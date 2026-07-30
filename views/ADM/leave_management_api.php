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
    // ── 請假系統設定（2026-07-29 新增，供請假系統使用）──
    case 'get_leave_settings':
        getLeaveSettings($db);
        break;
    case 'save_leave_settings':
        saveLeaveSettings($db);
        break;
    // ── 假別顯示順序（2026-07-30 新增）──
    case 'move_leave_type':
        moveLeaveType($db);
        break;
    default:
        echo json_encode(['status' => 'error', 'message' => '無效的操作。']);
        break;
}

/**
 * 假別的請假系統擴充欄位（2026-07-29 新增）：粒度與證明文件設定。
 * 舊版表單沒有這些欄位時採安全預設（時假、不需證明、可補件），避免既有操作行為改變。
 */
function leaveTypeExtraFields(): array {
    $unit = isset($_POST['unit_type']) ? trim((string)$_POST['unit_type']) : 'hour';
    if (!in_array($unit, ['hour', 'halfday', 'day'], true)) $unit = 'hour';
    return [
        'unit_type'          => $unit,
        'require_attachment' => isset($_POST['require_attachment']) ? 1 : 0,
        'attach_min_days'    => isset($_POST['attach_min_days']) ? max(0, (float)$_POST['attach_min_days']) : 0,
        // 沒送這個欄位時預設允許補件（比較寬鬆，不會卡住使用者送單）
        'allow_attach_later' => array_key_exists('allow_attach_later', $_POST) ? (empty($_POST['allow_attach_later']) ? 0 : 1) : 1,
    ];
}

/**
 * 讀取請假系統設定（system_settings 的 leave_* 系列）＋最終裁決者候選人清單。
 */
function getLeaveSettings($db) {
    if (!isset($_SESSION['id'])) { echo json_encode(['status' => 'error', 'message' => '尚未登入。']); exit; }
    try {
        $rows = $db->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE 'leave\\_%'")
                   ->fetchAll(PDO::FETCH_KEY_PAIR);
        $def = [
            'leave_attach_base' => '', 'leave_final_decider_id' => '',
            'leave_backdate_limit_days' => '7', 'leave_hours_per_day' => '8', 'leave_halfday_hours' => '4',
        ];
        foreach ($def as $k => $v) if (!array_key_exists($k, $rows)) $rows[$k] = $v;

        // 最終裁決者候選：在職人員，帶主部門與主職稱供前端「部門篩選＋姓名關鍵字」使用
        $users = $db->query(
            "SELECT u.id, u.user_cname,
                    m.department_id, d.name AS department_name, p.name AS position_name
             FROM user u
             LEFT JOIN user_department_position_map m ON m.user_id = u.id AND m.is_main = 1
             LEFT JOIN department d ON d.id = m.department_id
             LEFT JOIN position p ON p.id = m.position_id
             WHERE u.state = 1
             ORDER BY (d.sort_order IS NULL), d.sort_order, p.sort_order, u.user_cname")->fetchAll(PDO::FETCH_ASSOC);
        $depts = $db->query(
            "SELECT id, name FROM department ORDER BY (sort_order IS NULL), sort_order, name")->fetchAll(PDO::FETCH_ASSOC);

        // 已設定的裁決者若已離職/查無此人，另外回傳供前端提示（避免篩選後靜默遺失設定）
        $currentName = null;
        if (!empty($rows['leave_final_decider_id'])) {
            $st = $db->prepare("SELECT user_cname FROM user WHERE id = ? LIMIT 1");
            $st->execute([(int)$rows['leave_final_decider_id']]);
            $currentName = $st->fetchColumn() ?: null;
        }
        echo json_encode(['status' => 'success', 'data' => $rows, 'users' => $users,
                          'departments' => $depts, 'current_decider_name' => $currentName]);
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => '讀取設定失敗: ' . $e->getMessage()]);
    }
    exit;
}

/**
 * 儲存請假系統設定。附件根目錄只存「根目錄」，完整路徑一律於讀取當下即時組（鐵律5）。
 */
function saveLeaveSettings($db) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['status' => 'error', 'message' => '僅接受 POST 請求。']); exit; }
    if (!isset($_SESSION['id'])) { echo json_encode(['status' => 'error', 'message' => '尚未登入。']); exit; }
    $uid = (int)$_SESSION['id'];
    $allow = ['leave_attach_base', 'leave_final_decider_id', 'leave_backdate_limit_days',
              'leave_hours_per_day', 'leave_halfday_hours'];
    try {
        $nameSt = $db->prepare("SELECT user_cname FROM user WHERE id = ?");
        $nameSt->execute([$uid]);
        $by = (string)$nameSt->fetchColumn();
        $st = $db->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_by_id, updated_by, updated_at)
                            VALUES (?,?,?,?,NOW())
                            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value),
                                                    updated_by_id = VALUES(updated_by_id),
                                                    updated_by = VALUES(updated_by), updated_at = NOW()");
        foreach ($allow as $k) {
            if (!array_key_exists($k, $_POST)) continue;
            $v = trim((string)$_POST[$k]);
            if ($k === 'leave_backdate_limit_days') $v = (string)max(0, (int)$v);
            if ($k === 'leave_hours_per_day')       $v = (string)max(1, (float)$v);
            if ($k === 'leave_halfday_hours')       $v = (string)max(0.5, (float)$v);
            $st->execute([$k, $v, $uid, $by]);
        }
        echo json_encode(['status' => 'success', 'message' => '請假系統設定已儲存。']);
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => '儲存失敗: ' . $e->getMessage()]);
    }
    exit;
}

