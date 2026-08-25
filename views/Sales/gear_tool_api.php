<?php
// c:\MAMP\htdocs\EGsystem\views\Sales\gear_tool_api.php
// 齒輪／花鍵計算工具 API（唯一端點）。實作全在 src/common/gear_tool_lib.php。
// 2026-08-25 由 NewOrder_Track.php 內嵌的 gear_*/spline_* action 抽出，讓多個頁面共用同一份規則。
ini_set('session.gc_maxlifetime', 43200);
session_set_cookie_params(43200);
session_start();

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['userName'])) {
    echo json_encode(['success' => false, 'message' => '尚未登入或連線逾時，請重新登入']);
    exit;
}

include_once __DIR__ . '/../../src/common/DBConnection.php';
require_once __DIR__ . '/../../src/common/gear_tool_lib.php';

$conn = new DBConnection();
$pdo  = $conn->getPDO();

// 權限守門：畫面上看得到按鈕的人才可以呼叫（不可只擋前端＝鐵律8）
if (!gear_tool_can_use($pdo, intval($_SESSION['id'] ?? 0))) {
    echo json_encode(['success' => false, 'message' => '無齒輪計算工具使用權限']);
    exit;
}

gear_tool_handle_action($pdo);

// 走到這裡＝action 不在本工具的範圍內
echo json_encode(['success' => false, 'message' => '無效的操作']);
