<?php
header('Content-Type: application/json; charset=utf-8');

// 引入設定與資料庫連線 (雖然此處為模擬，但保留架構)
$document_root = $_SERVER['DOCUMENT_ROOT'];
session_start();
include_once $document_root . '/EGsystem/src/common/_config.php';
include_once $document_root . '/EGsystem/src/common/DBConnection.php';

$db_connection = new DBConnection();
$db = $db_connection->getPDO();

// --- 權限檢查 (未來實作) ---
/*
if (!isset($_SESSION['user_permissions']['hr_setting']) || !$_SESSION['user_permissions']['hr_setting']) {
    echo json_encode(['status' => 'error', 'message' => '您沒有權限執行此操作。']);
    exit;
}
*/

$action = isset($_REQUEST['action']) ? $_REQUEST['action'] : '';

switch ($action) {
    // --- 部門 (Department) Actions ---
    case 'get_departments':
        getDepartments();
        break;
    case 'add_department':
        addDepartment();
        break;
    case 'get_parent_departments':
        getParentDepartments();
        break;
    case 'get_department_details':
        getDepartmentDetails();
        break;
    case 'update_department':
        updateDepartment();
        break;
    case 'delete_department':
        deleteDepartment();
        break;

    // --- 綁定 (Binding) Actions ---
    case 'get_department_positions':
        getDepartmentPositions();
        break;
    case 'update_department_positions':
        updateDepartmentPositions();
        break;

    // --- 使用者代理 (User Delegate) Actions ---
    case 'get_user_delegates':
        getUserDelegates();
        break;
    case 'update_user_delegates':
        updateUserDelegates();
        break;
    case 'get_user_scopes': // 某被代理人的職務身分(主職+兼任)清單，供「兼任身分別代理」下拉
        getUserScopes();
        break;

    // --- 部門×職稱 指定負責人 (P2) ---
    case 'get_dept_position_owners':
        getDeptPositionOwners();
        break;
    case 'update_dept_position_owner':
        updateDeptPositionOwner();
        break;
    case 'get_positions_missing_level': // 階級未設定職稱提醒
        getPositionsMissingLevel();
        break;

    // --- 職稱代理 (Position Delegate) Actions ---
    case 'get_position_delegates':
        getPositionDelegates();
        break;
    case 'update_position_delegates':
        updatePositionDelegates();
        break;
    // --- 職稱 (Job Title) Actions ---
    case 'get_positions': // 為了相容 hr_settings.php 的呼叫
        getJobTitles();
        break;
    case 'get_job_titles':
        getJobTitles();
        break;
    case 'add_job_title':
        addJobTitle();
        break;
    case 'get_job_title_details':
        getJobTitleDetails();
        break;
    case 'update_job_title':
        updateJobTitle();
        break;
    case 'delete_job_title':
        deleteJobTitle();
        break;

    default:
        echo json_encode(['status' => 'error', 'message' => '無效的操作。']);
        break;
}

function getDepartments() {
    global $db;
    try {
        $sql = "SELECT 
                    d1.id, d1.name, d1.parent_id, d1.level, d1.sort_order, d2.name AS parent_name,
                    GROUP_CONCAT(p.name SEPARATOR ', ') AS assigned_positions
                FROM department d1
                LEFT JOIN department d2 ON d1.parent_id = d2.id
                LEFT JOIN department_position dp ON d1.id = dp.department_id
                LEFT JOIN position p ON dp.position_id = p.id
                GROUP BY d1.id, d1.name, d1.parent_id, d1.level, d2.name
                ORDER BY d1.sort_order ASC, d1.level, d1.parent_id, d1.name";
        $stmt = $db->query($sql);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'success', 'data' => $data]);
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => '資料庫查詢失敗: ' . $e->getMessage()]);
    }
}

