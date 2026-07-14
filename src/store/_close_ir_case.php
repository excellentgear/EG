<?php
session_start();
header('Content-Type: application/json');

include '../common/DBConnection.php';
include '../common/_config.php';

$response = ['success' => false, 'message' => ''];

if (!isset($_SESSION['id'])) {
    $response['message'] = '使用者未登入';
    echo json_encode($response);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['IR_id'])) {
    $response['message'] = '無效的請求';
    echo json_encode($response);
    exit;
}

$irId = $_POST['IR_id'];
$closeNote = $_POST['closeNote'] ?? '';
$userId = $_SESSION['id'];

try {
    $conn = new DBConnection();
    $pdo = $conn->getPDO();

    $stmt = $pdo->prepare("UPDATE ir_track SET IR_status = 9, closeNote = ?, Modified_By = ?, Modified_At = NOW() WHERE IR_id = ?");
    $stmt->execute([$closeNote, $userId, $irId]);

    if ($stmt->rowCount() > 0) {
        $response['success'] = true;
        $response['message'] = '退貨單已成功結案。';
    } else {
        $response['message'] = '結案失敗，找不到對應的退貨單或資料無變更。';
    }
} catch (PDOException $e) {
    $response['message'] = '資料庫錯誤：' . $e->getMessage();
}

echo json_encode($response);
?>