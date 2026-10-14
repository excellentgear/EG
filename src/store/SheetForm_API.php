<?php
/**
 * 表格模板 API（sheet_form_lib.php 的資料存取層）
 * 讀：GET；寫：POST，一律 CSRF（session 鍵 sf_csrf，沒登入先擋 401 再驗 CSRF，免得講錯原因）。
 */
$document_root = $_SERVER['DOCUMENT_ROOT'];
session_start();
require_once __DIR__ . '/../common/api_guard.php';   // 在職狀態守門（離職/留停者一律 403）
header('Content-Type: application/json; charset=utf-8');
include_once $document_root . '/EGsystem/src/common/_config.php';
include_once $document_root . '/EGsystem/src/common/DBConnection.php';
include_once $document_root . '/EGsystem/src/common/sheet_form_lib.php';
include_once $document_root . '/EGsystem/src/common/people_lib.php';
include_once $document_root . '/EGsystem/src/common/print_log_lib.php';

function jout($a) { echo json_encode(array_merge(['ok'=>true], $a), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); exit; }
function jerr($msg, $code = 400, $extra = []) { http_response_code($code); echo json_encode(array_merge(['ok'=>false, 'error'=>$msg], $extra), JSON_UNESCAPED_UNICODE); exit; }

try { $db = (new DBConnection())->getPDO(); sf_ensure_schema($db); }
catch (Throwable $e) { jerr('DB連線失敗：' . $e->getMessage(), 500); }

$u = sf_current_user($db);
if (!$u) jerr('未登入', 401);
$uid = (int)$u['id'];
$perms = sf_perms($db, $u);
$action = $_GET['action'] ?? $_POST['action'] ?? '';

if (empty($_SESSION['sf_csrf'])) $_SESSION['sf_csrf'] = bin2hex(random_bytes(16));
$csrf = (string)$_SESSION['sf_csrf'];
function need_csrf() {
    global $csrf;
    $t = (string)($_POST['csrf'] ?? '');
    if ($t === '' || !hash_equals($csrf, $t)) jerr('CSRF token 驗證失敗，請重新整理頁面', 403, ['code'=>'CSRF']);
}
function need_admin() { global $perms; if (!$perms['canAdmin']) jerr('需要模板管理權限', 403); }

