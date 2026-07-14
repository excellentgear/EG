<?php
ob_start(); // 在檔案最頂端啟動輸出緩衝
if (!isset($_SESSION)) {
    session_start();
}

// --- 引入設定與初始化 ---
include_once dirname(__DIR__) . '/common/_config.php';
$is_ajax_request = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
$response = ['success' => false, 'message' => '未知錯誤'];
$msg = ''; // 用於儲存成功訊息

try {

/*-------行事曆--------*/
// 新增行程
if (isset($_POST["newSchdule"]) && empty($_POST["userid"]) && !empty($_POST["schdule_start"]) && !empty($_POST['schdule_end'])) {
    $is_all_day = isset($_POST['schdule_all_day']) && $_POST['schdule_all_day'] === 'on';

    if ($is_all_day) {
        $start_datetime = $_POST['schdule_start'];
        $end_datetime = $_POST['schdule_end'];
    } else {
        // 確保時間部分存在
        $start_hour = $_POST['start_hour'] ?? '00';
        $start_minute = $_POST['start_minute'] ?? '00';
        $end_hour = $_POST['end_hour'] ?? '00';
        $end_minute = $_POST['end_minute'] ?? '00'; // 修正：避免重複使用 $_POST['end_minute']
        $start_datetime = $_POST['schdule_start'] . ' ' . $start_hour . ':' . $start_minute . ':00';
        $end_datetime = $_POST['schdule_end'] . ' ' . $_POST['end_hour'] . ':' . $_POST['end_minute'] . ':00';
    }

    // 處理重複事件資料
    $recurrence_type = !empty($_POST['recurrence_type']) ? $_POST['recurrence_type'] : null;
    // 只有當 recurrence_type 有效時，才儲存 recurrence_count
    $recurrence_interval = ($recurrence_type !== null && isset($_POST['recurrence_interval'])) ? (int)$_POST['recurrence_interval'] : 1;
    $actors = $_POST['actors'] ?? []; // 取得發生者 ID 陣列
    $recurrence_count = ($recurrence_type !== null && isset($_POST['recurrence_count'])) ? (int)$_POST['recurrence_count'] : null;
    $category_id = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null; // 取得事件類別 ID
    $leave_type_id = !empty($_POST['leave_type']) ? (int)$_POST['leave_type'] : null; // 新增：取得假別 ID
    $remark = $_POST['remark'] ?? null; // 新增：取得備註內容

    // 處理標題：若未填寫標題，則使用事件類別名稱
    $title = $_POST['schdule_title'] ?? '';
    if (empty($title) && $category_id) {
        $stmt_cat = $db->prepare("SELECT category_name FROM event_category WHERE id = ?");
        $stmt_cat->execute([$category_id]);
        $cat_row = $stmt_cat->fetch(PDO::FETCH_ASSOC);
        if ($cat_row) {
            $title = $cat_row['category_name'];
        }
    }

    // 使用預備語句來防止 SQL 注入
    $sth = $db->prepare("INSERT INTO `evenement`(`title`, `start`, `end`, `allDay`, `recurrence_type`, `recurrence_interval`, `recurrence_count`, `category_id`, `remark`, `leave_type_id`) VALUES (:title, :start, :end, :all_day, :recurrence_type, :recurrence_interval, :recurrence_count, :category_id, :remark, :leave_type_id)");
    
    // 綁定參數並執行
    $sth->execute([ // 修正：在 execute 陣列中加入 leave_type_id
        ':title' => $title,
        ':start' => $start_datetime,
        ':end'   => $end_datetime,
        ':all_day' => $is_all_day ? 1 : 0,
        ':recurrence_type' => $recurrence_type,
        ':recurrence_interval' => $recurrence_interval,
        ':recurrence_count' => $recurrence_count,
        ':category_id' => $category_id,
        ':remark' => $remark,
        ':leave_type_id' => $leave_type_id
    ]);

    // 處理發生者
    $event_id = $db->lastInsertId();
    if (!empty($actors) && $event_id) {
        $actor_sth = $db->prepare("INSERT INTO evenement_actor (event_id, user_id, created_at) VALUES (:event_id, :user_id, NOW())");
        foreach ($actors as $user_id) {
            $actor_sth->execute([
                ':event_id' => $event_id,
                ':user_id'  => $user_id
            ]);
        }
    }

    // --- 處理廣播對象 (新增) ---
    if ($event_id) {
        // 1. 清空舊的快取和目標設定 (雖然是新增，但以防萬一)
        $db->prepare("DELETE FROM evenement_target WHERE event_id = ?")->execute([$event_id]);
        $db->prepare("DELETE FROM evenement_recipient_cache WHERE event_id = ?")->execute([$event_id]);

        $is_target_all = isset($_POST['target_all']) || empty($_POST['targets']);
        $recipient_user_ids = [];

        if ($is_target_all) {
            // 寫入目標表
            $db->prepare("INSERT INTO evenement_target (event_id, target_type, created_at) VALUES (?, 'all', NOW())")->execute([$event_id]);
            // 取得所有有效使用者
            // 修正：確保查詢的是 user 表的 id
            $user_stmt = $db->query("SELECT id FROM user WHERE state NOT IN (0, 90) AND state IS NOT NULL");
            $recipient_user_ids = $user_stmt->fetchAll(PDO::FETCH_COLUMN);
        } else {
            $targets = $_POST['targets'] ?? [];
            $target_users = [];
            $target_depts = [];

            foreach ($targets as $target) {
                list($typePrefix, $id) = explode('-', $target, 2);
                $id = (int)$id;

                // 修正：將 'dept' 前綴轉換為 'department'
                $targetType = '';
                if ($typePrefix === 'dept') {
                    $targetType = 'department';
                } elseif ($typePrefix === 'user') {
                    $targetType = 'user';
                }

                // 寫入目標表
                $db->prepare("INSERT INTO evenement_target (event_id, target_type, target_id, created_at) VALUES (?, ?, ?, NOW())")->execute([$event_id, $targetType, $id]);

                if ($targetType === 'user') {
                    $target_users[] = $id;
                } elseif ($targetType === 'department') {
                    $target_depts[] = $id;
                }
            }
            
            $recipient_user_ids = $target_users;

            if (!empty($target_depts)) {
                // 使用遞迴查詢找出部門及其所有子部門
                $dept_ids_placeholders = implode(',', array_fill(0, count($target_depts), '?'));
                $sql = "
                    WITH RECURSIVE dept_tree AS (
                        SELECT id FROM department WHERE id IN ($dept_ids_placeholders)
                        UNION ALL
                        SELECT d.id FROM department d INNER JOIN dept_tree dt ON d.parent_id = dt.id
                    )
                    SELECT DISTINCT user_id FROM user_department_position_map WHERE department_id IN (SELECT id FROM dept_tree);
                ";
                $stmt = $db->prepare($sql);
                $stmt->execute($target_depts);
                $dept_user_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
                $recipient_user_ids = array_merge($recipient_user_ids, $dept_user_ids);
            }
        }

        // 寫入快取表
        $unique_recipient_ids = array_unique($recipient_user_ids);
        if (!empty($unique_recipient_ids)) {
            $cache_sth = $db->prepare("INSERT INTO evenement_recipient_cache (event_id, user_id, created_at) VALUES (?, ?, NOW())");
            foreach ($unique_recipient_ids as $user_id) {
                $cache_sth->execute([$event_id, $user_id]);
            }
        }
    }

    // 新增：將新事件的 ID 存入 session，以便前端聚焦
    $_SESSION['focusEventId'] = $event_id;

    // 修正：優先使用行事曆檢視日期，若無則使用事件開始日期，以便編輯後跳轉
    if (isset($_POST['current_calendar_date']) && !empty($_POST['current_calendar_date'])) {
        $_SESSION['gotoDate'] = $_POST['current_calendar_date'];
    } else {
        $_SESSION['gotoDate'] = $_POST['schdule_start'];
    }

    unset($_POST['schdule_title'], $_POST['schdule_start'], $_POST['schdule_end']);
    $msg = '新增成功！';

} 
// 更新行程
else if (isset($_POST["newSchdule"]) && !empty($_POST["userid"]) && !empty($_POST["schdule_start"]) && !empty($_POST['schdule_end'])) {
    $is_all_day = isset($_POST['schdule_all_day']) && $_POST['schdule_all_day'] === 'on';

    // 簡化邏輯：前端現在會將正確的基準日期填入 schdule_start 和 schdule_end，所以後端統一處理即可。
    if ($is_all_day) {
        $start_datetime = $_POST['schdule_start'];
        $end_datetime = $_POST['schdule_end'];
    } else {
        $start_hour = $_POST['start_hour'] ?? '00';
        $start_minute = $_POST['start_minute'] ?? '00';
        $end_hour = $_POST['end_hour'] ?? '00';
        $end_minute = $_POST['end_minute'] ?? '00'; // 修正：避免重複使用 $_POST['end_minute']
        $start_datetime = $_POST['schdule_start'] . ' ' . $start_hour . ':' . $start_minute . ':00';
        $end_datetime = $_POST['schdule_end'] . ' ' . $end_hour . ':' . $end_minute . ':00';
    }

    // 處理重複事件資料
    $recurrence_type = !empty($_POST['recurrence_type']) ? $_POST['recurrence_type'] : null;
    $recurrence_interval = ($recurrence_type !== null && isset($_POST['recurrence_interval'])) ? (int)$_POST['recurrence_interval'] : 1;
    $recurrence_count = ($recurrence_type !== null && isset($_POST['recurrence_count'])) ? (int)$_POST['recurrence_count'] : null;
    $actors = $_POST['actors'] ?? []; // 取得發生者 ID 陣列
    $category_id = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null; // 取得事件類別 ID
    $leave_type_id = !empty($_POST['leave_type']) ? (int)$_POST['leave_type'] : null; // 新增：取得假別 ID
    $remark = $_POST['remark'] ?? null; // 新增：取得備註內容

    // 處理標題：若未填寫標題，則使用事件類別名稱
    $title = $_POST['schdule_title'] ?? '';
    if (empty($title) && $category_id) {
        $stmt_cat = $db->prepare("SELECT category_name FROM event_category WHERE id = ?");
        $stmt_cat->execute([$category_id]);
        $cat_row = $stmt_cat->fetch(PDO::FETCH_ASSOC);
        if ($cat_row) {
            $title = $cat_row['category_name'];
        }
    }

    // 我們只更新主事件。如果 userid 包含 '-'，表示它是一個重複實例的 ID，我們需要取出原始 ID。
    $event_id_to_update = explode('-', $_POST['userid'])[0];

    // 使用預備語句來防止 SQL 注入
    $sth = $db->prepare("UPDATE evenement SET title = :title, start = :start, end = :end, allDay = :all_day, recurrence_type = :recurrence_type, recurrence_interval = :recurrence_interval, recurrence_count = :recurrence_count, category_id = :category_id, remark = :remark, leave_type_id = :leave_type_id WHERE id = :id");

    $sth->execute([
        ':title' => $title,
        ':start' => $start_datetime,
        ':end'   => $end_datetime,
        ':all_day' => $is_all_day ? 1 : 0,
        ':recurrence_type' => $recurrence_type,
        ':recurrence_interval' => $recurrence_interval,
        ':recurrence_count' => $recurrence_count,
        ':category_id' => $category_id,
        ':remark' => $remark,
        ':leave_type_id' => $leave_type_id,
        ':id'    => $event_id_to_update
    ]);

    // 更新發生者：先刪除舊的，再新增新的
    if ($event_id_to_update) {
        // 1. 刪除舊的 actor 關聯。
        $del_actor_sth = $db->prepare("DELETE FROM evenement_actor WHERE event_id = :event_id");
        $del_actor_sth->execute([':event_id' => $event_id_to_update]);

        // 2. 如果有提供新的 actor，則新增關聯。
        if (!empty($actors)) {
            $actor_sth = $db->prepare("INSERT INTO evenement_actor (event_id, user_id, created_at) VALUES (:event_id, :user_id, NOW())");
            foreach ($actors as $user_id) {
                $actor_sth->execute([':event_id' => $event_id_to_update, ':user_id' => $user_id]);
            }
        }
    }

    // 在更新邏輯中，將事件 ID 設為待處理的 ID，以便後續的廣播對象處理邏輯能夠使用
    $event_id = $event_id_to_update;

    // 新增：將更新事件的 ID 存入 session，以便前端聚焦
    $_SESSION['focusEventId'] = $event_id_to_update;

    // --- 處理廣播對象 (更新) ---
    if ($event_id_to_update) {
        // 1. 清空舊的快取和目標設定
        $db->prepare("DELETE FROM evenement_target WHERE event_id = ?")->execute([$event_id_to_update]);
        $db->prepare("DELETE FROM evenement_recipient_cache WHERE event_id = ?")->execute([$event_id_to_update]);

        $is_target_all = isset($_POST['target_all']) || empty($_POST['targets']);
        $recipient_user_ids = [];

        if ($is_target_all) {
            // 寫入目標表
            $db->prepare("INSERT INTO evenement_target (event_id, target_type, created_at) VALUES (?, 'all', NOW())")->execute([$event_id_to_update]);
            // 取得所有有效使用者
            // 修正：確保查詢的是 user 表的 id
            $user_stmt = $db->query("SELECT id FROM user WHERE state NOT IN (0, 90) AND state IS NOT NULL");
            $recipient_user_ids = $user_stmt->fetchAll(PDO::FETCH_COLUMN);
        } else {
            $targets = $_POST['targets'] ?? [];
            $target_users = [];
            $target_depts = [];

            foreach ($targets as $target) {
                list($typePrefix, $id) = explode('-', $target, 2);
                $id = (int)$id;

                // 修正：將 'dept' 前綴轉換為 'department'
                $targetType = '';
                if ($typePrefix === 'dept') {
                    $targetType = 'department';
                } elseif ($typePrefix === 'user') {
                    $targetType = 'user';
                }

                // 寫入目標表
                $db->prepare("INSERT INTO evenement_target (event_id, target_type, target_id, created_at) VALUES (?, ?, ?, NOW())")->execute([$event_id_to_update, $targetType, $id]);

                if ($targetType === 'user') {
                    $target_users[] = $id;
                } elseif ($targetType === 'department') {
                    $target_depts[] = $id;
                }
            }
            
            $recipient_user_ids = $target_users;

            if (!empty($target_depts)) {
                // 使用遞迴查詢找出部門及其所有子部門
                $dept_ids_placeholders = implode(',', array_fill(0, count($target_depts), '?'));
                $sql = "
                    WITH RECURSIVE dept_tree AS (
                        SELECT id FROM department WHERE id IN ($dept_ids_placeholders)
                        UNION ALL
                        SELECT d.id FROM department d INNER JOIN dept_tree dt ON d.parent_id = dt.id
                    )
                    SELECT DISTINCT user_id FROM user_department_position_map WHERE department_id IN (SELECT id FROM dept_tree);
                ";
                $stmt = $db->prepare($sql);
                $stmt->execute($target_depts);
                $dept_user_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
                $recipient_user_ids = array_merge($recipient_user_ids, $dept_user_ids);
            }
        }

        // 寫入快取表
        $unique_recipient_ids = array_unique($recipient_user_ids);
        if (!empty($unique_recipient_ids)) {
            $cache_sth = $db->prepare("INSERT INTO evenement_recipient_cache (event_id, user_id, created_at) VALUES (?, ?, NOW())");
            foreach ($unique_recipient_ids as $user_id) {
                $cache_sth->execute([$event_id_to_update, $user_id]);
            }
        }
    }

    // 修正：優先使用行事曆檢視日期，若無則使用事件開始日期，以便編輯後跳轉
    if (isset($_POST['current_calendar_date']) && !empty($_POST['current_calendar_date'])) {
        $_SESSION['gotoDate'] = $_POST['current_calendar_date'];
    } else {
        $_SESSION['gotoDate'] = $_POST['schdule_start'];
    }

    unset($_POST['schdule_title'], $_POST['schdule_start'], $_POST['schdule_end'], $_SESSION['schdule_title'], $_SESSION['schdule_start'], $_SESSION['schdule_end'], $_SESSION['userid']);
    $msg = '更改成功！';
}


// 清除表單中的 session 資料 (用於取消按鈕)
if (isset($_POST["resetCalendar"])) {
    unset($_SESSION['schdule_title']);
    unset($_SESSION['schdule_start']);
    unset($_SESSION['schdule_end']);
    unset($_SESSION['userid']);
}

} catch (PDOException $e) {
    // 捕捉資料庫相關的錯誤
    $response['message'] = '資料庫操作失敗：' . $e->getMessage();
} catch (Exception $e) {
    // 捕捉其他所有類型的錯誤
    $response['message'] = '伺服器內部錯誤：' . $e->getMessage();
}
?>
<?php
// --- 腳本結尾的回應處理 ---

// 只有在表單提交時才執行回應邏輯
if (isset($_POST["newSchdule"])) {
    if ($is_ajax_request) {
        ob_end_clean(); // 清除所有可能的意外輸出
        header('Content-Type: application/json');
        if (!empty($msg)) { // 如果 try 區塊成功執行並設定了 $msg
            $response['success'] = true;
            $response['message'] = $msg;
        }
        echo json_encode($response);
    } else {
        // 對於非 AJAX 請求，維持傳統的頁面跳轉
        $redirect_id = $_POST['id'] ?? '';
        header("Location: ../../views/pages/calendar.php?id=" . urlencode($redirect_id));
    }
    exit();
}
?>