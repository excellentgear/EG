<?php
session_start();
header('Content-Type: application/json');

// 引入設定與資料庫連線
include_once dirname(__DIR__) . '/common/DBConnection.php';
include_once dirname(__DIR__) . '/common/_config.php';

// 檢查使用者是否登入
if (!isset($_SESSION['userName'])) {
    echo json_encode(['error' => 'User not authenticated']);
    exit;
}

// 檢查是否提供了退貨 ID
if (!isset($_GET['ir_id']) || empty($_GET['ir_id'])) {
    echo json_encode(['error' => 'IR ID not provided']);
    exit;
}

$irId = $_GET['ir_id'];

try {
    $db = new DBConnection();
    $pdo = $db->getPDO();

    // 準備 SQL 查詢
    $stmt = $pdo->prepare("
        SELECT 
            IR_id,
            IR_no,
            DATE_FORMAT(IR_date, '%Y-%m-%d') AS IR_date,
            Client_Name,
            C_IR,
            d_id,
            Processing_items,
            Qty,
            IR_ps,
            QC_Assignee
        FROM ir_track 
        WHERE IR_id = :ir_id
    ");
    $stmt->bindParam(':ir_id', $irId, PDO::PARAM_INT);
    $stmt->execute();

    $irDetail = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($irDetail) {
        echo json_encode($irDetail);
    } else {
        echo json_encode(['error' => 'IR detail not found']);
    }
} catch (PDOException $e) {
    error_log("Error fetching IR detail: " . $e->getMessage());
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>