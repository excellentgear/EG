<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

// Include necessary files
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/_config.php';

// Get pti from request, with validation and a default
$pti = isset($_GET['pti']) && is_numeric($_GET['pti']) ? intval($_GET['pti']) : 12;

// Determine maker_check_name based on pti
$maker_check_name = ($pti == 1) ? '原一' : '超正';

// Construct the view name safely
$vw_pti = 'vw_pti_' . $pti . '_list';

$conn = new DBConnection();
$db = $conn->db; // ✅ 這一行非常重要，才能正常使用 PDO
$response_data = [];

// Get the process type name for the title
$pti_one = $conn->getOne("SELECT process_type FROM process_type WHERE process_type_id = ?", [$pti]);
$response_data['process_type_name'] = $pti_one ? $pti_one['process_type'] : '未知製程';
$response_data['schedules'] = [];

// Get the list of machines for this process type
// Use a try-catch block for robustness in case the view doesn't exist
try {
    $tg_list = $conn->getAll("SELECT DISTINCT machine, machine_id FROM $vw_pti ORDER BY machine_id");
} catch (Exception $e) {
    // If the view doesn't exist, tg_list will be false or an exception is thrown.
    $tg_list = [];
    $response_data['error'] = "無法讀取製程資料視圖: " . $vw_pti;
}


if ($tg_list) {
    foreach ($tg_list as $machine) {
        $machine_id = $machine['machine_id'];
        $machine_data = [
            'machine_name' => $machine['machine'],
            'machine_id' => $machine_id,
            'items' => []
        ];

        // Get max processing sequence for this machine
        $pdo = $conn->getPDO();

        $maxOrderStmt = $pdo->prepare("
            SELECT MAX(processing_sequence) AS max_order
            FROM $vw_pti
            WHERE process_type_id = :pti 
              AND (machine_id = :machine_id OR :machine_id IS NULL) 
              AND maker_id LIKE :maker
        ");

        $maxOrderStmt->execute([
            'pti'        => $pti,
            'machine_id' => $machine_id,
            'maker'      => "%$maker_check_name%"
        ]);

        $maxOrder = $maxOrderStmt->fetchColumn();
        $machine_data['max_order'] = $maxOrder ? (int)$maxOrder : 0;


        // Get all scheduled items for this machine
        $ALL_LIST = $conn->getAll("
            SELECT d_id, bom_ing_fid, Client_Name, bom_ing_id, bom, machine_id, process_no,
                   maker_id, sqty, processing_sequence, ProcessName, processing_state, ps,
                   outsource_date, return_date, Created_By, Delivery_date, PS2, machine
            FROM $vw_pti
            WHERE machine_id = ?
            ORDER BY processing_sequence ASC
        ", [$machine_id]);

        if ($ALL_LIST) {
            $machine_data['items'] = $ALL_LIST;
        }
        $response_data['schedules'][] = $machine_data;
    }
}

// Get user status for permissions
$user_status = $_SESSION['status'] ?? null;
$response_data['user_status'] = $user_status;

// Return the data as JSON
echo json_encode(['success' => true, 'data' => $response_data]);
exit;
