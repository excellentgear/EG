<?php
// 啟用錯誤報告
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 開始會話
session_start();

// 包含必要文件
include '../common/DBConnection.php';
include '../common/_config.php';
require_once '../common/order_track_perm_lib.php';
// 指定客戶的訂單「轉生管前要先給 BOSS 審圖」（2026-09-18 使用者要求）唯一實作
require_once '../common/order_boss_review_lib.php';

// 輸出結果
header('Content-Type: application/json');

try {
    // 檢查用戶是否已登錄
    if (!isset($_SESSION['userName'])) {
        echo json_encode(['success' => false, 'message' => '未授權的訪問']);
        exit;
    }

    // 獲取數據庫連接
    $conn = new DBConnection();
    $pdo = $conn->getPDO(); // Get PDO instance for prepared statements

    // 確保請求包含訂單ID
    if (!isset($_POST['Order_id']) || empty($_POST['Order_id'])) {
        echo json_encode(['success' => false, 'message' => '缺少訂單ID參數']);
        exit;
    }

    // 安全地轉換訂單ID為整數
    $order_id = intval($_POST['Order_id']);

    // 檢查ID是否為有效數字
    if ($order_id <= 0) {
        echo json_encode(['success' => false, 'message' => '無效的訂單ID']);
        exit;
    }

    // 轉生管/取消轉生管：只有此訂單目前指定的設計人員與管理員可操作（原本無任何權限檢查，任何登入者皆可呼叫）
    $uid = (int)($_SESSION['id'] ?? 0);
    if (!ot_can_operate_design($pdo, $uid, $order_id, 'ot_to_pm')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => '您不是此訂單目前指定的設計人員，無法操作轉生管狀態。']);
        exit;
    }

    // 檢查是否為取消操作
    $action = isset($_POST['action']) ? $_POST['action'] : 'update';

    // ══════════════════════════════════════════════════════════════════════
    // BOSS 審圖（2026-09-18 使用者要求）
    //  ・客戶在「需給 BOSS 審圖」名單內、而且這張訂單不是「存檔自動轉生管」的對象時，
    //    按【轉生管】不直接蓋轉生管日，改成記下「今天送 BOSS 審圖」。
    //  ・BOSS 審核 OK 之後（boss_ok_at 有值）就完全回到原本的流程，【轉生管】照常。
    //  ・名單沒設定＝ot_boss_required() 一律 false，這段整個不會進來，行為與改動前相同。
    //  權限沿用轉生管（ot_to_pm ＋ 只能操作自己被指定的訂單），上方已守過門。
    // ══════════════════════════════════════════════════════════════════════
    $bossRow = null;
    try {
        $stB = $pdo->prepare("SELECT Client_name_ID, ate, boss_ok_at FROM order_track WHERE Order_id = ?");
        $stB->execute([$order_id]);
        $bossRow = $stB->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Exception $eB) { $bossRow = null; }
    $bossNeed = $bossRow ? ot_boss_required($pdo, $bossRow['Client_name_ID'] ?? '', $bossRow['ate'] ?? 0) : false;

    if ($action === 'boss_ok') {
        // BOSS 審核完成：系統認定為今天
        $pdo->prepare("UPDATE order_track SET boss_ok_at = CURDATE(), boss_ok_by = ? WHERE Order_id = ?")
            ->execute([$uid, $order_id]);
        echo json_encode(['success' => true, 'message' => 'BOSS 審核完成已記錄',
                          'state' => ot_boss_cell_state($pdo, $order_id)]);
        exit;
    }
    if ($action === 'boss_cancel') {
        // 按錯了：把這張訂單的 BOSS 審圖狀態整個清掉（送審日與審核完成日一起），
        // 回到「還沒送 BOSS 審圖」的狀態。刻意兩個欄位一起清＝這顆 X 才是真正可回復的，
        // 否則 BOSS審核OK 一旦按錯就沒有任何辦法退回（比照審圖／轉生管的 X 一律可還原）。
        $pdo->prepare("UPDATE order_track SET boss_review_at = NULL, boss_review_by = NULL,
                              boss_ok_at = NULL, boss_ok_by = NULL WHERE Order_id = ?")
            ->execute([$order_id]);
        echo json_encode(['success' => true, 'message' => '已清除本訂單的 BOSS 審圖紀錄',
                          'state' => ot_boss_cell_state($pdo, $order_id)]);
        exit;
    }
    if ($action !== 'cancel' && $bossNeed && empty($bossRow['boss_ok_at'])) {
        // 還沒經過 BOSS 審核 → 這次按下轉生管＝送 BOSS 審圖（認定當天），不蓋轉生管日
        $pdo->prepare("UPDATE order_track SET boss_review_at = CURDATE(), boss_review_by = ? WHERE Order_id = ?")
            ->execute([$uid, $order_id]);
        echo json_encode(['success' => true, 'boss_review' => true,
                          'message' => '本客戶的訂單需給 BOSS 審圖，已記錄今日送 BOSS 審圖；等 BOSS 審核 OK 後才能轉生管。',
                          'state' => ot_boss_cell_state($pdo, $order_id)]);
        exit;
    }

    if ($action === 'cancel') {
        // 清除轉生管日期
        // pmGet_auto 一併歸零：人工取消後，這筆就不再算「系統自動蓋的」（見 order_auto_pmget_lib.php）
        $stmt = $pdo->prepare("UPDATE order_track SET pmGet = NULL, pmGet_auto = 0 WHERE Order_id = ?");
        $result = $stmt->execute([$order_id]);
        
        if ($result && $stmt->rowCount() > 0) {
            // Fetch the current in_review_date to return it formatted
            $stmt_fetch_ir = $pdo->prepare("SELECT DATE_FORMAT(in_review, '%c/%e') AS in_review_date FROM order_track WHERE Order_id = ?");
            $stmt_fetch_ir->execute([$order_id]);
            $ir_date_result = $stmt_fetch_ir->fetch(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'message' => '轉生管標記已成功取消',
                'in_review_date' => $ir_date_result ? $ir_date_result['in_review_date'] : null,
                'state' => ot_boss_cell_state($pdo, $order_id)
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => '取消轉生管失敗或無變更。']);
        }
    } else {
        // 設置當前日期為轉生管日期
        // $currentDate = date('Y-m-d H:i:s'); // Using CURDATE() in SQL is better
        // pmGet_auto=0 標記「這是人工按鈕蓋的」，之後改指派設計時不會被自動規則清掉
        $stmt = $pdo->prepare("UPDATE order_track SET pmGet = CURDATE(), pmGet_auto = 0 WHERE Order_id = ?");
        $result = $stmt->execute([$order_id]);
        
        if ($result) {
            // Fetch the newly set date to return it formatted
            $stmt_fetch = $pdo->prepare("SELECT DATE_FORMAT(pmGet, '%c/%e') AS pmGet_date FROM order_track WHERE Order_id = ?");
            $stmt_fetch->execute([$order_id]);
            $date_result = $stmt_fetch->fetch(PDO::FETCH_ASSOC);

            if ($date_result) {
                echo json_encode([
                    'success' => true, 
                    'message' => '轉生管日期已成功更新', 
                    'pmGet_date' => $date_result['pmGet_date'],
                    'state' => ot_boss_cell_state($pdo, $order_id)
                ]);
            } else {
                // Should not happen if update was successful, but as a fallback
                echo json_encode(['success' => true, 'message' => '轉生管日期已成功更新，但無法獲取日期。',
                                  'state' => ot_boss_cell_state($pdo, $order_id)]);
            }
        } else {
            echo json_encode(['success' => false, 'message' => '更新失敗']);
        }
    }
    
} catch (Exception $e) {
    // 記錄錯誤但不向客戶端暴露詳細資訊
    error_log('Error in simple_update_pmGet.php: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => '處理請求時發生錯誤: ' . $e->getMessage()]);
    exit;
}
?> 