<?php
header('Content-Type: application/json; charset=utf-8');

// 引入設定與資料庫連線
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
 * @param string|null $hireDate 到職日 (Y-m-d)
 * @return float 計算後的天數
 */
function calculateProratedAnnualLeave($hireDate) {
    if (!$hireDate) {
        return 0;
    }

    $currentYear = date('Y');
    $hire_date_obj = new DateTime($hireDate);
    $year_start_obj = new DateTime("$currentYear-01-01");
    $year_end_obj = new DateTime("$currentYear-12-31");
    $days_in_year = $year_start_obj->diff($year_end_obj)->days + 1;

    // 週年日
    $anniversary_date_this_year = new DateTime($hire_date_obj->format("$currentYear-m-d"));

    // 取得對應年資的特休天數
    $get_leave_days = function($years, $months) {
        // 規則：滿10年16天，之後每年+1，上限30天
        if ($years >= 10) return min(30, 16 + ($years - 10));
        // 規則：根據範例，滿5年為15天。此處假設5-9年皆為15天
        if ($years >= 5) return 15; 
        // 規則：滿3, 4年為14天
        if ($years >= 3) return 14; 
        if ($years >= 2) return 10;
        if ($years >= 1) return 7;
        // 規則：僅在未滿一年但滿6個月時適用
        if ($years == 0 && $months >= 6) return 3; 
        return 0;
    };

    // 1. 計算前期年資 (年初時的年資)
    $interval_at_year_start = $hire_date_obj->diff($year_start_obj);
    $seniority_years_before = $interval_at_year_start->y;
    $seniority_months_before = $interval_at_year_start->y * 12 + $interval_at_year_start->m;
    $leave_days_before = $get_leave_days($seniority_years_before, $seniority_months_before);

    // --- 特殊情況：在計算年度內才滿6個月 ---
    $six_month_anniversary = (clone $hire_date_obj)->add(new DateInterval('P6M'));
    if ($seniority_months_before < 6 && $six_month_anniversary->format('Y') == $currentYear) {
        $leave_days = 0;
        
        // 1. 滿6個月的3天假，直接給予
        $leave_days += 3;

        // 2. 計算滿一年後的按比例天數
        $one_year_anniversary = (clone $hire_date_obj)->add(new DateInterval('P1Y'));
        if ($one_year_anniversary->format('Y') == $currentYear) {
            $days_after_one_year = $one_year_anniversary->diff($year_end_obj)->days + 1;
            $pro_rated_after_one_year = (7 / $days_in_year) * $days_after_one_year;
            $leave_days += $pro_rated_after_one_year;
        }

        $total_leave_days = $leave_days;
    } else {
        // --- 正常情況：年初已滿6個月或更久 ---
    // 2. 計算後期年資 (週年日時的年資)
    $seniority_at_anniversary = $hire_date_obj->diff($anniversary_date_this_year)->y;
    $leave_days_after = $get_leave_days($seniority_at_anniversary, 12); // 週年日當天必滿12個月

    $total_leave_days = 0;

    if ($anniversary_date_this_year > $year_start_obj && $anniversary_date_this_year <= $year_end_obj) {
        // 週年日在今年
        $days_before_anniversary = $year_start_obj->diff($anniversary_date_this_year)->days;
        $days_after_anniversary = $days_in_year - $days_before_anniversary;

        // 判斷前期的計算基數：滿6個月的3天特休，其基數為半年(182.5天)
        $proration_base_before = ($leave_days_before == 3) ? 182.5 : $days_in_year;
        // 後期的計算基數恆為一整年
        $proration_base_after = $days_in_year;

        $pro_rated_before = ($leave_days_before / $proration_base_before) * $days_before_anniversary;
        $pro_rated_after = ($leave_days_after / $proration_base_after) * $days_after_anniversary;
        
        $total_leave_days = $pro_rated_before + $pro_rated_after;
    } else {
        // 週年日不在今年 (例如 2/29)，直接用年初年資計算整年
        $total_leave_days = $leave_days_before;
    }
    }

    // --- 根據年資套用不同的進位規則 ---

    // 判斷是否為 "到職滿一年的年度"
    $is_first_anniversary_year = ($seniority_years_before == 0 && $hire_date_obj->format('Y') < $currentYear);

    if ($is_first_anniversary_year) {
        // 規則B: 到職滿一年的特例，無條件進位 (e.g., 6.52 -> 7)
        return ceil($total_leave_days);
    } else {
        // 規則A: 正常情況，半天進位 (e.g., 14.17 -> 14.5)
        $floor_val = floor($total_leave_days);
        $decimal_part = $total_leave_days - $floor_val;

        if ($decimal_part > 0.5) {
            return ceil($total_leave_days);
        } elseif ($decimal_part > 0) {
            return $floor_val + 0.5;
        }
        return $total_leave_days;
    }
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