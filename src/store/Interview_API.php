<?php
/**
 * 訪談記錄 API（行政人事紀錄）— 共用邏輯 src/common/interview_lib.php
 * 可見範圍：訪談人／建立人／人事部門／本頁管理員／全站管理員；寫入類動作一律驗 CSRF。
 */
$document_root = $_SERVER['DOCUMENT_ROOT'];
session_start();
require_once __DIR__ . '/../common/api_guard.php';
header('Content-Type: application/json; charset=utf-8');
include_once $document_root . '/EGsystem/src/common/_config.php';
include_once $document_root . '/EGsystem/src/common/DBConnection.php';
include_once $document_root . '/EGsystem/src/common/people_lib.php';
include_once $document_root . '/EGsystem/src/common/interview_lib.php';
include_once $document_root . '/EGsystem/src/common/place_lib.php';

function jout($a){ echo json_encode(array_merge(['ok'=>true], $a), JSON_UNESCAPED_UNICODE); exit; }
function jerr($msg, $code=400){ http_response_code($code); echo json_encode(['ok'=>false,'error'=>$msg], JSON_UNESCAPED_UNICODE); exit; }
function ivS($v, int $max = 500): string { return mb_substr(trim((string)$v), 0, $max); }
function ivDate($v): ?string { $v = trim((string)$v); return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null; }
function ivTime($v): ?string {
    $v = trim((string)$v); if ($v === '') return null;
    if (preg_match('/^(\d{1,2}):?(\d{2})$/', $v, $m) && (int)$m[1] <= 23 && (int)$m[2] <= 59) return sprintf('%02d:%02d', $m[1], $m[2]);
    return null;
}

try {
    $db = (new DBConnection())->getPDO();
    iv_ensure_schema($db);
    eg_place_ensure($db);
    cm_ensure_schema($db);
} catch (Throwable $e) { jerr('DB連線失敗：' . $e->getMessage(), 500); }

$u = memo_current_user($db);
if (!$u) jerr('未登入', 401);
$uid = (int)$u['id']; $uname = (string)$u['user_cname'];
$P = iv_perms($db, $u);
if (!$P['canUse']) jerr('無訪談記錄使用權限（帳號非在職狀態）', 403);
$action = $_GET['action'] ?? $_POST['action'] ?? '';

