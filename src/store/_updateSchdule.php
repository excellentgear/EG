<?php
 if (!isset($_SESSION)){
    session_start();
    }
include("../../src/common/_config.php"); 

    // ✅【安全性修正】: 使用參數化查詢防止 SQL Injection
    $update_id = $_GET['updateid'] ?? 0;
    $cmd = $db->prepare("SELECT * FROM evenement where id = :updateid");
    $cmd->bindParam(':updateid', $update_id, PDO::PARAM_INT);
    $cmd->execute();
    $row = $cmd->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        $_SESSION['userid'] = $row['id'];
        $_SESSION['schdule_title'] = $row['title'];
        $_SESSION['schdule_start'] = $row['start'];
        $_SESSION['schdule_end'] = $row['end'];
        $_SESSION['schdule_all_day'] = $row['allDay'];
        $_SESSION['schdule_category_id'] = $row['category_id'];
        // ✅【功能修正】: 新增 leave_type_id 到 Session，解決假別無法帶入的問題
        $_SESSION['leave_type_id'] = $row['leave_type_id'];
        $_SESSION['remark'] = $row['remark'];
        $_SESSION['recurrence_type'] = $row['recurrence_type'];
        $_SESSION['recurrence_interval'] = $row['recurrence_interval'];
        $_SESSION['recurrence_count'] = $row['recurrence_count'];

        // 處理發生者 (Actors)
        $actor_stmt = $db->prepare("SELECT user_id FROM evenement_actor WHERE event_id = :event_id");
        $actor_stmt->bindParam(':event_id', $update_id, PDO::PARAM_INT);
        $actor_stmt->execute();
        $_SESSION['actors'] = $actor_stmt->fetchAll(PDO::FETCH_COLUMN);

    } else {
        // 如果找不到事件，可以設定一個錯誤訊息
        $_SESSION['error_message'] = "找不到要編輯的事件。";
    }

    header("location:../../views/pages/calendar.php?userid=".$_GET['userid'].'&id='.$_GET['id']);
