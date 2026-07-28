<?php
header('Content-Type: application/json; charset=utf-8');

// 引入設定與資料庫連線
$document_root = $_SERVER['DOCUMENT_ROOT'];
session_start();
include_once $document_root . '/EGsystem/src/common/_config.php';
include_once $document_root . '/EGsystem/src/common/DBConnection.php';
// 特休天數算法已抽為共用庫（請假系統額度也用同一套，避免兩邊數字不一致）
require_once $document_root . '/EGsystem/src/common/annual_leave_lib.php';

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
    case 'get_employees':
        getEmployees();
        break;
    case 'get_employees_for_selection':
        getEmployeesForSelection();
        exit; // 確保執行完畢後立即停止，避免被後續 include 的檔案覆蓋
        break;
    case 'get_employee_details':
        getEmployeeDetails();
        break;
    case 'add_employee':
        addOrUpdateEmployee('add');
        break;
    case 'update_employee':
        addOrUpdateEmployee('update');
        break;
    case 'delete_employee':
        deleteEmployee();
        break;

    // --- 從 department_job_title_api.php 搬移過來的 Actions ---
    case 'get_departments':
        getDepartments();
        break;
    case 'get_department_positions_for_assignment': // 為了對應前端的呼叫
        getDepartmentPositions();
        break;
    case 'get_organization_data':
        getOrganizationData();
        break;

    default:
        echo json_encode(['status' => 'error', 'message' => '在 employee_api 中無效的操作。']);
        break;
}

/**
 * 根據到職日，以曆年制按比例計算特休天數
 * 實作已移至 src/common/annual_leave_lib.php（請假系統的特休額度共用同一套算法）。
 * 本函式保留為薄包裝，行為與原本完全相同。
 * @param string|null $hireDate 到職日 (Y-m-d)
 * @return float 計算後的天數
 */
function calculateProratedAnnualLeave($hireDate) {
    return eg_annual_leave_raw($hireDate);
}

function getEmployees() {
    global $db;
    try {
        $sql = "SELECT 
                    u.id, u.user_uname, u.user_cname, u.user_status, u.state, u.gender, u.hire_date,
                    d.name as main_department_name,
                    p.name as main_position_name,
                    (SELECT GROUP_CONCAT(CONCAT(d2.name, ' / ', p2.name) SEPARATOR '; ') 
                     FROM user_department_position_map map2
                     JOIN department d2 ON map2.department_id = d2.id
                     JOIN position p2 ON map2.position_id = p2.id WHERE map2.user_id = u.id AND map2.is_main = 0) as concurrent_positions,
                    -- 備註欄位
                    CASE
                        WHEN u.state = 0 THEN CONCAT('離職日：', IFNULL(u.leave_date, '未記錄'))
                        WHEN u.state IN (2, 3) THEN (
                            SELECT CONCAT(
                                '開始：', IFNULL(h.start_date, ''), 
                                ' 結束：', IFNULL(h.end_date, ''), 
                                ' 備註：', IFNULL(h.remark, '')
                            )
                            FROM user_status_history h
                            WHERE h.user_id = u.id AND h.status = u.state
                            ORDER BY h.id DESC LIMIT 1
                        )
                        ELSE ''
                    END as remark
                FROM user u
                LEFT JOIN user_department_position_map map1 ON u.id = map1.user_id AND map1.is_main = 1
                LEFT JOIN department d ON map1.department_id = d.id
                LEFT JOIN position p ON map1.position_id = p.id
                ORDER BY 
                    CASE WHEN u.state = 0 THEN 1 ELSE 0 END ASC, -- 1. 將離職員工(state=0)排到最後
                    CASE u.user_status WHEN 99 THEN 0 WHEN 90 THEN 1 ELSE 2 END ASC, -- 2. 狀態99最前, 其次90
                    CASE WHEN d.id IS NULL OR p.id IS NULL THEN 0 ELSE 1 END ASC, -- 3. 將沒有主部門或主職稱的員工排在狀態90之後
                    d.sort_order ASC, -- 4. 依照主部門的排序順序
                    p.sort_order ASC, -- 5. 依照主職稱的排序順序
                    u.id ASC";
        $stmt = $db->query($sql);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 在 PHP 中計算特休天數
        foreach ($data as $key => $employee) {
            if ($employee['state'] != 0) { // 只計算在職員工
                $data[$key]['annual_leave_days'] = calculateProratedAnnualLeave($employee['hire_date']);
            } else {
                $data[$key]['annual_leave_days'] = 0;
            }
        }

        echo json_encode(['status' => 'success', 'data' => $data]);
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => '資料庫查詢失敗: ' . $e->getMessage()]);
    }
}

