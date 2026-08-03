<?php
header('Content-Type: application/json; charset=utf-8');

// 引入設定與資料庫連線
$document_root = $_SERVER['DOCUMENT_ROOT'];
session_start();
include_once $document_root . '/EGsystem/src/common/_config.php';
include_once $document_root . '/EGsystem/src/common/DBConnection.php';
// 特休天數算法已抽為共用庫（請假系統額度也用同一套，避免兩邊數字不一致）
require_once $document_root . '/EGsystem/src/common/annual_leave_lib.php';
// 在職狀態封鎖／權限清除（離職、留職停薪、育嬰留停）
require_once $document_root . '/EGsystem/src/common/user_active_lib.php';
// 職務調動紀錄（ai-rules/14 P1：異動快照、依日期解析、補登）
require_once $document_root . '/EGsystem/src/common/position_history_lib.php';

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
    case 'get_permission_summary':   // 查此人殘留的權限設定（清除前給人事看）
        getPermissionSummary();
        break;
    case 'revoke_permissions':       // 一鍵清除此人所有權限設定（先寫 audit_log 再刪）
        revokePermissions();
        break;
    case 'get_change_history':       // 異動紀錄（職務調動＋在職狀態）
        getChangeHistory();
        break;
    case 'backfill_position_history': // 補登過去的職務異動（生效日可為過去；主職調動／兼任新增／兼任移除／兼任更動）
        backfillPositionHistory();
        break;
    case 'get_position_snapshot_at':  // 查某人在某日期之前的職務快照（補登表單用，供核對/自動算兼任異動基準）
        getPositionSnapshotAt();
        break;
    case 'delete_position_history':   // 刪除補登列（source='manual' 才可刪，系統自動寫的不可）
        deletePositionHistory();
        break;
    case 'backfill_status_history':   // 補登離職/復職/留停等在職狀態紀錄
        backfillStatusHistory();
        break;
    case 'delete_status_history':     // 刪除補登的在職狀態列（remark 帶 [補登 前綴者才可刪）
        deleteStatusHistory();
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
                    u.id, u.user_uname, u.user_cname, u.user_status, u.state, u.gender, u.hire_date, u.leave_date,
                    -- 預定離職：還在可用狀態、但已填未來離職日（含今天，當天仍可使用系統）
                    CASE WHEN u.leave_date IS NOT NULL AND u.leave_date >= CURDATE()
                              AND (u.state IS NULL OR u.state NOT IN (" . EG_BLOCKED_USER_STATES . "))
                         THEN u.leave_date END AS pending_leave_date,
                    DATEDIFF(u.leave_date, CURDATE()) AS pending_leave_days,
                    map1.department_id as main_department_id,
                    d.name as main_department_name,
                    p.name as main_position_name,
                    (SELECT GROUP_CONCAT(CONCAT(d2.name, ' / ', p2.name) SEPARATOR '; ')
                     FROM user_department_position_map map2
                     JOIN department d2 ON map2.department_id = d2.id
                     JOIN position p2 ON map2.position_id = p2.id WHERE map2.user_id = u.id AND map2.is_main = 0) as concurrent_positions,
                    -- 兼任部門 id 清單（供前端「兼任部門」篩選用，逗號分隔）
                    (SELECT GROUP_CONCAT(DISTINCT map3.department_id)
                     FROM user_department_position_map map3
                     WHERE map3.user_id = u.id AND map3.is_main = 0) as concurrent_department_ids,
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

            // 本次是否「從可用狀態」變成「被封鎖狀態（離職/留停）」——存檔後要提醒人事清權限
            $stmt_old = $db->prepare("SELECT state FROM user WHERE id = ?");
            $stmt_old->execute([$id]);
            $old_state = $stmt_old->fetchColumn();
            $turned_blocked = ($old_state === false ? false
                : (!in_array((int)$old_state, eg_blocked_state_list(), true)
                   && in_array((int)$state, eg_blocked_state_list(), true)));

            // 離職日（2026-07-30 起可預填）：
            //   離職狀態 → 實際離職日，可為過去或今天；
            //   其他狀態 → 視為「預定離職日」，只接受未來日期（當天仍可使用系統，隔天 0 點起自動封鎖）。
            //   填今天或更早卻不是離職狀態＝資料自相矛盾，直接擋下並說明原因，不要默默清成 NULL。
            $final_leave_date = $leave_date;
            if ($leave_date !== null && (int)$state !== 0 && $leave_date <= date('Y-m-d')) {
                $db->rollBack();
                echo json_encode(['status' => 'error',
                    'message' => '預定離職日必須是未來日期（' . $leave_date . ' 已過或就是今天）。'
                               . '若是復職，請把離職日清空；若此人確實已離職，請把在職狀態改為「離職」。'], JSON_UNESCAPED_UNICODE);
                return;
            }

            // 根據是否有提供密碼來決定是否更新密碼欄位
            if (!empty($user_password)) {
                // 共用帳號鎖密碼（ai-rules/13）：管理員這條路徑豁免可改，但必須留稽核紀錄
                require_once __DIR__ . '/../common/shared_account_lib.php';
                if (eg_shared_password_locked($db, (int)$id)) {
                    try {
                        $db->prepare("INSERT INTO audit_log (action_type, target_type, target_id, target_name, changes, user_id, operator, created_at)
                                      VALUES ('pw_change_locked', 'user', ?, ?, '管理員變更了已鎖定密碼的帳號密碼', ?, ?, NOW())")
                           ->execute([(string)$id, $user_cname, (int)($_SESSION['id'] ?? 0), ($_SESSION['user_cname'] ?? '')]);
                    } catch (Throwable $e) { error_log('[shared] locked pw audit failed: ' . $e->getMessage()); }
                }
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
        // P1（ai-rules/14）：改動 map 前先拍「異動前」快照，異動紀錄與職務變更同一交易
        $before_snap = ($mode === 'update') ? eg_position_snapshot_now($db, (int)$id) : [];
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

        // P1：職務有實質變動（部門:職稱:主兼 組合不同）→ 寫入 user_position_history＋audit_log（同一交易）
        if ($mode === 'update') {
            $after_snap = eg_position_snapshot_now($db, (int)$id);
            if (eg_position_snapshot_changed($before_snap, $after_snap)) {
                eg_position_history_write($db, (int)$id, eg_position_change_type($before_snap, $after_snap),
                    $before_snap, $after_snap, date('Y-m-d'), null, 'auto',
                    isset($_SESSION['id']) ? (int)$_SESSION['id'] : null, $_SESSION['user_cname'] ?? '');
            }
        }

        // 4. 在職狀態歷程（user_status_history）
        if ($mode === 'update') {
            $newSt = (int)$state;
            $oldSt = ($old_state === false) ? null : (int)$old_state;
            if (in_array($newSt, [2, 3], true)) {
                $status_start_date = !empty($_POST['status_start_date']) ? $_POST['status_start_date'] : null;
                $status_end_date = !empty($_POST['status_end_date']) ? $_POST['status_end_date'] : null;
                $status_remark = $_POST['status_remark'] ?? null;
                // 與最後一筆完全相同就不重複寫（留停期間每次存檔都插一筆會灌出重複列）
                $chk = $db->prepare("SELECT status, start_date, end_date, remark FROM user_status_history
                                     WHERE user_id = ? ORDER BY id DESC LIMIT 1");
                $chk->execute([$id]);
                $last = $chk->fetch(PDO::FETCH_ASSOC);
                if (!$last || (int)$last['status'] !== $newSt || $last['start_date'] !== $status_start_date
                    || $last['end_date'] !== $status_end_date || (string)$last['remark'] !== (string)$status_remark) {
                    $db->prepare("INSERT INTO user_status_history (user_id, status, start_date, end_date, remark) VALUES (?, ?, ?, ?, ?)")
                       ->execute([$id, $newSt, $status_start_date, $status_end_date, $status_remark]);
                }
            } elseif ($oldSt !== null && $oldSt !== $newSt && $newSt === 0) {
                // 人事手動設為離職也留一筆（原本只有「預定離職日到期系統自動轉離職」會寫）
                $db->prepare("INSERT INTO user_status_history (user_id, status, start_date, end_date, remark) VALUES (?, 0, ?, NULL, '人事設定離職')")
                   ->execute([$id, $final_leave_date ?: date('Y-m-d')]);
            } elseif ($oldSt !== null && in_array($oldSt, [0, 2, 3], true) && $newSt === 1) {
                // 復職／恢復在職也留一筆，時間軸才接得起來（日後補歷史資料要靠它）
                $db->prepare("INSERT INTO user_status_history (user_id, status, start_date, end_date, remark) VALUES (?, 1, ?, NULL, ?)")
                   ->execute([$id, date('Y-m-d'),
                              $oldSt === 0 ? '復職' : '恢復在職（原：' . ($oldSt === 2 ? '留職停薪' : '育嬰留停') . '）']);
            }
        }


        $db->commit();
        $message = $mode === 'add' ? '員工新增成功。' : '員工更新成功。';
        $resp = ['status' => 'success', 'message' => $message];

        // 改成離職/留停 → 權限已自動失效（判斷時擋住），但「殘留設定要不要真的刪掉」由人事決定
        if (!empty($turned_blocked)) {
            $snap  = eg_collect_user_permissions($db, (int)$id);
            $count = count($snap['user_roles']) + (empty($snap['user_permissions']) ? 0 : 1)
                   + count($snap['user_module_permissions']) + count($snap['page_operator_acl'])
                   + count($snap['user_delegate']);
            $resp['permission_notice'] = [
                'user_id'  => (int)$id,
                'state'    => (int)$state,
                'label'    => eg_user_state_label($state),
                'count'    => $count,
                'warnings' => eg_user_permission_warnings($db, (int)$id),
            ];
        }
        echo json_encode($resp, JSON_UNESCAPED_UNICODE);

    } catch (PDOException $e) {
        $db->rollBack();
        echo json_encode(['status' => 'error', 'message' => '資料庫操作失敗: ' . $e->getMessage()]);
    }
}

/**
 * 權限殘留摘要：離職/留停者身上還掛著哪些權限設定。
 * 判斷時已一律擋下（見 src/common/user_active_lib.php），這裡是給人事「真的把資料清掉」前先看的清單。
 */
function getPermissionSummary() {
    global $db;
    $id = (int)($_REQUEST['id'] ?? 0);
    if ($id <= 0) { echo json_encode(['status' => 'error', 'message' => '未提供員工 ID。']); return; }

    $snap  = eg_collect_user_permissions($db, $id);
    $items = [];
    if (!empty($snap['user_roles'])) {
        $names = [];
        foreach ($snap['user_roles'] as $r) $names[] = ($r['role_name'] ?? ('角色' . $r['role_id']));
        $items[] = ['label' => 'RBAC 角色', 'count' => count($snap['user_roles']), 'detail' => implode('、', $names)];
    }
    if (!empty($snap['user_permissions'])) {
        $items[] = ['label' => '舊版個人權限(user_permissions)', 'count' => 1, 'detail' => '整列設定'];
    }
    if (!empty($snap['user_module_permissions'])) {
        $codes = [];
        foreach ($snap['user_module_permissions'] as $r) $codes[] = $r['module_code'] . '(' . $r['permission'] . ')';
        $items[] = ['label' => '模組權限', 'count' => count($snap['user_module_permissions']), 'detail' => implode('、', $codes)];
    }
    if (!empty($snap['page_operator_acl'])) {
        $keys = [];
        foreach ($snap['page_operator_acl'] as $r) $keys[] = $r['page_key'];
        $items[] = ['label' => '頁面白名單', 'count' => count($snap['page_operator_acl']), 'detail' => implode('、', $keys)];
    }
    if (!empty($snap['user_delegate'])) {
        $items[] = ['label' => '生效中的代理設定', 'count' => count($snap['user_delegate']), 'detail' => '本人被代理或擔任他人代理（清除時只停用，不刪紀錄）'];
    }

    echo json_encode([
        'status'   => 'success',
        'items'    => $items,
        'warnings' => eg_user_permission_warnings($db, $id),
        'total'    => array_sum(array_column($items, 'count')),
    ], JSON_UNESCAPED_UNICODE);
}

/** 一鍵清除權限設定（清除前把原設定完整寫進 audit_log 備查） */
function revokePermissions() {
    global $db;
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) { echo json_encode(['status' => 'error', 'message' => '未提供員工 ID。']); return; }

    $reason = $_POST['reason'] ?? '在職狀態異動';
    $res = eg_revoke_user_permissions($db, $id, $reason,
                                      $_SESSION['id'] ?? null, $_SESSION['user_cname'] ?? '');
    echo json_encode([
        'status'   => $res['ok'] ? 'success' : 'error',
        'message'  => $res['message'],
        'deleted'  => $res['deleted'],
        'warnings' => $res['warnings'],
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * 異動紀錄查詢：職務調動（user_position_history）＋在職狀態（user_status_history）。
 * 快照在後端組成顯示字串（before_label/after_label），前端不用自己拼。
 */
function getChangeHistory() {
    global $db;
    $id = (int)($_REQUEST['id'] ?? 0);
    if ($id <= 0) { echo json_encode(['status' => 'error', 'message' => '未提供員工 ID。']); return; }
    try {
        $st = $db->prepare("SELECT id, change_type, before_json, after_json, effective_date, reason, source, operator, created_at
                            FROM user_position_history WHERE user_id = ? ORDER BY effective_date DESC, id DESC");
        $st->execute([$id]);
        $pos = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $r['before_label'] = eg_position_snapshot_label(eg_position_snap_decode($r['before_json']));
            $r['after_label']  = eg_position_snapshot_label(eg_position_snap_decode($r['after_json']));
            unset($r['before_json'], $r['after_json']);
            $pos[] = $r;
        }
        $st2 = $db->prepare("SELECT id, status, start_date, end_date, remark, created_at FROM user_status_history
                             WHERE user_id = ? ORDER BY COALESCE(start_date, '1000-01-01') DESC, id DESC");
        $st2->execute([$id]);
        $sta = [];
        foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $r) {
            // 補登列以 remark 前綴 [補登:操作者] 標記（此表加 source 欄需 ALTER，工具擋 DDL，待使用者核准後再轉正）
            $r['is_backfill'] = (strpos((string)$r['remark'], '[補登') === 0) ? 1 : 0;
            $sta[] = $r;
        }
        echo json_encode(['status' => 'success', 'position' => $pos, 'state' => $sta], JSON_UNESCAPED_UNICODE);
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => '查詢失敗: ' . $e->getMessage()]);
    }
}

/** 補登快照用：查部門/職稱名稱，組成一筆快照列（$isMain 決定主/兼）；查不到名稱回 null */
function _backfillSnapItem(PDO $db, int $depId, int $posId, int $isMain = 1): ?array {
    $d = $db->prepare("SELECT name FROM department WHERE id = ?");
    $d->execute([$depId]);
    $dn = $d->fetchColumn();
    $p = $db->prepare("SELECT name FROM position WHERE id = ?");
    $p->execute([$posId]);
    $pn = $p->fetchColumn();
    if ($dn === false || $pn === false) return null;
    return ['department_id' => $depId, 'department_name' => (string)$dn,
            'position_id' => $posId, 'position_name' => (string)$pn, 'is_main' => $isMain];
}

/** 補登基準快照：取「生效日前一天」依既有歷史紀錄解析出的職務（無紀錄則退回現況，與全站「當時職務」解析規則一致） */
function _backfillBaseSnapshot(PDO $db, int $userId, string $eff): array {
    $prevDate = date('Y-m-d', strtotime($eff . ' -1 day'));
    return eg_position_snapshot_at($db, $userId, $prevDate);
}

/** 從快照中移除指定部門/職稱那一筆，供補登兼任新增/移除/更動時重組快照用 */
function _snapWithoutItem(array $snap, int $depId, int $posId): array {
    return array_values(array_filter($snap, fn($s) => !($s['department_id'] === $depId && $s['position_id'] === $posId)));
}

/** 查某人在某日期之前的職務快照（補登表單開日期時的參考／核對用） */
function getPositionSnapshotAt() {
    global $db;
    $id = (int)($_REQUEST['id'] ?? 0);
    $eff = trim((string)($_REQUEST['effective_date'] ?? ''));
    if ($id <= 0) { echo json_encode(['status' => 'error', 'message' => '未提供員工 ID。']); return; }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $eff)) {
        echo json_encode(['status' => 'error', 'message' => '日期格式不正確（YYYY-MM-DD）。'], JSON_UNESCAPED_UNICODE); return;
    }
    $snap = _backfillBaseSnapshot($db, $id, $eff);
    echo json_encode(['status' => 'success', 'data' => $snap, 'label' => eg_position_snapshot_label($snap)], JSON_UNESCAPED_UNICODE);
}

