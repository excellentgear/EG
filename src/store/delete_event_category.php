<?php
// c:\MAMP\htdocs\EGsystem\src\store\delete_event_category.php

session_start();
header('Content-Type: application/json');

// 引入設定與資料庫連線
include_once dirname(__DIR__) . '/common/_config.php';

$response = ['success' => false, 'message' => '無效的請求。'];

// 檢查使用者是否登入 (可選，但建議)
if (!isset($_SESSION['id'])) {
    $response['message'] = '使用者未登入，無法執行操作。';
    echo json_encode($response);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'] ?? 0;

    if (empty($id) || !is_numeric($id)) {
        $response['message'] = '缺少有效的類別 ID。';
        echo json_encode($response);
        exit;
    }

    try {
        $db->beginTransaction();

        // 1. 將使用此類別的事件的 category_id 設為 NULL
        $updateEventsStmt = $db->prepare("UPDATE evenement SET category_id = NULL WHERE category_id = :id");
        $updateEventsStmt->bindParam(':id', $id, PDO::PARAM_INT);
        $updateEventsStmt->execute();

        // 2. 刪除類別本身
        $deleteStmt = $db->prepare("DELETE FROM event_category WHERE id = :id");
        $deleteStmt->bindParam(':id', $id, PDO::PARAM_INT);
        
        if ($deleteStmt->execute() && $deleteStmt->rowCount() > 0) {
            $db->commit();
            $response = ['success' => true, 'message' => '類別已成功刪除。'];
        } else {
            $db->rollBack();
            $response['message'] = '刪除失敗，找不到對應的類別或資料庫操作錯誤。';
        }
    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $response['message'] = '資料庫錯誤：' . $e->getMessage();
    }
}

echo json_encode($response);
?>