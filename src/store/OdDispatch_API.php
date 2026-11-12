<?php
/**
 * 批次發單（外發加工單）API — 2026-11-12 建立
 * 權限（od_dispatch_lib.php od_perms，roles module='od_dispatch'）：
 *   od_admin 管理員：列印格式／AS 文件綁定／全部
 *   od_issue 發單與列印
 *   od_view  檢閱（看單據紀錄）
 * 前端擋一次、後端同規則再擋一次（鐵律8）。
 */
$document_root = $_SERVER['DOCUMENT_ROOT'];
session_start();
require_once __DIR__ . '/../common/api_guard.php';
include_once $document_root . '/EGsystem/src/common/_config.php';
include_once $document_root . '/EGsystem/src/common/DBConnection.php';
include_once $document_root . '/EGsystem/src/common/od_dispatch_lib.php';
include_once $document_root . '/EGsystem/src/common/asdoc_lib.php';

function jout($a = []) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(array_merge(['ok' => true], $a), JSON_UNESCAPED_UNICODE); exit; }
function jerr($msg, $code = 400, $extra = []) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($code);
    echo json_encode(array_merge(['ok' => false, 'error' => $msg], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $db = (new DBConnection())->getPDO();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    od_ensure_schema($db);
} catch (Throwable $e) { jerr('DB連線失敗：' . $e->getMessage(), 500); }

if (empty($_SESSION['od_csrf'])) $_SESSION['od_csrf'] = bin2hex(random_bytes(16));
$u = od_current_user($db);
if (!$u) jerr('未登入', 401);
$P = od_perms($db, $u);
if (!$P['uid']) jerr('帳號非在職狀態', 403);
if (!$P['canView']) jerr('沒有批次發單權限，請洽管理員指派角色', 403);

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$WRITE = ['issue', 'print_record', 'format_save', 'format_delete', 'format_default', 'asdoc_save', 'price_save', 'price_void', 'price_approve'];
if (in_array($action, $WRITE, true)) {
    $tok = $_POST['csrf'] ?? '';
    if (!is_string($tok) || $tok === '' || !hash_equals((string)$_SESSION['od_csrf'], $tok))
        jerr('連線憑證失效，請重新整理頁面後再試 (CSRF)', 400, ['code' => 'CSRF']);
    if (in_array($action, ['issue', 'print_record', 'price_save', 'price_void', 'price_approve'], true) && !$P['canIssue']) jerr('需要「批次發單」角色才能發單與列印', 403);
    if (in_array($action, ['format_save', 'format_delete', 'format_default', 'asdoc_save'], true) && !$P['canAdmin']) jerr('需要批次發單管理員權限', 403);
}

function odFormats(PDO $db): array {
    $rows = $db->query("SELECT format_id, name, is_default, is_active, config_json, maker_ids_json FROM od_format WHERE is_active=1 ORDER BY is_default DESC, format_id")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['config'] = od_format_normalize(json_decode((string)$r['config_json'], true) ?: []);
        $r['maker_ids'] = json_decode((string)$r['maker_ids_json'], true) ?: [];
        unset($r['config_json'], $r['maker_ids_json']);
    }
    return $rows;
}

if ($action === 'meta') {
    $types = $db->query("SELECT process_type_id AS id, process_type AS name FROM process_type WHERE is_active=1 ORDER BY sort_order, process_type_id")->fetchAll(PDO::FETCH_ASSOC);
    $doc = eg_asdoc_get($db, 'od_slip');
    jout([
        'csrf' => $_SESSION['od_csrf'], 'perms' => ['canIssue' => $P['canIssue'], 'canAdmin' => $P['canAdmin'], 'name' => $P['name']],
        'formats' => odFormats($db), 'columns' => od_format_columns(), 'process_types' => $types,
        'today' => date('Y-m-d'), 'due_days' => 5, 'asdoc' => $doc,
        'asdoc_list' => $P['canAdmin'] ? eg_asdoc_list($db) : [],
    ]);
}

if ($action === 'chain') {
    $f = [];
    foreach (['bom', 'part', 'client', 'process_type_id', 'urgent', 'qc_mode', 'limit', 'offset'] as $k) $f[$k] = $_GET[$k] ?? $_POST[$k] ?? '';
    jout(od_chain($db, $f));
}

