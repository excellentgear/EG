<?php
header('Content-Type: application/json; charset=utf-8');

// 使用 $_SERVER['DOCUMENT_ROOT'] 來確保路徑的準確性
$document_root = $_SERVER['DOCUMENT_ROOT'];

session_start();
include_once $document_root . '/EGsystem/src/common/_config.php';
include_once $document_root . '/EGsystem/src/common/DBConnection.php';
// --- 權限檢查 (未來實作) ---
// 這裡應檢查使用者是否擁有 'hr_setting' 權限
/*
if (!isset($_SESSION['user_permissions']['hr_setting']) || !$_SESSION['user_permissions']['hr_setting']) {
    echo json_encode(['status' => 'error', 'message' => '您沒有權限執行此操作。']);
    exit;
}
*/

// 舊的 DBConnection 不再使用
// $conn = new DBConnection();

$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : '';

switch ($action) {
    case 'get_leave_types':
        getLeaveTypes($db); // 改為傳入 $db
        break;
    case 'add_leave_type':
        addLeaveType($db); // 改為只傳入 $db
        break;
    case 'get_leave_type_details':
        getLeaveTypeDetails($db); // 改為只傳入 $db
        break;
    case 'update_leave_type':
        updateLeaveType($db); // 改為只傳入 $db
        break;
    case 'delete_leave_type':
        deleteLeaveType($db); // 改為只傳入 $db
        break;
    default:
        echo json_encode(['status' => 'error', 'message' => '無效的操作。']);
        break;
}

/**
 * 獲取所有假別設定
 */
function getLeaveTypes($db) { // 改為接收 $db
    try {
        // 使用 PDO 進行查詢
        $stmt = $db->query("SELECT id, leave_name, need_approval, agent, max_approval_level FROM leave_type ORDER BY id");
        $leaveTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'success', 'data' => $leaveTypes]);
    } catch (PDOException $e) { // 捕捉 PDOException
        echo json_encode(['status' => 'error', 'message' => '讀取假別資料失敗: ' . $e->getMessage()]);
    }
    exit; // 確保執行完畢後終止
}

/**
 * 新增假別
 */
function addLeaveType($db) { // 改為只接收 $db
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['status' => 'error', 'message' => '僅接受 POST 請求。']);
        exit;
    }

    $name = isset($_POST['name']) ? trim($_POST['name']) : '';
    $need_approval = isset($_POST['need_manager_sign']) ? 1 : 0; // 修正：對應前端的 name="need_manager_sign"
    $agent = isset($_POST['need_agent_sign']) ? 1 : 0; // 修正：對應前端的 name="need_agent_sign"
    $max_level = isset($_POST['max_level']) ? intval($_POST['max_level']) : 1;

    if (empty($name)) {
        echo json_encode(['status' => 'error', 'message' => '假別名稱不可為空。']);
        exit;
    }

    try {
        // 檢查假別名稱是否已存在
        $checkStmt = $db->prepare("SELECT COUNT(*) FROM leave_type WHERE leave_name = :name");
        $checkStmt->bindParam(':name', $name, PDO::PARAM_STR);
        $checkStmt->execute();
        if ($checkStmt->fetchColumn() > 0) {
            echo json_encode(['status' => 'error', 'message' => '假別名稱重複，請修改。']);
            exit;
        }

        // 如果名稱不存在，則執行新增
        $stmt = $db->prepare(
            "INSERT INTO leave_type (leave_name, need_approval, agent, max_approval_level) VALUES (:name, :need_approval, :agent, :max_level)"
        );
        $stmt->bindParam(':name', $name, PDO::PARAM_STR);
        $stmt->bindParam(':need_approval', $need_approval, PDO::PARAM_INT);
        $stmt->bindParam(':agent', $agent, PDO::PARAM_INT);
        $stmt->bindParam(':max_level', $max_level, PDO::PARAM_INT);
        $stmt->execute();
        echo json_encode(['status' => 'success', 'message' => '假別新增成功。']);
        exit;
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => '資料庫錯誤: ' . $e->getMessage()]);
        exit;
    }
}

