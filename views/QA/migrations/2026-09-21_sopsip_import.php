<?php
/**
 * 2026-09-21_sopsip_import.php — 把「FOR CODEING 說明文件/SIP SOP」底下既有的紙本電子檔
 * 匯入成 SOP／SIP 正式單據（使用者 2026-09-21 指定：xlsx/docx 解析成單據，PDF 掛成掃描附件）
 *
 * 用法（CLI）：
 *   php 2026-09-21_sopsip_import.php            試算，只印出會建立什麼，不寫入
 *   php 2026-09-21_sopsip_import.php --run      實際寫入
 *   php 2026-09-21_sopsip_import.php --rollback 刪掉本腳本建立的資料（連同落地的圖片檔）
 *
 * 可重複執行：同一個來源檔（src_tag）已經匯入過就跳過，不會建出第二份。
 *
 * 解析口徑
 *   3-TD-02-01 設備操作說明書(docx) → kind=equip，機台以「機器編號」對 machine_list.asset_no，
 *       對不到再試 field_no／machine_model；都對不到就**不建立**（機台 SOP 沒有機台等於沒有意義），
 *       改列進報告讓人補機台主檔。
 *   3-TD-02-02 製造製程說明書(xlsx) → kind=process，scope=general（這幾份都是通用作業）。
 *       步驟圖由 xl/drawings 的錨點列號對回步驟；同一步驟有多張時第一張當參考圖示，
 *       其餘仍存成附件不丟掉。
 *   標準檢驗指導書(xlsx) → kind=sip，料號取表頭「產品料號」對 d_setting.D_Setting_Id；
 *       對得到就 scope=part（存 d_id），對不到 scope=general 並在報告列出。
 *   PDF／JPG → 掛成該文件的掃描附件（usage=scan）；找不到對應文件的（品保課那幾份量測儀器操作）
 *       另外建一份 kind=process、scope=general 的文件，內容就是那張掃描檔。
 */

$ROOT = realpath(__DIR__ . '/../../..');
require_once $ROOT . '/src/common/_config.php';
require_once $ROOT . '/src/common/DBConnection.php';
require_once $ROOT . '/src/common/sopsip_lib.php';
require_once $ROOT . '/src/common/attach_lib.php';

$RUN      = in_array('--run', $argv, true);
$ROLLBACK = in_array('--rollback', $argv, true);
$SRC_DIR  = $ROOT . '/FOR CODEING 說明文件/SIP SOP';

$db = (new DBConnection())->getPDO();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
ss_ensure_schema($db);
/* 來源檔標記：可重複執行靠它判「這個檔匯過了沒」。存在 ss_doc.proc_name 之外的地方會多一張表，
   故直接加一個欄位（ensure_col 可重複執行）。 */
ss_ensure_col($db, 'ss_doc', 'src_tag', "VARCHAR(255) NULL COMMENT '匯入來源檔名，重複執行時用來判斷是否已匯入'");
ss_ensure_col($db, 'ss_file', 'src_tag', "VARCHAR(255) NULL COMMENT '匯入來源檔名'");

/* ══════════════════════ xlsx / docx 解析 ══════════════════════ */

/** xlsx 全部儲存格 → ['A1'=>值]；共用字串與 inline 字串都處理 */
function xl_cells(string $path, string $sheet = 'xl/worksheets/sheet1.xml'): array
{
    $z = new ZipArchive;
    if ($z->open($path) !== true) return [];
    $ss = [];
    if (($s = $z->getFromName('xl/sharedStrings.xml')) !== false) {
        $x = @simplexml_load_string($s);
        if ($x) foreach ($x->si as $si) {
            $t = '';
            foreach ($si->xpath('.//*[local-name()="t"]') as $tt) $t .= (string)$tt;
            $ss[] = $t;
        }
    }
    $sh = $z->getFromName($sheet);
    if ($sh === false) { $z->close(); return []; }
    $x = @simplexml_load_string($sh);
    $out = [];
    if ($x) foreach ($x->sheetData->row as $row) {
        foreach ($row->c as $c) {
            $r = (string)$c['r']; $t = (string)$c['t']; $v = (string)$c->v;
            if ($t === 's') $v = $ss[(int)$v] ?? '';
            elseif ($t === 'inlineStr') {
                $v = '';
                foreach ($c->xpath('.//*[local-name()="t"]') as $tt) $v .= (string)$tt;
            }
            $v = trim(preg_replace('/[ \t]+/u', ' ', $v));
            if ($v !== '') $out[$r] = $v;
        }
    }
    $z->close();
    return $out;
}