/**
 * 補登過去的職務異動（生效日可為過去日期）。
 * 補了之後，教育訓練等頁依日期解析（eg_position_snapshot_at）才能抓到「當時」的部門職稱。
 * change_kind：transfer(主職調動) / concurrent_add(新增兼任) / concurrent_remove(移除兼任) / concurrent_change(更動兼任)。
 * 兼任三種一律以「生效日前一天」解析出的快照為基準，套用差異後存成完整快照（主職＋所有兼任），
 * 這樣主職調動也會把既有兼任職務原樣帶過去，不會因為補登主職異動而把兼任紀錄弄丟。
 * 兼任三種不檢查「基準快照裡有沒有這筆」就擋下——補登本來就是要覆蓋系統對過去日期的預設猜測
 * （無紀錄時 eg_position_snapshot_at 會回現況，若現況剛好已有/已無這筆，會被誤判成「重複/找不到」）；
 * 一律強制寫成使用者指定的「異動前/異動後」狀態即可，不論基準快照原本是什麼。
 */
function backfillPositionHistory() {
    global $db;
    $id = (int)($_POST['id'] ?? 0);
    $kind = trim((string)($_POST['change_kind'] ?? 'transfer'));
    $eff = trim((string)($_POST['effective_date'] ?? ''));
    $reason = trim((string)($_POST['reason'] ?? ''));
    if ($id <= 0) { echo json_encode(['status' => 'error', 'message' => '未提供員工 ID。']); return; }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $eff)) {
        echo json_encode(['status' => 'error', 'message' => '生效日格式不正確（YYYY-MM-DD）。'], JSON_UNESCAPED_UNICODE); return;
    }
    [$y, $m, $d] = array_map('intval', explode('-', $eff));
    if (!checkdate($m, $d, $y)) { echo json_encode(['status' => 'error', 'message' => '生效日不存在（' . $eff . '）。'], JSON_UNESCAPED_UNICODE); return; }

    $before = []; $after = [];

    if ($kind === 'transfer') {
        $aDep = (int)($_POST['after_department_id'] ?? 0);
        $aPos = (int)($_POST['after_position_id'] ?? 0);
        if ($aDep <= 0 || $aPos <= 0) {
            echo json_encode(['status' => 'error', 'message' => '請選擇「主職異動後」的部門與職稱。'], JSON_UNESCAPED_UNICODE); return;
        }
        $afterMain = _backfillSnapItem($db, $aDep, $aPos, 1);
        if ($afterMain === null) { echo json_encode(['status' => 'error', 'message' => '異動後的部門或職稱不存在。'], JSON_UNESCAPED_UNICODE); return; }

        $bDep = (int)($_POST['before_department_id'] ?? 0);
        $bPos = (int)($_POST['before_position_id'] ?? 0);
        if ($bDep > 0 || $bPos > 0) {
            if ($bDep <= 0 || $bPos <= 0) {
                echo json_encode(['status' => 'error', 'message' => '「主職異動前」的部門與職稱請兩個都選，或兩個都留空（＝之前無紀錄）。'], JSON_UNESCAPED_UNICODE); return;
            }
            $beforeMain = _backfillSnapItem($db, $bDep, $bPos, 1);
            if ($beforeMain === null) { echo json_encode(['status' => 'error', 'message' => '異動前的部門或職稱不存在。'], JSON_UNESCAPED_UNICODE); return; }
            // 主職異動前有填才帶入基準快照裡「當時的兼任職務」一起保留；完全留空＝之前無任何紀錄，維持舊行為
            $concurrentOnly = array_values(array_filter(_backfillBaseSnapshot($db, $id, $eff), fn($s) => !$s['is_main']));
            $before = array_merge([$beforeMain], $concurrentOnly);
        }
        $concurrentOnly = array_values(array_filter(_backfillBaseSnapshot($db, $id, $eff), fn($s) => !$s['is_main']));
        $after = array_merge([$afterMain], $concurrentOnly);

    } elseif ($kind === 'concurrent_add') {
        $aDep = (int)($_POST['add_department_id'] ?? 0);
        $aPos = (int)($_POST['add_position_id'] ?? 0);
        if ($aDep <= 0 || $aPos <= 0) { echo json_encode(['status' => 'error', 'message' => '請選擇要新增的兼任職務。'], JSON_UNESCAPED_UNICODE); return; }
        $item = _backfillSnapItem($db, $aDep, $aPos, 0);
        if ($item === null) { echo json_encode(['status' => 'error', 'message' => '部門或職稱不存在。'], JSON_UNESCAPED_UNICODE); return; }
        // 補登的意義就是「系統原本不知道這件事」：不論目前依現有紀錄解析出的快照有沒有這筆，
        // 一律強制設成「異動前無、異動後有」，藉此覆蓋掉系統對過去日期的預設猜測（無紀錄一律回現況）
        $rest = _snapWithoutItem(_backfillBaseSnapshot($db, $id, $eff), $aDep, $aPos);
        $before = $rest;
        $after = array_merge($rest, [$item]);

    } elseif ($kind === 'concurrent_remove') {
        $rDep = (int)($_POST['remove_department_id'] ?? 0);
        $rPos = (int)($_POST['remove_position_id'] ?? 0);
        if ($rDep <= 0 || $rPos <= 0) { echo json_encode(['status' => 'error', 'message' => '請選擇要移除的兼任職務。'], JSON_UNESCAPED_UNICODE); return; }
        $item = _backfillSnapItem($db, $rDep, $rPos, 0);
        if ($item === null) { echo json_encode(['status' => 'error', 'message' => '部門或職稱不存在。'], JSON_UNESCAPED_UNICODE); return; }
        $rest = _snapWithoutItem(_backfillBaseSnapshot($db, $id, $eff), $rDep, $rPos);
        $before = array_merge($rest, [$item]);
        $after = $rest;

    } elseif ($kind === 'concurrent_change') {
        $fDep = (int)($_POST['from_department_id'] ?? 0);
        $fPos = (int)($_POST['from_position_id'] ?? 0);
        $tDep = (int)($_POST['to_department_id'] ?? 0);
        $tPos = (int)($_POST['to_position_id'] ?? 0);
        if ($fDep <= 0 || $fPos <= 0) { echo json_encode(['status' => 'error', 'message' => '請選擇「原本的兼任職務」。'], JSON_UNESCAPED_UNICODE); return; }
        if ($tDep <= 0 || $tPos <= 0) { echo json_encode(['status' => 'error', 'message' => '請選擇「更動後的兼任職務」。'], JSON_UNESCAPED_UNICODE); return; }
        if ($fDep === $tDep && $fPos === $tPos) { echo json_encode(['status' => 'error', 'message' => '更動前後的職務相同，沒有實質變動。'], JSON_UNESCAPED_UNICODE); return; }
        $fromItem = _backfillSnapItem($db, $fDep, $fPos, 0);
        $toItem = _backfillSnapItem($db, $tDep, $tPos, 0);
        if ($fromItem === null || $toItem === null) { echo json_encode(['status' => 'error', 'message' => '部門或職稱不存在。'], JSON_UNESCAPED_UNICODE); return; }
        $rest = _snapWithoutItem(_snapWithoutItem(_backfillBaseSnapshot($db, $id, $eff), $fDep, $fPos), $tDep, $tPos);
        $before = array_merge($rest, [$fromItem]);
        $after = array_merge($rest, [$toItem]);
    } else {
        echo json_encode(['status' => 'error', 'message' => '未知的異動類型。'], JSON_UNESCAPED_UNICODE); return;
    }

    if (!eg_position_snapshot_changed($before, $after)) {
        echo json_encode(['status' => 'error', 'message' => '異動前後沒有差異，未寫入。'], JSON_UNESCAPED_UNICODE); return;
    }
    $changeType = eg_position_change_type($before, $after);

    try {
        $db->beginTransaction();
        $hid = eg_position_history_write($db, $id, $changeType, $before, $after, $eff,
            $reason !== '' ? $reason : '人事補登', 'manual',
            isset($_SESSION['id']) ? (int)$_SESSION['id'] : null, $_SESSION['user_cname'] ?? '');
        $db->commit();
        echo json_encode(['status' => 'success', 'message' => '已補登職務異動。', 'id' => $hid], JSON_UNESCAPED_UNICODE);
    } catch (PDOException $e) {
        $db->rollBack();
        echo json_encode(['status' => 'error', 'message' => '補登失敗: ' . $e->getMessage()]);
    }
}

