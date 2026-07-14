<?php
session_start();
header('Content-Type: application/json');

// 包含資料庫設定檔
include_once dirname(__DIR__) . '/common/_config.php';

$response = [
    'success' => false,
    'targets' => []
];

if (isset($_GET['event_id'])) {
    $eventId = $_GET['event_id'];

    try {
        // 使用 LEFT JOIN 根據 target_type 查詢對應的名稱
        $sql = "SELECT 
                    et.target_type, 
                    et.target_id,
                    CASE 
                        WHEN et.target_type = 'user' THEN u.user_cname
                        WHEN et.target_type = 'department' THEN d.name
                    END AS target_name
                FROM evenement_target et
                LEFT JOIN user u ON et.target_type = 'user' AND et.target_id = u.id
                LEFT JOIN department d ON et.target_type = 'department' AND et.target_id = d.id
                WHERE et.event_id = :event_id";
        $stmt = $db->prepare($sql);
        $stmt->bindParam(':event_id', $eventId, PDO::PARAM_INT);
        $stmt->execute();
        $targets = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response['success'] = true;
        $response['targets'] = $targets;

    } catch (PDOException $e) {
        $response['message'] = '資料庫查詢失敗：' . $e->getMessage();
    }
} else {
    $response['message'] = '缺少事件 ID。';
}

echo json_encode($response);
?>