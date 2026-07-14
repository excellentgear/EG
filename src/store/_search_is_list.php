<?php
session_start();
header('Content-Type: application/json');

include '../common/DBConnection.php';
include '../common/_config.php';

// 確保使用者已登入
if (!isset($_SESSION['userName'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$conn = new DBConnection();
$db = $conn->getPDO();

$searchTerm = isset($_GET['term']) ? trim($_GET['term']) : '';
//  判斷是否有要求查詢所有資料
$allData = isset($_GET['all_data']) && $_GET['all_data'] == 1;

if (empty($searchTerm)) {
    echo json_encode([]);
    exit;
}

$likeTerm = '%' . $searchTerm . '%';

// 準備 SQL 查詢
$sql = "
    SELECT
        isl.IS_id,
        isl.Order_date,
        CONCAT(DATE_FORMAT(isl.Order_date, '%y'), 'y/', DATE_FORMAT(isl.Order_date, '%c/%e')) AS Order_date_T,
        isl.IS_number,
        isl.Client_id,
        isl.Client_name,
        isl.Product_id,
        isl.Specification,
        isl.Qty,
        isl.Unit_price,
        isl.Order_id,
        isl.Warehouse,
        isl.Note,
        user.user_cname
    FROM is_list isl
    LEFT JOIN user ON user.id = isl.Created_By
    WHERE
        isl.IS_number LIKE :term OR
        isl.Client_name LIKE :term OR
        isl.Product_id LIKE :term OR
        isl.Specification LIKE :term OR
        isl.Warehouse LIKE :term OR
        isl.Note LIKE :term
";

// 如果沒有要求查詢全部資料，則加上年份限制
if (!$allData) {
    $sql .= " AND isl.Created_At >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)";
}
$sql .= "
    ORDER BY isl.Order_date DESC, isl.Client_name ASC
";

$stmt = $db->prepare($sql);
$stmt->execute([':term' => $likeTerm]);
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($results);
?>