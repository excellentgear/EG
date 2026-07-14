<?php
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/Sales/NewOrder_List.php?in=999";
    header("Location:../../index.php");
    exit;
}

include '../../src/common/DBConnection.php';
include '../../src/store/_setting.php';
include '../../src/common/_config.php';

@$userName = $_SESSION['user_cname'];
@$id       = $_SESSION['id'];

$conn = new DBConnection();

// 取得指派設計的選項
@$ate_list = $conn->getAll("SELECT `user_cname`,`user_uname`,`id` FROM `user` WHERE `user_status`=63");

// 取得現有的訂單資料（依排序條件）
@$order_list = $conn->getAll("SELECT 
            order_list.*,
            CONCAT(DATE_FORMAT(order_list.Order_date, '%y'), 'y/', DATE_FORMAT(order_list.Order_date, '%c/%e')) AS Order_date,
            CONCAT(DATE_FORMAT(order_list.Delivery_date, '%y'), 'y/', DATE_FORMAT(order_list.Delivery_date, '%c/%e')) AS Delivery_date_T,
            DATE_FORMAT(order_list.ateGet, '%c/%e') AS ateGet,
            DATE_FORMAT(order_list.pmGet, '%c/%e') AS pmGet,
            user.user_cname,Open_Qty
        FROM order_list
        LEFT JOIN user ON user.id = order_list.ate
        WHERE Order_status is null 
        ORDER BY order_list.Order_date DESC, order_list.Client_name ASC;
        ");

        // 增加檢查邏輯
        if (empty($order_list)) {
            error_log("No data found in order_list table.");
        } else {
            foreach ($order_list as $order) {
                if (empty($order['Order_id'])) {
                    error_log("Order ID is missing for order: " . print_r($order, true));
                }
            }
        }

// 將 SESSION 中的資料取出用於表單預設顯示；若未設置則為空
@$OrderNo           = isset($_SESSION['OrderNo'])            ? $_SESSION['OrderNo']           : "";
@$orderindate       = isset($_SESSION['orderindate'])        ? $_SESSION['orderindate']       : "";
@$orderDdate        = isset($_SESSION['orderDdate'])         ? $_SESSION['orderDdate']        : "";
@$Client_Name       = isset($_SESSION['Client_Name'])        ? $_SESSION['Client_Name']       : "";
@$Client_OrderNo    = isset($_SESSION['Client_OrderNo'])     ? $_SESSION['Client_OrderNo']    : "";
@$d_id              = isset($_SESSION['d_id'])               ? $_SESSION['d_id']              : "";
@$Process           = isset($_SESSION['Process'])            ? $_SESSION['Process']           : "";
@$Qty               = isset($_SESSION['Qty'])                ? $_SESSION['Qty']               : "";
@$datepicker_ate    = isset($_SESSION['datepicker_ate'])     ? $_SESSION['datepicker_ate']    : "";
@$drop_zone         = isset($_SESSION['drop_zone'])          ? $_SESSION['drop_zone']         : "";
@$Containers        = isset($_SESSION['Containers'])         ? $_SESSION['Containers']        : "";
@$sample            = isset($_SESSION['sample'])             ? $_SESSION['sample']            : "";
@$jig               = isset($_SESSION['jig'])                ? $_SESSION['jig']               : "";
@$Order_ps          = isset($_SESSION['Order_ps'])           ? $_SESSION['Order_ps']          : "";
@$ateNote           = isset($_SESSION['ateNote'])            ? $_SESSION['ateNote']           : "";
@$ate= isset($_SESSION['ate']) ? $_SESSION['ate']: "";
@$Order_id          = isset($_SESSION['Order_id'])           ? $_SESSION['Order_id']          : "";

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

    <title>訂單追蹤</title>

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

    <!-- 月曆相關 -->
    <!-- <link href="../../resource/css/pages.css" rel="stylesheet"> -->

    <link rel="stylesheet" href="http://code.jquery.com/ui/1.11.4/themes/smoothness/jquery-ui.css">
</head>
<style>
    /* 為 .item.form-group 設置一致的間距 */
.item.form-group {
    display: flex; /* 保持彈性佈局 */
    align-items: center; /* 垂直置中 */
    justify-content: flex-start; /* 水平靠左 */
    margin-bottom: 5px; /* 確保每個條目有一致的底部間距 */
    gap: 10px; /* 控制 label 和 input 之間的固定距離 */
    flex-wrap: nowrap; /* 確保元素不會因換行而亂跳 */
}

/* 控制 label 寬度，統一對齊 */
.item.form-group label {
    min-width: 60px; /* 固定寬度，避免長短不一導致未對齊 */
    text-align: left; /* 保持文字靠左 */
    margin: 0; /* 移除多餘的 margin */
}

/* 確保 input 保持一致的樣式 */
.item.form-group input {
    flex: 1; /* 彈性寬度，讓輸入框可填滿可用空間 */
    max-width: 250px; /* 設置最大寬度，避免過大 */
    padding: 5px;
    font-size: 14px;
    border: 1px solid #ccc;
    border-radius: 4px;
    text-align: left; /* 確保文字靠左對齊 */
    box-sizing: border-box; /* 確保 padding 不會影響寬度 */
}

/* 確保 select 保持一致的樣式 */
.item.form-group select {
    flex: 1; /* 彈性寬度，讓輸入框可填滿可用空間 */
    max-width: 250px; /* 設置最大寬度，避免過大 */
    padding: 5px;
    font-size: 14px;
    border: 1px solid #ccc;
    border-radius: 4px;
    text-align: left; /* 確保文字靠左對齊 */
    box-sizing: border-box; /* 確保 padding 不會影響寬度 */
}

.all-filters > .all-type {
    display: flex; /* 使用 Flexbox 讓內部項目水平排列 */
    flex-wrap: wrap; /* 支援換行，當空間不足時自動換行 */
    gap: 5px; /* 控制每個框之間的間距 */
    justify-content: space-between; /* 均勻分布框線 */
}

.all-filters > .all-filters {
    gap: 5px; /* 控制每個框之間的間距 */
}

/* 內層框線 */
.all-type > .all-filters {
    border: 1.5px solid #ccc;
    border-radius: 8px;
    padding: 10px;
    margin-bottom: 10px;
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
    display: flex;
    flex-direction: column; /* 行內垂直排列，分為不同行 */
    gap: 5px;
}

/* 保持 .all-filters 並排 */
.all-type {
    display: flex; /* 使用 Flexbox 讓內部項目水平排列 */
    flex-wrap: wrap; /* 允許換行 */
    gap: 5px; /* 設置間距 */
}

/* 其他內層框線內容不受影響 */
.all-type > .all-filters {
    flex: 1 1 calc(30% - 10px); /* 保持並排三等分的樣式 */
    box-sizing: border-box; /* 確保內外邊距穩定 */
}

#Order_ps {
        width: 100%;
        height: 100%;
        max-width: 500px; /* 設置適當的寬度 */
        max-height: 100px;
        height: 100px; /* 高度可根據需求調整 */
        padding: 10px;
        font-size: 14px;
        border: 1px solid #ccc;
        border-radius: 4px;
        box-sizing: border-box;
        resize: vertical; /* 允許垂直調整高度 */
    }