function addDepartment() {
    global $db;
    $name = isset($_POST['name']) ? trim($_POST['name']) : '';
    $parent_id = isset($_POST['parent_id']) && !empty($_POST['parent_id']) ? intval($_POST['parent_id']) : null;
    $level = isset($_POST['level']) ? intval($_POST['level']) : 0;
    $sort_order = isset($_POST['sort_order']) ? intval($_POST['sort_order']) : 0;

    if (empty($name)) {
        echo json_encode(['status' => 'error', 'message' => '部門名稱不可為空。']);
        return;
    }
    if ($level <= 0) {
        echo json_encode(['status' => 'error', 'message' => '請選擇層級。']);
        return;
    }
    if ($level >= 4 && $parent_id === null) {
        echo json_encode(['status' => 'error', 'message' => '「組」層級必須選擇一個上層課室。']);
        return;
    }

    try {
        // 檢查排序值是否重複
        $checkStmt = $db->prepare("SELECT COUNT(*) FROM department WHERE sort_order = :sort_order");
        $checkStmt->bindParam(':sort_order', $sort_order, PDO::PARAM_INT);
        $checkStmt->execute();
        if ($checkStmt->fetchColumn() > 0) {
            echo json_encode(['status' => 'error', 'message' => '排序值已被使用，請輸入不同的值。']);
            return;
        }

        $sql = "INSERT INTO department (name, parent_id, level, sort_order, created_at, updated_at) VALUES (:name, :parent_id, :level, :sort_order, NOW(), NOW())";
        $stmt = $db->prepare($sql);
        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':parent_id', $parent_id, $parent_id === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindParam(':level', $level, PDO::PARAM_INT);
        $stmt->bindParam(':sort_order', $sort_order, PDO::PARAM_INT);
        $stmt->execute();
        echo json_encode(['status' => 'success', 'message' => '部門新增成功。']);
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => '資料庫操作失敗: ' . $e->getMessage()]);
    }
}

function getParentDepartments() {
    global $db;
    try {
        $sql = "SELECT id, name FROM department WHERE level <= 3 ORDER BY level, name";
        $stmt = $db->query($sql);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'success', 'data' => $data]);
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => '讀取課室資料失敗: ' . $e->getMessage()]);
    }
}

function getDepartmentDetails() {
    global $db;
    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    if ($id <= 0) {
        echo json_encode(['status' => 'error', 'message' => '無效的 ID。']);
        return;
    }
    try {
        $stmt = $db->prepare("SELECT id, name, parent_id, level, sort_order FROM department WHERE id = :id");
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'success', 'data' => $data]);
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => '讀取資料失敗: ' . $e->getMessage()]);
    }
}

function updateDepartment() {
    global $db;
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    $name = isset($_POST['name']) ? trim($_POST['name']) : '';
    $parent_id = isset($_POST['parent_id']) && !empty($_POST['parent_id']) ? intval($_POST['parent_id']) : null;
    $level = isset($_POST['level']) ? intval($_POST['level']) : 0;
    $sort_order = isset($_POST['sort_order']) ? intval($_POST['sort_order']) : 0;

    if ($id <= 0 || empty($name) || $level <= 0) {
        echo json_encode(['status' => 'error', 'message' => '資料不完整。']);
        return;
    }
    if ($level >= 4 && $parent_id === null) {
        echo json_encode(['status' => 'error', 'message' => '「組」層級必須選擇一個上層課室。']);
        return;
    }

    try {
        // 檢查排序值是否與其他部門重複
        $checkStmt = $db->prepare("SELECT COUNT(*) FROM department WHERE sort_order = :sort_order AND id != :id");
        $checkStmt->bindParam(':sort_order', $sort_order, PDO::PARAM_INT);
        $checkStmt->bindParam(':id', $id, PDO::PARAM_INT);
        $checkStmt->execute();
        if ($checkStmt->fetchColumn() > 0) {
            echo json_encode(['status' => 'error', 'message' => '排序值已被其他部門使用，請輸入不同的值。']);
            return;
        }
        $sql = "UPDATE department SET name = :name, parent_id = :parent_id, level = :level, sort_order = :sort_order, updated_at = NOW() WHERE id = :id";
        $stmt = $db->prepare($sql);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':parent_id', $parent_id, $parent_id === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindParam(':level', $level, PDO::PARAM_INT);
        $stmt->bindParam(':sort_order', $sort_order, PDO::PARAM_INT);
        $stmt->execute();
        echo json_encode(['status' => 'success', 'message' => '部門更新成功。']);
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => '資料庫更新失敗: ' . $e->getMessage()]);
    }
}

function deleteDepartment() {
    global $db;
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    if ($id <= 0) {
        echo json_encode(['status' => 'error', 'message' => '無效的 ID。']);
        return;
    }
    try {
        $stmt = $db->prepare("DELETE FROM department WHERE id = :id");
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        echo json_encode(['status' => 'success', 'message' => '部門刪除成功。']);
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => '資料庫刪除失敗: ' . $e->getMessage()]);
    }
}

