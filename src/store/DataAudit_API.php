<?php
/**
 * 資料稽核 API —— 2026-09-18 建立
 *
 * 權限（data_audit_lib.php dqa_perms()）：
 *   dqa_admin ＝改設定、標記例外、留存稽核結果
 *   dqa_view  ＝唯讀（含列印、匯出）
 * 前端擋一次、後端同規則再擋一次（鐵律8）。
 */
$document_root = $_SERVER['DOCUMENT_ROOT'];
session_start();
require_once __DIR__ . '/../common/api_guard.php';
include_once $document_root . '/EGsystem/src/common/_config.php';
include_once $document_root . '/EGsystem/src/common/DBConnection.php';
include_once $document_root . '/EGsystem/src/common/data_audit_lib.php';
include_once $document_root . '/EGsystem/src/common/asdoc_lib.php';
include_once $document_root . '/EGsystem/src/common/print_log_lib.php';

function jout($a = []) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['ok' => true], $a), JSON_UNESCAPED_UNICODE);
    exit;
}
function jerr($msg, $code = 400, $extra = []) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($code);
    echo json_encode(array_merge(['ok' => false, 'error' => $msg], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

/* 未捕捉的例外一律轉成 JSON。不裝這個的話 PHP 會回 HTTP 500 空白內容，
   前端 ajaxError 只拿得到「操作失敗（HTTP 500）」，畫面上等於「按了沒反應」，最難查。
   （2026-09-21 實際踩到：舊資料指向已刪除的訂單，外鍵擋下就是這個症狀。） */
set_exception_handler(function (Throwable $e) {
    if (!headers_sent()) { header('Content-Type: application/json; charset=utf-8'); http_response_code(500); }
    error_log('DataAudit_API 未捕捉例外：' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    echo json_encode(['ok' => false, 'error' => '伺服器發生錯誤：' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
});

try {
    $db = (new DBConnection())->getPDO();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    dqa_ensure_schema($db);
} catch (Throwable $e) { jerr('DB連線失敗：' . $e->getMessage(), 500); }

if (empty($_SESSION['dqa_csrf'])) $_SESSION['dqa_csrf'] = bin2hex(random_bytes(16));

$P = dqa_perms($db, dqa_current_user($db));
$uid = (int)$P['uid']; $uname = (string)$P['name'];
if (!$uid)          jerr('未登入或帳號非在職狀態', 401);
if (!$P['canView']) jerr('您沒有資料稽核的檢閱權限，請洽管理員於「使用者權限設定」開通', 403);

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$WRITE = ['settings_save', 'exempt_set', 'exempt_del', 'scope_save', 'run_save', 'print_log',
          'excl_save', 'excl_toggle', 'excl_del', 'bind', 'unbind'];
if (in_array($action, $WRITE, true)) {
    $tok = $_POST['csrf'] ?? '';
    if (!is_string($tok) || $tok === '' || !hash_equals((string)$_SESSION['dqa_csrf'], $tok))
        jerr('連線憑證失效，請重新整理頁面後再試 (CSRF)', 400, ['code' => 'CSRF']);
    if (!$P['canAdmin']) jerr('需要「資料稽核管理員」權限才能執行這個動作', 403);
}

function dqaIn($k, $d = '') { return trim((string)($_GET[$k] ?? $_POST[$k] ?? $d)); }

switch ($action) {

/* ── 流程順序稽核 ───────────────────────────────────── */
case 'trace_list': {
    $r = dqa_trace_rows($db, [
        'from'        => dqaIn('from'),
        'to'          => dqaIn('to'),
        'client'      => dqaIn('client'),
        'part'        => dqaIn('part'),
        'cmp_process' => dqaIn('cmp_process') === '1',
        'only_bad'    => dqaIn('only_bad') === '1',
        'limit'       => (int)dqaIn('limit', '3000'),
    ]);
    $r['items']  = dqa_trace_items();
    $r['levels'] = dqa_trace_levels($db);
    $r['scope']  = dqa_scope_docs_info($db, 'trace');
    $r['can_bind'] = $P['canAdmin'];
    jout($r);
}

/* ── 綁定之後只重算這幾列（不必整份重跑）──────────────── */
case 'trace_recheck': {
    $ids = json_decode((string)($_POST['order_ids'] ?? $_GET['order_ids'] ?? '[]'), true);
    if (!is_array($ids) || !$ids) jerr('缺少要重算的訂單');
    $r = dqa_trace_rows($db, [
        'order_ids'   => $ids,
        'cmp_process' => dqaIn('cmp_process') === '1',
        'only_bad'    => false,          // 剛綁完可能已經沒有缺失了，仍然要把那一列回傳給畫面換掉
    ]);
    jout(['rows' => $r['rows']]);
}

/* ── 分頁三：報價項目追蹤（報價了有沒有下單／出貨／收款）── */
case 'quote_list': {
    $r = dqa_quote_rows($db, [
        'from'     => dqaIn('from'),
        'to'       => dqaIn('to'),
        'client'   => dqaIn('client'),
        'part'     => dqaIn('part'),
        'only_bad' => dqaIn('only_bad') === '1',
        'limit'    => (int)dqaIn('limit', '3000'),
    ]);
    $r['can_bind'] = $P['canAdmin'];
    jout($r);
}

/* ── 整張報價單的內容（含治具／刀具那幾列有沒有訂單與出貨）── */
case 'quote_detail': {
    $r = dqa_quote_detail($db, (int)dqaIn('quote_id'), (int)dqaIn('item_id'));
    if (!empty($r['error'])) jerr($r['error'], 404);
    jout($r);
}

/* ── 綁定用的候選單據 ───────────────────────────────── */
case 'node_candidates': {
    $kind = dqaIn('kind');
    if (!in_array($kind, ['quote', 'bom', 'ship'], true)) jerr('不支援的節點：' . $kind);
    $r = dqa_node_candidates($db, (int)dqaIn('order_id'), $kind, [
        'kw'         => dqaIn('kw'),
        'all_client' => dqaIn('all_client') === '1',
        'same_part'  => dqaIn('same_part') !== '0',
    ]);
    if (!empty($r['error'])) jerr($r['error'], 404);
    $r['can_bind'] = $P['canAdmin'];
    jout($r);
}

/* ── 建立／解除綁定（一律走全站唯一的綁定引擎 trace_chain_lib）──
 * 前端擋一次、這裡同規則再擋一次（鐵律8）：節點代碼白名單、訂單必須存在、
 * 數量與客戶的檢查則由 tc_link() 自己做，不在這裡再寫第二份規則。 */
case 'bind':
case 'unbind': {
    $kind = dqaIn('kind');
    $map  = ['quote' => 'quote_order', 'bom' => 'order_bom', 'ship' => 'order_ship'];
    if (!isset($map[$kind])) jerr('不支援的節點：' . $kind);
    $orderId = (int)dqaIn('order_id');
    if ($orderId <= 0) jerr('缺少訂單');
    $chk = $db->prepare("SELECT COUNT(*) FROM order_track WHERE Order_id=?");
    $chk->execute([$orderId]);
    if ((int)$chk->fetchColumn() === 0) jerr('找不到這張訂單', 404);

    $type = $map[$kind];
    $u = ['id' => $uid, 'user_id' => $uid, 'user_cname' => $uname];
    /* 方向：報價→訂單是 (報價項目, 訂單)，訂單→製令／出貨是 (訂單, 目標) */
    $target = (string)dqaIn('target');            // 製令是編號字串，不可一律轉 int
    if ($target === '') jerr('缺少要綁定的單據');
    $qty = (int)dqaIn('qty', '0');

    if ($kind === 'quote') { $fromId = (int)$target; $toId = $orderId; }
    else                   { $fromId = $orderId;     $toId = $target; }

    $r = ($action === 'bind')
        ? tc_link($db, $type, $fromId, $toId, $qty, $u)
        : tc_unlink($db, $type, $fromId, $toId, $u);
    if (empty($r['success'])) jerr($r['message'] ?? '操作失敗');

    // 綁完直接把重算過的那一列回傳，畫面不必再打一支
    $re = dqa_trace_rows($db, ['order_ids' => [$orderId], 'only_bad' => false]);
    jout(['msg' => $r['message'], 'warn' => $r['warn'] ?? [], 'rows' => $re['rows']]);
}

/* ── 基本資料稽核 ───────────────────────────────────── */
case 'master_list': {
    $type = dqaIn('type') === 'maker' ? 'maker' : 'customer';
    $r = dqa_master_rows($db, $type, [
        'q'                => dqaIn('q'),
        'only_bad'         => dqaIn('only_bad') === '1',
        'include_inactive' => dqaIn('include_inactive') === '1',
    ]);
    $r['type']   = $type;
    $r['fields'] = dqa_master_fields($type);
    $r['scope']  = dqa_scope_docs_info($db, 'master');
    jout($r);
}

/* ── 年度清單（期間切換用：只列真的有訂單的年度）────── */
case 'trace_years': {
    $rows = $db->query("SELECT DISTINCT YEAR(Order_date) y FROM order_track
                         WHERE Order_date IS NOT NULL ORDER BY y DESC")->fetchAll(PDO::FETCH_COLUMN);
    $ys = [];
    foreach ($rows as $y) { $y = (int)$y; if ($y >= 2000 && $y <= 2100) $ys[] = $y; }
    jout(['years' => $ys]);
}

/* ── 篩選用的客戶清單（流程稽核上方的下拉）───────────── */
case 'trace_clients': {
    $from = dqaIn('from'); $to = dqaIn('to');
    $st = $db->prepare("SELECT Client_name c, COUNT(*) n FROM order_track
                         WHERE Order_date >= ? AND Order_date <= ? AND Client_name <> ''
                         GROUP BY Client_name ORDER BY n DESC");
    $st->execute([$from ?: date('Y-01-01'), $to ?: date('Y-12-31')]);
    jout(['rows' => $st->fetchAll(PDO::FETCH_ASSOC)]);
}

/* ── 設定 ───────────────────────────────────────────── */
case 'settings_get': {
    jout([
        'code_rule'  => dqa_code_rule($db),
        'rule_text'  => dqa_code_rule_text(dqa_code_rule($db)),
        'tolerance'  => dqa_tolerance($db),
        'trace_items' => dqa_trace_items(),
        'trace_levels' => dqa_trace_levels($db),
        'quote_items'  => dqa_quote_items(),
        'quote_levels' => dqa_quote_levels($db),
        'fields_customer' => dqa_master_fields('customer'),
        'fields_maker'    => dqa_master_fields('maker'),
        'levels_customer' => dqa_master_levels($db, 'customer'),
        'levels_maker'    => dqa_master_levels($db, 'maker'),
        'scope_trace'  => dqa_scope_docs_info($db, 'trace'),
        'scope_master' => dqa_scope_docs_info($db, 'master'),
        'as_docs'      => eg_asdoc_list($db),
        'can_admin'    => $P['canAdmin'],
    ]);
}

case 'settings_save': {
    $rule = json_decode((string)($_POST['code_rule'] ?? ''), true);
    if (is_array($rule)) {
        $c = [];
        foreach (['letters', 'digits', 'suffix', 'allow_suffix', 'upper_only'] as $k)
            if (isset($rule[$k]) && is_numeric($rule[$k])) $c[$k] = (int)$rule[$k];
        if (($c['letters'] ?? 2) < 1 || ($c['letters'] ?? 2) > 5) jerr('英文碼數請填 1~5');
        if (($c['digits'] ?? 3)  < 1 || ($c['digits'] ?? 3)  > 8) jerr('數字碼數請填 1~8');
        dqa_param_save($db, 'code_rule', $c, $uname);
    }
    $tol = json_decode((string)($_POST['tolerance'] ?? ''), true);
    if (is_array($tol)) {
        foreach (['qty_pct', 'price_pct'] as $k)
            if (isset($tol[$k]) && (!is_numeric($tol[$k]) || $tol[$k] < 0 || $tol[$k] > 100))
                jerr('容許誤差請填 0~100 的數字');
        foreach (['quote_valid_days', 'no_order_days'] as $k)
            if (isset($tol[$k]) && (!is_numeric($tol[$k]) || $tol[$k] < 0 || $tol[$k] > 3650))
                jerr('天數請填 0~3650');
        dqa_param_save($db, 'tolerance', $tol, $uname);
    }
    foreach ([['trace_items', null], ['quote_items', 'q'],
              ['fields_customer', 'customer'], ['fields_maker', 'maker']] as [$key, $t]) {
        $v = json_decode((string)($_POST[$key] ?? ''), true);
        if (!is_array($v)) continue;
        $allow = $t === null ? array_keys(dqa_trace_items())
               : ($t === 'q' ? array_keys(dqa_quote_items()) : array_keys(dqa_master_fields($t)));
        $c = [];
        foreach ($v as $k => $lv) {
            if (!in_array((string)$k, $allow, true)) continue;
            if (!in_array($lv, ['critical', 'major', 'minor', 'warn', 'off'], true)) continue;
            $c[(string)$k] = $lv;
        }
        dqa_param_save($db, $key, $c, $uname);
    }
    jout(['msg' => '設定已儲存']);
}

/* ── 稽核對象（綁定的 AS 表單編號，多選）─────────────── */
case 'scope_save': {
    $tab = dqaIn('tab') === 'master' ? 'master' : 'trace';
    $ids = json_decode((string)($_POST['ids'] ?? '[]'), true);
    if (!is_array($ids)) $ids = [];
    if (count($ids) > 50) jerr('一個分頁最多綁定 50 份表單');
    $saved = dqa_scope_save($db, $tab, $ids, $uname);
    jout(['ids' => $saved, 'docs' => dqa_scope_docs_info($db, $tab),
          'msg' => '已儲存，共 ' . count($saved) . ' 份表單']);
}

/* ── 排除設定（整個客戶／廠商／料號不列為缺失）────────── */
case 'excl_list': {
    jout(['rows' => dqa_excl_list($db), 'dims' => dqa_excl_dims(),
          'tabs' => dqa_excl_tabs(), 'items' => dqa_trace_items()]);
}

case 'excl_search': {
    $dim = dqaIn('dim');
    if (!isset(dqa_excl_dims()[$dim])) jerr('不支援的排除維度');
    jout(['rows' => dqa_excl_search($db, $dim, dqaIn('kw'))]);
}

case 'excl_save': {
    $tabs  = json_decode((string)($_POST['tabs'] ?? '[]'), true);
    $items = json_decode((string)($_POST['items'] ?? '[]'), true);
    try {
        $r = dqa_excl_save($db, [
            'dim' => dqaIn('dim'), 'val' => dqaIn('val'),
            'tabs' => is_array($tabs) ? $tabs : [], 'items' => is_array($items) ? $items : [],
            'reason' => dqaIn('reason'),
        ], $uid, $uname);
    } catch (Throwable $e) { jerr($e->getMessage()); }
    jout(['saved' => $r, 'rows' => dqa_excl_list($db), 'msg' => '排除設定已儲存']);
}

case 'excl_toggle': {
    $id = (int)dqaIn('id');
    if ($id <= 0) jerr('缺少規則編號');
    dqa_excl_set_active($db, $id, dqaIn('on') === '1');
    jout(['rows' => dqa_excl_list($db), 'msg' => dqaIn('on') === '1' ? '已啟用' : '已停用（不再排除）']);
}

case 'excl_del': {
    $id = (int)dqaIn('id');
    if ($id <= 0) jerr('缺少規則編號');
    dqa_excl_del($db, $id);
    jout(['rows' => dqa_excl_list($db), 'msg' => '已刪除這條排除設定']);
}

/* ── 例外（已核可不列為缺失）────────────────────────── */
case 'exempt_set': {
    $scope = dqaIn('scope');
    if (!in_array($scope, ['customer', 'maker', 'trace', 'quote'], true)) jerr('不支援的稽核對象');
    $key  = dqaIn('key');
    $item = dqaIn('item');
    if ($key === '' || $item === '') jerr('缺少必要參數');
    // 後端再驗一次：項目代碼必須是這個分頁真的有的檢核項目（鐵律8）
    $allow = $scope === 'trace' ? array_keys(dqa_trace_items())
           : ($scope === 'quote' ? array_keys(dqa_quote_items()) : array_keys(dqa_master_fields($scope)));
    $allow[] = '*';
    if ($scope !== 'trace') $allow[] = 'dup_name';
    if (!in_array($item, $allow, true)) jerr('不支援的檢核項目：' . $item);
    $reason = dqaIn('reason');
    if (mb_strlen($reason) > 300) jerr('原因請控制在 300 字以內');
    dqa_exempt_set($db, $scope, $key, $item, $reason, $uid, $uname);
    jout(['msg' => '已標記為已核可的例外']);
}

case 'exempt_del': {
    $scope = dqaIn('scope');
    if (!in_array($scope, ['customer', 'maker', 'trace', 'quote'], true)) jerr('不支援的稽核對象');
    $key = dqaIn('key'); $item = dqaIn('item');
    if ($key === '' || $item === '') jerr('缺少必要參數');
    dqa_exempt_del($db, $scope, $key, $item);
    jout(['msg' => '已取消例外，這一項會重新納入稽核']);
}

/* ── 留存稽核結果（供內部稽核引用）──────────────────── */
case 'run_save': {
    $tab = dqaIn('tab') === 'master' ? 'master' : 'trace';
    $d = [
        'tab' => $tab, 'sub_type' => dqaIn('sub_type'),
        'from' => dqaIn('from'), 'to' => dqaIn('to'),
        'total' => (int)dqaIn('total'), 'critical' => (int)dqaIn('critical'), 'warn' => (int)dqaIn('warn'),
        'scope' => dqa_scope_docs($db, $tab),
        'stat' => json_decode((string)($_POST['stat'] ?? '[]'), true) ?: [],
        'note' => dqaIn('note'),
    ];
    if (!$d['scope']) jerr('請先在「稽核對象」綁定這次稽核涵蓋哪幾份 AS 表單，內部稽核才帶得出資料');
    $id = dqa_run_save($db, $d, $uid, $uname);
    jout(['id' => $id, 'msg' => '已留存，內部稽核可引用這次結果', 'rows' => dqa_run_list($db, 30)]);
}

case 'run_list': {
    jout(['rows' => dqa_run_list($db, (int)dqaIn('limit', '30'))]);
}

case 'print_log': {
    $name = dqaIn('doc_name');
    if ($name === '') jerr('缺少文件名稱');
    eg_print_log_add($db, ['source' => 'data_audit', 'doc_name' => $name, 'part_no' => dqaIn('part_no')]);
    jout();
}

default:
    jerr('無效的操作：' . $action);
}
