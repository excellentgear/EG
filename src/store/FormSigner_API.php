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
include_once $document_root . '/EGsystem/src/common/form_signer_pdf_lib.php';
include_once $document_root . '/EGsystem/src/common/attach_lib.php';
include_once $document_root . '/EGsystem/src/common/people_lib.php';
include_once $document_root . '/EGsystem/src/common/org_role_lib.php';
include_once $document_root . '/EGsystem/src/common/confirm_password_lib.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// template_file/case_file/case_page_file 直接串流檔案內容，Content-Type 依檔案類型而定，其餘動作一律 JSON
$fsdStreamActions = ['template_file', 'case_file', 'case_page_file', 'case_export_file'];
if (!in_array($action, $fsdStreamActions, true)) header('Content-Type: application/json; charset=utf-8');

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
function fsd_case_attach_dir_safe(PDO $db): string {
    $dir = eg_attach_dir($db, 'fsd_case_nas_dir', '表單簽核設計器-案件');
    eg_attach_ensure_dir($dir);
    return $dir;
}
/** case_file/case_page_file共用權限檢查：本人/可檢視全部者放行，否則須是目前階段待處理人才能看，不是就jerr中止。 */
function fsd_case_view_file_perm_check(PDO $db, array $case, int $uid, array $perms): void {
    if ($perms['canViewAll'] || (int)$case['applicant_id'] === $uid) return;
    $schema = fsd_case_schema($db, $case);
    $stage = null;
    foreach ($schema['stages'] ?? [] as $s) if ((int)$s['seq'] === (int)$case['current_stage_seq']) { $stage = $s; break; }
    if ($stage) foreach ($stage['signers'] as $sg) {
        $r = fsd_resolve_signer($db, $sg, $case);
        if ($r && (int)$r['id'] === $uid) return;
    }
    jerr('無權檢視此檔案', 403);
}
/** 上傳檔案共用檢查：回傳 ['ext'=>,'is_pdf'=>] 或直接 jerr 中止。 */
function fsd_check_upload_ext(): array {
    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) jerr('請選擇要上傳的檔案');
    $ext = strtolower(pathinfo((string)$_FILES['file']['name'], PATHINFO_EXTENSION));
    $isPdf = $ext === 'pdf';
    $isImg = in_array($ext, ['png','jpg','jpeg'], true);
    if (!$isPdf && !$isImg) jerr('僅接受圖片(png/jpg)或PDF檔');
    return ['ext'=>$ext, 'is_pdf'=>$isPdf];
}
/**
 * 案件文件上傳共用檢查。兩種來源二擇一，回傳 form_signer_lib.php 的 $doc 結構：
 *   ①多張圖片(png/jpg)，依上傳順序各成一頁 → ['type'=>'image','images'=>[...]]
 *   ②單一 PDF（可多頁）                    → ['type'=>'pdf','file_name'=>..,'pages'=>[...]]
 *
 * PDF 於 2026-08-19 重新開放（2026-08-14 曾因畫質限定只能傳圖片，但那個糊來自前端把 PDF 重畫成
 * 點陣圖，不是 PDF 本身；改用 FPDI 直接匯入原頁後畫質完全不損，見 form_signer_pdf_lib.php 檔頭）。
 * PDF 一律在**上傳當下**就用 FPDI 試解析：讀不了（加密/損毀）直接擋下並說明原因，不接受、也不
 * 偷偷降級成轉圖（使用者明確要求），順便把每頁尺寸量好，不必再靠前端量測。
 * 任何一個檔案不合格即整批中止(jerr直接結束)。
 */
