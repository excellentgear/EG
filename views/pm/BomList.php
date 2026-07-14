<?php
session_start();

if (!isset($_SESSION['userName'])) //若使用者未設定，則返回登入頁
{
    $_SESSION['lastpage']="../../views/pm/OreadyReply_ForPm_BaseOfTime.php";
    header("Location:../../index.php"); //返回登入頁
    exit();
}

include '../../src/common/DBConnection.php';
include '../../src/store/_setting.php';
include '../../src/common/_config.php';

@$userName      = $_SESSION['user_cname'];
@$id            = $_SESSION['id'];


@$conn = new DBConnection();

    // 所有報工
    @$OreadyReply_list = $conn->getAll("SELECT 
        vw_vw_oreadyreply_forpm.OreadyReply_id,
        vw_vw_oreadyreply_forpm.bom_ing_id,
        bom.bom,
        bom.d_id,
        SUBSTRING(REPLACE(bom.Client_Name, ' ', ''), 1, 3) AS Client_Name, /* 去除空格並限制顯示三個中文字 */
        bom_ing.process_no,
        process_no.ProcessName,
        process_no.process_type_id AS pti,
        bom_ing.maker_id,
        bom_ing.sqty,
        vw_vw_oreadyreply_forpm.oready_sqty_total,
        vw_vw_oreadyreply_forpm.ng_sqty_total,
        CONCAT(DATE_FORMAT(vw_vw_oreadyreply_forpm.Created_At_end, '%y'), 'y/', DATE_FORMAT(vw_vw_oreadyreply_forpm.Created_At_end, '%c/%e')) AS Created_At_s
    FROM 
        vw_vw_oreadyreply_forpm
        LEFT JOIN bom_ing ON bom_ing.bom_ing_id = vw_vw_oreadyreply_forpm.bom_ing_id
        LEFT JOIN bom ON bom.bom = bom_ing.bom
        LEFT JOIN process_no ON process_no.ProcessNo = bom_ing.process_no
    ORDER BY 
        vw_vw_oreadyreply_forpm.Created_At_end DESC;");


@$BOM           =$_GET['BOM'];         
@$ProcessNo     =$_GET['ProcessNo'];   
@$MakerId       =$_GET['MakerId'];     
@$sqty          =$_GET['sqty'];  
@$d_id          =$_GET['d_id'];
@$D_Setting_Id  =$_GET['dsi'];
@$Client_Name   =$_GET['c'];

@$pn            =$_SESSION['pn'];

// 已報工紀錄
@$PmOreadyReply_list = $conn->getAll("SELECT bom.d_id,vw_oreadyreply_list.reply_id,vw_oreadyreply_list.BOM,vw_oreadyreply_list.oready_sqty,
date(vw_oreadyreply_list.Created_At) as Created_date,vw_oreadyreply_list.Created_At as Created_date_ORDER,vw_oreadyreply_list.Created_By,
vw_oreadyreply_list.ok_sqty,vw_oreadyreply_list.ng_sqty_total,user.user_cname,vw_oreadyreply_list.ps,
vw_oreadyreply_list.m,vw_oreadyreply_list.t,vw_oreadyreply_list.width,vw_oreadyreply_list.mc_id,vw_oreadyreply_list.mc_time,
vw_oreadyreply_list.processing_time,machine_list.machine,vw_oreadyreply_list.mc_user,
vw_oreadyreply_list.sqty,
vw_oreadyreply_list.oready_sqty,vw_oreadyreply_list.ProcessName,vw_oreadyreply_list.ProcessNo,vw_oreadyreply_list.MakerId
FROM vw_oreadyreply_list
LEFT JOIN user ON user.id=vw_oreadyreply_list.Created_By
LEFT JOIN machine_list ON vw_oreadyreply_list.machine_id=machine_list.machine_id
LEFT JOIN bom ON bom.bom=vw_oreadyreply_list.BOM
WHERE vw_oreadyreply_list.BOM='$BOM' AND vw_oreadyreply_list.ProcessNo='$ProcessNo'
ORDER BY Created_date_ORDER DESC");


?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <!-- Meta, title, CSS, favicons, etc. -->
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>已報工未移轉(報工順序為基準)</title>

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
    <link href="https://cdn.jsdelivr.net/npm/select2@4.0.13/dist/css/select2.min.css" rel="stylesheet"/>

</head>
<style>
    .btn-xss {
        font-size: 8px; /* 調整字體大小 */
    }

    #table-DOWN td {
        overflow: hidden; /* 隱藏溢出內容 */
        text-overflow: ellipsis; /* 當內容過多時顯示省略號 */
    }
    .adjustable-font-size {
        font-size: calc(10px + 0.5vw); /* 根據視窗寬度調整字體大小 */
    }

    #table-DOWN {
        width: 100%;
        table-layout: auto;
    }
    #table-DOWN th, #table-DOWN td {
        padding-left: 5px;  /* 左邊內間距 */
        padding-right: 5px; /* 右邊內間距 */
        white-space: nowrap; /* 強制不換行 */
    }
    .control-label {
        margin: 0; /* 移除 margin */
    }
    .control-label div {
        display: inline-flex; /* 使 div 元素與文字排列 */
        align-items: center; /* 垂直居中 */
    }
    .control-label div figure {
        margin-right: 8px; /* 設定與文本間的距離 */
    }
    /* 球燈 */
    .circle_red {
        display: block;
        background: #cd5c5c; /* 印度紅 */
        border-radius: 50%;
        height: 18px;
        width: 18px;
        margin: 0;
        background: radial-gradient;
    }
    .circle_green {
        display: block;
        background: MediumSeaGreen; /* 中海綠 */
        border-radius: 50%;
        height: 18px;
        width: 18px;
        margin: 0;
        background: radial-gradient;
    }
    .circle_y {
        display: block;
        background: #FFD306; /* 黃 */
        border-radius: 50%;
        height: 18px;
        width: 18px;
        margin: 0;
        background: radial-gradient;
    }
    .table-wrapper {
        overflow-x: auto;
        max-height: 400px; /* 根據你的需要調整高度 */
    }
    table {
        width: 100%;
        border-collapse: collapse;
    }
    th, td {
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
    .all-filters {
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
    .all-filters button,
    .all-filters input,
    .all-filters select {
      height: 26px;       /* 與車床按鈕接近（可依需求微調） */
      font-size: 10px;     /* 與上方 btn-xs 同大小 */
      line-height: 1;
      padding: 0 4px;
      border: 1px solid #ccc;
      border-radius: 3px;
    }
    .all-filters button {
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
    #table-DOWN th, #table-DOWN td {
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

    /* BOM 色彩篩選按鈕：設為 18px 圓形，並加入 relative 定位供 tooltip 絕對定位 */
    #bomColorFilter {
      position: relative;
      width: 18px;
      height: 18px;
      padding: 0;
      border-radius: 50%;
      display: flex;
      justify-content: center;
      align-items: center;
      background-color: #337ab7;
      color: #fff;
      cursor: pointer;
      font-size: 8px;
    }

    /* Tooltip 基本樣式：利用 visibility 與 opacity 控制顯示 */
    #bomColorFilter .tooltip {
      visibility: hidden;
      opacity: 0;
      position: absolute;
      top: 22px;  /* 位於按鈕下方 */
      left: 50%;
      transform: translateX(-50%);
      background-color: #fff;
      border: 1px solid #ccc;
      border-radius: 4px;
      padding: 4px;
      font-size: 8px;
      white-space: nowrap;
      z-index: 10;
      box-shadow: 0 1px 3px rgba(0,0,0,0.2);
      transition: opacity 0.2s ease-in-out;
      color: #000; /* 說明文字為黑色 */
    }

    /* 當滑鼠懸停於 BOM 按鈕時顯示 tooltip */
    #bomColorFilter:hover .tooltip {
      visibility: visible;
      opacity: 1;
    }

    /* tooltip 內部的內容，採用控制標籤的 inline-flex 方式對齊 */
    .tooltip-content .control-label {
      margin: 0; /* 參照原有 control-label */
    }
    .tooltip-content .control-label div {
      display: inline-flex;
      align-items: center;
    }
    .tooltip-content .control-label div figure {
      margin-right: 8px; /* 與文本間的距離，參考原有設定 */
    }
