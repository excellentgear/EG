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
        'dept_position_pairs'=>hrf_dept_position_pairs($db),
        'top_approver_dept_position'=>hrf_top_approver_dept_position($db),
        'csrf'=>hrf_csrf_token(),
    ]);
}

/* ============================================================ 表單本體 ============================================================ */

case 'list': {
    $formType = (string)($_GET['form_type'] ?? '');
    if (!isset(HRF_FORM_TYPES[$formType])) jerr('不明的表單類型');
    $opt = ['form_type'=>$formType];
    if (!empty($_GET['keyword'])) $opt['keyword'] = (string)$_GET['keyword'];
$rows = hrf_instance_list($db, $opt);
    if ($perms['isAdmin'] || $perms['canAdmin']) {
        // 系統管理員／模組管理員固定全權，不受下方「職位以下」限制
    } elseif ($perms['canViewAll']) {
        // hrf_view_all：檢視自己職位以下的員工表單（同職級/未知職級一律看不到），另外一律看得到自己的和自己建立的
        $viewerLevel = hrf_viewer_level($db, $uid);
        $rows = array_values(array_filter($rows, function($r) use ($uid, $viewerLevel) {
            if ((int)$r['user_id'] === $uid || (int)$r['created_by'] === $uid) return true;
            if ($viewerLevel === null || $r['target_level'] === null) return false;
            return (int)$r['target_level'] > $viewerLevel; // 數字越大職級越低
        }));
    } else {
        $mineDepts = array_column(eg_people_list($db, ['user_ids'=>[$uid]]), 'dept_ids');
        $deptIds = $mineDepts ? ($mineDepts[0] ?? []) : [];
        $rows = array_values(array_filter($rows, function($r) use ($uid, $deptIds) {
            return (int)$r['user_id'] === $uid || (int)$r['created_by'] === $uid || in_array((int)$r['dept_id'], $deptIds, true);
        }));
    }
    jout(['instances'=>$rows]);
}

case 'get': {
    $id = (int)($_GET['id'] ?? 0);
    $r = hrf_instance_get($db, $id);
    if (!$r) jerr('找不到此表單', 404);
    // 列印頁尾右下的 AS 文件編號一律含版次，且版次要依「這張表單自己的業務日期」回推當時生效的版本
    // （ai-rules/16 第三之四節）。前端不可只印 doc_no——那會漏掉版次，改版後仍印舊編號。
    jout(['instance'=>$r, 'as_doc_no'=>hrf_asdoc_no_display((string)$r['form_type'], $db, (string)($r['business_date'] ?? ''))]);
}

/* 建立表單挑選人員用的「該業務日期當時」員工清單（部門/職稱依 user_position_history 回推，含當時在職、
   現在已離職者；日期＝今天則等同 meta 的 people）。2026-08-18 使用者明確要求。 */
case 'people_asof': {
    $date = trim((string)($_GET['date'] ?? ''));
    jout(['people'=>hrf_people_asof($db, $date), 'date'=>$date, 'dept_names'=>hrf_dept_name_map($db)]);
}

case 'create': {
    hrf_need_csrf();
    if (!$perms['canCreate']) jerr('無建立權限', 403);
    $formType = (string)($_POST['form_type'] ?? '');
    if (!isset(HRF_FORM_TYPES[$formType]) || $formType === 'job_desc') jerr('職務說明書請用 batch_create（部門x職位），此動作僅供09/10使用');
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
    $bizDate = (string)($_POST['business_date'] ?? date('Y-m-d'));
    if ($formType === 'job_desc') {
        // 01 以部門x職位為主：user_ids 改傳 dept_position_pairs = [{dept_id,position_id},...]
        $pairs = json_decode((string)($_POST['dept_position_pairs'] ?? '[]'), true);
        if (!is_array($pairs) || !$pairs) jerr('請至少選擇一組部門×職位');
        $r = hrf_instance_create_batch_job_desc($db, $pairs, $bizDate, $uid, $uname);
        jout(['created'=>count($r['created']), 'created_ids'=>$r['created'], 'errors'=>$r['errors'], 'skipped'=>$r['skipped']]);
    }
    $targetUids = json_decode((string)($_POST['user_ids'] ?? '[]'), true);
    if (!is_array($targetUids) || !$targetUids) jerr('請至少選擇一位員工');
    $whitelistIds = json_decode((string)($_POST['whitelist_ids'] ?? '[]'), true);
    if (!is_array($whitelistIds)) $whitelistIds = [];
    $r = hrf_instance_create_batch($db, $formType, $targetUids, $whitelistIds, $bizDate, $uid, $uname);
    jout(['created'=>count($r['created']), 'created_ids'=>$r['created'], 'errors'=>$r['errors'], 'skipped'=>$r['skipped']]);
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
    // 10員工職能鑑定表使用者明確要求送簽後仍可修改（改動會自動退回草稿並要求重新送簽，見 hrf_instance_save_items()）；
    // 01職務說明書本就恆為 active 不受影響；09技能鑑定表沒有這個動作可呼叫，不受影響。
    if ($inst['form_type'] !== 'competency' && !in_array($inst['status'], ['draft','active'], true)) jerr('此表單已送出簽核，不可再編輯內容');
    $items = json_decode((string)($_POST['items'] ?? '[]'), true);
    if (!is_array($items)) jerr('內容格式錯誤');
    hrf_instance_save_items($db, $id, $items);
    jout([]);
}