/**
 * 獲取用於下拉選單的員工列表（ID, 姓名, 員工編號）
 */
function getEmployeesForSelection() {
    global $db;
    try {
        // 獲取所有在職 (state = 1) 的員工，並包含其主要部門與職稱
        $sql = "SELECT 
                    u.id, 
                    u.user_uname, 
                    u.user_cname,
                    d.name AS department_name,
                    pos.name AS position_name
                FROM user u 
                LEFT JOIN user_department_position_map udpm ON u.id = udpm.user_id AND udpm.is_main = 1
                LEFT JOIN department d ON udpm.department_id = d.id
                LEFT JOIN position pos ON udpm.position_id = pos.id
                WHERE u.state = 1 
                ORDER BY 
                    CASE WHEN d.name IS NULL THEN 1 ELSE 0 END, -- 將沒有部門的排在後面
                    d.name ASC, 
                    pos.name ASC, 
                    u.user_cname ASC";
        $stmt = $db->query($sql);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'success', 'data' => $data]);
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => '讀取使用者資料失敗: ' . $e->getMessage()]);
    }
}

function getEmployeeDetails() {
    global $db;
    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    if ($id <= 0) {
        echo json_encode(['status' => 'error', 'message' => '無效的員工 ID。']);
        return;
    }

    try {
        // 獲取基本資料
        $stmt = $db->prepare("SELECT user_uname, user_cname, phone, user_status, state, gender, hire_date, leave_date FROM user WHERE id = ?");
        $stmt->execute([$id]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$data) {
            echo json_encode(['status' => 'error', 'message' => '找不到該員工。']);
            return;
        }

        // 獲取主職務
        $stmt_main = $db->prepare("SELECT department_id, position_id FROM user_department_position_map WHERE user_id = ? AND is_main = 1");
        $stmt_main->execute([$id]);
        $main_pos = $stmt_main->fetch(PDO::FETCH_ASSOC);
        $data['main_department_id'] = $main_pos['department_id'] ?? null;
        $data['main_position_id'] = $main_pos['position_id'] ?? null;

        // 獲取兼任職務
        $stmt_concurrent = $db->prepare("SELECT department_id, position_id FROM user_department_position_map WHERE user_id = ? AND is_main = 0 ORDER BY id ASC");
        $stmt_concurrent->execute([$id]);
        $data['concurrent_positions'] = $stmt_concurrent->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['status' => 'success', 'data' => $data]);
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => '讀取員工詳細資料失敗: ' . $e->getMessage()]);
    }
}

