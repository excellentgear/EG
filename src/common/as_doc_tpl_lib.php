<?php
/**
 * AS 文件線上版「版面樣板」 ── 唯一實作 ──
 *
 * 產生文件的固定版面：一階封面、文件制修訂紀錄書、目錄、每一頁的頁首與頁尾。
 * 編輯器（views/ADM/as_doc_editor.php）與列印版（as_doc_print.php）**用的是這同一支**，
 * 兩邊各寫一份的話版面與換頁位置一定會走鐘（鐵律4；本模組已經為了排版 CSS 踩過一次）。
 *
 * ── 核心原則（使用者 2026-09-22 定調）────────────────────────────────────
 * 「其他可以自動代入的資料都要全部自動帶入，避免過多人工會失誤」
 * 所以這些版面**一律由資料庫即時產生、使用者不能在內容裡編輯**：
 *   公司中文全名 ← customer_list.customer_full（is_own_company=1）
 *   公司英文全名 ← customer_list.customer_full_en
 *   文件編號/名稱/階別/部門 ← as_document
 *   制修訂紀錄（版別·日期·頁次·摘要）← as_document_version   ★ 本來就已經有這份資料
 *   文件類別 ← 由階別推導（一階＝品質手冊(1)、二階＝程序書(2)…）
 * 使用者只編「正文」，版面不必也不能手打第二份。
 *
 * ── 頁版別怎麼算（使用者問「這有可能自動跳版次嗎」）──────────────────────
 * as_document_version.revised_pages 記的就是「這一次改版動到哪幾頁」（"4"、"全冊"）。
 * 由舊到新掃過每一個版次：寫「全冊」就把所有頁的版別更新成該版次，
 * 寫頁碼（可接受 4、4,5、4-6）就只更新那幾頁。所以每一頁會顯示「最後一次動到它的版次」，
 * 跟紙本的做法一致。完全比對不到的頁一律退回文件目前版次。
 */

