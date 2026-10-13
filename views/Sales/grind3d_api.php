<?php
// c:\MAMP\htdocs\EGsystem\views\Sales\grind3d_api.php
// 砂輪干涉 3D 模擬 API（唯一端點）。實作全在 src/common/grind3d_tool_lib.php。
//
// 本工具的「計算」完全在瀏覽器端做、不需要後端；這支端點只服務 2026-10-13 新增的
// 「砂輪尺寸預設」——砂輪是現場共用設備，預設值必須全廠共用，所以存資料庫而不是
// 瀏覽器 localStorage（存 localStorage 會變成每個人每台電腦各自重打一次）。
//
// 比照 views/Sales/gear_tool_api.php 的寫法：session 放寬、登入守門、權限守門，
// 真正的邏輯一律在共用庫，不在這裡寫第二份規則。
ini_set('session.gc_maxlifetime', 43200);
session_set_cookie_params(43200);
session_start();

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['userName'])) {
    echo json_encode(['success' => false, 'message' => '尚未登入或連線逾時，請重新登入']);
    exit;
}

include_once __DIR__ . '/../../src/common/DBConnection.php';
require_once __DIR__ . '/../../src/common/grind3d_tool_lib.php';

$pdo = (new DBConnection())->getPDO();
$uid = (int)($_SESSION['id'] ?? 0);

// 權限守門：畫面上看得到這個工具的人才可以呼叫（不可只擋前端＝鐵律8）
if (!grind3d_tool_can_use($pdo, $uid)) {
    echo json_encode(['success' => false, 'message' => '無砂輪干涉 3D 模擬的使用權限']);
    exit;
}

$action  = (string)($_POST['action'] ?? $_GET['action'] ?? '');
$isAdmin = grind3d_tool_is_admin();

try {
    if ($action === 'presets_get') {
        echo json_encode(['success' => true,
            'rows'     => grind3d_presets_get($pdo),
            'can_edit' => $isAdmin]);
        exit;
    }

    if ($action === 'presets_save') {
        // 讀取開放給所有使用者，**寫入限管理員**（比照齒輪計算工具的「預留量管理」：
        // 那也是全廠共用的對照表，由管理員維護，一般使用者只是取用）。
        if (!$isAdmin) throw new Exception('只有系統管理員可以維護砂輪預設');
        $rows = json_decode((string)($_POST['rows'] ?? '[]'), true);
        if (!is_array($rows)) throw new Exception('資料格式不正確');
        echo json_encode(['success' => true,
            'rows'     => grind3d_presets_save($pdo, $rows, $uid),
            'can_edit' => true]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => '不支援的操作：' . $action]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
