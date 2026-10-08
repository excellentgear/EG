<?php
session_start();
// Ensure common files are included. Adjust paths if necessary.
include_once dirname(__DIR__) . '/common/DBConnection.php'; // Adjusted path for robustness
include_once dirname(__DIR__) . '/common/_config.php';    // Adjusted path for robustness
require_once dirname(__DIR__) . '/common/order_track_perm_lib.php';
// 「轉生管日／BOM開立」那一格的狀態一律由共用庫算（含 BOSS 審圖），前端用同一支 otPmCellHtml 畫
require_once dirname(__DIR__) . '/common/order_boss_review_lib.php';

header('Content-Type: application/json');

if (!isset($_SESSION['userName'])) {
    echo json_encode(['success' => false, 'message' => 'User not authenticated.']);
    exit;
}

$db = new DBConnection();
$pdo = $db->getPDO();

if (!$pdo) {
    echo json_encode(['success' => false, 'message' => 'Database connection failed.']);
    exit;
}

$orderId = isset($_POST['Order_id']) ? trim($_POST['Order_id']) : null;
$action = isset($_POST['action']) ? $_POST['action'] : null;

if (empty($orderId) || !is_numeric($orderId)) {
    echo json_encode(['success' => false, 'message' => 'Invalid Order ID.']);
    exit;
}

// 審圖/取消審圖：只有此訂單目前指定的設計人員與管理員可操作（原本無任何權限檢查，任何登入者皆可呼叫）
$uid = (int)($_SESSION['id'] ?? 0);
if (!ot_can_operate_design($pdo, $uid, (int)$orderId, 'ot_batch_draw')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => '您不是此訂單目前指定的設計人員，無法操作審圖狀態。']);
    exit;
}

if ($action === 'set_in_review') {
    try {
        $pdo->prepare("UPDATE order_track SET in_review = CURDATE() WHERE Order_id = ?")
            ->execute([$orderId]);

        // 不再靠 UPDATE 的 rowCount 判斷「有沒有設定成功」——同一天重複按本來就 rowCount=0，
        // 一律重新查目前狀態，順便把下面的 BOSS 審圖判斷接進同一次查詢（唯一一份即時狀態）。
        $stmt_fetch = $pdo->prepare("SELECT in_review, DATE_FORMAT(in_review, '%c/%e') AS in_review_date,
                                             Client_name_ID, ate, boss_review_at, boss_ok_at
                                      FROM order_track WHERE Order_id = ?");
        $stmt_fetch->execute([$orderId]);
        $row = $stmt_fetch->fetch(PDO::FETCH_ASSOC);

        if ($row && !empty($row['in_review_date'])) {
            // 2026-10-08 使用者要求：客戶在「需給 BOSS 審圖」名單內時，按下【審圖】的當下就直接
            // 送出 BOSS 審圖（不必等再按一次【轉生管】才送）——否則提示與「BOSS審圖中」會晚一步
            // 才出現，使用者會以為按了審圖沒反應。只在「這張單還沒送過、也還沒 BOSS OK 過」時才送，
            // 避免覆蓋既有的送審/審核紀錄；轉生管那邊原本的判斷（simple_update_pmGet.php）照常保留，
            // 當成舊資料（審圖早就按過、名單後來才加這家客戶）的退路，兩邊同時存在不衝突。
            $bossMsg = null;
            if (empty($row['boss_review_at']) && empty($row['boss_ok_at'])
                && ot_boss_required($pdo, $row['Client_name_ID'] ?? '', $row['ate'] ?? 0, $row['in_review'])) {
                $pdo->prepare("UPDATE order_track SET boss_review_at = CURDATE(), boss_review_by = ? WHERE Order_id = ?")
                    ->execute([$uid, $orderId]);
                $bossMsg = '本客戶的訂單需給 BOSS 審圖，已記錄今日送 BOSS 審圖；等 BOSS 審核 OK 後才能轉生管。';
            }
            $resp = [
                'success' => true,
                'message' => $bossMsg !== null ? $bossMsg : '審圖中狀態已設定。',
                'in_review_date' => $row['in_review_date'],
                'state' => ot_boss_cell_state($pdo, (int)$orderId),
            ];
            if ($bossMsg !== null) $resp['boss_review'] = true;
            echo json_encode($resp);
        } else {
            echo json_encode(['success' => false, 'message' => '設定審圖中狀態失敗或無變更。']);
        }
    } catch (PDOException $e) {
        error_log("Error setting in_review: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => '資料庫錯誤： ' . $e->getMessage()]);
    }
} elseif ($action === 'cancel_in_review') {
    try {
        // 2026-10-08 使用者要求：取消審圖要把 BOSS 審圖的送審/審核紀錄一起清掉，回到「完全沒按過
        // 審圖」的狀態——否則 boss_review_at／boss_ok_at 留著舊值，下次再按審圖時，會因為這裡已經
        // 有紀錄而直接跳成「BOSS審圖中」（甚至「BOSS OK」），不會重新走一次送審提示。
        $stmt = $pdo->prepare("UPDATE order_track SET in_review = NULL,
                               boss_review_at = NULL, boss_review_by = NULL,
                               boss_ok_at = NULL, boss_ok_by = NULL
                               WHERE Order_id = ?");
        $stmt->execute([$orderId]);
        if ($stmt->rowCount() > 0) {
            echo json_encode(['success' => true, 'message' => '審圖中狀態已取消。', 'state' => ot_boss_cell_state($pdo, (int)$orderId)]);
        } else {
            echo json_encode(['success' => false, 'message' => '取消審圖中狀態失敗或無變更。']);
        }
    } catch (PDOException $e) {
        error_log("Error cancelling in_review: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => '資料庫錯誤： ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => '無效的操作。']);
}
?>
