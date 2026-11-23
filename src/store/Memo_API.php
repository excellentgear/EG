<?php
/**
 * 簽呈（2-MM-01-15）API
 * 權限：memo_lib.php memo_perms()（roles module='memo_request'）；全體在職員工可開／查自己的單，
 * 簽核關卡上的人可看可批示；檢閱角色看全部「一般」等級；管理員看全部。
 */
$document_root = $_SERVER['DOCUMENT_ROOT'];
session_start();
require_once __DIR__ . '/../common/api_guard.php';
header('Content-Type: application/json; charset=utf-8');
include_once $document_root . '/EGsystem/src/common/_config.php';
include_once $document_root . '/EGsystem/src/common/DBConnection.php';
include_once $document_root . '/EGsystem/src/common/memo_lib.php';
include_once $document_root . '/EGsystem/src/common/interview_lib.php';   // 調查連結（簽呈↔訪談記錄）

function jout($a){ echo json_encode(array_merge(['ok'=>true], $a), JSON_UNESCAPED_UNICODE); exit; }
function jerr($msg, $code=400){ http_response_code($code); echo json_encode(['ok'=>false,'error'=>$msg], JSON_UNESCAPED_UNICODE); exit; }

try {
    $db = (new DBConnection())->getPDO();
    memo_ensure_schema($db);
} catch (Throwable $e) { jerr('DB連線失敗：' . $e->getMessage(), 500); }

$u = memo_current_user($db);
if (!$u) jerr('未登入', 401);
$uid   = (int)$u['id'];
$uname = (string)$u['user_cname'];
$perms = memo_perms($db, $u);
if (!$perms['canApply'] && !$perms['viewAny']) jerr('無簽呈使用權限（帳號非在職狀態）', 403);

$action = $_GET['action'] ?? $_POST['action'] ?? '';

