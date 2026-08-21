<?php
/**
 * 列印與簽核紀錄 API（2026-08-21 使用者明確要求）
 *
 * 兩種用途：
 *   (1) log_print  ── 給全站任何「會列印」的頁面呼叫，留下列印紀錄（見 ai-rules/23）。
 *                     全體登入者都可寫（要留的就是「誰印了什麼」，不能因為沒角色就不記）。
 *   (2) list_*     ── 紀錄查詢頁 views/admin/print_sign_log.php 用；沒有角色的人只看得到自己的。
 *
 * 簽核紀錄一律讀共用的 approval_record（含自動簽核產生的那些），
 * 但輸出絕對不帶「自動簽核」字樣或旗標（使用者明確要求）。
 */
$document_root = $_SERVER['DOCUMENT_ROOT'];
session_start();
header('Content-Type: application/json; charset=utf-8');
include_once $document_root . '/EGsystem/src/common/DBConnection.php';
include_once $document_root . '/EGsystem/src/common/print_log_lib.php';

function pslOut(array $a) { echo json_encode(array_merge(['ok' => true], $a), JSON_UNESCAPED_UNICODE); exit; }
function pslErr(string $m, int $code = 400) { http_response_code($code); echo json_encode(['ok' => false, 'error' => $m], JSON_UNESCAPED_UNICODE); exit; }

try {
    $db = (new DBConnection())->getPDO();
} catch (Throwable $e) { pslErr('DB連線失敗', 500); }

$u = eg_printlog_current_user($db);
if (!$u) pslErr('未登入', 401);
$perms = eg_printlog_perms($db, $u);
$uid   = (int)$u['id'];

$action = $_GET['action'] ?? $_POST['action'] ?? '';

/**
 * 內部註記（補簽核／自動簽核）是否要解除遮蔽。
 * 兩個條件都要成立：① 目前是管理員 ② 這個 session 在 PSL_NOTE_REVEAL_TTL 秒內驗過操作確認密碼。
 * 權限每次重查，不吃 session 裡的舊值——角色被拔掉之後就該立刻看不到。
 */
const PSL_NOTE_REVEAL_TTL = 600;   // 10 分鐘，過期要重新輸入密碼
function pslNoteRevealed(array $perms): bool {
    if (empty($perms['canAdmin']) && empty($perms['isAdmin'])) return false;
    return (int)($_SESSION['psl_note_reveal_until'] ?? 0) > time();
}

/** 篩選條件：沒有「看全部」權限的人一律被綁死成只能查自己（後端擋，不靠前端） */
function pslFilters(array $perms, int $uid): array {
    $f = [
        'source'    => trim((string)($_POST['source']    ?? $_GET['source']    ?? '')),
        'module'    => trim((string)($_POST['module']    ?? $_GET['module']    ?? '')),
        'user_id'   => (int)($_POST['user_id']  ?? $_GET['user_id']  ?? 0),
        'date_from' => trim((string)($_POST['date_from'] ?? $_GET['date_from'] ?? '')),
        'date_to'   => trim((string)($_POST['date_to']   ?? $_GET['date_to']   ?? '')),
        'kw'        => trim((string)($_POST['kw']        ?? $_GET['kw']        ?? '')),
        'page'      => max(1, (int)($_POST['page'] ?? $_GET['page'] ?? 1)),
        'per'       => (int)($_POST['per'] ?? $_GET['per'] ?? 20),
    ];
    foreach (['date_from', 'date_to'] as $k) {
        if ($f[$k] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $f[$k])) $f[$k] = '';
    }
    if (!in_array($f['per'], [5, 10, 20, 50, 0], true)) $f['per'] = 20;
    if (!$perms['canViewAll']) $f['user_id'] = $uid;   // 只能看自己
    return $f;
}

