<?php

session_start();

include '../../src/common/DBConnection.php';
include '../../src/store/_setupUser.php';
include '../../src/common/_config.php';

// --- 權限檢查 ---
$db_connection = new DBConnection();
$conn_pdo = $db_connection->getPDO();

if (!isset($_SESSION['userName'])) {
    header("Location:../../index.php");
    exit;
}

$stmt = $conn_pdo->prepare("SELECT id FROM user WHERE user_uname = ?");
$stmt->execute([$_SESSION['userName']]);
$currentUser = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$currentUser) {
    header("Location:../../index.php");
    exit;
}

$permStmt = $conn_pdo->prepare("SELECT permission FROM user_module_permissions WHERE user_id = ? AND module_code = 'hr_permissions'");
$permStmt->execute([$currentUser['id']]);
$myHrPerms = $permStmt->fetchColumn();

if (!$myHrPerms || (strpos($myHrPerms, 'A') === false && strpos($myHrPerms, 'R') === false && strpos($myHrPerms, 'U') === false)) {
    header("Location:../admin/dashboard.php?msg=" . urlencode("無權限檢視頁面"));
    exit;
}

$canEdit = ($myHrPerms && (strpos($myHrPerms, 'A') !== false || strpos($myHrPerms, 'U') !== false));
// --- 結束權限檢查 ---

$msg = null;

// ── 批圖編輯器：標籤 NAS 儲存路徑設定（system_settings: imgedit_label_nas_dir）──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_imgedit_label_dir'])) {
    if (!$canEdit) {
        $msg = '無權限修改標籤儲存路徑';
    } else {
        $newDir = rtrim(trim($_POST['imgedit_label_dir'] ?? ''), '\\/');
        if ($newDir === '') {
            $msg = '路徑不可為空';
        } else {
            try {
                $conn_pdo->beginTransaction();
                $st = $conn_pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_by_id, updated_by)
                                          VALUES ('imgedit_label_nas_dir', ?, ?, ?)
                                          ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value),
                                              updated_by_id = VALUES(updated_by_id), updated_by = VALUES(updated_by)");
                $st->execute([$newDir, (int)$currentUser['id'], $_SESSION['userName'] ?? '']);
                $conn_pdo->commit();
                $msg = '標籤儲存路徑已更新' . (is_dir($newDir) ? '' : '（提醒：目前伺服器無法存取此路徑，請確認 NAS 權限）');
            } catch (Exception $e) {
                if ($conn_pdo->inTransaction()) $conn_pdo->rollBack();
                $msg = '儲存失敗：' . $e->getMessage();
            }
        }
    }
}
$imgeditLabelDir = '';
try {
    $v = $conn_pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'imgedit_label_nas_dir'")->fetchColumn();
    if ($v) $imgeditLabelDir = $v;
} catch (Exception $e) {}

// ── 批圖編輯器：同一料號最多保留幾份工作檔（system_settings: imgedit_workfile_max_count）──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_imgedit_workfile_max'])) {
    if (!$canEdit) {
        $msg = '無權限修改工作檔保留上限';
    } else {
        $newMax = (int)($_POST['imgedit_workfile_max'] ?? 0);
        if ($newMax < 1) {
            $msg = '保留上限至少要 1 份';
        } else {
            try {
                $conn_pdo->beginTransaction();
                $st = $conn_pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_by_id, updated_by)
                                          VALUES ('imgedit_workfile_max_count', ?, ?, ?)
                                          ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value),
                                              updated_by_id = VALUES(updated_by_id), updated_by = VALUES(updated_by)");
                $st->execute([$newMax, (int)$currentUser['id'], $_SESSION['userName'] ?? '']);
                $conn_pdo->commit();
                $msg = '工作檔保留上限已更新為 ' . $newMax . ' 份';
            } catch (Exception $e) {
                if ($conn_pdo->inTransaction()) $conn_pdo->rollBack();
                $msg = '儲存失敗：' . $e->getMessage();
            }
        }
    }
}
$imgeditWorkfileMax = 3;
try {
    $v = $conn_pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'imgedit_workfile_max_count'")->fetchColumn();
    if ($v !== false && (int)$v > 0) $imgeditWorkfileMax = (int)$v;
} catch (Exception $e) {}

// ── AS9100 文件管理：檔案儲存根路徑（system_settings: as_doc_nas_dir）──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_asdoc_nas_dir'])) {
    if (!$canEdit) {
        $msg = '無權限修改 AS 文件儲存路徑';
    } else {
        $newDir = rtrim(trim($_POST['asdoc_nas_dir'] ?? ''), '\\/');
        if ($newDir === '') {
            $msg = '路徑不可為空';
        } else {
            try {
                $conn_pdo->beginTransaction();
                $st = $conn_pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_by_id, updated_by)
                                          VALUES ('as_doc_nas_dir', ?, ?, ?)
                                          ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value),
                                              updated_by_id = VALUES(updated_by_id), updated_by = VALUES(updated_by)");
                $st->execute([$newDir, (int)$currentUser['id'], $_SESSION['userName'] ?? '']);
                $conn_pdo->commit();
                $msg = 'AS 文件儲存路徑已更新' . (is_dir($newDir) ? '' : '（提醒：目前伺服器無法存取此路徑，請確認 NAS 權限）');
            } catch (Exception $e) {
                if ($conn_pdo->inTransaction()) $conn_pdo->rollBack();
                $msg = '儲存失敗：' . $e->getMessage();
            }
        }
    }
}
$asdocNasDir = '';
try {
    $v = $conn_pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'as_doc_nas_dir'")->fetchColumn();
    if ($v) $asdocNasDir = $v;
} catch (Exception $e) {}

