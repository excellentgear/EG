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
$WRITE = ['settings_save', 'exempt_set', 'exempt_del', 'scope_save', 'run_save', 'print_log'];
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
    jout($r);
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
        if (isset($tol['quote_valid_days']) && (!is_numeric($tol['quote_valid_days'])
            || $tol['quote_valid_days'] < 0 || $tol['quote_valid_days'] > 3650))
            jerr('報價有效天數請填 0~3650');
        dqa_param_save($db, 'tolerance', $tol, $uname);
    }
    foreach ([['trace_items', null], ['fields_customer', 'customer'], ['fields_maker', 'maker']] as [$key, $t]) {
        $v = json_decode((string)($_POST[$key] ?? ''), true);
        if (!is_array($v)) continue;
        $allow = $t === null ? array_keys(dqa_trace_items()) : array_keys(dqa_master_fields($t));
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

/* ── 例外（已核可不列為缺失）────────────────────────── */
case 'exempt_set': {
    $scope = dqaIn('scope');
    if (!in_array($scope, ['customer', 'maker', 'trace'], true)) jerr('不支援的稽核對象');
    $key  = dqaIn('key');
    $item = dqaIn('item');
    if ($key === '' || $item === '') jerr('缺少必要參數');
    // 後端再驗一次：項目代碼必須是這個分頁真的有的檢核項目（鐵律8）
    $allow = $scope === 'trace' ? array_keys(dqa_trace_items()) : array_keys(dqa_master_fields($scope));
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
    if (!in_array($scope, ['customer', 'maker', 'trace'], true)) jerr('不支援的稽核對象');
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
