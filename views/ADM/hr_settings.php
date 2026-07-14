<?php
session_start();
include_once '../../src/common/_config.php';
include ("../../src/common/DBConnection.php");

// --- 權限檢查與設定 ---
$db_connection = new DBConnection();
$conn = $db_connection->getPDO();

if (!isset($_SESSION['userName'])) {
    header("Location:../../index.php");
    exit;
}

$stmt = $conn->prepare("SELECT id FROM user WHERE user_uname = ?");
$stmt->execute([$_SESSION['userName']]);
$currentUser = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$currentUser) {
    header("Location:../../index.php");
    exit;
}

$currentUserId = $currentUser['id'];

// --- 系統頁面權限判斷 (AI 版) ---
$id = $currentUser['id'];
$current_script_path = $_SERVER['PHP_SELF'];

$permission_code = null;
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
            $permission_code = 'A';
        } else {
            sort($unique_chars);
            $permission_code = implode('', $unique_chars);
        }
    }
} catch (Exception $e) {
    error_log("Permission check error: " . $e->getMessage());
}

// 2. 判斷權限並導向
if (empty($permission_code)) {
    header("Location:../../src/store/Login.php?msg=" . urlencode("無權限檢視頁面"));
    exit;
}

if ($permission_code === 'R') {
    if (!empty($page_url_editable) && substr($current_script_path, -strlen($page_url_editable)) === $page_url_editable) {
        if (!empty($page_url_readonly)) {
            header("Location: " . $page_url_readonly);
            exit;
        }
    }
}

// 將頁面權限對應回舊有的變數，以維持頁面內部邏輯 (如區塊顯示/隱藏)
$hrUserPerm = $permission_code;
$agentPerm = $permission_code;
$leaveTypePerm = $permission_code;

// 定義代理設定的表單顯示權限 (需有 A, C 或 U)
$agentCanAdd = (strpos($agentPerm, 'A') !== false || strpos($agentPerm, 'C') !== false);
$agentCanEdit = (strpos($agentPerm, 'A') !== false || strpos($agentPerm, 'U') !== false);
$showAgentForm = ($agentCanAdd || $agentCanEdit);

