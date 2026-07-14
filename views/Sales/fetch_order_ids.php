<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

date_default_timezone_set('Asia/Taipei'); // ✅ 設定時區為台灣

header('Content-Type: application/json');

include_once '../../src/common/DBConnection.php';
include '../../src/common/_config.php';

// 建立資料庫連線實例
$db = new DBConnection();
$pdo = $db->getPDO();


if (!$pdo) {
    echo json_encode(['status' => 'error', 'message' => '資料庫連線失敗']);
    exit();
}

// 取得前端資料
$raw = file_get_contents("php://input");
$json = json_decode($raw, true);

if (!isset($json['orderIds']) || !is_array($json['orderIds'])) {
    echo json_encode(['status' => 'error', 'message' => 'Missing or invalid orderIds parameter']);
    exit();
}

// --- NEW: Calculate monthly counts for designers ---
$selectedYear = isset($json['year']) ? intval($json['year']) : date('Y');
$orderCountsByDesignerMonth = [];

$sqlCounts = "SELECT
                user.user_cname,
                DATE_FORMAT(order_track.ateGet, '%c') AS month
              FROM order_track
              LEFT JOIN user ON user.id = order_track.ate
              WHERE YEAR(order_track.ateGet) = :year
                AND order_track.ate IS NOT NULL
                AND order_track.ateGet IS NOT NULL
                AND user.user_cname IS NOT NULL";

$stmtCounts = $pdo->prepare($sqlCounts);
$stmtCounts->execute([':year' => $selectedYear]);
$allCounts = $stmtCounts->fetchAll(PDO::FETCH_ASSOC);

foreach ($allCounts as $c) {
    $designer = $c['user_cname'];
    $month = intval($c['month']);
    if (!isset($orderCountsByDesignerMonth[$designer][$month])) {
        $orderCountsByDesignerMonth[$designer][$month] = 0;
    }
    $orderCountsByDesignerMonth[$designer][$month]++;
}

$orderIds = array_filter($json['orderIds'], 'is_numeric');
$orderIds = array_map('intval', $orderIds);

if (empty($orderIds)) {
    echo json_encode(['status' => 'success', 'data' => [], 'count' => 0]);
    exit();
}

// 動態產生 SQL IN 子句
$placeholders = implode(',', array_fill(0, count($orderIds), '?'));

$sql = "SELECT
            order_track.*,
            CONCAT(DATE_FORMAT(order_track.Order_date, '%y'), 'y/', DATE_FORMAT(order_track.Order_date, '%c/%e')) AS Order_date_formatted,
            CONCAT(DATE_FORMAT(order_track.Delivery_date, '%y'), 'y/', DATE_FORMAT(order_track.Delivery_date, '%c/%e')) AS Delivery_date_T,
            DATE_FORMAT(order_track.ateGet, '%c/%e') AS ateGet_formatted,
            DATE_FORMAT(order_track.pmGet, '%c/%e') AS pmGet_formatted,
            user.user_cname
        FROM order_track
        LEFT JOIN user ON user.id = order_track.ate
        WHERE order_track.Order_id IN ($placeholders)
        ORDER BY order_track.Order_date DESC, order_track.Client_name ASC";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($orderIds);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // --- NEW: Add monthly_count to each order ---
    foreach ($data as &$order) {
        if (!empty($order['ateGet'])) {
            list($m,) = explode('/', $order['ateGet_formatted']);
            $month = intval($m);
            $designer = $order['user_cname'];
            $order['monthly_count'] = $orderCountsByDesignerMonth[$designer][$month] ?? 0;
        } else {
            $order['monthly_count'] = 0;
        }
    }
    unset($order); // Unset reference

    echo json_encode([
        'status' => 'success',
        'data' => $data,
        'timestamp' => date('Y-m-d H:i:s'),
        'count' => count($data)
    ]);
} catch (PDOException $e) {
    echo json_encode([
        'status' => 'error',
        'message' => 'SQL Error: ' . $e->getMessage()
    ]);
}
?>
