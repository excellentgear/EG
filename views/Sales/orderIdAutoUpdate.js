/** 
 * orderIdAutoUpdate.js - 定時自動更新Console中的ORDER_ID
 * 使用AJAX實現定時抓取當前頁面的ORDER_ID
 */
function formatToShortYear(dateStr) {
  if (!dateStr) return '';
  const date = new Date(dateStr);
  if (isNaN(date)) return dateStr;
  const year = String(date.getFullYear()).slice(-2); // 後兩碼
  const month = date.getMonth() + 1;
  const day = date.getDate();
  return `${year}y/${month}/${day}`;
}

function formatMonthDay(dateStr) {
  if (!dateStr) return '';
  const date = new Date(dateStr);
  if (isNaN(date)) return dateStr;
  const month = date.getMonth() + 1;
  const day = date.getDate();
  return `${month}/${day}`;
}

// Helper function for escaping HTML, needed for onclick attributes
function escapeHtml(unsafe) {
  if (typeof unsafe !== 'string') return '';
  return unsafe
       .replace(/&/g, "&amp;")
       .replace(/</g, "&lt;")
       .replace(/>/g, "&gt;")
       .replace(/"/g, "&quot;")
       .replace(/'/g, "&#039;");
}

// 創建一個命名空間以避免全局變量/函數衝突
window.orderIdLogger = {
  // 配置
  currentOrderIds: [],
  autoUpdateInterval: null,
  UPDATE_INTERVAL: 60000, // 更新頻率: 60秒
  shouldAutoUpdate: true,
  
  // 函數
  fetchOrderUpdates: function() {
    if (this.shouldAutoUpdate) {
      this.updateOrderIds();
    }
  },

  // 啟動自動更新
  startOrderIdAutoUpdate: function() {
    // 先清除可能已存在的定時器
    if (this.autoUpdateInterval) {
      clearInterval(this.autoUpdateInterval);
    }
    
    // 初始更新一次
    this.updateOrderIds();
    
    // 設置定時更新
    const self = this;
    this.autoUpdateInterval = setInterval(function() {
      if (self.shouldAutoUpdate) {
        self.updateOrderIds();
      }
    }, this.UPDATE_INTERVAL);
    console.log("已啟動ORDER_ID自動更新，間隔" + (this.UPDATE_INTERVAL/1000) + "秒");
  },

  // 停止自動更新
  stopOrderIdAutoUpdate: function() {
    if (this.autoUpdateInterval) {
      clearInterval(this.autoUpdateInterval);
      this.autoUpdateInterval = null;
      this.shouldAutoUpdate = false;
      console.log("已停止ORDER_ID自動更新");
    }
  },

  // 更新ORDER_ID並顯示在console
  updateOrderIds: function() {
    // 檢查shouldAutoUpdate標誌
    if (!this.shouldAutoUpdate) {
      console.log("自動更新已暫停");
      return;
    }
    
    try {
      // 先獲取當前頁面上顯示的ORDER_ID列表
      let visibleIds = logVisibleOrderIds();
      
      // 如果頁面上沒有任何ORDER_ID，則不發送請求
      if (!visibleIds || visibleIds.length === 0) {
        console.log("當前頁面沒有顯示任何ORDER_ID，跳過更新");
        return;
      }
      
      // 保存當前的ORDER_ID，用於後續比較
      this.currentOrderIds = visibleIds;
      
      // --- NEW: Get the selected year from the dropdown ---
      const yearSelect = document.getElementById('year-select');
      const selectedYear = yearSelect ? yearSelect.value : new Date().getFullYear();

      const self = this;
      // 發送AJAX請求來獲取最新的ORDER_ID數據
      fetch('fetch_order_ids.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({ orderIds: visibleIds, year: selectedYear }) // <-- Send year to backend
      })
      .then(response => {
        if (!response.ok) {
          throw new Error('網絡請求失敗，狀態碼：' + response.status);
        }
        return response.json();
      })
      .then(data => {
        if (data.status === 'success') {
          // 清空console並顯示最新的ORDER_ID列表
          console.clear();
          console.log("=========== 自動更新ORDER_ID列表 ===========");
          console.log("更新時間: " + data.timestamp);
          
          // 如果有數據，顯示ORDER_ID
          if (data.data.length > 0) {
            data.data.forEach(order => {
              console.log("ORDER_ID: " + order.Order_id + " | 客戶: " + order.Client_name + " | 訂單日期: " + order.Order_date);
              updateOrderRow(order);  // 這是你要加的
            });
          } else {
            console.log("沒有找到匹配的ORDER_ID數據");
          }
          
          console.log("共 " + data.count + " 個訂單ID");
          console.log("下次更新時間: " + new Date(Date.now() + self.UPDATE_INTERVAL).toLocaleTimeString());
          console.log("=========================================");
        } else {
          console.error("獲取ORDER_ID數據失敗：", data.message);
        }
      })
      .catch(error => {
        console.error("AJAX請求失敗：", error);
      });
    } catch (error) {
      console.error("更新ORDER_ID時發生錯誤：", error);
    }
  }
};

// 當DOM載入完成後，自動啟動ORDER_ID更新
document.addEventListener("DOMContentLoaded", function() {
  // 確保頁面上有logVisibleOrderIds函數
  if (typeof logVisibleOrderIds === "function") {
    // 延遲2秒啟動，確保頁面已完全加載
    setTimeout(function() {
      window.orderIdLogger.startOrderIdAutoUpdate();
    }, 2000);
  } else {
    console.error("找不到logVisibleOrderIds函數，無法啟動自動更新");
  }
}



);

function updateOrderRow(order) {
  const row = document.querySelector(`tr[data-order-id="${order.Order_id}"]`);
  if (!row) return;

  const setText = (name, value) => {
    const cell = row.querySelector(`td[name="${name}"]`);
    if (cell) cell.textContent = value;
  };

  const setTextarea = (name, value) => {
    const textarea = row.querySelector(`textarea[name="${name}"]`);
    if (textarea) {
      textarea.value = value;
      textarea.setAttribute("data-orig", value);
    }
  };

  setText("Order_date", formatToShortYear(order.Order_date)); // 接單
  setText("Delivery_date_T", order.Delivery_date_T);   // 交期
  setText("Client_name", order.Client_name);           // 客戶
  // setText("d_id", order.d_id);                      // 料號 - 改為下方自訂innerHTML
  setText("Processing_items", order.Processing_items); // 製程
  setText("Qty", order.Qty);                           // 數量

  setTextarea("Order_ps", order.Order_ps);             // 業務備註

  // 設計/日期
  const ateText = order.user_cname?.slice(-2) ?? "";
  const ateDate = order.ateGet ? formatMonthDay(order.ateGet) : "";
  const monthlyCount = order.monthly_count ?? 0; // <-- Get the new count
  setText("ate", `${ateText} ${ateDate} x${monthlyCount}張`); // <-- Reconstruct the full string

  setTextarea("ateNote", order.ateNote);               // 設計備註

  // 轉生管日
  const pmCell = row.querySelector('td[name="pmGet"]');
  if (pmCell) {
    if (order.pmGet) {
      pmCell.innerHTML = `
        <button type="button" class="btn btn-xs btn-danger" onclick="cancelPmGet('${order.Order_id}')">取消</button>
        ${formatMonthDay(order.pmGet)}
      `;
    } else {
      pmCell.innerHTML = `
        <button type="button" class="btn btn-warning btn-xs" onclick="updatePmGet('${order.Order_id}')">轉生管</button>
      `;
    }
  }

  // setText("Order_oo", order.Order_oo);              // 訂單編號 - 改為下方自訂innerHTML
  setText("Containers", order.Containers);             // 容器

  // 樣品 / 治具
  const sampleCell = row.querySelector('td[name="Sample"]');
  if (sampleCell) {
    if (order.Sample && order.JIG) {
      sampleCell.textContent = `${order.Sample} / ${order.JIG}`;
    } else if (order.Sample) {
      sampleCell.textContent = `${order.Sample}`;
    } else if (order.JIG) {
      sampleCell.textContent = `/ ${order.JIG}`;
    } else {
      sampleCell.textContent = '';
    }
  }

  setText("drop_zone", order.drop_zone);               // 下料區域
  setText("C_order", order.C_order);                   // 客戶單號
  // 料號 (d_id) - 包含複製按鈕
  const dIdCell = row.querySelector('td[name="d_id"]');
  if (dIdCell) {
    const d_id_val = order.d_id || "";
    const d_id_escaped = escapeHtml(d_id_val);
    dIdCell.innerHTML = `
      <i class="fa fa-copy copy-btn" title="複製料號" onclick="copyToClipboardWithFeedback('${d_id_escaped}', this)"></i>
      ${d_id_val}
    `;
  }

  // 訂單編號 (Order_oo) - 包含複製按鈕
  const orderOoCell = row.querySelector('td[name="Order_oo"]');
  if (orderOoCell) {
    const order_oo_val = order.Order_oo || "";
    const order_oo_escaped = escapeHtml(order_oo_val);
    orderOoCell.innerHTML = `
      <i class="fa fa-copy copy-btn" title="複製訂單編號" onclick="copyToClipboardWithFeedback('${order_oo_escaped}', this)"></i>
      ${order_oo_val}
    `;
  }
}