$WRITE = ['place_add','save_places','save','delete','finish','reopen','improve_add','improve_del','improve_to_track','save_types','upload','file_delete'];
if (in_array($action, $WRITE, true)) {
    $tok = (string)($_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (empty($_SESSION['iv_csrf']) || !hash_equals((string)$_SESSION['iv_csrf'], $tok)) jerr('連線憑證失效，請重新整理頁面後再試 (CSRF)', 403);
}

function ivRow(PDO $db, int $id): ?array {
    $st = $db->prepare("SELECT r.* FROM iv_record r WHERE r.iv_id=? AND r.is_deleted=0");
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
/** 只有訪談人、建立人與管理員能改；已完成要先「重新開啟」 */
function ivCanEdit(array $r, array $P): bool {
    return $P['canAdmin'] || (int)$r['interviewer_id'] === $P['uid'] || (int)$r['created_by'] === $P['uid'];
}

if ($action === 'meta') {
    if (empty($_SESSION['iv_csrf'])) $_SESSION['iv_csrf'] = bin2hex(random_bytes(16));
    $people = array_map(function($p){
        return ['key'=>$p['post_key'] ?? ($p['id'].':'), 'id'=>(int)$p['id'], 'name'=>$p['user_cname'], 'dept_id'=>(int)$p['dept_id'], 'dsort'=>(int)($p['dept_sort'] ?? 0),
                'dept'=>$p['dept_name'], 'pos'=>$p['position_name']];
    }, eg_people_list($db, ['all_posts'=>true]));
    jout(['csrf'=>$_SESSION['iv_csrf'], 'uid'=>$uid, 'uname'=>$uname, 'perms'=>$P,
          'types'=>iv_types($db, false), 'places'=>eg_place_list($db), 'people'=>$people, 'company'=>eg_company_full_name($db)]);
}

if ($action === 'list') {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per = (int)($_GET['per'] ?? 20); if (!in_array($per, [5,10,20,50], true)) $per = 20;
    [$vis, $va] = iv_visible_sql($P);
    $w = ["r.is_deleted=0", $vis]; $a = $va;
    if (($t = (int)($_GET['type_id'] ?? 0)) > 0) { $w[] = 'r.type_id=?'; $a[] = $t; }
    $stt = ivS($_GET['status'] ?? '', 10);
    if (in_array($stt, ['draft','done'], true)) { $w[] = 'r.status=?'; $a[] = $stt; }
    if (!empty($_GET['due'])) { $w[] = "r.next_date IS NOT NULL AND r.next_date<=CURDATE() AND r.status='done'"; }
    $kw = ivS($_GET['kw'] ?? '', 60);
    foreach (preg_split('/\s+/', $kw, -1, PREG_SPLIT_NO_EMPTY) as $t) {
        $w[] = "(r.iv_no LIKE ? OR r.interviewee_name LIKE ? OR r.interviewer_name LIKE ? OR r.interviewee_dept LIKE ? OR r.type_name LIKE ?)";
        for ($i = 0; $i < 5; $i++) $a[] = '%' . $t . '%';
    }
    $where = implode(' AND ', $w);
    $st = $db->prepare("SELECT COUNT(*) FROM iv_record r WHERE $where"); $st->execute($a);
    $total = (int)$st->fetchColumn();
    $st = $db->prepare("SELECT r.iv_id, r.iv_no, r.type_name, r.iv_date, r.interviewer_name, r.interviewee_name, r.interviewee_dept, r.status, r.next_date, r.memo_id,
                               (SELECT COUNT(*) FROM iv_improve i WHERE i.iv_id=r.iv_id AND i.is_deleted=0) AS imp_cnt
                        FROM iv_record r WHERE $where ORDER BY r.iv_date DESC, r.iv_id DESC
                        LIMIT " . (int)$per . " OFFSET " . (($page - 1) * $per));
    $st->execute($a);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $mp = memo_perms($db, $u);
    foreach ($rows as &$rw) {
        $rw['memo_no'] = ''; $rw['memo_can'] = 0;
        if ((int)$rw['memo_id'] > 0 && ($mm = memo_row($db, (int)$rw['memo_id']))) {
            $rw['memo_no'] = $mm['memo_no'];
            $rw['memo_can'] = memo_can_see($mm, $mp, $uid) ? 1 : 0;
        }
    }
    unset($rw);
    jout(['rows'=>$rows, 'total'=>$total, 'page'=>$page, 'per'=>$per]);
}

if ($action === 'get') {
    $r = ivRow($db, (int)($_GET['iv_id'] ?? 0));
    if (!$r) jerr('找不到這筆訪談記錄', 404);
    if (!iv_can_see($r, $P)) jerr('無權限檢視此訪談記錄', 403);
    $st = $db->prepare("SELECT i.imp_id, i.content, i.track_id, t.track_no, t.is_closed
                        FROM iv_improve i LEFT JOIN comm_track t ON t.track_id=i.track_id AND t.is_deleted=0
                        WHERE i.iv_id=? AND i.is_deleted=0 ORDER BY i.imp_id");
    $st->execute([(int)$r['iv_id']]);
    $memo = null;
    if ((int)$r['memo_id'] > 0) {   // 簽呈主旨只在使用者本來就看得到那張簽呈時才帶出
        $m = memo_row($db, (int)$r['memo_id']);
        if ($m && memo_can_see($m, memo_perms($db, $u), $uid)) $memo = ['memo_id'=>(int)$m['memo_id'], 'memo_no'=>$m['memo_no'], 'subject'=>$m['subject']];
        else $memo = ['memo_id'=>(int)$r['memo_id'], 'memo_no'=>'（無權限檢視）', 'subject'=>''];
    }
    jout(['row'=>$r, 'improves'=>$st->fetchAll(PDO::FETCH_ASSOC), 'memo'=>$memo, 'can_edit'=>ivCanEdit($r, $P), 'files'=>iv_files($db, (int)$r['iv_id'])]);
}

/* 可綁定的簽呈：只列使用者自己看得到的（簽呈機密規則照舊） */
if ($action === 'memo_search') {
    $mp = memo_perms($db, $u);
    [$vis, $va] = memo_visible_sql($mp, $uid);
    $kw = ivS($_GET['kw'] ?? '', 60);
    $w = ["m.is_deleted=0", $vis]; $a = $va;
    foreach (preg_split('/\s+/', $kw, -1, PREG_SPLIT_NO_EMPTY) as $t) { $w[] = "(m.memo_no LIKE ? OR m.subject LIKE ?)"; $a[] = "%$t%"; $a[] = "%$t%"; }
    $st = $db->prepare("SELECT m.memo_id, m.memo_no, m.subject FROM memo_request m WHERE " . implode(' AND ', $w) . " ORDER BY m.memo_id DESC LIMIT 100");
    $st->execute($a);
    jout(['rows'=>$st->fetchAll(PDO::FETCH_ASSOC)]);
}

if ($action === 'save') {
    $id = (int)($_POST['iv_id'] ?? 0);
    $old = $id ? ivRow($db, $id) : null;
    if ($id && !$old) jerr('找不到這筆訪談記錄', 404);
    if ($old) {
        if (!ivCanEdit($old, $P)) jerr('只有訪談人、建立人或管理員能修改', 403);
        if ($old['status'] === 'done') jerr('已完成的訪談記錄不能直接修改，請先「重新開啟」（請重新整理頁面）', 409);
    }
    $date = ivDate($_POST['iv_date'] ?? '');
    if (!$date) jerr('請填訪談日期');
    $typeId = (int)($_POST['type_id'] ?? 0);
    $st = $db->prepare("SELECT * FROM iv_type WHERE type_id=? AND (is_active=1 OR type_id=?)");
    $st->execute([$typeId, $old ? (int)$old['type_id'] : 0]);
    $type = $st->fetch(PDO::FETCH_ASSOC);
    if (!$type) jerr('請選擇訪談類型');
    $intvId = (int)($_POST['interviewer_id'] ?? 0); $inteeId = (int)($_POST['interviewee_id'] ?? 0);
    if (!$intvId) jerr('請選擇訪談人');
    if (!$inteeId) jerr('請選擇受訪人');
    if ($intvId === $inteeId) jerr('訪談人與受訪人不能是同一人');
    $memoId = (int)($_POST['memo_id'] ?? 0);
    if ((int)$type['need_memo'] && !$memoId) jerr('「' . $type['name'] . '」要綁定簽呈');
    if ($memoId) {
        $m = memo_row($db, $memoId);
        if (!$m || !memo_can_see($m, memo_perms($db, $u), $uid)) jerr('不能綁定這張簽呈（找不到或你無權限看）');
    }
    $content = ivS($_POST['content'] ?? '', 20000);
    $next = ivDate($_POST['next_date'] ?? '');
    if ($next && $next <= $date) jerr('下次約談日必須晚於訪談日期');
    $s1 = ivTime($_POST['start_time'] ?? ''); $s2 = ivTime($_POST['end_time'] ?? '');
    if ($s1 && $s2 && $s2 < $s1) jerr('結束時間不能早於開始時間');
    $a = iv_identity($db, $intvId, (int)($_POST['interviewer_dept_id'] ?? 0), $date);
    $b = iv_identity($db, $inteeId, (int)($_POST['interviewee_dept_id'] ?? 0), $date);
    $now = $db->query("SELECT NOW()")->fetchColumn();
    $vals = [(int)$type['type_id'], $type['name'], $memoId ?: null, $date, $s1, $s2, ivS($_POST['place'] ?? '', 100),
             $intvId, $a['name'], $a['dept'], $a['pos'], $inteeId, $b['name'], $b['dept_id'] ?: null, $b['dept'], $b['pos'],
             $content, ivS($_POST['follow'] ?? '', 4000), $next];
    try {
        if ($old) {
            $db->prepare("UPDATE iv_record SET type_id=?, type_name=?, memo_id=?, iv_date=?, start_time=?, end_time=?, place=?,
                          interviewer_id=?, interviewer_name=?, interviewer_dept=?, interviewer_pos=?,
                          interviewee_id=?, interviewee_name=?, interviewee_dept_id=?, interviewee_dept=?, interviewee_pos=?,
                          content=?, follow=?, next_date=?, updated_at=? WHERE iv_id=?")->execute(array_merge($vals, [$now, $id]));
        } else {
            $db->prepare("INSERT INTO iv_record (type_id, type_name, memo_id, iv_date, start_time, end_time, place,
                          interviewer_id, interviewer_name, interviewer_dept, interviewer_pos,
                          interviewee_id, interviewee_name, interviewee_dept_id, interviewee_dept, interviewee_pos,
                          content, follow, next_date, iv_no, created_by, created_at, updated_at)
                          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
               ->execute(array_merge($vals, [iv_next_no($db, $date), $uid, $now, $now]));
            $id = (int)$db->lastInsertId();
        }
    } catch (Throwable $e) { jerr('儲存失敗：' . $e->getMessage(), 500); }
    jout(['iv_id'=>$id]);
}

if ($action === 'finish' || $action === 'reopen') {
    $r = ivRow($db, (int)($_POST['iv_id'] ?? 0));
    if (!$r) jerr('找不到這筆訪談記錄', 404);
    if (!ivCanEdit($r, $P)) jerr('只有訪談人、建立人或管理員能操作', 403);
    if ($action === 'finish') {
        if ($r['status'] === 'done') jerr('這筆已經完成了，請重新整理頁面', 409);
        if (trim((string)$r['content']) === '') jerr('談話內容還沒有填，不能完成');
        $db->prepare("UPDATE iv_record SET status='done', done_at=NOW(), updated_at=NOW() WHERE iv_id=?")->execute([(int)$r['iv_id']]);
    } else {
        if ($r['status'] !== 'done') jerr('這筆不是已完成狀態，請重新整理頁面', 409);
        if (!$P['canAdmin']) jerr('只有管理員能重新開啟已完成的訪談記錄', 403);
        $db->prepare("UPDATE iv_record SET status='draft', done_at=NULL, updated_at=NOW() WHERE iv_id=?")->execute([(int)$r['iv_id']]);
    }
    jout([]);
}

if ($action === 'delete') {
    $r = ivRow($db, (int)($_POST['iv_id'] ?? 0));
    if (!$r) jerr('找不到這筆訪談記錄', 404);
    if (!$P['canAdmin'] && !(ivCanEdit($r, $P) && $r['status'] === 'draft')) jerr('只有草稿可由本人刪除，其餘僅管理員可刪', 403);
    $db->prepare("UPDATE iv_record SET is_deleted=1, updated_at=NOW() WHERE iv_id=?")->execute([(int)$r['iv_id']]);
    jout([]);
}

/* ---- 附件（訪談記錄簽章結果掃描檔等）：看得到這筆就下載得到；上傳／刪除限訪談人、建立人、管理員，已完成的也可上傳（簽章結果通常在完成後才掃描）---- */
if ($action === 'upload') {
    $r = ivRow($db, (int)($_POST['iv_id'] ?? 0));
    if (!$r) jerr('找不到這筆訪談記錄', 404);
    if (!ivCanEdit($r, $P)) jerr('只有訪談人、建立人或管理員能上傳附件', 403);
    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) jerr('檔案上傳失敗，請重新選擇');
    $f = $_FILES['file'];
    if ($f['size'] > 50 * 1024 * 1024) jerr('單一檔案請小於 50MB');
    $orig = (string)$f['name'];
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if ($ext !== '' && !preg_match('/^[a-z0-9]{1,8}$/', $ext)) jerr('副檔名不正確');
    if (in_array($ext, ['php','phtml','exe','bat','cmd','com','scr','vbs','js','jar','msi'], true)) jerr('不接受這種檔案類型');
    $dir = iv_attach_dir($db);
    require_once __DIR__ . '/../common/attach_lib.php';
    if (!eg_attach_ensure_dir($dir)) jerr('附件資料夾無法建立，請洽管理員檢查路徑設定');
    $now = $db->query("SELECT NOW()")->fetchColumn();   // 檔名時間與 DB 同一個時區
    $name = 'iv_' . preg_replace('/\D/', '', $now) . '_' . bin2hex(random_bytes(4)) . ($ext ? '.' . $ext : '');
    if (!@move_uploaded_file($f['tmp_name'], $dir . $name)) jerr('檔案寫入失敗，請洽管理員檢查資料夾權限');
    try {
        $db->prepare("INSERT INTO iv_file (iv_id, file_name, original_name, file_size, uploaded_by, created_at) VALUES (?,?,?,?,?,?)")
           ->execute([(int)$r['iv_id'], $name, mb_substr($orig, 0, 255), (int)$f['size'], $uid, $now]);
    } catch (Throwable $e) { @unlink($dir . $name); jerr('儲存失敗：' . $e->getMessage(), 500); }
    jout(['file_id'=>(int)$db->lastInsertId(), 'files'=>iv_files($db, (int)$r['iv_id'])]);
}
if ($action === 'file_delete') {
    $st = $db->prepare("SELECT * FROM iv_file WHERE file_id=? AND is_deleted=0");
    $st->execute([(int)($_POST['file_id'] ?? 0)]);
    $fl = $st->fetch(PDO::FETCH_ASSOC);
    if (!$fl) jerr('找不到這個附件，請重新整理', 404);
    $r = ivRow($db, (int)$fl['iv_id']);
    if (!$r || !ivCanEdit($r, $P)) jerr('只有訪談人、建立人或管理員能刪除附件', 403);
    $db->prepare("UPDATE iv_file SET is_deleted=1 WHERE file_id=?")->execute([(int)$fl['file_id']]);   // 軟刪除，檔案留在 NAS 供稽核
    jout(['files'=>iv_files($db, (int)$r['iv_id'])]);
}
if ($action === 'download') {
    $st = $db->prepare("SELECT * FROM iv_file WHERE file_id=? AND is_deleted=0");
    $st->execute([(int)($_GET['id'] ?? 0)]);
    $fl = $st->fetch(PDO::FETCH_ASSOC);
    if (!$fl) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); exit('查無此附件'); }
    $r = ivRow($db, (int)$fl['iv_id']);
    if (!$r || !iv_can_see($r, $P)) { http_response_code(403); header('Content-Type: text/plain; charset=utf-8'); exit('無權限檢視此訪談記錄的附件'); }
    $name = (string)$fl['file_name'];
    if ($name === '' || strpbrk($name, "/\\") !== false || strpos($name, '..') !== false) { http_response_code(400); header('Content-Type: text/plain; charset=utf-8'); exit('檔名不合法'); }
    require_once __DIR__ . '/../common/attach_lib.php';
    $path = iv_attach_dir($db) . $name;
    if (!is_file($path)) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); exit('檔案不存在（可能已被移除）'); }
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $mimes = ['pdf'=>'application/pdf','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp','txt'=>'text/plain; charset=utf-8'];
    header('Content-Type: ' . ($mimes[$ext] ?? 'application/octet-stream'));
    header('Content-Length: ' . filesize($path));
    eg_attach_send_disposition((string)($fl['original_name'] ?: $name));
    readfile($path);
    exit;
}

