<?php
session_start();
if (!isset($_SESSION['userName'])) {
    header("Location:../../index.php");
    exit;
}

include '../../src/common/DBConnection.php';
include '../../src/store/_setting.php';
include '../../src/common/_config.php';

$conn = new DBConnection();
$pdo = $conn->getPDO();

// --- 1. 處理篩選參數 ---
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01'); // 預設本月一號
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d'); // 預設今天
$filter_machine_type = isset($_GET['machine_type_id']) ? $_GET['machine_type_id'] : '';
$filter_machine = isset($_GET['machine_id']) ? $_GET['machine_id'] : '';
$filter_user = isset($_GET['user_id']) ? $_GET['user_id'] : '';

// 計算選取天數 (用於稼動率分母)
$days_diff = (strtotime($end_date) - strtotime($start_date)) / 86400 + 1;
$capacity_hours_per_machine = $days_diff * 24; // 假設每天 24 小時工作時間

// --- 2. 獲取篩選選單資料 ---
// 機台種類
$machine_types = $pdo->query("SELECT process_type_id AS machine_type_id, process_type AS machine_type FROM process_type ORDER BY process_type_id")->fetchAll(PDO::FETCH_ASSOC);
// 機台列表
$machines = $pdo->query("SELECT machine_id, machine, machine_type_id FROM machine_list WHERE (state IS NULL OR state != '1') ORDER BY machine")->fetchAll(PDO::FETCH_ASSOC);
// 人員列表 (有報工紀錄的人員)
$users = $pdo->query("SELECT DISTINCT u.id, u.user_cname FROM user u 
    JOIN pm_process_daily_report r ON (u.id = r.setup_user_id OR u.id = r.production_user_id) 
    ORDER BY u.user_cname")->fetchAll(PDO::FETCH_ASSOC);

// --- 3. 查詢報工數據 ---
$sql = "
    SELECT 
        r.report_id,
        r.report_date,
        r.machine_id,
        m.machine,
        r.setup_user_id,
        u_s.user_cname as setup_user_name,
        r.production_user_id,
        u_p.user_cname as prod_user_name,
        r.produced_qty,
        r.is_finished,
        (SELECT COALESCE(SUM(ng_qty),0) FROM pm_process_daily_ng WHERE report_id = r.report_id) as ng_qty,
        r.setup_start_time,
        r.setup_end_time,
        r.production_start_time,
        r.production_end_time,
        TIMESTAMPDIFF(SECOND, r.setup_start_time, r.setup_end_time) as setup_seconds,
        TIMESTAMPDIFF(SECOND, r.production_start_time, r.production_end_time) as prod_seconds,
        bi.bom,
        b.d_id,
        b.Client_Name,
        pn.ProcessName
    FROM pm_process_daily_report r
    LEFT JOIN machine_list m ON r.machine_id = m.machine_id
    LEFT JOIN user u_s ON r.setup_user_id = u_s.id
    LEFT JOIN user u_p ON r.production_user_id = u_p.id
    LEFT JOIN bom_ing bi ON r.bom_ing_fid = bi.bom_ing_fid
    LEFT JOIN bom b ON bi.bom = b.bom
    LEFT JOIN process_no pn ON r.process_no = pn.ProcessNo
    WHERE r.report_date BETWEEN :start AND :end
";

$params = [':start' => $start_date, ':end' => $end_date];

if ($filter_machine) {
    $sql .= " AND r.machine_id = :mid";
    $params[':mid'] = $filter_machine;
} elseif ($filter_machine_type) {
    $sql .= " AND m.machine_type_id = :mtid";
    $params[':mtid'] = $filter_machine_type;
}
if ($filter_user) {
    $sql .= " AND (r.setup_user_id = :uid OR r.production_user_id = :uid)";
    $params[':uid'] = $filter_user;
}

$sql .= " ORDER BY r.report_date DESC, r.report_id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- 4. 數據處理與統計 ---
$machine_stats = [];
$user_stats = [];
$user_detail_map = []; // 詳細人員統計
$timeline_series_setup = []; // 時間軸-架機
$timeline_series_prod = []; // 時間軸-生產
$unique_users_for_timeline = []; // 時間軸-人員清單

$total_prod_qty = 0;
$total_ng_qty = 0;
$total_work_seconds = 0;

foreach ($rows as $row) {
    $m_name = $row['machine'] ?: '未指定機台';
    $setup_sec = max(0, (int)$row['setup_seconds']);
    $prod_sec = max(0, (int)$row['prod_seconds']);
    $total_sec = $setup_sec + $prod_sec;
    $qty = (int)$row['produced_qty'];
    $ng = (int)$row['ng_qty'];

    // 全局統計
    $total_prod_qty += $qty;
    $total_ng_qty += $ng;
    $total_work_seconds += $total_sec;

    // 機台統計
    if (!isset($machine_stats[$m_name])) {
        $machine_stats[$m_name] = ['setup' => 0, 'prod' => 0, 'total' => 0, 'qty' => 0];
    }
    $machine_stats[$m_name]['setup'] += $setup_sec;
    $machine_stats[$m_name]['prod'] += $prod_sec;
    $machine_stats[$m_name]['total'] += $total_sec;
    $machine_stats[$m_name]['qty'] += $qty;

    // 人員統計 (生產人員)
    if ($row['prod_user_name']) {
        $u_name = $row['prod_user_name'];
        $unique_users_for_timeline[$u_name] = 1;
        
        if (!isset($user_stats[$u_name])) {
            $user_stats[$u_name] = ['hours' => 0, 'qty' => 0, 'ng' => 0];
        }
        $user_stats[$u_name]['hours'] += ($prod_sec / 3600); // 轉為小時
        $user_stats[$u_name]['qty'] += $qty;
        $user_stats[$u_name]['ng'] += $ng;
        
        // 詳細統計
        if (!isset($user_detail_map[$u_name])) {
            $user_detail_map[$u_name] = ['name' => $u_name, 'setup_sec' => 0, 'prod_sec' => 0, 'qty' => 0, 'ng' => 0];
        }
        $user_detail_map[$u_name]['prod_sec'] += $prod_sec;
        $user_detail_map[$u_name]['qty'] += $qty;
        $user_detail_map[$u_name]['ng'] += $ng;
    }
    // 人員統計 (架機人員 - 僅計時)
    if ($row['setup_user_name']) {
        $u_name = $row['setup_user_name'];
        $unique_users_for_timeline[$u_name] = 1;
        
        if (!isset($user_stats[$u_name])) {
            $user_stats[$u_name] = ['hours' => 0, 'qty' => 0, 'ng' => 0];
        }
        $user_stats[$u_name]['hours'] += ($setup_sec / 3600);
        
        // 詳細統計
        if (!isset($user_detail_map[$u_name])) {
            $user_detail_map[$u_name] = ['name' => $u_name, 'setup_sec' => 0, 'prod_sec' => 0, 'qty' => 0, 'ng' => 0];
        }
        $user_detail_map[$u_name]['setup_sec'] += $setup_sec;
    }
}

// 準備時間軸圖表數據
$chart_timeline_users = array_keys($unique_users_for_timeline);
sort($chart_timeline_users);
$timeline_height = max(600, count($chart_timeline_users) * 60); // 動態高度：每人至少 60px

foreach ($rows as $row) {
    // Setup
    if ($row['setup_user_name'] && $row['setup_start_time'] && $row['setup_end_time']) {
        $idx = array_search($row['setup_user_name'], $chart_timeline_users);
        if ($idx !== false) {
            $timeline_series_setup[] = [
                'x' => $idx,
                'low' => strtotime($row['setup_start_time']) * 1000, // JS time (ms)
                'high' => strtotime($row['setup_end_time']) * 1000,
                'bom' => $row['bom'],
                'machine' => $row['machine']
            ];
        }
    }
    // Prod
    if ($row['prod_user_name'] && $row['production_start_time'] && $row['production_end_time']) {
        $idx = array_search($row['prod_user_name'], $chart_timeline_users);
        if ($idx !== false) {
            $timeline_series_prod[] = [
                'x' => $idx,
                'low' => strtotime($row['production_start_time']) * 1000,
                'high' => strtotime($row['production_end_time']) * 1000,
                'bom' => $row['bom'],
                'machine' => $row['machine']
            ];
        }
    }
}

// --- 修正 Highcharts Error #15: Data must be sorted by x ---
usort($timeline_series_setup, function($a, $b) {
    if ($a['x'] == $b['x']) return $a['low'] <=> $b['low'];
    return $a['x'] <=> $b['x'];
});
usort($timeline_series_prod, function($a, $b) {
    if ($a['x'] == $b['x']) return $a['low'] <=> $b['low'];
    return $a['x'] <=> $b['x'];
});

// 準備加班時段背景 (PlotBands)
$plot_bands = [];
$curr_ts = strtotime($start_date);
$end_ts = strtotime($end_date . ' 23:59:59');

while ($curr_ts <= $end_ts) {
    $y = date('Y', $curr_ts);
    $m = date('m', $curr_ts);
    $d = date('d', $curr_ts);
    
    // 早班 OT: 17:00 - 20:00
    $plot_bands[] = [
        'from' => mktime(17, 0, 0, $m, $d, $y) * 1000,
        'to' => mktime(20, 0, 0, $m, $d, $y) * 1000,
        'color' => 'rgba(255, 255, 0, 0.1)', // 淡黃色
        'label' => ['text' => '早班OT', 'style' => ['color'=>'#ccc', 'fontSize'=>'10px'], 'align'=>'center']
    ];
    
    // 晚班 OT: 05:00 - 08:00
    $plot_bands[] = [
        'from' => mktime(5, 0, 0, $m, $d, $y) * 1000,
        'to' => mktime(8, 0, 0, $m, $d, $y) * 1000,
        'color' => 'rgba(255, 255, 0, 0.1)',
        'label' => ['text' => '晚班OT', 'style' => ['color'=>'#ccc', 'fontSize'=>'10px'], 'align'=>'center']
    ];
    
    $curr_ts += 86400; // Next day
}

// 準備圖表數據 - 機台
$chart_machine_cats = [];
$chart_machine_prod = [];
$chart_machine_setup = [];
$chart_machine_util = []; // 稼動率

arsort($machine_stats); // 依總工時排序
foreach ($machine_stats as $name => $stat) {
    $chart_machine_cats[] = $name;
    $prod_h = round($stat['prod'] / 3600, 1);
    $setup_h = round($stat['setup'] / 3600, 1);
    $chart_machine_prod[] = $prod_h;
    $chart_machine_setup[] = $setup_h;
    // 稼動率 = 生產工時 / (天數 * 20)
    $util = ($capacity_hours_per_machine > 0) ? round(($prod_h / $capacity_hours_per_machine) * 100, 1) : 0;
    $chart_machine_util[] = $util;
}

// 準備圖表數據 - 人員效率 (改為長條圖: 生產工時 vs 架機工時)
$chart_user_cats = [];
$chart_user_prod = [];
$chart_user_setup = [];
$chart_user_eff = []; // 新增：人員效率數據

// --- 修正 User 統計邏輯 (分開 Setup / Prod) ---
$user_stats_detailed = [];
foreach ($rows as $row) {
    $setup_sec = max(0, (int)$row['setup_seconds']);
    $prod_sec = max(0, (int)$row['prod_seconds']);

    if ($row['prod_user_name']) {
        $u = $row['prod_user_name'];
        if (!isset($user_stats_detailed[$u])) $user_stats_detailed[$u] = ['prod' => 0, 'setup' => 0];
        $user_stats_detailed[$u]['prod'] += $prod_sec;
    }
    if ($row['setup_user_name']) {
        $u = $row['setup_user_name'];
        if (!isset($user_stats_detailed[$u])) $user_stats_detailed[$u] = ['prod' => 0, 'setup' => 0];
        $user_stats_detailed[$u]['setup'] += $setup_sec;
    }
}

// 依總工時排序
uasort($user_stats_detailed, function($a, $b) {
    return ($b['prod'] + $b['setup']) <=> ($a['prod'] + $a['setup']);
});

foreach ($user_stats_detailed as $name => $stat) {
    $chart_user_cats[] = $name;
    $chart_user_prod[] = round($stat['prod'] / 3600, 1);
    $chart_user_setup[] = round($stat['setup'] / 3600, 1);
    
    $qty = $user_detail_map[$name]['qty'] ?? 0;
    $total_h = ($stat['prod'] + $stat['setup']) / 3600;
    $chart_user_eff[] = ($total_h > 0) ? round($qty / $total_h, 1) : 0;
}

// --- 新增：每日產出趨勢 (Top 5 Users) ---
$top_users = array_keys(array_slice($user_stats, 0, 5, true)); // 取工時前5名
$daily_trend_series = [];

foreach ($top_users as $u_name) {
    $data_points = [];
    // 篩選該人員的生產紀錄
    $user_rows = array_filter($rows, function($r) use ($u_name) {
        return $r['prod_user_name'] === $u_name;
    });
    
    // 按日期加總
    $daily_qty = [];
    foreach ($user_rows as $r) {
        $d = date('Y-m-d', strtotime($r['report_date']));
        if (!isset($daily_qty[$d])) $daily_qty[$d] = 0;
        $daily_qty[$d] += (int)$r['produced_qty'];
    }
    ksort($daily_qty); // 日期升序
    foreach ($daily_qty as $d => $q) {
        $data_points[] = [strtotime($d) * 1000, $q];
    }
    $daily_trend_series[] = ['name' => $u_name, 'data' => $data_points];
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>機台與人員效率分析</title>

    <!-- Bootstrap -->
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <!-- NProgress -->
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <!-- Custom Theme Style -->
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <!-- Datatables -->
    <link href="../../resource/css/dataTables.bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/buttons.bootstrap.css" rel="stylesheet">
    
    <style>
        .tile_count .tile_stats_count {
            margin-bottom: 10px;
            border-bottom: 0;
            padding-bottom: 10px;
        }
        .tile_count .tile_stats_count .count {
            font-size: 30px;
            font-weight: bold;
            line-height: 1.6;
        }
        .chart-container {
            height: 400px;
            margin-bottom: 20px;
        }
        .table-responsive {
            overflow-x: auto;
        }
        #reportTable th, #reportTable td {
            white-space: nowrap;
            vertical-align: middle;
            font-size: 13px;
        }
        .filter-box {
            background: #f7f7f7;
            padding: 15px;
            border: 1px solid #e5e5e5;
            margin-bottom: 20px;
            border-radius: 5px;
        }
        .nav-tabs > li.active > a {
            font-weight: bold;
            border-top: 3px solid #26B99A;
        }
    </style>
</head>

<body class="nav-sm">
    <div class="container body">
        <div class="main_container">
            <!-- 選單 -->
            <?php include '../partPage/sideAndTopBarMenu.html' ?>

            <!-- 頁面內容 -->
            <div class="right_col" role="main">
                <div class="">
                    <div class="page-title">
                        <div class="title_left">
                            <h3>機台與人員效率分析 <small>Machine & Personnel Efficiency</small></h3>
                        </div>
                    </div>

                    <div class="clearfix"></div>

                    <!-- 篩選區塊 -->
                    <div class="row">
                        <div class="col-md-12">
                            <div class="filter-box">
                                <form method="GET" action="" class="form-inline" id="filterForm">
                                    <div class="form-group">
                                        <label for="start_date">日期：</label>
                                        <div class="btn-group" style="margin-right: 5px;">
                                            <button type="button" class="btn btn-default btn-sm" onclick="changeMonth(-1)" title="上個月"><i class="fa fa-chevron-left"></i></button>
                                            <button type="button" class="btn btn-default btn-sm" onclick="setThisMonth()" title="本月">本月</button>
                                            <button type="button" class="btn btn-default btn-sm" onclick="changeMonth(1)" title="下個月"><i class="fa fa-chevron-right"></i></button>
                                        </div>
                                        <input type="date" class="form-control input-sm" name="start_date" id="start_date" value="<?= $start_date ?>">
                                        <label for="end_date"> 至 </label>
                                        <input type="date" class="form-control input-sm" name="end_date" id="end_date" value="<?= $end_date ?>">
                                    </div>
                                    <div class="form-group" style="margin-left: 10px;">
                                        <label for="machine_type_id">機台種類：</label>
                                        <select class="form-control input-sm" name="machine_type_id" id="machine_type_id">
                                            <option value="">-- 全部 --</option>
                                            <?php foreach ($machine_types as $mt): ?>
                                                <option value="<?= $mt['machine_type_id'] ?>" <?= $filter_machine_type == $mt['machine_type_id'] ? 'selected' : '' ?>><?= $mt['machine_type'] ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="form-group" style="margin-left: 10px;">
                                        <label for="machine_id">機台：</label>
                                        <select class="form-control input-sm" name="machine_id" id="machine_id">
                                            <option value="">-- 全部 --</option>
                                            <?php foreach ($machines as $m): ?>
                                                <option value="<?= $m['machine_id'] ?>" data-type="<?= $m['machine_type_id'] ?>" <?= $filter_machine == $m['machine_id'] ? 'selected' : '' ?>><?= $m['machine'] ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="form-group" style="margin-left: 10px;">
                                        <label for="user_id">人員：</label>
                                        <select class="form-control input-sm" name="user_id">
                                            <option value="">-- 全部 --</option>
                                            <?php foreach ($users as $u): ?>
                                                <option value="<?= $u['id'] ?>" <?= $filter_user == $u['id'] ? 'selected' : '' ?>><?= $u['user_cname'] ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <button type="submit" class="btn btn-primary btn-sm" style="margin-left: 10px; margin-bottom: 0;">查詢</button>
                                    <a href="Machine_Person_Efficiency.php" class="btn btn-default btn-sm" style="margin-bottom: 0;">重置</a>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- 統計數據磚 -->
                    <div class="row tile_count">
                        <div class="col-md-3 col-sm-4 col-xs-6 tile_stats_count">
                            <span class="count_top"><i class="fa fa-clock-o"></i> 總投入工時</span>
                            <div class="count"><?= number_format($total_work_seconds / 3600, 1) ?></div>
                            <span class="count_bottom">小時</span>
                        </div>
                        <div class="col-md-3 col-sm-4 col-xs-6 tile_stats_count">
                            <span class="count_top"><i class="fa fa-cubes"></i> 總良品產出</span>
                            <div class="count green"><?= number_format($total_prod_qty) ?></div>
                            <span class="count_bottom">PCS</span>
                        </div>
                        <div class="col-md-3 col-sm-4 col-xs-6 tile_stats_count">
                            <span class="count_top"><i class="fa fa-exclamation-triangle"></i> 總 NG 數量</span>
                            <div class="count red"><?= number_format($total_ng_qty) ?></div>
                            <span class="count_bottom">PCS</span>
                        </div>
                        <div class="col-md-3 col-sm-4 col-xs-6 tile_stats_count">
                            <span class="count_top"><i class="fa fa-bolt"></i> 平均時產</span>
                            <div class="count blue">
                                <?= ($total_work_seconds > 0) ? number_format($total_prod_qty / ($total_work_seconds / 3600), 1) : 0 ?>
                            </div>
                            <span class="count_bottom">PCS/小時</span>
                        </div>
                    </div>

                    <!-- 分頁籤 -->
                    <div role="tabpanel">
                        <ul class="nav nav-tabs bar_tabs" role="tablist">
                            <li role="presentation" class="active"><a href="#tab_machine" role="tab" data-toggle="tab">機台效率分析</a></li>
                            <li role="presentation"><a href="#tab_person" role="tab" data-toggle="tab">人員效率分析</a></li>
                        </ul>
                        
                        <div class="tab-content">
                            <!-- Tab 1: 機台效率分析 -->
                            <div role="tabpanel" class="tab-pane fade active in" id="tab_machine">
                                <!-- 圖表區塊 -->
                                <div class="row">
                                    <!-- 機台稼動率圖表 -->
                                    <div class="col-md-12 col-sm-12 col-xs-12">
                                        <div class="x_panel">
                                            <div class="x_title">
                                                <h2><i class="fa fa-bar-chart"></i> 機台稼動工時與稼動率</h2>
                                                <div class="clearfix"></div>
                                            </div>
                                            <div class="x_content">
                                                <div id="machine-chart" class="chart-container"></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Tab 2: 人員效率分析 -->
                            <div role="tabpanel" class="tab-pane fade" id="tab_person">
                                <!-- 工時分布 (Timeline) - 移至最上方 -->
                                <div class="row">
                                    <div class="col-md-12">
                                        <div class="x_panel">
                                            <div class="x_title">
                                                <h2><i class="fa fa-calendar"></i> 人員每日工時分布 (Timeline)</h2>
                                                <small>藍色:架機 / 綠色:生產 / 空白:空閒或未報工 (可拖曳時間軸縮放查看細節)</small>
                                                <div class="clearfix"></div>
                                            </div>
                                            <div class="x_content">
                                                <div id="timeline-chart" style="height: <?= $timeline_height ?>px;"></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- 每日產出趨勢 (Line Chart) -->
                                <div class="row">
                                    <div class="col-md-12">
                                        <div class="x_panel">
                                            <div class="x_title">
                                                <h2><i class="fa fa-line-chart"></i> 人員每日產出趨勢 (Top 5)</h2>
                                                <div class="clearfix"></div>
                                            </div>
                                            <div class="x_content">
                                                <div id="user-trend-chart" class="chart-container"></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- 人員效率圖表 -->
                                <div class="row">
                                    <div class="col-md-6 col-sm-12 col-xs-12">
                                        <div class="x_panel">
                                            <div class="x_title">
                                                <h2><i class="fa fa-users"></i> 人員報工工時分佈</h2>
                                                <small>生產 vs 架機 (小時)</small>
                                                <div class="clearfix"></div>
                                            </div>
                                            <div class="x_content">
                                                <div id="user-chart" class="chart-container"></div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6 col-sm-12 col-xs-12">
                                        <div class="x_panel">
                                            <div class="x_title">
                                                <h2><i class="fa fa-line-chart"></i> 人員平均效率</h2>
                                                <small>平均產出 (PCS/小時)</small>
                                                <div class="clearfix"></div>
                                            </div>
                                            <div class="x_content">
                                                <div id="user-efficiency-chart" class="chart-container"></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- 人員詳細統計表格 -->
                                <div class="row">
                                    <div class="col-md-12 col-sm-12 col-xs-12">
                                        <div class="x_panel">
                                            <div class="x_title">
                                                <h2><i class="fa fa-table"></i> 人員詳細統計數據</h2>
                                                <div class="clearfix"></div>
                                            </div>
                                            <div class="x_content">
                                                <div class="table-responsive">
                                                    <table id="userDetailTable" class="table table-striped table-bordered dt-responsive nowrap" cellspacing="0" width="100%">
                                                        <thead>
                                                            <tr>
                                                                <th>人員姓名</th>
                                                                <th>總工時 (H)</th>
                                                                <th>架機工時 (H)</th>
                                                                <th>生產工時 (H)</th>
                                                                <th>良品數 (PCS)</th>
                                                                <th>NG數 (PCS)</th>
                                                                <th>平均效率 (PCS/H)</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php foreach ($user_detail_map as $u): 
                                                                $total_h = ($u['setup_sec'] + $u['prod_sec']) / 3600;
                                                                $setup_h = $u['setup_sec'] / 3600;
                                                                $prod_h = $u['prod_sec'] / 3600;
                                                                $eff = ($total_h > 0) ? round($u['qty'] / $total_h, 1) : 0;
                                                            ?>
                                                            <tr>
                                                                <td><?= htmlspecialchars($u['name']) ?></td>
                                                                <td class="text-right"><?= number_format($total_h, 1) ?></td>
                                                                <td class="text-right"><?= number_format($setup_h, 1) ?></td>
                                                                <td class="text-right"><?= number_format($prod_h, 1) ?></td>
                                                                <td class="text-right"><?= number_format($u['qty']) ?></td>
                                                                <td class="text-right"><?= number_format($u['ng']) ?></td>
                                                                <td class="text-right font-bold"><?= $eff ?></td>
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

                    <!-- 詳細報工紀錄表格 -->
                    <div class="row">
                        <div class="col-md-12 col-sm-12 col-xs-12">
                            <div class="x_panel">
                                <div class="x_title">
                                    <h2><i class="fa fa-list"></i> 詳細報工紀錄</h2>
                                    <div class="clearfix"></div>
                                </div>
                                <div class="x_content">
                                    <div class="table-responsive">
                                        <table id="reportTable" class="table table-striped table-bordered dt-responsive nowrap" cellspacing="0" width="100%">
                                            <thead>
                                                <tr>
                                                    <th>狀態</th>
                                                    <th>日期</th>
                                                    <th>機台</th>
                                                    <th>客戶</th>
                                                    <th>BOM / 料號</th>
                                                    <th>製程</th>
                                                    <th>人員 (架/產)</th>
                                                    <th>時間 (架/產)</th>
                                                    <th>工時 (分)</th>
                                                    <th>良品</th>
                                                    <th>NG</th>
                                                    <th>效率 (PCS/H)</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($rows as $row): 
                                                    $setup_mins = round((int)$row['setup_seconds'] / 60);
                                                    $prod_mins = round((int)$row['prod_seconds'] / 60);
                                                    $total_mins = $setup_mins + $prod_mins;
                                                    $efficiency = ($total_mins > 0) ? round($row['produced_qty'] / ($total_mins / 60), 1) : 0;
                                                    
                                                    $setup_time_str = $row['setup_start_time'] ? substr($row['setup_start_time'], 11, 5) . '~' . substr($row['setup_end_time'], 11, 5) : '-';
                                                    $prod_time_str = $row['production_start_time'] ? substr($row['production_start_time'], 11, 5) . '~' . substr($row['production_end_time'], 11, 5) : '-';
                                                ?>
                                                <tr>
                                                    <td class="text-center"><?= $row['is_finished'] ? '<i class="fa fa-check-circle text-success" style="font-size:16px;"></i>' : '' ?></td>
                                                    <td><?= $row['report_date'] ?></td>
                                                    <td><?= htmlspecialchars($row['machine']) ?></td>
                                                    <td><?= htmlspecialchars($row['Client_Name'] ?? '') ?></td>
                                                    <td>
                                                        <strong><?= htmlspecialchars($row['bom']) ?></strong><br>
                                                        <small class="text-muted"><?= htmlspecialchars($row['d_id']) ?></small>
                                                    </td>
                                                    <td><?= htmlspecialchars($row['ProcessName']) ?></td>
                                                    <td>
                                                        <?php if($row['setup_user_name']) echo '<span class="label label-default">架</span> ' . htmlspecialchars($row['setup_user_name']) . '<br>'; ?>
                                                        <?php if($row['prod_user_name']) echo '<span class="label label-primary">產</span> ' . htmlspecialchars($row['prod_user_name']); ?>
                                                    </td>
                                                    <td>
                                                        <small>架: <?= $setup_time_str ?></small><br>
                                                        <small>產: <?= $prod_time_str ?></small>
                                                    </td>
                                                    <td>
                                                        架: <?= $setup_mins ?><br>
                                                        產: <?= $prod_mins ?>
                                                    </td>
                                                    <td class="text-right font-bold text-success"><?= number_format($row['produced_qty']) ?></td>
                                                    <td class="text-right <?= $row['ng_qty'] > 0 ? 'text-danger' : '' ?>"><?= number_format($row['ng_qty']) ?></td>
                                                    <td class="text-right"><?= $efficiency ?></td>
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
            <!-- /page content -->

            <!-- footer content -->
            <?php include '../partPage/footer.html' ?>
            <!-- /footer content -->
        </div>
    </div>

    <!-- Scripts -->
    <script src="../../resource/js/jquery.min.js"></script>
    <script src="../../resource/js/bootstrap.min.js"></script>
    <script src="../../resource/js/custom.min.js"></script>
    
    <!-- DataTables -->
    <script src="../../resource/js/jquery.dataTables.min.js"></script>
    <script src="../../resource/js/dataTables.bootstrap.min.js"></script>
    <script src="../../resource/js/dataTables.buttons.min.js"></script>
    <script src="../../resource/js/buttons.flash.min.js"></script>
    <script src="../../resource/js/buttons.html5.min.js"></script>
    <script src="../../resource/js/buttons.print.min.js"></script>
    <script src="../../resource/js/jszip.min.js"></script>
    <script src="../../resource/js/pdfmake.min.js"></script>
    <script src="../../resource/js/vfs_fonts.js"></script>

    <!-- Highcharts -->
    <script src="../../code/highcharts.js"></script>
    <script src="../../code/highcharts-more.js"></script> <!-- For bubble chart -->
    <script src="../../code/modules/exporting.js"></script>
    <script src="../../code/modules/accessibility.js"></script>

    <script>
        // 日期操作函式
        function formatDate(date) {
            var d = new Date(date),
                month = '' + (d.getMonth() + 1),
                day = '' + d.getDate(),
                year = d.getFullYear();
            if (month.length < 2) month = '0' + month;
            if (day.length < 2) day = '0' + day;
            return [year, month, day].join('-');
        }

        function setThisMonth() {
            var date = new Date();
            var firstDay = new Date(date.getFullYear(), date.getMonth(), 1);
            var lastDay = new Date(date.getFullYear(), date.getMonth() + 1, 0);
            document.getElementById('start_date').value = formatDate(firstDay);
            document.getElementById('end_date').value = formatDate(lastDay);
            document.getElementById('filterForm').submit();
        }

        function changeMonth(offset) {
            var startVal = document.getElementById('start_date').value;
            var baseDate = startVal ? new Date(startVal) : new Date();
            var targetDate = new Date(baseDate.getFullYear(), baseDate.getMonth() + offset, 1);
            var firstDay = new Date(targetDate.getFullYear(), targetDate.getMonth(), 1);
            var lastDay = new Date(targetDate.getFullYear(), targetDate.getMonth() + 1, 0);
            document.getElementById('start_date').value = formatDate(firstDay);
            document.getElementById('end_date').value = formatDate(lastDay);
            document.getElementById('filterForm').submit();
        }

        $(document).ready(function() {
            var machineChart, userChart, userEffChart, timelineChart, userTrendChart;

            // 初始化 DataTable
            $('#reportTable').DataTable({
                dom: 'Bfrtip',
                buttons: [
                    { extend: 'copy', className: 'btn btn-default btn-sm' },
                    { extend: 'csv', className: 'btn btn-default btn-sm' },
                    { extend: 'excel', className: 'btn btn-default btn-sm', title: '報工效率分析' },
                    { extend: 'print', className: 'btn btn-default btn-sm' }
                ],
                pageLength: 10,
                order: [[1, 'desc']], // 預設依日期降序 (index 1)
                language: {
                    "url": "//cdn.datatables.net/plug-ins/1.10.20/i18n/Chinese-traditional.json"
                }
            });

            // 初始化 User Detail Table
            $('#userDetailTable').DataTable({
                dom: 'Bfrtip',
                buttons: [
                    { extend: 'copy', className: 'btn btn-default btn-sm' },
                    { extend: 'csv', className: 'btn btn-default btn-sm' },
                    { extend: 'excel', className: 'btn btn-default btn-sm', title: '人員詳細效率統計' },
                    { extend: 'print', className: 'btn btn-default btn-sm' }
                ],
                pageLength: 25,
                order: [[1, 'desc']] // 依總工時排序
            });

            // 機台選單連動
            $('#machine_type_id').change(function() {
                var typeId = $(this).val();
                var $machineSelect = $('#machine_id');
                $machineSelect.find('option').show();
                if (typeId) {
                    $machineSelect.find('option').each(function() {
                        if ($(this).val() && $(this).data('type') != typeId) {
                            $(this).hide();
                        }
                    });
                }
                $machineSelect.val('');
            });

            // 機台稼動率圖表 (堆疊長條圖)
            machineChart = Highcharts.chart('machine-chart', {
                chart: { type: 'bar' },
                title: { text: null },
                xAxis: {
                    categories: <?php echo json_encode($chart_machine_cats); ?>,
                    title: { text: null }
                },
                yAxis: {
                    min: 0,
                    title: { text: '工時 (小時)', align: 'high' },
                    labels: { overflow: 'justify' }
                },
                tooltip: { 
                    shared: true,
                    formatter: function() {
                        var s = '<b>' + this.x + '</b>';
                        var util = 0;
                        $.each(this.points, function(i, point) {
                            s += '<br/>' + point.series.name + ': ' + point.y + ' 小時';
                        });
                        var idx = this.points[0].point.index;
                        var utils = <?php echo json_encode($chart_machine_util); ?>;
                        s += '<br/>----------------<br/>稼動率: ' + utils[idx] + '% (以24H/天計算)';
                        return s;
                    }
                },
                plotOptions: {
                    bar: { dataLabels: { enabled: true } },
                    series: { stacking: 'normal' }
                },
                legend: { reversed: true },
                credits: { enabled: false },
                series: [{
                    name: '生產',
                    data: <?php echo json_encode($chart_machine_prod); ?>,
                    color: '#26B99A'
                }, {
                    name: '架機',
                    data: <?php echo json_encode($chart_machine_setup); ?>,
                    color: '#3498db'
                }]
            });

            // 人員效率圖表 (堆疊長條圖)
            userChart = Highcharts.chart('user-chart', {
                chart: { type: 'bar' },
                title: { text: null },
                xAxis: {
                    categories: <?php echo json_encode($chart_user_cats); ?>,
                    title: { text: null }
                },
                yAxis: {
                    min: 0,
                    title: { text: '工時 (小時)', align: 'high' },
                    labels: { overflow: 'justify' }
                },
                tooltip: { valueSuffix: ' 小時', shared: true },
                plotOptions: {
                    bar: { dataLabels: { enabled: true } },
                    series: { stacking: 'normal' }
                },
                legend: { reversed: true },
                credits: { enabled: false },
                series: [{
                    name: '生產',
                    data: <?php echo json_encode($chart_user_prod); ?>,
                    color: '#26B99A'
                }, {
                    name: '架機',
                    data: <?php echo json_encode($chart_user_setup); ?>,
                    color: '#3498db'
                }]
            });

            // 人員效率圖表 (長條圖)
            userEffChart = Highcharts.chart('user-efficiency-chart', {
                chart: { type: 'bar' },
                title: { text: null },
                xAxis: {
                    categories: <?php echo json_encode($chart_user_cats); ?>,
                    title: { text: null }
                },
                yAxis: {
                    min: 0,
                    title: { text: '效率 (PCS/H)', align: 'high' }
                },
                tooltip: { valueSuffix: ' PCS/H', shared: true },
                legend: { enabled: false },
                credits: { enabled: false },
                series: [{
                    name: '平均效率',
                    data: <?php echo json_encode($chart_user_eff); ?>,
                    color: '#e74c3c' // Red
                }]
            });

            // 人員產出趨勢圖 (折線圖)
            userTrendChart = Highcharts.chart('user-trend-chart', {
                chart: { type: 'line' },
                title: { text: null },
                xAxis: { type: 'datetime', title: { text: '日期' } },
                yAxis: { title: { text: '產出數量 (PCS)' }, min: 0 },
                tooltip: { shared: true, xDateFormat: '%Y-%m-%d' },
                plotOptions: {
                    line: { dataLabels: { enabled: false }, marker: { enabled: false } }
                },
                credits: { enabled: false },
                series: <?php echo json_encode($daily_trend_series); ?>
            });

            // 人員時間軸圖表 (Timeline / ColumnRange)
            timelineChart = Highcharts.chart('timeline-chart', {
                chart: { 
                    type: 'columnrange', 
                    inverted: true,
                    zoomType: 'y', // 允許縮放時間軸
                    style: { fontFamily: 'Arial, sans-serif' }
                },
                title: { text: null },
                xAxis: {
                    categories: <?php echo json_encode($chart_timeline_users); ?>,
                    title: { text: '人員' },
                    gridLineWidth: 1,
                    labels: { style: { fontSize: '13px', fontWeight: 'bold' } }
                },
                yAxis: {
                    type: 'datetime',
                    title: { text: '時間' },
                    plotBands: <?php echo json_encode($plot_bands); ?>,
                    gridLineWidth: 1,
                    dateTimeLabelFormats: { day: '%m/%d', hour: '%H:00' }
                },
                tooltip: {
                    useHTML: true,
                    headerFormat: '<span style="font-size: 12px; font-weight: bold;">{point.key}</span><br/>',
                    pointFormatter: function() {
                        var start = Highcharts.dateFormat('%Y-%m-%d %H:%M', this.low);
                        var end = Highcharts.dateFormat('%H:%M', this.high);
                        var dur = ((this.high - this.low) / 1000 / 60).toFixed(0);
                        var bom = this.options.bom || '-';
                        var machine = this.options.machine || '-';
                        var color = this.color;
                        return '<div style="margin-top: 5px; padding: 5px; border: 1px solid #ccc; background: #fff; border-radius: 3px;">' +
                               '<span style="color:' + color + '">●</span> <b>' + this.series.name + '</b><br/>' +
                               '時間: ' + start + ' ~ ' + end + ' (' + dur + '分)<br/>' +
                               'BOM: <b>' + bom + '</b><br/>' +
                               '機台: <b>' + machine + '</b>' +
                               '</div>';
                    }
                },
                plotOptions: {
                    columnrange: {
                        grouping: false, // 允許重疊 (同一人多個時段)
                        borderRadius: 3,
                        borderWidth: 1,
                        borderColor: '#ffffff',
                        pointPadding: 0.1,
                        groupPadding: 0,
                        dataLabels: {
                            enabled: false
                        }
                    }
                },
                legend: { enabled: true, align: 'right', verticalAlign: 'top', layout: 'vertical' },
                credits: { enabled: false },
                series: [{
                    name: '架機',
                    data: <?php echo json_encode($timeline_series_setup); ?>,
                    color: '#3498db' // Solid Blue
                }, {
                    name: '生產',
                    data: <?php echo json_encode($timeline_series_prod); ?>,
                    color: '#26B99A' // Solid Green
                }]
            });

            // 修正 Tab 切換時圖表顯示問題 (Reflow)
            $('a[data-toggle="tab"]').on('shown.bs.tab', function (e) {
                var target = $(e.target).attr('href');
                if (target === '#tab_person') {
                    if(userChart) userChart.reflow();
                    if(userEffChart) userEffChart.reflow();
                    if(timelineChart) timelineChart.reflow();
                    if(userTrendChart) userTrendChart.reflow();
                } else if (target === '#tab_machine') {
                    if(machineChart) machineChart.reflow();
                }
            });
        });
    </script>
</body>
</html>
