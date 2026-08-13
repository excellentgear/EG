<?php
/**
 * 人資職務表單 API（hr_form_lib.php 的資料存取層）
 * 權限：hr_form_lib.php hrf_perms()（roles module='hr_form'）。
 * 讀：GET；寫：POST，一律 CSRF（hrf_need_csrf()）。
 */
$document_root = $_SERVER['DOCUMENT_ROOT'];
session_start();
header('Content-Type: application/json; charset=utf-8');
include_once $document_root . '/EGsystem/src/common/_config.php';
include_once $document_root . '/EGsystem/src/common/DBConnection.php';
include_once $document_root . '/EGsystem/src/common/hr_form_lib.php';
include_once $document_root . '/EGsystem/src/common/people_lib.php';
include_once $document_root . '/EGsystem/src/common/org_role_lib.php';

function jout($a) { echo json_encode(array_merge(['ok'=>true], $a), JSON_UNESCAPED_UNICODE); exit; }
function jerr($msg, $code = 400) { http_response_code($code); echo json_encode(['ok'=>false, 'error'=>$msg], JSON_UNESCAPED_UNICODE); exit; }

try {
    $db = (new DBConnection())->getPDO();
} catch (Throwable $e) { jerr('DB連線失敗：' . $e->getMessage(), 500); }

hrf_ensure_schema($db);

$u = hrf_current_user($db);
if (!$u) jerr('未登入', 401);
$uid = (int)$u['id'];
$uname = (string)$u['user_cname'];
$perms = hrf_perms($db, $u);
$action = $_GET['action'] ?? $_POST['action'] ?? '';
if (!$perms['canView']) jerr('無人資職務表單檢閱權限', 403);