/* RWD 響應式設計：窄螢幕時改為垂直排列 */
@media screen and (max-width: 768px) {
    .all-type {
        display: flex;
        flex-direction: column; /* 在窄螢幕時改為垂直排列整體 */
        gap: 5px;
    }

    /* 第一和第三 .all-filters 並排 */
    .all-type > .all-filters:nth-child(1),
    .all-type > .all-filters:nth-child(3) {
        display: flex;
        flex: 1 1 calc(50% - 10px); /* 各自占一半寬度，並保持間距 */
        box-sizing: border-box;
    }

    /* 第二個 .all-filters 獨占一行 */
    .all-type > .all-filters:nth-child(2) {
        display: flex;
        flex: 1 1 100%; /* 獨占整行 */
        box-sizing: border-box;
    }

    #Order_ps {
        width: 100%;
        height: 100%;
        max-width: 300px; /* 設置適當的寬度 */
        max-height: 100px;
        height: 100px; /* 高度可根據需求調整 */
        padding: 10px;
        font-size: 14px;
        border: 1px solid #ccc;
        border-radius: 4px;
        box-sizing: border-box;
        resize: vertical; /* 允許垂直調整高度 */
    }
}


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
    .control-label-2 {
        margin: 0; /* 移除 margin */
    }
    .control-label-2 div {
        display: inline-flex; /* 使 div 元素與文字排列 */
        align-items: center; /* 垂直居中 */
    }
    .control-label-2 div figure {
        margin-right: 8px; /* 設定與文本間的距離 */
    }
    
    /* 表格樣式修改 */
    .table-wrapper {
        overflow-x: auto; /* 保留水平捲動 */
        overflow-y: hidden !important; /* 完全隱藏垂直捲動 */
        max-height: none !important; /* 移除高度限制 */
    }

    /* 固定表格標題，即使在水平捲動時 */
    #table-DOWN thead th {
        position: sticky;
        top: 0;
        z-index: 10;
        background-color: #ffffff;
        box-shadow: 0 1px 2px rgba(0,0,0,0.1);
    }

    /* 回頂端按鈕樣式 */
    #backToTop {
        display: none;
        position: fixed;
        bottom: 20px;
        right: 20px;
        z-index: 1000;
        width: 40px;
        height: 40px;
        background-color: #337ab7;
        color: white;
        border: none;
        border-radius: 50%;
        font-size: 20px;
        cursor: pointer;
        box-shadow: 0 2px 5px rgba(0,0,0,0.3);
        transition: background-color 0.3s;
    }

    #backToTop:hover {
        background-color: #23527c;
    }

    /* 徹底修正偶數行背景色 */
    .table-striped > tbody > tr:nth-of-type(even) {
        background-color: #ffffff !important; /* 確保純白色 */
    }

    .table-striped > tbody > tr:nth-of-type(odd) {
        background-color: #f3f3f3 !important; /* 確保奇數行顏色 */
    }

    /* 確保樣式優先級高於Bootstrap */
    #table-DOWN.table-striped > tbody > tr:nth-of-type(even) {
        background-color: #ffffff !important;
    }

    #table-DOWN.table-striped > tbody > tr:nth-of-type(odd) {
        background-color: #f3f3f3 !important;
    }

    .x_content {
        overflow-y: hidden !important; /* 確保內容區域也不出現垂直捲動 */
    }

    /* 調整表格行底色 */
    #table-DOWN {
        border-collapse: collapse;
        width: 100%;
    }

    /* 將原本的條紋樣式覆寫 */
    .table-striped > tbody > tr:nth-of-type(odd) {
        background-color: #f3f3f3; /* 奇數行使用淺灰色 */
    }

    .table-striped > tbody > tr:nth-of-type(even) {
        background-color: #ffffff; /* 偶數行使用白色 */
    }

    /* 覆蓋Bootstrap的表格條紋樣式 */
    .table-striped > tbody > tr {
        background-color: transparent; /* 清除原有背景 */
    }

    /* 確保表格完全顯示，不受限制 */
    .table-fixed-left {
        margin-bottom: 20px; /* 增加底部間距 */
        height: auto !important; /* 表格高度自適應 */
    }

    /* 增強表格行視覺效果 */
    #table-DOWN tr:nth-child(even) {
        background-color: #f9f9f9;
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
      height: 26px;       /* 與車床按鈕接近（可依需求微調） */
      font-size: 10px;     /* 與上方 btn-xs 同大小 */
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
        background-color: #f5f5f5;
    }

    /* 根據記錄數啟用垂直捲動 */
    .table-wrapper.scrollable {
        overflow-y: auto !important; /* 啟用垂直捲動 */
        max-height: 600px !important; /* 設置最大高度 */
        border-bottom: 1px solid #ddd; /* 底部邊框 */
    }
