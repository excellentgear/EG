<?php
if (!isset($_SESSION)) {
    session_start();
}

// 引入設定與資料庫連線
include_once dirname(__DIR__) . '/common/_config.php';

header('Content-Type: application/json');

// 獲取目前登入的使用者 ID
$current_user_id = $_SESSION['id'] ?? 0;
if ($current_user_id === 0) {
    // 如果未登入，回傳空陣列並終止
    echo json_encode([]);
    exit();
}

// 檢查使用者是否為特殊職位 (position.id = 99)，可以看見所有事件
$is_special_user = false;
$pos_stmt = $db->prepare("SELECT 1 FROM user_department_position_map WHERE user_id = ? AND position_id = 99 LIMIT 1");
$pos_stmt->execute([$current_user_id]);
if ($pos_stmt->fetch()) {
    $is_special_user = true;
}

// 增加邏輯：若 user.state = 90，也可以看見所有事件
if (!$is_special_user) {
    $state_stmt = $db->prepare("SELECT state FROM user WHERE id = ? LIMIT 1");
    $state_stmt->execute([$current_user_id]);
    if ($state_stmt->fetchColumn() == 90) {
        $is_special_user = true;
    }
}

// 根據使用者身份決定權限過濾的 SQL 片段
$permission_filter_sql = "
    LEFT JOIN evenement_recipient_cache erc ON e.id = erc.event_id AND erc.user_id = :user_id
    LEFT JOIN evenement_target et_all ON e.id = et_all.event_id AND et_all.target_type = 'all'
    WHERE (erc.user_id IS NOT NULL OR et_all.event_id IS NOT NULL)
";

