<?php
include_once dirname(__DIR__) . '/common/DBConnection.php';
$db = (new DBConnection())->getPDO();

$date = $_GET['date'] ?? date('Y-m-d');

try {
    // 查詢指定日期有「休假」(category_id=1) 的使用者 ID
    // 判斷標準：事件包含該日期
    // 全天事件：start <= date < end (FullCalendar end is exclusive)
    // 非全天：DATE(start) <= date <= DATE(end) (簡化判斷，視為當天有休假)
    
    $sql = "
        SELECT DISTINCT ea.user_id
        FROM evenement e
        JOIN evenement_actor ea ON e.id = ea.event_id
        WHERE e.category_id = 1
        AND (
            (e.allDay = 1 AND e.start <= :date1 AND IFNULL(e.end, DATE_ADD(e.start, INTERVAL 1 DAY)) > :date2)
            OR
            (e.allDay = 0 AND DATE(e.start) <= :date3 AND DATE(IFNULL(e.end, e.start)) >= :date4)
        )
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([
        ':date1' => $date, ':date2' => $date,
        ':date3' => $date, ':date4' => $date
    ]);
    
    $userIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'users' => $userIds]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}