<?php
session_start();

// Enable error reporting for debugging, disable for production
ini_set('display_errors', 1); // Keep this for dev, set to 0 for production
error_reporting(E_ALL);

include_once '../../src/common/DBConnection.php'; // This should make $db (PDO object) available
include_once '../../src/common/_config.php';    // Or this one, depending on your setup

header('Content-Type: application/json');

if (!isset($_SESSION['id'])) {
    echo json_encode(['success' => false, 'message' => '使用者未登入或 Session 過期。']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => '僅接受 POST 請求。']);
    exit;
}

if ((!isset($_POST['bom_ing_fid']) && !isset($_POST['bom_ing_id'])) || !isset($_POST['new_status'])) {
    echo json_encode(['success' => false, 'message' => '缺少必要參數 (bom_ing_fid/bom_ing_id 或 new_status)。']);
    exit;
}

// 優先使用主鍵 bom_ing_fid 精準定位；bom_ing_id 為組合字串、非唯一索引，僅作舊頁面相容用的退回查法
$bom_ing_fid = isset($_POST['bom_ing_fid']) ? trim($_POST['bom_ing_fid']) : '';
$bom_ing_id  = isset($_POST['bom_ing_id']) ? trim($_POST['bom_ing_id']) : '';
$new_status  = trim($_POST['new_status']);
$user_id = $_SESSION['id']; // Get user ID from session

if ((empty($bom_ing_fid) && empty($bom_ing_id)) || empty($new_status)) {
    echo json_encode(['success' => false, 'message' => '參數不得為空。']);
    exit;
}

if ($new_status !== 'Q') { // 前端固定送 Q，後端依 is_exclude_qc 決定實際狀態
    echo json_encode(['success' => false, 'message' => '不支援的狀態更新。']);
    exit;
}

try {
    // Ensure $db is the PDO connection object
    if (!isset($db) || !($db instanceof PDO)) {
        $conn = new DBConnection();
        $db = $conn->getPDO();
        if (!isset($db) || !($db instanceof PDO)) {
             throw new Exception("資料庫連線物件未正確初始化。");
        }
    }

    // 查詢目標製程：優先用主鍵 bom_ing_fid 精準鎖定；否則退回用 bom_ing_id 查出對應的 bom_ing_fid
    if ($bom_ing_fid !== '') {
        $chk = $db->prepare("SELECT bi.bom_ing_fid, bi.sqty, bi.maker_id_no, pn.is_exclude_qc FROM bom_ing bi LEFT JOIN process_no pn ON bi.process_no = pn.ProcessNo WHERE bi.bom_ing_fid = ? LIMIT 1");
        $chk->execute([$bom_ing_fid]);
    } else {
        $chk = $db->prepare("SELECT bi.bom_ing_fid, bi.sqty, bi.maker_id_no, pn.is_exclude_qc FROM bom_ing bi LEFT JOIN process_no pn ON bi.process_no = pn.ProcessNo WHERE bi.bom_ing_id = ? LIMIT 1");
        $chk->execute([$bom_ing_id]);
    }
    $chk_row = $chk->fetch(PDO::FETCH_ASSOC);

    if (!$chk_row) {
        echo json_encode(['success' => false, 'message' => '找不到對應的製程記錄。']);
        exit;
    }

    // 統一改用查到的主鍵做更新，避免 bom_ing_id 重複時誤改到其他批次
    $target_fid = (int)$chk_row['bom_ing_fid'];

    // is_exclude_qc=1 → 直接跳到 P（免驗），否則正常 Q
    $actual_status = !empty($chk_row['is_exclude_qc']) ? 'P' : 'Q';

    $sql = "UPDATE bom_ing
            SET
                processing_state = :new_status,
                return_date = NOW(),
                Modified_By = :user_id,
                Modified_At = NOW()
            WHERE bom_ing_fid = :target_fid";

    $stmt = $db->prepare($sql);
    $stmt->bindParam(':new_status', $actual_status, PDO::PARAM_STR);
    $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $stmt->bindParam(':target_fid', $target_fid, PDO::PARAM_INT);

    $db->beginTransaction();
    if ($stmt->execute()) {
        if ($stmt->rowCount() > 0) {
            // 稽核留痕：記錄本次回廠事件，比照拆批/合併機制寫入 bom_ing_event
            $ins_ev = $db->prepare("INSERT INTO bom_ing_event
                (bom_ing_fid, related_bom_ing_fid, event_type, affected_qty, target_maker_id, event_note, Created_By)
                VALUES (:fid, NULL, 'return', :qty, :maker, :note, :uid)");
            $ins_ev->bindParam(':fid', $target_fid, PDO::PARAM_INT);
            $ins_ev->bindParam(':qty', $chk_row['sqty'], PDO::PARAM_INT);
            $ins_ev->bindParam(':maker', $chk_row['maker_id_no'], PDO::PARAM_STR);
            $note = '生管標記回廠，狀態 → ' . $actual_status;
            $ins_ev->bindParam(':note', $note, PDO::PARAM_STR);
            $ins_ev->bindParam(':uid', $user_id, PDO::PARAM_STR);
            $ins_ev->execute();

            $db->commit();
            echo json_encode(['success' => true, 'message' => '狀態更新成功。', 'new_status' => $actual_status, 'bom_ing_fid' => $target_fid]);
        } else {
            $db->rollBack();
            echo json_encode(['success' => false, 'message' => '未找到對應的記錄或狀態未改變。']);
        }
    } else {
        $db->rollBack();
        echo json_encode(['success' => false, 'message' => '資料庫更新失敗。']);
    }
} catch (Exception $e) {
    if ($db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    echo json_encode(['success' => false, 'message' => '處理請求時發生錯誤：' . $e->getMessage()]);
}
?>