function getDepartmentPositions() {
    global $db;
    $department_id = isset($_GET['department_id']) ? intval($_GET['department_id']) : 0;
    if ($department_id <= 0) {
        echo json_encode(['status' => 'error', 'message' => '無效的部門 ID。']);
        return;
    }
    try {
        $stmt = $db->prepare("SELECT position_id FROM department_position WHERE department_id = :department_id");
        $stmt->bindParam(':department_id', $department_id, PDO::PARAM_INT);
        $stmt->execute();
        $data = $stmt->fetchAll(PDO::FETCH_COLUMN, 0); // 只獲取 position_id 欄位
        echo json_encode(['status' => 'success', 'data' => $data]);
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => '讀取綁定資料失敗: ' . $e->getMessage()]);
    }
}

function updateDepartmentPositions() {
    global $db;
    $department_id = isset($_POST['department_id']) ? intval($_POST['department_id']) : 0;
    $position_ids = isset($_POST['position_ids']) ? $_POST['position_ids'] : [];

    if ($department_id <= 0) {
        echo json_encode(['status' => 'error', 'message' => '無效的部門 ID。']);
        return;
    }

    try {
        $db->beginTransaction();
        $db->prepare("DELETE FROM department_position WHERE department_id = ?")->execute([$department_id]);
        $stmt = $db->prepare("INSERT INTO department_position (department_id, position_id, created_at, updated_at) VALUES (?, ?, NOW(), NOW())");
        foreach ($position_ids as $position_id) {
            $stmt->execute([$department_id, $position_id]);
        }
        $db->commit();
        echo json_encode(['status' => 'success', 'message' => '職稱綁定更新成功。']);
    } catch (PDOException $e) {
        $db->rollBack();
        echo json_encode(['status' => 'error', 'message' => '資料庫操作失敗: ' . $e->getMessage()]);
    }
}

function getUserDelegates() {
    global $db;
    try {
        $sql = "SELECT ud.id, ud.user_id, u1.user_cname, ud.delegate_id, u2.user_cname as delegate_cname,
                       ud.scope_department_id, ud.scope_position_id, sd.name AS scope_department_name, sp.name AS scope_position_name,
                       ud.start_date, ud.end_date, ud.active, ud.priority
                FROM user_delegate ud
                JOIN user u1 ON ud.user_id = u1.id
                JOIN user u2 ON ud.delegate_id = u2.id
                LEFT JOIN department sd ON sd.id = ud.scope_department_id
                LEFT JOIN position sp ON sp.id = ud.scope_position_id
                ORDER BY ud.user_id, ud.start_date DESC, ud.priority ASC";
        $stmt = $db->query($sql);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'success', 'data' => $data]);
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => '讀取代理資料失敗: ' . $e->getMessage()]);
    }
}