</style>
<script>
  // ------------------------------
  // 輔助函式：escapeHtml
  function escapeHtml(text) {
    if (!text) return "";
    return text.replace(/&/g, "&amp;")
               .replace(/</g, "&lt;")
               .replace(/>/g, "&gt;")
               .replace(/"/g, "&quot;")
               .replace(/'/g, "&#039;");
}

  // ------------------------------
  // 重設表單函式：清空所有表單欄位
  function resetForm() {
    const form = document.querySelector('form');
    if (form) {
      form.reset();
    }
    const inputs = form.querySelectorAll('input, select, textarea');
    inputs.forEach(input => {
      if (input.type === 'text' || input.tagName.toUpperCase() === 'TEXTAREA') {
          input.value = '';
      } else if (input.tagName.toUpperCase() === 'SELECT') {
          input.selectedIndex = 0;
      }
    });
  }

  // ------------------------------
  // 自動隱藏成功訊息（若存在）
  setTimeout(function() {
    var successMessage = document.getElementById('message');
    if (successMessage) {
      successMessage.style.display = 'none';
    }
  }, 3000);

  // ------------------------------
  // 全域變數：訂單狀態篩選，依照按鈕選擇
  // 可設定："in_progress"、"transferred"、"communicate"，空字串表示不篩選
  var orderStatusFilter = "";

  // ------------------------------
  // 自動調整 textarea 高度
  function autoResize(textarea) {
    if (!textarea.dataset.baseHeight) {
      textarea.dataset.baseHeight = textarea.clientHeight;
    }
    var baseHeight = parseInt(textarea.dataset.baseHeight, 10);
    if (textarea.value.trim() === "") {
      textarea.style.height = baseHeight + "px";
      textarea.style.overflowY = "hidden";
      return;
    }
    textarea.style.height = "auto";
    var newHeight = textarea.scrollHeight;
    var maxHeight = baseHeight * 3;
    if (newHeight > maxHeight) {
      textarea.style.height = maxHeight + "px";
      textarea.style.overflowY = "scroll";
    } else {
      textarea.style.height = newHeight + "px";
      textarea.style.overflowY = "hidden";
    }
  }

  // ------------------------------
  // 處理 textarea 按鍵事件：Shift+Enter 允許換行增加行；單獨 Enter 觸發 AJAX 更新
  function handleKeyDown(event, textarea, orderId) {
    var key = event.key || event.keyCode;
    if ((key === "Enter" || key === 13) && event.shiftKey) {
      setTimeout(function() {
        var currentRows = parseInt(textarea.getAttribute("rows"), 10) || 1;
        textarea.setAttribute("rows", currentRows + 1);
        autoResize(textarea);
      }, 0);
    } else if ((key === "Enter" || key === 13) && !event.shiftKey) {
      event.preventDefault();
      var currentVal = textarea.value;
      var origVal = textarea.getAttribute("data-orig") || "";
      if (currentVal !== origVal) {
        updateOrderNote(textarea, orderId);
      } else {
        console.log("內容未變，不更新");
      }
    }
  }

  // ------------------------------
  // AJAX 更新訂單備註
  function updateOrderNote(textarea, orderId) {
    var note = textarea.value;
    console.log("更新 Order ID: " + orderId + ", note: " + note);
    var noteType = textarea.name; // 例如 "Order_ps" 或 "ateNote"
    var xhr = new XMLHttpRequest();
    xhr.open("POST", "../../src/store/_update_order_data.php", true);
    xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
    xhr.onreadystatechange = function() {
      if (xhr.readyState === 4) {
        if (xhr.status === 200) {
          console.log("回應: " + xhr.responseText);
          if (xhr.responseText.indexOf("更新成功") > -1) {
            textarea.style.backgroundColor = "#dff0d8";
            textarea.setAttribute("data-orig", note);
          } else {
            console.log("更新回應異常");
          }
        } else {
          console.error("更新失敗，狀態碼: " + xhr.status);
        }
      }
    };
    var params = "orderId=" + encodeURIComponent(orderId) +
                 "&noteType=" + encodeURIComponent(noteType) +
                 "&note=" + encodeURIComponent(note);
    xhr.send(params);
  }

  // ------------------------------
  // 接單日期篩選處理：使用者輸入例如 "3/20"、">3/20"、"<3/20"，自動補上今年
  function handleOrderDateInput() {
      var dateInput = document.getElementById("order-date-filter");
      if (!dateInput) return;

      var inputVal = dateInput.value.trim();
      if (inputVal === "") {
          filterTable();
          return;
      }

      var operator = "";
      if (inputVal[0] === "=" || inputVal[0] === ">" || inputVal[0] === "<") {
          operator = inputVal[0];
          inputVal = inputVal.substring(1).trim();
      } else {
          operator = "=";
      }

      var parts = inputVal.split("/");
      var formattedDate = "";
      var currentYear = new Date().getFullYear();

      if (parts.length === 2) {
          formattedDate = currentYear + "/" + parts[0] + "/" + parts[1];
      } else if (parts.length === 3) {
          formattedDate = (parts[0].length === 4) ? parts.join("/") : currentYear + "/" + parts[1] + "/" + parts[2];
      } else {
          filterTable();
          return;
      }

      dateInput.value = operator + formattedDate;
      filterTable();
  }

  // ------------------------------
  // 日期篩選處理：使用者輸入例如 "3/20"、">3/20"、"<3/20"，自動補上今年後兩位與 "y/" 前綴
  function handleDateInput() {
    var dateInput = document.getElementById("date-filter");
    if (!dateInput) return;

    var inputVal = dateInput.value.trim();
    if (inputVal === "") {
        // 空字串則不進行格式化，以免產生不必要的篩選
        filterTable();
        return;
    }

    // 從運算子開始擷取，如果第一個字元為 =, >, 或 <
    var operator = "";
    if (inputVal[0] === "=" || inputVal[0] === ">" || inputVal[0] === "<") {
        operator = inputVal[0];
        inputVal = inputVal.substring(1).trim();
    } else {
        operator = "=";
    }

    // 依斜線分割使用者輸入的日期字串
    var parts = inputVal.split("/");
    var formattedDate = "";
    var currentYear = new Date().getFullYear(); // 例如 2025

    if (parts.length === 2) {
        // 僅輸入月/日，補上今年
        formattedDate = currentYear + "/" + parts[0] + "/" + parts[1];
    } else if (parts.length === 3) {
        // 如果第一部分為4位數表示年份，否則視為不足年份，強制用今年
        if (parts[0].length === 4) {
            formattedDate = parts.join("/");
        } else {
            formattedDate = currentYear + "/" + parts[1] + "/" + parts[2];
        }
    } else {
        // 格式不正確則不處理，直接跳出
        filterTable();
        return;
    }

    // 更新輸入欄內容，形如 "=2025/4/10" 或 ">2025/4/10"
    dateInput.value = operator + formattedDate;
    filterTable();
}

  // ------------------------------
  // 數量篩選處理：支援 >、<、= 條件（以數值比較）
  function handleQuantityInput() {
    filterTable();
  }

  // ------------------------------
// 設定按鈕篩選 (批圖狀態)
function setOrderStatusFilter(filterType) {
    // 全域變數 orderStatusFilter 用來儲存目前的篩選條件
    orderStatusFilter = filterType;
    console.log("設定篩選類型為：" + orderStatusFilter);
    filterTable();
}

// 取消所有篩選：清空所有搜尋欄位及下拉選單，並重置全域變數
function cancelFilters() {
    orderStatusFilter = "";
    var filterIds = ["customer-filter", "bom-filter", "order-filter", "order-date-filter", "date-filter", "global-search"];
    filterIds.forEach(function(id) {
        var elem = document.getElementById(id);
        if (elem) { elem.value = ""; }
    });
    var vendorSelect = document.getElementById("vendor-filter");
    if (vendorSelect) { vendorSelect.selectedIndex = 0; }
    var custSelect = document.getElementById("customer-filter");
    if (custSelect) { custSelect.selectedIndex = 0; }
    filterTable();
}

  // ------------------------------
  // 動態更新下拉選單內容
  // - 客戶下拉選單 (select id="customer-filter")：從篩選後的資料列中客戶欄位 (index 3) 取得
  // - 設計下拉選單 (select id="vendor-filter")：從篩選後的資料列中設計/日期欄位 (index 8) 取得，只取前 2 個字
  function updateDropdowns() {
    var customerSet = new Set();
    var vendorSet = new Set();
    
    // 使用篩選後的資料列 (filteredRows) 來更新下拉選單
    if (filteredRows && filteredRows.length > 0) {
      // 從篩選後的資料列取得選項
      for (var i = 0; i < filteredRows.length; i++) {
        var cells = filteredRows[i].getElementsByTagName("td");
        if (cells[3]) {
          var cust = cells[3].textContent.trim();
          if (cust) customerSet.add(cust);
        }
        if (cells[8]) {
          var designFull = cells[8].textContent.trim();
          if (designFull.length >= 2) {
            var designShort = designFull.substring(0, 2);
            vendorSet.add(designShort);
          }
        }
      }
    } else {
      // 如果沒有篩選或篩選後沒有結果，則從所有行取得選項
    var table = document.getElementById("table-DOWN");
    if (!table) return;
    var rows = table.getElementsByTagName("tr");
    for (var i = 1; i < rows.length; i++) {
        var cells = rows[i].getElementsByTagName("td");
        if (cells[3]) {
            var cust = cells[3].textContent.trim();
            if (cust) customerSet.add(cust);
        }
        if (cells[8]) {
            var designFull = cells[8].textContent.trim();
            if (designFull.length >= 2) {
                var designShort = designFull.substring(0, 2);
                vendorSet.add(designShort);
          }
            }
        }
    }
    
    // 更新客戶下拉選單，但先保存現有選擇
    var custSelect = document.getElementById("customer-filter");
    if (custSelect) {
        var currentCustomer = custSelect.value;
        custSelect.innerHTML = "<option value=''>全部客戶</option>";
        Array.from(customerSet).sort().forEach(function(cust) {
            var opt = document.createElement("option");
            opt.value = cust.toLowerCase();
            opt.textContent = cust;
            custSelect.appendChild(opt);
        });
      
      // 如果之前選擇的值仍然存在於新選項中，則保留選擇
      if (currentCustomer) {
        var foundInOptions = false;
        for (var i = 0; i < custSelect.options.length; i++) {
          if (custSelect.options[i].value === currentCustomer) {
            foundInOptions = true;
            break;
          }
        }
        if (foundInOptions) {
        custSelect.value = currentCustomer;
        }
      }
    }
    
    // 更新設計下拉選單，也先保存現有選擇
    // var vendSelect = document.getElementById("vendor-filter");
    // if (vendSelect) {
    //     var currentVendor = vendSelect.value;
    //     vendSelect.innerHTML = "<option value=''>全部設計</option>";
    //     Array.from(vendorSet).sort().forEach(function(vend) {
    //         var opt = document.createElement("option");
    //         opt.value = vend.toLowerCase();
    //         opt.textContent = vend;
    //         vendSelect.appendChild(opt);
    //     });
      
    //   // 如果之前選擇的值仍然存在於新選項中，則保留選擇
    //   if (currentVendor) {
    //     var foundInOptions = false;
    //     for (var i = 0; i < vendSelect.options.length; i++) {
    //       if (vendSelect.options[i].value === currentVendor) {
    //         foundInOptions = true;
    //         break;
    //       }
    //     }
    //     if (foundInOptions) {
    //     vendSelect.value = currentVendor;
    //     }
    //   }
    // }
}

  // ------------------------------
  // 主表格篩選函式
  // 新欄位順序：
  // 0: 修改按鈕 (含隱藏 Order_id)
  // 1: 接單日期
  // 2: 交期
  // 3: 客戶
  // 4: 料號
  // 5: 製程
  // 6: 數量
  // 7: 業務備註
  // 8: 設計/日期
  // 9: 設計備註
  // 10: 轉生管日
  // 11: 訂單編號
  // 12: 客戶單號
  // 13: 容器
  // 14: 樣品 / 治具
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

function filterTable() {
    var table = document.getElementById("table-DOWN");
    if (!table) return;
    var rows = table.getElementsByTagName("tr");
    filteredRows = []; // 重置篩選後的資料列

    // 取得各個篩選欄位的條件
    var custFilterVal   = document.getElementById("customer-filter") ? document.getElementById("customer-filter").value.toLowerCase().trim() : "";
    var bomFilterVal    = document.getElementById("bom-filter") ? document.getElementById("bom-filter").value.toLowerCase().trim() : "";
    var orderFilterVal  = document.getElementById("order-filter") ? document.getElementById("order-filter").value.trim() : "";
    var orderDateFilterVal = document.getElementById("order-date-filter") ? document.getElementById("order-date-filter").value.trim() : "";
    var dateFilterVal   = document.getElementById("date-filter") ? document.getElementById("date-filter").value.trim() : "";
    var vendorFilterVal = document.getElementById("vendor-filter") ? document.getElementById("vendor-filter").value.toLowerCase().trim() : "";
    var globalSearchVal = document.getElementById("global-search") ? document.getElementById("global-search").value.toLowerCase().trim() : "";
    
    // 遍歷每一筆表格資料列（假設第一列是表頭）
    for (var i = 1; i < rows.length; i++) {
        var cells = rows[i].getElementsByTagName("td");
        var show = true;

        // 1. 客戶篩選（假設客戶在 index 3）
        var cellCustomer = cells[3] ? cells[3].textContent.toLowerCase().trim() : "";
        if (custFilterVal && cellCustomer.indexOf(custFilterVal) === -1) {
            show = false;
        }

        // 2. 料號/製程篩選（合併 index 4 與 index 5）
        var cellBom = "";
        if (cells[4]) { cellBom += cells[4].textContent.toLowerCase(); }
        if (cells[5]) { cellBom += " " + cells[5].textContent.toLowerCase(); }
        if (bomFilterVal && cellBom.indexOf(bomFilterVal) === -1) {
            show = false;
        }

        // 3. 數量篩選（假設數量位於 index 6，以 >、<、= 進行比對）
        var cellOrder = cells[6] ? cells[6].textContent.trim() : "";
        if (orderFilterVal) {
            var op = "";
            var filterVal = orderFilterVal;
            if (orderFilterVal[0] === ">" || orderFilterVal[0] === "<" || orderFilterVal[0] === "=") {
                op = orderFilterVal[0];
                filterVal = orderFilterVal.substring(1).trim();
            } else {
                op = "=";
            }
            var numCell = parseFloat(cellOrder);
            var numFilter = parseFloat(filterVal);
            if (!isNaN(numFilter)) {
                if (op === ">") {
                    if (!(numCell > numFilter)) show = false;
                } else if (op === "<") {
                    if (!(numCell < numFilter)) show = false;
                } else {
                    if (numCell !== numFilter) show = false;
                }
            }
        }

        // 4. 接單日期篩選 (與交期篩選邏輯相同，但目標是 cells[1])
        if (orderDateFilterVal) {
            var op = (orderDateFilterVal[0] === "=" || orderDateFilterVal[0] === ">" || orderDateFilterVal[0] === "<") ? orderDateFilterVal[0] : "=";
            var filterDateStr = orderDateFilterVal.substring(op === "=" ? 0 : 1).trim();
            var filterDateObj = parseDateFromNormalizedString(filterDateStr);

            // 取得該列接單日期，格式為 "24y/5/16"
            var cellDateStr = cells[1] ? cells[1].textContent.trim() : "";
            var normalizedCellDateStr = cellDateStr;
            var match = cellDateStr.match(/^(\d{2})y\/(.*)$/);
            if (match) {
                normalizedCellDateStr = "20" + match[1] + "/" + match[2];
            }
            var cellDateObj = parseDateFromNormalizedString(normalizedCellDateStr);

            if (!filterDateObj || !cellDateObj) {
                show = false;
            } else {
                if (op === "=") {
                    if (!datesAreEqual(cellDateObj, filterDateObj)) {
                        show = false;
                    }
                } else if (op === ">") {
                    if (!(cellDateObj > filterDateObj)) {
                        show = false;
                    }
                } else if (op === "<") {
                    if (!(cellDateObj < filterDateObj)) {
                        show = false;
                    }
                }
            }
        }

        // 4. 交期篩選（特殊處理日期）
        // 該篩選欄位的輸入內容 (例如 "=2025/4/10" 或 ">2025/4/10")
        if (dateFilterVal) {
            // 取出運算子（若有）
            var op = (dateFilterVal[0] === "=" || dateFilterVal[0] === ">" || dateFilterVal[0] === "<") ? dateFilterVal[0] : "=";
            var filterDateStr = dateFilterVal.substring(1).trim(); // 例如 "2025/4/10" 或 "2024/4/10"
            var filterDateObj = parseDateFromNormalizedString(filterDateStr);

            // 取得該列交期，交期格式假設為 "25y/4/10" 表示 2025/4/10
            var cellDateStr = cells[2] ? cells[2].textContent.trim() : "";
            var normalizedCellDateStr = cellDateStr;
            var match = cellDateStr.match(/^(\d{2})y\/(.*)$/);
            if (match) {
                normalizedCellDateStr = "20" + match[1] + "/" + match[2];
            }
            var cellDateObj = parseDateFromNormalizedString(normalizedCellDateStr);

            if (!filterDateObj || !cellDateObj) {
                show = false;
            } else {
                if (op === "=") {
                    if (!datesAreEqual(cellDateObj, filterDateObj)) {
                        show = false;
                    }
                } else if (op === ">") {
                    if (!(cellDateObj > filterDateObj)) {
                        show = false;
                    }
                } else if (op === "<") {
                    if (!(cellDateObj < filterDateObj)) {
                        show = false;
                    }
                }
            }
        }

        // 5. 設計篩選（假設設計相關資訊位於 index 8，僅取前 2 個字）
        // var cellVendor = cells[8] ? cells[8].textContent.toLowerCase().trim() : "";
        // if (vendorFilterVal) {
        //     if (cellVendor.substring(0, 2).indexOf(vendorFilterVal) === -1) {
        //         show = false;
        //     }
        // }

        // 6. 全表格搜尋：遍歷整列內所有欄位文字進行包含檢查
        if (globalSearchVal) {
            var found = false;
            for (var j = 0; j < cells.length; j++) {
                if ((cells[j].textContent || "").toLowerCase().indexOf(globalSearchVal) > -1) {
                    found = true;
                    break;
                }
            }
            if (!found) show = false;
        }

        // 7. 按鈕篩選（批圖狀態）：根據設計備註與轉生管日進行判斷（例如 index 9 與 10）
// var cellDesign = cells[8] ? cells[8].textContent.trim() : "";
// var cellDesignNote = cells[9] ? cells[9].textContent.trim() : "";
// // 這裡使用 innerHTML 來讀取 cellPM，以便偵測是否包含轉生管按鈕
// var cellPM = cells[10] ? cells[10].innerHTML.trim() : "";

// // 若設定了全域變數 orderStatusFilter，則依據按鈕篩選條件調整是否顯示這一列
// if (typeof orderStatusFilter !== "undefined" && orderStatusFilter) {
//     if (orderStatusFilter === "transferred") {
//         // 已轉生管：cellPM 中不包含「轉生管」字串，表示已填入日期
//         show = (cellPM.indexOf("轉生管") === -1);
//     } else if (orderStatusFilter === "communicate") {
//         // 批圖溝通中：要求設計備註有內容，且 cellPM 中包含「轉生管」字串
//         show = (cellDesignNote !== "" && cellPM.indexOf("轉生管") > -1);
//     } else if (orderStatusFilter === "in_progress") {
//         // 批圖中：要求 cellPM 中包含「轉生管」字串，且設計備註為空且設計欄位有資料
//         show = (cellPM.indexOf("轉生管") > -1 && cellDesignNote === "" && cellDesign !== "");
//     }
// }

        // 如果該列符合條件，則添加到過濾後的數組
        if (show) {
            filteredRows.push(rows[i]);
        }
        
        // 隱藏所有行，稍後會根據分頁顯示
        rows[i].style.display = "none";
    }
    
    // 更新下拉選單（例如客戶、設計）內容
    updateDropdowns();
    
    // 重置到第一頁並顯示
    currentPage = 1;
    displayPage();
  }
  
  // 顯示當前頁的數據
  function displayPage() {
    // 計算當前頁的索引範圍
    var startIndex = (currentPage - 1) * recordsPerPage;
    var endIndex = Math.min(startIndex + recordsPerPage, filteredRows.length);
    
    // 顯示當前頁的行
    for (var i = 0; i < filteredRows.length; i++) {
        if (i >= startIndex && i < endIndex) {
            filteredRows[i].style.display = "";
        } else {
            filteredRows[i].style.display = "none";
        }
    }
    
    // 更新分頁控制項
    updatePaginationControls();
  }
  
  // 更新分頁控制項
  function updatePaginationControls() {
    var totalPages = Math.max(1, Math.ceil(filteredRows.length / recordsPerPage));
    var paginationInfo = document.getElementById("pagination-info");
    var pageSelector = document.getElementById("page-selector");
    
    if (paginationInfo) {
        paginationInfo.textContent = `顯示 ${filteredRows.length} 筆中的 ${Math.min(recordsPerPage, filteredRows.length)} 筆，第 ${currentPage}/${totalPages} 頁`;
    }
    
    if (pageSelector) {
        pageSelector.innerHTML = '';
        for (var i = 1; i <= totalPages; i++) {
            var option = document.createElement("option");
            option.value = i;
            option.textContent = i;
            if (i === currentPage) {
                option.selected = true;
            }
            pageSelector.appendChild(option);
        }
    }
    
    // 更新按鈕狀態
    document.getElementById("btn-first").disabled = (currentPage === 1);
    document.getElementById("btn-prev").disabled = (currentPage === 1);
    document.getElementById("btn-next").disabled = (currentPage === totalPages);
    document.getElementById("btn-last").disabled = (currentPage === totalPages);
    
    // 更新每頁顯示筆數選擇器
    var recordsPerPageSelector = document.getElementById("records-per-page");
    if (recordsPerPageSelector) {
        recordsPerPageSelector.value = recordsPerPage;
    }
  }
  
  // 翻頁功能
  function goToPage(page) {
    var totalPages = Math.ceil(filteredRows.length / recordsPerPage);
    if (page < 1) page = 1;
    if (page > totalPages) page = totalPages;
    
    currentPage = page;
    displayPage();
  }
  
  // 更改每頁顯示筆數
  function changeRecordsPerPage(value) {
    recordsPerPage = parseInt(value, 10);
    currentPage = 1; // 切換回第一頁
    
    // 切換表格容器樣式
    var tableWrapper = document.querySelector('.table-wrapper');
    if (tableWrapper) {
      if (recordsPerPage > 10) {
        tableWrapper.classList.add('scrollable');
      } else {
        tableWrapper.classList.remove('scrollable');
      }
    }
    
    displayPage();
  }
  
  // 更改頁碼
  function changePageSelector(selector) {
    goToPage(parseInt(selector.value, 10));
}

  // ------------------------------
  // 初始設定：事件綁定、下拉選單更新、並啟動自動更新
  document.addEventListener("DOMContentLoaded", function() {
    updateDropdowns();
    var orderDateInput = document.getElementById("order-date-filter");
    if (orderDateInput) {
      orderDateInput.addEventListener("keydown", function(e) {
        if (e.key === "Enter") {
          e.preventDefault();
          handleOrderDateInput();
        }
      });
    }
    var dateInput = document.getElementById("date-filter");
    if (dateInput) {
      dateInput.addEventListener("keydown", function(e) {
        if (e.key === "Enter") {
          e.preventDefault();
          handleDateInput();
        }
      });
    }
    filterTable();
    setInterval(fetchDataAndUpdate, 5000);
  });

  
// 定義新模式與修改模式的 HTML 片段（全局變數）
var newModeHtml = '<div class="item form-group">' +
    '<label class="control-label col-md-3 col-sm-3 col-xs-12"></label>' +
    '<button name="or_new" type="submit" class="btn btn-primary">新增</button>' +
    '<button name="or_new_copy" type="submit" class="btn btn-success">新增並複製</button>' +
    '&emsp;&emsp;' +
    '<button name="resetpSetting" type="button" class="btn btn-warning" onclick="resetForm()">清除</button>' +
    '</div>';

    var editModeHtml = '<div class="item form-group">' +
    '<label class="control-label col-md-3 col-sm-3 col-xs-12"></label>' +
    '<button name="or_update" type="submit" class="btn btn-primary">更新</button>' +
    '<button name="resetpSetting" type="button" class="btn btn-warning" onclick="cancelEditing(event); resetForm();">取消</button>' +
    '&emsp;&emsp;' +
    '<button name="del_order_track" type="button" class="btn btn-danger" onclick="confirmDelete()">刪除</button>' +
    '</div>';


    function confirmDelete() {
    const confirmBox = document.createElement('div');
    confirmBox.setAttribute('id', 'confirmBox');
    confirmBox.innerHTML = `
        <div style="position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); padding: 20px; background: white; border: 1px solid #ddd; box-shadow: 0px 4px 6px rgba(0,0,0,0.1); z-index: 1000;">
            <p>確認刪除這筆資料嗎？</p>
            <div style="display: flex; gap: 10px; justify-content: center;">
                <button onclick="closeConfirmBox()" style="padding: 10px 20px; background: #ccc; border: none; cursor: pointer;">取消</button>
                <button onclick="submitDelete()" style="padding: 10px 20px; background: #d9534f; color: white; border: none; cursor: pointer;">確認刪除</button>
            </div>
        </div>
        <div style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.5); z-index: 999;" onclick="closeConfirmBox()"></div>
    `;
    document.body.appendChild(confirmBox);
}

function closeConfirmBox() {
    const confirmBox = document.getElementById('confirmBox');
    if (confirmBox) {
        document.body.removeChild(confirmBox);
    }
}

function submitDelete() {
    console.log("submitDelete 被呼叫了"); // 調試訊息
    closeConfirmBox();

    const form = document.getElementById('orderForm');
    if (!form) {
        console.error("找不到表單");
        return;
    }

    const deleteInput = document.createElement('input');
    deleteInput.setAttribute('type', 'hidden');
    deleteInput.setAttribute('name', 'del_order_track');
    deleteInput.setAttribute('value', 'true');
    form.appendChild(deleteInput);
    form.submit();
}


// 當使用者按下「修改」按鈕時，利用 AJAX 從後端取得該筆訂單資料，更新上方表單欄位，並切換上方按鈕區到修改模式
function fetchOrderDetail(link) {
    var orderId = link.getAttribute("data-orderid");
    var xhr = new XMLHttpRequest();
    xhr.open("GET", "../../src/store/_fetch_order_detail.php?oi=" + encodeURIComponent(orderId), true);
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4) {
            if (xhr.status === 200) {
                try {
                    var data = JSON.parse(xhr.responseText);
                    // 更新上方各個表單欄位（請依你的欄位 ID 調整）
                    if (document.getElementById("OrderNo"))
                        document.getElementById("OrderNo").value = data.OrderNo;
                    if (document.getElementById("datepicker"))
                        document.getElementById("datepicker").value = data.orderindate;
                    if (document.getElementById("datepicker_end"))
                        document.getElementById("datepicker_end").value = data.orderDdate;
                    if (document.getElementById("Client_Name"))
                        document.getElementById("Client_Name").value = data.Client_Name;
                    if (document.getElementById("Client_OrderNo"))
                        document.getElementById("Client_OrderNo").value = data.Client_OrderNo;
                    if (document.getElementById("d_id"))
                        document.getElementById("d_id").value = data.d_id;
                    if (document.getElementById("Process"))
                        document.getElementById("Process").value = data.Process;
                    if (document.getElementById("Qty"))
                        document.getElementById("Qty").value = data.Qty;
                    
                    // 處理日期欄位 "datepicker_ate"，若包含時間部分只取日期
                    if (document.getElementById("datepicker_ate")) {
                        var dateVal = data.datepicker_ate;
                        if (dateVal && dateVal.indexOf(" ") !== -1) {
                            dateVal = dateVal.split(" ")[0];
                        }
                        document.getElementById("datepicker_ate").value = dateVal;
                    }
                    
                    if (document.getElementById("drop_zone"))
                        document.getElementById("drop_zone").value = data.drop_zone;
                    if (document.getElementById("Containers"))
                        document.getElementById("Containers").value = data.Containers;
                    if (document.getElementById("sample"))
                        document.getElementById("sample").value = data.sample;
                    if (document.getElementById("jig"))
                        document.getElementById("jig").value = data.jig;
                    if (document.getElementById("Order_ps"))
                        document.getElementById("Order_ps").value = data.Order_ps;
                    if (document.getElementById("ate"))
                        document.getElementById("ate").value = data.ate;
                    
                    // 如果有隱藏欄位 order_id 用來標示修改狀態，也更新之
                    if (document.getElementById("Order_id")) {
                        document.getElementById("Order_id").value = data.Order_id;
                        console.log("Set Order_id:", data.Order_id); // 確認是否正確設置
                    } else {
                        console.error("Element with ID 'Order_id' not found");
                    }
                    
                    // 切換上方按鈕區到修改模式
                    var btnContainer = document.getElementById("topFormButtons");
                    if (btnContainer) {
                        btnContainer.innerHTML = editModeHtml;
                    }
                    
                    // 捲動到最上方
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                } catch (e) {
                    console.error("解析 JSON 失敗：" + e);
                }
            } else {
                console.error("AJAX 請求失敗，狀態碼：" + xhr.status);
            }
        }
    };
    xhr.send();
}

