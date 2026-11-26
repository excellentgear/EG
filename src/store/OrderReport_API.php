<?php
// OrderReport_API.php — 訂單「是否檢附報告」選項設定／讀取（唯一實作在 src/common/order_report_lib.php）
// 讀取：看得到訂單追蹤頁的人；設定：僅管理員（後端再驗一次，鐵律8）。
// 訂單自己的選擇是跟著「新增/編輯訂單」存檔一起寫的（_NewOrder_Track.php），不走這支。
session_start();
require_once __DIR__ . '/../common/api_guard.php';
header('Content-Type: application/json; charset=utf-8');
if (!isset($_SESSION['userName'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '未登入']);
    exit;
}
require_once __DIR__ . '/../common/DBConnection.php';
require_once __DIR__ . '/../common/order_track_perm_lib.php';
require_once __DIR__ . '/../common/order_report_lib.php';

$pdo = (new DBConnection())->getPDO();
$uid = (int)($_SESSION['id'] ?? 0);
$action = $_POST['action'] ?? $_GET['action'] ?? '';
function orpReply(array $d) { echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

switch ($action) {
    case 'options': {
        $oid = (int)($_POST['order_id'] ?? $_GET['order_id'] ?? 0);
        $sel = orp_get_for_order($pdo, $oid);
        orpReply(['success' => true, 'options' => orp_options($pdo, true, $sel['ids']), 'selected' => $sel]);
    }
    case 'defs': {
        if (!ot_is_admin($pdo, $uid)) { http_response_code(403); orpReply(['success' => false, 'message' => '只有管理員可以設定']); }
        orpReply(['success' => true, 'options' => orp_options($pdo, false), 'types' => orp_process_types($pdo)]);
    }
    case 'save_defs': {
        if (!ot_is_admin($pdo, $uid)) { http_response_code(403); orpReply(['success' => false, 'message' => '只有管理員可以設定']); }
        $rows = json_decode((string)($_POST['rows'] ?? '[]'), true);
        $r = orp_save_defs($pdo, is_array($rows) ? $rows : [], (string)($_SESSION['userName'] ?? ''));
        if (!$r['ok']) orpReply(['success' => false, 'message' => $r['msg']]);
        orpReply(['success' => true, 'options' => orp_options($pdo, false)]);
    }
    default: orpReply(['success' => false, 'message' => '無效的操作']);
}