try {
switch ($action) {

case 'meta': {
    $people = [];
    foreach (eg_people_list($db, []) as $p) $people[] = (string)($p['user_cname'] ?? '');
    $people = array_values(array_filter(array_unique($people), 'strlen'));
    jout(['perms'=>$perms, 'uid'=>$uid, 'uname'=>$u['user_cname'], 'today'=>date('Y-m-d'), 'people'=>$people, 'csrf'=>$csrf,
          'features'=>SF_FEATURES]);
}

/* ------------------------------------------------------------ 模板 ------------------------------------------------------------ */
case 'template_list': {
    $rows = sf_template_list($db);
    if (!$perms['canAdmin']) $rows = array_values(array_filter($rows, fn($r) => $r['status'] === 'active'));
    jout(['templates'=>$rows]);
}

case 'template_get': {
    $t = sf_template_get($db, (int)($_GET['id'] ?? 0));
    if (!$t) jerr('找不到此模板', 404);
    if ($t['status'] !== 'active' && !$perms['canAdmin']) jerr('此模板已停用', 403);
    $t['fields'] = json_decode((string)$t['fields_json'], true) ?: [];
    $t['book'] = json_decode((string)$t['book_json'], true);
    unset($t['fields_json'], $t['book_json']);
    $t['as_doc'] = eg_asdoc_get($db, sf_asdoc_module((int)$t['id']));
    jout(['template'=>$t]);
}

case 'template_save': {
    need_csrf(); need_admin();
    try {
        $id = sf_template_save($db, $uid, (int)($_POST['id'] ?? 0), (string)($_POST['name'] ?? ''),
            (string)($_POST['paper_size'] ?? 'A4'), (string)($_POST['orientation'] ?? 'landscape'), (string)($_POST['book_json'] ?? ''));
    } catch (InvalidArgumentException $e) { jerr($e->getMessage()); }
    if (isset($_POST['as_doc_id'])) eg_asdoc_save($db, sf_asdoc_module($id), (int)$_POST['as_doc_id'], (string)$u['user_cname']);
    jout(['id'=>$id]);
}

case 'template_status': {
    need_csrf(); need_admin();
    $st = ($_POST['status'] ?? '') === 'archived' ? 'archived' : 'active';
    $db->prepare("UPDATE sf_template SET status=?, modified_by=?, modified_at=NOW() WHERE id=?")->execute([$st, $uid, (int)($_POST['id'] ?? 0)]);
    jout([]);
}

case 'template_delete': {
    need_csrf(); need_admin();
    $id = (int)($_POST['id'] ?? 0);
    $c = $db->prepare("SELECT COUNT(*) FROM sf_instance WHERE template_id=? AND deleted_at IS NULL"); $c->execute([$id]);
    if ((int)$c->fetchColumn() > 0) jerr('此模板已有填寫單據，不能刪除，請改用「停用」', 409);
    $db->prepare("DELETE FROM sf_template WHERE id=?")->execute([$id]);
    jout([]);
}

case 'template_copy': {
    need_csrf(); need_admin();
    $t = sf_template_get($db, (int)($_POST['id'] ?? 0));
    if (!$t) jerr('找不到此模板', 404);
    $name = mb_substr($t['name'], 0, 90) . '（複本）';
    $nid = sf_template_save($db, $uid, 0, $name, $t['paper_size'], $t['orientation'], (string)$t['book_json']);
    jout(['id'=>$nid]);
}

case 'asdoc_list': jout(['docs'=>eg_asdoc_list($db)]);

/* ------------------------------------------------------------ 單據 ------------------------------------------------------------ */
case 'instance_list': {
    $where = ["i.deleted_at IS NULL"]; $args = [];
    if (!$perms['canViewAll']) { $where[] = "i.created_by=?"; $args[] = $uid; }
    if ((int)($_GET['template_id'] ?? 0) > 0) { $where[] = "i.template_id=?"; $args[] = (int)$_GET['template_id']; }
    if (in_array($_GET['status'] ?? '', ['draft', 'done'], true)) { $where[] = "i.status=?"; $args[] = $_GET['status']; }
    $kw = trim((string)($_GET['kw'] ?? ''));
    if ($kw !== '') { $where[] = "(i.title LIKE ? OR t.name LIKE ? OR u.user_cname LIKE ?)"; $args = array_merge($args, ["%$kw%", "%$kw%", "%$kw%"]); }
    $per = max(5, min(100, (int)($_GET['per'] ?? 20))); $page = max(1, (int)($_GET['page'] ?? 1));
    $w = implode(' AND ', $where);
    $cnt = $db->prepare("SELECT COUNT(*) FROM sf_instance i JOIN sf_template t ON t.id=i.template_id LEFT JOIN user u ON u.id=i.created_by WHERE $w");
    $cnt->execute($args); $total = (int)$cnt->fetchColumn();
    $st = $db->prepare("SELECT i.id, i.template_id, t.name AS template_name, i.title, i.biz_date, i.status, i.created_by, u.user_cname AS creator_name,
            i.created_at, i.modified_at, i.submitted_at
        FROM sf_instance i JOIN sf_template t ON t.id=i.template_id LEFT JOIN user u ON u.id=i.created_by
        WHERE $w ORDER BY i.id DESC LIMIT " . (($page - 1) * $per) . ", $per");
    $st->execute($args);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) $r['can_edit'] = ($r['status'] === 'draft') && ($perms['canAdmin'] || (int)$r['created_by'] === $uid);
    jout(['rows'=>$rows, 'total'=>$total, 'page'=>$page, 'per'=>$per]);
}

case 'instance_create': {
    need_csrf();
    $t = sf_template_get($db, (int)($_POST['template_id'] ?? 0));
    if (!$t || $t['status'] !== 'active') jerr('找不到可用的模板');
    $biz = (string)($_POST['biz_date'] ?? '');
    if ($biz !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $biz)) jerr('業務日期格式不正確');
    $title = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 150);
    $db->prepare("INSERT INTO sf_instance (template_id, tpl_version, title, biz_date, tpl_book_json, tpl_fields_json, values_json, created_by, created_at, modified_by, modified_at)
                  VALUES (?,?,?,?,?,?, '{}', ?, NOW(), ?, NOW())")
       ->execute([$t['id'], $t['version'], $title, $biz ?: date('Y-m-d'), $t['book_json'], $t['fields_json'], $uid, $uid]);
    jout(['id'=>(int)$db->lastInsertId()]);
}

case 'instance_get': {
    $inst = sf_instance_get($db, (int)($_GET['id'] ?? 0));
    if (!$inst) jerr('找不到此單據', 404);
    if (!$perms['canViewAll'] && (int)$inst['created_by'] !== $uid) jerr('無權限檢視此單據', 403);
    $tpl = sf_template_get($db, (int)$inst['template_id']);
    $out = [
        'id'=>(int)$inst['id'], 'template_id'=>(int)$inst['template_id'], 'template_name'=>$tpl['name'] ?? '', 'title'=>$inst['title'],
        'biz_date'=>$inst['biz_date'], 'status'=>$inst['status'], 'creator_name'=>$inst['creator_name'], 'extra_rows'=>(int)$inst['extra_rows'],
        'paper_size'=>$tpl['paper_size'] ?? 'A4', 'orientation'=>$tpl['orientation'] ?? 'landscape',
        'book'=>json_decode((string)$inst['tpl_book_json'], true),
        'fields'=>json_decode((string)$inst['tpl_fields_json'], true) ?: [],
        'values'=>json_decode((string)$inst['values_json'], true) ?: new stdClass(),
        'can_edit'=>sf_instance_can_edit($perms, $inst, $uid),
        'print'=>sf_print_meta($db, (int)$inst['template_id'], $tpl['name'] ?? '', $inst['biz_date']),
    ];
    jout(['instance'=>$out]);
}

case 'instance_save': {
    need_csrf();
    $inst = sf_instance_get($db, (int)($_POST['id'] ?? 0));
    if (!$inst) jerr('找不到此單據', 404);
    if (!sf_instance_can_edit($perms, $inst, $uid)) jerr($inst['status'] === 'draft' ? '只能修改自己建立的單據' : '此單據已完成，不能再修改', 403);
    $fields = json_decode((string)$inst['tpl_fields_json'], true) ?: [];
    $extra = max(0, min(500, (int)($_POST['extra_rows'] ?? 0)));
    $vals = json_decode((string)($_POST['values_json'] ?? '{}'), true);
    if (!is_array($vals)) jerr('資料格式不正確');
    $submit = !empty($_POST['submit']);
    $res = sf_validate_values($fields, $vals, $extra, $submit);
    if ($res['errors']) jerr($res['errors'][0][2] . (count($res['errors']) > 1 ? '（另有 ' . (count($res['errors']) - 1) . ' 項）' : ''), 422, ['errors'=>$res['errors']]);
    $title = mb_substr(trim((string)($_POST['title'] ?? $inst['title'])), 0, 150);
    $biz = (string)($_POST['biz_date'] ?? $inst['biz_date']);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $biz)) $biz = (string)$inst['biz_date'];
    $db->prepare("UPDATE sf_instance SET title=?, biz_date=?, values_json=?, extra_rows=?, status=?, submitted_at=IF(?, NOW(), submitted_at),
                  modified_by=?, modified_at=NOW() WHERE id=?")
       ->execute([$title, $biz, json_encode($res['values'], JSON_UNESCAPED_UNICODE), $extra, $submit ? 'done' : 'draft', $submit ? 1 : 0, $uid, $inst['id']]);
    jout(['status'=>$submit ? 'done' : 'draft']);
}