</style>
<script>
    // ---------- 輔助函數 ----------
    // 將日期物件正規化（只保留年月日）
    function normalizeDate(dt) {
        return new Date(dt.getFullYear(), dt.getMonth(), dt.getDate());
    }
    // 將日期字串（格式如 "yy/m/d" 或 "yyyy/m/d"）轉換為 Date 物件
    function convertDateFormat(dateStr) {
        const parts = dateStr.split("/");
        let year;
        if (parts[0].includes("y")) {
            year = parseInt(parts[0].replace("y", ""), 10) + 2000;
        } else if(parts[0].length === 2) {
            year = "20" + parts[0];
        } else {
            year = parts[0];
        }
        const month = parts[1].length === 1 ? "0" + parts[1] : parts[1];
        const day = parts[2].length === 1 ? "0" + parts[2] : parts[2];
        return new Date(year + "-" + month + "-" + day);
    }

    // ---------- 全域變數 ----------
    var currentBomFilter = "all"; // BOM 色彩篩選狀態
    var orderComparison = "<";    // （本功能已修改：若未指定操作符，發單比較預設使用 "="，此全域變數現僅供切換按鈕使用，但在篩選中改為 "="）
    var ptiSearch = "";           // 製程關鍵字搜尋（如 "1", "2", "4"…）

    // ---------- BOM 色彩篩選操作（原始邏輯） ----------
    function toggleBomColorFilter() {
        const btn = document.getElementById("bomColorFilter");
        if (currentBomFilter === "all") {
            currentBomFilter = "green";
            btn.innerHTML = '<figure class="circle_green" style="margin:0;width:18px;height:18px;display:block;"></figure>' +
                            '<div class="tooltip"><div class="tooltip-content">' +
                            '<div class="control-label"><div><figure class="circle_green"></figure><span>已完工</span></div></div>' +
                            '<div class="control-label"><div><figure class="circle_y"></figure><span>加工中</span></div></div>' +
                            '<div class="control-label"><div><figure class="circle_red"></figure><span>NG數已達加工數1/3</span></div></div>' +
                            '</div></div>';
        } else if (currentBomFilter === "green") {
            currentBomFilter = "yellow";
            btn.innerHTML = '<figure class="circle_y" style="margin:0;width:18px;height:18px;display:block;"></figure>' +
                            '<div class="tooltip"><div class="tooltip-content">' +
                            '<div class="control-label"><div><figure class="circle_green"></figure><span>已完工</span></div></div>' +
                            '<div class="control-label"><div><figure class="circle_y"></figure><span>加工中</span></div></div>' +
                            '<div class="control-label"><div><figure class="circle_red"></figure><span>NG數已達加工數1/3</span></div></div>' +
                            '</div></div>';
        } else if (currentBomFilter === "yellow") {
            currentBomFilter = "red";
            btn.innerHTML = '<figure class="circle_red" style="margin:0;width:18px;height:18px;display:block;"></figure>' +
                            '<div class="tooltip"><div class="tooltip-content">' +
                            '<div class="control-label"><div><figure class="circle_green"></figure><span>已完工</span></div></div>' +
                            '<div class="control-label"><div><figure class="circle_y"></figure><span>加工中</span></div></div>' +
                            '<div class="control-label"><div><figure class="circle_red"></figure><span>NG數已達加工數1/3</span></div></div>' +
                            '</div></div>';
        } else {  // 當 currentBomFilter 為 "red"
            currentBomFilter = "all";
            btn.innerHTML = '<span style="font-size:8px; display:inline-block;">All</span>' +
                            '<div class="tooltip"><div class="tooltip-content">' +
                            '<div class="control-label"><div><figure class="circle_green"></figure><span>已完工</span></div></div>' +
                            '<div class="control-label"><div><figure class="circle_y"></figure><span>加工中</span></div></div>' +
                            '<div class="control-label"><div><figure class="circle_red"></figure><span>NG數已達加工數1/3</span></div></div>' +
                            '</div></div>';
        }
        filterTable();
    }

    // ---------- 日期篩選操作 ----------
    function handleDateInput() {
        const dateInput = document.getElementById("date-filter");
        let dateVal = dateInput.value.trim();
        if (dateVal && dateVal.split("/").length === 2) {
            const year = new Date().getFullYear();
            dateVal = `${year}/${dateVal}`;
            dateInput.value = dateVal;
        }
        filterTable();
    }
    function toggleComparison() {
        const btn = document.getElementById("comparison");
        const current = btn.textContent.trim();
        if (current === "<") {
            btn.textContent = ">";
        } else if (current === ">") {
            btn.textContent = "=";
        } else {
            btn.textContent = "<";
        }
        filterTable();
    }

    // ---------- 發單篩選操作（只搜索發單） ----------
    function toggleOrderComparison() {
        const btn = document.getElementById("order-comparison");
        if (orderComparison === "<") {
            orderComparison = ">";
            btn.textContent = ">";
        } else if (orderComparison === ">") {
            orderComparison = "=";
            btn.textContent = "=";
        } else {
            orderComparison = "<";
            btn.textContent = "<";
        }
        filterTable();
    }
    
    // ---------- 製程 (pti) 搜索操作（新增） ----------
    // 當點選製程按鈕時，將對應的值存入全域變數 ptiSearch，並呼叫 filterTable()
    function filterByPTI(val) {
        ptiSearch = val;
        filterTable();
    }

    // ---------- 篩選表格主函數 ----------
    function filterTable() {
        const dateFilterVal = document.getElementById("date-filter").value.trim();
        const bomFilter = document.getElementById("bom-filter").value.toLowerCase().trim();
        const comparison = document.getElementById("comparison").textContent.trim();
        const customerFilter = document.getElementById("customer-filter").value;
        const vendorFilter = document.getElementById("vendor-filter").value;
        const orderFilter = document.getElementById("order-filter").value.toLowerCase().trim();

        let hasDateFilter = dateFilterVal !== "";
        let filterDate;
        if (hasDateFilter) {
            filterDate = new Date(dateFilterVal);
            if (isNaN(filterDate.getTime())) {
                hasDateFilter = false;
            } else {
                filterDate = normalizeDate(filterDate);
            }
        }

        const table = document.getElementById("table-DOWN");
        const rows = table.getElementsByTagName("tr");

        // 假設第一行為標題，資料從索引1開始
        for (let i = 1; i < rows.length; i++) {
            const cells = rows[i].getElementsByTagName("td");
            let show = true;

            // --- 日期篩選 ---
            const rowDateText = cells[0] ? (cells[0].textContent || cells[0].innerText).trim() : "";
            const rowDate = convertDateFormat(rowDateText);
            const normRowDate = normalizeDate(rowDate);
            if (hasDateFilter) {
                const normTime = normRowDate.getTime();
                const filterTime = filterDate.getTime();
                if (comparison === ">") {
                    if (normTime < filterTime) show = false;
                } else if (comparison === "<") {
                    if (normTime > filterTime) show = false;
                } else {
                    if (normTime !== filterTime) show = false;
                }
            }

            // --- BOM 搜尋 ---
            const rowBOM = cells[1] ? (cells[1].textContent || cells[1].innerText).toLowerCase() : "";
            const rowPartNo = cells[2] ? (cells[2].textContent || cells[2].innerText).toLowerCase() : "";
            if (bomFilter) {
                if (rowBOM.indexOf(bomFilter) === -1 && rowPartNo.indexOf(bomFilter) === -1) {
                    show = false;
                }
            }

            // --- 客戶篩選 ---
            const rowCustomer = cells[3] ? (cells[3].textContent || cells[3].innerText).trim() : "";
            if (customerFilter && rowCustomer.indexOf(customerFilter) === -1) {
                show = false;
            }

            // --- 廠商篩選 ---
            let rowVendor = cells[5] ? (cells[5].textContent || cells[5].innerText).trim() : "";
            if (rowVendor.includes("回")) {
                rowVendor = rowVendor.split("回")[0];
            }
            if (vendorFilter && rowVendor.indexOf(vendorFilter) === -1) {
                show = false;
            }

            // --- 搜索【發單】（只針對發單數，取自第7欄） ---
            const rowOrder = cells[6] ? (cells[6].textContent || cells[6].innerText).trim() : "";
            if (orderFilter) {
                let operator, filterVal;
                if (orderFilter[0] === ">" || orderFilter[0] === "<" || orderFilter[0] === "=") {
                    operator = orderFilter[0];
                    filterVal = orderFilter.slice(1).trim();
                } else {
                    // 修改：若未指定操作符，預設使用 "="
                    operator = "=";
                    filterVal = orderFilter;
                }
                const numCell = parseFloat(rowOrder);
                const numFilter = parseFloat(filterVal);
                if (!isNaN(numFilter)) {
                    if (operator === ">") {
                        if (!(numCell > numFilter)) show = false;
                    } else if (operator === "<") {
                        if (!(numCell < numFilter)) show = false;
                    } else if (operator === "=") {
                        if (!(numCell === numFilter)) show = false;
                    }
                } else {
                    if (rowOrder.indexOf(filterVal) === -1) show = false;
                }
            }
            
            // --- 搜索【pti】（新增：若 ptiSearch 不為空，則僅顯示 pti 欄位值與之相符的行） ---
            if (ptiSearch) {
                // 假設 pti 欄位位於第9欄（索引8），請確認你的表格欄位順序正確
                const rowPTI = cells[8] ? (cells[8].textContent || cells[8].innerText).trim() : "";
                if (rowPTI !== ptiSearch) {
                    show = false;
                }
            }

            rows[i].style.display = show ? "" : "none";
        }
        updateDropdowns();
    }

    function updateDropdowns(){
        const table = document.getElementById("table-DOWN");
        const rows = table.getElementsByTagName("tr");
        const customerSet = new Set();
        const vendorSet = new Set();
        for (let i = 1; i < rows.length; i++) {
            if (rows[i].style.display === "none") continue;
            const cells = rows[i].getElementsByTagName("td");
            if (cells[3]) {
                const cust = (cells[3].textContent || cells[3].innerText).trim();
                if (cust) customerSet.add(cust);
            }
            if (cells[5]) {
                let vend = (cells[5].textContent || cells[5].innerText).trim();
                if (vend) {
                    if (vend.includes("回")) {
                        vend = vend.split("回")[0];
                    }
                    vendorSet.add(vend);
                }
            }
        }
        const customerDatalist = document.getElementById("customerList");
        customerDatalist.innerHTML = "";
        Array.from(customerSet).sort().forEach(cust => {
            const opt = document.createElement("option");
            opt.value = cust;
            customerDatalist.appendChild(opt);
        });
        const vendorDatalist = document.getElementById("vendorList");
        vendorDatalist.innerHTML = "";
        Array.from(vendorSet).sort().forEach(vend => {
            const opt = document.createElement("option");
            opt.value = vend;
            vendorDatalist.appendChild(opt);
        });
    }

    document.addEventListener("DOMContentLoaded", function(){
        document.getElementById("date-filter").addEventListener("keydown", function(e){
            if (e.key === "Enter") {
                handleDateInput();
            }
        });
        filterTable();
    });
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
                    <div class="title_left">
                        <h4>
                            <?php
                            if(!empty($_GET['message'])) {
                                if($_GET['message']=="success") {
                                    echo "<div class=\"alert alert-success fade in alert-dismissable\">
                                    <a href=\"#\" class=\"close\" data-dismiss=\"alert\" aria-label=\"close\" title=\"close\">×</a>
                                    更新成功
                                    </div>";
                                } else if($_GET['message']!="success"){
                                    $var=$_GET['message'];
                                    echo "<div class=\"alert alert-danger fade in alert-dismissable\">
                                    <a href=\"#\" class=\"close\" data-dismiss=\"alert\" aria-label=\"close\" title=\"close\">×</a>
                                    $var
                                    </div>";
                                }
                            }
                            ?>
                        </h4>
                        <!-- <h3>Event <small>Live</small></h3> -->
                    </div>
                    <div class="clearfix"></div>
                    
                    <div class="row">
                        <div class="col-md-12 col-sm-12 col-xs-12">
                            <div class="x_panel">
                                <?php
                                if(!empty($BOM)){?>

                                    <div class="x_title">
                                        <h2><?= $BOM ?> 報工明細</h2>
                                        <div class="clearfix"></div>
                                    </div>
                                    <div class="x_content">
                                        <form action="" class="form-label-left" novalidate>
                                                <div class="col-md-12 col-sm-12 col-xs-12">
                                                    <p>料號：<?= $d_id ?></p>
                                                </div>
                                                <div class="col-md-12 col-sm-12 col-xs-12">
                                                    <p>客戶：<?= $Client_Name?></p>
                                                </div>
                                                <div class="col-md-12 col-sm-12 col-xs-12">
                                                    <p>製程：<?=$ProcessNo?> <?=$pn?>&ensp;&ensp;<?= $MakerId ?></p>
                                                </div>
                                                <div class="col-md-12 col-sm-12 col-xs-12">
                                                    <?php
                                                        $sum_ok_sqty = 0; // 定義一個變數來儲存加總值

                                                        // 遍歷 $PmOreadyReply_list 陣列，計算 ok_sqty 的加總值
                                                        foreach ($PmOreadyReply_list as $PmOreadyReply) {
                                                            $sum_oready_sqty += $PmOreadyReply['oready_sqty'];
                                                            $sum_ok_sqty += $PmOreadyReply['ok_sqty'];
                                                            $sum_ng_sqty += $PmOreadyReply['ng_sqty_total'];
                                                        }
                                                    ?>
                                                    <p>加工 / 發單：<?= $sum_oready_sqty ?> / <?= $sqty?></p>
                                                    <p>良品 / NG：<?= $sum_ok_sqty ?> / 
                                                        <?php if ($sum_ng_sqty != 0): ?>
                                                            <span style="color: red;"><?= $sum_ng_sqty ?></span>
                                                        <?php else: ?>
                                                            <?= $sum_ng_sqty ?>
                                                        <?php endif; ?>
                                                </div>
                                            </div>
                                        </form>
                                        <p class="text-muted font-13 m-b-30">
                                        </p>
                                            
                                            <!-- 已報工紀錄 -->
                                            <table class="table table-striped ">
                                                <thead>
                                                    <tr>
                                                        <th>報工日</th>
                                                        <th>加工數</th>
                                                        <th>良品數</th>
                                                        <th>NG數</th>
                                                        <th>人員</th>
                                                        <th>備註</th>
                                                        <!-- 以下齒研加工才出現 -->
                                                        <?php if($ProcessNo==12){ ?>
                                                            <th>其他資訊</th> 
                                                        <?php } ?>
                                                        <!-- 齒研加工才出現-end -->
                                                    </tr>
                                                    </thead>
                                                    <tbody>
                                                    <!-- 以下齒研加工才出現 -->
                                                    <?php
                                                    if($ProcessNo==12){
                                                        foreach ($PmOreadyReply_list as $PmOreadyReply_list){
                                                    ?>
                                                    <tr>
                                                        <td>
                                                            <!-- 以下按鈕無設定功能，生管應無修改權限 -->
                                                            <!-- <a href="../../src/store/_showReply.php?ri=<?= $PmOreadyReply_list['reply_id'] ?>&bi=<?= $PmOreadyReply_list['BOM']?>&pna=<?= $PmOreadyReply_list['ProcessName'] ?>&pn=<?= $PmOreadyReply_list['ProcessNo'] ?>&mi=<?= $PmOreadyReply_list['MakerId'] ?>&s=<?= $sqty ?>&C=<?= $Client_Name ?>"> 
                                                                <input type="button" name="reply_other_update" class="btn btn-warning btn-xs update" value="更新"></a>&ensp;
                                                            </a> -->
                                                            <?= $PmOreadyReply_list['Created_date'] ?></td>
                                                        <td> <?= $PmOreadyReply_list['oready_sqty'] ?></td>
                                                        <td> <?= $PmOreadyReply_list['ok_sqty'] ?></td>
                                                        <td>
                                                            <?php if ($PmOreadyReply_list['ng_sqty_total'] != 0): ?>
                                                                <span style="color: red;"><?= $PmOreadyReply_list['ng_sqty_total'] ?></span>
                                                            <?php else: ?>
                                                                 <?= $PmOreadyReply_list['ng_sqty_total'] ?>
                                                            <?php endif; ?></td>
                                                        <td> <?= $PmOreadyReply_list['user_cname'] ?></td>
                                                        <td> <?= $PmOreadyReply_list['ps'] ?></td>
                                                        <th> <?php if($PmOreadyReply_list['m']!=null){ ?>
                                                                <!-- 模數 <?= $PmOreadyReply_list['m'] ?> T<?= $PmOreadyReply_list['t'] ?> W<?= $PmOreadyReply_list['width'] ?>  -->
                                                                <?php if($PmOreadyReply_list['machine']!=null){ ?>
                                                                    <?= $PmOreadyReply_list['machine'] ?>
                                                                <? } ?><BR>
                                                                校機 <?= $PmOreadyReply_list['mc_time'] ?> / <?= $PmOreadyReply_list['mc_user'] ?><BR>
                                                                <?php if($PmOreadyReply_list['change_tool_time']!=null){ ?>
                                                                換刀 <?= $PmOreadyReply_list['change_tool_time'] ?>
                                                                <? } ?>
                                                        <? } ?>
                                                        </th>
                                                    </tr>
                                                    <!-- 齒研加工才出現-end -->
                                                    <?php }} else {
                                                        foreach ($PmOreadyReply_list as $PmOreadyReply_list){
                                                    ?>
                                                    <tr>
                                                        <td>
                                                            <!-- 以下兩個按鈕無設定功能，生管應無修改權限 -->
                                                            <!-- <a href="../../src/store/_showReply_ ForPm_BaseOfTime.php?ri=<?= $PmOreadyReply_list['reply_id'] ?>&bi=<?= $PmOreadyReply_list['BOM']?>&pna=<?= $PmOreadyReply_list['ProcessName'] ?>&pn=<?= $PmOreadyReply_list['ProcessNo'] ?>&mi=<?= $PmOreadyReply_list['MakerId'] ?>&s=<?= $sqty ?>&C=<?= $Client_Name ?>"> 
                                                                <input type="button" name="reply_other_update" class="btn btn-warning btn-xs update" value="更新"></a>&ensp;
                                                            </a>
                                                            <a href="../../src/store/_deleteReply_FromPM.php?ri=<?= $reply_id ?>&bi=<?= $bom_ing_id ?>&pna=<?= $ProcessName ?>&pn=<?= $ProcessNo ?>&mi=<?= $MakerId ?>&s=<?= $sqty ?>&C=<?= $Client_Name ?>"> 
                                                                <input type="button" name="reply_other_del" class="btn btn-danger btn-xs" value="刪除"></a>&ensp;
                                                            </a> -->
                                                            <?= $PmOreadyReply_list['Created_date'] ?></td>
                                                        <td> <?= $PmOreadyReply_list['oready_sqty'] ?></td>
                                                        <td> <?= $PmOreadyReply_list['ok_sqty'] ?></td>
                                                        <td> <?= $PmOreadyReply_list['ng_sqty_total'] ?></td>
                                                        <td> <?= $PmOreadyReply_list['user_cname'] ?></td>
                                                        <td> <?= $PmOreadyReply_list['ps'] ?></td>
                                                    </tr>
                                                    <?php }} ?>
                                                </tbody>
                                            </table>
                                    </div>
                                <?php } ?>
                                <?php
                                if(isset($_888)){?>

                                    <div class="x_title">
                                        <h2>已報工未移轉 <small>直接新增或點選[更新]後送出修改</small></h2>
                                        <div class="clearfix"></div>
                                    </div>
                                    
                                    <div class="x_content">
                                        <p class="text-muted font-13 m-b-30">
                                        </p>
                                        <form action="../../src/store/_NewReply.php?BOM=<?= $bom_ing_id ?>" method="POST" action="" class="form-horizontal form-label-left" novalidate>
                                            <!-- <input id="reply_id" name="reply_id" value="<?= $reply_id ?>" type="hidden"> -->
                                            <div class="item form-group">
                                                <label class="control-label col-md-3 col-sm-3 col-xs-12" for="BOM"> BOM <span class="required">*</span>
                                                </label>
                                                <div class="col-md-6 col-sm-6 col-xs-12">
                                                    <input id="BOM" class="BOM" value="<?= $BOM ?>" data-validate-length-range="2" name="BOM"
                                                    data-validate-words="1" required="required" type="text">
                                                </div>
                                            </div>
                                            <div class="item form-group">
                                                <label class="control-label col-md-3 col-sm-3 col-xs-12" for="d_id"> 產編 <span class="required">*</span>
                                                </label>
                                                <div class="col-md-6 col-sm-6 col-xs-12">
                                                <input id="d_id" class="d_id" value="<?= $d_id ?>" name="d_id"
                                                data-validate-length-range="2" data-validate-words="1" required="required" type="text">
                                                </div>
                                            </div>
                                            <div class="item form-group">
                                                <label class="control-label col-md-3 col-sm-3 col-xs-12" for="ProcessNo"> 製程 <span class="required">*</span>
                                                </label>
                                                <div class="col-md-6 col-sm-6 col-xs-12">
                                                    <input id="ProcessNo" name="ProcessNo" size="5" value="<?= $ProcessNo ?>"  name="ProcessNo"
                                                    data-validate-length-range="2" data-validate-words="1" required="required" type="text">
                                                </div>
                                            </div>
                                            <div class="item form-group">
                                                <label class="control-label col-md-3 col-sm-3 col-xs-12" for="sqty"> 發單數 <span class="required">*</span>
                                                </label>
                                                <div class="col-md-6 col-sm-6 col-xs-12">
                                                    <input id="sqty" class="sqty" value="<?= $sqty ?>" required name="sqty" 
                                                    required="required" type="text" size="5">
                                                </div>
                                            </div>
                                            <div class="item form-group">
                                                <label class="control-label col-md-3 col-sm-3 col-xs-12" for="oready_sqty"> 本次加工數 <span class="required">*</span>
                                                </label>
                                                <div class="col-md-6 col-sm-6 col-xs-12">
                                                    <input id="oready_sqty" class="oready_sqty" value="<?= $oready_sqty ?>" required name="oready_sqty" 
                                                    required="required" type="text" size="5"><small>(空白=發單總數)</small>
                                                </div>
                                            </div>
                                            <div class="item form-group">
                                                <label class="control-label col-md-3 col-sm-3 col-xs-12" for="Client_Name"> 客戶 <span class="required">*</span>
                                                </label>
                                                <div class="col-md-6 col-sm-6 col-xs-12">
                                                    <input id="Client_Name" class="Client_Name" value="<?= $Client_Name ?>" name="Client_Name"
                                                    data-validate-length-range="2" data-validate-words="1" required="required" type="text">
                                                </div>
                                            </div>
                                    
                                            <div class="item form-group">
                                                <label class="control-label col-md-3 col-sm-3 col-xs-12" for="NG"> NG數(1) <span class="required">*</span>
                                                </label>
                                                <div class="col-md-6 col-sm-6 col-xs-12">
                                                    <input id="NG" class="NG" value="<?= $NG ?>" size="5" data-validate-length-range="2" name="NG"
                                                    data-validate-words="1" required="required" type="text">
                                                    原因
                                                    <select name="NG_id" id="NG_id">
                                                        <option value="0">請選擇</option>
                                                        <?php foreach($ng_txt_list as $ng_txt_list){ ?>
                                                            <option value="<?= $ng_txt_list['ng_id']?>"><?= $ng_txt_list['ng_txt'] ?></option>
                                                        <?php } ?>
                                                    </select>
                                                </div>
                                            </div>

                                            <div class="item form-group">
                                                <label class="control-label col-md-3 col-sm-3 col-xs-12" for="NG2"> NG數(2) <span class="required">*</span>
                                                </label>
                                                <div class="col-md-6 col-sm-6 col-xs-12">
                                                    <input id="NG2" class="NG2" value="<?= $NG2 ?>" size="5" name="NG2"
                                                    data-validate-length-range="2" data-validate-words="1" required="required" type="text">
                                                    原因
                                                    <select name="NG_id2" id="NG_id2">
                                                        <option value="0">請選擇</option>
                                                        <?php foreach($ng_txt_list2 as $ng_txt_list2){ ?>
                                                            <option value="<?= $ng_txt_list2['ng_id']?>"><?= $ng_txt_list2['ng_txt'] ?></option>
                                                        <?php } ?>
                                                    </select>
                                                </div>
                                            </div>

                                            <div class="item form-group">
                                                <label class="control-label col-md-3 col-sm-3 col-xs-12" for="NG3"> NG數(3) <span class="required">*</span>
                                                </label>
                                                <div class="col-md-6 col-sm-6 col-xs-12">
                                                    <input id="NG3" class="NG3" value="<?= $NG ?>" size="5" name="NG3"
                                                    data-validate-length-range="2" data-validate-words="1" required="required" type="text">
                                                    原因
                                                    <select name="NG_id3" id="NG_id3">
                                                        <option value="0">請選擇</option>
                                                        <?php foreach($ng_txt_list3 as $ng_txt_list3){ ?>
                                                            <option value="<?= $ng_txt_list3['ng_id']?>"><?= $ng_txt_list3['ng_txt'] ?></option>
                                                        <?php } ?>
                                                    </select>
                                                </div>
                                            </div>

                                            <div class="item form-group">
                                                <label class="control-label col-md-3 col-sm-3 col-xs-12" for="id"> 報工人員 <span class="required">*</span>
                                                </label>
                                                <div class="col-md-6 col-sm-6 col-xs-12">
                                                    <input readonly id="id" class="id" value="<?= $id ?>" size="5"
                                                    data-validate-length-range="2" data-validate-words="1" name="id" required="required" type="text">
                                                </div>
                                            </div>

                                            <div class="ln_solid"></div>
                                            <div class="form-group">
                                                <div class="col-md-6 col-md-offset-3">
                                                    <button name="resetpSetting" type="submit" class="btn btn-primary">取消</button>
                                                    <button id="send" name="reply_UpDate" type="submit" class="btn btn-success">送出</button>
                                                </div>
                                            </div>
                                        </form>
                                    </div>
                                <?php } ?>
                            </div>
                        </div>
                    </div>
                </div>


                <!-- 料號總覽 -->
                <form method="POST" action="">
                    <div class="row">
                        <div class="col-md-12 col-sm-12 col-xs-12">
                            <div class="x_panel">
                                <div class="x_title">
                                    <h2>BOM 總表 
                                    <div class="title">
                                        <a><input type="button" class="btn btn-xs btn-primary" value="車床" onclick="filterByPTI('1')"></a>
                                        <a><input type="button" class="btn btn-xs btn-primary" value="銑床" onclick="filterByPTI('2')"></a>
                                        <a><input type="button" class="btn btn-xs btn-primary" value="滾齒" onclick="filterByPTI('4')"></a>
                                        <a><input type="button" class="btn btn-xs btn-primary" value="平研" onclick="filterByPTI('33')"></a>
                                        <a><input type="button" class="btn btn-xs btn-primary" value="外研" onclick="filterByPTI('11')"></a>
                                        <a><input type="button" class="btn btn-xs btn-primary" value="齒研" onclick="filterByPTI('12')"></a>
                                        <a><input type="button" class="btn btn-xs btn-primary" value="其他製程" onclick="filterByPTI('189')"></a>
                                        <a><input type="button" class="btn btn-xs btn-primary" value="雷刻與包裝" onclick="filterByPTI('16')"></a>

                                        <a href="../../views/pm/OreadyReply_ForPm_BaseOfTime.php?id=<?= $_GET['id']?>"><input type="button" class="btn btn-xs btn-warning" value="取消(顯示全部)"></a>
                                    </div> 
                                    </h2>
                                    <ul class="nav navbar-right panel_toolbox">
                                        <li><a class="collapse-link"><i class="fa fa-chevron-up"></i></a>
                                        </li>
                                        <li><a class="close-link"><i class="fa fa-close"></i></a>
                                        </li>
                                    </ul>
                                    
                                <div class="clearfix"></div>
                                    <div class="all-filters">
                                        <button type="button" id="comparison" onclick="toggleComparison()">&lt;</button>
                                        <input type="text" id="date-filter" onblur="handleDateInput()" placeholder="篩選 報工日" />
                                        
                                        <button type="button" id="bomColorFilter" onclick="toggleBomColorFilter()">
                                            <!-- 這裡根據狀態來顯示相應內容，初始狀態為 All -->
                                            <span id="bomColorContent" style="font-size:8px; display:inline-block;">All</span>
                                            <div class="tooltip">
                                                <div class="tooltip-content">
                                                    <div class="control-label">
                                                      <div>
                                                        <figure class="circle_green"></figure>
                                                        <span>已完工</span>
                                                      </div>
                                                    </div>
                                                    <div class="control-label">
                                                      <div>
                                                        <figure class="circle_y"></figure>
                                                        <span>加工中</span>
                                                      </div>
                                                    </div>
                                                    <div class="control-label">
                                                      <div>
                                                        <figure class="circle_red"></figure>
                                                        <span>NG數已達加工數1/3</span>
                                                      </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </button>


                                        <input type="text" id="bom-filter" onkeyup="filterTable()" placeholder="搜索 BOM / 料號" />
                                        <!-- 客戶與廠商使用 datalist -->
                                        <input type="text" id="customer-filter" list="customerList" oninput="filterTable()" placeholder="全部客戶" />
                                        <datalist id="customerList"></datalist>
                                        <input type="text" id="vendor-filter" list="vendorList" oninput="filterTable()" placeholder="全部廠商" />
                                        <datalist id="vendorList"></datalist>
                                        <input type="text" id="order-filter" onkeyup="filterTable()" placeholder="搜索 發單" />
                                    </div>
                                </div>

                                <!-- 呈現料號資料   -->
                                <!-- <table id="datatable-buttons" class="table table-striped table-bordered"> -->
                                <div class="table-wrapper">          
                                
                                    <table id="table-DOWN" class="table table-striped" border="1" cellspacing="0" cellpadding="5">
                                        <thead>
                                            <tr>
                                                <th>報工日</th>
                                                <th>BOM</th>
                                                <th>料號</th>
                                                <th>客戶</th>
                                                <th>製程</th>
                                                <th>廠商</th>
                                                <th>發單數</th>
                                                <th>已加工 / NG</th>
                                                <th hidden>pti</th>
                                            </tr>
                                            </thead>
                                            <tbody>
                                            <?php
                                            foreach ($OreadyReply_list as $OreadyReply_list){
                                            ?>
                                            <tr>
                                                <td name="Created_At_s">
                                                    <a href="../../src/store/_pmOreadyReply_list_bt.php?c_pti=<?= $choose_pti ?>&c=<?= $OreadyReply_list['Client_Name'] ?>&or_id=<?= $OreadyReply_list['OreadyReply_id'] ?>&b=<?= $OreadyReply_list['bom'] ?>&d=<?= $OreadyReply_list['d_id'] ?>&pn=<?= $OreadyReply_list['process_no'] ?>&mi=<?= $OreadyReply_list['maker_id'] ?>&s=<?= $OreadyReply_list['sqty'] ?>">
                                                        <input type="button" name="oreadyReply_list" class="btn btn-warning btn-xs btn-xss update" value="明細"></a>
                                                    <a href="../../src/store/_pmGotoNext_bt.php?c_pti=<?= $choose_pti ?>&c=<?= $OreadyReply_list['Client_Name'] ?>&or_id=<?= $OreadyReply_list['OreadyReply_id'] ?>&b=<?= $OreadyReply_list['bom'] ?>&d=<?= $OreadyReply_list['d_id'] ?>&pn=<?= $OreadyReply_list['process_no'] ?>&mi=<?= $OreadyReply_list['maker_id'] ?>&s=<?= $OreadyReply_list['sqty'] ?>">
                                                        <input type="button" name="gotoNext" class="btn btn-success btn-xs btn-xss update" value="已移轉"></a>
                                                    <?= $OreadyReply_list['Created_At_s'] ?>
                                                </td>
                                                <td name="BOM">
                                                    <?php if($OreadyReply_list['oready_sqty_total']+$OreadyReply_list['ng_sqty_total']>=$OreadyReply_list['sqty']) { ?>
                                                    <label class="control-label col-md-3 col-sm-3 col-xs-12">
                                                        <div>
                                                            <figure class="circle_green"></figure>
                                                            <?= $OreadyReply_list['bom'] ?>
                                                        </div>
                                                    </label>
                                                    <?php } elseif($OreadyReply_list['ng_sqty_total']>=($OreadyReply_list['oready_sqty_total']*0.3)) { ?>
                                                    <label class="control-label col-md-3 col-sm-3 col-xs-12">
                                                        <div>
                                                            <figure class="circle_red"></figure>
                                                            <?= $OreadyReply_list['bom'] ?>
                                                        </div>
                                                    </label>
                                                    <?php } else { ?>
                                                    <label class="control-label col-md-3 col-sm-3 col-xs-12">
                                                        <div>
                                                            <figure class="circle_y"></figure>
                                                            <?= $OreadyReply_list['bom'] ?>
                                                        </div>
                                                    </label>
                                                    <?php } ?>
                                                </td>
                                                <td name="d_id"> <?= $OreadyReply_list['d_id'] ?></td>
                                                <td name="Client_Name"> <?= $OreadyReply_list['Client_Name'] ?></td>
                                                <td name="ProcessName"> <?= $OreadyReply_list['process_no'] ?> <?= $OreadyReply_list['ProcessName'] ?></td>
                                                <td name="MakerId"> <?= $OreadyReply_list['maker_id'] ?></td>
                                                <td name="sqty"> <?= $OreadyReply_list['sqty'] ?></td>
                                                <td name="ok_sqty"> <?= $OreadyReply_list['oready_sqty_total'] ?> / 
                                                    <?= $OreadyReply_list['ng_sqty_total'] ?>
                                                </td> <!-- This column is already "已加工 / NG" -->
                                                <td name="pti" hidden> <?= $OreadyReply_list['pti'] ?></td>
                                            </tr>
                                            <?php
                                                };
                                            ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        
<!-- 線圖 -->


<script src="../../code/highcharts.js"></script>
            <script src="../../code/modules/exporting.js"></script>
            <script src="../../code/modules/export-data.js"></script>
            <script src="../../code/modules/accessibility.js"></script>
            <!-- /page content -->
        <!-- footer content include -->
        <?php include '../partPage/footer.html' ?>
        <!-- /footer content include -->
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
</body>
</html>
