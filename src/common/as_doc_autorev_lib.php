<?php
/**
 * as_doc_autorev_lib.php — 線上版簽核完成後自動補「制修訂紀錄」一列（唯一實作）
 * 建立：2026-10-16（使用者交辦：「簽核完成後制修訂紀錄沒有自動出現新的紀錄與修訂結果與日期」）
 *
 * 規則（使用者逐項指定）：
 *   ・比對對象＝「上一次簽核通過的內容」（每次簽核完成都把當時的內容存成快照 content_snapshot）
 *   ・修訂 2 處以下＝小改版，版別小數 +1（1.3→1.4）；超過 2 處＝大改版，小數點前 +1 並從 .0 開始（1.3→2.0）
 *   ・一「處」＝一段連續有變動的區塊（連續好幾個段落一起改，算一處）
 *   ・日期＝這次送簽的業務日期；頁次＝實際有變動的頁；摘要＝自動列出增／減／改了哪些章節
 *   ・沒有上一次的快照（第一次簽核）只存快照、不補列——沒有東西可以比
 *   ・沒有任何變動不補列
 * 為什麼是「新增一列版次並把內容與簽核案件搬過去」：制修訂紀錄書是 as_document_version 的版次列產生的系統頁，
 * 與 as_doc_ref_lib.php 的 adr_apply() 同一個做法（兩邊版別規則才會一致）。
 */
