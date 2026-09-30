<?php
/**
 * 2026-09-30_gsop_import.php
 * 把「FOR CODEING 說明文件/as-sop」那批紙本 xlsx 匯入成標準作業流程 SOP（layout=gsop）。
 *
 * 使用者 2026-09-30 交辦＋以 AskUserQuestion 拍板四項：
 *   ① 併進「製造製程說明書 3-TD-02-02」底下當成一種版式（layout='gsop'），不另開第四種版面
 *   ② 軟體／硬體步驟做成**逐機種範本**，建立文件時自動帶入、之後仍可逐份修改
 *   ③ 這 10 個料號裡只有 40301017-01 有料號附件，其餘 9 個一筆都沒有
 *      → xlsx 內嵌圖面抽出來掛成該份 SOP 自己的圖面，**發行日期留白**（不可拿上傳日湊）
 *   ④ KAPP NILES 那一份綁「KAPP 3G」（型號 KNe3G，machine_id 126）
 *
 * 用法（都可重複執行，靠 ss_doc.src_tag／ss_msop_tpl 判斷有沒有匯過）：
 *   php 2026-09-30_gsop_import.php            試算，不寫入
 *   php 2026-09-30_gsop_import.php --run      實際寫入
 *   php 2026-09-30_gsop_import.php --rollback 移除本次匯入的文件與範本（以明確 src_tag 為條件）
 *
 * ── 兩個解析上的重點（不處理就會變成假資料）
 * ⑴ **儲存格裡有真正的換行**（「1.更換時機：…\nb.砂輪肩部外徑…」），一律原樣保留；
 *    把 \s+ 收成空白會讓備註整段黏成一行。只有「標題類」欄位（管理重點、步驟名稱、表頭）
 *    才把 CJK 中間那種排版用的空白收掉（外 觀→外觀），內容欄位一律不動。
 * ⑵ **Excel 的浮點數要清乾淨**：132.72200000000001 是二進位浮點的雜訊不是量測值，
 *    直接寫進資料庫會在紙上印出一串假的有效位數。
 */

if (PHP_SAPI !== 'cli') { header('Content-Type: text/plain; charset=utf-8'); }
$ROOT = dirname(__DIR__, 3);
require_once $ROOT . '/src/common/DBConnection.php';
require_once $ROOT . '/src/common/sopsip_lib.php';

$argvv    = $argv ?? [];
$RUN      = in_array('--run', $argvv, true);
$ROLLBACK = in_array('--rollback', $argvv, true);

const GSOP_TAG = 'gsop-import-20260930';          // 本次匯入的標記（rollback 以它為條件）
const SRC_DIR  = 'FOR CODEING 說明文件/as-sop';

$db = (new DBConnection())->getPDO();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
ss_ensure_schema($db);

/* ══════════════════════ xlsx 讀取 ══════════════════════ */

/** 共用字串表 */
function zx_shared(ZipArchive $z): array
{
    $out = [];
    $s = $z->getFromName('xl/sharedStrings.xml');
    if ($s === false) return $out;
    $x = @simplexml_load_string($s);
    if (!$x) return $out;
    foreach ($x->si as $si) {
        if (isset($si->t) && !isset($si->r)) { $out[] = (string)$si->t; continue; }
        $t = '';
        foreach ($si->r as $r) $t .= (string)$r->t;   // 同一格裡多段格式會被拆成好幾個 <r>
        $out[] = $t;
    }
    return $out;
}

/** 工作表清單：[['name'=>, 'path'=>'xl/worksheets/sheetN.xml'], …]（依活頁簿順序） */
function zx_sheets(ZipArchive $z): array
{
    $wb = $z->getFromName('xl/workbook.xml');
    $rl = $z->getFromName('xl/_rels/workbook.xml.rels');
    if ($wb === false || $rl === false) return [];
    $map = [];
    if (preg_match_all('~Id="([^"]+)"[^>]*Target="([^"]+)"~', $rl, $m, PREG_SET_ORDER)) {
        foreach ($m as $x) {
            $t = ltrim(str_replace('../', '', $x[2]), '/');
            $map[$x[1]] = strpos($t, 'xl/') === 0 ? $t : 'xl/' . $t;
        }
    }
    $out = [];
    if (preg_match_all('~<sheet\b[^>]*>~', $wb, $mm)) {
        foreach ($mm[0] as $tag) {
            if (!preg_match('~name="([^"]*)"~', $tag, $a)) continue;
            if (!preg_match('~r:id="([^"]+)"~', $tag, $b)) continue;
            $p = $map[$b[1]] ?? '';
            if ($p !== '') $out[] = ['name' => html_entity_decode($a[1], ENT_QUOTES, 'UTF-8'), 'path' => $p];
        }
    }
    return $out;
}

/**
 * Excel 數值清雜訊：132.72200000000001 → 132.722、0.0030000000000000001 → 0.003。
 * 整數保持整數（20240129 不可變成 20240129.0）。
 */
function zx_num(string $v): string
{
    if (!is_numeric($v)) return $v;
    $f = (float)$v;
    if ($f == (int)$f && abs($f) < 1e15) return (string)(int)$f;
    $s = rtrim(rtrim(sprintf('%.10F', $f), '0'), '.');
    return $s === '' || $s === '-' ? $v : $s;
}