function updateUserDelegates() {
    global $db;
    // 新的資料
    $user_id = $_POST['user_id'] ?? 0;
    $start_date = $_POST['start_date'] ?? '';
    $end_date = $_POST['end_date'] ?? '';
    $delegate_ids = $_POST['delegate_ids'] ?? [];
    $active = $_POST['active'] ?? 1;
    // 職務身分別代理（scope）；空字串 => NULL（主職/全域）
    $scope_dep = (isset($_POST['scope_department_id']) && $_POST['scope_department_id'] !== '') ? intval($_POST['scope_department_id']) : null;
    $scope_pos = (isset($_POST['scope_position_id']) && $_POST['scope_position_id'] !== '') ? intval($_POST['scope_position_id']) : null;

    // 來自編輯時的原始資料 key：user_id|scopeDep|scopePos|start|end（scope 空字串=NULL）
    $original_key = $_POST['original_key'] ?? '';

    if (empty($user_id) || empty($start_date) || empty($end_date)) {
        echo json_encode(['status' => 'error', 'message' => '被代理人、開始日期和結束日期為必填！']);
        return;
    }

    try {
        $db->beginTransaction();

        // 如果有 original_key，表示是編輯模式，使用原始 key 來刪除舊資料（scope 精準比對，NULL 用 IS NULL）
        if (!empty($original_key)) {
            $parts = explode('|', $original_key);
            // 相容舊格式(3段) 與 新格式(5段)
            if (count($parts) === 5) {
                list($o_uid, $o_dep, $o_pos, $o_start, $o_end) = $parts;
            } else {
                list($o_uid, $o_start, $o_end) = $parts; $o_dep = ''; $o_pos = '';
            }
            $sqlDel = "DELETE FROM user_delegate WHERE user_id = ? AND start_date = ? AND end_date = ?
                       AND " . ($o_dep === '' ? "scope_department_id IS NULL" : "scope_department_id = " . intval($o_dep)) . "
                       AND " . ($o_pos === '' ? "scope_position_id IS NULL" : "scope_position_id = " . intval($o_pos));
            $deleteStmt = $db->prepare($sqlDel);
            $deleteStmt->execute([$o_uid, $o_start, $o_end]);
        }

        // 如果 delegate_ids 是空的，代表是刪除操作，直接完成交易並返回
        if (empty($delegate_ids)) {
            $db->commit();
            echo json_encode(['status' => 'success', 'message' => '使用者代理規則已刪除。']);
            return;
        }

        // 2. 插入新的代理規則
        if (!empty($delegate_ids)) {
            $insertStmt = $db->prepare("INSERT INTO user_delegate (user_id, delegate_id, scope_department_id, scope_position_id, start_date, end_date, active, priority) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            foreach ($delegate_ids as $index => $delegate_id) {
                $priority = $index + 1; // 順序從 1 開始
                $insertStmt->execute([$user_id, $delegate_id, $scope_dep, $scope_pos, $start_date, $end_date, $active, $priority]);
            }
        }

        $db->commit();
        echo json_encode(['status' => 'success', 'message' => '使用者代理規則已更新。']);
    } catch (PDOException $e) {
        $db->rollBack();
        echo json_encode(['status' => 'error', 'message' => '資料庫操作失敗: ' . $e->getMessage()]);
    }
}

function getPositionDelegates() {
    global $db;
    $sql = "SELECT pd.id, pd.position_id, p1.name as position_name, pd.delegate_position_id, p2.name as delegate_position_name, pd.priority
            FROM position_delegate pd
            JOIN position p1 ON pd.position_id = p1.id
            JOIN position p2 ON pd.delegate_position_id = p2.id
            ORDER BY pd.position_id, pd.priority ASC";
    $stmt = $db->query($sql);
    echo json_encode(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

function updatePositionDelegates() {
    global $db;
    $position_id = $_POST['position_id'] ?? 0;
    $delegate_ids = $_POST['delegate_ids'] ?? [];

    if (empty($position_id)) {
        echo json_encode(['status' => 'error', 'message' => '未提供主職稱 ID。']);
        return;
    }

    try {
        $db->beginTransaction();

        // 1. 刪除此主職稱所有舊的代理規則
        $deleteStmt = $db->prepare("DELETE FROM position_delegate WHERE position_id = ?");
        $deleteStmt->execute([$position_id]);

        // 2. 插入新的代理規則
        $insertStmt = $db->prepare("INSERT INTO position_delegate (position_id, delegate_position_id, priority) VALUES (?, ?, ?)");
        foreach ($delegate_ids as $index => $delegate_id) {
            $priority = $index + 1; // 順序從 1 開始
            $insertStmt->execute([$position_id, $delegate_id, $priority]);
        }
        $db->commit();
        echo json_encode(['status' => 'success', 'message' => '職稱代理規則已更新。']);
    } catch (PDOException $e) {
        $db->rollBack();
        echo json_encode(['status' => 'error', 'message' => '資料庫操作失敗: ' . $e->getMessage()]);
    }
}

function getJobTitles() {
    global $db;
    try {
        // 使用 LEFT JOIN 從 position_level 取得 level
        $sql = "SELECT p.id, p.name, p.sort_order, pl.level 
                FROM `position` p
                LEFT JOIN `position_level` pl ON p.id = pl.position_id
                ORDER BY p.sort_order ASC, p.id ASC";
        $stmt = $db->query($sql);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'success', 'data' => $data]);
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => '讀取職稱資料失敗: ' . $e->getMessage()]);
    }
}

