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

// ── 角色指派資料（依模組分開：報價單 / 公告通知；系統角色 admin 兩者皆顯示）──────
$_quotRoles     = [];  $_userQuotRoles   = [];
$_noticeRoles   = [];  $_userNoticeRoles = [];
$_homeRoles     = [];  $_userHomeRoles   = [];
$_qcRoles       = [];  $_userQcRoles     = [];
$_carRoles      = [];  $_userCarRoles    = [];
$_imgRoles      = [];  $_userImgRoles    = [];
$_drawRoles     = [];  $_userDrawRoles   = [];
$_bomRenRoles   = [];  $_userBomRenRoles = [];
$_oreadyRoles   = [];  $_userOreadyRoles = [];
$_bomtrkRoles   = [];  $_userBomtrkRoles = [];
$_ptaskRoles    = [];  $_userPtaskRoles  = [];
$_profitRoles   = [];  $_userProfitRoles = [];
$_asdocRoles    = [];  $_userAsdocRoles  = [];
$_mdataRoles    = [];  $_userMdataRoles  = [];
$_dbbkRoles     = [];  $_userDbbkRoles   = [];
$_stampRoles    = [];  $_userStampRoles  = [];
$_rosterRoles   = [];  $_userRosterRoles = [];
$_dcRoles       = [];  $_userDcRoles     = [];
$_tcalRoles     = [];  $_userTcalRoles   = [];
$_trainRoles    = [];  $_userTrainRoles  = [];
$_meetRoles     = [];  $_userMeetRoles   = [];
$_vaudRoles     = [];  $_userVaudRoles   = [];
$_leaveRoles    = [];  $_userLeaveRoles  = [];
$_shipRoles     = [];  $_userShipRoles   = [];
$_accRoles      = [];  $_userAccRoles    = [];
$_purcRoles     = [];  $_userPurcRoles   = [];
$_kpiRoles      = [];  $_userKpiRoles    = [];
$_extdocRoles   = [];  $_userExtdocRoles = [];
$_otrkRoles     = [];  $_userOtrkRoles   = [];
$_rvfRoles      = [];  $_userRvfRoles    = [];
$_tidcRoles     = [];  $_userTidcRoles   = [];
$_tdevRoles     = [];  $_userTdevRoles   = [];
$_pfmeaRoles    = [];  $_userPfmeaRoles  = [];
$_hrfRoles      = [];  $_userHrfRoles    = [];
$_fsdRoles      = [];  $_userFsdRoles    = [];
$_asdocPositions = []; $_asdocPosRoles   = [];
$_quotDepts     = [];

// 各模組角色清單（含系統角色 admin）
try {
    $st = $conn_pdo->prepare("SELECT role_id, role_name, is_system FROM roles WHERE module=? OR is_system=1 ORDER BY is_system DESC, role_id ASC");
    $st->execute(['quotation']); $_quotRoles = $st->fetchAll(PDO::FETCH_ASSOC);
    $st->execute(['notice']);    $_noticeRoles = $st->fetchAll(PDO::FETCH_ASSOC);
    $st->execute(['homepage']);  $_homeRoles = $st->fetchAll(PDO::FETCH_ASSOC);
    $st->execute(['qc']);        $_qcRoles = $st->fetchAll(PDO::FETCH_ASSOC);
    $st->execute(['car']);       $_carRoles = $st->fetchAll(PDO::FETCH_ASSOC);
    $st->execute(['imgedit']);   $_imgRoles = $st->fetchAll(PDO::FETCH_ASSOC);
    $st->execute(['drawing_rename']); $_drawRoles = $st->fetchAll(PDO::FETCH_ASSOC);
    $st->execute(['bom_rename']); $_bomRenRoles = $st->fetchAll(PDO::FETCH_ASSOC);
    $st->execute(['oready']);    $_oreadyRoles = $st->fetchAll(PDO::FETCH_ASSOC);
    $st->execute(['bom_track']); $_bomtrkRoles = $st->fetchAll(PDO::FETCH_ASSOC);
    $st->execute(['personal_task']); $_ptaskRoles = $st->fetchAll(PDO::FETCH_ASSOC);
    $st->execute(['order_profit']); $_profitRoles = $st->fetchAll(PDO::FETCH_ASSOC);
    $st->execute(['part_process_report']); $_pprRoles = $st->fetchAll(PDO::FETCH_ASSOC);
    $st->execute(['as_doc']);    $_asdocRoles = $st->fetchAll(PDO::FETCH_ASSOC);
    $st->execute(['master_data']); $_mdataRoles = $st->fetchAll(PDO::FETCH_ASSOC);
    $st->execute(['db_backup']);   $_dbbkRoles = $st->fetchAll(PDO::FETCH_ASSOC);
    $st->execute(['stamp']);       $_stampRoles = $st->fetchAll(PDO::FETCH_ASSOC);
    $st->execute(['kpi']);         $_kpiRoles = $st->fetchAll(PDO::FETCH_ASSOC);
    $st->execute(['roster']);      $_rosterRoles = $st->fetchAll(PDO::FETCH_ASSOC);
    $st->execute(['data_console']);$_dcRoles = $st->fetchAll(PDO::FETCH_ASSOC);
    $st->execute(['tool_calib']);  $_tcalRoles = $st->fetchAll(PDO::FETCH_ASSOC);
    $st->execute(['training']);    $_trainRoles = $st->fetchAll(PDO::FETCH_ASSOC);
    $st->execute(['meeting']);     $_meetRoles = $st->fetchAll(PDO::FETCH_ASSOC);
    $st->execute(['vendor_audit']);$_vaudRoles = $st->fetchAll(PDO::FETCH_ASSOC);
    $st->execute(['leave']);       $_leaveRoles = $st->fetchAll(PDO::FETCH_ASSOC);
    $st->execute(['shipping']);    $_shipRoles = $st->fetchAll(PDO::FETCH_ASSOC);
    $st->execute(['purchase']);    $_purcRoles = $st->fetchAll(PDO::FETCH_ASSOC);
    $st->execute(['accounting']);  $_accRoles = $st->fetchAll(PDO::FETCH_ASSOC);
    $st->execute(['external_doc']);$_extdocRoles = $st->fetchAll(PDO::FETCH_ASSOC);
    $st->execute(['order_track']); $_otrkRoles = $st->fetchAll(PDO::FETCH_ASSOC);
    $st->execute(['review_form']); $_rvfRoles = $st->fetchAll(PDO::FETCH_ASSOC);
    $st->execute(['type_id_ctrl']); $_tidcRoles = $st->fetchAll(PDO::FETCH_ASSOC);
    $st->execute(['td_dev_eval']); $_tdevRoles = $st->fetchAll(PDO::FETCH_ASSOC);
    $st->execute(['pfmea']); $_pfmeaRoles = $st->fetchAll(PDO::FETCH_ASSOC);
    $st->execute(['hr_form']); $_hrfRoles = $st->fetchAll(PDO::FETCH_ASSOC);
    $st->execute(['form_signer']); $_fsdRoles = $st->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $_e) {}

