<?php
// src/common/dxf_text_lib.php
// ── DXF（AutoCAD 交換格式，ASCII）文字與基本幾何解析（唯一實作，禁止各頁自刻）──────
// 這是向量解析不是 OCR：TEXT/MTEXT 實體裡存的是精確字串，100% 準確，不會有 0/O、Q/G
// 混淆的問題；代價是只認得「圖面本身存的就是向量文字」這種檔案。
//
// 支援範圍：ASCII DXF（最常見的交換格式，副檔名 .dxf，group code/value 成對出現）。
// 不支援：DWG（AutoCAD 專屬二進位格式，沒有免費的本機解析方式，需另外轉存 DXF）；
//        舊式 POLYLINE+VERTEX 複合實體（現代 CAD 匯出多已改存 LWPOLYLINE，暫不處理）。

/** 偵測並轉成 UTF-8（部分舊版中文 CAD 匯出是 Big5/GBK，不轉會整段變亂碼） */
function eg_dxf_to_utf8(string $raw): string {
    if (@mb_check_encoding($raw, 'UTF-8')) return $raw;
    foreach (['BIG5', 'GBK'] as $enc) {
        $t = @iconv($enc, 'UTF-8//IGNORE', $raw);
        if ($t !== false && $t !== '') return $t;
    }
    return $raw;
}

/** 清掉 MTEXT 的格式控制碼（\P 換行、{\fArial|...;} 字型/顏色區塊等），只留純文字 */
function eg_dxf_strip_mtext(string $s): string {
    $s = preg_replace('/\\\\P/u', "\n", $s);
    $s = preg_replace('/\\\\[A-Za-z][^;]*;/u', '', $s); // \fArial|b0i0...; \C1; \H2.5x; 等
    $s = str_replace(['{', '}'], '', $s);
    $s = str_replace('\\~', ' ', $s);
    $s = str_replace('\\\\', '\\', $s);
    return trim($s);
}

/**
 * 解析 DXF 檔，回傳文字清單＋基本線框幾何（供畫面畫出簡易線稿，文字可逐一點選標記）。
 */