/* ---- 改善事項 ---- */
if ($action === 'improve_add') {
    $r = ivRow($db, (int)($_POST['iv_id'] ?? 0));
    if (!$r) jerr('找不到這筆訪談記錄', 404);
    if (!ivCanEdit($r, $P)) jerr('只有訪談人、建立人或管理員能操作', 403);
    $c = ivS($_POST['content'] ?? '', 1000);
    if ($c === '') jerr('請填改善事項內容');
    // 去識別化提醒：內容含受訪人姓名時擋下（人名一旦進了追蹤表，就等於把個別員工的談話洩到品質系統）
    if ($r['interviewee_name'] && mb_strpos($c, (string)$r['interviewee_name']) !== false) jerr('改善事項不可包含受訪人姓名，請改寫成不含個人資料的客觀描述');
    $db->prepare("INSERT INTO iv_improve (iv_id, content, created_by, created_at) VALUES (?,?,?,NOW())")->execute([(int)$r['iv_id'], $c, $uid]);
    jout(['imp_id'=>(int)$db->lastInsertId()]);
}
if ($action === 'improve_del') {
    $st = $db->prepare("SELECT i.*, r.interviewer_id, r.created_by FROM iv_improve i JOIN iv_record r ON r.iv_id=i.iv_id WHERE i.imp_id=? AND i.is_deleted=0");
    $st->execute([(int)($_POST['imp_id'] ?? 0)]);
    $i = $st->fetch(PDO::FETCH_ASSOC);
    if (!$i) jerr('找不到這項改善事項', 404);
    if (!$P['canAdmin'] && (int)$i['interviewer_id'] !== $uid && (int)$i['created_by'] !== $uid) jerr('無權限', 403);
    if ((int)$i['track_id'] > 0) jerr('這一項已轉入措施追蹤表，不能在這裡刪除（請到溝通管理處理）', 409);
    $db->prepare("UPDATE iv_improve SET is_deleted=1 WHERE imp_id=?")->execute([(int)$i['imp_id']]);
    jout([]);
}
if ($action === 'improve_to_track') {
    $st = $db->prepare("SELECT i.*, r.interviewer_id, r.created_by, r.iv_date, r.status FROM iv_improve i JOIN iv_record r ON r.iv_id=i.iv_id WHERE i.imp_id=? AND i.is_deleted=0");
    $st->execute([(int)($_POST['imp_id'] ?? 0)]);
    $i = $st->fetch(PDO::FETCH_ASSOC);
    if (!$i) jerr('找不到這項改善事項', 404);
    if (!$P['canAdmin'] && (int)$i['interviewer_id'] !== $uid && (int)$i['created_by'] !== $uid) jerr('無權限', 403);
    if ((int)$i['track_id'] > 0) {   // 點開即刷新鐵則：已轉過的不重複建立
        $c = $db->prepare("SELECT 1 FROM comm_track WHERE track_id=? AND is_deleted=0"); $c->execute([(int)$i['track_id']]);
        if ($c->fetchColumn()) jerr('這一項已經轉入追蹤表了，請重新整理後查看', 409);
    }
    $ownerId = (int)($_POST['owner_id'] ?? 0);
    $due = ivDate($_POST['due_date'] ?? '');
    if (!$ownerId) jerr('請選擇負責人');
    if (!$due) jerr('請填預計完成日');
    $odId = (int)($_POST['owner_dept_id'] ?? 0);
    $catId = (int)$db->query("SELECT cat_id FROM comm_track_cat WHERE kind='employee' AND is_active=1 ORDER BY cat_id LIMIT 1")->fetchColumn();
    $now = $db->query("SELECT NOW()")->fetchColumn();
    try {
        $db->beginTransaction();
        $db->prepare("INSERT INTO comm_track (track_no, party, cat_id, content, react_date, action, owner_id, owner_name, owner_dept_id, owner_dept_name, due_date,
                        created_by, created_by_name, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([cm_track_no_next($db, (string)$i['iv_date']), '員工反映（訪談）', $catId ?: null, $i['content'], $i['iv_date'], ivS($_POST['action_text'] ?? '', 4000),
                      $ownerId, cm_user_name($db, $ownerId), $odId ?: null, $odId ? cm_dept_name($db, $odId) : null, $due, $uid, $uname, $now, $now]);
        $tid = (int)$db->lastInsertId();
        $db->prepare("UPDATE iv_improve SET track_id=? WHERE imp_id=?")->execute([$tid, (int)$i['imp_id']]);
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); jerr('轉入失敗：' . $e->getMessage(), 500); }
    jout(['track_id'=>$tid]);
}