function fsd_case_upload_doc(PDO $db, string $field): array {
    if (empty($_FILES[$field]) || empty($_FILES[$field]['name'][0])) jerr('請上傳要簽核的文件（圖片 png/jpg 或 PDF）');
    $dir = fsd_case_attach_dir_safe($db);
    $n = count($_FILES[$field]['name']);
    $exts = [];
    for ($i = 0; $i < $n; $i++) $exts[] = strtolower(pathinfo((string)$_FILES[$field]['name'][$i], PATHINFO_EXTENSION));
    $pdfCount = count(array_filter($exts, fn($e) => $e === 'pdf'));
    if ($pdfCount > 0 && $pdfCount !== $n) jerr('PDF 不能和圖片混在一起上傳，請擇一：整份 PDF，或多張圖片');
    if ($pdfCount > 1) jerr('一次只能上傳一份 PDF（PDF 本身可以是多頁）');

    if ($pdfCount === 1) {
        if ($_FILES[$field]['error'][0] !== UPLOAD_ERR_OK) jerr('PDF 上傳失敗');
        $fname = 'fsdc_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.pdf';
        $dest = $dir . $fname;
        if (!move_uploaded_file($_FILES[$field]['tmp_name'][0], $dest)) jerr('檔案寫入失敗：' . $_FILES[$field]['name'][0], 500);
        $probe = fsd_pdf_probe($dest);
        if (!$probe['ok']) { @unlink($dest); jerr($probe['msg']); } // 擋下時順手刪掉已落地的檔，不留垃圾
        return ['type'=>'pdf', 'file_name'=>$fname, 'pages'=>$probe['pages']];
    }

    $images = [];
    for ($i = 0; $i < $n; $i++) {
        if ($_FILES[$field]['error'][$i] !== UPLOAD_ERR_OK) jerr('第' . ($i + 1) . '個檔案上傳失敗');
        $orig = (string)$_FILES[$field]['name'][$i];
        if (!in_array($exts[$i], ['png', 'jpg', 'jpeg'], true)) jerr('只接受圖片(png/jpg)或 PDF：' . $orig);
        $dim = @getimagesize($_FILES[$field]['tmp_name'][$i]);
        if (!$dim) jerr('無法讀取圖片尺寸，檔案可能已損毀：' . $orig);
        $fname = 'fsdc_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '_' . $i . '.' . $exts[$i];
        if (!move_uploaded_file($_FILES[$field]['tmp_name'][$i], $dir . $fname)) jerr('檔案寫入失敗：' . $orig, 500);
        $images[] = ['file_name' => $fname, 'width_pt' => $dim[0] / 96 * 72, 'height_pt' => $dim[1] / 96 * 72];
    }
    return ['type'=>'image', 'images'=>$images];
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

/** 刪除樣板：僅限「從未被任何案件使用過」的樣板，已使用過的一律只能停用(2026-08-14使用者明確要求)。 */
case 'template_delete': {
    fsd_need_csrf();
    if (!$perms['canAdmin']) jerr('僅管理員可刪除樣板', 403);
    $id = (int)($_POST['id'] ?? 0);
    $r = fsd_template_delete_unused($db, $id);
    if (!$r['ok']) jerr($r['msg']);
    if (!empty($r['file_name'])) {
        $fp = fsd_attach_dir_safe($db) . $r['file_name'];
        if (is_file($fp)) @unlink($fp);
    }
    jout([]);
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

/** 旋轉頁面時清空該頁既有框選(座標系已改變,舊位置不再適用)。 */
case 'field_delete_page': {
    fsd_need_csrf();
    if (!$perms['canAdmin']) jerr('僅管理員可刪除框選', 403);
    $id = (int)($_POST['template_id'] ?? 0);
    $pageNo = (int)($_POST['page_no'] ?? 0);
    fsd_field_delete_by_page($db, $id, $pageNo);
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

case 'stamp_tpl_options': jout(['templates'=>fsd_stamp_tpl_options($db)]);

case 'stamp_tpl_save': {
    fsd_need_csrf();
    if (!$perms['canAdmin']) jerr('僅管理員可設定圖章模板', 403);
    $id = (int)($_POST['template_id'] ?? 0);
    $stampTplId = (int)($_POST['stamp_tpl_id'] ?? 0);
    fsd_template_set_stamp_tpl($db, $id, $stampTplId, $uname);
    jout(['template'=>fsd_template_get($db, $id)]);
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
    $cases = fsd_case_list($db, $tid, $onlyMine);
    foreach ($cases as &$c) {
        if (in_array($c['status'], ['draft', 'void'], true)) continue; // 尚未送出/已刪除不需要進度摘要
        $schema = fsd_case_schema($db, $c);
        $c['progress'] = fsd_case_progress_chips($db, $c, $schema, fsd_case_responses($db, (int)$c['id']));
    }
    unset($c);
    jout(['cases'=>$cases]);
}

case 'case_get': {
    $id = (int)($_GET['id'] ?? 0);
    $case = fsd_case_get($db, $id);
    if (!$case) jerr('找不到此案件', 404);
    $isOwner = (int)$case['applicant_id'] === $uid;
    if (!$perms['canViewAll'] && !$isOwner) {
        // 非本人也非可檢視全部者：若是目前階段的待處理人仍可看(才能簽核)，否則擋；草稿只有本人/管理員能看
        $schema = fsd_case_schema($db, $case);
        $stage = null;
        foreach ($schema['stages'] ?? [] as $s) if ((int)$s['seq'] === (int)$case['current_stage_seq']) { $stage = $s; break; }
        $isPending = false;
        if ($stage) foreach ($stage['signers'] as $sg) {
            $r = fsd_resolve_signer($db, $sg, $case);
            if ($r && (int)$r['id'] === $uid) { $isPending = true; break; }
        }
        if (!$isPending) jerr('無權檢視他人建立的案件', 403);
    }
    $schema = fsd_case_schema($db, $case);
    $responses = fsd_sanitize_responses_for_viewer(fsd_case_responses($db, $id), $perms['canAdmin']);
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
                // 決策階段(決定型/線性)：只有「目前輪到的那一位」能決策，不是整關任何一位槽位成員都能決
                $pendingSg = fsd_case_decision_next_pending_signer($db, $id, (int)$case['current_stage_seq'], $curStage);
                if ($pendingSg) {
                    $r = fsd_resolve_signer($db, $pendingSg, $case);
                    if ($r && (int)$r['id'] === $uid) $canDecision = true;
                }
            }
        }
    }
    $canDeleteHard = $uid === 1;
    $canDeleteSoft = !$canDeleteHard && $perms['canAdmin'] && eg_confirm_password_allowed($db, $uid);
    jout([
        'case'=>$case, 'schema'=>$schema, 'responses'=>$responses, 'current_stage'=>$curStage,
        'can_advisory_respond'=>$canAdvisory, 'can_decision_respond'=>$canDecision,
        'can_edit_fields'=>$case['status']==='draft' && ($isOwner || $perms['canAdmin']),
        'can_delete_draft'=>$case['status']==='draft' && ($isOwner || $perms['canAdmin']),
        'can_delete_hard'=>$canDeleteHard, 'can_delete_soft'=>$canDeleteSoft, 'can_set_filler'=>$uid === 1,
        'pages'=>fsd_case_pages_get($db, $id),
        // 補案件的圖章框要附上人員姓名與該章自己綁的圖章模板 schema（一般案件是全部共用樣板那一個模板）
        'fields'=>fsd_is_backfill($case) ? fsd_backfill_fields_for_view($db, $id) : fsd_case_field_list($db, $id),
        'field_whitelist'=>array_keys(fsd_case_field_whitelist($db, $case)),
        'as_doc_no'=>fsd_case_asdoc_no($db, $case),
        'company_name'=>eg_company_full_name($db),
    ]);
}

