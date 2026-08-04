<?php
/**
 * 教育訓練管理 API
 * 權限：training_lib.php training_perms()（roles module='training'；admin⊃edit⊃view），fail-closed
 * 讀：GET；寫：POST，transaction。
 */
$document_root = $_SERVER['DOCUMENT_ROOT'];
session_start();
header('Content-Type: application/json; charset=utf-8');
include_once $document_root . '/EGsystem/src/common/_config.php';
include_once $document_root . '/EGsystem/src/common/DBConnection.php';
include_once $document_root . '/EGsystem/src/common/training_lib.php';

function jout($a){ echo json_encode(array_merge(['ok'=>true], $a), JSON_UNESCAPED_UNICODE); exit; }
function jerr($msg, $code=400){ http_response_code($code); echo json_encode(['ok'=>false,'error'=>$msg], JSON_UNESCAPED_UNICODE); exit; }

try {
    $db = (new DBConnection())->getPDO();
    training_ensure_schema($db);
} catch (Throwable $e) { jerr('DB連線失敗：'.$e->getMessage(), 500); }

$u = training_current_user($db);
if (!$u) jerr('未登入', 401);
$uid = (int)$u['id'];
$uname = (string)$u['user_cname'];
$perms = training_perms($db, $u);
$action = $_GET['action'] ?? $_POST['action'] ?? '';
// 需求申請單的核准人（申請單位部門主管）多半沒有教育訓練模組任何角色，只是被指派決行一筆申請單，
// 不能卡在「無教育訓練檢閱權限」——這兩個動作各自在 case 內再驗一次「是不是被指派的那個人」。
$publicActions = ['request_decide'];
if (!$perms['canView'] && !in_array($action, $publicActions, true)) jerr('無教育訓練檢閱權限', 403);

/** 申請人的主要部門（申請單「申請單位」預設值） */
function tr_user_dept_id(PDO $db, int $uid): ?int {
    try {
        $st = $db->prepare("SELECT department_id FROM user_department_position_map WHERE user_id=? ORDER BY is_main DESC, id LIMIT 1");
        $st->execute([$uid]);
        $v = $st->fetchColumn();
        return $v ? (int)$v : null;
    } catch (Throwable $e) { return null; }
}
function tr_user_dept_name(PDO $db, int $uid): string {
    $id = tr_user_dept_id($db, $uid);
    if (!$id) return '';
    $st = $db->prepare("SELECT name FROM department WHERE id=?");
    $st->execute([$id]);
    return (string)($st->fetchColumn() ?: '');
}

function tr_dept_map(PDO $db): array {
    $m = [];
    foreach ($db->query("SELECT id, name FROM department")->fetchAll(PDO::FETCH_ASSOC) as $d) $m[(int)$d['id']] = $d['name'];
    return $m;
}
function tr_locations(PDO $db): array {
    return $db->query("SELECT loc_id, name FROM training_location WHERE is_active=1 ORDER BY sort_order, loc_id")
              ->fetchAll(PDO::FETCH_ASSOC);
}
/* HH:MM 合法性（0-23 / 0-59，擋 25:00 這種） */
function tr_valid_time(?string $t): bool {
    if ($t === null || $t === '') return true;
    if (!preg_match('/^([0-9]{1,2}):([0-9]{2})$/', $t, $m)) return false;
    return (int)$m[1] <= 23 && (int)$m[2] <= 59;
}
function tr_norm_time(?string $t): ?string {
    $t = trim((string)$t);
    if ($t === '') return null;
    if (!preg_match('/^([0-9]{1,2}):([0-9]{2})$/', $t, $m)) return $t;   // 交給 tr_valid_time 擋
    return sprintf('%02d:%02d', (int)$m[1], (int)$m[2]);
}

/** 需求申請單一列（含部門名稱與最新一筆主管簽核紀錄），供多個 request_* action 共用 */
function tr_request_row(PDO $db, int $id): ?array {
    $deptMap = tr_dept_map($db);
    $st = $db->prepare("SELECT * FROM training_request WHERE request_id=?");
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    $r['dept_name'] = $r['dept_id'] !== null ? ($deptMap[(int)$r['dept_id']] ?? '') : '';
    $r['approval'] = eg_approval_latest($db, 'training_request', $id, 'manager');
    $dq = $db->prepare("SELECT * FROM training_request_day WHERE request_id=? ORDER BY day_no");
    $dq->execute([$id]);
    $r['days'] = $dq->fetchAll(PDO::FETCH_ASSOC);
    $tq = $db->prepare("SELECT * FROM training_request_trainee WHERE request_id=? ORDER BY rt_id");
    $tq->execute([$id]);
    $r['trainees_list'] = $tq->fetchAll(PDO::FETCH_ASSOC);
    return $r;
}

