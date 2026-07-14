<?php
// bind_is_record.php
// 綁定出貨資料到 d_setting (料號) 及更新 Client_id (客戶)
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

    $is_id      = intval($_POST['is_id'] ?? 0);
    $d_setting_id = !empty($_POST['d_setting_id']) ? intval($_POST['d_setting_id']) : null;
    $client_id  = !empty($_POST['client_id']) ? trim($_POST['client_id']) : null;
    $client_name = trim($_POST['client_name'] ?? '');

    if ($is_id <= 0) {
        $response['message'] = '無效的出貨記錄 ID';
        echo json_encode($response);
        exit;
    }

    // 若有提供 d_setting_id，驗證存在
    if ($d_setting_id !== null) {
        $chk = $pdo->prepare("SELECT d_id FROM d_setting WHERE d_id = ? LIMIT 1");
        $chk->execute([$d_setting_id]);
        if (!$chk->fetch()) {
            $response['message'] = '找不到指定料號 (d_setting_id=' . $d_setting_id . ')';
            echo json_encode($response);
            exit;
        }
    }

    // 若有提供 client_id，驗證存在
    if ($client_id !== null && $client_id !== '') {
        $chk2 = $pdo->prepare("SELECT customer_id FROM customer_list WHERE customer_id = ? LIMIT 1");
        $chk2->execute([$client_id]);
        if (!$chk2->fetch()) {
            // client_id 不存在，只允許更新 client_name，不更新 client_id
            $client_id = null;
        }
    }

    // 執行更新
    $set_parts = [];
    $params    = [];

    // d_setting_id (允許設為 NULL 以清除綁定)
    $set_parts[] = 'd_setting_id = ?';
    $params[]    = $d_setting_id;

    // Client_id
    if ($client_id !== null && $client_id !== '') {
        $set_parts[] = 'Client_id = ?';
        $params[]    = $client_id;
    }

    // Client_name (同步更新，保持與客戶名稱一致)
    if ($client_name !== '') {
        $set_parts[] = 'Client_name = ?';
        $params[]    = $client_name;
    } elseif ($client_id !== null) {
        // 若有 client_id 但沒有 client_name，從 customer_list 查詢
        $stmt_name = $pdo->prepare("SELECT customer FROM customer_list WHERE customer_id = ? LIMIT 1");
        $stmt_name->execute([$client_id]);
        $fetched_name = $stmt_name->fetchColumn();
        if ($fetched_name) {
            $set_parts[] = 'Client_name = ?';
            $params[]    = $fetched_name;
        }
    }

    if (empty($set_parts)) {
        $response['message'] = '沒有要更新的欄位';
        echo json_encode($response);
        exit;
    }

    $params[] = $is_id;
    $sql_upd  = "UPDATE is_list SET " . implode(', ', $set_parts) . " WHERE IS_id = ?";
    $stmt_upd = $pdo->prepare($sql_upd);
    $stmt_upd->execute($params);

    $response['success'] = true;
    $response['message'] = '綁定已儲存';

} catch (Exception $e) {
    $response['message'] = '儲存失敗: ' . $e->getMessage();
}

echo json_encode($response);