function addJobTitle() {
    global $db;
    $name = isset($_POST['name']) ? trim($_POST['name']) : '';
    $sort_order = isset($_POST['sort_order']) ? intval($_POST['sort_order']) : 0;
    // 接收 level，如果是空字串，則設為 NULL
    $level = isset($_POST['level']) && $_POST['level'] !== '' ? intval($_POST['level']) : null;

    if (empty($name)) {
        echo json_encode(['status' => 'error', 'message' => '職稱名稱不可為空。']);
        return;
    }

    $db->beginTransaction();

    try {
        // 檢查職稱名稱是否已存在
        $checkStmt = $db->prepare("SELECT COUNT(*) FROM `position` WHERE name = :name");
        $checkStmt->bindParam(':name', $name, PDO::PARAM_STR);
        $checkStmt->execute();
        if ($checkStmt->fetchColumn() > 0) {
            $db->rollBack(); // 不需要繼續執行，所以回滾
            echo json_encode(['status' => 'error', 'message' => '職稱名稱已存在，請使用不同的名稱。']);
            return;
        }

        // 1. 新增到 `position` 資料表
        $stmt = $db->prepare("INSERT INTO `position` (name, sort_order, created_at, updated_at) VALUES (:name, :sort_order, NOW(), NOW())");
        $stmt->bindParam(':name', $name, PDO::PARAM_STR);
        $stmt->bindParam(':sort_order', $sort_order, PDO::PARAM_INT);
        $stmt->execute();
        $position_id = $db->lastInsertId();

        // 2. 新增到 `position_level` 資料表
        $stmt_level = $db->prepare(
            "INSERT INTO `position_level` (position_id, `level`, created_at, updated_at) 
             VALUES (:position_id, :level, NOW(), NOW())"
        );
        $stmt_level->bindParam(':position_id', $position_id, PDO::PARAM_INT);
        $stmt_level->bindParam(':level', $level, $level === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt_level->execute();

        $db->commit();
        echo json_encode(['status' => 'success', 'message' => '職稱新增成功。']);

    } catch (PDOException $e) {
        $db->rollBack();
        // 檢查是否為重複鍵值的錯誤 (通常是 name 欄位設定為 UNIQUE)
        if ($e->getCode() == 23000) {
            echo json_encode(['status' => 'error', 'message' => '職稱名稱可能已重複。']);
        } else {
            echo json_encode(['status' => 'error', 'message' => '資料庫錯誤: ' . $e->getMessage()]);
        }
    }
}

function getJobTitleDetails() {
    global $db;
    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    if ($id <= 0) {
        echo json_encode(['status' => 'error', 'message' => '無效的 ID。']);
        return;
    }
    try {
        $sql = "SELECT p.id, p.name, p.sort_order, pl.level 
                FROM `position` p
                LEFT JOIN `position_level` pl ON p.id = pl.position_id
                WHERE p.id = :id";
        $stmt = $db->prepare($sql);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        $data = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($data) {
            echo json_encode(['status' => 'success', 'data' => $data]);
        } else {
            echo json_encode(['status' => 'error', 'message' => '找不到指定的職稱。']);
        }
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => '讀取資料失敗: ' . $e->getMessage()]);
    }
}