// 當使用者按下「取消」時，透過 AJAX 呼叫後端 API 清除修改狀態，然後更新上方按鈕區回到新模式
function cancelEditing(e) {
    if (e) e.preventDefault();
    var xhr = new XMLHttpRequest();
    xhr.open("GET", "../../src/store/_cancel_order_edit.php", true);
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4) {
            if (xhr.status === 200) {
                try {
                    var response = JSON.parse(xhr.responseText);
                    if (response.success) {
                        // 清除用來標示修改狀態的隱藏欄位（如果存在）
                        var orderIdElem = document.getElementById("order_id");
                        if (orderIdElem) {
                            orderIdElem.value = "";
                        }
                        // 更新上方按鈕區回新模式
                        var btnContainer = document.getElementById("topFormButtons");
                        if (btnContainer) {
                            btnContainer.innerHTML = newModeHtml;
                        }
                        window.scrollTo({ top: 0, behavior: "smooth" });
                    } else {
                        console.error("取消修改失敗");
                    }
                } catch (ex) {
                    console.error("解析 JSON 失敗：" + ex);
                }
            } else {
                console.error("AJAX 請求失敗，狀態碼：" + xhr.status);
            }
        }
    };
    xhr.send();
}

let skipUpdateOrders = new Set(); // 用於保護用戶操作行

