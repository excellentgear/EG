<?php
session_start();
if (!isset($_SESSION['userName'])) {
    header("Location:../../index.php");
    exit;
}

include '../../src/common/DBConnection.php';
include '../../src/common/_config.php';

$conn = new DBConnection();
$pdo = $conn->getPDO();

// --- AJAX Handlers ---

// AJAX: 儲存計算方式設定
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_calculation_config') {
    header('Content-Type: application/json');
    try {
        $config_json = $_POST['config'] ?? '{}';
        $user = $_SESSION['userName'] ?? 'system';

        $sql = "INSERT INTO system_parameters (param_group, param_key, param_value, description, updated_by, updated_at) 
                VALUES ('PROFIT_ANALYSIS', 'calculation_config', ?, '產品利潤分析-計算方式設定', ?, NOW()) 
                ON DUPLICATE KEY UPDATE param_value = VALUES(param_value), updated_by = VALUES(updated_by), updated_at = NOW()";
        $pdo->prepare($sql)->execute([$config_json, $user]);
        
        echo json_encode(['success' => true, 'message' => '計算方式設定已儲存']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => '儲存失敗: ' . $e->getMessage()]);
    }
    exit;
}

// AJAX: 取得產品圖檔 (沿用)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_product_files') {
    header('Content-Type: application/json');
    try {
        $pid = $_POST['product_id'];
        $stmt = $pdo->prepare("SELECT bom, sqty FROM bom WHERE d_id = ? ORDER BY Created_At DESC");
        $stmt->execute([$pid]);
        $bom_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $files = [];
        $scan_dir = 'Z:/BOM/';
        $url_dir = '/nas/';

        if (is_dir($scan_dir)) {
            $allFiles = scandir($scan_dir);
            foreach ($bom_rows as $row) {
                $bom = $row['bom'];
                $qty = $row['sqty'];
                foreach ($allFiles as $f) {
                    if ($f === '.' || $f === '..') continue;
                    if (strpos($f, $bom) === 0) {
                        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
                        if (in_array($ext, ['jpg', 'jpeg', 'png', 'pdf'])) {
                            $display_bom = $bom . ' (Qty:' . ($qty !== null ? $qty : '?') . ')';
                            $files[] = ['bom' => $display_bom, 'name' => $f, 'path' => $url_dir . $f, 'type' => $ext];
                        }
                    }
                }
            }
        }
        echo json_encode(['success' => true, 'files' => $files]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// --- Data Fetching and Processing ---

// 1. 取得篩選參數
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');

// 2. 載入計算設定
$stmt_config = $pdo->prepare("SELECT param_value FROM system_parameters WHERE param_group = 'PROFIT_ANALYSIS' AND param_key = 'calculation_config'");
$stmt_config->execute();
$config_row = $stmt_config->fetch(PDO::FETCH_ASSOC);
$calculation_config = $config_row ? json_decode($config_row['param_value'], true) : ['excluded_processes' => []];
$excluded_processes = $calculation_config['excluded_processes'] ?? [];

// 3. 撈取期間內的所有出貨紀錄 (作為分析基礎)
$sql_shipping = "SELECT Product_id, SUM(Qty) as total_qty, SUM(Qty * Unit_price) as total_revenue
                 FROM is_list 
                 WHERE Order_date BETWEEN :start AND :end AND Product_id IS NOT NULL AND Product_id != ''
                 GROUP BY Product_id";
$stmt_shipping = $pdo->prepare($sql_shipping);
$stmt_shipping->execute([':start' => $start_date, ':end' => $end_date]);
$shipping_data = $stmt_shipping->fetchAll(PDO::FETCH_KEY_PAIR | PDO::FETCH_GROUP);

$product_ids = array_keys($shipping_data);
$analysis_data = [];

if (!empty($product_ids)) {
    $placeholders = implode(',', array_fill(0, count($product_ids), '?'));

    // 4. 批次撈取料號、客戶、齒輪資訊
    $sql_products = "SELECT ds.D_Setting_Id, ds.Client_Name, ds.Type as part_type, 
                            dg.Module, dg.Teeth, dg.Face_Width, dg.Workpiece_Length
                     FROM d_setting ds
                     LEFT JOIN d_setting_gear dg ON ds.d_id = dg.d_setting_id
                     WHERE ds.D_Setting_Id IN ($placeholders)";
    $stmt_products = $pdo->prepare($sql_products);
    $stmt_products->execute($product_ids);
    $product_info = $stmt_products->fetchAll(PDO::FETCH_ASSOC);

    // 5. 批次撈取所有相關的加工成本
    $sql_costs = "SELECT product_id, bom, bom_sn, process_amount, loss_qty, transfer_qty, price 
                  FROM bom_ing_transfer_log 
                  WHERE product_id IN ($placeholders)";
    $stmt_costs = $pdo->prepare($sql_costs);
    $stmt_costs->execute($product_ids);
    $cost_data = $stmt_costs->fetchAll(PDO::FETCH_ASSOC);

    // 6. 批次撈取所有相關的製程資訊
    $sql_processes = "SELECT b.d_id, b.bom, bi.bom_sn, bi.process_no, pn.ProcessName
                      FROM bom b
                      JOIN bom_ing bi ON b.bom = bi.bom
                      LEFT JOIN process_no pn ON bi.process_no = pn.ProcessNo
                      WHERE b.d_id IN ($placeholders)";
    $stmt_processes = $pdo->prepare($sql_processes);
    $stmt_processes->execute($product_ids);
    $process_data = $stmt_processes->fetchAll(PDO::FETCH_ASSOC);

    // 7. 批次撈取出貨明細
    $sql_shipping_details = "SELECT Product_id, Order_date, IS_number, Qty, Unit_price FROM is_list WHERE Product_id IN ($placeholders) AND Order_date BETWEEN :start AND :end";
    $stmt_shipping_details = $pdo->prepare($sql_shipping_details);
    $stmt_shipping_details->execute(array_merge($product_ids, [':start' => $start_date, ':end' => $end_date]));
    $shipping_details = $stmt_shipping_details->fetchAll(PDO::FETCH_ASSOC | PDO::FETCH_GROUP);

    // 8. 彙整資料
    $product_map = [];
    foreach ($product_info as $p) { $product_map[$p['D_Setting_Id']] = $p; }

    $cost_map = [];
    foreach ($cost_data as $c) {
        $cost_map[$c['product_id']][] = $c;
    }

    $process_map = [];
    foreach ($process_data as $p) {
        $process_map[$p['d_id']][$p['bom']][$p['bom_sn']] = $p;
    }

    foreach ($product_ids as $pid) {
        $info = $product_map[$pid] ?? ['Client_Name' => '未知', 'part_type' => '一般'];
        $costs = $cost_map[$pid] ?? [];
        
        $total_cost = 0;
        $cost_details = [];
        foreach ($costs as $c) {
            $process_no = $process_map[$pid][$c['bom']][$c['bom_sn']]['process_no'] ?? 'N/A';
            
            // 根據設定排除成本
            if (in_array($process_no, $excluded_processes)) {
                continue;
            }

            $amount = floatval($c['process_amount']);
            if ($amount == 0 && floatval($c['transfer_qty']) > 0 && floatval($c['price']) > 0) {
                $amount = floatval($c['transfer_qty']) * floatval($c['price']);
            }
            $total_cost += $amount;
            $cost_details[] = [
                'bom' => $c['bom'],
                'bom_sn' => $c['bom_sn'],
                'process_no' => $process_no,
                'process_name' => $process_map[$pid][$c['bom']][$c['bom_sn']]['ProcessName'] ?? '未知製程',
                'amount' => $amount
            ];
        }

        $total_revenue = $shipping_data[$pid][0]['total_revenue'] ?? 0;
        $profit = $total_revenue - $total_cost;
        $margin = ($total_revenue > 0) ? ($profit / $total_revenue) * 100 : 0;

        $analysis_data[$pid] = [
            'product_id' => $pid,
            'client_name' => $info['Client_Name'],
            'part_type' => $info['part_type'],
            'gear_info' => ($info['part_type'] === 'G') ? "M{$info['Module']} T{$info['Teeth']} W{$info['Face_Width']}" : '',
            'total_revenue' => $total_revenue,
            'total_cost' => $total_cost,
            'profit' => $profit,
            'margin' => $margin,
            'shipping_details' => $shipping_details[$pid] ?? [],
            'cost_details' => $cost_details
        ];
    }
}

// 9. 準備圖表與統計數據
$chart_profit_data = [];
$chart_margin_data = [];
uasort($analysis_data, function($a, $b) { return $b['profit'] <=> $a['profit']; });
$top_10_profit = array_slice($analysis_data, 0, 10, true);
foreach($top_10_profit as $pid => $data) {
    $chart_profit_data[] = ['name' => $pid, 'y' => (float)number_format($data['profit'], 2, '.', '')];
}

uasort($analysis_data, function($a, $b) { return $a['margin'] <=> $b['margin']; });
$bottom_10_margin = array_slice($analysis_data, 0, 10, true);
foreach($bottom_10_margin as $pid => $data) {
    $chart_margin_data[] = ['name' => $pid, 'y' => (float)number_format($data['margin'], 2, '.', '')];
}

// 重新排序以供表格顯示
uasort($analysis_data, function($a, $b) { return $b['profit'] <=> $a['profit']; });

$all_processes_list = $pdo->query("SELECT ProcessNo, ProcessName FROM process_no ORDER BY ProcessNo")->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>產品利潤分析</title>

    <!-- Libs -->
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <link href="../../resource/css/dataTables.bootstrap.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

    <style>
        body { font-size: 14px; }
        .x_panel { box-shadow: 0 2px 4px rgba(0,0,0,0.08); }
        .x_title h2 { font-weight: 600; }
        .chart-container { height: 400px; }
        #profitTable td, #profitTable th { vertical-align: middle; }
        .details-control { cursor: pointer; font-size: 1.2em; }
        tr.details td { background-color: #f9f9f9; padding: 0 !important; }
        .child-table { margin: 10px; }
        .child-table th { background-color: #e8e8e8; }
        .profit-positive { color: #1ABB9C; font-weight: bold; }
        .profit-negative { color: #d9534f; font-weight: bold; }
        .margin-badge { font-size: 1em; padding: .3em .6em; }
    </style>
</head>

<body class="nav-sm">
    <div class="container body">
        <div class="main_container">
            <?php include '../partPage/sideAndTopBarMenu.html' ?>

            <div class="right_col" role="main">
                <div class="">
                    <div class="page-title">
                        <div class="title_left">
                            <h3>產品利潤分析 <small>Product Profitability Analysis</small></h3>
                        </div>
                    </div>
                    <div class="clearfix"></div>

                    <!-- Filter Section -->
                    <div class="row">
                        <div class="col-md-12">
                            <div class="x_panel">
                                <div class="x_title">
                                    <h2><i class="fa fa-filter"></i> 查詢條件</h2>
                                    <ul class="nav navbar-right panel_toolbox">
                                        <li><a class="collapse-link"><i class="fa fa-chevron-up"></i></a></li>
                                    </ul>
                                    <div class="clearfix"></div>
                                </div>
                                <div class="x_content">
                                    <form method="GET" action="" class="form-inline">
                                        <div class="form-group">
                                            <label>出貨日期範圍：</label>
                                            <input type="date" class="form-control" name="start_date" value="<?= $start_date ?>">
                                            <span>至</span>
                                            <input type="date" class="form-control" name="end_date" value="<?= $end_date ?>">
                                        </div>
                                        <button type="submit" class="btn btn-primary">查詢</button>
                                        <button type="button" class="btn btn-info" data-toggle="modal" data-target="#configModal">計算方式設定</button>
                                        <button type="button" class="btn btn-danger" onclick="runAnomalyDetection()">異常偵測</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Chart Section -->
                    <div class="row">
                        <div class="col-md-6">
                            <div class="x_panel">
                                <div class="x_title">
                                    <h2><i class="fa fa-line-chart"></i> 利潤最高 Top 10 產品</h2>
                                    <div class="clearfix"></div>
                                </div>
                                <div class="x_content">
                                    <div id="top-profit-chart" class="chart-container"></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="x_panel">
                                <div class="x_title">
                                    <h2><i class="fa fa-line-chart"></i> 利潤率最低 Top 10 產品</h2>
                                    <div class="clearfix"></div>
                                </div>
                                <div class="x_content">
                                    <div id="bottom-margin-chart" class="chart-container"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Main Table -->
                    <div class="row">
                        <div class="col-md-12">
                            <div class="x_panel">
                                <div class="x_title">
                                    <h2><i class="fa fa-list"></i> 利潤分析列表</h2>
                                    <div class="clearfix"></div>
                                </div>
                                <div class="x_content">
                                    <table id="profitTable" class="table table-striped table-bordered" style="width:100%">
                                        <thead>
                                            <tr>
                                                <th></th>
                                                <th>料號</th>
                                                <th>客戶</th>
                                                <th>總收入</th>
                                                <th>總成本</th>
                                                <th>利潤</th>
                                                <th>利潤率</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach($analysis_data as $pid => $data): ?>
                                            <tr>
                                                <td class="details-control text-center"><i class="fa fa-plus-square-o"></i></td>
                                                <td><a href="javascript:void(0);" onclick="openProductFiles('<?= htmlspecialchars($pid) ?>')"><?= htmlspecialchars($pid) ?></a></td>
                                                <td><?= htmlspecialchars($data['client_name']) ?></td>
                                                <td class="text-right"><?= number_format($data['total_revenue'], 0) ?></td>
                                                <td class="text-right"><?= number_format($data['total_cost'], 0) ?></td>
                                                <td class="text-right <?= $data['profit'] >= 0 ? 'profit-positive' : 'profit-negative' ?>"><?= number_format($data['profit'], 0) ?></td>
                                                <td class="text-right">
                                                    <?php 
                                                        $margin = $data['margin'];
                                                        $badge_class = 'default';
                                                        if ($margin >= 30) $badge_class = 'success';
                                                        elseif ($margin >= 10) $badge_class = 'info';
                                                        elseif ($margin >= 0) $badge_class = 'warning';
                                                        else $badge_class = 'danger';
                                                    ?>
                                                    <span class="badge label-<?= $badge_class ?> margin-badge"><?= number_format($margin, 1) ?>%</span>
                                                </td>
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

            <?php include '../partPage/footer.html' ?>
        </div>
    </div>

    <!-- Modals -->
    <div class="modal fade" id="configModal" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h4 class="modal-title">計算方式設定</h4>
                </div>
                <div class="modal-body">
                    <form id="configForm">
                        <div class="form-group">
                            <label>從成本中排除以下製程：</label>
                            <select id="excluded_processes_select" name="excluded_processes[]" multiple="multiple" class="form-control">
                                <?php foreach($all_processes_list as $proc): ?>
                                    <option value="<?= $proc['ProcessNo'] ?>" <?= in_array($proc['ProcessNo'], $excluded_processes) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($proc['ProcessNo'] . ' - ' . $proc['ProcessName']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
                    <button type="button" class="btn btn-primary" onclick="saveConfig()">儲存</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="anomalyModal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h4 class="modal-title">異常偵測報告</h4>
                </div>
                <div class="modal-body" id="anomaly-body" style="max-height: 70vh; overflow-y: auto;"></div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal">關閉</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="bomFileModal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-lg" style="width: 90%;">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                    <h4 class="modal-title">產品圖檔: <span id="modal-product-title"></span></h4>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-3"><div class="list-group" id="bom-file-list"></div></div>
                        <div class="col-md-9" id="bom-file-viewer" style="min-height: 500px; text-align: center; background: #eee;"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Libs -->
    <script src="../../resource/js/jquery.min.js"></script>
    <script src="../../resource/js/bootstrap.min.js"></script>
    <script src="../../resource/js/custom.min.js"></script>
    <script src="../../resource/js/jquery.dataTables.min.js"></script>
    <script src="../../resource/js/dataTables.bootstrap.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="../../code/highcharts.js"></script>
    <script src="../../code/modules/exporting.js"></script>

    <script>
        var analysisData = <?= json_encode(array_values($analysis_data)); ?>;

        // Format child row
        function format(d) {
            let revenueHtml = '<h5><i class="fa fa-truck"></i> 出貨收入明細 (' + d.shipping_details.length + '筆)</h5>';
            if (d.shipping_details.length > 0) {
                revenueHtml += '<table class="table table-condensed table-bordered child-table">';
                revenueHtml += '<thead><tr><th>出貨日</th><th>單號</th><th class="text-right">數量</th><th class="text-right">單價</th><th class="text-right">金額</th></tr></thead><tbody>';
                d.shipping_details.forEach(s => {
                    revenueHtml += `<tr><td>${s.Order_date}</td><td>${s.IS_number}</td><td class="text-right">${s.Qty}</td><td class="text-right">${s.Unit_price}</td><td class="text-right">${s.Qty * s.Unit_price}</td></tr>`;
                });
                revenueHtml += '</tbody></table>';
            } else {
                revenueHtml += '<p class="text-muted" style="margin-left:10px;">無出貨紀錄</p>';
            }

            let costHtml = '<h5><i class="fa fa-wrench"></i> 加工成本明細 (' + d.cost_details.length + '筆)</h5>';
            if (d.cost_details.length > 0) {
                costHtml += '<table class="table table-condensed table-bordered child-table">';
                costHtml += '<thead><tr><th>BOM</th><th>SN</th><th>製程</th><th class="text-right">金額</th></tr></thead><tbody>';
                d.cost_details.forEach(c => {
                    costHtml += `<tr><td>${c.bom}</td><td>${c.bom_sn}</td><td>${c.process_no} - ${c.process_name}</td><td class="text-right">${c.amount}</td></tr>`;
                });
                costHtml += '</tbody></table>';
            } else {
                costHtml += '<p class="text-muted" style="margin-left:10px;">無成本紀錄</p>';
            }

            return '<div style="padding:10px 20px;">' + revenueHtml + '<hr>' + costHtml + '</div>';
        }

        $(document).ready(function() {
            var table = $('#profitTable').DataTable({
                "data": analysisData,
                "columns": [
                    { "className": 'details-control text-center', "orderable": false, "data": null, "defaultContent": '<i class="fa fa-plus-square-o"></i>' },
                    { "data": "product_id", "render": function(data, type, row) {
                        return `<a href="javascript:void(0);" onclick="openProductFiles('${data}')">${data}</a>`;
                    }},
                    { "data": "client_name" },
                    { "data": "total_revenue", "className": "text-right", "render": $.fn.dataTable.render.number(',', '.', 0) },
                    { "data": "total_cost", "className": "text-right", "render": $.fn.dataTable.render.number(',', '.', 0) },
                    { "data": "profit", "className": "text-right", "render": function(data, type, row) {
                        var colorClass = data >= 0 ? 'profit-positive' : 'profit-negative';
                        return `<span class="${colorClass}">${$.fn.dataTable.render.number(',', '.', 0).display(data)}</span>`;
                    }},
                    { "data": "margin", "className": "text-right", "render": function(data, type, row) {
                        var badge_class = 'default';
                        if (data >= 30) badge_class = 'success';
                        else if (data >= 10) badge_class = 'info';
                        else if (data >= 0) badge_class = 'warning';
                        else badge_class = 'danger';
                        return `<span class="badge label-${badge_class} margin-badge">${$.fn.dataTable.render.number(',', '.', 1).display(data)}%</span>`;
                    }}
                ],
                "order": [[5, 'desc']],
                "language": { "url": "//cdn.datatables.net/plug-ins/1.10.20/i18n/Chinese-traditional.json" }
            });

            // Add event listener for opening and closing details
            $('#profitTable tbody').on('click', 'td.details-control', function () {
                var tr = $(this).closest('tr');
                var row = table.row(tr);

                if (row.child.isShown()) {
                    row.child.hide();
                    tr.find('i').removeClass('fa-minus-square-o').addClass('fa-plus-square-o');
                } else {
                    row.child(format(row.data())).show();
                    tr.find('i').removeClass('fa-plus-square-o').addClass('fa-minus-square-o');
                }
            });

            // Init Select2
            $('#excluded_processes_select').select2({
                placeholder: "選擇要排除的製程",
                width: '100%'
            });

            // Init Charts
            Highcharts.chart('top-profit-chart', {
                chart: { type: 'bar' },
                title: { text: null },
                xAxis: { type: 'category', title: { text: null } },
                yAxis: { title: { text: '利潤 (NTD)' } },
                legend: { enabled: false },
                series: [{ name: '利潤', data: <?= json_encode($chart_profit_data) ?> }],
                credits: { enabled: false }
            });

            Highcharts.chart('bottom-margin-chart', {
                chart: { type: 'bar' },
                title: { text: null },
                xAxis: { type: 'category', title: { text: null } },
                yAxis: { title: { text: '利潤率 (%)' } },
                legend: { enabled: false },
                series: [{ name: '利潤率', data: <?= json_encode($chart_margin_data) ?>, color: '#f0ad4e' }],
                credits: { enabled: false }
            });
        });

        function saveConfig() {
            var excluded = $('#excluded_processes_select').val();
            var config = { excluded_processes: excluded || [] };
            
            $.post('', { action: 'save_calculation_config', config: JSON.stringify(config) }, function(res) {
                if (res.success) {
                    alert('設定已儲存，頁面將重新整理以套用。');
                    location.reload();
                } else {
                    alert('儲存失敗: ' + res.message);
                }
            }, 'json');
        }

        function runAnomalyDetection() {
            let html = '';
            let negativeProfitItems = [];
            let zeroCostItems = [];
            let zeroRevenueItems = [];

            analysisData.forEach(item => {
                if (item.profit < 0) {
                    negativeProfitItems.push(item);
                }
                if (item.total_revenue > 0 && item.total_cost === 0) {
                    zeroCostItems.push(item);
                }
                if (item.total_cost > 0 && item.total_revenue === 0) {
                    zeroRevenueItems.push(item);
                }
            });

            if (negativeProfitItems.length > 0) {
                html += '<h4><i class="fa fa-arrow-down text-danger"></i> 負利潤產品 (' + negativeProfitItems.length + '筆)</h4><ul>';
                negativeProfitItems.forEach(item => {
                    html += `<li><a href="javascript:void(0);" onclick="jumpToRowByPid('${item.product_id}')">${item.product_id} (${item.client_name})</a> - 利潤: ${numberFormat(item.profit, 0)}</li>`;
                });
                html += '</ul><hr>';
            }

            if (zeroCostItems.length > 0) {
                html += '<h4><i class="fa fa-question-circle text-warning"></i> 有收入但無成本 (' + zeroCostItems.length + '筆)</h4><ul>';
                zeroCostItems.forEach(item => {
                    html += `<li><a href="javascript:void(0);" onclick="jumpToRowByPid('${item.product_id}')">${item.product_id} (${item.client_name})</a> - 收入: ${numberFormat(item.total_revenue, 0)}</li>`;
                });
                html += '</ul><hr>';
            }

            if (zeroRevenueItems.length > 0) {
                html += '<h4><i class="fa fa-exclamation-triangle text-info"></i> 有成本但無收入 (' + zeroRevenueItems.length + '筆)</h4><ul>';
                zeroRevenueItems.forEach(item => {
                    html += `<li><a href="javascript:void(0);" onclick="jumpToRowByPid('${item.product_id}')">${item.product_id} (${item.client_name})</a> - 成本: ${numberFormat(item.total_cost, 0)}</li>`;
                });
                html += '</ul>';
            }

            if (html === '') {
                html = '<div class="alert alert-success">未偵測到明顯異常。</div>';
            }

            $('#anomaly-body').html(html);
            $('#anomalyModal').modal('show');
        }

        function jumpToRowByPid(pid) {
            var table = $('#profitTable').DataTable();
            var pageInfo = table.page.info();
            
            // Clear any existing search
            table.search('').draw();
            
            var rowIndex = table.rows().indexes().filter(function (value, index) {
                return table.row(value).data().product_id === pid;
            });

            if (rowIndex.length > 0) {
                var page = Math.floor(rowIndex[0] / pageInfo.length);
                table.page(page).draw(false);

                setTimeout(function() {
                    var rowNode = table.row(rowIndex[0]).node();
                    $('#anomalyModal').modal('hide');
                    $('html, body').animate({
                        scrollTop: $(rowNode).offset().top - 100
                    }, 500);
                    $(rowNode).find('td.details-control').click();
                }, 200);
            }
        }

        function openProductFiles(pid) {
            if (!pid || pid === '未知料號') return;
            $('#modal-product-title').text(pid);
            $('#bom-file-list').html('<p class="text-center"><i class="fa fa-spinner fa-spin"></i> 載入中...</p>');
            $('#bom-file-viewer').empty();
            $('#bomFileModal').modal('show');

            $.post('', { action: 'get_product_files', product_id: pid }, function(res) {
                if (res.success && res.files.length > 0) {
                    var listHtml = '';
                    res.files.forEach(function(f, idx) {
                        var active = idx === 0 ? 'active' : '';
                        listHtml += `<a href="#" class="list-group-item bom-file-item ${active}" data-path="${f.path}" data-type="${f.type}">
                                        <h5 class="list-group-item-heading">${f.bom}</h5>
                                        <p class="list-group-item-text">${f.name}</p></a>`;
                    });
                    $('#bom-file-list').html(listHtml);
                    showBomFile(res.files[0].path, res.files[0].type);
                } else {
                    $('#bom-file-list').html('<div class="alert alert-warning">無相關圖檔</div>');
                }
            }, 'json');
        }

        $(document).on('click', '.bom-file-item', function(e) {
            e.preventDefault();
            $('.bom-file-item').removeClass('active');
            $(this).addClass('active');
            showBomFile($(this).data('path'), $(this).data('type'));
        });

        function showBomFile(path, type) {
            var html = '';
            if (type === 'pdf') {
                html = `<iframe src="${path}" style="width:100%; height:600px; border:none;"></iframe>`;
            } else {
                html = `<img src="${path}" style="max-width:100%; max-height:600px; margin-top:10px;">`;
            }
            $('#bom-file-viewer').html(html);
        }

        function numberFormat(number, decimals, dec_point, thousands_sep) {
            number = (number + '').replace(/[^0-9+\-Ee.]/g, '');
            var n = !isFinite(+number) ? 0 : +number,
                prec = !isFinite(+decimals) ? 0 : Math.abs(decimals),
                sep = (typeof thousands_sep === 'undefined') ? ',' : thousands_sep,
                dec = (typeof dec_point === 'undefined') ? '.' : dec_point,
                s = '',
                toFixedFix = function (n, prec) {
                    var k = Math.pow(10, prec);
                    return '' + Math.round(n * k) / k;
                };
            s = (prec ? toFixedFix(n, prec) : '' + Math.round(n)).split('.');
            if (s[0].length > 3) {
                s[0] = s[0].replace(/\B(?=(?:\d{3})+(?!\d))/g, sep);
            }
            if ((s[1] || '').length < prec) {
                s[1] = s[1] || '';
                s[1] += new Array(prec - s[1].length + 1).join('0');
            }
            return s.join(dec);
        }
    </script>
</body>
</html>