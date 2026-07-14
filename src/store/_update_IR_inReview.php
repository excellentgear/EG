<?php
session_start();
// Ensure common files are included. Adjust paths if necessary.
include_once dirname(__DIR__) . '/common/DBConnection.php'; // Adjusted path for robustness
include_once dirname(__DIR__) . '/common/_config.php';    // Adjusted path for robustness

header('Content-Type: application/json');

if (!isset($_SESSION['userName'])) {
    echo json_encode(['success' => false, 'message' => 'User not authenticated.']);
    exit;
}

$db = new DBConnection();
$pdo = $db->getPDO();

if (!$pdo) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed.']);
    exit;
}

$irId = isset($_POST['IR_id']) ? trim($_POST['IR_id']) : null; // 改為接收 IR_id
$action = isset($_POST['action']) ? $_POST['action'] : null;

if (empty($irId) || !is_numeric($irId)) {
    echo json_encode(['success' => false, 'message' => '無效的退貨 ID。']); // Invalid IR ID
    exit;
}

if ($action === 'set_in_review') {
    try {
        $stmt = $pdo->prepare("UPDATE ir_track SET in_review = CURDATE() WHERE IR_id = ?"); // 更新 ir_track
        $stmt->execute([$irId]);
        if ($stmt->rowCount() > 0) {
            $stmt_fetch = $pdo->prepare("SELECT DATE_FORMAT(in_review, '%c/%e') AS in_review_date FROM ir_track WHERE IR_id = ?"); // 從 ir_track 查詢
            $stmt_fetch->execute([$irId]);
            $result = $stmt_fetch->fetch(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'message' => '審圖中狀態已設定。', 'in_review_date' => $result['in_review_date']]);
        } else {
            // If no rows affected, it might be already set to today. Fetch current date to be sure.
            $stmt_fetch = $pdo->prepare("SELECT DATE_FORMAT(in_review, '%c/%e') AS in_review_date FROM ir_track WHERE IR_id = ? AND in_review = CURDATE()"); // 從 ir_track 查詢
            $stmt_fetch->execute([$irId]);
            $result = $stmt_fetch->fetch(PDO::FETCH_ASSOC);
            if ($result) {
                 echo json_encode(['success' => true, 'message' => '審圖中狀態已是今日。', 'in_review_date' => $result['in_review_date']]);
            } else {
                echo json_encode(['success' => false, 'message' => '設定審圖中狀態失敗或無變更。']);
            }
        }
    } catch (PDOException $e) {
        error_log("Error setting in_review: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => '資料庫錯誤： ' . $e->getMessage()]);
    }
} elseif ($action === 'cancel_in_review') {
    try {
        $stmt = $pdo->prepare("UPDATE ir_track SET in_review = NULL WHERE IR_id = ?"); // 更新 ir_track
        $stmt->execute([$irId]);
        if ($stmt->rowCount() > 0) {
            echo json_encode(['success' => true, 'message' => '審圖中狀態已取消。']);
        } else {
            echo json_encode(['success' => false, 'message' => '取消審圖中狀態失敗或無變更。']);
        }
    } catch (PDOException $e) {
        error_log("Error cancelling in_review: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => '資料庫錯誤： ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => '無效的操作。']);
}
?>
