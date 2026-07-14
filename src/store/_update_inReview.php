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

$orderId = isset($_POST['Order_id']) ? trim($_POST['Order_id']) : null;
$action = isset($_POST['action']) ? $_POST['action'] : null;

if (empty($orderId) || !is_numeric($orderId)) {
    echo json_encode(['success' => false, 'message' => 'Invalid Order ID.']);
    exit;
}

if ($action === 'set_in_review') {
    try {
        $stmt = $pdo->prepare("UPDATE order_track SET in_review = CURDATE() WHERE Order_id = ?");
        $stmt->execute([$orderId]);
        if ($stmt->rowCount() > 0) {
            $stmt_fetch = $pdo->prepare("SELECT DATE_FORMAT(in_review, '%c/%e') AS in_review_date FROM order_track WHERE Order_id = ?");
            $stmt_fetch->execute([$orderId]);
            $result = $stmt_fetch->fetch(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'message' => '審圖中狀態已設定。', 'in_review_date' => $result['in_review_date']]);
        } else {
            // If no rows affected, it might be already set to today. Fetch current date to be sure.
            $stmt_fetch = $pdo->prepare("SELECT DATE_FORMAT(in_review, '%c/%e') AS in_review_date FROM order_track WHERE Order_id = ? AND in_review = CURDATE()");
            $stmt_fetch->execute([$orderId]);
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
        $stmt = $pdo->prepare("UPDATE order_track SET in_review = NULL WHERE Order_id = ?");
        $stmt->execute([$orderId]);
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
