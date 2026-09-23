<?php
/**
 * as_doc_ref_lib.php — AS 文件線上版「內文引用的文件編號」自動維護（唯一實作）
 * 建立：2026-09-22（使用者交辦）
 *
 * 解決的問題：一階／二階文件做成線上版之後，內文裡會寫滿別份文件的編號
 * （實測 2-DC-01 文件管理程序的線上版就引用了 6 種、19 處）。
 * 哪一天那份被引用的文件**改了編號或被廢止**，這裡的內文不會自己跟著變，
 * 而且**完全不報錯**——印出來就是一份指向不存在編號的程序書，稽核一問就破。
 *
 * 流程（使用者 2026-09-22 以 AskUserQuestion 逐項拍板）：
 *   ①觸發來源＝**編號變更＋廢止**兩種（這兩種才會讓引用真的失效）
 *   ②編號變更→系統自動算好新內文；**廢止→只標示不自動改**
 *     （廢止沒有「新編號」可換，要改指到別份文件還是整段刪掉只有人知道）
 *   ③套用前一定要讓人看到**每一處改在哪、改成什麼**，逐處可勾可退
 *   ④套用＝**改目前版次的內容**（不另外複製一份內容），並在制修訂紀錄書補一列：
 *      版別＝小數點後 +1、修訂日期＝來源異動日期、頁次＝實際改到的頁、摘要＝增／減／改／廢了什麼
 *   ⑤**送審／自動簽核這一輪不做**——線上版自己的簽核流程（制修訂紀錄書上那三格）
 *      還沒實作，使用者指示「等 as_doc_editor.php 完成送審後再對接」。
 *      對接點已經留好：adr_apply() 回傳新版次 id，簽核流程做好之後在那裡接上即可。
 *
 * 為什麼「補一列」是新增 as_document_version 而不是改 HTML：
 *   制修訂紀錄書是 as_doc_tpl_lib.php 的 **系統頁**（adt_revlog_pages_html），
 *   內容直接來自 as_document_version 的版次列，不是存在 content_html 裡。
 *   所以「制修訂紀錄多一列」＝新增一筆版次列，不要去動內文的 HTML。
 */
