<?php
/**
 * 表單簽核設計器 API（form_signer_lib.php 的資料存取層，比照 ReviewForm_API.php 架構）
 * 權限：form_signer_lib.php fsd_perms()（roles module='form_signer'）。
 * 讀：GET；寫：POST，一律 CSRF（fsd_need_csrf()）。
 */
$document_root = $_SERVER['DOCUMENT_ROOT'];
session_start();
include_once $document_root . '/EGsystem/src/common/_config.php';
include_once $document_root . '/EGsystem/src/common/DBConnection.php';
include_once $document_root . '/EGsystem/src/common/form_signer_lib.php';
include_once $document_root . '/EGsystem/src/common/attach_lib.php';
include_once $document_root . '/EGsystem/src/common/people_lib.php';
include_once $document_root . '/EGsystem/src/common/org_role_lib.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// template_file 直接串流檔案內容，Content-Type 依檔案類型而定，其餘動作一律 JSON
if ($action !== 'template_file') header('Content-Type: application/json; charset=utf-8');

function jout($a) { echo json_encode(array_merge(['ok'=>true], $a), JSON_UNESCAPED_UNICODE); exit; }
function jerr($msg, $code = 400) { http_response_code($code); echo json_encode(['ok'=>false, 'error'=>$msg], JSON_UNESCAPED_UNICODE); exit; }

try {
    $db = (new DBConnection())->getPDO();
} catch (Throwable $e) { jerr('DB連線失敗：' . $e->getMessage(), 500); }

$u = fsd_current_user($db);
if (!$u) jerr('未登入', 401);
$uid = (int)$u['id'];
$uname = (string)$u['user_cname'];
$perms = fsd_perms($db, $u);
if (!$perms['canView']) jerr('無表單簽核設計器檢閱權限', 403);

function fsd_attach_dir_safe(PDO $db): string {
    $dir = eg_attach_dir($db, 'fsd_nas_dir', '表單簽核設計器');
    eg_attach_ensure_dir($dir);
    return $dir;
}

switch ($action) {

case 'meta': {
    $depts = $db->query("SELECT id, name, parent_id FROM department ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
    $people = eg_people_list($db, []);
    jout([
        'perms'=>$perms, 'uid'=>$uid, 'uname'=>$uname, 'today'=>date('Y-m-d'),
        'departments'=>$depts, 'people'=>$people, 'features'=>FSD_FEATURES,
        'signer_modes'=>FSD_SIGNER_MODES, 'company_name'=>eg_company_full_name($db),
        'csrf'=>fsd_csrf_token(),
    ]);
}

/* ============================================================ 樣板（管理員） ============================================================ */

case 'template_list': {
    jout(['templates'=>fsd_template_list($db)]);
}

case 'template_get': {
    $id = (int)($_GET['id'] ?? 0);
    $t = fsd_template_get($db, $id);
    if (!$t) jerr('找不到此樣板', 404);
    $t['pages'] = fsd_template_pages_get($db, $id);
    $t['stages'] = fsd_stage_list($db, $id);
    $t['fields'] = fsd_field_list($db, $id);
    $t['as_doc_list'] = eg_asdoc_list($db);
    jout(['template'=>$t]);
}

/** 上傳原始檔(圖片/PDF)並建立樣板。$_FILES['file'] + $_POST['name']。頁面尺寸另由前端量測後呼叫 pages_save。 */
case 'template_upload': {
    fsd_need_csrf();
    if (!$perms['canAdmin']) jerr('僅管理員可上傳樣板', 403);
    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '') jerr('請輸入樣板名稱');
    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) jerr('請選擇要上傳的檔案');
    $orig = (string)$_FILES['file']['name'];
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    $isPdf = $ext === 'pdf';
    $isImg = in_array($ext, ['png','jpg','jpeg'], true);
    if (!$isPdf && !$isImg) jerr('僅接受圖片(png/jpg)或PDF檔');
    $dir = fsd_attach_dir_safe($db);
    $fname = 'fsd_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($_FILES['file']['tmp_name'], $dir . $fname)) jerr('檔案寫入失敗', 500);
    $tplId = fsd_template_create($db, $name, $isPdf ? 'pdf' : 'image', $fname, 1, $uname);
    jout(['id'=>$tplId, 'file_type'=>$isPdf ? 'pdf' : 'image']);
}