/**
 * 建立案件草稿：需一併上傳要簽核的文件(不是沿用樣板檔案，樣板只提供欄位提示/白名單)。
 * 案件一律只能上傳圖片、可一次多張(2026-08-14使用者明確要求：PDF轉圖列印畫質實測不如圖片清楚，
 * 故案件不再接受PDF；多張圖片依前端拖拽排序後的順序依序成頁，樣板仍可上傳PDF不受影響)。
 */
case 'case_create_draft': {
    fsd_need_csrf();
    if (!$perms['canCreate']) jerr('您沒有建立案件的權限', 403);
    $tid = (int)($_POST['template_id'] ?? 0);
    $title = trim((string)($_POST['title'] ?? ''));
    $bizDate = trim((string)($_POST['business_date'] ?? '')) ?: date('Y-m-d');
    $doc = fsd_case_upload_doc($db, 'files');
    $r = fsd_case_create_draft_doc($db, $tid, $uid, $uname, $title, $bizDate, $doc);
    if (!$r['ok']) jerr($r['msg']);
    jout(['id'=>$r['id']]);
}

/** 草稿階段更換文件(換一份重新框選)：多張圖片或一份多頁PDF二擇一，已產生的匯出PDF一併作廢。 */
case 'case_replace_file': {
    fsd_need_csrf();
    $id = (int)($_POST['case_id'] ?? 0);
    $case = fsd_case_get($db, $id);
    if (!$case) jerr('找不到此案件', 404);
    if ((int)$case['applicant_id'] !== $uid && !$perms['canAdmin']) jerr('只有申請人本人或管理員可以編輯', 403);
    $doc = fsd_case_upload_doc($db, 'files');
    $r = fsd_case_replace_file_doc($db, $id, $doc);
    if (!$r['ok']) jerr($r['msg']);
    jout([]);
}