function updatePmGet(orderId) {
    const cell = document.querySelector(`tr[data-orderid='${orderId}'] td[name='pmGet']`);
    if (!cell) {
        console.warn(`未找到訂單 ID 為 ${orderId} 的元素`);
        return; // 提前退出避免錯誤
    }

    // 顯示處理中的狀態
    cell.innerHTML = `<span class="loading-spinner">處理中...</span>`;
    skipUpdateOrders.add(orderId);

    var xhr = new XMLHttpRequest();
    xhr.open("POST", "../../src/store/_update_order_List.php", true);
    xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
    xhr.onreadystatechange = function () {
        if (xhr.readyState === 4 && xhr.status === 200) {
            try {
                const response = JSON.parse(xhr.responseText);
                if (response.success) {
                    const currentDate = new Date();
                    const formattedDate = `${currentDate.getMonth() + 1}/${currentDate.getDate()}`;
                    cell.innerHTML = `
                        <button type="button"
                                class="btn btn-xs btn-danger"
                                onclick="cancelPmGet('${orderId}')">取消</button>
                        ${formattedDate}`;
                } else {
                    console.error("更新失敗: " + response.message);
                    cell.innerHTML = `<span class="error-message">更新失敗，請重試！</span>`;
                }
            } catch (e) {
                console.error("解析 JSON 失敗: " + e);
                cell.innerHTML = `<span class="error-message">解析失敗，請重試！</span>`;
            }
        }
        skipUpdateOrders.delete(orderId); // 操作完成後移除
    };
    xhr.send(`Order_id=${encodeURIComponent(orderId)}`);
}

