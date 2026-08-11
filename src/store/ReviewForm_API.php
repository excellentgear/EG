<?php
/**
 * 審核表單引擎 API（review_form_lib.php 的資料存取層）
 * 權限：review_form_lib.php rvf_perms()（roles module='review_form'）。
 * 讀：GET；寫：POST，一律 CSRF（rvf_need_csrf()）。
 */
$document_root = $_SERVER['DOCUMENT_ROOT'];
session_start();
header('Content-Type: application/json; charset=utf-8');
include_once $document_root . '/EGsystem/src/common/_config.php';
include_once $document_root . '/EGsystem/src/common/DBConnection.php';
include_once $document_root . '/EGsystem/src/common/review_form_lib.php';
include_once $document_root . '/EGsystem/src/common/people_lib.php';
include_once $document_root . '/EGsystem/src/common/org_role_lib.php';

function jout($a) { echo json_encode(array_merge(['ok'=>true], $a), JSON_UNESCAPED_UNICODE); exit; }
function jerr($msg, $code = 400) { http_response_code($code); echo json_encode(['ok'=>false, 'error'=>$msg], JSON_UNESCAPED_UNICODE); exit; }

try {
    $db = (new DBConnection())->getPDO();
} catch (Throwable $e) { jerr('DB連線失敗：' . $e->getMessage(), 500); }

$u = rvf_current_user($db);
if (!$u) jerr('未登入', 401);
$uid = (int)$u['id'];
$uname = (string)$u['user_cname'];
$perms = rvf_perms($db, $u);
$action = $_GET['action'] ?? $_POST['action'] ?? '';
if (!$perms['canView']) jerr('無審核表單檢閱權限', 403);

function eg_company_full_name_safe(PDO $db): string {
    if (function_exists('eg_company_full_name')) return eg_company_full_name($db);
    try {
        $st = $db->query("SELECT customer_full FROM customer_list WHERE is_own_company=1 LIMIT 1");
        return (string)($st->fetchColumn() ?: '');
    } catch (Throwable $e) { return ''; }
}

