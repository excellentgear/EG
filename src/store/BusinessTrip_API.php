<?php
/**
 * 公出單（2-MM-01-06）API
 * 權限：business_trip_lib.php bt_perms()（roles module='business_trip'）；
 *       **全體在職員工都能開/查自己的單**，canViewAll/canAdmin 才看得到別人的（fail-closed）。
 * 簽核：單位主管一關；模組設定可免簽核（僅系統管理者可改）。自動核准依 ai-rules/21 錯開時間戳。
 */
$document_root = $_SERVER['DOCUMENT_ROOT'];
session_start();
header('Content-Type: application/json; charset=utf-8');
include_once $document_root . '/EGsystem/src/common/_config.php';
include_once $document_root . '/EGsystem/src/common/DBConnection.php';
include_once $document_root . '/EGsystem/src/common/business_trip_lib.php';
include_once $document_root . '/EGsystem/src/common/date_fmt_lib.php';

function jout($a){ echo json_encode(array_merge(['ok'=>true], $a), JSON_UNESCAPED_UNICODE); exit; }
function jerr($msg, $code=400){ http_response_code($code); echo json_encode(['ok'=>false,'error'=>$msg], JSON_UNESCAPED_UNICODE); exit; }

try {
    $db = (new DBConnection())->getPDO();
    bt_ensure_schema($db);
} catch (Throwable $e) { jerr('DB連線失敗：' . $e->getMessage(), 500); }

$u = bt_current_user($db);
if (!$u) jerr('未登入', 401);
$uid   = (int)$u['id'];
$uname = (string)$u['user_cname'];
$perms = bt_perms($db, $u);
if (!$perms['canApply'] && !$perms['canViewAll']) jerr('無公出單使用權限（帳號非在職狀態）', 403);

$action = $_GET['action'] ?? $_POST['action'] ?? '';

/** 這筆單目前的核准人是不是我（含管理者） */
function bt_can_decide(PDO $db, array $trip, array $perms, int $uid): bool
{
    if ($trip['status'] !== 'submitted') return false;
    if ($perms['isAdmin']) return true;
    return (int)($trip['approver_id'] ?? 0) === $uid;
}

/** 我看不看得到這張單 */
function bt_can_see(array $trip, array $perms, int $uid): bool
{
    return $perms['canViewAll'] || (int)$trip['user_id'] === $uid
        || (int)($trip['approver_id'] ?? 0) === $uid || (int)($trip['created_by'] ?? 0) === $uid;
}

