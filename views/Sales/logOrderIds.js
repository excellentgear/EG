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
  
  return orderIds; // 返回ID列表，以防需要在其他地方使用
}
