<?php
session_start();

// 檢查是否登入
if (!isset($_SESSION['userName'])) {
    header("Location:../../index.php");
    exit;
}

// 引入共用的資料庫連線設定
include_once '../../src/common/_config.php';
include ("../../src/common/DBConnection.php");

$db_connection = new DBConnection();
$conn = $db_connection->getPDO();

// 獲取當前使用者的 ID
$stmt = $conn->prepare("SELECT id FROM user WHERE user_uname = ?");
$stmt->execute([$_SESSION['userName']]);
$currentUser = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$currentUser) {
    header("Location:../../index.php");
    exit;
}

// --- 系統頁面權限判斷 (AI 版) ---
$id = $currentUser['id'];
$current_script_path = $_SERVER['PHP_SELF'];

$deptPerm = null; // Resulting permission string
$page_url_editable = '';
$page_url_readonly = '';

try {
    // 1. 依據 URL 找到頁面
    $sql_page_info = "
        SELECT smp.page_id, smp.page_url, smp.page_url_readonly, smp.group_id
        FROM system_module_pages smp
        WHERE (:script LIKE CONCAT('%', smp.page_url) AND smp.page_url IS NOT NULL AND smp.page_url != '')
           OR (:script LIKE CONCAT('%', smp.page_url_readonly) AND smp.page_url_readonly IS NOT NULL AND smp.page_url_readonly != '')
        LIMIT 1
    ";
    $stmt_page_info = $conn->prepare($sql_page_info);
    $stmt_page_info->execute([':script' => $current_script_path]);
    $page_info = $stmt_page_info->fetch(PDO::FETCH_ASSOC);

    if ($page_info) {
        $page_url_editable = $page_info['page_url'];
        $page_url_readonly = $page_info['page_url_readonly'];
        $page_id = $page_info['page_id'];
        $group_id = $page_info['group_id'];

        // 2. 優先檢查 Page Scope 權限
        $sql_page_perm = "SELECT permission FROM user_module_permissions WHERE user_id = ? AND scope = 'page' AND module_code = ?";
        $stmt_page_perm = $conn->prepare($sql_page_perm);
        $stmt_page_perm->execute([$id, $page_id]);
        $perms_found = $stmt_page_perm->fetchAll(PDO::FETCH_COLUMN);

        // 3. 若無 Page Scope，檢查 Group Scope
        if (empty($perms_found) && !empty($group_id)) {
            $sql_group_module = "SELECT module_code FROM system_modules WHERE group_id = ? LIMIT 1";
            $stmt_group_module = $conn->prepare($sql_group_module);
            $stmt_group_module->execute([$group_id]);
            $group_module_code = $stmt_group_module->fetchColumn();

            if ($group_module_code) {
                $sql_group_perm = "SELECT permission FROM user_module_permissions WHERE user_id = ? AND scope = 'group' AND module_code = ?";
                $stmt_group_perm = $conn->prepare($sql_group_perm);
                $stmt_group_perm->execute([$id, $group_module_code]);
                $perms_found = $stmt_group_perm->fetchAll(PDO::FETCH_COLUMN);
            }
        }

        // 4. 整合權限
        $all_chars = [];
        foreach ($perms_found as $pStr) {
            $chars = str_split($pStr);
            $all_chars = array_merge($all_chars, $chars);
        }
        $unique_chars = array_unique($all_chars);
        
        if (in_array('A', $unique_chars)) {
            $deptPerm = 'A';
        } else {
            sort($unique_chars);
            $deptPerm = implode('', $unique_chars);
        }
    }
} catch (Exception $e) {
    error_log("Permission check error: " . $e->getMessage());
}

// 2. 判斷權限並導向
if (empty($deptPerm)) {
    header("Location:../../src/store/Login.php?msg=" . urlencode("無權限檢視頁面"));
    exit;
}

