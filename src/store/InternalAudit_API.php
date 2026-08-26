<?php
/**
 * 內部稽核（2-GM-06）API
 * 權限：internal_audit_lib.php ia_perms()（roles module='internal_audit'）
 *   ia_admin   內稽管理員（管理代表）：全部
 *   ia_auditor 稽核員：查檢表、開 IA 單、驗證
 *   ia_view    檢閱：唯讀
 *   其餘在職員工：只能回覆自己單位的 IA 單（逐單由 ia_nc_stage_perm() 判定）
 * 前端擋一次、後端同規則再擋一次（鐵律8），不留只擋 UI 的漏洞。
 */
$document_root = $_SERVER['DOCUMENT_ROOT'];
session_start();
require_once __DIR__ . '/../common/api_guard.php';
header('Content-Type: application/json; charset=utf-8');
include_once $document_root . '/EGsystem/src/common/_config.php';
include_once $document_root . '/EGsystem/src/common/DBConnection.php';
include_once $document_root . '/EGsystem/src/common/internal_audit_lib.php';
include_once $document_root . '/EGsystem/src/common/date_fmt_lib.php';
include_once $document_root . '/EGsystem/src/common/print_log_lib.php';

function jout($a) { echo json_encode(array_merge(['ok' => true], $a), JSON_UNESCAPED_UNICODE); exit; }
function jerr($msg, $code = 400) { http_response_code($code); echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE); exit; }

try {
    $db = (new DBConnection())->getPDO();
    ia_ensure_schema($db);
} catch (Throwable $e) { jerr('DB連線失敗：' . $e->getMessage(), 500); }

$u = ia_current_user($db);
if (!$u) jerr('未登入', 401);
$uid   = (int)$u['id'];
$uname = (string)$u['user_cname'];
$perms = ia_perms($db, $u);
if (!$perms['uid']) jerr('無內部稽核使用權限（帳號非在職狀態）', 403);

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$today  = ia_today($db);

/** 到期提醒順路觸發：不另開排程，有人用這個模組就順便檢查一次（內部 static 擋重複、每單每天最多一則） */
ia_nc_remind_tick($db);

function iaReqAdmin(array $perms) { if (!$perms['canAdmin']) jerr('需要內稽管理員權限', 403); }
function iaReqAudit(array $perms) { if (!$perms['canAudit']) jerr('需要稽核員權限', 403); }
function iaReqView(array $perms)  { if (!$perms['canView'])  jerr('無檢視權限', 403); }

/** 日期字串驗證（空字串一律回 null，不寫入 0000-00-00） */
function iaDate($v): ?string
{
    $v = trim((string)$v);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
}
function iaTime($v): ?string
{
    $v = trim((string)$v);
    if ($v === '') return null;
    if (preg_match('/^(\d{1,2}):(\d{2})$/', $v, $m)) {
        $h = (int)$m[1]; $i = (int)$m[2];
        if ($h >= 0 && $h <= 23 && $i >= 0 && $i <= 59) return sprintf('%02d:%02d', $h, $i);
    }
    if (preg_match('/^(\d{1,2})(\d{2})$/', $v, $m)) {
        $h = (int)$m[1]; $i = (int)$m[2];
        if ($h >= 0 && $h <= 23 && $i >= 0 && $i <= 59) return sprintf('%02d:%02d', $h, $i);
    }
    if (preg_match('/^\d{1,2}$/', $v)) { $h = (int)$v; if ($h >= 0 && $h <= 23) return sprintf('%02d:00', $h); }
    return null;
}
function iaInt($v): ?int { $v = trim((string)$v); return ($v === '' || !ctype_digit(ltrim($v, '-'))) ? null : (int)$v; }