switch ($action) {

/* ── 寫入一筆列印紀錄（全站列印頁面呼叫）────────────────────────────────── */
case 'log_print': {
    $source = trim((string)($_POST['source'] ?? ''));
    $name   = trim((string)($_POST['doc_name'] ?? ''));
    if ($source === '' || $name === '') pslErr('缺少 source 或 doc_name');
    $id = eg_print_log_add($db, [
        'source'    => $source,
        'doc_kind'  => (string)($_POST['doc_kind'] ?? 'attachment'),
        'ref_table' => (string)($_POST['ref_table'] ?? ''),
        'ref_id'    => (string)($_POST['ref_id'] ?? ''),
        'doc_name'  => $name,
        'part_no'   => (string)($_POST['part_no'] ?? ''),
        'note'      => (string)($_POST['note'] ?? ''),
        'user_id'   => $uid,
        'user_name' => (string)$u['user_cname'],
    ]);
    pslOut(['id' => $id]);
}

/* ── 篩選用的下拉選項 ──────────────────────────────────────────────────────
   使用者要求（2026-08-21）：**目前日期區間內沒有資料的來源／人員就不要出現在下拉裡**。
   選 2026/08 卻列出十個來源、選下去是 0 筆，那個下拉等於在騙人。
   所以這裡先撈出「這段區間內實際出現過」的來源與人員 id，再拿去過濾完整清單
   （人員仍走 eg_printlog_people() 取部門/職稱/在職狀態，維持人員列表鐵則的欄位與排序）。
   沒有「看全部」權限的人一律只算自己的紀錄，跟清單的限制一致。 */
case 'meta': {
    $dFrom = trim((string)($_POST['date_from'] ?? $_GET['date_from'] ?? ''));
    $dTo   = trim((string)($_POST['date_to']   ?? $_GET['date_to']   ?? ''));
    if ($dFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dFrom)) $dFrom = '';
    if ($dTo   !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dTo))   $dTo   = '';
    $onlyUid = $perms['canViewAll'] ? 0 : $uid;

    // ── 列印分頁：這段區間內出現過的 source 與列印人 ──
    $usedSrc = []; $usedPrintUsers = [];
    try {
        $w = []; $ar = [];
        if ($dFrom !== '') { $w[] = 'x.printed_at >= ?'; $ar[] = $dFrom . ' 00:00:00'; }
        if ($dTo   !== '') { $w[] = 'x.printed_at <= ?'; $ar[] = $dTo . ' 23:59:59'; }
        if ($onlyUid)      { $w[] = 'x.printed_by = ?';  $ar[] = $onlyUid; }
        $sql = 'SELECT DISTINCT x.source, x.printed_by FROM ' . eg_printlog_union_sql('') . ' x'
             . ($w ? ' WHERE ' . implode(' AND ', $w) : '');
        $st = $db->prepare($sql); $st->execute($ar);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ($r['source'] !== null && $r['source'] !== '') $usedSrc[(string)$r['source']] = true;
            if ((int)$r['printed_by'] > 0) $usedPrintUsers[(int)$r['printed_by']] = true;
        }
    } catch (Throwable $e) {}

    // ── 簽核分頁：這段區間內出現過的 module 與簽核人 ──
    //   日期條件與 eg_signlog_query() 一致（送件日或決行日任一落在區間內就算）
    $usedMod = []; $usedSignUsers = [];
    try {
        $w = []; $ar = [];
        if ($dFrom !== '') { $w[] = '(a.submitted_at >= ? OR a.decided_at >= ?)'; $ar[] = $dFrom . ' 00:00:00'; $ar[] = $dFrom . ' 00:00:00'; }
        if ($dTo   !== '') { $w[] = '(a.submitted_at <= ? OR a.decided_at <= ?)'; $ar[] = $dTo . ' 23:59:59';  $ar[] = $dTo . ' 23:59:59'; }
        if ($onlyUid)      { $w[] = '(a.approver_id = ? OR (a.approver_id IS NULL AND a.submitted_by = ?))'; $ar[] = $onlyUid; $ar[] = $onlyUid; }
        $sql = 'SELECT DISTINCT a.module, a.approver_id, a.submitted_by FROM approval_record a'
             . ($w ? ' WHERE ' . implode(' AND ', $w) : '');
        $st = $db->prepare($sql); $st->execute($ar);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ($r['module'] !== null && $r['module'] !== '') $usedMod[(string)$r['module']] = true;
            // 簽核人欄位的篩選條件是「approver_id，沒有才退回 submitted_by」，這裡跟著同一套
            $pid = (int)($r['approver_id'] ?: $r['submitted_by']);
            if ($pid > 0) $usedSignUsers[$pid] = true;
        }
    } catch (Throwable $e) {}

    $srcs = [];
    foreach (eg_print_all_sources() as $code => $v) {
        if (isset($usedSrc[$code])) $srcs[] = ['code' => $code, 'label' => $v['label']];
    }
    $mods = [];
    $regMod = eg_sign_modules();
    foreach ($usedMod as $code => $_) {
        // 沒登錄在 eg_sign_modules() 的模組也要能篩（不然那些紀錄查不到），名稱退成代碼
        $mods[] = ['code' => $code, 'label' => $regMod[$code]['label'] ?? $code];
    }
    usort($mods, fn($a, $b) => strcmp($a['label'], $b['label']));

    $people = [];
    if ($perms['canViewAll']) {
        $usedUsers = $usedPrintUsers + $usedSignUsers;   // 兩個分頁共用同一份，切分頁不必再打一次
        foreach (eg_printlog_people($db) as $pp) {
            if (isset($usedUsers[(int)$pp['id']])) {
                $pp['in_print'] = isset($usedPrintUsers[(int)$pp['id']]) ? 1 : 0;
                $pp['in_sign']  = isset($usedSignUsers[(int)$pp['id']])  ? 1 : 0;
                $people[] = $pp;
            }
        }
    }

    // 完整登錄表只在頁面第一次載入時給：用來把「已選中、但這個區間沒資料」那一筆的
    // 名稱顯示出來（不然選項只剩一個看不懂的代碼）。之後每次換區間都不必再傳一次。
    $allSrc = []; $allMod = [];
    if (!empty($_POST['with_all']) || !empty($_GET['with_all'])) {
        foreach (eg_print_all_sources() as $code => $v) $allSrc[] = ['code' => $code, 'label' => $v['label']];
        foreach ($regMod as $code => $v)                $allMod[] = ['code' => $code, 'label' => $v['label']];
    }

    pslOut([
        'sources' => $srcs,
        'modules' => $mods,
        'people'  => $people,
        'all_sources' => $allSrc,
        'all_modules' => $allMod,
        'range'   => ['from' => $dFrom, 'to' => $dTo],
        'perms'   => ['canViewAll' => $perms['canViewAll'], 'canAdmin' => $perms['canAdmin'], 'uid' => $uid],
        'me'      => ['id' => $uid, 'name' => (string)$u['user_cname']],
    ]);
}

