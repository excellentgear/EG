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
                        <a href="#dept-position-owner-section" class="btn btn-default btn-sm">指定負責人</a>
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
                                            <input type="text" id="ud-target-filter" class="form-control input-sm" style="margin-bottom:6px;" placeholder="篩選：部門／姓名／職稱…">
                                            <select id="user_id" name="user_id" class="form-control" required></select>
                                        </div>
                                        <div class="form-group col-md-5 col-sm-6 col-xs-12">
                                            <label for="scope_identity">適用職務身分 <small class="text-muted">(可只針對某兼任身分設代理)</small></label>
                                            <select id="scope_identity" name="scope_identity" class="form-control">
                                                <option value="">不分身分（主職／全部，預設）</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="row">
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
                                            <input type="text" id="ud-delegate-filter" class="form-control input-sm" style="margin-bottom:6px;" placeholder="篩選：部門／姓名／職稱…">
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
                                <div class="clearfix" style="margin-bottom:8px;">
                                    <div class="pull-right" style="display:flex; align-items:center; gap:10px;">
                                        <label style="margin:0; font-weight:normal;">每頁
                                            <select id="ud-page-size" class="form-control input-sm" style="display:inline-block; width:auto;">
                                                <option>5</option><option selected>10</option><option>20</option><option>50</option>
                                            </select> 筆
                                        </label>
                                        <span id="ud-pager"></span>
                                    </div>
                                </div>
                                <table class="table table-striped table-hover">
                                    <thead>
                                        <tr>
                                            <th>被代理人</th><th style="width:16%;">適用職務身分</th><th>代理人 (依順序)</th><th>開始日期</th><th>結束日期</th><th style="width: 150px;">操作</th>
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

                    <!-- 3. 部門×職稱 指定負責人設定區塊 (P2) -->
                    <div id="dept-position-owner-section" class="col-md-12 col-sm-12 col-xs-12">
                        <div class="x_panel">
                            <div class="x_title">
                                <h2>部門×職稱 指定負責人 <small style="color:#8a5a2b;">（讓職位代理解析成實際的人、並作為權責分離的主管鏈依據）</small></h2>
                                <ul class="nav navbar-right panel_toolbox">
                                    <li><a class="collapse-link"><i class="fa fa-chevron-up"></i></a></li>
                                    <li><a class="close-link"><i class="fa fa-close"></i></a></li>
                                </ul>
                                <div class="clearfix"></div>
                            </div>
                            <div class="x_content">
                                <div style="margin-bottom:12px; padding:8px 12px; border-radius:4px; background:#fbf1e0; color:#5a3d1a; border:1px solid #e6c48f;">
                                    <i class="fa fa-info-circle"></i> 主管階級（供權責分離自動找上一級主管）在「<b>部門與職稱設定 → 職稱階級管理</b>」維護，可自行增減修改；非主管職稱不必設。
                                </div>
                                <p class="text-muted" style="margin-bottom:10px;">指定某部門某職稱的「負責人」後，選單變更即自動儲存。此人會成為職位代理(職稱→職稱)實際落到的人，也是 SoD 直升主管鏈的解析依據。</p>
                                <div style="margin-bottom:8px;">
                                    <input type="text" id="dpo-filter" class="form-control input-sm" style="display:inline-block; width:260px;" placeholder="輸入部門/職稱過濾…">
                                    <label style="margin-left:10px; font-weight:normal;"><input type="checkbox" id="dpo-only-unset"> 只看未指定</label>
                                </div>
                                <table class="table table-striped table-hover">
                                    <thead>
                                        <tr>
                                            <th style="width:28%;">部門</th>
                                            <th style="width:24%;">職稱</th>
                                            <th style="width:14%;">主管階級</th>
                                            <th>指定負責人</th>
                                        </tr>
                                    </thead>
                                    <tbody id="dept-position-owner-body"></tbody>
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
                                            <th>需指定代理人</th>
                                            <th>最高簽核層級</th>
                                            <th>請假粒度</th>
                                            <th>需附證明</th>
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

                    <!-- 6. 請假系統設定（2026-07-29 新增）-->
                    <div id="leave-setting-section" class="col-md-12 col-sm-12 col-xs-12">
                        <div class="x_panel">
                            <div class="x_title">
                                <h2>請假系統設定 <small>簽核補位・補請假期限・工時基準・證明文件存放位置</small></h2>
                                <ul class="nav navbar-right panel_toolbox">
                                    <li><a class="collapse-link"><i class="fa fa-chevron-up"></i></a></li>
                                </ul>
                                <div class="clearfix"></div>
                            </div>
                            <div class="x_content">
                                <div class="row">
                                    <div class="col-md-6 form-group">
                                        <label for="set_final_decider">最終裁決者</label>
                                        <select class="form-control" id="set_final_decider"></select>
                                        <div class="text-muted" style="font-size:12px;">
                                            當申請人的主管鏈往上找不到下一級主管時（例如申請人本身已是最高階，或上層部門未設指定負責人），由這位裁決。未設定時會暫掛管理員。
                                        </div>
                                    </div>
                                    <div class="col-md-3 form-group">
                                        <label for="set_backdate_days">補請假上限（天）</label>
                                        <input type="number" class="form-control" id="set_backdate_days" min="0" step="1">
                                        <div class="text-muted" style="font-size:12px;">起始日早於今天幾天內仍可送單；超過須洽人事。</div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-3 form-group">
                                        <label for="set_hours_per_day">一天工時（小時）</label>
                                        <input type="number" class="form-control" id="set_hours_per_day" min="1" step="0.5">
                                    </div>
                                    <div class="col-md-3 form-group">
                                        <label for="set_halfday_hours">半天時數（小時）</label>
                                        <input type="number" class="form-control" id="set_halfday_hours" min="0.5" step="0.5">
                                        <div class="text-muted" style="font-size:12px;">半天假的換算基準。</div>
                                    </div>
                                    <div class="col-md-6 form-group">
                                        <label for="set_attach_base">證明文件存放根目錄</label>
                                        <input type="text" class="form-control" id="set_attach_base" placeholder="例：\\excellentnas\人事\ERP請假單附件">
                                        <div class="text-muted" style="font-size:12px;">
                                            只填<b>根目錄</b>；系統會依單號自動建子資料夾，完整路徑於讀取當下即時組出。<span class="text-warning">未設定時無法上傳證明文件。</span>
                                        </div>
                                    </div>
                                </div>
                                <button type="button" class="btn btn-primary" id="btnSaveLeaveSetting">儲存請假系統設定</button>
                                <span id="leaveSettingMsg" style="margin-left:10px;font-size:13px;"></span>
                                <div class="text-muted" style="font-size:12px;margin-top:10px;">
                                    <i class="fa fa-info-circle"></i>
                                    請假的<b>職務代理人</b>沿用本頁上方的「代理人設定」，請假頁不另設一套；主管當日有行程時由其代理人簽核，代理人若正好是申請人則自動直升上一級（權責分離）。
                                </div>
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
                                <label><input type="checkbox" id="need_agent_sign" name="need_agent_sign"> 需指定職務代理人</label>
                                <div class="text-muted" style="font-size:12px;margin-left:20px;">
                                    勾選後，申請此假別時必須從「代理人設定」既有的代理人中指定一位；<b>代理人不參與簽核</b>，核准後系統才通知他接手職務。
                                </div>
                            </div>
                            <hr>
                            <div class="form-group">
                                <label for="unit_type">請假粒度</label>
                                <select class="form-control" id="unit_type" name="unit_type">
                                    <option value="hour">時假（依實際起訖時數計算）</option>
                                    <option value="halfday">半天（不足半天以半天計）</option>
                                    <option value="day">整天（以工作日計，忽略時分）</option>
                                </select>
                            </div>
                            <div class="checkbox">
                                <label><input type="checkbox" id="require_attachment" name="require_attachment"> 需附證明文件（例：病假需診斷證明）</label>
                            </div>
                            <div class="form-group" id="attachOpts" style="margin-left:20px;">
                                <label for="attach_min_days" style="font-weight:400;">超過幾天才需要證明</label>
                                <input type="number" class="form-control" id="attach_min_days" name="attach_min_days" min="0" step="0.5" value="0" style="max-width:160px;">
                                <div class="text-muted" style="font-size:12px;">0＝一律需要。例如填 3 表示請超過 3 天才需附證明。</div>
                                <div class="checkbox" style="margin-top:6px;">
                                    <input type="hidden" name="allow_attach_later" value="0">
                                    <label><input type="checkbox" id="allow_attach_later" name="allow_attach_later" value="1" checked> 允許先送審、事後補件</label>
                                    <div class="text-muted" style="font-size:12px;margin-left:20px;">
                                        不勾選＝沒附證明就不能送出。勾選時單據會標記「待補證明」，但不影響主管簽核。
                                    </div>
                                </div>
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

        // 格式化被代理人/代理人資訊（主職 + 兼任）
        function formatUserInfo(u) {
            if (!u || !u.user_cname) { return `(ID: ${u ? u.id : '?'})`; }
            let html = `<b>${escapeHtml(u.user_cname)}</b><br><small style="color:#8a5a2b;">[主] ${escapeHtml(u.main_department_name || '-')} / ${escapeHtml(u.main_position_name || '-')}</small>`;
            if (u.concurrent_positions) {
                html += `<br><small style="color:#b26a1a;">[兼] ${u.concurrent_positions.split('; ').map(escapeHtml).join('<br>[兼] ')}</small>`;
            }
            return html;
        }

        // 使用者代理：分頁狀態
        let udGroupsList = [];  // 排序後的規則群組陣列（供分頁）
        let udPage = 1;

        function loadUserDelegates() {
            // 如果 allUsers 是空的，表示使用者資料還沒載入，稍後會由 loadUsersToSelect 觸發
            if (allUsers.length === 0) return;

            callApi(DEPT_JOB_API_URL, 'get_user_delegates', 'GET', null, function(response) {
                if (response.status !== 'success') { alert('讀取代理設定失敗: ' + response.message); return; }

                // 依 被代理人 + 職務身分(scope) + 起訖 分組；key = user_id|scopeDep|scopePos|start|end
                groupedByUserAndDate = {};
                response.data.forEach(item => {
                    const dep = item.scope_department_id || '';
                    const pos = item.scope_position_id || '';
                    const key = `${item.user_id}|${dep}|${pos}|${item.start_date}|${item.end_date}`;
                    if (!groupedByUserAndDate[key]) {
                        const user = allUsers.find(u => u.id == item.user_id);
                        if (!user || parseInt(user.state) === 0) return; // 找不到或已離職的被代理人跳過
                        groupedByUserAndDate[key] = {
                            user: user,
                            startDate: item.start_date,
                            endDate: item.end_date,
                            scopeDep: dep,
                            scopePos: pos,
                            scopeLabel: (dep || pos) ? `${escapeHtml(item.scope_department_name || '-')} / ${escapeHtml(item.scope_position_name || '-')}` : '',
                            delegates: []
                        };
                    }
                    const delegate = allUsers.find(u => u.id == item.delegate_id);
                    if (delegate && parseInt(delegate.state) !== 0) {
                        groupedByUserAndDate[key].delegates.push({ ...delegate, priority: item.priority });
                    }
                });

                udGroupsList = Object.keys(groupedByUserAndDate).map(k => ({ key: k, ...groupedByUserAndDate[k] }));
                udPage = 1;
                renderUserDelegateTable();
            });
        }

        function renderUserDelegateTable() {
            const tableBody = $('#user-delegate-table-body');
            tableBody.empty();

            const size = parseInt($('#ud-page-size').val()) || 10;
            const total = udGroupsList.length;
            const pages = Math.max(1, Math.ceil(total / size));
            if (udPage > pages) udPage = pages;
            const startIdx = (udPage - 1) * size;
            const slice = udGroupsList.slice(startIdx, startIdx + size);

            const today = new Date(); today.setHours(0, 0, 0, 0);
            const agentP = window.currentUserPerms.agent || '';

            slice.forEach(rule => {
                let editBtn = '', delBtn = '';
                if (agentP.includes('A') || agentP.includes('U')) editBtn = `<button class="btn btn-sm btn-info btn-edit-user-delegate-group" data-key="${rule.key}">編輯</button>`;
                if (agentP.includes('A') || agentP.includes('D')) delBtn = `<button class="btn btn-sm btn-danger btn-delete-user-delegate-group" data-key="${rule.key}">刪除</button>`;

                const delegatesHtml = rule.delegates
                    .sort((a, b) => a.priority - b.priority)
                    .map(d => `<span class="badge" style="background-color:#f7e0bd; color:#5a3d1a; border:1px solid #e6c48f; margin:2px; font-size:13px; display:inline-block; text-align:left; white-space:normal; padding:8px;"><b>${d.priority}. ${escapeHtml(d.user_cname)}</b> <small style="color:#8a5a2b;">[主] ${escapeHtml(d.main_department_name || '-')}/${escapeHtml(d.main_position_name || '-')}</small></span>`)
                    .join(' ');

                const startDate = rule.startDate ? escapeHtml(rule.startDate.split(' ')[0]) : '-';
                const endDateRaw = rule.endDate ? rule.endDate.split(' ')[0] : '';
                const endDate = endDateRaw ? escapeHtml(endDateRaw) : '-';
                // 到期判定：結束日 < 今天 => 已到期，整列暖色底標示
                const isExpired = endDateRaw && (new Date(endDateRaw) < today);
                const rowStyle = isExpired ? 'background-color:#f2d3c4;' : '';
                const expiredTag = isExpired ? ' <span class="label" style="background-color:#dd5138;">已到期</span>' : '';
                const scopeHtml = rule.scopeLabel ? `<span style="color:#8a5a2b; font-weight:600;">${rule.scopeLabel}</span>` : `<small class="text-muted">不分身分</small>`;

                tableBody.append(`<tr style="vertical-align:top; ${rowStyle}">
                    <td>${formatUserInfo(rule.user)}</td>
                    <td>${scopeHtml}</td>
                    <td style="min-width:250px; line-height:1.8;">${delegatesHtml}</td>
                    <td>${startDate}</td>
                    <td>${endDate}${expiredTag}</td>
                    <td>${editBtn} ${delBtn}</td>
                </tr>`);
            });
            if (total === 0) tableBody.append('<tr><td colspan="6" class="text-center text-muted">尚無代理設定</td></tr>');

            // 分頁列（右上）
            const from = total === 0 ? 0 : startIdx + 1;
            const to = Math.min(startIdx + size, total);
            let pager = `<span style="margin-right:8px;">第 ${from}-${to} / 共 ${total} 筆</span>`;
            pager += `<div class="btn-group btn-group-sm" role="group">`;
            pager += `<button type="button" class="btn btn-default ud-page-btn" data-page="${udPage - 1}" ${udPage <= 1 ? 'disabled' : ''}>‹</button>`;
            pager += `<button type="button" class="btn btn-default" disabled>${udPage}/${pages}</button>`;
            pager += `<button type="button" class="btn btn-default ud-page-btn" data-page="${udPage + 1}" ${udPage >= pages ? 'disabled' : ''}>›</button>`;
            pager += `</div>`;
            $('#ud-pager').html(pager);
        }

        $(document).on('click', '.ud-page-btn', function() {
            const p = parseInt($(this).data('page'));
            if (p >= 1) { udPage = p; renderUserDelegateTable(); }
        });
        $('#ud-page-size').on('change', function() { udPage = 1; renderUserDelegateTable(); });

        // 載入被代理人的職務身分（主職+兼任）到 scope 下拉；selectedValue 供編輯時回填 "dep:pos"
        function loadUserScopes(userId, selectedValue) {
            const sel = $('#scope_identity');
            sel.empty().append('<option value="">不分身分（主職／全部，預設）</option>');
            if (!userId) return;
            callApi(DEPT_JOB_API_URL, 'get_user_scopes', 'GET', { user_id: userId }, function(resp) {
                if (resp.status === 'success') {
                    resp.data.forEach(s => {
                        const tag = parseInt(s.is_main) === 1 ? '[主]' : '[兼]';
                        sel.append(`<option value="${s.department_id}:${s.position_id}">${tag} ${escapeHtml(s.department_name || '-')} - ${escapeHtml(s.position_name || '-')}</option>`);
                    });
                    if (selectedValue) sel.val(selectedValue);
                }
            });
        }

        // 被代理人 / 可選代理人 下拉篩選（選項文字含 [姓名] 部門 - 職稱，關鍵字比對即可）
        function applyOptionFilter(selectId, kw) {
            kw = (kw || '').trim().toLowerCase();
            const isAvail = (selectId === 'available-users');
            $(`#${selectId} option`).each(function() {
                const $o = $(this);
                if ($o.val() === '') return; // 保留「請選擇…」
                const match = !kw || $o.text().toLowerCase().indexOf(kw) !== -1;
                if (isAvail) {
                    const picked = $(`#selected-user-delegates li[data-id="${$o.val()}"]`).length > 0;
                    $o.toggle(match && !picked); // 已挑選者維持隱藏
                } else {
                    $o.toggle(match || $o.is(':selected')); // 目前已選的被代理人不因篩選而消失
                }
            });
        }
        $('#ud-target-filter').on('input', function() { applyOptionFilter('user_id', this.value); });
        $('#ud-delegate-filter').on('input', function() { applyOptionFilter('available-users', this.value); });

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
            applyOptionFilter('available-users', $('#ud-delegate-filter').val());
        });

        $('#remove-from-user-delegates').on('click', function() {
            $('#selected-user-delegates li.selected').each(function() {
                $('#available-users option[value="' + $(this).data('id') + '"]').show();
                $(this).remove();
            });
            applyOptionFilter('available-users', $('#ud-delegate-filter').val());
        });

        $(document).on('click', '#selected-user-delegates li', function() {
            $(this).toggleClass('selected');
        });

        function resetUserDelegateForm() {
            if ($('#userDelegateForm').length === 0) return;
            $('#userDelegateForm')[0].reset();
            $('#original_key').val(''); // 重設時清空 original_key
            $('#scope_identity').empty().append('<option value="">不分身分（主職／全部，預設）</option>'); // 重設職務身分
            $('#ud-target-filter').val(''); $('#ud-delegate-filter').val(''); // 清空篩選字
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

        // 當被代理人變更時，更新可選代理人列表 + 載入其職務身分
        $('#user_id').on('change', function() {
            const mainUserId = $(this).val();
            $('#selected-user-delegates').empty();
            $('#available-users option').show();
            if (mainUserId) {
                $('#available-users option[value="' + mainUserId + '"]').hide();
            }
            applyOptionFilter('available-users', $('#ud-delegate-filter').val());
            loadUserScopes(mainUserId);
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

            // 解析職務身分 scope（value 格式 "dep:pos"；空=不分身分）
            const scopeVal = $('#scope_identity').val() || '';
            let scopeDep = '', scopePos = '';
            if (scopeVal) { const parts = scopeVal.split(':'); scopeDep = parts[0]; scopePos = parts[1]; }

            const data = {
                original_key: $('#original_key').val(), // 提交 original_key
                user_id: userId,
                scope_department_id: scopeDep,
                scope_position_id: scopePos,
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
            // key = user_id|scopeDep|scopePos|start|end
            const parts = key.split('|');
            const userId = parts[0], scopeDep = parts[1] || '', scopePos = parts[2] || '', startDate = parts[3], endDate = parts[4];

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
            // 載入該被代理人的職務身分並回填 scope
            loadUserScopes(userId, (scopeDep || scopePos) ? `${scopeDep}:${scopePos}` : '');

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
                // key = user_id|scopeDep|scopePos|start|end
                const parts = key.split('|');
                const data = {
                    // 使用 original_key 來精準刪除（含 scope）
                    original_key: key,
                    // 為了通過後端驗證，仍需傳遞 user_id, start_date, end_date
                    user_id: parts[0],
                    start_date: parts[3],
                    end_date: parts[4],
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

                        // 請假粒度／證明文件（2026-07-29 請假系統）
                        const unitTxt = {hour:'時假', halfday:'半天', day:'整天'}[item.unit_type] || '時假';
                        let attTxt = '<i class="fa fa-times text-danger"></i>';
                        if (parseInt(item.require_attachment) === 1) {
                            const minD = parseFloat(item.attach_min_days || 0);
                            attTxt = '<i class="fa fa-check text-success"></i>'
                                   + (minD > 0 ? ` <span class="text-muted">(逾 ${minD} 天)</span>` : '')
                                   + (parseInt(item.allow_attach_later) === 1 ? ' <span class="label label-warning">可補件</span>' : ' <span class="label label-default">須先附</span>');
                        }
                        var row = `<tr>
                            <td>${escapeHtml(item.leave_name)}</td>
                            <td>${parseInt(item.need_approval) === 1 ? '<i class="fa fa-check text-success"></i>' : '<i class="fa fa-times text-danger"></i>'}</td>
                            <td>${parseInt(item.agent) === 1 ? '<i class="fa fa-check text-success"></i>' : '<i class="fa fa-times text-danger"></i>'}</td>
                            <td>${escapeHtml(item.max_approval_level)}
                                ${supervisorTitles[item.max_approval_level] ? ` <span class="text-muted">(${supervisorTitles[item.max_approval_level]})</span>` : ''}</td>
                            <td>${unitTxt}</td>
                            <td>${attTxt}</td>
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
            $('#attachOpts').toggle($('#require_attachment').is(':checked'));   // 依勾選顯示證明文件細部設定
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
                        // 請假系統擴充欄位
                        $('#unit_type').val(d.unit_type || 'hour');
                        $('#require_attachment').prop('checked', parseInt(d.require_attachment) === 1).trigger('change');
                        $('#attach_min_days').val(parseFloat(d.attach_min_days || 0));
                        $('#allow_attach_later').prop('checked', parseInt(d.allow_attach_later) === 1);
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
        // ── 請假系統設定（2026-07-29）──────────────────────────────
        $('#require_attachment').on('change', function(){ $('#attachOpts').toggle(this.checked); });
        function loadLeaveSettings() {
            callApi(LEAVE_API_URL, 'get_leave_settings', 'GET', null, function(res) {
                if (res.status !== 'success') return;
                const d = res.data || {};
                const $u = $('#set_final_decider').empty().append('<option value="">（未設定，暫掛管理員）</option>');
                (res.users || []).forEach(function(u) {
                    $u.append('<option value="' + u.id + '">' + escapeHtml(u.user_cname) + '</option>');
                });
                $('#set_final_decider').val(d.leave_final_decider_id || '');
                $('#set_backdate_days').val(d.leave_backdate_limit_days);
                $('#set_hours_per_day').val(d.leave_hours_per_day);
                $('#set_halfday_hours').val(d.leave_halfday_hours);
                $('#set_attach_base').val(d.leave_attach_base);
            });
        }
        $('#btnSaveLeaveSetting').on('click', function() {
            const payload = {
                leave_final_decider_id:    $('#set_final_decider').val(),
                leave_backdate_limit_days: $('#set_backdate_days').val(),
                leave_hours_per_day:       $('#set_hours_per_day').val(),
                leave_halfday_hours:       $('#set_halfday_hours').val(),
                leave_attach_base:         $('#set_attach_base').val()
            };
            callApi(LEAVE_API_URL, 'save_leave_settings', 'POST', payload, function(res) {
                $('#leaveSettingMsg').html(res.status === 'success'
                    ? '<span class="text-success"><i class="fa fa-check"></i> ' + escapeHtml(res.message) + '</span>'
                    : '<span class="text-danger">' + escapeHtml(res.message) + '</span>');
                if (res.status === 'success') setTimeout(function(){ $('#leaveSettingMsg').empty(); }, 3000);
            });
        });
        loadLeaveSettings();

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

        // --- 部門×職稱 指定負責人 (P2) ---
        let dpoData = []; // 快取所有 部門×職稱 綁定

        function loadDeptPositionOwners() {
            if ($('#dept-position-owner-body').length === 0) return;
            callApi(DEPT_JOB_API_URL, 'get_dept_position_owners', 'GET', null, function(resp) {
                if (resp.status !== 'success') { alert('讀取指定負責人失敗: ' + resp.message); return; }
                dpoData = resp.data;
                renderDeptPositionOwners();
            });
        }

        // 職稱階級名稱（動態，來自 position_rank；於「部門與職稱設定」頁維護）
        let positionRanks = [];
        function levelText(level) {
            if (level === null || level === '' || typeof level === 'undefined') return '非主管';
            const r = positionRanks.find(x => String(x.rank_order) === String(level));
            return r ? r.name : ('第' + level + '階');
        }
        function loadPositionRanks(cb) {
            callApi(DEPT_JOB_API_URL, 'get_position_ranks', 'GET', null, function(resp) {
                if (resp.status === 'success') positionRanks = resp.data;
                if (typeof cb === 'function') cb();
            });
        }

        function renderDeptPositionOwners() {
            const tbody = $('#dept-position-owner-body');
            tbody.empty();
            const kw = ($('#dpo-filter').val() || '').trim().toLowerCase();
            const onlyUnset = $('#dpo-only-unset').is(':checked');
            const agentP = window.currentUserPerms.agent || '';
            const canEdit = agentP.includes('A') || agentP.includes('U') || agentP.includes('C');

            let shown = 0;
            dpoData.forEach(row => {
                if (onlyUnset && row.primary_user_id) return;
                const hay = `${row.department_name} ${row.position_name}`.toLowerCase();
                if (kw && hay.indexOf(kw) === -1) return;
                shown++;

                let selHtml;
                if (!canEdit) {
                    selHtml = row.primary_user_name ? escapeHtml(row.primary_user_name) : '<small class="text-muted">未指定</small>';
                } else if (!row.candidates || row.candidates.length === 0) {
                    selHtml = '<small class="text-muted">此部門×職稱目前無在職持有者</small>';
                } else {
                    let opts = '<option value="">— 未指定 —</option>';
                    row.candidates.forEach(c => {
                        opts += `<option value="${c.user_id}" ${String(c.user_id) === String(row.primary_user_id) ? 'selected' : ''}>${escapeHtml(c.user_cname)}</option>`;
                    });
                    selHtml = `<select class="form-control input-sm dpo-owner-select" data-dp-id="${row.id}" style="width:auto; display:inline-block; min-width:160px;">${opts}</select>`;
                }
                const lvlBadge = row.level ? `<span class="label" style="background-color:#b26a1a;">${levelText(row.level)}</span>` : '<span class="label" style="background-color:#c9a06a;">未設階級</span>';

                tbody.append(`<tr>
                    <td>${escapeHtml(row.department_name)}</td>
                    <td>${escapeHtml(row.position_name)}</td>
                    <td>${lvlBadge}</td>
                    <td><span class="dpo-saved-flag" style="color:#3c9d40; display:none; margin-right:8px;"><i class="fa fa-check"></i> 已儲存</span>${selHtml}</td>
                </tr>`);
            });
            if (shown === 0) tbody.append('<tr><td colspan="4" class="text-center text-muted">無符合的資料</td></tr>');
        }

        // 選單變更即自動儲存
        $(document).on('change', '.dpo-owner-select', function() {
            const dpId = $(this).data('dp-id');
            const val = $(this).val();
            const $flag = $(this).closest('td').find('.dpo-saved-flag');
            callApi(DEPT_JOB_API_URL, 'update_dept_position_owner', 'POST', { dp_id: dpId, primary_user_id: val }, function(resp) {
                if (resp.status === 'success') {
                    // 更新快取
                    const row = dpoData.find(r => String(r.id) === String(dpId));
                    if (row) {
                        row.primary_user_id = val || null;
                        const cand = (row.candidates || []).find(c => String(c.user_id) === String(val));
                        row.primary_user_name = cand ? cand.user_cname : null;
                    }
                    $flag.stop(true, true).fadeIn(120).delay(1200).fadeOut(400);
                } else {
                    alert('儲存失敗: ' + resp.message);
                    loadDeptPositionOwners();
                }
            });
        });

        $('#dpo-filter').on('input', renderDeptPositionOwners);
        $('#dpo-only-unset').on('change', renderDeptPositionOwners);

        // 初始載入
        loadUsersToSelect(['user_id', 'available-users']);
        loadPositionsToDelegateSelects();
        loadPositionDelegates();
        loadPositionRanks(function() { loadDeptPositionOwners(); }); // 先載階級名稱，讓指定負責人表的階級顯示正確
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