switch ($action) {

/* ---------------- meta：設定、AS 文件、人員、部門、公司名 ---------------- */
case 'meta': {
    $set = bt_settings($db);
    $doc = eg_asdoc_get($db, BT_ASDOC_MODULE);
    $depts = [];
    try { $depts = $db->query("SELECT id, name FROM department ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC); }
    catch (Throwable $e) {}
    $people = [];
    if ($perms['canAdmin']) {           // 只有管理員能代開，才需要全體人員清單
        try { $people = eg_people_list($db, []); } catch (Throwable $e) { $people = []; }
    }
    $stampTpls = [];
    if ($perms['canAdmin']) {
        try {
            $stampTpls = $db->query("SELECT p.id, p.tpl_name, t.type_name
                                      FROM stamp_template p LEFT JOIN stamp_type t ON t.id=p.type_id
                                      WHERE p.is_active=1 ORDER BY p.tpl_name")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {}
    }
    jout([
        'me'       => ['id'=>$uid, 'name'=>$uname] + bt_user_identity($db, $uid),
        'perms'    => $perms,
        'settings' => $set,
        'sign_sources' => BT_SIGN_SOURCES,
        'as_doc'   => $doc,
        'as_docs'  => $perms['canAdmin'] ? eg_asdoc_list($db) : [],
        'doc_no'   => eg_asdoc_no($doc),
        'doc_name' => $doc['doc_name'] ?? '公出單',
        'company'  => eg_company_full_name($db),
        'depts'    => $depts,
        'people'   => $people,
        'stamp_tpls' => $stampTpls,
        'stamp_template' => bt_stamp_template($db),
        'today'    => date('Y-m-d'),
    ]);
}

/* ---------------- 清單（分頁在前端做；後端一律回符合條件的全部，統計才不會只算一頁） ---------------- */
case 'list': {
    $scope  = (string)($_GET['scope'] ?? 'mine');       // mine / all / pending（待我核准）
    $kw     = trim((string)($_GET['kw'] ?? ''));
    $status = (string)($_GET['status'] ?? '');
    $from   = (string)($_GET['date_from'] ?? '');
    $to     = (string)($_GET['date_to'] ?? '');
    $w = ['COALESCE(t.is_deleted,0)=0']; $p = [];
    if ($scope === 'all') {
        if (!$perms['canViewAll']) jerr('無查看全部公出單的權限', 403);
    } elseif ($scope === 'pending') {
        $w[] = "t.status='submitted' AND (t.approver_id=? " . ($perms['isAdmin'] ? "OR 1=1" : "") . ")";
        $p[] = $uid;
    } else {
        $w[] = '(t.user_id=? OR t.created_by=?)';
        $p[] = $uid; $p[] = $uid;
    }
    if ($status !== '') { $w[] = 't.status=?'; $p[] = $status; }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $w[] = 't.date_to>=?';   $p[] = $from; }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   { $w[] = 't.date_from<=?'; $p[] = $to; }
    if ($kw !== '') {
        // 全表搜尋：畫面上看得到的欄位都掃（多關鍵字＝每個都要命中，可分散在不同欄位）
        foreach (preg_split('/\s+/', $kw) as $k) {
            if ($k === '') continue;
            $w[] = '(t.trip_no LIKE ? OR t.user_name LIKE ? OR t.dept_name LIKE ? OR t.position_name LIKE ?
                     OR t.location LIKE ? OR t.reason LIKE ? OR t.approver_name LIKE ?)';
            for ($i = 0; $i < 7; $i++) $p[] = '%' . $k . '%';
        }
    }
    $sql = "SELECT t.* FROM business_trip t WHERE " . implode(' AND ', $w)
         . " ORDER BY t.date_from DESC, t.trip_id DESC";
    $st = $db->prepare($sql);
    $st->execute($p);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['period']     = bt_period_text($r);
        $r['can_edit']   = ((int)$r['user_id'] === $uid || (int)$r['created_by'] === $uid || $perms['canAdmin'])
                           && in_array($r['status'], ['draft', 'rejected'], true);
        $r['can_decide'] = bt_can_decide($db, $r, $perms, $uid);
    }
    unset($r);
    jout(['rows'=>$rows, 'count'=>count($rows)]);
}

/* ---------------- 單筆 ---------------- */
case 'get': {
    $trip = bt_trip_row($db, (int)($_GET['trip_id'] ?? 0));
    if (!$trip) jerr('找不到公出單');
    if (!bt_can_see($trip, $perms, $uid)) jerr('無權限檢視此公出單', 403);
    $trip['can_edit']   = ((int)$trip['user_id'] === $uid || (int)$trip['created_by'] === $uid || $perms['canAdmin'])
                          && in_array($trip['status'], ['draft', 'rejected'], true);
    $trip['can_decide'] = bt_can_decide($db, $trip, $perms, $uid);
    $trip['period']     = bt_period_text($trip);
    $trip['signers']    = bt_print_signers($db, $trip);
    jout(['trip'=>$trip]);
}

/* ---------------- 新增／修改 ---------------- */
case 'save': {
    $tripId = (int)($_POST['trip_id'] ?? 0);
    $forUid = (int)($_POST['user_id'] ?? 0) ?: $uid;
    if ($forUid !== $uid && !$perms['canAdmin']) jerr('只有公出單管理員能代其他人開單', 403);
    if (!$perms['canApply'] && !$perms['canAdmin']) jerr('無開立公出單權限', 403);

    $applyDate = trim((string)($_POST['apply_date'] ?? ''));
    $dateFrom  = trim((string)($_POST['date_from'] ?? ''));
    $dateTo    = trim((string)($_POST['date_to'] ?? '')) ?: $dateFrom;
    foreach (['單據日期'=>$applyDate, '公出起日'=>$dateFrom, '公出迄日'=>$dateTo] as $lb => $d) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) jerr($lb . '格式不正確');
    }
    if ($dateTo < $dateFrom) jerr('公出迄日不可早於起日');
    $timeFrom = bt_norm_time($_POST['time_from'] ?? '');
    $timeTo   = bt_norm_time($_POST['time_to'] ?? '');
    if ($timeFrom === null) jerr('開始時間不是合法時刻（時 0-23、分 0-59）');
    if ($timeTo === null)   jerr('結束時間不是合法時刻（時 0-23、分 0-59）');
    if ($dateFrom === $dateTo && $timeFrom && $timeTo && $timeTo <= $timeFrom)
        jerr('同一天的結束時間不可早於或等於開始時間');
    $location = trim((string)($_POST['location'] ?? ''));
    $reason   = trim((string)($_POST['reason'] ?? ''));
    if ($location === '') jerr('請填公出地點');
    if ($reason === '')   jerr('請填事由');
    if (mb_strlen($reason) > 2000) jerr('事由過長（上限 2000 字）');

    $deptId   = ($_POST['dept_id'] ?? '') === '' ? null : (int)$_POST['dept_id'];
    $deptName = trim((string)($_POST['dept_name'] ?? ''));
    $posName  = trim((string)($_POST['position_name'] ?? ''));
    $ident    = bt_user_identity($db, $forUid);
    if ($deptId === null) $deptId = $ident['dept_id'];
    if ($deptName === '') {
        $deptName = $ident['dept_name'];
        if ($deptId && $deptId !== $ident['dept_id']) {
            $dq = $db->prepare("SELECT name FROM department WHERE id=?");
            $dq->execute([$deptId]);
            $deptName = (string)$dq->fetchColumn();
        }
    }
    if ($posName === '') $posName = $ident['position_name'];

    // 每日時段明細（多天且各天時段不同時才需要）
    $days = json_decode((string)($_POST['days'] ?? '[]'), true);
    $cleanDays = [];
    if (is_array($days)) {
        foreach ($days as $i => $d) {
            $dd = trim((string)($d['day_date'] ?? ''));
            if ($dd === '') continue;
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dd)) jerr('每日時段第 ' . ($i + 1) . ' 列日期格式不正確');
            if ($dd < $dateFrom || $dd > $dateTo) jerr('每日時段第 ' . ($i + 1) . ' 列的日期不在公出期間內');
            $s = bt_norm_time($d['start_time'] ?? '');
            $e = bt_norm_time($d['end_time'] ?? '');
            if ($s === null || $e === null) jerr('每日時段第 ' . ($i + 1) . ' 列的時間不是合法時刻');
            if ($s && $e && $e <= $s) jerr('每日時段第 ' . ($i + 1) . ' 列的結束時間不可早於或等於開始時間');
            $cleanDays[] = ['day_date'=>$dd, 'start_time'=>$s, 'end_time'=>$e];
        }
    }
    usort($cleanDays, fn($a, $b) => strcmp($a['day_date'], $b['day_date']));

    try {
        $db->beginTransaction();
        if ($tripId) {
            $old = bt_trip_row($db, $tripId);
            if (!$old) { $db->rollBack(); jerr('找不到公出單'); }
            if (!in_array($old['status'], ['draft', 'rejected'], true)) { $db->rollBack(); jerr('已送出的公出單不可修改（請先退回或另開新單）'); }
            if ((int)$old['user_id'] !== $uid && (int)$old['created_by'] !== $uid && !$perms['canAdmin']) { $db->rollBack(); jerr('只能修改自己的公出單', 403); }
            $db->prepare("UPDATE business_trip SET apply_date=?, user_id=?, user_name=?, dept_id=?, dept_name=?, position_name=?,
                                 date_from=?, date_to=?, time_from=?, time_to=?, location=?, reason=?, updated_at=NOW()
                          WHERE trip_id=?")
               ->execute([$applyDate, $forUid, $ident['user_name'], $deptId, $deptName, $posName,
                          $dateFrom, $dateTo, $timeFrom ?: null, $timeTo ?: null, $location, $reason, $tripId]);
        } else {
            $db->prepare("INSERT INTO business_trip
                          (trip_no, apply_date, user_id, user_name, dept_id, dept_name, position_name,
                           date_from, date_to, time_from, time_to, location, reason, status, source, created_by, created_at, updated_at)
                          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?, 'draft', 'manual', ?, NOW(), NOW())")
               ->execute([bt_next_no($db, $applyDate), $applyDate, $forUid, $ident['user_name'], $deptId, $deptName, $posName,
                          $dateFrom, $dateTo, $timeFrom ?: null, $timeTo ?: null, $location, $reason, $uid]);
            $tripId = (int)$db->lastInsertId();
        }
        bt_replace_days($db, $tripId, $cleanDays);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        jerr('儲存失敗：' . $e->getMessage(), 500);
    }
    jout(['trip_id'=>$tripId]);
}

