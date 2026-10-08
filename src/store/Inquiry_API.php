<?php
/**
 * Inquiry_API.php — 詢價單（業務／生管／採購共用）資料介面
 * 共用邏輯一律在 src/common/inquiry_lib.php；本檔只負責登入／權限／CSRF 守門與參數整理。
 */
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../common/_config.php';
require_once __DIR__ . '/../common/DBConnection.php';
require_once __DIR__ . '/../common/inquiry_lib.php';
require_once __DIR__ . '/../common/asdoc_lib.php';
require_once __DIR__ . '/../common/print_log_lib.php';

function jout($ok, $data = []) { echo json_encode(array_merge(['success' => $ok], is_array($data) ? $data : ['message' => $data]), JSON_UNESCAPED_UNICODE); exit; }
function jerr($msg, $code = '') { jout(false, ['message' => $msg, 'code' => $code]); }

$uid = (int)($_SESSION['id'] ?? 0);
if ($uid <= 0) { http_response_code(401); jerr('尚未登入或登入已逾時，請重新整理頁面後再試', 'LOGIN'); }

$db = (new DBConnection())->getPDO();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
set_exception_handler(function ($e) { http_response_code(500); jout(false, ['message' => '系統發生例外：' . $e->getMessage()]); });

inq_ensure_schema($db);
$perms = inq_perms($db, $uid);
if (!$perms['canView']) { http_response_code(403); jerr('沒有詢價單的使用權限'); }

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$isWrite = isset($_POST['action']);
if ($isWrite) {
    if (empty($_SESSION['inq_csrf'])) jerr('連線憑證失效，請重新整理頁面後再試 (CSRF)', 'CSRF');
    $tok = $_POST['csrf'] ?? '';
    if (!hash_equals((string)$_SESSION['inq_csrf'], (string)$tok)) jerr('連線憑證失效，請重新整理頁面後再試 (CSRF)', 'CSRF');
}

