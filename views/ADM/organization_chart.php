<?php
session_start();
include_once '../../src/common/_config.php';
include ("../../src/common/DBConnection.php");
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>組織圖</title>
    <!-- Bootstrap -->
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <!-- NProgress -->
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <!-- Custom Theme Style -->
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <!-- Google Charts Loader -->
    <script type="text/javascript" src="https://www.gstatic.com/charts/loader.js"></script>
    <!-- jsPDF and html2canvas for PDF export -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>

    <style>
        /* 移除舊的卡片樣式，替換為 Google Chart 節點樣式 */
        .org-chart-container {
            width: 100%;
            min-height: 600px; /* 給予一個最小高度 */
            overflow: auto; /* 當組織圖太寬時，允許捲動 */
        }

        /* Google Chart 節點樣式 */
        .google-visualization-orgchart-node {
            border: 2px solid #7cb5ec;
            background: #f8f9fa;
            border-radius: 8px;
            padding: 5px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            vertical-align: top !important; /* 強制節點內容垂直靠上對齊 */
            height: 100%; /* 確保節點填滿單元格高度，實現視覺對齊 */
        }

        /* 強制 Google Chart TD 內的 DIV 內容靠上對齊 */
        .google-visualization-orgchart-node > div {
            display: flex;
            flex-direction: column;
            align-items: center; /* 水平置中 */
            justify-content: center; /* 垂直置中 */
            height: 100%; /* 確保內容容器也填滿 */
        }

        /* 部門節點的特定樣式 */
        .google-visualization-orgchart-node-department {
            background: #e7f4ff;
            border-color: #007bff;
        }

        .node-content {
            padding: 8px;
            text-align: center;
            vertical-align: top; /* 確保內容頂端對齊 */
            min-height: 60px; /* 設定一個最小高度，確保單人與多人節點高度協調 */
            width: 100%; /* 確保內容填滿節點寬度 */
        }

        .node-name {
            font-size: 16px;
            font-weight: bold;
            white-space: nowrap; /* 避免部門名稱換行 */
            color: #333;
        }
        /* 單人職務的樣式 (e.g., 課長：林鴻銘) */
        .single-employee-node {
            display: flex; /* 使用 flexbox 排版 */
            flex-direction: column; /* 改為垂直排列 */
            width: 100%;
        }

        /* 職務群組標題 (例如：課長、組長) */
        .node-position-group-title {
            font-size: 15px;
            font-weight: bold;
            color: #0056b3;
            margin-bottom: 5px;
        }

        /* 員工姓名網格容器 */
        .employee-grid {
            display: grid; /* 使用 CSS Grid */
            grid-template-columns: repeat(3, auto); /* 建立三欄，寬度自動 */
            justify-content: center; /* 水平置中網格內容 */
            gap: 4px 15px; /* 上下間距4px, 左右間距15px */
            width: 100%; /* 確保網格容器佔滿可用寬度 */
            padding: 0 10px; /* 增加左右內距，避免文字太貼邊 */
        }
        /* 新增：摘要資訊節點的樣式 */
        .summary-node-content {
            padding: 10px;
            text-align: left;
            font-size: 16px; /* 加大字體 */
            line-height: 1.6;
            width: 100%;
        }
        .summary-node-content ul {
            list-style-type: none;
            padding-left: 0;
            margin-bottom: 0;
            max-height: 100px; overflow-y: auto;
        }

        .node-position {
            font-size: 13px;
            color: #555;
        }

        .concurrent-role {
            font-size: 0.8em;
            font-weight: bold;
            color: #fd7e14; /* 橘色以突顯 */
        }

        /* 員工姓名樣式 */
        .employee-name-in-grid {
            font-size: 14px;
            color: #333;
            white-space: nowrap; /* 避免姓名換行 */
            padding: 2px 0; /* 增加一點垂直內距 */
        }
    </style>
    <!-- 新增：摘要資訊浮動框的樣式 -->
    <style>
        #summary-overlay {
            position: absolute;
            top: 10px;
            left: 10px;
            z-index: 10; /* 確保在圖表上層 */
            background-color: rgba(255, 255, 255, 0.9);
            border: 1px solid #ccc;
            border-radius: 8px;
            padding: 10px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
            min-width: 300px; /* 拉寬格子 */
        }
    </style>