function updateJobTitle() {
    global $db;
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    $name = isset($_POST['name']) ? trim($_POST['name']) : '';
    $sort_order = isset($_POST['sort_order']) ? intval($_POST['sort_order']) : 0;
    $level = isset($_POST['level']) && $_POST['level'] !== '' ? intval($_POST['level']) : null;

    if ($id <= 0 || empty($name)) {
        echo json_encode(['status' => 'error', 'message' => '資料不完整。']);
        return;
    }

    $db->beginTransaction();

    try {
        // 檢查名稱是否與其他職稱重複
        $checkStmt = $db->prepare("SELECT COUNT(*) FROM `position` WHERE name = :name AND id != :id");
        $checkStmt->bindParam(':name', $name, PDO::PARAM_STR);
        $checkStmt->bindParam(':id', $id, PDO::PARAM_INT);
        $checkStmt->execute();
        if ($checkStmt->fetchColumn() > 0) {
            $db->rollBack();
            echo json_encode(['status' => 'error', 'message' => '此職稱名稱已被使用，請更換。']);
            return;
        }

        // 1. 更新 `position` 資料表
        $stmt = $db->prepare("UPDATE `position` SET name = :name, sort_order = :sort_order, updated_at = NOW() WHERE id = :id");
        $stmt->bindParam(':name', $name, PDO::PARAM_STR);
        $stmt->bindParam(':sort_order', $sort_order, PDO::PARAM_INT);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->execute();

        // 2. 更新或刪除 `position_level`
        if ($level !== null) {
            // 如果 level 有值，則更新或新增
            $check_level_stmt = $db->prepare("SELECT COUNT(*) FROM `position_level` WHERE position_id = :id");
            $check_level_stmt->execute([':id' => $id]);
            if ($check_level_stmt->fetchColumn() > 0) {
                // 已存在，更新 level
                $stmt_level = $db->prepare("UPDATE `position_level` SET `level` = :level, updated_at = NOW() WHERE position_id = :id");
            } else {
                // 不存在，新增 level
                $stmt_level = $db->prepare("INSERT INTO `position_level` (position_id, `level`, created_at, updated_at) VALUES (:id, :level, NOW(), NOW())");
            }
            $stmt_level->bindParam(':id', $id, PDO::PARAM_INT);
            $stmt_level->bindParam(':level', $level, PDO::PARAM_INT);
            $stmt_level->execute();
        } else {
            // 如果 level 是 null，表示要移除主管等級，刪除記錄
            $stmt_level_delete = $db->prepare("DELETE FROM `position_level` WHERE position_id = :id");
            $stmt_level_delete->execute([':id' => $id]);
        }

        $db->commit();
        echo json_encode(['status' => 'success', 'message' => '職稱更新成功。']);

    } catch (PDOException $e) {
        $db->rollBack();
        if ($e->getCode() == 23000) {
            echo json_encode(['status' => 'error', 'message' => '此職稱名稱已被使用，請更換。']);
        } else {
            echo json_encode(['status' => 'error', 'message' => '資料庫錯誤: ' . $e->getMessage()]);
        }
    }
}

function deleteJobTitle() {
    global $db;
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    if ($id <= 0) {
        echo json_encode(['status' => 'error', 'message' => '無效的 ID。']);
        return;
    }

    $db->beginTransaction();

    try {
        // 檢查1：此職稱是否被部門綁定
        $check_dept_stmt = $db->prepare("SELECT COUNT(*) FROM `department_position` WHERE position_id = :id");
        $check_dept_stmt->execute([':id' => $id]);
        if ($check_dept_stmt->fetchColumn() > 0) {
            throw new Exception('刪除失敗：此職稱已被部門綁定，請先解除綁定。');
        }

        // 檢查2：此職稱是否被用於職稱代理設定中
        $check_delegate_stmt = $db->prepare(
            "SELECT COUNT(*) FROM `position_delegate` WHERE position_id = :id OR delegate_position_id = :id"
        );
        $check_delegate_stmt->execute([':id' => $id]);
        if ($check_delegate_stmt->fetchColumn() > 0) {
            throw new Exception('刪除失敗：此職稱正被用於代理人設定中，請先移除相關設定。');
        }

        // 1. 從 `position_level` 刪除
        $stmt_level = $db->prepare("DELETE FROM `position_level` WHERE position_id = :id");
        $stmt_level->execute([':id' => $id]);

        // 2. 從 `position` 刪除
        $stmt = $db->prepare("DELETE FROM `position` WHERE id = :id");
        $stmt->execute([':id' => $id]);

        if ($stmt->rowCount() > 0) {
            $db->commit();
            echo json_encode(['status' => 'success', 'message' => '職稱刪除成功。']);
        } else {
            throw new Exception('找不到要刪除的職稱或刪除失敗。');
        }
    } catch (Exception $e) {
        $db->rollBack();
        // 處理外鍵約束錯誤或其他自訂例外
        if ($e instanceof PDOException && $e->getCode() == 23000) {
             echo json_encode(['status' => 'error', 'message' => '刪除失敗：此職稱可能仍被系統其他部分(如代理人設定)使用中。']);
        } else {
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }
    }
}

// ===== P2：職務身分別代理 / 部門×職稱指定負責人 / 階級提醒 =====

