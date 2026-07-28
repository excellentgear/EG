<?php
// OreadyReply_ForPm_BaseOfTime2.php
$execution_start_time = microtime(true); // 開始計時
session_start();

// 記憶體上限：256M 已足夠，不足時應優先優化 SQL。
ini_set('memory_limit', '256M');


// ══════════════════════════════════════════════════════════════════════
// AJAX 請求統一分派到 OreadyReply_ForPm_BaseOfTime2_ajax.php
// 所有寫入／查詢邏輯在 _ajax.php，找功能請看下方對應表
// ══════════════════════════════════════════════════════════════════════
//
// ── BOM 主資料 ────────────────────────────────────────────────────────
//   create_bom              新增 BOM（製令）
//   update_bom_info         更新 BOM 基本資料（客戶、備註、訂單綁定）
//   update_bom_delivery_date 更新交期 / 未交量
//   delete_bom              刪除 BOM 及所有相關製程
//   check_closed_bom        搜尋已完工 BOM
//   cancel_bom_close        取消結案（恢復進行中）
//   fetch_bom_operation_log 取得操作記錄
//
// ── BOM 製程 (bom_ing) ───────────────────────────────────────────────
//   transfer_process        移轉製程（發外包）
//   cancel_transfer         取消移轉
//   delete_bom_ing          刪除單一製程
//   copy_bom_processes      複製製程（從其他 BOM）
//   get_process_price       取得製程歷史單價
//   search_process          搜尋製程清單
//
// ── 批次操作 ─────────────────────────────────────────────────────────
//   get_batch_status        取得批次狀態
//   do_batch_operation      執行批次（拆批 / 合批 / 繼續）
//   get_bom_buffer_worktime 計算 BOM 緩衝工時
//   get_bom_impact_score    計算 BOM 排程影響分數
//   get_outsource_predict   外包預測天數
//   get_order_urgent_level  訂單緊急程度
//
// ── BOM 詳情查詢 ──────────────────────────────────────────────────────
//   get_row_details         取得 BOM 列完整資料（彈窗用）
//   get_bom_files           取得 BOM 相關文件（圖面）
//   get_all_reports_for_bom 取得工單報告清單
//   get_report_details_for_popover  工單報告彈出詳情
//
// ── 料號設定 (d_setting) ──────────────────────────────────────────────
//   search_d_setting        搜尋料號設定
//   search_d_id_and_orders  搜尋料號（含訂單資訊）
//   get_orders_for_d_id     取得料號相關訂單
//   apply_dsetting_to_bom   快速綁定料號設定到 BOM
//   save_part_info          新增 / 更新料號設定
//   delete_part             刪除料號設定
//
// ── 訂單 ─────────────────────────────────────────────────────────────
//   get_orders_for_edit     取得訂單資料（供編輯）
//
// ── 客戶 (customer_list) ──────────────────────────────────────────────
//   search_customer_for_part 搜尋客戶（新增料號用）
//   add_new_customer        新增客戶
//   update_customer_data    更新客戶資料
//   update_customer_sales   更新客戶業務人員
//   get_invalid_customer_data     取得無效客戶清單
//   update_invalid_customer_status 更新客戶有效狀態
//
// ── 廠商 (maker_list) ─────────────────────────────────────────────────
//   search_maker            搜尋廠商
//   search_maker_for_bom    搜尋廠商（新增 BOM 製程用）
//
// ── 系統設定 ─────────────────────────────────────────────────────────
//   update_system_params    更新系統參數（BOM_SETTING）
//   update_sales_unit_setting  更新業務單位設定
//   get_sales_settings_data    取得業務設定資料
//   save_file_tags_setting     儲存文件標籤設定
//   get_file_tags_setting      取得文件標籤設定
//   get_process_types          取得製程類別清單
//   save_pti_filter_setting    儲存 PTI 篩選按鈕設定
//   get_process_type_map       取得製程類別對應製程
//   save_process_type_map      儲存製程類別對應
//   save_internal_process_types 設定廠內製程類型
//   get_internal_process_types  取得廠內製程類型設定
//
// ═════════════════════════════════════════════════════════════════════
if (isset($_POST['action'])) {
    require __DIR__ . '/OreadyReply_ForPm_BaseOfTime2_ajax.php';
    exit;
}


// MAIN PAGE PHP LOGIC (existing code continues below)

if (!isset($_SESSION['userName'])) //若使用者未設定，則返回登入頁
{
    $_SESSION['lastpage'] = "../../views/pm/OreadyReply_ForPm_BaseOfTime2.php";
    header("Location:../../index.php"); //返回登入頁
    exit();
}

include '../../src/common/DBConnection.php';
include '../../src/store/_setting.php';
include '../../src/common/_config.php';

@$userName      = $_SESSION['user_cname'] ?? $_SESSION['userName'];
@$id            = $_SESSION['id'];
session_write_close(); // session 讀取完畢，立即釋放鎖，避免 AJAX 請求排隊

@$conn = new DBConnection();
$conn->execute("SET SESSION group_concat_max_len = 65536");

// --- Get users on leave today ---
$today_str = date('Y-m-d');
$users_on_leave_ids = [];
$users_on_leave_names = [];
if (isset($db) && $db instanceof PDO) {
    try {
        // 1. Get day_off categories from system_parameters
        $stmt_param = $db->prepare("SELECT param_value FROM system_parameters WHERE param_group = 'ALL' AND param_key = 'day_off'");
        $stmt_param->execute();
        $param_row = $stmt_param->fetch(PDO::FETCH_ASSOC);
        
        $day_off_ids = [];
        if ($param_row && !empty($param_row['param_value'])) {
            $decoded = json_decode($param_row['param_value'], true);
            if (is_array($decoded)) {
                $day_off_ids = $decoded;
            } elseif (is_numeric($decoded)) {
                $day_off_ids = [$decoded];
            }
        }

        if (!empty($day_off_ids)) {
            $day_off_ids = array_map('intval', $day_off_ids);
            $day_off_ids = array_filter($day_off_ids);
            
            if (!empty($day_off_ids)) {
                $in_clause = implode(',', $day_off_ids);
                
                // 根據使用者回饋，僅使用 evenement_actor 來判斷休假人員，
                // 並改用 system_parameters 中的 day_off 設定來判斷休假類別
                $sql_leave = "
                    SELECT DISTINCT u.id, u.user_cname
                    FROM evenement_actor ea
                    JOIN evenement e ON ea.event_id = e.id
                    JOIN user u ON u.id = ea.user_id
                    WHERE e.category_id IN ($in_clause) AND :today BETWEEN DATE(e.start) AND DATE(e.end)
                ";
                $stmt_leave = $db->prepare($sql_leave);
                $stmt_leave->execute([':today' => $today_str]);
                $users_on_leave_data = $stmt_leave->fetchAll(PDO::FETCH_ASSOC);
                
                $users_on_leave_ids = array_map(function($row) { return intval($row['id']); }, $users_on_leave_data);
                $users_on_leave_names = array_map(function($row) { return $row['user_cname']; }, $users_on_leave_data);
            }
        }
    } catch (Exception $e) {
        error_log("Error fetching users on leave: " . $e->getMessage());
        // Continue without leave data if it fails
    }
}

// 取得權限
// 開啟錯誤除錯模式（建議開發時使用）
ini_set('display_errors', 1);
error_reporting(E_ALL);

// --- 系統頁面權限判斷 (AI 版) ---
$id = intval($_SESSION['id'] ?? 0);
$current_script_path = $_SERVER['PHP_SELF'];

$permission_code = null;
$page_url_editable = '';
$page_url_readonly = '';
$module_codes_to_check = []; // 初始化變數，確保下方 Debug 區塊能讀取
$debug_html = ''; // For detailed debug output

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
    $debug_html .= '<div style="font-family: monospace; font-size: 11px; text-align: left; line-height: 1.4;">';
    $debug_html .= '<b>Step 1: Find Page Info from URL</b><br>';
    $debug_html .= '<b>SQL:</b> ' . htmlspecialchars($sql_page_info) . '<br>';
    $debug_html .= '<b>Params:</b> [:script => ' . htmlspecialchars($current_script_path) . ']<br>';

    $stmt_page_info = $db->prepare($sql_page_info);
    $stmt_page_info->execute([':script' => $current_script_path]);
    $page_info = $stmt_page_info->fetch(PDO::FETCH_ASSOC);

    if (!$page_info) {
        // 若頁面無定義，則無權限
        $permission_code = null;
        $debug_html .= '<b>Result:</b> ' . htmlspecialchars(json_encode($page_info, JSON_UNESCAPED_UNICODE)) . '<br><hr>';
        $debug_html .= '<b>Step 1 Failed:</b> No matching page found in `system_module_pages`. Check `page_url` values.</div>';
    } else {
        $debug_html .= '<b>Result:</b> ' . htmlspecialchars(json_encode($page_info, JSON_UNESCAPED_UNICODE)) . '<br><hr>';
        $page_url_editable = $page_info['page_url'];
        $page_url_readonly = $page_info['page_url_readonly'];
        $page_id = $page_info['page_id'];
        $group_id = $page_info['group_id'];

        // Step 2: Get module_code from group_id for group-level permission check
        $group_module_code = null;
        if (!empty($group_id)) {
            $sql_group_module = "SELECT module_code FROM system_modules WHERE group_id = :gid LIMIT 1";
            $debug_html .= '<b>Step 2: Find Module Code from Group ID</b><br>';
            $debug_html .= '<b>SQL:</b> ' . htmlspecialchars($sql_group_module) . '<br>';
            $debug_html .= '<b>Params:</b> [:gid => ' . htmlspecialchars($group_id) . ']<br>';

            $stmt_group_module = $db->prepare($sql_group_module);
            $stmt_group_module->execute([':gid' => $group_id]);
            $group_module_code = $stmt_group_module->fetchColumn();

            $debug_html .= '<b>Result:</b> ' . htmlspecialchars($group_module_code ?: 'Not Found') . '<br><hr>';
        }

        // Step 3: Find User Permissions, prioritizing 'page' scope over 'group' scope.
        $user_perms = []; // This will hold the final permission strings to be processed.

        // 3a. First, try to find a page-specific permission.
        $sql_page_perm = "
            SELECT permission 
            FROM user_module_permissions 
            WHERE user_id = :user_id AND scope = 'page' AND module_code = :page_id
        ";
        $debug_html .= '<b>Step 3a: Find Page-Specific Permission</b><br>';
        $debug_html .= '<b>SQL:</b> ' . htmlspecialchars($sql_page_perm) . '<br>';
        $page_params = [':user_id' => $id, ':page_id' => $page_id];
        $debug_html .= '<b>Params:</b> ' . htmlspecialchars(json_encode($page_params)) . '<br>';

        $stmt_page_perm = $db->prepare($sql_page_perm);
        $stmt_page_perm->execute($page_params);
        $page_permissions_found = $stmt_page_perm->fetchAll(PDO::FETCH_COLUMN);

        // Filter out any empty/null results from the database
        $page_permissions_found = array_filter($page_permissions_found);

        if (!empty($page_permissions_found)) {
            // If page-specific permissions exist, use them exclusively.
            $user_perms = $page_permissions_found;
            $debug_html .= '<b>Result:</b> ' . htmlspecialchars(json_encode($user_perms, JSON_UNESCAPED_UNICODE)) . '<br>';
            $debug_html .= '<b>Decision:</b> Page-specific permission found. Using it exclusively.<br><hr>';
        } else {
            $debug_html .= '<b>Result:</b> No page-specific permission found.<br><hr>';
            // 3b. If no page-specific permission, check for group permission.
            if (!empty($group_module_code)) {
                $sql_group_perm = "
                    SELECT permission 
                    FROM user_module_permissions 
                    WHERE user_id = :user_id AND scope = 'group' AND module_code = :module_code
                ";
                $debug_html .= '<b>Step 3b: Find Group-Specific Permission</b><br>';
                $debug_html .= '<b>SQL:</b> ' . htmlspecialchars($sql_group_perm) . '<br>';
                $group_params = [':user_id' => $id, ':module_code' => $group_module_code];
                $debug_html .= '<b>Params:</b> ' . htmlspecialchars(json_encode($group_params)) . '<br>';

                $stmt_group_perm = $db->prepare($sql_group_perm);
                $stmt_group_perm->execute($group_params);
                $group_permissions_found = $stmt_group_perm->fetchAll(PDO::FETCH_COLUMN);
                
                // Filter out any empty/null results
                $group_permissions_found = array_filter($group_permissions_found);

                if (!empty($group_permissions_found)) {
                    $user_perms = $group_permissions_found;
                    $debug_html .= '<b>Result:</b> ' . htmlspecialchars(json_encode($user_perms, JSON_UNESCAPED_UNICODE)) . '<br>';
                    $debug_html .= '<b>Decision:</b> No page-specific permission. Using group permission.<br><hr>';
                } else {
                    $debug_html .= '<b>Result:</b> No group-specific permission found.<br><hr>';
                }
            } else {
                $debug_html .= '<b>Step 3b Skipped:</b> No group_module_code to check.<br><hr>';
            }
        }

        $debug_html .= '<b>Permissions to Process:</b> ' . htmlspecialchars(json_encode($user_perms, JSON_UNESCAPED_UNICODE)) . '<br>';

        // 4. 整合權限：拆分組合權限 (e.g., 'CR') 並以最強權限為準 (A > C/U/D > R)
        $all_individual_perms = [];
        foreach ($user_perms as $perm_string) {
            // The array is already filtered for empty values, so no need for !empty check
            $chars = str_split($perm_string);
            $all_individual_perms = array_merge($all_individual_perms, $chars);
        }
        $unique_perms = array_unique($all_individual_perms);
        
        $debug_html .= '<b>Result (Unique Individual Perms):</b> ' . htmlspecialchars(json_encode(array_values($unique_perms))) . '<br><hr>';

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
        $debug_html .= '<b>Step 4: Final Permission Code</b><br>';
        $debug_html .= '<b>Final Code:</b> ' . htmlspecialchars($permission_code ?: 'NULL (No Permission)') . '</div>';
    }

} catch (Exception $e) {
    error_log("Permission check error: " . $e->getMessage());
    $permission_code = null; // 出錯時一律視為無權限
    $debug_html .= '<b>ERROR:</b> ' . htmlspecialchars($e->getMessage()) . '</div>';
}

// Format permission code for display
$display_permission_code = '';
$permission_display_text = '';
$permission_tooltip_text = '';

if ($permission_code) {
    if ($permission_code === 'A') {
        $display_permission_code = 'A';
        $permission_display_text = 'A 管理者';
        $permission_tooltip_text = '管理者權限有所有功能';
    } else {
        // Sort the characters to ensure consistent order e.g., C,R,U,D
        $permission_parts = str_split($permission_code);
        sort($permission_parts);
        $display_permission_code = implode('+', $permission_parts);

        if ($display_permission_code === 'C+R') {
            $permission_display_text = 'C+R 生管';
            $permission_tooltip_text = '生管權限 無 設定燈號功能，其他功能都有';
        } elseif ($display_permission_code === 'R') {
            $permission_display_text = 'R 檢視';
            $permission_tooltip_text = '僅查看，不顯示功能按鈕(設定燈號、設定業務、更新) 僅有篩選按鈕可使用且單關備註(bom.single_bet_ps)變為唯讀';
        } elseif ($display_permission_code === 'R+U') {
            $permission_display_text = 'R+U 業務';
            $permission_tooltip_text = '業務權限，可以增加修改 BOM備註(bom.bom_ps)、更改客戶名稱(bom.Client_Name)、交期×數量(未交量)修改(bom.Delivery_date)、燈號設定(bom.priority_type)、但不出現 移轉 按鈕、不出現 新增製程 按鈕、不可修改新增刪除 單關備註(bom.single_bet_ps)';
        } elseif ($display_permission_code === 'D+R') {
            $permission_display_text = 'R+D 受限業務';
            $permission_tooltip_text = '受限業務權限，可以增加修改 BOM備註(bom.bom_ps)、更改客戶名稱(bom.Client_Name)、交期×數量(未交量)修改(bom.Delivery_date)、但不出現 移 按鈕、不出現 新增製程 按鈕、不可修改新增刪除 單關備註(bom.single_bet_ps)、 無 設定燈號';
        } else {
            $permission_display_text = $display_permission_code;
        }
    }
}

// 2. 判斷權限並導向
if (is_null($permission_code)) {
    // NULL → 無權限，自動跳回主頁
    echo "<script>alert('您無權限訪問此頁面'); window.location.href='../../index.php';</script>";
    exit;
}

if ($permission_code === 'R') {
    // 若使用者僅有 R 權限 → 導向 system_module_pages.page_url_readonly
    // 檢查當前是否在可編輯頁面 (避免在唯讀頁面重複導向)
    if (!empty($page_url_editable) && substr($current_script_path, -strlen($page_url_editable)) === $page_url_editable) {
        if (!empty($page_url_readonly)) {
            header("Location: " . $page_url_readonly);
            exit;
        }
    }
}

// 3. 設定功能操作權限變數
// ✅ 業務類權限（R+U、C+R+U、C+D+R+U）：隱藏「移」及「取消移轉」按鈕
// $_is_cru = true 代表「業務身份」；用於隱藏移轉相關按鈕（與是否含 D 無關）
$_sales_permission_codes = ['R+U', 'C+R+U', 'C+D+R+U'];
$_is_cru = in_array($display_permission_code, $_sales_permission_codes, true);
// 人工結案按鈕：只要含 D 或 A 就可顯示（R+U、C+R+U 無 D 不可顯示；C+D+R+U 含 D 可顯示）
$can_manual_close = ($permission_code && (strpos($permission_code, 'A') !== false || strpos($permission_code, 'D') !== false));
$can_create = (!$_is_cru && $permission_code && (strpos($permission_code, 'A') !== false || strpos($permission_code, 'C') !== false));
$can_update = ($permission_code && (strpos($permission_code, 'A') !== false || strpos($permission_code, 'U') !== false || strpos($permission_code, 'C') !== false));
$can_delete = ($permission_code && (strpos($permission_code, 'A') !== false || strpos($permission_code, 'D') !== false || (!$_is_cru && strpos($permission_code, 'C') !== false)));

// --- 角色功能碼（新機制，與上方舊 CRUD 規則並存：舊規則 OR 新功能碼，任一成立即可）---
require_once '../../src/common/role_features_helper.php';
$_oready_features = rf_load_user_features($db, $id);
$can_manual_close = $can_manual_close || rf_has_feature($_oready_features, 'oready_manual_close');
$can_create       = $can_create       || rf_has_feature($_oready_features, 'oready_create');
$can_update        = $can_update       || rf_has_feature($_oready_features, 'oready_update');
$can_delete        = $can_delete       || rf_has_feature($_oready_features, 'oready_delete');

// 對應舊版 user_status (1=可編輯, 0=唯讀)
$user_status = ($can_create || $can_update || $can_delete) ? 1 : 0;

// 其餘細項功能：這些按鈕原本各自有比 can_create/can_update/user_status 更窄的排除規則
// （例如 D+R、isCRU、特定 displayPermissionCode 組合要排除），為避免把新功能碼誤接到過寬的共用變數
// 導致誤開權限，這裡只提供「純功能碼」旗標，實際套用時在各按鈕原本的判斷式後面加 OR，不改動原判斷式本身。
$oready_feat_mark_returned = rf_has_feature($_oready_features, 'oready_mark_returned');
$oready_feat_transfer      = rf_has_feature($_oready_features, 'oready_transfer');
$oready_feat_batch         = rf_has_feature($_oready_features, 'oready_batch_split_merge');
$oready_feat_view_price    = rf_has_feature($_oready_features, 'oready_view_price');
$oready_feat_process_settings = rf_has_feature($_oready_features, 'oready_process_settings');

// --- 修改後的 PHP 資料準備邏輯 ---
// 1. 獲取基礎 BOM 資料 (移除 ol 的 JOIN，因為 OrderList 會單獨處理)
$OreadyReply_list_base = $conn->getAll("SELECT 
    bi.bom_ing_id,
    b.bom,
    b.Delivery_date,
    b.bom_ps            AS bom_ALL_bom_ps,
    b.priority_type,
    bi.bom_sn,
    b.processing_state  AS bom_processing_state,
    bi.processing_state,
    b.sqty              AS Qty,
    b.d_id,
    b.d_setting_id,
    COALESCE(ds.D_Setting_Id, b.d_id, '') AS d_display,
    COALESCE(ds.Drawing_No, '') AS d_drawing_no,
    COALESCE(ds.Customer_Id, cl.customer_id, '') AS d_customer_id,
    b.Client_Name       AS Client_Name_Full,
    SUBSTRING(REPLACE(b.Client_Name, ' ', ''), 1, 3) AS Client_Name,
    COALESCE(cl_ds.customer, b.Client_Name)           AS client_name_display,
    bi.process_no,
    bi.bom_ing_fid,
    bi.ps,
    bi.ProcessName, -- ✅ 修正：直接從子查詢取得合併後的製程名稱
    -- 修正：直接使用子查詢中已彙整好的 pti
    bi.pti,
    bi.maker_id,    -- ✅ 修正：直接從子查詢取得合併後的廠商名稱
    bi.maker_id_no_list,
    bi.bom_ing_sqty,
    -- ✅ 優先使用 bom_order_process_map 的第一筆訂單，fallback 到 bom.o_order_id
    COALESCE(
        (SELECT bopm_first.order_id FROM bom_order_process_map bopm_first WHERE bopm_first.bom = b.bom ORDER BY bopm_first.id ASC LIMIT 1),
        b.o_order_id
    ) AS Order_id,
    (SELECT GROUP_CONCAT(CONCAT(bopm_all.order_id, ':', COALESCE(bopm_all.allocated_qty, 0), ':', COALESCE(ot_all.Order_oo, '')) SEPARATOR ';')
     FROM bom_order_process_map bopm_all
     LEFT JOIN order_track ot_all ON bopm_all.order_id = ot_all.Order_id
     WHERE bopm_all.bom = b.bom
    ) AS bound_orders_info,
    vw.OreadyReply_id,
    vw.oready_sqty_total,
    vw.ng_sqty_total,
    vw.Created_At_end,
    bi.QC_check,
    bi.QC_ps,
    bi.bom_bom_ps,
    
    CONCAT(DATE_FORMAT(vw.Created_At_end, '%y'), 'y/', DATE_FORMAT(vw.Created_At_end, '%c/%e')) AS Created_At_s,
    CONCAT(DATE_FORMAT(bi.Created_At, '%y'), 'y/', DATE_FORMAT(bi.Created_At, '%c/%e')) AS Created_At_bi,
    DATE_FORMAT(bi.outsource_date, '%Y/%m/%d')     AS outsource_date,
    DATE_FORMAT(bi.return_date, '%Y/%m/%d')        AS return_date,
    DATE_FORMAT(bi.QC_check_date, '%Y/%m/%d')      AS QC_check_date,
    ml.m_tel,
    ml.m_fax,
    ml.m_tel2,
    ml.factory_address,
    ml.contact_person,
    ml.contact_title,
    u_sales_primary.id AS PrimarySalesId,
    u_sales_primary.user_cname AS PrimarySalesName,
    u_sales_deputy.id AS DeputySalesId,
    u_sales_deputy.user_cname AS DeputySalesName,

    -- ✅ QC 燈號統計欄位
    IFNULL(qc.qc_total,    0) AS qc_total,
    IFNULL(qc.QC_QQ_sqty,  0) AS qc_qq_qty,
    IFNULL(qc.QC_ok_sqty,  0) AS qc_ok_qty,
    IFNULL(qc.QC_ng_sqty,  0) AS qc_ng_qty,
    IFNULL(qc.QC_aod_sqty, 0) AS qc_aod_qty,

    CASE 
        WHEN qc.qc_total < bi.bom_ing_sqty THEN 1 
        ELSE 0 
    END AS show_gray_light,

    -- ✅ 核心：查詢未交訂單數量
    IFNULL(unshipped_orders.total_open_qty, 0) AS unshipped_qty

FROM bom b
LEFT JOIN d_setting ds ON ds.d_id = b.d_setting_id
-- 當 bom 綁定料號(d_setting_id)時，以 d_setting.Customer_Id → customer_list.customer 為優先顯示客戶名
LEFT JOIN customer_list cl_ds ON cl_ds.customer_id = ds.Customer_Id

-- ◆ 更新後的「最新 bom_ing」邏輯
-- 1. 找出每個 bom 最新的一個有效日期 (max_effective_date)
-- 2. 根據這個日期，找出所有相關的 bom_ing 記錄 (可能有多筆 bom_sn 在同一天發單)
-- 3. 將這些記錄的資訊 GROUP_CONCAT 起來，以便在單一列中顯示
LEFT JOIN (
    SELECT
        bi_grouped.bom,
        -- 將所有符合條件的 bom_ing_id, bom_sn, process_no 等資訊彙整起來
        GROUP_CONCAT(DISTINCT bi_grouped.bom_ing_id ORDER BY bi_grouped.bom_sn) AS bom_ing_id,
        GROUP_CONCAT(DISTINCT bi_grouped.bom_sn ORDER BY bi_grouped.bom_sn) AS bom_sn,
        -- 修正：將 pti 也 GROUP_CONCAT 起來
        GROUP_CONCAT(DISTINCT pn.process_type_id ORDER BY bi_grouped.bom_sn) AS pti,
        -- ✅ 將多個製程名稱用斜線合併
        GROUP_CONCAT(DISTINCT pn.ProcessName ORDER BY bi_grouped.bom_sn SEPARATOR '/') AS ProcessName,
        -- ✅ 將多個廠商名稱用斜線合併
        GROUP_CONCAT(DISTINCT ml.maker_id ORDER BY bi_grouped.bom_sn SEPARATOR '/') AS maker_id,
        GROUP_CONCAT(DISTINCT CAST(bi_grouped.maker_id_no AS CHAR) ORDER BY bi_grouped.bom_sn SEPARATOR '/') AS maker_id_no_list,
        GROUP_CONCAT(DISTINCT bi_grouped.process_no ORDER BY bi_grouped.bom_sn) AS process_no,
        GROUP_CONCAT(DISTINCT bi_grouped.bom_ing_fid ORDER BY bi_grouped.bom_sn) AS bom_ing_fid,
        GROUP_CONCAT(DISTINCT bi_grouped.ps ORDER BY bi_grouped.bom_sn) AS ps,
        GROUP_CONCAT(DISTINCT bi_grouped.sqty ORDER BY bi_grouped.bom_sn) AS bom_ing_sqty,
        GROUP_CONCAT(DISTINCT bi_grouped.QC_check ORDER BY bi_grouped.bom_sn) AS QC_check,
        GROUP_CONCAT(DISTINCT bi_grouped.QC_ps ORDER BY bi_grouped.bom_sn) AS QC_ps,
        GROUP_CONCAT(DISTINCT bi_grouped.single_bet_ps ORDER BY bi_grouped.bom_sn) AS bom_bom_ps,
        -- 日期、廠商等資訊因為是同一批，所以取 MAX 即可
        MAX(bi_grouped.outsource_date) AS outsource_date,
        MAX(bi_grouped.return_date) AS return_date,
        MAX(bi_grouped.QC_check_date) AS QC_check_date,
        MAX(bi_grouped.maker_id_no) AS maker_id_no,
        MAX(bi_grouped.Created_At) AS Created_At,
        -- 只取一個 processing_state 作為代表
        SUBSTRING_INDEX(GROUP_CONCAT(bi_grouped.processing_state ORDER BY bi_grouped.bom_sn), ',', 1) AS processing_state
    FROM bom_ing bi_grouped
    INNER JOIN ( -- Subquery to find the latest effective date for each BOM
        -- 以移轉日(outsource_date)為準：未發單(null)的製程不列入比較，只顯示移轉日最新的製程
        SELECT bom, MAX(DATE(outsource_date)) AS max_effective_date
        FROM bom_ing WHERE processing_state IN ('Q', 'P', 'ing', 'E') AND outsource_date IS NOT NULL AND is_schedule_split = 0 GROUP BY bom
    ) bi_latest ON bi_grouped.bom = bi_latest.bom AND bi_grouped.outsource_date IS NOT NULL AND DATE(bi_grouped.outsource_date) = bi_latest.max_effective_date
    LEFT JOIN maker_list ml ON ml.maker_id_no = bi_grouped.maker_id_no -- ✅ 新增 JOIN 以取得廠商中文名
    LEFT JOIN process_no pn ON pn.ProcessNo = bi_grouped.process_no -- 修正：在子查詢內 JOIN process_no
    WHERE bi_grouped.processing_state IN ('Q', 'P', 'ing', 'E') AND bi_grouped.is_schedule_split = 0
    GROUP BY bi_grouped.bom -- 修正：僅依 bom 分組以合併所有最新製程
) bi ON bi.bom = b.bom

LEFT JOIN vw_vw_oreadyreply_forpm vw
  ON FIND_IN_SET(vw.bom_ing_id, bi.bom_ing_id) -- ✅ 修正：使用 FIND_IN_SET 進行關聯

-- QC_check 彙總（以 BOM 為單位，避免 FIND_IN_SET 在多製程有QC紀錄時產生重複列）
LEFT JOIN (
    SELECT
        bi_qc.bom,
        SUM(IFNULL(qc_inner.QC_QQ_sqty,  0)) AS QC_QQ_sqty,
        SUM(IFNULL(qc_inner.QC_ng_sqty,  0)) AS QC_ng_sqty,
        SUM(IFNULL(qc_inner.QC_aod_sqty, 0)) AS QC_aod_sqty,
        SUM(IFNULL(qc_inner.QC_ok_sqty,  0)) AS QC_ok_sqty,
        SUM(
            IFNULL(qc_inner.QC_QQ_sqty,  0) +
            IFNULL(qc_inner.QC_ng_sqty,  0) +
            IFNULL(qc_inner.QC_aod_sqty, 0) +
            IFNULL(qc_inner.QC_ok_sqty,  0)
        ) AS qc_total
    FROM QC_check qc_inner
    JOIN bom_ing bi_qc ON qc_inner.bom_ing_fid_ref = bi_qc.bom_ing_fid
    GROUP BY bi_qc.bom
) qc ON qc.bom = b.bom

LEFT JOIN maker_list ml 
  ON bi.maker_id_no = ml.maker_id_no

-- 修正：將 process_no 的 JOIN 移回 WHERE 之前
-- 這裡的 bi.process_no 是 GROUP_CONCAT 的結果，所以用 FIND_IN_SET 來 JOIN
-- 為了避免因多個製程而產生重複的資料列，我們只 JOIN 第一個製程來顯示其名稱
LEFT JOIN process_no pn 
  ON pn.ProcessNo = SUBSTRING_INDEX(bi.process_no, ',', 1)

-- ✅ 核心：JOIN 未交訂單總數的子查詢（使用 bom_order_process_map + order_track）
LEFT JOIN (
    SELECT 
        bopm.bom,
        SUM(ot.Open_Qty) as total_open_qty
    FROM bom_order_process_map bopm
    JOIN order_track ot ON ot.Order_id = bopm.order_id
    WHERE (ot.Order_status IS NULL OR ot.Order_status <> '9')
      AND ot.Open_Qty > 0
    GROUP BY bopm.bom
) unshipped_orders 
  ON unshipped_orders.bom = b.bom

-- ✅ JOIN 客戶業務資料 (Primary and Deputy)
-- 備用：透過 bom.Client_Name 比對客戶名稱（未綁定料號時使用）
LEFT JOIN customer_list cl ON cl.customer = b.Client_Name
-- 業務查詢：優先用 cl_ds（綁定料號），否則用 cl（名稱比對）
LEFT JOIN customer_sales cs_primary ON cs_primary.customer_id = COALESCE(cl_ds.customer_id, cl.customer_id) AND cs_primary.is_active = 1 AND cs_primary.role = 'primary'
LEFT JOIN user u_sales_primary ON u_sales_primary.id = cs_primary.user_id
-- Join for Deputy Salesperson
LEFT JOIN customer_sales cs_deputy ON cs_deputy.customer_id = COALESCE(cl_ds.customer_id, cl.customer_id) AND cs_deputy.is_active = 1 AND cs_deputy.role = 'deputy'
LEFT JOIN user u_sales_deputy ON u_sales_deputy.id = cs_deputy.user_id

-- 基本過濾條件
WHERE 
    b.d_id <> ''
    AND b.processing_state IS NULL

ORDER BY
    vw.Created_At_end DESC,
    CAST(SUBSTRING_INDEX(b.bom, '-', -1) AS UNSIGNED) ASC;");

// 取得最大製程數 & 判斷bom製程數 (Optimized by PHP)
$bom_ps_list = [];
$bom_ps_list_max = 0;
$all_boms = array_column($OreadyReply_list_base, 'bom');
$all_boms = array_filter(array_unique($all_boms));

if (!empty($all_boms)) {
    // 1. Fetch all bom_ing records for the active BOMs only
    $placeholders_ps = implode(',', array_fill(0, count($all_boms), '?'));
    
    $sql_ps = "SELECT
                  bi.*,
                  pn.ProcessName,
                  pn.is_exclude_qc,
                  ml.maker_id
                FROM bom_ing AS bi
                LEFT JOIN process_no pn ON bi.process_no = pn.ProcessNo
                LEFT JOIN maker_list ml ON bi.maker_id_no = ml.maker_id_no
                WHERE bi.bom IN ($placeholders_ps)
                  AND bi.is_schedule_split = 0
                ORDER BY bi.bom, bi.bom_sn";

    $stmt_ps = $db->prepare($sql_ps);
    $stmt_ps->execute(array_values($all_boms));
    $raw_ps_list = $stmt_ps->fetchAll(PDO::FETCH_ASSOC);

    // 2. Process in PHP: Deduplicate (pick latest) and Calculate Max Count
    $processed_ps_map    = []; // To store unique (bom, sn) records
    $all_active_per_key  = []; // [bom_sn_key => [active batches]] 供拆分顯示
    $counts_per_bom      = [];

    foreach ($raw_ps_list as $row) {
        $b   = $row['bom'];
        $sn  = $row['bom_sn'];
        $key = $b . '_' . $sn;

        $fmt_d = function($v) {
            return (!empty($v) && $v !== '0000-00-00 00:00:00')
                ? date('Y/m/d', strtotime($v)) : null;
        };
        $batch_entry = [
            'bom_ing_fid'      => $row['bom_ing_fid'] ?? null,
            'batch_label'      => $row['batch_label'] ?? null,
            'sqty'             => $row['sqty'],
            'maker_id'         => $row['maker_id'] ?? '',
            'maker_id_no'      => $row['maker_id_no'] ?? '',
            'outsource_date'   => $fmt_d($row['outsource_date']),
            'return_date'      => $fmt_d($row['return_date']),
            'processing_state' => $row['processing_state'] ?? '',
            'is_consumed'      => (int)($row['is_consumed'] ?? 0),
            'QC_check'         => $row['QC_check'] ?? null,
            'qc_completed'     => (int)($row['qc_completed'] ?? 0),
            'QC_check_date'    => $fmt_d($row['QC_check_date'] ?? null),
        ];

        // 活躍批次（is_consumed=0）→ 供篩選與互動功能
        if (empty($row['is_consumed'])) {
            $all_active_per_key[$key][] = $batch_entry;
        }
        // 所有有 batch_label 的批次（含已消耗）→ 供製程欄歷史顯示
        if (!empty($row['batch_label'])) {
            $all_split_per_key[$key][] = $batch_entry;
        }

        // Logic to pick latest if duplicate exists (mimicking the original SQL aggregation)
        $row_time = strtotime($row['Modified_At'] ?: $row['Created_At']);
        if (isset($processed_ps_map[$key])) {
            $existing      = $processed_ps_map[$key];
            $existing_time = strtotime($existing['Modified_At'] ?: $existing['Created_At']);
            if ($row_time > $existing_time) $processed_ps_map[$key] = $row;
        } else {
            $processed_ps_map[$key] = $row;
        }
    }

    // 將活躍批次清單附加到每筆代表記錄
    foreach ($processed_ps_map as $key => &$entry) {
        $batches = $all_active_per_key[$key] ?? [];
        usort($batches, function($a, $b) {
            return strcmp((string)($a['batch_label'] ?? ''), (string)($b['batch_label'] ?? ''));
        });
        $entry['split_batches'] = $batches;

        // 所有拆分批次（含已消耗），供已完成製程欄歷史顯示
        $all_split = $all_split_per_key[$key] ?? [];
        usort($all_split, function($a, $b) {
            return strcmp((string)($a['batch_label'] ?? ''), (string)($b['batch_label'] ?? ''));
        });
        $entry['all_split_batches'] = $all_split;
    }
    unset($entry);

    // Convert map back to list
    $bom_ps_list = array_values($processed_ps_map);
    
    // Calculate max count per BOM
    foreach ($bom_ps_list as $item) {
        $b = $item['bom'];
        if (!isset($counts_per_bom[$b])) {
            $counts_per_bom[$b] = 0;
        }
        $counts_per_bom[$b]++;
    }
    if (!empty($counts_per_bom)) {
        $bom_ps_list_max = max($counts_per_bom);
    }
}

// ── 建立 ingActiveMap：[bom => [{per-process active data}]] 供前端發單日欄位逐製程顯示 ──
// 使用 $raw_ps_list（含所有批次）而非 dedup 後的清單，確保拆分批次也能顯示與篩選
$bom_ing_active_map = [];
foreach ($raw_ps_list as $_iam_item) {
    $_iam_state = $_iam_item['processing_state'] ?? '';
    // 'N'(待發包) 只在有 batch_label（拆分批次）時納入，確保拆批後的未發包批次也能顯示
    $_iam_has_label = !empty($_iam_item['batch_label']);
    if (!in_array($_iam_state, ['Q', 'P', 'ing', 'E']) && !($_iam_state === 'N' && $_iam_has_label)) continue;
    if (!empty($_iam_item['is_consumed'])) continue; // 已消耗批次不列入
    $_iam_bom = $_iam_item['bom'];
    $_iam_fmt = function($v) { return (!empty($v) && $v !== '0000-00-00 00:00:00') ? date('Y/m/d', strtotime($v)) : null; };
    $_iam_qcd = $_iam_item['QC_check_date'] ?? null;
    $bom_ing_active_map[$_iam_bom][] = [
        'bom_sn'           => $_iam_item['bom_sn'],
        'batch_label'      => $_iam_item['batch_label'] ?? null,
        'bom_ing_id'       => $_iam_item['bom_ing_id'],
        'bom_ing_fid'      => $_iam_item['bom_ing_fid'],
        'process_no'       => $_iam_item['process_no'],
        'ProcessName'      => $_iam_item['ProcessName'] ?? '',
        'processing_state' => $_iam_state,
        'outsource_date'   => $_iam_fmt($_iam_item['outsource_date'] ?? null),
        'return_date'      => $_iam_fmt($_iam_item['return_date'] ?? null),
        'QC_check'         => $_iam_item['QC_check'] ?? null,
        'QC_check_date'    => !empty($_iam_qcd) && $_iam_qcd !== '0000-00-00 00:00:00' ? $_iam_fmt($_iam_qcd) : null,
        'QC_ps'            => $_iam_item['QC_ps'] ?? null,
        'qc_completed'     => (int)($_iam_item['qc_completed'] ?? 0),
        'qc_completed_at'  => $_iam_fmt($_iam_item['qc_completed_at'] ?? null),
        'maker_id'         => $_iam_item['maker_id'] ?? '',
        'maker_id_no'      => $_iam_item['maker_id_no'] ?? null,
        'sqty'             => $_iam_item['sqty'] ?? null,
        'is_exclude_qc'    => (int)($_iam_item['is_exclude_qc'] ?? 0),
    ];
}
foreach ($bom_ing_active_map as &$_iam_procs) {
    usort($_iam_procs, function($a, $b) { return (int)$a['bom_sn'] - (int)$b['bom_sn']; });
}
unset($_iam_procs);

// ── 批量查詢 bom_ing_transfer_log（本BOM當關最新單價 + 同料號歷史單價）──────
$transfer_price_map    = []; // [bom][bom_sn] = 最新單價列
$transfer_history_map  = []; // [product_id][bom_sn] = [{...}, ...] 由新到舊（排除本BOM）

if (!empty($all_boms)) {
    // 取得每個 BOM 對應的 d_id（料號）— 同時收集 d_id 與 d_display 雙重 key
    // 因為 bom_ing_transfer_log.product_id 可能存 d_id 也可能存 d_display，兩者都查
    $bom_to_did = [];
    $all_dids_set = [];
    foreach ($OreadyReply_list_base as $r) {
        $raw_did     = $r['d_id'] ?? '';
        $display_did = !empty($r['d_display']) ? $r['d_display'] : $raw_did;
        // 以 d_display 為主 key（與前端 _didKey 一致）
        if (!empty($r['bom']) && !empty($display_did)) {
            $bom_to_did[$r['bom']] = $display_did;
        }
        // 同時把 d_id 和 d_display 都加入查詢集合
        if (!empty($raw_did))     $all_dids_set[$raw_did]     = true;
        if (!empty($display_did)) $all_dids_set[$display_did] = true;
    }
    $all_dids = array_filter(array_keys($all_dids_set));

    // 1. 本批 BOM 的 transfer_log（每個 bom+bom_sn 取最新一筆）
    $ph_boms = implode(',', array_fill(0, count($all_boms), '?'));
    try {
        $sql_tl = "
            SELECT tl.bom, tl.bom_sn, tl.maker_from, tl.sqty, tl.transfer_date,
                   tl.price, tl.modified_unit_price, tl.note,
                   ml.maker_id AS maker_name
            FROM bom_ing_transfer_log tl
            INNER JOIN (
                SELECT bom, bom_sn, MAX(transfer_id) AS max_id
                FROM bom_ing_transfer_log
                WHERE bom IN ($ph_boms)
                GROUP BY bom, bom_sn
            ) latest ON tl.bom = latest.bom AND tl.bom_sn = latest.bom_sn AND tl.transfer_id = latest.max_id
            LEFT JOIN maker_list ml ON ml.maker_id_no = tl.maker_from
            WHERE tl.bom IN ($ph_boms)
        ";
        $stmt_tl = $db->prepare($sql_tl);
        $params_tl = array_merge(array_values($all_boms), array_values($all_boms));
        $stmt_tl->execute($params_tl);
        foreach ($stmt_tl->fetchAll(PDO::FETCH_ASSOC) as $row_tl) {
            $transfer_price_map[$row_tl['bom']][$row_tl['bom_sn']] = $row_tl;
        }
    } catch(PDOException $e) { error_log('transfer_log batch query error: ' . $e->getMessage()); }

    // 2. 同料號所有BOM的 transfer_log（用於 hover 歷史顯示，排除本批 BOM）
    if (!empty($all_dids)) {
        $ph_dids = implode(',', array_fill(0, count($all_dids), '?'));
        try {
            $sql_hist = "
                SELECT tl.bom, tl.bom_sn, tl.maker_from, tl.sqty, tl.transfer_date,
                       tl.price, tl.modified_unit_price, tl.note, tl.product_id,
                       ml.maker_id AS maker_name
                FROM bom_ing_transfer_log tl
                LEFT JOIN maker_list ml ON ml.maker_id_no = tl.maker_from
                WHERE tl.product_id IN ($ph_dids)
                ORDER BY tl.product_id, tl.bom_sn, tl.transfer_date DESC, tl.transfer_id DESC
            ";
            $stmt_hist = $db->prepare($sql_hist);
            $stmt_hist->execute(array_values($all_dids));
            foreach ($stmt_hist->fetchAll(PDO::FETCH_ASSOC) as $row_hist) {
                $did  = $row_hist['product_id'];
                $bsn  = $row_hist['bom_sn'];
                // 歷史保留所有同料號 bom 的記錄（前端依 row.bom 過濾本 bom 自身）
                // 同時以 product_id 原始值 與 bom_to_did 對照值 雙重建立 key，確保前端查得到
                $transfer_history_map[$did][$bsn][] = $row_hist;
                // 若 product_id 不等於 d_display，也補一份 d_display key
                $alt_did = $bom_to_did[$row_hist['bom']] ?? '';
                if (!empty($alt_did) && $alt_did !== $did) {
                    $transfer_history_map[$alt_did][$bsn][] = $row_hist;
                }
            }
        } catch(PDOException $e) { error_log('transfer_log history query error: ' . $e->getMessage()); }
    }
}

// 2. 為每筆 BOM 資料準備 OrderList (模擬 _fetch_data2.php 的邏輯)
$OreadyReply_list_final = [];
if (is_array($OreadyReply_list_base)) {
    // 確保 $db 物件存在
    if (!isset($db) || !$db instanceof PDO) {
        // 如果 $db 未被 _config.php 正確初始化，這裡會出錯
        // 您可能需要在此處添加錯誤處理或確保 _config.php 正確設定 $db
        error_log("OreadyReply_ForPm_BaseOfTime2.php: PDO \$db object is not available.");
    }

// ✅ 1. 變更查詢主鍵：不再收集每一列的 bom_ing_fid，而是收集 bom 編號。

    // Optimization: Collect all IDs for batch fetching
    $all_bom_ing_fids = [];
    foreach ($OreadyReply_list_base as $row) {
        if (!empty($row['bom_ing_fid'])) {
            $fids = explode(',', $row['bom_ing_fid']);
            foreach ($fids as $fid_val) $all_bom_ing_fids[] = trim((string)$fid_val);
        }
    }
    $all_bom_ing_fids = array_unique(array_filter($all_bom_ing_fids));

    // ✅ 修正：建立 pm_report_map（以 bom_ing_fid 為 key）
    // 注意：主查詢的 bom_ing_fid 只包含目前 active 製程，
    // 因此改以 bom 為單位批量查詢，確保已完工製程的報工資料也能被取到
    $pm_report_map    = []; // key: bom_ing_fid
    $pm_schedule_map  = []; // key: bom_ing_fid
    $finished_fids_set = []; // fid => true（有 is_finished=1 報工的 fid）
    $bom_all_fids_map  = []; // bom => [fid, ...]（該 BOM 所有製程 fid）
    if (!empty($all_boms)) {
        $placeholders_pm_init = implode(',', array_fill(0, count($all_boms), '?'));
        // 報工資料：以 bom 為單位撈，不限制 bom_ing 的 processing_state
        $sql_pm_init = "
            SELECT bi.bom_ing_fid,
                   MAX(COALESCE(pdr.production_start_time, pdr.setup_start_time, pdr.report_date)) AS latest_date,
                   SUM(COALESCE(pdr.produced_qty, 0)) AS total_good,
                   (
                       SELECT COALESCE(SUM(ng.ng_qty), 0)
                       FROM pm_process_daily_ng ng
                       JOIN pm_process_daily_report pdr2 ON ng.report_id = pdr2.report_id
                       WHERE pdr2.bom_ing_fid = pdr.bom_ing_fid
                   ) AS total_ng
            FROM pm_process_daily_report pdr
            JOIN bom_ing bi ON pdr.bom_ing_fid = bi.bom_ing_fid
            WHERE bi.bom IN ($placeholders_pm_init)
            GROUP BY pdr.bom_ing_fid
        ";
        try {
            $stmt_pm_init = $db->prepare($sql_pm_init);
            $stmt_pm_init->execute(array_values($all_boms));
            foreach ($stmt_pm_init->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $pm_report_map[$r['bom_ing_fid']] = $r;
            }
        } catch (PDOException $e) {
            error_log("pm_report_map init error: " . $e->getMessage());
        }

        // 排程順位
        try {
            $stmt_pm_sched = $db->prepare("
                SELECT ps.bom_ing_fid, ps.schedule_order
                FROM pm_process_schedule ps
                JOIN bom_ing bi ON ps.bom_ing_fid = bi.bom_ing_fid
                WHERE bi.bom IN ($placeholders_pm_init)
            ");
            $stmt_pm_sched->execute(array_values($all_boms));
            foreach ($stmt_pm_sched->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $pm_schedule_map[$r['bom_ing_fid']] = $r['schedule_order'];
            }
        } catch (PDOException $e) {
            error_log("pm_schedule_map init error: " . $e->getMessage());
        }

        // ✅ 所有製程 fid（用於判斷完工）
        try {
            $stmt_all_fids = $db->prepare("
                SELECT bi.bom, bi.bom_ing_fid
                FROM bom_ing bi
                WHERE bi.bom IN ($placeholders_pm_init)
                ORDER BY bi.bom, CAST(bi.bom_sn AS UNSIGNED)
            ");
            $stmt_all_fids->execute(array_values($all_boms));
            foreach ($stmt_all_fids->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $bom_all_fids_map[$r['bom']][] = $r['bom_ing_fid'];
            }
        } catch (PDOException $e) {
            error_log("bom_all_fids_map init error: " . $e->getMessage());
        }

        // ✅ 有完工報工（is_finished=1）的 fid 集合
        // 使用 MAX(is_finished) 避免因欄位型態問題查不到
        try {
            $stmt_fin = $db->prepare("
                SELECT pdr.bom_ing_fid
                FROM pm_process_daily_report pdr
                JOIN bom_ing bi ON pdr.bom_ing_fid = bi.bom_ing_fid
                WHERE bi.bom IN ($placeholders_pm_init)
                GROUP BY pdr.bom_ing_fid
                HAVING MAX(COALESCE(pdr.is_finished, 0)) = 1
            ");
            $stmt_fin->execute(array_values($all_boms));
            foreach ($stmt_fin->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $finished_fids_set[$r['bom_ing_fid']] = true;
            }
        } catch (PDOException $e) {
            error_log("finished_fids_set init error: " . $e->getMessage());
        }
    }

    $qq_details_map = [];
    if (!empty($all_boms)) {
        $placeholders = implode(',', array_fill(0, count($all_boms), '?'));
        // ✅ 2. 修改「異常(QQ)」備註的 SQL 查詢
        $sql_qq_details = "
            SELECT 
                bi.bom,
                qc.bom_ing_fid_ref,
                qc.QC_check,
                DATE_FORMAT(qc.QC_check_date, '%c/%e') AS qc_date_formatted, qc.QC_check_date,
                qc.QC_QQ_sqty,
                qc.QC_ps,
                bi.bom_sn,
                pn.ProcessName,
                bi.QC_ps  AS bQC_ps,
                bi.QC_ps2 AS bQC_ps2
            FROM 
                QC_check AS qc
            LEFT JOIN 
                bom_ing AS bi ON qc.bom_ing_fid_ref = bi.bom_ing_fid
            LEFT JOIN
                process_no AS pn ON bi.process_no = pn.ProcessNo
            WHERE 
                bi.bom IN ($placeholders)
                AND qc.QC_check = 'QQ'
            ORDER BY 
                bi.bom, bi.bom_sn DESC, qc.QC_check_date DESC
        
        ";
        $stmt_qq_details = $db->prepare($sql_qq_details);
        $stmt_qq_details->execute(array_values($all_boms));
        $qq_details_raw = $stmt_qq_details->fetchAll(PDO::FETCH_ASSOC);

        // ✅ 4. 修改資料映射 (Mapping) 邏輯
        // 將明細分組到 map 中
        foreach ($qq_details_raw as $detail) {
            $qq_details_map[$detail['bom']][] = $detail;
        }
    }

    // ✅ 新增：一次性獲取所有相關的 OK 明細 (for green light popover)
    $ok_details_map = [];
    if (!empty($all_boms)) {
        // ✅ 3. 修改「允收(OK)」備註的 SQL 查詢
        $sql_ok_details = "
            SELECT 
                bi.bom,
                qc.bom_ing_fid_ref,
                qc.QC_check,
                qc.QC_check_date,
                DATE_FORMAT(qc.QC_check_date, '%c/%e') AS qc_date_formatted,
                qc.QC_ok_sqty,
                qc.QC_ps_ok,
                bi.bom_sn,
                pn.ProcessName,
                bi.QC_ps  AS bQC_ps,
                bi.QC_ps2 AS bQC_ps2
            FROM 
                QC_check AS qc
            LEFT JOIN 
                bom_ing AS bi ON qc.bom_ing_fid_ref = bi.bom_ing_fid
            LEFT JOIN
                process_no AS pn ON bi.process_no = pn.ProcessNo
            WHERE 
                bi.bom IN ($placeholders)
                AND qc.QC_check = 'ok'
            ORDER BY 
                bi.bom, bi.bom_sn DESC, qc.QC_check_date DESC
        ";
        $stmt_ok_details = $db->prepare($sql_ok_details);
        $stmt_ok_details->execute(array_values($all_boms));
        $ok_details_raw = $stmt_ok_details->fetchAll(PDO::FETCH_ASSOC);
        // ✅ 4. 修改資料映射 (Mapping) 邏輯
        foreach ($ok_details_raw as $detail) {
            $ok_details_map[$detail['bom']][] = $detail;
        }
    }

    // ✅ 新增：一次性獲取所有相關的出貨紀錄
    $all_d_ids = array_column($OreadyReply_list_base, 'd_id');
    $all_d_ids = array_filter(array_unique($all_d_ids));

    $shipment_history_map = [];
    // ✅ 新版：先用 exec() 重置變數，然後再 prepare/execute SELECT
    if (! empty($all_d_ids)) {
        $placeholders = implode(',', array_fill(0, count($all_d_ids), '?'));

        // 初始化 MySQL user variables
        $db->exec("SET @rn := 0, @prev_prod := ''");

        $sql_shipment_history = "
            SELECT
                il.Product_id,
                il.Qty,
                il.Specification, -- Added
                DATE_FORMAT(il.Order_date, '%Y-%m-%d') AS formatted_date, -- For display 'YYYY-MM-DD'
                DATE_FORMAT(il.Order_date, '%Y-%m-%d') AS shipment_iso_date, -- For JS logic 'YYYY-MM-DD'
                il.Order_date -- Keep original Order_date for PHP sorting
            FROM is_list il
            WHERE il.Product_id IN ({$placeholders})
            ORDER BY il.Product_id, il.Order_date DESC
        ";

        // $init_product_num 若沒用到可以刪掉，避免混亂
        // $db->exec($init_product_num);

        $stmt_shipment_history = $db->prepare($sql_shipment_history);
        $stmt_shipment_history->execute(array_values($all_d_ids));
        $shipment_history_raw = $stmt_shipment_history->fetchAll(PDO::FETCH_ASSOC);

        foreach ($shipment_history_raw as $shipment) {
            $shipment_history_map[$shipment['Product_id']][] = $shipment;
        }
    }

    // ✅ 修正：為每筆 BOM 預先準備好 OrderList (使用 d_setting_id 對應 order_track.d_id_ID)
    $order_list_map = [];
    $all_d_setting_ids_for_orders = array_column($OreadyReply_list_base, 'd_setting_id');
    $all_d_setting_ids_for_orders = array_filter(array_unique($all_d_setting_ids_for_orders));

    if (!empty($all_d_setting_ids_for_orders)) {
        $placeholders_orders = implode(',', array_fill(0, count($all_d_setting_ids_for_orders), '?'));
        $sql_order_list = "
            SELECT
                ot.d_id_ID,
                ot.Order_id, ot.Order_oo,
                DATE_FORMAT(ot.Delivery_date, '%Y-%m-%d') AS Delivery_date,
                (CASE WHEN ot.split_seq = 1 THEN
                    ot.Qty - COALESCE((SELECT SUM(child.Qty) FROM order_track child WHERE child.parent_order_id = ot.Order_id AND child.split_seq > 1), 0)
                 ELSE ot.Qty END) AS Qty,
                ot.Open_Qty,
                COALESCE(ot.Specification, '') AS Specification
            FROM order_track ot
            WHERE ot.d_id_ID IN ($placeholders_orders)
              AND (ot.Order_status IS NULL OR ot.Order_status <> 9)
            ORDER BY ot.d_id_ID, CAST(RIGHT(ot.Order_oo, 10) AS UNSIGNED) ASC
        ";
        $stmt_order_list = $db->prepare($sql_order_list);
        $stmt_order_list->execute(array_values($all_d_setting_ids_for_orders));
        $order_list_raw = $stmt_order_list->fetchAll(PDO::FETCH_ASSOC);

        foreach ($order_list_raw as $order) {
            // 以 d_id_ID (即 d_setting_id) 為 key 建立 map
            $order_list_map[$order['d_id_ID']][] = $order;
        }
    }

    // --- New Logic for Future Reports Check (有新製程報工) ---
    $max_reported_sn_map = [];
    if (!empty($all_boms)) {
        $placeholders_sn = implode(',', array_fill(0, count($all_boms), '?'));
        $sql_max_sn = "
            SELECT bi.bom, MAX(CAST(bi.bom_sn AS UNSIGNED)) as max_sn
            FROM pm_process_daily_report pdr
            JOIN bom_ing bi ON pdr.bom_ing_fid = bi.bom_ing_fid
            WHERE bi.bom IN ($placeholders_sn)
            GROUP BY bi.bom
        ";
        $stmt_max_sn = $db->prepare($sql_max_sn);
        $stmt_max_sn->execute(array_values($all_boms));
        $sn_rows = $stmt_max_sn->fetchAll(PDO::FETCH_ASSOC);
        foreach ($sn_rows as $r) {
            $max_reported_sn_map[$r['bom']] = intval($r['max_sn']);
        }
    }

    // --- 取得每個 BOM 的最新一筆報工資料（用於初始顯示） ---
    $latest_report_data_map = [];
    if (!empty($all_boms)) {
        $ph = implode(',', array_fill(0, count($all_boms), '?'));
        $sql_latest = "
            SELECT 
                bi.bom,
                pdr.report_id,
                pdr.remark,
                pn.ProcessName,
                COALESCE(pdr.production_start_time, pdr.setup_start_time, pdr.report_date) as last_activity_time
            FROM pm_process_daily_report pdr
            JOIN bom_ing bi ON pdr.bom_ing_fid = bi.bom_ing_fid
            LEFT JOIN process_no pn ON pdr.process_no = pn.ProcessNo
            INNER JOIN (
                SELECT bi2.bom, MAX(pdr2.report_id) as max_rid
                FROM pm_process_daily_report pdr2
                JOIN bom_ing bi2 ON pdr2.bom_ing_fid = bi2.bom_ing_fid
                WHERE bi2.bom IN ($ph)
                GROUP BY bi2.bom
            ) lr ON pdr.report_id = lr.max_rid
        ";
        $stmt_latest = $db->prepare($sql_latest);
        $stmt_latest->execute(array_values($all_boms));
        $latest_reports = $stmt_latest->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($latest_reports)) {
            $rids = array_column($latest_reports, 'report_id');
            $ph_rids = implode(',', array_fill(0, count($rids), '?'));
            $sql_ng_details = "
                SELECT ng.report_id, ng.ng_qty, nt.ng_txt, ng.ng_remark
                FROM pm_process_daily_ng ng
                LEFT JOIN ng_txt nt ON ng.ng_id = nt.ng_id
                WHERE ng.report_id IN ($ph_rids)
            ";
            $stmt_ng_details = $db->prepare($sql_ng_details);
            $stmt_ng_details->execute($rids);
            $ng_raw = $stmt_ng_details->fetchAll(PDO::FETCH_ASSOC | PDO::FETCH_GROUP);

            foreach ($latest_reports as $lr) {
                $rid = $lr['report_id'];
                $ng_str_parts = [];
                if (isset($ng_raw[$rid])) {
                    foreach ($ng_raw[$rid] as $n) {
                        $qty    = $n['ng_qty'];
                        $reason = !empty($n['ng_txt']) ? $n['ng_txt'] : '其它';
                        $remark = !empty($n['ng_remark']) ? $n['ng_remark'] : '';
                        $ng_str_parts[] = "{$qty}:::{$reason}:::{$remark}";
                    }
                }
                $latest_report_data_map[$lr['bom']] = [
                    'process' => $lr['ProcessName'],
                    'remark' => $lr['remark'],
                    'ng_info' => implode('|', $ng_str_parts)
                ];
            }
        }
    }

    // ✅ 修正：在處理 OrderList 之前，先將乾淨的出貨紀錄附加到主資料中
    // 這樣可以徹底避免後續迴圈污染 shipment_history 的日期格式
    foreach ($OreadyReply_list_base as &$item_ref) { // 使用引用來直接修改
        // --- Existing Merges ---
        $item_ref['qq_details'] = $qq_details_map[$item_ref['bom']] ?? [];
        $item_ref['ok_details'] = $ok_details_map[$item_ref['bom']] ?? [];
        $item_ref['shipment_history'] = $shipment_history_map[$item_ref['d_id']] ?? [];
        
        // --- Merge PM Report Data ---
        // Aggregate data for the whole BOM (summing up all processes might be misleading if sequential, 
        // but usually 'Processed' on BOM level implies the total output of the *current* or *all* steps.
        // Based on user request: "已加工 則顯示已經加工的總數". We will sum up all reported quantities for this BOM's processes.
        // For Date: Max date of all processes.
        // For Sequence: If no report, use the sequence of the first process (bom_sn=1).
        
        $pm_latest_date = null;
        $pm_total_good = 0;
        $pm_total_ng = 0;
        $pm_has_report = false;
        $pm_first_schedule_order = null;

        if (!empty($item_ref['bom_ing_fid'])) {
            $fids = explode(',', $item_ref['bom_ing_fid']);
            if (isset($fids[0])) {
                $first_fid = trim($fids[0]);
                $pm_first_schedule_order = $pm_schedule_map[$first_fid] ?? null;
            }

            // 使用 bom_all_fids_map 涵蓋所有製程（含新製程報工）
            $all_fids_for_pm = !empty($bom_all_fids_map[$item_ref['bom']]) ? $bom_all_fids_map[$item_ref['bom']] : $fids;
            foreach ($all_fids_for_pm as $fid_val) {
                $fid_val = trim($fid_val);
                if (isset($pm_report_map[$fid_val])) {
                    $rpt = $pm_report_map[$fid_val];
                    $pm_has_report = true;
                    $pm_total_good += (float)$rpt['total_good'];
                    $pm_total_ng += (float)$rpt['total_ng'];
                    if ($pm_latest_date === null || $rpt['latest_date'] > $pm_latest_date) {
                        $pm_latest_date = $rpt['latest_date'];
                    }
                }
            }
        }
        
        $item_ref['pm_has_report'] = $pm_has_report;
        $item_ref['pm_latest_date'] = $pm_latest_date;
        $item_ref['pm_total_processed'] = $pm_total_good + $pm_total_ng; // Processed = Good + NG
        $item_ref['pm_total_ng'] = $pm_total_ng;
        $item_ref['pm_schedule_order'] = $pm_first_schedule_order;

        // ✅ 計算是否所有製程都已完工（用於顯示完工圖示）
        $bom_key = $item_ref['bom'];
        $all_fids_of_bom = $bom_all_fids_map[$bom_key] ?? [];
        $pm_is_all_finished = false;
        if (!empty($all_fids_of_bom) && !empty($finished_fids_set)) {
            $pm_is_all_finished = true;
            foreach ($all_fids_of_bom as $check_fid) {
                if (empty($finished_fids_set[$check_fid])) {
                    $pm_is_all_finished = false;
                    break;
                }
            }
        }
        $item_ref['pm_is_all_finished'] = $pm_is_all_finished;

        // Check for future reports (New Process Reported)
        $current_sn = 0;
        if (!empty($item_ref['bom_sn'])) {
            // bom_sn is comma separated string, find the max one as current (if multiple processes are active)
            $sns = explode(',', $item_ref['bom_sn']);
            foreach ($sns as $s) {
                $val = intval($s);
                if ($val > $current_sn) {
                    $current_sn = $val;
                }
            }
        }
        $max_sn = $max_reported_sn_map[$item_ref['bom']] ?? 0;
        $item_ref['has_new_process_report'] = ($max_sn > $current_sn);
        $item_ref['has_any_pm_report'] = isset($max_reported_sn_map[$item_ref['bom']]);

        // Add leave status
        $item_ref['IsPrimaryOnLeave'] = !empty($item_ref['PrimarySalesId']) && in_array(intval($item_ref['PrimarySalesId']), $users_on_leave_ids, true);
        $item_ref['IsDeputyOnLeave'] = !empty($item_ref['DeputySalesId']) && in_array(intval($item_ref['DeputySalesId']), $users_on_leave_ids, true);

        $latest_info = $latest_report_data_map[$item_ref['bom']] ?? null;
        $item_ref['latest_report_process'] = $latest_info['process'] ?? '';
        $item_ref['latest_report_remark']  = $latest_info['remark'] ?? '';
        $item_ref['latest_ng_info_str']    = $latest_info['ng_info'] ?? '';
    }
    unset($item_ref); // 斷開引用

    foreach ($OreadyReply_list_base as $item) {
        $item['OrderList'] = $order_list_map[$item['d_setting_id']] ?? [];
        $OreadyReply_list_final[] = $item;
    }
}

@$BOM           = $_GET['b'];
@$ProcessNo     = $_GET['pn'];
@$MakerId       = $_GET['mi'];
@$sqty          = $_GET['s'];
@$d_id          = $_GET['d'];
@$D_Setting_Id  = $_GET['d'];
@$Client_Name   = $_GET['c'];


if (!empty($BOM)) {
    // 已報工紀錄
    @$PmOreadyReply_list = $conn->getAll("SELECT bom.d_id,vw_oreadyreply_list.reply_id,vw_oreadyreply_list.BOM,vw_oreadyreply_list.oready_sqty,
  date(vw_oreadyreply_list.Created_At) as Created_date,vw_oreadyreply_list.Created_At as Created_date_ORDER,vw_oreadyreply_list.Created_By,
  vw_oreadyreply_list.ok_sqty,vw_oreadyreply_list.ng_sqty_total,user.user_cname,vw_oreadyreply_list.ps,
  vw_oreadyreply_list.m,vw_oreadyreply_list.t,vw_oreadyreply_list.width,vw_oreadyreply_list.mc_id,vw_oreadyreply_list.mc_time,
  vw_oreadyreply_list.processing_time,machine_list.machine,vw_oreadyreply_list.mc_user,
  vw_oreadyreply_list.sqty,
  vw_oreadyreply_list.oready_sqty,vw_oreadyreply_list.ProcessName,vw_oreadyreply_list.ProcessNo,vw_oreadyreply_list.MakerId
  FROM vw_oreadyreply_list
  LEFT JOIN user ON user.id=vw_oreadyreply_list.Created_By
  LEFT JOIN machine_list ON vw_oreadyreply_list.machine_id=machine_list.machine_id
  LEFT JOIN bom ON bom.bom=vw_oreadyreply_list.BOM
  WHERE vw_oreadyreply_list.BOM='$BOM' AND vw_oreadyreply_list.ProcessNo='$ProcessNo'
  ORDER BY Created_date_ORDER DESC");
}

// Fetch workdays for current and next year for JavaScript calculations
$workdays_stmt_php = null;
$js_workdays_list_php = [];
if (isset($db) && $db instanceof PDO) {
    try {
        // 定義查詢範圍 (例如前後 2 年)
        $startYear = date("Y") - 1;
        $endYear = date("Y") + 2;
        $startDate = "$startYear-01-01";
        $endDate = "$endYear-12-31";

        // 查詢例外事件 (s=休假日, m=補班/工作日)
        // 修正：evenement.category_id 對應 event_category.id
        $sql = "
            SELECT e.start, e.end, ec.day_type
            FROM evenement e
            JOIN event_category ec ON e.category_id = ec.id
            WHERE ec.day_type IN ('s', 'm')
            AND e.start <= :end_date AND e.end >= :start_date
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute([':start_date' => $startDate, ':end_date' => $endDate]);
        $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 建立例外日期對照表
        $exceptions = [];
        foreach ($events as $ev) {
            $s = new DateTime($ev['start']);
            $e = new DateTime($ev['end']);
            // 假設結束日期是包含的 (視 fullcalendar 實作而定，若不包含需調整)
            // 這裡假設資料庫存的是 YYYY-MM-DD 且為包含區間
            while ($s <= $e) {
                $exceptions[$s->format('Y-m-d')] = $ev['day_type'];
                $s->modify('+1 day');
            }
        }

        // 產生工作日列表
        $current = new DateTime($startDate);
        $end = new DateTime($endDate);
        while ($current <= $end) {
            $ymd = $current->format('Y-m-d');
            $dayOfWeek = $current->format('N'); // 1(Mon) - 7(Sun)
            $type = $exceptions[$ymd] ?? null;

            $isWorkday = false;
            if ($type === 'm') {
                $isWorkday = true; // 補班/工作日
            } elseif ($type === 's') {
                $isWorkday = false; // 休假日
            } else {
                $isWorkday = ($dayOfWeek < 6); // 預設：週一至週五為工作日
            }

            if ($isWorkday) {
                $js_workdays_list_php[] = $ymd;
            }
            $current->modify('+1 day');
        }

    } catch (PDOException $e) {
        error_log("Error fetching workdays in OreadyReply_ForPm_BaseOfTime2.php: " . $e->getMessage());
    }
} else {
    error_log("DB connection not available for fetching workdays in OreadyReply_ForPm_BaseOfTime2.php.");
}

// --- Fetch PTI filter button settings ---
$pti_filter_saved = [];
$process_type_list = [];
$pti_process_map   = []; // process_type_id => [ProcessNo, ...]
try {
    $pt_rows = $conn->getAll("SELECT process_type_id, process_type FROM process_type ORDER BY process_type_id ASC");
    if ($pt_rows) $process_type_list = $pt_rows;
    $pti_param = $conn->getOne("SELECT param_value FROM system_parameters WHERE param_group = 'OreadyReply_PM' AND param_key = 'pti_filter_buttons' LIMIT 1");
    if ($pti_param && !empty($pti_param['param_value'])) {
        $decoded_pti = json_decode($pti_param['param_value'], true);
        if (is_array($decoded_pti)) $pti_filter_saved = $decoded_pti;
    }
    // 製程類別 → 製程對應表
    try {
        $map_rows = $conn->getAll("SELECT process_type_id, process_no_id FROM process_type_process_map ORDER BY sort_order, id");
        if ($map_rows) {
            foreach ($map_rows as $mr) {
                $ptId = (string)$mr['process_type_id'];
                if (!isset($pti_process_map[$ptId])) $pti_process_map[$ptId] = [];
                $pti_process_map[$ptId][] = (string)$mr['process_no_id'];
            }
        }
    } catch (Exception $e2) {
        // 表尚未建立時忽略
    }
} catch (Exception $e) {
    error_log("Error fetching PTI filter settings: " . $e->getMessage());
}

// --- Fetch System Parameters for Light Settings ---
$light_settings_php = ['yellow' => '', 'red' => '', 'process' => '', 'show_workday' => false, 'red_days_before' => '', 'buffer_mode' => false];
try {
    $system_params = $conn->getAll("SELECT param_key, param_value FROM system_parameters WHERE param_group = 'BOM_SETTING'");
    if ($system_params) {
        foreach ($system_params as $param) {
            if ($param['param_key'] === 'light-control-sd') {
                $val = json_decode($param['param_value'], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $light_settings_php['yellow'] = $val['yellow'] ?? '';
                    $light_settings_php['red'] = $val['red'] ?? '';
                    $light_settings_php['red_days_before'] = $val['red_days_before'] ?? '';
                }
            } elseif ($param['param_key'] === 'process_day') {
                $val = json_decode($param['param_value'], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    if (is_array($val)) {
                        $light_settings_php['process'] = $val['day'] ?? ($val['days'] ?? ($val['process'] ?? ''));
                    } else {
                        $light_settings_php['process'] = $val;
                    }
                }
            } elseif ($param['param_key'] === 'show_workday') {
                $val = json_decode($param['param_value'], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $light_settings_php['show_workday'] = $val['show'] ?? false;
                }
            } elseif ($param['param_key'] === 'buffer_mode') {
                $val = json_decode($param['param_value'], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $light_settings_php['buffer_mode'] = $val['enabled'] ?? false;
                }
            }
        }
    }
} catch (Exception $e) {
    error_log("Error fetching system parameters: " . $e->getMessage());
}

// --- 將準備好的資料嵌入到 JavaScript ---
// 移除重量級子陣列，改為前端按需 AJAX 撈取
$OreadyReply_list_slim = [];
foreach ($OreadyReply_list_final ?: [] as $row) {
    unset($row['shipment_history'], $row['qq_details'], $row['ok_details']);
    $OreadyReply_list_slim[] = $row;
}
echo "<script>\n";
echo "    window.initialFullDataset = " . json_encode($OreadyReply_list_slim) . ";\n";
echo "    window.initialBomPSList = " . json_encode($bom_ps_list ?: []) . ";\n";
echo "    window.initialMaxCount = " . json_encode((int)($bom_ps_list_max ?: 0)) . ";\n";
echo "    window.transferPriceMap = " . json_encode($transfer_price_map ?: []) . "; // [bom][bom_sn] 最新單價\n";
echo "    window.ingActiveMap = " . json_encode($bom_ing_active_map ?: (object)[]) . "; // [bom] => [{per-process active data}] 供發單日欄位使用\n";
echo "    window.transferHistoryMap = " . json_encode($transfer_history_map ?: []) . "; // [product_id][bom_sn] 同料號歷史\n";
echo "    window.currentUserStatus = " . json_encode($user_status ?? null) . ";\n";
echo "    window.canCreate = " . json_encode($can_create) . ";\n";
echo "    window.canUpdate = " . json_encode($can_update) . ";\n";
echo "    window.canDelete = " . json_encode($can_delete) . ";\n";
echo "    window.isCRU = " . json_encode((bool)$_is_cru) . "; // 業務類權限 (R+U / C+R+U / C+D+R+U) → 隱藏移轉按鈕\n";
echo "    window.canManualClose = " . json_encode((bool)$can_manual_close) . "; // 含D或A才可人工結案\n";
echo "    window.isRD = " . json_encode($display_permission_code === 'D+R') . "; // R+D受限業務：人工結案需二次輸入Y確認\n";
// 純功能碼旗標（不含任何舊規則），JS端在各按鈕原本判斷式後面自行 OR，不取代原判斷式
echo "    window.featMarkReturned = " . json_encode((bool)$oready_feat_mark_returned) . "; // 功能碼 oready_mark_returned\n";
echo "    window.featTransfer = " . json_encode((bool)$oready_feat_transfer) . "; // 功能碼 oready_transfer\n";
echo "    window.featBatchOp = " . json_encode((bool)$oready_feat_batch) . "; // 功能碼 oready_batch_split_merge\n";
echo "    window.featSeePrice = " . json_encode((bool)$oready_feat_view_price) . "; // 功能碼 oready_view_price\n";
echo "    window.oreadyIsAdmin = " . json_encode($permission_code === 'A') . "; // 目前權限=A者可開啟角色功能設定\n";
echo "    window.globalWorkdaysList = " . json_encode($js_workdays_list_php) . "; // Workday list for JS\n";
echo "    window.initialLightSettings = " . json_encode($light_settings_php) . ";\n";
echo "    window.bufferModeEnabled = " . json_encode((bool)$light_settings_php['buffer_mode']) . ";\n";
echo "    window._bufferCache = {};\n";
echo "    window._urgentCache = {};\n";
echo "    window.displayPermissionCode = " . json_encode($display_permission_code) . ";\n";
echo "    window.ptiFilterSaved = " . json_encode($pti_filter_saved) . ";\n";
echo "    window.processTypeList = " . json_encode($process_type_list) . ";\n";
echo "    window.ptiProcessMap   = " . json_encode($pti_process_map) . "; // {process_type_id:[ProcessNo,...]}\n";
// 讀取例外內製製程
$internal_pt_saved = [];
try {
    $ipt_row = $conn->getOne("SELECT param_value FROM system_parameters WHERE param_group='BOM_SETTING' AND param_key='internal_process_types' LIMIT 1");
    if ($ipt_row && !empty($ipt_row['param_value'])) {
        $decoded_ipt = json_decode($ipt_row['param_value'], true);
        if (is_array($decoded_ipt)) $internal_pt_saved = array_map('intval', $decoded_ipt);
    }
} catch (Exception $e) { error_log("Error fetching internal_process_types: " . $e->getMessage()); }
echo "    window.internalProcessTypes = " . json_encode($internal_pt_saved) . ";\n";

$execution_time = round(microtime(true) - $execution_start_time, 4);
// console.log 僅在開發期啟用，正式環境關閉
// echo "    console.log('PHP 執行時間: {$execution_time} 秒');\n";
$_av = filemtime(__FILE__); // 靜態資源版本號，檔案修改時自動更新
echo "</script>\n";

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <!-- Meta, title, CSS, favicons, etc. -->
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>BOM 總覽</title>

    <!-- Bootstrap -->
    <link href="../../resource/css/bootstrap.css?v=<?=$_av?>" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="../../resource/css/font-awesome.css?v=<?=$_av?>" rel="stylesheet">
    <!-- NProgress -->
    <link href="../../resource/css/nprogress.css?v=<?=$_av?>" rel="stylesheet">
    <!-- Custom Theme Style -->
    <link href="../../resource/css/custom.css?v=<?=$_av?>" rel="stylesheet">
    <!-- Datatables -->
    <link href="../../resource/css/buttons.bootstrap.css?v=<?=$_av?>" rel="stylesheet">
    <link href="../../resource/css/fixedHeader.bootstrap.css?v=<?=$_av?>" rel="stylesheet">
    <link href="../../resource/css/scroller.bootstrap.css?v=<?=$_av?>" rel="stylesheet">
    <!-- 過長表格變+號 -->
    <link href="../../resource/css/dataTables.bootstrap.css?v=<?=$_av?>" rel="stylesheet">
    <link href="../../resource/css/responsive.bootstrap.css?v=<?=$_av?>" rel="stylesheet">
    <!-- 引入 jQuery 與 Select2 的 CSS 與 JS -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.0.13/dist/css/select2.min.css" rel="stylesheet" />
    <!-- 引入 html2canvas 按鈕部分-->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <!-- jQuery UI CSS (用於 Datepicker) -->
    <link rel="stylesheet" href="https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css">

</head>
<style>
    .btn-xss {
        font-size: 8px;
        /* 調整字體大小 */
    }

    #table-DOWN td {
        overflow: hidden;
        /* 隱藏溢出內容 */
        text-overflow: ellipsis;
        /* 當內容過多時顯示省略號 */
    }

    .adjustable-font-size {
        font-size: calc(10px + 0.5vw);
        /* 根據視窗寬度調整字體大小 */
    }

    #table-DOWN {
        width: 100%;
        table-layout: auto;
    }

    #table-DOWN th,
    #table-DOWN td {
        padding-left: 5px;
        /* 左邊內間距 */
        padding-right: 5px;
        /* 右邊內間距 */
        white-space: nowrap;
        /* 強制不換行 */
    }

    .control-label {
        margin: 0;
        /* 移除 margin */
    }

    .control-label div {
        display: inline-flex;
        /* 使 div 元素與文字排列 */
        align-items: center;
        /* 垂直居中 */
    }

    .control-label div figure {
        margin-right: 8px;
        /* 設定與文本間的距離 */
    }

    /* 球燈 */
    .circle_red {
        display: block;
        background: #cd5c5c;
        /* 印度紅 */
        border-radius: 50%;
        height: 18px;
        width: 18px;
        margin: 0;
        background: radial-gradient;
    }

    .circle_green {
        display: block;
        background: MediumSeaGreen;
        /* 中海綠 */
        border-radius: 50%;
        height: 18px;
        width: 18px;
        margin: 0;
        background: radial-gradient;
    }

    .circle_y {
        display: block;
        background: #FFD306;
        /* 黃 */
        border-radius: 50%;
        height: 18px;
        width: 18px;
        margin: 0;
        background: radial-gradient;
    }

    /* 發單日燈號 */
    .circle_greenS {
        display: block;
        background: MediumSeaGreen;
        /* 中海綠 */
        border-radius: 50%;
        height: 15px;
        width: 15px;
        margin: 0;
        background: radial-gradient;
    }

    .circle_yo {
        display: block;
        background: #FFD306;
        /* 黃 */
        border-radius: 50%;
        height: 15px;
        width: 15px;
        margin: 0;
        background: radial-gradient;
    }

    .circle_gray {
        display: block;
        background: radial-gradient(circle, #C0C0C0 30%, #A0A0A0 100%);
        /* Silver/Gray gradient */
        border-radius: 50%;
        height: 15px;
        width: 15px;
        margin: 0;
        background: radial-gradient;
    }

    /* New style for the light container to align items */
    .status-lights-container {
        display: flex;
        align-items: center;
        margin-top: 5px;
    }


    table {
        width: 100%;
        border-collapse: collapse;
    }

    th,
    td {
        border: 1px solid #dddddd;
        padding: 8px;
        /* 保留原有的 padding */
        /* text-align: left;  由下方更具體的規則控制 */
    }

    thead th {
        position: sticky;
        top: 0;
        background-color: white;
        z-index: 1;
        text-align: center !important;
        /* 強化表頭全部置中 */
        vertical-align: middle !important;
        /* 確保垂直也置中 */
    }

    .title {
        display: flex;
        flex-wrap: wrap;
    }

    .title a {
        margin: 5px;
    }

    @media (max-width: 600px) {
        .title a {
            flex: 0 1 calc(33.333% - 10px);
        }
    }

    @media (max-width: 400px) {
        .title a {
            flex: 0 1 calc(50% - 10px);
        }
    }

    /* 表格內多段篩選 */
    /* 整體篩選外框 */
    .all-filters {
        border: 1px solid #ccc;
        border-radius: 3px;
        padding: 4px;
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 4px;
        margin-bottom: 10px;
    }

    /* 所有篩選欄皆採用同一樣式 */
    .all-filters button,
    .all-filters input,
    .all-filters select {
        height: 26px;
        /* 與車床按鈕接近（可依需求微調） */
        font-size: 10px;
        /* 與上方 btn-xs 同大小 */
        line-height: 1;
        padding: 0 4px;
        border: 1px solid #ccc;
        border-radius: 3px;
    }

    /* 額外 class，可給動態 select 用 */
    .filter-select {
        height: 26px;
        font-size: 10px;
        padding: 2px 6px;
        line-height: 1.2;
        border: 1px solid #ccc;
        border-radius: 3px;
        background-color: #fff;
    }


    .all-filters button {
        background-color: #337ab7;
        color: #fff;
        cursor: pointer;
    }

    /* 表格與原有樣式 */
    #table-DOWN {
        width: 100%;
        table-layout: auto;
        border-collapse: collapse;
    }

    #table-DOWN th,
    #table-DOWN td {
        padding-left: 5px;
        padding-right: 5px;
        white-space: nowrap;
        border: 1px solid #dddddd;
        box-sizing: border-box;
        /* 確保寬度計算包含 padding 和 border */
        font-size: 13px;
        /* 表格文字大小改為 13px */
        text-align: left;
        /* 維持大部分 td 左對齊 */
    }

    #table-DOWN td {
        overflow: hidden;
        text-overflow: ellipsis;
    }

    thead th {
        position: sticky;
        top: 0;
        background-color: white;
        text-align: center !important;
        /* 強化表頭全部置中 (再次確認) */
        z-index: 1;
    }

    .table-wrapper {
        overflow-x: auto;
        /* max-height: 400px; */

    }

    /* BOM 色彩篩選按鈕：設為 18px 圓形，並加入 relative 定位供 tooltip 絕對定位 */
    #bomColorFilter {
        position: relative;
        width: 18px;
        height: 18px;
        padding: 0;
        border-radius: 50%;
        display: flex;
        justify-content: center;
        align-items: center;
        background-color: #337ab7;
        color: #fff;
        cursor: pointer;
        font-size: 8px;
    }

    /* Tooltip 基本樣式：利用 visibility 與 opacity 控制顯示 */
    #bomColorFilter .tooltip {
        visibility: hidden;
        opacity: 0;
        position: absolute;
        top: 22px;
        /* 位於按鈕下方 */
        left: 50%;
        transform: translateX(-50%);
        background-color: #fff;
        border: 1px solid #ccc;
        border-radius: 4px;
        padding: 8px;
        /* 增加 tooltip 內邊距 */
        font-size: 10px;
        /* 稍微放大 tooltip 整體字型 */
        white-space: nowrap;
        z-index: 10;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2);
        transition: opacity 0.2s ease-in-out;
        color: #000000;
        /* 將文字顏色調整為黑色 */
        /* 說明文字為黑色 */
    }

    /* 當滑鼠懸停於 BOM 按鈕時顯示 tooltip */
    #bomColorFilter:hover .tooltip {
        visibility: visible;
        opacity: 1;
    }

    /* tooltip 內部的內容，採用控制標籤的 inline-flex 方式對齊 */
    .tooltip-content .control-label {
        margin: 0;
        /* 參照原有 control-label */
    }

    .tooltip-content .control-label div {
        display: inline-flex;
        align-items: center;
    }

    .tooltip-content .control-label div figure {
        margin-right: 8px;
        /* 與文本間的距離，參考原有設定 */
    }

    /* 明確設定 tooltip 內說明文字 span 的顏色為黑色 */
    #bomColorFilter .tooltip .tooltip-content span {
        color: #000000;
        /* 維持黑色 */
        font-size: 10px;
        /* 明確設定說明文字大小 */
    }

    /* 使 h2 中的 small 換行且與 h2 的內容左對齊 */
    .x_title h2 small {
        display: block;
        text-align: left;
        margin-left: 0;
        /* 可依需要調整字型大小、顏色等 */
        font-size: 12px;
    }

    /* 分頁控制樣式 */
    .pagination-controls {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 10px;
        padding: 5px;
        background-color: #f9f9f9;
        border-radius: 4px;
    }

    .pagination-info {
        font-size: 14px;
    }

    .pagination-buttons button {
        padding: 5px 10px;
        margin: 0 3px;
        background-color: #337ab7;
        color: white;
        border: none;
        border-radius: 3px;
        cursor: pointer;
    }

    .pagination-buttons button:disabled {
        background-color: #cccccc;
        cursor: not-allowed;
    }

    .page-selector {
        margin: 0 5px;
    }

    .records-per-page {
        margin-left: 10px;
    }

    /* 目前製程變色 */
    .highlight-process {
        font-weight: bold;
        color: #007bff;
        /* Bootstrap 藍 */
        background-color: #e6f2ff;
        /* 淺藍背景 */
    }

    td.process-col {
        min-width: 60px;
        /* 保持最小寬度 */
        padding: 2px;
        white-space: normal;
        /* 允許換行 */
        overflow: visible;
        /* 允許內容溢出（如果需要）*/
        /* 或使用 clamp() 根據容器自適應 */
        text-align: center;
        font-size: clamp(7px, 1vw, 12px);
        line-height: 1.2;
        /* Adjust line height for multi-line content */
    }

    /* 針對「交期x數量」欄位內的下拉選單樣式已移除（功能廢棄） */


    /* --- Start: Styles for compact "交期x數量(未交)" display --- */
    /* Hide the <br> tag in the "交期x數量(未交)" column */
    #table-DOWN td[name="Delivery_date"] br {
        display: none;
    }

    /* Make the first span a block element to force a new line and control its line height */
    #table-DOWN td[name="Delivery_date"] span.delivery-text-display {
        display: block;
        line-height: 1;
        /* Minimal line height for tight spacing */
        margin-bottom: 0;
        /* Ensure no extra space below it */
        padding: 0;
        /* Remove any default padding */
    }

    /* Ensure the divs inside process-col don't add extra space */
    .process-col div {
        margin: 0;
        padding: 0;
        line-height: 1.2;
        /* Match parent line height */
    }

    .process-col .shrink-font {
        font-size: 10px;
    }

    /* --- Select Separator Style (for status-filter) --- */
    #status-filter option[value^="---sep"] {
        font-size: 1px !important;
        color: transparent !important;
        background-color: #ccc !important;
        height: 1px !important;
        display: block !important;

    }

    /* --- Datalist Separator Style --- */
    datalist option[value="---separator---"] {
        font-size: 1px;
        /* Make it very small */
        color: transparent;
        /* Hide the text */
        background-color: #ccc;
        /* Separator line color */
        height: 1px;
        /* Separator line height */
        display: block;
        /* Ensure it takes full width */
    }

    /* --- Sticky Header --- */
    #table-DOWN thead th {
        position: -webkit-sticky;
        /* Safari */
        position: sticky;
        top: 0;
        /* 黏在頂部 */
        background-color: #f8f9fa;
        /* 設置背景色以覆蓋下方滾動內容 */
        z-index: 2;
        /* 確保表頭在滾動內容之上 */
        font-size: 14px;
        /* 表頭文字放大 1px (13px + 1px) */
        /* 確保表頭在滾動內容之上 */
    }

    /* --- Sticky Left Columns --- */

    /* 第 1 欄：客戶 */
    #table-DOWN th:nth-child(1),
    #table-DOWN td:nth-child(1) {
        position: sticky;
        left: 0;
        background-color: #ffffff;
        z-index: 1;
        min-width: 90px;
        /* 保持最小寬度，但允許內容擴展 */
    }

    /* 讓裡面的 select 寬度填滿 */
    #table-DOWN td:nth-child(2) select {
        width: 100%;
    }

    /* 第 2 欄：交期x數量 */
    #table-DOWN th:nth-child(2) {
        position: sticky;
        top: 0;
        /* 保持頂部固定 */
        left: 90px;
        /* 此值將需要根據實際內容調整 */
        /* width: 100px; */
        /* 移除固定寬度 */
        min-width: 110px;
        background-color: #ffffff;
        z-index: 1;
    }

    #table-DOWN td:nth-child(2) {
        position: sticky;
        left: 90px;
        /* 改為第一欄的新寬度 */
        /* width: 100px; */
        /* 移除固定寬度 */
        min-width: 110px;
        /* 保持最小寬度，但允許內容擴展 */
        background-color: #ffffff;
        z-index: 1;
        overflow: visible !important;
    }

    /* 第 3 欄：BOM */
    #table-DOWN th:nth-child(3) {
        position: sticky;
        top: 0;
        /* 保持頂部固定 */
        left: 200px;
        /* 此值將需要根據實際內容調整 */
        min-width: 150px;
        background-color: #ffffff;
        z-index: 1;
    }

    #table-DOWN td:nth-child(3) {
        position: sticky;
        left: 200px;
        /* 80px + 100px */
        /* width: 150px; */
        /* 移除固定寬度 */
        min-width: 150px;
        /* 保持最小寬度，但允許內容擴展 */
        background-color: #ffffff;
        z-index: 1;
        overflow: visible !important;
    }

    /* 第 4 欄：料號 */
    #table-DOWN th:nth-child(4) {
        position: sticky;
        top: 0;
        /* 保持頂部固定 */
        left: 350px;
        /* 此值將需要根據實際內容調整 */
        width: 220px;
        min-width: 220px;
        background-color: #ffffff;
        z-index: 1;
    }

    #table-DOWN td:nth-child(4) {
        position: sticky;
        left: 350px;
        /* 此值將需要根據實際內容調整 */
        /* width: 220px; */
        /* 移除固定寬度 */
        min-width: 220px;
        background-color: #ffffff;
        z-index: 2;
        /* 料號欄內容疊在左側欄位之上 */
        overflow: visible !important;
    }

    /* 第 5 欄：發單日 */
    #table-DOWN th:nth-child(5) {
        position: sticky;
        top: 0;
        /* 保持頂部固定 */
        left: 570px;
        /* 此值將需要根據實際內容調整 */
        width: 120px;
        min-width: 120px;
        background-color: #ffffff;
        z-index: 1;
        text-align: right;
        padding-right: 10px;
        /* Added more specific right padding */
    }

    #table-DOWN td:nth-child(5) {
        position: sticky;
        left: 570px;
        /* 此值將需要根據實際內容調整 */
        /* width: 120px; */
        /* 移除固定寬度 */
        min-width: 120px;
        background-color: #ffffff;
        z-index: 1;
        text-align: right;
        padding-right: 10px;
        /* Added more specific right padding */
        overflow: visible !important;
    }

    /* 第 6 欄：製程 */
    #table-DOWN th:nth-child(6) {
        position: sticky;
        top: 0;
        /* 保持頂部固定 */
        left: 690px;
        /* 此值將需要根據實際內容調整 */
        width: 100px;
        /* Matches min-width */
        min-width: 100px;
        background-color: #ffffff;
        /* Will be overridden by more specific thead th rule */
        z-index: 1;
    }

    #table-DOWN td:nth-child(6) {
        position: sticky;
        left: 690px;
        /* 此值將需要根據實際內容調整 */
        width: 100px;
        /* Matches min-width */
        min-width: 100px;
        background-color: #ffffff;
        z-index: 1;
        overflow: visible !important;
        /* For consistency if content might overflow */
    }

    /* 讓前五個欄位自動依內容調整寬度，並允許內容換行 */
    #table-DOWN th:nth-child(1),
    #table-DOWN td:nth-child(1),
    #table-DOWN th:nth-child(2),
    #table-DOWN td:nth-child(2),
    #table-DOWN th:nth-child(3),
    #table-DOWN td:nth-child(3),
    #table-DOWN th:nth-child(4),
    #table-DOWN td:nth-child(4),
    #table-DOWN th:nth-child(5),
    #table-DOWN td:nth-child(5) {
        width: auto;
        /* 讓寬度自動調整 */
        /* min-width 保持，確保不會過窄 */
        white-space: normal;
        /* 允許內容換行 */
        overflow: visible;
        /* 讓內容可見，不截斷 */
        text-overflow: clip;
        /* 移除省略號 */
    }

    /* 第 7 欄：廠商 */
    #table-DOWN th:nth-child(7),
    #table-DOWN td:nth-child(7) {
        min-width: 100px;
    }

    /* 第 8 欄：發單數 */
    #table-DOWN th:nth-child(8),
    #table-DOWN td:nth-child(8) {
        min-width: 60px;
        text-align: center;
        /* 發單數置中 - remains center */
    }

    /* 第 9 欄：備註 */
    #table-DOWN th:nth-child(9),
    #table-DOWN td:nth-child(9) {
        min-width: 150px;
        border-right: 2px solid #dee2e6;
        /* 保持最後一欄的右邊框 */
    }

    /* --- 確保固定表頭 z-index 正確 --- */
    /* Only apply z-index: 3 to the horizontally sticky header cells (1-5) */
    /* Extended to column 6 */
    #table-DOWN thead th:nth-child(1),
    #table-DOWN thead th:nth-child(2),
    #table-DOWN thead th:nth-child(3),
    #table-DOWN thead th:nth-child(4),
    #table-DOWN thead th:nth-child(5),
    #table-DOWN thead th:nth-child(6) {
        z-index: 3;
        background-color: #f8f9fa;
    }

    /* For columns 6-9, their thead th will rely on the general z-index: 2 
       from the '#table-DOWN thead th' rule for top stickiness, 
       but won't have the higher z-index needed for horizontal stickiness overlap.
       Their background will also come from the general '#table-DOWN thead th' rule.
    */

    /* --- 3. 解決固定欄位背景色不一致 (包含 Hover 顏色修改) --- */

    /* 確保固定欄位的預設背景是白色 */
    #table-DOWN td:nth-child(1),
    #table-DOWN td:nth-child(2),
    #table-DOWN td:nth-child(3),
    #table-DOWN td:nth-child(4),
    #table-DOWN td:nth-child(5),
    #table-DOWN td:nth-child(6),
    #table-DOWN td:nth-child(7),
    #table-DOWN td:nth-child(8),
    #table-DOWN td:nth-child(9) {
        background-color: #ffffff;
        /* 確保預設是白色 */
    }

    /* 設定新的 Hover 顏色 */
    #table-DOWN tbody tr:hover {
        background-color: #F0F5D6;
        /*  更淡的懸停顏色 */
    }

    /* 將新的 Hover 效果同步應用到固定的 td */
    #table-DOWN tbody tr:hover td:nth-child(1),
    #table-DOWN tbody tr:hover td:nth-child(2),
    #table-DOWN tbody tr:hover td:nth-child(3),
    #table-DOWN tbody tr:hover td:nth-child(4),
    #table-DOWN tbody tr:hover td:nth-child(5),
    #table-DOWN tbody tr:hover td:nth-child(6),
    #table-DOWN tbody tr:hover td:nth-child(7),
    #table-DOWN tbody tr:hover td:nth-child(8),
    #table-DOWN tbody tr:hover td:nth-child(9) {
        background-color: #F0F5D6;
        /*  更淡的懸停顏色 */
    }

    .date-multiline {
        font-size: 0.85em;
        /* 稍微縮小整體字體以便容納兩行 */
        line-height: 1.1;
        /* 調整行高讓兩行更緊湊 */
        display: inline-block;
        /* 讓內容塊化，以便更好地控制 */
    }

    /* --- Copy Button Style --- */
    .btn-copy {
        background-color: #f0ad4e;
        /* Yellow background */
        color: white;
        /* White icon/text */
        border: none;
        margin-left: 5px;
        padding: 1px 2px;
        /* Adjust padding */
        vertical-align: middle;
        /* Align with text */
        cursor: pointer;
    }

    /* Add style for "Show Processes" button */
    #show-processes-btn {
        margin-left: 10px;
        /* Keep spacing from the previous button */
        /* Background color will be inherited from .all-filters button */
    }

    .btn-return-style {
        padding-left: 3px;
        /* Adjust horizontal padding for the text "已回" */
        padding-right: 3px;
        font-size: 10px;
        /* Max font size for 10px content height with line-height 1 */
        line-height: 1;
        /* To achieve 10px content height, matching btn-copy effectively */
    }

    /* CSS for individual column wrappers */
    .edit-form-column-wrapper {
        border: 1.5px solid #ccc;
        /* Adapted from your .all-type > .all-filters */
        border-radius: 8px;
        /* Adapted from your .all-type > .all-filters */
        padding: 10px;
        /* Adapted from your .all-type > .all-filters */
        margin-bottom: 10px;
        /* Adapted from your .all-type > .all-filters */
        box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        /* Adapted from your .all-type > .all-filters */
        background-color: #fdfdfd;
        /* Light background for the boxes */
    }

    /* New styles based on user request */
    .all-type {
        display: flex;
        /* Use Flexbox for horizontal arrangement */
        flex-wrap: wrap;
        /* Allow wrapping to the next line */
        gap: 10px;
        /* Space between the .all-filters boxes */
        /* justify-content: space-between; /* Distribute boxes evenly, or remove for left-align */
        margin-bottom: 10px;
        /* Space below the .all-type container */
    }

    /* Styles for each .all-filters box within an .all-type container */
    .all-type>.all-filters {
        border: 1.5px solid #ccc;
        border-radius: 8px;
        padding: 10px;
        box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
        display: flex;
        flex-direction: column;
        /* Items inside this box stack vertically */
        gap: 5px;
        /* Space between items inside this box */
        flex: 1 1 calc(33.333% - 10px);
        /* Aim for three columns, adjusting for gap. (10px is an example, (gap*2)/3 for 3 items) */
        box-sizing: border-box;
        min-width: 200px;
        /* Prevent boxes from becoming too narrow */
    }

    /* Responsive design for .all-type layout */
    @media screen and (max-width: 992px) {

        /* Tablet and larger mobile */
        .all-type>.all-filters {
            flex: 1 1 calc(50% - 10px);
            /* Two columns */
        }
    }

    /* User's specific responsive layout for 768px */
    @media screen and (max-width: 768px) {
        .all-type {
            /* If .all-type becomes column, its children (.all-filters) will stack vertically regardless of their flex-basis.
           To achieve the 1st and 3rd side-by-side, .all-type must remain flex-direction: row and use flex-wrap.
        */
            /* display: flex; /* Already flex */
            /* flex-direction: column; /* User's original: this will stack all .all-filters items vertically */
            /* gap: 5px; /* Already has gap */
        }

        /* To make 1st and 3rd side-by-side, and 2nd full-width, .all-type needs to be row & wrap */
        .all-type>.all-filters:nth-child(1),
        .all-type>.all-filters:nth-child(3) {
            /* display: flex; /* .all-filters is already display:flex for its internal content */
            flex: 1 1 calc(50% - 5px);
            /* Adjust basis for 2 items, considering parent gap */
            /* box-sizing: border-box; /* Already set */
        }

        .all-type>.all-filters:nth-child(2) {
            /* display: flex; /* .all-filters is already display:flex for its internal content */
            flex: 1 1 100%;
            /* Takes full width */
            /* box-sizing: border-box; /* Already set */
        }
    }

    /* Style for .all-filters2 (alternative filter bar style) */
    .all-filters2 {
        border: 1px solid #ccc;
        border-radius: 3px;
        padding: 4px;
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 4px;
        margin-bottom: 10px;
    }

    .all-filters2 input,
    .all-filters2 select,
    .all-filters2 button {
        /* Combined for consistency */
        height: 26px;
        font-size: 10px;
        line-height: 1;
        padding: 0 4px;
        border: 1px solid #ccc;
        border-radius: 3px;
    }

    .all-filters2 button {
        background-color: #337ab7;
        color: #fff;
        cursor: pointer;
    }

    /* Media query for stacking edit form columns on small screens */
    @media (max-width: 767px) {

        /* Bootstrap xs breakpoint */
        #edit-form-flex-columns {
            flex-direction: column !important;
        }

        #edit-form-flex-columns>.edit-form-column-wrapper {
            flex-basis: 100% !important;
            /* Make them take full width */
            width: 100% !important;
            /* Explicit width */
            margin-right: 0 !important;
            /* Remove right margin on leftCol when stacked */
            margin-bottom: 15px;
            /* Add some space when stacked */
        }

        /* Ensure the last child doesn't have bottom margin if not desired */
        #edit-form-flex-columns>.edit-form-column-wrapper:last-child {
            margin-bottom: 0;
        }
    }

    /* Tooltip for Vendor Name */
    /* Tooltip Arrow */
    .vendor-name-tooltip-trigger:hover .vendor-tooltip-text {
        visibility: visible;
        opacity: 1;
    }

    .vendor-tooltip-text::after {
        /* 移除箭頭，因其可能導致顯示問題或不美觀 */
        content: none;
    }

    /* --- 根據需求隱藏「製程」、「廠商」、「發單數」欄位 --- */
    #table-DOWN th:nth-child(6),
    #table-DOWN td:nth-child(6),
    #table-DOWN th:nth-child(7),
    #table-DOWN td:nth-child(7),
    #table-DOWN th:nth-child(8),
    #table-DOWN td:nth-child(8) {
        display: none;
    }

    /* --- Popover Styles --- */
    .delivery-popover {
        max-width: 400px; /* Adjust as needed */
    }
    .popover-content {
        font-size: 12px;
    }
    .popover-grid {
        display: grid;
        grid-template-columns: auto auto auto 1fr;
        gap: 2px 8px;
        align-items: center;
    }
    .popover-spec {
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    /* --- Popover Styles --- */
    .delivery-popover {
        max-width: 450px; /* Adjust as needed */
        background-color: white;
        border: none; /* Invisible border */
        box-shadow: 0 2px 8px rgba(0,0,0,0.15); /* Optional: subtle shadow */
    }

    /* 加寬加工單價歷史彈窗 */
    .price-history-popover {
        max-width: none !important;
    }
    .price-history-popover .popover-content {
        white-space: nowrap;
    }

    .popover-content {
        font-size: 12px;
        padding: 5px 8px; /* Tight padding */
    }
    .popover-grid {
        display: grid;
        grid-template-columns: auto auto auto 1fr;
        gap: 2px 8px; /* Tight row and column gap */
        align-items: center;
    }
    .popover-spec {
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        max-width: 250px; /* Prevent very long specs from breaking layout */
    }

    /* Image Editor Styles */
    #canvas-container {
        width: 100%;
        height: 100%;
        overflow: auto;
        background-color: #555;
        position: relative;
        cursor: crosshair;
        display: flex;
        justify-content: center;
        align-items: flex-start;
    }
    #paint-canvas {
        background-color: #fff;
        display: block;
        box-shadow: 0 0 10px rgba(0,0,0,0.5);
    }
    .editor-toolbar {
        padding: 8px;
        background: #f5f5f5;
        border-top: 1px solid #ddd;
        user-select: none;
    }
    .tool-btn { margin-right: 5px; }
    .tool-btn.active { background-color: #337ab7; color: white; border-color: #2e6da4; }
    #imageEditModal { z-index: 10070; }
    #selection-box {
        position: absolute; border: 2px dashed red; background-color: rgba(255, 255, 255, 0.3);
        display: none; pointer-events: none; z-index: 999;
    }

    /* 調整製程篩選按鈕格式 */
    .title a {
    display: contents;
}
</style>
<style>
    /* ── 全域搜索 loading overlay ── */
    #gs-loading-overlay {
        display: none;
        position: fixed;
        top: 50%; left: 50%;
        transform: translate(-50%, -50%);
        background: rgba(20,20,20,0.82);
        color: #fff;
        padding: 14px 32px;
        border-radius: 8px;
        z-index: 29999;
        font-size: 15px;
        letter-spacing: 1px;
        pointer-events: none;
    }
    /* ── 已結案通知 toast ── */
    #gs-closed-notice, #bomf-closed-notice {
        display: none;
        position: fixed;
        bottom: 28px; left: 50%;
        transform: translateX(-50%);
        background: #17a2b8;
        color: #fff;
        padding: 10px 22px;
        border-radius: 6px;
        z-index: 29999;
        font-size: 14px;
        box-shadow: 0 3px 10px rgba(0,0,0,0.35);
        white-space: nowrap;
        cursor: default;
    }
    /* ── 圖面跳窗可拖曳 ── */
    #drawingChoiceModal .modal-header { cursor: move; user-select: none; }
    #drawingChoiceModal .modal-dialog { position: fixed !important; }
    /* ── 圖面縮放容器 ── */
    #img-zoom-wrap { overflow: hidden; width: 100%; height: 600px;
        display: flex; align-items: center; justify-content: center;
        cursor: grab; background: #eee; }
    #img-zoom-wrap:active { cursor: grabbing; }
    #bom-zoom-img { max-width: 100%; max-height: 100%;
        transform-origin: 50% 50%; user-select: none; pointer-events: none; }
</style>
<style>
    /* --- Tooltip for "製程未過半" button --- */
    #toggle-process-not-halfway-filter-btn {
        position: relative; /* For tooltip positioning */
    }

    #toggle-process-not-halfway-filter-btn .tooltip-text {
        visibility: hidden;
        opacity: 0;
        width: max-content; /* Adjust width to content */
        max-width: 300px;   /* Set a max-width */
        background-color: #fff;
        color: #333;
        text-align: left;
        border: 1px solid #ccc;
        border-radius: 6px;
        padding: 8px 12px;
        position: absolute;
        z-index: 10;
        bottom: 125%; /* Position above the button */
        left: 50%;
        transform: translateX(-50%);
        box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        transition: opacity 0.2s;
        white-space: normal; /* Allow text to wrap */
    }

    #toggle-process-not-halfway-filter-btn:hover .tooltip-text {
        visibility: visible;
        opacity: 1;
    }

    /* ── 清單排序控制項（暖色系）───────────────────────────────── */
    .list-sort-controls {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 2px 6px;
        border: 1px solid #E0C091;
        border-radius: 4px;
        background: #FDF3E4;
        white-space: nowrap;
    }
    .list-sort-controls > label {
        margin: 0;
        font-size: 11px;
        font-weight: bold;
        color: #7a4a16;
    }
    .list-sort-controls select#list-sort-field {
        height: 22px;
        font-size: 11px;
        padding: 0 3px;
        border: 1px solid #E0C091;
        border-radius: 3px;
        background: #fff;
        color: #5b3a1a;
    }
    .list-sort-btn {
        height: 22px;
        font-size: 11px;
        line-height: 1;
        padding: 0 7px;
        border: 1px solid #E0C091;
        border-radius: 3px;
        background: #F7E0BD;
        color: #5b3a1a;
        cursor: pointer;
    }
    .list-sort-btn:hover { background: #F0CFA0; }
    .list-sort-btn.active { background: #F0A24B; border-color: #d9861f; color: #fff; font-weight: bold; }
    .list-sort-btn[disabled] { opacity: .45; cursor: default; }

    /* 表頭排序小圖示 */
    .th-sort-btn {
        display: inline-block;
        margin-left: 3px;
        padding: 0 3px;
        font-size: 11px;
        line-height: 1.3;
        color: #a07b4a;
        border: 1px solid transparent;
        border-radius: 3px;
        cursor: pointer;
        user-select: none;
    }
    .th-sort-btn:hover { background: #F7E0BD; border-color: #E0C091; color: #7a4a16; }
    .th-sort-btn.active { background: #F0A24B; border-color: #d9861f; color: #fff; font-weight: bold; }

    /* ── 通知廠商圖 預覽視窗 ───────────────────────────────────── */
    #vendor-notify-mask {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(60, 40, 20, 0.45);
        z-index: 20000;
    }
    #vendor-notify-box {
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        width: auto;
        max-width: 92vw;
        max-height: 92vh;
        display: flex;
        flex-direction: column;
        background: #fff;
        border: 2px solid #E0C091;
        border-radius: 6px;
        box-shadow: 0 6px 24px rgba(0, 0, 0, .35);
    }
    #vendor-notify-head {
        padding: 6px 10px;
        background: #F0A24B;
        color: #fff;
        font-weight: bold;
        font-size: 13px;
        border-radius: 4px 4px 0 0;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    #vendor-notify-body {
        padding: 8px 10px;
        overflow: auto;
        background: #FDF3E4;
    }
    #vendor-notify-body img { max-width: 100%; border: 1px solid #E0C091; background: #fff; }
    #vendor-notify-foot {
        padding: 6px 10px;
        border-top: 1px solid #E0C091;
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 12px;
        color: #5b3a1a;
    }
</style>
<!-- 引入 jQuery 與 Select2 的 CSS 與 JS -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.0.13/dist/css/select2.min.css" rel="stylesheet" />
<!-- 引入 html2canvas 按鈕部分-->
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<link href='../../resource/css/fullcalendar.css' rel='stylesheet' />

<script>
    // $bom_ps_list 與 $max_count 輸出為全域 JavaScript 變數
    window.bomPSList = <?= json_encode($bom_ps_list ?: []); ?>; // Ensure it's an array
    window.maxCount = <?= json_encode((int)($bom_ps_list_max ?: 0)); ?>; // Ensure it's an integer
    window.transferPriceMap = <?= json_encode($transfer_price_map ?: []); ?>; // [bom][bom_sn] 最新單價
    window.transferHistoryMap = <?= json_encode($transfer_history_map ?: []); ?>; // [product_id][bom_sn] 同料號歷史
    if (typeof window.ingActiveMap === 'undefined') { window.ingActiveMap = buildIngActiveMap(window.bomPSList); }

    // ---------- 輔助函數 (移至全域範圍) ----------
    // 將日期物件正規化（只保留年月日）
    function normalizeDate(dt) {
        if (!(dt instanceof Date) || isNaN(dt)) return null; // 增加檢查
        return new Date(Date.UTC(dt.getUTCFullYear(), dt.getUTCMonth(), dt.getUTCDate()));
    }
    // 將日期字串（格式如 "yy'y'/m/d" 或 "yyyy/m/d" 或 "mm/dd"）轉換為 Date 物件
    function convertDateFormat(dateStr) {
        if (!dateStr || typeof dateStr !== 'string') return null;
        dateStr = dateStr.trim();
        if (!dateStr) return null;

        // 增加此行以處理 YYYY-MM-DD 格式，將其轉換為 YYYY/MM/DD
        dateStr = dateStr.replace(/-/g, '/');

        const parts = dateStr.split("/");
        let year, month, day;

        if (parts.length === 3) { // YYYY/MM/DD or YYy/MM/DD
            let yearPart = parts[0].replace('y', '');
            year = parseInt(yearPart, 10);
            if (yearPart.length <= 2) year += 2000; // For '24y' or '24'
            month = parseInt(parts[1], 10) - 1; // JS months are 0-indexed
            day = parseInt(parts[2], 10);
        } else if (parts.length === 2) { // MM/DD (assumes current year)
            year = new Date().getFullYear();
            month = parseInt(parts[0], 10) - 1;
            day = parseInt(parts[1], 10);
        } else {
            return null; // Invalid format
        }
        if (isNaN(year) || isNaN(month) || isNaN(day)) return null;
        return new Date(Date.UTC(year, month, day));
    }
    // 新增：輔助函數 - 格式化動態製程欄位的發單日
    function formatDynamicProcessDate(dateString) {
        if (!dateString || String(dateString).trim() === "" || String(dateString).toLowerCase() === "null") {
            return ""; // 如果日期為空或無效，返回空字串
        }

        // 嘗試將傳入的日期字串（預期格式 YYYY/M/D 或 YYYY-M-D）轉換為 Date 物件
        const normalizedDateInput = String(dateString).replace(/-/g, '/');
        const parts = normalizedDateInput.split('/');
        if (parts.length !== 3) {
            return dateString; // 格式不符，返回原字串
        }
        const year = parseInt(parts[0], 10);
        const month = parseInt(parts[1], 10);
        const day = parseInt(parts[2], 10);

        if (isNaN(year) || isNaN(month) || isNaN(day)) return dateString; // 無效日期，返回原字串

        const currentYear = new Date().getFullYear();
        if (year === currentYear) {
            return `${month}/${day}`; // 今年：m/d
        }
        return `${String(year).slice(-2)}y/${month}/${day}`; // 非今年：yy'y'/m/d
    }

    // 從 bomPSList 重建 ingActiveMap（在 AJAX 刷新後使用）
    function buildIngActiveMap(psList) {
        var map = {};
        (psList || []).forEach(function(item) {
            if (!item || !item.bom) return;
            var st = item.processing_state || '';
            // 'N' 狀態只在有 batch_label（拆分批次）時納入
            if (['Q', 'P', 'ing', 'E'].indexOf(st) === -1 && !(st === 'N' && item.batch_label)) return;
            var bom = String(item.bom);
            if (!map[bom]) map[bom] = [];
            // 正規化日期：YYYY-MM-DD HH:MM:SS 或 YYYY/MM/DD → YYYY/M/D
            function _nd(s) {
                if (!s) return null;
                s = String(s).trim();
                if (!s || s === '0000-00-00' || s === '0000-00-00 00:00:00') return null;
                var m = s.match(/^(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})/);
                return m ? (m[1] + '/' + parseInt(m[2], 10) + '/' + parseInt(m[3], 10)) : null;
            }
            map[bom].push({
                bom_sn:           item.bom_sn,
                bom_ing_id:       item.bom_ing_id,
                bom_ing_fid:      item.bom_ing_fid,
                process_no:       item.process_no,
                ProcessName:      item.ProcessName || '',
                processing_state: st,
                outsource_date:   _nd(item.outsource_date),
                return_date:      _nd(item.return_date),
                QC_check:         item.QC_check || null,
                QC_check_date:    _nd(item.QC_check_date),
                qc_completed:     item.qc_completed ? 1 : 0,
                qc_completed_at:  _nd(item.qc_completed_at),
                maker_id:         item.maker_id || '',
                maker_id_no:      item.maker_id_no || null,
                sqty:             item.sqty,
            });
        });
        Object.keys(map).forEach(function(bom) {
            map[bom].sort(function(a, b) { return parseInt(a.bom_sn || 0, 10) - parseInt(b.bom_sn || 0, 10); });
        });
        return map;
    }

    // New helper function for date formatting specifically for the "發單日" column
    function formatOutsourceDateForDisplay(dateString) {
        if (!dateString || String(dateString).trim() === "" || String(dateString).toLowerCase() === "null") {
            return "";
        }
        const normalizedDateString = String(dateString).replace(/-/g, '/');
        const parts = normalizedDateString.split('/');
        if (parts.length !== 3) {
            return dateString; // Return as is if format is unexpected
        }
        const year = parseInt(parts[0], 10);
        const month = parseInt(parts[1], 10);
        const day = parseInt(parts[2], 10);

        if (isNaN(year) || isNaN(month) || isNaN(day)) return dateString;

        const currentYear = new Date().getFullYear();
        if (year === currentYear) {
            return `${month}/${day}`; // mm/dd for current year
        } else {
            return `${String(year).slice(-2)}y/${month}/${day}`; // yy'y'/mm/dd for other years
        }
    }

    // 更新發單日表頭顯示 (顯示/隱藏 "已過 / 總工作日")
    function updateOutsourceDateHeader() {
        const outsourceDateHeader = document.querySelector('#table-DOWN thead th:nth-child(5)');
        if (!outsourceDateHeader) return;

        const existingInfo = document.getElementById('outsource-date-header-info');
        const existingBr = outsourceDateHeader.querySelector('br.outsource-date-header-br');

        const shouldShow = window.isProcessNotHalfwayFilterActive || window.settingShowWorkday;

        if (shouldShow) {
            if (!existingInfo) {
                const br = document.createElement('br');
                br.className = 'outsource-date-header-br';
                const smallText = document.createElement('small');
                smallText.id = 'outsource-date-header-info';
                smallText.textContent = '已過 / 總工作日';
                smallText.style.fontWeight = 'normal';
                smallText.style.fontSize = '0.8em';
                smallText.style.color = 'red';
                outsourceDateHeader.appendChild(br);
                outsourceDateHeader.appendChild(smallText);
            }
        } else {
            if (existingBr) existingBr.remove();
            if (existingInfo) existingInfo.remove();
        }
    }

    // --- 全域變數 ---
    window.userStatus = <?= json_encode($user_status ?? null); ?>;
    // 動態取得當前 PHP 檔名，讓所有 AJAX 都打到正確 handler（不管檔名是否帶數字2）
    var _phpSelf = window.location.pathname.split('/').pop() || 'OreadyReply_ForPm_BaseOfTime2.php';
    var fullDataset = [];
    var _rowDetailCache = {}; // 子資料按需載入快取
    var _loadRowDetailsInFlight = 0;
    var _loadRowDetailsQueue = [];
    // window.bomPSList and window.maxCount are now reliably set by PHP.
    // Removed redundant: var bomPSList = [];
    // Removed redundant: var maxCount = 0;
    var currentPage = 1;
    var recordsPerPage = 10;
    var currentBomFilter = "all";
    var ptiSearch = "";
        var salesSearch = ""; // 業務篩選
    var isTextareaFocused = false;
    var isUpdatingOrderId = false;
    var isPriorityUpdating = false; // Flag to pause updates during priority change
    var pendingPriorityUpdates = {}; // Object to store BOMs and their latest intended priority
    var availableCustomers = []; // For customer switching // 料號排序狀態
    var currentCustomerIndex = -1; // For customer switching
    var availableVendors = []; // For vendor switching
    var currentVendorIndex = -1; // For vendor switching
    var allProcessTypes = []; // To store all process types for the add modal
    var isCustomerSwitchingActive = false; // For customer switching title display
    var isSelectFocused = false; // <--- 新增或確認此行存在
    var dynamicDateCounter = 0; // Counter for dynamic date inputs
    // var dIdSortOrder = 'none'; // 'none', 'asc', 'desc' for 料號 sorting
    var isProcessNotHalfwayFilterActive = false; // New filter state
    var elapsedDaysFilterValue = null; // For "日內未回" filter
    var isQcReportSortActive = false; // For QC report sorting


    // Helper function to translate processing_state
    function translateProcessingState(state) {
        if (!state) return ""; // If state is null, undefined, or empty string
        state = String(state).trim(); // Ensure it's a string and trim whitespace
        switch (state) {
            case 'N':
                return '新建製程';
            case 'P':
                return '待移轉';
            case 'Q':
                return 'QC待驗';
            case 'ing':
                return '加工中';
            case 'E':
                return '已移轉';
            case 'skip':
                return '跳過';
                // Add other states if needed, e.g., 'ok'
                // case 'ok': return '已完成(舊)';
            default:
                return state; // Return original state if no match
        }
    }

    // ── QC/生管製程同步判斷 ─────────────────────────────────────────────
    // 從 window.bomPSList 找出該BOM「QC有檢驗紀錄(QC_check/QC_check_date)或已按QC完工(qc_completed)」的製程，
    // 與目前製程序號(row.bom_sn，可能為逗號字串)比對：
    //   hasQcAtOrBeyond：存在序號 ≧ 目前製程 的QC紀錄（「QC檢驗」篩選用）
    //   target：序號 > 目前製程、狀態尚可操作(N/ing/Q/P)的最遠QC製程（快速移轉按鈕的目標關）
    function getQcSyncInfo(row) {
        var result = { hasQcAtOrBeyond: false, target: null };
        if (!row || !row.bom || !Array.isArray(window.bomPSList)) return result;
        var curSn = -1;
        String(row.bom_sn || '').split(',').forEach(function(s) {
            var n = parseInt(s, 10);
            if (!isNaN(n) && n > curSn) curSn = n;
        });
        if (curSn < 0) return result;
        var bomStr = String(row.bom).trim();
        window.bomPSList.forEach(function(p) {
            if (!p || String(p.bom || '').trim() !== bomStr) return;
            if (parseInt(p.is_consumed || 0, 10) === 1) return;
            var qcDate = String(p.QC_check_date || '').trim();
            var hasQc = (p.QC_check && String(p.QC_check).trim() !== '') ||
                        (qcDate !== '' && qcDate.indexOf('0000') !== 0) ||
                        (p.qc_completed == 1);
            if (!hasQc) return;
            var sn = parseInt(p.bom_sn, 10);
            if (isNaN(sn) || sn < curSn) return;
            result.hasQcAtOrBeyond = true;
            var st = String(p.processing_state || '').trim();
            if (sn > curSn && (st === 'N' || st === 'ing' || st === 'Q' || st === 'P')) {
                if (!result.target || sn > (parseInt(result.target.bom_sn, 10) || 0)) result.target = p;
            }
        });
        return result;
    }

    // --- Date and Workday Calculation Helpers ---
    // Parses Minguo date string (e.g., "1130603") to a JS Date object
    function parseMinguoDateString(minguoDateStr) {
        if (!minguoDateStr || typeof minguoDateStr !== 'string' || minguoDateStr.length < 7) return null;
        // 修正：確保能處理超過 113 年的民國年份
        try {
            const yearMingStr = minguoDateStr.substring(0, 3);
            const yearMing = parseInt(yearMingStr, 10);
            const month = parseInt(minguoDateStr.substring(3, 5), 10);
            const day = parseInt(minguoDateStr.substring(5, 7), 10);

            if (isNaN(yearMing) || isNaN(month) || isNaN(day)) return null;

            // 修正：直接將民國年加上1911來得到西元年
            const yearAD = yearMing + 1911; 
            const d = new Date(yearAD, month - 1, day); // JS month is 0-indexed
            if (isNaN(d.getTime())) return null;
            return new Date(Date.UTC(yearAD, month - 1, day)); // Use Date.UTC for Minguo dates
        } catch (e) {
            console.error("Error parsing Minguo date string:", minguoDateStr, e);
            return null;
        }
    }

    // Parses Order Number (OO-1130603016) or BOM Number (B-1130603016) to extract date
    function parseOrderOrBomDate(orderOrBomNumberStr) {
        if (!orderOrBomNumberStr || typeof orderOrBomNumberStr !== 'string') return null;
        const str = String(orderOrBomNumberStr).trim();

        let datePartSource = '';

        if (str.includes('-')) {
            // Handles "B-1130305007" and "1130227-2"
            const parts = str.split('-');
            if (parts.length >= 2) {
                const firstPartUpper = parts[0].toUpperCase();
                if (firstPartUpper === 'B' || firstPartUpper === 'OO') {
                    datePartSource = parts[1]; // e.g., "1130305007" from "B-1130305007"
                } else {
                    datePartSource = parts[0]; // e.g., "1130227" from "1130227-2"
                }
            }
        } else if (str.toUpperCase().startsWith('OO')) {
            // Handles "OO1130227002"
            datePartSource = str.substring(2); // e.g., "1130227002"
        }

        if (datePartSource && datePartSource.length >= 7) {
            const datePartStr = datePartSource.substring(0, 7);
            return parseMinguoDateString(datePartStr);
        }

        return null;
    }

    // Checks if a given date is a workday
    function isWorkday(dateObj, workdaysList) {
        if (!dateObj || !(dateObj instanceof Date) || isNaN(dateObj.getTime()) || !workdaysList) return false;
        const formattedDate = dateObj.getUTCFullYear() + '-' + // Use UTC year
            ('0' + (dateObj.getUTCMonth() + 1)).slice(-2) + '-' + // Use UTC month
            ('0' + dateObj.getUTCDate()).slice(-2); // Use UTC date
        return workdaysList.includes(formattedDate);
    }

    /**
     * Counts workdays in the interval [startDate, endDate).
     * If startDate > endDate, it counts workdays in [endDate, startDate) and returns a negative count.
     */
    function countWorkdays(startDate, endDate, workdaysList) {
        if (!startDate || !endDate || !(startDate instanceof Date) || !(endDate instanceof Date) || isNaN(startDate.getTime()) || isNaN(endDate.getTime())) {
            return null;
        }
        let count = 0;
        const reversed = startDate.getTime() > endDate.getTime();
        const d1 = reversed ? new Date(endDate.getTime()) : new Date(startDate.getTime());
        const d2 = reversed ? new Date(startDate.getTime()) : new Date(endDate.getTime());
        const current = new Date(d1.getTime());
        while (current.getTime() < d2.getTime()) {
            if (isWorkday(current, workdaysList)) {
                count++;
            }
            current.setUTCDate(current.getUTCDate() + 1); // Use setUTCDate
        }
        return reversed ? -count : count;
    }

    /**
     * Calculates workdays in the interval (startDate, endDate].
     */
    function calculateWorkdaysExclusiveStartInclusiveEnd(startDate, endDate, workdaysList) {
        if (!startDate || !endDate || !(startDate instanceof Date) || !(endDate instanceof Date) || isNaN(startDate.getTime()) || isNaN(endDate.getTime())) {
            return null;
        }
        let count = 0;
        const reversed = startDate.getTime() >= endDate.getTime();
        const d1 = reversed ? new Date(endDate.getTime()) : new Date(startDate.getTime());
        const d2 = reversed ? new Date(startDate.getTime()) : new Date(endDate.getTime());
        const current = new Date(d1.getTime());
        current.setUTCDate(current.getUTCDate() + 1); // Use setUTCDate
        while (current.getTime() <= d2.getTime()) {
            if (isWorkday(current, workdaysList)) {
                count++;
            }
            current.setUTCDate(current.getUTCDate() + 1); // Use setUTCDate
        }
        return reversed ? -count : count;
    }

    // Helper function to count total workdays inclusive of start and end dates
    function countTotalWorkdaysInclusive(startDate, endDate, workdaysList) {
        if (!startDate || !endDate || !(startDate instanceof Date) || !(endDate instanceof Date) || isNaN(startDate.getTime()) || isNaN(endDate.getTime())) {
            return null;
        }
        let count = 0;
        // Ensure d1 is always before or same as d2 for iteration
        const d1 = startDate.getTime() <= endDate.getTime() ? new Date(startDate.getTime()) : new Date(endDate.getTime());
        const d2 = startDate.getTime() <= endDate.getTime() ? new Date(endDate.getTime()) : new Date(startDate.getTime());

        const current = new Date(d1.getTime());
        while (current.getTime() <= d2.getTime()) { // Iterate up to and including d2
            if (isWorkday(current, workdaysList)) {
                count++;
            }
            current.setUTCDate(current.getUTCDate() + 1); // Use setUTCDate
        }
        return startDate.getTime() > endDate.getTime() ? -count : count; // Return negative if original start was after end
    }

    // Main function to calculate all required workday metrics for an item
    function calculateAllWorkdayMetrics(item, today, workdaysListFromPHP) {
        if (!item) return item;

        // --- 修正：優先使用手動交期 (bom.Delivery_date)，若無則使用選定訂單的交期 ---
        let effectiveDeliveryDateStr = null;
        if (item.Delivery_date && item.Delivery_date !== '0000-00-00') {
            effectiveDeliveryDateStr = item.Delivery_date;
        } else if (item.Order_id && item.Order_id !== 'B' && String(item.Order_id).trim() !== '' && Array.isArray(item.OrderList)) {
            const selectedOrder = item.OrderList.find(o => o && String(o.Order_id) === String(item.Order_id));
            if (selectedOrder && selectedOrder.Delivery_date) {
                effectiveDeliveryDateStr = selectedOrder.Delivery_date;
            }
        }
        const deliveryDate = effectiveDeliveryDateStr ? normalizeDate(convertDateFormat(effectiveDeliveryDateStr)) : null;
        const outsourceDate = item.outsource_date ? normalizeDate(convertDateFormat(item.outsource_date)) : null; // outsource_date is YYYY/MM/DD
        const orderDate = parseOrderOrBomDate(item.Order_oo);
        const bomDate = parseOrderOrBomDate(item.bom);

        item.remaining_workdays_today_delivery = null;
        item.elapsed_workdays_outsource_today = null; // "發單未回日"
        item.workdays_order_delivery = null;
        item.total_workdays_outsource_to_selected_delivery = null; // For "總 ? 日"
        item.remaining_workdays_today_ref = null;
        item.elapsed_workdays_total_to_today = null; // 新增：從訂單/BOM日到今日的總經過工作日

        if (deliveryDate && outsourceDate) { // Scenario 1
            item.remaining_workdays_today_delivery = calculateWorkdaysExclusiveStartInclusiveEnd(today, deliveryDate, workdaysListFromPHP);
            item.elapsed_workdays_outsource_today = countWorkdays(outsourceDate, today, workdaysListFromPHP);
            if (orderDate) item.workdays_order_delivery = countWorkdays(orderDate, deliveryDate, workdaysListFromPHP);
        } else if (deliveryDate) { // Scenario 2
            item.remaining_workdays_today_delivery = calculateWorkdaysExclusiveStartInclusiveEnd(today, deliveryDate, workdaysListFromPHP);
            if (orderDate) item.workdays_order_delivery = countWorkdays(orderDate, deliveryDate, workdaysListFromPHP);
        } else if (outsourceDate) { // Scenario 3
            const refDate = orderDate || bomDate;
            if (refDate) item.remaining_workdays_today_ref = calculateWorkdaysExclusiveStartInclusiveEnd(today, refDate, workdaysListFromPHP);
            item.elapsed_workdays_outsource_today = countWorkdays(outsourceDate, today, workdaysListFromPHP);
        }

        // Calculate total workdays from order/BOM date to selected order's delivery_date OR manual delivery date
        let targetDeliveryDateObj = null;
        let startDateForTotal = null;

        // 1. Determine target delivery date (Manual > Order)
        if (item.Delivery_date && item.Delivery_date !== '0000-00-00') {
            targetDeliveryDateObj = normalizeDate(convertDateFormat(item.Delivery_date));
        } else if (item.Order_id && item.Order_id !== 'B' && String(item.Order_id).trim() !== '' && Array.isArray(item.OrderList)) {
            const selectedOrder = item.OrderList.find(o => o && String(o.Order_id) === String(item.Order_id));
            if (selectedOrder && selectedOrder.Delivery_date) {
                targetDeliveryDateObj = normalizeDate(convertDateFormat(selectedOrder.Delivery_date));
            }
        }

        // 2. Determine start date (Order > BOM)
        if (item.Order_id && item.Order_id !== 'B' && String(item.Order_id).trim() !== '' && Array.isArray(item.OrderList)) {
            const selectedOrder = item.OrderList.find(o => o && String(o.Order_id) === String(item.Order_id));
            if (selectedOrder) {
                startDateForTotal = parseOrderOrBomDate(selectedOrder.Order_oo);
            }
        }
        if (!startDateForTotal) {
            startDateForTotal = parseOrderOrBomDate(item.bom);
        }

        // 4. Calculate if we have both dates
        if (startDateForTotal && targetDeliveryDateObj) {
            item.total_workdays_outsource_to_selected_delivery = countTotalWorkdaysInclusive(startDateForTotal, targetDeliveryDateObj, workdaysListFromPHP);
        }

        // 5. Calculate elapsed workdays from Start Date (Order/BOM) to Today (for Auto Light Logic)
        if (startDateForTotal) {
            item.elapsed_workdays_total_to_today = countWorkdays(startDateForTotal, today, workdaysListFromPHP);
        }
        return item;
    }

    function applyWorkdayCalculationsToDataset(dataset) {
        if (!Array.isArray(dataset) || !window.globalWorkdaysList) {
            return dataset;
        }
        const today = normalizeDate(new Date());
        return dataset.map(item => calculateAllWorkdayMetrics(item, today, window.globalWorkdaysList));
    }

    // New helper function to format date as "m/d"
    function formatDateAsMd(dateString) {
        if (!dateString || String(dateString).trim() === "" || String(dateString).toLowerCase() === "null") {
            return "";
        }
        const normalizedDateInput = String(dateString).replace(/-/g, '/');
        const parts = normalizedDateInput.split('/');
        if (parts.length !== 3) {
            return dateString; // Return as is if format is unexpected
        }
        const month = parseInt(parts[1], 10);
        const day = parseInt(parts[2], 10);
        if (isNaN(month) || isNaN(day)) return dateString;
        return `${month}/${day}`;
    }
    // 初始設定：事件綁定、下拉選單更新、並啟動自動更新
    document.addEventListener("DOMContentLoaded", function() {
        // 初始化 recordsPerPage 和其他篩選器
        var initialRecordsPerPage = document.getElementById("records-per-page").value;
        recordsPerPage = parseInt(initialRecordsPerPage, 10);

        // --- Initialize Light Settings from Backend ---
        var lightSettings = window.initialLightSettings || { yellow: '', red: '', process: '', red_days_before: '' };
        window.settingYellowDays = lightSettings.yellow || '';
        window.settingRedDays = lightSettings.red || '';
        window.settingRedDaysBefore = lightSettings.red_days_before || '';
        window.settingProcessDays = lightSettings.process || '';
        // 初始化方案一/急單快取
        if (!window._bufferCache) window._bufferCache = {};
        if (!window._urgentCache) window._urgentCache = {};
        window.settingShowWorkday = lightSettings.show_workday || false;
        updateOutsourceDateHeader(); // 根據初始設定更新表頭

        // --- 使用從 PHP 嵌入的初始資料 ---
        fullDataset = window.initialFullDataset || [];
        bomPSList = window.initialBomPSList || [];
        // window.globalWorkdaysList is already set by PHP
        maxCount = window.initialMaxCount || 0;
        window.userStatus = window.currentUserStatus; // 確保 userStatus 也從 PHP 設定
        window.canCreate = window.canCreate || false;
        window.canUpdate = window.canUpdate || false;
        window.canDelete = window.canDelete || false;
        // --- 從 URL 參數恢復篩選條件 (從第二個區塊移入) ---
        var urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has("date_filter")) {
            // Ensure element exists before setting value
            const dateFilterEl = document.getElementById("date-filter");
            if (dateFilterEl) dateFilterEl.value = urlParams.get("date_filter");
        }
        if (urlParams.has("bom_filter")) {
            const bomFilterEl = document.getElementById("bom-filter");
            if (bomFilterEl) bomFilterEl.value = urlParams.get("bom_filter");
        }
        if (urlParams.has("customer_filter")) {
            const customerFilterEl = document.getElementById("customer-filter");
            if (customerFilterEl) customerFilterEl.value = urlParams.get("customer_filter");
        }
        if (urlParams.has("vendor_filter")) {
            const vendorFilterEl = document.getElementById("vendor-filter");
            document.getElementById("date-filter").value = urlParams.get("date_filter");
        }
        if (urlParams.has("bom_filter")) {
            document.getElementById("bom-filter").value = urlParams.get("bom_filter");
        }
        if (urlParams.has("customer_filter")) {
            document.getElementById("customer-filter").value = urlParams.get("customer_filter");
        }
        if (urlParams.has("vendor_filter")) {
            if (vendorFilterEl) vendorFilterEl.value = urlParams.get("vendor_filter");
        }
        if (urlParams.has("order_filter")) {
            const orderFilterEl = document.getElementById("order-filter");
            if (orderFilterEl) orderFilterEl.value = urlParams.get("order_filter");
        }
        if (urlParams.has("delivery_date_filter")) {
            const deliveryDateFilterEl = document.getElementById("delivery-date-filter");
            if (deliveryDateFilterEl) deliveryDateFilterEl.value = urlParams.get("delivery_date_filter");
        }
        if (urlParams.has("status_filter")) {
            const statusFilterEl = document.getElementById("status-filter");
            document.getElementById("vendor-filter").value = urlParams.get("vendor_filter");
        }
        if (urlParams.has("sales_filter")) {
            const salesFilterEl = document.getElementById("sales-filter");
            if (salesFilterEl) salesFilterEl.value = urlParams.get("sales_filter");
        }
        if (urlParams.has("order_filter")) {
            document.getElementById("order-filter").value = urlParams.get("order_filter");
        }
        if (urlParams.has("delivery_date_filter")) { // 新增：恢復交期篩選
            document.getElementById("status-filter").value = urlParams.get("status_filter");
        }
        if (urlParams.has("pti")) {
            ptiSearch = urlParams.get("pti"); // 更新全域變數
            // 可以選擇性地更新按鈕狀態，如果需要的話
        }
        // --- URL 參數恢復結束 ---
        // --- Global Search Filter Restore ---
        if (urlParams.has("global_search")) {
            const globalSearchEl = document.getElementById("global-search");
            if (globalSearchEl) globalSearchEl.value = urlParams.get("global_search");
        }
        // 移除頁面載入時的 scrollToTop 檢查，因為新的編輯表單將在頁面下方顯示


        // 綁定分頁按鈕事件
        document.getElementById("btn-first").addEventListener("click", function(e) {
            e.preventDefault();
            goToPage(1);
        });
        document.getElementById("btn-prev").addEventListener("click", function(e) {
            e.preventDefault();
            goToPage(currentPage - 1);
        });
        document.getElementById("btn-next").addEventListener("click", function(e) {
            e.preventDefault();
            goToPage(currentPage + 1);
        });
        document.getElementById("btn-last").addEventListener("click", function(e) {
            e.preventDefault();
            var pageSelector = document.getElementById("page-selector");
            var totalPages = pageSelector ? pageSelector.options.length : 1;
            if (totalPages === 0) totalPages = 1;
            goToPage(totalPages);
        });

        // 綁定下拉選單事件
        const pageSelectorEl = document.getElementById("page-selector");
        if (pageSelectorEl) {
            pageSelectorEl.addEventListener("change", function() {
                changePageSelector(this);
            });
            pageSelectorEl.addEventListener('focus', function() { // 當選單被點擊或聚焦時
                isSelectFocused = true; // 設定標記為 true
                // console.log('頁碼選擇器已聚焦, isSelectFocused:', isSelectFocused, '時間:', new Date().toLocaleTimeString());
            });
            pageSelectorEl.addEventListener('blur', function() { // 當選單失去焦點時
                isSelectFocused = false; // 設定標記為 false
                // console.log('頁碼選擇器已失焦, isSelectFocused:', isSelectFocused, '時間:', new Date().toLocaleTimeString());
            });
        }

        const recordsPerPageEl = document.getElementById("records-per-page");
        if (recordsPerPageEl) {
            recordsPerPageEl.addEventListener("change", function() { // 當選擇的值改變時
                changeRecordsPerPage(this.value);
            });
            recordsPerPageEl.addEventListener('focus', function() { // 當選單被點擊或聚焦時
                isSelectFocused = true; // 設定標記為 true
                // console.log('每頁筆數選擇器已聚焦, isSelectFocused:', isSelectFocused, '時間:', new Date().toLocaleTimeString());
            });
            recordsPerPageEl.addEventListener('blur', function() { // 當選單失去焦點時
                isSelectFocused = false; // 設定標記為 false
                // console.log('每頁筆數選擇器已失焦, isSelectFocused:', isSelectFocused, '時間:', new Date().toLocaleTimeString());
            });
        }

        const statusFilterEl = document.getElementById("status-filter");
        if (statusFilterEl) { // Ensure the element exists before adding listeners
            statusFilterEl.addEventListener('focus', function() { // 當選單被點擊或聚焦時
                isSelectFocused = true; // 設定標記為 true
                // 確保 isSelectFocused 標記在點擊下拉選單選項後能被正確重設
                // 這裡的 blur 事件處理可能需要延遲，以確保選項的 click 事件先觸發
                this.addEventListener('change', function() {
                    isSelectFocused = false; // 選項改變後，假設焦點行為已完成
                }, {
                    once: true
                }); // 確保此事件只觸發一次，避免重複綁定
                // console.log('狀態篩選器已聚焦, isSelectFocused:', isSelectFocused, '時間:', new Date().toLocaleTimeString());
            });
            statusFilterEl.addEventListener('blur', function() { // 當選單失去焦點時
                isSelectFocused = false; // 設定標記為 false
                // console.log('狀態篩選器已失焦, isSelectFocused:', isSelectFocused, '時間:', new Date().toLocaleTimeString());
            });
        }

        const customerFilterEl = document.getElementById("customer-filter");
        if (customerFilterEl) {
            customerFilterEl.addEventListener('focus', function() { // 當輸入框被點擊或聚焦時 (datalist 展開)
                isSelectFocused = true; // 設定標記為 true
                // console.log('客戶篩選器已聚焦, isSelectFocused:', isSelectFocused, '時間:', new Date().toLocaleTimeString());
            });
            customerFilterEl.addEventListener('blur', function() { // 當輸入框失去焦點時
                // Delay slightly to allow click on datalist option before blur fully processes
                setTimeout(function() { // 延遲處理，以允許 datalist 選項的點擊事件完成
                    isSelectFocused = false;
                    // console.log('客戶篩選器已失焦, isSelectFocused:', isSelectFocused, '時間:', new Date().toLocaleTimeString());
                }, 150);
            });
        }

        const vendorFilterEl = document.getElementById("vendor-filter");
        if (vendorFilterEl) {
            vendorFilterEl.addEventListener('focus', function() { // 當輸入框被點擊或聚焦時 (datalist 展開)
                isSelectFocused = true; // 設定標記為 true
                // console.log('廠商篩選器已聚焦, isSelectFocused:', isSelectFocused, '時間:', new Date().toLocaleTimeString());
            });
            vendorFilterEl.addEventListener('blur', function() { // 當輸入框失去焦點時
                // Delay slightly to allow click on datalist option before blur fully processes
                setTimeout(function() { // 延遲處理
                    isSelectFocused = false;
                    // console.log('廠商篩選器已失焦, isSelectFocused:', isSelectFocused, '時間:', new Date().toLocaleTimeString());
                }, 150);
            });
        }

        // --- Add event listeners for Export buttons (Ensure elements exist) ---
        const btnCsv = document.getElementById('btn-export-csv');
        const btnJpg = document.getElementById('btn-export-jpg');
        if (btnCsv) btnCsv.addEventListener('click', exportToCSV);
        if (btnJpg) btnJpg.addEventListener('click', exportToJPG);

        // --- 通知廠商圖（BOM／料號／發單日 三欄，複製到剪貼簿）---
        const btnVendorImg = document.getElementById('btn-vendor-notify-img');
        if (btnVendorImg) btnVendorImg.addEventListener('click', exportVendorNotifyImage);

        // --- 清單排序控制項 ---
        const sortFieldSel = document.getElementById('list-sort-field');
        if (sortFieldSel) {
            sortFieldSel.addEventListener('change', function() {
                setListSort(this.value, listSortDir);
            });
        }
        const sortDirBtn = document.getElementById('btn-list-sort-dir');
        if (sortDirBtn) {
            sortDirBtn.addEventListener('click', function() {
                if (!listSortField) return;
                setListSort(listSortField, listSortDir === 'asc' ? 'desc' : 'asc');
            });
        }
        const sortClearBtn = document.getElementById('btn-list-sort-clear');
        if (sortClearBtn) {
            sortClearBtn.addEventListener('click', function() {
                setListSort('', 'asc');
            });
        }
        // 表頭小圖示點擊排序（事件委派，表頭重繪也不會失效）
        const sortTableEl = document.getElementById('table-DOWN');
        if (sortTableEl) {
            sortTableEl.addEventListener('click', function(e) {
                const btn = e.target.closest ? e.target.closest('.th-sort-btn') : null;
                if (!btn) return;
                e.preventDefault();
                e.stopPropagation();
                const f = btn.getAttribute('data-sort-field');
                if (!f) return;
                if (listSortField === f) {
                    // 同欄位：遞增 → 遞減 → 取消
                    if (listSortDir === 'asc') setListSort(f, 'desc');
                    else setListSort('', 'asc');
                } else {
                    setListSort(f, 'asc');
                }
            });
        }
        updateListSortUI();

        // --- Add event listeners for Customer Switching buttons ---
        const setWorkdayBtn = document.getElementById('set-workday-btn');
        if (setWorkdayBtn) {
            setWorkdayBtn.addEventListener('click', openWorkdayModal);
        }
        // Event listener for the "新增空白日期" button will be added inside openWorkdayModal
        // because the button is created dynamically.



        // --- Add event listeners for Customer Switching buttons ---
        document.getElementById('btn-prev-customer').addEventListener('click', switchToPrevCustomer);
        document.getElementById('btn-next-customer').addEventListener('click', switchToNextCustomer);
        document.getElementById('btn-prev-vendor').addEventListener('click', switchToPrevVendor);
        document.getElementById('btn-next-vendor').addEventListener('click', switchToNextVendor);

        // --- Add event listener for "日內未回" filter button ---
        const elapsedDaysFilterBtn = document.getElementById('elapsed-days-filter-btn');
        if (elapsedDaysFilterBtn) {
            // This button is now replaced by toggle-elapsed-days-filter-btn and confirm-elapsed-days-filter-btn
            // So, the old event listener for 'elapsed-days-filter-btn' can be removed or commented out.
        }

        // --- New: Event listener for the "篩選發單未回" / "取消篩選發單未回" button ---
        const toggleElapsedDaysBtn = document.getElementById('toggle-elapsed-days-filter-btn');
        const elapsedDaysInputContainer = document.getElementById('elapsed-days-input-container');
        const elapsedDaysFilterInput = document.getElementById('elapsed-days-filter-input');
        const confirmElapsedDaysBtn = document.getElementById('confirm-elapsed-days-filter-btn');
        const elapsedDaysStatusText = document.getElementById('elapsed-days-filter-status-text');

        if (toggleElapsedDaysBtn) {
            toggleElapsedDaysBtn.addEventListener('click', function() {
                if (elapsedDaysFilterValue === null) { // If filter is not active, show input
                    elapsedDaysInputContainer.style.display = 'inline-flex';
                    elapsedDaysFilterInput.focus();
                } else {
                    // Filter is active, so this click means "Cancel"
                    elapsedDaysFilterValue = null;
                    elapsedDaysFilterInput.value = '';
                    elapsedDaysInputContainer.style.display = 'none';
                    elapsedDaysStatusText.style.display = 'none'; // Hide status text on cancel
                    toggleElapsedDaysBtn.textContent = '篩選發單未回';
                    toggleElapsedDaysBtn.classList.remove('btn-warning');
                    toggleElapsedDaysBtn.classList.add('btn-danger');
                    currentPage = 1;
                    processAndRenderData();
                }
            });
        }
        // --- Add event listener for "Show Processes" button ---
        const btnShowProcesses = document.getElementById('show-processes-btn');
        if (btnShowProcesses) btnShowProcesses.addEventListener('click', scrollToProcesses);

        // --- Event listener for "製程未過半" filter button ---
        const toggleProcessNotHalfwayBtn = document.getElementById('toggle-process-not-halfway-filter-btn');
        // const processNotHalfwayStatusText = document.getElementById('process-not-halfway-filter-status-text'); // Not currently used for display text

        if (toggleProcessNotHalfwayBtn) {
            toggleProcessNotHalfwayBtn.addEventListener('click', function() {

                isProcessNotHalfwayFilterActive = !isProcessNotHalfwayFilterActive;

                // --- 修正：使用一個函數來更新按鈕文字，同時保留 tooltip 的 HTML 結構 ---
                const updateButtonText = (button, text) => {
                    const tooltipSpan = button.querySelector('.tooltip-text');
                    button.textContent = text; // 先設定文字
                    if (tooltipSpan) {
                        button.appendChild(tooltipSpan); // 再把 tooltip 加回去
                    }
                };

                if (isProcessNotHalfwayFilterActive) {
                    updateButtonText(this, '取消製程未過半');
                    this.classList.remove('btn-info');
                    this.classList.add('btn-warning');

                } else {
                    updateButtonText(this, '篩選製程未過半');
                    this.classList.remove('btn-warning');
                    this.classList.add('btn-info');

                }
                updateOutsourceDateHeader(); // 更新表頭顯示
                currentPage = 1;
                processAndRenderData();
            });
        }

        const toggleQcReportSortBtn = document.getElementById('toggle-qc-report-sort-btn');
        if (toggleQcReportSortBtn) {
            toggleQcReportSortBtn.addEventListener('click', function() {
                isQcReportSortActive = !isQcReportSortActive; // Toggle the state

                if (isQcReportSortActive) {
                    // 按下按鈕後，狀態篩選自動變成篩選「檢驗完成待移轉」
                    document.getElementById('status-filter').value = 'P';

                    this.textContent = '按照QC報工排序中';
                    this.classList.remove('btn-primary');
                    this.classList.add('btn-danger');
                } else {
                    // 取消時，清除此按鈕設定的篩選狀態並恢復按鈕
                    const statusFilter = document.getElementById('status-filter');
                    if (statusFilter.value === 'P') {
                        statusFilter.value = '';
                    }
                    this.textContent = 'QC報工排序';
                    this.classList.remove('btn-danger');
                    this.classList.add('btn-primary');
                }
                currentPage = 1; // 變更排序/篩選時，重設到第一頁
                fetchDataAndFilter(); // 重新獲取資料、篩選並渲染
            });
        }



        // 綁定篩選條件變動事件
        document.getElementById('customer-filter').addEventListener('input', processAndRenderData);
        document.getElementById('bom-filter').addEventListener('input', processAndRenderData);
        document.getElementById('vendor-filter').addEventListener('input', processAndRenderData);
        document.getElementById('sales-filter').addEventListener('input', processAndRenderData);
        document.getElementById('order-filter').addEventListener('input', processAndRenderData);

        // --- 新增：雙擊「客戶篩選」輸入框本身來清空其內容 ---
        const customerFilterInputForDblClick = document.getElementById('customer-filter');
        if (customerFilterInputForDblClick) {
            customerFilterInputForDblClick.addEventListener('dblclick', function() {
                if (this.value.trim() !== "") {
                    this.value = ""; // 清空輸入框
                    console.log("雙擊「客戶篩選」輸入框，已清空內容並觸發篩選。");
                    processAndRenderData(); // 觸發篩選
                }
            });
        }



        // --- 新增：雙擊「搜索 BOM / 料號」輸入框本身來清空其內容 ---
        const bomFilterInput = document.getElementById('bom-filter');
        if (bomFilterInput) {
            bomFilterInput.addEventListener('dblclick', function() {
                if (this.value.trim() !== "") {
                    this.value = ""; // 清空輸入框
                    console.log("雙擊「搜索 BOM / 料號」輸入框，已清空內容並觸發篩選。");
                    processAndRenderData(); // 觸發篩選
                }
            });
        }

        // --- 新增：雙擊「業務篩選」輸入框本身來清空其內容 ---
        const salesFilterInputForDblClick = document.getElementById('sales-filter');
        if (salesFilterInputForDblClick) {
            salesFilterInputForDblClick.addEventListener('dblclick', function() {
                if (this.value.trim() !== "") {
                    this.value = ""; // 清空輸入框
                    console.log("雙擊「業務篩選」輸入框，已清空內容並觸發篩選。");
                    processAndRenderData(); // 觸發篩選
                }
            });
        }

        // --- 新增：雙擊「廠商篩選」輸入框本身來清空其內容 ---
        const vendorFilterInputForDblClick = document.getElementById('vendor-filter');
        if (vendorFilterInputForDblClick) {
            vendorFilterInputForDblClick.addEventListener('dblclick', function() {
                if (this.value.trim() !== "") {
                    this.value = ""; // 清空輸入框
                    console.log("雙擊「廠商篩選」輸入框，已清空內容並觸發篩選。");
                    processAndRenderData(); // 觸發篩選
                }
            });
        }

        // --- 新增：雙擊「搜索所有欄位」輸入框本身來清空其內容 ---
        const globalSearchInputForDblClick = document.getElementById('global-search');
        if (globalSearchInputForDblClick) {
            globalSearchInputForDblClick.addEventListener('dblclick', function() {
                if (this.value.trim() !== "") {
                    this.value = ""; // 清空輸入框
                    console.log("雙擊「搜索所有欄位」輸入框，已清空內容並觸發篩選。");
                    processAndRenderData(); // 觸發篩選
                }
            });
        }

        // --- 新增：雙擊「交期篩選」輸入框本身來清空其內容 ---
        const deliveryDateFilterInputForDblClick = document.getElementById('delivery-date-filter');
        if (deliveryDateFilterInputForDblClick) {
            deliveryDateFilterInputForDblClick.addEventListener('dblclick', function() {
                if (this.value.trim() !== "") {
                    this.value = ""; // 清空輸入框
                    console.log("雙擊「交期篩選」輸入框，已清空內容並觸發篩選。");
                    processAndRenderData(); // 觸發篩選
                }
            });
        }

        // --- 新增：雙擊「發單數篩選」輸入框本身來清空其內容 ---
        const orderFilterInputForDblClick = document.getElementById('order-filter');
        if (orderFilterInputForDblClick) {
            orderFilterInputForDblClick.addEventListener('dblclick', function() {
                if (this.value.trim() !== "") {
                    this.value = ""; // 清空輸入框
                    console.log("雙擊「發單數篩選」輸入框，已清空內容並觸發篩選。");
                    processAndRenderData(); // 觸發篩選
                }
            });
        }

        document.getElementById('date-filter').addEventListener('blur', function() {
            handleDateInput();
        });
        document.getElementById('date-filter').addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                handleDateInput();
            }
        });
        // 新增：交期篩選事件綁定
        document.getElementById('delivery-date-filter').addEventListener('blur', function() {
            handleDeliveryDateInput();
        });
        document.getElementById('delivery-date-filter').addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                handleDeliveryDateInput();
            }
        });
        if (document.getElementById('status-filter')) {
            document.getElementById('status-filter').addEventListener('change', function() {
                var sf = this.value;
                var qcDateEl = document.getElementById('qc-date-filter');
                if (sf === 'qc_date_pick') {
                    qcDateEl.style.display = 'inline-block';
                    // 預設今天
                    if (!qcDateEl.value) {
                        var t = new Date();
                        qcDateEl.value = t.getFullYear() + '-' + String(t.getMonth()+1).padStart(2,'0') + '-' + String(t.getDate()).padStart(2,'0');
                    }
                } else {
                    qcDateEl.style.display = 'none';
                }
                processAndRenderData();
            });
            document.getElementById('qc-date-filter').addEventListener('change', processAndRenderData);
        }
        // ── 全域搜索：防抖 200ms + 搜索中 overlay + 已結案通知 ──
        (function() {
            // 建立 DOM 元素（僅一次）
            var _ov = document.createElement('div'); _ov.id = 'gs-loading-overlay';
            _ov.innerHTML = '<i class="fa fa-spinner fa-spin" style="margin-right:8px;"></i>搜索中...';
            document.body.appendChild(_ov);
            var _nt = document.createElement('div'); _nt.id = 'gs-closed-notice';
            _nt.style.cursor = 'pointer';
            _nt.title = '點擊開啟已完工查詢';
            document.body.appendChild(_nt);

            var _timer = null;
            var _lastQT = '';

            // 點擊通知 → 清除全域篩選 → 開啟已完工查詢 modal 並預填關鍵字
            _nt.addEventListener('click', function(e) {
                e.stopPropagation();
                _nt.style.display = 'none';
                clearTimeout(_nt._hideTimer);
                // 先清空全域搜索並重繪，避免已完工 modal 開啟時仍有殘留篩選
                var _gs = document.getElementById('global-search');
                if (_gs && _gs.value.trim() !== '') {
                    _gs.value = '';
                    processAndRenderData();
                }
                openSearchCompletedModal();
                setTimeout(function() {
                    var _inp = document.getElementById('completed-bom-search-term');
                    var _btn = document.getElementById('execute-completed-search-btn');
                    if (_inp) { _inp.value = _lastQT; }
                    if (_btn) { _btn.click(); }
                }, 150);
            });

            // 點擊其他地方 → 關閉通知
            document.addEventListener('click', function() {
                if (_nt.style.display !== 'none') {
                    _nt.style.display = 'none';
                    clearTimeout(_nt._hideTimer);
                }
            });

            document.getElementById('global-search').addEventListener('input', function() {
                _ov.style.display = 'block';
                _nt.style.display = 'none';
                clearTimeout(_timer);
                var _q = this.value;
                _timer = setTimeout(function() {
                    processAndRenderData();
                    _ov.style.display = 'none';
                    var _qt = (_q || '').trim();
                    // 有關鍵字 且 dataset 已載入 且 活躍資料結果為0 → 查已結案
                    if (_qt && fullDataset && fullDataset.length > 0
                            && !document.querySelector('#table-DOWN tbody tr')) {
                        $.post('', { action: 'check_closed_bom', q: _qt }, function(res) {
                            if (res && res.count > 0) {
                                _lastQT = _qt;
                                _nt.innerHTML = '<i class="fa fa-info-circle" style="margin-right:6px;"></i>'
                                    + '未結案中查無「<strong>' + escapeHtml(_qt) + '</strong>」，'
                                    + '已結案資料有 <strong>' + res.count + '</strong> 筆符合。'
                                    + ' <span style="opacity:.7;font-size:12px;">點擊查詢 &rsaquo;</span>';
                                _nt.style.display = 'block';
                                clearTimeout(_nt._hideTimer);
                                _nt._hideTimer = setTimeout(function() { _nt.style.display = 'none'; }, 10000);
                            }
                        }, 'json');
                    }
                }, 200);
            });
        })();

        // ── BOM / 料號 搜索：活躍資料查無結果時，提示已結案有無符合（邏輯同全域搜索）──
        (function() {
            var _nt = document.createElement('div'); _nt.id = 'bomf-closed-notice';
            _nt.style.cursor = 'pointer'; _nt.title = '點擊開啟已完工查詢';
            document.body.appendChild(_nt);
            var _timer = null, _lastQT = '';

            // 點擊通知 → 清除 BOM 篩選 → 開啟已完工查詢 modal 並預填關鍵字
            _nt.addEventListener('click', function(e) {
                e.stopPropagation();
                _nt.style.display = 'none'; clearTimeout(_nt._hideTimer);
                var _bf = document.getElementById('bom-filter');
                if (_bf && _bf.value.trim() !== '') { _bf.value = ''; processAndRenderData(); }
                openSearchCompletedModal();
                setTimeout(function() {
                    var _inp = document.getElementById('completed-bom-search-term');
                    var _btn = document.getElementById('execute-completed-search-btn');
                    if (_inp) { _inp.value = _lastQT; }
                    if (_btn) { _btn.click(); }
                }, 150);
            });
            document.addEventListener('click', function() {
                if (_nt.style.display !== 'none') { _nt.style.display = 'none'; clearTimeout(_nt._hideTimer); }
            });

            var _bf = document.getElementById('bom-filter');
            if (_bf) _bf.addEventListener('input', function() {
                _nt.style.display = 'none';
                clearTimeout(_timer);
                var _q = this.value;
                // 既有的 input 監聽已同步呼叫 processAndRenderData 完成篩選，這裡只補「查無結果→查已結案」
                _timer = setTimeout(function() {
                    var _qt = (_q || '').trim();
                    if (_qt && fullDataset && fullDataset.length > 0
                            && !document.querySelector('#table-DOWN tbody tr')) {
                        $.post('', { action: 'check_closed_bom', q: _qt }, function(res) {
                            if (res && res.count > 0) {
                                _lastQT = _qt;
                                _nt.innerHTML = '<i class="fa fa-info-circle" style="margin-right:6px;"></i>'
                                    + '未結案中查無「<strong>' + escapeHtml(_qt) + '</strong>」，'
                                    + '已結案資料有 <strong>' + res.count + '</strong> 筆符合。'
                                    + ' <span style="opacity:.7;font-size:12px;">點擊查詢 &rsaquo;</span>';
                                _nt.style.display = 'block';
                                clearTimeout(_nt._hideTimer);
                                _nt._hideTimer = setTimeout(function() { _nt.style.display = 'none'; }, 10000);
                            }
                        }, 'json');
                    }
                }, 250);
            });
        })();

        // --- 按鈕觸發函數定義 (確保它們內部最後調用 processAndRenderData) ---
        // --- Add Enter key prevention for specific text/search filters ---
        const filterInputIdsToPreventEnterSubmit = [
            'customer-filter',
            'bom-filter',
            'sales-filter',
            'vendor-filter',
            'order-filter',
            'global-search'
        ];
        filterInputIdsToPreventEnterSubmit.forEach(function(inputId) {
            const inputElement = document.getElementById(inputId);
            if (inputElement) {
                inputElement.addEventListener('keydown', function(event) {
                    if (event.key === 'Enter') {
                        event.preventDefault(); // Prevent default form submission
                        // processAndRenderData(); // Data is already filtered on 'input' event
                    }
                });
            }
        });

        window.toggleBomColorFilter = function() {
            const btn = document.getElementById("bomColorFilter");
            const contentSpan = document.getElementById("bomColorContent");
            if (currentBomFilter === "all") {
                currentBomFilter = "green";
                if (contentSpan) contentSpan.innerHTML = '<figure class="circle_green" style="margin:0;width:18px;height:18px;display:block;"></figure>';
            } else if (currentBomFilter === "green") {
                currentBomFilter = "yellow";
                if (contentSpan) contentSpan.innerHTML = '<figure class="circle_y" style="margin:0;width:18px;height:18px;display:block;"></figure>';
            } else if (currentBomFilter === "yellow") {
                currentBomFilter = "red";
                if (contentSpan) contentSpan.innerHTML = '<figure class="circle_red" style="margin:0;width:18px;height:18px;display:block;"></figure>';
            } else {
                currentBomFilter = "all";
                if (contentSpan) contentSpan.innerHTML = '<span style="font-size:8px; display:inline-block;">All</span>';
            }
            currentPage = 1; // 篩選時回到第一頁
            processAndRenderData(); // <--- 必須呼叫
        };

        // 製程篩選輔助函式
        // 若有設定 ptiProcessMap[ptiId]，則比對 row.process_no（ProcessNo）
        // 否則維持原邏輯：比對 row.pti（process_type_id 陣列）
        window._matchPti = function(row, ptiId) {
            if (!ptiId) return true;
            var map = window.ptiProcessMap || {};
            var ptId = String(ptiId);
            if (map[ptId] && map[ptId].length > 0) {
                // 比對 row.process_no（可能是逗號分隔）
                var rowProcNos = String(row.process_no || '').split(',').map(function(s){ return s.trim(); });
                return map[ptId].some(function(pno) {
                    return rowProcNos.indexOf(String(pno)) !== -1;
                });
            } else {
                // 原邏輯：比對 process_type_id
                var ptiArray = String(row.pti || '').split(',');
                return ptiArray.indexOf(ptId) !== -1;
            }
        };

        window.filterByPTI = function(val) {
            window.ptiSearch = val; // 更新全域變數
            currentPage = 1; // 篩選時回到第一頁
            processAndRenderData(); // <--- 必須呼叫
        };

        // ── PTI 篩選按鈕動態渲染 ──
        function renderPtiFilterButtons() {
            var container = document.getElementById('pti-filter-buttons-container');
            if (!container) return;
            container.innerHTML = '';
            var saved = window.ptiFilterSaved || [];
            var ptList = window.processTypeList || [];
            // 若有儲存設定，依儲存的 process_type_id 清單顯示；否則不顯示
            var toShow = [];
            if (saved.length > 0 && ptList.length > 0) {
                saved.forEach(function(sid) {
                    var found = ptList.find(function(p){ return String(p.process_type_id) === String(sid); });
                    if (found) toShow.push(found);
                });
            }
            // 全部製程按鈕永遠顯示
            var allBtn = document.createElement('a');
            allBtn.innerHTML = '<input type="button" class="btn btn-xs btn-primary" value="全部製程" onclick="filterByPTI(\'\')" style="margin-left:4px;">';
            container.appendChild(allBtn);
            toShow.forEach(function(pt) {
                var a = document.createElement('a');
                a.innerHTML = '<input type="button" class="btn btn-xs btn-primary" value="' + escapeHtml(pt.process_type) + '" onclick="filterByPTI(\'' + escapeHtml(String(pt.process_type_id)) + '\')" style="margin-left:4px;">';
                container.appendChild(a);
            });
        }
        renderPtiFilterButtons();

        // ── 製程設定彈窗 ──
        // ── 例外內製製程設定按鈕 ──
        var btnInternalProc = document.getElementById('btn-internal-proc-setting');
        if (btnInternalProc) {
            btnInternalProc.onclick = function() { openInternalProcSettingModal(); };
        }

        var btnPtiSetting = document.getElementById('btn-pti-filter-setting');
        if (btnPtiSetting) {
            btnPtiSetting.onclick = function() {
                // 移除舊彈窗
                var old = document.getElementById('pti-setting-modal-overlay');
                if (old) old.remove();

                var overlay = document.createElement('div');
                overlay.id = 'pti-setting-modal-overlay';
                overlay.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.45);z-index:9998;display:flex;align-items:center;justify-content:center;';

                var modal = document.createElement('div');
                modal.style.cssText = 'background:#fff;border-radius:6px;padding:20px 24px;min-width:340px;max-width:480px;width:90%;box-shadow:0 4px 24px rgba(0,0,0,.2);position:relative;z-index:9999;';
                modal.innerHTML = '<h4 style="margin-top:0;margin-bottom:10px;font-size:15px;border-bottom:1px solid #eee;padding-bottom:8px;">製程設定</h4>' +
                    '<div style="display:flex;border-bottom:2px solid #e0e0e0;margin-bottom:12px;">' +
                    '<button id="pti-tab-btn-1" style="padding:5px 16px;border:none;background:none;font-size:13px;font-weight:700;color:#2E6DA4;border-bottom:2px solid #2E6DA4;margin-bottom:-2px;cursor:pointer;">篩選列顯示</button>' +
                    '<button id="pti-tab-btn-2" style="padding:5px 16px;border:none;background:none;font-size:13px;font-weight:600;color:#888;cursor:pointer;">製程類別對應製程</button>' +
                    '</div>' +
                    '<div id="pti-tab-1"><small style="color:#888;font-size:11px;display:block;margin-bottom:6px;">選擇要顯示在篩選列的製程分類</small>' +
                    '<div id="pti-setting-list" style="max-height:260px;overflow-y:auto;margin-bottom:12px;"></div></div>' +
                    '<div id="pti-tab-2" style="display:none;">' +
                    '<small style="color:#888;font-size:11px;display:block;margin-bottom:8px;">左側選製程類別，右側勾選對應製程</small>' +
                    '<div style="display:flex;gap:0;height:280px;border:1px solid #ddd;border-radius:4px;overflow:hidden;">' +
                    '<div id="pti-type-list" style="width:130px;flex-shrink:0;overflow-y:auto;border-right:1px solid #ddd;background:#fafafa;"></div>' +
                    '<div style="flex:1;display:flex;flex-direction:column;min-width:0;">' +
                    '<input id="pti-proc-search" placeholder="篩選製程名稱..." style="border:none;border-bottom:1px solid #eee;padding:6px 8px;font-size:12px;outline:none;flex-shrink:0;">' +
                    '<div id="pti-proc-list" style="flex:1;overflow-y:auto;padding:4px 6px;"></div>' +
                    '</div></div>' +
                    '</div>' +
                    '<div style="text-align:right;gap:8px;display:flex;justify-content:flex-end;margin-top:12px;">' +
                    '<button type="button" id="pti-setting-cancel" class="btn btn-default btn-sm">取消</button>' +
                    '<button type="button" id="pti-setting-save" class="btn btn-primary btn-sm">儲存設定</button>' +
                    '</div>';
                overlay.appendChild(modal);
                document.body.appendChild(overlay);

                // Tab 切換
                document.getElementById('pti-tab-btn-1').onclick = function() {
                    document.getElementById('pti-tab-1').style.display = '';
                    document.getElementById('pti-tab-2').style.display = 'none';
                    this.style.cssText = 'padding:5px 16px;border:none;background:none;font-size:13px;font-weight:700;color:#2E6DA4;border-bottom:2px solid #2E6DA4;margin-bottom:-2px;cursor:pointer;';
                    document.getElementById('pti-tab-btn-2').style.cssText = 'padding:5px 16px;border:none;background:none;font-size:13px;font-weight:600;color:#888;cursor:pointer;';
                };
                document.getElementById('pti-tab-btn-2').onclick = function() {
                    document.getElementById('pti-tab-1').style.display = 'none';
                    document.getElementById('pti-tab-2').style.display = '';
                    this.style.cssText = 'padding:5px 16px;border:none;background:none;font-size:13px;font-weight:700;color:#2E6DA4;border-bottom:2px solid #2E6DA4;margin-bottom:-2px;cursor:pointer;';
                    document.getElementById('pti-tab-btn-1').style.cssText = 'padding:5px 16px;border:none;background:none;font-size:13px;font-weight:600;color:#888;cursor:pointer;';
                    if (!window._ptiMapLoaded) _loadPtiMapData();
                };

                // Tab2: 輔助函式
                window._ptiMapLoaded  = false;
                window._ptiMapData    = { types:[], processes:[] };
                window._ptiMapResult  = {}; // { process_type_id: [process_no_id, ...] }
                window._ptiActiveType = null;

                function _loadPtiMapData() {
                    $('#pti-type-list').html('<p style="color:#aaa;font-size:12px;padding:8px;">載入中...</p>');
                    $.post('', { action: 'get_process_type_map' }, function(res) {
                        window._ptiMapLoaded = true;
                        if (!res.success) { $('#pti-type-list').html('<p style="color:red;font-size:12px;padding:6px;">載入失敗</p>'); return; }
                        window._ptiMapData.types     = res.types     || [];
                        window._ptiMapData.processes = res.processes || [];
                        // 把 maps 轉成 { ptId: [pnId, ...] }
                        window._ptiMapResult = {};
                        (res.maps || []).forEach(function(m) {
                            var ptId = String(m.process_type_id);
                            if (!window._ptiMapResult[ptId]) window._ptiMapResult[ptId] = [];
                            window._ptiMapResult[ptId].push(String(m.process_no_id));
                        });
                        _renderTypeList();
                    }, 'json');
                }

                function _renderTypeList() {
                    var $list = $('#pti-type-list').empty();
                    window._ptiMapData.types.forEach(function(t) {
                        var ptId = String(t.process_type_id);
                        var cnt  = (window._ptiMapResult[ptId] || []).length;
                        var $btn = $('<div class="pti-type-item" data-ptid="' + ptId + '" style="padding:7px 10px;cursor:pointer;font-size:12px;border-bottom:1px solid #f0f0f0;display:flex;justify-content:space-between;align-items:center;">' +
                            '<span>' + escapeHtml(t.process_type) + '</span>' +
                            '<span class="pti-type-cnt" style="background:#e0e7f0;border-radius:10px;padding:0 5px;font-size:10px;color:#2E6DA4;">' + cnt + '</span>' +
                            '</div>');
                        $btn.on('click', function() {
                            $('.pti-type-item').css({ background:'', fontWeight:'' });
                            $(this).css({ background:'#EBF3FB', fontWeight:'700' });
                            window._ptiActiveType = ptId;
                            _renderProcList('');
                        });
                        $list.append($btn);
                    });
                }

                function _renderProcList(filter) {
                    var ptId  = window._ptiActiveType;
                    var $list = $('#pti-proc-list').empty();
                    if (!ptId) { $list.html('<p style="color:#aaa;font-size:12px;padding:8px;">請先選擇左側製程類別</p>'); return; }
                    var checked = window._ptiMapResult[ptId] || [];
                    var lc = filter.toLowerCase();
                    window._ptiMapData.processes.forEach(function(p) {
                        if (lc && escapeHtml(p.ProcessName).toLowerCase().indexOf(lc) === -1) return;
                        var pnId = String(p.process_no_id);
                        var isChk = checked.indexOf(pnId) !== -1;
                        var $row = $('<label style="display:flex;align-items:center;gap:6px;padding:3px 2px;cursor:pointer;font-size:12px;">' +
                            '<input type="checkbox" class="pti-proc-cb" value="' + pnId + '"' + (isChk?' checked':'') + ' style="cursor:pointer;">' +
                            '<span>' + escapeHtml(p.ProcessName) + '</span>' +
                            '</label>');
                        $row.find('input').on('change', function() {
                            if (!window._ptiMapResult[ptId]) window._ptiMapResult[ptId] = [];
                            if (this.checked) {
                                if (window._ptiMapResult[ptId].indexOf(pnId) === -1)
                                    window._ptiMapResult[ptId].push(pnId);
                            } else {
                                window._ptiMapResult[ptId] = window._ptiMapResult[ptId].filter(function(x){ return x !== pnId; });
                            }
                            // 更新類別列表的計數
                            $('#pti-type-list .pti-type-item[data-ptid="' + ptId + '"] .pti-type-cnt').text(window._ptiMapResult[ptId].length);
                        });
                        $list.append($row);
                    });
                    if ($list.children().length === 0) $list.html('<p style="color:#aaa;font-size:12px;padding:8px;">無符合結果</p>');
                }

                $(document).off('input.ptiproc').on('input.ptiproc', '#pti-proc-search', function() {
                    _renderProcList($(this).val().trim());
                });

                // 移除舊的 add-row / del 事件（不再使用）
                $(document).off('click.ptimap').off('click.ptidel');

                // 載入製程分類
                $.post('', { action: 'get_process_types' }, function(res) {
                    if (!res.success) { document.getElementById('pti-setting-list').innerHTML = '<p style="color:red;">載入失敗</p>'; return; }
                    var ptList = res.process_types || [];
                    window.processTypeList = ptList; // 更新全域
                    var savedArr = (res.saved && Array.isArray(res.saved)) ? res.saved.map(String) : (window.ptiFilterSaved || []).map(String);
                    var html = '';
                    ptList.forEach(function(pt) {
                        var chk = savedArr.indexOf(String(pt.process_type_id)) !== -1 ? 'checked' : '';
                        html += '<label style="display:flex;align-items:center;gap:8px;padding:5px 2px;cursor:pointer;border-bottom:1px solid #f5f5f5;">' +
                            '<input type="checkbox" class="pti-setting-cb" value="' + escapeHtml(String(pt.process_type_id)) + '" ' + chk + ' style="width:16px;height:16px;cursor:pointer;">' +
                            '<span style="font-size:13px;">' + escapeHtml(pt.process_type) + '</span>' +
                            '<small style="color:#aaa;font-size:11px;">(ID: ' + escapeHtml(String(pt.process_type_id)) + ')</small>' +
                            '</label>';
                    });
                    document.getElementById('pti-setting-list').innerHTML = html || '<p style="color:#aaa;font-size:12px;">無製程分類資料</p>';
                }, 'json');

                document.getElementById('pti-setting-cancel').onclick = function() { overlay.remove(); };
                overlay.addEventListener('click', function(e){ if (e.target === overlay) overlay.remove(); });

                document.getElementById('pti-setting-save').onclick = function() {
                    var isTab2 = document.getElementById('pti-tab-2').style.display !== 'none';
                    if (!isTab2) {
                        // Tab1: 儲存篩選列設定（原有邏輯不變）
                        var selected = [];
                        document.querySelectorAll('#pti-setting-list .pti-setting-cb:checked').forEach(function(cb){ selected.push(cb.value); });
                        $.post('', { action: 'save_pti_filter_setting', selected_json: JSON.stringify(selected) }, function(res) {
                            if (res.success) {
                                window.ptiFilterSaved = selected;
                                renderPtiFilterButtons();
                                overlay.remove();
                                showTemporaryMessage('製程設定已儲存', true);
                            } else {
                                alert('儲存失敗：' + (res.message || '未知錯誤'));
                            }
                        }, 'json');
                    } else {
                        // Tab2: 儲存製程類別對應製程（從 _ptiMapResult 收集）
                        var maps = [];
                        Object.keys(window._ptiMapResult || {}).forEach(function(ptId) {
                            (window._ptiMapResult[ptId] || []).forEach(function(pnId) {
                                maps.push({ process_type_id: ptId, process_no_id: pnId });
                            });
                        });
                        $.post('', { action: 'save_process_type_map', map_json: JSON.stringify(maps) }, function(res) {
                            if (res.success) {
                                overlay.remove();
                                showTemporaryMessage('製程類別對應設定已儲存', true);
                            } else {
                                alert('儲存失敗：' + (res.message || ''));
                            }
                        }, 'json');
                    }
                };
            };
        }


        window.cancelFilters = function() {
            document.getElementById("date-filter").value = "";
            document.getElementById("bom-filter").value = "";
            document.getElementById("customer-filter").value = "";
            document.getElementById("sales-filter").value = "";
            document.getElementById("vendor-filter").value = "";
            document.getElementById("delivery-date-filter").value = ""; // 新增：清除交期篩選
            document.getElementById("order-filter").value = "";
            if (document.getElementById("status-filter")) {
                document.getElementById("status-filter").value = "";
            }
            document.getElementById("global-search").value = "";
            ptiSearch = ""; // 清空全域變數
            currentBomFilter = "all"; // 清空全域變數

            // Reset "製程未過半" filter
            isProcessNotHalfwayFilterActive = false;
            const toggleProcessNotHalfwayBtn = document.getElementById('toggle-process-not-halfway-filter-btn');
            // const processNotHalfwayStatusText = document.getElementById('process-not-halfway-filter-status-text');
            if (toggleProcessNotHalfwayBtn) {
                toggleProcessNotHalfwayBtn.textContent = '篩選製程未過半';
                toggleProcessNotHalfwayBtn.classList.remove('btn-warning');
                toggleProcessNotHalfwayBtn.classList.add('btn-info');
            }
            
            updateOutsourceDateHeader(); // 更新表頭顯示

            // --- 修正：確保「製程未過半」按鈕的 tooltip HTML 存在 ---
            if (toggleProcessNotHalfwayBtn) {
                const tooltipSpan = toggleProcessNotHalfwayBtn.querySelector('.tooltip-text');
                if (!tooltipSpan) {
                    const newTooltipSpan = document.createElement('span');
                    newTooltipSpan.className = 'tooltip-text';
                    newTooltipSpan.innerHTML = `<b>規則一：</b>製程未過熱處理(含正在熱處理)或已過熱處理但加工日超過總加工日比例<br><b>規則二：</b>無熱處理者，使用「已經過的工作天數」與「總製程數」做比例換算，計算日期過半但製程未過半者。`;
                    toggleProcessNotHalfwayBtn.appendChild(newTooltipSpan);
                }
            }

            // Reset "日內未回" filter elements
            elapsedDaysFilterValue = null;
            if (elapsedDaysFilterInput) elapsedDaysFilterInput.value = '';
            if (elapsedDaysInputContainer) elapsedDaysInputContainer.style.display = 'none';
            if (elapsedDaysStatusText) elapsedDaysStatusText.style.display = 'none';
            if (toggleElapsedDaysBtn) {
                toggleElapsedDaysBtn.textContent = '篩選發單未回';
                toggleElapsedDaysBtn.classList.remove('btn-warning');
                toggleElapsedDaysBtn.classList.add('btn-danger');
            }



            // Reset "QC報工排序" button
            isQcReportSortActive = false;
            const toggleQcSortBtn = document.getElementById('toggle-qc-report-sort-btn');
            if (toggleQcSortBtn) {
                toggleQcSortBtn.textContent = 'QC報工排序';
                toggleQcSortBtn.classList.remove('btn-danger');
                toggleQcSortBtn.classList.add('btn-primary');
            }

            elapsedDaysFilterValue = null; // Clear elapsed days filter
            const contentSpan = document.getElementById("bomColorContent");
            // dIdSortOrder = 'none'; // Reset 料號 sort order
            if (contentSpan) contentSpan.innerHTML = '<span style="font-size:8px; display:inline-block;">All</span>';
            isCustomerSwitchingActive = false; // Reset customer switching flag
            document.getElementById('current-customer-display').textContent = ''; // Clear title display
            currentPage = 1; // 取消篩選時回到第一頁
            processAndRenderData(); // <--- 必須呼叫
            // Also clear the input field for elapsed days
            const elapsedDaysInput = document.getElementById('elapsed-days-filter-input');
            if (elapsedDaysInput) elapsedDaysInput.value = "";

        };

        // --- New: Event listener for the "確認" button of the "日內未回" filter ---
        if (confirmElapsedDaysBtn) {
            confirmElapsedDaysBtn.addEventListener('click', function() {
                const value = parseInt(elapsedDaysFilterInput.value, 10);
                if (!isNaN(value) && value >= 0 && value <= 99) {
                    elapsedDaysFilterValue = value;
                    elapsedDaysStatusText.textContent = `目前篩選 ${value} 天發單未回`;
                    elapsedDaysStatusText.style.display = 'inline';
                    elapsedDaysInputContainer.style.display = 'none';
                    toggleElapsedDaysBtn.textContent = '取消工作日篩選';
                    toggleElapsedDaysBtn.classList.remove('btn-danger');
                    toggleElapsedDaysBtn.classList.add('btn-warning');
                    currentPage = 1;
                    processAndRenderData();
                } else {
                    alert("請輸入 0 到 99 之間的天數。");
                    elapsedDaysFilterInput.focus();
                }
            });
        };
        // --- New: Event listener for Enter key on "日內未回" day input ---
        if (elapsedDaysFilterInput) {
            elapsedDaysFilterInput.addEventListener('keydown', function(event) {
                if (event.key === 'Enter') {
                    event.preventDefault(); // Prevent default Enter action (like form submission)
                    if (confirmElapsedDaysBtn) {
                        confirmElapsedDaysBtn.click(); // Simulate click on the confirm button
                    }
                }
            });
        }

        window.handleDateInput = function() { // 將 handleDateInput 改為全域
            const dateInput = document.getElementById("date-filter");
            let rawInput = dateInput.value.trim();
            // console.log("原始輸入：", rawInput);

            let operator = "";
            if (rawInput.length > 0 && (rawInput[0] === ">" || rawInput[0] === "<" || rawInput[0] === "=")) {
                operator = rawInput[0];
                rawInput = rawInput.slice(1).trim();
            } else {
                operator = "="; // 如果沒有運算符，預設為等於
            }
            const parts = rawInput.split("/");
            if (rawInput && parts.length === 2) { // 只有 月/日
                const currentYear = new Date().getFullYear();
                rawInput = `${currentYear}/${rawInput}`; // 補上年份
            }
            // 重新組合包含操作符的日期字串
            const newDateFilter = operator + rawInput;
            // console.log("處理後的日期條件：", newDateFilter);
            dateInput.value = newDateFilter; // 更新輸入框的值
            currentPage = 1; // 日期篩選變更，回到第一頁
            processAndRenderData(); // <--- 確保呼叫
        };

        // 新增：處理交期篩選輸入的函數
        window.handleDeliveryDateInput = function() {
            const dateInput = document.getElementById("delivery-date-filter");
            let rawInput = dateInput.value.trim();
            // console.log("交期原始輸入：", rawInput);

            let operator = "";
            if (rawInput.length > 0 && (rawInput[0] === ">" || rawInput[0] === "<" || rawInput[0] === "=")) {
                operator = rawInput[0];
                rawInput = rawInput.slice(1).trim();
            } else {
                operator = "="; // 如果沒有運算符，預設為等於
            }
            const parts = rawInput.split("/");
            if (rawInput && parts.length === 2) { // 只有 月/日
                const currentYear = new Date().getFullYear();
                rawInput = `${currentYear}/${rawInput}`; // 補上年份
            }
            const newDateFilter = operator + rawInput;
            // console.log("處理後的交期條件：", newDateFilter);
            dateInput.value = newDateFilter;
            currentPage = 1;
            processAndRenderData();
        };

        // --- 綁定設定業務按鈕 ---
        const btnSalesSetting = document.getElementById('btn-sales-setting');
        if (btnSalesSetting) btnSalesSetting.addEventListener('click', openSalesSettingModal);

        // --- 按鈕觸發函數定義結束 ---

        // --- 直接使用已嵌入的資料進行首次渲染 ---
        fullDataset = fullDataset.map(function(r) {
            if (!r) return r;
            r.shipment_history = r.shipment_history || [];
            r.qq_details       = r.qq_details       || [];
            r.ok_details       = r.ok_details       || [];
            return r;
        });
        fullDataset = applyWorkdayCalculationsToDataset(fullDataset);
        console.log("DOMContentLoaded: 使用 PHP 嵌入的初始資料進行渲染...");
        processAndRenderData();
        // --- 新增BOM按鈕 ---
        var btnCreateBom = document.getElementById('btn-create-bom');
        if (btnCreateBom) btnCreateBom.addEventListener('click', openCreateBomModal);
        // --- 啟動定時刷新（預設 30 秒，失敗時指數退避至 120 秒）---
        // 自適應退避定時刷新：_fetch_data2.php 失敗時不干擾即時搜尋
        (function(){
            var _failCount=0, _timer=null, _BASE=30000, _MAX=120000;
            function _schedule(delay){
                clearTimeout(_timer);
                _timer=setTimeout(function(){
                    fetchDataAndFilter(function(ok){
                        _failCount = ok ? 0 : Math.min(_failCount+1,4);
                        _schedule(ok ? _BASE : Math.min(_BASE*Math.pow(2,_failCount),_MAX));
                    });
                }, delay);
            }
            _schedule(_BASE);
        })();
    });

    function scrollToBeginning() {
        const tableWrapper = document.querySelector('.table-wrapper');
        if (!tableWrapper) {
            console.error("Table wrapper element not found for scrolling to beginning!");
            return; // 如果找不到元素，直接返回
        }
        tableWrapper.scrollLeft = 0;
    }

    function scrollToProcesses() {
        const tableWrapper = document.querySelector('.table-wrapper');
        const table = document.getElementById('table-DOWN');
        if (!tableWrapper || !table) {
            console.error("Table wrapper or table element not found for scrolling!");
            return;
        }

        const headerRow = table.querySelector('thead tr');
        if (!headerRow) {
            console.error("Table header row not found for calculating scroll offset!");
            return;
        }

        let scrollAmount = 0;
        // Sum the widths of non-sticky columns before the first dynamic process column.
        // These are columns 7 (廠商) through 11 (已加工 / NG).
        // Columns 12 (pti) and 13 (狀態) are hidden and should have offsetWidth = 0.
        for (let i = 7; i <= 11; i++) {
            const th = headerRow.querySelector(`th:nth-child(${i})`);
            if (th) {
                scrollAmount += th.offsetWidth;
            }
        }

        tableWrapper.scrollLeft = scrollAmount;
        // console.log(`Scrolled to processes. scrollLeft target: ${scrollAmount}`);
    }

    // Helper function to format outsource_date
    function formatOutsourceDateDisplay(dateString) {
        if (!dateString || String(dateString).trim() === "" || String(dateString).toLowerCase() === "null") {
            return ""; // Return empty if no date or null
        }

        const normalizedDateString = String(dateString).replace(/-/g, '/'); // 允許 YYYY-M-D
        const dateParts = normalizedDateString.split('/'); // SQL 格式為 YYYY/M/D
        if (dateParts.length !== 3) {
            // console.warn("預期外的 outsource_date 格式:", dateString);
            return dateString; // 格式不符則回傳原字串
        }

        const year = parseInt(dateParts[0], 10);
        const month = parseInt(dateParts[1], 10);
        const day = parseInt(dateParts[2], 10);

        if (isNaN(year) || isNaN(month) || isNaN(day)) {
            // console.warn("無效的 outsource_date 組件:", dateString);
            return dateString; // 無效日期組件則回傳原字串
        }

        const currentYear = new Date().getFullYear();

        if (year === currentYear) {
            return `${month}/${day}`;
        } else {
            return `<div class="date-multiline"><div>${year}</div><div>${month}/${day}</div></div>`;
        }
    }

    // ---------- 跳转明细页面 ----------
    function goToDetail(link) {
        var baseUrl = link.getAttribute("data-href");
        var date_filter = document.getElementById("date-filter").value.trim();
        var bom_filter = document.getElementById("bom-filter").value.trim();
        var customer_filter = document.getElementById("customer-filter").value.trim();
        var vendor_filter = document.getElementById("vendor-filter").value.trim();
        var sales_filter = document.getElementById("sales-filter").value.trim();
        var order_filter = document.getElementById("order-filter").value.trim();
        var status_filter = document.getElementById("status-filter") ? document.getElementById("status-filter").value.trim() : "";
        var pti_filter = ptiSearch; // 全域變數（制程過濾）

        var qs = "?date_filter=" + encodeURIComponent(date_filter) +
            "&bom_filter=" + encodeURIComponent(bom_filter) +
            "&customer_filter=" + encodeURIComponent(customer_filter) +
            "&sales_filter=" + encodeURIComponent(sales_filter) +
            "&vendor_filter=" + encodeURIComponent(vendor_filter) +
            "&order_filter=" + encodeURIComponent(order_filter) +
            "&status_filter=" + encodeURIComponent(status_filter) +
            "&pti=" + encodeURIComponent(pti_filter);

        window.location.href = baseUrl + qs;
    }
    // Helper function to format the text for order options in the dropdown
    function formatOrderOptionText(order) {
        if (!order || !order.Order_oo || String(order.Order_oo).trim() === '') {
            return '無編號';
        }

        let orderOo = String(order.Order_oo).trim();
        // Delivery_date from PHP is like "25y/5/16" (yy'y'/M/d)
        let deliveryDateRaw = String(order.Delivery_date || '').trim();
        let qty = String(order.Qty || '').trim();

        let formattedOrderOoPart;

        // Parsing Order_oo based on the example "OO1130428001" -> "3-0428-1"
        if (orderOo.length >= 12) { // Minimum length for the example pattern
            // Remove "OO" from the beginning if it exists
            let firstPart = orderOo.substring(0, 9);
            if (firstPart.startsWith("OO")) {
                firstPart = firstPart.substring(2); // Remove "OO", e.g., "1130227"
            }
            const p3Raw = orderOo.substring(9, 12); // Characters from index 9 to 11, e.g., '001'
            const p3 = parseInt(p3Raw, 10).toString(); // Convert "001" to integer "1"
            formattedOrderOoPart = `${firstPart}-${p3}`; // Combine as "1130227-1"
        } else {
            // Fallback if Order_oo is not long enough for the specific parsing
            formattedOrderOoPart = escapeHtml(orderOo);
        }

        let formattedDeliveryDatePart = '';
        if (deliveryDateRaw) {
            // Remove the 'y' character if present after the year (e.g., "25y/5/16" -> "25/5/16")
            const deliveryDateCleaned = deliveryDateRaw.replace(/y\//, '/');
            formattedDeliveryDatePart = `_${deliveryDateCleaned}`;
        }

        let formattedQtyPart = '';
        if (qty) {
            formattedQtyPart = `x${qty}`;
        }

        return `${formattedOrderOoPart}${formattedDeliveryDatePart}${formattedQtyPart}`;
    }

    // 2. 替換 fetchDataAndFilter 函數 (加入 isSelectFocused 檢查)
    function fetchDataAndFilter(callback) { // Added callback parameter
        // *** 在最前面檢查所有標誌 ***
        // console.log('fetchDataAndFilter - Check 1 - isTextareaFocused:', isTextareaFocused, 'isUpdatingOrderId:', isUpdatingOrderId, 'isSelectFocused:', isSelectFocused, 'isPriorityUpdating:', isPriorityUpdating, 'Time:', new Date().toLocaleTimeString());
        if (isTextareaFocused || isUpdatingOrderId || isSelectFocused || isPriorityUpdating) { // <--- 加入 isPriorityUpdating 檢查
            console.log(`跳過更新: 文字區塊聚焦=${isTextareaFocused}, 訂單更新中=${isUpdatingOrderId}, 下拉選單聚焦=${isSelectFocused}, 優先級更新中=${isPriorityUpdating} (時間: ${new Date().toLocaleTimeString()})`);
            return;
        } else {
            // console.log(`執行更新: 文字區塊聚焦=${isTextareaFocused}, 訂單更新中=${isUpdatingOrderId}, 下拉選單聚焦=${isSelectFocused} (時間: ${new Date().toLocaleTimeString()})`);
        }
        console.log("⏳ fetchDataAndFilter 開始 " + new Date().toLocaleTimeString());

        // --- NEW: Modify URL based on QC sort state ---
        let fetchUrl = new URL('../../src/store/_fetch_data2.php', window.location.href);

        // ⭐ 核心修正：在自動更新時，總是請求所有資料
        fetchUrl.searchParams.set('fetchAll', '1');

        if (isQcReportSortActive) {
            // 如果 isQcReportSortActive 為 true，在 URL 中加入排序參數
            fetchUrl.searchParams.set('sort_by_qc_date', '1');
            console.log("啟用 QC 報工排序，請求 URL:", fetchUrl);
        }

        var xhr = new XMLHttpRequest();
        xhr.open('GET', fetchUrl.href, true); // 使用組合後的 URL
        xhr.onreadystatechange = function() {
            if (xhr.readyState === 4 && xhr.status === 200) {
                try {
                    var response = JSON.parse(xhr.responseText);
                    // console.log("fetchDataAndFilter 收到回應:", response, '時間:', new Date().toLocaleTimeString());
                    
                    // --- DEBUG: 顯示後端 SQL 查詢 ---
                    if (response.debug_order_query) console.log("[DEBUG] 後端查詢訂單 SQL:", response.debug_order_query);

                    if (response && Array.isArray(response.data) && Array.isArray(response.bom_ps_list) && response.bom_ps_list_max !== undefined) {
                        // *** 在渲染前再次檢查所有標誌 ***
                        if (isTextareaFocused) {
                            // console.log("AJAX 完成但 Textarea 已獲取焦點，取消渲染");
                            return;
                        }
                        let newFetchedData = response.data;
                        let newBomPSList = response.bom_ps_list;
                        let newMaxCount = response.bom_ps_list_max;
                        // 補上子陣列（從快取合併，或設為空陣列）
                        newFetchedData.forEach(function(newItem) {
                            if (!newItem || !newItem.bom) return;
                            newItem.shipment_history = newItem.shipment_history || [];
                            newItem.qq_details       = newItem.qq_details       || [];
                            newItem.ok_details       = newItem.ok_details       || [];
                            var cached = _rowDetailCache[newItem.bom];
                            if (cached && !cached._loading) {
                                if (cached.shipment_history && cached.shipment_history.length)
                                    newItem.shipment_history = cached.shipment_history;
                                if (cached.qq_details && cached.qq_details.length)
                                    newItem.qq_details = cached.qq_details;
                                if (cached.ok_details && cached.ok_details.length)
                                    newItem.ok_details = cached.ok_details;
                            }
                        });
                        // --- Client-side patch for "備庫" status ---
                        // Create a map of the old fullDataset's Order_id status by BOM for quick lookup
                        const oldOrderStates = new Map();
                        if (Array.isArray(fullDataset)) {
                            fullDataset.forEach(oldItem => {
                                if (oldItem && oldItem.bom) {
                                    oldOrderStates.set(oldItem.bom, oldItem.Order_id);
                                }
                            });
                        }
                        // "Correct" new data if server returns null/empty for Order_id where client had 'B'
                        newFetchedData.forEach(newItem => {
                            if (newItem && newItem.bom) {
                                const oldOrderId = oldOrderStates.get(newItem.bom);
                                if ((newItem.Order_id === null || String(newItem.Order_id).trim() === "") && oldOrderId === 'B') {
                                    // console.log(`fetchDataAndFilter: Correcting Order_id for BOM ${newItem.bom} from [${newItem.Order_id}] to 'B' based on previous client state.`);
                                    newItem.Order_id = 'B';
                                }
                            }
                        });
                        // --- End of client-side patch ---
                        fullDataset = newFetchedData;
                        bomPSList = newBomPSList;
                        window.bomPSList = newBomPSList;
                        fullDataset = applyWorkdayCalculationsToDataset(fullDataset); // Calculate workdays for AJAX refreshed data
                        maxCount = newMaxCount;
                        window.maxCount = newMaxCount;
                        window.allProcessTypes = response.all_process_types || []; // Store all process types globally
                        if (response.transfer_price_map) window.transferPriceMap = response.transfer_price_map;
                        if (response.transfer_history_map) window.transferHistoryMap = response.transfer_history_map;
                        if (response.ing_active_map) window.ingActiveMap = response.ing_active_map;
                        else window.ingActiveMap = buildIngActiveMap(newBomPSList);
                        if (typeof callback === 'function') {
                            callback(); // Execute the callback before rendering main table if needed, or after
                        }
                        // --- Apply pending priority updates AFTER main render ---
                        if (Object.keys(pendingPriorityUpdates).length > 0) {
                            console.log("Applying pending priority updates:", JSON.parse(JSON.stringify(pendingPriorityUpdates)));
                            applyPendingPriorityUpdates(); // New helper function
                            pendingPriorityUpdates = {}; // Clear after applying
                            console.log("Cleared pending priority updates.");
                        }
                        processAndRenderData();
                        console.log("✅ fetchDataAndFilter 完成 " + new Date().toLocaleTimeString());
                    } else {
                        console.error("❌ fetch 回傳的資料格式不符預期:", response);
                        fullDataset = []; // 清空數據以防錯誤渲染
                        processAndRenderData(); // 仍然調用以更新空表格
                    }
                } catch (e) {
                    console.error("❌ fetch JSON 解析錯誤：", e);
                    console.warn("⚠️ fetch 原始回傳內容：", xhr.responseText);
                    fullDataset = []; // 清空數據
                    processAndRenderData(); // 更新空表格
                }
            } else if (xhr.readyState === 4) {
                console.warn("_fetch_data2.php 失敗（狀態碼: " + xhr.status + "），保留現有資料。");
            }
        };
        // If a callback is provided and we want it to run *after* processAndRenderData,
        // it's tricky with the current structure. For now, let's assume the callback
        // passed to fetchDataAndFilter is meant to run *before* processAndRenderData
        // if it needs to manipulate data that processAndRenderData depends on, or *after* if it depends on processAndRenderData's completion.
        xhr.send();

    }

    // 3. 替換 updateTable 函數 (修正 <br> 輸出邏輯)
    function updateTable(pageData) {
        // 在重繪表格前，移除所有已存在的 Bootstrap Popover 元素
        $('.popover').remove();

        // console.log("updateTable 接收到 pageData 筆數:", pageData.length);
        var table = document.getElementById('table-DOWN');
        var tbody = table.querySelector('tbody');
        if (!tbody) {
            console.error("找不到 tbody 元素");
            return;
        }
        tbody.innerHTML = ""; // 清空現有內容
        let modalsHtmlBuffer = ''; // Buffer to hold all modals HTML for this page

        var baseExcelUrl = window.location.protocol + '//' + window.location.host + '/nas/';

        pageData.forEach(function(row) {
            if (!row || typeof row !== 'object' || Object.keys(row).length === 0) return;

            // 將 null 轉為空字串
            for (var key in row) {
                if (row[key] === null) {
                    row[key] = '';
                }
            }

            // 解析所有綁定訂單資訊 (支援 1對多)
            var boundOrders = [];
            if (row.bound_orders_info) {
                row.bound_orders_info.split(';').forEach(function(s) {
                    var p = s.split(':');
                    if (p.length >= 2 && p[0].trim() !== '') {
                        boundOrders.push({ id: p[0], pcs: p[1] || '0', oo: p.slice(2).join(':') });
                    }
                });
            }

            var bomFid = String(row.bom_ing_fid || '').trim(); // 確保 bom_ing_fid 存在
            var tr = document.createElement("tr");
            tr.setAttribute('data-bom', row.bom || ''); // ── 方案一/急單：讓 querySelector 找得到 ──

            // --- 1. 客戶 ---
            var tdClient = document.createElement('td');
            tdClient.setAttribute('name', 'Client_Name');
            tdClient.style.whiteSpace = 'normal'; // 允許多行顯示
            tdClient.style.lineHeight = '1.3'; // 調整行高以容納多行
            tdClient.style.verticalAlign = 'top'; // 確保內容從頂部對齊


            // 根據使用者身份決定是否顯示 "更新" 按鈕
            if (window.userStatus == 1) {
                var updateButton = document.createElement('button');
                updateButton.type = 'button';
                updateButton.className = 'btn btn-xs btn-warning';
                updateButton.textContent = '更新';
                updateButton.style.marginRight = '5px';
                updateButton.style.padding = '1px 3px';
                updateButton.style.fontSize = '10px';
                updateButton.style.lineHeight = '1.2';
                updateButton.onclick = function() {
                    displayEditFormForRow(row, this);
                };
                tdClient.appendChild(updateButton);
            }

            // 添加客戶名稱文字節點（優先使用 client_name_display：有綁定料號時取 customer_list.customer）
            const clientNameSpan = document.createElement('span');
            clientNameSpan.textContent = row.client_name_display || row.Client_Name_Full || row.Client_Name || '';
            tdClient.appendChild(clientNameSpan);

            // --- NEW: Salesperson Display Logic (Refined for strict height/spacing consistency) ---
            if (row.PrimarySalesName) {
                const getShortName = (fullName) => {
                    if (!fullName) return '';
                    const name = String(fullName);
                    // "吳佳靜" -> "佳靜", "李四" -> "李四"
                    return name.length > 2 ? name.substring(name.length - 2) : name;
                };
            
                const primaryShortName = getShortName(row.PrimarySalesName);
                const deputyShortName = getShortName(row.DeputySalesName);

                // Create the main container with flex layout for consistent alignment
                const salesDiv = document.createElement('div');
                salesDiv.style.fontSize = '0.9em';
                salesDiv.style.color = '#555';
                salesDiv.style.display = 'flex';
                salesDiv.style.alignItems = 'center'; // Align icon and text block to center
                salesDiv.style.gap = '4px';

                // Create the icon wrapper to control height and alignment
                const iconWrapper = document.createElement('div');
                iconWrapper.style.display = 'flex';
                iconWrapper.style.alignItems = 'center';
                iconWrapper.style.height = '1.2em'; // Match the text line-height

                const icon = document.createElement('i');
                icon.className = 'fa fa-user';
                icon.title = '業務';
                iconWrapper.appendChild(icon);

                // Create the container for all text content
                const textContainer = document.createElement('div');
                textContainer.style.display = 'flex';
                textContainer.style.flexDirection = 'column';
                textContainer.style.justifyContent = 'center';

                // Helper to create a consistent text line
                const createLine = (html, color) => {
                    const div = document.createElement('div');
                    div.style.whiteSpace = 'nowrap';
                    div.style.lineHeight = '1.2'; // Strict line height
                    div.style.margin = '0';
                    div.style.padding = '0';
                    if (color) div.style.color = color;
                    div.innerHTML = html;
                    return div;
                };

                if (row.IsPrimaryOnLeave && row.DeputySalesName) {
                    // Case 1: Primary on leave, deputy exists. Display in two compact lines.
                    let deputyHtml = escapeHtml(deputyShortName);
                    if (row.IsDeputyOnLeave) {
                        deputyHtml += ' <span style="color: red;">(休)</span>';
                    }
                    deputyHtml += ' <span style="color: blue;">(代)</span>';
                    textContainer.appendChild(createLine(deputyHtml));

                    const primaryHtml = '(' + escapeHtml(primaryShortName) + ' <span style="color: red;">(休)</span>)';
                    textContainer.appendChild(createLine(primaryHtml, '#999'));
                } else {
                    // Case 2: Single line display (Primary working, or on leave without deputy)
                    let contentHtml = escapeHtml(primaryShortName);
                    if (row.IsPrimaryOnLeave) {
                        contentHtml += ' <span style="color: red;">(休)</span>';
                    }
                    textContainer.appendChild(createLine(contentHtml));
                }
                
                // Assemble the final element
                salesDiv.appendChild(iconWrapper);
                salesDiv.appendChild(textContainer);

                tdClient.appendChild(salesDiv);
            }

            // BOM格式 B-YYYMMDDXXX，傳回 JS Date 物件
            function bomStrToDate(bomStr) {
                if (!bomStr || !/^B-\d{9,}$/.test(bomStr)) return null;
                const yyy = parseInt(bomStr.substr(2, 3), 10);
                const mm = parseInt(bomStr.substr(5, 2), 10);
                const dd = parseInt(bomStr.substr(7, 2), 10);
                if (isNaN(yyy) || isNaN(mm) || isNaN(dd)) return null;
                const yyyy = yyy + 1911;
                return new Date(`${yyyy}-${String(mm).padStart(2, '0')}-${String(dd).padStart(2, '0')}`);
            }

            // --- 新增：顯示最近兩筆出貨紀錄 ---
            if (Array.isArray(row.shipment_history) && row.shipment_history.length > 0) {
                const historyContainer = document.createElement('div');
                historyContainer.style.fontSize = '10px';
                historyContainer.style.color = '#555';
                historyContainer.style.marginTop = '4px';

                // 基準日：優先使用訂單日期，若無則用BOM日期
                // 修正：不論是訂單號碼或BOM號碼，都使用同一個、更全面的 parseOrderOrBomDate 函數來解析。
                // 這樣可以確保如 '1130227-2' 格式的 BOM 號碼也能被正確處理。
                const referenceDate = parseOrderOrBomDate(row.Order_oo) || parseOrderOrBomDate(row.bom);

                // console.log('BOM/訂單:', row.bom, row.Order_oo, '基準日:', referenceDate);

                // 過濾基準日之後的出貨紀錄
                let filteredHistory = row.shipment_history.filter(shipment => {
                    // 修正：如果沒有基準日，則不過濾，顯示所有出貨紀錄（最多三筆）
                    // 這樣可以處理 BOM 和訂單都無法解析出日期的情況
                    if (!referenceDate) return true; 

                    // 修正：使用後端新提供的 shipment_iso_date 欄位 ('YYYY-MM-DD') 來建立 UTC 日期，
                    // 避免使用可能被污染的 Order_date 欄位。
                    const shipmentDateUTC = new Date(shipment.shipment_iso_date + 'T00:00:00Z');
                    return shipmentDateUTC.getTime() >= referenceDate.getTime();
                });

                // 按出貨日距離基準日的天數排序，愈接近愈前面
                filteredHistory = filteredHistory.sort((a, b) => {
                    // 修正：確保排序時也使用 UTC 日期進行比較，避免時區問題
                    if (!referenceDate) return 0; // 如果沒有基準日，保持原始順序

                    const aDate = new Date(a.shipment_iso_date + 'T00:00:00Z');
                    const bDate = new Date(b.shipment_iso_date + 'T00:00:00Z');
                    return Math.abs(aDate - referenceDate) - Math.abs(bDate - referenceDate);
                });

                // 取最接近基準日的三筆
                const closestThree = filteredHistory.slice(0, 3);

                // 最後再按新到舊排列，以顯示最新的在最上面
                closestThree.sort((a, b) => new Date(b.shipment_iso_date) - new Date(a.shipment_iso_date));

                // 只顯示這三筆出貨紀錄與 log
                closestThree.forEach(shipment => {
                    const shipmentDate = new Date(shipment.shipment_iso_date);
                    // DEBUG：只印這三筆
                    // console.log(
                    //     "BOM/訂單:", row.bom, row.Order_oo,
                    //     "| 基準日:", referenceDate && referenceDate.toISOString().slice(0,10),
                    //     "| 出貨日:", shipment.shipment_iso_date, `（${shipmentDate.toISOString().slice(0,10)}）`
                    // );
                    const historyLine = document.createElement('div');
                    // 隱藏除錯用的完整出貨日期
                    let displayDate = shipment.formatted_date;
                    if (displayDate && displayDate.includes('-')) {
                        const parts = displayDate.split('-');
                        if (parts.length === 3) {
                            if (parseInt(parts[0], 10) === new Date().getFullYear()) {
                                displayDate = parts[1] + '-' + parts[2]; // 若為今年，只顯示 MM-DD
                            }
                        }
                    }
                    historyLine.innerHTML = `出 ${displayDate} x${shipment.Qty}`; // DEBUG: ` <small style="color:#999;">(${shipment.shipment_iso_date})</small>`
                    historyContainer.appendChild(historyLine);
                });

                tdClient.appendChild(historyContainer);
            }

            // --- 新增：客戶欄位雙擊事件監聽器 ---
            tdClient.addEventListener('dblclick', function() {
                const clientNameFromRow = String(row.Client_Name || '').trim(); // 直接從 rowData 取客戶名稱
                const customerFilterInput = document.getElementById('customer-filter');

                if (customerFilterInput) {
                    if (customerFilterInput.value.trim() !== "") { // 如果篩選框已有內容
                        customerFilterInput.value = ""; // 清空篩選框
                        console.log(`雙擊客戶欄位 ("${clientNameFromRow}")，已清空客戶篩選框並觸發篩選。`);
                    } else if (clientNameFromRow) { // 如果篩選框為空，且儲存格客戶名稱不為空
                        customerFilterInput.value = clientNameFromRow; // 將客戶名稱填入篩選框
                        console.log(`雙擊客戶欄位，已將 "${clientNameFromRow}" 設定到客戶篩選框並觸發篩選。`);
                    }
                    // 如果篩選框為空且儲存格客戶名稱也為空，則不執行任何操作，但仍會觸發篩選
                    processAndRenderData(); // 觸發篩選
                }
            });

            tr.appendChild(tdClient);

            // --- 2. 交期x數量 ---
            var tdDelivery = document.createElement('td');
            tdDelivery.setAttribute('name', 'Delivery_date');
            // tdDelivery.style.fontSize = '12px'; // This line is redundant, moved below

            // --- Popover Logic ---
            let popoverContentHtml = '';
            const allShipments = row.shipment_history || [];
            const openOrders = row.OrderList ? row.OrderList.filter(o => o.Open_Qty > 0) : [];

            // ✅ 核心修改：加入與「客戶」欄位相同的基準日與過濾邏輯
            const referenceDateForPopover = parseOrderOrBomDate(row.Order_oo) || parseOrderOrBomDate(row.bom);
            const filteredShipments = allShipments.filter(shipment => {
                if (!referenceDateForPopover) return true; // 若無基準日，則不過濾
                const shipmentDateUTC = new Date(shipment.shipment_iso_date + 'T00:00:00Z');
                return shipmentDateUTC.getTime() >= referenceDateForPopover.getTime();
            });
            // 最後再按新到舊排列
            filteredShipments.sort((a, b) => new Date(b.shipment_iso_date) - new Date(a.shipment_iso_date));


            if (filteredShipments.length > 0 || openOrders.length > 0) {
                popoverContentHtml += '<div class="popover-grid">';

                // Shipment records
                // ✅ 核心修改：使用過濾後的 filteredShipments
                filteredShipments.forEach(shipment => {
                    popoverContentHtml += `
                        <div style="color: green;">出貨</div>
                        <div>${escapeHtml(formatDateAsMd(shipment.formatted_date))}</div>
                        <div>x${escapeHtml(shipment.Qty)}</div>
                        <div class="popover-spec" style="color: green;">${escapeHtml(shipment.Specification)}</div>
                    `;
                });

                // Open order records
                openOrders.forEach(order => {
                    const deliveryDate = order.Delivery_date ? new Date(order.Delivery_date + 'T00:00:00Z') : null;
                    const formattedDeliveryDate = deliveryDate ? `${deliveryDate.getUTCMonth() + 1}/${deliveryDate.getUTCDate()}` : '無';
                    popoverContentHtml += `
                        <div style="color: #00008B;">交期</div>
                        <div>${formattedDeliveryDate}</div>
                        <div>x${escapeHtml(order.Open_Qty)}</div>
                        <div class="popover-spec" style="color: #00008B;">${escapeHtml(order.Specification)}</div>
                    `;
                });

                popoverContentHtml += '</div>';
            } else {
                popoverContentHtml = '無紀錄';
            }

            tdDelivery.setAttribute('data-toggle', 'popover');
            tdDelivery.setAttribute('data-content', popoverContentHtml);
            // --- End Popover Logic ---
            tdDelivery.style.fontSize = '12px';

            let mainDeliveryText = ""; // This will hold "交期x數量"
            let openQtyDisplayInfo = ""; // This will hold "(未交X)"
            let manualDeliveryHtml = ""; // 新增：手動交期顯示 HTML

            var deliveryTextSpan = document.createElement('span');
            deliveryTextSpan.className = 'delivery-text-display'; // 給 span 一個 class 以便選取
            deliveryTextSpan.style.fontSize = '12px'; // 讓文字大小與下拉選單一致

            if (row.Order_id === 'B') {
                mainDeliveryText = "備庫";
            } else if (!row.OrderList || !Array.isArray(row.OrderList) || row.OrderList.length === 0) {
                if (row.Delivery_date && row.Delivery_date !== '0000-00-00') {
                    mainDeliveryText = escapeHtml(row.Delivery_date) + '<small style="color:red;">(手動)</small>';
                    openQtyDisplayInfo = '';
                } else {
                    mainDeliveryText = '無訂單'; // 或者留空，取決於您希望如何顯示
                }
            } else {
                var currentOrder = row.OrderList.find(o => o && String(o.Order_id) === String(row.Order_id));
                var selectedInList = !!currentOrder; // True if currentOrder is found

                if (selectedInList) {
                    // If Delivery_date is present and not an empty string
                    if (currentOrder.Delivery_date && String(currentOrder.Delivery_date).trim() !== "") {
                        // Qty can be 0. If Qty is null or undefined, display as '0'.
                        let qtyDisplay = (currentOrder.Qty === null || typeof currentOrder.Qty === 'undefined') ? '0' : currentOrder.Qty; // No change
                        mainDeliveryText = escapeHtml(String(currentOrder.Delivery_date)) + 'x' + escapeHtml(String(qtyDisplay));

                        if (currentOrder.Open_Qty != currentOrder.Qty) { // Only show if Open_Qty is different from Qty
                            let openQtyVal = (currentOrder.Open_Qty === null || typeof currentOrder.Open_Qty === 'undefined') ? '?' : currentOrder.Open_Qty;
                            openQtyDisplayInfo = `(未交${escapeHtml(String(openQtyVal))})`;
                        }

                        // 若有手動交期，將原文字畫刪除線，並準備手動交期 HTML
                        if (row.Delivery_date && row.Delivery_date !== '0000-00-00') {
                            mainDeliveryText = `<span style="text-decoration: line-through; color: #999;">${mainDeliveryText}</span>`;
                            manualDeliveryHtml = `<div>${escapeHtml(row.Delivery_date)}<small style="color:red;">(手動)</small></div>`;
                        }
                    } else {
                        mainDeliveryText = "無交期"; // Only show "無交期" if Delivery_date itself is missing/empty
                        // 即使無訂單交期，若有手動交期也要顯示
                        if (row.Delivery_date && row.Delivery_date !== '0000-00-00') {
                            manualDeliveryHtml = `<div>${escapeHtml(row.Delivery_date)}<small style="color:red;">(手動)</small></div>`;
                        }
                    }
                } else if (row.Order_id && row.Order_id !== 'B' && row.Order_id !== '') {
                    mainDeliveryText = "訂單資訊缺失";
                    if (row.Delivery_date && row.Delivery_date !== '0000-00-00') {
                        manualDeliveryHtml = `<div>${escapeHtml(row.Delivery_date)}<small style="color:red;">(手動)</small></div>`;
                    }
                } else {
                    // ✅ 修正：當 OrderList 有資料但 Order_id 為空（尚未選取訂單）時的顯示邏輯
                    if (row.Delivery_date && row.Delivery_date !== '0000-00-00') {
                        mainDeliveryText = escapeHtml(row.Delivery_date) + '<small style="color:red;">(手動)</small>';
                    } else {
                        mainDeliveryText = "無訂單";
                    }
                }
            }

            // --- 下拉選單部分 ---
            var _hasBoundOrder = row.OrderList && Array.isArray(row.OrderList) && row.OrderList.length > 0 &&
                row.Order_id && String(row.Order_id).trim() !== '' && row.Order_id !== 'B';

            if (_hasBoundOrder) {
                // ✅ 修復：只顯示已綁定的那一筆訂單（row.Order_id 對應的），而非整個 OrderList
                function _formatOo(oo) {
                    oo = String(oo || '').trim();
                    if (oo.length >= 12) {
                        var fp = oo.substring(0, 9);
                        if (fp.startsWith('OO')) fp = fp.substring(2);
                        return fp + '-' + parseInt(oo.substring(9, 12), 10);
                    }
                    return oo;
                }
                // 手動交期優先顯示在最上方
                if (row.Delivery_date && row.Delivery_date !== '0000-00-00') {
                    var manualDiv = document.createElement('div');
                    manualDiv.style.cssText = 'font-size:11px;color:red;font-weight:bold;margin-bottom:2px;';
                    manualDiv.innerHTML = escapeHtml(row.Delivery_date) + '<small>（手動）</small>';
                    tdDelivery.appendChild(manualDiv);
                }

                // ✅ 遍歷所有綁定訂單進行顯示
                boundOrders.forEach(function(bo, bIdx) {
                    var _boundOrder = row.OrderList.find(function(o) {
                        return o && String(o.Order_id) === String(bo.id);
                    });

                    if (!_boundOrder) return;

                    var wrap = document.createElement('div');
                    wrap.style.cssText = 'font-size:11px;margin-top:2px;line-height:1.3;' + (boundOrders.length > 1 ? 'border-bottom:1px dotted #eee;padding-bottom:3px;margin-bottom:3px;' : '');
                    var dateStr = _boundOrder.Delivery_date || '-';
                    var openQ = parseInt(_boundOrder.Open_Qty || 0, 10);
                    var openQStr = openQ > 0 ? '（未交' + openQ + '）' : '';
                    var ooLabel = _formatOo(_boundOrder.Order_oo);
                    // 若有分配數量則顯示分配數，否則顯示訂單總數
                    var displayQty = (bo.pcs && bo.pcs !== '0') ? bo.pcs : (_boundOrder.Qty || '-');

                    // ✅ 訂單編號緊貼交期，小字樣式同 BOM 欄進度備註
                    wrap.innerHTML =
                        '<span style="color:#333;display:block;">' + escapeHtml(dateStr) + '×' + escapeHtml(String(displayQty)) + openQStr + '</span>' +
                        '<span style="color:#0056b3;display:block;font-size:0.85em;margin-top:1px;line-height:1.2;white-space:nowrap;"><i class="fa fa-tag" style="margin-right:2px;font-size:9px;"></i>' + escapeHtml(ooLabel) + '</span>';
                    tdDelivery.appendChild(wrap);
                });
            } else {
                // 非綁定：輸出 deliveryTextSpan（含手動交期、備庫、無訂單等）
                // mainDeliveryText 和 manualDeliveryHtml 已在上方邏輯中處理手動交期
                deliveryTextSpan.innerHTML = mainDeliveryText + manualDeliveryHtml;
                tdDelivery.appendChild(deliveryTextSpan);
                if (openQtyDisplayInfo.trim() !== '') {
                    var oqSpan = document.createElement('span');
                    oqSpan.style.cssText = 'display:block;color:red;font-size:0.8em;';
                    oqSpan.textContent = openQtyDisplayInfo;
                    tdDelivery.appendChild(oqSpan);
                }
            }
            // 交期欄位下拉選單已移除（order_list 舊交期功能廢棄）
            tr.appendChild(tdDelivery); // 將 td 加入 tr



            // --- 3. BOM ---
            var tdBom = document.createElement('td');
            tdBom.setAttribute('name', 'BOM');
            tdBom.innerHTML = generateBomHtml(row, baseExcelUrl); // 假設 generateBomHtml 返回安全的 HTML
            // ── 批次管理按鈕（BOM 欄底部）──
            // 2026-07-16 拆批/合併功能停用：僅測試資料使用過、正式 BOM 未使用，
            // 依使用者指示註解掉操作入口（Modal、後端 API、拆分批次顯示邏輯均保留，
            // 要恢復功能只需解除本段註解）。
            /*
            if (window.userStatus == 1 || window.featBatchOp) {
                var batchBtn = document.createElement('button');
                batchBtn.type = 'button';
                batchBtn.className = 'btn btn-xs btn-default';
                batchBtn.title = '批次拆分/合併管理';
                batchBtn.innerHTML = '<i class="fa fa-sitemap"></i> 批次';
                batchBtn.style.cssText = 'margin-top:4px;padding:1px 5px;font-size:10px;line-height:1.3;display:block;';
                (function(b){ batchBtn.onclick = function(e){ e.stopPropagation(); openBatchMgmt(b); }; })(row.bom);
                tdBom.appendChild(batchBtn);
            }
            */
            tr.appendChild(tdBom);

            // --- 新增：BOM 欄位雙擊事件監聽器 ---
            tdBom.addEventListener('dblclick', function() {
                const bomValue = String(row.bom || '').trim();
                const bomSearchInput = document.getElementById('bom-filter');
                if (bomSearchInput) {
                    if (bomSearchInput.value.trim() !== "") {
                        bomSearchInput.value = "";
                        console.log(`雙擊BOM欄位 ("${bomValue}")，已清空 BOM/料號 搜索框。`);
                    } else if (bomValue) {
                        bomSearchInput.value = bomValue;
                        console.log(`雙擊BOM欄位，已將 "${bomValue}" 設定到 BOM/料號 搜索框。`);
                    }
                    processAndRenderData();
                }
            });

            // --- 4. 料號 ---
            var tdDid = document.createElement('td'); // 建立料號的 <td>
            tdDid.setAttribute('name', 'd_id');

            // --- 產生 QR Code 按鈕 ---
            var qrCodeButtonHtmlString = `
                <button type="button" 
                        class="btn btn-xs btn-default qr-code-btn-tooltip js-show-qr-modal" 
                        style="margin-right: 3px; padding: 1px 5px; display: inline-flex; align-items: center; justify-content: center;" 
                        data-modal-id="myModal_qrcode_${escapeHtml(row.bom_ing_fid)}" 
                        title="顯示QR Code">
                    <i class="fa fa-qrcode" style="font-size: 1.2em;"></i>
                </button>`;
            var tempQrDiv = document.createElement('div');
            tempQrDiv.innerHTML = qrCodeButtonHtmlString.trim();
            var qrCodeButtonElement = tempQrDiv.firstChild;
            tdDid.appendChild(qrCodeButtonElement); // <-- QR Code 按鈕先加進 <td>


            // --- 先建立複製按鈕 ---
            var copyBtnDid = document.createElement('button');
            copyBtnDid.type = 'button'; // 確保 type 是 button
            copyBtnDid.className = 'btn btn-xs btn-copy'; // 套用你的按鈕樣式
            copyBtnDid.innerHTML = '<i class="fa fa-copy"></i>'; // Font Awesome 圖示
            copyBtnDid.title = '複製料號';
            // 綁定點擊事件，傳遞料號 (row.d_id) 和按鈕元素本身 (this)
            copyBtnDid.onclick = function(e) {
                e.stopPropagation();
                copyToClipboard(row.d_display || row.d_id, this);
                console.log('[複製料號ID] d_display:', row.d_display || '(無)', '| d_id(顯示):', row.d_id);
            };
            tdDid.appendChild(copyBtnDid); // <-- 按鈕先加進 <td>

            // --- 再建立料號連結 ---
            const linkDid = document.createElement('a');
            linkDid.href = "javascript:void(0);";
            linkDid.style.textDecoration = "none";
            linkDid.style.color = "inherit";
            linkDid.style.cursor = "pointer";
            linkDid.onclick = (function(r){ return function(e) { e.preventDefault(); openBomFiles(r.bom, r.d_display || r.d_id); }; })(row);
            linkDid.textContent = row.d_display || row.d_id;
            tdDid.appendChild(linkDid);

            // --- 料號別名（Drawing_No）---
            if (row.d_drawing_no && row.d_drawing_no !== (row.d_display || row.d_id)) {
                var aliasDiv = document.createElement('div');
                aliasDiv.style.cssText = 'font-size:11px;color:#1a7abf;margin-top:1px;';
                aliasDiv.textContent = '代：' + row.d_drawing_no;
                tdDid.appendChild(aliasDiv);
            }

            // --- 料號綁定狀態圖示（綠色鏈結 / 紅色斷鏈）---
            var _hasDsettingBound = !!(row.d_setting_id && String(row.d_setting_id).trim() !== '');
            var _hasOrders = Array.isArray(row.OrderList) && row.OrderList.length > 0 &&
                !(row.OrderList.length === 1 && (!row.OrderList[0].Order_oo || row.OrderList[0].Order_oo.trim() === ''));
            var _isStock = row.Order_id === 'B';
            // ✅【修正】以 boundOrders（來自 bound_orders_info）為主要判斷來源
            //   bound_orders_info 有值 → 已透過 bom_order_process_map 綁定 → 顯示綠色鏈結
            //   否則 fallback 到 Order_id 不是 B 且不為空
            var _isBound = (boundOrders.length > 0) ||
                !!(row.bound_orders_info && String(row.bound_orders_info).trim() !== '') ||
                (!_isStock && row.Order_id && String(row.Order_id).trim() !== '' && row.Order_id !== 'B');
            if (!_isStock) {
                var bindIconSpan = document.createElement('span');
                bindIconSpan.style.cssText = 'margin-left: 4px;';
                if (_isBound) {
                    var _boundLabel = boundOrders.length
                        ? boundOrders.map(function(b){ return b.oo || b.id; }).join(', ')
                        : (row.bound_orders_info ? '已綁定' : String(row.Order_id || ''));
                    var boundTitle = '已綁定訂單：' + _boundLabel;
                    bindIconSpan.innerHTML = '<i class="fa fa-chain" style="color: #28a745;" title="' + escapeHtml(boundTitle) + '"></i>';
                } else if (_hasOrders && !_hasDsettingBound) {
                    bindIconSpan.innerHTML = '<i class="fa fa-chain-broken" style="color: #dc3545; cursor:pointer;" title="未綁定訂單，點擊快速篩選"></i>';
                    bindIconSpan.onclick = function(e) {
                        e.stopPropagation();
                        document.getElementById('status-filter').value = 'unbound_order';
                        processAndRenderData();
                    };
                }
                tdDid.appendChild(bindIconSpan);
            }

            // --- 快速綁定料號按鈕：已有綁定料號(d_setting_id有值)時不顯示 ---
            // _hasDsettingBound 已於上方宣告
            if (window.userStatus == 1 && window.displayPermissionCode !== 'D+R' && !_hasDsettingBound) {
                var quickBindBtn = document.createElement('button');
                quickBindBtn.type = 'button';
                quickBindBtn.className = 'btn btn-xs btn-default';
                quickBindBtn.style.cssText = 'margin-left:4px; padding:1px 4px; font-size:10px;';
                quickBindBtn.innerHTML = '<i class="fa fa-search"></i>';
                quickBindBtn.title = '快速搜尋料號設定 (d_setting)';
                quickBindBtn.onclick = (function(rowRef) {
                    return function(e) {
                        e.stopPropagation();
                        openQuickBindDsetting(rowRef, quickBindBtn);
                    };
                })(row);
                tdDid.appendChild(quickBindBtn);
            }
            tr.appendChild(tdDid); // <-- 將包含按鈕和連結的 td 加入 tr

            // --- 新增：有新製程報工 提示 ---
            if (row.has_new_process_report) {
                tdDid.insertAdjacentHTML('beforeend', '<div style="color:red; font-weight:bold; font-size:11px; margin-top:2px;">有新製程報工!!</div>');
            }

            // ── 急迫資訊列（左色條+hover詳細，無外框）──
            var urgencyBarDiv = document.createElement('div');
            urgencyBarDiv.className = 'urgency-score-bar';
            urgencyBarDiv.setAttribute('data-bom', row.bom || '');
            urgencyBarDiv.style.cssText = 'margin-top:3px;min-height:0;';
            var _cachedImpact = window._impactCache && window._impactCache[row.bom];
            var _cachedBuffer = window._bufferCache && window._bufferCache[row.bom];
            var _cachedUrgent  = window._urgentCache && window._urgentCache[row.bom];
            if (_cachedImpact && _cachedImpact.success) {
                urgencyBarDiv.innerHTML = buildUrgencyBarHtml(row.bom, _cachedImpact, _cachedBuffer, _cachedUrgent);
            }
            tdDid.appendChild(urgencyBarDiv);

            if (window.displayPermissionCode === 'A' || window.displayPermissionCode === 'C+D+R' || window.featSeePrice) {
                const _tpm2 = window.transferPriceMap || {};
                let _totalPrice = 0, _noPriceCount = 0, _processCount = 0;
                if (window.bomPSList && Array.isArray(window.bomPSList)) {
                    const _procs = window.bomPSList.filter(p => p && p.bom && p.bom.toString().trim() === String(row.bom||'').trim());
                    _processCount = _procs.length;
                    _procs.forEach(function(p) {
                        const _pi2 = (_tpm2[row.bom] || {})[String(p.bom_sn||'')] || null;
                        // 總單價 = 各製程單價加總（不乘數量）
                        const _rawP = _pi2 ? (parseFloat(_pi2.modified_unit_price)||parseFloat(_pi2.price)||0) : 0;
                        if (_rawP > 0) { _totalPrice += _rawP; } else { _noPriceCount++; }
                    });
                }
                if (_processCount > 0) {
                    const _pd = document.createElement('div');
                    _pd.style.cssText = 'margin-top:3px; font-size:11px; line-height:1.3;';
                    let _ph = _totalPrice > 0
                        ? `<span style="color:#0a6; font-weight:bold;">$${_totalPrice%1===0?_totalPrice.toFixed(0):_totalPrice.toFixed(1)}</span>`
                        : `<span style="color:#ccc;">$--</span>`;
                    if (_noPriceCount > 0) _ph += ` <span style="color:#aaa; font-size:10px;">(${_noPriceCount}關無價)</span>`;
                    _pd.innerHTML = _ph;
                    tdDid.appendChild(_pd);
                }
            }

            // --- 新增：料號欄位雙擊事件監聽器 ---
            tdDid.addEventListener('dblclick', function() {
                const partNumberFromCell = String(row.d_id || '').trim(); // Get d_id from row data for accuracy
                const bomSearchInput = document.getElementById('bom-filter'); // 改為搜索 BOM/料號 輸入框

                if (bomSearchInput) {
                    if (bomSearchInput.value.trim() !== "") { // If bom-filter has content
                        bomSearchInput.value = ""; // Clear it
                        console.log(`雙擊料號欄位 ("${partNumberFromCell}")，已清空 BOM/料號 搜索框。`);
                    } else if (partNumberFromCell) { // If bom-filter is empty AND cell has a part number
                        bomSearchInput.value = partNumberFromCell; // Set it to the part number
                        console.log(`雙擊料號欄位，已將 "${partNumberFromCell}" 設定到 BOM/料號 搜索框。`);
                    }
                    // Always re-filter, even if nothing changed, to maintain consistent behavior
                    processAndRenderData(); // 觸發篩選
                }
            });

            // --- 發單日 (outsource_date) - 逐製程顯示，支援多製程並存 ---
            var tdOutsourceDate = document.createElement('td');
            tdOutsourceDate.setAttribute('name', 'outsource_date');
            tdOutsourceDate.style.lineHeight = '1.2';
            tdOutsourceDate.style.fontSize = '12px';

            // BOM 總數標頭
            var _qtyHdr = document.createElement('div');
            _qtyHdr.style.cssText = 'margin:0;padding:0;line-height:1.2;';
            _qtyHdr.innerHTML = '<span style="font-size:1.2em;font-weight:bold;color:#006400;">' + escapeHtml(String(row.Qty || '')) + '</span>x';
            tdOutsourceDate.appendChild(_qtyHdr);

            // 取本 BOM 的所有進行中製程（ingActiveMap 由 PHP 及 AJAX 刷新後建立）
            var _activeProcs = (window.ingActiveMap || {})[String(row.bom || '').trim()] || [];
            var _lastBtnRow = null; // 記錄最後一個狀態按鈕列

            if (_activeProcs.length === 0) {
                var _fbDiv = document.createElement('div');
                _fbDiv.style.cssText = 'margin:0;padding:0;line-height:1.2;';
                var _fbDate = formatOutsourceDateForDisplay(row.outsource_date);
                var _fbMaker = escapeHtml(row.maker_id || '');
                var _fbStateVal = String(row.processing_state || '').trim();
                // 有有效狀態（Q/P/ing/E）且 ingActiveMap 資料暫缺時，顯示狀態文字作為安全備援
                // 其餘情況（未發包、僅 N 狀態）統一顯示「未發包」
                if (_fbStateVal && _fbStateVal !== 'N' && (_fbDate || _fbMaker)) {
                    _fbDiv.innerHTML = escapeHtml(row.ProcessName || '') +
                        (_fbDate ? (' ' + _fbDate) : '') + (_fbMaker ? (' ' + _fbMaker) : '') +
                        ' (' + translateProcessingState(_fbStateVal) + ')';
                } else {
                    _fbDiv.style.color = '#999';
                    _fbDiv.style.fontSize = '11px';
                    _fbDiv.textContent = '未發包';
                }
                tdOutsourceDate.appendChild(_fbDiv);
            } else {
                // 判斷是否有拆分批次：同一 bom_sn 有多個批次（含 E 狀態的已移轉批次，只要有 batch_label 就計入）
                var _snCounts = {};
                _activeProcs.forEach(function(p) {
                    if (p.processing_state !== 'E' || p.batch_label) {
                        _snCounts[p.bom_sn] = (_snCounts[p.bom_sn] || 0) + 1;
                    }
                });
                var _hasActiveSplit = Object.keys(_snCounts).some(function(sn) { return _snCounts[sn] > 1; });

                // ── 預計算燈號 HTML（在 forEach 前完成，讓 forEach 裡直接用）──────────
                var _preOverallState = _activeProcs.length > 0 ? _activeProcs[_activeProcs.length - 1].processing_state : (row.processing_state || '');
                var _preBomTotalSqty = parseFloat(row.Qty) || 0;
                var _preQqSqty = parseFloat(row.qc_qq_qty) || 0;
                var _preOkSqty = parseFloat(row.qc_ok_qty) || 0;
                var _preHasActiveQcOk = _activeProcs.some(function(p) {
                    return (p.qc_completed == 1 && p.QC_check !== 'ng') || p.QC_check === 'ok' || p.QC_check === 'AOD';
                });
                var _preCalcOkSqty = _activeProcs
                    .filter(function(p) { return (p.qc_completed == 1 && p.QC_check !== 'ng') || p.QC_check === 'ok' || p.QC_check === 'AOD'; })
                    .reduce(function(s, p) { return s + (parseFloat(p.sqty) || 0); }, 0);
                var _preDisplayOkSqty = (_preHasActiveQcOk && _preCalcOkSqty > 0) ? _preCalcOkSqty : _preOkSqty;
                var _preAdjOkQty = _preHasActiveQcOk ? _preDisplayOkSqty : 0;
                var _preTotalChecked = _preQqSqty + (parseFloat(row.qc_ng_qty)||0) + (parseFloat(row.qc_aod_qty)||0) + _preAdjOkQty;
                var _preShowGray  = _preBomTotalSqty > 0 && _preTotalChecked < _preBomTotalSqty;
                var _preShowGreen = _preHasActiveQcOk && _preDisplayOkSqty > 0;
                var _preLightsHtml = '';
                if (_preShowGray) _preLightsHtml += '<figure class="circle_gray" style="margin-left:5px;margin-right:3px;"></figure>';
                if (_preQqSqty > 0) {
                    var _preYellowTip = '待驗明細';
                    if (Array.isArray(row.qq_details) && row.qq_details.length > 0) {
                        _preYellowTip = row.qq_details.map(function(d) {
                            return (d.ProcessName ? '['+escapeHtml(d.ProcessName)+'] ' : '') + escapeHtml(d.qc_date_formatted||'') + ': ' + escapeHtml(String(d.QC_QQ_sqty||'')) + ' pcs' + (d.QC_ps ? ' ('+escapeHtml(d.QC_ps)+')' : '');
                        }).join('<br>');
                    }
                    _preLightsHtml += '<figure class="circle_yo" style="margin-left:5px;margin-right:3px;cursor:pointer;" data-toggle="popover" data-placement="top" data-container="body" data-trigger="hover" data-html="true" title="異常明細" data-content="' + _preYellowTip.replace(/"/g,'&quot;') + '"></figure>';
                    _preLightsHtml += '<small>' + _preQqSqty + '</small>';
                }
                if (_preShowGreen) {
                    var _preGreenTip = '無允收明細';
                    if (Array.isArray(row.ok_details) && row.ok_details.length > 0) {
                        _preGreenTip = row.ok_details.map(function(d) {
                            return (d.ProcessName ? '<span style="color:#0b5e0b;font-weight:bold;">['+escapeHtml(d.ProcessName)+']</span> ' : '') + escapeHtml(d.qc_date_formatted||'') + ': ' + escapeHtml(String(d.QC_ok_sqty||'')) + ' pcs' + (d.QC_ps_ok ? ' ('+escapeHtml(d.QC_ps_ok)+')' : '');
                        }).join('<br>');
                    }
                    _preLightsHtml += '<figure class="circle_greenS" style="margin-left:5px;margin-right:3px;cursor:pointer;" data-toggle="popover" data-placement="top" data-container="body" data-trigger="hover" data-html="true" title="允收明細" data-content="' + _preGreenTip.replace(/"/g,'&quot;') + '"></figure>';
                    var _preShowQty = !(_preOverallState === 'P' && !_preShowGray && _preQqSqty === 0 && _preDisplayOkSqty === _preBomTotalSqty);
                    if (_preShowQty) _preLightsHtml += '<small>' + _preDisplayOkSqty + '</small>';
                }
                var _lightsAttached = false; // 燈號是否已在 forEach 裡附加

                var _displayProcs;
                if (_hasActiveSplit) {
                    // 拆分批次模式：有 batch_label 的批次全部顯示（含 E 已移轉），無 batch_label 的排除 E
                    _displayProcs = _activeProcs.filter(function(p) {
                        return (p.batch_label || p.processing_state !== 'E') && (p.outsource_date || p.batch_label);
                    });
                    _displayProcs.sort(function(a, b) {
                        var snA = parseInt(a.bom_sn || 0, 10), snB = parseInt(b.bom_sn || 0, 10);
                        if (snA !== snB) return snA - snB;
                        return String(a.batch_label || '').localeCompare(String(b.batch_label || ''));
                    });
                } else {
                    // 一般批次模式：原有最新移轉日邏輯
                    var _procsWithDate = _activeProcs.filter(function(p) { return p.outsource_date; });
                    var _maxDateVal = -Infinity;
                    _procsWithDate.forEach(function(p) {
                        var _dp = String(p.outsource_date).split('/');
                        var _dv = _dp.length >= 3 ? new Date(parseInt(_dp[0],10), parseInt(_dp[1],10)-1, parseInt(_dp[2],10)).getTime() : -Infinity;
                        if (_dv > _maxDateVal) _maxDateVal = _dv;
                    });
                    var _latestProcs = _procsWithDate.filter(function(p) {
                        var _dp = String(p.outsource_date).split('/');
                        var _dv = _dp.length >= 3 ? new Date(parseInt(_dp[0],10), parseInt(_dp[1],10)-1, parseInt(_dp[2],10)).getTime() : -Infinity;
                        return _dv === _maxDateVal;
                    });
                    _displayProcs = _latestProcs.filter(function(p) { return p.processing_state !== 'E'; });
                    if (_displayProcs.length === 0 && _latestProcs.length > 0) {
                        var _maxSnProc = _latestProcs.reduce(function(best, p) {
                            return parseInt(p.bom_sn || 0, 10) > parseInt(best.bom_sn || 0, 10) ? p : best;
                        }, _latestProcs[0]);
                        _displayProcs = [_maxSnProc];
                    }
                }
                _displayProcs.forEach(function(_proc, _pi) {
                    var _pDiv = document.createElement('div');
                    _pDiv.style.cssText = 'margin-top:' + (_pi === 0 ? '1' : '4') + 'px;' +
                        (_pi > 0 ? 'padding-top:3px;border-top:1px dotted #ddd;' : '') + 'line-height:1.2;';
                    var _nameDiv = document.createElement('div');
                    _nameDiv.style.fontWeight = '500';
                    // 拆分批次：[標籤] 數量pcs 製程名稱
                    _nameDiv.textContent = (_proc.batch_label
                        ? '['+_proc.batch_label+'] ' + (_proc.sqty != null ? _proc.sqty + 'pcs ' : '')
                        : '') + (_proc.ProcessName || '');
                    _pDiv.appendChild(_nameDiv);
                    var _od = formatOutsourceDateForDisplay(_proc.outsource_date);
                    var _mk = _proc.maker_id || '';
                    if (_od || _mk) {
                        var _dmDiv = document.createElement('div');
                        _dmDiv.style.cssText = 'color:#555;font-size:11px;';
                        _dmDiv.textContent = [_od, _mk].filter(Boolean).join(' ');
                        _pDiv.appendChild(_dmDiv);
                    }
                    var _st  = String(_proc.processing_state || '');
                    var _fid = String(_proc.bom_ing_fid || '');
                    var _iid = String(_proc.bom_ing_id  || '');
                    // qc_completed=1 且 processing_state='Q' → 前端認定為生管待移轉(P)
                    var _isQcCompletedQ = (_st === 'Q' && _proc.qc_completed == 1);
                    var _effectiveSt = _isQcCompletedQ ? 'P' : _st;
                    if (_st === 'ing' && _iid !== '') {
                        var _ingBtn = document.createElement('button');
                        _ingBtn.type = 'button';
                        _ingBtn.className = 'btn btn-xs btn-warning btn-return-style';
                        _ingBtn.textContent = '加工中';
                        _ingBtn.style.marginTop = '2px';
                        if (window.featMarkReturned) {
                            // 有獨立功能碼授權，覆蓋下方 D+R / 業務受限的舊排除規則
                            (function(_id, _excQc, _f) { _ingBtn.onclick = function() { markAsReturned(_id, this, _excQc, _f); }; })(_iid, _proc.is_exclude_qc ? 1 : 0, _fid);
                        } else if (window.displayPermissionCode === 'D+R') {
                            _ingBtn.title = '無權限 (R+D 受限業務)';
                            _ingBtn.style.cursor = 'not-allowed'; _ingBtn.style.opacity = '0.6';
                            _ingBtn.onclick = function(e) { e.preventDefault(); return false; };
                        } else if (window.isCRU) {
                            _ingBtn.title = '無執行權限 (C+R+U 業務受限)';
                            _ingBtn.style.cursor = 'not-allowed'; _ingBtn.style.opacity = '0.6';
                            _ingBtn.onclick = function(e) { e.preventDefault(); return false; };
                        } else {
                            (function(_id, _excQc, _f) { _ingBtn.onclick = function() { if (window.userStatus == 1) markAsReturned(_id, this, _excQc, _f); }; })(_iid, _proc.is_exclude_qc ? 1 : 0, _fid);
                        }
                        var _ingBtnRow = document.createElement('div');
                        _ingBtnRow.style.cssText = 'margin-top:2px;display:flex;align-items:center;justify-content:flex-end;';
                        _ingBtnRow.appendChild(_ingBtn);
                        _pDiv.appendChild(_ingBtnRow);
                        _lastBtnRow = _ingBtnRow;
                    } else if (_st === 'N' && _proc.batch_label) {
                        // 拆分批次尚未發包（N 狀態）→ 顯示待發包標籤
                        var _nRow = document.createElement('div');
                        _nRow.style.cssText = 'margin-top:2px;display:flex;align-items:center;justify-content:flex-end;';
                        var _nBadge = document.createElement('span');
                        _nBadge.className = 'label label-default';
                        _nBadge.style.cssText = 'font-size:9px;';
                        _nBadge.textContent = '待發包';
                        _nRow.appendChild(_nBadge);
                        _pDiv.appendChild(_nRow);
                        _lastBtnRow = _nRow;
                    } else if (_effectiveSt === 'Q' || _effectiveSt === 'P' || _effectiveSt === 'E') {
                        var _btnRow = document.createElement('div');
                        _btnRow.style.cssText = 'margin-top:2px;display:flex;align-items:center;justify-content:flex-end;';
                        var _dh = '';
                        if (_effectiveSt === 'Q' && _proc.return_date) {
                            var _rp = String(_proc.return_date).split('/');
                            if (_rp.length >= 3) _dh = parseInt(_rp[1], 10) + '/' + parseInt(_rp[2], 10) + ' ';
                        } else if (_effectiveSt === 'P') {
                            // 優先 QC_check_date，若無（qc_completed=1 但尚未同步）則用 qc_completed_at
                            var _pDateSrc = _proc.QC_check_date || _proc.qc_completed_at;
                            if (_pDateSrc) {
                                var _qp = String(_pDateSrc).split('/');
                                if (_qp.length >= 3) _dh = parseInt(_qp[1], 10) + '/' + parseInt(_qp[2], 10) + ' ';
                            }
                        }
                        if (_dh) _btnRow.appendChild(document.createTextNode(_dh));
                        var _aBtn = document.createElement('button');
                        _aBtn.type = 'button';
                        if (_effectiveSt === 'Q') { _aBtn.className = 'btn btn-primary btn-xs btn-return-style'; _aBtn.textContent = 'QC待驗'; }
                        else if (_effectiveSt === 'P') { _aBtn.className = 'btn btn-success btn-xs btn-return-style'; _aBtn.textContent = '待移轉'; }
                        else { _aBtn.className = 'btn btn-info btn-xs btn-return-style'; _aBtn.textContent = '已移轉'; }
                        if (window.isCRU && !window.featTransfer) {
                            _aBtn.title = '無執行權限 (C+R+U 業務受限)';
                            _aBtn.style.cursor = 'not-allowed'; _aBtn.style.opacity = '0.6';
                            _aBtn.onclick = function(e) { e.preventDefault(); return false; };
                        } else if (_effectiveSt === 'P' || _effectiveSt === 'E') {
                            (function(_cf) { _aBtn.onclick = function() { if (window.userStatus == 1 || window.featTransfer) submitFormWithBif(_cf); }; })(_fid);
                        } else { _aBtn.onclick = function() {}; }
                        _btnRow.appendChild(_aBtn);
                        _pDiv.appendChild(_btnRow);
                        _lastBtnRow = _btnRow;
                        // 各批次個別燈號：僅 Q/P 顯示燈號，E/ing/N 不顯示
                        var _batchLightsHtml = '';
                        if (_effectiveSt === 'Q') {
                            // QC 待驗中
                            if (_proc.QC_ps) {
                                _batchLightsHtml = '<figure class="circle_gray" style="margin-left:5px;margin-right:3px;cursor:pointer;" data-toggle="popover" data-placement="top" data-container="body" data-trigger="hover" title="QC待驗" data-content="' + escapeHtml(_proc.QC_ps) + '"></figure>';
                            } else {
                                _batchLightsHtml = '<figure class="circle_gray" style="margin-left:5px;margin-right:3px;" title="QC待驗"></figure>';
                            }
                        } else if (_effectiveSt === 'P') {
                            // QC 已完成，待移轉（依 QC_check 決定顏色：ng→紅，其餘→綠）
                            var _batchOkTip = (_proc.QC_check_date ? _proc.QC_check_date + ' ' : '') + (_proc.sqty ? _proc.sqty + ' pcs' : '') + (_proc.QC_ps ? ' ('+_proc.QC_ps+')' : '');
                            var _isNg = (_proc.QC_check === 'ng');
                            _batchLightsHtml = _isNg
                                ? '<figure class="circle_red" style="margin-left:5px;margin-right:3px;cursor:pointer;" data-toggle="popover" data-placement="top" data-container="body" data-trigger="hover" title="判定NG" data-content="' + escapeHtml(_batchOkTip) + '"></figure>'
                                : '<figure class="circle_greenS" style="margin-left:5px;margin-right:3px;cursor:pointer;" data-toggle="popover" data-placement="top" data-container="body" data-trigger="hover" title="允收" data-content="' + escapeHtml(_batchOkTip) + '"></figure>';
                            if (_proc.sqty) _batchLightsHtml += '<small>' + _proc.sqty + '</small>';
                        }
                        if (_batchLightsHtml) {
                            var _batchSpan = document.createElement('span');
                            _batchSpan.style.cssText = 'display:inline-flex;align-items:center;margin-left:4px;flex-shrink:0;';
                            _batchSpan.innerHTML = _batchLightsHtml;
                            _btnRow.appendChild(_batchSpan);
                            _lightsAttached = true;
                        }
                    }
                    tdOutsourceDate.appendChild(_pDiv);
                });
                // P/Q 批次燈已在 forEach 裡附加（_lightsAttached=true）；其餘狀態（ing/E/N）不顯示燈號
            }

            // BOM 層級 QC 燈號（_activeProcs 為空時的 fallback，僅 P/Q 狀態顯示）
            if (_activeProcs.length === 0 && (row.bom_ing_id != null && String(row.bom_ing_id).trim() !== '') && (row.processing_state === 'P' || row.processing_state === 'Q')) {
                var _fbOkSqty = parseFloat(row.qc_ok_qty) || 0;
                var _fbQqSqty = parseFloat(row.qc_qq_qty) || 0;
                var _fbBomQty = parseFloat(row.Qty) || 0;
                var _fbTotalChecked = _fbQqSqty + (parseFloat(row.qc_ng_qty)||0) + (parseFloat(row.qc_aod_qty)||0) + _fbOkSqty;
                var _fbLightsHtml = '';
                if (_fbBomQty > 0 && _fbTotalChecked < _fbBomQty) _fbLightsHtml += '<figure class="circle_gray" style="margin-left:5px;margin-right:3px;"></figure>';
                if (_fbQqSqty > 0) _fbLightsHtml += '<figure class="circle_yo" style="margin-left:5px;margin-right:3px;"></figure><small>' + _fbQqSqty + '</small>';
                if (_fbOkSqty > 0) _fbLightsHtml += '<figure class="circle_greenS" style="margin-left:5px;margin-right:3px;"></figure><small>' + _fbOkSqty + '</small>';
                if (_fbLightsHtml) {
                    var _fbSpan = document.createElement('span');
                    _fbSpan.style.cssText = 'display:inline-flex;align-items:center;margin-left:4px;flex-shrink:0;';
                    _fbSpan.innerHTML = _fbLightsHtml;
                    if (_lastBtnRow) {
                        _lastBtnRow.appendChild(_fbSpan);
                    } else {
                        var _fbWrap = document.createElement('div');
                        _fbWrap.style.cssText = 'margin-top:3px;display:flex;align-items:center;justify-content:flex-end;';
                        _fbWrap.appendChild(_fbSpan);
                        tdOutsourceDate.appendChild(_fbWrap);
                    }
                }
            }
            // --- Display "已過 X 日 / 總 Y 日" or "已過 X 日" when "製程未過半" filter is active or "顯示工作天數" is enabled ---
            // This will be appended below the main three lines.
            if (isProcessNotHalfwayFilterActive || window.settingShowWorkday) {
                const elapsed_workdays = row.elapsed_workdays_outsource_today ?? '-';
                const total_workdays_selected_delivery = row.total_workdays_outsource_to_selected_delivery ?? '-';

                // 計算總製程天數 (製程數 * 設定天數)
                let processCount = 0;
                if (window.bomPSList && Array.isArray(window.bomPSList)) {
                    const currentBom = (row.bom ? String(row.bom).trim() : '');
                    processCount = window.bomPSList.filter(p => p.bom && String(p.bom).trim() === currentBom).length;
                }
                const settingDays = parseFloat(window.settingProcessDays) || 0;
                const totalProcessDays = processCount * settingDays;

                // 比較 "總工作日" (依交期) 和 "總製程天數" (依製程數)，取較小者作為燈號判斷基準顯示
                let finalTotalDaysForDisplay;
                const hasValidTotalWorkdays = typeof total_workdays_selected_delivery === 'number';

                if (hasValidTotalWorkdays) {
                    finalTotalDaysForDisplay = Math.min(totalProcessDays, total_workdays_selected_delivery);
                } else {
                    finalTotalDaysForDisplay = totalProcessDays;
                }

                const workdayInfoDiv = document.createElement('div');
                // Add a class for potential future styling or targeting
                workdayInfoDiv.style.color = 'red'; // Always red
                workdayInfoDiv.style.fontSize = '0.8em';
                workdayInfoDiv.style.marginTop = '2px';
                workdayInfoDiv.style.margin = '0'; // Ensure no extra margin
                workdayInfoDiv.style.padding = '0'; // Ensure no extra padding

                // 僅當 total_workdays_selected_delivery 是一個有效的數字時 (包括 0)，才顯示 " / 總 Y 日"
                // total_workdays_selected_delivery 在 ?? '-' 後可能是數字、字串'-'或空字串""
                if (hasValidTotalWorkdays) {
                    workdayInfoDiv.textContent = `已過 ${elapsed_workdays} 日 / 總 ${total_workdays_selected_delivery} 日(總製程 ${finalTotalDaysForDisplay} 日)`;
                } else {
                    workdayInfoDiv.textContent = `已過 ${elapsed_workdays} 日(總製程 ${finalTotalDaysForDisplay} 日)`;
                }
                tdOutsourceDate.appendChild(workdayInfoDiv);
            }
            // --- Else, if "日內未回" filter is active (and "製程未過半" is not), show its specific text ---
            else if (elapsedDaysFilterValue !== null && typeof elapsedDaysFilterValue === 'number' &&
                row.hasOwnProperty('elapsed_workdays_outsource_today') && row.elapsed_workdays_outsource_today !== null) {
                const elapsedWorkdayDiv = document.createElement('div');
                elapsedWorkdayDiv.style.color = 'red';
                elapsedWorkdayDiv.style.fontSize = '0.8em';
                elapsedWorkdayDiv.style.marginTop = '2px';
                elapsedWorkdayDiv.style.margin = '0'; // Ensure no extra margin
                elapsedWorkdayDiv.style.padding = '0'; // Ensure no extra padding
                elapsedWorkdayDiv.textContent = `已過 ${row.elapsed_workdays_outsource_today} 日`;
                tdOutsourceDate.appendChild(elapsedWorkdayDiv);
            }

            // --- 新增：發單日欄位雙擊事件監聽器 (原廠商欄位功能) ---
            tdOutsourceDate.addEventListener('dblclick', function(event) {
                // 防止觸發到按鈕或 span 的 popover (如果未來發單日欄位有類似元素)
                if (event.target.tagName === 'BUTTON' || event.target.closest('.vendor-name-tooltip-trigger')) {
                    return;
                }
                const makerValue = String(row.maker_id || '').trim(); // 從 rowData 取得廠商名稱
                const vendorFilterInput = document.getElementById('vendor-filter'); // 目標是廠商篩選輸入框
                if (vendorFilterInput) {
                    if (vendorFilterInput.value.trim() !== "") { // 如果篩選框已有內容
                        vendorFilterInput.value = ""; // 清空篩選框
                        console.log(`雙擊發單日欄位，已清空廠商篩選框。`);
                    } else if (makerValue) { // 如果篩選框為空，且儲存格廠商名稱不為空
                        vendorFilterInput.value = makerValue; // 將廠商名稱填入篩選框
                        console.log(`雙擊發單日欄位，已將 "${makerValue}" 設定到廠商篩選框。`);
                    }
                    processAndRenderData(); // 觸發篩選
                }
            });
            tr.appendChild(tdOutsourceDate);

            // --- 5. 製程 ---
            var tdProcessName = document.createElement('td');
            tdProcessName.setAttribute('name', 'ProcessName');
            tdProcessName.textContent = row.process_no + ' ' + row.ProcessName;
            tr.appendChild(tdProcessName);

            // --- 6. 廠商 ---
            var tdMakerId = document.createElement('td'); // Create the <td> for MakerId
            tdMakerId.setAttribute('name', 'MakerId'); // Set its name attribute
            var contentParts = []; // Array to hold parts of the cell content

            // Vendor name text node with Bootstrap Popover
            var vendorName = row.maker_id || '';
            if (vendorName) {
                var vendorSpan = document.createElement('span');
                vendorSpan.textContent = vendorName; // Vendor name text
                vendorSpan.className = 'vendor-name-tooltip-trigger'; // Keep class for potential styling or other JS

                // Popover Content
                let popoverContent = '';
                const tel = String(row.m_tel || '').trim();
                const fax = String(row.m_fax || '').trim();

                if (tel !== '' && tel.toLowerCase() !== 'null') {
                    popoverContent += `TEL：${escapeHtml(tel)}`;
                }

                if (fax !== '' && fax.toLowerCase() !== 'null') {
                    if (popoverContent !== '') { // Add <br> only if TEL was added
                        popoverContent += `<br>`;
                    }
                    popoverContent += `FAX：${escapeHtml(fax)}`;
                }

                // Only set popover attributes and pointer cursor if there is content
                if (popoverContent !== '') {
                    vendorSpan.style.cursor = 'pointer'; // Indicate it's interactive
                    vendorSpan.setAttribute('data-toggle', 'popover');
                    vendorSpan.setAttribute('data-placement', 'top');
                    vendorSpan.setAttribute('data-container', 'body');
                    vendorSpan.setAttribute('data-trigger', 'hover');
                    vendorSpan.setAttribute('data-html', 'true');
                    vendorSpan.setAttribute('data-content', popoverContent);
                }
                // If popoverContent is empty, no popover attributes are set, and no popover will appear.
                contentParts.push(vendorSpan);
            }

            // Translated processing_state in <small> tag
            var translatedState = translateProcessingState(row.processing_state);
            var datePrefix = ''; // Generic prefix for dates
            var trimmedProcessingState = row.processing_state ? String(row.processing_state).trim() : "";

            if (trimmedProcessingState === 'Q' && row.return_date && typeof row.return_date === 'string' && row.return_date.trim() !== '') {
                const dateParts = String(row.return_date).split('/'); // Assuming YYYY/MM/DD
                if (dateParts.length === 3) {
                    // Format as m/d (no leading zeros)
                    datePrefix = `${parseInt(dateParts[1], 10)}/${parseInt(dateParts[2], 10)} `;
                } else {
                    console.warn(`[QC待驗] BOM: ${row.bom}, return_date 格式錯誤: '${row.return_date}'. 預期格式 YYYY/MM/DD.`);
                }
            } else if (trimmedProcessingState === 'P') {
                if (row.QC_check_date && String(row.QC_check_date).trim()) {
                    const dateParts = String(row.QC_check_date).split('/');
                    if (dateParts.length === 3) {
                        datePrefix = `${parseInt(dateParts[1], 10)}/${parseInt(dateParts[2], 10)} `;
                    }
                }
            }

            if (translatedState) {
                var smallState = document.createElement('small');
                smallState.textContent = '(' + datePrefix + translatedState + ')';
                // 根據狀態設定顏色
                if (trimmedProcessingState === 'ing') {
                    smallState.style.color = 'orange';
                } else if (trimmedProcessingState === 'Q') {
                    smallState.style.color = 'blue';
                }
                contentParts.push(smallState);
            }
            // Append all parts to the cell, adding spaces between them
            contentParts.forEach((part, index) => {
                if (index > 0) {
                    tdMakerId.appendChild(document.createTextNode(' '));
                }
                tdMakerId.appendChild(part);
            });
            tr.appendChild(tdMakerId);

            // --- 7. 發單數 ---
            var tdSqty = document.createElement('td');
            tdSqty.setAttribute('name', 'sqty');
            tdSqty.textContent = row.Qty; // <-- 修改：從 row.sqty 改為 row.Qty
            tr.appendChild(tdSqty);

            // --- 9. BOM備註 (bom.bom_ps as textarea) --- (原為第10欄，現移至第9欄)
            var tdBomPs = document.createElement('td');
            tdBomPs.setAttribute('name', 'bom_bom_ps_edit');

            // --- START: 新增邏輯，顯示 bom.bom_ps ---
            var bomALLBomPsText = row.bom_ALL_bom_ps || "";
            if (bomALLBomPsText.trim() !== "") {
                var bomALLBomPsDiv = document.createElement('div');
                bomALLBomPsDiv.innerHTML = bomALLBomPsText.replace(/\n/g, '<br>'); // 處理換行
                bomALLBomPsDiv.style.marginBottom = '8px'; // 與下方內容的間距
                tdBomPs.appendChild(bomALLBomPsDiv);

                // 如果下方還有內容，新增分隔線
                var bomPsTextForCheck = row.bom_bom_ps || "";
                var bomIngPsTextForCheck = row.ps || "";
                if (bomPsTextForCheck.trim() !== "" || bomIngPsTextForCheck.trim() !== "") {
                    const separator = document.createElement('hr');
                    separator.style.borderTop = '1px dashed #ccc';
                    separator.style.marginTop = '8px';
                    separator.style.marginBottom = '8px';
                    tdBomPs.appendChild(separator);
                }
            }
            // --- END: 新增邏輯 ---

            // ⭐ 新增：創建一個 flex 容器來並排顯示製程名稱和輸入框
            var bomPsContainer = document.createElement('div');
            bomPsContainer.style.display = 'flex';
            bomPsContainer.style.alignItems = 'center'; // 垂直居中
            bomPsContainer.style.gap = '5px'; // 名稱和輸入框之間的間距

            // ⭐ 新增：創建顯示製程名稱的 span
            var processNameSpan = document.createElement('span');
            processNameSpan.textContent = row.ProcessName || ''; // 從 row data 取得製程名稱
            processNameSpan.style.whiteSpace = 'nowrap'; // 防止製程名稱換行
            processNameSpan.style.flexShrink = '0'; // 防止名稱被壓縮

            // ⭐ 新增：將製程名稱 span 加入容器
            bomPsContainer.appendChild(processNameSpan);

            var bomPsText = row.bom_bom_ps || ""; // This is bom.bom_ps from backend
            var bomPsLineCount = Math.max(bomPsText.split("\n").length, 1);
            var bomPsTextarea = document.createElement('textarea');
            var bomPsInitialRows = (bomPsLineCount > 3) ? 3 : bomPsLineCount; // Keep this logic for rows attribute

            // BOM備註 (textarea part)
            bomPsTextarea.id = 'single_bet_ps-' + row.bom_ing_fid; // ⭐ 修改：ID 從 bom 改為 bom_ing_fid，以確保唯一性並用於更新
            bomPsTextarea.name = 'single_bet_ps_textarea'; // ⭐ 修改：name 屬性以反映新用途
            bomPsTextarea.rows = bomPsInitialRows;
            bomPsTextarea.setAttribute('data-orig', bomPsText);
            // ⭐ 修改：調整 textarea 樣式以在 flex 容器中正常顯示
            bomPsTextarea.style.cssText = 'resize: none; overflow: hidden; line-height: 1.2em; padding: 2px; width: 100%; box-sizing: border-box; flex-grow: 1;'; // 使用 flex-grow: 1 填滿剩餘空間
            if (bomPsLineCount > 3) {
                bomPsTextarea.style.overflowY = 'scroll';
            }
            // 根據使用者身份設定 textarea 是否唯讀
            if (!(window.userStatus == 1)) {
                bomPsTextarea.readOnly = true;
            }

            bomPsTextarea.value = bomPsText;
            // Event listeners for the textarea
            bomPsTextarea.addEventListener('input', function() {
                autoResize(this);
            });
            bomPsTextarea.addEventListener('keydown', function(event) {
                handleBomPsKeyDown(event, this, row.bom_ing_fid);
            }); // ⭐ 修改：傳遞 bom_ing_fid 作為識別碼
            bomPsTextarea.addEventListener('focus', function() {
                isTextareaFocused = true;
                console.log('%cBOM備註 Textarea focused:', 'color: blue; font-weight: bold;', this.id, 'isTextareaFocused:', isTextareaFocused, 'Time:', new Date().toLocaleTimeString());
            });
            bomPsTextarea.addEventListener('blur', function() {
                isTextareaFocused = false;
                console.log('%cBOM備註 Textarea blurred:', 'color: orange; font-weight: bold;', this.id, 'isTextareaFocused:', isTextareaFocused, 'Time:', new Date().toLocaleTimeString());
            });

            // ⭐ 新增：將 textarea 加入容器
            bomPsContainer.appendChild(bomPsTextarea);

            // ⭐ 修改：將整個容器加入儲存格
            tdBomPs.appendChild(bomPsContainer);

            // --- START: UNIFIED REMARK GATHERING AND DISPLAY ---

            // 步驟 1 & 2: 建立統一陣列，並收集所有備註
            const combinedRemarks = [];

            // QC 異常備註 (NG)
                if (row.qq_details && Array.isArray(row.qq_details)) {
                    row.qq_details.forEach(detail => {
                        if (detail.QC_ps && String(detail.QC_ps).trim() !== '') {
                            combinedRemarks.push({
                                type: 'qc',
                                bom_sn: parseInt(detail.bom_sn) || 0,
                                text: detail.QC_ps,
                                ProcessName: detail.ProcessName,
                                date: new Date(detail.QC_check_date),
                                formatted_date: detail.qc_date_formatted,
                                qc_check_type: detail.QC_check === 'QQ' ? 'NG' : (detail.QC_check || 'NG'),
                                qty: detail.QC_QQ_sqty || 0,
                                type_order: 2
                            });
                        }
                    });
                }

                // QC 允收備註 (OK)
                if (row.ok_details && Array.isArray(row.ok_details)) {
                    row.ok_details.forEach(detail => {
                        if (detail.QC_ps_ok && String(detail.QC_ps_ok).trim() !== '') {
                            combinedRemarks.push({
                                type: 'qc',
                                bom_sn: parseInt(detail.bom_sn) || 0,
                                text: detail.QC_ps_ok,
                                ProcessName: detail.ProcessName,
                                date: new Date(detail.QC_check_date),
                                formatted_date: detail.qc_date_formatted,
                                qc_check_type: detail.QC_check === 'ok' ? 'OK' : (detail.QC_check || 'OK'),
                                qty: detail.QC_ok_sqty || 0,
                                type_order: 2
                            });
                        }
                    });
                }


            // 收集所有相關製程的 "單關備註" (single_bet_ps)
            // 包含當前製程和其他製程
            const otherProcessesWithRemarks = (window.bomPSList && Array.isArray(window.bomPSList)) ?
                window.bomPSList
                .filter(p =>
                    p.bom === row.bom && // 篩選出相同 BOM 的所有製程
                    p.bom_sn !== row.bom_sn && // 排除目前正在顯示的製程
                    p.single_bet_ps && String(p.single_bet_ps).trim() !== '' // 過濾掉 single_bet_ps 為空或無內容的製程
                )
                .sort((a, b) => (parseInt(b.bom_sn) || 0) - (parseInt(a.bom_sn) || 0)) // 依照 bom_sn 由大到小排序
                : [];

            if (otherProcessesWithRemarks.length > 0) {
                // 新增分隔線
                // const separator = document.createElement('hr');
                // separator.style.borderTop = '1px dashed #ccc';
                // separator.style.marginTop = '8px';
                // separator.style.marginBottom = '8px';
                // tdBomPs.appendChild(separator);

                const otherRemarksContainer = document.createElement('div');
                otherRemarksContainer.style.fontSize = '12px';
                otherRemarksContainer.style.color = '#555';

                otherProcessesWithRemarks.forEach(proc => {
                    const remarkLine = document.createElement('div');
                    remarkLine.style.marginBottom = '4px';
                    // const processLabel = `<strong>${proc.bom_sn} ${proc.ProcessName || ''}:</strong> `;
                    const processLabel = `<strong>${proc.ProcessName || ''}:</strong> `;
                    const remarkContent = (proc.single_bet_ps || '').replace(/\n/g, '<br>'); // 處理換行
                    remarkLine.innerHTML = processLabel + remarkContent;
                    otherRemarksContainer.appendChild(remarkLine);
                });
                tdBomPs.appendChild(otherRemarksContainer);
            }

            // --- QC 檢驗備註顯示 ---
            combinedRemarks.sort((a, b) => b.date - a.date);
            if (combinedRemarks.length > 0) {
                const currentMaxBomSn = Math.max(...String(row.bom_sn||'0').split(',').map(s=>parseInt(s.trim())||0));
                // ── 規則：所有 NG 永遠顯示；OK 只顯示最近兩關（bom_sn >= currentMaxBomSn - 1）
                //         其餘 OK 彙整成「尚有N筆QC OK」可點擊跳窗
                const ngRemarks  = combinedRemarks.filter(r => r.qc_check_type === 'NG');
                const okRemarks  = combinedRemarks.filter(r => r.qc_check_type === 'OK');
                const okSnThreshold = currentMaxBomSn - 1;
                const okRemarksFiltered = okRemarks.filter(r => r.bom_sn >= okSnThreshold);
                const okHiddenList     = okRemarks.filter(r => r.bom_sn < okSnThreshold);
                const visibleRemarks   = [...ngRemarks, ...okRemarksFiltered];
                if (visibleRemarks.length > 0 || okHiddenList.length > 0) {
                    const qcC = document.createElement('div');
                    qcC.style.cssText = 'margin-top:4px; font-size:11px; line-height:1.3; color:#555;';
                    const sep = document.createElement('hr');
                    sep.style.cssText = 'border:none; border-top:1px dashed #ddd; margin:3px 0;';
                    qcC.appendChild(sep);
                    visibleRemarks.forEach(remark => {
                        const rd = document.createElement('div');
                        rd.style.cssText = 'margin-bottom:2px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;';
                        // ── 按鈕樣式徽章（暖色系）──
                        const isNG = remark.qc_check_type === 'NG';
                        const badge = isNG
                            ? `<span style="display:inline-block;background:#c0392b;color:#fff;padding:1px 5px;border-radius:3px;font-size:10px;font-weight:bold;letter-spacing:0.5px;">NG</span>`
                            : `<span style="display:inline-block;background:#27ae60;color:#fff;padding:1px 5px;border-radius:3px;font-size:10px;font-weight:bold;letter-spacing:0.5px;">OK</span>`;
                        rd.innerHTML = `${badge} <span style="color:#999;">${remark.formatted_date}</span> ${escapeHtml(remark.ProcessName||'')} x${remark.qty}：${escapeHtml(remark.text).replace(/\n/g,' ')}`;
                        qcC.appendChild(rd);
                    });
                    if (okHiddenList.length > 0) {
                        const md = document.createElement('div');
                        md.style.cssText = 'color:#337ab7;font-size:10px;margin-top:2px;cursor:pointer;text-decoration:underline;';
                        md.textContent = `▸ 尚有 ${okHiddenList.length} 筆QC OK檢驗資料`;
                        md.onclick = (function(hl, bRef) { return function(e) {
                            e.stopPropagation();
                            const rh = hl.map(r=>`<div style="padding:5px 0;border-bottom:1px solid #f0f0f0;font-size:12px;"><span style="display:inline-block;background:#27ae60;color:#fff;padding:1px 5px;border-radius:3px;font-size:10px;font-weight:bold;">OK</span> <span style="color:#888;">${escapeHtml(r.formatted_date||'')}</span> <strong>${escapeHtml(r.ProcessName||'')}</strong> x${r.qty}：${escapeHtml(r.text||'').replace(/\n/g,'<br>')}</div>`).join('');
                            const ov = document.createElement('div');
                            ov.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.45);z-index:9999;display:flex;align-items:center;justify-content:center;';
                            ov.innerHTML = `<div style="background:#fff;border-radius:6px;box-shadow:0 6px 32px rgba(0,0,0,.22);min-width:320px;max-width:540px;width:92%;max-height:72vh;display:flex;flex-direction:column;"><div style="padding:11px 16px;border-bottom:1px solid #e8e8e8;font-weight:bold;font-size:13px;display:flex;justify-content:space-between;align-items:center;"><span>前期 QC OK 記錄 <span style="color:#888;font-size:11px;font-weight:normal;">(${escapeHtml(bRef)})</span></span><button id="_qcok_close" style="border:none;background:none;font-size:20px;cursor:pointer;color:#aaa;line-height:1;padding:0 4px;">×</button></div><div style="padding:8px 16px;overflow-y:auto;flex:1;">${rh}</div></div>`;
                            document.body.appendChild(ov);
                            ov.querySelector('#_qcok_close').onclick=function(){document.body.removeChild(ov);};
                            ov.onclick=function(ev){if(ev.target===ov)document.body.removeChild(ov);};
                        };})(okHiddenList, String(row.bom||''));
                        qcC.appendChild(md);
                    }
                    tdBomPs.appendChild(qcC);
                }
            }
            // --- END QC 檢驗備註 ---

            // Add a line break if both remarks have content
            var bomIngPsText = row.ps || ""; // Corrected: Use row.ps (from bom_ing.ps)
            if ((bomPsText.trim() !== "" || otherProcessesWithRemarks.length > 0) && bomIngPsText.trim() !== "") {
                const solidSeparator = document.createElement('hr'); // 改用實線分隔
                solidSeparator.style.borderTop = '1px solid #ccc';
                solidSeparator.style.marginTop = '8px';
                solidSeparator.style.marginBottom = '8px';
                tdBomPs.appendChild(solidSeparator);
            }

            // 製程備註 (text part) - append to the same td (tdBomPs)
            if (bomIngPsText.trim() !== "") { // Only add if there's content
                var bomIngPsDiv = document.createElement('div');
                bomIngPsDiv.textContent = bomIngPsText;
                bomIngPsDiv.style.marginTop = '5px'; // Add some top margin for separation
                bomIngPsDiv.style.padding = '2px'; // Consistent padding
                bomIngPsDiv.style.lineHeight = '1.2em'; // Consistent line height
                tdBomPs.appendChild(bomIngPsDiv);
            }
            tr.appendChild(tdBomPs); // Append the combined cell

            // --- 10. 報工狀況與備註 (合併原 10 & 11) ---
            var tdCombined = document.createElement('td');
            tdCombined.setAttribute('name', 'Report_Combined_Status');
            tdCombined.style.fontSize = '14px';
            tdCombined.style.lineHeight = '1.3';
            tdCombined.style.minWidth = '250px';

            // 第一行：[按鈕] [製程] [日期] [良/NG] [總表按鈕]
            let line1 = document.createElement('div');
            line1.style.display = 'flex';
            line1.style.alignItems = 'center';
            line1.style.gap = '4px';
            line1.style.whiteSpace = 'nowrap';

            let buttonsHTML = '';
            if (row.oready_sqty_total || row.ng_sqty_total) {
                buttonsHTML = '<a href="javascript:void(0);" style="margin-right:2px;" data-href="../../views/pm/OreadyReply_ForPm_BaseOfTime2.php?c_pti=' + row.pti +
                    '&c=' + encodeURIComponent(row.Client_Name) +
                    '&or_id=' + row.OreadyReply_id +
                    '&b=' + encodeURIComponent(row.bom) +
                    '&d=' + encodeURIComponent(row.d_id) +
                    '&pn=' + row.process_no +
                    '&mi=' + encodeURIComponent(row.maker_id) +
                    '&s=' + row.sqty +
                    '&pm=' + encodeURIComponent(row.ProcessName) +
                    '" onclick="goToDetail(this)">' +
                    '<input type="button" class="btn btn-warning btn-xs btn-xss update" value="明細">' +
                    '</a>';
            }
            let processLabel = row.latest_report_process ? `<span class="label label-info" style="font-size:13px; padding:2px 6px; vertical-align:middle; margin-right:4px;">${escapeHtml(row.latest_report_process)}</span>` : '';
            let dateDisplayHtml = '';
            if (row.pm_has_report && row.pm_latest_date) {
                const dateObj = new Date(row.pm_latest_date);
                const now = new Date();
                let dateDisplay = (dateObj.getFullYear() === now.getFullYear())
                    ? (dateObj.getMonth() + 1).toString().padStart(2, '0') + '.' + dateObj.getDate().toString().padStart(2, '0')
                    : row.pm_latest_date.substring(0, 10).replace(/-/g, '/');
                const finishedIconHtml = row.pm_is_all_finished ? `<i class="fa fa-check-circle" style="color:#27ae60; margin-left:2px;" title="所有製程已完工"></i>` : '';
                dateDisplayHtml = `<span style="color: #337ab7; font-weight: bold; font-size: 13px;">${dateDisplay}${finishedIconHtml}</span>`;
            } else if (row.pm_schedule_order) {
                dateDisplayHtml = `<span style="color: #999; font-size: 13px;"><i class="fa fa-sort-numeric-asc"></i> 順位${row.pm_schedule_order}</span>`;
            }
            let procQtyTotal = row.pm_has_report ? row.pm_total_processed : ((parseFloat(row.oready_sqty_total) || 0) + (parseFloat(row.ng_sqty_total) || 0));
            let ngQtyTotal = row.pm_has_report ? row.pm_total_ng : (parseFloat(row.ng_sqty_total) || 0);
            let qtyLabel = (procQtyTotal > 0 || ngQtyTotal > 0) ? `<span style="margin-left:auto; font-weight:bold; font-size:14px;">${procQtyTotal} / <span style="${ngQtyTotal > 0 ? 'color:red;' : 'color:#ccc;'}">${ngQtyTotal}</span></span>` : '';
            const hasReportData = (row.has_any_pm_report === true) || (parseFloat(row.oready_sqty_total) > 0) || (parseFloat(row.ng_sqty_total) > 0);
            const reportIconBtn = hasReportData ? `<button type="button" class="btn btn-xs btn-default" style="margin-left:2px; padding:0 3px;" title="查看所有報工紀錄" onclick="openAllReportsModal('${escapeHtml(row.bom)}')"><i class="fa fa-file-text-o"></i></button>` : '';
            line1.innerHTML = buttonsHTML + processLabel + dateDisplayHtml + qtyLabel + reportIconBtn;
            tdCombined.appendChild(line1);

            // 第二行：報工備註
            if (row.latest_report_remark && String(row.latest_report_remark).trim() !== "") {
                let remarkLine = document.createElement('div');
                remarkLine.style.cssText = 'font-size:13px; color:#555; margin-top:2px; border-top:1px dotted #ddd; padding-top:2px;';
                remarkLine.innerHTML = `<i class="fa fa-commenting-o" style="margin-right:3px; color:#aaa;"></i>${escapeHtml(row.latest_report_remark)}`;
                tdCombined.appendChild(remarkLine);
            }

            // 第三行：NG 明細 (數量 + 原因 + 備註)
            if (row.latest_ng_info_str && String(row.latest_ng_info_str).trim() !== "") {
                row.latest_ng_info_str.split('|').forEach(ngInfo => {
                    let p = ngInfo.split(':::');
                    // p[0]=數量, p[1]=原因, p[2]=備註
                    if (p[0] && parseFloat(p[0]) > 0) {
                        let ngLine = document.createElement('div');
                        ngLine.style.cssText = 'font-size:12px; color:#d9534f; margin-top:2px;';
                        let reason = p[1] || '其它原因';
                        let remark = p[2] || '';
                        let displayStr = `<i class="fa fa-warning" style="margin-right:3px;"></i>[NG ${p[0]}] ${escapeHtml(reason)}`;
                        if (remark.trim() !== "") displayStr += ` <span style="color:#666; font-style:italic;"> - ${escapeHtml(remark)}</span>`;
                        ngLine.innerHTML = displayStr;
                        tdCombined.appendChild(ngLine);
                    }
                });
            }
            tr.appendChild(tdCombined);

            // --- 12. pti (hidden) --- (Original column 13, now effectively column 12)
            var tdPti = document.createElement('td');
            tdPti.setAttribute('name', 'pti');
            tdPti.hidden = true;
            tdPti.textContent = row.pti;
            tr.appendChild(tdPti);

            // --- 13. processing_state (hidden) --- (Original column 14, now effectively column 13)
            var tdProcessingState = document.createElement('td');
            tdProcessingState.setAttribute('name', 'processing_state');
            tdProcessingState.hidden = true;
            tdProcessingState.textContent = row.processing_state;
            tr.appendChild(tdProcessingState);

            // --- 14+ 動態製程欄位 ---
            var _fmtP = function(v) { var f=parseFloat(v); return (f%1===0)?'$'+f.toFixed(0):'$'+f.toFixed(2); };
            try {
                if (window.bomPSList && Array.isArray(window.bomPSList) && window.maxCount > 0) {
                    var currentBom = (row.bom ? row.bom.toString().trim() : '');
                    var matchingProcesses = window.bomPSList.filter(function(item) {
                        return item && item.bom && item.bom.toString().trim() === currentBom;
                    });
                    // 修改排序邏輯，使用 bom_sn 進行排序
                    matchingProcesses.sort((a, b) => (parseInt(a.bom_sn) || 0) - (parseInt(b.bom_sn) || 0));

                    // QC/生管製程不同步：算出快速移轉的目標關（QC已檢驗/完工、序號>目前製程的最遠關）
                    var _qcSyncTarget = getQcSyncInfo(row).target;

                    for (var i = 0; i < window.maxCount; i++) {
                        var tdDynamicProcess = document.createElement('td');
                        tdDynamicProcess.className = 'process-col';
                        var processInfo = matchingProcesses[i];
                        if (processInfo) {
                            // --- 修正：處理合併後的 bom_sn ---
                            // row.bom_sn 現在可能是 "3,4,5" 這樣的字串
                            // processInfo.bom_sn 則是單一值，如 "3"
                            // 我們需要檢查 processInfo.bom_sn 是否存在於 row.bom_sn 的字串中
                            const currentBomSnArray = String(row.bom_sn || '').split(',');
                            const isCurrentProcess = currentBomSnArray.includes(String(processInfo.bom_sn));

                            if (isCurrentProcess) {
                                tdDynamicProcess.classList.add('highlight-process');
                            }
                            var _splitBatches = (processInfo.split_batches && processInfo.split_batches.length > 1)
                                ? processInfo.split_batches
                                : (processInfo.all_split_batches && processInfo.all_split_batches.length > 1)
                                ? processInfo.all_split_batches : null;

                            if (_splitBatches) {
                                // ── 拆分批次顯示 ──────────────────────────────
                                var _stMap = {N:'待發包',P:'待移轉',ing:'加工中',Q:'QC待驗',E:'已結',1:'已結',skip:'跳過'};
                                var _sHtml = '<div style="font-size:10.5px;font-weight:600;color:#444;margin-bottom:2px;">'
                                           + escapeHtml(processInfo.ProcessName||'')
                                           + ' <span style="color:#337ab7;font-weight:normal;font-size:9px;background:#e8f0fe;border-radius:8px;padding:1px 5px;">拆'+_splitBatches.length+'批</span>'
                                           + '</div>';
                                _splitBatches.forEach(function(_sb) {
                                    var _lbl = _sb.batch_label || '─';
                                    var _od  = formatDynamicProcessDate(_sb.outsource_date);
                                    var _rd  = formatDynamicProcessDate(_sb.return_date);
                                    var _st  = _stMap[_sb.processing_state] || '';
                                    var _sc  = _sb.processing_state==='ing'?'#1a7a1a'
                                             : _sb.processing_state==='Q'?'#0056b3'
                                             : _sb.processing_state==='P'?'#28a745'
                                             : _sb.processing_state==='skip'?'#e67e22':'#888';
                                    // QC 狀態
                                    var _qcHtml = '';
                                    if (_sb.qc_completed==1||_sb.qc_completed==='1') {
                                        _qcHtml = '<span style="color:#28a745;font-size:9px;"> [QC完工]</span>';
                                    } else if (_sb.QC_check) {
                                        var _qcc = _sb.QC_check==='ok'?'#28a745': _sb.QC_check==='ng'?'#c00':'#e67e00';
                                        var _qcl = {ok:'允收',ng:'驗退',QQ:'異常',AOD:'特採'}[_sb.QC_check]||_sb.QC_check;
                                        _qcHtml = '<span style="color:'+_qcc+';font-size:9px;"> ['+_qcl+']</span>';
                                    }
                                    _sHtml += '<div style="border-left:2px solid #c5d5f5;padding:1px 0 1px 5px;margin:2px 0;font-size:10px;line-height:1.4;">'
                                           +  '<strong style="color:#337ab7;">' + escapeHtml(_lbl) + '</strong>'
                                           +  ' ' + escapeHtml(String(_sb.sqty||'')) + ' pcs'
                                           +  (_sb.maker_id ? '<br><span style="color:#555;">' + escapeHtml(_sb.maker_id) + '</span>' : '')
                                           +  (_od ? '<span style="color:#aaa;"> '+_od+'</span>' : '')
                                           +  (_rd ? '<span style="color:#2a7ae2;"> 回:'+_rd+'</span>' : '')
                                           +  (_st ? ' <span style="color:'+_sc+';font-size:9px;">['+_st+']</span>' : '')
                                           +  _qcHtml
                                           +  '</div>';
                                });
                                tdDynamicProcess.innerHTML = _sHtml;
                            } else {
                                // ── 單批次顯示（原有邏輯）────────────────────
                                let processDisplayText = escapeHtml(processInfo.process_no || '');
                                if (processInfo.ProcessName) {
                                    processDisplayText += ' ' + escapeHtml(processInfo.ProcessName);
                                }
                                tdDynamicProcess.innerHTML = `<div>${processDisplayText}</div>`;

                                var formattedOutsourceDate = formatDynamicProcessDate(processInfo.outsource_date);
                                var makerName = processInfo.maker_id || '';
                                var makerInfoHtml = '';
                                if (formattedOutsourceDate) makerInfoHtml += escapeHtml(formattedOutsourceDate);
                                if (makerName) {
                                    if (formattedOutsourceDate && makerName) makerInfoHtml += ' ';
                                    makerInfoHtml += escapeHtml(makerName);
                                }
                                if (makerInfoHtml) {
                                    tdDynamicProcess.innerHTML += `<small style="color: #888;">${makerInfoHtml}</small>`;
                                }

                                if (isCurrentProcess) {
                                    let datePrefix = '';
                                    var translatedState = translateProcessingState(processInfo.processing_state);
                                    var stateColor = '#555';
                                    if (processInfo.processing_state === 'ing') {
                                        stateColor = 'orange';
                                    } else if (processInfo.processing_state === 'Q') {
                                        if (processInfo.return_date && String(processInfo.return_date).trim() !== '') {
                                            datePrefix = formatDateAsMd(processInfo.return_date) + ' ';
                                        }
                                        stateColor = 'blue';
                                    } else if (processInfo.processing_state === 'P') {
                                        if (processInfo.QC_check_date && String(processInfo.QC_check_date).trim() !== '') {
                                            datePrefix = formatDateAsMd(processInfo.QC_check_date) + ' ';
                                        }
                                        stateColor = '#28a745';
                                    }
                                    tdDynamicProcess.innerHTML += `<div style="font-size: 0.9em; color: ${stateColor};">${datePrefix}${escapeHtml(translatedState)}</div>`;
                                }
                            }

                            // ── 加工單價顯示（動態製程欄，A 或 C+D+R 才可見）──────────────
                            if (window.displayPermissionCode === 'A' || window.displayPermissionCode === 'C+D+R' || window.featSeePrice) {
                                var _tpm = window.transferPriceMap || {};
                                var _thm = window.transferHistoryMap || {};
                                var _bsn = String(processInfo.bom_sn || '');
                                var _pi  = _tpm[row.bom] ? (_tpm[row.bom][_bsn] || null) : null;
                                // d_display 優先，fallback d_id（product_id 可能存任一值）
                                var _didKey = String(row.d_display || row.d_id || '');
                                var _didKey2 = String(row.d_id || '');
                                var _histBySn = [];
                                if (_thm[_didKey] && _thm[_didKey][_bsn]) {
                                    _histBySn = _thm[_didKey][_bsn].filter(function(h){ return h.bom !== row.bom; });
                                } else if (_didKey2 && _didKey2 !== _didKey && _thm[_didKey2] && _thm[_didKey2][_bsn]) {
                                    _histBySn = _thm[_didKey2][_bsn].filter(function(h){ return h.bom !== row.bom; });
                                }

                                var _hasCurrentPrice = _pi && (_pi.modified_unit_price || _pi.price);
                                var _hasHistory = _histBySn.length > 0 && (_histBySn[0].modified_unit_price || _histBySn[0].price);

                                if (_hasCurrentPrice || _hasHistory) {
                                    var _iconEl = document.createElement('div');
                                    _iconEl.className = 'price-history-trigger';
                                    _iconEl.style.cssText = 'display:block;cursor:pointer;margin-top:2px;font-size:12px;line-height:1.4;';

                                    if (_hasCurrentPrice) {
                                        var _rawPrice = _pi.modified_unit_price || _pi.price;
                                        var _priceLabel = _fmtP(_rawPrice);
                                        _iconEl.innerHTML = '<i class="fa fa-list-alt" style="color:#0a6;"></i><span style="color:#0a6;font-size:11px;margin-left:2px;">' + escapeHtml(_priceLabel) + '</span>';
                                    } else {
                                        _iconEl.innerHTML = '<i class="fa fa-list-alt" style="color:#aaa;"></i>';
                                    }

                                    var _rows = [];
                                    if (_hasCurrentPrice) {
                                        var _rawPrice2 = _pi.modified_unit_price || _pi.price;
                                        _rows.push({
                                            date: _pi.transfer_date ? String(_pi.transfer_date).substring(0, 10) : '',
                                            maker: _pi.maker_name || _pi.maker_from || '',
                                            sqty: _pi.sqty ? _pi.sqty + 'pcs' : '',
                                            price: _fmtP(_rawPrice2),
                                            bom: row.bom,
                                            note: _pi.note || '',
                                            isCurrent: true
                                        });
                                    }
                                    _histBySn.forEach(function(h) {
                                        var _hp = h.modified_unit_price || h.price;
                                        if (!_hp) return;
                                        _rows.push({
                                            date: h.transfer_date ? String(h.transfer_date).substring(0, 10) : '',
                                            maker: h.maker_name || h.maker_from || '',
                                            sqty: h.sqty ? h.sqty + 'pcs' : '',
                                            price: _fmtP(_hp),
                                            bom: h.bom || '',
                                            note: h.note || '',
                                            isCurrent: false
                                        });
                                    });

                                    if (_rows.length > 0) {
                                        var _tableHtml = '<table style="border-collapse:collapse;font-size:11px;white-space:nowrap;">' +
                                            '<tr style="border-bottom:1px solid #ccc;color:#555;">' +
                                            '<th style="padding:1px 5px 1px 0;">日期</th>' +
                                            '<th style="padding:1px 5px;">廠商</th>' +
                                            '<th style="padding:1px 5px;">數量</th>' +
                                            '<th style="padding:1px 5px;">單價</th>' +
                                            '<th style="padding:1px 5px;">BOM</th>' +
                                            '<th style="padding:1px 5px;">備註</th>' +
                                            '</tr>';
                                        _rows.forEach(function(r) {
                                            var _rowStyle = r.isCurrent ? 'color:#0a6;font-weight:bold;' : 'color:#333;';
                                            _tableHtml += '<tr style="' + _rowStyle + '">' +
                                                '<td style="padding:1px 5px 1px 0;white-space:nowrap;">' + escapeHtml(r.date) + '</td>' +
                                                '<td style="padding:1px 5px;white-space:nowrap;">' + escapeHtml(r.maker) + '</td>' +
                                                '<td style="padding:1px 5px;white-space:nowrap;text-align:right;">' + escapeHtml(r.sqty) + '</td>' +
                                                '<td style="padding:1px 5px;white-space:nowrap;text-align:right;">' + escapeHtml(r.price) + '</td>' +
                                                '<td style="padding:1px 5px;white-space:nowrap;">' + escapeHtml(r.bom) + '</td>' +
                                                '<td style="padding:1px 5px;white-space:nowrap;max-width:200px;overflow:hidden;text-overflow:ellipsis;">' + escapeHtml(r.note) + '</td>' +
                                                '</tr>';
                                        });
                                        _tableHtml += '</table>';

                                        _iconEl.setAttribute('data-toggle', 'popover');
                                        _iconEl.setAttribute('data-placement', 'right');
                                        _iconEl.setAttribute('data-container', 'body');
                                        _iconEl.setAttribute('data-trigger', 'hover');
                                        _iconEl.setAttribute('data-html', 'true');
                                        _iconEl.setAttribute('title', '加工單價歷史');
                                        _iconEl.setAttribute('data-content', _tableHtml);
                                    }

                                    tdDynamicProcess.appendChild(_iconEl);
                                }
                            }
                            // ── /加工單價顯示 ────────────────────────────────────────────
                        }
                        var _canTransferRole = (!window.isCRU && window.displayPermissionCode !== 'D+R') || window.featTransfer;
                        var _canCancelRole = (window.displayPermissionCode === 'A' || window.displayPermissionCode === 'C+R+U' || window.displayPermissionCode === 'C+D+R+U');
                        var _hasEligibleSplitForCancel = !!(_splitBatches && _splitBatches.filter(function(b){
                            return b.processing_state !== 'E' && b.processing_state !== '1' && b.processing_state !== 'skip' && b.bom_ing_fid;
                        }).length > 1);
                        if (processInfo && processInfo.bom_ing_fid &&
                            (_canTransferRole || (_hasEligibleSplitForCancel && _canCancelRole))) {
                            tdDynamicProcess.style.cursor = 'pointer';
                            tdDynamicProcess.title = '點擊快速移轉此製程';
                            tdDynamicProcess.addEventListener('click', (function(pi, rowRef) {
                                return function(e) {
                                    if (e.target.closest('.price-history-trigger')) return;
                                    e.stopPropagation();
                                    openQuickTransferModal(pi, rowRef);
                                };
                            })(processInfo, row));
                        }
                        // QC/生管製程不同步：此關是QC已檢驗/完工但目前製程還在前面的目標關 → 顯示快速移轉按鈕
                        if (processInfo && _qcSyncTarget && _canTransferRole &&
                            String(_qcSyncTarget.bom_ing_fid) === String(processInfo.bom_ing_fid)) {
                            var _qsBtnRow = document.createElement('div');
                            _qsBtnRow.style.cssText = 'margin-top:3px;display:flex;justify-content:flex-end;';
                            var _qsBtn = document.createElement('button');
                            _qsBtn.type = 'button';
                            _qsBtn.className = 'btn btn-xs';
                            _qsBtn.style.cssText = 'background:#e67e22;color:#fff;font-size:10px;padding:1px 6px;';
                            _qsBtn.innerHTML = '<i class="fa fa-bolt"></i> 快速移轉';
                            _qsBtn.title = 'QC已檢驗到此製程但目前製程仍在前面，點擊快速移轉到此關（自動以今天回廠，QC已完工則直接跳待移轉）';
                            _qsBtn.addEventListener('click', (function(pi, rowRef) {
                                return function(e) {
                                    e.stopPropagation();
                                    _openQuickTransferForm(rowRef, {
                                        fid: String(pi.bom_ing_fid || ''),
                                        process_no: pi.process_no,
                                        ProcessName: pi.ProcessName,
                                        maker_id_no: pi.maker_id_no,
                                        maker_id: pi.maker_id,
                                        batch_label: null
                                    }, {
                                        action: 'quick_sync_transfer',
                                        note: '將以今天作為回廠日期自動回廠；若此製程QC已完工會直接跳到「待移轉」。'
                                    });
                                };
                            })(processInfo, row));
                            _qsBtnRow.appendChild(_qsBtn);
                            tdDynamicProcess.appendChild(_qsBtnRow);
                        }
                        tr.appendChild(tdDynamicProcess);
                    }
                }
            } catch (e) {
                console.error("Error rendering dynamic process columns for BOM:", row.bom, e);
                // Optionally append empty cells or an error message cell for maxCount
                for (var k = 0; k < (window.maxCount || 0); k++) {
                    var tdError = document.createElement('td');
                    tdError.className = 'process-col';
                    tdError.innerHTML = '<small style="color:red;">Error</small>';
                    tr.appendChild(tdError);
                }
            }

            // Generate and append modal HTML for this row
            modalsHtmlBuffer += generateQrModalForRow(row); // Assuming generateQrModalForRow is defined

            tbody.appendChild(tr); // 將完成的行添加到 tbody
        });

        // 背景預載當前頁各行的子資料
        pageData.forEach(function(row) {
            if (!row || !row.bom) return;
            if ((row.shipment_history && row.shipment_history.length > 0) ||
                (row.qq_details && row.qq_details.length > 0) ||
                (row.ok_details && row.ok_details.length > 0)) return;
            if (_rowDetailCache[row.bom] && !_rowDetailCache[row.bom]._loading) {
                var c = _rowDetailCache[row.bom];
                row.shipment_history = c.shipment_history || [];
                row.qq_details       = c.qq_details       || [];
                row.ok_details       = c.ok_details       || [];
                return;
            }
            _loadRowDetails(row.bom, row.d_id);
        });

        // Initialize Bootstrap Popovers after table is updated
        // 1. 一般彈窗 (交期、燈號) - 排除加工單價觸發器
        $('[data-toggle="popover"]:not(.price-history-trigger)').popover({
            html: true, // 允許 data-content 屬性中包含 HTML
            trigger: 'hover', // 當滑鼠懸停時觸發
            container: 'body', // 將 popover 附加到 body，避免被表格樣式裁切
            placement: 'auto', // 自動判斷最佳顯示位置 (top/bottom/left/right)
            delay: { "show": 100, "hide": 100 }, // 稍微延遲顯示與隱藏，避免滑鼠快速移動時閃爍
            template: '<div class="popover delivery-popover" role="tooltip"><div class="arrow"></div><h3 class="popover-title"></h3><div class="popover-content"></div></div>' // 套用自訂的 CSS class
        });
// 2. 加工單價歷史專用彈窗 (不限寬度)
        $('.price-history-trigger').popover({
            html: true,
            trigger: 'hover',
            container: 'body',
            placement: 'auto',
            sanitize: false,
            delay: { "show": 100, "hide": 100 },
            template: '<div class="popover price-history-popover" role="tooltip"><div class="arrow"></div><h3 class="popover-title"></h3><div class="popover-content"></div></div>'
        });
        // Append all modals to the container
        const modalsContainer = document.getElementById('modals-container');
        if (modalsContainer) {
            modalsContainer.innerHTML = modalsHtmlBuffer;
        }
        // console.log("updateTable 完成"); // Add completion log

        // The custom timer logic for 5-second hide is removed.
        // Bootstrap's default 'hover' trigger will handle show on mouseenter and hide on mouseleave.
    }

    // --- QR Code Modal specific JavaScript (adapted from QC_check_list.php) ---
    // Helper function to escape HTML (already defined in the main script as escapeHtml)
    // function he(str) { ... }

    // Function to generate QR Code Modal HTML for a row
    function generateQrModalForRow(row) {
        let itemModalsHtml = '';
        const bomIngFidEsc = escapeHtml(row.bom_ing_fid);
        const bomEsc = escapeHtml(row.bom);
        const dIdEsc = escapeHtml(row.d_id);
        const sqtyEsc = escapeHtml(row.Qty); // Using row.Qty for total quantity

        itemModalsHtml += `
<div id="myModal_qrcode_${bomIngFidEsc}" class="modal fade" role="dialog">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal">&times;</button>
                <h4 class="modal-title">
                    ${bomEsc} / ${dIdEsc}<br>
                    <small style="font-weight:normal;">總數：${sqtyEsc}</small>
                </h4>
            </div>
            <div class="modal-body" data-total-qty="${sqtyEsc}" data-bom="${bomEsc}" data-d-id="${dIdEsc}" style="min-height: 180px;">
                <div class="form-group qr-modal-centered-form-group">
                    <div class="row qr-modal-controls-row" style="margin-bottom: 10px;">
                        <label class="col-xs-2 control-label qr-modal-label">容器：</label>
                        <div class="col-xs-4 qr-modal-input-group">
                            <select class="form-control packaging-type" id="packaging-type-${bomIngFidEsc}">
                                <option>PP箱</option>
                                <option>蝴蝶籠</option>
                                <option>鐵桶</option>
                                <option>棧板</option>
                            </select>
                        </div>
                        <label class="col-xs-2 control-label qr-modal-label">箱數：</label>
                        <div class="col-xs-4 qr-modal-input-group">
                            <input type="number" class="form-control qty-per-unit" id="qty-per-unit-${bomIngFidEsc}" placeholder="數量" min="1">
                        </div>
                    </div>
                </div>                
                <div class="form-group" style="margin-top: 0;">
                    <div class="row">
                        <div class="col-xs-12 calculation-result" style="padding-top: 7px; font-weight: bold;">共 ? PP箱</div>
                    </div>
                </div>
                <div class="form-group qrcode-display-area" style="text-align: center; margin-top: 15px; display: none;">
                    <div id="qrcode_image_container_${bomIngFidEsc}" style="margin-bottom: 10px;"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default pull-left clear-button">清除</button>
                <button type="button" class="btn btn-default" data-dismiss="modal">關閉</button>
                <button type="button" class="btn btn-success direct-print-qrcode-button">列印</button>
            </div>
        </div>
    </div>
</div>`;
        return itemModalsHtml;
    }

    // --- Wrapper functions for inline event handlers ---
    function displayEditFormForRowWrapper(bomId, buttonElement) {
        const rowData = fullDataset.find(item => item.bom === bomId);
        if (rowData) {
            displayEditFormForRow(rowData, buttonElement);
        } else {
            console.error("Row data not found for BOM ID:", bomId, "in displayEditFormForRowWrapper");
        }
    }

    function handleClientDblClickWrapper(clientNameFromRow, bomId) {
        const customerFilterInput = document.getElementById('customer-filter');
        if (customerFilterInput) {
            if (customerFilterInput.value.trim() !== "") {
                customerFilterInput.value = "";
            } else if (clientNameFromRow) {
                customerFilterInput.value = clientNameFromRow;
            }
            processAndRenderData();
        }
    }

    function handleBomDblClickWrapper(bomValue) {
        const bomSearchInput = document.getElementById('bom-filter');
        if (bomSearchInput) {
            if (bomSearchInput.value.trim() !== "") {
                bomSearchInput.value = "";
            } else if (bomValue) {
                bomSearchInput.value = bomValue;
            }
            processAndRenderData();
        }
    }

    function handleDidDblClickWrapper(partNumber) { // partNumber is already textContent
        const bomSearchInput = document.getElementById('bom-filter');
        if (partNumber && bomSearchInput) {
            bomSearchInput.value = partNumber;
            processAndRenderData();
        }
    }

    // Add other dblclick wrappers (handleOutsourceDateDblClickWrapper, handleMakerIdDblClickWrapper) if they were complex.
    // For simple ones, the logic can be directly in the ondblclick or they might already take simple params.

    function updatePaginationControls(totalRecords) {
        var totalPages = Math.max(1, Math.ceil(totalRecords / recordsPerPage));
        var paginationInfo = document.getElementById("pagination-info");
        var pageSelector = document.getElementById("page-selector");
        var startRecord = totalRecords === 0 ? 0 : (currentPage - 1) * recordsPerPage + 1;
        var endRecord = Math.min(currentPage * recordsPerPage, totalRecords);

        if (paginationInfo) {
            paginationInfo.textContent = `顯示 ${totalRecords} 筆中的 ${startRecord} - ${endRecord} 筆，第 ${currentPage}/${totalPages} 頁`;
        }

        // 更新頁碼下拉選單
        if (pageSelector) {
            // 記住當前選中的值（雖然理論上應該是 currentPage，但以防萬一）
            var currentSelectedValue = pageSelector.value;
            // 清空舊選項
            pageSelector.innerHTML = '';
            // 重新產生選項
            for (var i = 1; i <= totalPages; i++) {
                var option = document.createElement("option");
                option.value = i;
                option.textContent = i;
                // *** 核心：如果循環到的頁碼等於當前頁碼，則設為選中 ***
                if (i === currentPage) {
                    option.selected = true;
                }
                pageSelector.appendChild(option);
            }
            // *** 確保下拉選單的值最終設定為當前頁 ***
            // 嘗試恢復之前選中的值（如果它仍然在新的選項中）
            // if (pageSelector.querySelector('option[value="' + currentSelectedValue + '"]')) {
            //      pageSelector.value = currentSelectedValue;
            // } else {
            pageSelector.value = currentPage; // 確保選中的是當前頁
            // }
        }

        // 更新按鈕狀態
        document.getElementById("btn-first").disabled = (currentPage === 1 || totalPages <= 1);
        document.getElementById("btn-prev").disabled = (currentPage === 1 || totalPages <= 1);
        document.getElementById("btn-next").disabled = (currentPage === totalPages || totalPages <= 1);
        document.getElementById("btn-last").disabled = (currentPage === totalPages || totalPages <= 1);

        // 更新最後一頁按鈕的 onclick (因為 totalPages 可能改變) - 我們使用 addEventListener，這裡不需要
        // var btnLast = document.getElementById("btn-last");
        // if (btnLast) {
        //     btnLast.onclick = function() { goToPage(totalPages); return false; };
        // }

        // 更新每頁顯示筆數選擇器 (確保其值與 recordsPerPage 同步)
        var recordsPerPageSelector = document.getElementById("records-per-page");
        if (recordsPerPageSelector) {
            recordsPerPageSelector.value = recordsPerPage;
        }
        // console.log(`Pagination controls updated: currentPage=${currentPage}, totalPages=${totalPages}, totalRecords=${totalRecords}`); // Debug
    }

    // 5. 修改分頁事件處理函數，呼叫 processAndRenderData
    function goToPage(page) {
        var pageSelector = document.getElementById("page-selector");
        var totalPages = pageSelector ? pageSelector.options.length : 1;
        if (totalPages === 0) totalPages = 1;

        page = parseInt(page, 10);
        if (isNaN(page)) page = 1;
        if (page < 1) page = 1;
        if (page > totalPages) page = totalPages;

        if (currentPage !== page) {
            // console.log(`goToPage: 從 ${currentPage} 到 ${page}`); // Debug
            currentPage = page;
            processAndRenderData(); // <--- 呼叫核心函數
        } else {
            // console.log(`goToPage: 頁碼未改變 (${currentPage})`); // Debug
        }
    }

    function changeRecordsPerPage(value) {
        var newRecordsPerPage = parseInt(value, 10);
        if (recordsPerPage !== newRecordsPerPage) {
            // console.log(`changeRecordsPerPage: 從 ${recordsPerPage} 到 ${newRecordsPerPage}`); // Debug
            recordsPerPage = newRecordsPerPage;
            currentPage = 1;
            processAndRenderData(); // <--- 呼叫核心函數
        }
    }

    function changePageSelector(selector) {
        var selectedPage = parseInt(selector.value, 10);
        console.log(`changePageSelector: 選中 ${selectedPage}`); // Debug
        goToPage(selectedPage); // goToPage 內部會呼叫 processAndRenderData
    }

    // 6. 新增核心處理函數 processAndRenderData
    function processAndRenderData() {
        var filters = getFilters(); // 先取得篩選條件
        console.log("執行 processAndRenderData..."); // Debug
        if (!fullDataset || fullDataset.length === 0) {
            console.log("processAndRenderData: fullDataset 為空，清空表格"); // Debug
            updateTable([]);
            updatePaginationControls(0);
            updateDropdowns(); // 可能需要清空下拉選單
            return;
        }

        // *** 新增：檢查特定 BOM 是否存在於篩選前的 fullDataset 中 ***
        const bomExistsBeforeFilter = fullDataset.find(item => item.bom === '0503001');

        // console.log("processAndRenderData - fullDataset 長度:", fullDataset.length, "使用的篩選條件:", filters); // <-- 新增 Log
        const filteredDataset = filterData(fullDataset); // 1. 過濾完整數據


        // If not active, the original server-side sort order is preserved.

        const bomExistsAfterFilter = filteredDataset.find(item => item.bom === '0503001');
        // console.log("processAndRenderData - filteredDataset 長度:", filteredDataset.length); // <-- 新增 Log
        const totalRecords = filteredDataset.length;
        const totalPages = Math.max(1, Math.ceil(totalRecords / recordsPerPage));

        // 確保 currentPage 在有效範圍內 (篩選後可能改變總頁數)
        if (currentPage > totalPages) currentPage = totalPages;
        if (currentPage < 1) currentPage = 1;

        const start = (currentPage - 1) * recordsPerPage;
        const end = start + recordsPerPage;
        const pageData = filteredDataset.slice(start, end); // 2. 切割出當前頁數據
        // *** 新增：檢查特定 BOM 是否存在於當前頁的 pageData 中 ***
        const bomExistsInPageData = pageData.find(item => item.bom === '0503001');

        // console.log(`渲染第 ${currentPage} 頁，資料範圍 ${start} 到 ${end}，共 ${pageData.length} 筆`); // Debug

        updateTable(pageData); // 3. 將當前頁數據渲染到表格
        updatePaginationControls(totalRecords); // 4. 更新分頁控制項顯示

        // *** 新增/修改：決定下拉選單的數據源 ***
        // 如果 ptiSearch 是空字串 (代表"全部製程"或未篩選)，則使用完整數據集 fullDataset
        // 否則，使用已被篩選過的數據集 filteredDataset
        const dropdownDataSource = (ptiSearch === "") ? fullDataset : filteredDataset;
        // console.log(`Dropdown source determined: ${ptiSearch === "" ? 'fullDataset' : 'filteredDataset'} (length: ${dropdownDataSource.length})`); // Debug
        // --- Update Title Display ---
        const customerDisplaySpan = document.getElementById('current-customer-display');
        if (customerDisplaySpan) {
            const currentCustomerFilter = document.getElementById('customer-filter').value;
            customerDisplaySpan.textContent = (isCustomerSwitchingActive && currentCustomerFilter) ?
                ` - 客戶 = ${currentCustomerFilter} (上下切換客戶中)` :
                '';
        }
        updateDropdowns(dropdownDataSource); // 5. 更新篩選下拉選單，傳入決定的數據源
    }

    // ── 子資料按需載入 ────────────────────────────────────────────────────────
    var _loadRowDetailsInFlight = _loadRowDetailsInFlight || 0;
    var _loadRowDetailsQueue    = _loadRowDetailsQueue    || [];

    function _loadRowDetails(bom, d_id) {
        if (_rowDetailCache[bom]) return;
        _rowDetailCache[bom] = { _loading: true };
        var MAX_CONCURRENT = 2;
        if (_loadRowDetailsInFlight >= MAX_CONCURRENT) {
            _loadRowDetailsQueue.push({ bom: bom, d_id: d_id });
            return;
        }
        _loadRowDetailsInFlight++;
        var xhr = new XMLHttpRequest();
        xhr.open('POST', _phpSelf, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onreadystatechange = function() {
            if (xhr.readyState !== 4) return;
            var details = { shipment_history:[], qq_details:[], ok_details:[] };
            if (xhr.status === 200) {
                try {
                    var resp = JSON.parse(xhr.responseText);
                    if (resp.success) {
                        details.shipment_history = resp.shipment_history || [];
                        details.qq_details       = resp.qq_details       || [];
                        details.ok_details       = resp.ok_details       || [];
                    }
                } catch(e) {}
            }
            _rowDetailCache[bom] = details;
            var _needRedraw = false;
            for (var i = 0; i < fullDataset.length; i++) {
                if (fullDataset[i] && fullDataset[i].bom === bom) {
                    fullDataset[i].shipment_history = details.shipment_history;
                    fullDataset[i].qq_details       = details.qq_details;
                    fullDataset[i].ok_details       = details.ok_details;
                    _needRedraw = true;
                }
            }
            if (_needRedraw && typeof processAndRenderData === 'function') {
                processAndRenderData();
            }
            _loadRowDetailsInFlight--;
            if (_loadRowDetailsQueue.length > 0) {
                var next = _loadRowDetailsQueue.shift();
                setTimeout(function() { _loadRowDetails(next.bom, next.d_id); }, 50);
            }
        };
        xhr.send('action=get_row_details&bom=' + encodeURIComponent(bom) + '&d_id=' + encodeURIComponent(d_id || ''));
    }

    // 輔助函式：轉換 HTML 特殊字元
    function escapeHtml(text) {
        if (!text) return "";
        return text.replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }


    // ---------- 全域變數 ----------
    var currentBomFilter = "all"; // BOM 色彩篩選狀態
    var orderComparison = "<"; // 發單數比較運算子（保留原邏輯）
    var ptiSearch = ""; // 製程篩選

    // ---------- 更新上方客戶與廠商下拉選單（Datalist）----------
    function updateDropdowns(dataSource) { // <--- 接收 dataSource 參數
        // console.log("Updating dropdowns. dataSource length:", dataSource.length); // Debug

        // --- 建立 availableCustomers (用於切換) ---
        // *** 核心修改：這裡要用 fullDataset 並套用其他篩選條件 ***
        const customerSetForSwitching = new Set();
        availableCustomers = []; // Clear and repopulate available customers for switching
        const otherFilters = getFilters(); // 獲取所有篩選條件
        delete otherFilters.customer; // 移除客戶篩選條件

        // 遍歷 *完整* 數據集
        fullDataset.forEach(row => {
            // 檢查是否符合 *其他* 篩選條件
            if (checkRowAgainstFilters(row, otherFilters)) { // <--- 需要一個輔助函數 checkRowAgainstFilters
                // 使用「實際顯示」的客戶名稱（client_name_display：有綁定料號時取料號客戶），
                // 與篩選與顯示邏輯一致，避免下拉選單出現「群燁」卻顯示「誠岱」。
                const cust = String(row.client_name_display || row.Client_Name_Full || row.Client_Name || '').trim();
                if (cust) customerSetForSwitching.add(cust);
            }
        });
        availableCustomers = Array.from(customerSetForSwitching).sort(); // 從 Set 轉換為排序後的 Array
        // console.log("Updated availableCustomers (for switching):", availableCustomers); // Debug
        // --- availableCustomers 建立完成 ---

        // --- 建立 availableVendors（同客戶作法：篩除廠商條件，其餘條件保留，含狀態篩選）---
        const vendorSetForSwitching = new Set();
        availableVendors = [];
        const otherFiltersForVendor = getFilters();
        delete otherFiltersForVendor.vendor; // 移除廠商篩選條件，其他（含狀態）保留

        fullDataset.forEach(row => {
            if (checkRowAgainstFilters(row, otherFiltersForVendor)) {
                if (row.maker_id) {
                    let vend = String(row.maker_id).trim();
                    if (vend.includes("回")) vend = vend.split("回")[0].trim();
                    if (vend) vendorSetForSwitching.add(vend);
                }
            }
        });
        availableVendors = Array.from(vendorSetForSwitching).sort();

        // --- 業務統計仍使用 dataSource ---
        const salesCaseCount = new Map(); // NEW: To store salesperson and their case count
        dataSource.forEach(row => { // 這裡仍然使用 dataSource (當前顯示的數據)

            // NEW Sales Logic for filter dropdown and case count
            let effectiveSalesName = null;
            if (row.PrimarySalesName) {
                if (!row.IsPrimaryOnLeave) {
                    // Primary is working, case belongs to them.
                    effectiveSalesName = row.PrimarySalesName;
                } else { // Primary is on leave
                    if (row.DeputySalesName && !row.IsDeputyOnLeave) {
                        // Deputy is working, case belongs to them.
                        effectiveSalesName = row.DeputySalesName;
                    } else {
                        // Primary is on leave, and (deputy doesn't exist OR deputy is also on leave).
                        // Case belongs to the primary.
                        effectiveSalesName = row.PrimarySalesName;
                    }
                }
            }

            if (effectiveSalesName) {
                let sales = String(effectiveSalesName).trim();
                if (sales) {
                    salesCaseCount.set(sales, (salesCaseCount.get(sales) || 0) + 1);
                }
            }
        });

        // 填充客戶 Datalist
        const customerDatalist = document.getElementById("customerList");
        if (customerDatalist) {
            customerDatalist.innerHTML = ""; // 清空
            availableCustomers.forEach(cust => { // Use the sorted array to populate datalist
                const opt = document.createElement("option");
                opt.value = cust;
                customerDatalist.appendChild(opt);
            });


            // console.log("Updated Customer dropdown with", availableCustomers.length, "options + special options"); // Debug
        } else {
            console.error("Customer datalist element not found!");
        }


        // 填充廠商 Datalist (使用 availableVendors，已依狀態篩選)
        const vendorDatalist = document.getElementById("vendorList");
        if (vendorDatalist) {
            vendorDatalist.innerHTML = ""; // 清空現有選項
            availableVendors.forEach(vend => {
                const opt = document.createElement("option");
                opt.value = vend;
                vendorDatalist.appendChild(opt);
            });

            // console.log("Updated Vendor dropdown with", vendorSet.size, "options + special options"); // Debug
        } else {
            console.error("Vendor datalist element not found!");
        }

        // 填充業務 Datalist
        const salesDatalist = document.getElementById("salesList");
        if (salesDatalist) {
            salesDatalist.innerHTML = "";
            // NEW: Create options from the salesCaseCount map
            const sortedSales = Array.from(salesCaseCount.keys()).sort();
            sortedSales.forEach(sales => {
                const count = salesCaseCount.get(sales);
                const opt = document.createElement("option");
                // The value will be "Sales Name (Count)" for display in the input box upon selection
                opt.value = `${sales} (案量：${count})`;
                salesDatalist.appendChild(opt);
            });
        }
    }

    // --- 新增輔助函數：檢查單行數據是否符合篩選條件 ---
    // (這個函數需要複製 filterData 中的部分邏輯，但排除 customer 篩選)
    function checkRowAgainstFilters(row, filters) {
        let show = true;
        // BOM/料號
        if (filters.bom && (!row.bom || row.bom.toLowerCase().indexOf(filters.bom) === -1) && (!row.d_id || row.d_id.toLowerCase().indexOf(filters.bom) === -1)) show = false;
        // 廠商（含拆分批次廠商）
        if (filters.vendor) {
            var _fvL = String(filters.vendor).trim().toLowerCase();
            var _mkOk = (row.maker_id && row.maker_id.toLowerCase().indexOf(_fvL) !== -1) ||
                        (row.maker_id_no_list && String(row.maker_id_no_list).toLowerCase().indexOf(_fvL) !== -1);
            if (!_mkOk) show = false;
        }
        // 製程
        if (filters.pti && !window._matchPti(row, filters.pti)) show = false;
        // 狀態
        if (filters.status && row.processing_state !== filters.status) show = false;

        // 發單數 (包含比較符；Qty 或 sqty 均可)
        if (filters.order) {
            let operator = '=';
            let filterValStr = filters.order;
            if (['>', '<', '='].includes(filters.order[0])) {
                operator = filters.order[0];
                filterValStr = filters.order.slice(1).trim();
            }
            var _rqtyC = parseFloat(row.Qty || row.sqty);
            var _fvC   = parseFloat(filterValStr);
            var _qmC   = false;
            if (!isNaN(_rqtyC) && !isNaN(_fvC)) {
                if (operator === '>' && _rqtyC > _fvC) _qmC = true;
                else if (operator === '<' && _rqtyC < _fvC) _qmC = true;
                else if (operator === '=' && _rqtyC === _fvC) _qmC = true;
            } else if (String(row.Qty || row.sqty || '').toLowerCase().indexOf(filterValStr) !== -1) {
                _qmC = true;
            }
            if (!_qmC) show = false;
        }

        // 日期 (包含比較符)
        if (filters.date) {
            let dateOperator = '=';
            let dateValueStr = filters.date;
            if (['>', '<', '='].includes(filters.date[0])) {
                dateOperator = filters.date[0];
                dateValueStr = filters.date.slice(1).trim();
            }
            const partsDate = dateValueStr.split("/");
            if (dateValueStr && partsDate.length === 2) {
                const currentYear = new Date().getFullYear();
                dateValueStr = `${currentYear}/${dateValueStr}`;
            }
            let filterDate = dateValueStr ? convertDateFormat(dateValueStr) : null; // 假設 convertDateFormat 已定義
            let rowDate = row.Created_At_s ? convertDateFormat(row.Created_At_s) : null; // 假設 convertDateFormat 已定義

            if (filterDate && rowDate && !isNaN(filterDate.getTime()) && !isNaN(rowDate.getTime())) {
                let normFilterTime = normalizeDate(filterDate).getTime(); // 假設 normalizeDate 已定義
                let normRowTime = normalizeDate(rowDate).getTime(); // 假設 normalizeDate 已定義
                if (dateOperator === '>' && !(normRowTime > normFilterTime)) show = false;
                else if (dateOperator === '<' && !(normRowTime < normFilterTime)) show = false;
                else if (dateOperator === '=' && !(normRowTime === normFilterTime)) show = false;
            } else if (filterDate) {
                show = false;
            }
        }

        // 狀態燈號
        if (filters.bomColor && filters.bomColor !== 'all') { // 確保 bomColor 存在且不是 'all'
            const actualColorInfo = determineRowColor(row);
            if (actualColorInfo.color !== filters.bomColor) {
                show = false;
            }
        }

        // 全域搜索（排除廠商代號/客戶ID）
        if (filters.globalSearch) {
            const _gsMatch = Object.entries(row).some(([k, v]) => k !== 'maker_id_no_list' && k !== 'd_customer_id' && String(v).toLowerCase().includes(filters.globalSearch));
            if (!_gsMatch) show = false;
        }

        return show;
    }

    // ps欄位輸入
    // 自動調整 textarea 高度
    function autoResize(textarea) {
        // 第一次 oninput 事件進來時，記錄 textarea 的基礎高度
        if (!textarea.dataset.baseHeight) {
            textarea.dataset.baseHeight = textarea.clientHeight;
        }
        var baseHeight = parseInt(textarea.dataset.baseHeight, 10);

        // 若內容空白，恢復到基本高度，並隱藏垂直滾動條
        if (textarea.value.trim() === "") {
            textarea.style.height = baseHeight + "px";
            textarea.style.overflowY = "hidden";
            return;
        }

        // 先暫時設定高度為 auto，以取得正確 scrollHeight
        textarea.style.height = "auto";
        var newHeight = textarea.scrollHeight;
        var maxHeight = baseHeight * 3; // 最大高度限制

        if (newHeight > maxHeight) {
            textarea.style.height = maxHeight + "px";
            textarea.style.overflowY = "scroll";
        } else {
            textarea.style.height = newHeight + "px";
            textarea.style.overflowY = "hidden";
        }
    }

    // Modified handleKeyDown for bom.bom_ps
    function handleBomPsKeyDown(event, textarea, bomIdentifier) { // bomIdentifier is row.bom
        var key = event.key || event.keyCode;

        // Shift+Enter: 允許換行，並延遲執行增加 rows 屬性
        if ((key === "Enter" || key === 13) && event.shiftKey) {
            setTimeout(function() {
                var currentRows = parseInt(textarea.getAttribute("rows"), 10) || 1;
                textarea.setAttribute("rows", currentRows + 1);
                autoResize(textarea);
            }, 0);
        }
        // 單獨 Enter (無 Shift) 時，阻止換行，並根據內容判斷是否需要更新
        else if ((key === "Enter" || key === 13) && !event.shiftKey) {
            event.preventDefault(); // 阻止預設換行
            var currentVal = textarea.value;
            var origVal = (textarea.getAttribute("data-orig") || "");
            if (currentVal !== origVal) { // 不論是不是空，只要更動過就更新
                updateBomPs(textarea, bomIdentifier); // Call the new update function
            } else {
                // console.log("BOM備註內容和原始內容相同，無需更新。");
            }
        }
    }

    // New AJAX update function for bom.bom_ps
    function updateBomPs(textarea, bomIdentifier) {
        var newBomPsValue = textarea.value;
        // console.log("開始更新 BOM備註, bom:", bomIdentifier, "new_bom_ps:", newBomPsValue);
        textarea.style.borderColor = 'orange';
        textarea.disabled = true;
        var xhr = new XMLHttpRequest();
        xhr.open("POST", "_update_single_bet_ps.php", true); // Corrected backend script to handle bom.bom_ps update
        xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
        xhr.onreadystatechange = function() {
            if (xhr.readyState === 4) {
                textarea.style.borderColor = '';
                textarea.disabled = false;
            }
            if (xhr.readyState === 4 && xhr.status === 200) {
                try {
                    var serverResponse = JSON.parse(xhr.responseText); // Expect JSON
                    // console.log("BOM備註後端回傳:", serverResponse);
                    if (serverResponse && serverResponse.success) { // ⭐ 修改：增加 serverResponse 存在性檢查
                        // console.log("BOM備註更新成功 (Backend):", serverResponse.message);
                        textarea.setAttribute('data-orig', newBomPsValue);
                        textarea.style.backgroundColor = '#dff0d8';
                        setTimeout(function() {
                            textarea.style.backgroundColor = '';
                        }, 1000);

                        // --- CRITICAL: Update fullDataset ---
                        const itemInFullDataset = fullDataset.find(item => item.bom_ing_fid == bomIdentifier); // ⭐ 修改：使用 bom_ing_fid 查找
                        if (itemInFullDataset) {
                            itemInFullDataset.bom_bom_ps = newBomPsValue; // Ensure this field name matches _fetch_data2.php
                            // console.log("Updated fullDataset for bom_ing_fid:", bomIdentifier, "with new single_bet_ps:", newBomPsValue);
                        } else {
                            console.warn("Could not find BOM in fullDataset to update bom_ps:", bomIdentifier);
                            // Optionally, trigger a full refresh if this happens, as data might be inconsistent
                            // fetchDataAndFilter(); 
                        }
                        // --- End of CRITICAL update ---

                    } else {
                        console.error("後端回傳更新失敗訊息:", serverResponse.message);
                        alert("製程備註更新失敗！\n" + (serverResponse.message || '未知錯誤')); // ⭐ 修改：更新提示訊息
                    }
                } catch (e) {
                    console.error("Error parsing JSON response from _update_single_bet_ps.php or other JS error:", e);
                    console.warn("Raw response from _update_single_bet_ps.php:", xhr.responseText);
                    alert("製程備註更新回應處理失敗。原始回應: " + xhr.responseText); // ⭐ 修改：更新提示訊息
                }
            } else if (xhr.readyState === 4) {
                console.error("AJAX 請求失敗，狀態碼:", xhr.status);
                alert("製程備註與伺服器通訊失敗，請稍後再試。"); // ⭐ 修改：更新提示訊息

            }
        };
        // ⭐ 修改：傳送正確的參數名稱 (bom_ing_fid, single_bet_ps)
        xhr.send("bom_ing_fid=" + encodeURIComponent(bomIdentifier) + "&single_bet_ps=" + encodeURIComponent(newBomPsValue));

    }

    // Helper to determine the effective color of a row (Green, Yellow, Red)
    // This logic must match generateBomHtml's visual logic
    function determineRowColor(row) {
        // 1. Calculate Auto Status first (Progress & Deadline)

        // --- Calculate Progress Percentages ---
        let currPct = 0;
        let normPct = 0;
        let isProgressCalculable = false;

        const elapsedTotal = row.elapsed_workdays_total_to_today;
        const daysPerProcess = parseFloat(window.settingProcessDays) || 0;

        if (elapsedTotal !== null && daysPerProcess > 0 && window.bomPSList) {
            const processes = window.bomPSList.filter(p => p.bom === row.bom);
            const processCount = processes.length;

            if (processCount > 0) {
                isProgressCalculable = true;
                let currentSn = 0;
                if (row.bom_sn) {
                    const snParts = String(row.bom_sn).split(',');
                    currentSn = snParts.reduce((max, val) => Math.max(max, parseInt(val) || 0), 0);
                }
                const sortedProcesses = [...processes].sort((a, b) => (parseInt(a.bom_sn) || 0) - (parseInt(b.bom_sn) || 0));
                const foundIndex = sortedProcesses.findIndex(p => parseInt(p.bom_sn) === currentSn);
                const currentProcessIndex = (foundIndex !== -1) ? foundIndex + 1 : 0;
                currPct = (currentProcessIndex / processCount) * 100;

                const processBasedTotalDays = processCount * daysPerProcess;
                const actualTotalWorkdays = row.total_workdays_outsource_to_selected_delivery;

                // 修正：若總加工日大於製程估算日，則以總加工日為分母
                let effectiveTotalDays = processBasedTotalDays;
                if (typeof actualTotalWorkdays === 'number' && actualTotalWorkdays > processBasedTotalDays) {
                    effectiveTotalDays = actualTotalWorkdays;
                }

                if (effectiveTotalDays > 0) {
                    normPct = (elapsedTotal / effectiveTotalDays) * 100;
                    if (normPct > 100) normPct = 100;
                    if (normPct < 0) normPct = 0;
                }
            }
        }

        // --- Determine Auto Red Condition ---
        let isAutoRed = false;
        let autoRedTitle = '';

        // 1. Deadline Logic (Overdue)
        const remainingWorkdays = row.remaining_workdays_today_delivery;
        if (remainingWorkdays !== null && typeof remainingWorkdays === 'number' && remainingWorkdays <= 0) {
            isAutoRed = true;
            autoRedTitle = `已過交期 ${Math.abs(remainingWorkdays)} 工作天`;
        }

        // 2. Deadline Logic (Urgent)
        if (!isAutoRed) {
            const totalWorkdays = row.total_workdays_outsource_to_selected_delivery;
            const redDaysBeforeSetting = parseInt(window.settingRedDaysBefore, 10);

            if (!isNaN(redDaysBeforeSetting) && [5, 10].includes(redDaysBeforeSetting) && totalWorkdays !== null && totalWorkdays > 0) {
                // 修正：僅在符合「自動調整」條件（總天數 <= 設定天數）時才觸發紅燈
                if (totalWorkdays <= redDaysBeforeSetting) {
                    let threshold = Math.ceil(totalWorkdays / 2);
                    if (remainingWorkdays <= threshold) {
                        isAutoRed = true;
                        autoRedTitle = `交期緊迫 (剩餘 ${remainingWorkdays} 天 <= 門檻 ${threshold} 天)`;
                    }
                }
            }
        }

        // 3. Progress Logic (Red)
        const redPct = parseFloat(window.settingRedDays) || 0;
        if (!isAutoRed && isProgressCalculable) {
            const redThreshold = normPct * (redPct / 100);
            if (redPct > 0 && currPct < redThreshold && normPct > 10) {
                isAutoRed = true;
                autoRedTitle = `進度落後 (紅燈)\n應達進度: ${normPct.toFixed(0)}%\n實際進度: ${currPct.toFixed(0)}%\n(低於 ${redPct}% 的應達進度)`;
            }
        }

        // --- Apply Priority Logic ---
        const priority = row.priority_type; // 'U', 'E', or null/empty

        if (priority === 'E') {
            return { color: 'red', title: '目前：特急件 (E)' };
        } else if (priority === 'U') {
            if (isAutoRed) {
                return { color: 'red', title: `目前：急件 (U) - 但觸發紅燈條件:\n${autoRedTitle}` };
            }
            return { color: 'yellow', title: '目前：急件 (U)' };
        }

        // --- No Priority: Return Auto Result ---
        if (isAutoRed) {
            return { color: 'red', title: autoRedTitle };
        }

        // Check Auto Yellow (Progress)
        const yellowPct = parseFloat(window.settingYellowDays) || 0;
        if (isProgressCalculable) {
            const yellowThreshold = normPct * (yellowPct / 100);
            if (yellowPct > 0 && currPct < yellowThreshold) {
                return { 
                    color: 'yellow', 
                    title: `進度落後 (黃燈)\n應達進度: ${normPct.toFixed(0)}%\n實際進度: ${currPct.toFixed(0)}%\n(低於 ${yellowPct}% 的應達進度)` 
                };
            }
        }

        return { color: 'green', title: '目前：一般' };
    }

    // ══════════════════════════════════════════════════════════════
    // 方案一：緩衝比燈號計算
    // ══════════════════════════════════════════════════════════════
    function determineBufferColor(row) {
        var bd = row._bufferData;
        if (!bd || !bd.success) return { color: 'unknown', title: '緩衝比：資料載入中', buffer_pct: null };
        var deliveryDate = null;
        if (row.Delivery_date) deliveryDate = new Date(row.Delivery_date.replace(/\//g,'-'));
        var today = new Date(); today.setHours(0,0,0,0);
        if (!deliveryDate || isNaN(deliveryDate.getTime())) return { color: 'unknown', title: '緩衝比：無交期資料', buffer_pct: null };
        var daysLeft = countWorkdays(today, deliveryDate, window.globalWorkdaysList || []);
        var remainPess = bd.total_remain_pessimistic || 0;
        var remainNorm = bd.total_remain_normal || 0;
        var remainOpt  = bd.total_remain_optimistic || 0;
        if (daysLeft <= 0) return { color:'red', title:'【緩衝比】已過交期', buffer_pct:0, days_left:daysLeft, remain_pessimistic:remainPess, remain_normal:remainNorm, remain_optimistic:remainOpt };
        var bufferPct = Math.max(0, Math.min(100, Math.round((daysLeft - remainPess) / daysLeft * 100)));
        var color = bufferPct < 20 ? 'red' : bufferPct < 40 ? 'yellow' : 'green';
        var label = bufferPct < 20 ? '危險' : bufferPct < 40 ? '風險' : '充裕';
        var fallbackNote = bd.fallback_used ? ' ⚠資料不足，部分使用預設值' : '';
        var title = '【緩衝比 ' + bufferPct + '%】' + label + fallbackNote +
            '\n距交期：' + daysLeft + ' 工作天' +
            '\n剩餘估計（悲觀）：' + remainPess + ' 天' +
            '\n剩餘估計（一般）：' + remainNorm + ' 天' +
            '\n剩餘估計（樂觀）：' + remainOpt + ' 天';
        return { color:color, title:title, buffer_pct:bufferPct, days_left:daysLeft, remain_pessimistic:remainPess, remain_normal:remainNorm, remain_optimistic:remainOpt };
    }

    // 分批載入可見列緩衝比資料
    function loadBufferDataForVisible() {
        if (!window.bufferModeEnabled) return;
        var rows = Array.from(document.querySelectorAll('tr[data-bom]'));
        var toLoad = rows.filter(function(tr) {
            var bom = tr.getAttribute('data-bom');
            return bom && !window._bufferCache[bom];
        }).slice(0, 10).map(function(tr){ return tr.getAttribute('data-bom'); });
        if (!toLoad.length) return;
        toLoad.forEach(function(bom) {
            window._bufferCache[bom] = { _loading: true };
            $.ajax({
                url: '', type: 'POST',
                data: { action: 'get_bom_buffer_worktime', bom: bom },
                dataType: 'json',
                success: function(res) {
                    window._bufferCache[bom] = res;
                    refreshBomRowBufferDisplay(bom, res);
                },
                error: function() { window._bufferCache[bom] = { success: false }; }
            });
        });
    }

    function refreshBomRowBufferDisplay(bom, bufferData) {
        var row = null;
        if (window.fullDataset) row = window.fullDataset.find(function(r){ return r.bom === bom; });
        if (!row) return;
        row._bufferData = bufferData;
        // circle-buffer 元素已移除，此段保留但無作用
        var threeValDiv = document.querySelector('.three-val-days[data-bom="' + bom + '"]');
        if (threeValDiv && bufferData.success) {
            var bc2 = determineBufferColor(row);
            var anomalyHtml = (bc2.color === 'red') ? '<span style="color:red;font-weight:bold;" title="悲觀剩餘超過交期">⚠急</span> ' : '';
            threeValDiv.innerHTML = anomalyHtml +
                '<span style="color:#3a3;font-size:10px;" title="樂觀">' + bufferData.total_remain_optimistic + '</span>' +
                '<span style="color:#888;font-size:10px;">~</span>' +
                '<span style="color:#555;font-size:10px;" title="一般">' + bufferData.total_remain_normal + '</span>' +
                '<span style="color:#888;font-size:10px;">~</span>' +
                '<span style="color:#c33;font-size:10px;" title="悲觀">' + bufferData.total_remain_pessimistic + '</span>' +
                '<span style="color:#aaa;font-size:9px;">天</span>';
        }
        // 更新急單歷史標記
        refreshUrgentBadge(bom, window._urgentCache[bom] || {});
        // 更新急迫評分條
        refreshUrgencyBar(bom);
    }

    // ══════════════════════════════════════════════════════════════
    // 方案二：衝擊評分 popover
    // ══════════════════════════════════════════════════════════════
    function openImpactScorePopover(bom, dSettingId, processCount, anchorEl) {
        var existing = document.getElementById('impact-score-popover');
        if (existing) existing.remove();
        var pop = document.createElement('div');
        pop.id = 'impact-score-popover';
        pop.style.cssText = 'position:fixed;z-index:9999;background:#fff;border:1px solid #e0e0e0;border-radius:5px;padding:8px 12px;box-shadow:0 3px 12px rgba(0,0,0,0.12);min-width:180px;max-width:260px;font-size:10px;';
        pop.innerHTML = '<div style="font-weight:500;font-size:11px;margin-bottom:6px;border-bottom:1px solid #f0f0f0;padding-bottom:4px;display:flex;justify-content:space-between;align-items:center;">' +
            '<span>📊 衝擊評分 <span style="color:#aaa;font-weight:normal;">' + bom + '</span></span>' +
            '<button onclick="document.getElementById(&quot;impact-score-popover&quot;).remove()" style="border:none;background:none;cursor:pointer;font-size:12px;color:#999;padding:0;">✕</button></div>' +
            '<div id="impact-score-body" style="font-size:10px;">載入中...</div>';
        document.body.appendChild(pop);
        var rect = anchorEl.getBoundingClientRect();
        var top = rect.bottom + 4; var left = Math.min(rect.left, window.innerWidth - 360);
        if (top + 200 > window.innerHeight) top = rect.top - 210;
        pop.style.top = top + 'px'; pop.style.left = left + 'px';
        setTimeout(function() {
            document.addEventListener('click', function closePop(e) {
                if (!pop.contains(e.target) && e.target !== anchorEl) { pop.remove(); document.removeEventListener('click', closePop); }
            });
        }, 100);
        $.ajax({
            url: '', type: 'POST',
            data: { action: 'get_bom_impact_score', bom: bom, d_setting_id: dSettingId, process_count: processCount },
            dataType: 'json',
            success: function(res) {
                var body = document.getElementById('impact-score-body');
                if (!body) return;
                if (!res.success) { body.innerHTML = '<span style="color:red;font-size:10px;">載入失敗</span>'; return; }
                var lc = res.score_level === 'high' ? '#c0392b' : res.score_level === 'medium' ? '#e67e22' : '#27ae60';
                var lt = res.score_level === 'high' ? '高風險' : res.score_level === 'medium' ? '中風險' : '低風險';
                var histText = res.hist_days_median !== null ? res.hist_days_median + '天 (n=' + res.hist_sample + ')' : '無歷史';
                // 排隊明細
                var queueRows = '';
                if (res.queue_detail && res.queue_detail.length > 0) {
                    res.queue_detail.slice(0,5).forEach(function(q) {
                        var label = q.type === 'machine' ? '機台#' + q.machine_id : '製程類型#' + q.process_type_id;
                        queueRows += '<tr><td style="color:#666;padding:2px 4px 2px 0;">' + label + '</td><td style="text-align:right;font-weight:bold;color:' + (q.queue_count>=5?'#c0392b':q.queue_count>=3?'#e67e22':'#333') + ';">' + q.queue_count + ' 張</td></tr>';
                    });
                } else {
                    queueRows = '<tr><td colspan="2" style="color:#aaa;padding:2px 0;">無排隊</td></tr>';
                }
                body.innerHTML =
                    '<div style="display:flex;align-items:center;gap:6px;margin-bottom:8px;">' +
                    '<div style="width:4px;height:18px;background:' + lc + ';border-radius:2px;"></div>' +
                    '<span style="font-size:10px;font-weight:bold;color:' + lc + ';">' + lt + '</span>' +
                    '</div>' +
                    '<table style="width:100%;border-collapse:collapse;font-size:10px;">' +
                    '<tr><td style="color:#666;padding:2px 4px 2px 0;">外包占比</td><td style="text-align:right;font-weight:bold;color:' + (res.outsource_pct>=60?'#c0392b':res.outsource_pct>=40?'#e67e22':'#333') + ';">' + res.outsource_pct + '%</td></tr>' +
                    queueRows +
                    '<tr><td style="color:#666;padding:2px 4px 2px 0;">歷史完工</td><td style="text-align:right;">' + histText + '</td></tr>' +
                    '</table>';
            },
            error: function() { var b = document.getElementById('impact-score-body'); if(b) b.innerHTML = '<span style="color:red;">連線失敗</span>'; }
        });
    }

    // ══════════════════════════════════════════════════════════════
    // 方案四：外包回廠預測 popover
    // ══════════════════════════════════════════════════════════════
    function openOutsourcePredictPanel(bom, priority_type, anchorEl) {
        var existing = document.getElementById('outsource-predict-panel');
        if (existing) { existing.remove(); return; }
        var panel = document.createElement('div');
        panel.id = 'outsource-predict-panel';
        panel.style.cssText = 'position:fixed;z-index:9998;background:#fff;border:1px solid #ccc;border-radius:6px;padding:14px 18px;box-shadow:0 4px 20px rgba(0,0,0,0.18);min-width:320px;max-width:480px;font-size:12px;max-height:70vh;overflow-y:auto;';
        panel.innerHTML = '<div style="font-weight:bold;font-size:13px;margin-bottom:10px;border-bottom:1px solid #eee;padding-bottom:6px;">🚚 外包回廠預測 <small style="color:#888;font-weight:normal;">' + bom + '</small>' +
            '<button onclick="document.getElementById(&quot;outsource-predict-panel&quot;).remove()" style="float:right;border:none;background:none;cursor:pointer;font-size:16px;line-height:1;">✕</button></div>' +
            '<div id="outsource-predict-body">載入中...</div>';
        document.body.appendChild(panel);
        var rect = anchorEl.getBoundingClientRect();
        var top = rect.bottom + 4; var left = Math.max(4, Math.min(rect.left, window.innerWidth - 500));
        if (top + 300 > window.innerHeight) top = rect.top - 320;
        panel.style.top = top + 'px'; panel.style.left = left + 'px';
        var urgency = priority_type === 'E' ? 5 : priority_type === 'U' ? 3 : 1;
        $.ajax({
            url: '', type: 'POST',
            data: { action: 'get_outsource_predict', bom: bom },
            dataType: 'json',
            success: function(res) {
                var body = document.getElementById('outsource-predict-body');
                if (!body) return;
                if (!res.success) { body.innerHTML = '<span style="color:red;">' + (res.message||'載入失敗') + '</span>'; return; }
                if (!res.data || res.data.length === 0) { body.innerHTML = '<span style="color:#888;">此BOM無外包製程</span>'; return; }
                
                function getScoreForSort(item) {
                    if (item.is_returned || !item.hist.p80_days || !item.outsource_date) return 0;
                    var sDate = convertDateFormat(item.outsource_date);
                    var elap = countWorkdays(sDate, normalizeDate(new Date()), window.globalWorkdaysList || []);
                    return (elap / item.hist.p80_days) * urgency;
                }

                res.data.sort(function(a,b) {
                    var pa = getScoreForSort(a);
                    var pb = getScoreForSort(b);
                    return pb - pa;
                });

                var html = '<table style="width:100%;border-collapse:collapse;"><thead><tr style="background:#f5f5f5;">' +
                    '<th style="text-align:left;padding:4px 6px;border-bottom:1px solid #ddd;">製程/廠商</th>' +
                    '<th style="text-align:center;padding:4px 6px;border-bottom:1px solid #ddd;">發包日</th>' +
                    '<th style="text-align:center;padding:4px 6px;border-bottom:1px solid #ddd;">歷史回廠天</th>' +
                    '<th style="text-align:center;padding:4px 6px;border-bottom:1px solid #ddd;">催單優先分</th>' +
                    '</tr></thead><tbody>';
                res.data.forEach(function(p) {
                    var histText, histColor = '#333';
                    if (p.is_returned) { histText = '✅ 已回廠 ' + (p.return_date||''); histColor = '#888'; }
                    else if (p.hist.sample_n === 0) { histText = '無歷史資料'; histColor = '#aaa'; }
                    else {
                        histText = p.hist.avg_days + '天 (P80:' + p.hist.p80_days + ')';
                        if (p.hist.sample_n < 3) histText += ' ⚠樣本少';
                    }
                    var elapsed = 0;
                    if (p.outsource_date && !p.is_returned) {
                        var start = convertDateFormat(p.outsource_date);
                        var today = normalizeDate(new Date());
                        elapsed = countWorkdays(start, today, window.globalWorkdaysList || []);
                    }
                    var ps = p.is_returned ? '-' : (p.hist.p80_days && p.outsource_date ? ((elapsed / p.hist.p80_days) * urgency).toFixed(1) : '0');
                    var scoreVal = parseFloat(ps) || 0;
                    var sc = '#333';
                    if (!p.is_returned && p.outsource_date && p.hist.p80_days) {
                        if (scoreVal >= urgency * 1.2) sc = '#c00';
                        else if (scoreVal >= urgency * 0.8) sc = '#e67e00';
                    }
                    html += '<tr style="border-bottom:1px solid #f0f0f0;">' +
                        '<td style="padding:5px 6px;"><div style="font-weight:500;">' + escapeHtml(p.ProcessName) + '</div><div style="color:#666;font-size:11px;">' + escapeHtml(p.maker_id||'(未設廠商)') + '</div></td>' +
                        '<td style="text-align:center;padding:5px 6px;color:#555;">' + (p.outsource_date||'-') + '</td>' +
                        '<td style="text-align:center;padding:5px 6px;color:' + histColor + ';">' + histText + '</td>' +
                        '<td style="text-align:center;padding:5px 6px;font-weight:bold;color:' + sc + ';">' + ps + '</td></tr>';
                });
                html += '</tbody></table><div style="margin-top:8px;font-size:10px;color:#aaa;">催單優先分 = (已發包工作天 / P80回廠天) × 急迫係數（一般=1/急件=3/特急=5）</div>';
                body.innerHTML = html;
            },
            error: function() { var b = document.getElementById('outsource-predict-body'); if(b) b.innerHTML = '<span style="color:red;">連線失敗</span>'; }
        });
        setTimeout(function() {
            document.addEventListener('click', function closePanel(e) {
                if (!panel.contains(e.target) && e.target !== anchorEl) { panel.remove(); document.removeEventListener('click', closePanel); }
            });
        }, 100);
    }

    // ══════════════════════════════════════════════════════════════
    // 急單歷史標記（生管頁）
    // ══════════════════════════════════════════════════════════════
    function loadUrgentLevelForVisible() {
        var rows = Array.from(document.querySelectorAll('tr[data-bom]'));
        var toLoad = rows.filter(function(tr) {
            var bom = tr.getAttribute('data-bom');
            return bom && !window._urgentCache[bom];
        }).slice(0, 10).map(function(tr){ return tr.getAttribute('data-bom'); });
        if (!toLoad.length) return;
        toLoad.forEach(function(bom) {
            window._urgentCache[bom] = { _loading: true };
            $.ajax({
                url: '', type: 'POST',
                data: { action: 'get_order_urgent_level', bom: bom },
                dataType: 'json',
                success: function(res) { window._urgentCache[bom] = res; refreshUrgentBadge(bom, res); },
                error: function() { window._urgentCache[bom] = { success: false }; }
            });
        });
    }

    function refreshUrgentBadge(bom, res) {
        var badge = document.querySelector('.urgent-hist-badge[data-bom="' + bom + '"]');
        if (!badge || !res || !res.success) return;
        var level = res.level || 'unknown', avg = res.avg_days, dtd = res.days_to_delivery, n = res.sample_n;
        var tip = avg !== null ? '歷史均值 ' + avg + ' 天（n=' + n + '），距交期 ' + (dtd!==null?dtd:'?') + ' 天' : '無歷史資料';
        var html = '';
        if (level === 'overdue')       html = '<span style="color:#c00;font-size:10px;font-weight:bold;" title="' + tip + '">⚠已過期</span>';
        else if (level === 'urgent_high')   html = '<span style="color:#c00;font-size:10px;font-weight:bold;" title="' + tip + '">⚠急(' + avg + '天)</span>';
        else if (level === 'urgent_medium') html = '<span style="color:#e67e00;font-size:10px;" title="' + tip + '">⚡注意(' + avg + '天)</span>';
        else if (level === 'unknown')  html = '<span style="color:#bbb;font-size:10px;" title="' + tip + '">無歷史</span>';
        badge.innerHTML = html;
        // 同步更新急迫評分條（加入急單等級資料後重算）
        refreshUrgencyBar(bom);
    }

    // ══════════════════════════════════════════════════════════════
    // 急迫評分條
    // ══════════════════════════════════════════════════════════════
    if (!window._impactCache) window._impactCache = {};

    function buildUrgencyBarHtml(bom, impactData, bufferData, urgentData) {
        if (!impactData || !impactData.success) return '';
        var op = impactData.outsource_pct || 0;
        var mq = impactData.max_queue || 0;
        var rs = impactData.risk_score || 0;
        // 總分：外包30 + 排隊40 + 風險評分30
        var opScore = op >= 60 ? 30 : op >= 40 ? 20 : op >= 20 ? 10 : 0;
        var mqScore = mq >= 5 ? 40 : mq >= 3 ? 27 : mq >= 1 ? 14 : 0;
        var rsScore = Math.round((rs / 6) * 30);
        var totalScore = Math.min(100, opScore + mqScore + rsScore);
        var barColor = totalScore >= 65 ? '#c0392b' : totalScore >= 35 ? '#e67e22' : '#27ae60';
        var levelText = totalScore >= 65 ? '高' : totalScore >= 35 ? '中' : '低';

        // Tooltip 內容
        var tipLines = [];
        tipLines.push('【外包占比】' + op + '% (未回廠)');
        if (impactData.queue_detail && impactData.queue_detail.length > 0) {
            impactData.queue_detail.slice(0,4).forEach(function(q){
                var label = q.type === 'machine' ? '機台#' + q.machine_id : '製程類型#' + q.process_type_id;
                tipLines.push('【排隊】' + label + '：' + q.queue_count + ' 張 BOM');
            });
        } else {
            tipLines.push('【排隊】無排隊');
        }
        if (impactData.hist_days_median) {
            tipLines.push('【歷史完工中位】' + impactData.hist_days_median + ' 天 (n=' + (impactData.hist_sample||0) + ')');
        }
        // 加入緩衝比
        if (bufferData && bufferData.success) {
            tipLines.push('【剩餘估計】樂' + bufferData.total_remain_optimistic + '／一般' + bufferData.total_remain_normal + '／悲' + bufferData.total_remain_pessimistic + ' 天');
        }
        // 加入歷史急單等級
        if (urgentData && urgentData.success && urgentData.level) {
            var lvlMap = {overdue:'已過期', urgent_high:'急單', urgent_medium:'注意', normal:'正常', unknown:'無歷史'};
            var avgStr = urgentData.avg_days ? '(歷史均值' + urgentData.avg_days + '天)' : '';
            tipLines.push('【歷史急單判斷】' + (lvlMap[urgentData.level]||urgentData.level) + ' ' + avgStr);
        }
        var tip = tipLines.join('\n');
        var bomEsc = escapeHtml(bom);

        // 左色條設計：無外框，色條+文字+短進度條
        return '<div class="urgency-bar-inner" style="display:flex;align-items:center;gap:4px;margin-top:2px;cursor:pointer;" ' +
            'title="' + escapeHtml(tip) + '" ' +
            'onclick="event.stopPropagation();openImpactScorePopover(\'' + bomEsc + '\',\'\',0,this);">' +
            '<div style="width:3px;height:14px;background:' + barColor + ';border-radius:2px;flex-shrink:0;"></div>' +
            '<span style="font-size:10px;font-weight:bold;color:' + barColor + ';min-width:14px;">' + levelText + '</span>' +
            '<div style="width:36px;height:4px;background:#e8e8e8;border-radius:2px;overflow:hidden;flex-shrink:0;">' +
            '<div style="width:' + totalScore + '%;height:100%;background:' + barColor + ';"></div>' +
            '</div>' +
            '</div>';
    }

    function refreshUrgencyBar(bom) {
        var el = document.querySelector('.urgency-score-bar[data-bom="' + bom + '"]');
        if (!el) return;
        var impact = window._impactCache && window._impactCache[bom];
        var buffer = window._bufferCache && window._bufferCache[bom];
        var urgent = window._urgentCache && window._urgentCache[bom];
        if (impact && impact.success) el.innerHTML = buildUrgencyBarHtml(bom, impact, buffer, urgent);
    }

    function loadImpactScoreForVisible() {
        if (!window._impactCache) window._impactCache = {};
        var rows = Array.from(document.querySelectorAll('tr[data-bom]'));
        var toLoad = rows.filter(function(tr) {
            var bom = tr.getAttribute('data-bom');
            return bom && !window._impactCache[bom];
        }).slice(0, 8).map(function(tr){ return tr.getAttribute('data-bom'); });
        if (!toLoad.length) return;
        toLoad.forEach(function(bom) {
            window._impactCache[bom] = { _loading: true };
            var procCount = window.bomPSList ? window.bomPSList.filter(function(p){ return p.bom === bom; }).length : 0;
            $.ajax({
                url: '', type: 'POST',
                data: { action: 'get_bom_impact_score', bom: bom, process_count: procCount },
                dataType: 'json',
                success: function(res) {
                    window._impactCache[bom] = res;
                    refreshUrgencyBar(bom);
                },
                error: function() { window._impactCache[bom] = { success: false }; }
            });
        });
    }

    // ══════════════════════════════════════════════════════════════
    // 例外內製製程設定彈窗
    // ══════════════════════════════════════════════════════════════
    function openInternalProcSettingModal() {
        var existing = document.getElementById('internal-proc-modal-overlay');
        if (existing) existing.remove();
        var ptList = window.processTypeList || [];
        var savedArr = (window.internalProcessTypes || []).map(String);
        var overlay = document.createElement('div');
        overlay.id = 'internal-proc-modal-overlay';
        overlay.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);display:flex;justify-content:center;align-items:center;z-index:10070;';
        var box = document.createElement('div');
        box.style.cssText = 'background:#fff;border-radius:6px;padding:20px;width:340px;max-width:95%;max-height:80vh;overflow-y:auto;box-shadow:0 4px 20px rgba(0,0,0,0.2);';
        var html = '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;border-bottom:1px solid #eee;padding-bottom:8px;">' +
            '<h4 style="margin:0;font-size:15px;">例外內製製程設定</h4>' +
            '<button id="ipt-close-x" style="border:none;background:none;font-size:18px;cursor:pointer;">✕</button>' +
            '</div>' +
            '<p style="font-size:12px;color:#666;margin-bottom:12px;">勾選後，即使廠商不是廠內（internal=1），此製程類型也視為廠內加工，不計入外包占比。</p>' +
            '<div id="ipt-list" style="margin-bottom:14px;">';
        ptList.forEach(function(pt) {
            var chk = savedArr.includes(String(pt.process_type_id)) ? 'checked' : '';
            html += '<label style="display:flex;align-items:center;gap:8px;padding:5px 0;border-bottom:1px solid #f5f5f5;cursor:pointer;">' +
                '<input type="checkbox" value="' + pt.process_type_id + '" ' + chk + ' style="width:15px;height:15px;"> ' +
                '<span style="font-size:13px;">' + escapeHtml(pt.process_type) + '</span></label>';
        });
        html += '</div><div style="display:flex;justify-content:flex-end;gap:8px;">' +
            '<button id="ipt-cancel" class="btn btn-default btn-sm">取消</button>' +
            '<button id="ipt-save" class="btn btn-primary btn-sm">儲存</button></div>';
        box.innerHTML = html;
        overlay.appendChild(box);
        document.body.appendChild(overlay);
        box.querySelector('#ipt-close-x').onclick = function() { overlay.remove(); };
        box.querySelector('#ipt-cancel').onclick = function() { overlay.remove(); };
        box.querySelector('#ipt-save').onclick = function() {
            var selected = Array.from(box.querySelectorAll('#ipt-list input[type=checkbox]:checked')).map(function(cb){ return parseInt(cb.value); });
            $.ajax({
                url: '', type: 'POST',
                data: { action: 'save_internal_process_types', selected_json: JSON.stringify(selected) },
                dataType: 'json',
                success: function(res) {
                    if (res.success) {
                        window.internalProcessTypes = selected;
                        window._impactCache = {};
                        showTemporaryMessage('例外製程設定已儲存', true);
                        overlay.remove();
                        setTimeout(loadImpactScoreForVisible, 300);
                    } else { alert('儲存失敗: ' + (res.message || '')); }
                },
                error: function() { alert('通訊失敗'); }
            });
        };
        overlay.addEventListener('click', function(e) { if (e.target === overlay) overlay.remove(); });
    }

    // 變成 已移轉
    function submitFormWithBif(bomIngFid) {
        // Log the action for debugging purposes
        console.log(`Attempting to transfer BOM_ING_FID: ${bomIngFid}`);

        // Use the Fetch API to send an asynchronous POST request
        fetch('../../src/store/_pmGotoNext_bt.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                // Send the bom_ing_fid as a URL-encoded form parameter
                body: `bif=${encodeURIComponent(bomIngFid)}`
            })
            .then(response => {
                // Check if the network response was successful (status code 200-299)
                if (!response.ok) {
                    // Log an error if the response was not OK, but do not display an alert
                    console.error(`Network response was not ok: ${response.status} ${response.statusText}`);
                }
                // If the backend is expected to return JSON, you would parse it here:
                // return response.json();
                // Otherwise, just return the response to continue the promise chain
                return response;
            })
            .then(() => {
                // After the request is processed (regardless of success or specific content),
                // refresh the main table data to reflect any changes.
                isSelectFocused = isTextareaFocused = isUpdatingOrderId = isPriorityUpdating = false;
                fetchDataAndFilter();
            })
            .catch(error => {
                // Catch any network errors during the fetch operation
                console.error('Error during fetch operation for _pmGotoNext_bt.php:', error);
                // Do not display an alert, as per the requirement for silent execution
            });
    }
    // 1. 獲取篩選條件 (確保此函數正確讀取所有篩選元件的值)
    function getFilters() {
        let customerFilterValue = document.getElementById('customer-filter').value;
        let vendorFilterValue = document.getElementById('vendor-filter').value;
        let statusFilterValue = document.getElementById('status-filter') ? document.getElementById('status-filter').value : '';

        if (statusFilterValue === "no_vendor") {
            vendorFilterValue = "FILTER_NO_VENDOR"; // 特殊標記值
            statusFilterValue = ""; // 清空狀態篩選，因為它由廠商篩選處理
        } else if (statusFilterValue === "no_client_name") {
            customerFilterValue = "FILTER_NO_CLIENT_NAME"; // 特殊標記值
            statusFilterValue = ""; // 清空狀態篩選，因為它由客戶篩選處理
        }

        return {
            customer: customerFilterValue,
            bomColor: window.currentBomFilter || 'all',
            bom: document.getElementById('bom-filter').value.toLowerCase(),
            sales: document.getElementById('sales-filter').value.toLowerCase(),
            vendor: vendorFilterValue,
            order: document.getElementById('order-filter').value.toLowerCase(),
            deliveryDate: document.getElementById('delivery-date-filter').value.trim(), // 新增：交期篩選值
            date: document.getElementById('date-filter').value,
            status: statusFilterValue, // 可能的值: is_stock, no_order_data, unselected_order_in_dropdown, 或一般狀態碼
            qcDatePick: (document.getElementById('qc-date-filter') || {}).value || '',
            globalSearch: document.getElementById('global-search').value.toLowerCase(), // 日內未回篩選值
            elapsedDays: elapsedDaysFilterValue,
            processNotHalfwayActive: isProcessNotHalfwayFilterActive, // Add state of the new filter
            pti: window.ptiSearch || ''
        };
    }

    // ── 清單排序（發單日／交期／BOM／料號／客戶，可遞增遞減）─────────────────
    // 重點：排序一律「套在所有篩選條件跑完之後的完整結果」上，且在分頁切割之前，
    //       所以任何篩選狀態下都能正常排序，且排的是全部符合條件的資料而非只有本頁。
    var listSortField = '';    // '' = 原始順序（伺服器回傳順序）
    var listSortDir   = 'asc'; // 'asc' | 'desc'

    var LIST_SORT_LABELS = {
        '':               '原始順序',
        'outsource_date': '發單日',
        'delivery_date':  '交期',
        'bom':            'BOM',
        'd_id':           '料號',
        'customer':       '客戶'
    };

    // 將 YYYY/M/D、YYYY-M-D、YYYY/M/D HH:MM:SS 轉為可比較的數值；無效回傳 null
    function _sortDateValue(s) {
        if (s === null || s === undefined) return null;
        var str = String(s).trim();
        if (!str || str === 'null' || str.indexOf('0000-00-00') === 0) return null;
        var m = str.replace(/-/g, '/').match(/^(\d{4})\/(\d{1,2})\/(\d{1,2})/);
        if (!m) return null;
        return parseInt(m[1], 10) * 10000 + parseInt(m[2], 10) * 100 + parseInt(m[3], 10);
    }

    // 取得該列目前「選中的訂單」交期（與 CSV 匯出取法一致）
    function _sortDeliveryDateOf(row) {
        if (row && Array.isArray(row.OrderList) && row.Order_id && row.Order_id !== 'none') {
            var sel = row.OrderList.find(function(o) { return o && o.Order_id == row.Order_id; });
            if (sel && sel.Delivery_date) return sel.Delivery_date;
        }
        return null;
    }

    // 取出排序鍵：{ n: 數值(日期用) 或 null, s: 字串(文字用) }
    function _listSortKey(row, field) {
        if (!row) return { n: null, s: '' };
        switch (field) {
            case 'outsource_date':
                return { n: _sortDateValue(row.outsource_date), s: '' };
            case 'delivery_date':
                return { n: _sortDateValue(_sortDeliveryDateOf(row)), s: '' };
            case 'bom':
                return { n: null, s: String(row.bom || '').trim() };
            case 'd_id':
                return { n: null, s: String(row.d_display || row.d_id || '').trim() };
            case 'customer':
                return { n: null, s: String(row.client_name_display || row.Client_Name_Full || row.Client_Name || '').trim() };
            default:
                return { n: null, s: '' };
        }
    }

    // 對「已篩選完」的陣列做排序；空值一律排在最後（不論遞增遞減），並保持穩定排序
    function applyListSort(rows) {
        if (!listSortField || !Array.isArray(rows) || rows.length < 2) return rows;

        var isDate = (listSortField === 'outsource_date' || listSortField === 'delivery_date');
        var dir = (listSortDir === 'desc') ? -1 : 1;

        var decorated = rows.map(function(row, idx) {
            var k = _listSortKey(row, listSortField);
            var isEmpty = isDate ? (k.n === null) : (k.s === '');
            return { row: row, idx: idx, n: k.n, s: k.s, empty: isEmpty };
        });

        decorated.sort(function(a, b) {
            if (a.empty !== b.empty) return a.empty ? 1 : -1; // 無資料永遠殿後
            if (a.empty && b.empty) return a.idx - b.idx;
            var cmp;
            if (isDate) {
                cmp = (a.n === b.n) ? 0 : (a.n < b.n ? -1 : 1);
            } else {
                // 數字型料號/BOM 用 numeric 比較才不會出現 10 排在 9 前面
                cmp = a.s.localeCompare(b.s, 'zh-Hant', { numeric: true, sensitivity: 'base' });
            }
            if (cmp !== 0) return cmp * dir;
            return a.idx - b.idx; // 穩定：同鍵值保持原始順序
        });

        return decorated.map(function(d) { return d.row; });
    }

    // 同步排序控制項 / 表頭圖示的顯示狀態
    function updateListSortUI() {
        var sel = document.getElementById('list-sort-field');
        if (sel && sel.value !== listSortField) sel.value = listSortField;

        var dirBtn = document.getElementById('btn-list-sort-dir');
        if (dirBtn) {
            dirBtn.textContent = (listSortDir === 'desc') ? '▼ 遞減' : '▲ 遞增';
            dirBtn.disabled = !listSortField;
            dirBtn.classList.toggle('active', !!listSortField);
            dirBtn.title = listSortField ?
                ('目前：' + (LIST_SORT_LABELS[listSortField] || listSortField) + (listSortDir === 'desc' ? ' 由大到小／由新到舊' : ' 由小到大／由舊到新') + '，點擊切換') :
                '請先選擇排序欄位';
        }

        var clearBtn = document.getElementById('btn-list-sort-clear');
        if (clearBtn) clearBtn.disabled = !listSortField;

        document.querySelectorAll('#table-DOWN .th-sort-btn').forEach(function(el) {
            var f = el.getAttribute('data-sort-field');
            if (f && f === listSortField) {
                el.classList.add('active');
                el.textContent = (listSortDir === 'desc') ? '▼' : '▲';
            } else {
                el.classList.remove('active');
                el.textContent = '⇅';
            }
        });
    }

    // 設定排序欄位/方向並重繪（field 傳 '' = 取消排序）
    function setListSort(field, dir) {
        listSortField = field || '';
        if (dir === 'asc' || dir === 'desc') listSortDir = dir;
        if (!listSortField) listSortDir = 'asc';
        updateListSortUI();
        currentPage = 1; // 排序變更回到第一頁
        processAndRenderData();
    }

    // 2. 過濾數據陣列 (確保此函數邏輯正確，操作 data 陣列)
    function filterData(data) {
        var filters = getFilters();
        // console.log("Applying filters:", filters); // Debug
        let filtered = data.filter(function(row) {
            // 確保 row 和 row.OrderList 存在且是陣列
            if (!row || typeof row !== 'object') return false;
            const orderList = Array.isArray(row.OrderList) ? row.OrderList : [];
            let show = true; // 預設顯示

            // --- Status Filter (is_stock, no_order_data, unselected_order_in_dropdown, regular processing_state) ---
            // "no_vendor" and "no_client_name" are handled by their respective filters below.
            if (filters.status) {
                if (filters.status === "is_stock") { // 備庫
                    if (row.Order_id !== 'B') {
                        show = false;
                    }
                } else if (filters.status === "no_order_data") { // 無訂單 (BOM本身無訂單記錄)
                    const isNoOrderList = orderList.length === 0; // No orders in OrderList at all for this BOM
                    const isSingleNoOoInList = orderList.length === 1 && (!orderList[0].Order_oo || orderList[0].Order_oo.trim() === ''); // OrderList only has one "無編號" placeholder
                    // To be "no_order_data", it must NOT be stock ('B') AND must satisfy the no order list conditions.
                    if (row.Order_id === 'B' || !(isNoOrderList || isSingleNoOoInList)) {
                        show = false;
                    }
                } else if (filters.status === "unselected_order_in_dropdown") { // 無交期(未選訂單)
                    const isDropdownPleaseSelect = (!row.Order_id || String(row.Order_id).trim() === ''); // Dropdown is "請選擇"
                    const isNotStock = (row.Order_id !== 'B'); // Not "備庫"
                    // Check if BOM has actual orders in its OrderList (not empty and not just a placeholder)
                    const bomHasActualOrders = !(orderList.length === 0 || (orderList.length === 1 && (!orderList[0].Order_oo || orderList[0].Order_oo.trim() === '')));

                    if (!(isDropdownPleaseSelect && isNotStock && bomHasActualOrders)) {
                        show = false;
                    }
                } else if (filters.status === "has_bom_ing_ps") { // 有備註 (BOM 主檔備註)
                    // If bom_bom_ps is null, undefined, or an empty string after trimming, then hide.
                    if (!row.bom_bom_ps || String(row.bom_bom_ps).trim() === "") {
                        show = false;
                    }
                } else if (filters.status === "has_report_data") { // 有報工資料
                    // 檢查是否有 PM 報工資料 (pm_has_report) 或舊版報工資料 (oready_sqty_total > 0)
                    const hasPM = row.pm_has_report === true;
                    const hasOld = (parseFloat(row.oready_sqty_total) > 0 || parseFloat(row.ng_sqty_total) > 0);
                    if (!hasPM && !hasOld) {
                        show = false;
                    }
                } else if (filters.status === 'has_new_process_report') { // 新增：有新製程報工
                    if (!row.has_new_process_report) show = false;
                } else if (filters.status === 'qc_check_any') { // QC檢驗：QC有檢驗/完工紀錄且序號≧目前製程（原「今日QC檢驗」改版）
                    if (!getQcSyncInfo(row).hasQcAtOrBeyond) show = false;
                } else if (filters.status === 'qc_date_pick') { // 指定日期QC檢驗
                    const pickDate = String(filters.qcDatePick || '').trim().replace(/-/g, '/');
                    const rowQcDate2 = String(row.QC_check_date || '').trim();
                    if (!pickDate || !rowQcDate2 || rowQcDate2 !== pickDate) show = false;
                } else if (filters.status === 'unbound_order') { // 未綁定訂單（bom.d_setting_id 為空）
                    const isUnbound = !row.d_setting_id || String(row.d_setting_id).trim() === '';
                    if (!isUnbound) show = false;
                } else { // Regular processing_state filter (e.g., 'P', 'Q', 'ing', 'E')
                    if (String(row.processing_state || '').trim() !== filters.status) {
                        show = false;
                    }
                }
            }
            // --- Customer Filter (handles regular and "FILTER_NO_CLIENT_NAME") ---
            if (filters.customer) {
                if (filters.customer === "FILTER_NO_CLIENT_NAME") { // 無客戶名稱
                    if (row.Client_Name && String(row.Client_Name).trim() !== "") show = false;
                } else { // Regular customer name search
                    const custLower = String(filters.customer).toLowerCase();
                    // 比對「實際顯示」的客戶名稱（client_name_display：有綁定料號時取料號客戶），
                    // 與第 4343 行顯示邏輯一致，避免篩選「群燁」卻顯示「誠岱」的不一致。
                    const displayedName = String(row.client_name_display || row.Client_Name_Full || row.Client_Name || '').toLowerCase();
                    const custMatch = (displayedName && displayedName.indexOf(custLower) !== -1) ||
                                      (row.d_customer_id && String(row.d_customer_id).toLowerCase().indexOf(custLower) !== -1);
                    if (!custMatch) show = false;
                }
            }

            // NEW Sales Filter Logic
            if (filters.sales) {
                const salesNameOnly = filters.sales.split(' (')[0];
                let effectiveSalesName = null;

                if (row.PrimarySalesName) {
                    if (!row.IsPrimaryOnLeave) {
                        // Primary is working
                        effectiveSalesName = row.PrimarySalesName;
                    } else { // Primary is on leave
                        if (row.DeputySalesName && !row.IsDeputyOnLeave) {
                            // Deputy is working
                            effectiveSalesName = row.DeputySalesName;
                        } else {
                            // Primary is on leave, and deputy is not available or also on leave.
                            // The case is still filterable by the primary salesperson.
                            effectiveSalesName = row.PrimarySalesName;
                        }
                    }
                }

                if (!effectiveSalesName || effectiveSalesName.toLowerCase().indexOf(salesNameOnly) === -1) {
                    show = false;
                }
            }

            // BOM/料號
            if (filters.bom && (!row.bom || row.bom.toLowerCase().indexOf(filters.bom) === -1) && (!row.d_id || row.d_id.toLowerCase().indexOf(filters.bom) === -1)) show = false;

            // --- Vendor Filter (handles regular and "FILTER_NO_VENDOR") ---
            if (filters.vendor) { // filters.vendor could be a name or "無廠商"
                if (filters.vendor === "FILTER_NO_VENDOR") {
                    if (row.maker_id && String(row.maker_id).trim() !== "") {
                        show = false;
                    }
                } else {
                    const fvLower = String(filters.vendor).trim().toLowerCase();
                    const makerOk = (row.maker_id && String(row.maker_id).toLowerCase().indexOf(fvLower) !== -1) ||
                                    (row.maker_id_no_list && String(row.maker_id_no_list).toLowerCase().indexOf(fvLower) !== -1);
                    if (!makerOk) show = false;
                }
            }

            // --- 製程篩選 ---
            if (filters.pti && !window._matchPti(row, filters.pti)) {
                show = false;
            }
            // 發單數 (包含比較符；同時比對 BOM 總數與拆分批次個別數量)
            if (filters.order) {
                let operator = '=';
                let filterValStr = filters.order;
                if (['>', '<', '='].includes(filters.order[0])) {
                    operator = filters.order[0];
                    filterValStr = filters.order.slice(1).trim();
                }
                const _rowQty  = parseFloat(row.Qty);
                const filterVal = parseFloat(filterValStr);
                var _qtyMatch = false;
                if (!isNaN(_rowQty) && !isNaN(filterVal)) {
                    if (operator === '>' && _rowQty > filterVal) _qtyMatch = true;
                    else if (operator === '<' && _rowQty < filterVal) _qtyMatch = true;
                    else if (operator === '=' && _rowQty === filterVal) _qtyMatch = true;
                } else if (String(row.Qty).toLowerCase().indexOf(filterValStr) !== -1) {
                    _qtyMatch = true;
                }
                // 若 BOM 總數不符，再比對拆分批次個別數量
                if (!_qtyMatch && window.bomPSList) {
                    var _qbom = String(row.bom||'').trim();
                    for (var _qpi=0;_qpi<window.bomPSList.length;_qpi++) {
                        var _qp=window.bomPSList[_qpi];
                        if (!_qp||String(_qp.bom||'').trim()!==_qbom) continue;
                        var _batches=(_qp.split_batches&&_qp.split_batches.length>1)?_qp.split_batches:[{sqty:_qp.sqty}];
                        for (var _qi=0;_qi<_batches.length;_qi++) {
                            var _bsqty=parseFloat(_batches[_qi].sqty||0);
                            if (!isNaN(_bsqty)&&!isNaN(filterVal)) {
                                if (operator==='>'&&_bsqty>filterVal){_qtyMatch=true;break;}
                                if (operator==='<'&&_bsqty<filterVal){_qtyMatch=true;break;}
                                if (operator==='='&&_bsqty===filterVal){_qtyMatch=true;break;}
                            }
                        }
                        if (_qtyMatch) break;
                    }
                }
                if (!_qtyMatch) show = false;
            }

            // 日期 (包含比較符)
            if (filters.date) {
                let dateOperator = '=';
                let dateValueStr = filters.date;
                if (['>', '<', '='].includes(filters.date[0])) {
                    dateOperator = filters.date[0];
                    dateValueStr = filters.date.slice(1).trim();
                }
                const partsDate = dateValueStr.split("/");
                if (dateValueStr && partsDate.length === 2) {
                    const currentYear = new Date().getFullYear();
                    dateValueStr = `${currentYear}/${dateValueStr}`;
                }
                let filterDate = dateValueStr ? convertDateFormat(dateValueStr) : null;
                let rowDate = row.Created_At_s ? convertDateFormat(row.Created_At_s) : null;

                if (filterDate && rowDate && !isNaN(filterDate.getTime()) && !isNaN(rowDate.getTime())) {
                    let normFilterTime = normalizeDate(filterDate).getTime();
                    let normRowTime = normalizeDate(rowDate).getTime();
                    if (dateOperator === '>' && !(normRowTime > normFilterTime)) show = false;
                    else if (dateOperator === '<' && !(normRowTime < normFilterTime)) show = false;
                    else if (dateOperator === '=' && !(normRowTime === normFilterTime)) show = false;
                } else if (filterDate) {
                    show = false;
                }
            }

            // 交期篩選 (與報工日篩選邏輯類似，但目標是 row.Delivery_date)
            if (filters.deliveryDate) {
                let deliveryDateOperator = '=';
                let deliveryDateValueStr = filters.deliveryDate;

                if (['>', '<', '='].includes(filters.deliveryDate[0])) {
                    deliveryDateOperator = filters.deliveryDate[0];
                    deliveryDateValueStr = filters.deliveryDate.slice(1).trim();
                }

                // 處理 MM/DD 格式，補上年份
                const partsDeliveryDate = deliveryDateValueStr.split("/");
                if (deliveryDateValueStr && partsDeliveryDate.length === 2) {
                    const currentYear = new Date().getFullYear();
                    deliveryDateValueStr = `${currentYear}/${deliveryDateValueStr}`;
                }

                let filterDeliveryDate = deliveryDateValueStr ? convertDateFormat(deliveryDateValueStr) : null;

                // --- CORRECTED LOGIC TO GET ROW'S DELIVERY DATE ---
                let rowDeliveryDateStr = null;
                // 只有在已選擇訂單 (非備庫、非請選擇) 的情況下，才進行日期篩選
                if (row.Order_id && row.Order_id !== 'B' && Array.isArray(row.OrderList)) {
                    const selectedOrder = row.OrderList.find(o => o && String(o.Order_id) === String(row.Order_id));
                    if (selectedOrder && selectedOrder.Delivery_date) {
                        rowDeliveryDateStr = selectedOrder.Delivery_date; // This is 'YYYY-MM-DD' from PHP
                    }
                }
                let rowDeliveryDate = rowDeliveryDateStr ? convertDateFormat(rowDeliveryDateStr) : null;
                // --- END CORRECTED LOGIC ---

                if (filterDeliveryDate) { // Only filter if a valid filter date is provided
                    if (rowDeliveryDate && !isNaN(rowDeliveryDate.getTime())) {
                        let normFilterDeliveryTime = normalizeDate(filterDeliveryDate).getTime();
                        let normRowDeliveryTime = normalizeDate(rowDeliveryDate).getTime();
                        if (deliveryDateOperator === '>' && !(normRowDeliveryTime > normFilterDeliveryTime)) show = false;
                        else if (deliveryDateOperator === '<' && !(normRowDeliveryTime < normFilterDeliveryTime)) show = false;
                        else if (deliveryDateOperator === '=' && !(normRowDeliveryTime === normFilterDeliveryTime)) show = false;
                    } else { // If filter is set but row has no valid date, hide it.
                        show = false;
                    }
                }
            }

            // 優先級燈號篩選 (BOM Priority Light Filter)
            if (filters.bomColor && filters.bomColor !== 'all') { // Ensure bomColor filter is active and not 'all'
                const actualRowPriorityColor = determineRowColor(row);
                if (actualRowPriorityColor.color !== filters.bomColor) {
                    show = false;
                }
            }

            // 全域搜索（排除廠商代號/客戶ID，這兩個有專屬篩選欄位）
            if (filters.globalSearch) {
                let match = false;
                for (const key in row) {
                    if (row.hasOwnProperty(key) && key !== 'maker_id_no_list' && key !== 'd_customer_id' &&
                        String(row[key]).toLowerCase().includes(filters.globalSearch)) {
                        match = true;
                        break;
                    }
                }
                if (!match) show = false;
            }

            // --- 日內未回 (發單未回日) 篩選 ---
            if (filters.elapsedDays !== null && typeof filters.elapsedDays === 'number') {
                // row.elapsed_workdays_outsource_today is "發單日"與"今日"之間已過的工作日
                if (row.elapsed_workdays_outsource_today === null || row.elapsed_workdays_outsource_today < filters.elapsedDays) {
                    show = false;
                }
            }

            // --- 製程未過半 篩選 ---
            if (filters.processNotHalfwayActive) {
                let isNotHalfway = false; // Assume it IS halfway, then prove it's NOT.

                const processesForThisBom = window.bomPSList.filter(p => p.bom === row.bom);

                if (processesForThisBom.length > 0) {
                    processesForThisBom.sort((a, b) => (parseInt(a.bom_sn) || 0) - (parseInt(b.bom_sn) || 0));
                    const currentBomSn = parseInt(row.bom_sn);

                    if (!isNaN(currentBomSn)) { // Only proceed if current_bom_sn is a valid number
                        const processType7Info = processesForThisBom.find(p => String(p.process_type_id) === '7');

                        if (processType7Info) {
                            // Logic 1: process_type_id = 7 exists
                            // "只要目前製程還沒經過（或尚未到達）process_type_id = 7，就視為「未過半」"
                            // current_bom_sn <= bom_sn_of_process_7 means "not halfway"
                            const process7Sn = parseInt(processType7Info.bom_sn);
                            if (!isNaN(process7Sn)) {
                                if (currentBomSn <= process7Sn) {
                                    isNotHalfway = true;
                                }
                            }
                        } else {
                            // Logic 2: No process_type_id = 7
                            // "使用「已經過的工作天數」與「總製程數」做比例換算。"
                            // "例如本列製程數為6，今天是第 3 工作日，若目前製程小於或等於第 3 個製程，則視為未過半。"
                            // This implies: current_bom_sn <= elapsed_workdays_outsource_today
                            const elapsedWorkdays = row.elapsed_workdays_outsource_today; // Calculated in calculateAllWorkdayMetrics

                            if (elapsedWorkdays !== null && elapsedWorkdays >= 0) {
                                if (currentBomSn <= elapsedWorkdays) {
                                    isNotHalfway = true;
                                }
                            }
                        }
                    }
                }
                // If isNotHalfway is still false, it means it didn't meet any "not halfway" criteria,
                // or data was insufficient. So, we hide it.
                if (!isNotHalfway) {
                    show = false;
                }
            }


            return show;
        });
        // console.log(`Filtered data count: ${filtered.length}`); // Debug
        // 篩選完成後再套用排序（排全部符合條件的資料，之後才由呼叫端切頁）
        filtered = applyListSort(filtered);
        return filtered;
    }

    // 更新訂單BOM對應訂單
    function updateOrderId(orderId, bomKey, selectElement) { // MODIFIED: bomFid to bomKey
        isUpdatingOrderId = true; // Set flag at the beginning of the operation
        // console.log("updateOrderId - 開始", {
        //     orderId: orderId,
        //     bomKey: bomKey, // MODIFIED
        //     isUpdatingOrderId: isUpdatingOrderId
        // });
        // *** 新增 Log：顯示傳入的 orderId 和 bomFid ***
        // console.log("updateOrderId - Received: orderId =", orderId, 
        //             "(type:", typeof orderId, 
        //             "), bomKey =", bomKey, 
        //             "(type:", typeof bomKey, ")"
        // ); // MODIFIED

        var tdElement = selectElement.closest('td');
        if (!tdElement) {
            /* ... 錯誤處理 ... */
            return;
        }

        // --- 保存原始狀態 (用於錯誤恢復) ---
        var originalDeliveryText = '';
        var originalSelectHTML = selectElement.outerHTML;
        var originalSelectedValue = selectElement.value; // <-- 保存原始選中的值
        var brTag = tdElement.querySelector('br');
        // ... (獲取 originalDeliveryText 的邏輯) ...
        if (brTag && brTag.previousSibling && brTag.previousSibling.nodeType === Node.TEXT_NODE) {
            originalDeliveryText = brTag.previousSibling.textContent;
        } else if (tdElement.firstChild && tdElement.firstChild.nodeType === Node.TEXT_NODE) {
            originalDeliveryText = tdElement.firstChild.textContent;
        }


        // --- 立即從前端數據源查找並更新 DOM (保持之前的修改) ---
        var rowIndex = fullDataset.findIndex(item => item.bom == bomKey); // MODIFIED: Find by bomKey (which is row.bom)
        var newDeliveryText = "資料錯誤";
        // ... (計算 newDeliveryText 的邏輯，使用 == 比較) ...
        if (rowIndex === -1) {
            console.error("updateOrderId - 錯誤：在 fullDataset 中找不到 bomKey:", bomKey, "fullDataset length:", fullDataset.length); // MODIFIED
            newDeliveryText = "處理中...";
        } else {
            var rowData = fullDataset[rowIndex];
            // *** 新增 Log：顯示找到的 rowData 及其 OrderList ***
            // console.log("updateOrderId - Found rowData at index", rowIndex, ":", JSON.parse(JSON.stringify(rowData)));
            if (rowData && Array.isArray(rowData.OrderList)) { // MODIFIED: bomKey
                // console.log("updateOrderId - Inspecting OrderList for bomKey:", bomKey, "OrderList content:", JSON.parse(JSON.stringify(rowData.OrderList))); // MODIFIED
                // rowData.OrderList.forEach(o => {
                //     console.log("  Order in list - ID:", o.Order_id, "(type:", typeof o.Order_id, "), Order_oo:", o.Order_oo, ", Delivery_date:", o.Delivery_date, ", Qty:", o.Qty);
                // });
            } else {
                // console.warn("updateOrderId - OrderList is missing or not an array for bomKey:", bomKey, "for rowData:", JSON.parse(JSON.stringify(rowData))); // MODIFIED
            }
            if (rowData && Array.isArray(rowData.OrderList)) {

            } else {
                // console.warn("updateOrderId - OrderList is missing or not an array for bomKey:", bomKey); // MODIFIED
            }
            // console.log("updateOrderId - Found rowData for bomKey:", bomKey, JSON.parse(JSON.stringify(rowData))); // MODIFIED: Log the specific row

            var selectedOrderInfo = null;
            if (rowData && Array.isArray(rowData.OrderList)) { // 確保 rowData 和 OrderList 都存在且是陣列
                // 使用更嚴格的比較，或確保類型一致
                selectedOrderInfo = rowData.OrderList.find(o => o && String(o.Order_id) === String(orderId));
                // *** 修正：檢查 selectedOrderInfo 是否為 undefined ***
                if (typeof selectedOrderInfo !== 'undefined') {
                    // console.log("updateOrderId - Searching in OrderList for orderId:", orderId, "Found selectedOrderInfo:", JSON.parse(JSON.stringify(selectedOrderInfo)));
                } else { // *** 新增 Log：如果 selectedOrderInfo 是 undefined ***

                    // console.log("updateOrderId - Searching in OrderList for orderId:", orderId, "Found selectedOrderInfo: undefined");
                }
            } else {
                console.warn("updateOrderId - rowData.OrderList is missing or not an array for bomKey:", bomKey, "rowData:", rowData); // MODIFIED
            }

            if (orderId === 'B') { // If "備庫" is selected (value is 'B')
                newDeliveryText = "備庫";
            } else if (selectedOrderInfo) {
                // If Delivery_date is present and not an empty string
                if (selectedOrderInfo.Delivery_date && String(selectedOrderInfo.Delivery_date).trim() !== "") {
                    // PHP now provides YYYY-MM-DD directly, so no need for complex conversion here.
                    const formattedDeliveryDate = escapeHtml(String(selectedOrderInfo.Delivery_date));
                    // Qty can be 0. If Qty is null or undefined, display as '0'.
                    let qtyDisplay = (selectedOrderInfo.Qty === null || typeof selectedOrderInfo.Qty === 'undefined') ? '0' : selectedOrderInfo.Qty;

                    // New logic for Open_Qty
                    let openQtyInfo = '';
                    if (selectedOrderInfo.Open_Qty == selectedOrderInfo.Qty) {
                        // openQtyInfo = '(尚未交貨)';
                    } else {
                        let openQtyDisplay = (selectedOrderInfo.Open_Qty === null || typeof selectedOrderInfo.Open_Qty === 'undefined') ? '?' : selectedOrderInfo.Open_Qty;
                        openQtyInfo = `<div style="color: red; font-size: 0.8em; line-height: 1;">(未交${escapeHtml(String(openQtyDisplay))})</div>`;
                    }

                    newDeliveryText = formattedDeliveryDate + "x" + escapeHtml(String(qtyDisplay)) + openQtyInfo;
                } else {
                    newDeliveryText = "無交期"; // Only show "無交期" if Delivery_date itself is missing/empty
                }
            } else if (orderId && orderId !== 'B' && orderId !== '') {
                // If orderId is something other than 'B' or empty (for "請選擇"), but no info found
                console.warn("updateOrderId - 找不到訂單詳細資訊:", {
                    orderId: orderId,
                    bomKey: bomKey, // MODIFIED
                });
                newDeliveryText = "訂單資訊缺失";
            }
        }

        // --- 立即更新 DOM 文字 ---
        // console.log("updateOrderId - 立即更新 DOM 文字為:", newDeliveryText);
        var deliveryTextDisplaySpan = tdElement.querySelector('.delivery-text-display');
        if (deliveryTextDisplaySpan) {
            deliveryTextDisplaySpan.textContent = newDeliveryText;
        } else {
            console.error("updateOrderId - 錯誤：找不到 .delivery-text-display span 元素來更新文字。");
            // 如果找不到 span，可能需要一個備用方案，但理想情況下它應該總是被 updateTable 創建。
        }
        // --- DOM 文字更新結束 ---

        // --- AJAX 更新到後台 ---
        $.ajax({
            url: '../../src/store/_update_order_id.php',
            type: 'POST',
            data: {
                Order_id: orderId,
                bom: bomKey // MODIFIED: Send bomKey (which is row.bom) to backend
            },
            dataType: 'json',
            success: function(response) {
                // console.log("updateOrderId - 後端成功回應:", response);
                // isUpdatingOrderId will be set to false in complete callback
                // setTimeout(function() { // Delay slightly to ensure other event handlers complete
                //     isUpdatingOrderId = false;
                // }, 0);
                if (response && response.success) {
                    // --- 後端成功，更新前端數據源 (fullDataset) ---
                    if (rowIndex !== -1) {
                        // *** MODIFIED: Store 'none' as a string if orderId is 'none' ***
                        // This ensures consistency with the dropdown option value for "備庫"
                        // and allows updateTable to correctly re-render the "備庫" state.
                        fullDataset[rowIndex].Order_id = orderId;
                        // console.log("updateOrderId - 更新 fullDataset[" + rowIndex + "].Order_id 為:", fullDataset[rowIndex].Order_id);
                    } else {
                        /* ... 重試更新 fullDataset 的邏輯 ... */
                    }

                    // *** 核心修改：明確設定下拉選單的視覺選中項 ***
                    console.log("--- 訂單判定邏輯 DEBUG ---");
                    console.log("BOM:", rowData.bom);
                    console.log("料號 (d_id):", rowData.d_id);
                    console.log("OrderList 內容:", rowData.OrderList);
                    if (selectElement) { // 確保 selectElement 仍然有效
                        selectElement.value = orderId; // <--- 設定 select 的值
                        // console.log("updateOrderId - 更新下拉選單視覺值為:", orderId);
                        // 在 fullDataset 更新和 selectElement.value 設定後，再次確認 span 的文字內容
                        var deliveryTextDisplaySpanRecheck = tdElement.querySelector('.delivery-text-display');
                        if (deliveryTextDisplaySpanRecheck) {
                            // newDeliveryText 是在 AJAX 調用前計算好的，應該仍然是 "備庫"
                            deliveryTextDisplaySpanRecheck.textContent = newDeliveryText;
                        }
                    }

                    // --- 給予視覺回饋 ---
                    tdElement.style.backgroundColor = '#dff0d8';
                    setTimeout(function() {
                        tdElement.style.backgroundColor = '';
                    }, 100);

                } else {
                    // --- 後端返回失敗 ---
                    console.error("updateOrderId - 後端更新失敗:", response);
                    alert('訂單更新失敗：' + (response.message || '未知錯誤'));
                    // *** 恢復 DOM 到原始狀態 ***
                    // console.log("updateOrderId - 恢復 DOM 到原始狀態:", originalDeliveryText);
                    // ... (恢復 DOM 文字的邏輯) ...
                    if (brTag && brTag.previousSibling && brTag.previousSibling.nodeType === Node.TEXT_NODE) {
                        brTag.previousSibling.textContent = originalDeliveryText;
                    } else {
                        tdElement.innerHTML = originalDeliveryText + '<br>' + originalSelectHTML;
                        selectElement = tdElement.querySelector('select[name="Order_id"]'); // 重新獲取引用
                    }
                    // *** 恢復下拉選單的值 ***
                    if (selectElement) { // 確保引用有效
                        selectElement.value = originalSelectedValue; // <--- 恢復原始值
                        // console.log("updateOrderId - 恢復下拉選單視覺值為:", originalSelectedValue);
                    }
                }
            },
            error: function(xhr, status, error) {
                // --- AJAX 請求失敗 ---
                console.error("updateOrderId - AJAX 請求失敗:", status, error);
                // isUpdatingOrderId = false; // Reset flag on error
                // setTimeout(function() { // Delay slightly
                //     isUpdatingOrderId = false;
                // }, 0);
                alert('與伺服器通訊失敗，無法更新訂單。');
                // *** 恢復 DOM 到原始狀態 ***
                // console.log("updateOrderId - 恢復 DOM 到原始狀態:", originalDeliveryText);
                // ... (恢復 DOM 文字的邏輯) ...
                if (brTag && brTag.previousSibling && brTag.previousSibling.nodeType === Node.TEXT_NODE) {
                    brTag.previousSibling.textContent = originalDeliveryText;
                } else {
                    tdElement.innerHTML = originalDeliveryText + '<br>' + originalSelectHTML;
                    selectElement = tdElement.querySelector('select[name="Order_id"]'); // 重新獲取引用
                }
                // *** 恢復下拉選單的值 ***
                if (selectElement) { // 確保引用有效
                    selectElement.value = originalSelectedValue; // <--- 恢復原始值
                    // console.log("updateOrderId - 恢復下拉選單視覺值為:", originalSelectedValue);
                }
            }
        });
    }

    function updateBomDeliveryDate(bom, date) {
        $.ajax({
            url: '', // Current file
            type: 'POST',
            data: {
                action: 'update_bom_delivery_date',
                bom: bom,
                delivery_date: date
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    const item = fullDataset.find(i => i.bom === bom);
                    if (item) item.Delivery_date = date;
                    processAndRenderData();
                    showTemporaryMessage('交期已更新', true);
                } else {
                    alert('更新失敗: ' + response.message);
                }
            }
        });
    }

    // 8. 其他輔助函數 (確保存在且正確)
    function generateBomHtml(row, baseExcelUrl) {
        // 組合 Excel 連結 (假設使用 ms-excel URI)
        const excelLink = `<a href="ms-excel:ofe|u|${baseExcelUrl}${escapeHtml(row.bom)}.xlsm" target="_blank">${escapeHtml(row.bom)}</a>`;

        let circleClass = 'circle_green';
        
        // 使用 determineRowColor 統一處理所有燈號邏輯 (含優先級與自動計算)
        const colorInfo = determineRowColor(row);
        const finalColor = colorInfo.color;
        let titleText = colorInfo.title;

        if (finalColor === 'red') {
            circleClass = 'circle_red';
        } else if (finalColor === 'yellow') {
            circleClass = 'circle_y';
        }

        // --- 計算並顯示進度百分比 (目前進度 / 正常進度) ---
        let progressHtml = '';
        const elapsedTotal = row.elapsed_workdays_total_to_today;
        const daysPerProcess = parseFloat(window.settingProcessDays) || 0;
        const yellowPct = parseFloat(window.settingYellowDays) || 0;
        const redPct = parseFloat(window.settingRedDays) || 0;
        const redDaysBeforeSetting = parseInt(window.settingRedDaysBefore, 10);

        // --- 準備額外顯示資訊 (過交期 / 自動調整紅燈) ---
        let extraInfoHtml = '';
        const remainingWorkdays = row.remaining_workdays_today_delivery;
        const totalWorkdays = row.total_workdays_outsource_to_selected_delivery;

        if (remainingWorkdays !== null && typeof remainingWorkdays === 'number') {
            if (remainingWorkdays <= 0) {
                // 顯示已過交期天數
                extraInfoHtml = `<div style="font-size: 0.8em; margin-top: 2px; color: red; font-weight: bold;">已過交期 ${Math.abs(remainingWorkdays)} 工作天</div>`;
            } else if (!isNaN(redDaysBeforeSetting) && [5, 10].includes(redDaysBeforeSetting) && totalWorkdays !== null && totalWorkdays > 0) {
                // 檢查是否觸發自動調整顯示
                if (totalWorkdays <= redDaysBeforeSetting) {
                    const threshold = Math.ceil(totalWorkdays / 2);
                    // 只有在確實觸發紅燈條件時才顯示註記，或者總是顯示規則？
                    // 需求：「並在BOM欄位下方註記：自動調整...」
                    // 通常是在觸發該規則時顯示，避免資訊過多。
                    // 但若要提示使用者目前的紅燈標準已改變，則應顯示。
                    // 這裡設定為：只要符合「總天數過短」的條件就顯示提示，讓使用者知道標準變了。
                    // 但為了版面整潔，僅在剩餘天數接近時顯示可能較好。
                    // 依照需求語意，當「自動改為...亮紅燈」發生時註記。
                    if (remainingWorkdays <= threshold) {
                        extraInfoHtml = `<div style="font-size: 0.8em; margin-top: 2px; color: red;">自動調整：交期前 ${threshold} 天亮紅燈</div>`;
                    }
                }
            }
        }

        if (window.bomPSList && daysPerProcess > 0) {
            const processes = window.bomPSList.filter(p => p.bom === row.bom)
                                            .sort((a, b) => (parseInt(a.bom_sn)||0) - (parseInt(b.bom_sn)||0));
            const processCount = processes.length;

            if (processCount > 0) {
                // 1. 計算目前進度 % (依據目前製程在總製程中的位置)
                let currentSn = 0;
                if (row.bom_sn) {
                    const snParts = String(row.bom_sn).split(',');
                    currentSn = snParts.reduce((max, val) => Math.max(max, parseInt(val)||0), 0);
                }
                let currentProcessIndex = 0;
                const foundIndex = processes.findIndex(p => parseInt(p.bom_sn) === currentSn);
                if (foundIndex !== -1) {
                    currentProcessIndex = foundIndex + 1;
                }
                let currPct = (currentProcessIndex / processCount) * 100;

                // 2. 計算正常進度 % (依據時間流逝)
                const processBasedTotalDays = processCount * daysPerProcess;
                
                // 修正：若總加工日大於製程估算日，則以總加工日為分母 (避免長交期案件過早顯示正常進度100%)
                let effectiveTotalDays = processBasedTotalDays;
                if (typeof totalWorkdays === 'number' && totalWorkdays > processBasedTotalDays) {
                    effectiveTotalDays = totalWorkdays;
                }

                let normPct = 0;
                if (elapsedTotal !== null && effectiveTotalDays > 0) {
                    normPct = (elapsedTotal / effectiveTotalDays) * 100;
                    if (normPct > 100) normPct = 100; // 上限 100%
                    if (normPct < 0) normPct = 0;
                }

                // 3. 判斷樣式 (紅/黃字加粗)
                let style = 'font-size: 0.8em; margin-top: 2px; color: #777;'; // 預設灰色
                
                const redThreshold = normPct * (redPct / 100);
                const yellowThreshold = normPct * (yellowPct / 100);

                if (redPct > 0 && currPct < redThreshold) {
                    style = 'font-size: 0.8em; margin-top: 2px; color: red; font-weight: bold;';
                } else if (yellowPct > 0 && currPct < yellowThreshold) {
                    style = 'font-size: 0.8em; margin-top: 2px; color: #E6AC00; font-weight: bold;'; // 使用較深的黃色以利閱讀
                }

                progressHtml = `<div style="${style}">進度 ${currPct.toFixed(0)}% / 正常進度 ${normPct.toFixed(0)}%</div>`;
            }
        }

        // 緩衝比燈號已移除（避免 BOM 欄出現雙燈號）
        var bufferCircleHtml = '';

        // （急單/衝擊資訊已移至料號欄的 urgency-score-bar，BOM 欄不再重複顯示）

        const copyButtonHtml = `<button type='button' class='btn btn-xs btn-copy' title='複製 BOM' onclick='event.stopPropagation(); copyToClipboard("${escapeHtml(row.bom)}", this)'><i class='fa fa-copy'></i></button>`;

        // 組裝 HTML（BOM欄恢復簡潔：燈號+BOM編號+複製+進度+過交期）
        // 緩衝比燈號(DEBUG)仍可並排，但天數/急單/衝擊已移至料號欄
        return '<div class="control-label">' +
            '<div class="bom-content-holder" style="display:flex;align-items:center;gap:2px;">' +
                '<figure class="' + circleClass + '"' +
                    ' data-bom="' + escapeHtml(row.bom) + '"' +
                    ' data-priority="' + escapeHtml(row.priority_type || '') + '"' +
                    ' style="cursor:pointer;margin-right:3px;"' +
                    ' title="' + escapeHtml(titleText) + '"></figure>' +
                excelLink +
                copyButtonHtml +
            '</div>' +
            progressHtml +
            extraInfoHtml +
        '</div>';
    }

    // 輔助函式：轉換 HTML 特殊字元 (加入類型檢查和轉換)
    function escapeHtml(text) {
        if (text == null) return ""; // 處理 null 或 undefined
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return String(text).replace(/[&<>"']/g, function(m) {
            return map[m];
        });
    }

    // 新增：處理BOM優先級燈號點擊事件
    function handlePriorityClick(lightElement) {
        const bom = lightElement.dataset.bom;
        let currentPriority = lightElement.dataset.priority; // String: "", "U", or "E"

        isPriorityUpdating = true; // <--- Set flag before AJAX call
        // console.log(`handlePriorityClick: isPriorityUpdating set to true for BOM ${bom}`);

        // --- Optimistic UI: Store original state for potential rollback ---
        const originalClass = lightElement.className;
        const originalDataPriority = lightElement.dataset.priority;
        const originalTitle = lightElement.title;
        const itemIndexInFullDataset = fullDataset.findIndex(item => item.bom === bom);
        let originalPriorityInDataset = null;
        if (itemIndexInFullDataset > -1) {
            originalPriorityInDataset = fullDataset[itemIndexInFullDataset].priority_type;
        }

        let newPriorityType;
        let newClass;
        let newTitleText;

        if (currentPriority === "") { // Was null/empty (Green), change to 'U' (Yellow)
            newPriorityType = 'U';
            newClass = 'circle_y';
            newTitleText = "目前：急件 - 點擊切換";
        } else if (currentPriority === "U") { // Was 'U' (Yellow), change to 'E' (Red)
            newPriorityType = 'E';
            newClass = 'circle_red';
            newTitleText = "目前：特急件 - 點擊切換";
        } else if (currentPriority === "E") { // Was 'E' (Red), change to null (Green)
            newPriorityType = ''; // Representing null
            newClass = 'circle_green'; // Directly set to green
            newTitleText = "目前：一般 - 點擊切換";
        } else {
            console.error("Unknown current priority:", currentPriority);
            // Rollback optimistic UI if state is unknown
            lightElement.className = originalClass;
            lightElement.dataset.priority = originalDataPriority;
            lightElement.title = originalTitle;
            if (itemIndexInFullDataset > -1) {
                fullDataset[itemIndexInFullDataset].priority_type = originalPriorityInDataset;
            }
            return;
        }

        // --- Optimistic UI: Update UI and dataset immediately ---
        lightElement.className = newClass;
        lightElement.dataset.priority = newPriorityType;
        lightElement.title = newTitleText;

        if (itemIndexInFullDataset > -1) {
            fullDataset[itemIndexInFullDataset].priority_type = newPriorityType === '' ? null : newPriorityType;
            console.log(`Optimistic update: BOM ${bom} priority in fullDataset set to ${fullDataset[itemIndexInFullDataset].priority_type}`);
        }
        // Store/Overwrite the latest intended priority for this BOM
        // This happens regardless of whether the AJAX call is in progress for a *previous* click on *another* light.
        pendingPriorityUpdates[bom] = newPriorityType === '' ? null : newPriorityType;
        console.log(`Stored/Updated pending priority for BOM ${bom}:`, pendingPriorityUpdates[bom], "Current pending updates:", JSON.parse(JSON.stringify(pendingPriorityUpdates)));

        $.ajax({
            url: '../../src/store/_update_bom_priority.php', // 確保這是您目前使用的路徑
            type: 'POST',
            data: {
                bom: bom,
                new_priority_type: newPriorityType // Send '' for null
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    // UI and dataset already updated optimistically.
                    // Optional: Log success or provide subtle feedback.
                    // console.log('BOM priority successfully updated on backend for BOM:', bom);
                } else {
                    alert('更新優先級失敗: ' + response.message);
                    // --- Optimistic UI: Rollback on backend failure ---
                    lightElement.className = originalClass;
                    lightElement.dataset.priority = originalDataPriority;
                    lightElement.title = originalTitle;
                    if (itemIndexInFullDataset > -1) {
                        fullDataset[itemIndexInFullDataset].priority_type = originalPriorityInDataset;
                    }
                }
            },
            error: function(xhr, status, error) {
                alert('與伺服器通訊失敗，無法更新優先級。變更已還原。');
                // --- Optimistic UI: Rollback on AJAX error ---
                lightElement.className = originalClass;
                lightElement.dataset.priority = originalDataPriority;
                lightElement.title = originalTitle;
                if (itemIndexInFullDataset > -1) {
                    fullDataset[itemIndexInFullDataset].priority_type = originalPriorityInDataset;
                }
            },
            complete: function() { // <--- AJAX complete 回調
                isPriorityUpdating = false; // <--- Reset flag after AJAX completes
                // console.log(`handlePriorityClick: isPriorityUpdating set to false for BOM ${bom}, fetch will resume.`);
            }
        });
    }

    // --- Helper function to apply pending priority updates to the table and dataset ---
    function applyPendingPriorityUpdates() {
        const tableBody = document.getElementById('table-DOWN')?.querySelector('tbody');
        if (!tableBody) {
            console.error("applyPendingPriorityUpdates: Table body not found.");
            return;
        }

        for (const bomToUpdate in pendingPriorityUpdates) {
            if (pendingPriorityUpdates.hasOwnProperty(bomToUpdate)) {
                const newPriority = pendingPriorityUpdates[bomToUpdate]; // This will be null, 'U', or 'E'

                // 1. Update fullDataset (ensure it reflects the absolute latest state from pending)
                const itemInDataset = fullDataset.find(item => item.bom === bomToUpdate);
                if (itemInDataset) {
                    itemInDataset.priority_type = newPriority; // newPriority is already null if it was an empty string
                    // console.log(`Applied pending update to fullDataset for BOM ${bomToUpdate}, priority: ${newPriority}`);
                } else {
                    console.warn(`BOM ${bomToUpdate} not found in fullDataset during pending update application.`);
                }

                // 2. Update the visual light in the currently rendered table page
                const lightElement = tableBody.querySelector(`figure[data-bom="${bomToUpdate}"]`);

                if (lightElement) {
                    let newClass;
                    let newTitleText;

                    if (newPriority === null) { // Green
                        newClass = 'circle_green';
                        newTitleText = "目前：一般 - 點擊切換";
                    } else if (newPriority === 'U') { // Yellow
                        newClass = 'circle_y';
                        newTitleText = "目前：急件 (U) - 點擊切換";
                    } else if (newPriority === 'E') { // Red
                        newClass = 'circle_red';
                        newTitleText = "目前：特急件 (E) - 點擊切換";
                    }

                    lightElement.className = newClass;
                    lightElement.dataset.priority = newPriority === null ? '' : newPriority; // Store '' for null in data-attribute
                    lightElement.title = newTitleText;
                    // console.log(`Applied pending update to light for BOM ${bomToUpdate}, class: ${newClass}, data-priority: ${lightElement.dataset.priority}`);
                } else {
                    // console.warn(`Light element for BOM ${bomToUpdate} not found in the current table page during pending update application.`);
                }
            }
        }
    }
    // 6. 新增核心處理函數 processAndRenderData
    function processAndRenderData() {
        var filters = getFilters(); // 先取得篩選條件
        // console.log("執行 processAndRenderData..."); // Debug
        if (!fullDataset || fullDataset.length === 0) {
            // console.log("processAndRenderData: fullDataset 為空，清空表格"); // Debug
            updateTable([]);
            updatePaginationControls(0);
            updateDropdowns([]); // 傳遞空陣列以清空下拉選單
            return;
        }

        // console.log("processAndRenderData - fullDataset 長度:", fullDataset.length, "使用的篩選條件:", filters); // <-- 新增 Log
        const filteredDataset = filterData(fullDataset); // 1. 過濾完整數據
        // console.log("processAndRenderData - filteredDataset 長度:", filteredDataset.length); // <-- 新增 Log
        const totalRecords = filteredDataset.length;
        const totalPages = Math.max(1, Math.ceil(totalRecords / recordsPerPage));

        // 確保 currentPage 在有效範圍內 (篩選後可能改變總頁數)
        if (currentPage > totalPages) currentPage = totalPages;
        if (currentPage < 1) currentPage = 1;

        const start = (currentPage - 1) * recordsPerPage;
        const end = start + recordsPerPage;
        const pageData = filteredDataset.slice(start, end); // 2. 切割出當前頁數據

        // console.log(`渲染第 ${currentPage} 頁，資料範圍 ${start} 到 ${end}，共 ${pageData.length} 筆`); // Debug

        updateTable(pageData); // 3. 將當前頁數據渲染到表格
        updatePaginationControls(totalRecords); // 4. 更新分頁控制項顯示

        // *** 新增/修改：決定下拉選單的數據源 ***
        // 如果 ptiSearch 是空字串 (代表"全部製程"或未篩選)，則使用完整數據集 fullDataset
        // 否則，使用已被篩選過的數據集 filteredDataset
        const dropdownDataSource = (ptiSearch === "") ? fullDataset : filteredDataset;
        // console.log(`Dropdown source determined: ${ptiSearch === "" ? 'fullDataset' : 'filteredDataset'} (length: ${dropdownDataSource.length})`); // Debug
        updateDropdowns(dropdownDataSource); // 5. 更新篩選下拉選單，傳入決定的數據源

        // ── 急單歷史標記：分批載入 ──
        clearTimeout(window._urgentLoadTimer);
        window._urgentLoadTimer = setTimeout(loadUrgentLevelForVisible, 600);
        // ── 急迫評分條：分批載入 ──
        if (!window._impactCache) window._impactCache = {};
        clearTimeout(window._impactLoadTimer);
        window._impactLoadTimer = setTimeout(loadImpactScoreForVisible, 800);
    }

    // --- Function to Export Filtered Data to CSV (Simple Version) ---
    function exportToCSV() {
        console.log("Exporting CSV...");
        try {
            const filteredData = filterData(fullDataset); // Get currently filtered data

            if (!filteredData || !Array.isArray(filteredData) || filteredData.length === 0) {
                alert("沒有可匯出的資料。");
                return;
            }

            // Define CSV Headers
            let headers = [ // Changed to let to allow modification
                "客戶", "訂單號", "交期", "數量", "BOM", "料號", "發單日",
                "製程", "廠商", "狀態", "發單數", "備註", "最後報工日", "已加工", "NG"
            ];

            // Add dynamic process headers
            if (window.maxCount && window.maxCount > 0) {
                for (let i = 1; i <= window.maxCount; i++) {
                    headers.push(`製程 ${i}`);
                }
            }

            // Function to escape CSV fields
            const escapeCSV = (field) => {
                if (field == null) return '';
                const str = String(field);
                if (str.includes(',') || str.includes('"') || str.includes('\n')) {
                    return `"${str.replace(/"/g, '""')}"`;
                }
                return str;
            };

            // Convert data rows to CSV format
            const csvRows = filteredData.map(row => {
                let orderOo = '',
                    deliveryDate = '',
                    qty = '';
                if (row && Array.isArray(row.OrderList) && row.Order_id && row.Order_id !== 'none') {
                    const selectedOrder = row.OrderList.find(o => o && o.Order_id == row.Order_id);
                    if (selectedOrder) {
                        orderOo = selectedOrder.Order_oo || '無編號';
                        deliveryDate = selectedOrder.Delivery_date || '';
                        qty = selectedOrder.Qty || '';
                    }
                }
                const processInfo = `${row?.process_no || ''} ${row?.ProcessName || ''}`.trim();
                const translatedStateForCSV = translateProcessingState(row?.processing_state);

                let csvRowArray = [
                    escapeCSV(row?.Client_Name), escapeCSV(orderOo), escapeCSV(deliveryDate), escapeCSV(qty), // 客戶, 訂單號, 交期, 數量
                    escapeCSV(row?.bom), escapeCSV(row?.d_id), escapeCSV(row?.outsource_date), // BOM, 料號, 發單日
                    escapeCSV(processInfo),
                    escapeCSV(row?.maker_id), // 廠商
                    escapeCSV(translatedStateForCSV), // 狀態
                    escapeCSV(row?.Qty), escapeCSV(row?.ps), escapeCSV(row?.Created_At_s),
                    escapeCSV(row?.oready_sqty_total || 0), escapeCSV(row?.ng_sqty_total || 0)
                ];

                // Add dynamic process data
                if (window.bomPSList && window.maxCount && window.maxCount > 0) {
                    const currentBom = (row.bom ? String(row.bom).trim() : '');
                    const matchingProcesses = window.bomPSList.filter(item => item.bom && String(item.bom).trim() === currentBom);
                    matchingProcesses.sort((a, b) => (parseInt(a.processing_sequence || 0) - parseInt(b.processing_sequence || 0)));

                    for (let i = 0; i < window.maxCount; i++) {
                        if (matchingProcesses[i] && matchingProcesses[i].ProcessName) {
                            csvRowArray.push(escapeCSV(matchingProcesses[i].ProcessName));
                        } else {
                            csvRowArray.push(''); // Empty string if no process for this step
                        }
                    }
                } else if (window.maxCount && window.maxCount > 0) { // If bomPSList is not available but maxCount is, fill with empty
                    for (let i = 0; i < window.maxCount; i++) {
                        csvRowArray.push('');
                    }
                }
                return csvRowArray.join(',');
            });


            // Combine headers and rows, add BOM for Excel
            const csvString = "\uFEFF" + headers.join(',') + '\n' + csvRows.join('\n');

            // Create Blob and trigger download
            const blob = new Blob([csvString], {
                type: 'text/csv;charset=utf-8;'
            });
            const link = document.createElement("a");
            const url = URL.createObjectURL(blob);
            link.setAttribute("href", url);
            link.setAttribute("download", "bom_overview_export.csv");
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            URL.revokeObjectURL(url);
            console.log("CSV export triggered.");

        } catch (error) {
            console.error("CSV Export failed:", error);
            alert("匯出 CSV 時發生錯誤，請檢查控制台。");
        }
    }

    // --- Function to Export Visible Table to JPG (Simple Version) ---
    function exportToJPG() {
        console.log("Exporting JPG...");
        const exportButton = document.getElementById('btn-export-jpg');
        const tableElement = document.getElementById('table-DOWN');

        if (!tableElement) {
            alert("找不到要匯出的表格元素 (ID: table-DOWN)。");
            return;
        }
        if (exportButton) exportButton.disabled = true; // 禁用按鈕

        html2canvas(tableElement, {
                scale: 2,
                useCORS: true,
                logging: false
            }) // scale=2 提高解析度
            .then(canvas => {
                const link = document.createElement('a');
                link.download = 'bom_overview_export.jpg';
                link.href = canvas.toDataURL('image/jpeg', 0.9); // JPEG 格式
                link.click();
                console.log("JPG export triggered.");
            })
            .catch(err => {
                console.error("JPG export failed:", err);
                alert("匯出 JPG 失敗，請檢查控制台。");
            })
            .finally(() => {
                if (exportButton) exportButton.disabled = false; // 重新啟用按鈕
            });
    }

    // ── 通知廠商圖 ────────────────────────────────────────────────────────────
    // 依「目前篩選 + 目前排序」的全部資料（不是只有本頁）產生一張只含
    // BOM / 料號 / 發單日 三欄的圖片，並自動複製到剪貼簿，方便直接貼到 LINE / 郵件。
    // ⚠ 安全規定：圖片內容一律只取這三個欄位，永遠不得包含單價／金額等任何價格資訊
    //   （本函式直接由資料欄位重繪，不截取畫面，因此不論使用者權限高低都不可能帶出單價）。

    function _vendorImgFormatDate(s) {
        if (s === null || s === undefined) return '';
        var str = String(s).trim();
        if (!str || str === 'null' || str.indexOf('0000-00-00') === 0) return '';
        var m = str.replace(/-/g, '/').match(/^(\d{4})\/(\d{1,2})\/(\d{1,2})/);
        if (!m) return str;
        return m[1] + '/' + String(parseInt(m[2], 10)).padStart(2, '0') + '/' + String(parseInt(m[3], 10)).padStart(2, '0');
    }

    // 繪製圖片；回傳 canvas
    function _buildVendorNotifyCanvas(rows) {
        var HEADERS = ['BOM', '料號', '發單日'];
        var data = rows.map(function(r) {
            return [
                String(r.bom || ''),
                String(r.d_display || r.d_id || ''),
                _vendorImgFormatDate(r.outsource_date) || '－'
            ];
        });

        var SCALE      = 2;      // 2 倍解析度，貼上後不糊
        var FONT       = '15px "Microsoft JhengHei", "Noto Sans TC", Arial, sans-serif';
        var FONT_BOLD  = 'bold 15px "Microsoft JhengHei", "Noto Sans TC", Arial, sans-serif';
        var FONT_TITLE = 'bold 20px "Microsoft JhengHei", "Noto Sans TC", Arial, sans-serif';
        var FONT_SUB   = '13px "Microsoft JhengHei", "Noto Sans TC", Arial, sans-serif';
        var ROW_H      = 28;
        var PAD_X      = 10;     // 儲存格左右內距
        var MARGIN     = 16;     // 圖片外框留白
        var BLOCK_GAP  = 24;     // 多欄區塊之間的間距

        // 量測用 canvas
        var meas = document.createElement('canvas').getContext('2d');

        // 依筆數決定要切成幾個直欄（避免變成一條很長的圖）
        var n = data.length;
        var blocks = 1;
        if (n > 30)  blocks = 2;
        if (n > 70)  blocks = 3;
        if (n > 120) blocks = 4;
        var rowsPerBlock = Math.ceil(n / blocks);

        // 各欄寬度（所有區塊共用同一組寬度，看起來整齊）
        var colW = HEADERS.map(function(h) {
            meas.font = FONT_BOLD;
            return meas.measureText(h).width;
        });
        meas.font = FONT;
        data.forEach(function(row) {
            row.forEach(function(cell, i) {
                var w = meas.measureText(cell).width;
                if (w > colW[i]) colW[i] = w;
            });
        });
        colW = colW.map(function(w, i) {
            var min = (i === 2) ? 92 : (i === 0 ? 80 : 110);
            return Math.max(min, Math.ceil(w) + PAD_X * 2);
        });
        var blockW = colW.reduce(function(a, b) { return a + b; }, 0);

        // 標題資訊
        var now = new Date();
        var pad2 = function(v) { return String(v).padStart(2, '0'); };
        var stamp = now.getFullYear() + '/' + pad2(now.getMonth() + 1) + '/' + pad2(now.getDate()) +
                    ' ' + pad2(now.getHours()) + ':' + pad2(now.getMinutes());
        var sortText = listSortField ?
            ('排序：' + (LIST_SORT_LABELS[listSortField] || listSortField) + (listSortDir === 'desc' ? '（遞減）' : '（遞增）')) :
            '排序：原始順序';
        var title = '發單通知清單';
        var subtitle = '共 ' + n + ' 筆　|　' + sortText + '　|　產生時間 ' + stamp;

        var headerH = 30;                       // 表頭列高
        var titleH  = 30 + 20;                  // 標題 + 副標
        var tableH  = headerH + rowsPerBlock * ROW_H;
        var totalW  = MARGIN * 2 + blockW * blocks + BLOCK_GAP * (blocks - 1);
        meas.font = FONT_SUB;
        var subW = meas.measureText(subtitle).width + MARGIN * 2;
        if (subW > totalW) totalW = Math.ceil(subW);
        var totalH  = MARGIN * 2 + titleH + tableH;

        var canvas = document.createElement('canvas');
        canvas.width  = Math.ceil(totalW * SCALE);
        canvas.height = Math.ceil(totalH * SCALE);
        canvas.style.width  = totalW + 'px';
        canvas.style.height = totalH + 'px';
        var ctx = canvas.getContext('2d');
        ctx.scale(SCALE, SCALE);
        ctx.textBaseline = 'middle';

        // 底色
        ctx.fillStyle = '#FFFFFF';
        ctx.fillRect(0, 0, totalW, totalH);

        // 標題
        ctx.fillStyle = '#7a4a16';
        ctx.font = FONT_TITLE;
        ctx.textAlign = 'left';
        ctx.fillText(title, MARGIN, MARGIN + 14);
        ctx.fillStyle = '#8a6b45';
        ctx.font = FONT_SUB;
        ctx.fillText(subtitle, MARGIN, MARGIN + 38);

        // 逐區塊畫表格
        for (var b = 0; b < blocks; b++) {
            var offsetX = MARGIN + b * (blockW + BLOCK_GAP);
            var offsetY = MARGIN + titleH;
            var slice = data.slice(b * rowsPerBlock, (b + 1) * rowsPerBlock);

            // 表頭
            ctx.fillStyle = '#F0A24B';
            ctx.fillRect(offsetX, offsetY, blockW, headerH);
            ctx.fillStyle = '#FFFFFF';
            ctx.font = FONT_BOLD;
            var hx = offsetX;
            HEADERS.forEach(function(h, i) {
                ctx.textAlign = 'left';
                ctx.fillText(h, hx + PAD_X, offsetY + headerH / 2);
                hx += colW[i];
            });

            // 資料列
            ctx.font = FONT;
            for (var r = 0; r < slice.length; r++) {
                var y = offsetY + headerH + r * ROW_H;
                ctx.fillStyle = (r % 2 === 0) ? '#FFFFFF' : '#FDF3E4';
                ctx.fillRect(offsetX, y, blockW, ROW_H);
                ctx.fillStyle = '#4a3117';
                var cx = offsetX;
                for (var c = 0; c < slice[r].length; c++) {
                    var text = slice[r][c];
                    // 超寬文字裁切（理論上欄寬已依內容計算，這裡只是保險）
                    var maxW = colW[c] - PAD_X * 2;
                    while (text && ctx.measureText(text).width > maxW) {
                        text = text.slice(0, -1);
                    }
                    ctx.textAlign = 'left';
                    ctx.fillText(text, cx + PAD_X, y + ROW_H / 2);
                    cx += colW[c];
                }
            }

            // 格線
            ctx.strokeStyle = '#E0C091';
            ctx.lineWidth = 1;
            var blockRows = slice.length;
            var blockH = headerH + blockRows * ROW_H;
            ctx.strokeRect(offsetX + 0.5, offsetY + 0.5, blockW - 1, blockH - 1);
            ctx.beginPath();
            for (var rr = 0; rr <= blockRows; rr++) {
                var ly = offsetY + headerH + rr * ROW_H + 0.5;
                ctx.moveTo(offsetX, ly);
                ctx.lineTo(offsetX + blockW, ly);
            }
            var lx = offsetX;
            for (var ci = 0; ci < colW.length - 1; ci++) {
                lx += colW[ci];
                ctx.moveTo(lx + 0.5, offsetY);
                ctx.lineTo(lx + 0.5, offsetY + blockH);
            }
            ctx.stroke();
        }

        return canvas;
    }

    // 複製 canvas 圖片到剪貼簿：優先用 Clipboard API，內網 http 沒有安全內容時退回 execCommand
    function _copyCanvasToClipboard(canvas) {
        return new Promise(function(resolve) {
            var canUseClipboardApi = !!(navigator.clipboard && window.ClipboardItem && window.isSecureContext);

            function fallbackExecCommand() {
                try {
                    var holder = document.createElement('div');
                    holder.contentEditable = 'true';
                    holder.style.position = 'fixed';
                    holder.style.left = '-99999px';
                    holder.style.top = '0';
                    holder.style.opacity = '0';
                    var img = document.createElement('img');
                    img.src = canvas.toDataURL('image/png');
                    holder.appendChild(img);
                    document.body.appendChild(holder);

                    var range = document.createRange();
                    range.selectNode(img);
                    var sel = window.getSelection();
                    sel.removeAllRanges();
                    sel.addRange(range);

                    var ok = document.execCommand('copy');
                    sel.removeAllRanges();
                    document.body.removeChild(holder);
                    resolve(ok ? 'execCommand' : 'none');
                } catch (e) {
                    console.warn('execCommand 複製圖片失敗:', e);
                    resolve('none');
                }
            }

            if (!canUseClipboardApi) {
                fallbackExecCommand();
                return;
            }

            canvas.toBlob(function(blob) {
                if (!blob) { fallbackExecCommand(); return; }
                try {
                    navigator.clipboard.write([new ClipboardItem({ 'image/png': blob })])
                        .then(function() { resolve('clipboard'); })
                        .catch(function(err) {
                            console.warn('Clipboard API 複製失敗，改用備援方式:', err);
                            fallbackExecCommand();
                        });
                } catch (e) {
                    console.warn('Clipboard API 例外，改用備援方式:', e);
                    fallbackExecCommand();
                }
            }, 'image/png');
        });
    }

    function _downloadCanvasPng(canvas) {
        var now = new Date();
        var pad2 = function(v) { return String(v).padStart(2, '0'); };
        var name = '發單通知_' + now.getFullYear() + pad2(now.getMonth() + 1) + pad2(now.getDate()) +
                   '_' + pad2(now.getHours()) + pad2(now.getMinutes()) + '.png';
        var link = document.createElement('a');
        link.download = name;
        link.href = canvas.toDataURL('image/png');
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }

    function _showVendorNotifyModal(canvas, rowCount) {
        var mask = document.getElementById('vendor-notify-mask');
        if (!mask) {
            mask = document.createElement('div');
            mask.id = 'vendor-notify-mask';
            mask.innerHTML =
                '<div id="vendor-notify-box">' +
                '  <div id="vendor-notify-head"><span>通知廠商圖（BOM／料號／發單日）</span>' +
                '    <span style="margin-left:auto; cursor:pointer; font-size:16px;" id="vendor-notify-close-x" title="關閉">✕</span>' +
                '  </div>' +
                '  <div id="vendor-notify-body"></div>' +
                '  <div id="vendor-notify-foot">' +
                '    <span id="vendor-notify-status"></span>' +
                '    <span style="margin-left:auto; display:flex; gap:6px;">' +
                '      <button type="button" class="list-sort-btn" id="vendor-notify-recopy" style="height:26px;">再複製一次</button>' +
                '      <button type="button" class="list-sort-btn" id="vendor-notify-download" style="height:26px;">下載 PNG</button>' +
                '      <button type="button" class="list-sort-btn active" id="vendor-notify-close" style="height:26px;">關閉</button>' +
                '    </span>' +
                '  </div>' +
                '</div>';
            document.body.appendChild(mask);

            var closeFn = function() { mask.style.display = 'none'; };
            mask.querySelector('#vendor-notify-close').addEventListener('click', closeFn);
            mask.querySelector('#vendor-notify-close-x').addEventListener('click', closeFn);
            mask.addEventListener('click', function(e) { if (e.target === mask) closeFn(); });
            mask.querySelector('#vendor-notify-download').addEventListener('click', function() {
                if (mask._canvas) _downloadCanvasPng(mask._canvas);
            });
            mask.querySelector('#vendor-notify-recopy').addEventListener('click', function() {
                if (!mask._canvas) return;
                var st = mask.querySelector('#vendor-notify-status');
                st.textContent = '複製中…';
                _copyCanvasToClipboard(mask._canvas).then(function(how) {
                    _setVendorNotifyStatus(st, how, rowCount);
                });
            });
        }

        mask._canvas = canvas;
        var body = mask.querySelector('#vendor-notify-body');
        body.innerHTML = '';
        var img = document.createElement('img');
        img.src = canvas.toDataURL('image/png');
        img.alt = '發單通知清單';
        img.title = '若剪貼簿複製失敗，可在此圖上按右鍵 →「複製圖片」';
        body.appendChild(img);
        mask.style.display = 'block';
        return mask.querySelector('#vendor-notify-status');
    }

    function _setVendorNotifyStatus(statusEl, how, rowCount) {
        if (!statusEl) return;
        if (how === 'clipboard' || how === 'execCommand') {
            statusEl.textContent = '✔ 已複製到剪貼簿（共 ' + rowCount + ' 筆），可直接在 LINE／郵件／Word 按 Ctrl+V 貼上。';
            statusEl.style.color = '#7a4a16';
        } else {
            statusEl.textContent = '⚠ 瀏覽器不允許自動複製（內網為 http 連線時常見）。請在上圖按右鍵 →「複製圖片」，或按「下載 PNG」。';
            statusEl.style.color = '#DD5138';
        }
    }

    function exportVendorNotifyImage() {
        var btn = document.getElementById('btn-vendor-notify-img');
        if (btn) btn.disabled = true;
        try {
            var rows = filterData(fullDataset); // 目前篩選＋排序後的全部資料（非只有本頁）
            if (!rows || rows.length === 0) {
                alert('目前篩選結果沒有資料，無法產生通知廠商圖。');
                return;
            }
            if (rows.length > 300 && !confirm('目前篩選結果有 ' + rows.length + ' 筆，圖片會很大，確定要產生嗎？\n（建議先用廠商／製程等條件縮小範圍）')) {
                return;
            }

            var canvas = _buildVendorNotifyCanvas(rows);
            var statusEl = _showVendorNotifyModal(canvas, rows.length);
            if (statusEl) {
                statusEl.textContent = '複製中…';
                statusEl.style.color = '#8a6b45';
            }
            _copyCanvasToClipboard(canvas).then(function(how) {
                _setVendorNotifyStatus(statusEl, how, rows.length);
            });
        } catch (err) {
            console.error('通知廠商圖產生失敗:', err);
            alert('產生通知廠商圖時發生錯誤，請檢查控制台。');
        } finally {
            if (btn) btn.disabled = false;
        }
    }

    // --- Customer Switching Functions ---
    function switchToPrevCustomer() {
        if (availableCustomers.length === 0) return; // No customers to switch

        const currentFilterValue = document.getElementById('customer-filter').value;
        currentCustomerIndex = availableCustomers.indexOf(currentFilterValue);

        if (currentCustomerIndex === -1) { // Not found or first time
            currentCustomerIndex = availableCustomers.length - 1; // Start from the last one
        } else {
            currentCustomerIndex--;
            if (currentCustomerIndex < 0) {
                currentCustomerIndex = availableCustomers.length - 1; // Wrap around to last
            }
        }

        isCustomerSwitchingActive = true;
        document.getElementById('customer-filter').value = availableCustomers[currentCustomerIndex];
        processAndRenderData(); // Re-filter and render
    }

    function switchToNextCustomer() {
        if (availableCustomers.length === 0) return; // No customers to switch

        const currentFilterValue = document.getElementById('customer-filter').value;
        currentCustomerIndex = availableCustomers.indexOf(currentFilterValue);

        if (currentCustomerIndex === -1) { // Not found or first time
            currentCustomerIndex = 0; // Start from the first one
        } else {
            currentCustomerIndex++;
            if (currentCustomerIndex >= availableCustomers.length) {
                currentCustomerIndex = 0; // Wrap around to first
            }
        }

        isCustomerSwitchingActive = true;
        document.getElementById('customer-filter').value = availableCustomers[currentCustomerIndex];
        processAndRenderData(); // Re-filter and render
    }

    function switchToPrevVendor() {
        if (availableVendors.length === 0) return;
        const currentFilterValue = document.getElementById('vendor-filter').value;
        currentVendorIndex = availableVendors.indexOf(currentFilterValue);
        if (currentVendorIndex === -1) {
            currentVendorIndex = availableVendors.length - 1;
        } else {
            currentVendorIndex--;
            if (currentVendorIndex < 0) currentVendorIndex = availableVendors.length - 1;
        }
        document.getElementById('vendor-filter').value = availableVendors[currentVendorIndex];
        processAndRenderData();
    }

    function switchToNextVendor() {
        if (availableVendors.length === 0) return;
        const currentFilterValue = document.getElementById('vendor-filter').value;
        currentVendorIndex = availableVendors.indexOf(currentFilterValue);
        if (currentVendorIndex === -1) {
            currentVendorIndex = 0;
        } else {
            currentVendorIndex++;
            if (currentVendorIndex >= availableVendors.length) currentVendorIndex = 0;
        }
        document.getElementById('vendor-filter').value = availableVendors[currentVendorIndex];
        processAndRenderData();
    }

    // --- Clipboard Copy Function ---
    // --- Fallback Function ---
    function fallbackCopyToClipboard(text, buttonElement) {
        const textArea = document.createElement("textarea");
        textArea.value = text;
        textArea.style.position = "fixed"; // Prevent scrolling issues
        textArea.style.top = "-9999px";
        textArea.style.left = "-9999px";
        textArea.setAttribute("readonly", ""); // Make it non-editable
        document.body.appendChild(textArea);
        textArea.focus(); // Focus on the textarea
        textArea.select(); // Select its content

        try {
            const successful = document.execCommand('copy');
            if (successful) {
                console.log('已複製 (Fallback):', text);
                // Visual feedback
                const originalIcon = buttonElement.innerHTML;
                buttonElement.innerHTML = '<i class="fa fa-check"></i>';
                buttonElement.disabled = true;
                setTimeout(() => {
                    buttonElement.innerHTML = originalIcon;
                    buttonElement.disabled = false;
                }, 1000);
            } else {
                console.error('Fallback: document.execCommand("copy") returned false.');
                alert('自動複製失敗，請手動複製。'); // More specific message
            }
        } catch (err) {
            console.error('Fallback: 複製時發生錯誤:', err);
            alert('自動複製失敗，請手動複製。'); // More specific message
        } finally { // Ensure removal even if errors occur
            document.body.removeChild(textArea);
        }
    }

    function copyToClipboard(text, buttonElement) {
        // --- Modern Clipboard API (Try First) ---
        if (navigator.clipboard && navigator.clipboard.writeText) { // Check if writeText exists
            navigator.clipboard.writeText(text).then(function() {
                console.log('已複製 (Clipboard API):', text);
                // Visual feedback
                const originalIcon = buttonElement.innerHTML;
                buttonElement.innerHTML = '<i class="fa fa-check"></i>';
                buttonElement.disabled = true;
                setTimeout(() => {
                    buttonElement.innerHTML = originalIcon;
                    buttonElement.disabled = false;
                }, 1000);
            }).catch(function(err) {
                console.error('Clipboard API 複製失敗:', err);
                // --- Fallback on Modern API Failure ---
                fallbackCopyToClipboard(text, buttonElement);
            });
        } else {
            // --- Fallback if Modern API is not available at all ---
            console.warn("Clipboard API not available, using fallback.");
            fallbackCopyToClipboard(text, buttonElement);
        }
    }

    // --- Function to mark item as returned (status 'Q', or 'P' if is_exclude_qc) ---
    function markAsReturned(bomIngId, buttonElement, isExcludeQc, bomIngFid) {
        if (!bomIngId) {
            alert("錯誤：缺少 bom_ing_id！");
            return;
        }
        var confirmMsg = isExcludeQc
            ? "確認將此項目標記為待移轉(免驗)？"
            : "確認將此項目標記為已回 (狀態 Q)？";
        if (confirm(confirmMsg)) {
            buttonElement.disabled = true;
            buttonElement.textContent = '處理中...';

            var xhr = new XMLHttpRequest();
            xhr.open("POST", "../../src/store/_update_bom_ing_status.php", true);
            xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
            xhr.onreadystatechange = function() {
                if (xhr.readyState === 4) {
                    if (xhr.status === 200) {
                        try {
                            var response = JSON.parse(xhr.responseText);
                            if (response.success) {
                                var actualState = response.new_status || 'Q';
                                if (Array.isArray(fullDataset)) {
                                    fullDataset.forEach(function(item) {
                                        if (item && item.bom_ing_id && String(item.bom_ing_id).split(',').some(function(id){ return String(id).trim()===String(bomIngId).trim(); })) {
                                            item.processing_state = actualState;
                                        }
                                    });
                                }
                                // 樂觀更新 window.ingActiveMap
                                if (window.ingActiveMap) {
                                    Object.keys(window.ingActiveMap).forEach(function(bom) {
                                        if (Array.isArray(window.ingActiveMap[bom])) {
                                            window.ingActiveMap[bom].forEach(function(p) {
                                                if (String(p.bom_ing_id || '') === String(bomIngId)) {
                                                    p.processing_state = actualState;
                                                }
                                            });
                                        }
                                    });
                                }
                                isSelectFocused = false; isTextareaFocused = false;
                                isUpdatingOrderId = false; isPriorityUpdating = false;
                                processAndRenderData();
                                fetchDataAndFilter();
                            } else {
                                showTemporaryMessage("更新失敗：" + (response.message || "未知錯誤"), false);
                                buttonElement.disabled = false;
                                buttonElement.textContent = '已回';
                            }
                        } catch (e) {
                            showTemporaryMessage("處理回應時發生錯誤：" + e.message, false);
                            buttonElement.disabled = false;
                            buttonElement.textContent = '已回';
                        }
                    } else {
                        showTemporaryMessage("與伺服器通訊失敗，狀態碼：" + xhr.status, false);
                        buttonElement.disabled = false;
                        buttonElement.textContent = '已回';
                    }
                }
            };
            xhr.send("bom_ing_id=" + encodeURIComponent(bomIngId) + "&bom_ing_fid=" + encodeURIComponent(bomIngFid || '') + "&new_status=Q");
        }
    }

    // 函數：顯示用於修改客戶和製程的表單

    // ── 快速移轉（拆批時先選批次）────────────────────────────────────────────
    // 取出可操作的拆分批次清單（排除已結 E/1；需 <=1 表示不算拆批，回傳 null）
    function _getEligibleSplitBatches(processInfo) {
        var list = (processInfo.split_batches && processInfo.split_batches.length > 1)
            ? processInfo.split_batches
            : (processInfo.all_split_batches && processInfo.all_split_batches.length > 1)
            ? processInfo.all_split_batches : null;
        if (!list) return null;
        var eligible = list.filter(function(b){
            return b.processing_state !== 'E' && b.processing_state !== '1' && b.processing_state !== 'skip' && b.bom_ing_fid;
        });
        return eligible.length > 1 ? eligible : null;
    }

    function openQuickTransferModal(processInfo, rowData) {
        var _eligible = _getEligibleSplitBatches(processInfo);
        if (_eligible) {
            _openBatchPickerModal(processInfo, rowData, _eligible);
            return;
        }
        _openQuickTransferForm(rowData, {
            fid: String(processInfo.bom_ing_fid||''),
            process_no: processInfo.process_no,
            ProcessName: processInfo.ProcessName,
            maker_id_no: processInfo.maker_id_no,
            maker_id: processInfo.maker_id,
            batch_label: processInfo.batch_label || null
        });
    }

    // ── 拆批專用：先選要操作的批次（一次只能選一批）────────────────────────────
    function _openBatchPickerModal(processInfo, rowData, batches) {
        var bom=String(rowData.bom||''), did=String(rowData.d_display||rowData.d_id||'');
        var procNo=String(processInfo.process_no||''), procName=String(processInfo.ProcessName||'');
        var modalId='qbp-modal-'+String(processInfo.bom_sn||procNo);
        var ex=document.getElementById(modalId); if(ex)ex.remove();

        var canTransferRole = (!window.isCRU && window.displayPermissionCode !== 'D+R') || window.featTransfer;
        var canCancelRole = (window.displayPermissionCode === 'A' || window.displayPermissionCode === 'C+R+U' || window.displayPermissionCode === 'C+D+R+U');
        var stMap = {N:'待發包',P:'待移轉',ing:'加工中',Q:'QC待驗',E:'已結',1:'已結',skip:'跳過'};

        var rowsHtml = batches.map(function(b, idx){
            var lbl = escapeHtml(b.batch_label || '─');
            var st  = stMap[b.processing_state] || (b.processing_state||'');
            var sc  = b.processing_state==='ing'?'#1a7a1a': b.processing_state==='Q'?'#0056b3': b.processing_state==='P'?'#28a745': b.processing_state==='skip'?'#e67e22':'#337ab7';
            var makerTxt = b.maker_id ? escapeHtml(b.maker_id) : '<span style="color:#bbb;">未指定</span>';
            var canCancelThis = canCancelRole && b.processing_state !== 'N' && b.processing_state !== 'P';
            return '<div style="display:flex;align-items:center;justify-content:space-between;padding:8px 10px;border:1px solid #e5e5e5;border-radius:5px;margin-bottom:6px;">'
                 +   '<div style="font-size:12px;">'
                 +     '<strong style="color:#337ab7;">'+lbl+'</strong>'
                 +     ' <span style="color:#555;">'+escapeHtml(String(b.sqty||''))+' pcs</span>'
                 +     ' <span style="color:'+sc+';margin-left:6px;">['+escapeHtml(st)+']</span>'
                 +     '<br><small style="color:#888;">廠商：'+makerTxt+'</small>'
                 +   '</div>'
                 +   '<div style="display:flex;gap:6px;">'
                 +     (canTransferRole ? '<button type="button" class="btn btn-xs btn-primary qbp-pick-btn" data-idx="'+idx+'">發單</button>' : '')
                 +     (canCancelThis ? '<button type="button" class="btn btn-xs btn-danger qbp-cancel-btn" data-idx="'+idx+'">取消移轉</button>' : '')
                 +   '</div>'
                 + '</div>';
        }).join('');

        var overlay=document.createElement('div');
        overlay.id=modalId;
        overlay.style.cssText='position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.45);z-index:10500;display:flex;align-items:center;justify-content:center;';
        overlay.innerHTML='<div style="background:#fff;border-radius:6px;box-shadow:0 6px 32px rgba(0,0,0,0.22);width:420px;max-width:95vw;">'
            + '<div style="padding:12px 16px;border-bottom:1px solid #e0e0e0;display:flex;justify-content:space-between;align-items:center;">'
            +   '<strong style="font-size:13px;">'+escapeHtml(bom)+' / '+escapeHtml(did)+' '+escapeHtml(procNo)+' '+escapeHtml(procName)+'<br><small style="color:#888;font-weight:normal;">此製程已拆'+batches.length+'批，請選擇一批操作（一次僅能選一批）</small></strong>'
            +   '<button id="qbp-close" style="border:none;background:none;font-size:20px;cursor:pointer;color:#aaa;line-height:1;padding:0 4px;">×</button>'
            + '</div>'
            + '<div style="padding:12px 16px;max-height:60vh;overflow-y:auto;">'+rowsHtml+'</div>'
            + '</div>';
        document.body.appendChild(overlay);

        function _close(){ var m=document.getElementById(modalId); if(m) m.remove(); }
        overlay.querySelector('#qbp-close').onclick=_close;
        overlay.addEventListener('click', function(e){ if (e.target===overlay) _close(); });

        overlay.querySelectorAll('.qbp-pick-btn').forEach(function(btn){
            btn.onclick=function(){
                var b=batches[parseInt(this.getAttribute('data-idx'))];
                _close();
                _openQuickTransferForm(rowData, {
                    fid: String(b.bom_ing_fid||''),
                    process_no: procNo,
                    ProcessName: procName,
                    maker_id_no: b.maker_id_no,
                    maker_id: b.maker_id,
                    batch_label: b.batch_label
                });
            };
        });

        overlay.querySelectorAll('.qbp-cancel-btn').forEach(function(btn){
            btn.onclick=function(){
                var b=batches[parseInt(this.getAttribute('data-idx'))];
                var lbl=b.batch_label || '─';
                if (!confirm('確定要取消「'+lbl+'」批次的移轉嗎？\n（此為第一次確認，取消後該批次會回歸前一狀態）')) return;
                _close();
                cancelTransfer(String(b.bom_ing_fid||''), procName+'（批次 '+lbl+'）');
            };
        });
    }

    // ── 移轉日期/廠商表單（單批次或已選定批次後顯示）────────────────────────────
    function _openQuickTransferForm(rowData, target, opts) {
        opts = opts || {};
        const _qtrAction = opts.action || 'transfer_process'; // 'quick_sync_transfer' = QC/生管不同步快速移轉（多做今天回廠+依qc_completed跳P）
        const _qtrNoteHtml = opts.note ? '<p style="font-size:11px;color:#e67e22;margin:6px 0 0;"><i class="fa fa-info-circle"></i> ' + escapeHtml(opts.note) + '</p>' : '';
        const fid=String(target.fid||''), bom=String(rowData.bom||''), did=String(rowData.d_display||rowData.d_id||''), procNo=String(target.process_no||''), procName=String(target.ProcessName||'');
        const today=new Date(), todayStr=today.getFullYear()+'-'+String(today.getMonth()+1).padStart(2,'0')+'-'+String(today.getDate()).padStart(2,'0');
        const defaultMakerNo=String(target.maker_id_no||''), defaultMakerName=String(target.maker_id||'');
        const batchTitle = target.batch_label ? (' <span style="color:#337ab7;">[批次 '+escapeHtml(target.batch_label)+']</span>') : '';
        const modalId='qtr-modal-'+fid; const ex=document.getElementById(modalId); if(ex)ex.remove();
        const overlay=document.createElement('div');
        overlay.id=modalId;
        overlay.style.cssText='position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.45);z-index:10500;display:flex;align-items:center;justify-content:center;';
        var _initDisplay = defaultMakerNo ? defaultMakerNo + (defaultMakerName ? ' — ' + defaultMakerName : '') : '';
        overlay.innerHTML=`<div style="background:#fff;border-radius:6px;box-shadow:0 6px 32px rgba(0,0,0,0.22);width:460px;max-width:95vw;">
<div style="padding:12px 16px;border-bottom:1px solid #e0e0e0;display:flex;justify-content:space-between;align-items:center;">
  <strong style="font-size:13px;">${escapeHtml(bom)} / ${escapeHtml(did)} 移轉至 ${escapeHtml(procNo)} ${escapeHtml(procName)}${batchTitle}</strong>
  <button id="qtr-close-${fid}" style="border:none;background:none;font-size:20px;cursor:pointer;color:#aaa;line-height:1;padding:0 4px;">×</button>
</div>
<div style="padding:16px;">
  <div style="display:flex;align-items:center;margin-bottom:12px;">
    <label style="width:60px;flex-shrink:0;font-size:13px;">移轉日：</label>
    <div class="datetest" style="flex:1;"><input type="text" id="qtr-date-${fid}" class="form-control transfer-datepicker" value="${escapeHtml(todayStr)}" placeholder="請選擇日期" style="width:100%;"></div>
  </div>
  <div style="display:flex;align-items:flex-start;margin-bottom:4px;">
    <label style="width:60px;flex-shrink:0;font-size:13px;padding-top:6px;">廠商：</label>
    <div style="flex:1;">
      <input type="text" id="qtr-maker-inp-${fid}" class="form-control" list="qtr-dl-${fid}"
             value="${escapeHtml(_initDisplay)}" placeholder="輸入廠商編號或名稱..." autocomplete="off" style="width:100%;">
      <datalist id="qtr-dl-${fid}"></datalist>
      <div id="qtr-maker-info-${fid}" style="margin-top:6px;min-height:0;"></div>
      <input type="hidden" id="qtr-maker-no-${fid}" value="${escapeHtml(defaultMakerNo)}">
      <input type="hidden" id="qtr-maker-name-${fid}" value="${escapeHtml(defaultMakerName)}">
    </div>
  </div>
  ${_qtrNoteHtml}
</div>
<div style="padding:10px 16px;border-top:1px solid #e0e0e0;display:flex;justify-content:flex-end;gap:8px;">
  <button id="qtr-cancel-${fid}" class="btn btn-default">取消</button>
  <button id="qtr-confirm-${fid}" class="btn btn-primary">確認移轉</button>
</div></div>`;
        document.body.appendChild(overlay);
        try{$('#qtr-date-'+fid).datepicker({format:'yyyy-mm-dd',autoclose:true,language:'zh-TW',todayHighlight:true});}catch(e){}

        // ── 廠商模糊搜尋（datalist 機制，同 vendor-filter）──────────────────
        var _qtrTimer  = null;
        var _qtrCache  = [];   // 最後一次搜尋結果，供 change 事件比對
        var _qtrInp    = document.getElementById('qtr-maker-inp-'+fid);
        var _qtrDl     = document.getElementById('qtr-dl-'+fid);
        var _qtrInfo   = document.getElementById('qtr-maker-info-'+fid);
        var _qtrHidNo  = document.getElementById('qtr-maker-no-'+fid);
        var _qtrHidName= document.getElementById('qtr-maker-name-'+fid);
        var _qtrSelf   = window.location.pathname.split('/').pop() || 'OreadyReply_ForPm_BaseOfTime2.php';

        function _qtrShowInfo(m){
            var chips = '';
            if(m.m_process_items) m.m_process_items.split('、').forEach(function(p){
                if(p.trim()) chips += '<span style="display:inline-block;background:#e8f4fd;color:#1a7abf;border:1px solid #bee3f8;border-radius:8px;padding:0 6px;font-size:10px;font-weight:600;margin:1px 2px 1px 0;white-space:nowrap;">'+escapeHtml(p.trim())+'</span>';
            });
            _qtrInfo.innerHTML = '<div style="background:#f0f7ff;border:1px solid #b3d4f5;border-radius:4px;padding:5px 10px;">'
                +'<code style="font-size:11px;color:#337ab7;">'+escapeHtml(m.maker_id_no||'')+'</code>'
                +' <strong style="font-size:12px;">'+escapeHtml(m.maker_id||'')+'</strong>'
                +(chips ? '<div style="margin-top:4px;">'+chips+'</div>' : '')
                +'</div>';
        }

        function _qtrFill(m){
            _qtrHidNo.value  = m.maker_id_no || '';
            _qtrHidName.value= m.maker_id || '';
            _qtrInp.value    = m.maker_id_no + ' — ' + m.maker_id;
            _qtrShowInfo(m);
        }

        function _qtrSearch(term){
            clearTimeout(_qtrTimer);
            if(!term){ _qtrDl.innerHTML=''; _qtrCache=[]; return; }
            _qtrTimer = setTimeout(function(){
                $.post(_qtrSelf, {action:'search_maker', term:term, search_type:'no'}, function(resp){
                    _qtrCache = (resp && resp.success && resp.data) ? resp.data : [];
                    _qtrDl.innerHTML = '';
                    _qtrCache.forEach(function(m){
                        var opt = document.createElement('option');
                        opt.value = m.maker_id_no + ' — ' + m.maker_id;
                        _qtrDl.appendChild(opt);
                    });
                }, 'json');
            }, 250);
        }

        _qtrInp.addEventListener('input', function(){
            _qtrHidNo.value=''; _qtrHidName.value=''; _qtrInfo.innerHTML='';
            _qtrSearch(this.value.trim());
        });
        _qtrInp.addEventListener('change', function(){
            var val = this.value.trim();
            // 從 cache 找精確對應
            var found = _qtrCache.filter(function(m){ return (m.maker_id_no+' — '+m.maker_id)===val; })[0]
                     || _qtrCache.filter(function(m){ return m.maker_id_no===val||m.maker_id===val; })[0];
            if(found){ _qtrFill(found); }
        });

        // 如有既有廠商，直接顯示資訊
        if(defaultMakerNo && defaultMakerName) _qtrShowInfo({maker_id_no:defaultMakerNo, maker_id:defaultMakerName, m_process_items:''});
        function _closeQtr(){const m=document.getElementById(modalId);if(m)m.remove();}
        document.getElementById('qtr-close-'+fid).onclick=_closeQtr;
        document.getElementById('qtr-cancel-'+fid).onclick=_closeQtr;
        overlay.addEventListener('click',function(e){if(e.target===overlay)_closeQtr();});
        document.getElementById('qtr-confirm-'+fid).onclick=function(){
            const $btn=$(this), transDate=$('#qtr-date-'+fid).val().trim(), makerNo=$('#qtr-maker-no-'+fid).val().trim(), makerName=$('#qtr-maker-name-'+fid).val().trim();
            if(!transDate){alert('請選擇移轉日期。');return;}
            if(!makerNo||!makerName){alert('請輸入並選擇有效的廠商。');return;}
            $btn.prop('disabled',true).text('處理中...');
            $.ajax({url:_phpSelf,type:'POST',data:{action:_qtrAction,bom_ing_fid:fid,transfer_date:transDate,maker_no:makerNo,maker_name:makerName},dataType:'json',
                success:function(response){
                    if(response.success){
                        showTemporaryMessage(response.message,true); _closeQtr();
                        var _newDate = transDate.replace(/-/g,'/');
                        if(_qtrAction==='quick_sync_transfer'){
                            // 快速同步移轉：目標關直接進 Q/P（伺服器回傳 new_state），更新 bomPSList 讓按鈕即刻消失、目前製程前進
                            var _ns = response.new_state || 'Q';
                            if(Array.isArray(window.bomPSList))window.bomPSList.forEach(function(p){
                                if(String(p.bom_ing_fid||'')===String(fid)){
                                    p.processing_state=_ns;p.outsource_date=_newDate;p.maker_id=makerName;p.maker_id_no=makerNo;
                                }
                            });
                            delete _rowDetailCache[bom];
                        } else {
                        // 樂觀更新 fullDataset
                        if(Array.isArray(fullDataset))fullDataset.forEach(function(item){
                            if(item&&item.bom_ing_fid&&String(item.bom_ing_fid).split(',').some(function(id){return String(id).trim()===fid;})){
                                item.processing_state='ing';item.outsource_date=_newDate;item.maker_id=makerName;delete _rowDetailCache[item.bom];
                            }
                        });
                        // 樂觀更新 window.ingActiveMap（發單日欄位讀取此資料）
                        if(window.ingActiveMap&&window.ingActiveMap[bom]&&Array.isArray(window.ingActiveMap[bom])){
                            window.ingActiveMap[bom].forEach(function(p){
                                if(String(p.bom_ing_fid||'')===String(fid)){
                                    p.processing_state='ing';p.outsource_date=_newDate;p.maker_id=makerName;
                                }
                            });
                        }
                        }
                        processAndRenderData();
                        // 清除所有 focus/update flag 確保 fetchDataAndFilter 不被跳過，再背景刷新
                        isSelectFocused = false; isTextareaFocused = false;
                        isUpdatingOrderId = false; isPriorityUpdating = false;
                        fetchDataAndFilter();
                    } else { alert('移轉失敗：'+(response.message||'未知錯誤')); $btn.prop('disabled',false).text('確認移轉'); }
                },
                error:function(){alert('與伺服器通訊失敗。');$btn.prop('disabled',false).text('確認移轉');}
            });
        };
    }
    function cancelTransfer(fid, procName, onSuccess) {
        if (!confirm('確認回歸前一狀態「' + (procName||'') + '」？')) return;
        var xhr = new XMLHttpRequest();
        xhr.open('POST', _phpSelf, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onreadystatechange = function() {
            if (xhr.readyState !== 4) return;
            if (xhr.status === 200) {
                try {
                    var resp = JSON.parse(xhr.responseText);
                    if (resp.no_action) {
                        alert('目前已是最初狀態(N)，無反應。');
                        return;
                    }
                    if (resp.qc_state) {
                        alert('本狀態由QC回報，不可手動回歸。');
                        return;
                    }
                    if (resp.success) {
                        showTemporaryMessage(resp.message || '已回歸前一狀態', true);
                        var fidStr = String(fid);
                        var newState = resp.new_state;
                        // 更新 bomPSList
                        if (Array.isArray(window.bomPSList)) {
                            window.bomPSList.forEach(function(p) {
                                if (String(p.bom_ing_fid).trim() === fidStr) {
                                    p.processing_state = newState;
                                    if (newState === 'N') {
                                        p.outsource_date = null;
                                        p.maker_id = '';
                                        p.maker_id_no = null;
                                    } else if (newState === 'ing') {
                                        p.return_date = null;
                                    }
                                }
                            });
                        }
                        // 更新 fullDataset
                        if (Array.isArray(fullDataset)) {
                            fullDataset.forEach(function(item) {
                                if (!item) return;
                                var fids = String(item.bom_ing_fid||'').split(',').map(function(f){ return f.trim(); });
                                if (fids.indexOf(fidStr) !== -1) {
                                    var stillHigher = (window.bomPSList||[]).some(function(p){
                                        return String(p.bom).trim() === String(item.bom||'').trim()
                                            && p.processing_state === 'ing';
                                    });
                                    if (!stillHigher) {
                                        item.processing_state = newState;
                                        if (newState === 'N') {
                                            item.outsource_date = '';
                                            item.maker_id = '';
                                        } else if (newState === 'ing') {
                                            item.return_date = '';
                                        }
                                    }
                                }
                            });
                        }
                        processAndRenderData();
                        if (typeof onSuccess === 'function') onSuccess();
                        fetchDataAndFilter();
                    } else { alert('操作失敗：' + (resp.message||'未知錯誤')); }
                } catch(e) { alert('回應解析錯誤'); }
            } else { alert('伺服器通訊失敗（HTTP ' + xhr.status + '）'); }
        };
        xhr.send('action=cancel_transfer&bom_ing_fid=' + encodeURIComponent(fid));
    }

    // ── 建立製程列表項目（共用）────────────────────────────────────────────
    function _buildProcItemDiv(proc, rowData, showTransfer) {
        var div = document.createElement('div');
        div.className = 'form-group row';
        div.style.cssText = 'margin-bottom:4px;display:flex;align-items:center;';

        var isIng = (proc.processing_state === 'ing');

        // 按鈕欄
        var btnCol = document.createElement('div');
        btnCol.className = 'col-md-2 col-sm-2 col-xs-3 text-right';
        btnCol.style.cssText = 'display:flex;gap:3px;justify-content:flex-end;';

        if (showTransfer) {

                var transferBtn = document.createElement('button');
                transferBtn.type = 'button';
                transferBtn.className = 'btn btn-warning btn-xs';
                transferBtn.setAttribute('data-toggle', 'modal');
                transferBtn.setAttribute('data-target', '#transferProcessModal_' + proc.bom_ing_fid);
                transferBtn.title = '移轉此製程';
                transferBtn.textContent = '移';
                btnCol.appendChild(transferBtn);

        }
        div.appendChild(btnCol);

        // SN
        var snCol = document.createElement('div');
        snCol.className = 'col-md-1 col-sm-1 col-xs-2';
        snCol.style.cssText = 'padding-top:5px;font-weight:bold;font-size:12px;';
        snCol.textContent = proc.bom_sn || '';
        div.appendChild(snCol);

        // 製程代號
        var pnoCol = document.createElement('div');
        pnoCol.className = 'col-md-1 col-sm-1 col-xs-2';
        pnoCol.style.cssText = 'padding-top:5px;font-size:12px;';
        pnoCol.textContent = proc.process_no || '';
        div.appendChild(pnoCol);

        // 製程中文 + 廠商
        var nameCol = document.createElement('div');
        nameCol.className = 'col-md-5 col-sm-5 col-xs-4';
        nameCol.style.cssText = 'padding-top:5px;font-size:12px;';
        nameCol.textContent = proc.ProcessName || '';
        if (isIng && proc.maker_id) {
            var ms = document.createElement('small');
            ms.style.cssText = 'color:orange;margin-left:4px;';
            ms.textContent = proc.maker_id;
            nameCol.appendChild(ms);
        }
        div.appendChild(nameCol);

        // 刪除
        if (window.canDelete && window.displayPermissionCode !== 'D+R') {
            var delCol = document.createElement('div');
            delCol.className = 'col-md-1 col-sm-1 col-xs-1 text-right';
            var delBtn = document.createElement('button');
            delBtn.type = 'button'; delBtn.className = 'btn btn-danger btn-xs';
            delBtn.title = '刪除'; delBtn.textContent = 'X';
            (function(f, bom, bsn, pn) {
                delBtn.onclick = function(){ confirmDeleteBomIng(f, bom, bsn, pn); };
            })(proc.bom_ing_fid, rowData.bom, rowData.bom_sn, proc.ProcessName||'');
            delCol.appendChild(delBtn);
            div.appendChild(delCol);
        }

        if (String(proc.bom_sn) === String(rowData.bom_sn)) {
            div.style.backgroundColor = '#e6f2ff';
            div.title = '目前製程';
        }
        return div;
    }

    // ── 新增 BOM Modal ───────────────────────────────────────────────────────
    function openCreateBomModal() {
        // 權限檢查：只有 A 或 C+R 才可新增BOM
        if (window.displayPermissionCode !== 'A' && window.displayPermissionCode !== 'C+R') {
            alert('您沒有新增 BOM 的權限。');
            return;
        }
        // 動態取得當前 PHP 檔名，確保 AJAX POST 打到正確的 handler
        var _selfUrl = window.location.pathname.split('/').pop() || 'OreadyReply_ForPm_BaseOfTime2.php';
        var ex = document.getElementById('create-bom-modal');
        if (ex) ex.remove();

        var overlay = document.createElement('div');
        overlay.id = 'create-bom-modal';
        overlay.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);display:flex;justify-content:center;align-items:flex-start;z-index:10060;padding:30px 0;overflow-y:auto;';
        overlay.onclick = function(e){ if(e.target===overlay) overlay.remove(); };

        var box = document.createElement('div');
        box.style.cssText = 'background:#fff;padding:24px;border-radius:6px;box-shadow:0 4px 20px rgba(0,0,0,0.3);width:960px;max-width:98%;margin:0 auto;';
        box.onclick = function(e){ e.stopPropagation(); };

        // Header
        var hdr = document.createElement('div');
        hdr.style.cssText = 'display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;padding-bottom:10px;border-bottom:2px solid #337ab7;';
        var ttl = document.createElement('h4');
        ttl.textContent = '新增 BOM'; ttl.style.cssText = 'margin:0;color:#337ab7;';
        var xBtn = document.createElement('button');
        xBtn.innerHTML = '&times;'; xBtn.style.cssText = 'background:none;border:none;font-size:1.6rem;cursor:pointer;color:#aaa;line-height:1;';
        xBtn.onclick = function(){ overlay.remove(); };
        hdr.appendChild(ttl); hdr.appendChild(xBtn);
        box.appendChild(hdr);

        function _row(label, el, required) {
            var g = document.createElement('div');
            g.style.cssText = 'display:flex;align-items:flex-start;margin-bottom:11px;gap:10px;';
            var lbl = document.createElement('label');
            lbl.style.cssText = 'min-width:90px;padding-top:7px;font-size:13px;font-weight:bold;color:#555;text-align:right;';
            lbl.innerHTML = label + (required ? ' <span style="color:red">*</span>' : '');
            var w = document.createElement('div'); w.style.flex = '1';
            w.appendChild(el);
            g.appendChild(lbl); g.appendChild(w);
            return g;
        }
        function _inp(id, ph, type) {
            var i = document.createElement('input');
            i.type = type||'text'; i.id = id; i.className = 'form-control';
            i.placeholder = ph||''; i.style.fontSize = '13px';
            return i;
        }
        function _note(txt) {
            var s = document.createElement('small'); s.style.color = '#999'; s.textContent = txt; return s;
        }

        // BOM號碼
        var inBom = _inp('cb-bom','例：B-1140528001');
        var bomErr = document.createElement('small');
        bomErr.style.cssText = 'color:red;display:none;margin-left:6px;';
        var bomWrap = document.createElement('div');
        bomWrap.appendChild(inBom); bomWrap.appendChild(bomErr);
        bomWrap.appendChild(document.createElement('br'));
        bomWrap.appendChild(_note('格式：B-民國年三碼MMDD流水號三碼（共14碼）'));
        box.appendChild(_row('BOM號碼', bomWrap, true));
        // BOM 號碼驗證函數（可共用於 input 與送出）
        function _validateBomNumber(val) {
            // 基本格式：B- + 10位數字
            if (!/^B-[0-9]{10}$/.test(val)) return { ok: false, warn: false, msg: '格式錯誤，應為 B-民國年三碼MMDD流水號三碼（共14碼）' };
            var body = val.substring(2); // 去掉 "B-"
            var yy = parseInt(body.substring(0, 3), 10);   // 民國年 3碼
            var mm = parseInt(body.substring(3, 5), 10);   // 月 2碼
            var dd = parseInt(body.substring(5, 7), 10);   // 日 2碼
            // 月份合法性
            if (mm < 1 || mm > 12) return { ok: false, warn: false, msg: '月份錯誤（01~12）' };
            // 日期合法性：用 JS Date 物件驗證（例如 2/30 會溢位成 3/2，可偵測到）
            var adYear = yy + 1911;
            var testDate = new Date(adYear, mm - 1, dd); // month 是 0-based
            if (testDate.getMonth() !== mm - 1 || testDate.getDate() !== dd) {
                return { ok: false, warn: false, msg: mm + '月沒有 ' + dd + ' 號，日期不存在' };
            }
            // 民國年上限：今年西元年 - 1911 + 1（允許多一年，例如年底建立明年BOM）
            var nowAD = new Date().getFullYear();
            var maxROC = (nowAD - 1911) + 1;  // e.g. 2026 → 116
            if (yy > maxROC) return { ok: false, warn: false, msg: '民國年超過合理範圍（最大允許 ' + maxROC + '）' };
            // 判斷是否「日期超過今天」→ 警告但不擋
            var today = new Date(); today.setHours(0,0,0,0);
            testDate.setHours(0,0,0,0);
            if (testDate > today) return { ok: true, warn: true, msg: '注意：BOM 日期超過今天（' + yy + '年' + mm + '月' + dd + '日），請確認是否填寫正確' };
            return { ok: true, warn: false, msg: '' };
        }

        inBom.addEventListener('input', function(){
            inBom.value = inBom.value.toUpperCase();
            var v = inBom.value;
            if (!v) { bomErr.style.display = 'none'; return; }
            var res = _validateBomNumber(v);
            if (!res.ok) {
                bomErr.style.display = 'inline';
                bomErr.style.color = 'red';
                bomErr.textContent = res.msg;
            } else if (res.warn) {
                bomErr.style.display = 'inline';
                bomErr.style.color = '#e67e00';
                bomErr.textContent = '⚠ ' + res.msg;
            } else {
                bomErr.style.display = 'none';
            }
        });

        // 料號（搜尋下拉）
        var inDid = _inp('cb-did','輸入料號搜尋...');
        var _selectedDidId = '';   // 暫存已選中的 d_id（區別顯示文字與實際 ID）
        var didDrop = document.createElement('div');
        didDrop.style.cssText = 'border:1px solid #ccc;border-radius:3px;max-height:160px;overflow-y:auto;display:none;position:absolute;background:#fff;z-index:9999;min-width:320px;box-shadow:0 2px 6px rgba(0,0,0,0.15);font-size:13px;';
        var didWrap = document.createElement('div');
        didWrap.style.position = 'relative';
        didWrap.appendChild(inDid); didWrap.appendChild(didDrop);
        didWrap.appendChild(document.createElement('br'));
        didWrap.appendChild(_note('輸入後從下拉選擇，選擇後自動帶入客戶與訂單'));
        box.appendChild(_row('料號', didWrap, true));

        // 客戶（唯讀）
        var inClient = _inp('cb-client','選擇料號後自動帶入');
        inClient.readOnly = true; inClient.style.background = '#f5f5f5';
        box.appendChild(_row('客戶', inClient, false));

        // 數量
        var inQty = _inp('cb-qty','生產數量','number');
        inQty.min = '1';
        box.appendChild(_row('數量', inQty, true));

        // 訂單（料號選定後顯示）
        var orderArea = document.createElement('div');
        orderArea.style.display = 'none';
        var orderTblWrap = document.createElement('div');
        orderTblWrap.style.cssText = 'overflow-x:auto;width:100%;';
        var orderTbl = document.createElement('table');
        orderTbl.className = 'table table-condensed table-bordered';
        orderTbl.style.cssText = 'font-size:12px;margin-bottom:4px;white-space:nowrap;min-width:720px;';
        orderTbl.innerHTML = '<thead><tr style="background:#f0f0f0"><th style="width:24px"></th><th>訂單編號</th><th style="min-width:55px">數量</th><th style="min-width:55px">未交</th><th>交期</th><th>製程</th><th>備註</th><th style="min-width:120px">對應PCS <span style="color:#e67e00;font-size:10px">（可綁定餘量）</span></th></tr></thead><tbody id="cb-order-tbody"></tbody>';
        orderTblWrap.appendChild(orderTbl);
        orderArea.appendChild(orderTblWrap);
        orderArea.appendChild(_note('可勾選多個訂單（1對多）；勾選後自動填入可綁定數量；不勾選表示備庫'));
        box.appendChild(_row('對應訂單', orderArea, false));

        // BOM 備註
        var inBomPs = _inp('cb-bom-ps','BOM備註（選填）');
        box.appendChild(_row('BOM備註', inBomPs, false));

        // 製程
        var procSec = document.createElement('div');
        procSec.style.cssText = 'border:1px solid #ddd;border-radius:4px;padding:10px 12px;';
        var procRows = document.createElement('div');
        procRows.id = 'cb-proc-rows';
        procSec.appendChild(procRows);

        var procBtnsDiv = document.createElement('div');
        procBtnsDiv.style.cssText = 'display:flex;gap:6px;margin-top:6px;';
        var addPBtn = document.createElement('button');
        addPBtn.type='button'; addPBtn.className='btn btn-default btn-xs';
        addPBtn.innerHTML='<i class="fa fa-plus"></i> 新增製程';
        addPBtn.onclick=function(){ _addProcRow(); setTimeout(function(){ procRows.lastChild.querySelector('.pcInput').focus(); },50); };
        var copyProcBtn = document.createElement('button');
        copyProcBtn.type='button'; copyProcBtn.className='btn btn-info btn-xs';
        copyProcBtn.innerHTML='<i class="fa fa-copy"></i> 從其他BOM複製製程';
        copyProcBtn.onclick = _openCopyProcModal;
        procBtnsDiv.appendChild(addPBtn); procBtnsDiv.appendChild(copyProcBtn);
        procSec.appendChild(procBtnsDiv);
        var procWrap = document.createElement('div');
        procWrap.appendChild(procSec);
        procWrap.appendChild(document.createElement('br'));
        procWrap.appendChild(_note('製程欄位：Tab鍵跳至廠商→備註→下一行製程；↓箭頭新增下一行；X按鈕不佔Tab焦點；拖拉左側≡圖示可調整順序'));
        box.appendChild(_row('製程順序', procWrap, true));

        // Footer
        var ftr = document.createElement('div');
        ftr.style.cssText = 'display:flex;justify-content:flex-end;gap:10px;margin-top:18px;padding-top:12px;border-top:1px solid #eee;';
        var cancelBtn2 = document.createElement('button');
        cancelBtn2.type='button'; cancelBtn2.className='btn btn-default';
        cancelBtn2.textContent='取消'; cancelBtn2.onclick=function(){ overlay.remove(); };
        var confirmBtn = document.createElement('button');
        confirmBtn.type='button'; confirmBtn.className='btn btn-primary';
        confirmBtn.textContent='確認新增';
        ftr.appendChild(cancelBtn2); ftr.appendChild(confirmBtn);
        box.appendChild(ftr);
        overlay.appendChild(box);
        document.body.appendChild(overlay);

        // ── 製程行邏輯（含廠商/備註/Tab跳過X/↓新增/拖拉排序）─────────────
        function _reindexProcRows(){
            Array.from(procRows.children).forEach(function(child,idx){
                var l=child.querySelector('.pcSN'); if(l) l.textContent='SN'+((idx+1)*10);
            });
        }
        function _addProcRow(prefill){
            prefill=prefill||{};
            var r=document.createElement('div');
            r.setAttribute('draggable','true');
            r.style.cssText='display:flex;align-items:center;gap:4px;margin-bottom:4px;padding:3px 5px;border:1px solid #eee;border-radius:3px;background:#fff;';
            r.addEventListener('dragstart',function(e){ e.dataTransfer.effectAllowed='move'; r.style.opacity='0.5'; procRows._drag=r; });
            r.addEventListener('dragend',function(){ r.style.opacity='1'; procRows._drag=null; _reindexProcRows(); });
            r.addEventListener('dragover',function(e){ e.preventDefault(); var d=procRows._drag; if(d&&d!==r){ procRows.insertBefore(d, r.getBoundingClientRect().top+r.offsetHeight/2<e.clientY?r.nextSibling:r); } });

            var snLbl=document.createElement('span'); snLbl.className='pcSN';
            snLbl.style.cssText='min-width:40px;font-size:11px;color:#888;font-weight:bold;cursor:grab;user-select:none;';
            snLbl.innerHTML='<i class="fa fa-bars" style="color:#ccc;margin-right:2px;"></i>SN'+(( procRows.children.length+1)*10);
            r.appendChild(snLbl);

            // 製程輸入
            var pIn=document.createElement('input'); pIn.type='text'; pIn.className='form-control input-sm pcInput';
            pIn.style.cssText='width:155px;font-size:12px;'; pIn.placeholder='製程代號或名稱'; pIn.dataset.pno=prefill.pno||'';
            if(prefill.pno) pIn.value=(prefill.pno||'')+(prefill.pname?' '+prefill.pname:'');
            var pdrop=document.createElement('div');
            pdrop.style.cssText='border:1px solid #ccc;border-radius:3px;max-height:150px;overflow-y:auto;display:none;position:absolute;top:100%;left:0;background:#fff;z-index:9999;min-width:220px;box-shadow:0 2px 6px rgba(0,0,0,.15);font-size:12px;';
            var pWrap=document.createElement('div'); pWrap.style.cssText='position:relative;';
            pWrap.appendChild(pIn); pWrap.appendChild(pdrop); r.appendChild(pWrap);
            var _pi=-1,_pt=null;
            function _pHL(i){ var it=Array.from(pdrop.querySelectorAll('.pd-opt')); it.forEach(function(e,j){ e.style.background=(j===i)?'#d0e4ff':''; }); _pi=i; }
            pIn.addEventListener('keydown',function(e){
                var it=Array.from(pdrop.querySelectorAll('.pd-opt'));
                if(pdrop.style.display!=='none'&&it.length){
                    if(e.key==='ArrowDown'){ e.preventDefault(); _pHL(Math.min(_pi+1,it.length-1)); return; }
                    if(e.key==='ArrowUp'){ e.preventDefault(); _pHL(Math.max(_pi-1,0)); return; }
                    if(e.key==='Enter'){ e.preventDefault(); if(_pi>=0) it[_pi].click(); return; }
                    if(e.key==='Escape'){ pdrop.style.display='none'; _pi=-1; return; }
                }
                if(e.key==='Tab'&&!e.shiftKey){ e.preventDefault(); makerIn.focus(); return; }
                if(e.key==='ArrowDown'&&pdrop.style.display==='none'){
                    e.preventDefault();
                    var rows=Array.from(procRows.children),ci=rows.indexOf(r);
                    if(ci<rows.length-1){ rows[ci+1].querySelector('.pcInput').focus(); }
                    else{ _addProcRow(); setTimeout(function(){ procRows.lastChild.querySelector('.pcInput').focus(); },50); }
                }
                // ArrowUp on empty製程 input → delete this row and focus previous
                if(e.key==='ArrowUp'&&pdrop.style.display==='none'&&!pIn.value.trim()&&!pIn.dataset.pno){
                    e.preventDefault();
                    var rows=Array.from(procRows.children),ci=rows.indexOf(r);
                    if(rows.length>1){ // 至少保留一行
                        r.remove(); _reindexProcRows();
                        var newRows=Array.from(procRows.children);
                        var focusIdx=Math.min(ci,newRows.length-1);
                        if(newRows[focusIdx]) newRows[focusIdx].querySelector('.pcInput').focus();
                    }
                }
            });
            pIn.addEventListener('input',function(){
                pIn.dataset.pno=''; _pi=-1; clearTimeout(_pt);
                var t=pIn.value.trim(); if(!t){ pdrop.style.display='none'; return; }
                _pt=setTimeout(function(){
                    $.post(_selfUrl,{action:'search_process',term:t},function(resp){
                        pdrop.innerHTML=''; _pi=-1;
                        if(!resp.success||!resp.processes||!resp.processes.length){
                            var n=document.createElement('div'); n.style.cssText='padding:7px 10px;color:#999;font-size:12px;'; n.textContent='查無製程'; pdrop.appendChild(n); pdrop.style.display='block'; return;
                        }
                        resp.processes.forEach(function(p){
                            var o=document.createElement('div'); o.className='pd-opt';
                            o.style.cssText='padding:5px 10px;cursor:pointer;border-bottom:1px solid #f0f0f0;';
                            o.innerHTML='<span style="font-weight:bold;color:#337ab7;">'+p.ProcessNo+'</span> - '+(p.ProcessName||'');
                            o.onmouseover=function(){ _pHL(Array.from(pdrop.querySelectorAll('.pd-opt')).indexOf(o)); };
                            o.onclick=function(){ pIn.value=p.ProcessNo+' '+(p.ProcessName||''); pIn.dataset.pno=String(p.ProcessNo); pdrop.style.display='none'; _pi=-1; makerIn.focus(); };
                            pdrop.appendChild(o);
                        }); pdrop.style.display='block';
                    },'json');
                },250);
            });
            pIn.addEventListener('blur',function(){
                setTimeout(function(){
                    pdrop.style.display='none'; _pi=-1;
                    // 再等 500ms，讓 AJAX 有機會回來設定 pno，再判斷是否顯示警告
                    setTimeout(function(){
                        var pWarnEl=pWrap.querySelector('.p-invalid-warn'); if(pWarnEl) pWarnEl.remove();
                        var val=pIn.value.trim();
                        if(val && !pIn.dataset.pno){
                            pIn.style.borderColor='#dc3545';
                            var warn=document.createElement('div'); warn.className='p-invalid-warn';
                            warn.style.cssText='color:#dc3545;font-size:10px;position:absolute;left:0;top:100%;z-index:9990;background:#fff;border:1px solid #dc3545;border-radius:3px;padding:2px 6px;white-space:nowrap;';
                            warn.textContent='⚠ 查無此製程，請從清單選取';
                            pWrap.appendChild(warn);
                        } else {
                            pIn.style.borderColor='';
                        }
                    }, 500);
                },200);
            });

            // 廠商輸入
            var makerIn=document.createElement('input'); makerIn.type='text'; makerIn.className='form-control input-sm pcMaker';
            makerIn.style.cssText='width:135px;font-size:12px;'; makerIn.placeholder='廠商(選填)';
            makerIn.dataset.makerNo=prefill.maker_id_no||''; makerIn.dataset.makerId=prefill.maker_id||'';
            if(prefill.maker_id) makerIn.value=(prefill.maker_id_no||'')+' '+(prefill.maker_id||'');
            var mdrop=document.createElement('div');
            mdrop.style.cssText='border:1px solid #ccc;border-radius:3px;max-height:200px;overflow-y:auto;display:none;position:absolute;top:100%;left:0;background:#fff;z-index:9999;min-width:340px;box-shadow:0 2px 6px rgba(0,0,0,.15);font-size:11px;';
            var mWrap=document.createElement('div'); mWrap.style.cssText='position:relative;';
            mWrap.appendChild(makerIn); mWrap.appendChild(mdrop); r.appendChild(mWrap);
            var _mi=-1,_mt=null;
            function _mHL(i){ var it=Array.from(mdrop.querySelectorAll('.md-opt')); it.forEach(function(e,j){ e.style.background=(j===i)?'#d0e4ff':''; }); _mi=i; }
            makerIn.addEventListener('keydown',function(e){
                var it=Array.from(mdrop.querySelectorAll('.md-opt'));
                if(mdrop.style.display!=='none'&&it.length){
                    if(e.key==='ArrowDown'){ e.preventDefault(); _mHL(Math.min(_mi+1,it.length-1)); return; }
                    if(e.key==='ArrowUp'){ e.preventDefault(); _mHL(Math.max(_mi-1,0)); return; }
                    if(e.key==='Enter'){ e.preventDefault(); if(_mi>=0) it[_mi].click(); return; }
                    if(e.key==='Escape'){ mdrop.style.display='none'; _mi=-1; return; }
                }
                if(e.key==='Tab'&&!e.shiftKey){ e.preventDefault(); psIn.focus(); return; }
                // ArrowDown（下拉關閉）→ 跳至下一列製程
                if(e.key==='ArrowDown'&&mdrop.style.display==='none'){
                    e.preventDefault();
                    var rows=Array.from(procRows.children),ci=rows.indexOf(r);
                    if(ci<rows.length-1){ rows[ci+1].querySelector('.pcInput').focus(); }
                    else{ _addProcRow(); setTimeout(function(){ procRows.lastChild.querySelector('.pcInput').focus(); },50); }
                }
                // ArrowUp（下拉關閉且廠商空）→ 刪此列並跳上一列
                if(e.key==='ArrowUp'&&mdrop.style.display==='none'){
                    e.preventDefault();
                    var rows=Array.from(procRows.children),ci=rows.indexOf(r);
                    if(!makerIn.value.trim()&&rows.length>1){
                        r.remove(); _reindexProcRows();
                        var nr=Array.from(procRows.children); var fi=Math.min(ci,nr.length-1);
                        if(nr[fi]) nr[fi].querySelector('.pcInput').focus();
                    } else if(ci>0){ rows[ci-1].querySelector('.pcInput').focus(); }
                }
            });
            makerIn.addEventListener('input',function(){
                makerIn.dataset.makerNo=''; makerIn.dataset.makerId=''; _mi=-1; clearTimeout(_mt);
                var t=makerIn.value.trim(); if(!t){ mdrop.style.display='none'; return; }
                _mt=setTimeout(function(){
                    $.post(_selfUrl,{action:'search_maker_for_bom',term:t},function(resp){
                        mdrop.innerHTML=''; _mi=-1;
                        if(!resp.success||!resp.makers||!resp.makers.length){
                            var n=document.createElement('div'); n.style.cssText='padding:6px 10px;color:#999;'; n.textContent='查無廠商'; mdrop.appendChild(n); mdrop.style.display='block'; return;
                        }
                        resp.makers.forEach(function(m){
                            var o=document.createElement('div'); o.className='md-opt';
                            o.style.cssText='padding:5px 8px;cursor:pointer;border-bottom:1px solid #f0f0f0;line-height:1.4;';
                            o.innerHTML='<div><strong style="color:#337ab7;">'+escapeHtml(m.maker_id_no)+'</strong> '+escapeHtml(m.maker_id||'')+'</div>'+
                                '<div style="color:#888;font-size:10px;">'+(m.m_category||'')+(m.m_process_items?' | '+m.m_process_items:'')+'</div>'+
                                (m.factory_address?'<div style="color:#aaa;font-size:10px;">'+escapeHtml(m.factory_address)+'</div>':'');
                            o.onmouseover=function(){ _mHL(Array.from(mdrop.querySelectorAll('.md-opt')).indexOf(o)); };
                            o.onclick=function(){ makerIn.value=m.maker_id_no+' '+(m.maker_id||''); makerIn.dataset.makerNo=m.maker_id_no; makerIn.dataset.makerId=m.maker_id||''; mdrop.style.display='none'; _mi=-1; psIn.focus(); };
                            mdrop.appendChild(o);
                        }); mdrop.style.display='block';
                    },'json');
                },250);
            });
            makerIn.addEventListener('blur',function(){ setTimeout(function(){ mdrop.style.display='none'; _mi=-1; },200); });

            // 備註（加寬兩倍 + 上下鍵跳列）
            var psIn=document.createElement('input'); psIn.type='text'; psIn.className='form-control input-sm pcPs';
            psIn.style.cssText='width:320px;font-size:12px;'; psIn.placeholder='備註(選填)'; psIn.value=prefill.ps||'';
            psIn.addEventListener('keydown',function(e){
                if(e.key==='Tab'&&!e.shiftKey){
                    e.preventDefault();
                    var rows=Array.from(procRows.children),ci=rows.indexOf(r);
                    if(ci<rows.length-1){ rows[ci+1].querySelector('.pcInput').focus(); }
                    else{ _addProcRow(); setTimeout(function(){ procRows.lastChild.querySelector('.pcInput').focus(); },50); }
                    return;
                }
                // ArrowDown → 跳至下一列製程
                if(e.key==='ArrowDown'){
                    e.preventDefault();
                    var rows=Array.from(procRows.children),ci=rows.indexOf(r);
                    if(ci<rows.length-1){ rows[ci+1].querySelector('.pcInput').focus(); }
                    else{ _addProcRow(); setTimeout(function(){ procRows.lastChild.querySelector('.pcInput').focus(); },50); }
                }
                // ArrowUp（備註空）→ 刪此列並跳上一列；否則跳上一列製程
                if(e.key==='ArrowUp'){
                    e.preventDefault();
                    var rows=Array.from(procRows.children),ci=rows.indexOf(r);
                    if(!psIn.value.trim()&&rows.length>1){
                        r.remove(); _reindexProcRows();
                        var nr=Array.from(procRows.children); var fi=Math.min(ci,nr.length-1);
                        if(nr[fi]) nr[fi].querySelector('.pcInput').focus();
                    } else if(ci>0){ rows[ci-1].querySelector('.pcInput').focus(); }
                }
            });
            r.appendChild(psIn);

            // X 按鈕（tabIndex=-1 不佔Tab焦點）
            var delBtn=document.createElement('button'); delBtn.type='button'; delBtn.className='btn btn-danger btn-xs';
            delBtn.innerHTML='<i class="fa fa-times"></i>'; delBtn.tabIndex=-1; delBtn.title='刪除';
            delBtn.onclick=function(){ r.remove(); _reindexProcRows(); };
            r.appendChild(delBtn);
            procRows.appendChild(r);
        }
        _addProcRow();

        // ── 複製製程彈窗 ─────────────────────────────────────────────────────
        function _openCopyProcModal(){
            var existPop=document.getElementById('copy-proc-popup'); if(existPop) existPop.remove();
            var pop=document.createElement('div'); pop.id='copy-proc-popup';
            pop.style.cssText='position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;border:2px solid #337ab7;border-radius:6px;padding:16px;z-index:10095;min-width:380px;max-width:540px;box-shadow:0 4px 20px rgba(0,0,0,.3);';
            pop.innerHTML='<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;"><strong>從其他BOM複製製程</strong><button type="button" onclick="document.getElementById(\'copy-proc-popup\').remove();" style="background:none;border:none;font-size:1.3rem;cursor:pointer;">&times;</button></div>'+
                '<input type="text" id="copy-proc-input" class="form-control" placeholder="輸入BOM號碼或料號搜尋" style="margin-bottom:8px;">'+
                '<div id="copy-proc-results" style="max-height:260px;overflow-y:auto;border:1px solid #eee;border-radius:3px;"></div>'+
                '<div style="margin-top:8px;font-size:11px;color:#888;">複製時會帶入製程、廠商、備註，不帶入料號/BOM/BOM備註</div>';
            document.body.appendChild(pop);
            var inp=document.getElementById('copy-proc-input');
            var res=document.getElementById('copy-proc-results');
            var _ct=null;
            inp.addEventListener('input',function(){
                clearTimeout(_ct); var t=inp.value.trim();
                if(!t){ res.innerHTML=''; return; }
                res.innerHTML='<div style="padding:8px;color:#999;font-size:12px;"><i class="fa fa-spinner fa-spin"></i> 搜尋中...</div>';
                _ct=setTimeout(function(){
                    $.post(_selfUrl,{action:'copy_bom_processes',term:t},function(resp){
                        res.innerHTML='';
                        if(!resp.success||!resp.results||!resp.results.length){
                            var dbg = resp.debug_count !== undefined ? ' (搜尋詞: '+resp.debug_term+', 筆數:'+resp.debug_count+')' : '';
                            res.innerHTML='<div style="padding:8px;color:#999;font-size:12px;">查無結果'+dbg+'</div>';
                            if(resp.message) res.innerHTML += '<div style="padding:4px 8px;color:red;font-size:11px;">'+escapeHtml(resp.message)+'</div>';
                            return;
                        }
                        resp.results.forEach(function(bItem){
                            var sec=document.createElement('div');
                            sec.style.cssText='border-bottom:1px solid #f0f0f0;padding:6px 10px;';
                            var hdr=document.createElement('div');
                            hdr.style.cssText='font-size:12px;font-weight:bold;color:#337ab7;margin-bottom:4px;';
                            hdr.textContent='BOM: '+bItem.bom+' (料號: '+bItem.d_id+')';
                            sec.appendChild(hdr);
                            if(!bItem.processes||!bItem.processes.length){ var np=document.createElement('div'); np.style.cssText='color:#999;font-size:11px;'; np.textContent='無製程資料'; sec.appendChild(np); }
                            else{
                                bItem.processes.forEach(function(p){
                                    var row=document.createElement('div');
                                    row.style.cssText='font-size:11px;color:#555;margin-left:8px;';
                                    row.textContent='SN'+p.bom_sn+' '+p.process_no+' '+(p.ProcessName||'')+(p.maker_id?' ['+p.maker_id+']':'')+(p.ps?' ('+p.ps+')':'');
                                    sec.appendChild(row);
                                });
                                var applyBtn=document.createElement('button');
                                applyBtn.type='button'; applyBtn.className='btn btn-success btn-xs';
                                applyBtn.style.cssText='margin-top:5px;';
                                applyBtn.textContent='套用此BOM製程';
                                (function(procs){ applyBtn.onclick=function(){
                                    // 清空現有製程行
                                    procRows.innerHTML='';
                                    procs.forEach(function(p){ _addProcRow({pno:p.process_no,pname:p.ProcessName,maker_id_no:p.maker_id_no,maker_id:p.maker_id,ps:p.ps}); });
                                    pop.remove();
                                    showTemporaryMessage('已複製 '+procs.length+' 個製程',true);
                                }; })(bItem.processes);
                                sec.appendChild(applyBtn);
                            }
                            res.appendChild(sec);
                        });
                    },'json').fail(function(){ res.innerHTML='<div style="padding:8px;color:red;font-size:12px;">搜尋失敗</div>'; });
                },300);
            });
            setTimeout(function(){ inp.focus(); },100);
            document.addEventListener('click',function _cpClose(e){ if(!pop.contains(e.target)&&e.target!==copyProcBtn){ pop.remove(); document.removeEventListener('click',_cpClose); } });
        }

        // ── 料號搜尋（含鍵盤上下選擇）────────────────────────────────────────
        var _didTimer=null, _didIdx=-1;
        function _didHL(i){ var it=Array.from(didDrop.querySelectorAll('.did-opt')); it.forEach(function(e,j){ e.style.background=(j===i)?'#d0e4ff':''; }); _didIdx=i; }
        inDid.addEventListener('keydown',function(e){
            var it=Array.from(didDrop.querySelectorAll('.did-opt'));
            if(didDrop.style.display!=='none'&&it.length){
                if(e.key==='ArrowDown'){ e.preventDefault(); _didHL(Math.min(_didIdx+1,it.length-1)); return; }
                if(e.key==='ArrowUp'){ e.preventDefault(); _didHL(Math.max(_didIdx-1,0)); return; }
                if(e.key==='Enter'){ e.preventDefault(); if(_didIdx>=0) it[_didIdx].click(); return; }
                if(e.key==='Escape'){ didDrop.style.display='none'; _didIdx=-1; return; }
            }
        });
        inDid.addEventListener('input', function(){
            _selectedDidId=''; _didIdx=-1;
            clearTimeout(_didTimer);
            var term=inDid.value.trim();
            didDrop.innerHTML=''; didDrop.style.display='none';
            orderArea.style.display='none'; inClient.value='';
            if(!term) return;
            _didTimer=setTimeout(function(){
                $.post(_selfUrl,{action:'search_d_id_and_orders',term:term},function(resp){
                    didDrop.innerHTML=''; _didIdx=-1;
                    if(!resp.success||!resp.d_ids||!resp.d_ids.length){
                        var n=document.createElement('div'); n.style.cssText='padding:8px 10px;color:#999;font-size:12px;'; n.textContent='查無符合料號'; didDrop.appendChild(n); didDrop.style.display='block'; return;
                    }
                    resp.d_ids.forEach(function(d){
                        var opt=document.createElement('div'); opt.className='did-opt';
                        opt.style.cssText='padding:6px 10px;cursor:pointer;border-bottom:1px solid #f0f0f0;';
                        // 顯示: D_Setting_Id（顯示料號）[Spec_No]（客戶名稱）+ 代：Drawing_No（別名）
                        var _mainLabel = escapeHtml(d.display_id || d.d_id || '') + (d.spec_no ? ' ['+escapeHtml(d.spec_no)+']' : '') + (d.customer_name ? ' ('+escapeHtml(d.customer_name)+')' : '');
                        var _aliasLabel = (d.drawing_no && d.drawing_no !== (d.display_id || d.d_id)) ? '<span style="font-size:11px;color:#1a7abf;display:block;margin-top:1px;">代：' + escapeHtml(d.drawing_no) + '</span>' : '';
                        opt.innerHTML = _mainLabel + _aliasLabel;
                        opt.onmouseover=function(){ _didHL(Array.from(didDrop.querySelectorAll('.did-opt')).indexOf(opt)); };
                        opt.onmouseout=function(){ opt.style.background=''; };
                        opt.onclick=function(){
                            _selectedDidId = d.d_id || d.display_id;  // 內部數字ID（查訂單用）
                            inDid.value = d.display_id || d.d_id;     // 前端顯示文字
                            if(d.customer_name) inClient.value=d.customer_name;
                            console.log('[新增BOM] 選取料號',
                                '\n  d_setting_id(內部ID):', d.d_id,
                                '\n  display_id(顯示料號):', d.display_id,
                                '\n  customer_name:', d.customer_name
                            );
                            didDrop.style.display='none'; _didIdx=-1;
                            if(d.d_id) _loadOrdersForDid(d.d_id);
                            else orderArea.style.display='none';
                        };
                        didDrop.appendChild(opt);
                    });
                    didDrop.style.display='block';
                },'json');
            },300);
        });
        inDid.addEventListener('blur',function(){ setTimeout(function(){ didDrop.style.display='none'; _didIdx=-1; },200); });

        function _loadOrdersForDid(did){
            var tbody=document.getElementById('cb-order-tbody');
            if(tbody) tbody.innerHTML='<tr><td colspan="8" style="text-align:center;color:#999;padding:8px;">載入中...</td></tr>';
            orderArea.style.display='block';
            $.post(_selfUrl,{action:'get_orders_for_d_id',d_id:did},function(resp){
                if(!tbody) return;
                tbody.innerHTML='';
                if(!resp.success||!resp.orders||!resp.orders.length){
                    var et=document.createElement('tr'), ed=document.createElement('td');
                    ed.colSpan=8; ed.style.cssText='text-align:center;color:#999;padding:8px;font-size:12px;';
                    ed.textContent='此料號目前無進行中訂單（可直接新增為備庫）';
                    et.appendChild(ed); tbody.appendChild(et); return;
                }
                if(resp.client) inClient.value=resp.client;
                resp.orders.forEach(function(o){
                    var tr=document.createElement('tr');
                    // 勾選
                    var tdC=document.createElement('td'), chk=document.createElement('input');
                    chk.type='checkbox'; chk.dataset.orderId=o.Order_id;
                    // 點選前確認已填數量
                    chk.addEventListener('click',function(ev){
                        if(!chk.disabled){
                            var bq=parseInt(inQty.value||0,10);
                            if(!bq||bq<1){
                                ev.preventDefault();
                                inQty.focus(); inQty.style.borderColor='#dc3545';
                                var wId='qty-req-warn';
                                if(!document.getElementById(wId)){
                                    var w=document.createElement('div'); w.id=wId;
                                    w.style.cssText='color:red;font-size:11px;margin-top:2px;';
                                    w.textContent='⚠ 請先輸入 BOM 數量再勾選訂單';
                                    inQty.parentNode.appendChild(w);
                                    setTimeout(function(){ var el=document.getElementById(wId); if(el)el.remove(); inQty.style.borderColor=''; },2500);
                                }
                            }
                        }
                    },true);
                    // 可綁定餘量 (available_qty 由後端計算)
                    var avail=parseInt(o.available_qty||o.Open_Qty||o.Qty||0,10);
                    var bomQty=parseInt(inQty.value||0,10);
                    chk.dataset.availQty=avail;
                    if(avail<=0){
                        chk.disabled=true; chk.style.cursor='not-allowed';
                        tr.style.opacity='0.45'; tr.style.background='#f5f5f5';
                    } else {
                        chk.style.cursor='pointer';
                    }
                    tdC.appendChild(chk); tr.appendChild(tdC);
                    // 基本欄位
                    [o.Order_oo,o.Qty,o.Open_Qty,o.Delivery_date||'-'].forEach(function(v){
                        var td=document.createElement('td'); td.textContent=v||''; tr.appendChild(td);
                    });
                    // 規格
                    var tdSpec=document.createElement('td'); tdSpec.style.cssText='font-size:11px;color:#555;max-width:120px;word-break:break-all;'; tdSpec.textContent=o.Specification||'-'; tr.appendChild(tdSpec);
                    // 備註
                    var tdPs=document.createElement('td'); tdPs.style.cssText='font-size:11px;color:#888;max-width:120px;word-break:break-all;'; tdPs.textContent=o.order_ps||'-'; tr.appendChild(tdPs);
                    // 對應PCS（顯示可綁定餘量，inline）
                    var tdPcs=document.createElement('td'); tdPcs.style.cssText='white-space:nowrap;';
                    var pcsIn=document.createElement('input'); pcsIn.type='number'; pcsIn.min='1';
                    pcsIn.className='form-control input-sm cb-order-pcs';
                    pcsIn.style.cssText='width:72px;font-size:12px;padding:2px 5px;display:inline-block;vertical-align:middle;'; pcsIn.placeholder='全量';
                    pcsIn.dataset.orderId=o.Order_id;
                    var availLabel=document.createElement('span');
                    availLabel.style.cssText='font-size:12px;font-weight:bold;margin-left:5px;vertical-align:middle;color:'+(avail>0?'#28a745':'#dc3545')+';';
                    availLabel.textContent='可綁:'+avail;
                    tdPcs.appendChild(pcsIn); tdPcs.appendChild(availLabel); tr.appendChild(tdPcs);
                    // 超量提示 span
                    var overWarn=document.createElement('div');
                    overWarn.style.cssText='color:red;font-size:11px;display:none;margin-top:2px;';
                    tdPcs.appendChild(overWarn);
                    function _otherTotal(selfIn){
                        var t=0;
                        document.querySelectorAll('.cb-order-pcs').forEach(function(inp){
                            if(inp===selfIn)return;
                            var r2=inp.closest('tr');
                            if(r2&&r2.querySelector('input[type=checkbox]')&&r2.querySelector('input[type=checkbox]').checked)
                                t+=parseInt(inp.value||0,10);
                        });
                        return t;
                    }
                    pcsIn.addEventListener('input',function(){
                        var av=parseInt(chk.dataset.availQty||0,10);
                        var val=parseInt(pcsIn.value||0,10);
                        overWarn.style.display=(val>av&&av>0)?'block':'none';
                        if(overWarn.style.display==='block') overWarn.textContent='⚠ 超過可綁定數量！';
                    });
                    // 勾選時自動填入數量（扣已勾選）
                    chk.addEventListener('change',function(){
                        if(chk.checked){
                            var bq=parseInt(inQty.value||0,10);
                            var av=parseInt(chk.dataset.availQty||0,10);
                            var ot=_otherTotal(pcsIn);
                            if(bq>0&&ot>=bq){
                                pcsIn.value=0;
                                overWarn.textContent='⚠ 已勾選數量已達 BOM 總量（'+bq+'），請確認超額分配';
                                overWarn.style.display='block';
                            } else if(!pcsIn.value){
                                var remain=bq>0?Math.max(0,bq-ot):av;
                                pcsIn.value=Math.min(remain,av)||av||'';
                            }
                        }
                        if(!chk.checked){ pcsIn.value=''; overWarn.style.display='none'; }
                    });
                    tbody.appendChild(tr);
                });
            },'json').fail(function(){ if(tbody) tbody.innerHTML='<tr><td colspan="8" style="color:red;padding:8px;">訂單載入失敗</td></tr>'; });
        }

        // 確認送出
        confirmBtn.onclick=function(){
            var bom=inBom.value.trim().toUpperCase();
            var did=_selectedDidId || inDid.value.trim();
            var qty=parseInt(inQty.value.trim(),10);
            var bomPs=(document.getElementById('cb-bom-ps')||{}).value||'';
            if (!bom){ alert('請填入BOM號碼'); return; }
            var bomCheck = _validateBomNumber(bom);
            if (!bomCheck.ok){ alert('BOM格式錯誤：' + bomCheck.msg); return; }
            if (bomCheck.warn){ if(!confirm('⚠ ' + bomCheck.msg + '\n確定要繼續新增？')){ return; } }
            if (!did){ alert('請填入並從下拉選單選擇料號'); return; }
            if (!_selectedDidId){ alert('請從下拉選單選取料號（不可直接輸入）'); return; }
            if (!qty||qty<1){ alert('請填入有效數量'); return; }

            // 收集勾選訂單（使用 order_id，非 Order_oo）
            var checkedOrders=[];
            var tbody=document.getElementById('cb-order-tbody');
            if (tbody){
                tbody.querySelectorAll('input[type=checkbox]:checked').forEach(function(chk){
                    var oid = chk.dataset.orderId || chk.value;
                    var row=chk.closest('tr');
                    var pcsVal=row?parseInt((row.querySelector('.cb-order-pcs')||{}).value||'0',10):0;
                    checkedOrders.push({order_id:oid, pcs:pcsVal>0?pcsVal:null});
                });
            }
            var orderPcsJson=JSON.stringify(checkedOrders);

            // 收集製程（含廠商/備註），自動跳過空白行並重新編號
            var pInputs=procRows.querySelectorAll('.pcInput');
            var procs=[];
            pInputs.forEach(function(pi){
                var pno=pi.dataset.pno||pi.value.split(' ')[0].trim();
                if (!pno||isNaN(parseInt(pno))){ return; } // 跳過空白行，不報錯
                var rowEl = pi.closest('div[draggable]') || pi.parentElement.parentElement;
                var makerEl = rowEl ? rowEl.querySelector('.pcMaker') : null;
                var psEl    = rowEl ? rowEl.querySelector('.pcPs')    : null;
                procs.push({
                    process_no: pno,
                    maker_id_no: makerEl ? (makerEl.dataset.makerNo||'') : '',
                    maker_id:    makerEl ? (makerEl.dataset.makerId||'') : '',
                    ps:          psEl    ? (psEl.value||'')               : ''
                });
            });
            if (procs.length===0){ alert('請至少新增一個有效製程（從下拉選單選取）'); return; }

            confirmBtn.disabled=true; confirmBtn.textContent='新增中...';
            $.post(_selfUrl,{
                action:'create_bom', bom:bom,
                d_setting_id: _selectedDidId,         // d_setting.d_id 內部ID → bom.d_setting_id
                d_id: inDid.value.trim(),             // D_Setting_Id 顯示文字 → bom.d_id
                client_name:inClient.value.trim()||'',
                sqty:qty, bom_ps:bomPs,
                order_pcs_json:orderPcsJson,
                processes:JSON.stringify(procs)
            },function(resp){
                if(resp && resp.bom_exists){
                    alert('❌ BOM號碼「' + bom + '」已存在！無法重複建立，請確認號碼是否正確。');
                    confirmBtn.disabled=false; confirmBtn.textContent='確認新增';
                    return;
                }
                confirmBtn.disabled=false; confirmBtn.textContent='確認新增';
                if (resp&&resp.success){
                    showTemporaryMessage('BOM '+resp.bom+' 新增成功！',true);
                    overlay.remove();
                    fetchDataAndFilter();
                } else {
                    alert('新增失敗：'+(resp&&resp.message?resp.message:'未知錯誤'));
                }
            },'json').fail(function(xhr){
                confirmBtn.disabled=false; confirmBtn.textContent='確認新增';
                alert('伺服器通訊失敗（HTTP '+xhr.status+'）');
            });
        };
    }

    function displayEditFormForRow(rowData, buttonElement) {
        // 移除可能已存在的舊表單
        const existingForm = document.getElementById('edit-bom-row-form-container');
        if (existingForm) {
            existingForm.remove();
        }

        // 創建表單容器
        const formContainer = document.createElement('div');
        formContainer.id = 'edit-bom-row-form-container';
        formContainer.className = 'row'; // 使用 row 包裹 x_panel
        formContainer.style.marginTop = '20px';

        const panelDiv = document.createElement('div');
        panelDiv.className = 'col-md-12 col-sm-12 col-xs-12';
        formContainer.appendChild(panelDiv);

        const xPanel = document.createElement('div');
        xPanel.className = 'x_panel';
        panelDiv.appendChild(xPanel);

        // 表單標題 (包含收合按鈕)
        const xTitle = document.createElement('div');
        xTitle.className = 'x_title';

        const xTitleH2 = document.createElement('h2');
        xTitleH2.textContent = '修改 BOM 資料';
        xTitle.appendChild(xTitleH2);

        const xTitleUl = document.createElement('ul');
        xTitleUl.className = 'nav navbar-right panel_toolbox';
        xTitleUl.style.minWidth = 'unset';
        const xTitleLi = document.createElement('li');
        const xTitleCloseA = document.createElement('a');
        xTitleCloseA.className = 'close-link';
        xTitleCloseA.style.cursor = 'pointer';
        xTitleCloseA.innerHTML = '<i class="fa fa-close"></i>';
        // ✅ 修復：改用 addEventListener，buttonElement 透過閉包正確取用，避免 ReferenceError
        xTitleCloseA.addEventListener('click', function(e) {
            e.preventDefault();
            const container = document.getElementById('edit-bom-row-form-container');
            if (container) container.remove();
            if (buttonElement) {
                buttonElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        });
        xTitleLi.appendChild(xTitleCloseA);
        xTitleUl.appendChild(xTitleLi);
        xTitle.appendChild(xTitleUl);

        const xTitleClearfix = document.createElement('div');
        xTitleClearfix.className = 'clearfix';
        xTitle.appendChild(xTitleClearfix);

        xPanel.appendChild(xTitle);

        const xContent = document.createElement('div');
        xContent.className = 'x_content';
        const form = document.createElement('form');
        form.className = 'form-horizontal form-label-left';
        form.onsubmit = function(e) {
            e.preventDefault();
            return false;
        }; // 防止表單預設提交

        // Overall centering wrapper for the main layout columns
        const centeringWrapperDiv = document.createElement('div');
        centeringWrapperDiv.className = 'col-md-10 col-md-offset-1 col-sm-10 col-sm-offset-1 col-xs-12';

        // Flex container for the main layout (leftAndCenterPaneWrapper and rightColProcesses)
        const innerFlexContainer = document.createElement('div');
        innerFlexContainer.id = 'edit-form-flex-columns'; // ID for CSS media query targeting
        innerFlexContainer.style.display = 'flex';
        innerFlexContainer.style.flexDirection = 'row';
        innerFlexContainer.style.alignItems = 'stretch'; // Ensure columns stretch to the same height
        innerFlexContainer.style.flexWrap = 'nowrap'; // Prevent main panes from wrapping on large screens

        // Wrapper for Left and Center columns to stack them
        const leftAndCenterPaneWrapper = document.createElement('div');
        leftAndCenterPaneWrapper.id = 'left-center-pane';
        leftAndCenterPaneWrapper.style.display = 'flex'; // Make this a flex container too
        leftAndCenterPaneWrapper.style.flexDirection = 'column'; // Stack leftCol and centerCol vertically

        // 左欄
        const leftCol = document.createElement('div');
        leftCol.className = 'edit-form-column-wrapper'; // Keep custom styling, remove Bootstrap grid for md/sm
        leftCol.style.width = '100%';
        leftCol.style.marginBottom = '10px'; // 10px gap to centerCol below it

        // 左欄 - BOM (不可修改)
        leftCol.innerHTML += `
            <div class="form-group">
                <label class="control-label col-md-3 col-sm-3 col-xs-12">BOM</label>
                <div class="col-md-9 col-sm-9 col-xs-12" style="padding-top: 7px;">${escapeHtml(rowData.bom)}</div>
            </div>`;
        // 左欄 - 料號（顯示 d_display，並附快速綁定放大鏡）
        console.log('[更新BOM] debug:',
            '\n  bom:', rowData.bom,
            '\n  d_setting_id:', rowData.d_setting_id || '(未設定)',
            '\n  d_display:', rowData.d_display || rowData.d_id || '',
            '\n  d_id(bom.d_id):', rowData.d_id,
            '\n  Client_Name:', rowData.Client_Name || ''
        );
        (function(){
            var didDiv = document.createElement('div');
            didDiv.className = 'form-group';
            didDiv.innerHTML = '<label class="control-label col-md-3 col-sm-3 col-xs-12">料號</label>';
            var didVal = document.createElement('div');
            didVal.className = 'col-md-9 col-sm-9 col-xs-12';
            didVal.style.cssText = 'padding-top:7px;display:flex;align-items:center;gap:6px;';
            var didText = document.createElement('span');
            var _dDisp = rowData.d_display || rowData.d_id || '';
            didText.textContent = _dDisp || '（未設定）';
            didText.style.color = _dDisp ? '' : '#dc3545';
            didVal.appendChild(didText);
            // 已有 d_setting_id 綁定者不顯示放大鏡
            var _updateHasBound = !!(rowData.d_setting_id && String(rowData.d_setting_id).trim() !== '');
            // [已移除] 放大鏡按鈕功能已停用
            didDiv.appendChild(didVal);
            leftCol.appendChild(didDiv);
        })();
        // 左欄 - 發單數 (moved from rightCol)
        leftCol.innerHTML += `
            <div class="form-group">
                <label class="control-label col-md-3 col-sm-3 col-xs-12">發單數</label>
                <div class="col-md-9 col-sm-9 col-xs-12" style="padding-top: 7px;">${escapeHtml(rowData.Qty)}</div>
            </div>`;
        leftAndCenterPaneWrapper.appendChild(leftCol);

        // 中間欄 (原右欄部分內容)
        const centerCol = document.createElement('div');
        centerCol.className = 'edit-form-column-wrapper';
        centerCol.style.width = '100%';

        // --- 修正：判斷客戶名稱是否可編輯 (未綁定料號設定時可編輯) ---
        const isDsettingBound = !!(rowData.d_setting_id && String(rowData.d_setting_id).trim() !== '');
        const clientInputReadonly = isDsettingBound ? 'readonly' : '';
        const clientInputStyle = isDsettingBound ? 'background:#f5f5f5;cursor:not-allowed;color:#555;' : '';
        const clientInputHint = isDsettingBound ? '由料號綁定自動帶入，不可修改' : '尚未綁定料號，可手動修改客戶名稱';

        centerCol.innerHTML += `
        <div class="form-group">
            <label class="control-label col-md-3 col-sm-3 col-xs-12" for="edit-client-name">客戶</label>
            <div class="col-md-5 col-sm-5 col-xs-12">
                <input type="text" id="edit-client-name" class="form-control"
                       value="${escapeHtml(rowData.client_name_display || rowData.Client_Name_Full || rowData.Client_Name || '')}"
                       ${clientInputReadonly}
                       style="${clientInputStyle}">
                <small style="color:#888;">${clientInputHint}</small>
            </div>
        </div>`;

        // 中間欄 - 訂單綁定（多筆，含數量分配）
        const orderGroupDiv = document.createElement('div');
        orderGroupDiv.className = 'form-group';

        // --- DEBUG ---
        // console.log("%c[DEBUG] 開啟 BOM 修改視窗", "color: blue; font-weight: bold;");
        // console.log("BOM:", rowData.bom, "| 料號(d_id):", rowData.d_id, "| d_setting_id:", rowData.d_setting_id);

        const orderLabel = document.createElement('label');
        orderLabel.className = 'control-label col-md-3 col-sm-3 col-xs-12';
        orderLabel.textContent = '訂單綁定';
        orderGroupDiv.appendChild(orderLabel);

        const orderDisplayDiv = document.createElement('div');
        orderDisplayDiv.className = 'col-md-9 col-sm-9 col-xs-12';
        orderDisplayDiv.style.paddingTop = '2px';

        // ── 數量摘要列（BOM總數 / 已分配 / 狀態）──
        const qtyStatusBar = document.createElement('div');
        qtyStatusBar.style.cssText = 'background:#f8f9fa;border:1px solid #dee2e6;border-radius:4px;padding:4px 10px;margin-bottom:5px;font-size:12px;display:flex;align-items:center;gap:14px;flex-wrap:wrap;';
        qtyStatusBar.innerHTML =
            'BOM總數：<strong id="edit-bom-qty-display">' + escapeHtml(String(rowData.Qty || 0)) + '</strong>' +
            '&nbsp;已分配：<strong id="edit-bound-qty-display" style="color:gray;">0</strong>' +
            '&nbsp;<span id="edit-bound-qty-warn" style="color:orange;font-size:11px;display:none;"></span>' +
            '&nbsp;<label style="margin-left:10px;margin-bottom:0;display:flex;align-items:center;gap:5px;cursor:pointer;font-weight:normal;">' +
            '<input type="checkbox" id="edit-stock-cb" style="width:15px;height:15px;cursor:pointer;" ' + (rowData.Order_id === 'B' ? 'checked' : '') + '>' +
            '<span style="color:#555;">備庫（無訂單）</span>' +
            '</label>';
        orderDisplayDiv.appendChild(qtyStatusBar);

        // 備庫 checkbox 邏輯
        setTimeout(function() {
            var stockCb = document.getElementById('edit-stock-cb');
            var olc = document.getElementById('edit-form-order-list-container');
            if (stockCb) {
                stockCb.addEventListener('change', function() {
                    if (this.checked) {
                        // 清除所有已勾選訂單
                        if (olc) {
                            olc.querySelectorAll('input.order-bind-cb:checked').forEach(function(cb) {
                                cb.checked = false;
                                var row = cb.closest('tr');
                                if (row) { var pi = row.querySelector('input.order-bind-pcs'); if (pi) pi.value = ''; }
                            });
                        }
                        updateEditBoundQty();
                    }
                });
                // 勾選任意訂單時自動取消備庫
                if (olc) {
                    olc.addEventListener('change', function(e) {
                        if (e.target && e.target.classList.contains('order-bind-cb') && e.target.checked) {
                            var sc = document.getElementById('edit-stock-cb');
                            if (sc) sc.checked = false;
                        }
                    });
                }
            }
        }, 600);

        // ── 訂單勾選表格容器（由 loadOrdersForEditForm 動態填入）──
        const orderListContainer = document.createElement('div');
        orderListContainer.id = 'edit-form-order-list-container';
        orderListContainer.style.cssText = 'border:1px solid #dee2e6;border-radius:4px;max-height:220px;overflow-y:auto;';
        orderListContainer.innerHTML = '<div style="padding:10px;text-align:center;color:#999;font-size:12px;">載入訂單中...</div>';
        orderDisplayDiv.appendChild(orderListContainer);

        orderGroupDiv.appendChild(orderDisplayDiv);
        centerCol.appendChild(orderGroupDiv);

        // 非同步載入訂單列表（傳入 DOM 節點，避免 jQuery 選取器在節點尚未插入 DOM 時找不到元素）
        if (rowData.d_setting_id) {
            loadOrdersForEditForm(rowData.d_setting_id, rowData.bom, rowData.Qty, orderListContainer);
        } else {
            orderListContainer.innerHTML = '<div style="padding:10px;text-align:center;color:#aaa;font-size:12px;">此 BOM 未設定料號，無法載入訂單。</div>';
        }

        // 中間欄 - 手動交期（覆蓋訂單交期用）
        const deliveryQtyGroupDiv = document.createElement('div');
        deliveryQtyGroupDiv.className = 'form-group';

        const deliveryLabel = document.createElement('label');
        deliveryLabel.className = 'control-label col-md-3 col-sm-3 col-xs-12';
        deliveryLabel.textContent = '手動交期';
        deliveryQtyGroupDiv.appendChild(deliveryLabel);

        const deliveryDisplayDiv = document.createElement('div');
        deliveryDisplayDiv.className = 'col-md-5 col-sm-5 col-xs-12';
        deliveryDisplayDiv.style.paddingTop = '5px';

        const dateInput = document.createElement('input');
        dateInput.type = 'text';
        dateInput.className = 'form-control transfer-datepicker';
        dateInput.style.cssText = 'width:120px;display:inline-block;';
        dateInput.placeholder = '選填，覆蓋訂單交期';
        dateInput.value = (rowData.Delivery_date && rowData.Delivery_date !== '0000-00-00') ? rowData.Delivery_date : '';
        dateInput.onchange = function() { updateBomDeliveryDate(rowData.bom, this.value); };

        const reminder = document.createElement('div');
        reminder.style.cssText = 'color:red;font-size:0.85em;margin-top:2px;';
        reminder.textContent = '(手動設定優先)';
        deliveryDisplayDiv.append(dateInput, reminder);
        deliveryQtyGroupDiv.appendChild(deliveryDisplayDiv);

        centerCol.appendChild(deliveryQtyGroupDiv);

// 接著再加上 BOM 備註
        const bomPsGroupDiv = document.createElement('div');
        bomPsGroupDiv.className = 'form-group';
        bomPsGroupDiv.innerHTML = `
          <div class="form-group">
            <label
              class="control-label col-md-3 col-sm-3 col-xs-12"
              for="edit-form-bom-ps"
            >
              BOM備註
            </label>
            <div class="col-md-5 col-sm-5 col-xs-12">
              <textarea
                id="edit-form-bom-ps"
                class="form-control"
                rows="2"
                placeholder="請輸入 BOM 備註…"
                data-orig="${escapeHtml(rowData.bom_ALL_bom_ps || '')}"
              >${escapeHtml(rowData.bom_ALL_bom_ps || '')}</textarea>
            </div>
          </div>
        `;
        centerCol.appendChild(bomPsGroupDiv);

        // 燈號設定 (移至備註下方)
        const lightGroupDiv = document.createElement('div');
        lightGroupDiv.className = 'form-group';
        
        const lightLabel = document.createElement('label');
        lightLabel.className = 'control-label col-md-3 col-sm-3 col-xs-12';
        lightLabel.textContent = '燈號設定';
        lightGroupDiv.appendChild(lightLabel);

        const lightControlDiv = document.createElement('div');
        lightControlDiv.className = 'col-md-9 col-sm-9 col-xs-12';
        lightControlDiv.style.display = 'flex';
        lightControlDiv.style.alignItems = 'center';
        lightControlDiv.style.gap = '15px';
        lightControlDiv.style.paddingTop = '5px';

        const createLightOption = (cls, type, labelText) => {
            const wrapper = document.createElement('div');
            wrapper.style.display = 'flex';
            wrapper.style.alignItems = 'center';
            wrapper.style.cursor = 'pointer';
            
            const fig = document.createElement('figure');
            fig.className = cls;
            fig.style.margin = '0 5px 0 0';
            fig.style.width = '18px';
            fig.style.height = '18px';
            fig.style.borderRadius = '50%';
            
            const text = document.createElement('span');
            text.textContent = labelText;
            
            wrapper.appendChild(fig);
            wrapper.appendChild(text);
            
            wrapper.onclick = () => {
                $.ajax({
                    url: '../../src/store/_update_bom_priority.php',
                    type: 'POST',
                    data: { bom: rowData.bom, new_priority_type: type },
                    dataType: 'json',
                    success: function(res) {
                        if(res.success) {
                            updateLightSelection(type);
                            rowData.priority_type = type;
                            const item = fullDataset.find(i => i.bom === rowData.bom);
                            if(item) item.priority_type = type;
                            processAndRenderData();
                        } else {
                            alert('更新失敗: ' + res.message);
                        }
                    },
                    error: function() { alert('通訊失敗'); }
                });
            };
            return { wrapper, fig };
        };

        // 選項：無 (不指定), 黃燈, 紅燈
        // 使用 circle_green 代表 "無(預設)"
        const noneOption = createLightOption('circle_green', '', '無(預設)');
        const yellowOption = createLightOption('circle_y', 'U', '黃燈');
        const redOption = createLightOption('circle_red', 'E', '紅燈');

        const updateLightSelection = (selectedType) => {
            // Reset styles
            [noneOption, yellowOption, redOption].forEach(opt => {
                opt.fig.style.border = 'none';
                opt.fig.style.transform = 'scale(1)';
                opt.fig.style.boxShadow = 'none';
                opt.wrapper.style.fontWeight = 'normal';
            });

            let target = noneOption;
            if (selectedType === 'U') target = yellowOption;
            if (selectedType === 'E') target = redOption;

            target.fig.style.border = '2px solid #333';
            target.fig.style.transform = 'scale(1.2)';
            target.fig.style.boxShadow = '0 0 5px rgba(0,0,0,0.5)';
            target.wrapper.style.fontWeight = 'bold';
        };

        updateLightSelection(rowData.priority_type || '');

        lightControlDiv.appendChild(noneOption.wrapper);
        lightControlDiv.appendChild(yellowOption.wrapper);
        lightControlDiv.appendChild(redOption.wrapper);

        lightGroupDiv.appendChild(lightControlDiv);
        centerCol.appendChild(lightGroupDiv);

        leftAndCenterPaneWrapper.appendChild(centerCol);
        innerFlexContainer.appendChild(leftAndCenterPaneWrapper); // Add the combined left/center pane

        // 新右欄 - 製程相關
        const rightColProcesses = document.createElement('div');
        rightColProcesses.className = 'edit-form-column-wrapper';
        // Default: Takes roughly the other half of the space
        rightColProcesses.style.flex = '1 0 calc(50% - 25px)';

        // Find ProcessName for current rowData.process_no
        // Request 1: Remove "目前製程 製程代號 製程中文" display from the top of the right-side area.
        // The input field 'edit-process-no' was part of this display and is also removed.
        // If editing the main item's process_no is still required, it would need a different UI approach.

        // 新右欄 - 動態製程列表
        rightColProcesses.innerHTML += `<h5 style="margin-top:15px; margin-bottom:10px;">BOM 製程列表<small style="color: #777;"> (SN 製程編號 製程)</small></h5>`;
        const processListDiv = document.createElement('div');
        processListDiv.id = `dynamic-process-list-${rowData.bom}`;
        // processListDiv.style.maxHeight = '200px'; // Removed for auto height
        // processListDiv.style.overflowY = 'auto';  // Removed for auto height
        processListDiv.style.marginBottom = '10px';

        // Filter and sort processes for the current BOM from global bomPSList
        const currentBOMProcesses = window.bomPSList
            .filter(p => String(p.bom || '').trim() === String(rowData.bom || '').trim()) // Apply trim and ensure string comparison
            .sort((a, b) => (parseInt(a.bom_sn) || 0) - (parseInt(b.bom_sn) || 0));

        // 為每一個製程項目預先建立對應的 Modal HTML
        currentBOMProcesses.forEach(proc => {
            // Add the modal HTML to the form container
            const transferModalHtml = `
            <div id="transferProcessModal_${proc.bom_ing_fid}" class="modal fade" role="dialog">
              <div class="modal-dialog">
                <div class="modal-content">
                  <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                    <h4 class="modal-title">${escapeHtml(rowData.bom)} / ${escapeHtml(rowData.d_id)} 移轉至 ${escapeHtml(proc.process_no)} ${proc.ProcessName ? escapeHtml(proc.ProcessName) : ''}</h4>
                  </div>
                  <div class="modal-body">
                    <form class="form-horizontal" onsubmit="return false;">
                        <div class="form-group">
                            <label class="control-label col-sm-3">移轉日：</label>
                            <div class="col-sm-5">
                                <div class="datetest">
                                    <input type="text" class="form-control transfer-datepicker" id="transfer-date-${proc.bom_ing_fid}" placeholder="請選擇日期">
                                </div>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="control-label col-sm-3">廠商編號：</label>
                            <div class="col-sm-5">
                                <input type="text" class="form-control" id="transfer-maker-no-${proc.bom_ing_fid}" list="maker-no-list-${proc.bom_ing_fid}" placeholder="輸入編號..." pattern="[a-zA-Z0-9-]*" title="僅能輸入英數字與-符號">
                                <datalist id="maker-no-list-${proc.bom_ing_fid}"></datalist>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="control-label col-sm-3">廠商中文：</label>
                            <div class="col-sm-5">
                                <input type="text" class="form-control" id="transfer-maker-name-${proc.bom_ing_fid}" list="maker-name-list-${proc.bom_ing_fid}" placeholder="輸入中文...">
                                <datalist id="maker-name-list-${proc.bom_ing_fid}"></datalist>
                            </div>
                        </div>
                    </form>
                  </div>
                  <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
                    <button type="button" class="btn btn-primary" id="confirm-transfer-${proc.bom_ing_fid}">確認移轉</button>
                  </div>
                </div>
              </div>
            </div>`;
            form.insertAdjacentHTML('beforeend', transferModalHtml);
        });

        // --- Conditional Layout Logic ---
        console.log(`檢查 BOM ${rowData.bom} 的製程數量: ${currentBOMProcesses.length} 個製程。`); // 新增日誌

        if (currentBOMProcesses.length <= 3) {
            console.log(`套用 3 欄並排佈局 - BOM ${rowData.bom} 有 ${currentBOMProcesses.length} 個製程 (<= 3).`);

            leftCol.style.flex = '1'; // Grow and shrink equally
            leftCol.style.marginRight = '10px';
            leftCol.style.marginBottom = '0';

            centerCol.style.flex = '1';
            centerCol.style.marginRight = '10px'; // Consistent 10px gap
            centerCol.style.marginBottom = '0';

            rightColProcesses.style.flex = '1';
            rightColProcesses.style.marginRight = '0'; // No right margin for the last item
            rightColProcesses.style.marginBottom = '0';

            innerFlexContainer.appendChild(leftCol);
            innerFlexContainer.appendChild(centerCol);
            innerFlexContainer.appendChild(rightColProcesses);

            // Ensure process list in rightColProcesses is single column
            processListDiv.style.display = 'block';
            currentBOMProcesses.forEach(function(proc) {
                var showTransfer = (!window.isCRU && window.displayPermissionCode !== 'D+R') || window.featTransfer;
                processListDiv.appendChild(_buildProcItemDiv(proc, rowData, showTransfer));
            });

        } else { // currentBOMProcesses.length >= 4 (This is the 2-pane layout)
            console.log(`套用 2 主窗格並排佈局 - BOM ${rowData.bom} 有 ${currentBOMProcesses.length} 個製程 (>= 4).`);
            // 2-pane layout: leftAndCenterPaneWrapper and rightColProcesses
            leftAndCenterPaneWrapper.appendChild(leftCol); // leftCol is child of wrapper
            leftAndCenterPaneWrapper.appendChild(centerCol); // centerCol is child of wrapper

            leftAndCenterPaneWrapper.style.flex = '1 0 calc(50% - 25px)';
            leftAndCenterPaneWrapper.style.width = ''; // Clear explicit width
            leftAndCenterPaneWrapper.style.marginRight = '50px'; // Gap between left/center pane and right pane
            leftAndCenterPaneWrapper.style.marginBottom = '0'; // No bottom margin in side-by-side

            rightColProcesses.style.flex = '1 0 calc(50% - 25px)'; // Right pane takes the other half
            rightColProcesses.style.width = ''; // Clear explicit width
            rightColProcesses.style.marginRight = '0';

            innerFlexContainer.appendChild(leftAndCenterPaneWrapper);
            innerFlexContainer.appendChild(rightColProcesses);

            // Populate processListDiv based on count
            if (currentBOMProcesses.length >= 6) {
                console.log(`  套用右側製程列表 2 欄顯示 (內部) - BOM ${rowData.bom} 有 ${currentBOMProcesses.length} 個製程 (>= 6).`);
                processListDiv.innerHTML = ''; // Clear if any items were added by mistake before
                processListDiv.style.display = 'flex';
                // processListDiv.style.justifyContent = 'space-between'; // Distribute space

                const leftVirtualCol = document.createElement('div');
                leftVirtualCol.style.flex = '1'; // Adjust as needed, e.g., '0 0 48%'

                const rightVirtualCol = document.createElement('div');
                rightVirtualCol.style.flex = '1'; // Adjust as needed

                const itemsPerLeftCol = Math.ceil(currentBOMProcesses.length / 2);

                currentBOMProcesses.forEach(function(proc, index) {
                    var showTransfer = (!window.isCRU && window.displayPermissionCode !== 'D+R') || window.featTransfer;
                    var procItemDiv = _buildProcItemDiv(proc, rowData, showTransfer);
                    if (index < itemsPerLeftCol) leftVirtualCol.appendChild(procItemDiv);
                    else rightVirtualCol.appendChild(procItemDiv);
                });

                processListDiv.appendChild(leftVirtualCol);

                if (rightVirtualCol.hasChildNodes()) { // Only add separator if right column has content
                    const separator = document.createElement('div');
                    separator.style.width = '1px';
                    separator.style.backgroundColor = '#ccc'; // Separator color
                    separator.style.margin = '0 10px'; // Space around separator
                    separator.style.alignSelf = 'stretch'; // Make it full height of flex container
                    processListDiv.appendChild(separator);
                    processListDiv.appendChild(rightVirtualCol);
                }
            } else { // 4 to 5 processes
                console.log(`  套用右側製程列表 1 欄顯示 (內部) - BOM ${rowData.bom} 有 ${currentBOMProcesses.length} 個製程 (4-5).`);
                processListDiv.style.display = 'block';
                currentBOMProcesses.forEach(function(proc) {
                    var showTransfer = (!window.isCRU && window.displayPermissionCode !== 'D+R') || window.featTransfer;
                    processListDiv.appendChild(_buildProcItemDiv(proc, rowData, showTransfer));
                });
            }
        }
        centeringWrapperDiv.appendChild(innerFlexContainer);
        rightColProcesses.appendChild(processListDiv);
        // 新增製程按鈕 - 使用 createElement 避免 innerHTML += 破壞 processListDiv 內已綁定的事件
        if (window.canCreate) {
            var addProcBtn = document.createElement('button');
            addProcBtn.type = 'button';
            addProcBtn.className = 'btn btn-info btn-sm';
            addProcBtn.textContent = '新增製程';
            addProcBtn.onclick = function() {
                openAddProcessModal(rowData);
            };
            rightColProcesses.appendChild(addProcBtn);
        }

        // ── 方案四：外包回廠預測按鈕 ──
        var outsourcePredictBtn = document.createElement('button');
        outsourcePredictBtn.type = 'button';
        outsourcePredictBtn.className = 'btn btn-default btn-sm';
        outsourcePredictBtn.style.cssText = 'margin-left:6px;font-size:11px;';
        outsourcePredictBtn.innerHTML = '🚚 外包預測';
        outsourcePredictBtn.title = '顯示外包廠商歷史回廠天數與催單優先順序';
        (function(rd) {
            outsourcePredictBtn.onclick = function(e) {
                e.stopPropagation();
                var tdEl = e.target.closest && e.target.closest('tr') && e.target.closest('tr').querySelector('td[name="d_id"]');
                var anchorEl = tdEl || e.target;
                openOutsourcePredictPanel(rd.bom, rd.priority_type || null, anchorEl);
            };
        })(rowData);
        rightColProcesses.appendChild(outsourcePredictBtn);


        // 創建最外層的 row，用於 Bootstrap 的網格系統
        const outerFormRow = document.createElement('div');
        outerFormRow.className = 'row';
        outerFormRow.style.marginBottom = '15px'; // Add space below the columns, before the buttons
        outerFormRow.appendChild(centeringWrapperDiv);
        form.appendChild(outerFormRow); // 將包含居中邏輯的 outerFormRow 加入表單

        // 按鈕
        const buttonDiv = document.createElement('div'); // Ensure buttonDiv is declared before use
        buttonDiv.className = 'form-group'; // Moved from innerHTML to direct assignment
        
        // 權限判斷：含 D 或 A（window.canManualClose = true）才顯示人工結案按鈕
        // R+U、C+R+U 沒有 D，不顯示；C+D+R+U 含 D，可顯示
        let manualCloseBtnHtml = '';
        if (window.canManualClose) {
            manualCloseBtnHtml = '<button type="button" class="btn btn-danger" id="submit-end-bom">人工結案</button>&emsp;&emsp;';
        }

        buttonDiv.innerHTML = `
            <div class="col-md-6 col-md-offset-3">
                ${manualCloseBtnHtml}
                <button type="button" class="btn btn-warning" id="cancel-edit-bom" style="margin-right: 10px;">取消</button> 
                ${(window.displayPermissionCode === 'A' || window.displayPermissionCode === 'C+R') ? '<button type="button" class="btn btn-danger" id="cancel-transfer-edit-bom" style="margin-right: 10px;">取消移轉</button>' : ''}
                <button type="button" class="btn btn-primary" id="submit-edit-bom">確定修改</button>
            </div>`;
        form.appendChild(buttonDiv);
        xContent.appendChild(form); // 將 form 加入 x_content
        xPanel.appendChild(xContent); // 將 x_content 加入 x_panel

        // 將表單插入到主表格的 x_panel 之前
        const mainTableXPanel = document.querySelector('.right_col .x_panel:not(#edit-bom-row-form-container .x_panel)'); // 找到主表格的 x_panel
        if (mainTableXPanel && mainTableXPanel.parentNode) {
            mainTableXPanel.parentNode.insertBefore(formContainer, mainTableXPanel);
        } else {
            document.querySelector('.right_col .row').appendChild(formContainer); // 備用方案
        }

        // 捲動到新表單的位置
        formContainer.scrollIntoView({
            behavior: 'smooth',
            block: 'start'
        });

        // 初始化動態產生的日期選擇器
        // Initialize datepickers for the newly created transfer modals
        $('.transfer-datepicker').datepicker({
            changeMonth: true,
            changeYear: true,
            showMonthAfterYear: true,
            dateFormat: "yy-mm-dd"
        });

        // Event delegation for vendor auto-complete in the transfer modal (MODIFIED)
        $(formContainer).on('input', 'input[id^="transfer-maker-"]', function(event) {
            const $input = $(this);
            const term = $input.val().trim();
            const id = $input.attr('id');
            const fid = id.substring(id.lastIndexOf('-') + 1);

            let searchType = '';
            let $otherInput;
            let $makerNoInput = $('#transfer-maker-no-' + fid);
            let $makerNameInput = $('#transfer-maker-name-' + fid);
            let $noDatalist = $('#maker-no-list-' + fid);
            let $nameDatalist = $('#maker-name-list-' + fid);

            if (id.startsWith('transfer-maker-no-')) {
                searchType = 'no';
                $otherInput = $makerNameInput;
            } else if (id.startsWith('transfer-maker-name-')) {
                searchType = 'name';
                $otherInput = $makerNoInput;
            } else {
                return;
            }

            // If the input event was a selection from the datalist
            const selectedOption = $(`#${$input.attr('list')} option`).filter(function() {
                return this.value === $input.val();
            });

            if (selectedOption.length > 0) {
                const selectedNo = selectedOption.data('no');
                const selectedName = selectedOption.data('name');
                $makerNoInput.val(selectedNo);
                $makerNameInput.val(selectedName);
                $noDatalist.empty(); // Clear lists after selection
                $nameDatalist.empty();
                return; // Stop further processing
            }

            // If input is too short or empty, clear lists and the other input
            if (term.length < 1) {
                $otherInput.val('');
                $noDatalist.empty();
                $nameDatalist.empty();
                return;
            }

            // Perform AJAX search
            $.ajax({
                url: _phpSelf,
                type: 'POST',
                data: {
                    action: 'search_maker',
                    term: term,
                    search_type: searchType
                },
                dataType: 'json',
                success: function(response) {
                    $noDatalist.empty();
                    $nameDatalist.empty();

                    if (response.success && Array.isArray(response.data) && response.data.length > 0) {
                        response.data.forEach(function(maker) {
                            // Populate both datalists for consistency
                            $noDatalist.append(`<option value="${escapeHtml(maker.maker_id_no)}" data-name="${escapeHtml(maker.maker_id)}" data-no="${escapeHtml(maker.maker_id_no)}"></option>`);
                            $nameDatalist.append(`<option value="${escapeHtml(maker.maker_id)}" data-name="${escapeHtml(maker.maker_id)}" data-no="${escapeHtml(maker.maker_id_no)}">${escapeHtml(maker.maker_id_no)}</option>`);
                        });
                    }
                },
                error: function() {
                    $noDatalist.empty();
                    $nameDatalist.empty();
                }
            });
        });

        // Event delegation for the "Confirm Transfer" button
        $(formContainer).on('click', '[id^="confirm-transfer-"]', function() {
            const $button = $(this);
            const fid = $button.attr('id').substring($button.attr('id').lastIndexOf('-') + 1);

            const transferDate = $('#transfer-date-' + fid).val();
            const makerNo = $('#transfer-maker-no-' + fid).val().trim();
            const makerName = $('#transfer-maker-name-' + fid).val().trim();

            // Validation
            if (!transferDate) {
                alert('請選擇移轉日期。');
                return;
            }
            if (!makerNo || !makerName) {
                alert('請輸入並選擇有效的廠商。');
                return;
            }

            $button.prop('disabled', true).text('處理中...');

            $.ajax({
                url: _phpSelf,
                type: 'POST',
                data: {
                    action: 'transfer_process',
                    bom_ing_fid: fid,
                    transfer_date: transferDate,
                    maker_no: makerNo,
                    maker_name: makerName
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        showTemporaryMessage(response.message, true);
                        $('#transferProcessModal_' + fid).modal('hide');
                        var _newDateFmt = transferDate.replace(/-/g, '/');
                        if (Array.isArray(fullDataset)) {
                            fullDataset.forEach(function(item) {
                                if (item && item.bom_ing_fid && String(item.bom_ing_fid).split(',').some(function(id){ return String(id).trim()===String(fid); })) {
                                    item.processing_state = 'ing';
                                    item.outsource_date = _newDateFmt;
                                    item.maker_id = makerName;
                                    delete _rowDetailCache[item.bom];
                                }
                            });
                        }
                        // 樂觀更新 window.ingActiveMap（發單日欄位讀取此資料）
                        if (window.ingActiveMap) {
                            Object.keys(window.ingActiveMap).forEach(function(bom) {
                                if (Array.isArray(window.ingActiveMap[bom])) {
                                    window.ingActiveMap[bom].forEach(function(p) {
                                        if (String(p.bom_ing_fid || '') === String(fid)) {
                                            p.processing_state = 'ing';
                                            p.outsource_date = _newDateFmt;
                                            p.maker_id = makerName;
                                        }
                                    });
                                }
                            });
                        }
                        isSelectFocused = false; isTextareaFocused = false;
                        isUpdatingOrderId = false; isPriorityUpdating = false;
                        processAndRenderData();
                        fetchDataAndFilter();
                    } else {
                        alert('移轉失敗：' + (response.message || '未知錯誤'));
                        $button.prop('disabled', false).text('確認移轉');
                    }
                },
                error: function() {
                    alert('與伺服器通訊失敗，無法完成移轉。');
                    $button.prop('disabled', false).text('確認移轉');
                }
            });
        });

        // 綁定按鈕事件
        document.getElementById('cancel-edit-bom').onclick = function() {
            formContainer.remove();
            buttonElement.scrollIntoView({
                behavior: 'smooth',
                block: 'center'
            }); // 捲動回按鈕
        };

        // 重新綁定訂單按鈕
        var rebindOrderBomBtn = document.getElementById('rebind-order-bom');
        if (rebindOrderBomBtn) {
            rebindOrderBomBtn.onclick = function() {
                var orderSelect = formContainer.querySelector('select[name="Order_id"]');
                if (orderSelect) {
                    orderSelect.value = '';
                    showTemporaryMessage('已清空訂單綁定，請重新選擇訂單後按「確定修改」', true);
                } else {
                    alert('找不到訂單下拉選單，請確認此 BOM 有可選訂單。');
                }
            };
        }

        // 取消移轉按鈕（在更新表單內，針對 ing 狀態的製程）
        var cancelTransferEditBtn = document.getElementById('cancel-transfer-edit-bom');
        if (cancelTransferEditBtn) {
            cancelTransferEditBtn.onclick = function() {
                var fids = String(rowData.bom_ing_fid || '').split(',').map(function(s){ return s.trim(); }).filter(Boolean);
                if (!fids.length) {
                    alert('此 BOM 目前沒有有效的製程記錄 (bom_ing_fid)，無法操作');
                    return;
                }
                var trimmedState = String(rowData.processing_state || '').trim();
                // 狀態 N：無反應
                if (trimmedState === 'N' || trimmedState === '') {
                    alert('目前已是最初狀態(N)，無須回歸。');
                    return;
                }
                // 狀態 P：提示由QC回報，不做回歸
                if (trimmedState === 'P') {
                    alert('本狀態由QC回報，不可手動回歸。');
                    return;
                }
                // 狀態 ing → N，Q → ing，狀態 E → P
                var targetState = (trimmedState === 'ing') ? 'N' : (trimmedState === 'Q') ? 'ing' : (trimmedState === 'E') ? 'P' : null;
                if (!targetState) {
                    alert('此狀態(' + trimmedState + ')不支援回歸。');
                    return;
                }
                var fid = fids[0];
                var bomSn = rowData.bom_sn ? String(rowData.bom_sn).split(',')[0] : '-';
                var processName = rowData.ProcessName ? String(rowData.ProcessName).split('/')[0] : '-';
                var makerName = rowData.maker_id ? String(rowData.maker_id).split('/')[0] : '未設定';
                var outsourceDate = rowData.outsource_date || '未設定';

                // 建立確認彈窗
                var existConfirm = document.getElementById('cancel-transfer-confirm-pop');
                if (existConfirm) existConfirm.remove();
                var pop = document.createElement('div');
                pop.id = 'cancel-transfer-confirm-pop';
                pop.style.cssText = 'position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;border:2px solid #dc3545;border-radius:6px;padding:20px;z-index:10099;min-width:340px;box-shadow:0 4px 20px rgba(0,0,0,.35);';
                pop.innerHTML =
                    '<h5 style="color:#dc3545;margin-top:0;"><i class="fa fa-exclamation-triangle"></i> 確認回歸前一狀態</h5>' +
                    '<table style="width:100%;font-size:13px;border-collapse:collapse;margin-bottom:14px;">' +
                    '<tr><td style="padding:3px 8px;color:#555;width:90px;">BOM</td><td style="padding:3px 8px;font-weight:bold;">' + escapeHtml(rowData.bom) + '</td></tr>' +
                    '<tr><td style="padding:3px 8px;color:#555;">SN</td><td style="padding:3px 8px;">' + escapeHtml(bomSn) + '</td></tr>' +
                    '<tr><td style="padding:3px 8px;color:#555;">製程</td><td style="padding:3px 8px;">' + escapeHtml(processName) + '</td></tr>' +
                    '<tr><td style="padding:3px 8px;color:#555;">廠商</td><td style="padding:3px 8px;">' + escapeHtml(makerName) + '</td></tr>' +
                    '<tr><td style="padding:3px 8px;color:#555;">外包日期</td><td style="padding:3px 8px;">' + escapeHtml(outsourceDate) + '</td></tr>' +
                    '<tr><td style="padding:3px 8px;color:#555;">目前狀態</td><td style="padding:3px 8px;font-weight:bold;">' + escapeHtml(trimmedState) + ' → ' + escapeHtml(targetState) + '</td></tr>' +
                    '</table>' +
                    (trimmedState === 'ing' ? '<p style="font-size:12px;color:#721c24;margin-bottom:14px;">回歸後 processing_state → N，外包日期清空，廠商清空。</p>' : '<p style="font-size:12px;color:#721c24;margin-bottom:14px;">回歸後 processing_state → P。</p>') +
                    '<div style="display:flex;justify-content:flex-end;gap:8px;">' +
                    '<button type="button" id="ctc-no" class="btn btn-default">取消</button>' +
                    '<button type="button" id="ctc-yes" class="btn btn-danger">確認回歸</button>' +
                    '</div>';
                document.body.appendChild(pop);
                document.getElementById('ctc-no').onclick = function() { pop.remove(); };
                document.getElementById('ctc-yes').onclick = function() {
                    pop.remove();
                    $.ajax({
                        url: '', type: 'POST',
                        data: { action: 'cancel_transfer', bom_ing_fid: fid },
                        dataType: 'json',
                        success: function(res) {
                            if (res.no_action) {
                                alert('目前已是最初狀態(N)，無反應。');
                                return;
                            }
                            if (res.qc_state) {
                                alert('本狀態由QC回報，不可手動回歸。');
                                return;
                            }
                            if (res.success) {
                                showTemporaryMessage('已回歸至前一狀態(' + (res.new_state||'?') + ')', true);
                                var item = fullDataset.find(function(i){ return i.bom === rowData.bom; });
                                if (item) {
                                    item.processing_state = res.new_state;
                                    if (res.new_state === 'N') {
                                        item.maker_id = null;
                                        item.maker_id_no = null;
                                        item.outsource_date = null;
                                    } else if (res.new_state === 'ing') {
                                        item.return_date = null;
                                    }
                                }
                                formContainer.remove();
                                processAndRenderData();
                            } else {
                                showTemporaryMessage('操作失敗：' + (res.message || '未知'), false);
                            }
                        },
                        error: function() { alert('伺服器通訊失敗'); }
                    });
                };
            };
        }

        const submitEndBomBtn = document.getElementById('submit-end-bom');
        if (submitEndBomBtn) {
            submitEndBomBtn.onclick = function() {
                // 未完成製程清單確認彈窗：列出尚未轉移完成/未標記跳過的製程，要求填寫原因才允許結案
                var _stStMapForClose = {N:'待發包',P:'待移轉',ing:'加工中',Q:'QC待驗',E:'已結',1:'已結',skip:'跳過'};
                var _showUnfinishedProcessConfirm = function(unfinished) {
                    var existPop = document.getElementById('unfinished-close-confirm-pop');
                    if (existPop) existPop.remove();
                    var rowsHtml = (unfinished || []).map(function(u) {
                        return '<tr><td style="padding:3px 8px;">' + escapeHtml(u.bom_sn) + '</td>'
                             + '<td style="padding:3px 8px;">' + escapeHtml(u.ProcessName || u.process_no || '') + '</td>'
                             + '<td style="padding:3px 8px;">' + escapeHtml(_stStMapForClose[u.processing_state] || u.processing_state) + '</td></tr>';
                    }).join('');
                    var pop = document.createElement('div');
                    pop.id = 'unfinished-close-confirm-pop';
                    pop.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.65);display:flex;justify-content:center;align-items:center;z-index:99999;';
                    pop.innerHTML =
                        '<div style="background:#fff;border-radius:8px;padding:24px;max-width:480px;width:92%;max-height:80vh;overflow:auto;box-shadow:0 6px 28px rgba(0,0,0,0.35);">'
                        + '<h4 style="color:#c0392b;margin:0 0 10px;"><i class="fa fa-exclamation-triangle"></i>&nbsp;此 BOM 尚有未完成製程</h4>'
                        + '<p style="margin:0 0 10px;font-size:12px;color:#555;">以下製程尚未轉移完成，也未標記跳過。系統不會自動幫忙補轉移，若確定要結案，請填寫原因：</p>'
                        + '<table style="width:100%;font-size:12px;border-collapse:collapse;margin-bottom:12px;border:1px solid #eee;">'
                        + '<thead><tr style="background:#f7f7f7;"><th style="padding:3px 8px;text-align:left;">序號</th><th style="padding:3px 8px;text-align:left;">製程</th><th style="padding:3px 8px;text-align:left;">狀態</th></tr></thead>'
                        + '<tbody>' + rowsHtml + '</tbody></table>'
                        + '<textarea id="unfinished-close-reason" rows="2" style="width:100%;padding:6px;font-size:13px;border:1px solid #ccc;border-radius:4px;" placeholder="請輸入結案原因（例如：客戶取消該製程、趕件已改製程）"></textarea>'
                        + '<div style="display:flex;justify-content:flex-end;gap:8px;margin-top:14px;">'
                        + '<button type="button" id="ufc-cancel" class="btn btn-default">取消</button>'
                        + '<button type="button" id="ufc-confirm" class="btn btn-danger">確認仍要結案</button>'
                        + '</div></div>';
                    document.body.appendChild(pop);
                    document.getElementById('ufc-cancel').onclick = function() { pop.remove(); };
                    document.getElementById('ufc-confirm').onclick = function() {
                        var reason = (document.getElementById('unfinished-close-reason').value || '').trim();
                        if (!reason) { alert('請輸入結案原因才能繼續'); return; }
                        pop.remove();
                        _execClose(reason);
                    };
                };

                // 實際執行結案的 AJAX
                var _execClose = function(closeReason) {
                    $.ajax({
                        url: '../../src/store/_end_bom_manual.php',
                        type: 'POST',
                        data: { bom: rowData.bom, close_reason: closeReason || '' },
                        dataType: 'json',
                        success: function(response) {
                            if (response && response.need_confirmation) {
                                _showUnfinishedProcessConfirm(response.unfinished);
                                return;
                            }
                            if (response && response.success) {
                                showTemporaryMessage(response.message || 'BOM 已手動結案！', true);
                                formContainer.remove();
                                const indexToRemove = fullDataset.findIndex(function(item){ return item.bom === rowData.bom; });
                                if (indexToRemove > -1) fullDataset.splice(indexToRemove, 1);
                                processAndRenderData();
                                fetchDataAndFilter();
                            } else {
                                showTemporaryMessage('結案失敗：' + (response ? response.message : '未知錯誤'), false);
                            }
                        },
                        error: function() { alert('與伺服器通訊失敗！'); }
                    });
                };

                if (window.isRD) {
                    // R+D 權限：顯示二次確認 Modal，需手動輸入大寫 Y
                    var _removeRdModal = function() {
                        var m = document.getElementById('rd-close-confirm-modal');
                        if (m) m.parentNode.removeChild(m);
                    };
                    _removeRdModal();
                    var _rdModal = document.createElement('div');
                    _rdModal.id = 'rd-close-confirm-modal';
                    _rdModal.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.65);display:flex;justify-content:center;align-items:center;z-index:99999;';
                    _rdModal.innerHTML =
                        '<div style="background:#fff;border-radius:8px;padding:28px 24px;max-width:420px;width:92%;box-shadow:0 6px 28px rgba(0,0,0,0.35);">'
                        + '<h4 style="color:#c0392b;margin:0 0 14px;"><i class="fa fa-exclamation-triangle"></i>&nbsp;二次確認：人工結案</h4>'
                        + '<p style="margin:0 0 8px;font-size:13px;">即將結案 BOM：<strong>' + escapeHtml(rowData.bom) + '</strong></p>'
                        + '<p style="margin:0 0 16px;color:#555;font-size:12px;">此操作不可逆，結案後 ERP 系統仍需另行結案。<br>請在下方輸入大寫英文字母&nbsp;<strong style="font-size:15px;color:#c0392b;">Y</strong>&nbsp;以確認：</p>'
                        + '<input type="text" id="rd-close-y-input"'
                        + ' autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false"'
                        + ' style="width:100%;padding:10px;font-size:22px;text-align:center;letter-spacing:6px;border:2px solid #e74c3c;border-radius:6px;margin-bottom:18px;">'
                        + '<div style="display:flex;justify-content:flex-end;gap:8px;">'
                        + '<button type="button" id="rd-close-cancel-btn" class="btn btn-default">取消</button>'
                        + '<button type="button" id="rd-close-confirm-btn" class="btn btn-danger">確認結案</button>'
                        + '</div></div>';
                    document.body.appendChild(_rdModal);
                    document.getElementById('rd-close-cancel-btn').onclick = _removeRdModal;
                    document.getElementById('rd-close-confirm-btn').onclick = function() {
                        var val = (document.getElementById('rd-close-y-input').value || '');
                        if (val !== 'Y') { alert('請輸入大寫英文字母 Y 以確認結案'); return; }
                        _removeRdModal();
                        _execClose();
                    };
                    // 禁止自動填入：監聽 input 事件，強制清除瀏覽器 autocomplete 殘留
                    document.getElementById('rd-close-y-input').addEventListener('input', function() {
                        // 不做任何額外處理，讓使用者完整手動輸入
                    });
                    setTimeout(function() { var inp = document.getElementById('rd-close-y-input'); if (inp) inp.focus(); }, 60);
                } else {
                    if (confirm('您確定要手動結案 BOM: ' + rowData.bom + ' 嗎？\n 注意：ERP系統仍需結案。')) {
                        _execClose();
                    }
                }
            };
        }

        document.getElementById('submit-edit-bom').onclick = function() {
            const newClientName = document.getElementById('edit-client-name').value;
            const newBomPs = document.getElementById('edit-form-bom-ps').value;

            // 備庫 checkbox 判斷
            var isStock = document.getElementById('edit-stock-cb') && document.getElementById('edit-stock-cb').checked;

            // 收集勾選的訂單
            var checkedOrders = [];
            var orderListContainer = document.getElementById('edit-form-order-list-container');
            if (!isStock && orderListContainer) {
                var checkboxes = orderListContainer.querySelectorAll('input.order-bind-cb:checked');
                checkboxes.forEach(function(cb) {
                    var row = cb.closest('tr');
                    var pcsInput = row.querySelector('input.order-bind-pcs');
                    var pcs = pcsInput ? parseInt(pcsInput.value) : 0;
                    if (pcs > 0) {
                        checkedOrders.push({
                            order_id: cb.value,
                            pcs: pcs
                        });
                    }
                });
            }

            var orderPcsJson = JSON.stringify(checkedOrders);
            var newSelectedOrderId = isStock ? 'B' : ((checkedOrders.length > 0) ? checkedOrders[0].order_id : 'B');

            // --- 前端日誌記錄與檢查 ---
            console.log("準備提交修改。rowData.bom:", rowData.bom,
                "客戶名稱:", newClientName,
                "訂單ID:", newSelectedOrderId);
            if (!rowData.bom || String(rowData.bom).trim() === "") {
                alert("前端錯誤：BOM 號碼為空，無法提交！請檢查資料來源。");
                console.error("前端錯誤：rowData.bom 為空或未定義。");
                return; // 如果 BOM 為空，則停止提交
            }
            // --- 前端日誌記錄與檢查結束 ---

            // AJAX 提交到後端
            $.ajax({
                url: '', // 指向自身檔案，使其呼叫本檔案內的後端邏輯
                type: 'POST',
                data: {
                    action: 'update_bom_info', // 新增 action 參數以觸發後端 PHP 邏輯
                    bom: rowData.bom, // BOM number is the key for the bom table
                    client_name: newClientName,
                    d_setting_id: rowData.d_setting_id, // 傳遞料號ID以確保關聯
                    order_pcs_json: orderPcsJson, // 傳遞多筆訂單設定
                    bom_ps: newBomPs
                },
                dataType: 'json',
                success: function(response) {
                    if (response && response.success) { // Check if response and response.success are defined
                        showTemporaryMessage(response.message || '資料更新成功！', true); // 改用 showTemporaryMessage
                        formContainer.remove();
                        // 更新 fullDataset 中的對應行數據
                        fullDataset.forEach(item => {
                            if (item.bom === rowData.bom) {
                                item.Client_Name = newClientName;
                                item.Order_id = newSelectedOrderId; // Update the selected Order_id for this BOM
                                // 注意：OrderList 不會自動更新，需重新整理或重新 fetch
                                item.bom_ALL_bom_ps = newBomPs;
                            
                            }
                        });
                        processAndRenderData(); // 重新渲染表格
                        setTimeout(() => buttonElement.scrollIntoView({
                            behavior: 'smooth',
                            block: 'center'
                        }), 100); // 延遲捲動
                    } else {
                        showTemporaryMessage('更新失敗：' + (response && response.message ? response.message : '未知錯誤'), false); // 改用 showTemporaryMessage
                    }
                },
                error: function() {
                    alert('與伺服器通訊失敗！');
                }
            });
        };
    }

    // New function to confirm deletion before calling deleteBomIng
    window.confirmDeleteBomIng = function(bomIngIdToDelete, bomOfEditedItem, bomSnOfEditedItem, processName) {
        if (confirm(`您確定要刪除此製程項目 (ID: ${bomIngIdToDelete}) 嗎？此操作無法復原。`)) {
            deleteBomIng(bomIngIdToDelete, bomOfEditedItem, bomSnOfEditedItem, processName);
        } else {
            console.log("Deletion cancelled by user for bom_ing_id:", bomIngIdToDelete);
        }
    }

    // Function to prompt for skip reason and confirm before marking a process as skipped
    window.confirmMarkSkip = function(bomIngFid, bomOfEditedItem, bomSnOfEditedItem, processName) {
        var reason = prompt('請輸入跳過原因（例如：趕件改製程、客戶取消該製程、漏送）：');
        if (reason === null) return;
        reason = reason.trim();
        if (!reason) {
            alert('請輸入跳過原因才能標記跳過');
            return;
        }
        if (confirm(`確定要將製程「${processName}」標記為跳過嗎？此製程將不計入進度計算。`)) {
            markSkipBomIng(bomIngFid, bomOfEditedItem, bomSnOfEditedItem, processName, reason);
        }
    }

    // Function to handle marking a bom_ing row as skipped (called after confirmMarkSkip)
    window.markSkipBomIng = function(bomIngFid, bomOfEditedItem, bomSnOfEditedItem, processName, reason) {
        if (!bomIngFid) {
            showTemporaryMessage('錯誤：缺少 bom_ing_fid！', false);
            return;
        }
        var _skipUrl = window.location.pathname.split('/').pop() || 'OreadyReply_ForPm_BaseOfTime2.php';
        $.ajax({
            url: _skipUrl,
            type: 'POST',
            data: {
                action: 'mark_skip',
                bom_ing_fid: bomIngFid,
                reason: reason
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    showTemporaryMessage('已標記跳過：' + (processName || '製程項目'), true);

                    var itemIndex = window.bomPSList.findIndex(p => String(p.bom_ing_fid) === String(bomIngFid));
                    if (itemIndex > -1) {
                        window.bomPSList[itemIndex].processing_state = 'skip';
                    }
                    if (Array.isArray(fullDataset)) {
                        fullDataset.forEach(function(item) {
                            if (item && item.bom_ing_fid && String(item.bom_ing_fid).split(',').some(function(id){ return String(id).trim()===String(bomIngFid).trim(); })) {
                                item.processing_state = 'skip';
                            }
                        });
                    }

                    refreshEditModalProcessList(bomOfEditedItem, bomSnOfEditedItem);
                    if (typeof processAndRenderData === 'function') processAndRenderData();
                } else {
                    showTemporaryMessage('標記跳過失敗：' + (response.message || '未知錯誤'), false);
                }
            },
            error: function() {
                showTemporaryMessage('與伺服器通訊失敗，無法標記跳過！', false);
            }
        });
    }

    // Function to handle bom_ing deletion (called by delete buttons in the dynamic list)
    window.deleteBomIng = function(bomIngIdToDelete, bomOfEditedItem, bomSnOfEditedItem, processName) {
        if (!bomIngIdToDelete) {
            showTemporaryMessage('錯誤：缺少 bom_ing_id！', false);
            return;
        }
        console.log("Attempting to delete bom_ing_id:", bomIngIdToDelete, "for BOM:", bomOfEditedItem, "main SN:", bomSnOfEditedItem, "Process Name:", processName);
        var _delUrl = window.location.pathname.split('/').pop() || 'OreadyReply_ForPm_BaseOfTime2.php';
        $.ajax({
            url: _delUrl,
            type: 'POST',
            data: {
                action: 'delete_bom_ing',
                bom_ing_fid: bomIngIdToDelete
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    showTemporaryMessage('已刪除 ' + (processName || '製程項目'), false);

                    // Remove from local bomPSList
                    const itemIndex = window.bomPSList.findIndex(p => String(p.bom_ing_fid) === String(bomIngIdToDelete));
                    if (itemIndex > -1) {
                        window.bomPSList.splice(itemIndex, 1);
                        console.log("Removed process from local bomPSList, FID:", bomIngIdToDelete);
                    } else {
                        console.warn("Process to delete (FID:", bomIngIdToDelete, ") not found in local bomPSList. List might not visually update correctly without a full refresh.");
                    }

                    // Refresh the modal list using the main item's BOM and SN
                    refreshEditModalProcessList(bomOfEditedItem, bomSnOfEditedItem);

                } else {
                    showTemporaryMessage('刪除失敗：' + (response.message || '未知錯誤'), false);
                }
            },
            error: function() {
                showTemporaryMessage('與伺服器通訊失敗，無法刪除製程項目！', false);
            }
        });
    }

    // --- 載入訂單列表至編輯表單（多筆綁定，含可綁餘量與超量警示）---
    function loadOrdersForEditForm(dId, currentBom, bomTotalQty, containerNode) {
        // 優先用傳入的 DOM 節點（避免 jQuery 在節點未掛入 DOM 時找不到），fallback 才用選取器
        var container = containerNode || document.getElementById('edit-form-order-list-container');
        var $container = $(container);
        $container.html('<div style="padding:10px;text-align:center;color:#999;font-size:12px;">載入中...</div>');

        // ── DEBUG: 顯示送出的查詢參數 ──
        var _editUrl = window.location.pathname.split('/').pop() || 'OreadyReply_ForPm_BaseOfTime2.php';
        // console.group('%c[訂單綁定] loadOrdersForEditForm 發送 AJAX', 'color:#0056b3;font-weight:bold;');
        // console.log('POST URL    :', _editUrl);
        // console.log('action      :', 'get_orders_for_edit');
        // console.log('d_id (送出) :', dId,  '← 後端用此值查 order_track.d_id_ID');
        // console.log('bom  (送出) :', currentBom);
        // console.log('SQL 預期    : SELECT ... FROM order_track WHERE d_id_ID =', dId);
        // console.groupEnd();

        $.post(_editUrl, {
            action: 'get_orders_for_edit',
            d_id: dId,
            bom: currentBom
        }, function(res) {
            // console.group('%c[訂單綁定] 後端回傳結果', 'color:#28a745;font-weight:bold;');
            // console.log('success        :', res.success);
            // console.log('strategy_used  :', res.strategy_used,  '(A=d_id_ID+JOIN, B=d_id_ID, C=d_id舊欄位)');
            // console.log('queried_value  :', res.queried_value);
            // console.log('strategy_errors:', res.strategy_errors);
            // console.log('orders 筆數    :', res.orders ? res.orders.length : 0);
            // if (res.orders && res.orders.length > 0) {
            //     console.table(res.orders.map(function(o){ return {Order_id:o.Order_id, Order_oo:o.Order_oo, Qty:o.Qty, available:o.available_qty_for_bind, is_bound:o.is_bound, my_allocated:o.my_allocated}; }));
            // }
            // console.groupEnd();

            // ── 料號 ID 不一致警示框 ──
            var warnHtml = '';
            if (res.mismatch_info) {
                var mi = res.mismatch_info;
                var mdmBase = '../pages/master_data_management.php';
                var linkBom = '<a href="' + mdmBase + '?open_part=' + mi.bom_d_setting_id + '" target="_blank" style="color:#856404;">BOM 料號（ID: ' + mi.bom_d_setting_id + '）</a>';
                var linkConflicts = mi.mismatched.map(function(m) {
                    return '<a href="' + mdmBase + '?open_part=' + m.d_id_ID + '" target="_blank" style="color:#856404;">衝突料號（ID: ' + m.d_id_ID + '）</a>';
                }).join('、');
                var linkSearch = '<a href="' + mdmBase + '?search=' + encodeURIComponent(mi.d_id_text) + '" target="_blank" style="color:#0056b3;">🔍 篩選「' + escapeHtml(mi.d_id_text) + '」的所有料號記錄</a>';
                warnHtml =
                    '<div style="border:1px solid #f0ad4e;background:#fff8e1;border-radius:4px;padding:9px 12px;margin-bottom:8px;">' +
                        '<div style="font-size:12px;font-weight:bold;color:#856404;margin-bottom:5px;">⚠ 偵測到料號 ID 不一致</div>' +
                        '<div style="font-size:11px;color:#533f03;margin-bottom:6px;">' +
                            '料號「' + escapeHtml(mi.d_id_text) + '」有 ' + mi.mismatched.length + ' 筆訂單使用了不同的料號 ID，導致部分訂單無法顯示於下方清單。' +
                        '</div>' +
                        '<div style="font-size:11px;margin-bottom:7px;line-height:1.8;">' +
                            linkBom + '　' + linkConflicts + '<br>' + linkSearch +
                        '</div>' +
                        '<div style="font-size:11px;color:#721c24;background:#f8d7da;border-radius:3px;padding:6px 9px;line-height:1.7;">' +
                            '📋 <strong>處理步驟：</strong>至主資料管理 → 點上方連結確認哪個 ID 是正確料號 →' +
                            ' 將錯誤料號的訂單、BOM 綁定<strong>移轉</strong>至正確料號 → 刪除多餘料號' +
                        '</div>' +
                    '</div>';
            }

            if (!res.success) {
                $container.html('<div style="padding:10px;color:red;font-size:12px;">訂單載入失敗：' + (res.message || '未知錯誤') + '</div>');
                return;
            }
            if (!res.orders || res.orders.length === 0) {
                $container.html(warnHtml + '<div style="padding:10px;text-align:center;color:#999;font-size:12px;">此料號無進行中的訂單。</div>');
                updateEditBoundQty();
                return;
            }

            var html = '<table class="table table-condensed table-bordered" style="margin-bottom:0;font-size:12px;">';
            html += '<thead><tr style="background:#f5f5f5;">' +
                    '<th width="28" class="text-center">✓</th>' +
                    '<th>訂單號</th>' +
                    '<th width="62">交期</th>' +
                    '<th width="46" class="text-center">總數</th>' +
                    '<th width="62" class="text-center" style="color:#1a6a1a;">可綁餘量</th>' +
                    '<th width="76" class="text-center">分配數量</th>' +
                    '</tr></thead><tbody>';

            res.orders.forEach(function(o) {
                var isChecked    = o.is_bound ? 'checked' : '';
                var inputValue   = (o.is_bound && o.my_allocated != null) ? o.my_allocated : '';
                // available_qty_for_bind = 訂單總數 - 其他BOM佔用（不含本BOM），已含本BOM可重新分配的份額
                var avail        = parseInt(o.available_qty_for_bind) || 0;
                var availDisplay = avail;
                var delDate      = o.Delivery_date ? o.Delivery_date.substring(5).replace('-', '/') : '-';
                var disabledAttr = (!o.is_bound && avail <= 0) ? 'disabled' : '';
                var availColor   = availDisplay > 0 ? '#1a7a1a' : '#dc3545';

                // ── 日期 & 數量比對高亮 ──
                // 解析 BOM 日期（B-YYYMMDD→西元）
                var bomDateMs = null;
                var bomMatch = currentBom.match(/^B-(\d{3})(\d{2})(\d{2})\d{3}$/);
                if (bomMatch) {
                    var by = parseInt(bomMatch[1]) + 1911;
                    var bm = parseInt(bomMatch[2]) - 1;
                    var bd = parseInt(bomMatch[3]);
                    bomDateMs = new Date(by, bm, bd).getTime();
                }
                // 解析訂單日期（OO-YYYMMDD）
                var ordDateMs = null;
                var ooVal = o.Order_oo || '';
                var ooMatch = ooVal.match(/OO(\d{3})(\d{2})(\d{2})\d{3}$/i);
                if (ooMatch) {
                    var oy = parseInt(ooMatch[1]) + 1911;
                    var om = parseInt(ooMatch[2]) - 1;
                    var od = parseInt(ooMatch[3]);
                    ordDateMs = new Date(oy, om, od).getTime();
                }
                // 數量比對
                var bomQtyN  = parseInt(bomTotalQty) || 0;
                var ordQtyN  = parseInt(o.Qty) || 0;
                var qtyExact = (bomQtyN > 0 && ordQtyN > 0 && bomQtyN === ordQtyN);
                var qtyClose = (!qtyExact && bomQtyN > 0 && ordQtyN > 0 && Math.abs(bomQtyN - ordQtyN) / bomQtyN <= 0.05);
                // 日期比對：訂單日期 <= BOM日期，且差距在30天內
                var dateOk = false;
                if (bomDateMs !== null && ordDateMs !== null) {
                    var diffDays = (bomDateMs - ordDateMs) / 86400000;
                    dateOk = (diffDays >= 0 && diffDays <= 30);
                }
                // 決定底色（日期相近條件成立才上色）
                var rowBg = '';
                if (dateOk) {
                    if (qtyExact)      rowBg = 'background:#d4edda;';  // 綠底
                    else if (qtyClose) rowBg = 'background:#fff3cd;';  // 淡橘底
                }
                // 原本 opacity 半透明樣式優先（餘量=0且未綁定）
                var rowStyle = (!o.is_bound && avail <= 0)
                    ? 'style="opacity:0.5;background:#fafafa;"'
                    : (rowBg ? 'style="' + rowBg + '"' : '');

                html += `<tr ${rowStyle}>
                    <td class="text-center">
                        <input type="checkbox" class="order-bind-cb" value="${o.Order_id}"
                               ${isChecked} ${disabledAttr}
                               data-available="${avail}"
                               data-avail-display="${availDisplay}"
                               data-order-qty="${o.Qty}">
                    </td>
                    <td style="white-space:nowrap;">
                        ${escapeHtml(o.Order_oo || '')}
                        ${o.Processing_items ? `<br><span style="color:#888;font-size:10px;line-height:1.3;display:block;">製程：${escapeHtml(o.Processing_items)}</span>` : ''}
                        ${o.Order_ps ? `<span style="color:#888;font-size:10px;line-height:1.3;display:block;">備註：${escapeHtml(o.Order_ps)}</span>` : ''}
                    </td>
                    <td class="text-center">${escapeHtml(delDate)}</td>
                    <td class="text-center">${escapeHtml(String(o.Qty || 0))}</td>
                    <td class="text-center">
                        <strong style="color:${availColor};">${availDisplay}</strong>
                    </td>
                    <td>
                        <input type="number" class="form-control input-sm order-bind-pcs"
                               value="${inputValue}"
                               style="height:22px;padding:2px 4px;width:62px;"
                               placeholder="數量" min="0" max="${availDisplay}">
                        <div class="order-bind-over-warn" style="color:red;font-size:10px;display:none;"></div>
                    </td>
                </tr>`;
            });
            html += '</tbody></table>';
            $container.html(warnHtml + html);

            // ── 勾選事件：自動填入建議數量 ──
            $container.find('.order-bind-cb').on('change', function() {
                var $row   = $(this).closest('tr');
                var $input = $row.find('.order-bind-pcs');
                var $warn  = $row.find('.order-bind-over-warn');
                var avail  = parseInt($(this).data('avail-display')) || 0;

                if ($(this).is(':checked')) {
                    var currentBound = getCurrentBoundTotal();
                    var bomNeed      = parseInt($('#edit-bom-qty-display').text()) || 0;
                    var needed       = Math.max(0, bomNeed - currentBound);
                    var fillVal      = needed > 0 ? Math.min(avail, needed) : avail;
                    if (fillVal <= 0) fillVal = avail;
                    $input.val(fillVal);
                    $warn.hide().text('');
                } else {
                    $input.val('');
                    $warn.hide().text('');
                }
                updateEditBoundQty();
            });

            // ── 數量輸入事件：超量警示 ──
            $container.find('.order-bind-pcs').on('input keyup', function() {
                var $row   = $(this).closest('tr');
                var $cb    = $row.find('.order-bind-cb');
                var $warn  = $row.find('.order-bind-over-warn');
                var avail  = parseInt($cb.data('avail-display')) || 0;
                var orderQty = parseInt($cb.data('order-qty')) || 0;
                var val    = parseInt($(this).val()) || 0;

                if ($cb.is(':checked') && avail > 0 && val > avail) {
                    $warn.text('⚠ 超過可綁餘量（' + avail + '）').show();
                } else if ($cb.is(':checked') && orderQty > 0 && val > orderQty) {
                    $warn.text('⚠ 超過訂單總數（' + orderQty + '）').show();
                } else {
                    $warn.hide().text('');
                }
                updateEditBoundQty();
            });

            updateEditBoundQty();

        }, 'json').fail(function(xhr, status, err) {
            console.error('[訂單綁定] AJAX 請求失敗', 'status:', status, 'error:', err, 'responseText:', xhr.responseText);
            $container.html('<div style="padding:10px;color:red;font-size:12px;">訂單載入失敗（HTTP ' + xhr.status + '），請開啟 F12 Console 查看詳細錯誤。</div>');
        });
    }

    function getCurrentBoundTotal() {
        var total = 0;
        $('#edit-form-order-list-container input.order-bind-pcs').each(function() {
            var val = parseInt($(this).val());
            if (!isNaN(val) && val > 0 && $(this).closest('tr').find('.order-bind-cb').is(':checked')) {
                total += val;
            }
        });
        return total;
    }

    function updateEditBoundQty() {
        var total   = getCurrentBoundTotal();
        var bomQty  = parseInt($('#edit-bom-qty-display').text()) || 0;
        var $disp   = $('#edit-bound-qty-display');
        var $warn   = $('#edit-bound-qty-warn');

        $disp.text(total);

        if (total === 0) {
            $disp.css('color', 'gray');
            $warn.hide().text('');
        } else if (total === bomQty) {
            $disp.css('color', 'green');
            $warn.hide().text('');
        } else if (total < bomQty) {
            $disp.css('color', '#e67e00');
            $warn.text('⚠ 分配未達 BOM 總數（差 ' + (bomQty - total) + '）').show();
        } else {
            $disp.css('color', 'red');
            $warn.text('⚠ 分配超過 BOM 總數（超 ' + (total - bomQty) + '）').show();
        }
    }

    // --- Function to open Light Setting Modal ---
    function openLightSettingModal() {
        var existingModal = document.getElementById('light-setting-modal');
        if (existingModal) existingModal.remove();

        var savedYellow      = window.settingYellowDays || '';
        var savedRed         = window.settingRedDays || '';
        var savedProcess     = window.settingProcessDays || '';
        var savedRedDays     = window.settingRedDaysBefore || '';
        var savedShowWorkday = window.settingShowWorkday || false;
        var savedBufferMode  = window.bufferModeEnabled || false;

        var modalHtml = '<div id="light-setting-modal" style="position:fixed;top:0;left:0;width:100%;height:100%;background-color:rgba(0,0,0,0.5);display:flex;justify-content:center;align-items:center;z-index:10060;">' +
            '<div style="background-color:white;padding:20px;border-radius:5px;box-shadow:0 2px 10px rgba(0,0,0,0.1);width:390px;max-width:95%;">' +
            '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">' +
            '<h4 style="margin:0;">設定燈號與製程天數</h4>' +
            '<button type="button" class="close" onclick="document.getElementById(&quot;light-setting-modal&quot;).remove();" style="font-size:1.5rem;background:none;border:none;cursor:pointer;">&times;</button>' +
            '</div>' +
            '<div style="margin-bottom:8px;font-size:12px;color:#555;font-weight:bold;">燈號設定</div>' +
            '<div class="form-group" style="display:flex;align-items:center;margin-bottom:10px;">' +
            '<label style="width:120px;text-align:right;margin-right:10px;"><figure class="circle_y" style="display:inline-block;vertical-align:middle;margin:0;"></figure><span style="vertical-align:middle;"> 進度低於</span></label>' +
            '<div style="display:flex;align-items:center;"><input type="number" id="input-setting-yellow" class="form-control" style="width:80px;" value="' + savedYellow + '" placeholder="30-100"><span style="margin-left:5px;">%</span></div>' +
            '</div>' +
            '<div class="form-group" style="display:flex;align-items:center;margin-bottom:10px;">' +
            '<label style="width:120px;text-align:right;margin-right:10px;"><figure class="circle_red" style="display:inline-block;vertical-align:middle;margin:0;"></figure><span style="vertical-align:middle;"> 進度低於</span></label>' +
            '<div style="display:flex;align-items:center;"><input type="number" id="input-setting-red" class="form-control" style="width:80px;" value="' + savedRed + '" placeholder="30-100"><span style="margin-left:5px;">%</span></div>' +
            '</div>' +
            '<div class="form-group" style="display:flex;align-items:center;margin-bottom:10px;">' +
            '<label style="width:120px;text-align:right;margin-right:10px;">(過交期前轉燈)</label>' +
            '<input type="number" id="input-setting-red-days" class="form-control" style="width:100px;" value="' + savedRedDays + '" title="只能填寫 5 或 10">' +
            '</div>' +
            '<div class="form-group" style="display:flex;align-items:center;margin-bottom:10px;">' +
            '<label style="width:120px;text-align:right;margin-right:10px;">設定每關工作天數</label>' +
            '<input type="number" id="input-setting-process" class="form-control" style="width:100px;" value="' + savedProcess + '" placeholder="1-20">' +
            '</div>' +
            '<div class="form-group" style="display:flex;align-items:center;margin-bottom:10px;">' +
            '<label style="width:120px;text-align:right;margin-right:10px;">顯示工作天數</label>' +
            '<input type="checkbox" id="input-setting-show-workday" ' + (savedShowWorkday ? 'checked' : '') + '>' +
            '</div>' +
            '<div style="display:flex;justify-content:center;margin-top:20px;gap:10px;">' +
            '<button type="button" class="btn btn-default" onclick="document.getElementById(&quot;light-setting-modal&quot;).remove();">取消</button>' +
            '<button type="button" id="btn-confirm-light-setting" class="btn btn-primary">確認</button>' +
            '</div></div></div>';

        document.body.insertAdjacentHTML('beforeend', modalHtml);

        function validateIntRange(val, min, max) {
            if (val === '') return true;
            var num = Number(val);
            if (isNaN(num) || !Number.isInteger(num) || num < min || num > max) return false;
            return true;
        }

        document.getElementById('btn-confirm-light-setting').addEventListener('click', function() {
            var yInput  = document.getElementById('input-setting-yellow');
            var rInput  = document.getElementById('input-setting-red');
            var rdInput = document.getElementById('input-setting-red-days');
            var pInput  = document.getElementById('input-setting-process');
            var showWorkdayInput = document.getElementById('input-setting-show-workday');
            // bufferMode UI 已移除

            var yVal  = yInput.value.trim();
            var rVal  = rInput.value.trim();
            var rdVal = rdInput.value.trim();
            var pVal  = pInput.value.trim();

            if (!validateIntRange(yVal, 30, 100))  { alert('黃燈設定必須是 30 到 100 之間的整數'); yInput.focus(); return; }
            if (!validateIntRange(rVal, 30, 100))  { alert('紅燈設定必須是 30 到 100 之間的整數'); rInput.focus(); return; }
            if (rdVal !== '' && rdVal !== '5' && rdVal !== '10') { alert('過交期前轉紅燈天數只能填寫 5 或 10'); rdInput.focus(); return; }
            if (!validateIntRange(pVal, 1, 20))    { alert('每關工作天數必須是 1 到 20 之間的整數'); pInput.focus(); return; }

            var newBufferMode = false; // 緩衝比燈號已移除

            $.ajax({
                url: '', type: 'POST',
                data: {
                    action: 'update_system_params',
                    yellow: yVal, red: rVal, red_days_before: rdVal,
                    process: pVal, show_workday: showWorkdayInput.checked,
                    buffer_mode: newBufferMode
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        window.settingYellowDays    = yVal;
                        window.settingRedDays       = rVal;
                        window.settingRedDaysBefore = rdVal;
                        window.settingProcessDays   = pVal;
                        window.settingShowWorkday   = showWorkdayInput.checked;
                        showTemporaryMessage('設定已儲存', true);
                        document.getElementById('light-setting-modal').remove();
                        updateOutsourceDateHeader();
                        processAndRenderData();
                    } else {
                        alert('儲存失敗: ' + response.message);
                    }
                },
                error: function() { alert('通訊失敗'); }
            });
        });

        var modalElement = document.getElementById('light-setting-modal');
        if (modalElement) {
            modalElement.addEventListener('click', function(event) {
                if (event.target === modalElement) modalElement.remove();
            });
        }
    }

        // --- Function to open Edit Customer Modal ---
    function openEditCustomerModal(customerId, currentName, currentAddr) {
        const modalId = 'edit-customer-modal';
        const existingModal = document.getElementById(modalId);
        if (existingModal) existingModal.remove();

        const modalHtml = `
            <div id="${modalId}" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); display: flex; justify-content: center; align-items: center; z-index: 10080;">
                <div style="background-color: white; border-radius: 5px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); width: 400px; max-width: 90%; padding: 20px;">
                    <h4 style="margin-top: 0; margin-bottom: 15px;">修改客戶資料</h4>
                    <div class="form-group">
                        <label>客戶代碼</label>
                        <input type="text" class="form-control" value="${escapeHtml(customerId)}" disabled>
                    </div>
                    <div class="form-group">
                        <label>客戶名稱</label>
                        <input type="text" id="edit-cust-name" class="form-control" value="${escapeHtml(currentName)}">
                    </div>
                    <div class="form-group">
                        <label>客戶地址</label>
                        <input type="text" id="edit-cust-addr" class="form-control" value="${escapeHtml(currentAddr)}">
                    </div>
                    <div style="text-align: right; margin-top: 20px;">
                        <button type="button" class="btn btn-default" onclick="document.getElementById('${modalId}').remove();">取消</button>
                        <button type="button" id="btn-save-cust-info" class="btn btn-primary">儲存</button>
                    </div>
                </div>
            </div>
        `;
        document.body.insertAdjacentHTML('beforeend', modalHtml);

        document.getElementById('btn-save-cust-info').addEventListener('click', function() {
            const newName = document.getElementById('edit-cust-name').value.trim();
            const newAddr = document.getElementById('edit-cust-addr').value.trim();
            
            if(!newName) {
                alert('名稱不可為空');
                return;
            }

            $.ajax({
                url: '',
                type: 'POST',
                data: {
                    action: 'update_customer_data',
                    customer_id: customerId,
                    customer_name: newName,
                    customer_address: newAddr
                },
                dataType: 'json',
                success: function(res) {
                    if(res.success) {
                        showTemporaryMessage('客戶資料已更新', true);
                        document.getElementById(modalId).remove();
                        
                        // Update the row in sales setting table if it exists
                        const nameDiv = document.querySelector(`.customer-cell[data-cid="${customerId}"]`);
                        if(nameDiv) {
                            nameDiv.textContent = newName;
                            nameDiv.dataset.cname = newName;
                            nameDiv.dataset.caddr = newAddr;
                            // Update address div sibling
                            const td = nameDiv.closest('td');
                            const addrDiv = td.querySelector('.customer-addr');
                            if(addrDiv) {
                                addrDiv.textContent = newAddr;
                            } else if (newAddr) {
                                // Create if didn't exist
                                const div = document.createElement('div');
                                div.className = 'customer-addr';
                                div.style.cssText = 'font-size: 0.85em; color: #999;';
                                div.textContent = newAddr;
                                td.appendChild(div);
                            }
                        }
                    } else {
                        alert('更新失敗: ' + res.message);
                    }
                },
                error: function() {
                    alert('通訊失敗');
                }
            });
        });
    }

    // --- Function to open Add Customer Modal ---
    function openAddCustomerModal(onSuccessCallback) {
        const modalId = 'add-customer-modal';
        const existingModal = document.getElementById(modalId);
        if (existingModal) existingModal.remove();

        const modalHtml = `
            <div id="${modalId}" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); display: flex; justify-content: center; align-items: center; z-index: 10080;">
                <div style="background-color: white; border-radius: 5px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); width: 400px; max-width: 90%; padding: 20px;">
                    <h4 style="margin-top: 0; margin-bottom: 15px;">新增客戶</h4>
                    <div class="form-group">
                        <label>客戶代碼</label>
                        <input type="text" id="add-cust-id" class="form-control" placeholder="請輸入客戶代碼">
                    </div>
                    <div class="form-group">
                        <label>客戶名稱</label>
                        <input type="text" id="add-cust-name" class="form-control" placeholder="請輸入客戶名稱">
                    </div>
                    <div class="form-group">
                        <label>客戶地址</label>
                        <input type="text" id="add-cust-addr" class="form-control" placeholder="請輸入客戶地址">
                    </div>
                    <div style="text-align: right; margin-top: 20px;">
                        <button type="button" class="btn btn-default" onclick="document.getElementById('${modalId}').remove();">取消</button>
                        <button type="button" id="btn-submit-add-cust" class="btn btn-success">新增</button>
                    </div>
                </div>
            </div>
        `;
        document.body.insertAdjacentHTML('beforeend', modalHtml);

        document.getElementById('btn-submit-add-cust').addEventListener('click', function() {
            const id = document.getElementById('add-cust-id').value.trim();
            const name = document.getElementById('add-cust-name').value.trim();
            const addr = document.getElementById('add-cust-addr').value.trim();

            if (!id || !name) {
                alert('代碼與名稱不可為空');
                return;
            }

            $.ajax({
                url: '',
                type: 'POST',
                data: {
                    action: 'add_new_customer',
                    customer_id: id,
                    customer_name: name,
                    customer_address: addr
                },
                dataType: 'json',
                success: function(res) {
                    if (res.success) {
                        showTemporaryMessage('客戶已新增', true);
                        document.getElementById(modalId).remove();
                        
                        // 執行回調
                        if (typeof onSuccessCallback === 'function') {
                            onSuccessCallback({id: id, name: name});
                        }

                        // Refresh sales setting table by reopening it
                        const salesModal = document.getElementById('sales-setting-modal');
                        if(salesModal) {
                            salesModal.remove();
                            openSalesSettingModal();
                        }
                    } else {
                        alert('新增失敗: ' + res.message);
                    }
                }
            });
        });
    }

    // --- Function to open Sales Setting Modal ---
    function openSalesSettingModal() {
        const existingModal = document.getElementById('sales-setting-modal');
        if (existingModal) existingModal.remove();

        const modalHtml = `
            <div id="sales-setting-modal" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); display: flex; justify-content: center; align-items: center; z-index: 10060;">
                <div style="background-color: white; border-radius: 8px; box-shadow: 0 4px 20px rgba(0,0,0,0.2); width: 700px; max-width: 95%; max-height: 90vh; display: flex; flex-direction: column; overflow: hidden;">
                    
                    <!-- Header -->
                    <div style="padding: 15px 20px; background-color: #f8f9fa; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center;">
                        <div style="display: flex; align-items: center; gap: 15px;">
                            <h4 style="margin: 0; color: #333; font-weight: bold;">業務配置設定</h4>
                            <button type="button" id="btn-add-customer" class="btn btn-success btn-sm">新增客戶</button>
                            <button type="button" id="btn-manage-invalid-customers" class="btn btn-default btn-sm">設定無效客戶</button>
                        </div>
                        <button type="button" class="close" onclick="document.getElementById('sales-setting-modal').remove();" style="font-size: 1.5rem; background: none; border: none; cursor: pointer;">&times;</button>
                    </div>

                    <!-- Body -->
                    <div style="padding: 20px; overflow-y: auto; flex: 1;">
                        
                        <!-- Sales Unit Setting Section -->
                        <div style="background-color: #eef2f7; padding: 15px; border-radius: 6px; margin-bottom: 20px; border: 1px solid #dce4ec;">
                            <label style="font-weight: bold; margin-bottom: 8px; display: block; color: #555;">設定業務單位 (部門)</label>
                            <div style="display: flex; gap: 10px;">
                                <select id="sales-unit-select" class="form-control input-sm" style="flex: 1;">
                                    <option value="">-- 請選擇業務部門 --</option>
                                </select>
                                <button type="button" id="btn-save-sales-unit" class="btn btn-info btn-sm" style="margin-bottom: 0;">儲存部門設定</button>
                            </div>
                            <small style="color: #777; margin-top: 5px; display: block;">* 下方列表將僅顯示隸屬於此部門及其子部門的人員</small>
                        </div>

                        <!-- Customer Search -->
                        <div style="margin-bottom: 10px; display: flex; gap: 10px;">
                            <input type="text" id="sales-setting-search" class="form-control input-sm" placeholder="搜尋客戶名稱或主要業務..." style="flex: 1;">
                            <select id="sales-setting-quick-filter" class="form-control input-sm" style="width: 220px;">
                                <option value="">-- 快速篩選狀態 --</option>
                                <option value="no_primary">尚未設定主要業務</option>
                                <option value="primary_no_deputy">已設主要但未設代理</option>
                            </select>
                        </div>

                        <!-- Table -->
                        <table class="table table-bordered table-striped table-sm" id="sales-setting-table">
                            <thead style="background-color: #f1f1f1;">
                                <tr>
                                    <th style="width: 30%; position: sticky; top: 0; background-color: #f1f1f1; z-index: 1;">客戶名稱</th>
                                    <th style="width: 35%; position: sticky; top: 0; background-color: #f1f1f1; z-index: 1;">主要業務</th>
                                    <th style="width: 35%; position: sticky; top: 0; background-color: #f1f1f1; z-index: 1;">代理業務</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr><td colspan="3" class="text-center">載入中...</td></tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Footer -->
                    <div style="padding: 15px 20px; background-color: #f8f9fa; border-top: 1px solid #eee; text-align: right;">
                        <button type="button" class="btn btn-default" onclick="document.getElementById('sales-setting-modal').remove();" style="margin-right: 10px;">取消</button>
                        <button type="button" id="btn-save-sales-setting" class="btn btn-primary">儲存配置</button>
                    </div>
                </div>
            </div>
        `;
        document.body.insertAdjacentHTML('beforeend', modalHtml);

        // Add backdrop click close
        document.getElementById('sales-setting-modal').addEventListener('click', function(e) {
            if (e.target === this) {
                this.remove();
            }
        });

        // Fetch Data
        $.ajax({
            url: '',
            type: 'POST',
            data: { action: 'get_sales_settings_data' },
            dataType: 'json',
            success: function(res) {
                if (res.success) {
                    const tbody = document.querySelector('#sales-setting-table tbody');
                    tbody.innerHTML = '';
                    
                    const users = res.data.users;
                    const mappings = res.data.mappings;
                    const departments = res.data.departments;
                    const salesUnitSetting = res.data.sales_unit_setting;
                    let currentSalesUnitId = salesUnitSetting ? salesUnitSetting.id : '';

                    // Populate Department Dropdown
                    const unitSelect = document.getElementById('sales-unit-select');
                    departments.forEach(dept => {
                        const opt = document.createElement('option');
                        opt.value = dept.id;
                        // Indent based on level for hierarchy visualization
                        let prefix = '';
                        if (dept.level > 1) {
                            prefix = '&nbsp;&nbsp;'.repeat(dept.level - 1) + '└ ';
                        }
                        opt.innerHTML = prefix + escapeHtml(dept.name);
                        if (dept.id == currentSalesUnitId) opt.selected = true;
                        unitSelect.appendChild(opt);
                    });

                    // Helper to find descendant department IDs
                    const getDescendantDeptIds = (rootId) => {
                        if (!rootId) return [];
                        let ids = [String(rootId)];
                        let foundNew = true;
                        while(foundNew) {
                            foundNew = false;
                            departments.forEach(d => {
                                if (ids.includes(String(d.parent_id)) && !ids.includes(String(d.id))) {
                                    ids.push(String(d.id));
                                    foundNew = true;
                                }
                            });
                        }
                        return ids;
                    };

                    // Filter Users based on Sales Unit
                    let filteredUsers = [];
                    const filterUsers = () => {
                        const selectedUnitId = unitSelect.value;
                        if (!selectedUnitId) {
                            filteredUsers = users; // Show all if no unit selected? Or none? Let's show all for flexibility or based on request.
                            // Prompt says "only show... under sales unit". If none selected, maybe show none or all. Let's show all to be safe initially.
                        } else {
                            const validDeptIds = getDescendantDeptIds(selectedUnitId);
                            filteredUsers = users.filter(u => validDeptIds.includes(String(u.department_id)));
                        }
                    };

                    // Initial Filter
                    filterUsers();
                    
                    // Helper to create select options
                    const createOptions = (selectedId) => {
                        let opts = '<option value="">-- 請選擇 --</option>';
                        filteredUsers.forEach(u => {
                            const sel = (u.id == selectedId) ? 'selected' : '';
                            const position = u.position_name ? ` (${u.position_name})` : '';
                            const concurrent = u.is_main == '0' ? ' (兼任)' : '';
                            opts += `<option value="${u.id}" ${sel}>${escapeHtml(u.user_cname)}${escapeHtml(position)}${concurrent}</option>`;
                        });
                        return opts;
                    };

                    // Function to render/refresh table rows
                    const renderTableRows = () => {
                        const tbody = document.querySelector('#sales-setting-table tbody');
                        const rows = tbody.querySelectorAll('tr');
                        rows.forEach(row => {
                            const selects = row.querySelectorAll('select');
                            selects.forEach(sel => {
                                const currentVal = sel.value;
                                sel.innerHTML = createOptions(currentVal);
                                sel.value = currentVal; // Restore value if it still exists in filtered list
                            });
                        });
                    };

                    res.data.customers.forEach(cust => {
                        const tr = document.createElement('tr');
                        
                        // Find current mappings
                        const primary = mappings.find(m => m.customer_id == cust.customer_id && m.role === 'primary');
                        const deputy = mappings.find(m => m.customer_id == cust.customer_id && m.role === 'deputy');
                        
                        const addressHtml = cust.customer_address ? `<div class="customer-addr" style="font-size: 0.85em; color: #999;">${escapeHtml(cust.customer_address)}</div>` : '<div class="customer-addr" style="font-size: 0.85em; color: #999;"></div>';

                        tr.innerHTML = `
                            <td>
                                <div class="customer-cell" data-cid="${cust.customer_id}" data-cname="${escapeHtml(cust.customer)}" data-caddr="${escapeHtml(cust.customer_address || '')}" style="cursor: pointer;" title="雙擊編輯客戶資料"><span style="color: #666; font-size: 0.9em; margin-right: 5px;">${escapeHtml(cust.customer_id)}</span>${escapeHtml(cust.customer)}</div>
                                ${addressHtml}
                            </td>
                            <td>
                                <select class="form-control input-sm sales-select" data-cid="${cust.customer_id}" data-role="primary">
                                    ${createOptions(primary ? primary.user_id : '')}
                                </select>
                            </td>
                            <td>
                                <select class="form-control input-sm sales-select" data-cid="${cust.customer_id}" data-role="deputy">
                                    ${createOptions(deputy ? deputy.user_id : '')}
                                </select>
                            </td>
                        `;
                        tbody.appendChild(tr);
                    });

                    // Event Listener for Invalid Customer Button
                    document.getElementById('btn-manage-invalid-customers').addEventListener('click', openInvalidCustomerModal);

                    // Event Listener for Add Customer Button
                    document.getElementById('btn-add-customer').addEventListener('click', openAddCustomerModal);

                    // Event Listener for Double Click on Customer Cell
                    document.getElementById('sales-setting-table').addEventListener('dblclick', function(e) {
                        const cell = e.target.closest('.customer-cell');
                        if(cell) {
                            openEditCustomerModal(cell.dataset.cid, cell.dataset.cname, cell.dataset.caddr);
                        }
                    });

                    // Event Listener for Sales Unit Change (Preview)
                    unitSelect.addEventListener('change', function() {
                        filterUsers();
                        renderTableRows();
                    });

                    // Event Listener for Save Sales Unit
                    document.getElementById('btn-save-sales-unit').addEventListener('click', function() {
                        const newUnitId = unitSelect.value;
                        $.ajax({
                            url: '',
                            type: 'POST',
                            data: { action: 'update_sales_unit_setting', sales_unit_id: newUnitId },
                            dataType: 'json',
                            success: function(res) {
                                if (res.success) {
                                    showTemporaryMessage('部門設定已儲存', true);
                                } else {
                                    alert(res.message);
                                }
                            }
                        });
                    });

                    // NEW Filtering Logic
                    const applySalesSettingFilters = () => {
                        const term = document.getElementById('sales-setting-search').value.toLowerCase();
                        const quickFilter = document.getElementById('sales-setting-quick-filter').value;
                        const rows = document.querySelectorAll('#sales-setting-table tbody tr');

                        rows.forEach(row => {
                            const custName = row.cells[0].textContent.toLowerCase();
                            
                            const primarySelect = row.querySelector('select[data-role="primary"]');
                            const deputySelect = row.querySelector('select[data-role="deputy"]');
                            
                            const primaryId = primarySelect ? primarySelect.value : '';
                            const deputyId = deputySelect ? deputySelect.value : '';
                            
                            let primaryName = '';
                            if (primarySelect && primarySelect.selectedIndex > -1) {
                                primaryName = primarySelect.options[primarySelect.selectedIndex].text.toLowerCase();
                            }

                            let show = true;

                            // Search Term (Customer Name OR Primary Sales Name)
                            if (term) {
                                const matchCust = custName.includes(term);
                                const matchSales = (primaryId !== '' && primaryName.includes(term));
                                if (!matchCust && !matchSales) show = false;
                            }

                            // Quick Filter
                            if (show && quickFilter) {
                                if (quickFilter === 'no_primary') {
                                    if (primaryId !== '') show = false;
                                } else if (quickFilter === 'primary_no_deputy') {
                                    if (primaryId === '' || deputyId !== '') show = false;
                                }
                            }

                            row.style.display = show ? '' : 'none';
                        });
                    };

                    document.getElementById('sales-setting-search').addEventListener('input', applySalesSettingFilters);
                    document.getElementById('sales-setting-quick-filter').addEventListener('change', applySalesSettingFilters);

                    // Event Listener for Search Double Click (Clear)
                    document.getElementById('sales-setting-search').addEventListener('dblclick', function() {
                        this.value = '';
                        this.dispatchEvent(new Event('input'));
                    });

                } else {
                    alert('載入失敗: ' + res.message);
                }
            }
        });

        // Save Handler
        document.getElementById('btn-save-sales-setting').addEventListener('click', function() {
            const updates = [];
            const rows = document.querySelectorAll('#sales-setting-table tbody tr');
            let validationFailed = false;

            // --- Validation Step ---
            for (const row of rows) {
                const pSelect = row.querySelector('select[data-role="primary"]');
                const dSelect = row.querySelector('select[data-role="deputy"]');

                if (pSelect && dSelect) {
                    const primaryId = pSelect.value;
                    const deputyId = dSelect.value;

                    // Reset styles first
                    pSelect.style.borderColor = '';
                    dSelect.style.borderColor = '';

                    if (primaryId && deputyId && primaryId === deputyId) {
                        // Find customer name from the data attribute for accuracy
                        const customerCell = row.querySelector('.customer-cell');
                        const customerName = customerCell ? customerCell.dataset.cname : '此客戶';
                        
                        alert(`客戶 "${customerName}" 的主要業務與代理業務不可為同一人。`);
                        
                        // Highlight the problematic dropdowns
                        pSelect.style.borderColor = 'red';
                        dSelect.style.borderColor = 'red';
                        
                        validationFailed = true;
                        break; // Stop checking after the first error is found
                    }
                }
            }

            if (validationFailed) {
                return; // Stop the save process if validation fails
            }
            // --- End Validation ---

            rows.forEach(row => {
                const pSelect = row.querySelector('select[data-role="primary"]');
                const dSelect = row.querySelector('select[data-role="deputy"]');
                if (pSelect && dSelect) {
                    updates.push({
                        customer_id: pSelect.dataset.cid,
                        primary_user_id: pSelect.value,
                        deputy_user_id: dSelect.value
                    });
                }
            });

            $.ajax({
                url: '',
                type: 'POST',
                data: { action: 'update_customer_sales', updates: JSON.stringify(updates) },
                dataType: 'json',
                success: function(res) {
                    if (res.success) {
                        showTemporaryMessage('業務設定已儲存', true);
                        // document.getElementById('sales-setting-modal').remove(); // 移除此行以防止自動關閉
                        fetchDataAndFilter(); // Refresh main table
                    } else {
                        alert('儲存失敗: ' + res.message);
                    }
                }
            });
        });
    }

    // --- Function to open Invalid Customer Modal ---
    function openInvalidCustomerModal() {
        const modalId = 'invalid-customer-modal';
        const existingModal = document.getElementById(modalId);
        if (existingModal) existingModal.remove();

        const modalHtml = `
            <div id="${modalId}" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.6); display: flex; justify-content: center; align-items: center; z-index: 10070;">
                <div style="background-color: white; border-radius: 8px; box-shadow: 0 4px 20px rgba(0,0,0,0.2); width: 800px; max-width: 95%; height: 80vh; display: flex; flex-direction: column;">
                    <!-- Header -->
                    <div style="padding: 15px 20px; background-color: #f8f9fa; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center;">
                        <h4 style="margin: 0; color: #333; font-weight: bold;">設定無效客戶</h4>
                        <button type="button" class="close" onclick="document.getElementById('${modalId}').remove();" style="font-size: 1.5rem; background: none; border: none; cursor: pointer;">&times;</button>
                    </div>
                    <!-- Body -->
                    <div style="padding: 20px; overflow-y: auto; flex: 1; display: flex; gap: 15px;">
                        <!-- Valid Customers Column -->
                        <div style="flex: 1; display: flex; flex-direction: column;">
                            <label for="valid-customer-filter">有效客戶</label>
                            <input type="text" id="valid-customer-filter" class="form-control input-sm" placeholder="篩選客戶..." style="margin-bottom: 10px;">
                            <select id="valid-customers-list" multiple style="flex: 1; height: 100%;"></select>
                        </div>
                        <!-- Move Buttons Column -->
                        <div style="display: flex; flex-direction: column; justify-content: center; gap: 10px;">
                            <button id="move-to-invalid" class="btn btn-default">&gt;</button>
                            <button id="move-to-valid" class="btn btn-default">&lt;</button>
                        </div>
                        <!-- Invalid Customers Column -->
                        <div style="flex: 1; display: flex; flex-direction: column;">
                            <label for="invalid-customer-filter">無效客戶</label>
                            <input type="text" id="invalid-customer-filter" class="form-control input-sm" placeholder="篩選無效客戶..." style="margin-bottom: 10px;">
                            <select id="invalid-customers-list" multiple style="flex: 1; height: 100%;"></select>
                        </div>
                    </div>
                    <!-- Footer -->
                    <div style="padding: 15px 20px; background-color: #f8f9fa; border-top: 1px solid #eee; text-align: right;">
                        <button type="button" class="btn btn-default" onclick="document.getElementById('${modalId}').remove();" style="margin-right: 10px;">取消</button>
                        <button type="button" id="btn-save-invalid-customers" class="btn btn-primary">儲存設定</button>
                    </div>
                </div>
            </div>
        `;
        document.body.insertAdjacentHTML('beforeend', modalHtml);

        const validList = document.getElementById('valid-customers-list');
        const invalidList = document.getElementById('invalid-customers-list');
        const filterInput = document.getElementById('valid-customer-filter');
        const invalidFilterInput = document.getElementById('invalid-customer-filter');

        // Fetch data
        $.ajax({
            url: '',
            type: 'POST',
            data: { action: 'get_invalid_customer_data' },
            dataType: 'json',
            success: function(res) {
                if (res.success && Array.isArray(res.data)) {
                    validList.innerHTML = '';
                    invalidList.innerHTML = '';
                    res.data.forEach(cust => {
                        const option = document.createElement('option');
                        option.value = cust.customer_id;
                        option.textContent = cust.customer;
                        if (cust.is_inactive == 1) {
                            invalidList.appendChild(option);
                        } else {
                            validList.appendChild(option);
                        }
                    });
                } else {
                    alert('載入客戶資料失敗: ' + (res.message || '未知錯誤'));
                }
            }
        });

        // Move logic
        function moveOptions(sourceList, destList) {
            Array.from(sourceList.selectedOptions).forEach(option => destList.appendChild(option));
        }

        document.getElementById('move-to-invalid').addEventListener('click', () => moveOptions(validList, invalidList));
        document.getElementById('move-to-valid').addEventListener('click', () => moveOptions(invalidList, validList));

        // Filter logic for Valid Customers
        filterInput.addEventListener('input', function() {
            const filterText = this.value.toLowerCase();
            Array.from(validList.options).forEach(option => {
                option.style.display = option.textContent.toLowerCase().includes(filterText) ? '' : 'none';
            });
        });

        // Filter logic for Invalid Customers
        invalidFilterInput.addEventListener('input', function() {
            const filterText = this.value.toLowerCase();
            Array.from(invalidList.options).forEach(option => {
                option.style.display = option.textContent.toLowerCase().includes(filterText) ? '' : 'none';
            });
        });

        // 新增：雙擊篩選框以清除內容 (Valid)
        filterInput.addEventListener('dblclick', function() {
            if (this.value.trim() !== "") {
                this.value = "";
                // 觸發 input 事件以重新套用篩選（顯示所有客戶）
                this.dispatchEvent(new Event('input', { bubbles: true }));
            }
        });

        // 新增：雙擊篩選框以清除內容 (Invalid)
        invalidFilterInput.addEventListener('dblclick', function() {
            if (this.value.trim() !== "") {
                this.value = "";
                // 觸發 input 事件以重新套用篩選
                this.dispatchEvent(new Event('input', { bubbles: true }));
            }
        });

        // Save logic
        document.getElementById('btn-save-invalid-customers').addEventListener('click', function() {
            const invalidIds = Array.from(invalidList.options).map(opt => opt.value);
            $.ajax({
                url: '',
                type: 'POST',
                data: { action: 'update_invalid_customer_status', invalid_ids: invalidIds },
                dataType: 'json',
                success: function(res) {
                    if (res.success) {
                        showTemporaryMessage(res.message, true);
                        document.getElementById(modalId).remove();
                    } else {
                        alert('儲存失敗: ' + (res.message || '未知錯誤'));
                    }
                },
                error: () => alert('與伺服器通訊失敗')
            });
        });
    }

    // Function to show temporary messages (success/error)
    function showTemporaryMessage(message, isSuccess = true) {
        const msgDiv = document.createElement('div');
        msgDiv.textContent = message;
        msgDiv.setAttribute('role', 'alert'); // Add role attribute

        // Apply Bootstrap alert classes
        if (isSuccess) {
            msgDiv.className = 'alert alert-success';
        } else {
            msgDiv.className = 'alert alert-danger';
        }

        msgDiv.style.position = 'fixed';
        msgDiv.style.top = '70px'; // Adjusted top position
        msgDiv.style.left = '50%';
        msgDiv.style.transform = 'translateX(-50%)';
        msgDiv.style.padding = '10px 20px'; // Keep padding
        msgDiv.style.zIndex = '20000'; // Ensure it's above other elements
        document.body.appendChild(msgDiv);
        setTimeout(() => {
            if (document.body.contains(msgDiv)) {
                document.body.removeChild(msgDiv);
            }
        }, 2000);
    }

    // Function to refresh the process list in the "修改 BOM 資料" modal
    function refreshEditModalProcessList(bomIdForModal, mainProcessBomSnForHighlighting) {
        const processListContainerId = `dynamic-process-list-${bomIdForModal}`;
        const processListDiv = document.getElementById(processListContainerId);
        if (!processListDiv) { console.warn("Process list container not found:", processListContainerId); return; }

        // 權限判斷：A 或 C+R 才能看到加工單價；業務類(isCRU)或D+R不可看到移轉按鈕
        var canSeePrice = (window.displayPermissionCode === 'A' || window.displayPermissionCode === 'C+D+R') || window.featSeePrice;
        var canTransfer = (!window.isCRU && window.displayPermissionCode !== 'D+R') || window.featTransfer;
        var canSkip = (window.canUpdate && window.displayPermissionCode !== 'D+R' && window.displayPermissionCode !== 'R+U' && window.displayPermissionCode !== 'R') || window.featMarkReturned;

        processListDiv.innerHTML = '<div style="color:#999;font-size:12px;padding:4px;">載入中...</div>';

        // 先取得加工單價資料，再繪製清單
        var priceMap = {};
        var fetchPrice = canSeePrice
            ? $.post('', {action:'get_process_price', bom: bomIdForModal})
            : $.Deferred().resolve({success:true, prices:[]}).promise();

        fetchPrice.then(function(priceResp) {
            if (priceResp && priceResp.success && priceResp.prices) {
                priceResp.prices.forEach(function(p){ priceMap[String(p.bom_sn)] = p; });
            }
            _renderProcList();
        }).fail(function(){ _renderProcList(); });

        function _buildProcRow(proc) {
            var div = document.createElement('div');
            div.className = 'form-group row';
            div.style.marginBottom = '5px';
            div.style.alignItems = 'center';

            var priceInfo = priceMap[String(proc.bom_sn)];
            var priceDisplay = '';
            if (canSeePrice && priceInfo) {
                var p = priceInfo.modified_unit_price || priceInfo.price;
                if (p) {
                    priceDisplay = '<span style="color:#0a6;font-size:11px;margin-left:4px;">$' + parseFloat(p).toFixed(2) + '</span>';
                }
            }

            var transferBtnHtml = canTransfer
                ? `<button type="button" class="btn btn-warning btn-xs" data-toggle="modal" data-target="#transferProcessModal_${proc.bom_ing_fid}" title="移轉">移</button>`
                : '';

            div.innerHTML = `
                <div class="col-md-2 col-sm-2 col-xs-3 text-right">
                    ${transferBtnHtml}
                </div>
                <div class="col-md-2 col-sm-2 col-xs-2" style="padding-top:7px;font-weight:bold;">${escapeHtml(proc.bom_sn)}</div>
                <div class="col-md-2 col-sm-2 col-xs-2" style="padding-top:7px;">${escapeHtml(proc.process_no)}</div>
                <div class="col-md-4 col-sm-4 col-xs-4" style="padding-top:7px;">${escapeHtml(proc.ProcessName||'')}${priceDisplay}</div>
            `;

            if (canSkip && String(proc.processing_state||'') === 'N') {
                div.innerHTML += `<div class="col-md-1 col-sm-1 col-xs-1 text-right">
                    <button type="button" class="btn btn-default btn-xs" style="color:#e67e22;border-color:#e67e22;" title="標記跳過（此製程確定不加工）" onclick="confirmMarkSkip('${proc.bom_ing_fid}','${bomIdForModal}','${mainProcessBomSnForHighlighting}','${escapeHtml(proc.ProcessName||'')}')">跳過</button>
                </div>`;
            }
            if (window.canDelete && window.displayPermissionCode !== 'D+R' && window.displayPermissionCode !== 'R+U' && window.displayPermissionCode !== 'R') {
                div.innerHTML += `<div class="col-md-1 col-sm-1 col-xs-1 text-right">
                    <button type="button" class="btn btn-danger btn-xs" onclick="confirmDeleteBomIng('${proc.bom_ing_fid}','${bomIdForModal}','${mainProcessBomSnForHighlighting}','${escapeHtml(proc.ProcessName||'')}')">X</button>
                </div>`;
            }
            if (String(proc.bom_sn) === String(mainProcessBomSnForHighlighting)) {
                div.style.backgroundColor = '#e6f2ff';
                div.title = '目前製程';
            }
            return div;
        }

        function _renderProcList() {
            processListDiv.innerHTML = '';
            const bomProcessesForModal = window.bomPSList
                .filter(p => p.bom === bomIdForModal)
                .sort((a, b) => (parseInt(a.bom_sn)||0) - (parseInt(b.bom_sn)||0));

            if (bomProcessesForModal.length >= 6) {
                processListDiv.style.display = 'flex';
                const leftCol = document.createElement('div'); leftCol.style.flex = '1';
                const rightCol = document.createElement('div'); rightCol.style.flex = '1';
                const half = Math.ceil(bomProcessesForModal.length / 2);
                bomProcessesForModal.forEach((proc, idx) => {
                    var d = _buildProcRow(proc);
                    if (idx < half) leftCol.appendChild(d); else rightCol.appendChild(d);
                });
                processListDiv.appendChild(leftCol);
                if (rightCol.hasChildNodes()) {
                    const sep = document.createElement('div');
                    sep.style.cssText = 'width:1px;background:#ccc;margin:0 10px;align-self:stretch;';
                    processListDiv.appendChild(sep);
                    processListDiv.appendChild(rightCol);
                }
            } else {
                processListDiv.style.display = 'block';
                bomProcessesForModal.forEach(proc => processListDiv.appendChild(_buildProcRow(proc)));
            }
        }
    }

    // Function to recalculate maxCount based on the current window.bomPSList
    function recalculateMaxCount() {
        if (!window.bomPSList || window.bomPSList.length === 0) {
            window.maxCount = 0;
            return;
        }

        const bomProcessCounts = {};
        window.bomPSList.forEach(item => {
            if (item.bom) {
                // Use a Set to count distinct bom_sn for each bom
                if (!bomProcessCounts[item.bom]) {
                    bomProcessCounts[item.bom] = new Set();
                }
                if (item.bom_sn) { // Ensure bom_sn is not null/undefined before adding
                    bomProcessCounts[item.bom].add(item.bom_sn);
                }
            }
        });

        let newMax = 0;
        for (const bom in bomProcessCounts) {
            if (bomProcessCounts[bom].size > newMax) {
                newMax = bomProcessCounts[bom].size;
            }
        }
        window.maxCount = newMax;
        console.log("Recalculated maxCount:", window.maxCount);
    }

    // Function to open the modal for adding a new process
    function openAddProcessModal(rowData) {
        // Remove existing modal if any
        const existingModal = document.getElementById('add-process-modal');
        if (existingModal) existingModal.remove();

        const modal = document.createElement('div');
        modal.id = 'add-process-modal';
        modal.style.cssText = `
            position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%);
            background-color: white; padding: 20px; border: 2px solid #555; /* 需求 4: 外框加粗 */
            border-radius: 8px; box-shadow: 0 4px 8px rgba(0,0,0,0.2); z-index: 10050;
            width: 450px; max-width: 90%;
        `;

        const modalHeader = document.createElement('h4');
        modalHeader.textContent = `新增 ${escapeHtml(rowData.bom)} 製程`;
        modalHeader.style.marginBottom = '15px';
        modal.appendChild(modalHeader);

        const form = document.createElement('form');
        form.className = 'form-horizontal'; // For Bootstrap styling if desired

        form.innerHTML = `
            <div class="form-group">
                <label class="col-sm-3 control-label">料號:</label>
                <div class="col-sm-9"><p class="form-control-static">${escapeHtml(rowData.d_id)}</p></div>
            </div>
            <div class="form-group">
                <label for="new-bom-sn" class="col-sm-3 control-label">SN:</label>
                <div class="col-sm-9"><input type="text" id="new-bom-sn" class="form-control" style="width: 80px;"></div>
            </div>
            <div class="form-group">
                <label for="new-process-no" class="col-sm-3 control-label">製程代號:</label>
                <div class="col-sm-9"><input type="text" id="new-process-no" class="form-control" style="width: 80px;"></div>
            </div>
            <div class="form-group">
                <label for="new-process-name-hint" class="col-sm-3 control-label">製程中文:</label>
                <div class="col-sm-9">
                    <input type="text" id="new-process-name-hint" class="form-control" list="process-name-datalist" style="width: 180px;">
                    <datalist id="process-name-datalist"></datalist>
                </div>
            </div>
        `;
        modal.appendChild(form);

        const footer = document.createElement('div');
        // Use flexbox for footer button alignment
        footer.style.display = 'flex';
        footer.style.justifyContent = 'flex-end'; // Align buttons to the right
        footer.style.marginTop = '20px';

        const confirmButton = document.createElement('button');
        confirmButton.textContent = '確認新增';
        confirmButton.className = 'btn btn-primary btn-sm';
        confirmButton.onclick = function() {
            const newSn = document.getElementById('new-bom-sn').value.trim();
            const newProcessNo = document.getElementById('new-process-no').value.trim();

            // Validation
            if (!newSn || !newProcessNo) {
                showTemporaryMessage('SN 和 製程代號 欄位不可為空！', false);
                return;
            }
            const currentBOMProcesses = window.bomPSList.filter(p => p.bom === rowData.bom);
            if (currentBOMProcesses.some(p => String(p.bom_sn) === newSn)) {
                showTemporaryMessage('此 BOM 已存在相同的 SN！', false);
                return;
            }
            if (currentBOMProcesses.some(p => String(p.process_no) === newProcessNo)) {
                showTemporaryMessage('此 BOM 已存在相同的製程代號！', false);
                return;
            }

            submitNewProcessToDB(rowData, newSn, newProcessNo);
            modal.remove();
        };
        footer.appendChild(confirmButton);

        // Add Clear button to the right of Confirm Add, with spacing
        const clearButton = document.createElement('button');
        clearButton.textContent = '清除';
        clearButton.className = 'btn btn-info btn-sm';
        clearButton.style.marginLeft = '10px'; // Space from Confirm button
        clearButton.onclick = function() {
            document.getElementById('new-bom-sn').value = '';
            document.getElementById('new-process-no').value = '';
            document.getElementById('new-process-name-hint').value = '';
        };
        footer.appendChild(clearButton);

        const cancelButton = document.createElement('button');
        cancelButton.textContent = '取消';
        cancelButton.className = 'btn btn-default btn-sm';
        cancelButton.style.marginLeft = '10px'; // Space from Clear button
        cancelButton.onclick = function() {
            modal.remove();
        };
        footer.appendChild(cancelButton);

        modal.appendChild(footer);
        document.body.appendChild(modal);

        // Populate datalist and handle selection for process name hint
        const processNameHintInput = document.getElementById('new-process-name-hint');
        const processNoInput = document.getElementById('new-process-no');
        const datalist = document.getElementById('process-name-datalist');

        // Clear existing options in datalist
        datalist.innerHTML = '';

        // Populate datalist with ProcessName, store ProcessNo in data attribute
        (window.allProcessTypes || []).forEach(pt => {
            const option = document.createElement('option');
            option.value = pt.ProcessName; // Display ProcessName
            option.dataset.processNo = pt.ProcessNo; // Store ProcessNo
            datalist.appendChild(option);
        });

        // When ProcessName is selected or typed matching a datalist option
        processNameHintInput.addEventListener('input', function() {
            const selectedOption = Array.from(datalist.options).find(opt => opt.value === this.value);
            if (selectedOption && selectedOption.dataset.processNo) {
                processNoInput.value = selectedOption.dataset.processNo;
            }
        });

        // When ProcessNo is typed, update ProcessName hint
        processNoInput.addEventListener('input', function() {
            const enteredId = this.value.trim();
            const foundProcess = (window.allProcessTypes || []).find(pt => String(pt.ProcessNo) === enteredId);
            if (foundProcess) {
                processNameHintInput.value = foundProcess.ProcessName;
            } else {
                processNameHintInput.value = ''; // If no match, clear the hint
            }
        });
    }

    // Function to submit the new process to the database
    function submitNewProcessToDB(rowData, newSn, newProcessNo) {
        const bom = rowData.bom;
        const bomSqty = rowData.Qty; // Assuming rowData.Qty is the BOM's total quantity

        // Construct bom_ing_id as per requirement 5
        // BOM (last 9 digits)-ProcessNo(no leading zero)-bom_sn-sqty
        const lastNineOfBom = bom.slice(-9);
        const processNoWithoutLeadingZero = String(parseInt(newProcessNo, 10));
        const new_bom_ing_id = `${lastNineOfBom}-${processNoWithoutLeadingZero}-${newSn}-${bomSqty}`;

        $.ajax({
            url: '../../src/store/_add_bom_ing.php',
            type: 'POST',
            data: {
                bom: bom,
                bom_ing_id: new_bom_ing_id, // Send the formatted bom_ing_id
                new_sn: newSn,
                new_process_no: newProcessNo,
                sqty: bomSqty
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    showTemporaryMessage('新增製程成功！');

                    if (response.new_bom_ing_item) {
                        // Explicitly check if bom_ing_fid is present in the response
                        if (response.new_bom_ing_item.bom_ing_fid) {
                            console.log("Received new_bom_ing_item with bom_ing_fid:", response.new_bom_ing_item.bom_ing_fid, "Full item:", response.new_bom_ing_item);
                        } else {
                            console.warn("Received new_bom_ing_item BUT bom_ing_fid is missing. This could lead to issues in UI updates or subsequent operations. Item received:", response.new_bom_ing_item);
                            // The existing code will proceed, but this warning helps identify a potential backend issue.
                        }

                        // 1. Add the new item to the global bomPSList
                        window.bomPSList.push(response.new_bom_ing_item);
                        console.log("Added new process to local bomPSList:", response.new_bom_ing_item);

                        // 2. Recalculate maxCount
                        recalculateMaxCount();

                        // 3. Refresh the modal's process list
                        refreshEditModalProcessList(rowData.bom, rowData.bom_sn);
                        // 4. Re-render the main table with updated data
                        processAndRenderData();
                    } else {
                        // If new_bom_ing_item is not returned, fall back to full refresh as a safety measure
                        console.warn("Backend did not return new_bom_ing_item. Falling back to full data refresh.");
                        fetchDataAndFilter(function() {
                            refreshEditModalProcessList(rowData.bom, rowData.bom_sn);
                        });
                    }
                } else {
                    showTemporaryMessage('新增失敗：' + (response.message || '未知錯誤'), false);
                }
            },
            error: function(xhr, status, error) {
                showTemporaryMessage('與伺服器通訊失敗，無法新增製程！\n' + error, false);
            }
        });
    }

    // Function to reset workdays for a specific year via AJAX
    function resetWorkdaysForYear(year) {
        console.log(`正在重設 ${year} 年度工作日...`);

        // TODO: Create the backend script '../../src/store/_reset_workdays.php'
        // This script should handle deleting all records for the given year from your workday table.
        $.ajax({
            url: '../../src/store/_reset_workdays.php', // This backend script needs to be created
            type: 'POST',
            data: {
                year: year
            },
            dataType: 'json', // Assuming backend returns JSON response
            success: function(response) {
                if (response && response.success) {
                    showTemporaryMessage(`已成功重設 ${year} 年度工作日。`, true);
                    // Optionally close the modal or refresh its content if needed
                    const modal = document.getElementById('workday-setup-modal');
                    if (modal) modal.remove();
                } else {
                    showTemporaryMessage('重設失敗：' + (response && response.message ? response.message : '未知錯誤'), false);
                }
            },
            error: function(xhr, status, error) {
                showTemporaryMessage('與伺服器通訊失敗，無法重設工作日！\n' + error, false);
            }
        });
    }

    // Function to open the modal for setting workdays
    function openWorkdayModal() {
        // Remove existing modal if any
        const existingModal = document.getElementById('workday-setup-modal');
        if (existingModal) existingModal.remove();

        const modal = document.createElement('div');
        modal.id = 'workday-setup-modal';
        modal.style.cssText = `
            position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%);
            background-color: white; padding: 20px; border: 2px solid #555; /* Distinct border */
            border-radius: 8px; box-shadow: 0 4px 8px rgba(0,0,0,0.2); z-index: 10050; /* High z-index */
            width: 400px; max-width: 90%; /* Responsive width */
        `;

        const modalHeader = document.createElement('div');
        modalHeader.style.display = 'flex';
        modalHeader.style.justifyContent = 'center'; // Center the main content (title)
        modalHeader.style.alignItems = 'center';
        modalHeader.style.marginBottom = '15px';
        modalHeader.style.position = 'relative'; // For absolute positioning of the close button

        const headerTitle = document.createElement('h4');
        headerTitle.textContent = new Date().getFullYear() + ' 設定工作日'; // 增加今年的西元年
        headerTitle.style.margin = '0'; // Remove default h4 margin
        // headerTitle.style.flexGrow = '1'; // Allow title to take up space if needed
        // headerTitle.style.textAlign = 'center'; // Ensure text within h4 is centered
        modalHeader.appendChild(headerTitle);

        const closeButton = document.createElement('button');
        closeButton.innerHTML = '&times;'; // Close icon
        closeButton.className = 'btn btn-danger btn-xs'; // Style like other close buttons
        closeButton.style.lineHeight = '1'; // Adjust for better icon appearance
        closeButton.style.position = 'absolute'; // Position absolutely
        closeButton.style.top = '0'; // Align to top of header
        closeButton.style.right = '0'; // Align to right of header
        closeButton.onclick = function() {
            modal.remove();
        };
        modalHeader.appendChild(closeButton);

        modal.appendChild(modalHeader);

        const modalBody = document.createElement('div');
        modalBody.id = 'workday-modal-body';
        modalBody.style.overflowY = 'auto'; /* Add scroll if content overflows */
        modalBody.style.maxHeight = 'calc(100vh - 200px)'; /* Limit height to prevent modal from exceeding screen height */

        // Create two columns container
        // This container holds the *initial* date inputs and lists
        const columnsContainer = document.createElement('div');
        columnsContainer.style.display = 'flex';
        columnsContainer.style.justifyContent = 'space-between';
        columnsContainer.style.marginBottom = '15px';
        columnsContainer.style.gap = '20px'; /* Add gap between columns */

        const leftColumn = document.createElement('div');
        leftColumn.style.flex = '1';
        // Style for the "box" and centering content
        leftColumn.style.border = '1px dashed #ccc'; // Changed to dashed border
        leftColumn.style.padding = '10px';
        leftColumn.style.borderRadius = '4px';
        leftColumn.style.display = 'flex';
        leftColumn.style.flexDirection = 'column';
        leftColumn.style.alignItems = 'center'; // Center block children like form-group and list

        // Use the requested form-group structure for the calendar input
        leftColumn.innerHTML = `
            <div class="item form-group" style="margin-bottom: 0; text-align: center; width: 100%;">
                <label for="datepicker_workday_on_holiday" style="display: block; text-align: center; margin-bottom: 5px;">假日上班日</label>
                <input type="text" id="datepicker_workday_on_holiday" required value="" size="12" name="workday_on_holiday_date" placeholder="請選擇" class="form-control" style="width: 100px; display: inline-block; text-align: center;">
            </div>
            <div id="workday-on-holiday-list" style="text-align: center; margin-top: 10px; padding: 5px; max-height: 200px; overflow-y: auto; width: 90%;">
                <!-- 已設定的假日上班日將顯示在此 -->
            </div>
        `;

        const rightColumn = document.createElement('div');
        rightColumn.style.flex = '1';
        // Style for the "box" and centering content
        rightColumn.style.border = '1px dashed #ccc'; // Changed to dashed border
        rightColumn.style.padding = '10px';
        rightColumn.style.borderRadius = '4px';
        rightColumn.style.display = 'flex';
        rightColumn.style.flexDirection = 'column';
        rightColumn.style.alignItems = 'center'; // Center block children
        // Use the requested form-group structure for the calendar input
        rightColumn.innerHTML = `
            <div class="item form-group" style="margin-bottom: 0; text-align: center; width: 100%;">
                <label for="datepicker_holiday_on_weekday" style="display: block; text-align: center; margin-bottom: 5px;">平日放假日</label>
                <input type="text" id="datepicker_holiday_on_weekday" required value="" size="12" name="holiday_on_weekday_date" placeholder="請選擇" class="form-control" style="width: 100px; display: inline-block; text-align: center;">
            </div>
            <div id="holiday-on-weekday-list" style="text-align: center; margin-top: 10px; padding: 5px; max-height: 200px; overflow-y: auto; width: 90%;">
                <!-- 已設定的平日放假日將顯示在此 -->
            </div>
        `;

        columnsContainer.appendChild(leftColumn);
        columnsContainer.appendChild(rightColumn);
        modalBody.appendChild(columnsContainer);

        // Horizontal line
        const hr = document.createElement('hr');
        hr.style.borderTop = '1px solid #ccc';
        hr.style.marginTop = '15px';
        hr.style.marginBottom = '15px';
        modalBody.appendChild(hr);

        // Buttons container
        // Use flexbox for footer button alignment
        const buttonsContainer = document.createElement('div');
        buttonsContainer.style.display = 'flex';
        buttonsContainer.style.justifyContent = 'center'; // Align buttons to the center
        buttonsContainer.style.marginTop = '20px';

        // Create the Reset button (moved to be the first button)
        const resetButton = document.createElement('button');
        resetButton.type = 'button';
        resetButton.className = 'btn btn-danger btn-sm'; // Added btn-sm for consistency
        resetButton.textContent = '重設本年度';
        resetButton.style.marginRight = '10px'; // Space from the next button
        resetButton.onclick = function() {
            const currentYear = new Date().getFullYear(); // Get the current year
            if (confirm(`確定重設 ${currentYear} 年度工作日？\n此操作將刪除所有已設定的假日上班日和平日放假日。`)) {
                resetWorkdaysForYear(currentYear);
            } else {
                console.log("重設本年度工作日已取消。");
            }
        };
        buttonsContainer.appendChild(resetButton); // Add the reset button first

        // Add button (remains second)
        const addButton = document.createElement('button');
        addButton.type = 'button';
        addButton.className = 'btn btn-primary';
        addButton.textContent = '新增空白日期';
        addButton.id = 'add-blank-date-btn'; // Add ID for event listener
        addButton.style.marginRight = '10px';

        // Confirm button (remains third)
        const confirmButton = document.createElement('button');
        confirmButton.type = 'button';
        confirmButton.className = 'btn btn-success';
        confirmButton.textContent = '確定修改日期';
        // No margin-right needed as it's the last button

        buttonsContainer.appendChild(addButton);
        buttonsContainer.appendChild(confirmButton);
        modalBody.appendChild(buttonsContainer);

        // Add event listener for the "新增空白日期" button
        addButton.addEventListener('click', addDynamicDateRow);

        // Function to add a new row of date inputs
        function addDynamicDateRow() {
            dynamicDateCounter++; // Increment counter for unique IDs
            const leftGroupId = `dynamic-workday-group-${dynamicDateCounter}`;
            const rightGroupId = `dynamic-holiday-group-${dynamicDateCounter}`;

            // Create and append to leftColumn
            const leftDynamicInputGroup = document.createElement('div');
            leftDynamicInputGroup.id = leftGroupId;
            leftDynamicInputGroup.className = 'form-group'; // Use form-group for consistent styling
            leftDynamicInputGroup.style.marginBottom = '1px'; // 設定下方間隔為 1px
            if (dynamicDateCounter === 1) { // 如果是第一個新增的日期選擇器
                leftDynamicInputGroup.style.marginTop = '1px'; // 設定上方間隔為 1px，使其與原有的日期選擇器間隔為 1px
            }
            leftDynamicInputGroup.style.textAlign = 'center';
            leftDynamicInputGroup.innerHTML = `<input type="text" id="dynamic_workday_on_holiday_${dynamicDateCounter}" required value="" size="12" placeholder="請選擇" class="form-control" style="width: 100px; display: inline-block; text-align: center;">
            `;
            leftColumn.insertBefore(leftDynamicInputGroup, document.getElementById('workday-on-holiday-list'));

            // Create and append to rightColumn
            const rightDynamicInputGroup = document.createElement('div');
            rightDynamicInputGroup.id = rightGroupId;
            rightDynamicInputGroup.className = 'form-group'; // Use form-group for consistent styling
            rightDynamicInputGroup.style.marginBottom = '1px'; // 設定下方間隔為 1px
            if (dynamicDateCounter === 1) { // 如果是第一個新增的日期選擇器
                rightDynamicInputGroup.style.marginTop = '1px'; // 設定上方間隔為 1px，使其與原有的日期選擇器間隔為 1px
            }
            rightDynamicInputGroup.style.textAlign = 'center';
            rightDynamicInputGroup.innerHTML = `<input type="text" id="dynamic_holiday_on_weekday_${dynamicDateCounter}" required value="" size="12" placeholder="請選擇" class="form-control" style="width: 100px; display: inline-block; text-align: center;">
            `;
            rightColumn.insertBefore(rightDynamicInputGroup, document.getElementById('holiday-on-weekday-list'));

            // Initialize datepickers for the new inputs
            $(`#dynamic_workday_on_holiday_${dynamicDateCounter}`).datepicker({
                changeMonth: true,
                changeYear: true,
                showMonthAfterYear: true,
                dateFormat: "yy-mm-dd"
            });
            $(`#dynamic_holiday_on_weekday_${dynamicDateCounter}`).datepicker({
                changeMonth: true,
                changeYear: true,
                showMonthAfterYear: true,
                dateFormat: "yy-mm-dd"
            });
        }
        // Note: The removeDynamicDatePair function is no longer needed as the buttons calling it are removed.

        modal.appendChild(modalBody);

        document.body.appendChild(modal);

        // Initialize datepickers after they are added to the DOM
        // 設定 jQuery UI Datepicker 的預設區域設定 (只設定一次)
        $(function() {
            // 設定區域設定
            $.datepicker.regional["zh-TW"] = {
                closeText: "關閉",
                prevText: "&#x3C;上個月",
                nextText: "下個月&#x3E;",
                currentText: "今天",
                monthNames: ["一月", "二月", "三月", "四月", "五月", "六月",
                    "七月", "八月", "九月", "十月", "十一月", "十二月"
                ],
                monthNamesShort: ["一月", "二月", "三月", "四月", "五月", "六月",
                    "七月", "八月", "九月", "十月", "十一月", "十二月"
                ],
                dayNames: ["星期日", "星期一", "星期二", "星期三", "星期四", "星期五", "星期六"],
                dayNamesShort: ["週日", "週一", "週二", "週三", "週四", "週五", "週六"],
                dayNamesMin: ["日", "一", "二", "三", "四", "五", "六"],
                weekHeader: "週",
                dateFormat: "yy-mm-dd",
                firstDay: 1,
                isRTL: false,
                showMonthAfterYear: true,
                yearSuffix: "年"
            };

            // 設定預設區域
            $.datepicker.setDefaults($.datepicker.regional["zh-TW"]);

            // 初始化各個日期元件
            $("#datepicker_workday_on_holiday").datepicker({
                changeMonth: true,
                changeYear: true,
                showMonthAfterYear: true,
                dateFormat: "yy-mm-dd" // Ensure consistent date format
            });
            $("#datepicker_holiday_on_weekday").datepicker({
                changeMonth: true,
                changeYear: true,
                showMonthAfterYear: true,
                dateFormat: "yy-mm-dd" // Ensure consistent date format
            });
        });
    }

    // --- 快速綁定料號 (搜尋 d_setting) ---
    // --- 製程列表彈窗的取消移轉 ---
    function cancelTransferFromModal(fid, bomId, mainSn) {
        if (!fid) { alert('此製程沒有有效的 bom_ing_fid，無法操作'); return; }
        // 先從 bomPSList 取得目前狀態
        var proc = window.bomPSList && window.bomPSList.find(function(p){ return p.bom_ing_fid === fid; });
        var curState = proc ? String(proc.processing_state || '').trim() : '';
        if (curState === 'N' || curState === '') {
            alert('目前已是最初狀態(N)，無反應。');
            return;
        }
        if (curState === 'P') {
            alert('本狀態由QC回報，不可手動回歸。');
            return;
        }
        if (!confirm('確定要將此製程回歸前一狀態？\n(目前：' + curState + ' → ' + (curState === 'ing' ? 'N' : 'P') + ')')) return;
        $.ajax({
            url: '', type: 'POST',
            data: { action: 'cancel_transfer', bom_ing_fid: fid },
            dataType: 'json',
            success: function(res) {
                if (res.no_action) { alert('目前已是最初狀態(N)，無反應。'); return; }
                if (res.qc_state) { alert('本狀態由QC回報，不可手動回歸。'); return; }
                if (res.success) {
                    showTemporaryMessage('已回歸至前一狀態(' + (res.new_state||'?') + ')', true);
                    if (proc) {
                        proc.processing_state = res.new_state;
                        if (res.new_state === 'N') { proc.outsource_date = null; proc.maker_id = ''; }
                        else if (res.new_state === 'ing') { proc.return_date = null; }
                    }
                    refreshEditModalProcessList(bomId, mainSn);
                } else {
                    showTemporaryMessage('操作失敗：' + (res.message || '未知'), false);
                }
            },
            error: function() { alert('伺服器通訊失敗'); }
        });
    }

    function openQuickBindDsetting(rowData, triggerBtn) {
        var existing = document.getElementById('quick-bind-dsetting-popup');
        if (existing) existing.remove();

        var popup = document.createElement('div');
        popup.id = 'quick-bind-dsetting-popup';
        popup.style.cssText = 'position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;border:2px solid #337ab7;border-radius:6px;padding:16px;z-index:10090;min-width:360px;max-width:520px;box-shadow:0 4px 20px rgba(0,0,0,0.3);';

        console.log('[快速綁定搜尋] rowData:',
            '\n  bom:', rowData.bom,
            '\n  d_setting_id:', rowData.d_setting_id || '(未設定)',
            '\n  d_display(前端顯示料號):', rowData.d_display || rowData.d_id || '',
            '\n  Client_Name:', rowData.Client_Name || ''
        );
        var clientHint = rowData.Client_Name ? '<small style="color:#888;"> (客戶：' + escapeHtml(rowData.Client_Name) + ')</small>' : '';
        // 使用 d_display（= D_Setting_Id 顯示料號文字）做初始搜尋值
        var initialDid = escapeHtml(rowData.d_display || rowData.d_id || '');
        var initialClient = escapeHtml(rowData.Client_Name_Full || rowData.Client_Name || '');
        popup.innerHTML = '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">' +
            '<strong>搜尋料號設定' + (clientHint || '') + '</strong>' +
            '<button type="button" onclick="document.getElementById(\'quick-bind-dsetting-popup\').remove();" style="background:none;border:none;font-size:1.3rem;cursor:pointer;line-height:1;">&times;</button>' +
            '</div>' +
            '<div style="display:flex;gap:6px;margin-bottom:8px;">' +
            '<input type="text" id="qb-dsetting-input" class="form-control input-sm" placeholder="輸入料號或名稱搜尋..." value="' + initialDid + '" style="flex:1;">' +
            '<button type="button" id="qb-dsetting-search-btn" class="btn btn-primary btn-sm">搜尋</button>' +
            '</div>' +
            '<div style="margin-bottom:8px;position:relative;" id="qb-client-search-container">' +
            '<input type="text" id="qb-client-input" class="form-control input-sm" value="' + initialClient + '" readonly style="background:#f5f5f5;cursor:default;color:#555;" placeholder="客戶名稱">' +
            '<div id="qb-client-drop" style="display:none;"></div>' +
            '</div>' +
            '<div id="qb-dsetting-results" style="max-height:200px;overflow-y:auto;border:1px solid #eee;border-radius:3px;"></div>';

        document.body.appendChild(popup);

        // [已移除] 新增客戶按鈕已停用，客戶欄位改為唯讀
        // [已移除] 點此新增料號 功能已停用

        var clientInp = document.getElementById('qb-client-input');
        var clientDrop = document.getElementById('qb-client-drop');
        // [客戶欄位已設為唯讀，不需要搜尋事件]

        // [已移除] 點此新增料號 功能已停用

        var inp = document.getElementById('qb-dsetting-input');
        var resultsDiv = document.getElementById('qb-dsetting-results');

        function doSearch() {
            var term = inp.value.trim();
            if (!term) { resultsDiv.innerHTML = '<p style="padding:8px;color:#999;font-size:12px;">請輸入搜尋關鍵字</p>'; return; }
            console.log('[搜尋料號設定 SQL]',
                '\n  term:', term,
                '\n  d_setting_id:', rowData.d_setting_id||'(未設定)',
                '\n  d_display:', rowData.d_display||rowData.d_id||'',
                '\n  SQL: WHERE D_Setting_Id LIKE \'%'+term+'%\' OR Spec_No LIKE \'%'+term+'%\''
            );
            console.log('[搜尋料號設定]',
                '\n  term:', term,
                '\n  rowData.d_setting_id:', rowData.d_setting_id || '(未設定)',
                '\n  rowData.d_display:', rowData.d_display || rowData.d_id || '',
                '\n  client:', rowData.Client_Name || '',
                '\n  SQL: d_setting WHERE D_Setting_Id LIKE %'+term+'% OR Spec_No LIKE %'+term+'%'
            );
            resultsDiv.innerHTML = '<p style="padding:8px;color:#999;font-size:12px;"><i class="fa fa-spinner fa-spin"></i> 搜尋中...</p>';
            // 10秒超時保護
            var _searchTimeout = setTimeout(function() {
                resultsDiv.innerHTML = '<p style="padding:8px;color:#e00;font-size:12px;">搜尋逾時，請重試。</p>';
            }, 10000);
            $.ajax({
                url: '',
                type: 'POST',
                data: { action: 'search_d_setting', term: term, client: rowData.Client_Name || '', d_setting_id: rowData.d_setting_id || '' },
                dataType: 'json',
                timeout: 9000,
                success: function(res) {
                    clearTimeout(_searchTimeout);
                    console.log('[搜尋料號設定 response]', res.debug || '', '結果筆數:', res.results ? res.results.length : 0);
                    if (!res.success || !res.results || !res.results.length) {
                        resultsDiv.innerHTML = '<p style="padding:8px;color:#c00;font-size:12px;">查無料號「' + escapeHtml(term) + '」。</p>';
                        return;
                    }
                    var html = '<table style="width:100%;font-size:12px;border-collapse:collapse;">';
                    html += '<thead><tr style="background:#f0f0f0;">'+
                        '<th style="padding:4px 8px;">料號ID</th>'+
                        '<th style="padding:4px 8px;">顯示料號</th>'+
                        '<th style="padding:4px 8px;">客戶ID</th>'+
                        '<th style="padding:4px 8px;">客戶名稱</th>'+
                        '<th style="padding:4px 8px;"></th></tr></thead><tbody>';
                    res.results.forEach(function(r) {
                        var matchStyle = r.exact_match ? 'background:#e8f5e9;' : '';
                        var matchTip = r.client_match ? ' <span style="color:green;font-size:10px;">✓</span>' : '';
                        html += '<tr style="border-bottom:1px solid #eee;' + matchStyle + '">' +
                            '<td style="padding:4px 8px;color:#888;font-size:11px;">' + escapeHtml(r.d_id) + '</td>' +
                            '<td style="padding:4px 8px;font-weight:bold;">' + escapeHtml(r.display_id || r.d_id) + (r.drawing_no && r.drawing_no !== (r.display_id || r.d_id) ? '<span style="font-size:11px;color:#1a7abf;display:block;margin-top:1px;">代：' + escapeHtml(r.drawing_no) + '</span>' : '') + '</td>' +
                            '<td style="padding:4px 8px;color:#888;font-size:11px;">' + escapeHtml(r.customer_id || '') + '</td>' +
                            '<td style="padding:4px 8px;">' + escapeHtml(r.customer_name || '') + matchTip + '</td>' +
                            '<td style="padding:4px 8px;"><button type="button" class="btn btn-xs btn-success qb-apply-btn" data-bom="' + escapeHtml(rowData.bom) + '" data-did="' + escapeHtml(r.d_id) + '">套用</button></td></tr>';
                    });
                    html += '</tbody></table>';
                    resultsDiv.innerHTML = html;
                    resultsDiv.querySelectorAll('.qb-apply-btn').forEach(function(btn) {
                        btn.onclick = function() { applyDsettingToBom(btn.dataset.bom, btn.dataset.did, popup); };
                    });
                },
                error: function() { clearTimeout(_searchTimeout); resultsDiv.innerHTML = '<p style="padding:8px;color:#c00;font-size:12px;">搜尋失敗，請稍後再試。</p>'; }
            });
        }

        document.getElementById('qb-dsetting-search-btn').onclick = doSearch;
        inp.addEventListener('keydown', function(e) { if (e.key === 'Enter') { e.preventDefault(); doSearch(); } });
        if (inp.value.trim()) { setTimeout(doSearch, 100); }

        setTimeout(function() {
            document.addEventListener('click', function outsideClick(e) {
                if (!popup.contains(e.target) && e.target !== triggerBtn) {
                    popup.remove();
                    document.removeEventListener('click', outsideClick);
                }
            });
        }, 200);
    }

    function applyDsettingToBom(bom, newDid, popup) {
        // newDid 是 d_setting.d_id（數字內部ID）
        if (!confirm('確定要將 BOM「' + bom + '」綁定料號設定（d_setting_id=' + newDid + '）嗎？')) return;
        var _applyUrl = window.location.pathname; // _selfUrl 在此 scope 不可用
        console.log('[套用料號設定]',
            '\n  POST到:', _applyUrl,
            '\n  action: apply_dsetting_to_bom',
            '\n  bom:', bom,
            '\n  d_setting_id:', newDid,
            '\n  → 後端將執行: UPDATE bom SET d_setting_id='+newDid+', d_id=(D_Setting_Id) WHERE bom='+bom
        );
        $.post(_applyUrl, {
            action: 'apply_dsetting_to_bom',
            bom: bom,
            d_setting_id: newDid
        }, function(res) {
            if (res && res.success) {
                showTemporaryMessage('料號設定已綁定：' + (res.display_id || newDid), true);
                var item = fullDataset.find(function(i){ return i.bom === bom; });
                if (item) {
                    item.d_setting_id  = newDid;
                    item.d_display     = res.display_id || item.d_id;
                    item.d_id          = res.display_id || item.d_id;
                    item.d_customer_id = res.customer_id || '';
                    item.Client_Name   = res.customer_name || item.Client_Name;
                }
                processAndRenderData();
                if (popup) popup.remove();

                // ── 綁定料號後自動重撈訂單，並重新開啟更新視窗 ──
                if (item) {
                    // 先顯示提示
                    showTemporaryMessage('尚未綁定訂單，正在重新載入訂單資料...', true);
                    $.post(_applyUrl, {
                        action: 'get_orders_for_d_id',
                        d_id: newDid
                    }, function(orderRes) {
                        if (orderRes && orderRes.success) {
                            item.OrderList = orderRes.orders || [];
                            // 若訂單清單不為空則清除舊綁定，讓使用者重新選擇
                            if (item.OrderList.length > 0) {
                                item.Order_id = '';
                            }
                        }
                        // 找到觸發按鈕（表格列中的更新按鈕），沒有就用 null
                        var triggerBtn = null;
                        var tableBody = document.querySelector('#table-DOWN tbody');
                        if (tableBody) {
                            var rows = tableBody.querySelectorAll('tr');
                            rows.forEach(function(tr) {
                                var bomCell = tr.querySelector('td[name="bom"]');
                                if (bomCell && bomCell.textContent.trim() === bom) {
                                    triggerBtn = tr.querySelector('button');
                                }
                            });
                        }
                        processAndRenderData();
                        // 延遲一tick確保表格已重繪，再重開更新視窗
                        setTimeout(function() {
                            displayEditFormForRow(item, triggerBtn);
                        }, 150);
                    }, 'json').fail(function() {
                        // 就算撈訂單失敗，仍重開視窗（OrderList 維持空陣列）
                        processAndRenderData();
                        setTimeout(function() {
                            displayEditFormForRow(item, null);
                        }, 150);
                    });
                }
            } else {
                showTemporaryMessage('更新失敗：' + ((res && res.message) || '未知錯誤'), false);
            }
        }, 'json').fail(function(){ alert('伺服器通訊失敗'); });
    }

    // --- 刪除 BOM 功能（輸入BOM後自動顯示資料確認）---
    function promptDeleteBom() {
        // 權限檢查：A、C+R 或含有 D 權限者才可刪除BOM
        var pcode = window.displayPermissionCode || '';
        var allowDelete = (pcode === 'A' || pcode === 'C+R' || pcode.indexOf('D') !== -1);
        if (!allowDelete) { alert('您沒有刪除 BOM 的權限。'); return; }

        var existing = document.getElementById('delete-bom-modal');
        if (existing) existing.remove();

        var overlay = document.createElement('div');
        overlay.id = 'delete-bom-modal';
        overlay.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);display:flex;justify-content:center;align-items:center;z-index:10090;';

        var box = document.createElement('div');
        box.style.cssText = 'background:#fff;border-radius:6px;padding:20px;width:460px;max-width:95%;box-shadow:0 4px 20px rgba(0,0,0,0.3);';
        box.innerHTML =
            '<h4 style="margin-top:0;color:#dc3545;"><i class="fa fa-trash"></i> 刪除 BOM</h4>' +
            '<div style="margin-bottom:10px;">' +
            '<input type="text" id="del-bom-input" class="form-control" placeholder="輸入完整 BOM 號碼，自動顯示資料" autocomplete="off">' +
            '</div>' +
            '<div id="del-bom-info" style="min-height:60px;"><p style="color:#999;font-size:12px;margin:0;">請輸入 BOM 號碼...</p></div>' +
            '<div style="display:flex;justify-content:flex-end;gap:8px;margin-top:14px;">' +
            '<button type="button" id="del-bom-cancel-btn" class="btn btn-default">取消</button>' +
            '<button type="button" id="del-bom-confirm-btn" class="btn btn-danger" disabled>確認刪除</button>' +
            '</div>';

        overlay.appendChild(box);
        document.body.appendChild(overlay);

        var inp = document.getElementById('del-bom-input');
        var infoDiv = document.getElementById('del-bom-info');
        var confirmBtn = document.getElementById('del-bom-confirm-btn');
        var currentBom = '';
        var _queryTimer = null;

        document.getElementById('del-bom-cancel-btn').onclick = function() { overlay.remove(); };
        overlay.onclick = function(e) { if (e.target === overlay) overlay.remove(); };

        function queryBomInfo(bom) {
            bom = bom.toUpperCase();
            if (!bom) {
                infoDiv.innerHTML = '<p style="color:#999;font-size:12px;margin:0;">請輸入 BOM 號碼...</p>';
                confirmBtn.disabled = true;
                currentBom = '';
                return;
            }

            // 先從 fullDataset 找（完全比對）
            var found = fullDataset.find(function(i){ return i.bom && i.bom.toUpperCase() === bom; });
            if (found) {
                renderBomInfo(found);
                currentBom = found.bom;
                confirmBtn.disabled = false;
            } else {
                // 找不到時顯示警告但仍允許刪除
                infoDiv.innerHTML =
                    '<div style="padding:10px;background:#fff3cd;border:1px solid #ffc107;border-radius:4px;font-size:12px;">' +
                    '<i class="fa fa-exclamation-triangle" style="color:#856404;"></i> ' +
                    '在目前畫面找不到 BOM「' + escapeHtml(bom) + '」。<br>' +
                    '<small style="color:#856404;">此 BOM 可能已被篩選掉，但若存在仍可刪除。</small>' +
                    '</div>';
                currentBom = bom;
                confirmBtn.disabled = false;
            }
        }

        function renderBomInfo(data) {
            var processes = window.bomPSList ? window.bomPSList.filter(function(p){ return p.bom === data.bom; }) : [];
            var processNames = processes.map(function(p){ return (p.bom_sn||'') + ' ' + (p.ProcessName||''); }).join('、') || '無';
            var orderInfo = '未綁定';
            if (data.Order_id === 'B') {
                orderInfo = '備庫';
            } else if (data.Order_id && data.OrderList) {
                var o = data.OrderList.find(function(x){ return String(x.Order_id) === String(data.Order_id); });
                orderInfo = o ? formatOrderOptionText(o) : data.Order_id;
            }
            infoDiv.innerHTML =
                '<div style="background:#fff5f5;border:1px solid #f5c6cb;border-radius:4px;padding:10px;font-size:12px;">' +
                '<div style="color:#721c24;font-weight:bold;margin-bottom:6px;"><i class="fa fa-info-circle"></i> 即將刪除以下 BOM 及所有相關資料：</div>' +
                '<table style="width:100%;border-collapse:collapse;">' +
                '<tr><td style="padding:2px 6px;color:#555;width:90px;">BOM</td><td style="padding:2px 6px;font-weight:bold;">' + escapeHtml(data.bom) + '</td></tr>' +
                '<tr><td style="padding:2px 6px;color:#555;">料號</td><td style="padding:2px 6px;">' + escapeHtml(data.d_id||'') + '</td></tr>' +
                '<tr><td style="padding:2px 6px;color:#555;">客戶</td><td style="padding:2px 6px;">' + escapeHtml(data.Client_Name||'') + '</td></tr>' +
                '<tr><td style="padding:2px 6px;color:#555;">數量</td><td style="padding:2px 6px;">' + escapeHtml(String(data.Qty||'')) + '</td></tr>' +
                '<tr><td style="padding:2px 6px;color:#555;">綁定訂單</td><td style="padding:2px 6px;">' + escapeHtml(orderInfo) + '</td></tr>' +
                '<tr><td style="padding:2px 6px;color:#555;">製程</td><td style="padding:2px 6px;">' + escapeHtml(processNames) + '</td></tr>' +
                '</table>' +
                '<div style="margin-top:8px;color:#721c24;font-size:11px;">⚠️ 此操作將同時刪除所有相關 bom_ing、QC 記錄，完全無法復原！</div>' +
                '</div>';
        }

        // 輸入時自動查詢（debounce 300ms）
        inp.addEventListener('input', function() {
            clearTimeout(_queryTimer);
            var val = inp.value.trim();
            _queryTimer = setTimeout(function(){ queryBomInfo(val); }, 300);
        });

        confirmBtn.onclick = function() {
            if (!currentBom) return;
            if (!confirm('⚠️ 最終確認：永久刪除 BOM「' + currentBom + '」？\n此操作無法復原！')) return;
            confirmBtn.disabled = true;
            confirmBtn.textContent = '刪除中...';
            $.ajax({
                url: '',
                type: 'POST',
                data: { action: 'delete_bom', bom: currentBom },
                dataType: 'json',
                success: function(res) {
                    if (res.success) {
                        overlay.remove();
                        showTemporaryMessage('BOM 已刪除：' + currentBom, true);
                        fullDataset = fullDataset.filter(function(item){ return item.bom !== currentBom; });
                        processAndRenderData();
                    } else {
                        showTemporaryMessage('刪除失敗：' + (res.message || '未知錯誤'), false);
                        confirmBtn.disabled = false;
                        confirmBtn.textContent = '確認刪除';
                    }
                },
                error: function() {
                    alert('與伺服器通訊失敗');
                    confirmBtn.disabled = false;
                    confirmBtn.textContent = '確認刪除';
                }
            });
        };

        setTimeout(function(){ inp.focus(); }, 100);
    }

    // --- Function to open Search Completed BOM Modal ---
    function openSearchCompletedModal() {
        // Remove existing modal if any
        const existingModal = document.getElementById('search-completed-bom-modal');
        if (existingModal) {
            existingModal.remove();
        }

        const modalHtml = `
            <div id="search-completed-bom-modal" style="position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);display:flex;justify-content:center;align-items:flex-start;z-index:10050;padding-top:30px;overflow-y:auto;">
                <div style="background:#fff;padding:20px;border-radius:5px;box-shadow:0 2px 10px rgba(0,0,0,0.2);width:96%;max-width:1400px;margin:0 auto 30px auto;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                        <h4 style="margin:0;">查詢已完工資料</h4>
                        <button type="button" onclick="document.getElementById('search-completed-bom-modal').remove();" style="font-size:1.5rem;background:none;border:none;cursor:pointer;line-height:1;">&times;</button>
                    </div>
                    <div style="display:flex;gap:8px;margin-bottom:12px;">
                        <input type="text" id="completed-bom-search-term" class="form-control" placeholder="輸入 BOM、料號或客戶" style="flex:1;">
                        <button type="button" id="execute-completed-search-btn" class="btn btn-primary btn-sm">查詢</button>
                    </div>
                    <div id="completed-bom-results-area" style="overflow-x:auto;overflow-y:auto;max-height:70vh;"></div>
                </div>
            </div>
        `;

        document.body.insertAdjacentHTML('beforeend', modalHtml);

        // --- Add event listener to close modal on backdrop click ---
        const modalElement = document.getElementById('search-completed-bom-modal');
        if (modalElement) {
            modalElement.addEventListener('click', function(event) {
                if (event.target === modalElement) { // Check if the click is on the backdrop itself
                    modalElement.remove();
                }
            });
        }
        // --- Add event listener for Enter key on search input ---
        document.getElementById('completed-bom-search-term').addEventListener('keydown', function(event) {
            if (event.key === 'Enter') {
                event.preventDefault(); // Prevent default Enter action (like form submission)
                document.getElementById('execute-completed-search-btn').click(); // Simulate click on search button
            }
        });

        document.getElementById('execute-completed-search-btn').addEventListener('click', function() {
            const searchTerm = document.getElementById('completed-bom-search-term').value.trim();
            const resultsArea = document.getElementById('completed-bom-results-area');
            resultsArea.innerHTML = '<p>查詢中...</p>';

            if (!searchTerm) {
                resultsArea.innerHTML = '<p style="color: red;">請輸入 BOM、料號或客戶進行查詢。</p>';
                return;
            }

            $.ajax({
                url: _phpSelf, // Current file
                type: 'POST',
                data: {
                    action: 'search_completed_bom',
                    searchTerm: searchTerm
                },
                dataType: 'json',
                success: function(response) {
                    resultsArea.innerHTML = '';
                    if (!response.success || !response.data || !response.data.length) {
                        resultsArea.innerHTML = '<p>' + escapeHtml(response.message||'查無資料。') + '</p>';
                        return;
                    }
                    var data = response.data;
                    var maxProc = response.max_process_count || 0;
                    var priceMap = response.price_map || {}; // [bom][bom_sn]
                    var baseUrl = window.location.protocol + '//' + window.location.host + '/nas/';

                    // 格式化單價：整數不顯示小數，否則顯示一位小數
                    function fmtPrice(v) {
                        var n = parseFloat(v) || 0;
                        return n === 0 ? '' : (n % 1 === 0 ? n.toFixed(0) : n.toFixed(1));
                    }

                    var table = document.createElement('table');
                    table.className = 'table table-bordered table-striped table-condensed';
                    table.style.cssText = 'font-size:12px;white-space:nowrap;min-width:100%;';
                    var thead = document.createElement('thead');
                    var thRow = document.createElement('tr');
                    function mkTh(t,w){ var th=document.createElement('th'); th.textContent=t; if(w) th.style.minWidth=w; th.style.padding='4px 6px'; return th; }
                    thRow.appendChild(mkTh('客戶','60px')); thRow.appendChild(mkTh('BOM','130px'));
                    thRow.appendChild(mkTh('料號','120px')); thRow.appendChild(mkTh('數量','50px'));
                    for (var ci=1; ci<=maxProc; ci++) thRow.appendChild(mkTh(String(ci),'90px'));
                    thead.appendChild(thRow); table.appendChild(thead);
                    var tbody = document.createElement('tbody');
                    data.forEach(function(item) {
                        var tr = document.createElement('tr');

                        // ── 客戶欄 ──
                        var tdC = document.createElement('td'); tdC.style.padding='4px 6px';
                        var clientDiv = document.createElement('div'); clientDiv.style.cssText='display:flex;flex-direction:column;gap:3px;align-items:flex-start;';
                        var clientNameSpan = document.createElement('span'); clientNameSpan.textContent = item.client_name_display || item.Client_Name || '';
                        clientDiv.appendChild(clientNameSpan);
                        var cancelCloseBtn = document.createElement('button');
                        cancelCloseBtn.type = 'button';
                        cancelCloseBtn.className = 'btn btn-xs btn-warning';
                        cancelCloseBtn.textContent = '取消結案';
                        cancelCloseBtn.style.cssText = 'margin-top:2px;font-size:11px;padding:1px 5px;';
                        (function(bomVal, rowEl, btnEl) {
                            btnEl.onclick = function() {
                                if (!confirm('確認取消結案 BOM：' + bomVal + '？\n取消後將回到進行中狀態。')) return;
                                btnEl.disabled = true;
                                $.ajax({
                                    url: _phpSelf, type: 'POST',
                                    data: { action: 'cancel_bom_close', bom: bomVal },
                                    dataType: 'json',
                                    success: function(res) {
                                        if (res.success) {
                                            showTemporaryMessage('已取消結案：' + bomVal, true);
                                            var modal = document.getElementById('search-completed-bom-modal');
                                            if (modal) modal.remove();
                                            fetchDataAndFilter(function() {
                                                var globalSearch = document.getElementById('global-search');
                                                if (globalSearch) globalSearch.value = bomVal;
                                            });
                                        } else {
                                            alert('取消結案失敗：' + (res.message || '未知錯誤'));
                                            btnEl.disabled = false;
                                        }
                                    },
                                    error: function() { alert('伺服器通訊失敗'); btnEl.disabled = false; }
                                });
                            };
                        })(item.bom, tr, cancelCloseBtn);
                        clientDiv.appendChild(cancelCloseBtn);
                        tdC.appendChild(clientDiv); tr.appendChild(tdC);

                        // ── BOM 欄 ──
                        var tdB = document.createElement('td'); tdB.style.padding='4px 6px';
                        var cc = item.priority_type==='E'?'circle_red':item.priority_type==='U'?'circle_y':'circle_green';
                        var bomDiv = document.createElement('div'); bomDiv.style.cssText='display:flex;align-items:center;gap:4px;';
                        var fig=document.createElement('figure'); fig.className=cc; fig.style.margin='0';
                        var aEx=document.createElement('a'); aEx.href='ms-excel:ofe|u|'+baseUrl+item.bom+'.xlsm'; aEx.target='_blank'; aEx.textContent=item.bom;
                        var cBom=document.createElement('button'); cBom.type='button'; cBom.className='btn btn-xs btn-copy'; cBom.title='複製BOM'; cBom.innerHTML='<i class="fa fa-copy"></i>';
                        (function(b){ cBom.onclick=function(e){ e.stopPropagation(); copyToClipboard(b,this); }; })(item.bom);
                        bomDiv.appendChild(fig); bomDiv.appendChild(aEx); bomDiv.appendChild(cBom); tdB.appendChild(bomDiv);
                        // 結案人 + 結案時間
                        if (item.closed_by_name || item.closed_at) {
                            var closedInfoDiv = document.createElement('div');
                            closedInfoDiv.style.cssText = 'font-size:10px;color:#999;margin-top:3px;line-height:1.4;';
                            var parts = [];
                            if (item.closed_by_name) parts.push('結：' + item.closed_by_name);
                            if (item.closed_at) parts.push(item.closed_at);
                            closedInfoDiv.textContent = parts.join('　');
                            tdB.appendChild(closedInfoDiv);
                        }
                        tr.appendChild(tdB);

                        // ── 料號欄（含加工總單價）──
                        var tdD = document.createElement('td'); tdD.style.padding='4px 6px';
                        var didDiv=document.createElement('div'); didDiv.style.cssText='display:flex;align-items:center;gap:4px;';
                        var aD=document.createElement('a'); aD.href='#';
                        (function(b,d){ aD.onclick=function(e){ e.preventDefault(); openBomFiles(b, d); }; })(item.bom, item.d_id);
                        aD.textContent=item.d_id;
                        var cDid=document.createElement('button'); cDid.type='button'; cDid.className='btn btn-xs btn-copy'; cDid.title='複製料號'; cDid.innerHTML='<i class="fa fa-copy"></i>';
                        (function(d){ cDid.onclick=function(e){ e.stopPropagation(); copyToClipboard(d,this); }; })(item.d_id);
                        didDiv.appendChild(aD); didDiv.appendChild(cDid); tdD.appendChild(didDiv);

                        // 計算加工總單價（各製程單價加總，不乘數量）
                        var bomPrices = priceMap[item.bom] || {};
                        var totalUnitPrice = 0, noPriceCount = 0;
                        (item.processes || []).forEach(function(p) {
                            var pi = bomPrices[String(p.bom_sn)] || null;
                            var rawP = pi ? (parseFloat(pi.modified_unit_price) || parseFloat(pi.price) || 0) : 0;
                            if (rawP > 0) { totalUnitPrice += rawP; } else { noPriceCount++; }
                        });
                        if ((item.processes||[]).length > 0) {
                            var priceDiv = document.createElement('div');
                            priceDiv.style.cssText = 'margin-top:3px;font-size:11px;line-height:1.3;';
                            var priceHtml = totalUnitPrice > 0
                                ? '<span style="color:#0a6;font-weight:bold;">$' + (totalUnitPrice % 1 === 0 ? totalUnitPrice.toFixed(0) : totalUnitPrice.toFixed(1)) + '</span>'
                                : '<span style="color:#ccc;">$--</span>';
                            if (noPriceCount > 0) priceHtml += ' <span style="color:#aaa;font-size:10px;">(' + noPriceCount + '關無價)</span>';
                            priceDiv.innerHTML = priceHtml;
                            tdD.appendChild(priceDiv);
                        }
                        tr.appendChild(tdD);

                        // ── 數量欄 ──
                        var tdQ=document.createElement('td'); tdQ.style.cssText='padding:4px 6px;text-align:right;'; tdQ.textContent=item.Qty||''; tr.appendChild(tdQ);

                        // ── 製程欄（含各製程單價）──
                        var procs=item.processes||[];
                        for (var pi=0; pi<maxProc; pi++) {
                            var tdP=document.createElement('td'); tdP.style.cssText='padding:4px 6px;font-size:11px;vertical-align:top;';
                            var proc=procs[pi];
                            if (proc) {
                                var dn=document.createElement('div'); dn.textContent=(proc.process_no||'')+(proc.ProcessName?' '+proc.ProcessName:''); tdP.appendChild(dn);
                                var od=''; if(proc.outsource_date){ var op=proc.outsource_date.split('/'); if(op.length===3) od=parseInt(op[1])+'/'+parseInt(op[2]); }
                                var mk=proc.maker_id||'';
                                if(od||mk){ var sm=document.createElement('small'); sm.style.color='#888'; sm.textContent=(od?od:'')+(od&&mk?' ':'')+mk; tdP.appendChild(sm); }
                                if(proc.return_date){ var rp=proc.return_date.split('/'); var rd=rp.length===3?parseInt(rp[1])+'/'+parseInt(rp[2]):proc.return_date; var dr=document.createElement('div'); dr.style.cssText='color:#2a7ae2;font-weight:bold;'; dr.textContent='回廠:'+rd; tdP.appendChild(dr); }
                                // 顯示此製程單價
                                var pInfo = bomPrices[String(proc.bom_sn)] || null;
                                var pVal = pInfo ? (parseFloat(pInfo.modified_unit_price) || parseFloat(pInfo.price) || 0) : 0;
                                if (pVal > 0) {
                                    var pSpan = document.createElement('div');
                                    pSpan.style.cssText = 'color:#0a6;font-size:10px;margin-top:1px;';
                                    pSpan.textContent = '$' + fmtPrice(pVal);
                                    tdP.appendChild(pSpan);
                                }
                            }
                            tr.appendChild(tdP);
                        }
                        tbody.appendChild(tr);
                    });
                    table.appendChild(tbody); resultsArea.appendChild(table);
                },
                error: function(xhr, status, error) {
                    resultsArea.innerHTML = '<p style="color: red;">查詢時發生錯誤，請稍後再試。</p>';
                    console.error("AJAX error for completed BOM search:", status, error, xhr.responseText);
                }
            });
        });
    }

    // ── 操作流水帳 Modal ──────────────────────────────────────────────────────
    var _opLogMode  = 'day';
    var _opLogDate  = (function(){ var d=new Date(); return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0'); })();
    var _opLogMonth = (function(){ var d=new Date(); return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0'); })();
    var _opLogSearch = '';

    var _opTypeLabel = { manual_close:'人工結案', cancel_close:'取消結案', transfer:'移轉', create_bom:'新增BOM' };

    function _opDateAdd(delta) {
        var d = new Date(_opLogDate + 'T00:00:00');
        d.setDate(d.getDate() + delta);
        _opLogDate = d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0');
    }
    function _opMonthAdd(delta) {
        var parts = _opLogMonth.split('-');
        var d = new Date(parseInt(parts[0]), parseInt(parts[1])-1+delta, 1);
        _opLogMonth = d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0');
    }

    function openOpLogModal() {
        var ex = document.getElementById('op-log-modal');
        if (ex) ex.parentNode.removeChild(ex);
        _opLogMode = 'day';
        _opLogDate = (function(){ var d=new Date(); return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0'); })();
        _opLogMonth = (function(){ var d=new Date(); return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0'); })();
        _opLogSearch = '';

        var modal = document.createElement('div');
        modal.id = 'op-log-modal';
        modal.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);display:flex;justify-content:center;align-items:flex-start;z-index:10060;padding-top:20px;overflow-y:auto;';
        modal.innerHTML =
            '<div style="background:#fff;border-radius:6px;padding:20px;width:98%;max-width:1200px;margin:0 auto 30px;box-shadow:0 4px 16px rgba(0,0,0,0.25);">'
            + '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">'
            + '<h4 style="margin:0;"><i class="fa fa-list-alt"></i>&nbsp;BOM 操作流水帳</h4>'
            + '<button type="button" id="op-log-close-btn" style="font-size:1.4rem;background:none;border:none;cursor:pointer;">&times;</button>'
            + '</div>'
            // 搜尋 + 模式切換
            + '<div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:12px;">'
            + '<input type="text" id="op-log-search" class="form-control" placeholder="客戶/料號/BOM 模糊搜尋" style="flex:1;min-width:180px;max-width:280px;">'
            + '<button type="button" id="op-log-mode-day" class="btn btn-sm btn-primary">日</button>'
            + '<button type="button" id="op-log-mode-month" class="btn btn-sm btn-default">月</button>'
            + '<span id="op-log-nav-wrap" style="display:flex;align-items:center;gap:6px;">'
            + '<button type="button" id="op-log-prev" class="btn btn-xs btn-default">&lt;</button>'
            + '<span id="op-log-date-label" style="font-weight:bold;min-width:90px;text-align:center;">' + _opLogDate + '</span>'
            + '<button type="button" id="op-log-next" class="btn btn-xs btn-default">&gt;</button>'
            + '</span>'
            + '<button type="button" id="op-log-query-btn" class="btn btn-sm btn-info"><i class="fa fa-search"></i>&nbsp;查詢</button>'
            + '</div>'
            // 統計列
            + '<div id="op-log-stats" style="margin-bottom:10px;font-size:12px;color:#555;"></div>'
            // 結果表格區
            + '<div id="op-log-results" style="overflow-x:auto;max-height:60vh;overflow-y:auto;"><p style="color:#aaa;">點擊查詢載入資料</p></div>'
            + '</div>';
        document.body.appendChild(modal);

        // 關閉按鈕 + 點背景關閉
        document.getElementById('op-log-close-btn').onclick = function() { modal.parentNode.removeChild(modal); };
        modal.addEventListener('click', function(e){ if (e.target === modal) modal.parentNode.removeChild(modal); });

        // 模式切換
        document.getElementById('op-log-mode-day').onclick = function() {
            _opLogMode = 'day';
            document.getElementById('op-log-mode-day').className = 'btn btn-sm btn-primary';
            document.getElementById('op-log-mode-month').className = 'btn btn-sm btn-default';
            document.getElementById('op-log-date-label').textContent = _opLogDate;
            _opLogFetch();
        };
        document.getElementById('op-log-mode-month').onclick = function() {
            _opLogMode = 'month';
            document.getElementById('op-log-mode-day').className = 'btn btn-sm btn-default';
            document.getElementById('op-log-mode-month').className = 'btn btn-sm btn-primary';
            document.getElementById('op-log-date-label').textContent = _opLogMonth;
            _opLogFetch();
        };

        // 前後導航
        document.getElementById('op-log-prev').onclick = function() {
            if (_opLogMode==='day') { _opDateAdd(-1); document.getElementById('op-log-date-label').textContent = _opLogDate; }
            else { _opMonthAdd(-1); document.getElementById('op-log-date-label').textContent = _opLogMonth; }
            _opLogFetch();
        };
        document.getElementById('op-log-next').onclick = function() {
            if (_opLogMode==='day') { _opDateAdd(1); document.getElementById('op-log-date-label').textContent = _opLogDate; }
            else { _opMonthAdd(1); document.getElementById('op-log-date-label').textContent = _opLogMonth; }
            _opLogFetch();
        };

        // Enter 觸發查詢
        document.getElementById('op-log-search').addEventListener('keydown', function(e){ if (e.key==='Enter') _opLogFetch(); });
        document.getElementById('op-log-query-btn').onclick = _opLogFetch;

        // 初始載入
        _opLogFetch();
    }

    function _opLogFetch() {
        _opLogSearch = (document.getElementById('op-log-search') || {}).value || '';
        var $results = document.getElementById('op-log-results');
        var $stats   = document.getElementById('op-log-stats');
        if (!$results) return;
        $results.innerHTML = '<p style="color:#aaa;"><i class="fa fa-spinner fa-spin"></i> 載入中...</p>';
        if ($stats) $stats.innerHTML = '';

        $.ajax({
            url: _phpSelf, type: 'POST',
            data: { action: 'fetch_bom_operation_log', mode: _opLogMode, date: _opLogDate, month: _opLogMonth, search: _opLogSearch },
            dataType: 'json',
            success: function(res) {
                if (!res.success) { $results.innerHTML = '<p style="color:red;">' + escapeHtml(res.message||'查詢失敗') + '</p>'; return; }

                // 統計
                if ($stats) {
                    var statHtml = '<strong>統計：</strong>&nbsp;';
                    var total = 0;
                    var statParts = [];
                    $.each(res.stats||{}, function(type, cnt) {
                        var lbl = (res.op_labels||{})[type] || type;
                        statParts.push('<span style="margin-right:12px;">' + escapeHtml(lbl) + '&nbsp;<strong>' + cnt + '</strong></span>');
                        total += cnt;
                    });
                    if (statParts.length) statHtml += statParts.join('') + '&nbsp;│&nbsp;合計 <strong>' + total + '</strong> 筆';
                    else statHtml += '無資料';
                    $stats.innerHTML = statHtml;
                }

                var rows = res.data || [];
                if (!rows.length) { $results.innerHTML = '<p style="color:#aaa;">無操作紀錄</p>'; return; }

                var table = '<table class="table table-bordered table-condensed" style="font-size:12px;white-space:nowrap;min-width:100%;">'
                    + '<thead><tr>'
                    + '<th style="padding:4px 6px;">時間</th>'
                    + '<th style="padding:4px 6px;">BOM</th>'
                    + '<th style="padding:4px 6px;">料號</th>'
                    + '<th style="padding:4px 6px;">客戶</th>'
                    + '<th style="padding:4px 6px;">操作類型</th>'
                    + '<th style="padding:4px 6px;">操作人</th>'
                    + '<th style="padding:4px 6px;">細節</th>'
                    + '</tr></thead><tbody>';
                rows.forEach(function(r) {
                    var typeLabel = (res.op_labels||{})[r.operation_type] || r.operation_type;
                    var typeColor = r.operation_type==='manual_close'?'#E74C3C':r.operation_type==='cancel_close'?'#F39C12':r.operation_type==='transfer'?'#2980B9':'#27AE60';
                    var details = '';
                    try {
                        var d = JSON.parse(r.details_json || '{}');
                        // 翻譯舊版英文 action 值
                        var _actMap = {'manual_close':'人工結案','cancel_close':'取消結案','transfer':'移轉','create_bom':'新增BOM'};
                        var parts = [];
                        Object.entries(d).forEach(function(kv) {
                            var k = kv[0], v = String(kv[1] || '');
                            // 翻譯已知 key/value
                            var displayK = (k === 'action' || k === '操作') ? '' : k;
                            var displayV = _actMap[v] || v;
                            if (k === 'by' || k === 'action') return; // 'by' 冗餘（已顯示在操作人欄），'action' 合入值
                            parts.push(displayK ? displayK + '：' + displayV : displayV);
                        });
                        // 若只有 action 欄且已被 skip，直接翻譯
                        if (!parts.length && d.action) parts.push(_actMap[d.action] || d.action);
                        details = parts.join('；');
                    } catch(e) { details = r.details_json || ''; }
                    table += '<tr>'
                        + '<td style="padding:4px 6px;color:#555;">' + escapeHtml(r.operated_at||'') + '</td>'
                        + '<td style="padding:4px 6px;font-weight:bold;">' + escapeHtml(r.bom||'') + '</td>'
                        + '<td style="padding:4px 6px;">' + escapeHtml(r.d_id||'') + '</td>'
                        + '<td style="padding:4px 6px;">' + escapeHtml(r.client_name||'') + '</td>'
                        + '<td style="padding:4px 6px;"><span style="color:' + typeColor + ';font-weight:bold;">' + escapeHtml(typeLabel) + '</span></td>'
                        + '<td style="padding:4px 6px;">' + escapeHtml(r.operator_name||String(r.operator_id||'')) + '</td>'
                        + '<td style="padding:4px 6px;color:#888;font-size:11px;">' + escapeHtml(details) + '</td>'
                        + '</tr>';
                });
                table += '</tbody></table>';
                $results.innerHTML = table;
            },
            error: function() { $results.innerHTML = '<p style="color:red;">網路錯誤，請重試</p>'; }
        });
    }

    // Add event listener for the new button in DOMContentLoaded
    document.addEventListener("DOMContentLoaded", function() {
        // ... (existing DOMContentLoaded code) ...
        const btnSearchCompleted = document.getElementById('btn-search-completed');
        if (btnSearchCompleted) {
            btnSearchCompleted.addEventListener('click', openSearchCompletedModal);
        }
        const btnOpLog = document.getElementById('btn-op-log');
        if (btnOpLog) {
            btnOpLog.addEventListener('click', openOpLogModal);
        }
    });

    // Ensure escapeHtml is available if it's not already global
    if (typeof escapeHtml !== 'function') {
        function escapeHtml(text) {
            if (text == null) return "";
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return String(text).replace(/[&<>"']/g, function(m) {
                return map[m];
            });
        }
    }
    // Ensure copyToClipboard is available (it should be from the existing script)
</script>
<script>
    // QR Code Modal event listeners (moved from where generateQrModalForRow was)
    document.addEventListener('DOMContentLoaded', function() {

        // General Bootstrap modal cleanup (Enhanced with setTimeout)
        $(document).on('hidden.bs.modal', '.modal', function() {
            // Adding a very short delay
            setTimeout(function() {
                // Check if any modal is still considered "open" by Bootstrap or visibly present.
                if ($('.modal.in, .modal.show').filter(':visible').length === 0) {
                    // If truly no modals are active or visible, clean up.
                    $('body').removeClass('modal-open');
                    $('.modal-backdrop').remove();
                } else {
                    // If other modals are active/visible, ensure body has 'modal-open'.
                    if (!$('body').hasClass('modal-open')) {
                        $('body').addClass('modal-open');
                    }
                }
            }, 50); // 50ms delay
        });

        // Close QR Code modal on click outside its content
        $(document).on('mousedown', function(event) {
            var $visibleQrModal = $('.modal[id^="myModal_qrcode_"]:visible');
            if ($visibleQrModal.length) {
                // If the click is outside the modal-content 
                // and not on a modal trigger (standard or custom for this page)
                if (!$(event.target).closest($visibleQrModal.find('.modal-content')).length &&
                    !$(event.target).is('[data-toggle="modal"]') &&
                    !$(event.target).closest('[data-toggle="modal"]').length &&
                    !$(event.target).is('.js-show-qr-modal') &&
                    !$(event.target).closest('.js-show-qr-modal').length) {
                    $visibleQrModal.modal('hide');
                }
            }
        });

        // --- QR Code Modal: Show without backdrop ---
        // This uses event delegation on 'document' to catch clicks on '.js-show-qr-modal'
        // which might be added dynamically to the page (e.g., inside #modals-container or the table).
        $(document).on('click', '.js-show-qr-modal', function() {
            var modalId = $(this).data('modal-id');
            if (modalId && $('#' + modalId).length) { // Check if modalId is valid and element exists
                $('#' + modalId).modal({
                    backdrop: false, // Do not show backdrop
                    show: true
                });
            }
        });

        // Attach event listeners to #modals-container (ensure this div exists in your HTML)
        $('#modals-container').on('input change', '.qty-per-unit, .packaging-type', function() {
            const $modal = $(this).closest('.modal');
            if (!$modal.find('.qty-per-unit').length) return;

            const totalQty = parseFloat($modal.find('.modal-body').data('total-qty')) || 0;
            const $qtyPerUnitInput = $modal.find('.qty-per-unit');
            const $packagingTypeSelect = $modal.find('.packaging-type');
            const $calculationResultDiv = $modal.find('.calculation-result');

            let qtyPerUnit = parseFloat($qtyPerUnitInput.val()) || 0;
            const packagingType = $packagingTypeSelect.val();

            if (qtyPerUnit > totalQty && totalQty > 0) {
                alert("每單位數量 (" + qtyPerUnit + ") 不可超過總數 (" + totalQty + ")。");
                $qtyPerUnitInput.val(totalQty);
                qtyPerUnit = totalQty;
            }

            if (qtyPerUnit > 0 && totalQty > 0) {
                $calculationResultDiv.text(`共 ${qtyPerUnit} ${packagingType}`);
            } else {
                $calculationResultDiv.text(`共 ? ${packagingType}`);
            }
        });

        $('#modals-container').on('click', '.clear-button', function() {
            const $modal = $(this).closest('.modal');
            if (!$modal.find('.qty-per-unit').length) return;
            $modal.find('.qty-per-unit').val('');
            $modal.find('.packaging-type').prop('disabled', false).trigger('change');
            $modal.find('.direct-print-qrcode-button').show();
            $modal.find('.qrcode-display-area').hide().html('');
        });

        // Direct Print QR Code Button logic (copied and adapted from QC_check_list.php)
        // This includes the printHtml template and window.open logic.
        // Ensure the path to generate_qrcode.php is correct: ../../views/QC/generate_qrcode.php
        $('#modals-container').on('click', '.direct-print-qrcode-button', function() {
            const $modal = $(this).closest('.modal');
            const bomForQr = $modal.find('.modal-body').data('bom');
            const dIdFromModal = $modal.find('.modal-body').data('d-id') || 'N/A';
            const totalQty = parseFloat($modal.find('.modal-body').data('total-qty')) || 0;
            const packagingTypeVal = $modal.find('.packaging-type option:selected').text();
            const userInputTotalBoxes = parseFloat($modal.find('.qty-per-unit').val()) || 0;

            const qrUrlForPrint = `${location.origin}/EGsystem/views/pm/schedule_T5.php?b=${encodeURIComponent(bomForQr)}`;
            const generateQrCodePhpUrlForPrint = `../../views/QC/generate_qrcode.php?text=${encodeURIComponent(qrUrlForPrint)}`;
            const qrCodeForPrintHtml = `<img src="${generateQrCodePhpUrlForPrint}" alt="QR Code" class="qr-code-image">`;

            if (userInputTotalBoxes <= 0) {
                alert("請輸入有效的總箱數才能列印。");
                return;
            }
            const totalPagesToPrint = userInputTotalBoxes;
            if (totalQty < 0) {
                alert("總數量為0或無效，無法列印。");
                return;
            }
            const today = new Date();
            const dateString = `${today.getFullYear()}.${String(today.getMonth() + 1).padStart(2, '0')}.${String(today.getDate()).padStart(2, '0')}`;
            let allPagesHtml = '';
            for (let currentPage = 1; currentPage <= totalPagesToPrint; currentPage++) {
                let printHtml = `
                    <html><head><title>列印 - ${escapeHtml(bomForQr)} - 箱號 ${currentPage}/${totalPagesToPrint}</title><style>
                    @page { margin-top: 0mm; margin-bottom: 0mm; size: 70mm 50mm; }
                    body { font-family: Arial, "微軟正黑體", "Microsoft JhengHei", sans-serif; margin: 0; font-size: 8pt; }
                    .print-container { width: 70mm; height: 50mm; border: none; padding: 2mm; box-sizing: border-box; overflow: hidden; page-break-after: always; }
                    .part-number-row { font-size: 12pt; font-weight: bold; text-align: left; margin-bottom: 0.5mm; padding-bottom: 0mm; }
                    .content-table { width: 100%; border-collapse: collapse; } .content-table td { padding: 1mm; vertical-align: top; }
                    .left-col { width: 50%; text-align: left; font-size: 10pt; } .right-col { text-align: left; vertical-align: top; margin: 0; }
                    .label { font-weight: bold; font-size: 9pt; } .info-line { line-height: 1.5; font-size: 9pt; }
                    .info-line:not(:last-child) { margin-bottom: 0.5mm; }
                    .company-footer { margin-top: 0mm; padding-top: 1mm; font-size: 11pt; font-weight: bold; text-align: justify; text-justify: inter-word; border-top: 1px solid black; }
                    .qr-code-image { max-width: 90%; height: auto; display: block; margin: 0; }
                    </style></head><body><div class="print-container">
                    <div class="part-number-row">料號：${escapeHtml(dIdFromModal)}</div>
                    <table class="content-table"><tr>
                    <td class="left-col">
                        <div class="info-line"><span class="label">製令：</span>${escapeHtml(bomForQr)}</div>
                        <div class="info-line"><span class="label">總數：</span>${totalQty}</div>
                        <div class="info-line"><span class="label">容器：</span>${escapeHtml(packagingTypeVal)}</div>
                        <div class="info-line"><span class="label">箱號：</span>${currentPage} / ${totalPagesToPrint}</div>
                        <div class="info-line"><span class="label">日期：</span>${dateString}</div>
                    </td><td class="right-col">${qrCodeForPrintHtml}</td>
                    </tr></table>
                    <div class="company-footer">超正齒輪科技有限公司 2-QA-01-02</div>
                    </div></body></html>`;
                allPagesHtml += printHtml;
            }
            if (allPagesHtml) {
                let printWindow = window.open('', '_blank', 'height=600,width=1000');
                printWindow.document.write(`<html><head><title>列印預覽 - ${escapeHtml(bomForQr)}</title><style>
                    @page { margin-top: 0mm; margin-bottom: 0mm; size: 70mm 50mm; }
                    body { font-family: Arial, "微軟正黑體", "Microsoft JhengHei", sans-serif; margin: 0; font-size: 8pt; }
                    .print-container { width: 70mm; height: 50mm; border: none; padding: 2mm; box-sizing: border-box; overflow: hidden; page-break-after: always; }
                    .part-number-row { font-size: 12pt; font-weight: bold; text-align: left; margin-bottom: 0.5mm; padding-bottom: 0mm; }
                    .content-table { width: 100%; border-collapse: collapse; } .content-table td { padding: 1mm; vertical-align: top; }
                    .left-col { text-align: left; font-size: 10pt; } .right-col { text-align: left; vertical-align: top; margin: 0; }
                    .label { font-weight: bold; font-size: 9pt; } .info-line { line-height: 1.5; font-size: 9pt; }
                    .info-line:not(:last-child) { margin-bottom: 0.5mm; }
                    .company-footer { margin-top: 0mm; padding-top: 1mm; font-size: 11pt; font-weight: bold; text-align: justify; text-justify: inter-word; border-top: 1px solid black; }
                    .qr-code-image { max-width: 90%; height: auto; display: block; margin: 0; }
                    </style></head><body>${allPagesHtml}</body></html>`);
                printWindow.document.close();
                printWindow.focus();
                setTimeout(() => {
                    printWindow.print();
                }, 500);
            }
        });

        $('#modals-container').on('keypress', '.qty-per-unit', function(e) {
            if (e.which === 13) {
                e.preventDefault();
                const $modal = $(this).closest('.modal');
                const $printButton = $modal.find('.direct-print-qrcode-button');
                const qtyPerUnitVal = $(this).val();
                if (qtyPerUnitVal && parseFloat(qtyPerUnitVal) > 0 && $printButton.is(':visible')) {
                    $printButton.click();
                }
            }
        });

        $('#modals-container').on('shown.bs.modal', '.modal[id^="myModal_qrcode_"]', function() {
            var $modal = $(this);
            var $qtyInput = $modal.find('.qty-per-unit');
            if ($qtyInput.length) {
                if ($qtyInput.val() === '' || parseFloat($qtyInput.val()) === 0) {
                    $qtyInput.val(1).trigger('input'); // Set to 1 and trigger calculation
                }
                $qtyInput.focus();
            }
        });

        // Popover for Report Date
        $('body').on('mouseenter', '.report-date-trigger', function() {
            var $this = $(this);
            if ($this.data('bs.popover')) return; // Already initialized

            $this.popover({
                html: true,
                trigger: 'hover',
                placement: 'auto',
                container: 'body',
                title: '報工紀錄 (最近5筆)',
                content: function() {
                    var fids = $this.data('fids');
                    var divId = 'popover-content-' + Math.floor(Math.random() * 100000);
                    
                    $.ajax({
                        url: window.location.pathname.split('/').pop(), // Use the correct filename
                        type: 'POST',
                        data: { action: 'get_report_details_for_popover', bom_ing_fids: fids },
                        dataType: 'json',
                        success: function(res) {
                            var html = '';
                            if (res.success && res.data.length > 0) {
                                html = '<table class="table table-condensed table-bordered" style="margin:0; font-size:10px; background-color:#fff;"><thead><tr class="active"><th>時間</th><th>類別</th><th>人員</th><th>良/NG</th></tr></thead><tbody>';
                                res.data.forEach(function(r) {
                                    var timeDisplay = '';
                                    var typeDisplay = '';
                                    var opDisplay = '';
                                    
                                    if (r.setup_start_time) {
                                        timeDisplay += '<div>' + r.setup_start_time.substring(5, 16).replace('-','/') + '</div>';
                                        typeDisplay += '<div>架機</div>';
                                        opDisplay += '<div>' + (r.setup_operator || '-') + '</div>';
                                    }
                                    
                                    if (r.production_start_time) {
                                        timeDisplay += '<div>' + r.production_start_time.substring(5, 16).replace('-','/') + '</div>';
                                        typeDisplay += '<div>生產</div>';
                                        opDisplay += '<div>' + (r.operator || '-') + '</div>';
                                    }
                                    
                                    if (!timeDisplay) {
                                        timeDisplay = r.report_date ? r.report_date.substring(5, 10).replace('-','/') : '-';
                                        typeDisplay = '生產';
                                        opDisplay = r.operator || '-';
                                    }

                                    var ok = parseFloat(r.produced_qty) || 0;
                                    var ng = parseFloat(r.ng_qty) || 0;
                                    var finishedIcon = (r.is_finished == 1) ? ' <i class="fa fa-check-circle" style="color:green;"></i>' : '';
                                    
                                    html += `<tr><td>${timeDisplay}</td><td>${typeDisplay}</td><td>${opDisplay}</td><td>${ok} / <span style="${ng>0?'color:red;font-weight:bold;':''}">${ng}</span>${finishedIcon}</td></tr>`;
                                    if(r.remark) {
                                         html += `<tr><td colspan="4" style="color:#666; word-break:break-all;">備註: ${escapeHtml(r.remark)}</td></tr>`;
                                    }
                                });
                                html += '</tbody></table>';
                            } else {
                                html = '<div style="padding:5px;">無詳細報工紀錄</div>';
                            }
                            $('#' + divId).html(html);
                        },
                        error: function() {
                            $('#' + divId).html('<div style="padding:5px; color:red;">載入失敗</div>');
                        }
                    });
                    
                    return '<div id="' + divId + '" style="min-width:200px; min-height:50px;"><i class="fa fa-spinner fa-spin"></i> 載入中...</div>';
                }
            });
            $this.popover('show');
        });
    });

    // --- Open All Reports Modal ---
    function openAllReportsModal(bom) {
        $('#modal-all-reports-title').text(bom);
        $('#all-reports-body').html('<p class="text-center"><i class="fa fa-spinner fa-spin"></i> 載入中...</p>');
        $('#allReportsModal').modal('show');

        $.post(window.location.pathname.split('/').pop(), { action: 'get_all_reports_for_bom', bom: bom }, function(res) {
            if (res.success) {
                if (res.data.length === 0) {
                    $('#all-reports-body').html('<div class="alert alert-warning">無報工紀錄</div>');
                    return;
                }

                let html = '<table class="table table-bordered table-striped table-condensed" style="font-size:12px;"><thead><tr><th>日期</th><th>製程</th><th>人員</th><th>良品/NG</th><th>備註</th></tr></thead><tbody>';
                
                // Grouping Logic for Finished Processes
                const finishedFids = new Set();
                res.data.forEach(r => { if (r.is_finished == 1) finishedFids.add(r.bom_ing_fid); });
                
                const displayedFids = new Set();

                res.data.forEach(r => {
                    const fid = r.bom_ing_fid;
                    const isFinishedProcess = finishedFids.has(fid);
                    
                    // 如果是已完工製程，且尚未顯示過 (因為按日期降序，第一筆即為最新/完工那一筆)
                    if (isFinishedProcess) {
                        if (!displayedFids.has(fid)) {
                            // 顯示匯總行 (點擊查看詳情)
                            // 計算該製程的總良品與NG (簡單加總該FID的所有紀錄)
                            let totalOk = 0, totalNg = 0;
                            const processReports = res.data.filter(item => item.bom_ing_fid === fid);
                            processReports.forEach(pr => {
                                totalOk += parseFloat(pr.produced_qty) || 0;
                                totalNg += parseFloat(pr.ng_qty) || 0;
                            });

                            const processName = `${r.process_no} ${r.ProcessName}`;
                            const dateStr = r.report_date.substring(5, 10).replace('-', '/'); // MM/DD
                            
                            html += `<tr style="background-color:#dff0d8; cursor:pointer;" title="點擊查看詳細紀錄" onclick="showReportDetails('${fid}')">
                                <td>${dateStr} <span class="label label-success">完工</span></td>
                                <td>${escapeHtml(processName)}</td>
                                <td>(匯總)</td>
                                <td>${totalOk} / <span style="${totalNg>0?'color:red':''}">${totalNg}</span></td>
                                <td><i class="fa fa-plus-circle"></i> 點擊查看詳情</td>
                            </tr>`;
                            displayedFids.add(fid);
                        }
                        // 已顯示過則跳過
                    } else {
                        // 未完工製程，顯示每一筆
                        const processName = `${r.process_no} ${r.ProcessName}`;
                        const dateStr = r.report_date.substring(5, 16).replace('-', '/');
                        const operator = r.production_user_id ? r.operator : (r.setup_operator + '(架)');
                        const ok = parseFloat(r.produced_qty) || 0;
                        const ng = parseFloat(r.ng_qty) || 0;
                        
                        html += `<tr>
                            <td>${dateStr}</td>
                            <td>${escapeHtml(processName)}</td>
                            <td>${escapeHtml(operator)}</td>
                            <td>${ok} / <span style="${ng>0?'color:red':''}">${ng}</span></td>
                            <td>${escapeHtml(r.remark)}</td>
                        </tr>`;
                    }
                });
                html += '</tbody></table>';
                $('#all-reports-body').html(html);
            } else {
                $('#all-reports-body').html('<div class="alert alert-danger">載入失敗: ' + res.message + '</div>');
            }
        }, 'json');
    }

    // Reuse existing popover logic function for the modal detail click
    function showReportDetails(fid) {
        // Trigger the existing popover logic manually or create a temporary element to trigger it
        // Since the existing logic binds to .report-date-trigger, we can reuse the AJAX call directly.
        // But to show it in a "modal on top of modal" or expand, let's use a simple alert or replace content?
        // The request says "點擊後跳窗顯示詳細資料". Let's open a small nested modal or replace body.
        // Let's use a simple approach: Fetch details and show in a new modal #detailReportModal
        
        $.post(window.location.pathname.split('/').pop(), { action: 'get_report_details_for_popover', bom_ing_fids: fid }, function(res) {
            if (res.success && res.data.length > 0) {
                let html = '<table class="table table-bordered table-condensed"><thead><tr><th>時間</th><th>人員</th><th>良/NG</th><th>備註</th></tr></thead><tbody>';
                res.data.forEach(r => {
                    const time = r.production_start_time || r.setup_start_time || r.report_date;
                    const op = r.operator || r.setup_operator;
                    const ok = parseFloat(r.produced_qty) || 0;
                    const ng = parseFloat(r.ng_qty) || 0;
                    html += `<tr><td>${time}</td><td>${op}</td><td>${ok}/${ng}</td><td>${escapeHtml(r.remark)}</td></tr>`;
                });
                html += '</tbody></table>';
                
                // Create a temporary modal if not exists
                if ($('#detailReportModal').length === 0) {
                    $('body').append(`
                        <div class="modal fade" id="detailReportModal" tabindex="-1" role="dialog" style="z-index: 10060;">
                            <div class="modal-dialog" role="document">
                                <div class="modal-content">
                                    <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button><h4 class="modal-title">詳細報工紀錄</h4></div>
                                    <div class="modal-body" id="detail-report-body"></div>
                                </div>
                            </div>
                        </div>`);
                }
                $('#detail-report-body').html(html);
                $('#detailReportModal').modal('show');
            } else {
                alert('無詳細資料');
            }
        }, 'json');
    }
</script>

<body class="nav-sm">

    <div class="container body">
        <div class="main_container">

            <!-- side and top bar include -->
            <?php include '../partPage/sideAndTopBarMenu.html' ?>
            <!-- /side and top bar include -->

            <!-- page content -->
            <div class="right_col" role="main">
                <div class="">

                    <div class="page-title">
                        <div class="title_left">
                            <h3>BOM 總覽
                            <?php if (!empty($permission_display_text)): ?>
                                <small style="color: #73879C; font-size: 12px; margin-left: 10px; cursor: pointer;" 
                                       data-toggle="popover" 
                                       data-trigger="hover" 
                                       data-placement="bottom" 
                                       data-content="<?php echo htmlspecialchars($permission_tooltip_text); ?>">
                                    (權限：<?php echo htmlspecialchars($permission_display_text); ?>)
                                </small>
                            <?php endif; ?>
                            <?php if ($permission_code === 'A'): ?>
                                <button type="button" class="btn btn-xs btn-default" id="btn-oready-role-setting" style="margin-left:8px;" title="角色功能設定（僅管理員可用）" onclick="openOreadyRoleSettingModal()">
                                    <i class="fa fa-gear"></i> 角色功能設定
                                </button>
                            <?php endif; ?>
                            <span id="current-customer-display" style="font-size: 14px; color: #555;"></span>
                            <?php if (!empty($users_on_leave_names)): ?>
                                <small style="color: red; font-size: 12px; margin-left: 10px;">(今日休假: <?php echo htmlspecialchars(implode(', ', $users_on_leave_names)); ?>)</small>
                            <?php endif; ?>
                            </h3>
                        </div>
                    </div>

                    <?php if ($permission_code === 'A'): ?>
                    <!-- ══ 角色功能設定 Modal（僅管理員可見的按鈕才會觸發開啟） ══ -->
                    <div id="oreadyRoleSettingModal" class="modal fade" role="dialog" tabindex="-1">
                        <div class="modal-dialog" style="width:520px;">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                                    <h4 class="modal-title"><i class="fa fa-gear"></i> 角色功能設定</h4>
                                </div>
                                <div class="modal-body">
                                    <div class="form-group">
                                        <label>選擇角色</label>
                                        <div class="input-group">
                                            <select id="oready-role-select" class="form-control"></select>
                                            <span class="input-group-btn">
                                                <button type="button" class="btn btn-default" id="oready-role-refresh" title="重新整理角色清單"><i class="fa fa-refresh"></i></button>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <label>新增角色</label>
                                        <div class="input-group">
                                            <input type="text" id="oready-new-role-name" class="form-control" placeholder="輸入新角色名稱">
                                            <span class="input-group-btn">
                                                <button type="button" class="btn btn-success" id="oready-role-add"><i class="fa fa-plus"></i> 新增</button>
                                            </span>
                                        </div>
                                    </div>
                                    <hr>
                                    <label>此角色可使用的功能</label>
                                    <div id="oready-feature-box" style="max-height:260px;overflow-y:auto;"></div>
                                    <p style="font-size:11px;color:#888;margin-top:8px;">說明：這裡的功能碼與原本的 C/R/U/D/A 權限並存（任一成立即可使用該功能），不會取代原有權限設定。到「使用者權限管理」頁面可將角色指派給使用者。</p>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-default" data-dismiss="modal">關閉</button>
                                    <button type="button" class="btn btn-primary" id="oready-role-save">儲存功能設定</button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="title_left">
                        <h4>
                            <?php
                            if (!empty($_GET['message'])) {
                                if ($_GET['message'] == "success") {
                                    echo "<div class=\"alert alert-success fade in alert-dismissable\">
                                    <a href=\"#\" class=\"close\" data-dismiss=\"alert\" aria-label=\"close\" title=\"close\">×</a>
                                    更新成功
                                    </div>";
                                } else if ($_GET['message'] != "success") {
                                    $var = $_GET['message'];
                                    echo "<div class=\"alert alert-danger fade in alert-dismissable\">
                                    <a href=\"#\" class=\"close\" data-dismiss=\"alert\" aria-label=\"close\" title=\"close\">×</a>
                                    $var
                                    </div>";
                                }
                            }
                            ?>
                        </h4>
                        <!-- <h3>Event <small>Live</small></h3> -->
                    </div>
                    <div class="clearfix"></div>


                    <!-- 料號總覽 -->
                    <form method="POST" action="" onsubmit="return false;">

                        <div class="row">
                            <div class="col-md-12 col-sm-12 col-xs-12">
                                <div class="x_panel">
                                    <div class="x_title">
                                        <h2>
                                            <div class="title">
                                                <span id="pti-filter-buttons-container"></span>
                                                <a><input type="button" id="cancelBtn" class="btn btn-xs btn-warning" value="取消篩選" onclick="cancelFilters();"></a>
                                                
                                            </div>
                                            <!-- New button for searching completed BOMs -->
                                            <a><input type="button" id="btn-search-completed" class="btn btn-xs btn-info" value="查詢已完工" style="margin-left: 5px;"></a>
                                            <a><input type="button" id="btn-op-log" class="btn btn-xs btn-default" value="操作紀錄" style="margin-left: 5px;" title="查看BOM操作流水帳"></a>
                                            <?php if ($can_create): ?>
                                            <a><input type="button" id="btn-create-bom" class="btn btn-xs btn-success" value="新增BOM" style="margin-left: 5px;"></a>
                                            <?php endif; ?>
                                            <a><input type="button" id="btn-filter-unbound" class="btn btn-xs btn-warning" value="未綁定訂單" style="margin-left: 5px;" onclick="document.getElementById('status-filter').value='unbound_order'; processAndRenderData();"></a>
                                            <?php if ($can_delete): ?>
                                            <a><input type="button" id="btn-delete-bom" class="btn btn-xs btn-danger" value="刪除BOM" style="margin-left: 5px;" onclick="promptDeleteBom()"></a>
                                            <?php endif; ?>
                                            <a><input type="button" id="btn-pm-daily-report" class="btn btn-xs btn-primary" value="生管每日報表" style="margin-left: 70px;" title="匯出Excel：QC待驗逾2天者一分頁，其餘未回廠(ing)依廠商分頁" onclick="window.location.href='pm_daily_report_export.php'"></a>
                                            <a><input type="button" id="btn-capacity-gantt" class="btn btn-xs btn-primary" value="外包產能" style="margin-left: 5px; background:#5a3d8a; border-color:#4a3072;" title="外包產能甘特圖：依廠商/製程看自訂期間內的移轉→回廠重疊(產能排擠)狀態" onclick="openCapacityGantt()"></a>
                                        </h2>
                                        <ul class="nav navbar-right panel_toolbox">
                                            <li><a class="collapse-link"><i class="fa fa-chevron-up"></i></a>
                                            </li>
                                            <li><a class="close-link"><i class="fa fa-close"></i></a>
                                            </li>
                                        </ul>

                                        <div class="clearfix"></div>

                                        <div class="all-filters">

                                            <!-- 客戶 -->
                                            <input type="text" id="customer-filter" list="customerList" placeholder="全部客戶">
                                            <button type="button" id="btn-prev-customer" title="上一個客戶" style="padding: 0 6px; height: 26px; font-size: 10px;">&lt;</button>
                                            <button type="button" id="btn-next-customer" title="下一個客戶" style="padding: 0 6px; height: 26px; font-size: 10px;">&gt;</button>
                                            <datalist id="customerList"></datalist>
                                            <!-- 業務篩選 -->
                                            <input type="text" id="sales-filter" list="salesList" placeholder="負責業務">
                                            <datalist id="salesList"></datalist>
                                            <!-- 新增：交期搜索格 -->
                                            <input type="text" id="delivery-date-filter" placeholder="搜索 交期 (例：2/8、>2/8)">
                                            <!-- 狀態燈號 -->
                                            <button type="button" id="bomColorFilter" onclick="toggleBomColorFilter()">
                                                <!-- 這裡根據狀態來顯示相應內容，初始狀態為 All -->
                                                <span id="bomColorContent" style="font-size:8px; display:inline-block;">All</span>
                                                <div class="tooltip">
                                                    <div class="tooltip-content">
                                                        <div class="control-label">
                                                            <div>
                                                                <figure class="circle_green"></figure>
                                                                <span>一般件</span>
                                                            </div>
                                                        </div>
                                                        <div class="control-label">
                                                            <div>
                                                                <figure class="circle_y"></figure>
                                                                <span>急件</span>
                                                            </div>
                                                        </div>
                                                        <div class="control-label">
                                                            <div>
                                                                <figure class="circle_red"></figure>
                                                                <span>特急件</span>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </button>
                                            <input type="text" id="bom-filter" placeholder="搜索 BOM / 料號">
                                            <input type="text" id="vendor-filter" list="vendorList" placeholder="全部廠商">
                                            <button type="button" id="btn-prev-vendor" title="上一個廠商" style="padding: 0 6px; height: 26px; font-size: 10px;">&lt;</button>
                                            <button type="button" id="btn-next-vendor" title="下一個廠商" style="padding: 0 6px; height: 26px; font-size: 10px;">&gt;</button>
                                            <datalist id="vendorList"></datalist>
                                            <input type="text" id="order-filter" placeholder="搜索 發單 (例：50、>50、<50)">
                                            <input type="text" id="date-filter"
                                                placeholder="搜索 報工日 (例：2/8、>2/8、<2024/12/30)">
                                            <select id="status-filter" class="form-control input-sm" style="display:inline-block; width:auto;">
                                                <option value="">--全部狀態--</option>
                                                <option value="---sep1---" disabled>&nbsp;</option> <!-- Separator -->
                                                <option value="ing">未回廠</option>
                                                <option value="Q">QC檢驗中</option>
                                                <option value="P">檢驗完成待移轉</option>
                                                <option value="E">上華已移轉</option> <!-- 修改文字使其更簡潔 -->
                                                <option value="skip">跳過</option>
                                                <option value="has_bom_ing_ps">有備註</option> <!-- 新增：有製程備註 -->
                                                <option value="has_report_data">有報工資料</option> <!-- 新增：有報工資料 -->
                                                <option value="has_new_process_report">有新製程報工</option>
                                                <option value="qc_check_any">QC檢驗</option>
                                                <option value="qc_date_pick">指定日期QC檢驗…</option>
                                                <option value="---sep_process_remark---" disabled>&nbsp;</option> <!-- 新增分隔線 -->
                                                <option value="is_stock">備庫</option>
                                                <option value="no_order_data">無訂單</option>
                                                <option value="unselected_order_in_dropdown">無交期(未選訂單)</option>
                                                <option value="unbound_order">未綁定訂單</option>
                                                <option value="---sep2---" disabled>&nbsp;</option> <!-- Separator -->
                                                <option value="no_client_name">無客戶名稱</option>
                                                <option value="no_vendor">無廠商</option>
                                            </select>
                                            <input type="date" id="qc-date-filter" style="display:none;margin-left:4px;vertical-align:middle;" title="指定QC檢驗日期">
                                            <input type="text" id="global-search" placeholder="搜索所有欄位">

                                        </div>
                                    </div>

                                    <!-- 在表格上方添加分頁控制項 -->
                                    <div class="pagination-controls">
                                        <div class="pagination-left-group" style="display: flex; align-items: center; gap: 15px;">
                                            <div class="export-buttons" style="margin-right: 15px; display: inline-block; vertical-align: middle;">
                                                <button id="btn-export-csv" class="btn btn-info btn-sm" title="將目前篩選結果匯出為CSV">轉 CSV</button>
                                                <button id="btn-export-jpg" class="btn btn-info btn-sm" title="將目前表格畫面匯出為JPG">轉 JPG</button>
                                                <button id="btn-vendor-notify-img" class="btn btn-sm" style="background:#F0A24B; border:1px solid #d9861f; color:#fff; font-weight:bold;" title="依目前篩選＋排序結果，產生只含 BOM／料號／發單日 三欄的圖片並自動複製到剪貼簿（不含任何單價資訊）">通知廠商圖</button>
                                            </div>
                                            <!-- 清單排序（套用於「目前篩選後」的全部資料，非只有本頁） -->
                                            <div class="list-sort-controls" title="排序會套用在目前所有篩選條件之後的完整結果上（不是只排本頁）">
                                                <label for="list-sort-field">排序</label>
                                                <select id="list-sort-field">
                                                    <option value="">原始順序</option>
                                                    <option value="outsource_date">發單日</option>
                                                    <option value="delivery_date">交期</option>
                                                    <option value="bom">BOM</option>
                                                    <option value="d_id">料號</option>
                                                    <option value="customer">客戶</option>
                                                </select>
                                                <button type="button" id="btn-list-sort-dir" class="list-sort-btn" title="切換遞增／遞減">▲ 遞增</button>
                                                <button type="button" id="btn-list-sort-clear" class="list-sort-btn" title="清除排序，回到原始順序">取消排序</button>
                                            </div>
                                            <div class="pagination-info" id="pagination-info">
                                                顯示 0 筆中的 0 筆，第 0/0 頁
                                            </div>
                                            <button type="button" class="btn btn-xs btn-primary" title="移動表格至最左側" onclick="scrollToBeginning()">移至最左</button>
                                            <button type="button" class="btn btn-xs btn-primary" title="移動表格以顯示製程" onclick="scrollToProcesses()">顯示製程</button>
                                            <?php if (($display_permission_code !== 'C+R' && $display_permission_code !== 'D+R' && $display_permission_code !== 'R') || rf_has_feature($_oready_features, 'oready_update')): ?>
                                                <button type="button" class="btn btn-xs btn-warning" title="設定燈號與製程天數" onclick="openLightSettingModal()" style="margin-left: 5px;">設定燈號</button>
                                            <?php endif; ?>
                                            <!-- Modified "日內未回" filter section -->
                                            <div style="display: inline-flex; align-items: center; margin-left: 15px; ">
                                                <span id="elapsed-days-filter-status-text" style="font-weight: bold; margin-right: 5px; display: none;"></span>
                                                <button type="button" id="toggle-elapsed-days-filter-btn" class="btn btn-xs btn-danger">篩選發單未回</button>
                                                <div id="elapsed-days-input-container" style="display: none; margin-left: 5px; align-items: center;">
                                                    <input type="text" id="elapsed-days-filter-input" inputmode="numeric" maxlength="2" style="width: 6ch; height: 20px; font-size: 12px; line-height: 1.5; text-align: center; padding: 1px 5px; border: 1px solid #ccc; border-radius: 3px; box-sizing: border-box;" placeholder="天">
                                                    <button type="button" id="confirm-elapsed-days-filter-btn" class="btn btn-xs btn-primary" style="margin-left: 2px;">確認</button>
                                                </div>
                                                <!-- New "製程未過半" Filter Button -->
                                                <button type="button" id="toggle-process-not-halfway-filter-btn" class="btn btn-xs btn-info" style="margin-left: 10px; display: none;">篩選製程未過半
                                                    <span class="tooltip-text"><b>規則一：</b>製程未過熱處理(含正在熱處理)或已過熱處理但加工日超過總加工日比例<br><b>規則二：</b>無熱處理者，使用「已經過的工作天數」與「總製程數」做比例換算，計算日期過半但製程未過半者。</span>
                                                </button>
                                                <!-- New "QC報工排序" Sort Button -->
                                                <button type="button" id="toggle-qc-report-sort-btn" class="btn btn-xs btn-primary" style="margin-left: 10px; display: none;">QC報工排序</button>
                                                <!-- 設定業務按鈕 -->
                                                <?php if (($display_permission_code !== 'C+R' && $display_permission_code !== 'R') || rf_has_feature($_oready_features, 'oready_update')): ?>
                                                    <button type="button" id="btn-sales-setting" class="btn btn-xs btn-primary" style="margin-left: 10px;">設定業務</button>
                                                <?php endif; ?>
                                                <!-- 製程設定按鈕：舊規則(A或C+R+D) OR 新功能碼 oready_process_settings -->
                                                <?php if ($permission_code === 'A' || $display_permission_code === 'C+R+D' || $oready_feat_process_settings): ?>
                                                    <button type="button" id="btn-pti-filter-setting" class="btn btn-xs btn-warning" style="margin-left: 6px;" title="設定PTI製程篩選按鈕">製程設定</button>
                                                <?php endif; ?>
                                                <!-- 例外內製製程設定：舊規則(僅A) OR 新功能碼 oready_process_settings -->
                                                <?php if ($permission_code === 'A' || $oready_feat_process_settings): ?>
                                                    <button type="button" id="btn-internal-proc-setting" class="btn btn-xs btn-info" style="margin-left: 4px;" title="設定哪些製程類型視為廠內加工（例外設定）">內製製程</button>
                                                <?php endif; ?>
                                                <!-- <span id="process-not-halfway-filter-status-text" style="font-weight: bold; margin-left: 5px; display: none;"></span> -->
                                                <small id="set-workday-btn" class="btn btn-xs btn-warning btn-return-style" style="margin-left: 10px; cursor: pointer; display: none;">設定工作日</small>
                                            </div>

                                        </div>
                                        <div class="pagination-buttons">
                                            <button id="btn-first" title="第一頁">
                                                <<</button>
                                                    <button id="btn-prev" title="上一頁">
                                                        <</button>
                                                            <select id="page-selector" class="page-selector"></select>
                                                            <button id="btn-next" title="下一頁">></button>
                                                            <button id="btn-last" title="最後一頁">>></button>
                                                            <span class="records-per-page">
                                                                每頁顯示
                                                                <select id="records-per-page">
                                                                    <option value="5">5</option>
                                                                    <option value="7" selected>7</option>
                                                                    <option value="10">10</option>
                                                                    <option value="20">20</option>
                                                                    <option value="50">50</option>
                                                                    <option value="100">100</option>
                                                                </select>
                                                                筆
                                                            </span>
                                        </div>
                                    </div>

                                    <!-- 呈現料號資料   -->
                                    <div class="table-wrapper  table-fixed-left">

                                        <table id="table-DOWN" class="table table-striped" border="1" cellspacing="0" cellpadding="5">
                                            <thead>
                                                <tr>
                                                    <th style="width:5px;">客戶 <i class="fa fa-bullseye" title="雙擊儲存格內容 可快速篩選/取消篩選此客戶" style="margin-left: 3px; font-size: 0.9em; color: #777;"></i><span class="th-sort-btn" data-sort-field="customer" title="點擊依客戶排序（再點一次切換遞增／遞減）">⇅</span><small class="text-muted" style="display:block; margin-top:4px; font-size: 0.7em;">僅顯示三筆出貨</small></th>
                                                    <th style="width:20px;">交期x數量<small>(未交)</small><span class="th-sort-btn" data-sort-field="delivery_date" title="點擊依交期排序（再點一次切換遞增／遞減）">⇅</span></th>
                                                    <th style="width:20px;">BOM <i class="fa fa-bullseye" title="雙擊儲存格內容 可快速篩選/取消篩選此BOM" style="margin-left: 3px; font-size: 0.9em; color: #777;"></i><span class="th-sort-btn" data-sort-field="bom" title="點擊依 BOM 排序（再點一次切換遞增／遞減）">⇅</span>
                                                        <div style="font-weight:normal; font-size:10px; margin-top:5px; line-height:1.2; text-align:left;">
                                                            <div style="display:flex; align-items:center;">
                                                                <figure class="circle_y" style="width:10px; height:10px; margin:0 3px 0 0;"></figure> 進度 &lt; <?= htmlspecialchars($light_settings_php['yellow']) ?>%
                                                            </div>
                                                            <div style="display:flex; align-items:center;">
                                                                <figure class="circle_red" style="width:10px; height:10px; margin:0 3px 0 0;"></figure> 進度 &lt; <?= htmlspecialchars($light_settings_php['red']) ?>%
                                                            </div>
                                                            <div>交期緊迫天數 &lt; <?= htmlspecialchars($light_settings_php['red_days_before']) ?>天</div>
                                                        </div>
                                                    </th>
                                                    <th style="width:30px;">料號 <i class="fa fa-bullseye" title="雙擊儲存格內容 可快速篩選/取消篩選此料號" style="margin-left: 3px; font-size: 0.9em; color: #777;"></i><span class="th-sort-btn" data-sort-field="d_id" title="點擊依料號排序（再點一次切換遞增／遞減）">⇅</span></th>
                                                    <th style="min-width:80px;">發單日 <i class="fa fa-bullseye" title="雙擊儲存格內容 可快速篩選/取消篩選此廠商" style="margin-left: 3px; font-size: 0.9em; color: #777;"></i><span class="th-sort-btn" data-sort-field="outsource_date" title="點擊依發單日排序（再點一次切換遞增／遞減）">⇅</span><br><small style="font-size: 0.7em;">(總數/製程/廠商/狀態)</small></th>
                                                    <th style="width:10px;">製程</th>
                                                    <th style="width:20px;">廠商 <small>(狀態)</small> <i class="fa fa-bullseye" title="雙擊儲存格內容 可快速篩選/取消篩選此廠商" style="margin-left: 3px; font-size: 0.9em; color: #777;"></i></th>

                                                    <th style="width:5px;">

                                                    </th>
                                                    <th style="min-width:150px;">BOM/製程備註<small>(游標在此欄時表單不更新)</small></th>
                                                    <th style="width:250px;">報工狀況與備註</th>
                                                    <th hidden>pti</th>
                                                    <th hidden>狀態</th>
                                                    <!-- 製程標題欄 -->
                                                    <?php for ($i = 1; $i <= $bom_ps_list_max; $i++): ?>
                                                        <th style="width:5px;"> <?= $i ?></th>
                                                    <?php endfor; ?>

                                                </tr>
                                            </thead>
                                            <tbody>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- 線圖 -->

                <script src="../../code/highcharts.js"></script>
                <script src="../../code/modules/exporting.js"></script>
                <script src="../../code/modules/export-data.js"></script>
                <script src="../../code/modules/accessibility.js"></script>
                <!-- /page content -->

            </div>
            <!-- footer content include -->
            <?php include '../partPage/footer.html' ?>
            <!-- /footer content include -->
        </div>
    </div>
    <!-- Container for Modals (to be populated by JavaScript) -->
    <div id="modals-container"></div>
    
    <!-- All Reports Modal -->
    <div class="modal fade" id="allReportsModal" tabindex="-1" role="dialog" style="z-index: 10055;">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal">&times;</button>
                    <h4 class="modal-title">BOM 報工紀錄: <span id="modal-all-reports-title"></span></h4>
                </div>
                <div class="modal-body" id="all-reports-body" style="max-height: 70vh; overflow-y: auto;"></div>
            </div>
        </div>
    </div>

    <!-- Drawing Choice Modal -->
    <div class="modal fade" id="drawingChoiceModal" tabindex="-1" role="dialog" aria-labelledby="drawingChoiceModalLabel" style="z-index: 10060;" data-backdrop="false">
        <div class="modal-dialog modal-lg" style="width: 90%;">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                    <div style="display: flex; align-items: center;">
                        <h4 class="modal-title" id="drawingChoiceModalLabel" style="margin-right: 15px;">BOM 圖檔: <span id="modal-bom-title"></span></h4>
                        <button type="button" class="btn btn-default btn-sm" onclick="printCurrentFile()"><i class="fa fa-print"></i> 列印</button>
                        <button type="button" class="btn btn-info btn-sm" onclick="openFileTagsSetting()" style="margin-left: 5px;"><i class="fa fa-tags"></i> 設定標籤</button>
                        <button type="button" class="btn btn-primary btn-sm" onclick="openImageEditor()" style="margin-left: 5px;"><i class="fa fa-pencil"></i> 線上標記</button>
                    </div>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-3"><div class="list-group" id="bom-file-list"></div></div>
                        <div class="col-md-9" id="bom-file-viewer" style="min-height: 500px; text-align: center; background: #eee;"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 簡易繪圖 Modal -->
    <div class="modal fade" id="imageEditModal" tabindex="-1" role="dialog" data-backdrop="static" data-keyboard="false">
        <div class="modal-dialog modal-lg" style="width: 90%; height: 90%; margin: 30px auto;">
            <div class="modal-content" style="height: 100%; display: flex; flex-direction: column;">
                <div class="modal-header" style="flex: 0 0 auto;">
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                    <h4 class="modal-title">圖檔標記</h4>
                </div>
                
                <div class="modal-body" style="flex: 1 1 auto; padding: 0; overflow: hidden; position: relative;">
                    <div id="canvas-container">
                        <canvas id="paint-canvas"></canvas>
                        <div id="selection-box"></div>
                    </div>
                </div>
                
                <div class="modal-footer editor-toolbar" style="flex: 0 0 auto; text-align: left;">
                    <div class="row">
                        <div class="col-md-12 form-inline">
                            <!-- Tools -->
                            <div class="btn-group" role="group" style="margin-right: 10px;">
                                <button type="button" class="btn btn-default tool-btn active" data-tool="pen" title="畫筆"><i class="fa fa-pencil"></i></button>
                                <button type="button" class="btn btn-default tool-btn" data-tool="rect" title="方框"><i class="fa fa-square-o"></i></button>
                                <button type="button" class="btn btn-default tool-btn" data-tool="circle" title="圓圈"><i class="fa fa-circle-o"></i></button>
                                <button type="button" class="btn btn-default tool-btn" data-tool="eraser_rect" title="選取刪除 (框選清除)"><i class="fa fa-eraser"></i></button>
                                <button type="button" class="btn btn-default tool-btn" data-tool="pan" title="拖移 (按住左鍵拖動)"><i class="fa fa-arrows"></i></button>
                            </div>

                            <!-- Properties -->
                            <label>顏色:</label> 
                            <input type="color" id="pen-color" value="#ff0000" class="form-control input-sm" style="width: 40px; padding: 2px; height: 30px;">
                            
                            <label style="margin-left: 5px;">粗細:</label> 
                            <input type="number" id="pen-width" min="1" max="50" value="3" class="form-control input-sm" style="width: 60px;">

                            <!-- Zoom -->
                            <div class="btn-group" role="group" style="margin-left: 10px; margin-right: 10px;">
                                <button type="button" class="btn btn-default" id="btn-zoom-out" title="縮小"><i class="fa fa-minus"></i></button>
                                <span class="btn btn-default disabled" id="zoom-level" style="width: 60px;">100%</span>
                                <button type="button" class="btn btn-default" id="btn-zoom-in" title="放大"><i class="fa fa-plus"></i></button>
                            </div>

                            <!-- Actions -->
                            <button class="btn btn-warning btn-sm" id="btn-undo-canvas" title="復原"><i class="fa fa-undo"></i></button>
                            <button class="btn btn-danger btn-sm" id="btn-clear-canvas" title="全部清除"><i class="fa fa-trash"></i></button>
                            
                            <div class="pull-right">
                                <button class="btn btn-info" id="btn-print-canvas-modal"><i class="fa fa-print"></i> 列印標記</button>
                                <button type="button" class="btn btn-default" data-dismiss="modal">關閉</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- File Tags Setting Modal -->
    <div class="modal fade" id="fileTagsSettingModal" tabindex="-1" role="dialog" style="z-index: 10070;">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                    <h4 class="modal-title">設定 ERP/資材報告 檔名標籤</h4>
                </div>
                <div class="modal-body">
                    <table class="table table-bordered table-condensed" id="tagsSettingTable">
                        <thead>
                            <tr>
                                <th>檔名後綴 (例: -T)</th>
                                <th>標籤名稱 (例: 齒研報告)</th>
                                <th>顏色</th>
                                <th width="50">操作</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                    <button type="button" class="btn btn-success btn-sm" onclick="addTagRow()"><i class="fa fa-plus"></i> 新增規則</button>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
                    <button type="button" class="btn btn-primary" onclick="saveFileTagsSetting()">儲存設定</button>
                </div>
            </div>
        </div>
    </div>

    <!-- jQuery -->
    <script src="../../resource/js/jquery.min.js?v=<?=$_av?>"></script>
    <!-- Bootstrap -->
    <script src="../../resource/js/bootstrap.min.js?v=<?=$_av?>"></script>
    <!-- FastClick -->
    <script src="../../resource/js/fastclick.js?v=<?=$_av?>"></script>
    <!-- NProgress -->
    <script src="../../resource/js/nprogress.js?v=<?=$_av?>"></script>
    <!-- iCheck -->
    <script src="../../resource/js/icheck.min.js?v=<?=$_av?>"></script>
    <!-- Datatables -->
    <script src="../../resource/js/jquery.dataTables.min.js?v=<?=$_av?>"></script>
    <script src="../../resource/js/dataTables.bootstrap.min.js?v=<?=$_av?>"></script>
    <script src="../../resource/js/dataTables.buttons.min.js?v=<?=$_av?>"></script>
    <script src="../../resource/js/buttons.bootstrap.min.js?v=<?=$_av?>"></script>
    <script src="../../resource/js/buttons.flash.min.js?v=<?=$_av?>"></script>
    <script src="../../resource/js/buttons.html5.min.js?v=<?=$_av?>"></script>
    <script src="../../resource/js/buttons.print.min.js?v=<?=$_av?>"></script>
    <script src="../../resource/js/dataTables.fixedHeader.min.js?v=<?=$_av?>"></script>
    <script src="../../resource/js/dataTables.keyTable.min.js?v=<?=$_av?>"></script>
    <script src="../../resource/js/dataTables.responsive.min.js?v=<?=$_av?>"></script>
    <script src="../../resource/js/responsive.bootstrap.js?v=<?=$_av?>"></script>
    <script src="../../resource/js/dataTables.scroller.min.js?v=<?=$_av?>"></script>
    <script src="../../resource/js/jszip.min.js?v=<?=$_av?>"></script>
    <script src="../../resource/js/pdfmake.min.js?v=<?=$_av?>"></script>
    <script src="../../resource/js/vfs_fonts.js?v=<?=$_av?>"></script>
    <!-- Custom Theme Scripts -->
    <script src="../../resource/js/custom.min.js?v=<?=$_av?>"></script>


    <!-- jQuery UI JS (用於 Datepicker, 確保在 jQuery 之後載入) -->
    <script src="https://code.jquery.com/ui/1.12.1/jquery-ui.js"></script>

    <script>
        <?php if ($permission_code === 'A'): ?>
        // ══ 角色功能設定（僅權限=A者可見的按鈕會呼叫）══
        var OREADY_FEATURES = [
            {code:'oready_create',            label:'新增 BOM'},
            {code:'oready_update',             label:'修改（備註 / 更新表單 / 快速綁定料號 / 設定燈號 / 設定業務）'},
            {code:'oready_delete',             label:'刪除 BOM'},
            {code:'oready_manual_close',       label:'人工結案'},
            {code:'oready_mark_returned',      label:'回廠標記'},
            {code:'oready_transfer',           label:'移轉 / 取消移轉製程'},
            // 2026-07-16 拆批/合併功能停用（操作按鈕已註解），角色設定清單一併隱藏此功能碼；恢復時解除註解
            // {code:'oready_batch_split_merge',  label:'拆批 / 合併'},
            {code:'oready_view_price',         label:'查看加工單價'},
            {code:'oready_process_settings',   label:'製程設定 / 內製製程例外設定'}
        ];
        var OREADY_CODES = OREADY_FEATURES.map(function(f){ return f.code; });
        var _oreadyRoleCur = [];
        var OREADY_ROLES_API = '../../src/store/Roles_API.php';

        function openOreadyRoleSettingModal() {
            if (!window.oreadyIsAdmin) { alert('僅管理員可使用此功能'); return; }
            oreadyLoadRoles();
            $('#oreadyRoleSettingModal').modal('show');
        }

        function oreadyLoadRoles() {
            $.get(OREADY_ROLES_API, {action:'get_roles', module:'oready'}, function(res) {
                if (!res || !res.success) { alert('讀取角色失敗：' + ((res && res.message) || '未知錯誤')); return; }
                var $sel = $('#oready-role-select').empty();
                (res.data || []).forEach(function(r) {
                    $sel.append($('<option>').val(r.role_id).text(r.role_name + (r.is_system == 1 ? '（系統角色）' : '')));
                });
                oreadyRenderFeatureBox();
            }, 'json');
        }

        function oreadyRenderFeatureBox() {
            var roleId = $('#oready-role-select').val();
            var $box = $('#oready-feature-box').empty();
            if (!roleId) { $box.html('<div class="text-muted">尚無角色，請先於上方新增</div>'); return; }
            $.get(OREADY_ROLES_API, {action:'get_role_features', role_id: roleId}, function(res) {
                var cur = (res && res.success && res.data) || [];
                _oreadyRoleCur = cur;
                var isAll = cur.indexOf('all') !== -1;
                var html = '';
                OREADY_FEATURES.forEach(function(f) {
                    var checked = isAll || cur.indexOf(f.code) !== -1;
                    html += '<div class="checkbox"><label>' +
                        '<input type="checkbox" class="oready-feat" value="' + f.code + '" ' +
                        (checked ? 'checked ' : '') + (isAll ? 'disabled' : '') + '> ' + f.label + '</label></div>';
                });
                $box.html(html);
            }, 'json');
        }

        $(document).on('change', '#oready-role-select', oreadyRenderFeatureBox);
        $(document).on('click', '#oready-role-refresh', oreadyLoadRoles);

        $(document).on('click', '#oready-role-add', function() {
            var name = $('#oready-new-role-name').val().trim();
            if (!name) { alert('請輸入角色名稱'); return; }
            $.post(OREADY_ROLES_API, {action:'save_role', role_name:name, module:'oready'}, function(res) {
                if (!res || !res.success) { alert('新增失敗：' + ((res && res.message) || '未知錯誤')); return; }
                $('#oready-new-role-name').val('');
                oreadyLoadRoles();
            }, 'json');
        });

        $(document).on('click', '#oready-role-save', function() {
            var roleId = $('#oready-role-select').val();
            if (!roleId) { alert('請先選擇角色'); return; }
            var checked = $('#oready-feature-box .oready-feat:checked').map(function(){ return this.value; }).get();
            // 只替換 OREADY_CODES 範圍內的碼，避免洗掉其他模組（如 QC、報價單）的 feature_code
            var merged = _oreadyRoleCur.filter(function(c){ return OREADY_CODES.indexOf(c) === -1; }).concat(checked);
            $.post(OREADY_ROLES_API, {action:'save_role_features', role_id: roleId, features: JSON.stringify(merged)}, function(res) {
                if (!res || !res.success) { alert('儲存失敗：' + ((res && res.message) || '未知錯誤')); return; }
                showTemporaryMessage('角色功能已儲存', true);
                oreadyRenderFeatureBox();
            }, 'json');
        });
        <?php endif; ?>

        // Helper to get contrasting text color (black/white) for a given hex background color
        function getTextColor(hexcolor){
            if (!hexcolor) return '#000000';
            hexcolor = hexcolor.replace("#", "");
            var r = parseInt(hexcolor.substr(0,2),16);
            var g = parseInt(hexcolor.substr(2,2),16);
            var b = parseInt(hexcolor.substr(4,2),16);
            var yiq = ((r*299)+(g*587)+(b*114))/1000;
            return (yiq >= 128) ? '#000000' : '#ffffff';
        }

        // --- 新增料號視窗 (openNewPartModal) ---
        function openNewPartModal(d_id, client_name, targetCustomerId) {
            // 建立 overlay
            var existingModal = document.getElementById('new-part-modal-overlay');
            if (existingModal) existingModal.remove();

            var overlay = document.createElement('div');
            overlay.id = 'new-part-modal-overlay';
            overlay.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);display:flex;justify-content:center;align-items:flex-start;z-index:10080;padding-top:40px;overflow-y:auto;';

            var box = document.createElement('div');
            box.style.cssText = 'background:#fff;border-radius:6px;box-shadow:0 4px 20px rgba(0,0,0,0.3);width:96%;max-width:900px;margin:0 auto 40px auto;overflow:hidden;';

            var header = document.createElement('div');
            header.style.cssText = 'display:flex;justify-content:space-between;align-items:center;padding:12px 16px;border-bottom:1px solid #ddd;background:#f5f5f5;';
            header.innerHTML = '<strong>新增料號設定' + (d_id ? ' - ' + escapeHtml(d_id) : '') + '</strong>';
            var closeBtn = document.createElement('button');
            closeBtn.type = 'button';
            closeBtn.className = 'close';
            closeBtn.innerHTML = '&times;';
            closeBtn.style.cssText = 'font-size:1.5rem;background:none;border:none;cursor:pointer;line-height:1;';
            closeBtn.onclick = function() { overlay.remove(); };
            header.appendChild(closeBtn);
            box.appendChild(header);

            var body = document.createElement('div');
            body.style.cssText = 'padding:30px 20px 20px 20px;min-height:400px;';
            body.innerHTML = '<p style="padding:20px;color:#999;text-align:center;"><i class="fa fa-spinner fa-spin"></i> 載入中...</p>';
            box.appendChild(body);

            overlay.appendChild(box);
            document.body.appendChild(overlay);

            // 點擊背景關閉
            overlay.addEventListener('click', function(e) { if (e.target === overlay) overlay.remove(); });

            // 載入 modal_part_setting.php，傳入料號與客戶
            var params = [];
            if (d_id) params.push('d_id=' + encodeURIComponent(d_id));
            if (client_name) params.push('client_name=' + encodeURIComponent(client_name));
            var url = '../../views/popup/modal_part_setting.php' + (params.length ? '?' + params.join('&') : '');
            $.ajax({
                url: url,
                type: 'GET',
                success: function(html) {
                    body.innerHTML = html;
                    // 執行載入的 script 標籤
                    $(body).find('script').each(function() {
                        try { eval($(this).text()); } catch(e) { console.warn('modal_part_setting script error:', e); }
                    });
                    // 客戶欄位自動搜尋（覆蓋原本的 modal_part_setting 客戶搜尋邏輯）
                    (function setupCustomerSearch(){
                        var csEl = body.querySelector('#modal-client-search');
                        var ciEl = body.querySelector('#modal-customer-id');
                        var resDrop = body.querySelector('#customer-search-results');
                        if (!csEl) return;

                        // --- 修正：修正按鈕與搜索圖示重疊問題，改進 Flex 佈局 ---
                        var parent = csEl.parentElement;
                        if (parent && !body.querySelector('#btn-new-cust-wrapper')) {
                            var wrapper = document.createElement('div');
                            wrapper.id = 'btn-new-cust-wrapper';
                            wrapper.style.display = 'flex';
                            wrapper.style.gap = '5px';
                            wrapper.style.width = '100%';
                            wrapper.style.position = 'relative'; // 保持相對定位以容納內部絕對定位元素

                            // 將 parent 內所有現有元素 (包含 input 和可能的放大鏡 i 標籤) 移入 wrapper
                            while (parent.firstChild) {
                                wrapper.appendChild(parent.firstChild);
                            }
                            parent.appendChild(wrapper);

                            var newBtn = document.createElement('button');
                            newBtn.type = 'button';
                            newBtn.id = 'btn-new-cust-in-part';
                            newBtn.className = 'btn btn-xs btn-success';
                            newBtn.innerHTML = '<i class="fa fa-plus"></i> 新增';
                            newBtn.title = '新建客戶資料';
                            newBtn.style.flexShrink = '0';
                            newBtn.onclick = function() {
                                openAddCustomerModal(function(newCust) {
                                    csEl.value = newCust.name;
                                    if (ciEl) ciEl.value = newCust.id;
                                });
                            };
                            wrapper.appendChild(newBtn);
                        }

                        var _ct = null;
                        csEl.addEventListener('input', function(){
                            clearTimeout(_ct);
                            var kw = csEl.value.trim();
                            if (!kw) { if(resDrop) resDrop.style.display='none'; return; }
                            _ct = setTimeout(function(){
                                $.post('', { action: 'search_customer_for_part', term: kw }, function(resp){
                                    if (!resp || !resp.success || !resp.customers || !resp.customers.length) {
                                        if(resDrop) resDrop.style.display='none'; return;
                                    }
                                    if (resDrop) {
                                        resDrop.innerHTML = '';
                                        resp.customers.forEach(function(cu){
                                            var opt = document.createElement('div');
                                            opt.className = 'cust-opt-item';
                                            opt.style.cssText = 'padding:5px 10px;cursor:pointer;border-bottom:1px solid #f0f0f0;font-size:12px;';
                                            // 顯示 ID + 名稱，方便識別
                                            opt.innerHTML = '<strong style="color:#337ab7;">'+cu.customer_id+'</strong> - ' + (cu.customer || '');
                                            opt.onmousedown = function(e){ e.preventDefault(); };
                                            opt.onclick = function(){
                                                csEl.value = cu.customer || cu.customer_id;
                                                if (ciEl) ciEl.value = cu.customer_id;
                                                resDrop.style.display = 'none';
                                            };
                                            resDrop.appendChild(opt);
                                        });
                                        resDrop.style.display = 'block';
                                    }
                                }, 'json');
                            }, 0);
                        });
                        csEl.addEventListener('blur', function(){
                            setTimeout(function(){ if(resDrop) resDrop.style.display='none'; }, 200);
                        });
                    })();
                    // 自動填入料號與客戶
                    setTimeout(function(){
                        if (d_id) {
                            var pnEl = body.querySelector('#modal-part-no');
                            if (pnEl && !pnEl.value) pnEl.value = d_id;
                        }
                        if (client_name || targetCustomerId) {
                            var csEl = body.querySelector('#modal-client-search');
                            var ciEl = body.querySelector('#modal-customer-id');
                            if (csEl && !csEl.value) {
                                // 優先使用傳入的 ID，若無則用名稱搜尋
                                var searchTerm = targetCustomerId || client_name;
                                $.post('', { action: 'search_customer_for_part', term: searchTerm }, function(resp) {
                                    if (!resp || !resp.success || !resp.customers || !resp.customers.length) {
                                        csEl.value = client_name; return;
                                    }
                                    var best;
                                    if (targetCustomerId) {
                                        best = resp.customers.find(function(c){ return String(c.customer_id) === String(targetCustomerId); });
                                    } else {
                                        best = resp.customers.find(function(c){ return c.customer === client_name; });
                                    }
                                    best = best || resp.customers[0];
                                    csEl.value = best.customer || client_name;
                                    if (ciEl) ciEl.value = best.customer_id || '';
                                }, 'json');
                            }
                        }
                    }, 100);
                    // 攔截 modal_part_setting.php 的 AJAX（NewOrder_Track.php → 本頁 action）
                    (function(){
                        var _ph = window.location.pathname;
                        var _origPost = $.post.bind($);
                        $.post = function(url, data, cb, type) {
                            if (url && String(url).indexOf('NewOrder_Track') !== -1) {
                                // 轉換 action
                                if (data && data.action === 'search_customers') {
                                    data.action = 'search_customer_for_part';
                                    data.term = data.keyword || '';
                                    delete data.keyword;
                                    var _cb = cb;
                                    cb = function(resp) {
                                        // 轉換 response 格式：{customer_id,customer} → {id,name}
                                        if (resp && resp.customers) {
                                            resp.customers = resp.customers.map(function(c){
                                                return { id: c.customer_id, name: c.customer };
                                            });
                                        }
                                        if (_cb) _cb(resp);
                                    };
                                }
                                console.log('[modal攔截] NewOrder_Track → 本頁 action:', data && data.action);
                                return _origPost(_ph, data, cb, type || 'json');
                            }
                            return _origPost(url, data, cb, type);
                        };
                    })();
                },
                error: function(xhr) {
                    body.innerHTML = '<div class="alert alert-danger" style="margin:20px;">載入料號設定失敗 (HTTP ' + xhr.status + ')。<br>請確認路徑 <code>../../views/setting/modal_part_setting.php</code> 是否正確。</div>';
                }
            });
        }

        function openBomFiles(bom, did) {
            if (!bom && !did) return;
            var w = screen.availWidth, h = screen.availHeight;
            var pw = Math.min(1400, Math.round(w * 0.85));
            var ph = Math.min(900,  Math.round(h * 0.88));
            var pl = Math.round((w - pw) / 2);
            var pt = Math.round((h - ph) / 2);
            // 有料號時用「料號專用」獨立預覽 part_viewer.php（圖面＋ERP/資材＋料號附件，
            // 皆只抓此 BOM 名稱），並把該列 BOM 名稱一併帶過去；否則退回 BOM 版 bom_viewer.php
            var url = did
                ? 'part_viewer.php?d_id=' + encodeURIComponent(did) + (bom ? '&bom=' + encodeURIComponent(bom) : '')
                : 'bom_viewer.php?bom=' + encodeURIComponent(bom);
            var winName = did ? ('part_dv_' + did) : ('bom_viewer_' + bom);
            window.open(
                url, winName,
                'width=' + pw + ',height=' + ph + ',left=' + pl + ',top=' + pt
                    + ',resizable=yes,scrollbars=yes,menubar=no,toolbar=no,location=no,status=no'
            );
        }

        $(document).on('click', '.bom-file-item', function(e) {
            e.preventDefault();
            $('.bom-file-item').removeClass('active');
            $(this).addClass('active');
            showBomFile($(this).data('path'), $(this).data('type'));
        });

        function showBomFile(path, type) {
            var html = '';
            var typeLower = (type || '').toLowerCase();
            var _isImg = ['jpg', 'jpeg', 'png', 'gif', 'bmp'].indexOf(typeLower) !== -1;

            if (typeLower === 'pdf') {
                html = '<iframe id="bom-pdf-frame" src="' + path + '" style="width:100%; height:600px; border:none;"></iframe>';
            } else if (_isImg) {
                html = '<div id="img-zoom-wrap">' +
                       '<img id="bom-zoom-img" src="' + path + '">' +
                       '</div>';
            } else {
                html = '<div class="alert alert-info" style="margin-top:20px;">此檔案類型 (' + type + ') 無法直接預覽。<br>' +
                       '<a href="' + path + '" target="_blank" class="btn btn-primary"><i class="fa fa-download"></i> 下載/開啟檔案</a></div>';
            }
            $('#bom-file-viewer').data('current-path', path);
            $('#bom-file-viewer').data('current-type', type);
            $('#bom-file-viewer').html(html);

            // 圖片滾輪縮放 + 拖曳平移
            if (_isImg) {
                setTimeout(function() {
                    var wrap = document.getElementById('img-zoom-wrap');
                    var img  = document.getElementById('bom-zoom-img');
                    if (!wrap || !img) return;
                    var _sc = 1, _tx = 0, _ty = 0;
                    function _applyT() {
                        img.style.transform = 'translate(' + _tx + 'px,' + _ty + 'px) scale(' + _sc + ')';
                    }
                    // 滾輪縮放（不影響頁面捲動）
                    wrap.addEventListener('wheel', function(e) {
                        e.preventDefault();
                        _sc = Math.max(0.2, Math.min(8, _sc + (e.deltaY < 0 ? 0.12 : -0.12)));
                        _applyT();
                    }, { passive: false });
                    // 拖曳平移
                    var _pan = false, _px, _py, _ox, _oy;
                    wrap.addEventListener('mousedown', function(e) {
                        _pan = true; _px = e.clientX; _py = e.clientY; _ox = _tx; _oy = _ty;
                        e.preventDefault();
                    });
                    window.addEventListener('mousemove', function(e) {
                        if (!_pan) return;
                        _tx = _ox + e.clientX - _px; _ty = _oy + e.clientY - _py; _applyT();
                    });
                    window.addEventListener('mouseup', function() { _pan = false; });
                }, 30);
            }
        }

        // ── 圖面跳窗可拖曳 ──────────────────────────────────────────────────────
        (function() {
            var $modal = $('#drawingChoiceModal');
            var _drag = false, _sx, _sy, _ol, _ot, _$dlg;
            $modal.on('shown.bs.modal', function() {
                _$dlg = $modal.find('.modal-dialog');
                var r = _$dlg[0].getBoundingClientRect();
                _$dlg.css({ margin: '0', left: r.left + 'px', top: r.top + 'px', width: _$dlg.outerWidth() + 'px' });
            });
            $modal.on('hidden.bs.modal', function() {
                if (_$dlg) _$dlg.css({ margin: '', left: '', top: '', width: '' });
            });
            $modal.on('mousedown', '.modal-header', function(e) {
                if ($(e.target).closest('.close, button').length) return;
                _drag = true;
                _sx = e.clientX; _sy = e.clientY;
                var r = _$dlg[0].getBoundingClientRect();
                _ol = r.left; _ot = r.top;
                e.preventDefault();
            });
            $(document).on('mousemove.drawDrag', function(e) {
                if (!_drag || !_$dlg) return;
                _$dlg.css({ left: (_ol + e.clientX - _sx) + 'px', top: (_ot + e.clientY - _sy) + 'px' });
            }).on('mouseup.drawDrag', function() { _drag = false; });
        })();

        function printCurrentFile() {
            var path = $('#bom-file-viewer').data('current-path');
            var type = $('#bom-file-viewer').data('current-type');
            if (!path) return;
            
            if (type === 'pdf') {
                var iframe = document.getElementById('bom-pdf-frame');
                if (iframe && iframe.contentWindow) {
                    iframe.contentWindow.focus();
                    iframe.contentWindow.print();
                } else {
                    window.open(path, '_blank');
                }
            } else {
                // 使用隱藏 iframe 進行列印，避免彈出新視窗
                var iframeId = 'hidden-print-frame';
                var iframe = document.getElementById(iframeId);
                if (!iframe) {
                    iframe = document.createElement('iframe');
                    iframe.id = iframeId;
                    // 將 iframe 移出可視範圍
                    iframe.style.position = 'fixed';
                    iframe.style.left = '-9999px';
                    iframe.style.top = '0';
                    iframe.style.width = '0';
                    iframe.style.height = '0';
                    iframe.style.border = 'none';
                    document.body.appendChild(iframe);
                }
                
                var doc = iframe.contentWindow.document;
                doc.open();
                doc.write('<html><head><title>列印</title></head><body style="text-align:center; margin:0;">');
                // 圖片載入後自動觸發列印
                doc.write('<img src="' + path + '" style="max-width:100%;" onload="window.focus(); window.print();">');
                doc.write('</body></html>');
                doc.close();
            }
        }

        function openFileTagsSetting() {
            $.post('', { action: 'get_file_tags_setting' }, function(res) {
                if (res.success) {
                    var tbody = document.getElementById('tagsSettingTable').getElementsByTagName('tbody')[0];
                    tbody.innerHTML = '';
                    if (res.config && res.config.length > 0) {
                        res.config.forEach(function(item) {
                            addTagRow(item.suffix, item.label, item.color);
                        });
                    }
                    $('#fileTagsSettingModal').modal('show');
                } else {
                    alert('載入設定失敗: ' + (res.message || '未知錯誤'));
                }
            }, 'json');
        }

        function addTagRow(suffix, label, color) {
            var suffixValue = suffix || '';
            var labelValue = label || '';

            // Map old color names to hex codes for backward compatibility
            const colorMap = {
                'default': '#777777',
                'primary': '#337ab7',
                'success': '#5cb85c',
                'info': '#5bc0de',
                'warning': '#f0ad4e',
                'danger': '#d9534f'
            };

            // If color is a name, map it. If it's already a hex code, use it. Otherwise, default to gray.
            var initialColor = colorMap[color] || (color && color.startsWith('#') ? color : '#777777');

            var row = `
                <tr>
                    <td><input type="text" class="form-control input-sm tag-suffix" value="${escapeHtml(suffixValue)}" placeholder="-T"></td>
                    <td><input type="text" class="form-control input-sm tag-label" value="${escapeHtml(labelValue)}" placeholder="齒研報告"></td>
                    <td><input type="color" class="form-control input-sm tag-color" value="${escapeHtml(initialColor)}"></td>
                    <td><button type="button" class="btn btn-danger btn-xs" onclick="$(this).closest('tr').remove()"><i class="fa fa-trash"></i></button></td>
                </tr>
            `;
            $('#tagsSettingTable tbody').append(row);
        }

        function saveFileTagsSetting() {
            var config = [];
            $('#tagsSettingTable tbody tr').each(function() {
                var suffix = $(this).find('.tag-suffix').val().trim();
                var label = $(this).find('.tag-label').val().trim();
                var color = $(this).find('.tag-color').val(); // This is now an <input type="color">
                
                if (suffix && label) {
                    config.push({ suffix: suffix, label: label, color: color });
                }
            });

            $.post('', { action: 'save_file_tags_setting', tags_config: JSON.stringify(config) }, function(res) {
                if (res.success) {
                    $('#fileTagsSettingModal').modal('hide');
                    
                    // Get the current BOM from the main modal title to refresh its content
                    var currentBom = $('#modal-bom-title').text();
                    if (currentBom) {
                        // Refresh the file list to show new tags/colors
                        openBomFiles(currentBom); 
                    }
                } else {
                    alert('儲存失敗: ' + res.message);
                }
            }, 'json');
        }

        // --- Canvas Drawing Logic ---
        var canvas = document.getElementById('paint-canvas');
        var ctx = canvas.getContext('2d');
        var $container = $('#canvas-container');
        var isDrawing = false;
        var canvasHistory = [];
        var currentTool = 'pen';
        var currentScale = 1.0;
        var startX, startY;
        var snapshot;
        var rectStartX, rectStartY;

        function openImageEditor() {
            var path = $('#bom-file-viewer').data('current-path');
            var type = $('#bom-file-viewer').data('current-type');

            if (!path) {
                alert('請先選擇圖檔');
                return;
            }
            if (type === 'pdf') {
                alert('PDF 檔案暫不支援線上標記功能');
                return;
            }
            
            initCanvas(path);
            $('#imageEditModal').modal('show');
        }

        function initCanvas(imgPath) {
            canvasHistory = [];
            currentScale = 1.0;
            updateZoomDisplay();
            
            $('.tool-btn').removeClass('active');
            $('[data-tool="pen"]').addClass('active');
            currentTool = 'pen';
            $container.css('cursor', 'crosshair');

            var img = new Image();
            img.crossOrigin = "Anonymous";
            img.onload = function() {
                canvas.width = img.width;
                canvas.height = img.height;
                $(canvas).css({width: img.width, height: img.height});
                ctx.drawImage(img, 0, 0);
                updatePen();
                saveHistory();
            };
            img.src = imgPath;
        }

        function updatePen() {
            ctx.strokeStyle = $('#pen-color').val();
            ctx.fillStyle = $('#pen-color').val();
            ctx.lineWidth = $('#pen-width').val();
            ctx.lineCap = 'round';
            ctx.lineJoin = 'round';
        }

        function getCanvasPos(e) {
            var rect = canvas.getBoundingClientRect();
            return {
                x: (e.clientX - rect.left) * (canvas.width / rect.width),
                y: (e.clientY - rect.top) * (canvas.height / rect.height)
            };
        }

        // Tool buttons
        $('.tool-btn').click(function() {
            $('.tool-btn').removeClass('active');
            $(this).addClass('active');
            currentTool = $(this).data('tool');
            if (currentTool === 'pan') $container.css('cursor', 'grab');
            else $container.css('cursor', 'crosshair');
        });

        $('#pen-color, #pen-width').on('input change', updatePen);

        // Mouse Events
        $container.on('mousedown', function(e) {
            isDrawing = true;
            var pos = getCanvasPos(e);
            startX = pos.x;
            startY = pos.y;
            updatePen();

            if (currentTool === 'pen') {
                ctx.beginPath();
                ctx.moveTo(startX, startY);
            } else if (currentTool === 'pan') {
                $container.css('cursor', 'grabbing');
                startX = e.clientX; 
                startY = e.clientY;
                $container.data('scrollLeft', $container.scrollLeft());
                $container.data('scrollTop', $container.scrollTop());
            } else if (currentTool === 'rect' || currentTool === 'circle') {
                snapshot = ctx.getImageData(0, 0, canvas.width, canvas.height);
            } else if (currentTool === 'eraser_rect') {
                var offset = $container.offset();
                rectStartX = e.pageX;
                rectStartY = e.pageY;
                var relLeft = rectStartX - offset.left + $container.scrollLeft();
                var relTop = rectStartY - offset.top + $container.scrollTop();
                $('#selection-box').css({left: relLeft, top: relTop, width: 0, height: 0, display: 'block'});
            }
        });

        $container.on('mousemove', function(e) {
            if (!isDrawing) return;
            var pos = getCanvasPos(e);

            if (currentTool === 'pen') {
                ctx.lineTo(pos.x, pos.y);
                ctx.stroke();
            } else if (currentTool === 'pan') {
                var dx = e.clientX - startX;
                var dy = e.clientY - startY;
                $container.scrollLeft($container.data('scrollLeft') - dx);
                $container.scrollTop($container.data('scrollTop') - dy);
            } else if (currentTool === 'rect') {
                ctx.putImageData(snapshot, 0, 0);
                ctx.beginPath();
                ctx.rect(startX, startY, pos.x - startX, pos.y - startY);
                ctx.stroke();
            } else if (currentTool === 'circle') {
                ctx.putImageData(snapshot, 0, 0);
                ctx.beginPath();
                var w = pos.x - startX;
                var h = pos.y - startY;
                ctx.ellipse(startX + w/2, startY + h/2, Math.abs(w/2), Math.abs(h/2), 0, 0, 2 * Math.PI);
                ctx.stroke();
            } else if (currentTool === 'eraser_rect') {
                var offset = $container.offset();
                var w = e.pageX - rectStartX;
                var h = e.pageY - rectStartY;
                var curX = e.pageX - offset.left + $container.scrollLeft();
                var curY = e.pageY - offset.top + $container.scrollTop();
                var startRelX = rectStartX - offset.left + $container.scrollLeft();
                var startRelY = rectStartY - offset.top + $container.scrollTop();
                $('#selection-box').css({
                    left: (w < 0 ? curX : startRelX),
                    top: (h < 0 ? curY : startRelY),
                    width: Math.abs(w),
                    height: Math.abs(h)
                });
            }
        });

        $(document).on('mouseup', function(e) {
            if (isDrawing) {
                isDrawing = false;
                if (currentTool === 'eraser_rect') {
                    $('#selection-box').hide();
                    var pos = getCanvasPos(e);
                    ctx.fillStyle = 'white';
                    ctx.fillRect(startX, startY, pos.x - startX, pos.y - startY);
                    updatePen();
                } else if (currentTool === 'pan') {
                    $container.css('cursor', 'grab');
                }
                if (currentTool !== 'pan') saveHistory();
            }
        });

        // Zoom
        function updateZoomDisplay() {
            $('#zoom-level').text(Math.round(currentScale * 100) + '%');
            $(canvas).css({width: canvas.width * currentScale, height: canvas.height * currentScale});
        }
        $('#btn-zoom-in').click(function() { currentScale += 0.1; updateZoomDisplay(); });
        $('#btn-zoom-out').click(function() { if (currentScale > 0.2) currentScale -= 0.1; updateZoomDisplay(); });

        // History
        function saveHistory() {
            if (canvasHistory.length > 10) canvasHistory.shift();
            canvasHistory.push(canvas.toDataURL());
        }
        $('#btn-undo-canvas').click(function() {
            if (canvasHistory.length > 1) {
                canvasHistory.pop();
                var img = new Image();
                img.onload = function() { ctx.clearRect(0,0,canvas.width,canvas.height); ctx.drawImage(img, 0, 0); };
                img.src = canvasHistory[canvasHistory.length - 1];
            }
        });
        $('#btn-clear-canvas').click(function() {
            if (canvasHistory.length > 0) {
                var initialData = canvasHistory[0];
                var img = new Image();
                img.onload = function() { ctx.clearRect(0,0,canvas.width,canvas.height); ctx.drawImage(img, 0, 0); saveHistory(); };
                img.src = initialData;
                canvasHistory = [initialData];
            }
        });

        // Print Canvas
        $('#btn-print-canvas-modal').click(function() {
            var dataURL = canvas.toDataURL('image/png');
            var win = window.open('', '_blank');
            win.document.write('<html><head><title>列印標記圖檔</title></head><body style="text-align:center; margin:0;">');
            win.document.write('<img src="' + dataURL + '" style="max-width:100%; max-height:100vh;" onload="window.print(); window.close();">');
            win.document.write('</body></html>');
            win.document.close();
        });

        // Datepicker 中文化 (仿照 calendar.php)
        $(function() {
            (function(factory) {
                if (typeof define === "function" && define.amd) {
                    // AMD. Register as an anonymous module.
                    define(["../widgets/datepicker"], factory);
                } else {
                    // Browser globals
                    factory(jQuery.datepicker);
                }
            }(function(datepicker) {
                datepicker.regional["zh-TW"] = {
                    closeText: "關閉",
                    prevText: "&#x3C;上個月",
                    nextText: "下個月&#x3E;",
                    currentText: "今天",
                    monthNames: ["一月", "二月", "三月", "四月", "五月", "六月",
                        "七月", "八月", "九月", "十月", "十一月", "十二月"
                    ],
                    monthNamesShort: ["一月", "二月", "三月", "四月", "五月", "六月",
                        "七月", "八月", "九月", "十月", "十一月", "十二月"
                    ],
                    dayNames: ["星期日", "星期一", "星期二", "星期三", "星期四", "星期五", "星期六"],
                    dayNamesShort: ["週日", "週一", "週二", "週三", "週四", "週五", "週六"],
                    dayNamesMin: ["日", "一", "二", "三", "四", "五", "六"],
                    weekHeader: "週",
                    dateFormat: "yy-mm-dd",
                    firstDay: 1,
                    isRTL: false,
                    showMonthAfterYear: true,
                    yearSuffix: "年"
                };
                datepicker.setDefaults(datepicker.regional["zh-TW"]);
                return datepicker.regional["zh-TW"];
            }));
        });
    </script>

<!-- 批次模組：隱藏 number input spinner；廠商下拉樣式 -->
<style>
#bc-targets-tbody input[type="number"]::-webkit-outer-spin-button,
#bc-targets-tbody input[type="number"]::-webkit-inner-spin-button { -webkit-appearance:none; margin:0; }
#bc-targets-tbody input[type="number"] { -moz-appearance:textfield; }
/* 廠商下拉列表間距 */
.bc-maker-dd .bc-maker-item { padding:8px 12px !important; line-height:1.5; }
.bc-maker-dd .bc-maker-item:hover { background:#f0f7ff; }
.bc-maker-dd .bc-maker-item:last-child { border-bottom:none !important; }
</style>
<!-- ═══════════════════════════════════════════════════════════════════
     批次拆分 / 合併 管理 Modal  (v2)
     ═══════════════════════════════════════════════════════════════════ -->
<div id="batchMgmtModal" class="modal fade" tabindex="-1" role="dialog" style="z-index:10100;">
  <div class="modal-dialog modal-lg" style="max-width:860px;">
    <div class="modal-content">
      <div class="modal-header" style="background:#f7f9fc;padding:10px 16px;">
        <button type="button" class="close" data-dismiss="modal">&times;</button>
        <h4 class="modal-title" style="font-size:15px;">
          <i class="fa fa-sitemap" style="color:#337ab7;margin-right:6px;"></i>
          批次管理 &ndash; <span id="bm-bom-label" style="color:#337ab7;font-family:monospace;"></span>
          <small id="bm-bom-sqty" style="color:#888;margin-left:8px;"></small>
        </h4>
      </div>
      <div class="modal-body" style="padding:12px 16px;max-height:70vh;overflow-y:auto;">
        <div id="bm-overview">
          <div style="text-align:center;padding:30px;color:#aaa;"><i class="fa fa-spinner fa-spin"></i> 載入中…</div>
        </div>
      </div>
      <div class="modal-footer" style="padding:8px 16px;">
        <button type="button" class="btn btn-default btn-sm" data-dismiss="modal">關閉</button>
      </div>
    </div>
  </div>
</div>

<!-- 批次設定子 Modal -->
<div id="batchConfigModal" class="modal fade" tabindex="-1" role="dialog" style="z-index:10200;">
  <div class="modal-dialog" style="max-width:860px;">
    <div class="modal-content">
      <div class="modal-header" style="background:#fff3cd;padding:10px 16px;">
        <button type="button" class="close" data-dismiss="modal">&times;</button>
        <h4 class="modal-title" style="font-size:14px;">
          設定批次 &ndash; 第 <span id="bc-sn"></span> 關
          <small id="bc-proc-name" style="color:#888;margin-left:6px;"></small>
        </h4>
      </div>
      <div class="modal-body" style="padding:12px 16px;">
        <!-- 來源批次（上一關） -->
        <div id="bc-sources-wrap" style="margin-bottom:10px;display:none;">
          <div style="font-size:12px;font-weight:bold;color:#555;margin-bottom:5px;">
            來源批次（上一關活躍批次）
            <small style="color:#888;font-weight:normal;">— 每筆來源需全數分配完才可送出</small>
          </div>
          <div id="bc-sources"></div>
        </div>
        <!-- 目標批次 -->
        <div>
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
            <span style="font-size:12px;font-weight:bold;color:#555;">本關批次配置</span>
            <button type="button" id="bc-add-row" class="btn btn-xs btn-success">
              <i class="fa fa-plus"></i> 增加批次
            </button>
          </div>
          <div style="overflow-x:auto;">
            <table class="table table-condensed table-bordered" id="bc-targets-table"
                   style="font-size:12px;margin-bottom:0;min-width:500px;">
              <thead id="bc-targets-thead"></thead>
              <tbody id="bc-targets-tbody"></tbody>
            </table>
          </div>
        </div>
        <div id="bc-validation" style="margin-top:10px;font-size:12px;"></div>
      </div>
      <div class="modal-footer" style="padding:8px 16px;">
        <button type="button" class="btn btn-default btn-sm" data-dismiss="modal">取消</button>
        <button type="button" id="bc-confirm" class="btn btn-primary btn-sm" disabled>
          <i class="fa fa-check"></i> 確認設定
        </button>
      </div>
    </div>
  </div>
</div>

<script>
// ═══════════════════════════════════════════════════════════════════
// 批次管理模組  v2
// ═══════════════════════════════════════════════════════════════════
(function(){
    var _bm = {
        bom        : '',
        bom_sqty   : 0,
        groups     : {},
        events     : [],
        makers     : [],
        cfg_sn     : 0,
        cfg_sources: [],
    };

    // ── 工具 ──────────────────────────────────────────────────────
    function pageUrl() {
        return window.location.pathname.split('/').pop() || 'OreadyReply_ForPm_BaseOfTime2.php';
    }

    // ── 開啟主 Modal ──────────────────────────────────────────────
    window.openBatchMgmt = function(bom) {
        _bm.bom = bom;
        $('#bm-bom-label').text(bom);
        $('#bm-overview').html('<div style="text-align:center;padding:30px;color:#aaa;"><i class="fa fa-spinner fa-spin"></i> 載入中…</div>');
        $('#bm-bom-sqty').text('');
        $('#batchMgmtModal').modal('show');
        loadBatchStatus();
    };

    function loadBatchStatus() {
        $.post(pageUrl(), { action:'get_batch_status', bom:_bm.bom }, function(res){
            if (!res.success) {
                $('#bm-overview').html('<div style="color:red;padding:12px;">載入失敗：' + (res.message||'') + '</div>');
                return;
            }
            _bm.bom_sqty = res.bom_sqty || 0;
            _bm.groups   = res.groups   || {};
            _bm.events   = res.events   || [];
            _bm.makers   = res.makers   || [];
            $('#bm-bom-sqty').text('共 ' + _bm.bom_sqty + ' pcs');
            renderOverview();
        }, 'json').fail(function(){
            $('#bm-overview').html('<div style="color:red;padding:12px;">連線失敗</div>');
        });
    }

    // ── 渲染概覽 ──────────────────────────────────────────────────
    function renderOverview() {
        var sns = Object.keys(_bm.groups).map(Number).sort(function(a,b){return a-b;});
        if (!sns.length) {
            $('#bm-overview').html('<div style="color:#999;padding:12px;">此 BOM 尚無製程記錄</div>');
            return;
        }
        var html = '<table style="width:100%;border-collapse:collapse;">'
                 + '<colgroup><col style="width:72px"><col style="width:88px"><col><col style="width:106px"></colgroup>'
                 + '<thead><tr style="background:#f0f4f8;font-size:12px;">'
                 + '<th style="padding:6px 8px;">序號</th><th style="padding:6px 8px;">製程</th>'
                 + '<th style="padding:6px 8px;">批次狀態</th><th style="padding:6px 8px;text-align:center;">操作</th>'
                 + '</tr></thead><tbody>';

        sns.forEach(function(sn){
            var batches  = _bm.groups[sn] || [];
            var active   = batches.filter(function(b){ return !parseInt(b.is_consumed); });
            var consumed = batches.filter(function(b){ return  parseInt(b.is_consumed); });
            var procName = (batches[0] && batches[0].ProcessName) || '';
            var prevSns  = sns.filter(function(s){ return s < sn; });
            var hasActiveSplit = active.some(function(b){ return b.batch_label; });
            var canReset = active.every(function(b){ return b.processing_state==='N'; });

            // 每個活躍批次：chip + 移轉按鈕 + 內嵌移轉表單
            var chipsHtml = active.map(function(b){
                var label = b.batch_label || '─';
                var state = b.processing_state || 'N';
                var sc = state==='ing'?'#1a7a1a': state==='Q'?'#a06000': (state==='E'||state==='1')?'#999': state==='skip'?'#e67e22':'#337ab7';
                var st = {N:'待發包',P:'待移轉',ing:'加工中',Q:'QC待驗',E:'已結',1:'已結',skip:'跳過'}[state] || state;
                var canTransfer = (state !== 'E' && state !== '1' && state !== 'skip');
                var makerDisplay = (b.maker_id_no||'') + (b.maker_id ? ' '+b.maker_id : '');

                var chip = '<span style="display:inline-flex;align-items:center;background:#e8f0fe;border:1px solid #c5d5f5;'
                         + 'border-radius:12px;padding:2px 8px;margin:2px 4px 2px 0;font-size:11px;">'
                         + '<strong style="color:#337ab7;margin-right:3px;">'+escapeHtml(label)+'</strong>'
                         + escapeHtml(String(b.sqty))+' pcs'
                         + (b.maker_id ? ' <span style="color:#888;margin-left:3px;">'+escapeHtml(b.maker_id)+'</span>' : '')
                         + ' <span style="color:'+sc+';margin-left:3px;font-size:10px;">['+st+']</span>'
                         + '</span>';

                var transferBtn = canTransfer
                    ? '<button type="button" class="btn btn-xs btn-info bm-transfer-btn" data-fid="'+b.bom_ing_fid
                      +'" style="margin-right:6px;padding:1px 6px;font-size:10px;border-radius:10px;vertical-align:middle;">'
                      +'<i class="fa fa-truck"></i> 移轉</button>'
                    : '';

                var transferForm = canTransfer
                    ? '<div id="bm-tf-'+b.bom_ing_fid+'" style="display:none;background:#f0f7ff;border:1px solid #b3d7f7;'
                      +'border-radius:4px;padding:7px 10px;margin-top:5px;font-size:11px;">'
                      +'<div style="font-weight:bold;color:#337ab7;margin-bottom:5px;">批次 <strong>'+escapeHtml(label)+'</strong> 移轉設定</div>'
                      +'<div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;">'
                      // 廠商模糊搜尋
                      +'<div class="bc-maker-wrap" style="position:relative;">'
                      +'<input type="text" class="form-control input-sm bm-tf-maker" autocomplete="off" '
                      +'placeholder="廠商 ID 或名稱（必填）" '
                      +'data-fid="'+b.bom_ing_fid+'" data-maker-no="'+escapeHtml(b.maker_id_no||'')+'" '
                      +'value="'+escapeHtml(makerDisplay)+'" '
                      +'style="height:26px;padding:2px 6px;font-size:11px;width:160px;">'
                      +'<div class="bc-maker-dd" style="display:none;position:fixed;min-width:200px;background:#fff;border:1px solid #ccc;'
                      +'border-radius:3px;max-height:140px;overflow-y:auto;z-index:19999;font-size:11px;box-shadow:0 2px 8px rgba(0,0,0,.2);"></div>'
                      +'</div>'
                      // 移轉日
                      +'<input type="date" class="form-control input-sm bm-tf-date" data-fid="'+b.bom_ing_fid+'" '
                      +'value="'+getTodayStr()+'" style="height:26px;padding:2px 6px;font-size:11px;width:134px;">'
                      // 確認 / 取消
                      +'<button type="button" class="btn btn-xs btn-primary bm-tf-confirm" data-fid="'+b.bom_ing_fid
                      +'" style="height:26px;padding:1px 10px;font-size:11px;">確認移轉</button>'
                      +'<button type="button" class="btn btn-xs btn-default bm-tf-cancel" data-fid="'+b.bom_ing_fid
                      +'" style="height:26px;padding:1px 7px;font-size:11px;">取消</button>'
                      +'</div></div>'
                    : '';

                return chip + transferBtn + transferForm;
            }).join('');

            // 已消耗提示（改為描述性文字）
            if (consumed.length) {
                chipsHtml += '<div style="font-size:10px;color:#bbb;margin-top:3px;" '
                           + 'title="此製程序號有 '+consumed.length+' 筆批次已在拆分/合併時被消耗重組（正常流程）">'
                           + '<i class="fa fa-info-circle"></i> '+consumed.length+' 筆批次已在上次重組時消耗（歷史紀錄）</div>';
            }
            if (!active.length && !consumed.length) chipsHtml = '<span style="color:#bbb;font-size:11px;">尚未設定</span>';

            var btnHtml = '';
            if (!hasActiveSplit || canReset) {
                var lbl = hasActiveSplit ? '重新設定' : '拆分批次';
                btnHtml = '<button type="button" class="btn btn-xs btn-primary bm-config-btn" data-sn="'+sn+'" style="font-size:11px;">'
                        + '<i class="fa fa-sliders"></i> '+lbl+'</button>';
            } else {
                btnHtml = '<span style="font-size:10px;color:#aaa;">加工中，無法重設</span>';
            }

            html += '<tr style="border-bottom:1px solid #eee;'+(active.length?'':'background:#fafafa;')+'">'
                 + '<td style="padding:7px 8px;font-family:monospace;font-size:13px;font-weight:bold;color:#555;">'+sn+'</td>'
                 + '<td style="padding:7px 8px;font-size:12px;">'+escapeHtml(procName)+'</td>'
                 + '<td style="padding:7px 8px;">'+chipsHtml+'</td>'
                 + '<td style="padding:7px 8px;text-align:center;">'+btnHtml+'</td>'
                 + '</tr>';
        });
        html += '</tbody></table>'
              + '<div style="margin-top:8px;font-size:11px;color:#aaa;">* 各關均可拆分/合併批次；廠商移轉後無法重設批次。</div>';
        $('#bm-overview').html(html);
    }

    // ── 今天日期字串 ──────────────────────────────────────────────
    function getTodayStr() {
        var d = new Date();
        return d.getFullYear() + '-'
             + String(d.getMonth()+1).padStart(2,'0') + '-'
             + String(d.getDate()).padStart(2,'0');
    }

    // ── 開啟批次設定 Modal ────────────────────────────────────────
    function openBatchConfig(sn) {
        _bm.cfg_sn = sn;
        var sns    = Object.keys(_bm.groups).map(Number).sort(function(a,b){return a-b;});
        var snIdx  = sns.indexOf(sn);
        var prevSn  = snIdx > 0 ? sns[snIdx-1] : null;
        // 第一關：以本關現有活躍批次為來源（自身拆分）；其他關：以上一關活躍批次為來源
        var srcSn   = prevSn !== null ? prevSn : sn;
        var sources = (_bm.groups[srcSn]||[]).filter(function(b){ return !parseInt(b.is_consumed); });
        _bm.cfg_sources = sources;

        var procName = (_bm.groups[sn] && _bm.groups[sn][0] && _bm.groups[sn][0].ProcessName) || '';
        $('#bc-sn').text(sn);
        $('#bc-proc-name').text(procName);

        renderSourcesPanel();
        buildTableHeader();
        renderDefaultTargets(sn, sources);
        $('#bc-validation').html('');
        $('#bc-confirm').prop('disabled', true);
        $('#batchConfigModal').modal('show');
    }

    // ── 來源面板 ──────────────────────────────────────────────────
    function renderSourcesPanel() {
        var sources = _bm.cfg_sources;
        if (!sources.length) { $('#bc-sources-wrap').hide(); return; }
        $('#bc-sources-wrap').show();
        var html = '<div style="display:flex;flex-wrap:wrap;gap:6px;">';
        sources.forEach(function(s){
            var lbl = s.batch_label || '─';
            html += '<div style="background:#fff3cd;border:1px solid #ffc107;border-radius:8px;padding:4px 10px;font-size:12px;">'
                  + '<strong style="color:#856404;">'+escapeHtml(lbl)+'</strong>'
                  + '&nbsp;<span id="src-remain-'+s.bom_ing_fid+'" style="font-weight:bold;color:#e67e00;">'+s.sqty+'</span>'
                  + '<span style="color:#888;"> / '+s.sqty+' pcs</span>'
                  + (s.maker_id ? '&nbsp;<small style="color:#aaa;">'+escapeHtml(s.maker_id)+'</small>' : '')
                  + '</div>';
        });
        html += '</div>';
        $('#bc-sources').html(html);
    }

    // ── 動態表頭 ──────────────────────────────────────────────────
    function buildTableHeader() {
        var sources    = _bm.cfg_sources;
        var hasSources = sources.length > 0;
        var th = '<tr style="background:#f5f5f5;">'
               + '<th style="width:34px;text-align:center;">批次</th>'
               + '<th style="min-width:160px;">廠商 <small style="color:#aaa;font-weight:normal;">(可留空)</small></th>';
        if (hasSources) {
            sources.forEach(function(s){
                var lbl = s.batch_label || '─';
                th += '<th style="text-align:center;min-width:64px;">'
                    + '來自 <strong style="color:#856404;">'+escapeHtml(lbl)+'</strong>'
                    + '<br><small style="color:#aaa;font-weight:normal;">max '+s.sqty+'</small></th>';
            });
            th += '<th style="text-align:center;width:56px;">小計</th>';
        } else {
            th += '<th style="width:72px;text-align:center;">數量</th>';
        }
        th += '<th style="width:28px;"></th></tr>';
        $('#bc-targets-thead').html(th);
    }

    // ── 預設目標列 ────────────────────────────────────────────────
    function renderDefaultTargets(sn, sources) {
        var existing = (_bm.groups[sn]||[]).filter(function(b){ return !parseInt(b.is_consumed); });
        $('#bc-targets-tbody').empty();
        if (existing.length > 0 && existing.some(function(b){ return b.batch_label; })) {
            existing.forEach(function(b, i){
                addTargetRow(i, { qty: b.sqty, maker_id_no: b.maker_id_no, maker_id: b.maker_id });
            });
        } else {
            var totalSrc = sources.reduce(function(a,s){ return a+(parseInt(s.sqty)||0); }, 0) || _bm.bom_sqty;
            addTargetRow(0, { qty: totalSrc });
        }
        reindexRows();
        bindInputEvents();
        validateConfig();
    }

    // ── 廠商模糊搜尋 Input ────────────────────────────────────────
    function makeMakerHtml(defaultNo, defaultName) {
        var display = defaultNo ? (defaultNo + (defaultName ? ' '+defaultName : '')) : '';
        return '<div class="bc-maker-wrap" style="position:relative;">'
             + '<input type="text" class="form-control input-sm bc-maker-text" autocomplete="off" '
             + 'placeholder="廠商 ID 或名稱（可留空）" '
             + 'data-maker-no="'+escapeHtml(defaultNo||'')+'" '
             + 'value="'+escapeHtml(display)+'" '
             + 'style="height:24px;padding:2px 6px;font-size:11px;">'
             + '<div class="bc-maker-dd" style="display:none;position:fixed;'
             + 'min-width:220px;background:#fff;border:1px solid #ccc;border-radius:3px;'
             + 'max-height:150px;overflow-y:auto;z-index:19999;font-size:11px;'
             + 'box-shadow:0 2px 8px rgba(0,0,0,.2);"></div>'
             + '</div>';
    }

    // ── 新增目標列 ────────────────────────────────────────────────
    function addTargetRow(idx, defaults) {
        defaults = defaults || {};
        var sources    = _bm.cfg_sources || [];
        var hasSources = sources.length > 0;

        var srcCells = '';
        if (hasSources) {
            sources.forEach(function(s){
                srcCells += '<td style="padding:2px 3px;text-align:center;">'
                          + '<input type="number" class="form-control input-sm bc-src-input" '
                          + 'data-fid="'+s.bom_ing_fid+'" data-src-max="'+s.sqty+'" '
                          + 'placeholder="0" min="0" '
                          + 'style="width:56px;height:24px;padding:2px 3px;text-align:center;">'
                          + '</td>';
            });
            // 小計欄（自動計算）
            srcCells += '<td style="padding:2px 4px;text-align:center;">'
                      + '<span class="bc-calc-qty" style="font-weight:bold;color:#aaa;">0</span>'
                      + '</td>';
        } else {
            srcCells = '<td style="padding:2px 4px;">'
                     + '<input type="number" class="form-control input-sm bc-qty" value="'+(defaults.qty||'')+'" min="1" '
                     + 'style="width:62px;height:24px;padding:2px 4px;text-align:right;">'
                     + '</td>';
        }

        var row = $('<tr class="bc-target-row">');
        row.html(
            '<td class="bc-row-label" style="text-align:center;font-weight:bold;color:#337ab7;padding:4px;">A</td>'
          + '<td style="padding:2px 4px;">' + makeMakerHtml(defaults.maker_id_no, defaults.maker_id) + '</td>'
          + srcCells
          + '<td style="padding:2px 3px;text-align:center;">'
          +   '<button type="button" class="btn btn-xs btn-danger bc-del-row" style="padding:1px 5px;">'
          +   '<i class="fa fa-times"></i></button>'
          + '</td>'
        );
        $('#bc-targets-tbody').append(row);
    }

    function reindexRows() {
        var labels = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'.split('');
        $('#bc-targets-tbody .bc-target-row').each(function(i){
            $(this).find('.bc-row-label').text(labels[i] || 'X'+i);
        });
    }

    // ── 事件綁定 ──────────────────────────────────────────────────
    function bindInputEvents() {
        var $tbody = $('#bc-targets-tbody');

        // 數量/來源 input → 更新小計 + 驗證
        $tbody.off('input.bc').on('input.bc', '.bc-src-input, .bc-qty', function(){
            updateCalcQty($(this).closest('tr'));
            validateConfig();
        });

        // 刪除列
        $tbody.off('click.bcdel').on('click.bcdel', '.bc-del-row', function(){
            if ($tbody.find('.bc-target-row').length <= 1) return;
            $(this).closest('tr').remove();
            reindexRows();
            validateConfig();
        });

        // 廠商模糊搜尋（fixed 定位避免 modal overflow 遮擋）
        $tbody.off('input.maker').on('input.maker', '.bc-maker-text', function(){
            var $inp  = $(this);
            var val   = $inp.val().trim().toLowerCase();
            var $dd   = $inp.siblings('.bc-maker-dd');
            $inp.data('maker-no', '');
            if (!val) { $dd.hide(); return; }
            var matches = _bm.makers.filter(function(m){
                return (m.maker_id_no||'').toLowerCase().indexOf(val) >= 0
                    || (m.maker_id||'').toLowerCase().indexOf(val) >= 0;
            }).slice(0, 10);
            if (!matches.length) { $dd.hide(); return; }
            // 計算 fixed 位置
            var rect = this.getBoundingClientRect();
            $dd.css({ top: (rect.bottom+2)+'px', left: rect.left+'px', width: Math.max(220, rect.width)+'px' });
            var ddHtml = matches.map(function(m){
                return '<div class="bc-maker-item" '
                     + 'data-no="'+escapeHtml(m.maker_id_no)+'" data-name="'+escapeHtml(m.maker_id||'')+'" '
                     + 'style="padding:5px 8px;cursor:pointer;border-bottom:1px solid #f0f0f0;white-space:nowrap;">'
                     + '<strong style="color:#337ab7;">'+escapeHtml(m.maker_id_no)+'</strong>'
                     + (m.maker_id ? ' <span style="color:#555;">'+escapeHtml(m.maker_id)+'</span>' : '')
                     + '</div>';
            }).join('');
            $dd.html(ddHtml).show();
        });

        $tbody.off('blur.maker').on('blur.maker', '.bc-maker-text', function(){
            setTimeout(function(){ $('.bc-maker-dd').hide(); }, 180);
        });
    }

    // 廠商下拉選擇（用 mousedown 避免 blur 搶先執行）
    $(document).off('mousedown.bmaker').on('mousedown.bmaker', '#bc-targets-tbody .bc-maker-item', function(){
        var no   = $(this).data('no');
        var name = $(this).data('name') || '';
        var $wrap = $(this).closest('.bc-maker-wrap');
        $wrap.find('.bc-maker-text').val(no + (name ? ' '+name : '')).data('maker-no', no);
        $wrap.find('.bc-maker-dd').hide();
        validateConfig();
    });

    function updateCalcQty($row) {
        var total = 0;
        $row.find('.bc-src-input').each(function(){
            total += parseInt($(this).val()) || 0;
        });
        var $cq = $row.find('.bc-calc-qty');
        if ($cq.length) $cq.text(total).css('color', total > 0 ? '#337ab7' : '#aaa');
    }

    // ── 驗證 ──────────────────────────────────────────────────────
    function validateConfig() {
        var sources    = _bm.cfg_sources;
        var hasSources = sources.length > 0;
        var ok = true, msgs = [];

        if (!$('#bc-targets-tbody .bc-target-row').length) {
            msgs.push('至少需要一個批次');
            ok = false;
        }

        if (hasSources) {
            // 各來源已分配量
            var srcAlloc = {};
            sources.forEach(function(s){ srcAlloc[s.bom_ing_fid] = 0; });
            var anyZeroQty = false;
            $('#bc-targets-tbody .bc-target-row').each(function(){
                var rowTotal = 0;
                $(this).find('.bc-src-input').each(function(){
                    var fid = parseInt($(this).data('fid'));
                    var v   = parseInt($(this).val()) || 0;
                    srcAlloc[fid] = (srcAlloc[fid]||0) + v;
                    rowTotal += v;
                });
                if (rowTotal <= 0) anyZeroQty = true;
            });
            if (anyZeroQty) { msgs.push('每個批次小計必須 > 0'); ok = false; }
            var srcOk = true;
            sources.forEach(function(s){
                var remain = (parseInt(s.sqty)||0) - (srcAlloc[s.bom_ing_fid]||0);
                $('#src-remain-'+s.bom_ing_fid).text(remain)
                    .css('color', remain===0?'#1a7a1a': remain<0?'#c00':'#e67e00');
                if (remain !== 0) srcOk = false;
            });
            if (!srcOk) { msgs.push('各來源需全數分配完畢'); ok = false; }
        } else {
            // 無來源：直接填數量
            var anyZero = false;
            $('#bc-targets-tbody .bc-target-row').each(function(){
                if (!(parseInt($(this).find('.bc-qty').val()) > 0)) anyZero = true;
            });
            if (anyZero) { msgs.push('每個批次數量必須 > 0'); ok = false; }
        }

        $('#bc-validation').html(ok
            ? '<span style="color:#1a7a1a;"><i class="fa fa-check-circle"></i> 分配正確，可以確認</span>'
            : '<span style="color:#c00;"><i class="fa fa-exclamation-circle"></i> '+msgs.join('；')+'</span>');
        $('#bc-confirm').prop('disabled', !ok);
        return ok;
    }

    // ── 增加批次列按鈕 ────────────────────────────────────────────
    $('#bc-add-row').on('click', function(){
        addTargetRow($('#bc-targets-tbody .bc-target-row').length, {});
        reindexRows();
        bindInputEvents();
        validateConfig();
    });

    // ── 確認送出 ──────────────────────────────────────────────────
    $('#bc-confirm').on('click', function(){
        if (!validateConfig()) return;
        var sources    = _bm.cfg_sources;
        var hasSources = sources.length > 0;
        var targets    = [];

        $('#bc-targets-tbody .bc-target-row').each(function(){
            var $row   = $(this);
            var $mText = $row.find('.bc-maker-text');
            var mkr_no = ($mText.data('maker-no') || '').toString().trim();
            // 若使用者直接輸入廠商ID（未從下拉選），嘗試比對
            if (!mkr_no) {
                var raw = $mText.val().trim();
                var found = _bm.makers.filter(function(m){ return m.maker_id_no === raw || m.maker_id === raw; });
                if (found.length) mkr_no = found[0].maker_id_no;
            }
            var mkr_id = '';
            var mMatch = _bm.makers.filter(function(m){ return m.maker_id_no === mkr_no; });
            if (mMatch.length) mkr_id = mMatch[0].maker_id || '';

            var qty  = 0;
            var srcs = [];
            if (hasSources) {
                $row.find('.bc-src-input').each(function(){
                    var fid = parseInt($(this).data('fid'));
                    var v   = parseInt($(this).val()) || 0;
                    if (fid && v > 0) { srcs.push({ from_fid: fid, transfer_qty: v }); qty += v; }
                });
            } else {
                qty = parseInt($row.find('.bc-qty').val()) || 0;
            }
            targets.push({ qty: qty, maker_id_no: mkr_no, maker_id: mkr_id, sources: srcs });
        });

        var $btn = $(this).prop('disabled', true).text('處理中…');
        $.post(pageUrl(), {
            action : 'do_batch_operation',
            bom    : _bm.bom,
            bom_sn : _bm.cfg_sn,
            targets: JSON.stringify(targets),
        }, function(res){
            $btn.prop('disabled', false).html('<i class="fa fa-check"></i> 確認設定');
            if (res.success) {
                $('#batchConfigModal').modal('hide');
                loadBatchStatus();
            } else {
                alert('設定失敗：' + (res.message||'未知錯誤'));
            }
        }, 'json').fail(function(){
            $btn.prop('disabled', false).html('<i class="fa fa-check"></i> 確認設定');
            alert('連線失敗');
        });
    });

    // 子 Modal 關閉後保持主 Modal open
    $('#batchConfigModal').on('hidden.bs.modal', function(){
        if ($('#batchMgmtModal').hasClass('in')) $('body').addClass('modal-open');
    });

    // ── Overview 事件委派（一次綁定，適用所有動態內容）────────────
    // 拆分批次按鈕
    $('#bm-overview').on('click', '.bm-config-btn', function(){
        openBatchConfig(parseInt($(this).data('sn')));
    });

    // 展開/收合移轉表單
    $('#bm-overview').on('click', '.bm-transfer-btn', function(){
        var fid = $(this).data('fid');
        $('#bm-tf-'+fid).slideToggle(120);
    });

    // 移轉表單 — 廠商模糊搜尋
    $('#bm-overview').on('input', '.bm-tf-maker', function(){
        var $inp  = $(this);
        var val   = $inp.val().trim().toLowerCase();
        var $dd   = $inp.siblings('.bc-maker-dd');
        $inp.data('maker-no', '');
        if (!val) { $dd.hide(); return; }
        var matches = _bm.makers.filter(function(m){
            return (m.maker_id_no||'').toLowerCase().indexOf(val) >= 0
                || (m.maker_id||'').toLowerCase().indexOf(val) >= 0;
        }).slice(0, 10);
        if (!matches.length) { $dd.hide(); return; }
        var rect = this.getBoundingClientRect();
        $dd.css({ top:(rect.bottom+2)+'px', left:rect.left+'px', width:Math.max(210,rect.width)+'px' });
        $dd.html(matches.map(function(m){
            return '<div class="bc-maker-item" data-no="'+escapeHtml(m.maker_id_no)+'" data-name="'+escapeHtml(m.maker_id||'')+'" '
                 + 'style="padding:5px 8px;cursor:pointer;border-bottom:1px solid #f0f0f0;white-space:nowrap;">'
                 + '<strong style="color:#337ab7;">'+escapeHtml(m.maker_id_no)+'</strong>'
                 + (m.maker_id ? ' <span style="color:#555;">'+escapeHtml(m.maker_id)+'</span>' : '')
                 + '</div>';
        }).join('')).show();
    });
    $('#bm-overview').on('blur', '.bm-tf-maker', function(){
        setTimeout(function(){ $('#bm-overview .bc-maker-dd').hide(); }, 180);
    });

    // 移轉表單廠商下拉選擇
    $(document).off('mousedown.bmovmaker').on('mousedown.bmovmaker', '#bm-overview .bc-maker-item', function(){
        var no   = $(this).data('no');
        var name = $(this).data('name') || '';
        var $wrap = $(this).closest('.bc-maker-wrap');
        $wrap.find('.bm-tf-maker').val(no+(name?' '+name:'')).data('maker-no', no);
        $wrap.find('.bc-maker-dd').hide();
    });

    // 取消移轉
    $('#bm-overview').on('click', '.bm-tf-cancel', function(){
        $('#bm-tf-'+$(this).data('fid')).slideUp(120);
    });

    // 確認移轉
    $('#bm-overview').on('click', '.bm-tf-confirm', function(){
        var fid    = $(this).data('fid');
        var $form  = $('#bm-tf-'+fid);
        var $mInp  = $form.find('.bm-tf-maker');
        var mkr_no = ($mInp.data('maker-no') || '').toString().trim();

        // 若未從下拉選，嘗試精確比對輸入文字
        if (!mkr_no) {
            var raw = $mInp.val().trim();
            var found = _bm.makers.filter(function(m){
                return m.maker_id_no === raw || m.maker_id === raw
                    || (m.maker_id_no+' '+m.maker_id) === raw;
            });
            if (found.length) { mkr_no = found[0].maker_id_no; $mInp.data('maker-no', mkr_no); }
        }
        if (!mkr_no) { alert('請先輸入廠商並從下拉清單選擇'); $mInp.focus(); return; }

        var mkr_name = (_bm.makers.filter(function(m){ return m.maker_id_no===mkr_no; })[0]||{}).maker_id || '';
        var date_val = $form.find('.bm-tf-date').val().trim();
        if (!date_val) { alert('請填寫移轉日期'); return; }

        var $btn = $(this).prop('disabled', true).text('移轉中…');
        $.post(pageUrl(), {
            action       : 'transfer_process',
            bom_ing_fid  : fid,
            transfer_date: date_val,
            maker_no     : mkr_no,
            maker_name   : mkr_name,
        }, function(res){
            $btn.prop('disabled', false).text('確認移轉');
            if (res.success) {
                loadBatchStatus(); // 重新載入批次管理概覽
                // 同時刷新主表（更新 ingActiveMap 與發單日欄的狀態按鈕）
                if (typeof fetchDataAndFilter === 'function') fetchDataAndFilter();
            } else {
                alert('移轉失敗：'+(res.message||''));
            }
        }, 'json').fail(function(){
            $btn.prop('disabled', false).text('確認移轉');
            alert('連線失敗');
        });
    });

})();
</script>
<script src="capacity_gantt.js?v=<?= @filemtime(__DIR__ . '/capacity_gantt.js') ?>"></script>
</body>

</html>
