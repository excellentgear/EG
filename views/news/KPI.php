<?php
session_start();

// --- AJAX Handler ---
if (isset($_GET['action']) && $_GET['action'] === 'fetch_kpi') {
    header('Content-Type: application/json');
    
    // 確保 AJAX 請求也能存取到必要的檔案
    include_once '../../src/common/DBConnection.php';
    include_once '../../src/common/_config.php';

    $selectedYear = isset($_GET['year']) ? intval($_GET['year']) : date('Y');
    $conn = new DBConnection();

    // --- (技術)出圖準確率 KPI ---
    $kpi_sql = "
    SELECT
      raw.ym AS '年月',
      ROUND(
        SUM(CASE WHEN raw.days <= 3 THEN 1 ELSE 0 END) / COUNT(*) * 100,
        0
      ) AS 'KPI_VALUE'
    FROM (
      SELECT
        DATE_FORMAT(ot.`ateGet`, '%Y-%m') AS ym,
        CASE WHEN ot.`ateGet` = ot.`pmGet` THEN 1 ELSE COALESCE((SELECT SUM(cw.is_workday) FROM calendar_workday cw WHERE cw.`date` BETWEEN ot.`ateGet` AND ot.`pmGet`), 0) END AS days
      FROM `order_track` ot
      WHERE ot.`ate` IN ('109110201','112020603') AND YEAR(ot.`ateGet`) = :selectedYear
    ) AS raw
    GROUP BY raw.ym ORDER BY raw.ym;";
    $kpi_stmt = $conn->getPDO()->prepare($kpi_sql);
    $kpi_stmt->execute([':selectedYear' => $selectedYear]);
    $kpi_results = $kpi_stmt->fetchAll(PDO::FETCH_ASSOC);
    $kpi_by_month = [];
    for ($m = 1; $m <= 12; $m++) { $kpi_by_month[$m] = null; }
    foreach ($kpi_results as $row) { $month = (int)substr($row['年月'], 5, 2); $kpi_by_month[$month] = (int)$row['KPI_VALUE']; }

    // --- (生管)訂單準交率 KPI ---
    $pm_order_kpi_sql = "
    SELECT t.年月, ROUND((t.總訂單筆數 - IFNULL(u.未交筆數, 0)) / t.總訂單筆數 * 100, 0) AS KPI_VALUE
    FROM (SELECT DATE_FORMAT(Delivery_date, '%Y-%m') AS 年月, COUNT(*) AS 總訂單筆數 FROM order_track WHERE Client_name NOT IN ('寶嘉誠', '泳建') AND UPPER(d_id) <> 'ZZZ' AND LOWER(d_id) NOT REGEXP '-(jg|jh|hg)$' AND YEAR(Delivery_date) = :selectedYear GROUP BY 年月) t
    LEFT JOIN (SELECT DATE_FORMAT(Delivery_date, '%Y-%m') AS 年月, COUNT(*) AS 未交筆數 FROM order_list WHERE Client_name NOT IN ('寶嘉誠', '泳建') AND UPPER(d_id) <> 'ZZZ' AND LOWER(d_id) NOT REGEXP '-(jg|jh|hg)$' AND Qty = Open_Qty AND Order_status IS NULL AND YEAR(Delivery_date) = :selectedYear GROUP BY 年月) u
    ON t.年月 = u.年月 ORDER BY t.年月;";
    $pm_order_kpi_stmt = $conn->getPDO()->prepare($pm_order_kpi_sql);
    $pm_order_kpi_stmt->execute([':selectedYear' => $selectedYear]);
    $pm_order_kpi_results = $pm_order_kpi_stmt->fetchAll(PDO::FETCH_ASSOC);
    $pm_order_kpi_by_month = [];
    for ($m = 1; $m <= 12; $m++) { $pm_order_kpi_by_month[$m] = null; }
    foreach ($pm_order_kpi_results as $row) { $month = (int)substr($row['年月'], 5, 2); $pm_order_kpi_by_month[$month] = (int)$row['KPI_VALUE']; }

    echo json_encode(['tech_kpi' => $kpi_by_month, 'pm_order_kpi' => $pm_order_kpi_by_month]);
    exit; // 處理完 AJAX 請求後終止腳本
}

if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/news/KPI.php?in=999";
    header("Location:../../index.php");
    exit;
}

include '../../src/common/DBConnection.php';
include '../../src/store/_setting.php';
include '../../src/common/_config.php';

@$userName = $_SESSION['user_cname'];
@$id       = $_SESSION['id'];

$conn = new DBConnection();

// 獲取年份選擇器的值（如果有）
$selectedYear = isset($_GET['year']) ? intval($_GET['year']) : date('Y');
// 確保年份有效
$selectedYear = ($selectedYear < 2024 || $selectedYear > date('Y')) ? date('Y') : $selectedYear;

// --- 新增：獲取當前年份和月份 ---
$currentYear = (int)date('Y');
$currentMonth = (int)date('n');



// --- 新增：計算出圖準確率 KPI ---
$kpi_sql = "
SELECT
  raw.ym AS '年月',
  ROUND(
    SUM(CASE WHEN raw.days <= 4 THEN 1 ELSE 0 END) / COUNT(*) * 100,
    0
  ) AS 'KPI_VALUE'
