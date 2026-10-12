<?php
// src/common/drawing_ocr_lib.php
// ── 圖面文字辨識（OCR）共用庫（唯一實作，禁止各頁自刻）─────────────────────────
// 免費本機方案：系統既有的 Tesseract OCR（C:\Program Files\Tesseract-OCR），辨識模型
// （chi_tra / chi_tra_vert / eng，best 版）隨專案放在 src/bin/tessdata/，不動系統安裝、
// 不需對外連線即可跑（執行時用 --tessdata-dir 指自己的資料夾，與系統安裝的 tessdata
// 互不影響，比照 src/bin/jpegtran.exe 的「隨版控走、不裝也能用」做法）。
//
// 本庫只負責「辨識出文字＋座標」，絕不自動寫入任何資料表——OCR 對 0/O、Q/G 這類符號
// 辨識仍會出錯（已實測），一律交由人在畫面上挑選確認後，才送進既有的「新增料號」流程
// （master_data_management.php 的 openPartModal(0)，見該頁 ?new_part_no= 深連結），不
// 在這裡另刻一套寫入 d_setting 的邏輯（鐵律4）。

/** 是否為系統管理員 */
function drawing_ocr_is_admin(): bool {
    return in_array(intval($_SESSION['status'] ?? 0), [9, 90], true);
}

/** 誰可以使用圖面文字辨識工具（唯一判定點） */
function drawing_ocr_can_use($pdo, int $userId): bool {
    if (drawing_ocr_is_admin()) return true;
    if ($userId <= 0 || !($pdo instanceof PDO)) return false;
    try {
        $st = $pdo->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id
                              WHERE ur.user_id=? AND r.role_code='drawing_ocr_use' LIMIT 1");
        $st->execute([$userId]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/** Tesseract 執行檔位置（系統已安裝；找不到才退回 PATH 裡的 tesseract） */
function eg_tesseract_bin(): ?string {
    $cands = ['C:\\Program Files\\Tesseract-OCR\\tesseract.exe'];
    foreach ($cands as $c) if (file_exists($c)) return $c;
    return 'tesseract'; // 退回 PATH，找不到時 exec() 自然會失敗，由呼叫端判斷
}

/** 本專案自帶的辨識模型資料夾（chi_tra/chi_tra_vert/eng），不是系統安裝那份 */
function eg_tessdata_dir(): string {
    $p = realpath(__DIR__ . '/../bin/tessdata');
    return $p ?: (__DIR__ . '/../bin/tessdata');
}

/**
 * 對一張圖片跑 OCR，回傳逐「行」文字框（行＝同一個 block/par/line 內的字詞合併，
 * 畫面上以行為可點選單位；要組合跨行文字由使用者多選幾行後自行合併/編輯）。
 * @return array{ok:bool,message?:string,width?:int,height?:int,lines?:array}
 */
function eg_ocr_run_image(string $imgAbsPath): array {
    if (!is_file($imgAbsPath)) return ['ok' => false, 'message' => '找不到圖片檔'];
    $bin = eg_tesseract_bin();
    $tessDir = eg_tessdata_dir();
    if (!is_dir($tessDir)) return ['ok' => false, 'message' => '找不到辨識模型資料夾（src/bin/tessdata）'];

    $sz = @getimagesize($imgAbsPath);
    $w = (int)($sz[0] ?? 0);
    $h = (int)($sz[1] ?? 0);

    $outBase = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'eg_ocr_' . uniqid();
    // --psm 11：稀疏文字模式，工程圖的尺寸標註/料號文字散落各處、不是一整段段落，這個模式較貼切
    // 用 -c tessedit_create_tsv=1 直接開 TSV 輸出，不依賴「tsv」這個設定檔名——系統安裝的
    // tessdata/configs/tsv 設定檔只存在系統那份 tessdata 裡，換成自己的 --tessdata-dir 之後
    // 用設定檔名去找反而找不到（實測會回 read_params_file: Can't open tsv）。
    $cmd = sprintf(
        '%s %s %s -l chi_tra+eng --tessdata-dir %s --psm 11 -c tessedit_create_tsv=1 2>&1',
        escapeshellarg($bin), escapeshellarg($imgAbsPath), escapeshellarg($outBase), escapeshellarg($tessDir)
    );
    $out = [];
    exec($cmd, $out, $ret);
    $tsvPath = $outBase . '.tsv';
    if (!is_file($tsvPath)) {
        return ['ok' => false, 'message' => 'OCR 執行失敗：' . implode(' ', $out)];
    }
    $rows = file($tsvPath, FILE_IGNORE_NEW_LINES);
    @unlink($tsvPath);
    if (!$rows || count($rows) < 2) return ['ok' => true, 'width' => $w, 'height' => $h, 'lines' => []];

    $groups = []; // key: block|par|line
    for ($i = 1; $i < count($rows); $i++) {
        $f = explode("\t", $rows[$i]);
        if (count($f) < 12) continue;
        if ((int)$f[0] !== 5) continue; // 只取「字詞」層級（level=5）
        $block = $f[2]; $par = $f[3]; $lineNo = $f[4];
        $l = (int)$f[6]; $t = (int)$f[7]; $wd = (int)$f[8]; $ht = (int)$f[9]; $cf = (float)$f[10];
        $text = trim(implode("\t", array_slice($f, 11)));
        if ($text === '') continue;
        $key = $block . '|' . $par . '|' . $lineNo;
        if (!isset($groups[$key])) {
            $groups[$key] = ['left' => $l, 'top' => $t, 'right' => $l + $wd, 'bottom' => $t + $ht, 'words' => [], 'confs' => []];
        }
        $g = &$groups[$key];
        $g['left']   = min($g['left'], $l);
        $g['top']    = min($g['top'], $t);
        $g['right']  = max($g['right'], $l + $wd);
        $g['bottom'] = max($g['bottom'], $t + $ht);
        $g['words'][] = $text;
        $g['confs'][] = $cf;
        unset($g);
    }

    $result = [];
    foreach ($groups as $g) {
        $txt = implode(' ', $g['words']);
        // 整行全是中文（含空白）時去掉字詞間空白，閱讀上比較自然
        if (preg_match('/^[\p{Han}\s]+$/u', $txt)) $txt = preg_replace('/\s+/u', '', $txt);
        $avgConf = $g['confs'] ? array_sum($g['confs']) / count($g['confs']) : 0;
        $result[] = [
            'text' => $txt,
            'left' => $g['left'], 'top' => $g['top'],
            'width' => $g['right'] - $g['left'], 'height' => $g['bottom'] - $g['top'],
            // 取整數百分比——信心值只是給人參考，一位小數沒有實際意義，整數才能避開
            // 伺服器 serialize_precision 設定不同時 json_encode 浮點數印出長串雜訊位數的問題。
            'conf' => (int)round($avgConf),
        ];
    }
    // 由上到下、左到右排序（同一列容許 8px 誤差），符合閱讀習慣
    usort($result, function ($a, $b) {
        if (abs($a['top'] - $b['top']) > 8) return $a['top'] <=> $b['top'];
        return $a['left'] <=> $b['left'];
    });
    return ['ok' => true, 'width' => $w, 'height' => $h, 'lines' => $result];
}