/** 串流樣板原始檔內容供前端渲染(pdf.js/<img>)，NAS路徑不可直連，一律走本API(鐵律5)。 */
case 'template_file': {
    $id = (int)($_GET['id'] ?? 0);
    $t = fsd_template_get($db, $id);
    if (!$t || !$t['file_name']) jerr('找不到此樣板檔案', 404);
    $dir = fsd_attach_dir_safe($db);
    $fp = $dir . $t['file_name'];
    if (!is_file($fp)) jerr('檔案不存在或已被搬移', 404);
    $ext = strtolower(pathinfo($t['file_name'], PATHINFO_EXTENSION));
    $mime = $ext === 'pdf' ? 'application/pdf' : ($ext === 'png' ? 'image/png' : 'image/jpeg');
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($fp));
    readfile($fp);
    exit;
}

case 'template_rename': {
    fsd_need_csrf();
    if (!$perms['canAdmin']) jerr('僅管理員可修改樣板', 403);
    $id = (int)($_POST['id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '') jerr('請輸入樣板名稱');
    fsd_template_rename($db, $id, $name, $uname);
    jout(['template'=>fsd_template_get($db, $id)]);
}

case 'template_set_status': {
    fsd_need_csrf();
    if (!$perms['canAdmin']) jerr('僅管理員可修改樣板', 403);
    $id = (int)($_POST['id'] ?? 0);
    $status = (string)($_POST['status'] ?? '');
    fsd_template_set_status($db, $id, $status, $uname);
    jout(['template'=>fsd_template_get($db, $id)]);
}

case 'pages_save': {
    fsd_need_csrf();
    if (!$perms['canAdmin']) jerr('僅管理員可設定樣板', 403);
    $id = (int)($_POST['template_id'] ?? 0);
    $pages = json_decode((string)($_POST['pages'] ?? '[]'), true);
    if (!is_array($pages) || !$pages) jerr('頁面尺寸資料格式不正確');
    fsd_template_pages_save($db, $id, $pages);
    jout(['pages'=>fsd_template_pages_get($db, $id)]);
}

case 'stages_save': {
    fsd_need_csrf();
    if (!$perms['canAdmin']) jerr('僅管理員可設定階段', 403);
    $id = (int)($_POST['template_id'] ?? 0);
    $stages = json_decode((string)($_POST['stages'] ?? '[]'), true);
    if (!is_array($stages)) jerr('階段資料格式不正確');
    fsd_stages_save($db, $id, $stages);
    jout(['stages'=>fsd_stage_list($db, $id)]);
}

case 'field_save': {
    fsd_need_csrf();
    if (!$perms['canAdmin']) jerr('僅管理員可框選區塊', 403);
    $id = (int)($_POST['template_id'] ?? 0);
    $field = json_decode((string)($_POST['field'] ?? '{}'), true);
    if (!is_array($field)) jerr('框選資料格式不正確');
    $r = fsd_field_save($db, $id, $field);
    if (!$r['ok']) jerr($r['msg']);
    jout(['id'=>$r['id'], 'fields'=>fsd_field_list($db, $id)]);
}

case 'field_delete': {
    fsd_need_csrf();
    if (!$perms['canAdmin']) jerr('僅管理員可刪除框選', 403);
    $id = (int)($_POST['template_id'] ?? 0);
    $fieldId = (int)($_POST['field_id'] ?? 0);
    fsd_field_delete($db, $id, $fieldId);
    jout(['fields'=>fsd_field_list($db, $id)]);
}

case 'schema_publish': {
    fsd_need_csrf();
    if (!$perms['canAdmin']) jerr('僅管理員可發布樣板', 403);
    $id = (int)($_POST['template_id'] ?? 0);
    try {
        $ver = fsd_template_schema_publish($db, $id, $uname);
    } catch (Throwable $e) { jerr($e->getMessage()); }
    jout(['version'=>$ver, 'template'=>fsd_template_get($db, $id)]);
}

case 'asdoc_list': jout(['docs'=>eg_asdoc_list($db)]);

case 'asdoc_save': {
    fsd_need_csrf();
    if (!$perms['canAdmin']) jerr('僅管理員可設定AS文件綁定', 403);
    $id = (int)($_POST['template_id'] ?? 0);
    $docId = (int)($_POST['as_doc_id'] ?? 0);
    eg_asdoc_save($db, fsd_asdoc_module($id), $docId, $uname);
    jout(['template'=>fsd_template_get($db, $id)]);
}

/* ============================================================ 案件 ============================================================ */

case 'case_list': {
    $tid = (int)($_GET['template_id'] ?? 0);
    $onlyMine = $perms['canViewAll'] ? null : $uid;
    jout(['cases'=>fsd_case_list($db, $tid, $onlyMine)]);
}

case 'case_get': {
    $id = (int)($_GET['id'] ?? 0);
    $case = fsd_case_get($db, $id);
    if (!$case) jerr('找不到此案件', 404);
    if (!$perms['canViewAll'] && (int)$case['applicant_id'] !== $uid) {
        // 非本人也非可檢視全部者：若是目前階段的待處理人仍可看(才能簽核)，否則擋
        $schema = fsd_case_schema($db, $case);
        $stage = null;
        foreach ($schema['stages'] ?? [] as $s) if ((int)$s['seq'] === (int)$case['current_stage_seq']) { $stage = $s; break; }
        $isPending = false;
        if ($stage) foreach ($stage['signers'] as $sg) {
            $r = fsd_resolve_signer($db, $sg, (int)$case['applicant_id']);
            if ($r && (int)$r['id'] === $uid) { $isPending = true; break; }
        }
        if (!$isPending) jerr('無權檢視他人建立的案件', 403);
    }
    $schema = fsd_case_schema($db, $case);
    $responses = fsd_case_responses($db, $id);
    $curStage = null;
    foreach ($schema['stages'] ?? [] as $s) if ((int)$s['seq'] === (int)$case['current_stage_seq']) { $curStage = $s; break; }
    $canAdvisory = false; $canDecision = false;
    if ($case['status'] === 'in_progress' && $curStage) {
        if ($curStage['stage_type'] === 'advisory') {
            $st = $db->prepare("SELECT 1 FROM fsd_case_response WHERE case_id=? AND stage_seq=? AND resolved_user_id=? AND decision IS NULL");
            $st->execute([$id, (int)$case['current_stage_seq'], $uid]);
            $canAdvisory = (bool)$st->fetchColumn();
        } else {
            $rec = eg_approval_latest($db, 'form_signer', $id, 'stage_' . $case['current_stage_seq']);
            if ($rec && $rec['status'] === 'pending') {
                foreach ($curStage['signers'] as $sg) {
                    $r = fsd_resolve_signer($db, $sg, (int)$case['applicant_id']);
                    if ($r && (int)$r['id'] === $uid) { $canDecision = true; break; }
                }
            }
        }
    }
    jout([
        'case'=>$case, 'schema'=>$schema, 'responses'=>$responses, 'current_stage'=>$curStage,
        'can_advisory_respond'=>$canAdvisory, 'can_decision_respond'=>$canDecision,
        'as_doc_no'=>fsd_asdoc_no_display($db, (int)$case['template_id'], $case['business_date']),
        'company_name'=>eg_company_full_name($db),
    ]);
}

case 'case_create': {
    fsd_need_csrf();
    if (!$perms['canCreate']) jerr('您沒有建立案件的權限', 403);
    $tid = (int)($_POST['template_id'] ?? 0);
    $title = trim((string)($_POST['title'] ?? ''));
    $bizDate = trim((string)($_POST['business_date'] ?? '')) ?: date('Y-m-d');
    $r = fsd_case_create($db, $tid, $uid, $uname, $title, $bizDate);
    if (!$r['ok']) jerr($r['msg']);
    jout(['id'=>$r['id']]);
}

case 'advisory_respond': {
    fsd_need_csrf();
    $id = (int)($_POST['case_id'] ?? 0);
    $decision = (string)($_POST['decision'] ?? '');
    $reply = trim((string)($_POST['reply_text'] ?? ''));
    $r = fsd_case_advisory_respond($db, $id, $uid, $uname, $decision, $reply);
    if (!$r['ok']) jerr($r['msg']);
    jout(['status'=>$r['status']]);
}

case 'decision_respond': {
    fsd_need_csrf();
    $id = (int)($_POST['case_id'] ?? 0);
    $decision = (string)($_POST['decision'] ?? '');
    $note = trim((string)($_POST['note'] ?? ''));
    if (!in_array($decision, ['approved','rejected'], true)) jerr('決定值不正確');
    if ($decision === 'rejected' && $note === '') jerr('駁回必須填寫原因');
    $r = fsd_case_decision_respond($db, $id, $uid, $uname, $decision, $note ?: null);
    if (!$r['ok']) jerr($r['msg']);
    jout(['status'=>$r['status']]);
}

case 'case_urge': {
    fsd_need_csrf();
    $id = (int)($_POST['case_id'] ?? 0);
    $case = fsd_case_get($db, $id);
    if (!$case) jerr('找不到此案件', 404);
    if ((int)$case['applicant_id'] !== $uid && !$perms['canAdmin']) jerr('只有申請人或管理員可催辦', 403);
    $r = fsd_case_urge($db, $id, $uid);
    if (!$r['ok']) jerr($r['msg']);
    jout([]);
}

default: jerr('未知的操作：' . $action, 400);
}
