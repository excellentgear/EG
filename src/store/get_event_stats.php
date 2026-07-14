<?php
header('Content-Type: application/json');

include ("../common/DBConnection.php");

// 建立資料庫連線物件
$db = (new DBConnection())->getPDO();

$year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');
$month_str = isset($_GET['month']) ? $_GET['month'] : date('Y-m');

$response = [
    'current_month' => [],
    'previous_month' => [],
    'current_year' => []
];

try {
    // 1. 取得所有事件，包含重複規則和類別資訊
    $sql = "
        SELECT 
            e.id, e.start, e.end, e.allday, 
            e.recurrence_type, e.recurrence_count,
            ec.id AS category_id, ec.category_name, ec.color
        FROM evenement e
        JOIN event_category ec ON e.category_id = ec.id
        ORDER BY e.start;
    ";
    $stmt = $db->query($sql);
    $all_events = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. 輔助函式：計算指定期間的統計數據
    function getStatsForPeriod($all_events, $periodStartStr, $periodEndStr) {
        $periodStart = new DateTime($periodStartStr);
        $periodEnd = (new DateTime($periodEndStr))->setTime(23, 59, 59); // 包含結束當天

        $categoryStats = [];

        foreach ($all_events as $event) {
            if (empty($event['category_id'])) continue;

            // 初始化統計陣列
            if (!isset($categoryStats[$event['category_id']])) {
                $categoryStats[$event['category_id']] = [
                    'category_id' => $event['category_id'],
                    'category_name' => $event['category_name'],
                    'color' => $event['color'],
                    'count' => 0
                ];
            }

            $isRecurring = !empty($event['recurrence_type']) && $event['recurrence_count'] > 0;

            // 處理主事件及後續的重複實例
            $loopCount = $isRecurring ? $event['recurrence_count'] + 1 : 1;
            $intervalString = '';
            if ($isRecurring) {
                switch ($event['recurrence_type']) {
                    case 'daily':   $intervalString = 'P1D'; break;
                    case 'weekly':  $intervalString = 'P1W'; break;
                    case 'monthly': $intervalString = 'P1M'; break;
                    case 'yearly':  $intervalString = 'P1Y'; break;
                }
            }

            $currentStart = new DateTime($event['start']);
            $eventEndDt = !empty($event['end']) ? new DateTime($event['end']) : clone $currentStart;
            $eventDuration = $currentStart->diff($eventEndDt);

            for ($i = 0; $i < $loopCount; $i++) {
                if ($i > 0 && $intervalString) {
                    $interval = new DateInterval($intervalString);
                    $currentStart->add($interval);
                }

                $currentEnd = (clone $currentStart)->add($eventDuration);

                // --- 計算重疊天數 ---
                // 確保事件的結束時間至少和開始時間是同一天
                $instanceStart = (clone $currentStart)->setTime(0, 0, 0);
                // 修正：對於非全天事件，結束時間應設為當天的結束，以確保 diff 計算能包含最後一天
                if ($event['allday'] == 0) {
                    $instanceEnd = (clone $currentEnd)->setTime(23, 59, 59);
                } else {
                    $instanceEnd = (clone $currentEnd)->setTime(0, 0, 0);
                }
                if ($event['allday'] == 1 && $instanceEnd > $instanceStart) {
                    $instanceEnd->modify('-0 day');
                }

                // 找出事件與統計期間的交集
                $overlapStart = max($periodStart, $instanceStart);
                $overlapEnd = min($periodEnd, $instanceEnd);

                if ($overlapStart <= $overlapEnd) {
                    // 如果有重疊，計算重疊的天數
                    $days = $overlapStart->diff($overlapEnd)->days + 1;
                    $categoryStats[$event['category_id']]['count'] += $days;
                }
            }
        }

        // 移除 count 為 0 的類別並排序
        $result = array_filter(array_values($categoryStats), function($stat) {
            return $stat['count'] > 0;
        });

        usort($result, function($a, $b) {
            return $b['count'] <=> $a['count'];
        });

        return $result;
    }

    // --- 1. Current Month Stats ---
    $currentMonthStart = date('Y-m-01', strtotime($month_str));
    $currentMonthEnd = date('Y-m-t', strtotime($month_str));
    $response['current_month'] = getStatsForPeriod($all_events, $currentMonthStart, $currentMonthEnd);

    // --- 2. Previous Month Stats ---
    $prevMonthDate = (new DateTime($currentMonthStart))->modify('-1 month');
    $prevMonthStart = $prevMonthDate->format('Y-m-01');
    $prevMonthEnd = $prevMonthDate->format('Y-m-t');
    $response['previous_month'] = getStatsForPeriod($all_events, $prevMonthStart, $prevMonthEnd);

    // --- 3. Current Year Stats ---
    $yearStart = $year . '-01-01';
    $yearEnd = $year . '-12-31';
    $response['current_year'] = getStatsForPeriod($all_events, $yearStart, $yearEnd);

} catch (PDOException $e) {
    // In case of error, return an error message
    http_response_code(500);
    $response['error'] = "Database error: " . $e->getMessage();
}

echo json_encode($response);

?>