/* ---- 地點選單：任何人填單時可新增一個，管理員可整批維護 ---- */
if ($action === 'place_add') {
    try { jout(['name'=>eg_place_add($db, (string)($_POST['name'] ?? ''))]); } catch (RuntimeException $e) { jerr($e->getMessage()); }
}
if ($action === 'save_places') {
    if (!$P['canAdmin']) jerr('需要訪談記錄管理員權限', 403);
    $rows = json_decode((string)($_POST['places'] ?? '[]'), true);
    if (!is_array($rows)) jerr('資料格式錯誤');
    try { eg_place_save($db, $rows); } catch (RuntimeException $e) { jerr($e->getMessage()); } catch (Throwable $e) { jerr('儲存失敗：' . $e->getMessage(), 500); }
    jout([]);
}

/* ---- 類型設定（本頁管理員） ---- */
if ($action === 'save_types') {
    if (!$P['canAdmin']) jerr('需要訪談記錄管理員權限', 403);
    $rows = json_decode((string)($_POST['types'] ?? '[]'), true);
    if (!is_array($rows) || !$rows) jerr('至少要有一種訪談類型');
    $seen = [];
    foreach ($rows as $r) {
        $n = ivS($r['name'] ?? '', 40);
        if ($n === '') jerr('訪談類型名稱不能空白');
        if (isset($seen[$n])) jerr('訪談類型名稱重複：' . $n);
        $seen[$n] = 1;
    }
    try {
        $db->beginTransaction();
        $keep = [];
        foreach ($rows as $k => $r) {
            $n = ivS($r['name'], 40); $tid = (int)($r['type_id'] ?? 0);
            $nm = !empty($r['need_memo']) ? 1 : 0; $nx = !empty($r['need_next']) ? 1 : 0; $gd = trim((string)($r['guide'] ?? '')); if (mb_strlen($gd) > 2000) $gd = mb_substr($gd, 0, 2000); $ac = array_key_exists('is_active', $r) && !$r['is_active'] ? 0 : 1;
            if ($tid) {
                $db->prepare("UPDATE iv_type SET name=?, need_memo=?, need_next=?, is_active=?, sort_order=?, guide=? WHERE type_id=?")->execute([$n, $nm, $nx, $ac, ($k+1)*10, $gd, $tid]);
                $db->prepare("UPDATE iv_record SET type_name=? WHERE type_id=?")->execute([$n, $tid]);
            } else {
                $db->prepare("INSERT INTO iv_type (name, need_memo, need_next, is_active, sort_order, guide) VALUES (?,?,?,?,?,?)")->execute([$n, $nm, $nx, $ac, ($k+1)*10, $gd]);
                $tid = (int)$db->lastInsertId();
            }
            $keep[] = $tid;
        }
        foreach ($db->query("SELECT type_id FROM iv_type")->fetchAll(PDO::FETCH_COLUMN) as $tid) {
            if (in_array((int)$tid, $keep, true)) continue;
            $c = $db->prepare("SELECT COUNT(*) FROM iv_record WHERE type_id=?"); $c->execute([$tid]);
            if ((int)$c->fetchColumn()) $db->prepare("UPDATE iv_type SET is_active=0 WHERE type_id=?")->execute([$tid]);
            else $db->prepare("DELETE FROM iv_type WHERE type_id=?")->execute([$tid]);
        }
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); jerr('儲存失敗：' . $e->getMessage(), 500); }
    jout([]);
}

jerr('無效的操作');