FROM (
  SELECT
    DATE_FORMAT(ot.`ateGet`, '%Y-%m')            AS ym,
    CASE
      WHEN ot.`ateGet` = ot.`pmGet` THEN 1
      ELSE COALESCE(
        (
          SELECT SUM(cw.is_workday)
          FROM calendar_workday cw
          WHERE cw.`date`
            BETWEEN ot.`ateGet`
                AND ot.`pmGet`
        )
      , 0)
    END                                         AS days
  FROM `order_track` ot
  WHERE ot.`ate` IN ('109110201','112020603')
    AND YEAR(ot.`ateGet`) = :selectedYear
    AND ot.Client_name <> '中森' -- 新增此行以排除特定客戶
) AS raw
GROUP BY raw.ym
ORDER BY raw.ym;
";

$kpi_stmt = $conn->getPDO()->prepare($kpi_sql);
$kpi_stmt->execute([':selectedYear' => $selectedYear]);
$kpi_results = $kpi_stmt->fetchAll(PDO::FETCH_ASSOC);
$kpi_by_month = [];
for ($m = 1; $m <= 12; $m++) {
    $kpi_by_month[$m] = null; // Default to null
}
foreach ($kpi_results as $row) {
    $month = (int)substr($row['年月'], 5, 2);
    $kpi_by_month[$month] = (int)$row['KPI_VALUE'];
}

// --- 新增：計算訂單準交率 KPI ---
$pm_order_kpi_sql = "
SELECT
  t.年月,
  t.總訂單筆數,
  IFNULL(u.未交筆數, 0) AS 未交筆數,
  (t.總訂單筆數 - IFNULL(u.未交筆數, 0)) AS 已交筆數,
  ROUND(
    (t.總訂單筆數 - IFNULL(u.未交筆數, 0)) / t.總訂單筆數 * 100,
    0
  ) AS KPI_VALUE
FROM
  (
    SELECT
      DATE_FORMAT(Delivery_date, '%Y-%m') AS 年月,
      COUNT(*) AS 總訂單筆數
    FROM order_track
    WHERE Client_name NOT IN ('寶嘉誠', '泳建')
      AND UPPER(d_id) <> 'ZZZ'
      AND LOWER(d_id) NOT REGEXP '-(jg|jh|hg)$'
      AND YEAR(Delivery_date) = :selectedYear
    GROUP BY 年月
  ) t
LEFT JOIN
  (
    SELECT
      DATE_FORMAT(Delivery_date, '%Y-%m') AS 年月,
      COUNT(*) AS 未交筆數
    FROM order_list
    WHERE Client_name NOT IN ('寶嘉誠', '泳建')
      AND UPPER(d_id) <> 'ZZZ'
      AND LOWER(d_id) NOT REGEXP '-(jg|jh|hg)$'
      AND Qty = Open_Qty
      AND Order_status IS NULL
      AND YEAR(Delivery_date) = :selectedYear
    GROUP BY 年月
  ) u
ON t.年月 = u.年月
ORDER BY t.年月;
";

$pm_order_kpi_stmt = $conn->getPDO()->prepare($pm_order_kpi_sql);
$pm_order_kpi_stmt->execute([':selectedYear' => $selectedYear]);
$pm_order_kpi_results = $pm_order_kpi_stmt->fetchAll(PDO::FETCH_ASSOC);
$pm_order_kpi_by_month = [];
for ($m = 1; $m <= 12; $m++) {
    $pm_order_kpi_by_month[$m] = null; // Default to null
}
foreach ($pm_order_kpi_results as $row) {
    $month = (int)substr($row['年月'], 5, 2);
    $pm_order_kpi_by_month[$month] = (int)$row['KPI_VALUE'];
}