if (!function_exists('adrv_run')) {

require_once __DIR__ . '/as_doc_ref_lib.php';   // adr_dom / adr_text / adr_next_version
require_once __DIR__ . '/as_doc_tpl_lib.php';   // adt_split_pages
require_once __DIR__ . '/as_doc_content_lib.php';

if (!defined('ADRV_MINOR_MAX')) define('ADRV_MINOR_MAX', 2);   // 這個處數以下算小改版

/** 補欄位（這個 MySQL 沒有 ADD COLUMN IF NOT EXISTS，一律先查再 ALTER） */
function adrv_ensure_cols(PDO $db): void
{
    static $done = false;
    if ($done || $db->inTransaction()) return;
    $done = true;
    try {
        $have = [];
        foreach ($db->query("SHOW COLUMNS FROM as_doc_sign_case")->fetchAll(PDO::FETCH_ASSOC) as $c) $have[strtolower($c['Field'])] = 1;
        if (empty($have['content_snapshot'])) $db->exec("ALTER TABLE as_doc_sign_case ADD COLUMN content_snapshot MEDIUMTEXT NULL COMMENT '簽核完成當下的內容（下次簽核比對用）'");
        $hv = [];
        foreach ($db->query("SHOW COLUMNS FROM as_document_version")->fetchAll(PDO::FETCH_ASSOC) as $c) $hv[strtolower($c['Field'])] = 1;
        if (empty($hv['is_draft'])) $db->exec("ALTER TABLE as_document_version ADD COLUMN is_draft TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=正式版發行後建立的新版編輯草稿（簽核完成才會變成正式的一列制修訂紀錄）'");
        if (empty($hv['superseded_by'])) $db->exec("ALTER TABLE as_document_version ADD COLUMN superseded_by INT NULL COMMENT '內容已搬到這個版次列（自動補制修訂紀錄時）；開舊網址要導過去'");
        foreach (['rev_summary' => "TEXT NULL COMMENT '送簽時使用者確認的制修訂摘要'",
                  'rev_pages'   => "VARCHAR(100) NULL COMMENT '送簽時確認的制修訂頁次'",
                  'rev_kind'    => "VARCHAR(8) NULL COMMENT 'major／minor／none（送簽時使用者確認的改版種類）'",
                  'rev_hunks'   => "INT NULL COMMENT '送簽時勾選要列的項目數'"] as $col => $def) {
            if (empty($have[$col])) $db->exec("ALTER TABLE as_doc_sign_case ADD COLUMN `$col` $def");
        }
        if (empty($have['no_autorev']))       $db->exec("ALTER TABLE as_doc_sign_case ADD COLUMN no_autorev TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1=這個案件的制修訂列已由別處補好，不要再自動補'");
    } catch (Throwable $e) { error_log('[adrv] ensure_cols: ' . $e->getMessage()); }
}

/** 內容拆成區塊：[ ['page'=>頁, 'text'=>純文字, 'num'=>章節編號] ... ]（空區塊不收） */
function adrv_blocks(string $html): array
{
    $out = [];
    $last = '';
    $pages = adt_split_pages($html);
    if (!$pages) $pages = [$html];
    foreach ($pages as $i => $ph) {
        $dom = adr_dom((string)$ph);
        if (!$dom) continue;
        foreach ($dom[1]->childNodes as $n) {
            $raw = ($n->nodeType === XML_ELEMENT_NODE) ? $dom[0]->saveHTML($n) : (string)$n->textContent;
            $img = ($n->nodeType === XML_ELEMENT_NODE && strpos($raw, '<img') !== false) ? ' [圖]' : '';
            $t = adr_text($raw) . $img;
            if ($t === '' || $t === ' ') continue;
            $num = preg_match('/^\s*(\d{1,2}(?:\.\d{1,2}){0,5})\.?/u', $t, $m) ? $m[1] : '';
            // 比對用的鍵：拿掉開頭的章節編號與全部空白——只是重新連號、或只是文字改綁定 AS 文件（字面不變），
            // 不是內容修訂，不可以算進修訂處數與摘要（使用者 2026-10-16 指定）
            $key = preg_replace('/\s+/u', '', preg_replace('/^\s*\d{1,2}(?:\.\d{1,2}){0,5}\.?/u', '', mb_convert_kana($t, 'a')));
            // Word 清單殘留的單獨數字／符號（「1」「2」…）不是內容
            if (mb_strlen($key, 'UTF-8') <= 3 && preg_match('/^[\d\W]*$/u', $key)) { if ($num !== '') $last = $num; continue; }
            if ($num !== '') $last = $num;
            $out[] = ['page' => $i + 1, 'text' => $t, 'num' => $num, 'key' => $key, 'ctx' => $last];
        }
    }
    return $out;
}

/** 區塊層級的差異（LCS）。回傳 hunks：每一處 ['kind'=>增/減/改,'page'=>頁,'label'=>章節與標題,'n'=>區塊數] */
function adrv_diff(array $old, array $new): array
{
    $n = count($old); $m = count($new);
    if ($n > 1500 || $m > 1500) return [['kind' => '改', 'page' => 1, 'label' => '（內容變動過大，無法逐段比對）', 'n' => max($n, $m)],
                                        ['kind' => '改', 'page' => 1, 'label' => '（請人工確認修訂摘要）', 'n' => 0],
                                        ['kind' => '改', 'page' => 1, 'label' => '（大改版）', 'n' => 0]];
    $a = array_map(function ($b) { return $b['key'] ?? $b['text']; }, $old);
    $b = array_map(function ($x) { return $x['key'] ?? $x['text']; }, $new);
    $L = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
    for ($i = $n - 1; $i >= 0; $i--) for ($j = $m - 1; $j >= 0; $j--)
        $L[$i][$j] = ($a[$i] === $b[$j]) ? $L[$i + 1][$j + 1] + 1 : max($L[$i + 1][$j], $L[$i][$j + 1]);
    $hunks = []; $i = 0; $j = 0; $cur = null;
    $flush = function () use (&$cur, &$hunks) { if ($cur) { $hunks[] = $cur; $cur = null; } };
    while ($i < $n || $j < $m) {
        if ($i < $n && $j < $m && $a[$i] === $b[$j]) { $flush(); $i++; $j++; continue; }
        if ($cur === null) $cur = ['del' => [], 'add' => [], 'page' => $new[min($j, $m - 1)]['page'] ?? 1];
        if ($j < $m && ($i >= $n || $L[$i][$j + 1] >= $L[$i + 1][$j])) { $cur['add'][] = $new[$j]; $j++; }
        else { $cur['del'][] = $old[$i]; $i++; }
    }
    $flush();
    $out = [];
    foreach ($hunks as $h) {
        $kind = $h['add'] && $h['del'] ? '改' : ($h['add'] ? '增' : '減');
        $ref = $h['add'] ? $h['add'][0] : $h['del'][0];
        $label = trim(($ref['num'] !== '' ? $ref['num'] . ' ' : '') . mb_substr(preg_replace('/^\s*[\d.]+\s*/u', '', $ref['text']), 0, 14, 'UTF-8'));
        $num = $ref['num'] !== '' ? $ref['num'] : (string)($ref['ctx'] ?? '');   // 沒有自己的編號就取它所在的條文編號
        $out[] = ['kind' => $kind, 'page' => (int)($h['add'] ? $h['add'][0]['page'] : $h['page']), 'label' => $label,
                  'num' => $num, 'n' => count($h['add']) + count($h['del'])];
    }
    return $out;
}

/** 這一版是不是「已正式簽核完成並設為正本」＝鎖定（要改內容請按「編輯」另建新版草稿，不可以直接改正本） */
function adrv_locked(PDO $db, int $versionId): bool
{
    $C = adc_content_by_version($db, $versionId);
    if (!$C || (int)$C['is_primary'] !== 1) return false;
    try {
        $st = $db->prepare("SELECT status FROM as_doc_sign_case WHERE version_id=? ORDER BY id DESC LIMIT 1");
        $st->execute([$versionId]);
        return $st->fetchColumn() === 'approved';
    } catch (Throwable $e) { return false; }
}

/** 這份文件目前編輯中的草稿版次 id（沒有回 0） */
function adrv_draft_of_doc(PDO $db, int $docId): int
{
    adrv_ensure_cols($db);
    try {
        $st = $db->prepare("SELECT id FROM as_document_version WHERE doc_id=? AND is_draft=1 ORDER BY id DESC LIMIT 1");
        $st->execute([$docId]);
        return (int)($st->fetchColumn() ?: 0);
    } catch (Throwable $e) { return 0; }
}

/**
 * 正式版按「編輯」：複製成新版草稿（新的版次列＋內容＋圖片流程圖），正本原封不動，
 * 草稿簽核完成才會變成一列正式的制修訂紀錄。同一份文件同時只留一份草稿（已經有就直接回那一份）。
 */
function adrv_start_draft(PDO $db, int $versionId, int $uid, string $uname): array
{
    adrv_ensure_cols($db);
    $V = adc_version_info($db, $versionId);
    if (!$V) return ['ok' => false, 'msg' => '找不到這個版次'];
    if (!adrv_locked($db, $versionId)) return ['ok' => false, 'msg' => '這一版還不是正式版本，直接編輯就可以了'];
    $ex = adrv_draft_of_doc($db, (int)$V['doc_id']);
    if ($ex > 0) return ['ok' => true, 'version_id' => $ex, 'existing' => true, 'msg' => '這份文件已經有一份編輯中的草稿，帶你過去'];
    $own = !$db->inTransaction();
    if ($own) $db->beginTransaction();
    try {
        $db->prepare("INSERT INTO as_document_version
            (doc_id, version, change_status, revised_date, revised_pages, revised_summary,
             doc_level_snapshot, department_id_snapshot, uploaded_by, uploaded_at, is_draft)
            VALUES (?,?,'修正',NULL,NULL,NULL,?,?,?,NOW(),1)")
           ->execute([(int)$V['doc_id'], $V['version'] . '（編輯中草稿）', (string)$V['doc_level'], (int)$V['department_id'], $uname]);
        $newVid = (int)$db->lastInsertId();
        $f = adc_version_fork($db, $versionId, $newVid, $uid);
        if (empty($f['ok'])) throw new RuntimeException($f['msg'] ?? '複製內容失敗');
        // fork 會連「是正本」一起複製；草稿一定不是正本（簽核並發行之前，正式版維持原狀）
        $db->prepare("UPDATE as_doc_content SET is_primary=0 WHERE id=?")->execute([(int)$f['content_id']]);
        if ($own) $db->commit();
    } catch (Throwable $e) {
        if ($own && $db->inTransaction()) $db->rollBack();
        return ['ok' => false, 'msg' => '建立草稿失敗：' . $e->getMessage()];
    }
    return ['ok' => true, 'version_id' => $newVid, 'existing' => false, 'msg' => '已建立新版草稿'];
}

/** 放棄草稿：連版次列、內容、圖片一併刪除。只有「草稿且沒有進行中／已完成的簽核」才可以 */
function adrv_discard_draft(PDO $db, int $versionId): array
{
    adrv_ensure_cols($db);
    $V = adc_version_info($db, $versionId);
    if (!$V) return ['ok' => false, 'msg' => '找不到這個版次'];
    $isDraft = (int)$db->query("SELECT is_draft FROM as_document_version WHERE id=" . (int)$versionId)->fetchColumn();
    if (!$isDraft) return ['ok' => false, 'msg' => '這不是草稿，不能放棄'];
    $st = $db->prepare("SELECT COUNT(*) FROM as_doc_sign_case WHERE version_id=? AND status IN ('pending','approved')");
    $st->execute([$versionId]);
    if ((int)$st->fetchColumn() > 0) return ['ok' => false, 'msg' => '這份草稿已經送簽，請先取消送簽再放棄'];
    $C = adc_content_by_version($db, $versionId);
    $own = !$db->inTransaction();
    if ($own) $db->beginTransaction();
    try {
        if ($C) {
            foreach (adc_assets($db, (int)$C['id']) as $a) adc_asset_delete($db, (int)$a['id']);
            $db->prepare("DELETE FROM as_doc_content WHERE id=?")->execute([(int)$C['id']]);
        }
        $db->prepare("DELETE FROM as_document_version WHERE id=? AND is_draft=1")->execute([$versionId]);
        if ($own) $db->commit();
    } catch (Throwable $e) {
        if ($own && $db->inTransaction()) $db->rollBack();
        return ['ok' => false, 'msg' => '放棄草稿失敗：' . $e->getMessage()];
    }
    return ['ok' => true, 'msg' => '已放棄草稿'];
}

/** 算下一個版別要以哪個版別為基準：草稿的版別字樣是「2.0（編輯中草稿）」不能拿來算，改取這份文件最新一筆正式紀錄 */
function adrv_base_version(PDO $db, array $V): string
{
    $isDraft = false;
    try { $isDraft = (int)$db->query("SELECT COALESCE(is_draft,0) FROM as_document_version WHERE id=" . (int)$V['version_id'])->fetchColumn() === 1; } catch (Throwable $e) {}
    if (!$isDraft) return (string)$V['version'];
    $st = $db->prepare("SELECT version FROM as_document_version WHERE doc_id=? AND COALESCE(is_draft,0)=0
                        ORDER BY revised_date DESC, id DESC LIMIT 1");
    $st->execute([(int)$V['doc_id']]);
    $v = $st->fetchColumn();
    return $v !== false ? (string)$v : (string)$V['version'];
}

/** 同一份文件上一次簽核通過的內容快照（沒有回 null）。$beforeCaseId＝只看這個案件之前的 */
function adrv_baseline(PDO $db, int $docId, int $beforeCaseId = PHP_INT_MAX): ?string
{
    $st = $db->prepare("SELECT sc.content_snapshot FROM as_doc_sign_case sc
                        JOIN as_document_version v ON v.id=sc.version_id
                        WHERE v.doc_id=? AND sc.status='approved' AND sc.id<? AND sc.content_snapshot IS NOT NULL
                        ORDER BY sc.id DESC LIMIT 1");
    $st->execute([$docId, $beforeCaseId]);
    $b = $st->fetchColumn();
    return ($b === false || $b === null) ? null : (string)$b;
}

/**
 * 送簽視窗用：這次內容相對上一次簽核通過的內容有哪些條文變動，以及系統能不能判斷大／小改版。
 * judgeable＝false（沒有上一次的內容可比、或差異大到無法逐段比對）時，畫面一定要請使用者自己確認。
 */
function adrv_items(PDO $db, int $versionId): array
{
    adrv_ensure_cols($db);
    $V = adc_version_info($db, $versionId);
    $C = adc_content_by_version($db, $versionId);
    if (!$V || !$C) return ['ok' => false, 'msg' => '找不到這個版次的內容'];
    $cur = adrv_base_version($db, $V);
    $out = ['ok' => true, 'cur' => $cur, 'major_ver' => adrv_next_version($cur, true), 'minor_ver' => adrv_next_version($cur, false),
            'minor_max' => ADRV_MINOR_MAX, 'items' => [], 'baseline' => false, 'judgeable' => false, 'auto_kind' => '',
            'page_count' => max(1, count(adt_split_pages((string)$C['content_html']) ?: [1]))];
    $base = adrv_baseline($db, (int)$V['doc_id']);
    if ($base === null) return $out;
    $out['baseline'] = true;
    $ob = adrv_blocks($base); $nb = adrv_blocks((string)$C['content_html']);
    if (count($ob) > 1500 || count($nb) > 1500) return $out;          // 差異太大，無法逐段比對
    $out['items'] = adrv_diff($ob, $nb);
    $out['judgeable'] = true;
    $out['auto_kind'] = count($out['items']) > ADRV_MINOR_MAX ? 'major' : 'minor';
    return $out;
}

/**
 * 已簽核完成的草稿，併進目前的正式版（不新增制修訂紀錄時用）：
 * 正式版的內容換成草稿的內容、圖片資產一起搬過去，簽核案件改掛在正式版上（章與簽核歷程留得住），草稿列刪除。
 * @return int 併入的正式版次 id（失敗回 0，草稿維持原狀）
 */
function adrv_fold_draft(PDO $db, int $draftVid, int $caseId): int
{
    $V = adc_version_info($db, $draftVid);
    $Cd = adc_content_by_version($db, $draftVid);
    if (!$V || !$Cd) return 0;
    $docId = (int)$V['doc_id'];
    $base = (int)$db->query("SELECT current_version_id FROM as_document WHERE id=" . $docId)->fetchColumn();
    if ($base <= 0 || (int)$db->query("SELECT COALESCE(is_draft,0) FROM as_document_version WHERE id=" . $base)->fetchColumn() === 1) {
        $base = (int)$db->query("SELECT id FROM as_document_version WHERE doc_id=" . $docId . " AND COALESCE(is_draft,0)=0
                                 ORDER BY revised_date DESC, id DESC LIMIT 1")->fetchColumn();
    }
    $C0 = $base > 0 ? adc_content_by_version($db, $base) : null;
    if (!$C0 || $base === $draftVid) return 0;
    $own = !$db->inTransaction();
    if ($own) $db->beginTransaction();
    try {
        foreach (adc_assets($db, (int)$C0['id']) as $a) adc_asset_delete($db, (int)$a['id']);   // 舊正式版的圖片（草稿有自己的一份）
        $db->prepare("UPDATE as_doc_content SET content_html=?, page_size=?, orientation=?, is_primary=1, updated_at=NOW() WHERE id=?")
           ->execute([$Cd['content_html'], $Cd['page_size'], $Cd['orientation'], (int)$C0['id']]);
        $db->prepare("UPDATE as_doc_asset SET content_id=? WHERE content_id=?")->execute([(int)$C0['id'], (int)$Cd['id']]);
        $db->prepare("DELETE FROM as_doc_content WHERE id=?")->execute([(int)$Cd['id']]);
        $db->prepare("UPDATE as_doc_sign_case SET version_id=?, released_at=NOW(), released_by_name='（併入現行版）' WHERE id=?")->execute([$base, $caseId]);
        $db->prepare("UPDATE as_doc_ref_change SET target_version_id=? WHERE target_version_id=?")->execute([$base, $draftVid]);
        $db->prepare("DELETE FROM as_document_version WHERE id=? AND is_draft=1")->execute([$draftVid]);
        if ($own) $db->commit();
    } catch (Throwable $e) {
        if ($own && $db->inTransaction()) $db->rollBack();
        error_log('[adrv] fold_draft: ' . $e->getMessage());
        return 0;
    }
    return $base;
}

/** 下一個版別。major=true 時小數點前 +1 並從 .0 開始（1.3→2.0）；否則小數 +1（1.3→1.4） */
function adrv_next_version(string $cur, bool $major): string
{
    $cur = trim($cur);
    if (preg_match('/^(\d+)\.(\d+)$/', $cur, $m)) {
        return $major ? ((int)$m[1] + 1) . '.0' : $m[1] . '.' . ((int)$m[2] + 1);
    }
    if ($major && preg_match('/^[A-Y]$/', $cur)) return chr(ord($cur) + 1);   // A→B（字母型版別）
    return adr_next_version($cur);
}

function adrv_summary(array $hunks): string
{
    $parts = [];
    foreach ($hunks as $h) $parts[] = $h['kind'] . ' ' . $h['label'];
    return mb_substr(implode('；', $parts), 0, 500, 'UTF-8');
}

/**
 * 簽核完成時呼叫。一定先存快照；有上一次快照且有差異才補列。
 * @return array|null 補列時回 ['version_id','version','major','hunks','pages','summary','date']
 */
function adrv_run(PDO $db, int $caseId): ?array
{
    adrv_ensure_cols($db);
    $st = $db->prepare("SELECT * FROM as_doc_sign_case WHERE id=?");
    $st->execute([$caseId]);
    $case = $st->fetch(PDO::FETCH_ASSOC);
    if (!$case || $case['status'] !== 'approved') return null;
    $vid = (int)$case['version_id'];
    $V = adc_version_info($db, $vid);
    $C = adc_content_by_version($db, $vid);
    if (!$V || !$C) return null;
    $html = (string)$C['content_html'];

    // 上一次簽核通過的內容（同一份文件，任何版次）
    $base = adrv_baseline($db, (int)$V['doc_id'], $caseId);

    // 快照一律先存（沒有就永遠比不了）
    $db->prepare("UPDATE as_doc_sign_case SET content_snapshot=? WHERE id=?")->execute([$html, $caseId]);
    if (!empty($case['no_autorev'])) return null;

    /* 送簽視窗使用者確認過的內容優先：改版種類（大／小／不新增）、摘要、頁次、項目數。
       沒有確認過（舊流程或自動送簽）才回到自動判斷；自動判斷沒有基準就不補列。 */
    $kind = (string)($case['rev_kind'] ?? '');
    if ($kind === 'none') {
        /* 草稿簽核完成又選「不新增制修訂紀錄」＝這次修改併進目前的正式版（版別、日期都不變）。
           以前這裡直接 return，草稿就永遠卡在「編輯中草稿」：簽核已完成、正式版卻還是舊內容。 */
        if ((int)$db->query("SELECT COALESCE(is_draft,0) FROM as_document_version WHERE id=" . $vid)->fetchColumn() === 1) {
            adrv_fold_draft($db, $vid, $caseId);
        }
        return null;
    }
    $userSummary = trim((string)($case['rev_summary'] ?? ''));
    $hunkCnt = null; $pages = [];
    if ($base !== null && ($userSummary === '' || (string)($case['rev_pages'] ?? '') === '')) {
        $hunks = adrv_diff(adrv_blocks($base), adrv_blocks($html));
        if (!$hunks && $kind === '') return null;
        $hunkCnt = count($hunks);
        foreach ($hunks as $h) $pages[(int)$h['page']] = true;
        $pages = array_keys($pages); sort($pages);
        if ($userSummary === '') $userSummary = adrv_summary($hunks);
    } elseif ($base === null && $kind === '') {
        return null;                                   // 沒有基準、使用者也沒確認 → 不補列
    }
    $pagesTxt = '';
    if ((string)($case['rev_pages'] ?? '') === '全冊') $pagesTxt = '全冊';      // 使用者選「全冊」＝每一頁的頁版別都跳新版次
    elseif ((string)($case['rev_pages'] ?? '') !== '') $pages = array_map('intval', explode(',', (string)$case['rev_pages']));
    if ($case['rev_hunks'] !== null && $case['rev_hunks'] !== '') $hunkCnt = (int)$case['rev_hunks'];
    if ($kind === 'major' || $kind === 'minor') $major = ($kind === 'major');
    else $major = ((int)$hunkCnt) > ADRV_MINOR_MAX;
    $hunks = array_fill(0, max(0, (int)$hunkCnt), 1);   // 後面只用到筆數
    if ($pagesTxt === '') $pagesTxt = implode(',', $pages);
    $newVer = adrv_next_version(adrv_base_version($db, $V), $major);
    $summary = $userSummary;
    $date = (string)($case['submit_date'] ?: $db->query("SELECT CURDATE()")->fetchColumn());

    $own = !$db->inTransaction();
    $isDraft = (int)$db->query("SELECT COALESCE(is_draft,0) FROM as_document_version WHERE id=" . $vid)->fetchColumn() === 1;
    if ($isDraft) {
        /* 草稿簽核完成：這一列本來就是為這次修訂建的，原地定案（版別／日期／頁次／摘要），
           內容與簽核案件都不必搬；是不是正本仍由「正式發行」決定（發行時才會把舊正本退回草稿）。 */
        try {
            $db->prepare("UPDATE as_document_version SET version=?, revised_date=?, revised_pages=?, revised_summary=?, is_draft=0 WHERE id=?")
               ->execute([$newVer, $date, $pagesTxt, $summary, $vid]);
        } catch (Throwable $e) { error_log('[adrv] draft finalize: ' . $e->getMessage()); return null; }
        return ['version_id' => $vid, 'version' => $newVer, 'major' => $major, 'hunks' => count($hunks),
                'pages' => str_replace(',', '、', $pagesTxt), 'summary' => $summary, 'date' => $date, 'draft' => 1];
    }
    if ($own) $db->beginTransaction();
    try {
        $db->prepare("INSERT INTO as_document_version
            (doc_id, version, change_status, revised_date, revised_pages, revised_summary,
             doc_level_snapshot, department_id_snapshot, uploaded_by, uploaded_at)
            VALUES (?,?,'修正',?,?,?,?,?,?,NOW())")
           ->execute([(int)$V['doc_id'], $newVer, $date, $pagesTxt, $summary,
                      (string)$V['doc_level'], (int)$V['department_id'], (string)($case['submitted_by_name'] ?? '')]);
        $newVid = (int)$db->lastInsertId();
        // 內容與這個簽核案件一起搬到新版次（章要跟著新的那一列走）
        $db->prepare("UPDATE as_doc_content SET version_id=? WHERE id=?")->execute([$newVid, (int)$C['id']]);
        $db->prepare("UPDATE as_document_version SET superseded_by=? WHERE id=?")->execute([$newVid, $vid]);
        $db->prepare("UPDATE as_doc_sign_case SET version_id=? WHERE id=?")->execute([$newVid, $caseId]);
        $db->prepare("UPDATE as_doc_ref_change SET target_version_id=? WHERE target_version_id=? AND status='pending'")->execute([$newVid, $vid]);
        $db->prepare("UPDATE as_document SET current_version=?, current_version_id=?, updated_at=NOW() WHERE id=?")
           ->execute([$newVer, $newVid, (int)$V['doc_id']]);
        if ($own) $db->commit();
    } catch (Throwable $e) {
        if ($own && $db->inTransaction()) $db->rollBack();
        error_log('[adrv] run: ' . $e->getMessage());
        return null;
    }
    return ['version_id' => $newVid, 'version' => $newVer, 'major' => $major, 'hunks' => count($hunks),
            'pages' => str_replace(',', '、', $pagesTxt), 'summary' => $summary, 'date' => $date];
}

}
