<?php
include_once '../../src/common/_config.php';
include "../../src/common/DBConnection.php";

// 檢查是否已登入 (處理 Session Timeout)
// 確保 Session 已啟動且檢查 user_id 或 id (相容舊系統)
if (!isset($_SESSION['user_id']) && !isset($_SESSION['id'])) {
    // 判斷是否為 AJAX 請求 (包含 jQuery $.post 和 fetch)
    $is_ajax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest')
        || isset($_POST['action'])
        || isset($_POST['machine_id'])
        || isset($_POST['order']);

    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => '連線逾時，請重新登入', 'timeout' => true, 'redirect' => '../../index.php']);
        exit;
    } else {
        // 一般頁面請求，直接導向登入頁
        echo "<script>alert('連線逾時，請重新登入'); window.location.href='../../index.php';</script>";
        exit;
    }
}

// 機台顯示名稱：一律用「現場編號(field_no)」，未填才退回機台編號/機型，皆未填才用機台名稱
// （與前端 machineOptionLabel() 同一套規則，避免同一台機台在儀表板與下拉選單顯示不同名稱）
function eg_machine_disp_name($m) {
    if (isset($m['field_no']) && trim((string)$m['field_no']) !== '') return trim($m['field_no']);
    $parts = array_filter([$m['asset_no'] ?? '', $m['machine_model'] ?? ''], function($v) { return trim((string)$v) !== ''; });
    if (!empty($parts)) return implode(' ', array_map('trim', $parts));
    return (string)($m['machine'] ?? '');
}

// =================================================================================
// 後端邏輯：儲存製程設定 (AJAX from Modal)
// =================================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_process_settings') {
    header('Content-Type: application/json');
    $db_conn_ajax = new DBConnection();
    $pdo = $db_conn_ajax->getPDO();
    $response = ['success' => false, 'message' => ''];
    $user = $_SESSION['id'] ?? 'system';

    try {
        $sub_action = $_POST['sub_action'] ?? '';

        if ($sub_action === 'save_tabs') {
            $visible_tabs = $_POST['visible_process_types'] ?? [];
            $json_value = json_encode($visible_tabs);
            $sql = "INSERT INTO system_parameters (param_group, param_key, param_value, description, updated_by, updated_at) 
                    VALUES ('PROCESS_SCHEDULE', 'visible_tabs', ?, '製程看板顯示的分頁', ?, NOW()) 
                    ON DUPLICATE KEY UPDATE param_value = VALUES(param_value), updated_by = VALUES(updated_by), updated_at = NOW()";
            $pdo->prepare($sql)->execute([$json_value, $user]);
            $response['message'] = '分頁顯示已儲存。';

} elseif ($sub_action === 'add_type') {
            // 這裡改成接收 new_type_id
            $new_id = trim($_POST['new_type_id'] ?? ''); 
            $process_type = trim($_POST['process_type'] ?? '');

            // 1. 嚴格檢查：ID 絕對不能為空
            if ($new_id === '') {
                throw new Exception("請務必輸入「製程分類ID」，不可為空！");
            }
            
            // 2. 嚴格檢查：分類名稱也不能為空
            if ($process_type === '') {
                throw new Exception("請輸入「製程分類名稱」！");
            }

            // 3. 專業級防呆：檢查 ID 是否已經被用過了 (避免 SQL 發生 Duplicate entry 錯誤)
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM process_type WHERE process_type_id = ?");
            $stmt->execute([$new_id]);
            if ($stmt->fetchColumn() > 0) {
                throw new Exception("哎呀！這個製程分類ID ( {$new_id} ) 已經存在囉，請確認是否輸入錯誤。");
            }

            // 4. 確認無誤，寫入資料庫
            $pdo->prepare("INSERT INTO process_type (process_type_id, process_type) VALUES (?, ?)")
                ->execute([$new_id, $process_type]);
                
            $response['message'] = '製程分類已成功新增。';
        } elseif ($sub_action === 'edit_type') {
            $pdo->prepare("UPDATE process_type SET process_type = ? WHERE process_type_id = ?")->execute([$_POST['process_type'], $_POST['process_type_id']]);
            $response['message'] = '製程分類已更新。';
        } elseif ($sub_action === 'delete_type') {
            // 檢查是否仍有製程使用此分類
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM process_no WHERE process_type_id = ?");
            $stmt->execute([$_POST['process_type_id']]);
            if ($stmt->fetchColumn() > 0) {
                throw new Exception("無法刪除，尚有製程屬於此分類。");
            }
            $pdo->prepare("DELETE FROM process_type WHERE process_type_id = ?")->execute([$_POST['process_type_id']]);
            $response['message'] = '製程分類已刪除。';
        } elseif ($sub_action === 'add_process') {
            $pdo->prepare("INSERT INTO process_no (ProcessNo, ProcessName, process_type_id) VALUES (?, ?, ?)")->execute([$_POST['ProcessNo'], $_POST['ProcessName'], $_POST['process_type_id']]);
            $response['message'] = '製程已新增。';
        } elseif ($sub_action === 'edit_process') {
            $pdo->prepare("UPDATE process_no SET ProcessName = ?, process_type_id = ? WHERE ProcessNo = ?")->execute([$_POST['ProcessName'], $_POST['process_type_id'], $_POST['ProcessNo']]);
            $response['message'] = '製程已更新。';
        } elseif ($sub_action === 'delete_process') {
            // 這裡可以加入檢查，例如是否在 bom_ing 中被使用
            $pdo->prepare("DELETE FROM process_no WHERE ProcessNo = ?")->execute([$_POST['ProcessNo']]);
            $response['message'] = '製程已刪除。';
        } else {
            throw new Exception("未知的操作");
        }

        $response['success'] = true;
    } catch (Exception $e) {
        $response['message'] = '操作失敗: ' . $e->getMessage();
    }
    echo json_encode($response);
    exit;
}

// =================================================================================
// 後端邏輯：獲取全廠看板資料 (Dashboard Data)
// =================================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_dashboard_data') {
    header('Content-Type: application/json');
    try {
        // Add DB connection inside the handler
        $db_conn_dashboard = new DBConnection();
        $pdo = $db_conn_dashboard->getPDO();

        // 1. Get all machines (active)
        $machines = $pdo->query("
            SELECT m.*, t.process_type AS machine_type
            FROM machine_list m 
            LEFT JOIN process_type t ON m.machine_type_id = t.process_type_id 
            WHERE (m.state IS NULL OR m.state != '1') AND m.position != '' AND m.position != '0'
            ORDER BY m.position, m.machine_type_id, m.machine_id ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
        
        $machines_map = [];
        foreach ($machines as $m) {
            $machines_map[$m['machine_id']] = $m;
        }

        // 2. Get ALL active tasks (ing) - Assigned AND Unassigned
        $sql_tasks = "
            SELECT bi.machine_id, bi.bom, bi.bom_ing_fid, b.d_id, bi.sqty, b.Client_Name, pn.ProcessName, pn.process_type_id,
            ds.Type as part_type, ds.d_id as ds_id, origin_bi.processing_sequence
            FROM vw_bom_ing bi 
            JOIN bom_ing origin_bi ON bi.bom_ing_fid = origin_bi.bom_ing_fid
            LEFT JOIN bom b ON bi.bom = b.bom
            LEFT JOIN process_no pn ON bi.process_no = pn.ProcessNo
            LEFT JOIN maker_list ml ON bi.maker_id_no = ml.maker_id_no
            LEFT JOIN (
                SELECT D_Setting_Id, MAX(d_id) as max_did 
                FROM d_setting 
                GROUP BY D_Setting_Id
            ) ds_max ON b.d_id = ds_max.D_Setting_Id
            LEFT JOIN d_setting ds ON ds.d_id = ds_max.max_did
            WHERE bi.processing_state = 'ing'
              AND (b.processing_state <> 1 OR b.processing_state IS NULL) AND
              ml.internal = 1 AND
              NOT EXISTS (
                  SELECT 1 FROM pm_process_daily_report r
                  WHERE r.bom_ing_fid = bi.bom_ing_fid AND r.is_finished = 1
              )
            ORDER BY bi.machine_id ASC, CASE WHEN origin_bi.processing_sequence IS NULL OR origin_bi.processing_sequence = 0 THEN 999999 ELSE origin_bi.processing_sequence END ASC
        ";
        $all_tasks = $pdo->query($sql_tasks)->fetchAll(PDO::FETCH_ASSOC);
        
        // Optimization: Fetch stats in batch
        $fids = array_column($all_tasks, 'bom_ing_fid');
        $task_stats = [];
        if (!empty($fids)) {
            $in_fids = implode(',', array_map('intval', $fids));
            $sql_stats = "
                SELECT bom_ing_fid, 
                       SUM(produced_qty) as total_ok, 
                       MIN(COALESCE(setup_start_time, production_start_time)) as start_time 
                FROM pm_process_daily_report 
                WHERE bom_ing_fid IN ($in_fids) 
                GROUP BY bom_ing_fid
            ";
            $stats_rows = $pdo->query($sql_stats)->fetchAll(PDO::FETCH_ASSOC);
            foreach ($stats_rows as $r) $task_stats[$r['bom_ing_fid']] = $r;
        }

        $active_tasks_map = []; // machine_id -> task
        $waiting_tasks_by_type = []; // type_id -> [tasks]

        // 收集齒輪資料 (針對 Type=G 的工件)
        $gear_ds_ids = [];
        foreach ($all_tasks as $t) {
            if (!empty($t['ds_id'])) {
                $gear_ds_ids[] = $t['ds_id'];
            }
        }
        $gears_map = [];
        if (!empty($gear_ds_ids)) {
            $in_ids = implode(',', array_unique($gear_ds_ids));
            $gears = $pdo->query("SELECT * FROM d_setting_gear WHERE d_setting_id IN ($in_ids)")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($gears as $g) {
                $gears_map[$g['d_setting_id']][] = $g;
            }
        }

        foreach ($all_tasks as $t) {
            $mid = $t['machine_id'];
            $type_id = $t['process_type_id'];
            
            // Merge stats
            $stat = $task_stats[$t['bom_ing_fid']] ?? ['total_ok' => 0, 'start_time' => null];
            $t['total_ok'] = $stat['total_ok'];
            $t['start_time'] = $stat['start_time'];

            // 附加齒輪資料
            $t['gear_info'] = [];
            if (isset($gears_map[$t['ds_id']])) {
                $t['gear_info'] = $gears_map[$t['ds_id']];
            }

            // Check if assigned to a valid active machine
            if (!empty($mid) && isset($machines_map[$mid])) {
                // Assign to machine (only first one per machine if we want to show only current task)
                if (!isset($active_tasks_map[$mid])) {
                    $active_tasks_map[$mid] = $t;
                }
            } else {
                // Unassigned or assigned to inactive machine
                if (!isset($waiting_tasks_by_type[$type_id])) {
                    $waiting_tasks_by_type[$type_id] = [];
                }
                $waiting_tasks_by_type[$type_id][] = $t;
            }
        }

        // 3. Get open abnormalities
        $abnormalities_data = $pdo->query("
            SELECT m.machine_id, m.abnormal_start_time, m.abnormal_desc, t.abnormal_name 
            FROM pm_machine_abnormal m
            LEFT JOIN abnormal_type t ON m.abnormal_type_id = t.abnormal_type_id
            WHERE m.handle_status != 'CLOSED'
        ")->fetchAll(PDO::FETCH_ASSOC);
        $abnormal_map = [];
        foreach ($abnormalities_data as $ab) {
            $abnormal_map[$ab['machine_id']] = $ab;
        }

        // 4. Check Setup Status for active tasks (Get latest report for active FIDs)
        $fids = array_column($active_tasks_map, 'bom_ing_fid');
        $setup_status = [];
        if (!empty($fids)) {
            $in_fids = implode(',', array_map('intval', $fids));
            $sql_reports = "SELECT r.bom_ing_fid, r.setup_start_time, r.setup_end_time FROM pm_process_daily_report r INNER JOIN (SELECT MAX(report_id) as max_id FROM pm_process_daily_report WHERE bom_ing_fid IN ($in_fids) GROUP BY bom_ing_fid) latest ON r.report_id = latest.max_id";
            $reports = $pdo->query($sql_reports)->fetchAll(PDO::FETCH_ASSOC);
            foreach ($reports as $r) {
                if (!empty($r['setup_start_time']) && empty($r['setup_end_time'])) {
                    $setup_status[$r['bom_ing_fid']] = true;
                }
            }
        }

        // 5. Process Waiting Tasks Data
        $waiting_data = [];
        $machine_types = $pdo->query("SELECT process_type_id as machine_type_id, process_type as machine_type FROM process_type")->fetchAll(PDO::FETCH_KEY_PAIR);
        
        foreach ($waiting_tasks_by_type as $tid => $tasks) {
            $typeName = $machine_types[$tid] ?? '其他';
            $list = [];
            foreach ($tasks as $task) {
                $gear_str = '';
                if (!empty($task['gear_info'])) {
                    $g = $task['gear_info'][0];
                    $m = (float)$g['Module'];
                    $t = (float)$g['Teeth'];
                    $pa = (float)$g['Pressure_Angle'];
                    $w = (float)$g['Face_Width'];
                    $l = (float)$g['Workpiece_Length'];
                    $gear_str = " M$m T$t PA$pa W$w L$l";
                }
                $list[] = $task['bom'] . ' ' . $task['d_id'] . ' (' . $task['sqty'] . ')' . $gear_str;
            }
            $waiting_data[$typeName] = [
                'cnt' => count($tasks),
                'list' => implode('<br>', $list)
            ];
        }

        // 7. Get gear display settings
        $stmt_gear = $pdo->prepare("SELECT param_value FROM system_parameters WHERE param_group = 'BOM_SETTING' AND param_key = 'gear_info_display_types'");
        $stmt_gear->execute();
        $row_gear = $stmt_gear->fetch(PDO::FETCH_ASSOC);
        $gear_settings = $row_gear ? json_decode($row_gear['param_value'], true) : [];

        // 6. Build structure
        $result = [];
        foreach ($machines as $m) {
            $mid = $m['machine_id'];
            $pos = $m['position'];
            $type = $m['machine_type'] ?: '其他';
            $status = 'yellow'; $info = '停機'; $detail = '';
            $progress = null;
            $full_data = null;

            if (isset($abnormal_map[$mid])) { 
                $status = 'red'; 
                $info = '異常'; 
                $ab = $abnormal_map[$mid];
                $full_data = [
                    'is_abnormal' => true,
                    'abnormal_name' => $ab['abnormal_name'],
                    'start_time' => $ab['abnormal_start_time'],
                    'description' => $ab['abnormal_desc']
                ];
            }
            elseif (isset($active_tasks_map[$mid])) { 
                $task = $active_tasks_map[$mid]; 
                $status = 'green'; 
                $info = $task['bom']; 
                if (empty($task['start_time'])) {
                    $info .= ' (待)';
                }
                $detail = $task['d_id']; 
                if (isset($setup_status[$task['bom_ing_fid']])) { $status = 'blue'; $info .= ' (架機)'; } 
                
                // 計算進度
                $total = (int)$task['sqty'];
                $ok = (int)$task['total_ok'];
                $percent = ($total > 0) ? round(($ok / $total) * 100) : 0;
                $progress = ['total' => $total, 'ok' => $ok, 'percent' => $percent];
                
                $full_data = [
                    'bom' => $task['bom'],
                    'd_id' => $task['d_id'],
                    'client' => $task['Client_Name'],
                    'process' => $task['ProcessName'],
                    'sqty' => $task['sqty'],
                    'ok' => $task['total_ok'],
                    'status_text' => ($status == 'blue' ? '架機中' : (!empty($task['start_time']) ? '加工中' : '待加工')),
                    'start_time' => $task['start_time'],
                    'gear_info' => $task['gear_info']
                ];
            }
            $disp_name = eg_machine_disp_name($m); // 現場編號優先
            $result[$pos][$type][] = [
                'id' => $mid,
                'name' => $disp_name,
                'full_name' => $disp_name,
                'status' => $status, 
                'info' => $info, 
                'detail' => $detail, 
                'progress' => $progress,
                'full_data' => $full_data,
                'machine_type_id' => $m['machine_type_id']
            ];
        }
        echo json_encode(['success' => true, 'data' => $result, 'waiting' => $waiting_data, 'gear_settings' => $gear_settings]);
    } catch (Exception $e) { echo json_encode(['success' => false, 'message' => $e->getMessage()]); }
    exit;
}

// 建立資料庫連線
$db = new DBConnection();
$pdo = $db->getPDO();

// --- 權限檢查 (Permission Check) ---
$id = intval($_SESSION['id'] ?? 0);
$current_script_path = $_SERVER['PHP_SELF'];

$permission_code = null;
$page_url_editable = '';
$page_url_readonly = '';

try {
    // 1. 依據 URL 找到頁面，並取得其對應的模組及群組資訊
    $sql_page_info = "
        SELECT 
            smp.page_id,
            smp.page_url,
            smp.page_url_readonly,
            smp.group_id
        FROM system_module_pages smp
        WHERE (:script LIKE CONCAT('%', smp.page_url) AND smp.page_url IS NOT NULL AND smp.page_url != '')
           OR (:script LIKE CONCAT('%', smp.page_url_readonly) AND smp.page_url_readonly IS NOT NULL AND smp.page_url_readonly != '')
        LIMIT 1
    ";

    $stmt_page_info = $pdo->prepare($sql_page_info);
    $stmt_page_info->execute([':script' => $current_script_path]);
    $page_info = $stmt_page_info->fetch(PDO::FETCH_ASSOC);

    if (!$page_info) {
        // 若頁面無定義，則無權限
        $permission_code = null;
    } else {
        $page_url_editable = $page_info['page_url'];
        $page_url_readonly = $page_info['page_url_readonly'];
        $page_id = $page_info['page_id'];
        $group_id = $page_info['group_id'];

        // Step 2: Get module_code from group_id for group-level permission check
        $group_module_code = null;
        if (!empty($group_id)) {
            $sql_group_module = "SELECT module_code FROM system_modules WHERE group_id = :gid LIMIT 1";
            $stmt_group_module = $pdo->prepare($sql_group_module);
            $stmt_group_module->execute([':gid' => $group_id]);
            $group_module_code = $stmt_group_module->fetchColumn();
        }

        // Step 3: Find User Permissions, prioritizing 'page' scope over 'group' scope.
        $user_perms = []; // This will hold the final permission strings to be processed.

        // 3a. First, try to find a page-specific permission.
        $sql_page_perm = "
            SELECT permission 
            FROM user_module_permissions 
            WHERE user_id = :user_id AND scope = 'page' AND module_code = :page_id
        ";

        $stmt_page_perm = $pdo->prepare($sql_page_perm);
        $stmt_page_perm->execute([':user_id' => $id, ':page_id' => $page_id]);
        $page_permissions_found = $stmt_page_perm->fetchAll(PDO::FETCH_COLUMN);

        // Filter out any empty/null results from the database
        $page_permissions_found = array_filter($page_permissions_found);

        if (!empty($page_permissions_found)) {
            // If page-specific permissions exist, use them exclusively.
            $user_perms = $page_permissions_found;
        } else {
            // 3b. If no page-specific permission, check for group permission.
            if (!empty($group_module_code)) {
                $sql_group_perm = "
                    SELECT permission 
                    FROM user_module_permissions 
                    WHERE user_id = :user_id AND scope = 'group' AND module_code = :module_code
                ";

                $stmt_group_perm = $pdo->prepare($sql_group_perm);
                $stmt_group_perm->execute([':user_id' => $id, ':module_code' => $group_module_code]);
                $group_permissions_found = $stmt_group_perm->fetchAll(PDO::FETCH_COLUMN);

                // Filter out any empty/null results
                $group_permissions_found = array_filter($group_permissions_found);

                if (!empty($group_permissions_found)) {
                    $user_perms = $group_permissions_found;
                }
            }
        }

        // 4. 整合權限：拆分組合權限 (e.g., 'CR') 並以最強權限為準 (A > C/U/D > R)
        $all_individual_perms = [];
        foreach ($user_perms as $perm_string) {
            $chars = str_split($perm_string);
            $all_individual_perms = array_merge($all_individual_perms, $chars);
        }
        $unique_perms = array_unique($all_individual_perms);

        if (in_array('A', $unique_perms)) {
            $permission_code = 'A';
        } else {
            if (!empty($unique_perms)) {
                sort($unique_perms); // Sort for consistent order (C, D, R, U)
                $permission_code = implode('', $unique_perms);
            } else {
                $permission_code = null;
            }
        }
    }
} catch (Exception $e) {
    error_log("Permission check error: " . $e->getMessage());
    $permission_code = null; // 出錯時一律視為無權限
}

// 2. 判斷權限並導向
if (is_null($permission_code)) {
    if ($is_ajax) {
        echo json_encode(['success' => false, 'message' => '無權限存取此功能', 'redirect' => '../../index.php']);
        exit;
    } else {
        echo "<script>alert('無權限存取此頁面'); window.location.href='../../index.php';</script>";
        exit;
    }
}

if ($permission_code === 'R') {
    if (!empty($page_url_editable) && substr($current_script_path, -strlen($page_url_editable)) === $page_url_editable) {
        if (!empty($page_url_readonly)) {
            header("Location: " . $page_url_readonly);
            exit;
        }
    }
}

// 3. 設定功能操作權限變數
$has_A = (strpos($permission_code, 'A') !== false);
$has_C = (strpos($permission_code, 'C') !== false);
$has_U = (strpos($permission_code, 'U') !== false);
$has_D = (strpos($permission_code, 'D') !== false);

// C可以增加機台與拖移資料塊
// U原本可以修改機台與拖移資料塊 -> 改為不能拖移、不能修改機台
// D可以刪除機台不能拖移資料塊
// R不能看到設定機台按鈕也不能拖移資料塊
$can_drag = ($has_A || $has_C || $has_U); // 2026.03.05 開放 U 權限拖移，但 JS 會限制其行為
$can_reorder = ($has_A || $has_C); // 只有 A 和 C 可以在新的「排程列表」中重新排序
$can_add_machine = ($has_A || $has_C);
$can_edit_machine = ($has_A || ($has_C && $has_U)); // 修改機台需 A 或 (C且U)
$can_delete_machine = ($has_A || $has_D);
$can_manage_machine = ($can_add_machine || $can_edit_machine || $can_delete_machine);

// 其他功能權限維持原邏輯或對應到新變數
$can_report = ($has_A || $has_C || $has_U); // 報工屬於新增資料
$can_settings = $has_A; // 只有管理者可以存取設定
$can_edit_pti01 = ($has_A || $has_U); // 編輯車床備註
$can_edit_ps2 = ($has_A || $has_C || $has_U); // 現場備註 (開放給所有可報工人員)
$can_manage_material = ($has_A || ($has_C && !$has_U)); // A 管理者 或 C+R 生管 (C且非U通常為生管)
$can_change_partial_bom = ($has_A || ($has_U && $has_D)); // 修改補加工BOM：A 或 R+U+D (含U及D)

// 新增：細分角色邏輯 (用於前端 UI 控制)
$is_prod_control = ($has_C && !$has_U && !$has_A); // 生管 (有 C 且無 U 且非 A)
$is_production = (($has_U || $has_D) && !$has_A); // 生產 (有 U 或 D，且非 A，包含主管)

// Format permission code for display
$display_permission_code = '';
$permission_display_text = '';
// 定義權限說明 (Legend)
$permission_tooltip_text = "權限代碼說明：<br>A = 管理員 (所有權限)<br>C = 新增 (生管/拖移)<br>U = 更新 (生產/拖移)<br>D = 刪除 (機台)<br>R = 唯讀 (檢視)";

if ($permission_code) {
    if ($permission_code === 'A') {
        $display_permission_code = 'A';
        $permission_display_text = 'A 管理者';
    } else {
        $parts = str_split($permission_code);
        sort($parts);
        $display_permission_code = implode('+', $parts);

        $label = '';
        if (strpos($permission_code, 'C') !== false && strpos($permission_code, 'U') !== false) {
            $label = '生產主管';
        } elseif (strpos($permission_code, 'C') !== false) {
            $label = '生管';
        } elseif ($permission_code === 'R' || $permission_code === 'r') {
            $label = '唯讀';
        } elseif (strpos($permission_code, 'U') !== false || strpos($permission_code, 'D') !== false) {
            $label = '生產組員';
        } else {
            $label = '唯讀';
        }

        $permission_display_text = $display_permission_code . ' ' . $label;
    }
}

// 修正權限判斷：若 display_permission_code 為 'C+R'，則賦予物料管理權限
if ($display_permission_code === 'C+R') {
    $can_manage_material = true;
    $can_manage_machine = false; // 2026.03.06 C+R 隱藏設定機台按鈕
}

// --- 獲取 UI 顯示設定 ---
$ui_settings = [
    'show_face_options' => [], // '此面完工' and '加工面'
    'show_material_arrived' => []
];
try {
    $stmt_ui = $pdo->prepare("SELECT param_value FROM system_parameters WHERE param_group = 'PROCESS_SCHEDULE_SETTINGS' AND param_key = 'ui_display_settings'");
    $stmt_ui->execute();
    $row_ui = $stmt_ui->fetch(PDO::FETCH_ASSOC);
    if ($row_ui && !empty($row_ui['param_value'])) {
        $decoded_settings = json_decode($row_ui['param_value'], true);
        if (is_array($decoded_settings)) {
            // Ensure keys exist before merging
            if (isset($decoded_settings['show_face_options'])) {
                $ui_settings['show_face_options'] = $decoded_settings['show_face_options'];
            }
            if (isset($decoded_settings['show_material_arrived'])) {
                $ui_settings['show_material_arrived'] = $decoded_settings['show_material_arrived'];
            }
        }
    }
} catch (Exception $e) {
    error_log("Error fetching UI settings: " . $e->getMessage());
}

// 修正權限判斷：若 display_permission_code 為 'C+R'，則賦予物料管理權限
if ($display_permission_code === 'C+R') {
    $can_manage_material = true;
}
// --- 獲取齒輪顯示設定 (PHP Context) ---
$gear_display_types = [];
try {
    $stmt_gear_settings = $pdo->prepare("SELECT param_value FROM system_parameters WHERE param_group = 'BOM_SETTING' AND param_key = 'gear_info_display_types'");
    $stmt_gear_settings->execute();
    $row_gear_settings = $stmt_gear_settings->fetch(PDO::FETCH_ASSOC);
    if ($row_gear_settings) {
        $gear_display_types = json_decode($row_gear_settings['param_value'], true);
    }
} catch (Exception $e) {
}

// --- 獲取看板分頁顯示設定 ---
$visible_tabs_ids = null;
try {
    $stmt_visible_tabs = $pdo->prepare("SELECT param_value FROM system_parameters WHERE param_group = 'PROCESS_SCHEDULE' AND param_key = 'visible_tabs'");
    $stmt_visible_tabs->execute();
    $visible_tabs_json = $stmt_visible_tabs->fetchColumn();
    if ($visible_tabs_json) {
        $visible_tabs_ids = json_decode($visible_tabs_json, true);
    }
} catch (Exception $e) {
    error_log("Error fetching visible tabs setting: " . $e->getMessage());
}


// =================================================================================
// 後端邏輯：獲取全廠看板資料 (Dashboard Data)
// =================================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_global_sequence') {
    header('Content-Type: application/json');
    try {
        if (!$can_reorder) { // 使用新的權限變數
            throw new Exception("無權限執行此操作");
        }
        $order = $_POST['order'] ?? [];

        $pdo->beginTransaction();
        // 確認：排序順序儲存於 bom_ing 資料表的 processing_sequence 欄位
        // 拖移後的新順序會依序更新至此欄位 (1, 2, 3...)
        $stmt = $pdo->prepare("UPDATE bom_ing SET processing_sequence = ? WHERE bom_ing_fid = ?");
        foreach ($order as $index => $fid) {
            // 修正：加入 (int) 強制轉型，過濾掉 '2ifi' 等異常字串
            $stmt->execute([$index + 1, (int)$fid]);
        }
        $pdo->commit();

        echo json_encode(['success' => true, 'message' => '排程順序已儲存']);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => '儲存失敗: ' . $e->getMessage()]);
    }
    exit;
    
}

// =================================================================================
// 後端邏輯：個人顯示設定 (讀取與儲存)
// =================================================================================

// 預設顯示設定
$personal_settings = [
    'show_shipping_date' => 1,
    'show_ps2' => 1,
    'show_pti01_ps' => 1,
    'show_est_completion' => 1,
    'show_progress' => 1,
    'show_est_days' => 1
];

// 讀取設定
$current_user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? 0;
if ($current_user_id) {
    $stmt_pset = $pdo->prepare("SELECT setting_value FROM user_page_settings WHERE user_id = ? AND page_code = 'process_schedule' AND setting_key = 'process_schedule_personal_setting'");
    $stmt_pset->execute([$current_user_id]);
    $row_pset = $stmt_pset->fetch(PDO::FETCH_ASSOC);
    if ($row_pset) {
        $saved_pset = json_decode($row_pset['setting_value'], true);
        if (is_array($saved_pset)) {
            $personal_settings = array_merge($personal_settings, $saved_pset);
        }
    }
}

// 產生 Body Class 以控制顯示
$body_setting_classes = '';
foreach ($personal_settings as $key => $val) {
    if (!$val) {
        $body_setting_classes .= ' hide-' . str_replace('_', '-', $key);
    }
}

// 設定時區為台北時間，避免 strtotime 解析與 time() 比較時產生誤差
date_default_timezone_set('Asia/Taipei');

// 獲取行事曆例外日 (s=休假, m=補班)
$calendar_map = [];
try {
    $stmt_cal = $pdo->query("
        SELECT DATE(e.start) as d, ec.day_type 
        FROM evenement e 
        JOIN event_category ec ON e.category_id = ec.id 
        WHERE ec.day_type IN ('s', 'm')
    ");
    while ($row = $stmt_cal->fetch(PDO::FETCH_ASSOC)) {
        $calendar_map[$row['d']] = $row['day_type'];
    }
} catch (Exception $e) {
}

// 計算工作天函數 (排除週六週日 + 行事曆例外)
function calculate_working_days($start_date, $calendar_map)
{
    if (!$start_date) return 0;
    $start = new DateTime($start_date);
    $end = new DateTime(); // Now
    $start->setTime(0, 0, 0);
    $end->setTime(0, 0, 0);

    if ($start > $end) return 0;

    $days = 0;
    $period = new DatePeriod($start, new DateInterval('P1D'), $end->modify('+1 day'));
    foreach ($period as $dt) {
        $ymd = $dt->format('Y-m-d');
        $w = $dt->format('N');
        $is_work = ($w < 6); // 預設週一至週五為工作日

        if (isset($calendar_map[$ymd])) {
            if ($calendar_map[$ymd] == 's') $is_work = false;
            elseif ($calendar_map[$ymd] == 'm') $is_work = true;
        }

        if ($is_work) $days++;
    }
    return $days;
}

// 計算預計完工時間 (模擬工時推算)
function calculate_completion_time($hours_needed, $calendar_map)
{
    if ($hours_needed <= 0) return '-';

    $current = new DateTime();
    // 設定最大迴圈避免無窮迴圈 (例如 1 年)
    $max_loops = 24 * 365;
    $loops = 0;

    while ($hours_needed > 0 && $loops < $max_loops) {
        $loops++;

        $h = (int)$current->format('H');
        $ymd = $current->format('Y-m-d');
        $w = $current->format('N');

        // 判斷是否為工作日
        $is_work_day = ($w < 6);
        if (isset($calendar_map[$ymd])) {
            if ($calendar_map[$ymd] == 's') $is_work_day = false;
            elseif ($calendar_map[$ymd] == 'm') $is_work_day = true;
        }

        if ($is_work_day) {
            // 假設工作時間: 08:00-12:00, 13:00-17:00 (共8小時)
            $is_work_hour = ($h >= 8 && $h < 12) || ($h >= 13 && $h < 17);
            if ($is_work_hour) {
                $hours_needed -= 1; // 扣除 1 小時
            }
        }

        // 時間推進 1 小時
        $current->modify("+1 hour");
    }
    return $current->format('m/d H:i');
}

// =================================================================================
// 後端邏輯：僅更新機台指派 (R+U 權限拖曳時使用)
// =================================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_assignment_only') {
    header('Content-Type: application/json');
    try {
        if (!$has_U && !$has_A && !$has_C) {
            throw new Exception("無權限執行此操作");
        }
        $machine_id = $_POST['machine_id'];
        if (!is_numeric($machine_id) || strpos($machine_id, 'unassigned') !== false) {
            $machine_id = null;
        }
        $bom_ing_fid = $_POST['bom_ing_fid'];

        // 只更新 machine_id，並將 processing_sequence 設為 NULL，使其排到對應列表的最後
        $stmt = $pdo->prepare("UPDATE bom_ing SET machine_id = ?, processing_sequence = NULL WHERE bom_ing_fid = ?");
        $stmt->execute([$machine_id, $bom_ing_fid]);

        // 如果是移回未指派，也清除 processing_sequence
        if (is_null($machine_id)) {
            $stmt_clear_seq = $pdo->prepare("UPDATE bom_ing SET processing_sequence = NULL WHERE bom_ing_fid = ?");
            $stmt_clear_seq->execute([$bom_ing_fid]);
        }

        echo json_encode(['success' => true, 'message' => '機台指派已更新']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => '更新失敗: ' . $e->getMessage()]);
    }
    exit;
}


// =================================================================================
// 後端邏輯：處理 AJAX 請求以更新排程順序
// =================================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['machine_id']) && isset($_POST['order'])) {
    header('Content-Type: application/json');

    if (!$can_drag) {
        echo json_encode(['success' => false, 'message' => '無權限執行此操作 (需 U 權限)']);
        exit;
    }

    $machine_id = $_POST['machine_id'];
    // 修正：若 machine_id 為 "unassigned-xx" 格式，代表移回未指派區域，需設為 NULL 以符合資料庫格式
    if (!is_numeric($machine_id) || strpos($machine_id, 'unassigned') !== false) {
        $machine_id = null;
    }
    $order = $_POST['order']; // 这是一个 bom_ing_fid 的陣列

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            "UPDATE bom_ing SET processing_sequence = ?, machine_id = ? WHERE bom_ing_fid = ?"
        );

        foreach ($order as $index => $bom_ing_fid) {
            // processing_sequence 從 1 開始
            $stmt->execute([$index + 1, $machine_id, $bom_ing_fid]);
        }

        $pdo->commit();
        echo json_encode(['success' => true, 'message' => '順序已更新！']);
    } catch (\Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => '更新失敗: ' . $e->getMessage()]);
    }
    exit;
}

// =================================================================================
// 後端邏輯：儲存全域排程順序 (從新 Modal 呼叫)
// =================================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_global_sequence') {
    header('Content-Type: application/json');
    try {
        if (!$can_reorder) { // 使用新的權限變數
            throw new Exception("無權限執行此操作");
        }
        $order = $_POST['order'] ?? [];

        $pdo->beginTransaction();
        $stmt = $pdo->prepare("UPDATE bom_ing SET processing_sequence = ? WHERE bom_ing_fid = ?");
        foreach ($order as $index => $fid) {
            // 修正：加入 (int) 強制轉型，過濾掉 '2ifi' 等異常字串
            $stmt->execute([$index + 1, (int)$fid]);
        }
        $pdo->commit();

        echo json_encode(['success' => true, 'message' => '排程順序已儲存']);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => '儲存失敗: ' . $e->getMessage()]);
    }
    exit;
}

// =================================================================================
// 後端邏輯：刪除報工紀錄 (僅限管理員)
// =================================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_daily_report') {
    header('Content-Type: application/json');
    try {
        if (!$has_A && !$has_D) {
            throw new Exception("無權限執行此操作 (需 A 或 D 權限)");
        }
        $report_id = $_POST['report_id'];

        // 1. 查詢要刪除的報工紀錄資訊 (用於後續狀態判斷)
        $stmt_get = $pdo->prepare("SELECT bom_ing_fid, machine_id, is_finished FROM pm_process_daily_report WHERE report_id = ?");
        $stmt_get->execute([$report_id]);
        $report = $stmt_get->fetch(PDO::FETCH_ASSOC);

        if (!$report) {
            throw new Exception("找不到該筆報工紀錄");
        }

        $fid = $report['bom_ing_fid'];
        $machine_id = $report['machine_id'];
        $was_finished = $report['is_finished'];

        // 2. 刪除紀錄
        $pdo->prepare("DELETE FROM pm_process_daily_ng WHERE report_id = ?")->execute([$report_id]);
        $pdo->prepare("DELETE FROM pm_process_daily_report WHERE report_id = ?")->execute([$report_id]);

        // 3. 狀態回朔邏輯
        // 如果刪除的是「完工」紀錄，且該工單沒有其他「完工」紀錄，則需將狀態從 'Q' (QC待驗) 改回 'ing' (加工中)
        // 並將機台指派回該報工紀錄的機台 (因為完工時機台會被設為 NULL)
        if ($was_finished == 1 && !empty($fid)) {
            $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM pm_process_daily_report WHERE bom_ing_fid = ? AND is_finished = 1");
            $stmt_check->execute([$fid]);
            $remaining_finished = $stmt_check->fetchColumn();

            if ($remaining_finished == 0) {
                // 恢復為加工中，並指派回原機台
                $stmt_revert = $pdo->prepare("UPDATE bom_ing SET processing_state = 'ing', machine_id = ? WHERE bom_ing_fid = ?");
                $stmt_revert->execute([$machine_id, $fid]);
            }
        }

        // 新增：若刪除後無任何報工紀錄，則重置機台指派 (讓使用者可以重新選擇機台)
        $moved_to_unassigned = false;
        if (!empty($fid)) {
            $stmt_check_all = $pdo->prepare("SELECT COUNT(*) FROM pm_process_daily_report WHERE bom_ing_fid = ?");
            $stmt_check_all->execute([$fid]);
            if ($stmt_check_all->fetchColumn() == 0) {
                $stmt_reset = $pdo->prepare("UPDATE bom_ing SET machine_id = NULL, processing_state = 'ing' WHERE bom_ing_fid = ?");
                $stmt_reset->execute([$fid]);
                $moved_to_unassigned = true;
            }
        }

        echo json_encode(['success' => true, 'message' => '報工紀錄已刪除，狀態已更新', 'moved_to_unassigned' => $moved_to_unassigned]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// =================================================================================
// 後端邏輯：修改補加工報工紀錄的 BOM 綁定 (需 A 或 U+D 權限)
// =================================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_report_bom') {
    header('Content-Type: application/json');
    try {
        if (!$has_A && !($has_U && $has_D)) {
            throw new Exception("無權限執行此操作 (需 A 或 U+D 權限)");
        }
        $report_id    = intval($_POST['report_id'] ?? 0);
        $new_fid      = intval($_POST['new_bom_ing_fid'] ?? 0);
        $new_proc_no  = intval($_POST['new_process_no'] ?? 0);

        if (!$report_id || !$new_fid || !$new_proc_no) {
            throw new Exception("缺少必要參數");
        }

        // 確認新 bom_ing_fid 存在
        $stmt_chk = $pdo->prepare("SELECT bom_ing_fid FROM bom_ing WHERE bom_ing_fid = ?");
        $stmt_chk->execute([$new_fid]);
        if (!$stmt_chk->fetch()) {
            throw new Exception("指定的工單不存在");
        }

        // 更新報工紀錄的 bom_ing_fid 與 process_no
        $stmt_upd = $pdo->prepare("UPDATE pm_process_daily_report SET bom_ing_fid = ?, process_no = ? WHERE report_id = ?");
        $stmt_upd->execute([$new_fid, $new_proc_no, $report_id]);

        echo json_encode(['success' => true, 'message' => 'BOM 綁定已更新']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// =================================================================================
// 後端邏輯：刪除機台異常通報 (需 A 或 D 權限)
// =================================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_machine_abnormal') {
    header('Content-Type: application/json');
    try {
        if (!$has_A && !$has_D) {
            throw new Exception("無權限執行此操作 (需 A 或 D 權限)");
        }
        $abnormal_id = $_POST['abnormal_id'];
        $stmt = $pdo->prepare("DELETE FROM pm_machine_abnormal WHERE abnormal_id = ?");
        $stmt->execute([$abnormal_id]);
        echo json_encode(['success' => true, 'message' => '異常紀錄已刪除']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// =================================================================================
// 後端邏輯：儲存個人顯示設定
// =================================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_personal_setting') {
    header('Content-Type: application/json');
    try {
        $user_id = $_SESSION['user_id'] ?? $_SESSION['id'] ?? 0;
        $settings = $_POST['settings'] ?? [];
        $json = json_encode($settings);

        // 使用 INSERT ON DUPLICATE KEY UPDATE (需確保有唯一索引 user_id + page_code + setting_key)
        // 這裡使用先查後更/插的方式以確保相容性
        $sql = "INSERT INTO user_page_settings (user_id, page_code, setting_key, setting_value, updated_at) VALUES (?, 'process_schedule', 'process_schedule_personal_setting', ?, NOW()) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()";
        $pdo->prepare($sql)->execute([$user_id, $json]);

        echo json_encode(['success' => true, 'message' => '個人顯示設定已儲存']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => '儲存失敗: ' . $e->getMessage()]);
    }
    exit;
}

// =================================================================================
// 後端邏輯：儲存製程分類顯示設定
// =================================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_process_category_settings') {
    header('Content-Type: application/json');
    try {
        // 權限檢查
        if (!$has_A && $display_permission_code !== 'C+R+U') {
            throw new Exception("無權限執行此操作");
        }

        $settings_json = $_POST['settings'] ?? '{}';
        $settings_array = json_decode($settings_json, true);

        // 簡單驗證一下格式
        if (!is_array($settings_array) || !isset($settings_array['show_face_options']) || !isset($settings_array['show_material_arrived'])) {
            throw new Exception("傳入的設定格式錯誤");
        }

        $user = $_SESSION['userName'] ?? 'system';

        $sql = "INSERT INTO system_parameters (param_group, param_key, param_value, description, updated_by, updated_at) 
                VALUES ('PROCESS_SCHEDULE_SETTINGS', 'ui_display_settings', ?, '加工排程看板-回報UI顯示設定', ?, NOW()) 
                ON DUPLICATE KEY UPDATE param_value = VALUES(param_value), updated_by = VALUES(updated_by), updated_at = NOW()";
        $pdo->prepare($sql)->execute([$settings_json, $user]);

        echo json_encode(['success' => true, 'message' => '設定已儲存']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => '儲存失敗: ' . $e->getMessage()]);
    }
    exit;
}

// =================================================================================
// 後端邏輯：機台管理 (列表/新增/修改/刪除)
// =================================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // 1. 獲取機台設定資料 (列表 + 類型)
    if ($_POST['action'] === 'get_machine_settings') {
        header('Content-Type: application/json');
        try {
            if (!$can_manage_machine) throw new Exception("無權限存取");

            // 獲取機台列表 (排除已刪除 state=1)
            $sql_machines = "
                SELECT ml.*, pt.process_type as type_name 
                FROM machine_list ml
                LEFT JOIN process_type pt ON ml.machine_type_id = pt.process_type_id
                WHERE (ml.state IS NULL OR ml.state != '1' OR ml.state = '')
                ORDER BY ml.machine_type_id, ml.machine
            ";
            $machines = $pdo->query($sql_machines)->fetchAll(PDO::FETCH_ASSOC);

            // 獲取機台類型列表
            $types = $pdo->query("SELECT process_type_id as machine_type_id, process_type as machine_type FROM process_type ORDER BY machine_type_id")->fetchAll(PDO::FETCH_ASSOC);

            // 獲取齒輪顯示設定
            $stmt_gear = $pdo->prepare("SELECT param_value FROM system_parameters WHERE param_group = 'BOM_SETTING' AND param_key = 'gear_info_display_types'");
            $stmt_gear->execute();
            $row_gear = $stmt_gear->fetch(PDO::FETCH_ASSOC);
            $gear_settings = $row_gear ? json_decode($row_gear['param_value'], true) : [];

            echo json_encode(['success' => true, 'machines' => $machines, 'types' => $types, 'gear_settings' => $gear_settings]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // 4. 儲存齒輪顯示設定
    if ($_POST['action'] === 'save_gear_display_settings') {
        header('Content-Type: application/json');
        try {
            if (!$can_settings) throw new Exception("無權限執行此操作");

            $settings = $_POST['settings'] ?? [];
            $json = json_encode($settings);
            $user = $_SESSION['userName'] ?? 'system';

            $sql = "INSERT INTO system_parameters (param_group, param_key, param_value, description, updated_by, updated_at) 
                    VALUES ('BOM_SETTING', 'gear_info_display_types', ?, '儀表板顯示齒輪規格的機台類型', ?, NOW()) 
                    ON DUPLICATE KEY UPDATE param_value = VALUES(param_value), updated_by = VALUES(updated_by), updated_at = NOW()";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$json, $user]);

            echo json_encode(['success' => true, 'message' => '設定已儲存']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // 2. 儲存機台 (新增/修改)
    if ($_POST['action'] === 'save_machine') {
        header('Content-Type: application/json');
        try {
            $id = $_POST['machine_id'] ?? '';
            $name = $_POST['machine'] ?? '';
            $type_id = $_POST['machine_type_id'] ?? '';
            $need_setup = $_POST['need_setup'] ?? 0;
            $position = $_POST['position'] ?? '';
            $machine_model = trim($_POST['machine_model'] ?? '');
            $asset_no = trim($_POST['asset_no'] ?? '');
            $field_no = trim($_POST['field_no'] ?? '');
            $spec = trim($_POST['spec'] ?? '');
            $note = trim($_POST['note'] ?? '');

            if (empty($name)) throw new Exception("機台名稱不可為空");
            if (empty($type_id)) throw new Exception("請選擇機台類型");

            if (!empty($id)) {
                // 修改
                if (!$can_edit_machine) throw new Exception("無修改權限 (需 A 或 U)");
                $stmt = $pdo->prepare("UPDATE machine_list SET machine=?, machine_type_id=?, need_setup=?, position=?, machine_model=?, asset_no=?, field_no=?, spec=?, note=? WHERE machine_id=?");
                $stmt->execute([$name, $type_id, $need_setup, $position, $machine_model, $asset_no, $field_no, $spec, $note, $id]);
                echo json_encode(['success' => true, 'message' => '機台已更新']);
            } else {
                // 新增
                if (!$can_add_machine) throw new Exception("無新增權限 (需 A 或 C)");
                $stmt = $pdo->prepare("INSERT INTO machine_list (machine, machine_type_id, need_setup, position, machine_model, asset_no, field_no, spec, note) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$name, $type_id, $need_setup, $position, $machine_model, $asset_no, $field_no, $spec, $note]);
                echo json_encode(['success' => true, 'message' => '機台已新增']);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // 3. 刪除機台 (軟刪除 state=1)
    if ($_POST['action'] === 'delete_machine') {
        header('Content-Type: application/json');
        try {
            if (!$can_delete_machine) throw new Exception("無刪除權限 (需 A 或 D)");
            $id = $_POST['machine_id'];
            $stmt = $pdo->prepare("UPDATE machine_list SET state='1' WHERE machine_id=?");
            $stmt->execute([$id]);
            echo json_encode(['success' => true, 'message' => '機台已刪除']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
}

// =================================================================================
// 後端邏輯：獲取報工歷史 (最近 10 筆)
// =================================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_report_history') {
    while (ob_get_level()) ob_end_clean(); // 清除緩衝區，避免雜訊破壞 JSON
    header('Content-Type: application/json');
    try {
        $fid = $_POST['bom_ing_fid'] ?? null;
        $process_no = $_POST['process_no'] ?? null;
        $report_id = $_POST['report_id'] ?? null;

        if ($report_id) {
            // 針對特定 report_id (如 TEMP 臨時加工)
            $stmt = $pdo->prepare("
                SELECT r.*, r.process_face, u1.user_cname as setup_user_name, u2.user_cname as prod_user_name,
                (SELECT SUM(ng_qty) FROM pm_process_daily_ng WHERE report_id = r.report_id) as total_ng
                FROM pm_process_daily_report r
                LEFT JOIN user u1 ON r.setup_user_id = u1.id
                LEFT JOIN user u2 ON r.production_user_id = u2.id
                WHERE r.report_id = ?
            ");
            $stmt->execute([$report_id]);
        } else {
            // 一般查詢 (依 bom_ing_fid 與 process_no)
            $stmt = $pdo->prepare("
                SELECT r.*, r.process_face, u1.user_cname as setup_user_name, u2.user_cname as prod_user_name,
                COALESCE(NULLIF(TRIM(m.field_no),''), m.machine) as machine_name,
                (SELECT SUM(ng_qty) FROM pm_process_daily_ng WHERE report_id = r.report_id) as total_ng
                FROM pm_process_daily_report r
                LEFT JOIN user u1 ON r.setup_user_id = u1.id
                LEFT JOIN user u2 ON r.production_user_id = u2.id
                LEFT JOIN machine_list m ON r.machine_id = m.machine_id
                WHERE r.bom_ing_fid = ? AND r.process_no = ?
                ORDER BY COALESCE(r.setup_start_time, r.production_start_time, r.report_date) DESC, r.report_id DESC
                LIMIT 10
            ");
            $stmt->execute([$fid, $process_no]);
        }

        $history = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 獲取目前排定的機台 (從 bom_ing)
        $stmt_m = $pdo->prepare("SELECT machine_id FROM bom_ing WHERE bom_ing_fid = ?");
        $stmt_m->execute([$fid]);
        $curr = $stmt_m->fetch(PDO::FETCH_ASSOC);
        $current_machine_id = $curr['machine_id'] ?? null;

        echo json_encode(['success' => true, 'history' => $history, 'current_machine_id' => $current_machine_id]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// =================================================================================
// 後端邏輯：搜尋已完工/非進行中任務
// =================================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'search_finished_tasks') {
    header('Content-Type: application/json');
    $term = $_POST['term'] ?? '';
    try {
        // Requirement 3: Get machine types for filter buttons
        $machine_types = $pdo->query("SELECT process_type_id AS machine_type_id, process_type AS machine_type FROM process_type ORDER BY process_type_id")->fetchAll(PDO::FETCH_ASSOC);

        // 1. 取得符合條件的項目列表 (包含正常工單與臨時加工)
        $items_to_process = [];

        if (empty($term)) {
            // 預設：最近有報工的 50 筆紀錄 (包含臨時加工)
            // 正常工單依 bom_ing_fid 分組，臨時加工依 report_id 分組 (因為無 fid)
            $sql = "SELECT bom_ing_fid, MAX(report_source) as report_source, MAX(report_id) as max_rid 
                    FROM pm_process_daily_report 
                    GROUP BY bom_ing_fid, (CASE WHEN report_source = 'TEMP' THEN report_id ELSE 0 END)
                    ORDER BY MAX(report_date) DESC, MAX(report_id) DESC 

                    LIMIT 100";

            $items_to_process = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        } else {
            // 搜尋：關聯多張表進行模糊搜尋
            $sql = "SELECT bi.bom_ing_fid
                    FROM bom_ing bi
                    JOIN bom b ON bi.bom = b.bom
                    LEFT JOIN (SELECT D_Setting_Id, MAX(d_id) as max_did FROM d_setting GROUP BY D_Setting_Id) ds_max ON b.d_id = ds_max.D_Setting_Id
                    LEFT JOIN d_setting_gear dsg ON dsg.d_setting_id = ds_max.max_did
                    LEFT JOIN pm_process_daily_report pdr ON pdr.bom_ing_fid = bi.bom_ing_fid
                    LEFT JOIN machine_list m ON pdr.machine_id = m.machine_id
                    WHERE (
                        bi.bom LIKE ? OR b.d_id LIKE ? OR b.Client_Name LIKE ? OR
                        dsg.Module LIKE ? OR dsg.Teeth LIKE ? OR dsg.Face_Width LIKE ? OR dsg.Workpiece_Length LIKE ? OR
                        m.machine LIKE ? OR m.field_no LIKE ? OR m.asset_no LIKE ?
                    )
                    AND pdr.report_id IS NOT NULL
                    GROUP BY bi.bom_ing_fid
                    ORDER BY MAX(pdr.report_date) DESC, MAX(pdr.report_id) DESC
                    LIMIT 100";
            $like = "%$term%";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$like, $like, $like, $like, $like, $like, $like, $like, $like, $like]);
            $fids = $stmt->fetchAll(PDO::FETCH_COLUMN);

            foreach ($fids as $f) {
                $items_to_process[] = ['bom_ing_fid' => $f, 'report_source' => 'NORMAL', 'max_rid' => 0];
            }
        }

        // 2. 針對每個 FID 獲取詳細資料與彙總
        $results = [];
        foreach ($items_to_process as $item) {
            $fid = $item['bom_ing_fid'];
            $source = $item['report_source'] ?? 'NORMAL';
            $rid = $item['max_rid'] ?? 0;

            // --- 處理臨時加工 (TEMP) ---
            if ($source === 'TEMP') {
                $stmt_temp = $pdo->prepare("
                    SELECT r.*, u1.user_cname as setup_user, u2.user_cname as prod_user, COALESCE(NULLIF(TRIM(m.field_no),''), m.machine) AS machine, pn.ProcessName
                    FROM pm_process_daily_report r
                    LEFT JOIN user u1 ON r.setup_user_id = u1.id
                    LEFT JOIN user u2 ON r.production_user_id = u2.id
                    LEFT JOIN machine_list m ON r.machine_id = m.machine_id
                    LEFT JOIN process_no pn ON r.process_no = pn.ProcessNo
                    WHERE r.report_id = ?
                ");
                $stmt_temp->execute([$rid]);
                $tRow = $stmt_temp->fetch(PDO::FETCH_ASSOC);

                if ($tRow) {
                    $results[] = [
                        'bom_ing_fid' => null,
                        'bom' => '臨時加工',
                        'd_id' => $tRow['remark'] ? $tRow['remark'] : '(無備註)',
                        'Client_Name' => $tRow['source_reason'] ?? '其他',
                        'ProcessName' => $tRow['ProcessName'],
                        'process_no' => $tRow['process_no'],
                        'process_type_id' => 0,
                        'sqty' => 0,
                        'total_ok_qty' => $tRow['produced_qty'],
                        'total_ng_qty' => 0, // 臨時加工通常不計累計NG，或需額外查詢
                        'is_finished' => $tRow['is_finished'],
                        'setup_hours' => 0,
                        'prod_hours' => 0,
                        'gear_spec' => '',
                        'face_info' => '-',
                        'machine' => $tRow['machine'],
                        'report_date' => $tRow['report_date'],
                        'logs' => [$tRow], // 直接使用本身作為 log
                        'is_report_list' => true,
                        'report_source' => 'TEMP',
                        'report_id' => $rid
                    ];
                }
                continue;
            }

            // --- 處理正常工單 ---
            // 基本資訊
            $sql_info = "SELECT bi.bom_ing_fid, bi.bom, bi.sqty, bi.process_no, bi.pti01_ps, bi.`1_side`, bi.PS2,
                                b.d_id, b.Client_Name, pn.ProcessName, pn.process_type_id,
                                dsg.Module, dsg.Teeth, dsg.Face_Width, dsg.Workpiece_Length
                         FROM bom_ing bi
                         JOIN bom b ON bi.bom = b.bom
                         LEFT JOIN process_no pn ON bi.process_no = pn.ProcessNo
                         LEFT JOIN (SELECT D_Setting_Id, MAX(d_id) as max_did FROM d_setting GROUP BY D_Setting_Id) ds_max ON b.d_id = ds_max.D_Setting_Id
                         LEFT JOIN d_setting_gear dsg ON dsg.d_setting_id = ds_max.max_did
                         WHERE bi.bom_ing_fid = ?";
            $stmt_info = $pdo->prepare($sql_info);
            $stmt_info->execute([$fid]);
            $info = $stmt_info->fetch(PDO::FETCH_ASSOC);

            if (!$info) continue;

            // 統計數據 (總良品、總NG)
            $sql_agg = "SELECT 
                            SUM(pdr.produced_qty) as total_ok,
                            (SELECT COALESCE(SUM(ng_qty), 0) FROM pm_process_daily_ng WHERE report_id IN (SELECT report_id FROM pm_process_daily_report WHERE bom_ing_fid = ?)) as total_ng,
                            MAX(pdr.is_finished) as max_is_finished,
                            MAX(CASE WHEN pdr.is_finished = 1 THEN 1 ELSE 0 END) as has_task_finished,
                            SUM(TIMESTAMPDIFF(SECOND, pdr.setup_start_time, pdr.setup_end_time)) as total_setup_sec,
                            SUM(TIMESTAMPDIFF(SECOND, pdr.production_start_time, pdr.production_end_time)) as total_prod_sec
                        FROM pm_process_daily_report pdr
                        WHERE pdr.bom_ing_fid = ?";
            $stmt_agg = $pdo->prepare($sql_agg);
            $stmt_agg->execute([$fid, $fid]);
            $agg = $stmt_agg->fetch(PDO::FETCH_ASSOC);

            // 加工面統計 (Face Info)
            $face_info_str = '-';
            $sql_face = "SELECT process_face, SUM(produced_qty) as qty, MAX(is_finished) as status 
                         FROM pm_process_daily_report 
                         WHERE bom_ing_fid = ? AND process_face IS NOT NULL AND process_face != '' 
                         GROUP BY process_face";
            $stmt_face = $pdo->prepare($sql_face);
            $stmt_face->execute([$fid]);
            $faces = $stmt_face->fetchAll(PDO::FETCH_ASSOC);

            $final_ok_qty = $agg['total_ok'] ?? 0;

            if (!empty($faces)) {
                usort($faces, function ($a, $b) {
                    return strcmp($a['process_face'], $b['process_face']);
                });

                // 若有加工面，只計算「未標示此面完工(2)」的面
                $recalc_qty = 0;
                foreach ($faces as $f) {
                    if ($f['status'] != 2) {
                        $recalc_qty += $f['qty'];
                    }
                }
                $final_ok_qty = $recalc_qty;

                $f_parts = [];
                foreach ($faces as $f) {
                    $icon = '';
                    if ($f['status'] >= 1) { // 1=完工, 2=換面(此面完工)
                        $icon = ' <i class="fa fa-check text-success"></i>';
                    }
                    $f_parts[] = $f['process_face'] . 'x' . $f['qty'] . $icon;
                }
                $face_info_str = implode(' ', $f_parts);
            }

            // 報工紀錄 (最近 10 筆)
            // Requirement 2: Fetch more log details
            $sql_logs = "SELECT pdr.report_date, pdr.setup_start_time, pdr.setup_end_time, pdr.production_start_time, pdr.production_end_time, pdr.produced_qty, COALESCE(NULLIF(TRIM(m.field_no),''), m.machine) AS machine,
                                pdr.process_face, u1.user_cname as setup_user, u2.user_cname as prod_user,
                                (SELECT SUM(ng_qty) FROM pm_process_daily_ng WHERE report_id = pdr.report_id) as log_ng_qty
                         FROM pm_process_daily_report pdr
                         LEFT JOIN machine_list m ON pdr.machine_id = m.machine_id
                         LEFT JOIN user u1 ON pdr.setup_user_id = u1.id
                         LEFT JOIN user u2 ON pdr.production_user_id = u2.id
                         WHERE pdr.bom_ing_fid = ?
                         ORDER BY pdr.report_date DESC, pdr.report_id DESC
                         LIMIT 10";
            $stmt_logs = $pdo->prepare($sql_logs);
            $stmt_logs->execute([$fid]);
            $logs = $stmt_logs->fetchAll(PDO::FETCH_ASSOC);

            // 格式化齒輪規格
            $gear_spec = '';
            if (!empty($info['Module'])) {
                $modClean = preg_replace('/[^0-9.]/', '', $info['Module']);
                $gear_spec = "M" . floatval($modClean);
                if (!empty($info['Teeth'])) $gear_spec .= " T" . floatval($info['Teeth']);
                if (!empty($info['Face_Width'])) $gear_spec .= " W" . floatval($info['Face_Width']);
                if (!empty($info['Workpiece_Length'])) $gear_spec .= " L" . floatval($info['Workpiece_Length']);
            }

            // 決定顯示的機台與日期 (取最新一筆)
            $display_machine = !empty($logs) ? $logs[0]['machine'] : '-';
            $display_date = !empty($logs) ? $logs[0]['report_date'] : '-';

            // 優先顯示本站完工(1)，否則顯示最大狀態(可能為2)
            $display_is_finished = ($agg['has_task_finished'] == 1) ? 1 : ($agg['max_is_finished'] ?? 0);

            $results[] = [
                'bom_ing_fid' => $info['bom_ing_fid'],
                'bom' => $info['bom'],
                'd_id' => $info['d_id'],
                'Client_Name' => $info['Client_Name'],
                'ProcessName' => $info['ProcessName'],
                'process_no' => $info['process_no'],
                'process_type_id' => $info['process_type_id'],
                'sqty' => $info['sqty'],
                'total_ok_qty' => $final_ok_qty,
                'total_ng_qty' => $agg['total_ng'] ?? 0,
                'is_finished' => $display_is_finished,
                'setup_hours' => isset($agg['total_setup_sec']) ? round($agg['total_setup_sec'] / 3600, 1) : 0,
                'prod_hours' => isset($agg['total_prod_sec']) ? round($agg['total_prod_sec'] / 3600, 1) : 0,
                'gear_spec' => $gear_spec,
                'face_info' => $face_info_str,
                'machine' => $display_machine,
                'report_date' => $display_date,
                'logs' => $logs,
                'pti01_ps' => $info['pti01_ps'],
                '1_side' => $info['1_side'],
                'PS2' => $info['PS2'],
                'is_report_list' => true, // 統一使用列表模式渲染
                'report_source' => 'NORMAL',
                'report_id' => 0
            ];
        }

        echo json_encode(['success' => true, 'data' => $results, 'machine_types' => $machine_types]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// =================================================================================
// 後端邏輯：搜尋補加工 BOM (Partial Mode)
// =================================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'search_partial_boms') {
    header('Content-Type: application/json');
    $term = $_POST['term'] ?? '';
    if (empty($term)) {
        echo json_encode(['success' => false, 'message' => '請輸入搜尋關鍵字']);
        exit;
    }
    try {
        $sql = "
            SELECT bi.*, b.d_id, b.Client_Name, pn.ProcessName, pn.process_type_id, ml.maker_id as maker_name
            FROM bom_ing bi
            LEFT JOIN bom b ON bi.bom = b.bom
            LEFT JOIN process_no pn ON bi.process_no = pn.ProcessNo
            LEFT JOIN maker_list ml ON bi.maker_id_no = ml.maker_id_no
            WHERE (bi.bom LIKE ? OR b.d_id LIKE ? OR b.Client_Name LIKE ?)
            AND pn.process_type_id IN (SELECT process_type_id FROM process_type)
            ORDER BY bi.Modified_At DESC LIMIT 100
        ";
        $stmt = $pdo->prepare($sql);
        $like = "%$term%";
        $stmt->execute([$like, $like, $like]);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 補充統計資料 (與 search_finished_tasks 相同邏輯)
        foreach ($results as &$row) {
            $stmt_stats = $pdo->prepare("
                SELECT 
                    SUM(pdr.produced_qty) AS total_ok_qty,
                    (SELECT SUM(ng_qty) FROM pm_process_daily_ng WHERE report_id IN (SELECT report_id FROM pm_process_daily_report WHERE bom_ing_fid = ?)) AS total_ng_qty
                FROM pm_process_daily_report pdr
                WHERE pdr.bom_ing_fid = ?
             ");
            $stmt_stats->execute([$row['bom_ing_fid'], $row['bom_ing_fid']]);
            $stats = $stmt_stats->fetch(PDO::FETCH_ASSOC);
            $row['total_ok_qty'] = $stats['total_ok_qty'] ?? 0;
            $row['total_ng_qty'] = $stats['total_ng_qty'] ?? 0;
        }

        echo json_encode(['success' => true, 'data' => $results]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// =================================================================================
// 後端邏輯：管理異常類型 (新增/刪除)
// =================================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'manage_abnormal_type') {
    header('Content-Type: application/json');
    try {
        if (!$can_settings) {
            throw new Exception("無權限執行此操作 (需 A 權限)");
        }

        $type = $_POST['type']; // 'add' or 'delete'

        if ($type === 'add') {
            $name = trim($_POST['abnormal_name']);
            if (empty($name)) throw new Exception("異常名稱不可為空");

            $stmt = $pdo->prepare("INSERT INTO abnormal_type (abnormal_name, is_stop_machine, sort_order) VALUES (?, 1, 1)");
            $stmt->execute([$name]);
            echo json_encode(['success' => true, 'message' => '新增成功', 'id' => $pdo->lastInsertId(), 'name' => $name]);
        } elseif ($type === 'delete') {
            $id = $_POST['id'];
            $stmt = $pdo->prepare("DELETE FROM abnormal_type WHERE abnormal_type_id = ?");
            $stmt->execute([$id]);
            echo json_encode(['success' => true, 'message' => '刪除成功']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// =================================================================================
// 後端邏輯：獲取機台異常資訊 (Open案件 + 歷史紀錄)
// =================================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_machine_abnormal_info') {
    header('Content-Type: application/json');
    $machine_id = $_POST['machine_id'];

    // 1. 獲取未結案 (OPEN)
    $stmt_open = $pdo->prepare("SELECT m.*, t.abnormal_name, u.user_cname FROM pm_machine_abnormal m LEFT JOIN abnormal_type t ON m.abnormal_type_id = t.abnormal_type_id LEFT JOIN user u ON m.Created_By = u.id WHERE m.machine_id = ? AND m.handle_status != 'CLOSED' ORDER BY m.abnormal_start_time DESC");
    $stmt_open->execute([$machine_id]);

    // 2. 獲取歷史紀錄 (最近 10 筆)
    $stmt_history = $pdo->prepare("SELECT m.*, t.abnormal_name, u.user_cname FROM pm_machine_abnormal m LEFT JOIN abnormal_type t ON m.abnormal_type_id = t.abnormal_type_id LEFT JOIN user u ON m.Created_By = u.id WHERE m.machine_id = ? ORDER BY m.abnormal_start_time DESC LIMIT 10");
    $stmt_history->execute([$machine_id]);

    echo json_encode(['success' => true, 'open_cases' => $stmt_open->fetchAll(PDO::FETCH_ASSOC), 'history' => $stmt_history->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

// =================================================================================
// 後端邏輯：提交機台異常通報 (新增/修改/結案)
// =================================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_machine_abnormal') {
    header('Content-Type: application/json');
    try {
        if (!$can_report) {
            throw new Exception("無權限執行此操作 (需 C, U 或 A 權限)");
        }

        $machine_id = $_POST['machine_id'];
        $sub_action = $_POST['sub_action'] ?? '';
        $abnormal_id = !empty($_POST['abnormal_id']) ? $_POST['abnormal_id'] : null;
        $ab_type_id = $_POST['abnormal_type_id'];
        $ab_desc = $_POST['abnormal_desc'] ?? '';
        $ab_start = !empty($_POST['abnormal_start_time']) ? str_replace('T', ' ', $_POST['abnormal_start_time']) : null;
        $ab_end = !empty($_POST['abnormal_end_time']) ? str_replace('T', ' ', $_POST['abnormal_end_time']) : null;
        // 優先使用 POST 傳入的 ID，其次 Session user_id，再其次 Session id，最後才用 System
        $created_by = !empty($_POST['user_id']) ? $_POST['user_id'] : ($_SESSION['user_id'] ?? ($_SESSION['id'] ?? 'System'));

        // --- 處理進度回報 ---
        if ($sub_action === 'progress') {
            if (empty($abnormal_id)) throw new Exception("異常 ID 遺失");
            $action_time = !empty($_POST['action_time']) ? str_replace('T', ' ', $_POST['action_time']) : date('Y-m-d H:i:s');
            $action_desc = $_POST['action_desc'] ?? '';

            // 獲取目前說明並追加
            $stmt_get = $pdo->prepare("SELECT abnormal_desc FROM pm_machine_abnormal WHERE abnormal_id = ?");
            $stmt_get->execute([$abnormal_id]);
            $curr = $stmt_get->fetch(PDO::FETCH_ASSOC);
            $new_desc = $curr['abnormal_desc'] . "\n" . "[$action_time] 進度: " . $action_desc;

            $stmt = $pdo->prepare("UPDATE pm_machine_abnormal SET abnormal_desc = ? WHERE abnormal_id = ?");
            $stmt->execute([$new_desc, $abnormal_id]);
            echo json_encode(['success' => true, 'message' => '進度已更新']);
            exit;
        }
        // --- 處理結案 ---
        elseif ($sub_action === 'close') {
            if (empty($abnormal_id)) throw new Exception("異常 ID 遺失");
            $action_time = !empty($_POST['action_time']) ? str_replace('T', ' ', $_POST['action_time']) : date('Y-m-d H:i:s');
            $action_desc = $_POST['action_desc'] ?? '';

            $stmt_get = $pdo->prepare("SELECT abnormal_desc FROM pm_machine_abnormal WHERE abnormal_id = ?");
            $stmt_get->execute([$abnormal_id]);
            $curr = $stmt_get->fetch(PDO::FETCH_ASSOC);
            $new_desc = $curr['abnormal_desc'] . "\n" . "[$action_time] 結案: " . $action_desc;

            $stmt = $pdo->prepare("UPDATE pm_machine_abnormal SET abnormal_desc = ?, abnormal_end_time = ?, handle_status = 'CLOSED' WHERE abnormal_id = ?");
            $stmt->execute([$new_desc, $action_time, $abnormal_id]);
            echo json_encode(['success' => true, 'message' => '異常已結案']);
            exit;
        }

        if (empty($machine_id)) throw new Exception("機台 ID 遺失");
        if (empty($ab_type_id)) throw new Exception("請選擇異常類型");
        if (empty($ab_start)) throw new Exception("請填寫異常開始時間");

        // 狀態判斷：若有結束時間則 CLOSED，否則 OPEN
        $handle_status = (!empty($ab_end)) ? 'CLOSED' : 'OPEN';

        if ($abnormal_id) {
            // 更新
            $sql = "UPDATE pm_machine_abnormal SET abnormal_type_id = ?, abnormal_desc = ?, abnormal_start_time = ?, abnormal_end_time = ?, handle_status = ? WHERE abnormal_id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$ab_type_id, $ab_desc, $ab_start, $ab_end, $handle_status, $abnormal_id]);
        } else {
            // 新增
            // 檢查是否有未結案的異常 (同一機台)
            $stmt_check = $pdo->prepare("SELECT abnormal_id FROM pm_machine_abnormal WHERE machine_id = ? AND handle_status != 'CLOSED'");
            $stmt_check->execute([$machine_id]);
            if ($stmt_check->fetch()) {
                throw new Exception("該機台已有未結案的異常，請先結案後再通報新的異常。");
            }

            $sql = "INSERT INTO pm_machine_abnormal (machine_id, abnormal_type_id, abnormal_desc, abnormal_start_time, abnormal_end_time, handle_status, Created_By) VALUES (?, ?, ?, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$machine_id, $ab_type_id, $ab_desc, $ab_start, $ab_end, $handle_status, $created_by]);
        }

        echo json_encode(['success' => true, 'message' => '異常通報已儲存']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// =================================================================================
// 後端邏輯：更新到料狀態 (bom_ing.Order_id)
// =================================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_material_status') {
    header('Content-Type: application/json');
    try {
        if (!$can_manage_material) {
            throw new Exception("無權限執行此操作");
        }
        $fid = $_POST['bom_ing_fid'];
        $status = $_POST['status']; // 1 or 0/empty
        $val = ($status == '1') ? 1 : null;

        $pdo->prepare("UPDATE bom_ing SET Order_id = ? WHERE bom_ing_fid = ?")->execute([$val, $fid]);
        echo json_encode(['success' => true, 'message' => '到料狀態已更新']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}
// =================================================================================
// 後端邏輯：獲取單筆報工詳細資料 (用於編輯)
// =================================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_report_detail') {
    header('Content-Type: application/json');
    try {
        $report_id = $_POST['report_id'];
        $stmt = $pdo->prepare("SELECT * FROM pm_process_daily_report WHERE report_id = ?");
        $stmt->execute([$report_id]);
        $report = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$report) throw new Exception("找不到該筆報工資料");

        // Get NG details
        $stmt_ng = $pdo->prepare("SELECT * FROM pm_process_daily_ng WHERE report_id = ?");
        $stmt_ng->execute([$report_id]);
        $ng_list = $stmt_ng->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'report' => $report, 'ng_list' => $ng_list]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// =================================================================================
// 後端邏輯：獲取單筆報工詳細資料 (用於編輯)
// =================================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_report_detail') {
    header('Content-Type: application/json');
    try {
        $report_id = $_POST['report_id'];
        // Join tables to get all necessary info
        $stmt = $pdo->prepare("
            SELECT r.*, bi.bom, b.d_id, b.Client_Name, bi.sqty, pn.ProcessName
            FROM pm_process_daily_report r
            LEFT JOIN bom_ing bi ON r.bom_ing_fid = bi.bom_ing_fid
            LEFT JOIN bom b ON bi.bom = b.bom
            LEFT JOIN process_no pn ON r.process_no = pn.ProcessNo
            WHERE r.report_id = ?
        ");
        $stmt->execute([$report_id]);
        $report = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$report) throw new Exception("找不到該筆報工資料");

        // Get NG details
        $stmt_ng = $pdo->prepare("SELECT * FROM pm_process_daily_ng WHERE report_id = ?");
        $stmt_ng->execute([$report_id]);
        $ng_list = $stmt_ng->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'report' => $report, 'ng_list' => $ng_list]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// =================================================================================
// 後端邏輯：處理現場每日回報 (含 NG 明細)
// =================================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_daily_report') {
    header('Content-Type: application/json');

    if (!$can_report) {
        echo json_encode(['success' => false, 'message' => '無權限執行此操作 (需 C, U 或 A 權限)']);
        exit;
    }

    $pdo->beginTransaction();
    try {
        $fid = $_POST['bom_ing_fid'];
        $report_id = !empty($_POST['report_id']) ? $_POST['report_id'] : null;
        $process_no = $_POST['process_no'];
        $report_date = $_POST['report_date'];
        $machine_id = $_POST['machine_id'];
        $is_finished_check = isset($_POST['is_finished']) ? 1 : 0;
        $remark = $_POST['remark'];
        $process_face = !empty($_POST['process_face']) ? $_POST['process_face'] : null;
        $is_face_finished_check = isset($_POST['is_face_finished']) ? 1 : 0;

        $is_finished = 0;
        if ($is_finished_check) {
            $is_finished = 1;
        } elseif ($is_face_finished_check) {
            $is_finished = 2;
        }
        $created_by = $_SESSION['user_id'] ?? $_SESSION['id'] ?? 'System'; // 修正：優先使用 user_id，若無則使用 id
        $setup_user = !empty($_POST['setup_user_id']) ? $_POST['setup_user_id'] : null;
        $setup_start = !empty($_POST['setup_start_time']) ? str_replace('T', ' ', $_POST['setup_start_time']) : null;
        $setup_end = !empty($_POST['setup_end_time']) ? str_replace('T', ' ', $_POST['setup_end_time']) : null;
        $prod_user = !empty($_POST['production_user_id']) ? $_POST['production_user_id'] : null;
        $prod_start = !empty($_POST['production_start_time']) ? str_replace('T', ' ', $_POST['production_start_time']) : null;
        $prod_end = !empty($_POST['production_end_time']) ? str_replace('T', ' ', $_POST['production_end_time']) : null;
        $report_source = $_POST['report_source'] ?? 'NORMAL';
        $source_reason = $_POST['source_reason'] ?? null;

        // 自動計算 report_date：取架機開始與生產開始的最早日期
        $timestamps_for_date = [];
        if ($setup_start) {
            $timestamps_for_date[] = strtotime($setup_start);
        }
        if ($prod_start) {
            $timestamps_for_date[] = strtotime($prod_start);
        }
        if (!empty($timestamps_for_date)) {
            $report_date = date('Y-m-d', min($timestamps_for_date));
        }

        // --- 新增：後端驗證總數量 ---
        $produced_qty = !empty($_POST['produced_qty']) ? (int)$_POST['produced_qty'] : 0;
        $ng_qtys = $_POST['ng_qty'] ?? [];
        $total_ng_qty = 0;
        if (is_array($ng_qtys)) {
            foreach ($ng_qtys as $qty) {
                $total_ng_qty += (int)$qty;
            }
        }

        // 修改：只有在選擇了生產人員時，才強制檢查數量 (架機可不填)
        if (!empty($prod_user) && $produced_qty <= 0 && $total_ng_qty <= 0) {
            throw new Exception("良品數與NG數不可皆為0或空白。");
        }

        // --- 時間邏輯驗證 ---
        $current_timestamp = time();
        $allowed_future_buffer = 60; // 允許 1 分鐘的時間誤差，避免客戶端時間略快於伺服器導致無法提交

        // 1. 架機時間驗證
        if ($setup_start && $setup_end) {
            if (strtotime($setup_end) <= strtotime($setup_start)) throw new Exception("架機結束時間必須大於開始時間");
            if (strtotime($setup_end) > $current_timestamp + $allowed_future_buffer) throw new Exception("架機結束時間不可晚於目前時間");
        }
        // 2. 生產時間驗證
        if ($prod_start && $prod_end) {
            if (strtotime($prod_end) <= strtotime($prod_start)) throw new Exception("生產結束時間必須大於開始時間");
            if (strtotime($prod_end) > $current_timestamp + $allowed_future_buffer) throw new Exception("生產結束時間不可晚於目前時間");
        }
        // 3. 本次架機與生產重疊驗證
        if ($setup_user && $prod_user && $setup_start && $setup_end && $prod_start && $prod_end) {
            if (max(strtotime($setup_start), strtotime($prod_start)) < min(strtotime($setup_end), strtotime($prod_end))) {
                throw new Exception("本次填寫的架機時間與生產時間重疊，請修正");
            }
        }
        // 4. 與資料庫現有資料重疊驗證 (針對生產與架機時間)
        if (($setup_start && $setup_end) || ($prod_start && $prod_end)) {
            // 修改：檢查同一機台的所有報工 (machine_id)，防止同一機台時間重疊
            $sql_check = "SELECT r.report_id, r.setup_start_time, r.setup_end_time, r.production_start_time, r.production_end_time, b.bom, pn.ProcessName 
                          FROM pm_process_daily_report r
                          LEFT JOIN bom_ing bi ON r.bom_ing_fid = bi.bom_ing_fid
                          LEFT JOIN bom b ON bi.bom = b.bom
                          LEFT JOIN process_no pn ON r.process_no = pn.ProcessNo
                          WHERE r.machine_id = ?";
            $params_check = [$machine_id];

            // 優化：加上時間篩選 (往前推1天)
            $min_start_ts = null;
            if ($setup_start) $min_start_ts = strtotime($setup_start);
            if ($prod_start) {
                $p_ts = strtotime($prod_start);
                if ($min_start_ts === null || $p_ts < $min_start_ts) $min_start_ts = $p_ts;
            }
            if ($min_start_ts !== null) {
                $filter_date = date('Y-m-d H:i:s', $min_start_ts - 86400);
                $sql_check .= " AND ((r.setup_end_time > ?) OR (r.production_end_time > ?))";
                $params_check[] = $filter_date;
                $params_check[] = $filter_date;
            }

            // 若為編輯模式，排除自身
            if ($report_id) {
                $sql_check .= " AND report_id != ?";
                $params_check[] = $report_id;
            }
            $stmt_check = $pdo->prepare($sql_check);
            $stmt_check->execute($params_check);
            $existing_reports = $stmt_check->fetchAll(PDO::FETCH_ASSOC);

            $new_s_start = ($setup_start) ? strtotime($setup_start) : 0;
            $new_s_end   = ($setup_end)   ? strtotime($setup_end)   : 0;
            $new_p_start = ($prod_start)  ? strtotime($prod_start)  : 0;
            $new_p_end   = ($prod_end)    ? strtotime($prod_end)    : 0;

            foreach ($existing_reports as $ex) {
                $ex_s_start = $ex['setup_start_time'] ? strtotime($ex['setup_start_time']) : 0;
                $ex_s_end   = $ex['setup_end_time']   ? strtotime($ex['setup_end_time'])   : 0;
                $ex_p_start = $ex['production_start_time'] ? strtotime($ex['production_start_time']) : 0;
                $ex_p_end   = $ex['production_end_time']   ? strtotime($ex['production_end_time'])   : 0;

                $conflict_info = " (衝突工單: " . ($ex['bom'] ?? '未知/臨時') . " - " . ($ex['ProcessName'] ?? '未知') . ")";

                // 檢查本次架機時間是否重疊
                if ($new_s_start && $new_s_end) {
                    if ($ex_s_start && $ex_s_end && max($new_s_start, $ex_s_start) < min($new_s_end, $ex_s_end)) throw new Exception("架機時間與機台現有報工(架機)重疊" . $conflict_info);
                    if ($ex_p_start && $ex_p_end && max($new_s_start, $ex_p_start) < min($new_s_end, $ex_p_end)) throw new Exception("架機時間與機台現有報工(生產)重疊" . $conflict_info);
                }
                // 檢查本次生產時間是否重疊
                if ($new_p_start && $new_p_end) {
                    if ($ex_s_start && $ex_s_end && max($new_p_start, $ex_s_start) < min($new_p_end, $ex_s_end)) throw new Exception("生產時間與機台現有報工(架機)重疊" . $conflict_info);
                    if ($ex_p_start && $ex_p_end && max($new_p_start, $ex_p_start) < min($new_p_end, $ex_p_end)) throw new Exception("生產時間與機台現有報工(生產)重疊" . $conflict_info);
                }
            }
        }

        // 5. 機台異常期間驗證 (不可在異常期間報工)
        if ($setup_start || $prod_start) {
            // 找出本次報工涵蓋的時間範圍，用於 SQL 初步篩選
            $check_times = [];
            if ($setup_start) $check_times[] = strtotime($setup_start);
            if ($setup_end) $check_times[] = strtotime($setup_end);
            if ($prod_start) $check_times[] = strtotime($prod_start);
            if ($prod_end) $check_times[] = strtotime($prod_end);

            if (!empty($check_times)) {
                $min_rpt = min($check_times);
                // 查詢條件：異常結束時間(或現在) > 報工範圍開始
                // 且 異常開始時間 < 報工範圍結束 (這部分在 PHP 內判斷)
                $stmt_ab = $pdo->prepare("
                    SELECT abnormal_id, abnormal_start_time, abnormal_end_time, handle_status 
                    FROM pm_machine_abnormal 
                    WHERE machine_id = ? 
                    AND (
                        (handle_status = 'CLOSED' AND abnormal_end_time > ?) 
                        OR 
                        (handle_status IS NULL OR handle_status != 'CLOSED')
                    )
                ");
                $stmt_ab->execute([$machine_id, date('Y-m-d H:i:s', $min_rpt)]);
                $abnormalities = $stmt_ab->fetchAll(PDO::FETCH_ASSOC);

                foreach ($abnormalities as $ab) {
                    $ab_start = strtotime($ab['abnormal_start_time']);
                    // 若未結案，視為持續到未來 (無限大)，確保覆蓋任何嘗試報工的時間
                    $ab_end = ($ab['handle_status'] === 'CLOSED' && !empty($ab['abnormal_end_time']))
                        ? strtotime($ab['abnormal_end_time'])
                        : PHP_INT_MAX;

                    // 檢查架機
                    if ($setup_start) {
                        $s_start = strtotime($setup_start);
                        $s_end = ($setup_end) ? strtotime($setup_end) : PHP_INT_MAX;
                        if (max($s_start, $ab_start) < min($s_end, $ab_end)) {
                            throw new Exception("架機時間與機台異常紀錄重疊 (異常ID: {$ab['abnormal_id']})");
                        }
                    }
                    // 檢查生產
                    if ($prod_start) {
                        $p_start = strtotime($prod_start);
                        $p_end = ($prod_end) ? strtotime($prod_end) : PHP_INT_MAX;
                        if (max($p_start, $ab_start) < min($p_end, $ab_end)) {
                            throw new Exception("生產時間與機台異常紀錄重疊 (異常ID: {$ab['abnormal_id']})");
                        }
                    }
                }
            }
        }

        // 1. 寫入/更新主表 pm_process_daily_report
        if ($report_id) {
            $stmt = $pdo->prepare("
                UPDATE pm_process_daily_report 
                SET report_date=?, machine_id=?, setup_user_id=?, setup_start_time=?, setup_end_time=?, 
                    production_user_id=?, production_start_time=?, production_end_time=?, produced_qty=?, 
                    is_finished=?, remark=?, Created_By=?, report_source=?, source_reason=?, process_face=? 
                WHERE report_id=? 
            ");
            $stmt->execute([$report_date, $machine_id, $setup_user, $setup_start, $setup_end, $prod_user, $prod_start, $prod_end, $produced_qty, $is_finished, $remark, $created_by, $report_source, $source_reason, $process_face, $report_id]);
            // 編輯時先清除舊的 NG 明細，稍後重寫
            $pdo->prepare("DELETE FROM pm_process_daily_ng WHERE report_id = ?")->execute([$report_id]);
        } else {
            // 若為 TEMP 模式，fid 可能為空，需允許 NULL
            $fid_val = ($fid === '' || $fid === 'null') ? null : $fid;
            $stmt = $pdo->prepare("
                INSERT INTO pm_process_daily_report
                (bom_ing_fid, process_no, report_date, machine_id, setup_user_id, setup_start_time, setup_end_time, production_user_id, production_start_time, production_end_time, produced_qty, is_finished, remark, Created_By, report_source, source_reason, process_face)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$fid_val, $process_no, $report_date, $machine_id, $setup_user, $setup_start, $setup_end, $prod_user, $prod_start, $prod_end, $produced_qty, $is_finished, $remark, $created_by, $report_source, $source_reason, $process_face]);
            $report_id = $pdo->lastInsertId();
        }

        // 2. 寫入 NG 明細 pm_process_daily_ng
        if (!empty($_POST['ng_id']) && is_array($_POST['ng_id'])) {
            $stmt_ng = $pdo->prepare("INSERT INTO pm_process_daily_ng (report_id, ng_id, ng_qty, ng_remark, Created_By) VALUES (?, ?, ?, ?, ?)");
            foreach ($_POST['ng_id'] as $key => $ng_val) {
                if (!empty($ng_val)) {
                    $ng_qty = $_POST['ng_qty'][$key] ?? 0;
                    $ng_remark = $_POST['ng_remark'][$key] ?? '';
                    $stmt_ng->execute([$report_id, $ng_val, $ng_qty, $ng_remark, $created_by]);
                }
            }
        }

        // 3. 更新 bom_ing 狀態 (NORMAL 與 PARTIAL 模式且有 fid)
        if (in_array($report_source, ['NORMAL', 'PARTIAL']) && !empty($fid)) {
            if ($is_finished == 1) {
                // 完工後，狀態變更為 QC 待驗，並從機台上移除 (machine_id = NULL)
                $stmt_update = $pdo->prepare("UPDATE bom_ing SET processing_state = 'Q', machine_id = NULL WHERE bom_ing_fid = ?");
                $stmt_update->execute([$fid]);
            } else {
                // 未完工，狀態為加工中，並將其指派到最新報工的機台
                $stmt_update = $pdo->prepare("UPDATE bom_ing SET processing_state = 'ing', machine_id = ? WHERE bom_ing_fid = ?");
                $stmt_update->execute([$machine_id, $fid]);
            }
        }

        // 4. 更新 bom_ing 額外欄位 (pti01_ps, 1_side, PS2)
        $extra_updates = [];
        $extra_params = [];

        if ($can_edit_pti01) {
            if (isset($_POST['pti01_ps'])) {
                $extra_updates[] = "pti01_ps = ?";
                $extra_params[] = $_POST['pti01_ps'];
            }
        }
        if ($can_edit_ps2 && isset($_POST['PS2'])) {
            $extra_updates[] = "PS2 = ?";
            $extra_params[] = $_POST['PS2'];
        }

        if (!empty($extra_updates) && !empty($fid)) {
            $sql_ex = "UPDATE bom_ing SET " . implode(', ', $extra_updates) . " WHERE bom_ing_fid = ?";
            $extra_params[] = $fid;
            $pdo->prepare($sql_ex)->execute($extra_params);
        }

        $pdo->commit();
        echo json_encode(['success' => true, 'message' => '資料已更新']);
    } catch (Exception $e) {
        $pdo->rollBack();
        $error_msg = $e->getMessage();
        echo json_encode(['success' => false, 'message' => '更新失敗: ' . $error_msg]);
    }
    exit;
}

// =================================================================================
// 後端邏輯：刪除拆分工單 (Delete Split Task)
// =================================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_split_task') {
    header('Content-Type: application/json');
    try {
        if (!$has_A && !$has_D) { // 需有 A 或 D 權限
            throw new Exception("無權限執行此操作");
        }

        $fid = $_POST['bom_ing_fid'];
        // 刪除 bom_ing (僅限拆分工單，前端已做初步判斷，後端可再檢查 ps 確保安全，這裡直接刪除)
        // 若要嚴謹，可檢查 ps 是否包含 '(拆分工單)'
        $pdo->prepare("DELETE FROM bom_ing WHERE bom_ing_fid = ? AND ps LIKE '%(拆分工單)%'")->execute([$fid]);

        echo json_encode(['success' => true, 'message' => '拆分工單已刪除']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// =================================================================================
// 後端邏輯：更新備註 (生管專用 / 生產自動儲存用)
// =================================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_task_remarks') {
    header('Content-Type: application/json');
    try {
        // 權限檢查：C, U, A 皆可執行此動作 (視欄位而定，前端會控制顯示)
        if (!$can_drag && !$has_C) { // can_drag 包含 A, C, U
            throw new Exception("無權限執行此操作");
        }

        $fid = $_POST['bom_ing_fid'];
        $pti01_ps = $_POST['pti01_ps'] ?? null;
        $ps2 = $_POST['PS2'] ?? null;

        // 構建更新 SQL
        $updates = [];
        $params = [];

        if (isset($_POST['pti01_ps'])) {
            $updates[] = "pti01_ps = ?";
            $params[] = $pti01_ps;
        }
        if (isset($_POST['PS2'])) {
            $updates[] = "PS2 = ?";
            $params[] = $ps2;
        }

        if (!empty($updates)) {
            $sql = "UPDATE bom_ing SET " . implode(', ', $updates) . " WHERE bom_ing_fid = ?";
            $params[] = $fid;
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
        }

        echo json_encode(['success' => true, 'message' => '備註已更新']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// =================================================================================
// 後端邏輯：儲存生產部門設定
// =================================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_department_setting') {
    header('Content-Type: application/json');
    try {
        if (!$can_settings) {
            throw new Exception("無權限執行此操作 (需 A 權限)");
        }

        $dept_id = $_POST['department_id'];
        $daily_hours = !empty($_POST['daily_working_hours']) ? (float)$_POST['daily_working_hours'] : 8;

        // 1. 儲存部門設定
        $param_value = json_encode((int)$dept_id);
        $sql = "INSERT INTO system_parameters (param_group, param_key, param_value, description) 
                VALUES ('process_schedule', 'department_setting', ?, '生產部門設定') 
                ON DUPLICATE KEY UPDATE param_value = VALUES(param_value)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$param_value]);

        // 2. 儲存每日工時設定
        $param_value_hours = json_encode($daily_hours);
        $sql_hours = "INSERT INTO system_parameters (param_group, param_key, param_value, description) 
                VALUES ('process_schedule', 'daily_working_hours', ?, '每日工時設定') 
                ON DUPLICATE KEY UPDATE param_value = VALUES(param_value)";
        $stmt_hours = $pdo->prepare($sql_hours);
        $stmt_hours->execute([$param_value_hours]);

        echo json_encode(['success' => true, 'message' => '部門與工時設定已儲存！']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => '儲存失敗: ' . $e->getMessage()]);
    }
    exit;
}

// =================================================================================
// 後端邏輯：儲存齒輪規格 (從現場回報視窗呼叫)
// =================================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_part_gear_info') {
    header('Content-Type: application/json');
    try {
        // 權限檢查：C, U, A 皆可
        if (!$can_report && !$can_settings) {
            throw new Exception("無權限執行此操作");
        }

        $d_id = $_POST['d_id'];
        $part_no = $_POST['part_no'] ?? '';
        $client_name = $_POST['client_name'] ?? '';
        $gears = isset($_POST['gears']) ? json_decode($_POST['gears'], true) : [];
        $user_id = $_SESSION['user_id'] ?? 'System';

        if (empty($d_id)) {
            if (empty($part_no)) throw new Exception("料號 ID 遺失且無料號名稱");

            // 檢查是否已存在 (避免重複)
            $stmt_chk = $pdo->prepare("SELECT d_id FROM d_setting WHERE D_Setting_Id = ?");
            $stmt_chk->execute([$part_no]);
            $exist_id = $stmt_chk->fetchColumn();

            if ($exist_id) {
                $d_id = $exist_id;
            } else {
                // 查詢 Customer_Id
                $customer_id = null;
                if (!empty($client_name)) {
                    $stmt_cust = $pdo->prepare("SELECT customer_id FROM customer_list WHERE customer = ?");
                    $stmt_cust->execute([$client_name]);
                    $customer_id = $stmt_cust->fetchColumn();
                }

                // Drawing_No 不自動帶入料號（沒有圖面代號就留空）
                $stmt_ins = $pdo->prepare("INSERT INTO d_setting (D_Setting_Id, Customer_Id, Type, Created_By, Created_At) VALUES (?, ?, 'G', ?, NOW())");
                $stmt_ins->execute([$part_no, $customer_id, $user_id]);
                $d_id = $pdo->lastInsertId();
            }
        }

        $pdo->beginTransaction();

        // 先刪除舊資料
        $pdo->prepare("DELETE FROM d_setting_gear WHERE d_setting_id = ?")->execute([$d_id]);

        if (!empty($gears)) {
            // 任務 3: 儲存資料 (包含新欄位)
            $sql_gear = "INSERT INTO d_setting_gear (d_setting_id, Module, Teeth, Face_Width, Helix_Angle, Pressure_Angle, Workpiece_Length, Gear_Type, Spec_No, Remark_Gear, Created_By, Helix_Direction, Profile_Shift_X, Helix_Angle_Str) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt_gear = $pdo->prepare($sql_gear);
            foreach ($gears as $g) {
                // Helper to convert empty string to null
                $v = function ($k) use ($g) {
                    return (isset($g[$k]) && $g[$k] !== '') ? $g[$k] : null;
                };

                $stmt_gear->execute([
                    $d_id,
                    $v('Module'),
                    $v('Teeth'),
                    $v('Face_Width'),
                    $v('Helix_Angle'),
                    $v('Pressure_Angle'),
                    $v('Workpiece_Length'),
                    $v('Gear_Type'),
                    $v('Spec_No'),
                    $v('Remark_Gear'),
                    $user_id,
                    $v('Helix_Direction'),
                    $v('Profile_Shift_X'),
                    $v('Helix_Angle_Str')
                ]);
            }
        }

        $pdo->commit();
        echo json_encode(['success' => true, 'message' => '齒輪規格已更新']);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => '儲存失敗: ' . $e->getMessage()]);
    }
    exit;
}

// =================================================================================
// 後端邏輯：獲取機台每日時程表 (Timeline)
// =================================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_machine_daily_schedule') {
    header('Content-Type: application/json');
    try {
        $date = $_POST['date'] ?? date('Y-m-d');
        $machine_type_id = $_POST['machine_type_id'] ?? '';
        $start_ts = $date . ' 00:00:00';
        $end_ts = $date . ' 23:59:59';

        // Fetch all machines
        $sql_machines = "SELECT machine_id, machine, position, field_no, asset_no, machine_model FROM machine_list WHERE (state IS NULL OR state != '1')";
        $params_machines = [];
        if (!empty($machine_type_id)) {
            $sql_machines .= " AND machine_type_id = ?";
            $params_machines[] = $machine_type_id;
        }
        $sql_machines .= " ORDER BY position, machine_type_id, machine";

        $stmt_machines = $pdo->prepare($sql_machines);
        $stmt_machines->execute($params_machines);
        $machines = $stmt_machines->fetchAll(PDO::FETCH_ASSOC);

        // Fetch reports overlapping with the day
        $sql = "
            SELECT r.report_id, r.machine_id,
                   r.setup_start_time, r.setup_end_time, r.production_start_time, r.production_end_time,
                   r.produced_qty,
                   (SELECT SUM(ng_qty) FROM pm_process_daily_ng WHERE report_id = r.report_id) as total_ng,
                   b.bom, b.d_id, pn.ProcessName, r.bom_ing_fid
            FROM pm_process_daily_report r
            LEFT JOIN bom_ing bi ON r.bom_ing_fid = bi.bom_ing_fid
            LEFT JOIN bom b ON bi.bom = b.bom
            LEFT JOIN process_no pn ON r.process_no = pn.ProcessNo
            WHERE r.machine_id IS NOT NULL
              AND (
                  (r.setup_start_time <= ? AND r.setup_end_time >= ?)
                  OR
                  (r.production_start_time <= ? AND r.production_end_time >= ?)
              )
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$end_ts, $start_ts, $end_ts, $start_ts]);
        $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'machines' => $machines, 'reports' => $reports]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// =================================================================================
// 後端邏輯：拆分製程 (Split Task) - 用於多機台同時加工
// =================================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'split_task') {
    header('Content-Type: application/json');
    try {
        if (!$has_A && !$has_C && !$has_U) { // 需有 A, C 或 U 權限
            throw new Exception("無權限執行此操作 (需要新增或更新權限)");
        }

        $fid = $_POST['bom_ing_fid'];

        // 1. 取得原資料
        $stmt = $pdo->prepare("SELECT * FROM bom_ing WHERE bom_ing_fid = ?");
        $stmt->execute([$fid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) throw new Exception("找不到原始資料");

        // 2. 準備新資料
        // 產生新的 bom_ing_id (避免重複 key)
        $new_bom_ing_id = substr($row['bom_ing_id'], 0, 25) . 'S' . rand(10, 99);

        // 移除主鍵 (讓 DB 自動產生)
        unset($row['bom_ing_fid']);

        $row['bom_ing_id'] = $new_bom_ing_id;
        $row['machine_id'] = null; // 重置為未指派
        $row['processing_state'] = 'ing'; // 狀態為加工中(待指派)
        $row['ps'] = ($row['ps'] ? $row['ps'] . ' ' : '') . '(拆分工單)'; // 標記為拆分
        $row['is_schedule_split'] = 1; // 標記為報工排程拆分，不列入BOM製程顯示
        $row['Created_At'] = date('Y-m-d H:i:s');
        $row['Created_By'] = $_SESSION['user_id'] ?? $_SESSION['id'] ?? 'System';

        // 3. 插入新資料
        $cols = array_keys($row);
        $vals = array_values($row);
        $placeholders = implode(',', array_fill(0, count($cols), '?'));
        $col_names = implode(',', $cols);

        $sql = "INSERT INTO bom_ing ($col_names) VALUES ($placeholders)";
        $pdo->prepare($sql)->execute($vals);

        echo json_encode(['success' => true, 'message' => '製程已拆分，請至未指派區查看']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// =================================================================================
// 後端邏輯：從資料庫獲取排程資料
// =================================================================================

// 1. 定義基本查詢 (Fallback - 確保網頁至少能運作)
$sql_basic = "
    SELECT
        bi.bom_ing_fid,
        bi.bom,
        bi.process_no,
        bi.ProcessName,
        bi.process_type_id,
        bi.machine_id,
    origin_bi.processing_sequence,
        bi.processing_state,
        bi.ps,
        origin_bi.single_bet_ps,
        origin_bi.pti01_ps,
        origin_bi.Order_id,
        origin_bi.`1_side`,
        origin_bi.PS2,
        b.d_id,
        b.Client_Name,
        bi.sqty,
        b.priority_type,
        b.bom_ps,
        COALESCE(b.Delivery_date, ol.Delivery_date) AS shipping_date,
        dsg.Module,
        dsg.Teeth,
        dsg.Face_Width,
        dsg.Workpiece_Length
    FROM vw_bom_ing AS bi
    JOIN bom_ing AS origin_bi ON bi.bom_ing_fid = origin_bi.bom_ing_fid
    LEFT JOIN bom AS b ON bi.bom = b.bom
    LEFT JOIN order_list AS ol ON b.o_order_id = ol.Order_id
    LEFT JOIN maker_list AS ml ON bi.maker_id_no = ml.maker_id_no
    LEFT JOIN (
        SELECT D_Setting_Id, MAX(d_id) as max_did 
        FROM d_setting 
        GROUP BY D_Setting_Id
    ) ds_max ON b.d_id = ds_max.D_Setting_Id
    LEFT JOIN d_setting_gear dsg ON dsg.d_setting_id = ds_max.max_did
    WHERE
        bi.processing_state = 'ing' AND
        (b.processing_state <> 1 OR b.processing_state IS NULL) AND
        ml.internal = 1 AND
        NOT EXISTS (
            SELECT 1 FROM pm_process_daily_report r
            WHERE r.bom_ing_fid = bi.bom_ing_fid AND r.is_finished = 1
        )
        
    ORDER BY
    origin_bi.processing_sequence ASC,  /* 🌟 把排程順序移到第一位！ */
    bi.machine_id ASC,                  /* 接著再排機台 */
    bi.bom_ing_fid ASC;
";

// 2. 定義擴展查詢 (包含報表統計與人員 - 可能會失敗)
$sql_extended = "
SELECT
    bi.bom_ing_fid,
    bi.bom,
    bi.process_no,
    bi.ProcessName,
    bi.process_type_id,
    bi.machine_id,
    origin_bi.processing_sequence,
    bi.processing_state,
    bi.ps,
    origin_bi.single_bet_ps,
    origin_bi.pti01_ps,
    origin_bi.Order_id,
    origin_bi.`1_side`,
    origin_bi.PS2,
    b.d_id,
    b.Client_Name,
    bi.sqty,
    b.priority_type,
    b.bom_ps,
    COALESCE(b.Delivery_date, ol.Delivery_date) AS shipping_date,
    ds.Type as part_type,
    ds.d_id as ds_id,
    dsg.Module,
    dsg.Teeth,
    dsg.Face_Width,
    dsg.Workpiece_Length
FROM vw_bom_ing AS bi
JOIN bom_ing AS origin_bi ON bi.bom_ing_fid = origin_bi.bom_ing_fid
LEFT JOIN bom AS b ON bi.bom = b.bom
LEFT JOIN order_list AS ol ON b.o_order_id = ol.Order_id
LEFT JOIN maker_list AS ml ON bi.maker_id_no = ml.maker_id_no
LEFT JOIN (
    SELECT D_Setting_Id, MAX(d_id) as max_did 
    FROM d_setting 
    GROUP BY D_Setting_Id
) ds_max ON b.d_id = ds_max.D_Setting_Id
LEFT JOIN d_setting ds ON ds.d_id = ds_max.max_did
LEFT JOIN d_setting_gear dsg ON dsg.d_setting_id = ds_max.max_did
WHERE
    bi.processing_state = 'ing' AND
    (b.processing_state <> 1 OR b.processing_state IS NULL) AND
    ml.internal = 1 AND
    NOT EXISTS (
        SELECT 1 FROM pm_process_daily_report r
        WHERE r.bom_ing_fid = bi.bom_ing_fid AND r.is_finished = 1
    )
    
    ORDER BY
    origin_bi.processing_sequence ASC,  /* 🌟 把排程順序移到第一位！ */
    bi.machine_id ASC,                  /* 接著再排機台 */
    bi.bom_ing_fid ASC;
";

$tasks = [];
$db_error = null;

try {
    $stmt = $pdo->query($sql_extended);
    if ($stmt === false) {
        throw new Exception("SQL Error: " . implode(" ", $pdo->errorInfo()));
    }
    $tasks = $stmt->fetchAll();

    // Optimization: Fetch stats and operators separately to avoid slow subqueries
    $fids = array_column($tasks, 'bom_ing_fid');
    $stats_map = [];
    $operator_map = [];
    $faces_map = [];

    if (!empty($fids)) {
        $in_fids = implode(',', array_map('intval', array_unique($fids))); // 使用 unique fids

        // Fetch Stats
        $sql_stats = "
            SELECT
                pdr.bom_ing_fid,
                SUM(pdr.produced_qty) AS total_ok_qty,
                SUM(ng_sub.daily_ng_qty) AS total_ng_qty,
                MIN(CASE WHEN pdr.setup_start_time > '2000-01-01' THEN pdr.setup_start_time END) AS first_setup_start,
                MIN(CASE WHEN pdr.production_start_time > '2000-01-01' AND pdr.produced_qty > 0 THEN pdr.production_start_time END) AS first_prod_start,
                MIN(COALESCE(pdr.setup_start_time, pdr.production_start_time, pdr.report_date)) AS start_process_date,
                AVG(pdr.produced_qty) AS avg_daily_qty,
                COALESCE(SUM(TIMESTAMPDIFF(SECOND, pdr.production_start_time, pdr.production_end_time)), 0) AS total_prod_seconds,
                COUNT(DISTINCT pdr.report_date) AS worked_days_count
            FROM pm_process_daily_report pdr
            LEFT JOIN (
                SELECT report_id, SUM(ng_qty) as daily_ng_qty
                FROM pm_process_daily_ng
                GROUP BY report_id
            ) ng_sub ON pdr.report_id = ng_sub.report_id
            WHERE pdr.bom_ing_fid IN ($in_fids)
            GROUP BY pdr.bom_ing_fid
        ";
        $stats_rows = $pdo->query($sql_stats)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($stats_rows as $r) $stats_map[$r['bom_ing_fid']] = $r;

        // Fetch Operators (Latest)
        $sql_ops = "
            SELECT pdr.bom_ing_fid, u_prod.user_cname as prod_name, u_setup.user_cname as setup_name
            FROM pm_process_daily_report pdr
            LEFT JOIN user u_prod ON pdr.production_user_id = u_prod.id
            LEFT JOIN user u_setup ON pdr.setup_user_id = u_setup.id
            WHERE pdr.bom_ing_fid IN ($in_fids)
            ORDER BY pdr.report_date DESC, pdr.report_id DESC
        ";
        $ops_rows = $pdo->query($sql_ops)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($ops_rows as $r) {
            if (!isset($operator_map[$r['bom_ing_fid']])) {
                $name = $r['prod_name'] ?: $r['setup_name'];
                if ($name) $operator_map[$r['bom_ing_fid']] = $name;
            }
        }

        // Fetch Reported Faces (for Type 1)
        $sql_faces = "
            SELECT bom_ing_fid, GROUP_CONCAT(DISTINCT process_face ORDER BY process_face SEPARATOR ',') as reported_faces
            FROM pm_process_daily_report
            WHERE bom_ing_fid IN ($in_fids) AND process_face IS NOT NULL AND process_face != ''
            GROUP BY bom_ing_fid
        ";
        $faces_rows = $pdo->query($sql_faces)->fetchAll(PDO::FETCH_KEY_PAIR);

        // Fetch Detailed Face Stats (Qty & Finished Status)
        $sql_face_details = "
            SELECT bom_ing_fid, process_face, SUM(produced_qty) as face_qty, MAX(CASE WHEN is_finished = 2 THEN 1 ELSE 0 END) as face_finished
            FROM pm_process_daily_report
            WHERE bom_ing_fid IN ($in_fids) AND process_face IS NOT NULL AND process_face != ''
            GROUP BY bom_ing_fid, process_face
        ";
        $face_details_rows = $pdo->query($sql_face_details)->fetchAll(PDO::FETCH_ASSOC);
        $face_stats_map = [];
        foreach ($face_details_rows as $r) {
            $face_stats_map[$r['bom_ing_fid']][] = $r;
        }
    }

    // Merge back
    foreach ($tasks as &$t) {
        $fid = $t['bom_ing_fid'];
        $s = $stats_map[$fid] ?? [];
        $t['reported_faces'] = $faces_rows[$fid] ?? '';
        $t['face_stats'] = $face_stats_map[$fid] ?? [];
        $t = array_merge($t, $s);
        $t['current_operator_name'] = $operator_map[$fid] ?? null;

        // --- 新增邏輯：針對多面加工，重新計算已加工總數 ---
        // 如果一個工單有加工面的統計資料，則其「已加工總數」只應包含「未完工」的那些面的數量。
        // 這可以避免 A 面完工後，其數量計入總數，導致 B 面無法報工的問題。
        if (!empty($t['face_stats'])) {
            $new_total_ok_qty = 0;
            foreach ($t['face_stats'] as $face_stat) {
                // face_finished 為 1 代表該加工面有任何一筆紀錄的 is_finished = 2 (此面完工)
                if ($face_stat['face_finished'] == 0) {
                    $new_total_ok_qty += (int)$face_stat['face_qty'];
                }
            }
            // 使用重新計算後的數量，覆蓋原先簡單加總的 total_ok_qty
            $t['total_ok_qty'] = $new_total_ok_qty;
        }
    }
    unset($t);
} catch (Exception $e) {
    $db_error = $e->getMessage();
    // 發生錯誤時，切換回基本查詢
    try {
        $stmt = $pdo->query($sql_basic);
        $tasks = $stmt->fetchAll();
    } catch (Exception $e2) {
        $db_error .= " | Fallback Error: " . $e2->getMessage();
        $tasks = [];
    }
}

// --- 獲取設定的生產部門與人員清單 ---
$dept_setting_id = 0;
$stmt_param = $pdo->prepare("SELECT param_value FROM system_parameters WHERE param_group = 'process_schedule' AND param_key = 'department_setting'");
$stmt_param->execute();
$row_param = $stmt_param->fetch(PDO::FETCH_ASSOC);
if ($row_param) {
    $dept_setting_id = (int)json_decode($row_param['param_value'], true);
}

// 獲取每日工時設定
$daily_working_hours = 8; // 預設 8 小時
$stmt_ph = $pdo->prepare("SELECT param_value FROM system_parameters WHERE param_group = 'process_schedule' AND param_key = 'daily_working_hours'");
$stmt_ph->execute();
$row_ph = $stmt_ph->fetch(PDO::FETCH_ASSOC);
if ($row_ph) {
    $daily_working_hours = (float)json_decode($row_ph['param_value'], true);
}

$user_list = [];
$target_dept_ids = [];

if ($dept_setting_id) {
    try {
        // 1. 找出該部門及其所有下層部門 ID
        $all_depts = $pdo->query("SELECT id, parent_id FROM department")->fetchAll(PDO::FETCH_ASSOC);
        $target_dept_ids = [$dept_setting_id];

        // 簡單的遞迴查找子部門 (支援多層)
        $added = true;
        while ($added) {
            $added = false;
            foreach ($all_depts as $d) {
                if (in_array($d['parent_id'], $target_dept_ids) && !in_array($d['id'], $target_dept_ids)) {
                    $target_dept_ids[] = (int)$d['id'];
                    $added = true;
                }
            }
        }
    } catch (Exception $e) {
    }
}

// 2. 查詢人員 (若有設定部門則篩選，否則顯示全部)
try {
    $sql_users = "
        SELECT u.id, u.user_cname, d.name as dept_name, p.name as pos_name
        FROM user_department_position_map map
        JOIN user u ON map.user_id = u.id
        JOIN department d ON map.department_id = d.id
        JOIN position p ON map.position_id = p.id
        WHERE u.state = 1
    ";

    if (!empty($target_dept_ids)) {
        $in_clause = implode(',', $target_dept_ids);
        $sql_users .= " AND map.department_id IN ($in_clause) ";
    }

    $sql_users .= " ORDER BY d.sort_order, p.sort_order, u.user_cname";
    $user_list = $pdo->query($sql_users)->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // 發生錯誤時保持空陣列
}

$sql_ng = "SELECT ng_id, ng_txt FROM ng_txt ORDER BY ng_id";
$ng_list = [];
try {
    $ng_list = $pdo->query($sql_ng)->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
}

// --- 獲取異常類型清單 ---
$sql_abnormal = "SELECT abnormal_type_id, abnormal_name FROM abnormal_type ORDER BY sort_order";
$abnormal_types = [];
try {
    $abnormal_types = $pdo->query($sql_abnormal)->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
}

// --- 獲取目前所有機台的未結案異常 (Open Abnormalities) ---
$open_abnormalities = [];
try {
    $sql_oa = "SELECT machine_id, abnormal_id, abnormal_type_id, abnormal_desc, abnormal_start_time, Created_By FROM pm_machine_abnormal WHERE handle_status != 'CLOSED'";
    $stmt_oa = $pdo->query($sql_oa);
    while ($row = $stmt_oa->fetch(PDO::FETCH_ASSOC)) {
        $open_abnormalities[$row['machine_id']] = $row;
    }
} catch (Exception $e) {
}

// --- NEW LOGIC: Group by Machine Type to allow cross-factory dragging ---

// 1. Get all machine types. These will be our tabs, ensuring all types are represented.
//    This fixes the issue where unassigned tasks for a type with no active machines would not be displayed.
$sql_machine_types = "SELECT process_type_id as machine_type_id, process_type as machine_type FROM process_type ORDER BY process_type_id ASC";
$all_machine_types = $pdo->query($sql_machine_types)->fetchAll(PDO::FETCH_ASSOC);

// --- 根據顯示設定篩選要顯示的分頁 ---
$tabs_to_display = $all_machine_types; // 預設顯示全部
// 如果設定存在且不為空陣列，則進行篩選
if (is_array($visible_tabs_ids) && !empty($visible_tabs_ids)) {
    $tabs_to_display = array_filter($all_machine_types, function($type) use ($visible_tabs_ids) {
        // 使用 in_array 檢查，並確保類型一致 (都轉為 string)
        return in_array(strval($type['machine_type_id']), array_map('strval', $visible_tabs_ids));
    });
}

// 2. Get all machines and group them by type. Also create display names.
$machines_by_type = [];
$machine_names = [];
$sql_all_machines = "
    SELECT ml.machine_id, ml.machine, ml.position, ml.machine_type_id, ml.need_setup,
           ml.machine_model, ml.asset_no, ml.field_no
    FROM machine_list ml
    WHERE (ml.state IS NULL OR ml.state != '1') 
      AND ml.position IS NOT NULL AND ml.position != '' AND ml.position != '0'
    ORDER BY ml.machine_id ASC
";
$all_machines_raw = $pdo->query($sql_all_machines)->fetchAll(PDO::FETCH_ASSOC);

foreach ($all_machines_raw as $machine) {
    $type_id = $machine['machine_type_id'];
    if (!isset($machines_by_type[$type_id])) {
        $machines_by_type[$type_id] = [];
    }
    $machines_by_type[$type_id][] = $machine['machine_id'];
    $machine_names[$machine['machine_id']] = eg_machine_disp_name($machine) . " (" . $machine['position'] . "廠)";
}

// 3. Group tasks into buckets: assigned to a machine, or unassigned (grouped by process type)
$tasks_by_machine = [];
$unassigned_tasks_by_type = [];

// Initialize buckets for all machines to ensure empty columns are shown
foreach ($machine_names as $id => $name) {
    $tasks_by_machine[$id] = [];
}

foreach ($tasks as $task) {
    if (!empty($task['machine_id']) && isset($tasks_by_machine[$task['machine_id']])) {
        // Task is assigned to a known, active machine
        $tasks_by_machine[$task['machine_id']][] = $task;
    } else {
        // Task is unassigned OR assigned to a machine that's not in our active list
        $process_type_id = $task['process_type_id'] ?? 'unknown';
        if (!isset($unassigned_tasks_by_type[$process_type_id])) {
            $unassigned_tasks_by_type[$process_type_id] = [];
        }
        $unassigned_tasks_by_type[$process_type_id][] = $task;
    }
}

// --- NEW: 排序未指派的任務 ---
// 規則：1. 急件狀態 (特急 > 急件 > 一般)  2. 交期 (早 -> 晚)  3. BOM編號
foreach ($unassigned_tasks_by_type as $type_id => &$unassigned_list) {
    usort($unassigned_list, function ($a, $b) {
        // 0. 人工排序 (processing_sequence)
        // 有序號的排前面，沒序號 (0或null) 的排後面
        $seqA = (int)($a['processing_sequence'] ?? 0);
        $seqB = (int)($b['processing_sequence'] ?? 0);

        if ($seqA > 0 && $seqB > 0) return $seqA <=> $seqB; // 都有序號，比大小
        if ($seqA > 0) return -1; // A 有序號，排前
        if ($seqB > 0) return 1;  // B 有序號，排前

        // -1. 到料狀態 (已到料 Order_id=1 排前面)
        $a_arrived = ($a['Order_id'] == 1);
        $b_arrived = ($b['Order_id'] == 1);
        if ($a_arrived !== $b_arrived) {
            return $a_arrived ? -1 : 1;
        }

        // --- 以下為沒序號 (新資料) 的自動排序 ---
        // 1. 優先級 (E=1, U=2, 其他=3)
        $priority_map = ['E' => 1, 'U' => 2];
        $a_priority = $priority_map[$a['priority_type']] ?? 3;
        $b_priority = $priority_map[$b['priority_type']] ?? 3;
        if ($a_priority != $b_priority) {
            return $a_priority <=> $b_priority;
        }

        // 2. 交期排序 (有日期的排前面，日期早的排前面)
        $a_date = !empty($a['shipping_date']) ? strtotime($a['shipping_date']) : false;
        $b_date = !empty($b['shipping_date']) ? strtotime($b['shipping_date']) : false;

        if ($a_date && $b_date) {
            if ($a_date != $b_date) {
                return $a_date <=> $b_date;
            }
        } elseif ($a_date) {
            return -1; // A 有日期，B 沒有，A 排前面
        } elseif ($b_date) {
            return 1;  // B 有日期，A 沒有，B 排前面
        }

        // 3. BOM 編號作為最後的排序依據
        return strnatcmp($a['bom'], $b['bom']);
    });
}
unset($unassigned_list); // 結束 usort 後，斷開引用

// 計算各機台類型的總任務數 (用於分頁顯示)
$type_counts = [];
foreach ($all_machine_types as $type) {
    $tid = $type['machine_type_id'];
    $count = 0;
    // 加總未指派
    if (isset($unassigned_tasks_by_type[$tid])) {
        $count += count($unassigned_tasks_by_type[$tid]);
    }
    // 加總已指派到該類型下各機台的任務
    if (isset($machines_by_type[$tid])) {
        foreach ($machines_by_type[$tid] as $mid) {
            if (isset($tasks_by_machine[$mid])) {
                $count += count($tasks_by_machine[$mid]);
            }
        }
    }
    $type_counts[$tid] = $count;
}

// --- 獲取所有 Level 3 部門 (供設定使用) ---
$sql_l3_depts = "SELECT id, name FROM department WHERE level = 3 ORDER BY sort_order, id";
$l3_depts = $pdo->query($sql_l3_depts)->fetchAll(PDO::FETCH_ASSOC);

// --- 獲取所有製程代號 (供臨時加工使用) ---
$sql_all_procs = "SELECT ProcessNo, ProcessName, process_type_id FROM process_no ORDER BY ProcessNo";
$all_processes = $pdo->query($sql_all_procs)->fetchAll(PDO::FETCH_ASSOC);

function get_priority_class($priority_type)
{
    switch ($priority_type) {
        case 'U':
            return 'card-priority-U'; // 急件
        case 'E':
            return 'card-priority-E'; // 特急件
        default:
            return 'card-priority-normal';
    }
}

function get_state_badge($state)
{
    switch ($state) {
        case 'ing':
            return '<span class="label label-success">加工中</span>';
        case 'Q':
            return '<span class="label label-warning">QC待驗</span>';
        case 'P':
            return '<span class="label label-primary">待移轉</span>';
        default:
            return "<span class=\"label label-default\">{$state}</span>";
    }
}

?>
<!DOCTYPE html>
<html lang="zh-Hant">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>加工排程管理 (即時看板)</title>

    <script>
        window.uiSettings = <?= json_encode($ui_settings) ?>;
    </script>
    <!-- 原有樣式 -->
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <!-- Select2 -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.0.13/dist/css/select2.min.css" rel="stylesheet" />
    <!-- Flatpickr CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">

    <style>
        /* 修正 nav-sm 模式下側邊欄子選單顯示問題 */
        .nav-sm .col-md-3.left_col {
            overflow: visible !important;
            width: 70px;
        }

        .nav-sm .nav.side-menu li .nav.child_menu {
            display: none !important;
        }

        .nav-sm .nav.side-menu li {
            position: relative;
        }

        .nav-sm .nav.side-menu li.active-sm .nav.child_menu {
            display: block !important;
            z-index: 9999;
            position: absolute;
            left: 100%;
            top: 0;
        }

        .kanban-board {
            display: flex;
            /* overflow-x: auto; */
            /* 取消水平滾動 */
            flex-wrap: wrap;
            /* 允許換行 */
            padding-bottom: 10px;
        }

        .kanban-column {
            min-width: 300px;
            width: 300px;
            margin-right: 15px;
            border-radius: 8px;
            display: flex;
            flex-direction: column;
        }

        .kanban-column-header {
            padding: 8px 10px;
            font-weight: bold;
            font-size: 16px;
            color: #333;
            border-bottom: 2px solid #E6E9ED;
            margin-bottom: 10px;
        }

        .kanban-cards {
            flex-grow: 1;
            min-height: 200px;
            /* 新增：限制最大高度並啟用垂直捲動，解決未指派欄位過長問題 */
            max-height: 70vh;
            overflow-y: auto;
        }
        /* 限制機台欄位高度 (最多顯示兩個資料塊)，超過捲動 */
        .kanban-cards:not(.unassigned-list) {
            max-height: 260px; /* 約兩個卡片高度 + 間距 */
            overflow-y: auto;
        }

        .kanban-card {
            background-color: #fff;
            padding: 12px;
            /* 增加內距，讓卡片不那麼擠 */
            margin-bottom: 10px;
            border: 1px solid #E6E9ED;
            cursor: grab;
            border-left: 4px solid #ccc;
            position: relative;
        }

        .kanban-card:active {
            cursor: grabbing;
        }

        .kanban-card .card-title {
            font-weight: 600;
            font-size: 14px;
            margin-bottom: 0;
            margin-top: 0;
            margin-right: 0;
            /* 移除右側預留空間，改用 Flexbox */
        }

        /* 新增：標題列 Flex 容器 */
        .kanban-card .card-header-flex {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
            /* 標題與內容的間距 */
        }

        /* 新增：標題列右側容器 (製程+狀態+順序) */
        .kanban-card .card-header-right {
            display: flex;
            align-items: center;
            flex-shrink: 0;
            margin-left: 10px;
        }

        /* 新增：製程名稱樣式 */
        .kanban-card .card-process-name {
            font-size: 13px;
            font-weight: bold;
            color: #2A3F54;
            margin-right: 6px;
        }

        .kanban-card .card-text {
            font-size: 12px;
            color: #73879C;
            margin-bottom: 6px;
            /* 增加行距 */
        }

        .kanban-card .card-text.text-danger {
            color: #d9534f;
        }

        .card-priority-U {
            border-left-color: #d9534f !important;
        }

        /* Red */
        .card-priority-E {
            border-left-color: #9B59B6 !important;
        }

        /* Purple */
        .card-priority-normal {
            border-left-color: #73879C !important;
        }

        /* Gray */

        /* SortableJS Ghost Class */
        .sortable-ghost {
            opacity: 0.4;
            background: #cce5ff;
        }

        .sortable-drag {
            opacity: 1 !important;
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.2);
            transform: rotate(3deg);
        }

        /* Custom Notification */
        #custom-toast {
            position: fixed;
            bottom: 20px;
            right: 20px;
            z-index: 9999;
            min-width: 250px;
            display: none;
            padding: 15px;
            color: #fff;
            border-radius: 4px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
            font-size: 14px;
        }

        /* 隱藏/顯示待辦事項的樣式 */
        .column-actions {
            text-align: right;
            margin-bottom: 5px;
            padding: 0 8px;
        }

        .waiting-task {
            display: none;
            /* 預設隱藏待辦卡片 */
        }

        .kanban-column.show-waiting .waiting-task {
            display: block;
            /* 點擊後顯示 */
        }

        .kanban-column.show-waiting .now-processing {
            /* 收合加工中卡片的樣式 */
            padding-top: 5px;
            padding-bottom: 5px;
        }

        .kanban-column.show-waiting .now-processing .card-text,
        .kanban-column.show-waiting .now-processing hr {
            display: none;
            /* 隱藏詳細資訊 */
        }

        /* 統一的回到頂端按鈕樣式 */
        .scroll-to-top {
            position: fixed;
            bottom: 20px;
            right: 20px;
            width: 50px;
            height: 50px;
            background-color: rgba(255, 255, 255, 0.5);
            color: black;
            border: none;
            border-radius: 50%;
            text-align: center;
            line-height: 50px;
            cursor: pointer;
            font-size: 12px;
            font-weight: bold;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
            transition: all 0.3s;
            z-index: 1000;
        }

        .scroll-to-top:hover {
            background-color: rgba(255, 255, 255, 0.7);
        }

        /* 修正 Bootstrap 衝突與版面調整 */
        .right_col {
            background-color: #f0f2f5;
            /* 配合看板背景色 */
            min-height: 100vh;
            /* 確保內容區塊填滿整個視窗高度 */
        }

        .page-title .title_left h3 {
            margin-left: 10px;
        }

        /* 異常機台標題樣式 */
        .kanban-column-header.abnormal-header {
            background-color: #d9534f !important;
            color: white !important;
            border-bottom: 2px solid #c9302c;
        }

        .kanban-column-header {
            cursor: pointer;
            /* 提示可點擊 */
        }

        /* 排序視窗列表樣式 */
        #unassigned-sort-list .list-group-item {
            cursor: move;
            padding: 10px;
            font-size: 14px;
        }

        /* 搜尋功能樣式 */
        body.search-mode .waiting-task {
            display: block !important;
            /* 搜尋模式下強制顯示待辦以便過濾 */
        }

        /* 修正：增加權重以覆蓋 body.search-mode .waiting-task 的設定，確保不符合的項目被隱藏 */
        body.search-mode .kanban-card.search-hidden,
        .search-hidden {
            display: none !important;
        }

        /* 修正左側未指派欄位的樣式 */
        .unassigned-wrapper .kanban-cards {
            max-height: 75vh;
            /* 讓左側欄位有獨立的滾動高度 */
        }

        /* 機台設定列表固定表頭 */
        #machineListTable thead th {
            position: sticky;
            top: 0;
            background-color: #f5f5f5;
            z-index: 1;
        }

        /* --- 列表模式樣式 (List View Mode) --- */
        body.list-view-mode .kanban-card {
            display: flex;
            align-items: center;
            padding: 6px 10px;
            min-height: auto;
            flex-wrap: wrap;
        }

        body.list-view-mode .kanban-card .card-title {
            margin: 0;
            font-size: 13px;
            width: 160px;
            /* 固定寬度給 BOM */
            flex-shrink: 0;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            margin-right: 10px !important;
        }

        body.list-view-mode .kanban-card .card-text {
            margin: 0 8px 0 0;
            font-size: 12px;
            display: inline-block;
        }

        body.list-view-mode .kanban-card .card-header-right {
            margin-left: auto;
            /* 列表模式下靠右對齊 */
        }

        /* 列表模式下隱藏詳細區塊與分隔線 */
        body.list-view-mode .kanban-card .detail-info:not(.always-show),
        body.list-view-mode .kanban-card hr,
        /* 修正：移除會隱藏加工中卡片內容的錯誤規則，避免進度條消失 */
        body.list-view-mode .kanban-card .now-processing>div.detail-info {
            display: none !important;
        }

        /* 修正：列表模式下顯示進度條 (若設定開啟) */
        body.list-view-mode .kanban-card .card-progress-bar {
            display: flex !important;
            flex-basis: 100%;
            /* 強制換行佔滿寬度 */
            margin-top: 5px;
            width: 100%;
        }

        /* 列表模式下顯示統計數據 */
        body.list-view-mode .kanban-card .card-stats-row {
            display: inline-block !important;
            font-size: 12px;
            color: #555;
            margin-left: 10px;
        }

        /* --- 視覺優化樣式 --- */
        .kanban-card .bom-title {
            background-color: #e8f0fe;
            /* 淺藍底 */
            color: #1967d2;
            /* 深藍字 */
            padding: 2px 6px;
            border-radius: 4px;
            font-weight: bold;
            font-size: 1.1em;
        }

        .kanban-card .part-no-highlight {
            background-color: #eee;
            /* 改為中性灰底 */
            color: #333;
            /* 深灰字 */
            padding: 2px 6px;
            border-radius: 3px;
            font-weight: bold;
        }

        .kanban-card .est-completion-block {
            background-color: #e6f4ea;
            /* 淺綠底 */
            color: #137333;
            /* 深綠字 */
            padding: 4px 8px;
            border-radius: 4px;
            font-weight: bold;
            margin-top: 4px;
            display: block;
            border: 1px solid #ceead6;
        }

        /* 統計數據間距 */
        .kanban-card .card-stats-row span {
            margin-right: 12px;
        }

        .stats-spacer {
            display: inline-block;
            width: 15px;
            text-align: center;
            color: #ccc;
        }

        /* --- 個人顯示設定控制 (CSS Toggle) --- */
        body.hide-show-shipping-date .card-shipping-date {
            display: none !important;
        }

        body.hide-show-ps2 .card-ps2 {
            display: none !important;
        }

        body.hide-show-pti01-ps .card-pti01-ps {
            display: none !important;
        }

        body.hide-show-est-completion .card-est-completion {
            display: none !important;
        }

        /* 修正：提高權重以覆蓋列表模式的強制顯示設定 */
        body.hide-show-progress .kanban-card .card-progress-bar,
        body.hide-show-progress .card-progress-bar {
            display: none !important;
        }

        body.hide-show-est-days .card-est-days {
            display: none !important;
        }

        /* --- Dashboard Styles --- */
        #dashboard-view {
            padding: 10px;
        }

        .dashboard-factory-container {
            border: 2px solid #337ab7;
            border-radius: 5px;
            margin-bottom: 20px;
            background-color: #fff;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        }

        .dashboard-factory-header {
            background-color: #337ab7;
            color: white;
            padding: 5px 10px;
            font-weight: bold;
            font-size: 16px;
        }

        .dashboard-factory-content {
            padding: 10px;
            display: flex;
            flex-wrap: wrap;
        }

        .dashboard-type-group {
            margin-right: 15px;
            margin-bottom: 10px;
            border: 1px solid #e0e0e0;
            border-radius: 4px;
            padding: 5px;
            background-color: #f9f9f9;
            display: flex;
            flex-direction: column;
        }

        .dashboard-type-header {
            font-weight: bold;
            text-align: center;
            margin-bottom: 5px;
            border-bottom: 1px solid #ccc;
            padding-bottom: 3px;
            color: #555;
        }

        .dashboard-machines-flex {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
        }

        .dashboard-machine-card {
            width: 110px;
            height: 90px;
            border-radius: 4px;
            padding: 5px;
            color: white;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2);
            text-align: left;
            cursor: pointer;
            position: relative;
        }

        .dashboard-machine-name {
            font-weight: bold;
            font-size: 13px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .dashboard-machine-info {
            font-size: 11px;
            line-height: 1.2;
            overflow: hidden;
        }

        .bg-red {
            background-color: #d9534f;
        }

        .bg-yellow {
            background-color: #f0ad4e;
            color: #333;
        }

        .bg-green {
            background-color: #26B99A;
        }

        .bg-blue {
            background-color: #3498db;
        }

        /* 寬版 Popover 樣式 */
        .wide-popover {
            max-width: none;
            /* 解除最大寬度限制 */
        }

        .wide-popover .popover-content {
            white-space: nowrap;
            /* 強制不換行 */
            padding: 10px;
        }

        /* 1. 使用 ID 選擇器 (優先級極高) 並強制重置所有可能干擾對齊的屬性 */
        #modal_bom_info[readonly] {
            text-align: left !important;
            padding-left: 12px !important;
            /* 稍微推開一點，視覺更貼心 */
            display: block !important;
            /* 強制設為塊狀 */
            width: 100% !important;
            text-indent: 0 !important;
            /* 防止某些框架設定了首行縮排 */
        }

        /* 機台時程表樣式 */
        .schedule-container {
            display: flex;
            flex-direction: column;
            height: auto;
            max-height: 65vh;
            /* Fit in modal */
            overflow-y: auto;
            /* Scroll machines */
            overflow-x: hidden;
            /* 24h fits in width */
            border: 1px solid #ccc;
        }

        .schedule-header {
            display: flex;
            position: sticky;
            top: 0;
            z-index: 20;
            background: #f5f5f5;
            border-bottom: 1px solid #ddd;
            height: 30px;
            line-height: 30px;
        }

        .header-machine-name {
            width: 120px;
            /* Fixed width for machine name */
            flex-shrink: 0;
            border-right: 1px solid #ddd;
            text-align: center;
            font-weight: bold;
            background: #e0e0e0;
        }

        .header-timeline {
            flex-grow: 1;
            position: relative;
            display: flex;
        }

        .header-hour {
            flex: 1;
            /* Distribute evenly */
            text-align: left;
            font-size: 10px;
            border-left: 1px solid #ccc;
            padding-left: 2px;
            color: #666;
        }

        .machine-row {
            display: flex;
            border-bottom: 1px solid #eee;
            height: 200px;
            /* Fixed height per machine */
            position: relative;
        }

        .machine-row:hover {
            z-index: 1000;
            /* 將滑鼠懸停的資料列拉到最前面，以顯示完整的任務資訊 */
        }

        .machine-name {
            width: 120px;
            flex-shrink: 0;
            border-right: 1px solid #ddd;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            background: #fff;
            font-size: 12px;
            padding: 0 5px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .machine-timeline {
            flex-grow: 1;
            position: relative;
            background: #fff;
        }

        /* Grid lines in timeline */
        .timeline-bg-grid {
            position: absolute;
            top: 0;
            bottom: 0;
            left: 0;
            right: 0;
            display: flex;
            pointer-events: none;

        }

        .bg-hour {
            flex: 1;
            border-left: 1px solid #f0f0f0;
        }

        .bg-hour:first-child {
            border-left: none;
        }

        .task-block {
            position: absolute;
            top: 5px;
            bottom: 5px;
            border-radius: 4px;
            font-size: 13px;
            font-weight: bold;
            /* 加粗 */
            color: white;
            padding: 2px 4px;
            overflow: hidden;
            white-space: nowrap;
            text-overflow: ellipsis;
            cursor: pointer;
            z-index: 10;
            box-shadow: 1px 1px 2px rgba(0, 0, 0, 0.2);
            /* Distinct separation */
            border: 1px solid rgba(255, 255, 255, 0.5);
            line-height: 1.2;
        }

        .task-block:hover {
            z-index: 1001;
            overflow: visible;
            width: auto;
            min-width: 100%;
            /* Expand to show text */
            background-color: #333 !important;
            /* Highlight on hover */
            color: #fff;
            box-shadow: 3px 3px 6px rgba(0, 0, 0, 0.3);
        }

        .task-setup {
            background-color: #3498db;
        }

        .task-prod {
            background-color: #26B99A;
        }

        /* 已報工格式 */
        .bom-part-cell {
            line-height: 1.2;
        }

        .bom-no {
            font-weight: 600;
            font-size: 14px;
        }

        .part-no {
            color: #777;
            font-size: 12px;
        }

        .gear-spec {
            color: #999;
            font-size: 11px;
        }

/* 2026.03.12: 調整分頁標籤字體大小與間距 */
        #machineTypeTabs > li > a {
            font-size: 13px;
            padding: 8px 12px;
        }
    </style>
</head>

<body class="nav-sm list-view-mode <?= $body_setting_classes ?>">
    <div class="container body">
        <div class="main_container">
            <!-- 引入選單 -->
            <?php include '../partPage/sideAndTopBarMenu.html' ?>

            <div class="right_col" role="main">
                <div class="">
                    <div class="page-title">
                        <div class="title_left">
                            <h3>
                                <strong style="color: #2A3F54;">加工排程管理</strong>
                                <span style="color: #d9534f; font-weight: bold; margin-left: 10px;">即時看板</span>
                                <?php if (!empty($permission_display_text)): ?>
                                    <small style="color: #73879C; font-size: 12px; margin-left: 10px; cursor: pointer;"
                                        data-toggle="popover" data-trigger="hover" data-placement="bottom" data-html="true"
                                        data-content="<?= htmlspecialchars($permission_tooltip_text, ENT_QUOTES, 'UTF-8') ?>">
                                        (權限：<?= htmlspecialchars($permission_display_text) ?>)
                                    </small>
                                <?php endif; ?>
                            </h3>
                        </div>
                        <div class="title_right">
                            <div class="pull-right" style="display: flex; align-items: center; justify-content: flex-end; flex-wrap: wrap;">
                                <!-- 按鈕群組與搜尋框同一列 -->
                                <div class="btn-group" style="margin-bottom: 5px; margin-right: 5px;">
                                    <button id="btn-dashboard-mode" class="btn btn-default btn-sm"><i class="fa fa-th-large"></i> 全廠看板</button>
                                    <button type="button" class="btn btn-default btn-sm dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                        <span class="caret"></span><span class="sr-only">Toggle Dropdown</span>
                                    </button>
                                    <button id="btn-schedule-list" class="btn btn-default btn-sm" style="margin-left: 5px;">
                                        <i class="fa fa-list-alt"></i> 排程列表
                                    </button>
                                    <ul class="dropdown-menu">
                                        <li class="dropdown-header">自動更新頻率</li>
                                        <li><a href="#" class="refresh-rate-opt" data-val="10">10 秒 (測試)</a></li>
                                        <li><a href="#" class="refresh-rate-opt" data-val="60">1 分鐘</a></li>
                                        <li><a href="#" class="refresh-rate-opt" data-val="300">5 分鐘</a></li>
                                    </ul>
                                    <input type="hidden" id="dashboard-refresh-rate" value="10">
                                </div>
                                <?php if ($has_A || $is_prod_control): ?>
                                <button id="btn-process-setting" onclick="openSharedModal('製程設定', '../popup/modal_process_setting.php')" class="btn btn-primary btn-sm" style="margin-bottom: 5px; margin-right: 5px;">
                                    <i class="fa fa-cogs"></i> 製程分類設定
                                </button>
                            <?php endif; ?>
                                <button id="toggle-all-waiting-btn" class="btn btn-info btn-sm" style="margin-bottom: 5px; margin-right: 5px; display: none;">
                                    <i class="fa fa-list-ul"></i> 顯示所有待辦
                                </button>
                                <button id="toggle-view-btn" class="btn btn-success btn-sm active" style="margin-bottom: 5px; margin-right: 5px;">
                                    <i class="fa fa-list"></i> 切換列表模式
                                </button>
                                <button class="btn btn-default btn-sm" data-toggle="modal" data-target="#personalSettingModal" style="margin-bottom: 5px; margin-right: 5px;">
                                    <i class="fa fa-eye"></i> 顯示設定
                                </button>
                                <?php if ($can_settings): ?>
                                    <button class="btn btn-default btn-sm" data-toggle="modal" data-target="#deptSettingModal" style="margin-bottom: 5px; margin-right: 5px;">
                                        <i class="fa fa-cog"></i> 設定生產部門
                                    </button>
                                <?php endif; ?>
                                <button class="btn btn-default btn-sm" id="btnSearchFinished" style="margin-bottom: 5px; margin-right: 5px;">
                                    <i class="fa fa-history"></i> 查詢已報工
                                </button>
                                <a class="btn btn-default btn-sm" href="process_report_query.php" target="_blank" style="margin-bottom: 5px; margin-right: 5px;">
                                    <i class="fa fa-list-alt"></i> 報工紀錄查詢列印
                                </a>
                                <button class="btn btn-danger btn-sm" id="btnTempReport" style="margin-bottom: 5px; margin-right: 5px;">
                                    <i class="fa fa-bolt"></i> 臨時加工
                                </button>
                                <?php if ($can_manage_machine): ?>
                                    <button class="btn btn-default btn-sm" id="btnOpenMachineSettings" style="margin-bottom: 5px; margin-right: 10px;">
                                        <i class="fa fa-cogs"></i> 設定機台
                                    </button>
                                <?php endif; ?>
                                <button class="btn btn-warning btn-sm" id="btnMachineSchedule" style="margin-bottom: 5px; margin-right: 5px;">
                                    <i class="fa fa-calendar"></i> 機台時程表
                                </button>
                                <!-- 搜尋框 -->
                                <div class="input-group" style="width: 250px; margin-bottom: 5px;">
                                    <input type="text" id="card-search-input" class="form-control" placeholder="搜尋 BOM, 料號, 客戶...">
                                    <span class="input-group-btn">
                                        <button class="btn btn-default" type="button"><i class="fa fa-search"></i></button>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="clearfix"></div>

                    <?php if ($db_error): ?>
                        <div class="alert alert-warning alert-dismissible fade in" role="alert">
                            <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">×</span></button>
                            <strong>注意!</strong> 無法載入詳細加工資訊 (資料庫錯誤)，已切換至基本模式。<br>
                            <small>錯誤訊息: <?= htmlspecialchars($db_error) ?></small>
                        </div>
                    <?php endif; ?>

                    <div id="dashboard-view" style="display:none;"></div>
                    <div class="row">

                        <div class="col-md-12 col-sm-12 col-xs-12">
                            <div class="x_panel">
                                <div class="x_content" style="display: flex; flex-wrap: wrap;">

                                    <!-- 左側：固定未指派欄位 -->
                                    <div class="col-md-3 col-sm-4 col-xs-12 unassigned-wrapper" style="padding-left: 0;">
                                        <div class="kanban-column" style="width: 100%; margin-right: 0;">
                                            <?php $header_tooltip = $can_drag ? '點擊此處可開啟排序視窗，進行拖曳排序' : '點擊此處可開啟檢視視窗'; ?>
                                            <div id="unassigned-header" class="kanban-column-header" style="background-color: #f39c12; color: white;" title="<?= $header_tooltip ?>">
                                                <i class="fa fa-inbox"></i> 未指派
                                                <span id="unassigned-count-badge" class="badge pull-right bg-blue">0</span>
                                            </div>
                                            <!-- 這裡產生所有類型的未指派清單，但透過 JS 控制顯示哪一個 -->
                                            <?php
                                            $type_index = 0;
                                            foreach ($tabs_to_display as $type):
                                                $type_id = $type['machine_type_id'];
                                                $display_style = ($type_index === 0) ? 'display: block;' : 'display: none;';
                                                $unassigned_tasks = $unassigned_tasks_by_type[$type_id] ?? [];
                                                // 🌟 魔法陣：強制將準備餵給跳窗的陣列，絕對依照 processing_sequence 由小到大排序！
                                                // ⚠️ 這裡覆蓋了上方可能存在的「已到料優先」邏輯，確保拖曳排序生效
                                                usort($unassigned_tasks, function ($a, $b) {
                                                    // 抓取順序，如果沒有設定(null或0)就當作無限大(999999)排到最後面
                                                    $seqA = (is_numeric($a['processing_sequence']) && $a['processing_sequence'] > 0) ? (int)$a['processing_sequence'] : 999999;
                                                    $seqB = (is_numeric($b['processing_sequence']) && $b['processing_sequence'] > 0) ? (int)$b['processing_sequence'] : 999999;

                                                    // 如果兩者順序不同，就依照順序排
                                                    if ($seqA !== $seqB) {
                                                        return $seqA <=> $seqB;
                                                    }
                                                    // 如果順序一樣，用 fid 防呆
                                                    return $a['bom_ing_fid'] <=> $b['bom_ing_fid'];
                                                });

                                            ?>
                                                <div class="kanban-cards list-group unassigned-list" id="machine-unassigned-<?= $type_id ?>" data-machine-id="unassigned-<?= $type_id ?>" data-type-id="<?= $type_id ?>" style="<?= $display_style ?>">
                                                    <?php foreach ($unassigned_tasks as $task):
                                                        $card_classes = 'kanban-card list-group-item ' . get_priority_class($task['priority_type']);
                                                        $seq_num_unassigned = !empty($task['processing_sequence']) && $task['processing_sequence'] > 0 ? htmlspecialchars($task['processing_sequence']) : '';
                                                        $seq_display_html = '';
                                                        if ($seq_num_unassigned) {
                                                            $seq_display_html = '<div style="margin-right:10px;"><span style="display:inline-block; width:24px; height:24px; line-height:24px; text-align:center; background-color:#9B59B6; color:white; font-weight:bold; border-radius:3px;">' . $seq_num_unassigned . '</span></div>';
                                                        }

                                                        // 計算進度與統計 (未指派)
                                                        $u_total_qty = (int)$task['sqty'];
                                                        $u_ok_qty = (int)($task['total_ok_qty'] ?? 0);
                                                        $u_percent = ($u_total_qty > 0) ? min(100, round(($u_ok_qty / $u_total_qty) * 100)) : 0;

                                                        // 判斷生管備註是否包含 "急件"
                                                        $pti01_text = $task['pti01_ps'] ?? '';
                                                        $is_urgent_pti01 = (strpos($pti01_text, '急件') !== false);
                                                        $pti01_class = $is_urgent_pti01 ? 'text-danger' : 'text-primary';
                                                        $pti01_style = $is_urgent_pti01 ? 'font-weight: bold;' : '';

                                                        // 準備齒輪資料字串 (供 Modal 使用)
                                                        $gear_info_str = '';
                                                        if (!empty($task['Module'])) {
                                                            $modClean = preg_replace('/[^0-9.]/', '', $task['Module']);
                                                            $gear_info_str = "M" . floatval($modClean) . " T" . floatval($task['Teeth']) . " W" . floatval($task['Face_Width']) . " L" . floatval($task['Workpiece_Length']);
                                                        }
                                                    ?>
                                                        <div class="<?= $card_classes ?>" title="拖曳可調整順序，雙擊可編輯" data-id="<?= $task['bom_ing_fid'] ?>" data-bom="<?= htmlspecialchars($task['bom']) ?>" data-processing-sequence="<?= htmlspecialchars($task['processing_sequence'] ?? '') ?>" data-process-no="<?= $task['process_no'] ?>" data-process-type-id="<?= $task['process_type_id'] ?>" data-state="<?= $task['processing_state'] ?>" data-ps="<?= htmlspecialchars($task['ps'] ?? '', ENT_QUOTES) ?>" data-single-bet-ps="<?= htmlspecialchars($task['single_bet_ps'] ?? '', ENT_QUOTES) ?>" data-pti01-ps="<?= htmlspecialchars($task['pti01_ps'] ?? '', ENT_QUOTES) ?>" data-1-side="<?= htmlspecialchars($task['1_side'] ?? '', ENT_QUOTES) ?>" data-ps2="<?= htmlspecialchars($task['PS2'] ?? '', ENT_QUOTES) ?>" data-client="<?= htmlspecialchars($task['Client_Name']) ?>" data-part-no="<?= htmlspecialchars($task['d_id']) ?>" data-sqty="<?= $task['sqty'] ?>" data-ok-qty="<?= $task['total_ok_qty'] ?? 0 ?>" data-ng-qty="<?= $task['total_ng_qty'] ?? 0 ?>" data-shipping-date="<?= htmlspecialchars($task['shipping_date'] ?? '') ?>" data-gear-info="<?= htmlspecialchars($gear_info_str) ?>" data-part-type="<?= $task['part_type'] ?>" data-ds-id="<?= $task['ds_id'] ?>" data-order-id="<?= $task['Order_id'] ?>">
                                                            <!-- 標題列：BOM (左) ... 製程 + 狀態 + 順序 (右) -->
                                                            <div class="card-header-flex">
                                                                <h6 class="card-title" style="display:flex; align-items:center;">
                                                                    <?= $seq_display_html ?>
                                                                    <span class="bom-title" style="cursor:pointer;" title="點擊複製" onclick="event.stopPropagation(); copyToClipboard('<?= htmlspecialchars($task['bom']) ?>', this)"><?= htmlspecialchars($task['bom']) ?></span>
                                                                </h6>
                                                                <div class="card-header-right card-badges">
                                                                    <span class="card-process-name"><?= htmlspecialchars($task['ProcessName'] ?? $task['process_no']) ?></span>
                                                                    <span class="card-state-badge"><span class="label label-info">待指派</span></span>
                                                                    <?php if ($can_reorder || $has_U): ?>
                                                                        <button type="button" class="btn btn-xs btn-default" style="margin-left: 5px; padding: 0 4px;" onclick="event.stopPropagation(); splitTask('<?= $task['bom_ing_fid'] ?>')" title="拆分製程 (多機台加工)">
                                                                            <i class="fa fa-code-fork"></i>
                                                                        </button>
                                                                    <?php endif; ?>
                                                                    <?php if (($has_A || $has_D) && strpos($task['ps'], '(拆分工單)') !== false): ?>
                                                                        <button type="button" class="btn btn-xs btn-danger" style="margin-left: 5px; padding: 0 4px;" onclick="event.stopPropagation(); deleteSplitTask('<?= $task['bom_ing_fid'] ?>')" title="刪除拆分工單">
                                                                            <i class="fa fa-trash"></i>
                                                                        </button>
                                                                    <?php endif; ?>
                                                                </div>
                                                            </div>
                                                            <p class="card-text mb-1">
                                                                <i class="fa fa-cube"></i>
                                                                <strong>料號:</strong> <a href="/nas/<?= urlencode($task['bom']) ?>.jpg" target="_blank" class="part-no-highlight" style="color: #333; text-decoration: underline;">
                                                                    <?= htmlspecialchars($task['d_id']) ?>
                                                                </a>
                                                            </p>
                                                            <?php if ($task['process_type_id'] == 1): ?>
                                                                <p class="card-text mb-1">
                                                                    <?php if ($task['Order_id'] == 1): ?>
                                                                        <span class="label label-success">已到料</span>
                                                                    <?php endif; ?>
                                                                    <?php if (!empty($task['reported_faces'])): ?><?php endif; ?>
                                                                </p>
                                                            <?php endif; ?>
                                                            <?php
                                                            // 顯示齒輪資料 (未指派顯示詳細)
                                                            if (!empty($task['Module'])) {
                                                                $modClean = preg_replace('/[^0-9.]/', '', $task['Module']);
                                                                $mod = floatval($modClean);
                                                                $teeth = floatval($task['Teeth']);
                                                                $width = floatval($task['Face_Width']);
                                                                $len = floatval($task['Workpiece_Length']);
                                                                echo '<p class="card-text mb-1"><span class="badge bg-blue module-filter-btn" style="cursor:pointer; font-size: 12px; padding: 4px 8px;" data-module="M' . $mod . '" title="點擊篩選此模數">M' . $mod . ' T' . $teeth . ' W' . $width . ' L' . $len . '</span></p>';
                                                            }
                                                            ?>
                                                            <?php if (!$is_production): // 生產權限不顯示客戶 
                                                            ?>
                                                                <p class="card-text mb-1">
                                                                    <i class="fa fa-user"></i>
                                                                    <strong>客戶:</strong> <?= htmlspecialchars($task['Client_Name']) ?>
                                                                </p>
                                                            <?php endif; ?>
                                                            <?php if (!empty($task['bom_ps'])): ?>
                                                                <hr style="margin: 5px 0;">
                                                                <p class="card-text text-danger small">
                                                                    <i class="fa fa-exclamation-triangle text-danger"></i>
                                                                    <strong>BOM:</strong> <?= htmlspecialchars($task['bom_ps']) ?>
                                                                </p>
                                                            <?php endif; ?>
                                                            <?php if (!empty($task['single_bet_ps'])): ?>
                                                                <p class="card-text text-info small">
                                                                    <i class="fa fa-info-circle"></i>
                                                                    <strong>備註:</strong> <?= htmlspecialchars($task['single_bet_ps']) ?>
                                                                </p>
                                                            <?php endif; ?>
                                                            <?php if ($task['process_type_id'] == 1 && !empty($task['pti01_ps'])): ?>
                                                                <p class="card-text <?= $pti01_class ?> small card-pti01-ps" style="margin-top:2px; <?= $pti01_style ?>">
                                                                    <i class="fa fa-pencil-square-o"></i>
                                                                    <strong>生管備註:</strong> <?= htmlspecialchars($task['pti01_ps']) ?>
                                                                </p>
                                                            <?php endif; ?>
                                                            <?php if (in_array($task['process_type_id'], $ui_settings['show_face_options']) && !empty($task['1_side'])): ?>
                                                                <p class="card-text text-primary small" style="margin-top:2px;">
                                                                    <i class="fa fa-arrows-h"></i>
                                                                    <strong>加工面:</strong> <?= htmlspecialchars($task['1_side']) ?>
                                                                </p>
                                                            <?php endif; ?>
                                                            <?php if (!empty($task['PS2'])): ?>
                                                                <p class="card-text text-success small card-ps2" style="margin-top:2px;">
                                                                    <i class="fa fa-comment-o"></i>
                                                                    <strong>現場備註:</strong> <?= htmlspecialchars($task['PS2']) ?>
                                                                </p>
                                                            <?php endif; ?>
                                                            <?php if (!empty($task['shipping_date'])): ?>
                                                                <p class="card-text small card-shipping-date">
                                                                    <i class="fa fa-calendar"></i>
                                                                    <strong>訂單交期:</strong> <?= htmlspecialchars($task['shipping_date']) ?>
                                                                </p>
                                                            <?php endif; ?>
                                                            <?php if (!empty($task['start_process_date'])): ?>
                                                                <p class="card-text small" style="color: #008000;">
                                                                    <i class="fa fa-clock-o"></i>
                                                                    <strong>開始:</strong> <?= date('m/d H:i', strtotime($task['start_process_date'])) ?>
                                                                </p>
                                                            <?php endif; ?>

                                                            <!-- 進度條與統計 (未指派) -->
                                                            <div class="card-progress-bar" style="margin-top: 5px;">
                                                                <div class="progress" style="margin-bottom: 2px; height: 6px; background-color: #e0e0e0; border-radius: 4px;">
                                                                    <div class="progress-bar progress-bar-success" role="progressbar" style="width: <?= $u_percent ?>%;"></div>
                                                                </div>
                                                                <div class="card-stats-row">
                                                                    <span title="良品/總數"><i class="fa fa-check text-success"></i> <?= number_format($u_ok_qty) ?> / <?= number_format($u_total_qty) ?></span>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php
                                                $type_index++;
                                            endforeach;
                                            ?>
                                        </div>
                                    </div>

                                    <!-- 右側：分頁與機台 -->
                                    <div class="col-md-9 col-sm-8 col-xs-12" style="padding-right: 0;">
                                        <!-- 分頁標籤 -->
                                        <div role="tabpanel" data-example-id="togglable-tabs">
                                            <ul id="machineTypeTabs" class="nav nav-tabs bar_tabs" role="tablist">
                                                <?php
                                                $is_first = true;
                                                foreach ($tabs_to_display as $type):
                                                    $tab_id = 'tab_type_' . $type['machine_type_id'];
                                                    $count = $type_counts[$type['machine_type_id']] ?? 0;
                                                    $display_name = htmlspecialchars($type['machine_type']) . ' <span class="badge bg-blue">' . $count . '</span>';
                                                    $active_class = $is_first ? 'active' : '';
                                                ?>
                                                    <li role="presentation" class="<?= $active_class ?>">
                                                        <a href="#<?= $tab_id ?>" role="tab" data-toggle="tab" aria-expanded="true">
                                                            <?= $display_name ?>
                                                        </a>
                                                    </li>
                                                <?php
                                                    $is_first = false;
                                                endforeach;
                                                ?>
                                            </ul>

                                            <!-- 分頁內容 -->
                                            <div id="machineTypeTabContent" class="tab-content">
                                                <?php
                                                $is_first = true;
                                                foreach ($tabs_to_display as $type):
                                                    $type_id = $type['machine_type_id'];
                                                    $tab_id = 'tab_type_' . $type_id;
                                                    $active_class = $is_first ? 'active in' : '';
                                                ?>
                                                    <div role="tabpanel" class="tab-pane fade <?= $active_class ?>" id="<?= $tab_id ?>">
                                                        <div class="kanban-board">
                                                            <!-- 2. 各機台欄位 -->
                                                            <?php
                                                            $machines_in_type = $machines_by_type[$type_id] ?? [];
                                                            foreach ($machines_in_type as $machine_id):
                                                                $machine_tasks = $tasks_by_machine[$machine_id] ?? [];

                                                                // --- 排序邏輯：有開始紀錄(最新) > 順序 ---
                                                                // 2026.03.06 修正：優先級 Active > Arrived > Sequence
                                                                usort($machine_tasks, function ($a, $b) {
                                                                    // 1. 檢查是否有開始時間 (start_process_date)
                                                                    $a_start = $a['start_process_date'] ?? null;
                                                                    $b_start = $b['start_process_date'] ?? null;

                                                                    // 若都有開始時間，越新的排越前面 (DESC) 
                                                                    // (通常只有一個正在加工，若有多個異常，新的在先)
                                                                    if ($a_start && $b_start) {
                                                                        return strtotime($b_start) <=> strtotime($a_start);
                                                                    }
                                                                    // 若只有 A 有，A 排前
                                                                    if ($a_start) return -1;
                                                                    // 若只有 B 有，B 排前
                                                                    if ($b_start) return 1;

                                                                    // 2. 到料優先 (Order_id=1)
                                                                    $a_arrived = ($a['Order_id'] == 1);
                                                                    $b_arrived = ($b['Order_id'] == 1);
                                                                    if ($a_arrived !== $b_arrived) {
                                                                        return $a_arrived ? -1 : 1;
                                                                    }

                                                                    // 3. 其餘依 processing_sequence 排序 (ASC)
                                                                    $seqA = (int)($a['processing_sequence'] ?? 999999);
                                                                    $seqB = (int)($b['processing_sequence'] ?? 999999);
                                                                    if ($seqA == 0) $seqA = 999999;
                                                                    if ($seqB == 0) $seqB = 999999;
                                                                    return $seqA <=> $seqB;
                                                                });

                                                                $has_open_abnormal = isset($open_abnormalities[$machine_id]);
                                                                $header_class = $has_open_abnormal ? 'kanban-column-header abnormal-header' : 'kanban-column-header';
                                                            ?>
                                                                <div class="kanban-column">
                                                                    <div class="<?= $header_class ?>" data-machine-id="<?= $machine_id ?>" data-machine-name="<?= htmlspecialchars($machine_names[$machine_id]) ?>" title="<?= $header_tooltip ?>" style="position: relative;">
                                                                        <i class="fa fa-server"></i>
                                                                        <?= htmlspecialchars($machine_names[$machine_id] ?? "未知機台 {$machine_id}") ?>
                                                                        <i class="fa fa-exclamation-triangle pull-right btn-abnormal-report" style="cursor: pointer; margin-left: 8px; color: #f0ad4e;" title="機台異常通報"></i>
                                                                        <?php if ($has_open_abnormal): ?><span class="label label-warning" style="margin-left:5px;">異常</span><?php endif; ?>
                                                                        <span class="badge pull-right bg-blue"><?= count($machine_tasks) ?></span>
                                                                    </div>
                                                                    <div class="kanban-cards list-group" id="machine-<?= htmlspecialchars($machine_id) ?>" data-machine-id="<?= htmlspecialchars($machine_id) ?>">
                                                                        <?php
                                                                        $is_first_ing = true;
                                                                        foreach ($machine_tasks as $task_index => $task):
                                                                            // 判斷是否為正在加工的第一張卡片
                                                                            $is_now_processing = ($task['processing_state'] === 'ing' && $is_first_ing);
                                                                            $card_classes = 'kanban-card list-group-item ' . get_priority_class($task['priority_type']);

                                                                            if ($is_now_processing) {
                                                                                $card_classes .= ' now-processing';
                                                                                $is_first_ing = false;
                                                                            } else {
                                                                                $card_classes .= ' waiting-task';
                                                                            }

                                                                            // 狀態標籤顯示邏輯
                                                                            $state_badge = get_state_badge($task['processing_state']);
                                                                            if ($task['processing_state'] === 'ing' && !$is_now_processing) {
                                                                                $state_badge = '<span class="label label-default">待加工</span>';
                                                                            }
                                                                            if ($has_open_abnormal) {
                                                                                // $state_badge = '<span class="label label-danger">暫停中</span>'; // 異常時是否要覆蓋狀態？使用者需求是標題顯示異常，卡片狀態依舊
                                                                                $card_classes .= ' card-abnormal';
                                                                                if ($is_now_processing) {
                                                                                    $state_badge = '<span class="label label-danger">暫停</span>';
                                                                                }
                                                                            }

                                                                            // 顯示順序編號 (使用資料庫中的 processing_sequence，與排程列表一致)
                                                                            $seq_num_assigned = $task['processing_sequence'];
                                                                            $seq_display_html_assigned = '<div style="margin-right:10px;"><span style="display:inline-block; width:24px; height:24px; line-height:24px; text-align:center; background-color:#9B59B6; color:white; font-weight:bold; border-radius:3px;">' . $seq_num_assigned . '</span></div>';

                                                                            // --- 提前計算統計數據 (供所有卡片使用) ---
                                                                            $total_qty = (int)$task['sqty'];
                                                                            $ok_qty = (int)($task['total_ok_qty'] ?? 0);
                                                                            $ng_qty = (int)($task['total_ng_qty'] ?? 0);
                                                                            $percent = ($total_qty > 0) ? min(100, round(($ok_qty / $total_qty) * 100)) : 0;

                                                                            $prod_seconds = $task['total_prod_seconds'] ?? 0;
                                                                            $total_ok = $task['total_ok_qty'] ?? 0;
                                                                            $worked_days = (int)($task['worked_days_count'] ?? 0);
                                                                            if ($total_ok > 0 && $worked_days == 0) $worked_days = 1;

                                                                            $hourly_rate = ($prod_seconds > 0) ? (($total_ok + $ng_qty) / ($prod_seconds / 3600)) : 0;
                                                                            $work_hours_calc = ($daily_working_hours > 0) ? $daily_working_hours : 8;
                                                                            $daily_rate = $hourly_rate * $work_hours_calc;

                                                                            $remaining_qty = max(0, (int)$task['sqty'] - $total_ok);
                                                                            $est_hours = ($hourly_rate > 0) ? ($remaining_qty / $hourly_rate) : 0;
                                                                            $est_days = ($daily_rate > 0) ? ($remaining_qty / $daily_rate) : (($est_hours > 0) ? $est_hours / $work_hours_calc : 0);
                                                                            $est_completion = ($est_hours > 0) ? calculate_completion_time($est_hours, $calendar_map) : '-';

                                                                            // 判斷生管備註是否包含 "急件"
                                                                            $pti01_text = $task['pti01_ps'] ?? '';
                                                                            $is_urgent_pti01 = (strpos($pti01_text, '急件') !== false);
                                                                            $pti01_class = $is_urgent_pti01 ? 'text-danger' : 'text-primary';
                                                                            $pti01_style = $is_urgent_pti01 ? 'font-weight: bold;' : '';

                                                                            // 準備齒輪資料字串 (供 Modal 使用)
                                                                            $gear_info_str = '';
                                                                            if (!empty($task['Module'])) {
                                                                                $modClean = preg_replace('/[^0-9.]/', '', $task['Module']);
                                                                                $gear_info_str = "M" . floatval($modClean) . " T" . floatval($task['Teeth']) . " W" . floatval($task['Face_Width']) . " L" . floatval($task['Workpiece_Length']);
                                                                            }
                                                                        ?>
                                                                            <div class="<?= $card_classes ?>" title="拖曳可調整順序，雙擊可編輯" data-id="<?= $task['bom_ing_fid'] ?>" data-bom="<?= htmlspecialchars($task['bom']) ?>" data-processing-sequence="<?= htmlspecialchars($task['processing_sequence'] ?? '') ?>" data-process-no="<?= $task['process_no'] ?>" data-process-type-id="<?= $task['process_type_id'] ?>" data-state="<?= $task['processing_state'] ?>" data-ps="<?= htmlspecialchars($task['ps'] ?? '', ENT_QUOTES) ?>" data-single-bet-ps="<?= htmlspecialchars($task['single_bet_ps'] ?? '', ENT_QUOTES) ?>" data-pti01-ps="<?= htmlspecialchars($task['pti01_ps'] ?? '', ENT_QUOTES) ?>" data-1-side="<?= htmlspecialchars($task['1_side'] ?? '', ENT_QUOTES) ?>" data-ps2="<?= htmlspecialchars($task['PS2'] ?? '', ENT_QUOTES) ?>" data-client="<?= htmlspecialchars($task['Client_Name']) ?>" data-part-no="<?= htmlspecialchars($task['d_id']) ?>" data-sqty="<?= $task['sqty'] ?>" data-ok-qty="<?= $task['total_ok_qty'] ?? 0 ?>" data-ng-qty="<?= $task['total_ng_qty'] ?? 0 ?>" data-shipping-date="<?= htmlspecialchars($task['shipping_date'] ?? '') ?>" data-gear-info="<?= htmlspecialchars($gear_info_str) ?>" data-part-type="<?= $task['part_type'] ?>" data-ds-id="<?= $task['ds_id'] ?>" data-order-id="<?= $task['Order_id'] ?>">
                                                                                <!-- 標題列：BOM (左) ... 製程 + 狀態 + 順序 (右) -->
                                                                                <div class="card-header-flex">
                                                                                    <h6 class="card-title" style="display:flex; align-items:center;">
                                                                                        <?= $seq_display_html_assigned ?>
                                                                                        <span class="bom-title" style="cursor:pointer;" title="點擊複製" onclick="event.stopPropagation(); copyToClipboard('<?= htmlspecialchars($task['bom']) ?>', this)"><?= htmlspecialchars($task['bom']) ?></span>
                                                                                    </h6>
                                                                                    <div class="card-header-right card-badges">
                                                                                        <span class="card-process-name"><?= htmlspecialchars($task['ProcessName'] ?? $task['process_no']) ?></span>
                                                                                        <span class="card-state-badge"><?= $state_badge ?></span>
                                                                                        <?php if ($can_reorder || $has_U): ?>
                                                                                            <button type="button" class="btn btn-xs btn-default" style="margin-left: 5px; padding: 0 4px;" onclick="event.stopPropagation(); splitTask('<?= $task['bom_ing_fid'] ?>')" title="拆分製程 (多機台加工)">
                                                                                                <i class="fa fa-code-fork"></i>
                                                                                            </button>
                                                                                        <?php endif; ?>
                                                                                        <?php if (($has_A || $has_D) && strpos($task['ps'], '(拆分工單)') !== false): ?>
                                                                                            <button type="button" class="btn btn-xs btn-danger" style="margin-left: 5px; padding: 0 4px;" onclick="event.stopPropagation(); deleteSplitTask('<?= $task['bom_ing_fid'] ?>')" title="刪除拆分工單">
                                                                                                <i class="fa fa-trash"></i>
                                                                                            </button>
                                                                                        <?php endif; ?>
                                                                                    </div>
                                                                                </div>
                                                                                <p class="card-text mb-1">
                                                                                    <i class="fa fa-cube"></i>
                                                                                    <strong>料號:</strong> <a href="/nas/<?= urlencode($task['bom']) ?>.jpg" target="_blank" class="part-no-highlight" style="color: #333; text-decoration: underline;">
                                                                                        <?= htmlspecialchars($task['d_id']) ?>
                                                                                    </a>
                                                                                </p>
                                                                                <?php if ($task['process_type_id'] == 1): ?>
                                                                                    <p class="card-text mb-1">
                                                                                        <?php if ($task['Order_id'] == 1): ?>
                                                                                            <span class="label label-success">已到料</span>
                                                                                        <?php endif; ?>
                                                                                        <?php if (!empty($task['reported_faces'])): ?><?php endif; ?>
                                                                                    </p>
                                                                                <?php endif; ?>
                                                                                <?php
                                                                                // 顯示齒輪資料 (已指派僅顯示模數，若設定開啟)
                                                                                if (!empty($task['Module']) && in_array($task['process_type_id'], $gear_display_types)) {
                                                                                    $modClean = preg_replace('/[^0-9.]/', '', $task['Module']);
                                                                                    $mod = floatval($modClean);
                                                                                    echo '<p class="card-text mb-1"><span class="badge bg-blue module-filter-btn" style="cursor:pointer; font-size: 12px; padding: 4px 8px;" data-module="M' . $mod . '" title="點擊篩選此模數">M' . $mod . '</span></p>';
                                                                                }
                                                                                ?>
                                                                                <?php if (!$is_production): // 生產權限不顯示客戶 
                                                                                ?>
                                                                                    <p class="card-text mb-1">
                                                                                        <i class="fa fa-user"></i>
                                                                                        <strong>客戶:</strong> <?= htmlspecialchars($task['Client_Name']) ?>
                                                                                    </p>
                                                                                <?php endif; ?>
                                                                                <?php if (!empty($task['shipping_date'])): ?>
                                                                                    <p class="card-text small card-shipping-date">
                                                                                        <i class="fa fa-calendar"></i>
                                                                                        <strong>訂單交期:</strong> <?= htmlspecialchars($task['shipping_date']) ?>
                                                                                    </p>
                                                                                <?php endif; ?>
                                                                                <?php if (!empty($task['start_process_date'])): ?>
                                                                                    <p class="card-text small" style="color: #008000;">
                                                                                        <i class="fa fa-clock-o"></i>
                                                                                        <strong>開始:</strong> <?= date('m/d H:i', strtotime($task['start_process_date'])) ?>
                                                                                    </p>
                                                                                <?php endif; ?>
                                                                                <?php if ($task['process_type_id'] == 1 && !empty($task['pti01_ps'])): ?>
                                                                                    <p class="card-text <?= $pti01_class ?> small card-pti01-ps" style="margin-bottom: 2px; <?= $pti01_style ?>">
                                                                                        <i class="fa fa-pencil-square-o"></i> <strong>生管備註:</strong> <?= htmlspecialchars($task['pti01_ps']) ?>
                                                                                    </p>
                                                                                <?php endif; ?>
                                                                                <?php if ($task['process_type_id'] == 1 && !empty($task['face_stats'])): ?>
                                                                                    <?php foreach ($task['face_stats'] as $fs): ?>
                                                                                        <?php if ($fs['face_finished'] == 1): ?>
                                                                                            <p class="card-text text-success small" style="margin-top:2px; margin-bottom: 2px;">
                                                                                                <i class="fa fa-check-circle"></i> <strong><?= htmlspecialchars($fs['process_face']) ?>面完工:</strong> <?= $fs['face_qty'] ?>
                                                                                            </p>
                                                                                        <?php else: ?>
                                                                                            <p class="card-text text-primary small" style="margin-top:2px; margin-bottom: 2px;">
                                                                                                <i class="fa fa-spinner"></i> <strong>目前加工面:</strong> <?= htmlspecialchars($fs['process_face']) ?> (<?= $fs['face_qty'] ?>)
                                                                                            </p>
                                                                                        <?php endif; ?>
                                                                                    <?php endforeach; ?>
                                                                                <?php endif; ?>
                                                                                <?php if (!empty($task['PS2'])): ?>
                                                                                    <p class="card-text text-success small card-ps2" style="margin-bottom: 2px;">
                                                                                        <i class="fa fa-comment-o"></i> <strong>現場備註:</strong> <?= htmlspecialchars($task['PS2']) ?>
                                                                                    </p>
                                                                                <?php endif; ?>
                                                                                <?php if (in_array($task['process_type_id'], $ui_settings['show_face_options']) && !empty($task['1_side'])): ?>
                                                                                    <p class="card-text text-primary small" style="margin-bottom: 2px;">
                                                                                        <i class="fa fa-arrows-h"></i> <strong>加工面:</strong> <?= htmlspecialchars($task['1_side']) ?>
                                                                                    </p>
                                                                                <?php endif; ?>

                                                                                <!-- 詳細資訊區塊 (預設隱藏，透過按鈕顯示) -->
                                                                                <div class="detail-info" style="display: block; border-top: 1px dashed #ccc; margin-top: 5px; padding-top: 5px;">
                                                                                    <?php if (!empty($task['bom_ps'])): ?>
                                                                                        <p class="card-text text-danger small" style="margin-bottom: 2px;">
                                                                                            <i class="fa fa-exclamation-triangle"></i> <strong>BOM:</strong> <?= htmlspecialchars($task['bom_ps']) ?>
                                                                                        </p>
                                                                                    <?php endif; ?>
                                                                                    <?php if (!empty($task['single_bet_ps'])): ?>
                                                                                        <p class="card-text text-info small" style="margin-bottom: 2px;">
                                                                                            <i class="fa fa-info-circle"></i> <strong>備註:</strong> <?= htmlspecialchars($task['single_bet_ps']) ?>
                                                                                        </p>
                                                                                    <?php endif; ?>
                                                                                </div>

                                                                                <!-- 預計完工時間 (修正重疊問題，改為區塊顯示) -->
                                                                                <?php if ($est_completion != '-'): ?>
                                                                                    <p class="card-text text-primary small card-est-completion est-completion-block">
                                                                                        <i class="fa fa-flag-checkered"></i> 預計完工: <?= $est_completion ?>
                                                                                    </p>
                                                                                <?php endif; ?>

                                                                                <!-- 進度條 (所有卡片皆顯示) -->
                                                                                <div class="card-progress-bar" style="display: flex; align-items: center; margin-bottom: 4px; margin-top: 4px;">
                                                                                    <div class="progress" style="flex-grow: 1; margin-bottom: 0; height: 10px; background-color: #e0e0e0; border-radius: 4px;">
                                                                                        <div class="progress-bar progress-bar-success" role="progressbar" style="width: <?= $percent ?>%;"></div>
                                                                                    </div>
                                                                                    <span style="font-size: 10px; margin-left: 5px; font-weight: bold; color: #555;"><?= $percent ?>%</span>
                                                                                </div>

                                                                                <!-- 統計數據 (所有卡片皆顯示) -->
                                                                                <div class="card-stats-row" style="font-size: 11px; color: #555;">
                                                                                    <div style="display: flex; margin-bottom: 2px;">
                                                                                        <span title="良品/總數"><i class="fa fa-check text-success"></i> <?= number_format($ok_qty) ?> / <?= number_format($total_qty) ?></span>
                                                                                        <span title="不良品"><i class="fa fa-times text-danger"></i> <?= number_format($ng_qty) ?></span>
                                                                                        <span title="剩餘數量"><i class="fa fa-hourglass-half"></i> 剩 <?= number_format($remaining_qty) ?></span>
                                                                                    </div>
                                                                                </div>

                                                                                <?php if ($is_now_processing): ?>
                                                                                    <!-- 開始時間與架機資料 -->
                                                                                    <div style="margin-bottom: 3px; font-size: 11px; color: #555;">
                                                                                        <?php if (!empty($task['first_prod_start'])): ?>
                                                                                            <div title="第一筆生產紀錄的開始時間">
                                                                                                <i class="fa fa-clock-o"></i> 生產: <?= date('m/d H:i', strtotime($task['first_prod_start'])) ?>
                                                                                            </div>
                                                                                        <?php endif; ?>
                                                                                    </div>

                                                                                    <!-- 詳細統計數據 (僅加工中顯示) -->
                                                                                    <div style="font-size: 11px; color: #555;">
                                                                                        <!-- Row 2: 產能速率與預計天數 -->
                                                                                        <div style="display: flex; justify-content: space-between; background-color: #f9f9f9; padding: 2px 4px; border-radius: 3px;">
                                                                                            <span title="時產 | 日產">
                                                                                                <i class="fa fa-bolt text-warning"></i> 產能 <?= ($hourly_rate > 0) ? number_format($hourly_rate, 1) : '-' ?>/時
                                                                                                <span style="color:#ccc">|</span>
                                                                                                <?= number_format($daily_rate, 0) ?>/日
                                                                                            </span>
                                                                                            <span class="stats-spacer"></span>
                                                                                            <span title="預計剩餘工作天數" class="card-est-days">
                                                                                                <i class="fa fa-calendar-o"></i> 剩餘加工 <?= number_format($est_days, 1) ?> 日
                                                                                            </span>
                                                                                        </div>
                                                                                    </div>

                                                                                    <!-- 底部資訊：架機與人員 -->
                                                                                    <div style="display: flex; justify-content: space-between; margin-top: 3px; font-size: 10px;">
                                                                                        <span style="color: #337ab7;">
                                                                                            <?php if (!empty($task['first_setup_start'])): ?>
                                                                                                <i class="fa fa-cogs"></i> 架機: <?= date('m/d H:i', strtotime($task['first_setup_start'])) ?>
                                                                                            <?php endif; ?>
                                                                                        </span>
                                                                                        <span style="color: #999;" title="目前操作人員">
                                                                                            <i class="fa fa-user"></i> <?= htmlspecialchars($task['current_operator_name'] ?? '-') ?>
                                                                                        </span>
                                                                                    </div>
                                                                                <?php endif; ?>
                                                                            </div>
                                                                        <?php endforeach; ?>
                                                                    </div>
                                                                </div>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    </div>
                                                <?php
                                                    $is_first = false;
                                                endforeach;
                                                ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 現場每日回報 Modal -->
                    <div class="modal fade" id="quickReportModal" tabindex="-1" role="dialog" aria-labelledby="quickReportModalLabel">
                        <div class="modal-dialog modal-lg" role="document">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                                    <h4 class="modal-title" id="quickReportModalLabel"><i class="fa fa-pencil-square-o"></i> 現場每日製程回報</h4>
                                </div>
                                <div class="modal-body" style="max-height: 70vh; overflow-y: auto;">
                                    <form id="quickReportForm">
                                        <input type="hidden" id="modal_bom_ing_fid" name="bom_ing_fid">
                                        <input type="hidden" id="modal_bom_val"> <!-- 新增：暫存 BOM 號碼 -->
                                        <input type="hidden" id="modal_report_id" name="report_id">
                                        <input type="hidden" id="modal_process_no" name="process_no">
                                        <input type="hidden" id="modal_process_type_id">
                                        <input type="hidden" name="action" value="submit_daily_report">

                                        <!-- 報工模式切換 -->
                                        <div class="row" style="margin-bottom: 10px; border-bottom: 1px solid #eee; padding-bottom: 10px;">
                                            <div class="col-md-12">
                                                <label style="margin-right: 15px;">報工模式：</label>
                                                <label class="radio-inline">
                                                    <input type="radio" name="report_source" value="NORMAL" checked> 正常報工
                                                </label>
                                                <label class="radio-inline">
                                                    <input type="radio" name="report_source" value="PARTIAL"> 補加工
                                                </label>
                                                <label class="radio-inline">
                                                    <input type="radio" name="report_source" value="TEMP"> 臨時加工 (無BOM)
                                                </label>
                                            </div>
                                        </div>

                                        <!-- 補加工專用：BOM 搜尋 -->
                                        <div class="row" id="div_partial_search" style="display: none; margin-bottom: 10px;">
                                            <div class="col-md-12">
                                                <label class="control-label">搜尋工單 (BOM / 料號 / 客戶)</label>
                                                <div class="input-group">
                                                    <input type="text" class="form-control input-sm" id="partial_search_term" placeholder="輸入關鍵字...">
                                                    <span class="input-group-btn"><button class="btn btn-default btn-sm" type="button" id="btn_partial_search"><i class="fa fa-search"></i></button></span>
                                                </div>
                                                <div id="partial_search_results" class="list-group" style="margin-top: 5px; max-height: 150px; overflow-y: auto; display:none;"></div>
                                            </div>
                                        </div>

                                        <!-- 臨時加工專用：製程選擇 -->
                                        <div class="row" id="div_temp_process" style="display: none; margin-bottom: 10px;">
                                            <div class="col-md-12 col-sm-12 col-xs-12">
                                                <label class="control-label">製程 (臨時加工必選) <span class="text-danger">*</span></label>
                                                <select class="form-control input-sm" id="temp_process_select">
                                                    <option value="">-- 請選擇製程 --</option>
                                                </select>
                                            </div>
                                        </div>

                                        <!-- Row 1: 客戶、料號、工單資訊 (同一列) -->
                                        <div class="row">
                                            <div class="col-md-3 col-sm-3 col-xs-12 form-group" style="margin-bottom: 5px;">
                                                <label class="control-label" style="margin-bottom: 2px; font-size: 12px;">客戶</label>
                                                <input type="text" class="form-control input-sm" id="modal_client_name" readonly style="background-color: #eee;">
                                            </div>
                                            <div class="col-md-3 col-sm-3 col-xs-12 form-group" style="margin-bottom: 5px;">
                                                <label class="control-label" style="margin-bottom: 2px; font-size: 12px;">料號</label>
                                                <input type="text" class="form-control input-sm" id="modal_part_no" readonly style="background-color: #eee;">
                                            </div>
                                            <div class="col-md-6 col-sm-6 col-xs-12 form-group" style="margin-bottom: 5px;">
                                                <label class="control-label" style="margin-bottom: 2px; font-size: 12px;">備註</label>
                                                <input type="text" class="form-control input-sm" id="modal_bom_info" readonly style="background-color: #eee; text-align: left !important;">
                                            </div>
                                        </div>

                                        <!-- Row 2: 日期、機台、人員 (緊湊排列) -->
                                        <div class="row" style="background-color: #f7f7f7; padding-top: 5px; margin-bottom: 5px; border-radius: 4px;">
                                            <div class="col-md-3 col-sm-3 col-xs-6 form-group" style="margin-bottom: 5px;">
                                                <label class="control-label" style="margin-bottom: 2px; font-size: 12px;">回報日期 <span class="text-danger">*</span></label>
                                                <input type="date" class="form-control input-sm" name="report_date" value="<?= date('Y-m-d') ?>" readonly>
                                            </div>
                                            <div class="col-md-3 col-sm-3 col-xs-6 form-group" style="margin-bottom: 5px;">
                                                <label class="control-label" style="margin-bottom: 2px; font-size: 12px;">機台 <span class="text-danger">*</span></label>
                                                <select class="form-control input-sm" id="modal_machine_id" name="machine_id" required></select>
                                            </div>
                                            <div class="col-md-3 col-sm-3 col-xs-6 form-group user-select-group" id="div_setup_user" style="margin-bottom: 5px;">
                                                <label class="control-label" style="margin-bottom: 2px; font-size: 12px;">架機人員</label>
                                                <select class="form-control input-sm" name="setup_user_id" id="modal_setup_user_id">
                                                    <option value="">-- 選擇 --</option>
                                                    <?php foreach ($user_list as $u): ?>
                                                        <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['dept_name'] . ' ' . $u['pos_name'] . ' ' . $u['user_cname']) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-3 col-sm-3 col-xs-6 form-group user-select-group" id="div_production_user" style="margin-bottom: 5px;">
                                                <label class="control-label" style="margin-bottom: 2px; font-size: 12px;">生產人員</label>
                                                <select class="form-control input-sm" name="production_user_id" id="modal_production_user_id">
                                                    <option value="">-- 選擇 --</option>
                                                    <?php foreach ($user_list as $u): ?>
                                                        <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['dept_name'] . ' ' . $u['pos_name'] . ' ' . $u['user_cname']) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>

                                        <!-- Row 3: 時間 (緊湊) -->
                                        <div class="row time-input-group">
                                            <div class="col-md-3 col-sm-3 col-xs-6 form-group" id="div_setup_start" style="margin-bottom: 5px;">
                                                <label style="font-size: 11px; margin-bottom: 0;">架機開始</label>
                                                <input type="text" class="form-control input-sm datetime-picker" name="setup_start_time" id="modal_setup_start_time" style="font-size: 11px;" autocomplete="off" disabled>
                                            </div>
                                            <div class="col-md-3 col-sm-3 col-xs-6 form-group" id="div_setup_end" style="margin-bottom: 5px;">
                                                <label style="font-size: 11px; margin-bottom: 0;">架機結束</label>
                                                <input type="text" class="form-control input-sm datetime-picker" name="setup_end_time" id="modal_setup_end_time" style="font-size: 11px;" autocomplete="off" disabled>
                                            </div>
                                            <div class="col-md-3 col-sm-3 col-xs-6 form-group" style="margin-bottom: 5px;">
                                                <label style="font-size: 11px; margin-bottom: 0;">生產開始</label>
                                                <input type="text" class="form-control input-sm datetime-picker" name="production_start_time" id="modal_production_start_time" style="font-size: 11px;" autocomplete="off" disabled>
                                            </div>
                                            <div class="col-md-3 col-sm-3 col-xs-6 form-group" style="margin-bottom: 5px;">
                                                <label style="font-size: 11px; margin-bottom: 0;">生產結束</label>
                                                <input type="text" class="form-control input-sm datetime-picker" name="production_end_time" id="modal_production_end_time" style="font-size: 11px;" autocomplete="off" disabled>
                                            </div>
                                        </div>

                                        <!-- 齒輪資訊 (Type=G 專用) -->
                                        <div class="row" id="div_gear_info" style="display:none; background-color: #dff0d8; padding: 10px; margin: 0 0 10px 0; border: 2px solid #3c763d; border-radius: 5px;">
                                            <div class="col-md-10 col-sm-10 col-xs-9 form-group" style="margin-bottom: 0;">
                                                <label class="control-label" style="margin-bottom: 5px; font-size: 14px; color: #3c763d; font-weight: bold;">齒輪規格</label>
                                                <div id="modal_gear_info_text" style="font-size: 16px; font-weight: bold; color: #333;"></div>
                                            </div>
                                            <div class="col-md-2 col-sm-2 col-xs-3 text-right" style="padding-top: 15px;">
                                                <button type="button" class="btn btn-xs btn-warning" id="btn_edit_gear_data">設定</button>
                                            </div>
                                        </div>

                                        <!-- 車床專用資訊 (Process Type ID = 1) -->
                                        <div class="row" id="div_pti01_fields" style="display:none; background-color: #f0f8ff; padding: 5px; margin: 0 0 5px 0; border: 1px dashed #337ab7;">
                                            <div class="col-md-12 col-sm-12 col-xs-12 form-group" style="margin-bottom: 5px;">
                                                <label class="control-label" style="margin-bottom: 2px; font-size: 11px;">生管備註 (車床) &nbsp;
                                                    <span id="span_material_arrived">
                                                        <label style="font-weight:bold; cursor:pointer; color:#d9534f; font-size:12px;">
                                                            <input type="checkbox" name="material_arrived" value="1"> 已到料
                                                            <input type="hidden" name="material_arrived_present" value="1">
                                                        </label>
                                                    </span>
                                                </label>
                                                <textarea class="form-control input-sm" name="pti01_ps" rows="1"></textarea>
                                            </div>
                                        </div>

                                        <!-- 加工面 (獨立顯示) -->
                                        <div class="row" id="div_1_side" style="display:none; background-color: #f0f8ff; padding: 5px; margin: 0 0 5px 0; border: 1px dashed #337ab7;">
                                            <div class="col-md-12 col-sm-12 col-xs-12 form-group" style="margin-bottom: 0;">
                                                <label class="control-label" style="margin-bottom: 2px; font-size: 11px;">加工面 <span class="text-danger">*</span></label>
                                                <select class="form-control input-sm" name="process_face">
                                                    <option value="">-- 選擇 --</option>
                                                    <option value="A">A面</option>
                                                    <option value="B">B面</option>
                                                    <option value="C">C面</option>
                                                    <option value="D">D面</option>
                                                    <option value="ALL">一次完成</option>
                                                </select>
                                            </div>
                                        </div>

                                        <!-- 例外原因 (補加工/臨時加工) -->
                                        <div class="row" id="div_source_reason" style="display: none; margin-bottom: 5px;">
                                            <div class="col-md-12 col-sm-12 col-xs-12 form-group">
                                                <label class="control-label" style="margin-bottom: 2px; font-size: 12px; color: #d9534f;">例外原因 <span class="text-danger">*</span></label>
                                                <select class="form-control input-sm" name="source_reason" id="source_reason">
                                                    <option value="補加工">補加工</option>
                                                    <option value="插單">插單</option>
                                                    <option value="試作">試作</option>
                                                    <option value="重工">重工</option>
                                                    <option value="其他">其他</option>
                                                </select>
                                            </div>
                                        </div>

                                        <hr style="margin: 5px 0;">

                                        <!-- 生產結果 & 統計 -->
                                        <h5 style="margin: 5px 0; font-weight: bold;">生產結果</h5>

                                        <!-- 正常報工模式區塊 -->
                                        <div>
                                            <!-- 統計資訊 -->
                                            <div id="stats-container" class="well well-sm" style="margin-bottom: 10px; padding: 5px; background-color: #e6f2ff; border-color: #b8daff;">
                                                <div class="row" style="margin: 0;">
                                                    <div class="col-xs-3 text-center">總數: <span id="modal_stats_total" class="badge">0</span></div>
                                                    <div class="col-xs-3 text-center">已加工: <span id="modal_stats_ok" class="badge bg-green">0</span></div>
                                                    <div class="col-xs-3 text-center">已NG: <span id="modal_stats_ng" class="badge bg-red">0</span></div>
                                                    <div class="col-xs-3 text-center">剩餘: <span id="modal_stats_remaining" class="badge bg-orange">0</span></div>
                                                </div>
                                            </div>

                                            <div class="row" id="production-input-container">
                                                <div class="col-md-3 col-sm-3 col-xs-6 form-group" style="margin-bottom: 5px;">
                                                    <label class="control-label" style="margin-bottom: 2px; font-size: 12px;">本日完成 <small class="text-danger">(良品)</small></label>
                                                    <input type="number" class="form-control input-sm" id="modal_produced_qty" name="produced_qty" placeholder="0">
                                                    <div id="avg_hourly_rate_display" style="font-size: 11px; color: #172D44; margin-top: 2px; font-weight: bold;"></div>
                                                </div>
                                                <div class="col-md-2 col-sm-2 col-xs-6 form-group" style="margin-bottom: 5px;">
                                                    <label style="display:block; margin-bottom: 2px;">&nbsp;</label>
                                                    <div class="checkbox" style="margin-top: 0;">
                                                        <label><input type="checkbox" name="is_finished" value="1"> <strong>本站完工</strong></label>
                                                    </div>
                                                </div>
                                                <div class="col-md-3 col-sm-3 col-xs-6 form-group" id="div_face_finish_checkbox" style="margin-bottom: 5px;"><label style="display:block; margin-bottom: 2px;">&nbsp;</label>
                                                    <div class="checkbox" style="margin-top: 0;"><label><input type="checkbox" name="is_face_finished" value="1"> <strong style="color: #337ab7;">此面完工後換面</strong></label></div>
                                                </div>
                                                <div class="col-md-4 col-sm-4 col-xs-12 form-group" style="margin-bottom: 5px;">
                                                    <label class="control-label" style="margin-bottom: 2px; font-size: 12px;">備註</label>
                                                    <textarea class="form-control input-sm" name="remark" rows="1"></textarea>
                                                </div>
                                            </div>

                                            <!-- NG Table -->
                                            <div id="ng-table-container" style="margin-top: 5px;">
                                                <label style="font-size: 12px;">NG 紀錄 <button type="button" class="btn btn-xs btn-danger" id="addNgRowBtn" style="margin-left: 5px;"><i class="fa fa-plus"></i></button></label>
                                                <div style="max-height: 100px; overflow-y: auto;">
                                                    <table class="table table-bordered table-condensed table-striped" id="ngTable" style="margin-bottom: 0; font-size: 12px;">
                                                        <thead>
                                                            <tr>
                                                                <th width="40%">原因</th>
                                                                <th width="20%">數量</th>
                                                                <th>備註</th>
                                                                <th width="30"></th>
                                                            </tr>
                                                        </thead>
                                                        <tbody></tbody>
                                                    </table>
                                                </div>
                                            </div>

                                            <!-- 一般報工歷史紀錄 -->
                                            <div style="margin-top: 10px;">
                                                <label style="font-size: 12px;">最近 10 筆生產報工紀錄</label>
                                                <div style="max-height: 120px; overflow-y: auto;">
                                                    <table class="table table-bordered table-condensed table-hover" id="historyTable" style="margin-bottom: 0; font-size: 11px; background-color: #fff;">
                                                        <thead>
                                                            <tr class="active">
                                                                <th>日期</th>
                                                                <th>機台</th>
                                                                <th>加工面</th>
                                                                <th>架機</th>
                                                                <th>生產</th>
                                                                <th>良品</th>
                                                                <th>NG</th>
                                                                <th>時產</th>
                                                                <th>人員</th>
                                                                <th>操作</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody></tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
                                    <?php if ($can_report): ?>
                                        <button type="button" class="btn btn-primary" id="saveQuickReportBtn">儲存變更</button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 機台異常通報 Modal (獨立) -->
                    <div class="modal fade" id="machineAbnormalModal" tabindex="-1" role="dialog">
                        <div class="modal-dialog modal-lg" role="document">
                            <div class="modal-content">
                                <div class="modal-header" style="background-color: #d9534f; color: white;">
                                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true" style="color: white;">&times;</span></button>
                                    <h4 class="modal-title"><i class="fa fa-exclamation-triangle"></i> 機台異常通報 - <span id="abnormal_machine_name"></span></h4>
                                </div>
                                <div class="modal-body">
                                    <form id="machineAbnormalForm">
                                        <input type="hidden" name="action" value="submit_machine_abnormal">
                                        <input type="hidden" id="abnormal_machine_id" name="machine_id">
                                        <input type="hidden" id="abnormal_sub_action" name="sub_action" value="">
                                        <input type="hidden" id="modal_abnormal_id" name="abnormal_id">

                                        <div class="row">
                                            <!-- 左側：輸入表單 -->
                                            <div class="col-md-5 col-sm-5 col-xs-12">
                                                <div class="form-group" id="fg_abnormal_type">
                                                    <label class="control-label">異常類型 <span class="text-danger">*</span></label>
                                                    <div class="input-group">
                                                        <select class="form-control" id="abnormal_type_id" name="abnormal_type_id">
                                                            <option value="">-- 請選擇 --</option>
                                                            <?php foreach ($abnormal_types as $at): ?>
                                                                <option value="<?= $at['abnormal_type_id'] ?>"><?= htmlspecialchars($at['abnormal_name']) ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                        <?php if ($can_settings): ?>
                                                            <span class="input-group-btn">
                                                                <button class="btn btn-default" type="button" id="manageAbnormalTypeBtn" title="管理異常類型"><i class="fa fa-plus"></i></button>
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <div class="form-group" id="fg_abnormal_start">
                                                    <label class="control-label">異常開始時間 <span class="text-danger">*</span></label>
                                                    <input type="text" class="form-control datetime-picker" name="abnormal_start_time" id="abnormal_start_time">
                                                </div>
                                                <div class="form-group" id="fg_abnormal_end">
                                                    <label class="control-label">異常結束時間 (結案時填寫)</label>
                                                    <input type="text" class="form-control datetime-picker" name="abnormal_end_time" id="abnormal_end_time">
                                                </div>
                                                <div class="form-group" id="fg_abnormal_desc">
                                                    <label class="control-label">異常說明 / 處理進度</label>
                                                    <textarea class="form-control" id="abnormal_desc" name="abnormal_desc" rows="5" placeholder="請輸入異常詳細說明"></textarea>
                                                </div>

                                                <!-- 新增：進度/結案用的動態欄位 -->
                                                <div class="form-group" id="fg_action_time" style="display:none;">
                                                    <label class="control-label" id="lbl_action_time">時間</label>
                                                    <input type="text" class="form-control datetime-picker" name="action_time" id="action_time">
                                                </div>
                                                <div class="form-group" id="fg_action_desc" style="display:none;">
                                                    <label class="control-label" id="lbl_action_desc">說明</label>
                                                    <textarea class="form-control" name="action_desc" id="action_desc" rows="3"></textarea>
                                                </div>

                                                <?php if ($can_report): ?>
                                                    <button type="button" class="btn btn-danger btn-block" id="saveAbnormalBtn">提交</button>
                                                <?php endif; ?>
                                                <button type="button" class="btn btn-default btn-block" id="resetAbnormalBtn" style="display:none;">取消 / 返回新增</button>
                                            </div>

                                            <!-- 右側：異常案件列表 -->
                                            <div class="col-md-7 col-sm-7 col-xs-12">
                                                <label>未結案異常</label>
                                                <table class="table table-bordered table-condensed table-hover" id="abnormalOpenTable" style="font-size: 11px; background-color: #fff;">
                                                    <thead>
                                                        <tr class="danger">
                                                            <th>時間</th>
                                                            <th>類型</th>
                                                            <th>說明/進度</th>
                                                            <th>通報人</th>
                                                            <th>操作</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody></tbody>
                                                </table>
                                                <label style="margin-top: 10px;">歷史紀錄 (最近 10 筆)</label>
                                                <table class="table table-bordered table-condensed" id="abnormalHistoryTable" style="font-size: 11px; background-color: #fff;">
                                                    <thead>
                                                        <tr class="active">
                                                            <th>時間</th>
                                                            <th>類型</th>
                                                            <th>狀態</th>
                                                            <th>通報人</th>
                                                            <th>操作</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody></tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 齒輪設定 Modal -->
                    <div class="modal fade" id="gearSettingModal" tabindex="-1" role="dialog">
                        <div class="modal-dialog modal-lg" role="document">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                                    <h4 class="modal-title">齒輪規格設定</h4>
                                </div>
                                <div class="modal-body">
                                    <form id="gearSettingForm">
                                        <input type="hidden" id="gear_d_id">
                                        <div class="row">
                                            <div class="col-md-6 form-group">
                                                <label>料號</label>
                                                <input type="text" id="gear_part_no" class="form-control" readonly>
                                            </div>
                                            <div class="col-md-6 form-group">
                                                <label>客戶</label>
                                                <input type="text" id="gear_client_name" class="form-control" readonly>
                                            </div>
                                        </div>
                                        <hr>
                                        <div id="gear-rows-container">
                                            <!-- 動態生成齒輪列 -->
                                        </div>
                                        <button type="button" class="btn btn-success btn-xs" id="btn-add-gear-row"><i class="fa fa-plus"></i> 新增齒輪</button>
                                    </form>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
                                    <button type="button" class="btn btn-primary" id="btnSaveGearData">儲存</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 部門設定 Modal -->
                    <div class="modal fade" id="deptSettingModal" tabindex="-1" role="dialog">
                        <div class="modal-dialog modal-sm" role="document">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                                    <h4 class="modal-title">設定生產部門</h4>
                                </div>
                                <div class="modal-body">
                                    <form id="deptSettingForm">
                                        <input type="hidden" name="action" value="save_department_setting">
                                        <div class="form-group">
                                            <label>選擇部門 (Level 3)</label>
                                            <select class="form-control" name="department_id">
                                                <?php foreach ($l3_depts as $d): ?>
                                                    <option value="<?= $d['id'] ?>" <?= $d['id'] == $dept_setting_id ? 'selected' : '' ?>><?= htmlspecialchars($d['name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="form-group">
                                            <label>每日工時 (小時)</label>
                                            <input type="number" class="form-control" name="daily_working_hours" value="<?= $daily_working_hours ?>" step="0.5" min="0">
                                        </div>
                                    </form>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-primary" id="saveDeptSettingBtn">儲存</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 個人顯示設定 Modal -->
                    <div class="modal fade" id="personalSettingModal" tabindex="-1" role="dialog">
                        <div class="modal-dialog modal-sm" role="document">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                                    <h4 class="modal-title"><i class="fa fa-eye"></i> 個人顯示設定</h4>
                                </div>
                                <div class="modal-body">
                                    <form id="personalSettingForm">
                                        <input type="hidden" name="action" value="save_personal_setting">
                                        <p class="text-muted small">勾選要顯示的項目：</p>
                                        <div class="checkbox"><label><input type="checkbox" name="settings[show_shipping_date]" value="1" <?= $personal_settings['show_shipping_date'] ? 'checked' : '' ?>> 訂單交期</label></div>
                                        <div class="checkbox"><label><input type="checkbox" name="settings[show_ps2]" value="1" <?= $personal_settings['show_ps2'] ? 'checked' : '' ?>> 現場備註 (PS2)</label></div>
                                        <div class="checkbox"><label><input type="checkbox" name="settings[show_pti01_ps]" value="1" <?= $personal_settings['show_pti01_ps'] ? 'checked' : '' ?>> 生管備註</label></div>
                                        <div class="checkbox"><label><input type="checkbox" name="settings[show_est_completion]" value="1" <?= $personal_settings['show_est_completion'] ? 'checked' : '' ?>> 預計完工時間</label></div>
                                        <div class="checkbox"><label><input type="checkbox" name="settings[show_progress]" value="1" <?= $personal_settings['show_progress'] ? 'checked' : '' ?>> 進度條</label></div>
                                        <div class="checkbox"><label><input type="checkbox" name="settings[show_est_days]" value="1" <?= $personal_settings['show_est_days'] ? 'checked' : '' ?>> 剩餘加工日</label></div>
                                    </form>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-primary" id="savePersonalSettingBtn">儲存設定</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 機台設定 Modal -->
                    <div class="modal fade" id="machineSettingModal" tabindex="-1" role="dialog">
                        <div class="modal-dialog modal-lg" role="document">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                                    <h4 class="modal-title">機台管理設定</h4>
                                </div>
                                <div class="modal-body">
                                    <div class="row">
                                        <!-- 左側：機台列表 -->
                                        <div class="col-md-8 col-sm-8 col-xs-12">
                                            <div style="max-height: 400px; overflow-y: auto; overflow-x: auto;">
                                                <table class="table table-bordered table-striped table-condensed" id="machineListTable" style="font-size: 12px; white-space: nowrap;">
                                                    <thead>
                                                        <tr>
                                                            <th>名稱</th>
                                                            <th>機台編號</th>
                                                            <th>現場編號</th>
                                                            <th>機型</th>
                                                            <th>類型</th>
                                                            <th>位置(廠別)</th>
                                                            <th>需架機</th>
                                                            <th width="100">操作</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody></tbody>
                                                </table>
                                            </div>
                                            <div style="margin-top: 15px; border-top: 1px solid #eee; padding-top: 10px;">
                                                <label>儀表板顯示設定 (勾選要在資料塊顯示齒輪模數的機台類型)</label>
                                                <div id="gear-settings-checkboxes" style="display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 10px;"></div>
                                                <button type="button" class="btn btn-info btn-sm" id="btnSaveGearSettings">儲存顯示設定</button>
                                            </div>
                                        </div>
                                        <!-- 右側：編輯表單 -->
                                        <div class="col-md-4 col-sm-4 col-xs-12">
                                            <div class="panel panel-default">
                                                <div class="panel-heading" id="machineFormTitle">新增機台</div>
                                                <div class="panel-body">
                                                    <form id="machineSettingForm">
                                                        <input type="hidden" name="action" value="save_machine">
                                                        <input type="hidden" name="machine_id" id="setting_machine_id">

                                                        <div class="form-group">
                                                            <label>機台名稱 <span class="text-danger">*</span></label>
                                                            <input type="text" class="form-control input-sm" name="machine" id="setting_machine_name" required>
                                                        </div>
                                                        <div class="form-group">
                                                            <label>機台編號 <span class="text-muted">(公司財產編號)</span></label>
                                                            <input type="text" class="form-control input-sm" name="asset_no" id="setting_asset_no">
                                                        </div>
                                                        <div class="form-group">
                                                            <label>現場自訂編號</label>
                                                            <input type="text" class="form-control input-sm" name="field_no" id="setting_field_no">
                                                        </div>
                                                        <div class="form-group">
                                                            <label>機型</label>
                                                            <input type="text" class="form-control input-sm" name="machine_model" id="setting_machine_model">
                                                        </div>
                                                        <div class="form-group">
                                                            <label>機台類型 <span class="text-danger">*</span></label>
                                                            <select class="form-control input-sm" name="machine_type_id" id="setting_machine_type" required></select>
                                                        </div>
                                                        <div class="form-group">
                                                            <label>位置 (廠別)</label>
                                                            <input type="text" class="form-control input-sm" name="position" id="setting_position" placeholder="例如: 1">
                                                        </div>
                                                        <div class="form-group">
                                                            <label>是否需要架機</label>
                                                            <select class="form-control input-sm" name="need_setup" id="setting_need_setup">
                                                                <option value="1">需要 (1)</option>
                                                                <option value="0">不需要 (0)</option>
                                                            </select>
                                                        </div>
                                                        <div class="form-group">
                                                            <label>規格</label>
                                                            <input type="text" class="form-control input-sm" name="spec" id="setting_spec">
                                                        </div>
                                                        <div class="form-group">
                                                            <label>備註</label>
                                                            <textarea class="form-control input-sm" name="note" id="setting_note" rows="2"></textarea>
                                                        </div>

                                                        <div class="text-right">
                                                            <button type="button" class="btn btn-default btn-sm" id="btnResetMachineForm">重置 / 新增</button>
                                                            <button type="button" class="btn btn-primary btn-sm" id="btnSaveMachine">儲存</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 全域排序 Modal -->
                    <div class="modal fade" id="scheduleListModal" tabindex="-1" role="dialog">
                        <div class="modal-dialog" role="document">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                                    <h4 class="modal-title"><i class="fa fa-list-alt"></i> 調整排程順序</h4>
                                </div>
                                <div class="modal-body" style="max-height: 70vh; overflow-y: auto; background-color: #f7f7f7;">
                                    <ul class="list-group" id="schedule-sort-list"></ul>
                                    <p class="text-muted text-center" style="margin-top: 10px;"><i class="fa fa-info-circle"></i> 拖曳項目調整順序，關閉視窗後自動儲存。</p>
                                </div>
                            </div>
                        </div>
                    </div>


                    <!-- 異常類型管理 Modal -->
                    <div class="modal fade" id="abnormalTypeModal" tabindex="-1" role="dialog" style="z-index: 1060;">
                        <div class="modal-dialog modal-sm" role="document">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                                    <h4 class="modal-title">管理異常類型</h4>
                                </div>
                                <div class="modal-body">
                                    <div class="input-group">
                                        <input type="text" class="form-control" id="new_abnormal_name" placeholder="輸入新類型名稱">
                                        <span class="input-group-btn">
                                            <button class="btn btn-success" type="button" id="addAbnormalTypeBtn">新增</button>
                                        </span>
                                    </div>
                                    <hr>
                                    <ul class="list-group" id="abnormalTypeList" style="max-height: 300px; overflow-y: auto;">
                                        <?php foreach ($abnormal_types as $at): ?>
                                            <li class="list-group-item clearfix">
                                                <?= htmlspecialchars($at['abnormal_name']) ?>
                                                <button class="btn btn-xs btn-danger pull-right delete-abnormal-type" data-id="<?= $at['abnormal_type_id'] ?>"><i class="fa fa-trash"></i></button>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 未指派排序 Modal -->
                    <div class="modal fade" id="unassignedSortModal" tabindex="-1" role="dialog">
                        <div class="modal-dialog" role="document">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                                    <h4 class="modal-title"><i class="fa fa-sort"></i> 調整未指派順序 (拖曳調整)</h4>
                                </div>
                                <div class="modal-body" style="max-height: 70vh; overflow-y: auto; background-color: #f7f7f7;">
                                    <ul class="list-group" id="unassigned-sort-list"></ul>
                                    <p class="text-muted text-center" style="margin-top: 10px;"><i class="fa fa-info-circle"></i> 拖曳項目調整順序，關閉視窗後自動儲存。</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 查詢已完工 Modal -->
                    <div class="modal fade" id="searchFinishedModal" tabindex="-1" role="dialog">
                        <div class="modal-dialog" role="document" style="width: 80%;">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                                    <h4 class="modal-title">查詢已報工工單 (預設顯示100筆報工)</h4>
                                </div>
                                <div class="modal-body">
                                    <div class="input-group">
                                        <input type="text" class="form-control" id="finished_search_term" placeholder="輸入 BOM, 料號, 或客戶...">
                                        <span class="input-group-btn">
                                            <button class="btn btn-primary" type="button" id="doSearchFinishedBtn">搜尋</button>
                                        </span>
                                    </div>
                                    <hr>
                                    <div class="list-group" id="finished_search_results" style="max-height: 60vh; overflow-y: auto;">
                                        <!-- Results go here -->
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Dashboard Detail Modal -->
                    <div class="modal fade" id="dashboardDetailModal" tabindex="-1" role="dialog">
                        <div class="modal-dialog modal-sm" role="document">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                                    <h4 class="modal-title" id="dashDetailTitle">機台詳情</h4>
                                </div>
                                <div class="modal-body">
                                    <div id="dashDetailContent"></div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-default" data-dismiss="modal">關閉</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 機台時程表 Modal -->
                    <div class="modal fade" id="machineScheduleModal" tabindex="-1" role="dialog">
                        <div class="modal-dialog modal-lg" role="document" style="width: 80%;">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                                    <h4 class="modal-title"><i class="fa fa-calendar"></i> 機台 24H 時程表 (檢視重疊)</h4>
                                </div>
                                <div class="modal-body">
                                    <div class="form-inline" style="margin-bottom: 10px;">
                                        <div class="form-group">
                                            <label>機台類別：</label>
                                            <select id="schedule_machine_type" class="form-control input-sm">
                                                <option value="">全部</option>
                                                <?php foreach ($all_machine_types as $type): ?>
                                                    <option value="<?= $type['machine_type_id'] ?>"><?= htmlspecialchars($type['machine_type']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="form-group">
                                            <label>日期：</label>
                                            <button type="button" class="btn btn-default btn-sm" id="btnPrevDay"><i class="fa fa-chevron-left"></i></button>
                                            <input type="date" id="schedule_date" class="form-control input-sm" value="<?= date('Y-m-d') ?>">
                                            <button type="button" class="btn btn-default btn-sm" id="btnNextDay"><i class="fa fa-chevron-right"></i></button>
                                        </div>
                                        <button type="button" class="btn btn-primary btn-sm" id="btnRefreshSchedule">查詢</button>
                                        <span class="text-muted" style="margin-left: 10px;">* 藍色: 架機 / 綠色: 生產 (高度代表時間長度)</span>
                                    </div>
                                    <div id="schedule-container" class="schedule-container">
                                        <!-- JS Rendered Content -->
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ================================================== -->
<!--  AJAX 共用動態跳窗 (Shared Dynamic Modal)            -->
<!-- ================================================== -->
<div class="modal fade" id="sharedDynamicModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal">&times;</button>
                <h4 class="modal-title" id="sharedModalTitle">載入中...</h4>
            </div>
            <div class="modal-body" id="sharedModalBody" style="min-height: 200px;">
                <div class="text-center"><i class="fa fa-spinner fa-spin fa-3x"></i></div>
            </div>
        </div>
    </div>
</div>


                    <!-- 頁尾 -->
                    <?php include '../partPage/footer.html' ?>
                </div>
            </div>

            <!-- 統一的回到頂端按鈕 -->
            <button class="scroll-to-top" onclick="scrollToTop()">回頂端</button>

            <!-- Custom Notification Element -->
            <div id="custom-toast"></div>

            <!-- 原有 Scripts -->
            <script src="../../resource/js/jquery.min.js"></script>
            <script src="../../resource/js/bootstrap.min.js"></script>
            <script>
                // 修正 custom.min.js 中 progressbar 函式缺失的錯誤
                if (typeof $.fn.progressbar === 'undefined') {
                    $.fn.progressbar = function() {
                        return this;
                    };
                }
            </script>
            <script src="../../resource/js/custom.min.js"></script>

            <!-- SortableJS Library -->
            <script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>
            <!-- Select2 -->
            <script src="https://cdn.jsdelivr.net/npm/select2@4.0.13/dist/js/select2.min.js"></script>
            <!-- Flatpickr -->
            <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
            <script src="https://npmcdn.com/flatpickr/dist/l10n/zh-tw.js"></script>

            <script>
                // 回到頂端功能
                function scrollToTop() {
                    window.scrollTo({
                        top: 0,
                        behavior: 'smooth'
                    });
                }

                // 將 PHP 機台資料傳遞給 JS
                var allMachines = <?= json_encode($all_machines_raw) ?>;

                // 機台選單顯示文字：現場編號（未填才退回機台編號/機型，皆未填才退回機台名稱＋位置）
                function machineOptionLabel(m) {
                    if (m.field_no && String(m.field_no).trim() !== '') return m.field_no;
                    var parts = [m.asset_no, m.machine_model].filter(function(v) { return v && String(v).trim() !== ''; });
                    if (parts.length === 0) return m.machine + ' (' + m.position + ')';
                    return parts.join(' ');
                }
                var openAbnormalities = <?= json_encode($open_abnormalities) ?>;
                var ngOptionsList = <?= json_encode($ng_list) ?>;
                var allProcesses = <?= json_encode($all_processes) ?>;

                // 權限設定
                var canReorder = <?= json_encode($can_reorder) ?>;
                var displayPermissionCode = '<?= $display_permission_code ?>';
                var userPerms = {
                    hasA: <?= $has_A ? 'true' : 'false' ?>,
                    hasD: <?= $has_D ? 'true' : 'false' ?>,
                    canDrag: <?= $can_drag ? 'true' : 'false' ?>,
                    canManageMaterial: <?= $can_manage_material ? 'true' : 'false' ?>,
                    canReport: <?= $can_report ? 'true' : 'false' ?>,
                    canSettings: <?= $can_settings ? 'true' : 'false' ?>,
                    canEditPti01: <?= $can_edit_pti01 ? 'true' : 'false' ?>,
                    canEditPs2: <?= $can_edit_ps2 ? 'true' : 'false' ?>,
                    canAddMachine: <?= $can_add_machine ? 'true' : 'false' ?>,
                    canEditMachine: <?= $can_edit_machine ? 'true' : 'false' ?>,
                    canDeleteMachine: <?= $can_delete_machine ? 'true' : 'false' ?>,
                    isProdControl: <?= $is_prod_control ? 'true' : 'false' ?>,
                    isProduction: <?= $is_production ? 'true' : 'false' ?>,
                    canChangePartialBom: <?= $can_change_partial_bom ? 'true' : 'false' ?>
                };

                // 全域 AJAX 處理：攔截 Session Timeout (針對 jQuery)
                $(document).ajaxSuccess(function(event, xhr, settings) {
                    try {
                        var res = xhr.responseJSON || JSON.parse(xhr.responseText);
                        if (res && res.timeout) {
                            alert(res.message);
                            window.location.href = res.redirect || '../../index.php';
                        }
                    } catch (e) {}
                });

                document.addEventListener('DOMContentLoaded', function() {
                    var existingReports = []; // 儲存歷史紀錄供前端驗證使用

                    // Initialize Flatpickr
                    // 針對開始時間 (預設 08:00)
                    $(".datetime-picker[name$='start_time']").flatpickr({
                        enableTime: true,
                        dateFormat: "Y-m-d H:i",
                        time_24hr: true,
                        locale: "zh_tw",
                        allowInput: true,
                        defaultHour: 8,
                        defaultMinute: 0
                    });
                    // 針對結束時間 (預設 17:00)
                    $(".datetime-picker[name$='end_time']").flatpickr({
                        enableTime: true,
                        dateFormat: "Y-m-d H:i",
                        time_24hr: true,
                        locale: "zh_tw",
                        allowInput: true,
                        defaultHour: 17,
                        defaultMinute: 0
                    });

                    // --- 強制設定為 nav-sm (收合) 模式，並修復子選單點擊功能 ---
                    $(document).ready(function() {
                        // 初始化 Bootstrap Popover (權限說明懸浮視窗)
                        $('[data-toggle="popover"]').popover();

                        // 1. 強制收合側邊欄 (覆蓋 custom.js 可能的預設行為)
                        $('body').removeClass('nav-md').addClass('nav-sm');
                        $('.left_col').removeClass('scroll-view').removeAttr('style');
                        $('.sidebar-footer').hide();

                        // 修正：移除 custom.js 或其他邏輯造成的選單展開 (移除 inline style 與 active-sm)
                        $('#sidebar-menu li ul').removeAttr('style');
                        $('#sidebar-menu li').removeClass('active-sm');

                        // 2. 重新綁定側邊欄選單點擊事件 (修復 nav-md 模式下無法展開子選單的問題)
                        $('#sidebar-menu').find('a').off('click').on('click', function(ev) {
                            var $li = $(this).parent();
                            if ($('body').hasClass('nav-md')) {
                                if ($('ul:first', $li).length > 0) {
                                    ev.preventDefault();
                                    ev.stopPropagation();

                                    if ($li.parent().is('.child_menu')) {
                                        $li.toggleClass('active');
                                        $('ul:first', $li).slideToggle();
                                    } else {
                                        if ($li.hasClass('active')) {
                                            $('ul:first', $li).slideUp(function() {
                                                $li.removeClass('active active-sm');
                                            });
                                        } else {
                                            $('#sidebar-menu').find('li.active').removeClass('active active-sm');
                                            $('#sidebar-menu').find('li ul').slideUp();
                                            $li.addClass('active');
                                            $('ul:first', $li).slideDown();
                                        }
                                    }
                                }
                            } else {
                                // nav-sm 模式：點擊切換顯示子選單
                                if ($('ul:first', $li).length > 0) {
                                    ev.preventDefault();
                                    ev.stopPropagation();
                                    if ($li.hasClass('active-sm')) {
                                        $li.removeClass('active-sm');
                                    } else {
                                        $('#sidebar-menu').find('li').removeClass('active-sm');
                                        $li.addClass('active-sm');
                                    }
                                }
                            }
                        });
                    });

                    // --- 修正側邊欄切換按鈕功能 ---
                    // 初始化：確保 nav-sm 狀態下的樣式正確 (隱藏 footer, 移除 scroll-view)
                    if ($('body').hasClass('nav-sm')) {
                        $('.left_col').removeClass('scroll-view').removeAttr('style');
                        $('.sidebar-footer').hide();
                    }

                    $('#menu_toggle').off('click').on('click', function() {
                        var $BODY = $('body');
                        if ($BODY.hasClass('nav-md')) {
                            $BODY.removeClass('nav-md').addClass('nav-sm');
                            if ($BODY.hasClass('nav-sm')) {
                                $('.left_col').removeClass('scroll-view').removeAttr('style');
                                $('.sidebar-footer').hide();
                                if ($('#sidebar-menu li').hasClass('active')) {
                                    $('#sidebar-menu li.active').addClass('active-sm').removeClass('active');
                                }
                            }
                        } else {
                            $BODY.removeClass('nav-sm').addClass('nav-md');
                            $('.sidebar-footer').show();
                            if ($('#sidebar-menu li').hasClass('active-sm')) {
                                $('#sidebar-menu li.active-sm').addClass('active').removeClass('active-sm');
                            }
                        }
                        $(window).resize();
                    });

                    const kanbanColumns = document.querySelectorAll('.kanban-cards');

                    // --- 監聽分頁切換，連動左側未指派清單 ---
                    $('a[data-toggle="tab"]').on('shown.bs.tab', function(e) {
                        // 取得新啟用的分頁 ID (例如 #tab_type_12)
                        var targetTabId = $(e.target).attr("href");
                        // 提取 type_id (例如 12)
                        var typeId = targetTabId.replace('#tab_type_', '');

                        // 儲存當前分頁 ID 到 localStorage，以便重新整理後恢復
                        localStorage.setItem('lastActiveTab', targetTabId);

                        // 隱藏所有未指派清單
                        $('.unassigned-list').hide();

                        // 顯示對應的未指派清單
                        $('#machine-unassigned-' + typeId).show();

                        // 更新未指派欄位的數量統計
                        var count = $('#machine-unassigned-' + typeId + ' .kanban-card').length;
                        $('#unassigned-count-badge').text(count);
                    });

                    // --- 恢復上次選中的分頁 (頁面載入時執行) ---
                    var lastTab = localStorage.getItem('lastActiveTab');
                    if (lastTab) {
                        var $targetTab = $('a[href="' + lastTab + '"]');
                        if ($targetTab.length > 0) {
                            $targetTab.tab('show');
                        }
                    }

                    // --- 搜尋功能 ---
                    const searchInput = document.getElementById('card-search-input');
                    searchInput.addEventListener('input', function(e) {
                        const term = e.target.value.toLowerCase().trim();
                        const cards = document.querySelectorAll('.kanban-card');
                        const columns = document.querySelectorAll('.kanban-column');

                        if (term.length > 0) {
                            document.body.classList.add('search-mode');

                            // 1. 過濾卡片
                            cards.forEach(card => {
                                const text = card.innerText.toLowerCase();
                                if (text.includes(term)) {
                                    card.classList.remove('search-hidden');
                                } else {
                                    card.classList.add('search-hidden');
                                }
                            });

                            // 2. 機台欄位始終顯示 (移除之前的隱藏邏輯，以便拖曳至空機台)
                            columns.forEach(column => {
                                column.classList.remove('search-hidden');
                            });
                        } else {
                            document.body.classList.remove('search-mode');
                            cards.forEach(card => card.classList.remove('search-hidden'));
                            columns.forEach(column => column.classList.remove('search-hidden'));
                        }
                    });

                    // --- 點擊模數標籤自動篩選 ---
                    $(document).on('click', '.module-filter-btn', function(e) {
                        e.stopPropagation(); // 防止觸發卡片雙擊
                        var module = $(this).data('module');
                        var searchInput = document.getElementById('card-search-input');
                        searchInput.value = module;
                        // 觸發原生 input 事件，確保篩選器能偵測到變更
                        searchInput.dispatchEvent(new Event('input', {
                            bubbles: true
                        }));
                    });

                    // --- 標題點擊事件 (開啟排序視窗 - 支援未指派與機台) ---
                    $(document).on('click', '.kanban-column-header', function() {
                        var $header = $(this);
                        var $targetList;
                        var titleText = '';

                        // 判斷是未指派還是機台
                        if ($header.attr('id') === 'unassigned-header') {
                            $targetList = $('.unassigned-list:visible');
                            titleText = '未指派';
                        } else {
                            var machineId = $header.data('machine-id');
                            if (!machineId) return;
                            $targetList = $('#machine-' + machineId);
                            titleText = $header.data('machine-name') || '機台排程';
                        }

                        if ($targetList.length === 0) return;

                        var listId = $targetList.attr('id');

                        // 更新 Modal 標題
                        $('#unassignedSortModal .modal-title').html('<i class="fa fa-sort"></i> 調整順序 - ' + titleText);

                        var $modalList = $('#unassigned-sort-list');
                        $modalList.empty();
                        $modalList.data('target-list-id', listId);

                        var isUnassignedList = $targetList.hasClass('unassigned-list');

                        // 🌟🌟🌟 修正點開始：攔截卡片，強制依照自訂順序重新洗牌 🌟🌟🌟
                        var cardsArray = [];
                        $targetList.find('.kanban-card').each(function() {
                            cardsArray.push($(this));
                        });

                        // 執行強制排序 (依據 processing-sequence 或紫色的數字標籤)
                        cardsArray.sort(function(a, b) {
                            var seqA = parseInt(a.data('processing-sequence')) || parseInt(a.find('.bg-purple').text()) || 999999;
                            var seqB = parseInt(b.data('processing-sequence')) || parseInt(b.find('.bg-purple').text()) || 999999;
                            return seqA - seqB;
                        });

                        // 使用排好序的陣列來產生 Modal 內容
                        $.each(cardsArray, function(index, $card) {
                            var id = $card.data('id'); // This is bom_ing_fid
                            var bom = $card.data('bom') || '';
                            var partNo = $card.data('part-no') || '';
                            var client = $card.data('client') || '';
                            var sqty = parseInt($card.data('sqty')) || 0;
                            var okQty = parseInt($card.data('ok-qty')) || 0;
                            var shippingDate = $card.data('shipping-date') || '';
                            var state = $card.data('state');
                            var seq = $card.find('.bg-purple').text() || $card.data('processing-sequence') || '';

                            // 計算進度
                            var percent = (sqty > 0) ? Math.min(100, Math.round((okQty / sqty) * 100)) : 0;

                            // 收集備註資訊
                            var remarks = [];
                            var singleBetPs = $card.data('single-bet-ps');
                            if (singleBetPs) remarks.push(singleBetPs);
                            var pti01Ps = $card.data('pti01-ps');
                            if (pti01Ps) remarks.push('車床:' + pti01Ps);
                            var ps2 = $card.data('ps2');
                            if (ps2) remarks.push('現場:' + ps2);

                            var gearInfo = $card.data('gear-info');

                            var html = '<div class="row" style="margin:0;">';
                            html += '<div class="col-xs-10" style="padding:0;">';
                            html += '<strong>' + seq + '. ' + bom + '</strong>';
                            if (partNo) html += ' <span class="text-muted">| ' + partNo + '</span>';

                            if (state === 'ing') {
                                if (isUnassignedList) {
                                    html += ' <span class="label label-info" style="font-size: 10px;">待指派</span>';
                                } else {
                                    if (index === 0) {
                                        html += ' <span class="label label-success" style="font-size: 10px;">加工中</span>';
                                    } else {
                                        html += ' <span class="label label-default" style="font-size: 10px;">待加工</span>';
                                    }
                                }
                            } else if (state === 'Q') html += ' <span class="label label-warning" style="font-size: 10px;">QC</span>';

                            html += '<div style="font-size: 11px; color: #666; margin-top: 2px;">';
                            html += '<span style="margin-right: 10px;"><i class="fa fa-user"></i> ' + (client || '-') + '</span>';
                            html += '<span style="margin-right: 10px;"><i class="fa fa-cubes"></i> ' + sqty + '</span>';
                            if (okQty > 0 || state === 'ing') {
                                html += '<span style="margin-right: 10px; color: #337ab7;"><i class="fa fa-pie-chart"></i> ' + percent + '% (' + okQty + '/' + sqty + ')</span>';
                            }
                            if (shippingDate) html += '<span><i class="fa fa-calendar"></i> ' + shippingDate + '</span>';
                            html += '</div>';

                            // 新增：進度條顯示 (若有生產進度)
                            if (sqty > 0 && (okQty > 0 || state === 'ing')) {
                                html += '<div class="progress" style="height: 4px; margin-bottom: 0; margin-top: 3px; background-color: #e0e0e0;">';
                                html += '<div class="progress-bar progress-bar-success" role="progressbar" style="width: ' + percent + '%;"></div>';
                                html += '</div>';
                            }

                            if (gearInfo) {
                                html += '<div style="font-size: 11px; color: #337ab7; margin-top: 2px; font-weight: bold;">' + gearInfo + '</div>';
                            }

                            if (remarks.length > 0) {
                                html += '<div style="font-size: 11px; color: #8a6d3b; margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><i class="fa fa-info-circle"></i> ' + remarks.join(' | ') + '</div>';
                            }
                            html += '</div><div class="col-xs-2 text-right" style="padding:0; padding-top: 8px;"><i class="fa fa-bars text-muted" style="cursor:move;"></i></div></div>';

                            $modalList.append('<li class="list-group-item" data-id="' + id + '">' + html + '</li>');
                        });
                        // 🌟🌟🌟 修正點結束 🌟🌟🌟

                        // 初始化 Sortable
                        if ($modalList.data('sortable')) {
                            $modalList.data('sortable').destroy();
                        }
                        var sortable = new Sortable($modalList[0], {
                            sort: false,
                            disabled: true, // 2026.03.06 根據需求，完全禁用拖移
                            animation: 150,
                            ghostClass: 'sortable-ghost',
                            forceFallback: true // 修正：強制使用 JS 模擬拖曳，解決在 Modal 內無法拖曳至頂端滾動的問題
                        });
                        $modalList.data('sortable', sortable);

                        $('#unassignedSortModal').modal('show');
                    });

                    // --- Modal 關閉時儲存排序 ---
                    $('#unassignedSortModal').on('hide.bs.modal', function() {
                        var $modalList = $('#unassigned-sort-list');
                        var targetListId = $modalList.data('target-list-id');
                        if (!targetListId) return;

                        var $targetList = $('#' + targetListId);
                        var newOrderIds = [];
                        $modalList.find('li').each(function() {
                            newOrderIds.push($(this).data('id'));
                        });

                        var $cards = $targetList.find('.kanban-card');
                        var cardMap = {};
                        $cards.each(function() {
                            cardMap[$(this).data('id')] = $(this);
                        });

                        $cards.detach();
                        newOrderIds.forEach(function(id) {
                            if (cardMap[id]) $targetList.append(cardMap[id]);
                        });
                        $cards.each(function() {
                            var id = $(this).data('id');
                            if (newOrderIds.indexOf(id.toString()) === -1 && newOrderIds.indexOf(parseInt(id)) === -1) {
                                $targetList.append($(this));
                            }
                        });

                        updateSchedule($targetList[0]);
                        updateCardSequenceBadges($targetList[0]);
                        updateColumnVisuals($targetList[0]); // 修正：排序後立即更新卡片視覺狀態 (加工中/待加工)
                    });

                    // --- 新增：排程列表 Modal 功能 ---
                    $('#btn-schedule-list').click(function() {
                        var activeTabId = $('#machineTypeTabs li.active a').attr('href');
                        if (!activeTabId) {
                            alert('請先選擇一個機台類型分頁。');
                            return;
                        }
                        var typeId = activeTabId.replace('#tab_type_', '');
                        var typeName = $('#machineTypeTabs li.active a').text().replace(/\s*\d+\s*$/, '').trim();

                        // 1. 收集所有相關卡片
                        var items = [];

                        // 輔助函式：提取卡片資料
                        function extractCardData($card, machineName, machineSeq, isProcessing) {
                            var id = $card.data('id');
                            var bom = $card.data('bom') || '';
                            var partNo = $card.data('part-no') || '';
                            var client = $card.data('client') || '';
                            var sqty = parseInt($card.data('sqty')) || 0;
                            var okQty = parseInt($card.data('ok-qty')) || 0;
                            var ps = $card.data('ps') || '';
                            var singleBetPs = $card.data('single-bet-ps') || '';
                            var pti01Ps = $card.data('pti01-ps') || '';
                            var ps2 = $card.data('ps2') || '';
                            var side1 = $card.data('1-side') || '';
                            var isArrived = $card.data('order-id') == 1;
                            var isSplit = ps.indexOf('(拆分工單)') !== -1;

                            var seqNum = parseInt($card.data('processing-sequence')) || 999999;
                            // 🌟🌟🌟 關鍵新增：從卡片上把「加工順序」抓下來！ 🌟🌟🌟

                            return {
                                id: id,
                                bom: bom,
                                partNo: partNo,
                                client: client,
                                sqty: sqty,
                                okQty: okQty,
                                ps: ps,
                                single_bet_ps: singleBetPs,
                                pti01_ps: pti01Ps,
                                isArrived: isArrived,
                                isSplit: isSplit,
                                side1: side1,
                                machineName: machineName,
                                machineSeq: machineSeq,
                                isProcessing: isProcessing,

                                // 🌟🌟🌟 關鍵新增：把抓到的順序放進陣列裡，交給後面的魔法陣去排！ 🌟🌟🌟
                                processing_sequence: seqNum
                            };
                        }

                        // 從未指派區收集
                        $('#machine-unassigned-' + typeId + ' .kanban-card').each(function() {
                            items.push(extractCardData($(this), null, null, false));
                        });

                        // 從該類型的所有機台收集 (修正選擇器邏輯，避免重複選取未指派)
                        $('#tab_type_' + typeId + ' .kanban-column').each(function() {
                            var $header = $(this).find('.kanban-column-header');
                            // 跳過未指派欄位
                            if ($header.attr('id') === 'unassigned-header') return;

                            var mName = $header.data('machine-name');
                            $(this).find('.kanban-card').each(function(idx) {
                                var isProcessing = (idx === 0); // 第一筆視為加工中
                                items.push(extractCardData($(this), mName, idx + 1, isProcessing));
                            });
                        });

                        // 2. 排序卡片
                        items.sort(function(a, b) {
                            // 加工中優先
                            if (a.isProcessing !== b.isProcessing) {
                                return a.isProcessing ? -1 : 1;
                            }
                            // 其次維持原順序 (穩定排序)
                            return 0;
                        });

                        // 3. 填充 Modal
                        var $modalList = $('#schedule-sort-list');
                        $modalList.empty();
                        $('#scheduleListModal .modal-title').html('<i class="fa fa-list-alt"></i> 調整排程順序 - ' + typeName);

                        var hasPrintedSeparator = false;
                        var showDetails = true; // All roles see details
                        var showClient = (window.displayPermissionCode !== 'R+U' && window.displayPermissionCode !== 'R');

                        // 🌟🌟🌟 終極魔法：在渲染跳窗前，強制重新排序 items 陣列 🌟🌟🌟
                        items.sort(function(a, b) {
                            // 【第一關】維持生管系統的霸王條款：「已到料」在上，「未到料」在下
                            var arrivedA = a.isArrived ? 1 : 0;
                            var arrivedB = b.isArrived ? 1 : 0;

                            // 如果一個已到料、一個未到料，已到料的優先排前面
                            if (arrivedA !== arrivedB) {
                                return arrivedB - arrivedA;
                            }

                            // 【第二關】如果狀態一樣(都是已到料)，就嚴格依照您拖曳的順序排列！
                            // (這裡涵蓋了前後端常見的命名方式，確保一定抓得到順序數字)
                            var seqA = parseInt(a.processing_sequence) || parseInt(a.processingSequence) || parseInt(a.seq) || 999999;
                            var seqB = parseInt(b.processing_sequence) || parseInt(b.processingSequence) || parseInt(b.seq) || 999999;

                            return seqA - seqB;
                        });
                        // 🌟🌟🌟 魔法陣結束 🌟🌟🌟
                        items.forEach(function(item, index) {
                            // 分隔線
                            if (!item.isArrived && !hasPrintedSeparator) {
                                $modalList.append('<li class="list-group-item" style="background-color:#333; color:#fff; text-align:center; font-weight:bold; padding:5px;">以下未到料</li>');
                                hasPrintedSeparator = true;
                            }

                            var seq = index + 1;

                            // 緊急按鈕
                            var urgentHtml = '';
                            var allRemarks = [item.ps, item.single_bet_ps, item.pti01_ps].join(' ').toLowerCase(); // 這裡僅用於判斷是否緊急
                            if (allRemarks.indexOf('boss急件') !== -1) {
                                urgentHtml = '<span class="label label-danger" style="margin-left:5px;">BOSS急件</span>';
                            } else if (allRemarks.indexOf('急件') !== -1) {
                                urgentHtml = '<span class="label label-danger" style="margin-left:5px;">急件</span>';
                            }

                            // 構建詳細資訊 HTML
                            var detailsHtml = '';
                            if (showDetails) {
                                detailsHtml += '<div style="font-size:11px; color:#555; margin-top:2px;">';
                                if (showClient) {
                                    detailsHtml += '<span style="margin-right:5px;"><i class="fa fa-user"></i> ' + item.client + '</span>';
                                }
                                detailsHtml += '<span><i class="fa fa-pie-chart"></i> ' + item.okQty + '/' + item.sqty + '</span>';

                                if (item.ps) detailsHtml += '<div style="color:#555;"><i class="fa fa-file-text-o"></i> BOM備註: ' + item.ps + '</div>';
                                if (item.single_bet_ps) detailsHtml += '<div style="color:#555;"><i class="fa fa-info-circle"></i> 單關備註: ' + item.single_bet_ps + '</div>';
                                if (item.pti01_ps) detailsHtml += '<div style="color:#8a6d3b;"><i class="fa fa-pencil-square-o"></i> 生管備註: ' + item.pti01_ps + '</div>';

                                if (item.isSplit) {
                                    detailsHtml += '<div style="color:#d9534f; font-weight:bold;">(拆批) ' + (item.side1 ? item.side1 + '面' : '') + '</div>';
                                }
                                detailsHtml += '</div>';
                            }

                            // 機台資訊
                            var machineInfoHtml = '';
                            if (item.machineName) {
                                var faceInfo = item.side1 ? ' <span class="text-primary">' + item.side1 + '面加工中</span>' : '';
                                machineInfoHtml = '<div style="text-align:right; font-size:11px; color:#337ab7; font-weight:bold;">' +
                                    item.machineName +
                                    faceInfo + '</div>';
                            } else {
                                machineInfoHtml = '<div style="text-align:right; font-size:11px; color:#999;">未指派</div>';
                            }

                            // 加工中標示
                            var processingLabel = item.isProcessing ? '<span class="label label-success">加工中</span>' : '';

                            var html = `<div style="display:flex; justify-content:space-between; align-items:center;">
                                <div style="display:flex; align-items:center; flex-grow:1;">
                                    <div style="margin-right:10px;">
                                        <span style="display:inline-block; width:24px; height:24px; line-height:24px; text-align:center; background-color:#9B59B6; color:white; font-weight:bold; border-radius:3px;">${seq}</span>
                                    </div>
                                    <div style="flex-grow:1;">
                                        <strong>${item.bom}</strong> <span class="text-muted">| ${item.partNo}</span> ${urgentHtml} ${processingLabel}
                                        ${detailsHtml}
                                    </div>
                                </div>
                                <div style="min-width:100px; margin-left:10px;">
                                    ${machineInfoHtml}
                                </div>
                                <i class="fa fa-bars text-muted" style="cursor:move; margin-left:10px;"></i>
                            </div>`;

                            var $li = $('<li class="list-group-item" data-id="' + item.id + '">' + html + '</li>');

                            // 雙擊開啟報工 (權限 A 或 C)
                            if (userPerms.hasA || userPerms.hasC) {
                                $li.on('dblclick', function() {
                                    // 關閉排序視窗 (可選)
                                    // $('#scheduleListModal').modal('hide');
                                    // 開啟報工視窗
                                    // 這裡我們需要傳遞完整的卡片資料，或者重新抓取。
                                    // 為了方便，我們呼叫一個新函式 openTaskReport
                                    openTaskReport(item.id);
                                });
                            }
                            $modalList.append($li);
                        });

                        // 4. 初始化 Sortable
                        if ($modalList.data('sortable')) {
                            $modalList.data('sortable').destroy();
                        }
                        var sortable = new Sortable($modalList[0], {
                            disabled: !window.canReorder, // 使用新的權限變數
                            animation: 150,
                            ghostClass: 'sortable-ghost',
                            forceFallback: true,
                            onEnd: function(evt) {
                                // 拖曳結束後，僅更新 Modal 內的視覺順序 (排除分隔線 li)
                                $(evt.target).find('li[data-id]').each(function(index) {
                                    var seq = index + 1;
                                    // 更新 li 內的順序數字 span
                                    $(this).find('div > div > span').first().text(seq);
                                });
                            }
                        });
                        $modalList.data('sortable', sortable);

                        $('#scheduleListModal').modal('show');
                    });

                    // --- 儲存全域排序 ---
                    $('#scheduleListModal').on('hide.bs.modal', function() {
                        var $modalList = $('#schedule-sort-list');
                        var newOrderIds = [];
                        $modalList.find('li[data-id]').each(function() {
                            newOrderIds.push($(this).data('id'));
                        });

                        // AJAX to update the database
                        $.ajax({
                            url: 'process_schedule_NOW.php',
                            type: 'POST',
                            data: {
                                action: 'update_global_sequence',
                                order: newOrderIds
                            },
                            dataType: 'json',
                            success: function(res) {
                                if (res.success) {
                                    showToast('成功', '排程順序已儲存', true);
                                    // Reload page to reflect changes
                                    setTimeout(function() {
                                        location.reload();
                                    }, 1000);
                                } else {
                                    alert('儲存失敗: ' + res.message);
                                }
                            },
                            error: function() {
                                alert('連線錯誤，無法儲存排程順序。');
                            }
                        });
                    });


                    // --- 初始化未指派數量 ---
                    (function() {
                        var activeTab = $('#machineTypeTabs .active a').attr('href');
                        if (activeTab) {
                            var typeId = activeTab.replace('#tab_type_', '');
                            var count = $('#machine-unassigned-' + typeId + ' .kanban-card').length;
                            $('#unassigned-count-badge').text(count);
                        }
                    })();

                    // --- 初始化臨時加工製程選單 ---
                    var $tempProcSelect = $('#temp_process_select');

                    // 1. 找出所有有機台的 process_type_id
                    var activeMachineTypeIds = new Set();
                    allMachines.forEach(function(m) {
                        if (m.machine_type_id) {
                            activeMachineTypeIds.add(String(m.machine_type_id));
                        }
                    });

                    allProcesses.forEach(function(p) {
                        // 2. 只加入有機台對應的製程
                        if (p.process_type_id && activeMachineTypeIds.has(String(p.process_type_id))) {
                            $tempProcSelect.append(`<option value="${p.ProcessNo}" data-type-id="${p.process_type_id}">${p.ProcessNo} ${p.ProcessName}</option>`);
                        }
                    });
                    // 3. 初始化 Select2 (支援搜尋)
                    $tempProcSelect.select2({
                        placeholder: "-- 請選擇製程 --",
                        allowClear: true,
                        dropdownParent: $('#quickReportModal'),
                        width: '100%'
                    });

                    // --- 雙擊卡片開啟報工/調整視窗 ---
                    $(document).on('dblclick', '.kanban-card', function() {
                        var id = $(this).data('id');
                        openTaskReport(id);
                    });

                    // 新增：開啟報工視窗的共用函式
                    window.openTaskReport = function(id) {
                        // 找到對應的卡片 (無論是在未指派還是機台區)
                        var $card = $('.kanban-card[data-id="' + id + '"]').first();
                        if ($card.length === 0) {
                            // 如果找不到 (可能是從排序列表點擊，但卡片被過濾了?)
                            // 嘗試從 DOM 中找，或者需要重新 fetch data。
                            // 這裡假設卡片一定存在於 DOM 中 (即使隱藏)
                            return;
                        }

                        var processNo = $card.data('process-no');
                        var processTypeId = $card.data('process-type-id');
                        var client = $card.data('client');
                        var partNo = $card.data('part-no');
                        var sqty = parseInt($card.data('sqty')) || 0;
                        var okQty = parseInt($card.data('ok-qty')) || 0;
                        var ngQty = parseInt($card.data('ng-qty')) || 0;
                        var remaining = sqty - okQty - ngQty;
                        var pti01Ps = $card.data('pti01-ps');
                        var singleBetPs = $card.data('single-bet-ps');
                        var ps = $card.data('ps');
                        var side1 = $card.data('1-side');
                        var ps2 = $card.data('ps2');
                        var shippingDate = $card.data('shipping-date');
                        var gearInfo = $card.data('gear-info');
                        var partType = $card.data('part-type');
                        var dsId = $card.data('ds-id');
                        var orderId = $card.data('order-id');

                        // --- UI Control based on Process Category Settings ---
                        var pTypeIdStr = String(processTypeId);
                        var showFaceOptions = window.uiSettings.show_face_options.includes(pTypeIdStr);
                        var showMaterialArrived = window.uiSettings.show_material_arrived.includes(pTypeIdStr);

                        // Control "此面完工" and "加工面"
                        $('#div_face_finish_checkbox').toggle(showFaceOptions);

                        // Control "已到料"
                        $('#span_material_arrived').toggle(showMaterialArrived);

                        // 如果 "加工面" 和 "已到料" 都隱藏，則整個 pti01 區塊也可能需要隱藏 (如果 pti01_ps 也沒有內容或權限)
                        // 這裡保持 pti01_ps 的可見性，僅控制 checkbox 和 dropdown

                        // 嘗試從所在的欄位獲取機台 ID
                        var columnMachineId = $card.closest('.kanban-cards').data('machine-id');
                        // 如果是在未指派區 (ID 包含 'unassigned')，則不預選
                        if (columnMachineId && columnMachineId.toString().indexOf('unassigned') !== -1) {
                            columnMachineId = '';
                        }

                        // 從卡片內容抓取顯示資訊
                        var bom = $card.data('bom'); // 直接用 data-bom 比較準確
                        var process = $card.find('.card-process-name').text();

                        // 判斷急件
                        var allRemarks = [ps, singleBetPs, pti01Ps].join(' ').toLowerCase();
                        var urgentLabel = '';
                        if (allRemarks.includes('boss急件')) {
                            urgentLabel = ' <span class="label label-danger">BOSS急件</span>';
                        } else if (allRemarks.includes('急件')) {
                            urgentLabel = ' <span class="label label-danger">急件</span>';
                        }

                        // 更新 Modal 標題
                        $('#quickReportModalLabel').html('<i class="fa fa-pencil-square-o"></i> 現場每日製程回報 - ' + bom + urgentLabel);

                        // 填入 Modal
                        $('#modal_bom_ing_fid').val(id);
                        $('#modal_bom_val').val(bom); // 暫存 BOM
                        $('#modal_process_no').val(processNo);
                        $('#modal_process_type_id').val(processTypeId);

                        // 修正：備註欄位顯示 BOM備註、單關備註、生管備註
                        var infoText = '';
                        if (ps) infoText += 'BOM備註: ' + ps + '\n';
                        if (singleBetPs) infoText += '單關備註: ' + singleBetPs + '\n';
                        if (pti01Ps) infoText += '生管備註: ' + pti01Ps + '\n';

                        $('#modal_bom_info').val(infoText);
                        $('#modal_client_name').val(client);
                        $('#modal_part_no').val(partNo);
                        $('#gear_d_id').val(dsId); // 暫存 ds_id 供齒輪設定使用
                        $('#modal_stats_total').text(sqty);
                        $('#modal_stats_ok').text(okQty);
                        $('#modal_stats_ng').text(ngQty);
                        $('#modal_stats_remaining').text(remaining);

                        // 設定模式為 NORMAL
                        $('input[name="report_source"][value="NORMAL"]').prop('checked', true).trigger('change');
                        // 鎖定模式選擇 (點選資料塊一定是正常加工)
                        $('input[name="report_source"]').prop('disabled', true);
                        $('input[name="report_source"]').parent().show(); // 確保顯示

                        $('#source_reason').val(''); // 清空原因

                        // 填入新欄位
                        $('textarea[name="pti01_ps"]').val(pti01Ps);
                        $('select[name="process_face"]').val(''); // 預設不選 (每次報工需重新選擇)

                        // 設定已到料勾選狀態
                        $('input[name="material_arrived"]').prop('checked', orderId == 1);

                        // 控制齒輪資訊顯示
                        var showGearSection = false;
                        var showEditBtn = false;
                        var gearText = '';

                        if (gearInfo) {
                            showGearSection = true;
                            showEditBtn = false; // 已經有規格，不要有修改按鈕
                            gearText = gearInfo;
                        } else if (!dsId) {
                            // 沒有出現在 d_setting 資料表 -> 出現設定規格的表格(按鈕)
                            showGearSection = true;
                            showEditBtn = true;
                            gearText = '<span class="text-danger">尚未設定齒輪規格</span>';
                        } else if (partType === 'G') {
                            // 是齒輪但無詳細規格 (且已在 d_setting) -> 顯示無規格，無按鈕
                            showGearSection = true;
                            showEditBtn = false;
                            gearText = '無詳細規格';
                        }

                        if (showGearSection) {
                            $('#div_gear_info').show();
                            $('#modal_gear_info_text').html(gearText);
                            if (showEditBtn) $('#btn_edit_gear_data').show();
                            else $('#btn_edit_gear_data').hide();
                        } else {
                            $('#div_gear_info').hide();
                        }

                        // 控制車床欄位顯示
                        if (processTypeId == 1) {
                            $('#div_pti01_fields').show();
                        } else {
                            $('#div_pti01_fields').hide();
                        }

                        // 清空並禁用時間欄位 (等待選擇人員)
                        $('#modal_setup_start_time, #modal_setup_end_time, #modal_production_start_time, #modal_production_end_time').val('').prop('disabled', true);
                        $('#modal_setup_user_id, #modal_production_user_id').val('');
                        $('#modal_produced_qty').val('');
                        $('#avg_hourly_rate_display').text('');
                        $('#is_abnormality').prop('checked', false).trigger('change'); // 重置異常勾選
                        $('#abnormal_type_id').val('');
                        $('#abnormal_desc').val('');
                        $('#modal_abnormal_id').val(''); // 重置異常ID
                        $('#ngTable tbody').empty();

                        // --- 權限與 UI 控制邏輯 ---
                        var $saveBtn = $('#saveQuickReportBtn');
                        $saveBtn.off('click').click(handleSaveReport); // 重置並綁定儲存事件
                        $saveBtn.text('儲存變更').removeClass('btn-warning btn-info').addClass('btn-primary').show();

                        // 預設顯示所有欄位
                        $('.user-select-group, .time-input-group, #stats-container, #production-input-container, #ng-table-container, #div_1_side').show();
                        $('textarea[name="pti01_ps"], select[name="process_face"]').prop('disabled', false);
                        $('#modal_machine_id').closest('.form-group').show();

                        if (userPerms.isProdControl) {
                            // --- 生管 (C) 模式 ---
                            // 隱藏：生產/架機人員、日期時間、NG 輸入、加工面選單
                            // 修正：隱藏 生產結果輸入框 (本日完成、本站完工、備註)
                            $('.user-select-group, .time-input-group, #ng-table-container, #div_1_side, #production-input-container').hide();

                            // 隱藏機台選擇
                            $('#modal_machine_id').closest('.form-group').hide();

                            // 確保顯示 統計
                            $('#stats-container').show();

                            // pti01_ps 可編輯 (若為車床)
                            $('textarea[name="pti01_ps"]').prop('disabled', false);

                            // 按鈕改為 "更新備註"
                            $saveBtn.text('更新備註').removeClass('btn-primary').addClass('btn-info');

                            // 生管可編輯已到料
                            $('input[name="material_arrived"]').prop('disabled', false);

                        } else if (userPerms.isProduction) {
                            // --- 生產 (U/D) 模式 ---
                            // 若為車床，隱藏 pti01_ps
                            if (processTypeId == 1) {
                                $('textarea[name="pti01_ps"]').closest('.form-group').hide();
                            }

                            // 生產不可編輯已到料
                            $('input[name="material_arrived"]').prop('disabled', true);

                        } else if (!userPerms.canReport && !userPerms.canSettings) {
                            // --- 唯讀 (R) 模式 ---
                            $('#quickReportForm input, #quickReportForm select, #quickReportForm textarea').prop('disabled', true);
                            $('#addNgRowBtn').hide();
                            $saveBtn.hide();
                        }

                        // --- 動態產生機台選單 ---
                        var $machineSelect = $('#modal_machine_id');
                        $machineSelect.empty();
                        $machineSelect.append('<option value="">請選擇機台</option>');

                        // 移除可能存在的隱藏欄位
                        $('#hidden_machine_id').remove();

                        allMachines.forEach(function(m) {
                            // 篩選邏輯：
                            // 1. 如果卡片已排定 (columnMachineId 存在)，則顯示所有機台 (或至少包含該機台)，並選中它。
                            // 2. 如果卡片未排定 (columnMachineId 空)，則只顯示 process_type_id 相符的機台。

                            var showOption = false;
                            if (columnMachineId) {
                                showOption = true; // 已排定時顯示全部，方便換機台 (或者您希望只顯示同類型的也可以，這裡先顯示全部)
                            } else {
                                if (m.machine_type_id == processTypeId) showOption = true;
                            }

                            if (showOption) {
                                var selected = (columnMachineId && m.machine_id == columnMachineId) ? 'selected' : '';
                                $machineSelect.append(`<option value="${m.machine_id}" ${selected} data-need-setup="${m.need_setup}">${machineOptionLabel(m)}</option>`);
                            }
                        });

                        // 若已排定機台，預選但不鎖定，讓使用者可自由更換機台
                        if (columnMachineId) {
                            $machineSelect.val(columnMachineId);
                        }
                        $('#hidden_machine_id').remove();
                        if (userPerms.canReport) {
                            $machineSelect.prop('disabled', false);
                        }

                        // 若為管理者 (A)，確保 pti01_ps 可見且可編輯
                        if (userPerms.hasA) {
                            $('#div_pti01_fields').show();
                            $('textarea[name="pti01_ps"]').prop('disabled', false);
                            $('input[name="material_arrived"]').prop('disabled', false);
                        }
                        // 根據機台設定顯示/隱藏架機欄位
                        checkMachineSetupNeeded();
                        updateProcessFaceVisibility();


                        // --- 載入歷史紀錄 ---
                        loadReportHistory(id, processNo);

                        // 顯示 Modal
                        $('#quickReportModal').modal('show');
                    };

                    // --- 齒輪設定功能 ---
                    $('#btn_edit_gear_data').click(function() {
                        var dId = $('#gear_d_id').val();
                        var partNo = $('#modal_part_no').val();
                        var client = $('#modal_client_name').val();

                        $('#gear_part_no').val(partNo);
                        $('#gear_client_name').val(client);
                        $('#gear-rows-container').empty();

                        // 設定料號點擊開啟圖檔
                        $('#gear_part_no').css({
                                'cursor': 'pointer',
                                'color': '#337ab7',
                                'text-decoration': 'underline'
                            })
                            .off('click').on('click', function() {
                                var bom = $('#modal_bom_val').val();
                                if (bom) window.open('/nas/' + bom + '.jpg', '_blank');
                            });

                        // 載入現有齒輪資料 (需透過 AJAX 獲取，這裡假設從全域資料或重新 fetch)
                        // 由於 fullDataset 可能沒有詳細齒輪結構，這裡發送請求獲取
                        $.post('process_schedule_NOW.php', {
                            action: 'get_dashboard_data'
                        }, function(res) {
                            // 這裡偷懶重用 get_dashboard_data，理想上應有專用 API。
                            // 但為了效率，我們直接從 fullDataset 找 (如果有的話)
                            // 或者直接用 inspection_standard_setting.php 的 API? 
                            // 為了獨立性，我們在 process_schedule_NOW.php 增加 save_part_gear_info，
                            // 但讀取部分，我們可以重用 get_dashboard_data 的邏輯，或者簡單地解析 gearInfo 字串 (不夠精確)。
                            // 最佳解：使用 inspection_standard_setting.php 的 init_part_settings API

                            $.post('../QC/inspection_standard_setting.php', {
                                action: 'init_part_settings',
                                d_id: dId
                            }, function(res2) {
                                if (res2.success && res2.gears) {
                                    if (res2.gears.length > 0) {
                                        res2.gears.forEach(g => addGearRow(g));
                                    } else {
                                        addGearRow(); // 預設一列
                                    }
                                } else {
                                    addGearRow();
                                }
                                $('#gearSettingModal').modal('show');
                            }, 'json');
                        }, 'json');
                    });

                    $('#btn-add-gear-row').click(function() {
                        addGearRow();
                    });

                    // 監聽齒輪類型改變，控制螺旋角輸入框顯示
                    $('#gear-rows-container').on('change', '.gear-type', function() {
                        var $row = $(this).closest('.gear-row');
                        var selectedType = $(this).val();
                        var $helixGroup = $row.find('.helix-angle-group');
                        if (selectedType && selectedType.includes('螺旋')) {
                            $helixGroup.slideDown();
                        } else {
                            $helixGroup.slideUp();
                            // $helixGroup.find('input').val(''); // Optional: Clear
                        }
                    });

                    // 任務 2: 螺旋角模式切換與計算 (同步自 ERP_Cost_Analysis.php)
                    $(document).on('click', '.btn-mode-dec', function() {
                        var $group = $(this).closest('.helix-angle-group');
                        $group.find('.mode-decimal').show();
                        $group.find('.mode-dms').hide();
                        $(this).addClass('active').siblings().removeClass('active');
                    });
                    $(document).on('click', '.btn-mode-dms', function() {
                        var $group = $(this).closest('.helix-angle-group');
                        $group.find('.mode-decimal').hide();
                        $group.find('.mode-dms').css('display', 'flex');
                        $(this).addClass('active').siblings().removeClass('active');
                    });

                    // 計算並更新隱藏欄位
                    $(document).on('input', '.gear-helix-val', function() {
                        var val = $(this).val();
                        var $group = $(this).closest('.helix-angle-group');
                        $group.find('.hidden-helix-val').val(val);
                        $group.find('.hidden-helix-str').val(val); // 十進位模式下，字串即數值
                    });

                    $(document).on('input', '.dms-d, .dms-m, .dms-s', function() {
                        var $group = $(this).closest('.helix-angle-group');
                        var d = parseFloat($group.find('.dms-d').val()) || 0;
                        var m = parseFloat($group.find('.dms-m').val()) || 0;
                        var s = parseFloat($group.find('.dms-s').val()) || 0;

                        var decimal = d + (m / 60) + (s / 3600);
                        $group.find('.hidden-helix-val').val(decimal.toFixed(6)); // 儲存計算值

                        var str = d + "°" + m + "'" + s + '"';
                        $group.find('.hidden-helix-str').val(str); // 儲存原始字串
                    });

                    // 格式化函式
                    window.formatModule = function(el) {
                        let val = el.value.trim();
                        if (val && !isNaN(val)) {
                            el.value = 'M' + val;
                        }
                    };
                    window.formatPA = function(el) {
                        let val = parseFloat(el.value);
                        if (!isNaN(val)) {
                            el.value = Number(val).toString(); // Removes trailing zeros
                        }
                    };
                    window.formatDecimal2 = function(el) {
                        let val = parseFloat(el.value);
                        if (!isNaN(val)) {
                            el.value = val.toFixed(2);
                        }
                    };

                    function addGearRow(data = {}) {
                        const type = data.Gear_Type || '';
                        const module = data.Module || '';
                        const teeth = data.Teeth || '';
                        const pa = data.Pressure_Angle || '';
                        const width = data.Face_Width || '';
                        const length = data.Workpiece_Length || '';
                        const remark = data.Remark_Gear || '';

                        // 任務 2: 處理新欄位與去尾數
                        const helix_angle = (data.Helix_Angle !== undefined && data.Helix_Angle !== null && data.Helix_Angle !== '') ? parseFloat(data.Helix_Angle) : '';
                        const helix_str = data.Helix_Angle_Str || '';
                        const direction = data.Helix_Direction || '';
                        const shift_x = data.Profile_Shift_X !== null ? parseFloat(data.Profile_Shift_X) : '';
                        const showHelix = String(type).includes('螺旋');

                        const html = `
                <div class="gear-row" style="padding: 10px; border: 1px solid #eee; margin-bottom: 5px; background: #f9f9f9;">
                    <div class="row">
                        <div class="col-md-2">
                            <select class="form-control input-sm gear-type">
                                <option value="">類型</option>
                                <option value="直齒" ${type == '直齒' ? 'selected' : ''}>直齒</option>
                                <option value="螺旋" ${type == '螺旋' ? 'selected' : ''}>螺旋</option>
                                <option value="傘齒" ${type == '傘齒' ? 'selected' : ''}>傘齒</option>
                                <option value="蝸桿" ${type == '蝸桿' ? 'selected' : ''}>蝸桿</option>
                                <option value="蝸輪" ${type == '蝸輪' ? 'selected' : ''}>蝸輪</option>
                            </select>
                        </div>
                        <div class="col-md-2"><input type="text" class="form-control input-sm gear-module" placeholder="模數" value="${module}" onblur="formatModule(this)"></div>
                        <div class="col-md-2"><input type="number" class="form-control input-sm gear-teeth" placeholder="齒數" value="${teeth}"></div>
                        <div class="col-md-6 helix-angle-group" style="display: ${showHelix ? 'block' : 'none'}; background-color: #e9ecef; padding: 5px; border-radius: 4px;">
                            <div style="display:flex; gap:5px; margin-bottom:5px;">
                                <select class="form-control input-sm gear-direction" style="width:70px;">
                                    <option value="" ${direction === '' ? 'selected' : ''}>旋向</option>
                                    <option value="RH" ${direction === 'RH' ? 'selected' : ''}>RH(右)</option>
                                    <option value="LH" ${direction === 'LH' ? 'selected' : ''}>LH(左)</option>
                                </select>
                                <div class="btn-group btn-group-xs" data-toggle="buttons">
                                    <label class="btn btn-default active btn-mode-dec"><input type="radio" name="options_${Date.now()}" autocomplete="off" checked> 十進位</label>
                                    <label class="btn btn-default btn-mode-dms"><input type="radio" name="options_${Date.now()}" autocomplete="off"> 度分秒</label>
                                </div>
                            </div>
                            <div class="mode-decimal">
                                <input type="number" step="any" class="form-control input-sm gear-helix-val" value="${helix_angle}" placeholder="螺旋角 (如 15.5)">
                            </div>
                            <div class="mode-dms" style="display:none; align-items:center; gap:2px;">
                                <input type="number" class="form-control input-sm dms-d" placeholder="度" style="width:40px;">°
                                <input type="number" class="form-control input-sm dms-m" placeholder="分" style="width:40px;">'
                                <input type="number" class="form-control input-sm dms-s" placeholder="秒" style="width:40px;">"
                            </div>
                            <input type="hidden" class="hidden-helix-val" value="${helix_angle}">
                            <input type="hidden" class="hidden-helix-str" value="${helix_str}">
                        </div>
                    </div>
                    <div class="row" style="margin-top:5px;">
                        <div class="col-md-2"><input type="text" class="form-control input-sm gear-pressure-angle" placeholder="壓力角" value="${pa}" onblur="formatPA(this)"></div>
                        <div class="col-md-2"><input type="number" class="form-control input-sm gear-face-width" placeholder="齒寬" value="${width}" onblur="formatDecimal2(this)"></div>
                        <div class="col-md-2"><input type="number" class="form-control input-sm gear-length" placeholder="總長" value="${length}" onblur="formatDecimal2(this)"></div>
                        <div class="col-md-2"><input type="number" class="form-control input-sm gear-shift-x" step="any" value="${shift_x}" placeholder="係數X"></div>
                        <div class="col-md-4"><input type="text" class="form-control input-sm gear-remark" placeholder="備註" value="${remark}"></div>
                        <div class="col-md-2"><button type="button" class="btn btn-danger btn-xs pull-right" onclick="$(this).closest('.gear-row').remove()"><i class="fa fa-trash"></i></button></div>
                    </div>
                </div>
            `;
                        $('#gear-rows-container').append(html);

                        // 初始化 DMS 顯示 (若有原始字串且包含度分秒符號)
                        if (helix_str && (helix_str.includes('°') || helix_str.includes("'"))) {
                            const $lastRow = $('#gear-rows-container .gear-row').last();
                            $lastRow.find('.btn-mode-dms').trigger('click');
                            const d = helix_str.split('°')[0];
                            const m = helix_str.split('°')[1] ? helix_str.split('°')[1].split("'")[0] : '';
                            const s = helix_str.split("'")[1] ? helix_str.split("'")[1].split('"')[0] : '';
                            $lastRow.find('.dms-d').val(d);
                            $lastRow.find('.dms-m').val(m);
                            $lastRow.find('.dms-s').val(s);
                        }
                    }

                    $('#btnSaveGearData').click(function() {
                        var gears = [];
                        $('#gear-rows-container .gear-row').each(function() {
                            gears.push({
                                Module: $(this).find('.gear-module').val(),
                                Teeth: $(this).find('.gear-teeth').val(),
                                Face_Width: $(this).find('.gear-face-width').val(),
                                // 任務 2: 收集新欄位資料
                                Helix_Angle: $(this).find('.hidden-helix-val').val(), // 取計算後的十進位
                                Helix_Angle_Str: $(this).find('.hidden-helix-str').val(), // 取原始字串
                                Helix_Direction: $(this).find('.gear-direction').val(),
                                Profile_Shift_X: $(this).find('.gear-shift-x').val(),
                                Pressure_Angle: $(this).find('.gear-pressure-angle').val(),
                                Workpiece_Length: $(this).find('.gear-length').val(),
                                Gear_Type: $(this).find('.gear-type').val(),
                                Remark_Gear: $(this).find('.gear-remark').val()
                            });
                        });

                        $.post('process_schedule_NOW.php', {
                            action: 'save_part_gear_info',
                            d_id: $('#gear_d_id').val(),
                            part_no: $('#gear_part_no').val(),
                            client_name: $('#gear_client_name').val(),
                            gears: JSON.stringify(gears)
                        }, function(res) {
                            if (res.success) {
                                showToast('成功', '齒輪規格已更新', true);
                                $('#gearSettingModal').modal('hide');
                                // 重新整理頁面以更新顯示 (或手動更新 DOM)
                                setTimeout(function() {
                                    location.reload();
                                }, 1000);
                            } else {
                                alert(res.message);
                            }
                        }, 'json');
                    });

                    // --- 點擊「臨時加工」按鈕 ---
                    $('#btnTempReport').click(function() {
                        // 重置表單
                        $('#quickReportForm')[0].reset();
                        $('#ngTable tbody').empty();
                        $('#modal_bom_ing_fid').val(''); // 臨時加工無 BOM ID
                        $('#modal_report_id').val('');
                        $('#modal_process_no').val('');

                        // 設定模式為 TEMP
                        $('input[name="report_source"][value="TEMP"]').prop('checked', true).trigger('change');
                        // 啟用模式選擇但隱藏 NORMAL
                        $('input[name="report_source"]').prop('disabled', false);
                        $('input[name="report_source"][value="NORMAL"]').parent().hide();


                        // 清空唯讀資訊欄位
                        $('#modal_client_name').val('');
                        $('#modal_part_no').val('');
                        $('#modal_bom_info').val('臨時加工模式 (無 BOM)');
                        $('#modal_stats_total, #modal_stats_ok, #modal_stats_ng, #modal_stats_remaining').text('-');

                        // 啟用輸入
                        if (userPerms.canReport) {
                            $('#quickReportForm input, #quickReportForm select, #quickReportForm textarea').prop('disabled', false);
                            $('#addNgRowBtn').show();
                            $('.user-select-group, .time-input-group, #production-input-container').show();
                        }

                        // 重建機台選單 (顯示全部)
                        var $machineSelect = $('#modal_machine_id');
                        $machineSelect.empty().append('<option value="">請選擇機台</option>');
                        $('#hidden_machine_id').remove();
                        allMachines.forEach(function(m) {
                            $machineSelect.append(`<option value="${m.machine_id}" data-need-setup="${m.need_setup}">${machineOptionLabel(m)}</option>`);
                        });

                        // 隱藏歷史紀錄 (無 BOM 無法查)
                        $('#historyTable tbody').html('<tr><td colspan="8" class="text-center text-muted">臨時加工不顯示歷史紀錄</td></tr>');

                        // 綁定儲存按鈕事件 (修正：補上事件綁定，否則按鈕無反應)
                        $('#saveQuickReportBtn').off('click').click(handleSaveReport);

                        $('#saveQuickReportBtn').text('提交臨時報工').removeClass('btn-warning').addClass('btn-danger').show();
                        $('#quickReportModal').modal('show');
                    });

                    // --- 臨時加工製程選擇連動 ---
                    $('#temp_process_select').change(function() {
                        var pNo = $(this).val();
                        var typeId = $(this).find(':selected').data('type-id'); // 取得 process_type_id

                        $('#modal_process_type_id').val(typeId); // 更新隱藏欄位
                        updateProcessFaceVisibility(); // 更新加工面顯示
                        // 2. 呼叫核心邏輯！(只要有設定就會瞬間跑出來)
                        if (typeof updateProcessFaceVisibility === 'function') {
                            updateProcessFaceVisibility();
                        }

                        $('#modal_process_no').val(pNo);

                        // 根據製程類別篩選機台
                        var $machineSelect = $('#modal_machine_id');
                        $machineSelect.empty().append('<option value="">請選擇機台</option>');

                        if (pNo) {
                            var selectedProc = allProcesses.find(function(p) {
                                return p.ProcessNo == pNo;
                            });
                            if (selectedProc && selectedProc.process_type_id) {
                                var typeId = selectedProc.process_type_id;
                                allMachines.forEach(function(m) {
                                    if (m.machine_type_id == typeId) {
                                        $machineSelect.append(`<option value="${m.machine_id}" data-need-setup="${m.need_setup}">${machineOptionLabel(m)}</option>`);
                                    }
                                });
                            } else {
                                // 若無對應類別，顯示全部
                                allMachines.forEach(function(m) {
                                    $machineSelect.append(`<option value="${m.machine_id}" data-need-setup="${m.need_setup}">${machineOptionLabel(m)}</option>`);
                                });
                            }
                        } else {
                            // 未選擇製程時顯示全部
                            allMachines.forEach(function(m) {
                                $machineSelect.append(`<option value="${m.machine_id}" data-need-setup="${m.need_setup}">${machineOptionLabel(m)}</option>`);
                            });
                        }
                        $machineSelect.trigger('change'); // 更新架機欄位顯示狀態
                    });

                    // --- 載入歷史紀錄函式 ---
                    function loadReportHistory(fid, processNo, reportId = null) {
                        $('#historyTable tbody').html('<tr><td colspan="8" class="text-center">載入中...</td></tr>');
                        $.post('process_schedule_NOW.php', {
                            action: 'get_report_history',
                            bom_ing_fid: fid,
                            process_no: processNo,
                            report_id: reportId
                        }, function(res) {
                            if (res.success) {
                                existingReports = res.history; // 更新全域變數
                                var rows = '';
                                if (res.history.length === 0) {
                                    rows = '<tr><td colspan="6" class="text-center text-muted">尚無報工紀錄</td></tr>';
                                } else {
                                    res.history.forEach(function(r) {
                                        var setupStr = (r.setup_start_time) ? r.setup_start_time.substr(11, 5) + '~' + (r.setup_end_time ? r.setup_end_time.substr(11, 5) : '') : '-';
                                        var prodStr = (r.production_start_time) ? r.production_start_time.substr(11, 5) + '~' + (r.production_end_time ? r.production_end_time.substr(11, 5) : '') : '-';
                                        var users = [];

                                        // 計算單筆時產
                                        var hourlyRate = '-';
                                        if (r.production_start_time && r.production_end_time) {
                                            var start = new Date(r.production_start_time.replace(/-/g, '/'));
                                            var end = new Date(r.production_end_time.replace(/-/g, '/'));
                                            var diffHrs = (end - start) / (1000 * 60 * 60);
                                            if (diffHrs > 0) {
                                                var totalQty = (parseInt(r.produced_qty) || 0) + (parseInt(r.total_ng) || 0);
                                                hourlyRate = (totalQty / diffHrs).toFixed(1);
                                            }
                                        }

                                        if (r.setup_user_name) users.push('架:' + r.setup_user_name);
                                        if (r.prod_user_name) users.push('產:' + r.prod_user_name);

                                        // 優先顯示架機開始日期，其次生產開始日期，最後才是報工日期
                                        var workDate = r.report_date;
                                        if (r.setup_start_time) {
                                            workDate = r.setup_start_time.substring(0, 10);
                                        } else if (r.production_start_time) {
                                            workDate = r.production_start_time.substring(0, 10);
                                        }

                                        var deleteBtn = '';
                                        if (userPerms.hasA || userPerms.hasD) {
                                            deleteBtn = '<button type="button" class="btn btn-xs btn-danger btn-delete-report" data-id="' + r.report_id + '" title="刪除紀錄"><i class="fa fa-trash"></i></button>';
                                        }
                                        var changeBomBtn = '';
                                        if (userPerms.canChangePartialBom) {
                                            changeBomBtn = '<button type="button" class="btn btn-xs btn-warning btn-change-bom-report" data-id="' + r.report_id + '" title="修改綁定BOM" style="margin-left:2px;"><i class="fa fa-exchange"></i></button>';
                                        }

                                        rows += `<tr data-report-id="${r.report_id}" style="cursor: pointer;" title="雙擊可編輯此紀錄 (補填結束時間)">
                                <td>${workDate}</td>
                                <td><span class="label label-default" style="font-size:10px;">${r.machine_name || '-'}</span></td>
                        <td>${r.process_face || '-'}</td>
                                <td>${setupStr}</td>
                                <td>${prodStr}</td>
                                <td>${r.produced_qty}</td>
                                <td>${r.total_ng || 0}</td>
                                <td>${hourlyRate}</td>
                                <td>${users.join(' ')}</td>
                                <td>${deleteBtn}${changeBomBtn}</td>
                            </tr>`;
                                    });
                                }
                                $('#historyTable tbody').html(rows);
                            } else {
                                $('#historyTable tbody').html('<tr><td colspan="7" class="text-danger">載入失敗: ' + (res.message || '未知錯誤') + '</td></tr>');
                            }
                        }, 'json').fail(function(xhr, status, error) {
                            console.error("History load error:", xhr.responseText);
                            $('#historyTable tbody').html('<tr><td colspan="7" class="text-danger">載入失敗 (連線錯誤)</td></tr>');
                        });
                    }

                    // --- 刪除報工紀錄 (僅限管理員) ---
                    $(document).on('click', '.btn-delete-report', function(e) {
                        e.stopPropagation(); // 防止觸發雙擊編輯
                        if (!confirm('確定刪除此報工紀錄？此操作無法復原。')) return;
                        var rid = $(this).data('id');
                        $.post('process_schedule_NOW.php', {
                            action: 'delete_daily_report',
                            report_id: rid
                        }, function(res) {
                            if (res.success) {
                                showToast('成功', res.message, true);

                                if (res.moved_to_unassigned) {
                                    // 若已無報工紀錄，重新整理頁面以移回未指派
                                    setTimeout(function() {
                                        location.reload();
                                    }, 1000);
                                } else {
                                    var fid = $('#modal_bom_ing_fid').val();
                                    var pno = $('#modal_process_no').val();
                                    loadReportHistory(fid, pno); // 重新載入列表
                                }
                            } else {
                                alert(res.message);
                            }
                        }, 'json');
                    });

                    // --- 修改補加工報工紀錄的 BOM 綁定 ---
                    $(document).on('click', '.btn-change-bom-report', function(e) {
                        e.stopPropagation();
                        var rid = $(this).data('id');
                        var answer = prompt('確定要修改此筆報工紀錄的 BOM 綁定嗎？\n請輸入 Y 確認：');
                        if (answer === null || answer.trim().toUpperCase() !== 'Y') {
                            alert('已取消。');
                            return;
                        }

                        // 建立搜尋視窗
                        var $modal = $('<div class="modal fade" id="changeBomModal" tabindex="-1" role="dialog">' +
                            '<div class="modal-dialog modal-sm" role="document">' +
                            '<div class="modal-content">' +
                            '<div class="modal-header">' +
                            '<button type="button" class="close" data-dismiss="modal">&times;</button>' +
                            '<h4 class="modal-title"><i class="fa fa-exchange"></i> 重新選擇 BOM 綁定</h4>' +
                            '</div>' +
                            '<div class="modal-body">' +
                            '<div class="input-group" style="margin-bottom:8px;">' +
                            '<input type="text" class="form-control input-sm" id="changeBomSearchTerm" placeholder="輸入 BOM / 料號 / 客戶...">' +
                            '<span class="input-group-btn"><button class="btn btn-default btn-sm" type="button" id="btnChangeBomSearch"><i class="fa fa-search"></i></button></span>' +
                            '</div>' +
                            '<div id="changeBomResults" class="list-group" style="max-height:250px; overflow-y:auto;"></div>' +
                            '</div>' +
                            '<div class="modal-footer">' +
                            '<button type="button" class="btn btn-default" data-dismiss="modal">取消</button>' +
                            '</div>' +
                            '</div></div></div>');

                        $('#changeBomModal').remove();
                        $('body').append($modal);
                        $modal.modal('show');
                        $modal.on('hidden.bs.modal', function() { $(this).remove(); });

                        // 搜尋功能
                        function doChangeBomSearch() {
                            var term = $('#changeBomSearchTerm').val().trim();
                            if (!term) return;
                            var $res = $('#changeBomResults');
                            $res.html('<div class="list-group-item"><i class="fa fa-spinner fa-spin"></i> 搜尋中...</div>');
                            $.post('process_schedule_NOW.php', {
                                action: 'search_partial_boms',
                                term: term
                            }, function(res) {
                                $res.empty();
                                if (res.success && res.data.length > 0) {
                                    res.data.forEach(function(item) {
                                        var html = '<a href="#" class="list-group-item change-bom-pick-item"' +
                                            ' data-fid="' + item.bom_ing_fid + '"' +
                                            ' data-procno="' + item.process_no + '"' +
                                            ' data-label="' + escapeHtml(item.bom + ' - ' + (item.ProcessName || item.process_no) + ' | ' + item.d_id + ' | ' + item.Client_Name) + '">' +
                                            '<strong>' + escapeHtml(item.bom) + '</strong> - ' + escapeHtml(item.ProcessName || item.process_no) + '<br>' +
                                            '<small class="text-muted">' + escapeHtml(item.d_id) + ' | ' + escapeHtml(item.Client_Name) + ' | 剩餘: ' + (item.sqty - item.total_ok_qty - item.total_ng_qty) + '</small>' +
                                            '</a>';
                                        $res.append(html);
                                    });
                                } else {
                                    $res.html('<div class="list-group-item text-muted">查無資料</div>');
                                }
                            }, 'json');
                        }

                        $('#btnChangeBomSearch').off('click').on('click', doChangeBomSearch);
                        $('#changeBomSearchTerm').off('keypress').on('keypress', function(e) {
                            if (e.which === 13) doChangeBomSearch();
                        });

                        // 選取 BOM 後送出
                        $(document).off('click', '.change-bom-pick-item').on('click', '.change-bom-pick-item', function(e) {
                            e.preventDefault();
                            var newFid    = $(this).data('fid');
                            var newProcNo = $(this).data('procno');
                            var label     = $(this).data('label');
                            if (!confirm('確定將此筆報工改綁定至：\n' + label + ' ?')) return;

                            $.post('process_schedule_NOW.php', {
                                action: 'change_report_bom',
                                report_id: rid,
                                new_bom_ing_fid: newFid,
                                new_process_no: newProcNo
                            }, function(res) {
                                if (res.success) {
                                    showToast('成功', res.message, true);
                                    $modal.modal('hide');
                                    var fid = $('#modal_bom_ing_fid').val();
                                    var pno = $('#modal_process_no').val();
                                    loadReportHistory(fid, pno);
                                } else {
                                    alert('失敗：' + res.message);
                                }
                            }, 'json');
                        });
                    });

                    // --- 雙擊歷史紀錄進行編輯 ---
                    $(document).on('dblclick', '#historyTable tbody tr', function() {
                        var rid = $(this).data('report-id');
                        if (!rid) return;

                        $.post('process_schedule_NOW.php', {
                            action: 'get_report_detail',
                            report_id: rid
                        }, function(res) {
                            if (res.success) {
                                var r = res.report;
                                $('#modal_report_id').val(r.report_id);
                                $('input[name="report_date"]').val(r.report_date);

                                // 嘗試設定機台 (若選單中有該機台)
                                if ($('#modal_machine_id option[value="' + r.machine_id + '"]').length > 0) {
                                    var $mSelect = $('#modal_machine_id');
                                    $mSelect.val(r.machine_id);
                                    // 只有在有權限時才啟用
                                    if (userPerms.canReport) {
                                        $mSelect.prop('disabled', false);
                                        // 移除隱藏欄位，確保使用下拉選單的值
                                        $('#hidden_machine_id').remove();
                                    }
                                    checkMachineSetupNeeded();
                                } else {
                                    // 若選單沒該機台 (可能被過濾掉)，暫時加回去
                                    // 這裡簡化處理：若不在清單中，可能無法正確顯示，但通常會在清單中
                                }

                                $('#modal_setup_user_id').val(r.setup_user_id).trigger('change');
                                $('#modal_production_user_id').val(r.production_user_id).trigger('change');

                                // 時間格式轉換 (DB: Y-m-d H:i:s -> Input: Y-m-d H:i)
                                var fmt = function(t) {
                                    return t ? t.substring(0, 16) : '';
                                };
                                $('#modal_setup_start_time').val(fmt(r.setup_start_time));
                                $('#modal_setup_end_time').val(fmt(r.setup_end_time));
                                $('#modal_production_start_time').val(fmt(r.production_start_time));
                                $('#modal_production_end_time').val(fmt(r.production_end_time));

                                $('#modal_produced_qty').val(r.produced_qty);
                                $('textarea[name="remark"]').val(r.remark);
                                $('input[name="is_finished"]').prop('checked', r.is_finished == 1);
                                $('input[name="is_face_finished"]').prop('checked', r.is_finished == 2);

                                // 設定模式與原因 (若有)
                                var source = r.report_source || 'NORMAL';
                                $('input[name="report_source"][value="' + source + '"]').prop('checked', true).trigger('change');
                                if (r.source_reason) $('#source_reason').val(r.source_reason);

                                // 回填加工面
                                if (r.process_face) {
                                    $('select[name="process_face"]').val(r.process_face);
                                } else {
                                    $('select[name="process_face"]').val('');
                                }

                                // 重建 NG 列表
                                $('#ngTable tbody').empty();
                                var originalNgTotal = 0;
                                if (res.ng_list && res.ng_list.length > 0) {
                                    res.ng_list.forEach(function(ngItem) {
                                        originalNgTotal += (parseInt(ngItem.ng_qty) || 0);
                                        var optionsHtml = '<option value="">-- 選擇原因 --</option>';
                                        ngOptionsList.forEach(function(opt) {
                                            var selected = (opt.ng_id == ngItem.ng_id) ? 'selected' : '';
                                            optionsHtml += `<option value="${opt.ng_id}" ${selected}>${opt.ng_txt}</option>`;
                                        });

                                        var rowHtml = `
                                <tr>
                                    <td><select class="form-control input-sm" name="ng_id[]">${optionsHtml}</select></td>
                                    <td><input type="number" class="form-control input-sm" name="ng_qty[]" value="${ngItem.ng_qty}"></td>
                                    <td><input type="text" class="form-control input-sm" name="ng_remark[]" value="${ngItem.ng_remark || ''}"></td>
                                    <td>
                                        ${userPerms.canReport ? '<button type="button" class="btn btn-xs btn-default" onclick="$(this).closest(\'tr\').remove()"><i class="fa fa-trash"></i></button>' : ''}
                                    </td>
                                </tr>
                            `;
                                        $('#ngTable tbody').append(rowHtml);
                                    });
                                }

                                // 儲存原始數值供驗證使用 (避免編輯時重複計算)
                                $('#modal_produced_qty').data('original-ok', r.produced_qty);
                                $('#modal_produced_qty').data('original-ng', originalNgTotal);

                                // 若無權限，禁用輸入
                                if (!userPerms.canReport) {
                                    $('#quickReportForm input, #quickReportForm select, #quickReportForm textarea').prop('disabled', true);
                                }

                                // 變更按鈕狀態
                                $('#saveQuickReportBtn').text('更新報工資料').removeClass('btn-primary').addClass('btn-warning');

                                // 若為生管 (C+R)，隱藏儲存按鈕 (生管無法報工/修改報工)
                                if (userPerms.isProdControl) {
                                    $('#saveQuickReportBtn').hide();
                                }
                            }
                        }, 'json');
                    });

                    // --- 檢查機台是否需要架機 ---
                    function checkMachineSetupNeeded() {
                        // 若為生管 (C+R)，強制隱藏架機欄位 (避免被下方 show() 覆蓋)
                        if (userPerms.isProdControl) {
                            $('#div_setup_user, #div_setup_start, #div_setup_end').hide();
                            return;
                        }

                        var $opt = $('#modal_machine_id option:selected');
                        var needSetup = $opt.data('need-setup');
                        // need_setup: 0=不需要, 1=需要 (預設視為需要，除非明確為0)
                        if (needSetup == 0) {
                            $('#div_setup_user, #div_setup_start, #div_setup_end').hide();
                            // 清空值以避免誤送
                            $('#modal_setup_user_id').val('').trigger('change');
                            $('#modal_setup_start_time').val('');
                            $('#modal_setup_end_time').val('');
                        } else {
                            $('#div_setup_user, #div_setup_start, #div_setup_end').show();
                        }
                    }

                    $('#modal_machine_id').change(checkMachineSetupNeeded);

                    // --- 加工面顯示邏輯 (權限=A 或 生管，若無選擇機台/人員則隱藏) ---
                    // --- 核心邏輯：控制「加工面」與「此面完工」同進同出 ---
                    window.updateProcessFaceVisibility = function() {
                        var processTypeId = $('#modal_process_type_id').val();
                        var pTypeIdStr = String(processTypeId || '');

                        // 1. 檢查製程設定是否開啟
                        var showFaceOptions = false;
                        if (processTypeId && window.uiSettings && window.uiSettings.show_face_options) {
                            showFaceOptions = window.uiSettings.show_face_options.includes(pTypeIdStr);
                        }

                        // 2. 絕對綁定：同進同出！
                        if (showFaceOptions) {
                            $('#div_1_side').show(); // 顯示：加工面選單
                            $('#div_face_finish_checkbox').show(); // 顯示：此面完工後換面
                        } else {
                            $('#div_1_side').hide(); // 隱藏：加工面選單
                            $('#div_face_finish_checkbox').hide(); // 隱藏：此面完工後換面

                            // 隱藏時順便清空，避免髒資料送進資料庫
                            $('select[name="process_face"]').val('');
                            $('input[name="is_face_finished"]').prop('checked', false);
                        }
                    };
                    $('#modal_machine_id, #modal_setup_user_id, #modal_production_user_id').change(updateProcessFaceVisibility);

                    // --- 人員選擇連動時間欄位 ---
                    function toggleTimeInputs(userSelectId, startInputId, endInputId) {
                        var hasUser = $(userSelectId).val() !== '';
                        var $start = $(startInputId);
                        var $end = $(endInputId);

                        if (!userPerms.canReport) return; // 無權限不處理啟用

                        $start.prop('disabled', !hasUser);
                        $end.prop('disabled', !hasUser);

                        if (!hasUser) {
                            $start.val('');
                            $end.val('');
                        }
                        calculateHourlyRate(); // 重新計算
                    }

                    $('#modal_setup_user_id').change(function() {
                        toggleTimeInputs('#modal_setup_user_id', '#modal_setup_start_time', '#modal_setup_end_time');
                    });

                    $('#modal_production_user_id').change(function() {
                        toggleTimeInputs('#modal_production_user_id', '#modal_production_start_time', '#modal_production_end_time');
                    });

                    // --- 計算平均時產 ---
                    function calculateHourlyRate() {
                        var startVal = $('#modal_production_start_time').val();
                        var endVal = $('#modal_production_end_time').val();
                        var qty = parseFloat($('#modal_produced_qty').val()) || 0;

                        // 計算 NG 總數
                        var ngQty = 0;
                        $('input[name="ng_qty[]"]').each(function() {
                            ngQty += (parseFloat($(this).val()) || 0);
                        });

                        var totalQty = qty + ngQty;
                        var $display = $('#avg_hourly_rate_display');

                        if (startVal && endVal && totalQty > 0) {
                            var start = new Date(startVal);
                            var end = new Date(endVal);
                            var diffMs = end - start;

                            if (diffMs > 0) {
                                var diffHrs = diffMs / (1000 * 60 * 60);
                                var rate = totalQty / diffHrs;
                                $display.text('平均產出: ' + rate.toFixed(1) + '/hr (含NG)');
                            } else {
                                $display.text('');
                            }
                        } else {
                            $display.text('');
                        }
                    }

                    // 監聽 NG 數量變化以更新平均產出
                    $(document).on('change input', '#modal_production_start_time, #modal_production_end_time, #modal_produced_qty, input[name="ng_qty[]"]', calculateHourlyRate);

                    // --- 報工模式切換邏輯 (完整修復版) ---
                    $('input[name="report_source"]').off('change').on('change', function() {
                        var mode = $(this).val();

                        // 定義區塊變數
                        var $reasonDiv = $('#div_source_reason');
                        var $tempProcDiv = $('#div_temp_process');
                        var $partialSearchDiv = $('#div_partial_search');

                        // 🌟 1. 恢復您原本的面板切換邏輯 (讓搜尋框回來！)
                        if (mode === 'NORMAL') {
                            $reasonDiv.hide();
                            $tempProcDiv.hide();
                            $partialSearchDiv.hide();
                        } else if (mode === 'PARTIAL') {
                            $reasonDiv.show();
                            $tempProcDiv.hide();
                            $partialSearchDiv.show(); // 顯示補加工的 BOM 搜尋框
                        } else if (mode === 'TEMP') {
                            $reasonDiv.show();
                            $tempProcDiv.show(); // 顯示臨時加工的製程選單
                            $partialSearchDiv.hide();
                        }

                        // 🌟 2. 結合我們剛剛寫的「加工面與此面完工」顯示邏輯
                        if (mode === 'TEMP' || mode === 'PARTIAL') {
                            // 只要切換到補加工或臨時加工，一律先強制隱藏並清空加工面欄位！
                            $('#div_1_side').hide();
                            $('#div_face_finish_checkbox').hide();
                            $('select[name="process_face"]').val('');
                            $('input[name="is_face_finished"]').prop('checked', false);

                            if (mode === 'TEMP') {
                                $('#modal_process_type_id').val(''); // 臨時加工一開始沒選製程，清空 ID
                            }
                        }

                        // 呼叫核心邏輯，讓系統根據當下的製程 ID 決定是否要把欄位叫出來
                        if (typeof updateProcessFaceVisibility === 'function') {
                            updateProcessFaceVisibility();
                        }
                    });

                    // 觸發一次 change 以設定初始狀態
                    $('input[name="report_source"]:checked').trigger('change');

                    // --- 補加工搜尋功能 ---
                    $('#btn_partial_search').click(function() {
                        var term = $('#partial_search_term').val().trim();
                        if (!term) return;

                        var $res = $('#partial_search_results');
                        $res.html('<div class="list-group-item"><i class="fa fa-spinner fa-spin"></i> 搜尋中...</div>').show();

                        $.post('process_schedule_NOW.php', {
                            action: 'search_partial_boms',
                            term: term
                        }, function(res) {
                            $res.empty();
                            if (res.success && res.data.length > 0) {
                                res.data.forEach(function(item) {
                                    var html = `
                            <a href="#" class="list-group-item partial-bom-item" data-json='${JSON.stringify(item).replace(/'/g, "&#39;")}'>
                                <strong>${item.bom}</strong> - ${item.ProcessName || item.process_no} <br>
                                <small class="text-muted">${item.d_id} | ${item.Client_Name} | 剩餘: ${item.sqty - item.total_ok_qty - item.total_ng_qty}</small>
                            </a>
                        `;
                                    $res.append(html);
                                });
                            } else {
                                $res.html('<div class="list-group-item text-muted">查無資料</div>');
                            }
                        }, 'json');
                    });

                    $(document).on('click', '.partial-bom-item', function(e) {
                        e.preventDefault();
                        var item = $(this).data('json');

                        // 更新 process_type_id 並觸發 UI 更新
                        $('#modal_process_type_id').val(item.process_type_id);
                        updateProcessFaceVisibility();

                        $('#modal_bom_ing_fid').val(item.bom_ing_fid);
                        $('#modal_process_no').val(item.process_no);
                        $('#modal_bom_info').val(item.bom + ' - ' + (item.ProcessName || item.process_no) + ' (數量: ' + item.sqty + ')').css('text-align', 'left');
                        $('#modal_client_name').val(item.Client_Name);
                        $('#modal_part_no').val(item.d_id);
                        $('#modal_stats_total').text(item.sqty);
                        $('#modal_stats_ok').text(item.total_ok_qty);
                        $('#modal_stats_ng').text(item.total_ng_qty);
                        $('#modal_stats_remaining').text(item.sqty - item.total_ok_qty - item.total_ng_qty);

                        // 根據製程類別篩選機台
                        var $machineSelect = $('#modal_machine_id');
                        $machineSelect.empty().append('<option value="">請選擇機台</option>');

                        if (item.process_type_id) {
                            allMachines.forEach(function(m) {
                                if (m.machine_type_id == item.process_type_id) {
                                    $machineSelect.append(`<option value="${m.machine_id}" data-need-setup="${m.need_setup}">${machineOptionLabel(m)}</option>`);
                                }
                            });
                        }
                        $machineSelect.trigger('change'); // 更新架機欄位顯示狀態

                        // 載入歷史紀錄
                        loadReportHistory(item.bom_ing_fid, item.process_no);

                        $('#partial_search_results').hide();
                    });

                    // --- 儲存處理函式 (分流邏輯) ---
                    function handleSaveReport() {
                        try {
                            // 若為生管模式 (C)，只更新備註
                            if (userPerms.isProdControl) {
                                var fid = $('#modal_bom_ing_fid').val();
                                var pti01_ps = $('textarea[name="pti01_ps"]').val();
                                var materialArrived = $('input[name="material_arrived"]').is(':checked') ? '1' : '0';

                                var promises = [];

                                // Promise for remarks update
                                promises.push($.post('process_schedule_NOW.php', {
                                    action: 'update_task_remarks',
                                    bom_ing_fid: fid,
                                    pti01_ps: pti01_ps
                                }));

                                // Promise for material status update
                                if (userPerms.canManageMaterial) {
                                    promises.push($.post('process_schedule_NOW.php', {
                                        action: 'update_material_status',
                                        bom_ing_fid: fid,
                                        status: materialArrived
                                    }));
                                }

                                $.when.apply($, promises).done(function() {
                                    showToast('成功', '資料已更新', true);
                                    $('#quickReportModal').modal('hide');
                                    setTimeout(function() {
                                        location.reload();
                                    }, 1000);
                                }).fail(function() {
                                    showToast('錯誤', '更新時發生錯誤', false);
                                });
                                return;
                            }

                            var reportSource = $('input[name="report_source"]:checked').val();

                            // 確保 bom_ing_fid 存在 (除非是 TEMP 模式)
                            if (reportSource !== 'TEMP' && !$('#modal_bom_ing_fid').val()) {
                                alert('系統錯誤：遺失工單 ID (bom_ing_fid)，請重新開啟視窗。');
                                return;
                            }

                            // --- 加工面必填檢查 (若欄位可見) ---
                            if ($('select[name="process_face"]').is(':visible') && !$('select[name="process_face"]').val()) {
                                alert('請選擇加工面');
                                return;
                            }

                            // --- 必填欄位驗證 ---
                            var reportDate = $('input[name="report_date"]').val();
                            var machineId = $('#modal_machine_id').val();
                            var setupUser = $('select[name="setup_user_id"]').val();
                            var prodUser = $('select[name="production_user_id"]').val();
                            var setupStart = $('input[name="setup_start_time"]').val();
                            var setupEnd = $('input[name="setup_end_time"]').val();
                            var prodStart = $('input[name="production_start_time"]').val();
                            var prodEnd = $('input[name="production_end_time"]').val();
                            var sourceReason = $('#source_reason').val();

                            // TEMP 模式檢查
                            if (reportSource === 'TEMP') {
                                if (!$('#modal_process_no').val()) {
                                    alert('臨時加工模式請選擇製程');
                                    return;
                                }
                                if (!sourceReason) {
                                    alert('臨時加工模式請選擇例外原因');
                                    return;
                                }
                            }


                            if (!reportDate) {
                                alert('請填寫回報日期');
                                return;
                            }
                            if (!machineId) {
                                alert('請選擇機台');
                                return;
                            }
                            if (!setupUser && !prodUser) {
                                alert('請至少選擇一位架機人員或生產人員');
                                return;
                            }
                            // 若選擇了架機人員，則開始時間必填 (結束時間可後補)
                            if (setupUser && !setupStart) {
                                alert('請填寫架機開始時間');
                                return;
                            }
                            // 若選擇了生產人員，則開始時間必填 (結束時間可後補)
                            if (prodUser && !prodStart) {
                                alert('請填寫生產開始時間');
                                return;
                            }

                            // --- 時間邏輯檢查 (前端先擋) ---
                            var now = new Date();
                            var maxFutureTime = new Date(now.getTime() + 10 * 60000); // Now + 10 minutes
                            function isFutureTime(timeStr) {
                                if (!timeStr) return false;
                                var t = new Date(timeStr.replace(/-/g, '/').replace('T', ' '));
                                return t > maxFutureTime;
                            }
                            if (isFutureTime(setupStart)) {
                                alert('架機開始時間不可超過目前時間 10 分鐘');
                                return;
                            }
                            if (isFutureTime(setupEnd)) {
                                alert('架機結束時間不可超過目前時間 10 分鐘');
                                return;
                            }
                            if (isFutureTime(prodStart)) {
                                alert('生產開始時間不可超過目前時間 10 分鐘');
                                return;
                            }
                            if (isFutureTime(prodEnd)) {
                                alert('生產結束時間不可超過目前時間 10 分鐘');
                                return;
                            }

                            if (setupStart && setupEnd && setupStart >= setupEnd) {
                                alert('架機結束時間必須大於開始時間');
                                return;
                            }
                            if (prodStart && prodEnd && prodStart >= prodEnd) { // 異常結束時間若有填，也需檢查
                                alert('生產結束時間必須大於開始時間');
                                return;
                            }
                            if (setupUser && prodUser) {
                                // 檢查重疊
                                if (setupStart < prodEnd && setupEnd > prodStart) {
                                    alert('架機時間與生產時間不可重疊');
                                    return;
                                }
                            }

                            // --- 檢查與歷史紀錄重疊 (前端優先檢查) ---
                            var hasOverlap = false;
                            var overlapMsg = '';

                            function checkOverlap(s1, e1, s2, e2) {
                                return Math.max(Date.parse(s1), Date.parse(s2)) < Math.min(Date.parse(e1), Date.parse(e2));
                            }

                            var currentReportId = $('#modal_report_id').val();

                            // TEMP 模式不檢查歷史重疊 (因為沒有 bom_ing_fid)
                            if (reportSource !== 'TEMP' && existingReports && existingReports.length > 0) {
                                var currentMachineId = $('#modal_machine_id').val();
                                $.each(existingReports, function(i, r) {
                                    if (currentReportId && r.report_id == currentReportId) return true;
                                    // 只檢查同一機台的報工時間重疊，不同機台可同時加工同一工單
                                    if (r.machine_id != currentMachineId) return true;

                                    var rSetupStart = r.setup_start_time ? r.setup_start_time.replace(' ', 'T') : null;
                                    var rSetupEnd = r.setup_end_time ? r.setup_end_time.replace(' ', 'T') : null;
                                    var rProdStart = r.production_start_time ? r.production_start_time.replace(' ', 'T') : null;
                                    var rProdEnd = r.production_end_time ? r.production_end_time.replace(' ', 'T') : null;

                                    if (setupStart && setupEnd) {
                                        if (rSetupStart && rSetupEnd && checkOverlap(setupStart, setupEnd, rSetupStart, rSetupEnd)) {
                                            hasOverlap = true;
                                            overlapMsg = '架機時間與歷史紀錄(ID:' + r.report_id + ')的架機時間重疊';
                                            return false;
                                        }
                                        if (rProdStart && rProdEnd && checkOverlap(setupStart, setupEnd, rProdStart, rProdEnd)) {
                                            hasOverlap = true;
                                            overlapMsg = '架機時間與歷史紀錄(ID:' + r.report_id + ')的生產時間重疊';
                                            return false;
                                        }
                                    }
                                    if (prodStart && prodEnd) {
                                        if (rSetupStart && rSetupEnd && checkOverlap(prodStart, prodEnd, rSetupStart, rSetupEnd)) {
                                            hasOverlap = true;
                                            overlapMsg = '生產時間與歷史紀錄(ID:' + r.report_id + ')的架機時間重疊';
                                            return false;
                                        }
                                        if (rProdStart && rProdEnd && checkOverlap(prodStart, prodEnd, rProdStart, rProdEnd)) {
                                            hasOverlap = true;
                                            overlapMsg = '生產時間與歷史紀錄(ID:' + r.report_id + ')的生產時間重疊';
                                            return false;
                                        }
                                    }
                                });
                            }

                            if (hasOverlap) {
                                alert(overlapMsg + '，請修正時間。');
                                return;
                            }


                            // --- 數量檢查邏輯 ---
                            var totalQty = parseInt($('#modal_stats_total').text()) || 0;
                            var prevOk = parseInt($('#modal_stats_ok').text()) || 0;
                            var prevNg = parseInt($('#modal_stats_ng').text()) || 0;

                            var currentOk = parseInt($('#modal_produced_qty').val()) || 0;
                            var currentNg = 0;

                            if (currentOk < 0) {
                                alert('本日完成數量不可小於 0');
                                return;
                            }

                            // 計算目前輸入的 NG 總數
                            var ngCheckPassed = true;

                            // NORMAL 模式檢查：剩餘數量
                            if (reportSource === 'NORMAL') {
                                var remaining = parseInt($('#modal_stats_remaining').text()) || 0;
                                if (remaining <= 0 && !$('#modal_report_id').val()) { // 編輯模式不擋
                                    alert('此工單已無剩餘可加工數量，請切換至「補加工」模式。');
                                    return;
                                }
                            }

                            $('input[name="ng_qty[]"]').each(function() {
                                var $row = $(this).closest('tr');
                                var ngId = $row.find('select[name="ng_id[]"]').val();
                                var val = parseInt($(this).val()) || 0;

                                if (val < 0) {
                                    alert('NG 數量不可小於 0');
                                    ngCheckPassed = false;
                                    return false;
                                }

                                // 檢查：若有選 NG 原因，數量必須 > 0
                                if (ngId && val <= 0) {
                                    alert('已選擇 NG 原因，數量必須大於 0');
                                    ngCheckPassed = false;
                                    return false;
                                }

                                // 檢查：若有填數量，必須選 NG 原因
                                if (!ngId && val > 0) {
                                    alert('已填寫 NG 數量，請選擇 NG 原因');
                                    ngCheckPassed = false;
                                    return false;
                                }

                                currentNg += val;
                            });

                            if (!ngCheckPassed) return;

                            // 檢查：若有生產人員且有結束時間，則 (良品+NG) 必須 > 0
                            // 若只有開始時間(開工)，則允許為 0
                            if (prodUser && prodEnd && (currentOk + currentNg) <= 0) {
                                alert('填寫生產人員且有結束時間時，(本日完成 + NG總數) 必須大於 0');
                                return;
                            }

                            // 若為編輯模式，需先扣除原本的數量，再加入新的數量
                            var originalOk = 0;
                            var originalNg = 0;
                            if ($('#modal_report_id').val()) {
                                originalOk = parseInt($('#modal_produced_qty').data('original-ok')) || 0;
                                originalNg = parseInt($('#modal_produced_qty').data('original-ng')) || 0;
                            }
                            var totalReported = (prevOk - originalOk) + (prevNg - originalNg) + currentOk + currentNg;

                            // 僅在 NORMAL 模式下提示超過總數 (若有選擇加工面則不提示，因為多面加工總數會超過訂單數)
                            var pFace = $('select[name="process_face"]').val();
                            if (reportSource === 'NORMAL' && totalReported > totalQty && (!pFace || pFace === 'ALL')) {
                                var exceed = totalReported - totalQty;
                                var msg = "警告：累積報工數量 (" + totalReported + ") 已超過訂單總數 (" + totalQty + ")！\n" +
                                    "超過數量: " + exceed + "\n\n" +
                                    "確定要繼續儲存嗎？";
                                if (!confirm(msg)) {
                                    return; // 使用者取消
                                }
                            }
                            // ------------------

                            var formData = $('#quickReportForm').serialize();

                            // 再次確保 report_id 有被包含 (防止因欄位被禁用而遺漏)
                            var currentReportId = $('#modal_report_id').val();
                            if (currentReportId && formData.indexOf('report_id=') === -1) {
                                formData += '&report_id=' + currentReportId;
                            }
                            // 補上可能因 disabled 而未序列化的隱藏欄位 (bom_ing_fid, process_no)
                            if (formData.indexOf('bom_ing_fid=') === -1) formData += '&bom_ing_fid=' + ($('#modal_bom_ing_fid').val() || '');
                            if (formData.indexOf('process_no=') === -1) formData += '&process_no=' + $('#modal_process_no').val();

                            // 處理 checkbox (未勾選時不會送出，需手動補上 0 或在後端處理)
                            // 這裡我們確保如果 checkbox 存在但未勾選，後端能識別 (透過 material_arrived_present 隱藏欄位)
                            // 如果 checkbox 被 disabled，serialize() 也不會包含它，所以需要手動處理
                            if ($('input[name="material_arrived"]').length > 0 && !$('input[name="material_arrived"]').is(':disabled')) {
                                // 如果未勾選，serialize 不會包含，後端需檢查 material_arrived_present
                                // 如果勾選，serialize 會包含 material_arrived=1
                            }

                            // 確保 machine_id 被送出 (即使選單被 disabled)
                            if (formData.indexOf('machine_id=') === -1) {
                                formData += '&machine_id=' + $('#modal_machine_id').val();
                            }

                            $.post('process_schedule_NOW.php', formData, function(response) {
                                if (response.success) {
                                    $('#quickReportModal').modal('hide');
                                    showToast('成功', response.message, true);
                                    // 延遲 3 秒後重新整理，讓使用者看清楚提示
                                    setTimeout(function() {
                                        location.reload();
                                    }, 3000);
                                } else {
                                    showToast('錯誤', response.message, false);
                                }
                            }, 'json').fail(function() {
                                showToast('錯誤', '連線失敗，請稍後再試', false);
                            });
                        } catch (e) {
                            console.error("Save Report Error:", e);
                            alert("發生錯誤: " + e.message);
                        }
                    }

                    // --- 異常類型管理 ---
                    $('#manageAbnormalTypeBtn').click(function() {
                        $('#abnormalTypeModal').modal('show');
                    });

                    // --- 雙擊機台標題開啟異常通報 ---
                    $(document).on('dblclick', '.kanban-column-header', function() {
                        var machineId = $(this).data('machine-id');
                        var machineName = $(this).data('machine-name');

                        if (!machineId) return;

                        $('#abnormal_machine_id').val(machineId);
                        $('#abnormal_machine_name').text(machineName);

                        // 重置表單
                        resetAbnormalForm();

                        // 載入該機台的異常資訊
                        loadMachineAbnormalInfo(machineId);

                        // 權限控制
                        if (!userPerms.canReport) {
                            $('#machineAbnormalForm input, #machineAbnormalForm select, #machineAbnormalForm textarea').prop('disabled', true);
                        }

                        $('#machineAbnormalModal').modal('show');
                    });



                    // --- 查詢已完工功能 ---
                    $('#btnSearchFinished').click(function() {
                        $('#searchFinishedModal').modal('show');
                        doFinishedSearch(); // 開啟時自動載入最近資料
                    });

                    function doFinishedSearch() {
                        var term = $('#finished_search_term').val().trim();
                        var $list = $('#finished_search_results');

                        $list.html('<div class="list-group-item text-center"><i class="fa fa-spinner fa-spin"></i> 搜尋中...</div>');

                        $.post('process_schedule_NOW.php', {
                            action: 'search_finished_tasks',
                            term: term
                        }, function(res) {
                            $list.empty();
                            if (res.success) {
                                if (res.data.length === 0) {
                                    $list.html('<div class="list-group-item text-center text-muted">查無資料</div>');
                                } else {
                                    // Requirement 3: Add filter buttons
                                    var filterHtml = '<div class="btn-group btn-group-xs" style="margin-bottom: 10px;">';
                                    filterHtml += '<button type="button" class="btn btn-warning process-filter-btn active" data-type-id="all">全部顯示</button>';
                                    if (res.machine_types) {
                                        res.machine_types.forEach(function(type) {
                                            filterHtml += `<button type="button" class="btn btn-default process-filter-btn" data-type-id="${type.machine_type_id}">${escapeHtml(type.machine_type)}</button>`;
                                        });
                                    }
                                    filterHtml += '</div>';
                                    $list.append(filterHtml);
                                    // 判斷是否為報工列表模式 (空搜尋)
                                    if (res.data[0].is_report_list) {
                                        // Requirement 1 & 2: Modify table header

                                        var tableHtml = '<div class="table-responsive"><table class="table table-bordered table-condensed table-striped table-hover" style="font-size:12px; white-space: nowrap;">' +
                                            '<thead style="background:#f5f5f5;"><tr style="position: sticky; top: 0; z-index: 1; background: #f5f5f5;"><th>時間</th><th>客戶</th><th>BOM/料號/規格</th><th>製程</th><th>加工面</th><th>累計/訂單</th><th>NG</th><th>工時(架/產)</th><th>機台 | 人員 & 時間 & 數量</th></tr></thead><tbody>';

                                        res.data.forEach(function(item) {
                                            var userTimeHtml = '';
                                            var latest_process_face = '-';
                                            // 收集此工單所有用過的機台（去重）
                                            var machineSet = {};
                                            if (item.logs && item.logs.length > 0) {
                                                item.logs.forEach(function(log) {
                                                    if (log.machine) machineSet[log.machine] = true;
                                                });
                                            }
                                            var allMachinesArr = Object.keys(machineSet);
                                            var multiMachine = allMachinesArr.length > 1;

                                            if (item.logs && item.logs.length > 0) {
                                                item.logs.forEach(function(log) {
                                                    var u = log.prod_user || log.setup_user || '-';
                                                    var startTime = log.production_start_time || log.setup_start_time || '';
                                                    var endTime = log.production_end_time || log.setup_end_time || '';
                                                    var ok_qty = parseInt(log.produced_qty) || 0;
                                                    var ng_qty = parseInt(log.log_ng_qty) || 0;
                                                    var qty_html = `數量: ${ok_qty}`;
                                                    if (ng_qty > 0) {
                                                        qty_html += ` / <span style="color:red;">${ng_qty}</span>`;
                                                    }

                                                    var startTimeFormatted = startTime ? startTime.substr(5, 11).replace('-', '/') : '';
                                                    var endTimeFormatted = endTime ? endTime.substr(11, 5) : '';

                                                    // 機台 badge：單機台每筆都顯示（簡潔），多機台高亮不同色
                                                    var machineTag = '';
                                                    if (log.machine) {
                                                        var badgeColor = multiMachine ? 'label-warning' : 'label-default';
                                                        machineTag = `<span class="label ${badgeColor}" style="margin-right:4px;">${log.machine}</span>`;
                                                    }

                                                    userTimeHtml += `<div style="margin-bottom:2px;">${machineTag}${u} | ${startTimeFormatted} - ${endTimeFormatted || '未完'} | <strong style="color:#c85a5a;">${qty_html}</strong></div>`;
                                                });
                                            }

                                            var finishedMark = (item.is_finished == 1) ? ' <i class="fa fa-check-circle text-success" title="本站完工"></i>' : '';

                                            var jsonStr = JSON.stringify(item).replace(/'/g, "&#39;").replace(/"/g, "&quot;");

                                            var bomPartCell = `
<div class="bom-part-cell">
    <div class="bom-no">${item.bom}</div>
    <div class="part-no">${item.d_id}</div>
    ${item.gear_spec ? `<div class="gear-spec"><i class="fa fa-cog"></i> ${item.gear_spec}</div>` : ``}
</div>
`;

                                            tableHtml += `<tr class="finished-task-item" data-json='${jsonStr}' data-process-type-id="${item.process_type_id}" style="cursor:pointer;">
                                <td>${item.report_date}</td>
                                    <td>${item.Client_Name}</td>
                                <td>${bomPartCell}</td> 
                                    <td>${item.ProcessName || item.process_no || ''}</td>
                                    <td>${item.face_info || '-'}</td>
                                    <td><span class="text-primary" style="font-weight:bold;">${item.total_ok_qty}</span> / ${item.sqty}${finishedMark}</td>
                                    <td>${item.total_ng_qty > 0 ? '<span class="text-danger">'+item.total_ng_qty+'</span>' : '0'}</td>
                                    <td>${item.setup_hours}H / ${item.prod_hours}H</td>
                                    <td>${userTimeHtml}</td>
                                </tr>`;
                                        });
                                        tableHtml += '</tbody></table></div>';
                                        $list.append(tableHtml);
                                    } else {
                                        // Fallback for non-report list view
                                        res.data.forEach(function(item) {
                                            var bomPartCell = `<div style="line-height: 1.2;"><strong>${item.bom}</strong></div>
                                                       <div style="line-height: 1.2;"><span class="text-muted">${item.d_id}</span></div>`;
                                            if (item.gear_spec) {
                                                bomPartCell += `<div style="line-height: 1.2; font-size: 11px; color: #777;">${item.gear_spec}</div>`;
                                            }

                                            var html = `
                                <a href="#" class="list-group-item finished-task-item" data-json='${JSON.stringify(item).replace(/'/g, "&#39;")}'>
                                    <h5 class="list-group-item-heading">${bomPartCell}</h5>
                                    <p class="list-group-item-text text-muted" style="font-size: 12px;">
                                        料號: ${item.d_id} | 客戶: ${item.Client_Name} <br>
                                        數量: ${item.sqty} | 已完工: ${item.total_ok_qty} | NG: ${item.total_ng_qty}
                                    </p>
                                </a>
                            `;
                                            $list.append(html); // This part seems to be for a different view, but let's keep it.

                                        });
                                    }
                                }
                            } else {
                                $list.html('<div class="list-group-item text-danger">搜尋失敗: ' + res.message + '</div>');
                            }
                            // Requirement 3: Add click handler for filter buttons using event delegation on the modal
                            $('#searchFinishedModal').off('click', '.process-filter-btn').on('click', '.process-filter-btn', function() {
                                var $this = $(this);
                                $this.closest('.btn-group').find('.process-filter-btn').removeClass('active btn-warning').addClass('btn-default');
                                $this.addClass('active btn-warning');

                                var typeId = $this.data('type-id');
                                if (typeId === 'all') {
                                    $('#finished_search_results .finished-task-item').show();
                                } else {
                                    $('#finished_search_results .finished-task-item').hide();
                                    $('#finished_search_results .finished-task-item[data-process-type-id="' + typeId + '"]').show();
                                }
                            });
                        }, 'json');
                    }

                    $('#doSearchFinishedBtn').click(doFinishedSearch);

                    var finishedSearchTimer;
                    $('#finished_search_term').on('input', function() {
                        clearTimeout(finishedSearchTimer);
                        finishedSearchTimer = setTimeout(doFinishedSearch, 500);
                    });

                    // 搜尋框清空時自動搜尋
                    $('#finished_search_term').on('input', function() {
                        if ($(this).val().trim() === '') {
                            doFinishedSearch();
                        }
                    });

                    $('#finished_search_term').on('dblclick', function() {
                        if ($(this).val()) {
                            $(this).val('');
                            $('#finished_search_results').empty();
                        }
                    });

                    // 補加工搜尋：輸入即自動搜尋
                    var partialSearchTimer;
                    $('#partial_search_term').on('input', function() {
                        clearTimeout(partialSearchTimer);
                        var term = $(this).val().trim();
                        if (term) {
                            partialSearchTimer = setTimeout(function() {
                                $('#btn_partial_search').click();
                            }, 500);
                        } else {
                            $('#partial_search_results').hide().empty();
                        }
                    });

                    // 補加工搜尋：雙擊清除
                    $('#partial_search_term').on('dblclick', function() {
                        $(this).val('');
                        $('#partial_search_results').hide().empty();
                    });

                    // 點擊搜尋結果開啟報工視窗 (檢視/修改紀錄)
                    $(document).on('click', '.finished-task-item', function(e) {
                        e.preventDefault();
                        var item = $(this).data('json');

                        // 手動填入 Modal 資料
                        $('#modal_bom_ing_fid').val(item.bom_ing_fid);
                        $('#modal_bom_val').val(item.bom); // 暫存 BOM
                        $('#modal_process_no').val(item.process_no);
                        $('#modal_bom_info').val(item.bom + ' - ' + (item.ProcessName || item.process_no) + ' (數量: ' + item.sqty + ')').css('text-align', 'left');
                        $('#modal_client_name').val(item.Client_Name);
                        $('#modal_part_no').val(item.d_id);
                        $('#modal_stats_total').text(item.sqty);
                        $('#modal_stats_ok').text(item.total_ok_qty);
                        $('#modal_stats_ng').text(item.total_ng_qty);
                        $('#modal_stats_remaining').text(item.sqty - item.total_ok_qty - item.total_ng_qty);

                        // 判斷是否為臨時加工
                        if (item.report_source === 'TEMP') {
                            $('input[name="report_source"][value="TEMP"]').prop('checked', true).trigger('change');
                            // 臨時加工不需要 source_reason 預設值，或保持原樣
                        } else {
                            // 查詢已完工 -> 預設為 PARTIAL 模式
                            $('input[name="report_source"][value="PARTIAL"]').prop('checked', true).trigger('change');
                            $('#source_reason').val('補加工');
                        }

                        $('textarea[name="pti01_ps"]').val(item.pti01_ps);
                        $('select[name="process_face"]').val(''); // 歷史紀錄不回填加工面，因為是單次報工屬性

                        // --- 動態產生機台選單 (針對已完工項目) ---
                        var $machineSelect = $('#modal_machine_id');
                        $machineSelect.empty();
                        $machineSelect.append('<option value="">請選擇機台</option>');
                        $('#hidden_machine_id').remove();

                        var processTypeId = item.process_type_id;
                        allMachines.forEach(function(m) {
                            if (m.machine_type_id == processTypeId) {
                                $machineSelect.append(`<option value="${m.machine_id}" data-need-setup="${m.need_setup}">${machineOptionLabel(m)}</option>`);
                            }
                        });
                        if (userPerms.canReport) {
                            $machineSelect.prop('disabled', false);
                        }

                        // 重置輸入欄位
                        $('#modal_setup_start_time, #modal_setup_end_time, #modal_production_start_time, #modal_production_end_time').val('').prop('disabled', true);
                        $('#modal_setup_user_id, #modal_production_user_id').val('');
                        $('#modal_produced_qty').val('');
                        $('#avg_hourly_rate_display').text('');
                        $('#ngTable tbody').empty();

                        // 綁定儲存按鈕事件 (修正：補上事件綁定)
                        var $saveBtn = $('#saveQuickReportBtn');
                        $saveBtn.off('click').click(handleSaveReport);
                        $saveBtn.text('更新報工資料').removeClass('btn-primary').addClass('btn-warning').show();

                        // 載入歷史紀錄
                        if (item.report_source === 'TEMP') {
                            loadReportHistory(null, null, item.report_id);
                        } else {
                            loadReportHistory(item.bom_ing_fid, item.process_no);
                        }

                        // 顯示 Modal
                        $('#searchFinishedModal').modal('hide');
                        $('#quickReportModal').modal('show');

                        // 檢查機台設定 (雖然已完工可能無機台，但若有紀錄則顯示)
                        checkMachineSetupNeeded();
                        // 這裡不強制鎖定機台，因為是檢視模式
                    });

                    // 點擊剩餘數量自動帶入本日完成
                    $('#modal_stats_remaining').css('cursor', 'pointer').attr('title', '點擊帶入本日完成數量').click(function() {
                        var val = parseInt($(this).text()) || 0;
                        if (val > 0) {
                            $('#modal_produced_qty').val(val).trigger('input'); // 觸發 input 事件以更新計算
                        }
                    });

                    // --- 儲存異常通報 ---
                    $('#saveAbnormalBtn').click(function() {
                        var now = new Date();
                        var maxFutureTime = new Date(now.getTime() + 10 * 60000); // Now + 10 minutes
                        function isFutureTime(timeStr) {
                            if (!timeStr) return false;
                            var t = new Date(timeStr.replace(/-/g, '/').replace('T', ' '));
                            return t > maxFutureTime;
                        }

                        if (isFutureTime($('#abnormal_start_time').val())) {
                            alert('異常開始時間不可超過目前時間 10 分鐘');
                            return;
                        }
                        if (isFutureTime($('#abnormal_end_time').val())) {
                            alert('異常結束時間不可超過目前時間 10 分鐘');
                            return;
                        }
                        if ($('#fg_action_time').is(':visible') && isFutureTime($('#action_time').val())) {
                            alert('時間不可超過目前時間 10 分鐘');
                            return;
                        }

                        var formData = $('#machineAbnormalForm').serialize();

                        // 嘗試從 PHP Session (頁面載入時) 或 側邊欄 DOM (#user_id) 獲取 user_id
                        // 修正：增強抓取邏輯，支援 input value 或 text content
                        var currentUserId = "<?= $_SESSION['user_id'] ?? '' ?>";

                        if (!currentUserId) {
                            var $userEl = $('#user_id');
                            if ($userEl.length) {
                                // 嘗試取值 (input) 或取文字 (span/div)
                                currentUserId = $userEl.val() || $userEl.text().trim();
                            }
                            // 如果還是沒有，嘗試找 name="user_id" 的欄位
                            if (!currentUserId && $('input[name="user_id"]').length) {
                                currentUserId = $('input[name="user_id"]').val();
                            }
                        }

                        if (currentUserId) {
                            formData += '&user_id=' + currentUserId;
                        }

                        $.post('process_schedule_NOW.php', formData, function(response) {
                            if (response.success) {
                                $('#machineAbnormalModal').modal('hide');
                                showToast('成功', response.message, true);
                                setTimeout(function() {
                                    location.reload();
                                }, 1000);
                            } else {
                                alert(response.message);
                            }
                        }, 'json').fail(function() {
                            alert('連線失敗');
                        });
                    });


                    $('#addAbnormalTypeBtn').click(function() {
                        var name = $('#new_abnormal_name').val().trim();
                        if (!name) return;
                        $.post('process_schedule_NOW.php', {
                            action: 'manage_abnormal_type',
                            type: 'add',
                            abnormal_name: name
                        }, function(res) {
                            if (res.success) {
                                // 更新列表
                                $('#abnormalTypeList').append(`<li class="list-group-item clearfix">${res.name} <button class="btn btn-xs btn-danger pull-right delete-abnormal-type" data-id="${res.id}"><i class="fa fa-trash"></i></button></li>`);
                                // 更新下拉選單
                                $('#abnormal_type_id').append(`<option value="${res.id}">${res.name}</option>`);
                                $('#new_abnormal_name').val('');
                            } else {
                                alert(res.message);
                            }
                        }, 'json');
                    });

                    $(document).on('click', '.delete-abnormal-type', function() {
                        if (!confirm('確定刪除此類型？')) return;
                        var $li = $(this).closest('li');
                        var id = $(this).data('id');
                        $.post('process_schedule_NOW.php', {
                            action: 'manage_abnormal_type',
                            type: 'delete',
                            id: id
                        }, function(res) {
                            if (res.success) {
                                $li.remove();
                                $('#abnormal_type_id option[value="' + id + '"]').remove();
                            } else {
                                alert(res.message);
                            }
                        }, 'json');
                    });

                    // --- 重置異常表單 ---
                    function resetAbnormalForm() {
                        $('#modal_abnormal_id').val('');
                        $('#abnormal_sub_action').val('');
                        $('#abnormal_type_id').val('');
                        $('#abnormal_desc').val('').prop('readonly', false);
                        $('#abnormal_start_time').val('');
                        $('#abnormal_end_time').val('');
                        $('#action_time').val('');
                        $('#action_desc').val('');

                        // 顯示標準欄位
                        $('#fg_abnormal_type, #fg_abnormal_start, #fg_abnormal_end, #fg_abnormal_desc').show();
                        // 隱藏動作欄位
                        $('#fg_action_time, #fg_action_desc').hide();

                        $('#saveAbnormalBtn').text('提交異常通報').removeClass('btn-primary btn-success').addClass('btn-danger');

                        if (userPerms.canReport) {
                            $('#machineAbnormalForm input, #machineAbnormalForm select, #machineAbnormalForm textarea').prop('disabled', false);
                        }

                        $('#resetAbnormalBtn').hide();
                    }

                    $('#resetAbnormalBtn').click(function() {
                        resetAbnormalForm();
                    });

                    // 取得當前時間字串 (YYYY-MM-DDTHH:mm)
                    // 取得當前時間字串 (YYYY-MM-DD HH:mm)
                    function getNowString() {
                        var now = new Date();
                        now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
                        return now.toISOString().slice(0, 16);
                        var year = now.getFullYear();
                        var month = String(now.getMonth() + 1).padStart(2, '0');
                        var day = String(now.getDate()).padStart(2, '0');
                        var hour = String(now.getHours()).padStart(2, '0');
                        var minute = String(now.getMinutes()).padStart(2, '0');
                        return `${year}-${month}-${day} ${hour}:${minute}`;
                    }

                    // HTML 跳脫字元處理 (防止 XSS 與 屬性破壞)
                    function escapeHtml(text) {
                        var map = {
                            '&': '&amp;',
                            '<': '&lt;',
                            '>': '&gt;',
                            '"': '&quot;',
                            "'": '&#039;'
                        };
                        return (text || '').replace(/[&<>"']/g, function(m) {
                            return map[m];
                        });
                    }

                    // --- 載入機台異常資訊 ---
                    function loadMachineAbnormalInfo(machineId) {
                        if (!machineId) return;
                        $.post('process_schedule_NOW.php', {
                            action: 'get_machine_abnormal_info',
                            machine_id: machineId
                        }, function(res) {
                            if (res.success) {
                                // 1. 未結案列表
                                var openRows = '';
                                if (res.open_cases.length === 0) {
                                    openRows = '<tr><td colspan="5" class="text-center text-muted">無未結案異常</td></tr>';
                                } else {
                                    res.open_cases.forEach(function(c) {
                                        var fullDesc = c.abnormal_desc || '';

                                        // 解析說明與進度
                                        var lines = fullDesc.split('\n');
                                        var descText = lines[0]; // 第一行作為主要說明
                                        var progressText = '';

                                        // 尋找最後一筆進度
                                        for (var i = lines.length - 1; i >= 0; i--) {
                                            if (lines[i].indexOf('] 進度:') !== -1) {
                                                progressText = lines[i];
                                                break;
                                            }
                                        }

                                        var shortDesc = descText.length > 12 ? descText.substr(0, 12) + '...' : descText;
                                        var cellContent = `<div title="${escapeHtml(descText)}">${escapeHtml(shortDesc)}</div>`;

                                        if (progressText) {
                                            var displayStr = progressText.replace('[', '').replace(']', '');

                                            // 若進度年份與異常開始年份相同，則隱藏年份
                                            var timeEndIndex = progressText.indexOf(']');
                                            if (timeEndIndex > 5) {
                                                var timeStr = progressText.substring(1, timeEndIndex);
                                                var contentStr = progressText.substring(timeEndIndex + 1).trim();
                                                var startYear = (c.abnormal_start_time || '').substr(0, 4);
                                                var progYear = timeStr.substr(0, 4);
                                                if (startYear && progYear === startYear) {
                                                    displayStr = timeStr.substring(5) + ' ' + contentStr;
                                                }
                                            }

                                            cellContent += `<div class="text-primary" style="font-size: 11px; margin-top: 3px; border-top: 1px dotted #ccc; padding-top: 2px;" title="${escapeHtml(progressText)}">
                                    ${escapeHtml(displayStr)}
                                </div>`;
                                        }

                                        var actionBtns = '';
                                        if (userPerms.canReport) {
                                            actionBtns = `
                                    <button type="button" class="btn btn-xs btn-primary btn-progress" data-id="${c.abnormal_id}" data-desc="${escapeHtml(fullDesc)}">進度</button>
                                    <button type="button" class="btn btn-xs btn-success btn-close-case" data-id="${c.abnormal_id}" data-desc="${escapeHtml(fullDesc)}">結案</button>
                                `;
                                        }

                                        if (userPerms.hasA || userPerms.hasD) {
                                            actionBtns += ` <button type="button" class="btn btn-xs btn-danger btn-delete-abnormal" data-id="${c.abnormal_id}"><i class="fa fa-trash"></i></button>`;
                                        }

                                        openRows += `<tr>
                                <td>${c.abnormal_start_time}</td>
                                <td>${c.abnormal_name}</td>
                                <td>${cellContent}</td>
                                <td>${c.user_cname || c.Created_By || ''}</td>
                                <td>${actionBtns}</td>
                            </tr>`;
                                    });
                                }
                                $('#abnormalOpenTable tbody').html(openRows);

                                // 2. 歷史紀錄
                                var histRows = '';
                                res.history.forEach(function(h) {
                                    var statusLabel = (h.handle_status === 'CLOSED') ? '<span class="label label-success">已結案</span>' : '<span class="label label-danger">未結案</span>';
                                    var delBtn = '';
                                    if (userPerms.hasA || userPerms.hasD) {
                                        delBtn = `<button type="button" class="btn btn-xs btn-danger btn-delete-abnormal" data-id="${h.abnormal_id}"><i class="fa fa-trash"></i></button>`;
                                    }
                                    histRows += `<tr>
                            <td>${h.abnormal_start_time}</td>
                            <td>${h.abnormal_name}</td>
                            <td>${statusLabel}</td>
                            <td>${h.user_cname || h.Created_By || ''}</td>
                            <td>${delBtn}</td>
                        </tr>`;
                                });
                                $('#abnormalHistoryTable tbody').html(histRows);
                            }
                        }, 'json');
                    }

                    // 機台變更時，若在異常模式下，重新載入
                    $('#modal_machine_id').change(function() {
                        if ($('#is_abnormality').is(':checked')) {
                            loadMachineAbnormalInfo($(this).val());
                        }
                    });

                    // 刪除異常紀錄
                    $(document).on('click', '.btn-delete-abnormal', function(e) {
                        e.stopPropagation();
                        if (!confirm('確定刪除此異常紀錄？')) return;
                        var id = $(this).data('id');
                        var machineId = $('#abnormal_machine_id').val();
                        $.post('process_schedule_NOW.php', {
                            action: 'delete_machine_abnormal',
                            abnormal_id: id
                        }, function(res) {
                            if (res.success) {
                                showToast('成功', res.message, true);
                                loadMachineAbnormalInfo(machineId);
                            } else {
                                alert(res.message);
                            }
                        }, 'json');
                    });

                    // 點擊 "進度"
                    $(document).on('click', '.btn-progress', function() {
                        var id = $(this).data('id');
                        var desc = $(this).data('desc');

                        resetAbnormalForm();
                        $('#modal_abnormal_id').val(id);
                        $('#abnormal_sub_action').val('progress');

                        // 設定 UI
                        $('#fg_abnormal_type, #fg_abnormal_start, #fg_abnormal_end').hide();
                        $('#abnormal_desc').val(desc).prop('readonly', true); // 顯示原始說明但唯讀

                        $('#fg_action_time').show();
                        $('#lbl_action_time').text('回報時間');
                        $('#action_time').val(getNowString());

                        $('#fg_action_desc').show();
                        $('#lbl_action_desc').text('處理進度');

                        if (userPerms.canReport) {
                            $('#action_time, #action_desc').prop('disabled', false);
                        }

                        $('#saveAbnormalBtn').text('更新進度').removeClass('btn-danger').addClass('btn-primary');
                        $('#resetAbnormalBtn').show();
                    });

                    // 點擊 "結案"
                    $(document).on('click', '.btn-close-case', function() {
                        var id = $(this).data('id');
                        var desc = $(this).data('desc');

                        resetAbnormalForm();
                        $('#modal_abnormal_id').val(id);
                        $('#abnormal_sub_action').val('close');

                        // 設定 UI
                        $('#fg_abnormal_type, #fg_abnormal_start, #fg_abnormal_end').hide();
                        $('#abnormal_desc').val(desc).prop('readonly', true);

                        $('#fg_action_time').show();
                        $('#lbl_action_time').text('結案時間');
                        $('#action_time').val(getNowString());

                        $('#fg_action_desc').show();
                        $('#lbl_action_desc').text('結案備註');

                        if (userPerms.canReport) {
                            $('#action_time, #action_desc').prop('disabled', false);
                        }

                        $('#saveAbnormalBtn').text('確認結案').removeClass('btn-danger').addClass('btn-success');
                        $('#resetAbnormalBtn').show();
                    });

                    // --- 儲存部門設定 ---
                    $('#saveDeptSettingBtn').click(function() {
                        var formData = $('#deptSettingForm').serialize();
                        $.post('process_schedule_NOW.php', formData, function(response) {
                            if (response.success) {
                                $('#deptSettingModal').modal('hide');
                                showToast('成功', response.message, true);
                                setTimeout(function() {
                                    location.reload();
                                }, 1000);
                            } else {
                                showToast('錯誤', response.message, false);
                            }
                        }, 'json').fail(function() {
                            showToast('錯誤', '連線失敗，請稍後再試', false);
                        });
                    });

                    // --- 儲存個人顯示設定 ---
                    $('#savePersonalSettingBtn').click(function() {
                        // 修正：手動收集所有 checkbox 狀態 (包含未勾選的設為 0)
                        var settings = {};
                        $('#personalSettingForm input[type="checkbox"]').each(function() {
                            var name = $(this).attr('name').replace('settings[', '').replace(']', '');
                            settings[name] = $(this).is(':checked') ? 1 : 0;
                        });

                        $.post('process_schedule_NOW.php', {
                            action: 'save_personal_setting',
                            settings: settings
                        }, function(res) {
                            if (res.success) {
                                $('#personalSettingModal').modal('hide');
                                showToast('成功', res.message, true);

                                // 立即套用設定 (切換 Body Class)
                                var settings = {};
                                $('#personalSettingForm input[type="checkbox"]').each(function() {
                                    var name = $(this).attr('name').replace('settings[', '').replace(']', '');
                                    settings[name] = $(this).is(':checked');
                                });

                                for (var key in settings) {
                                    var className = 'hide-' + key.replace(/_/g, '-');
                                    if (settings[key]) $('body').removeClass(className);
                                    else $('body').addClass(className);
                                }
                            } else {
                                showToast('錯誤', res.message, false);
                            }
                        }, 'json');
                    });

                    // --- 機台管理功能 ---
                    let machineSettingsChanged = false;

                    $('#btnOpenMachineSettings').click(function() {
                        machineSettingsChanged = false;
                        loadMachineSettings();
                        $('#machineSettingModal').modal('show');
                    });

                    <?php if ($can_manage_machine): ?>
                    if (new URLSearchParams(window.location.search).get('auto_open_machine') === '1') {
                        $('#btnOpenMachineSettings').trigger('click');
                    }
                    <?php endif; ?>

                    function loadMachineSettings() {
                        $.post('process_schedule_NOW.php', {
                            action: 'get_machine_settings'
                        }, function(res) {
                            if (res.success) {
                                // 1. 填入類型下拉選單 (編輯表單 + 齒輪設定)
                                var $typeSelect = $('#setting_machine_type');
                                $typeSelect.empty().append('<option value="">-- 請選擇 --</option>');
                                res.types.forEach(function(t) {
                                    $typeSelect.append(`<option value="${t.machine_type_id}">${t.machine_type}</option>`);
                                });

                                // 2. 填入機台列表
                                var $tbody = $('#machineListTable tbody');
                                $tbody.empty();

                                // 3. 填入齒輪顯示設定 Checkbox
                                var $gearContainer = $('#gear-settings-checkboxes');
                                $gearContainer.empty();
                                var savedGearSettings = res.gear_settings || [];

                                res.types.forEach(function(t) {
                                    var checked = savedGearSettings.includes(t.machine_type_id) || savedGearSettings.includes(String(t.machine_type_id)) ? 'checked' : '';
                                    $gearContainer.append(`<label class="checkbox-inline" style="margin-left: 0; margin-right: 10px;"><input type="checkbox" class="gear-setting-cb" value="${t.machine_type_id}" ${checked}> ${t.machine_type}</label>`);
                                });

                                if (res.machines && res.machines.length > 0) {
                                    res.machines.forEach(function(m) {
                                        var setupText = (m.need_setup == 1) ? '<span class="text-success">是</span>' : '<span class="text-muted">否</span>';

                                        var $tr = $('<tr>');
                                        $tr.append($('<td>').text(m.machine));
                                        $tr.append($('<td>').text(m.asset_no || ''));
                                        $tr.append($('<td>').text(m.field_no || ''));
                                        $tr.append($('<td>').text(m.machine_model || ''));
                                        $tr.append($('<td>').text(m.type_name || ''));
                                        $tr.append($('<td>').text(m.position));
                                        $tr.append($('<td>').html(setupText));

                                        var $actions = $('<td>');
                                        if (userPerms.canEditMachine) {
                                            var $editBtn = $('<button class="btn btn-xs btn-info btn-edit-machine"><i class="fa fa-pencil"></i></button>');
                                            $editBtn.data('json', m); // 使用 data() 儲存物件，避免引號問題
                                            $actions.append($editBtn).append(' ');
                                        }
                                        if (userPerms.canDeleteMachine) {
                                            var $delBtn = $('<button class="btn btn-xs btn-danger btn-delete-machine"><i class="fa fa-trash"></i></button>');
                                            $delBtn.data('id', m.machine_id);
                                            $actions.append($delBtn);
                                        }
                                        $tr.append($actions);
                                        $tbody.append($tr);
                                    });
                                } else {
                                    $tbody.html('<tr><td colspan="8" class="text-center text-muted">無機台資料</td></tr>');
                                }

                                // 重置表單
                                resetMachineForm();
                            } else {
                                alert(res.message);
                            }
                        }, 'json');
                    }

                    function resetMachineForm() {
                        $('#setting_machine_id').val('');
                        $('#setting_machine_name').val('');
                        $('#setting_machine_type').val('');
                        $('#setting_position').val('');
                        $('#setting_need_setup').val('1');
                        $('#setting_machine_model').val('');
                        $('#setting_asset_no').val('');
                        $('#setting_field_no').val('');
                        $('#setting_spec').val('');
                        $('#setting_note').val('');
                        $('#machineFormTitle').text('新增機台');

                        // 權限控制按鈕
                        $('#btnSaveMachine').prop('disabled', !userPerms.canAddMachine);
                    }

                    $('#btnResetMachineForm').click(resetMachineForm);

                    // 點擊編輯
                    $(document).on('click', '.btn-edit-machine', function() {
                        var m = $(this).data('json');
                        $('#setting_machine_id').val(m.machine_id);
                        $('#setting_machine_name').val(m.machine);
                        $('#setting_machine_type').val(m.machine_type_id);
                        $('#setting_position').val(m.position);
                        $('#setting_need_setup').val(m.need_setup);
                        $('#setting_machine_model').val(m.machine_model || '');
                        $('#setting_asset_no').val(m.asset_no || '');
                        $('#setting_field_no').val(m.field_no || '');
                        $('#setting_spec').val(m.spec || '');
                        $('#setting_note').val(m.note || '');
                        $('#machineFormTitle').text('編輯機台');

                        $('#btnSaveMachine').prop('disabled', !userPerms.canEditMachine);
                    });

                    // 儲存機台
                    $('#btnSaveMachine').click(function() {
                        var formData = $('#machineSettingForm').serialize();
                        $.post('process_schedule_NOW.php', formData, function(res) {
                            if (res.success) {
                                showToast('成功', res.message, true);
                                loadMachineSettings(); // 重新載入列表
                                machineSettingsChanged = true;
                            } else {
                                alert(res.message);
                            }
                        }, 'json');
                    });

                    // 儲存齒輪顯示設定
                    $('#btnSaveGearSettings').click(function() {
                        var selectedTypes = [];
                        $('.gear-setting-cb:checked').each(function() {
                            selectedTypes.push($(this).val());
                        });

                        $.post('process_schedule_NOW.php', {
                            action: 'save_gear_display_settings',
                            settings: selectedTypes
                        }, function(res) {
                            if (res.success) {
                                showToast('成功', res.message, true);
                            } else {
                                alert(res.message);
                            }
                        }, 'json');
                    });

                    // 刪除機台
                    $(document).on('click', '.btn-delete-machine', function() {
                        if (!confirm('確定要刪除此機台嗎？(將標記為不存在)')) return;
                        var id = $(this).data('id');
                        $.post('process_schedule_NOW.php', {
                            action: 'delete_machine',
                            machine_id: id
                        }, function(res) {
                            if (res.success) {
                                showToast('成功', res.message, true);
                                loadMachineSettings();
                                machineSettingsChanged = true;
                            } else {
                                alert(res.message);
                            }
                        }, 'json');
                    });

                    $('#machineSettingModal').on('hidden.bs.modal', function() {
                        if (machineSettingsChanged) {
                            location.reload();
                        }
                    });

                    // 雙擊清除搜尋內容
                    searchInput.addEventListener('dblclick', function() {
                        if (this.value) {
                            this.value = '';
                            this.dispatchEvent(new Event('input')); // 觸發 input 事件以重置搜尋結果
                        }
                    });

                    // --- NG 紀錄動態增減 ---
                    $('#addNgRowBtn').click(function() {
                        var html = `
                <tr>
                    <td>
                        <select class="form-control input-sm" name="ng_id[]">
                            <option value="">-- 選擇原因 --</option>
                            <?php foreach ($ng_list as $ng): ?>
                                <option value="<?= $ng['ng_id'] ?>"><?= htmlspecialchars($ng['ng_txt']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td><input type="number" class="form-control input-sm" name="ng_qty[]" value="0"></td>
                    <td><input type="text" class="form-control input-sm" name="ng_remark[]"></td>
                    <td><button type="button" class="btn btn-xs btn-default" onclick="$(this).closest('tr').remove()"><i class="fa fa-trash"></i></button></td>
                </tr>
            `;
                        $('#ngTable tbody').append(html);
                    });

                    // Modal 關閉時清空表單
                    $('#quickReportModal').on('hidden.bs.modal', function() {
                        $('#quickReportForm')[0].reset();
                        $('#ngTable tbody').empty();
                        // 重置隱藏欄位
                        $('#modal_bom_ing_fid').val('');
                        $('#modal_report_id').val('');
                        $('#modal_process_no').val('');
                        $('input[name="report_source"]').prop('disabled', false); // 解除鎖定
                        $('input[name="report_source"]').parent().show(); // 恢復顯示
                        $('input[name="report_source"][value="NORMAL"]').prop('checked', true).trigger('change'); // 重置為 NORMAL
                        $('#source_reason').val('');
                        $('#saveQuickReportBtn').text('儲存變更').removeClass('btn-warning').addClass('btn-primary');
                        $('#modal_produced_qty').removeData('original-ok').removeData('original-ng');

                        // 恢復啟用狀態 (避免影響下次開啟)
                        if (userPerms.canReport) {
                            $('#quickReportForm input, #quickReportForm select, #quickReportForm textarea').prop('disabled', false);
                            $('#addNgRowBtn').show();
                            // 恢復顯示可能被隱藏的欄位
                            $('.user-select-group, .time-input-group, #stats-container, #production-input-container, #ng-table-container, #div_1_side, #production-input-container').show();
                            $('textarea[name="pti01_ps"]').closest('.form-group').show();
                        }
                    });


                    // --- Custom Notification Logic ---
                    const toastEl = document.getElementById('custom-toast');
                    let toastTimeout;

                    // --- 製程分類設定 Modal ---
                    $('#processCategorySettingModal').on('show.bs.modal', function() {
                        var container = $('#process-settings-container');
                        container.html('<p class="text-center"><i class="fa fa-spinner fa-spin"></i> 載入中...</p>');

                        var allProcessTypes = <?= json_encode($all_machine_types) ?>;
                        var currentSettings = window.uiSettings;

                        var tableHtml = '<table class="table table-bordered table-striped"><thead><tr><th>製程分類</th><th>加工面/此面完工</th><th>已到料</th></tr></thead><tbody>';

                        allProcessTypes.forEach(function(type) {
                            var typeId = type.machine_type_id;
                            var faceChecked = currentSettings.show_face_options.includes(String(typeId)) ? 'checked' : '';
                            var materialChecked = currentSettings.show_material_arrived.includes(String(typeId)) ? 'checked' : '';

                            tableHtml += `
                    <tr>
                        <td>${type.machine_type} (${typeId})</td>
                        <td class="text-center"><input type="checkbox" class="js-setting-cb" data-setting="show_face_options" value="${typeId}" ${faceChecked}></td>
                        <td class="text-center"><input type="checkbox" class="js-setting-cb" data-setting="show_material_arrived" value="${typeId}" ${materialChecked}></td>
                    </tr>
                `;
                        });

                        tableHtml += '</tbody></table>';
                        container.html(tableHtml);
                    });

                    $('#saveProcessCategorySettingsBtn').click(function() {
                        var newSettings = {
                            show_face_options: [],
                            show_material_arrived: []
                        };

                        $('#process-settings-container .js-setting-cb:checked').each(function() {
                            var settingName = $(this).data('setting');
                            var typeId = $(this).val();
                            if (newSettings[settingName]) {
                                newSettings[settingName].push(typeId);
                            }
                        });

                        var $btn = $(this);
                        $btn.prop('disabled', true).text('儲存中...');

                        $.post('process_schedule_NOW.php', {
                            action: 'save_process_category_settings',
                            settings: JSON.stringify(newSettings)
                        }, function(res) {
                            if (res.success) {
                                showToast('成功', res.message, true);
                                window.uiSettings = newSettings;
                                $('#processCategorySettingModal').modal('hide');
                            } else {
                                alert('儲存失敗: ' + res.message);
                            }
                        }, 'json').always(function() {
                            $btn.prop('disabled', false).text('儲存設定');
                        });
                    });

                    function showToast(title, message, isSuccess) {
                        toastEl.innerHTML = `<strong>${title}</strong><br>${message}`;
                        toastEl.style.display = 'block';
                        toastEl.style.backgroundColor = isSuccess ? '#26B99A' : '#d9534f'; // Green or Red

                        if (toastTimeout) clearTimeout(toastTimeout);
                        toastTimeout = setTimeout(() => {
                            toastEl.style.display = 'none';
                        }, 3000);
                    }

                    // --- 全局顯示/隱藏待辦事項 ---
                    let isAllWaitingShown = false; // 追蹤全域顯示狀態
                    $('#toggle-all-waiting-btn').on('click', function() {
                        var $btn = $(this);
                        var $columns = $('.kanban-board .kanban-column');

                        // Toggle the view for all columns at once
                        $columns.toggleClass('show-waiting');
                        isAllWaitingShown = !isAllWaitingShown; // 切換狀態

                        // Check the new state and update the button
                        // 修正：應該檢查 isAllWaitingShown 而不是 class，因為 class 可能會被單獨操作
                        if (isAllWaitingShown) {
                            $btn.html('<i class="fa fa-compress"></i> 隱藏所有待辦');
                            $btn.removeClass('btn-info').addClass('btn-primary');
                        } else {
                            $btn.html('<i class="fa fa-list-ul"></i> 顯示所有待辦');
                            $btn.removeClass('btn-primary').addClass('btn-info');
                        }
                    });

                    // --- 切換列表模式按鈕 ---
                    $('#toggle-view-btn').click(function() {
                        var $btn = $(this);
                        $('body').toggleClass('list-view-mode');

                        if ($('body').hasClass('list-view-mode')) {
                            $btn.removeClass('btn-default').addClass('btn-success active');
                        } else {
                            $btn.removeClass('btn-success active').addClass('btn-default');
                        }
                    });

                    // 全域變數：記錄是否正在拖曳
                    let isDragging = false;

                    // --- SortableJS Initialization ---
                    kanbanColumns.forEach(column => {
                        const isUnassigned = column.classList.contains('unassigned-list');
                        new Sortable(column, {
                            group: 'kanban', // set both lists to same group
                            disabled: !userPerms.canDrag, // 權限控制：若無 U 或 A 權限則禁用拖曳
                            sort: isUnassigned, // 修正：未指派可排序，機台不可直接排序 (需透過視窗)
                            animation: 150,
                            ghostClass: 'sortable-ghost',
                            dragClass: 'sortable-drag',
                            onStart: function(evt) {
                                isDragging = true;
                                // 拖曳開始時，為 body 添加 class 以便處理游標事件穿透 (如果需要)
                                document.body.classList.add('is-dragging-card');
                            },
                            onEnd: function(evt) {
                                // evt.to: the list where the item was dropped
                                // evt.from: the list from which the item was dragged

                                const targetColumn = evt.to;
                                const isTargetUnassigned = targetColumn.classList.contains('unassigned-list');

                                // 根據權限決定行為
                                if (window.canReorder) {
                                    // A 或 C 權限：執行完整排序更新
                                    if (evt.from !== evt.to || !isTargetUnassigned) {
                                        targetColumn.appendChild(evt.item);
                                    }
                                    updateSchedule(targetColumn);
                                } else {
                                    // R+U 權限：只更新指派，不更新順序
                                    if (evt.from !== evt.to) { // 只有跨欄位拖曳才觸發
                                        updateAssignmentOnly(evt.item, targetColumn);
                                        // 手動將項目移到新欄位的最後
                                        targetColumn.appendChild(evt.item);
                                    } else {
                                        // 同欄位內拖曳，取消操作
                                        evt.from.insertBefore(evt.item, evt.from.children[evt.oldIndex]);
                                    }
                                }

                                if (evt.from !== evt.to) {
                                    updateSchedule(evt.from);
                                }

                                // 更新欄位數量統計
                                updateColumnCounts(evt.to);
                                if (evt.from !== evt.to) updateColumnCounts(evt.from);

                                // 更新卡片視覺狀態 (加工中/待加工)
                                updateColumnVisuals(targetColumn);
                                if (evt.from !== evt.to) {
                                    updateColumnVisuals(evt.from);
                                }

                                // 更新順序編號
                                updateCardSequenceBadges(targetColumn);
                                if (evt.from !== evt.to) {
                                    updateCardSequenceBadges(evt.from);
                                }

                                // 拖曳結束，重置狀態
                                isDragging = false;
                                document.body.classList.remove('is-dragging-card');
                            }
                        });
                    });

                    // 新增：僅更新機台指派的函式 (for R+U)
                    function updateAssignmentOnly(itemElement, columnElement) {
                        const machineId = columnElement.dataset.machineId;
                        const bomIngFid = itemElement.dataset.id;

                        $.post('process_schedule_NOW.php', {
                            action: 'update_assignment_only',
                            machine_id: machineId,
                            bom_ing_fid: bomIngFid
                        }, function(res) {
                            if (res.success) {
                                showToast('成功', '機台指派已更新', true);
                                // 將卡片上的順序編號清除，因為它現在是未排序狀態
                                $(itemElement).find('.badge.bg-purple').text('');
                                // 更新 data-attribute
                                $(itemElement).data('processing-sequence', '');
                            } else {
                                showToast('錯誤', res.message, false);
                                // 這裡可以加入復原拖曳的邏輯
                            }
                        }, 'json');
                    }
                    // --- 拆分製程功能 ---
                    window.splitTask = function(fid) {
                        if (!confirm('確定要拆分此製程嗎？\n這將建立一個副本(標記為拆分工單)，以便指派到其他機台進行多面加工。')) return;

                        $.post('process_schedule_NOW.php', {
                            action: 'split_task',
                            bom_ing_fid: fid
                        }, function(res) {
                            if (res.success) {
                                showToast('成功', res.message, true);
                                setTimeout(function() {
                                    location.reload();
                                }, 1000);
                            } else {
                                alert(res.message);
                            }
                        }, 'json');
                    };

                    // --- 刪除拆分工單功能 ---
                    window.deleteSplitTask = function(fid) {
                        if (!confirm('確定要刪除此拆分工單嗎？\n注意：此操作無法復原。')) return;

                        $.post('process_schedule_NOW.php', {
                            action: 'delete_split_task',
                            bom_ing_fid: fid
                        }, function(res) {
                            if (res.success) {
                                showToast('成功', res.message, true);
                                setTimeout(function() {
                                    location.reload();
                                }, 1000);
                            } else {
                                alert(res.message);
                            }
                        }, 'json');
                    };

                    // --- 機台異常通報按鈕點擊事件 ---
                    $(document).on('click', '.btn-abnormal-report', function(e) {
                        e.stopPropagation(); // 防止觸發標頭的排序視窗
                        var $header = $(this).closest('.kanban-column-header');
                        var machineId = $header.data('machine-id');
                        var machineName = $header.data('machine-name');

                        if (!machineId) return;

                        $('#abnormal_machine_id').val(machineId);
                        $('#abnormal_machine_name').text(machineName);

                        resetAbnormalForm();
                        loadMachineAbnormalInfo(machineId);
                        if (!userPerms.canReport) {
                            $('#machineAbnormalForm input, #machineAbnormalForm select, #machineAbnormalForm textarea').prop('disabled', true);
                        }
                        $('#machineAbnormalModal').modal('show');
                    });

                    // 更新欄位上的數量 Badge
                    function updateColumnCounts(columnList) {
                        if (!columnList) return;
                        var colContainer = columnList.closest('.kanban-column');
                        if (colContainer) {
                            var badge = colContainer.querySelector('.kanban-column-header .badge');
                            var count = columnList.querySelectorAll('.kanban-card:not(.sortable-ghost)').length;
                            if (badge) badge.textContent = count;
                        }
                        if (columnList.classList.contains('unassigned-list')) {
                            var count = columnList.querySelectorAll('.kanban-card:not(.sortable-ghost)').length;
                            var uBadge = document.getElementById('unassigned-count-badge');
                            if (uBadge) uBadge.textContent = count;
                        }
                    }

                    // 更新欄位內卡片的視覺樣式 (第一筆為加工中，其餘為待加工)
                    function updateColumnVisuals(column) {
                        var colId = $(column).attr('id');
                        var isUnassigned = colId && colId.indexOf('unassigned') !== -1;

                        $(column).find('.kanban-card').each(function(index) {
                            var $card = $(this);
                            var state = $card.data('state');
                            var $badge = $card.find('.card-state-badge');

                            // 取得加工中專有的元素 (進度條容器、統計列、分隔線)
                            // 結構: <hr> -> <div><div class="progress">...</div></div> -> <div class="row">...</div>
                            var $progContainer = $card.find('.progress').parent();
                            var $statsRow = $card.find('.row');
                            var $sep = $progContainer.prev('hr');

                            if (isUnassigned) {
                                // 未指派區域：恢復預設狀態
                                $card.removeClass('now-processing waiting-task');
                                // 需求：移回未指派時，狀態顯示「待指派」
                                $badge.html('<span class="label label-info">待指派</span>');

                                $progContainer.hide();
                                $statsRow.hide();
                                $sep.hide();
                            } else {
                                // 機台區域
                                if (index === 0) {
                                    // 第一筆：視為加工中
                                    $card.removeClass('waiting-task').addClass('now-processing');
                                    if (state === 'ing') $badge.html('<span class="label label-success">加工中</span>');
                                    $progContainer.show();
                                    $statsRow.show();
                                    $sep.show();
                                } else {
                                    // 其他：視為待加工
                                    $card.removeClass('now-processing').addClass('waiting-task');
                                    if (state === 'ing') $badge.html('<span class="label label-default">待加工</span>');
                                    $progContainer.hide();
                                    $statsRow.hide();
                                    $sep.hide();
                                }
                            }
                        });
                    }

                    // 更新卡片上的順序編號
                    function updateCardSequenceBadges(column) {
                        $(column).find('.kanban-card').each(function(index) {
                            var seq = index + 1;
                            var $badges = $(this).find('.card-badges');
                            var $badge = $badges.find('.badge.bg-purple');

                            if ($badge.length === 0) {
                                $badge = $('<span class="badge bg-purple" style="font-size: 10px; margin-left: 5px;"></span>');
                                $badges.append($badge);
                            }
                            $badge.text(seq);
                        });
                    }

                    function updateSchedule(columnElement) {
                        const machineId = columnElement.dataset.machineId;
                        const sortableInstance = Sortable.get(columnElement);
                        const order = sortableInstance.toArray();

                        // Prepare data for sending
                        const formData = new FormData();
                        formData.append('machine_id', machineId);
                        order.forEach((item, index) => {
                            formData.append(`order[${index}]`, item);
                        });

                        // Send the new order to the server via AJAX
                        fetch('process_schedule_NOW.php', {
                                method: 'POST',
                                body: formData
                            })
                            .then(response => response.json())
                            .then(data => {
                                if (data.timeout) {
                                    alert(data.message);
                                    window.location.href = data.redirect || '../../index.php';
                                    return;
                                }
                                if (data.success) {
                                    console.log('Success:', data.message);
                                    showToast('更新成功', `機台 ${machineId} 的排程已儲存。`, true);
                                } else {
                                    console.error('Error:', data.message);
                                    showToast('更新失敗', `儲存機台 ${machineId} 的排程時發生錯誤。`, false);
                                    // Optional: Revert the changes visually if the save fails
                                    // This can be complex, so for now we just show an error.
                                }
                            })
                            .catch((error) => {
                                console.error('Fetch Error:', error);
                                showToast('網路錯誤', '無法連接到伺服器。', false);
                            });
                    }

                    // --- Dashboard Logic ---
                    $('#btn-dashboard-mode').click(function() {
                        var $btn = $(this);
                        var $kanban = $('.row:has(.kanban-column)');
                        var $dash = $('#dashboard-view');
                        if ($dash.is(':visible')) {
                            $dash.hide();
                            $kanban.show();
                            $btn.removeClass('btn-primary').addClass('btn-default');
                            stopDashboardRefresh();
                        } else {
                            $kanban.hide();
                            $dash.show();
                            $btn.removeClass('btn-default').addClass('btn-primary');
                            fetchDashboardData();
                            startDashboardRefresh();
                        }
                    });
                    $('.refresh-rate-opt').click(function(e) {
                        e.preventDefault();
                        $('#dashboard-refresh-rate').val($(this).data('val'));
                        if ($('#dashboard-view').is(':visible')) startDashboardRefresh();
                    });
                    var dashboardTimer;

                    function startDashboardRefresh() {
                        stopDashboardRefresh();
                        var interval = parseInt($('#dashboard-refresh-rate').val()) * 1000;
                        dashboardTimer = setInterval(fetchDashboardData, interval);
                    }

                    function stopDashboardRefresh() {
                        if (dashboardTimer) clearInterval(dashboardTimer);
                    }

                    function fetchDashboardData() {
                        $.post('process_schedule_NOW.php', {
                            action: 'get_dashboard_data'
                        }, function(res) {
                            if (res.success) renderDashboard(res.data, res.waiting, res.gear_settings);
                        }, 'json');
                    }

                    function renderDashboard(data, waitingData, gearSettings) {
                        var html = '';
                        var positions = Object.keys(data).sort();
                        positions.forEach(function(pos) {
                            // 計算稼動率
                            var totalM = 0;
                            var activeM = 0;
                            var types = data[pos];
                            for (var t in types) {
                                types[t].forEach(function(m) {
                                    totalM++;
                                    if (m.status === 'green' || m.status === 'blue') activeM++;
                                });
                            }
                            var utilRate = totalM > 0 ? Math.round((activeM / totalM) * 100) : 0;

                            html += `<div class="dashboard-factory-container"><div class="dashboard-factory-header">${pos} 廠 <small class="pull-right" style="color:white; font-size:12px; margin-top:2px;">稼動率: ${utilRate}%</small></div><div class="dashboard-factory-content">`;
                            var types = data[pos];
                            for (var type in types) {
                                // 待加工資訊
                                var waitInfo = (waitingData && waitingData[type]) ? waitingData[type] : {
                                    cnt: 0,
                                    list: ''
                                };
                                var waitBadge = waitInfo.cnt > 0 ? `<span class="badge bg-orange pull-right" style="cursor:help;" data-toggle="popover" data-trigger="hover" data-placement="top" data-html="true" data-content="${waitInfo.list}" title="待加工清單 (${waitInfo.cnt})">${waitInfo.cnt}</span>` : '';

                                html += `<div class="dashboard-type-group"><div class="dashboard-type-header">${type} ${waitBadge}</div><div class="dashboard-machines-flex">`;
                                types[type].forEach(function(m) {
                                    var progressHtml = '';
                                    if (m.progress) {
                                        progressHtml = `
                                <div class="progress" style="height: 4px; margin-bottom: 2px; margin-top: 2px; background-color: rgba(255,255,255,0.3);">
                                    <div class="progress-bar progress-bar-warning" role="progressbar" style="width: ${m.progress.percent}%; background-color: #f0ad4e;"></div>
                                </div>
                                <div style="font-size:9px; text-align:right; line-height:1;">${m.progress.ok} / ${m.progress.total}</div>
                            `;
                                    }
                                    // 將詳細資料存入 data attribute
                                    var detailJson = m.full_data ? JSON.stringify(m.full_data).replace(/"/g, '&quot;') : '';
                                    var safeFullName = m.full_name.replace(/"/g, '&quot;');

                                    // 檢查是否顯示齒輪規格 (Module)
                                    var gearInfoHtml = '';
                                    if (gearSettings && (gearSettings.includes(m.machine_type_id) || gearSettings.includes(String(m.machine_type_id))) && m.full_data && m.full_data.gear_info && m.full_data.gear_info.length > 0) {
                                        var modVal = m.full_data.gear_info[0].Module;
                                        var modClean = String(modVal).replace(/[^0-9.]/g, '');
                                        var mod = (modClean && !isNaN(parseFloat(modClean))) ? parseFloat(modClean) : '';
                                        if (mod !== '') {
                                            gearInfoHtml = `<div style="position: absolute; top: 2px; right: 2px; background: #337ab7; color: white; padding: 1px 4px; border-radius: 3px; font-size: 10px;">M${mod}</div>`;
                                        }
                                    }

                                    html += `<div class="dashboard-machine-card bg-${m.status}" title="${m.name}" data-full-name="${safeFullName}" data-status="${m.status}" data-detail="${detailJson}"><div class="dashboard-machine-name">${m.name}</div><div class="dashboard-machine-info"><div>${m.info}</div><div style="font-size:10px;">${m.detail || ''}</div>${gearInfoHtml}${progressHtml}</div></div>`;
                                });
                                html += `</div></div>`;
                            }
                            html += `</div></div>`;
                        });
                        $('#dashboard-view').html(html);
                        // 初始化 Popover
                        $('#dashboard-view [data-toggle="popover"]').popover({
                            html: true,
                            container: 'body',
                            trigger: 'hover',
                            placement: 'auto',
                            template: '<div class="popover wide-popover" role="tooltip"><div class="arrow"></div><h3 class="popover-title"></h3><div class="popover-content"></div></div>'
                        });
                    }

                    // 全域函數供使用
                    window.showDashDetail = function(machineName, status, detailJson) {
                        $('#dashDetailTitle').text(machineName);
                        var content = '';
                        if (status === 'red') {
                            var d = (detailJson && typeof detailJson === 'string') ? JSON.parse(detailJson) : detailJson;
                            if (d && d.is_abnormal) {
                                content = '<div class="alert alert-danger"><strong>機台異常中</strong><br>' +
                                    '類型: ' + (d.abnormal_name || '-') + '<br>' +
                                    '發生日期: ' + (d.start_time || '-') + '<br>' +
                                    '說明/進度: <pre style="background:none; border:none; padding:0; color:#333; white-space:pre-wrap;">' + (d.description || '無說明') + '</pre></div>';
                            } else {
                                content = '<div class="alert alert-danger">機台異常中</div>';
                            }
                        } else if (status === 'yellow') content = '<div class="alert alert-warning">機台停機中</div>';
                        else if (detailJson) {
                            var d = (typeof detailJson === 'string') ? JSON.parse(detailJson) : detailJson;

                            var startTime = d.start_time ? d.start_time.substring(5, 16) : '-'; // mm-dd HH:MM

                            var formatGearValue = function(val) {
                                if (!val) return '-';
                                return String(val).replace(/\.00$/, '');
                            };

                            content = `
                    <table class="table table-bordered table-condensed" style="margin-bottom:10px;">
                        <tr><th width="30%" class="active">狀態</th><td>${d.status_text}</td></tr>
                        <tr><th class="active">BOM</th><td>${d.bom}</td></tr>
                        <tr><th class="active">料號</th><td>${d.d_id}</td></tr>
                        <tr><th class="active">客戶</th><td>${d.client}</td></tr>
                        <tr><th class="active">製程</th><td>${d.process}</td></tr>
                        <tr><th class="active">進度</th><td>${d.ok} / ${d.sqty}</td></tr>
                        <tr><th class="active">開始</th><td>${startTime}</td></tr>
                    </table>
                `;

                            if (d.gear_info && d.gear_info.length > 0) {
                                content += '<div style="border-top:1px solid #eee; margin-top:10px; padding-top:5px;"><strong>齒輪規格</strong></div>';
                                d.gear_info.forEach(function(g) {
                                    content += `
                            <table class="table table-bordered table-condensed" style="font-size:12px; margin-bottom:5px; background:#f9f9f9;">
                                <tr><th width="30%">模數</th><td>${formatGearValue(g.Module)}</td></tr>
                                <tr><th>齒數</th><td>${formatGearValue(g.Teeth)}</td></tr>
                                <tr><th>壓力角</th><td>${formatGearValue(g.Pressure_Angle)}</td></tr>
                                <tr><th>螺旋角</th><td>${formatGearValue(g.Helix_Angle)}</td></tr>
                                <tr><th>齒寬</th><td>${formatGearValue(g.Face_Width)}</td></tr>
                                <tr><th>長度</th><td>${formatGearValue(g.Workpiece_Length)}</td></tr>
                                ${g.Remark_Gear ? `<tr><th>備註</th><td>${g.Remark_Gear}</td></tr>` : ''}
                            </table>
                        `;
                                });
                            }
                        } else {
                            content = '<p>無詳細資料</p>';
                        }
                        $('#dashDetailContent').html(content);
                        $('#dashboardDetailModal').modal('show');
                    };

                    // 綁定資料塊點擊事件
                    $(document).on('click', '.dashboard-machine-card', function() {
                        var name = $(this).data('full-name');
                        var status = $(this).data('status');
                        var detail = $(this).data('detail');
                        showDashDetail(name, status, detail);
                    });

                    // 新增：從時程表開啟報工紀錄視窗
                    window.openReportForEditing = function(reportId) {
                        if (!reportId) return;
                        if (!userPerms.canReport && !userPerms.hasA) {
                            showToast('提示', '您沒有編輯報工紀錄的權限。', false);
                            return;
                        }

                        $.post('process_schedule_NOW.php', {
                            action: 'get_report_detail',
                            report_id: reportId
                        }, function(res) {
                            if (res.success) {
                                var r = res.report;

                                // 重置表單
                                $('#quickReportForm')[0].reset();
                                $('#ngTable tbody').empty();
                                $('input[name="report_source"]').prop('disabled', false).parent().show();
                                $('input[name="report_source"][value="NORMAL"]').prop('checked', true).trigger('change');
                                $('#saveQuickReportBtn').text('儲存變更').removeClass('btn-warning btn-info').addClass('btn-primary').show();
                                $('#modal_produced_qty').removeData('original-ok').removeData('original-ng');

                                // 填入資料
                                $('#modal_report_id').val(r.report_id);
                                $('#modal_bom_ing_fid').val(r.bom_ing_fid);
                                $('#modal_process_no').val(r.process_no);

                                $('#modal_client_name').val(r.Client_Name);
                                $('#modal_part_no').val(r.d_id);

                                var sqty = parseInt(r.sqty) || 0;

                                // 統計數據需要從卡片或重新查詢，這裡先用報工紀錄的數量
                                $('#modal_stats_total').text(sqty);
                                $('#modal_stats_ok').text('?');
                                $('#modal_stats_ng').text('?');
                                $('#modal_stats_remaining').text('?');

                                $('#modal_bom_info').val(r.bom + ' - ' + (r.ProcessName || r.process_no) + ' (數量: ' + sqty + ')');

                                $('input[name="report_date"]').val(r.report_date);

                                // 機台選單
                                var $machineSelect = $('#modal_machine_id');
                                $machineSelect.empty().append('<option value="">請選擇機台</option>');
                                $('#hidden_machine_id').remove();
                                allMachines.forEach(function(m) {
                                    $machineSelect.append(`<option value="${m.machine_id}" data-need-setup="${m.need_setup}">${machineOptionLabel(m)}</option>`);
                                });
                                if ($('#modal_machine_id option[value="' + r.machine_id + '"]').length > 0) {
                                    $machineSelect.val(r.machine_id);
                                }
                                if (userPerms.canReport) {
                                    $machineSelect.prop('disabled', false);
                                }
                                checkMachineSetupNeeded();

                                $('#modal_setup_user_id').val(r.setup_user_id).trigger('change');
                                $('#modal_production_user_id').val(r.production_user_id).trigger('change');

                                var fmt = function(t) {
                                    return t ? t.substring(0, 16) : '';
                                };
                                $('#modal_setup_start_time').val(fmt(r.setup_start_time));
                                $('#modal_setup_end_time').val(fmt(r.setup_end_time));
                                $('#modal_production_start_time').val(fmt(r.production_start_time));
                                $('#modal_production_end_time').val(fmt(r.production_end_time));

                                $('#modal_produced_qty').val(r.produced_qty);
                                $('textarea[name="remark"]').val(r.remark);
                                $('input[name="is_finished"]').prop('checked', r.is_finished == 1);
                                $('input[name="is_face_finished"]').prop('checked', r.is_finished == 2);

                                var source = r.report_source || 'NORMAL';
                                $('input[name="report_source"][value="' + source + '"]').prop('disabled', true);
                                $('input[name="report_source"][value="' + source + '"]').prop('checked', true).trigger('change');
                                if (r.source_reason) $('#source_reason').val(r.source_reason);

                                if (r.process_face) {
                                    $('select[name="process_face"]').val(r.process_face);
                                } else {
                                    $('select[name="process_face"]').val('');
                                }

                                var originalNgTotal = 0;
                                if (res.ng_list && res.ng_list.length > 0) {
                                    res.ng_list.forEach(function(ngItem) {
                                        originalNgTotal += (parseInt(ngItem.ng_qty) || 0);
                                        var optionsHtml = '<option value="">-- 選擇原因 --</option>';
                                        ngOptionsList.forEach(function(opt) {
                                            var selected = (opt.ng_id == ngItem.ng_id) ? 'selected' : '';
                                            optionsHtml += `<option value="${opt.ng_id}" ${selected}>${opt.ng_txt}</option>`;
                                        });

                                        var rowHtml = `<tr><td><select class="form-control input-sm" name="ng_id[]">${optionsHtml}</select></td><td><input type="number" class="form-control input-sm" name="ng_qty[]" value="${ngItem.ng_qty}"></td><td><input type="text" class="form-control input-sm" name="ng_remark[]" value="${ngItem.ng_remark || ''}"></td><td>${userPerms.canReport ? '<button type="button" class="btn btn-xs btn-default" onclick="$(this).closest(\'tr\').remove()"><i class="fa fa-trash"></i></button>' : ''}</td></tr>`;
                                        $('#ngTable tbody').append(rowHtml);
                                    });
                                }

                                $('#modal_produced_qty').data('original-ok', r.produced_qty);
                                $('#modal_produced_qty').data('original-ng', originalNgTotal);

                                if (!userPerms.canReport) {
                                    $('#quickReportForm input, #quickReportForm select, #quickReportForm textarea').prop('disabled', true);
                                }

                                $('#saveQuickReportBtn').text('更新報工資料').removeClass('btn-primary').addClass('btn-warning');
                                $('#quickReportModal').modal('show');
                            } else {
                                alert('無法載入報工資料: ' + res.message);
                            }
                        }, 'json');
                    }

                    // --- 機台時程表功能 ---
                    $('#btnMachineSchedule').click(function() {
                        $('#machineScheduleModal').modal('show');
                        loadMachineSchedule();
                    });

                    $('#btnRefreshSchedule').click(function() {
                        loadMachineSchedule();
                    });

                    // 日期前後一天切換
                    $('#btnPrevDay').click(function() {
                        var date = new Date($('#schedule_date').val());
                        date.setDate(date.getDate() - 1);
                        var y = date.getFullYear();
                        var m = ('0' + (date.getMonth() + 1)).slice(-2);
                        var d = ('0' + date.getDate()).slice(-2);
                        $('#schedule_date').val(y + '-' + m + '-' + d);
                        loadMachineSchedule();
                    });
                    $('#btnNextDay').click(function() {
                        var date = new Date($('#schedule_date').val());
                        date.setDate(date.getDate() + 1);
                        var y = date.getFullYear();
                        var m = ('0' + (date.getMonth() + 1)).slice(-2);
                        var d = ('0' + date.getDate()).slice(-2);
                        $('#schedule_date').val(y + '-' + m + '-' + d);
                        loadMachineSchedule();
                    });

                    function loadMachineSchedule() {
                        var date = $('#schedule_date').val();
                        var typeId = $('#schedule_machine_type').val();
                        var $container = $('#schedule-container');
                        $container.html('<div class="text-center" style="padding:20px;"><i class="fa fa-spinner fa-spin"></i> 載入中...</div>');

                        $.post('process_schedule_NOW.php', {
                            action: 'get_machine_daily_schedule',
                            date: date,
                            machine_type_id: typeId
                        }, function(res) {
                            if (res.success) {
                                renderSchedule(res.machines, res.reports, date);
                            } else {
                                $container.html('<div class="alert alert-danger">載入失敗: ' + res.message + '</div>');
                            }
                        }, 'json');
                    }

                    function renderSchedule(machines, reports, dateStr) {
                        var $container = $('#schedule-container');
                        $container.empty();

                        // 1. Time Labels Column
                        // 1. Header
                        var headerHtml = '<div class="schedule-header">';
                        headerHtml += '<div class="header-machine-name">機台</div>';
                        headerHtml += '<div class="header-timeline">';
                        for (var h = 0; h < 24; h++) {
                            headerHtml += `<div class="header-hour">${('0'+h).slice(-2)}</div>`;
                        }
                        headerHtml += '</div></div>';
                        $container.append(headerHtml);

                        // 2. Machine Rows
                        machines.forEach(function(mach) {
                            var rowHtml = `<div class="machine-row">`;
                            var machDisp = machineOptionLabel(mach);
                            rowHtml += `<div class="machine-name" title="${machDisp}">${machDisp}</div>`;
                            rowHtml += `<div class="machine-timeline">`;

                            // Background Grid
                            rowHtml += `<div class="timeline-bg-grid">`;
                            for (var h = 0; h < 24; h++) {
                                rowHtml += `<div class="bg-hour"></div>`;
                            }
                            rowHtml += `</div>`; // End bg-grid

                            // Tasks
                            var machReports = reports.filter(r => r.machine_id == mach.machine_id);
                            var tasksHtml = '';

                            machReports.forEach(function(r) {
                                // Calculate position
                                var drawBlock = function(start, end, type) {
                                    if (!start || !end) return;
                                    var s = new Date(start);
                                    var e = new Date(end);

                                    var dayStart = new Date(dateStr + ' 00:00:00');
                                    var dayEnd = new Date(dateStr + ' 23:59:59');

                                    if (e < dayStart || s > dayEnd) return;
                                    if (s < dayStart) s = dayStart;
                                    if (e > dayEnd) e = dayEnd;

                                    var totalMinutes = 24 * 60;
                                    var startMinutes = (s.getHours() * 60) + s.getMinutes();
                                    var durationMinutes = (e - s) / 1000 / 60;

                                    var leftPct = (startMinutes / totalMinutes) * 100;
                                    var widthPct = (durationMinutes / totalMinutes) * 100;

                                    var title = `${r.bom} ${r.ProcessName || ''}`;
                                    var timeRange = `${start.substr(11,5)}~${end.substr(11,5)}`;
                                    var cls = type === 'setup' ? 'task-setup' : 'task-prod';

                                    // Tooltip content
                                    var tooltip = `${title}\n${timeRange}\nID:${r.report_id}`;


                                    // Block content
                                    var qty_info = '';
                                    if (type === 'prod') {
                                        var ok_qty = parseInt(r.produced_qty) || 0;
                                        var ng_qty = parseInt(r.total_ng) || 0;
                                        qty_info = ` | ${ok_qty}` + (ng_qty > 0 ? ` / <span style="color: #ffdddd;">${ng_qty}</span>` : '');
                                    }
                                    var block_content = `${r.bom}<br><small>${r.d_id}</small><br><small>${timeRange}${qty_info}</small>`;

                                    tasksHtml += `<div class="task-block ${cls}" style="left:${leftPct}%; width:${widthPct}%;" title="${tooltip}" ondblclick="openReportForEditing(${r.report_id})">
                            ${block_content}
                        </div>`;
                                };

                                drawBlock(r.setup_start_time, r.setup_end_time, 'setup');
                                drawBlock(r.production_start_time, r.production_end_time, 'prod');
                            });

                            rowHtml += tasksHtml;
                            rowHtml += `</div>`; // End machine-timeline
                            rowHtml += `</div>`; // End machine-row
                            $container.append(rowHtml);
                        });


                    }
                });

                // --- Clipboard Functions ---
                function copyToClipboard(text, element) {
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(text).then(function() {
                            showClipboardToast('已複製', text);
                        }).catch(function(err) {
                            fallbackCopyToClipboard(text);
                        });
                    } else {
                        fallbackCopyToClipboard(text);
                    }
                }

                function fallbackCopyToClipboard(text) {
                    var textArea = document.createElement("textarea");
                    textArea.value = text;
                    textArea.style.position = "fixed";
                    textArea.style.left = "-9999px";
                    document.body.appendChild(textArea);
                    textArea.focus();
                    textArea.select();
                    try {
                        var successful = document.execCommand('copy');
                        if (successful) {
                            showClipboardToast('已複製', text);
                        }
                    } catch (err) {
                        console.error('Copy failed', err);
                    }
                    document.body.removeChild(textArea);
                }

                function showClipboardToast(title, message) {
                    var toastEl = document.getElementById('custom-toast');
                    if (toastEl) {
                        toastEl.innerHTML = '<strong>' + title + '</strong><br>' + message;
                        toastEl.style.display = 'block';
                        toastEl.style.backgroundColor = '#26B99A';
                        setTimeout(function() {
                            toastEl.style.display = 'none';
                        }, 1000);
                    }
                }

                
                // 共用呼叫函式
function openSharedModal(title, url, postData = {}) {
    // 1. 設定標題
    $('#sharedModalTitle').text(title);
    
    // 2. 顯示 Loading 動畫並打開跳窗
    $('#sharedModalBody').html('<div class="text-center"><i class="fa fa-spinner fa-spin fa-3x"></i></div>');
    $('#sharedDynamicModal').modal('show');
    
    // 3. 使用 AJAX (post) 載入目標網頁內容
    $.post(url, postData, function(htmlContent) {
        $('#sharedModalBody').html(htmlContent);
    }).fail(function() {
        $('#sharedModalBody').html('<div class="alert alert-danger">載入失敗，請稍後再試。</div>');
    });
}

// 使用事件委派攔截跳窗內的表單提交
$(document).on('submit', '.dynamic-modal-form', function(e) {
    e.preventDefault(); // 防止頁面跳轉
    
    var $form = $(this);
    var submitter = e.originalEvent.submitter; // 取得被點擊的按鈕
    var $submitButton = $(submitter); // 將按鈕轉為 jQuery 物件
    var originalButtonText = $submitButton.html();

    // 防呆機制：禁用按鈕
    $submitButton.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> 處理中...');

    var formData = $form.serialize();
    // 手動將被點擊按鈕的 name 和 value 加入到要送出的資料中
    if (submitter && submitter.name) {
        formData += '&' + encodeURIComponent(submitter.name) + '=' + encodeURIComponent(submitter.value);
    }

    $.ajax({
        url: $form.attr('action'),
        type: 'POST',
        data: formData, // 使用包含按鈕資訊的表單資料
        dataType: 'json',
        success: function(response) {
            alert(response.message); // 顯示後端回傳的訊息
            if (response.success) {
                $('#sharedDynamicModal').modal('hide'); // 成功則關閉跳窗
                location.reload(); // 並重新整理頁面
            } else {
                // 若失敗，恢復按鈕狀態
                $submitButton.prop('disabled', false).html(originalButtonText);
            }
        },
        error: function() {
            alert('請求失敗，請檢查網路連線或聯繫管理員。');
            // 錯誤時恢復按鈕狀態
            $submitButton.prop('disabled', false).html(originalButtonText);
        },
        complete: function() {
            // 此處不再需要恢復按鈕，因為已在 success 和 error 中處理
        }
    });
});
            </script>
</body>

</html>