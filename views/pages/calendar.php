<?php
session_start();

// 修正：先引入資料庫連線，再引入會使用到 $db 的設定檔
include ("../../src/common/DBConnection.php");
include '../../src/store/_events_setting.php';

// 建立資料庫連線物件
$db = (new DBConnection())->getPDO();

// 取得所有部門/小組列表，用於廣播對象下拉選單與建立部門層級
$departments = [];
$deptMap = [];
try {
    $sql = "
        SELECT id, name, parent_id, level 
        FROM department 
        ORDER BY level ASC, name ASC";
    $dept_stmt = $db->query($sql);
    while ($row = $dept_stmt->fetch(PDO::FETCH_ASSOC)) {
        $departments[] = $row;
        $deptMap[$row['id']] = $row;
    }
} catch (PDOException $e) {
    // Handle error if necessary
}

function getDeptPath($deptId, $deptMap) {
    if (empty($deptId) || !isset($deptMap[$deptId])) return '未指定';
    $path = [];
    $curr = $deptMap[$deptId];
    $limit = 10; // 防止無窮迴圈
    while ($curr && $limit-- > 0) {
        array_unshift($path, $curr['name']);
        if ($curr['level'] <= 3) break; // 若到達 Level 3 (或更上層) 則停止
        $parentId = $curr['parent_id'];
        if (!$parentId || !isset($deptMap[$parentId])) break;
        $curr = $deptMap[$parentId];
    }
    return implode(' / ', $path);
}

$users = [];
try {
    // 取得所有非離職/停用狀態的使用者，並一併取得其主要部門與職稱
    $sql = "
        SELECT 
            u.id, 
            u.user_cname,
            d.name as department_name,
            d.id as department_id,
            p.name as position_name
        FROM user u
        LEFT JOIN user_department_position_map udpm ON u.id = udpm.user_id AND udpm.is_main = 1
        LEFT JOIN department d ON udpm.department_id = d.id
        LEFT JOIN position p ON udpm.position_id = p.id
        WHERE u.state NOT IN (0, 90) ORDER BY u.user_cname ASC";
    $user_stmt = $db->query($sql);
    $users = $user_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Handle error if necessary
}

// 取得所有事件類別
$categories = [];
try {
    $sql = "
        SELECT id, category_name, color 
        FROM event_category 
        ORDER BY category_name ASC";
    $category_stmt = $db->query($sql);
    $categories = $category_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Handle error if necessary
}

// 修正：重新加入這段被誤刪的程式碼，以獲取包含備註的完整類別列表
// 這段程式碼主要用於頁面初次載入時，確保其他部分若有需要，能取得到完整資料
$categories_full = [];
try {
    $sql = "SELECT id, category_name, color, description, day_type FROM event_category ORDER BY category_name ASC";
    $category_stmt_full = $db->query($sql);
    $categories_full = $category_stmt_full->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Handle error if necessary
}

// 取得所有假別
$leave_types = [];
try {
    $sql = "SELECT id, leave_name FROM leave_type ORDER BY id ASC";
    $leave_type_stmt = $db->query($sql);
    $leave_types = $leave_type_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Handle error if necessary
}


//查詢行程
$result = null;
if (isset($_POST['btn_go_schdule'])) {
    $find = $_POST["sreach_schdule"]; // 從 POST 請求中獲取搜索關鍵字

    // 準備 SQL 查詢語句，使用預備語句（Prepared Statement）來防止 SQL 注入
    $stmt = $db->prepare("SELECT * FROM evenement WHERE title LIKE :find ORDER BY start");
    // 綁定參數，並在關鍵字前後加上 '%' 進行模糊搜索
    $stmt->bindValue(':find', '%' . $find . '%', PDO::PARAM_STR);
    $stmt->execute(); // 執行查詢
}

@$userid=$_SESSION['userid'];
@$schdule_title=$_SESSION['schdule_title'];
@$schdule_start=$_SESSION['schdule_start'];
@$schdule_end=$_SESSION['schdule_end'];
@$schdule_category_id = $_SESSION['schdule_category_id'];
@$leave_type_id = $_SESSION['leave_type_id'];
@$gotoDate = isset($_SESSION['gotoDate']) ? $_SESSION['gotoDate'] : '';

// 處理從列表編輯帶入的日期時間
$schdule_start_date = '';
$schdule_start_hour = '00';
$schdule_start_minute = '00';
$schdule_end_date = '';
$schdule_end_hour = '00';
$schdule_end_minute = '00';

if (!empty($schdule_start)) {
    list($schdule_start_date, $schdule_start_time) = explode(' ', $schdule_start);
    list($schdule_start_hour, $schdule_start_minute, ) = explode(':', $schdule_start_time);
}
if (!empty($schdule_end)) {
    list($schdule_end_date, $schdule_end_time) = explode(' ', $schdule_end);
    list($schdule_end_hour, $schdule_end_minute, ) = explode(':', $schdule_end_time);
}