if ($action === 'makers') {
    jout(['makers' => od_makers($db, (string)($_GET['q'] ?? ''), (int)($_GET['ptype'] ?? 0))]);
}
if ($action === 'common_makers') {
    $out = [];
    foreach (array_filter(array_map('intval', explode(',', (string)($_GET['ptypes'] ?? '')))) as $t) $out[$t] = od_common_makers($db, $t, 6);
    jout(['by_type' => $out]);
}
if ($action === 'due_date') {
    $d = (string)($_GET['from'] ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) jerr('日期格式不正確');
    jout(['due' => od_add_workdays($db, $d, max(0, min(60, (int)($_GET['days'] ?? 5))))]);
}

if ($action === 'issue') {
    $items = json_decode((string)($_POST['items'] ?? '[]'), true);
    if (!is_array($items)) jerr('發單內容格式不正確');
    $r = od_issue($db, $u, $items, (string)($_POST['issue_date'] ?? date('Y-m-d')), (int)($_POST['format_id'] ?? 0), !empty($_POST['two_part']) ? 1 : 0);
    if (!$r['ok']) jerr($r['message']);
    jout($r);
}

/* ───── 外包加工價格 ───── */
if ($action === 'price_suggest') {      // items: [{bom, process_no, maker_no}] → 每列的核定價／歷史價
    $items = json_decode((string)($_POST['items'] ?? $_GET['items'] ?? '[]'), true);
    if (!is_array($items) || count($items) > 200) jerr('項目格式不正確');
    $out = [];
    foreach ($items as $k => $it) {
        $r = od_price_suggest($db, (string)($it['bom'] ?? ''), (int)($it['process_no'] ?? 0), (string)($it['maker_no'] ?? ''));
        $out[(string)($it['key'] ?? $k)] = ['approved' => $r['approved'] ? ['price' => (float)$r['approved']['unit_price'], 'maker' => $r['approved']['maker'], 'qty_note' => $r['approved']['qty_note']] : null,
            'last' => $r['last'], 'inquiries' => count(array_filter($r['table'], fn($x) => $x['kind'] === 'inquiry'))];
    }
    jout(['suggest' => $out]);
}
if ($action === 'price_history') {
    $r = od_price_suggest($db, (string)($_GET['bom'] ?? ''), (int)($_GET['process_no'] ?? 0), '');
    jout(['table' => $r['table'], 'history' => od_price_history($db, (string)($_GET['bom'] ?? ''), (int)($_GET['process_no'] ?? 0), 15)]);
}
if ($action === 'price_list') {
    jout(['prices' => od_price_list($db, ['process_no' => $_GET['process_no'] ?? 0, 'kind' => $_GET['kind'] ?? '', 'kw' => $_GET['kw'] ?? '', 'void' => $_GET['void'] ?? 0]),
          'processes' => $db->query("SELECT ProcessNo AS no, ProcessName AS name FROM process_no ORDER BY ProcessNo")->fetchAll(PDO::FETCH_ASSOC)]);
}
if ($action === 'part_search') {
    $q = trim((string)($_GET['q'] ?? ''));
    if ($q === '') jout(['parts' => []]);
    $st = $db->prepare("SELECT d.d_id, d.D_Setting_Id AS part_no, d.Drawing_No, COALESCE(c.customer,'') AS client FROM d_setting d LEFT JOIN customer_list c ON c.customer_id=d.Customer_Id
                         WHERE d.D_Setting_Id LIKE ? OR d.Drawing_No LIKE ? ORDER BY d.d_id DESC LIMIT 30");
    $st->execute(["%$q%", "%$q%"]);
    jout(['parts' => $st->fetchAll(PDO::FETCH_ASSOC)]);
}
if ($action === 'price_save') {
    $r = od_price_save($db, $u, $_POST);
    if (!$r['ok']) jerr($r['message']);
    jout($r);
}
if ($action === 'price_void') {
    $db->prepare("UPDATE od_price SET status='void', updated_at=NOW() WHERE price_id=?")->execute([(int)($_POST['price_id'] ?? 0)]);
    jout([]);
}
if ($action === 'price_approve') {   // 廠商詢價結果 → 老闆核定（核定後發單才會自動帶入）
    $db->prepare("UPDATE od_price SET kind='approved', approved_by=?, approved_at=NOW(), updated_at=NOW() WHERE price_id=? AND status='active'")->execute([$P['name'], (int)($_POST['price_id'] ?? 0)]);
    jout([]);
}

if ($action === 'slips') {
    jout(['slips' => od_slips_list($db, ['from' => $_GET['from'] ?? '', 'to' => $_GET['to'] ?? '', 'kw' => $_GET['kw'] ?? '', 'limit' => $_GET['limit'] ?? 50])]);
}
if ($action === 'slip_print') {
    $p = od_print_payload($db, (int)($_GET['slip_id'] ?? 0));
    if (!$p) jerr('找不到單據', 404);
    jout($p);
}
if ($action === 'print_record') {
    $r = od_print_record($db, (int)($_POST['slip_id'] ?? 0));
    if (!$r['ok']) jerr($r['message']);
    jout($r);
}

if ($action === 'format_save') {
    $id = (int)($_POST['format_id'] ?? 0);
    $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 60);
    if ($name === '') jerr('請輸入格式名稱');
    $cfg = od_format_normalize(json_decode((string)($_POST['config'] ?? '{}'), true) ?: []);
    $mk = array_values(array_unique(array_filter(array_map('strval', (array)(json_decode((string)($_POST['maker_ids'] ?? '[]'), true) ?: [])))));
    $dup = $db->prepare("SELECT 1 FROM od_format WHERE name=? AND format_id<>? AND is_active=1");
    $dup->execute([$name, $id]);
    if ($dup->fetchColumn()) jerr('已有同名的格式');
    if ($id > 0) {
        $db->prepare("UPDATE od_format SET name=?, config_json=?, maker_ids_json=?, updated_at=NOW() WHERE format_id=?")
           ->execute([$name, json_encode($cfg, JSON_UNESCAPED_UNICODE), json_encode($mk), $id]);
    } else {
        $db->prepare("INSERT INTO od_format (name, config_json, maker_ids_json, created_by, created_at, updated_at) VALUES (?,?,?,?,NOW(),NOW())")
           ->execute([$name, json_encode($cfg, JSON_UNESCAPED_UNICODE), json_encode($mk), $P['uid']]);
        $id = (int)$db->lastInsertId();
    }
    jout(['format_id' => $id, 'formats' => odFormats($db)]);
}
if ($action === 'format_default') {
    $id = (int)($_POST['format_id'] ?? 0);
    $chk = $db->prepare("SELECT 1 FROM od_format WHERE format_id=? AND is_active=1"); $chk->execute([$id]);
    if (!$chk->fetchColumn()) jerr('找不到格式');
    $db->beginTransaction();
    $db->exec("UPDATE od_format SET is_default=0");
    $db->prepare("UPDATE od_format SET is_default=1 WHERE format_id=?")->execute([$id]);
    $db->commit();
    jout(['formats' => odFormats($db)]);
}
if ($action === 'format_delete') {
    $id = (int)($_POST['format_id'] ?? 0);
    $r = $db->prepare("SELECT is_default FROM od_format WHERE format_id=? AND is_active=1"); $r->execute([$id]);
    $d = $r->fetch(PDO::FETCH_ASSOC);
    if (!$d) jerr('找不到格式');
    if ((int)$d['is_default'] === 1) jerr('預設格式不可刪除，請先把別的格式設為預設');
    $db->prepare("UPDATE od_format SET is_active=0, is_default=0 WHERE format_id=?")->execute([$id]); // 已印過的單據仍指向它，所以只停用不刪
    jout(['formats' => odFormats($db)]);
}
if ($action === 'asdoc_save') {
    eg_asdoc_save($db, 'od_slip', (int)($_POST['doc_id'] ?? 0), $P['name']);
    jout(['asdoc' => eg_asdoc_get($db, 'od_slip')]);
}

jerr('未知的動作：' . $action, 400);
