<?php
/**
 * Word 流程圖匯入（AS 文件線上版）
 * ---------------------------------------------------------------------------
 * 把 Word 裡用「圖案＋文字方塊＋連接線」畫出來的流程圖，轉成本站流程圖編輯器
 *（views/ADM/_flow_editor_ui.php，Fabric）看得懂的物件，於是匯入之後每一個方塊
 * 都還能拖、雙擊還能改字，不是一張貼死的圖。
 *
 * 為什麼要另外讀 .fodt（LibreOffice 的 XML 格式）：
 *   LibreOffice 轉 HTML 時會把每個圖案各自輸出成一張 GIF 碎片、文字方塊輸出成
 *   浮動 <span>，位置與連線全部散掉（使用者 2026-10-15：「後面的流程圖整個亂七八糟」）。
 *   .fodt 裡則完整保留每個圖案的座標、尺寸、形狀種類與文字，拼得回原樣。
 *
 * 對應鍵：draw:name。HTML 的 <img name="…"> 與 <span id="…"> 用的就是同一個值，
 *   所以能精準知道「HTML 的這塊碎片屬於哪一張流程圖」，換掉時不會波及別的內容。
 *   群組（draw:g）在 HTML 只有一張碎片，所以群組內的子圖形一律掛在群組名下。
 *
 * 座標：.fodt 一律是 cm，而且同一張圖裡的元素共用同一個基準，
 *   所以整群平移到原點就還原得出相對位置。
 */

require_once __DIR__ . '/as_doc_content_lib.php';

/** cm → px（96dpi：1cm = 37.795px） */
define('ADFI_PX_PER_CM', 37.795275591);
/** 圖面四周留白（px） */
define('ADFI_PAD', 12);
/** 一張流程圖最少要有幾個元素才值得轉（太少多半是裝飾線） */
define('ADFI_MIN_ITEMS', 3);
/** 畫 PNG 用的中文字型（Windows 內建標楷體；沒有就退回微軟正黑體） */
define('ADFI_FONTS', ['C:/Windows/Fonts/kaiu.ttf', 'C:/Windows/Fonts/msjh.ttc', 'C:/Windows/Fonts/mingliu.ttc']);

/** "6.424cm" → 6.424（空字串回 null，呼叫端才分得出「沒有這個屬性」與「0」） */
function adfi_cm(?string $v): ?float
{
    $v = trim((string)$v);
    if ($v === '') return null;
    if (!preg_match('/^(-?[\d.]+)\s*(cm|mm|in|pt|px)?$/i', $v, $m)) return null;
    $n = (float)$m[1];
    switch (strtolower($m[2] ?? 'cm')) {
        case 'mm': return $n / 10;
        case 'in': return $n * 2.54;
        case 'pt': return $n * 2.54 / 72;
        case 'px': return $n * 2.54 / 96;
        default:   return $n;
    }
}

/**
 * 解析 .fodt 的繪圖元素。
 * @return array{byName:array<string,array>, order:string[]} byName：draw:name → 元素陣列
 */