// 用完後清除 session，避免影響後續操作
if (isset($_SESSION['gotoDate'])) {
    unset($_SESSION['gotoDate']);
}
// 清除其他從列表頁帶來的 session 資料
foreach (['userid', 'schdule_title', 'schdule_start', 'schdule_end', 'schdule_category_id', 'leave_type_id'] as $key) {
    if (isset($_SESSION[$key])) unset($_SESSION[$key]);
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

    <title>Excellentgear 超正齒輪</title>

    <!-- Bootstrap -->
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <!-- NProgress -->
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <!-- Custom Theme Style -->
    <!-- Select2 -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css" rel="stylesheet" />

    <!-- iCheck -->
    <link href="../../resource/css/green.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">

    <link rel="stylesheet" href="http://code.jquery.com/ui/1.11.4/themes/smoothness/jquery-ui.css">

    <link href='../../resource/css/fullcalendar.css' rel='stylesheet' />

    <style>
        #event-type-badge-container {
            margin-right: 10px;
            vertical-align: middle;
        }
        .color-picker-container {
            position: relative;
            display: inline-block;
        }
        .select2-results__option .color-box {
            display: inline-block; width: 14px; height: 14px; margin-right: 8px;
            border: 1px solid #ccc; vertical-align: middle;
        }
        /* 新增：管理 Modal 中的顏色方塊樣式 */
        #categoryList .color-box {
            display: inline-block;
            width: 16px;
            height: 16px;
            margin-right: 8px;
            border: 1px solid #ccc;
            vertical-align: -3px; /* 微調垂直對齊 */
        }
        /* 新增：調整顏色選擇器寬度 */
        #category_color {
            width: 50px !important; /* 縮小寬度並填滿顏色 */
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
                            <h3>行事曆</h3>
                        </div>
                    </div>
                    <form method="POST" action="calendar.php?id=<?= $_GET['id']?>">
                        <div class="title_right">
                            <div class="col-md-5 col-sm-5 col-xs-12 form-group pull-right top_search">
                                <div class="input-group">
                                    <input type="text" class="form-control" name="sreach_schdule" placeholder="搜索標題...">
                                    <span class="input-group-btn">
                                        <button name="btn_go_schdule" class="btn btn-default" type="submit">Go!</button>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </form>

                    <div class="clearfix"></div>
                    <div class="row">

                        <div class="col-md-12 col-sm-12 col-xs-12">
                            <div class="x_panel">
                                <div class="x_title">
                                    <ul class="nav navbar-right panel_toolbox">
                                        <li><a class="collapse-link"><i class="fa fa-chevron-up"></i></a>
                                        </li>

                                        <li><a class="close-link"><i class="fa fa-close"></i></a>
                                        </li>
                                    </ul>
                                    <div class="clearfix">
                                        <h2>新增 / 編輯 行事曆</h2>
                                    </div>
                                </div>
                                <div class="x_content">

                                    <form id="addEventForm" method="POST" action="../../src/store/_events_setting.php" class="form-horizontal form-label-left" novalidate>

                                        <input type="hidden" id="userid" name="userid" value="<?= $userid ?>">
                                        <input type="hidden" name="id" value="<?= $_GET['id'] ?>">
                                        <!-- 新增隱藏欄位來儲存原始事件日期 -->
                                        <!-- 為了簡化後端邏輯，我們現在也需要 original_event_end -->
                                        <input type="hidden" id="original_event_end" name="original_event_end" value="">
                                        <input type="hidden" id="original_event_date" name="original_event_date" value="">

                                        <div class="row">
                                            <!-- 左側欄位 -->
                                            <div class="col-md-6 col-sm-12 col-xs-12">
                                                <div class="item form-group">
                                                    <label class="control-label col-md-3 col-sm-3 col-xs-12" for="schdule_title">標題 <span class="required">*</span></label>
                                                    <div class="col-md-9 col-sm-9 col-xs-12">
                                                        <span id="event-type-badge-container" style="display: none;"></span>
                                                        <input id="schdule_title" class="form-control" value="<?= $schdule_title ?>" name="schdule_title" required="required" type="text">
                                                    </div>
                                                </div>

                                                <div class="item form-group">
                                                    <label class="control-label col-md-3 col-sm-3 col-xs-12" for="event_category">事件類別 <span class="required">*</span></label>
                                                    <div class="col-md-9 col-sm-9 col-xs-12" style="display: flex; align-items: center; gap: 10px;">
                                                        <!-- 新增：用於顯示顏色的方塊 -->
                                                        <span id="category_color_display" style="display: inline-block; width: 20px; height: 20px; border: 1px solid #ccc; background-color: #fff;"></span>
                                                        <div id="event_category_container" style="flex-grow: 1; transition: all 0.3s ease;">
                                                            <select id="event_category" name="category_id" class="form-control" required="required" style="width: 100%;">
                                                                <option value="">請選擇類別</option>
                                                                <?php foreach ($categories_full as $category): ?>
                                                                    <option value="<?= htmlspecialchars($category['id']) ?>" data-color="<?= htmlspecialchars($category['color']) ?>" data-day-type="<?= htmlspecialchars($category['day_type']) ?>" data-name="<?= htmlspecialchars($category['category_name']) ?>" <?= (isset($schdule_category_id) && $schdule_category_id == $category['id']) ? 'selected' : '' ?>>
                                                                        <?= htmlspecialchars($category['category_name']) ?>
                                                                    </option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>
                                                        <button type="button" id="manage_categories_btn" class="btn btn-default" title="管理事件類別">
                                                            <i class="fa fa-plus"></i>
                                                        </button>
                                                        <!-- 新增：假別下拉選單，預設隱藏 -->
                                                        <div id="leave_type_container" style="display: none; flex-grow: 1;">
                                                            <select id="leave_type_select" name="leave_type" class="form-control" style="width: 100%;">
                                                                <option value="">請選擇假別</option>
                                                                <?php foreach ($leave_types as $leave_type): ?>
                                                                    <option value="<?= htmlspecialchars($leave_type['id']) ?>" <?= (isset($leave_type_id) && $leave_type_id == $leave_type['id']) ? 'selected' : '' ?>><?= htmlspecialchars($leave_type['leave_name']) ?></option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>
                                                    </div>
                                                </div>

                                                <div class="item form-group">
                                                    <label class="control-label col-md-3 col-sm-3 col-xs-12" for="actors">發生者</label>
                                                    <div class="col-md-9 col-sm-9 col-xs-12">
                                                        <select id="actors" name="actors[]" class="form-control" multiple="multiple">
                                                            <?php foreach ($users as $user): 
                                                                $department = htmlspecialchars(getDeptPath($user['department_id'] ?? 0, $deptMap));
                                                                $position = htmlspecialchars($user['position_name'] ?? '未指定');
                                                            ?>
                                                                <option value="<?= htmlspecialchars($user['id']) ?>" data-name="<?= htmlspecialchars($user['user_cname']) ?>" data-department="<?= $department ?>" data-position="<?= $position ?>">
                                                                    <?= htmlspecialchars($user['user_cname']) ?> (<?= $department ?> / <?= $position ?>)
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                        <div class="checkbox" style="margin-top: 10px;">
                                                            <label>
                                                                <input type="checkbox" id="actor_all" name="actor_all"> 全體成員
                                                            </label>
                                                        </div>
                                                    </div>
                                                </div>

                                                <div class="item form-group">
                                                    <label class="control-label col-md-3 col-sm-3 col-xs-12" for="targets">廣播對象</label>
                                                    <div class="col-md-9 col-sm-9 col-xs-12">
                                                        <select id="targets" name="targets[]" class="form-control" multiple="multiple">
                                                            <optgroup label="部門/小組">
                                                                <?php foreach ($departments as $department): ?>
                                                                    <option value="dept-<?= htmlspecialchars($department['id']) ?>">
                                                                        <?= htmlspecialchars($department['name']) ?>
                                                                    </option>
                                                                <?php endforeach; ?>
                                                            </optgroup>
                                                            <optgroup label="使用者">
                                                                <?php foreach ($users as $user): 
                                                                    $department = htmlspecialchars(getDeptPath($user['department_id'] ?? 0, $deptMap));
                                                                    $position = htmlspecialchars($user['position_name'] ?? '未指定');
                                                                ?>
                                                                    <option value="user-<?= htmlspecialchars($user['id']) ?>" data-name="<?= htmlspecialchars($user['user_cname']) ?>" data-department="<?= $department ?>" data-position="<?= $position ?>">
                                                                        <?= htmlspecialchars($user['user_cname']) ?> (<?= $department ?> / <?= $position ?>)
                                                                    </option>
                                                                <?php endforeach; ?>
                                                            </optgroup>
                                                        </select>
                                                        <div class="checkbox" style="margin-top: 10px;">
                                                            <label>
                                                                <input type="checkbox" id="target_all" name="target_all" checked> 預設廣播給全體成員
                                                            </label>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- 右側欄位 -->
                                            <div class="col-md-6 col-sm-12 col-xs-12">
                                                <div class="item form-group">
                                                    <label class="control-label col-md-3 col-sm-3 col-xs-12" for="schdule_all_day">全天事件</label>
                                                    <div class="col-md-9 col-sm-9 col-xs-12" style="padding-top: 8px; display: flex; align-items: center;">
                                                        <div class="checkbox" style="margin-right: 10px; margin-top: 0; margin-bottom: 0;">
                                                            <label><input type="checkbox" id="schdule_all_day" name="schdule_all_day"></label>
                                                        </div>
                                                        <button type="button" id="auto_date_btn" class="btn btn-info btn-xs">自動日期</button>
                                                    </div>
                                                </div>

                                                <div class="item form-group">
                                                    <label class="control-label col-md-3 col-sm-3 col-xs-12" for="schdule_start">開始日期 <span class="required">*</span></label>
                                                    <div class="col-md-9 col-sm-9 col-xs-12" style="display: flex; align-items: center; gap: 15px;" data-start-hour="<?= $schdule_start_hour ?>" data-start-minute="<?= $schdule_start_minute ?>">
                                                        <input type="text" id="datepicker" value="<?= $schdule_start_date ?>" name='schdule_start' class="form-control" style="width: 120px;" autocomplete="off">
                                                        <div class="time-select-container" style="display: flex; align-items: center; gap: 10px;">
                                                            <select id="start_hour" name="start_hour" class="form-control" style="width: 70px;"></select> : 
                                                            <select id="start_minute" name="start_minute" class="form-control" style="width: 70px;"></select>
                                                        </div>
                                                        <div id="original-start-display" class="text-muted" style="display: none;"></div>
                                                    </div>
                                                </div>

                                                <div class="item form-group">
                                                    <label class="control-label col-md-3 col-sm-3 col-xs-12" for="schdule_end">結束日期 <span class="required">*</span></label>
                                                    <div class="col-md-9 col-sm-9 col-xs-12" style="display: flex; align-items: center; gap: 15px;" data-end-hour="<?= $schdule_end_hour ?>" data-end-minute="<?= $schdule_end_minute ?>">
                                                        <input type="text" id="datepicker_end" value="<?= $schdule_end_date ?>" name='schdule_end' class="form-control" style="width: 120px;" autocomplete="off">
                                                        <div class="time-select-container" style="display: flex; align-items: center; gap: 10px;">
                                                            <select id="end_hour" name="end_hour" class="form-control" style="width: 70px;"></select> : 
                                                            <select id="end_minute" name="end_minute" class="form-control" style="width: 70px;"></select>
                                                        </div>
                                                        <div id="original-end-display" class="text-muted" style="display: none;"></div>
                                                    </div>
                                                </div>

                                                <div class="item form-group">
                                                    <label class="control-label col-md-3 col-sm-3 col-xs-12" for="recurrence_type">重複</label>
                                                    <div class="col-md-9 col-sm-9 col-xs-12" style="display: flex; align-items: center; gap: 10px;">
                                                        <select id="recurrence_type" name="recurrence_type" class="form-control" style="width: 120px;">
                                                            <option value="">不重複</option>
                                                            <option value="daily">每天</option>
                                                            <option value="weekly">每週</option>
                                                            <option value="monthly">每月</option>
                                                            <option value="yearly">每年</option>
                                                        </select>
                                                        <div id="recurrence_count_container" style="display: none; align-items: center; gap: 10px;">
                                                            <span>重複</span>
                                                            <input type="number" id="recurrence_count" name="recurrence_count" class="form-control" min="1" value="1" style="width: 80px;">
                                                            <span>次</span>
                                                            <label style="margin-left: 10px; margin-bottom: 0; font-weight: normal; cursor: pointer;">
                                                                <input type="checkbox" id="recurrence_independent" name="recurrence_independent"> 獨立事件(不連動)
                                                            </label>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- 新增：備註欄位 -->
                                        <div class="row" style="margin-top: 15px;">
                                            <div class="col-md-12 col-sm-12 col-xs-12">
                                                <div class="item form-group">
                                                    <label class="control-label col-md-1 col-sm-3 col-xs-12" for="schdule_remark" style="text-align: left; padding-left: 30px;">備註</label>
                                                    <div class="col-md-11 col-sm-9 col-xs-12"><textarea id="schdule_remark" name="remark" class="form-control" rows="2"></textarea></div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="ln_solid"></div>
                                        <div class="form-group">
                                            <div class="col-md-6 col-md-offset-3">
                                                <!-- 將 name="resetCalendar" 改為 id="cancelBtn"，並改為 button 類型 -->
                                                <button id="cancelBtn" type="button" class="btn btn-primary">取消</button>
                                                <button id="send" name="newSchdule" type="submit" class="btn btn-success">送出</button>
                                                <!-- 新增複製按鈕，預設隱藏 -->
                                                <button id="copyBtn" type="button" class="btn btn-info" style="display: none;">複製事件</button>
                                                <!-- 新增刪除按鈕，預設隱藏 -->
                                                <a id="deleteBtn" href="#" style="display: none;" onclick="return confirm('您確定要刪除此行程嗎？');"><button type="button" class="btn btn-danger">刪除</button></a>
                                            </div>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="clearfix"></div>

                <div class="row">
                    <!-- 修改：行事曆欄 -->
                    <div class="col-md-8 col-sm-12 col-xs-12">
                        <div class="x_panel">
                            <div class="x_title">
                                <ul class="nav navbar-right panel_toolbox">
                                    <li><a class="collapse-link"><i class="fa fa-chevron-up"></i></a>
                                </ul>
                                <div class="clearfix">
                                    <h3>行事曆</h3>
                                </div>
                            </div>
                            <div class="x_content">
                                <!-- 新增：年份與月份快速切換器 -->
                                <div class="row" style="margin-bottom: 15px; display: flex; align-items: center;">
                                    <div class="col-md-2 col-sm-3 col-xs-12" style="margin-bottom: 5px; display: none;">
                                        <select id="calendar-year-selector" class="form-control"></select>
                                    </div>
                                    <div class="col-md-12 col-sm-12 col-xs-12 text-center">
                                        <div role="group" aria-label="月份快速切換">
                                            <button type="button" class="btn btn-primary btn-xs btn-month" data-month="0" style="margin: 2px;">一月</button>
                                            <button type="button" class="btn btn-primary btn-xs btn-month" data-month="1" style="margin: 2px;">二月</button>
                                            <button type="button" class="btn btn-primary btn-xs btn-month" data-month="2" style="margin: 2px;">三月</button>
                                            <button type="button" class="btn btn-primary btn-xs btn-month" data-month="3" style="margin: 2px;">四月</button>
                                            <button type="button" class="btn btn-primary btn-xs btn-month" data-month="4" style="margin: 2px;">五月</button>
                                            <button type="button" class="btn btn-primary btn-xs btn-month" data-month="5" style="margin: 2px;">六月</button>
                                            <button type="button" class="btn btn-primary btn-xs btn-month" data-month="6" style="margin: 2px;">七月</button>
                                            <button type="button" class="btn btn-primary btn-xs btn-month" data-month="7" style="margin: 2px;">八月</button>
                                            <button type="button" class="btn btn-primary btn-xs btn-month" data-month="8" style="margin: 2px;">九月</button>
                                            <button type="button" class="btn btn-primary btn-xs btn-month" data-month="9" style="margin: 2px;">十月</button>
                                            <button type="button" class="btn btn-primary btn-xs btn-month" data-month="10" style="margin: 2px;">十一月</button>
                                            <button type="button" class="btn btn-primary btn-xs btn-month" data-month="11" style="margin: 2px;">十二月</button>
                                        </div>
                                    </div>
                                </div>
                                <div class="calendarclass" id='calendar'></div>
                            </div>
                        </div>
                    </div>

                    <!-- 新增：右側統計欄 -->
                    <div class="col-md-4 col-sm-12 col-xs-12">
                        <div class="x_panel">
                            <div class="x_title">
                                <h2>當月事件統計</h2>
                                <div class="clearfix"></div>
                            </div>
                            <div class="x_content">
                                <p><strong>本月上班日：</strong><span id="current-month-workdays">計算中...</span> 天</p>
                                <table class="table table-striped" id="event-stats-table">
                                    <thead>
                                        <tr>
                                            <th style="width: 20px;"></th>
                                            <th>類別</th>
                                            <th class="text-right">次數</th>
                                            <th class="text-right">較上月</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <!-- 統計資料將由 JS 動態填入 -->
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="x_panel">
                            <div class="x_title">
                                <h2>年度事件統計</h2>
                                <div class="clearfix"></div>
                            </div>
                            <div class="x_content">
                                <p><strong>本年度上班日：</strong><span id="current-year-workdays">計算中...</span> 天</p>
                                <table class="table table-striped" id="year-event-stats-table">
                                    <thead>
                                        <tr>
                                            <th style="width: 20px;"></th>
                                            <th>類別</th>
                                            <th class="text-right">次數</th>
                                        </tr>
                                    </thead>
                                    <tbody></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 行事曆列表 -->
                <?php if (isset($_POST['btn_go_schdule']) && $stmt->rowCount() > 0) : ?>
                    <form method="POST" action="">
                        <div class="row">
                            <div class="col-md-12 col-sm-12 col-xs-12">
                                <div class="x_panel">
                                    <div class="x_title">
                                        <h2>行事曆列表</h2>
                                        <ul class="nav navbar-right panel_toolbox">
                                            <li><a class="collapse-link"><i class="fa fa-chevron-up"></i></a>
                                            </li>
                                            <li><a class="close-link"><i class="fa fa-close"></i></a>
                                            </li>
                                        </ul>
                                        <div class="clearfix"></div>
                                    </div>
                                    <div class="x_content">
                                        <p class="text-muted font-13 m-b-30"></p>
                                        <table class="table table-striped table-bordered">
                                            <thead>
                                                <tr>
                                                    <th>標題</th>
                                                    <th>開始日期</th>
                                                    <th>結束日期</th>
                                                    <th></th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) : ?>
                                                    <tr>
                                                        <td style="width: 300px"><?= htmlspecialchars($row["title"]) ?></td>
                                                        <td style="width: 200px"><?= htmlspecialchars($row["start"]) ?></td>
                                                        <td style="width: 200px"><?= htmlspecialchars($row["end"]) ?></td>
                                                        <td>
                                                            <a href="../../src/store/_updateSchdule.php?updateid=<?= $row["id"] ?>&id=<?= $_GET['id']?>"><input type="button" name="updateSchdule" class="btn btn-warning btn-xs update" value="更新"></a>
                                                            <a href="../../src/store/_deleteSchdule.php?delid=<?= $row["id"] ?>&id=<?= $_GET['id']?>" onclick="return confirm('您確定要刪除此行程嗎？')"><input type="button" name="deleteSchdule" class="btn btn-danger btn-xs delete" value="刪除"></a>
                                                        </td>
                                                    </tr>
                                                <?php endwhile; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- /page content -->

        <!-- footer content include -->
        <?php include '../partPage/footer.html' ?>
        <!-- /footer content include -->
    </div>
    </div>


    <!-- Edit Event Modal -->
    <div class="modal fade" id="editEventModal" tabindex="-1" role="dialog" aria-labelledby="editEventModalLabel">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <form id="editEventForm">
                    <div class="modal-header">
                        <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                        <h4 class="modal-title" id="editEventModalLabel">編輯/刪除行程</h4>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" id="edit_event_id" name="userid">
                        
                        <div class="form-group">
                            <label for="edit_schdule_title">標題</label>
                            <input type="text" class="form-control" id="edit_schdule_title" name="schdule_title" required>
                        </div>
                        <div class="form-group">
                            <div class="checkbox">
                                <label for="edit_all_day">
                                    <input type="checkbox" id="edit_all_day" name="schdule_all_day"> 全天事件
                                </label>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="edit_datepicker_start">開始日期與時間</label>
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <input type="text" class="form-control" id="edit_datepicker_start" name="schdule_start" required style="width: 120px;" autocomplete="off">
                                <select id="edit_start_hour" name="start_hour" class="form-control time-select" style="width: 70px;"></select> :
                                <select id="edit_start_minute" name="start_minute" class="form-control time-select" style="width: 70px;"></select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="edit_datepicker_end">結束日期與時間</label>
                            <div style="display: flex; align-items: center; gap: 10px;" autocomplete="off">
                                <input type="text" class="form-control" id="edit_datepicker_end" name="schdule_end" required style="width: 120px;">
                                <select id="edit_end_hour" name="end_hour" class="form-control time-select" style="width: 70px;"></select> :
                                <select id="edit_end_minute" name="end_minute" class="form-control time-select" style="width: 70px;"></select>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" id="deleteEventBtn" class="btn btn-danger pull-left">刪除</button>
                        <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
                        <button type="submit" class="btn btn-success">儲存變更</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Category Manager Modal -->
    <div class="modal fade" id="categoryManagerModal" tabindex="-1" role="dialog" aria-labelledby="categoryManagerModalLabel">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                    <h4 class="modal-title" id="categoryManagerModalLabel">管理事件類別</h4>
                </div>
                <div class="modal-body">
                    <!-- Form for adding/editing a category -->
                    <form id="categoryForm" class="form-horizontal">
                        <input type="hidden" id="edit_category_id" name="id">
                        <div class="form-group">
                            <label for="category_name" class="col-sm-3 control-label">類別名稱</label>
                            <div class="col-sm-7">
                                <input type="text" class="form-control" id="category_name" name="category_name" required>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="category_color" class="col-sm-3 control-label">顏色</label>
                            <div class="col-sm-4">
                                <input type="color" class="form-control" id="category_color" name="color" value="#3a87ad" required>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="category_description" class="col-sm-3 control-label">備註</label>
                            <div class="col-sm-7">
                                <textarea class="form-control" id="category_description" name="description" rows="2"></textarea>
                            </div>
                        </div>
                        <div class="form-group">
                            <label for="day_type" class="col-sm-3 control-label">日期類型</label>
                            <div class="col-sm-7"><select class="form-control" id="day_type" name="day_type">
                                <option value="">一般日 (預設)</option>
                                <option value="s">休假日</option>
                                <option value="m">補班日</option>
                            </select></div>
                        </div>
                        <div class="form-group">
                            <div class="col-sm-offset-3 col-sm-9">
                                <button type="submit" class="btn btn-success">儲存類別</button>
                                <button type="button" id="cancelEditCategory" class="btn btn-default" style="display: none;">取消編輯</button>
                            </div>
                        </div>
                    </form>
                    <hr>
                    <h4>現有類別</h4>
                    <div id="categoryList" class="list-group">
                        <!-- Categories will be loaded here via AJAX -->
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- jQuery -->
    <!-- 1. 必須最先載入 jQuery -->
    <script src="../../resource/js/jquery.min.js"></script>
    <!-- 2. 接著載入依賴 jQuery 的插件 -->
    <script src="http://code.jquery.com/ui/1.11.4/jquery-ui.js"></script>
    <!-- Bootstrap -->
    <script src="../../resource/js/bootstrap.min.js"></script>
    <!-- 修正：Moment.js 必須在 FullCalendar 之前載入 -->
    <script src='../../resource/js/moment.min.js'></script>
    <!-- FastClick -->
    <script src="../../resource/js/fastclick.js"></script>
    <!-- fullcalendar -->
    <script src='../../resource/js/fullcalendar.min.js'></script><!-- 日曆配件 -->
    <!-- NProgress -->
    <script src="../../resource/js/nprogress.js"></script>
    <!-- iCheck -->
    <script src="../../resource/js/icheck.min.js"></script>
    <!-- Select2 -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>

    <!-- Datatables and other plugins that might be used by custom.min.js -->
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

    <!-- Custom Theme Scripts - 應在我們的頁面腳本之前，但在所有函式庫之後載入 -->
    <script src="../../resource/js/custom.min.js"></script>

    <!-- 我們的頁面腳本，包含 FullCalendar 初始化 -->
    <script>
        // 注入類別資料以供前端統計使用
        var categoriesLookup = {};
        <?php foreach ($categories_full as $cat): ?>
        categoriesLookup['<?= $cat['id'] ?>'] = {
            name: '<?= htmlspecialchars($cat['category_name']) ?>',
            color: '<?= $cat['color'] ?>'
        };
        <?php endforeach; ?>

        $(function() { // 使用 $(function() { ... }) 作為 $(document).ready() 的簡寫，並合併所有邏輯
            
            // --- 新增：處理請假人員顯示邏輯 ---
            var usersOnLeave = []; // 儲存目前選定日期的請假人員 ID 列表

            function fetchLeaveUsers(date) {
                $.ajax({
                    url: '../../src/store/get_users_on_leave.php',
                    type: 'GET',
                    data: { date: date },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            usersOnLeave = response.users; // 更新全域變數
                            // 這裡不需要強制重繪 Select2，因為 Select2 在下次打開或渲染時會讀取新的 usersOnLeave
                        }
                    }
                });
            }

            // --- Select2 初始化 ---
            // --- 事件類別 Select2 初始化 (移到最前面確保優先執行) ---
            $('#event_category').select2({
                width: '100%',
                templateResult: formatCategory,
                templateSelection: formatCategorySelection
            });

            // 新增：當事件類別改變時，更新旁邊的顏色方塊
            $('#event_category').on('change', function(e) {
                var selectedOption = $(this).find('option:selected');
                var color = selectedOption.data('color');
                var categoryId = selectedOption.val(); // 新增：取得 category ID
                var dayType = selectedOption.data('day-type');
                var categoryName = selectedOption.data('name');

                if (color) {
                    $('#category_color_display').css('background-color', color);
                } else {
                    // 如果沒有選擇或選擇的是預設選項，則恢復白色
                    $('#category_color_display').css('background-color', '#fff');
                }

                // --- 新增：根據 day_type 控制 UI ---
                var $titleInput = $('#schdule_title');
                var $categoryContainer = $('#event_category_container');
                var $leaveContainer = $('#leave_type_container');

                // 修正：如果類別名稱為"休假"，就觸發假別介面
                if (categoryName === '休假') { 
                    $categoryContainer.css('flex-grow', '0').css('width', '150px'); // 縮小事件類別寬度
                    $leaveContainer.show(); // 顯示假別下拉選單
                    $('#leave_type_select').prop('disabled', false); // 啟用下拉選單
                    $titleInput.val('').prop('readonly', true).css('background-color', '#eee'); // 修正：改為 readonly，讓表單可以提交

                    // --- 新增：在這裡設定假別的值 ---
                    var currentEvent = $(this).data('currentEvent');
                    // 修正：只要是從 eventClick 進來的 (有 currentEvent)，就強制設定值
                    if (currentEvent) {
                        // 這樣即使 leave_type_id 為 null 或空字串，也會正確清空下拉選單，避免殘留上一次的選擇
                        $('#leave_type_select').val(currentEvent.leave_type_id || '');
                        // 修正：因為上方 $titleInput.val('') 清空了標題，這裡將原標題填回，避免標題變成空白
                        if (currentEvent.title) {
                            $titleInput.val(currentEvent.title);
                        }
                        $(this).removeData('currentEvent'); // 用完後清除，避免影響後續操作
                    }
                } else { // 如果不是休假日
                    $categoryContainer.css('flex-grow', '1').css('width', ''); // 恢復事件類別寬度
                    $leaveContainer.hide(); // 隱藏假別下拉選單
                    $('#leave_type_select').val(''); // 清空假別選擇，但不禁用，確保後端能接收到參數
                    $titleInput.prop('readonly', false).css('background-color', ''); // 修正：移除 readonly 屬性
                }
            });

            // --- 新增：當假別改變時，將假別名稱填入標題欄位 ---
            $('#leave_type_select').on('change', function() {
                var selectedText = $(this).find('option:selected').text();
                var $titleInput = $('#schdule_title');
                if ($(this).val()) {
                    $titleInput.val(selectedText);
                } else {
                    $titleInput.val('');
                }
            });


            function formatCategory (category) {
                if (!category.id) { return category.text; }
                var $category = $(
                    '<span><span class="color-box" style="background-color:' + $(category.element).data('color') + '"></span>' + category.text + '</span>'
                );
                return $category;
            };

            function formatCategorySelection (category) {
                if (!category.id) { return category.text; }
                 var $category = $(
                    '<span><span class="color-box" style="background-color:' + $(category.element).data('color') + '"></span>' + category.text + '</span>'
                );
                return $category;
            };

            // 修改：將 Select2 物件存入變數，以便後續控制
            var $actorsSelect = $('#actors').select2({
                width: '100%', // 讓 select2 填滿 .col-md-6 容器
                placeholder: '選擇事件發生者',
                allowClear: true,
                // templateResult 用於格式化下拉選單中的選項
                templateResult: function(data) {
                    if (!data.id) { return data.text; }
                    
                    var $element = $(data.element);
                    var department = $element.data('department');
                    var position = $element.data('position');
                    var name = $element.data('name');
                    
                    // 檢查是否請假
                    var isLeave = usersOnLeave.indexOf(data.id) !== -1 || usersOnLeave.indexOf(parseInt(data.id)) !== -1;
                    var leaveHtml = isLeave ? ' <span style="color: red; font-weight: bold;">(休假)</span>' : '';

                    if (name && department && position) {
                        // 組合顯示名稱與部門職稱
                        return $(
                            '<span>' + name + 
                            ' <small style="color: #555;">(' + department + ' / ' + position + ')</small>' + leaveHtml +
                            '</span>'
                        );
                    }
                    return data.text;
                },
                // templateSelection 用於格式化已選擇的項目
                templateSelection: function(data) {
                    // 選中後只顯示人名
                    var $element = $(data.element);
                    var name = $element.data('name');
                    
                    var isLeave = usersOnLeave.indexOf(data.id) !== -1 || usersOnLeave.indexOf(parseInt(data.id)) !== -1;
                    var leaveHtml = isLeave ? ' <span style="color: red;">(休假)</span>' : '';

                    if (name) {
                        return $('<span>' + name + leaveHtml + '</span>');
                    }
                    return data.text;
                }
            });

            // --- Select2 初始化 (廣播對象) ---
            var $targetsSelect = $('#targets').select2({
                width: '100%',
                placeholder: '選擇廣播對象 (部門或使用者)',
                allowClear: true,
                templateResult: function(data) {
                    if (!data.id) { return data.text; }
                    var $element = $(data.element);
                    var department = $element.data('department');
                    var position = $element.data('position');
                    var name = $element.data('name');
                    
                    // 檢查是否請假 (針對 user-ID 格式)
                    var userId = null;
                    if (data.id.startsWith('user-')) {
                        userId = data.id.split('-')[1];
                    }
                    var isLeave = userId && (usersOnLeave.indexOf(userId) !== -1 || usersOnLeave.indexOf(parseInt(userId)) !== -1);
                    var leaveHtml = isLeave ? ' <span style="color: red; font-weight: bold;">(休假)</span>' : '';

                    if (name && department && position) {
                        return $(
                            '<span>' + name + 
                            ' <small style="color: #555;">(' + department + ' / ' + position + ')</small>' + leaveHtml +
                            '</span>'
                        );
                    }
                    return data.text;
                },
                templateSelection: function(data) {
                    var $element = $(data.element);
                    var name = $element.data('name');
                    
                    // 檢查是否請假
                    var userId = null;
                    if (data.id && data.id.startsWith('user-')) {
                        userId = data.id.split('-')[1];
                    }
                    var isLeave = userId && (usersOnLeave.indexOf(userId) !== -1 || usersOnLeave.indexOf(parseInt(userId)) !== -1);
                    var leaveHtml = isLeave ? ' <span style="color: red;">(休假)</span>' : '';

                    if (name) {
                        return $('<span>' + name + leaveHtml + '</span>');
                    }
                    return name || data.text;
                }
            });

            // --- 廣播對象 checkbox 事件綁定 ---
            $('#target_all').on('change', function() {
                if (this.checked) {
                $targetsSelect.val(null).trigger('change'); // 清空選擇
                $targetsSelect.prop('disabled', true); // 禁用下拉選單
                } else {
                $targetsSelect.prop('disabled', false); // 啟用下拉選單
                }
            });
            
            // 頁面載入時，根據 checkbox 初始狀態決定是否禁用
            if ($('#target_all').is(':checked')) { $targetsSelect.prop('disabled', true); }

            // --- 新增：發生者 checkbox 事件綁定 ---
            $('#actor_all').on('change', function() {
                if (this.checked) {
                    $actorsSelect.val(null).trigger('change'); // 清空選擇
                    $actorsSelect.prop('disabled', true); // 禁用下拉選單
                } else {
                    $actorsSelect.prop('disabled', false); // 啟用下拉選單
                }
            });

            // --- iCheck 初始化與事件綁定 ---
            // 確保在頁面載入時，所有 class="flat" 的 checkbox 都被 iCheck 正確初始化
            $('input.flat').iCheck({
                checkboxClass: 'icheckbox_flat-green',
                radioClass: 'iradio_flat-green'
            });

            // 當「全天事件」勾選框狀態改變時，同步觸發 change 事件
            $('#schdule_all_day, #edit_all_day').on('ifChanged', function() {
                $(this).trigger('change');
            });
            var date = new Date();
            var d = date.getDate();
            var m = date.getMonth();
            var y = date.getFullYear();

            // 新增：用於防止 eventAfterAllRender 無限遞迴的旗標
            var isRerendering = false;
            var gotoDate = '<?= $gotoDate ?>'; // 從 PHP 獲取跳轉日期

            var calendar = $('#calendar').fullCalendar({
                editable: false,
                events: '../../src/store/events.php',
                displayEventTime: true,
                timeFormat: 'HH:mm',
                header: {
                    left: 'prev,next today',
                    center: 'title',
                    right: 'month,agendaWeek,agendaDay'
                },

                // 新增：修改標題格式與樣式
                titleFormat: 'yyyy.M',
                
                // 修改：在視圖渲染完成後觸發統計。這是最可靠的時機。
                // viewRender 會在每次切換月份、週、日視圖時執行
                viewRender: function(view, element) {
                    // viewRender 只負責處理與視圖本身相關的樣式，不處理事件數據
                    $('.fc-center h2').css({'font-weight': 'bold', 'font-size': '1.5em'});
                },

                // 新增：使用 eventAfterAllRender 確保在所有事件載入並渲染後才執行
                // 這是解決顏色和統計問題的關鍵
                eventAfterAllRender: function(view) {
                    // --- 邏輯從 calendar-工作日正常.php 整合過來 ---
                    var events = $('#calendar').fullCalendar('clientEvents');
                    var viewStart = moment(view.start);
                    var viewEnd = moment(view.end);
                    var currentYear = viewStart.year();

                    // --- 當月與年度統計 ---
                    var holidays = new Set();
                    var makeupDays = new Set();

                    events.forEach(function(event) {
                        // 只處理有 day_type 的事件
                        if (event.start && (event.day_type === 's' || event.day_type === 'm')) {
                            var current = moment(event.start);
                            // 如果沒有結束日期，則視為單日事件
                            var end = event.end ? moment(event.end) : current.clone();
                            
                            // 遍歷事件區間內的每一天
                            while (current.isSameOrBefore(end, 'day')) {
                                var dateStr = current.format('YYYY-MM-DD');
                                if (event.day_type === 's') {
                                    holidays.add(dateStr);
                                } else if (event.day_type === 'm') {
                                    makeupDays.add(dateStr);
                                }
                                current.add(1, 'day');
                            }
                        }
                    });

                    // --- 計算上班日 ---
                    function calculateWorkdays(start, end) {
                        let count = 0;
                        let current = start.clone();
                        while (current.isSameOrBefore(end)) {
                            let day = current.day(); // 0=週日, 6=週六
                            let dateStr = current.format('YYYY-MM-DD');
                            
                            var isWeekend = (day === 0 || day === 6);
                            var isHoliday = holidays.has(dateStr);
                            var isMakeupDay = makeupDays.has(dateStr);

                            // 判斷條件：是補班日，或者 (不是週末 而且 不是休假日)
                            if (isMakeupDay || (!isWeekend && !isHoliday)) {
                                count++;
                            }
                            current.add(1, 'days');
                        }
                        return count;
                    }

                    // --- 更新上班日顯示 ---
                    // FullCalendar 的 view.end 是不包含的，所以要往前減一天
                    var monthWorkdays = calculateWorkdays(viewStart, viewEnd.clone().subtract(1, 'day'));
                    $('#current-month-workdays').text(monthWorkdays);

                    var yearStart = moment({ year: currentYear, month: 0, day: 1 });
                    var yearEnd = moment({ year: currentYear, month: 11, day: 31 });
                    var yearWorkdays = calculateWorkdays(yearStart, yearEnd);
                    $('#current-year-workdays').text(yearWorkdays);

                    // 重新觸發一次 rerenderEvents 來確保背景色正確
                    // 這裡我們仍然需要遞迴旗標來防止無限循環
                    if (!isRerendering) {
                        isRerendering = true;
                        $('#calendar').fullCalendar('rerenderEvents');
                        isRerendering = false;
                    }

                    // 呼叫原本的事件類別統計函式 (如果需要保留的話)
                    // 注意：updateEventStats 函式現在可以被簡化或移除，因為工作日計算已在此完成
                    // 為了完整性，暫時保留，但您可以考慮重構
                    updateEventStats(view); 

                    // --- 新增：更新 Tooltip 中的請假狀態 ---
                    // 1. 建立請假對照表 (日期 -> 請假人員ID列表)
                    var leaveMap = {}; 
                    events.forEach(function(ev) {
                        if (ev.category_id == 1) { // 類別 1 為休假
                            var start = moment(ev.start).startOf('day');
                            var end = ev.end ? moment(ev.end) : start.clone().add(1, 'days');
                            var curr = start.clone();
                            // 遍歷事件涵蓋的每一天
                            while (curr.isBefore(end)) {
                                var d = curr.format('YYYY-MM-DD');
                                if (!leaveMap[d]) leaveMap[d] = [];
                                if (ev.actors) ev.actors.forEach(function(a) { leaveMap[d].push(a.id); });
                                curr.add(1, 'days');
                            }
                        }
                    });

                    // 2. 更新所有事件的 Tooltip
                    events.forEach(function(ev) {
                        if (ev._element && ev.actors && ev.actors.length > 0) {
                            // 判斷是否為全體員工 (發生者人數等於下拉選單總人數，或後端標記為全體)
                            var totalUsers = $('#actors option').length;
                            var isAllUsers = (ev.actors.length === totalUsers) || ev.all_actors;

                            var tooltipParts = [];

                            if (isAllUsers) {
                                tooltipParts.push('發生者：全體員工');
                            } else {
                                var dateStr = moment(ev.start).format('YYYY-MM-DD');
                                var leavesOnDate = leaveMap[dateStr] || [];
                                
                                // 重新產生 Tooltip 內容
                                var actorContent = ev.actors.map(function(actor) {
                                    var dept = actor.department_name ? '[' + actor.department_name + '] ' : '';
                                    // 檢查該人員是否在當天請假
                                    var isLeave = leavesOnDate.indexOf(actor.id) !== -1 || leavesOnDate.indexOf(String(actor.id)) !== -1;
                                    var leaveText = isLeave ? ' <span style="color:red">(休假)</span>' : '';
                                    return dept + actor.name + leaveText;
                                }).join('<br>');
                                tooltipParts.push(actorContent);
                            }

                            if (ev.remark && ev.remark.trim() !== '') {
                                tooltipParts.push('備註：' + ev.remark.replace(/\n/g, '<br>'));
                            }
                            
                            // 更新 DOM 上的 Tooltip
                            $(ev._element).attr('data-original-title', tooltipParts.join('<br>'));
                        }
                    });
                },

                // 移除 loading 回呼中的統計觸發，統一由 viewRender 處理，避免重複和衝突
                // 同時保留 loading 回呼中的 render，這對 dayRender 的重新上色很重要
                loading: function(isLoading) { /* 移除 if (!isLoading) { $('#calendar').fullCalendar('render'); } */ },

                height: 650, // 設定日曆的總高度為 650px，您可以自行調整此數值
                // 如果有指定跳轉日期，則行事曆初始化時顯示該日期
                defaultDate: gotoDate ? moment(gotoDate) : moment(),
                
                // 恢復 dayRender 邏輯，這是最可靠的上色方式
                dayRender: function(date, cell) {
                    var allEvents = $('#calendar').fullCalendar('clientEvents');
                    var momentDate = moment(date);
                    var dateStr = momentDate.format('YYYY-MM-DD');
                    var dayOfWeek = momentDate.day(); // 0=週日, 6=週六

                    var dayType = null;

                    // 修正：找出當天是否落在 's' (休假日) 或 'm' (補班日) 的事件區間內
                    for (var i = 0; i < allEvents.length; i++) {
                        var event = allEvents[i];
                        // 只處理有 day_type 的事件
                        if (event.start && (event.day_type === 's' || event.day_type === 'm')) {
                            var eventStart = moment(event.start).startOf('day');
                            // 如果沒有結束日期，則視為單日事件
                            var eventEnd = event.end ? moment(event.end).startOf('day') : eventStart;

                            // 使用 isBetween 檢查目前 cell 的日期是否在事件的開始與結束之間 (包含頭尾)
                            if (momentDate.isBetween(eventStart, eventEnd, 'day', '[]')) {
                                if (event.day_type === 's') {
                                    dayType = 's';
                                    break; // 找到休假日，優先權最高，直接跳出迴圈
                                } else if (event.day_type === 'm') {
                                    dayType = 'm'; // 找到補班日，繼續檢查是否有更高優先級的休假日
                                }
                            }
                        }
                    }

                    // 規則：
                    // 顏色判斷的優先級：休假日 > 補班日 > 週末
                    // 1. 如果是休假日 (dayType === 's')
                    // 2. 或者是週末 (週六或週日)，且當天不是補班日 (dayType !== 'm')
                    if (dayType === 's' || ((dayOfWeek === 0 || dayOfWeek === 6) && dayType !== 'm')) {
                        cell.css('background-color', '#FFF0F5'); // 淺粉紅色
                    } else {
                        cell.css('background-color', ''); // 確保其他日期是預設背景色
                    }
                },

                // 改為 v3 的 eventRender API
                eventRender: function(event, element) {
                    event._element = element; // 新增：儲存元素參照，供 eventAfterAllRender 使用
                    // --- 根據事件類別設定顏色 ---
                    if (event.color) { element.css('background-color', event.color); }

                    var tooltipParts = []; // 用於儲存浮動視窗的各個部分

                    // --- 處理發生者顯示 (再次修正判斷條件) ---
                    if (event.actors && event.actors.length > 0 && event.actors[0] && event.actors[0].id !== null) {
                        // 判斷是否為全體員工 (發生者人數等於下拉選單總人數，或後端標記為全體)
                        var totalUsers = $('#actors option').length;
                        var isAllUsers = (event.actors.length === totalUsers) || event.all_actors;

                        if (isAllUsers) {
                            var titleElement = element.find('.fc-title, .fc-event-title');
                            titleElement.append(' (全體員工)');
                            tooltipParts.push('發生者：全體員工');
                        } else {
                            var firstActor = event.actors[0];
                            var actorDisplay = '';

                            // 組合要顯示在事件標題後的文字
                            if (firstActor.department_name) {
                                actorDisplay += ' [' + firstActor.department_name + '] ';
                            }
                            actorDisplay += firstActor.name;

                            // 如果只有一位發生者，則不將其加入 tooltipParts，因為已顯示在標題
                            // 只有多位發生者時才將列表加入 tooltipParts
                            if (event.actors.length > 1) {
                                // --- 新增：排序邏輯 ---
                                // 1. 先按部門名稱排序
                                // 2. 如果部門相同，再按職稱順序 (position_sort_order) 排序
                                event.actors.sort(function(a, b) {
                                    var deptA = a.department_name || 'Z'; // 將沒有部門的排在最後
                                    var deptB = b.department_name || 'Z';
                                    if (deptA < deptB) return -1;
                                    if (deptA > deptB) return 1;
                                    return (a.position_sort_order || 999) - (b.position_sort_order || 999);
                                });
                                // 建立懸浮提示窗的內容 (所有發生者)
                                var actorTooltipContent = event.actors.map(function(actor) {
                                    var dept = actor.department_name ? '[' + actor.department_name + '] ' : '';
                                    return dept + actor.name;
                                }).join('<br>'); // 使用 <br> 換行，並啟用 html
                                tooltipParts.push(actorTooltipContent);
                            }

                            if (event.actors.length > 1) {
                                actorDisplay += '...共' + event.actors.length + '位';
                            }

                            // 2. 將組合好的文字附加到事件標題後面
                            var titleElement = element.find('.fc-title, .fc-event-title');
                            titleElement.append(' ' + actorDisplay); // 將發生者資訊附加到標題後面
                        }
                    }

                    // --- 處理備註顯示 ---
                    if (event.remark && event.remark.trim() !== '') {
                        tooltipParts.push('備註：' + event.remark.replace(/\n/g, '<br>'));
                    }

                    // --- 統一綁定 Tooltip ---
                    if (tooltipParts.length > 0) {
                        var finalTooltipContent = tooltipParts.join('<br>'); // 將所有部分用換行符連接
                        element.tooltip({ title: finalTooltipContent, container: 'body', html: true, placement: 'top' });
                    }
                    // --- 新增：處理重複事件的按鈕標籤 ---
                    var eventType = event.event_type; // 從後端取得 event_type
                    var badgeHtml = '';

                    // 改用更美觀的「標籤」樣式取代原本的「按鈕」
                    if (eventType === 'main_recurring') {
                        // 主要事件：改為綠色 (label-success) 標籤，更清晰
                        badgeHtml = '<span class="label label-success" style="margin-right: 5px;">主要</span>';
                    } else if (eventType === 'recurring_instance') {
                        // 重複事件：改為橘黃色 (label-warning) 標籤，更易識別
                        badgeHtml = '<span class="label label-warning" style="margin-right: 5px;">重複</span>';
                    }

                    // 修正：無論是否為全天事件，都先將標籤附加到標題上
                    if (badgeHtml) {
                        element.find('.fc-title, .fc-event-title').prepend(badgeHtml);
                    }

                    // 整日事件保持 FullCalendar 預設顯示，不進行任何操作
                    // 加上標籤後，如果是全天事件就可以結束了
                    if (event.allDay) return;

                    // --- 以下為非全天事件的時間格式處理 ---

                    // 將 event.start/end 字串轉換為 Moment 物件
                    var start = moment(event.start);
                    // 如果沒有結束時間，則將其視為與開始時間相同
                    var end = event.end ? moment(event.end) : start.clone();

                    var timeText;
                    if (start.isSame(end, 'day')) {
                        // 同一天事件：顯示 HH:mm - HH:mm 標題
                        timeText = start.format('HH:mm') + ' - ' + end.format('HH:mm');
                    } else {
                        // 跨日事件：顯示 MM/DD HH:mm - MM/DD HH:mm 標題
                        timeText = start.format('MM/DD HH:mm') + ' - ' + end.format('MM/DD HH:mm');
                    }
                
                    // 更新 DOM：將時間格式加到標題前面 (標籤已在最前面)
                    element.find('.fc-title, .fc-event-title').prepend(timeText + ' ');
                    element.find('.fc-time, .fc-event-time').remove();
                },

                // 當事件被點擊時觸發
                eventClick: function(event) {
                    // 將頁面平滑捲動到表單位置，方便使用者編輯
                    $('html, body').animate({ scrollTop: $('.x_panel').first().offset().top }, 500);

                    // --- 重置/隱藏主事件日期顯示 ---
                    $('#original-start-display').hide().html('');
                    $('#original-end-display').hide().html('');
                    // 同時清空 original_event_end
                    // 我們仍然需要這些隱藏欄位來判斷是否為重複事件
                    $('#original_event_end').val('');
                    $('#original_event_date').val(''); // 清空隱藏欄位

                    // --- 新增：處理編輯表單中的「主要/重複」按鈕 ---
                    var eventType = event.event_type;
                    var badgeContainer = $('#event-type-badge-container');
                    var badgeHtml = '';

                    // 根據事件類型，建立並插入按鈕
                    if (eventType === 'main_recurring') {
                        badgeHtml = '<span class="label label-success">主要</span>';
                        badgeContainer.html(badgeHtml).show();
                    } else if (eventType === 'recurring_instance') {
                        badgeContainer.html(badgeHtml).show();
                    } else {
                        // 如果是一般事件，清空並隱藏按鈕容器
                        badgeContainer.html('').hide();
                    }
                    // --- 新增結束 ---


                    // --- iCheck 狀態更新：只使用 iCheck 方法 ---
                    if (event.allDay) {
                        $('#schdule_all_day').iCheck('check');
                    } else {
                        $('#schdule_all_day').iCheck('uncheck');
                    }
                    // 移除多餘的 .prop() 與 .trigger()，避免衝突

                    // --- 新增：處理發生者 ---
                    $('#recurrence_independent').prop('checked', false); // 重置獨立事件選項
                    
                    // 判斷是否為全體成員
                    // 條件：1. 後端回傳 all_actors 為 true  OR  2. 發生者人數等於下拉選單總人數
                    var totalUsers = $('#actors option').length;
                    var currentActorsCount = event.actors ? event.actors.length : 0;

                    if (event.all_actors || (totalUsers > 0 && currentActorsCount === totalUsers)) {
                        $('#actor_all').prop('checked', true);
                        $actorsSelect.val(null).trigger('change').prop('disabled', true);
                    } else {
                        $('#actor_all').prop('checked', false);
                        // 1. 從 event.actors 陣列中提取所有 user id
                        var actorIds = event.actors ? event.actors.map(function(actor) { return actor.id; }) : [];
                        // 2. 將 id 陣列設定給 select2，並觸發 change 事件更新 UI
                        $actorsSelect.val(actorIds).trigger('change').prop('disabled', false);
                    }

                    // --- 新增：處理事件類別 ---
                    var categoryId = event.category_id || '';
                    // 將 event 物件暫存起來，以便 change 事件處理函式可以存取 leave_type_id
                    $('#event_category').data('currentEvent', event);
                    // 修正：設定值後需觸發 change 事件，Select2 才會更新 UI
                    $('#event_category').val(categoryId).trigger('change');

                    // --- AJAX 請求以獲取並設定廣播對象 ---
                    $.ajax({
                        url: '../../src/store/get_event_targets.php',
                        type: 'GET',
                        data: { event_id: event.id },
                        dataType: 'json',
                        success: function(response) {
                            if (response.success) {
                                var targets = response.targets;
                                if (targets.length === 0 || targets.some(t => t.target_type === 'all')) {
                                    // 如果沒有目標或目標是 'all'，則勾選「全體」
                                    $('#target_all').iCheck('check');
                                    $targetsSelect.val(null).trigger('change').prop('disabled', true);
                                } else {
                                    // 否則，取消勾選「全體」並填入目標
                                    $('#target_all').iCheck('uncheck');
                                    var targetValues = targets.map(function(target) {
                                        if (target.target_type === 'department') {
                                            return 'dept-' + target.target_id;
                                        } else if (target.target_type === 'user') {
                                            return 'user-' + target.target_id;
                                        }
                                    });
                                    $targetsSelect.val(targetValues).trigger('change').prop('disabled', false);
                                }
                            } else {
                                // 請求失敗或沒有資料時，恢復預設（全體廣播）
                                $('#target_all').iCheck('check');
                                $targetsSelect.val(null).trigger('change').prop('disabled', true);
                            }
                        }
                    });
                    
                    $('#userid').val(event.id); 
                    
                    $('#schdule_title').val(event.title); // 填入標題
                    $('#schdule_remark').val(event.remark || ''); // 填入備註

                    // --- 新增：日期交換邏輯 ---
                    var startDate, startHour, startMinute;
                    var endDate, endHour, endMinute;

                    if (eventType === 'recurring_instance') {
                        // 編輯重複事件：將主事件日期填入可編輯欄位
                        var mainStart = moment(event.original_start);
                        var mainEnd = event.original_end ? moment(event.original_end) : mainStart.clone();

                        startDate = mainStart.format("YYYY-MM-DD");
                        startHour = mainStart.format("HH");
                        startMinute = mainStart.format("mm");

                        endDate = mainEnd.format("YYYY-MM-DD");
                        endHour = mainEnd.format("HH");
                        endMinute = mainEnd.format("mm");

                        // 將重複事件實例的日期顯示在右側文字區
                        var instanceStart = moment(event.start);
                        var instanceEnd = event.end ? moment(event.end) : instanceStart.clone();
                        var format = event.allDay ? 'YYYY-MM-DD' : 'YYYY-MM-DD HH:mm';
                        $('#original-start-display').html('(此重複: ' + instanceStart.format(format) + ')').show();
                        $('#original-end-display').html('(此重複: ' + instanceEnd.format(format) + ')').show();

                        // 仍然填寫隱藏欄位，以便後端識別
                        $('#original_event_date').val(event.original_start);
                        $('#original_event_end').val(event.original_end);

                    } else {
                        // 編輯主事件或一般事件：正常填入事件本身的日期
                        startDate = $.fullCalendar.formatDate(event.start, "yyyy-MM-dd");
                        startHour = $.fullCalendar.formatDate(event.start, "HH");
                        startMinute = $.fullCalendar.formatDate(event.start, "mm");
                    }

                    // 觸發請假人員查詢 (根據開始日期)
                    fetchLeaveUsers(startDate);

                    $('#datepicker').val(startDate);
                    $('#start_hour').val(startHour);
                    $('#start_minute').val(startMinute);

                    // 處理結束日期與時間
                    if (event.end && eventType !== 'recurring_instance') {
                        if (event.allDay) {
                            // 對於全日事件，FullCalendar 的結束日期是 exclusive (不包含) 的，所以後端會加一天以便正確顯示。
                            // 修正：編輯時需將結束日期減一天，以顯示為 inclusive (包含) 的日期
                            var startMoment = moment(event.start);
                            var endMoment = moment(event.end);
                            
                            var durationDays = endMoment.diff(startMoment, 'days');
                            
                            // 修正：依據需求，多天事件直接加一天，單日事件維持減一天
                            if (durationDays = 2) {
                            } else {
                                endMoment.subtract(1, 'days');
                            }
                            endDate = endMoment.format("YYYY-MM-DD");

                            // 全日事件沒有時間，設為 '00'
                            endHour = '00';
                            endMinute = '00';
                        } else {
                            endDate = $.fullCalendar.formatDate(event.end, "yyyy-MM-dd");
                            endHour = $.fullCalendar.formatDate(event.end, "HH");
                            endMinute = $.fullCalendar.formatDate(event.end, "mm");
                        }
                    } else if (!event.end) {
                        endDate = startDate;
                        endHour = startHour;
                        endMinute = startMinute;
                    }

                    $('#datepicker_end').val(endDate);
                    $('#end_hour').val(endHour);
                    $('#end_minute').val(endMinute);

                    // 改變按鈕狀態
                    $('#send').text('更新');
                    $('#copyBtn').show();
                    $('#deleteBtn').show();
                    $('#cancelBtn').show();

                    var deleteUrl = '../../src/store/_deleteSchdule.php?delid=' + event.id + '&id=<?= $_GET['id'] ?>';
                    $('#deleteBtn').attr('href', deleteUrl);

                    // --- 修改：讀取後端傳來的重複規則 ---
                    var recurrenceType = event.recurrence_type || '';
                    var recurrenceCount = event.recurrence_count || 1;
                    $('#recurrence_type').val(recurrenceType).trigger('change'); // 觸發 change 以顯示次數輸入框
                    if (recurrenceType) {
                        $('#recurrence_count').val(recurrenceCount);
                    }
                }
            });

            // --- 新增：自動日期按鈕邏輯 ---
            // 初始化 Tooltip
            $('#auto_date_btn').tooltip({
                html: true,
                title: "<div style='text-align:left'>自動日期規則：<br>1. 無日期(或都有日期)時將自動設定為今日<br>2. 若只有一方有日期，則自動設定為相同日期</div>",
                placement: "top"
            });

            $('#auto_date_btn').on('click', function() {
                var startDate = $('#datepicker').val();
                var endDate = $('#datepicker_end').val();
                var today = moment().format('YYYY-MM-DD');

                if ((!startDate && !endDate) || (startDate && endDate)) {
                    $('#datepicker').val(today);
                    $('#datepicker_end').val(today);
                } else if (startDate && !endDate) {
                    $('#datepicker_end').val(startDate);
                } else if (!startDate && endDate) {
                    $('#datepicker').val(endDate);
                }
                // 觸發 change 事件以更新相關邏輯 (如請假人員查詢)
                $('#datepicker').trigger('change');
            });

            // 當「取消」按鈕被點擊時，清空表單並還原按鈕狀態
            $('#cancelBtn').on('click', function(e) {
                e.preventDefault();

                // --- 新增：取消時隱藏主事件日期顯示 ---
                $('#original-start-display').hide().html('');
                $('#original-end-display').hide().html('');

                // 清空並隱藏標題旁邊的按鈕
                $('#event-type-badge-container').html('').hide();

                $('#userid').val('');
                // 當取消時，也使用 iCheck 的方法來更新 UI
                $('#schdule_all_day').iCheck('uncheck');
                $('#schdule_title').val('');
                $('#datepicker').val('');
                // --- 新增：重置假別相關 UI ---
                $('#leave_type_container').hide();
                $('#leave_type_select').val('');
                $('#event_category_container').css('flex-grow', '1').css('width', '');
                $('#schdule_title').prop('readonly', false).css('background-color', ''); // 修正：移除 readonly 屬性
                $('#event_category').val('').trigger('change'); // 清空事件類別並觸發 select2 更新
                // 重置發生者
                $actorsSelect.val(null).trigger('change').prop('disabled', false);
                $('#actor_all').prop('checked', false);
                // 清空廣播對象並設為預設(全體)
                $('#targets').val(null).trigger('change').prop('disabled', true);
                $('#target_all').prop('checked', true); // 修正：改為使用 prop()

                $('#datepicker_end').val('');
                $('#schdule_remark').val(''); // 新增：清空備註欄位
                // 清空重複事件欄位
                $('#recurrence_type').val('').trigger('change');
                $('#recurrence_independent').prop('checked', false); // 重置獨立事件選項

                $('#send').text('送出');
                $('#deleteBtn').hide();
                $('#copyBtn').hide();
                // $(this).hide(); // 註解掉這行，讓取消按鈕在新增模式下也保持可見
            });

            // 頁面載入時，如果事件類別有值，手動觸發一次 change 事件
            // 這樣才能正確顯示/隱藏假別下拉選單
            if ($('#event_category').val()) {
                $('#event_category').trigger('change');
            }

            // 當「複製事件」按鈕被點擊時
            $('#copyBtn').on('click', function(e) {
                e.preventDefault();

                // 清空事件 ID，這樣送出時就會被當作新事件
                $('#userid').val('');

                // 還原按鈕狀態為「新增」模式
                $('#send').text('送出');
                $('#deleteBtn').hide();
                $(this).hide(); // 隱藏複製按鈕自己
            });

            // 當「全天事件」勾選框狀態改變時
            $('#schdule_all_day').on('change', function() {
                if (this.checked) {
                    $('.time-select-container').hide();
                } else {
                    $('.time-select-container').show();
                }
            });

            // 當「重複類型」下拉選單改變時
            $('#recurrence_type').on('change', function() {
                if ($(this).val()) {
                    $('#recurrence_count_container').css('display', 'flex');
                    $('#recurrence_count').val(1); // 預設為1
                } else {
                    $('#recurrence_count_container').hide();
                }
            });
            
            // --- 處理獨立重複事件 (攔截表單提交) ---
            $('#addEventForm').on('submit', function(e) {
                // 新增：若勾選全體成員，則自動選取所有發生者並啟用欄位，確保後端能接收到資料並更新 evenement_actor
                if ($('#actor_all').is(':checked')) {
                    var $actors = $('#actors');
                    $actors.prop('disabled', false);
                    var allOptions = $actors.find('option').map(function() { return $(this).val(); }).get();
                    $actors.val(allOptions);
                }

                var $form = $(this);
                var isIndependent = $('#recurrence_independent').is(':checked');
                var recurrenceType = $('#recurrence_type').val();
                var recurrenceCount = parseInt($('#recurrence_count').val(), 10);

                // 只有在勾選獨立事件且有設定重複條件時才介入
                if (isIndependent && recurrenceType && recurrenceCount > 0) {
                    e.preventDefault(); // 阻止預設提交

                    if (!confirm('您確定要建立 ' + (recurrenceCount + 1) + ' 筆獨立的事件嗎？\n這些事件將不會互相連動，需個別編輯。')) {
                        return;
                    }

                    NProgress.start();
                    var $submitBtn = $('#send');
                    $submitBtn.prop('disabled', true).text('處理中...');

                    var originalDate = $('#datepicker').val(); // YYYY-MM-DD
                    var originalEndDate = $('#datepicker_end').val(); // YYYY-MM-DD
                    
                    // 取得表單資料並移除重複設定 (讓後端視為單一事件)
                    var formData = $form.serializeArray();
                    
                    // 修正：手動加入 submit 按鈕的參數，確保後端 isset($_POST['newSchdule']) 驗證通過
                    formData.push({name: 'newSchdule', value: '1'});

                    formData = formData.filter(function(item) {
                        return item.name !== 'recurrence_type' && item.name !== 'recurrence_count' && item.name !== 'recurrence_independent';
                    });

                    var promises = [];
                    var unitMap = { 'daily': 'days', 'weekly': 'weeks', 'monthly': 'months', 'yearly': 'years' };
                    var unit = unitMap[recurrenceType] || 'days';

                    // 迴圈建立事件：i=0 為原始事件，i>0 為重複事件
                    for (var i = 0; i <= recurrenceCount; i++) {
                        var p = new Promise(function(resolve, reject) {
                            var newStart = moment(originalDate).add(i, unit).format('YYYY-MM-DD');
                            var newEnd = moment(originalEndDate).add(i, unit).format('YYYY-MM-DD');

                            // 複製並修改資料
                            var currentData = $.extend(true, [], formData);
                            
                            // 更新日期
                            updateFormData(currentData, 'schdule_start', newStart);
                            updateFormData(currentData, 'schdule_end', newEnd);

                            // 如果是重複的複本 (i > 0)，清除 userid 以便建立新事件
                            if (i > 0) {
                                updateFormData(currentData, 'userid', '');
                            }

                            $.ajax({
                                url: '../../src/store/_events_setting.php',
                                type: 'POST',
                                data: currentData,
                                complete: function() { resolve(); } // 無論成功失敗都繼續
                            });
                        });
                        promises.push(p);
                    }

                    Promise.all(promises).then(function() {
                        NProgress.done();
                        alert('已成功建立 ' + (recurrenceCount + 1) + ' 筆獨立事件。');
                        location.reload();
                    });
                }
            });

            function updateFormData(dataArray, name, value) {
                var found = false;
                for (var i = 0; i < dataArray.length; i++) {
                    if (dataArray[i].name === name) {
                        dataArray[i].value = value;
                        found = true;
                        break;
                    }
                }
                if (!found) {
                    dataArray.push({name: name, value: value});
                }
            }

            // 填充時間下拉選單
            function populateTimeSelects(hourSelect, minuteSelect) {
                for (var i = 0; i < 24; i++) {
                    var hour = ('0' + i).slice(-2);
                    hourSelect.append($('<option>', { value: hour, text: hour }));
                }
                for (var i = 0; i < 60; i += 10) {
                    var minute = ('0' + i).slice(-2);
                    minuteSelect.append($('<option>', { value: minute, text: minute }));
                }
            }

            populateTimeSelects($('#start_hour'), $('#start_minute'));
            populateTimeSelects($('#end_hour'), $('#end_minute'));

            // 從 data-* 屬性設定從列表編輯時帶入的時間
            var startContainer = $('#datepicker').parent();
            var endContainer = $('#datepicker_end').parent();
            $('#start_hour').val(startContainer.data('start-hour'));
            $('#start_minute').val(startContainer.data('start-minute'));
            $('#end_hour').val(endContainer.data('end-hour'));
            $('#end_minute').val(endContainer.data('end-minute'));

            // 如果是從列表編輯，也要更新按鈕狀態
            if ($('#userid').val() !== '') { $('#send').text('更新'); }
            
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
            $("#datepicker").datepicker({
                changeMonth: true,
                changeYear: true,
                showMonthAfterYear: true
            });
            
            // 初始化時載入今天的請假人員
            fetchLeaveUsers(moment().format('YYYY-MM-DD'));

            // 當日期改變時，重新查詢請假人員
            $("#datepicker").on('change', function() {
                fetchLeaveUsers($(this).val());
            });

            $("#datepicker_end").datepicker({
                changeMonth: true,
                changeYear: true,
                showMonthAfterYear: true
            });

            // --- 事件類別管理 Modal ---

            // 點擊 "+" 按鈕時，打開 Modal 並載入類別
            $('#manage_categories_btn').on('click', function() {
                $('#categoryManagerModal').modal('show');
                loadCategories();
            });

            // 從資料庫載入類別列表並顯示在 Modal 中
            function loadCategories() {
                $.ajax({
                    url: '../../src/store/get_event_categories.php',
                    type: 'GET',
                    dataType: 'json',
                    success: function(categories) {
                        var categoryList = $('#categoryList');
                        categoryList.empty();
                        categories.forEach(function(cat) {
                            var item = `
                                <div class="list-group-item">
                                    <span class="color-box" style="background-color: ${cat.color};"></span>
                                    <strong>${cat.category_name}</strong>
                                    ${cat.day_type === 's' ? '<span class="label label-danger" style="margin-left: 5px; font-size: 90%;">休假日</span>' : ''}
                                    ${cat.day_type === 'm' ? '<span class="label label-success" style="margin-left: 5px; font-size: 90%;">補班/調班日</span>' : ''}
                                    <small class="text-muted" style="margin-left: 10px;">${cat.description || ''}</small>
                                    <div class="pull-right">
                                        <button class="btn btn-warning btn-xs btn-edit-category" 
                                            data-id="${cat.id}" data-name="${cat.category_name}" 
                                            data-color="${cat.color}" 
                                            data-description="${cat.description || ''}"
                                            data-day_type="${cat.day_type || ''}"
                                            >編輯</button>
                                        <button class="btn btn-danger btn-xs btn-delete-category" data-id="${cat.id}">刪除</button>
                                    </div>
                                </div>`;
                            categoryList.append(item);
                        });
                    }
                });
            }

            // 處理新增/編輯類別的表單提交
            $('#categoryForm').on('submit', function(e) {
                e.preventDefault();
                var formData = $(this).serialize();
                $.ajax({
                    url: '../../src/store/save_event_category.php',
                    type: 'POST',
                    data: formData,
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            loadCategories(); // 重新載入 Modal 列表
                            updateMainCategoryDropdown(); // 更新主下拉選單
                            resetCategoryForm(); // 清空表單
                            $('#categoryManagerModal').modal('hide'); // 新增：成功後關閉 Modal
                            // 新增：重新整理行事曆事件，讓顏色即時更新
                            $('#calendar').fullCalendar('refetchEvents');
                        }
                    },
                    error: function(jqXHR, textStatus, errorThrown) {
                        // 新增：錯誤處理，以便在後端出錯時提示使用者
                        alert('儲存類別時發生錯誤，請檢查後端程式或網路連線。\n錯誤訊息: ' + textStatus);
                    }
                });
            });

            // 點擊「編輯」按鈕
            $('#categoryList').on('click', '.btn-edit-category', function() {
                var id = $(this).data('id');
                var name = $(this).data('name');
                var color = $(this).data('color');
                var description = $(this).data('description');
                var day_type = String($(this).data('day_type') || ''); // 確保取到的是字串

                $('#edit_category_id').val(id);
                $('#category_name').val(name);
                $('#category_color').val(color);
                $('#category_description').val(description);
                $('#day_type').val(day_type);
                $('#cancelEditCategory').show();
            });

            // 點擊「刪除」按鈕
            $(document).on('click', '.btn-delete-category', function() {
                if (confirm('您確定要刪除這個類別嗎？')) {
                    var id = $(this).data('id');
                    $.ajax({
                        url: '../../src/store/delete_event_category.php',
                        type: 'POST',
                        data: { id: id },
                        dataType: 'json',
                        success: function(response) {
                            if (response.success) {
                                loadCategories(); // 重新載入 Modal 列表
                                updateMainCategoryDropdown(); // 更新主下拉選單
                                // 新增：重新整理行事曆事件，讓被刪除類別的事件顏色恢復預設
                                $('#calendar').fullCalendar('refetchEvents');
                            }
                        }
                    });
                }
            });

            // 點擊「取消編輯」按鈕
            $('#cancelEditCategory').on('click', function() {
                resetCategoryForm();
            });

            // 重置類別表單的函式
            function resetCategoryForm() {
                $('#categoryForm')[0].reset();
                $('#edit_category_id').val('');
                $('#category_color').val('#3a87ad'); // 恢復預設顏色
                $('#day_type').val(''); // 恢復日期類型預設值
                $('#cancelEditCategory').hide();
            }

            // 當 Modal 關閉時，也重置表單
            $('#categoryManagerModal').on('hidden.bs.modal', function () {
                resetCategoryForm();
            });

            // 更新主畫面的事件類別下拉選單
            function updateMainCategoryDropdown() {
                $.ajax({
                    url: '../../src/store/get_event_categories.php',
                    type: 'GET',
                    dataType: 'json',
                    success: function(categories) {
                        var $select = $('#event_category');
                        var currentVal = $select.val(); // 記住目前選中的值
                        $select.empty().append('<option value="">請選擇類別</option>'); // 清空並加上預設選項
                        
                        categories.forEach(function(cat) {
                            var option = new Option(cat.category_name, cat.id, false, false);
                            $(option).attr('data-color', cat.color);
                            $select.append(option);
                        });

                        $select.val(currentVal).trigger('change.select2'); // 恢復之前選中的值並觸發 select2 更新
                    }
                });
            }

            // --- 新增：處理年份與月份快速切換 ---
            function setupCalendarControls() {
                var $yearSelector = $('#calendar-year-selector');
                var currentYear = new Date().getFullYear();

                // 填充年份下拉選單 (前後 10 年)
                for (var i = currentYear - 10; i <= currentYear + 10; i++) {
                    $yearSelector.append($('<option>', { value: i, text: i + '年' }));
                }
                // 設定當前年份為預設值
                $yearSelector.val(currentYear);

                // 月份按鈕點擊事件
                $('.btn-month').on('click', function() {
                    var selectedYear = $yearSelector.val();
                    var selectedMonth = parseInt($(this).data('month'), 10);

                    var calendar = $('#calendar');
                    var currentDate = calendar.fullCalendar('getDate'); // 取得行事曆當前的日期 (Moment 物件)

                    // 建立目標日期物件 (只考慮年月)
                    var targetDate = moment({ year: selectedYear, month: selectedMonth });

                    // 計算目標與當前日期的月份差異
                    var monthDiff = targetDate.diff(currentDate, 'months');

                    if (monthDiff > 0) {
                        // 如果差異為正數，代表要往未來移動，執行 'next'
                        for (var i = 0; i < monthDiff; i++) { calendar.fullCalendar('next'); }
                    } else if (monthDiff < 0) {
                        // 如果差異為負數，代表要往過去移動，執行 'prev'
                        for (var i = 0; i < Math.abs(monthDiff); i++) { calendar.fullCalendar('prev'); }
                    }
                });

            }
            // 初始化快速切換功能
            setupCalendarControls();

            // --- 事件類別統計 ---
            // 注意：工作日計算已移至 eventAfterAllRender
            function updateEventStats(view) {
                var calendar = $('#calendar');
                var currentMoment = moment(calendar.fullCalendar('getDate'));
                var currentYear = currentMoment.year();
                var currentMonthStr = currentMoment.format('YYYY-MM');
                var prevMonthMoment = currentMoment.clone().subtract(1, 'months');
                var prevMonthStr = prevMonthMoment.format('YYYY-MM');

                // 決定抓取範圍：包含今年以及上個月 (若上個月跨年)
                var startFetch = moment({year: currentYear, month: 0, day: 1});
                if (prevMonthMoment.year() < currentYear) {
                    startFetch = prevMonthMoment.clone().startOf('month');
                }
                var endFetch = moment({year: currentYear, month: 11, day: 31});

                // 改為呼叫 events.php 取得符合權限的事件，並在前端計算統計
                $.ajax({
                    url: '../../src/store/events.php',
                    type: 'GET',
                    data: {
                        start: startFetch.format('YYYY-MM-DD'),
                        end: endFetch.format('YYYY-MM-DD')
                    },
                    dataType: 'json',
                    success: function(events) {
                        var currentMonthStats = {};
                        var prevMonthStats = {};
                        var yearStats = {};

                        events.forEach(function(event) {
                            var eventStart = moment(event.start);
                            var eventMonthStr = eventStart.format('YYYY-MM');
                            var eventYear = eventStart.year();
                            var catId = event.category_id;

                            if (!catId || !categoriesLookup[catId]) return;

                            // 年度統計 (本年度)
                            if (eventYear === currentYear) {
                                yearStats[catId] = (yearStats[catId] || 0) + 1;
                            }

                            // 當月統計
                            if (eventMonthStr === currentMonthStr) {
                                currentMonthStats[catId] = (currentMonthStats[catId] || 0) + 1;
                            }

                            // 上月統計
                            if (eventMonthStr === prevMonthStr) {
                                prevMonthStats[catId] = (prevMonthStats[catId] || 0) + 1;
                            }
                        });

                        renderStatsTable(currentMonthStats, prevMonthStats, '#event-stats-table tbody', true);
                        renderStatsTable(yearStats, null, '#year-event-stats-table tbody', false);
                    }
                });
            }

            function renderStatsTable(stats, prevStats, selector, showDiff) {
                var tbody = $(selector);
                tbody.empty();
                var hasData = false;

                for (var catId in categoriesLookup) {
                    if (stats[catId] > 0) {
                        hasData = true;
                        var catData = categoriesLookup[catId];
                        var count = stats[catId];
                        var diffHtml = '';

                        if (showDiff) {
                            var prevCount = prevStats[catId] || 0;
                            var diff = count - prevCount;
                            if (diff > 0) diffHtml = '<span class="text-success"><i class="fa fa-arrow-up"></i> ' + diff + '</span>';
                            else if (diff < 0) diffHtml = '<span class="text-danger"><i class="fa fa-arrow-down"></i> ' + Math.abs(diff) + '</span>';
                            else diffHtml = '<span class="text-muted">-</span>';
                        }

                        var row = '<tr>' +
                            '<td><span class="color-box" style="background-color: ' + catData.color + '; border: 1px solid #ccc; display: inline-block; width: 14px; height: 14px;"></span></td>' +
                            '<td>' + catData.name + '</td>' +
                            '<td class="text-right">' + count + '</td>' +
                            (showDiff ? '<td class="text-right">' + diffHtml + '</td>' : '') +
                            '</tr>';
                        tbody.append(row);
                    }
                }

                if (!hasData) {
                    tbody.append('<tr><td colspan="' + (showDiff ? 4 : 3) + '" class="text-center">' + (showDiff ? '本月' : '本年度') + '尚無事件</td></tr>');
                }
            }
        });
    </script>
</body>

</html>