// 使用者已指派角色（依模組過濾）
try {
    $st = $conn_pdo->prepare("
        SELECT ur.user_id, r.role_id, r.role_name
        FROM user_roles ur JOIN roles r ON r.role_id = ur.role_id
        WHERE r.module=? OR r.is_system=1");
    $st->execute(['quotation']);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $_r) {
        $_userQuotRoles[$_r['user_id']][] = ['role_id'=>$_r['role_id'], 'role_name'=>$_r['role_name']];
    }
    $st->execute(['notice']);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $_r) {
        $_userNoticeRoles[$_r['user_id']][] = ['role_id'=>$_r['role_id'], 'role_name'=>$_r['role_name']];
    }
    $st->execute(['homepage']);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $_r) {
        $_userHomeRoles[$_r['user_id']][] = ['role_id'=>$_r['role_id'], 'role_name'=>$_r['role_name']];
    }
    $st->execute(['qc']);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $_r) {
        $_userQcRoles[$_r['user_id']][] = ['role_id'=>$_r['role_id'], 'role_name'=>$_r['role_name']];
    }
    $st->execute(['car']);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $_r) {
        $_userCarRoles[$_r['user_id']][] = ['role_id'=>$_r['role_id'], 'role_name'=>$_r['role_name']];
    }
    $st->execute(['imgedit']);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $_r) {
        $_userImgRoles[$_r['user_id']][] = ['role_id'=>$_r['role_id'], 'role_name'=>$_r['role_name']];
    }
    $st->execute(['drawing_rename']);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $_r) {
        $_userDrawRoles[$_r['user_id']][] = ['role_id'=>$_r['role_id'], 'role_name'=>$_r['role_name']];
    }
    $st->execute(['bom_rename']);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $_r) {
        $_userBomRenRoles[$_r['user_id']][] = ['role_id'=>$_r['role_id'], 'role_name'=>$_r['role_name']];
    }
    $st->execute(['oready']);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $_r) {
        $_userOreadyRoles[$_r['user_id']][] = ['role_id'=>$_r['role_id'], 'role_name'=>$_r['role_name']];
    }
    $st->execute(['bom_track']);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $_r) {
        $_userBomtrkRoles[$_r['user_id']][] = ['role_id'=>$_r['role_id'], 'role_name'=>$_r['role_name']];
    }
    $st->execute(['personal_task']);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $_r) {
        $_userPtaskRoles[$_r['user_id']][] = ['role_id'=>$_r['role_id'], 'role_name'=>$_r['role_name']];
    }
    $st->execute(['order_profit']);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $_r) {
        $_userProfitRoles[$_r['user_id']][] = ['role_id'=>$_r['role_id'], 'role_name'=>$_r['role_name']];
    }
    $st->execute(['part_process_report']);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $_r) {
        $_userPprRoles[$_r['user_id']][] = ['role_id'=>$_r['role_id'], 'role_name'=>$_r['role_name']];
    }
    $st->execute(['as_doc']);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $_r) {
        $_userAsdocRoles[$_r['user_id']][] = ['role_id'=>$_r['role_id'], 'role_name'=>$_r['role_name']];
    }
    $st->execute(['master_data']);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $_r) {
        $_userMdataRoles[$_r['user_id']][] = ['role_id'=>$_r['role_id'], 'role_name'=>$_r['role_name']];
    }
    $st->execute(['db_backup']);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $_r) {
        $_userDbbkRoles[$_r['user_id']][] = ['role_id'=>$_r['role_id'], 'role_name'=>$_r['role_name']];
    }
    $st->execute(['stamp']);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $_r) {
        $_userStampRoles[$_r['user_id']][] = ['role_id'=>$_r['role_id'], 'role_name'=>$_r['role_name']];
    }
    $st->execute(['kpi']);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $_r) {
        $_userKpiRoles[$_r['user_id']][] = ['role_id'=>$_r['role_id'], 'role_name'=>$_r['role_name']];
    }
    $st->execute(['roster']);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $_r) {
        $_userRosterRoles[$_r['user_id']][] = ['role_id'=>$_r['role_id'], 'role_name'=>$_r['role_name']];
    }
    $st->execute(['data_console']);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $_r) {
        $_userDcRoles[$_r['user_id']][] = ['role_id'=>$_r['role_id'], 'role_name'=>$_r['role_name']];
    }
    $st->execute(['tool_calib']);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $_r) {
        $_userTcalRoles[$_r['user_id']][] = ['role_id'=>$_r['role_id'], 'role_name'=>$_r['role_name']];
    }
    $st->execute(['training']);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $_r) {
        $_userTrainRoles[$_r['user_id']][] = ['role_id'=>$_r['role_id'], 'role_name'=>$_r['role_name']];
    }
    $st->execute(['meeting']);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $_r) {
        $_userMeetRoles[$_r['user_id']][] = ['role_id'=>$_r['role_id'], 'role_name'=>$_r['role_name']];
    }
    $st->execute(['vendor_audit']);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $_r) {
        $_userVaudRoles[$_r['user_id']][] = ['role_id'=>$_r['role_id'], 'role_name'=>$_r['role_name']];
    }
    $st->execute(['leave']);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $_r) {
        $_userLeaveRoles[$_r['user_id']][] = ['role_id'=>$_r['role_id'], 'role_name'=>$_r['role_name']];
    }
    $st->execute(['shipping']);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $_r) {
        $_userShipRoles[$_r['user_id']][] = ['role_id'=>$_r['role_id'], 'role_name'=>$_r['role_name']];
    }
    $st->execute(['purchase']);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $_r) {
        $_userPurcRoles[$_r['user_id']][] = ['role_id'=>$_r['role_id'], 'role_name'=>$_r['role_name']];
    }
    $st->execute(['accounting']);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $_r) {
        $_userAccRoles[$_r['user_id']][] = ['role_id'=>$_r['role_id'], 'role_name'=>$_r['role_name']];
    }
    $st->execute(['external_doc']);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $_r) {
        $_userExtdocRoles[$_r['user_id']][] = ['role_id'=>$_r['role_id'], 'role_name'=>$_r['role_name']];
    }
    $st->execute(['order_track']);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $_r) {
        $_userOtrkRoles[$_r['user_id']][] = ['role_id'=>$_r['role_id'], 'role_name'=>$_r['role_name']];
    }
    $st->execute(['review_form']);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $_r) {
        $_userRvfRoles[$_r['user_id']][] = ['role_id'=>$_r['role_id'], 'role_name'=>$_r['role_name']];
    }
    $st->execute(['type_id_ctrl']);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $_r) {
        $_userTidcRoles[$_r['user_id']][] = ['role_id'=>$_r['role_id'], 'role_name'=>$_r['role_name']];
    }
    $st->execute(['td_dev_eval']);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $_r) {
        $_userTdevRoles[$_r['user_id']][] = ['role_id'=>$_r['role_id'], 'role_name'=>$_r['role_name']];
    }
    $st->execute(['pfmea']);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $_r) {
        $_userPfmeaRoles[$_r['user_id']][] = ['role_id'=>$_r['role_id'], 'role_name'=>$_r['role_name']];
    }
    $st->execute(['hr_form']);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $_r) {
        $_userHrfRoles[$_r['user_id']][] = ['role_id'=>$_r['role_id'], 'role_name'=>$_r['role_name']];
    }
    $st->execute(['form_signer']);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $_r) {
        $_userFsdRoles[$_r['user_id']][] = ['role_id'=>$_r['role_id'], 'role_name'=>$_r['role_name']];
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
                                <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                                    <strong style="margin-right:4px;"><i class="fa fa-compass"></i> 快速切換：</strong>
                                    <?php
                                    $_navItems = [
                                        'perm-matrix-section'    => '人員權限設定',
                                        'quot-role-section'      => '報價單',
                                        'notice-role-section'    => '公告/通知',
                                        'home-role-section'      => '首頁設定',
                                        'qc-role-section'        => 'QC檢驗',
                                        'car-role-section'       => '異常矯正單',
                                        'mdata-role-section'     => '主檔管理',
                                        'imgedit-role-section'   => '批圖編輯器',
                                        'drawren-role-section'   => '圖面改檔名',
                                        'bomren-role-section'    => '叫料改檔名',
                                        'oready-role-section'    => '生管BOM',
                                        'bomtrk-role-section'    => 'BOM追蹤',
                                        'ptask-role-section'     => '個人工作紀錄',
                                        'asdoc-role-section'     => 'AS文件管理',
                                        'dbbk-role-section'      => '資料庫備份',
                                        'stamp-role-section'     => '圖章管理',
                                        'roster-role-section'    => '輪值排班',
                                        'kpi-role-section'        => 'KPI績效指標',
                                        'tcal-role-section'      => '量測儀器校驗',
                                        'train-role-section'     => '教育訓練',
                                        'meet-role-section'      => '會議紀錄',
                                        'vaud-role-section'      => '供應商稽核',
                                        'leave-role-section'     => '請假系統',
                                        'ship-role-section'      => '快速出貨',
                                        'purc-role-section'      => '申請採購',
                                        'acc-role-section'       => '會計',
                                        'extdoc-role-section'    => '外來文件清單',
                                        'otrk-role-section'      => '訂單追蹤',
                                        'rvf-role-section'       => '審核表單',
                                        'hrf-role-section'       => '人資職務表單',
                                        'tidc-role-section'      => '型態識別文件管制表',
                                        'tdev-role-section'      => '產品開發評估表',
                                        'pfmea-role-section'     => 'PFMEA',
                                        'asdoc-pos-role-section' => 'AS文件·職稱權限',
                                        'imgedit-label-dir-section' => '批圖標籤路徑',
                                        'asdoc-nas-dir-section'  => 'AS文件儲存路徑',
                                    ];
                                    foreach ($_navItems as $_nid => $_nlabel): ?>
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

                    <!-- ══ 角色指派（依模組分開）══ -->
                    <?php
                    eg_render_role_section('quot', 'quotation', '報價單管理', 'fa-file-text-o', '#3498db',
                        '為每位使用者指派報價單管理頁面的操作角色。角色與功能定義請至 <strong>報價單管理 → 報價單設定（齒輪圖示）→ 權限設定</strong>。',
                        $_quotRoles, $_userQuotRoles, $admins, $_quotDepts, $canEdit);

                    eg_render_role_section('notice', 'notice', '公告 / 通知管理', 'fa-bullhorn', '#1ABB9C',
                        '為每位使用者指派公告/通知頁面的操作角色。角色與功能定義請至 <strong>公告 / 通知管理 → 權限設定</strong>。',
                        $_noticeRoles, $_userNoticeRoles, $admins, $_quotDepts, $canEdit);

                    eg_render_role_section('home', 'homepage', '首頁設定', 'fa-home', '#e67e22',
                        '為每位使用者指派首頁設定頁面的操作角色。角色與功能定義請至 <strong>首頁設定 → 權限設定</strong>。',
                        $_homeRoles, $_userHomeRoles, $admins, $_quotDepts, $canEdit);

                    eg_render_role_section('qc', 'qc', '品管檢驗（QC）', 'fa-check-square-o', '#9b59b6',
                        '為每位使用者指派品管檢驗頁面的操作角色（填寫檢驗表單、修改/開放檢驗歷程、回覆異常處置）。角色與功能定義請至 <strong>品管檢驗 → 設定（齒輪圖示）→ 權限設定</strong>。',
                        $_qcRoles, $_userQcRoles, $admins, $_quotDepts, $canEdit);

                    eg_render_role_section('car', 'car', '異常矯正處理單', 'fa-wrench', '#16a085',
                        '為每位使用者指派異常矯正處理單頁面的操作角色（檢閱、開立、修改、刪除、管理設定）。角色與功能定義請至 <strong>異常矯正處理單 → 設定（齒輪圖示）→ 權限設定（角色）</strong>。',
                        $_carRoles, $_userCarRoles, $admins, $_quotDepts, $canEdit);

                    eg_render_role_section('mdata', 'master_data', '主檔管理（附件 / 圖面查閱）', 'fa-database', '#d4761a',
                        '為每位使用者指派主檔管理頁「其他附件」分頁的操作角色（檢視、上傳、刪除、編輯標籤/浮水印）。「報價資料」分頁沿用報價單「檢視」權限（quotation_view）；「圖面查閱」對所有登入者開放。<strong>過渡期：尚未指派 master_data 角色前，暫時沿用主檔管理頁原有附件權限，不會鎖住任何人；一旦指派了第一位，未被指派者即改以角色為準。</strong>角色與功能定義請至 <strong>主檔管理頁 → 角色設定（僅管理員可見）</strong>。管理者固定可用。',
                        $_mdataRoles, $_userMdataRoles, $admins, $_quotDepts, $canEdit);

                    eg_render_role_section('imgedit', 'imgedit', '批圖編輯器', 'fa-paint-brush', '#ab47bc',
                        '為每位使用者指派「批圖使用者」角色（批圖編輯器：訂單追蹤頁「批圖」按鈕開啟的圖面編輯跳窗）。<strong>尚未指派任何人之前，暫時開放所有登入者使用；一旦指派了第一位，未被指派者即無法開啟。</strong>管理者固定可用。',
                        $_imgRoles, $_userImgRoles, $admins, $_quotDepts, $canEdit);

                    eg_render_role_section('rvf', 'review_form', '審核表單', 'fa-clipboard', '#c0782d',
                        '為每位使用者指派審核表單引擎的操作角色（檢閱、檢視全部人員表單、建立/填寫/送出、列印、模板管理）。模板管理僅供設定 AS 文件綁定/審核核准流程；項次內容維護權限則另在「審核表單模板管理」頁依維護部門/維護人員指派，不透過此處角色。角色與功能定義請至 <strong>審核表單模板管理 → 使用說明</strong>。',
                        $_rvfRoles, $_userRvfRoles, $admins, $_quotDepts, $canEdit);

                    eg_render_role_section('hrf', 'hr_form', '人資職務表單', 'fa-id-card', '#a0662e',
                        '為每位使用者指派人資職務表單（職務說明書／專業技能鑑定考核表／員工職能鑑定表）的操作角色（檢閱、檢視全部人員表單、建立/批次建立/複製/編輯、列印、範本管理）。確認人（該員工直屬主管）／核准人（總經理）為固定角色，不透過此處角色指派，由系統依組織架構自動解析。角色與功能定義請至 <strong>人資職務表單設定 → 使用說明</strong>。',
                        $_hrfRoles, $_userHrfRoles, $admins, $_quotDepts, $canEdit);

                    eg_render_role_section('fsd', 'form_signer', '表單簽核設計器', 'fa-object-group', '#7a5217',
                        '為每位使用者指派表單簽核設計器的操作角色（檢閱、檢視全部人員案件、建立/送出案件、列印、樣板管理）。各階段的簽核槽位（意見成員/決策者）由管理員在「樣板管理」逐樣板設定，不透過此處角色指派。角色與功能定義請至 <strong>表單簽核設計器 - 樣板管理 → 使用說明</strong>。',
                        $_fsdRoles, $_userFsdRoles, $admins, $_quotDepts, $canEdit);

                    eg_render_role_section('drawren', 'drawing_rename', '圖面自動改檔名工具', 'fa-file-image-o', '#2c81ba',
                        '為每位使用者指派圖面自動改檔名工具的操作角色（檢閱、執行改檔名、管理資料夾與前後綴設定）。角色與功能定義請至 <strong>圖面自動改檔名工具</strong> 頁面內查看「權限說明」。',
                        $_drawRoles, $_userDrawRoles, $admins, $_quotDepts, $canEdit);

                    eg_render_role_section('bomren', 'bom_rename', '叫料文件自動改檔名工具', 'fa-file-archive-o', '#8e44ad',
                        '為每位使用者指派叫料文件（BOM）自動改檔名工具的操作角色（檢閱/掃描、核對確認並產生檔案、管理資料夾與OCR設定）。角色與功能定義請至 <strong>叫料文件自動改檔名工具</strong> 頁面內查看「權限說明」。',
                        $_bomRenRoles, $_userBomRenRoles, $admins, $_quotDepts, $canEdit);

                    eg_render_role_section('oready', 'oready', '生管 BOM 狀態管理', 'fa-industry', '#e67e22',
                        '為每位使用者指派 BOM 狀態頁（回廠標記、拆批/合併、移轉、人工結案等）的操作角色。角色與功能定義請至 <strong>生管 BOM 狀態頁右上角「角色功能設定」（僅管理員可見）</strong>。',
                        $_oreadyRoles, $_userOreadyRoles, $admins, $_quotDepts, $canEdit);

                    eg_render_role_section('bomtrk', 'bom_track', 'BOM 追蹤', 'fa-crosshairs', '#8e44ad',
                        '為每位使用者指派 BOM 追蹤功能的使用權限。此功能不分細部操作，只要指派角色即可使用。',
                        $_bomtrkRoles, $_userBomtrkRoles, $admins, $_quotDepts, $canEdit);

                    eg_render_role_section('profit', 'order_profit', '訂單毛利分析', 'fa-line-chart', '#c0392b',
                        '為每位使用者指派「訂單毛利分析」頁的檢視資格。<strong>毛利屬敏感資料</strong>，未被指派角色者無法開啟本頁；此功能不分細部操作。管理者固定可用。',
                        $_profitRoles, $_userProfitRoles, $admins, $_quotDepts, $canEdit);

                    eg_render_role_section('ppr', 'part_process_report', '料號製程履歷報告', 'fa-file-text-o', '#c0392b',
                        '為每位使用者指派「料號製程履歷報告」頁的使用資格。<strong>整頁單一權限</strong>（含成本毛利，不分層），未被指派角色者無法開啟本頁。管理者固定可用。',
                        $_pprRoles, $_userPprRoles, $admins, $_quotDepts, $canEdit);

                    eg_render_role_section('stamp', 'stamp', '圖章管理', 'fa-certificate', '#c0762c',
                        '圖章管理頁角色：「圖章檢閱」＝唯讀（檢閱清冊/匯出）；「圖章管理員」＝登記核發（個人章/部門章）、種類管理、掃描實體章上傳。<strong>未被指派任何角色者看不到清冊內容</strong>（避免圖章被瀏覽轉存惡意複製）；簽核單據上的印章顯示不受此限。管理者固定可管理。',
                        $_stampRoles, $_userStampRoles, $admins, $_quotDepts, $canEdit);

                    eg_render_role_section('kpi', 'kpi', 'KPI 關鍵績效指標', 'fa-tachometer', '#c0762c',
                        'KPI 總覽頁角色：「KPI檢閱」＝檢視總覽/趨勢圖/附件；「KPI填報」＝檢閱＋重算自動指標、擔當者填寫本人負責的手動指標與上傳佐證；「KPI管理員」＝填報＋手動覆寫(需原因)、舊年度重算、KPI設定頁(指標/公式/目標/權限規則/NAS路徑)。此處指派與 KPI 設定頁的「部門×主管階級/指定人員」規則<strong>為聯集</strong>；管理者固定全權。',
                        $_kpiRoles, $_userKpiRoles, $admins, $_quotDepts, $canEdit);

                    eg_render_role_section('ptask', 'personal_task', '個人工作紀錄', 'fa-sticky-note-o', '#27ae60',
                        '為每位使用者指派「個人工作紀錄」功能的使用資格。此功能不分細部操作；每人只看得到自己建立的紀錄（含管理者也看不到他人內容）。',
                        $_ptaskRoles, $_userPtaskRoles, $admins, $_quotDepts, $canEdit);

                    eg_render_role_section('roster', 'roster', '輪值排班表', 'fa-calendar-check-o', '#c0762c',
                        '通用輪值排班（掃地/值日/現場班別皆共用）角色：「排班唯讀」＝只能檢閱自己建立或被設為公開對象的表；「排班一般使用者」＝可建立/編輯/刪除自己的排班表；「排班管理者」＝可檢視所有表、代他人補簽、對任何表調班。值勤本人可對自己的班別簽核；公開對象名單內的人才看得到該表。管理者固定全權。',
                        $_rosterRoles, $_userRosterRoles, $admins, $_quotDepts, $canEdit);

                    eg_render_role_section('asdoc', 'as_doc', 'AS9100 文件管理（個人指派，優先於職稱）', 'fa-folder-open-o', '#c0392b',
                        '為使用者「個人」指派 AS 文件管理角色——<strong>個人有指派時以個人為準（覆蓋職稱）</strong>；未指派者自動套用下方「職稱權限」的設定。角色定義（名稱與功能勾選）請至 <strong>AS9100 文件管理頁 → 角色設定</strong>。<br><strong>本區角色同時套用到「AS流程說明手冊」頁</strong>（各課室流程/表單說明＋待處理問題清單，唯讀）：有 <code>asdoc_view</code>（或該頁 ACRUD 的 A／R）即可檢視，管理者固定可看。',
                        $_asdocRoles, $_userAsdocRoles, $admins, $_quotDepts, $canEdit);

                    eg_render_role_section('dbbk', 'db_backup', '資料庫備份管理', 'fa-database', '#b06f27',
                        '為每位使用者指派「資料庫備份管理」頁的操作角色（檢視/下載、立即備份、整表還原）。<strong>未被指派角色者無法進入本頁</strong>；整庫還原、備份設定與還原密碼一律僅限管理員；整表/部分還原另需輸入管理員設定的還原密碼。角色與功能定義請至 <strong>資料庫備份管理頁 → 角色權限（僅管理員可見）</strong>。',
                        $_dbbkRoles, $_userDbbkRoles, $admins, $_quotDepts, $canEdit);

                    eg_render_role_section('dc', 'data_console', '資料急救台', 'fa-medkit', '#b53c26',
                        '為每位使用者指派「資料急救台」頁的操作角色。此頁可直接查改後端資料庫，請謹慎授權。角色功能：<strong>data_console_view</strong>＝進入/瀏覽/搜尋/查詢；<strong>data_console_edit</strong>＝新增/修改（仍受各表「允許編輯」限制）；<strong>data_console_delete</strong>＝刪除（仍受各表「允許刪除」限制且需二次確認）。<strong>未被指派角色者無法進入本頁</strong>；表級開放設定與關聯地圖一律僅限管理員；管理員固定擁有全部權限。',
                        $_dcRoles, $_userDcRoles, $admins, $_quotDepts, $canEdit);

                    eg_render_role_section('tcal', 'tool_calib', '量測儀器校驗管理', 'fa-thermometer-half', '#b06f27',
                        '為每位使用者指派「量測儀器校驗管理」頁的操作角色（KPI #18 量測儀器按時校驗率的來源頁）。角色功能：<strong>校驗唯讀</strong>＝檢視儀器清單/校驗歷史/統計與匯出；<strong>校驗登錄</strong>＝唯讀＋登錄各儀器校驗完成紀錄；<strong>校驗管理員</strong>＝登錄＋新增儀器、設定週期/納管/基準到期日、刪除誤登紀錄。<strong>未被指派角色者無法進入本頁</strong>；管理者固定擁有全部權限。',
                        $_tcalRoles, $_userTcalRoles, $admins, $_quotDepts, $canEdit);

                    eg_render_role_section('train', 'training', '教育訓練管理', 'fa-graduation-cap', '#b06f27',
                        '為每位使用者指派「教育訓練管理」頁的操作角色（KPI #19 人員教育訓練達成率的來源頁）。角色功能：<strong>訓練檢閱</strong>＝檢視訓練計畫/紀錄、月達成率與匯出；<strong>訓練登錄</strong>＝檢閱＋新增/編輯訓練場次、登錄完成；<strong>訓練管理員</strong>＝登錄＋刪除場次。<strong>未被指派角色者無法進入本頁</strong>；管理者固定擁有全部權限。',
                        $_trainRoles, $_userTrainRoles, $admins, $_quotDepts, $canEdit);

                    eg_render_role_section('meet', 'meeting', '會議紀錄管理', 'fa-users', '#8A5A2B',
                        '為每位使用者指派「會議紀錄管理」頁的操作角色（2-GM-05-01 會議記錄）。角色功能：<strong>會議記錄檢閱</strong>＝檢視會議記錄（草稿僅記錄人本人看得到，出席人員／主席／總經理對相關會議自動有唯讀權限，不受此角色限制）；<strong>會議記錄登錄</strong>＝檢閱＋新增/編輯/送出自己的會議記錄；<strong>會議記錄管理員</strong>＝登錄＋檢視全部人員記錄、刪除、修改他人已送出的記錄。<strong>未被指派角色者仍可看到自己的草稿與相關會議</strong>，但看不到其他人的記錄列表；管理者固定擁有全部權限。',
                        $_meetRoles, $_userMeetRoles, $admins, $_quotDepts, $canEdit);

                    eg_render_role_section('vaud', 'vendor_audit', '供應商稽核管理', 'fa-clipboard', '#b06f27',
                        '為每位使用者指派「供應商稽核管理」頁的操作角色（KPI #6 廠商稽核按時執行率的來源頁）。角色功能：<strong>稽核檢閱</strong>＝檢視廠商清單/稽核歷史/半年統計與匯出；<strong>稽核登錄</strong>＝檢閱＋登錄各廠商稽核完成紀錄；<strong>稽核管理員</strong>＝登錄＋設定週期/納管/基準到期日、刪除誤登紀錄。<strong>未被指派角色者無法進入本頁</strong>；管理者固定擁有全部權限。',
                        $_vaudRoles, $_userVaudRoles, $admins, $_quotDepts, $canEdit);

                    eg_render_role_section('leave', 'leave', '請假系統', 'fa-calendar-minus-o', '#d99a4e',
                        '<strong>所有登入者都能申請請假、查看與撤回／銷假自己的單</strong>，不需要在這裡指派角色。此處只指派 <strong>人事（可看全部請假單）</strong>＝可檢視全公司請假單（不含代為簽核的權力）。<br>
                         <span style="color:#b06f27;">簽核權不由角色決定</span>：由申請人的部門／職稱階級推出主管鏈逐層簽核；主管當日有行程時改由其代理人簽，代理人若正好是申請人則自動直升上一級（權責分離）。<strong>代理人設定與最終裁決者請至「人事設定（hr_settings）」維護</strong>。主管（職稱有設定階級者）自動可檢視自己部門含下轄的請假單。管理者固定擁有全部權限。',
                        $_leaveRoles, $_userLeaveRoles, $admins, $_quotDepts, $canEdit);

                    eg_render_role_section('ship', 'shipping', '快速出貨', 'fa-truck', '#F0A24B',
                        '為每位使用者指派「快速出貨」頁的操作角色。角色功能：<strong>出貨檢閱</strong>＝查詢待出貨清單、檢視近期出貨單與匯出，<span style="color:#b06f27;">不可建立出貨單</span>；<strong>出貨登錄</strong>＝檢閱＋建立出貨單（同客戶同日自動併為一張出貨單，並回填訂單編號與扣製令完工量）；<strong>出貨管理員</strong>＝登錄＋執行「舊資料訂單回填」（把 ERP 匯入、未帶訂單編號的歷史出貨資料比對回訂單）。<strong>未被指派角色者無法進入本頁</strong>；管理者固定擁有全部權限。',
                        $_shipRoles, $_userShipRoles, $admins, $_quotDepts, $canEdit);

                    eg_render_role_section('purc', 'purchase', '申請採購', 'fa-shopping-cart', '#8A5A2B',
                        '<span style="color:#b06f27;">此模組的角色名稱與內容可自訂</span>：到「申請採購」頁 →「設定」分頁 →「角色權限設定」可新增／改名／刪除角色，並逐一勾選該角色的<strong>可視內容</strong>（檢閱全部單據、看得到金額、看得到廠商與發票付款）與<strong>可操作</strong>（申請、採購版申請單、到貨入庫、詢價下單、高階核准、採購設定）。此處只負責把角色指派給人。以下是預設角色的內容，若已被改過請以該頁「角色權限說明」為準。<br>
                         為每位使用者指派「申請採購」頁的操作角色（權限由上而下包含，指派上層即自動具備下層能力）。<strong>申請採購</strong>＝提出／修改自己的申請單、上傳附件、查看自己的單；<strong>到貨入庫</strong>＝申請＋登錄到貨（可選「入庫待領／直接交付請購人／不列管」）；<strong>採購作業</strong>＝到貨入庫＋詢價填實際金額、下單、記發票與付款、結案、維護採購品主檔；<strong>採購管理員</strong>＝採購作業＋標籤與規格屬性設定、簽核門檻與附件路徑設定、刪除任何單據；<strong>採購檢閱</strong>＝唯讀查看全部單據與統計；<strong>高階核准</strong>＝金額超過第二層門檻時的第二關簽核人；<strong>完整申請單</strong>＝看到「採購版」申請單。<br>
                         <span style="color:#b06f27;">申請單有兩種版型</span>：一般使用者看到<strong>精簡版</strong>（只填用途、希望到貨日、急件、品名／規格／數量／單位，標題自動產生），採購料號、預估單價、到貨處理、附件分類都由採購後續補；指派<strong>完整申請單</strong>角色的人（採購作業以上自動具備，不必另外指派）看到<strong>採購版</strong>，可直接綁採購料號、手填標題、填預估單價與到貨處理、分類附件。<br>
                         <span style="color:#b06f27;">簽核不在申請當下判定</span>：申請時金額可以留白，等採購詢完價、填入實際金額後才依含稅總額判定要不要簽核（門檻在該頁「設定」分頁調整，預設 5000／30000）。第一層簽核人＝申請人的部門主管，由系統依代理人設定自動解析（主管當日有行程改由代理人簽，代理人正好是申請人時自動直升上一級）。<strong>未被指派角色者無法進入本頁</strong>；管理者固定擁有全部權限。',
                        $_purcRoles, $_userPurcRoles, $admins, $_quotDepts, $canEdit);

                    eg_render_role_section('acc', 'accounting', '會計（對帳／應收／發票／應付）', 'fa-calculator', '#b06f27',
                        '為每位使用者指派「會計模組」的角色，涵蓋客戶發票資料維護、對帳作業、對帳單總覽、應收對帳單、發票開立與轉出、收款沖帳、應付對帳（加工費＋採購）、付款與沖帳等頁。<br>
                         <span style="color:#b06f27;">應付涵蓋範圍</span>：廠商加工費＋材料／其他採購（<strong>只收月結</strong>；現金／零用金採購不經會計，由採購自行做零用金記帳）。
                         採購單是現金還是月結預設依付款方式文字判定，<strong>會計登錄</strong>以上可在應付頁逐張改判（須填原因、寫入稽核）。
                         月結採購的付款狀態<strong>以會計的付款沖帳為準</strong>並自動回寫採購單，採購頁只顯示結果。<br>
                         <strong>會計檢閱</strong>＝只能查詢與匯出，不可修改；<strong>會計登錄</strong>＝檢閱＋維護客戶統編/發票抬頭、CSV 匯入、開立發票與收款沖帳、兩側都可對帳；<strong>會計管理員</strong>＝登錄＋作廢發票、刪除收款單、<strong>退回已鎖帳的對帳單</strong>。<br>
                         <span style="color:#b06f27;">對帳分工（實務）</span>：應收由<strong>業務</strong>對完帳給會計、應付由<strong>生管</strong>對完帳給會計，所以另有兩個只做對帳的角色——<strong>應收對帳(業務)</strong> 只能對應收（客戶／出貨退貨），<strong>應付對帳(生管)</strong> 只能對應付（廠商／加工費），<span style="color:#b06f27;">兩者互不相通</span>（業務不能碰應付、生管不能碰應收），且都<strong>不能</strong>開發票或做收款付款。這兩個角色可以修改單據數量/單價/金額/備註與帳款月份，但<strong>每次修改都必填原因並寫入稽核紀錄</strong>。<br>
                         <span style="color:#b06f27;">鎖帳規則</span>：對帳人員按「確認正確」即鎖帳，之後不可改單也不可再暫存，<strong>僅會計管理員可退回重對</strong>（須填原因）。已開立發票的憑證另有更硬的鎖——不提供解鎖，只能作廢／折讓／補開，因為帳面必須與國稅局申報一致。<br>
                         <span style="color:#b06f27;">為什麼要先維護客戶發票資料</span>：開立電子發票必須有買方統一編號與買方名稱，目前 925 家有效客戶只有 12 家資料完整、近一年有出貨的 175 家中有 171 家缺資料，補齊前發票轉出會被擋下。<strong>未被指派角色者無法進入這些頁面</strong>；管理者固定擁有全部權限。',
                        $_accRoles, $_userAccRoles, $admins, $_quotDepts, $canEdit);

                    eg_render_role_section('extdoc', 'external_doc', '外來文件清單', 'fa-file-text-o', '#b06f27',
                        '為每位使用者指派「外來文件清單」頁（業務 &gt; 外來文件清單，AS9100 外來文件管制）的操作角色。角色功能：<strong>外來文件檢閱</strong>＝檢視清單（依訂單綁定/客戶/年度篩選）、匯出 CSV、依客戶分組列印；<strong>外來文件管理</strong>＝檢閱＋綁定列印頁尾的 AS 文件編號。清單內容來自附件標籤有勾「列入外來文件清單」的料號附件與報價附件（標籤設定在報價單頁或主檔管理）。<strong>未被指派角色者無法檢視本頁</strong>；管理者固定擁有全部權限。',
                        $_extdocRoles, $_userExtdocRoles, $admins, $_quotDepts, $canEdit);

                    eg_render_role_section('otrk', 'order_track', '訂單追蹤', 'fa-list-alt', '#c0762c',
                        '為每位使用者指派「訂單追蹤」頁（業務 &gt; 訂單追蹤）的操作角色。角色與各功能的對應在<strong>訂單追蹤頁右上「角色設定」</strong>（僅管理員）依功能群組勾選：訂單基本操作（檢視/新建編輯/刪除/顯示金額）、訂單流程（批圖/轉生管/結案/取消訂單/OP轉訂單）、訂單變更、設計與批圖（設計備註/批圖編輯器/前往料號主檔）、計算工具（齒輪/鍵槽計算）。<strong>注意：訂單追蹤頁目前權限檢查尚未切換為角色制</strong>，此處指派先建立人員對照，切換後才會生效；管理者固定擁有全部權限。',
                        $_otrkRoles, $_userOtrkRoles, $admins, $_quotDepts, $canEdit);

                    eg_render_role_section('tidc', 'type_id_ctrl', '型態識別文件管制表', 'fa-sitemap', '#b06f27',
                        '為每位使用者指派「型態識別文件管制表」頁（技術部 &gt; 型態識別文件管制表）的操作角色。角色功能：<strong>型態文件檢閱</strong>＝檢視清單、開啟查看、列印；<strong>型態文件登錄</strong>＝檢閱＋新增/編輯、「掃描待建立料號」與自動產生/同步、確認清單（含排除項目）；<strong>型態文件管理員</strong>＝登錄＋刪除、AS 文件編號綁定。<strong>未被指派角色者無法進入本頁</strong>；管理者固定擁有全部權限。',
                        $_tidcRoles, $_userTidcRoles, $admins, $_quotDepts, $canEdit);

                    eg_render_role_section('tdev', 'td_dev_eval', '產品開發評估表', 'fa-flask', '#c0762c',
                        '為每位使用者指派「產品開發評估表」頁（技術部 &gt; 產品開發評估表，AS 2-TD-02-01）的操作角色。角色功能：<strong>評估表檢閱</strong>＝檢視清單、開啟查看、列印；<strong>評估表登錄</strong>＝檢閱＋新增/編輯、逐項填寫、依部門身分簽核；<strong>評估表管理員</strong>＝登錄＋刪除、AS 文件編號綁定、取消他人簽核。<strong>APQP 小組簽認各部門欄位由該部門任一主管簽核</strong>，部門綁定在「組織角色綁定設定」頁（重用技術/業務/管理/生產/品保部門既有綁定，另有資材部門角色），與本頁的檢閱/登錄/管理角色是兩件事——這裡只決定誰能進本頁操作，能不能簽某部門的欄位另外看是否為該部門主管。<strong>未被指派角色者無法進入本頁</strong>；管理者固定擁有全部權限。',
                        $_tdevRoles, $_userTdevRoles, $admins, $_quotDepts, $canEdit);

                    eg_render_role_section('pfmea', 'pfmea', 'PFMEA潛在失效模式及效應分析', 'fa-exclamation-triangle', '#8A5A2B',
                        '為每位使用者指派「PFMEA潛在失效模式及效應分析」頁（技術部 &gt; PFMEA，AS 3-TD-01-02）的操作角色。角色功能：<strong>PFMEA檢閱</strong>＝檢視清單、開啟查看、列印；<strong>PFMEA登錄</strong>＝檢閱＋新增/編輯分析列；<strong>PFMEA管理員</strong>＝登錄＋刪除、AS 文件編號綁定。<strong>未被指派角色者無法進入本頁</strong>；管理者固定擁有全部權限。',
                        $_pfmeaRoles, $_userPfmeaRoles, $admins, $_quotDepts, $canEdit);
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