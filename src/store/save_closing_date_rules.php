<?php
session_start();
if (!isset($_SESSION['userName'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

include '../common/DBConnection.php';
$conn = new DBConnection();
$pdo = $conn->getPDO();

$rules = isset($_POST['rules']) ? $_POST['rules'] : '';
$group = 'SHIPPING_ANALYSIS';
$key = 'CLOSING_DATE_RULES';

if (!$rules) {
    echo json_encode(['success' => false, 'message' => 'No data']);
    exit;
}

// 檢查是否存在，存在則更新，不存在則新增
$sql = "INSERT INTO system_parameters (param_group, param_key, param_value, updated_by) 
        VALUES (:group, :key, :val, :user) 
        ON DUPLICATE KEY UPDATE param_value = :val, updated_by = :user, updated_at = NOW()";

$stmt = $pdo->prepare($sql);
$stmt->execute([
    ':group' => $group,
    ':key' => $key,
    ':val' => $rules,
    ':user' => $_SESSION['user_cname']
]);

echo json_encode(['success' => true]);
?>