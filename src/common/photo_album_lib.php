<?php
/**
 * photo_album_lib.php — 料號附件「照片相簿」唯一實作點
 * ---------------------------------------------------------------------------
 * 2026-08-25 使用者要求：像相簿一樣，同一次上傳的照片可自動成為一本相簿，
 * 檢視時以九宮格排列、可各別點開放大。
 *
 * 幾個刻意的決定（改動前請先看完，免得走回頭路）：
 *  1. 相簿只是**系統裡的分組**，NAS 上不開子資料夾（使用者拍板）。檔案照舊存在
 *     「附件根目錄\料號d_id\檔名」，所以既有的下載／列印／路徑組法全部不動，
 *     日後改相簿名稱或搬照片都不必動硬碟上的檔案（＝鐵律5 的即時組路徑）。
 *  2. 哪些標籤要用相簿檢視，由 quotation_file_categories.is_photo_album 逐標籤勾選，
 *     **不在程式裡寫死「產品照片」這個名字**（鐵律4：寫死的名稱會在使用者改名後繼續錯下去）。
 *  3. 縮圖是衍生資料，快取放系統暫存目錄、檔名帶原檔 mtime，原檔一被覆蓋就自動失效；
 *     快取被清掉也只是重新產生，不影響正確性。
 */

if (!function_exists('pa_album_ensure_schema')) {

/** 建欄位／建表（可重複執行） */
function pa_album_ensure_schema(PDO $pdo): void {
    static $done = false;
    if ($done) return;
    $done = true;
    // 逐標籤旗標：這個標籤的附件要用九宮格相簿檢視
    try {
        $pdo->exec("ALTER TABLE quotation_file_categories ADD COLUMN is_photo_album TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=此標籤的附件以九宮格相簿檢視'");
        // 首次建欄位時把「產品照片」預設打開（只在建欄位那一次跑，之後一律以使用者在
        // 標籤設定裡的勾選為準——這是一次性的預設值，不是寫死的對照表）
        $pdo->exec("UPDATE quotation_file_categories SET is_photo_album=1 WHERE category_name='產品照片'");
    } catch (Throwable $e) {}
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS part_attach_album (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            d_id          INT NOT NULL                COMMENT '料號 d_setting.d_id',
            album_name    VARCHAR(100) NOT NULL       COMMENT '相簿名稱',
            category_id   INT NULL                    COMMENT '主標籤（決定這本相簿出現在哪個標籤分頁）',
            note          VARCHAR(255) NULL,
            created_by    VARCHAR(50) NULL,
            created_by_id INT NULL,
            created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at    DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
            deleted_at    DATETIME NULL,
            INDEX idx_did (d_id),
            INDEX idx_cat (category_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='料號附件相簿（只做系統內分組，NAS 不開子資料夾）'");
    } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE part_attachments ADD COLUMN album_id INT NULL COMMENT '所屬相簿 part_attach_album.id；NULL=未分相簿' AFTER process_tag"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE part_attachments ADD INDEX idx_album (album_id)"); } catch (Throwable $e) {}
}

/** 以相簿檢視的標籤 id 清單（int[]） */
function pa_album_cat_ids(PDO $pdo): array {
    pa_album_ensure_schema($pdo);
    try {
        $rows = $pdo->query("SELECT id FROM quotation_file_categories WHERE COALESCE(is_photo_album,0)=1")->fetchAll(PDO::FETCH_COLUMN);
        return array_map('intval', $rows);
    } catch (Throwable $e) { return []; }
}

/** 這個附件的標籤字串裡有沒有相簿標籤；有的話回傳第一個相簿標籤 id，否則 0 */
function pa_album_cat_of(?string $categoryIds, array $albumCatIds): int {
    if (!$categoryIds || !$albumCatIds) return 0;
    foreach (explode(',', $categoryIds) as $cid) {
        $cid = (int)trim($cid);
        if ($cid && in_array($cid, $albumCatIds, true)) return $cid;
    }
    return 0;
}

/**
 * 取相簿清單（含照片張數與封面附件 id）
 * @param int[] $dIds 料號 d_id（檢視頁可能一次看多個 d_id）
 */