// 準備傳遞給前端 JS 的權限資料
$jsPerms = [
    'agent' => $agentPerm,
    'leave_type' => $leaveTypePerm,
    'hr_user' => $hrUserPerm
];
// --- 結束權限檢查 ---
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>HR 設定 | Excellentgear</title>

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
                        <h3>HR 設定</h3>
                    </div>
                </div>

                <div class="clearfix"></div>

                <!-- 快速導覽按鈕 -->
                <div class="row">
                    <div class="col-md-12" style="margin-bottom: 20px;">
                        <strong>快速導覽：</strong>
                        <?php if (!empty($agentPerm)): ?>
                        <a href="#user-delegate-section" class="btn btn-default btn-sm">使用者代理設定</a>
                        <a href="#position-delegate-section" class="btn btn-default btn-sm">職稱代理設定</a>
                        <?php endif; ?>
                        <a href="#leave-type-section" class="btn btn-default btn-sm">假別設定</a>
                    </div>
                </div>

                <div class="row">
                    <?php if (!empty($agentPerm)): ?>
                    <!-- 1. 使用者代理設定區塊 -->
                    <div id="user-delegate-section" class="col-md-12 col-sm-12 col-xs-12">
                        <div class="x_panel">
                            <div class="x_title">
                                <h2>使用者代理設定</h2>
                                <ul class="nav navbar-right panel_toolbox">
                                    <li><a class="collapse-link"><i class="fa fa-chevron-up"></i></a></li>
                                    <li><a class="close-link"><i class="fa fa-close"></i></a></li>
                                </ul>
                                <div class="clearfix"></div>
                            </div>
                            <div class="x_content">
                                <?php if ($showAgentForm): ?>
                                <form id="userDelegateForm" style="margin-bottom: 20px;">
                                    <input type="hidden" id="original_key" name="original_key"> <!-- 新增隱藏欄位 -->
                                    <div class="row">
                                        <div class="form-group col-md-4 col-sm-6 col-xs-12">
                                            <label for="user_id">被代理人</label>
                                            <select id="user_id" name="user_id" class="form-control" required></select>
                                        </div>
                                        <div class="form-group col-md-3 col-sm-6 col-xs-12">
                                            <label for="start_date">開始日期</label>
                                            <input type="date" id="start_date" name="start_date" class="form-control" required>
                                        </div>
                                        <div class="form-group col-md-3 col-sm-6 col-xs-12">
                                            <label for="end_date">結束日期</label>
                                            <input type="date" id="end_date" name="end_date" class="form-control" required>
                                        </div>
                                    </div>
                                    <div class="row" style="margin-top: 15px;">
                                        <!-- 可選代理人列表 -->
                                        <div class="col-md-5">
                                            <label>可選的代理人</label>
                                            <select id="available-users" class="form-control" multiple style="height: 200px;"></select>
                                        </div>
                                        <!-- 操作按鈕 -->
                                        <div class="col-md-2 text-center" style="padding-top: 50px;">
                                            <button type="button" id="add-to-user-delegates" class="btn btn-primary" style="margin-bottom: 10px;">加入 &gt;</button><br>
                                            <button type="button" id="remove-from-user-delegates" class="btn btn-danger">&lt; 移除</button>
                                        </div>
                                        <!-- 已選代理人列表 (可排序) -->
                                        <div class="col-md-5">
                                            <label>已選的代理人 (可拖曳排序)</label>
                                            <ul id="selected-user-delegates" class="list-group" style="height: 200px; overflow-y: auto; border: 1px solid #ccc; border-radius: 4px; padding: 5px;">
                                                <!-- JS 動態載入 -->
                                            </ul>
                                        </div>
                                    </div>
                                    <div style="margin-top: 15px;">
                                        <button type="submit" class="btn btn-success">儲存代理設定</button>
                                        <button type="button" id="btn-clear-user-delegate-selection" class="btn btn-default">清空重設</button>
                                    </div>
                                </form>
                                <?php endif; ?>
                                <table class="table table-striped table-hover">
                                    <thead>
                                        <tr>
                                            <th>被代理人</th><th>代理人 (依順序)</th><th>開始日期</th><th>結束日期</th><th style="width: 150px;">操作</th>
                                        </tr>
                                    </thead>
                                    <tbody id="user-delegate-table-body"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- 2. 職稱代理設定區塊 -->
                    <div id="position-delegate-section" class="col-md-12 col-sm-12 col-xs-12">
                        <div class="x_panel">
                            <div class="x_title">
                                <h2>職稱代理設定</h2>
                                <ul class="nav navbar-right panel_toolbox">
                                    <li><a class="collapse-link"><i class="fa fa-chevron-up"></i></a></li>
                                    <li><a class="close-link"><i class="fa fa-close"></i></a></li>
                                </ul>
                                <div class="clearfix"></div>
                            </div>
                            <div class="x_content">
                                <?php if ($showAgentForm): ?>
                                <!-- 職稱代理設定表單 -->
                                <form id="positionDelegateForm" style="margin-bottom: 20px;">
                                    <div class="row">
                                        <div class="form-group col-md-4 col-sm-6 col-xs-12">
                                            <label for="position_id">主職稱</label>
                                            <select id="position_id" name="position_id" class="form-control" required></select>
                                        </div>
                                    </div>
                                    <div class="row" style="margin-top: 15px;">
                                        <!-- 可選職稱列表 -->
                                        <div class="col-md-5">
                                            <label>可選的代理職稱</label>
                                            <select id="available-positions" class="form-control" multiple style="height: 200px;"></select>
                                        </div>
                                        <!-- 操作按鈕 -->
                                        <div class="col-md-2 text-center" style="padding-top: 50px;">
                                            <button type="button" id="add-to-delegates" class="btn btn-primary" style="margin-bottom: 10px;">加入 &gt;</button><br>
                                            <button type="button" id="remove-from-delegates" class="btn btn-danger">&lt; 移除</button>
                                        </div>
                                        <!-- 已選代理職稱列表 (可排序) -->
                                        <div class="col-md-5">
                                            <label>已選的代理職稱 (可拖曳排序)</label>
                                            <ul id="selected-delegates" class="list-group" style="height: 200px; overflow-y: auto; border: 1px solid #ccc; border-radius: 4px; padding: 5px;">
                                                <!-- JS 動態載入 -->
                                            </ul>
                                        </div>
                                    </div>
                                    <div style="margin-top: 15px;">
                                        <button type="submit" class="btn btn-success">儲存代理規則</button>
                                        <button type="button" id="btn-clear-position-selection" class="btn btn-default">清空重設</button>
                                    </div>
                                </form>
                                <?php endif; ?>
                                <hr>
                                <h4>已設定規則</h4>
                                <table class="table table-striped table-hover">
                                    <thead><tr><th style="width: 25%;">主職稱</th><th>代理職稱 (依順序)</th><th style="width: 15%;">操作</th></tr></thead>
                                    <tbody id="position-delegate-table-body"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- 5. 假別設定區塊 -->
                    <div id="leave-type-section" class="col-md-12 col-sm-12 col-xs-12">
                        <div class="x_panel">
                            <div class="x_title">
                                <h2>假別設定 (目前新增修改完跳窗不會消失，須點擊空白處)</h2>
                                <ul class="nav navbar-right panel_toolbox">
                                    <li><a class="collapse-link"><i class="fa fa-chevron-up"></i></a></li>
                                    <li><a class="close-link"><i class="fa fa-close"></i></a></li>
                                </ul>
                                <div class="clearfix"></div>
                            </div>
                            <div class="x_content">
                                <?php if (strpos($leaveTypePerm, 'A') !== false || strpos($leaveTypePerm, 'C') !== false): ?>
                                <button type="button" class="btn btn-primary" style="margin-bottom: 15px;" data-toggle="modal" data-target="#leaveTypeModal" data-action="add">新增假別</button>
                                <?php endif; ?>
                                <table class="table table-striped table-hover">
                                    <thead>
                                        <tr>
                                            <th>假別名稱</th>
                                            <th>需主管簽核</th>
                                            <th>需代理人簽章</th>
                                            <th>最高簽核層級</th>
                                            <th>操作</th>
                                        </tr>
                                    </thead>
                                    <tbody id="leave-type-table-body">
                                        <!-- JS 動態載入 -->
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- /page content -->

        <button class="scroll-to-top" onclick="scrollToTop()">回頂端</button>

        <!-- Leave Type Modal -->
        <div class="modal fade" id="leaveTypeModal" tabindex="-1" role="dialog" aria-labelledby="leaveTypeModalLabel">
            <div class="modal-dialog" role="document">
                <div class="modal-content">
                    <form id="leaveTypeForm">
                        <input type="hidden" id="leave_type_id" name="id">
                        <div class="modal-header">
                            <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                            <h4 class="modal-title" id="leaveTypeModalLabel">假別設定</h4>
                        </div>
                        <div class="modal-body">
                            <div class="form-group">
                                <label for="leave_type_name">假別名稱</label>
                                <input type="text" class="form-control" id="leave_type_name" name="name" required>
                            </div>
                            <div class="form-group">
                                <label for="max_level">最高簽核層級</label>
                                <select class="form-control" id="max_level" name="max_level" required>
                                    <!-- JS 動態載入 -->
                                </select>
                            </div>
                            <div class="checkbox">
                                <label><input type="checkbox" id="need_manager_sign" name="need_manager_sign"> 需主管簽核</label>
                            </div>
                            <div class="checkbox">
                                <label><input type="checkbox" id="need_agent_sign" name="need_agent_sign"> 需代理人簽章</label>
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

        <!-- footer content -->
        <?php include '../partPage/footer.html' ?>
        <!-- /footer content -->
    </div>
