<?php
session_start();

// 引入資料庫連線
include("../../src/common/DBConnection.php");
include_once '../../src/common/_config.php';

// 建立資料庫連線物件
@$db = (new DBConnection())->getPDO();

// 1. 登入檢查
// if (!isset($_SESSION['userid'])) {
//     header("Location: ../../index.php");
//     exit;
// }

@$userId = $_SESSION['userid'];

// 2. 權限檢查：確認使用者是否有 position_id = 99
@$hasPermission = true; // 暫時取消驗證，強制允許進入
/*
try {
    $permSql = "SELECT COUNT(*) FROM user_department_position_map WHERE user_id = ? AND position_id = 99";
    $permStmt = $db->prepare($permSql);
    $permStmt->execute([$userId]);
    if ($permStmt->fetchColumn() > 0) {
        $hasPermission = true;
    }
} catch (PDOException $e) {
    // 錯誤處理：顯示詳細錯誤以便除錯 (正式上線後建議改為 error_log)
    die("權限檢查 SQL 錯誤: " . $e->getMessage());
}
*/

if (!$hasPermission) {
    // 無權限，跳回登入畫面或首頁
    echo "<script>alert('您無權限存取此頁面 (Debug: UserID={$userId})'); window.location.href = '../../index.php';</script>";
    exit;
}

