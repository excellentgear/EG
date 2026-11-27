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
include_once $document_root . '/EGsystem/src/common/asdoc_lib.php';         // AS 文件編號綁定（ai-rules/16 第一之三節）

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

    $pm = sq_print_meta($db);
    sq_out([
        'perms'      => $perms,
        'csrf'       => sq_csrf_token(),
        'today'      => date('Y-m-d'),
        'user'       => ['id' => $uid, 'name' => $uname],
        'clients'    => $clients,
        'warehouses' => $warehouses,
        'company'    => $pm['company'],
        'asdoc'      => $pm['asdoc'],
        'as_docs'    => $perms['canAsdoc'] ? eg_asdoc_list($db) : [],
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
    fputcsv($out, ['訂單號', '客戶單號', '客戶', '料號', '品名規格', '訂購量', '已出量', '未出量',
                   '可出量', '單價', '可出金額', '交期', '製令', '未完工製令目前製程', '訂單備註']);
    foreach ($rows as $x) {
        /* 未完工的製令要看得出走到哪一關（畫面上有、匯出也要有，否則匯出去就判斷不了）。
           文字用後端組好的 proc_full，不在這裡另外拼一種說法。 */
        $procs = [];
        foreach ($x['boms'] as $bv) {
            if (!empty($bv['undone'])) $procs[] = $bv['bom'] . '：' . ($bv['proc_full'] ?: '無製程資料');
        }
        fputcsv($out, [
            $x['order_oo'], $x['c_order'], $x['client_display'], $x['d_id'], $x['desc_full'],
            $x['order_qty'], $x['shipped_qty'], $x['remain_qty'], $x['ready_qty'],
            $x['unit_price'], $x['ready_qty'] * $x['unit_price'], $x['delivery_date'],
            implode(' ', array_column($x['boms'], 'bom')), implode('｜', $procs), $x['order_ps'],
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
    $rows = sq_shipment_detail($db, $no);
    // 版次依「出貨日期」回推（ai-rules/16 第三之四節：不可一律印現在最新版）
    $meta = sq_print_meta($db, $rows[0]['ship_date'] ?? null);
    sq_out(['rows' => $rows, 'company' => $meta['company'], 'asdoc' => $meta['asdoc'],
            'asdoc_no' => $meta['asdoc_no'], 'can_delete' => (bool)$perms['canDelete'],
            'can_edit' => (bool)$perms['canEdit']]);
}

/* ── 出貨明細備註：建立後可隨時補填／修改 ───────────────────────────── */
case 'note_save': {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') sq_err('必須用 POST', 405);
    if (!$perms['canEdit']) sq_err('無出貨登錄權限', 403);
    if (!sq_csrf_ok($_POST['csrf'] ?? '')) sq_err('CSRF 驗證失敗，請重新整理頁面');
    $r = sq_shipment_note_save($db, (int)($_POST['is_id'] ?? 0), (string)($_POST['note'] ?? ''),
                               ['id' => $uid, 'name' => $uname]);
    if (!$r['success']) sq_err($r['message']);
    sq_out($r);
}

/* ── 刪除出貨單（整張或指定明細）：數量回到原訂單、必要時取消結案 ──── */
case 'delete': {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') sq_err('必須用 POST', 405);
    if (!$perms['canDelete']) sq_err('無刪除出貨單權限', 403);
    if (!sq_csrf_ok($_POST['csrf'] ?? '')) sq_err('CSRF 驗證失敗，請重新整理頁面');

    $no   = trim((string)($_POST['is_number'] ?? ''));
    $ids  = json_decode((string)($_POST['is_ids'] ?? ''), true);
    // 「沒給 is_ids」＝整張刪；「給了空陣列」＝沒指定任何明細，一律擋下（兩者語意不同）
    if ($ids !== null && !is_array($ids)) $ids = null;
    if (is_array($ids) && !$ids) sq_err('沒有指定要刪除的明細');

    $r = sq_delete_shipment($db, $no, $ids, ['id' => $uid, 'name' => $uname]);
    if (!$r['success']) sq_err($r['message']);
    sq_out($r);
}

/* ── AS 文件編號綁定（列印頁尾右下角）───────────────────────────────── */
case 'asdoc_save': {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') sq_err('必須用 POST', 405);
    if (!$perms['canAsdoc']) sq_err('無設定 AS 文件綁定的權限', 403);
    if (!sq_csrf_ok($_POST['csrf'] ?? '')) sq_err('CSRF 驗證失敗，請重新整理頁面');

    $docId = (int)($_POST['as_doc_id'] ?? 0);
    if ($docId > 0) {
        $chk = $db->prepare("SELECT 1 FROM as_document WHERE id = ? AND is_deleted = 0");
        $chk->execute([$docId]);
        if (!$chk->fetchColumn()) sq_err('指定的 AS 文件不存在或已刪除');
    }
    eg_asdoc_save($db, SQ_ASDOC_MODULE, $docId, $uname);
    $pm = sq_print_meta($db);
    sq_out(['asdoc' => $pm['asdoc'], 'message' => $docId ? 'AS 文件編號已綁定' : '已清除 AS 文件綁定']);
}

/* ── 舊資料回填：找出 Order_id 為空、可對應到訂單的出貨單 ────────────── */
case 'match_preview': {
    if (!$perms['canMatch']) sq_err('無舊資料訂單回填的權限', 403);
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
    if (!$perms['canMatch']) sq_err('無舊資料訂單回填的權限', 403);
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
    if (!$perms['canMatch']) sq_err('無舊資料訂單回填的權限', 403);
    $src  = $_POST ?: $_GET;
    $isId = (int)($src['is_id'] ?? 0);
    if ($isId <= 0) sq_err('缺少出貨明細 id');
    $same = !isset($src['same_part']) || !empty($src['same_part']);
    sq_out(sq_match_candidates($db, $isId, $same, (string)($src['kw'] ?? '')));
}

/* ── 製令綁定舊出貨單（BOM總覽／完工BOM「待出貨」進來，已有訂單編號者）── */
case 'bom_bind_candidates': {
    if (!$perms['canBindOldShip']) sq_err('無綁定舊出貨單的權限', 403);
    $src = $_POST ?: $_GET;
    sq_out(sq_bom_bind_candidates($db, (string)($src['bom'] ?? '')));
}

case 'ship_mark_return': {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') sq_err('必須用 POST', 405);
    if (!$perms['canBindOldShip']) sq_err('無設定歸還的權限', 403);
    if (!sq_csrf_ok($_POST['csrf'] ?? '')) sq_err('CSRF 驗證失敗，請重新整理頁面');
    sq_out(sq_ship_set_return($db, (int)($_POST['is_id'] ?? 0), !empty($_POST['on']), (string)$uid, (string)($_POST['kind'] ?? 'return')));
}

case 'bom_bind_apply': {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') sq_err('必須用 POST', 405);
    if (!$perms['canBindOldShip']) sq_err('無綁定舊出貨單的權限', 403);
    if (!sq_csrf_ok($_POST['csrf'] ?? '')) sq_err('CSRF 驗證失敗，請重新整理頁面');
    $pairs = json_decode($_POST['pairs'] ?? '[]', true);
    if (!is_array($pairs) || !$pairs) sq_err('沒有要綁定的資料');
    sq_out(sq_bom_bind_apply($db, (string)($_POST['bom'] ?? ''), $pairs, (string)$uid, !empty($_POST['force'])));
}

/* ── 訂單還沒有製令：找可綁的製令並綁定（訂單→製令，走追溯對照同一套 tc_link）── */
case 'order_bom_candidates': {
    if (!$perms['canBindBom']) sq_err('無綁定製令的權限', 403);
    $src = $_POST ?: $_GET;
    sq_out(sq_order_bom_candidates($db, (int)($src['order_id'] ?? 0)));
}

case 'order_bom_bind': {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') sq_err('必須用 POST', 405);
    if (!$perms['canBindBom']) sq_err('無綁定製令的權限', 403);
    if (!sq_csrf_ok($_POST['csrf'] ?? '')) sq_err('CSRF 驗證失敗，請重新整理頁面');
    $pairs = json_decode($_POST['pairs'] ?? '[]', true);
    if (!is_array($pairs) || !$pairs) sq_err('沒有要綁定的製令');
    sq_out(sq_order_bom_bind($db, (int)($_POST['order_id'] ?? 0), $pairs, is_array($u ?? null) ? $u : ['id' => $uid]));
}

/* ── 外購／現貨：標記訂單免製令（空字串＝取消）── */
case 'order_src_set': {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') sq_err('必須用 POST', 405);
    if (!$perms['canBindBom']) sq_err('無設定免製令的權限（需有綁製令權限）', 403);
    if (!sq_csrf_ok($_POST['csrf'] ?? '')) sq_err('CSRF 驗證失敗，請重新整理頁面');
    sq_out(sq_order_src_set($db, (int)($_POST['order_id'] ?? 0), trim((string)($_POST['src'] ?? '')), is_array($u ?? null) ? $u : ['id' => $uid]));
}

case 'order_bom_auto_preview': {
    if (!$perms['canBindBom']) sq_err('無綁定製令的權限', 403);
    $ids = json_decode($_POST['order_ids'] ?? '[]', true);
    if (!is_array($ids) || !$ids) sq_err('沒有訂單');
    sq_out(sq_order_bom_auto_preview($db, $ids));
}

case 'order_bom_bind_batch': {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') sq_err('必須用 POST', 405);
    if (!$perms['canBindBom']) sq_err('無綁定製令的權限', 403);
    if (!sq_csrf_ok($_POST['csrf'] ?? '')) sq_err('CSRF 驗證失敗，請重新整理頁面');
    $items = json_decode($_POST['items'] ?? '[]', true);
    if (!is_array($items) || !$items) sq_err('沒有要綁定的資料');
    sq_out(sq_order_bom_bind_batch($db, $items, is_array($u ?? null) ? $u : ['id' => $uid]));
}

case 'match_scrap_boms': {
    if (!$perms['canMatch']) sq_err('無使用此功能的權限', 403);
    $src = $_POST ?: $_GET;
    sq_out(sq_match_scrap_boms($db, (int)($src['is_id'] ?? 0)));
}

case 'match_apply': {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') sq_err('必須用 POST', 405);
    if (!$perms['canMatch']) sq_err('無舊資料訂單回填的權限', 403);
    if (!sq_csrf_ok($_POST['csrf'] ?? '')) sq_err('CSRF 驗證失敗，請重新整理頁面');

    $pairs = json_decode($_POST['pairs'] ?? '[]', true);
    if (!is_array($pairs) || !$pairs) sq_err('沒有要回填的資料');
    sq_out(sq_match_apply($db, $pairs, (string)$uid));
}

/* ── 批次改單價：搜尋料號主檔（回料號、圖號、客戶，選定後以主檔 id 歸戶）── */
case 'price_part_search': {
    if (!$perms['canPriceBatch']) sq_err('無使用此功能的權限', 403);
    $kw = trim((string)(($_POST ?: $_GET)['kw'] ?? ''));
    if ($kw === '') sq_out(['parts' => []]);
    $like = '%' . addcslashes($kw, '%_\\') . '%';
    $st = $db->prepare("SELECT d.d_id, d.D_Setting_Id part, d.Drawing_No, TRIM(c.customer) client
                        FROM d_setting d LEFT JOIN customer_list c ON c.customer_id = d.Customer_Id
                        WHERE d.D_Setting_Id LIKE ? OR d.Drawing_No LIKE ? ORDER BY d.D_Setting_Id, d.d_id LIMIT 20");
    $st->execute([$like, $like]);
    sq_out(['parts' => $st->fetchAll(PDO::FETCH_ASSOC)]);
}

/* ── 批次修改出貨單價：料號＋期間內把單價 A 改成 B（非 A 的不動）；僅出貨管理員 ── */
case 'price_batch_preview':
case 'price_batch_apply': {
    if (!$perms['canPriceBatch']) sq_err('無批次修改單價的權限', 403);
    $apply = ($action === 'price_batch_apply');
    if ($apply) {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') sq_err('必須用 POST', 405);
        if (!sq_csrf_ok($_POST['csrf'] ?? '')) sq_err('CSRF 驗證失敗，請重新整理頁面');
    }
    $r = sq_price_batch($db, $_POST ?: $_GET, $apply, ['id' => $uid, 'name' => $uname]);
    if (!$r['success']) sq_err($r['message']);
    sq_out($r);
}

/* ── 追溯鏈：某客戶底下有資料的料號 ──────────────────────────────────── */
case 'chain_parts': {
    $src = $_POST ?: $_GET;
    $cli = trim((string)($src['client'] ?? ''));
    if ($cli === '') sq_err('請先選擇客戶');
    sq_out(['parts' => tc_parts($db, $cli)]);
}

case 'chain_part_search': {
    $src = $_POST ?: $_GET;
    sq_out(['parts' => tc_part_search($db, (string)($src['q'] ?? ''))]);
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
    $r['can_link'] = (bool)$perms['canChainLink'];
    sq_out($r);
}

/* ── 出貨單綁定總覽（訂單列「出貨綁定」用）：這張訂單綁了哪些出貨單、每張出貨單分給哪些訂單，
   以及同客戶同料號可改綁的候選訂單。寫入一律走 chain_link／chain_unlink（同一套 tc_link／tc_unlink）。── */
case 'ship_bind_info': {
    $src = $_POST ?: $_GET;
    $oid = (int)($src['order_id'] ?? 0);
    $isId = (int)($src['is_id'] ?? 0);
    $ids = [];
    if ($isId > 0) $ids[] = $isId;
    elseif ($oid > 0) {
        $q = $db->prepare("SELECT il.IS_id FROM is_list il LEFT JOIN is_order_map m ON m.IS_id=il.IS_id
                            WHERE m.Order_id=? OR il.Order_id=? GROUP BY il.IS_id ORDER BY il.Order_date, il.IS_id LIMIT 40");
        $q->execute([$oid, $oid]);
        $ids = array_map('intval', $q->fetchAll(PDO::FETCH_COLUMN));
    } else sq_err('缺少訂單或出貨單');
    $order = $oid > 0 ? tc_node($db, 'order', $oid) : null;
    $ships = []; $client = $order ? trim((string)$order['client']) : ''; $part = $order ? trim((string)$order['part']) : '';
    foreach ($ids as $id) {
        $n = tc_node($db, 'ship', $id);
        if (!$n) continue;
        if ($client === '') { $client = trim((string)$n['client']); }
        if ($part === '') { $part = trim((string)$n['part']); }
        $dq = $db->prepare("SELECT Order_date, Order_id FROM is_list WHERE IS_id=?"); $dq->execute([$id]);
        $d = $dq->fetch(PDO::FETCH_ASSOC);
        $aq = $db->prepare("SELECT m.Order_id, m.allocated_qty, ot.Order_oo, ot.Qty FROM is_order_map m
                              LEFT JOIN order_track ot ON ot.Order_id=m.Order_id WHERE m.IS_id=? ORDER BY m.allocated_qty DESC");
        $aq->execute([$id]);
        $al = []; $sum = 0;
        foreach ($aq->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $al[] = ['order_id' => (int)$r['Order_id'], 'oo' => (string)$r['Order_oo'], 'order_qty' => (int)$r['Qty'],
                     'qty' => (int)$r['allocated_qty'], 'legacy' => false];
            $sum += (int)$r['allocated_qty'];
        }
        if (!$al && (int)($d['Order_id'] ?? 0) > 0) { // 舊欄位綁定（分配表沒有列）：整張出貨量視為分給那張訂單
            $o2 = tc_node($db, 'order', (int)$d['Order_id']);
            if ($o2) { $al[] = ['order_id' => (int)$d['Order_id'], 'oo' => (string)$o2['no'], 'order_qty' => (int)$o2['qty'],
                               'qty' => (int)$n['qty'], 'legacy' => true]; $sum = (int)$n['qty']; }
        }
        $ships[] = ['is_id' => $id, 'no' => (string)$n['no'], 'date' => (string)($d['Order_date'] ?? ''),
                    'qty' => (int)$n['qty'], 'alloc' => $al, 'alloc_sum' => $sum];
    }
    $cands = [];
    if ($client !== '' && $part !== '') {
        $cq = $db->prepare("SELECT Order_id, Order_oo, Qty, Order_date FROM order_track
                             WHERE (Client_name=? OR Client_name='' OR Client_name IS NULL) AND d_id=? AND (Order_status IS NULL OR Order_status<>6)
                             ORDER BY Order_id DESC LIMIT 40");
        $cq->execute([$client, $part]);
        foreach ($cq->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $used = tc_allocated($db, 'order_ship', 'order', (int)$r['Order_id']);
            $cands[] = ['order_id' => (int)$r['Order_id'], 'oo' => (string)$r['Order_oo'], 'qty' => (int)$r['Qty'],
                        'allocated' => $used, 'free' => max(0, (int)$r['Qty'] - $used), 'date' => (string)$r['Order_date']];
        }
        usort($cands, fn($a, $b) => $a['order_id'] <=> $b['order_id']);
    }
    sq_out(['order' => $order, 'ships' => $ships, 'candidates' => $cands, 'can_link' => (bool)$perms['canChainLink']]);
}

/* ── 追溯鏈：建立／解除一條對應（拖放綁定）──────────────────────────── */
case 'chain_link':
case 'chain_unlink': {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') sq_err('必須用 POST', 405);
    if (!$perms['canChainLink']) sq_err('無建立或解除對應的權限', 403);
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
    // 解除「訂單→出貨單」後這張出貨單如果完全沒綁任何訂單，回報給畫面提醒（避免只做到一半）
    if ($action === 'chain_unlink' && $type === 'order_ship') {
        $oq = $db->prepare("SELECT COUNT(*) FROM is_order_map WHERE IS_id=?"); $oq->execute([(int)$to]);
        $lq = $db->prepare("SELECT COALESCE(Order_id,0) FROM is_list WHERE IS_id=?"); $lq->execute([(int)$to]);
        if ((int)$oq->fetchColumn() === 0 && (int)$lq->fetchColumn() === 0) $r['orphan_is_id'] = (int)$to;
    }
    sq_out($r);
}

default:
    sq_err('未知操作：' . $action, 404);
}