/** 刪除補登的職務異動列（source='manual' 限定；系統異動當下自動寫的不可刪，刪除前寫 audit_log） */
function deletePositionHistory() {
    global $db;
    $hid = (int)($_POST['hist_id'] ?? 0);
    if ($hid <= 0) { echo json_encode(['status' => 'error', 'message' => '未提供紀錄 ID。']); return; }
    try {
        $st = $db->prepare("SELECT user_id, change_type, effective_date, source, before_json, after_json FROM user_position_history WHERE id = ?");
        $st->execute([$hid]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) { echo json_encode(['status' => 'error', 'message' => '找不到該筆紀錄。'], JSON_UNESCAPED_UNICODE); return; }
        // 系統自動寫入的紀錄原則上不可刪（稽核軌跡）；僅超級管理員(id=1)可強制刪除以清理測試/錯誤資料，並照樣留稽核紀錄
        $isSuperAdmin = isset($_SESSION['id']) && (int)$_SESSION['id'] === 1;
        if ($row['source'] !== 'manual' && !$isSuperAdmin) {
            echo json_encode(['status' => 'error', 'message' => '系統自動寫入的異動紀錄不可刪除（稽核軌跡）。'], JSON_UNESCAPED_UNICODE); return;
        }
        $db->beginTransaction();
        $db->prepare("INSERT INTO audit_log (action_type, target_type, target_id, target_name, changes, user_id, operator, created_at)
                      VALUES ('POSITION_CHANGE_DEL', 'user', ?, '', ?, ?, ?, NOW())")
           ->execute([(string)$row['user_id'],
                      json_encode(['deleted_hist_id' => $hid, 'effective_date' => $row['effective_date'], 'source' => $row['source'],
                                   'force_by_super_admin' => ($row['source'] !== 'manual' && $isSuperAdmin),
                                   'before' => $row['before_json'], 'after' => $row['after_json']], JSON_UNESCAPED_UNICODE),
                      isset($_SESSION['id']) ? (int)$_SESSION['id'] : null, $_SESSION['user_cname'] ?? '']);
        $db->prepare("DELETE FROM user_position_history WHERE id = ?")->execute([$hid]);
        $db->commit();
        echo json_encode(['status' => 'success', 'message' => '已刪除補登紀錄。'], JSON_UNESCAPED_UNICODE);
    } catch (PDOException $e) {
        $db->rollBack();
        echo json_encode(['status' => 'error', 'message' => '刪除失敗: ' . $e->getMessage()]);
    }
}

/** 補登在職狀態紀錄（離職/復職/留停/育嬰；remark 加 [補登:操作者] 前綴以便辨識與刪除） */
function backfillStatusHistory() {
    global $db;
    $id = (int)($_POST['id'] ?? 0);
    $status = (string)($_POST['status'] ?? '');
    $start = trim((string)($_POST['start_date'] ?? ''));
    $end = trim((string)($_POST['end_date'] ?? ''));
    $remark = trim((string)($_POST['remark'] ?? ''));
    if ($id <= 0) { echo json_encode(['status' => 'error', 'message' => '未提供員工 ID。']); return; }
    if (!in_array($status, ['0', '1', '2', '3'], true)) {
        echo json_encode(['status' => 'error', 'message' => '狀態不正確（離職/在職/留職停薪/育嬰留停）。'], JSON_UNESCAPED_UNICODE); return;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
        echo json_encode(['status' => 'error', 'message' => '開始日格式不正確（YYYY-MM-DD）。'], JSON_UNESCAPED_UNICODE); return;
    }
    if ($end !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end) || $end < $start)) {
        echo json_encode(['status' => 'error', 'message' => '結束日格式不正確，或早於開始日。'], JSON_UNESCAPED_UNICODE); return;
    }
    try {
        $op = (string)($_SESSION['user_cname'] ?? '');
        $db->beginTransaction();
        $db->prepare("INSERT INTO user_status_history (user_id, status, start_date, end_date, remark) VALUES (?, ?, ?, ?, ?)")
           ->execute([$id, (int)$status, $start, $end !== '' ? $end : null,
                      '[補登:' . $op . ']' . ($remark !== '' ? ' ' . $remark : '')]);
        $hid = (int)$db->lastInsertId();
        $db->prepare("INSERT INTO audit_log (action_type, target_type, target_id, target_name, changes, user_id, operator, created_at)
                      VALUES ('STATUS_BACKFILL', 'user', ?, '', ?, ?, ?, NOW())")
           ->execute([(string)$id,
                      json_encode(['hist_id' => $hid, 'status' => (int)$status, 'start' => $start, 'end' => $end, 'remark' => $remark], JSON_UNESCAPED_UNICODE),
                      isset($_SESSION['id']) ? (int)$_SESSION['id'] : null, $op]);
        $db->commit();
        echo json_encode(['status' => 'success', 'message' => '已補登在職狀態紀錄。', 'id' => $hid], JSON_UNESCAPED_UNICODE);
    } catch (PDOException $e) {
        $db->rollBack();
        echo json_encode(['status' => 'error', 'message' => '補登失敗: ' . $e->getMessage()]);
    }
}

