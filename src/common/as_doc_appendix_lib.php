<?php
/**
 * 一階文件（品質手冊）的系統附件頁 —— 目前只有「附件A 管理代表派令」（2026-10-27）
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

if (!defined('ADX_MGMT_REP_TITLE')) define('ADX_MGMT_REP_TITLE', '附件A　管理代表派令');
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

/** 「附件A 管理代表派令」那一頁的 HTML */
function adx_mgmt_rep_page_html(PDO $db): string
{
    $i = adx_mgmt_rep_info($db);
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
    $h  = '<h1 style="text-align:center">' . adx_e(ADX_MGMT_REP_TITLE) . '</h1>';
    $h .= '<p style="text-align:center">（2.1　管理代表派令）</p><p><br></p>';
    $h .= '<p style="text-align:center;font-size:20pt"><b>派　　令</b></p><p><br></p>';
    $h .= '<p style="margin-left:2em;margin-right:2em">茲遴派　' . $repTxt . '　為本公司 AS9100 國際品質管理系統之管理代表，'
        . '負責建立、執行與維持品質管理系統，並向管理階層提出品質管理系統的有效成效及待改進事項，'
        . '確保全體人員皆能了解顧客要求，為品質管理系統對外之聯絡人。</p><p><br></p>';
    $h .= '<p style="margin-left:2em">生效日期：' . $effTxt . '</p><p><br></p><p><br></p>';
    $h .= '<table style="width:60%;margin-left:auto;margin-right:auto"><tbody><tr>'
        . '<td style="width:30%;text-align:center;vertical-align:middle"><b>總經理</b></td>'
        . '<td' . $attr . ' style="height:110px;text-align:center;vertical-align:middle">&nbsp;</td>'
        . '</tr></tbody></table>';
    return $h;
}

/** 這一頁是不是「附件A 管理代表派令」（以標題文字判定；清洗器不留自訂屬性，只能靠文字） */
function adx_is_mgmt_rep_page(string $pageHtml): bool
{
    $t = preg_replace('/[\s\x{00A0}\x{3000}]+/u', '', strip_tags($pageHtml));
    return mb_strpos((string)$t, '附件A管理代表派令', 0, 'UTF-8') === 0;
}

/**
 * 一階文件：把內容裡的「附件A 管理代表派令」頁換成依目前設定產生的版本；沒有就補在最後。
 * 非一階、或沒有內容時原樣回傳。
 * @param string $html 文件內容（含頁界標記 <hr style="page-break-after:always">）
 */
function adx_apply(PDO $db, string $html, ?string $docLevel): string
{
    if ((string)$docLevel !== '一階') return $html;
    if (trim($html) === '') return $html;
    try {
        $page  = adx_mgmt_rep_page_html($db);
        $parts = preg_split('#<hr[^>]*page-break-after[^>]*>#i', $html);
        if (!is_array($parts)) return $html;
        $found = false;
        foreach ($parts as $k => $p) {
            if (adx_is_mgmt_rep_page($p)) { $parts[$k] = $page; $found = true; }
        }
        if (!$found) $parts[] = $page;
        return implode("\n" . ADX_PAGE_SEP . "\n", $parts);
    } catch (Throwable $e) {
        return $html;      // 附件頁壞掉不可以讓整份文件打不開
    }
}

} // adx_apply
