<?php
/**
 * 組織角色綁定 API（全站共用設定：哪個部門是人事/品管…、誰是最高核准人員）
 * 權限：讀＝已登入即可（各頁面都要用）；寫＝限系統管理者。
 */
$document_root = $_SERVER['DOCUMENT_ROOT'];
session_start();
header('Content-Type: application/json; charset=utf-8');
include_once $document_root . '/EGsystem/src/common/_config.php';
include_once $document_root . '/EGsystem/src/common/DBConnection.php';
include_once $document_root . '/EGsystem/src/common/org_role_lib.php';
include_once $document_root . '/EGsystem/src/common/people_lib.php';

function ojout($a){ echo json_encode(array_merge(['ok'=>true], $a), JSON_UNESCAPED_UNICODE); exit; }
function ojerr($m, $c=400){ http_response_code($c); echo json_encode(['ok'=>false,'error'=>$m], JSON_UNESCAPED_UNICODE); exit; }

try {
    $db = (new DBConnection())->getPDO();
    eg_org_ensure_schema($db);
} catch (Throwable $e) { ojerr('DB連線失敗：'.$e->getMessage(), 500); }

$uname = $_SESSION['userName'] ?? '';
if ($uname === '') ojerr('未登入', 401);
$st = $db->prepare("SELECT id, user_cname, user_status FROM user WHERE user_uname=?");
$st->execute([$uname]);
$me = $st->fetch(PDO::FETCH_ASSOC);
if (!$me) ojerr('未登入', 401);
$isAdmin = in_array((int)$me['user_status'], [9, 90], true);
if (!$isAdmin) {
    $q = $db->prepare("SELECT 1 FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id
                       WHERE ur.user_id=? AND r.role_code='admin' AND r.is_system=1 LIMIT 1");
    $q->execute([(int)$me['id']]);
    $isAdmin = (bool)$q->fetchColumn();
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {

case 'meta': {
    $depts = $db->query("SELECT id, name FROM department ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
    // 人員一律走 people_lib（只列未離職、依職稱排序、跨部門顯示部門）
    $people = [];
    try { $people = eg_people_list($db, ['multi_dept'=>true]); } catch (Throwable $e) {
        $people = $db->query("SELECT u.id, u.user_cname FROM user u
                              WHERE COALESCE(u.state,1) NOT IN (0,90) AND u.user_cname<>'' ORDER BY u.user_cname")
                     ->fetchAll(PDO::FETCH_ASSOC);
    }
    $bind = eg_org_bindings($db);
    // 每個部門類綁定順便解出目前的部門主管，讓設定頁看得到「審核會是誰」
    $mgr = [];
    foreach (EG_ORG_ROLES as $k => $r) {
        if ($r['type'] !== 'dept') continue;
        $d = $bind[$k]['dept_id'] ?? null;
        if ($d) $mgr[$k] = eg_org_dept_manager($db, (int)$d);
    }
    ojout(['roles'=>EG_ORG_ROLES, 'departments'=>$depts, 'people'=>$people,
           'bindings'=>$bind, 'managers'=>$mgr, 'is_admin'=>$isAdmin]);
}

case 'save': {
    if (!$isAdmin) ojerr('僅系統管理者可修改組織角色綁定', 403);
    $list = json_decode((string)($_POST['bindings'] ?? '[]'), true);
    if (!is_array($list)) ojerr('資料格式不正確');
    try {
        $db->beginTransaction();
        foreach ($list as $b) {
            $k = (string)($b['role_key'] ?? '');
            if (!isset(EG_ORG_ROLES[$k])) continue;
            $d = ($b['dept_id'] ?? '') === '' ? null : (int)$b['dept_id'];
            $u = ($b['user_id'] ?? '') === '' ? null : (int)$b['user_id'];
            eg_org_save($db, $k, $d, $u, (string)$me['user_cname']);
        }
        $db->commit();
    } catch (Throwable $e) { $db->rollBack(); ojerr('儲存失敗：'.$e->getMessage(), 500); }
    $bind = eg_org_bindings($db);
    $mgr = [];
    foreach (EG_ORG_ROLES as $k => $r) {
        if ($r['type'] !== 'dept') continue;
        $d = $bind[$k]['dept_id'] ?? null;
        if ($d) $mgr[$k] = eg_org_dept_manager($db, (int)$d);
    }
    ojout(['bindings'=>$bind, 'managers'=>$mgr]);
}

default:
    ojerr('未知動作：'.$action);
}
