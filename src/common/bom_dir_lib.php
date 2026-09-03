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
 * ── 免傳 PDO 的版本（2026-08-25 新增）─────────────────────────────────────
 * 全站有二十幾個頁面把 `'Z:/BOM/'` 直接寫死在掃描程式碼裡，多半在沒有 $pdo 在手的
 * 區塊（或變數名稱各不相同）。要把它們一次換掉又不動到原本的邏輯，最不容易出錯的
 * 作法就是「字面值換成一次函式呼叫」——所以這裡提供自己開連線（靜態快取，一個
 * 請求只開一次）的版本，而且**回傳的已經是檔案系統吃得到的路徑**（已過
 * eg_bom_fs_path），呼叫端原本怎麼 is_dir()／scandir() 就怎麼用，不必再改第二處。
 */
function eg_bom_dir_pdo(): ?PDO {
    static $pdo = null, $tried = false;
    if ($tried) return $pdo;
    $tried = true;
    try {
        require_once __DIR__ . '/DBConnection.php';
        $pdo = (new DBConnection())->getPDO();
    } catch (Throwable $e) { $pdo = null; }
    return $pdo;
}
/**
 * BOM 圖檔資料夾（檔案系統路徑）；連不上 DB 時退回預設值，行為與改動前相同。
 * **結果整個請求只算一次**：eg_bom_fs_path() 內含 is_dir()，對網路磁碟是一次網路往返，
 * 萬一有呼叫端把它寫在迴圈裡就會變成每列一次。
 */
function eg_bom_scan_dir_auto(): string {
    static $v = null;
    if ($v !== null) return $v;
    $db = eg_bom_dir_pdo();
    return $v = eg_bom_fs_path($db ? eg_bom_scan_dir($db) : 'Z:/BOM/');
}
/** ERP 圖檔資料夾（檔案系統路徑）；同樣整個請求只算一次 */
function eg_bom_erp_scan_dir_auto(): string {
    static $v = null;
    if ($v !== null) return $v;
    $db = eg_bom_dir_pdo();
    return $v = eg_bom_fs_path($db ? eg_bom_erp_scan_dir($db) : 'Z:/BOM/ERP/資材(生管and業務)/BOM/');
}

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
 * ── ERP/資材報告「檔名後綴標籤」比對（唯一實作）──────────────────────────
 * 這段規則原本被複製在 5 支檔案共 10 處（part_viewer、兩支 OreadyReply ajax、
 * type_id_ctrl_lib），改一處等於漏改九處，故收斂於此。
 *
 * 命中條件＝檔名的 `$head`（＝BOM名稱＋後綴，或 [料號]＋後綴）後面：
 *   ① 沒東西了，或接非英數字（副檔名的點、空白、底線…）  → 第 1 份
 *   ② 接**純數字**（後面不可再有英數字）                    → 第 N 份
 *      `-H2` ＝「-H 的第二個」（使用者 2026-09-01 指定），`-H23` ＝第 23 份
 *   ③ 接英文字母 → **不算命中**。這條不能拿掉，否則 `-M` 會誤中 `-MR`、
 *      `-T` 誤中 `-TR`、`-C` 誤中 `-CRT`（現有 18 個後綴裡就有這些成對的）。
 *      `-T2A` 這種「數字後面又接字母」的一併排除，寧可不上標籤也不要上錯。
 *
 * @param string $name     UTF-8 檔名
 * @param string $head     要比對的前置字
 * @param bool   $anywhere false＝只比對檔名開頭；true＝出現在任何位置皆可（[料號] 那種）
 * @return int|null        null＝不命中；否則回「第幾份」（無編號＝1）
 */
function eg_bom_tag_seq(string $name, string $head, bool $anywhere = false): ?int {
    if ($head === '') return null;
    if ($anywhere) {
        $pos = stripos($name, $head);
        if ($pos === false) return null;
    } else {
        if (stripos($name, $head) !== 0) return null;
        $pos = 0;
    }
    $after = substr($name, $pos + strlen($head));
    if ($after === '') return 1;
    if (preg_match('/^([0-9]+)(?![a-zA-Z0-9])/', $after, $m)) return max(1, (int)$m[1]);
    if (preg_match('/^[^a-zA-Z0-9]/', $after)) return 1;
    return null;
}

/**
 * 把「後綴＋份數」的存放鍵拆回 [後綴, 第幾份]（'-H2'→['-H',2]、'-H'→['-H',1]）。
 * 供 type_id_ctrl 這種「把鍵存進 DB、事後要回頭對照標籤設定」的呼叫端使用；
 * 呼叫端要先確認整個鍵本身不是一個已設定的後綴，再用這支拆（後綴本身就以數字結尾時才不會誤拆）。
 */
function eg_bom_tag_key_parse(string $key): array {
    if (preg_match('/^(.*?)([0-9]+)$/', $key, $m) && $m[1] !== '') return [$m[1], max(1, (int)$m[2])];
    return [$key, 1];
}

