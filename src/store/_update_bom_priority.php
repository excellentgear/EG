<?php
session_start();

ini_set('display_errors', 0); // Suppress errors from breaking JSON output in production
error_reporting(E_ALL);

include_once '../../src/common/DBConnection.php';
include_once '../../src/common/_config.php';

header('Content-Type: application/json');
ob_clean(); // Clean any previous output buffer

$response = ['success' => false, 'message' => 'An error occurred.'];

if (!isset($_SESSION['id'])) {
    $response['message'] = 'User not logged in or session expired.';
    echo json_encode($response);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $response['message'] = 'Invalid request method. Only POST is accepted.';
    echo json_encode($response);
    exit;
}

if (!isset($_POST['bom']) || !isset($_POST['new_priority_type'])) { // 修改此處
    $response['message'] = '缺少必要的更新參數 (bom 或 new_priority_type)'; // 修改錯誤訊息以匹配
    echo json_encode($response);
    exit;
}

$bom_to_update = trim($_POST['bom']);
$new_priority_type_from_post = $_POST['new_priority_type']; // 修改此處，接收 new_priority_type

$priority_to_set_in_db = ($new_priority_type_from_post === '' || strtoupper($new_priority_type_from_post) === 'NULL') ? null : $new_priority_type_from_post;
$modified_by_user_id = $_SESSION['id'];

if (empty($bom_to_update)) {
    $response['message'] = 'BOM identifier cannot be empty.';
    echo json_encode($response);
    exit;
}

try {
    $sql = "UPDATE bom SET priority_type = :priority_type, Modified_At = NOW(), Modified_By = :modified_by WHERE bom = :bom_id";
    $stmt = $db->prepare($sql);
    $stmt->bindParam(':priority_type', $priority_to_set_in_db, ($priority_to_set_in_db === null) ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $stmt->bindParam(':modified_by', $modified_by_user_id, PDO::PARAM_INT);
    $stmt->bindParam(':bom_id', $bom_to_update, PDO::PARAM_STR);

    if ($stmt->execute()) {
        $response['success'] = true;
        $response['message'] = 'BOM priority updated successfully.';
        $response['new_priority'] = $priority_to_set_in_db; // Send back the actual value set
    } else {
        $errorInfo = $stmt->errorInfo();
        $response['message'] = 'Database update failed: ' . $errorInfo[2];
        error_log("BOM Priority Update SQL Error: " . $errorInfo[2] . " for BOM: " . $bom_to_update);
    }
} catch (PDOException $e) {
    $response['message'] = 'Database error: ' . $e->getMessage();
    error_log("BOM Priority Update PDOException: " . $e->getMessage() . " for BOM: " . $bom_to_update);
}

echo json_encode($response);
exit;
?>