function fetchUpdates() {
    $.ajax({
        url: "../../src/store/_fetch_updates.php",
        method: "GET",
        success: function (data) {
            data.forEach(order => {
                if (!skipUpdateOrders.has(order.Order_id)) {
                    updateOrderRow(order);
                }
            });
        },
        error: function (xhr, status, error) {
            console.error(`5秒更新請求失敗：${error}`);
        }
    });
}

function cancelPmGet(orderId) {
    const cell = document.querySelector(`tr[data-orderid='${orderId}'] td[name='pmGet']`);
    if (!cell) {
        console.warn(`未找到訂單 ID 為 ${orderId} 的元素`);
        return;
    }
    cell.innerHTML = `<span class="loading-spinner">處理中...</span>`;

    var xhr = new XMLHttpRequest();
    xhr.open("POST", "../../src/store/_update_order_List.php", true);
    xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
    xhr.onreadystatechange = function () {
        if (xhr.readyState === 4 && xhr.status === 200) {
            try {
                const response = JSON.parse(xhr.responseText);
                if (response.success) {
                    cell.innerHTML = `
                        <button type="button"
                                class="btn btn-warning btn-xs"
                                onclick="updatePmGet('${orderId}')">轉生管</button>`;
                } else {
                    console.error("取消失敗: " + response.message);
                    cell.innerHTML = `<span class="error-message">取消失敗，請重試！</span>`;
                }
            } catch (e) {
                console.error("解析 JSON 失敗: " + e);
                cell.innerHTML = `<span class="error-message">解析失敗，請重試！</span>`;
            }
        }
    };
    xhr.send(`Order_id=${encodeURIComponent(orderId)}&action=cancel`);
}

function updateOrderRow(order) {
    const existingRow = document.querySelector(`tr[data-orderid='${order.Order_id}']`);
    if (!existingRow) {
        console.warn(`行未找到，訂單 ID 為 ${order.Order_id}，跳過更新`);
        return;
    }

    const cell = existingRow.querySelector("td[name='pmGet']");
    if (cell) {
        if (order.pmGet) {
            cell.innerHTML = `
                <button type="button"
                        class="btn btn-xs btn-danger"
                        onclick="cancelPmGet('${order.Order_id}')">取消</button>
                ${order.pmGet}`;
        } else {
            cell.innerHTML = `
                <button type="button"
                        class="btn btn-warning btn-xs"
                        onclick="updatePmGet('${order.Order_id}')">轉生管</button>`;
        }
    }
}

document.addEventListener("DOMContentLoaded", function () {
    setInterval(fetchUpdates, 5000);
});