if (!defined('EG_AS_DOC_TPL_LIB')) {
define('EG_AS_DOC_TPL_LIB', 1);

require_once __DIR__ . '/date_fmt_lib.php';

/** 頁尾左下的預設字樣（紙本上本來就印這一行） */
define('ADT_FOOT_LEFT_DEFAULT', '(本文件不得擅自塗改或影印)');
/** 制修訂紀錄表至少要有幾列（不足的補空白列，紙本留白給日後手寫） */
define('ADT_REVLOG_MIN_ROWS', 14);

/* ════════════════════════════════════════════════════════════════════════
   設定（逐份文件）
   ⚠ DDL 會造成隱式 commit，一律先 SHOW TABLES 確認、且不在交易中才下
   ════════════════════════════════════════════════════════════════════════ */
function adt_ensure_schema(PDO $db): void
{
    static $done = false;
    if ($done) return;
    try {
        $st = $db->prepare("SHOW TABLES LIKE ?");
        $st->execute(['as_doc_tpl']);
        if ($st->fetchColumn() !== false) { $done = true; return; }
    } catch (Throwable $e) { return; }
    if ($db->inTransaction()) return;
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS as_doc_tpl (
            doc_id INT NOT NULL PRIMARY KEY COMMENT 'as_document.id',
            issue_dept_id INT NULL COMMENT '發行單位（部門）；空＝用文件自己的部門',
            cover_en VARCHAR(255) NULL COMMENT '一階封面中文書名下方的英文（可自行輸入）',
            foot_left VARCHAR(190) NULL COMMENT '頁尾左下字樣；空＝用預設「(本文件不得擅自塗改或影印)」',
            toc_on TINYINT(1) NOT NULL DEFAULT 1 COMMENT '要不要自動產生目錄頁（一階預設要）',
            updated_by INT NULL, updated_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='AS文件線上版的版面樣板設定'");
        $done = true;
    } catch (Throwable $e) { error_log('[adt] ensure_schema: ' . $e->getMessage()); }
}

function adt_settings(PDO $db, int $docId): array
{
    adt_ensure_schema($db);
    $row = null;
    try {
        $st = $db->prepare("SELECT * FROM as_doc_tpl WHERE doc_id=?");
        $st->execute([$docId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {}
    return [
        'doc_id'        => $docId,
        'issue_dept_id' => isset($row['issue_dept_id']) ? (int)$row['issue_dept_id'] : 0,
        'cover_en'      => (string)($row['cover_en'] ?? ''),
        'foot_left'     => (string)($row['foot_left'] ?? ''),
        'toc_on'        => isset($row['toc_on']) ? (int)$row['toc_on'] : 1,
    ];
}

function adt_settings_save(PDO $db, int $docId, array $in, int $uid): bool
{
    adt_ensure_schema($db);
    $cur = adt_settings($db, $docId);
    // array_key_exists 判「有沒有送這個欄位」：沒送＝不要動它（本專案踩過多次）
    $dept = array_key_exists('issue_dept_id', $in) ? (int)$in['issue_dept_id'] : $cur['issue_dept_id'];
    $cvEn = array_key_exists('cover_en', $in)  ? mb_substr(trim((string)$in['cover_en']), 0, 255)  : $cur['cover_en'];
    $foot = array_key_exists('foot_left', $in) ? mb_substr(trim((string)$in['foot_left']), 0, 190) : $cur['foot_left'];
    $toc  = array_key_exists('toc_on', $in)    ? (!empty($in['toc_on']) ? 1 : 0)                   : $cur['toc_on'];
    try {
        $st = $db->prepare("INSERT INTO as_doc_tpl (doc_id, issue_dept_id, cover_en, foot_left, toc_on, updated_by, updated_at)
                            VALUES (?,?,?,?,?,?,NOW())
                            ON DUPLICATE KEY UPDATE issue_dept_id=VALUES(issue_dept_id), cover_en=VALUES(cover_en),
                                foot_left=VALUES(foot_left), toc_on=VALUES(toc_on),
                                updated_by=VALUES(updated_by), updated_at=NOW()");
        $st->execute([$docId, $dept ?: null, $cvEn !== '' ? $cvEn : null, $foot !== '' ? $foot : null, $toc, $uid ?: null]);
        return true;
    } catch (Throwable $e) { error_log('[adt] settings_save: ' . $e->getMessage()); return false; }
}

/* ════════════════════════════════════════════════════════════════════════
   共用資料
   ════════════════════════════════════════════════════════════════════════ */

/** 部門顯示名：組（level>=4）要連上層一起寫，例「資材課 倉管組」（使用者明確要求） */
function adt_dept_label(PDO $db, int $deptId): string
{
    if ($deptId <= 0) return '';
    try {
        $st = $db->prepare("SELECT d.name, d.level, p.name AS pname
                            FROM department d LEFT JOIN department p ON p.id=d.parent_id
                            WHERE d.id=?");
        $st->execute([$deptId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return '';
        $nm = (string)$r['name'];
        if ((int)$r['level'] >= 4 && !empty($r['pname'])) return $r['pname'] . ' ' . $nm;
        return $nm;
    } catch (Throwable $e) { return ''; }
}

/* ════════════════════════════════════════════════════════════════════════
   公版設定（管理員可調整的表格字型／字級／粗細與框線型式）
   ——存在 system_parameters，全站文件共用一份，改一次全部跟著變。
   ════════════════════════════════════════════════════════════════════════ */

/** 可選的字型（只給固定清單：任意字型打錯字就整份文件變預設字體而且不報錯） */
function adt_style_fonts(): array
{
    return [
        ''                => '（跟著內文，不特別指定）',
        '"標楷體","DFKai-SB",serif'            => '標楷體',
        '"新細明體","PMingLiU",serif'          => '新細明體',
        '"微軟正黑體","Microsoft JhengHei",sans-serif' => '微軟正黑體',
        '"Arial",sans-serif'                   => 'Arial',
        '"Times New Roman",serif'              => 'Times New Roman',
    ];
}
/** 框線型式（使用者要求的「框線分類型式」） */
function adt_style_borders(): array
{
    return [
        'solid'  => '實線',
        'double' => '雙線',
        'dashed' => '虛線',
        'dotted' => '點線',
    ];
}
function adt_style_widths(): array
{
    return ['0.5pt' => '細 0.5pt', '1px' => '標準 1px', '1.5pt' => '粗 1.5pt', '2pt' => '特粗 2pt'];
}
function adt_style_colors(): array
{
    return ['#000000' => '黑', '#333333' => '深灰', '#8A5A2B' => '暖棕'];
}

function adt_style_defaults(): array
{
    return ['tbl_font' => '', 'tbl_size' => '', 'tbl_weight' => 'normal',
            'brd_style' => 'solid', 'brd_w' => '1px', 'brd_color' => '#000000',
            'cell_pad' => '4px',
            // 內容大框：把正文整塊框起來，框線與頁首同寬、上下自然對齊
            'body_frame' => '0', 'body_pad' => '3mm'];
}

/** 目前的公版設定（沒設定過就回預設＝與改版前外觀完全相同） */
function adt_style_get(PDO $db): array
{
    static $cache = null;
    if (!empty($GLOBALS['__adt_style_dirty'])) { $cache = null; unset($GLOBALS['__adt_style_dirty']); }
    if ($cache !== null) return $cache;
    $out = adt_style_defaults();
    try {
        $st = $db->prepare("SELECT param_value FROM system_parameters
                            WHERE param_group=? AND param_key=? LIMIT 1");
        $st->execute(['AS_DOC_TPL', 'style']);
        $j = (string)$st->fetchColumn();
        $a = $j !== '' ? json_decode($j, true) : null;
        if (is_array($a)) foreach ($out as $k => $v) if (isset($a[$k])) $out[$k] = (string)$a[$k];
    } catch (Throwable $e) {}
    return $cache = $out;
}

/** 存公版設定；值一律過白名單，不採信前端送什麼就寫什麼（鐵律8） */
function adt_style_save(PDO $db, array $in, int $uid): array
{
    $cur = adt_style_get($db);
    $new = $cur;
    if (array_key_exists('tbl_font', $in)) {
        $v = (string)$in['tbl_font'];
        if (!array_key_exists($v, adt_style_fonts())) return ['ok' => false, 'msg' => '字型不在可選清單內'];
        $new['tbl_font'] = $v;
    }
    if (array_key_exists('tbl_size', $in)) {
        $v = trim((string)$in['tbl_size']);
        if ($v !== '' && !preg_match('/^\d{1,2}(\.\d)?pt$/', $v)) return ['ok' => false, 'msg' => '字級要像 10.5pt 這樣'];
        $new['tbl_size'] = $v;
    }
    if (array_key_exists('tbl_weight', $in)) {
        $v = (string)$in['tbl_weight'];
        if (!in_array($v, ['normal', 'bold'], true)) return ['ok' => false, 'msg' => '粗細只能是一般或粗體'];
        $new['tbl_weight'] = $v;
    }
    if (array_key_exists('brd_style', $in)) {
        $v = (string)$in['brd_style'];
        if (!array_key_exists($v, adt_style_borders())) return ['ok' => false, 'msg' => '框線型式不在清單內'];
        $new['brd_style'] = $v;
    }
    if (array_key_exists('brd_w', $in)) {
        $v = (string)$in['brd_w'];
        if (!array_key_exists($v, adt_style_widths())) return ['ok' => false, 'msg' => '框線粗細不在清單內'];
        $new['brd_w'] = $v;
    }
    if (array_key_exists('brd_color', $in)) {
        $v = strtolower((string)$in['brd_color']);
        if (!array_key_exists($v, adt_style_colors())) return ['ok' => false, 'msg' => '框線顏色不在清單內'];
        $new['brd_color'] = $v;
    }
    if (array_key_exists('body_frame', $in)) {
        $new['body_frame'] = !empty($in['body_frame']) ? '1' : '0';
    }
    if (array_key_exists('body_pad', $in)) {
        $v = trim((string)$in['body_pad']);
        if ($v !== '' && !preg_match('/^\d{1,2}(\.\d)?(mm|px)$/', $v)) return ['ok' => false, 'msg' => '大框內距要像 3mm 這樣'];
        $new['body_pad'] = $v ?: '3mm';
    }
    if (array_key_exists('cell_pad', $in)) {
        $v = trim((string)$in['cell_pad']);
        if ($v !== '' && !preg_match('/^\d{1,2}px$/', $v)) return ['ok' => false, 'msg' => '儲存格內距要像 4px 這樣'];
        $new['cell_pad'] = $v ?: '4px';
    }
    try {
        $st = $db->prepare("INSERT INTO system_parameters (param_group, param_key, param_value, updated_by, updated_at)
                            VALUES ('AS_DOC_TPL','style',?,?,NOW())
                            ON DUPLICATE KEY UPDATE param_value=VALUES(param_value),
                                updated_by=VALUES(updated_by), updated_at=NOW()");
        $st->execute([json_encode($new, JSON_UNESCAPED_UNICODE), (string)$uid]);
        $GLOBALS['__adt_style_dirty'] = 1;
    } catch (Throwable $e) {
        return ['ok' => false, 'msg' => '公版設定存檔失敗：' . $e->getMessage()];
    }
    return ['ok' => true, 'msg' => '公版設定已存檔', 'style' => $new];
}

/**
 * 把公版設定變成一段 CSS 變數覆寫，編輯器與列印版都注入這一段。
 * 只覆寫變數、不動任何規則——所以不會跟 eg_doc_page.css 打架。
 */
function adt_style_css(PDO $db): string
{
    $s = adt_style_get($db);
    $css = [];
    if ($s['tbl_font'] !== '')   $css[] = '--adt-tbl-font:' . $s['tbl_font'];
    if ($s['tbl_size'] !== '')   $css[] = '--adt-tbl-size:' . $s['tbl_size'];
    if ($s['tbl_weight'] !== '') $css[] = '--adt-tbl-weight:' . $s['tbl_weight'];
    $css[] = '--adt-brd-style:' . $s['brd_style'];
    $css[] = '--adt-brd-w:' . $s['brd_w'];
    $css[] = '--adt-brd-color:' . $s['brd_color'];
    if ($s['cell_pad'] !== '')   $css[] = '--adt-cell-pad:' . $s['cell_pad'];
    if (!empty($s['body_frame'])) {
        // 大框用的是同一組框線設定，所以它一定跟頁首那張表看起來是同一套
        $css[] = '--adt-body-brd:' . $s['brd_w'] . ' ' . $s['brd_style'] . ' ' . $s['brd_color'];
        $css[] = '--adt-body-pad:' . ($s['body_pad'] !== '' ? $s['body_pad'] : '3mm');
    }
    return ':root{' . implode(';', $css) . '}';
}

/**
 * 是不是一階（品質手冊）——只有一階有封面頁。
 * as_document.doc_level 存的是中文「一階／二階／四階」不是數字，
 * 判定寫在這裡一處，前端也是拿 API 回傳的旗標，不要在別的地方再比對一次字串。
 */
function adt_is_level1(array $ctx): bool
{
    return ((string)($ctx['doc_level'] ?? '')) === '一階';
}

/** 文件類別顯示字樣：一階＝品質手冊(1)、二階＝程序書(2)、三階＝指導書(3)、四階＝表單(4) */
function adt_kind_label(?string $level): string
{
    switch ((string)$level) {
        case '一階': return '品質手冊(1)';
        case '二階': return '程序書(2)';
        case '三階': return '指導書(3)';
        case '四階': return '表單(4)';
    }
    return (string)$level;
}

/**
 * 組出產生版面所需的全部資料。
 * @return array|null null＝版次不存在
 */
function adt_context(PDO $db, int $versionId): ?array
{
    require_once __DIR__ . '/as_doc_content_lib.php';
    $v = adc_version_info($db, $versionId);
    if (!$v) return null;
    $docId = (int)$v['doc_id'];

    $co = ['full' => '', 'en' => ''];
    try {
        $r = $db->query("SELECT customer_full, customer_full_en FROM customer_list WHERE is_own_company=1 LIMIT 1")
                ->fetch(PDO::FETCH_ASSOC);
        if ($r) { $co['full'] = (string)$r['customer_full']; $co['en'] = (string)$r['customer_full_en']; }
    } catch (Throwable $e) {}

    $vers = [];
    try {
        $st = $db->prepare("SELECT version, revised_date, revised_pages, revised_summary
                            FROM as_document_version WHERE doc_id=?
                            ORDER BY revised_date ASC, id ASC");
        $st->execute([$docId]);
        $vers = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {}

    $cfg = adt_settings($db, $docId);
    $issueDept = $cfg['issue_dept_id'] ?: (int)$v['department_id'];

    return [
        'version_id' => $versionId,
        'doc_id'     => $docId,
        'doc_no'     => (string)$v['doc_no'],
        'doc_name'   => (string)$v['doc_name'],
        'doc_level'  => (string)$v['doc_level'],
        'kind'       => adt_kind_label($v['doc_level']),
        'version'    => (string)$v['version'],
        'rev_date'   => $v['revised_date'] ? eg_fmt_date($v['revised_date']) : '',
        'co_full'    => $co['full'],
        'co_en'      => $co['en'],
        'dept_label' => adt_dept_label($db, (int)$v['department_id']),
        'issue_dept' => adt_dept_label($db, $issueDept),
        'versions'   => $vers,
        'cfg'        => $cfg,
        // 制修訂／審查／核准三格要蓋誰的章：由送簽流程決定，這裡只是把結果帶進版面。
        // 延後 require：版面樣板本身不依賴簽核流程（沒送簽時就是三個空格）。
        'stamps'     => (function () use ($db, $versionId) {
            try {
                require_once __DIR__ . '/as_doc_sign_lib.php';
                return ads_stamp_map($db, $versionId);
            } catch (Throwable $e) {
                return ['draft' => [], 'review' => [], 'approve' => []];
            }
        })(),
    ];
}

/* ════════════════════════════════════════════════════════════════════════
   頁版別
   ════════════════════════════════════════════════════════════════════════ */

/** 把 revised_pages 解析成頁碼陣列；「全冊」等非數字回 null（＝代表整份） */
function adt_parse_pages(?string $s): ?array
{
    $s = trim((string)$s);
    if ($s === '') return [];
    // 全冊／全部／all：整份都更新
    if (preg_match('/全\s*冊|全\s*部|all/iu', $s)) return null;
    // 範圍符號現場實際會寫成 ~ ～ － – —（不是只有半形 -），
    // 全部先正規化成 '-'，否則「39~43」會被當成兩個獨立頁碼、中間 40~42 不會跳版
    //（1-GM-01 的制修訂紀錄就是寫「4、23、39~43」）
    $s = str_replace(['～', '〜', '－', '–', '—', '‐', '~'], '-', $s);
    $out = [];
    foreach (preg_split('/[^\d\-]+/', $s) as $part) {
        $part = trim($part);
        if ($part === '') continue;
        if (preg_match('/^(\d+)\s*-\s*(\d+)$/', $part, $m)) {
            for ($i = (int)$m[1]; $i <= (int)$m[2] && $i - (int)$m[1] < 500; $i++) $out[] = $i;
        } elseif (ctype_digit($part)) {
            $out[] = (int)$part;
        }
    }
    return $out;
}

/**
 * 每一頁的「頁版別」。
 * @param array $versions adt_context()['versions']（已依日期由舊到新）
 * @param int   $pageCount 正文頁數
 * @param string $fallback 比對不到時用的版次（文件目前版次）
 * @return string[] 索引 0 起，對應第 1 頁
 */
function adt_page_versions(array $versions, int $pageCount, string $fallback): array
{
    $out = array_fill(0, max(0, $pageCount), $fallback);
    foreach ($versions as $v) {
        $ver = (string)$v['version'];
        $pages = adt_parse_pages($v['revised_pages'] ?? '');
        if ($pages === null) {                       // 全冊
            for ($i = 0; $i < $pageCount; $i++) $out[$i] = $ver;
        } else {
            foreach ($pages as $p) {
                if ($p >= 1 && $p <= $pageCount) $out[$p - 1] = $ver;
            }
        }
    }
    return $out;
}

/* ════════════════════════════════════════════════════════════════════════
   版面片段（HTML）
   輸出一律經過 htmlspecialchars；這些是系統產生的版面，不走富文字清洗器
   ════════════════════════════════════════════════════════════════════════ */
function adt_e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/**
 * 制修訂紀錄書上的一格簽章。
 * 這裡只輸出「要蓋誰的章」的資料，真正畫成印章是前端 eg_stamp.js 的事
 * （ai-rules/18：簽章一律走共用元件，不可只印姓名文字）——
 * 編輯器與列印版都會跑 egDocStamps() 把這些格子填起來。
 * 還沒簽的關卡留空格，紙本照樣印得出來、手簽也可以。
 */
function adt_sign_cell(array $ctx, string $stage): string
{
    $list = $ctx['stamps'][$stage] ?? [];
    if (!$list) return '<td data-sign="' . $stage . '">&nbsp;</td>';
    $attr = '';
    $s = $list[0];                       // 一格只蓋一個章；同一關多人時蓋最先簽的那一位
    foreach (['name' => 'name', 'dept' => 'dept', 'pos' => 'pos'] as $k => $a) {
        $attr .= ' data-stamp-' . $a . '="' . adt_e((string)($s[$k] ?? '')) . '"';
    }
    $attr .= ' data-stamp-date="' . adt_e($s['date'] ? eg_fmt_date($s['date']) : '') . '"';
    $more = count($list) > 1 ? ' data-stamp-more="' . (count($list) - 1) . '"' : '';
    return '<td data-sign="' . $stage . '"' . $attr . $more . '>&nbsp;</td>';
}

/** 一階封面（使用者指定：上方公司中文、第二列英文、下方外框內為文件名稱與可自填英文） */
function adt_cover_html(array $ctx): string
{
    $en = trim((string)$ctx['cfg']['cover_en']);
    return '<div class="adt-cover">'
         . '<div class="adt-cover-co">' . adt_e($ctx['co_full']) . '</div>'
         . '<div class="adt-cover-coen">' . adt_e($ctx['co_en']) . '</div>'
         . '<div class="adt-cover-box">'
         . '<div class="adt-cover-name">' . adt_e($ctx['doc_name']) . '</div>'
         . ($en !== '' ? '<div class="adt-cover-nameen">' . adt_e($en) . '</div>' : '')
         . '</div>'
         . '</div>';
}

/** 文件制修訂紀錄書（資料直接來自 as_document_version，不必也不可手打第二份） */
function adt_revlog_html(array $ctx): string
{
    $h = '<div class="adt-rev">'
       . '<div class="adt-rev-co">' . adt_e($ctx['co_full']) . '</div>'
       . '<div class="adt-rev-coen">' . adt_e($ctx['co_en']) . '</div>'
       . '<div class="adt-rev-ttl">文件制修訂紀錄書</div>';

    /* 抬頭與下面的制修訂紀錄共用同一組四欄格線（14/20/16/50）：
       文件名稱那一格跨前三欄，所以它的右邊框線正好落在「制修訂摘要」欄的起點，
       上下對得起來（使用者：底下框線務必要跟上方對齊）。 */
    $cols = '<colgroup><col class="adt-rc1"><col class="adt-rc2"><col class="adt-rc3"><col class="adt-rc4"></colgroup>';
    $h .= '<table class="adt-rev-head">' . $cols . '<tr>'
        . '<td rowspan="2" colspan="3" class="adt-rev-name">' . adt_e($ctx['doc_name']) . '</td>'
        . '<td>文件編號：' . adt_e($ctx['doc_no']) . '</td></tr>'
        . '<tr><td>文件類別：' . adt_e($ctx['kind']) . '</td></tr></table>';

    // 制修訂紀錄本體（與抬頭同一組格線）
    $h .= '<table class="adt-rev-tbl">' . $cols
        . '<tr><td colspan="4" class="adt-rev-cap">制　修　訂　紀　錄</td></tr>'
        . '<tr><th>文件版別</th><th>制修訂日期</th><th>制修訂頁次</th><th>制修訂摘要（增、減、改、廢項目）</th></tr>';
    $n = 0;
    foreach ($ctx['versions'] as $v) {
        $h .= '<tr><td>' . adt_e($v['version']) . '</td>'
            . '<td>' . adt_e($v['revised_date'] ? eg_fmt_date($v['revised_date']) : '') . '</td>'
            . '<td>' . adt_e($v['revised_pages']) . '</td>'
            . '<td class="adt-l">' . adt_e($v['revised_summary']) . '</td></tr>';
        $n++;
    }
    for (; $n < ADT_REVLOG_MIN_ROWS; $n++) {
        $h .= '<tr><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td></tr>';
    }
    $h .= '</table>';

    // 發行單位與簽章欄（簽章人由簽核流程填，這裡先留格）
    $h .= '<table class="adt-rev-foot">'
        . '<colgroup><col class="adt-fc1"><col class="adt-fc2"><col class="adt-fc3"><col class="adt-fc4"></colgroup>'
        . '<tr><td colspan="4" class="adt-l">發行單位：' . adt_e($ctx['issue_dept']) . '</td></tr>'
        . '<tr><th>制修訂部門</th><th>制修訂</th><th>審查</th><th>核准</th></tr>'
        . '<tr class="adt-sign"><td>' . adt_e($ctx['dept_label']) . '</td>'
        . adt_sign_cell($ctx, 'draft') . adt_sign_cell($ctx, 'review') . adt_sign_cell($ctx, 'approve')
        . '</tr></table>';

    return $h . '</div>';
}

/** 目錄（由正文各頁的標題產生；$pages＝各頁 HTML，$startNo＝正文第一頁的頁碼） */
function adt_toc_html(array $ctx, array $pages, int $startNo): string
{
    $items = [];
    foreach ($pages as $i => $html) {
        if (!preg_match_all('#<h([1-4])[^>]*>(.*?)</h\1>#is', (string)$html, $m, PREG_SET_ORDER)) continue;
        foreach ($m as $one) {
            $txt = trim(preg_replace('/\s+/u', ' ', strip_tags($one[2])));
            if ($txt === '') continue;
            $items[] = ['lv' => (int)$one[1], 'txt' => $txt, 'page' => $startNo + $i];
        }
    }
    $h = '<div class="adt-toc"><div class="adt-toc-ttl">目　錄　Index</div>';
    if (!$items) {
        $h .= '<div class="adt-toc-empty">正文裡還沒有標題。'
            . '把段落設成「標題 1～標題 4」之後，目錄會自動列出來（存檔後更新）。</div>';
    } else {
        $h .= '<table class="adt-toc-tbl">';
        foreach ($items as $it) {
            $h .= '<tr><td class="adt-toc-t adt-toc-lv' . $it['lv'] . '">' . adt_e($it['txt']) . '</td>'
                . '<td class="adt-toc-p">' . $it['page'] . '</td></tr>';
        }
        $h .= '</table>';
    }
    return $h . '</div>';
}

/** 每一頁的頁首（二階第二頁起、一階正文頁都用這個）。
 *  $pageNo/$total 刻意不限定成 int：編輯器要的是帶 {{PAGE}} 佔位符的樣板，
 *  這樣版面的 HTML 結構只有這一份，前端只做字串代入、不會再組一次版面。 */
function adt_header_html(array $ctx, $pageNo, $total, string $pageVer): string
{
    /* 四欄固定格線：[文件名稱標題][文件名稱內容][項目][值]
       公司名與「文件名稱」那一格各自跨欄，所以**每一條直框線都落在同一個位置**，
       上下兩列不會像原本那樣各切各的（使用者：底下框線務必要跟上方對齊）。 */
    return '<table class="adt-hdr">'
         . '<colgroup><col class="adt-c1"><col class="adt-c2"><col class="adt-c3"><col class="adt-c4"></colgroup>'
         . '<tr>'
         . '<td class="adt-hdr-co" rowspan="2" colspan="2">'
         . '<div class="adt-hdr-coen">' . adt_e($ctx['co_en']) . '</div>'
         . '<div class="adt-hdr-cozh">' . adt_e($ctx['co_full']) . '</div>'
         . '</td>'
         . '<td class="adt-hdr-k">文件編號</td><td class="adt-hdr-v">' . adt_e($ctx['doc_no']) . '</td></tr>'
         . '<tr><td class="adt-hdr-k">頁　　次</td><td class="adt-hdr-v">' . adt_e($pageNo) . ' / ' . adt_e($total) . '</td></tr>'
         // 文件名稱與內容分兩格（使用者指定：文件名稱 ｜ 文件管理程序，內容佔大格）
         . '<tr><td class="adt-hdr-nk">文件名稱</td>'
         . '<td class="adt-hdr-nm">' . adt_e($ctx['doc_name']) . '</td>'
         . '<td class="adt-hdr-k">頁 版 別</td><td class="adt-hdr-v">' . adt_e($pageVer) . '</td></tr>'
         . '</table>';
}

/** 每一頁的頁尾：左下可設定字樣、右下固定 AS 文件編號 */
function adt_footer_html(array $ctx): string
{
    $left = trim((string)$ctx['cfg']['foot_left']);
    if ($left === '') $left = ADT_FOOT_LEFT_DEFAULT;
    return '<table class="adt-ftr"><tr>'
         . '<td class="adt-l">' . adt_e($left) . '</td>'
         . '<td class="adt-r">' . adt_e($ctx['doc_no']) . '</td>'
         . '</tr></table>';
}

/**
 * 系統頁（封面／制修訂紀錄書／目錄）。回傳每一項 ['key'=>,'label'=>,'html'=>]
 * 一階：封面 → 制修訂紀錄書 → 目錄
 * 其他：制修訂紀錄書（目錄依設定）
 */
function adt_system_pages(array $ctx, array $contentPages): array
{
    $out = [];
    if (adt_is_level1($ctx)) $out[] = ['key' => 'cover', 'label' => '封面', 'html' => adt_cover_html($ctx)];
    $out[] = ['key' => 'revlog', 'label' => '文件制修訂紀錄書', 'html' => adt_revlog_html($ctx)];
    // 目錄**只有一階（品質手冊）才有**（2026-09-22 使用者指定：二階文件不需要目錄）。
    // 設定值仍然保留，但二階以下一律不產生——否則舊設定會讓程序書冒出一頁空目錄。
    if (adt_is_level1($ctx) && !empty($ctx['cfg']['toc_on'])) {
        // 目錄本身也佔一頁，所以正文從「系統頁數＋1」開始編號
        $startNo = count($out) + 2;
        $out[] = ['key' => 'toc', 'label' => '目錄', 'html' => adt_toc_html($ctx, $contentPages, $startNo)];
    }
    return $out;
}

/** 把存起來的內容依分頁標記切成各頁（與 eg_richtext.js 的頁界同一個標記） */
function adt_split_pages(?string $html): array
{
    $h = trim((string)$html);
    if ($h === '') return [];
    $parts = preg_split('#<hr[^>]*page-break-after[^>]*>#i', $h);
    return is_array($parts) ? $parts : [$h];
}

} // EG_AS_DOC_TPL_LIB