/** 一張工作表的所有儲存格 → ['A1'=>'值'，保留換行] */
function zx_cells(ZipArchive $z, string $path, array $ss): array
{
    $data = $z->getFromName($path);
    if ($data === false) return [];
    $x = @simplexml_load_string($data);
    if (!$x) return [];
    $out = [];
    foreach ($x->sheetData->row as $row) {
        foreach ($row->c as $c) {
            $ref = (string)$c['r']; $t = (string)$c['t']; $v = '';
            if ($t === 'inlineStr') {
                $v = isset($c->is->t) ? (string)$c->is->t : '';
                foreach ($c->is->r ?? [] as $r) $v .= (string)$r->t;
            } elseif (isset($c->v)) {
                $raw = (string)$c->v;
                if ($t === 's')      $v = $ss[(int)$raw] ?? '';
                elseif ($t === 'str' || $t === 'e') $v = $raw;
                else                 $v = zx_num($raw);       // 一般數值才清雜訊
            }
            // 保留換行；只把每一行的頭尾空白修掉
            $v = implode("\n", array_map(fn($l) => trim($l), preg_split('/\r\n|\r|\n/', $v)));
            $v = trim($v);
            if ($v !== '') $out[$ref] = $v;
        }
    }
    return $out;
}

/** 合併儲存格清單 → [[c1,r1,c2,r2], …]（欄以 1 起算的數字表示） */
function zx_merges(ZipArchive $z, string $path): array
{
    $s = $z->getFromName($path);
    if ($s === false) return [];
    $out = [];
    if (preg_match_all('~<mergeCell[^>]*ref="([A-Z]+)(\d+):([A-Z]+)(\d+)"~', $s, $m, PREG_SET_ORDER)) {
        foreach ($m as $x) $out[] = [col_n($x[1]), (int)$x[2], col_n($x[3]), (int)$x[4]];
    }
    return $out;
}

function col_n(string $c): int
{
    $n = 0;
    for ($i = 0; $i < strlen($c); $i++) $n = $n * 26 + (ord($c[$i]) - 64);
    return $n;
}

function col_a(int $n): string
{
    $s = '';
    while ($n > 0) { $r = ($n - 1) % 26; $s = chr(65 + $r) . $s; $n = intdiv($n - 1 - $r, 26); }
    return $s;
}

/**
 * 某一列在 [$from,$to] 這幾欄裡的「格子」——**一定要看合併儲存格**。
 * 紙本上鍵值的欄位位置**逐列不同**：一般列是 N|O、P|Q、R|S 六格；
 * 研磨參數那幾列的值卻合併成 O:P 與 R:S，變成 N|O-P、Q|R-S 四格。
 * 只照固定欄位配對，就會把「修砂 0.04mm/9齒」配成「(空,修砂)、(0.04mm/9齒,空)」（實測踩到）。
 * 所以改成：依合併結果攤出實際格子，再一格鍵一格值依序配。
 */
function row_slots(array $cells, array $merges, int $row, string $from, string $to): array
{
    $f = col_n($from); $t = col_n($to);
    $skip = []; $span = [];
    foreach ($merges as [$c1, $r1, $c2, $r2]) {
        if ($row < $r1 || $row > $r2) continue;
        for ($c = $c1; $c <= $c2; $c++) {
            if ($c === $c1 && $row === $r1) { $span[$c] = $c2 - $c1 + 1; continue; }
            $skip[$c] = 1;                       // 被合併蓋住的格子（含被上一列往下合併蓋住的）
        }
    }
    $out = [];
    for ($c = $f; $c <= $t; $c++) {
        if (isset($skip[$c])) continue;
        $out[] = ['v' => trim((string)($cells[col_a($c) . $row] ?? '')), 'span' => $span[$c] ?? 1];
    }
    return $out;
}

/** 把一列的格子依序配成「鍵、值」成對 */
function slots_to_pairs(array $slots): array
{
    $pairs = [];
    for ($i = 0; $i < count($slots); $i += 2) {
        $k = $slots[$i]['v'];
        $v = $slots[$i + 1]['v'] ?? '';
        if ($k === '' && $v === '') continue;
        $pairs[] = ['k' => $k, 'v' => $v];
    }
    return $pairs;
}

/**
 * 步驟名稱正規化。使用者 2026-09-30 指定：**「工件參數」一律改成「工件規格」**
 * （紙本上寫的是參數，但那一格填的是這顆工件的規格）。唯一改名處就放這裡。
 */
function tidy_step_name(string $s): string
{
    $s = tidy_label($s);
    if ($s === '工件參數') return '工件規格';
    return $s;
}