/** xlsx 內嵌圖片 → [['row'=>0起算, 'col'=>0起算, 'bytes'=>..., 'ext'=>'png']] */
function xl_images(string $path): array
{
    $z = new ZipArchive;
    if ($z->open($path) !== true) return [];
    $out = [];
    for ($i = 0; $i < $z->numFiles; $i++) {
        $n = $z->getNameIndex($i);
        if (!preg_match('~^xl/drawings/drawing\d+\.xml$~', $n)) continue;
        $s = $z->getFromName($n);
        $rels = $z->getFromName(str_replace('drawings/', 'drawings/_rels/', $n) . '.rels');
        $map = [];
        if ($rels && preg_match_all('~Id="([^"]+)"[^>]*Target="([^"]+)"~', $rels, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) $map[$x[1]] = 'xl/' . ltrim(str_replace('../', '', $x[2]), '/');
        }
        if (preg_match_all('~<xdr:(twoCellAnchor|oneCellAnchor)[^>]*>(.*?)</xdr:\1>~s', $s, $mm)) {
            foreach ($mm[2] as $blk) {
                if (!preg_match('~<xdr:from>.*?<xdr:col>(\d+)</xdr:col>.*?<xdr:row>(\d+)</xdr:row>~s', $blk, $a)) continue;
                if (!preg_match('~r:embed="([^"]+)"~', $blk, $b)) continue;
                $target = $map[$b[1]] ?? '';
                if ($target === '') continue;
                $bytes = $z->getFromName($target);
                if ($bytes === false) continue;
                $out[] = ['col' => (int)$a[1], 'row' => (int)$a[2], 'bytes' => $bytes,
                          'ext' => strtolower(pathinfo($target, PATHINFO_EXTENSION))];
            }
        }
    }
    $z->close();
    usort($out, fn($a, $b) => [$a['row'], $a['col']] <=> [$b['row'], $b['col']]);
    return $out;
}

/** docx 的表格 → [ [ [cell,cell,...], ... ], ... ] */
function dx_tables(string $path): array
{
    $z = new ZipArchive;
    if ($z->open($path) !== true) return [];
    $s = $z->getFromName('word/document.xml');
    $z->close();
    if ($s === false) return [];
    $out = [];
    if (!preg_match_all('~<w:tbl>(.*?)</w:tbl>~s', $s, $mt)) return [];
    foreach ($mt[1] as $tbl) {
        $rows = [];
        if (preg_match_all('~<w:tr[ >](.*?)</w:tr>~s', $tbl, $mr)) {
            foreach ($mr[1] as $tr) {
                $cells = [];
                if (preg_match_all('~<w:tc[ >](.*?)</w:tc>~s', $tr, $mc)) {
                    foreach ($mc[1] as $tc) {
                        // 段落之間要換行，否則「操作方法」那格會把好幾個步驟黏成一行。
                        // **`<w:t[^>]*>` 會連 `<w:tcW w:w="1728" …>` 一起吃掉**（w:t 後面接 cW 也符合 [^>]*），
                        // 抓出來的會是欄寬屬性字串而不是文字，所以一定要寫成 `<w:t>` 或 `<w:t 空白…>`。
                        $paras = [];
                        foreach (preg_split('~</w:p>~', $tc) as $p) {
                            $t = '';
                            if (preg_match_all('~<w:t(?:\s[^>]*)?>(.*?)</w:t>~s', $p, $mx)) {
                                foreach ($mx[1] as $x) $t .= html_entity_decode($x, ENT_QUOTES, 'UTF-8');
                            }
                            $t = trim($t);
                            if ($t !== '') $paras[] = $t;
                        }
                        $cells[] = implode("\n", $paras);
                    }
                }
                if ($cells) $rows[] = $cells;
            }
        }
        if ($rows) $out[] = $rows;
    }
    return $out;
}

/** 'C12' → ['C', 12]；欄名可能是兩個字母（AA） */
function ref_split(string $ref): array
{
    preg_match('/^([A-Z]+)(\d+)$/', $ref, $m);
    return [$m[1] ?? 'A', (int)($m[2] ?? 0)];
}

/** 欄名 → 0 起算的索引；索引 → 欄名 */
function col_idx(string $col): int
{
    $n = 0;
    foreach (str_split($col) as $ch) $n = $n * 26 + (ord($ch) - 64);
    return $n - 1;
}
function idx_col(int $i): string
{
    $s = '';
    $i++;
    while ($i > 0) { $r = ($i - 1) % 26; $s = chr(65 + $r) . $s; $i = intdiv($i - 1, 26); }
    return $s;
}

/**
 * 在儲存格裡找標籤所在的位置。**不可以寫死儲存格位置**——同一份 SIP 範本在不同檔案裡
 * 表頭有的在第 5 列、有的在第 7 列（實測 標準檢驗指導書 SIP 子資料夾那幾份整個往下位移兩列），
 * 寫死列號會讀出一片空白而且完全不報錯。
 * @return array|null [col, row]
 */
function find_label(array $cells, string $label): ?array
{
    $want = preg_replace('/\s+/u', '', $label);
    foreach ($cells as $ref => $v) {
        if (preg_replace('/\s+/u', '', $v) === $want) return ref_split($ref);
    }
    return null;
}

/** Excel 日期序號 或 2024.04.23 這種字串 → Y-m-d；看不懂回空字串 */
function xl_date($v): string
{
    $v = trim((string)$v);
    if ($v === '') return '';
    if (preg_match('/^\d{5}(\.\d+)?$/', $v)) {           // Excel 序號（1900 制，含它那個不存在的 1900-02-29）
        $ts = (int)(((float)$v - 25569) * 86400);
        return gmdate('Y-m-d', $ts);
    }
    if (preg_match('/(\d{4})[.\-\/](\d{1,2})[.\-\/](\d{1,2})/', $v, $m)) {
        return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
    }
    if (preg_match('/^(\d{3})[.\-\/](\d{1,2})[.\-\/](\d{1,2})$/', $v, $m)) {   // 民國年
        return sprintf('%04d-%02d-%02d', 1911 + (int)$m[1], $m[2], $m[3]);
    }
    return '';
}