// 查詢使用者可見的事件，並同時彙整 actors 和 targets
$requete = "
    SELECT 
        e.*,
        ec.color,
        ec.id as category_id,
        ec.day_type,
        -- 彙整 actors
        (
            SELECT JSON_ARRAYAGG(JSON_OBJECT('id', u.id, 'name', u.user_cname, 'department_name', d.name, 'position_sort_order', p.sort_order))
            FROM evenement_actor ea
            JOIN user u ON ea.user_id = u.id
            LEFT JOIN user_department_position_map udpm ON u.id = udpm.user_id AND udpm.is_main = 1
            LEFT JOIN department d ON udpm.department_id = d.id
            LEFT JOIN position p ON udpm.position_id = p.id
            WHERE ea.event_id = e.id
        ) as actors_json,
        -- 彙整 targets
        (
            SELECT JSON_ARRAYAGG(JSON_OBJECT('type', et.target_type, 'id', COALESCE(et.target_id, NULL), 'name', CASE WHEN et.target_type = 'user' THEN u.user_cname WHEN et.target_type = 'department' THEN d.name ELSE '全體' END))
            FROM evenement_target et
            LEFT JOIN user u ON et.target_type = 'user' AND et.target_id = u.id
            LEFT JOIN department d ON et.target_type = 'department' AND et.target_id = d.id
            WHERE et.event_id = e.id
        ) as targets_json
    FROM evenement e
    LEFT JOIN event_category ec ON e.category_id = ec.id
    " . ($is_special_user ? '' : $permission_filter_sql) . "
    GROUP BY e.id
    ORDER BY e.id
 ";
 
 // connexion à la base de données
 try {
    // 使用 _config.php 中的 $db 連線物件
    $stmt = $db->prepare($requete);
    if (!$is_special_user) {
        $stmt->bindParam(':user_id', $current_user_id, PDO::PARAM_INT);
    }
    $stmt->execute();
 } catch(Exception $e) {
    exit('無法連接資料庫。');
 }
 
 $events = array();
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    // 解碼 actors 和 targets 的 JSON 字串
    $actors = !empty($row['actors_json']) ? json_decode($row['actors_json'], true) : [];
    $targets = !empty($row['targets_json']) ? json_decode($row['targets_json'], true) : [];

    $isAllDay = ($row['allday'] == 1);
    $recurrenceType = $row['recurrence_type'] ?? null;
    $recurrenceCount = isset($row['recurrence_count']) ? (int)$row['recurrence_count'] : 0;

    $isRecurring = $recurrenceType && $recurrenceCount > 0;

    try {
        $startDate = new DateTime($row['start']);
        $endDate = !empty($row['end']) ? new DateTime($row['end']) : null;

        // 儲存主要事件的原始開始時間，以便在重複事件中參考
        $mainEventStartString = $isAllDay ? $startDate->format('Y-m-d') : $startDate->format('Y-m-d\TH:i:s');
        // 新增：儲存主要事件的原始結束時間
        $mainEventEndString = $endDate ? ($isAllDay ? $endDate->format('Y-m-d') : $endDate->format('Y-m-d\TH:i:s')) : null;

        // 建立並加入主要事件
        $originalEvent = [
            'id' => $row['id'],
            'groupId' => $row['id'],
            'title' => $row['title'],
            'allDay' => $isAllDay,
            'start' => $isAllDay ? $startDate->format('Y-m-d') : $startDate->format('Y-m-d\TH:i:s'),
            'end' => $endDate ? ($isAllDay ? $endDate->format('Y-m-d') : $endDate->format('Y-m-d\TH:i:s')) : null,
            'event_type' => $isRecurring ? 'main_recurring' : 'single',
            'recurrence_type' => $recurrenceType,
            'recurrence_count' => $recurrenceCount,
            'actors' => $actors, // 加入發生者資訊
            'targets' => $targets, // 加入廣播對象資訊
            'color' => $row['color'], // 加入顏色
            'category_id' => $row['category_id'], // 加入類別 ID
            'remark' => $row['remark'], // 新增：加入備註
            'day_type' => $row['day_type'], // 新增：加入 day_type
            'leave_type_id' => $row['leave_type_id'], // 新增：加入假別 ID
        ];
        $events[] = $originalEvent;

        // 建立一個基礎模板，用於生成重複事件，避免直接複製 $originalEvent
        $recurringTemplate = [
            'groupId' => $row['id'],
            'title' => $row['title'],
            'allDay' => $isAllDay,
            'event_type' => 'recurring_instance',
            'actors' => $actors, // 重複事件也需要發生者資訊
            'targets' => $targets, // 重複事件也需要廣播對象資訊
            'color' => $row['color'], // 重複事件也需要顏色
            'category_id' => $row['category_id'], // 重複事件也需要類別 ID
            'remark' => $row['remark'], // 新增：重複事件也需要備註
            'day_type' => $row['day_type'], // 新增：重複事件也需要 day_type
            'leave_type_id' => $row['leave_type_id'], // 新增：重複事件也需要假別 ID
        ];

        // 如果是重複事件，生成後續事件
        if ($isRecurring) {
            $intervalString = '';
            switch ($recurrenceType) {
                case 'daily':   $intervalString = 'P1D'; break;
                case 'weekly':  $intervalString = 'P1W'; break;
                case 'monthly': $intervalString = 'P1M'; break;
                case 'yearly':  $intervalString = 'P1Y'; break;
            }

            if ($intervalString) {
                $interval = new DateInterval($intervalString);
                $currentStartDate = clone $startDate;
                $currentEndDate = $endDate ? clone $endDate : null;

                for ($i = 0; $i < $recurrenceCount; $i++) {
                    $currentStartDate->add($interval);
                    if ($currentEndDate) $currentEndDate->add($interval);

                    // 使用模板和新計算的日期來建立一個全新的重複事件陣列
                    $recurringEvent = array_merge($recurringTemplate, [
                        'id' => $row['id'] . '-' . ($i + 1), // 唯一的事件 ID
                        'start' => $isAllDay ? $currentStartDate->format('Y-m-d') : $currentStartDate->format('Y-m-d\TH:i:s'),
                        'end' => $currentEndDate ? ($isAllDay ? $currentEndDate->format('Y-m-d') : $currentEndDate->format('Y-m-d\TH:i:s')) : null,
                        
                        // 再次明確地加入重複規則和原始開始時間
                        'recurrence_type' => $recurrenceType,
                        'recurrence_count' => $recurrenceCount,
                        'original_start' => $mainEventStartString,
                        'original_end' => $mainEventEndString, // 新增：傳遞主事件的原始結束日期
                    ]);
                    
                    $events[] = $recurringEvent;
                }
            }
        }
    } catch (Exception $e) {
        // 如果日期格式錯誤，跳過此事件並記錄錯誤
        error_log("無法處理事件 ID {$row['id']} 的日期: " . $e->getMessage());
    }
}
 
 // envoi du résultat au success
 echo json_encode($events);
 
?>