</head>
<body class="nav-sm">
<div class="container body">
    <div class="main_container">
        <!-- side and top bar include -->
        <?php include '../partPage/sideAndTopBarMenu.html' ?>

        <!-- page content -->
        <div class="right_col" role="main">
            <div class="">
                <div class="page-title">
                    <div class="title_left">
                        <h3>公司組織圖</h3>
                    </div>
                </div>

                <div class="clearfix"></div>

                <div class="row">
                    <div class="col-md-12 col-sm-12 ">
                        <div class="x_panel">
                            <div class="x_title">
                                <h2 style="font-size: 30px; font-weight: bold;">超正齒輪-組織圖</h2>
                                <div class="navbar-right" style="margin-right: 10px;">
                                    <select id="display-mode-select" class="form-control" style="display: inline-block; width: auto;">
                                        <option value="occupied" data-html2canvas-ignore="true">僅顯示在職</option>
                                        <option value="all" data-html2canvas-ignore="true">顯示所有職位</option>
                                    </select>
                                    <!-- 新增列印與PDF按鈕 -->
                                    <!-- <button id="print-btn" class="btn btn-default" style="margin-left: 10px;" data-html2canvas-ignore="true">
                                        <i class="fa fa-print"></i> 列印圖表
                                    </button> -->
                                    <button id="pdf-btn" class="btn btn-default" data-html2canvas-ignore="true">
                                        <i class="fa fa-file-pdf-o"></i> 另存為 PDF
                                    </button>
                                </div>
                                <ul class="nav navbar-right panel_toolbox">
                                    <li><a class="collapse-link"><i class="fa fa-chevron-up"></i></a></li>
                                    <li><a class="close-link"><i class="fa fa-close"></i></a></li>
                                </ul>
                                <div class="clearfix"></div>
                            </div>
                            <div class="x_content" style="position: relative;">
                                <!-- 新增：用於顯示摘要資訊的浮動容器 (移到 org-chart-container 外面) -->
                                <div id="summary-overlay"></div>
                                <!-- 組織圖將會動態生成於此 -->
                            <div class="x_content">
                                <div id="org-chart-container" class="org-chart-container">
                                    <!-- 新增：用於顯示摘要資訊的浮動容器 -->
                                    <div id="summary-overlay">
                                        <!-- 摘要內容將由 JavaScript 動態填入 -->
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- /page content -->

        <!-- footer content -->
        <?php include '../partPage/footer.html' ?>
        <!-- /footer content -->
    </div>