/* ══════════════════════ 共用寫入 ══════════════════════ */

$REPORT = ['doc' => [], 'skip' => [], 'warn' => []];
$IMPORT_BY = 'Claude匯入';

/** 這個來源檔匯過了沒 */
function already(PDO $db, string $tag): int
{
    $st = $db->prepare("SELECT doc_id FROM ss_doc WHERE src_tag=? AND is_deleted=0 LIMIT 1");
    $st->execute([$tag]);
    return (int)$st->fetchColumn();
}

/** 存一張圖到本模組資料夾，回傳 ss_file.file_id */
function put_file(PDO $db, int $docId, int $verId, string $usage, string $bytes, string $ext, string $origName, string $tag): int
{
    $dir  = ss_attach_dir($db);
    $now  = (string)$db->query("SELECT NOW()")->fetchColumn();
    $name = 'ss' . $docId . '_' . preg_replace('/\D/', '', $now) . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
    $fs   = rtrim($dir, "/\\") . DIRECTORY_SEPARATOR . $name;
    if (@file_put_contents($fs, $bytes) === false) throw new RuntimeException('圖片寫入失敗：' . $fs);
    $st = $db->prepare("INSERT INTO ss_file (doc_id, ver_id, usage_kind, src, file_name, orig_name, file_size, uploaded_at, uploaded_by, src_tag)
                        VALUES (?,?,?, 'upload', ?,?,?, NOW(), 0, ?)");
    $st->execute([$docId, $verId ?: null, $usage, $name, $origName, strlen($bytes), $tag]);
    return (int)$db->lastInsertId();
}

/** 建一份文件＋第一個版次（已核准狀態不自動給，匯進來一律是草稿，由使用者確認後再送簽） */
function make_doc(PDO $db, array $doc, array $ver): array
{
    $st = $db->prepare("INSERT INTO ss_doc (kind, scope, machine_id, part_d_id, part_no_text, title, proc_name,
                            created_at, created_by, created_by_name, src_tag)
                        VALUES (?,?,?,?,?,?,?, NOW(), 0, ?, ?)");
    $st->execute([$doc['kind'], $doc['scope'], $doc['machine_id'] ?: null, $doc['part_d_id'] ?: null,
                  $doc['part_no_text'] ?: null, $doc['title'], $doc['proc_name'] ?: null,
                  '匯入', $doc['src_tag']]);
    $docId = (int)$db->lastInsertId();

    $cols = ['doc_id', 'ver_no', 'form_date', 'rev_note', 'status', 'created_by'];
    $ph   = ['?', '?', '?', '?', '?', '?'];
    $vals = [$docId, $ver['ver_no'] ?: '01', $ver['form_date'] ?: null, $ver['rev_note'] ?: null, 'draft', 0];
    foreach (ss_ver_fields() as $f) {
        if (in_array($f, ['ver_no', 'form_date', 'rev_note'], true)) continue;
        if (!array_key_exists($f, $ver)) continue;
        $cols[] = $f; $ph[] = '?'; $vals[] = ($ver[$f] === '' ? null : $ver[$f]);
    }
    $cols[] = 'created_at'; $ph[] = 'NOW()';
    $sql = "INSERT INTO ss_ver (" . implode(',', $cols) . ") VALUES (" . implode(',', $ph) . ")";
    $db->prepare($sql)->execute($vals);
    $verId = (int)$db->lastInsertId();
    $db->prepare("UPDATE ss_doc SET cur_ver_id=? WHERE doc_id=?")->execute([$verId, $docId]);
    return [$docId, $verId];
}

/* ══════════════ ① 設備操作說明書 3-TD-02-01（docx）══════════════ */

/** 由機器編號／名稱找 machine_list；先比 asset_no，再比 field_no、machine_model */
function find_machine(PDO $db, string $assetNo, string $name, string $model): ?array
{
    foreach ([['asset_no', $assetNo], ['field_no', $assetNo], ['field_no', $model],
              ['machine_model', $model], ['machine', $name]] as [$col, $val]) {
        $val = trim($val);
        if ($val === '') continue;
        $st = $db->prepare("SELECT machine_id, machine, asset_no, field_no FROM machine_list WHERE `$col`=? LIMIT 1");
        $st->execute([$val]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if ($r) return $r;
    }
    return null;
}

function import_equip(PDO $db, string $file, bool $run, array &$REPORT): void
{
    $tag = 'equip:' . basename($file);
    if (already($db, $tag)) { $REPORT['skip'][] = $tag . '（已匯入）'; return; }

    $tables = dx_tables($file);
    $kv = [];      // 標籤 => 值
    $hist = [];    // 修訂履歷列
    foreach ($tables as $rows) {
        foreach ($rows as $cells) {
            $c = array_values(array_filter(array_map('trim', $cells), fn($x) => $x !== ''));
            // 版次履歷是 8 欄的那張表（版次/年/月/日/制修訂事項/制修訂/審查/核准）
            if (count($cells) >= 8 && preg_match('/^\d+$/', trim($cells[0] ?? ''))) {
                $hist[] = ['ver' => trim($cells[0]), 'y' => trim($cells[1] ?? ''), 'm' => trim($cells[2] ?? ''),
                           'd' => trim($cells[3] ?? ''), 'note' => trim($cells[4] ?? ''), 'by' => trim($cells[5] ?? '')];
                continue;
            }
            for ($i = 0; $i + 1 < count($cells); $i += 2) {
                $k = trim($cells[$i]); $v = trim($cells[$i + 1]);
                if ($k !== '' && $v !== '' && mb_strlen($k) <= 12) $kv[$k] = $v;
            }
        }
    }
    $assetNo = $kv['機器編號'] ?? '';
    $mName   = $kv['機器名稱'] ?? '';
    $mMaker  = $kv['機器製造商'] ?? '';
    $mSpec   = $kv['型式規格'] ?? '';
    $mRange  = $kv['加工適用範圍'] ?? '';
    $op      = $kv['操作方法'] ?? '';
    $caut    = $kv['使用注意事項'] ?? '';
    $maint   = $kv['保養維修要點'] ?? '';

    $m = find_machine($db, $assetNo, $mName, $mSpec);
    if (!$m) {
        $REPORT['skip'][] = $tag . '：機器編號「' . ($assetNo ?: '未填') . '」在機台主檔找不到，未建立';
        return;
    }
    // 同一台機器可能有好幾份說明書（臥式B-002 與 臥式工件偏擺確認 都是 EG-002），
    // 用檔名去掉制式前綴後的那一段當「主題」才分得出來，否則清單上會出現兩列一模一樣的
    $subject = trim(preg_replace('~^3-TD-02-01-?設備操作說明書\s*\(SOP\)\s*~u', '', pathinfo($file, PATHINFO_FILENAME)));

    $h = $hist ? $hist[0] : [];
    $formDate = '';
    if (!empty($h['y'])) {
        $y = (int)$h['y'];
        if ($y < 1911) $y += 1911;                        // docx 上寫的是民國年（112/9/1）
        $formDate = sprintf('%04d-%02d-%02d', $y, max(1, (int)($h['m'] ?? 1)), max(1, (int)($h['d'] ?? 1)));
    }
    $REPORT['doc'][] = sprintf('設備操作說明書  %s（%s）機台 %s  版次 %s  %s',
        $mName ?: $m['machine'], basename($file), $m['asset_no'] ?: $m['field_no'],
        $h['ver'] ?? '0', $formDate ?: '無日期');
    if (!$run) return;

    [$docId, $verId] = make_doc($db, [
        'kind' => 'equip', 'scope' => 'machine', 'machine_id' => (int)$m['machine_id'],
        'part_d_id' => 0, 'part_no_text' => '', 'title' => $mName ?: (string)$m['machine'],
        'proc_name' => $subject, 'src_tag' => $tag,
    ], [
        'ver_no' => (string)($h['ver'] ?? '0'), 'form_date' => $formDate,
        'rev_note' => (string)($h['note'] ?? '初訂'),
        'm_maker' => $mMaker, 'm_name' => $mName, 'm_spec' => $mSpec, 'm_range' => $mRange,
        'op_method' => $op, 'cautions' => $caut, 'maintain' => $maint,
    ]);
}

/* ══════════════ ② 製造製程說明書 3-TD-02-02（xlsx）══════════════ */

function import_process(PDO $db, string $file, bool $run, array &$REPORT): void
{
    $tag = 'process:' . basename($file);
    if (already($db, $tag)) { $REPORT['skip'][] = $tag . '（已匯入）'; return; }

    $c = xl_cells($file);
    $g = fn($ref) => trim((string)($c[$ref] ?? ''));
    $title = $g('C4');
    if ($title === '') { $REPORT['skip'][] = $tag . '：讀不到製程名稱，未建立'; return; }
    $useEquip = $g('C5');
    $estHours = $g('C6') ?: $g('B6');
    $verNo    = $g('G6') ?: '1';
    $formDate = xl_date($g('N4'));

    // 步驟：A 欄是項次、B 欄是參考圖示的說明、H 欄操作步驟、N 欄說明；
    // 同一個項次會跨好幾列，H/N 欄要依「下一個項次開始之前」整段接起來
    $starts = [];
    foreach ($c as $ref => $v) {
        if (preg_match('/^A(\d+)$/', $ref, $m) && preg_match('/^\d+$/', $v) && (int)$m[1] >= 8) {
            $starts[(int)$v] = (int)$m[1];
        }
    }
    ksort($starts);
    $rowsIdx = array_values($starts);
    $steps = [];
    foreach ($rowsIdx as $i => $from) {
        $to = $rowsIdx[$i + 1] ?? ($from + 40);
        $name = $g('B' . $from);
        $txt = []; $note = [];
        for ($r = $from; $r < $to; $r++) {
            $h = $g('H' . $r); $n = $g('N' . $r);
            if ($h !== '') $txt[] = $h;
            if ($n !== '') $note[] = $n;
        }
        // 說明欄在 Excel 裡是硬換行切成好幾列（「防止工件受傷，確」＋「保產品精度。」），接回去
        $steps[] = ['from' => $from, 'to' => $to, 'name' => $name,
                    'text' => implode("\n", $txt), 'note' => implode('', $note)];
    }
    if (!$steps) { $REPORT['skip'][] = $tag . '：讀不到操作步驟，未建立'; return; }

    $imgs = xl_images($file);
    $REPORT['doc'][] = sprintf('製造製程說明書  %s  設備 %s  步驟 %d 列  圖 %d 張  版次 %s  %s',
        $title, $useEquip ?: '—', count($steps), count($imgs), $verNo, $formDate ?: '無日期');
    if (!$run) return;

    [$docId, $verId] = make_doc($db, [
        'kind' => 'process', 'scope' => 'general', 'machine_id' => 0, 'part_d_id' => 0,
        'part_no_text' => '', 'title' => $title, 'proc_name' => '', 'src_tag' => $tag,
    ], [
        'ver_no' => $verNo, 'form_date' => $formDate, 'rev_note' => '紙本匯入',
        'use_equip' => $useEquip, 'est_hours' => $estHours,
    ]);

    // 圖片對回步驟：錨點列號（0起算）＋1 之後落在哪個步驟的列範圍內就算那一步的
    $usedFirst = [];
    foreach ($imgs as $k => $im) {
        $row = $im['row'] + 1;
        $si = null;
        foreach ($steps as $i => $s) if ($row >= $s['from'] && $row < $s['to']) { $si = $i; break; }
        $fid = put_file($db, $docId, $verId, 'step', $im['bytes'], $im['ext'] ?: 'png',
                        basename($file) . '#img' . ($k + 1) . '.' . ($im['ext'] ?: 'png'), $tag);
        if ($si !== null && !isset($usedFirst[$si])) $usedFirst[$si] = $fid;   // 一步只掛一張，其餘仍存成附件不丟掉
    }
    $seq = 0;
    foreach ($steps as $i => $s) {
        $seq++;
        $db->prepare("INSERT INTO ss_step (ver_id, seq, step_name, img_file_id, step_text, note) VALUES (?,?,?,?,?,?)")
           ->execute([$verId, $seq, $s['name'] ?: null, $usedFirst[$i] ?? null,
                      $s['text'] ?: null, $s['note'] ?: null]);
    }
}

/* ══════════════ ③ 標準檢驗指導書 2-QA-02-01（xlsx）══════════════ */

/**
 * 解析一份 SIP xlsx，只解析不寫入（同一個料號在不同資料夾其實是不同版次，
 * 要先全部解析完、依日期排好，才知道誰是第一版誰是後來的改版）。
 * @return array|null
 */
function parse_sip(PDO $db, string $file, array &$REPORT): ?array
{
    $tag = 'sip:' . basename(dirname($file)) . '/' . basename($file);
    $c = xl_cells($file);
    $g = fn($ref) => trim((string)($c[$ref] ?? ''));
    /** 表頭：標籤在某一格，值就在它正下方那一格 */
    $below = function (string $label) use ($c, $g) {
        $p = find_label($c, $label);
        return $p ? $g($p[0] . ($p[1] + 1)) : '';
    };
    $partNo   = $below('產品料號');
    $cust     = $below('客戶名稱');
    $proc     = $below('工程名稱');
    $verNo    = $below('版次') ?: '01';
    $formDate = xl_date($below('製表日期'));
    $orderNo  = $below('製令單號');
    $qty      = $below('數量');

    // 檢驗項目：先找到「管理重點」那一列，同一列右邊各欄就是品質特性／擔當者／檢驗方法…
    $hdr = find_label($c, '管理重點');
    if (!$hdr) { $REPORT['skip'][] = $tag . '：找不到「管理重點」表頭，未建立'; return null; }
    [$cCtrl, $rHdr] = $hdr;
    $colOf = function (string $label) use ($c, $rHdr) {
        $p = find_label($c, $label);
        return ($p && $p[1] === $rHdr) ? $p[0] : '';
    };
    $cQ    = $colOf('品質特性');
    $cOwn  = $colOf('擔當者');
    $cMth  = $colOf('檢驗方法');
    $cTool = $colOf('檢具編號');
    $cFreq = $colOf('檢驗頻率');
    $cNote = $colOf('備註');

    $items = [];
    for ($r = $rHdr + 1; $r <= $rHdr + 30; $r++) {
        $j = $g($cCtrl . $r);
        if ($j === '' || mb_strpos($j, '修改記錄') === 0) continue;
        $l = $cQ !== '' ? $g($cQ . $r) : '';
        $it = ['ctrl_point' => $j, 'q_char' => '', 'up' => '', 'lo' => '',
               'owner' => $cOwn ? $g($cOwn . $r) : '', 'method' => $cMth ? $g($cMth . $r) : '',
               'tool' => $cTool ? $g($cTool . $r) : '', 'freq' => $cFreq ? $g($cFreq . $r) : '',
               'note' => $cNote ? $g($cNote . $r) : ''];
        if ($l === '上限') {
            // 上下限的「值」不在標籤那一格，而在它右邊幾欄（範本是 L 標籤、N 值）：
            // 從品質特性欄往右掃到擔當者欄之前，第一個有值的就是它
            $from = col_idx($cQ) + 1;
            $to   = $cOwn !== '' ? col_idx($cOwn) : $from + 4;
            $pick = function (int $row) use ($g, $from, $to) {
                for ($i = $from; $i < $to; $i++) { $v = $g(idx_col($i) . $row); if ($v !== '') return $v; }
                return '';
            };
            $it['up'] = $pick($r);
            $it['lo'] = ($g($cQ . ($r + 1)) === '下限') ? $pick($r + 1) : '';
        } else {
            $it['q_char'] = $l;
        }
        // 範本上沒填的「尺寸：」空列不要匯進來，否則每張單都多出 6 列空白
        if (rtrim($j, '： :') === '尺寸' && $it['up'] === '' && $it['lo'] === '') continue;
        $items[] = $it;
    }
    if (!$items) { $REPORT['skip'][] = $tag . '：讀不到檢驗項目，未建立'; return null; }

    // 注意事項：標題那一格底下同一欄的編號條列
    $notice = [];
    $np = find_label($c, '注意事項');
    if ($np) {
        for ($r = $np[1] + 1; $r <= $np[1] + 16; $r++) {
            $a = $g($np[0] . $r);
            if ($a !== '' && preg_match('/^\d+\./', $a)) $notice[] = $a;
        }
    }

    $partDId = 0; $partText = $partNo;
    if ($partNo !== '') {
        $st = $db->prepare("SELECT d_id FROM d_setting WHERE D_Setting_Id=? ORDER BY d_id LIMIT 1");
        $st->execute([$partNo]);
        $partDId = (int)$st->fetchColumn();
        if (!$partDId) $REPORT['warn'][] = $tag . '：料號「' . $partNo . '」在料號主檔找不到，改建成通用 SIP';
    }
    $imgs = xl_images($file);
    return ['tag' => $tag, 'file' => $file, 'part_no' => $partNo, 'part_d_id' => $partDId,
            'cust' => $cust, 'proc' => $proc, 'ver_no' => $verNo, 'form_date' => $formDate,
            'order_no' => $orderNo, 'qty' => $qty, 'items' => $items, 'notice' => $notice, 'imgs' => $imgs];
}

/**
 * 把解析好的 SIP 依「料號＋製程」分組寫入：一組＝一份文件，組內每一個檔案＝一個版次，
 * 依表單日期由舊到新。同一個料號在不同資料夾其實就是不同時期的版本
 * （實測 HYB-250-1-1 有 2023-10-13／2023-12-22／2024-02-06 三份），
 * 各建一份文件會讓現場看到三份「一樣的 SIP」而不知道該用哪一份。
 */
function write_sip_group(PDO $db, array $recs, bool $run, array &$REPORT): void
{
    usort($recs, fn($a, $b) => [$a['form_date'] ?: '9999', $a['ver_no']] <=> [$b['form_date'] ?: '9999', $b['ver_no']]);
    // 同一份檔案被放在兩個資料夾（根目錄與「標準檢驗指導書 SIP」子資料夾）是常態，
    // 版次與日期與項目數都一樣就是同一份，只留一個，否則版次歷程會出現兩列一模一樣的
    $seen = []; $uniq = [];
    foreach ($recs as $r) {
        $k = $r['ver_no'] . '|' . $r['form_date'] . '|' . count($r['items']);
        if (isset($seen[$k])) continue;
        $seen[$k] = 1; $uniq[] = $r;
    }
    $recs = $uniq;
    $head = $recs[0];
    $label = ($head['part_no'] ?: '（無料號）') . ($head['proc'] !== '' ? '　' . $head['proc'] : '');

    if (already($db, 'sipgrp:' . $label)) { $REPORT['skip'][] = 'SIP ' . $label . '（已匯入）'; return; }
    $REPORT['doc'][] = sprintf('標準檢驗指導書  %s  客戶 %s  版次 %d 個（%s）%s',
        $label, $head['cust'] ?: '—', count($recs),
        implode('→', array_map(fn($r) => ($r['ver_no'] . '@' . ($r['form_date'] ?: '無日期')), $recs)),
        $head['part_d_id'] ? '' : '  ※未綁料號');
    if (!$run) return;

    $docId = 0; $lastVer = 0;
    foreach ($recs as $i => $r) {
        if ($i === 0) {
            [$docId, $verId] = make_doc($db, [
                'kind' => 'sip', 'scope' => $r['part_d_id'] ? 'part' : 'general', 'machine_id' => 0,
                'part_d_id' => $r['part_d_id'], 'part_no_text' => $r['part_no'],
                'title' => $r['part_no'] !== '' ? $r['part_no'] : '通用檢驗指導書',
                'proc_name' => $r['proc'], 'src_tag' => 'sipgrp:' . $label,
            ], [
                'ver_no' => $r['ver_no'], 'form_date' => $r['form_date'], 'rev_note' => '紙本匯入',
                'customer_name' => $r['cust'], 'order_no' => $r['order_no'], 'qty' => $r['qty'],
                'notice' => implode("\n", $r['notice']),
            ]);
        } else {
            $st = $db->prepare("INSERT INTO ss_ver (doc_id, ver_no, form_date, rev_note, status, customer_name,
                                    order_no, qty, notice, created_at, created_by)
                                VALUES (?,?,?,?, 'draft', ?,?,?,?, NOW(), 0)");
            $st->execute([$docId, $r['ver_no'], $r['form_date'] ?: null, '紙本匯入', $r['cust'] ?: null,
                          $r['order_no'] ?: null, $r['qty'] ?: null, implode("\n", $r['notice']) ?: null]);
            $verId = (int)$db->lastInsertId();
        }
        $lastVer = $verId;

        $seq = 0;
        foreach ($r['items'] as $it) {
            $seq++;
            $db->prepare("INSERT INTO ss_item (ver_id, seq, ctrl_point, q_char, up_limit, lo_limit, owner, method, tool_no, freq, note)
                          VALUES (?,?,?,?,?,?,?,?,?,?,?)")
               ->execute([$verId, $seq, $it['ctrl_point'], $it['q_char'] ?: null, $it['up'] ?: null, $it['lo'] ?: null,
                          $it['owner'] ?: null, $it['method'] ?: null, $it['tool'] ?: null,
                          $it['freq'] ?: null, $it['note'] ?: null]);
        }
        // 圖面：SIP 左邊那一大塊圖，第一張當這一版帶入的圖面
        $first = 0;
        foreach ($r['imgs'] as $k => $im) {
            $fid = put_file($db, $docId, $verId, 'draw', $im['bytes'], $im['ext'] ?: 'jpeg',
                            basename($r['file']) . '#img' . ($k + 1) . '.' . ($im['ext'] ?: 'jpeg'), $r['tag']);
            if (!$first) $first = $fid;
        }
        if ($first) $db->prepare("UPDATE ss_ver SET draw_file_id=? WHERE ver_id=?")->execute([$first, $verId]);
    }
    if ($lastVer) $db->prepare("UPDATE ss_doc SET cur_ver_id=? WHERE doc_id=?")->execute([$lastVer, $docId]);
}

/* ══════════════ ④ PDF／JPG 掃描檔 ══════════════ */

/**
 * 把一份掃描檔掛到「最像的那份文件」上。比對方式＝檔名裡出現的料號或機器編號。
 * 對不到就自己建一份 kind=process、scope=general 的文件（品保課那幾份量測儀器操作就是這種）。
 */
function import_scan(PDO $db, string $file, bool $run, array &$REPORT): void
{
    $base = basename($file);
    $tag  = 'scan:' . $base;
    $st = $db->prepare("SELECT file_id FROM ss_file WHERE src_tag=? LIMIT 1");
    $st->execute([$tag]);
    if ($st->fetchColumn()) { $REPORT['skip'][] = $tag . '（已匯入）'; return; }

    $stem = pathinfo($base, PATHINFO_FILENAME);
    $ext  = strtolower(pathinfo($base, PATHINFO_EXTENSION));
    // 檔名含料號／機器編號時掛到那份文件；比對用「文件的料號或機器編號有沒有出現在檔名裡」
    $st = $db->prepare("SELECT d.doc_id, d.cur_ver_id, d.part_no_text, m.asset_no, m.field_no
                        FROM ss_doc d LEFT JOIN machine_list m ON m.machine_id=d.machine_id
                        WHERE d.is_deleted=0");
    $st->execute();
    // **一律取「相符字串最長」的那一份**，不是第一個命中的：料號 DRW_AA96598_001 是
    // DRW_AA96598_001D 的前綴，先命中誰完全看查詢順序，會把 D 版的掃描檔掛到別份文件上。
    $hit = null; $hitLen = 0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $d) {
        foreach ([$d['part_no_text'], $d['asset_no'], $d['field_no']] as $key) {
            $key = trim((string)$key);
            if ($key === '' || mb_strlen($key) < 4) continue;
            if (mb_stripos($stem, $key) === false) continue;
            if (mb_strlen($key) > $hitLen) { $hit = $d; $hitLen = mb_strlen($key); }
        }
    }
    $bytes = @file_get_contents($file);
    if ($bytes === false) { $REPORT['skip'][] = $tag . '：讀不到檔案'; return; }

    if ($hit) {
        $REPORT['doc'][] = sprintf('掃描檔  %s → 掛到文件 #%d（%s）', $base, (int)$hit['doc_id'],
            (string)($hit['part_no_text'] ?: $hit['asset_no']));
        if (!$run) return;
        put_file($db, (int)$hit['doc_id'], (int)$hit['cur_ver_id'], 'scan', $bytes, $ext, $base, $tag);
        return;
    }
    $REPORT['doc'][] = sprintf('掃描檔  %s → 另建一份通用文件（內容為紙本掃描）', $base);
    if (!$run) return;
    [$docId, $verId] = make_doc($db, [
        'kind' => 'process', 'scope' => 'general', 'machine_id' => 0, 'part_d_id' => 0,
        'part_no_text' => '', 'title' => $stem, 'proc_name' => '', 'src_tag' => $tag,
    ], ['ver_no' => '01', 'form_date' => '', 'rev_note' => '紙本掃描匯入']);
    put_file($db, $docId, $verId, 'scan', $bytes, $ext, $base, $tag);
}

/* ══════════════════════ 主程式 ══════════════════════ */

if ($ROLLBACK) {
    $n = 0;
    foreach ($db->query("SELECT file_id, src, file_name FROM ss_file WHERE src_tag IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC) as $f) {
        if ((string)$f['src'] === 'upload') {
            $p = ss_file_path($db, $f);
            if ($p && is_file($p)) @unlink($p);
        }
        $n++;
    }
    $db->exec("DELETE FROM ss_file WHERE src_tag IS NOT NULL");
    $docs = $db->query("SELECT doc_id FROM ss_doc WHERE src_tag IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
    if ($docs) {
        $in = implode(',', array_map('intval', $docs));
        $vers = $db->query("SELECT ver_id FROM ss_ver WHERE doc_id IN ($in)")->fetchAll(PDO::FETCH_COLUMN);
        if ($vers) {
            $vin = implode(',', array_map('intval', $vers));
            $db->exec("DELETE FROM ss_step WHERE ver_id IN ($vin)");
            $db->exec("DELETE FROM ss_item WHERE ver_id IN ($vin)");
            $db->exec("DELETE FROM ss_sign WHERE ver_id IN ($vin)");
            $db->exec("DELETE FROM ss_ver WHERE doc_id IN ($in)");
        }
        $db->exec("DELETE FROM ss_doc WHERE doc_id IN ($in)");
    }
    echo "已還原：刪除文件 " . count($docs) . " 份、檔案 $n 個\n";
    exit;
}

if (!is_dir($SRC_DIR)) { fwrite(STDERR, "找不到來源資料夾：$SRC_DIR\n"); exit(1); }

/** 遞迴列出檔案，排除 Word 的 ~$ 暫存檔與 Thumbs.db */
function walk(string $dir): array
{
    $out = [];
    foreach (scandir($dir) ?: [] as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = $dir . '/' . $f;
        if (is_dir($p)) { $out = array_merge($out, walk($p)); continue; }
        if (strpos($f, '~$') === 0 || strcasecmp($f, 'Thumbs.db') === 0) continue;
        $out[] = $p;
    }
    return $out;
}

$files = walk($SRC_DIR);
$equip = $proc = $sip = $scan = [];
foreach ($files as $p) {
    $ext = strtolower(pathinfo($p, PATHINFO_EXTENSION));
    $rel = str_replace('\\', '/', substr($p, strlen($SRC_DIR) + 1));
    if ($ext === 'docx' && strpos($rel, '3-TD-02-01') === 0) { $equip[] = $p; continue; }
    if ($ext === 'xlsx' && strpos($rel, '3-TD-02-02') === 0) { $proc[] = $p; continue; }
    if ($ext === 'xlsx' && strpos($rel, '標準檢驗指導書') === 0) {
        if (strpos(basename($p), '2-QA-02-01') === 0) continue;   // 空白範本不匯
        $sip[] = $p; continue;
    }
    if (in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'], true)) { $scan[] = $p; continue; }
    // 不是那三種標準範本的 xlsx/docx（品保課那幾份量測儀器操作）解析不出欄位，
    // 一律當成紙本原檔掛上去，不要靜默丟掉
    if ($ext === 'xlsx' || $ext === 'docx') {
        $scan[] = $p;
        $REPORT['warn'][] = '非標準範本，以原檔附件方式匯入：' . $rel;
    }
}

echo $RUN ? "=== 實際寫入 ===\n" : "=== 試算（未寫入，加 --run 才會寫）===\n";
echo sprintf("來源：設備操作說明書 %d／製造製程說明書 %d／標準檢驗指導書 %d／掃描檔 %d\n\n",
    count($equip), count($proc), count($sip), count($scan));

if ($RUN) $db->beginTransaction();
try {
    foreach ($equip as $p) import_equip($db, $p, $RUN, $REPORT);
    foreach ($proc as $p)  import_process($db, $p, $RUN, $REPORT);
    // SIP 要先全部解析完才知道哪幾個檔其實是同一份文件的不同版次
    $groups = [];
    foreach ($sip as $p) {
        $r = parse_sip($db, $p, $REPORT);
        if (!$r) continue;
        $key = ($r['part_d_id'] ?: 'txt:' . $r['part_no']) . '|' . $r['proc'];
        $groups[$key][] = $r;
    }
    foreach ($groups as $recs) write_sip_group($db, $recs, $RUN, $REPORT);
    foreach ($scan as $p)  import_scan($db, $p, $RUN, $REPORT);   // 掃描檔要在文件都建好之後才掛得上
    if ($RUN) $db->commit();
} catch (Throwable $e) {
    if ($RUN && $db->inTransaction()) $db->rollBack();
    fwrite(STDERR, "失敗：" . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
}

foreach ($REPORT['doc'] as $l)  echo "  建立  $l\n";
foreach ($REPORT['warn'] as $l) echo "  注意  $l\n";
foreach ($REPORT['skip'] as $l) echo "  略過  $l\n";
echo sprintf("\n合計：建立 %d、注意 %d、略過 %d\n", count($REPORT['doc']), count($REPORT['warn']), count($REPORT['skip']));

