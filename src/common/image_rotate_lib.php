<?php
/**
 * image_rotate_lib.php — 「把磁碟上的圖檔／PDF 就地旋轉並覆寫存檔」的唯一實作
 *
 * 為什麼要有這支（2026-08-25）：
 * 站上原本已經有兩份各自寫的旋轉程式（`Quotation_File_API.php` 的 rotate_file、
 * `store_DrawingRename_API.php` 的 drRotateImage/drRotatePdf），圖面查閱頁再刻第三份
 * 就是鐵律4 講的「兩邊各刻一份必定走鐘」。日後任何頁面要做「旋轉並存檔」一律呼叫這裡。
 *
 * 三個實作上的重點（都是踩過才知道的）：
 *  1. **GD 的角度是逆時針**，使用者講的「順時針轉 90 度」要送 -90 給 imagerotate。
 *  2. **手機拍的 JPG 方向是靠 EXIF**：瀏覽器的 <img> 會自動依 EXIF 轉正，GD 不會。
 *     不先把 EXIF 方向烙進畫素就直接轉，使用者看到的是「按一下順時針、圖卻跳到別的角度」。
 *     所以這裡先依 EXIF 正規化再套使用者要的角度，存檔後 EXIF 沒了但方向已經是對的。
 *     （注意：CLI 的 php.ini 沒開 exif、Apache 的有開，用 CLI 測不出這段。）
 *  3. **先寫暫存檔、驗證成功才覆蓋原檔**。目標檔多半在 NAS 上，直接對原檔寫入
 *     只要中途斷線就是把正本弄壞、而且沒有備份可以還原。
 */

