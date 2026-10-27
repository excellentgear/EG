<?php
header('Content-Type: application/json; charset=utf-8');
session_start();
$root = $_SERVER['DOCUMENT_ROOT'] . '/EGsystem/src/common/';
require_once $root . '_config.php';
require_once $root . 'DBConnection.php';
require_once $root . 'org_chart_hide_lib.php';
require_once $root . 'asdoc_lib.php';

$db  = (new DBConnection())->getPDO();
$uid = (int)($_SESSION['id'] ?? 0);
if ($uid <= 0) { http_response_code(401); echo json_encode(['status'=>'error','message'=>'尚未登入']); exit; }
$isAdmin = ($uid === 1) || eg_asdoc_is_admin($db, $uid);
if (empty($_SESSION['och_csrf'])) $_SESSION['och_csrf'] = bin2hex(random_bytes(16));

$action = $_REQUEST['action'] ?? '';
if (!$isAdmin) { http_response_code(403); echo json_encode(['status'=>'error','message'=>'僅管理員可設定']); exit; }

if ($action === 'get') {
    $depts = $db->query("SELECT id,name,level FROM department ORDER BY sort_order")->fetchAll(PDO::FETCH_ASSOC);
    $people = $db->query("SELECT u.id,u.user_cname,
            (SELECT d.name FROM user_department_position_map m JOIN department d ON d.id=m.department_id
              WHERE m.user_id=u.id ORDER BY m.is_main DESC LIMIT 1) AS dept
        FROM user u WHERE u.state=1 ORDER BY u.id")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['status'=>'success','csrf'=>$_SESSION['och_csrf'],'hide'=>och_get($db),'depts'=>$depts,'people'=>$people]);
    exit;
}
if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $tok = (string)($_POST['csrf'] ?? '');
    if ($tok === '' || !hash_equals($_SESSION['och_csrf'], $tok)) {
        http_response_code(403); echo json_encode(['status'=>'error','message'=>'連線憑證失效，請重新整理頁面']); exit;
    }
    $d = json_decode((string)($_POST['depts'] ?? '[]'), true);
    $u = json_decode((string)($_POST['users'] ?? '[]'), true);
    och_save($db, is_array($d) ? $d : [], is_array($u) ? $u : [], 'uid:' . $uid);
    echo json_encode(['status'=>'success']);
    exit;
}
echo json_encode(['status'=>'error','message'=>'無效的操作']);