// --- 處理表單提交 (CRUD) ---
@$message = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 檢查是否為 AJAX 請求
    $is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

    try {
        $action = $_POST['action'] ?? '';

        // --- 排序操作 (AJAX) ---
        if ($action === 'reorder_items') {
            header('Content-Type: application/json');
            $type = $_POST['type'];
            $order = $_POST['order']; // Array of IDs
            
            // --- 針對子頁面的特殊排序邏輯 ---
            if ($type === 'page' && is_array($order)) {
                $db->beginTransaction();
                try {
                    // 取得所有頁面及其 group_id，避免在迴圈中查詢
                    $pageGroupMap = [];
                    $pagesQuery = $db->query("SELECT page_id, group_id FROM system_module_pages");
                    while ($row = $pagesQuery->fetch(PDO::FETCH_ASSOC)) {
                        $pageGroupMap[$row['page_id']] = $row['group_id'];
                    }

                    $groupCounters = [];
                    $updateStmt = $db->prepare("UPDATE system_module_pages SET sort_order = ? WHERE page_id = ?");

                    foreach ($order as $page_id) {
                        // 使用一個鍵來處理 NULL 的 group_id
                        $group_key = $pageGroupMap[$page_id] ?? 'null_group';
                        
                        // 如果計數器不存在，則初始化為 1
                        if (!isset($groupCounters[$group_key])) {
                            $groupCounters[$group_key] = 1;
                        }
                        
                        // 更新頁面的 sort_order
                        $updateStmt->execute([$groupCounters[$group_key], $page_id]);
                        
                        // 該群組的計數器加一
                        $groupCounters[$group_key]++;
                    }
                    $db->commit();
                    echo json_encode(['status' => 'success', 'message' => '頁面排序已更新']);
                } catch (Exception $e) {
                    $db->rollBack();
                    http_response_code(500);
                    echo json_encode(['status' => 'error', 'message' => '排序更新失敗: ' . $e->getMessage()]);
                }
            } 
            // --- 其他表格的原始排序邏輯 ---
            else {
                $table = '';
                $pk = '';
                if ($type === 'group') { $table = 'system_module_groups'; $pk = 'group_id'; }
                elseif ($type === 'module') { $table = 'system_modules'; $pk = 'module_code'; }

                if ($table && is_array($order)) {
                    $sql = "UPDATE $table SET sort_order = ? WHERE $pk = ?";
                    $stmt = $db->prepare($sql);
                    foreach ($order as $index => $id) {
                        $stmt->execute([$index + 1, $id]);
                    }
                    echo json_encode(['status' => 'success', 'message' => '排序已更新']);
                }
            }
            exit;
        }

        // --- 獲取所有資料 (AJAX Refresh) ---
        elseif ($action === 'fetch_data') {
            // Groups
            $groups = $db->query("SELECT g.*, GROUP_CONCAT(p.page_name ORDER BY p.sort_order SEPARATOR ', ') as bound_pages_list FROM system_module_groups g LEFT JOIN system_module_pages p ON g.group_id = p.group_id GROUP BY g.group_id ORDER BY g.sort_order ASC, g.group_id ASC")->fetchAll(PDO::FETCH_ASSOC);
            // Pages
            $pages = $db->query("SELECT p.*, g.group_name AS current_group_name FROM system_module_pages p LEFT JOIN system_module_groups g ON p.group_id = g.group_id ORDER BY g.sort_order ASC, p.sort_order ASC")->fetchAll(PDO::FETCH_ASSOC);
            // Modules
            $sql_modules = "SELECT m.*, g.group_name, p.page_name, p.page_url AS module_url FROM system_modules m LEFT JOIN system_module_groups g ON m.group_id = g.group_id LEFT JOIN system_module_pages p ON m.page_id = p.page_id ORDER BY m.sort_order ASC, m.module_code ASC";
            $modules = $db->query($sql_modules)->fetchAll(PDO::FETCH_ASSOC);

            header('Content-Type: application/json');
            echo json_encode([
                'status' => 'success',
                'groups' => $groups,
                'pages' => $pages,
                'modules' => $modules
            ]);
            exit;
        }

        // --- Group 操作 ---
        elseif ($action === 'save_group') {
            $group_name = $_POST['group_name'];
            $remark = $_POST['remark'];
            $group_id = $_POST['group_id'] ?? '';
            $is_edit = !empty($group_id);

            if ($is_edit) {
                // Update
                $sql = "UPDATE system_module_groups SET group_name=?, remark=? WHERE group_id=?";
                $stmt = $db->prepare($sql);
                $stmt->execute([$group_name, $remark, $group_id]);
            } else {
                // Insert
                // 自動計算排序：取最大值 + 1
                $sort_order = $db->query("SELECT IFNULL(MAX(sort_order), 0) + 1 FROM system_module_groups")->fetchColumn();
                $sql = "INSERT INTO system_module_groups (group_name, sort_order, remark) VALUES (?, ?, ?)";
                $stmt = $db->prepare($sql);
                $stmt->execute([$group_name, $sort_order, $remark]);
                $group_id = $db->lastInsertId();
            }

            // 處理子頁面綁定
            $clearSql = "UPDATE system_module_pages SET group_id = NULL WHERE group_id = ?";
            $db->prepare($clearSql)->execute([$group_id]);

            $bound_pages = $_POST['bound_pages'] ?? [];
            if (!empty($bound_pages) && is_array($bound_pages)) {
                $placeholders = implode(',', array_fill(0, count($bound_pages), '?'));
                $bindSql = "UPDATE system_module_pages SET group_id = ? WHERE page_id IN ($placeholders)";
                $params = array_merge([$group_id], $bound_pages);
                $db->prepare($bindSql)->execute($params);
            }

            if ($is_ajax) {
                $stmt = $db->prepare("SELECT g.*, GROUP_CONCAT(p.page_name ORDER BY p.sort_order SEPARATOR ', ') as bound_pages_list FROM system_module_groups g LEFT JOIN system_module_pages p ON g.group_id = p.group_id WHERE g.group_id = ? GROUP BY g.group_id");
                $stmt->execute([$group_id]);
                $saved_data = $stmt->fetch(PDO::FETCH_ASSOC);
                header('Content-Type: application/json');
                echo json_encode(['status' => 'success', 'message' => '主項目儲存成功', 'data' => $saved_data, 'is_edit' => $is_edit]);
                exit;
            }
            $message = $is_edit ? "主項目更新成功" : "主項目新增成功";
        } elseif ($action === 'delete_group') {
            $group_id = $_POST['group_id'];
            // 檢查是否有模組正在使用此群組
            $check = $db->prepare("SELECT COUNT(*) FROM system_modules WHERE group_id = ?");
            $check->execute([$group_id]);
            if ($check->fetchColumn() > 0) {
                throw new Exception("無法刪除：尚有模組使用此主項目");
            }
            // 解除子頁面綁定
            $db->prepare("UPDATE system_module_pages SET group_id = NULL WHERE group_id = ?")->execute([$group_id]);
            
            $stmt = $db->prepare("DELETE FROM system_module_groups WHERE group_id = ?");
            $stmt->execute([$group_id]);
            if ($is_ajax) {
                header('Content-Type: application/json');
                echo json_encode(['status' => 'success', 'message' => '主項目刪除成功']);
                exit;
            }
            $message = "主項目刪除成功";
        }

        // --- Page 操作 ---
        elseif ($action === 'save_page') {
            $page_name = $_POST['page_name'];
            $page_url = $_POST['page_url'];
            $page_url_readonly = $_POST['page_url_readonly'];
            $remark = $_POST['remark'];
            $page_id = $_POST['page_id'] ?? '';
            $is_edit = !empty($page_id);

            if ($is_edit) {
                // Update
                $sql = "UPDATE system_module_pages SET page_name=?, page_url=?, page_url_readonly=?, remark=? WHERE page_id=?";
                $stmt = $db->prepare($sql);
                $stmt->execute([$page_name, $page_url, $page_url_readonly, $remark, $page_id]);
            } else {
                // Insert
                $sort_order = $db->query("SELECT IFNULL(MAX(sort_order), 0) + 1 FROM system_module_pages")->fetchColumn();
                $sql = "INSERT INTO system_module_pages (page_name, page_url, page_url_readonly, sort_order, remark) VALUES (?, ?, ?, ?, ?)";
                $stmt = $db->prepare($sql);
                $stmt->execute([$page_name, $page_url, $page_url_readonly, $sort_order, $remark]);
                $page_id = $db->lastInsertId();
            }

            if ($is_ajax) {
                $stmt = $db->prepare("SELECT p.*, g.group_name AS current_group_name FROM system_module_pages p LEFT JOIN system_module_groups g ON p.group_id = g.group_id WHERE p.page_id = ?");
                $stmt->execute([$page_id]);
                $saved_data = $stmt->fetch(PDO::FETCH_ASSOC);
                header('Content-Type: application/json');
                echo json_encode(['status' => 'success', 'message' => '子頁面儲存成功', 'data' => $saved_data, 'is_edit' => $is_edit]);
                exit;
            }
        } elseif ($action === 'delete_page') {
            $page_id = $_POST['page_id'];
            // 檢查是否有模組正在使用此頁面
            $check = $db->prepare("SELECT COUNT(*) FROM system_modules WHERE page_id = ?");
            $check->execute([$page_id]);
            if ($check->fetchColumn() > 0) {
                throw new Exception("無法刪除：尚有模組使用此子頁面");
            }
            $stmt = $db->prepare("DELETE FROM system_module_pages WHERE page_id = ?");
            $stmt->execute([$page_id]);
            if ($is_ajax) {
                header('Content-Type: application/json');
                echo json_encode(['status' => 'success', 'message' => '子頁面刪除成功']);
                exit;
            }
            $message = "子頁面刪除成功";
        }

        // --- Module 操作 ---
        elseif ($action === 'save_module') {
            $module_code = $_POST['module_code'];
            $module_name = $_POST['module_name'];
            $description = $_POST['description'];
            $group_id = !empty($_POST['group_id']) ? $_POST['group_id'] : null;
            $page_id = !empty($_POST['page_id']) ? $_POST['page_id'] : null;

            if (empty($group_id) && empty($page_id)) {
                throw new Exception("請選擇「所屬主項目」或「對應子頁面」(擇一)");
            }
            if (!empty($group_id) && !empty($page_id)) {
                throw new Exception("「所屬主項目」與「對應子頁面」只能擇一填寫");
            }

            $is_edit = $_POST['is_edit'] ?? '0';

            if ($is_edit == '1') {
                // Update (module_code is PK)
                $sql = "UPDATE system_modules SET module_name=?, description=?, group_id=?, page_id=? WHERE module_code=?";
                $stmt = $db->prepare($sql);
                $stmt->execute([$module_name, $description, $group_id, $page_id, $module_code]);
            } else {
                // Insert
                // 檢查 module_code 是否重複
                $check = $db->prepare("SELECT COUNT(*) FROM system_modules WHERE module_code = ?");
                $check->execute([$module_code]);
                if ($check->fetchColumn() > 0) {
                    throw new Exception("模組代碼 (Module Code) 已存在，請勿重複");
                }

                $sort_order = $db->query("SELECT IFNULL(MAX(sort_order), 0) + 1 FROM system_modules")->fetchColumn();
                $sql = "INSERT INTO system_modules (module_code, module_name, description, sort_order, group_id, page_id) VALUES (?, ?, ?, ?, ?, ?)";
                $stmt = $db->prepare($sql);
                $stmt->execute([$module_code, $module_name, $description, $sort_order, $group_id, $page_id]);

                // 自動為超級管理者 (user_id=1) 新增權限
                $permSql = "INSERT INTO user_module_permissions (user_id, module_code, permission, created_at) VALUES (1, ?, 'A', NOW())";
                $db->prepare($permSql)->execute([$module_code]);
            }

            if ($is_ajax) {
                $sql_modules = "SELECT m.*, g.group_name, p.page_name, p.page_url AS module_url FROM system_modules m LEFT JOIN system_module_groups g ON m.group_id = g.group_id LEFT JOIN system_module_pages p ON m.page_id = p.page_id WHERE m.module_code = ?";
                $stmt = $db->prepare($sql_modules);
                $stmt->execute([$module_code]);
                $saved_data = $stmt->fetch(PDO::FETCH_ASSOC);
                header('Content-Type: application/json');
                echo json_encode(['status' => 'success', 'message' => '模組設定儲存成功', 'data' => $saved_data, 'is_edit' => ($is_edit == '1')]);
                exit;
            }
            $message = "模組設定儲存成功";
        } elseif ($action === 'delete_module') {
            $module_code = $_POST['module_code'];
            
            // 刪除模組前，先移除該模組在 user_module_permissions 中的所有權限設定
            $db->prepare("DELETE FROM user_module_permissions WHERE module_code = ?")->execute([$module_code]);

            $stmt = $db->prepare("DELETE FROM system_modules WHERE module_code = ?");
            $stmt->execute([$module_code]);
            if ($is_ajax) {
                header('Content-Type: application/json');
                echo json_encode(['status' => 'success', 'message' => '模組設定刪除成功']);
                exit;
            }
            $message = "模組設定刪除成功";
        }

    } catch (Exception $e) {
        if ($is_ajax) {
            header('Content-Type: application/json');
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
            exit;
        }
        $message = "錯誤：" . $e->getMessage();
    }
}