//人員
$conn = new DBConnection();
$admins = $conn->getAll("
    SELECT 
        u.id, 
        u.user_cname, 
        u.user_uname
    FROM `user` u
    WHERE u.state != 0 AND u.id != 2
    ORDER BY u.id ASC
");

// Fetch User Roles (New Schema)
$user_roles_data = $conn->getAll("
    SELECT 
        m.user_id,
        m.department_id,
        d.name as department_name,
        d.sort_order as dept_sort,
        m.position_id,
        p.name as position_title,
        p.sort_order as pos_sort,
        m.is_main
    FROM user_department_position_map m
    JOIN department d ON m.department_id = d.id
    JOIN position p ON m.position_id = p.id
    ORDER BY m.is_main DESC, d.sort_order ASC, p.sort_order ASC
");

// Map roles to users
$roles_by_user = [];
foreach ($user_roles_data as $r) {
    $roles_by_user[$r['user_id']][] = $r;
}

// Fetch System Modules
$modules = $conn->getAll("
    SELECT 
        m.*,
        g.group_name,
        g.sort_order AS group_sort,
        p.page_name,
        p.page_url
    FROM system_modules m
    LEFT JOIN system_module_groups g ON m.group_id = g.group_id
    LEFT JOIN system_module_pages p ON m.page_id = p.page_id
    ORDER BY g.sort_order ASC, m.sort_order ASC
");

// Fetch All Pages for grouping
$all_pages = $conn->getAll("SELECT * FROM system_module_pages ORDER BY page_id ASC");
$pages_by_module = [];
$pages_by_group = [];
$pages_by_id = [];
foreach ($all_pages as $p) {
    $p_mCode = isset($p['module_code']) ? trim($p['module_code']) : '';
    if ($p_mCode !== '') {
        $pages_by_module[$p_mCode][] = $p;
    }
    $p_groupId = isset($p['group_id']) ? trim($p['group_id']) : '';
    if ($p_groupId !== '') {
        $pages_by_group[$p_groupId][] = $p;
    }
    $pages_by_id[$p['page_id']] = $p;
}

// ── 底下已有「角色指派」區塊的頁面：人員權限設定僅保留「R 開啟」單一選項，
//    細部功能（新增/修改/刪除…）一律由本頁下方各模組的角色指派決定（過渡期規則，2026-07-20）──
$rbacManagedPageIds = [
    84,  // 報價單 (quotation_list_NEW)
    95,  // 報價_TEST (quotation_list_test)
    51,  // 公告/通知管理 (createEvent)
    88, 89, // 首頁設定 (home_page_setting)
    86,  // QC檢驗_NEW_TEST (inspection_combined_prototype)
    118, // 品管檢驗表2.0 (inspection_entry_v2)；與 86 共用 module='qc' 角色，見下方「品管檢驗（QC）」角色指派區塊
    123, // 圖面變更紀錄 (drawing_change_log)；同樣走 module='qc' 角色（建立需 qc_manage_settings）
    93,  // 異常矯正處理單 (correction_order)
    96,  // 圖面自動改檔名工具 (drawing_rename)
    97,  // 叫料文件自動改檔名工具 (bom_rename)
    33, 26, // BOM 總表 / bom_TEST (OreadyReply oready)
    98,  // BOM追蹤 (bom_tracking)
    101, // 個人工作紀錄 (personal_task)
    102, // 訂單毛利分析_TEST (Order_Profit_Analysis)
    100, // AS9100文件管理 (as_document_management)
    128, // AS流程說明手冊 (as_flow_guide)；唯讀頁，與 100 共用 module='as_doc' 角色（asdoc_view 即可檢視）
    135, 136, // 審核表單模板管理 / 審核表單 (review_form_template / review_form)；共用 module='review_form' 角色
    141, 142, // 人資職務表單 / 人資職務表單設定 (hr_position_forms / hr_position_forms_template)；共用 module='hr_form' 角色
    143, // 表單簽核設計器 (form_signer)；module='form_signer' 角色
];
$rbacManagedModuleCodes = ['bom_track', 'personal_task'];  // 模組本身直連 RBAC 頁面者

// Fetch User Module Permissions
$user_module_perms_data = $conn->getAll("SELECT * FROM user_module_permissions");
$user_module_perms = [];
foreach ($user_module_perms_data as $perm) {
    // Assuming columns: user_id, module_code, permission
    $scope = $perm['scope'] ?? 'group';
    $user_module_perms[$perm['user_id']][$scope][$perm['module_code']] = $perm['permission'];
}

// Attach roles to admins and determine sort key
foreach ($admins as &$admin) {
    $admin['roles'] = isset($roles_by_user[$admin['id']]) ? $roles_by_user[$admin['id']] : [];

    // Find main department for sorting
    $dept_sort = 9999;
    $pos_sort = 9999;
    foreach ($admin['roles'] as $role) {
        if ($role['is_main'] == 1) {
            $dept_sort = $role['dept_sort'];
            $pos_sort = $role['pos_sort'];
            break;
        }
    }
    if ($dept_sort === 9999 && !empty($admin['roles'])) {
        $dept_sort = $admin['roles'][0]['dept_sort'];
        $pos_sort = $admin['roles'][0]['pos_sort'];
    }
    $admin['dept_sort'] = $dept_sort;
    $admin['pos_sort'] = $pos_sort;

    // Attach permissions
    $admin['permissions'] = $user_module_perms[$admin['id']] ?? ['group' => [], 'page' => []];
}
unset($admin);

// Sort admins
usort($admins, function ($a, $b) {
    $deptA = (int)$a['dept_sort'];
    $deptB = (int)$b['dept_sort'];
    if ($deptA != $deptB) {
        return $deptA - $deptB;
    }
    $posA = (int)$a['pos_sort'];
    $posB = (int)$b['pos_sort'];
    if ($posA != $posB) {
        return $posA - $posB;
    }
    return $a['id'] - $b['id'];
});

// 準備用於複製權限的來源使用者清單 (只列出有設定權限的人員)
$sourceUsers = [];
foreach ($admins as $u) {
    $hasPerms = false;
    if (!empty($u['permissions'])) {
        foreach ($u['permissions'] as $scope => $codes) {
            foreach ($codes as $p) {
                if ($p !== '') {
                    $hasPerms = true;
                    break 2;
                }
            }
        }
    }
    if ($hasPerms) {
        $sourceUsers[] = [
            'id' => $u['id'],
            'name' => $u['user_cname'],
            'permissions' => $u['permissions']
        ];
    }
}

@$userid       = $_SESSION['userid'];
@$user_cname   = $_SESSION['user_cname'];
@$user_uname   = $_SESSION['user_uname'];
@$user_password = $_SESSION['user_password'];
@$user_open   = $_SESSION['user_cname'];

// ── 確保 RBAC 資料表存在（此頁面可能比報價單頁先被開啟）──────────────────
try {
    $conn_pdo->exec("CREATE TABLE IF NOT EXISTS roles (
        role_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
        role_code VARCHAR(30) NOT NULL UNIQUE,
        role_name VARCHAR(50) NOT NULL,
        is_system TINYINT NOT NULL DEFAULT 0,
        note VARCHAR(200),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn_pdo->exec("CREATE TABLE IF NOT EXISTS role_features (
        role_id INT NOT NULL,
        feature_code VARCHAR(60) NOT NULL,
        PRIMARY KEY (role_id, feature_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $conn_pdo->exec("CREATE TABLE IF NOT EXISTS user_roles (
        user_id INT NOT NULL,
        role_id INT NOT NULL,
        PRIMARY KEY (user_id, role_id),
        INDEX idx_ur_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // 植入管理員角色
    $conn_pdo->exec("INSERT IGNORE INTO roles (role_code,role_name,is_system) VALUES ('admin','管理員',1)");
    $_aid = $conn_pdo->query("SELECT role_id FROM roles WHERE role_code='admin' LIMIT 1")->fetchColumn();
    if ($_aid) $conn_pdo->prepare("INSERT IGNORE INTO role_features (role_id,feature_code) VALUES (?,?)")->execute([$_aid,'all']);
} catch(Exception $_e) {}

// ── 模組對照表（唯一來源，2026-08-27）────────────────────────────────────
//   本頁所有地方要顯示「模組名稱」時，一律查這張表，不要再各自寫死一份中文名——
//   原本快速切換列、各區塊標題、角色下拉三個地方各寫各的，使用者看到的名稱就不一致
//   （角色下拉直接秀出 accounting / as_doc 這種資料庫代碼）。
//   label ＝ 使用者在畫面上看到的中文名（沿用原本快速切換列的用字）
//   page  ＝ 這個模組對應的頁面檔名；用來即時查它在「子頁面設定」被歸到哪個主項目，
//           所以在 views/admin/system_module_setting.php 改了主項目歸屬，這裡的分群會自動跟著變（鐵律4）
$EG_ROLE_MODULES = [
    'quotation'           => ['prefix'=>'quot',    'label'=>'報價單',              'page'=>'quotation_list_NEW.php'],
    'notice'              => ['prefix'=>'notice',  'label'=>'公告/通知',           'page'=>'createEvent.php'],
    'homepage'            => ['prefix'=>'home',    'label'=>'首頁設定',            'page'=>'home_page_setting.php'],
    'qc'                  => ['prefix'=>'qc',      'label'=>'QC檢驗',              'page'=>'inspection_entry_v2.php'],
    'car'                 => ['prefix'=>'car',     'label'=>'異常矯正單',          'page'=>'correction_order.php'],
    'master_data'         => ['prefix'=>'mdata',   'label'=>'主檔管理',            'page'=>'master_data_management.php'],
    'imgedit'             => ['prefix'=>'imgedit', 'label'=>'批圖編輯器',          'page'=>''],   // 未登記進選單（帶參數才進得去）
    'review_form'         => ['prefix'=>'rvf',     'label'=>'審核表單',            'page'=>'review_form.php'],
    'hr_form'             => ['prefix'=>'hrf',     'label'=>'人資職務表單',        'page'=>'hr_position_forms.php'],
    'form_signer'         => ['prefix'=>'fsd',     'label'=>'表單簽核設計器',      'page'=>'form_signer.php'],
    'drawing_rename'      => ['prefix'=>'drawren', 'label'=>'圖面改檔名',          'page'=>'drawing_rename.php'],
    'bom_rename'          => ['prefix'=>'bomren',  'label'=>'叫料改檔名',          'page'=>'bom_rename.php'],
    'oready'              => ['prefix'=>'oready',  'label'=>'生管BOM',             'page'=>'OreadyReply_ForPm_BaseOfTime.php'],
    'bom_track'           => ['prefix'=>'bomtrk',  'label'=>'BOM追蹤',             'page'=>'bom_tracking.php'],
    'order_profit'        => ['prefix'=>'profit',  'label'=>'訂單毛利分析',        'page'=>'Order_Profit_Analysis.php'],
    'part_process_report' => ['prefix'=>'ppr',     'label'=>'料號製程履歷報告',    'page'=>'part_process_report.php'],
    'stamp'               => ['prefix'=>'stamp',   'label'=>'圖章管理',            'page'=>'stamp_management.php'],
    'kpi'                 => ['prefix'=>'kpi',     'label'=>'KPI績效指標',         'page'=>'kpi_main.php'],
    'personal_task'       => ['prefix'=>'ptask',   'label'=>'個人工作紀錄',        'page'=>'personal_task.php'],
    'roster'              => ['prefix'=>'roster',  'label'=>'輪值排班',            'page'=>'roster.php'],
    'as_doc'              => ['prefix'=>'asdoc',   'label'=>'AS文件管理',          'page'=>'as_document_management.php'],
    'db_backup'           => ['prefix'=>'dbbk',    'label'=>'資料庫備份',          'page'=>'db_backup.php'],
    'data_console'        => ['prefix'=>'dc',      'label'=>'資料急救台',          'page'=>'data_console.php'],
    'tool_calib'          => ['prefix'=>'tcal',    'label'=>'量測儀器校驗',        'page'=>'tool_calibration.php'],
    'training'            => ['prefix'=>'train',   'label'=>'教育訓練',            'page'=>'training_record.php'],
    'meeting'             => ['prefix'=>'meet',    'label'=>'會議紀錄',            'page'=>'meeting_record.php'],
    'vendor_audit'        => ['prefix'=>'vaud',    'label'=>'供應商稽核',          'page'=>'vendor_audit.php'],
    'equip_machine'       => ['prefix'=>'eqm',     'label'=>'機台設備一覽表',      'page'=>'equipment_machine_list.php'],
    'business_trip'       => ['prefix'=>'btrp',    'label'=>'公出單',              'page'=>'business_trip.php'],
    'doc_apply'           => ['prefix'=>'dap',     'label'=>'文件制修申請單',      'page'=>'doc_apply.php'],
    'eng_change'          => ['prefix'=>'eng',     'label'=>'工程變更申請單',      'page'=>'eng_change.php'],
    'print_sign_log'      => ['prefix'=>'psl',     'label'=>'列印與簽核紀錄',      'page'=>'print_sign_log.php'],
    'internal_audit'      => ['prefix'=>'ia',      'label'=>'內部稽核',            'page'=>'internal_audit.php'],
    'leave'               => ['prefix'=>'leave',   'label'=>'請假系統',            'page'=>'leave_request.php'],
    'shipping'            => ['prefix'=>'ship',    'label'=>'快速出貨',            'page'=>'Shipping_Quick.php'],
    'purchase'            => ['prefix'=>'purc',    'label'=>'申請採購',            'page'=>'purchase_request.php'],
    'accounting'          => ['prefix'=>'acc',     'label'=>'會計',                'page'=>'recon_overview.php'],
    'external_doc'        => ['prefix'=>'extdoc',  'label'=>'外來文件清單',        'page'=>'external_doc_list.php'],
    'order_track'         => ['prefix'=>'otrk',    'label'=>'訂單追蹤',            'page'=>'_cleanOrder_Track_ate_only.php'],
    'type_id_ctrl'        => ['prefix'=>'tidc',    'label'=>'型態識別文件管制表',  'page'=>'type_id_ctrl_doc.php'],
    'td_dev_eval'         => ['prefix'=>'tdev',    'label'=>'產品開發評估表',      'page'=>'td_dev_eval.php'],
    'pfmea'               => ['prefix'=>'pfmea',   'label'=>'PFMEA',               'page'=>'pfmea.php'],
    'project'             => ['prefix'=>'prj',     'label'=>'專案管理',            'page'=>'project_mgmt.php'],
];

/** 模組代碼 → 中文名（查不到就回代碼本身，至少不會顯示空白） */
if (!function_exists('eg_module_label')) {
    function eg_module_label($module) {
        global $EG_ROLE_MODULES;
        return $EG_ROLE_MODULES[$module]['label'] ?? (string)$module;
    }
}

// 每個模組屬於哪個「主項目」：即時查子頁面設定（system_module_pages.group_id），
// 所以在「子頁面設定＋主項目綁定」改了歸屬，這裡的分群立刻跟著變，不必回來改程式。
$EG_MODULE_GROUP = [];   // module => 主項目名稱
try {
    $_pgRows = $conn_pdo->query("
        SELECT p.page_url, g.group_name, g.sort_order
        FROM system_module_pages p
        LEFT JOIN system_module_groups g ON g.group_id = p.group_id")->fetchAll(PDO::FETCH_ASSOC);
    $_pgByFile = []; $_grpSort = [];
    foreach ($_pgRows as $_pg) {
        $_bn = basename((string)$_pg['page_url']);
        if ($_bn === '' || isset($_pgByFile[$_bn])) continue;
        $_pgByFile[$_bn] = $_pg['group_name'];
        if ($_pg['group_name'] !== null) $_grpSort[$_pg['group_name']] = (int)$_pg['sort_order'];
    }
    foreach ($EG_ROLE_MODULES as $_mk => $_mv) {
        $_g = ($_mv['page'] !== '' && isset($_pgByFile[$_mv['page']])) ? $_pgByFile[$_mv['page']] : null;
        $EG_MODULE_GROUP[$_mk] = ($_g !== null && $_g !== '') ? $_g : '其他';
    }
} catch (Exception $_e) {
    foreach ($EG_ROLE_MODULES as $_mk => $_mv) $EG_MODULE_GROUP[$_mk] = '其他';
    $_grpSort = [];
}

// ── 角色指派資料（2026-08-27 改為自動生成）────────────────────────────────
//   原本 44 個模組每個都要手寫 3 段（變數宣告、角色清單載入、已指派載入）＋1 個渲染呼叫，
//   新模組只要漏掉其中一段就會靜靜地少一塊設定畫面。改成一次撈完、依 roles.module 分組：
//     $RS[module]  ＝ 該模組可指派的角色清單（含系統角色 admin）
//     $RSU[module] ＝ [user_id => 已指派的角色]（個人指派 user_roles）
//   下方 eg_render_role_section() 一律吃這兩個陣列；沒有手寫區塊的模組會由最後的
//   「其他模組」迴圈自動補一塊出來（見該處說明），不會再有漏掉的情況。
$RS = []; $RSU = []; $_quotDepts = [];
$_asdocPositions = []; $_asdocPosRoles = [];
try {
    $_sysRoles = $conn_pdo->query(
        "SELECT role_id, role_name, is_system FROM roles WHERE is_system=1 ORDER BY role_id ASC")->fetchAll(PDO::FETCH_ASSOC);
    $_allRoles = $conn_pdo->query(
        "SELECT role_id, role_name, is_system, module FROM roles WHERE is_system=0 AND module IS NOT NULL AND module<>'' ORDER BY role_id ASC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($_allRoles as $_r) {
        $_m = $_r['module'];
        if (!isset($RS[$_m])) $RS[$_m] = $_sysRoles;   // 系統角色 admin 每個模組都列（與原本行為一致）
        unset($_r['module']);
        $RS[$_m][] = $_r;
    }
    // 已指派（個人指派）：系統角色要出現在每一個模組的清單裡，與原本 `WHERE module=? OR is_system=1` 相同
    $_urRows = $conn_pdo->query(
        "SELECT ur.user_id, r.role_id, r.role_name, r.is_system, r.module
         FROM user_roles ur JOIN roles r ON r.role_id = ur.role_id")->fetchAll(PDO::FETCH_ASSOC);
    $_sysRoleUsers = [];
    foreach ($_urRows as $_r) {
        $_entry = ['role_id'=>$_r['role_id'], 'role_name'=>$_r['role_name']];
        if ((int)$_r['is_system'] === 1) {
            $_sysRoleUsers[$_r['user_id']][] = $_entry;
            foreach (array_keys($RS) as $_m) $RSU[$_m][$_r['user_id']][] = $_entry;   // 系統角色：每個模組都顯示
        } elseif ($_r['module'] !== null && $_r['module'] !== '') {
            $RSU[$_r['module']][$_r['user_id']][] = $_entry;
        }
    }
    // 有自訂角色的模組，系統角色也要一起併進去（上面那圈只跑了當下已存在的 key，順序上可能漏掉後建的）
    foreach (array_keys($RS) as $_m) {
        foreach ($_sysRoleUsers as $_uid => $_es) {
            foreach ($_es as $_e) {
                $_has = false;
                foreach (($RSU[$_m][$_uid] ?? []) as $_x) { if ($_x['role_id'] == $_e['role_id']) { $_has = true; break; } }
                if (!$_has) $RSU[$_m][$_uid][] = $_e;
            }
        }
    }
} catch(Exception $_e) {}

// 取值一律走這兩支：某個模組還沒建任何自訂角色時（例如 homepage），仍要列出系統角色「管理員」，
// 與舊寫法的 `WHERE module=? OR is_system=1` 完全等價——否則該區塊會整塊變成「尚未建立任何角色」。
if (!function_exists('rs_of')) {
    function rs_of($module) { global $RS, $_sysRoles; return $RS[$module] ?? ($_sysRoles ?: []); }
    function rsu_of($module) {
        global $RSU, $_sysRoleUsers;
        if (isset($RSU[$module])) return $RSU[$module];
        return $_sysRoleUsers ?: [];     // 只有系統角色的模組：仍要顯示誰是管理員
    }
}

// 相容：AS9100「職稱權限」區塊仍直接引用 $_asdocRoles
$_asdocRoles = rs_of('as_doc');

// ── 部門 × 職稱 角色設定（2026-08-27 新增）────────────────────────────────
//   使用者的困擾：同部門同職稱的人（例如生管組 7 個組員）每一位都要手動指派一次角色。
//   這裡以「部門＋職稱」為單位設定一次，該編制底下的在職人員自動取得，新人到職掛上職務就有。
//   解析優先序（見 src/common/role_features_helper.php）：個人指派 →（同模組沒有個人指派時）部門職稱。
//   department_id=0 代表「該職稱所有部門通用」（本表原本的語意，網管設定的超級管理員那筆就在這一層）。
$_dpRows = [];      // 實際有在職人員的「部門×職稱」編制
$_dpRoles = [];     // ["{did}_{pid}" => [已指派角色]]
$_rolesByModule = []; // 指派用的角色下拉（依模組分組）
try {
    $_dpRows = $conn_pdo->query("
        SELECT m.department_id, d.name AS department_name, m.position_id, p.name AS position_name,
               COUNT(DISTINCT m.user_id) AS people,
               GROUP_CONCAT(DISTINCT u.user_cname ORDER BY u.user_cname SEPARATOR '、') AS people_names
        FROM user_department_position_map m
        JOIN department d ON d.id = m.department_id
        JOIN position  p ON p.id = m.position_id
        JOIN `user` u ON u.id = m.user_id AND u.state = 1
        GROUP BY m.department_id, d.name, m.position_id, p.name, d.sort_order, p.sort_order
        ORDER BY d.sort_order, d.id, p.sort_order, p.id")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($conn_pdo->query("
        SELECT pr.department_id, pr.position_id, r.role_id, r.role_name, r.module
        FROM position_roles pr JOIN roles r ON r.role_id = pr.role_id
        ORDER BY r.module, r.role_id")->fetchAll(PDO::FETCH_ASSOC) as $_r) {
        $_dpRoles[(int)$_r['department_id'] . '_' . (int)$_r['position_id']][] = $_r;
    }
    // 孤兒設定：position_roles 裡 role_id 已經不存在於 roles 的列（不 JOIN 才看得到）。
    // 一定要顯示出來，否則會變成「有一筆設定存在、畫面上卻完全看不到」——日後查權限會查不出來。
    foreach ($conn_pdo->query("
        SELECT pr.department_id, pr.position_id, pr.role_id
        FROM position_roles pr LEFT JOIN roles r ON r.role_id = pr.role_id
        WHERE r.role_id IS NULL")->fetchAll(PDO::FETCH_ASSOC) as $_r) {
        $_dpRoles[(int)$_r['department_id'] . '_' . (int)$_r['position_id']][] =
            ['role_id'=>$_r['role_id'], 'role_name'=>'#'.$_r['role_id'].'（角色已不存在）', 'module'=>'', 'orphan'=>1];
    }
    // 可指派的角色：系統角色（管理員）刻意排除——整個職稱變全域管理員風險太大，
    // 與既有 assign_position_role 的後端規則一致（鐵律8：前端不列、後端也擋）
    foreach ($conn_pdo->query("
        SELECT role_id, role_name, module FROM roles
        WHERE is_system=0 AND module IS NOT NULL AND module<>'' ORDER BY module, role_id")->fetchAll(PDO::FETCH_ASSOC) as $_r) {
        $_rolesByModule[$_r['module']][] = $_r;
    }
} catch(Exception $_e) {}

// ── 職稱角色指派資料（position_roles，AS9100 文件管理用）──────────────────
try {
    $_asdocPositions = $conn_pdo->query("
        SELECT p.id, p.name,
               GROUP_CONCAT(DISTINCT d.name ORDER BY d.sort_order SEPARATOR '、') AS departments
        FROM position p
        LEFT JOIN department_position dp ON dp.position_id = p.id
        LEFT JOIN department d ON d.id = dp.department_id
        GROUP BY p.id, p.name
        ORDER BY p.sort_order ASC, p.id ASC")->fetchAll(PDO::FETCH_ASSOC);
    $st = $conn_pdo->prepare("
        SELECT pr.position_id, r.role_id, r.role_name
        FROM position_roles pr JOIN roles r ON r.role_id = pr.role_id
        WHERE r.module = ?");
    $st->execute(['as_doc']);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $_r) {
        $_asdocPosRoles[$_r['position_id']][] = ['role_id'=>$_r['role_id'], 'role_name'=>$_r['role_name']];
    }
} catch(Exception $_e) {}

// 角色指派區塊（依模組共用渲染）
if (!function_exists('eg_render_role_section')) {
    function eg_render_role_section($prefix, $module, $title, $icon, $color, $hint, $roles, $userRoles, $admins, $depts, $canEdit) {
        // 記錄已渲染的模組，供頁尾「其他模組」自動補區塊時排除（不必再維護一份手寫清單＝鐵律4）
        $GLOBALS['_eg_rendered_role_modules'][] = $module;
        ?>
        <div class="row" style="margin-top:20px;" id="<?= $prefix ?>-role-section">
            <div class="col-md-12">
                <div class="x_panel">
                    <div class="x_title">
                        <h2><i class="fa <?= $icon ?>" style="color:<?= $color ?>;margin-right:7px;"></i><?= htmlspecialchars($title) ?> <small>角色指派</small></h2>
                        <ul class="nav navbar-right panel_toolbox"><li><a class="collapse-link"><i class="fa fa-chevron-up"></i></a></li></ul>
                        <div class="clearfix"></div>
                    </div>
                    <div class="x_content">
                        <p style="font-size:12px;color:#888;margin-bottom:12px;"><i class="fa fa-info-circle"></i> <?= $hint ?></p>
                        <?php if (empty($roles)): ?>
                            <div class="alert alert-warning">尚未建立任何角色，請先至該頁面的「權限設定」建立角色。</div>
                        <?php else: ?>
                            <div style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap;">
                                <div class="input-group input-group-sm" style="width:220px;">
                                    <span class="input-group-addon"><i class="fa fa-search"></i></span>
                                    <input type="text" id="<?= $prefix ?>-search-name" class="form-control" placeholder="搜尋姓名 / 帳號" oninput="roleFilterTable('<?= $prefix ?>')">
                                </div>
                                <select id="<?= $prefix ?>-search-dept" class="form-control input-sm" style="width:160px;" onchange="roleFilterTable('<?= $prefix ?>')">
                                    <option value="">— 所有部門 —</option>
                                    <?php foreach ($depts as $_d): ?><option value="<?= htmlspecialchars($_d) ?>"><?= htmlspecialchars($_d) ?></option><?php endforeach; ?>
                                </select>
                                <button class="btn btn-default btn-sm" onclick="roleClearSearch('<?= $prefix ?>')"><i class="fa fa-times"></i> 清除</button>
                                <span id="<?= $prefix ?>-filter-count" class="text-muted" style="line-height:30px;font-size:12px;"></span>
                            </div>
                            <table class="table table-striped table-bordered table-condensed" id="<?= $prefix ?>-role-table" style="font-size:13px;">
                                <thead style="background:#f8f9fa;">
                                    <tr>
                                        <th style="width:110px;">姓名</th><th style="width:100px;">帳號</th><th style="width:120px;">部門</th>
                                        <th>已指派角色</th>
                                        <?php if ($canEdit): ?><th style="width:220px;">新增角色</th><?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody id="<?= $prefix ?>-role-tbody">
                                <?php foreach ($admins as $admin):
                                    $assignedRoles = $userRoles[$admin['id']] ?? [];
                                    $allDepts = []; $deptDisplay = '';
                                    foreach ($admin['roles'] as $dr) {
                                        if (empty($dr['department_name'])) continue;
                                        if (!in_array($dr['department_name'], $allDepts)) $allDepts[] = $dr['department_name'];
                                        $deptDisplay .= ($dr['is_main'] == 1)
                                            ? htmlspecialchars($dr['department_name'])
                                            : ' <span style="color:#e67e22;font-size:11px;">兼 '.htmlspecialchars($dr['department_name']).'</span>';
                                    }
                                ?>
                                    <tr data-name="<?= htmlspecialchars(strtolower($admin['user_cname'].$admin['user_uname'])) ?>" data-dept="<?= htmlspecialchars(implode('|', $allDepts)) ?>">
                                        <td style="font-weight:600;"><?= htmlspecialchars($admin['user_cname']) ?></td>
                                        <td style="color:#888;"><?= htmlspecialchars($admin['user_uname']) ?></td>
                                        <td style="color:#666;font-size:12px;"><?= $deptDisplay ?: '—' ?></td>
                                        <td id="<?= $prefix ?>-tags-<?= $admin['id'] ?>">
                                            <?php if (empty($assignedRoles)): ?>
                                                <span class="text-muted" style="font-size:12px;">（未指派）</span>
                                            <?php else: foreach ($assignedRoles as $ar): ?>
                                                <span class="label <?= $ar['role_name']==='管理員'?'label-danger':'label-primary' ?>" style="margin-right:4px;font-size:12px;padding:3px 7px;display:inline-block;">
                                                    <?= htmlspecialchars($ar['role_name']) ?>
                                                    <?php if ($canEdit): ?>
                                                        <a href="#" onclick="roleRemove('<?= $prefix ?>','<?= $module ?>',<?= $admin['id'] ?>,<?= $ar['role_id'] ?>,<?= $ar['role_name']==='管理員'?1:0 ?>);return false;" style="color:#fff;margin-left:4px;opacity:.8;" title="移除">×</a>
                                                    <?php endif; ?>
                                                </span>
                                            <?php endforeach; endif; ?>
                                        </td>
                                        <?php if ($canEdit): ?>
                                        <td>
                                            <div class="input-group input-group-sm">
                                                <select class="form-control" id="<?= $prefix ?>-sel-<?= $admin['id'] ?>">
                                                    <option value="">— 選擇角色 —</option>
                                                    <?php foreach ($roles as $role): ?><option value="<?= $role['role_id'] ?>"><?= htmlspecialchars($role['role_name']) ?></option><?php endforeach; ?>
                                                </select>
                                                <span class="input-group-btn">
                                                    <button class="btn btn-primary btn-sm" type="button" onclick="roleAssign('<?= $prefix ?>','<?= $module ?>',<?= $admin['id'] ?>)"><i class="fa fa-plus"></i> 指派</button>
                                                </span>
                                            </div>
                                        </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}

// 從 $admins 的 roles (department) 收集部門清單
$_deptSet = [];
foreach ($admins as $_adm) {
    foreach ($_adm['roles'] as $_dr) {
        if (!empty($_dr['department_name'])) {
            $_deptSet[$_dr['department_name']] = $_dr['dept_sort'] ?? 9999;
        }
    }
}
asort($_deptSet);
$_quotDepts = array_keys($_deptSet);

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <!-- Meta, title, CSS, favicons, etc. -->
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Excellentgear 超正齒輪</title>

    <!-- Bootstrap -->
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <!-- NProgress -->
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <!-- Custom Theme Style -->
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <style>
        /* 隱藏 Datatables 的功能按鈕 */
        .dt-buttons {
            display: none;
        }

        /* 讓 DataTables 的 filter 區塊變成左右排列 */
        .dataTables_filter {
            display: flex !important;
            justify-content: space-between;
            align-items: center;
            width: 100%;
            float: none !important;
            text-align: left !important;
        }

        .scroll-to-top {
            position: fixed; bottom: 20px; right: 20px; width: 50px; height: 50px;
            background-color: rgba(255,255,255,0.5); color: #000; border: none; border-radius: 50%;
            text-align: center; line-height: 50px; cursor: pointer; font-size: 12px; font-weight: bold;
            box-shadow: 0 4px 8px rgba(0,0,0,0.2); transition: all 0.3s; z-index: 1000;
        }
        .scroll-to-top:hover { background-color: rgba(255,255,255,0.7); }
    </style>
</head>

<body class="nav-sm">
    <div class="container body">
        <div class="main_container">

            <!-- side and top bar include -->
            <?php include '../partPage/sideAndTopBarMenu.html' ?>
            <!-- /side and top bar include -->

            <!-- page content -->
            <div class="right_col" role="main">
                <div class="">

                    <!-- ══ 快速切換：跳至各設定區塊（Excel 凍結窗格式：捲過後固定貼齊視窗頂端）══ -->
                    <div class="row" id="quick-nav-block">
                        <div class="col-md-12">
                            <div class="x_panel" style="padding:8px 12px;margin-bottom:10px;">
                                <div style="display:flex;align-items:center;gap:6px;margin-bottom:6px;">
                                    <strong><i class="fa fa-compass"></i> 快速切換</strong>
                                    <span class="text-muted" style="font-size:11px;">分類依「子頁面設定」的主項目歸屬，在該頁調整後這裡會自動跟著變</span>
                                    <a href="#" id="qn-toggle" onclick="qnToggle();return false;" style="font-size:11px;margin-left:auto;"><i class="fa fa-angle-double-down"></i> 展開全部</a>
                                </div>
                                <?php
                                // 常用／不屬於任何模組的區塊（固定放最前面）
                                $_navFixed = [
                                    'perm-matrix-section' => '人員權限設定',
                                    'person-view-section' => '人員權限總覽',
                                    'dp-role-section'     => '部門×職稱角色',
                                ];
                                // 模組區塊：依「主項目」分群，名稱與分群都取自唯一來源 $EG_ROLE_MODULES / $EG_MODULE_GROUP
                                $_navGroups = [];
                                foreach ($EG_ROLE_MODULES as $_mk => $_mv) {
                                    $_navGroups[$EG_MODULE_GROUP[$_mk] ?? '其他'][] = [
                                        'id'    => $_mv['prefix'] . '-role-section',
                                        'label' => $_mv['label'],
                                    ];
                                }
                                // 主項目排序：照 system_module_groups 的 sort_order，「其他」永遠最後
                                uksort($_navGroups, function($a, $b) use ($_grpSort) {
                                    if ($a === '其他') return 1;
                                    if ($b === '其他') return -1;
                                    $sa = $_grpSort[$a] ?? 9999; $sb = $_grpSort[$b] ?? 9999;
                                    return $sa === $sb ? strcmp($a, $b) : $sa - $sb;
                                });
                                // 其他非模組的設定區塊
                                $_navTail = [
                                    'asdoc-pos-role-section'    => 'AS文件·職稱權限',
                                    'imgedit-label-dir-section' => '批圖標籤路徑',
                                    'asdoc-nas-dir-section'     => 'AS文件儲存路徑',
                                ];
                                ?>
                                <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-bottom:6px;padding-bottom:6px;border-bottom:1px solid #eee;">
                                    <?php foreach ($_navFixed as $_nid => $_nlabel): ?>
                                        <a href="#<?= $_nid ?>" class="btn btn-xs btn-warning quick-nav-link" data-target="<?= $_nid ?>"><?= htmlspecialchars($_nlabel) ?></a>
                                    <?php endforeach; ?>
                                </div>
                                <div id="qn-groups" style="display:flex;align-items:center;gap:4px 6px;flex-wrap:wrap;max-height:60px;overflow-y:auto;">
                                    <?php foreach ($_navGroups as $_gname => $_items): ?>
                                        <span style="display:inline-block;font-size:11px;color:#8a5a2b;background:#FFF3E2;border:1px solid #E4D3BC;border-radius:3px;padding:1px 6px;line-height:18px;white-space:nowrap;margin-left:2px;"><?= htmlspecialchars($_gname) ?></span>
                                        <?php foreach ($_items as $_it): ?>
                                            <a href="#<?= $_it['id'] ?>" class="btn btn-xs btn-default quick-nav-link" data-target="<?= $_it['id'] ?>"><?= htmlspecialchars($_it['label']) ?></a>
                                        <?php endforeach; ?>
                                    <?php endforeach; ?>
                                    <span style="display:inline-block;font-size:11px;color:#999;background:#f5f5f5;border:1px solid #e5e5e5;border-radius:3px;padding:1px 6px;line-height:18px;white-space:nowrap;margin-left:2px;">其他設定</span>
                                    <?php foreach ($_navTail as $_nid => $_nlabel): ?>
                                        <a href="#<?= $_nid ?>" class="btn btn-xs btn-default quick-nav-link" data-target="<?= $_nid ?>"><?= htmlspecialchars($_nlabel) ?></a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- ／快速切換 ══ -->

                    <?php if ($canEdit): ?>
                    <!-- ══ 複製其他員工的權限設定（角色指派＋選單群組/頁面權限，一次複製）══ -->
                    <div class="row" id="copy-perm-section">
                        <div class="col-md-12">
                            <div class="x_panel" style="padding:12px 15px;margin-bottom:10px;">
                                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                                    <strong><i class="fa fa-clone"></i> 複製其他員工的權限設定：</strong>
                                    <span class="text-muted" style="font-size:12px;">複製來源員工「所有模組的角色指派」與「選單群組/頁面權限」；不含頁面白名單等個別例外設定，複製前會寫入稽核紀錄</span>
                                </div>
                                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:8px;">
                                    <label style="margin:0;">來源員工</label>
                                    <select id="copy-perm-source" class="form-control input-sm" style="width:200px;" data-eg-filter="輸入姓名篩選…">
                                        <option value="">-- 請選擇 --</option>
                                        <?php foreach ($admins as $_cpA): ?>
                                            <option value="<?= (int)$_cpA['id'] ?>"><?= htmlspecialchars($_cpA['user_cname'] ?: $_cpA['user_uname']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <i class="fa fa-long-arrow-right"></i>
                                    <label style="margin:0;">目標員工</label>
                                    <select id="copy-perm-target" class="form-control input-sm" style="width:200px;" data-eg-filter="輸入姓名篩選…">
                                        <option value="">-- 請選擇 --</option>
                                        <?php foreach ($admins as $_cpA): ?>
                                            <option value="<?= (int)$_cpA['id'] ?>"><?= htmlspecialchars($_cpA['user_cname'] ?: $_cpA['user_uname']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <select id="copy-perm-mode" class="form-control input-sm" style="width:240px;">
                                        <option value="merge">合併（只補目標沒有的，保留原有設定）</option>
                                        <option value="overwrite">覆蓋（先清空目標原設定，完全比照來源）</option>
                                    </select>
                                    <button type="button" class="btn btn-sm btn-primary" id="btn-copy-perm"><i class="fa fa-clone"></i> 複製</button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- ／複製其他員工的權限設定 ══ -->
                    <?php endif; ?>

                    <?php if (!empty($msg)): ?>
                    <div class="row">
                        <div class="col-md-12">
                            <div class="alert alert-info" style="margin-bottom:10px;"><i class="fa fa-info-circle"></i> <?= htmlspecialchars($msg) ?></div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="row" id="perm-matrix-section">

                        <div class="col-md-12 col-sm-12 col-xs-12">
                            <div class="x_panel">
                                <div class="x_title">
                                    <h2>人員權限設定</h2>
                                    <ul class="nav navbar-right panel_toolbox">
                                        <li><a class="collapse-link"><i class="fa fa-chevron-up"></i></a>
                                        </li>
                                        <!-- <li><a class="close-link"><i class="fa fa-close"></i></a>
                                            </li> -->
                                    </ul>
                                    <div class="clearfix"></div>
                                </div>
                                <div class="x_content">

                                    <p class="text-muted font-13 m-b-30">

                                    </p>

                                    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:8px;" id="user-filter-area">
                                        <div id="user-filter-buttons" class="btn-group">
                                            <button type="button" class="btn btn-sm btn-primary" id="btn-show-all">顯示所有員工</button>
                                            <button type="button" class="btn btn-sm btn-default" id="btn-show-unconfigured">顯示未設定權限</button>
                                        </div>
                                        <select id="dept-filter-select" class="form-control input-sm" style="width:160px;" onchange="filterPermTableByDept()">
                                            <option value="">— 所有部門 —</option>
                                            <?php foreach ($_quotDepts as $_d): ?>
                                            <option value="<?= htmlspecialchars($_d) ?>"><?= htmlspecialchars($_d) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div id="permission-legend" style="display: none;">
                                        <strong style="margin-right: 5px;">權限說明：</strong>
                                        <span class="label label-default">A: 完整</span>
                                        <span class="label label-primary">C: 新增</span>
                                        <span class="label label-success">R: 檢視</span>
                                        <span class="label label-warning">U: 修改</span>
                                        <span class="label label-danger">D: 刪除</span>
                                        <span class="text-muted" style="font-size:11px;margin-left:8px;">※ 下方已有「角色指派」的頁面僅需勾 R(開啟)，細部權限由角色指派決定</span>
                                    </div>

                                    <table id="datatable-buttons" class="table table-striped table-bordered">
                                        <thead>
                                            <tr>
                                                <?php if ($canEdit): ?>
                                                    <th class="text-center" style="white-space: nowrap; width: 80px;">設定</th>
                                                <?php endif; ?>
                                                <th style="width: 30px;">部門</th>
                                                <th style="white-space: nowrap;">姓名</th>
                                                <th>帳號</th>
                                                <?php foreach ($modules as $module): ?>
                                                    <th class="text-center" style="white-space: normal; width: 60px; vertical-align: middle;" title="<?= htmlspecialchars($module['group_name'] ?? '') ?>"><?= htmlspecialchars(!empty($module['page_name']) ? $module['page_name'] : $module['module_name']) ?></th>
                                                <?php endforeach; ?>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            $acrudMap = [
                                                'A' => '完整權限',
                                                'C' => '新增',
                                                'R' => '檢視',
                                                'U' => '修改',
                                                'D' => '刪除'
                                            ];
                                            $sortIndex = 0;
                                            foreach ($admins as $admin) {
                                                $sortIndex++;

                                                $hasAnyPerm = false;
                                                if (!empty($admin['permissions'])) {
                                                    foreach ($admin['permissions'] as $scope => $codes) {
                                                        foreach ($codes as $p) {
                                                            if ($p !== '') {
                                                                $hasAnyPerm = true;
                                                                break 2;
                                                            }
                                                        }
                                                    }
                                                }
                                            ?>
                                                <?php
                                                $allDepts2 = array_unique(array_filter(array_column($admin['roles'], 'department_name')));
                                                ?>
                                                <tr data-user-id="<?= $admin['id'] ?>"
                                                    data-has-perms="<?= $hasAnyPerm ? 'true' : 'false' ?>"
                                                    data-depts="<?= htmlspecialchars(implode('|', $allDepts2)) ?>">
                                                    <?php if ($canEdit): ?>
                                                        <td style="white-space: nowrap;" data-order="<?= $sortIndex ?>">
                                                            <!-- 更新按鈕，觸發 Modal -->
                                                            <button type="button" class="btn btn-warning btn-xs" onclick="event.stopPropagation(); $('#updateModal-<?= $admin['id'] ?>').modal('show');">
                                                                更新
                                                            </button>
                                                        </td>
                                                    <?php endif; ?>
                                                    <td style="white-space: nowrap;" data-department-cell="true" data-order="<?= $admin['dept_sort'] . '-' . $admin['pos_sort'] ?>">
                                                        <?php
                                                        if (!empty($admin['roles'])) {
                                                            foreach ($admin['roles'] as $role) {
                                                                $str = htmlspecialchars($role['department_name'] . ' - ' . $role['position_title']);
                                                                if (!$role['is_main']) $str .= ' (兼任)';
                                                                echo $str . '<br>';
                                                            }
                                                        }
                                                        ?>
                                                    </td>
                                                    <td style="width: 160px; white-space: nowrap;"><?= htmlspecialchars($admin['user_cname']) ?></td>
                                                    <td style="width: 160px"><?= htmlspecialchars($admin['user_uname']) ?></td>
                                                    <?php foreach ($modules as $module):
                                                        $currPerm = $admin['permissions']['group'][$module['module_code']] ?? '';
                                                        $displayTooltips = [];
                                                        $displayHtml = '';
                                                        foreach ($acrudMap as $key => $val) {
                                                            if (strpos($currPerm, $key) !== false) {
                                                                $displayTooltips[] = $val;

                                                                $color = 'default';
                                                                if ($key === 'C') $color = 'primary';
                                                                if ($key === 'R') $color = 'success';
                                                                if ($key === 'U') $color = 'warning';
                                                                if ($key === 'D') $color = 'danger';

                                                                $displayHtml .= '<span class="label label-' . $color . '">' . $key . '</span> ';
                                                            }
                                                        }
                                                    ?>
                                                        <td class="text-center" data-permission="<?= $module['module_code'] ?>" title="<?= htmlspecialchars(implode('、', $displayTooltips)) ?>"><?= $displayHtml ?></td>
                                                    <?php endforeach; ?>
                                                </tr>
                                            <?php
                                            }
                                            ?>
                                        </tbody>
                                    </table>
                                    <?php foreach ($admins as $admin): ?>
                                        <!-- Modal 彈出視窗 (移至表格外以避免 DataTables 事件衝突) -->
                                        <div class="modal fade" id="updateModal-<?= $admin['id'] ?>" tabindex="-1" role="dialog" aria-labelledby="updateModalLabel-<?= $admin['id'] ?>">
                                            <div class="modal-dialog" role="document">
                                                <div class="modal-content">
                                                    <form action="../../src/store/_updateUserPermissions.php" method="POST" class="permission-update-form">
                                                        <div class="modal-header">
                                                            <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                                                            <div class="pull-right" style="margin-right: 10px;">
                                                                <button type="submit" name="updatePermissions" class="btn btn-primary btn-sm">儲存</button>
                                                                <button type="button" class="btn btn-default btn-sm" data-dismiss="modal">取消</button>
                                                            </div>
                                                            <h4 class="modal-title" id="updateModalLabel-<?= $admin['id'] ?>"><?= htmlspecialchars($admin['user_cname']) ?>　/　<?= htmlspecialchars($admin['user_uname']) ?>　權限修改</h4>
                                                        </div>
                                                        <div class="modal-body">
                                                            <!-- 隱藏欄位，用於傳遞使用者ID -->
                                                            <input type="hidden" name="userid" value="<?= $admin['id'] ?>">
                                                            <input type="hidden" name="id" value="<?= $_GET['id'] ?>">

                                                            <!-- 複製權限區塊 -->
                                                            <div class="text-right" style="margin-bottom: 10px; padding-bottom: 10px; border-bottom: 1px solid #eee;">
                                                                <label style="font-weight: normal;">複製權限自：</label>
                                                                <select class="form-control input-sm copy-source-select" style="width: auto; display: inline-block; vertical-align: middle;">
                                                                    <option value="">-- 請選擇員工 --</option>
                                                                    <?php foreach ($sourceUsers as $srcUser): ?>
                                                                        <?php if ($srcUser['id'] == $admin['id']) continue; ?>
                                                                        <option value="<?= $srcUser['id'] ?>" data-perms='<?= htmlspecialchars(json_encode($srcUser['permissions']), ENT_QUOTES, 'UTF-8') ?>'><?= htmlspecialchars($srcUser['name']) ?></option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                                <button type="button" class="btn btn-sm btn-info btn-copy-perms" style="margin-bottom: 0; vertical-align: middle;">複製</button>
                                                            </div>

                                                            <div class="text-right" style="margin-bottom: 5px;">
                                                                <span style="margin-right: 5px;">全部設為:</span>
                                                                <?php foreach ($acrudMap as $k => $v): ?>
                                                                    <button type="button" class="btn btn-xs btn-default btn-bulk-check" data-val="<?= $k ?>" title="<?= $v ?>"><?= $k ?></button>
                                                                <?php endforeach; ?>
                                                                <button type="button" class="btn btn-xs btn-danger btn-bulk-clear">清空</button>
                                                            </div>
                                                            <table class="table table-bordered text-center" style="margin-bottom: 0;">
                                                                <thead>
                                                                    <tr>
                                                                        <th class="text-center">模組</th>
                                                                        <th class="text-center">權限 (ACRUD)</th>
                                                                    </tr>
                                                                </thead>
                                                                <tbody>
                                                                    <?php
                                                                    foreach ($modules as $module):
                                                                        $mCode = trim($module['module_code']);
                                                                        $mGroupId = isset($module['group_id']) ? trim($module['group_id']) : '';
                                                                        // 取得該模組代碼下的所有子頁面
                                                                        $modulePages = $pages_by_module[$mCode] ?? [];
                                                                        
                                                                        // 取得該群組下的所有子頁面 (合併 group_id 對應的頁面)
                                                                        if ($mGroupId !== '' && isset($pages_by_group[$mGroupId])) {
                                                                            foreach ($pages_by_group[$mGroupId] as $gp) {
                                                                                $exists = false;
                                                                                foreach ($modulePages as $mp) {
                                                                                    if ($mp['page_id'] == $gp['page_id']) {
                                                                                        $exists = true;
                                                                                        break;
                                                                                    }
                                                                                }
                                                                                if (!$exists) {
                                                                                    $modulePages[] = $gp;
                                                                                }
                                                                            }
                                                                        }

                                                                        // 如果模組本身有連結到主頁面 (page_id)，也將其加入子頁面列表 (若尚未存在)
                                                                        if (!empty($module['page_id']) && isset($pages_by_id[$module['page_id']])) {
                                                                            $mainPage = $pages_by_id[$module['page_id']];
                                                                            $exists = false;
                                                                            foreach ($modulePages as $mp) {
                                                                                if ($mp['page_id'] == $mainPage['page_id']) {
                                                                                    $exists = true;
                                                                                    break;
                                                                                }
                                                                            }
                                                                            if (!$exists) {
                                                                                array_unshift($modulePages, $mainPage);
                                                                            }
                                                                        }

                                                                        $currPerm = $admin['permissions']['group'][$mCode] ?? '';
                                                                    ?>
                                                                        <tr>
                                                                            <td style="vertical-align: middle;">
                                                                                <?php if (!empty($modulePages)): ?>
                                                                                    <button type="button" class="btn btn-xs btn-default toggle-pages" style="margin-right: 5px;" data-module="<?= htmlspecialchars($mCode) ?>" onclick="toggleModulePages(this)">
                                                                                        <i class="fa fa-plus-square-o"></i>
                                                                                    </button>
                                                                                <?php endif; ?>
                                                                                <?php if (!empty($module['group_name'])): ?>
                                                                                    <span class="text-muted" style="font-size: 0.85em;">[<?= htmlspecialchars($module['group_name']) ?>]</span>
                                                                                <?php endif; ?>
                                                                                <strong><?= htmlspecialchars($module['module_name']) ?></strong>
                                                                            </td>
                                                                            <td>
                                                                                <?php
                                                                                $isRbacManagedModule = in_array($mCode, $rbacManagedModuleCodes, true)
                                                                                    || (!empty($module['page_id']) && in_array((int)$module['page_id'], $rbacManagedPageIds, true));
                                                                                if ($isRbacManagedModule): ?>
                                                                                    <label class="checkbox-inline">
                                                                                        <input type="checkbox" name="permissions[group][<?= $mCode ?>][]" value="R" <?= (strpos($currPerm, 'R') !== false || strpos($currPerm, 'A') !== false) ? 'checked' : '' ?>> R 開啟
                                                                                    </label>
                                                                                    <div class="text-muted" style="font-size:11px;">細部權限由下方「角色指派」設定</div>
                                                                                <?php else: ?>
                                                                                <?php foreach ($acrudMap as $char => $label):
                                                                                    // 針對 hr_permissions 模組，只顯示 A, R, U
                                                                                    if (($mCode === 'hr_permissions' || $module['module_name'] === 'hr_permissions') && !in_array($char, ['A', 'R', 'U'])) continue;
                                                                                ?>
                                                                                    <label class="checkbox-inline">
                                                                                        <input type="checkbox" name="permissions[group][<?= $mCode ?>][]" value="<?= $char ?>" <?= strpos($currPerm, $char) !== false ? 'checked' : '' ?>> <?= $char . ' ' . $label ?>
                                                                                    </label>
                                                                                <?php endforeach; ?>
                                                                                <?php endif; ?>
                                                                            </td>
                                                                        </tr>
                                                                        <?php if (!empty($modulePages)): ?>
                                                                            <?php foreach ($modulePages as $page): 
                                                                                $pId = $page['page_id'];
                                                                                $currPagePerm = $admin['permissions']['page'][$pId] ?? '';
                                                                            ?>
                                                                            <tr class="page-row-<?= $mCode ?>" data-parent-module="<?= htmlspecialchars($mCode) ?>" style="display: none; background-color: #f9f9f9;">
                                                                                <td style="vertical-align: middle; padding-left: 40px; text-align: left;">
                                                                                    <i class="fa fa-angle-right"></i> <?= htmlspecialchars($page['page_name']) ?>
                                                                                </td>
                                                                                <td>
                                                                                    <?php if (in_array((int)$pId, $rbacManagedPageIds, true)): ?>
                                                                                        <label class="checkbox-inline">
                                                                                            <input type="checkbox" name="permissions[page][<?= $pId ?>][]" value="R" <?= (strpos($currPagePerm, 'R') !== false || strpos($currPagePerm, 'A') !== false) ? 'checked' : '' ?>> R 開啟
                                                                                        </label>
                                                                                        <span class="text-muted" style="font-size:11px;">（細部權限由下方「角色指派」設定）</span>
                                                                                    <?php else: ?>
                                                                                    <?php foreach ($acrudMap as $char => $label): ?>
                                                                                        <label class="checkbox-inline">
                                                                                            <input type="checkbox" name="permissions[page][<?= $pId ?>][]" value="<?= $char ?>" <?= strpos($currPagePerm, $char) !== false ? 'checked' : '' ?>> <?= $char . ' ' . $label ?>
                                                                                        </label>
                                                                                    <?php endforeach; ?>
                                                                                    <?php endif; ?>
                                                                                </td>
                                                                            </tr>
                                                                            <?php endforeach; ?>
                                                                        <?php endif; ?>
                                                                    <?php endforeach; ?>
                                                                </tbody>
                                                            </table>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ══ 人員權限總覽（依人員查看）══════════════════════════════════ -->
                    <div class="row" style="margin-top:20px;" id="person-view-section">
                        <div class="col-md-12">
                            <div class="x_panel">
                                <div class="x_title">
                                    <h2><i class="fa fa-user-circle-o" style="color:#B5762A;margin-right:7px;"></i>人員權限總覽 <small>選一個人，看他的權限從哪裡來</small></h2>
                                    <ul class="nav navbar-right panel_toolbox"><li><a class="collapse-link"><i class="fa fa-chevron-up"></i></a></li></ul>
                                    <div class="clearfix"></div>
                                </div>
                                <div class="x_content">
                                    <div style="font-size:12px;color:#8a5a2b;background:#FFF9F0;border:1px solid #F0E2CC;border-radius:3px;padding:8px 10px;margin-bottom:12px;line-height:1.7;">
                                        <i class="fa fa-info-circle"></i>
                                        上面的區塊是「一個模組一張表」，要看<strong>某一個人到底有哪些權限、又是哪裡來的</strong>就得一張張翻。這裡反過來以人為單位查。<br>
                                        ・一個人可能有<strong>多個「部門＋職稱」身分</strong>（主要職務＋兼任），每個身分各自帶到不同的角色，所以下面是依身分分開列。<br>
                                        ・<strong>代理是掛在職稱身分上的</strong>：請假設定的代理人若走「完整承接權限」的假別，會在生效期間借到被代理職稱的角色，這裡也一併顯示。<br>
                                        ・「生效權限」欄的<span class="label label-primary" style="font-size:11px;padding:2px 5px;">個人</span>表示該模組有個人指派、覆蓋掉部門職稱設定；<span class="label label-success" style="font-size:11px;padding:2px 5px;">部門職稱</span>表示由編制自動帶入。
                                    </div>

                                    <div style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap;align-items:center;">
                                        <label style="margin:0;font-weight:600;font-size:13px;">選擇人員：</label>
                                        <select id="pv-user" class="form-control input-sm" style="width:280px;" onchange="pvLoad()" data-eg-filter="輸入姓名或帳號篩選…">
                                            <option value="">— 請選擇 —</option>
                                            <?php foreach ($admins as $_a):
                                                $_dp = [];
                                                foreach ($_a['roles'] as $_r) { if (!empty($_r['department_name'])) $_dp[] = $_r['department_name'].' '.$_r['position_title']; }
                                            ?>
                                            <option value="<?= (int)$_a['id'] ?>"><?= htmlspecialchars($_a['user_cname'].'（'.$_a['user_uname'].'）'.($_dp ? ' － '.implode('／', $_dp) : '')) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <span id="pv-loading" style="display:none;font-size:12px;color:#888;"><i class="fa fa-spinner fa-spin"></i> 載入中…</span>
                                    </div>

                                    <div id="pv-result" style="display:none;"></div>
                                    <div id="pv-empty" class="text-muted" style="font-size:12px;padding:10px;">尚未選擇人員。</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- ══ ／人員權限總覽 ══ -->
                    <!-- ══ 部門 × 職稱 角色設定 ══════════════════════════════════════ -->
                    <?php
                    // 版面（2026-08-27 重做，使用者回報「不夠直觀、很不一目了然」）：
                    //   ① 依部門分組，部門標題列可摺疊，一眼看得出哪個部門設好了、哪個還沒
                    //   ② 「全部門通用」是進階用法，移到最後獨立收合，不再霸佔畫面最上方
                    //   ③ 每列不再各放一個 114 選項的下拉（46 個下拉＝5000 多個 option，是本頁肥大的主因之一），
                    //      改成「＋」按鈕勾選該列，角色一律在底部操作列挑，互動只有一種
                    //   ④ 批次操作列改成「勾了才浮出」的固定底部列，平常完全不佔版面
                    // 「全部門通用」列（department_id=0）：套用到所有部門的該職稱。
                    //   每個「目前有人在用的職稱」都給一列，否則這一層只看得到、沒辦法新增。
                    // 只列出「目前有在職人員」的職稱。沒有在職人員的職稱一律不列——
                    //   超級管理員（IT 最高權限、由網管固定持有，帳號 state=99 不算在職）因此不會出現在這張表，
                    //   使用者明確要求不要列入；其 position_roles 設定保持不動，只是不從這裡顯示與操作。
                    $_dpList = []; $_seenPos = [];
                    foreach ($_dpRows as $_r) {
                        $_pid = (int)$_r['position_id'];
                        if (isset($_seenPos[$_pid])) continue;
                        $_seenPos[$_pid] = true;
                        $_cnt = 0;
                        foreach ($_dpRows as $_r2) { if ((int)$_r2['position_id'] === $_pid) $_cnt += (int)$_r2['people']; }
                        $_dpList[] = ['department_id'=>0, 'department_name'=>'（全部門通用）', 'position_id'=>$_pid,
                                      'position_name'=>$_r['position_name'], 'people'=>$_cnt,
                                      'people_names'=>'所有部門的「'.$_r['position_name'].'」共 '.$_cnt.' 人'];
                    }
                    foreach ($_dpRows as $_r) $_dpList[] = $_r;

                    $_dpGroups = [];   // [部門名 => ['id'=>, 'rows'=>[], 'people'=>, 'set'=>]]
                    $_dpAnyDept = [];  // 全部門通用（department_id = 0）
                    $_dpSetCnt = 0;
                    foreach ($_dpList as $_row) {
                        $_k = (int)$_row['department_id'] . '_' . (int)$_row['position_id'];
                        $_row['_key'] = $_k;
                        $_row['_roles'] = $_dpRoles[$_k] ?? [];
                        if (!empty($_row['_roles'])) $_dpSetCnt++;
                        if ((int)$_row['department_id'] === 0) { $_dpAnyDept[] = $_row; continue; }
                        $_dn = $_row['department_name'];
                        if (!isset($_dpGroups[$_dn])) $_dpGroups[$_dn] = ['id'=>(int)$_row['department_id'], 'rows'=>[], 'people'=>0, 'set'=>0];
                        $_dpGroups[$_dn]['rows'][] = $_row;
                        $_dpGroups[$_dn]['people'] += (int)$_row['people'];
                        if (!empty($_row['_roles'])) $_dpGroups[$_dn]['set']++;
                    }
                    $_dpTotal = count($_dpList);

                    // 一列（部門×職稱 或 全部門通用）共用的渲染
                    $_dpRenderRow = function($r, $canEdit, $isAny = false) {
                        $k = $r['_key'];
                    ?>
                        <tr class="dp-row" data-key="<?= $k ?>"
                            data-search="<?= htmlspecialchars(mb_strtolower($r['department_name'].$r['position_name'].($r['people_names'] ?? ''), 'UTF-8')) ?>"
                            data-hasrole="<?= empty($r['_roles']) ? 0 : 1 ?>">
                            <?php if ($canEdit): ?>
                            <td class="text-center" style="width:34px;"><input type="checkbox" class="dp-chk" value="<?= $k ?>" onclick="dpUpdateCount()"></td>
                            <?php endif; ?>
                            <td style="width:150px;<?= $isAny ? '' : 'padding-left:26px;' ?>">
                                <?php if ($isAny): ?>
                                    <span style="color:#8a5a2b;"><i class="fa fa-globe"></i> 所有部門的</span>
                                <?php endif; ?>
                                <strong><?= htmlspecialchars($r['position_name']) ?></strong>
                            </td>
                            <td style="width:70px;" class="text-center" title="<?= htmlspecialchars($r['people_names'] ?? '') ?>">
                                <span style="color:#888;font-size:12px;"><?= (int)$r['people'] ?> 人</span>
                            </td>
                            <td id="dp-<?= $k ?>-tags">
                                <?php if (empty($r['_roles'])): ?>
                                    <span style="color:#ccc;font-size:12px;">—</span>
                                <?php else: foreach ($r['_roles'] as $_ar): ?>
                                    <span class="label <?= empty($_ar['orphan']) ? 'label-primary' : 'label-default' ?>" style="margin:0 4px 3px 0;font-size:12px;padding:3px 7px;display:inline-block;" <?= empty($_ar['orphan']) ? '' : 'title="這筆設定指到一個已經不存在的角色，實際上不帶任何權限；此處刻意不提供移除"' ?>>
                                        <?php if ($_ar['module'] !== ''): ?><span style="opacity:.7;font-size:11px;"><?= htmlspecialchars(eg_module_label($_ar['module'])) ?></span> <?php endif; ?>
                                        <?= htmlspecialchars($_ar['role_name']) ?>
                                        <?php if ($canEdit && empty($_ar['orphan'])): ?>
                                            <a href="#" onclick="dpRoleRemove(<?= (int)$r['department_id'] ?>,<?= (int)$r['position_id'] ?>,<?= (int)$_ar['role_id'] ?>);return false;" style="color:#fff;margin-left:4px;opacity:.8;" title="移除">&times;</a>
                                        <?php endif; ?>
                                    </span>
                                <?php endforeach; endif; ?>
                            </td>
                            <?php if ($canEdit): ?>
                            <td style="width:90px;" class="text-right">
                                <button type="button" class="btn btn-xs btn-default" onclick="dpQuickAdd('<?= $k ?>')" title="勾選這一列，到下方操作列選角色"><i class="fa fa-plus"></i> 加角色</button>
                            </td>
                            <?php endif; ?>
                        </tr>
                    <?php };
                    ?>
                    <div class="row" style="margin-top:20px;" id="dp-role-section">
                        <div class="col-md-12">
                            <div class="x_panel">
                                <div class="x_title">
                                    <h2><i class="fa fa-sitemap" style="color:#B5762A;margin-right:7px;"></i>部門 × 職稱 角色設定
                                        <small>設定一次，該編制的人自動具備</small></h2>
                                    <ul class="nav navbar-right panel_toolbox"><li><a class="collapse-link"><i class="fa fa-chevron-up"></i></a></li></ul>
                                    <div class="clearfix"></div>
                                </div>
                                <div class="x_content">

                                    <!-- 一行摘要＋說明收合（說明預設收起，不再一開始就塞六行字） -->
                                    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px;">
                                        <span style="font-size:13px;">
                                            <strong style="color:#B5762A;font-size:16px;"><?= $_dpSetCnt ?></strong>
                                            <span style="color:#888;">/ <?= $_dpTotal ?> 組編制已設定角色</span>
                                        </span>
                                        <a href="#" onclick="$('#dp-help').slideToggle();return false;" style="font-size:12px;">
                                            <i class="fa fa-question-circle"></i> 這是什麼？優先序怎麼算？
                                        </a>
                                    </div>
                                    <div id="dp-help" style="display:none;font-size:12px;color:#8a5a2b;background:#FFF9F0;border:1px solid #F0E2CC;border-radius:3px;padding:8px 10px;margin-bottom:12px;line-height:1.8;">
                                        以「<strong>部門＋職稱</strong>」為單位設定角色，該編制底下的<strong>在職人員自動取得</strong>，新人到職掛上職務就有，不必逐人再設一次。<br>
                                        ・<strong>優先序：個人指派 &gt; 部門職稱</strong>，而且是<strong>逐模組</strong>判斷——某人自己被指派了報價單角色，不會因此失去這裡帶來的訂單追蹤角色。<br>
                                        ・所以某個人要「例外處理」時，只要在下面各模組區塊單獨指派他該模組的角色即可。<br>
                                        ・<strong>職稱一定要連部門一起看</strong>：「組員」橫跨 7 個部門、「組長」橫跨 7 個部門，只綁職稱會讓品管組員拿到業務組員的權限。<br>
                                        ・<strong>系統角色「管理員」不出現在這裡</strong>（整個職稱變全域管理員風險太大），請到下面各區塊個別指派。<br>
                                        ・離職／留停者一律不套用；請假「完整承接權限」的代理人會另外借到被代理職稱的角色。
                                    </div>

                                    <div style="display:flex;gap:8px;margin-bottom:10px;flex-wrap:wrap;align-items:center;">
                                        <div class="input-group input-group-sm" style="width:230px;">
                                            <span class="input-group-addon"><i class="fa fa-search"></i></span>
                                            <input type="text" id="dp-search" class="form-control" placeholder="搜尋部門 / 職稱 / 人名" oninput="dpFilter()">
                                        </div>
                                        <label style="font-weight:normal;line-height:30px;font-size:12px;margin:0;">
                                            <input type="checkbox" id="dp-only-set" onchange="dpFilter()"> 只看已設定的
                                        </label>
                                        <button type="button" class="btn btn-xs btn-default" onclick="dpExpandAll(true)"><i class="fa fa-angle-double-down"></i> 全部展開</button>
                                        <button type="button" class="btn btn-xs btn-default" onclick="dpExpandAll(false)"><i class="fa fa-angle-double-up"></i> 全部收合</button>
                                        <span id="dp-filter-count" class="text-muted" style="line-height:30px;font-size:12px;"></span>
                                    </div>

                                    <table class="table table-condensed" id="dp-role-table" style="font-size:13px;margin-bottom:6px;">
                                        <tbody id="dp-role-tbody">
<?php foreach ($_dpGroups as $_dn => $_g):
    $_gid = 'dpg-' . $_g['id'];
?>
                                            <tr class="dp-group" data-group="<?= $_gid ?>" style="background:#F5EFE6;cursor:pointer;" onclick="dpToggleGroup('<?= $_gid ?>')">
                                                <?php if ($canEdit): ?>
                                                <td class="text-center" style="width:34px;" onclick="event.stopPropagation();">
                                                    <input type="checkbox" class="dp-group-chk" data-group="<?= $_gid ?>" onclick="dpToggleGroupChk(this)" title="勾選此部門全部職稱">
                                                </td>
                                                <?php endif; ?>
                                                <td colspan="<?= $canEdit ? 3 : 3 ?>" style="font-weight:600;color:#5a4326;">
                                                    <i class="fa fa-caret-down dp-caret" data-group="<?= $_gid ?>" style="width:12px;"></i>
                                                    <?= htmlspecialchars($_dn) ?>
                                                    <span style="font-weight:normal;color:#999;font-size:12px;margin-left:6px;">
                                                        <?= count($_g['rows']) ?> 個職稱・<?= $_g['people'] ?> 人
                                                    </span>
                                                </td>
                                                <?php if ($canEdit): ?>
                                                <td class="text-right" style="width:90px;">
                                                    <?php if ($_g['set'] > 0): ?>
                                                        <span class="label label-success" style="font-size:11px;"><?= $_g['set'] ?>/<?= count($_g['rows']) ?> 已設定</span>
                                                    <?php else: ?>
                                                        <span style="color:#bbb;font-size:11px;">未設定</span>
                                                    <?php endif; ?>
                                                </td>
                                                <?php endif; ?>
                                            </tr>
                                            <?php foreach ($_g['rows'] as $_row): $_row['_grp'] = $_gid; ?>
                                                <?php ob_start(); $_dpRenderRow($_row, $canEdit, false); $_h = ob_get_clean();
                                                      echo str_replace('class="dp-row"', 'class="dp-row" data-group="'.$_gid.'"', $_h); ?>
                                            <?php endforeach; ?>
<?php endforeach; ?>
                                        </tbody>
                                    </table>

                                    <!-- 進階：不分部門的職稱設定（預設收合，因為多數情況用不到） -->
                                    <div style="border-top:1px dashed #ddd;padding-top:8px;">
                                        <a href="#" onclick="$('#dp-anydept-wrap').slideToggle();$(this).find('.fa-caret-right,.fa-caret-down').toggleClass('fa-caret-right fa-caret-down');return false;" style="font-size:12px;color:#8a5a2b;">
                                            <i class="fa fa-caret-right"></i> 進階：不分部門的職稱設定（<?= count($_dpAnyDept) ?>）
                                        </a>
                                        <span class="text-muted" style="font-size:11px;margin-left:6px;">套用到<strong>所有部門</strong>的同名職稱，例如「所有部門的課長」。一般情況請用上面的部門分組設定。</span>
                                        <div id="dp-anydept-wrap" style="display:none;margin-top:8px;">
                                            <table class="table table-condensed" style="font-size:13px;">
                                                <tbody id="dp-anydept-tbody">
                                                <?php foreach ($_dpAnyDept as $_row) { $_dpRenderRow($_row, $canEdit, true); } ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <?php if ($canEdit): ?>
                    <!-- 勾選後才浮出的底部操作列：平常完全不佔版面 -->
                    <div id="dp-actionbar" style="display:none;position:fixed;left:0;right:0;bottom:0;z-index:1040;background:#2A3F54;color:#fff;box-shadow:0 -2px 10px rgba(0,0,0,.25);padding:10px 16px;">
                        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                            <span style="font-weight:600;white-space:nowrap;">
                                <i class="fa fa-check-square-o"></i> 已選 <span id="dp-sel-count">0</span> 組編制
                            </span>
                            <button class="btn btn-xs btn-default" type="button" onclick="dpClearSel()">取消選取</button>
                            <span style="opacity:.4;">｜</span>

                            <select id="dp-bulk-role" class="form-control input-sm" style="width:280px;" data-eg-filter="輸入模組或角色名稱篩選…">
                                <option value="">— 選擇角色 —</option>
                                <?php foreach ($_rolesByModule as $_m => $_rs): ?>
                                <optgroup label="<?= htmlspecialchars(eg_module_label($_m)) ?>">
                                    <?php foreach ($_rs as $_r2): ?>
                                    <option value="<?= (int)$_r2['role_id'] ?>"><?= htmlspecialchars(eg_module_label($_m) . '｜' . $_r2['role_name']) ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn btn-sm btn-success" type="button" onclick="dpBulk('assign')"><i class="fa fa-plus"></i> 指派</button>
                            <button class="btn btn-sm btn-default" type="button" onclick="dpBulk('remove')"><i class="fa fa-minus"></i> 移除</button>

                            <span style="opacity:.4;">｜</span>
                            <span style="white-space:nowrap;">複製自</span>
                            <select id="dp-copy-type" class="form-control input-sm" style="width:100px;" onchange="dpCopyTypeChange()">
                                <option value="user">人員</option>
                                <option value="position">編制</option>
                            </select>
                            <select id="dp-copy-user" class="form-control input-sm" style="width:230px;" data-eg-filter="輸入姓名或帳號篩選…">
                                <option value="">— 來源人員 —</option>
                                <?php foreach ($admins as $_a):
                                    $_dp2 = [];
                                    foreach ($_a['roles'] as $_r3) { if (!empty($_r3['department_name'])) $_dp2[] = $_r3['department_name'].' '.$_r3['position_title']; }
                                ?>
                                <option value="<?= (int)$_a['id'] ?>"><?= htmlspecialchars($_a['user_cname'].($_dp2 ? '（'.implode('／', $_dp2).'）' : '')) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select id="dp-copy-pos" class="form-control input-sm" style="width:230px;display:none;" data-eg-filter="輸入部門或職稱篩選…">
                                <option value="">— 來源編制 —</option>
                                <?php foreach ($_dpList as $_row2): ?>
                                <option value="<?= (int)$_row2['department_id'] . '_' . (int)$_row2['position_id'] ?>"><?= htmlspecialchars($_row2['department_name'] . ' ' . $_row2['position_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select id="dp-copy-mode" class="form-control input-sm" style="width:150px;">
                                <option value="merge">合併保留原有</option>
                                <option value="overwrite">覆蓋清空原有</option>
                            </select>
                            <button class="btn btn-sm btn-warning" type="button" onclick="dpBulk('copy')"><i class="fa fa-clone"></i> 複製過去</button>
                        </div>
                    </div>
                    <?php endif; ?>
                    <!-- ══ ／部門 × 職稱 角色設定 ══ -->
                    <!-- ══ 角色指派（依模組分開）══ -->
                    <?php
                    eg_render_role_section('quot', 'quotation', '報價單管理', 'fa-file-text-o', '#3498db',
                        '為每位使用者指派報價單管理頁面的操作角色。角色與功能定義請至 <strong>報價單管理 → 報價單設定（齒輪圖示）→ 權限設定</strong>。',
                        rs_of('quotation'), rsu_of('quotation'), $admins, $_quotDepts, $canEdit);

                    eg_render_role_section('notice', 'notice', '公告 / 通知管理', 'fa-bullhorn', '#1ABB9C',
                        '為每位使用者指派公告/通知頁面的操作角色。角色與功能定義請至 <strong>公告 / 通知管理 → 權限設定</strong>。',
                        rs_of('notice'), rsu_of('notice'), $admins, $_quotDepts, $canEdit);

                    eg_render_role_section('home', 'homepage', '首頁設定', 'fa-home', '#e67e22',
                        '為每位使用者指派首頁設定頁面的操作角色。角色與功能定義請至 <strong>首頁設定 → 權限設定</strong>。',
                        rs_of('homepage'), rsu_of('homepage'), $admins, $_quotDepts, $canEdit);

                    eg_render_role_section('qc', 'qc', '品管檢驗（QC）', 'fa-check-square-o', '#9b59b6',
                        '為每位使用者指派品管檢驗頁面的操作角色（填寫檢驗表單、修改/開放檢驗歷程、回覆異常處置）。角色與功能定義請至 <strong>品管檢驗 → 設定（齒輪圖示）→ 權限設定</strong>。',
                        rs_of('qc'), rsu_of('qc'), $admins, $_quotDepts, $canEdit);

                    eg_render_role_section('car', 'car', '異常矯正處理單', 'fa-wrench', '#16a085',
                        '為每位使用者指派異常矯正處理單頁面的操作角色（檢閱、開立、修改、刪除、管理設定）。角色與功能定義請至 <strong>異常矯正處理單 → 設定（齒輪圖示）→ 權限設定（角色）</strong>。',
                        rs_of('car'), rsu_of('car'), $admins, $_quotDepts, $canEdit);

                    eg_render_role_section('mdata', 'master_data', '主檔管理（附件 / 圖面查閱）', 'fa-database', '#d4761a',
                        '為每位使用者指派主檔管理頁「其他附件」分頁的操作角色（檢視、上傳、刪除、編輯標籤/浮水印）。「報價資料」分頁沿用報價單「檢視」權限（quotation_view）；「圖面查閱」對所有登入者開放。<strong>過渡期：尚未指派 master_data 角色前，暫時沿用主檔管理頁原有附件權限，不會鎖住任何人；一旦指派了第一位，未被指派者即改以角色為準。</strong>角色與功能定義請至 <strong>主檔管理頁 → 角色設定（僅管理員可見）</strong>。管理者固定可用。',
                        rs_of('master_data'), rsu_of('master_data'), $admins, $_quotDepts, $canEdit);

                    eg_render_role_section('imgedit', 'imgedit', '批圖編輯器', 'fa-paint-brush', '#ab47bc',
                        '為每位使用者指派「批圖使用者」角色（批圖編輯器：訂單追蹤頁「批圖」按鈕開啟的圖面編輯跳窗）。<strong>尚未指派任何人之前，暫時開放所有登入者使用；一旦指派了第一位，未被指派者即無法開啟。</strong>管理者固定可用。',
                        rs_of('imgedit'), rsu_of('imgedit'), $admins, $_quotDepts, $canEdit);

                    eg_render_role_section('rvf', 'review_form', '審核表單', 'fa-clipboard', '#c0782d',
                        '為每位使用者指派審核表單引擎的操作角色（檢閱、檢視全部人員表單、建立/填寫/送出、列印、模板管理）。模板管理僅供設定 AS 文件綁定/審核核准流程；項次內容維護權限則另在「審核表單模板管理」頁依維護部門/維護人員指派，不透過此處角色。角色與功能定義請至 <strong>審核表單模板管理 → 使用說明</strong>。',
                        rs_of('review_form'), rsu_of('review_form'), $admins, $_quotDepts, $canEdit);

                    eg_render_role_section('hrf', 'hr_form', '人資職務表單', 'fa-id-card', '#a0662e',
                        '為每位使用者指派人資職務表單（職務說明書／專業技能鑑定考核表／職能鑑定表）的操作角色（檢閱、檢視全部人員表單、建立/批次建立/複製/編輯、列印、範本管理）。確認人（該員工直屬主管）／核准人（總經理）為固定角色，不透過此處角色指派，由系統依組織架構自動解析。角色與功能定義請至 <strong>人資職務表單設定 → 使用說明</strong>。',
                        rs_of('hr_form'), rsu_of('hr_form'), $admins, $_quotDepts, $canEdit);

                    eg_render_role_section('fsd', 'form_signer', '表單簽核設計器', 'fa-object-group', '#7a5217',
                        '為每位使用者指派表單簽核設計器的操作角色（檢閱、檢視全部人員案件、建立/送出案件、列印、樣板管理）。各階段的簽核槽位（意見成員/決策者）由管理員在「樣板管理」逐樣板設定，不透過此處角色指派。角色與功能定義請至 <strong>表單簽核設計器 - 樣板管理 → 使用說明</strong>。',
                        rs_of('form_signer'), rsu_of('form_signer'), $admins, $_quotDepts, $canEdit);

                    eg_render_role_section('drawren', 'drawing_rename', '圖面自動改檔名工具', 'fa-file-image-o', '#2c81ba',
                        '為每位使用者指派圖面自動改檔名工具的操作角色（檢閱、執行改檔名、管理資料夾與前後綴設定）。角色與功能定義請至 <strong>圖面自動改檔名工具</strong> 頁面內查看「權限說明」。',
                        rs_of('drawing_rename'), rsu_of('drawing_rename'), $admins, $_quotDepts, $canEdit);

                    eg_render_role_section('bomren', 'bom_rename', '叫料文件自動改檔名工具', 'fa-file-archive-o', '#8e44ad',
                        '為每位使用者指派叫料文件（BOM）自動改檔名工具的操作角色（檢閱/掃描、核對確認並產生檔案、管理資料夾與OCR設定）。角色與功能定義請至 <strong>叫料文件自動改檔名工具</strong> 頁面內查看「權限說明」。',
                        rs_of('bom_rename'), rsu_of('bom_rename'), $admins, $_quotDepts, $canEdit);

                    eg_render_role_section('oready', 'oready', '生管 BOM 狀態管理', 'fa-industry', '#e67e22',
                        '為每位使用者指派 BOM 狀態頁（回廠標記、拆批/合併、移轉、人工結案等）的操作角色。角色與功能定義請至 <strong>生管 BOM 狀態頁右上角「角色功能設定」（僅管理員可見）</strong>。',
                        rs_of('oready'), rsu_of('oready'), $admins, $_quotDepts, $canEdit);

                    eg_render_role_section('bomtrk', 'bom_track', 'BOM 追蹤', 'fa-crosshairs', '#8e44ad',
                        '為每位使用者指派 BOM 追蹤功能的使用權限。此功能不分細部操作，只要指派角色即可使用。',
                        rs_of('bom_track'), rsu_of('bom_track'), $admins, $_quotDepts, $canEdit);

                    eg_render_role_section('profit', 'order_profit', '訂單毛利分析', 'fa-line-chart', '#c0392b',
                        '為每位使用者指派「訂單毛利分析」頁的檢視資格。<strong>毛利屬敏感資料</strong>，未被指派角色者無法開啟本頁；此功能不分細部操作。管理者固定可用。',
                        rs_of('order_profit'), rsu_of('order_profit'), $admins, $_quotDepts, $canEdit);

                    eg_render_role_section('ppr', 'part_process_report', '料號製程履歷報告', 'fa-file-text-o', '#c0392b',
                        '為每位使用者指派「料號製程履歷報告」頁的使用資格。<strong>整頁單一權限</strong>（含成本毛利，不分層），未被指派角色者無法開啟本頁。管理者固定可用。',
                        rs_of('part_process_report'), rsu_of('part_process_report'), $admins, $_quotDepts, $canEdit);

                    eg_render_role_section('stamp', 'stamp', '圖章管理', 'fa-certificate', '#c0762c',
                        '圖章管理頁角色：「圖章檢閱」＝唯讀（檢閱清冊/匯出）；「圖章管理員」＝登記核發（個人章/部門章）、種類管理、掃描實體章上傳。<strong>未被指派任何角色者看不到清冊內容</strong>（避免圖章被瀏覽轉存惡意複製）；簽核單據上的印章顯示不受此限。管理者固定可管理。',
                        rs_of('stamp'), rsu_of('stamp'), $admins, $_quotDepts, $canEdit);

                    eg_render_role_section('kpi', 'kpi', 'KPI 關鍵績效指標', 'fa-tachometer', '#c0762c',
                        'KPI 總覽頁角色：「KPI檢閱」＝檢視總覽/趨勢圖/附件；「KPI填報」＝檢閱＋重算自動指標、擔當者填寫本人負責的手動指標與上傳佐證；「KPI管理員」＝填報＋手動覆寫(需原因)、舊年度重算、KPI設定頁(指標/公式/目標/權限規則/NAS路徑)。此處指派與 KPI 設定頁的「部門×主管階級/指定人員」規則<strong>為聯集</strong>；管理者固定全權。',
                        rs_of('kpi'), rsu_of('kpi'), $admins, $_quotDepts, $canEdit);

                    eg_render_role_section('ptask', 'personal_task', '個人工作紀錄', 'fa-sticky-note-o', '#27ae60',
                        '為每位使用者指派「個人工作紀錄」功能的使用資格。此功能不分細部操作；每人只看得到自己建立的紀錄（含管理者也看不到他人內容）。',
                        rs_of('personal_task'), rsu_of('personal_task'), $admins, $_quotDepts, $canEdit);

                    eg_render_role_section('roster', 'roster', '輪值排班表', 'fa-calendar-check-o', '#c0762c',
                        '通用輪值排班（掃地/值日/現場班別皆共用）角色：「排班唯讀」＝只能檢閱自己建立或被設為公開對象的表；「排班一般使用者」＝可建立/編輯/刪除自己的排班表；「排班管理者」＝可檢視所有表、代他人補簽、對任何表調班。值勤本人可對自己的班別簽核；公開對象名單內的人才看得到該表。管理者固定全權。',
                        rs_of('roster'), rsu_of('roster'), $admins, $_quotDepts, $canEdit);

                    eg_render_role_section('asdoc', 'as_doc', 'AS9100 文件管理（個人指派，優先於職稱）', 'fa-folder-open-o', '#c0392b',
                        '為使用者「個人」指派 AS 文件管理角色——<strong>個人有指派時以個人為準（覆蓋職稱）</strong>；未指派者自動套用下方「職稱權限」的設定。角色定義（名稱與功能勾選）請至 <strong>AS9100 文件管理頁 → 角色設定</strong>。<br><strong>本區角色同時套用到「AS流程說明手冊」頁</strong>（各課室流程/表單說明＋待處理問題清單，唯讀）：有 <code>asdoc_view</code>（或該頁 ACRUD 的 A／R）即可檢視，管理者固定可看。',
                        rs_of('as_doc'), rsu_of('as_doc'), $admins, $_quotDepts, $canEdit);

                    eg_render_role_section('dbbk', 'db_backup', '資料庫備份管理', 'fa-database', '#b06f27',
                        '為每位使用者指派「資料庫備份管理」頁的操作角色（檢視/下載、立即備份、整表還原）。<strong>未被指派角色者無法進入本頁</strong>；整庫還原、備份設定與還原密碼一律僅限管理員；整表/部分還原另需輸入管理員設定的還原密碼。角色與功能定義請至 <strong>資料庫備份管理頁 → 角色權限（僅管理員可見）</strong>。',
                        rs_of('db_backup'), rsu_of('db_backup'), $admins, $_quotDepts, $canEdit);

                    eg_render_role_section('dc', 'data_console', '資料急救台', 'fa-medkit', '#b53c26',
                        '為每位使用者指派「資料急救台」頁的操作角色。此頁可直接查改後端資料庫，請謹慎授權。角色功能：<strong>data_console_view</strong>＝進入/瀏覽/搜尋/查詢；<strong>data_console_edit</strong>＝新增/修改（仍受各表「允許編輯」限制）；<strong>data_console_delete</strong>＝刪除（仍受各表「允許刪除」限制且需二次確認）。<strong>未被指派角色者無法進入本頁</strong>；表級開放設定與關聯地圖一律僅限管理員；管理員固定擁有全部權限。',
                        rs_of('data_console'), rsu_of('data_console'), $admins, $_quotDepts, $canEdit);

                    eg_render_role_section('tcal', 'tool_calib', '量測儀器校驗管理', 'fa-thermometer-half', '#b06f27',
                        '為每位使用者指派「量測儀器校驗管理」頁的操作角色（KPI #18 量測儀器按時校驗率的來源頁）。角色功能：<strong>校驗唯讀</strong>＝檢視儀器清單/校驗歷史/統計與匯出；<strong>校驗登錄</strong>＝唯讀＋登錄各儀器校驗完成紀錄；<strong>校驗管理員</strong>＝登錄＋新增儀器、設定週期/納管/基準到期日、刪除誤登紀錄。<strong>未被指派角色者無法進入本頁</strong>；管理者固定擁有全部權限。',
                        rs_of('tool_calib'), rsu_of('tool_calib'), $admins, $_quotDepts, $canEdit);

                    eg_render_role_section('train', 'training', '教育訓練管理', 'fa-graduation-cap', '#b06f27',
                        '為每位使用者指派「教育訓練管理」頁的操作角色（KPI #19 人員教育訓練達成率的來源頁）。角色功能：<strong>訓練檢閱</strong>＝檢視訓練計畫/紀錄、月達成率與匯出；<strong>訓練登錄</strong>＝檢閱＋新增/編輯訓練場次、登錄完成；<strong>訓練管理員</strong>＝登錄＋刪除場次。<strong>未被指派角色者無法進入本頁</strong>；管理者固定擁有全部權限。',
                        rs_of('training'), rsu_of('training'), $admins, $_quotDepts, $canEdit);

                    eg_render_role_section('meet', 'meeting', '會議紀錄管理', 'fa-users', '#8A5A2B',
                        '為每位使用者指派「會議紀錄管理」頁的操作角色（2-GM-05-01 會議記錄）。角色功能：<strong>會議記錄檢閱</strong>＝檢視會議記錄（草稿僅記錄人本人看得到，出席人員／主席／總經理對相關會議自動有唯讀權限，不受此角色限制）；<strong>會議記錄登錄</strong>＝檢閱＋新增/編輯/送出自己的會議記錄；<strong>會議記錄管理員</strong>＝登錄＋檢視全部人員記錄、刪除、修改他人已送出的記錄。<strong>未被指派角色者仍可看到自己的草稿與相關會議</strong>，但看不到其他人的記錄列表；管理者固定擁有全部權限。',
                        rs_of('meeting'), rsu_of('meeting'), $admins, $_quotDepts, $canEdit);

                    eg_render_role_section('vaud', 'vendor_audit', '供應商稽核管理', 'fa-clipboard', '#b06f27',
                        '為每位使用者指派「供應商稽核管理」頁的操作角色（KPI #6 廠商稽核按時執行率的來源頁）。角色功能：<strong>稽核檢閱</strong>＝檢視廠商清單/稽核歷史/半年統計與匯出；<strong>稽核登錄</strong>＝檢閱＋登錄各廠商稽核完成紀錄；<strong>稽核管理員</strong>＝登錄＋設定週期/納管/基準到期日、刪除誤登紀錄。<strong>未被指派角色者無法進入本頁</strong>；管理者固定擁有全部權限。',
                        rs_of('vendor_audit'), rsu_of('vendor_audit'), $admins, $_quotDepts, $canEdit);

                    eg_render_role_section('eqm', 'equip_machine', '機台設備一覽表', 'fa-cogs', '#b06f27',
                        '為每位使用者指派「機台設備一覽表」頁的操作角色（主檔與 KPI「機台資產設定」共用同一張 machine_list）。角色功能：<strong>設備唯讀</strong>＝檢視機台清單/保養人歷程/機器設備履歴表；<strong>設備登錄</strong>＝唯讀＋新增/編輯機台、指派保養人、登錄履歴表、送出年度整份清單；<strong>設備管理員</strong>＝登錄＋停用機台、校正/刪除歷史紀錄、AS文件綁定、送簽設定、核准/退回年度清單。<strong>未被指派角色者無法進入本頁</strong>；管理者固定擁有全部權限。',
                        rs_of('equip_machine'), rsu_of('equip_machine'), $admins, $_quotDepts, $canEdit);

                    eg_render_role_section('btrp', 'business_trip', '公出單', 'fa-sign-out', '#d99a4e',
                        '為每位使用者指派「公出單」頁（2-MM-01-06）的角色。<strong>所有在職員工不需要指派任何角色</strong>，就能開立／檢視／列印<strong>自己的</strong>公出單，並核准指派給自己的單（核准人＝公出人的單位主管，主管本人公出時自動改為最高核准人員，主管請假時依代理設定轉給代理人）。此處只指派兩種加值角色：<strong>公出單檢閱</strong>＝可查看全部人員的公出單（唯讀）；<strong>公出單管理員</strong>＝查全部＋代其他人開單、刪除、模組設定（AS 文件綁定／外訓是否自動產生／核准圖章／列印簽章三格來源）、從外訓場次批次帶入。<span style="color:#b06f27;">「是否需要主管簽核（免簽核）」僅系統管理者可改</span>。管理者固定擁有全部權限。',
                        rs_of('business_trip'), rsu_of('business_trip'), $admins, $_quotDepts, $canEdit);

                    eg_render_role_section('dap', 'doc_apply', '文件制修申請單', 'fa-file-text-o', '#b06f27',
                        '為每位使用者指派「文件制、修申請單」頁（2-DC-01-01）的角色。角色功能：<strong>文件制修申請單檢閱</strong>＝唯讀查看全部申請單與列印；
                         <strong>文件制修申請單申請</strong>＝檢閱＋開立／編輯／送出<span style="color:#b06f27;">自己的</span>申請單（文件編碼依 AS 文件管理同一套規則自動產生）；
                         <strong>文件制修申請單管理員</strong>＝全部＋代他人開單、勾選會簽單位「採用並簽」、核准／退回、<strong>自動簽核（需操作確認密碼）</strong>、
                         <strong>建議建立</strong>（掃描 AS 文件管理裡有新文件或改版卻沒有申請單者，可設定只掃某日期之後、多選或全選一次建立）、
                         批次列印／批次刪除、會簽預設（以部門分類設定，也可對單一 AS 文件個別覆寫）與模組設定（AS 文件綁定／四格簽章來源／三組圖章模板）。<br>
                         <span style="color:#b06f27;">沒有任何角色的人</span>，若被指派為某張單的<strong>會簽單位簽核人</strong>（含代理人），仍可從通知開啟該單完成會簽。
                         核准／管理代表／單位主管的實際人員一律即時查<a href="../admin/org_role_setting.php" target="_blank" style="color:#b5762a;">組織角色綁定</a>，不寫死人名。管理者固定擁有全部權限。',
                        rs_of('doc_apply'), rsu_of('doc_apply'), $admins, $_quotDepts, $canEdit);

                    eg_render_role_section('eng', 'eng_change', '工程變更申請單', 'fa-exchange', '#d99a4e',
                        '為每位使用者指派「<a href="../TD/eng_change.php" target="_blank" style="color:#b5762a;">工程變更申請單</a>」頁（2-TD-01-01）的角色。
                         角色功能：<strong>工程變更檢閱</strong>＝唯讀查看全部申請單與列印；
                         <strong>工程變更申請</strong>＝檢閱＋開立／編輯／送出<span style="color:#b06f27;">自己的</span>申請單（文件編號＝西元年月日＋3 位流水，依表單上的日期自動產生）；
                         <strong>工程變更管理員</strong>＝全部＋代他人開單、改他人的單、刪除、<strong>代簽任何一關</strong>、模組設定（AS 文件綁定／各關卡簽章人來源／圖章模板／是否由圖面變更自動產生）。<br>
                         <span style="color:#b06f27;">簽核權不看角色</span>：流程各關卡（單位主管→倉管組→技術課→核准→相關單位會審→管制員）由系統依<strong>本單日期當時的職務</strong>解析出該簽的人，
                         是那個人才簽得下去（本人不在時自動換代理人，圖章加「代」字）。沒有任何角色的人，仍看得到「輪到自己簽」的那幾張單，否則收到通知點進來會是空白頁。<br>
                         各關卡與六個會審單位對應的部門一律即時查<a href="../admin/org_role_setting.php" target="_blank" style="color:#b5762a;">組織角色綁定</a>，不寫死部門 id 或人名。管理者固定擁有全部權限。',
                        rs_of('eng_change'), rsu_of('eng_change'), $admins, $_quotDepts, $canEdit);

                    eg_render_role_section('psl', 'print_sign_log', '列印與簽核紀錄', 'fa-history', '#b06f27',
                        '為每位使用者指派「<a href="../admin/print_sign_log.php" target="_blank" style="color:#b5762a;">列印與簽核紀錄</a>」頁的角色。
                         <strong>所有登入者不需要指派任何角色</strong>，就能查看<span style="color:#b06f27;">自己</span>的列印與簽核紀錄（這是本人自己的足跡，不是別人的）。
                         此處只指派兩種加值角色：<strong>紀錄檢閱</strong>＝可查看<strong>全部人員</strong>的列印與簽核紀錄（唯讀）；
                         <strong>紀錄管理</strong>＝查全部＋列印匯出全部篩選結果。<br>
                         列印紀錄會留下<strong>列印時間／列印人／登入電腦（電腦名稱＋IP）／文件名稱</strong>；
                         簽核紀錄取自全站共用的簽核資料，含<strong>文件名稱／送件日期／簽核人／簽核日期時間／結果與回覆意見</strong>。
                         目前涵蓋哪些表單、哪些還沒涵蓋，該頁「使用說明」會即時掃描列出。管理者固定擁有全部權限。',
                        rs_of('print_sign_log'), rsu_of('print_sign_log'), $admins, $_quotDepts, $canEdit);

                    eg_render_role_section('ia', 'internal_audit', '內部稽核', 'fa-search-plus', '#b06f27',
                        '為每位使用者指派「<a href="../ADM/internal_audit.php" target="_blank" style="color:#b5762a;">內部稽核</a>」頁（2-GM-06）的角色。
                         <strong>所有在職員工不需要指派任何角色</strong>，就能收到<span style="color:#b06f27;">自己單位</span>的內稽不符合通知單（IA 單）並填寫「原因分析／糾正措施／預防措施／單位主管核示」，也看得到自己單位的 IA 單。<br>
                         此處指派三種角色：<strong>內稽檢閱</strong>＝唯讀查看全部年度計畫、稽核通知單、查檢表、IA 單與稽核報告表；
                         <strong>稽核員</strong>＝檢閱＋建立與填寫三種查檢表、開立 IA 單、<strong>代受稽單位填寫回覆（會留下代填紀錄）</strong>、稽核組長驗證、重發通知；
                         <strong>內稽管理員（管理代表）</strong>＝全部＋年度計畫表建立/排定/送審/核准、稽核通知單新增編輯刪除、自動建立事前與結束會議紀錄、
                         AS 條文題庫維護、IA 單管理代表意見與<strong>結案</strong>、稽核報告表儲存與核准、模組設定（七份表單的 AS 文件綁定／簽章圖章／核准審查格來源／到期提醒天數）。<br>
                         <span style="color:#b06f27;">IA 單四段分工由系統依角色鎖定</span>：稽核員段／受稽單位段／驗證段／管理代表段，各段只有該身分能填。管理者固定擁有全部權限。',
                        rs_of('internal_audit'), rsu_of('internal_audit'), $admins, $_quotDepts, $canEdit);

                    eg_render_role_section('leave', 'leave', '請假系統', 'fa-calendar-minus-o', '#d99a4e',
                        '<strong>所有登入者都能申請請假、查看與撤回／銷假自己的單</strong>，不需要在這裡指派角色。此處只指派 <strong>人事（可看全部請假單）</strong>＝可檢視全公司請假單（不含代為簽核的權力）。<br>
                         <span style="color:#b06f27;">簽核權不由角色決定</span>：由申請人的部門／職稱階級推出主管鏈逐層簽核；主管當日有行程時改由其代理人簽，代理人若正好是申請人則自動直升上一級（權責分離）。<strong>代理人設定與最終裁決者請至「人事設定（hr_settings）」維護</strong>。主管（職稱有設定階級者）自動可檢視自己部門含下轄的請假單。管理者固定擁有全部權限。',
                        rs_of('leave'), rsu_of('leave'), $admins, $_quotDepts, $canEdit);

                    eg_render_role_section('ship', 'shipping', '快速出貨', 'fa-truck', '#F0A24B',
                        '為每位使用者指派「快速出貨」頁的操作角色。角色功能：<strong>出貨檢閱</strong>＝查詢待出貨清單、檢視近期出貨單與匯出，<span style="color:#b06f27;">不可建立出貨單</span>；<strong>出貨登錄</strong>＝檢閱＋建立出貨單（同客戶同日自動併為一張出貨單，並回填訂單編號與扣製令完工量）；<strong>出貨管理員</strong>＝登錄＋執行「舊資料訂單回填」（把 ERP 匯入、未帶訂單編號的歷史出貨資料比對回訂單）。<strong>未被指派角色者無法進入本頁</strong>；管理者固定擁有全部權限。',
                        rs_of('shipping'), rsu_of('shipping'), $admins, $_quotDepts, $canEdit);

                    eg_render_role_section('purc', 'purchase', '申請採購', 'fa-shopping-cart', '#8A5A2B',
                        '<span style="color:#b06f27;">此模組的角色名稱與內容可自訂</span>：到「申請採購」頁 →「設定」分頁 →「角色權限設定」可新增／改名／刪除角色，並逐一勾選該角色的<strong>可視內容</strong>（檢閱全部單據、看得到金額、看得到廠商與發票付款）與<strong>可操作</strong>（申請、採購版申請單、到貨入庫、詢價下單、高階核准、採購設定）。此處只負責把角色指派給人。以下是預設角色的內容，若已被改過請以該頁「角色權限說明」為準。<br>
                         為每位使用者指派「申請採購」頁的操作角色（權限由上而下包含，指派上層即自動具備下層能力）。<strong>申請採購</strong>＝提出／修改自己的申請單、上傳附件、查看自己的單；<strong>到貨入庫</strong>＝申請＋登錄到貨（可選「入庫待領／直接交付請購人／不列管」）；<strong>採購作業</strong>＝到貨入庫＋詢價填實際金額、下單、記發票與付款、結案、維護採購品主檔；<strong>採購管理員</strong>＝採購作業＋標籤與規格屬性設定、簽核門檻與附件路徑設定、刪除任何單據；<strong>採購檢閱</strong>＝唯讀查看全部單據與統計；<strong>高階核准</strong>＝金額超過第二層門檻時的第二關簽核人；<strong>完整申請單</strong>＝看到「採購版」申請單。<br>
                         <span style="color:#b06f27;">申請單有兩種版型</span>：一般使用者看到<strong>精簡版</strong>（只填用途、希望到貨日、急件、品名／規格／數量／單位，標題自動產生），採購料號、預估單價、到貨處理、附件分類都由採購後續補；指派<strong>完整申請單</strong>角色的人（採購作業以上自動具備，不必另外指派）看到<strong>採購版</strong>，可直接綁採購料號、手填標題、填預估單價與到貨處理、分類附件。<br>
                         <span style="color:#b06f27;">簽核不在申請當下判定</span>：申請時金額可以留白，等採購詢完價、填入實際金額後才依含稅總額判定要不要簽核（門檻在該頁「設定」分頁調整，預設 5000／30000）。第一層簽核人＝申請人的部門主管，由系統依代理人設定自動解析（主管當日有行程改由代理人簽，代理人正好是申請人時自動直升上一級）。<strong>未被指派角色者無法進入本頁</strong>；管理者固定擁有全部權限。',
                        rs_of('purchase'), rsu_of('purchase'), $admins, $_quotDepts, $canEdit);

                    eg_render_role_section('acc', 'accounting', '會計（對帳／應收／發票／應付）', 'fa-calculator', '#b06f27',
                        '為每位使用者指派「會計模組」的角色，涵蓋客戶發票資料維護、對帳作業、對帳單總覽、應收對帳單、發票開立與轉出、收款沖帳、應付對帳（加工費＋採購）、付款與沖帳等頁。<br>
                         <span style="color:#b06f27;">應付涵蓋範圍</span>：廠商加工費＋材料／其他採購（<strong>只收月結</strong>；現金／零用金採購不經會計，由採購自行做零用金記帳）。
                         採購單是現金還是月結預設依付款方式文字判定，<strong>會計登錄</strong>以上可在應付頁逐張改判（須填原因、寫入稽核）。
                         月結採購的付款狀態<strong>以會計的付款沖帳為準</strong>並自動回寫採購單，採購頁只顯示結果。<br>
                         <strong>會計檢閱</strong>＝只能查詢與匯出，不可修改；<strong>會計登錄</strong>＝檢閱＋維護客戶統編/發票抬頭、CSV 匯入、開立發票與收款沖帳、兩側都可對帳；<strong>會計管理員</strong>＝登錄＋作廢發票、刪除收款單、<strong>退回已鎖帳的對帳單</strong>。<br>
                         <span style="color:#b06f27;">對帳分工（實務）</span>：應收由<strong>業務</strong>對完帳給會計、應付由<strong>生管</strong>對完帳給會計，所以另有兩個只做對帳的角色——<strong>應收對帳(業務)</strong> 只能對應收（客戶／出貨退貨），<strong>應付對帳(生管)</strong> 只能對應付（廠商／加工費），<span style="color:#b06f27;">兩者互不相通</span>（業務不能碰應付、生管不能碰應收），且都<strong>不能</strong>開發票或做收款付款。這兩個角色可以修改單據數量/單價/金額/備註與帳款月份，但<strong>每次修改都必填原因並寫入稽核紀錄</strong>。<br>
                         <span style="color:#b06f27;">鎖帳規則</span>：對帳人員按「確認正確」即鎖帳，之後不可改單也不可再暫存，<strong>僅會計管理員可退回重對</strong>（須填原因）。已開立發票的憑證另有更硬的鎖——不提供解鎖，只能作廢／折讓／補開，因為帳面必須與國稅局申報一致。<br>
                         <span style="color:#b06f27;">為什麼要先維護客戶發票資料</span>：開立電子發票必須有買方統一編號與買方名稱，目前 925 家有效客戶只有 12 家資料完整、近一年有出貨的 175 家中有 171 家缺資料，補齊前發票轉出會被擋下。<strong>未被指派角色者無法進入這些頁面</strong>；管理者固定擁有全部權限。',
                        rs_of('accounting'), rsu_of('accounting'), $admins, $_quotDepts, $canEdit);

                    eg_render_role_section('extdoc', 'external_doc', '外來文件清單', 'fa-file-text-o', '#b06f27',
                        '為每位使用者指派「外來文件清單」頁（業務 &gt; 外來文件清單，AS9100 外來文件管制）的操作角色。角色功能：<strong>外來文件檢閱</strong>＝檢視清單（依訂單綁定/客戶/年度篩選）、匯出 CSV、依客戶分組列印；<strong>外來文件管理</strong>＝檢閱＋綁定列印頁尾的 AS 文件編號。清單內容來自附件標籤有勾「列入外來文件清單」的料號附件與報價附件（標籤設定在報價單頁或主檔管理）。<strong>未被指派角色者無法檢視本頁</strong>；管理者固定擁有全部權限。',
                        rs_of('external_doc'), rsu_of('external_doc'), $admins, $_quotDepts, $canEdit);

                    eg_render_role_section('otrk', 'order_track', '訂單追蹤', 'fa-list-alt', '#c0762c',
                        '為每位使用者指派「訂單追蹤」頁（業務 &gt; 訂單追蹤）的操作角色。角色與各功能的對應在<strong>訂單追蹤頁右上「角色設定」</strong>（僅管理員）依功能群組勾選：訂單基本操作（檢視/新建編輯/刪除/顯示金額）、訂單流程（批圖/轉生管/結案/取消訂單/OP轉訂單）、訂單變更、設計與批圖（設計備註/批圖編輯器/前往料號主檔）、計算工具（齒輪/鍵槽計算）。<strong>注意：訂單追蹤頁目前權限檢查尚未切換為角色制</strong>，此處指派先建立人員對照，切換後才會生效；管理者固定擁有全部權限。',
                        rs_of('order_track'), rsu_of('order_track'), $admins, $_quotDepts, $canEdit);

                    eg_render_role_section('tidc', 'type_id_ctrl', '型態識別文件管制表', 'fa-sitemap', '#b06f27',
                        '為每位使用者指派「型態識別文件管制表」頁（技術部 &gt; 型態識別文件管制表）的操作角色。角色功能：<strong>型態文件檢閱</strong>＝檢視清單、開啟查看、列印；<strong>型態文件登錄</strong>＝檢閱＋新增/編輯、「掃描待建立料號」與自動產生/同步、確認清單（含排除項目）；<strong>型態文件管理員</strong>＝登錄＋刪除、AS 文件編號綁定。<strong>未被指派角色者無法進入本頁</strong>；管理者固定擁有全部權限。',
                        rs_of('type_id_ctrl'), rsu_of('type_id_ctrl'), $admins, $_quotDepts, $canEdit);

                    eg_render_role_section('tdev', 'td_dev_eval', '產品開發評估表', 'fa-flask', '#c0762c',
                        '為每位使用者指派「產品開發評估表」頁（技術部 &gt; 產品開發評估表，AS 2-TD-02-01）的操作角色。角色功能：<strong>評估表檢閱</strong>＝檢視清單、開啟查看、列印；<strong>評估表登錄</strong>＝檢閱＋新增/編輯、逐項填寫、依部門身分簽核；<strong>評估表管理員</strong>＝登錄＋刪除、AS 文件編號綁定、取消他人簽核。<strong>APQP 小組簽認各部門欄位由該部門任一主管簽核</strong>，部門綁定在「組織角色綁定設定」頁（重用技術/業務/管理/生產/品保部門既有綁定，另有資材部門角色），與本頁的檢閱/登錄/管理角色是兩件事——這裡只決定誰能進本頁操作，能不能簽某部門的欄位另外看是否為該部門主管。<strong>未被指派角色者無法進入本頁</strong>；管理者固定擁有全部權限。',
                        rs_of('td_dev_eval'), rsu_of('td_dev_eval'), $admins, $_quotDepts, $canEdit);

                    eg_render_role_section('pfmea', 'pfmea', 'PFMEA潛在失效模式及效應分析', 'fa-exclamation-triangle', '#8A5A2B',
                        '為每位使用者指派「PFMEA潛在失效模式及效應分析」頁（技術部 &gt; PFMEA，AS 3-TD-01-02）的操作角色。角色功能：<strong>PFMEA檢閱</strong>＝檢視清單、開啟查看、列印；<strong>PFMEA登錄</strong>＝檢閱＋新增/編輯分析列；<strong>PFMEA管理員</strong>＝登錄＋刪除、AS 文件編號綁定。<strong>未被指派角色者無法進入本頁</strong>；管理者固定擁有全部權限。',
                        rs_of('pfmea'), rsu_of('pfmea'), $admins, $_quotDepts, $canEdit);

                    eg_render_role_section('prj', 'project', '專案管理', 'fa-folder-open-o', '#B5762A',
                        '為每位使用者指派「專案管理」頁（總經理室 &gt; 專案管理，AS 2-GM-02 專案管理程序）的操作角色。角色功能：<strong>專案檢閱</strong>＝檢視清單、明細、列印執行規劃表(2-GM-02-02)與專案管理卡(2-GM-02-03)；<strong>專案登錄</strong>＝檢閱＋建立/編輯專案、訂單轉專案、編排執行規劃表、開立管理卡、同步 BOM 製程；<strong>專案管理員</strong>＝登錄＋刪除專案與管理卡、自訂標籤維護、模組設定（立案核准人、預設會簽單位、結案前文件檢核開關、圖章模板）、AS 文件編號綁定、批次自動簽核。'
                        . '<br><strong>另有兩種不看角色的身分</strong>：①<strong>專案負責人</strong>（每個專案自己指定的人）即使只有「專案檢閱」角色，也能編輯自己負責的那些專案；②被指派為某一列<strong>會簽人</strong>者不需要任何角色就能處理自己那一列會簽。<strong>未被指派角色者無法進入本頁</strong>；管理者固定擁有全部權限。',
                        rs_of('project'), rsu_of('project'), $admins, $_quotDepts, $canEdit);

                    // ── 其他模組：有建角色、但上面還沒有手寫區塊的，自動補一塊 ──────────────
                    //   以前新模組要回到本頁手寫 4 段（宣告/載入/載入/呼叫），漏掉就整塊設定畫面消失、
                    //   而且不會報錯。現在只要在該模組頁面建好角色就會自動長出來，不必再回來改這裡；
                    //   要自訂標題與說明文字時，再補一個 eg_render_role_section() 呼叫即可（上面的優先）。
                    $_renderedModules = [];
                    foreach ($GLOBALS['_eg_rendered_role_modules'] ?? [] as $_m) $_renderedModules[$_m] = true;
                    foreach (array_keys($RS) as $_m) {
                        if (isset($_renderedModules[$_m])) continue;
                        $_prefix = 'auto' . preg_replace('/[^a-z0-9]/', '', $_m);
                        eg_render_role_section($_prefix, $_m, $_m, 'fa-cube', '#8A5A2B',
                            '此模組尚未在本頁設定專屬的標題與說明，內容由系統依 <code>roles.module = ' . htmlspecialchars($_m) . '</code> 自動列出。'
                            . '角色與功能定義請至該模組頁面的「權限設定 / 角色設定」。',
                            rs_of($_m), rsu_of($_m), $admins, $_quotDepts, $canEdit);
                    }
                    ?>

                    <!-- ══ AS9100 文件管理：職稱權限指派 ══ -->
                    <div class="row" style="margin-top:20px;" id="asdoc-pos-role-section">
                        <div class="col-md-12">
                            <div class="x_panel">
                                <div class="x_title">
                                    <h2><i class="fa fa-sitemap" style="color:#c0392b;margin-right:7px;"></i>AS9100 文件管理 <small>職稱權限指派（該職稱所有人自動擁有）</small></h2>
                                    <ul class="nav navbar-right panel_toolbox"><li><a class="collapse-link"><i class="fa fa-chevron-up"></i></a></li></ul>
                                    <div class="clearfix"></div>
                                </div>
                                <div class="x_content">
                                    <p style="font-size:12px;color:#888;margin-bottom:12px;">
                                        <i class="fa fa-info-circle"></i>
                                        指派角色給「職稱」後，<strong>所有擁有該職稱（含兼任）的在職人員</strong>自動獲得該角色功能，之後到職/調職者也自動生效，不必逐一指派。
                                        <strong>優先權：職稱為主自動套用；個人（上方區塊或 AS 頁「角色設定」）另有指派時，以個人設定為準（覆蓋職稱）。</strong>系統角色「管理員」不可指派給職稱。
                                    </p>
                                    <?php $_asdocAssignableRoles = array_values(array_filter($_asdocRoles, function($r){ return (int)$r['is_system'] === 0; })); ?>
                                    <table class="table table-striped table-bordered table-condensed" style="font-size:13px;">
                                        <thead style="background:#f8f9fa;">
                                            <tr>
                                                <th style="width:140px;">職稱</th>
                                                <th>綁定部門</th>
                                                <th>已指派角色</th>
                                                <?php if ($canEdit): ?><th style="width:220px;">新增角色</th><?php endif; ?>
                                            </tr>
                                        </thead>
                                        <tbody id="asdocpos-role-tbody">
                                        <?php foreach ($_asdocPositions as $_pos):
                                            $_assigned = $_asdocPosRoles[$_pos['id']] ?? [];
                                        ?>
                                            <tr>
                                                <td style="font-weight:600;"><?= htmlspecialchars($_pos['name']) ?></td>
                                                <td style="color:#666;font-size:12px;"><?= htmlspecialchars($_pos['departments'] ?? '') ?: '—' ?></td>
                                                <td id="asdocpos-tags-<?= $_pos['id'] ?>">
                                                    <?php if (empty($_assigned)): ?>
                                                        <span class="text-muted" style="font-size:12px;">（未指派）</span>
                                                    <?php else: foreach ($_assigned as $_ar): ?>
                                                        <span class="label label-primary" style="margin-right:4px;font-size:12px;padding:3px 7px;display:inline-block;">
                                                            <?= htmlspecialchars($_ar['role_name']) ?>
                                                            <?php if ($canEdit): ?>
                                                                <a href="#" onclick="posRoleRemove(<?= $_pos['id'] ?>,<?= $_ar['role_id'] ?>);return false;" style="color:#fff;margin-left:4px;opacity:.8;" title="移除">×</a>
                                                            <?php endif; ?>
                                                        </span>
                                                    <?php endforeach; endif; ?>
                                                </td>
                                                <?php if ($canEdit): ?>
                                                <td>
                                                    <div class="input-group input-group-sm">
                                                        <select class="form-control" id="asdocpos-sel-<?= $_pos['id'] ?>">
                                                            <option value="">— 選擇角色 —</option>
                                                            <?php foreach ($_asdocAssignableRoles as $_role): ?><option value="<?= $_role['role_id'] ?>"><?= htmlspecialchars($_role['role_name']) ?></option><?php endforeach; ?>
                                                        </select>
                                                        <span class="input-group-btn">
                                                            <button class="btn btn-primary btn-sm" type="button" onclick="posRoleAssign(<?= $_pos['id'] ?>)"><i class="fa fa-plus"></i> 指派</button>
                                                        </span>
                                                    </div>
                                                </td>
                                                <?php endif; ?>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- ／AS 職稱權限指派 ══ -->

                    <!-- ══ 批圖編輯器：標籤儲存路徑 ══ -->
                    <div class="row" style="margin-top:20px;" id="imgedit-label-dir-section">
                        <div class="col-md-12">
                            <div class="x_panel">
                                <div class="x_title">
                                    <h2><i class="fa fa-folder-open" style="color:#ab47bc;margin-right:7px;"></i>批圖編輯器 <small>標籤儲存路徑（NAS）</small></h2>
                                    <ul class="nav navbar-right panel_toolbox"><li><a class="collapse-link"><i class="fa fa-chevron-up"></i></a></li></ul>
                                    <div class="clearfix"></div>
                                </div>
                                <div class="x_content">
                                    <p style="font-size:12px;color:#888;">
                                        標籤內的實體圖檔存放位置。子資料夾自動分層：<code>company</code>＝公司共用、<code>U使用者ID</code>＝私人、<code>D部門ID</code>＝部門（前綴避免 ID 衝突）。
                                        修改路徑後<strong>既有檔案不會自動搬移</strong>，請先將舊資料夾內容複製到新位置再儲存。
                                    </p>
                                    <form method="post" style="display:flex;gap:8px;align-items:center;max-width:760px;">
                                        <input type="text" name="imgedit_label_dir" class="form-control input-sm" style="flex:1;font-family:Consolas,monospace;"
                                               value="<?= htmlspecialchars($imgeditLabelDir, ENT_QUOTES, 'UTF-8') ?>" placeholder="\\excellentnas\生產課\BOM\ERP\共用資料\標籤" <?= $canEdit ? '' : 'disabled' ?>>
                                        <?php if ($canEdit): ?>
                                        <button type="submit" name="save_imgedit_label_dir" value="1" class="btn btn-primary btn-sm"
                                                onclick="return confirm('確定更新標籤儲存路徑？\n既有檔案不會自動搬移，請確認新位置已放妥檔案。');">
                                            <i class="fa fa-save"></i> 儲存
                                        </button>
                                        <?php endif; ?>
                                    </form>
                                    <?php if (is_string($imgeditLabelDir) && $imgeditLabelDir !== ''): ?>
                                    <p style="font-size:11.5px;margin-top:6px;color:<?= is_dir($imgeditLabelDir) ? '#26b99a' : '#e74c3c' ?>;">
                                        <i class="fa <?= is_dir($imgeditLabelDir) ? 'fa-check-circle' : 'fa-exclamation-triangle' ?>"></i>
                                        <?= is_dir($imgeditLabelDir) ? '目前路徑可正常存取' : '目前伺服器無法存取此路徑（NAS 離線或權限不足），標籤圖檔會暫以資料庫內嵌方式保存' ?>
                                    </p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- ／標籤儲存路徑 ══ -->

                    <!-- ══ 批圖編輯器：工作檔保留上限 ══ -->
                    <div class="row" style="margin-top:20px;">
                        <div class="col-md-12">
                            <div class="x_panel">
                                <div class="x_title">
                                    <h2><i class="fa fa-archive" style="color:#ab47bc;margin-right:7px;"></i>批圖編輯器 <small>工作檔保留上限</small></h2>
                                    <ul class="nav navbar-right panel_toolbox"><li><a class="collapse-link"><i class="fa fa-chevron-up"></i></a></li></ul>
                                    <div class="clearfix"></div>
                                </div>
                                <div class="x_content">
                                    <p style="font-size:12px;color:#888;">
                                        同一個料號最多保留幾份批圖工作檔（.egwork.json）。存檔時若超過上限，會自動刪除最舊的一份（絕對不會刪到剛存好的這份）。
                                    </p>
                                    <form method="post" style="display:flex;gap:8px;align-items:center;max-width:300px;">
                                        <input type="number" name="imgedit_workfile_max" class="form-control input-sm" min="1" max="50"
                                               value="<?= (int)$imgeditWorkfileMax ?>" <?= $canEdit ? '' : 'disabled' ?>>
                                        <?php if ($canEdit): ?>
                                        <button type="submit" name="save_imgedit_workfile_max" value="1" class="btn btn-primary btn-sm">
                                            <i class="fa fa-save"></i> 儲存
                                        </button>
                                        <?php endif; ?>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- ／工作檔保留上限 ══ -->

                    <!-- ══ AS9100 文件管理：檔案儲存根路徑 ══ -->
                    <div class="row" style="margin-top:20px;" id="asdoc-nas-dir-section">
                        <div class="col-md-12">
                            <div class="x_panel">
                                <div class="x_title">
                                    <h2><i class="fa fa-folder-open" style="color:#c0392b;margin-right:7px;"></i>AS9100 文件管理 <small>檔案儲存根路徑（NAS）</small></h2>
                                    <ul class="nav navbar-right panel_toolbox"><li><a class="collapse-link"><i class="fa fa-chevron-up"></i></a></li></ul>
                                    <div class="clearfix"></div>
                                </div>
                                <div class="x_content">
                                    <p style="font-size:12px;color:#888;">
                                        AS9100 文件（程序書/表單各版本檔案與制修申請單）實體存放位置。子資料夾自動分層：<code>docs\文件ID</code>＝各文件、<code>_template</code>＝申請單範本。
                                        資料庫只存檔名，完整路徑於讀取時以此設定即時組出；修改路徑後<strong>既有檔案不會自動搬移</strong>，請先將舊資料夾整個複製到新位置再儲存。
                                    </p>
                                    <form method="post" style="display:flex;gap:8px;align-items:center;max-width:760px;">
                                        <input type="text" name="asdoc_nas_dir" class="form-control input-sm" style="flex:1;font-family:Consolas,monospace;"
                                               value="<?= htmlspecialchars($asdocNasDir, ENT_QUOTES, 'UTF-8') ?>" placeholder="\\excellentnas\as9100\ERP測試" <?= $canEdit ? '' : 'disabled' ?>>
                                        <?php if ($canEdit): ?>
                                        <button type="submit" name="save_asdoc_nas_dir" value="1" class="btn btn-primary btn-sm"
                                                onclick="return confirm('確定更新 AS 文件儲存路徑？\n既有檔案不會自動搬移，請確認新位置已放妥檔案（docs 資料夾與 _template 資料夾整個複製過去）。');">
                                            <i class="fa fa-save"></i> 儲存
                                        </button>
                                        <?php endif; ?>
                                    </form>
                                    <?php if (is_string($asdocNasDir) && $asdocNasDir !== ''): ?>
                                    <p style="font-size:11.5px;margin-top:6px;color:<?= is_dir($asdocNasDir) ? '#26b99a' : '#e74c3c' ?>;">
                                        <i class="fa <?= is_dir($asdocNasDir) ? 'fa-check-circle' : 'fa-exclamation-triangle' ?>"></i>
                                        <?= is_dir($asdocNasDir) ? '目前路徑可正常存取' : '目前伺服器無法存取此路徑（NAS 離線或權限不足），上傳/下載文件會失敗' ?>
                                    </p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- ／AS 文件儲存路徑 ══ -->

                    <!-- ／角色指派 ══ -->

                </div>
            </div>


            <!-- /page content -->

            <!-- footer content include -->
            <?php include '../partPage/footer.html' ?>
            <!-- /footer content include -->
        </div>
    </div>

    <!-- jQuery -->
    <script src="../../resource/js/jquery.min.js"></script>
    <!-- Bootstrap -->
    <script src="../../resource/js/bootstrap.min.js"></script>
    <!-- FastClick -->
    <script src="../../resource/js/fastclick.js"></script>
    <!-- NProgress -->
    <script src="../../resource/js/nprogress.js"></script>
    <!-- validator 按送出後的資料檢驗與重導網頁-->
    <!-- <script src="../../resource/js/validator.js"></script> -->
    <!-- Custom Theme Scripts -->
    <script src="../../resource/js/custom.min.js"></script>

    <!-- iCheck -->
    <script src="../../resource/js/icheck.min.js"></script>
    <!-- Datatables -->
    <script src="../../resource/js/jquery.dataTables.min.js"></script>
    <script src="../../resource/js/dataTables.bootstrap.min.js"></script>
    <script src="../../resource/js/dataTables.buttons.min.js"></script>
    <script src="../../resource/js/buttons.bootstrap.min.js"></script>
    <script src="../../resource/js/buttons.flash.min.js"></script>
    <script src="../../resource/js/buttons.html5.min.js"></script>
    <script src="../../resource/js/buttons.print.min.js"></script>
    <script src="../../resource/js/dataTables.fixedHeader.min.js"></script>
    <script src="../../resource/js/dataTables.keyTable.min.js"></script>
    <script src="../../resource/js/dataTables.responsive.min.js"></script>
    <script src="../../resource/js/responsive.bootstrap.js"></script>
    <script src="../../resource/js/dataTables.scroller.min.js"></script>
    <script src="../../resource/js/jszip.min.js"></script>
    <script src="../../resource/js/pdfmake.min.js"></script>
    <script src="../../resource/js/vfs_fonts.js"></script>

    <script>
        $(document).ready(function() {
            // DataTables 在 custom.min.js 中初始化，所以我們可以在這裡安全地附加事件。
            // 我們鎖定由 DataTables 產生的搜尋輸入框。
            var searchInput = $('div.dataTables_filter input[type="search"]');

            // 為其添加雙擊事件監聽器。
            searchInput.on('dblclick', function() {
                // 檢查輸入框中是否有內容。
                if ($(this).val().trim() !== '') {
                    // 清除內容並觸發 'keyup' 事件，以強制 DataTables 重新篩選。
                    $(this).val('').trigger('keyup');
                }
            });

            // 將權限說明移動到 Search 同一列
            var $legend = $('#permission-legend');
            var $btns = $('#user-filter-buttons');
            var $filter = $('.dataTables_filter');

            if ($filter.length) {
                // 擴展 Filter 區塊寬度以容納更多元件，防止換行
                var $filterCol = $filter.closest('[class*="col-"]');
                if ($filterCol.length) {
                    $filterCol.removeClass('col-sm-6 col-md-6').addClass('col-sm-12 col-md-12');
                    $filterCol.siblings().hide(); // 隱藏其他佔位區塊 (如 length menu)
                }
                $filter.css({
                    'white-space': 'nowrap',
                    'text-align': 'left'
                }); // 強制不換行並靠左對齊

                if ($legend.length) {
                    // Legend
                    $legend.css({
                        'display': 'inline-block',
                        'margin-right': '20px',
                        'vertical-align': 'middle'
                    });
                    $filter.prepend($legend);
                }
                if ($btns.length) {
                    // Buttons 最左邊
                    $btns.css({
                        'display': 'inline-block',
                        'margin-right': '10px',
                        'vertical-align': 'middle'
                    });
                    $filter.prepend($btns);
                }
                var $filter = $('.dataTables_filter');

                $filter.css({
                    'white-space': 'nowrap',
                    'text-align': 'left',
                    'float': 'left'
                });

                $('#user-filter-buttons, #permission-legend, div.dataTables_filter input[type="search"]')
                    .css({
                        'display': 'inline-block',
                        'vertical-align': 'middle',
                        'margin-right': '10px'
                    });
                // 將左邊群組包起來以維持結構
                var $filter = $('.dataTables_filter');

                var $leftGroup = $('<div class="dt-left-group" style="display:inline-flex; align-items:center; gap:8px; flex-wrap:wrap;"></div>');
                $leftGroup.append($('#user-filter-buttons'));
                $leftGroup.append($('#permission-legend'));
                // 部門篩選下拉也移入同一列
                $leftGroup.append($('#dept-filter-select').css({
                    'display': 'inline-block',
                    'width': '150px',
                    'vertical-align': 'middle'
                }));

                $filter.prepend($leftGroup);

                // 搜尋框保持 DataTables 原本的位置（右側）

            }
        });
    </script>
    <script>
        // 顯示臨時訊息的輔助函數
        function showTemporaryMessage(message, isSuccess) {
            var msgDiv = $('<div></div>');
            msgDiv.text(message);
            msgDiv.css({
                'position': 'fixed',
                'top': '20px',
                'left': '50%',
                'transform': 'translateX(-50%)',
                'padding': '10px 25px',
                'border-radius': '5px',
                'color': '#fff',
                'z-index': '9999',
                'background-color': isSuccess ? '#26B99A' : '#E74C3C', // Green for success, Red for error
                'display': 'none' // Start hidden for fadeIn
            });

            $('body').append(msgDiv);

            // Fade in, wait 2 seconds, then fade out and remove
            msgDiv.fadeIn(500).delay(2000).fadeOut(500, function() {
                $(this).remove();
            });
        }

        $(document).ready(function() {
            // 使用事件委派來處理所有權限更新表單的提交
            $(document).on('submit', '.permission-update-form', function(e) {
                e.preventDefault(); // 防止表單的傳統提交方式

                var form = $(this);
                var formData = form.serialize(); // 序列化表單數據
                var url = form.attr('action');

                $.ajax({
                    type: 'POST',
                    url: url,
                    data: formData + '&updatePermissions=true', // 手動附加 submit 按鈕的參數，因為 .serialize() 不會包含它
                    dataType: 'json',
                    success: function(response) {
                        // 從 modal 標題獲取使用者名稱
                        var modalTitle = form.closest('.modal-content').find('.modal-title').text(),
                            userName = modalTitle.split('　/　')[0].trim();

                        if (response.success) {
                            var successMsg = userName + '　權限修改成功';
                            showTemporaryMessage(successMsg, true);

                            // 更新表格中的對應行
                            var userId = form.find('input[name="userid"]').val();
                            var row = $('tr[data-user-id="' + userId + '"]');

                            // 準備新的權限物件，用於更新下拉選單
                            var newPermsObj = {};
                            var hasAnyPerm = false;

                            if (row.length) {
                                // 遍歷表單中的核取方塊以更新表格
                                row.find('td[data-permission]').each(function() {
                                    var cell = $(this);
                                    var moduleCode = cell.attr('data-permission');

                                    // Find checked inputs for this module in the form
                                    var checkedVals = [];
                                    form.find('input[name="permissions[group][' + moduleCode + '][]"]:checked').each(function() {
                                        checkedVals.push($(this).val());
                                    });

                                    // 構建權限物件
                                    if (checkedVals.length > 0) {
                                        newPermsObj[moduleCode] = checkedVals.join('');
                                        hasAnyPerm = true;
                                    }

                                    var acrudMap = {
                                        'A': '完整權限',
                                        'C': '新增',
                                        'R': '檢視',
                                        'U': '修改',
                                        'D': '刪除'
                                    };
                                    var displayTooltips = [];
                                    var displayHtml = '';
                                    ['A', 'C', 'R', 'U', 'D'].forEach(function(key) {
                                        if (checkedVals.indexOf(key) !== -1) {
                                            displayTooltips.push(acrudMap[key]);

                                            var color = 'default';
                                            if (key === 'C') color = 'primary';
                                            if (key === 'R') color = 'success';
                                            if (key === 'U') color = 'warning';
                                            if (key === 'D') color = 'danger';

                                            displayHtml += '<span class="label label-' + color + '">' + key + '</span> ';
                                        }
                                    });

                                    cell.html(displayHtml);
                                    cell.attr('title', displayTooltips.join('、'));
                                });
                            }

                            // 更新該行的 data-has-perms 屬性
                            row.attr('data-has-perms', hasAnyPerm ? 'true' : 'false');
                            // 若目前處於「顯示未設定權限」模式，重新繪製表格以即時反映篩選結果
                            if ($('#btn-show-unconfigured').hasClass('btn-primary')) {
                                $('#datatable-buttons').DataTable().draw();
                            }

                            // 更新所有 Modal 中的「複製權限」下拉選單
                            $('.copy-source-select').each(function() {
                                var select = $(this);
                                // 取得該下拉選單所屬的 Modal 的使用者 ID (避免自己複製自己)
                                var currentModalUserId = select.closest('form').find('input[name="userid"]').val();

                                if (currentModalUserId !== userId) {
                                    var option = select.find('option[value="' + userId + '"]');

                                    if (hasAnyPerm) {
                                        if (option.length > 0) {
                                            // 更新現有選項的權限資料
                                            option.data('perms', newPermsObj);
                                        } else {
                                            // 新增選項
                                            var newOption = $('<option>', {
                                                value: userId,
                                                text: userName
                                            });
                                            newOption.data('perms', newPermsObj);
                                            select.append(newOption);
                                        }
                                    } else {
                                        // 若該使用者已無任何權限，從清單中移除
                                        if (option.length > 0) {
                                            option.remove();
                                        }
                                    }
                                }
                            });

                            // 關閉 modal
                            form.closest('.modal').modal('hide');

                        } else {
                            var errorMsg = response.message || '發生未知錯誤';
                            showTemporaryMessage(errorMsg, false);
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error("AJAX 請求錯誤:", status, error, xhr.responseText);
                        showTemporaryMessage('與伺服器通訊時發生錯誤，請檢查主控台。', false);
                    }
                });
            });

            // 快速勾選按鈕
            $(document).on('click', '.btn-bulk-check', function() {
                var val = $(this).data('val');
                var form = $(this).closest('form');
                // 將該表單內所有對應的 checkbox 設為 checked 並觸發 change 事件以執行互斥邏輯
                form.find('input[type="checkbox"][value="' + val + '"]').prop('checked', true).trigger('change');
            });

            // 清空按鈕
            $(document).on('click', '.btn-bulk-clear', function() {
                var form = $(this).closest('form');
                form.find('input[type="checkbox"]').prop('checked', false);
            });

            // 複製權限按鈕邏輯
            $(document).on('click', '.btn-copy-perms', function() {
                var container = $(this).closest('div');
                var select = container.find('.copy-source-select');
                var selectedOption = select.find('option:selected');
                var permsData = selectedOption.data('perms');
                var form = $(this).closest('form');

                if (!permsData) {
                    alert('請選擇一位員工以複製權限');
                    return;
                }

                // 清空目前設定
                form.find('input[type="checkbox"]').prop('checked', false);

                // 套用新設定
                if (permsData.group) {
                    $.each(permsData.group, function(mCode, pStr) {
                        if (pStr) {
                            var $boxes = form.find('input[name="permissions[group][' + mCode + '][]"]');
                            for (var i = 0; i < pStr.length; i++) {
                                $boxes.filter('[value="' + pStr.charAt(i) + '"]').prop('checked', true);
                            }
                            // RBAC 簡化列（僅 R 選項）：來源含 A(完整) 視同開啟
                            if (pStr.indexOf('A') !== -1 && $boxes.filter('[value="A"]').length === 0) {
                                $boxes.filter('[value="R"]').prop('checked', true);
                            }
                        }
                    });
                }
                if (permsData.page) {
                    $.each(permsData.page, function(pId, pStr) {
                        if (pStr) {
                            var $boxes = form.find('input[name="permissions[page][' + pId + '][]"]');
                            for (var i = 0; i < pStr.length; i++) {
                                $boxes.filter('[value="' + pStr.charAt(i) + '"]').prop('checked', true);
                            }
                            if (pStr.indexOf('A') !== -1 && $boxes.filter('[value="A"]').length === 0) {
                                $boxes.filter('[value="R"]').prop('checked', true);
                            }
                        }
                    });
                }
            });

            // 權限 Checkbox 連動邏輯：A (完整權限) 與其他權限互斥
            $(document).on('change', '.permission-update-form input[type="checkbox"]', function() {
                var $this = $(this);
                var val = $this.val();
                var $container = $this.closest('td');

                if ($this.is(':checked')) {
                    if (val === 'A') {
                        // 勾選 A 時，清除同列的其他選項
                        $container.find('input[type="checkbox"]').not($this).prop('checked', false);
                    } else {
                        // 勾選其他選項時，清除 A (避免邏輯衝突)
                        $container.find('input[type="checkbox"][value="A"]').prop('checked', false);

                        // 若選擇 C, U, D，自動勾選 R (因為沒有檢視權限就無法進行CUD的操作)
                        if (['C', 'U', 'D'].indexOf(val) !== -1) {
                            $container.find('input[type="checkbox"][value="R"]').prop('checked', true);
                        }
                    }
                } else {
                    // 若取消勾選 R，則自動取消 C, U, D
                    if (val === 'R') {
                        $container.find('input[type="checkbox"][value="C"], input[type="checkbox"][value="U"], input[type="checkbox"][value="D"]').prop('checked', false);
                    }
                }
            });

            // 列表篩選功能
            var filterUnconfigured = false;
            var filterDeptValue    = '';

            // 擴充 DataTables 搜尋功能（未設定 + 部門篩選）
            $.fn.dataTable.ext.search.push(
                function(settings, data, dataIndex) {
                    var table = new $.fn.dataTable.Api(settings);
                    var row   = table.row(dataIndex).node();
                    // 未設定權限篩選
                    if (filterUnconfigured && $(row).attr('data-has-perms') !== 'false') return false;
                    // 部門篩選（含兼任）
                    if (filterDeptValue) {
                        var depts = ($(row).data('depts') || '').split('|').map(function(s){ return s.trim(); });
                        if (depts.indexOf(filterDeptValue) === -1) return false;
                    }
                    return true;
                }
            );

            // 部門篩選函數（供 select 的 inline onchange 呼叫，必須掛在 window 上，
            // 否則在 ready 閉包內宣告的函式從 HTML 屬性找不到 → 篩選無作用）
            window.filterPermTableByDept = function() {
                filterDeptValue = $('#dept-filter-select').val();
                $('#datatable-buttons').DataTable().draw();
            };

            $('#btn-show-all').click(function() {
                filterUnconfigured = false;
                $(this).removeClass('btn-default').addClass('btn-primary');
                $('#btn-show-unconfigured').removeClass('btn-primary').addClass('btn-default');
                $('#datatable-buttons').DataTable().draw();
            });

            $('#btn-show-unconfigured').click(function() {
                filterUnconfigured = true;
                $(this).removeClass('btn-default').addClass('btn-primary');
                $('#btn-show-all').removeClass('btn-primary').addClass('btn-default');
                $('#datatable-buttons').DataTable().draw();
            });
        });

        // 展開/收合子頁面 (使用 data attribute 以支援特殊字元代碼)
        function toggleModulePages(btn) {
            var mCode = $(btn).attr('data-module');
            $('tr[data-parent-module="' + mCode + '"]').toggle();
        }

        // ══ 角色指派（依模組通用：prefix=quot/notice，module=quotation/notice）══
        var ROLES_API = '../../src/store/Roles_API.php';
        // 模組代碼 → 中文名（與 PHP 端 eg_module_label() 同一張表，畫面上不要出現 as_doc 這種代碼）
        var EG_MODULE_LABEL = <?= json_encode(array_map(function($m){ return $m['label']; }, $EG_ROLE_MODULES), JSON_UNESCAPED_UNICODE) ?>;
        function egModLabel(m) { return (m && EG_MODULE_LABEL[m]) ? EG_MODULE_LABEL[m] : (m || ''); }

        // 複製其他員工的權限設定（角色指派＋選單群組/頁面權限）
        $(document).on('click', '#btn-copy-perm', function() {
            var srcId = $('#copy-perm-source').val();
            var tgtId = $('#copy-perm-target').val();
            var mode  = $('#copy-perm-mode').val();
            if (!srcId || !tgtId) { alert('請選擇來源與目標員工'); return; }
            if (srcId === tgtId) { alert('來源與目標不可為同一人'); return; }
            var srcName = $('#copy-perm-source option:selected').text();
            var tgtName = $('#copy-perm-target option:selected').text();
            var modeText = mode === 'overwrite'
                ? '覆蓋：會先清空「' + tgtName + '」原本所有的角色與模組權限，再完全比照「' + srcName + '」'
                : '合併：只補上「' + tgtName + '」目前沒有的設定，保留他原本已有的其他設定';
            if (!confirm('確定要把「' + srcName + '」的權限設定複製給「' + tgtName + '」嗎？\n\n' + modeText + '\n\n（不含頁面白名單等個別例外設定）')) return;
            var $btn = $(this).prop('disabled', true);
            $.post(ROLES_API, { action: 'copy_user_permissions', source_user_id: srcId, target_user_id: tgtId, mode: mode })
            .done(function(res) {
                alert(res.success ? res.message : ('複製失敗：' + (res.message || '未知錯誤')));
                if (res.success) location.reload();
            })
            .fail(function(xhr) { alert('連線失敗（' + xhr.status + '）：' + xhr.responseText.substring(0, 200)); })
            .always(function() { $btn.prop('disabled', false); });
        });

        function roleFilterTable(p) {
            var kw   = ($('#' + p + '-search-name').val() || '').toLowerCase().trim();
            var dept = ($('#' + p + '-search-dept').val() || '').trim();
            var rows = $('#' + p + '-role-tbody tr');
            var visible = 0;
            rows.each(function() {
                var name  = ($(this).data('name') || '').toLowerCase();
                var depts = ($(this).data('dept') || '').split('|').map(function(s){ return s.trim(); });
                var deptMatch = !dept || depts.indexOf(dept) !== -1;
                var show = (!kw || name.indexOf(kw) !== -1) && deptMatch;
                $(this).toggle(show);
                if (show) visible++;
            });
            var total = rows.length;
            $('#' + p + '-filter-count').text(visible < total ? '顯示 ' + visible + ' / ' + total + ' 人' : '共 ' + total + ' 人');
        }

        function roleClearSearch(p) {
            $('#' + p + '-search-name').val('');
            $('#' + p + '-search-dept').val('');
            roleFilterTable(p);
        }

        // 頁面載入後顯示各區塊總人數
        $(document).ready(function() {
            ['quot', 'notice', 'oready', 'bomtrk', 'ptask', 'asdoc'].forEach(function(p) {
                var total = $('#' + p + '-role-tbody tr').length;
                if (total > 0) $('#' + p + '-filter-count').text('共 ' + total + ' 人');
            });

            // 快速切換：平滑捲動至各設定區塊（避開凍結的快速切換列本身）
            // 快速切換列自動補漏：頁面上任何 id 結尾為 -role-section 的區塊，若上面的 $_navItems 沒登記，
            // 就自動補一顆按鈕。（新模組自動長出的角色區塊也吃這條，不必回頭維護那份清單＝鐵律4）
            (function autoFillQuickNav() {
                var $bar = $('#quick-nav-block .x_panel > div');
                if (!$bar.length) return;
                var have = {};
                $bar.find('.quick-nav-link').each(function(){ have[$(this).data('target')] = 1; });
                $('[id$="-role-section"]').each(function() {
                    var id = this.id;
                    if (have[id]) return;
                    var label = $.trim($(this).find('.x_title h2').first().clone().children('small').remove().end().text()) || id;
                    $bar.append($('<a class="btn btn-xs btn-default quick-nav-link"></a>')
                        .attr('href', '#' + id).attr('data-target', id).text(label));
                    have[id] = 1;
                });
            })();

            // 快速切換列預設壓成兩行：它是黏在視窗頂端的，太高會把要看的內容擠出畫面
            window.qnToggle = function() {
                var $g = $('#qn-groups'), open = $g.data('open') ? false : true;
                $g.data('open', open).css('max-height', open ? '300px' : '60px');
                $('#qn-toggle').html(open ? '<i class="fa fa-angle-double-up"></i> 收合'
                                          : '<i class="fa fa-angle-double-down"></i> 展開全部');
            };

            $(document).on('click', '.quick-nav-link', function(e) {
                e.preventDefault();
                var target = $('#' + $(this).data('target'));
                if (target.length) {
                    var stickyH = $('#quick-nav-block').outerHeight() || 50;
                    $('html, body').stop().animate({ scrollTop: target.offset().top - stickyH - 10 }, 500);
                }
            });

            // Excel 凍結窗格式固定：捲過原位置後 fixed 貼齊視窗頂端，原位放佔位元素避免內容跳動
            var $qn = $('#quick-nav-block');
            if ($qn.length) {
                var $qnPh = $('<div id="quick-nav-placeholder" style="display:none;"></div>').insertAfter($qn);
                var qnFixed = false;
                function qnGetTop() { return (qnFixed ? $qnPh : $qn).offset().top; }
                function qnUpdate() {
                    var shouldFix = $(window).scrollTop() > qnGetTop();
                    if (shouldFix && !qnFixed) {
                        qnFixed = true;
                        $qnPh.height($qn.outerHeight(true)).show();
                        $qn.css({
                            position: 'fixed', top: 0, zIndex: 1050,
                            left: $qnPh.offset().left - $(window).scrollLeft(),
                            width: $qnPh.outerWidth(), margin: 0
                        }).find('.x_panel').css('box-shadow', '0 2px 8px rgba(0,0,0,.25)');
                    } else if (!shouldFix && qnFixed) {
                        qnFixed = false;
                        $qn.css({ position: '', top: '', zIndex: '', left: '', width: '', margin: '' })
                           .find('.x_panel').css('box-shadow', '');
                        $qnPh.hide();
                    } else if (qnFixed) {
                        // 視窗寬度改變時跟著佔位元素調整
                        $qn.css({ left: $qnPh.offset().left - $(window).scrollLeft(), width: $qnPh.outerWidth() });
                    }
                }
                $(window).on('scroll resize', qnUpdate);
                qnUpdate();
            }
        });

        // ══ 人員權限總覽（依人員查看）══
        function pvEsc(t) { return $('<div>').text(t == null ? '' : t).html(); }

        function pvLoad() {
            var uid = $('#pv-user').val();
            if (!uid) { $('#pv-result').hide(); $('#pv-empty').show(); return; }
            $('#pv-loading').show(); $('#pv-empty').hide();
            $.get(ROLES_API, { action:'get_user_profile', user_id:uid })
            .done(function(res) {
                if (!res || !res.success) { alert('載入失敗：' + ((res && res.message) || '未知錯誤')); return; }
                $('#pv-result').html(pvRender(res)).show();
            })
            .fail(function(xhr) { alert('連線失敗（' + xhr.status + '）：' + xhr.responseText.substring(0, 200)); })
            .always(function() { $('#pv-loading').hide(); });
        }

        function pvRender(d) {
            var h = '';
            // 標頭
            h += '<div style="margin-bottom:10px;font-size:14px;">';
            h += '<strong style="font-size:16px;">' + pvEsc(d.user.user_cname) + '</strong> ';
            h += '<span style="color:#888;">' + pvEsc(d.user.user_uname) + '</span> ';
            h += d.user.active == 1
                 ? '<span class="label label-success" style="font-size:11px;">在職</span>'
                 : '<span class="label label-danger" style="font-size:11px;">非在職（一律無任何權限）</span>';
            h += ' <span class="text-muted" style="font-size:12px;margin-left:8px;">目前生效功能碼 ' + (d.features || []).length + ' 個</span>';
            h += '</div>';

            // 代理狀態
            if ((d.delegate_in && d.delegate_in.length) || (d.delegate_out && d.delegate_out.length)) {
                h += '<div style="background:#FFF3E2;border:1px solid #E4D3BC;border-radius:3px;padding:8px 10px;margin-bottom:10px;font-size:12px;line-height:1.7;">';
                h += '<strong><i class="fa fa-exchange"></i> 目前的代理狀態</strong><br>';
                (d.delegate_in || []).forEach(function(x) {
                    h += '・代理 <strong>' + pvEsc(x.employee_name) + '</strong> 的「' + pvEsc(x.scope_label || ((x.scope_department||'') + ' ' + (x.scope_position||'')) || '全部職務') + '」';
                    h += '（' + pvEsc(x.type_name) + '　' + pvEsc(String(x.start_datetime).substring(0,10)) + ' ~ ' + pvEsc(String(x.end_datetime).substring(0,10)) + '）';
                    h += x.full_inherit_permission == 1
                         ? ' <span class="label label-warning" style="font-size:10px;">完整承接權限：期間內借用該職稱的角色</span>'
                         : ' <span class="label label-default" style="font-size:10px;">僅代理簽核，不承接頁面權限</span>';
                    h += '<br>';
                });
                (d.delegate_out || []).forEach(function(x) {
                    h += '・本人請假中，由 <strong>' + pvEsc(x.agent_name) + '</strong> 代理「' + pvEsc(x.scope_label || ((x.scope_department||'') + ' ' + (x.scope_position||'')) || '全部職務') + '」';
                    h += '（' + pvEsc(x.type_name) + '　' + pvEsc(String(x.start_datetime).substring(0,10)) + ' ~ ' + pvEsc(String(x.end_datetime).substring(0,10)) + '）<br>';
                });
                h += '</div>';
            }

            // 身分（部門＋職稱）
            h += '<div style="font-weight:600;font-size:13px;margin:12px 0 6px;"><i class="fa fa-sitemap"></i> 職務身分與各身分帶到的角色</div>';
            if (!d.identities || !d.identities.length) {
                h += '<div class="text-muted" style="font-size:12px;">（未掛任何部門職稱）</div>';
            } else {
                h += '<table class="table table-bordered table-condensed" style="font-size:12px;"><thead style="background:#f8f9fa;">';
                h += '<tr><th style="width:130px;">部門</th><th style="width:110px;">職稱</th><th style="width:70px;">身分</th><th>此身分帶到的角色（來自部門×職稱設定）</th></tr></thead><tbody>';
                d.identities.forEach(function(i) {
                    h += '<tr><td>' + pvEsc(i.department_name) + '</td><td>' + pvEsc(i.position_name) + '</td>';
                    h += '<td>' + (i.is_main == 1 ? '主要' : '<span style="color:#e67e22;">兼任</span>') + '</td><td>';
                    if (!i.roles || !i.roles.length) {
                        h += '<span class="text-muted">（此編制尚未設定角色）</span>';
                    } else {
                        i.roles.forEach(function(r) {
                            h += '<span class="label label-success" style="margin-right:4px;padding:3px 7px;display:inline-block;">';
                            h += '<span style="opacity:.75;font-size:11px;">' + pvEsc(egModLabel(r.module)) + '</span> ' + pvEsc(r.role_name);
                            if (r.scope === '全部門通用') h += ' <span style="opacity:.7;font-size:10px;">(通用)</span>';
                            h += '</span>';
                        });
                    }
                    h += '</td></tr>';
                });
                h += '</tbody></table>';
            }

            // 逐模組生效結果
            h += '<div style="font-weight:600;font-size:13px;margin:14px 0 6px;"><i class="fa fa-check-square-o"></i> 逐模組生效權限與來源</div>';
            if (!d.effective || !d.effective.length) {
                h += '<div class="text-muted" style="font-size:12px;">（目前沒有任何模組角色）</div>';
            } else {
                h += '<table class="table table-bordered table-condensed" style="font-size:12px;"><thead style="background:#f8f9fa;">';
                h += '<tr><th style="width:160px;">模組</th><th style="width:90px;">來源</th><th>生效角色</th></tr></thead><tbody>';
                d.effective.forEach(function(e) {
                    h += '<tr><td>' + pvEsc(egModLabel(e.module)) + '</td>';
                    h += '<td>' + (e.source === 'personal'
                          ? '<span class="label label-primary" style="font-size:11px;">個人</span>'
                          : '<span class="label label-success" style="font-size:11px;">部門職稱</span>') + '</td>';
                    h += '<td>' + e.roles.map(pvEsc).join('、');
                    if (e.shadowed && e.shadowed.length) {
                        h += '<div style="font-size:11px;color:#999;margin-top:2px;"><i class="fa fa-level-down"></i> 因個人指派而不套用：'
                             + e.shadowed.map(pvEsc).join('、') + '</div>';
                    }
                    h += '</td></tr>';
                });
                h += '</tbody></table>';
            }

            // 個人指派原始清單
            h += '<div style="font-weight:600;font-size:13px;margin:14px 0 6px;"><i class="fa fa-user"></i> 個人指派的角色（在上面各模組區塊設定）</div>';
            if (!d.personal || !d.personal.length) {
                h += '<div class="text-muted" style="font-size:12px;">（無，完全依部門×職稱設定）</div>';
            } else {
                d.personal.forEach(function(r) {
                    h += '<span class="label ' + (r.is_system == 1 ? 'label-danger' : 'label-primary') + '" style="margin:0 4px 4px 0;padding:3px 7px;display:inline-block;">';
                    if (r.module) h += '<span style="opacity:.75;font-size:11px;">' + pvEsc(egModLabel(r.module)) + '</span> ';
                    h += pvEsc(r.role_name) + '</span>';
                });
            }
            return h;
        }

        // ══ 部門 × 職稱 角色設定 ══
        // 設定一次，該編制底下的在職人員自動具備（個人指派在同一個模組會覆蓋這裡）
        function dpRoleAssign(deptId, positionId) {
            var key = 'dp-' + deptId + '_' + positionId;
            var roleId = $('#' + key + '-sel').val();
            if (!roleId) { alert('請先選擇角色'); return; }
            var $btn = $('#' + key + '-sel').closest('.input-group').find('button');
            $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i>');
            $.post(ROLES_API, { action:'assign_position_role', department_id:deptId, position_id:positionId, role_id:roleId })
            .done(function(res) {
                if (!res.success) { alert('指派失敗：' + (res.message || '未知錯誤')); return; }
                dpRoleReloadRow(deptId, positionId);
                $('#' + key + '-sel').val('');
            })
            .fail(function(xhr) { alert('連線失敗（' + xhr.status + '）：' + xhr.responseText.substring(0, 200)); })
            .always(function() { $btn.prop('disabled', false).html('<i class="fa fa-plus"></i> 指派'); });
        }

        function dpRoleRemove(deptId, positionId, roleId) {
            var NL = String.fromCharCode(10);
            var who = (deptId === 0) ? '所有部門的這個職稱' : '這個部門的這個職稱';
            if (!confirm('確認移除？' + NL + who + '底下的在職人員將同時失去此角色的功能。' + NL + '（在上面各模組區塊「個別指派」過這個模組角色的人不受影響。）')) return;
            $.post(ROLES_API, { action:'remove_position_role', department_id:deptId, position_id:positionId, role_id:roleId })
            .done(function(res) {
                if (!res.success) { alert('移除失敗：' + (res.message || '未知錯誤')); return; }
                dpRoleReloadRow(deptId, positionId);
            })
            .fail(function(xhr) { alert('連線失敗（' + xhr.status + '）：' + xhr.responseText.substring(0, 200)); });
        }

        function dpRoleReloadRow(deptId, positionId) {
            $.get(ROLES_API, { action:'get_positions' })
            .done(function(res) {
                if (!res.success) return;
                var list = (deptId === 0)
                    ? ((res.data || []).filter(function(p){ return p.id == positionId; })[0] || {}).roles
                    : (((res.dept_positions || []).filter(function(dp){ return dp.department_id == deptId && dp.position_id == positionId; })[0] || {}).roles);
                var $cell = $('#dp-' + deptId + '_' + positionId + '-tags');
                var $tr   = $cell.closest('tr');
                if (!list || !list.length) {
                    $cell.html('<span class="text-muted" style="font-size:12px;">（未設定）</span>');
                    $tr.attr('data-hasrole', '0');
                    dpFilter();
                    return;
                }
                var html = '';
                list.forEach(function(r) {
                    html += '<span class="label label-primary" style="margin-right:4px;font-size:12px;padding:3px 7px;display:inline-block;">';
                    if (r.module) html += '<span style="opacity:.75;font-size:11px;">' + egModLabel(r.module) + '</span> ';
                    html += r.role_name;
                    html += ' <a href="#" onclick="dpRoleRemove(' + deptId + ',' + positionId + ',' + r.role_id + ');return false;" style="color:#fff;margin-left:4px;opacity:.8;" title="移除此角色">&times;</a>';
                    html += '</span>';
                });
                $cell.html(html);
                $tr.attr('data-hasrole', '1');
                dpFilter();
            })
            .fail(function(xhr) { console.error('dpRoleReloadRow failed:', xhr.responseText); });
        }

        // ── 部門×職稱：分組摺疊、勾選與批次設定 ────────────────────────────────
        function dpRows()  { return $('#dp-role-tbody tr.dp-row, #dp-anydept-tbody tr.dp-row'); }
        function dpVisible($tr) { return $tr.css('display') !== 'none'; }

        function dpSelected() {
            var out = [];
            $('.dp-chk:checked').each(function() {
                var $tr = $(this).closest('tr');
                if (!dpVisible($tr)) return;                       // 被篩選掉的不算
                if ($tr.hasClass('dp-collapsed')) return;          // 被收合起來的也不算
                out.push($(this).val());
            });
            return out;
        }

        function dpUpdateCount() {
            var n = dpSelected().length;
            $('#dp-sel-count').text(n);
            $('#dp-actionbar').toggle(n > 0);                      // 勾了才浮出操作列
            $('body').css('padding-bottom', n > 0 ? '70px' : '');  // 不要蓋住頁尾內容
        }

        function dpClearSel() {
            $('.dp-chk, .dp-group-chk').prop('checked', false);
            dpUpdateCount();
        }

        // 部門標題列的勾選：連動該部門底下所有職稱
        function dpToggleGroupChk(el) {
            var g = $(el).data('group');
            $('#dp-role-tbody tr.dp-row[data-group="' + g + '"]').each(function() {
                if (!dpVisible($(this))) return;
                $(this).find('.dp-chk').prop('checked', el.checked);
            });
            dpUpdateCount();
        }

        // 部門分組摺疊
        function dpToggleGroup(g) {
            var $rows = $('#dp-role-tbody tr.dp-row[data-group="' + g + '"]');
            var hide = !$rows.first().hasClass('dp-collapsed');
            $rows.toggleClass('dp-collapsed', hide);
            $('.dp-caret[data-group="' + g + '"]').toggleClass('fa-caret-down', !hide).toggleClass('fa-caret-right', hide);
            dpFilter();
        }

        function dpExpandAll(open) {
            $('#dp-role-tbody tr.dp-row').toggleClass('dp-collapsed', !open);
            $('.dp-caret').toggleClass('fa-caret-down', open).toggleClass('fa-caret-right', !open);
            dpFilter();
        }

        // 列上的「＋ 加角色」：勾起該列並把焦點帶到底部操作列的角色下拉
        function dpQuickAdd(key) {
            $('.dp-chk[value="' + key + '"]').prop('checked', true);
            dpUpdateCount();
            var $sel = $('#dp-bulk-role');
            var $filter = $sel.prev('input');                      // eg_input_rules 產生的篩選框
            ($filter.length ? $filter : $sel).focus();
        }

        function dpFilter() {
            var kw = ($('#dp-search').val() || '').toLowerCase().trim().split(/\s+/).filter(Boolean);
            var onlySet = $('#dp-only-set').is(':checked');
            var shown = 0, total = 0;
            dpRows().each(function() {
                var $tr = $(this), hay = $tr.attr('data-search') || '';
                total++;
                var ok = kw.every(function(k){ return hay.indexOf(k) !== -1; });
                if (ok && onlySet && $tr.attr('data-hasrole') !== '1') ok = false;
                // 有輸入搜尋字時，收合中的分組自動展開，否則會「搜尋不到明明存在的列」
                if (ok && $tr.hasClass('dp-collapsed')) {
                    if (kw.length) $tr.removeClass('dp-collapsed');
                    else ok = false;
                }
                $tr.toggle(ok);
                if (ok) shown++;
            });
            // 部門標題列：底下一列都沒顯示、又不是收合狀態，就把標題也藏起來
            $('#dp-role-tbody tr.dp-group').each(function() {
                var g = $(this).data('group');
                var $kids = $('#dp-role-tbody tr.dp-row[data-group="' + g + '"]');
                var any = $kids.filter(function(){ return dpVisible($(this)); }).length;
                var collapsed = $kids.first().hasClass('dp-collapsed');
                $(this).toggle(any > 0 || collapsed);
            });
            $('#dp-filter-count').text('顯示 ' + shown + ' / ' + total + ' 組');
            dpUpdateCount();
        }

        function dpCopyTypeChange() {
            var t = $('#dp-copy-type').val();
            $('#dp-copy-user').toggle(t === 'user');
            $('#dp-copy-pos').toggle(t === 'position');
            $('#dp-copy-user, #dp-copy-pos').each(function() {
                var $f = $(this).prev('input');
                if ($f.length) $f.toggle($(this).is(':visible'));
            });
        }

        function dpBulk(op) {
            var NL = String.fromCharCode(10);
            var targets = dpSelected();
            if (!targets.length) { alert('請先勾選要一起設定的部門×職稱'); return; }

            var data = { action:'bulk_position_roles', op:op, targets: JSON.stringify(targets) };
            var confirmMsg = '';
            if (op === 'assign' || op === 'remove') {
                var rid = $('#dp-bulk-role').val();
                if (!rid) { alert('請先選擇角色'); return; }
                data.role_id = rid;
                var rname = $('#dp-bulk-role option:selected').text();
                confirmMsg = (op === 'assign' ? '確認把角色「' : '確認從勾選的編制移除角色「') + rname + '」'
                           + (op === 'assign' ? '指派給勾選的 ' : '？共 ') + targets.length + ' 組編制？';
            } else {
                var t = $('#dp-copy-type').val();
                data.source_type = t;
                var srcName = '';
                if (t === 'user') {
                    var suid = $('#dp-copy-user').val();
                    if (!suid) { alert('請先選擇來源人員'); return; }
                    data.source_user_id = suid;
                    srcName = '人員「' + $('#dp-copy-user option:selected').text() + '」';
                } else {
                    var key = $('#dp-copy-pos').val();
                    if (!key) { alert('請先選擇來源編制'); return; }
                    var kp = key.split('_');
                    data.source_department_id = kp[0];
                    data.source_position_id = kp[1];
                    srcName = '編制「' + $('#dp-copy-pos option:selected').text() + '」';
                }
                data.mode = $('#dp-copy-mode').val();
                confirmMsg = '確認把 ' + srcName + ' 的角色複製到勾選的 ' + targets.length + ' 組編制？' + NL
                           + (data.mode === 'overwrite'
                              ? '※ 覆蓋模式：目標編制原本的角色會先全部清除。'
                              : '※ 合併模式：保留目標原有設定，只補上缺少的。');
            }
            confirmMsg += NL + NL + '提醒：在下面各模組區塊「個別指派」過該模組角色的人，仍以個人設定為準，不受影響。';
            if (!confirm(confirmMsg)) return;

            var $btns = $('#dp-actionbar button').prop('disabled', true);
            $.post(ROLES_API, data)
            .done(function(res) {
                if (!res || !res.success) { alert('操作失敗：' + ((res && res.message) || '未知錯誤')); return; }
                alert(res.message + NL + NL + '頁面將重新整理以顯示最新結果。');
                location.reload();
            })
            .fail(function(xhr) { alert('連線失敗（' + xhr.status + '）：' + xhr.responseText.substring(0, 200)); })
            .always(function() { $btns.prop('disabled', false); });
        }

        // ══ AS9100 文件管理：職稱角色指派 ══
        function posRoleAssign(positionId) {
            var roleId = $('#asdocpos-sel-' + positionId).val();
            if (!roleId) { alert('請先選擇角色'); return; }
            var $btn = $('#asdocpos-sel-' + positionId).closest('.input-group').find('button');
            $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i>');
            $.post(ROLES_API, { action:'assign_position_role', position_id:positionId, role_id:roleId })
            .done(function(res) {
                if (!res.success) { alert('指派失敗：' + (res.message || '未知錯誤')); return; }
                posRoleReloadRow(positionId);
                $('#asdocpos-sel-' + positionId).val('');
            })
            .fail(function(xhr) { alert('連線失敗（' + xhr.status + '）：' + xhr.responseText.substring(0, 200)); })
            .always(function() { $btn.prop('disabled', false).html('<i class="fa fa-plus"></i> 指派'); });
        }

        function posRoleRemove(positionId, roleId) {
            if (!confirm('確認移除此職稱的這個角色？\n該職稱所有人員將同時失去此角色的功能（個人另有指派者不受影響）。')) return;
            $.post(ROLES_API, { action:'remove_position_role', position_id:positionId, role_id:roleId })
            .done(function(res) {
                if (!res.success) { alert('移除失敗：' + (res.message || '未知錯誤')); return; }
                posRoleReloadRow(positionId);
            })
            .fail(function(xhr) { alert('連線失敗（' + xhr.status + '）：' + xhr.responseText.substring(0, 200)); });
        }

        function posRoleReloadRow(positionId) {
            $.get(ROLES_API, { action:'get_positions', module:'as_doc' })
            .done(function(res) {
                if (!res.success) return;
                var pos = res.data.find(function(p){ return p.id == positionId; });
                if (!pos) return;
                var $cell = $('#asdocpos-tags-' + positionId);
                if (!pos.roles || !pos.roles.length) {
                    $cell.html('<span class="text-muted" style="font-size:12px;">（未指派）</span>');
                    return;
                }
                var html = '';
                pos.roles.forEach(function(r) {
                    html += '<span class="label label-primary" style="margin-right:4px;font-size:12px;padding:3px 7px;display:inline-block;">';
                    html += r.role_name;
                    html += ' <a href="#" onclick="posRoleRemove(' + positionId + ',' + r.role_id + ');return false;" style="color:#fff;margin-left:4px;opacity:.8;" title="移除此角色">&times;</a>';
                    html += '</span>';
                });
                $cell.html(html);
            })
            .fail(function(xhr) { console.error('posRoleReloadRow failed:', xhr.responseText); });
        }
        // ══════════════════════════════════════════════════════════════

        function roleAssign(p, module, userId) {
            var roleId = $('#' + p + '-sel-' + userId).val();
            if (!roleId) { alert('請先選擇角色'); return; }
            var $btn = $('#' + p + '-sel-' + userId).closest('.input-group').find('button');
            $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i>');
            $.post(ROLES_API, { action:'assign_user_role', user_id:userId, role_id:roleId })
            .done(function(res) {
                if (!res.success) { alert('指派失敗：' + (res.message || '未知錯誤')); return; }
                roleReloadRow(p, module, userId);
                $('#' + p + '-sel-' + userId).val('');
            })
            .fail(function(xhr) { alert('連線失敗（' + xhr.status + '）：' + xhr.responseText.substring(0, 200)); })
            .always(function() { $btn.prop('disabled', false).html('<i class="fa fa-plus"></i> 指派'); });
        }

        function roleRemove(p, module, userId, roleId, isAdm) {
            var msg = isAdm
                ? '「管理員」是全域系統角色，移除後此使用者在「所有模組」（報價單／公告通知／首頁／品管檢驗）的管理員權限都會一併消失。\n\n確認移除？'
                : '確認移除此角色？';
            if (!confirm(msg)) return;
            $.post(ROLES_API, { action:'remove_user_role', user_id:userId, role_id:roleId })
            .done(function(res) {
                if (!res.success) { alert('移除失敗：' + (res.message || '未知錯誤')); return; }
                roleReloadRow(p, module, userId);
            })
            .fail(function(xhr) { alert('連線失敗（' + xhr.status + '）：' + xhr.responseText.substring(0, 200)); });
        }

        function roleReloadRow(p, module, userId) {
            $.get(ROLES_API, { action:'get_users', module: module })
            .done(function(res) {
                if (!res.success) return;
                var user = res.data.find(function(u){ return u.id == userId; });
                if (!user) return;
                var $cell = $('#' + p + '-tags-' + userId);
                if (!user.roles || !user.roles.length) {
                    $cell.html('<span class="text-muted" style="font-size:12px;">（未指派）</span>');
                    return;
                }
                var html = '';
                user.roles.forEach(function(r) {
                    var isAdm = r.role_name === '管理員';
                    html += '<span class="label ' + (isAdm ? 'label-danger' : 'label-primary') + '" style="margin-right:4px;font-size:12px;padding:3px 7px;display:inline-block;">';
                    html += r.role_name;
                    html += ' <a href="#" onclick="roleRemove(\'' + p + '\',\'' + module + '\',' + userId + ',' + r.role_id + ',' + (isAdm ? 1 : 0) + ');return false;" style="color:#fff;margin-left:4px;opacity:.8;" title="移除此角色">&times;</a>';
                    html += '</span>';
                });
                $cell.html(html);
            })
            .fail(function(xhr) { console.error('roleReloadRow failed:', xhr.responseText); });
        }
        // ══════════════════════════════════════════════════════════════
    </script>
    <button class="scroll-to-top" onclick="window.scrollTo({top:0,behavior:'smooth'})">回頂端</button>
</body>

</html>