@$formatted_date = "";
if (!empty($datepicker_ate)) {
    try {
        $dt = new DateTime($datepicker_ate);
        $formatted_date = $dt->format("Y-n-j"); // 例如：2025/3/25
    } catch (Exception $e) {
        // 如果轉換失敗，保留原值
        $formatted_date = $datepicker_ate;
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

    <title>KPI</title>

    <!-- Bootstrap -->
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <!-- NProgress -->
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <!-- Custom Theme Style -->
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <!-- Datatables -->
    <link href="../../resource/css/buttons.bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/fixedHeader.bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/scroller.bootstrap.css" rel="stylesheet">
    <!-- 過長表格變+號 -->
    <link href="../../resource/css/dataTables.bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/responsive.bootstrap.css" rel="stylesheet">
    <!-- 引入 jQuery 與 Select2 的 CSS 與 JS -->
    <link href="https://cdn.jsdelivr.net/npm/select2@4.0.13/dist/css/select2.min.css" rel="stylesheet" />

    <!-- 月曆相關 -->
    <!-- <link href="../../resource/css/pages.css" rel="stylesheet"> -->

    <link rel="stylesheet" href="http://code.jquery.com/ui/1.11.4/themes/smoothness/jquery-ui.css">

    <style>
        /* 初始隱藏側邊欄以防止選單展開時閃爍 */
        #sidebar-menu {
            visibility: hidden;
        }
    </style>

    <style>
        /* 為 .item.form-group 設置一致的間距 */
        .item.form-group {
            display: flex;
            /* 保持彈性佈局 */
            align-items: center;
            /* 垂直置中 */
            justify-content: flex-start;
            /* 水平靠左 */
            margin-bottom: 5px;
            /* 確保每個條目有一致的底部間距 */
            gap: 10px;
            /* 控制 label 和 input 之間的固定距離 */
            flex-wrap: nowrap;
            /* 確保元素不會因換行而亂跳 */
        }

        /* 控制 label 寬度，統一對齊 */
        .item.form-group label {
            min-width: 60px;
            /* 固定寬度，避免長短不一導致未對齊 */
            text-align: left;
            /* 保持文字靠左 */
            margin: 0;
            /* 移除多餘的 margin */
        }

        /* 確保 input 保持一致的樣式 */
        .item.form-group input {
            flex: 1;
            /* 彈性寬度，讓輸入框可填滿可用空間 */
            max-width: 250px;
            /* 設置最大寬度，避免過大 */
            padding: 5px;
            font-size: 14px;
            border: 1px solid #ccc;
            border-radius: 4px;
            text-align: left;
            /* 確保文字靠左對齊 */
            box-sizing: border-box;
            /* 確保 padding 不會影響寬度 */
        }

        /* 確保 select 保持一致的樣式 */
        .item.form-group select {
            flex: 1;
            /* 彈性寬度，讓輸入框可填滿可用空間 */
            max-width: 250px;
            /* 設置最大寬度，避免過大 */
            padding: 5px;
            font-size: 14px;
            border: 1px solid #ccc;
            border-radius: 4px;
            text-align: left;
            /* 確保文字靠左對齊 */
            box-sizing: border-box;
            /* 確保 padding 不會影響寬度 */
        }

        .all-filters>.all-type {
            display: flex;
            /* 使用 Flexbox 讓內部項目水平排列 */
            flex-wrap: wrap;
            /* 支援換行，當空間不足時自動換行 */
            gap: 5px;
            /* 控制每個框之間的間距 */
            justify-content: space-between;
            /* 均勻分布框線 */
        }

        .all-filters>.all-filters {
            gap: 5px;
            /* 控制每個框之間的間距 */
        }

        /* 內層框線 */
        .all-type>.all-filters {
            border: 1.5px solid #ccc;
            border-radius: 8px;
            padding: 10px;
            margin-bottom: 10px;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            display: flex;
            flex-direction: column;
            /* 行內垂直排列，分為不同行 */
            gap: 5px;
        }

        /* 保持 .all-filters 並排 */
        .all-type {
            display: flex;
            /* 使用 Flexbox 讓內部項目水平排列 */
            flex-wrap: wrap;
            /* 允許換行 */
            gap: 5px;
            /* 設置間距 */
        }

        /* 其他內層框線內容不受影響 */
        .all-type>.all-filters {
            flex: 1 1 calc(30% - 10px);
            /* 保持並排三等分的樣式 */
            box-sizing: border-box;
            /* 確保內外邊距穩定 */
        }

        #Order_ps {
            width: 100%;
            height: 100%;
            max-width: 500px;
            /* 設置適當的寬度 */
            max-height: 100px;
            height: 100px;
            /* 高度可根據需求調整 */
            padding: 10px;
            font-size: 14px;
            border: 1px solid #ccc;
            border-radius: 4px;
            box-sizing: border-box;
            resize: vertical;
            /* 允許垂直調整高度 */
        }


        /* RWD 響應式設計：窄螢幕時改為垂直排列 */
        @media screen and (max-width: 768px) {
            .all-type {
                display: flex;
                flex-direction: column;
                /* 在窄螢幕時改為垂直排列整體 */
                gap: 5px;
            }

            /* 第一和第三 .all-filters 並排 */
            .all-type>.all-filters:nth-child(1),
            .all-type>.all-filters:nth-child(3) {
                display: flex;
                flex: 1 1 calc(50% - 10px);
                /* 各自占一半寬度，並保持間距 */
                box-sizing: border-box;
            }

            /* 第二個 .all-filters 獨占一行 */
            .all-type>.all-filters:nth-child(2) {
                display: flex;
                flex: 1 1 100%;
                /* 獨占整行 */
                box-sizing: border-box;
            }

            #Order_ps {
                width: 100%;
                height: 100%;
                max-width: 300px;
                /* 設置適當的寬度 */
                max-height: 100px;
                height: 100px;
                /* 高度可根據需求調整 */
                padding: 10px;
                font-size: 14px;
                border: 1px solid #ccc;
                border-radius: 4px;
                box-sizing: border-box;
                resize: vertical;
                /* 允許垂直調整高度 */
            }
        }


        .btn-xss {
            font-size: 8px;
            /* 調整字體大小 */
        }

        #table-DOWN td {
            overflow: hidden;
            /* 隱藏溢出內容 */
            text-overflow: ellipsis;
            /* 當內容過多時顯示省略號 */
        }

        .adjustable-font-size {
            font-size: calc(10px + 0.5vw);
            /* 根據視窗寬度調整字體大小 */
        }

        #table-DOWN {
            width: 100%;
            table-layout: auto;
        }

        #table-DOWN th,
        #table-DOWN td {
            padding-left: 5px;
            /* 左邊內間距 */
            padding-right: 5px;
            /* 右邊內間距 */
            white-space: nowrap;
            /* 強制不換行 */
        }

        .control-label-2 {
            margin: 0;
            /* 移除 margin */
        }

        .control-label-2 div {
            display: inline-flex;
            /* 使 div 元素與文字排列 */
            align-items: center;
            /* 垂直居中 */
        }

        .control-label-2 div figure {
            margin-right: 8px;
            /* 設定與文本間的距離 */
        }

        /* 表格樣式修改 */
        .table-wrapper {
            overflow-x: auto;
            /* 保留水平捲動 */
            overflow-y: hidden !important;
            /* 完全隱藏垂直捲動 */
            max-height: none !important;
            /* 移除高度限制 */
        }

        /* 固定表格標題，即使在水平捲動時 */
        #table-DOWN thead th {
            position: sticky;
            top: 0;
            z-index: 10;
            background-color: #ffffff;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
        }

        /* 回頂端按鈕樣式 */
        #backToTop {
            display: none;
            position: fixed;
            bottom: 20px;
            right: 30px;
            z-index: 99;
            font-size: 18px;
            border: none;
            outline: none;
            background-color: #555;
            color: white;
            cursor: pointer;
            padding: 10px 15px;
            border-radius: 4px;
            opacity: 0.7;
            transition: 0.3s;
        }

        #backToTop:hover {
            background-color: #333;
            opacity: 1;
        }

        .x_content {
            overflow-y: hidden !important;
            /* 確保內容區域也不出現垂直捲動 */
        }

        /* 調整表格行底色 */
        #table-DOWN {
            border-collapse: collapse;
            width: 100%;
        }

        /* 將原本的條紋樣式覆寫 */
        .table-striped>tbody>tr:nth-of-type(odd) {
            background-color: #f3f3f3;
            /* 奇數行使用淺灰色 */
        }

        .table-striped>tbody>tr:nth-of-type(even) {
            background-color: #ffffff;
            /* 偶數行使用白色 */
        }

        /* 覆蓋Bootstrap的表格條紋樣式 */
        .table-striped>tbody>tr {
            background-color: transparent;
            /* 清除原有背景 */
        }

        /* 確保表格完全顯示，不受限制 */
        .table-fixed-left {
            margin-bottom: 20px;
            /* 增加底部間距 */
            height: auto !important;
            /* 表格高度自適應 */
        }

        /* 增強表格行視覺效果 */
        #table-DOWN tr:nth-child(even) {
            background-color: #f9f9f9;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            border: 1px solid #dddddd;
            padding: 8px;
            text-align: left;
        }

        thead th {
            position: sticky;
            top: 0;
            background-color: white;
            z-index: 1;
        }

        .title {
            display: flex;
            flex-wrap: wrap;
        }

        .title a {
            margin: 5px;
        }

        @media (max-width: 600px) {
            .title a {
                flex: 0 1 calc(33.333% - 10px);
            }
        }

        @media (max-width: 400px) {
            .title a {
                flex: 0 1 calc(50% - 10px);
            }
        }

        /* 表格內多段篩選 */
        /* 整體篩選外框 */
        .all-filters2 {
            border: 1px solid #ccc;
            border-radius: 3px;
            padding: 4px;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 4px;
            margin-bottom: 10px;
        }

        /* 所有篩選欄皆採用同一樣式 */
        .all-filters2 input,
        .all-filters2 select {
            height: 26px;
            /* 與車床按鈕接近（可依需求微調） */
            font-size: 10px;
            /* 與上方 btn-xs 同大小 */
            line-height: 1;
            padding: 0 4px;
            border: 1px solid #ccc;
            border-radius: 3px;
        }

        .all-filters2 button {
            background-color: #337ab7;
            color: #fff;
            cursor: pointer;
        }

        /* 表格與原有樣式 */
        #table-DOWN {
            width: 100%;
            table-layout: auto;
            border-collapse: collapse;
        }

        #table-DOWN th,
        #table-DOWN td {
            padding-left: 5px;
            padding-right: 5px;
            white-space: nowrap;
            border: 1px solid #dddddd;
        }

        #table-DOWN td {
            overflow: hidden;
            text-overflow: ellipsis;
        }

        thead th {
            position: sticky;
            top: 0;
            background-color: white;
            z-index: 1;
        }

        .table-wrapper {
            overflow-x: auto;
            max-height: 400px;

        }

        /* 使 h2 中的 small 換行且與 h2 的內容左對齊 */
        .x_title h2 small {
            display: block;
            text-align: left;
            margin-left: 0;
            /* 可依需要調整字型大小、顏色等 */
            font-size: 12px;
        }

        /* 分頁控制樣式 */
        .pagination-controls {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
            padding: 5px;
            background-color: #f9f9f9;
            border-radius: 4px;
        }

        .pagination-info {
            font-size: 14px;
        }

        .pagination-buttons button {
            padding: 5px 10px;
            margin: 0 3px;
            background-color: #337ab7;
            color: white;
            border: none;
            border-radius: 3px;
            cursor: pointer;
        }

        .pagination-buttons button:disabled {
            background-color: #cccccc;
            cursor: not-allowed;
        }

        .page-selector {
            margin: 0 5px;
        }

        .records-per-page {
            margin-left: 10px;
        }

        /* 增強表格顯示 */
        #table-DOWN tbody tr:hover {
            background-color: #E0EBAF !important;
        }

        /* 根據記錄數啟用垂直捲動 */
        .table-wrapper.scrollable {
            overflow-y: auto !important;
            /* 啟用垂直捲動 */
            max-height: 600px !important;
            /* 設置最大高度 */
            border-bottom: 1px solid #ddd;
            /* 底部邊框 */
        }

        /* 優化性能的樣式 */
        .optimize-performance * {
            /* 減少渲染複雜度 */
            will-change: auto !important;
            /* 降低複雜動畫的效能開銷 */
            animation-duration: 0.001s !important;
            transition-duration: 0.001s !important;
        }

        /* 延遲加載非關鍵資源 */
        .optimize-performance img:not([loading="eager"]) {
            content-visibility: auto;
        }

        /* 減少滾動時的重繪 */
        .optimize-performance .table-wrapper {
            contain: content;
            content-visibility: auto;
            contain-intrinsic-size: 1000px;
        }

        /* 進一步優化表格渲染 */
        .optimize-performance #table-DOWN {
            contain: strict;
            content-visibility: auto;
        }

        /* 返回頂部按鈕樣式 */
        #back-to-top {
            position: fixed;
            bottom: 20px;
            right: 20px;
            display: none;
            background-color: #007bff;
            color: white;
            border: none;
            border-radius: 50%;
            width: 50px;
            height: 50px;
            text-align: center;
            font-size: 24px;
            cursor: pointer;
            z-index: 99;
            opacity: 0.8;
            transition: opacity 0.3s;
        }

        #back-to-top:hover {
            opacity: 1;
        }

        /* 性能優化相關樣式 */
        .optimize-performance * {
            transition: none !important;
            animation: none !important;
        }

        /* 滾動中減少渲染消耗 */
        body.scrolling .table-container {
            will-change: transform;
        }

        body.scrolling .non-essential {
            visibility: hidden;
        }

        /* 大數據表格優化 */
        [data-large-table="true"] {
            contain: content;
            will-change: transform;
        }

        /* 提高表格渲染性能 */
        table {
            border-collapse: separate;
            border-spacing: 0;
            table-layout: fixed;
        }

        /* 減少重複渲染 */
        .batch-update {
            contain: content;
        }

        /* 優化滾動性能 */
        .table-wrapper {
            overflow: auto;
            -webkit-overflow-scrolling: touch;
            overscroll-behavior: contain;
            scroll-behavior: auto;
        }

        .copy-btn {
            margin-right: 5px;
            background-color: #f0ad4e; /* Yellow background, matches btn-warning */
            color: white;             /* White icon/text, matches btn-warning */
            border: none;             /* As per OreadyReply_ForPm_BaseOfTime.php */
            padding: 1px 2px;         /* As per OreadyReply_ForPm_BaseOfTime.php */
            vertical-align: middle;   /* As per OreadyReply_ForPm_BaseOfTime.php */
            cursor: pointer;          /* As per OreadyReply_ForPm_BaseOfTime.php */
            border-radius: 3px;       /* Added for button feel, common with btn-xs */
            display: inline-block;    /* Added for padding to work correctly and button feel */
            font-size: 0.9em;         /* Consistent with previous icon styling */
            line-height: 1.42857143;  /* Default Bootstrap line-height for buttons */
            text-align: center;
        }
        .copy-btn:hover {
            background-color: #ec971f; /* Darker yellow, matches btn-warning:hover */
            color: white;
        }
        .copy-btn.fa-check { /* Style for when it's a checkmark */
            background-color: #f0ad4e; /* Bootstrap success background */
            color: white;
            /* border: none; /* border is already none */
        }
    </style>
