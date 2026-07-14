<?php
session_start();

// 引入 _config.php 來獲取 $db 物件，而不是 DBConnection.php
include_once '../../src/common/_config.php';

// 檢查是否有提供要刪除的 ID (delid)
if (isset($_GET['delid'])) {
    $id_to_delete = $_GET['delid'];

    try {
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