switch ($action) {

case 'list': {
    $opt = [
        'dept'      => (string)($_GET['dept'] ?? ''),
        'status'    => (string)($_GET['status'] ?? ''),
        'date_from' => (string)($_GET['date_from'] ?? ''),
        'date_to'   => (string)($_GET['date_to'] ?? ''),
        'kw'        => trim((string)($_GET['kw'] ?? '')),
    ];
    $rows = inq_group_list($db, $opt);
    foreach ($rows as &$r) {
        $r['docs'] = inq_group_docs($db, (int)$r['id']);
        foreach ($r['docs'] as &$d) { $d['items'] = inq_doc_items($db, (int)$d['id']); }
    }
    jout(true, ['rows' => $rows, 'dept_set' => inq_dept_settings_all($db)]);
}

case 'get': {
    $gid = (int)($_GET['id'] ?? 0);
    $g = inq_group_full($db, $gid);
    if (!$g) jerr('找不到這個詢價案');
    jout(true, ['group' => $g]);
}

case 'vendors': {
    jout(true, ['rows' => inq_vendor_list($db)]);
}

case 'part_search': {
    jout(true, ['rows' => inq_part_search($db, (string)($_GET['kw'] ?? ''))]);
}

case 'bom_search': {
    jout(true, ['rows' => inq_bom_search($db, (string)($_GET['kw'] ?? ''))]);
}

case 'preq_search': {
    jout(true, ['rows' => inq_preq_search($db, (string)($_GET['kw'] ?? ''))]);
}

case 'dept_settings': {
    jout(true, ['dept_set' => inq_dept_settings_all($db)]);
}

case 'create': {
    if (!$perms['canCreate']) jerr('沒有建立詢價單的權限');
    $dept = (string)($_POST['source_dept'] ?? '');
    if (!$perms['canAdmin'] && !in_array($dept, $perms['myDepts'], true)) {
        jerr('不是這個部門的人員，不能用這個部門身分建立詢價單');
    }
    $items = json_decode((string)($_POST['items'] ?? '[]'), true) ?: [];
    $vendorIds = json_decode((string)($_POST['vendor_ids'] ?? '[]'), true) ?: [];
    $p = [
        'source_dept'   => $dept,
        'items'         => $items,
        'vendor_ids'    => $vendorIds,
        'bind_type'     => (string)($_POST['bind_type'] ?? ''),
        'bind_ref'      => (string)($_POST['bind_ref'] ?? ''),
        'requester_id'  => (int)($_POST['requester_id'] ?? $uid),
        'requester_name'=> (string)($_POST['requester_name'] ?? ''),
        'inquiry_date'  => (string)($_POST['inquiry_date'] ?? date('Y-m-d')),
        'currency'      => (string)($_POST['currency'] ?? 'NTD'),
        'note'          => (string)($_POST['note'] ?? ''),
    ];
    $res = inq_group_create($db, $p, $uid, $perms['name']);
    if (!$res['success']) jerr($res['message']);
    jout(true, $res);
}

case 'update_group': {
    $gid = (int)($_POST['id'] ?? 0);
    $g = inq_group_row($db, $gid);
    if (!$g) jerr('找不到這個詢價案');
    if (!inq_can_edit_dept($perms, $g['source_dept'])) jerr('沒有這個部門的編輯權限');
    $items = json_decode((string)($_POST['items'] ?? 'null'), true);
    $p = [
        'inquiry_date'    => (string)($_POST['inquiry_date'] ?? $g['inquiry_date']),
        'currency'        => (string)($_POST['currency'] ?? $g['currency']),
        'note'            => (string)($_POST['note'] ?? ($g['note'] ?? '')),
        'requester_name'  => (string)($_POST['requester_name'] ?? $g['requester_name']),
        'items'           => is_array($items) ? $items : null,
    ];
    $res = inq_group_update($db, $gid, $p, $uid);
    if (!$res['success']) jerr($res['message']);
    jout(true, $res);
}

case 'bind': {
    $gid = (int)($_POST['id'] ?? 0);
    $g = inq_group_row($db, $gid);
    if (!$g) jerr('找不到這個詢價案');
    if (!inq_can_edit_dept($perms, $g['source_dept'])) jerr('沒有這個部門的編輯權限');
    $res = inq_group_bind($db, $gid, (string)($_POST['bind_type'] ?? ''), (string)($_POST['bind_ref'] ?? ''), $uid);
    if (!$res['success']) jerr($res['message']);
    jout(true, $res);
}

case 'add_vendors': {
    $gid = (int)($_POST['id'] ?? 0);
    $g = inq_group_row($db, $gid);
    if (!$g) jerr('找不到這個詢價案');
    if (!inq_can_edit_dept($perms, $g['source_dept'])) jerr('沒有這個部門的編輯權限');
    $vendorIds = json_decode((string)($_POST['vendor_ids'] ?? '[]'), true) ?: [];
    $res = inq_group_add_vendors($db, $gid, $vendorIds, $uid);
    if (!$res['success']) jerr($res['message']);
    jout(true, $res);
}

case 'update_doc': {
    $did = (int)($_POST['id'] ?? 0);
    $d = inq_doc_row($db, $did);
    if (!$d) jerr('找不到這張子單');
    $g = inq_group_row($db, (int)$d['group_id']);
    if (!$g || !inq_can_edit_dept($perms, $g['source_dept'])) jerr('沒有這個部門的編輯權限');
    $items = json_decode((string)($_POST['items'] ?? 'null'), true);
    $p = ['items' => is_array($items) ? $items : null, 'status' => (string)($_POST['status'] ?? '')];
    $res = inq_doc_update($db, $did, $p, $uid);
    if (!$res['success']) jerr($res['message']);
    jout(true, $res);
}

case 'doc_refollow': {
    $did = (int)($_POST['id'] ?? 0);
    $d = inq_doc_row($db, $did);
    if (!$d) jerr('找不到這張子單');
    $g = inq_group_row($db, (int)$d['group_id']);
    if (!$g || !inq_can_edit_dept($perms, $g['source_dept'])) jerr('沒有這個部門的編輯權限');
    $res = inq_doc_refollow($db, $did, $uid);
    if (!$res['success']) jerr($res['message']);
    jout(true, $res);
}

case 'doc_delete': {
    $did = (int)($_POST['id'] ?? 0);
    $d = inq_doc_row($db, $did);
    if (!$d) jerr('找不到這張子單');
    $g = inq_group_row($db, (int)$d['group_id']);
    if (!$g || !inq_can_edit_dept($perms, $g['source_dept'])) jerr('沒有這個部門的編輯權限');
    $res = inq_doc_delete($db, $did, $uid);
    if (!$res['success']) jerr($res['message']);
    jout(true, $res);
}

case 'group_delete': {
    $gid = (int)($_POST['id'] ?? 0);
    $g = inq_group_row($db, $gid);
    if (!$g) jerr('找不到這個詢價案');
    if (!inq_can_edit_dept($perms, $g['source_dept'])) jerr('沒有這個部門的編輯權限');
    $res = inq_group_delete($db, $gid, $uid);
    if (!$res['success']) jerr($res['message']);
    jout(true, $res);
}

case 'group_status': {
    $gid = (int)($_POST['id'] ?? 0);
    $g = inq_group_row($db, $gid);
    if (!$g) jerr('找不到這個詢價案');
    if (!inq_can_edit_dept($perms, $g['source_dept'])) jerr('沒有這個部門的編輯權限');
    $res = inq_group_set_status($db, $gid, (string)($_POST['status'] ?? ''), $uid);
    if (!$res['success']) jerr($res['message']);
    jout(true, $res);
}

case 'print_data': {
    $did = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
    $data = inq_print_doc_data($db, $did);
    if (!$data) jerr('找不到這張子單');
    $data['as_doc'] = eg_asdoc_get($db, 'inquiry');
    $data['as_no']  = eg_asdoc_no_asof($db, 'inquiry', $data['group']['inquiry_date']);
    jout(true, $data);
}

case 'print_log': {
    $did = (int)($_POST['id'] ?? 0);
    $d = inq_doc_row($db, $did);
    if ($d) {
        eg_print_log_add($db, [
            'source'   => 'inquiry',
            'doc_kind' => 'form',
            'ref_table'=> 'inq_doc', 'ref_id' => $did,
            'doc_name' => '詢價單 ' . $d['doc_no'],
            'part_no'  => '',
            'user_id'  => $uid,
        ]);
        $db->prepare("UPDATE inq_doc SET printed_at=NOW() WHERE id=?")->execute([$did]);
    }
    jout(true);
}

/* ── AS 文件綁定（ai-rules/16 第一之三節，僅模組管理員） ── */
case 'asdoc_list': { jout(true, ['docs' => eg_asdoc_list($db)]); }
case 'asdoc_get':  { jout(true, ['as_doc' => eg_asdoc_get($db, 'inquiry')]); }
case 'as_doc_save': {
    if (!$perms['canAdmin']) jerr('沒有管理員權限');
    eg_asdoc_save($db, 'inquiry', (int)($_POST['doc_id'] ?? 0), (string)($_SESSION['userName'] ?? ''));
    jout(true, ['as_doc' => eg_asdoc_get($db, 'inquiry')]);
}

/* ── 部門使用規則設定（僅模組管理員） ── */
case 'dept_setting_save': {
    if (!$perms['canAdmin']) jerr('沒有管理員權限');
    $key = (string)($_POST['dept_key'] ?? '');
    $ok = inq_dept_setting_save($db, $key, (bool)($_POST['allow_free_part'] ?? 0), (bool)($_POST['allow_custom_spec'] ?? 0), $uid);
    if (!$ok) jerr('儲存失敗');
    jout(true, ['dept_set' => inq_dept_settings_all($db)]);
}

default:
    http_response_code(400);
    jerr('不支援的操作：' . $action);
}
