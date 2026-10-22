<?php
/**
 * PriceFillCompare_API.php — 訂單補單價比對頁（views/pm/price_fill_compare.php）專用，僅系統管理員。
 * list：回傳候選列（左：補資料／ERP 內容，右：訂單追蹤內容）；apply：批次轉價格（後端重新判定，不採信前端價格）
 */
$document_root = $_SERVER['DOCUMENT_ROOT'];
session_start();
include_once $document_root . '/EGsystem/src/common/_config.php';
include_once $document_root . '/EGsystem/src/common/DBConnection.php';
include_once $document_root . '/EGsystem/src/common/role_features_helper.php';
include_once $document_root . '/EGsystem/src/common/order_price_autofill_lib.php';

header('Content-Type: application/json; charset=utf-8');
set_exception_handler(function ($e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => '伺服器錯誤：' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
});
function pfcOut(array $a) { echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }

$uid = (int)($_SESSION['id'] ?? 0);
if (!$uid) { http_response_code(401); pfcOut(['ok' => false, 'error' => '尚未登入']); }
if (!rf_has_feature(rf_load_user_features($db, $uid), 'all')) { http_response_code(403); pfcOut(['ok' => false, 'error' => '僅管理員可使用']); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); pfcOut(['ok' => false, 'error' => '方法不允許']); }
set_time_limit(120); ini_set('memory_limit', '512M');

$action = $_GET['action'] ?? '';
switch ($action) {
case 'list': {
    $cat = (string)($_POST['cat'] ?? '');
    $q   = trim((string)($_POST['q'] ?? ''));
    $page = max(1, (int)($_POST['page'] ?? 1));
    $per  = min(200, max(10, (int)($_POST['per'] ?? 50)));
    $closed = opf_auto_close_exact($db);   // 訂單內已有相同明細的待轉入列，自動認定為已存在
    $all = opf_candidates($db);
    $counts = []; foreach ($all as $c) $counts[$c['cat']] = ($counts[$c['cat']] ?? 0) + 1;
    $rows = array_values(array_filter($all, function ($c) use ($cat, $q) {
        if ($cat !== '' && $c['cat'] !== $cat) return false;
        if ($q !== '') { $h = $c['order']['Order_oo'] . ' ' . $c['order']['d_id'] . ' ' . $c['order']['Client_name'] . ' ' . $c['order']['Processing_items'] . ' ' . $c['order']['Order_id'];
            foreach (preg_split('/\s+/u', $q) as $w) if ($w !== '' && mb_stripos($h, $w) === false) return false; }
        return true;
    }));
    usort($rows, fn($a, $b) => strcmp((string)$b['order']['Order_oo'], (string)$a['order']['Order_oo']) ?: $a['order_id'] <=> $b['order_id']);
    $total = count($rows);
    if (!empty($_POST['ids_only'])) pfcOut(['ok' => true, 'ids' => array_map(fn($c) => (int)$c['order_id'], $rows)]);   // 一次全選：回傳符合條件的全部 id
    $rows = array_slice($rows, ($page - 1) * $per, $per);
    // 客戶名稱（已綁定者取主檔）
    $cid = array_values(array_unique(array_filter(array_map(fn($c) => $c['order']['Client_name_ID'], $rows))));
    $cn = [];
    if ($cid) { $s = $db->prepare("SELECT customer_id,customer FROM customer_list WHERE customer_id IN (" . implode(',', array_fill(0, count($cid), '?')) . ")"); $s->execute($cid); foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) $cn[$r['customer_id']] = $r['customer']; }
    foreach ($rows as &$r) $r['order']['client_display'] = $cn[$r['order']['Client_name_ID']] ?? $r['order']['Client_name'];
    unset($r);
    pfcOut(['ok' => true, 'total' => $total, 'page' => $page, 'per' => $per, 'counts' => $counts, 'rows' => $rows, 'auto_closed' => $closed, 'can_apply' => OPF_APPLY_CATS]);
}
case 'apply': {
    $ids = json_decode($_POST['ids'] ?? '[]', true);
    $cat = (string)($_POST['cat'] ?? '');
    if (!is_array($ids) || !$ids) pfcOut(['ok' => false, 'error' => '請先勾選']);
    if (count($ids) > 5000) pfcOut(['ok' => false, 'error' => '一次最多 5000 筆']);
    set_time_limit(600);
    $r = opf_apply($db, $ids, $cat, $uid);
    pfcOut(['ok' => true] + $r);
}
default: pfcOut(['ok' => false, 'error' => '未知的動作']);
}