</div>

<!-- jQuery -->
<script src="../../resource/js/jquery.min.js"></script>
<!-- Bootstrap -->
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/bootstrap.bundle.min.js"></script>
<!-- FastClick -->
<script src="../../resource/js/fastclick.js"></script>
<!-- NProgress -->
<script src="../../resource/js/nprogress.js"></script>
<!-- SortableJS for drag-and-drop -->
<script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>
<!-- Custom Theme Scripts -->
<script src="../../resource/js/custom.min.js"></script>

<script>
    // 將後端權限資料注入到全域變數
    window.currentUserPerms = <?php echo json_encode($jsPerms); ?>;
</script>

<script>
    $(document).ready(function() {
        const DEPT_JOB_API_URL = '../../src/store/_department_job_title_api.php';
        const USER_API_URL = '../../src/store/_employee_api.php';
        const LEAVE_API_URL = './leave_management_api.php';

        // --- 通用功能 ---
        // HTML 特殊字元跳脫
        function escapeHtml(text) {
            if (text === null || typeof text === 'undefined') return '';
            return String(text).replace(/[&<>"']/g, function(m) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m];
            });
        }

        // 通用 AJAX 函數
        function callApi(url, action, method, data, successCallback) {
            $.ajax({
                url: `${url}?action=${action}`, // 在 URL 中加入 action
                type: method,
                data: data,
                dataType: 'json',
                success: function(response) {
                    // 在 console 中印出成功的 API 回應，方便偵錯
                    console.log(`API Success (${action}):`, response);
                    successCallback(response);
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    // 在 console 中印出詳細的錯誤資訊
                    console.error(`API Error (${action}):`, {
                        status: jqXHR.status,
                        statusText: jqXHR.statusText,
                        responseText: jqXHR.responseText, // 顯示後端回傳的原始內容
                        errorThrown: errorThrown
                    });
                    alert(`請求失敗: ${textStatus} - ${errorThrown}`);
                }
            });
        }

        // 回到頂端功能
        function scrollToTop() { window.scrollTo({ top: 0, behavior: 'smooth' }); }
        $('.scroll-to-top').on('click', scrollToTop);

        // --- 使用者代理相關 ---
        // 平滑捲動至錨點
        $(document).on('click', 'a[href^="#"]', function (event) {
            var target = $(this.getAttribute('href'));
            // 確保目標元素存在於頁面中
            if (target.length) {
                event.preventDefault(); // 防止預設的跳轉行為
                $('html, body').stop().animate({
                    // 捲動到目標位置，減去 60px 以避開頂部導覽列
                    scrollTop: target.offset().top - 60 
                }, 800, 'swing'); // 800ms 動畫時間
            }
        });


        let allUsers = []; // 用於快取所有使用者資料
        let supervisorTitles = {}; // 用於快取主管職稱等級對應

        // 在全域範圍宣告 groupedByUserAndDate 以便編輯功能使用
        let groupedByUserAndDate = {};

        function loadUsersToSelect(selectElementIds) {
            callApi(USER_API_URL, 'get_employees', 'GET', null, function(response) {
                if (response.status === 'success') {
                    // 快取資料並進行排序，使其與員工管理頁面一致 (通常是按 id 排序)
                    allUsers = response.data.sort((a, b) => {
                        // 1. 比較部門名稱
                        const deptCompare = (a.main_department_name || '').localeCompare(b.main_department_name || '');
                        if (deptCompare !== 0) {
                            return deptCompare;
                        }
                        // 2. 如果部門相同，比較職稱名稱
                        const posCompare = (a.position_name || '').localeCompare(b.position_name || '');
                        if (posCompare !== 0) {
                            return posCompare;
                        }
                        // 3. 如果職稱也相同，則按員工編號排序
                        return parseInt(a.id) - parseInt(b.id);
                    });

                    selectElementIds.forEach(function(selectId) {
                        var select = $(`#${selectId}`);
                        select.empty();
                        select.append('<option value="">請選擇...</option>');
                        // 在前端進行篩選，只在下拉選單中顯示 ID > 99999 的使用者
                        allUsers.forEach(function(user) {
                            const optionText = `[${escapeHtml(user.user_cname)}] ${escapeHtml(user.main_department_name)} - ${escapeHtml(user.main_position_name)}`;
                            // 篩選條件：ID > 99999 且非離職員工 (state != 0)
                            if (parseInt(user.id) > 99999 && parseInt(user.state) !== 0) {
                                const option = `<option value="${user.id}">${optionText}</option>`;
                                select.append(option);
                            }
                        });
                    });
                    // 在使用者資料載入後再載入代理設定，確保姓名可以被正確對應
                    loadUserDelegates();
                } else { alert('讀取使用者資料失敗: ' + response.message); }
            });
        }

        function loadUserDelegates() {
            // 如果 allUsers 是空的，表示使用者資料還沒載入，稍後會由 loadUsersToSelect 觸發
            if (allUsers.length === 0) return;

            // 建立一個函式來格式化使用者資訊 (主要與兼任職務)
            const formatUserInfo = (u) => {
                if (!u || !u.user_cname) {
                    return `(ID: ${u.id})`;
                }
                let userInfoHtml = `<b>${escapeHtml(u.user_cname)}</b><br><small style="color: #007bff;">[主] ${escapeHtml(u.main_department_name || '-')} / ${escapeHtml(u.main_position_name || '-')}</small>`;
                if (u.concurrent_positions) {
                    userInfoHtml += `<br><small style="color: #008000;">[兼] ${u.concurrent_positions.split('; ').map(escapeHtml).join('<br>[兼] ')}</small>`;
                }
                return userInfoHtml;
            };

            callApi(DEPT_JOB_API_URL, 'get_user_delegates', 'GET', null, function(response) {
                if (response.status === 'success') {
                    var tableBody = $('#user-delegate-table-body');
                    tableBody.empty();
                    
                    // 將規則按 user_id 和 start_date 分組
                    groupedByUserAndDate = response.data.reduce((acc, item) => {
                        // 建立一個已設定代理的被代理人 ID 集合
                        if (!acc.configuredUserIds) {
                            acc.configuredUserIds = new Set();
                        }
                        const key = `${item.user_id}|${item.start_date}|${item.end_date}`;
                        if (!acc[key]) {
                            const user = allUsers.find(u => u.id == item.user_id);
                            if (!user || parseInt(user.state) === 0) return acc; // 如果找不到被代理人或已離職，則跳過

                            acc[key] = {
                                user: user,
                                startDate: item.start_date,
                                endDate: item.end_date,
                                delegates: []
                            };
                            acc.configuredUserIds.add(String(item.user_id));
                        }
                        const delegate = allUsers.find(u => u.id == item.delegate_id);
                        if (delegate && parseInt(delegate.state) !== 0) { // 只加入在職的代理人
                             acc[key].delegates.push({ ...delegate, priority: item.priority });
                        }
                        return acc;
                    }, {});

                    // 從 groupedByUserAndDate 中移除 'configuredUserIds' 屬性，以便只遍歷規則
                    const configuredUserIds = groupedByUserAndDate.configuredUserIds || new Set();
                    delete groupedByUserAndDate.configuredUserIds;

                    for (const key in groupedByUserAndDate) { // 遍歷代理規則
                        // 檢查權限以決定是否顯示操作按鈕
                        let editBtn = '';
                        let delBtn = '';
                        const agentP = window.currentUserPerms.agent || '';
                        if (agentP.includes('A') || agentP.includes('U')) {
                            editBtn = `<button class="btn btn-sm btn-info btn-edit-user-delegate-group" data-key="${key}">編輯</button>`;
                        }
                        if (agentP.includes('A') || agentP.includes('D')) {
                            delBtn = `<button class="btn btn-sm btn-danger btn-delete-user-delegate-group" data-key="${key}">刪除</button>`;
                        }

                        const rule = groupedByUserAndDate[key];
                        const userDisplay = formatUserInfo(rule.user);
                        
                        // 排序代理人並產生 HTML
                        const delegatesHtml = rule.delegates
                            .sort((a, b) => a.priority - b.priority)
                            .map(d => `<span class="badge" style="background-color: #f0f0f0; color: #333; border: 1px solid #ddd; margin: 2px; font-size: 13px; display: inline-block; text-align: left; white-space: normal; padding: 8px;"><b>${d.priority}. ${escapeHtml(d.user_cname)}</b> <small style="color: #007bff;">[主] ${escapeHtml(d.main_department_name || '-')}/${escapeHtml(d.main_position_name || '-')}</small>${d.concurrent_positions ? ` <small style="color: #008000;">[兼] ${d.concurrent_positions.split('; ').map(escapeHtml).join(', ')}</small>` : ''}</span>`)
                            .join(' ');

                        const startDate = rule.startDate ? escapeHtml(rule.startDate.split(' ')[0]) : '-';
                        const endDate = rule.endDate ? escapeHtml(rule.endDate.split(' ')[0]) : '-';

                        var row = `<tr style="vertical-align: top;">
                            <td>${userDisplay}</td>
                            <td style="min-width: 250px; line-height: 1.8;">${delegatesHtml}</td>
                            <td>${startDate}</td>
                            <td>${endDate}</td>
                            <td>
                                ${editBtn}
                                ${delBtn}
                            </td>
                        </tr>`;
                        tableBody.append(row);
                    }

                    // 更新被代理人下拉選單，隱藏已設定的選項
                    $('#user_id option').each(function() {
                        const userId = $(this).val();
                        if (userId && configuredUserIds.has(userId)) $(this).prop('disabled', true).hide(); else $(this).prop('disabled', false).show();
                    });
                } else { alert('讀取代理設定失敗: ' + response.message); }
            });
        }

        // 使用者代理雙列表操作
        $('#add-to-user-delegates').on('click', function() {
            $('#available-users option:selected').each(function() {
                const val = $(this).val();
                const name = $(this).text();
                if ($(`#selected-user-delegates li[data-id="${val}"]`).length === 0) {
                    $('#selected-user-delegates').append(`<li class="list-group-item" data-id="${val}" draggable="true">${name}</li>`);
                }
                $(this).hide();
            });
        });

        $('#remove-from-user-delegates').on('click', function() {
            $('#selected-user-delegates li.selected').each(function() {
                $('#available-users option[value="' + $(this).data('id') + '"]').show();
                $(this).remove();
            });
        });

        $(document).on('click', '#selected-user-delegates li', function() {
            $(this).toggleClass('selected');
        });

        function resetUserDelegateForm() {
            if ($('#userDelegateForm').length === 0) return;
            $('#userDelegateForm')[0].reset();
            $('#original_key').val(''); // 重設時清空 original_key
            $('#selected-user-delegates').empty();
            // 重設可選列表，同時考慮到被代理人不能是自己
            const mainUserId = $('#user_id').val();
            $('#available-users option').show();
            $('#user_id').prop('disabled', false); // 確保表單重設時，被代理人選單是可用的
            if(mainUserId) {
                $('#available-users option[value="' + mainUserId + '"]').hide();
            }

            // 若無新增權限 (僅有編輯權限)，重設後隱藏儲存按鈕 (回到新增模式)
            const agentP = window.currentUserPerms.agent || '';
            if (!(agentP.includes('A') || agentP.includes('C'))) {
                $('#userDelegateForm button[type="submit"]').hide();
            }
        }
        $('#btn-clear-user-delegate-selection').on('click', resetUserDelegateForm);

        // 當被代理人變更時，更新可選代理人列表
        $('#user_id').on('change', function() {
            const mainUserId = $(this).val();
            $('#selected-user-delegates').empty();
            $('#available-users option').show();
            if (mainUserId) {
                $('#available-users option[value="' + mainUserId + '"]').hide();
            }
        });

        $('#userDelegateForm').on('submit', function(e) {
            e.preventDefault();
            const userId = $('#user_id').val();
            const startDate = $('#start_date').val();
            const endDate = $('#end_date').val();

            if (!userId || !startDate || !endDate) {
                alert('被代理人、開始日期和結束日期為必填！');
                return;
            }

            const delegateIds = $('#selected-user-delegates li').map(function() {
                return $(this).data('id');
            }).get();

            if (delegateIds.length === 0) {
                alert('請至少選擇一位代理人！');
                return;
            }

            const data = {
                original_key: $('#original_key').val(), // 提交 original_key
                user_id: userId,
                start_date: startDate,
                end_date: endDate,
                delegate_ids: delegateIds,
                active: 1 // 預設為啟用
            };

            callApi(DEPT_JOB_API_URL, 'update_user_delegates', 'POST', data, function(response) {
                if (response.status === 'success') {
                    // 移除 alert 提示
                    loadUserDelegates();
                    resetUserDelegateForm();
                } else { 
                    alert('儲存失敗: ' + response.message); 
                }
            });
        });
        
        // 編輯使用者代理群組
        $(document).on('click', '.btn-edit-user-delegate-group', function() {
            const key = $(this).data('key');
            const [userId, startDate, endDate] = key.split('|');
            
            // 1. 重設表單
            resetUserDelegateForm();

            // 若有編輯權限，顯示儲存按鈕
            const agentP = window.currentUserPerms.agent || '';
            if (agentP.includes('A') || agentP.includes('U')) {
                $('#userDelegateForm button[type="submit"]').show();
            }

            // 將原始 key 存入隱藏欄位
            $('#original_key').val(key);

            // 讓已設定的被代理人選項在編輯時暫時可用，但不可更改
            $('#user_id option[value="' + userId + '"]').prop('disabled', false).show();

            // 2. 填入表單資料
            $('#user_id').val(userId);
            $('#start_date').val(startDate);
            $('#end_date').val(endDate);

            // 3. 載入代理人
            const rule = groupedByUserAndDate[key];
            if (rule) {
                rule.delegates.sort((a, b) => a.priority - b.priority).forEach(delegate => {
                    // 模擬點擊可選列表中的選項
                    $('#available-users option[value="' + delegate.id + '"]').prop('selected', true);
                });
                // 觸發加入按鈕
                $('#add-to-user-delegates').trigger('click');
            }
            $('html, body').animate({ scrollTop: $('#user-delegate-section').offset().top }, 500);
        });

        $(document).on('click', '.btn-delete-user-delegate-group', function() {
            if (confirm('您確定要刪除此群組的所有代理設定嗎？')) {
                const key = $(this).data('key');
                const [userId, startDate, endDate] = key.split('|');
                const data = {
                    // 使用 original_key 來精準刪除，即使未來 API 邏輯變更也能適用
                    original_key: key,
                    // 為了通過後端驗證，仍需傳遞 user_id, start_date, end_date
                    user_id: userId,
                    start_date: startDate,
                    end_date: endDate,
                    delegate_ids: [] // 傳送空陣列表示刪除
                };
                callApi(DEPT_JOB_API_URL, 'update_user_delegates', 'POST', data, function(response) {
                    if (response.status === 'success') { alert('刪除成功'); loadUserDelegates(); }
                    else { alert('刪除失敗: ' + response.message); }
                });
            }
        });

        // --- 職稱代理相關 ---
        function loadPositionsToDelegateSelects() {
            // 修正：呼叫 _department_job_title_api.php 的 get_positions action
            // 在全域範圍宣告 groupedByMain 以便編輯功能使用
            let groupedByMain = {};
            callApi(DEPT_JOB_API_URL, 'get_positions', 'GET', null, function(response) { // This should call get_job_titles or get_positions
                if (response.status === 'success') {
                    const allPositions = response.data;
                    const mainSelect = $('#position_id');
                    const availableSelect = $('#available-positions');

                    mainSelect.empty().append('<option value="">請選擇主職稱...</option>');
                    availableSelect.empty();

                    allPositions.forEach(function(pos) {
                        mainSelect.append(`<option value="${pos.id}">${escapeHtml(pos.name)}</option>`);
                        availableSelect.append(`<option value="${pos.id}">${escapeHtml(pos.name)}</option>`);
                    });
                } else {
                    alert('讀取職稱資料失敗: ' + response.message);
                }
            });
        }
        
        function loadPositionDelegates() { 
            // 呼叫 API 獲取已設定的職稱代理規則
            callApi(DEPT_JOB_API_URL, 'get_position_delegates', 'GET', null, function(response) {
                if (response.status === 'success') {
                    const tableBody = $('#position-delegate-table-body');
                    tableBody.empty();
                    groupedByMain = response.data.reduce((acc, item) => {
                        // 建立一個已設定代理的主職稱 ID 集合
                        if (!acc.configuredPositionIds) {
                            acc.configuredPositionIds = new Set();
                        }
                        if (!acc[item.position_id]) {
                            acc[item.position_id] = { name: item.position_name, delegates: [] };
                            acc.configuredPositionIds.add(String(item.position_id));
                        }
                        acc[item.position_id].delegates.push(item);
                        return acc;
                    }, {});

                    // 從 groupedByMain 中移除 'configuredPositionIds' 屬性
                    const configuredPositionIds = groupedByMain.configuredPositionIds || new Set();
                    delete groupedByMain.configuredPositionIds;

                    for (const mainPosId in groupedByMain) { // 遍歷代理規則
                        // 檢查權限以決定是否顯示操作按鈕
                        let editBtn = '';
                        let delBtn = '';
                        const agentP = window.currentUserPerms.agent || '';
                        if (agentP.includes('A') || agentP.includes('U')) {
                            editBtn = `<button class="btn btn-sm btn-info btn-edit-position-delegate" data-id="${mainPosId}">編輯</button>`;
                        }
                        if (agentP.includes('A') || agentP.includes('D')) {
                            delBtn = `<button class="btn btn-sm btn-danger btn-delete-position-delegate" data-id="${mainPosId}">刪除全部</button>`;
                        }

                        const mainPos = groupedByMain[mainPosId];
                        const delegatesHtml = mainPos.delegates
                            .map(d => `<span class="badge" style="background-color: #3498db; margin: 2px; font-size: 13px; display: inline-block;">${d.priority}. ${escapeHtml(d.delegate_position_name)}</span>`)
                            .join('');

                        const row = `<tr>
                            <td>${escapeHtml(mainPos.name)}</td>
                            <td style="line-height: 1.8;">${delegatesHtml}</td>
                            <td>
                                ${editBtn}
                                ${delBtn}
                            </td>
                        </tr>`;
                        tableBody.append(row);
                    }

                    // 更新主職稱下拉選單，隱藏已設定的選項
                    $('#position_id option').each(function() {
                        const posId = $(this).val();
                        if (posId && configuredPositionIds.has(posId)) $(this).prop('disabled', true).hide(); else $(this).prop('disabled', false).show();
                    });
                } else {
                    alert('讀取職稱代理規則失敗: ' + response.message);
                }
            });
        }

        // 雙列表操作
        $('#add-to-delegates').on('click', function() {
            $('#available-positions option:selected').each(function() {
                const val = $(this).val();
                const name = $(this).text();
                if ($(`#selected-delegates li[data-id="${val}"]`).length === 0) {
                    $('#selected-delegates').append(`<li class="list-group-item" data-id="${val}" draggable="true">${name}</li>`);
                }
                $(this).hide();
            });
        });

        $('#remove-from-delegates').on('click', function() {
            $('#selected-delegates li.selected').each(function() {
                $('#available-positions option[value="' + $(this).data('id') + '"]').show();
                $(this).remove();
            });
        });

        $(document).on('click', '#selected-delegates li', function() {
            $(this).toggleClass('selected');
        });

        // 提交儲存
        $('#positionDelegateForm').on('submit', function(e) {
            e.preventDefault();
            const positionId = $('#position_id').val();
            if (!positionId) {
                alert('請選擇一個主職稱！');
                return;
            }
            const delegateIds = $('#selected-delegates li').map(function() {
                return $(this).data('id');
            }).get();

            const data = {
                position_id: positionId,
                delegate_ids: delegateIds
            };

            callApi(DEPT_JOB_API_URL, 'update_position_delegates', 'POST', data, function(response) {
                if (response.status === 'success') {
                    // 移除 alert 提示
                    loadPositionDelegates();
                    resetPositionDelegateForm();
                } else {
                    alert('儲存失敗: ' + response.message);
                }
            });
        });

        // 編輯按鈕
        $(document).on('click', '.btn-edit-position-delegate', function() {
            const mainPosId = $(this).data('id');
            
            // 1. 重設表單
            resetPositionDelegateForm();

            // 若有編輯權限，顯示儲存按鈕
            const agentP = window.currentUserPerms.agent || '';
            if (agentP.includes('A') || agentP.includes('U')) {
                $('#positionDelegateForm button[type="submit"]').show();
            }

            // 2. 設定主職稱
            $('#position_id').val(mainPosId);

            // 3. 載入代理職稱
            const rule = groupedByMain[mainPosId];
            if (rule && rule.delegates) {
                rule.delegates.forEach(delegate => {
                    // 模擬點擊可選列表中的選項，然後觸發加入按鈕
                    $('#available-positions option[value="' + delegate.delegate_position_id + '"]').prop('selected', true);
                });
                $('#add-to-delegates').trigger('click');
            }
            $('html, body').animate({ scrollTop: $('#position-delegate-section').offset().top }, 500);
        });

        // 刪除按鈕
        $(document).on('click', '.btn-delete-position-delegate', function() {
            if (confirm('您確定要刪除此主職稱的所有代理規則嗎？')) {
                const positionId = $(this).data('id');
                // 傳送一個空的代理列表來達到刪除的效果
                const data = { position_id: positionId, delegate_ids: [] };
                callApi(DEPT_JOB_API_URL, 'update_position_delegates', 'POST', data, function(response) {
                    if (response.status === 'success') {
                        alert('刪除成功');
                        loadPositionDelegates();
                    } else {
                        alert('刪除失敗: ' + response.message);
                    }
                });
            }
        });

        function resetPositionDelegateForm() {
            if ($('#positionDelegateForm').length === 0) return;
            $('#positionDelegateForm')[0].reset();
            $('#selected-delegates').empty();
            $('#available-positions option').show();

            // 若無新增權限，重設後隱藏儲存按鈕
            const agentP = window.currentUserPerms.agent || '';
            if (!(agentP.includes('A') || agentP.includes('C'))) {
                $('#positionDelegateForm button[type="submit"]').hide();
            }
        }

        $('#btn-clear-position-selection').on('click', resetPositionDelegateForm);

        // --- 假別設定相關 ---
        function loadSupervisorTitlesForSelect() {
            callApi(DEPT_JOB_API_URL, 'get_job_titles', 'GET', null, function(response) {
                if (response.status === 'success') {
                    const select = $('#max_level');
                    select.empty();
                    supervisorTitles = {}; // 清空舊資料

                    // 篩選出主管職稱並按層級分組
                    const supervisorsByLevel = response.data.reduce((acc, title) => {
                        if (title.level && parseInt(title.level) > 0) {
                            if (!acc[title.level]) acc[title.level] = [];
                            acc[title.level].push(escapeHtml(title.name));
                        }
                        return acc;
                    }, {});

                    // 產生下拉選單選項並快取職稱文字
                    for (const level in supervisorsByLevel) {
                        const titles = supervisorsByLevel[level].join(' / ');
                        supervisorTitles[level] = titles; // 快取
                        select.append(`<option value="${level}">${level} (${titles})</option>`);
                    }
                    // 確保主管職稱載入後，才載入假別列表以顯示正確的職稱文字
                    loadLeaveTypes();
                }
            });
        }
        function loadLeaveTypes() {
            callApi(LEAVE_API_URL, 'get_leave_types', 'GET', null, function(response) {
                if (response.status === 'success') {
                    var tableBody = $('#leave-type-table-body');
                    tableBody.empty();
                    response.data.forEach(function(item) {
                        // 檢查權限以決定是否顯示操作按鈕
                        let editBtn = '';
                        let delBtn = '';
                        const leaveP = window.currentUserPerms.leave_type || '';
                        if (leaveP.includes('A') || leaveP.includes('U')) {
                            editBtn = `<button class="btn btn-sm btn-info btn-edit-leave-type" data-id="${item.id}" data-toggle="modal" data-target="#leaveTypeModal" data-action="edit">編輯</button>`;
                        }
                        if (leaveP.includes('A') || leaveP.includes('D')) {
                            delBtn = `<button class="btn btn-sm btn-danger btn-delete-leave-type" data-id="${item.id}">刪除</button>`;
                        }

                        var row = `<tr>
                            <td>${escapeHtml(item.leave_name)}</td>
                            <td>${parseInt(item.need_approval) === 1 ? '<i class="fa fa-check text-success"></i>' : '<i class="fa fa-times text-danger"></i>'}</td>
                            <td>${parseInt(item.agent) === 1 ? '<i class="fa fa-check text-success"></i>' : '<i class="fa fa-times text-danger"></i>'}</td>
                            <td>${escapeHtml(item.max_approval_level)}
                                ${supervisorTitles[item.max_approval_level] ? ` <span class="text-muted">(${supervisorTitles[item.max_approval_level]})</span>` : ''}</td>
                            <td>
                                ${editBtn}
                                ${delBtn}
                            </td>
                        </tr>`;
                        tableBody.append(row);
                    });
                } else { alert('讀取假別資料失敗: ' + response.message); }
            });
        }
        $('#leaveTypeModal').on('show.bs.modal', function(event) {
            var button = $(event.relatedTarget);
            var action = button.data('action');
            var modal = $(this);
            modal.find('form')[0].reset();
            modal.find('#leave_type_id').val('');
            var form = modal.find('form');
            if (action === 'add') {
                modal.find('.modal-title').text('新增假別');
                form.data('action', 'add_leave_type'); // 設定 action
            } else {
                modal.find('.modal-title').text('編輯假別');
                form.data('action', 'update_leave_type'); // 設定 action
                var id = button.data('id');
                modal.find('#leave_type_id').val(id);
                callApi(LEAVE_API_URL, 'get_leave_type_details', 'GET', { id: id }, function(response) {
                    if (response.status === 'success') {
                        var d = response.data;
                        $('#leave_type_name').val(d.leave_name);
                        $('#max_level').val(d.max_approval_level);
                        $('#need_manager_sign').prop('checked', parseInt(d.need_approval) === 1);
                        $('#need_agent_sign').prop('checked', parseInt(d.agent) === 1);
                    } else { alert('讀取資料失敗: ' + response.message); modal.modal('hide'); }
                });
            }
        });
        $('#leaveTypeForm').on('submit', function(e) {
            e.preventDefault();
            var action = $(this).data('action'); // 從 form 的 data 屬性獲取 action
            callApi(LEAVE_API_URL, action, 'POST', $(this).serialize(), function(response) {
                if (response.status === 'success') {
                    $('#leaveTypeModal').modal('hide'); // 在成功後自動關閉視窗
                    loadLeaveTypes();
                } else { alert('操作失敗: ' + response.message); }
            });
        });
        $(document).on('click', '.btn-delete-leave-type', function() {
            if (confirm('您確定要刪除此假別嗎？')) {
                callApi(LEAVE_API_URL, 'delete_leave_type', 'POST', { id: $(this).data('id') }, function(response) { // 移除多餘的 alert，統一操作體驗
                    if (response.status === 'success') {
                        // alert('刪除成功'); // 移除 alert，直接重載列表
                        loadLeaveTypes();
                    } 
                    else { alert('刪除失敗: ' + response.message); }
                });
            }
        });

        // --- 根據權限初始化 UI ---
        function initUIByPermissions() {
            // Agent 權限控制 (新增)
            const agentP = window.currentUserPerms.agent || '';
            const canAdd = agentP.includes('A') || agentP.includes('C');
            // const canEdit = agentP.includes('A') || agentP.includes('U'); // 用於邏輯判斷，此處僅需判斷是否隱藏預設按鈕

            // 若無新增權限，預設隱藏儲存按鈕 (表單預設為新增模式)
            if (!canAdd) {
                $('#userDelegateForm button[type="submit"], #positionDelegateForm button[type="submit"]').hide();
            }

            // Leave Type 權限控制 (新增)
            const leaveP = window.currentUserPerms.leave_type || '';
            const canAddLeave = leaveP.includes('A') || leaveP.includes('C');
            if (!canAddLeave) {
                $('button[data-target="#leaveTypeModal"][data-action="add"]').hide();
            }
        }

        // 初始載入
        loadUsersToSelect(['user_id', 'available-users']);
        loadPositionsToDelegateSelects();
        loadPositionDelegates();
        loadSupervisorTitlesForSelect(); // 載入主管職稱，並在其成功回呼中觸發 loadLeaveTypes()

        // 初始化 SortableJS
        new Sortable(document.getElementById('selected-user-delegates'), {
            animation: 150
        });
        new Sortable(document.getElementById('selected-delegates'), {
            animation: 150
        });

        // 執行 UI 權限控制
        initUIByPermissions();
    });
</script>
</body>
</html>