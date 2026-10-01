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

/* ── 分析用共用函式（2026-10-01 新增：畫面風格仿 Order_Analysis.php＋製程大項篩選＋自動分析）── */
require_once __DIR__ . '/../../src/common/transfer_log_analysis_lib.php';
$pt_ids_param   = isset($_GET['ptypes']) ? (array)$_GET['ptypes'] : [];
$pt_ids         = array_values(array_unique(array_filter(array_map('intval', $pt_ids_param))));
$cmp_basis      = in_array(($_GET['cmp'] ?? ''), ['prev', 'yoy'], true) ? $_GET['cmp'] : 'prev';
$process_type_opts = tla_process_types($conn->getPDO());

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
        pn.process_type_id,
        pt.process_type AS process_type_name
    FROM bom_ing_transfer_log t
    LEFT JOIN bom b ON t.bom = b.bom
    LEFT JOIN maker_list m ON t.maker_from = m.maker_id_no
    LEFT JOIN bom_ing bi ON t.bom = bi.bom AND t.bom_sn = bi.bom_sn
    LEFT JOIN process_no pn ON bi.process_no = pn.ProcessNo
    LEFT JOIN process_type pt ON pn.process_type_id = pt.process_type_id
    LEFT JOIN `user` bu ON bu.id = t.bill_ym_by
    WHERE t.transfer_date BETWEEN :start_date AND :end_date";
$sql_params = [':start_date' => $start_date, ':end_date' => $end_date];
if ($pt_ids) {
    $ph = [];
    foreach ($pt_ids as $i => $id) { $k = ':pt' . $i; $ph[] = $k; $sql_params[$k] = $id; }
    $sql .= " AND pn.process_type_id IN (" . implode(',', $ph) . ")";
}
$sql .= " ORDER BY t.transfer_date DESC, t.transfer_id DESC";