if ($deptPerm === 'R') {
    if (!empty($page_url_editable) && substr($current_script_path, -strlen($page_url_editable)) === $page_url_editable) {
        if (!empty($page_url_readonly)) {
            header("Location: " . $page_url_readonly);
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <!-- Meta, title, CSS, favicons, etc. -->
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>部門與職稱設定 | Excellentgear</title>

    <!-- Bootstrap -->
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <!-- NProgress -->
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <!-- Custom Theme Style -->
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <style>
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
                        <h3>部門與職稱設定 <small>(權限：<?php echo htmlspecialchars($deptPerm); ?>)</small></h3>
                    </div>
                </div>

                <div class="clearfix"></div>

                <div class="row">
                    <div class="col-md-12" style="margin-bottom: 10px;">
                        <a href="#job-title-section" class="btn btn-default btn-sm"><i class="fa fa-arrow-down"></i> 快速移動至職稱設定</a>
                    </div>
                </div>

                <div class="row">
                    <!-- 1. 部門設定區塊 -->
                    <div class="col-md-12 col-sm-12 col-xs-12">
                        <div class="x_panel">
                            <div class="x_title">
                                <h2>部門設定</h2>
                                <ul class="nav navbar-right panel_toolbox">
                                    <li><a class="collapse-link"><i class="fa fa-chevron-up"></i></a></li>
                                    <li><a class="close-link"><i class="fa fa-close"></i></a></li>
                                </ul>
                                <div class="clearfix"></div>
                            </div>
                            <div class="x_content">
                                <?php if (strpos($deptPerm, 'A') !== false || strpos($deptPerm, 'C') !== false): ?>
                                <button type="button" class="btn btn-primary" style="margin-bottom: 15px;" data-toggle="modal" data-target="#addDepartmentModal">新增部門</button>
                                <?php endif; ?>
                                <table class="table table-striped table-hover">
                                    <thead>
                                        <tr>
                                            <th style="width: 10%;">排序</th>
                                            <th style="width: 20%;">部門名稱</th>
                                            <th style="width: 20%;">上層部門</th>
                                            <th style="width: 15%;">層級</th>
                                            <th style="width: 25%;">已綁定職稱</th>
                                            <th style="width: 120px;">操作</th>
                                        </tr>
                                    </thead>
                                    <tbody id="department-table-body">
                                        <!-- JavaScript 動態載入部門資料 -->
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- 2. 職稱設定區塊 -->
                    <div id="job-title-section" class="col-md-12 col-sm-12 col-xs-12">
                        <div class="x_panel">
                            <div class="x_title">
                                <h2>職稱設定</h2>
                                <ul class="nav navbar-right panel_toolbox">
                                    <li><a class="collapse-link"><i class="fa fa-chevron-up"></i></a></li>
                                    <li><a class="close-link"><i class="fa fa-close"></i></a></li>
                                </ul>
                                <div class="clearfix"></div>
                            </div>
                            <div class="x_content">
                                <?php if (strpos($deptPerm, 'A') !== false || strpos($deptPerm, 'C') !== false): ?>
                                <button type="button" class="btn btn-primary" style="margin-bottom: 15px;"
                                    data-toggle="modal" data-target="#addJobTitleModal">新增職稱</button>
                                <?php endif; ?>
                                <div class="row" id="job-title-list">
                                    <!-- 這裡將用 JavaScript 動態載入職稱資料 -->
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- /page content -->

        <button class="scroll-to-top" onclick="scrollToTop()">回頂端</button>

        <!-- Add Job Title Modal -->
        <div class="modal fade" id="addJobTitleModal" tabindex="-1" role="dialog"
            aria-labelledby="addJobTitleModalLabel">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <form id="addJobTitleForm">
                        <div class="modal-header">
                            <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                            <h4 class="modal-title" id="addJobTitleModalLabel">新增職稱</h4>
                        </div>
                        <div class="modal-body">
                        <div class="form-group">
                            <label for="job_title_sort_order">排序</label>
                            <input type="number" class="form-control" id="job_title_sort_order" name="sort_order" value="0" required>
                        </div>
                            <div class="form-group">
                                <label for="job_title_name">職稱名稱</label>
                                <input type="text" class="form-control" id="job_title_name" name="name" required>
                            </div>
                            <div class="form-group">
                                <label for="job_title_level">職稱層級</label>
                                <select class="form-control" id="job_title_level" name="level">
                                    <option value="">非主管</option>
                                    <option value="1">一階主管</option>
                                    <option value="2">二階主管</option>
                                    <option value="3">三階主管</option>
                                </select>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
                            <button type="submit" class="btn btn-primary">儲存</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

<!-- Add Department Modal -->
<div class="modal fade" id="addDepartmentModal" tabindex="-1" role="dialog" aria-labelledby="addDepartmentModalLabel">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form id="addDepartmentForm">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                    <h4 class="modal-title" id="addDepartmentModalLabel">新增部門</h4>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label for="department_sort_order">排序</label>
                        <input type="number" class="form-control" id="department_sort_order" name="sort_order" value="0" required>
                    </div>
                    <div class="form-group">
                        <label for="department_name">部門名稱</label>
                        <input type="text" class="form-control" id="department_name" name="name" required>
                    </div>
                    <div class="form-group">
                        <label for="department_level">層級</label>
                        <select class="form-control" id="department_level" name="level" required>
                            <option value="">請選擇...</option>
                            <option value="1">最高階層</option>
                            <option value="2">總經理 / 副總層</option>
                            <option value="3">部門 / 課室</option>
                            <option value="4">組別</option>
                            <option value="5">小組（備用層）</option>
                        </select>
                    </div>
                    <div class="form-group" id="parent_department_group" style="display: none;">
                        <label for="parent_department_id">上層部門</label>
                        <select class="form-control" id="parent_department_id" name="parent_id">
                            <!-- JS 動態載入 -->
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
                    <button type="submit" class="btn btn-primary">儲存</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Department Modal -->
<div class="modal fade" id="editDepartmentModal" tabindex="-1" role="dialog" aria-labelledby="editDepartmentModalLabel">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form id="editDepartmentForm">
                <input type="hidden" id="edit_department_id" name="id">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                    <h4 class="modal-title" id="editDepartmentModalLabel">編輯部門</h4>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label for="edit_department_sort_order">排序</label>
                        <input type="number" class="form-control" id="edit_department_sort_order" name="sort_order" value="0" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_department_name">部門名稱</label>
                        <input type="text" class="form-control" id="edit_department_name" name="name" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_department_level">層級</label>
                        <select class="form-control" id="edit_department_level" name="level" required>
                            <option value="">請選擇...</option>
                            <option value="1">最高階層</option>
                            <option value="2">總經理 / 副總層</option>
                            <option value="3">部門 / 課室</option>
                            <option value="4">組別</option>
                            <option value="5">小組（備用層）</option>
                        </select>
                    </div>
                    <div class="form-group" id="edit_parent_department_group" style="display: none;">
                        <label for="edit_parent_department_id">上層部門</label>
                        <select class="form-control" id="edit_parent_department_id" name="parent_id">
                            <!-- JS 動態載入 -->
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
                    <button type="submit" class="btn btn-primary">儲存變更</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Job Title Modal -->
<div class="modal fade" id="editJobTitleModal" tabindex="-1" role="dialog" aria-labelledby="editJobTitleModalLabel">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form id="editJobTitleForm">
                <input type="hidden" id="edit_job_title_id" name="id">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                    <h4 class="modal-title" id="editJobTitleModalLabel">編輯職稱</h4>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label for="edit_job_title_sort_order">排序</label>
                        <input type="number" class="form-control" id="edit_job_title_sort_order" name="sort_order" value="0" required></div>
                    <div class="form-group">
                        <label for="edit_job_title_name">職稱名稱</label>
                        <input type="text" class="form-control" id="edit_job_title_name" name="name" required>
                    </div>
                    <div class="form-group">
                        <label for="edit_job_title_level">職稱層級</label>
                        <select class="form-control" id="edit_job_title_level" name="level">
                            <option value="">非主管</option>
                            <option value="1">一階主管</option>
                            <option value="2">二階主管</option>
                            <option value="3">三階主管</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
                    <button type="submit" class="btn btn-primary">儲存變更</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Assign Position Modal -->
<div class="modal fade" id="assignPositionModal" tabindex="-1" role="dialog" aria-labelledby="assignPositionModalLabel">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form id="assignPositionForm">
                <input type="hidden" id="assign_department_id" name="department_id">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                    <h4 class="modal-title" id="assignPositionModalLabel">設定職稱</h4>
                </div>
                <div class="modal-body">
                    <p>正在為「<strong id="assign_department_name"></strong>」設定職稱</p>
                    <div class="form-group">
                        <label for="position_ids">選擇職稱 (可多選)</label>
                        <select class="form-control" id="position_ids" name="position_ids[]" multiple="multiple" required size="10">
                            <!-- JS 動態載入職稱 -->
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
                    <button type="submit" class="btn btn-primary">儲存綁定</button>
                </div>
            </form>
        </div>
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
<!-- Custom Theme Scripts -->
<script src="../../resource/js/custom.min.js"></script>

<script>
    // 回到頂端功能
    function scrollToTop() {
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    // 平滑捲動至錨點
    $(document).on('click', 'a[href^="#"]', function (event) {
        var target = $(this.getAttribute('href'));
        if (target.length) {
            event.preventDefault();
            $('html, body').stop().animate({
                scrollTop: target.offset().top - 60 // 減去 60px 以避開頂部導覽列
            }, 800);
        }
    });
</script>

<script>
    // 將後端權限資料注入到全域變數
    window.deptPerm = "<?php echo $deptPerm ? $deptPerm : ''; ?>";
</script>

<script>
    $(document).ready(function() {
        const API_URL = '../../src/store/_department_job_title_api.php';

        // HTML 特殊字元跳脫
        function escapeHtml(text) {
            if (text === null || typeof text === 'undefined') return '';
            return String(text).replace(/[&<>"']/g, function(m) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m];
            });
        }

        // 通用 AJAX 函數
        function callApi(action, method, data, successCallback) {
            $.ajax({
                url: `${API_URL}?action=${action}`,
                type: method,
                data: data,
                dataType: 'json',
                success: successCallback,
                error: function(jqXHR, textStatus, errorThrown) {
                    alert(`請求失敗: ${textStatus} - ${errorThrown}`);
                }
            });
        }

        // 載入部門列表
        function loadDepartments() {
            callApi('get_departments', 'GET', null, function(response) {
                if (response.status === 'success') {
                    var tableBody = $('#department-table-body');
                    tableBody.empty();
                    response.data.forEach(function(item) {
                        let editBtn = '';
                        let assignBtn = '';
                        let delBtn = '';
                        
                        const perm = window.deptPerm || '';
                        if (perm.includes('A') || perm.includes('U')) {
                            editBtn = `<button class="btn btn-sm btn-info btn-edit-department" data-id="${item.id}">編輯部門</button>`;
                            assignBtn = `<button class="btn btn-sm btn-success btn-assign-position" data-id="${item.id}" data-name="${escapeHtml(item.name)}">設定職稱</button>`;
                        }
                        if (perm.includes('A') || perm.includes('D')) {
                            delBtn = `<button class="btn btn-sm btn-danger btn-delete-department" data-id="${item.id}">刪除</button>`;
                        }

                        var row = `<tr>
                            <td>${escapeHtml(item.sort_order) || '0'}</td>
                            <td>${escapeHtml(item.name) || ''}</td>
                            <td>${escapeHtml(item.parent_name) || '-'}</td>
                            <td>${getLevelText(item.level)}</td>
                            <td>${escapeHtml(item.assigned_positions) || '<i class="text-muted">未設定</i>'}</td>
                            <td class="text-nowrap">
                                ${editBtn}
                                ${assignBtn}
                                ${delBtn}
                            </td>
                        </tr>`;
                        tableBody.append(row);
                    });
                } else {
                    alert('讀取部門資料失敗: ' + response.message);
                }
            });
        }

        // 獲取層級顯示文字
        function getLevelText(level) {
            switch (parseInt(level)) {
                case 1:
                    return '最高階層';
                case 2:
                    return '總經理 / 副總層';
                case 3:
                    return '部門 / 課室';
                case 4:
                    return '組別';
                case 5:
                    return '小組（備用層）';
                default:
                    return '未知';
            }
        }

        // 載入上層部門 (level=1) 到下拉選單
        function loadParentDepartments(selectElementId) {
            callApi('get_parent_departments', 'GET', null, function(response) {
                if (response.status === 'success') {
                    var select = $(`#${selectElementId}`);
                    select.empty();
                    select.append('<option value="">-- 無 --</option>'); // 允許沒有上層
                    response.data.forEach(function(item) {
                        var option = `<option value="${item.id}">${escapeHtml(item.name)}</option>`;
                        select.append(option);
                    });
                } else {
                    alert('讀取部門資料失敗: ' + response.message);
                }
            });
        }

        // 載入職稱列表 (水平排列)
        function loadJobTitles() {
            callApi('get_job_titles', 'GET', null, function(response) {
                if (response.status === 'success' && response.data) {
                    var jobTitleList = $('#job-title-list');
                    jobTitleList.empty();

                    if (response.data.length === 0) return;

                    var itemsPerColumn = 5;
                    var numColumns = Math.ceil(response.data.length / itemsPerColumn);
                    numColumns = Math.min(numColumns, 4); // 最多 4 欄，若要3欄則改為3
                    var colSize = Math.max(3, Math.floor(12 / numColumns)); // 每欄至少佔3格
                    var colClass = 'col-md-' + colSize;

                    for (let i = 0; i < numColumns; i++) {
                        var column = $(`<div class="${colClass}"></div>`);
                        var table = $('<table class="table table-condensed"><tbody></tbody></table>');
                        var tbody = table.find('tbody');

                        for (let j = i * itemsPerColumn; j < Math.min((i + 1) * itemsPerColumn, response.data.length); j++) {
                            var item = response.data[j];
                            let levelText = '';
                            switch(parseInt(item.level)) {
                                case 1: levelText = '一階主管'; break;
                                case 2: levelText = '二階主管'; break;
                                case 3: levelText = '三階主管'; break;
                                default: levelText = '非主管';
                            }
                            let levelBadge = '';
                            if (item.level) {
                                levelBadge = ` <span class="badge" style="background-color: #1ABB9C;">${levelText}</span>`;
                            } else {
                                levelBadge = ` <span class="badge" style="background-color: #777;">${levelText}</span>`;
                            }

                            let editBtn = '';
                            let delBtn = '';
                            const perm = window.deptPerm || '';
                            if (perm.includes('A') || perm.includes('U')) {
                                editBtn = `<button class="btn btn-xs btn-info btn-edit-job-title" data-id="${item.id}">編輯</button>`;
                            }
                            if (perm.includes('A') || perm.includes('D')) {
                                delBtn = `<button class="btn btn-xs btn-danger btn-delete-job-title" data-id="${item.id}">刪除</button>`;
                            }

                            var row = `<tr>
                                <td><span class="badge">${escapeHtml(item.sort_order) || '0'}</span> ${escapeHtml(item.name)}${levelBadge}</td>
                                <td class="text-right text-nowrap">
                                    ${editBtn}
                                    ${delBtn}
                                </td>
                            </tr>`;
                            tbody.append(row);
                        }
                        column.append(table);
                        jobTitleList.append(column);
                    }
                } else { alert('讀取職稱資料失敗: ' + response.message); }
            });
        }

        // 載入所有職稱到多選框
        function loadPositionsToSelect(selectElementId) {
            callApi('get_job_titles', 'GET', null, function(response) {
                if (response.status === 'success') {
                    var select = $(`#${selectElementId}`);
                    select.empty();
                    response.data.forEach(function(item) {
                        var option = `<option value="${item.id}">${escapeHtml(item.name)}</option>`;
                        select.append(option);
                    });
                } else {
                    alert('讀取職稱資料失敗: ' + response.message);
                }
            });
        }

        // --- Modal 相關事件 ---
        // 當選擇層級時，決定是否顯示 "上層部門" 選單
        $('#department_level, #edit_department_level').on('change', function() {
            var parentGroup = $(this).attr('id').startsWith('edit') ? '#edit_parent_department_group' : '#parent_department_group';
            if (parseInt($(this).val()) > 1) {
                $(parentGroup).show();
            } else {
                $(parentGroup).hide();
            }
        });
        // 當新增 Modal 顯示時，載入課室
        $('#addDepartmentModal').on('show.bs.modal', function () {
            $(this).find('form')[0].reset();
            $('#parent_department_group').hide();
            loadParentDepartments('parent_department_id');
        });

        // --- 部門事件處理 ---
        $('#addDepartmentForm').on('submit', function(e) {
            e.preventDefault();
            callApi('add_department', 'POST', $(this).serialize(), function(response) {
                if (response.status === 'success') {
                    $('#addDepartmentModal').modal('hide');
                    loadDepartments();
                } else { alert('新增失敗: ' + response.message); }
            });
        });

        $(document).on('click', '.btn-edit-department', async function() {
            var id = $(this).data('id');
            $('#editDepartmentForm')[0].reset();
            await loadParentDepartments('edit_parent_department_id');
            callApi('get_department_details', 'GET', { id: id }, function(response) {
                if (response.status === 'success') {
                    $('#edit_department_id').val(response.data.id);
                    $('#edit_department_sort_order').val(response.data.sort_order || 0);
                    $('#edit_department_name').val(response.data.name);
                    $('#edit_department_level').val(response.data.level).trigger('change');
                    if(response.data.parent_id) {
                        $('#edit_parent_department_id').val(response.data.parent_id);
                    }
                    $('#editDepartmentModal').modal('show');
                } else { alert('獲取資料失敗: ' + response.message); }
            });
        });

        $('#editDepartmentForm').on('submit', function(e) {
            e.preventDefault();
            callApi('update_department', 'POST', $(this).serialize(), function(response) {
                if (response.status === 'success') {
                    $('#editDepartmentModal').modal('hide');
                    loadDepartments();
                } else { alert('更新失敗: ' + response.message); }
            });
        });

        $(document).on('click', '.btn-delete-department', function() {
            if (confirm('您確定要刪除此部門嗎？')) {
                var id = $(this).data('id');
                callApi('delete_department', 'POST', { id: id }, function(response) {
                    if (response.status === 'success') {
                        loadDepartments();
                    } else { alert('刪除失敗: ' + response.message); }
                });
            }
        });

        // --- 職稱綁定事件處理 ---
        $(document).on('click', '.btn-assign-position', function() {
            var id = $(this).data('id'); // 從按鈕獲取部門 ID
            var departmentName = $(this).closest('tr').find('td:first').text();

            $('#assign_department_id').val(id);
            $('#assign_department_name').text(departmentName);

            // 1. 載入所有職稱到多選框
            loadPositionsToSelect('position_ids');

            // 2. 獲取該部門已綁定的職稱並選中
            callApi('get_department_positions', 'GET', { department_id: id }, function(response) {
                if (response.status === 'success') {
                    $('#position_ids').val(response.data);
                    $('#assignPositionModal').modal('show');
                } else {
                    alert('獲取綁定資料失敗: ' + response.message);
                }
            });
        });

        $('#assignPositionForm').on('submit', function(e) {
            e.preventDefault();
            callApi('update_department_positions', 'POST', $(this).serialize(), function(response) {
                if (response.status === 'success') {
                    $('#assignPositionModal').modal('hide');
                    loadDepartments(); // 重新載入部門列表以更新顯示
                } else {
                    alert('綁定失敗: ' + response.message);
                }
            });
        });

        // 當新增職稱 Modal 顯示時，重置表單
        $('#addJobTitleModal').on('show.bs.modal', function () {
            $(this).find('form')[0].reset();
            $('#job_title_level').val(''); // 確保下拉選單也重置
        });

        // --- 職稱事件處理 ---
        $('#addJobTitleForm').on('submit', function(e) {
            e.preventDefault();
            // 取得表單資料
            var formData = $(this).serializeArray();
            // 找到職稱名稱欄位並手動 trim
            var nameField = formData.find(field => field.name === 'name');
            if (nameField) {
                nameField.value = nameField.value.trim();
            }
            callApi('add_job_title', 'POST', $.param(formData), function(response) {
                if (response.status === 'success') {
                    $('#addJobTitleModal').modal('hide');
                    loadJobTitles();
                } else { alert('新增失敗: ' + response.message); }
            });
        });

        $(document).on('click', '.btn-edit-job-title', function() {
        var id = $(this).data('id');
            callApi('get_job_title_details', 'GET', { id: id }, function(response) {
                if (response.status === 'success') {
                    $('#edit_job_title_id').val(response.data.id);
                    $('#edit_job_title_sort_order').val(response.data.sort_order || 0);
                    $('#edit_job_title_name').val(response.data.name);
                    $('#edit_job_title_level').val(response.data.level || '');
                    $('#editJobTitleModal').modal('show');
                } else { alert('獲取資料失敗: ' + response.message); }
            });
        });

        $('#editJobTitleForm').on('submit', function(e) {
            e.preventDefault();
            callApi('update_job_title', 'POST', $(this).serialize(), function(response) {
                if (response.status === 'success') {
                    $('#editJobTitleModal').modal('hide');
                    loadJobTitles();
                } else { alert('更新失敗: ' + response.message); }
            });
        });

        $(document).on('click', '.btn-delete-job-title', function() {
            if (confirm('您確定要刪除此職稱嗎？')) {
                var id = $(this).data('id');
                callApi('delete_job_title', 'POST', { id: id }, function(response) {
                    if (response.status === 'success') {
                        loadJobTitles();
                    } else { alert('刪除失敗: ' + response.message); }
                });
            }
        });

        // 初始載入
        loadDepartments();
        loadJobTitles();
    });
</script>
</body>
</html>