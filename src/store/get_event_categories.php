<?php
session_start();
header('Content-Type: application/json');

// 包含資料庫設定檔
include_once dirname(__DIR__) . '/common/_config.php';

$response = [
    'success' => false,
    'categories' => []
];

try {
    // 查詢所有事件類別，包含備註
    $sql = "SELECT id, category_name, color, description,day_type FROM event_category ORDER BY category_name ASC";
    $stmt = $db->query($sql);
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 直接回傳從資料庫取得的陣列
    echo json_encode($categories);

} catch (PDOException $e) {
    // 如果出錯，回傳一個空的 JSON 陣列
    echo json_encode([]);
}
?>