/** 某一張工作表用到的內嵌圖（依在表上的位置由上到下、由左到右） */
function zx_sheet_images(ZipArchive $z, string $sheetPath): array
{
    $sx = $z->getFromName($sheetPath);
    if ($sx === false || !preg_match('~<drawing[^>]*r:id="([^"]+)"~', $sx, $d)) return [];
    $rels = $z->getFromName(preg_replace('~([^/]+)$~', '_rels/$1.rels', $sheetPath));
    if ($rels === false) return [];
    $dpath = '';
    if (preg_match_all('~Id="([^"]+)"[^>]*Target="([^"]+)"~', $rels, $m, PREG_SET_ORDER)) {
        foreach ($m as $x) {
            if ($x[1] !== $d[1]) continue;
            $t = ltrim(str_replace('../', '', $x[2]), '/');
            $dpath = strpos($t, 'xl/') === 0 ? $t : 'xl/' . $t;
        }
    }
    if ($dpath === '') return [];
    $dx = $z->getFromName($dpath);
    if ($dx === false) return [];
    $drl = $z->getFromName(preg_replace('~([^/]+)$~', '_rels/$1.rels', $dpath));
    $map = [];
    if ($drl !== false && preg_match_all('~Id="([^"]+)"[^>]*Target="([^"]+)"~', $drl, $m2, PREG_SET_ORDER)) {
        foreach ($m2 as $x) {
            $t = ltrim(str_replace('../', '', $x[2]), '/');
            $map[$x[1]] = strpos($t, 'xl/') === 0 ? $t : 'xl/' . $t;
        }
    }
    $out = [];
    if (preg_match_all('~<xdr:(twoCellAnchor|oneCellAnchor)[^>]*>(.*?)</xdr:\1>~s', $dx, $mm)) {
        foreach ($mm[2] as $blk) {
            if (!preg_match('~<xdr:from>.*?<xdr:col>(\d+)</xdr:col>.*?<xdr:row>(\d+)</xdr:row>~s', $blk, $a)) continue;
            if (!preg_match('~r:embed="([^"]+)"~', $blk, $b)) continue;
            $tgt = $map[$b[1]] ?? '';
            if ($tgt === '') continue;
            $bytes = $z->getFromName($tgt);
            if ($bytes === false || strlen($bytes) < 2000) continue;   // 太小的多半是裝飾用小圖示
            $out[] = ['col' => (int)$a[1], 'row' => (int)$a[2], 'bytes' => $bytes,
                      'ext' => strtolower(pathinfo($tgt, PATHINFO_EXTENSION)) ?: 'png'];
        }
    }
    usort($out, fn($a, $b) => [$a['row'], $a['col']] <=> [$b['row'], $b['col']]);
    return $out;
}

/* ══════════════════════ 格線小工具 ══════════════════════ */

function gv(array $c, string $col, int $row): string { return (string)($c[$col . $row] ?? ''); }

/** 標題類欄位：把 CJK 中間那種排版用的空白收掉（外 觀→外觀、軟 體 步 驟→軟體步驟） */
function tidy_label(string $s): string
{
    $s = preg_replace('/\s+/u', ' ', $s);
    // 只有「空白兩側都是中日韓字元或全形標點」才收掉，'。 b.' 這種不動
    $cjk = '\x{3000}-\x{303F}\x{4E00}-\x{9FFF}\x{FF00}-\x{FFEF}';
    for ($i = 0; $i < 4; $i++) $s = preg_replace('/(?<=[' . $cjk . '])\s+(?=[' . $cjk . '])/u', '', $s);
    return trim($s);
}

/** 找出某一欄裡第一個（收掉空白後）等於／包含某字樣的列號 */
function find_row(array $c, string $col, string $needle, int $from = 1, int $to = 80): int
{
    for ($r = $from; $r <= $to; $r++) {
        $v = tidy_label(gv($c, $col, $r));
        if ($v !== '' && mb_strpos($v, $needle) !== false) return $r;
    }
    return 0;
}

/** yyyymmdd / yyyy-mm-dd → Y-m-d，認不出來回空字串 */
function to_date(string $s): string
{
    $s = trim($s);
    if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $s, $m)) {
        return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? "$m[1]-$m[2]-$m[3]" : '';
    }
    if (preg_match('/^(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})$/', $s, $m)) {
        return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
    }
    return '';
}

/* ══════════════════════ 解析一張 SOP 工作表 ══════════════════════ */

/**
 * 回傳 ['head'=>…, 'soft'=>[…], 'hard'=>[…], 'items'=>[…], 'changes'=>[…]]，
 * 不是 SOP 版面的工作表回 null。
 */