<script>
    // 輔助函式：將 "2025/4/10" 格式的字串轉換成 Date 物件
    function parseDateFromNormalizedString(dateStr) {
        var parts = dateStr.split("/");
        if (parts.length !== 3) return null;
        var year = parseInt(parts[0], 10);
        var month = parseInt(parts[1], 10);
        var day = parseInt(parts[2], 10);
        return new Date(year, month - 1, day);
    }

    // 輔助函式：比較兩個日期是否相同（僅年月日）
    function datesAreEqual(d1, d2) {
        if (!d1 || !d2) return false;
        return d1.getFullYear() === d2.getFullYear() &&
            d1.getMonth() === d2.getMonth() &&
            d1.getDate() === d2.getDate();
    }


    // 處理年份選擇變更
    function changeYear(year) {
        // 重新導向到同一頁面但帶有年份參數
        window.location.href = `?year=${year}`;
    }

    // 定義 fetchDataAndUpdate 函數
    function fetchDataAndUpdate() {
        // 獲取當前選擇的年份
        const yearSelect = document.getElementById('year-select');
        const selectedYear = yearSelect ? yearSelect.value : new Date().getFullYear();

        // 獲取當前頁面狀態
        const currentFilters = {
            customer: document.getElementById("customer-filter") ? document.getElementById("customer-filter").value : "",
            bom: document.getElementById("bom-filter") ? document.getElementById("bom-filter").value : "",
            order: document.getElementById("order-filter") ? document.getElementById("order-filter").value : "",
            date: document.getElementById("date-filter") ? document.getElementById("date-filter").value : "",
            vendor: document.getElementById("vendor-filter") ? document.getElementById("vendor-filter").value : "",
            globalSearch: document.getElementById("global-search") ? document.getElementById("global-search").value : ""
        };

        // 記錄當前的頁碼和過濾條件
        const savedPage = currentPage;
        const scrollPosition = document.documentElement.scrollTop || document.body.scrollTop;

        // 獲取當前頁面的訂單ID列表
        var visibleOrderIds = [];

        // 只處理當前頁面顯示的行
        if (filteredRows && filteredRows.length > 0) {
            var startIndex = (currentPage - 1) * recordsPerPage;
            var endIndex = Math.min(startIndex + recordsPerPage, filteredRows.length);

            for (var i = startIndex; i < endIndex; i++) {
                var orderId = filteredRows[i].getAttribute("data-orderid");
                if (orderId) {
                    visibleOrderIds.push(orderId);
                }
            }
        }

        // 如果當前頁面沒有顯示任何行，則正常更新整個表格
        if (visibleOrderIds.length === 0) {
            console.log("無法確定當前顯示的訂單，執行完整更新");

            // 使用 AJAX 請求獲取數據
            $.ajax({
                url: "../../src/store/_fetch_order_dataT.php",
                method: "GET",
                data: {
                    year: selectedYear
                },
                dataType: "json",
                success: function(data) {
                    updateTableWithData(data);

                    // 恢復過濾條件
                    restoreFiltersAndPosition(currentFilters, savedPage, scrollPosition);
                },
                error: function(xhr, status, error) {
                    console.error("獲取數據失敗：" + error);
                }
            });
            return;
        }

        console.log("僅更新當前頁面，顯示的訂單ID: " + visibleOrderIds.join(', '));

        // 使用 AJAX 請求獲取特定訂單的數據
        $.ajax({
            url: "../../src/store/_fetch_order_dataT.php",
            method: "GET",
            data: {
                year: selectedYear,
                orderIds: visibleOrderIds.join(',')
            },
            dataType: "json",
            success: function(data) {
                // 僅更新當前頁面的數據
                updateCurrentPageData(data);

                console.log("當前頁面數據已更新，共 " + data.length + " 筆記錄");
            },
            error: function(xhr, status, error) {
                console.error("獲取數據失敗：" + error);
            }
        });
    }


    // 輔助函數：恢復過濾條件和位置
    function restoreFiltersAndPosition(filters, savedPage, scrollPosition) {
        // 恢復篩選條件

        if (document.getElementById("date-filter")) document.getElementById("date-filter").value = filters.date;

        if (document.getElementById("global-search")) document.getElementById("global-search").value = filters.globalSearch;

        // 應用篩選
        filterTable();


        // 恢復滾動位置
        setTimeout(() => {
            window.scrollTo(0, scrollPosition);
        }, 100);
    }

    // 回頂端按鈕功能
    document.addEventListener("DOMContentLoaded", function() {
        var backToTopBtn = document.getElementById("backToTop");

        // 監聽滾動事件，決定何時顯示按鈕
        window.onscroll = function() {
            if (document.body.scrollTop > 20 || document.documentElement.scrollTop > 20) {
                backToTopBtn.style.display = "block";
            } else {
                backToTopBtn.style.display = "none";
            }
        };

        // 點擊按鈕回到頂部
        backToTopBtn.addEventListener("click", function() {
            document.body.scrollTop = 0; // Safari
            document.documentElement.scrollTop = 0; // Chrome, Firefox, IE, Opera
        });

        // 初始化過濾和分頁
        filterTable();
    });

    // 格式化日期為月/日格式
    function formatDate(dateString) {
        if (!dateString) return '';
        const date = new Date(dateString);
        // 確保日期有效
        if (isNaN(date.getTime())) return dateString;
        return `${date.getMonth() + 1}/${date.getDate()}`;
    }


    // 修改顯示表格函數，處理日期格式
    function displayPage() {
        // 現有代碼...

        // 處理日期顯示格式
        const rows = document.querySelectorAll('#table-DOWN tbody tr');
        for (let i = 0; i < rows.length; i++) {
            const cells = rows[i].querySelectorAll('td');

            // 處理日期欄位 - 特別是日期那些欄位
            for (let j = 0; j < cells.length; j++) {
                const cell = cells[j];
                // 處理接單日期和交期欄位 (index 1 和 2)
                if (j === 1 || j === 2) {
                    const text = cell.textContent;
                    if (text && text.match(/^\d{4}-\d{2}-\d{2}$/)) {
                        // 將 2025-03-27 格式轉換為 25y/3/27
                        const parts = text.split('-');
                        const year = parts[0].substring(2);
                        const month = parseInt(parts[1]);
                        const day = parseInt(parts[2]);
                        cell.textContent = `${year}y/${month}/${day}`;
                    }
                }
            }
        }
    }

 

    // 確保全局變數只定義一次
    // 注意：這些變數應該在其他腳本前定義
    window.shouldAutoUpdate = window.shouldAutoUpdate || false;
    window.autoUpdateInterval = window.autoUpdateInterval || null;
    window.lastUpdateTime = window.lastUpdateTime || null;