/* 寫入類動作一律驗 CSRF（與全站其他頁面同一個慣例：session 內有 csrf_token 才驗） */
$WRITE = ['save','submit','decide','comment','delete','withdraw','save_settings','save_asdoc'];
if (in_array($action, $WRITE, true)) {
    $tok = (string)($_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (empty($_SESSION['memo_csrf']) || !hash_equals((string)$_SESSION['memo_csrf'], $tok)) jerr('連線憑證失效，請重新整理頁面後再試 (CSRF)', 403);
}

/** 我目前要批示的關卡（待簽且簽核人是我；管理員可代為批示任一待簽關卡） */
function memo_my_pending_step(array $memo, array $perms, int $uid, int $stepId = 0): ?array
{
    if ($memo['status'] !== 'submitted') return null;
    foreach ($memo['steps'] as $s) {
        if ((int)$s['round_no'] !== (int)$memo['round_no'] || $s['status'] !== 'pending') continue;
        if ($stepId && (int)$s['step_id'] !== $stepId) continue;
        if ((int)$s['assignee_id'] === $uid || $perms['isAdmin']) return $s;
    }
    return null;
}

function memo_clean_cosign($raw): array
{
    $out = []; $seen = [];
    foreach ((array)$raw as $c) {
        $d = (int)($c['dept_id'] ?? 0); $p = (int)($c['user_id'] ?? 0);
        if (!$d && !$p) continue;
        $k = $d . ':' . $p;
        if (isset($seen[$k])) continue;
        $seen[$k] = 1;
        $out[] = ['dept_id'=>$d, 'user_id'=>$p];
    }
    return array_slice($out, 0, MEMO_COSIGN_MAX);
}

switch ($action) {

case 'meta': {
    if (empty($_SESSION['memo_csrf'])) $_SESSION['memo_csrf'] = bin2hex(random_bytes(16));
    $doc = eg_asdoc_get($db, MEMO_ASDOC_MODULE);
    $depts = [];
    try { $depts = $db->query("SELECT id, name FROM department ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
    $people = [];
    try { $people = eg_people_list($db, ['all_posts'=>true]); } catch (Throwable $e) {}
    $stamps = [];
    if ($perms['canAdmin']) {
        try { $stamps = $db->query("SELECT id, tpl_name FROM stamp_template WHERE is_active=1 ORDER BY id")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
    }
    jout([
        'csrf'=>$_SESSION['memo_csrf'], 'perms'=>$perms, 'uid'=>$uid, 'uname'=>$uname,
        'settings'=>memo_settings($db), 'stamp_tpls'=>$stamps, 'routes'=>memo_routes($db, !$perms['canAdmin']),
        'as_doc'=>$doc, 'as_docs'=>$perms['canAdmin'] ? eg_asdoc_list($db) : [],
        'doc_no'=>eg_asdoc_no($doc), 'doc_name'=>$doc['doc_name'] ?? '簽呈',
        'company'=>eg_company_full_name($db), 'depts'=>$depts, 'people'=>$people,
        'identity'=>memo_user_identity($db, $uid), 'stamp_template'=>memo_stamp_template($db),
        'secrecy'=>MEMO_SECRECY, 'urgency'=>array_map(fn($x)=>$x[0], MEMO_URGENCY),
    ]);
}

/* ---------------- 清單：scope = mine 我提的／todo 待我批示／involved 我經手過／all 全部 ---------------- */
case 'list': {
    $scope = $_GET['scope'] ?? 'mine';
    $status = $_GET['status'] ?? '';
    $kw = trim((string)($_GET['kw'] ?? ''));
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per = (int)($_GET['per'] ?? 10); if (!in_array($per, [5,10,20,50], true)) $per = 10;
    $w = ["COALESCE(m.is_deleted,0)=0"]; $p = [];
    if ($scope === 'todo') {
        $w[] = "EXISTS (SELECT 1 FROM memo_step s WHERE s.memo_id=m.memo_id AND s.round_no=m.round_no AND s.status='pending' AND s.assignee_id=?)";
        $p[] = $uid;
    } elseif ($scope === 'involved') {
        $w[] = "(m.user_id=? OR m.created_by=? OR EXISTS (SELECT 1 FROM memo_step s WHERE s.memo_id=m.memo_id AND s.assignee_id=?))";
        array_push($p, $uid, $uid, $uid);
    } elseif ($scope === 'all') {
        if (!$perms['viewAny']) jerr('無檢視全部的權限', 403);
        [$vs, $vp] = memo_visible_sql($perms, $uid); $w[] = $vs; $p = array_merge($p, $vp);
    } else { $w[] = "(m.user_id=? OR m.created_by=?)"; array_push($p, $uid, $uid); }
    if (in_array($status, ['draft','submitted','approved','rejected'], true)) { $w[] = "m.status=?"; $p[] = $status; }
    if ($kw !== '') {
        $w[] = "(m.subject LIKE ? OR m.memo_no LIKE ? OR m.user_name LIKE ? OR m.dept_name LIKE ? OR m.content LIKE ?)";
        for ($i = 0; $i < 5; $i++) $p[] = "%$kw%";
    }
    $where = implode(' AND ', $w);
    $st = $db->prepare("SELECT COUNT(*) FROM memo_request m WHERE $where"); $st->execute($p);
    $total = (int)$st->fetchColumn();
    $st = $db->prepare("SELECT m.memo_id, m.memo_no, m.apply_date, m.user_id, m.user_name, m.dept_name, m.secrecy, m.urgency, m.subject,
                               m.status, m.due_date, m.created_by,
                               (SELECT GROUP_CONCAT(CONCAT(s.assignee_name,'（',CASE s.step_type WHEN 'dept' THEN '部門主管' WHEN 'gm' THEN '總經理' ELSE CONCAT('會辦',IFNULL(s.cosign_dept_name,'')) END,'）') SEPARATOR '、')
                                  FROM memo_step s WHERE s.memo_id=m.memo_id AND s.round_no=m.round_no AND s.status='pending') AS pending_who
                        FROM memo_request m WHERE $where ORDER BY m.apply_date DESC, m.memo_id DESC LIMIT " . (int)$per . " OFFSET " . (int)(($page-1)*$per));
    $st->execute($p);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $todo = $db->prepare("SELECT COUNT(DISTINCT m.memo_id) FROM memo_request m JOIN memo_step s ON s.memo_id=m.memo_id AND s.round_no=m.round_no
                          WHERE COALESCE(m.is_deleted,0)=0 AND m.status='submitted' AND s.status='pending' AND s.assignee_id=?");
    $todo->execute([$uid]);
    jout(['rows'=>$rows, 'total'=>$total, 'page'=>$page, 'per'=>$per, 'todo_count'=>(int)$todo->fetchColumn()]);
}

case 'get': {
    $memo = memo_row($db, (int)($_GET['memo_id'] ?? 0));
    if (!$memo) jerr('找不到簽呈');
    if (!memo_can_see($memo, $perms, $uid)) jerr('無權限檢視此簽呈', 403);
    $c = $db->prepare("SELECT comment_id, user_id, user_name, content, created_at FROM memo_comment WHERE memo_id=? ORDER BY comment_id");
    $c->execute([$memo['memo_id']]);
    $my = memo_my_pending_step($memo, $perms, $uid);
    $inv = memo_invest_links($db, $memo, $perms, $u);
    jout(['memo'=>$memo, 'comments'=>$c->fetchAll(PDO::FETCH_ASSOC), 'invest'=>$inv,
          'my_step'=>$my ? (int)$my['step_id'] : 0,
          'can_edit'=>in_array($memo['status'], ['draft','rejected'], true) && ((int)$memo['user_id'] === $uid || (int)$memo['created_by'] === $uid || $perms['canAdmin']),
          'can_withdraw'=>$memo['status'] === 'submitted' && ((int)$memo['user_id'] === $uid || $perms['canAdmin']),
          'can_delete'=>$perms['canAdmin'] || ($memo['status'] === 'draft' && ((int)$memo['user_id'] === $uid || (int)$memo['created_by'] === $uid)),
    ]);
}

/* ---------------- 儲存（新增／修改草稿與退回件） ---------------- */
case 'save': {
    $id = (int)($_POST['memo_id'] ?? 0);
    $applyDate = trim((string)($_POST['apply_date'] ?? ''));
    $subject = trim((string)($_POST['subject'] ?? ''));
    $content = trim((string)($_POST['content'] ?? ''));
    $secrecy = (string)($_POST['secrecy'] ?? 'normal');
    $urgency = (string)($_POST['urgency'] ?? 'normal');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $applyDate)) jerr('簽呈日期格式不正確');
    if (!isset(MEMO_SECRECY[$secrecy])) jerr('機密等級不正確');
    if (!isset(MEMO_URGENCY[$urgency])) jerr('時限不正確');
    if ($subject === '') jerr('請填寫主旨');
    if (mb_strlen($subject) > 300) jerr('主旨最多 300 字');
    $cosign = memo_clean_cosign(json_decode((string)($_POST['cosign'] ?? '[]'), true));
    $routeId = (int)($_POST['route_id'] ?? 0);
    if ($routeId) { $okr = false; foreach (memo_routes($db, true) as $r) if ($r['route_id'] === $routeId) $okr = true; if (!$okr) jerr('送簽模式不存在或已停用'); }
    $deptName = trim((string)($_POST['dept_name'] ?? '')); $posName = trim((string)($_POST['position_name'] ?? ''));
    $srcMemoId = (int)($_POST['src_memo_id'] ?? 0);   // 調查結果簽呈：原啟動調查簽呈
    if ($srcMemoId) {
        $sm = memo_row($db, $srcMemoId);
        if (!$sm || !memo_can_see($sm, $perms, $uid)) jerr('調查來源簽呈不存在或你無權限檢視');
        if ($id && $srcMemoId === $id) jerr('調查來源簽呈不能是自己');
    }
    try {
        if ($id) {
            $memo = memo_row($db, $id);
            if (!$memo) jerr('找不到簽呈');
            if (!in_array($memo['status'], ['draft','rejected'], true)) jerr('簽核中或已核准的簽呈不能修改（請重新整理）');
            if ((int)$memo['user_id'] !== $uid && (int)$memo['created_by'] !== $uid && !$perms['canAdmin']) jerr('只能修改自己的簽呈', 403);
            $no = $memo['memo_no'];
            if ($perms['canAdmin'] && isset($_POST['memo_no']) && trim($_POST['memo_no']) !== '') $no = trim($_POST['memo_no']);
            $db->prepare("UPDATE memo_request SET memo_no=?, apply_date=?, dept_name=?, position_name=?, secrecy=?, urgency=?, subject=?,
                                 content=?, cosign_json=?, route_id=?, src_memo_id=?, updated_at=NOW() WHERE memo_id=?")
               ->execute([$no, $applyDate, $deptName, $posName, $secrecy, $urgency, $subject, $content, json_encode($cosign), $routeId ?: null, $srcMemoId ?: null, $id]);
        } else {
            $owner = $uid;
            if ($perms['canAdmin'] && (int)($_POST['user_id'] ?? 0) > 0) $owner = (int)$_POST['user_id'];   // 管理員可代開
            $idn = memo_user_identity($db, $owner);
            if (!$deptName) $deptName = $idn['dept_name'];
            if (!$posName) $posName = $idn['position_name'];
            $no = ($perms['canAdmin'] && trim((string)($_POST['memo_no'] ?? '')) !== '') ? trim($_POST['memo_no']) : memo_next_no($db, $applyDate);
            $db->prepare("INSERT INTO memo_request (memo_no, apply_date, user_id, user_name, dept_id, dept_name, position_name, secrecy, urgency,
                                 subject, content, cosign_json, route_id, src_memo_id, status, created_by, created_at, updated_at)
                          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'draft', ?, NOW(), NOW())")
               ->execute([$no, $applyDate, $owner, $idn['user_name'], $idn['dept_id'], $deptName, $posName, $secrecy, $urgency,
                          $subject, $content, json_encode($cosign), $routeId ?: null, $srcMemoId ?: null, $uid]);
            $id = (int)$db->lastInsertId();
        }
    } catch (Throwable $e) { jerr('儲存失敗：' . $e->getMessage(), 500); }
    jout(['memo_id'=>$id]);
}

/* ---------------- 送出（含退回後重送＝新一輪） ---------------- */
case 'submit': {
    $id = (int)($_POST['memo_id'] ?? 0);
    $memo = memo_row($db, $id);
    if (!$memo) jerr('找不到簽呈');
    if ((int)$memo['user_id'] !== $uid && (int)$memo['created_by'] !== $uid && !$perms['canAdmin']) jerr('只能送出自己的簽呈', 403);
    if (!in_array($memo['status'], ['draft','rejected'], true)) jerr('此簽呈目前狀態不可送出（請重新整理清單）');
    if (trim($memo['subject']) === '') jerr('請填寫主旨');
    if (trim((string)$memo['content']) === '') jerr('請填寫說明');
    if ((int)($memo['src_memo_id'] ?? 0) > 0 && preg_match('/【待填[^】]*】/u', $memo['subject'] . "
" . $memo['content'], $pm)) jerr('調查結果簽呈還有未填寫的欄位：' . $pm[0] . '，請補完再送出');
    try {
        $now = $db->query("SELECT NOW() n, CURDATE() d")->fetch(PDO::FETCH_ASSOC);
        $round = (int)$memo['round_no'] + ($memo['status'] === 'rejected' ? 1 : 0);
        $db->beginTransaction();
        $db->prepare("UPDATE memo_request SET status='submitted', round_no=?, submit_date=?, submitted_at=?, due_date=?, reject_note=NULL,
                             closed_date=NULL, closed_at=NULL, updated_at=NOW() WHERE memo_id=?")
           ->execute([$round, $now['d'], $now['n'], memo_due_date($now['d'], $memo['urgency']), $id]);
        $memo = memo_row($db, $id);
        eg_approval_submit($db, 'memo_request', $id, 'memo', $uid, $uname);
        memo_build_steps($db, $memo);
        $db->commit();
        $res = memo_advance($db, $id, $uid);      // 通知在交易之外（失敗不影響單據）
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); jerr('送出失敗：' . $e->getMessage(), 500); }
    jout(['status'=>$res === 'approved' ? 'approved' : 'submitted']);
}

/* ---------------- 批示：核准／退回（退回強制填原因，ai-rules/17） ---------------- */
case 'decide': {
    $id = (int)($_POST['memo_id'] ?? 0);
    $stepId = (int)($_POST['step_id'] ?? 0);
    $decision = (string)($_POST['decision'] ?? '');
    $note = trim((string)($_POST['note'] ?? ''));
    $bizDate = trim((string)($_POST['decide_date'] ?? '')) ?: date('Y-m-d');
    if (!in_array($decision, ['approved','rejected'], true)) jerr('決定值不正確');
    if ($decision === 'rejected' && $note === '') jerr('退回必須填寫原因');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $bizDate)) jerr('批示日期格式不正確');
    if (mb_strlen($note) > 1000) jerr('批示意見最多 1000 字');
    $memo = memo_row($db, $id);
    if (!$memo) jerr('找不到簽呈');
    if ($memo['status'] !== 'submitted') jerr('此簽呈目前沒有待批示的項目（請重新整理清單）');
    $step = memo_my_pending_step($memo, $perms, $uid, $stepId);
    if (!$step) jerr('這一關目前不是由您批示（可能已被處理，請重新整理）', 403);
    try {
        $db->beginTransaction();
        $db->prepare("UPDATE memo_step SET status=?, note=?, decided_by=?, decided_by_name=?, decided_date=?, decided_at=NOW()
                      WHERE step_id=? AND status='pending'")
           ->execute([$decision, $note ?: null, $uid, $uname, $bizDate, $step['step_id']]);
        $a = eg_approval_latest($db, 'memo_request', $id);
        if ($a && $a['status'] === 'pending' && $decision === 'rejected') eg_approval_decide($db, (int)$a['id'], $uid, $uname, 'rejected', $note);
        memo_close_event($db, (int)$step['event_id']);
        if ($decision === 'rejected') {
            memo_close_open_steps($db, $id, (int)$memo['round_no'], '本輪已被退回，未處理');
            $db->prepare("UPDATE memo_request SET status='rejected', reject_note=?, closed_date=NULL, closed_at=NULL, updated_at=NOW() WHERE memo_id=?")
               ->execute([$note, $id]);
        }
        $db->commit();
        if ($decision === 'rejected') {
            $memo['status'] = 'rejected';
            memo_notify_result($db, $memo, 'rejected', $note, $uid, $uname);
            jout(['status'=>'rejected']);
        }
        $res = memo_advance($db, $id, $uid);
        if ($res === 'approved') {
            $a = eg_approval_latest($db, 'memo_request', $id);
            if ($a && $a['status'] === 'pending') eg_approval_decide($db, (int)$a['id'], $uid, $uname, 'approved', null);
        }
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); jerr('批示失敗：' . $e->getMessage(), 500); }
    jout(['status'=>$res === 'approved' ? 'approved' : 'submitted']);
}

/* ---------------- 回覆（討論）：關係人皆可回，通知提簽人與所有關卡簽核人 ---------------- */
case 'comment': {
    $id = (int)($_POST['memo_id'] ?? 0);
    $text = trim((string)($_POST['content'] ?? ''));
    if ($text === '') jerr('請輸入回覆內容');
    if (mb_strlen($text) > 1000) jerr('回覆最多 1000 字');
    $memo = memo_row($db, $id);
    if (!$memo) jerr('找不到簽呈');
    if ($memo['status'] === 'draft') jerr('草稿尚未送出，不能回覆');
    if (!memo_can_see($memo, $perms, $uid)) jerr('無權限回覆此簽呈', 403);
    $db->prepare("INSERT INTO memo_comment (memo_id, user_id, user_name, content, created_at) VALUES (?,?,?,?,NOW())")
       ->execute([$id, $uid, $uname, $text]);
    memo_notify_comment($db, $memo, $uid, $uname, $text);
    jout([]);
}

/* ---------------- 撤回：簽核中、還沒有任何一關批示過才可撤回 ---------------- */
case 'withdraw': {
    $id = (int)($_POST['memo_id'] ?? 0);
    $memo = memo_row($db, $id);
    if (!$memo) jerr('找不到簽呈');
    if ((int)$memo['user_id'] !== $uid && !$perms['canAdmin']) jerr('只能撤回自己的簽呈', 403);
    if ($memo['status'] !== 'submitted') jerr('此簽呈目前不是簽核中（請重新整理）');
    foreach ($memo['steps'] as $s) {
        if ((int)$s['round_no'] === (int)$memo['round_no'] && in_array($s['status'], ['approved','rejected'], true))
            jerr('已有關卡批示過，不能撤回（可請簽核人退回）');
    }
    $db->beginTransaction();
    memo_close_open_steps($db, $id, (int)$memo['round_no'], '提簽人撤回');
    $db->prepare("UPDATE memo_request SET status='draft', updated_at=NOW() WHERE memo_id=?")->execute([$id]);
    $db->commit();
    jout([]);
}

case 'delete': {
    $id = (int)($_POST['memo_id'] ?? 0);
    $memo = memo_row($db, $id);
    if (!$memo) jerr('找不到簽呈');
    $own = ((int)$memo['user_id'] === $uid || (int)$memo['created_by'] === $uid);
    if (!$perms['canAdmin'] && !($own && $memo['status'] === 'draft')) jerr('只有草稿可由本人刪除，其餘僅管理員可刪', 403);
    foreach ($memo['steps'] as $s) memo_close_event($db, (int)$s['event_id']);
    $db->prepare("UPDATE memo_request SET is_deleted=1, updated_at=NOW() WHERE memo_id=?")->execute([$id]);
    jout([]);
}

/* 可當「調查來源」的簽呈：已核准、使用者看得到的（調查結果簽呈用） */
case 'invest_sources': {
    [$vis, $va] = memo_visible_sql($perms, $uid);
    $st = $db->prepare("SELECT m.memo_id, m.memo_no, m.subject FROM memo_request m WHERE COALESCE(m.is_deleted,0)=0 AND m.status='approved' AND $vis ORDER BY m.memo_id DESC LIMIT 200");
    $st->execute($va);
    jout(['rows'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
}

/* 依原啟動調查簽呈組出「調查結果簽呈」的主旨與說明草稿；系統查得到的（簽呈編號與核准日、訪談日期與人員、附件）帶入，查不到的一律留【待填】 */
case 'invest_template': {
    $src = memo_row($db, (int)($_GET['src_memo_id'] ?? 0));
    if (!$src || !memo_can_see($src, $perms, $uid)) jerr('找不到調查來源簽呈或你無權限檢視');
    if ($src['status'] !== 'approved') jerr('調查來源簽呈尚未核准');
    jout(memo_invest_template($db, $src, $perms, $u));
}

case 'save_settings': {
    if (!$perms['canAdmin']) jerr('無設定權限', 403);
    foreach (['memo_need_gm','memo_cosign_before_gm'] as $k) if (isset($_POST[$k])) memo_save_setting($db, $k, (int)$_POST[$k] ? 1 : 0);
    foreach (['memo_view_hr_levels','memo_view_sup_levels'] as $k) if (isset($_POST[$k])) memo_save_setting($db, $k, implode(',', memo_levels_clean(explode(',', (string)$_POST[$k]))));
    if (isset($_POST['memo_stamp_tpl_id'])) memo_save_setting($db, 'memo_stamp_tpl_id', (int)$_POST['memo_stamp_tpl_id'] ?: '');
    jout(['settings'=>memo_settings($db)]);
}

case 'save_routes': {
    if (!$perms['canAdmin']) jerr('無設定權限', 403);
    $rows = json_decode((string)($_POST['routes'] ?? '[]'), true);
    if (!is_array($rows) || !$rows) jerr('至少要有一種送簽模式');
    $clean = []; $defs = 0;
    foreach ($rows as $i => $r) {
        $name = trim((string)($r['route_name'] ?? ''));
        if ($name === '') jerr('第 ' . ($i + 1) . ' 列：請填寫模式名稱');
        if (mb_strlen($name) > 60) jerr('第 ' . ($i + 1) . ' 列：名稱最多 60 字');
        $x = ['id'=>(int)($r['route_id'] ?? 0), 'name'=>$name, 'nd'=>!empty($r['need_dept']) ? 1 : 0, 'ng'=>!empty($r['need_gm']) ? 1 : 0,
              'ac'=>!empty($r['allow_cosign']) ? 1 : 0, 'df'=>!empty($r['is_default']) ? 1 : 0, 'ia'=>!isset($r['is_active']) || $r['is_active'] ? 1 : 0];
        if ($x['df'] && !$x['ia']) jerr('預設的送簽模式不能停用');
        $defs += $x['df']; $clean[] = $x;
    }
    if ($defs !== 1) jerr('請指定一個（且只有一個）預設送簽模式');
    try {
        $db->beginTransaction();
        $keep = [];
        foreach ($clean as $i => $x) {
            if ($x['id']) {
                $db->prepare('UPDATE memo_route SET route_name=?, need_dept=?, need_gm=?, allow_cosign=?, is_default=?, is_active=?, sort_no=? WHERE route_id=?')
                   ->execute([$x['name'], $x['nd'], $x['ng'], $x['ac'], $x['df'], $x['ia'], $i + 1, $x['id']]);
                $keep[] = $x['id'];
            } else {
                $db->prepare('INSERT INTO memo_route (route_name, need_dept, need_gm, allow_cosign, is_default, is_active, sort_no) VALUES (?,?,?,?,?,?,?)')
                   ->execute([$x['name'], $x['nd'], $x['ng'], $x['ac'], $x['df'], $x['ia'], $i + 1]);
                $keep[] = (int)$db->lastInsertId();
            }
        }
        // 被移除的模式：已被簽呈使用就改成停用，沒人用才真的刪
        foreach (memo_routes($db) as $r) {
            if (in_array($r['route_id'], $keep, true)) continue;
            $st = $db->prepare('SELECT COUNT(*) FROM memo_request WHERE route_id=?'); $st->execute([$r['route_id']]);
            if ((int)$st->fetchColumn() > 0) $db->prepare('UPDATE memo_route SET is_active=1-1, is_default=0 WHERE route_id=?')->execute([$r['route_id']]);
            else $db->prepare('DELETE FROM memo_route WHERE route_id=?')->execute([$r['route_id']]);
        }
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); jerr('儲存失敗：' . $e->getMessage(), 500); }
    jout(['routes'=>memo_routes($db)]);
}

case 'save_asdoc': {
    if (!$perms['canAdmin']) jerr('無設定權限', 403);
    eg_asdoc_save($db, MEMO_ASDOC_MODULE, (int)($_POST['doc_id'] ?? 0), $uname);
    $doc = eg_asdoc_get($db, MEMO_ASDOC_MODULE);
    jout(['as_doc'=>$doc, 'doc_no'=>eg_asdoc_no($doc), 'doc_name'=>$doc['doc_name'] ?? '簽呈']);
}

/* ---------------- 列印用資料（版次依簽呈日期回推，ai-rules/16 第三之四節） ---------------- */
case 'print_meta': {
    $memo = memo_row($db, (int)($_GET['memo_id'] ?? 0));
    if (!$memo) jerr('找不到簽呈');
    if (!memo_can_see($memo, $perms, $uid)) jerr('無權限檢視此簽呈', 403);
    $doc = eg_asdoc_get($db, MEMO_ASDOC_MODULE);
    jout(['memo'=>$memo, 'company'=>eg_company_full_name($db), 'doc_name'=>$doc['doc_name'] ?? '簽呈',
          'doc_no'=>eg_asdoc_no_asof($db, MEMO_ASDOC_MODULE, $memo['apply_date'] ?: null),
          'stamp_template'=>memo_stamp_template($db)]);
}

default:
    jerr('無效的操作');
}