if (!function_exists('eg_rotate_file')) {

/** 可就地旋轉的副檔名（PDF 走 FPDI，其餘走 GD） */
function eg_rotate_exts(): array { return ['jpg','jpeg','png','gif','bmp','webp','pdf']; }

/** 角度正規化成 0/90/180/270（順時針）；不合法一律回 0 */
function eg_rotate_norm_deg(int $deg): int {
    $d = $deg % 360;
    if ($d < 0) $d += 360;
    return in_array($d, [90, 180, 270], true) ? $d : 0;
}

/**
 * 就地旋轉並覆寫存檔。
 * @param string $fsPath 檔案系統完整路徑（呼叫端負責權限與路徑安全）
 * @param int    $degCW  順時針度數：90 / 180 / 270（-90 等同 270）
 * @return array ['success'=>bool, 'message'=>string]
 */
function eg_rotate_file(string $fsPath, int $degCW): array {
    $deg = eg_rotate_norm_deg($degCW);
    if ($deg === 0)          return ['success'=>false, 'message'=>'旋轉角度只接受 90／180／270 度'];
    if (!is_file($fsPath))   return ['success'=>false, 'message'=>'找不到檔案（可能已被移動或刪除）'];
    // 這裡**刻意不做 is_writable() 預檢**：實測 NAS 的 UNC 路徑上，明明寫得進去
    // （同一支程式 file_put_contents 成功）is_writable() 仍一律回 false——Windows 上
    // 它只看唯讀屬性、不看網路share 的 ACL。做了這道預檢的結果是每一次旋轉都被自己擋掉。
    // 真的不能寫時，下面覆蓋正本那步會失敗並回報，正本也不會被動到（我們先寫暫存檔）。

    $ext = strtolower(pathinfo($fsPath, PATHINFO_EXTENSION));
    if (!in_array($ext, eg_rotate_exts(), true)) {
        return ['success'=>false, 'message'=>'這種格式不支援旋轉（只支援圖片檔與 PDF）'];
    }
    // ── ① JPG 優先走「無損」路徑（jpegtran，不解碼直接搬 DCT 係數）──────────
    // 走得通的話畫素一個都不會變、檔案大小幾乎不變、也比 GD 快十倍；
    // 走不通（沒有 jpegtran、帶 EXIF 方向、執行失敗…）就靜靜落回下面的 GD 路徑。
    $mode = 'reencode';
    if (in_array($ext, ['jpg','jpeg'], true) && eg_rotate_can_lossless($fsPath)) {
        $tmpL = eg_rotate_tmp_path($ext);
        if (eg_rotate_jpeg_lossless($fsPath, $tmpL, $deg) && is_file($tmpL) && filesize($tmpL) > 0
            && @getimagesize($tmpL)) {                       // 產出真的是張讀得開的圖才敢覆蓋
            if (@copy($tmpL, $fsPath)) {
                @unlink($tmpL);
                @touch($fsPath);
                clearstatcache(true, $fsPath);
                return ['success'=>true, 'message'=>'已旋轉並存檔（無損）', 'mode'=>'lossless'];
            }
        }
        @unlink($tmpL);
    }

    // ── ② 其餘（PNG／PDF／jpegtran 走不通的 JPG）走原本的重新編碼路徑 ────────
    if ($ext !== 'pdf') {
        $memErr = eg_rotate_ensure_memory($fsPath);
        if ($memErr !== null) return ['success'=>false, 'message'=>$memErr];
    }
    $tmp = eg_rotate_tmp_path($ext);
    try {
        $ok = ($ext === 'pdf') ? eg_rotate_pdf_to($fsPath, $tmp, $deg)
                               : eg_rotate_image_to($fsPath, $tmp, $deg);
        if (!$ok || !is_file($tmp) || filesize($tmp) < 1) {
            @unlink($tmp);
            return ['success'=>false, 'message'=>'旋轉失敗（檔案可能毀損、加密，或格式不支援）'];
        }
        // 驗證成功才覆蓋正本（copy 會直接覆寫；不先 unlink，免得覆蓋失敗連原檔都沒了）
        if (!@copy($tmp, $fsPath)) {
            @unlink($tmp);
            return ['success'=>false, 'message'=>'寫回原檔失敗（NAS 沒有寫入權限或檔案被其他程式開著）'];
        }
        @unlink($tmp);
        @touch($fsPath);          // 更新 mtime，前端才好破快取
        clearstatcache(true, $fsPath);
        return ['success'=>true, 'message'=>'已旋轉並存檔', 'mode'=>$mode];
    } catch (Throwable $e) {
        @unlink($tmp);
        error_log('[eg_rotate_file] ' . $e->getMessage());
        return ['success'=>false, 'message'=>'旋轉失敗：' . $e->getMessage()];
    }
}

// ──────────────────────────────────────────────────────────────────────────
// 無損路徑（jpegtran）
// ──────────────────────────────────────────────────────────────────────────
/** 專案自帶的 jpegtran.exe（見 src/bin/README.md）；不存在回空字串 */
function eg_rotate_jpegtran_path(): string {
    $p = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'bin'
       . DIRECTORY_SEPARATOR . 'jpegtran.exe';
    return is_file($p) ? $p : '';
}

/**
 * 這個 JPG 適不適合走無損路徑。
 * **帶 EXIF 方向（Orientation>1）的一律不走**：jpegtran 會原樣複製 EXIF、但不會更新
 * Orientation，於是瀏覽器會照著標籤「再轉一次」＝總共轉了兩次。那種檔案交給 GD
 * （GD 那條路徑會先把 EXIF 方向烙進畫素）。
 */
function eg_rotate_can_lossless(string $fsPath): bool {
    if (eg_rotate_jpegtran_path() === '') return false;
    if (!function_exists('proc_open')) return false;           // 有些主機把它關掉
    if (function_exists('exif_read_data')) {
        $ex  = @exif_read_data($fsPath);
        $ori = (is_array($ex) && isset($ex['Orientation'])) ? (int)$ex['Orientation'] : 1;
        if ($ori > 1) return false;
    }
    return true;
}

/**
 * 無損旋轉：不解碼，直接搬 JPEG 內部的 DCT 係數。
 *
 * **刻意不加 `-trim`**：圖的寬高不是 8 的倍數時最後一個不完整的區塊沒辦法轉置，
 * 不加 -trim 時 jpegtran 會保留它（內容等於平移 1~7 個畫素、邊緣補白，實測是乾淨白邊
 * 不是雜訊），而且**來回轉會完全還原**（實測 MAE=0）；加了 -trim 位移一樣存在、
 * 還會每轉一次削掉一列畫素。所以選一個畫素都不丟的作法。
 *
 * `-copy all` 保留 EXIF／註解等中繼資料（呼叫端已擋掉帶方向標籤的檔案）。
 */
function eg_rotate_jpeg_lossless(string $src, string $dst, int $degCW): bool {
    $exe = eg_rotate_jpegtran_path();
    if ($exe === '') return false;
    $cmd = escapeshellarg($exe) . ' -rotate ' . (int)$degCW . ' -copy all -outfile '
         . escapeshellarg($dst) . ' ' . escapeshellarg($src);
    $desc = [1 => ['pipe','w'], 2 => ['pipe','w']];
    $proc = @proc_open($cmd, $desc, $pipes, dirname($exe));   // cwd 設在 exe 旁邊，才找得到 jpeg62.dll
    if (!is_resource($proc)) return false;
    $err = '';
    foreach ([1, 2] as $i) { $err .= stream_get_contents($pipes[$i]); fclose($pipes[$i]); }
    $code = proc_close($proc);
    if ($code !== 0) {
        error_log('[eg_rotate_jpeg_lossless] jpegtran exit=' . $code . ' ' . trim($err));
        return false;
    }
    return true;
}

/**
 * 先把 memory_limit 開到夠用再動手（**這一步不做的話大圖一律 fatal error**）。
 *
 * 實測：掃描的 BOM 圖面是 7016×4961＝3,480 萬畫素，GD 一律以 32 位元 truecolor 存放，
 * 原圖一份＋旋轉後一份就要約 280MB，遠超過預設的 memory_limit=128M —— 症狀是
 * 「Allowed memory size exhausted」白畫面，而不是回傳失敗訊息。
 * 這裡依實際尺寸算出需要多少再 ini_set；真的大到離譜（超過 1.5GB）就明白回報，
 * 不要讓 PHP 直接死在半路（不過正本永遠不會壞，我們是先寫暫存檔才覆蓋）。
 */
function eg_rotate_ensure_memory(string $fsPath): ?string {
    $info = @getimagesize($fsPath);
    if (!$info || empty($info[0]) || empty($info[1])) return null;   // 不是圖片，交給載入那段去失敗
    $mp    = ((int)$info[0] * (int)$info[1]) / 1048576;              // 百萬畫素
    $need  = (int)ceil($mp * 4 * 2 * 1.6) + 48;                      // MB：來源＋旋轉後各一份，1.6 倍餘裕
    $limit = 1536;                                                   // MB：上限，避免一次請求吃掉整台記憶體
    if ($need > $limit) {
        return '這張圖太大（' . round($mp, 1) . ' 百萬畫素），伺服器記憶體不足以旋轉；'
             . '請改用批圖編輯器或小畫家處理後重新上傳';
    }
    $cur = eg_rotate_mem_bytes((string)ini_get('memory_limit'));
    if ($cur > 0 && $cur < $need * 1048576) {
        @ini_set('memory_limit', $need . 'M');
        $now = eg_rotate_mem_bytes((string)ini_get('memory_limit'));
        if ($now > 0 && $now < $need * 1048576) {
            return '伺服器 memory_limit 被鎖住（目前 ' . ini_get('memory_limit')
                 . '），這張 ' . round($mp, 1) . ' 百萬畫素的圖至少需要 ' . $need . 'M，無法旋轉';
        }
    }
    return null;
}

/** memory_limit 字串（128M/1G/-1）→ 位元組；-1（無限制）回 0 */
function eg_rotate_mem_bytes(string $v): int {
    $v = trim($v);
    if ($v === '' || $v === '-1') return 0;
    $unit = strtolower(substr($v, -1));
    $num  = (int)$v;
    return match ($unit) { 'g' => $num * 1073741824, 'm' => $num * 1048576, 'k' => $num * 1024, default => $num };
}

/** 暫存檔路徑（放系統暫存區，不在 NAS 上產生半成品） */
function eg_rotate_tmp_path(string $ext): string {
    return rtrim(sys_get_temp_dir(), '/' . DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
         . 'eg_rot_' . bin2hex(random_bytes(8)) . '.' . $ext;
}

/** 圖片：GD 旋轉（含 EXIF 方向正規化），輸出到 $dst */
function eg_rotate_image_to(string $src, string $dst, int $degCW): bool {
    if (!function_exists('imagerotate')) return false;
    $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));
    $im = match ($ext) {
        'png'  => @imagecreatefrompng($src),
        'gif'  => @imagecreatefromgif($src),
        'bmp'  => (function_exists('imagecreatefrombmp')  ? @imagecreatefrombmp($src)  : false),
        'webp' => (function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($src) : false),
        default=> @imagecreatefromjpeg($src),
    };
    if (!$im) return false;

    // ① 先把 EXIF 方向烙進畫素（見檔頭說明第 2 點）
    if (in_array($ext, ['jpg','jpeg'], true) && function_exists('exif_read_data')) {
        $ex  = @exif_read_data($src);
        $ori = (is_array($ex) && isset($ex['Orientation'])) ? (int)$ex['Orientation'] : 0;
        $pre = match ($ori) { 3 => 180, 6 => -90, 8 => 90, default => 0 };   // GD 正角度＝逆時針
        if ($pre !== 0) {
            $r = @imagerotate($im, $pre, 0);
            if ($r) { imagedestroy($im); $im = $r; }
        }
    }
    // ② 再套使用者要的角度（順時針 → GD 取負值）
    $transparent = ($ext === 'png' || $ext === 'gif' || $ext === 'webp')
        ? imagecolorallocatealpha($im, 0, 0, 0, 127)
        : imagecolorallocate($im, 255, 255, 255);
    $rot = @imagerotate($im, -$degCW, $transparent);
    imagedestroy($im);
    if (!$rot) return false;
    if ($ext === 'png' || $ext === 'webp') { imagealphablending($rot, false); imagesavealpha($rot, true); }

    // 品質 95：掃描圖面重新編碼幾乎看不出差異，又不會讓檔案暴增（原尺寸不變）
    $ok = match ($ext) {
        'png'  => @imagepng($rot, $dst),
        'gif'  => @imagegif($rot, $dst),
        'bmp'  => (function_exists('imagebmp')  ? @imagebmp($rot, $dst)      : false),
        'webp' => (function_exists('imagewebp') ? @imagewebp($rot, $dst, 95) : false),
        default=> @imagejpeg($rot, $dst, 95),
    };
    imagedestroy($rot);
    return (bool)$ok;
}

