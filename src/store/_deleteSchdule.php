<?php
session_start();

// 引入 _config.php 來獲取 $db 物件，而不是 DBConnection.php
include_once '../../src/common/_config.php';

// 檢查是否有提供要刪除的 ID (delid)
if (isset($_GET['delid'])) {
    $id_to_delete = $_GET['delid'];

    try {
        // 這筆是休假事件而且自動建過假單的話，假單要跟著撤掉（先撤單再刪事件，
        // 順序反過來的話事件已經不見、resync 撈不到，假單會變成沒有來源的孤兒）
        try {
            require_once __DIR__ . '/../common/leave_calendar_lib.php';
            eg_leave_cal_resync_event($db, (int)$id_to_delete,
                ['operator_id' => (int)($_SESSION['id'] ?? 0), 'deleting' => 1]);
        } catch (Throwable $_e) { /* 假單同步失敗不擋行事曆操作 */ }

        // 使用預備語句來安全地刪除資料，防止 SQL 注入
        $stmt = $db->prepare("DELETE FROM evenement WHERE id = :id");
        $stmt->bindParam(':id', $id_to_delete, PDO::PARAM_INT);
        $stmt->execute();

        // 刪除成功後，重導回行事曆頁面
        // 您可以選擇附加一個成功訊息
        header("Location: ../../views/pages/calendar.php?id=" . $_GET['id'] . "&message=delete_success");
        exit();

    } catch (PDOException $e) {
        // 如果出錯，重導並附帶錯誤訊息
        header("Location: ../../views/pages/calendar.php?id=" . $_GET['id'] . "&message=delete_error");
        exit();
    }
}

// 如果沒有提供 delid，直接重導回行事曆頁面
header("Location: ../../views/pages/calendar.php?id=" . $_GET['id']);
