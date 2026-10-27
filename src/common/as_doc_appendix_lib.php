<?php
/**
 * 一階文件（品質手冊）的系統附件頁 —— 目前只有「管理代表派令附錄」（2026-10-27）
 *
 * 為什麼是「讀取時即時產生」而不是存一份死文字進文件內容：
 *   派令上的人名、部門職稱、生效日一律來自 views/admin/org_role_setting.php 的「管理代表」綁定
 *   （唯一來源，鐵律4）。存成死文字，換管理代表時手冊上還是舊人而且不報錯。
 *   所以文件內容裡只放「這一頁在哪」（標題「附件A　管理代表派令」），每次讀取（編輯器載入／預覽／列印／
 *   目錄與頁數計算）都由 adx_apply() 把這一頁整頁換成依目前設定算出的新版；內容裡找不到這頁就補在最後。
 *
 * 簽章：總經理那一格只輸出 data-stamp-* 屬性，由前端 eg_doc_sign_stamp.js 畫成圖章（ai-rules/18），
 *   圖章日期＝管理代表的「生效日」（使用者指定）；沒設生效日就不蓋章、留空給手簽（不用今天日期亂補）。
 *
 * 限制：讀取時產生、不經富文字清洗器（清洗器會把 data-stamp-* 整個丟掉）；存檔時清洗器丟掉的屬性無妨，
 *   因為下次讀取又會整頁重產。
 */
require_once __DIR__ . '/org_role_lib.php';
require_once __DIR__ . '/people_lib.php';
require_once __DIR__ . '/date_fmt_lib.php';

if (!defined('ADX_PAGE_SEP')) define('ADX_PAGE_SEP', '<hr style="page-break-after:always">');