case 'cp_set_update_date': {
    hrf_need_csrf();
    if (!$perms['isSuperAdmin']) jerr('僅超級管理員可設定此欄位', 403);
    $id = (int)($_POST['id'] ?? 0);
    $date = (string)($_POST['date'] ?? '');
    if (!$date) jerr('請指定日期');
    $r = hrf_cp_set_update_date($db, $id, $date);
    if (!$r['ok']) jerr($r['msg']);
    jout([]);
}

case 'jd_confirm': {
    hrf_need_csrf();
    if (!$perms['canCreate']) jerr('無確認權限', 403);
    $id = (int)($_POST['id'] ?? 0);
    $inst = hrf_instance_get($db, $id);
    if (!$inst) jerr('找不到此表單', 404);
    if ($inst['form_type'] !== 'job_desc') jerr('僅職務說明書可標記確認完成');
    if (!$perms['canAdmin'] && (int)$inst['created_by'] !== $uid) jerr('僅建立者或管理員可確認', 403);
    $r = hrf_job_desc_confirm($db, $id, $uid, $uname);
    if (!$r['ok']) jerr($r['msg']);
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
    $scoresByInstance = json_decode((string)($_POST['scores_by_instance'] ?? '{}'), true);
    if (!is_array($scoresByInstance)) $scoresByInstance = [];
    $scoresByInstance = array_combine(array_map('intval', array_keys($scoresByInstance)), array_values($scoresByInstance));
    $itemsByInstance = json_decode((string)($_POST['items_by_instance'] ?? '{}'), true);
    if (!is_array($itemsByInstance)) $itemsByInstance = [];
    $itemsByInstance = array_combine(array_map('intval', array_keys($itemsByInstance)), array_values($itemsByInstance));
    $r = hrf_auto_sign_bulk($db, $ids, $signDate, $uid, $uname, $scoresByInstance, $itemsByInstance);
    jout(['done'=>count($r['done']), 'errors'=>$r['errors']]);
}

/* ============================================================ AS 文件編號綁定 ============================================================ */

case 'asdoc_list': jout(['docs'=>eg_asdoc_list($db)]);

case 'asdoc_get': {
    $formType = (string)($_GET['form_type'] ?? '');
    if (!isset(HRF_FORM_TYPES[$formType])) jerr('不明的表單類型');
    $doc = eg_asdoc_get($db, hrf_asdoc_module($formType));
    // print_no＝含版次的完整編號（四階才附版次，見 eg_asdoc_no()），畫面上顯示綁定資訊時用它，
    // 免得畫面顯示「2-MM-01-09」而列印是「2-MM-01-09A」看起來像兩份文件。
    jout(['doc'=>$doc, 'print_no'=>eg_asdoc_no($doc)]);
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
    // 這個動作同時給「管理員編輯範本」與「一般使用者列印表單要拿圖章模板」兩種情境用（見 hr_position_forms.php
    // fetchTplForPrint()），原本卡 canAdmin 會讓非管理員印表單時完全拿不到範本設定(含圖章模板)，永遠退回系統
    // 預設章，2026-08-13 使用者回報印出來圖章太小才發現。寫入(template_save)仍然只有管理員可以，這裡只放寬讀取。
    if (!$perms['canPrint'] && !$perms['canAdmin']) jerr('無權檢視此範本', 403);
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
        (int)($_POST['list_stamp_tpl_id'] ?? 0) ?: null, (int)($_POST['footer_stamp_tpl_id'] ?? 0) ?: null, $uname,
        (int)($_POST['cp_auto_fill_dynamic'] ?? 0) === 1, (int)($_POST['cp_auto_fill_skill_tpl_id'] ?? 0) ?: null);

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

/* ============================================================ 員工編號前綴（管理員） ============================================================ */

case 'user_no_prefix_get': jout(['prefix'=>hrf_user_no_prefix_get($db)]);

case 'user_no_prefix_save': {
    hrf_need_csrf();
    if (!$perms['canAdmin']) jerr('僅管理員可設定', 403);
    hrf_user_no_prefix_save($db, trim((string)($_POST['prefix'] ?? '')), $uname);
    jout([]);
}

/* ============================================================ 課長對應職位（確認人解析用，管理員） ============================================================ */

case 'confirmer_position_get': jout(['position_id'=>hrf_confirmer_position_get($db)]);

case 'confirmer_position_save': {
    hrf_need_csrf();
    if (!$perms['canAdmin']) jerr('僅管理員可設定', 403);
    hrf_confirmer_position_save($db, (int)($_POST['position_id'] ?? 0) ?: null, $uname);
    jout([]);
}

/* ============================================================ KPI 項目清單（供職務說明書 DPI 項目多選模糊搜尋，不走 KPI 模組本身的權限） ============================================================ */

case 'kpi_indicator_list': {
    try {
        $rows = $db->query("SELECT indicator_id, item_no, name, stat_desc FROM kpi_as_indicator WHERE is_active=1 ORDER BY sort_order, item_no")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { $rows = []; }
    jout(['indicators'=>$rows]);
}

default:
    jerr('不明的操作：' . $action, 404);
}