/* ---------------- 送出（依設定決定要不要真的送主管） ---------------- */
case 'submit': {
    $tripId = (int)($_POST['trip_id'] ?? 0);
    $trip   = bt_trip_row($db, $tripId);
    if (!$trip) jerr('找不到公出單');
    if ((int)$trip['user_id'] !== $uid && (int)$trip['created_by'] !== $uid && !$perms['canAdmin'])
        jerr('只能送出自己的公出單', 403);
    if (!in_array($trip['status'], ['draft', 'rejected'], true)) jerr('此公出單目前狀態不可送出（請重新整理清單）');

    $set       = bt_settings($db);
    $needAppr  = (int)$set['bt_need_approval'] === 1;
    // 時間一律取 DB 的時間，不要用 PHP date()——本機 PHP 時區與 MySQL 不同（PHP=UTC、MySQL=本地），
    // 混用會讓 submitted_at 比 approved_at 早 8 小時，業務日期也可能差一天。
    $nowRow    = $db->query("SELECT NOW() AS n, CURDATE() AS d")->fetch(PDO::FETCH_ASSOC);
    $now       = (string)$nowRow['n'];
    $today     = (string)$nowRow['d'];
    $signer    = bt_resolve_approver($db, $trip['dept_id'] !== null ? (int)$trip['dept_id'] : null,
                                     (int)$trip['user_id'], !$needAppr);
    try {
        if (!$needAppr || !$signer) {
            // 自動核准：業務日期＝送出日，精確時間錯開 5~30 分鐘不跨日（ai-rules/21）
            $autoAt   = bt_auto_sign_time($now);
            $autoNote = !$needAppr ? '模組設定為「免簽核」，送出即視同核准'
                                   : '查無可核准的單位主管與最高核准人員，系統自動核准';
            $aid = eg_approval_submit($db, 'business_trip', $tripId, 'manager', $uid, $uname);
            eg_approval_decide($db, $aid, $signer['id'] ?? $uid, $signer['name'] ?? $uname, 'approved', $autoNote);
            $db->prepare("UPDATE business_trip SET status='approved', submit_date=?, submitted_at=?,
                                 approver_id=?, approver_name=?, is_delegated=?, is_auto=1, auto_note=?,
                                 approved_date=?, approved_at=?, updated_at=NOW()
                          WHERE trip_id=?")
               ->execute([$today, $now, $signer['id'] ?? null, $signer['name'] ?? '',
                          !empty($signer['is_delegated']) ? 1 : 0, $autoNote, $today, $autoAt, $tripId]);
            jout(['status'=>'approved', 'auto'=>1, 'note'=>$autoNote,
                  'approver'=>$signer['name'] ?? '', 'approved_at'=>$autoAt]);
        }
        $aid = eg_approval_submit($db, 'business_trip', $tripId, 'manager', $uid, $uname);
        $db->prepare("UPDATE business_trip SET status='submitted', submit_date=?, submitted_at=?,
                             approver_id=?, approver_name=?, is_delegated=?, is_auto=0, auto_note=NULL, updated_at=NOW()
                      WHERE trip_id=?")
           ->execute([$today, $now, $signer['id'], $signer['name'], $signer['is_delegated'] ? 1 : 0, $tripId]);
        $trip['status'] = 'submitted';
        $ev = bt_notify_approver($db, $trip, (int)$signer['id'], $uid);
        if ($ev) eg_approval_set_live_event($db, $aid, $ev);
    } catch (Throwable $e) { jerr('送出失敗：' . $e->getMessage(), 500); }
    jout(['status'=>'submitted', 'approver'=>$signer['name'], 'reason'=>$signer['reason']]);
}

/* ---------------- 核准／退回（退回強制填原因，ai-rules/17） ---------------- */
case 'decide': {
    $tripId   = (int)($_POST['trip_id'] ?? 0);
    $decision = (string)($_POST['decision'] ?? '');
    $note     = trim((string)($_POST['note'] ?? ''));
    $bizDate  = trim((string)($_POST['decide_date'] ?? '')) ?: date('Y-m-d');
    if (!in_array($decision, ['approved', 'rejected'], true)) jerr('決定值不正確');
    if ($decision === 'rejected' && $note === '') jerr('退回必須填寫原因');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $bizDate)) jerr('核准日期格式不正確');
    $trip = bt_trip_row($db, $tripId);
    if (!$trip) jerr('找不到公出單');
    if ($trip['status'] !== 'submitted') jerr('此公出單目前沒有待核准的項目（請重新整理清單）');
    if (!bt_can_decide($db, $trip, $perms, $uid)) jerr('您不是此公出單的核准人', 403);
    try {
        $appr = eg_approval_latest($db, 'business_trip', $tripId, 'manager');
        if ($appr && $appr['status'] === 'pending') {
            eg_approval_decide($db, (int)$appr['id'], $uid, $uname, $decision, $note ?: null);
        }
        $db->prepare("UPDATE business_trip SET status=?, approver_id=?, approver_name=?, decide_note=?,
                             approved_date=?, approved_at=NOW(), updated_at=NOW()
                      WHERE trip_id=?")
           ->execute([$decision, $uid, $uname, $note ?: null,
                      $decision === 'approved' ? $bizDate : null, $tripId]);
        bt_close_notice($db, $tripId);
        bt_notify_result($db, $trip, (int)$trip['user_id'], $decision, $note, $uid, $uname);
    } catch (Throwable $e) { jerr('決行失敗：' . $e->getMessage(), 500); }
    jout(['status'=>$decision]);
}

/* ---------------- 刪除（草稿本人可刪；其餘僅管理員） ---------------- */
case 'delete': {
    $tripId = (int)($_POST['trip_id'] ?? 0);
    $trip   = bt_trip_row($db, $tripId);
    if (!$trip) jerr('找不到公出單');
    $mine = ((int)$trip['user_id'] === $uid || (int)$trip['created_by'] === $uid);
    if (!$perms['canAdmin'] && !($mine && in_array($trip['status'], ['draft', 'rejected'], true)))
        jerr('只有草稿/已退回的單可由本人刪除，其餘請洽公出單管理員', 403);
    $db->prepare("UPDATE business_trip SET is_deleted=1, updated_at=NOW() WHERE trip_id=?")->execute([$tripId]);
    bt_close_notice($db, $tripId);
    jout(['trip_id'=>$tripId]);
}

/* ---------------- 模組設定（免簽核僅系統管理者可改） ---------------- */
case 'save_settings': {
    if (!$perms['canAdmin']) jerr('無設定權限', 403);
    $set = bt_settings($db);
    if (isset($_POST['bt_need_approval'])) {
        if (!$perms['isAdmin']) jerr('「是否需要主管簽核」僅系統管理者可修改', 403);
        bt_save_setting($db, 'bt_need_approval', (int)$_POST['bt_need_approval'] === 1 ? 1 : 0);
    }
    if (isset($_POST['bt_auto_from_training'])) bt_save_setting($db, 'bt_auto_from_training', (int)$_POST['bt_auto_from_training'] === 1 ? 1 : 0);
    if (isset($_POST['bt_stamp_tpl_id']))       bt_save_setting($db, 'bt_stamp_tpl_id', (int)$_POST['bt_stamp_tpl_id'] ?: '');
    foreach (['bt_sign_acc', 'bt_sign_section', 'bt_sign_group'] as $k) {
        if (!isset($_POST[$k])) continue;
        $v = (string)$_POST[$k];
        if (!array_key_exists($v, BT_SIGN_SOURCES)) jerr('簽章欄來源設定值不正確');
        bt_save_setting($db, $k, $v);
    }
    jout(['settings'=>bt_settings($db)]);
}

/* ---------------- AS 文件編號綁定（走共用 asdoc_lib，ai-rules/16 第一之三節） ---------------- */
case 'save_asdoc': {
    if (!$perms['canAdmin']) jerr('無設定權限', 403);
    eg_asdoc_save($db, BT_ASDOC_MODULE, (int)($_POST['doc_id'] ?? 0), $uname);
    $doc = eg_asdoc_get($db, BT_ASDOC_MODULE);
    jout(['as_doc'=>$doc, 'doc_no'=>eg_asdoc_no($doc), 'doc_name'=>$doc['doc_name'] ?? '公出單']);
}

/* ---------------- 列印用資料（版次依業務日期回推，ai-rules/16 第三之四節） ---------------- */
case 'print_meta': {
    $trip = bt_trip_row($db, (int)($_GET['trip_id'] ?? 0));
    if (!$trip) jerr('找不到公出單');
    if (!bt_can_see($trip, $perms, $uid)) jerr('無權限檢視此公出單', 403);
    $doc = eg_asdoc_get($db, BT_ASDOC_MODULE);
    jout([
        'trip'     => $trip,
        'company'  => eg_company_full_name($db),
        'doc_name' => $doc['doc_name'] ?? '公出單',
        'doc_no'   => eg_asdoc_no_asof($db, BT_ASDOC_MODULE, $trip['apply_date'] ?: null),
        'signers'  => bt_print_signers($db, $trip),
        'settings' => bt_settings($db),
        'stamp_template' => bt_stamp_template($db),
    ]);
}

/* ---------------- 從教育訓練外訓場次手動帶入（補產生沒開到的單） ---------------- */
case 'from_training': {
    $sid = (int)($_POST['session_id'] ?? 0);
    if (!$perms['canAdmin']) jerr('僅公出單管理員可批次帶入', 403);
    if (!$sid) jerr('請指定訓練場次');
    $r = bt_create_from_training($db, $sid);
    jout($r);
}

/* ---------------- 可帶入的外訓場次清單 ---------------- */
case 'training_sessions': {
    if (!$perms['canAdmin']) jerr('僅公出單管理員可批次帶入', 403);
    $year = (int)($_GET['year'] ?? date('Y'));
    $st = $db->prepare("SELECT s.session_id, s.course_name, s.org_unit, s.done_date, s.status,
                               (SELECT COUNT(*) FROM training_attendee a WHERE a.session_id=s.session_id) AS att_cnt,
                               (SELECT COUNT(*) FROM business_trip t WHERE t.ref_type='training_session'
                                  AND t.ref_id=s.session_id AND COALESCE(t.is_deleted,0)=0) AS trip_cnt
                        FROM training_session s
                        WHERE s.train_type='external' AND s.year=? AND s.status IN ('scheduled','done')
                        ORDER BY s.done_date DESC, s.session_id DESC");
    $st->execute([$year]);
    jout(['rows'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
}

default:
    jerr('未知的動作：' . $action);
}
