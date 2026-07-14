<?php
session_start();
if (!isset($_SESSION['userName'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

include '../common/DBConnection.php';
$conn = new DBConnection();
$pdo = $conn->getPDO();

$is_id = $_POST['is_id'] ?? null;
if (!$is_id) {
    echo json_encode(['success' => false, 'message' => 'Missing ID']);
    exit;
}

// 允許更新的欄位
$fields = [
    'Order_date', 'IS_number', 'Client_name', 'Product_id',
    'Specification', 'Content', 'Qty', 'Unit_price', 'Order_id',
    'Warehouse', 'Note', 'sale_type', 'billing_month_override'
];

$set_clause = [];
$params = [':id' => $is_id];

foreach ($fields as $field) {
    if (isset($_POST[$field])) {
        $set_clause[] = "$field = :$field";
        $val = $_POST[$field];
        // 處理 sale_type 的 NULL 值
        if ($field === 'sale_type' && ($val === '' || $val === 'NULL')) {
            $val = null;
        }
        if ($field === 'billing_month_override' && $val === '') {
            $val = null;
        }
        $params[":$field"] = $val;
    }
}

if (empty($set_clause)) {
    echo json_encode(['success' => false, 'message' => 'No fields to update']);
    exit;
}

$sql = "UPDATE is_list SET " . implode(', ', $set_clause) . " WHERE IS_id = :id";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>