if (!function_exists('adr_ensure_schema')) {

require_once __DIR__ . '/as_doc_content_lib.php';
require_once __DIR__ . '/as_doc_tpl_lib.php';

/** 一次掃描最多處理幾份線上版（防呆，正常遠低於此）。
 *  用 define 不用 const：本檔整個包在 if (!function_exists(...)) 之下，const 在區塊裡是語法錯誤。 */
if (!defined("ADR_MAX_TARGETS")) define("ADR_MAX_TARGETS", 500);

/* ════════════════════════════════════════════════════════════════════════
   Schema
   ════════════════════════════════════════════════════════════════════════ */

/**
 * 建表。**先 SHOW TABLES 確認不存在、且不在交易中才下 DDL**——
 * CREATE TABLE 即使加了 IF NOT EXISTS 也會造成 MySQL 隱式 commit，
 * 在交易裡建表會讓外層 commit() 爆「There is no active transaction」，
 * 而且資料其實已經寫進去了、畫面卻顯示失敗（本專案已踩三次）。
 */
function adr_ensure_schema(PDO $db): void
{
    static $done = false;
    if ($done) return;
    try {
        $st = $db->query("SHOW TABLES LIKE 'as_doc_ref_change'");
        if ($st && $st->fetchColumn()) { $done = true; return; }
        if ($db->inTransaction()) return;
    } catch (Throwable $e) { return; }
    $done = true;
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS as_doc_ref_change (
            id INT AUTO_INCREMENT PRIMARY KEY,
            target_doc_id INT NOT NULL COMMENT '要改的線上版文件 as_document.id',
            target_version_id INT NOT NULL COMMENT '偵測當下內容掛的版次',
            src_doc_id INT NULL COMMENT '被改的那份文件',
            src_kind VARCHAR(12) NOT NULL COMMENT 'renumber=編號變更／obsolete=廢止',
            src_old_no VARCHAR(40) NOT NULL COMMENT '內文裡現在寫的編號',
            src_new_no VARCHAR(40) NULL COMMENT '要換成的編號（廢止時為 NULL＝不自動改）',
            src_date DATE NULL COMMENT '來源異動的業務日期（＝補上去那一列的修訂日期）',
            src_note VARCHAR(255) NULL COMMENT '來源說明（文件名稱等）',
            hits INT NOT NULL DEFAULT 0 COMMENT '命中處數',
            pages VARCHAR(120) NULL COMMENT '命中的正文頁碼，逗號分隔',
            hits_json MEDIUMTEXT NULL COMMENT '逐處明細（頁次／前後文／新舊）',
            status VARCHAR(10) NOT NULL DEFAULT 'pending' COMMENT 'pending／applied／dismissed',
            applied_version_id INT NULL COMMENT '套用後補出來的版次列',
            applied_summary VARCHAR(500) NULL,
            created_at DATETIME NOT NULL,
            decided_by INT NULL, decided_by_name VARCHAR(60) NULL, decided_at DATETIME NULL,
            KEY idx_target (target_doc_id, status),
            KEY idx_ver (target_version_id, status),
            KEY idx_src (src_doc_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='線上版內文引用的文件編號待處理變更'");
    } catch (Throwable $e) { error_log('[adr] ensure_schema: ' . $e->getMessage()); }
}

/* ════════════════════════════════════════════════════════════════════════
   版別：小數點後 +1
   ════════════════════════════════════════════════════════════════════════ */

/**
 * 下一個版別＝**小數點後 +1**（使用者 2026-09-22 指定的說法）。
 *   2.0 → 2.1　　2.9 → 2.10　　A → A.1　　A.1 → A.2　　1.1 → 1.2
 * 全庫版別字串是混用的（實測：空白 81、2.0 有 29、A 有 27、1.1 有 6…），
 * 所以規則寫成「有小數尾就把它當整數 +1，沒有就接 .1」，字母型與數字型都吃得下。
 * 空字串＝這份文件還沒有版別（全庫 81 份是這樣），退回 '1.1'
 * ——不能回 '.1'，那在制修訂紀錄書上看起來像漏印。
 */
function adr_next_version(string $cur): string
{
    $cur = trim($cur);
    if ($cur === '') return '1.1';
    if (preg_match('/^(.*?)\.(\d+)$/u', $cur, $m)) {
        return $m[1] . '.' . ((int)$m[2] + 1);
    }
    return $cur . '.1';
}

/* ════════════════════════════════════════════════════════════════════════
   比對與替換
   ════════════════════════════════════════════════════════════════════════ */

/**
 * 「這個編號」在純文字裡的比對樣式。
 *
 * **邊界條件是這支最重要的地方**：2-DC-01 的線上版內文裡同時有 `2-DC-01`（14 處）
 * 與 `2-DC-01-01`、`2-DC-01-02`…，直接字串取代會把子文件編號腰斬成
 * `2-SM-09-01`（前半被換掉、後半留著），而且看起來很像對的、不會報錯。
 * 所以右邊界要同時排除「後面接英數」與「後面接 -數字」兩種。
 */
function adr_pattern(string $no): string
{
    return '/(?<![0-9A-Za-z\-])' . preg_quote($no, '/') . '(?![0-9A-Za-z]|-\d)/u';
}

/** 純文字裡有幾處命中 */
function adr_count_in_text(string $text, string $no): int
{
    if ($no === '' || $text === '') return 0;
    return (int)preg_match_all(adr_pattern($no), $text, $m);
}

/**
 * 只在**文字節點**上做替換，不碰標籤與屬性。
 * 用字串取代整份 HTML 會連 `<img src="…2-TD-01-02…">`、class、註解一起改到；
 * 走 DOM 只改看得到的字，才不會把版面或資產引用弄壞。
 *
 * @return array ['html'=>替換後, 'count'=>換了幾處]
 */
function adr_replace_in_html(string $html, string $old, string $new): array
{
    if ($html === '' || $old === '') return ['html' => $html, 'count' => 0];

    $doc  = new DOMDocument('1.0', 'UTF-8');
    $prev = libxml_use_internal_errors(true);
    $ok = $doc->loadHTML('<?xml encoding="UTF-8"><div id="adr-root">' . $html . '</div>',
                         LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if (!$ok) return ['html' => $html, 'count' => 0];

    $root = $doc->getElementById('adr-root') ?: $doc->documentElement;
    if (!$root) return ['html' => $html, 'count' => 0];

    $pat = adr_pattern($old);
    $cnt = 0;
    $xp  = new DOMXPath($doc);
    foreach ($xp->query('.//text()', $root) as $t) {
        /** @var DOMText $t */
        $n = 0;
        $s = preg_replace($pat, $new, $t->nodeValue, -1, $n);
        if ($n > 0) { $t->nodeValue = $s; $cnt += $n; }
    }
    if ($cnt === 0) return ['html' => $html, 'count' => 0];

    $out = '';
    foreach ($root->childNodes as $c) { $out .= $doc->saveHTML($c); }
    return ['html' => trim($out), 'count' => $cnt];
}

/** HTML → 純文字（比對與前後文用；標籤之間補空白，免得兩段字黏成一個假的編號） */
function adr_text(string $html): string
{
    $t = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/isu', ' ', $html);
    $t = preg_replace('/<[^>]*>/u', ' ', (string)$t);
    $t = html_entity_decode((string)$t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('/\s+/u', ' ', (string)$t));
}

/**
 * 逐頁找出命中處與前後文。
 * 頁碼用**正文頁碼（1 起算）**，與 adt_page_versions()／adt_parse_pages() 同一套口徑
 * ——制修訂頁次要能讓「頁版別」對得上，不能用列印時含封面目錄的那個頁碼。
 * （列印頁碼＝系統頁數＋正文頁碼，確認畫面上兩個都會顯示，免得使用者對不起來。）
 *
 * @return array [['page'=>int,'ctx'=>前後文,'old'=>舊,'new'=>新|null], …]
 */
function adr_find_hits(string $html, string $old, ?string $new): array
{
    $hits  = [];
    $pages = adt_split_pages($html);
    if (!$pages) $pages = [$html];
    foreach ($pages as $i => $pageHtml) {
        $txt = adr_text((string)$pageHtml);
        if ($txt === '') continue;
        if (!preg_match_all(adr_pattern($old), $txt, $m, PREG_OFFSET_CAPTURE)) continue;
        foreach ($m[0] as $one) {
            $pos = (int)$one[1];
            // 前後各取 40 字當前後文（用 byte offset 換算成字元，中文才不會切一半）
            $before = mb_substr(substr($txt, 0, $pos), -40, null, 'UTF-8');
            $after  = mb_substr(substr($txt, $pos + strlen($old)), 0, 40, 'UTF-8');
            $hits[] = ['page' => $i + 1, 'before' => $before, 'after' => $after,
                       'old' => $old, 'new' => $new];
        }
    }
    return $hits;
}

/* ════════════════════════════════════════════════════════════════════════
   偵測：來源文件異動時掃描所有線上版
   ════════════════════════════════════════════════════════════════════════ */

/**
 * 來源文件（編號變更或廢止）異動時，掃描所有線上版內文並建立待處理列。
 *
 * @param array $src ['kind'=>'renumber|obsolete', 'doc_id'=>被改的文件, 'old_no'=>, 'new_no'=>(renumber才有),
 *                    'date'=>業務日期, 'note'=>說明]
 * @return array ['targets'=>建立幾筆, 'hits'=>總命中處數, 'rows'=>[[doc_no,hits],…]]
 */
function adr_scan_on_change(PDO $db, array $src): array
{
    adr_ensure_schema($db);
    $kind  = ($src['kind'] ?? '') === 'obsolete' ? 'obsolete' : 'renumber';
    $oldNo = trim((string)($src['old_no'] ?? ''));
    $newNo = $kind === 'renumber' ? trim((string)($src['new_no'] ?? '')) : '';
    $out   = ['targets' => 0, 'hits' => 0, 'rows' => []];
    if ($oldNo === '' || ($kind === 'renumber' && ($newNo === '' || $newNo === $oldNo))) return $out;

    try {
        // 只掃「這份文件目前版次的線上版」；歷史版次是發行紀錄，不可以回頭改
        $st = $db->prepare("SELECT c.id content_id, c.version_id, c.doc_id, c.content_html,
                                   d.doc_no, d.doc_name
                            FROM as_doc_content c
                            JOIN as_document d ON d.id = c.doc_id
                            WHERE d.is_deleted=0 AND COALESCE(d.is_obsolete,0)=0
                              AND c.version_id = d.current_version_id
                              AND c.content_html IS NOT NULL AND c.content_html <> ''
                            LIMIT " . ADR_MAX_TARGETS);
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { error_log('[adr] scan: ' . $e->getMessage()); return $out; }

    $srcDocId = (int)($src['doc_id'] ?? 0);
    $ins = $db->prepare("INSERT INTO as_doc_ref_change
        (target_doc_id, target_version_id, src_doc_id, src_kind, src_old_no, src_new_no,
         src_date, src_note, hits, pages, hits_json, status, created_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?, 'pending', NOW())");

    foreach ($rows as $r) {
        // 被改的那份文件如果自己就有線上版，不必掃自己（改的是它的編號，內文引用自己時照樣要換，
        // 所以這裡刻意**不**跳過，只有完全沒命中才略過）
        $hits = adr_find_hits((string)$r['content_html'], $oldNo, $newNo !== '' ? $newNo : null);
        if (!$hits) continue;
        $pages = array_values(array_unique(array_column($hits, 'page')));
        sort($pages);
        try {
            $ins->execute([
                (int)$r['doc_id'], (int)$r['version_id'], $srcDocId ?: null, $kind,
                $oldNo, $newNo !== '' ? $newNo : null,
                ($src['date'] ?? null) ?: null, mb_substr((string)($src['note'] ?? ''), 0, 255),
                count($hits), implode(',', $pages),
                json_encode($hits, JSON_UNESCAPED_UNICODE),
            ]);
        } catch (Throwable $e) { error_log('[adr] insert: ' . $e->getMessage()); continue; }
        $out['targets']++;
        $out['hits'] += count($hits);
        $out['rows'][] = ['doc_no' => $r['doc_no'], 'doc_name' => $r['doc_name'], 'hits' => count($hits)];
    }
    return $out;
}

/* ════════════════════════════════════════════════════════════════════════
   查詢
   ════════════════════════════════════════════════════════════════════════ */

/** 這個版次還沒處理的引用變更 */
function adr_pending_for_version(PDO $db, int $versionId): array
{
    adr_ensure_schema($db);
    if ($versionId <= 0) return [];
    try {
        $st = $db->prepare("SELECT * FROM as_doc_ref_change
                            WHERE target_version_id=? AND status='pending' ORDER BY id");
        $st->execute([$versionId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { return []; }
    foreach ($rows as &$r) $r['hits_list'] = json_decode((string)$r['hits_json'], true) ?: [];
    return $rows;
}

/** 全站還沒處理的筆數（AS 文件管理清單上要標） */
function adr_pending_count_by_doc(PDO $db): array
{
    adr_ensure_schema($db);
    try {
        $st = $db->query("SELECT target_doc_id, COUNT(*) n, SUM(hits) h
                          FROM as_doc_ref_change WHERE status='pending' GROUP BY target_doc_id");
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['target_doc_id']] = ['changes' => (int)$r['n'], 'hits' => (int)$r['h']];
        }
        return $out;
    } catch (Throwable $e) { return []; }
}

/* ════════════════════════════════════════════════════════════════════════
   預覽：把要改的地方標出來（**不寫進內容**，只給確認畫面看）
   ════════════════════════════════════════════════════════════════════════ */

/**
 * 產生「標示過」的內文：舊編號包成 <del class="adr-old">、新編號包成 <ins class="adr-new">；
 * 廢止（沒有新編號）只包 <mark class="adr-warn">。
 *
 * **標示只存在於預覽**，永遠不會寫進 as_doc_content——把 <ins>/<del> 存進正本，
 * 列印出來就會看到一堆刪除線，而且下一次再標示會層層疊上去。
 *
 * @param array $changes adr_pending_for_version() 的列（可只傳要預覽的那幾筆）
 * @return array ['html'=>標示過的內文, 'marks'=>標了幾處]
 */
function adr_mark_html(string $html, array $changes): array
{
    if ($html === '') return ['html' => '', 'marks' => 0];
    $doc  = new DOMDocument('1.0', 'UTF-8');
    $prev = libxml_use_internal_errors(true);
    $ok = $doc->loadHTML('<?xml encoding="UTF-8"><div id="adr-root">' . $html . '</div>',
                         LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if (!$ok) return ['html' => $html, 'marks' => 0];
    $root = $doc->getElementById('adr-root') ?: $doc->documentElement;
    if (!$root) return ['html' => $html, 'marks' => 0];

    $marks = 0;
    $xp = new DOMXPath($doc);
    foreach ($changes as $ch) {
        $old = (string)$ch['src_old_no'];
        $new = (string)($ch['src_new_no'] ?? '');
        $pat = adr_pattern($old);
        // 每一筆都重抓一次 text node：上一筆可能已經插入了新節點
        foreach (iterator_to_array($xp->query('.//text()', $root)) as $t) {
            /** @var DOMText $t */
            $val = $t->nodeValue;
            if (!preg_match($pat, $val)) continue;
            $parts = preg_split($pat, $val, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_DELIM_CAPTURE);
            // preg_split 會把符合的部分吃掉，所以自己逐段重建
            $frag = $doc->createDocumentFragment();
            $off  = 0;
            if (!preg_match_all($pat, $val, $m, PREG_OFFSET_CAPTURE)) continue;
            foreach ($m[0] as $one) {
                $pos = (int)$one[1];
                if ($pos > $off) $frag->appendChild($doc->createTextNode(substr($val, $off, $pos - $off)));
                if ($new !== '') {
                    $d = $doc->createElement('del', $old); $d->setAttribute('class', 'adr-old');
                    $n = $doc->createElement('ins', $new); $n->setAttribute('class', 'adr-new');
                    $frag->appendChild($d); $frag->appendChild($n);
                } else {
                    $w = $doc->createElement('mark', $old); $w->setAttribute('class', 'adr-warn');
                    $frag->appendChild($w);
                }
                $marks++;
                $off = $pos + strlen($old);
            }
            if ($off < strlen($val)) $frag->appendChild($doc->createTextNode(substr($val, $off)));
            $t->parentNode->replaceChild($frag, $t);
        }
    }
    $out = '';
    foreach ($root->childNodes as $c) { $out .= $doc->saveHTML($c); }
    return ['html' => trim($out), 'marks' => $marks];
}

/* ════════════════════════════════════════════════════════════════════════
   套用
   ════════════════════════════════════════════════════════════════════════ */

/**
 * 摘要文字（制修訂摘要欄）。使用者指定要寫「增／減／改／廢 哪幾小段內容／文件編號」。
 * 這一版產生的都是「改」（編號變更）與「廢」（引用到已廢止的文件），
 * 增／減留給日後人工編輯時自己填。
 */
function adr_summary_text(array $applied): string
{
    $chg = []; $obs = [];
    foreach ($applied as $a) {
        $p = $a['pages'] !== '' ? '第' . str_replace(',', '、', $a['pages']) . '頁' : '';
        if (($a['kind'] ?? '') === 'obsolete') $obs[] = $p . ' ' . $a['old'] . '（已廢止）';
        else $chg[] = $p . ' ' . $a['old'] . '→' . $a['new'];
    }
    $s = [];
    if ($chg) $s[] = '改：' . implode('；', $chg);
    if ($obs) $s[] = '廢：' . implode('；', $obs);
    return mb_substr(implode('　', $s), 0, 500, 'UTF-8');
}

/**
 * 套用選定的幾筆引用變更。
 *
 * 使用者拍板的落地方式是「**改目前版次、只補一列紀錄**」，所以這裡：
 *   ①直接改內容（不複製第二份內容，也不 fork 資產）
 *   ②在 as_document_version 補一列（版別小數點+1／日期＝來源異動日／頁次／摘要）
 *   ③把內容與 as_document 的目前版次**指到新補的那一列**
 *      ——制修訂紀錄書多一列、頁首版別跟著變成 2.1，兩邊才不會一個寫 2.0 一個寫 2.1。
 *      舊版次列仍留在制修訂紀錄上（它本來就是歷史），只是不再掛著內容。
 *
 * 廢止類（沒有新編號）一律**不動內文**，只計入摘要的「廢」——使用者明確要求只標示不自動改。
 *
 * @param int[] $changeIds 要套用的 as_doc_ref_change.id
 * @return array ['ok'=>bool,'msg'=>string,'version_id'=>新版次,'version'=>新版別,'replaced'=>換了幾處]
 */
function adr_apply(PDO $db, int $versionId, array $changeIds, int $uid, string $uname): array
{
    adr_ensure_schema($db);
    $ids = array_values(array_unique(array_filter(array_map('intval', $changeIds))));
    if (!$ids) return ['ok' => false, 'msg' => '請先勾選要套用的項目'];

    $C = adc_content_by_version($db, $versionId);
    if (!$C) return ['ok' => false, 'msg' => '找不到這個版次的線上版內容'];
    $V = adc_version_info($db, $versionId);
    if (!$V) return ['ok' => false, 'msg' => '找不到這個版次'];

    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $db->prepare("SELECT * FROM as_doc_ref_change
                        WHERE id IN ($in) AND target_version_id=? AND status='pending'");
    $st->execute(array_merge($ids, [$versionId]));
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return ['ok' => false, 'msg' => '勾選的項目已被處理過，請重新整理頁面確認'];

    $html     = (string)$C['content_html'];
    $replaced = 0;
    $applied  = [];
    foreach ($rows as $r) {
        $old = (string)$r['src_old_no'];
        $new = (string)($r['src_new_no'] ?? '');
        if ($r['src_kind'] === 'obsolete' || $new === '') {
            // 廢止：不動內文，只記進摘要讓制修訂紀錄看得到「這一版確認過這件事」
            $applied[] = ['kind' => 'obsolete', 'old' => $old, 'new' => '', 'pages' => (string)$r['pages']];
            continue;
        }
        $res = adr_replace_in_html($html, $old, $new);
        // 重新算一次實際改到的頁（掃描到套用之間內容可能被編輯過，頁次要以**當下**為準）
        $pagesNow = [];
        foreach (adr_find_hits($html, $old, $new) as $h) $pagesNow[$h['page']] = true;
        $pagesNow = array_keys($pagesNow); sort($pagesNow);
        $html = $res['html'];
        $replaced += $res['count'];
        $applied[] = ['kind' => 'renumber', 'old' => $old, 'new' => $new,
                      'pages' => implode(',', $pagesNow) ?: (string)$r['pages'], 'n' => $res['count']];
    }

    // 修訂日期＝來源異動的業務日期（使用者指定），多筆取最新的一個；都沒有才退回今天
    $date = '';
    foreach ($rows as $r) { $d = (string)($r['src_date'] ?? ''); if ($d !== '' && $d > $date) $date = $d; }
    if ($date === '') { try { $date = (string)$db->query("SELECT CURDATE()")->fetchColumn(); } catch (Throwable $e) { $date = date('Y-m-d'); } }

    // 制修訂頁次：只列真的有改到內文的頁（廢止那種沒動內文，頁次仍列出來供追溯）
    $pageSet = [];
    foreach ($applied as $a) foreach (explode(',', (string)$a['pages']) as $p) { $p = trim($p); if ($p !== '') $pageSet[(int)$p] = true; }
    $pageList = array_keys($pageSet); sort($pageList);
    $newVer   = adr_next_version((string)$V['version']);
    $summary  = adr_summary_text($applied);

    try {
        $db->beginTransaction();

        // ① 補一列制修訂紀錄（＝as_document_version 新列，制修訂紀錄書是由它產生的系統頁）
        $db->prepare("INSERT INTO as_document_version
            (doc_id, version, change_status, revised_date, revised_pages, revised_summary,
             doc_level_snapshot, department_id_snapshot, uploaded_by, uploaded_at)
            VALUES (?,?,'修正',?,?,?,?,?,?,NOW())")
           ->execute([(int)$V['doc_id'], $newVer, $date,
                      implode(',', $pageList), $summary,
                      (string)$V['doc_level'], (int)$V['department_id'], $uname]);
        $newVid = (int)$db->lastInsertId();

        // ② 內容就地改，並把它掛到新補的版次列（不複製第二份內容＝使用者選的「改目前版次」）
        $clean = eg_richtext_sanitize($html, ADC_MAX_LEN, 'doc');
        $db->prepare("UPDATE as_doc_content SET content_html=?, version_id=?, updated_by=?, updated_at=NOW() WHERE id=?")
           ->execute([$clean !== '' ? $clean : null, $newVid, $uid ?: null, (int)$C['id']]);

        // ③ 文件的目前版次跟著走，否則頁首印 2.0、制修訂紀錄卻多了一列 2.1
        $db->prepare("UPDATE as_document SET current_version=?, current_version_id=?, updated_at=NOW() WHERE id=?")
           ->execute([$newVer, $newVid, (int)$V['doc_id']]);

        // ④ 結案；同一版次其他還沒處理的不動（使用者可能刻意留著之後再處理），
        //    但 target_version_id 要跟著搬到新版次，不然重新整理後就找不到它們了
        $db->prepare("UPDATE as_doc_ref_change SET status='applied', applied_version_id=?, applied_summary=?,
                      decided_by=?, decided_by_name=?, decided_at=NOW() WHERE id IN ($in)")
           ->execute(array_merge([$newVid, $summary, $uid ?: null, $uname], $ids));
        $db->prepare("UPDATE as_doc_ref_change SET target_version_id=? WHERE target_version_id=? AND status='pending'")
           ->execute([$newVid, $versionId]);

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('[adr] apply: ' . $e->getMessage());
        return ['ok' => false, 'msg' => '套用失敗：' . $e->getMessage()];
    }

    return ['ok' => true, 'msg' => '已套用並補上制修訂紀錄',
            'version_id' => $newVid, 'version' => $newVer, 'replaced' => $replaced,
            'pages' => implode('、', $pageList), 'summary' => $summary, 'date' => $date];
}

/** 略過（這一處不需要改）——留紀錄，不是直接刪掉 */
function adr_dismiss(PDO $db, int $versionId, array $changeIds, int $uid, string $uname): array
{
    adr_ensure_schema($db);
    $ids = array_values(array_unique(array_filter(array_map('intval', $changeIds))));
    if (!$ids) return ['ok' => false, 'msg' => '請先勾選項目'];
    $in = implode(',', array_fill(0, count($ids), '?'));
    try {
        $st = $db->prepare("UPDATE as_doc_ref_change SET status='dismissed', decided_by=?, decided_by_name=?, decided_at=NOW()
                            WHERE id IN ($in) AND target_version_id=? AND status='pending'");
        $st->execute(array_merge([$uid ?: null, $uname], $ids, [$versionId]));
        return ['ok' => true, 'msg' => '已略過 ' . $st->rowCount() . ' 筆'];
    } catch (Throwable $e) { return ['ok' => false, 'msg' => '失敗：' . $e->getMessage()]; }
}

} // adr_ensure_schema