function parse_sop(array $c, array $merges = []): ?array
{
    // ① 認版面：A4 那一格是「標 準 作 業 流 程 S O P」
    $hdrRow = 0;
    for ($r = 1; $r <= 12; $r++) {
        if (mb_strpos(tidy_label(gv($c, 'A', $r)), '標準作業流程') !== false) { $hdrRow = $r; break; }
    }
    if (!$hdrRow) return null;

    // ② 表頭：標籤在 $hdrRow、值在下一列
    $labels = [];
    foreach (range('A', 'Z') as $col) {
        $v = tidy_label(gv($c, $col, $hdrRow));
        if ($v !== '') $labels[$col] = $v;
    }
    $pick = function (array $names) use ($labels, $c, $hdrRow): string {
        foreach ($labels as $col => $lab) {
            foreach ($names as $n) if (mb_strpos($lab, $n) !== false) return gv($c, $col, $hdrRow + 1);
        }
        return '';
    };
    $head = [
        'machine'  => trim($pick(['加工機種'])),
        'customer' => trim($pick(['客戶名稱'])),
        'order_no' => trim($pick(['製令單號'])),
        'part_no'  => trim($pick(['產品料號'])),
        'proc'     => tidy_label($pick(['工程名稱'])),
        'maker'    => trim($pick(['製表人'])),
        'ver_no'   => trim($pick(['版次'])),
        'date'     => to_date($pick(['製表日期'])),
    ];

    // ③ 兩段步驟的邊界
    $softHdr = find_row($c, 'L', '軟體步驟');
    $hardHdr = find_row($c, 'L', '硬體步驟');
    $chgRow  = find_row($c, 'L', '更改記錄');
    $signRow = find_row($c, 'L', '核准');
    $endHard = $chgRow ?: ($signRow ?: 60);
    if (!$softHdr || !$hardHdr) return null;

    /** 某一段裡「L 欄有字」的那幾列＝群組起點 */
    $groups = function (int $from, int $to) use ($c): array {
        $g = [];
        for ($r = $from; $r <= $to; $r++) {
            $nm = tidy_step_name(gv($c, 'L', $r));
            if ($nm !== '') $g[] = ['row' => $r, 'name' => $nm];
        }
        foreach ($g as $i => &$x) $x['end'] = isset($g[$i + 1]) ? $g[$i + 1]['row'] - 1 : $to;
        unset($x);
        return $g;
    };

    // ④ 軟體步驟：要點區 N..S 一列最多三組（N,O）（P,Q）（R,S）
    $soft = [];
    foreach ($groups($softHdr + 1, $hardHdr - 1) as $g) {
        $kv = [];
        for ($r = $g['row']; $r <= $g['end']; $r++) {
            $pairs = slots_to_pairs(row_slots($c, $merges, $r, 'N', 'S'));
            if ($pairs) $kv[] = $pairs;
        }
        // 備註可能落在群組裡任何一列（合併時在最上面那一列），整段掃過再串起來
        $note = '';
        for ($r = $g['row']; $r <= $g['end']; $r++) {
            $t = trim(gv($c, 'T', $r));
            if ($t !== '') $note .= ($note === '' ? '' : "\n") . $t;
        }
        if (!$kv && $note === '') continue;                 // 紙本上留白的空格子不匯
        $soft[] = ['name' => $g['name'], 'kv' => $kv, 'note' => $note];
    }

    // ⑤ 硬體步驟：要點是一整格（N29:S31 合併），備註在 T
    $hard = [];
    foreach ($groups($hardHdr + 1, $endHard - 1) as $g) {
        $txt = '';
        for ($r = $g['row']; $r <= $g['end']; $r++) {
            $t = trim(gv($c, 'N', $r));
            if ($t !== '') $txt .= ($txt === '' ? '' : "\n") . $t;
        }
        $note = '';
        for ($r = $g['row']; $r <= $g['end']; $r++) {
            $t = trim(gv($c, 'T', $r));
            if ($t !== '') $note .= ($note === '' ? '' : "\n") . $t;
        }
        if ($txt === '' && $note === '') continue;
        $hard[] = ['name' => $g['name'], 'text' => $txt, 'note' => $note];
    }

    // ⑥ 檢驗項目（左半邊 A..K）
    $itemHdr = find_row($c, 'A', '管理重點');
    $items = [];
    if ($itemHdr) {
        /* 第五欄的標題**逐檔不同**：KKYC 是「檢具編號」、DRW 是「檢驗頻率」。
           照標題判斷要存進 tool_no 還是 freq，寫死一種會把另一種塞錯欄位。 */
        $colIisFreq = mb_strpos(tidy_label(gv($c, 'I', $itemHdr)), '頻率') !== false;
        $last = ($signRow ?: 60) - 1;
        for ($r = $itemHdr + 1; $r <= $last; $r++) {
            $ctrl = tidy_label(gv($c, 'A', $r));
            if ($ctrl === '' || $ctrl === '管理重點') continue;
            $c1 = tidy_label(gv($c, 'C', $r));
            $q = ''; $up = ''; $lo = '';
            if ($c1 === '上限') {
                $up = trim(gv($c, 'E', $r));
                for ($k = $r + 1; $k <= min($r + 3, $last); $k++) {
                    if (tidy_label(gv($c, 'C', $k)) === '下限') { $lo = trim(gv($c, 'E', $k)); break; }
                }
            } else {
                $q = trim(gv($c, 'C', $r));
            }
            $five = trim(gv($c, 'I', $r));
            $items[] = [
                'ctrl' => $ctrl, 'q' => $q, 'up' => $up, 'lo' => $lo,
                'owner' => tidy_label(gv($c, 'F', $r)),
                'method' => tidy_label(gv($c, 'G', $r)),
                'tool_no' => $colIisFreq ? '' : $five,
                'freq'    => $colIisFreq ? $five : '',
                'note'    => trim(gv($c, 'J', $r)),
            ];
        }
    }

    // ⑦ 更改記錄
    $changes = [];
    if ($chgRow) {
        for ($r = $chgRow; $r <= ($signRow ?: $chgRow + 6); $r++) {
            $d = to_date(gv($c, 'L', $r));
            $t = trim(gv($c, 'M', $r));
            if ($d === '' && $t === '') continue;
            if ($t !== '') $changes[] = ['date' => $d, 'text' => $t];
        }
    }

    return ['head' => $head, 'soft' => $soft, 'hard' => $hard, 'items' => $items, 'changes' => $changes];
}

/* ══════════════════════ 機台對照 ══════════════════════ */

/**
 * 紙本上的「加工機種」→ 機台型號＋機台 id。
 * **KAPP NILES 刻意對到 KAPP 3G（KNe3G / machine_id 126）**：機台主檔裡型號叫
 * 「KAPP NILES」的那一台其實是齒輪量測機，而這份 SOP 的工程名稱是齒輪研磨
 * ——使用者 2026-09-30 拍板綁 KAPP 3G。
 */
