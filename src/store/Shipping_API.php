<?php
/**
 * 出貨作業 API（新版快速出貨 views/Sales/Shipping_Quick.php 專用）
 *
 * 權限：shipping_lib.php sq_perms()（roles module='shipping'；admin ⊃ edit ⊃ view），fail-closed。
 * 讀：GET／POST 皆可；寫：POST + CSRF + transaction（transaction 在 lib 內）。
 * 合計／匯出一律後端對「全部符合條件」的資料計算，不使用前端當頁資料。
 */
$document_root = $_SERVER['DOCUMENT_ROOT'];
session_start();
require_once __DIR__ . '/../common/api_guard.php';   // 在職狀態守門（離職/留停者一律 403）
include_once $document_root . '/EGsystem/src/common/_config.php';
include_once $document_root . '/EGsystem/src/common/DBConnection.php';
include_once $document_root . '/EGsystem/src/common/shipping_lib.php';
include_once $document_root . '/EGsystem/src/common/trace_chain_lib.php';   // 追溯鏈（報價→訂單→製令→出貨→退貨）

function sq_out(array $a) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(array_merge(['ok' => true], $a), JSON_UNESCAPED_UNICODE); exit; }
function sq_err(string $m, int $c = 400) { header('Content-Type: application/json; charset=utf-8'); http_response_code($c); echo json_encode(['ok' => false, 'error' => $m], JSON_UNESCAPED_UNICODE); exit; }

