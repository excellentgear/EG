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
]);

/**
 * 清洗富文字 HTML。
 * @param string $html  使用者送來的原始 HTML
 * @param int    $maxLen 清洗後的長度上限（字元，約略值——截斷後要重新補齊標籤，故可能略超；0=不限）
 * @return string 可安全直接輸出到頁面的 HTML；空內容一律回 ''
 */
function eg_richtext_sanitize(string $html, int $maxLen = 20000): string
{
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
    eg_rt_clean_node($doc, $root);

    $out = '';
    foreach ($root->childNodes as $c) { $out .= $doc->saveHTML($c); }
    $out = trim($out);

    // 只剩空白/空標籤（contenteditable 清空後常留 <br> 或 <div><br></div>）視同沒填
    if (eg_richtext_to_text($out) === '' && stripos($out, '<img') === false) return '';

    if ($maxLen > 0 && mb_strlen($out, 'UTF-8') > $maxLen) {
        // 截斷會切壞標籤，故截原始文字後重跑一次清洗讓 DOM 幫忙補齊
        $out = eg_richtext_sanitize(mb_substr($out, 0, $maxLen, 'UTF-8'), 0);
    }
    return $out;
}

/** 遞迴清洗：白名單外的元素脫殼、屬性只留 style 的安全宣告 */
function eg_rt_clean_node(DOMDocument $doc, DOMNode $node): void
{
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

        eg_rt_clean_node($doc, $child);   // 先清子層，脫殼時才不會把髒東西留下

        if (!in_array($tag, EG_RT_TAGS, true)) {
            // 脫殼：把子節點提上來取代自己，只留文字與合法格式
            while ($child->firstChild) {
                $child->parentNode->insertBefore($child->firstChild, $child);
            }
            $child->parentNode->removeChild($child);
            continue;
        }

        // 屬性全清，只留重建後的 style
        $style = eg_rt_clean_style($child->getAttribute('style'));
        $attrs = [];
        foreach ($child->attributes as $a) { $attrs[] = $a->nodeName; }
        foreach ($attrs as $a) { $child->removeAttribute($a); }
        if ($style !== '') $child->setAttribute('style', $style);
    }
}

/** style 只留 EG_RT_STYLES 白名單內的宣告，且值要通過該屬性自己的樣式檢查 */
function eg_rt_clean_style(string $style): string
{
    if ($style === '') return '';
    $keep = [];
    foreach (explode(';', $style) as $decl) {
        $p = explode(':', $decl, 2);
        if (count($p) !== 2) continue;
        $prop = strtolower(trim($p[0]));
        $val  = trim($p[1]);
        $pat  = EG_RT_STYLES[$prop] ?? null;
        // url()/expression()/javascript: 進不來：每個屬性的值都要通過自己的白名單樣式
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
    $t = preg_replace('#<(br|/li|/div|/p|/ul|/ol)\s*/?>#i', "\n", $html);
    $t = strip_tags((string)$t);
    $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = str_replace("\xC2\xA0", ' ', $t);            // &nbsp;
    $t = preg_replace("/[ \t]+/", ' ', $t);
    $t = preg_replace("/\n{3,}/", "\n\n", (string)$t);
    return trim((string)$t);
}

} // EG_RICHTEXT_LIB