/** 標籤顯示文字：第 2 份以後在標籤後面帶出份數（-H2→「熱處理2」）；第 1 份維持原樣不變 */
function eg_bom_tag_label(string $label, int $seq): string {
    return $seq > 1 ? $label . $seq : $label;
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


/**
 * 取得某個圖檔資料夾內「副檔名符合」的檔名清單（含快取），給「這個 BOM 有沒有圖面」這類判定用。
 *
 * 為什麼要有這支（2026-09-03，訂單追蹤清單載入 1.6 秒的根因）：
 * 原本各頁自己 `scandir()` 整個資料夾（實際有 19,000 多個檔）再用巢狀迴圈逐一比對，
 * 而且**把整份檔名清單存進 `$_SESSION`**——session 檔因此膨脹到 600KB 以上，於是
 * 「全站每一支頁面」的每一次請求都要讀寫這 600KB，連不相干的頁面都被拖慢。
 * 這裡改成：①快取放系統暫存檔、**全站共用**（不進 session）②只留副檔名符合的檔名
 * ③另外提供 `eg_bom_file_prefix_index()` 讓比對變成 O(1) 雜湊查詢，不必每個編號掃全部檔名。
 *
 * @param string $utf8Dir UTF-8 路徑（編碼轉換由本檔處理）
 * @param array  $exts    小寫副檔名白名單，例：['jpg','jpeg','png','pdf']
 * @param int    $ttl     快取秒數（預設 300 秒，與原本 session 快取相同）
 * @return string[] 檔名（不含路徑）；資料夾讀不到時回空陣列
 */
function eg_bom_file_cache_path(string $utf8Dir, array $exts): string {
    $exts = array_values(array_unique(array_map('strtolower', $exts)));
    sort($exts);
    return rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR
         . 'eg_bomfiles_' . md5($utf8Dir . '|' . implode(',', $exts)) . '.cache';
}

/**
 * 只讀快取、絕不掃描（清單頁一律用這支）。
 * 回傳 null＝完全沒有快取；回傳陣列＝可用的檔名清單（可能已過期，過期與否看 $ageOut）。
 * **為什麼不在這裡順手掃一次**：那個資料夾在 NAS 上有 19,000 多個檔，實測掃完要一分半，
 * 只要讓它跑在畫面要用的路徑上，使用者就會看到「載入中」卡住不動（2026-09-03 事故）。
 */
function eg_bom_file_cache_read(string $utf8Dir, array $exts, ?int &$ageOut = null): ?array {
    static $mem = [];
    $f = eg_bom_file_cache_path($utf8Dir, $exts);
    if (isset($mem[$f])) { $ageOut = $mem[$f][1]; return $mem[$f][0]; }
    if (!is_file($f)) { $ageOut = null; return null; }
    $raw = @file_get_contents($f);
    if ($raw === false || $raw === '') { $ageOut = null; return null; }
    $list = @unserialize($raw);
    if (!is_array($list)) { $ageOut = null; return null; }
    $ageOut = time() - (int)@filemtime($f);
    $mem[$f] = [$list, $ageOut];
    return $list;
}

/**
 * 真的去掃資料夾並寫進快取（**很慢，只能由背景／非同步的請求呼叫**）。
 * 同時只允許一個請求在掃（鎖檔 10 分鐘），避免多人同時開頁面把 NAS 打爆。
 * @return array{ok:bool,count:int,skipped:bool}
 */
function eg_bom_file_cache_refresh(string $utf8Dir, array $exts, int $lockTtl = 600): array {
    $cacheFile = eg_bom_file_cache_path($utf8Dir, $exts);
    $lockFile  = $cacheFile . '.lock';
    if (is_file($lockFile) && (time() - (int)@filemtime($lockFile)) < $lockTtl) {
        return ['ok' => true, 'count' => 0, 'skipped' => true];   // 已經有人在掃
    }
    @file_put_contents($lockFile, (string)time());
    $exts = array_values(array_unique(array_map('strtolower', $exts)));
    $fs   = eg_bom_fs_path(rtrim($utf8Dir, '/\\') . DIRECTORY_SEPARATOR);
    $list = [];
    $ok   = false;
    $dh   = @opendir($fs);
    if ($dh !== false) {
        while (($fn = readdir($dh)) !== false) {
            if ($fn === '.' || $fn === '..') continue;
            $dot = strrpos($fn, '.');
            if ($dot === false) continue;
            if (in_array(strtolower(substr($fn, $dot + 1)), $exts, true)) $list[] = $fn;
        }
        closedir($dh);
        @file_put_contents($cacheFile, serialize($list), LOCK_EX);
        $ok = true;
    }
    @unlink($lockFile);
    return ['ok' => $ok, 'count' => count($list), 'skipped' => false];
}

/**
 * 依「要比對的編號長度」把檔名做成前綴索引：`[長度 => [前綴 => true]]`。
 * 判定「檔名是否以某個編號開頭」時用 `isset($idx[strlen($no)][$no])`，
 * 取代「每個編號 × 全部檔名」的巢狀掃描（實測 1.6 秒 → 數十毫秒）。
 */
function eg_bom_file_prefix_index(array $files, array $numbers): array {
    $lens = [];
    foreach ($numbers as $no) { $l = strlen((string)$no); if ($l > 0) $lens[$l] = true; }
    $idx = [];
    foreach (array_keys($lens) as $l) {
        $m = [];
        foreach ($files as $fn) { $m[substr($fn, 0, $l)] = true; }
        $idx[$l] = $m;
    }
    return $idx;
}
}