function addOrUpdateEmployee($mode) {
    global $db;
    // 這裡可以加上更完整的資料驗證
    $id = $_POST['id'] ?? null;
    $user_password = $_POST['user_password'] ?? '';
    $user_uname = $_POST['user_uname'] ?? '';
    $user_cname = $_POST['user_cname'] ?? '';
    $state = $_POST['state'] ?? 1;
    $gender = $_POST['gender'] ?? null;
    $hire_date = !empty($_POST['hire_date']) ? $_POST['hire_date'] : null;
    $leave_date = !empty($_POST['leave_date']) ? $_POST['leave_date'] : null;

    if ($mode === 'add' && empty($id)) {
        echo json_encode(['status' => 'error', 'message' => '新增時員工編號(ID)為必填。']);
        return;
    }
    if ($mode === 'update' && empty($id)) {
        echo json_encode(['status' => 'error', 'message' => '更新時需要提供員工 ID。']);
        return;
    }
    if (empty($user_uname)) {
        echo json_encode(['status' => 'error', 'message' => '登入帳號為必填。']);
        return;
    }

    try {
        $db->beginTransaction();

        if ($mode === 'add') {
            // 檢查 ID 和 user_uname 是否唯一
            $stmt_check = $db->prepare("SELECT id FROM user WHERE id = ? OR user_uname = ?");
            $stmt_check->execute([$id, $user_uname]);
            if ($stmt_check->fetch()) {
                echo json_encode(['status' => 'error', 'message' => '員工編號(ID)或登入帳號已存在，請使用不同的值。']);
                $db->rollBack();
                return;
            }

            $sql = "INSERT INTO user (id, user_uname, user_cname, phone, user_password, state, gender, hire_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $db->prepare($sql);
            $stmt->execute([$id, $user_uname, $user_cname, $_POST['phone'], $user_password, $state, $gender, $hire_date]);
        } else { // update
            // 檢查 user_uname 是否與其他使用者重複
            $stmt_check = $db->prepare("SELECT id FROM user WHERE user_uname = ? AND id != ?");
            $stmt_check->execute([$user_uname, $id]);
            if ($stmt_check->fetch()) {
                echo json_encode(['status' => 'error', 'message' => '登入帳號已被其他使用者使用。']);
                $db->rollBack();
                return;
            }

            // 如果狀態為離職，則更新離職日期，否則設為 NULL
            $final_leave_date = ($state == 0) ? $leave_date : null;

            // 根據是否有提供密碼來決定是否更新密碼欄位
            if (!empty($user_password)) {
                $sql = "UPDATE user SET user_uname = ?, user_cname = ?, phone = ?, user_password = ?, state = ?, gender = ?, hire_date = ?, leave_date = ? WHERE id = ?";
                $stmt = $db->prepare($sql);
                $stmt->execute([$user_uname, $user_cname, $_POST['phone'], $user_password, $state, $gender, $hire_date, $final_leave_date, $id]);
            } else {
                $sql = "UPDATE user SET user_uname = ?, user_cname = ?, phone = ?, state = ?, gender = ?, hire_date = ?, leave_date = ? WHERE id = ?";
                $stmt = $db->prepare($sql);
                $stmt->execute([$user_uname, $user_cname, $_POST['phone'], $state, $gender, $hire_date, $final_leave_date, $id]);
            }
        }

        // === P4：人員異動 → 代理設定連動檢查（僅 update 模式；規範見 ai-rules/11） ===
        // 若此次異動移除了某職務身分，會使「綁該身分的 scoped 代理」與「此人擔任的指定負責人」失效；
        // 未確認前擋下存檔（rollback 回傳 need_confirm），確認後於本交易一併停用。
        $delegate_cleanup = [];
        if ($mode === 'update') {
            $oldStmt = $db->prepare("SELECT department_id, position_id, is_main FROM user_department_position_map WHERE user_id = ?");
            $oldStmt->execute([$id]);
            $oldSet = []; $oldMainDep = null;
            foreach ($oldStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $oldSet[$r['department_id'] . ':' . $r['position_id']] = true;
                if ((int)$r['is_main'] === 1) $oldMainDep = (int)$r['department_id'];
            }
            $newSet = []; $newMainDep = !empty($_POST['main_department_id']) ? (int)$_POST['main_department_id'] : null;
            if (!empty($_POST['main_department_id']) && !empty($_POST['main_position_id'])) $newSet[$_POST['main_department_id'] . ':' . $_POST['main_position_id']] = true;
            if (isset($_POST['concurrent']) && is_array($_POST['concurrent'])) {
                foreach ($_POST['concurrent'] as $cp) if (!empty($cp['department_id']) && !empty($cp['position_id'])) $newSet[$cp['department_id'] . ':' . $cp['position_id']] = true;
            }
            $removed = array_diff_key($oldSet, $newSet);

            $affected = ['as_target_scoped' => [], 'as_primary_owner' => [], 'as_delegate_info' => []];
            foreach (array_keys($removed) as $key) {
                list($dep, $pos) = explode(':', $key);
                $st = $db->prepare("SELECT ud.id, u.user_cname AS delegate_name, d.name AS dep_name, p.name AS pos_name
                                    FROM user_delegate ud JOIN user u ON u.id = ud.delegate_id
                                    LEFT JOIN department d ON d.id = ud.scope_department_id
                                    LEFT JOIN position p ON p.id = ud.scope_position_id
                                    WHERE ud.user_id = ? AND ud.scope_department_id = ? AND ud.scope_position_id = ? AND ud.active = 1");
                $st->execute([$id, $dep, $pos]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) $affected['as_target_scoped'][] = $row;

                $st2 = $db->prepare("SELECT dp.id, d.name AS dep_name, p.name AS pos_name
                                     FROM department_position dp JOIN department d ON d.id = dp.department_id JOIN position p ON p.id = dp.position_id
                                     WHERE dp.primary_user_id = ? AND dp.department_id = ? AND dp.position_id = ?");
                $st2->execute([$id, $dep, $pos]);
                foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $row) $affected['as_primary_owner'][] = $row;
            }
            if ($oldMainDep !== $newMainDep) {
                $st3 = $db->prepare("SELECT ud.id, u.user_cname AS target_name FROM user_delegate ud JOIN user u ON u.id = ud.user_id WHERE ud.delegate_id = ? AND ud.active = 1");
                $st3->execute([$id]);
                $affected['as_delegate_info'] = $st3->fetchAll(PDO::FETCH_ASSOC);
            }

            $mustHandle = !empty($affected['as_target_scoped']) || !empty($affected['as_primary_owner']);
            $needConfirm = $mustHandle || !empty($affected['as_delegate_info']);
            if ($needConfirm && (($_POST['confirm_delegate'] ?? '') !== '1')) {
                $db->rollBack();
                echo json_encode(['status' => 'need_confirm', 'affected' => $affected,
                    'message' => '此員工的部門/職位異動會影響既有代理設定，請確認處理方式。']);
                return;
            }
            if ($mustHandle) $delegate_cleanup = array_keys($removed);
        }

        // 更新職務對應
        // 1. 刪除舊的對應
        $db->prepare("DELETE FROM user_department_position_map WHERE user_id = ?")->execute([$id]);

        // 2. 新增主職務
        if (!empty($_POST['main_department_id']) && !empty($_POST['main_position_id'])) {
            $stmt_map = $db->prepare("INSERT INTO user_department_position_map (user_id, department_id, position_id, is_main) VALUES (?, ?, ?, 1)");
            $stmt_map->execute([$id, $_POST['main_department_id'], $_POST['main_position_id']]);
        }

        // 3. 新增兼任職務
        if (isset($_POST['concurrent']) && is_array($_POST['concurrent'])) {
            foreach ($_POST['concurrent'] as $concurrent_pos) {
                if (!empty($concurrent_pos['department_id']) && !empty($concurrent_pos['position_id'])) {
                    $stmt_map = $db->prepare("INSERT INTO user_department_position_map (user_id, department_id, position_id, is_main) VALUES (?, ?, ?, 0)");
                    $stmt_map->execute([$id, $concurrent_pos['department_id'], $concurrent_pos['position_id']]);
                }
            }
        }

        // P4：確認後，停用因身分移除而失效的代理設定（scoped 代理停用、指定負責人清空）
        if (!empty($delegate_cleanup)) {
            foreach ($delegate_cleanup as $key) {
                list($dep, $pos) = explode(':', $key);
                $db->prepare("UPDATE user_delegate SET active = 0 WHERE user_id = ? AND scope_department_id = ? AND scope_position_id = ?")->execute([$id, $dep, $pos]);
                $db->prepare("UPDATE department_position SET primary_user_id = NULL WHERE primary_user_id = ? AND department_id = ? AND position_id = ?")->execute([$id, $dep, $pos]);
            }
        }

        // 4. 處理留職停薪或育嬰留停的歷史紀錄
        if ($mode === 'update' && in_array($state, [2, 3])) {
            $status_start_date = !empty($_POST['status_start_date']) ? $_POST['status_start_date'] : null;
            $status_end_date = !empty($_POST['status_end_date']) ? $_POST['status_end_date'] : null;
            $status_remark = $_POST['status_remark'] ?? null;

            $stmt_history = $db->prepare("INSERT INTO user_status_history (user_id, status, start_date, end_date, remark) VALUES (?, ?, ?, ?, ?)");
            $stmt_history->execute([$id, $state, $status_start_date, $status_end_date, $status_remark]);
        }


        $db->commit();
        $message = $mode === 'add' ? '員工新增成功。' : '員工更新成功。';
        echo json_encode(['status' => 'success', 'message' => $message]);

    } catch (PDOException $e) {
        $db->rollBack();
        echo json_encode(['status' => 'error', 'message' => '資料庫操作失敗: ' . $e->getMessage()]);
    }
}

function deleteEmployee() {
    global $db;
    $id = $_POST['id'] ?? null;

    if (empty($id)) {
        echo json_encode(['status' => 'error', 'message' => '未提供員工 ID。']);
        return;
    }

    // 為了安全，可以加上一些保護機制，例如不允許刪除系統管理員 (ID=1)
    if ($id == 1) {
        echo json_encode(['status' => 'error', 'message' => '無法刪除系統管理員。']);
        return;
    }

    try {
        $db->beginTransaction();

        // 1. 刪除職務對應表中的資料
        $stmt_map = $db->prepare("DELETE FROM user_department_position_map WHERE user_id = ?");
        $stmt_map->execute([$id]);

        // 2. 刪除使用者主表中的資料
        $stmt_user = $db->prepare("DELETE FROM user WHERE id = ?");
        $stmt_user->execute([$id]);

        $db->commit();
        echo json_encode(['status' => 'success', 'message' => '員工資料已成功刪除。']);

    } catch (PDOException $e) {
        $db->rollBack();
        echo json_encode(['status' => 'error', 'message' => '資料庫操作失敗: ' . $e->getMessage()]);
    }
}

function addOrUpdateEmployee_original($mode) {
    global $db;
    // 這裡可以加上更完整的資料驗證
    $id = $_POST['id'] ?? null;
    $user_uname = $_POST['user_uname'] ?? '';
    $user_cname = $_POST['user_cname'] ?? '';
    $user_status = $_POST['user_status'] ?? 1;

    if ($mode === 'update' && empty($id)) {
        echo json_encode(['status' => 'error', 'message' => '更新時需要提供員工 ID。']);
        return;
    }

    try {
        $db->beginTransaction();

        if ($mode === 'add') {
            $sql = "INSERT INTO user (user_uname, user_cname, phone, state) VALUES (?, ?, ?, ?, ?, ?)";
            $stmt = $db->prepare($sql);
            $stmt->execute([$user_uname, $user_cname, $_POST['phone'], $user_status == 1 ? 1 : 0]);
            $id = $db->lastInsertId();
        } else { // update
            $sql = "UPDATE user SET user_cname = ?, phone = ?, state = ? WHERE id = ?";
            $stmt = $db->prepare($sql);
            $stmt->execute([$user_cname, $_POST['phone'], $user_status == 1 ? 1 : 0, $id]);
        }

        // 更新職務對應
        // 1. 刪除舊的對應
        $db->prepare("DELETE FROM user_department_position_map WHERE user_id = ?")->execute([$id]);

        // 2. 新增主職務
        if (!empty($_POST['main_department_id']) && !empty($_POST['main_position_id'])) {
            $stmt_map = $db->prepare("INSERT INTO user_department_position_map (user_id, department_id, position_id, is_main) VALUES (?, ?, ?, 1)");
            $stmt_map->execute([$id, $_POST['main_department_id'], $_POST['main_position_id']]);
        }

        // 3. 新增兼任職務
        if (isset($_POST['concurrent']) && is_array($_POST['concurrent'])) {
            foreach ($_POST['concurrent'] as $concurrent_pos) {
                if (!empty($concurrent_pos['department_id']) && !empty($concurrent_pos['position_id'])) {
                    $stmt_map = $db->prepare("INSERT INTO user_department_position_map (user_id, department_id, position_id, is_main) VALUES (?, ?, ?, 0)");
                    $stmt_map->execute([$id, $concurrent_pos['department_id'], $concurrent_pos['position_id']]);
                }
            }
        }

        $db->commit();
        $message = $mode === 'add' ? '員工新增成功。' : '員工更新成功。';
        echo json_encode(['status' => 'success', 'message' => $message]);

    } catch (PDOException $e) {
        $db->rollBack();
        echo json_encode(['status' => 'error', 'message' => '資料庫操作失敗: ' . $e->getMessage()]);
    }
}

// --- 從 department_job_title_api.php 搬移過來的函式 ---

function getDepartments() {
    global $db;
    try {
        // 根據 sort_order 排序
        $sql = "SELECT id, name, level, parent_id FROM department ORDER BY sort_order ASC";
        $stmt = $db->query($sql);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'success', 'data' => $data]);
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => '讀取部門資料失敗: ' . $e->getMessage()]);
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
        // 透過 department_position 取得該部門下所有可用的職稱
        $sql = "SELECT p.id, p.name 
                FROM position p
                JOIN department_position dp ON p.id = dp.position_id
                WHERE dp.department_id = ?
                ORDER BY p.sort_order ASC, p.name ASC";
        $stmt = $db->prepare($sql);
        $stmt->execute([$department_id]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'success', 'data' => $data]);
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => '讀取綁定資料失敗: ' . $e->getMessage()]);
    }
}