$rows = [];
if ($bm_perms['canView']) {          // 沒有檢視權限就不查，單價與金額不會出現在 HTML 裡
    $stmt = $conn->getPDO()->prepare($sql);
    $stmt->execute($sql_params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

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

/* ── 資料分析（2026-10-01：改走 transfer_log_analysis_lib.php 的唯一實作）──────────
 * $rows 已含 process_type_name，一次 foreach 就同時算出總計／依製程大項／依廠商／依料號，
 * 不再各自分開寫一次迴圈（鐵律4）。畫面上的「統計分析」分頁之後還會依目前的 DataTables
 * 篩選結果在前端用同一套規則重算一次（updateStatistics()），這裡只負責「頁面剛載入」那一次。 */
$agg = tla_aggregate($rows);
$total_qty    = $agg['qty'];
$total_amount = $agg['amount'];
$total_loss   = $agg['loss'];
$valid_count  = $agg['count'];

// 廠商排行（全部，不只前五；畫面上另有 Top N 可調）
$maker_rank = tla_top_share($agg['by_maker'], count($agg['by_maker']) ?: 1)['items'];
$top_makers = [];
foreach (array_slice($maker_rank, 0, 5) as $m) { $top_makers[$m['name']] = $m['amount']; }

// 製程大項排行（全部）
$type_rank = tla_top_share($agg['by_type'], count($agg['by_type']) ?: 1)['items'];

// 熱門加工料號排序 (依金額，前10)
$part_rank = tla_top_share($agg['by_part'], count($agg['by_part']) ?: 1)['items'];
$top_products = [];
foreach (array_slice($part_rank, 0, 10) as $p) {
    $top_products[$p['name']] = ['amount' => $p['amount'], 'qty' => $p['qty'], 'count' => $p['count'], 'loss' => $agg['by_part'][$p['name']]['loss'] ?? 0];
}

// 趨勢：依製程大項堆疊（取金額前 8 大項＋其他）
$trend = tla_trend_by_type($rows, $chart_group_by, 8);

// 比較期間（上一個等長期間／去年同期，可在畫面切換）與自動分析
$cmp_bases = tla_cmp_bases();
$cmp_label = $cmp_bases[$cmp_basis] ?? $cmp_bases['prev'];
$cmp_agg = ['count' => 0, 'qty' => 0.0, 'loss' => 0.0, 'amount' => 0.0, 'zero_price' => 0, 'high_loss' => 0, 'by_type' => [], 'by_maker' => [], 'by_part' => []];
$new_makers = [];
$insights = [];
$maker_first_dates = [];   // 廠商 => 有史以來第一次加工紀錄日期（不受畫面篩選影響），答「哪邊看得到廠商第一次交易時間」
if ($bm_perms['canView']) {
    [$cmp_start, $cmp_end] = tla_compare_range($start_date, $end_date, $cmp_basis);
    $cmp_rows = tla_fetch_rows_lite($conn->getPDO(), $cmp_start, $cmp_end, $pt_ids);
    $cmp_agg  = tla_aggregate($cmp_rows);
    $new_makers = tla_new_makers($conn->getPDO(), $start_date, $end_date, $pt_ids);
    $insights = tla_insights($agg, $cmp_agg, $new_makers, $cmp_label);
    $maker_first_dates = tla_maker_first_dates($conn->getPDO());
}

$transfer_data_json = json_encode($rows);
$trend_json  = json_encode($trend);
$type_rank_json = json_encode(array_values($type_rank));
$maker_first_dates_json = json_encode($maker_first_dates);

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
        /* 角色設定 */
        .tl-role { display:inline-flex; align-items:center; gap:4px; font-size:12px; color:#5b3a1e;
            background:#F7E0BD; border-radius:12px; padding:3px 10px; }
        .ptl-role-item { padding:6px 10px; border-bottom:1px solid #F1E4D0; cursor:pointer; font-size:13px; color:#5b3a1e; }
        .ptl-role-item:hover { background:#FDF8EF; }
        .ptl-role-item.on { background:#F0A24B; color:#fff; }
        .ptl-role-item.sys { cursor:not-allowed; color:#999; }
        /* 頁內分頁（明細／統計分析） */
        .tl-tabs { border-bottom:2px solid #E8D5B5; margin-bottom:12px; }
        .tl-tabs > li > a { color:#8A5A2B; font-size:14px; font-weight:bold; border:none; }
        .tl-tabs > li > a:hover { background:#FDF8EF; border:none; }
        .tl-tabs > li.active > a, .tl-tabs > li.active > a:hover, .tl-tabs > li.active > a:focus {
            color:#fff; background:#F0A24B; border:none; }

        /* ── 2026-10-01：畫面風格比照 views/Sales/Order_Analysis.php（暖色系，ai-rules/10）── */
        :root { --ink:#4A3524; --amber:#F0A24B; --amber-d:#C77C1A; --coral:#DD5138;
                --line:#E4D3BC; --muted:#a08a6f; --brown:#8a5a2b; }
        .warm-panel { background:#fff; border:1px solid var(--line); border-radius:8px; padding:12px 14px; margin-bottom:12px; }
        .btn-warm { background:var(--amber); border:1px solid var(--amber-d); color:var(--ink); font-weight:bold; }
        .btn-warm:hover, .btn-warm:focus { background:var(--amber-d); color:#fff; }
        .btn-warm-o { background:#fff; border:1px solid var(--amber-d); color:var(--amber-d); }
        .btn-warm-o:hover { background:#F7E0BD; color:var(--ink); }
        .tla-bar { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
        .tla-bar label { margin:0; font-size:12px; color:var(--ink); font-weight:600; }
        /* KPI 卡 */
        .kpi-row { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:12px; }
        .kpi-card { flex:1 1 150px; min-width:150px; background:#fff; border:1px solid var(--line);
                    border-top:3px solid var(--amber); border-radius:8px; padding:10px 12px; }
        .kpi-card .k-lab { font-size:12px; color:var(--muted); }
        .kpi-card .k-val { font-size:24px; font-weight:700; color:var(--ink); line-height:1.2; word-break:break-all; }
        .kpi-card .k-sub { font-size:11px; color:var(--muted); margin-top:2px; }
        /* 區塊（統計分析分頁內每個小節） */
        .sec { background:#fff; border:1px solid var(--line); border-radius:8px; padding:12px 14px; margin-bottom:14px; }
        .sec h4 { margin:0 0 4px; font-size:16px; color:var(--ink); font-weight:700;
                  display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
        .sec h4 .hint { font-size:11px; color:var(--muted); font-weight:normal; }
        .sec-tools { margin-left:auto; display:flex; gap:6px; align-items:center; flex-wrap:wrap; }
        .chart-box { width:100%; height:300px; }
        .chart-box.tall { height:360px; }
        .two-col { display:flex; gap:14px; flex-wrap:wrap; }
        .two-col > div { flex:1 1 380px; min-width:300px; }
        table.oa-t { width:100%; border-collapse:collapse; font-size:12px; table-layout:fixed; }
        table.oa-t th, table.oa-t td { border:1px solid var(--line); padding:4px 6px; vertical-align:middle;
                                        word-break:break-all; line-height:1.5; }
        table.oa-t th { background:#faf6f0; color:#6B4423; font-weight:700; text-align:center; white-space:nowrap; }
        table.oa-t td.n { text-align:right; font-variant-numeric:tabular-nums; }
        table.oa-t tbody tr:nth-child(even) { background:#fdfbf8; }
        .tbl-wrap { max-height:420px; overflow:auto; border:1px solid var(--line); border-radius:6px; }
        .tbl-wrap table.oa-t th { position:sticky; top:0; z-index:2; }
        /* 自動分析卡片 */
        .ins-list { display:flex; flex-direction:column; gap:6px; }
        .ins { display:flex; gap:10px; align-items:flex-start; border:1px solid var(--line); border-left-width:4px;
               border-radius:6px; padding:7px 10px; background:#fffdfa; }
        .ins .ic { font-size:15px; line-height:20px; width:18px; text-align:center; flex:0 0 18px; }
        .ins .bd { flex:1 1 auto; min-width:0; }
        .ins .tt { font-weight:700; color:var(--ink); font-size:13px; }
        .ins .dt { font-size:12px; color:#6B4423; line-height:1.7; }
        .ins .mt { flex:0 0 auto; font-weight:700; font-size:13px; white-space:nowrap; }
        .ins-bad  { border-left-color:var(--coral); } .ins-bad  .ic, .ins-bad  .mt { color:var(--coral); }
        .ins-warn { border-left-color:var(--amber); } .ins-warn .ic, .ins-warn .mt { color:var(--amber-d); }
        .ins-good { border-left-color:#4F8A4F; }      .ins-good .ic, .ins-good .mt { color:#2E7D32; }
        .ins-info { border-left-color:#B9A78C; }      .ins-info .ic, .ins-info .mt { color:var(--muted); }
        .nav-jump { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:10px; }
        .nav-jump a { font-size:12px; border:1px solid var(--line); background:#fff; color:#6B4423;
                      padding:3px 10px; border-radius:12px; text-decoration:none; }
        .nav-jump a:hover { background:#F7E0BD; }
        .pt-chip { display:inline-flex; align-items:center; gap:3px; background:#F7E0BD; color:#6B4423;
                   border-radius:12px; padding:1px 9px 1px 4px; font-size:12px; }
        .pt-chip i { cursor:pointer; color:#8C3A28; }
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
                            <span class="tl-role" style="margin-left:auto;" title="你目前在本頁的身分（由管理員在角色設定指派）">
                                <i class="fa fa-user"></i> <?= htmlspecialchars(eg_bm_role_label($bm_perms)) ?>
                            </span>
<?php if ($bm_perms['isAdmin'] || $bm_perms['canAdmin']): ?>
                            <button id="btnRoleSetting" class="page-help-btn" style="margin-left:6px;background:#8A5A2B;border-color:#6d4622;"><i class="fa fa-users"></i> 角色設定</button>
<?php endif; ?>
                            <button id="btnPageHelp" class="page-help-btn" style="margin-left:6px;"><i class="fa fa-question-circle"></i> 使用說明</button>
                        </div>
                    </div>

                    <div class="clearfix"></div>

<?php if (!$bm_perms['canView']): ?>
                    <div class="alert alert-warning" style="clear:both;">
                        <h4 style="margin-top:0;"><i class="fa fa-lock"></i> 沒有製程移轉一覽表的檢視權限</h4>
                        <p style="margin-bottom:0;">本頁會顯示加工單價與金額，需由管理者在
                            <a href="../user/user_permissions.php" target="_blank">使用者權限設定</a>
                            指派含「檢視」功能的角色後才看得到內容。</p>
                    </div>
<?php else: ?>
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
                                        <div class="form-group" style="margin-left:10px;">
                                            <label for="fPType">製程大項：</label>
                                            <select id="fPType" name="ptypes[]" class="form-control" multiple="multiple" style="min-width:240px;">
                                                <?php foreach ($process_type_opts as $pto): $ptid = (int)$pto['process_type_id']; ?>
                                                <option value="<?= $ptid ?>"<?= in_array($ptid, $pt_ids, true) ? ' selected' : '' ?>><?= htmlspecialchars($pto['process_type']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="form-group" style="margin-left:10px;">
                                            <label for="fCmp">比較基準：</label>
                                            <select id="fCmp" name="cmp" class="form-control input-sm">
                                                <?php foreach ($cmp_bases as $ck => $cv): ?>
                                                <option value="<?= htmlspecialchars($ck) ?>"<?= $cmp_basis === $ck ? ' selected' : '' ?>><?= htmlspecialchars($cv) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <span style="font-size:11px;color:#9b8676;">（供「自動分析」比較用）</span>
                                        </div>
                                        <button type="submit" class="btn btn-warm btn-sm" style="margin-left:10px;"><i class="fa fa-check"></i> 套用篩選</button>

                                        <div style="margin-top: 5px;">
                                            <button type="button" class="btn btn-info btn-sm" onclick="setQuickDate('thisMonth')">本月</button>
                                            <button type="button" class="btn btn-info btn-sm" onclick="setQuickDate('lastMonth')">上月</button>
                                            <button type="button" class="btn btn-info btn-sm" onclick="setQuickDate('thisYear')">今年</button>
                                            <button type="button" class="btn btn-default btn-sm" onclick="setQuickDate('q1')">Q1</button>
                                            <button type="button" class="btn btn-default btn-sm" onclick="setQuickDate('q2')">Q2</button>
                                            <button type="button" class="btn btn-default btn-sm" onclick="setQuickDate('q3')">Q3</button>
                                            <button type="button" class="btn btn-default btn-sm" onclick="setQuickDate('q4')">Q4</button>
<?php if ($pt_ids): ?>
                                            <a href="javascript:void(0);" onclick="clearPTypeFilter()" style="margin-left:10px;font-size:12px;color:#8C3A28;"><i class="fa fa-times-circle"></i> 清除製程大項篩選</a>
<?php endif; ?>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- 快速導覽 -->
                    <div class="nav-jump">
                        <a href="#secInsight">自動分析</a><a href="#tab-detail-link" onclick="$('a[href=\'#tab-detail\']').tab('show');return false;">移轉明細</a>
                        <a href="#secProcType" onclick="$('a[href=\'#tab-stats\']').tab('show');">製程大項分析</a>
                        <a href="#secTrend" onclick="$('a[href=\'#tab-stats\']').tab('show');">加工金額趨勢</a>
                        <a href="#secMaker" onclick="$('a[href=\'#tab-stats\']').tab('show');">廠商分析</a>
                        <a href="#secPart" onclick="$('a[href=\'#tab-stats\']').tab('show');">熱門料號</a>
                    </div>

                    <!-- ── 自動分析（2026-10-01 新增，比照 Order_Analysis.php）───────────────── -->
                    <div class="sec" id="secInsight">
                        <h4><i class="fa fa-lightbulb-o" style="color:var(--coral);"></i> 自動分析
                            <span class="hint">依目前的日期與製程大項篩選，和「<?= htmlspecialchars($cmp_label) ?>」比較，每一條都附具體數字</span>
                        </h4>
                        <div class="ins-list">
<?php foreach ($insights as $ins): ?>
                            <div class="ins ins-<?= htmlspecialchars($ins['level']) ?>">
                                <div class="ic"><i class="fa <?= htmlspecialchars($ins['icon']) ?>"></i></div>
                                <div class="bd">
                                    <div class="tt"><?= htmlspecialchars($ins['title']) ?></div>
                                    <div class="dt"><?= htmlspecialchars($ins['detail']) ?></div>
                                </div>
<?php if (!empty($ins['metric'])): ?>
                                <div class="mt"><?= htmlspecialchars($ins['metric']) ?></div>
<?php endif; ?>
                            </div>
<?php endforeach; ?>
                        </div>
                    </div>

                    <!-- 頁內分頁：移轉明細／統計分析（資料太多時分開看） -->
                    <ul class="nav nav-tabs tl-tabs" role="tablist">
                        <li role="presentation" class="active"><a href="#tab-detail" data-toggle="tab" role="tab"><i class="fa fa-list"></i> 移轉明細</a></li>
                        <li role="presentation"><a href="#tab-stats" data-toggle="tab" role="tab"><i class="fa fa-bar-chart"></i> 統計分析</a></li>
                    </ul>
                    <div class="tab-content">
                    <div role="tabpanel" class="tab-pane fade in active" id="tab-detail">

                    <!-- 統計數據磚（2026-10-01：改用 Order_Analysis.php 同款 KPI 卡） -->
                    <?php
                        $display_amount = $total_amount;
                        $unit = '萬';
                        if ($total_amount >= 100000000) { $display_amount = $total_amount / 100000000; $unit = '億'; }
                        else { $display_amount = $total_amount / 10000; }
                        $anomaly_cnt = (int)$agg['zero_price'] + (int)$agg['high_loss'];
                    ?>
                    <div class="kpi-row">
                        <div class="kpi-card">
                            <div class="k-lab"><i class="fa fa-list-alt"></i> 移轉筆數</div>
                            <div class="k-val" id="stat-count"><?= number_format($valid_count) ?></div>
                            <div class="k-sub">筆</div>
                        </div>
                        <div class="kpi-card">
                            <div class="k-lab"><i class="fa fa-cubes"></i> 總加工數量</div>
                            <div class="k-val" id="stat-qty"><?= number_format($total_qty) ?></div>
                            <div class="k-sub">PCS（NG: <span id="stat-loss"><?= number_format($total_loss) ?></span>）</div>
                        </div>
                        <div class="kpi-card">
                            <div class="k-lab"><i class="fa fa-money"></i> 總加工金額 <span id="amount-unit-title">(<?= $unit ?>)</span></div>
                            <div class="k-val" id="stat-amount"><?= number_format($display_amount, 2) ?></div>
                            <div class="k-sub" id="amount-unit-bottom"><?= $unit ?>TWD</div>
                        </div>
                        <div class="kpi-card">
                            <div class="k-lab"><i class="fa fa-wrench"></i> 加工廠商數</div>
                            <div class="k-val" id="stat-maker-count"><?= count($agg['by_maker']) ?></div>
                            <div class="k-sub">家</div>
                        </div>
                        <div class="kpi-card" style="border-top-color:var(--coral);">
                            <div class="k-lab"><i class="fa fa-exclamation-triangle"></i> 異常筆數</div>
                            <div class="k-val" id="stat-anomaly"><?= number_format($anomaly_cnt) ?></div>
                            <div class="k-sub">單價為0＋高損耗</div>
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
                                                    <th>製程大項</th>
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

                    <!-- ── 製程大項分析（2026-10-01 新增）───────────────────────── -->
                    <div class="sec" id="secProcType">
                        <h4><i class="fa fa-cogs" style="color:var(--amber-d);"></i> 製程大項分析
                            <span class="hint">依「製程」欄反查的製程大項（主檔管理 → 類別字典設定）彙總，隨下方「移轉明細」目前的篩選即時更新</span>
                        </h4>
                        <div class="two-col">
                            <div><div id="chProcTypePie" class="chart-box"></div></div>
                            <div><div id="chProcTypeBar" class="chart-box"></div></div>
                        </div>
                        <div class="tbl-wrap" style="margin-top:10px;">
                            <table class="oa-t" id="tblProcType">
                                <colgroup><col style="width:28%"><col style="width:14%"><col style="width:14%">
                                          <col style="width:16%"><col style="width:14%"><col style="width:14%"></colgroup>
                                <thead><tr><th>製程大項</th><th>筆數</th><th>數量</th><th>金額(萬)</th><th>佔金額</th><th>NG數</th></tr></thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>

                    <!-- ── 加工金額趨勢（依製程大項堆疊）─────────────────────── -->
                    <div class="sec" id="secTrend">
                        <h4><i class="fa fa-line-chart" style="color:var(--amber-d);"></i> 加工金額趨勢
                            (<span id="trendGranLabel"><?= $chart_group_by == 'month' ? '月' : ($chart_group_by == 'week' ? '週' : '日') ?></span>)
                            <span class="hint">依製程大項堆疊，取金額前 8 大項＋其他</span>
                            <span class="sec-tools">
                                <label style="margin:0;font-size:12px;">顯示</label>
                                <select id="trendMetric" class="form-control input-sm" style="width:100px;">
                                    <option value="amount">加工金額(萬)</option>
                                    <option value="qty">加工數量</option>
                                </select>
                            </span>
                        </h4>
                        <div id="analysis-chart" class="chart-box tall"></div>
                    </div>

                    <!-- ── 廠商分析（2026-10-01 由「前五大」擴充為完整排行）───────── -->
                    <div class="sec" id="secMaker">
                        <h4><i class="fa fa-trophy" style="color:var(--amber-d);"></i> 加工廠商分析
                            <span class="hint" id="makerHint"></span>
                            <span class="sec-tools">
                                <label style="margin:0;font-size:12px;">顯示筆數</label>
                                <select id="makerTop" class="form-control input-sm" style="width:80px;">
                                    <option>10</option><option selected>15</option><option>20</option><option>30</option>
                                </select>
                            </span>
                        </h4>
                        <div id="chMaker" class="chart-box tall"></div>
                        <div class="tbl-wrap" style="margin-top:10px;">
                            <table class="oa-t" id="tblMaker">
                                <colgroup><col style="width:17%"><col style="width:9%"><col style="width:11%">
                                          <col style="width:12%"><col style="width:9%"><col style="width:10%"><col style="width:8%">
                                          <col style="width:12%"></colgroup>
                                <thead><tr><th>廠商</th><th>筆數</th><th>數量</th><th>金額(萬)</th><th>佔金額</th><th>平均單價</th><th>NG數</th>
                                           <th>第一次交易日期</th></tr></thead>
                                <tbody id="top-makers-body"></tbody>
                            </table>
                        </div>
                    </div>

                    <!-- ── 熱門加工料號排行 ─────────────────────────────────── -->
                    <div class="sec" id="secPart">
                        <h4><i class="fa fa-star" style="color:var(--amber-d);"></i> 十大高加工成本料號
                            <span class="hint">隨「移轉明細」目前的篩選即時更新，點料號可開圖檔</span>
                        </h4>
                        <div class="tbl-wrap" style="max-height:360px;">
                            <table class="oa-t" id="tblPart">
                                <colgroup><col style="width:8%"><col style="width:24%"><col style="width:16%">
                                          <col style="width:14%"><col style="width:16%"><col style="width:12%"><col style="width:10%"></colgroup>
                                <thead><tr><th>排名</th><th>料號</th><th>總金額(萬)</th><th>總數量</th><th>平均單價</th><th>筆數</th><th>NG數</th></tr></thead>
                                <tbody id="top-products-tbody">
                                    <?php
                                    $rank = 1;
                                    foreach ($top_products as $pid => $stats):
                                        $avg_price = $stats['qty'] > 0 ? $stats['amount'] / $stats['qty'] : 0;
                                        $safePid = str_replace("'", "\\'", $pid);
                                        $displayPid = htmlspecialchars($pid);
                                    ?>
                                    <tr>
                                        <td class="n"><?= $rank++ ?></td>
                                        <td><a href="javascript:void(0);" onclick="openProductFiles('<?= $safePid ?>')" style="text-decoration: underline; color: #8a5a2b;"><?= $displayPid ?></a></td>
                                        <td class="n">$<?= number_format($stats['amount'] / 10000, 2) ?></td>
                                        <td class="n"><?= number_format($stats['qty']) ?></td>
                                        <td class="n">$<?= number_format($avg_price, 2) ?></td>
                                        <td class="n"><?= number_format($stats['count']) ?></td>
                                        <td class="n"><?= number_format($stats['loss']) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    </div><!-- /#tab-stats -->
                    </div><!-- /.tab-content -->
<?php endif; /* canView */ ?>

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

<?php if ($bm_perms['isAdmin'] || $bm_perms['canAdmin']): ?>
    <!-- 角色設定 Modal：管理員自行建立角色、自行勾選功能（走全站共用 Roles_API，不另建一套） -->
    <div class="modal fade" id="roleSettingModal" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                    <h4 class="modal-title"><i class="fa fa-users"></i> 製程移轉一覽表 角色設定</h4>
                </div>
                <div class="modal-body">
                    <div class="bm-hint">
                        角色<b>名稱與內容都由您自訂</b>：左邊建立／選一個角色，右邊勾選它能用哪些功能。
                        改完到 <a href="../user/user_permissions.php" target="_blank" style="color:#8A5A2B;text-decoration:underline;">使用者權限設定</a> 指派給人員即可。
                        <b>「管理員」是系統角色，固定擁有全部權限，不可修改。</b>
                    </div>
                    <div class="row">
                        <div class="col-md-4">
                            <div style="display:flex;gap:6px;margin-bottom:6px;">
                                <button class="btn btn-warning btn-sm" id="btnPtlRoleAdd" style="margin:0;"><i class="fa fa-plus"></i> 新增角色</button>
                            </div>
                            <div id="ptlRoleList" style="border:1px solid #E8D5B5;border-radius:6px;max-height:320px;overflow-y:auto;"></div>
                        </div>
                        <div class="col-md-8">
                            <div id="ptlRoleEditHint" class="text-muted" style="padding:20px;font-size:13px;">← 請先在左邊選一個角色，或按「新增角色」。</div>
                            <div id="ptlRoleEdit" style="display:none;">
                                <div class="bm-form-row">
                                    <label>角色名稱</label>
                                    <input type="text" id="ptlRoleName" class="form-control input-sm" style="width:200px;" maxlength="50">
                                    <button class="btn btn-default btn-sm" id="btnPtlRoleRename" style="margin:0;">改名</button>
                                    <button class="btn btn-danger btn-sm" id="btnPtlRoleDel" style="margin:0;">刪除角色</button>
                                </div>
                                <div style="border-top:1px solid #eee;padding-top:8px;">
                                    <div style="font-size:13px;font-weight:bold;color:#8A5A2B;margin-bottom:4px;">檢視</div>
                                    <div id="ptlFeatView" style="padding-left:6px;"></div>
                                    <div style="font-size:13px;font-weight:bold;color:#8A5A2B;margin:8px 0 4px;">操作</div>
                                    <div id="ptlFeatOp" style="padding-left:6px;"></div>
                                </div>
                                <div style="margin-top:10px;">
                                    <button class="btn btn-warning btn-sm" id="btnPtlFeatSave"><i class="fa fa-check"></i> 儲存功能設定</button>
                                    <span id="ptlRoleMsg" style="margin-left:8px;font-size:12px;"></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal">關閉</button>
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
                        <li><b>製程大項篩選</b>（2026-10-01 新增）：同一列可以打字多選「製程大項」（車床／銑床／齒研…，來源為主檔管理 → 類別字典設定），
                            選好後按<b>「套用篩選」</b>（或任一快速日期鈕）送出，整頁（含下方自動分析／趨勢／明細）都只看選中的大項；
                            不選＝全部大項，已篩選時篩選列右側會出現「清除製程大項篩選」。</li>
                        <li><b>比較基準</b>：自動分析卡片比較的對象，可選「上一個等長期間」（預設）或「去年同期」。</li>
                        <li><b>自動分析</b>（2026-10-01 新增）：比照訂單分析頁的做法，系統直接把本期與比較期間的差異、
                            製程大項／廠商集中度、單價為 0、高損耗率、新增廠商等寫成結論，每一條都附具體數字，不必自己盯著圖表找。</li>
                        <li><b>移轉明細分頁</b>：上方五格是<b>目前篩選結果</b>的合計（筆數／數量／金額／廠商數／異常筆數），會隨篩選即時變動；
                            下方列表新增「製程大項」欄，並可用逐欄篩選（日期／單號／BOM／料號／備註）、廠商多選、以及最右的<b>全域搜索</b>；
                            欄位有值時<b>雙擊即可清空該欄篩選</b>，或按「取消」清掉全部條件。</li>
                        <li><b>匯出</b>：列表標題右側有 複製／CSV／Excel／列印 四顆鈕，匯出的是<b>目前篩選後</b>的內容。</li>
                        <li><b>統計分析分頁</b>（2026-10-01 重做）：製程大項分析（表＋圓餅＋長條）、加工金額趨勢（依製程大項堆疊，可切換顯示金額或數量，
                            點柱子可把明細篩成該區間）、廠商分析（完整排行表＋長條圖，Top N 可調 10/15/20/30）、十大高加工成本料號；
                            以上全部都隨「移轉明細」目前的篩選（日期＋製程大項＋廠商多選＋逐欄文字篩選）即時重算，料號可點開查看對應的 BOM 圖檔。</li>
                        <li><b>廠商第一次交易日期</b>（2026-10-01 新增）：「廠商分析」排行表最右一欄，是該廠商<b>有史以來第一次</b>
                            出現在製程移轉紀錄裡的日期（取全表最早一筆，不受畫面上的日期區間或製程大項篩選影響），
                            用來看「跟這家廠商合作多久了」；自動分析卡片的「新增加工廠商」也會附上日期。</li>
                        <li><b>異常偵測</b>：在「查詢條件」右上角，會針對目前資料檢查單價異常等狀況並列出報告。</li>
                    </ul>

                    <h4>重要行為</h4>
                    <ul>
                        <li>金額若 ERP 沒帶（process_amount＝0）但有單價與數量時，系統會自動以<b>數量×單價</b>補算，避免統計短少。</li>
                        <li>統計分析分頁的所有圖表／表格都是跟著<b>篩選後</b>的資料重算；<b>自動分析</b>與「比較基準」則是整頁重新查詢（改日期／製程大項／比較基準並按套用）才會變，不受廠商多選等明細內篩選影響。</li>
                        <li>「新增廠商」是比對該廠商在<b>全表</b>（不限目前日期區間）最早一筆移轉日期是否落在本期，只代表系統裡第一次看到紀錄，不代表實際上第一次合作。</li>
                        <li>全表目前共 <?= number_format($total_log_rows) ?> 筆，最早可追溯到 2018 年，一次查太大區間會比較慢。</li>
                    </ul>

                    <h4>權限（角色可由管理員自訂）</h4>
                    <p>本頁的角色<b>名稱與內容都由管理員自己設定</b>：頁首「<b>角色設定</b>」可新增角色、改名、刪除，
                       並逐項勾選它能用哪些功能。四個功能可以<b>各別</b>開關：</p>
                    <ul>
                        <li><b>檢視（唯讀）</b>：看得到移轉明細與統計分析。
                            <span style="color:#b06f27;">沒有這項就整頁看不到內容</span>（本頁會顯示加工單價與金額）。</li>
                        <li><b>列印／匯出</b>：複製、CSV、Excel、列印四顆鈕；沒勾就不會出現。</li>
                        <li><b>帳款月份維護</b>：勾選欄與「批次修改帳款月份」「還原為自動」。</li>
                        <li><b>模組管理</b>：以上全部＋「重算」＋角色設定。</li>
                    </ul>
                    <div class="tip">
                        設好角色後，到<a href="../user/user_permissions.php" target="_blank" style="color:#8A5A2B;">使用者權限設定</a>
                        指派給人員（可指派給個人，也可用「部門×職稱」整批套用）。
                        管理者固定擁有全部權限。<b>沒有權限的人不只看不到按鈕，直接呼叫 API 也會被後端擋下。</b>
                    </div>
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
    <!-- 日期顯示一律走共用檔（ai-rules/20：YYYY.MM.DD） -->
    <script src="../../resource/js/eg_date_fmt.js?v=<?= @filemtime(__DIR__ . '/../../resource/js/eg_date_fmt.js') ?>"></script>

    <script>
        var transferData = <?= $transfer_data_json ?>;
        // 每家廠商「有史以來第一次」出現在製程移轉紀錄裡的日期（不受畫面篩選影響）
        var MAKER_FIRST_DATES = <?= $maker_first_dates_json ?: '{}' ?>;
        var chartGroupBy = '<?= $chart_group_by ?>';
        var currentChartFilter = null;

        /* ── 2026-10-01：製程大項分析／自動分析（畫面風格比照 Order_Analysis.php）──
         * 暖色調色盤固定 10 色（ai-rules/10 既有「假別分類色盤」同一組值），依類別出現順序
         * 依序指派，不用亂數／HSL。 */
        var TLA_PALETTE = ['#DD5138','#F0A24B','#B06F27','#E8C07A','#8A5A2B','#D98A5F','#C9A227','#A34E2A','#EBD3A8','#7A4A34'];
        function tlaColor(i) { return TLA_PALETTE[i % TLA_PALETTE.length]; }
        function tlaEsc(t) { return $('<div>').text(t == null ? '' : t).html(); }
        function tlaSortDesc(obj) {
            return Object.keys(obj).map(function(k) { var o = {name: k}; for (var kk in obj[k]) o[kk] = obj[k][kk]; return o; })
                .sort(function(a, b) { return b.amount - a.amount; });
        }
        function tlaFindChart(id) {
            return Highcharts.charts.find(function(c) { return c && c.renderTo && c.renderTo.id === id; });
        }
        function tlaDestroyChart(id) {
            var c = tlaFindChart(id);
            if (c) c.destroy();
        }

        /* ── 帳款月份 ─────────────────────────────────────────────
         * 權限由後端算好帶進來；沒有維護權限時勾選欄整欄不顯示、工具列也不輸出，
         * 後端 API 仍會用同一套規則再擋一次（鐵律8）。 */
        var BM_CAN_VIEW  = <?= $bm_perms['canView']  ? 'true' : 'false' ?>;
        var BM_CAN_PRINT = <?= $bm_perms['canPrint'] ? 'true' : 'false' ?>;
        var BM_CAN_EDIT  = <?= $bm_perms['canEdit']  ? 'true' : 'false' ?>;
        var BM_CAN_ADMIN = <?= $bm_perms['canAdmin'] ? 'true' : 'false' ?>;
        var BM_CSRF      = <?= json_encode($bm_csrf) ?>;
        var BM_API       = '../../src/store/TransferBilling_API.php';
        var BM_DEF_DAY   = <?= (int)$bm_set['day'] ?>;
        var bmSelected   = {};   // transfer_id => true（跨分頁保留勾選）

        $(document).ready(function() {
            // 使用說明與角色設定：沒有檢視權限也要能開（管理員可能就是進來設定權限的）
            $('#btnPageHelp').on('click', function() { $('#helpUseMask').modal('show'); });
            $('#btnRoleSetting').on('click', function() { $('#roleSettingModal').modal('show'); ptlLoadRoles(); });

            // 製程大項篩選（GET 表單多選，select2 只是加強外觀，送出的仍是原生 select 的值）
            $('#fPType').select2({ placeholder: '製程大項（不選＝全部）', allowClear: true, width: '240px' });

            if (!BM_CAN_VIEW) return;   // 以下都要有明細表格才跑得動

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
                    { data: 'process_type_name', render: function(data) { return data ? $('<div>').text(data).html() : '<span style="color:#bbb;">未分類</span>'; } },
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
                // 列印／匯出四顆鈕要有 ptl_print 功能才出現（後端已算好 BM_CAN_PRINT）
                buttons: BM_CAN_PRINT ? [
                    { extend: 'copy', className: 'btn btn-default btn-sm' },
                    { extend: 'csv', className: 'btn btn-default btn-sm' },
                    { extend: 'excel', className: 'btn btn-default btn-sm', title: '製程移轉紀錄' },
                    { extend: 'print', className: 'btn btn-default btn-sm' }
                ] : [],
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
            $('#filter-note').on('keyup change', function() { table.column(18).search(this.value).draw(); });

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

            // 統計分析分頁（KPI 磚／製程大項分析／趨勢／廠商分析／熱門料號）一律由
            // updateStatistics() 依「目前 DataTables 篩選後」的資料重新計算與畫圖，
            // 每次 draw（換頁、篩選、全域搜尋…）都會跟著更新；這裡先手動呼叫一次把頁面剛載入
            // 時的統計磚與圖表畫出來（DataTable 本身的初始 draw 發生在這個事件綁定之前）。
            table.on('draw', updateStatistics);
            updateStatistics();

            $('#trendMetric').on('change', function() { updateStatistics(); });
            $('#makerTop').on('change', function() { updateStatistics(); });

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

        });

        /* ── 角色設定（走全站共用 Roles_API，功能碼由後端 PROC_TRANSFER_FEATURES 帶進來） ── */
        var PTL_FEATURES = <?= json_encode(PROC_TRANSFER_FEATURES, JSON_UNESCAPED_UNICODE) ?>;
        var PTL_RAPI = '../../src/store/Roles_API.php';
        var PTL_ROLES = [], PTL_CUR = 0;

        function ptlEsc(t) { return $('<div>').text(t == null ? '' : t).html(); }

        function ptlLoadRoles(then) {
            $.getJSON(PTL_RAPI, { action: 'get_roles', module: 'proc_transfer' }, function(res) {
                PTL_ROLES = (res && res.data) || [];
                var h = '';
                PTL_ROLES.forEach(function(r) {
                    var sys = String(r.is_system) === '1';
                    h += '<div class="ptl-role-item' + (sys ? ' sys' : '') + '" data-id="' + r.role_id + '">'
                       + ptlEsc(r.role_name) + (sys ? '<span style="color:#999;font-size:11px;">（系統．固定全權）</span>' : '')
                       + '</div>';
                });
                $('#ptlRoleList').html(h || '<div style="padding:10px;color:#8a6d45;font-size:13px;">尚無角色</div>');
                if (PTL_CUR) $('.ptl-role-item[data-id="' + PTL_CUR + '"]').addClass('on');
                if (typeof then === 'function') then();
            });
        }

        function ptlSelRole(id) {
            var r = PTL_ROLES.filter(function(x) { return String(x.role_id) === String(id); })[0];
            if (!r) return;
            if (String(r.is_system) === '1') { alert('系統角色「' + r.role_name + '」固定擁有全部權限，不可修改。'); return; }
            PTL_CUR = id;
            $('.ptl-role-item').removeClass('on');
            $('.ptl-role-item[data-id="' + id + '"]').addClass('on');
            $('#ptlRoleEditHint').hide(); $('#ptlRoleEdit').show();
            $('#ptlRoleName').val(r.role_name);
            $('#ptlRoleMsg').text('');
            var vh = '', oh = '';
            PTL_FEATURES.forEach(function(f) {
                var row = '<label style="display:block;font-weight:normal;padding:2px 0;font-size:13px;">'
                        + '<input type="checkbox" class="ptl-featcb" value="' + ptlEsc(f.code) + '"> ' + ptlEsc(f.label) + '</label>';
                if (f.group === 'view') vh += row; else oh += row;
            });
            $('#ptlFeatView').html(vh); $('#ptlFeatOp').html(oh);
            $.getJSON(PTL_RAPI, { action: 'get_role_features', role_id: id }, function(res) {
                var has = (res && res.data) || [];
                $('.ptl-featcb').each(function() {
                    this.checked = has.indexOf(this.value) > -1 || has.indexOf('all') > -1;
                });
            });
        }

        $(document).on('click', '#ptlRoleList .ptl-role-item', function() { ptlSelRole($(this).data('id')); });
        $(document).on('click', '#btnPtlRoleAdd', function() {
            var n = prompt('新角色名稱：');
            if (!n || !$.trim(n)) return;
            $.post(PTL_RAPI, { action: 'save_role', role_name: $.trim(n), module: 'proc_transfer' }, function(r) {
                if (!r.success) { alert(r.message || '建立失敗'); return; }
                ptlLoadRoles(function() { ptlSelRole(r.role_id); });
            }, 'json');
        });
        $(document).on('click', '#btnPtlRoleRename', function() {
            if (!PTL_CUR) return;
            var n = $.trim($('#ptlRoleName').val() || '');
            if (!n) { $('#ptlRoleMsg').css('color', '#DD5138').text('請輸入角色名稱'); return; }
            $.post(PTL_RAPI, { action: 'save_role', role_id: PTL_CUR, role_name: n }, function(r) {
                if (!r.success) { alert(r.message || '改名失敗'); return; }
                $('#ptlRoleMsg').css('color', '#5b8c3a').text('已改名');
                ptlLoadRoles();
            }, 'json');
        });
        $(document).on('click', '#btnPtlRoleDel', function() {
            if (!PTL_CUR) return;
            if (!confirm('確定要刪除這個角色嗎？\n已指派給人員的資料也會一併移除。')) return;
            $.post(PTL_RAPI, { action: 'delete_role', role_id: PTL_CUR }, function(r) {
                if (!r.success) { alert(r.message || '刪除失敗'); return; }
                PTL_CUR = 0; $('#ptlRoleEdit').hide(); $('#ptlRoleEditHint').show();
                ptlLoadRoles();
            }, 'json');
        });
        $(document).on('click', '#btnPtlFeatSave', function() {
            if (!PTL_CUR) return;
            var feats = $('.ptl-featcb:checked').map(function() { return this.value; }).get();
            $.post(PTL_RAPI, { action: 'save_role_features', role_id: PTL_CUR, features: JSON.stringify(feats) }, function(r) {
                if (!r.success) { alert(r.message || '儲存失敗'); return; }
                $('#ptlRoleMsg').css('color', '#5b8c3a').text('已儲存（被指派這個角色的人重新整理頁面後生效）');
            }, 'json');
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

        /**
         * 2026-10-01：統計分析分頁改比照 Order_Analysis.php，每次 DataTables 重繪
         * （換頁／篩選／全域搜尋／廠商多選…）都用「目前篩選後」的資料重算一次：
         * KPI 磚、製程大項分析（表＋圓餅＋長條）、廠商分析（表＋長條，取代原本只有前五大）、
         * 十大高加工成本料號、加工金額趨勢（依製程大項堆疊）。
         * 製程大項篩選（ptypes GET 參數）與自動分析是整頁重新查詢才會變，這裡不處理。
         */
        function updateStatistics() {
            var table = $('#transferTable').DataTable();
            var data = table.rows({ search: 'applied' }).data().toArray();

            var totalQty = 0, totalAmount = 0, totalLoss = 0, validCount = 0, zeroPrice = 0, highLoss = 0;
            var byType = {}, byMaker = {}, byPart = {};

            data.forEach(function(row) {
                var qty = parseFloat(row.transfer_qty) || 0;
                var loss = parseFloat(row.loss_qty) || 0;
                var price = parseFloat(row.price) || 0;
                var amount = parseFloat(row.process_amount);
                if (!amount && qty > 0 && price > 0) amount = qty * price;
                if (!amount || isNaN(amount)) amount = 0;

                validCount++; totalQty += qty; totalLoss += loss; totalAmount += amount;
                if (price === 0) zeroPrice++;
                if (qty > 0 && (loss / qty) > 0.1) highLoss++;

                var tn = (row.process_type_name && String(row.process_type_name).trim()) || '未分類';
                if (!byType[tn]) byType[tn] = { qty: 0, amount: 0, count: 0, loss: 0 };
                byType[tn].qty += qty; byType[tn].amount += amount; byType[tn].count++; byType[tn].loss += loss;

                var mk = row.maker_from_name || row.maker_from || '未知廠商';
                if (!byMaker[mk]) byMaker[mk] = { qty: 0, amount: 0, count: 0, loss: 0 };
                byMaker[mk].qty += qty; byMaker[mk].amount += amount; byMaker[mk].count++; byMaker[mk].loss += loss;

                var pid = (row.product_id || '').toString().trim() || '未知料號';
                if (!byPart[pid]) byPart[pid] = { qty: 0, amount: 0, count: 0, loss: 0 };
                byPart[pid].qty += qty; byPart[pid].amount += amount; byPart[pid].count++; byPart[pid].loss += loss;
            });

            // KPI 磚
            $('#stat-count').text(numberFormat(validCount));
            $('#stat-qty').text(numberFormat(totalQty));
            $('#stat-loss').text(numberFormat(totalLoss));
            var displayAmount = totalAmount, unit = '萬';
            if (totalAmount >= 100000000) { displayAmount = totalAmount / 100000000; unit = '億'; }
            else { displayAmount = totalAmount / 10000; }
            $('#stat-amount').text(numberFormat(displayAmount, 2));
            $('#amount-unit-title').text('(' + unit + ')');
            $('#amount-unit-bottom').text(unit + 'TWD');
            $('#stat-maker-count').text(Object.keys(byMaker).length);
            $('#stat-anomaly').text(numberFormat(zeroPrice + highLoss));

            tlaRenderTypeAnalysis(byType, totalAmount);
            tlaRenderMakerAnalysis(byMaker);
            tlaRenderPartAnalysis(byPart);
            tlaRenderTrendChart(data);
        }

        /** 製程大項分析：表格＋圓餅圖（金額佔比）＋長條圖 */
        function tlaRenderTypeAnalysis(byType, totalAmount) {
            var arr = tlaSortDesc(byType);
            var rowsHtml = '';
            arr.forEach(function(it) {
                var share = totalAmount > 0 ? (it.amount / totalAmount * 100) : 0;
                rowsHtml += '<tr><td>' + tlaEsc(it.name) + '</td><td class="n">' + numberFormat(it.count) + '</td>'
                    + '<td class="n">' + numberFormat(it.qty) + '</td><td class="n">' + numberFormat(it.amount / 10000, 2) + '</td>'
                    + '<td class="n">' + share.toFixed(1) + '%</td><td class="n">' + numberFormat(it.loss) + '</td></tr>';
            });
            $('#tblProcType tbody').html(rowsHtml || '<tr><td colspan="6" style="text-align:center;color:#bbb;">目前篩選下沒有資料</td></tr>');

            tlaDestroyChart('chProcTypePie');
            if (arr.length) {
                var pieData = arr.slice(0, 10).map(function(it, i) { return { name: it.name, y: it.amount, color: tlaColor(i) }; });
                var otherSum = arr.slice(10).reduce(function(s, it) { return s + it.amount; }, 0);
                if (otherSum > 0) pieData.push({ name: '其他', y: otherSum, color: '#cbb79a' });
                Highcharts.chart('chProcTypePie', {
                    chart: { type: 'pie' },
                    title: { text: '製程大項金額佔比', style: { fontSize: '13px', color: '#6B4423' } },
                    tooltip: { pointFormat: '{series.name}: <b>${point.y:,.0f}</b>（{point.percentage:.1f}%）' },
                    plotOptions: { pie: { dataLabels: { enabled: true, format: '{point.name} {point.percentage:.1f}%' } } },
                    series: [{ name: '金額', data: pieData }],
                    credits: { enabled: false }
                });
            }

            tlaDestroyChart('chProcTypeBar');
            if (arr.length) {
                var top = arr.slice(0, 12);
                Highcharts.chart('chProcTypeBar', {
                    chart: { type: 'bar' },
                    title: { text: '製程大項加工金額(萬)', style: { fontSize: '13px', color: '#6B4423' } },
                    xAxis: { categories: top.map(function(i) { return i.name; }) },
                    yAxis: { min: 0, title: { text: null } },
                    legend: { enabled: false },
                    series: [{ name: '金額(萬)', data: top.map(function(it, i) { return { y: +(it.amount / 10000).toFixed(2), color: tlaColor(i) }; }) }],
                    credits: { enabled: false }
                });
            }
        }

        /** 廠商分析：完整排行表格＋可調 Top N 的長條圖（取代原本只有前五大） */
        function tlaRenderMakerAnalysis(byMaker) {
            var arr = tlaSortDesc(byMaker);
            var total = arr.reduce(function(s, i) { return s + i.amount; }, 0);
            var topN = parseInt($('#makerTop').val(), 10) || 15;
            $('#makerHint').text('共 ' + arr.length + ' 家廠商，長條圖顯示金額前 ' + Math.min(topN, arr.length) + ' 家，下方表格列出全部');

            var rowsHtml = '';
            arr.forEach(function(it) {
                var share = total > 0 ? (it.amount / total * 100) : 0;
                var avgPrice = it.qty > 0 ? it.amount / it.qty : 0;
                var firstDate = MAKER_FIRST_DATES[it.name];
                var firstDateHtml = firstDate ? egFmtDate(firstDate) : '<span style="color:#bbb;">—</span>';
                rowsHtml += '<tr><td>' + tlaEsc(it.name) + '</td><td class="n">' + numberFormat(it.count) + '</td>'
                    + '<td class="n">' + numberFormat(it.qty) + '</td><td class="n">$' + numberFormat(it.amount / 10000, 2) + '</td>'
                    + '<td class="n">' + share.toFixed(1) + '%</td><td class="n">$' + numberFormat(avgPrice, 2) + '</td>'
                    + '<td class="n">' + numberFormat(it.loss) + '</td><td class="n">' + firstDateHtml + '</td></tr>';
            });
            $('#top-makers-body').html(rowsHtml || '<tr><td colspan="8" style="text-align:center;color:#bbb;">目前篩選下沒有資料</td></tr>');

            tlaDestroyChart('chMaker');
            if (arr.length) {
                var top = arr.slice(0, topN);
                Highcharts.chart('chMaker', {
                    chart: { type: 'bar', height: Math.max(300, top.length * 24) },
                    title: { text: null },
                    xAxis: { categories: top.map(function(i) { return i.name; }) },
                    yAxis: { min: 0, title: { text: '金額(萬)' } },
                    legend: { enabled: false },
                    tooltip: { pointFormat: '金額：<b>${point.y:,.2f} 萬</b>' },
                    series: [{ name: '金額(萬)', data: top.map(function(it, i) { return { y: +(it.amount / 10000).toFixed(2), color: tlaColor(i) }; }) }],
                    credits: { enabled: false }
                });
            }
        }

        /** 十大高加工成本料號（隨篩選重算） */
        function tlaRenderPartAnalysis(byPart) {
            var arr = tlaSortDesc(byPart).slice(0, 10);
            var rowsHtml = '';
            arr.forEach(function(it, idx) {
                var avgPrice = it.qty > 0 ? it.amount / it.qty : 0;
                var safe = String(it.name).replace(/'/g, "\\'");
                rowsHtml += '<tr><td class="n">' + (idx + 1) + '</td>'
                    + '<td><a href="javascript:void(0);" onclick="openProductFiles(\'' + safe + '\')" style="text-decoration:underline;color:#8a5a2b;">' + tlaEsc(it.name) + '</a></td>'
                    + '<td class="n">$' + numberFormat(it.amount / 10000, 2) + '</td>'
                    + '<td class="n">' + numberFormat(it.qty) + '</td>'
                    + '<td class="n">$' + numberFormat(avgPrice, 2) + '</td>'
                    + '<td class="n">' + numberFormat(it.count) + '</td>'
                    + '<td class="n">' + numberFormat(it.loss) + '</td></tr>';
            });
            $('#top-products-tbody').html(rowsHtml || '<tr><td colspan="7" style="text-align:center;color:#bbb;">目前篩選下沒有資料</td></tr>');
        }

        /** 加工金額趨勢：依製程大項堆疊（金額前 8 大項＋其他），可切換顯示金額或數量 */
        function tlaTrendByType(rows, groupBy, topN) {
            var totals = {}, cells = {}, keysSet = {};
            rows.forEach(function(row) {
                var key = getDateKey(row.transfer_date);
                keysSet[key] = true;
                var qty = parseFloat(row.transfer_qty) || 0;
                var price = parseFloat(row.price) || 0;
                var amt = parseFloat(row.process_amount);
                if (!amt && qty > 0 && price > 0) amt = qty * price;
                if (!amt || isNaN(amt)) amt = 0;
                var tn = (row.process_type_name && String(row.process_type_name).trim()) || '未分類';
                totals[tn] = (totals[tn] || 0) + amt;
                if (!cells[key]) cells[key] = {};
                if (!cells[key][tn]) cells[key][tn] = { amount: 0, qty: 0 };
                cells[key][tn].amount += amt;
                cells[key][tn].qty += qty;
            });
            var typeNames = Object.keys(totals).sort(function(a, b) { return totals[b] - totals[a]; });
            var topTypes = typeNames.slice(0, topN);
            var hasOther = typeNames.length > topN;
            var typeList = topTypes.slice();
            if (hasOther) typeList.push('其他');
            var categories = Object.keys(keysSet).sort();
            var series = {};
            typeList.forEach(function(tn) { series[tn] = { amount: categories.map(function() { return 0; }), qty: categories.map(function() { return 0; }) }; });
            categories.forEach(function(cat, ci) {
                var c = cells[cat];
                if (!c) return;
                Object.keys(c).forEach(function(tn) {
                    var target = topTypes.indexOf(tn) >= 0 ? tn : '其他';
                    if (!series[target]) return;
                    series[target].amount[ci] += c[tn].amount;
                    series[target].qty[ci] += c[tn].qty;
                });
            });
            return { categories: categories, types: typeList, series: series };
        }

        function tlaRenderTrendChart(data) {
            var metric = $('#trendMetric').val() || 'amount';
            var t = tlaTrendByType(data, chartGroupBy, 8);
            $('#trendGranLabel').text(chartGroupBy === 'month' ? '月' : (chartGroupBy === 'week' ? '週' : '日'));

            var series = t.types.map(function(tn, i) {
                var vals = metric === 'amount'
                    ? t.series[tn].amount.map(function(v) { return +(v / 10000).toFixed(2); })
                    : t.series[tn].qty.map(function(v) { return Math.round(v); });
                return { name: tn, data: vals, color: tn === '其他' ? '#cbb79a' : tlaColor(i) };
            });
            var yTitle = metric === 'amount' ? '金額 (萬TWD)' : '數量 (PCS)';

            tlaDestroyChart('analysis-chart');
            Highcharts.chart('analysis-chart', {
                chart: { type: 'column' },
                title: { text: null },
                xAxis: { categories: t.categories, crosshair: true, title: { text: '日期/區間' } },
                yAxis: { min: 0, title: { text: yTitle } },
                tooltip: {
                    headerFormat: '<span style="font-size:10px">{point.key}</span><table>',
                    pointFormat: '<tr><td style="color:{series.color};padding:0">{series.name}: </td><td style="padding:0"><b>{point.y:,.2f}</b></td></tr>',
                    footerFormat: '</table>',
                    shared: true,
                    useHTML: true
                },
                plotOptions: {
                    column: {
                        stacking: 'normal',
                        borderWidth: 0,
                        cursor: 'pointer',
                        point: { events: { click: function() { applyChartFilter(this.category); } } }
                    }
                },
                series: series,
                credits: { enabled: false }
            });
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

        // 清除製程大項篩選（送出整頁重查，自動分析／趨勢／製程大項分析都會回到「全部」）
        function clearPTypeFilter() {
            $('#fPType').val(null).trigger('change');
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