/** 串流案件自己上傳的文件內容供前端渲染(pdf.js/<img>)，NAS路徑不可直連，一律走本API(鐵律5)。 */
case 'case_file': {
    $id = (int)($_GET['id'] ?? 0);
    $case = fsd_case_get($db, $id);
    if (!$case || !$case['file_name']) jerr('找不到此案件的檔案', 404);
    fsd_case_view_file_perm_check($db, $case, $uid, $perms);
    $dir = fsd_case_attach_dir_safe($db);
    $fp = $dir . $case['file_name'];
    if (!is_file($fp)) jerr('檔案不存在或已被搬移', 404);
    $ext = strtolower(pathinfo($case['file_name'], PATHINFO_EXTENSION));
    $mime = $ext === 'pdf' ? 'application/pdf' : ($ext === 'png' ? 'image/png' : 'image/jpeg');
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($fp));
    readfile($fp);
    exit;
}

/**
 * 串流案件某一頁自己的圖片(新版多圖案件，每頁各自一張)；找不到該頁專屬檔名時退回case.file_name
 * (向下相容2026-08-14前建立的舊版單檔/PDF案件——但PDF案件的多頁本來就該走case_file交給pdf.js處理，
 * 這裡只服務image型別)。權限比照case_file。
 */
case 'case_page_file': {
    $id = (int)($_GET['id'] ?? 0);
    $pageNo = (int)($_GET['page_no'] ?? 0);
    $case = fsd_case_get($db, $id);
    if (!$case) jerr('找不到此案件', 404);
    fsd_case_view_file_perm_check($db, $case, $uid, $perms);
    $pages = fsd_case_pages_get($db, $id);
    $page = null;
    foreach ($pages as $p) if ((int)$p['page_no'] === $pageNo) { $page = $p; break; }
    if (!$page) jerr('找不到此頁', 404);
    $fname = fsd_case_page_file_name($case, $page);
    if (!$fname) jerr('找不到此頁的檔案', 404);
    $dir = fsd_case_attach_dir_safe($db);
    $fp = $dir . $fname;
    if (!is_file($fp)) jerr('檔案不存在或已被搬移', 404);
    $ext = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
    $mime = $ext === 'pdf' ? 'application/pdf' : ($ext === 'png' ? 'image/png' : 'image/jpeg');
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($fp));
    readfile($fp);
    exit;
}

case 'case_pages_save': {
    fsd_need_csrf();
    $id = (int)($_POST['case_id'] ?? 0);
    $case = fsd_case_get($db, $id);
    if (!$case) jerr('找不到此案件', 404);
    if ((int)$case['applicant_id'] !== $uid && !$perms['canAdmin']) jerr('只有申請人本人或管理員可以編輯', 403);
    $pages = json_decode((string)($_POST['pages'] ?? '[]'), true);
    if (!is_array($pages) || !$pages) jerr('頁面尺寸資料格式不正確');
    fsd_case_pages_save($db, $id, $pages);
    jout(['pages'=>fsd_case_pages_get($db, $id)]);
}

