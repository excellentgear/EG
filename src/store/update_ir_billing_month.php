<?php
session_start();
if (!isset($_SESSION['userName'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

include '../common/DBConnection.php';
$conn = new DBConnection();
$pdo = $conn->getPDO();

$ir_id = $_POST['ir_id'] ?? null;
if (!$ir_id) {
    echo json_encode(['success' => false, 'message' => 'Missing ID']);
    exit;
}

$override = isset($_POST['billing_month_override']) && $_POST['billing_month_override'] !== ''
    ? $_POST['billing_month_override']
    : null;

try {
    $stmt = $pdo->prepare("UPDATE ir_track SET billing_month_override = :bmo WHERE IR_id = :id");
    $stmt->execute([':bmo' => $override, ':id' => $ir_id]);
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
