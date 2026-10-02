<?php
// views/pages/gear_spec_api.php
// 齒輪規格設定元件（views/pages/_gear_spec_ui.php）要用的字典——**唯一端點**。
// 2026-10-02 由 master_data_management.php 內嵌的四支 action（manage_gear_types op=list／
// get_chain_sizes／get_belt_profiles／manage_gear_quality op=get_ref）收斂而來，
// 讓主檔管理與報價單管理的「新增料號」拿到的是同一份字典。
// 只讀不寫；齒輪類型／鏈條規格／皮帶齒型／齒輪等級的「維護(CRUD)」仍留在主檔管理那一頁。
ini_set('session.gc_maxlifetime', 43200);
session_set_cookie_params(43200);
session_start();

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['userName'])) {
    echo json_encode(['success' => false, 'message' => '尚未登入或連線逾時，請重新登入']);
    exit;
}

include_once __DIR__ . '/../../src/common/DBConnection.php';
require_once __DIR__ . '/../../src/common/gear_spec_lib.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';
try {
    $pdo = (new DBConnection())->getPDO();
    if ($action === 'dicts') {
        echo json_encode(['success' => true, 'data' => eg_gear_spec_dicts($pdo)], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(['success' => false, 'message' => '無效的操作']);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