case 'case_field_save': {
    fsd_need_csrf();
    $id = (int)($_POST['case_id'] ?? 0);
    $case = fsd_case_get($db, $id);
    if (!$case) jerr('找不到此案件', 404);
    if ((int)$case['applicant_id'] !== $uid && !$perms['canAdmin']) jerr('只有申請人本人或管理員可以編輯', 403);
    $field = json_decode((string)($_POST['field'] ?? '{}'), true);
    if (!is_array($field)) jerr('框選資料格式不正確');
    $r = fsd_case_field_save($db, $id, $field);
    if (!$r['ok']) jerr($r['msg']);
    jout(['id'=>$r['id'], 'fields'=>$r['fields']]);
}

case 'case_field_delete': {
    fsd_need_csrf();
    $id = (int)($_POST['case_id'] ?? 0);
    $case = fsd_case_get($db, $id);
    if (!$case) jerr('找不到此案件', 404);
    if ((int)$case['applicant_id'] !== $uid && !$perms['canAdmin']) jerr('只有申請人本人或管理員可以編輯', 403);
    $fieldId = (int)($_POST['field_id'] ?? 0);
    $r = fsd_case_field_delete($db, $id, $fieldId);
    jout(['fields'=>$r['fields']]);
}

/** 旋轉頁面時清空該頁既有框選(座標系已改變,舊位置不再適用)。 */
case 'case_field_delete_page': {
    fsd_need_csrf();
    $id = (int)($_POST['case_id'] ?? 0);
    $case = fsd_case_get($db, $id);
    if (!$case) jerr('找不到此案件', 404);
    if ((int)$case['applicant_id'] !== $uid && !$perms['canAdmin']) jerr('只有申請人本人或管理員可以編輯', 403);
    $pageNo = (int)($_POST['page_no'] ?? 0);
    $r = fsd_case_field_delete_by_page($db, $id, $pageNo);
    if (!$r['ok']) jerr($r['msg']);
    jout(['fields'=>$r['fields']]);
}

/* ============================================================ 補案件（管理員把已簽好章的紙本補進系統） ============================================================ */

/** 補案件用的下拉資料：簽章人員（含已離職者）、圖章模板清單、AS文件清單。 */
case 'backfill_meta': {
    if (!$perms['canAdmin']) jerr('僅管理員可使用補案件功能', 403);
    // 模板要連 schema 一起給前端：新增圖章框的預設大小/最小尺寸要照該模板實際公分數換算(跟後端同一套口徑)
    $tpls = fsd_stamp_tpl_options($db);
    foreach ($tpls as &$t) { $t['schema'] = fsd_stamp_tpl_get($db, (int)$t['id'])['schema'] ?? null; }
    unset($t);
    jout(['people'=>fsd_backfill_people($db), 'stamp_tpls'=>$tpls,
          'as_docs'=>eg_asdoc_list($db), 'max_stamps'=>FSD_BACKFILL_MAX_STAMPS]);
}

case 'backfill_create_draft': {
    fsd_need_csrf();
    if (!$perms['canAdmin']) jerr('僅管理員可使用補案件功能', 403);
    $title   = trim((string)($_POST['title'] ?? ''));
    $bizDate = trim((string)($_POST['business_date'] ?? '')) ?: date('Y-m-d');
    $asDocId = (int)($_POST['as_doc_id'] ?? 0);
    $doc     = fsd_case_upload_doc($db, 'files');
    $r = fsd_backfill_create_draft($db, $uid, $uname, $title, $bizDate, $asDocId, $doc);
    if (!$r['ok']) jerr($r['msg']);
    jout(['id'=>$r['id']]);
}