if (!function_exists('adx_apply')) {


function adx_e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/** 某人在某日當時的部門／職稱（業務日期回推，ai-rules/22；回推不到退回現況，同一人兩個職務時取主職） */
function adx_person_asof(PDO $db, int $uid, string $date, string $preferPos = ''): array
{
    $out = ['dept' => '', 'pos' => ''];
    if ($uid <= 0) return $out;
    try {
        $rows = eg_people_posts_asof($db, ['user_ids' => [$uid]], $date);
        if (!$rows) $rows = eg_people_posts($db, ['user_ids' => [$uid]]);
        // 兼任者：派令上簽章的是「總經理」，所以優先取指定職稱（例：同時是董事長與總經理時取總經理）；沒有再取主職
        usort($rows, function ($a, $b) use ($preferPos) {
            $pa = ($preferPos !== '' && (string)$a['position_name'] === $preferPos) ? 1 : 0;
            $pb = ($preferPos !== '' && (string)$b['position_name'] === $preferPos) ? 1 : 0;
            return [$pb, (int)$b['is_main']] <=> [$pa, (int)$a['is_main']];
        });
        if ($rows) { $out['dept'] = (string)$rows[0]['dept_name']; $out['pos'] = (string)$rows[0]['position_name']; }
    } catch (Throwable $e) {}
    return $out;
}

/** 管理代表派令所需資料 */
function adx_mgmt_rep_info(PDO $db): array
{
    eg_org_ensure_schema($db);
    $eff = eg_org_effective_date($db, 'mgmt_rep');
    $date = $eff ?: date('Y-m-d');
    $rep = eg_org_user($db, 'mgmt_rep');
    $gm  = eg_org_user($db, 'top_approver');
    $repP = $rep ? adx_person_asof($db, (int)$rep['id'], $date) : ['dept' => '', 'pos' => ''];
    $gmP  = $gm  ? adx_person_asof($db, (int)$gm['id'],  $date, '總經理') : ['dept' => '', 'pos' => ''];
    return [
        'eff'      => $eff,
        'rep_name' => $rep ? (string)$rep['user_cname'] : '',
        'rep_dept' => $repP['dept'], 'rep_pos' => $repP['pos'],
        'gm_name'  => $gm ? (string)$gm['user_cname'] : '',
        'gm_dept'  => $gmP['dept'], 'gm_pos' => $gmP['pos'],
    ];
}

/** 附錄標題的前綴（「錄」可能是 CJK 相容字，與 adt_toc_auto_level 同一組寫法） */
if (!defined('ADX_PREFIX_RE')) define('ADX_PREFIX_RE', '(?:附件|附[錄\x{F900}-\x{FAFF}])');

/**
 * 內容裡「其他附錄」的編號與用字：回 ['letters'=>['A','B','D'], 'prefix'=>'附錄']
 * 標題的判定：手動設成標題 1～4 的一律算；一般段落／儲存格要短、無句子標點、且不是「附錄 A 用以…」這種敘述。
 */
function adx_scan_appendix(array $pages): array
{
    $letters = []; $cnt = ['附錄' => 0, '附件' => 0];
    foreach ($pages as $p) {
        if (adx_is_mgmt_rep_page($p)) continue;       // 派令頁自己不算（它的編號由別人的最大值推出來）
        if (!preg_match_all('#<(h[1-4]|p|td)\b[^>]*>(.*?)</\1>#is', $p, $m, PREG_SET_ORDER)) continue;
        foreach ($m as $one) {
            $t = trim(preg_replace('/[\s\x{00A0}\x{3000}]+/u', ' ', strip_tags($one[2])));
            if ($t === '' || !preg_match('/^(' . ADX_PREFIX_RE . ')\s*([A-Z])/u', $t, $mm)) continue;
            $isH = $one[1] !== 'p' && $one[1] !== 'td';
            if (!$isH) {
                if (mb_strlen($t, 'UTF-8') > 60 || preg_match('/[，。；;,：:]/u', $t)) continue;
                if (preg_match('/^' . ADX_PREFIX_RE . '\s*[A-Z]\s*用/u', $t)) continue;
            }
            $letters[$mm[2]] = true;
            $cnt[mb_substr($mm[1], 0, 2, 'UTF-8') === '附件' ? '附件' : '附錄']++;
        }
    }
    $L = array_keys($letters); sort($L);
    return ['letters' => $L, 'prefix' => $cnt['附件'] > $cnt['附錄'] ? '附件' : '附錄'];
}

/** 「管理代表派令」附錄頁的 HTML。編號＝其他附錄的最後一個再往下跳號（使用者指定，不固定 A） */
function adx_mgmt_rep_page_html(PDO $db, array $otherPages = []): string
{
    $i = adx_mgmt_rep_info($db);
    $sc = adx_scan_appendix($otherPages);
    $last = $sc['letters'] ? end($sc['letters']) : '';
    $letter = $last === '' ? 'A' : chr(min(ord('Z'), ord($last) + 1));
    $title = $sc['prefix'] . ' ' . $letter . '　管理代表派令';
    $repTxt = $i['rep_name'] !== ''
        ? adx_e(trim($i['rep_dept'] . '　' . $i['rep_pos'])) . '　<b>' . adx_e($i['rep_name']) . '</b>'
        : '<b>（尚未於「組織角色綁定」設定管理代表）</b>';
    $effTxt = $i['eff'] ? adx_e(eg_fmt_date($i['eff'])) : '（尚未設定生效日）';

    $attr = '';
    if ($i['eff'] && $i['gm_name'] !== '') {      // 沒生效日＝不蓋章（圖章一定帶日期，不拿今天亂補）
        $attr = ' data-sign="appx_gm" data-stamp-name="' . adx_e($i['gm_name']) . '"'
              . ' data-stamp-dept="' . adx_e($i['gm_dept']) . '" data-stamp-pos="' . adx_e($i['gm_pos']) . '"'
              . ' data-stamp-date="' . adx_e(eg_fmt_date($i['eff'])) . '"';
    }
    // 標題一律帶「強制換頁」：編輯器回流時會把下一頁的第一個區塊拉回上一頁填空位，
    // 沒有這個標記，附錄就會被拉進前一頁的空白處（使用者 2026-10-27 回報「附件應該自己一頁」）
    $h  = '<h1 style="text-align:center;page-break-before:always">' . adx_e($title) . '</h1>';
    $h .= '<p style="text-align:center">（2.1　管理代表派令）</p><p><br></p>';
    $h .= '<p style="text-align:center;font-size:20pt"><b>派　　令</b></p><p><br></p>';
    $h .= '<p style="margin-left:2em;margin-right:2em">茲遴派　' . $repTxt . '　為本公司 AS9100 國際品質管理系統之管理代表，'
        . '負責建立、執行與維持品質管理系統，並向管理階層提出品質管理系統的有效成效及待改進事項，'
        . '確保全體人員皆能了解顧客要求，為品質管理系統對外之聯絡人。</p><p><br></p>';
    $h .= '<p style="margin-left:2em">生效日期：' . $effTxt . '</p><p><br></p><p><br></p>';
    // 「總經理：＿＿＿＿」：標籤在左、底線在右，圖章蓋在底線上（格子只留底線，其餘框線拿掉）
    $h .= '<table style="width:60%;margin-left:auto;margin-right:auto"><tbody><tr>'
        . '<td style="width:28%;text-align:right;vertical-align:bottom;border:none"><b>總經理：</b></td>'
        . '<td' . $attr . ' style="height:110px;text-align:center;vertical-align:bottom;border:none;border-bottom:1px solid #000">&nbsp;</td>'
        . '</tr></tbody></table>';
    return $h;
}

/** 這一頁是不是「管理代表派令」附錄（以標題文字判定；清洗器不留自訂屬性，只能靠文字；編號會變所以只比標題） */
function adx_is_mgmt_rep_page(string $pageHtml): bool
{
    $t = preg_replace('/[\s\x{00A0}\x{3000}]+/u', '', strip_tags($pageHtml));
    return (bool)preg_match('/^' . ADX_PREFIX_RE . '[A-Z]管理代表派令/u', (string)$t);
}

/**
 * 把內容裡已存的「管理代表派令」附錄整段拿掉（標題 h1 起到派令表格結束）。
 * 不能只靠「頁」判定：編輯器的回流會把下一頁第一個區塊拉回上一頁的空位，
 * 存檔後這個附錄就掉在別頁的中間（2026-10-27 實際發生），所以用區塊（標題→表格）找。
 * @return array [新的內容, 是否有拿掉]
 */
function adx_strip_stored(string $html): array
{
    if (!preg_match_all('#<h1\b[^>]*>.*?</h1>#is', $html, $m, PREG_OFFSET_CAPTURE)) return [$html, false];
    foreach ($m[0] as $one) {
        $t = preg_replace('/[\s\x{00A0}\x{3000}]+/u', '', strip_tags($one[0]));
        if (!preg_match('/^' . ADX_PREFIX_RE . '[A-Z]管理代表派令/u', (string)$t)) continue;
        $start = $one[1];
        $te = stripos($html, '</table>', $start);
        if ($te === false || $te - $start > 6000) continue;          // 找不到派令表格：不動，免得誤刪
        $end = $te + 8;
        if (mb_strpos(strip_tags(substr($html, $start, $end - $start)), '派', 0, 'UTF-8') === false) continue;
        return [substr($html, 0, $start) . substr($html, $end), true];
    }
    return [$html, false];
}

/**
 * 一階文件：把內容裡的「管理代表派令附錄」換成依目前設定產生的版本，放在整份文件最後一頁。
 * 非一階、或沒有內容時原樣回傳。
 * @param string $html 文件內容（含頁界標記 <hr style="page-break-after:always">）
 */
function adx_apply(PDO $db, string $html, ?string $docLevel): string
{
    if ((string)$docLevel !== '一階') return $html;
    if (trim($html) === '') return $html;
    try {
        [$rest, ] = adx_strip_stored($html);
        $parts = preg_split('#<hr[^>]*page-break-after[^>]*>#i', $rest);
        if (!is_array($parts)) return $html;
        // 拿掉附錄後變成完全空白的頁（只剩空段落）一併丟掉
        $keep = [];
        foreach ($parts as $p) {
            if (trim(strip_tags($p, '<img><table>')) === '' && stripos($p, '<img') === false && stripos($p, '<table') === false) continue;
            $keep[] = $p;
        }
        $parts = $keep ?: $parts;
        $page  = adx_mgmt_rep_page_html($db, $parts);
        $parts[] = $page;
        return implode("\n" . ADX_PAGE_SEP . "\n", $parts);
    } catch (Throwable $e) {
        return $html;      // 附錄頁壞掉不可以讓整份文件打不開
    }
}

} // adx_apply