/**
 * 獲取單一假別的詳細資料
 */
function getLeaveTypeDetails($db) { // 改為只接收 $db
    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    if ($id <= 0) {
        echo json_encode(['status' => 'error', 'message' => '無效的 ID。']);
        exit;
    }

    try {
        $stmt = $db->prepare("SELECT id, leave_name, need_approval, agent, max_approval_level FROM leave_type WHERE id = :id");
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $leaveType = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($leaveType) {
            // 成功找到資料，回傳 success 和 data
            echo json_encode(['status' => 'success', 'data' => $leaveType]);
        } else {
            // 找不到資料，回傳 error
            echo json_encode(['status' => 'error', 'message' => '找不到指定的假別。']);
        }
    } catch (PDOException $e) {
        // 資料庫查詢出錯，回傳 error
        echo json_encode(['status' => 'error', 'message' => '讀取資料失敗: ' . $e->getMessage()]);
    }
    exit; // 確保無論 try/catch 結果如何，最後都會終止腳本
}

/**
 * 更新假別
 */
function updateLeaveType($db) { // 改為只接收 $db
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['status' => 'error', 'message' => '僅接受 POST 請求。']);
        exit;
    }

    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    $name = isset($_POST['name']) ? trim($_POST['name']) : '';
    $need_approval = isset($_POST['need_manager_sign']) ? 1 : 0; // 修正：對應前端的 name="need_manager_sign"
    $agent = isset($_POST['need_agent_sign']) ? 1 : 0; // 修正：對應前端的 name="need_agent_sign"
    $max_level = isset($_POST['max_level']) ? intval($_POST['max_level']) : 1;

    if ($id <= 0) {
        echo json_encode(['status' => 'error', 'message' => '無效的 ID。']);
        exit;
    }
    if (empty($name)) {
        echo json_encode(['status' => 'error', 'message' => '假別名稱不可為空。']);
        exit;
    }

    try {
        // 檢查名稱是否與其他假別重複
        $checkStmt = $db->prepare("SELECT COUNT(*) FROM leave_type WHERE leave_name = :name AND id != :id");
        $checkStmt->bindParam(':name', $name, PDO::PARAM_STR);
        $checkStmt->bindParam(':id', $id, PDO::PARAM_INT);
        $checkStmt->execute();
        if ($checkStmt->fetchColumn() > 0) {
            echo json_encode(['status' => 'error', 'message' => '假別名稱重複，請修改。']);
            exit;
        }

        // 執行更新
        $stmt = $db->prepare(
            "UPDATE leave_type SET leave_name = :name, need_approval = :need_approval, agent = :agent, max_approval_level = :max_level WHERE id = :id"
        );
        $stmt->bindParam(':name', $name, PDO::PARAM_STR);
        $stmt->bindParam(':need_approval', $need_approval, PDO::PARAM_INT);
        $stmt->bindParam(':agent', $agent, PDO::PARAM_INT);
        $stmt->bindParam(':max_level', $max_level, PDO::PARAM_INT);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        echo json_encode(['status' => 'success', 'message' => '假別更新成功。']);
        exit;
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => '資料庫錯誤: ' . $e->getMessage()]);
        exit;
    }
}

/**
 * 刪除假別
 */
function deleteLeaveType($db) { // 改為只接收 $db
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['status' => 'error', 'message' => '僅接受 POST 請求。']);
        exit;
    }

    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;

    if ($id <= 0) {
        echo json_encode(['status' => 'error', 'message' => '無效的 ID。']);
        exit;
    }

    try {
        // 執行刪除
        $stmt = $db->prepare("DELETE FROM leave_type WHERE id = :id");
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        if ($stmt->rowCount() > 0) {
            echo json_encode(['status' => 'success', 'message' => '假別刪除成功。']);
            exit;
        } else {
            echo json_encode(['status' => 'error', 'message' => '找不到要刪除的假別或刪除失敗。']);
            exit;
        }
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => '資料庫錯誤: ' . $e->getMessage()]);
        exit;
    }
}
?>