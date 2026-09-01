<?php
/**
 * QC_Container_API.php — 容器種類設定的讀寫端點
 * 設定入口：views/pm/OreadyReply_ForPm_BaseOfTime.php →「容器設定」
 * 唯一實作與驗證規則都在 src/common/qc_container_lib.php，這裡只做守門與轉呼叫
 *
 * action=list  取得全部容器（含停用）＋各代碼使用筆數＋本人可否設定
 * action=save  存檔（需權限 + CSRF；驗證與刪除保護在 lib 內，前端擋一次這裡再擋一次＝鐵律8）
 */
header('Content-Type: application/json; charset=utf-8');
if (!isset($_SESSION)) session_start();

$out = function ($ok, $msg, $extra = array()) {
    echo json_encode(array_merge(array('success' => $ok, 'message' => $msg), $extra), JSON_UNESCAPED_UNICODE);
    exit;
};

if (!isset($_SESSION['id'])) $out(false, '未登入或連線已逾時，請重新整理頁面後再試');

include_once __DIR__ . '/../common/DBConnection.php';
include_once __DIR__ . '/../common/_config.php';
require_once __DIR__ . '/../common/qc_container_lib.php';

if (!isset($db) || !($db instanceof PDO)) $out(false, '資料庫連線失敗');
eg_qc_container_db($db);

$uid    = $_SESSION['id'];
$action = isset($_REQUEST['action']) ? trim($_REQUEST['action']) : '';
$canSet = eg_qc_container_can_settings($db, $uid);

if ($action === 'list') {
    $out(true, '', array(
        'data'     => eg_qc_container_all($db),
        'usage'    => eg_qc_container_usage($db),
        'can_edit' => $canSet,
        'csrf'     => $canSet ? eg_qc_container_csrf() : '',
    ));
}

if ($action === 'save') {
    if (!$canSet) $out(false, '無權限設定容器種類');
    if (!eg_qc_container_csrf_ok(isset($_POST['csrf']) ? $_POST['csrf'] : null)) {
        $out(false, '連線憑證失效，請重新整理頁面後再試 (CSRF)', array('code' => 'CSRF'));
    }
    $raw  = isset($_POST['options']) ? $_POST['options'] : '';
    $list = json_decode((string)$raw, true);
    if (!is_array($list)) $out(false, '資料格式不正確');

    list($ok, $msg) = eg_qc_container_save($db, $list, $uid);
    if (!$ok) $out(false, $msg);

    $out(true, $msg, array(
        'data'    => eg_qc_container_all($db),
        'options' => eg_qc_container_options($db),
        'usage'   => eg_qc_container_usage($db),
    ));
}

$out(false, '無效的操作');
