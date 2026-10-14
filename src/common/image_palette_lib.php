<?php
/**
 * image_palette_lib.php — 大 PNG 轉 256 色（批圖編輯器輸出圖存檔用，唯一實作）
 *
 * 為什麼：批圖編輯器 A3 兩倍輸出的 PNG 動輒 40MB 以上，預覽慢、佔 NAS。
 * 做法（2026-10-14 使用者實測確認畫質）：接近白色(fuzz 6%)的紙面底色先還原成純白
 * （掃描底的極淡雜點若直接量化，會被併成少數淡灰、變成成片斜紋），再量化 256 色、不抖色。
 * 保護：解析度必須相同、與原圖 RMSE < 2%；任何一步失敗一律「保留原檔不動」，絕不可讓存檔失敗。
 * 既有舊檔的整批轉換見 views/Sales/migrations/2026-10-14_imgedit_png_256.php（同一套做法）。
 */
if (!defined('EG_PNG256_MIN_BYTES')) define('EG_PNG256_MIN_BYTES', 8 * 1024 * 1024);

/** PNG 是否已是調色盤（256 色）格式：直接讀檔頭 IHDR 的 color type（第 26 個位元組，3＝調色盤），瞬間完成；
 *  不要改回 ImageMagick 的 %[type]——它要掃過整張圖，A3 大圖要幾十秒。 */
function eg_png_is_palette(string $path): bool {
    $h = @fopen($path, 'rb');
    if (!$h) return false;
    $b = fread($h, 26);
    fclose($h);
    return is_string($b) && strlen($b) >= 26 && substr($b, 1, 3) === 'PNG' && ord($b[25]) === 3;
}

function eg_magick_path(): string {
    foreach (['C:\\MAMP\\bin\\ImageMagick-7.1.0-Q16\\magick.exe'] as $p) { if (is_file($p)) return $p; }
    return '';
}

/**
 * 把 $path 的 PNG 原地轉成 256 色。小於門檻、已是調色盤、或任何檢查沒過都回 false（原檔不變）。
 * 回傳 true＝已轉換並覆蓋。
 */
function eg_png_to_palette256(string $path, int $minBytes = EG_PNG256_MIN_BYTES): bool {
    try {
        if (!is_file($path) || filesize($path) < $minBytes) return false;
        $mg = eg_magick_path();
        if ($mg === '' || !function_exists('exec')) return false;
        @set_time_limit(240);
        $q = function ($s) { return '"' . $s . '"'; };
        $out = $path . '.p256.tmp.png';
        if (eg_png_is_palette($path)) return false;                                  // 已是 256 色
        $o = []; $rc = 1;
        exec($q($mg) . ' ' . $q($path) . ' -fuzz 6% -fill white -opaque white -colors 256 -dither None PNG8:' . $q($out) . ' 2>&1', $o, $rc);
        if ($rc !== 0 || !is_file($out)) { @unlink($out); return false; }
        $a = @getimagesize($path); $b = @getimagesize($out);
        if (!$a || !$b || $a[0] !== $b[0] || $a[1] !== $b[1]) { @unlink($out); return false; }
        $o = [];
        exec($q($mg) . ' compare -metric RMSE ' . $q($path) . ' ' . $q($out) . ' null: 2>&1', $o, $rc);
        if (!preg_match('/\(([\d.eE+-]+)\)/', implode(' ', $o), $m) || (float)$m[1] >= 0.02) { @unlink($out); return false; }
        if (filesize($out) >= filesize($path)) { @unlink($out); return false; }       // 沒變小就不換
        if (!@rename($out, $path)) {                                                  // UNC 上 rename 覆蓋失敗時退回複製
            $ok = @copy($out, $path); @unlink($out);
            return $ok;
        }
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * 背景轉檔：存檔請求不必等轉檔（A3 大圖要 6~20 秒），先把原圖存好就回應，
 * 轉檔交給獨立的 CLI 程序（image_palette_worker.php），使用者關掉瀏覽器也照常完成。
 * 轉檔失敗時原圖仍在、只是檔案比較大；整批補轉可跑 views/Sales/migrations/2026-10-14_imgedit_png_256.php。
 * 回傳 true＝已成功派出背景程序（不代表已轉完）。
 */
function eg_png_to_palette256_async(string $path, int $minBytes = EG_PNG256_MIN_BYTES): bool {
    try {
        if (!is_file($path) || filesize($path) < $minBytes) return false;
        if (!function_exists('popen')) return false;
        $php = '';
        foreach (glob('C:\MAMP\bin\php\php' . PHP_VERSION . '\php.exe') ?: [] as $p) { $php = $p; }
        if ($php === '') foreach (glob('C:\MAMP\bin\php\php*\php.exe') ?: [] as $p) { $php = $p; }
        if ($php === '') return false;
        $worker = __DIR__ . DIRECTORY_SEPARATOR . 'image_palette_worker.php';
        $cmd = 'start "" /B "' . $php . '" -d memory_limit=512M "' . $worker . '" "' . $path . '"';
        $h = @popen($cmd, 'r');
        if (!$h) return false;
        @pclose($h);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}