</script>


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
                        <h2>KPI 總覽</h2>
                        <div class="title_left">
                            <h4>
                                <?php
                                if (!empty($_GET['message'])) {
                                    if ($_GET['message'] == "success") {
                                        echo "<div class=\"alert alert-success fade in alert-dismissable\" id=\"message\">
                                        <a href=\"#\" class=\"close\" data-dismiss=\"alert\" aria-label=\"close\" title=\"close\">×</a>
                                        新增/修改成功
                                        </div>";
                                    } else if ($_GET['message'] != "success") {
                                        @$var = $_GET['message'];
                                        echo "<div class=\"alert alert-success fade in alert-dismissable\" id=\"message\">
                                        <a href=\"#\" class=\"close\" data-dismiss=\"alert\" aria-label=\"close\" title=\"close\">×</a>
                                        $var
                                        </div>";
                                    }
                                }
                                ?>
                            </h4>
                        </div>
                    </div>
                    <div class="clearfix"></div>

                    <!-- 總覽 -->
                    <form method="POST" action="">
                        <div class="row">
                            <div class="col-md-12 col-sm-12 col-xs-12">
                                <div class="x_panel">
                                    <div class="x_title">
                                        <h2>KPI總覽
                                        </h2>
                                        <ul class="nav navbar-right panel_toolbox">
                                            <li><a class="collapse-link"><i class="fa fa-chevron-up"></i></a></li>
                                            <!-- <li><a class="close-link"><i class="fa fa-close"></i></a></li> -->
                                        </ul>
                                        <div class="clearfix"></div>
                                        <!-- 過濾條件區 -->
                                        <div class="all-filters2" style="margin-bottom:10px;">
                                            <!-- 年份選擇器 (onchange 已修改為觸發 AJAX) -->
                                            <select id="year-select" onchange="updateKpiData(this.value)">
                                                <?php
                                                $currentYear = date('Y');
                                                for ($year = 2024; $year <= $currentYear; $year++) {
                                                    $selected = ($year == $selectedYear) ? 'selected' : '';
                                                    echo "<option value=\"$year\" $selected>$year</option>";
                                                }
                                                ?>
                                            </select>
                                            </div>
                                    </div>
                                    <div class="x_content">

                                    <!-- KPI 顯示區塊 (新增 ID 以便 AJAX 更新) -->
                                    <div id="kpi-display-area">
                                        <div class="x_content">
                                            <div id="tech-kpi-container" class="all-filters2" style="justify-content: center; align-items: center; margin-bottom: 10px; padding: 8px;">
                                                <span style="font-weight: bold; margin-right: 15px;">(技術)出圖準確率 KPI</span>
                                                <?php for ($m = 1; $m <= 12; $m++): ?>
                                                    <?php
                                                    $kpi_value = $kpi_by_month[$m];
                                                    $display_text = '';
                                                    $color_style = '';
                                                    if ($selectedYear == $currentYear && $m > $currentMonth) {
                                                        $display_text = 'NA';
                                                    } else {
                                                        $display_text = ($kpi_value === null) ? '?%' : $kpi_value . '%';
                                                        $color_style = ($kpi_value !== null && $kpi_value < 80) ? 'color: red;' : '';
                                                    }
                                                    ?>
                                                    <span data-month="<?= $m ?>" style="margin: 0 10px; <?= $color_style ?>">
                                                        <?= $m ?>月 <span class="kpi-value"><?= $display_text ?></span>
                                                    </span>
                                                <?php endfor; ?>
                                            </div>
                                        </div> <!-- x_content -->
                                        <div class="x_content">
                                            <div id="pm-kpi-container" class="all-filters2" style="justify-content: center; align-items: center; margin-bottom: 10px; padding: 8px;">
                                                <span style="font-weight: bold; margin-right: 15px;">(生管)訂單準交率 KPI</span>
                                                <?php for ($m = 1; $m <= 12; $m++): ?>
                                                    <?php
                                                    $pm_order_kpi_value = $pm_order_kpi_by_month[$m];
                                                    $pm_order_display_text = '';
                                                    $pm_order_color_style = '';
                                                    if ($selectedYear == $currentYear && $m > $currentMonth) {
                                                        $pm_order_display_text = 'NA';
                                                    } else {
                                                        $pm_order_display_text = ($pm_order_kpi_value === null) ? '?%' : $pm_order_kpi_value . '%';
                                                        $pm_order_color_style = ($pm_order_kpi_value !== null && $pm_order_kpi_value < 80) ? 'color: red;' : '';
                                                    }
                                                    ?>
                                                    <span data-month="<?= $m ?>" style="margin: 0 10px; <?= $pm_order_color_style ?>">
                                                        <?= $m ?>月 <span class="kpi-value"><?= $pm_order_display_text ?></span>
                                                    </span>
                                                <?php endfor; ?>
                                            </div>
                                        </div> <!-- #kpi-display-area -->
                                </div> <!-- x_panel -->
                            </div> <!-- col -->
                        </div> <!-- row -->
                    </form>
                </div>

                <!-- 線圖 -->
                <script src="../../code/highcharts.js"></script>
                <script src="../../code/modules/exporting.js"></script>
                <script src="../../code/modules/export-data.js"></script>
                <script src="../../code/modules/accessibility.js"></script>
                <!-- /page content -->

            </div>
            <!-- footer content include -->
            <?php include '../partPage/footer.html' ?>
            <!-- /footer content include -->
        </div>
    </div>

    <!-- 回頂端按鈕 -->
    <button id="backToTop" title="回到頂端">
        <i class="fa fa-chevron-up"></i>
    </button>

    <!-- jQuery -->
    <script src="../../resource/js/jquery.min.js"></script>
    <!-- Bootstrap -->
    <script src="../../resource/js/bootstrap.min.js"></script>
    <!-- FastClick -->
    <script src="../../resource/js/fastclick.js"></script>
    <!-- NProgress -->
    <script src="../../resource/js/nprogress.js"></script>
    <!-- iCheck -->
    <script src="../../resource/js/icheck.min.js"></script>
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
    <!-- Custom Theme Scripts -->
    <script src="../../resource/js/custom.min.js"></script>

    <script src="http://code.jquery.com/jquery-1.10.2.js"></script>
    <script src="http://code.jquery.com/ui/1.11.4/jquery-ui.js"></script>

    <!-- 引入ORDER_ID自動更新相關JavaScript -->
    <script src="orderIdAutoUpdate.js"></script>

    <script>

        // 回頂端按鈕功能
        document.addEventListener("DOMContentLoaded", function() {
            var backToTopBtn = document.getElementById("backToTop");

            // 監聽滾動事件，決定何時顯示按鈕
            window.onscroll = function() {
                if (document.body.scrollTop > 20 || document.documentElement.scrollTop > 20) {
                    backToTopBtn.style.display = "block";
                } else {
                    backToTopBtn.style.display = "none";
                }
            };

            // 點擊按鈕回到頂部
            backToTopBtn.addEventListener("click", function() {
                document.body.scrollTop = 0; // Safari
                document.documentElement.scrollTop = 0; // Chrome, Firefox, IE, Opera
            });

            // 初始化過濾和分頁
            filterTable();
        });

        // 初始化頁面時檢查是否需要啟用捲動
        document.addEventListener("DOMContentLoaded", function() {
            // ... 現有代碼 ...

            // 初始檢查是否需要啟用表格捲動
            var recordsPerPageSelector = document.getElementById("records-per-page");
            if (recordsPerPageSelector) {
                var selectedValue = parseInt(recordsPerPageSelector.value, 10);
                var tableWrapper = document.querySelector('.table-wrapper');
                if (tableWrapper && selectedValue > 10) {
                    tableWrapper.classList.add('scrollable');
                }
            }

            // ... 現有代碼 ...
        });
    </script>

    <!-- 初始化自動更新功能 -->
    <script>
        // 頁面載入完成後，自動啟動ORDER_ID更新
        document.addEventListener("DOMContentLoaded", function() {
            // 確保頁面上有logVisibleOrderIds函數
            if (typeof logVisibleOrderIds === "function") {
                console.log("正在初始化ORDER_ID自動更新功能...");
                // 延遲2秒啟動，確保頁面已完全加載
                setTimeout(function() {
                    if (window.orderIdLogger && typeof window.orderIdLogger.startOrderIdAutoUpdate === "function") {
                        window.orderIdLogger.startOrderIdAutoUpdate();
                    } else {
                        console.error("找不到orderIdLogger.startOrderIdAutoUpdate函數，無法啟動自動更新");
                    }
                }, 2000);
            } else {
                console.error("找不到logVisibleOrderIds函數，無法啟動自動更新");
            }
        });
    </script>
    <script>
