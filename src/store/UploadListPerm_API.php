<?php
// 上傳 List 權限設定 API：get＝讀設定（管理員）／save＝存設定（管理員）
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../common/api_guard.php';
if (!isset($_SESSION['userName'])) { http_response_code(401); echo json_encode(['ok' => false, 'msg' => '尚未登入']); exit; }
require_once __DIR__ . '/../common/DBConnection.php';
require_once __DIR__ . '/../common/upload_list_perm_lib.php';
$db  = (new DBConnection())->getPDO();
$uid = (int)($_SESSION['id'] ?? 0);

if (!ulp_is_admin(rf_load_user_features($db, $uid))) {
    http_response_code(403); echo json_encode(['ok' => false, 'msg' => '僅全站管理員／本站管理員可設定']); exit;
}
if (empty($_SESSION['ulp_csrf'])) $_SESSION['ulp_csrf'] = bin2hex(random_bytes(16));
$act = $_GET['action'] ?? '';
if ($act === 'get') {
    $feat = [];
    foreach (ulp_features() as $k => $f) $feat[] = ['key' => $k, 'type' => $f['type'], 'label' => $f['label']];
    $grp = [];
    foreach (ulp_groups() as $k => $g) $grp[] = ['key' => $k, 'label' => $g[0]];
    echo json_encode(['ok' => true, 'features' => $feat, 'groups' => $grp, 'access' => ulp_settings($db), 'csrf' => $_SESSION['ulp_csrf']], JSON_UNESCAPED_UNICODE); exit;
}
if ($act === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $tok = $_POST['csrf'] ?? '';
    if ($tok === '' || !hash_equals((string)$_SESSION['ulp_csrf'], (string)$tok)) {
        echo json_encode(['ok' => false, 'msg' => '連線憑證失效，請重新整理頁面後再試 (CSRF)']); exit;
    }
    $in = json_decode((string)($_POST['access'] ?? ''), true);
    if (!is_array($in)) { echo json_encode(['ok' => false, 'msg' => '資料格式錯誤']); exit; }
    $cur = ulp_settings_save($db, $in, (string)($_SESSION['user_cname'] ?? ''));
    echo json_encode(['ok' => true, 'access' => $cur], JSON_UNESCAPED_UNICODE); exit;
}
echo json_encode(['ok' => false, 'msg' => '無效的操作']);
