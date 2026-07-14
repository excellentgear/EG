  // ------------------------------
  // ???湔銝??詨?批捆
  // - 摰Ｘ銝??詨 (select id="customer-filter")嚗?蝭拚敺?鞈??葉摰Ｘ甈? (index 3) ??
  // - 閮剛?銝??詨 (select id="vendor-filter")嚗?蝭拚敺?鞈??葉閮剛?/?交?甈? (index 8) ??嚗?? 2 ??
  function updateDropdowns() {
    var customerSet = new Set();
    var vendorSet = new Set();
    
    // 雿輻蝭拚敺?鞈???(filteredRows) 靘?唬????
    if (filteredRows && filteredRows.length > 0) {
      // 敺祟?詨????????賊?
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
      // 憒?瘝?蝭拚?祟?詨?瘝?蝯?嚗?敺??????賊?
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
    
    // ?湔摰Ｘ銝??詨嚗???摮???
    var custSelect = document.getElementById("customer-filter");
    if (custSelect) {
      var currentCustomer = custSelect.value;
      custSelect.innerHTML = "<option value=''>?券摰Ｘ</option>";
      Array.from(customerSet).sort().forEach(function(cust) {
        var opt = document.createElement("option");
        opt.value = cust.toLowerCase();
        opt.textContent = cust;
        custSelect.appendChild(opt);
      });
      
      // 憒?銋??豢??潔??嗅??冽?圈?葉嚗?靽??豢?
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
    
    // ?湔閮剛?銝??詨嚗???摮???
    var vendSelect = document.getElementById("vendor-filter");
    if (vendSelect) {
      var currentVendor = vendSelect.value;
      vendSelect.innerHTML = "<option value=''>?券閮剛?</option>";
      Array.from(vendorSet).sort().forEach(function(vend) {
        var opt = document.createElement("option");
        opt.value = vend.toLowerCase();
        opt.textContent = vend;
        vendSelect.appendChild(opt);
      });
      
      // 憒?銋??豢??潔??嗅??冽?圈?葉嚗?靽??豢?
      if (currentVendor) {
        var foundInOptions = false;
        for (var i = 0; i < vendSelect.options.length; i++) {
          if (vendSelect.options[i].value === currentVendor) {
            foundInOptions = true;
            break;
          }
        }
        if (foundInOptions) {
          vendSelect.value = currentVendor;
        }
      }
    }
  } 