function map_machine(PDO $db, string $txt): array
{
    $t = strtoupper(preg_replace('/\s+/', '', $txt));
    $model = '';
    if ($t === '') return ['model' => '', 'ids' => [], 'note' => '紙本沒有寫加工機種'];
    if (strpos($t, 'KAPP') !== false)          $model = 'KNe3G';
    elseif (strpos($t, 'KX500') !== false)     $model = 'KX500';
    elseif (strpos($t, '3040') !== false)      $model = 'LHG-3040';
    else                                        $model = trim($txt);

    $st = $db->prepare("SELECT machine_id FROM machine_list WHERE machine_model=? ORDER BY field_no, machine_id");
    $st->execute([$model]);
    $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
    return ['model' => $model, 'ids' => $ids,
            'note'  => $ids ? '' : "機台主檔查無型號 {$model}"];
}

/* ══════════════════════ 寫入 ══════════════════════ */

function put_draw(PDO $db, int $docId, int $verId, array $img, string $origName, string $tag): int
{
    $dir = ss_attach_dir($db);
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    $now  = (string)$db->query("SELECT NOW()")->fetchColumn();
    $name = 'ss' . $docId . '_' . preg_replace('/\D/', '', $now) . '_' . bin2hex(random_bytes(3)) . '.' . $img['ext'];
    $fs   = rtrim($dir, "/\\") . DIRECTORY_SEPARATOR . $name;
    if (@file_put_contents($fs, $img['bytes']) === false) throw new RuntimeException('圖片寫入失敗：' . $fs);
    $db->prepare("INSERT INTO ss_file (doc_id, ver_id, usage_kind, src, file_name, orig_name, file_size,
                                       uploaded_at, uploaded_by, src_tag)
                  VALUES (?,?,'draw','upload',?,?,?,NOW(),0,?)")
       ->execute([$docId, $verId ?: null, $name, $origName, strlen($img['bytes']), $tag]);
    return (int)$db->lastInsertId();
}

/* ══════════════════════ rollback ══════════════════════ */

if ($ROLLBACK) {
    $st = $db->prepare("SELECT doc_id FROM ss_doc WHERE src_tag LIKE ?");
    $st->execute([GSOP_TAG . '%']);
    $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
    echo "將移除 " . count($ids) . " 份匯入的文件：" . implode(',', $ids) . "\n";
    $st = $db->prepare("SELECT file_id, file_name FROM ss_file WHERE src_tag LIKE ?");
    $st->execute([GSOP_TAG . '%']);
    $files = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    echo "將移除 " . count($files) . " 個圖檔\n";
    $tplN = (int)$db->query("SELECT COUNT(*) FROM ss_msop_tpl")->fetchColumn();
    echo "將清空機種步驟範本 {$tplN} 列\n";
    if (!$RUN) { echo "（試算，加 --run 才真的刪）\n"; exit; }
    $dir = ss_attach_dir($db);
    $db->beginTransaction();
    foreach ($ids as $id) {
        $vs = $db->prepare("SELECT ver_id FROM ss_ver WHERE doc_id=?"); $vs->execute([$id]);
        foreach ($vs->fetchAll(PDO::FETCH_COLUMN) as $v) {
            $db->prepare("DELETE FROM ss_step WHERE ver_id=?")->execute([$v]);
            $db->prepare("DELETE FROM ss_item WHERE ver_id=?")->execute([$v]);
            $db->prepare("DELETE FROM ss_sign WHERE ver_id=?")->execute([$v]);
        }
        $db->prepare("DELETE FROM ss_ver WHERE doc_id=?")->execute([$id]);
        $db->prepare("DELETE FROM ss_doc_machine WHERE doc_id=?")->execute([$id]);
        $db->prepare("DELETE FROM ss_file WHERE doc_id=?")->execute([$id]);
        $db->prepare("DELETE FROM ss_doc WHERE doc_id=?")->execute([$id]);
    }
    $db->exec("DELETE FROM ss_msop_tpl");
    $db->commit();
    foreach ($files as $f) @unlink(rtrim($dir, "/\\") . DIRECTORY_SEPARATOR . $f['file_name']);
    echo "已移除。\n";
    exit;
}

/* ══════════════════════ 主流程 ══════════════════════ */

$dir = $ROOT . '/' . SRC_DIR;
if (!is_dir($dir)) { exit("找不到來源資料夾：$dir\n"); }
$files = glob($dir . '/*.xlsx') ?: [];
sort($files);

$parsed = [];   // 每一張 SOP 工作表一筆
foreach ($files as $f) {
    $base = basename($f);
    if (strpos($base, '~$') === 0) continue;               // Excel 開著時留下的鎖定檔
    $z = new ZipArchive;
    if ($z->open($f) !== true) { echo "!! 開不了：$base\n"; continue; }
    $ss = zx_shared($z);
    foreach (zx_sheets($z) as $sh) {
        $cells = zx_cells($z, $sh['path'], $ss);
        $p = parse_sop($cells, zx_merges($z, $sh['path']));
        if (!$p) continue;
        $p['file']  = $base;
        $p['sheet'] = $sh['name'];
        $p['imgs']  = zx_sheet_images($z, $sh['path']);
        // 紙本沒填產品料號時（DRW 的臥式那一張），退回用檔名
        if ($p['head']['part_no'] === '') $p['head']['part_no'] = preg_replace('/\.xlsx$/i', '', $base);
        $parsed[] = $p;
    }
    $z->close();
}

