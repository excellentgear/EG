<?php
// BomReport_API.php — BOM「訂單要求檢附的報告」查詢（唯讀；判定在 src/common/bom_report_lib.php）
//   action=map            BOM 總表用：進行中且有勾報告的 BOM → 報告清單／缺哪些／是否到關卡
//   action=status&bom=..  單張 BOM（完工／結案提示、快速出貨提示用）
session_start();
require_once __DIR__ . '/../common/api_guard.php';
header('Content-Type: application/json; charset=utf-8');
if (!isset($_SESSION['userName'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '未登入']);
    exit;
}
require_once __DIR__ . '/../common/DBConnection.php';
require_once __DIR__ . '/../common/bom_report_lib.php';
$pdo = (new DBConnection())->getPDO();
$action = $_POST['action'] ?? $_GET['action'] ?? '';
try {
    if ($action === 'map') {
        $m = brp_open_map($pdo);
        echo json_encode(['success' => true, 'map' => $m ?: new stdClass()], JSON_UNESCAPED_UNICODE);
    } elseif ($action === 'status') {
        $bom = trim((string)($_POST['bom'] ?? $_GET['bom'] ?? ''));
        $m = brp_required_map($pdo, [$bom]);
        echo json_encode(['success' => true, 'info' => $m[$bom] ?? null], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['success' => false, 'message' => '無效的操作']);
    }
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => '查詢失敗']);
}