function resetCancelToOriginal(cell, orderId) {
    var currentDate = new Date();
    var formattedDate = (currentDate.getMonth() + 1) + "/" + currentDate.getDate();
    cell.innerHTML = `
        <button type="button" 
                class="btn btn-xs btn-danger" 
                onclick="cancelPmGet('${orderId}')">取消</button>
        ${formattedDate}`;
}

  // ------------------------------
  // 全域變數：分頁相關
  var currentPage = 1;
  var recordsPerPage = 10;
  var filteredRows = []; // 儲存篩選後的資料列參考
  
  // ------------------------------
  // 主表格篩選函式 (更新以支持分頁)
  function filterTable() {
    var table = document.getElementById("table-DOWN");
    if (!table) return;
    var rows = table.getElementsByTagName("tr");
    filteredRows = []; // 重置篩選後的資料列
    
    // 取得各個篩選欄位的條件
    var custFilterVal   = document.getElementById("customer-filter") ? document.getElementById("customer-filter").value.toLowerCase().trim() : "";
    var bomFilterVal    = document.getElementById("bom-filter") ? document.getElementById("bom-filter").value.toLowerCase().trim() : "";
    var orderFilterVal  = document.getElementById("order-filter") ? document.getElementById("order-filter").value.trim() : "";
    var dateFilterVal   = document.getElementById("date-filter") ? document.getElementById("date-filter").value.trim() : "";
    var vendorFilterVal = document.getElementById("vendor-filter") ? document.getElementById("vendor-filter").value.toLowerCase().trim() : "";
    var globalSearchVal = document.getElementById("global-search") ? document.getElementById("global-search").value.toLowerCase().trim() : "";
    
    // 遍歷每一筆表格資料列（假設第一列是表頭）
    for (var i = 1; i < rows.length; i++) {
        var cells = rows[i].getElementsByTagName("td");
        var show = true;

        // 1. 客戶篩選（假設客戶在 index 3）
        var cellCustomer = cells[3] ? cells[3].textContent.toLowerCase().trim() : "";
        if (custFilterVal && cellCustomer.indexOf(custFilterVal) === -1) {
            show = false;
        }

        // 2. 料號/製程篩選（合併 index 4 與 index 5）
        var cellBom = "";
        if (cells[4]) { cellBom += cells[4].textContent.toLowerCase(); }
        if (cells[5]) { cellBom += " " + cells[5].textContent.toLowerCase(); }
        if (bomFilterVal && cellBom.indexOf(bomFilterVal) === -1) {
            show = false;
        }

        // 3. 數量篩選（假設數量位於 index 6，以 >、<、= 進行比對）
        var cellOrder = cells[6] ? cells[6].textContent.trim() : "";
        if (orderFilterVal) {
            var op = "";
            var filterVal = orderFilterVal;
            if (orderFilterVal[0] === ">" || orderFilterVal[0] === "<" || orderFilterVal[0] === "=") {
                op = orderFilterVal[0];
                filterVal = orderFilterVal.substring(1).trim();
            } else {
                op = "=";
            }
            var numCell = parseFloat(cellOrder);
            var numFilter = parseFloat(filterVal);
            if (!isNaN(numFilter)) {
                if (op === ">") {
                    if (!(numCell > numFilter)) show = false;
                } else if (op === "<") {
                    if (!(numCell < numFilter)) show = false;
                } else {
                    if (numCell !== numFilter) show = false;
                }
            }
        }

        // 4. 交期篩選（特殊處理日期）
        // 該篩選欄位的輸入內容 (例如 "=2025/4/10" 或 ">2025/4/10")
        if (dateFilterVal) {
            // 取出運算子（若有）
            var op = (dateFilterVal[0] === "=" || dateFilterVal[0] === ">" || dateFilterVal[0] === "<") ? dateFilterVal[0] : "=";
            var filterDateStr = dateFilterVal.substring(1).trim(); // 例如 "2025/4/10" 或 "2024/4/10"
            var filterDateObj = parseDateFromNormalizedString(filterDateStr);

            // 取得該列交期，交期格式假設為 "25y/4/10" 表示 2025/4/10
            var cellDateStr = cells[2] ? cells[2].textContent.trim() : "";
            var normalizedCellDateStr = cellDateStr;
            var match = cellDateStr.match(/^(\d{2})y\/(.*)$/);
            if (match) {
                normalizedCellDateStr = "20" + match[1] + "/" + match[2];
            }
            var cellDateObj = parseDateFromNormalizedString(normalizedCellDateStr);

            if (!filterDateObj || !cellDateObj) {
                show = false;
            } else {
                if (op === "=") {
                    if (!datesAreEqual(cellDateObj, filterDateObj)) {
                        show = false;
                    }
                } else if (op === ">") {
                    if (!(cellDateObj > filterDateObj)) {
                        show = false;
                    }
                } else if (op === "<") {
                    if (!(cellDateObj < filterDateObj)) {
                        show = false;
                    }
                }
            }
        }

        // 5. 設計篩選（假設設計相關資訊位於 index 8，僅取前 2 個字）
        // var cellVendor = cells[8] ? cells[8].textContent.toLowerCase().trim() : "";
        // if (vendorFilterVal) {
        //     if (cellVendor.substring(0, 2).indexOf(vendorFilterVal) === -1) {
        //         show = false;
        //     }
        // }

        // 6. 全表格搜尋：遍歷整列內所有欄位文字進行包含檢查
        if (globalSearchVal) {
            var found = false;
            for (var j = 0; j < cells.length; j++) {
                if ((cells[j].textContent || "").toLowerCase().indexOf(globalSearchVal) > -1) {
                    found = true;
                    break;
                }
            }
            if (!found) show = false;
        }

        // 7. 按鈕篩選（批圖狀態）：根據設計備註與轉生管日進行判斷（例如 index 9 與 10）
        // var cellDesign = cells[8] ? cells[8].textContent.trim() : "";
        // var cellDesignNote = cells[9] ? cells[9].textContent.trim() : "";
        // // 這裡使用 innerHTML 來讀取 cellPM，以便偵測是否包含轉生管按鈕
        // var cellPM = cells[10] ? cells[10].innerHTML.trim() : "";

        // 若設定了全域變數 orderStatusFilter，則依據按鈕篩選條件調整是否顯示這一列
        // if (typeof orderStatusFilter !== "undefined" && orderStatusFilter) {
        //     if (orderStatusFilter === "transferred") {
        //         // 已轉生管：cellPM 中不包含「轉生管」字串，表示已填入日期
        //         show = (cellPM.indexOf("轉生管") === -1);
        //     } else if (orderStatusFilter === "communicate") {
        //         // 批圖溝通中：要求設計備註有內容，且 cellPM 中包含「轉生管」字串
        //         show = (cellDesignNote !== "" && cellPM.indexOf("轉生管") > -1);
        //     } else if (orderStatusFilter === "in_progress") {
        //         // 批圖中：要求 cellPM 中包含「轉生管」字串，且設計備註為空且設計欄位有資料
        //         show = (cellPM.indexOf("轉生管") > -1 && cellDesignNote === "" && cellDesign !== "");
        //     }
        // }

        // 如果該列符合條件，則添加到過濾後的數組
        if (show) {
            filteredRows.push(rows[i]);
        }
        
        // 隱藏所有行，稍後會根據分頁顯示
        rows[i].style.display = "none";
    }
    
    // 更新下拉選單（例如客戶、設計）內容
    updateDropdowns();
    
    // 重置到第一頁並顯示
    currentPage = 1;
    displayPage();
  }
  
  // 顯示當前頁的數據
  function displayPage() {
    // 計算當前頁的索引範圍
    var startIndex = (currentPage - 1) * recordsPerPage;
    var endIndex = Math.min(startIndex + recordsPerPage, filteredRows.length);
    
    // 顯示當前頁的行
    for (var i = 0; i < filteredRows.length; i++) {
        if (i >= startIndex && i < endIndex) {
            filteredRows[i].style.display = "";
        } else {
            filteredRows[i].style.display = "none";
        }
    }
    
    // 更新分頁控制項
    updatePaginationControls();
  }
  
  // 更新分頁控制項
  function updatePaginationControls() {
    var totalPages = Math.max(1, Math.ceil(filteredRows.length / recordsPerPage));
    var paginationInfo = document.getElementById("pagination-info");
    var pageSelector = document.getElementById("page-selector");
    
    if (paginationInfo) {
        paginationInfo.textContent = `顯示 ${filteredRows.length} 筆中的 ${Math.min(recordsPerPage, filteredRows.length)} 筆，第 ${currentPage}/${totalPages} 頁`;
    }
    
    if (pageSelector) {
        pageSelector.innerHTML = '';
        for (var i = 1; i <= totalPages; i++) {
            var option = document.createElement("option");
            option.value = i;
            option.textContent = i;
            if (i === currentPage) {
                option.selected = true;
            }
            pageSelector.appendChild(option);
        }
    }
    
    // 更新按鈕狀態
    document.getElementById("btn-first").disabled = (currentPage === 1);
    document.getElementById("btn-prev").disabled = (currentPage === 1);
    document.getElementById("btn-next").disabled = (currentPage === totalPages);
    document.getElementById("btn-last").disabled = (currentPage === totalPages);
    
    // 更新每頁顯示筆數選擇器
    var recordsPerPageSelector = document.getElementById("records-per-page");
    if (recordsPerPageSelector) {
        recordsPerPageSelector.value = recordsPerPage;
    }
  }
  
  // 翻頁功能
  function goToPage(page) {
    var totalPages = Math.ceil(filteredRows.length / recordsPerPage);
    if (page < 1) page = 1;
    if (page > totalPages) page = totalPages;
    
    currentPage = page;
    displayPage();
  }
  
  // 更改每頁顯示筆數
  function changeRecordsPerPage(value) {
    recordsPerPage = parseInt(value, 10);
    currentPage = 1; // 切換回第一頁
    
    // 切換表格容器樣式
    var tableWrapper = document.querySelector('.table-wrapper');
    if (tableWrapper) {
      if (recordsPerPage > 10) {
        tableWrapper.classList.add('scrollable');
      } else {
        tableWrapper.classList.remove('scrollable');
      }
    }
    
    displayPage();
  }
  
  // 更改頁碼
  function changePageSelector(selector) {
    goToPage(parseInt(selector.value, 10));
  }

  // ------------------------------
  // 初始設定：事件綁定、下拉選單更新、並啟動自動更新
  document.addEventListener("DOMContentLoaded", function() {
    updateDropdowns();
    var dateInput = document.getElementById("date-filter");
    if (dateInput) {
      dateInput.addEventListener("keydown", function(e) {
        if (e.key === "Enter") {
          e.preventDefault();
          handleDateInput();
        }
      });
    }
    filterTable();
    setInterval(fetchDataAndUpdate, 5000);
  });

  // ... existing code ...
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
                    <h2>未交訂單</h2>
                    <div class="title_left">
                        <h4>
                            <?php
                                if(!empty($_GET['message'])) {
                                    if($_GET['message']=="success") {
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
                                    <h2>訂單總覽
                                        <div class="title">
                                            <!-- <a><input type="button" class="btn btn-xs btn-primary" value="批圖中" onclick="setOrderStatusFilter('in_progress')"></a> -->
                                            <!-- <a><input type="button" class="btn btn-xs btn-primary" value="已轉生管" onclick="setOrderStatusFilter('transferred')"></a> -->
                                            <!-- <a><input type="button" class="btn btn-xs btn-primary" value="批圖溝通中" onclick="setOrderStatusFilter('communicate')"></a> -->
                                            <a><input type="button" id="cancelBtn" class="btn btn-xs btn-warning" value="取消篩選" onclick="cancelFilters();"></a>
                                        </div>
                                    </h2>
                                    <ul class="nav navbar-right panel_toolbox">
                                        <li><a class="collapse-link"><i class="fa fa-chevron-up"></i></a></li>
                                        <li><a class="close-link"><i class="fa fa-close"></i></a></li>
                                    </ul>
                                    <div class="clearfix"></div>
                                    <!-- 過濾條件區 -->
                                    <div class="all-filters2" style="margin-bottom:10px;">
                                        <!-- 客戶 -->
                                        <select id="customer-filter" onchange="filterTable()"></select>
                                        <input type="text" id="order-date-filter"
                                               onkeydown="if(event.key === 'Enter'){ event.preventDefault(); handleOrderDateInput(); }"
                                               onblur="handleOrderDateInput()"
                                               placeholder="搜索 接單 (例：2/8, >2/8, <2/8)">
                                        <input type="text" id="bom-filter" placeholder="搜索 料號/製程" onkeyup="filterTable()">
                                        <input type="text" id="order-filter" placeholder="搜索 數量 (例：50, >50, <50)" onkeyup="filterTable()">
                                        <input type="text" id="date-filter" 
                                               onkeydown="if(event.key === 'Enter'){ event.preventDefault(); handleDateInput(); }" 
                                               onblur="handleDateInput()" 
                                               placeholder="搜索 交期 (例：2/8, >2/8, <2/8)">
                                        <!-- <select id="vendor-filter" onchange="filterTable()"></select> -->
                                        <input type="text" id="global-search" placeholder="全表格搜索" onkeyup="filterTable()">
                                    </div>
                                </div>
                                <div class="x_content">
                                    
                                    <!-- 在表格上方添加分頁控制項 -->
                                    <div class="pagination-controls">
                                        <div class="pagination-info" id="pagination-info">
                                            顯示 0 筆中的 0 筆，第 0/0 頁
                                        </div>
                                        <div class="pagination-buttons">
                                            <button id="btn-first" onclick="goToPage(1); return false;" title="第一頁"><<</button>
                                            <button id="btn-prev" onclick="goToPage(currentPage - 1); return false;" title="上一頁"><</button>
                                            <select id="page-selector" class="page-selector" onchange="changePageSelector(this)"></select>
                                            <button id="btn-next" onclick="goToPage(currentPage + 1); return false;" title="下一頁">></button>
                                            <button id="btn-last" onclick="goToPage(Math.ceil(filteredRows.length / recordsPerPage)); return false;" title="最後一頁">>></button>
                                            <span class="records-per-page">
                                                每頁顯示
                                                <select id="records-per-page" onchange="changeRecordsPerPage(this.value)">
                                                    <option value="5">5</option>
                                                    <option value="10" selected>10</option>
                                                    <option value="20">20</option>
                                                    <option value="50">50</option>
                                                    <option value="100">100</option>
                                                </select>
                                                筆
                                            </span>
                                        </div>
                                    </div>

                                    <!-- 顯示訂單資料 -->
                                    <div class="table-wrapper table-fixed-left">
                                        <table id="table-DOWN" class="table table-striped" border="1" cellspacing="0" cellpadding="5">
                                            <thead>
                                                <tr>
                                                    <th hidden></th>                   <!-- index 0：修改按鈕 -->
                                                    <th>接單</th>           <!-- index 1 -->
                                                    <th>交期</th>           <!-- index 2 -->
                                                    <th>客戶</th>           <!-- index 3 -->
                                                    <th>料號</th>           <!-- index 4 -->
                                                    <th>規格</th>           <!-- index 5 -->
                                                    <th>數量</th>            <!-- index 6 -->
                                                    <th>未交數量</th>            <!-- index 6 -->
                                                    <!-- <th>業務備註</th>       index 7 -->
                                                    <!-- <th>設計/日期</th>       index 8 -->
                                                    <!-- <th>設計備註</th>        index 9 -->
                                                    <!-- <th>轉生管日</th>        index 10 -->
                                                    <th>訂單編號</th>        <!-- index 11 -->
                                                    <!-- <th>容器</th>            index 12 -->
                                                    <th>樣品 / 治具</th>     <!-- index 13 -->
                                                    <!-- <th>下料區域</th>       index 14 -->
                                                    <!-- <th>客戶單號</th>       index 15 -->
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($order_list as $order): ?>
                                                <tr data-orderid="<?= $order['Order_id'] ?>">
                                                    <!-- 修改按鈕 -->
                                                    <td hidden>
                                                        <a href="javascript:void(0);" 
                                                             data-orderid="<?= $order['Order_id'] ?>"
                                                             onclick="fetchOrderDetail(this)">
                                                            <input type="button" name="updateDrawing" class="btn btn-warning btn-xs update" value="修改">
                                                            <input type="hidden" name="Order_id" value="<?= $order['Order_id'] ?>">
                                                        </a>
                                                    </td>
                                                    <!-- 接單日期 -->
                                                    <td name="Order_date"><?= $order['Order_date'] ?></td>
                                                    <!-- 交期 -->
                                                    <td name="Delivery_date_T"><?= $order['Delivery_date_T'] ?></td>
                                                    <!-- 客戶 -->
                                                    <td name="Client_name"><?= $order['Client_name'] ?></td>
                                                    <!-- 料號 -->
                                                    <td name="d_id"><?= $order['d_id'] ?></td>
                                                    <!-- 規格 -->
                                                    <td name="Specification"><?= $order['Specification'] ?></td>
                                                    <!-- 數量 -->
                                                    <td name="Qty"><?= $order['Qty'] ?></td>
                                                    <!-- 未交數量 -->
                                                    <td name="Open_Qty">
                                                        <?php
                                                            if ($order['Qty'] == $order['Open_Qty']) {
                                                                echo "尚未交貨";
                                                            } else {
                                                                echo $order['Open_Qty'];
                                                            }
                                                        ?>
                                                    </td>
                                                    <!-- 業務備註 -->
                                                    <!-- <?php
                                                      $ps = $order['Order_ps'];
                                                      $lineCount = max(substr_count($ps, "\n") + 1, 1);
                                                      $initialRows = ($lineCount > 3) ? 3 : $lineCount;
                                                    ?>
                                                    <td name="Order_ps">
                                                      <textarea id="Order_ps-<?= trim($order['Order_id']) ?>"
                                                        name="Order_ps"
                                                        rows="<?= $initialRows ?>"
                                                        data-orig="<?= htmlspecialchars($order['Order_ps']) ?>"
                                                        style="resize: none; overflow: hidden; line-height:1.2em; padding:2px;<?= ($lineCount > 3) ? 'overflow-y: scroll;' : '' ?>"
                                                        oninput="autoResize(this)"
                                                        onkeydown="handleKeyDown(event, this, '<?= trim($order['Order_id']) ?>')"><?= htmlspecialchars($order['Order_ps']) ?></textarea>
                                                    </td> -->
                                                    <!-- 設計/日期 -->
                                                    <!-- <?php if(isset($order['ateGet'])): ?>
                                                      <td name="ate"><?= mb_substr($order['user_cname'], -2, 2, 'UTF-8') ?> <?= $order['ateGet'] ?></td>
                                                    <?php else: ?>
                                                      <td name="ate"><?= mb_substr($order['user_cname'], -2, 2, 'UTF-8') ?></td>
                                                    <?php endif; ?> -->
                                                    <!-- 設計備註 -->
                                                    <!-- <?php
                                                      $ps2 = $order['ateNote'];
                                                      $lineCount2 = max(substr_count($ps2, "\n") + 1, 1);
                                                      $initialRows2 = ($lineCount2 > 3) ? 3 : $lineCount2;
                                                    ?> -->
                                                    <!-- <td name="ateNote">
                                                      <textarea id="ateNote-<?= trim($order['Order_id']) ?>"
                                                        name="ateNote"
                                                        rows="<?= $initialRows2 ?>"
                                                        data-orig="<?= htmlspecialchars($order['ateNote']) ?>"
                                                        style="resize: none; overflow: hidden; line-height:1.2em; padding:2px;<?= ($lineCount2 > 3) ? 'overflow-y: scroll;' : '' ?>"
                                                        oninput="autoResize(this)"
                                                        onkeydown="handleKeyDown(event, this, '<?= trim($order['Order_id']) ?>')"><?= htmlspecialchars($order['ateNote']) ?></textarea>
                                                    </td> -->
                                                    <!-- 轉生管日 -->
                                                    <!-- <?php if (isset($order['pmGet'])): ?>
                                                        <td name="pmGet">
                                                            <button type="button" 
                                                                    class="btn btn-xs btn-danger" 
                                                                    onclick="cancelPmGet('<?= $order['Order_id'] ?>')">取消</button>
                                                            <?= $order['pmGet'] ?>
                                                        </td>
                                                    <?php else: ?>
                                                        <td name="pmGet">
                                                            <button type="button" 
                                                                    class="btn btn-warning btn-xs" 
                                                                    onclick="updatePmGet('<?= $order['Order_id'] ?>')">轉生管</button>
                                                        </td>
                                                    <?php endif; ?> -->
                                                    <!-- 訂單編號 -->
                                                    <td name="Order_oo"><?= $order['Order_oo'] ?></td>
                                                    <!-- 容器 -->
                                                    <!-- <td name="Containers"><?= $order['Containers'] ?></td> -->
                                                    <!-- 樣品 / 治具 -->
                                                    <?php if($order['Sample'] != "" && $order['JIG'] != ""): ?>
                                                      <td name="Sample"><?= $order['Sample'] ?> / <?= $order['JIG'] ?></td>
                                                    <?php elseif($order['Sample'] != ""): ?>
                                                      <td name="Sample"><?= $order['Sample'] ?></td>
                                                    <?php elseif($order['JIG'] != ""): ?>
                                                      <td name="Sample"> / <?= $order['JIG'] ?></td>
                                                    <?php else: ?>
                                                      <td name="Sample"></td>
                                                    <?php endif; ?>
                                                    <!-- 下料區域 -->
                                                    <!-- <td name="drop_zone"><?= $order['drop_zone'] ?></td> -->
                                                    <!-- 客戶單號 -->
                                                    <!-- <td name="C_order"><?= $order['C_order'] ?></td> -->
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div> <!-- x_content -->
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
    <script src="../../resource/js/jszip.min.js"></script>    <script src="../../resource/js/pdfmake.min.js"></script>
    <script src="../../resource/js/vfs_fonts.js"></script>    
    <!-- Custom Theme Scripts -->
    <script src="../../resource/js/custom.min.js"></script>

    <!-- <script src="http://code.jquery.com/jquery-1.10.2.js"></script> -->
    <script src="http://code.jquery.com/ui/1.11.4/jquery-ui.js"></script>
    
    <script>
      // 設定 jQuery UI Datepicker 的預設區域設定 (只設定一次)
      $(function() {
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

          $("#datepicker_end").datepicker({
              changeMonth: true,
              changeYear: true,
              showMonthAfterYear: true
          });

          $("#datepicker_ate").datepicker({
              changeMonth: true,
              changeYear: true,
              showMonthAfterYear: true
          });
      });
  
  // ... existing code ...

  // 分页功能修复: 重寫displayPage函數
  function displayPage() {
    // 確保filteredRows有數據
    if (!filteredRows || filteredRows.length === 0) {
      console.log("沒有找到符合條件的數據");
      return;
    }
    
    // 計算當前頁的索引範圍
    var startIndex = (currentPage - 1) * recordsPerPage;
    var endIndex = Math.min(startIndex + recordsPerPage, filteredRows.length);
    
    // 首先隱藏所有行
    for (var i = 0; i < filteredRows.length; i++) {
      filteredRows[i].style.display = "none";
    }
    
    // 然後僅顯示當前頁的行
    for (var i = startIndex; i < endIndex; i++) {
      if (i < filteredRows.length) {
        filteredRows[i].style.display = "";
      }
    }
    
    // 更新分頁資訊和控制按鈕
    updatePaginationControls();
    console.log("當前頁: " + currentPage + ", 顯示行: " + startIndex + " 至 " + (endIndex-1));
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

  // 確保分頁按鈕工作正常
  function goToPage(page) {
    // 阻止按鈕默認行為
    event.preventDefault(); 
    console.log("嘗試跳轉到頁面: " + page);
    var totalPages = Math.ceil(filteredRows.length / recordsPerPage);
    
    if (page < 1) page = 1;
    if (page > totalPages) page = totalPages;
    
    currentPage = page;
    displayPage();
    return false; // 防止頁面刷新
  }

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
</body>
</html>