/**
 * 假別顯示順序上移／下移（與相鄰那筆交換 sort_order）。
 * 順序影響請假申請頁的假別下拉，故一律以 sort_order 為準（同值再以 id 排）。
 */
function moveLeaveType($db) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['status' => 'error', 'message' => '僅接受 POST 請求。']); exit; }
    if (!isset($_SESSION['id'])) { echo json_encode(['status' => 'error', 'message' => '尚未登入。']); exit; }
    $id  = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    $dir = ($_POST['dir'] ?? '') === 'up' ? 'up' : 'down';
    if ($id <= 0) { echo json_encode(['status' => 'error', 'message' => '無效的 ID。']); exit; }

    try {
        // 依目前顯示順序取出全部，用陣列位置交換後重寫 sort_order，
        // 這樣即使原本有同值或斷號也能穩定重排（不用擔心交換兩筆相同 sort_order 時無效）
        $rows = $db->query("SELECT id FROM leave_type ORDER BY sort_order, id")->fetchAll(PDO::FETCH_COLUMN);
        $ids = array_map('intval', $rows);
        $pos = array_search($id, $ids, true);
        if ($pos === false) { echo json_encode(['status' => 'error', 'message' => '找不到此假別。']); exit; }
        $swap = $dir === 'up' ? $pos - 1 : $pos + 1;
        if ($swap < 0 || $swap >= count($ids)) {
            echo json_encode(['status' => 'success', 'message' => '已在' . ($dir === 'up' ? '最前' : '最後') . '，無需移動', 'moved' => false]);
            exit;
        }
        $tmp = $ids[$pos]; $ids[$pos] = $ids[$swap]; $ids[$swap] = $tmp;

        $db->beginTransaction();
        $st = $db->prepare("UPDATE leave_type SET sort_order = ? WHERE id = ?");
        foreach ($ids as $i => $lid) $st->execute([($i + 1) * 10, $lid]);
        $db->commit();
        echo json_encode(['status' => 'success', 'message' => '順序已更新', 'moved' => true]);
    } catch (PDOException $e) {
        if ($db->inTransaction()) $db->rollBack();
        echo json_encode(['status' => 'error', 'message' => '順序更新失敗: ' . $e->getMessage()]);
    }
    exit;
}

/**
 * 獲取所有假別設定
 */
function getLeaveTypes($db) { // 改為接收 $db
    try {
        // 使用 PDO 進行查詢
        $stmt = $db->query("SELECT id, leave_name, need_approval, agent, max_approval_level,
                  unit_type, require_attachment, attach_min_days, allow_attach_later, sort_order
           FROM leave_type ORDER BY sort_order, id");
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
        $ext = leaveTypeExtraFields();
        $stmt = $db->prepare(
            "INSERT INTO leave_type (leave_name, need_approval, agent, max_approval_level,
                                     unit_type, require_attachment, attach_min_days, allow_attach_later)
             VALUES (:name, :need_approval, :agent, :max_level, :unit_type, :req_att, :att_min, :att_later)"
        );
        $stmt->bindParam(':name', $name, PDO::PARAM_STR);
        $stmt->bindParam(':need_approval', $need_approval, PDO::PARAM_INT);
        $stmt->bindParam(':agent', $agent, PDO::PARAM_INT);
        $stmt->bindParam(':max_level', $max_level, PDO::PARAM_INT);
        $stmt->bindValue(':unit_type', $ext['unit_type'], PDO::PARAM_STR);
        $stmt->bindValue(':req_att',   $ext['require_attachment'], PDO::PARAM_INT);
        $stmt->bindValue(':att_min',   $ext['attach_min_days']);
        $stmt->bindValue(':att_later', $ext['allow_attach_later'], PDO::PARAM_INT);
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
        $stmt = $db->prepare("SELECT id, leave_name, need_approval, agent, max_approval_level,
                  unit_type, require_attachment, attach_min_days, allow_attach_later, sort_order
           FROM leave_type WHERE id = :id");
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
        $ext = leaveTypeExtraFields();
        $stmt = $db->prepare(
            "UPDATE leave_type SET leave_name = :name, need_approval = :need_approval, agent = :agent,
                                   max_approval_level = :max_level, unit_type = :unit_type,
                                   require_attachment = :req_att, attach_min_days = :att_min,
                                   allow_attach_later = :att_later
             WHERE id = :id"
        );
        $stmt->bindParam(':name', $name, PDO::PARAM_STR);
        $stmt->bindParam(':need_approval', $need_approval, PDO::PARAM_INT);
        $stmt->bindParam(':agent', $agent, PDO::PARAM_INT);
        $stmt->bindParam(':max_level', $max_level, PDO::PARAM_INT);
        $stmt->bindValue(':unit_type', $ext['unit_type'], PDO::PARAM_STR);
        $stmt->bindValue(':req_att',   $ext['require_attachment'], PDO::PARAM_INT);
        $stmt->bindValue(':att_min',   $ext['attach_min_days']);
        $stmt->bindValue(':att_later', $ext['allow_attach_later'], PDO::PARAM_INT);
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
        // 使用中防呆（2026-07-29）：已有請假單引用此假別時不可刪除，否則舊單會查不到假別名稱
        $used = $db->prepare("SELECT COUNT(*) FROM leave_request WHERE leave_type_id = :id");
        $used->bindParam(':id', $id, PDO::PARAM_INT);
        $used->execute();
        $n = (int)$used->fetchColumn();
        if ($n > 0) {
            echo json_encode(['status' => 'error',
                'message' => "此假別已有 {$n} 張請假單使用中，不可刪除。若不再使用，請改為停用或改名。"]);
            exit;
        }

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