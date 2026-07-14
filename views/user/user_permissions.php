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
} catch(Exception $_e) {}

// 角色指派區塊（依模組共用渲染）
if (!function_exists('eg_render_role_section')) {
    function eg_render_role_section($prefix, $module, $title, $icon, $color, $hint, $roles, $userRoles, $admins, $depts, $canEdit) {
        ?>
        <div class="row" style="margin-top:20px;">
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

                    <div class="row">

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
                                                                                <?php foreach ($acrudMap as $char => $label):
                                                                                    // 針對 hr_permissions 模組，只顯示 A, R, U
                                                                                    if (($mCode === 'hr_permissions' || $module['module_name'] === 'hr_permissions') && !in_array($char, ['A', 'R', 'U'])) continue;
                                                                                ?>
                                                                                    <label class="checkbox-inline">
                                                                                        <input type="checkbox" name="permissions[group][<?= $mCode ?>][]" value="<?= $char ?>" <?= strpos($currPerm, $char) !== false ? 'checked' : '' ?>> <?= $char . ' ' . $label ?>
                                                                                    </label>
                                                                                <?php endforeach; ?>
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
                                                                                    <?php foreach ($acrudMap as $char => $label): ?>
                                                                                        <label class="checkbox-inline">
                                                                                            <input type="checkbox" name="permissions[page][<?= $pId ?>][]" value="<?= $char ?>" <?= strpos($currPagePerm, $char) !== false ? 'checked' : '' ?>> <?= $char . ' ' . $label ?>
                                                                                        </label>
                                                                                    <?php endforeach; ?>
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

                    eg_render_role_section('imgedit', 'imgedit', '批圖編輯器', 'fa-paint-brush', '#ab47bc',
                        '為每位使用者指派「批圖使用者」角色（批圖編輯器：訂單追蹤頁「批圖」按鈕開啟的圖面編輯跳窗）。<strong>尚未指派任何人之前，暫時開放所有登入者使用；一旦指派了第一位，未被指派者即無法開啟。</strong>管理者固定可用。',
                        $_imgRoles, $_userImgRoles, $admins, $_quotDepts, $canEdit);

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
                    ?>

                    <!-- ══ 批圖編輯器：標籤儲存路徑 ══ -->
                    <div class="row" style="margin-top:20px;">
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
                            for (var i = 0; i < pStr.length; i++) {
                                form.find('input[name="permissions[group][' + mCode + '][]"][value="' + pStr.charAt(i) + '"]').prop('checked', true);
                            }
                        }
                    });
                }
                if (permsData.page) {
                    $.each(permsData.page, function(pId, pStr) {
                        if (pStr) {
                            for (var i = 0; i < pStr.length; i++) {
                                form.find('input[name="permissions[page][' + pId + '][]"][value="' + pStr.charAt(i) + '"]').prop('checked', true);
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

            // 部門篩選函數（供 select onchange 呼叫）
            function filterPermTableByDept() {
                filterDeptValue = $('#dept-filter-select').val();
                $('#datatable-buttons').DataTable().draw();
            }

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
            ['quot', 'notice', 'oready', 'bomtrk'].forEach(function(p) {
                var total = $('#' + p + '-role-tbody tr').length;
                if (total > 0) $('#' + p + '-filter-count').text('共 ' + total + ' 人');
            });
        });

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
</body>

</html>