<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

include_once dirname(__DIR__) . '/common/DBConnection.php';
include_once dirname(__DIR__) . '/common/_config.php';

if (!isset($_SESSION['userName'])) {
    echo json_encode(['success' => false, 'message' => 'User not authenticated.']);
    exit;
}

$conn = new DBConnection();
$pdo = $conn->getPDO();

if (!$pdo) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed.']);
    exit;
}

try {
    $sql = "SELECT
                ir_track.*,
                CONCAT(DATE_FORMAT(ir_track.ir_date, '%y'), 'y/', DATE_FORMAT(ir_track.ir_date, '%c/%e')) AS IR_date_formatted,
                DATE_FORMAT(ir_track.ateGet, '%c/%e') AS ateGet_formatted,
                DATE_FORMAT(ir_track.pmGet, '%c/%e') AS pmGet_formatted,
                DATE_FORMAT(ir_track.Created_At, '%c/%e') AS Created_At_formatted,
                DATE_FORMAT(ir_track.in_review, '%c/%e') AS in_review_date_formatted,
                user.user_cname
            FROM ir_track
            LEFT JOIN user ON user.id = ir_track.QC_Assignee
            WHERE ir_track.IR_status = 9
            ORDER BY COALESCE(ir_track.Modified_At, ir_track.Created_At) DESC";

    $stmt = $pdo->query($sql);
    $closed_cases = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $closed_cases]);
} catch (PDOException $e) {
    error_log("Error fetching closed IR cases: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => '資料庫查詢失敗。']);
}
?>