echo "═══ 來源：" . count($files) . " 個 xlsx，解析出 " . count($parsed) . " 張 SOP 工作表 ═══\n\n";

/* --detail=關鍵字：把解析結果整份印出來，拿去跟原始 Excel 逐格核對用（不寫入） */
foreach ($argvv as $a) {
    if (strpos($a, '--detail') !== 0) continue;
    $kw = substr($a, 9);
    foreach ($parsed as $p) {
        if ($kw !== '' && stripos($p['file'], $kw) === false) continue;
        echo "┌── {$p['file']} [{$p['sheet']}]\n";
        foreach ($p['head'] as $k => $v) if ($v !== '') echo "│ 表頭 $k = $v\n";
        foreach ($p['soft'] as $s) {
            echo "│ 軟體【{$s['name']}】\n";
            foreach ($s['kv'] as $row) {
                $cells = [];
                foreach ($row as $kk) $cells[] = ($kk['k'] !== '' ? $kk['k'] : '·') . '＝' . ($kk['v'] !== '' ? $kk['v'] : '·');
                echo "│     " . implode('　｜　', $cells) . "\n";
            }
            if ($s['note'] !== '') echo "│     〔備註〕" . str_replace("\n", ' ⏎ ', $s['note']) . "\n";
        }
        foreach ($p['hard'] as $s) {
            echo "│ 硬體【{$s['name']}】" . str_replace("\n", ' ⏎ ', $s['text']) . "\n";
            if ($s['note'] !== '') echo "│     〔備註〕" . str_replace("\n", ' ⏎ ', $s['note']) . "\n";
        }
        foreach ($p['items'] as $it) {
            echo "│ 檢驗 {$it['ctrl']}｜" . ($it['q'] !== '' ? $it['q'] : "上{$it['up']}/下{$it['lo']}")
               . "｜{$it['owner']}｜{$it['method']}｜檢具{$it['tool_no']}｜頻率{$it['freq']}｜{$it['note']}\n";
        }
        foreach ($p['changes'] as $ch) echo "│ 更改 {$ch['date']} {$ch['text']}\n";
        echo "└──\n\n";
    }
    exit;
}

/* ── ① 機種步驟範本：同一個機種取「步驟最完整」的那一張當範本 ──
   刻意從實際紙本取，不自己編一份出來；軟體步驟只留鍵不留值（值是各料號自己的參數）。 */
$byModel = [];
foreach ($parsed as $i => $p) {
    $mm = map_machine($db, $p['head']['machine']);
    $parsed[$i]['mm'] = $mm;
    $m = $mm['model'];
    if ($m === '') continue;
    $score = count($p['soft']) * 100 + count($p['hard']);
    if (!isset($byModel[$m]) || $score > $byModel[$m]['score']) {
        $byModel[$m] = ['score' => $score, 'p' => $p];
    }
}

echo "── 機種步驟範本 ──\n";
foreach ($byModel as $model => $x) {
    $p = $x['p'];
    printf("  %-10s 來源 %s[%s]　軟體 %d 項／硬體 %d 項\n",
        $model, $p['file'], $p['sheet'], count($p['soft']), count($p['hard']));
    foreach ($p['soft'] as $s) {
        $keys = [];
        foreach ($s['kv'] as $row) foreach ($row as $kk) if ($kk['k'] !== '') $keys[] = $kk['k'];
        echo "       軟 " . $s['name'] . '：' . (implode('／', $keys) ?: '（無參數欄）') . "\n";
    }
    foreach ($p['hard'] as $s) echo "       硬 " . $s['name'] . "\n";
}
echo "\n";