</div>
<!-- jQuery -->
<script src="../../resource/js/jquery.min.js"></script>
<!-- Bootstrap -->
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/bootstrap.bundle.min.js"></script>
<!-- NProgress -->
<script src="../../resource/js/nprogress.js"></script>
<!-- Custom Theme Scripts (如果 custom.js 有其他功能，請保留) -->
<script src="../../resource/js/custom.min.js"></script>
<script>
    // 將 chart 和 dataTable 宣告在外面，方便其他函式存取
    let chart;
    let dataTable;
    $(document).ready(function() {
        // 載入 Google Chart，並在完成後呼叫 fetchOrganizationData
        google.charts.load('current', {packages:['orgchart']});
        google.charts.setOnLoadCallback(fetchOrganizationData);

        // 為下拉選單綁定事件，當選項改變時重新繪製圖表
        $('#display-mode-select').on('change', function() {
            fetchOrganizationData();
        });

        // 綁定列印按鈕事件
        $('#print-btn').on('click', function() {
            if (!chart) {
                alert('圖表尚未生成，請稍候');
                return;
            }
            // 獲取圖表標題和摘要資訊
            const title = $('.x_title h2').text();
            const summaryHtml = $('#summary-overlay').html();
            // 獲取圖表的圖片 URI
            const chartImgUri = chart.getImageURI();

            // 建立一個用於列印的新視窗
            const printWindow = window.open('', '_blank');
            printWindow.document.write(`
                <html>
                    <head><title>列印 - ${title}</title>
                    <style>
                        body { font-family: sans-serif; }
                        .print-container { text-align: center; width: 100%; page-break-inside: avoid; }
                        h2 { text-align: left; }
                        img { max-width: 100%; height: auto; }
                        #summary-overlay { background-color: #f0f0f0; border: 1px solid #ccc; padding: 10px; margin-bottom: 20px; text-align: left; display: inline-block; }
                        #summary-overlay ul { list-style-type: none; padding-left: 0; margin-bottom: 0; }
                    </style>
                    </head>
                    <body style="margin:0;">
                        <div class="print-container">
                            <h2>${title}</h2>
                            <div id="summary-overlay" style="display: block !important;">${summaryHtml}</div>
                            <img id="chart-img" src="${chartImgUri}" />
                        </div>
                        <script>
                            const img = document.getElementById('chart-img');
                            // 確保圖片載入完成後才觸發列印
                            img.onload = function() { window.print(); };
                            if (img.complete) { img.onload(); } // 如果圖片已在快取中，手動觸發
                        <\/script>
                    </body>
                </html>
            `);
            printWindow.document.close();
        });

        // 綁定另存為 PDF 按鈕事件
        $('#pdf-btn').on('click', async function() {
            const { jsPDF } = window.jspdf;
            const xPanel = document.querySelector('.x_panel');

            // 1. 隱藏按鈕區（保留佔位，避免版面跑位）
            const hideEls = xPanel.querySelectorAll('.panel_toolbox, .navbar-right');
            hideEls.forEach(el => el.style.visibility = 'hidden');

            // 2. 展開所有會截斷內容的容器（從 org-chart-container 內部一路到 body）
            const restored = [];
            const saveAndExpand = el => {
                if (!el || el === document.body) return;
                restored.push({
                    el,
                    overflow:  el.style.overflow,
                    overflowX: el.style.overflowX,
                    overflowY: el.style.overflowY,
                    width:     el.style.width,
                    height:    el.style.height,
                    maxWidth:  el.style.maxWidth,
                });
                el.style.overflow  = 'visible';
                el.style.overflowX = 'visible';
                el.style.overflowY = 'visible';
                el.style.maxWidth  = 'none';
                el.style.width     = el.scrollWidth + 'px';
                el.style.height    = el.scrollHeight + 'px';
            };

            // 只展開 org-chart-container 本身及其祖先容器，不動圖表內部元素
            let ancestor = document.getElementById('org-chart-container');
            while (ancestor && ancestor !== document.body) {
                saveAndExpand(ancestor);
                ancestor = ancestor.parentElement;
            }

            // 3. 等待瀏覽器 reflow（雙 rAF 確保 layout 完成）
            await new Promise(r => requestAnimationFrame(() => requestAnimationFrame(r)));

            // 以圖表內 table 的實際寬度作為擷取基準
            const chartTable = document.querySelector('#org-chart-container table');
            const captureW = chartTable
                ? Math.max(xPanel.scrollWidth, chartTable.scrollWidth + 40)
                : xPanel.scrollWidth;
            const captureH = xPanel.scrollHeight;

            try {
                const canvas = await html2canvas(xPanel, {
                    scale: 1.5,
                    useCORS: true,
                    logging: false,
                    width: captureW,
                    height: captureH,
                    scrollX: 0,
                    scrollY: -window.scrollY,
                });

                const imgData = canvas.toDataURL('image/png');
                const pdf = new jsPDF({ orientation: 'landscape', unit: 'mm', format: 'a4' });
                const pdfW = pdf.internal.pageSize.getWidth();
                const pdfH = pdf.internal.pageSize.getHeight();
                const sc = Math.min(pdfW / canvas.width, pdfH / canvas.height);
                const iw = canvas.width * sc;
                const ih = canvas.height * sc;
                pdf.addImage(imgData, 'PNG', (pdfW - iw) / 2, (pdfH - ih) / 2, iw, ih);
                pdf.save('超正齒輪-組織圖.pdf');
            } finally {
                // 4. 無論成功或失敗都還原 DOM
                restored.forEach(({ el, overflow, overflowX, overflowY, width, height }) => {
                    el.style.overflow = overflow;
                    el.style.overflowX = overflowX;
                    el.style.overflowY = overflowY;
                    el.style.width = width;
                    el.style.height = height;
                });
                hideEls.forEach(el => el.style.visibility = '');
            }
        });
    });

    function fetchOrganizationData() {
        $('#org-chart-container').html('<p>正在載入組織圖資料...</p>');
        $.ajax({
            url: '../../src/store/_employee_api.php',
            type: 'GET',
            data: { action: 'get_organization_data' },
            dataType: 'json',
            success: function(response) {
                if (response.status === 'success' && response.data) {
                    drawChart(response.data);
                } else {
                    $('#org-chart-container').html('<div class="alert alert-danger">載入組織圖資料失敗: ' + response.message + '</div>');
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                console.error("AJAX Error:", textStatus, errorThrown);
                $('#org-chart-container').html('<div class="alert alert-danger">請求組織圖資料時發生嚴重錯誤。</div>');
            }
        });
    }

    function drawChart(data) {
        // 先清空圖表容器，以防舊圖表殘留
        $('#org-chart-container').empty();

        const displayMode = $('#display-mode-select').val(); // 獲取當前的顯示模式: 'all' 或 'occupied'

        dataTable = new google.visualization.DataTable();
        dataTable.addColumn('string', 'Node ID');
        dataTable.addColumn('string', 'Parent Node ID');
        dataTable.addColumn('string', 'Tooltip');

        // --- 計算摘要資訊 ---
        let totalCount = 0;
        let maleCount = 0;
        let femaleCount = 0;
        const newHires = [];
        const today = new Date();
        const threeMonthsAgo = new Date();
        threeMonthsAgo.setMonth(today.getMonth() - 3);

        // 使用 Set 確保不重複計算員工
        const uniqueEmployees = new Map();
        data.forEach(dept => {
            if (dept.employees) {
                dept.employees.forEach(emp => {
                    if (!uniqueEmployees.has(emp.user_id)) {
                        uniqueEmployees.set(emp.user_id, emp);
                    }
                });
            }
        });

        uniqueEmployees.forEach(emp => {
            totalCount++;
            if (emp.gender === 'M') maleCount++;
            if (emp.gender === 'F') femaleCount++;

            if (emp.hire_date) {
                const hireDate = new Date(emp.hire_date);
                if (hireDate >= threeMonthsAgo && hireDate <= today) {
                    newHires.push({ name: emp.user_cname, date: emp.hire_date });
                }
            }
        });

        // --- 建立摘要資訊節點的 HTML ---
        let newHiresHtml = '';
        if (newHires.length > 0) {
            newHires.sort((a, b) => new Date(b.date) - new Date(a.date)); // 按到職日降序排列
            newHires.forEach(hire => {
                newHiresHtml += `<li>${hire.name} <span class="text-muted pull-right">${hire.date}</span></li>`;
            });
        } else {
            newHiresHtml = '<li>無</li>';
        }

        const summaryNodeHtml = `
            <div class="summary-node-content">
                <p style="margin:0;"><strong>總人數：</strong>${totalCount} 人 (男 ${maleCount} / 女 ${femaleCount})</p>
                <p style="margin:0;"><strong>生效日期：</strong>${today.toISOString().split('T')[0]}</p>
                <p style="margin:0; font-weight:bold;">到職未滿三個月人員：</p>
                <ul>${newHiresHtml}</ul>
            </div>
        `;

        // 將摘要 HTML 填入浮動框中
        $('#summary-overlay').html(summaryNodeHtml);

        // 處理資料，轉換成 Google Chart 格式
        const rows = [];
        const processedEmployees = new Set(); // 用於追蹤已處理的員工主職務
        const lastEmployeeNodeMap = new Map(); // 用於儲存每個部門最後一個員工節點的 ID

        // 1. 處理部門節點
        // 建立部門層級樹，並計算各部門主職人數
        const departmentMap = new Map(data.map(dept => [dept.id, { 
            ...dept, 
            children: [],
            mainEmployeeCount: dept.employees.filter(emp => emp.is_main == 1).length // 在建立 Map 時就計算好
        }]));
        const departmentTree = [];
        data.forEach(dept => {
            if (dept.parent_id && departmentMap.has(dept.parent_id)) {
                departmentMap.get(dept.parent_id).children.push(departmentMap.get(dept.id));
            } else {
                departmentTree.push(departmentMap.get(dept.id));
            }
        });

        // 遞迴計算包含子部門的總主職人數
        function calculateTotalMainCount(deptNode) {
            let total = deptNode.mainEmployeeCount;
            for (const child of deptNode.children) {
                total += calculateTotalMainCount(child);
            }
            deptNode.totalMainEmployeeCount = total;
            return total;
        }
        departmentTree.forEach(calculateTotalMainCount);

        // 遍歷部門資料，生成節點
        data.forEach(dept => {
            let departmentNodeHtml;
            // 根據部門層級決定是否顯示人數
            if (dept.level == 3) {
                const count = departmentMap.get(dept.id).totalMainEmployeeCount || 0;
                departmentNodeHtml = `<div class="node-content node-name">${dept.name} (${count}人)</div>`;
            } else {
                departmentNodeHtml = `<div class="node-content node-name">${dept.name}</div>`;
            }

            // 決定部門節點本身應該掛在哪裡
            const parentDept = data.find(d => d.id === dept.parent_id);
            let parentNodeId = '';
            if (dept.parent_id) {
                // 如果父部門有員工，則掛載在父部門的最後一個員工節點下
                // 否則，直接掛載在父部門節點下
                parentNodeId = lastEmployeeNodeMap.has(dept.parent_id)
                    ? lastEmployeeNodeMap.get(dept.parent_id) // 掛在父部門的最後一個員工節點
                    : 'dept_' + dept.parent_id;
            }
            rows.push([{ v: 'dept_' + dept.id, f: departmentNodeHtml }, parentNodeId, dept.name]);

            // 2. 處理部門內的員工與職稱
            // 修改邏輯：即使沒有員工，只要有職稱定義(all_positions)，也要顯示
            if (dept.all_positions && dept.all_positions.length > 0) {
                // 先將現有員工按職稱分組
                const employeesByPosition = groupEmployeesByPosition(dept.employees);

                // 建立一個包含所有應有職稱的 Map，並填入員工資料
                // 根據顯示模式決定要處理哪些職稱
                const allPositionsMap = new Map();
                dept.all_positions.forEach(pos => {
                    const employeesInPos = employeesByPosition[pos.position_name] || [];
                    // 如果是 'all' 模式，或 'occupied' 模式且該職位有員工，才加入 Map
                    if (displayMode === 'all' || (displayMode === 'occupied' && employeesInPos.length > 0)) {
                        allPositionsMap.set(pos.position_name, {
                            sort_order: pos.position_sort_order || 999,
                            employees: employeesInPos
                        });
                    }
                });
 
                let lastNodeId = 'dept_' + dept.id;
                // 根據職稱排序號碼對職位進行排序
                const sortedPositions = Array.from(allPositionsMap.keys()).sort((a, b) => {
                    const sortOrderA = allPositionsMap.get(a).sort_order;
                    const sortOrderB = allPositionsMap.get(b).sort_order;
                    return sortOrderA - sortOrderB;
                });
 
                // 遍歷排序後的職位來生成節點
                sortedPositions.forEach(positionName => {
                    const positionData = allPositionsMap.get(positionName);
                    const employees = positionData.employees;
                    const positionNodeId = `pos_${dept.id}_${positionName.replace(/\s/g, '')}`;
 
                    const nodeHtml = createEmployeeNodeHtml(dept, positionName, employees);
 
                    rows.push([
                        { v: positionNodeId, f: nodeHtml },
                        lastNodeId, // 將當前職位節點掛載到前一個節點下方
                        `${positionName} (${employees.length > 0 ? employees.length + '人' : '無人'})`
                    ]);
 
                    // 更新 lastNodeId，讓下一個職位掛載到當前職位下方，確保垂直階層
                    lastNodeId = positionNodeId;
                });
 
                // 將該部門的最後一個員工節點 ID 存起來，給子部門掛載用
                lastEmployeeNodeMap.set(dept.id, lastNodeId);
            }
        });

        dataTable.addRows(rows);

        chart = new google.visualization.OrgChart(document.getElementById('org-chart-container'));

        // allowHtml: true 讓我們可以使用自訂的 HTML 節點
        // nodeClass 和 selectedNodeClass 用於 CSS 美化
        chart.draw(dataTable, {
            allowHtml: true,
            nodeClass: 'google-visualization-orgchart-node',
            selectedNodeClass: 'google-visualization-orgchart-node-selected',
            allowCollapse: true, // 允許摺疊
            compactRows: false // 不使用緊湊模式，允許節點高度自適應
        });
    }

    // 輔助函式：建立員工節點的 HTML 內容
    function createEmployeeNodeHtml(dept, positionName, employees) {
        let nodeHtml;
        if (!employees || employees.length === 0) {
            // 無人擔任的職稱節點
            nodeHtml = `<div class="node-content single-employee-node">
                            <div class="node-position-group-title">${positionName}</div>
                            <div class="employee-name-in-grid" style="color: #999;">(目前無人)</div>
                        </div>`;
        } else if (employees.length === 1 || dept.level <= 2) {
            // 單人職務或頂層主管
            const emp = employees[0];
            const concurrentIndicator = emp.is_main != 1 ? ' <span class="concurrent-role">(兼任)</span>' : '';
            nodeHtml = `<div class="node-content single-employee-node">
                            <div class="node-position-group-title">${positionName}</div>
                            <div class="employee-name-in-grid">${emp.user_cname}${concurrentIndicator}</div>
                        </div>`;
        } else { // 多人職務，使用網格樣式
            let employeeGridHtml = '<div class="employee-grid">';
            employees.forEach(emp => {
                const concurrentIndicator = emp.is_main != 1 ? ' <span class="concurrent-role">(兼任)</span>' : '';
                employeeGridHtml += `<div class="employee-name-in-grid">${emp.user_cname}${concurrentIndicator}</div>`;
            });
            employeeGridHtml += '</div>';

            nodeHtml = `<div class="node-content">
                            <div class="node-position-group-title">${positionName}</div>
                            ${employeeGridHtml}
                        </div>`;
        }
        return nodeHtml;
    }

    // 輔助函式：將員工按職稱分組
    function groupEmployeesByPosition(employees) {
        return employees.reduce((acc, emp) => {
            const position = emp.position_name || '未指定職稱';
            if (!acc[position]) {
                acc[position] = [];
            }
            acc[position].push(emp);
            return acc;
        }, {});
    }

    // 輔助函式：將指定的 HTML 元素匯出為 PDF
    function exportElementAsPDF(element, filename, ignoreSelector) {
        const { jsPDF } = window.jspdf;

        // 準備 html2canvas 的選項
        const options = {
            scale: 2, // 提高解析度
            useCORS: true,
            logging: false,
            onclone: (doc) => {
                // 展開所有有捲動的容器，確保 html2canvas 能擷取完整內容
                doc.querySelectorAll('*').forEach(el => {
                    const style = window.getComputedStyle(el);
                    if (style.overflow === 'auto' || style.overflow === 'scroll' ||
                        style.overflowX === 'auto' || style.overflowX === 'scroll') {
                        el.style.overflow = 'visible';
                        el.style.overflowX = 'visible';
                        el.style.overflowY = 'visible';
                        el.style.width = el.scrollWidth + 'px';
                        el.style.height = el.scrollHeight + 'px';
                    }
                });

                // 在複製的 DOM 中，確保摘要資訊是可見的
                const clonedOverlay = doc.getElementById('summary-overlay');
                if (clonedOverlay) {
                    clonedOverlay.style.display = 'block';
                }
                // 在複製的 DOM 中，移除 x_panel 的邊框和 x_title 的底線
                const clonedPanel = doc.querySelector('.x_panel');
                if (clonedPanel) {
                    clonedPanel.style.border = 'none';
                    clonedPanel.style.width = clonedPanel.scrollWidth + 'px';
                }
                const clonedTitle = doc.querySelector('.x_title');
                if (clonedTitle) {
                    clonedTitle.style.borderBottom = 'none';
                }

                // 移除所有要忽略的元素
                if (ignoreSelector) {
                    doc.querySelectorAll(ignoreSelector).forEach(el => el.remove());
                }
            },
            width: element.scrollWidth,
            height: element.scrollHeight,
            windowWidth: element.scrollWidth,
            windowHeight: element.scrollHeight
        };

        html2canvas(element, {
            ...options,
        }).then(canvas => {
            const imgData = canvas.toDataURL('image/png');
            const pdf = new jsPDF({ orientation: 'landscape', unit: 'mm', format: 'a4' });

            const pdfWidth = pdf.internal.pageSize.getWidth();
            const pdfHeight = pdf.internal.pageSize.getHeight();

            // 同時比較寬高縮放比，取較小值確保整張圖縮進一頁
            const scale = Math.min(pdfWidth / canvas.width, pdfHeight / canvas.height);
            const imgWidth = canvas.width * scale;
            const imgHeight = canvas.height * scale;
            const x = (pdfWidth - imgWidth) / 2;
            const y = (pdfHeight - imgHeight) / 2;

            pdf.addImage(imgData, 'PNG', x, y, imgWidth, imgHeight);
            pdf.save(filename);
        });
    }
    </script>

</body>
</html>