/** 某被代理人的職務身分清單（主職 + 兼任），供「兼任身分別代理」下拉選擇 */
function getUserScopes() {
    global $db;
    $user_id = intval($_REQUEST['user_id'] ?? 0);
    if ($user_id <= 0) { echo json_encode(['status'=>'error','message'=>'缺少 user_id']); return; }
    try {
        $sql = "SELECT m.department_id, d.name AS department_name, m.position_id, p.name AS position_name, m.is_main
                FROM user_department_position_map m
                LEFT JOIN department d ON d.id = m.department_id
                LEFT JOIN position p ON p.id = m.position_id
                WHERE m.user_id = ?
                ORDER BY m.is_main DESC, m.id ASC";
        $stmt = $db->prepare($sql);
        $stmt->execute([$user_id]);
        echo json_encode(['status'=>'success','data'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (PDOException $e) {
        echo json_encode(['status'=>'error','message'=>'讀取職務身分失敗: '.$e->getMessage()]);
    }
}

/** 列出所有 部門×職稱 綁定及其指定負責人；並附各綁定可指派的在職人員清單 */
function getDeptPositionOwners() {
    global $db;
    try {
        $sql = "SELECT dp.id, dp.department_id, d.name AS department_name, dp.position_id, p.name AS position_name,
                       dp.primary_user_id, u.user_cname AS primary_user_name, pl.level
                FROM department_position dp
                JOIN department d ON d.id = dp.department_id
                JOIN position p ON p.id = dp.position_id
                LEFT JOIN user u ON u.id = dp.primary_user_id
                LEFT JOIN position_level pl ON pl.position_id = dp.position_id
                ORDER BY d.sort_order, d.name, p.sort_order, p.name";
        $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        // 各 部門×職稱 目前在職的持有者（可被指派為負責人）
        $candStmt = $db->prepare("SELECT m.user_id, u.user_cname
                                  FROM user_department_position_map m
                                  JOIN user u ON u.id = m.user_id AND u.state = 1
                                  WHERE m.department_id = ? AND m.position_id = ?
                                  ORDER BY u.user_cname");
        foreach ($rows as &$r) {
            $candStmt->execute([$r['department_id'], $r['position_id']]);
            $r['candidates'] = $candStmt->fetchAll(PDO::FETCH_ASSOC);
        }
        unset($r);
        echo json_encode(['status'=>'success','data'=>$rows]);
    } catch (PDOException $e) {
        echo json_encode(['status'=>'error','message'=>'讀取指定負責人失敗: '.$e->getMessage()]);
    }
}

/** 設定某 部門×職稱 綁定的指定負責人（primary_user_id）；空值=清除 */
function updateDeptPositionOwner() {
    global $db;
    $dp_id = intval($_POST['dp_id'] ?? 0);
    $primary_user_id = (isset($_POST['primary_user_id']) && $_POST['primary_user_id'] !== '') ? intval($_POST['primary_user_id']) : null;
    if ($dp_id <= 0) { echo json_encode(['status'=>'error','message'=>'缺少 dp_id']); return; }
    try {
        // 驗證：若有指定人，該人須確實擔任此 部門×職稱 且在職
        if ($primary_user_id !== null) {
            $chk = $db->prepare("SELECT COUNT(*) FROM user_department_position_map m JOIN user u ON u.id=m.user_id AND u.state=1
                                 JOIN department_position dp ON dp.department_id=m.department_id AND dp.position_id=m.position_id
                                 WHERE dp.id = ? AND m.user_id = ?");
            $chk->execute([$dp_id, $primary_user_id]);
            if ((int)$chk->fetchColumn() === 0) {
                echo json_encode(['status'=>'error','message'=>'指定的負責人並未擔任此部門×職稱，或已離職。']); return;
            }
        }
        $stmt = $db->prepare("UPDATE department_position SET primary_user_id = ? WHERE id = ?");
        $stmt->execute([$primary_user_id, $dp_id]);
        echo json_encode(['status'=>'success','message'=>'指定負責人已更新。']);
    } catch (PDOException $e) {
        echo json_encode(['status'=>'error','message'=>'更新失敗: '.$e->getMessage()]);
    }
}

/** 尚未設定主管階級(position_level)的職稱清單（供 SoD 主管鏈提醒管理員補齊） */
function getPositionsMissingLevel() {
    global $db;
    try {
        $sql = "SELECT p.id, p.name
                FROM position p
                LEFT JOIN position_level pl ON pl.position_id = p.id
                WHERE pl.id IS NULL OR pl.level IS NULL
                ORDER BY p.sort_order, p.name";
        $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status'=>'success','data'=>$rows]);
    } catch (PDOException $e) {
        echo json_encode(['status'=>'error','message'=>'讀取失敗: '.$e->getMessage()]);
    }
}

?>