switch ($action) {

/* ============================ meta ============================ */
case 'meta': {
    $set   = ia_settings($db);
    $depts = [];
    try { $depts = $db->query("SELECT id, name, parent_id, level FROM department ORDER BY sort_order, id")
                      ->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
    $people = [];
    try { $people = eg_people_list($db, []); } catch (Throwable $e) {}

    // 七份表單各自的 AS 綁定（表頭名稱與頁尾編號都由綁定推導，不寫死）
    $asDocs = [];
    foreach (IA_ASDOC_MODULES as $k => $m) {
        $doc = eg_asdoc_get($db, $m['module']);
        $asDocs[$k] = [
            'label'    => $m['label'],
            'module'   => $m['module'],
            'doc_id'   => (int)($doc['id'] ?? 0),
            'doc_no'   => $doc ? eg_asdoc_no($doc) : $m['fallback'],
            'doc_name' => $doc['doc_name'] ?? $m['label'],
            'bound'    => (bool)$doc,
        ];
    }
    $stampTpls = [];
    if ($perms['canAdmin']) {
        try {
            $stampTpls = $db->query("SELECT p.id, p.tpl_name, t.type_name FROM stamp_template p
                                     LEFT JOIN stamp_type t ON t.id=p.type_id
                                     WHERE p.is_active=1 ORDER BY p.tpl_name")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {}
    }
    // 年度選單含近十年（管理員要補以前年度的資料，選單裡就得選得到）
    $years = ia_year_options($db);
    $cy    = (int)substr($today, 0, 4);

    jout([
        'me'        => ['id' => $uid, 'name' => $uname] + ia_identity_asof($db, $uid, $today),
        'perms'     => $perms,
        'role_label'=> ia_role_label($perms),
        'settings'  => $set,
        'sign_sources' => IA_SIGN_SOURCES,
        'nc_types'  => IA_NC_TYPES,
        'nc_stages' => IA_NC_STAGES,
        'check_kinds' => IA_CHECK_KINDS,
        'as_docs'   => $asDocs,
        'as_doc_list' => $perms['canAdmin'] ? eg_asdoc_list($db) : [],
        'company'   => eg_company_full_name($db),
        'depts'     => $depts,
        'people'    => $people,
        // 受稽單位＝群組（如 生產部＋生產1/2/3廠）＋沒被群組收編的單一部門
        'units'     => ia_audit_units($db),
        // 稽核員／陪檢員只列有資格的人；名單沒設定時回全體在職員工
        'auditors'  => ia_qualified_people($db, 'auditor'),
        'escorts'   => ia_qualified_people($db, 'escort'),
        'qualify_kinds' => IA_QUALIFY_KINDS,
        'stamp_tpls'=> $stampTpls,
        'years'     => $years,
        'this_year' => $cy,
        'today'     => $today,
    ]);
}

/* ============================ 設定 ============================ */
case 'save_setting': {
    iaReqAdmin($perms);
    $k = (string)($_POST['key'] ?? '');
    if (!in_array($k, IA_SETTING_KEYS, true)) jerr('不支援的設定項目');
    $v = (string)($_POST['value'] ?? '');
    if ($k === 'ia_remind_days') {
        if ($v !== '' && (!ctype_digit($v) || (int)$v > 365)) jerr('提醒天數請填 0~365 的整數');
    }
    if (in_array($k, ['ia_sign_approve', 'ia_sign_review'], true) && !array_key_exists($v, IA_SIGN_SOURCES)) {
        jerr('不支援的簽章來源');
    }
    if ($k === 'ia_stamp_tpl_id' && $v !== '') {
        $st = $db->prepare("SELECT 1 FROM stamp_template WHERE id=? AND is_active=1");
        $st->execute([(int)$v]);
        if (!$st->fetchColumn()) jerr('圖章模板不存在或已停用');
    }
    // 存不進去一定要讓使用者看到；回報成功卻沒存＝使用者會一直重存卻永遠是空的
    try { ia_setting_save($db, $k, $v, $uname); }
    catch (Throwable $e) { jerr('設定儲存失敗：' . $e->getMessage(), 500); }
    // 立刻讀回來確認真的寫進去了（寫入成功但值被資料庫改寫的情況也擋得住）
    $back = ia_settings($db);
    if (($back[$k] ?? null) !== $v) jerr('設定寫入後讀回的值不一致，請回報系統管理者', 500);
    jout(['saved' => true, 'value' => $back[$k]]);
}

case 'save_asdoc': {
    iaReqAdmin($perms);
    $key = (string)($_POST['key'] ?? '');
    if (!isset(IA_ASDOC_MODULES[$key])) jerr('不支援的表單代碼');
    $docId = (int)($_POST['doc_id'] ?? 0);
    if ($docId) {
        $st = $db->prepare("SELECT 1 FROM as_document WHERE id=?");
        $st->execute([$docId]);
        if (!$st->fetchColumn()) jerr('AS 文件不存在');
    }
    eg_asdoc_save($db, IA_ASDOC_MODULES[$key]['module'], $docId, $uname);
    jout(['saved' => true]);
}

/* ============================ 年度計劃表 2-GM-06-01 ============================ */
case 'plan_get': {
    iaReqView($perms);
    $year = (int)($_GET['year'] ?? substr($today, 0, 4));
    $plan = ia_plan_get($db, $year);
    if (!$plan) jout(['plan' => null, 'year' => $year]);
    // ★ cells／actual 一定要轉成物件再送出去：PHP 的**空**關聯陣列 json_encode 出來是 `[]`（JS 的陣列）
    //   不是 `{}`；前端在陣列上加字串鍵（'6-11'）之後，用 $.each 之類的走訪拿不到那些鍵，
    //   結果就是「點了格子、按了儲存，送出的卻是空清單」——存進去是空的而且完全不報錯
    //   （2026-08-25 使用者回報「很多儲存按鈕按了沒真的存」的根因之一）。
    //   轉型只在輸出這一步做，內部（dashboard 會 count()／foreach）仍是陣列。
    $plan['cells']  = (object)$plan['cells'];
    $plan['actual'] = (object)$plan['actual'];
    jout(['plan' => $plan, 'year' => $year]);
}

case 'plan_create': {
    iaReqAdmin($perms);
    $year = (int)($_POST['year'] ?? 0);
    if ($year < 2000 || $year > 2200) jerr('年度不正確');
    $st = $db->prepare("SELECT plan_id FROM ia_plan WHERE year=? AND COALESCE(is_deleted,0)=0");
    $st->execute([$year]);
    if ($st->fetchColumn()) jerr('該年度的稽核計劃表已存在');

    $deptIds = json_decode((string)($_POST['dept_ids'] ?? '[]'), true);
    if (!is_array($deptIds) || !$deptIds) jerr('請至少選一個受稽單位');

    $db->beginTransaction();
    try {
        $db->prepare("INSERT INTO ia_plan (year, status, created_by, created_by_name, created_at, updated_at,
                          maker_id, maker_name, maker_date)
                      VALUES (?, 'draft', ?, ?, NOW(), NOW(), ?, ?, ?)")
           ->execute([$year, $uid, $uname, $uid, $uname, $today]);
        $pid = (int)$db->lastInsertId();
        $ins = $db->prepare("INSERT INTO ia_plan_dept (plan_id, dept_id, dept_name, sort_order) VALUES (?,?,?,?)");
        $nameSt = $db->prepare("SELECT name FROM department WHERE id=?");
        $i = 0;
        foreach ($deptIds as $d) {
            $d = (int)$d; if (!$d) continue;
            $nameSt->execute([$d]);
            $ins->execute([$pid, $d, (string)($nameSt->fetchColumn() ?: ''), ++$i * 10]);
        }
        $db->commit();
        jout(['plan_id' => $pid]);
    } catch (Throwable $e) { $db->rollBack(); jerr('建立失敗：' . $e->getMessage(), 500); }
}

case 'plan_save_cells': {
    iaReqAdmin($perms);
    $pid = (int)($_POST['plan_id'] ?? 0);
    $st = $db->prepare("SELECT * FROM ia_plan WHERE plan_id=? AND COALESCE(is_deleted,0)=0");
    $st->execute([$pid]);
    $plan = $st->fetch(PDO::FETCH_ASSOC);
    if (!$plan) jerr('找不到這張計劃表');
    if ($plan['status'] === 'approved' && !$perms['isAdmin']) jerr('已核准的計劃表不可修改，請洽系統管理者');

    $cells = json_decode((string)($_POST['cells'] ?? '[]'), true);
    if (!is_array($cells)) jerr('格式錯誤');
    $db->beginTransaction();
    try {
        $db->prepare("DELETE FROM ia_plan_cell WHERE plan_id=?")->execute([$pid]);
        $ins = $db->prepare("INSERT INTO ia_plan_cell (plan_id, dept_id, month, planned, note) VALUES (?,?,?,1,?)");
        foreach ($cells as $c) {
            $d = (int)($c['dept_id'] ?? 0); $m = (int)($c['month'] ?? 0);
            if (!$d || $m < 1 || $m > 12) continue;
            $ins->execute([$pid, $d, $m, isset($c['note']) && $c['note'] !== '' ? mb_substr((string)$c['note'], 0, 200) : null]);
        }
        $db->prepare("UPDATE ia_plan SET remark=?, updated_at=NOW() WHERE plan_id=?")
           ->execute([mb_substr(trim((string)($_POST['remark'] ?? '')), 0, 500) ?: null, $pid]);
        $db->commit();
        jout(['saved' => true]);
    } catch (Throwable $e) { $db->rollBack(); jerr('儲存失敗：' . $e->getMessage(), 500); }
}

case 'plan_set_depts': {
    iaReqAdmin($perms);
    $pid = (int)($_POST['plan_id'] ?? 0);
    $st = $db->prepare("SELECT plan_id, status FROM ia_plan WHERE plan_id=? AND COALESCE(is_deleted,0)=0");
    $st->execute([$pid]);
    if (!$st->fetch(PDO::FETCH_ASSOC)) jerr('找不到這張計劃表');
    $deptIds = json_decode((string)($_POST['dept_ids'] ?? '[]'), true);
    if (!is_array($deptIds) || !$deptIds) jerr('請至少選一個受稽單位');
    $keep = array_values(array_filter(array_map('intval', $deptIds)));
    $db->beginTransaction();
    try {
        $in = implode(',', array_fill(0, count($keep), '?'));
        // 移除的單位連同它的格子一起清掉，才不會留下看不到卻仍在算的資料
        $db->prepare("DELETE FROM ia_plan_dept WHERE plan_id=? AND dept_id NOT IN ($in)")
           ->execute(array_merge([$pid], $keep));
        $db->prepare("DELETE FROM ia_plan_cell WHERE plan_id=? AND dept_id NOT IN ($in)")
           ->execute(array_merge([$pid], $keep));
        $ins = $db->prepare("INSERT IGNORE INTO ia_plan_dept (plan_id, dept_id, dept_name, sort_order) VALUES (?,?,?,?)");
        $upd = $db->prepare("UPDATE ia_plan_dept SET sort_order=? WHERE plan_id=? AND dept_id=?");
        $nameSt = $db->prepare("SELECT name FROM department WHERE id=?");
        $i = 0;
        foreach ($keep as $d) {
            $i += 10;
            $nameSt->execute([$d]);
            $ins->execute([$pid, $d, (string)($nameSt->fetchColumn() ?: ''), $i]);
            $upd->execute([$i, $pid, $d]);
        }
        $db->prepare("UPDATE ia_plan SET updated_at=NOW() WHERE plan_id=?")->execute([$pid]);
        $db->commit();
        jout(['saved' => true]);
    } catch (Throwable $e) { $db->rollBack(); jerr('儲存失敗：' . $e->getMessage(), 500); }
}

case 'plan_decide': {
    iaReqAdmin($perms);
    $pid = (int)($_POST['plan_id'] ?? 0);
    $st = $db->prepare("SELECT * FROM ia_plan WHERE plan_id=? AND COALESCE(is_deleted,0)=0");
    $st->execute([$pid]);
    $plan = $st->fetch(PDO::FETCH_ASSOC);
    if (!$plan) jerr('找不到這張計劃表');
    $to = (string)($_POST['status'] ?? '');
    if (!in_array($to, ['draft', 'submitted', 'approved'], true)) jerr('狀態不正確');
    $d = iaDate($_POST['biz_date'] ?? '') ?: $today;
    if ($to === 'submitted') {
        $db->prepare("UPDATE ia_plan SET status='submitted', submit_date=?, submitted_at=NOW(),
                          reviewer_id=?, reviewer_name=?, reviewer_date=?, updated_at=NOW() WHERE plan_id=?")
           ->execute([$d, $uid, $uname, $d, $pid]);
    } elseif ($to === 'approved') {
        $db->prepare("UPDATE ia_plan SET status='approved', approved_date=?, approved_at=NOW(),
                          approver_id=?, approver_name=?, approver_date=?, decide_note=?, updated_at=NOW() WHERE plan_id=?")
           ->execute([$d, $uid, $uname, $d, mb_substr(trim((string)($_POST['note'] ?? '')), 0, 500) ?: null, $pid]);
    } else {
        $db->prepare("UPDATE ia_plan SET status='draft', updated_at=NOW() WHERE plan_id=?")->execute([$pid]);
    }
    jout(['saved' => true]);
}

case 'plan_delete': {
    iaReqAdmin($perms);
    $pid = (int)($_POST['plan_id'] ?? 0);
    $db->prepare("UPDATE ia_plan SET is_deleted=1, updated_at=NOW() WHERE plan_id=?")->execute([$pid]);
    jout(['deleted' => true]);
}

/* ============================ 稽核通知單 2-GM-06-02 ============================ */
case 'case_list': {
    iaReqView($perms);
    $w = ['COALESCE(c.is_deleted,0)=0']; $p = [];
    $year = iaInt($_GET['year'] ?? '');
    if ($year) { $w[] = 'c.year=?'; $p[] = $year; }
    $status = (string)($_GET['status'] ?? '');
    if ($status !== '') { $w[] = 'c.status=?'; $p[] = $status; }
    $kw = trim((string)($_GET['kw'] ?? ''));
    if ($kw !== '') {
        foreach (preg_split('/\s+/', $kw) as $k) {
            if ($k === '') continue;
            $w[] = "(c.case_no LIKE ? OR c.leader_name LIKE ? OR c.remark LIKE ? OR c.end_meet_place LIKE ?
                     OR EXISTS (SELECT 1 FROM ia_case_dept x WHERE x.case_id=c.case_id
                        AND (x.dept_name LIKE ? OR x.auditor_name LIKE ? OR x.escort_name LIKE ? OR x.start_process LIKE ?)))";
            for ($i = 0; $i < 8; $i++) $p[] = '%' . $k . '%';
        }
    }
    $sql = "SELECT c.*,
                   (SELECT GROUP_CONCAT(x.dept_name ORDER BY x.sort_order SEPARATOR '、')
                      FROM ia_case_dept x WHERE x.case_id=c.case_id) AS dept_list,
                   (SELECT COUNT(*) FROM ia_nc n WHERE n.case_id=c.case_id AND COALESCE(n.is_deleted,0)=0) AS nc_cnt,
                   (SELECT COUNT(*) FROM ia_check k WHERE k.case_id=c.case_id AND COALESCE(k.is_deleted,0)=0) AS check_cnt
              FROM ia_case c WHERE " . implode(' AND ', $w) . " ORDER BY c.year DESC, c.seq_no DESC, c.case_id DESC";
    $st = $db->prepare($sql); $st->execute($p);
    jout(['rows' => $st->fetchAll(PDO::FETCH_ASSOC)]);
}

case 'case_get': {
    iaReqView($perms);
    $cid = (int)($_GET['case_id'] ?? 0);
    $st = $db->prepare("SELECT * FROM ia_case WHERE case_id=? AND COALESCE(is_deleted,0)=0");
    $st->execute([$cid]);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if (!$c) jerr('找不到這張稽核通知單', 404);
    $st = $db->prepare("SELECT * FROM ia_case_dept WHERE case_id=? ORDER BY sort_order, cd_id");
    $st->execute([$cid]);
    $c['depts'] = $st->fetchAll(PDO::FETCH_ASSOC);
    $st = $db->prepare("SELECT check_id, kind, half, title, auditor_name, check_date, status
                          FROM ia_check WHERE case_id=? AND COALESCE(is_deleted,0)=0 ORDER BY check_id");
    $st->execute([$cid]);
    $c['checks'] = $st->fetchAll(PDO::FETCH_ASSOC);
    $st = $db->prepare("SELECT nc_id, nc_no, dept_name, nc_type, stage, due_date, fact
                          FROM ia_nc WHERE case_id=? AND COALESCE(is_deleted,0)=0 ORDER BY nc_no");
    $st->execute([$cid]);
    $c['ncs'] = $st->fetchAll(PDO::FETCH_ASSOC);
    // 會議紀錄狀態（不重複建立，只顯示連結）
    foreach ([['pre_meeting_id', 'pre'], ['end_meeting_id', 'end']] as $mm) {
        $mid = (int)($c[$mm[0]] ?? 0);
        $c[$mm[1] . '_meeting'] = null;
        if ($mid) {
            try {
                $q = $db->prepare("SELECT meeting_id, subject, meeting_date, status FROM meeting_record WHERE meeting_id=?");
                $q->execute([$mid]);
                $c[$mm[1] . '_meeting'] = $q->fetch(PDO::FETCH_ASSOC) ?: null;
            } catch (Throwable $e) {}
        }
    }
    jout(['row' => $c]);
}

case 'case_save': {
    iaReqAdmin($perms);
    $cid  = (int)($_POST['case_id'] ?? 0);
    $nd   = iaDate($_POST['notify_date'] ?? '');
    if (!$nd) jerr('請填通知日期');
    $af = iaDate($_POST['audit_from'] ?? '');
    $at = iaDate($_POST['audit_to'] ?? '');
    if ($af && $at && $at < $af) jerr('稽核結束日期不可早於開始日期');
    $ems = iaTime($_POST['end_meet_start'] ?? '');
    $eme = iaTime($_POST['end_meet_end'] ?? '');
    if ($ems && $eme && $eme < $ems) jerr('結束會議的結束時間不可早於開始時間');

    $year   = (int)substr($nd, 0, 4);
    $leader = iaInt($_POST['leader_id'] ?? '');
    $leaderName = '';
    if ($leader) {
        $q = $db->prepare("SELECT user_cname FROM `user` WHERE id=?"); $q->execute([$leader]);
        $leaderName = (string)($q->fetchColumn() ?: '');
        if ($leaderName === '') jerr('稽核組長不存在');
    }
    $depts = json_decode((string)($_POST['depts'] ?? '[]'), true);
    if (!is_array($depts)) $depts = [];

    $db->beginTransaction();
    try {
        if ($cid) {
            $q = $db->prepare("SELECT * FROM ia_case WHERE case_id=? AND COALESCE(is_deleted,0)=0");
            $q->execute([$cid]);
            $old = $q->fetch(PDO::FETCH_ASSOC);
            if (!$old) { $db->rollBack(); jerr('找不到這張稽核通知單', 404); }
            $db->prepare("UPDATE ia_case SET year=?, notify_date=?, audit_from=?, audit_to=?,
                              leader_id=?, leader_name=?, end_meet_date=?, end_meet_start=?, end_meet_end=?,
                              end_meet_place=?, remark=?, updated_at=NOW() WHERE case_id=?")
               ->execute([$year, $nd, $af, $at, $leader, $leaderName ?: null,
                          iaDate($_POST['end_meet_date'] ?? ''), $ems, $eme,
                          mb_substr(trim((string)($_POST['end_meet_place'] ?? '')), 0, 150) ?: null,
                          trim((string)($_POST['remark'] ?? '')) ?: null, $cid]);
        } else {
            $q = $db->prepare("SELECT COALESCE(MAX(seq_no),0)+1 FROM ia_case WHERE year=? AND COALESCE(is_deleted,0)=0");
            $q->execute([$year]);
            $seq = (int)$q->fetchColumn();
            $caseNo = ia_next_case_no($db, $nd);
            $db->prepare("INSERT INTO ia_case (year, seq_no, case_no, notify_date, audit_from, audit_to,
                              leader_id, leader_name, end_meet_date, end_meet_start, end_meet_end, end_meet_place,
                              remark, status, maker_id, maker_name, maker_date, created_by, created_by_name,
                              created_at, updated_at)
                          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?, 'draft', ?,?,?,?,?, NOW(), NOW())")
               ->execute([$year, $seq, $caseNo, $nd, $af, $at, $leader, $leaderName ?: null,
                          iaDate($_POST['end_meet_date'] ?? ''), $ems, $eme,
                          mb_substr(trim((string)($_POST['end_meet_place'] ?? '')), 0, 150) ?: null,
                          trim((string)($_POST['remark'] ?? '')) ?: null,
                          $uid, $uname, $nd, $uid, $uname]);
            $cid = (int)$db->lastInsertId();
        }

        $db->prepare("DELETE FROM ia_case_dept WHERE case_id=?")->execute([$cid]);
        $ins = $db->prepare("INSERT INTO ia_case_dept (case_id, sort_order, start_process, dept_id, dept_name,
                                 auditor_id, auditor_name, escort_id, escort_name, audited_date, audited_time, improve_due)
                             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
        $nameSt = $db->prepare("SELECT name FROM department WHERE id=?");
        $userSt = $db->prepare("SELECT user_cname FROM `user` WHERE id=?");
        $i = 0;
        foreach ($depts as $d) {
            $did = iaInt($d['dept_id'] ?? '');
            $dn  = trim((string)($d['dept_name'] ?? ''));
            if ($did) { $nameSt->execute([$did]); $dn = (string)($nameSt->fetchColumn() ?: $dn); }
            if (!$did && $dn === '' && trim((string)($d['start_process'] ?? '')) === '') continue;
            $aid = iaInt($d['auditor_id'] ?? ''); $an = '';
            if ($aid) { $userSt->execute([$aid]); $an = (string)($userSt->fetchColumn() ?: ''); }
            $eid = iaInt($d['escort_id'] ?? '');  $en = '';
            if ($eid) { $userSt->execute([$eid]); $en = (string)($userSt->fetchColumn() ?: ''); }
            $ins->execute([$cid, ++$i * 10, mb_substr(trim((string)($d['start_process'] ?? '')), 0, 150) ?: null,
                           $did, $dn ?: null, $aid, $an ?: null, $eid, $en ?: null,
                           iaDate($d['audited_date'] ?? ''), iaTime($d['audited_time'] ?? ''),
                           iaDate($d['improve_due'] ?? '')]);
        }
        $db->commit();
        jout(['case_id' => $cid]);
    } catch (Throwable $e) { $db->rollBack(); jerr('儲存失敗：' . $e->getMessage(), 500); }
}

case 'case_status': {
    iaReqAdmin($perms);
    $cid = (int)($_POST['case_id'] ?? 0);
    $to  = (string)($_POST['status'] ?? '');
    if (!in_array($to, ['draft', 'issued', 'executing', 'closed'], true)) jerr('狀態不正確');
    $q = $db->prepare("SELECT * FROM ia_case WHERE case_id=? AND COALESCE(is_deleted,0)=0");
    $q->execute([$cid]);
    $c = $q->fetch(PDO::FETCH_ASSOC);
    if (!$c) jerr('找不到這張稽核通知單', 404);
    // 「實際實施」＝執行中或已結案；年度計劃表的◎就是看這個旗標
    $executed = in_array($to, ['executing', 'closed'], true) ? 1 : 0;
    $edate = $executed ? (iaDate($_POST['executed_date'] ?? '') ?: ($c['audit_from'] ?: $c['notify_date'])) : null;
    $db->prepare("UPDATE ia_case SET status=?, executed=?, executed_date=?, updated_at=NOW() WHERE case_id=?")
       ->execute([$to, $executed, $edate, $cid]);
    jout(['saved' => true]);
}

case 'case_delete': {
    iaReqAdmin($perms);
    $cid = (int)($_POST['case_id'] ?? 0);
    $q = $db->prepare("SELECT COUNT(*) FROM ia_nc WHERE case_id=? AND COALESCE(is_deleted,0)=0");
    $q->execute([$cid]);
    if ((int)$q->fetchColumn() > 0) jerr('這張通知單底下還有不符合通知單，請先處理');
    $db->prepare("UPDATE ia_case SET is_deleted=1, updated_at=NOW() WHERE case_id=?")->execute([$cid]);
    $db->prepare("UPDATE ia_check SET is_deleted=1 WHERE case_id=?")->execute([$cid]);
    jout(['deleted' => true]);
}

/* ============================ 查檢表（AS／系統／績效） ============================ */
case 'check_bank': {
    // 建立查檢表前先看題庫，讓使用者勾要查哪幾項
    iaReqAudit($perms);
    $kind = (string)($_GET['kind'] ?? '');
    if (!isset(IA_CHECK_KINDS[$kind])) jerr('查檢表種類不正確');
    $year = (int)($_GET['year'] ?? substr($today, 0, 4));
    if     ($kind === 'as')     jout(['kind' => $kind, 'rows' => ia_as_clauses($db)]);
    elseif ($kind === 'system') jout(['kind' => $kind, 'rows' => ia_system_forms($db)]);
    else                        jout(['kind' => $kind, 'rows' => ia_kpi_indicators($db, $year)]);
}

case 'check_list': {
    iaReqView($perms);
    $w = ['COALESCE(k.is_deleted,0)=0']; $p = [];
    $year = iaInt($_GET['year'] ?? '');
    if ($year) { $w[] = 'k.year=?'; $p[] = $year; }
    $kind = (string)($_GET['kind'] ?? '');
    if ($kind !== '' && isset(IA_CHECK_KINDS[$kind])) { $w[] = 'k.kind=?'; $p[] = $kind; }
    $cid = iaInt($_GET['case_id'] ?? '');
    if ($cid) { $w[] = 'k.case_id=?'; $p[] = $cid; }
    $kw = trim((string)($_GET['kw'] ?? ''));
    if ($kw !== '') {
        foreach (preg_split('/\s+/', $kw) as $k) {
            if ($k === '') continue;
            $w[] = "(k.title LIKE ? OR k.auditor_name LIKE ?
                     OR EXISTS (SELECT 1 FROM ia_check_item x WHERE x.check_id=k.check_id
                        AND (x.col_a LIKE ? OR x.col_b LIKE ? OR x.col_c LIKE ? OR x.evidence LIKE ? OR x.remark LIKE ?)))";
            for ($i = 0; $i < 7; $i++) $p[] = '%' . $k . '%';
        }
    }
    $sql = "SELECT k.*, c.case_no,
                   (SELECT COUNT(*) FROM ia_check_item i WHERE i.check_id=k.check_id AND i.is_header=0) AS item_cnt,
                   (SELECT COUNT(*) FROM ia_check_item i WHERE i.check_id=k.check_id AND i.result='ng')    AS ng_cnt,
                   (SELECT COUNT(*) FROM ia_check_item i WHERE i.check_id=k.check_id AND i.is_header=0
                                                           AND (i.result IS NULL OR i.result='')) AS todo_cnt
              FROM ia_check k LEFT JOIN ia_case c ON c.case_id=k.case_id
             WHERE " . implode(' AND ', $w) . " ORDER BY k.year DESC, k.check_date DESC, k.check_id DESC";
    $st = $db->prepare($sql); $st->execute($p);
    jout(['rows' => $st->fetchAll(PDO::FETCH_ASSOC)]);
}

case 'check_create': {
    iaReqAudit($perms);
    $kind = (string)($_POST['kind'] ?? '');
    if (!isset(IA_CHECK_KINDS[$kind])) jerr('查檢表種類不正確');
    $cd = iaDate($_POST['check_date'] ?? '');
    if (!$cd) jerr('請填稽核日期');
    $year = (int)substr($cd, 0, 4);
    $half = (string)($_POST['half'] ?? '');
    if ($kind === 'kpi' && !in_array($half, ['H1', 'H2'], true)) jerr('績效執行稽核查檢表請選上／下半年度');
    $caseId = iaInt($_POST['case_id'] ?? '');
    if ($caseId) {
        $q = $db->prepare("SELECT 1 FROM ia_case WHERE case_id=? AND COALESCE(is_deleted,0)=0");
        $q->execute([$caseId]);
        if (!$q->fetchColumn()) jerr('稽核案件不存在');
    }
    // 注意：ia_check_build_items() 把「空的 pick」當成不篩選＝整份題庫全帶。
    // 所以空陣列一定要在這裡先擋掉，否則直打 API 送 pick=[] 會安靜地建出一張 71 題的全表（前端擋得住、後端也要擋＝鐵律8）。
    $pick = json_decode((string)($_POST['pick'] ?? '[]'), true);
    $pick = is_array($pick) ? array_values(array_filter(array_map('intval', $pick))) : [];
    if (!$pick) jerr('請至少勾選一個要查核的項目');
    $items = ia_check_build_items($db, $kind, $year, $pick);
    // 只勾到章節標題列也算沒勾（會建出一張只有標題沒有題目的空表）
    $real = 0; foreach ($items as $it) { if (!$it['is_header']) $real++; }
    if ($real === 0) jerr('請至少勾選一個要查核的項目（目前只勾到章節標題列）');

    $auditorId = iaInt($_POST['auditor_id'] ?? '') ?: $uid;
    $q = $db->prepare("SELECT user_cname FROM `user` WHERE id=?"); $q->execute([$auditorId]);
    $auditorName = (string)($q->fetchColumn() ?: $uname);

    $db->beginTransaction();
    try {
        $db->prepare("INSERT INTO ia_check (case_id, year, kind, half, title, auditor_id, auditor_name,
                          check_date, status, created_by, created_by_name, created_at, updated_at)
                      VALUES (?,?,?,?,?,?,?,?, 'draft', ?,?, NOW(), NOW())")
           ->execute([$caseId, $year, $kind, $kind === 'kpi' ? $half : null,
                      mb_substr(trim((string)($_POST['title'] ?? '')), 0, 150) ?: null,
                      $auditorId, $auditorName, $cd, $uid, $uname]);
        $kid = (int)$db->lastInsertId();
        $ins = $db->prepare("INSERT INTO ia_check_item (check_id, sort_order, is_header, col_a, col_b, col_c, col_d,
                                 ref_kind, ref_id) VALUES (?,?,?,?,?,?,?,?,?)");
        foreach ($items as $it) {
            $ins->execute([$kid, $it['sort_order'], $it['is_header'], $it['col_a'], $it['col_b'],
                           $it['col_c'], $it['col_d'], $it['ref_kind'], $it['ref_id']]);
        }
        $db->commit();
        jout(['check_id' => $kid, 'items' => count($items)]);
    } catch (Throwable $e) { $db->rollBack(); jerr('建立失敗：' . $e->getMessage(), 500); }
}

case 'check_get': {
    iaReqView($perms);
    $kid = (int)($_GET['check_id'] ?? 0);
    $st = $db->prepare("SELECT k.*, c.case_no FROM ia_check k LEFT JOIN ia_case c ON c.case_id=k.case_id
                         WHERE k.check_id=? AND COALESCE(k.is_deleted,0)=0");
    $st->execute([$kid]);
    $k = $st->fetch(PDO::FETCH_ASSOC);
    if (!$k) jerr('找不到這張查檢表', 404);
    $st = $db->prepare("SELECT i.*, n.nc_no FROM ia_check_item i LEFT JOIN ia_nc n ON n.nc_id=i.nc_id
                         WHERE i.check_id=? ORDER BY i.sort_order, i.item_id");
    $st->execute([$kid]);
    $k['items'] = $st->fetchAll(PDO::FETCH_ASSOC);
    $k['kind_label'] = IA_CHECK_KINDS[$k['kind']]['label'] ?? $k['kind'];
    $k['can_edit'] = ($perms['canAudit'] && $k['status'] !== 'done') || $perms['canAdmin'];
    jout(['row' => $k]);
}

case 'check_save_items': {
    iaReqAudit($perms);
    $kid = (int)($_POST['check_id'] ?? 0);
    $st = $db->prepare("SELECT * FROM ia_check WHERE check_id=? AND COALESCE(is_deleted,0)=0");
    $st->execute([$kid]);
    $k = $st->fetch(PDO::FETCH_ASSOC);
    if (!$k) jerr('找不到這張查檢表', 404);
    if ($k['status'] === 'done' && !$perms['canAdmin']) jerr('這張查檢表已結案，需內稽管理員才能修改');

    $items = json_decode((string)($_POST['items'] ?? '[]'), true);
    if (!is_array($items)) jerr('格式錯誤');
    $db->beginTransaction();
    try {
        $upd = $db->prepare("UPDATE ia_check_item SET result=?, evidence=?, remark=?, col_c=?, col_d=?
                              WHERE item_id=? AND check_id=?");
        foreach ($items as $it) {
            $iid = (int)($it['item_id'] ?? 0);
            if (!$iid) continue;
            $res = (string)($it['result'] ?? '');
            if (!in_array($res, ['', 'ok', 'ng'], true)) $res = '';
            $upd->execute([$res ?: null,
                           trim((string)($it['evidence'] ?? '')) ?: null,
                           mb_substr(trim((string)($it['remark'] ?? '')), 0, 255) ?: null,
                           mb_substr(trim((string)($it['col_c'] ?? '')), 0, 255) ?: null,
                           mb_substr(trim((string)($it['col_d'] ?? '')), 0, 255) ?: null,
                           $iid, $kid]);
        }
        $ad = iaDate($_POST['check_date'] ?? '');
        $db->prepare("UPDATE ia_check SET title=?, check_date=COALESCE(?, check_date), updated_at=NOW() WHERE check_id=?")
           ->execute([mb_substr(trim((string)($_POST['title'] ?? '')), 0, 150) ?: null, $ad, $kid]);
        $db->commit();
        jout(['saved' => true]);
    } catch (Throwable $e) { $db->rollBack(); jerr('儲存失敗：' . $e->getMessage(), 500); }
}

case 'check_done': {
    iaReqAudit($perms);
    $kid = (int)($_POST['check_id'] ?? 0);
    $to  = (string)($_POST['status'] ?? 'done');
    if (!in_array($to, ['draft', 'done'], true)) jerr('狀態不正確');
    $st = $db->prepare("SELECT * FROM ia_check WHERE check_id=? AND COALESCE(is_deleted,0)=0");
    $st->execute([$kid]);
    $k = $st->fetch(PDO::FETCH_ASSOC);
    if (!$k) jerr('找不到這張查檢表', 404);
    if ($to === 'done') {
        $q = $db->prepare("SELECT COUNT(*) FROM ia_check_item WHERE check_id=? AND is_header=0
                            AND (result IS NULL OR result='')");
        $q->execute([$kid]);
        $todo = (int)$q->fetchColumn();
        if ($todo > 0) jerr('還有 ' . $todo . ' 個項目沒有判定合格／不合格，不能結案');
    }
    if ($to === 'draft' && !$perms['canAdmin']) jerr('取消結案需內稽管理員權限', 403);
    $db->prepare("UPDATE ia_check SET status=?, updated_at=NOW() WHERE check_id=?")->execute([$to, $kid]);
    jout(['saved' => true]);
}

case 'check_delete': {
    iaReqAdmin($perms);
    $kid = (int)($_POST['check_id'] ?? 0);
    $q = $db->prepare("SELECT COUNT(*) FROM ia_check_item WHERE check_id=? AND nc_id IS NOT NULL");
    $q->execute([$kid]);
    if ((int)$q->fetchColumn() > 0) jerr('這張查檢表已經開過不符合通知單，不可刪除');
    $db->prepare("UPDATE ia_check SET is_deleted=1, updated_at=NOW() WHERE check_id=?")->execute([$kid]);
    jout(['deleted' => true]);
}

/* ---- AS 條文題庫維護 ---- */
case 'clause_list': {
    iaReqView($perms);
    jout(['rows' => ia_as_clauses($db, false)]);
}
case 'clause_save': {
    iaReqAdmin($perms);
    $id   = iaInt($_POST['clause_id'] ?? '');
    $text = trim((string)($_POST['clause_text'] ?? ''));
    if ($text === '') jerr('請填品質管理系統要求');
    if (mb_strlen($text) > 1000) jerr('內容過長（上限 1000 字）');
    $ref  = trim((string)($_POST['doc_ref'] ?? ''));
    $hdr  = !empty($_POST['is_header']) ? 1 : 0;
    $act  = isset($_POST['is_active']) ? (!empty($_POST['is_active']) ? 1 : 0) : 1;
    $sort = iaInt($_POST['sort_order'] ?? '');
    if ($id) {
        $db->prepare("UPDATE ia_as_clause SET clause_text=?, doc_ref=?, is_header=?, is_active=?,
                          sort_order=COALESCE(?, sort_order), updated_at=NOW(), updated_by=? WHERE clause_id=?")
           ->execute([$text, $ref ?: null, $hdr, $act, $sort, $uname, $id]);
    } else {
        if ($sort === null) {
            $sort = (int)$db->query("SELECT COALESCE(MAX(sort_order),0)+10 FROM ia_as_clause")->fetchColumn();
        }
        $db->prepare("INSERT INTO ia_as_clause (sort_order, is_header, clause_text, doc_ref, is_active, updated_at, updated_by)
                      VALUES (?,?,?,?,?,NOW(),?)")->execute([$sort, $hdr, $text, $ref ?: null, $act, $uname]);
        $id = (int)$db->lastInsertId();
    }
    jout(['clause_id' => $id]);
}
case 'clause_delete': {
    iaReqAdmin($perms);
    $id = (int)($_POST['clause_id'] ?? 0);
    // 已經被查檢表引用的條文不刪除，改停用（刪掉會讓舊查檢表的來源對不上）
    $q = $db->prepare("SELECT COUNT(*) FROM ia_check_item WHERE ref_kind='as_clause' AND ref_id=?");
    $q->execute([$id]);
    if ((int)$q->fetchColumn() > 0) {
        $db->prepare("UPDATE ia_as_clause SET is_active=0, updated_at=NOW(), updated_by=? WHERE clause_id=?")
           ->execute([$uname, $id]);
        jout(['deactivated' => true, 'note' => '此條文已被既有查檢表引用，已改為停用（不再出現在新表）而非刪除']);
    }
    $db->prepare("DELETE FROM ia_as_clause WHERE clause_id=?")->execute([$id]);
    jout(['deleted' => true]);
}

/* ============================ 不符合通知單 2-GM-06-07 ============================ */
case 'nc_list': {
    $w = ['COALESCE(n.is_deleted,0)=0']; $p = [];
    $year = iaInt($_GET['year'] ?? '');
    if ($year) { $w[] = 'n.year=?'; $p[] = $year; }
    $stage = (string)($_GET['stage'] ?? '');
    if ($stage !== '' && isset(IA_NC_STAGES[$stage])) { $w[] = 'n.stage=?'; $p[] = $stage; }
    $cid = iaInt($_GET['case_id'] ?? '');
    if ($cid) { $w[] = 'n.case_id=?'; $p[] = $cid; }
    if ((string)($_GET['overdue'] ?? '') === '1') { $w[] = "n.stage<>'closed' AND n.due_date IS NOT NULL AND n.due_date < ?"; $p[] = $today; }
    // 沒有檢閱權限的人只看得到跟自己有關的（後端強制綁定，不是只擋前端）
    if (!$perms['canView']) {
        $deptIds = [];
        try {
            $q = $db->prepare("SELECT DISTINCT department_id FROM user_department_position_map WHERE user_id=?");
            $q->execute([$uid]);
            foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $d) {
                foreach (eg_dept_subtree_ids($db, (int)$d) ?: [(int)$d] as $x) $deptIds[] = (int)$x;
            }
        } catch (Throwable $e) {}
        $deptIds = array_values(array_unique($deptIds));
        if ($deptIds) {
            $in = implode(',', array_fill(0, count($deptIds), '?'));
            $w[] = "(n.auditee_id=? OR n.head_id=? OR n.resp_id=? OR n.auditor_id=? OR n.dept_id IN ($in))";
            array_push($p, $uid, $uid, $uid, $uid);
            foreach ($deptIds as $d) $p[] = $d;
        } else {
            $w[] = "(n.auditee_id=? OR n.head_id=? OR n.resp_id=? OR n.auditor_id=?)";
            array_push($p, $uid, $uid, $uid, $uid);
        }
    }
    $kw = trim((string)($_GET['kw'] ?? ''));
    if ($kw !== '') {
        foreach (preg_split('/\s+/', $kw) as $k) {
            if ($k === '') continue;
            $w[] = "(n.nc_no LIKE ? OR n.dept_name LIKE ? OR n.auditee_name LIKE ? OR n.fact LIKE ?
                     OR n.clause_ref LIKE ? OR n.ref_form_no LIKE ? OR n.cause LIKE ? OR n.corrective LIKE ?
                     OR n.preventive LIKE ? OR n.auditor_name LIKE ? OR n.head_note LIKE ?)";
            for ($i = 0; $i < 11; $i++) $p[] = '%' . $k . '%';
        }
    }
    $sql = "SELECT n.*, c.case_no FROM ia_nc n LEFT JOIN ia_case c ON c.case_id=n.case_id
             WHERE " . implode(' AND ', $w) . " ORDER BY n.year DESC, n.nc_no DESC, n.nc_id DESC";
    $st = $db->prepare($sql); $st->execute($p);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['type_label']  = IA_NC_TYPES[(string)($r['nc_type'] ?? '')] ?? '';
        $r['stage_label'] = IA_NC_STAGES[(string)$r['stage']] ?? (string)$r['stage'];
        $r['overdue']     = ($r['stage'] !== 'closed' && $r['due_date'] && $r['due_date'] < $today) ? 1 : 0;
    }
    unset($r);
    jout(['rows' => $rows]);
}

case 'nc_get': {
    $id = (int)($_GET['nc_id'] ?? 0);
    $st = $db->prepare("SELECT n.*, c.case_no FROM ia_nc n LEFT JOIN ia_case c ON c.case_id=n.case_id
                         WHERE n.nc_id=? AND COALESCE(n.is_deleted,0)=0");
    $st->execute([$id]);
    $n = $st->fetch(PDO::FETCH_ASSOC);
    if (!$n) jerr('找不到這張不符合通知單', 404);
    $sp = ia_nc_stage_perm($db, $n, $perms, $uid);
    if (!$sp['view']) jerr('無權檢視這張不符合通知單', 403);
    $n['perm']        = $sp;
    $n['type_label']  = IA_NC_TYPES[(string)($n['nc_type'] ?? '')] ?? '';
    $n['stage_label'] = IA_NC_STAGES[(string)$n['stage']] ?? (string)$n['stage'];
    $n['overdue']     = ($n['stage'] !== 'closed' && $n['due_date'] && $n['due_date'] < $today) ? 1 : 0;
    // 建議的單位主管（依業務日期回推當時職務，ai-rules/22）
    $sug = ia_dept_head_asof($db, (int)($n['dept_id'] ?? 0), (string)($n['audit_date'] ?? ''));
    $n['suggest_head'] = $sug;
    $st = $db->prepare("SELECT * FROM ia_nc_log WHERE nc_id=? ORDER BY log_id");
    $st->execute([$id]);
    $n['logs'] = $st->fetchAll(PDO::FETCH_ASSOC);
    jout(['row' => $n]);
}

case 'nc_create': {
    iaReqAudit($perms);
    $ad = iaDate($_POST['audit_date'] ?? '');
    if (!$ad) jerr('請填稽核日期');
    $fact = trim((string)($_POST['fact'] ?? ''));
    if ($fact === '') jerr('請填不合格事實描述');
    $type = (string)($_POST['nc_type'] ?? '');
    if (!isset(IA_NC_TYPES[$type])) jerr('請選擇不合格類型');
    $deptId = iaInt($_POST['dept_id'] ?? '');
    $deptName = trim((string)($_POST['dept_name'] ?? ''));
    if ($deptId) {
        $q = $db->prepare("SELECT name FROM department WHERE id=?"); $q->execute([$deptId]);
        $deptName = (string)($q->fetchColumn() ?: $deptName);
    }
    if ($deptName === '') jerr('請選擇受稽核單位');
    $due = iaDate($_POST['due_date'] ?? '');
    if ($due && $due < $ad) jerr('要求完成期限不可早於稽核日期');

    $auditeeId = iaInt($_POST['auditee_id'] ?? ''); $auditeeName = '';
    if ($auditeeId) {
        $q = $db->prepare("SELECT user_cname FROM `user` WHERE id=?"); $q->execute([$auditeeId]);
        $auditeeName = (string)($q->fetchColumn() ?: '');
        if ($auditeeName === '') jerr('受審核人不存在');
    }
    $caseId = iaInt($_POST['case_id'] ?? '');
    $c = [];                                   // 沒綁案件時仍要有值，否則下方取 leader_id 會噴 undefined
    if ($caseId) {
        $q = $db->prepare("SELECT leader_id, leader_name FROM ia_case WHERE case_id=? AND COALESCE(is_deleted,0)=0");
        $q->execute([$caseId]);
        $c = $q->fetch(PDO::FETCH_ASSOC);
        if (!$c) jerr('稽核案件不存在');
    }
    $srcItem = iaInt($_POST['src_item_id'] ?? '');

    $db->beginTransaction();
    try {
        $head = ia_dept_head_asof($db, $deptId, $ad);
        $ncNo = ia_next_nc_no($db, $ad);
        $db->prepare("INSERT INTO ia_nc (nc_no, case_id, year, dept_id, dept_name, auditee_id, auditee_name,
                          audit_date, src_kind, src_item_id, ref_form_no, fact, nc_type, clause_ref, due_date,
                          auditor_id, auditor_name, auditor_date, head_id, head_name,
                          leader_id, leader_name, stage, created_by, created_by_name, created_at, updated_at)
                      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'issued', ?,?, NOW(), NOW())")
           ->execute([$ncNo, $caseId, (int)substr($ad, 0, 4), $deptId, $deptName, $auditeeId, $auditeeName ?: null,
                      $ad, (string)($_POST['src_kind'] ?? '') ?: null, $srcItem,
                      mb_substr(trim((string)($_POST['ref_form_no'] ?? '')), 0, 60) ?: null,
                      $fact, $type, mb_substr(trim((string)($_POST['clause_ref'] ?? '')), 0, 300) ?: null, $due,
                      $uid, $uname, $ad,
                      $head['id'] ?? null, $head['name'] ?? null,
                      $c['leader_id'] ?? null, $c['leader_name'] ?? null,
                      $uid, $uname]);
        $ncId = (int)$db->lastInsertId();
        if ($srcItem) {
            $db->prepare("UPDATE ia_check_item SET nc_id=?, remark=COALESCE(NULLIF(remark,''), ?) WHERE item_id=?")
               ->execute([$ncId, $ncNo, $srcItem]);
        }
        ia_nc_log_add($db, $ncId, 'issued', 'create', $uid, $uname, '開立不符合通知單 ' . $ncNo);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('建立失敗：' . $e->getMessage(), 500); }

    $q = $db->prepare("SELECT * FROM ia_nc WHERE nc_id=?"); $q->execute([$ncId]);
    $nc = $q->fetch(PDO::FETCH_ASSOC);
    ia_notify_nc_issued($db, $nc, $uid);
    jout(['nc_id' => $ncId, 'nc_no' => $ncNo]);
}

case 'nc_save_sec1': {
    // 稽核員段：不合格事實／類型／違反條文／期限／受審核人
    $id = (int)($_POST['nc_id'] ?? 0);
    $st = $db->prepare("SELECT * FROM ia_nc WHERE nc_id=? AND COALESCE(is_deleted,0)=0");
    $st->execute([$id]); $n = $st->fetch(PDO::FETCH_ASSOC);
    if (!$n) jerr('找不到這張不符合通知單', 404);
    $sp = ia_nc_stage_perm($db, $n, $perms, $uid);
    if (!$sp['sec1']) jerr('您沒有修改稽核員填寫區的權限（或本單已結案）', 403);

    $fact = trim((string)($_POST['fact'] ?? ''));
    if ($fact === '') jerr('請填不合格事實描述');
    $type = (string)($_POST['nc_type'] ?? '');
    if (!isset(IA_NC_TYPES[$type])) jerr('請選擇不合格類型');
    $due = iaDate($_POST['due_date'] ?? '');
    if ($due && $n['audit_date'] && $due < $n['audit_date']) jerr('要求完成期限不可早於稽核日期');
    $auditeeId = iaInt($_POST['auditee_id'] ?? ''); $auditeeName = null;
    if ($auditeeId) {
        $q = $db->prepare("SELECT user_cname FROM `user` WHERE id=?"); $q->execute([$auditeeId]);
        $auditeeName = (string)($q->fetchColumn() ?: '');
        if ($auditeeName === '') jerr('受審核人不存在');
    }
    $db->prepare("UPDATE ia_nc SET fact=?, nc_type=?, clause_ref=?, due_date=?, ref_form_no=?,
                      auditee_id=?, auditee_name=?, updated_at=NOW() WHERE nc_id=?")
       ->execute([$fact, $type, mb_substr(trim((string)($_POST['clause_ref'] ?? '')), 0, 300) ?: null, $due,
                  mb_substr(trim((string)($_POST['ref_form_no'] ?? '')), 0, 60) ?: null,
                  $auditeeId, $auditeeName, $id]);
    ia_nc_log_add($db, $id, (string)$n['stage'], 'edit', $uid, $uname, '修改稽核員填寫區');
    jout(['saved' => true]);
}

case 'nc_save_sec2': {
    // 受稽單位段：單位主管核示／原因分析／糾正措施／預防措施／責任主管
    $id = (int)($_POST['nc_id'] ?? 0);
    $st = $db->prepare("SELECT * FROM ia_nc WHERE nc_id=? AND COALESCE(is_deleted,0)=0");
    $st->execute([$id]); $n = $st->fetch(PDO::FETCH_ASSOC);
    if (!$n) jerr('找不到這張不符合通知單', 404);
    $sp = ia_nc_stage_perm($db, $n, $perms, $uid);
    if (!$sp['sec2']) jerr('您沒有填寫受稽單位回覆區的權限（或本單已結案）', 403);

    $submit = !empty($_POST['submit']);
    $cause  = trim((string)($_POST['cause'] ?? ''));
    $corr   = trim((string)($_POST['corrective'] ?? ''));
    $prev   = trim((string)($_POST['preventive'] ?? ''));
    if ($submit) {
        // 送出才驗必填；只是暫存不擋（讓人分次填）
        if ($cause === '') jerr('請填原因分析');
        if ($corr === '')  jerr('請填糾正措施及完成時間');
        if ($prev === '')  jerr('請填預防措施及完成時間');
    }
    $headId = iaInt($_POST['head_id'] ?? ''); $headName = null;
    if ($headId) {
        $q = $db->prepare("SELECT user_cname FROM `user` WHERE id=?"); $q->execute([$headId]);
        $headName = (string)($q->fetchColumn() ?: '');
        if ($headName === '') jerr('受審查單位主管不存在');
    }
    $respId = iaInt($_POST['resp_id'] ?? ''); $respName = null;
    if ($respId) {
        $q = $db->prepare("SELECT user_cname FROM `user` WHERE id=?"); $q->execute([$respId]);
        $respName = (string)($q->fetchColumn() ?: '');
        if ($respName === '') jerr('責任主管不存在');
    }
    $headNote = trim((string)($_POST['head_note'] ?? ''));       // 單位主管核示（使用者要求）
    $headDate = iaDate($_POST['head_date'] ?? '') ?: $today;
    $respDate = iaDate($_POST['resp_date'] ?? '') ?: $today;

    $db->beginTransaction();
    try {
        $db->prepare("UPDATE ia_nc SET cause=?, corrective=?, preventive=?, head_id=?, head_name=?,
                          head_note=?, head_date=?, resp_id=?, resp_name=?, resp_date=?, updated_at=NOW()
                       WHERE nc_id=?")
           ->execute([$cause ?: null, $corr ?: null, $prev ?: null, $headId, $headName,
                      $headNote ?: null, $headId ? $headDate : null,
                      $respId, $respName, $respId ? $respDate : null, $id]);
        if ($submit && $n['stage'] === 'issued') {
            $db->prepare("UPDATE ia_nc SET stage='replied', updated_at=NOW() WHERE nc_id=?")->execute([$id]);
        }
        ia_nc_log_add($db, $id, $submit ? 'replied' : (string)$n['stage'],
                      $submit ? 'reply' : 'edit', $uid, $uname,
                      $submit ? '受稽單位送出回覆' : '暫存受稽單位回覆',
                      $sp['proxy'] ? 1 : 0, $sp['proxy'] ? (string)($headName ?: $n['dept_name']) : '');
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('儲存失敗：' . $e->getMessage(), 500); }

    if ($submit) {
        $q = $db->prepare("SELECT * FROM ia_nc WHERE nc_id=?"); $q->execute([$id]);
        $nc = $q->fetch(PDO::FETCH_ASSOC);
        ia_nc_close_notice($db, $id, 'IA_NC_REPLY');
        ia_notify_nc_replied($db, $nc, $uid, $uname);
    }
    jout(['saved' => true, 'submitted' => $submit, 'proxy' => (bool)$sp['proxy']]);
}

case 'nc_save_sec3': {
    // 驗證段：稽核組長驗證描述／結束
    $id = (int)($_POST['nc_id'] ?? 0);
    $st = $db->prepare("SELECT * FROM ia_nc WHERE nc_id=? AND COALESCE(is_deleted,0)=0");
    $st->execute([$id]); $n = $st->fetch(PDO::FETCH_ASSOC);
    if (!$n) jerr('找不到這張不符合通知單', 404);
    $sp = ia_nc_stage_perm($db, $n, $perms, $uid);
    if (!$sp['sec3']) jerr('尚未收到受稽單位回覆，或您沒有驗證權限', 403);

    $desc = trim((string)($_POST['verify_desc'] ?? ''));
    $res  = (string)($_POST['verify_result'] ?? '');
    if (!in_array($res, ['', 'pass', 'fail'], true)) jerr('驗證結果不正確');
    $submit = !empty($_POST['submit']);
    if ($submit) {
        if ($desc === '') jerr('請填糾正和預防措施執行狀況驗證描述');
        if ($res === '')  jerr('請選擇驗證結果（通過／不通過）');
    }
    $ld = iaDate($_POST['leader_date'] ?? '') ?: $today;
    $db->beginTransaction();
    try {
        $db->prepare("UPDATE ia_nc SET verify_desc=?, verify_result=?, close_note=?,
                          leader_id=?, leader_name=?, leader_date=?, updated_at=NOW() WHERE nc_id=?")
           ->execute([$desc ?: null, $res ?: null,
                      mb_substr(trim((string)($_POST['close_note'] ?? '')), 0, 300) ?: null,
                      $uid, $uname, $ld, $id]);
        if ($submit) {
            if ($res === 'fail') {
                // 驗證不通過＝退回受稽單位重填
                $db->prepare("UPDATE ia_nc SET stage='issued', updated_at=NOW() WHERE nc_id=?")->execute([$id]);
                ia_nc_log_add($db, $id, 'issued', 'verify', $uid, $uname, '驗證不通過，退回受稽單位重新提出措施');
            } else {
                $db->prepare("UPDATE ia_nc SET stage='verified', updated_at=NOW() WHERE nc_id=?")->execute([$id]);
                ia_nc_log_add($db, $id, 'verified', 'verify', $uid, $uname, '驗證通過，待管理代表意見');
            }
        } else {
            ia_nc_log_add($db, $id, (string)$n['stage'], 'edit', $uid, $uname, '暫存驗證內容');
        }
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('儲存失敗：' . $e->getMessage(), 500); }

    if ($submit && $res === 'fail') {
        $q = $db->prepare("SELECT * FROM ia_nc WHERE nc_id=?"); $q->execute([$id]);
        ia_notify_nc_issued($db, $q->fetch(PDO::FETCH_ASSOC), $uid);
    }
    jout(['saved' => true, 'submitted' => $submit]);
}

case 'nc_save_sec4': {
    // 管理代表意見＋結案
    iaReqAdmin($perms);
    $id = (int)($_POST['nc_id'] ?? 0);
    $st = $db->prepare("SELECT * FROM ia_nc WHERE nc_id=? AND COALESCE(is_deleted,0)=0");
    $st->execute([$id]); $n = $st->fetch(PDO::FETCH_ASSOC);
    if (!$n) jerr('找不到這張不符合通知單', 404);
    if ($n['stage'] === 'closed') jerr('本單已結案');
    $close = !empty($_POST['close']);
    if ($close && $n['stage'] !== 'verified') jerr('要先由稽核組長完成驗證才能結案');
    $note = trim((string)($_POST['mgr_note'] ?? ''));
    $md   = iaDate($_POST['mgr_date'] ?? '') ?: $today;
    $db->beginTransaction();
    try {
        $db->prepare("UPDATE ia_nc SET mgr_note=?, mgr_id=?, mgr_name=?, mgr_date=?,
                          stage=IF(?=1,'closed',stage), updated_at=NOW() WHERE nc_id=?")
           ->execute([$note ?: null, $uid, $uname, $md, $close ? 1 : 0, $id]);
        ia_nc_log_add($db, $id, $close ? 'closed' : (string)$n['stage'],
                      $close ? 'close' : 'edit', $uid, $uname, $close ? '管理代表結案' : '填寫管理代表意見');
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('儲存失敗：' . $e->getMessage(), 500); }
    if ($close) {
        $q = $db->prepare("SELECT * FROM ia_nc WHERE nc_id=?"); $q->execute([$id]);
        ia_notify_nc_closed($db, $q->fetch(PDO::FETCH_ASSOC), $uid, $uname);
    }
    jout(['saved' => true, 'closed' => $close]);
}

case 'nc_delete': {
    iaReqAdmin($perms);
    $id = (int)($_POST['nc_id'] ?? 0);
    $db->beginTransaction();
    try {
        $db->prepare("UPDATE ia_nc SET is_deleted=1, updated_at=NOW() WHERE nc_id=?")->execute([$id]);
        $db->prepare("UPDATE ia_check_item SET nc_id=NULL WHERE nc_id=?")->execute([$id]);
        foreach (['IA_NC_REPLY', 'IA_NC_VERIFY', 'IA_NC_DUE'] as $t) ia_nc_close_notice($db, $id, $t);
        ia_nc_log_add($db, $id, 'deleted', 'delete', $uid, $uname, '刪除');
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('刪除失敗：' . $e->getMessage(), 500); }
    jout(['deleted' => true]);
}

case 'nc_resend': {
    iaReqAudit($perms);
    $id = (int)($_POST['nc_id'] ?? 0);
    $st = $db->prepare("SELECT * FROM ia_nc WHERE nc_id=? AND COALESCE(is_deleted,0)=0");
    $st->execute([$id]); $n = $st->fetch(PDO::FETCH_ASSOC);
    if (!$n) jerr('找不到這張不符合通知單', 404);
    if ($n['stage'] === 'closed') jerr('已結案的單不需再通知');
    $eid = ia_notify_nc_issued($db, $n, $uid);
    jout(['sent' => (bool)$eid]);
}

/* ============================ 稽核報告表 2-GM-06-08 ============================ */
case 'report_get': {
    iaReqView($perms);
    $year = (int)($_GET['year'] ?? substr($today, 0, 4));
    $st = $db->prepare("SELECT * FROM ia_report WHERE year=? AND COALESCE(is_deleted,0)=0");
    $st->execute([$year]);
    $rep = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    $data = ia_report_data($db, $year);          // 缺點數與缺點記錄一律即時算，不存快照
    jout(['year' => $year, 'report' => $rep, 'rows' => $data['rows'], 'records' => $data['records']]);
}

case 'report_save': {
    iaReqAdmin($perms);
    $year = (int)($_POST['year'] ?? 0);
    if ($year < 2000 || $year > 2200) jerr('年度不正確');
    $note = trim((string)($_POST['extra_note'] ?? ''));
    $st = $db->prepare("SELECT report_id FROM ia_report WHERE year=? AND COALESCE(is_deleted,0)=0");
    $st->execute([$year]);
    $rid = (int)($st->fetchColumn() ?: 0);
    if ($rid) {
        $db->prepare("UPDATE ia_report SET extra_note=?, updated_at=NOW() WHERE report_id=?")
           ->execute([$note ?: null, $rid]);
    } else {
        $db->prepare("INSERT INTO ia_report (year, extra_note, status, maker_id, maker_name, maker_date,
                          created_by, created_at, updated_at) VALUES (?,?, 'draft', ?,?,?,?, NOW(), NOW())")
           ->execute([$year, $note ?: null, $uid, $uname, $today, $uid]);
        $rid = (int)$db->lastInsertId();
    }
    // 預定完成改善時間是存在 ia_case_dept.improve_due（那才是「受稽單位」層級的欄位）
    $dues = json_decode((string)($_POST['dues'] ?? '[]'), true);
    if (is_array($dues) && $dues) {
        $upd = $db->prepare("UPDATE ia_case_dept cd JOIN ia_case c ON c.case_id=cd.case_id
                             SET cd.improve_due=? WHERE c.year=? AND cd.dept_name=?");
        foreach ($dues as $d) {
            $dn = trim((string)($d['dept_name'] ?? ''));
            if ($dn === '') continue;
            $upd->execute([iaDate($d['improve_due'] ?? ''), $year, $dn]);
        }
    }
    jout(['report_id' => $rid]);
}

case 'report_approve': {
    iaReqAdmin($perms);
    $year = (int)($_POST['year'] ?? 0);
    $d = iaDate($_POST['biz_date'] ?? '') ?: $today;
    $st = $db->prepare("SELECT report_id FROM ia_report WHERE year=? AND COALESCE(is_deleted,0)=0");
    $st->execute([$year]);
    $rid = (int)($st->fetchColumn() ?: 0);
    if (!$rid) jerr('請先儲存稽核報告表');
    $db->prepare("UPDATE ia_report SET status='approved', approver_id=?, approver_name=?, approver_date=?, updated_at=NOW()
                   WHERE report_id=?")->execute([$uid, $uname, $d, $rid]);
    jout(['saved' => true]);
}

/* ============================ 會議紀錄串接（不重複建立，走既有模組） ============================ */
case 'meeting_create': {
    iaReqAdmin($perms);
    $cid  = (int)($_POST['case_id'] ?? 0);
    $kind = (string)($_POST['kind'] ?? '');           // pre=事前會議 / end=結束會議
    if (!in_array($kind, ['pre', 'end'], true)) jerr('會議種類不正確');
    $st = $db->prepare("SELECT * FROM ia_case WHERE case_id=? AND COALESCE(is_deleted,0)=0");
    $st->execute([$cid]); $c = $st->fetch(PDO::FETCH_ASSOC);
    if (!$c) jerr('找不到這張稽核通知單', 404);
    $col = $kind === 'pre' ? 'pre_meeting_id' : 'end_meeting_id';
    if ((int)($c[$col] ?? 0)) {
        // 已建過就直接回傳既有的，不重複建立（點開即刷新鐵則：同一顆按鈕按兩次不該長出兩筆會議）
        $q = $db->prepare("SELECT meeting_id FROM meeting_record WHERE meeting_id=?");
        $q->execute([(int)$c[$col]]);
        if ($q->fetchColumn()) jout(['meeting_id' => (int)$c[$col], 'existed' => true]);
    }

    $set = ia_settings($db);
    $year = (int)$c['year'];
    $subject = $kind === 'pre'
        ? (trim((string)$set['ia_meeting_pre_subject']) ?: ($year . '年度 內稽事前會議'))
        : (trim((string)$set['ia_meeting_end_subject']) ?: ($year . '年度 內稽結束會議'));
    if ((string)$c['case_no'] !== '') $subject .= '（稽核件號 ' . $c['case_no'] . '）';

    $mdate = $kind === 'pre'
        ? ($c['notify_date'] ?: $c['audit_from'] ?: $today)
        : ($c['end_meet_date'] ?: $c['audit_to'] ?: $c['audit_from'] ?: $today);
    $stime = $kind === 'end' ? ($c['end_meet_start'] ?: null) : null;
    $etime = $kind === 'end' ? ($c['end_meet_end'] ?: null)   : null;
    $place = $kind === 'end' ? ($c['end_meet_place'] ?: null) : null;

    // 主席＝稽核組長（沒填就用建立者）；與會人員＝受稽單位的陪檢員＋稽核員
    $chairId   = (int)($c['leader_id'] ?? 0) ?: $uid;
    $q = $db->prepare("SELECT user_cname FROM `user` WHERE id=?"); $q->execute([$chairId]);
    $chairName = (string)($q->fetchColumn() ?: $uname);

    $db->beginTransaction();
    try {
        $db->prepare("INSERT INTO meeting_record (subject, meeting_date, start_time, end_time, location,
                          chair_user_id, chair_name, recorder_user_id, recorder_name, status,
                          created_at, created_by, created_by_name)
                      VALUES (?,?,?,?,?,?,?,?,?, 'draft', NOW(), ?, ?)")
           ->execute([$subject, $mdate, $stime, $etime, $place,
                      $chairId, $chairName, $uid, $uname, $uid, $uname]);
        $mid = (int)$db->lastInsertId();

        // 與會人員：稽核組長＋各受稽單位的稽核員與陪檢員（去重）
        $q = $db->prepare("SELECT auditor_id, auditor_name, escort_id, escort_name, dept_name
                             FROM ia_case_dept WHERE case_id=? ORDER BY sort_order");
        $q->execute([$cid]);
        $seen = []; $att = [];
        $push = function ($id, $name) use (&$seen, &$att) {
            $id = (int)$id;
            if (!$id || isset($seen[$id])) return;
            $seen[$id] = 1;
            $att[] = ['id' => $id, 'name' => (string)$name];
        };
        $push($chairId, $chairName);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $push($r['auditor_id'], $r['auditor_name']);
            $push($r['escort_id'],  $r['escort_name']);
        }
        $ins = $db->prepare("INSERT INTO meeting_attendee (meeting_id, user_id, user_name, dept_name,
                                 position_name, is_chair, signed) VALUES (?,?,?,?,?,?,0)");
        foreach ($att as $a) {
            $idt = ia_identity_asof($db, $a['id'], $mdate);
            $ins->execute([$mid, $a['id'], $a['name'], $idt['dept'] ?: null, $idt['position'] ?: null,
                           $a['id'] === $chairId ? 1 : 0]);
        }
        $db->prepare("UPDATE ia_case SET `$col`=?, updated_at=NOW() WHERE case_id=?")->execute([$mid, $cid]);
        $db->commit();
        jout(['meeting_id' => $mid, 'existed' => false, 'attendees' => count($att)]);
    } catch (Throwable $e) { $db->rollBack(); jerr('建立會議紀錄失敗：' . $e->getMessage(), 500); }
}

case 'meeting_link': {
    // 改綁到既有的會議紀錄（使用者自己先建好的情況）
    iaReqAdmin($perms);
    $cid  = (int)($_POST['case_id'] ?? 0);
    $kind = (string)($_POST['kind'] ?? '');
    if (!in_array($kind, ['pre', 'end'], true)) jerr('會議種類不正確');
    $mid = iaInt($_POST['meeting_id'] ?? '');
    if ($mid) {
        $q = $db->prepare("SELECT 1 FROM meeting_record WHERE meeting_id=?"); $q->execute([$mid]);
        if (!$q->fetchColumn()) jerr('找不到這筆會議紀錄');
    }
    $col = $kind === 'pre' ? 'pre_meeting_id' : 'end_meeting_id';
    $db->prepare("UPDATE ia_case SET `$col`=?, updated_at=NOW() WHERE case_id=?")->execute([$mid, $cid]);
    jout(['saved' => true]);
}

case 'meeting_options': {
    iaReqView($perms);
    $year = (int)($_GET['year'] ?? substr($today, 0, 4));
    try {
        $st = $db->prepare("SELECT meeting_id, subject, meeting_date, status FROM meeting_record
                             WHERE YEAR(meeting_date)=? ORDER BY meeting_date DESC, meeting_id DESC LIMIT 200");
        $st->execute([$year]);
        jout(['rows' => $st->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (Throwable $e) { jout(['rows' => []]); }
}

/* ============================ 列印中繼資料 ============================ */
case 'print_meta': {
    // 讀取不卡管理員（ai-rules/18 鐵則9：卡了一般人列印永遠拿不到圖章模板）
    $key = (string)($_GET['key'] ?? '');
    if (!isset(IA_ASDOC_MODULES[$key])) jerr('表單代碼不正確');
    $biz = iaDate($_GET['biz_date'] ?? '');
    $mod = IA_ASDOC_MODULES[$key];
    $doc = eg_asdoc_get($db, $mod['module']);
    $docId = (int)($doc['id'] ?? 0);
    // 版次依業務日期回推（ai-rules/16 第三之四節），不是一律印現在最新版
    $docNo = $docId ? eg_asdoc_no_asof_id($db, $docId, $biz) : $mod['fallback'];

    $set = ia_settings($db);
    $tpl = ia_stamp_template($db);          // 含 schema，前端 eg_stamp.js 要吃它才畫得出模板章
    $ctx = ['leader_id' => iaInt($_GET['leader_id'] ?? ''), 'leader_name' => (string)($_GET['leader_name'] ?? ''),
            'maker_id'  => iaInt($_GET['maker_id'] ?? ''),  'maker_name'  => (string)($_GET['maker_name'] ?? ''),
            'biz_date'  => $biz ?: $today];
    jout([
        'doc_no'    => $docNo,
        'doc_name'  => $doc['doc_name'] ?? $mod['label'],
        'company'   => eg_company_full_name($db),
        'stamp_tpl' => $tpl,
        'sign_approve' => ia_sign_person($db, (string)$set['ia_sign_approve'], $ctx),
        'sign_review'  => ia_sign_person($db, (string)$set['ia_sign_review'],  $ctx),
    ]);
}

/* 列印紀錄不在這裡自己寫一支：一律走共用的 EGPrintLog.record() → PrintSignLog_API，
   來源代碼 'internal_audit' 已登錄在 print_log_lib.php 的 eg_print_sources()（ai-rules/23）。
   兩條寫入路徑＝同一次列印可能記兩筆，故刻意不留。 */

/* ============================ 儀表板（首頁分頁用） ============================ */
case 'dashboard': {
    iaReqView($perms);
    $year = (int)($_GET['year'] ?? substr($today, 0, 4));
    $out  = ['year' => $year];
    try {
        $q = $db->prepare("SELECT COUNT(*) FROM ia_case WHERE year=? AND COALESCE(is_deleted,0)=0");
        $q->execute([$year]); $out['case_cnt'] = (int)$q->fetchColumn();
        $q = $db->prepare("SELECT COUNT(*) FROM ia_case WHERE year=? AND COALESCE(is_deleted,0)=0 AND executed=1");
        $q->execute([$year]); $out['case_done'] = (int)$q->fetchColumn();
        $q = $db->prepare("SELECT COUNT(*) FROM ia_check WHERE year=? AND COALESCE(is_deleted,0)=0");
        $q->execute([$year]); $out['check_cnt'] = (int)$q->fetchColumn();
        $q = $db->prepare("SELECT stage, COUNT(*) c FROM ia_nc WHERE year=? AND COALESCE(is_deleted,0)=0 GROUP BY stage");
        $q->execute([$year]);
        $byStage = array_fill_keys(array_keys(IA_NC_STAGES), 0);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $byStage[(string)$r['stage']] = (int)$r['c'];
        $out['nc_by_stage'] = $byStage;
        $q = $db->prepare("SELECT nc_type, COUNT(*) c FROM ia_nc WHERE year=? AND COALESCE(is_deleted,0)=0 GROUP BY nc_type");
        $q->execute([$year]);
        $byType = array_fill_keys(array_keys(IA_NC_TYPES), 0);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (isset($byType[(string)$r['nc_type']])) $byType[(string)$r['nc_type']] = (int)$r['c'];
        }
        $out['nc_by_type'] = $byType;
        $q = $db->prepare("SELECT COUNT(*) FROM ia_nc WHERE year=? AND COALESCE(is_deleted,0)=0
                            AND stage<>'closed' AND due_date IS NOT NULL AND due_date < ?");
        $q->execute([$year, $today]); $out['nc_overdue'] = (int)$q->fetchColumn();
        $q = $db->prepare("SELECT nc_id, nc_no, dept_name, due_date, stage FROM ia_nc
                            WHERE year=? AND COALESCE(is_deleted,0)=0 AND stage<>'closed' AND due_date IS NOT NULL
                            ORDER BY due_date LIMIT 10");
        $q->execute([$year]); $out['nc_soon'] = $q->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
    $plan = ia_plan_get($db, $year);
    $out['has_plan'] = (bool)$plan;
    if ($plan) {
        $planned = count($plan['cells']); $actual = 0;
        foreach ($plan['cells'] as $k => $v) { if (isset($plan['actual'][$k])) $actual++; }
        $out['plan_planned'] = $planned;
        $out['plan_actual']  = $actual;
        $out['plan_extra']   = max(0, count($plan['actual']) - $actual);   // 沒排卻做了的
    }
    jout($out);
}


/* ============================ 受稽單位群組 ============================ */
case 'unit_list': {
    iaReqView($perms);
    jout(['units' => ia_audit_units($db), 'kinds' => IA_QUALIFY_KINDS]);
}

case 'unit_save': {
    iaReqAdmin($perms);
    $unitId  = (int)($_POST['unit_id'] ?? 0);
    $name    = trim((string)($_POST['unit_name'] ?? ''));
    $mainId  = (int)($_POST['main_dept_id'] ?? 0);
    $deptIds = json_decode((string)($_POST['dept_ids'] ?? '[]'), true);
    if (!is_array($deptIds)) $deptIds = [];
    $deptIds = array_values(array_unique(array_filter(array_map('intval', $deptIds))));
    if (!$mainId && $deptIds) $mainId = $deptIds[0];
    // 前端擋一次、後端同規則再擋一次（鐵律8）
    $err = ia_unit_validate($db, $unitId, $name, $mainId, $deptIds);
    if ($err !== '') jerr($err);

    $db->beginTransaction();
    try {
        if ($unitId) {
            $db->prepare("UPDATE ia_audit_unit SET unit_name=?, main_dept_id=?, is_active=1,
                              updated_at=NOW(), updated_by=? WHERE unit_id=?")
               ->execute([$name, $mainId, $uname, $unitId]);
        } else {
            $db->prepare("INSERT INTO ia_audit_unit (unit_name, main_dept_id, sort_order, is_active, updated_at, updated_by)
                          VALUES (?,?,(SELECT COALESCE(MAX(s.sort_order),0)+10 FROM (SELECT sort_order FROM ia_audit_unit) s),1,NOW(),?)")
               ->execute([$name, $mainId, $uname]);
            $unitId = (int)$db->lastInsertId();
        }
        $db->prepare("DELETE FROM ia_audit_unit_dept WHERE unit_id=?")->execute([$unitId]);
        $ins = $db->prepare("INSERT INTO ia_audit_unit_dept (unit_id, dept_id) VALUES (?,?)");
        foreach ($deptIds as $d) $ins->execute([$unitId, $d]);
        // 已建立的計畫表／通知單上，原本各自成欄的成員部門要併回代表部門，否則畫面會出現重複的欄
        $db->prepare("UPDATE ia_plan_dept SET dept_id=?, dept_name=? WHERE dept_id IN
                      (SELECT dept_id FROM ia_audit_unit_dept WHERE unit_id=?) AND dept_id<>?")
           ->execute([$mainId, $name, $unitId, $mainId]);
        $db->prepare("UPDATE ia_plan_dept SET dept_name=? WHERE dept_id=?")->execute([$name, $mainId]);
        $db->commit();
        jout(['unit_id' => $unitId]);
    } catch (Throwable $e) {
        $db->rollBack();
        // 同一個計畫表裡兩個成員部門都被列成欄時，併欄會撞 UNIQUE(plan_id,dept_id)
        if (strpos($e->getMessage(), '1062') !== false || stripos($e->getMessage(), 'Duplicate') !== false) {
            jerr('有年度計畫表同時列了這個群組裡的兩個以上部門當欄位，請先到該年度計畫表的「受稽單位」把多餘的欄取消勾選，再建立群組');
        }
        jerr('儲存失敗：' . $e->getMessage(), 500);
    }
}

case 'unit_delete': {
    iaReqAdmin($perms);
    $unitId = (int)($_POST['unit_id'] ?? 0);
    $db->beginTransaction();
    try {
        // 解散群組：成員部門各自變回獨立的受稽單位，既有資料仍掛在代表部門上不動
        $db->prepare("DELETE FROM ia_audit_unit_dept WHERE unit_id=?")->execute([$unitId]);
        $db->prepare("DELETE FROM ia_audit_unit WHERE unit_id=?")->execute([$unitId]);
        $db->commit();
        jout(['deleted' => true]);
    } catch (Throwable $e) { $db->rollBack(); jerr('刪除失敗：' . $e->getMessage(), 500); }
}

/* ============================ 稽核員／陪檢員資格名單 ============================ */
case 'qualify_get': {
    iaReqView($perms);
    jout(['kinds' => IA_QUALIFY_KINDS, 'map' => ia_qualify_map($db),
          'people' => eg_people_list($db, [])]);
}

case 'qualify_save': {
    iaReqAdmin($perms);
    $kind = (string)($_POST['kind'] ?? '');
    if (!isset(IA_QUALIFY_KINDS[$kind])) jerr('身分別不正確');
    $ids = json_decode((string)($_POST['user_ids'] ?? '[]'), true);
    if (!is_array($ids)) jerr('格式錯誤');
    $db->beginTransaction();
    try {
        ia_qualify_save($db, $kind, $ids, $uname);
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); jerr('儲存失敗：' . $e->getMessage(), 500); }
    // 存完讀回來確認（存不進去卻回成功，使用者只會一直重存）
    $back = ia_qualify_map($db);
    jout(['saved' => true, 'count' => count($back[$kind] ?? [])]);
}

default:
    jerr('無效的操作：' . $action);
}
