<?php
/**
 * bom_dir_lib.php — BOM 圖檔資料夾的「路徑設定」與「安全掃描」唯一實作
 *
 * 為什麼要有這支（2026-08-07）：
 * 這些資料夾原本寫死在程式碼裡（`$scan_dir = 'Z:/BOM/'`），散在約 10 個頁面，
 * 造成兩個問題：①換機或磁碟機對應掉了就整批讀不到，且沒人能自行設定
 * ②要改成 `\\excellentnas\生產課\BOM\` 這種 UNC 寫法時，路徑會從純 ASCII 變成帶中文，
 *   Windows 下 PHP 的檔案函式需要 Big5 轉換——**漏轉不會報錯，scandir() 只回空清單**，
 *   畫面直接變成「沒有圖面」而沒有任何錯誤訊息（見 ai-rules/07 該節）。
 *
 * 所以編碼一律由本檔集中處理：呼叫端只給 UTF-8 路徑，不需要知道 Big5 這回事。
 * 作法是「兩種編碼都試，哪個 is_dir() 成立就用哪個」，所以純 ASCII 的舊路徑
 * （Z:/BOM/）與帶中文的新路徑（\\excellentnas\生產課\BOM\）都能正確運作。
 *
 * 設定鍵（管理員可在頁面上自行設定，預設值＝維持現行行為，不改設定就跟以前一模一樣）：
 *   bom_scan_dir      BOM 圖檔資料夾      預設 Z:/BOM/
 *   bom_erp_scan_dir  ERP 圖檔資料夾      預設 Z:/BOM/ERP/資材(生管and業務)/BOM/
 */

if (!function_exists('eg_bom_dir_get')) {

/** 讀設定值（UTF-8，保證結尾有分隔符）；沒設定就回預設值＝現行寫死的路徑 */
function eg_bom_dir_get(PDO $db, string $key, string $default): string {
    $v = '';
    try {
        $st = $db->prepare("SELECT setting_value FROM system_settings WHERE setting_key=?");
        $st->execute([$key]);
        $v = trim((string)$st->fetchColumn());
    } catch (Throwable $e) {}
    if ($v === '') $v = $default;
    if (!preg_match('#[/\\\\]$#', $v)) $v .= (strpos($v, '\\') !== false ? '\\' : '/');
    return $v;
}

function eg_bom_scan_dir(PDO $db): string     { return eg_bom_dir_get($db, 'bom_scan_dir', 'Z:/BOM/'); }
function eg_bom_erp_scan_dir(PDO $db): string { return eg_bom_dir_get($db, 'bom_erp_scan_dir', 'Z:/BOM/ERP/資材(生管and業務)/BOM/'); }

/**
 * 把 UTF-8 路徑轉成「這台機器的檔案系統實際吃得到」的字串。
 * 純 ASCII 路徑兩種編碼相同，不受影響；帶中文的路徑在 Windows 下才需要 Big5。
 * 兩種都試，回傳 is_dir() 成立的那個；都不成立則回原字串（讓呼叫端的 is_dir 自然失敗）。
 */
function eg_bom_fs_path(string $utf8Path): string {
    if (is_dir($utf8Path)) return $utf8Path;
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $big5 = @mb_convert_encoding($utf8Path, 'Big5', 'UTF-8');
        if ($big5 !== false && $big5 !== '' && is_dir($big5)) return $big5;
    }
    return $utf8Path;
}

/** scandir() 回來的檔名轉回 UTF-8（本來就是合法 UTF-8 就原樣回傳，避免二次轉換弄壞檔名） */
function eg_bom_name_utf8(string $name): string {
    if (mb_check_encoding($name, 'UTF-8')) return $name;
    $c = @mb_convert_encoding($name, 'UTF-8', 'Big5');
    return ($c !== false && $c !== '') ? $c : $name;
}

/**
 * 安全掃描：回傳 [['name'=>UTF-8檔名, 'fs'=>檔案系統完整路徑, 'ext'=>副檔名, 'mtime'=>int], ...]
 * 編碼、目錄不存在、副檔名／檔名開頭過濾都在這裡處理，呼叫端不必重複寫。
 *
 * **效能鐵則**：這個資料夾實測有 1.8 萬個檔（ERP 那個 6 千），而且在網路磁碟上，
 * 每一次 is_file()/filemtime() 都是一次網路往返。所以一律「先用檔名過濾，
 * 只對真正命中的少數檔案取 mtime」——對全部檔案做 stat 會讓頁面從毫秒變成分鐘級。
 *
 * @param string $utf8Dir UTF-8 資料夾路徑（結尾有無分隔符皆可）
 * @param array  $exts    允許的副檔名（小寫，空陣列＝全部）
 * @param string $prefix  只要檔名以此開頭的（空字串＝不限）；比對用 UTF-8 檔名
 * @param bool   $withMtime 是否取檔案時間（要排序才需要；不需要就別付這個網路成本）
 */
function eg_bom_scan(string $utf8Dir, array $exts = [], string $prefix = '', bool $withMtime = true): array {
    $dir = eg_bom_fs_path(rtrim($utf8Dir, '/\\') . DIRECTORY_SEPARATOR);
    if (!is_dir($dir)) return [];
    $out = [];
    foreach (@scandir($dir) ?: [] as $f) {
        if ($f === '.' || $f === '..') continue;
        $name = eg_bom_name_utf8($f);
        if ($prefix !== '' && strpos($name, $prefix) !== 0) continue;   // 先過濾，別 stat
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($exts && !in_array($ext, $exts, true)) continue;
        $full = $dir . $f;
        $out[] = ['name' => $name, 'fs' => $full, 'ext' => $ext,
                  'mtime' => $withMtime ? (int)@filemtime($full) : 0];
    }
    return $out;
}

/**
 * 設定頁用的連線測試：回報這個路徑讀不讀得到、有幾個檔、實際用了哪種編碼。
 * 「先確認讀得到才替換」就是靠這支——不要等使用者發現圖面消失才知道設錯。
 * 只做一次目錄列舉、不對檔案做 stat（那個資料夾有上萬個檔）。
 */
function eg_bom_dir_probe(string $utf8Dir): array {
    $want = rtrim($utf8Dir, '/\\') . DIRECTORY_SEPARATOR;
    $fs   = eg_bom_fs_path($want);
    $ok   = is_dir($fs);
    $n    = 0;
    if ($ok) {
        foreach (@scandir($fs) ?: [] as $f) { if ($f !== '.' && $f !== '..') $n++; }
    }
    return [
        'ok'       => $ok,
        'count'    => $n,
        'encoding' => $ok ? ($fs === $want ? '直接可讀（純 ASCII 或系統支援 UTF-8）' : '需 Big5 轉碼（路徑含中文，已自動處理）') : '',
        'message'  => $ok ? "可讀取，這個資料夾有 {$n} 個項目" : '讀不到這個位置（路徑錯誤、磁碟未連線，或伺服器沒有存取權限）',
    ];
}

}