switch ($action) {

case 'meta': {
    $depts = $db->query("SELECT id, name, parent_id FROM department ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
    $positions = $db->query("SELECT id, name FROM position ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
    $people = eg_people_list($db, []);
    jout([
        'perms'=>$perms, 'uid'=>$uid, 'uname'=>$uname, 'today'=>date('Y-m-d'),
        'departments'=>$depts, 'positions'=>$positions, 'people'=>$people, 'features'=>HRF_FEATURES,
        'form_types'=>HRF_FORM_TYPES, 'company_name'=>eg_company_full_name($db),
        'dept_type_settings'=>hrf_dept_type_setting_list($db),
        'csrf'=>hrf_csrf_token(),
    ]);
}

/* ============================================================ 表單本體 ============================================================ */

case 'list': {
    $formType = (string)($_GET['form_type'] ?? '');
    if (!isset(HRF_FORM_TYPES[$formType])) jerr('不明的表單類型');
    $opt = ['form_type'=>$formType];
    if (!empty($_GET['keyword'])) $opt['keyword'] = (string)$_GET['keyword'];
    if (!$perms['canViewAll']) {
        $mineDepts = array_column(eg_people_list($db, ['user_ids'=>[$uid]]), 'dept_ids');
        $deptIds = $mineDepts ? ($mineDepts[0] ?? []) : [];
        $rows = hrf_instance_list($db, $opt);
        $rows = array_values(array_filter($rows, function($r) use ($uid, $deptIds) {
            return (int)$r['user_id'] === $uid || (int)$r['created_by'] === $uid || in_array((int)$r['dept_id'], $deptIds, true);
        }));
    } else {
        if (!empty($_GET['dept_id'])) $opt['dept_ids'] = [(int)$_GET['dept_id']];
        $rows = hrf_instance_list($db, $opt);
    }
    jout(['instances'=>$rows]);
}

case 'get': {
    $id = (int)($_GET['id'] ?? 0);
    $r = hrf_instance_get($db, $id);
    if (!$r) jerr('找不到此表單', 404);
    jout(['instance'=>$r]);
}

case 'create': {
    hrf_need_csrf();
    if (!$perms['canCreate']) jerr('無建立權限', 403);
    $formType = (string)($_POST['form_type'] ?? '');
    if (!isset(HRF_FORM_TYPES[$formType])) jerr('不明的表單類型');
    $targetUid = (int)($_POST['user_id'] ?? 0);
    if ($targetUid <= 0) jerr('請選擇員工');
    $whitelistId = (int)($_POST['whitelist_id'] ?? 0) ?: null;
    $bizDate = (string)($_POST['business_date'] ?? date('Y-m-d'));
    $r = hrf_instance_create_one($db, $formType, $targetUid, $whitelistId, $bizDate, $uid, $uname);
    if (!$r['ok']) jerr($r['msg']);
    jout(['id'=>$r['id']]);
}

case 'batch_create': {
    hrf_need_csrf();
    if (!$perms['canCreate']) jerr('無建立權限', 403);
    $formType = (string)($_POST['form_type'] ?? '');
    if (!isset(HRF_FORM_TYPES[$formType])) jerr('不明的表單類型');
    $targetUids = json_decode((string)($_POST['user_ids'] ?? '[]'), true);
    if (!is_array($targetUids) || !$targetUids) jerr('請至少選擇一位員工');
    $whitelistIds = json_decode((string)($_POST['whitelist_ids'] ?? '[]'), true);
    if (!is_array($whitelistIds)) $whitelistIds = [];
    $bizDate = (string)($_POST['business_date'] ?? date('Y-m-d'));
    $r = hrf_instance_create_batch($db, $formType, $targetUids, $whitelistIds, $bizDate, $uid, $uname);
    jout(['created'=>count($r['created']), 'created_ids'=>$r['created'], 'errors'=>$r['errors']]);
}

case 'copy': {
    hrf_need_csrf();
    if (!$perms['canCreate']) jerr('無建立權限', 403);
    $id = (int)($_POST['id'] ?? 0);
    $r = hrf_instance_copy($db, $id, $uid, $uname);
    if (!$r['ok']) jerr($r['msg']);
    jout(['id'=>$r['id']]);
}

case 'delete': {
    hrf_need_csrf();
    $inst = hrf_instance_get($db, (int)($_POST['id'] ?? 0));
    if (!$inst) jerr('找不到此表單', 404);
    if (!$perms['canAdmin'] && (int)$inst['created_by'] !== $uid) jerr('僅建立者或管理員可刪除', 403);
    hrf_instance_delete($db, (int)$inst['id']);
    jout([]);
}

case 'save_items': {
    hrf_need_csrf();
    if (!$perms['canCreate']) jerr('無編輯權限', 403);
    $id = (int)($_POST['id'] ?? 0);
    $inst = hrf_instance_get($db, $id);
    if (!$inst) jerr('找不到此表單', 404);
    if (!$perms['canAdmin'] && (int)$inst['created_by'] !== $uid) jerr('僅建立者或管理員可編輯', 403);
    if (!in_array($inst['status'], ['draft','active'], true)) jerr('此表單已送出簽核，不可再編輯內容');
    $items = json_decode((string)($_POST['items'] ?? '[]'), true);
    if (!is_array($items)) jerr('內容格式錯誤');
    hrf_instance_save_items($db, $id, $items);
    jout([]);
}

case 'save_scores': {
    hrf_need_csrf();
    if (!$perms['canCreate']) jerr('無編輯權限', 403);
    $id = (int)($_POST['id'] ?? 0);
    $inst = hrf_instance_get($db, $id);
    if (!$inst) jerr('找不到此表單', 404);
    if ($inst['form_type'] !== 'skill_assess') jerr('此表單類型無評分欄位');
    if ($inst['status'] !== 'draft') jerr('此表單已送出簽核，請由確認人/核准人各自在簽核時填寫該欄分數');
    if (!$perms['canAdmin'] && (int)$inst['created_by'] !== $uid) jerr('僅建立者或管理員可編輯', 403);
    $scores = json_decode((string)($_POST['scores'] ?? '{}'), true);
    if (!is_array($scores)) jerr('評分格式錯誤');
    hrf_instance_save_scores($db, $id, $scores);
    jout([]);
}

case 'submit': {
    hrf_need_csrf();
    if (!$perms['canCreate']) jerr('無送出權限', 403);
    $id = (int)($_POST['id'] ?? 0);
    $r = hrf_instance_submit($db, $id, $uid, $uname);
    if (!$r['ok']) jerr($r['msg']);
    jout(['status'=>$r['status']]);
}

case 'confirm_decide': {
    hrf_need_csrf();
    $id = (int)($_POST['id'] ?? 0);
    $decision = (string)($_POST['decision'] ?? '');
    $note = trim((string)($_POST['note'] ?? ''));
    if ($decision === 'rejected' && $note === '') jerr('退回必須填寫原因');
    $scores = json_decode((string)($_POST['scores'] ?? '{}'), true) ?: [];
    $items = json_decode((string)($_POST['items'] ?? '[]'), true) ?: [];
    $r = hrf_confirm_decide($db, $id, $uid, $uname, $decision, $note ?: null, is_array($scores)?$scores:[], is_array($items)?$items:[]);
    if (!$r['ok']) jerr($r['msg']);
    jout(['status'=>$r['status']]);
}

case 'approve_decide': {
    hrf_need_csrf();
    $id = (int)($_POST['id'] ?? 0);
    $decision = (string)($_POST['decision'] ?? '');
    $note = trim((string)($_POST['note'] ?? ''));
    if ($decision === 'rejected' && $note === '') jerr('退回必須填寫原因');
    $scores = json_decode((string)($_POST['scores'] ?? '{}'), true) ?: [];
    $r = hrf_approve_decide($db, $id, $uid, $uname, $decision, $note ?: null, is_array($scores)?$scores:[]);
    if (!$r['ok']) jerr($r['msg']);
    jout(['status'=>$r['status']]);
}

case 'auto_sign_bulk': {
    hrf_need_csrf();
    if ($uid !== 1) jerr('僅超級管理員可使用自動簽核', 403);
    $pwCheck = eg_confirm_password_verify($db, $uid, (string)($_POST['password'] ?? ''));
    if (!$pwCheck['ok']) jerr($pwCheck['msg']);
    $ids = json_decode((string)($_POST['ids'] ?? '[]'), true);
    if (!is_array($ids) || !$ids) jerr('請至少選擇一筆表單');
    $signDate = (string)($_POST['sign_date'] ?? date('Y-m-d'));
    $r = hrf_auto_sign_bulk($db, $ids, $signDate, $uid, $uname);
    jout(['done'=>count($r['done']), 'errors'=>$r['errors']]);
}

/* ============================================================ AS 文件編號綁定 ============================================================ */

case 'asdoc_list': jout(['docs'=>eg_asdoc_list($db)]);

case 'asdoc_get': {
    $formType = (string)($_GET['form_type'] ?? '');
    if (!isset(HRF_FORM_TYPES[$formType])) jerr('不明的表單類型');
    jout(['doc'=>eg_asdoc_get($db, hrf_asdoc_module($formType))]);
}

case 'asdoc_save': {
    hrf_need_csrf();
    if (!$perms['canAdmin']) jerr('僅管理員可設定 AS 文件綁定', 403);
    $formType = (string)($_POST['form_type'] ?? '');
    if (!isset(HRF_FORM_TYPES[$formType])) jerr('不明的表單類型');
    eg_asdoc_save($db, hrf_asdoc_module($formType), (int)($_POST['doc_id'] ?? 0), $uname);
    jout([]);
}

/* ============================================================ 職位範本（管理員） ============================================================ */

case 'template_list': {
    if (!$perms['canAdmin']) jerr('僅管理員可管理範本', 403);
    $formType = (string)($_GET['form_type'] ?? '');
    if (!isset(HRF_FORM_TYPES[$formType])) jerr('不明的表單類型');
    jout(['templates'=>hrf_template_list($db, $formType)]);
}

case 'template_get': {
    if (!$perms['canAdmin']) jerr('僅管理員可管理範本', 403);
    $t = hrf_template_get($db, (int)($_GET['id'] ?? 0));
    if (!$t) jerr('找不到此範本', 404);
    jout(['template'=>$t]);
}

case 'template_save': {
    hrf_need_csrf();
    if (!$perms['canAdmin']) jerr('僅管理員可管理範本', 403);
    $id = (int)($_POST['id'] ?? 0);
    $formType = (string)($_POST['form_type'] ?? '');
    if (!isset(HRF_FORM_TYPES[$formType])) jerr('不明的表單類型');
    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '') jerr('請輸入範本名稱');
    $tid = hrf_template_save($db, $id, $formType, $name,
        (int)($_POST['list_stamp_tpl_id'] ?? 0) ?: null, (int)($_POST['footer_stamp_tpl_id'] ?? 0) ?: null, $uname);

    $scope = json_decode((string)($_POST['scope'] ?? '[]'), true);
    if (is_array($scope)) hrf_template_scope_save($db, $tid, $scope);

    if ($formType === 'skill_assess') {
        $wids = json_decode((string)($_POST['whitelist_ids'] ?? '[]'), true);
        if (is_array($wids)) hrf_template_machines_save($db, $tid, $wids);
    } else {
        $items = json_decode((string)($_POST['items'] ?? '[]'), true);
        if (is_array($items)) hrf_template_items_save($db, $tid, $items);
    }
    jout(['id'=>$tid]);
}

case 'template_delete': {
    hrf_need_csrf();
    if (!$perms['canAdmin']) jerr('僅管理員可管理範本', 403);
    hrf_template_delete($db, (int)($_POST['id'] ?? 0));
    jout([]);
}

case 'stamp_options': {
    if (!$perms['canAdmin']) jerr('僅管理員可管理範本', 403);
    jout(['options'=>hrf_stamp_tpl_options($db)]);
}

/* ============================================================ 機型/量具白名單（管理員） ============================================================ */

case 'whitelist_sources': {
    if (!$perms['canAdmin']) jerr('僅管理員可管理白名單', 403);
    jout(hrf_whitelist_sources($db));
}

case 'whitelist_list': {
    if (!$perms['canAdmin']) jerr('僅管理員可管理白名單', 403);
    jout(['whitelist'=>hrf_whitelist_list($db)]);
}

case 'whitelist_save': {
    hrf_need_csrf();
    if (!$perms['canAdmin']) jerr('僅管理員可管理白名單', 403);
    $entries = json_decode((string)($_POST['entries'] ?? '[]'), true);
    if (!is_array($entries)) jerr('資料格式錯誤');
    hrf_whitelist_save($db, $entries, $uname);
    jout([]);
}

/* ============================================================ 部門表單資格設定（管理員） ============================================================ */

case 'dept_type_setting_save': {
    hrf_need_csrf();
    if (!$perms['canAdmin']) jerr('僅管理員可設定', 403);
    $deptId = (int)($_POST['department_id'] ?? 0);
    if ($deptId <= 0) jerr('部門不正確');
    hrf_dept_type_setting_save($db, $deptId, !empty($_POST['produce_skill_assess']), !empty($_POST['produce_competency']));
    jout([]);
}

default:
    jerr('不明的操作：' . $action, 404);
}