case 'backfill_update_head': {
    fsd_need_csrf();
    if (!$perms['canAdmin']) jerr('僅管理員可使用補案件功能', 403);
    $r = fsd_backfill_update_head($db, (int)($_POST['case_id'] ?? 0), trim((string)($_POST['title'] ?? '')),
        trim((string)($_POST['business_date'] ?? '')), (int)($_POST['as_doc_id'] ?? 0));
    if (!$r['ok']) jerr($r['msg']);
    jout(['case'=>$r['case']]);
}

case 'backfill_field_save': {
    fsd_need_csrf();
    if (!$perms['canAdmin']) jerr('僅管理員可使用補案件功能', 403);
    $field = json_decode((string)($_POST['field'] ?? '{}'), true);
    if (!is_array($field)) jerr('圖章資料格式不正確');
    $r = fsd_backfill_field_save($db, (int)($_POST['case_id'] ?? 0), $field);
    if (!$r['ok']) jerr($r['msg']);
    jout(['id'=>$r['id'], 'fields'=>$r['fields']]);
}

case 'backfill_apply_tpl_all': {
    fsd_need_csrf();
    if (!$perms['canAdmin']) jerr('僅管理員可使用補案件功能', 403);
    $r = fsd_backfill_apply_stamp_tpl_all($db, (int)($_POST['case_id'] ?? 0), (int)($_POST['stamp_tpl_id'] ?? 0));
    if (!$r['ok']) jerr($r['msg']);
    jout(['fields'=>$r['fields']]);
}

case 'case_submit': {
    fsd_need_csrf();
    $id = (int)($_POST['case_id'] ?? 0);
    $r = fsd_case_submit($db, $id, $uid);
    if (!$r['ok']) jerr($r['msg']);
    jout(['status'=>$r['status']]);
}

/** 刪除：草稿一律直接刪；已送出的案件依權限走硬刪(id=1不留紀錄)或軟刪(管理員+操作密碼,留紀錄可復原)。 */
case 'case_delete': {
    fsd_need_csrf();
    $id = (int)($_POST['case_id'] ?? 0);
    $case = fsd_case_get($db, $id);
    if (!$case) jerr('找不到此案件', 404);
    if ($case['status'] === 'draft') {
        $r = fsd_case_delete_draft($db, $id, $uid, $perms['canAdmin']);
        if (!$r['ok']) jerr($r['msg']);
        jout([]);
    }
    if ($uid === 1) {
        $r = fsd_case_delete_hard($db, $id);
        if (!$r['ok']) jerr($r['msg']);
        jout([]);
    }
    if (!$perms['canAdmin']) jerr('您沒有刪除案件的權限', 403);
    $pw = (string)($_POST['password'] ?? '');
    $chk = eg_confirm_password_verify_scoped($db, $uid, $pw, 'fsd_case_delete');
    if (!$chk['ok']) jerr($chk['msg'], 403);
    $r = fsd_case_delete_soft($db, $id, $uid, $uname);
    if (!$r['ok']) jerr($r['msg']);
    jout([]);
}

case 'case_restore': {
    fsd_need_csrf();
    if (!$perms['canAdmin']) jerr('僅管理員可復原', 403);
    $id = (int)($_POST['case_id'] ?? 0);
    $r = fsd_case_restore($db, $id, $uid, $uname);
    if (!$r['ok']) jerr($r['msg']);
    jout(['status'=>$r['status']]);
}