function pa_album_list(PDO $pdo, array $dIds): array {
    pa_album_ensure_schema($pdo);
    $dIds = array_values(array_unique(array_filter(array_map('intval', $dIds))));
    if (!$dIds) return [];
    $ph = implode(',', array_fill(0, count($dIds), '?'));
    try {
        $st = $pdo->prepare("SELECT a.id, a.d_id, a.album_name, a.category_id, a.note,
                                    COALESCE(u.user_cname, a.created_by) AS created_by, a.created_at,
                                    (SELECT COUNT(*) FROM part_attachments p WHERE p.album_id=a.id AND p.deleted_at IS NULL) AS photo_count,
                                    (SELECT p2.id FROM part_attachments p2 WHERE p2.album_id=a.id AND p2.deleted_at IS NULL
                                      ORDER BY p2.uploaded_at ASC, p2.id ASC LIMIT 1) AS cover_id
                             FROM part_attach_album a
                             LEFT JOIN user u ON u.id = a.created_by_id
                             WHERE a.d_id IN ($ph) AND a.deleted_at IS NULL
                             ORDER BY a.created_at DESC, a.id DESC");
        $st->execute($dIds);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
    foreach ($rows as &$r) {
        $r['id']          = (int)$r['id'];
        $r['d_id']        = (int)$r['d_id'];
        $r['category_id'] = $r['category_id'] === null ? null : (int)$r['category_id'];
        $r['photo_count'] = (int)$r['photo_count'];
        $r['cover_id']    = $r['cover_id'] ? (int)$r['cover_id'] : 0;
    }
    unset($r);
    return $rows;
}

/** 相簿名稱：長度與空白檢查（前後端同一套規則＝鐵律8） */
function pa_album_check_name(string $name): string {
    $name = trim($name);
    if ($name === '')                   throw new Exception('請輸入相簿名稱');
    if (mb_strlen($name, 'UTF-8') > 50) throw new Exception('相簿名稱最多 50 個字（目前 ' . mb_strlen($name, 'UTF-8') . ' 個）');
    return $name;
}

/** 建立相簿，回傳新 id */
function pa_album_create(PDO $pdo, int $dId, string $name, ?int $categoryId, int $userId, string $userName): int {
    pa_album_ensure_schema($pdo);
    if ($dId <= 0) throw new Exception('缺少料號 ID');
    $name = pa_album_check_name($name);
    $pdo->prepare("INSERT INTO part_attach_album (d_id,album_name,category_id,created_by,created_by_id) VALUES (?,?,?,?,?)")
        ->execute([$dId, $name, ($categoryId ?: null), $userName, ($userId ?: null)]);
    return (int)$pdo->lastInsertId();
}

/** 取一本相簿（已刪除的視為不存在） */
function pa_album_get(PDO $pdo, int $albumId): ?array {
    pa_album_ensure_schema($pdo);
    $st = $pdo->prepare("SELECT * FROM part_attach_album WHERE id=? AND deleted_at IS NULL");
    $st->execute([$albumId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** 改名 */
function pa_album_rename(PDO $pdo, int $albumId, string $name): void {
    $al = pa_album_get($pdo, $albumId);
    if (!$al) throw new Exception('找不到這本相簿，可能已被刪除');
    $name = pa_album_check_name($name);
    $pdo->prepare("UPDATE part_attach_album SET album_name=? WHERE id=?")->execute([$name, $albumId]);
}

/**
 * 刪除相簿：**只解散分組，不刪照片**（照片退回「未分相簿」）。
 * 相簿是分組用的容器，按「刪除相簿」多半只是想重分，不該連照片一起消失；
 * 真的要刪照片走既有的附件刪除（有 30 天軟刪除可還原）。
 */
function pa_album_delete(PDO $pdo, int $albumId): int {
    $al = pa_album_get($pdo, $albumId);
    if (!$al) throw new Exception('找不到這本相簿，可能已被刪除');
    $st = $pdo->prepare("UPDATE part_attachments SET album_id=NULL WHERE album_id=?");
    $st->execute([$albumId]);
    $moved = $st->rowCount();
    $pdo->prepare("UPDATE part_attach_album SET deleted_at=NOW() WHERE id=?")->execute([$albumId]);
    return $moved;
}

/**
 * 把附件加入相簿（$albumId 傳 0＝移出相簿）。
 * 只准操作同一個料號底下的附件，避免把別的料號的照片掛進來。
 */
function pa_album_assign(PDO $pdo, int $albumId, array $attachIds): int {
    pa_album_ensure_schema($pdo);
    $attachIds = array_values(array_unique(array_filter(array_map('intval', $attachIds))));
    if (!$attachIds) throw new Exception('請先選擇照片');
    $ph = implode(',', array_fill(0, count($attachIds), '?'));
    if ($albumId > 0) {
        $al = pa_album_get($pdo, $albumId);
        if (!$al) throw new Exception('找不到這本相簿，可能已被刪除');
        $st = $pdo->prepare("UPDATE part_attachments SET album_id=? WHERE id IN ($ph) AND d_id=? AND deleted_at IS NULL");
        $st->execute(array_merge([$albumId], $attachIds, [(int)$al['d_id']]));
    } else {
        $st = $pdo->prepare("UPDATE part_attachments SET album_id=NULL WHERE id IN ($ph) AND deleted_at IS NULL");
        $st->execute($attachIds);
    }
    return $st->rowCount();
}

/** 這個使用者能不能編輯料號附件（＝料號主檔頁的 C/U/A）。API 端自己再驗一次＝鐵律8 */
function pa_album_can_edit(PDO $pdo, int $userId): bool {
    if ($userId <= 0) return false;
    try {
        $sp = $pdo->prepare("SELECT page_id, group_id FROM system_module_pages
                             WHERE page_url LIKE '%master_data_management.php' LIMIT 1");
        $sp->execute();
        $pi = $sp->fetch(PDO::FETCH_ASSOC);
        if (!$pi) return true;   // 頁面沒登記進選單時不鎖死既有功能（與頁面端同樣採寬鬆）
        $q = $pdo->prepare("SELECT permission FROM user_module_permissions WHERE user_id=? AND scope='page' AND module_code=?");
        $q->execute([$userId, $pi['page_id']]);
        $perms = $q->fetchAll(PDO::FETCH_COLUMN);
        if (!$perms && !empty($pi['group_id'])) {
            $g = $pdo->prepare("SELECT module_code FROM system_modules WHERE group_id=? LIMIT 1");
            $g->execute([$pi['group_id']]);
            $gc = $g->fetchColumn();
            if ($gc) {
                $q2 = $pdo->prepare("SELECT permission FROM user_module_permissions WHERE user_id=? AND scope='group' AND module_code=?");
                $q2->execute([$userId, $gc]);
                $perms = $q2->fetchAll(PDO::FETCH_COLUMN);
            }
        }
        $chars = [];
        foreach ($perms as $p) $chars = array_merge($chars, str_split((string)$p));
        return in_array('A', $chars, true) || in_array('C', $chars, true) || in_array('U', $chars, true);
    } catch (Throwable $e) { return false; }
}

// ── 縮圖 ────────────────────────────────────────────────────────────────────
/** 支援縮圖的副檔名 */
function pa_thumb_supported(string $ext): bool {
    return in_array(strtolower($ext), ['jpg','jpeg','png','gif','webp','bmp'], true);
}

/** 縮圖快取目錄（衍生資料，放系統暫存；清掉只會重新產生） */
function pa_thumb_cache_dir(): string {
    $dir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'eg_part_thumb';
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    return $dir;
}

/**
 * 產生（或取用快取）縮圖，回傳 [檔案路徑, mime]；失敗回 null 讓呼叫端退回原圖。
 * 快取檔名帶原檔 mtime＝原檔一被覆蓋就自動換一個快取檔，不會拿到舊圖。
 */
function pa_thumb_make(string $srcPath, int $maxSide = 400): ?array {
    if (!is_file($srcPath) || !extension_loaded('gd')) return null;
    $ext = strtolower(pathinfo($srcPath, PATHINFO_EXTENSION));
    if (!pa_thumb_supported($ext)) return null;
    $maxSide = max(80, min(1600, $maxSide));
    $key   = md5($srcPath . '|' . (int)@filemtime($srcPath) . '|' . $maxSide);
    $cache = pa_thumb_cache_dir() . DIRECTORY_SEPARATOR . $key . '.jpg';
    if (is_file($cache) && filesize($cache) > 0) return [$cache, 'image/jpeg'];
    try {
        $src = match ($ext) {
            'png'  => @imagecreatefrompng($srcPath),
            'gif'  => @imagecreatefromgif($srcPath),
            'webp' => (function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($srcPath) : false),
            'bmp'  => (function_exists('imagecreatefrombmp')  ? @imagecreatefrombmp($srcPath)  : false),
            default=> @imagecreatefromjpeg($srcPath),
        };
        if (!$src) return null;
        // 手機拍的照片方向靠 EXIF，不轉正的話相簿裡會東倒西歪（exif 沒開就跳過）
        if (in_array($ext, ['jpg','jpeg'], true) && function_exists('exif_read_data')) {
            $ex  = @exif_read_data($srcPath);
            $ori = (is_array($ex) && isset($ex['Orientation'])) ? (int)$ex['Orientation'] : 0;
            $deg = match ($ori) { 3 => 180, 6 => -90, 8 => 90, default => 0 };
            if ($deg !== 0 && function_exists('imagerotate')) {
                $rot = @imagerotate($src, $deg, 0);
                if ($rot) { imagedestroy($src); $src = $rot; }
            }
        }
        $w = imagesx($src); $h = imagesy($src);
        if ($w < 1 || $h < 1) { imagedestroy($src); return null; }
        $scale = min(1, $maxSide / max($w, $h));
        $nw = max(1, (int)round($w * $scale));
        $nh = max(1, (int)round($h * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        // 透明底（png/gif）存成 jpg 會變黑，先鋪白底
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefilledrectangle($dst, 0, 0, $nw, $nh, $white);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagejpeg($dst, $cache, 82);
        imagedestroy($src); imagedestroy($dst);
        pa_thumb_gc();
        return is_file($cache) ? [$cache, 'image/jpeg'] : null;
    } catch (Throwable $e) { return null; }
}

/** 偶爾清一次超過 30 天沒用到的縮圖快取（1% 機率，不值得為它排一支排程） */
function pa_thumb_gc(): void {
    if (random_int(1, 100) !== 1) return;
    $dir = pa_thumb_cache_dir();
    $cut = time() - 30 * 86400;
    foreach ((glob($dir . DIRECTORY_SEPARATOR . '*.jpg') ?: []) as $f) {
        if (@filemtime($f) < $cut) @unlink($f);
    }
}

}   // function_exists guard