function adfi_parse(string $fodtPath): array
{
    if (!is_file($fodtPath)) return ['byName' => [], 'order' => []];
    @ini_set('memory_limit', '768M');   // 含內嵌圖片的 .fodt 可達數十 MB，DOM 展開後更大
    $xml = (string)@file_get_contents($fodtPath);
    if ($xml === '') return ['byName' => [], 'order' => []];

    $doc = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $ok = $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOENT);
    libxml_clear_errors(); libxml_use_internal_errors($prev);
    if (!$ok) return ['byName' => [], 'order' => []];

    $xp = new DOMXPath($doc);
    $xp->registerNamespace('draw',  'urn:oasis:names:tc:opendocument:xmlns:drawing:1.0');
    $xp->registerNamespace('svg',   'urn:oasis:names:tc:opendocument:xmlns:svg-compatible:1.0');
    $xp->registerNamespace('style', 'urn:oasis:names:tc:opendocument:xmlns:style:1.0');
    $xp->registerNamespace('fo',    'urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0');
    $xp->registerNamespace('text',  'urn:oasis:names:tc:opendocument:xmlns:text:1.0');

    // 圖形樣式：要知道「線有沒有箭頭」「方框有沒有框線」
    $styles = [];
    foreach ($xp->query('//style:style[@style:family="graphic"]') as $st) {
        $gp = null;
        foreach ($st->childNodes as $c) {
            if ($c->nodeType === XML_ELEMENT_NODE && $c->nodeName === 'style:graphic-properties') { $gp = $c; break; }
        }
        $styles[$st->getAttribute('style:name')] = [
            'arrow'  => $gp && ($gp->getAttribute('draw:marker-end') !== '' || $gp->getAttribute('draw:marker-start') !== ''),
            'border' => $gp ? trim((string)$gp->getAttribute('fo:border')) : '',
            'stroke' => $gp ? trim((string)$gp->getAttribute('draw:stroke')) : '',
        ];
    }

    $byName = [];
    $order  = [];
    // 只走頂層的繪圖元素（draw:g 自己收子元素），避免同一個圖形被收兩次
    $q = $xp->query('//draw:custom-shape[not(ancestor::draw:g)] | //draw:line[not(ancestor::draw:g)]'
                  . ' | //draw:frame[not(ancestor::draw:g)] | //draw:rect[not(ancestor::draw:g)]'
                  . ' | //draw:g');
    foreach ($q as $e) {
        $name = (string)$e->getAttribute('draw:name');
        if ($name === '') continue;
        $items = ($e->nodeName === 'draw:g')
            ? adfi_group_items($e, $styles)
            : array_filter([adfi_one($e, $styles)]);
        if (!$items) continue;
        if (!isset($byName[$name])) { $byName[$name] = []; $order[] = $name; }
        foreach ($items as $it) $byName[$name][] = $it;
    }
    return ['byName' => $byName, 'order' => $order];
}

/** draw:g 展平成子元素（群組內的座標一樣是絕對值，不必再換算） */
function adfi_group_items(DOMElement $g, array $styles): array
{
    $out = [];
    foreach ($g->getElementsByTagName('*') as $c) {
        if (!in_array($c->nodeName, ['draw:custom-shape', 'draw:line', 'draw:frame', 'draw:rect'], true)) continue;
        $one = adfi_one($c, $styles);
        if ($one) $out[] = $one;
    }
    return $out;
}