// --- 讀取資料 ---
@$groups = [];
@$pages = [];
@$modules = [];

try {
    // 取得 Groups
    $groups = $db->query("SELECT g.*, GROUP_CONCAT(p.page_name ORDER BY p.sort_order SEPARATOR ', ') as bound_pages_list FROM system_module_groups g LEFT JOIN system_module_pages p ON g.group_id = p.group_id GROUP BY g.group_id ORDER BY g.sort_order ASC, g.group_id ASC")->fetchAll(PDO::FETCH_ASSOC);
    
    // 取得 Pages
    // Join groups to get current group name for display
    $pages = $db->query("SELECT p.*, g.group_name AS current_group_name FROM system_module_pages p LEFT JOIN system_module_groups g ON p.group_id = g.group_id ORDER BY g.sort_order ASC, p.sort_order ASC")->fetchAll(PDO::FETCH_ASSOC);
    
    // 取得 Modules (包含關聯名稱)
    $sql_modules = "
        SELECT m.*, g.group_name, p.page_name, p.page_url AS module_url
        FROM system_modules m
        LEFT JOIN system_module_groups g ON m.group_id = g.group_id
        LEFT JOIN system_module_pages p ON m.page_id = p.page_id
        ORDER BY m.sort_order ASC, m.module_code ASC";
    $modules = $db->query($sql_modules)->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    // Handle error
}
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>系統模組與權限設定 | Excellentgear</title>

    <!-- Bootstrap -->
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <!-- Custom Theme Style -->
    <link href="../../resource/css/custom.css" rel="stylesheet">
    
    <!-- DataTables -->
    <link href="../../resource/js/dataTables.bootstrap.min.css" rel="stylesheet">

    <style>
        .nav-tabs > li.active > a, .nav-tabs > li.active > a:focus, .nav-tabs > li.active > a:hover {
            background-color: #fff;
            border-bottom-color: transparent;
            font-weight: bold;
            color: #2A3F54;
        }
        .tab-content {
            padding-top: 20px;
            background: #fff;
            border: 1px solid #ddd;
            border-top: 0;
            padding: 20px;
        }
        /* 拖曳時的游標樣式 */
        .datatable-custom tbody tr {
            cursor: move;
        }
        /* 滑鼠移過時的整列變色 */
        .datatable-custom tbody tr:hover > td {
            background-color: #FFFFCC !important;
        }
        /* 綁定子頁面清單：勾選中的項目暖色高亮 */
        #group_pages_container .checkbox {
            padding: 3px 6px;
            border-radius: 3px;
        }
        #group_pages_container .checkbox.eg-checked {
            background-color: #F7E0BD;
        }
        #group_pages_container .checkbox:hover {
            background-color: #FCEFD9;
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
                    <div class="page-title">
                        <div class="title_left">
                            <h3>系統模組與權限設定</h3>
                        </div>
                    </div>
                    <div class="clearfix"></div>

                    <?php if (!empty($message)): ?>
                    <div class="alert alert-info alert-dismissible fade in" role="alert">
                        <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">×</span></button>
                        <strong>系統訊息：</strong> <?= htmlspecialchars($message) ?>
                    </div>
                    <?php endif; ?>

                    <div class="row">
                        <div class="col-md-12 col-sm-12 col-xs-12">
                            <div class="x_panel">
                                <div class="x_content">
                                    
                                    <!-- Tabs -->
                                    <ul class="nav nav-tabs bar_tabs" id="myTab" role="tablist">
                                        <li role="presentation" class="active"><a href="#tab_groups" role="tab" data-toggle="tab" aria-expanded="true">主項目設定 (Groups)</a></li>
                                        <li role="presentation" class=""><a href="#tab_pages" role="tab" data-toggle="tab" aria-expanded="false">子頁面設定 (Pages)</a></li>
                                        <li role="presentation" class=""><a href="#tab_modules" role="tab" data-toggle="tab" aria-expanded="false">模組權限對應 (Modules)</a></li>
                                    </ul>

                                    <div class="tab-content" id="myTabContent">
                                        
                                        <!-- Tab 1: Groups -->
                                        <div role="tabpanel" class="tab-pane fade active in" id="tab_groups" aria-labelledby="home-tab">
                                            <button class="btn btn-success btn-sm" onclick="openGroupModal()">
                                                <i class="fa fa-plus"></i> 新增主項目
                                            </button>
                                            <table id="table_groups" class="table table-striped table-bordered datatable-custom">
                                                <thead>
                                                    <tr>
                                                        <th style="width: 15%;">主項目名稱</th>
                                                        <th style="width: 30%;">已綁定子頁面</th>
                                                        <th style="width: 55%;">備註</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($groups as $row): ?>
                                                    <tr data-id="<?= $row['group_id'] ?>" data-json="<?= htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8') ?>">
                                                        <td><?= htmlspecialchars($row['group_name']) ?></td>
                                                        <td><?= htmlspecialchars($row['bound_pages_list'] ?? '') ?></td>
                                                        <td><?= htmlspecialchars($row['remark']) ?></td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>

                                        <!-- Tab 2: Pages -->
                                        <div role="tabpanel" class="tab-pane fade" id="tab_pages" aria-labelledby="profile-tab">
                                            <button class="btn btn-success btn-sm" onclick="openPageModal()">
                                                <i class="fa fa-plus"></i> 新增子頁面
                                            </button>
                                            <table id="table_pages" class="table table-striped table-bordered datatable-custom">
                                                <thead>
                                                    <tr>
                                                        <th style="width: 10%;">子頁面名稱</th>
                                                        <th style="width: 15%;">所屬主項目</th>
                                                        <th style="width: 25%;">網址 (URL)</th>
                                                        <th style="width: 25%;">唯讀網址</th>
                                                        <th style="width: 25%;">備註</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($pages as $row): ?>
                                                    <tr data-id="<?= $row['page_id'] ?>" data-json="<?= htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8') ?>">
                                                        <td><?= htmlspecialchars($row['page_name']) ?></td>
                                                        <td><?= htmlspecialchars($row['current_group_name'] ?? '') ?></td>
                                                        <td style="word-break: break-all; min-width: 120px;"><?= htmlspecialchars($row['page_url']) ?></td>
                                                        <td style="word-break: break-all; min-width: 120px;"><?= htmlspecialchars($row['page_url_readonly']) ?></td>
                                                        <td><?= htmlspecialchars($row['remark']) ?></td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>

                                        <!-- Tab 3: Modules -->
                                        <div role="tabpanel" class="tab-pane fade" id="tab_modules" aria-labelledby="profile-tab">
                                            <button class="btn btn-success btn-sm" onclick="openModuleModal()">
                                                <i class="fa fa-plus"></i> 新增模組設定
                                            </button>
                                            <table id="table_modules" class="table table-striped table-bordered datatable-custom">
                                                <thead>
                                                    <tr>
                                                        <th style="width: 10%;">模組代碼</th>
                                                        <th style="width: 10%;">模組名稱</th>
                                                        <th style="width: 30%;">主項目 (Group)</th>
                                                        <th style="width: 30%;">對應頁面 (Page)</th>
                                                        <th style="width: 20%;">說明</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($modules as $row): ?>
                                                    <tr data-id="<?= $row['module_code'] ?>" data-json="<?= htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8') ?>">
                                                        <td><?= htmlspecialchars($row['module_code']) ?></td>
                                                        <td><?= htmlspecialchars($row['module_name']) ?></td>
                                                        <td><?= htmlspecialchars($row['group_name']) ?></td>
                                                        <td><?= htmlspecialchars($row['page_name']) ?></td>
                                                        <td><?= htmlspecialchars($row['description']) ?></td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>

                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <!-- /page content -->

            <!-- footer content include -->
            <?php include '../partPage/footer.html' ?>
            <!-- /footer content include -->
        </div>
    </div>

    <!-- Modals -->
    
    <!-- Group Modal -->
    <div class="modal fade" id="groupModal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                        <h4 class="modal-title" id="groupModalTitle">新增主項目</h4>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="save_group">
                        <input type="hidden" name="group_id" id="group_id">
                        <div class="form-group">
                            <label>主項目名稱 (Group Name) *</label>
                            <input type="text" class="form-control" name="group_name" id="group_name" required>
                        </div>
                        <div class="form-group">
                            <label>備註 (Remark)</label>
                            <input type="text" class="form-control" name="remark" id="group_remark">
                        </div>
                        <div class="form-group">
                            <label>綁定子頁面 (Bound Pages) <small class="text-muted">（勾選要納入此主項目的子頁面）</small></label>
                            <div style="display:flex; flex-wrap:wrap; gap:6px; align-items:center; margin-bottom:6px;">
                                <input type="text" id="group_pages_search" class="form-control input-sm" placeholder="輸入關鍵字篩選（頁面名稱／網址，可打部分字）…" style="flex:1; min-width:180px;" autocomplete="off">
                                <button type="button" class="btn btn-default btn-sm" id="btn_select_filtered">全選（篩選結果）</button>
                                <button type="button" class="btn btn-default btn-sm" id="btn_clear_filtered">全不選（篩選結果）</button>
                            </div>
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
                                <span style="font-size:0.9em; color:#8a5a1a;">已勾選 <strong id="group_pages_count">0</strong> 個</span>
                                <label style="font-size:0.9em; color:#8a5a1a; font-weight:normal; margin:0; cursor:pointer;">
                                    <input type="checkbox" id="group_pages_only_checked" style="vertical-align:middle;"> 只顯示已勾選
                                </label>
                            </div>
                            <div id="group_pages_container" style="max-height: 45vh; overflow-y: auto; border: 1px solid #ccc; padding: 10px; background: #f9f9f9;">
                                <!-- Checkboxes will be populated by JS -->
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-danger pull-left" id="btn_delete_group" style="display:none;">刪除</button>
                        <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
                        <button type="submit" class="btn btn-primary">儲存</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Page Modal -->
    <div class="modal fade" id="pageModal" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                        <h4 class="modal-title" id="pageModalTitle">新增子頁面</h4>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="save_page">
                        <input type="hidden" name="page_id" id="page_id">
                        <div class="form-group">
                            <label>子頁面名稱 (Page Name) *</label>
                            <input type="text" class="form-control" name="page_name" id="page_name" required>
                        </div>
                        <div class="form-group">
                            <label>網址 (URL)</label>
                            <input type="text" class="form-control" name="page_url" id="page_url">
                        </div>
                        <div class="form-group">
                            <label>唯讀網址 (Readonly URL)</label>
                            <input type="text" class="form-control" name="page_url_readonly" id="page_url_readonly">
                        </div>
                        <div class="form-group">
                            <label>備註 (Remark)</label>
                            <input type="text" class="form-control" name="remark" id="page_remark">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-danger pull-left" id="btn_delete_page" style="display:none;">刪除</button>
                        <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
                        <button type="submit" class="btn btn-primary">儲存</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Module Modal -->
    <div class="modal fade" id="moduleModal" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <form method="POST">
                    <div class="modal-header">
                        <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                        <h4 class="modal-title" id="moduleModalTitle">新增模組設定</h4>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="save_module">
                        <input type="hidden" name="is_edit" id="module_is_edit" value="0">
                        
                        <div class="form-group">
                            <label>模組代碼 (Module Code) * <small class="text-muted">(英文，唯一值)</small></label>
                            <input type="text" class="form-control" name="module_code" id="module_code" required>
                        </div>
                        <div class="form-group">
                            <label>模組名稱 (Module Name) *</label>
                            <input type="text" class="form-control" name="module_name" id="module_name" required>
                        </div>
                        <div class="form-group">
                            <label>所屬主項目 (Group) <small class="text-muted">(擇一)</small></label>
                            <select class="form-control" name="group_id" id="module_group_id">
                                <option value="">請選擇主項目</option>
                                <?php foreach ($groups as $g): ?>
                                    <option value="<?= $g['group_id'] ?>"><?= htmlspecialchars($g['group_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>對應子頁面 (Page) <small class="text-muted">(擇一)</small></label>
                            <select class="form-control" name="page_id" id="module_page_id">
                                <option value="">請選擇子頁面</option>
                                <?php foreach ($pages as $p): ?>
                                    <option value="<?= $p['page_id'] ?>"><?= htmlspecialchars($p['page_name']) ?> (<?= htmlspecialchars($p['page_url']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>說明 (Description)</label>
                            <textarea class="form-control" name="description" id="module_description" rows="2"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-danger pull-left" id="btn_delete_module" style="display:none;">刪除</button>
                        <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
                        <button type="submit" class="btn btn-primary">儲存</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="../../resource/js/jquery.min.js"></script>
    <script src="../../resource/js/bootstrap.min.js"></script>
    <script src="../../resource/js/custom.min.js"></script>
    <!-- DataTables -->
    <script src="../../resource/js/jquery.dataTables.min.js"></script>
    <script src="../../resource/js/dataTables.bootstrap.min.js"></script>
    <!-- jQuery UI for Sortable -->
    <script src="https://code.jquery.com/ui/1.12.1/jquery-ui.min.js"></script>

    <script>
        // 將 PHP 的 pages 資料傳給 JS 使用
        var allPages = <?= json_encode($pages) ?>;

        $(document).ready(function() {
            $('.datatable-custom').DataTable({
                "language": {
                    "url": "//cdn.datatables.net/plug-ins/1.10.20/i18n/Chinese-traditional.json"
                },
                "pageLength": 50, // 增加每頁顯示數量，方便拖曳排序
                "order": []
            });

            // 保持 Tab 狀態 (如果頁面重新整理)
            var activeTab = localStorage.getItem('activeTab');
            if (activeTab) {
                $('#myTab a[href="' + activeTab + '"]').tab('show');
            }

            $('a[data-toggle="tab"]').on('shown.bs.tab', function (e) {
                localStorage.setItem('activeTab', $(e.target).attr('href'));
            });

            // 啟用拖曳排序 (Sortable)
            $('.datatable-custom tbody').sortable({
                helper: function(e, tr) {
                    var $originals = tr.children();
                    var $helper = tr.clone();
                    $helper.children().each(function(index) {
                        $(this).width($originals.eq(index).width());
                    });
                    return $helper;
                },
                stop: function(e, ui) {
                    var tableId = $(this).closest('table').attr('id');
                    var type = '';
                    if (tableId == 'table_groups') type = 'group';
                    else if (tableId == 'table_pages') type = 'page';
                    else if (tableId == 'table_modules') type = 'module';

                    // 新的排序邏輯只針對子頁面表格
                    if (type === 'page') {
                        var draggedItem = ui.item;
                        var draggedItemData = draggedItem.data('json');
                        var originalGroupId = draggedItemData ? draggedItemData.group_id : null;
                        
                        var rows = $(this).find('tr');
                        var firstItemInGroup = null;
                        var lastItemInGroup = null;

                        // 找出原群組的第一筆與最後一筆 (排除自己)
                        rows.each(function() {
                            if ($(this)[0] === draggedItem[0]) return;
                            var d = $(this).data('json');
                            if (d && d.group_id === originalGroupId) {
                                if (!firstItemInGroup) firstItemInGroup = $(this);
                                lastItemInGroup = $(this);
                            }
                        });

                        if (firstItemInGroup && lastItemInGroup) {
                            var draggedIndex = draggedItem.index();
                            var firstIndex = firstItemInGroup.index();
                            var lastIndex = lastItemInGroup.index();

                            if (draggedIndex < firstIndex) {
                                // 往上超過 -> 移到第一筆
                                draggedItem.insertBefore(firstItemInGroup);
                            } else if (draggedIndex > lastIndex) {
                                // 往下超過 -> 移到最後一筆
                                draggedItem.insertAfter(lastItemInGroup);
                            }
                        } else {
                            // 若群組中無其他項目，檢查是否跨區，若是則取消
                            var prevItem = draggedItem.prev('tr');
                            var nextItem = draggedItem.next('tr');
                            var isOut = false;
                            if (prevItem.length) {
                                var d = prevItem.data('json');
                                if (d && d.group_id !== originalGroupId) isOut = true;
                            }
                            if (nextItem.length) {
                                var d = nextItem.data('json');
                                if (d && d.group_id !== originalGroupId) isOut = true;
                            }
                            if (isOut) $(this).sortable('cancel');
                        }
                    }

                    // 取得最終的順序並發送到後端
                    var order = [];
                    $(this).find('tr').each(function() {
                        order.push($(this).data('id'));
                    });

                    // 發送 AJAX 更新排序
                    $.post('system_module_setting.php', { action: 'reorder_items', type: type, order: order }, function(response){
                        if(response && response.status === 'success'){
                            // 排序成功後，重新載入所有表格以確保資料一致性
                            reloadAllTables();
                            showNotification('success', response.message);
                        } else {
                            showNotification('error', (response && response.message) || '排序更新失敗');
                        }
                    }, 'json').fail(function(xhr) {
                        var errorMsg = '排序請求失敗';
                        if (xhr.responseJSON && xhr.responseJSON.message) {
                            errorMsg = xhr.responseJSON.message;
                        }
                        showNotification('error', errorMsg);
                    });
                }
            });

            // 雙擊編輯 (Double click to edit)
            $('.datatable-custom tbody').on('dblclick', 'tr', function() {
                var data = $(this).data('json');
                if (!data) return;

                var tableId = $(this).closest('table').attr('id');
                if (tableId === 'table_groups') {
                    editGroup(data);
                } else if (tableId === 'table_pages') {
                    editPage(data);
                } else if (tableId === 'table_modules') {
                    editModule(data);
                }
            });

            // --- AJAX Handlers ---

            // Delete Buttons
            $('#btn_delete_group').click(function() {
                if (confirm('確定刪除此主項目？')) {
                    var group_id = $('#group_id').val();
                    ajaxRequest({ action: 'delete_group', group_id: group_id }, function(response) {
                        $('#groupModal').modal('hide');
                    });
                }
            });

            $('#btn_delete_page').click(function() {
                if (confirm('確定刪除此子頁面？')) {
                    var page_id = $('#page_id').val();
                    ajaxRequest({ action: 'delete_page', page_id: page_id }, function(response) {
                        $('#pageModal').modal('hide');
                    });
                }
            });

            $('#btn_delete_module').click(function() {
                if (confirm('確定刪除此模組設定？')) {
                    var module_code = $('#module_code').val();
                    ajaxRequest({ action: 'delete_module', module_code: module_code }, function(response) {
                        $('#moduleModal').modal('hide');
                    });
                }
            });

            // Form Submissions
            $('#groupModal form').on('submit', function(e) {
                e.preventDefault();
                ajaxRequest($(this).serialize(), function(response) {
                    $('#groupModal').modal('hide');
                });
            });

            $('#pageModal form').on('submit', function(e) {
                e.preventDefault();
                ajaxRequest($(this).serialize(), function(response) {
                    $('#pageModal').modal('hide');
                });
            });

            $('#moduleModal form').on('submit', function(e) {
                e.preventDefault();
                ajaxRequest($(this).serialize(), function(response) {
                    $('#moduleModal').modal('hide');
                });
            });

            // --- 綁定子頁面：即時篩選 / 全選 / 計數 ---
            $('#group_pages_search').on('input', filterGroupPages);
            // 雙擊清空搜尋（比照 UI 規範）
            $('#group_pages_search').on('dblclick', function() {
                if ($(this).val() !== '') { $(this).val(''); filterGroupPages(); }
            });
            // 搜尋框按 Enter 不送出表單（避免誤存），只維持篩選
            $('#group_pages_search').on('keydown', function(e) {
                if (e.key === 'Enter') { e.preventDefault(); }
            });
            $('#group_pages_only_checked').on('change', filterGroupPages);

            $('#btn_select_filtered').click(function() {
                $('#group_pages_container .checkbox:visible input[type=checkbox]').prop('checked', true)
                    .closest('.checkbox').addClass('eg-checked');
                updateGroupPagesCount();
            });
            $('#btn_clear_filtered').click(function() {
                $('#group_pages_container .checkbox:visible input[type=checkbox]').prop('checked', false)
                    .closest('.checkbox').removeClass('eg-checked');
                updateGroupPagesCount();
                if ($('#group_pages_only_checked').prop('checked')) { filterGroupPages(); }
            });

            // 勾選變動：更新暖色高亮與計數
            $('#group_pages_container').on('change', 'input[type=checkbox]', function() {
                $(this).closest('.checkbox').toggleClass('eg-checked', $(this).prop('checked'));
                updateGroupPagesCount();
            });

            // 打開弾窗後自動聚焦搜尋框
            $('#groupModal').on('shown.bs.modal', function() {
                $('#group_pages_search').focus();
            });

            // 雙擊搜尋框清除內容
            $(document).on('dblclick', '.dataTables_filter input', function() {
                var table = $(this).closest('.dataTables_wrapper').find('table').DataTable();
                if ($(this).val() !== '') {
                    table.search('').draw();
                    $(this).val('');
                }
            });

            // 模組設定：主項目與子頁面互斥
            $('#module_group_id').change(function() {
                if ($(this).val()) {
                    $('#module_page_id').val('').prop('disabled', true);
                } else {
                    $('#module_page_id').prop('disabled', false);
                }
            });

            $('#module_page_id').change(function() {
                if ($(this).val()) {
                    $('#module_group_id').val('').prop('disabled', true);
                } else {
                    $('#module_group_id').prop('disabled', false);
                }
            });
        });

        function ajaxRequest(data, successCallback, errorCallback) {
            $.ajax({
                type: 'POST',
                url: 'system_module_setting.php',
                data: data,
                dataType: 'json',
                success: function(response) {
                    if (response.status === 'success') {
                        showNotification('success', response.message);
                        reloadAllTables(); // 重新載入所有表格資料
                        if (successCallback) {
                            successCallback(response);
                        }
                    } else {
                        showNotification('error', response.message || '發生未知錯誤');
                        if (errorCallback) errorCallback(response);
                    }
                },
                error: function(xhr) {
                    var errorMsg = '發生未知錯誤';
                    if (xhr.responseJSON && xhr.responseJSON.message) {
                        errorMsg = xhr.responseJSON.message;
                    }
                    showNotification('error', errorMsg);
                    if (errorCallback) errorCallback(xhr);
                }
            });
        }

        function reloadAllTables() {
            $.post('system_module_setting.php', { action: 'fetch_data' }, function(response) {
                if (response.status === 'success') {
                    updateTable('table_groups', response.groups, ['group_name', 'bound_pages_list', 'remark'], 'group_id');
                    updateTable('table_pages', response.pages, ['page_name', 'current_group_name', 'page_url', 'page_url_readonly', 'remark'], 'page_id');
                    updateTable('table_modules', response.modules, ['module_code', 'module_name', 'group_name', 'page_name', 'description'], 'module_code');
                    
                    allPages = response.pages; // 更新全域變數
                    updateModuleModalOptions(response.groups, response.pages); // 更新模組 Modal 的下拉選單
                }
            }, 'json');
        }

        function updateTable(tableId, data, columns, idKey) {
            var table = $('#' + tableId).DataTable();
            table.clear();
            
            data.forEach(function(item) {
                var rowData = [];
                columns.forEach(function(col) {
                    rowData.push(item[col] || '');
                });
                var rowNode = table.row.add(rowData).node();
                $(rowNode).attr('data-id', item[idKey]);
                $(rowNode).attr('data-json', JSON.stringify(item));
                
                // 針對 table_pages 的特定欄位加上樣式 (URL 欄位)
                if (tableId === 'table_pages') {
                    $(rowNode).find('td:eq(2), td:eq(3)').css({'word-break': 'break-all', 'min-width': '120px'});
                }
            });
            table.draw(false);
        }

        function updateModuleModalOptions(groups, pages) {
            var groupSelect = $('#module_group_id');
            var pageSelect = $('#module_page_id');
            
            // 這裡簡單重繪，若要保留當前選擇值需額外處理，但通常 reload 是在儲存後，Modal 已關閉
            groupSelect.empty().append('<option value="">請選擇主項目</option>');
            groups.forEach(function(g) { groupSelect.append(new Option(g.group_name, g.group_id)); });
            
            pageSelect.empty().append('<option value="">請選擇子頁面</option>');
            pages.forEach(function(p) { pageSelect.append(new Option(p.page_name + ' (' + (p.page_url||'') + ')', p.page_id)); });
        }

        function showNotification(type, message) {
            var alertClass = type === 'success' ? 'alert-success' : 'alert-danger';
            var notification = $('<div class="alert ' + alertClass + ' alert-dismissible fade in" role="alert" style="position: fixed; top: 20px; right: 20px; z-index: 1051; min-width: 300px;">' +
                '<button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">×</span></button>' +
                '<strong>系統訊息：</strong> ' + message +
                '</div>');
            $('body').append(notification);
            setTimeout(function() {
                notification.fadeOut(400, function() { $(this).remove(); });
            }, 3000);
        }

        // --- Group Functions ---
        function openGroupModal() {
            $('#groupModalTitle').text('新增主項目');
            $('#group_id').val('');
            $('#group_name').val('');
            $('#group_remark').val('');
            $('#groupModal').modal('show');
            $('#btn_delete_group').hide();
            renderGroupPages(''); // 新增時，不預選任何頁面
        }

        function editGroup(data) {
            $('#groupModalTitle').text('編輯主項目');
            $('#group_id').val(data.group_id);
            $('#group_name').val(data.group_name);
            $('#group_remark').val(data.remark);
            $('#groupModal').modal('show');
            $('#btn_delete_group').show();
            renderGroupPages(data.group_id);
        }

        function renderGroupPages(groupId) {
            var container = $('#group_pages_container');
            container.empty();
            
            if (allPages.length === 0) {
                container.html('<p class="text-muted">尚無子頁面可供綁定</p>');
                return;
            }

            allPages.forEach(function(page) {
                var isChecked = (page.group_id == groupId);
                var groupInfo = '';
                // 如果該頁面已綁定到其他群組，顯示提示
                if (page.group_id && page.group_id != groupId) {
                    groupInfo = ' <span class="text-danger" style="font-size: 0.85em;">(目前屬於: ' + (page.current_group_name || '未知') + ')</span>';
                }

                // 供即時篩選用的搜尋文字（頁面名稱＋網址，轉小寫）
                var searchText = ((page.page_name || '') + ' ' + (page.page_url || '')).toLowerCase()
                    .replace(/&/g, '&amp;').replace(/"/g, '&quot;');

                var html = '<div class="checkbox' + (isChecked ? ' eg-checked' : '') + '" style="margin-top: 4px; margin-bottom: 4px;" data-search="' + searchText + '">';
                html += '<label>';
                html += '<input type="checkbox" name="bound_pages[]" value="' + page.page_id + '" ' + (isChecked ? 'checked' : '') + '>';
                html += ' ' + page.page_name + ' <small class="text-muted">(' + (page.page_url || '無網址') + ')</small>' + groupInfo;
                html += '</label>';
                html += '</div>';
                container.append(html);
            });

            // 重繪後重設篩選與計數
            $('#group_pages_search').val('');
            $('#group_pages_only_checked').prop('checked', false);
            filterGroupPages();
            updateGroupPagesCount();
        }

        // 即時篩選綁定子頁面清單（部分字元 + 只顯示已勾選）
        function filterGroupPages() {
            var kw = ($('#group_pages_search').val() || '').toLowerCase().trim();
            var onlyChecked = $('#group_pages_only_checked').prop('checked');
            $('#group_pages_container .checkbox').each(function() {
                var $box = $(this);
                var matchKw = kw === '' || (($box.data('search') || '') + '').indexOf(kw) !== -1;
                var matchChecked = !onlyChecked || $box.find('input[type=checkbox]').prop('checked');
                $box.toggle(matchKw && matchChecked);
            });
        }

        function updateGroupPagesCount() {
            var n = $('#group_pages_container input[type=checkbox]:checked').length;
            $('#group_pages_count').text(n);
        }

        // --- Page Functions ---
        function openPageModal() {
            $('#pageModalTitle').text('新增子頁面');
            $('#page_id').val('');
            $('#page_name').val('');
            $('#page_url').val('');
            $('#page_url_readonly').val('');
            $('#page_remark').val('');
            $('#pageModal').modal('show');
            $('#btn_delete_page').hide();
        }

        function editPage(data) {
            $('#pageModalTitle').text('編輯子頁面');
            $('#page_id').val(data.page_id);
            $('#page_name').val(data.page_name);
            $('#page_url').val(data.page_url);
            $('#page_url_readonly').val(data.page_url_readonly);
            $('#page_remark').val(data.remark);
            $('#pageModal').modal('show');
            $('#btn_delete_page').show();
        }

        // --- Module Functions ---
        function openModuleModal() {
            $('#moduleModalTitle').text('新增模組設定');
            $('#module_is_edit').val('0');
            $('#module_code').val('').prop('readonly', false);
            $('#module_name').val('');
            $('#module_group_id').val('').prop('disabled', false);
            $('#module_page_id').val('').prop('disabled', false);
            $('#module_description').val('');
            $('#moduleModal').modal('show');
            $('#btn_delete_module').hide();
        }

        function editModule(data) {
            $('#moduleModalTitle').text('編輯模組設定');
            $('#module_is_edit').val('1');
            $('#module_code').val(data.module_code).prop('readonly', true); // PK 不可改
            $('#module_name').val(data.module_name);
            $('#module_group_id').val(data.group_id).prop('disabled', !!data.page_id);
            $('#module_page_id').val(data.page_id).prop('disabled', !!data.group_id);
            $('#module_description').val(data.description);
            $('#moduleModal').modal('show');
            $('#btn_delete_module').show();
        }
    </script>
</body>
</html>