function sq_csrf_token(): string {
    if (empty($_SESSION['shipping_csrf'])) $_SESSION['shipping_csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['shipping_csrf'];
}
function sq_csrf_ok(?string $t): bool {
    return !empty($_SESSION['shipping_csrf']) && is_string($t) && hash_equals($_SESSION['shipping_csrf'], $t);
}

try {
    $db = (new DBConnection())->getPDO();
} catch (Throwable $e) { sq_err('DB連線失敗：' . $e->getMessage(), 500); }

$u = sq_current_user($db);
if (!$u) sq_err('未登入', 401);
$uid   = (int)$u['id'];
$uname = (string)$u['user_cname'];
$perms = sq_perms($db, $u);
if (!$perms['canView']) sq_err('無出貨作業檢閱權限', 403);

$action = $_GET['action'] ?? $_POST['action'] ?? '';

/** 讀取篩選參數 */
function sq_filter(): array {
    $src = $_POST ?: $_GET;
    return [
        'kw'             => trim((string)($src['kw'] ?? '')),
        'client_id'      => trim((string)($src['client_id'] ?? '')),
        'date_from'      => trim((string)($src['date_from'] ?? '')),
        'date_to'        => trim((string)($src['date_to'] ?? '')),
        'only_ready'     => !empty($src['only_ready']),
        'include_paused' => !empty($src['include_paused']),
        'sort'           => trim((string)($src['sort'] ?? '')),
        'dir'            => (($src['dir'] ?? 'asc') === 'desc') ? 'desc' : 'asc',
        'page'           => max(1, (int)($src['page'] ?? 1)),
        'per_page'       => (int)($src['per_page'] ?? 20),
    ];
}

switch ($action) {

/* ── 進頁初始化 ───────────────────────────────────────────────────────── */
case 'meta': {
    $clients = $db->query("
        SELECT cl.customer_id, COALESCE(cl.customer, ot.Client_name) AS name, COUNT(*) AS cnt
        FROM order_track ot
        JOIN customer_list cl ON cl.customer_id = ot.Client_name_ID
        WHERE (ot.Order_status IS NULL OR ot.Order_status <> 9)
        GROUP BY cl.customer_id, name
        ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

    $warehouses = $db->query("SELECT DISTINCT Warehouse FROM is_list
                              WHERE Warehouse IS NOT NULL AND Warehouse <> ''
                              ORDER BY Warehouse")->fetchAll(PDO::FETCH_COLUMN);

    sq_out([
        'perms'      => $perms,
        'csrf'       => sq_csrf_token(),
        'today'      => date('Y-m-d'),
        'user'       => ['id' => $uid, 'name' => $uname],
        'clients'    => $clients,
        'warehouses' => $warehouses,
    ]);
}

/* ── 待出貨清單 ───────────────────────────────────────────────────────── */
case 'pending': {
    $r = sq_pending_orders($db, sq_filter());
    sq_out($r);
}

/* ── 待出貨清單 CSV 匯出（全部符合條件，不受分頁限制）────────────────── */
case 'export': {
    $f = sq_filter();
    $f['per_page'] = 0;          // 0 = 全部符合條件，不受分頁限制
    $f['page']     = 1;
    $rows = sq_pending_orders($db, $f)['rows'];

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="待出貨清單_' . date('Ymd_Hi') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['訂單號', '客戶', '料號', '品名規格', '訂購量', '已出量', '未出量',
                   '可出量', '單價', '可出金額', '交期', '製令', '訂單備註']);
    foreach ($rows as $x) {
        fputcsv($out, [
            $x['order_oo'], $x['client_display'], $x['d_id'], $x['specification'],
            $x['order_qty'], $x['shipped_qty'], $x['remain_qty'], $x['ready_qty'],
            $x['unit_price'], $x['ready_qty'] * $x['unit_price'], $x['delivery_date'],
            implode(' ', array_column($x['boms'], 'bom')), $x['order_ps'],
        ]);
    }
    fclose($out);
    exit;
}

/* ── 建立出貨單 ───────────────────────────────────────────────────────── */
case 'create': {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') sq_err('必須用 POST', 405);
    if (!$perms['canEdit']) sq_err('無出貨登錄權限', 403);
    if (!sq_csrf_ok($_POST['csrf'] ?? '')) sq_err('CSRF 驗證失敗，請重新整理頁面');

    $shipDate = trim((string)($_POST['ship_date'] ?? ''));
    $items    = json_decode($_POST['items'] ?? '[]', true);
    if (!is_array($items) || !$items) sq_err('沒有出貨資料');

    $r = sq_create_shipment($db, $shipDate, $items, (string)$uid);
    if (!$r['success']) sq_err($r['message'] ?? '建立失敗');
    sq_out($r);
}

/* ── 近期出貨單 ───────────────────────────────────────────────────────── */
case 'recent': {
    $src = $_POST ?: $_GET;
    sq_out(['rows' => sq_recent_shipments($db, [
        'kw'        => trim((string)($src['kw'] ?? '')),
        'date_from' => trim((string)($src['date_from'] ?? '')),
        'date_to'   => trim((string)($src['date_to'] ?? '')),
    ])]);
}

/* ── 單張出貨單明細（檢視／列印送貨單）───────────────────────────────── */
case 'detail': {
    $no = trim((string)($_GET['is_number'] ?? $_POST['is_number'] ?? ''));
    if ($no === '') sq_err('缺少出貨單號');
    sq_out(['rows' => sq_shipment_detail($db, $no)]);
}

/* ── 舊資料回填：找出 Order_id 為空、可對應到訂單的出貨單 ────────────── */
case 'match_preview': {
    $src  = $_POST ?: $_GET;
    $from = trim((string)($src['date_from'] ?? ''));
    $to   = trim((string)($src['date_to'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        sq_err('請指定出貨日期區間');
    }
    sq_out(sq_match_preview($db, $from, $to, [
        'client'         => trim((string)($src['client'] ?? '')),
        'd_id'           => (int)($src['d_id'] ?? 0),
        'with_unmatched' => !empty($src['with_unmatched']),
    ]));
}

/* ── 回填工具的篩選來源（該區間內待回填的客戶／料號）────────────────── */
case 'match_filters': {
    $src  = $_POST ?: $_GET;
    $from = trim((string)($src['date_from'] ?? ''));
    $to   = trim((string)($src['date_to'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        sq_err('請指定出貨日期區間');
    }
    sq_out(sq_match_filters($db, $from, $to));
}

/* ── 手動改選訂單：列出該筆出貨可綁的候選訂單（一律同客戶）──────────── */
case 'match_candidates': {
    if (!$perms['canAdmin']) sq_err('僅出貨管理員可回填舊資料', 403);
    $src  = $_POST ?: $_GET;
    $isId = (int)($src['is_id'] ?? 0);
    if ($isId <= 0) sq_err('缺少出貨明細 id');
    $same = !isset($src['same_part']) || !empty($src['same_part']);
    sq_out(sq_match_candidates($db, $isId, $same, (string)($src['kw'] ?? '')));
}

case 'match_apply': {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') sq_err('必須用 POST', 405);
    if (!$perms['canAdmin']) sq_err('僅出貨管理員可回填舊資料', 403);
    if (!sq_csrf_ok($_POST['csrf'] ?? '')) sq_err('CSRF 驗證失敗，請重新整理頁面');

    $pairs = json_decode($_POST['pairs'] ?? '[]', true);
    if (!is_array($pairs) || !$pairs) sq_err('沒有要回填的資料');
    sq_out(sq_match_apply($db, $pairs, (string)$uid));
}

/* ── 追溯鏈：某客戶底下有資料的料號 ──────────────────────────────────── */
case 'chain_parts': {
    $src = $_POST ?: $_GET;
    $cli = trim((string)($src['client'] ?? ''));
    if ($cli === '') sq_err('請先選擇客戶');
    sq_out(['parts' => tc_parts($db, $cli)]);
}

/* ── 追溯鏈：載入一支料號的五個泳道與所有對應 ────────────────────────── */
case 'chain_load': {
    $src = $_POST ?: $_GET;
    $r = tc_chain($db, [
        'd_id'       => (int)($src['d_id'] ?? 0),
        'client'     => trim((string)($src['client'] ?? '')),
        'date_from'  => trim((string)($src['date_from'] ?? '')),
        'date_to'    => trim((string)($src['date_to'] ?? '')),
        'order'      => (($src['order'] ?? 'new') === 'old') ? 'old' : 'new',
        'limit'      => (int)($src['limit'] ?? TC_LANE_LIMIT),
        'all_client' => !empty($src['all_client']),
    ]);
    if (!empty($r['error'])) sq_err($r['error']);
    $r['can_link'] = (bool)$perms['canAdmin'];
    sq_out($r);
}

/* ── 追溯鏈：建立／解除一條對應（拖放綁定）──────────────────────────── */
case 'chain_link':
case 'chain_unlink': {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') sq_err('必須用 POST', 405);
    if (!$perms['canAdmin']) sq_err('僅出貨管理員可建立或解除對應', 403);
    if (!sq_csrf_ok($_POST['csrf'] ?? '')) sq_err('CSRF 驗證失敗，請重新整理頁面');

    $type = trim((string)($_POST['type'] ?? ''));
    $from = (string)($_POST['from_id'] ?? '');   // 製令的 id 是編號字串，不可一律轉 int
    $to   = (string)($_POST['to_id'] ?? '');
    $sk   = trim((string)($_POST['src_kind'] ?? ''));
    if ($type === '' || $from === '' || $to === '') sq_err('缺少對應的來源或目標');

    $u = ['id' => $uid, 'user_id' => $uid, 'user_cname' => $uname];
    $r = ($action === 'chain_link')
        ? tc_link($db, $type, $from, $to, (int)($_POST['qty'] ?? 0), $u, $sk)
        : tc_unlink($db, $type, $from, $to, $u, $sk);
    if (empty($r['success'])) sq_err($r['message'] ?? '操作失敗');
    sq_out($r);
}

default:
    sq_err('未知操作：' . $action, 404);
}