/** 刪除補登的在職狀態列（remark 前綴 [補登 者限定；系統寫入的不可刪） */
function deleteStatusHistory() {
    global $db;
    $hid = (int)($_POST['hist_id'] ?? 0);
    if ($hid <= 0) { echo json_encode(['status' => 'error', 'message' => '未提供紀錄 ID。']); return; }
    try {
        $st = $db->prepare("SELECT user_id, status, start_date, end_date, remark FROM user_status_history WHERE id = ?");
        $st->execute([$hid]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) { echo json_encode(['status' => 'error', 'message' => '找不到該筆紀錄。'], JSON_UNESCAPED_UNICODE); return; }
        // 系統自動寫入的紀錄原則上不可刪（稽核軌跡）；僅超級管理員(id=1)可強制刪除以清理測試/錯誤資料，並照樣留稽核紀錄
        $isSuperAdmin = isset($_SESSION['id']) && (int)$_SESSION['id'] === 1;
        $isBackfill = strpos((string)$row['remark'], '[補登') === 0;
        if (!$isBackfill && !$isSuperAdmin) {
            echo json_encode(['status' => 'error', 'message' => '系統自動寫入的狀態紀錄不可刪除（稽核軌跡）。'], JSON_UNESCAPED_UNICODE); return;
        }
        $db->beginTransaction();
        $db->prepare("INSERT INTO audit_log (action_type, target_type, target_id, target_name, changes, user_id, operator, created_at)
                      VALUES ('STATUS_BACKFILL_DEL', 'user', ?, '', ?, ?, ?, NOW())")
           ->execute([(string)$row['user_id'],
                      json_encode($row + ['deleted_hist_id' => $hid, 'force_by_super_admin' => (!$isBackfill && $isSuperAdmin)], JSON_UNESCAPED_UNICODE),
                      isset($_SESSION['id']) ? (int)$_SESSION['id'] : null, $_SESSION['user_cname'] ?? '']);
        $db->prepare("DELETE FROM user_status_history WHERE id = ?")->execute([$hid]);
        $db->commit();
        echo json_encode(['status' => 'success', 'message' => '已刪除補登紀錄。'], JSON_UNESCAPED_UNICODE);
    } catch (PDOException $e) {
        $db->rollBack();
        echo json_encode(['status' => 'error', 'message' => '刪除失敗: ' . $e->getMessage()]);
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