/* ── ② 文件 ── */
$made = 0; $skip = 0; $warn = [];
echo "── 文件 ──\n";
foreach ($parsed as $p) {
    $h = $p['head'];
    $tag = GSOP_TAG . ':' . $p['file'] . '#' . $p['sheet'];
    $exists = (function (PDO $db, string $tag): int {
        $st = $db->prepare("SELECT doc_id FROM ss_doc WHERE src_tag=? AND is_deleted=0 LIMIT 1");
        $st->execute([$tag]); return (int)$st->fetchColumn();
    })($db, $tag);

    // 料號主檔：同名料號用客戶分辨（C5487 在主檔有三筆，分屬全宏／欣欣龍／登裕）
    $partDId = 0; $partWhy = '';
    $st = $db->prepare("SELECT d.d_id, c.customer FROM d_setting d
                        LEFT JOIN customer_list c ON c.Customer_Id=d.Customer_Id
                        WHERE d.D_Setting_Id=? ORDER BY d.d_id");
    $st->execute([$h['part_no']]);
    $cands = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (count($cands) === 1) $partDId = (int)$cands[0]['d_id'];
    elseif (count($cands) > 1) {
        foreach ($cands as $cd) if ($h['customer'] !== '' && (string)$cd['customer'] === $h['customer']) { $partDId = (int)$cd['d_id']; break; }
        if (!$partDId) { $partWhy = '主檔有 ' . count($cands) . ' 筆同名料號，客戶對不上'; }
    } else { $partWhy = '料號主檔查不到'; }

    $mm = $p['mm'];
    $procNo = 0;
    if ($h['proc'] !== '') {
        // 「齒輪研磨」在製程主檔叫「齒研」——先全等，再退回前綴比對
        $q = $db->prepare("SELECT ProcessNo FROM process_no WHERE ProcessName=? LIMIT 1");
        $q->execute([$h['proc']]); $procNo = (int)$q->fetchColumn();
        if (!$procNo) {
            $q = $db->prepare("SELECT ProcessNo FROM process_no WHERE ProcessName='齒研' LIMIT 1");
            if (mb_strpos($h['proc'], '齒輪研磨') !== false || mb_strpos($h['proc'], '齒研') !== false) {
                $q->execute(); $procNo = (int)$q->fetchColumn();
            }
        }
    }

    $line = sprintf("  %-26s %-9s 機種%-10s 版次%-3s %s 軟%d 硬%d 檢驗%d 圖%d",
        $p['file'] . '[' . $p['sheet'] . ']', $h['part_no'], $mm['model'], $h['ver_no'],
        $h['date'] ?: '(無日期)', count($p['soft']), count($p['hard']), count($p['items']), count($p['imgs']));
    if ($exists) { echo $line . "　→ 已匯過 doc_id={$exists}，略過\n"; $skip++; continue; }
    echo $line . "\n";
    if ($partWhy !== '') $warn[] = "{$p['file']}[{$p['sheet']}]：{$partWhy}（料號 {$h['part_no']}）";
    if ($mm['note'] !== '') $warn[] = "{$p['file']}[{$p['sheet']}]：{$mm['note']}";
    if (!$procNo) $warn[] = "{$p['file']}[{$p['sheet']}]：製程「{$h['proc']}」對不到製程主檔";
    if (!$h['date']) $warn[] = "{$p['file']}[{$p['sheet']}]：沒有製表日期";
    $made++;
}

echo "\n可新增 {$made} 份、已存在略過 {$skip} 份\n";
if ($warn) { echo "\n── 要注意的地方 ──\n"; foreach (array_unique($warn) as $w) echo "  ⚠ $w\n"; }

if (!$RUN) { echo "\n（以上為試算，沒有寫入任何資料。加 --run 才真的寫入）\n"; exit; }

/* ══════════════ 真的寫入 ══════════════ */

$db->beginTransaction();
try {
    // ① 範本
    $db->exec("DELETE FROM ss_msop_tpl");
    foreach ($byModel as $model => $x) {
        $rows = [];
        foreach ($x['p']['soft'] as $s) {
            $kv = [];
            foreach ($s['kv'] as $row) {
                $r2 = [];
                foreach ($row as $kk) if ($kk['k'] !== '') $r2[] = ['k' => $kk['k'], 'v' => ''];
                if ($r2) $kv[] = $r2;
            }
            $rows[] = ['step_name' => $s['name'], 'kv' => $kv, 'step_text' => '', 'note' => ''];
        }
        ss_msop_tpl_replace($db, $model, 'soft', $rows, 0);

        $rows = [];
        foreach ($x['p']['hard'] as $s) {
            $rows[] = ['step_name' => $s['name'], 'kv' => [], 'step_text' => $s['text'], 'note' => $s['note']];
        }
        ss_msop_tpl_replace($db, $model, 'hard', $rows, 0);
    }

    // ② 文件
    $newIds = [];
    foreach ($parsed as $p) {
        $h = $p['head'];
        $tag = GSOP_TAG . ':' . $p['file'] . '#' . $p['sheet'];
        $st = $db->prepare("SELECT doc_id FROM ss_doc WHERE src_tag=? AND is_deleted=0 LIMIT 1");
        $st->execute([$tag]);
        if ((int)$st->fetchColumn()) continue;

        // 料號
        $partDId = 0;
        $st = $db->prepare("SELECT d.d_id, c.customer FROM d_setting d
                            LEFT JOIN customer_list c ON c.Customer_Id=d.Customer_Id
                            WHERE d.D_Setting_Id=? ORDER BY d.d_id");
        $st->execute([$h['part_no']]);
        $cands = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (count($cands) === 1) $partDId = (int)$cands[0]['d_id'];
        elseif (count($cands) > 1) {
            foreach ($cands as $cd) if ($h['customer'] !== '' && (string)$cd['customer'] === $h['customer']) { $partDId = (int)$cd['d_id']; break; }
        }
        // 製程
        $procNo = 0; $procNm = $h['proc'];
        $q = $db->prepare("SELECT ProcessNo, ProcessName FROM process_no WHERE ProcessName=? LIMIT 1");
        $q->execute([$h['proc']]); $pr = $q->fetch(PDO::FETCH_ASSOC);
        if (!$pr && (mb_strpos($h['proc'], '齒輪研磨') !== false || mb_strpos($h['proc'], '齒研') !== false)) {
            $q = $db->prepare("SELECT ProcessNo, ProcessName FROM process_no WHERE ProcessName='齒研' LIMIT 1");
            $q->execute(); $pr = $q->fetch(PDO::FETCH_ASSOC);
        }
        if ($pr) { $procNo = (int)$pr['ProcessNo']; $procNm = (string)$pr['ProcessName']; }

        $mm = $p['mm'];
        $formDate = $h['date'] ?: date('Y-m-d');
        $title = trim($h['part_no'] . ' ' . $procNm . ($mm['model'] !== '' ? ' ' . $mm['model'] : ''));

        // 客戶：一律由料號主檔帶（紙本上的「客戶名稱」只是簡稱，主檔才是正本）
        $cusId = ''; $cusNm = $h['customer'];
        if ($partDId > 0) {
            $q = $db->prepare("SELECT c.Customer_Id, c.customer FROM d_setting d
                               JOIN customer_list c ON c.Customer_Id=d.Customer_Id WHERE d.d_id=?");
            $q->execute([$partDId]);
            if ($cc = $q->fetch(PDO::FETCH_ASSOC)) { $cusId = (string)$cc['Customer_Id']; $cusNm = (string)$cc['customer']; }
        }

        $db->prepare("INSERT INTO ss_doc (kind, scope, layout, part_d_id, part_no_text, title, process_no, proc_name,
                                          machine_model, customer_id, customer_name, created_at, created_by,
                                          created_by_name, src_tag)
                      VALUES ('process','part','gsop',?,?,?,?,?,?,?,?,NOW(),0,'匯入(as-sop)',?)")
           ->execute([$partDId ?: null, $h['part_no'], $title, $procNo ?: null, $procNm,
                      $mm['model'] ?: null, $cusId ?: null, $cusNm ?: null, $tag]);
        $docId = (int)$db->lastInsertId();
        $newIds[] = $docId;

        /* 機台：**asof 一定要帶這份文件的日期**——KAPP 3G 在 2025-01-14 停用，
           不帶日期的話 ss_doc_machines_set() 會把它當停用機台安靜濾掉，
           畫面上就變成「沒有綁機台」而且不報錯。 */
        if ($mm['ids']) ss_doc_machines_set($db, $docId, $mm['ids'], $formDate);

        // 版次
        $verNo = $h['ver_no'] !== '' ? $h['ver_no'] : '01';
        $revNote = '';
        foreach ($p['changes'] as $ch) $revNote .= ($revNote === '' ? '' : "\n") . trim(($ch['date'] ?? '') . ' ' . $ch['text']);
        $db->prepare("INSERT INTO ss_ver (doc_id, ver_no, form_date, rev_note, status, customer_id, customer_name,
                                          created_at, created_by)
                      VALUES (?,?,?,?, 'draft', ?,?, NOW(), 0)")
           ->execute([$docId, $verNo, $formDate, $revNote ?: null, $cusId ?: null, $cusNm ?: null]);
        $verId = (int)$db->lastInsertId();
        $db->prepare("UPDATE ss_doc SET cur_ver_id=? WHERE doc_id=?")->execute([$verId, $docId]);

        // 步驟
        $seq = 0;
        foreach ($p['soft'] as $s) {
            $seq++;
            $kv = ss_kv_decode($s['kv']);
            $db->prepare("INSERT INTO ss_step (ver_id, seq, sect, step_name, step_text, note, kv_json)
                          VALUES (?,?,'soft',?,NULL,?,?)")
               ->execute([$verId, $seq, $s['name'], $s['note'] ?: null,
                          $kv ? json_encode($kv, JSON_UNESCAPED_UNICODE) : null]);
        }
        $seq = 0;
        foreach ($p['hard'] as $s) {
            $seq++;
            $db->prepare("INSERT INTO ss_step (ver_id, seq, sect, step_name, step_text, note)
                          VALUES (?,?,'hard',?,?,?)")
               ->execute([$verId, $seq, $s['name'], $s['text'] ?: null, $s['note'] ?: null]);
        }

        // 檢驗項目
        $seq = 0;
        foreach ($p['items'] as $it) {
            $seq++;
            $db->prepare("INSERT INTO ss_item (ver_id, seq, ctrl_point, q_char, up_limit, lo_limit,
                                               owner, method, tool_no, freq, note)
                          VALUES (?,?,?,?,?,?,?,?,?,?,?)")
               ->execute([$verId, $seq, $it['ctrl'], $it['q'] ?: null, $it['up'] ?: null, $it['lo'] ?: null,
                          $it['owner'] ?: null, $it['method'] ?: null, $it['tool_no'] ?: null,
                          $it['freq'] ?: null, $it['note'] ?: null]);
        }

        /* 圖面：xlsx 內嵌圖抽出來掛成這份 SOP 自己的圖面。
           **不會有發行日期**（它不是料號附件），畫面與列印會照實寫「尚未登錄發行章日期」，
           之後在料號附件補上正式圖面再改選就會自動帶入（使用者 2026-09-30 拍板留白）。 */
        if ($p['imgs']) {
            $img = $p['imgs'][0];      // 左上角那一張就是圖面
            $fid = put_draw($db, $docId, $verId, $img, $p['file'] . '#' . $p['sheet'] . '.' . $img['ext'], $tag);
            $db->prepare("UPDATE ss_ver SET draw_file_id=? WHERE ver_id=?")->execute([$fid, $verId]);
            for ($i = 1; $i < count($p['imgs']); $i++) {
                put_draw($db, $docId, $verId, $p['imgs'][$i],
                         $p['file'] . '#' . $p['sheet'] . '-' . ($i + 1) . '.' . $p['imgs'][$i]['ext'], $tag);
            }
        }
    }
    $db->commit();
    echo "\n✔ 已寫入。新建文件 doc_id：" . implode(',', $newIds) . "\n";
    echo "  機種範本：" . implode('／', array_keys($byModel)) . "\n";
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    echo "\n!! 寫入失敗，已全部回復：" . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