function eg_dxf_parse_file(string $absPath): array {
    if (!is_file($absPath)) return ['ok' => false, 'message' => '找不到檔案'];
    $raw = file_get_contents($absPath);
    if ($raw === false) return ['ok' => false, 'message' => '讀取檔案失敗'];
    $raw = eg_dxf_to_utf8($raw);
    $raw = preg_replace('/\r\n?/', "\n", $raw);
    $lines = explode("\n", $raw);
    $n = count($lines);

    // 轉成 (group_code, value) 配對（DXF ASCII 固定兩行一組：代碼行、值行）
    $pairs = [];
    for ($i = 0; $i + 1 < $n; $i += 2) {
        $code = trim($lines[$i]);
        if ($code === '' || !is_numeric($code)) { $i -= 1; continue; } // 容錯：遇到空行跳過、不要整體錯位
        $pairs[] = [(int)$code, rtrim($lines[$i + 1], "\r")];
    }

    // 找 ENTITIES 區段（0/SECTION, 2/ENTITIES … 0/ENDSEC）
    $entStart = -1; $entEnd = -1;
    $pc = count($pairs);
    for ($i = 1; $i < $pc; $i++) {
        if ($entStart < 0 && $pairs[$i][0] === 2 && trim($pairs[$i][1]) === 'ENTITIES'
            && $pairs[$i - 1][0] === 0 && trim($pairs[$i - 1][1]) === 'SECTION') {
            $entStart = $i + 1;
            continue;
        }
        if ($entStart >= 0 && $entEnd < 0 && $pairs[$i][0] === 0 && trim($pairs[$i][1]) === 'ENDSEC') {
            $entEnd = $i;
            break;
        }
    }
    if ($entStart < 0) return ['ok' => false, 'message' => '找不到 ENTITIES 區段，可能不是標準 ASCII DXF 檔（DWG 二進位格式不支援，請在 CAD 軟體另存成 DXF）'];
    if ($entEnd < 0) $entEnd = $pc;

    $texts = []; $segs = []; $circles = []; $arcs = []; $plines = [];
    $cur = null; $curType = null;

    $flush = function () use (&$cur, &$curType, &$texts, &$segs, &$circles, &$arcs, &$plines) {
        if ($curType === null || $cur === null) return;
        switch ($curType) {
            case 'TEXT':
                $t = trim((string)($cur[1] ?? ''));
                if ($t !== '') $texts[] = [
                    'text' => $t, 'x' => $cur[10] ?? 0.0, 'y' => $cur[20] ?? 0.0,
                    'h' => $cur[40] ?? 2.5, 'rot' => $cur[50] ?? 0.0, 'layer' => (string)($cur[8] ?? ''),
                ];
                break;
            case 'MTEXT':
                $t = eg_dxf_strip_mtext(($cur['_m3'] ?? '') . ($cur[1] ?? ''));
                if ($t !== '') $texts[] = [
                    'text' => $t, 'x' => $cur[10] ?? 0.0, 'y' => $cur[20] ?? 0.0,
                    'h' => $cur[40] ?? 2.5, 'rot' => $cur[50] ?? 0.0, 'layer' => (string)($cur[8] ?? ''),
                ];
                break;
            case 'LINE':
                $segs[] = ['x1' => $cur[10] ?? 0.0, 'y1' => $cur[20] ?? 0.0, 'x2' => $cur[11] ?? 0.0, 'y2' => $cur[21] ?? 0.0];
                break;
            case 'CIRCLE':
                $circles[] = ['cx' => $cur[10] ?? 0.0, 'cy' => $cur[20] ?? 0.0, 'r' => $cur[40] ?? 0.0];
                break;
            case 'ARC':
                $arcs[] = ['cx' => $cur[10] ?? 0.0, 'cy' => $cur[20] ?? 0.0, 'r' => $cur[40] ?? 0.0, 'a1' => $cur[50] ?? 0.0, 'a2' => $cur[51] ?? 0.0];
                break;
            case 'LWPOLYLINE':
                if (!empty($cur['_pts'])) {
                    $closed = (((int)($cur[70] ?? 0)) & 1) === 1;
                    $plines[] = ['pts' => $cur['_pts'], 'closed' => $closed];
                }
                break;
        }
    };

    for ($i = $entStart; $i < $entEnd; $i++) {
        [$code, $val] = $pairs[$i];
        if ($code === 0) {
            $flush();
            $curType = trim($val);
            $cur = [];
            continue;
        }
        if ($cur === null) continue;
        if ($curType === 'MTEXT' && $code === 3) { $cur['_m3'] = ($cur['_m3'] ?? '') . $val; continue; }
        if ($curType === 'LWPOLYLINE' && $code === 10) {
            $cur['_pts'] = $cur['_pts'] ?? [];
            $cur['_pts'][] = [(float)$val, 0.0];
            continue;
        }
        if ($curType === 'LWPOLYLINE' && $code === 20 && !empty($cur['_pts'])) {
            $cur['_pts'][count($cur['_pts']) - 1][1] = (float)$val;
            continue;
        }
        if (in_array($code, [10, 11, 20, 21, 40, 50, 51], true)) {
            $cur[$code] = (float)$val;
        } elseif ($code === 1 || $code === 8) {
            $cur[$code] = $val;
        } elseif ($code === 70) {
            $cur[$code] = (int)$val;
        }
    }
    $flush();

    // 計算整張圖的座標範圍（給前端畫 SVG 用的 viewBox）
    $minx = $miny = PHP_FLOAT_MAX; $maxx = $maxy = -PHP_FLOAT_MAX;
    $consider = function ($x, $y) use (&$minx, &$miny, &$maxx, &$maxy) {
        $minx = min($minx, $x); $maxx = max($maxx, $x);
        $miny = min($miny, $y); $maxy = max($maxy, $y);
    };
    foreach ($texts as $t) $consider($t['x'], $t['y']);
    foreach ($segs as $s) { $consider($s['x1'], $s['y1']); $consider($s['x2'], $s['y2']); }
    foreach ($circles as $c) { $consider($c['cx'] - $c['r'], $c['cy'] - $c['r']); $consider($c['cx'] + $c['r'], $c['cy'] + $c['r']); }
    foreach ($arcs as $a) { $consider($a['cx'] - $a['r'], $a['cy'] - $a['r']); $consider($a['cx'] + $a['r'], $a['cy'] + $a['r']); }
    foreach ($plines as $p) foreach ($p['pts'] as $pt) $consider($pt[0], $pt[1]);
    if ($minx > $maxx) { $minx = $miny = 0; $maxx = $maxy = 100; }

    return [
        'ok' => true,
        'texts' => $texts, 'lines' => $segs, 'circles' => $circles, 'arcs' => $arcs, 'polylines' => $plines,
        'bbox' => ['minx' => $minx, 'miny' => $miny, 'maxx' => $maxx, 'maxy' => $maxy],
        'counts' => ['texts' => count($texts), 'lines' => count($segs), 'circles' => count($circles), 'arcs' => count($arcs), 'polylines' => count($plines)],
    ];
}
