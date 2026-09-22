<?php
/**
 * 共用「富文字（有格式的備註）」處理庫  ── 唯一實作點，禁止各頁自刻 ──
 *
 * 用途：讓使用者在備註/說明類欄位像 Word 一樣做部份粗體、底線、換色、換底色。
 * 前端唯一實作：resource/js/eg_richtext.js（EGRichText.attach()）
 *
 * 鐵律8「前端擋一次、後端同規則再擋一次」：前端貼上時會先清一次，
 * 但任何人都能略過前端直打 API，所以**存檔前一律再過 eg_richtext_sanitize()**。
 * 這不只是版面問題——contenteditable 存下來的 HTML 會原樣塞回頁面，
 * 沒清乾淨就是一個 XSS 洞（<script>、onerror=、javascript: 連結）。
 *
 * 白名單以外的標籤一律「脫殼保留文字」（不是整段丟掉），使用者貼 Word 內容時
 * 才不會整段消失；style 只留 color / background-color 且值必須是 #hex 或 rgb()。
 */

if (!defined('EG_RICHTEXT_LIB')) {
define('EG_RICHTEXT_LIB', 1);

/** 允許保留的標籤（其餘脫殼只留文字） */
define('EG_RT_TAGS', ['b','strong','i','em','u','s','strike','del','br','ul','ol','li','span','div','p']);
/**
 * 允許保留的 CSS 宣告：屬性 => 該屬性的合法值樣式。
 * ⚠ 不能只留 color / background-color——瀏覽器 execCommand 在 styleWithCSS 模式下，
 *   粗體產生的是 <span style="font-weight:bold">、底線是 text-decoration:underline。
 *   把這兩個濾掉的話「按了粗體、存完卻變回普通字」而且完全不報錯，非常難發現
 *   （2026-08-21 無頭 Chrome 實測抓到）。貼 Word 內容也是同一組屬性。
 */
define('EG_RT_STYLES', [
    'color'                => '/^(#[0-9A-Fa-f]{3,8}|rgba?\(\s*[\d.,%\s]+\)|[a-zA-Z]{3,20})$/',
    'background-color'     => '/^(#[0-9A-Fa-f]{3,8}|rgba?\(\s*[\d.,%\s]+\)|[a-zA-Z]{3,20})$/',
    'font-weight'          => '/^(bold|bolder|normal|lighter|[1-9]00)$/i',
    'font-style'           => '/^(italic|oblique|normal)$/i',
    'text-decoration'      => '/^[a-zA-Z\- ]{1,40}$/',
    'text-decoration-line' => '/^[a-zA-Z\- ]{1,40}$/',
    // 縮排：只收 em 單位、最多 2 位數（前端 eg_richtext.js 的縮排鈕寫在區塊元素上）。
    // 不放行 px/%/calc()，避免有人把版面推到畫面外。
    'margin-left'          => '/^(\d{1,2}(\.\d)?)em$/',
]);

/* ──────────────────────────────────────────────────────────────────────────
   profile『doc』：二階程序書等「整份文件」的線上版用
   ──────────────────────────────────────────────────────────────────────────
   跟 basic（備註欄）的差別是它要當正式文件的正本，所以多了標題階層、表格、
   圖片、字型字級對齊。**不要把這些併進 basic**——備註欄是印在清單上的一小段，
   放得進表格與圖片只會把版面撐爛，而且白名單愈大，XSS 面愈大。

   <img> 刻意只留 data-asset（資產編號），**src 一律不存**：
   使用者送來的 src 不可信（外部網址、base64 塞爆 DB、javascript: 偽協定），
   所以存檔時剝掉，顯示前才由 as_doc_content_lib.php 依資產編號組回本站下載網址
   （鐵律5：DB 只存識別值，路徑即時組）。
*/
define('EG_RT_DOC_TAGS', array_merge(EG_RT_TAGS, [
    'h1','h2','h3','h4','h5','h6',
    'table','thead','tbody','tfoot','tr','td','th','colgroup','col',
    'img','hr','sub','sup',
]));

/** 允許的字型：只給這幾種，且**存的是字型堆疊字串**（CJK 字型要有英文後援才印得出來） */
define('EG_RT_DOC_FONTS', [
    '標楷體'      => '"標楷體","DFKai-SB","BiauKai",serif',
    '微軟正黑體'  => '"微軟正黑體","Microsoft JhengHei",sans-serif',
    '新細明體'    => '"新細明體","PMingLiU",serif',
    'Arial'       => 'Arial,Helvetica,sans-serif',
    'Times'       => '"Times New Roman",Times,serif',
    'Consolas'    => 'Consolas,"Courier New",monospace',
]);

define('EG_RT_DOC_STYLES', array_merge(EG_RT_STYLES, [
    // 字型：禁止出現括號＝連帶擋掉 url()／expression()；中文字型名要放行 CJK 與引號
    'font-family'      => '/^[\p{Han}\w\s,\'"\-]{1,120}$/u',
    'font-size'        => '/^([1-9]\d?(\.\d)?)(pt|px)$/',
    'text-align'       => '/^(left|right|center|justify)$/i',
    'text-indent'      => '/^(-?\d{1,2}(\.\d)?)(em|pt)$/',
    'line-height'      => '/^(\d(\.\d{1,2})?|1[0-9]{1,2}%|[1-9]\d?0%)$/',
    // cm/mm 要放行：Word 匯入進來的版面單位就是 cm（流程圖方框是 width:3.55cm），
    // 不收的話那些框會全部塌掉變成一整片文字
    'width'            => '/^(\d{1,3}(\.\d{1,2})?)(px|%|em|cm|mm)$/',
    'height'           => '/^(\d{1,4}(\.\d{1,2})?)(px|em|cm|mm)$/',
    'padding'          => '/^(\d{1,2}(\.\d{1,2})?(px|em|cm|mm)\s*){1,4}$/',
    'vertical-align'   => '/^(top|middle|bottom|baseline)$/i',
    'border'           => '/^(\d{1,2}px\s+(solid|dashed|dotted|none)\s+(#[0-9A-Fa-f]{3,8}|[a-zA-Z]{3,20})|none|0)$/i',
    'border-collapse'  => '/^(collapse|separate)$/i',
    'border-width'     => '/^(\d{1,2}px\s*){1,4}$/',
    'border-style'     => '/^((solid|dashed|dotted|none)\s*){1,4}$/i',
    'border-color'     => '/^((#[0-9A-Fa-f]{3,8}|[a-zA-Z]{3,20})\s*){1,4}$/',
    'float'            => '/^(left|right|none)$/i',
    'margin'           => '/^(\d{1,2}(\.\d{1,2})?(px|em|cm|mm)\s*){1,4}$/',
    'margin-right'     => '/^(\d{1,2}(\.\d{1,2})?)(em|px|cm|mm)$/',
    'page-break-before'=> '/^(always|auto|avoid)$/i',
    'page-break-after' => '/^(always|auto|avoid)$/i',
    'page-break-inside'=> '/^(avoid|auto)$/i',
]));

/**
 * 逐標籤允許保留的屬性（style 另外處理）。
 * 沒列在這裡的標籤＝屬性全清，行為與 basic 完全相同。
 */
define('EG_RT_DOC_ATTRS', [
    'td'  => ['colspan','rowspan'],
    'th'  => ['colspan','rowspan'],
    'img' => ['data-asset'],
    'col' => ['span'],
]);

/** 屬性值檢查：只收得下這幾種，其餘一律丟掉 */
define('EG_RT_DOC_ATTR_PAT', [
    'colspan'    => '/^([1-9]|[1-9]\d)$/',
    'rowspan'    => '/^([1-9]|[1-9]\d)$/',
    'span'       => '/^([1-9]|[1-9]\d)$/',
    'data-asset' => '/^[1-9]\d{0,9}$/',
]);

/**
 * 取得清洗設定。新增 profile 一律加在這裡，不要在呼叫端自己拼白名單。
 * @return array{tags:string[],styles:array,attrs:array,attrPat:array}
 */
function eg_rt_profile(string $name = 'basic'): array
{
    if ($name === 'doc') {
        return [
            'tags'    => EG_RT_DOC_TAGS,
            'styles'  => EG_RT_DOC_STYLES,
            'attrs'   => EG_RT_DOC_ATTRS,
            'attrPat' => EG_RT_DOC_ATTR_PAT,
        ];
    }
    return ['tags' => EG_RT_TAGS, 'styles' => EG_RT_STYLES, 'attrs' => [], 'attrPat' => []];
}

/**
 * 清洗富文字 HTML。
 * @param string $html  使用者送來的原始 HTML
 * @param int    $maxLen 清洗後的長度上限（字元，約略值——截斷後要重新補齊標籤，故可能略超；0=不限）
 * @param string $profile 'basic'＝備註欄（預設，行為與改版前完全相同）／'doc'＝整份文件（見 eg_rt_profile）
 * @return string 可安全直接輸出到頁面的 HTML；空內容一律回 ''
 */
function eg_richtext_sanitize(string $html, int $maxLen = 20000, string $profile = 'basic'): string
{
    $cfg = eg_rt_profile($profile);
    $html = trim($html);
    if ($html === '') return '';
    // 先擋掉超大輸入，避免 DOM 解析吃爆記憶體（原始長度給清洗後上限的 4 倍寬容）
    if ($maxLen > 0 && mb_strlen($html, 'UTF-8') > $maxLen * 4) {
        $html = mb_substr($html, 0, $maxLen * 4, 'UTF-8');
    }

    $doc = new DOMDocument('1.0', 'UTF-8');
    $prev = libxml_use_internal_errors(true);
    // mb_convert_encoding 把中文轉成 entity，避免 DOMDocument 把 UTF-8 當 Latin-1 讀成亂碼
    $ok = $doc->loadHTML(
        '<?xml encoding="UTF-8"><div id="eg-rt-root">' . $html . '</div>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET
    );
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if (!$ok) return '';

    $root = $doc->getElementById('eg-rt-root');
    if (!$root) {
        // 少數情況（如整段只有文字）拿不到 id，退回第一個元素
        $root = $doc->documentElement;
        if (!$root) return '';
    }
    eg_rt_clean_node($doc, $root, $cfg);

    $out = '';
    foreach ($root->childNodes as $c) { $out .= $doc->saveHTML($c); }
    $out = trim($out);

    // 只剩空白/空標籤（contenteditable 清空後常留 <br> 或 <div><br></div>）視同沒填。
    // 圖片與表格本身就是內容（一張流程圖、一張權責分工表都可能一個字都沒有），不可因此被判成空的。
    if (eg_richtext_to_text($out) === ''
        && stripos($out, '<img') === false
        && stripos($out, '<table') === false) return '';

    if ($maxLen > 0 && mb_strlen($out, 'UTF-8') > $maxLen) {
        // 截斷會切壞標籤，故截原始文字後重跑一次清洗讓 DOM 幫忙補齊
        $out = eg_richtext_sanitize(mb_substr($out, 0, $maxLen, 'UTF-8'), 0, $profile);
    }
    return $out;
}

/** 遞迴清洗：白名單外的元素脫殼、屬性只留 style 的安全宣告
 *  @param array|null $cfg eg_rt_profile() 的結果；null＝basic（舊呼叫端不必改） */
function eg_rt_clean_node(DOMDocument $doc, DOMNode $node, ?array $cfg = null): void
{
    if ($cfg === null) $cfg = eg_rt_profile('basic');
    // 先複製一份子節點清單——清洗過程會改動 childNodes（live NodeList 邊走邊改會漏掉節點）
    $kids = [];
    foreach ($node->childNodes as $c) { $kids[] = $c; }

    foreach ($kids as $child) {
        if ($child->nodeType === XML_TEXT_NODE) continue;

        if ($child->nodeType !== XML_ELEMENT_NODE) {   // 註解、CDATA、PI 一律移除
            $child->parentNode->removeChild($child);
            continue;
        }
        /** @var DOMElement $child */
        $tag = strtolower($child->nodeName);

        // script/style 連同內容整個移除（脫殼會把 JS 原始碼變成可見文字）
        if ($tag === 'script' || $tag === 'style') { $child->parentNode->removeChild($child); continue; }

        eg_rt_clean_node($doc, $child, $cfg);   // 先清子層，脫殼時才不會把髒東西留下

        // <img> 沒有合法的資產編號＝來路不明（外部網址、base64、貼上帶進來的），整個移除不脫殼
        // （脫殼對 void 元素等於直接消失，但寫清楚意圖免得日後誤以為漏做）
        if ($tag === 'img') {
            $aid = $child->getAttribute('data-asset');
            if ($aid === '' || !preg_match(EG_RT_DOC_ATTR_PAT['data-asset'], $aid)
                || !isset($cfg['attrs']['img'])) {
                $child->parentNode->removeChild($child);
                continue;
            }
        }

        if (!in_array($tag, $cfg['tags'], true)) {
            // 脫殼：把子節點提上來取代自己，只留文字與合法格式
            while ($child->firstChild) {
                $child->parentNode->insertBefore($child->firstChild, $child);
            }
            $child->parentNode->removeChild($child);
            continue;
        }

        // 沒有 <ul>/<ol> 當父層的孤兒 <li> 改成 <div>：
        // 貼上 Word／網頁內容時很容易只帶進 <li> 而沒有清單容器，瀏覽器仍會把它算成
        // display:list-item ＝ 每一行前面莫名其妙冒出一個「•」（使用者 2026-09-07 回報）。
        // 換成 <div> 可以保住原本的分行，只是不再有項目符號。
        if ($tag === 'li') {
            $pt = ($child->parentNode && $child->parentNode->nodeType === XML_ELEMENT_NODE)
                ? strtolower($child->parentNode->nodeName) : '';
            if ($pt !== 'ul' && $pt !== 'ol') {
                $dv = $doc->createElement('div');
                if ($child->getAttribute('style') !== '') $dv->setAttribute('style', $child->getAttribute('style'));
                while ($child->firstChild) { $dv->appendChild($child->firstChild); }
                $child->parentNode->replaceChild($dv, $child);
                $child = $dv;
                $tag = 'div';
            }
        }

        // 屬性全清，只留重建後的 style 與該標籤白名單內的屬性
        // （白名單是空的＝basic profile，行為與改版前完全相同）
        $style = eg_rt_clean_style($child->getAttribute('style'), $cfg['styles']);
        $keepAttr = [];
        foreach (($cfg['attrs'][$tag] ?? []) as $an) {
            $av = $child->getAttribute($an);
            if ($av === '') continue;
            $pat = $cfg['attrPat'][$an] ?? null;
            if ($pat !== null && !preg_match($pat, $av)) continue;
            $keepAttr[$an] = $av;
        }
        $attrs = [];
        foreach ($child->attributes as $a) { $attrs[] = $a->nodeName; }
        foreach ($attrs as $a) { $child->removeAttribute($a); }
        if ($style !== '') $child->setAttribute('style', $style);
        foreach ($keepAttr as $an => $av) { $child->setAttribute($an, $av); }
    }
}

/** style 只留白名單內的宣告，且值要通過該屬性自己的樣式檢查
 *  @param array|null $styles 允許的宣告表；null＝EG_RT_STYLES（basic，舊呼叫端不必改） */
function eg_rt_clean_style(string $style, ?array $styles = null): string
{
    if ($style === '') return '';
    if ($styles === null) $styles = EG_RT_STYLES;
    $keep = [];
    foreach (explode(';', $style) as $decl) {
        $p = explode(':', $decl, 2);
        if (count($p) !== 2) continue;
        $prop = strtolower(trim($p[0]));
        $val  = trim($p[1]);
        // 全域封鎖：任何屬性的值都不准出現 url()／expression()／偽協定。
        // 個別屬性的樣式本來就擋得住大部分，但 doc profile 放行了 border/font-family
        // 這類「字元種類比較雜」的屬性，多這一道才不會被某個樣式的漏洞穿過去。
        if (preg_match('/url\s*\(|expression\s*\(|javascript\s*:|data\s*:/i', $val)) continue;
        $pat  = $styles[$prop] ?? null;
        if ($pat === null || !preg_match($pat, $val)) continue;
        $keep[] = $prop . ':' . $val;
    }
    return implode(';', $keep);
}

/**
 * 轉純文字（列印、CSV、搜尋比對用；<br>/</li>/</div> 轉成換行）
 */
function eg_richtext_to_text(string $html): string
{
    if (trim($html) === '') return '';
    // 表格與標題也要斷行／斷欄，否則整張表會擠成一長串字（doc profile 用得到）
    $t = preg_replace('#</t[dh]>#i', "\t", $html);
    $t = preg_replace('#<(br|/li|/div|/p|/ul|/ol|/tr|/table|/h[1-6]|hr)\s*/?>#i', "\n", (string)$t);
    $t = strip_tags((string)$t);
    $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = str_replace("\xC2\xA0", ' ', $t);            // &nbsp;
    $t = preg_replace("/[ \t]+/", ' ', $t);
    $t = preg_replace("/\n{3,}/", "\n\n", (string)$t);
    return trim((string)$t);
}

} // EG_RICHTEXT_LIB