case 'instance_delete': {
    need_csrf();
    $inst = sf_instance_get($db, (int)($_POST['id'] ?? 0));
    if (!$inst) jerr('找不到此單據', 404);
    if (!($perms['canAdmin'] || ((int)$inst['created_by'] === $uid && $inst['status'] === 'draft'))) jerr('無權限刪除', 403);
    $db->prepare("UPDATE sf_instance SET deleted_at=NOW() WHERE id=?")->execute([$inst['id']]);
    jout([]);
}

case 'print_log': {
    need_csrf();
    $inst = sf_instance_get($db, (int)($_POST['id'] ?? 0));
    if (!$inst) jerr('找不到此單據', 404);
    if (!$perms['canViewAll'] && (int)$inst['created_by'] !== $uid) jerr('無權限', 403);
    eg_print_log_add($db, ['source'=>'sheet_form', 'doc_kind'=>'form', 'doc_name'=>($inst['title'] !== '' ? $inst['title'] : '表格模板單據') . '（#' . $inst['id'] . '）',
        'ref_table'=>'sf_instance', 'ref_id'=>(int)$inst['id']]);
    jout([]);
}

default: jerr('未知的操作', 404);
}
} catch (Throwable $e) {
    jerr('伺服器錯誤：' . $e->getMessage(), 500);
}