// 處理年份變更的函數
function updateKpiData(selectedYear) {
    // 顯示載入中的提示 (可選)
    document.getElementById('tech-kpi-container').style.opacity = '0.5';
    document.getElementById('pm-kpi-container').style.opacity = '0.5';

    // 使用 Fetch API 發送 AJAX 請求
    fetch(`KPI.php?action=fetch_kpi&year=${selectedYear}`)
        .then(response => response.json())
        .then(data => {
            // 成功獲取資料後，更新畫面
            renderKpiDisplays(data.tech_kpi, data.pm_order_kpi, selectedYear);
            
            // 更新瀏覽器網址列，但不會重整頁面
            history.pushState(null, '', `?year=${selectedYear}`);
        })
        .catch(error => {
            console.error('Error fetching KPI data:', error);
            alert('更新 KPI 資料失敗！');
        })
        .finally(() => {
            // 移除載入中提示 (可選)
            document.getElementById('tech-kpi-container').style.opacity = '1';
            document.getElementById('pm-kpi-container').style.opacity = '1';
        });
}

// 根據從 AJAX 獲取的資料來渲染 KPI 畫面的函數
function renderKpiDisplays(techData, pmData, selectedYear) {
    const currentYear = new Date().getFullYear();
    const currentMonth = new Date().getMonth() + 1;

    // 更新技術 KPI
    const techContainer = document.getElementById('tech-kpi-container');
    for (let m = 1; m <= 12; m++) {
        const monthSpan = techContainer.querySelector(`span[data-month="${m}"]`);
        const valueSpan = monthSpan.querySelector('.kpi-value');
        const kpiValue = techData[m];

        let displayText = '';
        let colorStyle = '';

        if (selectedYear == currentYear && m > currentMonth) {
            displayText = 'NA';
        } else {
            displayText = (kpiValue === null) ? '?%' : kpiValue + '%';
            colorStyle = (kpiValue !== null && kpiValue < 80) ? 'color: red;' : '';
        }

        valueSpan.textContent = displayText;
        monthSpan.style.color = colorStyle ? 'red' : ''; // 直接設定 style
    }

    // 更新生管 KPI
    const pmContainer = document.getElementById('pm-kpi-container');
    for (let m = 1; m <= 12; m++) {
        const monthSpan = pmContainer.querySelector(`span[data-month="${m}"]`);
        const valueSpan = monthSpan.querySelector('.kpi-value');
        const kpiValue = pmData[m];

        let displayText = '';
        let colorStyle = '';

        if (selectedYear == currentYear && m > currentMonth) {
            displayText = 'NA';
        } else {
            displayText = (kpiValue === null) ? '?%' : kpiValue + '%';
            colorStyle = (kpiValue !== null && kpiValue < 80) ? 'color: red;' : '';
        }

        valueSpan.textContent = displayText;
        monthSpan.style.color = colorStyle ? 'red' : ''; // 直接設定 style
    }
}
</script>

<script>
    // 頁面載入後，取消側邊欄的自動展開
    // 此腳本在範本的 custom.min.js 之後執行
    $(document).ready(function() {
        // 尋找被範本腳本自動設為 'active' 的主選單項目
        var $activeMenu = $('#sidebar-menu .nav.side-menu > li.active');

        // 如果找到，立即將其關閉且不帶動畫效果
        if ($activeMenu.length) {
            $activeMenu.removeClass('active').find('ul.child_menu').hide();
            $activeMenu.find('li.current-page').removeClass('current-page');
        }

        // 當選單已處於正確的收合狀態後，再將側邊欄設為可見
        $('#sidebar-menu').css('visibility', 'visible');
    });
</script>

</body>

</html>