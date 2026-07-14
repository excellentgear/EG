<?php
session_start();
include_once '../../src/common/_config.php';
include ("../../src/common/DBConnection.php");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>代理人狀態查詢 | Excellentgear</title>

    <!-- 引用與原頁面相同的 CSS -->
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
</head>

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
                        <h3>代理人狀態查詢</h3>
                    </div>
                </div>

                <div class="clearfix"></div>

                <div class="row">
                    <!-- 1. 使用者代理設定區塊 -->
                    <div class="col-md-12 col-sm-12 col-xs-12">
                        <div class="x_panel">
                            <div class="x_title">
                                <h2>使用者代理狀態</h2>
                                <ul class="nav navbar-right panel_toolbox">
                                    <li><a class="collapse-link"><i class="fa fa-chevron-up"></i></a></li>
                                </ul>
                                <div class="clearfix"></div>
                            </div>
                            <div class="x_content">
                                <table class="table table-striped table-hover">
                                    <thead>
                                        <tr>
                                            <th>被代理人</th>
                                            <th>代理人 (依順序)</th>
                                            <th>開始日期</th>
                                            <th>結束日期</th>
                                        </tr>
                                    </thead>
                                    <tbody id="user-delegate-table-body">
                                        <!-- JS 動態載入 -->
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- 2. 職稱代理設定區塊 -->
                    <div class="col-md-12 col-sm-12 col-xs-12">
                        <div class="x_panel">
                            <div class="x_title">
                                <h2>職稱代理規則</h2>
                                <ul class="nav navbar-right panel_toolbox">
                                    <li><a class="collapse-link"><i class="fa fa-chevron-up"></i></a></li>
                                </ul>
                                <div class="clearfix"></div>
                            </div>
                            <div class="x_content">
                                <table class="table table-striped table-hover">
                                    <thead>
                                        <tr>
                                            <th style="width: 25%;">主職稱</th>
                                            <th>代理職稱 (依順序)</th>
                                        </tr>
                                    </thead>
                                    <tbody id="position-delegate-table-body">
                                        <!-- JS 動態載入 -->
                                    </tbody>
                                </table>
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

<!-- 引用與原頁面相同的 JS -->
<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/fastclick.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>

<script>
    $(document).ready(function() {
        const DEPT_JOB_API_URL = '../../src/store/_department_job_title_api.php';
        const USER_API_URL = '../../src/store/_employee_api.php';

        // --- 通用功能 ---
        function escapeHtml(text) {
            if (text === null || typeof text === 'undefined') return '';
            return String(text).replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m]));
        }

        function callApi(url, action, method, data, successCallback) {
            $.ajax({
                url: `${url}?action=${action}`,
                type: method,
                data: data,
                dataType: 'json',
                success: successCallback,
                error: function(jqXHR, textStatus, errorThrown) {
                    console.error(`API Error (${action}):`, { status: jqXHR.status, responseText: jqXHR.responseText });
                    alert(`讀取資料時發生錯誤，請稍後再試。`);
                }
            });
        }

        let allUsers = []; // 用於快取所有使用者資料

        // --- 使用者代理 ---
        function loadUsersAndDelegates() {
            callApi(USER_API_URL, 'get_employees', 'GET', null, function(response) {
                if (response.status === 'success') {
                    allUsers = response.data;
                    loadUserDelegates(); // 使用者資料載入後，再載入代理設定
                } else {
                    alert('讀取使用者資料失敗: ' + response.message);
                }
            });
        }

        function loadUserDelegates() {
            if (allUsers.length === 0) return;

            const formatUserInfo = (u) => {
                if (!u || !u.user_cname) return `(ID: ${u.id})`;
                let info = `<b>${escapeHtml(u.user_cname)}</b><br><small style="color: #007bff;">[主] ${escapeHtml(u.main_department_name || '-')} / ${escapeHtml(u.main_position_name || '-')}</small>`;
                if (u.concurrent_positions) {
                    info += `<br><small style="color: #008000;">[兼] ${u.concurrent_positions.split('; ').map(escapeHtml).join('<br>[兼] ')}</small>`;
                }
                return info;
            };

            callApi(DEPT_JOB_API_URL, 'get_user_delegates', 'GET', null, function(response) {
                if (response.status === 'success') {
                    const tableBody = $('#user-delegate-table-body');
                    tableBody.empty();
                    
                    const grouped = response.data.reduce((acc, item) => {
                        const key = `${item.user_id}|${item.start_date}|${item.end_date}`;
                        if (!acc[key]) {
                            const user = allUsers.find(u => u.id == item.user_id);
                            if (!user || parseInt(user.state) === 0) return acc; // 跳過已離職的被代理人
                            acc[key] = { user, startDate: item.start_date, endDate: item.end_date, delegates: [] };
                        }
                        const delegate = allUsers.find(u => u.id == item.delegate_id);
                        if (delegate && parseInt(delegate.state) !== 0) { // 只加入在職的代理人
                             acc[key].delegates.push({ ...delegate, priority: item.priority });
                        }
                        return acc;
                    }, {});

                    for (const key in grouped) {
                        const rule = grouped[key];
                        const delegatesHtml = rule.delegates
                            .sort((a, b) => a.priority - b.priority)
                            .map(d => `<span class="badge" style="background-color: #f0f0f0; color: #333; border: 1px solid #ddd; margin: 2px; font-size: 13px; display: inline-block; text-align: left; white-space: normal; padding: 8px;"><b>${d.priority}. ${escapeHtml(d.user_cname)}</b> <small style="color: #007bff;">[主] ${escapeHtml(d.main_department_name || '-')}/${escapeHtml(d.main_position_name || '-')}</small></span>`)
                            .join(' ');

                        const row = `<tr style="vertical-align: top;">
                            <td>${formatUserInfo(rule.user)}</td>
                            <td style="min-width: 250px; line-height: 1.8;">${delegatesHtml}</td>
                            <td>${escapeHtml(rule.startDate.split(' ')[0])}</td>
                            <td>${escapeHtml(rule.endDate.split(' ')[0])}</td>
                        </tr>`;
                        tableBody.append(row);
                    }
                } else {
                    alert('讀取代理設定失敗: ' + response.message);
                }
            });
        }

        // --- 職稱代理 ---
        function loadPositionDelegates() {
            callApi(DEPT_JOB_API_URL, 'get_position_delegates', 'GET', null, function(response) {
                if (response.status === 'success') {
                    const tableBody = $('#position-delegate-table-body');
                    tableBody.empty();
                    
                    const grouped = response.data.reduce((acc, item) => {
                        if (!acc[item.position_id]) {
                            acc[item.position_id] = { name: item.position_name, delegates: [] };
                        }
                        acc[item.position_id].delegates.push(item);
                        return acc;
                    }, {});

                    for (const mainPosId in grouped) {
                        const mainPos = grouped[mainPosId];
                        const delegatesHtml = mainPos.delegates
                            .map(d => `<span class="badge" style="background-color: #3498db; margin: 2px; font-size: 13px; display: inline-block;">${d.priority}. ${escapeHtml(d.delegate_position_name)}</span>`)
                            .join('');

                        const row = `<tr>
                            <td>${escapeHtml(mainPos.name)}</td>
                            <td style="line-height: 1.8;">${delegatesHtml}</td>
                        </tr>`;
                        tableBody.append(row);
                    }
                } else {
                    alert('讀取職稱代理規則失敗: ' + response.message);
                }
            });
        }

        // 初始載入
        loadUsersAndDelegates();
        loadPositionDelegates();
    });
</script>
</body>
</html>