switch ($action) {

case 'meta': {
    $depts = $db->query("SELECT id, name, parent_id FROM department ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
    $people = eg_people_list($db, []);
    jout([
        'perms'=>$perms, 'uid'=>$uid, 'uname'=>$uname, 'today'=>date('Y-m-d'),
        'departments'=>$depts, 'people'=>$people, 'features'=>RVF_FEATURES,
        'approver_methods'=>RVF_APPROVER_METHODS, 'company_name'=>eg_company_full_name_safe($db),
        'csrf'=>rvf_csrf_token(),
    ]);
}

/* ============================================================ 模板 ============================================================ */

case 'template_list': {
    $rows = rvf_template_list($db);
    foreach ($rows as &$r) {
        $r['schema'] = json_decode((string)$r['current_schema_json'], true) ?: [];
        $r['approver_chain'] = json_decode((string)$r['approver_chain_json'], true) ?: ['top_approver'];
        $r['can_edit_items'] = rvf_can_edit_items($db, $uid, $perms['canAdmin'], $r);
    }
    jout(['templates'=>$rows]);
}

case 'template_get': {
    $id = (int)($_GET['id'] ?? 0);
    $t = rvf_template_get($db, $id);
    if (!$t) jerr('找不到此模板', 404);
    $t['schema'] = json_decode((string)$t['current_schema_json'], true) ?: [];
    $t['approver_chain'] = json_decode((string)$t['approver_chain_json'], true) ?: ['top_approver'];
    $t['can_edit_items'] = rvf_can_edit_items($db, $uid, $perms['canAdmin'], $t);
    $t['maintainers'] = rvf_maintainer_list($db, $id);
    $t['as_doc_list'] = eg_asdoc_list($db);
    jout(['template'=>$t]);
}

case 'template_settings_save': {
    rvf_need_csrf();
    if (!$perms['canAdmin']) jerr('僅管理員可設定模板', 403);
    $id = (int)($_POST['id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '') jerr('請輸入模板名稱');
    $paper = ($_POST['paper_size'] ?? 'A4') === 'A3' ? 'A3' : 'A4';
    $chain = json_decode((string)($_POST['approver_chain'] ?? '["top_approver"]'), true);
    $tid = rvf_template_settings_save($db, $id, [
        'name'=>$name, 'paper_size'=>$paper,
        'need_review'=>!empty($_POST['need_review']), 'review_dept_id'=>(int)($_POST['review_dept_id'] ?? 0) ?: null,
        'need_approval'=>!empty($_POST['need_approval']),
        'approver_dept_id'=>(int)($_POST['approver_dept_id'] ?? 0) ?: null,
        'approver_user_id'=>(int)($_POST['approver_user_id'] ?? 0) ?: null,
        'approver_chain'=>is_array($chain) ? $chain : ['top_approver'],
        'maintain_dept_id'=>(int)($_POST['maintain_dept_id'] ?? 0) ?: null,
    ], $uname);
    $docId = (int)($_POST['as_doc_id'] ?? 0);
    if ($docId >= 0) eg_asdoc_save($db, rvf_asdoc_module($tid), $docId, $uname);
    jout(['template'=>rvf_template_get($db, $tid)]);
}

case 'template_schema_save': {
    rvf_need_csrf();
    $id = (int)($_POST['template_id'] ?? 0);
    $tpl = rvf_template_get($db, $id);
    if (!$tpl) jerr('找不到此模板', 404);
    if (!rvf_can_edit_items($db, $uid, $perms['canAdmin'], $tpl)) jerr('您沒有維護此模板項次內容的權限', 403);
    $schema = json_decode((string)($_POST['schema'] ?? ''), true);
    if (!is_array($schema)) jerr('欄位定義格式不正確');
    $bumpedVerId = (int)($_POST['bumped_as_doc_version_id'] ?? 0) ?: null;
    $ver = rvf_template_schema_save($db, $id, $schema, $uname, $bumpedVerId);
    jout(['version'=>$ver, 'template'=>rvf_template_get($db, $id)]);
}

case 'maintainer_add': case 'maintainer_remove': {
    rvf_need_csrf();
    $id = (int)($_POST['template_id'] ?? 0);
    $targetUid = (int)($_POST['user_id'] ?? 0);
    $tpl = rvf_template_get($db, $id);
    if (!$tpl) jerr('找不到此模板', 404);
    $isDeptMgr = !empty($tpl['maintain_dept_id']) && in_array($uid, array_column(rvf_dept_managers($db, (int)$tpl['maintain_dept_id']), 'id'), true);
    if (!$perms['canAdmin'] && !$isDeptMgr) jerr('僅管理員或維護部門主管可指派維護人員', 403);
    if ($action === 'maintainer_add') rvf_maintainer_add($db, $id, $targetUid, $uname);
    else rvf_maintainer_remove($db, $id, $targetUid);
    jout(['maintainers'=>rvf_maintainer_list($db, $id)]);
}

case 'asdoc_list': jout(['docs'=>eg_asdoc_list($db)]);

/* ============================================================ 表單（instance） ============================================================ */

case 'instance_list': {
    $tid = (int)($_GET['template_id'] ?? 0);
    $onlyMine = $perms['canViewAll'] ? null : $uid;
    $rows = rvf_instance_list($db, $tid, $onlyMine);
    foreach ($rows as &$r) {
        $t = rvf_template_get($db, (int)$r['template_id']);
        $r['template_name'] = $t['name'] ?? '';
    }
    jout(['instances'=>$rows]);
}

case 'instance_get': {
    $id = (int)($_GET['id'] ?? 0);
    $inst = rvf_instance_get($db, $id);
    if (!$inst) jerr('找不到此表單', 404);
    if (!$perms['canViewAll'] && (int)$inst['created_by'] !== $uid) jerr('無權檢視他人建立的表單', 403);
    $tpl = rvf_template_get($db, (int)$inst['template_id']);
    $schema = rvf_template_schema_at_version($db, (int)$inst['template_id'], (int)$inst['template_version']) ?: (json_decode((string)$tpl['current_schema_json'], true) ?: []);
    $items = rvf_instance_items_get($db, $id);
    foreach ($items as &$it) {
        $it['required_signers'] = rvf_item_required_signers($db, $it);
        $it['fully_signed'] = rvf_item_is_fully_signed($db, $it);
    }
    $review = eg_approval_latest($db, 'review_form', $id, 'review');
    $approval = eg_approval_latest($db, 'review_form', $id, 'approval');
    $reviewPool = !empty($tpl['need_review']) ? rvf_review_pool($db, $tpl) : [];
    $approvalPool = !empty($tpl['need_approval']) ? rvf_approver_pool($db, $tpl, (int)$inst['created_by']) : [];
    jout([
        'instance'=>$inst, 'template'=>$tpl, 'schema'=>$schema, 'items'=>$items,
        'review'=>$review, 'approval'=>$approval,
        'can_review'=>$review && $review['status']==='pending' && in_array($uid, array_column($reviewPool,'id'), true),
        'can_approve'=>$approval && $approval['status']==='pending' && in_array($uid, array_column($approvalPool,'id'), true),
        'as_doc_no'=>rvf_asdoc_no_display($db, (int)$inst['template_id'], $inst['business_date']),
        'company_name'=>eg_company_full_name_safe($db),
    ]);
}

case 'instance_create': {
    rvf_need_csrf();
    if (!$perms['canCreate']) jerr('您沒有建立表單的權限', 403);
    $tid = (int)($_POST['template_id'] ?? 0);
    $title = trim((string)($_POST['title'] ?? ''));
    $bizDate = trim((string)($_POST['business_date'] ?? '')) ?: date('Y-m-d');
    $id = rvf_instance_create($db, $tid, $uid, $uname, $title, $bizDate);
    jout(['id'=>$id]);
}

case 'instance_save_items': {
    rvf_need_csrf();
    $id = (int)($_POST['instance_id'] ?? 0);
    $inst = rvf_instance_get($db, $id);
    if (!$inst) jerr('找不到此表單', 404);
    if ((int)$inst['created_by'] !== $uid && !$perms['canAdmin']) jerr('只有填表人本人可以編輯', 403);
    if ($inst['status'] !== 'draft') jerr('此表單已送出，內容已鎖定不可編輯');
    $items = json_decode((string)($_POST['items'] ?? '[]'), true);
    if (!is_array($items)) jerr('項次資料格式不正確');
    $title = trim((string)($_POST['title'] ?? ''));
    $bizDate = trim((string)($_POST['business_date'] ?? '')) ?: $inst['business_date'];
    $db->prepare("UPDATE rf_instance SET title=?,business_date=?,updated_at=NOW() WHERE id=?")->execute([$title, $bizDate, $id]);
    rvf_instance_items_save($db, $id, $items);
    jout(['items'=>rvf_instance_items_get($db, $id)]);
}

case 'instance_submit': {
    rvf_need_csrf();
    $id = (int)($_POST['instance_id'] ?? 0);
    $r = rvf_instance_submit($db, $id, $uid, $uname);
    if (!$r['ok']) jerr($r['msg']);
    jout(['status'=>$r['status']]);
}

case 'instance_delete': {
    rvf_need_csrf();
    $id = (int)($_POST['instance_id'] ?? 0);
    $inst = rvf_instance_get($db, $id);
    if (!$inst) jerr('找不到此表單', 404);
    if ((int)$inst['created_by'] !== $uid && !$perms['canAdmin']) jerr('只有填表人本人可以刪除', 403);
    if ($inst['status'] !== 'draft' && !$perms['canAdmin']) jerr('已送出的表單不可刪除，如需作廢請洽管理員');
    $db->prepare("DELETE FROM rf_instance_item_confirm WHERE item_id IN (SELECT id FROM rf_instance_item WHERE instance_id=?)")->execute([$id]);
    $db->prepare("DELETE FROM rf_instance_item WHERE instance_id=?")->execute([$id]);
    $db->prepare("DELETE FROM rf_instance WHERE id=?")->execute([$id]);
    jout([]);
}

case 'item_confirm': {
    rvf_need_csrf();
    $itemId = (int)($_POST['item_id'] ?? 0);
    $forUid = (int)($_POST['user_id'] ?? 0);
    $password = (string)($_POST['password'] ?? '');
    $r = rvf_item_confirm($db, $itemId, $forUid, $password);
    if (!$r['ok']) jerr($r['msg']);
    jout([]);
}

case 'review_decide': {
    rvf_need_csrf();
    $id = (int)($_POST['instance_id'] ?? 0);
    $decision = (string)($_POST['decision'] ?? '');
    $note = trim((string)($_POST['note'] ?? ''));
    if (!in_array($decision, ['approved','rejected'], true)) jerr('決定值不正確');
    if ($decision === 'rejected' && $note === '') jerr('退回必須填寫原因');
    $r = rvf_review_decide($db, $id, $uid, $uname, $decision, $note ?: null);
    if (!$r['ok']) jerr($r['msg']);
    jout(['status'=>$r['status']]);
}

case 'approval_decide': {
    rvf_need_csrf();
    $id = (int)($_POST['instance_id'] ?? 0);
    $decision = (string)($_POST['decision'] ?? '');
    $note = trim((string)($_POST['note'] ?? ''));
    if (!in_array($decision, ['approved','rejected'], true)) jerr('決定值不正確');
    if ($decision === 'rejected' && $note === '') jerr('退回必須填寫原因');
    $r = rvf_approval_decide($db, $id, $uid, $uname, $decision, $note ?: null);
    if (!$r['ok']) jerr($r['msg']);
    jout(['status'=>$r['status']]);
}

default: jerr('未知的操作：' . $action, 400);
}
