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

// 處理取得產品圖檔 (AJAX) - 沿用 Shipping_Analysis 的功能
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'get_product_files') {
    header('Content-Type: application/json');
    try {
        $pid = $_POST['product_id'];
        
        // 搜尋關聯的 BOM (由新到舊)
        $stmt = $conn->getPDO()->prepare("SELECT bom, sqty FROM bom WHERE d_id = ? ORDER BY Created_At DESC");
        $stmt->execute([$pid]);
        $bom_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $files = [];
        require_once __DIR__ . '/../../src/common/bom_dir_lib.php';   // 資料夾位置走設定鍵 bom_scan_dir，不再寫死 Z: 磁碟機代號
        $scan_dir = eg_bom_scan_dir_auto(); // 實體路徑 (NAS 映射)
        $url_dir = '/nas/';    // 網頁讀取路徑

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

// 取得 GET 參數
$start_date_param = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$end_date_param = isset($_GET['end_date']) ? $_GET['end_date'] : '';

// 預設日期範圍：若兩者皆空，則預設為當前季度
// 預設日期範圍：若兩者皆空，則預設為當前年份至今 (解決日期過窄導致找不到舊資料的問題)
if ($start_date_param === '' && $end_date_param === '') {
    $current_month = (int)date('n');
    $current_year = (int)date('Y');
    
    if ($current_month >= 1 && $current_month <= 3) { // Q1
        $start_date = $current_year . '-01-01';
        $end_date = $current_year . '-03-31';
    } elseif ($current_month >= 4 && $current_month <= 6) { // Q2
        $start_date = $current_year . '-04-01';
        $end_date = $current_year . '-06-30';
    } elseif ($current_month >= 7 && $current_month <= 9) { // Q3
        $start_date = $current_year . '-07-01';
        $end_date = $current_year . '-09-30';
    } else { // Q4
        $start_date = $current_year . '-10-01';
        $end_date = $current_year . '-12-31';
    }
    $start_date = $current_year . '-01-01';
    $end_date = date('Y-m-d');
} else {
    $start_date = $start_date_param ?: date('Y-m-01');
    $end_date = $end_date_param ?: date('Y-m-d');
}

/* ── 帳款月份（2026-08-27 新增）────────────────────────────────────────────
 * 計算規則、權限與批次修改的唯一實作都在 src/common/billing_month_lib.php。
 * 這裡只做三件事：確保欄位存在、解析目前使用者權限、把值帶進畫面。 */
require_once __DIR__ . '/../../src/common/billing_month_lib.php';
eg_bm_ensure_schema($conn->getPDO());
$bm_user  = eg_bm_current_user($conn->getPDO());
$bm_perms = eg_bm_perms($conn->getPDO(), $bm_user);
$bm_csrf  = eg_bm_csrf_token();
$bm_set   = eg_bm_default_settlement($conn->getPDO());

// 查詢資料：bom_ing_transfer_log 結合 bom 取得客戶與規格資訊
$sql = "SELECT
        t.transfer_id,
        t.transfer_date,
        t.transfer_no,
        t.bom,
        t.bom_sn,
        t.maker_from,
        t.maker_to,
        t.sqty,
        t.product_id,
        t.transfer_qty,
        t.loss_qty,
        t.price,
        t.process_amount,
        t.tax_amount,
        t.paid_qty,
        t.invoice_date,
        t.invoice_ym,
        t.note,
        t.note2,
        t.bill_ym,
        t.bill_ym_manual,
        t.bill_ym_at,
        bu.user_cname AS bill_ym_by_name,
        t.changed_by,
        t.created_at,
        t.modified_at,
        b.Client_Name,
        b.specification,
        m.maker_id AS maker_from_name,
        pn.ProcessName,
        pn.process_type_id
    FROM bom_ing_transfer_log t
    LEFT JOIN bom b ON t.bom = b.bom
    LEFT JOIN maker_list m ON t.maker_from = m.maker_id_no
    LEFT JOIN bom_ing bi ON t.bom = bi.bom AND t.bom_sn = bi.bom_sn
    LEFT JOIN process_no pn ON bi.process_no = pn.ProcessNo
    LEFT JOIN `user` bu ON bu.id = t.bill_ym_by
    WHERE t.transfer_date BETWEEN :start_date AND :end_date
    ORDER BY t.transfer_date DESC, t.transfer_id DESC";

$stmt = $conn->getPDO()->prepare($sql);
$stmt->execute([':start_date' => $start_date, ':end_date' => $end_date]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* 帳款月份：DB 有值就用 DB 的；還沒回填的列即時算給畫面看（不寫 DB，避免每次開頁都在寫入）。
   bill_ym_src：db＝已寫入、auto＝畫面即時算、''＝連日期都解析不出來 */
foreach ($rows as &$__r) {
    if ($__r['bill_ym'] !== null && $__r['bill_ym'] !== '') {
        $__r['bill_ym_src'] = 'db';
    } else {
        $__r['bill_ym']     = eg_bm_calc_row($conn->getPDO(), $__r);
        $__r['bill_ym_src'] = $__r['bill_ym'] === null ? '' : 'auto';
    }
    $__r['bill_ym_label'] = eg_bm_ym_label($__r['bill_ym']);
    $__r['bill_ym_manual'] = (int)($__r['bill_ym_manual'] ?? 0);
}
unset($__r);

// 判斷圖表顯示單位 (日/週/月)
$date_diff = (strtotime($end_date) - strtotime($start_date)) / 86400;
$chart_group_by = 'day';
if ($date_diff > 60) {
    $chart_group_by = 'month';
} elseif ($date_diff > 30) {
    $chart_group_by = 'week';
}

// 資料分析變數初始化
$total_qty = 0;
$total_amount = 0;
$total_loss = 0;
$valid_count = 0;
$maker_stats = []; // 廠商統計
$chart_stats = []; // 時間趨勢
$product_stats = []; // 料號統計

foreach ($rows as $row) {
    $qty = floatval($row['transfer_qty']);
    $loss = floatval($row['loss_qty']);
    $price = floatval($row['price']);
    $amount = floatval($row['process_amount']);

    // 若 process_amount 為 0 但有單價與數量，則自動計算 (防呆)
    if ($amount == 0 && $qty > 0 && $price > 0) {
        $amount = $qty * $price;
    }

    $valid_count++;
    $total_qty += $qty;
    $total_loss += $loss;
    $total_amount += $amount;

    // 廠商統計 (Maker From)
    $maker = $row['maker_from_name'] ?: ($row['maker_from'] ?: '未知廠商');
    if (!isset($maker_stats[$maker])) $maker_stats[$maker] = 0;
    $maker_stats[$maker] += $amount;

    // 時間圖表統計
    $date = $row['transfer_date'];
    $key = $date;
    if ($chart_group_by == 'month') {
        $key = date('Y-m', strtotime($date));
    } elseif ($chart_group_by == 'week') {
        $key = date('Y/m/d', strtotime('monday this week', strtotime($date)));
    }
    if (!isset($chart_stats[$key])) $chart_stats[$key] = 0;
    $chart_stats[$key] += ($amount / 10000); // 單位：萬

    // 熱門料號統計
    $pid = trim($row['product_id'] ?? '');
    if ($pid == '') $pid = '未知料號';
    if (!isset($product_stats[$pid])) {
        $product_stats[$pid] = ['amount' => 0, 'qty' => 0, 'count' => 0, 'loss' => 0];
    }
    $product_stats[$pid]['amount'] += $amount;
    $product_stats[$pid]['qty'] += $qty;
    $product_stats[$pid]['loss'] += $loss;
    $product_stats[$pid]['count']++;
}

// 排序並取前5名廠商
arsort($maker_stats);
$top_makers = array_slice($maker_stats, 0, 5, true);

// 熱門加工料號排序 (依金額)
uasort($product_stats, function($a, $b) {
    return $b['amount'] <=> $a['amount'];
});
$top_products = array_slice($product_stats, 0, 10, true);

ksort($chart_stats);

// 準備圖表數據
$chart_dates = array_keys($chart_stats);
$chart_values = array_values($chart_stats);
$transfer_data_json = json_encode($rows);

/* ── 頁首資訊列 ───────────────────────────────────────────────
 * 1) 最新資料日期＝整張 bom_ing_transfer_log 的 MAX(transfer_date)（不受畫面日期區間影響）
 * 2) 最近一次更新加工單價＝Upload_List.php「更新加工單價 ERP原始檔直接匯入」(but=transfer_log_raw)
 *    的匯入紀錄，存放於 system_settings.setting_key='upload_transfer_log_raw'
 *    （欄位語意與姓名解析方式比照 Upload_List.php 的 lastUpdateBadge()：
 *      updated_by 實際存的是登入帳號，故優先用 updated_by_id 查 user.user_cname） */
require_once __DIR__ . '/../../src/common/date_fmt_lib.php';

$latest_data_date = null;
$total_log_rows   = 0;
try {
    $r = $conn->getPDO()->query("SELECT MAX(transfer_date) AS mx, COUNT(*) AS cnt FROM bom_ing_transfer_log");
    if ($row = $r->fetch(PDO::FETCH_ASSOC)) {
        $latest_data_date = $row['mx'];
        $total_log_rows   = (int)$row['cnt'];
    }
} catch (Exception $e) {}

$price_import = null;   // ['ts'=>..,'name'=>..]
try {
    $st = $conn->getPDO()->prepare("SELECT updated_at, updated_by, updated_by_id
                                    FROM system_settings WHERE setting_key = 'upload_transfer_log_raw' LIMIT 1");
    $st->execute();
    if ($pi = $st->fetch(PDO::FETCH_ASSOC)) {
        $who = trim((string)($pi['updated_by'] ?? ''));
        $uid = (string)($pi['updated_by_id'] ?? '');
        if ($uid !== '') {
            $su = $conn->getPDO()->prepare("SELECT user_cname FROM user WHERE id = ? LIMIT 1");
            $su->execute([$uid]);
            $cn = $su->fetchColumn();
            if ($cn) $who = $cn;
        }
        if ($who === '' && $pi['updated_by']) {   // 退回用登入帳號比對
            $su = $conn->getPDO()->prepare("SELECT user_cname FROM user WHERE user_uname = ? LIMIT 1");
            $su->execute([$pi['updated_by']]);
            $cn = $su->fetchColumn();
            if ($cn) $who = $cn;
        }
        $price_import = ['ts' => $pi['updated_at'], 'name' => ($who !== '' ? $who : '—')];
    }
} catch (Exception $e) {}

?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>製程移轉一覽表</title>

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
    <!-- Select2 -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.0.13/dist/css/select2.min.css" rel="stylesheet" />
    
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
        .x_title h2 {
            font-size: 18px;
            font-weight: bold;
        }
        #analysis-chart {
            height: 350px;
        }
        .table-responsive {
            overflow-x: auto;
        }
        #transferTable th, #transferTable td {
            white-space: nowrap;
            vertical-align: middle;
            font-size: 13px;
        }
        /* 外部篩選容器樣式 */
        #external-filter-container {
            display: flex;
            flex-wrap: nowrap;
            gap: 5px;
            margin-bottom: 10px;
            padding: 5px;
            background: #f1f1f1;
            border: 1px solid #ddd;
            align-items: center;
            overflow-x: auto;
        }
        #external-filter-container input {
            min-width: 80px;
            max-width: 120px;
            display: inline-block;
        }
        #external-filter-container .select2-container {
            width: 150px !important;
        }
        .highlight-row {
            background-color: #fff3cd !important;
            transition: background-color 0.5s ease-in-out;
        }
        /* 異常標示 */
        .text-anomaly {
            color: #d9534f;
            font-weight: bold;
        }
        /* 頁首資訊列（最新資料日期／最近一次更新加工單價） */
        .tl-infobar { display:flex; flex-wrap:wrap; gap:8px; align-items:center; clear:both; margin-bottom:10px; }
        .tl-info { display:inline-flex; align-items:center; gap:6px; font-size:13px; color:#5b3a1e;
            background:#FDF8EF; border:1.5px solid #E8D5B5; border-radius:8px; padding:6px 12px; }
        .tl-info b { color:#8A5A2B; font-size:14px; }
        .tl-info .tl-sub { color:#9b8676; }
        .tl-info small { color:#8a7a68; }
        /* 使用說明鈕（全站統一樣式） */
        .page-help-btn { height:30px; font-size:13px; padding:0 12px; border:1px solid #d98a33; border-radius:15px;
            background:#F0A24B; color:#fff; cursor:pointer; }
        .page-help-btn:hover { background:#d98a33; }
        @media print { .page-help-btn { display:none !important; } }
        .help-doc { font-size:13px; color:#5b3a1e; line-height:1.75; }
        .help-doc h4 { color:#8A5A2B; border-bottom:2px solid #F7E0BD; padding-bottom:3px; margin:14px 0 6px; font-size:15px; }
        .help-doc h4:first-child { margin-top:0; }
        .help-doc b { color:#8A5A2B; }
        .help-doc ul { margin:4px 0 8px; padding-left:20px; }
        .help-doc li { margin:2px 0; }
        .help-doc .tip { background:#FFF7E8; border:1px dashed #F0A24B; border-radius:6px; padding:6px 10px; margin:6px 0; }
        /* 帳款月份 */
        .bm-pick-col { width:34px; text-align:center; }
        #transferTable td.bm-pick-col input { margin:0; }
        .bm-ym { font-weight:bold; color:#8A5A2B; }
        .bm-manual { display:inline-block; margin-left:4px; padding:0 5px; border-radius:8px;
            font-size:11px; background:#F0A24B; color:#fff; }
        .bm-none { color:#bbb; }
        .bm-sel-badge { display:inline-block; padding:3px 8px; border-radius:10px; background:#F7E0BD;
            color:#5b3a1e; font-size:12px; margin-right:6px; }
        /* 批次修改跳窗 */
        .bm-form-row { display:flex; align-items:center; gap:8px; margin-bottom:10px; flex-wrap:wrap; }
        .bm-form-row label { margin:0; font-size:13px; color:#5b3a1e; min-width:80px; }
        .bm-err { color:#DD5138; font-size:12px; margin-left:4px; }
        .bm-hint { background:#FFF7E8; border:1px dashed #F0A24B; border-radius:6px; padding:6px 10px;
            font-size:12px; color:#5b3a1e; margin-bottom:10px; }
        /* 頁內分頁（明細／統計分析） */
        .tl-tabs { border-bottom:2px solid #E8D5B5; margin-bottom:12px; }
        .tl-tabs > li > a { color:#8A5A2B; font-size:14px; font-weight:bold; border:none; }
        .tl-tabs > li > a:hover { background:#FDF8EF; border:none; }
        .tl-tabs > li.active > a, .tl-tabs > li.active > a:hover, .tl-tabs > li.active > a:focus {
            color:#fff; background:#F0A24B; border:none; }
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
                        <div class="title_left" style="display:flex;align-items:center;width:100%;">
                            <h3 style="margin:0;">製程移轉一覽表 <small>Process Transfer List</small></h3>
                            <button id="btnPageHelp" class="page-help-btn" style="margin-left:auto;"><i class="fa fa-question-circle"></i> 使用說明</button>
                        </div>
                    </div>

                    <div class="clearfix"></div>

                    <!-- 資料狀態列：最新資料日期＋最近一次更新加工單價 -->
                    <div class="tl-infobar">
                        <span class="tl-info">
                            <i class="fa fa-calendar"></i> 最新資料日期：
                            <b><?= $latest_data_date ? eg_fmt_date($latest_data_date) : '尚無資料' ?></b>
                            <span class="tl-sub">（全表 <?= number_format($total_log_rows) ?> 筆）</span>
                        </span>
                        <span class="tl-info">
                            <i class="fa fa-upload"></i> 最近一次更新加工單價：
                            <?php if ($price_import): ?>
                                <b><?= eg_fmt_date($price_import['ts'], true) ?></b>
                                <span class="tl-sub">│</span>
                                <b><?= htmlspecialchars($price_import['name']) ?></b>
                            <?php else: ?>
                                <b>尚無記錄</b>
                            <?php endif; ?>
                            <small>（<a href="Upload_List.php" style="color:#8A5A2B;text-decoration:underline;">上傳頁匯入</a>）</small>
                        </span>
                    </div>

                    <!-- 篩選區塊 -->
                    <div class="row">
                        <div class="col-md-12 col-sm-12 col-xs-12">
                            <div class="x_panel">
                                <div class="x_title">
                                    <h2><i class="fa fa-filter"></i> 查詢條件</h2>
                                    <ul class="nav navbar-right panel_toolbox">
                                        <li><a class="collapse-link"><i class="fa fa-chevron-up"></i></a></li>
                                    </ul>
                                    <div style="float: right; margin-top: 5px;">
                                        <button type="button" class="btn btn-danger btn-sm" onclick="performLocalAnalysis()" style="margin-bottom: 0;"><i class="fa fa-search"></i> 異常偵測</button>
                                    </div>
                                    <div class="clearfix"></div>
                                </div>
                                <div class="x_content">
                                    <form method="GET" action="" class="form-inline" id="filterForm">
                                        <div class="form-group">
                                            <label for="start_date">日期範圍：</label>
                                            <input type="date" class="form-control" id="start_date" name="start_date" value="<?= $start_date ?>" onchange="this.form.submit()">
                                            <label for="end_date"> 至 </label>
                                            <input type="date" class="form-control" id="end_date" name="end_date" value="<?= $end_date ?>" onchange="this.form.submit()">
                                        </div>
                                        
                                        <div style="margin-top: 5px;">
                                            <button type="button" class="btn btn-info btn-sm" onclick="setQuickDate('thisMonth')">本月</button>
                                            <button type="button" class="btn btn-info btn-sm" onclick="setQuickDate('lastMonth')">上月</button>
                                            <button type="button" class="btn btn-info btn-sm" onclick="setQuickDate('thisYear')">今年</button>
                                            <button type="button" class="btn btn-default btn-sm" onclick="setQuickDate('q1')">Q1</button>
                                            <button type="button" class="btn btn-default btn-sm" onclick="setQuickDate('q2')">Q2</button>
                                            <button type="button" class="btn btn-default btn-sm" onclick="setQuickDate('q3')">Q3</button>
                                            <button type="button" class="btn btn-default btn-sm" onclick="setQuickDate('q4')">Q4</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 頁內分頁：移轉明細／統計分析（資料太多時分開看） -->
                    <ul class="nav nav-tabs tl-tabs" role="tablist">
                        <li role="presentation" class="active"><a href="#tab-detail" data-toggle="tab" role="tab"><i class="fa fa-list"></i> 移轉明細</a></li>
                        <li role="presentation"><a href="#tab-stats" data-toggle="tab" role="tab"><i class="fa fa-bar-chart"></i> 統計分析</a></li>
                    </ul>
                    <div class="tab-content">
                    <div role="tabpanel" class="tab-pane fade in active" id="tab-detail">

                    <!-- 統計數據磚 -->
                    <div class="row tile_count">
                        <div class="col-md-3 col-sm-4 col-xs-6 tile_stats_count">
                            <span class="count_top"><i class="fa fa-list-alt"></i> 移轉筆數</span>
                            <div class="count" id="stat-count"><?= number_format($valid_count) ?></div>
                            <span class="count_bottom">筆</span>
                        </div>
                        <div class="col-md-3 col-sm-4 col-xs-6 tile_stats_count">
                            <span class="count_top"><i class="fa fa-cubes"></i> 總加工數量</span>
                            <div class="count green" id="stat-qty"><?= number_format($total_qty) ?></div>
                            <span class="count_bottom">PCS (NG: <?= number_format($total_loss) ?>)</span>
                        </div>
                        <div class="col-md-3 col-sm-4 col-xs-6 tile_stats_count">
                            <?php
                                $display_amount = $total_amount;
                                $unit = '萬';
                                if ($total_amount >= 100000000) {
                                    $display_amount = $total_amount / 100000000;
                                    $unit = '億';
                                } else {
                                    $display_amount = $total_amount / 10000;
                                }
                            ?>
                            <span class="count_top"><i class="fa fa-money"></i> 總加工金額 <span id="amount-unit-title">(<?= $unit ?>)</span></span>
                            <div class="count blue" id="stat-amount"><?= number_format($display_amount, 2) ?></div>
                            <span class="count_bottom" id="amount-unit-bottom"><?= $unit ?>TWD</span>
                        </div>
                        <div class="col-md-3 col-sm-4 col-xs-6 tile_stats_count">
                            <span class="count_top"><i class="fa fa-wrench"></i> 加工廠商數</span>
                            <div class="count" id="stat-maker-count"><?= count($maker_stats) ?></div>
                            <span class="count_bottom">家</span>
                        </div>
                    </div>

                    <!-- 詳細資料表格 -->
                    <div class="row">
                        <div class="col-md-12 col-sm-12 col-xs-12">
                            <div class="x_panel">
                                <div class="x_title">
                                    <h2><i class="fa fa-list"></i> 移轉明細列表</h2>
                                    <div id="buttons-container" style="display: inline-block; margin-left: 20px;"></div>
<?php if ($bm_perms['canEdit']): ?>
                                    <div style="display:inline-block;margin-left:12px;">
                                        <span id="bm-sel-count" class="bm-sel-badge">已勾選 0 筆</span>
                                        <button type="button" class="btn btn-warning btn-sm" id="btnBmBatch" style="margin-bottom:0;"><i class="fa fa-calendar-o"></i> 批次修改帳款月份</button>
                                        <button type="button" class="btn btn-default btn-sm" id="btnBmReset" style="margin-bottom:0;"><i class="fa fa-undo"></i> 還原為自動</button>
<?php if ($bm_perms['canAdmin']): ?>
                                        <button type="button" class="btn btn-default btn-sm" id="btnBmRecalc" style="margin-bottom:0;" title="依 J- 單號日期與各廠商結帳日重新計算（手動指定過的不會被蓋掉）"><i class="fa fa-refresh"></i> 重算</button>
<?php endif; ?>
                                    </div>
<?php endif; ?>
                                    <ul class="nav navbar-right panel_toolbox">
                                        <li><a class="collapse-link"><i class="fa fa-chevron-up"></i></a></li>
                                    </ul>
                                    <div class="clearfix"></div>
                                </div>
                                <div class="x_content">
                                    <div class="table-responsive">
                                        <!-- 外部篩選容器 -->
                                        <div id="external-filter-container">
                                            <input type="text" id="filter-date" class="form-control input-sm" placeholder="日期">
                                            <input type="text" id="filter-transfer-no" class="form-control input-sm" placeholder="單號">
                                            <input type="text" id="filter-bom" class="form-control input-sm" placeholder="BOM">
                                            <input type="text" id="filter-product" class="form-control input-sm" placeholder="料號">
                                            <select id="filter-maker" class="form-control input-sm" multiple="multiple">
                                                <!-- JS Populated -->
                                            </select>
                                            <input type="text" id="filter-note" class="form-control input-sm" placeholder="備註">
                                            <input type="text" id="filter-billym" class="form-control input-sm" placeholder="帳款月份 202608">
                                            <select id="filter-billym-manual" class="form-control input-sm" style="width:110px;">
                                                <option value="">帳款月份全部</option>
                                                <option value="1">只看手動</option>
                                                <option value="0">只看自動</option>
                                            </select>
                                            <input type="text" id="global-search" class="form-control input-sm" placeholder="全域搜索">
                                            <button type="button" class="btn btn-default btn-sm" id="clear-filters" style="margin-bottom: 0;">取消</button>
                                        </div>

                                        <table id="transferTable" class="table table-striped table-bordered dt-responsive nowrap" cellspacing="0" width="100%">
                                            <thead>
                                                <tr>
                                                    <th style="display:none;">ID</th>
                                                    <th class="bm-pick-col"><input type="checkbox" id="bm-check-all" title="全選/取消（目前篩選出來的全部）"></th>
                                                    <th>日期</th>
                                                    <th>單號</th>
                                                    <th>BOM</th>
                                                    <th>料號</th>
                                                    <th>製程</th>
                                                    <th>廠商 (From)</th>
                                                    <th>發包數量</th>
                                                    <th>報工數量</th>
                                                    <th>NG</th>
                                                    <th>單價</th>
                                                    <th>金額</th>
                                                    <th>付款數量</th>
                                                    <th>發票日期</th>
                                                    <th>發票年月</th>
                                                    <th>帳款月份</th>
                                                    <th>備註</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    </div><!-- /#tab-detail -->

                    <div role="tabpanel" class="tab-pane fade" id="tab-stats">

                    <!-- 圖表分析 -->
                    <div class="row">
                        <div class="col-md-8 col-sm-12 col-xs-12">
                            <div class="x_panel">
                                <div class="x_title">
                                    <h2><i class="fa fa-bar-chart"></i> 加工金額趨勢 (<?= $chart_group_by == 'month' ? '月' : ($chart_group_by == 'week' ? '週' : '日') ?>)</h2>
                                    <div class="clearfix"></div>
                                </div>
                                <div class="x_content">
                                    <div id="analysis-chart"></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4 col-sm-12 col-xs-12">
                            <div class="x_panel">
                                <div class="x_title">
                                    <h2><i class="fa fa-trophy"></i> 前五大加工廠商</h2>
                                    <div class="clearfix"></div>
                                </div>
                                <div class="x_content">
                                    <table class="table table-striped">
                                        <thead>
                                            <tr>
                                                <th>廠商</th>
                                                <th class="text-right">金額(萬)</th>
                                            </tr>
                                        </thead>
                                        <tbody id="top-makers-body">
                                            <?php foreach ($top_makers as $maker => $amount): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($maker) ?></td>
                                                <td class="text-right">$<?= number_format($amount / 10000, 2) ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 熱銷產品 (加工成本) -->
                    <div class="row">
                        <div class="col-md-12 col-sm-12 col-xs-12">
                            <div class="x_panel">
                                <div class="x_title">
                                    <h2><i class="fa fa-star"></i> 十大高加工成本料號</h2>
                                    <div class="clearfix"></div>
                                </div>
                                <div class="x_content" style="height: 300px; overflow-y: auto;">
                                    <table class="table table-striped table-hover">
                                        <thead>
                                            <tr>
                                                <th width="50">排名</th>
                                                <th>料號</th>
                                                <th class="text-right">總金額(萬)</th>
                                                <th class="text-right">總數量</th>
                                                <th class="text-right">平均單價</th>
                                                <th class="text-right">NG數</th>
                                            </tr>
                                        </thead>
                                        <tbody id="top-products-tbody">
                                            <?php 
                                            $rank = 1;
                                            foreach ($top_products as $pid => $stats): 
                                                $avg_price = $stats['qty'] > 0 ? $stats['amount'] / $stats['qty'] : 0;
                                                $safePid = str_replace("'", "\\'", $pid);
                                                $displayPid = htmlspecialchars($pid);
                                            ?>
                                            <tr>
                                                <td><?= $rank++ ?></td>
                                                <td><a href="javascript:void(0);" onclick="openProductFiles('<?= $safePid ?>')" style="text-decoration: underline; color: #337ab7;"><?= $displayPid ?></a></td>
                                                <td class="text-right">$<?= number_format($stats['amount'] / 10000, 2) ?></td>
                                                <td class="text-right"><?= number_format($stats['qty']) ?></td>
                                                <td class="text-right">$<?= number_format($avg_price, 2) ?></td>
                                                <td class="text-right"><?= number_format($stats['loss']) ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    </div><!-- /#tab-stats -->
                    </div><!-- /.tab-content -->

                </div>
            </div>
            <!-- /page content -->

            <!-- footer content -->
            <?php include '../partPage/footer.html' ?>
            <!-- /footer content -->
        </div>
    </div>

    <!-- BOM 圖檔 Modal -->
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

    <!-- 異常偵測結果 Modal -->
    <div class="modal fade" id="analysisResultModal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                    <h4 class="modal-title"><i class="fa fa-search"></i> 異常偵測報告</h4>
                </div>
                <div class="modal-body" id="analysis-result-body" style="max-height: 70vh; overflow-y: auto;">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal">關閉</button>
                </div>
            </div>
        </div>
    </div>

<?php if ($bm_perms['canEdit']): ?>
    <!-- 批次修改帳款月份 Modal -->
    <div class="modal fade" id="bmBatchModal" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                    <h4 class="modal-title"><i class="fa fa-calendar-o"></i> 批次修改帳款月份</h4>
                </div>
                <div class="modal-body">
                    <div class="bm-hint">
                        將修改勾選的 <b id="bm-batch-count">0</b> 筆。改過的資料列會標記為<b>「手動」</b>，
                        之後<b>重新匯入 ERP 或按重算都不會被蓋掉</b>；要恢復自動計算請用「還原為自動」。
                    </div>
                    <div class="bm-form-row">
                        <label><input type="radio" name="bm-mode" value="set" checked> 指定月份</label>
                        <label style="min-width:auto;"><input type="radio" name="bm-mode" value="shift"> 整批平移</label>
                    </div>
                    <div class="bm-form-row" id="bm-set-row">
                        <label>帳款月份</label>
                        <input type="text" id="bm-year" class="form-control input-sm" style="width:90px;"
                               value="<?= date('Y') ?>" maxlength="4" placeholder="西元年">
                        <span>年</span>
                        <select id="bm-month" class="form-control input-sm" style="width:80px;">
                            <?php for ($i = 1; $i <= 12; $i++): $mm = sprintf('%02d', $i); ?>
                            <option value="<?= $mm ?>"<?= $i == (int)date('n') ? ' selected' : '' ?>><?= $mm ?></option>
                            <?php endfor; ?>
                        </select>
                        <span>月</span>
                    </div>
                    <div class="bm-form-row" id="bm-shift-row" style="display:none;">
                        <label>平移月數</label>
                        <input type="number" id="bm-shift" class="form-control input-sm" style="width:90px;" value="1" step="1">
                        <span style="font-size:12px;color:#8a7a68;">正數＝往後（12 月 +1 會變成隔年 1 月）、負數＝往前</span>
                    </div>
                    <div class="bm-form-row"><span id="bm-err" class="bm-err"></span></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
                    <button type="button" class="btn btn-warning" id="bmBatchSubmit"><i class="fa fa-check"></i> 確定修改</button>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

    <!-- 使用說明 Modal（鐵律7） -->
    <div class="modal fade" id="helpUseMask" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                    <h4 class="modal-title"><i class="fa fa-question-circle"></i> 製程移轉一覽表 使用說明</h4>
                </div>
                <div class="modal-body help-doc" style="max-height:70vh;overflow-y:auto;">
                    <h4>這一頁在看什麼</h4>
                    <p>本頁列出 ERP 的<b>製程移轉憑單</b>匯入後的所有移轉紀錄（資料表 <code>bom_ing_transfer_log</code>），
                       並自動帶出每一筆對應的 BOM 資料：客戶、規格、製程名稱、加工廠商，以及<b>加工單價與金額</b>。</p>
                    <div class="tip">
                        資料來源＝<b>Upload_List.php</b>（上傳頁）的「更新加工單價 <b>ERP原始檔直接匯入</b>」。
                        本頁只讀不寫，要更新資料請到上傳頁匯入新的 ERP 原始檔。
                    </div>

                    <h4>頁首兩個日期怎麼看</h4>
                    <ul>
                        <li><b>最新資料日期</b>：整張表最新一筆的<b>移轉日期</b>（不受下方日期區間影響），用來判斷「資料已經匯到哪一天」。</li>
                        <li><b>最近一次更新加工單價</b>：上一次在上傳頁執行匯入的<b>時間與人員</b>。
                            若這個時間很舊、而現場已經有新的移轉單，代表該重新匯入了。</li>
                    </ul>

                    <h4>帳款月份怎麼算出來的</h4>
                    <ul>
                        <li><b>日期</b>：一律從 <b>J- 單號</b>解析。例：<code>J-1150819055</code> → 民國 115/08/19 →
                            115+1911＝<b>2026.08.19</b>。單號不是 J- 格式時才改用資料表上的日期欄。</li>
                        <li><b>結帳日</b>：<b>該廠商主檔自己設的優先</b>，沒設才用「主檔管理 → 類別字典設定 → 基本設定」的
                            <b>廠商預設結帳日</b>（目前是 <?= (int)$bm_set['day'] ?> 號）。</li>
                        <li><b>區間</b>：結帳日 D ⇒ 上月 D+1 ～ 本月 D 都算本月帳。
                            以 20 號為例，<b>7/21～8/20 都是 8 月帳</b>，8/21 起就變成 9 月帳；
                            <b>12 月會自動跨到隔年 1 月</b>。結帳日大於當月天數時（例如 31 號遇到 2 月）自動視為該月最後一天。</li>
                        <li>ERP 匯入檔<b>沒有帳款月份這一欄</b>，所以一律由系統依上述規則自動算；
                            每次在上傳頁匯入加工單價時，新進來的資料會自動補上。</li>
                    </ul>

                    <h4>手動修改帳款月份</h4>
                    <ul>
                        <li>在「移轉明細」勾選要改的列（<b>全選</b>＝目前篩選出來的全部，不只這一頁），
                            按<b>「批次修改帳款月份」</b>：可<b>指定某年某月</b>，或<b>整批平移 N 個月</b>（正數往後、負數往前，會自動跨年）。</li>
                        <li>改過的列會標上橘色<b>「手動」</b>標記（滑鼠移上去看得到是誰、什麼時候改的）。
                            <b>手動指定過的列，之後重新匯入 ERP 或按重算都不會被蓋掉。</b></li>
                        <li>要恢復系統自動算的值，勾選後按<b>「還原為自動」</b>（清掉手動標記並立刻重算）。</li>
                        <li>篩選列可用<b>帳款月份</b>關鍵字（打 202608、2026.08、2026-08 或只打 08 都可以），
                            以及<b>只看手動／只看自動</b>。</li>
                    </ul>

                    <h4>操作步驟</h4>
                    <ul>
                        <li><b>選日期區間</b>：頁面上方「查詢條件」選起訖日期（或按 本月／上月／今年／Q1~Q4 快速鈕），送出後重新查詢。
                            預設是<b>今年 1/1 至今</b>，要看更早的資料請自行往前調。</li>
                        <li><b>移轉明細分頁</b>：上方四格是<b>目前篩選結果</b>的合計（筆數／數量／金額／廠商數），會隨篩選即時變動；
                            下方列表可用逐欄篩選（日期／單號／BOM／料號／備註）、廠商多選、以及最右的<b>全域搜索</b>；
                            欄位有值時<b>雙擊即可清空該欄篩選</b>，或按「取消」清掉全部條件。</li>
                        <li><b>匯出</b>：列表標題右側有 複製／CSV／Excel／列印 四顆鈕，匯出的是<b>目前篩選後</b>的內容。</li>
                        <li><b>統計分析分頁</b>：加工金額趨勢圖（依區間長短自動切日／週／月）、前五大加工廠商、十大高加工成本料號。
                            <b>點趨勢圖的柱子</b>會把明細列表篩成該區間；料號可點開查看對應的 BOM 圖檔。</li>
                        <li><b>異常偵測</b>：在「查詢條件」右上角，會針對目前資料檢查單價異常等狀況並列出報告。</li>
                    </ul>

                    <h4>重要行為</h4>
                    <ul>
                        <li>金額若 ERP 沒帶（process_amount＝0）但有單價與數量時，系統會自動以<b>數量×單價</b>補算，避免統計短少。</li>
                        <li>統計磚、趨勢圖、前五大廠商都是跟著<b>篩選後</b>的資料重算；十大料號為進頁當下的區間統計。</li>
                        <li>全表目前共 <?= number_format($total_log_rows) ?> 筆，最早可追溯到 2018 年，一次查太大區間會比較慢。</li>
                    </ul>

                    <h4>權限</h4>
                    <ul>
                        <li><b>檢視</b>：只要能登入且左側選單看得到就能看（登記於「測試功能」群組）。
                            請注意本頁會顯示<b>加工單價與金額</b>。</li>
                        <li><b>帳款月份維護</b>（ptl_bill_edit）：可批次修改帳款月份、還原為自動。</li>
                        <li><b>製程移轉管理員</b>（ptl_admin）：以上全部＋<b>重算</b>（整批依規則重新計算，手動指定過的一律不動）。</li>
                        <li>角色在<a href="../user/user_permissions.php" target="_blank" style="color:#b5762a;">使用者權限設定</a>指派；
                            管理者固定擁有全部權限。沒有權限的人看不到勾選欄與批次按鈕，
                            直接打 API 也會被後端擋下。</li>
                    </ul>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal">我知道了</button>
                </div>
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
    <script src="../../resource/js/dataTables.buttons.min.js"></script>
    <script src="../../resource/js/buttons.flash.min.js"></script>
    <script src="../../resource/js/buttons.html5.min.js"></script>
    <script src="../../resource/js/buttons.print.min.js"></script>
    <script src="../../resource/js/jszip.min.js"></script>
    <script src="../../resource/js/pdfmake.min.js"></script>
    <script src="../../resource/js/vfs_fonts.js"></script>
    <!-- Select2 -->
    <script src="https://cdn.jsdelivr.net/npm/select2@4.0.13/dist/js/select2.min.js"></script>

    <!-- Highcharts -->
    <script src="../../code/highcharts.js"></script>
    <script src="../../code/modules/exporting.js"></script>
    <script src="../../code/modules/export-data.js"></script>
    <script src="../../code/modules/accessibility.js"></script>

    <script>
        var transferData = <?= $transfer_data_json ?>;
        var chartGroupBy = '<?= $chart_group_by ?>';
        var currentChartFilter = null;

        /* ── 帳款月份 ─────────────────────────────────────────────
         * 權限由後端算好帶進來；沒有維護權限時勾選欄整欄不顯示、工具列也不輸出，
         * 後端 API 仍會用同一套規則再擋一次（鐵律8）。 */
        var BM_CAN_EDIT  = <?= $bm_perms['canEdit']  ? 'true' : 'false' ?>;
        var BM_CAN_ADMIN = <?= $bm_perms['canAdmin'] ? 'true' : 'false' ?>;
        var BM_CSRF      = <?= json_encode($bm_csrf) ?>;
        var BM_API       = '../../src/store/TransferBilling_API.php';
        var BM_DEF_DAY   = <?= (int)$bm_set['day'] ?>;
        var bmSelected   = {};   // transfer_id => true（跨分頁保留勾選）

        $(document).ready(function() {
            // 填充廠商篩選下拉選單
            var uniqueMakers = [...new Set(transferData.map(item => item.maker_from_name || item.maker_from || ''))].filter(x => x).sort();
            var makerSelect = $('#filter-maker');
            uniqueMakers.forEach(function(m) {
                makerSelect.append(new Option(m, m));
            });
            
            $('#filter-maker').select2({
                placeholder: "廠商 (多選)",
                allowClear: true,
                width: '150px'
            }).on('change', function() {
                table.draw();
            });

            // 初始化 DataTable
            var table = $('#transferTable').DataTable({
                dom: 'Brtip',
                data: transferData,
                deferRender: true,
                columns: [
                    { data: 'transfer_id', visible: false },
                    {   // 勾選欄（沒有維護權限時整欄不顯示）
                        data: 'transfer_id', orderable: false, searchable: false,
                        className: 'bm-pick-col', visible: BM_CAN_EDIT,
                        render: function(data) {
                            return '<input type="checkbox" class="bm-pick" value="' + data + '"'
                                 + (bmSelected[data] ? ' checked' : '') + '>';
                        }
                    },
                    { data: 'transfer_date' },
                    { data: 'transfer_no', render: $.fn.dataTable.render.text() },
                    { data: 'bom', render: $.fn.dataTable.render.text() },
                    { 
                        data: 'product_id', 
                        render: function(data, type, row) {
                            if (!data) return '';
                            return '<a href="javascript:void(0);" onclick="openProductFiles(\'' + data + '\')" style="text-decoration: underline; color: #337ab7;">' + data + '</a>';
                        }
                    },
                    { data: 'ProcessName', render: $.fn.dataTable.render.text() },
                    { data: 'maker_from_name', render: function(data, type, row) { return data || row.maker_from || ''; } },
                    { data: 'sqty', className: 'text-right', render: $.fn.dataTable.render.number(',', '.', 0) },
                    { data: 'transfer_qty', className: 'text-right', render: $.fn.dataTable.render.number(',', '.', 0) },
                    { 
                        data: 'loss_qty', 
                        className: 'text-right', 
                        render: function(data, type, row) {
                            var val = parseFloat(data) || 0;
                            return val > 0 ? '<span class="text-danger">' + val + '</span>' : val;
                        }
                    },
                    { 
                        data: 'price', 
                        className: 'text-right', 
                        render: function(data, type, row) {
                            var val = parseFloat(data);
                            if (val === 0) return '<span class="text-anomaly">0</span>';
                            return $.fn.dataTable.render.number(',', '.', 2).display(val);
                        }
                    },
                    { 
                        data: 'process_amount', 
                        className: 'text-right', 
                        visible: false,
                        render: function(data, type, row) {
                            var val = parseFloat(data);
                            if (isNaN(val)) return '';
                            // 若是整數則不顯示小數點，否則顯示 2 位小數
                            if (Number.isInteger(val)) {
                                return $.fn.dataTable.render.number(',', '.', 0).display(val);
                            } else {
                                // 移除尾端多餘的 0 (例如 10.50 -> 10.5)
                                return $.fn.dataTable.render.number(',', '.', 2).display(val).replace(/\.?0+$/, '');
                            }
                        }
                    },
                    { data: 'paid_qty', className: 'text-right', render: $.fn.dataTable.render.number(',', '.', 0) },
                    { data: 'invoice_date', visible: false },
                    { data: 'invoice_ym', visible: false },
                    {   // 帳款月份：DB 已寫入或畫面即時算出的值；人工指定過會加「手動」標記
                        data: 'bill_ym_label',
                        render: function(data, type, row) {
                            if (type !== 'display') return row.bill_ym || '';
                            if (!data) return '<span class="bm-none">—</span>';
                            var html = '<span class="bm-ym">' + data + '</span>';
                            if (parseInt(row.bill_ym_manual, 10) === 1) {
                                var t = '手動指定';
                                if (row.bill_ym_by_name) t += '：' + row.bill_ym_by_name;
                                if (row.bill_ym_at) t += ' ' + row.bill_ym_at;
                                html += '<span class="bm-manual" title="' + t + '">手動</span>';
                            }
                            return html;
                        }
                    },
                    { 
                        data: 'note', 
                        render: function(data, type, row) {
                            if (!data) return '';
                            let note = data;
                            // 忽略 T--000
                            note = note.replace(/T--000/g, '');
                            // 轉換 O-OO1110321005-001 為 OO1110321005
                            // 邏輯：O- 開頭，中間是訂單號，後面接 -數字
                            note = note.replace(/O-([A-Z0-9]+)(-\d+)?/g, '$1');
                            return note;
                        }
                    }
                ],
                buttons: [
                    { extend: 'copy', className: 'btn btn-default btn-sm' },
                    { extend: 'csv', className: 'btn btn-default btn-sm' },
                    { extend: 'excel', className: 'btn btn-default btn-sm', title: '加工成本紀錄' },
                    { extend: 'print', className: 'btn btn-default btn-sm' }
                ],
                pageLength: 20,
                orderCellsTop: true,
                order: [[2, 'desc']],
                language: {
                    "url": "//cdn.datatables.net/plug-ins/1.10.20/i18n/Chinese-traditional.json"
                }
            });

            table.buttons().container().appendTo('#buttons-container');

            // 綁定外部篩選
            $('#global-search').on('keyup change', function() { table.search(this.value).draw(); });
            $('#filter-date').on('keyup change', function() { table.draw(); });
            $('#filter-transfer-no').on('keyup change', function() { table.column(3).search(this.value).draw(); });
            $('#filter-bom').on('keyup change', function() { table.column(4).search(this.value).draw(); });
            $('#filter-product').on('keyup change', function() { table.column(5).search(this.value).draw(); });
            $('#filter-note').on('keyup change', function() { table.column(17).search(this.value).draw(); });

            // 雙擊清除
            $('#external-filter-container input').on('dblclick', function() {
                $(this).val('').trigger('change');
            });

            $('#filter-billym').on('keyup change', function() { table.draw(); });
            $('#filter-billym-manual').on('change', function() { table.draw(); });

            $('#clear-filters').click(function() {
                $('#external-filter-container input[type="text"]').val('');
                $('#filter-maker').val(null).trigger('change');
                $('#filter-billym-manual').val('');
                currentChartFilter = null;
                table.search('').columns().search('').draw();
            });

            // 帳款月份篩選（可打 202608、2026.08、2026-08、或只打 08）
            $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
                var kw = ($('#filter-billym').val() || '').replace(/[.\-\/\s]/g, '');
                var mn = $('#filter-billym-manual').val();
                var row = settings.aoData[dataIndex]._aData;
                if (mn !== '' && String(parseInt(row.bill_ym_manual, 10) || 0) !== mn) return false;
                if (!kw) return true;
                return String(row.bill_ym || '').indexOf(kw) >= 0;
            });

            // 廠商篩選邏輯
            $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
                var selectedMakers = $('#filter-maker').val();
                if (!selectedMakers || selectedMakers.length === 0) return true;
                var rowData = settings.aoData[dataIndex]._aData;
                var maker = rowData.maker_from_name || rowData.maker_from || '';
                return selectedMakers.includes(maker);
            });

            // 日期篩選邏輯 (同 Shipping_Analysis)
            $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
                var input = $('#filter-date').val();
                if (!input) return true;
                var val = input.trim();
                var op = '=';
                if (val.startsWith('>')) { op = '>'; val = val.substring(1).trim(); }
                else if (val.startsWith('<')) { op = '<'; val = val.substring(1).trim(); }
                else if (val.startsWith('=')) { op = '='; val = val.substring(1).trim(); }

                var parts = val.split(/[\/\-]/);
                var year, month, day;
                var now = new Date();
                
                if (parts.length === 2) { year = now.getFullYear(); month = parseInt(parts[0], 10); day = parseInt(parts[1], 10); }
                else if (parts.length === 3) { year = parseInt(parts[0], 10); if (year < 100) year += 2000; month = parseInt(parts[1], 10); day = parseInt(parts[2], 10); }
                else return true;

                if (isNaN(year) || isNaN(month) || isNaN(day)) return true;
                var filterDate = new Date(year, month - 1, day);
                filterDate.setHours(0,0,0,0);

                var rowDateStr = data[2]; // 日期欄位（勾選欄插在 index 1，所以日期是 2）
                var rowDate = new Date(rowDateStr);
                rowDate.setHours(0,0,0,0);

                if (op === '>') return rowDate > filterDate;
                if (op === '<') return rowDate < filterDate;
                return rowDate.getTime() === filterDate.getTime();
            });

            // 圖表篩選邏輯
            $.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
                if (!currentChartFilter) return true;
                var rowData = settings.aoData[dataIndex]._aData;
                var dateStr = rowData.transfer_date;
                var key = getDateKey(dateStr);
                return key === currentChartFilter;
            });

            // 初始化 Highcharts
            Highcharts.chart('analysis-chart', {
                chart: { type: 'column' },
                title: { text: '加工金額趨勢' },
                xAxis: {
                    categories: <?php echo json_encode($chart_dates); ?>,
                    crosshair: true,
                    title: { text: '日期/區間' }
                },
                yAxis: { min: 0, title: { text: '金額 (萬TWD)' } },
                tooltip: {
                    headerFormat: '<span style="font-size:10px">{point.key}</span><table>',
                    pointFormat: '<tr><td style="color:{series.color};padding:0">{series.name}: </td><td style="padding:0"><b>${point.y:,.2f} 萬</b></td></tr>',
                    footerFormat: '</table>',
                    shared: true,
                    useHTML: true
                },
                plotOptions: {
                    column: {
                        pointPadding: 0.2,
                        borderWidth: 0,
                        cursor: 'pointer',
                        point: {
                            events: {
                                click: function () {
                                    applyChartFilter(this.category);
                                }
                            }
                        }
                    }
                },
                series: [{
                    name: '加工金額',
                    data: <?php echo json_encode($chart_values); ?>,
                    color: '#337ab7'
                }],
                credits: { enabled: false }
            });

            // 更新統計
            table.on('draw', updateStatistics);

            /* 切換到「統計分析」分頁時要 reflow：
             * Highcharts 在 display:none 的容器內初始化會量到寬度 0，畫出來只有一條線；
             * DataTables 的欄寬同理，切回明細分頁要重算一次。 */
            $('a[data-toggle="tab"]').on('shown.bs.tab', function(e) {
                var target = $(e.target).attr('href');
                if (target === '#tab-stats') {
                    Highcharts.charts.forEach(function(c) { if (c) c.reflow(); });
                } else if (target === '#tab-detail') {
                    table.columns.adjust();
                }
            });

            /* ── 帳款月份：勾選與批次動作 ───────────────────────── */
            if (BM_CAN_EDIT) {
                // 單列勾選（DataTables 重繪後 checkbox 會重畫，所以用事件委派）
                $('#transferTable tbody').on('change', '.bm-pick', function() {
                    var id = this.value;
                    if (this.checked) bmSelected[id] = true; else delete bmSelected[id];
                    bmUpdateCount();
                });
                // 全選＝目前篩選出來的全部（不是只有這一頁）
                $('#bm-check-all').on('change', function() {
                    var on = this.checked;
                    table.rows({ search: 'applied' }).every(function() {
                        var id = this.data().transfer_id;
                        if (on) bmSelected[id] = true; else delete bmSelected[id];
                    });
                    $('#transferTable tbody .bm-pick').prop('checked', on);
                    bmUpdateCount();
                });
                // 換頁/篩選後把勾選狀態畫回來
                table.on('draw', function() {
                    $('#transferTable tbody .bm-pick').each(function() {
                        this.checked = !!bmSelected[this.value];
                    });
                    $('#bm-check-all').prop('checked', false);
                });

                $('#btnBmBatch').on('click', function() {
                    if (!bmSelectedIds().length) { alert('請先勾選要修改的資料列。'); return; }
                    $('#bm-batch-count').text(bmSelectedIds().length);
                    $('#bm-err').text('');
                    $('#bmBatchModal').modal('show');
                });
                $('#btnBmReset').on('click', function() {
                    var ids = bmSelectedIds();
                    if (!ids.length) { alert('請先勾選要還原的資料列。'); return; }
                    if (!confirm('要把勾選的 ' + ids.length + ' 筆還原為自動計算嗎？\n（會清掉「手動」註記，改用 J- 單號日期＋該廠商結帳日重算）')) return;
                    bmPost({ action: 'reset_auto', ids: ids.join(',') });
                });
                $('#btnBmRecalc').on('click', function() {
                    if (!BM_CAN_ADMIN) return;
                    var onlyEmpty = confirm('要「只補還沒有帳款月份的資料」嗎？\n\n按「確定」＝只補空的（快）\n按「取消」＝整批重算（會把自動計算的全部重新算一次，手動指定過的一律不動）');
                    bmPost({ action: 'recalc', only_empty: onlyEmpty ? 1 : 0 });
                });

                // 送出批次修改（前端先驗一次，後端 API 會用同一套規則再驗）
                $('#bmBatchSubmit').on('click', function() {
                    var ids = bmSelectedIds();
                    if (!ids.length) { $('#bm-err').text('沒有勾選任何資料列'); return; }
                    var mode = $('input[name="bm-mode"]:checked').val();
                    var p = { action: 'set_month', ids: ids.join(','), mode: mode };
                    if (mode === 'set') {
                        var y = ($('#bm-year').val() || '').trim(), m = $('#bm-month').val();
                        if (!/^\d{4}$/.test(y) || +y < 1990 || +y > 2200) { $('#bm-err').text('年份要是 4 位西元年（1990~2200）'); return; }
                        p.ym = y + m;
                    } else {
                        var n = parseInt($('#bm-shift').val(), 10);
                        if (!n || isNaN(n)) { $('#bm-err').text('平移月數不可為 0 或空白'); return; }
                        if (n < -60 || n > 60) { $('#bm-err').text('平移月數要在 -60 ~ 60 之間'); return; }
                        p.shift = n;
                    }
                    $('#bm-err').text('');
                    bmPost(p, function() { $('#bmBatchModal').modal('hide'); });
                });
                $('input[name="bm-mode"]').on('change', function() {
                    var isSet = $('input[name="bm-mode"]:checked').val() === 'set';
                    $('#bm-set-row').toggle(isSet);
                    $('#bm-shift-row').toggle(!isSet);
                });
            }

            // 使用說明
            $('#btnPageHelp').on('click', function() { $('#helpUseMask').modal('show'); });
        });

        /* ── 帳款月份共用函式 ─────────────────────────────────── */
        function bmSelectedIds() { return Object.keys(bmSelected); }

        function bmUpdateCount() {
            $('#bm-sel-count').text('已勾選 ' + bmSelectedIds().length + ' 筆');
        }

        function bmPost(payload, onOk) {
            payload.csrf = BM_CSRF;
            var $btns = $('#btnBmBatch, #btnBmReset, #btnBmRecalc, #bmBatchSubmit').prop('disabled', true);
            $.post(BM_API, payload, null, 'json')
                .done(function(res) {
                    if (!res || !res.ok) { alert((res && res.error) || '處理失敗'); return; }
                    alert(res.msg || '完成');
                    if (onOk) onOk();
                    location.reload();   // 重新查一次，帳款月份與「手動」標記才會是最新的
                })
                .fail(function(xhr) {
                    var m = '處理失敗';
                    try { m = JSON.parse(xhr.responseText).error || m; } catch (e) {}
                    alert(m);
                })
                .always(function() { $btns.prop('disabled', false); });
        }

        function getDateKey(dateStr) {
            var parts = dateStr.split('-');
            var d = new Date(parts[0], parts[1]-1, parts[2]);
            if (chartGroupBy === 'month') {
                var m = d.getMonth() + 1;
                return d.getFullYear() + '-' + (m < 10 ? '0' + m : m);
            } else if (chartGroupBy === 'week') {
                var day = d.getDay(), diff = d.getDate() - day + (day == 0 ? -6 : 1); 
                var monday = new Date(d.setDate(diff));
                var mm = monday.getMonth() + 1;
                var dd = monday.getDate();
                return monday.getFullYear() + '/' + (mm < 10 ? '0' + mm : mm) + '/' + (dd < 10 ? '0' + dd : dd);
            } else {
                return dateStr;
            }
        }

        function applyChartFilter(category) {
            currentChartFilter = category;
            $('#transferTable').DataTable().draw();
            $('html, body').animate({ scrollTop: $('#transferTable_wrapper').offset().top - 100 }, 500);
        }

        function updateStatistics() {
            var table = $('#transferTable').DataTable();
            var data = table.rows({ search: 'applied' }).data().toArray();
            
            var totalQty = 0;
            var totalAmount = 0;
            var totalLoss = 0;
            var validCount = 0;
            var makerStats = {};
            var chartStats = {};
            
            data.forEach(function(row) {
                var qty = parseFloat(row.transfer_qty) || 0;
                var loss = parseFloat(row.loss_qty) || 0;
                var price = parseFloat(row.price) || 0;
                var amount = parseFloat(row.process_amount) || (qty * price);
                
                validCount++;
                totalQty += qty;
                totalLoss += loss;
                totalAmount += amount;
                
                var maker = row.maker_from_name || row.maker_from || '未知廠商';
                if (!makerStats[maker]) makerStats[maker] = 0;
                makerStats[maker] += amount;
                
                var key = getDateKey(row.transfer_date);
                if (!chartStats[key]) chartStats[key] = 0;
                chartStats[key] += (amount / 10000);
            });
            
            $('#stat-count').text(numberFormat(validCount));
            $('#stat-qty').text(numberFormat(totalQty));
            
            var displayAmount = totalAmount;
            var unit = '萬';
            if (totalAmount >= 100000000) {
                displayAmount = totalAmount / 100000000;
                unit = '億';
            } else {
                displayAmount = totalAmount / 10000;
            }
            $('#stat-amount').text(numberFormat(displayAmount, 2));
            $('#amount-unit-title').text('(' + unit + ')');
            $('#amount-unit-bottom').text(unit + 'TWD');
            $('#stat-maker-count').text(Object.keys(makerStats).length);
            
            // 更新前五大廠商
            var sortedMakers = Object.keys(makerStats).map(function(key) { return [key, makerStats[key]]; });
            sortedMakers.sort(function(a, b) { return b[1] - a[1]; });
            var topMakersHtml = '';
            sortedMakers.slice(0, 5).forEach(function(item) {
                topMakersHtml += '<tr><td>' + item[0] + '</td><td class="text-right">$' + numberFormat(item[1] / 10000, 2) + '</td></tr>';
            });
            $('#top-makers-body').html(topMakersHtml);

            // 更新圖表
            var barChart = Highcharts.charts.find(function(c) { return c && c.renderTo.id === 'analysis-chart'; });
            if (barChart) {
                var categories = Object.keys(chartStats).sort();
                var seriesData = categories.map(function(k) { return chartStats[k]; });
                barChart.xAxis[0].setCategories(categories);
                barChart.series[0].setData(seriesData);
            }
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

        // 快速日期設定
        function setQuickDate(type) {
            var now = new Date();
            var start, end;
            var year = now.getFullYear();
            var month = now.getMonth();

            if (type === 'thisMonth') {
                start = new Date(year, month, 1);
                end = new Date(year, month + 1, 0);
            } else if (type === 'lastMonth') {
                start = new Date(year, month - 1, 1);
                end = new Date(year, month, 0);
            } else if (type === 'thisYear') {
                start = new Date(year, 0, 1);
                end = new Date(year, 11, 31);
            } else if (type.startsWith('q')) {
                var q = parseInt(type.substring(1));
                var startMonth = (q - 1) * 3;
                start = new Date(year, startMonth, 1);
                end = new Date(year, startMonth + 3, 0);
            }

            function fmt(d) {
                var m = '' + (d.getMonth() + 1), dy = '' + d.getDate();
                if (m.length < 2) m = '0' + m;
                if (dy.length < 2) dy = '0' + dy;
                return [d.getFullYear(), m, dy].join('-');
            }

            $('#start_date').val(fmt(start));
            $('#end_date').val(fmt(end));
            document.getElementById('filterForm').submit();
        }

        // 聚焦到特定行
        function focusOnRow(id) {
            $('#analysisResultModal').modal('hide');
            var table = $('#transferTable').DataTable();
            
            // Find the row index using transfer_id
            var indexes = table.rows().indexes().filter(function(idx) {
                return table.row(idx).data().transfer_id == id;
            });

            if (indexes.length > 0) {
                var rowIndex = indexes[0];
                
                // Find position in current search/order
                var currentOrderIndexes = table.rows({ search: 'applied', order: 'current' }).indexes();
                var currentPosition = currentOrderIndexes.indexOf(rowIndex);
                
                if (currentPosition >= 0) {
                    var pageInfo = table.page.info();
                    var page = Math.floor(currentPosition / pageInfo.length);
                    table.page(page).draw(false);
                    
                    setTimeout(function() {
                        var tr = table.row(rowIndex).node();
                        if (tr) {
                            $('html, body').animate({
                                scrollTop: $(tr).offset().top - 150
                            }, 500);
                            
                            $(tr).addClass('highlight-row');
                            setTimeout(function() {
                                $(tr).removeClass('highlight-row');
                            }, 3000);
                        }
                    }, 100);
                } else {
                    alert("該項目在當前篩選條件下不可見。");
                }
            }
        }

        // 異常偵測
        function performLocalAnalysis() {
            var table = $('#transferTable').DataTable();
            var data = table.rows({ search: 'applied' }).data().toArray();
            
            var zeroPriceItems = [];
            var highLossItems = [];

            data.forEach(function(row) {
                var price = parseFloat(row.price) || 0;
                var qty = parseFloat(row.transfer_qty) || 0;
                var loss = parseFloat(row.loss_qty) || 0;

                if (price === 0) {
                    zeroPriceItems.push(row);
                }
                if (qty > 0 && (loss / qty) > 0.1) { // NG 率 > 10%
                    highLossItems.push(row);
                }
            });

            var html = '';
            if (zeroPriceItems.length > 0) {
                html += '<div class="alert alert-danger"><h4><i class="fa fa-exclamation-circle"></i> 單價為 0 (' + zeroPriceItems.length + ' 筆)</h4><ul>';
                zeroPriceItems.forEach(function(row) {
                    html += '<li><a href="javascript:void(0);" onclick="focusOnRow(' + row.transfer_id + ')" style="color: inherit; text-decoration: underline;">' + 
                            row.transfer_date + ' - ' + row.transfer_no + ' - ' + row.product_id + ' (' + (row.maker_from_name || row.maker_from) + ')</a></li>';
                });
                html += '</ul></div>';
            } else {
                html += '<div class="alert alert-success"><h4><i class="fa fa-check-circle"></i> 無單價為 0 的項目</h4></div>';
            }

            if (highLossItems.length > 0) {
                html += '<div class="alert alert-warning"><h4><i class="fa fa-exclamation-triangle"></i> 高損耗率 (>10%) (' + highLossItems.length + ' 筆)</h4><ul>';
                highLossItems.forEach(function(row) {
                    var rate = Math.round((row.loss_qty / row.transfer_qty) * 100);
                    html += '<li><a href="javascript:void(0);" onclick="focusOnRow(' + row.transfer_id + ')" style="color: inherit; text-decoration: underline;">' + 
                            row.transfer_date + ' - ' + row.product_id + ' - NG: ' + row.loss_qty + '/' + row.transfer_qty + ' (' + rate + '%)</a></li>';
                });
                html += '</ul></div>';
            }

            $('#analysis-result-body').html(html);
            $('#analysisResultModal').modal('show');
        }

        // 圖檔檢視
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
                        listHtml += '<a href="#" class="list-group-item bom-file-item ' + active + '" data-path="' + f.path + '" data-type="' + f.type + '">' + 
                                    '<h5 class="list-group-item-heading">' + f.bom + '</h5>' +
                                    '<p class="list-group-item-text">' + f.name + '</p></a>';
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
                html = '<iframe src="' + path + '" style="width:100%; height:600px; border:none;"></iframe>';
            } else {
                html = '<img src="' + path + '" style="max-width:100%; max-height:600px; margin-top:10px;">';
            }
            $('#bom-file-viewer').html(html);
        }
    </script>
</body>
</html>