<?php
// delete_is_records.php
// 批次刪除出貨資料 (需權限 A 或含 D)
session_start();
if (!isset($_SESSION['userName'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => '未登入']);
    exit;
}

header('Content-Type: application/json');

include '../../src/common/DBConnection.php';
include '../../src/store/_setting.php';
include '../../src/common/_config.php';

$response = ['success' => false, 'message' => ''];

try {
    $conn = new DBConnection();
    $pdo  = $conn->getPDO();

    $ids = $_POST['ids'] ?? [];
    if (is_string($ids)) {
        $ids = json_decode($ids, true);
    }

    if (empty($ids) || !is_array($ids)) {
        $response['message'] = '請提供要刪除的 ID 清單';
        echo json_encode($response);
        exit;
    }

    // 過濾確保全為整數，防止 SQL Injection
    $ids = array_map('intval', $ids);
    $ids = array_filter($ids, function($v) { return $v > 0; });

    if (empty($ids)) {
        $response['message'] = 'ID 格式錯誤';
        echo json_encode($response);
        exit;
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("DELETE FROM is_list WHERE IS_id IN ($placeholders)");
    $stmt->execute(array_values($ids));

    $affected = $stmt->rowCount();
    $response['success'] = true;
    $response['message'] = "已刪除 {$affected} 筆資料";
    $response['affected'] = $affected;

} catch (Exception $e) {
    $response['message'] = '刪除失敗: ' . $e->getMessage();
}

echo json_encode($response);
