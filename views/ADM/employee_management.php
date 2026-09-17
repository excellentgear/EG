<?php
session_start();

if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/ADM/employee_management.php";
    header("Location:../../index.php");
    exit;
}


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

$hrUserPerm = null; // Resulting permission string
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
            $hrUserPerm = 'A';
        } else {
            sort($unique_chars);
            $hrUserPerm = implode('', $unique_chars);
        }
    }
} catch (Exception $e) {
    error_log("Permission check error: " . $e->getMessage());
}

// 2. 判斷權限並導向
if (empty($hrUserPerm)) {
    header("Location:../../src/store/Login.php?msg=" . urlencode("無權限檢視頁面"));
    exit;
}

if ($hrUserPerm === 'R') {
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
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>員工資料管理 | Excellentgear</title>

    <!-- Bootstrap -->
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <!-- NProgress -->
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <!-- Custom Theme Style -->
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <style>
        /* ── 使用說明（全站統一，照抄 views/pm/vendor_audit.php）────────────── */
        .page-help-btn { height:30px; font-size:13px; padding:0 12px; border:1px solid #d98a33; border-radius:15px;
            background:#F0A24B; color:#fff; cursor:pointer; margin-left:auto; }
        .page-help-btn:hover { background:#d98a33; }
        @media print { .page-help-btn { display:none !important; } }
        .help-doc { font-size:13px; color:#5b3a1e; line-height:1.75; }
        .help-doc h4 { color:#8A5A2B; border-bottom:2px solid #F7E0BD; padding-bottom:3px; margin:14px 0 6px; font-size:15px; }
        .help-doc h4:first-child { margin-top:0; }
        .help-doc b { color:#8A5A2B; }
        .help-doc ul { margin:4px 0 8px; padding-left:20px; }
        .help-doc li { margin:2px 0; }
        .help-doc .tip { background:#FFF7E8; border:1px dashed #F0A24B; border-radius:6px; padding:6px 10px; margin:6px 0; }

        /* ── 連線狀態欄（暖色系，見 ai-rules/10）───────────────────────────── */
        .conn-on  { display:inline-block; background:#F7E0BD; color:#7A4A18; border:1px solid #E0BE8A;
                    border-radius:10px; padding:1px 9px; font-size:12px; white-space:nowrap; }
        .conn-off { color:#a5988c; font-size:12px; }
        .conn-time { display:block; font-size:11px; color:#9b8f83; margin-top:2px; }
        .btn-kick { height:24px; line-height:1; font-size:12px; padding:0 9px; margin-top:4px;
                    border:1px solid #b8442a; border-radius:4px; background:#DD5138; color:#fff; cursor:pointer; }
        .btn-kick:hover { background:#b8442a; }
        .btn-kick[disabled] { background:#d8cfc6; border-color:#c3b8ad; color:#fff; cursor:not-allowed; }
        .btn-kick-all { background:#DD5138; color:#fff; border:1px solid #b8442a; }
        .btn-kick-all:hover { background:#b8442a; color:#fff; }

        .concurrent-group {
            padding: 10px;
            border: 1px solid #e6e9ed;
            margin-bottom: 10px;
            background-color: #f8f9fa;
        }
        .concurrent-group legend {
            font-size: 1em;
            font-weight: bold;
            margin-bottom: 5px;
            border-bottom: none;
            width: auto;
            padding: 0 5px;
            /* Flexbox for alignment */
            display: flex;
            justify-content: space-between;
            align-items: center;
            width: 100%; /* Ensure legend takes full width */
        }
        .table th, .table td {
            font-size: 14px; /* 加大表格字體 */
            vertical-align: middle !important; /* 垂直置中 */
        }
        /* 預定離職（仍在職、已填未來離職日）：暖粉底提醒。
           色票見 ai-rules/10 —— 珊瑚紅系列的淺底版，淺底配深棕字。
           已離職者不套用，維持原本樣式（只有紅色「離職」標籤），符合「離職後恢復原底色」。
           specificity 要壓過 .table-striped 的斑馬紋，故加 tbody id 前綴。 */
        #employee-table-body tr.pending-leave > td {
            background-color: #F8DCD5;
            border-top-color: #E9B8AC;
            color: #6B471A;
        }
        #employee-table-body tr.pending-leave:hover > td {
            background-color: #F2CBC1;
        }
        /* 異動紀錄跳窗：內容多時跳窗不可撐破視窗高度，內文自己捲動
           （否則底部的補登按鈕被螢幕下緣遮住按不到） */
        #historyModal .modal-body {
            max-height: calc(100vh - 200px);
            overflow-y: auto;
        }
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
                <div class="page-title" style="display:flex; align-items:center;">
                    <div class="title_left">
                        <h3>員工資料管理 <small>(權限：<?php echo htmlspecialchars($hrUserPerm); ?>)</small></h3>
                    </div>
                    <button type="button" class="page-help-btn" id="btnPageHelp">使用說明</button>
                </div>

                <div class="clearfix"></div>

                <div class="row">
                    <div class="col-md-12 col-sm-12 col-xs-12">
                        <div class="x_panel">
                            <div class="x_title">
                                <h2>員工列表</h2>
                                <ul class="nav navbar-right panel_toolbox">
                                    <li><a class="collapse-link"><i class="fa fa-chevron-up"></i></a></li>
                                    <li><a class="close-link"><i class="fa fa-close"></i></a></li>
                                </ul>
                                <div class="clearfix"></div>
                            </div>
                            <div class="x_content">
                                <div class="row" style="margin-bottom: 15px;">
                                    <div class="col-md-2 col-sm-3 col-xs-12" style="margin-bottom: 8px;">
                                        <button type="button" id="btn-add-employee" class="btn btn-primary" data-toggle="modal" data-target="#employeeModal" data-action="add">新增員工</button>
                                    </div>
                                    <div class="col-md-4 col-sm-5 col-xs-12" style="margin-bottom: 8px;">
                                        <div class="input-group">
                                            <span class="input-group-addon">部門</span>
                                            <select class="form-control dept-filter" id="filter-dept" title="主職務或兼任職務任一符合即列出；左鍵連點兩下解除此篩選">
                                                <option value="">全部</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-4 col-sm-4 col-xs-12" style="margin-bottom: 8px;">
                                        <div class="input-group">
                                            <span class="input-group-addon">搜索</span>
                                            <input type="text" class="form-control" id="table-search" placeholder="搜索框" title="左鍵連點兩下清除資料">
                                        </div>
                                    </div>
                                    <div class="col-md-1 col-sm-12 col-xs-12" style="margin-bottom: 8px;">
                                        <button type="button" class="btn btn-default btn-block" id="btn-clear-filter" title="清除部門／搜索條件">清除</button>
                                    </div>
                                    <div class="col-xs-12" style="margin-bottom:8px;">
                                        <button type="button" class="btn btn-default btn-sm" id="btn-refresh-online"
                                                title="重新讀取目前有誰登入中">重新整理連線狀態</button>
                                        <?php if (strpos((string)$hrUserPerm, 'A') !== false): ?>
                                        <button type="button" class="btn btn-sm btn-kick-all" id="btn-kick-all"
                                                title="切斷所有人的登入連線（保留你自己），供系統維護或還原資料庫前清場">一鍵登出所有人</button>
                                        <?php endif; ?>
                                        <small class="text-muted" id="online-summary" style="margin-left:8px;"></small>
                                    </div>
                                    <div class="col-xs-12">
                                        <small class="text-muted" id="filter-result-count"></small>
                                    </div>
                                </div>

                                <table class="table table-striped table-hover">
                                    <thead>
                                        <tr>
                                            <th>員工編號</th>
                                            <th>登入帳號</th>
                                            <th>姓名</th>
                                            <th>主部門 / 職稱</th>
                                            <th>性別</th>
                                            <th>最高學歷</th>
                                            <th>到職日</th>
                                            <th>特休天數</th>
                                            <th>兼任職務</th>
                                            <th>狀態</th>
                                            <th>備註</th>
                                            <th title="目前是否有登入中的連線；可強制切斷">連線</th>
                                        </tr>
                                    </thead>
                                    <tbody id="employee-table-body">
                                        <!-- JavaScript 動態載入員工資料 -->
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

        <!-- Employee Modal (Add/Edit) -->
        <div class="modal fade" id="employeeModal" tabindex="-1" role="dialog" aria-labelledby="employeeModalLabel">
            <div class="modal-dialog modal-lg" role="document">
                <div class="modal-content">
                    <form id="employeeForm">
                        <!-- 移除隱藏的 id input，改為直接使用員工編號欄位 -->
                        <div class="modal-header">
                            <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                            <h4 class="modal-title" id="employeeModalLabel">員工資料</h4>
                        </div>
                        <div class="modal-body">
                            <!-- 基本資料區塊 -->
                            <div class="row">
                                <div class="col-md-4 form-group">
                                    <label for="user_id_input">員工編號 (ID)</label>
                                    <input type="text" class="form-control" id="user_id_input" name="id" required>
                                </div>
                                <div class="col-md-4 form-group">
                                    <label for="user_uname">登入帳號</label>
                                    <input type="text" class="form-control" id="user_uname" name="user_uname" required>
                                </div>
                                <div class="col-md-4 form-group">
                                    <label for="user_cname">中文姓名</label>
                                    <input type="text" class="form-control" id="user_cname" name="user_cname" required>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-4 form-group">
                                    <label for="password">密碼</label>
                                    <input type="password" class="form-control" id="password" name="password" placeholder="新增時必填，編輯時留空則不修改">
                                    <input type="hidden" name="user_password" id="user_password">
                                </div>
                                <div class="col-md-4 form-group">
                                    <label for="gender">性別</label>
                                    <select class="form-control" id="gender" name="gender">
                                        <option value="">請選擇</option>
                                        <option value="M">男</option>
                                        <option value="F">女</option>
                                    </select>
                                </div>
                                <div class="col-md-4 form-group">
                                    <label for="phone">連絡電話</label>
                                    <input type="text" class="form-control" id="phone" name="phone">
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 form-group">
                                    <label for="highest_education">最高學歷（選填）</label>
                                    <select class="form-control" id="highest_education" name="highest_education">
                                        <option value="">請選擇</option>
                                        <option value="jhs">國中（含）以下</option>
                                        <option value="shs">高中／高職</option>
                                        <option value="college">專科</option>
                                        <option value="univ">大學</option>
                                        <option value="master">碩士</option>
                                        <option value="phd">博士</option>
                                    </select>
                                </div>
                                <div class="col-md-6 form-group">
                                    <label for="second_education">次高學歷（選填）</label>
                                    <select class="form-control" id="second_education" name="second_education">
                                        <option value="">請選擇</option>
                                        <option value="jhs">國中（含）以下</option>
                                        <option value="shs">高中／高職</option>
                                        <option value="college">專科</option>
                                        <option value="univ">大學</option>
                                        <option value="master">碩士</option>
                                        <option value="phd">博士</option>
                                    </select>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-4 form-group">
                                    <label for="user_status">在職狀態</label>
                                    <select class="form-control" id="user_status" name="state" required>
                                        <option value="">請選擇狀態</option>
                                        <option value="1">在職</option>
                                        <option value="2">留職停薪</option>
                                        <option value="3">育嬰留停</option>
                                        <option value="0">離職</option>
                                        <option value="90">特殊帳號(不列入員工)</option>
                                        <option value="99">最高權限帳號</option>
                                    </select>
                                </div>
                                <div class="col-md-4 form-group">
                                    <div class="form-group">
                                        <label for="hire_date">到職日</label>
                                        <input type="date" class="form-control" id="hire_date" name="hire_date">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <!-- 動態日期顯示區塊 -->
                                    <!-- 離職日：在職者可預填「預定離職日」（未來日期），當天仍可用系統，隔天起自動封鎖 -->
                                    <div id="leave_date_group" class="form-group" style="display: none;">
                                        <label for="leave_date" id="leave_date_label">離職日</label>
                                        <input type="date" class="form-control" id="leave_date" name="leave_date">
                                        <small id="leave_date_hint" class="help-block" style="margin:2px 0 0;color:#8a6d3b;"></small>
                                    </div>
                                    <div id="status_dates_group" style="display: none;">
                                        <div class="form-group">
                                            <label for="status_start_date">狀態開始日</label>
                                            <input type="date" class="form-control" id="status_start_date" name="status_start_date">
                                        </div>
                                        <div class="form-group">
                                            <label for="status_end_date">狀態結束日</label>
                                            <input type="date" class="form-control" id="status_end_date" name="status_end_date">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <hr style="margin-top: 10px; margin-bottom: 15px;">
                            <h4>職務設定</h4>
                            <div class="row">
                                <!-- Main Department & Position -->
                                <div class="col-md-6">
                                     <fieldset class="concurrent-group">
                                        <legend>主部門 / 職稱</legend>
                                        <div class="row">
                                            <div class="col-md-6 form-group">
                                                <label>主部門</label>
                                                <select class="form-control department-select" id="main_department_id" name="main_department_id" data-position-target="#main_position_id" required>
                                                    <option value="">請先選擇部門...</option>
                                                </select>
                                            </div>
                                            <div class="col-md-6 form-group">
                                                <label>主職稱</label>
                                                <select class="form-control position-select" id="main_position_id" name="main_position_id" required>
                                                    <option value="">請先選擇部門...</option>
                                                </select>
                                            </div>
                                        </div>
                                    </fieldset>
                                </div>
                                <!-- Concurrent Position 1（與 2、3 一樣預設隱藏：沒設定兼任的人不該看到空白欄位） -->
                                <div class="col-md-6" id="concurrent_group_1" style="display: none;">
                                    <fieldset class="concurrent-group">
                                        <legend>
                                            <span>兼任職務 1</span>
                                            <button type="button" class="btn btn-xs btn-warning btn-clear-concurrent" data-concurrent-index="1" title="清除此兼任職務">
                                                <i class="fa fa-times"></i>
                                            </button>
                                        </legend>
                                        <div class="row">
                                            <div class="col-md-6 form-group">
                                                <label>兼任部門 1</label>
                                                <select class="form-control department-select" id="concurrent_department_id_1" name="concurrent[1][department_id]" data-position-target="#concurrent_position_id_1">
                                                    <option value="">-- 可選 --</option>
                                                </select>
                                            </div>
                                            <div class="col-md-6 form-group">
                                                <label>兼任職稱 1</label>
                                                <select class="form-control position-select" id="concurrent_position_id_1" name="concurrent[1][position_id]">
                                                    <option value="">-- 可選 --</option>
                                                </select>
                                            </div>
                                        </div>
                                    </fieldset>
                                </div>
                            </div>
                            <div class="row">
                                <!-- Concurrent Positions 2 & 3 -->
                                <?php for ($i = 2; $i <= 3; $i++): ?>
                                <!-- Initially hide concurrent positions 2 and 3 -->
                                <div class="col-md-6" id="concurrent_group_<?php echo $i; ?>" style="display: none;">
                                    <fieldset class="concurrent-group" >
                                        <legend>
                                            <span>兼任職務 <?php echo $i; ?></span>
                                            <button type="button" class="btn btn-xs btn-warning btn-clear-concurrent" data-concurrent-index="<?php echo $i; ?>" title="清除此兼任職務">
                                                <i class="fa fa-times"></i>
                                            </button>
                                        </legend>
                                        <div class="row">
                                            <div class="col-md-6 form-group">
                                                <label>兼任部門 <?php echo $i; ?></label>
                                                <select class="form-control department-select" id="concurrent_department_id_<?php echo $i; ?>" name="concurrent[<?php echo $i; ?>][department_id]" data-position-target="#concurrent_position_id_<?php echo $i; ?>">
                                                    <option value="">-- 可選 --</option>
                                                </select>
                                            </div>
                                            <div class="col-md-6 form-group">
                                                <label>兼任職稱 <?php echo $i; ?></label>
                                                <select class="form-control position-select" id="concurrent_position_id_<?php echo $i; ?>" name="concurrent[<?php echo $i; ?>][position_id]">
                                                    <option value="">-- 可選 --</option>
                                                </select>
                                            </div>
                                        </div>
                                    </fieldset>
                                </div>
                                <?php endfor; ?>
                            </div>
                            <!-- 兼任職務改成「要加才長出來」：沒兼任的人不該看到空白欄位（上限 3 個） -->
                            <div class="row">
                                <div class="col-md-12">
                                    <button type="button" class="btn btn-sm btn-default" id="btn-add-concurrent">
                                        <i class="fa fa-plus"></i> 新增兼任職務
                                    </button>
                                    <span id="concurrentHint" class="text-muted" style="font-size:12px; margin-left:8px;"></span>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-danger pull-left" id="btn-delete-in-modal" style="display: none;">刪除</button>
                            <!-- 離職/留停者才出現：清掉殘留的權限設定資料（權限本身已由在職狀態自動擋下） -->
                            <button type="button" class="btn btn-warning pull-left" id="btn-revoke-perm" style="display: none; margin-left: 8px;">清除權限設定</button>
                            <!-- 復職者才出現：目前沒有權限設定、但先前有被清除過紀錄時，一鍵還原離職前的設定 -->
                            <button type="button" class="btn btn-success pull-left" id="btn-restore-perm" style="display: none; margin-left: 8px;">還原離職前權限</button>
                            <!-- 職務調動＋在職狀態的歷史紀錄（含補登過去資料；ai-rules/14 P1） -->
                            <button type="button" class="btn btn-default pull-left" id="btn-history" style="display: none; margin-left: 8px;">異動紀錄</button>
                            <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
                            <button type="submit" class="btn btn-primary">儲存</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- 異動紀錄 Modal：職務調動（user_position_history）＋在職狀態（user_status_history），含補登 -->
        <div class="modal fade" id="historyModal" tabindex="-1" role="dialog" aria-labelledby="historyModalLabel">
            <div class="modal-dialog modal-lg" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                        <h4 class="modal-title" id="historyModalLabel">異動紀錄 — <span id="historyUserName"></span></h4>
                    </div>
                    <div class="modal-body">
                        <h4 style="margin-top:0;">職務調動紀錄</h4>
                        <p class="text-muted" style="font-size:13px; margin-bottom:8px;">
                            教育訓練等頁補登舊資料時，會依這裡的紀錄解析出「當時」的部門與職稱；職位變動過的人請把過去的異動補登進來（生效日填當時實際生效的日期）。
                            主職有換人才選「主職調動」；只是兼任職務有增減或換人、主職沒變，選對應的兼任類型即可，系統會自動算出完整的前後快照。
                            系統異動當下自動寫入的紀錄原則上不可刪除（稽核軌跡），只有補登列可刪；僅超級管理員可強制刪除系統紀錄，供清理測試/錯誤資料用。</p>
                        <div id="posHistPager" class="text-right" style="margin-bottom:4px;"></div>
                        <table class="table table-condensed table-striped">
                            <thead><tr><th>生效日<br><small class="text-muted" style="font-weight:normal;">（登記時間）</small></th><th>類型</th><th>異動前</th><th>異動後</th><th>原因</th><th>來源</th><th style="width:50px;"></th></tr></thead>
                            <tbody id="posHistBody"></tbody>
                        </table>
                        <div id="posBackfillBox" class="concurrent-group">
                            <b>補登職務異動</b>
                            <div class="row" style="margin-top:8px;">
                                <div class="col-md-3 form-group">
                                    <label>異動類型</label>
                                    <select id="bfChangeKind" class="form-control input-sm">
                                        <option value="transfer">主職調動</option>
                                        <option value="concurrent_add">新增兼任</option>
                                        <option value="concurrent_remove">移除兼任</option>
                                        <option value="concurrent_change">更動兼任</option>
                                    </select>
                                </div>
                                <div class="col-md-3 form-group">
                                    <label>生效日</label>
                                    <input type="date" max="9999-12-31" class="form-control input-sm" id="bfEffDate">
                                </div>
                                <div class="col-md-6 form-group">
                                    <label>該日期之前的職務（系統依紀錄自動解析，供核對）</label>
                                    <div id="bfSnapshotPreview" style="font-size:12px; color:#777; padding-top:6px;">請先選生效日</div>
                                </div>
                            </div>

                            <div class="row bf-kind-row" data-kind="transfer">
                                <div class="col-md-4 form-group">
                                    <label>主職異動前（可留空＝之前無主職紀錄）</label>
                                    <div class="row">
                                        <div class="col-xs-6" style="padding-right:4px;"><select id="bfBeforeDept" class="form-control input-sm hist-dept" data-pos-target="#bfBeforePos"><option value="">部門…</option></select></div>
                                        <div class="col-xs-6" style="padding-left:4px;"><select id="bfBeforePos" class="form-control input-sm"><option value="">職稱…</option></select></div>
                                    </div>
                                </div>
                                <div class="col-md-4 form-group">
                                    <label>主職異動後（必填）</label>
                                    <div class="row">
                                        <div class="col-xs-6" style="padding-right:4px;"><select id="bfAfterDept" class="form-control input-sm hist-dept" data-pos-target="#bfAfterPos"><option value="">部門…</option></select></div>
                                        <div class="col-xs-6" style="padding-left:4px;"><select id="bfAfterPos" class="form-control input-sm"><option value="">職稱…</option></select></div>
                                    </div>
                                </div>
                            </div>

                            <div class="row bf-kind-row" data-kind="concurrent_add" style="display:none;">
                                <div class="col-md-6 form-group">
                                    <label>新增的兼任職務（必填）</label>
                                    <div class="row">
                                        <div class="col-xs-6" style="padding-right:4px;"><select id="bfAddDept" class="form-control input-sm hist-dept" data-pos-target="#bfAddPos"><option value="">部門…</option></select></div>
                                        <div class="col-xs-6" style="padding-left:4px;"><select id="bfAddPos" class="form-control input-sm"><option value="">職稱…</option></select></div>
                                    </div>
                                </div>
                            </div>

                            <div class="row bf-kind-row" data-kind="concurrent_remove" style="display:none;">
                                <div class="col-md-6 form-group">
                                    <label>要移除的兼任職務（必填，須是上方預覽中列出的兼任項目）</label>
                                    <div class="row">
                                        <div class="col-xs-6" style="padding-right:4px;"><select id="bfRemoveDept" class="form-control input-sm hist-dept" data-pos-target="#bfRemovePos"><option value="">部門…</option></select></div>
                                        <div class="col-xs-6" style="padding-left:4px;"><select id="bfRemovePos" class="form-control input-sm"><option value="">職稱…</option></select></div>
                                    </div>
                                </div>
                            </div>

                            <div class="row bf-kind-row" data-kind="concurrent_change" style="display:none;">
                                <div class="col-md-4 form-group">
                                    <label>原本的兼任職務（必填，須是上方預覽中列出的兼任項目）</label>
                                    <div class="row">
                                        <div class="col-xs-6" style="padding-right:4px;"><select id="bfChgFromDept" class="form-control input-sm hist-dept" data-pos-target="#bfChgFromPos"><option value="">部門…</option></select></div>
                                        <div class="col-xs-6" style="padding-left:4px;"><select id="bfChgFromPos" class="form-control input-sm"><option value="">職稱…</option></select></div>
                                    </div>
                                </div>
                                <div class="col-md-4 form-group">
                                    <label>更動後的兼任職務（必填）</label>
                                    <div class="row">
                                        <div class="col-xs-6" style="padding-right:4px;"><select id="bfChgToDept" class="form-control input-sm hist-dept" data-pos-target="#bfChgToPos"><option value="">部門…</option></select></div>
                                        <div class="col-xs-6" style="padding-left:4px;"><select id="bfChgToPos" class="form-control input-sm"><option value="">職稱…</option></select></div>
                                    </div>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-8 form-group">
                                    <label>原因（選填）</label>
                                    <input type="text" class="form-control input-sm" id="bfReason" placeholder="例：組織調整、升任組長">
                                </div>
                                <div class="col-md-4" style="padding-top:24px;">
                                    <button type="button" class="btn btn-sm btn-primary" id="btnBackfillPos">補登</button>
                                    <span id="bfPosErr" style="color:#DD5138; margin-left:8px; font-size:13px;"></span>
                                </div>
                            </div>
                        </div>
                        <hr>
                        <h4>在職狀態紀錄（離職／復職／留職停薪／育嬰留停）</h4>
                        <div id="staHistPager" class="text-right" style="margin-bottom:4px;"></div>
                        <table class="table table-condensed table-striped">
                            <thead><tr><th>狀態</th><th>開始日<br><small class="text-muted" style="font-weight:normal;">（登記時間）</small></th><th>結束日</th><th>備註</th><th>來源</th><th style="width:50px;"></th></tr></thead>
                            <tbody id="staHistBody"></tbody>
                        </table>
                        <div id="staBackfillBox" class="concurrent-group">
                            <b>補登在職狀態</b>
                            <div class="row" style="margin-top:8px;">
                                <div class="col-md-3 form-group">
                                    <label>狀態</label>
                                    <select id="bfStaStatus" class="form-control input-sm">
                                        <option value="0">離職</option>
                                        <option value="1">在職（復職）</option>
                                        <option value="2">留職停薪</option>
                                        <option value="3">育嬰留停</option>
                                    </select>
                                </div>
                                <div class="col-md-3 form-group">
                                    <label>開始日</label>
                                    <input type="date" max="9999-12-31" class="form-control input-sm" id="bfStaStart">
                                </div>
                                <div class="col-md-3 form-group">
                                    <label>結束日（選填）</label>
                                    <input type="date" max="9999-12-31" class="form-control input-sm" id="bfStaEnd">
                                </div>
                                <div class="col-md-3 form-group">
                                    <label>備註（選填）</label>
                                    <input type="text" class="form-control input-sm" id="bfStaRemark">
                                </div>
                            </div>
                            <button type="button" class="btn btn-sm btn-primary" id="btnBackfillSta">補登</button>
                            <span id="bfStaErr" style="color:#DD5138; margin-left:8px; font-size:13px;"></span>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-default" data-dismiss="modal">關閉</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- 使用說明（鐵律7：每頁必附）-->
        <div class="modal fade" id="helpUseMask" tabindex="-1" role="dialog" aria-labelledby="helpUseLabel">
            <div class="modal-dialog modal-lg" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                        <h4 class="modal-title" id="helpUseLabel">員工資料管理 — 使用說明</h4>
                    </div>
                    <div class="modal-body help-doc">
                        <h4>這頁在做什麼</h4>
                        <p>維護全公司員工的基本資料、部門職稱（含兼任）、在職狀態與到職／離職日，並管理登入連線。</p>

                        <h4>基本操作</h4>
                        <ul>
                            <li><b>新增員工</b>：左上「新增員工」。員工編號即系統內部識別碼，一旦被單據引用就不該隨意更動；真要改請找系統管理員用專用工具（會同步全庫參照）。</li>
                            <li><b>編輯</b>：在該員工那一列<b>連點兩下</b>開啟編輯視窗。</li>
                            <li><b>篩選</b>：部門下拉會同時比對主職務與兼任職務；搜尋框比對整列文字。兩個欄位<b>連點兩下</b>可清除該條件。</li>
                            <li><b>兼任職務</b>：編輯視窗只會顯示這個人<b>實際設定過的</b>兼任職務（沒有就一格都不顯示），要加請按<b>「新增兼任職務」</b>（最多 3 個）。按兼任職務標題旁的 <b>×</b> 是整列移除，後面的會自動往前遞補，不會留下中間的空格。</li>
                        </ul>

                        <h4>在職狀態與離職</h4>
                        <ul>
                            <li><b>預定離職日</b>可先填未來日期，當天仍能正常使用系統（方便交接結案），<b>隔天 0 點起自動失效</b>。</li>
                            <li>系統會在有人使用時順路檢查並自動把狀態改為離職——所以<b>主機關機期間到期也不會漏掉</b>，開機後第一次有人開頁面就會補做。</li>
                            <li>離職／留職停薪／育嬰留停者一律不能登入，且原有權限即時失效。</li>
                            <li><b>權限設定不會自動刪除</b>（常有誤設或回鍋復職）。要清乾淨請在編輯視窗按「清除權限設定」；復職可按「還原離職前權限」。</li>
                            <li><b>離職只收回「權利」，不清除任何歷史紀錄。</b>「清除權限設定」只會動角色、模組權限、頁面操作權限這幾項，並把代理設定停用（不刪除）。</li>
                            <li>下列資料<b>一律完整保留</b>，離職不會刪除也不會清空：<b>行事曆、通知／公告、請假、簽核紀錄、教育訓練、職務／在職異動紀錄、稽核與登入紀錄</b>，以及職務歸屬（部門／職稱，供舊單據查出「當時他是哪個部門」）。</li>
                            <li><b>「刪除」按鈕只留給誤建帳號。</b>按下時系統會先掃描此人在全系統的紀錄，<b>只要查到任何一筆就擋下</b>並列出是哪幾類——因為帳號一旦被實體刪除，那些紀錄上的人員就變成查不出是誰的孤兒資料，而且不會有任何錯誤訊息。離職請一律改用「在職狀態」，不要用刪除。</li>
                        </ul>

                        <h4>連線狀態與強制登出</h4>
                        <ul>
                            <li><b>連線欄</b>顯示該員工目前是否有登入中的連線，以及大約的最後活動時間。</li>
                            <li><b>強制登出</b>：立刻切斷該員工的登入連線。他下次點任何頁面都會被導回登入頁。</li>
                            <li>在職人員被強制登出後<b>可以馬上重新登入</b>——這個功能是「切斷目前這條連線」，不是停用帳號。要讓人登不進來請改在職狀態。</li>
                            <li><b>一鍵登出所有人</b>：系統維護或還原資料庫前清場用，會保留你自己的連線。需要本頁完整權限（A）。</li>
                            <li>離職者的連線由系統自動切斷，不必手動處理。</li>
                        </ul>
                        <div class="tip">連線時間是近似值（取自伺服器端 session 檔的更新時間），用來判斷「該不該踢」沒問題，但<b>不可以拿來當出勤或工時紀錄</b>。</div>

                        <h4>異動紀錄</h4>
                        <ul>
                            <li>編輯視窗的「異動紀錄」可查該員工的職務調動與在職狀態變化。</li>
                            <li>過去沒登記的異動可以<b>補登</b>（生效日可填過去日期）。系統自動寫入的紀錄不可刪除，只有手動補登的才能刪。</li>
                            <li><b>排序一律最新的在最上面</b>（先看生效日、同一天再看登記進系統的先後）。生效日本身只記到「日」，所以日期下方另外標示<b>登記時間</b>，同一天有多筆異動時就能看出先後——<b>最上面那一筆＝該日最終生效的狀態</b>，全站各表單回推「當時職務」時採用的也是它。</li>
                            <li>補登的紀錄，登記時間是「你補登當下」的時間，不是當年實際異動的時間，只用來分辨同一天多筆的先後順序。</li>
                        </ul>

                        <h4>權限</h4>
                        <ul>
                            <li>本頁權限沿用系統的頁面權限設定（頁面標題旁會顯示你目前的權限代碼）。</li>
                            <li><b>R</b>＝唯讀，看得到但不能新增修改，也不能強制登出。</li>
                            <li><b>A</b>＝完整權限，含「一鍵登出所有人」。</li>
                            <li>設定入口：系統管理 → 使用者權限設定。</li>
                        </ul>
                        <div class="tip">強制登出與一鍵登出都會寫入<b>稽核日誌</b>（誰、何時、踢了誰、原因），可事後查核。</div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-default" data-dismiss="modal">關閉</button>
                    </div>
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
<!-- FastClick -->
<script src="../../resource/js/fastclick.js"></script>
<!-- NProgress -->
<script src="../../resource/js/nprogress.js"></script>
<!-- Custom Theme Scripts -->
<script src="../../resource/js/custom.min.js"></script>

<script>
    // 將後端權限資料注入到全域變數
    window.hrUserPerm = "<?php echo $hrUserPerm ? $hrUserPerm : ''; ?>";
    window.currentUserId = <?php echo (int)$id; ?>;
</script>

<script>
    // 回到頂端功能
    function scrollToTop() { window.scrollTo({ top: 0, behavior: 'smooth' }); }
</script>

<script>
$(document).ready(function() {
    const API_URL = '../../src/store/_employee_api.php';

    // --- 通用功能 ---
    function escapeHtml(text) {
        if (text === null || typeof text === 'undefined') return '';
        return String(text).replace(/[&<>"']/g, function(m) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m];
        });
    }

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

    function filterTable() {
        const searchText = $('#table-search').val().toLowerCase();
        // 同一個部門篩選框同時比對主職務與兼任職務：'' = 全部、'none' = 未設定部門、其餘為 department_id
        const dept = $('#filter-dept').val();

        let total = 0, shown = 0;

        $('#employee-table-body tr').each(function() {
            const row = $(this);
            const rowText = row.text().toLowerCase();
            total++;

            const matchesSearch = searchText === '' || rowText.indexOf(searchText) > -1;

            // 主部門 + 兼任部門（一人可有多個兼任部門），任一符合即算符合
            const rowMainDept = String(row.data('main-dept') || '');
            const rowDepts = String(row.data('concurrent-depts') || '').split(',').filter(v => v !== '');
            if (rowMainDept !== '') rowDepts.push(rowMainDept);

            let matchesDept = true;
            if (dept === 'none') {
                matchesDept = rowDepts.length === 0;
            } else if (dept !== '') {
                matchesDept = rowDepts.indexOf(dept) > -1;
            }

            if (matchesSearch && matchesDept) {
                row.show();
                shown++;
            } else {
                row.hide();
            }
        });

        const hasFilter = searchText !== '' || dept !== '';
        $('#filter-result-count').text(hasFilter ? `符合 ${shown} 筆 / 共 ${total} 筆（部門篩選含主職務與兼任職務）` : `共 ${total} 筆`);
    }

    // 載入部門清單到篩選下拉（維持與部門主檔相同的 sort_order 排序）
    function loadDepartmentFilters() {
        callApi('get_departments', 'GET', null, function(response) {
            if (response.status !== 'success') return;
            const select = $('#filter-dept');
            const keep = select.val();

            select.empty();
            select.append('<option value="">全部</option>');
            select.append('<option value="none">（未設定部門）</option>');
            response.data.forEach(function(dept) {
                select.append(`<option value="${dept.id}">${escapeHtml(dept.name)}</option>`);
            });

            if (keep) select.val(keep);
        });
    }

    // --- 員工列表相關 ---
    function loadEmployees() {
        callApi('get_employees', 'GET', null, function(response) {
            if (response.status === 'success') {
                var tableBody = $('#employee-table-body');
                tableBody.empty();
                response.data.forEach(function(emp) {
                    let statusLabel = '';
                    switch(parseInt(emp.state)) { // 改用 state 欄位
                        case 1: statusLabel = '<span class="btn btn-success btn-sm">在職</span>'; break;
                        case 0: statusLabel = '<span class="btn btn-danger btn-sm">離職</span>'; break; // 改回紅色
                        case 2: statusLabel = '<span class="btn btn-warning btn-sm">留職停薪</span>'; break;
                        case 3: statusLabel = '<span class="btn btn-info btn-sm">育嬰留停</span>'; break;
                        case 90: statusLabel = '<span class="btn btn-primary btn-sm">特殊帳號</span>'; break;
                        case 99: statusLabel = '<span class="btn btn-warning btn-sm">最高權限</span>'; break; // 改為黃色
                        default: statusLabel = '<span class="btn btn-default btn-sm">未知</span>';
                    }
                    
                    // 預定離職（仍可使用系統，離職日當天過後才封鎖）：整列暖粉底＋標示日期與剩餘天數。
                    // 顏色不是唯一資訊（規範要求）：狀態欄與備註欄都會寫出日期，黑白列印也看得懂。
                    let rowClass = '', remarkText = emp.remark || '';
                    if (emp.pending_leave_date) {
                        const d = String(emp.pending_leave_date);
                        const days = parseInt(emp.pending_leave_days);
                        const dayTxt = isNaN(days) ? '' : (days === 0 ? '（今天最後一天）' : '（尚餘 ' + days + ' 天）');
                        // 日期只寫在備註欄一處（狀態欄不再重複掛標籤）
                        rowClass = ' class="pending-leave"';
                        remarkText = '預定離職日：' + d + dayTxt + (remarkText ? '　' + remarkText : '');
                    }

                    let genderLabel = '';
                    switch(emp.gender) {
                        case 'M': genderLabel = '男'; break;
                        case 'F': genderLabel = '女'; break;
                    }
                    const EDU_LABEL = {jhs:'國中以下', shs:'高中/高職', college:'專科', univ:'大學', master:'碩士', phd:'博士'};
                    const eduLabel = EDU_LABEL[emp.highest_education] || '';

                    // 修正特休計算：到職不滿6個月為0天
                    let displayAnnualLeave = emp.annual_leave_days;
                    if (emp.hire_date) {
                        const dateParts = emp.hire_date.split('-');
                        if (dateParts.length === 3) {
                            const hireDateObj = new Date(dateParts[0], dateParts[1] - 1, dateParts[2]);
                            const sixMonthsLater = new Date(hireDateObj);
                            sixMonthsLater.setMonth(sixMonthsLater.getMonth() + 6);
                            
                            const today = new Date();
                            today.setHours(0, 0, 0, 0);
                            
                            if (today < sixMonthsLater) {
                                displayAnnualLeave = 0;
                            }
                        }
                    }

                    var row = `<tr${rowClass} data-id="${escapeHtml(emp.id)}" data-action="edit" data-main-dept="${escapeHtml(emp.main_department_id || '')}" data-concurrent-depts="${escapeHtml(emp.concurrent_department_ids || '')}" style="cursor: pointer;" title="連點兩下編輯">
                        <td>${escapeHtml(emp.id)}</td> <!-- 員工編號 -->
                        <td>${escapeHtml(emp.user_uname)}</td> <!-- 登入帳號 -->
                        <td>${escapeHtml(emp.user_cname)}</td>
                        <td>${escapeHtml(emp.main_department_name || '-')} / ${escapeHtml(emp.main_position_name || '-')}</td>
                        <td>
                            ${genderLabel}
                        </td>
                        <td>${eduLabel}</td>
                        <td>${escapeHtml(emp.hire_date || '')}</td>
                        <td>${escapeHtml(displayAnnualLeave)} 天</td>
                        <td>
                            ${emp.concurrent_positions ? emp.concurrent_positions.split('; ').map(escapeHtml).join('<br>') : '<i class="text-muted">無</i>'}
                        </td>
                        <td>${statusLabel}</td>
                        <td>${escapeHtml(remarkText)}</td>
                        <td class="conn-cell" data-uid="${escapeHtml(emp.id)}"><span class="conn-off">—</span></td>
                    </tr>`;
                    tableBody.append(row);
                });
                // 資料載入後，重新應用篩選
                filterTable();
                refreshOnlineStatus();   // 連線狀態是另一支 API，列表畫完才去補
            } else {
                alert('讀取員工資料失敗: ' + response.message);
            }
        });
    }

    /* ==============================================================
     * 連線狀態與強制登出（2026-08-24 新增）
     *
     * 「在線」判定＝伺服器端還留著這個人的 session 檔。
     * 顯示的時間取自 session 檔的 mtime：_config.php 每次請求都會 touch 它，
     * 所以約等於最後活動時間；但有部分入口沒載入 _config.php，那些請求只有在
     * session 內容有變時才更新，故此時間是「不早於」實際活動時間的近似值，
     * 僅供判斷該不該踢，不可拿來當出勤紀錄。
     * ============================================================== */
    const ME_ID    = <?php echo (int)$currentUser['id']; ?>;
    const CAN_KICK = <?php echo ($hrUserPerm !== 'R') ? 'true' : 'false'; ?>;   // 唯讀者不給踢人

    function refreshOnlineStatus() {
        callApi('get_online_status', 'GET', {}, function(res) {
            if (!res || res.status !== 'success') { $('#online-summary').text(''); return; }
            const online = res.online || {};
            let count = 0;

            $('#employee-table-body .conn-cell').each(function() {
                const cell = $(this);
                const uid  = String(cell.data('uid'));
                const ts   = online[uid];

                if (!ts) { cell.html('<span class="conn-off">離線</span>'); return; }
                count++;

                let html = '<span class="conn-on">在線</span>'
                         + '<span class="conn-time">' + escapeHtml(dispDateTime(ts)) + '</span>';
                if (CAN_KICK) {
                    html += (String(uid) === String(ME_ID))
                        ? '<button type="button" class="btn-kick" disabled title="不能對自己執行；要登出自己請用右上角的登出">本人</button>'
                        : '<button type="button" class="btn-kick" data-kick="' + escapeHtml(uid) + '">強制登出</button>';
                }
                cell.html(html);
            });

            $('#online-summary').text('目前線上 ' + count + ' 人（' + escapeHtml(dispDateTime(res.now)) + ' 更新）');
        });
    }

    /** 顯示用日期時間：日期部分依 ai-rules/20 一律 YYYY.MM.DD，時間保留 HH:MM。
     *  withSec=true 連秒一起顯示——異動紀錄的「登記時間」是用來分辨同一天多筆的先後，
     *  而人事連續補登兩筆常常只差幾十秒（實測 18:54:20 與 18:54:42），只印到分會變成兩列一模一樣。 */
    function dispDateTime(s, withSec) {
        if (!s) return '';
        const m = String(s).match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?/);
        if (!m) return String(s);
        return `${m[1]}.${m[2]}.${m[3]} ${m[4]}:${m[5]}` + (withSec && m[6] ? `:${m[6]}` : '');
    }

    /** 顯示用日期（只有日期沒有時間時用）：ai-rules/20 一律 YYYY.MM.DD */
    function dispDate(s) {
        if (!s) return '';
        const m = String(s).match(/^(\d{4})-(\d{2})-(\d{2})/);
        return m ? `${m[1]}.${m[2]}.${m[3]}` : String(s);
    }

    // 逐列強制登出（按鈕在 tr 內，必須擋掉冒泡，否則會觸發整列的「連點兩下編輯」）
    $('#employee-table-body').on('click', '.btn-kick', function(e) {
        e.stopPropagation();
        const btn  = $(this);
        const uid  = btn.data('kick');
        const row  = btn.closest('tr');
        const name = row.find('td').eq(2).text().trim();

        const reason = prompt(`確定要強制登出「${name}」？\n\n他目前的登入連線會立刻失效，下次點任何頁面都會被導回登入頁。\n（在職人員可以馬上重新登入；離職者本來就登不進來）\n\n可填寫原因（選填，會記入稽核日誌）：`, '');
        if (reason === null) return;   // 按取消

        btn.prop('disabled', true).text('處理中…');
        callApi('force_logout', 'POST', { id: uid, reason: reason }, function(res) {
            alert(res && res.message ? res.message : '操作完成');
            refreshOnlineStatus();
        });
    });

    // 一鍵登出所有人
    $('#btn-kick-all').on('click', function() {
        const reason = prompt('確定要登出「所有人」？\n\n除了你自己以外，所有登入中的使用者都會被切斷連線。\n通常用於系統維護或還原資料庫前清場。\n\n可填寫原因（選填，會記入稽核日誌）：', '');
        if (reason === null) return;
        if (!confirm('再確認一次：這會中斷全公司目前所有人的作業，未存檔的內容可能遺失。確定執行？')) return;

        const btn = $(this).prop('disabled', true).text('處理中…');
        callApi('force_logout_all', 'POST', { reason: reason }, function(res) {
            alert(res && res.message ? res.message : '操作完成');
            btn.prop('disabled', false).text('一鍵登出所有人');
            refreshOnlineStatus();
        });
    });

    $('#btn-refresh-online').on('click', refreshOnlineStatus);

    // 使用說明
    $('#btnPageHelp').on('click', function() { $('#helpUseMask').modal('show'); });

    // --- Modal 相關 ---

    // 載入所有部門到指定的 select 元素中
    function loadDepartmentsToSelects(selector) {
        callApi('get_departments', 'GET', null, function(response) {
            if (response.status === 'success') {
                $(selector).each(function() {
                    const select = $(this);
                    // 待套用值優先（員工資料可能比部門清單先回來，此時 val() 會落空）
                    const originalValue = select.data('pending-val') || select.val();
                    const isOptional = !select.prop('required');
                    
                    select.empty();
                    if (isOptional) {
                        select.append('<option value="">-- 可選 --</option>');
                    } else {
                        select.append('<option value="">請先選擇部門...</option>');
                    }

                    response.data.forEach(function(dept) {
                        select.append(`<option value="${dept.id}">${escapeHtml(dept.name)}</option>`);
                    });
                    
                    // 嘗試還原之前的值
                    if (originalValue) {
                        select.val(originalValue);
                    }
                    select.removeData('pending-val');
                });
            }
        });
    }

    // 根據選擇的部門，載入對應的職稱
    // onDone：職稱真的填好（含還原選取值）之後才呼叫——這是非同步的，呼叫端若在這之前就去讀 val()
    // 會讀到空字串（兼任職務的「新增」鈕就是因此一直停在停用狀態）
    function loadPositionsForDepartment(departmentId, positionSelectElement, selectedPositionId = null, onDone = null) {
        const select = $(positionSelectElement);
        const isOptional = !select.prop('required');
        select.empty();

        if (!departmentId) {
            if (isOptional) {
                select.append('<option value="">-- 可選 --</option>');
            } else {
                select.append('<option value="">請先選擇部門...</option>');
            }
            if (onDone) onDone();
            return;
        }

        callApi('get_department_positions_for_assignment', 'GET', { department_id: departmentId }, function(response) {
            if (response.status === 'success') {
                if (isOptional) {
                    select.append('<option value="">-- 可選 --</option>');
                } else {
                    select.append('<option value="">請選擇職稱...</option>');
                }
                response.data.forEach(function(pos) {
                    select.append(`<option value="${pos.id}">${escapeHtml(pos.name)}</option>`);
                });
                if (selectedPositionId) {
                    select.val(selectedPositionId);
                }
            } else {
                alert('讀取職稱失敗: ' + response.message);
            }
            if (onDone) onDone();
        });
    }

    // --- 兼任職務欄位：有幾個就顯示幾個，要加才按「新增兼任職務」---
    // 舊版是「填完第 N 個就自動長出第 N+1 個空白欄位」，所以每次點開編輯一定會多一格沒設定的空欄位
    // （沒兼任的人也會看到空的兼任 1），使用者反映會誤以為系統多設了一個職務。
    const CONCURRENT_MAX = 3;
    let concurrentShown = 0;     // 目前顯示幾個兼任欄位

    function concurrentSlotVal(i) {
        return { dept: $(`#concurrent_department_id_${i}`).val() || '', pos: $(`#concurrent_position_id_${i}`).val() || '' };
    }

    function clearConcurrentSlot(i) {
        $(`#concurrent_department_id_${i}`).removeData('pending-val').val('');
        $(`#concurrent_position_id_${i}`).empty().append('<option value="">-- 可選 --</option>');
    }

    /** 依「目前顯示幾個」重畫：多餘的欄位一律清空再隱藏。
     *  隱藏但留著值＝畫面上看不到、存檔卻照樣送出去（舊版清除兼任 1 時，填好的兼任 2 就會變成這種看不見的資料）。 */
    function refreshConcurrentUI(animate) {
        for (let i = 1; i <= CONCURRENT_MAX; i++) {
            const $g = $(`#concurrent_group_${i}`);
            if (i <= concurrentShown) {
                animate ? $g.slideDown(150) : $g.show();
            } else {
                clearConcurrentSlot(i);
                animate ? $g.slideUp(150) : $g.hide();
            }
        }
        // 上一個還沒填完就不給再加（否則會出現中間空一格的兼任職務）
        const last = concurrentShown > 0 ? concurrentSlotVal(concurrentShown) : null;
        const lastDone = !last || (last.dept && last.pos);
        $('#btn-add-concurrent').toggle(concurrentShown < CONCURRENT_MAX).prop('disabled', !lastDone);
        $('#concurrentHint').text(
            concurrentShown >= CONCURRENT_MAX ? `兼任職務最多 ${CONCURRENT_MAX} 個` :
            !lastDone ? '請先填完上一個兼任職務的部門與職稱' :
            concurrentShown === 0 ? '目前沒有兼任職務（需要時才按「新增兼任職務」）' : ''
        );
    }

    /** 一次把兼任職務設定成指定的清單（載入員工資料、清除某一列後重排都走這支） */
    function setConcurrentSlots(pairs) {
        const list = (pairs || []).filter(p => p && p.department_id && p.position_id).slice(0, CONCURRENT_MAX);
        for (let i = 1; i <= CONCURRENT_MAX; i++) clearConcurrentSlot(i);
        list.forEach(function(p, idx) {
            const i = idx + 1;
            $(`#concurrent_department_id_${i}`).data('pending-val', String(p.department_id)).val(p.department_id);
            // 職稱是非同步載入的，載完才重算一次按鈕狀態（否則「新增兼任職務」會一直停在停用）
            loadPositionsForDepartment(p.department_id, `#concurrent_position_id_${i}`, p.position_id,
                                       () => refreshConcurrentUI(false));
        });
        concurrentShown = list.length;
        refreshConcurrentUI(false);
    }

    $(document).on('click', '#btn-add-concurrent', function() {
        if (concurrentShown >= CONCURRENT_MAX) return;
        concurrentShown++;
        refreshConcurrentUI(true);
    });

    // 為所有部門下拉選單綁定 change 事件
    $(document).on('change', '.department-select', function() {
        const departmentId = $(this).val();
        const positionTarget = $(this).data('position-target');
        if (positionTarget) {
            loadPositionsForDepartment(departmentId, positionTarget);
        }
        refreshConcurrentUI(false);
    });

    $(document).on('change', '.position-select', function() {
        refreshConcurrentUI(false);
    });

    // --- 清除兼任職務按鈕事件：整列移除並把後面的往前遞補（不留中間的空格） ---
    $(document).on('click', '.btn-clear-concurrent', function() {
        const index = parseInt($(this).data('concurrent-index'));
        const rest = [];
        for (let i = 1; i <= CONCURRENT_MAX; i++) {
            if (i === index) continue;
            const v = concurrentSlotVal(i);
            if (v.dept && v.pos) rest.push({ department_id: v.dept, position_id: v.pos });
        }
        setConcurrentSlots(rest);
    });

    // 開啟 Modal 時的處理
    $('#employeeModal').on('show.bs.modal', function(event) {
        var button = $(event.relatedTarget || event.trigger); // 優先使用 relatedTarget，若無則使用我們自訂的 trigger
        var action = button.data('action');
        var modal = $(this);
        var form = modal.find('form');
        form[0].reset();
        modal.find('#user_id').val('');

        // 重置兼任職務：一律先收成 0 個，載入資料時有幾筆才長出幾個
        concurrentShown = 0;
        $('#concurrent_group_1, #concurrent_group_2, #concurrent_group_3').hide();
        $('#btn-delete-in-modal').hide(); // 預設隱藏刪除按鈕
        
        // 重置所有職稱下拉選單
        $('.position-select').each(function() {
            const isOptional = !$(this).prop('required');
            $(this).empty();
            if (isOptional) {
                $(this).append('<option value="">-- 可選 --</option>');
            } else {
                $(this).append('<option value="">請先選擇部門...</option>');
            }
        });

        // 載入所有部門選項（先清掉上一次殘留的待套用值，避免沿用前一位員工的部門）
        $('.department-select').removeData('pending-val').val('');
        loadDepartmentsToSelects('.department-select');

        if (action === 'add') {
            modal.find('.modal-title').text('新增員工');
            modal.find('#user_id_input').prop('readonly', false);
            modal.find('#user_uname').prop('readonly', false);
            modal.find('#password').prop('required', true);
            $('#btn-delete-in-modal').hide();
            $('#btn-revoke-perm').hide();
            $('#btn-history').hide();
        } else {
            modal.find('.modal-title').text('編輯員工資料');
            modal.find('#user_id_input').prop('readonly', true);
            modal.find('#user_uname').prop('readonly', false);
            modal.find('#password').prop('required', false);
            var userId = button.data('id');
            
            // 根據權限顯示刪除按鈕
            if (window.hrUserPerm.includes('A') || window.hrUserPerm.includes('D')) {
                $('#btn-delete-in-modal').show().data('id', userId);
            }

            // 異動紀錄：編輯模式一律可看（補登/刪除另依 U/A 權限在 modal 內控管）
            $('#btn-history').show().data('id', userId);
            
            // 載入員工詳細資料
            callApi('get_employee_details', 'GET', { id: userId }, function(response) {
                if (response.status === 'success') {
                    const emp = response.data;
                    $('#user_uname').val(emp.user_uname);
                    $('#user_cname').val(emp.user_cname);
                    $('#phone').val(emp.phone);
                    $('#user_id_input').val(userId); // 將 ID 填入員工編號欄位
                    $('#gender').val(emp.gender);
                    $('#highest_education').val(emp.highest_education || '');
                    $('#second_education').val(emp.second_education || '');
                    $('#hire_date').val(emp.hire_date);
                    $('#leave_date').val(emp.leave_date);

                    // **修正**: 改用 state 欄位來設定下拉選單的值，確保與列表顯示一致
                    // 如果 API 回傳了 state，就用 state，否則沿用舊的 user_status (向下相容)
                    $('#user_status').val(emp.state !== undefined ? emp.state : emp.user_status);

                    // 設定主職務
                    if (emp.main_department_id) {
                        $('#main_department_id').data('pending-val', String(emp.main_department_id)).val(emp.main_department_id);
                        loadPositionsForDepartment(emp.main_department_id, '#main_position_id', emp.main_position_id);
                    }

                    // 設定兼任職務：實際有幾筆就顯示幾格，沒有就一格都不顯示（要加再按「新增兼任職務」）
                    setConcurrentSlots(emp.concurrent_positions);

                    // 離職/留停者才顯示「清除權限設定」，並先問後端還剩幾筆（0 筆就不用出現）
                    var st = parseInt(emp.state !== undefined ? emp.state : emp.user_status);
                    $('#btn-revoke-perm').hide();
                    $('#btn-restore-perm').hide();
                    var canEditPerm = (window.hrUserPerm.includes('A') || window.hrUserPerm.includes('U'));
                    if ([0, 2, 3].indexOf(st) > -1 && canEditPerm) {
                        callApi('get_permission_summary', 'GET', { id: userId }, function(res) {
                            if (res.status === 'success' && res.total > 0) {
                                $('#btn-revoke-perm').show()
                                    .data('id', userId)
                                    .data('summary', res)
                                    .text('清除權限設定 (' + res.total + ')');
                            }
                        });
                    }
                    // 在職者才可能是「復職」：目前若已無任何權限設定，且先前有清除紀錄，才提供一鍵還原
                    if (st === 1 && canEditPerm) {
                        callApi('get_permission_summary', 'GET', { id: userId }, function(sumRes) {
                            if (sumRes.status === 'success' && sumRes.total === 0) {
                                callApi('get_restorable_permissions', 'GET', { id: userId }, function(res) {
                                    if (res.status === 'success' && res.found && res.total > 0) {
                                        $('#btn-restore-perm').show()
                                            .data('id', userId)
                                            .data('summary', res)
                                            .text('還原離職前權限 (' + res.total + ')');
                                    }
                                });
                            }
                        });
                    }

                    // 根據狀態顯示/隱藏日期欄位，並填入資料
                    handleStatusChange();
                    if (emp.status_history) {
                        $('#status_start_date').val(emp.status_history.start_date);
                        $('#status_end_date').val(emp.status_history.end_date);
                    }
                } else {
                    alert('讀取員工資料失敗: ' + response.message);
                    modal.modal('hide');
                }
            });
        }
    });

    // 清除權限設定（離職/留停者專用；權限本身已由在職狀態自動擋下，這裡只是把殘留資料刪乾淨）
    $('#btn-revoke-perm').on('click', function() {
        var userId  = $(this).data('id');
        var summary = $(this).data('summary') || {};
        var lines = ['此帳號目前還留有下列權限設定：', ''];
        (summary.items || []).forEach(function(it) {
            lines.push('● ' + it.label + '：' + it.count + ' 筆　' + (it.detail || ''));
        });
        if (summary.warnings && summary.warnings.length) {
            lines.push('', '● 需人事另行處理（系統不會自動改）：');
            summary.warnings.forEach(function(w) { lines.push('　- ' + w); });
        }
        // 講明「只清權限、不動歷史」——否則人事會擔心按下去連請假、簽核紀錄一起不見而不敢按
        lines.push('', '● 只會清除上列「權限設定」。行事曆、通知／公告、請假、簽核紀錄、',
                       '　教育訓練、職務／在職異動紀錄與稽核紀錄一律原樣保留，不受影響。');
        lines.push('', '清除後復職需重新設定權限。清除前會完整寫入稽核紀錄備查。確定要清除嗎？');
        if (!confirm(lines.join('\n'))) return;
        callApi('revoke_permissions', 'POST', { id: userId, reason: '人事手動清除' }, function(res) {
            alert(res.status === 'success' ? res.message : ('清除失敗：' + res.message));
            if (res.status === 'success') $('#btn-revoke-perm').hide();
        });
    });

    // 一鍵還原離職前的權限設定（復職專用；來源是當初「清除權限設定」時寫入的稽核紀錄）
    $('#btn-restore-perm').on('click', function() {
        var userId  = $(this).data('id');
        var summary = $(this).data('summary') || {};
        var lines = ['將還原此帳號離職前（' + (summary.revoked_at || '') + ' 清除）的權限設定：', ''];
        (summary.items || []).forEach(function(it) {
            lines.push('● ' + it.label + '：' + it.count + ' 筆　' + (it.detail || ''));
        });
        if (summary.delegate_count > 0) {
            lines.push('', '● 另有 ' + summary.delegate_count + ' 筆代理設定當初被停用，不會自動恢復，需要的話請至代理設定頁確認後手動啟用。');
        }
        lines.push('', '只會補回目前沒有的設定，不會動到還原前已存在的其他設定。確定要還原嗎？');
        if (!confirm(lines.join('\n'))) return;
        callApi('restore_permissions', 'POST', { id: userId }, function(res) {
            alert(res.status === 'success' ? res.message : ('還原失敗：' + res.message));
            if (res.status === 'success') $('#btn-restore-perm').hide();
        });
    });

    // 監聽在職狀態的變化，以顯示/隱藏相關日期欄位
    $(document).on('change', '#user_status', handleStatusChange);

    function handleStatusChange() {
        const status = $('#user_status').val();
        // 離職日欄位一律顯示：離職＝實際離職日；其他狀態＝可預填的「預定離職日」（留空表示沒有）
        $('#leave_date_group').show();
        $('#leave_date_label').text(status === '0' ? '離職日' : '預定離職日（可留空）');
        $('#status_dates_group').toggle(status === '2' || status === '3');
        validateLeaveDate();
    }

    // 即時驗證離職日（表單三總則：輸入當下就驗，紅框＋寫明為什麼錯）
    function validateLeaveDate() {
        const status = $('#user_status').val();
        const val = $('#leave_date').val();
        const grp = $('#leave_date_group');
        const hint = $('#leave_date_hint');
        grp.removeClass('has-error');
        hint.css('color', '#8a6d3b').text('');

        if (!val) {
            if (status !== '0') hint.text('可先填未來的預定離職日；當天仍可使用系統，隔天起自動停用並轉為離職。');
            return true;
        }
        const today = new Date(); today.setHours(0, 0, 0, 0);
        const picked = new Date(val + 'T00:00:00');
        if (status !== '0' && picked <= today) {
            grp.addClass('has-error');
            hint.css('color', '#DD5138')
                .text('預定離職日必須是未來日期（' + val + ' 已過或就是今天）。'
                    + '若是復職，請把此欄清空；若確實已離職，請把在職狀態改成「離職」。');
            return false;
        }
        if (status !== '0') hint.text('到 ' + val + ' 當天仍可使用系統，隔天起自動停用並轉為離職。');
        return true;
    }

    $(document).on('change input', '#leave_date', validateLeaveDate);


    // --- 表單提交 ---
    // P4：組出「部門/職位異動影響代理設定」的確認訊息
    function buildDelegateImpactMsg(aff) {
        aff = aff || {};
        const scoped = aff.as_target_scoped || [];
        const owner = aff.as_primary_owner || [];
        const info = aff.as_delegate_info || [];
        let lines = ['此員工的部門/職位異動會影響既有代理設定：', ''];
        if (scoped.length || owner.length) {
            lines.push('● 下列設定將因移除該職務身分而失效，存檔時一併停用：');
            scoped.forEach(r => lines.push('　- 代理：由「' + r.delegate_name + '」代理（' + (r.dep_name || '') + '/' + (r.pos_name || '') + ' 身分）'));
            owner.forEach(r => lines.push('　- 指定負責人：' + (r.dep_name || '') + '/' + (r.pos_name || '')));
            lines.push('');
        }
        if (info.length) {
            lines.push('● 另：此人目前是 ' + info.length + ' 筆代理設定的「代理人」（' + info.map(r => r.target_name).join('、') + '），換單位後仍有效，建議至代理設定頁複查。');
            lines.push('');
        }
        lines.push('按「確定」＝停用上述失效項並存檔；「取消」＝先不存檔、我去調整。');
        return lines.join('\n');
    }

    // 在職狀態改成離職/留停後的權限處理（2026-07-30）
    // 系統已自動讓此人「判斷時無任何權限」，這裡問的只是「要不要把殘留的設定資料也刪掉」。
    function askRevokePermissions(n) {
        var lines = ['已將「' + n.label + '」狀態存檔。', '',
                     '● 此帳號即時生效的處置：無法登入、線上中會被登出、所有權限判斷一律視為無權限。', ''];
        if (n.count > 0) {
            lines.push('● 目前還留有 ' + n.count + ' 筆權限設定資料（角色、模組權限、代理等）。');
            lines.push('　留著不影響安全（判斷時已擋），清掉則資料乾淨、復職需重設。');
        } else {
            lines.push('● 此帳號沒有殘留的權限設定資料。');
        }
        if (n.warnings && n.warnings.length) {
            lines.push('');
            lines.push('● 需人事另行處理（系統不會自動改）：');
            n.warnings.forEach(function(w) { lines.push('　- ' + w); });
        }
        // 離職只收回「權利」，不動任何要留存的歷史資料（使用者定調）
        lines.push('', '● 行事曆、通知／公告、請假、簽核紀錄、教育訓練、職務／在職異動與稽核紀錄',
                       '　一律完整保留，離職不會刪除也不會清空。');
        if (n.count > 0) {
            lines.push('', '要現在清除這些權限設定嗎？（只清權限，不影響上述紀錄；清除前會完整寫入稽核紀錄備查）');
            if (!confirm(lines.join('\n'))) return;
            callApi('revoke_permissions', 'POST', { id: n.user_id, reason: n.label }, function(res) {
                alert(res.status === 'success' ? res.message : ('清除失敗：' + res.message));
            });
        } else {
            alert(lines.join('\n'));
        }
    }

    function submitEmployee(action, data, confirmed) {
        var payload = data + (confirmed ? '&confirm_delegate=1' : '');
        callApi(action, 'POST', payload, function(response) {
            if (response.status === 'success') {
                $('#employeeModal').modal('hide');
                loadEmployees();
                // 改成離職/留停：權限已自動失效，順便問要不要把殘留設定也清掉
                if (response.permission_notice) askRevokePermissions(response.permission_notice);
            } else if (response.status === 'need_confirm') {
                if (confirm(buildDelegateImpactMsg(response.affected))) {
                    submitEmployee(action, data, true); // 確認後帶旗標重送
                }
            } else {
                alert('操作失敗: ' + response.message);
            }
        });
    }

    $('#employeeForm').on('submit', function(e) {
        e.preventDefault();
        // 從表單的 data 屬性中獲取當前操作 (在 show.bs.modal 事件中設定)
        var action = $(this).data('action');

        if (!validateLeaveDate()) {
            $('#leave_date').focus();
            return;   // 錯誤原因已顯示在欄位旁，不再另跳「資料有誤」的空泛訊息
        }

        // 將 password input 的值複製到隱藏的 user_password 欄位
        var password = $('#password').val();
        $('#user_password').val(password);

        submitEmployee(action, $(this).serialize(), false);
    });

    // --- 列表雙擊編輯事件 ---
    $(document).on('dblclick', '#employee-table-body tr', function() {
        // 檢查編輯權限
        if (window.hrUserPerm.includes('A') || window.hrUserPerm.includes('U')) {
            // $(this) 是 tr 元素，已在 loadEmployees 中設定 data-id 和 data-action="edit"
            $('#employeeModal').modal('show', $(this));
        } else {
            alert('您沒有權限編輯員工資料');
        }
    });

    // 當 Modal 顯示時，設定表單的 action
    $('#employeeModal').on('show.bs.modal', function(event) {
        var button = $(event.relatedTarget);
        var action = button.data('action') === 'add' ? 'add_employee' : 'update_employee';
        $('#employeeForm').data('action', action);
    });

    // --- Modal 內刪除按鈕事件 ---
    // 按下當下才向後端問「此人有沒有一定要保留的歷史紀錄」（點開即刷新）。
    // 有的話一律擋下並引導改用「離職」——行事曆、通知、請假、簽核等紀錄必須留存，
    // 帳號被實體刪掉之後那些紀錄會變成查不出人的孤兒資料，而且完全不會報錯。
    $('#btn-delete-in-modal').on('click', function() {
        var userId = $(this).data('id');
        var userName = $('#user_cname').val(); // 從 Modal 輸入框獲取姓名

        callApi('check_delete_employee', 'GET', { id: userId }, function(chk) {
            if (!chk || chk.status !== 'success') {
                alert('檢查失敗：' + ((chk && chk.message) || '無法取得刪除前檢查結果'));
                return;
            }

            if (!chk.can_delete) {
                var msg = '無法刪除員工「' + userName + '」(ID: ' + userId + ')\n\n' + chk.reason;
                if (chk.lines && chk.lines.length) {
                    msg += '\n\n目前查到的紀錄：\n・' + chk.lines.join('\n・');
                }
                alert(msg);
                return;
            }

            if (confirm('您確定要刪除員工「' + userName + '」(ID: ' + userId + ') 嗎？\n\n'
                      + '系統已確認此帳號沒有任何歷史紀錄（行事曆、通知、請假、簽核、異動紀錄皆無），'
                      + '屬於可直接移除的誤建帳號。\n刪除後無法復原。')) {
                callApi('delete_employee', 'POST', { id: userId }, function(response) {
                    if (response.status === 'success') {
                        $('#employeeModal').modal('hide'); // 關閉 Modal
                        loadEmployees(); // 重新載入列表
                    } else {
                        var m = '刪除失敗: ' + response.message;
                        if (response.lines && response.lines.length) m += '\n\n・' + response.lines.join('\n・');
                        alert(m);
                    }
                });
            }
        });
    });

    // --- 異動紀錄（職務調動＋在職狀態；ai-rules/14 P1）---
    const CHANGE_TYPE_LABEL = { transfer: '調動', concurrent_add: '兼任新增', concurrent_remove: '兼任移除', concurrent_change: '兼任異動',
                                backfill: '補登', resign: '離職', reinstate: '復職' };
    const STATUS_HIST_LABEL = { 0: '離職', 1: '在職（復職）', 2: '留職停薪', 3: '育嬰留停' };
    const isSuperAdmin = window.currentUserId === 1; // 超級管理員固定 id=1，補登異動紀錄一律放行，方便補足/修正資料
    const canEditHist = window.hrUserPerm.includes('A') || window.hrUserPerm.includes('U') || isSuperAdmin;

    $('#btn-history').on('click', function() {
        const userId = $(this).data('id');
        $('#historyUserName').text(($('#user_cname').val() || '') + '（' + userId + '）');
        $('#historyModal').data('id', userId);
        $('#employeeModal').modal('hide');
        $('#bfPosErr, #bfStaErr').text('');
        $('#posBackfillBox, #staBackfillBox').toggle(canEditHist);
        resetPosBackfillForm();
        loadHistDepts();
        loadChangeHistory(userId);
        $('#historyModal').modal('show');
    });

    // 紀錄資料留在前端，分頁只是切片渲染（單人紀錄量小，API 一次已回全部）
    let POS_HIST = [], STA_HIST = [];
    const posPg = { page: 1, per: 10 }, staPg = { page: 1, per: 10 };

    function loadChangeHistory(userId) {
        callApi('get_change_history', 'GET', { id: userId }, function(res) {
            if (res.status !== 'success') { alert('讀取異動紀錄失敗: ' + res.message); return; }
            POS_HIST = res.position || []; STA_HIST = res.state || [];
            posPg.page = 1; staPg.page = 1;
            renderPosHist(); renderStaHist();
        });
    }

    function pageSlice(arr, pg) {
        const total = arr.length;
        const pages = Math.max(1, Math.ceil(total / pg.per));
        if (pg.page > pages) pg.page = pages;
        if (pg.page < 1) pg.page = 1;
        return { rows: arr.slice((pg.page - 1) * pg.per, pg.page * pg.per), pages: pages, total: total };
    }

    // 分頁列（列表右上）：總筆數＋每頁 5/10/20/50＋上一頁/下一頁
    function histPagerHtml(kind, pg, s) {
        let h = '<small class="text-muted">共 ' + s.total + ' 筆</small>　<small>每頁</small> '
              + '<select class="hist-per" data-kind="' + kind + '" style="height:24px; padding:0 2px;">';
        [5, 10, 20, 50].forEach(function(n) { h += '<option value="' + n + '"' + (pg.per === n ? ' selected' : '') + '>' + n + '</option>'; });
        h += '</select>';
        if (s.pages > 1) {
            h += '　<button type="button" class="btn btn-xs btn-default hist-page-btn" data-kind="' + kind + '" data-d="-1"' + (pg.page <= 1 ? ' disabled' : '') + '>‹ 上一頁</button>'
               + ' <span style="font-size:12px;">第 ' + pg.page + ' / ' + s.pages + ' 頁</span> '
               + '<button type="button" class="btn btn-xs btn-default hist-page-btn" data-kind="' + kind + '" data-d="1"' + (pg.page >= s.pages ? ' disabled' : '') + '>下一頁 ›</button>';
        }
        return h;
    }

    function renderPosHist() {
        const s = pageSlice(POS_HIST, posPg);
        let h = '';
        s.rows.forEach(function(r) {
            // 生效日只到「日」（欄位型別是 date，沒有時分），同一天有多筆時看不出先後，
            // 故一併印出「登記時間」＝這筆紀錄實際寫進系統的時間（created_at）。
            // 排序與 eg_position_snapshot_at() 的解析順序一致（effective_date, id），
            // 所以同一天的多筆中「最上面那一筆」就是當天最終生效的狀態。
            h += '<tr><td>' + escapeHtml(dispDate(r.effective_date))
              + (r.created_at ? '<br><small class="text-muted" title="這筆紀錄登記進系統的時間（同一天多筆時用來分辨先後）">登記 ' + escapeHtml(dispDateTime(r.created_at, true)) + '</small>' : '')
              + '</td>'
              + '<td>' + escapeHtml(CHANGE_TYPE_LABEL[r.change_type] || r.change_type) + '</td>'
              + '<td>' + escapeHtml(r.before_label) + '</td><td>' + escapeHtml(r.after_label) + '</td>'
              + '<td>' + escapeHtml(r.reason || '') + '</td>'
              + '<td>' + (r.source === 'manual' ? '補登' : '系統') + (r.operator ? '<br><small>' + escapeHtml(r.operator) + '</small>' : '') + '</td>'
              + '<td>' + ((r.source === 'manual' && canEditHist) || isSuperAdmin
                    ? '<button type="button" class="btn btn-xs btn-danger btn-del-poshist" data-hid="' + r.id + '">刪</button>' : '') + '</td></tr>';
        });
        $('#posHistBody').html(h || '<tr><td colspan="7" class="text-muted">尚無職務調動紀錄（' +
            '之後在上方表單改部門/職稱會自動寫入；更早以前的異動請由下方補登）</td></tr>');
        $('#posHistPager').html(s.total ? histPagerHtml('pos', posPg, s) : '');
    }

    function renderStaHist() {
        const s = pageSlice(STA_HIST, staPg);
        let h = '';
        s.rows.forEach(function(r) {
            h += '<tr><td>' + escapeHtml(STATUS_HIST_LABEL[parseInt(r.status)] || r.status) + '</td>'
              + '<td>' + escapeHtml(dispDate(r.start_date))
              + (r.created_at ? '<br><small class="text-muted" title="這筆紀錄登記進系統的時間（同一天多筆時用來分辨先後）">登記 ' + escapeHtml(dispDateTime(r.created_at, true)) + '</small>' : '')
              + '</td><td>' + escapeHtml(dispDate(r.end_date)) + '</td>'
              + '<td>' + escapeHtml(r.remark || '') + '</td>'
              + '<td>' + (r.is_backfill ? '補登' : '系統') + '</td>'
              + '<td>' + ((r.is_backfill && canEditHist) || isSuperAdmin
                    ? '<button type="button" class="btn btn-xs btn-danger btn-del-stahist" data-hid="' + r.id + '">刪</button>' : '') + '</td></tr>';
        });
        $('#staHistBody').html(h || '<tr><td colspan="6" class="text-muted">尚無在職狀態紀錄（' +
            '之後改狀態會自動寫入；更早以前的請由下方補登）</td></tr>');
        $('#staHistPager').html(s.total ? histPagerHtml('sta', staPg, s) : '');
    }

    $(document).on('change', '.hist-per', function() {
        const kind = $(this).data('kind'), per = parseInt($(this).val());
        if (kind === 'pos') { posPg.per = per; posPg.page = 1; renderPosHist(); }
        else { staPg.per = per; staPg.page = 1; renderStaHist(); }
    });
    $(document).on('click', '.hist-page-btn', function() {
        const kind = $(this).data('kind'), d = parseInt($(this).data('d'));
        if (kind === 'pos') { posPg.page += d; renderPosHist(); }
        else { staPg.page += d; renderStaHist(); }
    });

    // 補登區的部門下拉（與員工表單的下拉分開，避免互相干擾）
    function loadHistDepts() {
        callApi('get_departments', 'GET', null, function(res) {
            if (res.status !== 'success') return;
            $('.hist-dept').each(function() {
                const keep = $(this).val(); const $s = $(this);
                $s.empty().append('<option value="">部門…</option>');
                res.data.forEach(function(d) { $s.append('<option value="' + d.id + '">' + escapeHtml(d.name) + '</option>'); });
                if (keep) $s.val(keep);
            });
        });
    }
    $(document).on('change', '.hist-dept', function() {
        const did = $(this).val(); const $p = $($(this).data('pos-target'));
        $p.empty().append('<option value="">職稱…</option>');
        if (!did) return;
        callApi('get_department_positions_for_assignment', 'GET', { department_id: did }, function(res) {
            if (res.status !== 'success') return;
            res.data.forEach(function(p) { $p.append('<option value="' + p.id + '">' + escapeHtml(p.name) + '</option>'); });
        });
    });

    // 異動類型切換：只顯示對應的欄位區塊
    $(document).on('change', '#bfChangeKind', function() {
        const k = $(this).val();
        $('.bf-kind-row').hide();
        $('.bf-kind-row[data-kind="' + k + '"]').show();
    });

    // 該日期之前的職務快照（供核對，兼任新增/移除/更動要選的職務都以此為準）
    function loadBfSnapshotPreview() {
        const userId = $('#historyModal').data('id');
        const eff = $('#bfEffDate').val();
        if (!userId || !eff) { $('#bfSnapshotPreview').text('請先選生效日'); return; }
        callApi('get_position_snapshot_at', 'GET', { id: userId, effective_date: eff }, function(res) {
            $('#bfSnapshotPreview').text(res.status === 'success' ? res.label : ('讀取失敗: ' + res.message));
        });
    }
    $(document).on('change', '#bfEffDate', loadBfSnapshotPreview);

    function resetPosBackfillForm() {
        $('#bfChangeKind').val('transfer').trigger('change');
        $('#bfEffDate, #bfReason').val('');
        $('#bfSnapshotPreview').text('請先選生效日');
        $('#bfBeforeDept, #bfAfterDept, #bfAddDept, #bfRemoveDept, #bfChgFromDept, #bfChgToDept').val('');
        $('#bfBeforePos, #bfAfterPos, #bfAddPos, #bfRemovePos, #bfChgFromPos, #bfChgToPos')
            .empty().append('<option value="">職稱…</option>');
    }

    // 補登職務異動（表單三總則：當下驗證、紅字寫原因）
    $('#btnBackfillPos').on('click', function() {
        const userId = $('#historyModal').data('id');
        const $err = $('#bfPosErr').text('');
        const eff = $('#bfEffDate').val();
        if (!eff) { $err.text('請填生效日（當時實際生效的日期）'); return; }
        const kind = $('#bfChangeKind').val();
        const payload = { id: userId, effective_date: eff, change_kind: kind, reason: $('#bfReason').val() };

        if (kind === 'transfer') {
            if (!$('#bfAfterDept').val() || !$('#bfAfterPos').val()) { $err.text('「主職異動後」的部門與職稱必填'); return; }
            const bD = $('#bfBeforeDept').val(), bP = $('#bfBeforePos').val();
            if ((bD && !bP) || (!bD && bP)) { $err.text('「主職異動前」請部門與職稱都選，或兩個都留空'); return; }
            payload.before_department_id = bD || 0; payload.before_position_id = bP || 0;
            payload.after_department_id = $('#bfAfterDept').val(); payload.after_position_id = $('#bfAfterPos').val();
        } else if (kind === 'concurrent_add') {
            if (!$('#bfAddDept').val() || !$('#bfAddPos').val()) { $err.text('請選擇新增的兼任職務'); return; }
            payload.add_department_id = $('#bfAddDept').val(); payload.add_position_id = $('#bfAddPos').val();
        } else if (kind === 'concurrent_remove') {
            if (!$('#bfRemoveDept').val() || !$('#bfRemovePos').val()) { $err.text('請選擇要移除的兼任職務'); return; }
            payload.remove_department_id = $('#bfRemoveDept').val(); payload.remove_position_id = $('#bfRemovePos').val();
        } else { // concurrent_change
            if (!$('#bfChgFromDept').val() || !$('#bfChgFromPos').val()) { $err.text('請選擇「原本的兼任職務」'); return; }
            if (!$('#bfChgToDept').val() || !$('#bfChgToPos').val()) { $err.text('請選擇「更動後的兼任職務」'); return; }
            payload.from_department_id = $('#bfChgFromDept').val(); payload.from_position_id = $('#bfChgFromPos').val();
            payload.to_department_id = $('#bfChgToDept').val(); payload.to_position_id = $('#bfChgToPos').val();
        }

        callApi('backfill_position_history', 'POST', payload, function(res) {
            if (res.status === 'success') {
                resetPosBackfillForm();
                loadChangeHistory(userId);
            }
            else $err.text(res.message);
        });
    });
    $(document).on('click', '.btn-del-poshist', function() {
        if (!confirm('確定刪除此筆補登的職務異動？（教育訓練等頁依日期解析職務會受影響）')) return;
        const userId = $('#historyModal').data('id');
        callApi('delete_position_history', 'POST', { hist_id: $(this).data('hid') }, function(res) {
            if (res.status === 'success') loadChangeHistory(userId); else alert(res.message);
        });
    });

    // 補登在職狀態
    $('#btnBackfillSta').on('click', function() {
        const userId = $('#historyModal').data('id');
        const $err = $('#bfStaErr').text('');
        const sd = $('#bfStaStart').val(), ed = $('#bfStaEnd').val();
        if (!sd) { $err.text('請填開始日'); return; }
        if (ed && ed < sd) { $err.text('結束日（' + ed + '）不可早於開始日（' + sd + '）'); return; }
        callApi('backfill_status_history', 'POST', {
            id: userId, status: $('#bfStaStatus').val(), start_date: sd, end_date: ed, remark: $('#bfStaRemark').val()
        }, function(res) {
            if (res.status === 'success') { $('#bfStaStart, #bfStaEnd, #bfStaRemark').val(''); loadChangeHistory(userId); }
            else $err.text(res.message);
        });
    });
    $(document).on('click', '.btn-del-stahist', function() {
        if (!confirm('確定刪除此筆補登的在職狀態紀錄？')) return;
        const userId = $('#historyModal').data('id');
        callApi('delete_status_history', 'POST', { hist_id: $(this).data('hid') }, function(res) {
            if (res.status === 'success') loadChangeHistory(userId); else alert(res.message);
        });
    });

    // --- 搜尋與篩選事件綁定 ---
    $(document).on('keyup', '#table-search', filterTable);

    // 部門篩選（主職務＋兼任職務共用同一個篩選框）
    $(document).on('change', '.dept-filter', filterTable);

    // 雙擊解除該欄篩選（同輸入欄位規則）
    $(document).on('dblclick', '.dept-filter', function() {
        if ($(this).val()) {
            $(this).val('');
            filterTable();
        }
    });

    // 清除所有篩選條件
    $('#btn-clear-filter').on('click', function() {
        $('#filter-dept').val('');
        $('#table-search').val('');
        filterTable();
    });

    // 雙擊清除搜尋
    $(document).on('dblclick', '#table-search', function() {
        if ($(this).val()) {
            $(this).val('');
            filterTable();
        }
    });

    // 根據權限初始化 UI (新增按鈕)
    if (!(window.hrUserPerm.includes('A') || window.hrUserPerm.includes('C'))) {
        $('#btn-add-employee').hide();
    }

    // 初始載入
    loadDepartmentFilters();
    loadEmployees();
});
</script>
</body>
</html>