/**
 * PDF：用 FPDI 匯入原頁面再旋轉（不轉點陣圖，畫質無損；見記憶 pdf_rasterize）。
 * 加密的 PDF FPDI 讀不動，會丟例外由呼叫端轉成看得懂的訊息。
 */
function eg_rotate_pdf_to(string $src, string $dst, int $degCW): bool {
    require_once __DIR__ . '/../../vendor/autoload.php';
    $pdf = new \setasign\Fpdi\Tcpdf\Fpdi();
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetAutoPageBreak(false, 0);
    $pageCount = $pdf->setSourceFile($src);
    for ($i = 1; $i <= $pageCount; $i++) {
        $tpl  = $pdf->importPage($i);
        $size = $pdf->getTemplateSize($tpl);
        $w = $size['width']; $h = $size['height'];
        $swapped = ($degCW === 90 || $degCW === 270);
        $pageW = $swapped ? $h : $w;
        $pageH = $swapped ? $w : $h;
        $pdf->AddPage($pageW > $pageH ? 'L' : 'P', [$pageW, $pageH]);
        $pdf->StartTransform();
        $pdf->Rotate(-$degCW, $pageW / 2, $pageH / 2);   // TCPDF 正角度＝逆時針
        $pdf->useTemplate($tpl, ($pageW - $w) / 2, ($pageH - $h) / 2, $w, $h);
        $pdf->StopTransform();
    }
    @file_put_contents($dst, $pdf->Output('', 'S'));
    return is_file($dst) && filesize($dst) > 0;
}

}