case 'case_deleted_list': {
    if (!$perms['canAdmin']) jerr('僅管理員可檢視已刪除案件', 403);
    jout(['cases'=>fsd_case_deleted_list($db)]);
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

/** 事後回改填表人：僅超級管理員(id=1)，比照ai-rules/21業務日期回改精神，一般人不可竄改簽核解析基準。 */
case 'case_set_filler': {
    fsd_need_csrf();
    $id = (int)($_POST['case_id'] ?? 0);
    $fillerId = (int)($_POST['filler_id'] ?? 0);
    if (!$fillerId) jerr('請選擇填表人');
    $r = fsd_case_set_filler($db, $id, $uid, $fillerId);
    if (!$r['ok']) jerr($r['msg']);
    jout($r);
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

/* ============================================================ 合成 PDF（完成後定版存檔，可重複開啟列印/下載） ============================================================ */

/**
 * 產生合成 PDF 並存進 NAS。案件「完成時」由前端自動呼叫一次（前端負責把每個圖章依畫面實際樣式
 * 渲染成 6 倍解析度去背 PNG 傳上來——圖章長相是 eg_stamp.js/eg_stamp_tpl.js 在瀏覽器算出來的，
 * 後端不重寫一套，避免兩邊畫出來的章不一樣）。若當下沒產生成功（關掉視窗等），之後任何人開啟
 * PDF 時會再補產一次，所以這支是可重複執行的。
 */
case 'case_export_pdf': {
    fsd_need_csrf();
    $id = (int)($_POST['case_id'] ?? 0);
    $case = fsd_case_get($db, $id);
    if (!$case) jerr('找不到此案件', 404);
    fsd_case_view_file_perm_check($db, $case, $uid, $perms);
    if (!fsd_case_export_allowed($case)) jerr('只有已完成的案件才能匯出 PDF');

    $stamps = json_decode((string)($_POST['stamps'] ?? '[]'), true);
    $texts  = json_decode((string)($_POST['texts']  ?? '[]'), true);
    if (!is_array($stamps)) $stamps = [];
    if (!is_array($texts))  $texts  = [];
    if (count($stamps) > FSD_BACKFILL_MAX_STAMPS * 2) jerr('圖章數量異常過多，請重新整理後再試');

    $pages = fsd_case_pages_get($db, $id);
    if (!$pages) jerr('此案件沒有頁面資料，無法匯出');

    $dir  = fsd_case_attach_dir_safe($db);
    $name = 'fsdpdf_' . $id . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.pdf';
    $r = fsd_pdf_compose($case, $pages, rtrim($dir, '\/'), ['stamps'=>$stamps, 'texts'=>$texts],
                         fsd_case_asdoc_no($db, $case), rtrim($dir, '\/') . DIRECTORY_SEPARATOR . $name);
    if (!$r['ok']) jerr($r['msg'], 500);

    // 舊的那份直接刪掉，不留一堆同一案件的歷史檔（案件完成後內容不會再變，留著只會佔空間又容易拿錯）
    $old = trim((string)($case['export_pdf_name'] ?? ''));
    if ($old !== '' && $old !== $name && is_file($dir . $old)) @unlink($dir . $old);
    fsd_case_export_set($db, $id, $name, $r['mode']);
    jout(['file_name'=>$name, 'mode'=>$r['mode']]);
}

/** 串流合成好的 PDF。dl=1 才是下載（Content-Disposition: attachment），預設 inline 讓瀏覽器直接開起來按列印。 */
case 'case_export_file': {
    $id = (int)($_GET['id'] ?? 0);
    $case = fsd_case_get($db, $id);
    if (!$case) jerr('找不到此案件', 404);
    fsd_case_view_file_perm_check($db, $case, $uid, $perms);
    if (!fsd_case_has_export($case)) jerr('此案件尚未產生 PDF', 404);
    $fp = fsd_case_attach_dir_safe($db) . $case['export_pdf_name'];
    if (!is_file($fp)) jerr('PDF 檔不存在或已被搬移，請重新產生', 404);
    // 下載檔名用「案件標題-日期」，不用內部亂數檔名，使用者存到自己電腦才認得出是哪一份
    $safe = preg_replace('/[\\\/:*?"<>|]+/u', '_', trim((string)$case['title']) ?: ('案件' . $id));
    $show = $safe . '_' . (string)$case['business_date'] . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Length: ' . filesize($fp));
    header('Content-Disposition: ' . (!empty($_GET['dl']) ? 'attachment' : 'inline')
        . "; filename*=UTF-8''" . rawurlencode($show));
    readfile($fp);
    exit;
}

default: jerr('未知的操作：' . $action, 400);
}
