<?php
/**
 * 表格模板 API（sheet_form_lib.php／sheet_form_flow_lib.php 的資料存取層）
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
$uname = (string)$u['user_cname'];
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

/** 誰看得到這張單：看全部者／建立人／目前這關的簽核人／曾經簽過的人 */
function inst_can_view(PDO $db, array $perms, array $inst, int $uid): bool {
    if ($perms['canViewAll'] || (int)$inst['created_by'] === $uid) return true;
    [$can] = sf_flow_can_decide($db, $inst, $uid);
    if ($can) return true;
    $st = $db->prepare("SELECT 1 FROM approval_record WHERE module='sheet_form' AND entity_id=? AND approver_id=? LIMIT 1");
    $st->execute([(int)$inst['id'], $uid]);
    return (bool)$st->fetchColumn();
}

try {
switch ($action) {

case 'meta': {
    $people = [];
    foreach (eg_people_list($db, []) as $p) $people[] = (string)($p['user_cname'] ?? '');
    $people = array_values(array_filter(array_unique($people), 'strlen'));
    $out = ['perms'=>$perms, 'uid'=>$uid, 'uname'=>$uname, 'today'=>date('Y-m-d'), 'people'=>$people, 'csrf'=>$csrf, 'features'=>SF_FEATURES];
    $out['maintainable'] = sf_maintainable_ids($db, $perms, $uid);   // null＝全部（管理員）
    if ($perms['canAdmin']) {                                         // 設計器用：部門／人員／圖章樣式
        $out['departments'] = $db->query("SELECT id, name FROM department ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
        $pf = [];
        foreach (eg_people_list($db, []) as $p) $pf[] = ['id'=>(int)$p['id'], 'name'=>$p['user_cname'], 'dept'=>$p['dept_name'] ?? '', 'pos'=>$p['position_name'] ?? ''];
        $out['people_full'] = $pf;
        $out['stamp_tpls'] = rvf_stamp_tpl_options($db);
    }
    jout($out);
}

case 'lookup': {
    $kind = ($_GET['kind'] ?? '') === 'customer' ? 'customer' : 'part';
    jout(['rows'=>sf_lookup($db, $kind, (string)($_GET['q'] ?? ''))]);
}

/* ------------------------------------------------------------ 模板 ------------------------------------------------------------ */
case 'template_list': {
    $rows = sf_template_list($db);
    $mids = sf_maintainable_ids($db, $perms, $uid);
    foreach ($rows as &$r) {
        $t = sf_template_get($db, (int)$r['id']);
        $r['flow_count'] = count(sf_flow_of_template($t));
        $r['can_maintain'] = $mids === null || in_array((int)$r['id'], $mids, true);
    }
    unset($r);
    if (!$perms['canAdmin']) $rows = array_values(array_filter($rows, fn($r) => $r['status'] === 'active'));
    jout(['templates'=>$rows]);
}

case 'template_get': {
    $t = sf_template_get($db, (int)($_GET['id'] ?? 0));
    if (!$t) jerr('找不到此模板', 404);
    $maint = sf_can_maintain($db, $perms, $t, $uid);
    if ($t['status'] !== 'active' && !$perms['canAdmin']) jerr('此模板已停用', 403);
    $t['fields'] = json_decode((string)$t['fields_json'], true) ?: [];
    $t['book'] = $perms['canAdmin'] ? json_decode((string)$t['book_json'], true) : null;     // 版面只給管理員
    $t['defaults'] = json_decode((string)($t['defaults_json'] ?? ''), true);
    $t['flow'] = sf_flow_of_template($t);
    $m = json_decode((string)($t['maintainers_json'] ?? ''), true) ?: [];
    $t['maintainers'] = [];
    foreach ($m as $mid) { $r = sf_user_row($db, (int)$mid); if ($r) $t['maintainers'][] = ['id'=>(int)$r['id'], 'name'=>$r['user_cname']]; }
    $t['can_maintain'] = $maint;
    unset($t['fields_json'], $t['book_json'], $t['defaults_json'], $t['flow_json'], $t['maintainers_json']);
    $t['as_doc'] = eg_asdoc_get($db, sf_asdoc_module((int)$t['id']));
    jout(['template'=>$t]);
}

case 'template_save': {
    need_csrf(); need_admin();
    $opt = [];
    if (isset($_POST['defaults_json'])) $opt['defaults_json'] = (string)$_POST['defaults_json'];
    if (isset($_POST['flow_json'])) $opt['flow_json'] = (string)$_POST['flow_json'];
    if (isset($_POST['maintain_dept_id'])) $opt['maintain_dept_id'] = (int)$_POST['maintain_dept_id'];
    if (isset($_POST['maintainers'])) $opt['maintainers'] = json_decode((string)$_POST['maintainers'], true) ?: [];
    if (isset($_POST['stamp_tpl_id'])) $opt['stamp_tpl_id'] = (int)$_POST['stamp_tpl_id'];
    try {
        $id = sf_template_save($db, $uid, (int)($_POST['id'] ?? 0), (string)($_POST['name'] ?? ''),
            (string)($_POST['paper_size'] ?? 'A4'), (string)($_POST['orientation'] ?? 'landscape'), (string)($_POST['book_json'] ?? ''), $opt);
    } catch (InvalidArgumentException $e) { jerr($e->getMessage()); }
    if (isset($_POST['as_doc_id'])) eg_asdoc_save($db, sf_asdoc_module($id), (int)$_POST['as_doc_id'], (string)$u['user_cname']);
    jout(['id'=>$id]);
}

/** 維護人員只能改「預設明細內容」（標準項目），不能動版面與簽核設定 */
case 'template_defaults_save': {
    need_csrf();
    $t = sf_template_get($db, (int)($_POST['id'] ?? 0));
    if (!$t) jerr('找不到此模板', 404);
    if (!sf_can_maintain($db, $perms, $t, $uid)) jerr('您不是這張模板的維護人員', 403);
    $fields = json_decode((string)$t['fields_json'], true) ?: [];
    $raw = trim((string)($_POST['defaults_json'] ?? ''));
    $d = $raw === '' ? null : sf_defaults_normalize($fields, json_decode($raw, true));
    $db->prepare("UPDATE sf_template SET defaults_json=?, modified_by=?, modified_at=NOW() WHERE id=?")
       ->execute([$d ? json_encode($d, JSON_UNESCAPED_UNICODE) : null, $uid, $t['id']]);
    jout(['rows'=>$d['rows'] ?? 0]);
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
    $nid = sf_template_save($db, $uid, 0, $name, $t['paper_size'], $t['orientation'], (string)$t['book_json'], [
        'defaults_json' => (string)($t['defaults_json'] ?? ''), 'flow_json' => (string)($t['flow_json'] ?? ''),
        'maintain_dept_id' => (int)($t['maintain_dept_id'] ?? 0), 'stamp_tpl_id' => (int)($t['stamp_tpl_id'] ?? 0),
        'maintainers' => json_decode((string)($t['maintainers_json'] ?? ''), true) ?: []]);
    jout(['id'=>$nid]);
}

case 'asdoc_list': jout(['docs'=>eg_asdoc_list($db)]);

/* ------------------------------------------------------------ 單據 ------------------------------------------------------------ */
case 'instance_list': {
    $per = max(5, min(100, (int)($_GET['per'] ?? 20))); $page = max(1, (int)($_GET['page'] ?? 1));
    $kw = trim((string)($_GET['kw'] ?? ''));
    if (!empty($_GET['todo'])) {          // 待我簽核
        $rows = $db->query("SELECT i.id, i.template_id, t.name AS template_name, i.title, i.biz_date, i.status, i.created_by, u.user_cname AS creator_name,
                i.created_at, i.modified_at, i.submitted_at, i.cur_step, i.tpl_flow_json
            FROM sf_instance i JOIN sf_template t ON t.id=i.template_id LEFT JOIN user u ON u.id=i.created_by
            WHERE i.deleted_at IS NULL AND i.status='pending' ORDER BY i.id DESC LIMIT 300")->fetchAll(PDO::FETCH_ASSOC);
        $mine = [];
        foreach ($rows as $r) {
            if ($kw !== '' && stripos($r['title'] . $r['template_name'] . $r['creator_name'], $kw) === false) continue;
            [$can] = sf_flow_can_decide($db, $r, $uid);
            if ($can) { $r['can_edit'] = false; $r['step_label'] = sf_step_label(sf_flow_of_instance($r), (int)$r['cur_step']); unset($r['tpl_flow_json']); $mine[] = $r; }
        }
        jout(['rows'=>array_slice($mine, ($page - 1) * $per, $per), 'total'=>count($mine), 'page'=>$page, 'per'=>$per]);
    }
    $where = ["i.deleted_at IS NULL"]; $args = [];
    if (!$perms['canViewAll']) { $where[] = "(i.created_by=? OR EXISTS (SELECT 1 FROM approval_record ar WHERE ar.module='sheet_form' AND ar.entity_id=i.id AND ar.approver_id=?))"; $args[] = $uid; $args[] = $uid; }
    if ((int)($_GET['template_id'] ?? 0) > 0) { $where[] = "i.template_id=?"; $args[] = (int)$_GET['template_id']; }
    if (in_array($_GET['status'] ?? '', ['draft', 'pending', 'done', 'rejected'], true)) { $where[] = "i.status=?"; $args[] = $_GET['status']; }
    if ($kw !== '') { $where[] = "(i.title LIKE ? OR t.name LIKE ? OR u.user_cname LIKE ?)"; $args = array_merge($args, ["%$kw%", "%$kw%", "%$kw%"]); }
    $w = implode(' AND ', $where);
    $cnt = $db->prepare("SELECT COUNT(*) FROM sf_instance i JOIN sf_template t ON t.id=i.template_id LEFT JOIN user u ON u.id=i.created_by WHERE $w");
    $cnt->execute($args); $total = (int)$cnt->fetchColumn();
    $st = $db->prepare("SELECT i.id, i.template_id, t.name AS template_name, i.title, i.biz_date, i.status, i.created_by, u.user_cname AS creator_name,
            i.created_at, i.modified_at, i.submitted_at, i.cur_step, i.tpl_flow_json
        FROM sf_instance i JOIN sf_template t ON t.id=i.template_id LEFT JOIN user u ON u.id=i.created_by
        WHERE $w ORDER BY i.id DESC LIMIT " . (($page - 1) * $per) . ", $per");
    $st->execute($args);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['can_edit'] = in_array($r['status'], ['draft', 'rejected'], true) && ($perms['canAdmin'] || (int)$r['created_by'] === $uid);
        $r['step_label'] = $r['status'] === 'pending' ? sf_step_label(sf_flow_of_instance($r), (int)$r['cur_step']) : '';
        unset($r['tpl_flow_json']);
    }
    jout(['rows'=>$rows, 'total'=>$total, 'page'=>$page, 'per'=>$per]);
}

case 'todo_count': {
    $n = 0;
    foreach ($db->query("SELECT id, created_by, status, cur_step, tpl_flow_json FROM sf_instance WHERE deleted_at IS NULL AND status='pending' LIMIT 300")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        [$can] = sf_flow_can_decide($db, $r, $uid); if ($can) $n++;
    }
    jout(['n'=>$n]);
}

case 'instance_create': {
    need_csrf();
    $t = sf_template_get($db, (int)($_POST['template_id'] ?? 0));
    if (!$t || $t['status'] !== 'active') jerr('找不到可用的模板');
    $biz = (string)($_POST['biz_date'] ?? '');
    if ($biz !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $biz)) jerr('業務日期格式不正確');
    $title = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 150);
    // 模板有「預設明細內容」（標準項目）就一併帶入：列數＝extra_rows+1
    $defVals = '{}'; $extra = 0;
    $def = json_decode((string)($t['defaults_json'] ?? ''), true);
    if (is_array($def) && !empty($def['rows']) && is_array($def['values'] ?? null)) { $defVals = json_encode($def['values'], JSON_UNESCAPED_UNICODE); $extra = max(0, (int)$def['rows'] - 1); }
    $db->prepare("INSERT INTO sf_instance (template_id, tpl_version, title, biz_date, tpl_book_json, tpl_fields_json, tpl_flow_json, values_json, extra_rows, created_by, created_at, modified_by, modified_at)
                  VALUES (?,?,?,?,?,?,?,?,?, ?, NOW(), ?, NOW())")
       ->execute([$t['id'], $t['version'], $title, $biz ?: date('Y-m-d'), $t['book_json'], $t['fields_json'], $t['flow_json'], $defVals, $extra, $uid, $uid]);
    jout(['id'=>(int)$db->lastInsertId()]);
}

case 'instance_get': {
    $inst = sf_instance_get($db, (int)($_GET['id'] ?? 0));
    if (!$inst) jerr('找不到此單據', 404);
    if (!inst_can_view($db, $perms, $inst, $uid)) jerr('無權限檢視此單據', 403);
    $tpl = sf_template_get($db, (int)$inst['template_id']);
    [$canDecide] = sf_flow_can_decide($db, $inst, $uid);
    $flow = sf_flow_of_instance($inst);
    $out = [
        'id'=>(int)$inst['id'], 'template_id'=>(int)$inst['template_id'], 'template_name'=>$tpl['name'] ?? '', 'title'=>$inst['title'],
        'biz_date'=>$inst['biz_date'], 'status'=>$inst['status'], 'creator_name'=>$inst['creator_name'], 'extra_rows'=>(int)$inst['extra_rows'],
        'paper_size'=>$tpl['paper_size'] ?? 'A4', 'orientation'=>$tpl['orientation'] ?? 'landscape',
        'book'=>json_decode((string)$inst['tpl_book_json'], true),
        'fields'=>json_decode((string)$inst['tpl_fields_json'], true) ?: [],
        'values'=>json_decode((string)$inst['values_json'], true) ?: new stdClass(),
        'can_edit'=>sf_instance_can_edit($perms, $inst, $uid),
        'can_decide'=>$canDecide, 'cur_step'=>(int)$inst['cur_step'], 'flow_steps'=>sf_flow_steps_view($db, $inst), 'has_flow'=>count($flow) > 0,
        'submit_date'=>$inst['submit_date'],
        'stamp_tpl'=>(($stp = rvf_stamp_tpl_get($db, (int)($tpl['stamp_tpl_id'] ?? 0))) ? $stp['schema'] : null),
        'creator'=>['id'=>(int)$inst['created_by']] + sf_identity_asof($db, (int)$inst['created_by'], (string)($inst['submit_date'] ?: $inst['biz_date'])),
        'print'=>sf_print_meta($db, (int)$inst['template_id'], $tpl['name'] ?? '', $inst['submit_date'] ?: $inst['biz_date']),
    ];
    jout(['instance'=>$out]);
}

case 'instance_save': {
    need_csrf();
    $inst = sf_instance_get($db, (int)($_POST['id'] ?? 0));
    if (!$inst) jerr('找不到此單據', 404);
    if (!sf_instance_can_edit($perms, $inst, $uid)) jerr(in_array($inst['status'], ['draft', 'rejected'], true) ? '只能修改自己建立的單據' : ($inst['status'] === 'pending' ? '單據簽核中，不能修改' : '此單據已完成，不能再修改'), 403);
    $fields = json_decode((string)$inst['tpl_fields_json'], true) ?: [];
    $extra = max(0, min(500, (int)($_POST['extra_rows'] ?? 0)));
    $vals = json_decode((string)($_POST['values_json'] ?? '{}'), true);
    if (!is_array($vals)) jerr('資料格式不正確');
    $submit = !empty($_POST['submit']);
    $res = sf_validate_values($fields, $vals, $extra, $submit, $db);
    if ($res['errors']) jerr($res['errors'][0][2] . (count($res['errors']) > 1 ? '（另有 ' . (count($res['errors']) - 1) . ' 項）' : ''), 422, ['errors'=>$res['errors']]);
    $flow = sf_flow_of_instance($inst);
    if ($submit) {
        // 送出（含退回後重送）時用模板「目前」的簽核流程：版面與欄位已鎖在單據快照裡，但簽核人設定改了應該立刻生效
        $tNow = sf_template_get($db, (int)$inst['template_id']);
        if ($tNow) {
            $flow = sf_flow_of_template($tNow);
            $db->prepare("UPDATE sf_instance SET tpl_flow_json=? WHERE id=?")->execute([$flow ? json_encode($flow, JSON_UNESCAPED_UNICODE) : null, $inst['id']]);
        }
    }
    if ($submit && $flow) { $pe = sf_flow_precheck($db, $flow, (int)$inst['created_by']); if ($pe) jerr($pe, 422); }
    $title = mb_substr(trim((string)($_POST['title'] ?? $inst['title'])), 0, 150);
    $biz = (string)($_POST['biz_date'] ?? $inst['biz_date']);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $biz)) $biz = (string)$inst['biz_date'];
    $newStatus = ($submit && !$flow) ? 'done' : (($inst['status'] === 'rejected' && !$submit) ? 'rejected' : 'draft');
    $noFlowSubmit = ($submit && !$flow) ? 1 : 0;
    $db->prepare("UPDATE sf_instance SET title=?, biz_date=?, values_json=?, extra_rows=?, status=?, submitted_at=IF(?, NOW(), submitted_at),
                  submit_date=IF(?, CURDATE(), submit_date), modified_by=?, modified_at=NOW() WHERE id=?")
       ->execute([$title, $biz, json_encode($res['values'], JSON_UNESCAPED_UNICODE), $extra, $newStatus, $noFlowSubmit, $noFlowSubmit, $uid, $inst['id']]);
    if ($submit && $flow) {
        $fresh = sf_instance_get($db, (int)$inst['id']);
        $r = sf_flow_submit($db, $fresh, (int)$inst['created_by'], (string)$inst['creator_name']);
        if (empty($r['ok'])) jerr($r['msg'] ?? '送簽失敗', 422);
        jout(['status'=>$r['status']]);
    }
    jout(['status'=>$newStatus]);
}

case 'flow_decide': {
    need_csrf();
    $r = sf_flow_decide($db, (int)($_POST['id'] ?? 0), $uid, $uname, ($_POST['decision'] ?? '') === 'rejected' ? 'rejected' : 'approved', (string)($_POST['note'] ?? ''));
    if (empty($r['ok'])) jerr($r['msg'] ?? '處理失敗', 422);
    jout(['status'=>$r['status']]);
}

case 'instance_delete': {
    need_csrf();
    $inst = sf_instance_get($db, (int)($_POST['id'] ?? 0));
    if (!$inst) jerr('找不到此單據', 404);
    if (!($perms['canAdmin'] || ((int)$inst['created_by'] === $uid && in_array($inst['status'], ['draft', 'rejected'], true)))) jerr('無權限刪除', 403);
    $db->prepare("UPDATE sf_instance SET deleted_at=NOW() WHERE id=?")->execute([$inst['id']]);
    sf_notify_close($db, (int)$inst['id']);
    jout([]);
}

case 'print_log': {
    need_csrf();
    $inst = sf_instance_get($db, (int)($_POST['id'] ?? 0));
    if (!$inst) jerr('找不到此單據', 404);
    if (!inst_can_view($db, $perms, $inst, $uid)) jerr('無權限', 403);
    eg_print_log_add($db, ['source'=>'sheet_form', 'doc_kind'=>'form', 'doc_name'=>($inst['title'] !== '' ? $inst['title'] : '表格模板單據') . '（#' . $inst['id'] . '）',
        'ref_table'=>'sf_instance', 'ref_id'=>(int)$inst['id']]);
    jout([]);
}

default: jerr('未知的操作', 404);
}
} catch (Throwable $e) {
    jerr('伺服器錯誤：' . $e->getMessage(), 500);
}