/* ── 列印紀錄清單 ───────────────────────────────────────────────────────── */
case 'list_print': {
    $f = pslFilters($perms, $uid);
    $r = eg_printlog_query($db, $f);
    pslOut(['rows' => $r['rows'], 'total' => $r['total'], 'page' => $f['page'], 'per' => $f['per']]);
}

/* ── 簽核紀錄清單 ───────────────────────────────────────────────────────── */
case 'list_sign': {
    $f = pslFilters($perms, $uid);
    $r = eg_signlog_query($db, $f);
    $reveal = pslNoteRevealed($perms);
    // 輸出刻意不帶任何自動簽核旗標：畫面上不可出現「自動簽核」字樣（使用者明確要求）
    $rows = array_map(function ($x) use ($reveal) {   // $reveal 一定要 use 進來，否則永遠是 null＝永遠遮蔽
        return [
            'module'        => $x['module'],
            'module_label'  => $x['module_label'],
            'doc_name'      => $x['doc_name'],
            'doc_date'      => $x['doc_date'],
            'level_label'   => $x['level_label'],
            'submitted_by_name' => $x['submitted_by_name'],
            'submitted_at'  => $x['submitted_at'],
            'approver_name' => $x['approver_name'],
            'decided_at'    => $x['decided_at'],
            'result_label'  => $x['result_label'],
            'status'        => $x['status'],
            // 「（超級管理員補簽核）」這類內部註記絕對不直接輸出（使用者明確要求）。
            // 只有管理員按下「顯示內部註記」並驗過操作確認密碼，這個 session 才會在 10 分鐘內拿到原文。
            'note'          => $reveal ? (string)$x['note'] : eg_sign_note_public($x['note']),
            'note_hidden'   => eg_sign_note_is_internal($x['note']),
        ];
    }, $r['rows']);
    pslOut(['rows' => $rows, 'total' => $r['total'], 'page' => $f['page'], 'per' => $f['per'],
            'note_revealed' => $reveal, 'can_reveal' => (!empty($perms['canAdmin']) || !empty($perms['isAdmin']))]);
}

/* ── 內部註記解除遮蔽（管理員＋操作確認密碼）───────────────────────────────
   使用者明確要求：「補簽核」這種字樣不可以在前端直接顯示，要按按鈕、輸入操作密碼才看得到。
   解除只在這個 session 有效 10 分鐘，且列印/匯出一律仍然遮蔽。 */
case 'reveal_note': {
    if (empty($perms['canAdmin']) && empty($perms['isAdmin'])) pslErr('只有管理員可以檢視內部註記', 403);
    include_once $document_root . '/EGsystem/src/common/confirm_password_lib.php';
    $pw = (string)($_POST['password'] ?? '');
    if ($pw === '') pslErr('請輸入操作確認密碼');
    $vr = eg_confirm_password_verify_scoped($db, $uid, $pw, 'psl_reveal_note');
    if (empty($vr['ok'])) pslErr((string)$vr['msg'], 403);
    $_SESSION['psl_note_reveal_until'] = time() + PSL_NOTE_REVEAL_TTL;
    pslOut(['revealed' => true, 'ttl' => PSL_NOTE_REVEAL_TTL]);
}
case 'hide_note': {
    unset($_SESSION['psl_note_reveal_until']);
    pslOut(['revealed' => false]);
}

/* ── 涵蓋範圍（使用說明用；一律即時掃描，不放寫死清單）──────────────────── */
case 'coverage': {
    $root = $document_root . '/EGsystem';
    pslOut([
        'print' => eg_print_coverage($root),
        'sign'  => eg_sign_coverage($db),
    ]);
}

default:
    pslErr('未知的 action：' . $action, 404);
}
