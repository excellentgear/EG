<?php
/**
 * 產品開發評估表(2-TD-02-01) — 建議建立料號清單 API
 * 邏輯說明見 src/common/td_dev_eval_suggest_lib.php；權限沿用 td_dev_eval 模組角色（同一套設定）。
 */
header('Content-Type: application/json; charset=utf-8');

$document_root = $_SERVER['DOCUMENT_ROOT'];
session_start();
include_once $document_root . '/EGsystem/src/common/_config.php';
include_once $document_root . '/EGsystem/src/common/DBConnection.php';
include_once $document_root . '/EGsystem/src/common/td_dev_eval_lib.php';
include_once $document_root . '/EGsystem/src/common/td_dev_eval_suggest_lib.php';

if (!isset($_SESSION['userName'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '未登入']); exit;
}

$db = (new DBConnection())->getPDO();
td_dev_eval_ensure_schema($db);
td_dev_eval_suggest_ensure_schema($db);
$me    = td_dev_eval_current_user($db);
$perms = td_dev_eval_perms($db, $me);
$uid   = $me ? (int)$me['id'] : 0;
$uname = $me ? (string)$me['user_cname'] : '';

function jout($arr) { echo json_encode($arr, JSON_UNESCAPED_UNICODE); exit; }
function needView(array $perms) { if (!$perms['canView']) jout(['success'=>false,'message'=>'無檢閱權限']); }
function needEdit(array $perms) { if (!$perms['canEdit']) jout(['success'=>false,'message'=>'無登錄權限']); }
function needAdmin(array $perms) { if (!$perms['canAdmin']) jout(['success'=>false,'message'=>'無管理權限']); }

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {

case 'perms':
    jout(['success'=>true,'perms'=>$perms]);

case 'get_customer_setting':
    needView($perms);
    $st = $db->query("SELECT customer_id, customer FROM customer_list ORDER BY customer");
    $all = $st->fetchAll(PDO::FETCH_ASSOC);
    $selected = td_dev_eval_suggest_get_customers($db);
    jout(['success'=>true, 'all_customers'=>$all, 'selected_ids'=>array_keys($selected)]);

case 'save_customer_setting':
    needAdmin($perms);
    $ids = json_decode(file_get_contents('php://input'), true)['customer_ids'] ?? ($_POST['customer_ids'] ?? []);
    if (!is_array($ids)) jout(['success'=>false,'message'=>'格式錯誤']);
    td_dev_eval_suggest_save_customers($db, $ids, $uid);
    jout(['success'=>true]);

case 'list':
    needView($perms);
    $from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-1 year'));
    $to   = $_GET['date_to'] ?? date('Y-m-d');
    if (!td_dev_eval_suggest_get_customers($db)) jout(['success'=>true, 'rows'=>[], 'no_customer_configured'=>true]);
    $rows = td_dev_eval_suggest_candidates($db, $from, $to);
    jout(['success'=>true, 'rows'=>$rows]);

case 'history':
    needView($perms);
    $partDId = !empty($_GET['part_d_id']) ? (int)$_GET['part_d_id'] : null;
    $partText = trim((string)($_GET['part_no_text'] ?? ''));
    $custName = trim((string)($_GET['customer_name'] ?? ''));
    $from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-1 year'));
    $to   = $_GET['date_to'] ?? date('Y-m-d');
    if ($custName === '') jout(['success'=>false,'message'=>'缺客戶']);
    $rows = td_dev_eval_suggest_part_history($db, $partDId, $partText, $custName, $from, $to);
    jout(['success'=>true, 'rows'=>$rows]);

case 'ignore':
    needEdit($perms);
    $custId = trim((string)($_POST['customer_key'] ?? ''));
    $partKey = trim((string)($_POST['part_key'] ?? ''));
    $custName = trim((string)($_POST['customer_name'] ?? ''));
    $partText = trim((string)($_POST['part_no_text'] ?? ''));
    $note = trim((string)($_POST['note'] ?? ''));
    if ($custId === '' || $partKey === '') jout(['success'=>false,'message'=>'缺參數']);
    td_dev_eval_suggest_ignore_add($db, $custId, $partKey, $custName, $partText ?: null, $uid, $uname, $note);
    jout(['success'=>true]);

case 'ignore_list':
    needEdit($perms);
    jout(['success'=>true, 'rows'=>td_dev_eval_suggest_ignore_list($db)]);

case 'ignore_remove':
    needEdit($perms);
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) jout(['success'=>false,'message'=>'缺id']);
    td_dev_eval_suggest_ignore_remove($db, $id);
    jout(['success'=>true]);

case 'bulk_create':
    needEdit($perms);
    $rows = json_decode(file_get_contents('php://input'), true)['rows'] ?? [];
    if (!is_array($rows) || !$rows) jout(['success'=>false,'message'=>'未選擇任何項目']);
    $result = td_dev_eval_suggest_bulk_create($db, $rows, $uid, $uname);
    jout(['success'=>true] + $result);

case 'pending_count':
    needView($perms);
    jout(['success'=>true, 'count'=>td_dev_eval_suggest_pending_count($db)]);

default:
    jout(['success'=>false, 'message'=>'未知的操作']);
}
