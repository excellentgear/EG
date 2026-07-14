// 自動抓取當前頁面的ORDER_ID並列在CONSOLE中
function logVisibleOrderIds() {
  console.clear(); // 清除先前的console記錄
  console.log("=========== 當前頁面ORDER_ID列表 ===========");
  var visibleRows = document.querySelectorAll('#table-DOWN tbody tr:not([style*="display: none"])');
  var orderIds = [];
  
  visibleRows.forEach(function(row) {
    var orderId = row.getAttribute('data-orderid');
    if (orderId) {
      orderIds.push(orderId);
      console.log("ORDER_ID: " + orderId);
    }
  });
  
  console.log("共 " + orderIds.length + " 個訂單ID");
  console.log("=========================================");
  
  return orderIds;
}

// 在頁面載入完成後自動記錄ORDER_ID
document.addEventListener("DOMContentLoaded", function() {
  // 頁面首次載入後記錄
  setTimeout(logVisibleOrderIds, 500);
  
  // 監聽篩選器變化
  var filterInputs = document.querySelectorAll('input[type="text"], select');
  filterInputs.forEach(function(input) {
    input.addEventListener("change", function() {
      setTimeout(logVisibleOrderIds, 500);
    });
    
    if (input.type === "text") {
      input.addEventListener("keyup", function() {
        setTimeout(logVisibleOrderIds, 500);
      });
    }
  });
  
  // 監聽分頁按鈕
  var paginationButtons = document.querySelectorAll('.pagination-btn, .btn-first, .btn-prev, .btn-next, .btn-last');
  paginationButtons.forEach(function(button) {
    button.addEventListener("click", function() {
      setTimeout(logVisibleOrderIds, 500);
    });
  });
  
  // 監聽每頁顯示筆數更改
  var recordsPerPageSelector = document.getElementById("records-per-page");
  if (recordsPerPageSelector) {
    recordsPerPageSelector.addEventListener("change", function() {
      setTimeout(logVisibleOrderIds, 500);
    });
  }
  
  // 監聽篩選表格功能
  var originalFilterTable = window.filterTable;
  if (typeof originalFilterTable === 'function') {
    window.filterTable = function() {
      originalFilterTable.apply(this, arguments);
      setTimeout(logVisibleOrderIds, 500);
    };
  }
  
  // 監聽顯示頁面功能
  var originalDisplayPage = window.displayPage;
  if (typeof originalDisplayPage === 'function') {
    window.displayPage = function() {
      originalDisplayPage.apply(this, arguments);
      setTimeout(logVisibleOrderIds, 500);
    };
  }
});