switch ($action) {

case 'meta': {
    $depts = $db->query("SELECT id, name, parent_id, level FROM department ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
    $years = $db->query("SELECT DISTINCT year FROM training_session ORDER BY year DESC")->fetchAll(PDO::FETCH_COLUMN);
    $cy = (int)date('Y');
    $years = array_values(array_unique(array_merge([$cy], array_map('intval', $years))));
    rsort($years);
    $cats = [];
    try { $cats = $db->query("SELECT id, category_name, color FROM event_category ORDER BY category_name")->fetchAll(PDO::FETCH_ASSOC); }
    catch (Throwable $e) {}
    training_purge_temp_attachments($db);      // 懶惰清除過期暫存附件
    jout(['perms'=>$perms, 'departments'=>$depts, 'years'=>$years, 'locations'=>tr_locations($db),
          'shifts'=>training_shifts($db), 'settings'=>training_settings($db), 'event_categories'=>$cats,
          'cat_internal_eff'=>training_category_id($db, 'internal'), 'cat_external_eff'=>training_category_id($db, 'external'),
          'att_cats'=>TRAINING_ATT_CATS, 'eval_methods'=>TRAINING_EVAL_METHODS,
          'dept_groups'=>training_dept_groups($db), 'units'=>training_units($db),
          'as_docs'=>(function($db){ try { return $db->query("SELECT id, doc_no, doc_name FROM as_document
                        WHERE COALESCE(is_deleted,0)=0 ORDER BY doc_no")->fetchAll(PDO::FETCH_ASSOC); }
                        catch (Throwable $e) { return []; } })($db),
          'doc_no'=>['plan'=>training_as_doc_no($db,'plan'), 'result'=>training_as_doc_no($db,'result'),
                     'target'=>training_as_doc_no($db,'target'), 'request'=>training_as_doc_no($db,'request'),
                     'signsheet'=>training_as_doc_no($db,'signsheet')],
          'company_name'=>eg_company_full_name($db),
          'plan_signers'=>training_plan_signers($db),
          'plan_approval'=>training_plan_approval($db, (int)($_GET['year'] ?? date('Y'))),
          'plan_last_modified'=>training_plan_last_modified($db, (int)($_GET['year'] ?? date('Y'))),
          'my_dept_id'=>tr_user_dept_id($db, $uid), 'my_dept_name'=>tr_user_dept_name($db, $uid),
          'my_depts'=>training_user_depts($db, $uid),
          'features'=>TRAINING_FEATURES,
          'request_signers'=>['top_approver'=>eg_org_user($db,'top_approver'), 'hr_signer'=>training_hr_signer_effective($db)],
          'attach_nas_dir'=>$perms['canAdmin'] ? training_attach_dir($db) : null,
          'attach_root'=>$perms['canAdmin'] ? eg_attach_root($db) : null,
          'cur_year'=>$cy, 'cur_month'=>(int)date('n'), 'today'=>date('Y-m-d'), 'uid'=>$uid]);
}

/* 模組設定（限訓練管理員）：預設班別、行事曆類別綁定（存 id 不存名稱） */
case 'save_settings': {
    if (!$perms['canAdmin']) jerr('無管理權限（設定限訓練管理員）', 403);
    $map = ['default_shift_id'=>'training_default_shift_id',
            'cat_internal'=>'training_cat_internal', 'cat_external'=>'training_cat_external',
            'as_doc_plan'=>'training_as_doc_plan', 'as_doc_result'=>'training_as_doc_result',
            'as_doc_target'=>'training_as_doc_target', 'need_approval'=>'training_need_approval',
            'as_doc_request'=>'training_as_doc_request', 'request_need_approval'=>'training_request_need_approval',
            'as_doc_signsheet'=>'training_as_doc_signsheet'];
    // 休息時段（HH:MM 字串）：兩欄都空＝不扣休息；只填一欄視為未設定
    $bs = tr_norm_time($_POST['break_start'] ?? null);
    $be = tr_norm_time($_POST['break_end'] ?? null);
    if (array_key_exists('break_start', $_POST) || array_key_exists('break_end', $_POST)) {
        if (!tr_valid_time($bs)) jerr("休息開始時間不是合法時刻（{$bs}）");
        if (!tr_valid_time($be)) jerr("休息結束時間不是合法時刻（{$be}）");
        if (($bs === null) !== ($be === null)) jerr('休息時段請「起、迄」兩個都填，或兩個都留空（＝不扣休息）');
        if ($bs !== null && $be <= $bs) jerr("休息結束（{$be}）不可早於或等於休息開始（{$bs}）");
    }
    try {
        $db->beginTransaction();
        foreach ($map as $post => $key) {
            if (!array_key_exists($post, $_POST)) continue;
            $v = trim((string)$_POST[$post]);
            training_setting_save($db, $key, $v === '' ? null : (int)$v, $uid, $uname);
        }
        if (array_key_exists('break_start', $_POST) || array_key_exists('break_end', $_POST)) {
            training_setting_save($db, 'training_break_start', $bs === null ? '' : $bs, $uid, $uname);
            training_setting_save($db, 'training_break_end',   $be === null ? '' : $be, $uid, $uname);
        }
        if (array_key_exists('plan_sign_date', $_POST)) {
            $sd = trim((string)$_POST['plan_sign_date']);
            if ($sd !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $sd)) jerr('簽章日期格式應為 YYYY-MM-DD');
            training_setting_save($db, 'training_plan_sign_date', $sd, $uid, $uname);
        }
        if (array_key_exists('exclude_depts', $_POST)) {
            $ex = implode(',', array_values(array_filter(array_map('intval', explode(',', (string)$_POST['exclude_depts'])))));
            training_setting_save($db, 'training_exclude_depts', $ex, $uid, $uname);
        }
        if (array_key_exists('signsheet_blank_rows', $_POST)) {
            $sb = trim((string)$_POST['signsheet_blank_rows']);
            if ($sb !== '' && $sb !== 'fill16' && (!ctype_digit($sb) || (int)$sb > 16)) jerr('簽到表空白列數請填 0~16 的整數，或選擇補滿頁');
            training_setting_save($db, 'training_signsheet_blank_rows', $sb, $uid, $uname);
        }
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('設定儲存失敗：'.$e->getMessage(), 500); }
    jout(['settings'=>training_settings($db), 'units'=>training_units($db),
          'doc_no'=>['plan'=>training_as_doc_no($db,'plan'), 'result'=>training_as_doc_no($db,'result'),
                     'target'=>training_as_doc_no($db,'target'), 'request'=>training_as_doc_no($db,'request'),
                     'signsheet'=>training_as_doc_no($db,'signsheet')],
          'cat_internal_eff'=>training_category_id($db, 'internal'), 'cat_external_eff'=>training_category_id($db, 'external')]);
}

/* ---------- 年度訓練計劃表送審（見 ai-rules/17-審核通知標準） ---------- */
case 'plan_status': {
    $year = (int)($_GET['year'] ?? date('Y'));
    jout(['year'=>$year, 'approval'=>training_plan_approval($db, $year), 'signers'=>training_plan_signers($db),
          'need_approval'=>(int)(training_settings($db)['training_need_approval'] ?? 0),
          'plan_last_modified'=>training_plan_last_modified($db, $year),
          'plan_sign_date'=>(string)(training_settings($db)['training_plan_sign_date'] ?? '')]);
}

case 'plan_submit': {
    if (!$perms['canEdit']) jerr('無登錄權限', 403);
    $year = (int)($_POST['year'] ?? 0);
    if ($year < 2000) jerr('請指定年度');
    $need = (int)(training_settings($db)['training_need_approval'] ?? 0) === 1;
    $sg = training_plan_signers($db);
    $cur = training_plan_approval($db, $year);
    if (in_array($cur['status'], ['review_pending','approve_pending'], true)) jerr('本年度計畫已在簽核中，請勿重複送審');
    if (!$need) {
        // 不需送審：直接視為完成（送審日＝簽章日期），列印時所有簽章欄一起顯示
        $id = eg_approval_submit($db, 'training_plan', $year, 'approve', $uid, $uname);
        eg_approval_decide($db, $id, $uid, $uname, 'approved', '模組設定為「不需送審」，送出即視同完成');
        jout(['status'=>'approved', 'need_approval'=>0]);
    }
    if (!$sg['reviewer']) jerr('尚未設定「人事表單審核者」或人事部門主管，請先到「組織角色綁定設定」設定', 400);
    $id = eg_approval_submit($db, 'training_plan', $year, 'review', $uid, $uname);
    $ev = training_plan_notify($db, $year, 'review', $sg['reviewer']['id'],
        $year.' 年度教育訓練計畫表待審核',
        $uname.' 送出 '.$year.' 年度教育訓練計畫表，請審核（點入可看完整計畫內容與附件，並直接核准或退回）。', $uid);
    if ($ev) eg_approval_set_live_event($db, $id, $ev);
    jout(['status'=>'review_pending', 'approval_id'=>$id, 'need_approval'=>1]);
}

/* 核准／退回（審核檢視頁使用）：退回一定要填原因 */
case 'plan_decide': {
    $year = (int)($_POST['year'] ?? 0);
    $decision = (string)($_POST['decision'] ?? '');
    $note = trim((string)($_POST['note'] ?? ''));
    if (!in_array($decision, ['approved','rejected'], true)) jerr('決定值不正確');
    if ($decision === 'rejected' && $note === '') jerr('退回必須填寫原因');
    $cur = training_plan_approval($db, $year);
    $sg  = training_plan_signers($db);
    $stage = $cur['status'] === 'review_pending' ? 'review' : ($cur['status'] === 'approve_pending' ? 'approve' : '');
    if ($stage === '') jerr('此年度計畫目前沒有待簽核的項目');
    $rec = $cur[$stage === 'review' ? 'review' : 'approve'];
    $who = $stage === 'review' ? $sg['reviewer'] : $sg['approver'];
    if (!$perms['isAdmin'] && (!$who || (int)$who['id'] !== $uid)) jerr('您不是本階段的簽核人', 403);
    $r = eg_approval_decide($db, (int)$rec['id'], $uid, $uname, $decision, $note ?: null);
    if (!$r['success']) jerr($r['message']);
    training_plan_close_notice($db, $year);
    $submitter = (int)$rec['submitted_by'];
    if ($decision === 'rejected') {
        training_plan_notify_result($db, $year, $submitter, $year.' 年度教育訓練計畫表被退回',
            $uname.' 退回 '.$year.' 年度教育訓練計畫表。退回原因：'.$note, $uid);
        jout(['status'=>'rejected']);
    }
    if ($stage === 'review') {
        if (!$sg['approver']) jerr('尚未設定「最高核准人員」，請先到「組織角色綁定設定」設定', 400);
        $id = eg_approval_submit($db, 'training_plan', $year, 'approve', $submitter, (string)$rec['submitted_by_name']);
        $ev = training_plan_notify($db, $year, 'approve', $sg['approver']['id'],
            $year.' 年度教育訓練計畫表待核准',
            $uname.' 已審核通過 '.$year.' 年度教育訓練計畫表，請核准（點入可看完整計畫內容與附件）。', $uid);
        if ($ev) eg_approval_set_live_event($db, $id, $ev);
        jout(['status'=>'approve_pending']);
    }
    training_plan_notify_result($db, $year, $submitter, $year.' 年度教育訓練計畫表已核准',
        $uname.' 已核准 '.$year.' 年度教育訓練計畫表。'.($note ? '意見：'.$note : ''), $uid);
    jout(['status'=>'approved']);
}

/* ---------- 場次附件（簽到表掃描/教材/試卷）：DB 只存檔名，路徑即時組（鐵律5） ---------- */
case 'list_attach': {
    training_purge_temp_attachments($db);
    $sid = (int)($_GET['session_id'] ?? 0);
    jout(['attachments'=>training_attachments($db, $sid)]);
}

case 'upload_attach': {
    if (!$perms['canEdit']) jerr('無登錄權限', 403);
    $sid = (int)($_POST['session_id'] ?? 0);
    if ($sid > 0) {
        $st = $db->prepare("SELECT 1 FROM training_session WHERE session_id=?");
        $st->execute([$sid]);
        if (!$st->fetchColumn()) jerr('找不到場次');
    }
    // 類別可複選（同一份掃描 PDF 可能同時是簽到表＋試卷），存逗號分隔
    $cats = array_values(array_filter(array_map('trim', explode(',', (string)($_POST['cat'] ?? ''))),
                                      fn($c) => isset(TRAINING_ATT_CATS[$c])));
    $cat = $cats ? implode(',', array_unique($cats)) : 'other';
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $err = $_FILES['file']['error'] ?? -1;
        jerr($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE
            ? '檔案超過伺服器允許的上傳大小（upload_max_filesize）' : '上傳失敗（錯誤碼 '.$err.'）');
    }
    $orig = basename((string)$_FILES['file']['name']);
    $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    $allow = ['pdf','jpg','jpeg','png','gif','webp','bmp','tif','tiff','doc','docx','xls','xlsx','ppt','pptx','txt','csv','zip'];
    if (!in_array($ext, $allow, true)) jerr('不支援的檔案類型「'.$ext.'」（可上傳：'.implode('、', $allow).'）');
    if ((int)$_FILES['file']['size'] > 50 * 1024 * 1024) jerr('單檔上限 50MB');
    $nasDir = training_attach_dir($db);
    if (!eg_attach_ensure_dir($nasDir)) jerr('無法建立附件目錄，請確認「模組設定」的附件路徑（含網路磁碟權限）：'.$nasDir, 500);
    $fname = date('Ymd_His_') . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($_FILES['file']['tmp_name'], $nasDir . $fname)) jerr('檔案寫入失敗（路徑：'.$nasDir.'）', 500);
    try {
        if ($sid > 0) {
            $db->prepare("INSERT INTO training_attachment (session_id, cat, file_name, original_name, file_size, user_id, user_name, status)
                          VALUES (?,?,?,?,?,?,?,'active')")
               ->execute([$sid, $cat, $fname, $orig, (int)$_FILES['file']['size'], $uid, $uname]);
        } else {   // 場次尚未存檔＝暫存 2 天，存檔時由 save_execution 帶 temp_att_ids 轉正
            $db->prepare("INSERT INTO training_attachment (session_id, cat, file_name, original_name, file_size, user_id, user_name, status, expire_at)
                          VALUES (0,?,?,?,?,?,?,'temp', DATE_ADD(NOW(), INTERVAL 2 DAY))")
               ->execute([$cat, $fname, $orig, (int)$_FILES['file']['size'], $uid, $uname]);
        }
    } catch (Throwable $e) { @unlink($nasDir . $fname); jerr('附件登錄失敗：'.$e->getMessage(), 500); }
    jout(['att_id'=>(int)$db->lastInsertId(), 'file_name'=>$fname, 'original_name'=>$orig,
          'cat'=>$cat, 'file_size'=>(int)$_FILES['file']['size']]);
}

case 'del_attach': {
    if (!$perms['canEdit']) jerr('無登錄權限', 403);
    $aid = (int)($_POST['att_id'] ?? 0);
    $st = $db->prepare("SELECT file_name, status, user_id FROM training_attachment WHERE att_id=?");
    $st->execute([$aid]);
    $a = $st->fetch(PDO::FETCH_ASSOC);
    if (!$a) jerr('找不到附件');
    if ($a['status'] === 'temp' && (int)$a['user_id'] !== $uid) jerr('暫存附件僅上傳者本人可刪', 403);
    $db->prepare("DELETE FROM training_attachment WHERE att_id=?")->execute([$aid]);
    $nasDir = training_attach_dir($db);
    $fp = $nasDir . $a['file_name'];
    if (is_file($fp)) @unlink($fp);
    jout([]);
}

/* 下載/預覽：一律經此守門（檢閱權即可），路徑現場組 */
case 'download_attach': {
    $aid = (int)($_GET['att_id'] ?? 0);
    $st = $db->prepare("SELECT file_name, original_name FROM training_attachment WHERE att_id=?");
    $st->execute([$aid]);
    $a = $st->fetch(PDO::FETCH_ASSOC);
    if (!$a) jerr('找不到附件');
    $nasDir = training_attach_dir($db);
    $fp = $nasDir . $a['file_name'];
    if (!is_file($fp)) jerr('檔案不存在（可能已被移動或附件路徑設定已變更）：'.$a['file_name'], 404);
    $ext = strtolower(pathinfo($a['file_name'], PATHINFO_EXTENSION));
    $mime = ['pdf'=>'application/pdf','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png',
             'gif'=>'image/gif','webp'=>'image/webp','bmp'=>'image/bmp','txt'=>'text/plain; charset=utf-8'][$ext] ?? 'application/octet-stream';
    $inline = (bool)preg_match('#^(image/|application/pdf|text/)#', $mime);
    $dl = $a['original_name'] ?: $a['file_name'];
    header('Content-Type: '.$mime);                                   // 覆蓋前面的 application/json
    header('Content-Length: '.filesize($fp));
    header('Content-Disposition: '.($inline ? 'inline' : 'attachment').'; filename*=UTF-8\'\''.rawurlencode($dl));
    readfile($fp);
    exit;
}

/* 附件儲存路徑（限訓練管理員；只存設定值，不存完整路徑到附件列） */
case 'save_attach_path': {
    if (!$perms['canAdmin']) jerr('無管理權限（附件路徑限訓練管理員）', 403);
    $nasDir = trim((string)($_POST['nas_dir'] ?? ''));
    if ($nasDir === '') jerr('附件儲存路徑不可為空（要恢復預設請填全站根路徑＋\\教育訓練）');
    try {
        $db->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_by_id, updated_by) VALUES (?,?,?,?)
                      ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),
                          updated_by_id=VALUES(updated_by_id), updated_by=VALUES(updated_by)")
           ->execute(['training_nas_dir', $nasDir, $uid, $uname]);
    } catch (Throwable $e) { jerr('路徑儲存失敗：'.$e->getMessage(), 500); }
    jout(['attach_nas_dir'=>training_attach_dir($db)]);
}

/* ---------- 部門合併群組（達標統計的顯示單位）：限訓練管理員 ---------- */
case 'save_dept_groups': {
    if (!$perms['canAdmin']) jerr('無管理權限（部門合併設定限訓練管理員）', 403);
    $groups = json_decode((string)($_POST['groups'] ?? '[]'), true);
    if (!is_array($groups)) jerr('資料格式不正確');
    try {
        $db->beginTransaction();
        $db->exec("DELETE FROM training_dept_group_member");
        $db->exec("UPDATE training_dept_group SET is_active=0");
        $ins  = $db->prepare("INSERT INTO training_dept_group (group_id, group_name, sort_order, is_active) VALUES (?,?,?,1)
                              ON DUPLICATE KEY UPDATE group_name=VALUES(group_name), sort_order=VALUES(sort_order), is_active=1");
        $ins2 = $db->prepare("INSERT INTO training_dept_group (group_name, sort_order, is_active) VALUES (?,?,1)");
        $mem  = $db->prepare("INSERT IGNORE INTO training_dept_group_member (dept_id, group_id) VALUES (?,?)");
        $i = 0;
        foreach ($groups as $g) {
            $name = trim((string)($g['group_name'] ?? ''));
            $ids  = array_values(array_unique(array_filter(array_map('intval', $g['dept_ids'] ?? []))));
            if ($name === '' || !$ids) continue;              // 沒名字或沒成員的群組直接不留
            $gid = (int)($g['group_id'] ?? 0);
            if ($gid > 0) { $ins->execute([$gid, $name, $i]); }
            else { $ins2->execute([$name, $i]); $gid = (int)$db->lastInsertId(); }
            foreach ($ids as $d) $mem->execute([$d, $gid]);
            $i++;
        }
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('合併設定儲存失敗：'.$e->getMessage(), 500); }
    jout(['dept_groups'=>training_dept_groups($db), 'units'=>training_units($db)]);
}

/* ---------- 訓練次數目標（每月/每年 × 內訓/外訓，可全公司統一或逐單位）：限訓練管理員 ---------- */
case 'save_targets': {
    if (!$perms['canAdmin']) jerr('無管理權限（目標設定限訓練管理員）', 403);
    $year = (int)($_POST['year'] ?? date('Y'));
    $list = json_decode((string)($_POST['targets'] ?? '[]'), true);
    if (!is_array($list)) jerr('資料格式不正確');
    try {
        $db->beginTransaction();
        $up = $db->prepare("INSERT INTO training_target (year, unit_key, internal_period, internal_times, external_period, external_times, updated_at, updated_by)
                            VALUES (?,?,?,?,?,?,NOW(),?)
                            ON DUPLICATE KEY UPDATE internal_period=VALUES(internal_period), internal_times=VALUES(internal_times),
                                external_period=VALUES(external_period), external_times=VALUES(external_times),
                                updated_at=NOW(), updated_by=VALUES(updated_by)");
        $del = $db->prepare("DELETE FROM training_target WHERE year=? AND unit_key=?");
        foreach ($list as $t) {
            $key = trim((string)($t['unit_key'] ?? ''));
            if ($key === '' || !preg_match('/^(ALL|[DG]\d+)$/', $key)) continue;
            if (!empty($t['use_default']) && $key !== 'ALL') { $del->execute([$year, $key]); continue; }  // 改回套用統一預設
            $ip = ($t['internal_period'] ?? 'year') === 'month' ? 'month' : 'year';
            $ep = ($t['external_period'] ?? 'year') === 'month' ? 'month' : 'year';
            $it = max(0, (int)($t['internal_times'] ?? 0));
            $et = max(0, (int)($t['external_times'] ?? 0));
            if ($it > 999 || $et > 999) jerr('次數上限 999');
            $up->execute([$year, $key, $ip, $it, $ep, $et, $uname]);
        }
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('目標儲存失敗：'.$e->getMessage(), 500); }
    jout(['targets'=>training_targets($db, $year)]);
}

/* 達標狀況（依顯示單位 × 月份 × 內外訓） */
case 'target_stats': {
    $year = (int)($_GET['year'] ?? date('Y'));
    jout(['year'=>$year, 'stats'=>training_target_stats($db, $year), 'targets'=>training_targets($db, $year),
          'units'=>training_units($db)]);
}

/* 單一場次完整內容（清單展開／檢視畫面用）：場次＋上課日＋參加人員(含評鑑)＋附件 */
case 'session_detail': {
    $sid = (int)($_GET['session_id'] ?? 0);
    $st = $db->prepare("SELECT s.*, d.name AS dept_name FROM training_session s
                        LEFT JOIN department d ON d.id=s.dept_id WHERE s.session_id=?");
    $st->execute([$sid]);
    $s = $st->fetch(PDO::FETCH_ASSOC);
    if (!$s) jerr('找不到場次');
    $dq = $db->prepare("SELECT day_no, day_date, start_time, end_time, break_minutes, hours FROM training_session_day
                        WHERE session_id=? ORDER BY day_no, day_date");
    $dq->execute([$sid]);
    $aq = $db->prepare("SELECT user_id, user_name, dept_name, position_name, attended, signed, eval_result, eval_score, eval_note
                        FROM training_attendee WHERE session_id=? ORDER BY dept_name, user_name");
    $aq->execute([$sid]);
    $deptIds = training_session_depts($db, [$sid])[$sid] ?? [];
    $dnames = [];
    if ($deptIds) {
        $in = implode(',', $deptIds);
        $dnames = $db->query("SELECT name FROM department WHERE id IN ({$in}) ORDER BY sort_order, id")->fetchAll(PDO::FETCH_COLUMN);
    }
    jout(['session'=>$s, 'dept_ids'=>$deptIds, 'dept_names'=>$dnames,
          'days'=>$dq->fetchAll(PDO::FETCH_ASSOC), 'attendees'=>$aq->fetchAll(PDO::FETCH_ASSOC),
          'attachments'=>training_attachments($db, $sid)]);
}

/* ---------- 上課地點主檔（設定後可下拉選擇） ---------- */
case 'save_location': {
    if (!$perms['canEdit']) jerr('無登錄權限', 403);
    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '') jerr('請輸入地點名稱');
    if (mb_strlen($name) > 100) jerr('地點名稱過長（上限 100 字）');
    try {
        $db->beginTransaction();
        $st = $db->prepare("SELECT loc_id, is_active FROM training_location WHERE name=?");
        $st->execute([$name]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            if ((int)$row['is_active'] !== 1)
                $db->prepare("UPDATE training_location SET is_active=1 WHERE loc_id=?")->execute([$row['loc_id']]);
        } else {
            $db->prepare("INSERT INTO training_location (name, sort_order, created_by)
                          VALUES (?, (SELECT COALESCE(MAX(t.sort_order),0)+1 FROM training_location t), ?)")
               ->execute([$name, $uid]);
        }
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('地點儲存失敗：'.$e->getMessage(), 500); }
    jout(['locations'=>tr_locations($db), 'name'=>$name]);
}

case 'del_location': {
    if (!$perms['canAdmin']) jerr('無管理權限（停用地點限訓練管理員）', 403);
    $locId = (int)($_POST['loc_id'] ?? 0);
    try {
        $db->prepare("UPDATE training_location SET is_active=0 WHERE loc_id=?")->execute([$locId]);
    } catch (Throwable $e) { jerr('停用失敗：'.$e->getMessage(), 500); }
    jout(['locations'=>tr_locations($db)]);
}

case 'list': {
    $year = (int)($_GET['year'] ?? date('Y'));
    $deptMap = tr_dept_map($db);
    $st = $db->prepare("SELECT * FROM training_session WHERE year=? ORDER BY plan_month, session_id");
    $st->execute([$year]);
    // 各場次上課日期（多天課程）
    $dq = $db->prepare("SELECT d.session_id, d.day_no, d.day_date, d.start_time, d.end_time, d.hours,
                               d.break_minutes, d.evenement_id
                        FROM training_session_day d JOIN training_session s ON s.session_id=d.session_id
                        WHERE s.year=? ORDER BY d.session_id, d.day_no, d.day_date");
    $dq->execute([$year]);
    $dayMap = [];
    foreach ($dq->fetchAll(PDO::FETCH_ASSOC) as $d) $dayMap[(int)$d['session_id']][] = $d;
    // 各場次附件數（讓清單看得出簽到表/教材有沒有上傳）
    $attMap = [];
    try {
        $aq = $db->prepare("SELECT a.session_id, COUNT(*) c FROM training_attachment a
                            JOIN training_session s ON s.session_id=a.session_id
                            WHERE s.year=? AND a.status='active' GROUP BY a.session_id");
        $aq->execute([$year]);
        foreach ($aq->fetchAll(PDO::FETCH_ASSOC) as $a) $attMap[(int)$a['session_id']] = (int)$a['c'];
    } catch (Throwable $e) {}
    // 各場次評鑑統計（清單顯示合格狀態）
    $evMap = [];
    try {
        $eq = $db->prepare("SELECT a.session_id,
                                   SUM(a.eval_result='pass') p, SUM(a.eval_result='fail') f,
                                   SUM(a.eval_result='exempt') x, SUM(a.eval_result IS NULL OR a.eval_result='') n
                            FROM training_attendee a JOIN training_session s ON s.session_id=a.session_id
                            WHERE s.year=? GROUP BY a.session_id");
        $eq->execute([$year]);
        foreach ($eq->fetchAll(PDO::FETCH_ASSOC) as $e)
            $evMap[(int)$e['session_id']] = ['pass'=>(int)$e['p'], 'fail'=>(int)$e['f'], 'exempt'=>(int)$e['x'], 'none'=>(int)$e['n']];
    } catch (Throwable $e) {}
    // 對象部門（複選）
    $sq = $db->prepare("SELECT sd.session_id, sd.dept_id FROM training_session_dept sd
                        JOIN training_session s ON s.session_id=sd.session_id WHERE s.year=?");
    $sq->execute([$year]);
    $sdMap = [];
    foreach ($sq->fetchAll(PDO::FETCH_ASSOC) as $d) $sdMap[(int)$d['session_id']][] = (int)$d['dept_id'];

    $rows = [];
    $summary = [];
    for ($m = 1; $m <= 12; $m++) $summary[$m] = ['den'=>0, 'num'=>0];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $sid = (int)$r['session_id'];
        $r['dept_ids'] = $sdMap[$sid] ?? ($r['dept_id'] !== null ? [(int)$r['dept_id']] : []);
        $r['dept_name'] = $r['dept_ids']
            ? implode('、', array_map(fn($d) => $deptMap[$d] ?? '', $r['dept_ids'])) : '全公司';
        $r['days'] = $dayMap[$sid] ?? [];
        $r['attach_count'] = $attMap[$sid] ?? 0;
        $r['eval'] = $evMap[$sid] ?? ['pass'=>0,'fail'=>0,'exempt'=>0,'none'=>0];
        $rows[] = $r;
        $m = (int)$r['plan_month'];
        if ($r['status'] !== 'cancelled') $summary[$m]['den']++;
        if ($r['status'] === 'done') $summary[$m]['num']++;
    }
    // 年度合計達成率
    $yd = 0; $yn = 0;
    foreach ($summary as $s) { $yd += $s['den']; $yn += $s['num']; }
    jout(['rows'=>$rows, 'year'=>$year, 'summary'=>$summary,
          'year_rate'=>$yd > 0 ? round($yn / $yd * 100, 1) : null, 'year_den'=>$yd, 'year_num'=>$yn,
          'perms'=>$perms]);
}

/* 新增/修改「訓練計畫」（登錄權）
   只寫計畫欄位：年月/部門/課程/類型/講師或開課單位/時數/備註。
   實行欄位（狀態、實際開課日、時段、地點、名單）一律由 save_execution 維護，此處不動。 */
case 'save_session': {
    if (!$perms['canEdit']) jerr('無登錄權限', 403);
    $sid = (int)($_POST['session_id'] ?? 0);
    // 已排定/已完成的場次＝計畫已定案，只有訓練管理員能再改計畫內容（避免事後動到已成立的紀錄）
    if ($sid > 0) {
        $st = $db->prepare("SELECT status FROM training_session WHERE session_id=?");
        $st->execute([$sid]);
        $curSt = $st->fetchColumn();
        if ($curSt === false) jerr('找不到場次');
        if (in_array($curSt, ['scheduled','done'], true) && !$perms['canAdmin'])
            jerr('此場次已「'.($curSt === 'done' ? '完成' : '排定開課').'」，計畫內容僅訓練管理員可修改；如需改期請用「實行資料」或先退回計畫中', 403);
    }
    $year = (int)($_POST['year'] ?? 0);
    $month = (int)($_POST['plan_month'] ?? 0);
    $course = trim((string)($_POST['course_name'] ?? ''));
    if ($year < 2000 || $month < 1 || $month > 12) jerr('請選擇有效年月');
    if ($course === '') jerr('請填課程名稱');
    // 對象部門複選（dept_ids）；相容舊呼叫的單一 dept_id
    $deptIds = array_values(array_unique(array_filter(array_map('intval',
        explode(',', (string)($_POST['dept_ids'] ?? ($_POST['dept_id'] ?? '')))))));
    $deptId = $deptIds ? $deptIds[0] : null;
    $trainType = ($_POST['train_type'] ?? '') === 'external' ? 'external' : 'internal';
    $trainer = trim((string)($_POST['trainer'] ?? '')) ?: null;      // 內訓講師姓名
    $trainerId = ($_POST['trainer_id'] ?? '') === '' ? null : (int)$_POST['trainer_id'];
    $orgUnit = trim((string)($_POST['org_unit'] ?? '')) ?: null;     // 外訓開課單位
    if ($trainType === 'external') { $trainer = null; $trainerId = null; }
    else { $orgUnit = null; }
    if ($trainType === 'external' && $orgUnit === null) jerr('外訓請填開課單位');
    $hours = ($_POST['hours'] ?? '') === '' ? null : (float)$_POST['hours'];
    $planDays = ($_POST['plan_days'] ?? '') === '' ? null : max(1, (int)$_POST['plan_days']);
    if ($planDays !== null && $planDays > 60) jerr('計畫天數請勿超過 60 天');
    $note = trim((string)($_POST['note'] ?? '')) ?: null;
    try {
        $db->beginTransaction();
        if ($sid > 0) {
            $db->prepare("UPDATE training_session SET year=?, plan_month=?, dept_id=?, course_name=?, train_type=?, trainer=?, trainer_id=?, org_unit=?,
                          hours=?, plan_days=?, note=? WHERE session_id=?")
               ->execute([$year,$month,$deptId,$course,$trainType,$trainer,$trainerId,$orgUnit,$hours,$planDays,$note,$sid]);
        } else {
            $db->prepare("INSERT INTO training_session
                (year,plan_month,dept_id,course_name,train_type,trainer,trainer_id,org_unit,hours,plan_days,status,note,created_by,created_by_name)
                VALUES (?,?,?,?,?,?,?,?,?,?, 'planned', ?,?,?)")
               ->execute([$year,$month,$deptId,$course,$trainType,$trainer,$trainerId,$orgUnit,$hours,$planDays,$note,$uid,$uname]);
            $sid = (int)$db->lastInsertId();
        }
        training_save_session_depts($db, $sid, $deptIds);      // 對象部門複選（同步主表 dept_id）
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('儲存失敗：'.$e->getMessage(), 500); }
    jout(['session_id'=>$sid]);
}

/* 確認實行（登錄權）：登錄上課日期/時段/地點（支援多天課程，days JSON 每天一列）。
   狀態：mark_done=1 → done(已完成，計入 KPI 分子)；否則至少推進到 scheduled(已排定，可印簽到表)，
   已經是 done 的場次維持 done（單純修改實行紀錄）。計畫欄位一律不動；名單另由 save_attendees 寫入。
   主表 done_date/start_time/end_time = 第 1 天的值、actual_hours = 各天合計（相容既有程式與 KPI）。 */
case 'save_execution': {
    if (!$perms['canEdit']) jerr('無登錄權限', 403);
    $sid = (int)($_POST['session_id'] ?? 0);
    $st = $db->prepare("SELECT status FROM training_session WHERE session_id=?");
    $st->execute([$sid]);
    $cur = $st->fetchColumn();
    if ($cur === false) jerr('找不到場次');
    if ($cur === 'cancelled') jerr('此計畫已取消，請先恢復為計畫中再確認實行');
    $location = trim((string)($_POST['location'] ?? '')) ?: null;

    $shiftId = ($_POST['shift_type_id'] ?? '') === '' ? null : (int)$_POST['shift_type_id'];
    // 課程大綱／評鑑方式（確認開課時填）
    $outline = trim((string)($_POST['outline'] ?? ''));
    if (mb_strlen($outline) > 5000) jerr('課程大綱過長（上限 5000 字）');
    $evalMethod = trim((string)($_POST['eval_method'] ?? ''));
    if ($evalMethod !== '' && !isset(TRAINING_EVAL_METHODS[$evalMethod])) jerr('評鑑方式不正確');

    // days：[{day_date, start_time, end_time, break_minutes, hours}, ...]；沒帶就退回單天（相容舊呼叫）
    $days = json_decode((string)($_POST['days'] ?? '[]'), true);
    if (!is_array($days) || !count($days)) {
        $days = [['day_date'=>trim((string)($_POST['done_date'] ?? '')),
                  'start_time'=>$_POST['start_time'] ?? '', 'end_time'=>$_POST['end_time'] ?? '',
                  'hours'=>$_POST['actual_hours'] ?? '']];
    }
    if (count($days) > 60) jerr('上課天數請勿超過 60 天');
    $trSet = training_settings($db);          // 休息時段（重算休息分鐘用），迴圈外讀一次
    $clean = []; $seen = [];
    foreach ($days as $i => $d) {
        $n = $i + 1;
        $date = trim((string)($d['day_date'] ?? ''));
        if ($date === '') jerr("第 {$n} 天：請填上課日期");
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) jerr("第 {$n} 天：日期格式不正確（應為 YYYY-MM-DD）");
        [$yy,$mm,$dd] = array_map('intval', explode('-', $date));
        if (!checkdate($mm, $dd, $yy)) jerr("第 {$n} 天：日期不存在（{$date}）");
        if (isset($seen[$date])) jerr("第 {$n} 天：日期重複（{$date}）");
        $seen[$date] = true;
        $s = tr_norm_time($d['start_time'] ?? '');
        $e = tr_norm_time($d['end_time'] ?? '');
        if (!tr_valid_time($s)) jerr("第 {$n} 天：開始時間不是合法時刻（{$s}），時須 0-23、分須 0-59");
        if (!tr_valid_time($e)) jerr("第 {$n} 天：結束時間不是合法時刻（{$e}），時須 0-23、分須 0-59");
        if ($s && $e && $e <= $s) jerr("第 {$n} 天：結束時間（{$e}）不可早於或等於開始時間（{$s}）");
        // 休息一律由系統依「上課時間 ∩ 休息時段」重算（前端不給改，後端也不採信送上來的值）
        $brk = training_break_minutes($db, $s, $e, $trSet);
        // 時數＝(結束−開始)−休息；使用者可手填覆蓋（例：中間穿插其他行程）
        $span = ($s && $e) ? (int)round((strtotime("1970-01-01 $e UTC") - strtotime("1970-01-01 $s UTC")) / 60) : null;
        if ($span !== null && $brk >= $span)
            jerr("第 {$n} 天：上課時間 {$s}~{$e} 全部落在休息時段（{$trSet['training_break_start']}~{$trSet['training_break_end']}），扣除休息後沒有時數；請調整上課時間，或到「模組設定」改休息時段");
        $h = ($d['hours'] ?? '') === '' ? null : (float)$d['hours'];
        if ($h === null && $span !== null) $h = round(($span - $brk) / 60, 1);
        if ($h !== null && ($h < 0 || $h > 24)) jerr("第 {$n} 天：時數 {$h} 不合理（0~24）");
        $clean[] = ['date'=>$date, 'start'=>$s, 'end'=>$e, 'break'=>$brk, 'hours'=>$h];
    }
    usort($clean, fn($a, $b) => strcmp($a['date'], $b['date']));
    $totalH = 0; $hasH = false;
    foreach ($clean as $c) if ($c['hours'] !== null) { $totalH += $c['hours']; $hasH = true; }

    $markDone = (int)($_POST['mark_done'] ?? 0) === 1;
    $newStatus = $markDone ? 'done' : ($cur === 'done' ? 'done' : 'scheduled');
    try {
        $db->beginTransaction();
        training_event_remove($db, $sid);      // 舊事件先清（日期/時間可能已變動）
        $db->prepare("UPDATE training_session SET status=?, done_date=?, start_time=?, end_time=?, location=?, actual_hours=?, plan_days=?, shift_type_id=?,
                             outline=?, eval_method=?
                      WHERE session_id=?")
           ->execute([$newStatus, $clean[0]['date'], $clean[0]['start'], $clean[0]['end'], $location,
                      $hasH ? round($totalH, 1) : null, count($clean), $shiftId,
                      $outline === '' ? null : $outline, $evalMethod === '' ? null : $evalMethod, $sid]);
        // 暫存附件轉正（與主單同一筆交易內；限本人上傳的 temp）
        $tempIds = array_values(array_filter(array_map('intval', explode(',', (string)($_POST['temp_att_ids'] ?? '')))));
        if ($tempIds) {
            $in = implode(',', $tempIds);
            $db->prepare("UPDATE training_attachment SET session_id=?, status='active', expire_at=NULL
                          WHERE att_id IN ({$in}) AND user_id=? AND status='temp'")->execute([$sid, $uid]);
        }
        $db->prepare("DELETE FROM training_session_day WHERE session_id=?")->execute([$sid]);
        $ins = $db->prepare("INSERT INTO training_session_day (session_id, day_no, day_date, start_time, end_time, break_minutes, hours)
                             VALUES (?,?,?,?,?,?,?)");
        foreach ($clean as $i => $c) $ins->execute([$sid, $i + 1, $c['date'], $c['start'], $c['end'], $c['break'], $c['hours']]);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('儲存失敗：'.$e->getMessage(), 500); }
    // 確定開課後自動寫入行事曆（一天一筆；失敗不擋存檔）
    $evCount = training_event_sync($db, $sid);
    jout(['session_id'=>$sid, 'status'=>$newStatus, 'days'=>count($clean), 'events'=>$evCount,
          'total_hours'=>$hasH ? round($totalH, 1) : null]);
}

/* 狀態切換（登錄權）：退回計畫中 / 取消計畫 / 恢復計畫。
   退回或取消時清空開課日（KPI 只認 done），時段/地點/實際時數保留供改期後沿用。 */
case 'set_status': {
    if (!$perms['canEdit']) jerr('無登錄權限', 403);
    $sid = (int)($_POST['session_id'] ?? 0);
    $status = (string)($_POST['status'] ?? '');
    if (!in_array($status, ['planned','cancelled'], true)) jerr('狀態只能設為計畫中或取消');
    $st = $db->prepare("SELECT 1 FROM training_session WHERE session_id=?");
    $st->execute([$sid]);
    if (!$st->fetchColumn()) jerr('找不到場次');
    try {
        $db->beginTransaction();
        training_event_remove($db, $sid);      // 退回計畫中/取消 → 一併撤掉行事曆事件
        $db->prepare("UPDATE training_session SET status=?, done_date=NULL WHERE session_id=?")->execute([$status, $sid]);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('狀態變更失敗：'.$e->getMessage(), 500); }
    jout(['session_id'=>$sid, 'status'=>$status]);
}

/* ============================================================
 * 教育訓練需求申請單（2-MM-01-05 線上化）
 *   draft(草稿,僅本人可見可改) → submitted/approved(視是否需簽核) → 由訓練管理員轉為計畫(converted)
 *   權限：canApply(含 canEdit) 才可新增/送出；canView 皆可看清單(唯讀)；決行限被指派的部門主管或管理員；
 *        轉計畫/刪除限 canEdit/canAdmin。
 * ============================================================ */
case 'request_list': {
    $deptMap = tr_dept_map($db);
    $st = $db->query("SELECT * FROM training_request ORDER BY request_id DESC");
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $ids = array_column($rows, 'request_id');
    $trMap = []; $dyMap = [];
    if ($ids) {
        $in = implode(',', array_map('intval', $ids));
        foreach ($db->query("SELECT * FROM training_request_trainee WHERE request_id IN ($in) ORDER BY rt_id")->fetchAll(PDO::FETCH_ASSOC) as $t)
            $trMap[(int)$t['request_id']][] = $t;
        foreach ($db->query("SELECT * FROM training_request_day WHERE request_id IN ($in) ORDER BY day_no")->fetchAll(PDO::FETCH_ASSOC) as $d)
            $dyMap[(int)$d['request_id']][] = $d;
    }
    foreach ($rows as &$r) {
        $rid = (int)$r['request_id'];
        $r['dept_name'] = $r['dept_id'] !== null ? ($deptMap[(int)$r['dept_id']] ?? '') : '';
        $rec = eg_approval_latest($db, 'training_request', $rid, 'manager');
        $r['approver_name'] = $rec['approver_name'] ?? null;
        $r['reject_note'] = ($rec && $rec['status'] === 'rejected') ? $rec['note'] : null;
        $r['trainees_list'] = $trMap[$rid] ?? [];
        $r['days'] = $dyMap[$rid] ?? [];
        $signer = training_request_signer($db, $r['dept_id'] !== null ? (int)$r['dept_id'] : null, (int)$r['user_id']);
        $r['dept_signer_name'] = $signer['name'] ?? null;
    }
    unset($r);
    jout(['requests'=>$rows]);
}

case 'request_save': {
    if (!$perms['canApply']) jerr('無需求申請權限', 403);
    $rid = (int)($_POST['request_id'] ?? 0);
    if ($rid > 0) {
        $old = tr_request_row($db, $rid);
        if (!$old) jerr('找不到申請單');
        if (!in_array($old['status'], ['draft','rejected'], true) && !$perms['canAdmin']) jerr('此申請單已送審，不可修改（如需異動請先撤回或請訓練管理員協助）');
        if ((int)$old['user_id'] !== $uid && !$perms['canAdmin']) jerr('只能修改自己的申請單', 403);
    }
    $subject = trim((string)($_POST['subject'] ?? ''));
    if ($subject === '') jerr('請填主旨');
    $deptId = ($_POST['dept_id'] ?? '') === '' ? null : (int)$_POST['dept_id'];
    // 申請單位只能是自己所屬的部門（含兼職）；訓練管理員/系統管理者可以幫忙代填任何部門
    if ($deptId !== null && !$perms['canAdmin']) {
        $myDeptIds = array_map(fn($d) => (int)$d['id'], training_user_depts($db, $uid));
        if (!in_array($deptId, $myDeptIds, true)) jerr('申請單位只能選自己所屬的部門');
    }
    $applyDate = trim((string)($_POST['apply_date'] ?? date('Y-m-d')));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $applyDate)) jerr('申請日期格式不正確');

    // 每日上課時間（比照確認開課的逐日設定；申請階段不設休息）
    $days = json_decode((string)($_POST['days'] ?? '[]'), true);
    if (!is_array($days)) $days = [];
    if (count($days) > 60) jerr('受訓天數請勿超過 60 天');
    $cleanDays = []; $seenDates = [];
    foreach ($days as $i => $d) {
        $n = $i + 1;
        $dt = trim((string)($d['day_date'] ?? ''));
        if ($dt === '') jerr("第 {$n} 天：請填上課日期");
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dt)) jerr("第 {$n} 天：日期格式不正確");
        [$yy,$mm,$dd] = array_map('intval', explode('-', $dt));
        if (!checkdate($mm, $dd, $yy)) jerr("第 {$n} 天：日期不存在（{$dt}）");
        if (isset($seenDates[$dt])) jerr("第 {$n} 天：日期重複（{$dt}）");
        $seenDates[$dt] = true;
        $s = tr_norm_time($d['start_time'] ?? ''); $e = tr_norm_time($d['end_time'] ?? '');
        if (!tr_valid_time($s)) jerr("第 {$n} 天：開始時間不是合法時刻");
        if (!tr_valid_time($e)) jerr("第 {$n} 天：結束時間不是合法時刻");
        if ($s && $e && $e <= $s) jerr("第 {$n} 天：結束時間不可早於或等於開始時間");
        $cleanDays[] = ['date'=>$dt, 'start'=>$s, 'end'=>$e];
    }
    usort($cleanDays, fn($a,$b) => strcmp($a['date'], $b['date']));
    $sd = $cleanDays ? $cleanDays[0]['date'] : null;
    $ed = $cleanDays ? end($cleanDays)['date'] : null;
    $planDays = $cleanDays ? count($cleanDays) : null;
    $hours = ($_POST['hours'] ?? '') === '' ? null : (float)$_POST['hours'];

    // 受訓人員（結構化；限申請單位底下人員，不排除申請人本人，前端已用確認開課的同一套人員選擇器）
    $trainees = json_decode((string)($_POST['trainees'] ?? '[]'), true);
    if (!is_array($trainees)) $trainees = [];
    $traineeRows = []; $traineeNames = [];
    foreach ($trainees as $t) {
        $tuid = (int)($t['user_id'] ?? 0);
        if ($tuid <= 0) continue;
        $tn = trim((string)($t['user_name'] ?? ''));
        $traineeRows[] = [$tuid, $tn ?: null, trim((string)($t['dept_name'] ?? '')) ?: null, trim((string)($t['position_name'] ?? '')) ?: null];
        if ($tn) $traineeNames[] = $tn;
    }

    $fields = [$deptId, $applyDate, $subject,
        trim((string)($_POST['content'] ?? '')) ?: null, trim((string)($_POST['focus'] ?? '')) ?: null,
        $traineeNames ? implode('、', $traineeNames) : null, $sd, $ed, $planDays, $hours,
        trim((string)($_POST['location'] ?? '')) ?: null, trim((string)($_POST['cost'] ?? '')) ?: null,
        ($_POST['brochure_count'] ?? '') === '' ? null : (int)$_POST['brochure_count']];
    try {
        $db->beginTransaction();
        if ($rid > 0) {
            $db->prepare("UPDATE training_request SET dept_id=?,apply_date=?,subject=?,content=?,focus=?,trainees=?,
                          start_date=?,end_date=?,days=?,hours=?,location=?,cost=?,brochure_count=?,updated_at=NOW()
                          WHERE request_id=?")->execute(array_merge($fields, [$rid]));
        } else {
            $db->prepare("INSERT INTO training_request (dept_id,apply_date,subject,content,focus,trainees,
                          start_date,end_date,days,hours,location,cost,brochure_count,user_id,user_name,status)
                          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'draft')")
               ->execute(array_merge($fields, [$uid, $uname]));
            $rid = (int)$db->lastInsertId();
        }
        $db->prepare("DELETE FROM training_request_day WHERE request_id=?")->execute([$rid]);
        $insD = $db->prepare("INSERT INTO training_request_day (request_id, day_no, day_date, start_time, end_time) VALUES (?,?,?,?,?)");
        foreach ($cleanDays as $i => $d) $insD->execute([$rid, $i+1, $d['date'], $d['start'], $d['end']]);
        $db->prepare("DELETE FROM training_request_trainee WHERE request_id=?")->execute([$rid]);
        $insT = $db->prepare("INSERT INTO training_request_trainee (request_id, user_id, user_name, dept_name, position_name) VALUES (?,?,?,?,?)");
        foreach ($traineeRows as $t) $insT->execute(array_merge([$rid], $t));
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('儲存失敗：'.$e->getMessage(), 500); }
    jout(['request_id'=>$rid]);
}

/* 修改申請日期（補登舊文件用）：不受一般「已送審不可改」的限制，僅需 canEditApplyDate/isAdmin */
case 'request_set_apply_date': {
    if (!$perms['isAdmin'] && !$perms['canEditApplyDate']) jerr('無修改申請日期權限', 403);
    $rid = (int)($_POST['request_id'] ?? 0);
    $d = trim((string)($_POST['apply_date'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) jerr('日期格式不正確');
    $r = tr_request_row($db, $rid);
    if (!$r) jerr('找不到申請單');
    $db->prepare("UPDATE training_request SET apply_date=?, updated_at=NOW() WHERE request_id=?")->execute([$d, $rid]);
    jout(['apply_date'=>$d]);
}

/* 送出審核：依模組設定決定要不要真的送主管，免簽核／抓不到主管／申請人就是主管 → 直接視為核准 */
case 'request_submit': {
    if (!$perms['canApply']) jerr('無需求申請權限', 403);
    $rid = (int)($_POST['request_id'] ?? 0);
    $r = tr_request_row($db, $rid);
    if (!$r) jerr('找不到申請單');
    if ((int)$r['user_id'] !== $uid && !$perms['canAdmin']) jerr('只能送出自己的申請單', 403);
    if (!in_array($r['status'], ['draft','rejected'], true)) jerr('此申請單目前狀態不可送出');
    $needAppr = (int)(training_settings($db)['training_request_need_approval'] ?? 1) === 1;
    $signer = $needAppr ? training_request_signer($db, $r['dept_id'] !== null ? (int)$r['dept_id'] : null, (int)$r['user_id']) : null;
    try {
        if (!$signer) {
            $note = $needAppr ? '找不到申請單位的部門主管，系統自動核准' : '模組設定為「免簽核」，送出即視同核准';
            $aid = eg_approval_submit($db, 'training_request', $rid, 'manager', $uid, $uname);
            eg_approval_decide($db, $aid, $uid, $uname, 'approved', $note);
            $db->prepare("UPDATE training_request SET status='approved', updated_at=NOW() WHERE request_id=?")->execute([$rid]);
            jout(['status'=>'approved']);
        }
        $aid = eg_approval_submit($db, 'training_request', $rid, 'manager', $uid, $uname);
        $db->prepare("UPDATE training_request SET status='submitted', updated_at=NOW() WHERE request_id=?")->execute([$rid]);
        $ev = training_request_notify($db, $rid, $signer['id'],
            '教育訓練需求申請待核准：'.$r['subject'],
            $uname.'（'.($r['dept_name']?:'').'）送出教育訓練需求申請「'.$r['subject'].'」，請核准。', $uid);
        if ($ev) eg_approval_set_live_event($db, $aid, $ev);
    } catch (Throwable $e) { jerr('送出失敗：'.$e->getMessage(), 500); }
    jout(['status'=>'submitted']);
}

/* 核准／退回：只有被指派的部門主管或管理員可決行；退回強制填原因（ai-rules/17） */
case 'request_decide': {
    $rid = (int)($_POST['request_id'] ?? 0);
    $decision = (string)($_POST['decision'] ?? '');
    $note = trim((string)($_POST['note'] ?? ''));
    if (!in_array($decision, ['approved','rejected'], true)) jerr('決定值不正確');
    if ($decision === 'rejected' && $note === '') jerr('退回必須填寫原因');
    $r = tr_request_row($db, $rid);
    if (!$r || $r['status'] !== 'submitted' || !$r['approval'] || $r['approval']['status'] !== 'pending') jerr('此申請單目前沒有待核准的項目');
    $signer = training_request_signer($db, $r['dept_id'] !== null ? (int)$r['dept_id'] : null, (int)$r['user_id']);
    if (!$perms['isAdmin'] && (!$signer || (int)$signer['id'] !== $uid)) jerr('您不是此申請單的核准人', 403);
    $ret = eg_approval_decide($db, (int)$r['approval']['id'], $uid, $uname, $decision, $note ?: null);
    if (!$ret['success']) jerr($ret['message']);
    $db->prepare("UPDATE training_request SET status=?, updated_at=NOW() WHERE request_id=?")->execute([$decision === 'approved' ? 'approved' : 'rejected', $rid]);
    training_request_close_notice($db, $rid);
    training_request_notify_result($db, $rid, (int)$r['user_id'],
        '教育訓練需求申請「'.$r['subject'].'」已'.($decision==='approved'?'核准':'退回'),
        $uname.($decision==='approved' ? ' 已核准您的申請。'.($note?'意見：'.$note:'') : ' 已退回您的申請。退回原因：'.$note), $uid);
    jout(['status'=>$decision === 'approved' ? 'approved' : 'rejected']);
}

/* 轉為計畫：由「新增計畫」跳窗預填申請單資料後正常存檔，存檔成功後呼叫本動作把申請單標記為已轉 */
/* 轉為計畫：把申請單的每日時間與受訓人員原封帶入新計畫（training_session_day/training_attendee），
   確認開課時就已經是排好的，不必重選日期、名單「可另外增減」而不是從零重建。 */
case 'request_mark_converted': {
    if (!$perms['canEdit']) jerr('無登錄權限', 403);
    $rid = (int)($_POST['request_id'] ?? 0);
    $sid = (int)($_POST['session_id'] ?? 0);
    $r = tr_request_row($db, $rid);
    if (!$r) jerr('找不到申請單');
    if ($r['status'] !== 'approved') jerr('只有已核准的申請單可以轉為計畫');
    try {
        $db->beginTransaction();
        $db->prepare("UPDATE training_request SET status='converted', session_id=?, updated_at=NOW() WHERE request_id=?")->execute([$sid, $rid]);
        $db->prepare("UPDATE training_session SET from_request_id=? WHERE session_id=?")->execute([$rid, $sid]);
        if ($r['days']) {
            $db->prepare("DELETE FROM training_session_day WHERE session_id=?")->execute([$sid]);
            $ins = $db->prepare("INSERT INTO training_session_day (session_id, day_no, day_date, start_time, end_time, break_minutes)
                                 VALUES (?,?,?,?,?,0)");
            foreach ($r['days'] as $i => $d) $ins->execute([$sid, $i+1, $d['day_date'], $d['start_time'], $d['end_time']]);
            $first = $r['days'][0];
            $db->prepare("UPDATE training_session SET done_date=?, start_time=?, end_time=?, plan_days=? WHERE session_id=?")
               ->execute([$first['day_date'], $first['start_time'], $first['end_time'], count($r['days']), $sid]);
        }
        if ($r['trainees_list']) {
            $db->prepare("DELETE FROM training_attendee WHERE session_id=?")->execute([$sid]);
            $ins = $db->prepare("INSERT INTO training_attendee (session_id, user_id, user_name, dept_name, position_name, attended, signed)
                                 VALUES (?,?,?,?,?,0,0)");
            foreach ($r['trainees_list'] as $t) $ins->execute([$sid, $t['user_id'], $t['user_name'], $t['dept_name'], $t['position_name']]);
        }
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('轉換失敗：'.$e->getMessage(), 500); }
    jout(['days'=>count($r['days']), 'trainees'=>count($r['trainees_list'])]);
}

case 'request_delete': {
    $rid = (int)($_POST['request_id'] ?? 0);
    $r = tr_request_row($db, $rid);
    if (!$r) jerr('找不到申請單');
    if (!$perms['canAdmin'] && !((int)$r['user_id'] === $uid && $r['status'] === 'draft')) jerr('僅本人的草稿或訓練管理員可刪除', 403);
    try {
        $db->beginTransaction();
        $db->prepare("DELETE FROM training_request_day WHERE request_id=?")->execute([$rid]);
        $db->prepare("DELETE FROM training_request_trainee WHERE request_id=?")->execute([$rid]);
        $db->prepare("DELETE FROM training_request WHERE request_id=?")->execute([$rid]);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('刪除失敗：'.$e->getMessage(), 500); }
    jout([]);
}

/* 部門人員（講師/參加人員選擇用）
   排除：離職(state=0)、共用帳號(is_shared_account=1)、系統/公用身分(user_status 9/90/9999)。
   一併回傳該部門的職稱（同一人多職稱時取主要職務 is_main 優先）。
   at_date（YYYY-MM-DD，選填）：補登舊訓練紀錄用——依 user_position_history 解析「當日」誰在此部門、
   當時職稱為何（ai-rules/14；沒補登紀錄的人回現況）。此模式下已離職者若當日仍在職也會列出（標 resigned）。 */
case 'people': {
    $deptId = (int)($_GET['dept_id'] ?? 0);
    if ($deptId <= 0) jout(['people'=>[]]);
    $atDate = trim((string)($_GET['at_date'] ?? ''));
    if ($atDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $atDate) && $atDate < date('Y-m-d')) {
        require_once $document_root . '/EGsystem/src/common/position_history_lib.php';
        $snaps = eg_position_snapshot_at_bulk($db, $atDate);
        $us = $db->query("SELECT id, user_cname, state, leave_date FROM user
                          WHERE user_cname IS NOT NULL AND user_cname<>''
                            AND COALESCE(is_shared_account,0) <> 1
                            AND COALESCE(user_status,0) NOT IN (9,90,9999)")->fetchAll(PDO::FETCH_ASSOC);
        $people = [];
        foreach ($us as $u) {
            $uid2 = (int)$u['id'];
            // 已離職且離職日早於上課日＝當時已不在職，不列
            if ((int)($u['state'] ?? 1) === 0 && !empty($u['leave_date']) && $u['leave_date'] < $atDate) continue;
            $pos = '';
            $hit = false;
            foreach (($snaps[$uid2] ?? []) as $s) {
                if ((int)$s['department_id'] !== $deptId) continue;
                $hit = true;
                if ($pos === '' || $s['is_main']) $pos = $s['position_name'];   // 主職優先
                if ($s['is_main']) break;
            }
            if (!$hit) continue;
            $people[] = ['id' => $uid2, 'user_cname' => $u['user_cname'], 'position_name' => $pos,
                         'resigned' => (int)($u['state'] ?? 1) === 0 ? 1 : 0];
        }
        usort($people, fn($a, $b) => strcmp($a['user_cname'], $b['user_cname']));
        jout(['people' => $people, 'at_date' => $atDate]);
    }
    $st = $db->prepare("SELECT u.id, u.user_cname,
                               SUBSTRING_INDEX(GROUP_CONCAT(p.name ORDER BY m.is_main DESC, p.sort_order, p.id SEPARATOR '|'), '|', 1) AS position_name
                        FROM user_department_position_map m
                        JOIN user u ON u.id=m.user_id
                        LEFT JOIN position p ON p.id=m.position_id
                        WHERE m.department_id=? AND u.user_cname IS NOT NULL AND u.user_cname<>''
                          AND COALESCE(u.state,1) <> 0
                          AND COALESCE(u.is_shared_account,0) <> 1
                          AND COALESCE(u.user_status,0) NOT IN (9,90,9999)
                        GROUP BY u.id, u.user_cname
                        ORDER BY u.user_cname");
    $st->execute([$deptId]);
    jout(['people'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
}

/* 場次參加人員名單 */
case 'get_attendees': {
    $sid = (int)($_GET['session_id'] ?? 0);
    $st = $db->prepare("SELECT att_id, user_id, user_name, dept_name, position_name, attended, signed, signed_at, sign_method,
                               eval_result, eval_score, eval_note
                        FROM training_attendee WHERE session_id=? ORDER BY dept_name, user_name");
    $st->execute([$sid]);
    jout(['attendees'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
}

/* 儲存參加人員名單（整批取代；同步應到/實到人數） */
case 'save_attendees': {
    if (!$perms['canEdit']) jerr('無登錄權限', 403);
    $sid = (int)($_POST['session_id'] ?? 0);
    $st = $db->prepare("SELECT eval_method FROM training_session WHERE session_id=?");
    $st->execute([$sid]);
    $sRow = $st->fetch(PDO::FETCH_ASSOC);
    if (!$sRow) jerr('找不到場次');
    $isNotice = ($sRow['eval_method'] ?? '') === 'notice';    // 宣導＝免評鑑
    $list = json_decode((string)($_POST['attendees'] ?? '[]'), true);
    if (!is_array($list)) $list = [];
    try {
        $db->beginTransaction();
        // 保留既有簽名狀態：先讀舊資料
        $old = [];
        $oq = $db->prepare("SELECT user_id, attended, signed, signed_at, sign_method FROM training_attendee WHERE session_id=?");
        $oq->execute([$sid]);
        foreach ($oq->fetchAll(PDO::FETCH_ASSOC) as $o) $old[(int)$o['user_id']] = $o;
        $db->prepare("DELETE FROM training_attendee WHERE session_id=?")->execute([$sid]);
        $ins = $db->prepare("INSERT INTO training_attendee (session_id,user_id,user_name,dept_name,position_name,attended,signed,signed_at,sign_method,
                                                            eval_result,eval_score,eval_note)
                             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
        $total = 0; $att = 0;
        foreach ($list as $p) {
            $uidP = (int)($p['user_id'] ?? 0);
            if ($uidP <= 0) continue;                      // 一律綁 user.id，沒有 id 的不寫入
            $attended = (int)($p['attended'] ?? 0) === 1 ? 1 : 0;
            $o = $old[$uidP] ?? null;
            $ev = (string)($p['eval_result'] ?? '');
            if ($isNotice) $ev = 'exempt';                  // 宣導課程一律免評鑑
            elseif (!in_array($ev, ['pass','fail','exempt'], true)) $ev = '';
            $sc = ($p['eval_score'] ?? '') === '' || $p['eval_score'] === null ? null : (float)$p['eval_score'];
            if ($sc !== null && ($sc < 0 || $sc > 100)) jerr('評分需在 0~100 之間（'.trim((string)($p['user_name'] ?? '')).'）');
            $ins->execute([$sid, $uidP, trim((string)($p['user_name'] ?? '')) ?: null,
                trim((string)($p['dept_name'] ?? '')) ?: null,
                trim((string)($p['position_name'] ?? '')) ?: null, $attended,
                $o ? (int)$o['signed'] : 0, $o ? $o['signed_at'] : null, $o ? $o['sign_method'] : null,
                $ev === '' ? null : $ev, $sc, trim((string)($p['eval_note'] ?? '')) ?: null]);
            $total++; if ($attended) $att++;
        }
        // 同步人數（有名單即以名單為準）
        $db->prepare("UPDATE training_session SET target_headcount=?, actual_headcount=? WHERE session_id=?")
           ->execute([$total, $att, $sid]);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('儲存名單失敗：'.$e->getMessage(), 500); }
    // 行事曆事件的「發生者」＝內訓：講師＋參加人員／外訓：參加人員 → 名單改了要重寫一次
    $ev = training_event_sync($db, $sid);
    jout(['total'=>$total, 'events'=>$ev]);
}

/* 某位員工的受訓紀錄（供其他頁面查詢：員工資料、稽核佐證…）
   一律以 user.id 查（training_attendee.user_id），本人可查自己，其餘需檢閱權。 */
case 'user_history': {
    $target = (int)($_GET['user_id'] ?? 0);
    if ($target <= 0) jerr('請指定 user_id');
    if ($target !== $uid && !$perms['canView']) jerr('無查詢權限', 403);
    $year = ($_GET['year'] ?? '') === '' ? null : (int)$_GET['year'];
    jout(['user_id'=>$target, 'records'=>training_user_history($db, $target, $year)]);
}

/* 複製場次（內容複製、不帶參加名單；狀態回計畫中） */
case 'copy_session': {
    if (!$perms['canEdit']) jerr('無登錄權限', 403);
    $sid = (int)($_POST['session_id'] ?? 0);
    $st = $db->prepare("SELECT * FROM training_session WHERE session_id=?");
    $st->execute([$sid]);
    $s = $st->fetch(PDO::FETCH_ASSOC);
    if (!$s) jerr('找不到場次');
    try {
        $db->beginTransaction();
        $db->prepare("INSERT INTO training_session
            (year,plan_month,dept_id,course_name,train_type,trainer,trainer_id,org_unit,hours,plan_days,status,note,outline,eval_method,created_by,created_by_name)
            VALUES (?,?,?,?,?,?,?,?,?,?, 'planned', ?, ?, ?, ?, ?)")
           ->execute([$s['year'],$s['plan_month'],$s['dept_id'],$s['course_name'],$s['train_type'],$s['trainer'],
                      $s['trainer_id'],$s['org_unit'],$s['hours'],$s['plan_days'],$s['note'],
                      $s['outline'] ?? null, $s['eval_method'] ?? null, $uid,$uname]);
        $newId = (int)$db->lastInsertId();
        training_save_session_depts($db, $newId, training_session_depts($db, [$sid])[$sid] ?? []);   // 對象部門一併複製
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('複製失敗：'.$e->getMessage(), 500); }
    jout(['session_id'=>$newId]);
}

/* 刪除場次（管理員） */
case 'delete_session': {
    if (!$perms['canAdmin']) jerr('無刪除權限', 403);
    $sid = (int)($_POST['session_id'] ?? 0);
    $st = $db->prepare("SELECT 1 FROM training_session WHERE session_id=?");
    $st->execute([$sid]);
    if (!$st->fetchColumn()) jerr('找不到場次');
    // 附件實體檔先收集，DB 刪成功後才刪檔（避免交易失敗卻已刪檔）
    $attFiles = array_map(fn($a) => $a['file_name'], training_attachments($db, $sid));
    try {
        $db->beginTransaction();
        training_event_remove($db, $sid);      // 連帶撤掉行事曆事件
        $db->prepare("DELETE FROM training_attachment WHERE session_id=?")->execute([$sid]);
        $db->prepare("DELETE FROM training_attendee WHERE session_id=?")->execute([$sid]);
        $db->prepare("DELETE FROM training_session_day WHERE session_id=?")->execute([$sid]);
        $db->prepare("DELETE FROM training_session_dept WHERE session_id=?")->execute([$sid]);
        $db->prepare("DELETE FROM training_session WHERE session_id=?")->execute([$sid]);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('刪除失敗：'.$e->getMessage(), 500); }
    if ($attFiles) {
        $nasDir = training_attach_dir($db);
        foreach ($attFiles as $fn) { $fp = $nasDir . $fn; if (is_file($fp)) @unlink($fp); }
    }
    jout([]);
}

default:
    jerr('未知動作：'.$action);
}