/**
 * 獲取組織圖所需的資料結構
 * 回傳一個以部門為單位的陣列，每個部門包含其下的員工列表
 */
function getOrganizationData() {
    global $db;
    try {
        // 1. 獲取所有部門，並依照排序順序排列
        $sql_departments = "SELECT id, name, parent_id, level FROM department ORDER BY sort_order ASC";
        $stmt_departments = $db->query($sql_departments);
        $departments = $stmt_departments->fetchAll(PDO::FETCH_ASSOC);

        // 2. 準備 SQL 語句以獲取每個部門的員工
        // 只選擇在職 (state=1) 的員工
        $sql_employees = "SELECT 
                              u.user_cname, 
                              u.id as user_id,
                              u.gender,
                              u.hire_date,
                              p.name as position_name,
                              p.sort_order as position_sort_order,
                              udpm.is_main
                          FROM user_department_position_map udpm
                          JOIN user u ON udpm.user_id = u.id
                          JOIN position p ON udpm.position_id = p.id
                          WHERE udpm.department_id = ? AND u.state = 1
                          ORDER BY p.sort_order ASC, u.id ASC";
        $stmt_employees = $db->prepare($sql_employees);

        // 2.5 準備 SQL 語句以獲取每個部門所有可用的職稱
        $sql_all_positions = "SELECT 
                                  p.name as position_name,
                                  p.sort_order as position_sort_order
                              FROM department_position dp
                              JOIN position p ON dp.position_id = p.id
                              WHERE dp.department_id = ?
                              ORDER BY p.sort_order ASC";
        $stmt_all_positions = $db->prepare($sql_all_positions);

        // 3. 遍歷部門，將員工資料填充進去
        $organization_data = [];
        foreach ($departments as $dept) {
            $stmt_employees->execute([$dept['id']]);
            $employees = $stmt_employees->fetchAll(PDO::FETCH_ASSOC);
            $dept['employees'] = $employees;

            $stmt_all_positions->execute([$dept['id']]);
            $dept['all_positions'] = $stmt_all_positions->fetchAll(PDO::FETCH_ASSOC);
            $organization_data[] = $dept;
        }

        echo json_encode(['status' => 'success', 'data' => $organization_data]);
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => '讀取組織圖資料失敗: ' . $e->getMessage()]);
    }
}

?>