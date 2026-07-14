<?php
require_once '../common/DBConnection.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$ids = isset($_POST['ids']) ? $_POST['ids'] : [];
$sale_type = isset($_POST['sale_type']) ? $_POST['sale_type'] : null;

if (empty($ids) || !is_array($ids)) {
    echo json_encode(['success' => false, 'message' => 'No items selected']);
    exit;
}

// sale_type can be 'NULL' string from the select option value
if ($sale_type === 'NULL') {
    $sale_type = null;
}

$conn = new DBConnection();
$pdo = $conn->getPDO();

try {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sql = "UPDATE is_list SET sale_type = ? WHERE IS_id IN ($placeholders)";
    
    $params = array_merge([$sale_type], $ids);
    
    $stmt = $pdo->prepare($sql);
    $result = $stmt->execute($params);
    
    echo json_encode(['success' => true, 'count' => $stmt->rowCount()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>