/** 單一繪圖元素 → 內部表示 */
function adfi_one(DOMElement $e, array $styles): ?array
{
    $st  = $styles[$e->getAttribute('draw:style-name')] ?? [];
    $txt = adfi_text($e);

    if ($e->nodeName === 'draw:line') {
        $x1 = adfi_cm($e->getAttribute('svg:x1')); $y1 = adfi_cm($e->getAttribute('svg:y1'));
        $x2 = adfi_cm($e->getAttribute('svg:x2')); $y2 = adfi_cm($e->getAttribute('svg:y2'));
        if ($x1 === null || $y1 === null || $x2 === null || $y2 === null) return null;
        return ['t' => 'conn', 'kind' => !empty($st['arrow']) ? 'arrow' : 'line',
                'x1' => $x1, 'y1' => $y1, 'x2' => $x2, 'y2' => $y2];
    }

    $x = adfi_cm($e->getAttribute('svg:x')); $y = adfi_cm($e->getAttribute('svg:y'));
    $w = adfi_cm($e->getAttribute('svg:width')); $h = adfi_cm($e->getAttribute('svg:height'));
    if ($x === null || $y === null) return null;
    if ($w === null || $w <= 0) $w = 2.0;
    if ($h === null || $h <= 0) $h = 0.8;

    if ($e->nodeName === 'draw:custom-shape') {
        $geo = '';
        foreach ($e->childNodes as $c) {
            if ($c->nodeType === XML_ELEMENT_NODE && $c->nodeName === 'draw:enhanced-geometry') {
                $geo = (string)$c->getAttribute('draw:type'); break;
            }
        }
        return ['t' => 'shape', 'kind' => adfi_kind_of($geo), 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h, 'text' => $txt];
    }

    // draw:frame：有框線＝流程方塊，沒框線＝純文字標註（NO／YES 這種）
    $hasBorder = ($st['border'] ?? '') !== '' && stripos($st['border'], 'none') === false;
    if (!$hasBorder && $txt === '') return null;               // 既沒框又沒字＝裝飾，不收
    return ['t' => $hasBorder ? 'shape' : 'text', 'kind' => 'proc',
            'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h, 'text' => $txt];
}

/** Word 的圖案類型 → 本站流程圖編輯器的形狀 */
function adfi_kind_of(string $geo): string
{
    $g = strtolower($geo);
    if (strpos($g, 'decision') !== false || strpos($g, 'diamond') !== false || strpos($g, 'rhombus') !== false) return 'judge';
    if (strpos($g, 'terminator') !== false || strpos($g, 'round') !== false || strpos($g, 'ellipse') !== false) return 'term';
    if (strpos($g, 'document') !== false || strpos($g, 'data') !== false || strpos($g, 'parallelogram') !== false) return 'doc';
    return 'proc';
}

/** 取圖形內的文字（多行用換行接起來，不要把字黏成一串） */
function adfi_text(DOMElement $e): string
{
    $lines = [];
    foreach ($e->getElementsByTagName('*') as $c) {
        if ($c->nodeName !== 'text:p') continue;
        $t = trim((string)preg_replace('/[\s\x{00A0}\x{3000}]+/u', ' ', adfi_plain($c)));
        if ($t !== '') $lines[] = $t;
    }
    return implode("\n", $lines);
}

/** 取節點內的純文字，跳過內嵌圖片的 base64（textContent 會把它整包吃進來，單次可達數十 MB 而爆記憶體） */
function adfi_plain(DOMNode $n): string
{
    $s = '';
    foreach ($n->childNodes as $c) {
        if ($c->nodeType === XML_TEXT_NODE || $c->nodeType === XML_CDATA_SECTION_NODE) $s .= $c->nodeValue;
        elseif ($c->nodeType === XML_ELEMENT_NODE && $c->nodeName !== 'office:binary-data') $s .= adfi_plain($c);
    }
    return $s;
}

/**
 * 找出 HTML 裡每一塊流程圖碎片的範圍。
 * 圖案是 `<img name="…">`，文字方塊是 `<span id="…">…</span>`（裡面還會有 <p>，所以要配對）。
 * @return array<int,array{0:int,1:int,2:string}> [起, 迄, draw:name]，依出現順序
 */
function adfi_frag_ranges(string $html, array $byName): array
{
    $out = [];
    if (preg_match_all('#<img\b[^>]*\bname="([^"]*)"[^>]*>#i', $html, $ms, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
        foreach ($ms as $m) {
            if (!isset($byName[$m[1][0]])) continue;
            $out[] = [(int)$m[0][1], (int)$m[0][1] + strlen($m[0][0]), $m[1][0]];
        }
    }
    if (preg_match_all('#<span\b[^>]*\bid="([^"]*)"[^>]*>#i', $html, $ms, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
        foreach ($ms as $m) {
            if (!isset($byName[$m[1][0]])) continue;
            $s = (int)$m[0][1];
            $j = $s + strlen($m[0][0]);
            $depth = 1;
            while ($depth > 0) {
                if (!preg_match('#<(/?)span\b[^>]*>#i', $html, $m2, PREG_OFFSET_CAPTURE, $j)) { $j = strlen($html); break; }
                $depth += ($m2[1][0] === '/') ? -1 : 1;
                $j = (int)$m2[0][1] + strlen($m2[0][0]);
            }
            $out[] = [$s, $j, $m[1][0]];
        }
    }
    usort($out, function ($a, $b) { return $a[0] <=> $b[0]; });
    return $out;
}

/** 包住某個位置的最外層 <p>…</p>（LibreOffice 會把 <p> 巢在 <span> 裡，所以要自己數深度） */
function adfi_enclosing_p(string $html, int $pos): ?array
{
    $before = substr($html, 0, $pos);
    $s = -1;
    // 往前找第一個「還沒被收掉」的 <p
    $off = 0; $stack = [];
    while (preg_match('#<(/?)p\b[^>]*>#i', $before, $m, PREG_OFFSET_CAPTURE, $off)) {
        if ($m[1][0] === '/') array_pop($stack); else $stack[] = (int)$m[0][1];
        $off = (int)$m[0][1] + strlen($m[0][0]);
    }
    if (!$stack) return null;
    $s = $stack[0];                       // 最外層那一個
    $depth = 0; $j = $s;
    while (true) {
        if (!preg_match('#<(/?)p\b[^>]*>#i', $html, $m, PREG_OFFSET_CAPTURE, $j)) return null;
        $depth += ($m[1][0] === '/') ? -1 : 1;
        $j = (int)$m[0][1] + strlen($m[0][0]);
        if ($depth === 0) return [$s, $j];
    }
}

/**
 * 依 HTML 中的位置把碎片分群：中間夾了實質內文就切成不同張圖。
 * （用碎片之間的距離判斷，而不是段落邊界——LibreOffice 的 <p> 會巢狀，邊界不可信。）
 * @return array<int,array{names:string[],ranges:array,insert:int}>
 */
function adfi_groups_in_html(string $html, array $byName): array
{
    $frags = adfi_frag_ranges($html, $byName);
    if (!$frags) return [];

    $groups = [];
    $cur = [$frags[0]];
    for ($i = 1; $i < count($frags); $i++) {
        $gapHtml = substr($html, $cur[count($cur) - 1][1], $frags[$i][0] - $cur[count($cur) - 1][1]);
        $gapTxt  = trim(preg_replace('/[\s\x{00A0}\x{3000}]+/u', '',
                   html_entity_decode(strip_tags($gapHtml), ENT_QUOTES, 'UTF-8')));
        if (mb_strlen($gapTxt) > 24) { $groups[] = $cur; $cur = []; }   // 夾了一整句話＝不同張圖
        $cur[] = $frags[$i];
    }
    if ($cur) $groups[] = $cur;

    $out = [];
    foreach ($groups as $g) {
        $names = [];
        foreach ($g as $f) $names[$f[2]] = true;
        $names = array_keys($names);
        $cnt = 0;
        foreach ($names as $n) $cnt += count($byName[$n]);
        if ($cnt < ADFI_MIN_ITEMS) continue;
        /* 圖要插在「碎片所在的那一段」之後，不然會卡在標題文字中間
           （實例 2-GM-03：標題「風險管理程序流程」的「風」在碎片前、其餘在碎片後）。
           ⚠ 一定要以**最後一塊**碎片為基準找那一段：以第一塊找會停在半路的 </p>，
             後面還沒處理的碎片就落在插入點之後而被跳過不刪（實測會殘留 13 塊）。 */
        $last = $g[count($g) - 1];
        $p = adfi_enclosing_p($html, $last[0]);
        $ins = $p ? $p[1] : $last[1];
        if ($ins < $last[1]) $ins = $last[1];
        $out[] = ['names' => $names, 'ranges' => $g, 'insert' => $ins];
    }
    return $out;
}

/**
 * 一群元素 → 流程圖資料（座標平移到原點、cm 換成 px）。
 * 格式刻意做成自己的中介格式而不是 Fabric 的 JSON：Fabric 的序列化格式綁版本，
 * 前端改用編輯器自己的建構函式重建，行為才跟手畫的一模一樣（見 _flow_editor_ui.php）。
 */
function adfi_flow_data(array $items): array
{
    $xs = []; $ys = [];
    foreach ($items as $it) {
        if ($it['t'] === 'conn') { $xs[] = $it['x1']; $xs[] = $it['x2']; $ys[] = $it['y1']; $ys[] = $it['y2']; }
        else { $xs[] = $it['x']; $xs[] = $it['x'] + $it['w']; $ys[] = $it['y']; $ys[] = $it['y'] + $it['h']; }
    }
    if (!$xs) return [];
    $minX = min($xs); $minY = min($ys);
    $k = ADFI_PX_PER_CM;
    $px = function ($v) use ($k) { return (int)round($v * $k); };

    $out = [];
    foreach ($items as $it) {
        if ($it['t'] === 'conn') {
            $out[] = ['t' => 'conn', 'kind' => $it['kind'],
                      'x1' => $px($it['x1'] - $minX) + ADFI_PAD, 'y1' => $px($it['y1'] - $minY) + ADFI_PAD,
                      'x2' => $px($it['x2'] - $minX) + ADFI_PAD, 'y2' => $px($it['y2'] - $minY) + ADFI_PAD];
        } else {
            $out[] = ['t' => $it['t'], 'kind' => $it['kind'],
                      'x' => $px($it['x'] - $minX) + ADFI_PAD, 'y' => $px($it['y'] - $minY) + ADFI_PAD,
                      'w' => max(8, $px($it['w'])), 'h' => max(8, $px($it['h'])),
                      'text' => (string)($it['text'] ?? '')];
        }
    }
    $w = $px(max($xs) - $minX) + ADFI_PAD * 2;
    $h = $px(max($ys) - $minY) + ADFI_PAD * 2;
    return ['egImport' => 1, 'w' => $w, 'h' => $h, 'items' => $out];
}

/** 可用的中文字型檔（找不到就回 null，呼叫端改畫無字的框） */
function adfi_font(): ?string
{
    foreach (ADFI_FONTS as $f) if (is_file($f)) return $f;
    return null;
}

/**
 * 流程圖資料 → PNG（給文件裡當預覽圖用；真正可編輯的內容存在 flow_json）。
 * 後端畫圖是刻意的：匯入當下就要看得到正確的圖，不能等有人開過編輯器才有。
 * @return string|null PNG 內容
 */
function adfi_render_png(array $flow, int $scale = 2): ?string
{
    if (empty($flow['items'])) return null;
    $W = max(40, (int)$flow['w']) * $scale;
    $H = max(40, (int)$flow['h']) * $scale;
    if ($W * $H > 40000000) return null;                 // 太大就不畫，免得吃爆記憶體
    $im = @imagecreatetruecolor($W, $H);
    if (!$im) return null;
    imagealphablending($im, true);
    imagefilledrectangle($im, 0, 0, $W, $H, imagecolorallocate($im, 255, 255, 255));
    $ink  = imagecolorallocate($im, 51, 51, 51);
    $font = adfi_font();
    $fs   = 12 * $scale;

    foreach ($flow['items'] as $it) {
        if ($it['t'] === 'conn') {
            $x1 = $it['x1'] * $scale; $y1 = $it['y1'] * $scale;
            $x2 = $it['x2'] * $scale; $y2 = $it['y2'] * $scale;
            imagesetthickness($im, max(1, (int)round(1.5 * $scale)));
            imageline($im, (int)$x1, (int)$y1, (int)$x2, (int)$y2, $ink);
            imagesetthickness($im, 1);
            if ($it['kind'] === 'arrow') adfi_arrow($im, $x1, $y1, $x2, $y2, $ink, $scale);
            continue;
        }
        $x = $it['x'] * $scale; $y = $it['y'] * $scale;
        $w = $it['w'] * $scale; $h = $it['h'] * $scale;
        if ($it['t'] === 'shape') {
            imagesetthickness($im, max(1, (int)round(1.5 * $scale)));
            switch ($it['kind']) {
                case 'judge':
                    $pts = [$x + $w / 2, $y,  $x + $w, $y + $h / 2,  $x + $w / 2, $y + $h,  $x, $y + $h / 2];
                    imagepolygon($im, array_map('intval', $pts), $ink);
                    break;
                case 'doc':
                    $off = min($w * 0.1, 14 * $scale);
                    $pts = [$x + $off, $y,  $x + $w, $y,  $x + $w - $off, $y + $h,  $x, $y + $h];
                    imagepolygon($im, array_map('intval', $pts), $ink);
                    break;
                default:
                    imagerectangle($im, (int)$x, (int)$y, (int)($x + $w), (int)($y + $h), $ink);
            }
            imagesetthickness($im, 1);
        }
        $txt = trim((string)($it['text'] ?? ''));
        if ($txt !== '' && $font) adfi_text_box($im, $txt, $x, $y, $w, $h, $fs, $ink, $font);
    }

    ob_start();
    imagepng($im, null, 6);
    $bytes = (string)ob_get_clean();
    imagedestroy($im);
    return $bytes !== '' ? $bytes : null;
}

/** 在線的終點畫箭頭 */
function adfi_arrow($im, float $x1, float $y1, float $x2, float $y2, int $ink, int $scale): void
{
    $a = atan2($y2 - $y1, $x2 - $x1);
    $L = 9 * $scale; $Wd = 4.5 * $scale;
    $pts = [
        $x2, $y2,
        $x2 - $L * cos($a) + $Wd * sin($a), $y2 - $L * sin($a) - $Wd * cos($a),
        $x2 - $L * cos($a) - $Wd * sin($a), $y2 - $L * sin($a) + $Wd * cos($a),
    ];
    imagefilledpolygon($im, array_map('intval', $pts), $ink);
}

/** 方框內置中文字（依框寬自動換行；一個中文字一個字元寬，所以逐字量） */
function adfi_text_box($im, string $txt, float $x, float $y, float $w, float $h,
                       int $fs, int $ink, string $font): void
{
    $maxW = max(20, $w - 8);
    // 字數多的方框（「2-GM-01管理責任與經營規劃程序」這種）換行後會比框高還高，
    // 一路縮字級到放得下為止；縮到下限還是放不下才讓它溢出，至少字還看得見
    $lines = [];
    $lh = 0;
    for ($pass = 0; $pass < 8; $pass++) {
        $lines = [];
        foreach (preg_split('/\n/u', $txt) as $para) {
            $cur = '';
            $n = mb_strlen($para);
            for ($i = 0; $i < $n; $i++) {
                $ch  = mb_substr($para, $i, 1);
                $try = $cur . $ch;
                $bb  = @imagettfbbox($fs, 0, $font, $try);
                if ($bb && ($bb[2] - $bb[0]) > $maxW && $cur !== '') { $lines[] = $cur; $cur = $ch; }
                else $cur = $try;
            }
            if ($cur !== '') $lines[] = $cur;
        }
        $lh = (int)round($fs * 1.3);
        if (!$lines || count($lines) * $lh <= $h - 2 || $fs <= 7) break;
        $fs = (int)max(7, floor($fs * 0.88));
        $maxW = max(20, $w - 6);
    }
    if (!$lines) return;
    $totH = count($lines) * $lh;
    $ty = $y + ($h - $totH) / 2 + $fs;          // imagettftext 的 y 是基線
    foreach ($lines as $ln) {
        $bb = @imagettfbbox($fs, 0, $font, $ln);
        $lw = $bb ? ($bb[2] - $bb[0]) : 0;
        @imagettftext($im, $fs, 0, (int)round($x + ($w - $lw) / 2), (int)round($ty), $ink, $font, $ln);
        $ty += $lh;
    }
}

/**
 * 主流程：把 HTML 裡的流程圖碎片換成一張可再編輯的流程圖。
 *
 * 每一群碎片 → 一個 as_doc_asset（kind=flow：PNG 當預覽、flow_json 存可編輯的內容），
 * 碎片本身整批移除，<img data-asset="N"> 插在那一段之後。
 *
 * @return string 置換後的 HTML
 */
function adfi_import(PDO $db, string $html, string $fodtPath, int $contentId, int $uid, array &$stat): string
{
    $stat['flow_img'] = 0;
    $stat['flow_items'] = 0;
    if ($contentId <= 0) return $html;
    $p = adfi_parse($fodtPath);
    if (!$p['byName']) return $html;
    $groups = adfi_groups_in_html($html, $p['byName']);
    if (!$groups) return $html;

    // 由後往前改，前面的位移才不會影響還沒處理的群
    usort($groups, function ($a, $b) { return $b['insert'] <=> $a['insert']; });
    foreach ($groups as $g) {
        $items = [];
        foreach ($g['names'] as $n) foreach ($p['byName'][$n] as $it) $items[] = $it;
        $flow = adfi_flow_data($items);
        if (!$flow) continue;
        $png = adfi_render_png($flow);
        if ($png === null) continue;

        $r = adc_asset_store($db, $contentId, 'flow', $png, 'png', '流程圖（Word 匯入）',
                             json_encode($flow, JSON_UNESCAPED_UNICODE), $uid);
        if (empty($r['ok'])) { error_log('[adfi] asset_store: ' . ($r['msg'] ?? '?')); continue; }

        $tag = '<p><img data-asset="' . (int)$r['id'] . '" style="width:'
             . min(100, (int)round($flow['w'] / 6.8)) . '%"></p>';
        // 先插圖（位置在後），再由後往前刪碎片，索引才不會跑掉
        $html = substr($html, 0, $g['insert']) . $tag . substr($html, $g['insert']);
        $ranges = $g['ranges'];
        usort($ranges, function ($a, $b) { return $b[0] <=> $a[0]; });
        foreach ($ranges as $rg) {
            if ($rg[1] > $g['insert']) continue;             // 理論上不會發生，保險
            /* 連碎片後面的原始碼縮排（換行＋tab）一起吃掉：那本來只是「圖片與圖片之間」
               的排版空白，碎片一拿掉就會變成文字裡的一個空格，把同一句話從中間切開
               （實例 2-GM-03 的標題「風　險管理程序流程」，使用者 2026-10-15 回報）。
               純空格保留——英文字之間的空格是內容。 */
            $end = $rg[1];
            while ($end < strlen($html) && strpos("\r\n\t", $html[$end]) !== false) $end++;
            $html = substr($html, 0, $rg[0]) . substr($html, $end);
        }
        $stat['flow_img']++;
        $stat['flow_items'] += count($flow['items']);
    }
    return $html;
}
