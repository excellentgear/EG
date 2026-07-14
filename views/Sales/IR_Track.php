<?php
session_start();
if (!isset($_SESSION['userName'])) {
    header("Location:../../index.php");
    exit;
}
include '../../src/common/DBConnection.php';
include '../../src/store/_setting.php';
include '../../src/common/_config.php';
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>退貨追蹤 (IR Track)</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <link href="../../resource/css/dataTables.bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/buttons.bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/fixedHeader.bootstrap.css" rel="stylesheet">
    <style>
        :root {
            --primary-color: #2A3F54;
            --accent-color: #1ABB9C;
            --bg-color: #F4F7FC;
            --card-bg: #FFFFFF;
            --text-color: #495057;
            --border-color: #E6E9ED;
        }
        body { background-color: var(--bg-color); font-family: "Segoe UI","Roboto","Helvetica Neue",Arial,sans-serif; color: var(--text-color); }
        .right_col { background-color: var(--bg-color) !important; }
        .stats-container { display:flex; flex-wrap:wrap; gap:20px; margin-bottom:20px; padding-left:0; width:100%; }
        .stat-card { background:var(--card-bg); border-radius:8px; padding:15px 20px; box-shadow:0 2px 6px rgba(0,0,0,.08); transition:all .3s; cursor:pointer; border-left:5px solid transparent; position:relative; overflow:hidden; border:1px solid #f0f0f0; flex:1; min-width:200px; margin:0; display:flex; flex-direction:column; justify-content:center; }
        .stat-card:hover { transform:translateY(-3px); box-shadow:0 8px 15px rgba(0,0,0,.1); }
        .stat-card.active { background-color:#fff; box-shadow:0 0 0 2px var(--primary-color),0 4px 10px rgba(0,0,0,.1); z-index:1; }
        .stat-card.action-btn { flex:0 0 auto; width:160px; align-items:center; border-left:none; }
        .stat-card.card-all { border-left-color:#3498DB; }
        .stat-card.card-processing { border-left-color:#F39C12; }
        .stat-card.card-done { border-left-color:#1ABB9C; }
        .stat-icon { position:absolute; right:15px; top:15px; font-size:32px; opacity:.1; }
        .stat-value { font-size:24px; font-weight:800; margin-bottom:2px; color:var(--primary-color); }
        .stat-label { font-size:13px; color:#888; font-weight:600; text-transform:uppercase; letter-spacing:1px; }
        .main-card { background:var(--card-bg); border-radius:8px; box-shadow:0 2px 5px rgba(0,0,0,.05); padding:15px; border:none; }
        table.dataTable { border-collapse:collapse !important; width:100% !important; }
        table.dataTable thead th { background-color:#F8F9FA; color:#555; font-weight:700; border-bottom:2px solid #E9ECEF; padding:10px 5px; font-size:13px; white-space:nowrap; vertical-align:middle; }
        table.dataTable tbody td { padding:6px 5px; vertical-align:middle; border-bottom:1px solid #F1F3F5; font-size:13px; line-height:1.4; }
        table.dataTable tbody tr:hover { background-color:#FAFBFE !important; }
        .table-textarea { width:100%; min-width:150px; min-height:32px; resize:vertical; border:1px solid transparent; background:transparent; padding:4px; font-size:13px; border-radius:3px; transition:all .2s; }
        .table-textarea:hover { border-color:#ddd; background:#f9f9f9; }
        .table-textarea:focus { border-color:#3498DB; background:#fff; outline:none; box-shadow:0 0 0 2px rgba(52,152,219,.1); }
        .dept-tag { display:inline-block; padding:2px 6px; margin:2px; border-radius:4px; background:#f0f0f0; border:1px solid #ddd; font-size:11px; }
        .dept-tag.done { background:#dff0d8; border-color:#d6e9c6; color:#3c763d; }
        .dept-tag.pending { background:#fcf8e3; border-color:#faebcc; color:#8a6d3b; }
        .modal-header { background:var(--primary-color); color:white; border-radius:8px 8px 0 0; }
        .close { color:white; opacity:.8; }
        .close:hover { opacity:1; }
        #part-suggestions { position:absolute; z-index:2000; background:white; border:1px solid #ccc; width:90%; max-height:200px; overflow-y:auto; display:none; }
        .suggestion-item { padding:5px 10px; cursor:pointer; }
        .suggestion-item:hover { background-color:#eee; }
        #all_depts_container { max-height:none !important; overflow-y:visible !important; }
        #custom-toast { position:fixed; bottom:20px; right:20px; z-index:9999; min-width:250px; display:none; padding:15px; color:#fff; border-radius:4px; box-shadow:0 2px 10px rgba(0,0,0,.2); font-size:14px; }
        /* 異常單 5M+T 選項樣式 */
        .m5t-grid { display:flex; flex-wrap:wrap; gap:6px; margin-top:5px; }
        .m5t-item { display:flex; align-items:center; gap:4px; padding:4px 10px; border:1px solid #ddd; border-radius:4px; cursor:pointer; font-size:13px; background:#f9f9f9; transition:all .15s; }
        .m5t-item:has(input:checked) { background:#2A3F54; color:#fff; border-color:#2A3F54; }
        /* 流程表 */
        .flow-table th, .flow-table td { vertical-align:middle !important; }
        .flow-status-badge { display:inline-block; padding:2px 8px; border-radius:10px; font-size:12px; font-weight:600; }
        .flow-status-badge.pending  { background:#fcf8e3; color:#8a6d3b; border:1px solid #faebcc; }
        .flow-status-badge.received { background:#d9edf7; color:#31708f; border:1px solid #bce8f1; }
        .flow-status-badge.returned { background:#dff0d8; color:#3c763d; border:1px solid #d6e9c6; }
        /* 退貨性質 badge */
        .ir-type-badge { display:inline-block; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:600; white-space:nowrap; }
        .ir-type-badge.is-note { background:#FEF3C7; color:#92400E; border:1px solid #FCD34D; }
        .ir-type-badge.normal  { background:#F0F0F0; color:#555; border:1px solid #DDD; }
        /* 備註橫條列樣式 */
        tr.ir-note-banner-row > td { padding:0 !important; background:#FFFBEB; border-top:none !important; }
        tr.ir-note-banner-row > td.col-check { border-left:3px solid #F59E0B !important; padding:0 !important; vertical-align:middle !important; }
        .ir-note-banner { display:flex; align-items:center; gap:10px; padding:5px 14px; border-bottom:1px solid #FDE68A; }
        .ir-note-banner:hover { background:#FEF9C3; }
        /* 批次操作條 */
        #ir-batch-bar { position:fixed; bottom:0; left:0; right:0; z-index:1050; background:linear-gradient(135deg,#1e3a5f,#2c3e50); color:#fff; padding:10px 20px; box-shadow:0 -3px 12px rgba(0,0,0,.25); display:none; }
        #ir-batch-bar .inner { display:flex; align-items:center; gap:12px; max-width:1400px; margin:0 auto; flex-wrap:wrap; }
        /* checkbox 欄 */
        th.col-check, td.col-check { width:32px; text-align:center; padding:6px 0 !important; }
        /* DataTable 自訂佈局 */
        .dt-top-row { display:flex; align-items:center; justify-content:space-between; margin-bottom:8px; flex-wrap:wrap; gap:6px; }
        .dt-top-row .dataTables_length label { margin:0; font-size:13px; color:#666; }
        .dt-top-row .dataTables_length select { margin:0 4px; }
        .dt-top-row .dataTables_paginate { margin:0; }
        .dt-info-row { font-size:12px; color:#999; padding:6px 0 0; }
        .dt-info-row .dataTables_info { padding:0; }
        /* 附件列表 */
        .qa-attach-list { margin-top:4px; min-height:4px; }
        .qa-attach-item { display:inline-flex; align-items:center; gap:4px; background:#F1F5F9; border:1px solid #CBD5E1; border-radius:4px; padding:2px 8px; margin:2px; font-size:12px; }
        .qa-attach-item .del-btn { color:#EF4444; cursor:pointer; background:none; border:none; padding:0 2px; line-height:1; }
        .qa-attach-item .del-btn:hover { color:#B91C1C; }
        input[type=number]::-webkit-outer-spin-button,
        input[type=number]::-webkit-inner-spin-button { -webkit-appearance:none; margin:0; }
    </style>
</head>
<body class="nav-sm">
<div class="container body">
<div class="main_container">
    <?php include '../partPage/sideAndTopBarMenu.html' ?>
    <div class="right_col" role="main">
        <div class="">
            <div class="page-title">
                <div class="title_left"><h3>退貨追蹤 <small>IR Tracking</small></h3></div>
            </div>
            <div class="clearfix"></div>

            <div class="row">
                <div class="col-md-12">
                    <div class="stats-container" id="statsContainer">
                        <div class="stat-card card-all active">
                            <i class="fa fa-list-alt stat-icon"></i>
                            <div class="stat-value" id="count-all">0</div>
                            <div class="stat-label">全部退貨</div>
                        </div>
                        <div class="stat-card action-btn" style="background:#26B99A;color:white;" onclick="openNewIRModal()">
                            <div style="text-align:center;"><i class="fa fa-plus-circle" style="font-size:28px;margin-bottom:5px;display:block;"></i><div style="font-weight:600;font-size:14px;">新增退貨單</div></div>
                        </div>
                        <?php /* 「品質異常單—可用部門設定」已移至 品管合併檢驗頁（views/QC/inspection_combined_prototype.php 設定選單）2026-07-06 */ ?>
                    </div>
                </div>
            </div>

            <!-- 篩選列 -->
            <div class="row" style="margin-bottom:10px;">
                <div class="col-md-12">
                    <div style="background:#fff;border-radius:6px;padding:8px 14px;box-shadow:0 1px 4px rgba(0,0,0,.06);display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                        <span style="font-size:13px;color:#888;font-weight:600;"><i class="fa fa-filter"></i> 篩選：</span>
                        <select id="filter-assignee" class="form-control input-sm" style="width:150px;" onchange="applyFilters()">
                            <option value="">所有負責業務</option>
                        </select>
                        <select id="filter-return-type" class="form-control input-sm" style="width:140px;" onchange="applyFilters()">
                            <option value="">所有退貨性質</option>
                        </select>
                        <button class="btn btn-default btn-sm" onclick="clearFilters()"><i class="fa fa-times"></i> 清除篩選</button>
                        <div style="margin-left:auto;display:flex;align-items:center;gap:6px;">
                            <i class="fa fa-search" style="color:#bbb;font-size:13px;"></i>
                            <input type="search" id="ir-global-search" class="form-control input-sm" placeholder="全域搜尋..." style="width:200px;" oninput="irTableSearch(this.value)">
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-12">
                    <div class="main-card">
                        <table id="irTable" class="table table-striped">
                            <thead>
                                <tr>
                                    <th class="col-check"><input type="checkbox" id="selectAll" onclick="toggleSelectAll(this)" title="全選"></th>
                                    <th style="width:120px;">退貨單號</th>
                                    <th>退貨日期</th>
                                    <th>客戶</th>
                                    <th>料號</th>
                                    <th>數量</th>
                                    <th style="min-width:80px;">退貨性質</th>
                                    <th>退貨原因 / 備註</th>
                                    <th>部門處理狀態</th>
                                    <th style="min-width:90px;">負責業務</th>
                                    <th style="min-width:250px;">業務進度</th>
                                    <th>品質異常單</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php include '../partPage/footer.html' ?>
</div>
</div>

<div id="custom-toast"></div>

<!-- ── 批次修改操作條 ── -->
<div id="ir-batch-bar">
    <div class="inner">
        <i class="fa fa-check-square-o" style="font-size:20px;color:#FCD34D;"></i>
        <strong id="ir-batch-count">已選 0 筆</strong>
        <span style="border-left:1px solid rgba(255,255,255,.3);height:20px;margin:0 4px;"></span>
        <span style="font-size:12px;color:#ccc;">退貨性質：</span>
        <select id="ir-batch-type-select" class="form-control input-sm" style="width:150px;">
            <option value="">-- 清除性質 --</option>
        </select>
        <button class="btn btn-warning btn-sm" onclick="submitIRBatchTypeUpdate()">
            <i class="fa fa-tag"></i> 套用
        </button>
        <span style="border-left:1px solid rgba(255,255,255,.3);height:20px;margin:0 4px;"></span>
        <span style="font-size:12px;color:#ccc;">負責業務：</span>
        <select id="ir-batch-assignee-select" class="form-control input-sm" style="width:150px;">
            <option value="">-- 清除業務 --</option>
        </select>
        <button class="btn btn-info btn-sm" onclick="submitIRBatchAssigneeUpdate()">
            <i class="fa fa-user"></i> 套用
        </button>
        <button class="btn btn-link btn-sm" onclick="clearIRBatchSelection()" style="color:#aaa;margin-left:auto;">
            <i class="fa fa-times"></i> 取消
        </button>
    </div>
</div>

<!-- ── 設定 Modal ── -->
<div class="modal fade" id="returnTypeModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg"><div class="modal-content">
        <div class="modal-header">
            <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            <h4 class="modal-title"><i class="fa fa-cog"></i> 設定</h4>
        </div>
        <div class="modal-body">
            <ul class="nav nav-tabs" style="margin-bottom:15px;">
                <li class="active"><a href="#tab-return-type" data-toggle="tab"><i class="fa fa-tags"></i> 退貨性質設定</a></li>
                <li><a href="#tab-attach-path" data-toggle="tab" onclick="loadAttachRootPath()"><i class="fa fa-folder-open-o"></i> 附件儲存路徑設定</a></li>
            </ul>
            <div class="tab-content">
                <!-- Tab 1: 退貨性質設定 -->
                <div class="tab-pane active" id="tab-return-type">
                    <div class="row">
                        <div class="col-md-4">
                            <div class="panel panel-default">
                                <div class="panel-heading">新增 / 修改性質</div>
                                <div class="panel-body">
                                    <form id="returnTypeForm">
                                        <input type="hidden" id="rt_id" name="type_id">
                                        <div class="form-group">
                                            <label>性質名稱 <span class="text-danger">*</span></label>
                                            <input type="text" class="form-control" id="rt_name" name="type_name" required placeholder="例如：客訴、備忘">
                                        </div>
                                        <div class="form-group">
                                            <label>說明</label>
                                            <input type="text" class="form-control" id="rt_desc" name="description" placeholder="選填">
                                        </div>
                                        <div class="form-group">
                                            <label>排序（數字越小越前）</label>
                                            <input type="number" class="form-control" id="rt_sort" name="sort_order" value="0">
                                        </div>
                                        <div class="checkbox" style="margin-bottom:8px;">
                                            <label>
                                                <input type="checkbox" id="rt_is_note" name="is_note" value="1">
                                                <strong style="color:#D97706;"><i class="fa fa-sticky-note-o"></i> 備註模式</strong>
                                                <small class="text-muted" style="display:block;margin-left:20px;line-height:1.4;font-size:11px;">
                                                    勾選後此類退貨單將以<br>「備註條」形式顯示在列表
                                                </small>
                                            </label>
                                        </div>
                                        <div class="checkbox" style="margin-bottom:8px;">
                                            <label>
                                                <input type="checkbox" id="rt_allow_ncr" name="allow_ncr" value="1" checked>
                                                允許開立品質異常單
                                            </label>
                                        </div>
                                        <div class="checkbox" style="margin-bottom:12px;">
                                            <label>
                                                <input type="checkbox" id="rt_active" name="is_active" value="1" checked>
                                                啟用
                                            </label>
                                        </div>
                                        <button type="submit" class="btn btn-primary btn-block"><i class="fa fa-save"></i> 儲存</button>
                                        <button type="button" class="btn btn-default btn-block" onclick="resetReturnTypeForm()">重置表單</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-8">
                            <table class="table table-bordered table-striped" id="returnTypeTable" style="font-size:13px;">
                                <thead>
                                    <tr>
                                        <th>名稱</th><th>說明</th>
                                        <th style="width:80px;text-align:center;">備註模式</th>
                                        <th style="width:80px;text-align:center;">異常單</th>
                                        <th style="width:50px;text-align:center;">排序</th>
                                        <th style="width:55px;text-align:center;">狀態</th>
                                        <th style="width:70px;text-align:center;">操作</th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <!-- Tab 2: 附件儲存路徑設定 -->
                <div class="tab-pane" id="tab-attach-path">
                    <div class="form-group">
                        <label><strong>異常單附件儲存根目錄</strong></label>
                        <input type="text" class="form-control" id="attach_root_path_input"
                               placeholder="例: Z:\BOM\ERP\品管\異常單附件">
                        <small class="text-muted">附件將自動存入「此路徑\<strong>異常單號</strong>\」資料夾中，請確認路徑對應網站伺服器可存取的磁碟位置。</small>
                    </div>
                    <button type="button" class="btn btn-primary" onclick="saveAttachRootPath()">
                        <i class="fa fa-save"></i> 儲存路徑
                    </button>
                    <span id="attach_path_save_msg" style="margin-left:10px;font-size:13px;display:none;"></span>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-default" data-dismiss="modal">關閉</button>
        </div>
    </div></div>
</div>

<!-- ── 新增退貨單 Modal ── -->
<div class="modal fade" id="newIRModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg"><div class="modal-content">
        <div class="modal-header">
            <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            <h4 class="modal-title"><i class="fa fa-plus-circle"></i> 新增退貨單</h4>
        </div>
        <div class="modal-body" style="background:#f7f7f7;padding:20px;">
            <form id="newIRForm" class="form-horizontal">
                <div class="row">
                    <div class="col-md-6 col-sm-6 col-xs-12 form-group">
                        <label>退貨單號 (IR + 民國年 + MMDD + 3碼)</label>
                        <input type="text" class="form-control" name="ir_no" id="ir_no" placeholder="例如: IR1140101001" autocomplete="off">
                        <small class="text-muted" style="cursor:pointer" onclick="generateIRPrefix()">點此生成前綴</small>
                    </div>
                    <div class="col-md-3 col-sm-3 col-xs-12 form-group">
                        <label>退貨日期</label>
                        <input type="date" class="form-control" name="ir_date" id="ir_date_input">
                    </div>
                    <div class="col-md-3 col-sm-3 col-xs-12 form-group">
                        <label>退貨數量</label>
                        <input type="number" class="form-control" name="qty" autocomplete="off" placeholder="數量">
                    </div>
                    <div class="clearfix"></div>
                    <div class="col-md-6 col-sm-6 col-xs-12 form-group" style="position:relative;">
                        <label>退貨料號 (雙擊清除)</label>
                        <input type="text" class="form-control" id="part_search" placeholder="輸入料號..." autocomplete="off">
                        <div id="part-suggestions"></div>
                        <input type="hidden" name="d_id" id="d_id">
                    </div>
                    <div class="col-md-6 col-sm-6 col-xs-12 form-group">
                        <label>客戶</label>
                        <div id="client_container"><input type="text" class="form-control" name="client" id="client_input" placeholder="自動帶入或選擇"></div>
                    </div>
                    <div class="col-md-6 col-sm-6 col-xs-12 form-group">
                        <label>客戶退貨單號</label>
                        <input type="text" class="form-control" name="c_ir" placeholder="客戶單號" autocomplete="off">
                    </div>
                    <div class="col-md-6 col-sm-6 col-xs-12 form-group">
                        <label>負責業務 (自動帶入，可修改)</label>
                        <select class="form-control" name="sale_assignee" id="sale_assignee_select"><option value="">請選擇</option></select>
                    </div>
                    <div class="col-md-12 col-sm-12 col-xs-12 form-group">
                        <label>退貨原因 (最多100字)</label>
                        <textarea class="form-control" name="reason" rows="5" placeholder="請詳細描述退貨原因..."></textarea>
                    </div>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
            <button type="button" class="btn btn-primary" onclick="saveIR()">確認新增</button>
        </div>
    </div></div>
</div>

<?php /* 「品質異常單—可用部門設定」Modal 已移至 品管合併檢驗頁（views/QC/inspection_combined_prototype.php 設定選單）2026-07-06 */ ?>

<!-- ── 開立 / 編輯品質異常單 Modal ── -->
<input type="file" id="attach_file_input" style="display:none;" onchange="doUploadAttach(this)">
<div class="modal fade" id="qaModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg"><div class="modal-content">
        <div class="modal-header">
            <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            <h4 class="modal-title" id="qaModalTitle">開立品質異常單</h4>
        </div>
        <div class="modal-body" style="padding:20px;">
            <input type="hidden" id="qa_ir_id">
            <input type="hidden" id="qa_edit_id">

            <!-- BOM綁定 -->
            <div style="background:#EFF6FF;border:1px solid #BFDBFE;border-radius:6px;padding:10px 14px;margin-bottom:14px;">
                <div style="font-weight:600;color:#1E40AF;margin-bottom:6px;"><i class="fa fa-link"></i> 綁定BOM號碼 <small style="font-weight:normal;color:#64748B;">(選填，綁定後可選擇有問題的製程並自動帶出廠商)</small></div>
                <div style="display:flex;gap:6px;align-items:center;">
                    <input type="text" class="form-control input-sm" id="qa_bom_no" placeholder="輸入BOM號碼..." style="flex:1;max-width:260px;">
                    <button type="button" class="btn btn-info btn-sm" onclick="queryBomProcesses()"><i class="fa fa-search"></i> 查詢製程</button>
                    <button type="button" class="btn btn-default btn-sm" id="qa_bom_clear_btn" style="display:none;" onclick="clearBomBinding()"><i class="fa fa-times"></i> 清除</button>
                </div>
                <div id="qa_bom_process_container" style="display:none;margin-top:10px;">
                    <div style="font-size:12px;color:#555;margin-bottom:5px;font-weight:600;">選擇有問題的製程（可複選）：</div>
                    <div id="qa_bom_process_list" style="display:flex;flex-wrap:wrap;gap:6px;"></div>
                </div>
            </div>

            <div class="row">
                <!-- 左欄 -->
                <div class="col-md-6">
                    <div class="form-group">
                        <label>異常單號</label>
                        <input type="text" class="form-control" id="qa_no" placeholder="自動產生">
                    </div>
                    <div class="form-group">
                        <label>異常種類
                            <button type="button" class="btn btn-default btn-xs" onclick="openAbnormalTypeManageModal()" title="管理種類" style="margin-left:5px;"><i class="fa fa-cog"></i></button>
                        </label>
                        <select class="form-control" id="qa_abnormal_type"><option value="">請選擇...</option></select>
                    </div>
                    <div class="form-group">
                        <label>異常發生日期</label>
                        <input type="date" class="form-control" id="qa_occurrence_date">
                    </div>
                    <div class="form-group">
                        <label>異常數量</label>
                        <input type="number" class="form-control" id="qa_sqty" placeholder="不良品數量" style="-moz-appearance:textfield;">
                    </div>
                    <!-- 責任單位 -->
                    <div class="form-group">
                        <label>責任單位</label>
                        <div style="display:flex;gap:16px;margin-bottom:7px;">
                            <label style="font-weight:normal;cursor:pointer;margin:0;"><input type="radio" name="qa_resp_type" value="" onchange="onRespTypeChange('')" checked> 未指定</label>
                            <label style="font-weight:normal;cursor:pointer;margin:0;"><input type="radio" name="qa_resp_type" value="vendor" onchange="onRespTypeChange('vendor')"> 廠商</label>
                            <label style="font-weight:normal;cursor:pointer;margin:0;"><input type="radio" name="qa_resp_type" value="dept" onchange="onRespTypeChange('dept')"> 廠內部門</label>
                        </div>
                        <!-- 廠商 UI -->
                        <div id="qa_resp_vendor_ui" style="display:none;">
                            <select class="form-control" id="qa_resp_vendor_select" style="display:none;margin-bottom:4px;"></select>
                            <div id="qa_resp_vendor_search_wrap" style="position:relative;">
                                <input type="text" class="form-control" id="qa_resp_vendor_search" placeholder="輸入廠商名稱或編號搜尋..." oninput="onVendorSearch(this.value)" autocomplete="off">
                                <div id="qa_vendor_suggestions" style="position:absolute;top:100%;left:0;right:0;z-index:2001;background:#fff;border:1px solid #ccc;max-height:200px;overflow-y:auto;display:none;box-shadow:0 2px 6px rgba(0,0,0,.1);"></div>
                            </div>
                            <input type="hidden" id="qa_resp_vendor_id">
                        </div>
                        <!-- 廠內部門 UI -->
                        <div id="qa_resp_dept_ui" style="display:none;">
                            <select class="form-control" id="qa_resp_dept_select" onchange="onRespDeptChange()">
                                <option value="">選擇部門</option>
                            </select>
                            <select class="form-control" id="qa_resp_person_select" style="margin-top:5px;">
                                <option value="">選擇人員(選填)</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>發現單位</label>
                        <select class="form-control" id="qa_found_unit">
                            <option value="">請選擇</option>
                            <option value="廠內">廠內</option>
                            <option value="客退">客退</option>
                        </select>
                    </div>
                </div>
                <!-- 右欄 -->
                <div class="col-md-6">
                    <div class="form-group">
                        <label>異常現象描述
                            <button type="button" class="btn btn-xs btn-default" onclick="openAttachUpload('phenomenon')" title="上傳附件" style="margin-left:5px;"><i class="fa fa-paperclip"></i> 附件</button>
                        </label>
                        <textarea class="form-control" id="qa_phenomenon" rows="4" placeholder="詳細描述異常現象..."></textarea>
                        <div id="attachments_phenomenon" class="qa-attach-list"></div>
                    </div>
                    <div class="form-group">
                        <label>5M+T 異常原因分類（單選）</label>
                        <div class="m5t-grid" id="m5t_grid">
                            <?php foreach(['人','機器','材料','方法','工具','環','其他'] as $m): ?>
                            <label class="m5t-item">
                                <input type="radio" name="qa_defect_category" value="<?=$m?>" style="margin:0;">
                                <?=$m?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>原因詳細說明
                            <button type="button" class="btn btn-xs btn-default" onclick="openAttachUpload('defect_detail')" title="上傳附件" style="margin-left:5px;"><i class="fa fa-paperclip"></i> 附件</button>
                        </label>
                        <textarea class="form-control" id="qa_defect_detail" rows="3" placeholder="異常原因詳細說明..."></textarea>
                        <div id="attachments_defect_detail" class="qa-attach-list"></div>
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <label>品管備註
                            <button type="button" class="btn btn-xs btn-default" onclick="openAttachUpload('qa_ps')" title="上傳附件" style="margin-left:5px;"><i class="fa fa-paperclip"></i> 附件</button>
                        </label>
                        <textarea class="form-control" id="qa_ps" rows="3" placeholder="品管判定備註..."></textarea>
                        <div id="attachments_qa_ps" class="qa-attach-list"></div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label>異常處置方式</label>
                        <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:5px;">
                            <?php foreach(['特採','報廢','重工','需矯正','轉總經理裁示'] as $d): ?>
                            <label style="display:flex;align-items:center;gap:4px;padding:4px 10px;border:1px solid #ddd;border-radius:4px;cursor:pointer;font-size:13px;background:#f9f9f9;">
                                <input type="checkbox" name="qa_disposition" value="<?=$d?>" style="margin:0;"> <?=$d?>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>處置說明</label>
                        <textarea class="form-control" id="qa_disposition_note" rows="2" placeholder="處置說明..."></textarea>
                    </div>
                </div>
            </div>
            <hr style="margin:10px 0;">
            <div class="form-group">
                <label>回覆部門設定</label>
                <div id="qa_dept_container" style="max-height:220px;overflow-y:auto;border:1px solid #eee;padding:8px;border-radius:4px;"></div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
            <button type="button" class="btn btn-primary" id="qa_save_btn" onclick="saveQA()">確認開立</button>
        </div>
    </div></div>
</div>

<!-- ── 品質異常單詳情 Modal ── -->
<div class="modal fade" id="qaDetailModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" style="width:85%;"><div class="modal-content">
        <div class="modal-header">
            <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            <h4 class="modal-title">品質異常單詳情</h4>
        </div>
        <div class="modal-body">
            <input type="hidden" id="qa_detail_order_id">
            <!-- 基本資訊卡 -->
            <div class="row" id="qa_detail_info" style="margin-bottom:15px;"></div>
            <hr style="margin:10px 0;">
            <!-- 部門流程表 -->
            <h5 style="font-weight:700;margin-bottom:10px;">相關單位回覆流程</h5>
            <table class="table table-bordered table-striped flow-table" style="font-size:13px;">
                <thead>
                    <tr>
                        <th style="width:10%;">部門</th>
                        <th style="width:12%;">指定人員</th>
                        <th style="width:13%;">送交時間</th>
                        <th>回覆內容</th>
                        <th style="width:13%;">歸還時間</th>
                        <th style="width:22%;">操作</th>
                    </tr>
                </thead>
                <tbody id="qa_flow_tbody">
                    <tr><td colspan="6" class="text-center text-muted"><i class="fa fa-spinner fa-spin"></i> 載入中...</td></tr>
                </tbody>
            </table>
            <!-- 通知回覆/回簽狀態（來自公告通知系統，唯讀顯示） -->
            <div id="qa_notify_status_area" style="display:none;">
                <hr style="margin:10px 0;">
                <h5 style="font-weight:700;margin-bottom:10px;">通知回覆／回簽狀態 <small class="text-muted">（由公告通知系統回覆，唯讀）</small></h5>
                <div id="qa_notify_status_body"></div>
            </div>
        </div>
        <div class="modal-footer" style="display:flex;justify-content:space-between;">
            <button type="button" class="btn btn-warning btn-sm" onclick="openEditQAModal()"><i class="fa fa-pencil"></i> 編輯異常單</button>
            <button type="button" class="btn btn-default" data-dismiss="modal">關閉</button>
        </div>
    </div></div>
</div>

<!-- ── 異常種類管理 Modal ── -->
<div class="modal fade" id="abnormalTypeManageModal" tabindex="-1" role="dialog">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header">
            <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            <h4 class="modal-title">管理異常種類</h4>
        </div>
        <div class="modal-body">
            <div class="form-inline" style="margin-bottom:10px;">
                <input type="text" class="form-control" id="new_type_name" placeholder="輸入新種類名稱">
                <button class="btn btn-primary" onclick="addAbnormalType()">新增</button>
            </div>
            <table class="table table-striped table-bordered">
                <thead><tr><th>名稱</th><th>狀態</th><th>操作</th></tr></thead>
                <tbody id="abnormal_type_list_tbody"></tbody>
            </table>
        </div>
    </div></div>
</div>

<!-- ── 業務進度記錄 Modal ── -->
<div class="modal fade" id="irProgressModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg"><div class="modal-content">
        <div class="modal-header">
            <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            <h4 class="modal-title" id="ipmTitle"><i class="fa fa-comments"></i> 業務進度記錄</h4>
        </div>
        <div class="modal-body" style="padding:0;">
            <input type="hidden" id="ipm-ir-id">
            <input type="hidden" id="ipm-ir-no">
            <ul class="nav nav-tabs" style="margin:0;padding:0 15px;border-bottom:1px solid #ddd;">
                <li class="active" id="ipm-li-notes"><a href="#ipm-tab-notes" data-toggle="tab"><i class="fa fa-comments-o"></i> 進度回覆</a></li>
                <li id="ipm-li-attach"><a href="#ipm-tab-attach" data-toggle="tab" onclick="ipmLoadIrAttachments()"><i class="fa fa-paperclip"></i> 退貨單附件</a></li>
            </ul>
            <div class="tab-content" style="padding:14px 16px;">
                <!-- 進度回覆 Tab -->
                <div class="tab-pane active" id="ipm-tab-notes">
                    <div style="background:#f8f9fa;border:1px solid #e9ecef;border-radius:6px;padding:10px;margin-bottom:12px;">
                        <textarea class="form-control" id="ipm-new-note" rows="2" placeholder="輸入業務進度回覆…" style="resize:none;margin-bottom:6px;"></textarea>
                        <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:6px;">
                            <div>
                                <label class="btn btn-xs btn-default" style="cursor:pointer;border-radius:10px;margin:0;">
                                    <i class="fa fa-paperclip"></i> 選擇附件
                                    <input type="file" id="ipm-note-file-input" style="display:none;" multiple onchange="ipmAddNewNoteFiles(this)">
                                </label>
                                <div id="ipm-new-note-files-preview" class="qa-attach-list" style="margin-top:5px;display:flex;flex-wrap:wrap;gap:4px;"></div>
                            </div>
                            <button class="btn btn-primary btn-sm" onclick="ipmAddNote()"><i class="fa fa-send"></i> 送出</button>
                        </div>
                    </div>
                    <div id="ipm-notes-list" style="max-height:400px;overflow-y:auto;"></div>
                </div>
                <!-- 退貨單附件 Tab -->
                <div class="tab-pane" id="ipm-tab-attach">
                    <label class="btn btn-default btn-sm" style="cursor:pointer;margin-bottom:12px;">
                        <i class="fa fa-upload"></i> 上傳附件
                        <input type="file" id="ipm-ir-file-input" style="display:none;" multiple onchange="ipmUploadIrFiles(this)">
                    </label>
                    <div id="ipm-ir-attachments-list"><p class="text-muted" style="font-size:13px;">載入中...</p></div>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-default" data-dismiss="modal">關閉</button>
        </div>
    </div></div>
</div>

<!-- Scripts -->
<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/jquery.dataTables.min.js"></script>
<script src="../../resource/js/dataTables.bootstrap.min.js"></script>
<script src="../../resource/js/custom.min.js"></script>

<script>
var IR_API   = '../../src/store/store_IR_Track_API.php';
var QA_API   = '../../src/store/store_QA_Abnormal_API.php';
var allIRData = [];
var allDepts  = [];
var currentFilter = 'all';
var currentAssigneeFilter = '';
var currentReturnTypeFilter = '';
var deptUsersCache = {};
var qaTempKey = '';
var qaAttachments = { qa_ps: [], phenomenon: [], defect_detail: [] };
var qaCurrentAttachField = '';

$(document).ready(function() {
    loadIRList();
    loadAllDepts();
    loadSalesUsers();
    loadAbnormalTypes();
    loadReturnTypes();

    $(document).on('dblclick', '.dataTables_filter input', function() {
        var t = $('#irTable').DataTable();
        if (this.value !== '') { this.value = ''; t.search('').draw(); }
    });
});

// ── 退貨清單 ────────────────────────────────────────────────
function loadIRList() {
    $.post(IR_API, { action: 'get_ir_list' }, function(res) {
        if (res.success) {
            allIRData = res.data;
            updateStats();
            renderTable();
        }
    }, 'json');
}

function updateStats() {
    var countAll = allIRData.length;
    var countDone = allIRData.filter(r => r.IR_status == 9).length;
    var countProcessing = countAll - countDone;
    var html = `
        <div class="stat-card card-all ${currentFilter==='all'?'active':''}" onclick="filterStatus('all')">
            <i class="fa fa-list-alt stat-icon"></i>
            <div class="stat-value">${countAll}</div><div class="stat-label">全部退貨</div>
        </div>
        <div class="stat-card card-processing ${currentFilter==='processing'?'active':''}" onclick="filterStatus('processing')">
            <i class="fa fa-clock-o stat-icon"></i>
            <div class="stat-value">${countProcessing}</div><div class="stat-label">處理中</div>
        </div>
        <div class="stat-card card-done ${currentFilter==='done'?'active':''}" onclick="filterStatus('done')">
            <i class="fa fa-check-circle stat-icon"></i>
            <div class="stat-value">${countDone}</div><div class="stat-label">已完成</div>
        </div>
        <div class="stat-card action-btn" style="background:#26B99A;color:white;" onclick="openNewIRModal()">
            <div style="text-align:center;"><i class="fa fa-plus-circle" style="font-size:28px;margin-bottom:5px;display:block;"></i><div style="font-weight:600;font-size:14px;">新增退貨單</div></div>
        </div>
        <div class="stat-card action-btn" style="background:#8E44AD;color:white;width:120px;" onclick="openReturnTypeModal()">
            <div style="text-align:center;"><i class="fa fa-cog" style="font-size:22px;margin-bottom:5px;display:block;"></i><div style="font-weight:600;font-size:13px;">設定</div></div>
        </div>`;
    /* 「品質異常單—可用部門設定」已移至 品管合併檢驗頁（inspection_combined_prototype.php 設定選單）2026-07-06 */
    $('.stats-container').html(html);
}

function filterStatus(status) {
    currentFilter = status;
    updateStats();
    renderTable();
}

var currentNoteGroupMap = {}; // { d_id_key: {notes:[], anchorId:null} }

function renderTable() {
    if ($.fn.DataTable.isDataTable('#irTable')) { $('#irTable').DataTable().destroy(); }
    $('#irTable tbody').empty();
    $('#selectAll').prop('checked', false);
    clearIRBatchSelection();

    var data = allIRData.filter(function(r) {
        if (currentFilter === 'processing' && r.IR_status == 9) return false;
        if (currentFilter === 'done' && r.IR_status != 9) return false;
        if (currentAssigneeFilter && String(r.sale_assignee) !== currentAssigneeFilter) return false;
        if (currentReturnTypeFilter) {
            if (currentReturnTypeFilter === 'none' && r.return_type_id) return false;
            if (currentReturnTypeFilter !== 'none' && String(r.return_type_id) !== currentReturnTypeFilter) return false;
        }
        return true;
    });

    // 分離備註行和一般行
    var noteRows   = data.filter(function(r){ return r.return_type_is_note == 1; });
    var regularRows= data.filter(function(r){ return r.return_type_is_note != 1; });

    // 建立 note group map：依 d_id 分組
    currentNoteGroupMap = {};
    noteRows.forEach(function(r) {
        var key = r.d_id || ('__id_' + r.IR_id);
        if (!currentNoteGroupMap[key]) currentNoteGroupMap[key] = { notes: [], anchorId: null };
        currentNoteGroupMap[key].notes.push(r);
    });

    // 依 d_id 分組排列一般行，決定每組的 anchor（最後一筆）
    var groupMap   = {}; // d_id_key -> [rows]
    var groupOrder = []; // 出現順序
    regularRows.forEach(function(r) {
        var key = r.d_id || ('__id_' + r.IR_id);
        if (!groupMap[key]) { groupMap[key] = []; groupOrder.push(key); }
        groupMap[key].push(r);
    });

    // 更新 anchorId（每組最後一筆一般行）
    groupOrder.forEach(function(key) {
        var rows = groupMap[key];
        if (currentNoteGroupMap[key]) {
            currentNoteGroupMap[key].anchorId = rows[rows.length - 1].IR_id;
        }
    });

    // 渲染一般行（依 d_id 群組順序）
    groupOrder.forEach(function(key) {
        groupMap[key].forEach(function(row) {
            $('#irTable tbody').append(buildRegularRow(row));
        });
    });

    // 初始化 DataTable：移除原生搜尋框，把分頁放到上方右側
    var dt = $('#irTable').DataTable({
        pageLength: 10, ordering: false,
        dom: '<"dt-top-row"<"dt-length"l><"dt-pagination"p>>rt<"dt-info-row"i>',
        columnDefs: [{ orderable: false, targets: 0 }],
        language: {
            url: '//cdn.datatables.net/plug-ins/1.10.20/i18n/Chinese-traditional.json',
            lengthMenu: '每頁 _MENU_ 筆'
        },
        drawCallback: function() { injectNoteBanners(); }
    });

    // 恢復搜尋值並綁定輸入事件（用命名空間避免重複綁定）
    var savedSearch = $('#ir-global-search').val();
    if (savedSearch) dt.search(savedSearch).draw(false);
    $('#ir-global-search').off('input.irt').on('input.irt', function() {
        if ($.fn.DataTable.isDataTable('#irTable')) {
            $('#irTable').DataTable().search(this.value).draw();
        }
    });
}

function buildRegularRow(row) {
    var deptHtml = '';
    if (row.has_ncr == 1 && row.ncr_dept_status) {
        row.ncr_dept_status.sort(function(a,b) {
            var da = (a.recv&&a.recv!=='-') ? new Date(a.recv).getTime() : 8640000000000000;
            var db_ = (b.recv&&b.recv!=='-') ? new Date(b.recv).getTime() : 8640000000000000;
            return da - db_;
        });
        row.ncr_dept_status.forEach(function(ds) {
            var cls = ds.status === 'Returned' ? 'done' : 'pending';
            var dateHtml = ds.status === 'Returned'
                ? `<br><small>${ds.recv}~${ds.done}</small>`
                : (ds.recv && ds.recv !== '-' ? `<br><small>${ds.recv}~</small>` : '');
            deptHtml += `<div class="dept-tag ${cls}"><strong>${ds.dept}</strong>: ${ds.user}${dateHtml}</div>`;
        });
    }

    var modifierInfo = '';
    if (row.modifier_name) {
        modifierInfo = row.modifier_name + ' ' + (row.Modified_At_Str || '');
        if (!row.progress_note) modifierInfo += ' (刪除)';
    }

    var typeBadge = row.return_type_name
        ? `<span class="ir-type-badge normal">${row.return_type_name}</span>`
        : '<span style="color:#ccc;font-size:11px;">-</span>';
    var isDone   = row.IR_status == 9;
    var allowNcr = row.return_type_allow_ncr == null || row.return_type_allow_ncr != 0;
    var qaHtml   = !allowNcr
        ? '<span class="text-muted" style="font-size:11px;"><i class="fa fa-ban"></i> 不開立</span>'
        : (row.has_ncr == 1
            ? `<button class="btn btn-xs btn-info" onclick="openQADetailModal(${row.IR_id})">${row.qa_abnormal_order_no || '查看'}</button>`
            : `<button class="btn btn-xs btn-default" onclick="openCreateQAModal(${row.IR_id}, '${row.IR_no}')">開立</button>`);

    var statusBtn = isDone
        ? `<button class="btn btn-xs btn-default" onclick="toggleIRStatus(${row.IR_id},0)" title="點擊重新開啟" style="margin-top:4px;color:#888;">
               <i class="fa fa-check-circle" style="color:#1ABB9C;"></i> 已結案
           </button>`
        : `<button class="btn btn-xs btn-success" onclick="toggleIRStatus(${row.IR_id},9)" style="margin-top:4px;">
               <i class="fa fa-check"></i> 結案
           </button>`;

    return `<tr data-ir-id="${row.IR_id}" ${isDone ? 'style="opacity:.6;"' : ''}>
        <td class="col-check"><input type="checkbox" class="ir-batch-check" value="${row.IR_id}" onclick="onBatchCheck()"></td>
        <td>${row.IR_no}</td>
        <td>${row.IR_date}</td>
        <td>${row.Client_Name || '-'}</td>
        <td>${row.d_id || '-'}</td>
        <td>${row.Qty}</td>
        <td>${typeBadge}</td>
        <td>
            <div style="max-height:80px;overflow-y:auto;">
                ${row.IR_ps ? `<span>${row.IR_ps}</span>` : ''}
                ${row.ERP_note ? `<div style="color:#888;font-size:12px;margin-top:3px;border-top:1px dashed #ddd;padding-top:2px;"><i class="fa fa-tag" style="margin-right:2px;"></i>${row.ERP_note}</div>` : ''}
            </div>
        </td>
        <td>${deptHtml}</td>
        <td style="white-space:nowrap;">
            ${row.assignee_name
                ? `<span style="font-size:13px;"><i class="fa fa-user-o" style="color:#888;margin-right:3px;"></i>${row.assignee_name}</span>`
                : '<span style="color:#ccc;font-size:11px;">-</span>'}
        </td>
        <td>
            <textarea class="table-textarea" id="progress_${row.IR_id}" onkeydown="checkEnter(event,${row.IR_id},this)" placeholder="輸入業務進度...">${row.progress_note||''}</textarea>
            <div id="progress_info_${row.IR_id}" style="font-size:10px;color:#999;margin-top:2px;">${modifierInfo}</div>
            <div style="margin-top:5px;display:flex;gap:4px;flex-wrap:wrap;">
                <button class="btn btn-xs btn-default" onclick="openIrProgressModal(${row.IR_id},'${row.IR_no}')" title="業務進度回覆記錄"><i class="fa fa-comments"></i> 回覆記錄</button>
                <button class="btn btn-xs btn-default" onclick="openIrProgressModal(${row.IR_id},'${row.IR_no}','attach')" title="退貨單附件"><i class="fa fa-paperclip"></i> 附件</button>
            </div>
        </td>
        <td style="white-space:nowrap;">
            ${qaHtml}
            <br>${statusBtn}
        </td>
    </tr>`;
}

function buildNoteBannerRow(row) {
    var content = [row.IR_ps, row.ERP_note].filter(Boolean).join('　/　');
    return `<tr class="ir-note-banner-row" data-ir-id="${row.IR_id}">
        <td class="col-check">
            <input type="checkbox" class="ir-batch-check" value="${row.IR_id}" onclick="onBatchCheck()">
        </td>
        <td colspan="11" style="padding:0;">
            <div class="ir-note-banner">
                <i class="fa fa-sticky-note-o" style="color:#F59E0B;font-size:15px;flex-shrink:0;"></i>
                <span style="background:#FEF3C7;color:#92400E;font-weight:700;font-size:11px;padding:1px 8px;border-radius:10px;border:1px solid #FCD34D;white-space:nowrap;flex-shrink:0;">${row.return_type_name || '備註'}</span>
                <span style="color:#9CA3AF;font-size:11px;white-space:nowrap;flex-shrink:0;">${row.IR_no}&nbsp;·&nbsp;${row.IR_date}&nbsp;·&nbsp;${row.Client_Name || '-'}&nbsp;·&nbsp;${row.d_id || '-'}</span>
                <span style="flex:1;color:#374151;font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${content.replace(/"/g,'&quot;')}">${content || '（無內容）'}</span>
                ${row.assignee_name ? `<span style="color:#9CA3AF;font-size:11px;white-space:nowrap;flex-shrink:0;"><i class="fa fa-user-o"></i>&nbsp;${row.assignee_name}</span>` : ''}
            </div>
        </td>
    </tr>`;
}

function injectNoteBanners() {
    $('.ir-note-banner-row').remove();
    Object.keys(currentNoteGroupMap).forEach(function(key) {
        var group = currentNoteGroupMap[key];
        var $anchor = group.anchorId
            ? $('#irTable tbody tr[data-ir-id="' + group.anchorId + '"]')
            : null;

        if ($anchor && $anchor.length) {
            // 在 anchor 行後面依序插入備註橫條
            var $after = $anchor;
            group.notes.forEach(function(nr) {
                var $el = $(buildNoteBannerRow(nr));
                $after.after($el);
                $after = $el;
            });
        } else if (!group.anchorId) {
            // 無對應一般行（孤立備註）→ 直接加在 tbody 末尾
            group.notes.forEach(function(nr) {
                $('#irTable tbody').append(buildNoteBannerRow(nr));
            });
        }
        // anchorId 存在但不在當前頁：等翻到該頁再顯示
    });
}

function checkEnter(e, id, el) {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); saveProgress(id, el); }
}
function saveProgress(id, el) {
    $.post(IR_API, { action: 'update_progress', ir_id: id, note: el.value }, function(res) {
        if (res.success) {
            el.style.backgroundColor = '#dff0d8';
            var info = (res.modifier||'') + ' ' + (res.time||'');
            if (!el.value) info += ' (刪除)';
            $('#progress_info_' + id).text(info);
            var item = allIRData.find(i => i.IR_id == id);
            if (item) { item.progress_note = el.value; item.modifier_name = res.modifier; item.Modified_At_Str = res.time; }
            setTimeout(function(){ el.style.backgroundColor = 'transparent'; }, 2000);
        }
    }, 'json');
}

// ── 品質異常單 ──────────────────────────────────────────────
function openCreateQAModal(irId, irNo) {
    $('#qa_ir_id').val(irId);
    $('#qa_edit_id').val('');
    $('#qaModalTitle').text('開立品質異常單 — ' + irNo);
    $('#qa_save_btn').attr('onclick', 'saveQA()').text('確認開立');
    resetQAForm();

    qaTempKey = 'tmp_' + Math.random().toString(36).substr(2, 9) + Date.now();

    $.post(QA_API, { action: 'get_next_no' }, function(res) {
        if (res.success) $('#qa_no').val(res.no);
    }, 'json');

    $('#qa_found_unit').val('客退');
    $('#qa_occurrence_date').val(new Date().toISOString().split('T')[0]);

    loadQADeptContainer('#qa_dept_container', {});

    // 若關閉時未儲存則清除暫存附件
    $('#qaModal').off('hidden.bs.modal.qacleanup').on('hidden.bs.modal.qacleanup', function() {
        if (qaTempKey) {
            $.post(QA_API, { action: 'cleanup_temp_attachments', temp_key: qaTempKey });
            qaTempKey = '';
        }
    });

    $('#qaModal').modal('show');
}

function openEditQAModal() {
    var orderId = parseInt($('#qa_detail_order_id').val());
    if (!orderId) return;

    $('#ncrDetailModal, #qaDetailModal').modal('hide');

    $.post(QA_API, { action: 'get_detail', id: orderId }, function(res) {
        if (!res.success) { alert('載入失敗'); return; }
        var d = res.data;

        $('#qa_ir_id').val(d.source_id);
        $('#qa_edit_id').val(orderId);
        $('#qaModalTitle').text('編輯品質異常單 — ' + d.abnormal_order_no);
        $('#qa_save_btn').attr('onclick', 'saveQAEdit()').text('儲存修改');

        $('#qa_no').val(d.abnormal_order_no);
        $('#qa_abnormal_type').val(d.abnormal_type_id || '');
        $('#qa_occurrence_date').val(d.occurrence_date || '');
        $('#qa_sqty').val(d.sqty || '');
        $('#qa_found_unit').val(d.found_unit || '');
        $('#qa_phenomenon').val(d.abnormal_phenomenon || '');
        $('input[name="qa_defect_category"]').prop('checked', false);
        if (d.defect_category) $('input[name="qa_defect_category"][value="' + d.defect_category + '"]').prop('checked', true);
        $('#qa_defect_detail').val(d.defect_detail || '');
        $('#qa_ps').val(d.qa_ps || '');
        $('#qa_disposition_note').val(d.disposition_note || '');

        // 處置方式
        $('input[name="qa_disposition"]').prop('checked', false);
        if (d.disposition) {
            d.disposition.split(',').forEach(function(v) {
                $('input[name="qa_disposition"][value="' + v.trim() + '"]').prop('checked', true);
            });
        }

        // BOM
        $('#qa_bom_no').val(d.bom_no || '');
        if (d.bom_no) {
            var preselectedFids = [];
            try { preselectedFids = JSON.parse(d.bom_process_fids || '[]'); } catch(e) {}
            queryBomProcessesForEdit(d.bom_no, preselectedFids);
        }

        // 責任單位
        var rt = d.responsible_type || '';
        $('input[name="qa_resp_type"][value="' + rt + '"]').prop('checked', true);
        onRespTypeChange(rt);
        if (rt === 'vendor') {
            if (d.responsible_vendor_id) {
                $('#qa_resp_vendor_id').val(d.responsible_vendor_id);
                $('#qa_resp_vendor_search').val(d.vendor_name || d.responsible_unit || '');
            }
        } else if (rt === 'dept') {
            loadAllDeptsForResp(function() {
                $('#qa_resp_dept_select').val(d.responsible_dept_id || '');
                if (d.responsible_dept_id) {
                    onRespDeptChange(function() {
                        $('#qa_resp_person_select').val(d.responsible_person_id || '');
                    });
                }
            });
        }

        // 附件
        qaTempKey = '';
        qaAttachments = { qa_ps: [], phenomenon: [], defect_detail: [] };
        if (d.attachments && d.attachments.length) {
            d.attachments.forEach(function(a) {
                if (qaAttachments[a.field_type]) qaAttachments[a.field_type].push({ id: a.id, file_name: a.file_name });
            });
        }
        renderAllAttachLists();

        // 建立 flow map 供部門 container 比對
        var existingFlows = {};
        if (d.flow) d.flow.forEach(f => existingFlows[f.dept_id] = f);
        loadQADeptContainer('#qa_dept_container', existingFlows);

        $('#qaModal').modal('show');
    }, 'json');
}

function resetQAForm() {
    $('#qa_no,#qa_sqty,#qa_occurrence_date,#qa_phenomenon,#qa_defect_detail,#qa_ps,#qa_disposition_note').val('');
    $('#qa_abnormal_type,#qa_found_unit').val('');
    $('input[name="qa_defect_category"],input[name="qa_disposition"]').prop('checked', false);
    $('#qa_dept_container').empty();
    // 重置BOM
    $('#qa_bom_no').val('');
    $('#qa_bom_process_container').hide();
    $('#qa_bom_process_list').empty();
    $('#qa_bom_clear_btn').hide();
    // 重置責任單位
    $('input[name="qa_resp_type"][value=""]').prop('checked', true);
    $('#qa_resp_vendor_ui,#qa_resp_dept_ui').hide();
    $('#qa_resp_vendor_id').val('');
    $('#qa_resp_vendor_search').val('');
    $('#qa_resp_vendor_select').hide().empty();
    $('#qa_resp_vendor_search_wrap').show();
    $('#qa_resp_dept_select').val('');
    $('#qa_resp_person_select').empty().append('<option value="">選擇人員(選填)</option>');
    // 重置附件
    qaAttachments = { qa_ps: [], phenomenon: [], defect_detail: [] };
    renderAllAttachLists();
}

function saveQA() {
    var data = buildQAFormData();
    data.action = 'create';
    data.source_type = 'IR';
    data.source_id   = $('#qa_ir_id').val();

    var btn = $('#qa_save_btn').prop('disabled', true).text('儲存中...');
    $.post(QA_API, data, function(res) {
        btn.prop('disabled', false).text('確認開立');
        if (res.success) {
            qaTempKey = ''; // 防止 cleanup 刪除已儲存的附件
            showToast('異常單 ' + res.no + ' 已建立', true);
            $('#qaModal').modal('hide');
            loadIRList();
        } else {
            alert('建立失敗：' + (res.message || ''));
        }
    }, 'json');
}

function saveQAEdit() {
    var data = buildQAFormData();
    data.action = 'update';
    data.id     = $('#qa_edit_id').val();

    var btn = $('#qa_save_btn').prop('disabled', true).text('儲存中...');
    $.post(QA_API, data, function(res) {
        btn.prop('disabled', false).text('儲存修改');
        if (res.success) {
            qaTempKey = '';
            showToast('修改成功', true);
            $('#qaModal').modal('hide');
            openQADetailModal($('#qa_ir_id').val());
            loadIRList();
        } else {
            alert('修改失敗：' + (res.message || ''));
        }
    }, 'json');
}

function buildQAFormData() {
    var depts = [];
    $('#qa_dept_container .qa-dept-check:checked').each(function() {
        var deptId = $(this).val();
        var $row   = $(this).closest('.row');
        depts.push({
            dept_id: deptId,
            mode:    $row.find('.qa-dept-mode').val() || 0,
            user_id: $row.find('.qa-dept-user-select').val() || ''
        });
    });

    var disposition = [];
    $('input[name="qa_disposition"]:checked').each(function() { disposition.push($(this).val()); });

    // 責任單位
    var respType     = $('input[name="qa_resp_type"]:checked').val() || '';
    var respVendorId = '';
    var respDeptId   = '';
    var respPersonId = '';
    var respUnit     = '';

    if (respType === 'vendor') {
        if ($('#qa_resp_vendor_select').is(':visible')) {
            respVendorId = $('#qa_resp_vendor_select').val() || '';
            respUnit     = $('#qa_resp_vendor_select option:selected').text();
        } else {
            respVendorId = $('#qa_resp_vendor_id').val() || '';
            respUnit     = $('#qa_resp_vendor_search').val();
        }
    } else if (respType === 'dept') {
        respDeptId   = $('#qa_resp_dept_select').val() || '';
        respPersonId = $('#qa_resp_person_select').val() || '';
        respUnit     = $('#qa_resp_dept_select option:selected').text();
        if (respPersonId) respUnit += ' / ' + $('#qa_resp_person_select option:selected').text();
    }

    // BOM製程
    var procFids = [];
    $('.bom-proc-check:checked').each(function() { procFids.push($(this).val()); });

    return {
        abnormal_order_no:    $('#qa_no').val(),
        occurrence_date:      $('#qa_occurrence_date').val(),
        responsible_unit:     respUnit,
        responsible_type:     respType,
        responsible_vendor_id: respVendorId,
        responsible_dept_id:  respDeptId,
        responsible_person_id: respPersonId,
        found_unit:           $('#qa_found_unit').val(),
        abnormal_phenomenon:  $('#qa_phenomenon').val(),
        abnormal_type_id:     $('#qa_abnormal_type').val(),
        defect_category:      $('input[name="qa_defect_category"]:checked').val() || '',
        defect_detail:        $('#qa_defect_detail').val(),
        disposition:          disposition.join(','),
        disposition_note:     $('#qa_disposition_note').val(),
        qa_ps:                $('#qa_ps').val(),
        sqty:                 $('#qa_sqty').val(),
        departments:          JSON.stringify(depts),
        bom_no:               $('#qa_bom_no').val().trim(),
        bom_process_fids:     JSON.stringify(procFids),
        temp_key:             qaTempKey
    };
}

// ── 品質異常單詳情 ───────────────────────────────────────────
function openQADetailModal(irId) {
    $('#qa_detail_order_id').val('');
    $('#qa_flow_tbody').html('<tr><td colspan="6" class="text-center text-muted"><i class="fa fa-spinner fa-spin"></i> 載入中...</td></tr>');
    $('#qa_detail_info').empty();

    $.post(QA_API, { action: 'get_by_source', source_type: 'IR', source_id: irId }, function(res) {
        if (!res.success || !res.data.length) { alert('找不到異常單'); return; }
        var orderId = res.data[0].id; // 取最新一筆
        $('#qa_detail_order_id').val(orderId);
        loadQADetail(orderId);
        $('#qaDetailModal').modal('show');
    }, 'json');
}

function loadQADetail(orderId) {
    $.post(QA_API, { action: 'get_detail', id: orderId }, function(res) {
        if (!res.success) { alert('載入失敗'); return; }
        var d = res.data;

        // 資訊卡
        var m5t = d.defect_category ? ('<span class="label label-default">' + d.defect_category + '</span>') : '-';
        var disp = d.disposition ? d.disposition.split(',').map(v=>`<span class="label label-primary">${v.trim()}</span>`).join(' ') : '-';
        $('#qa_detail_info').html(`
            <div class="col-md-6">
                <table class="table table-condensed" style="font-size:13px;">
                    <tr><th style="width:35%;">異常單號</th><td><strong>${d.abnormal_order_no}</strong></td></tr>
                    <tr><th>異常種類</th><td>${d.abnormal_type_name||'-'}</td></tr>
                    <tr><th>發生日期</th><td>${d.occurrence_date||'-'}</td></tr>
                    <tr><th>異常數量</th><td>${d.sqty||'-'}</td></tr>
                    <tr><th>責任單位</th><td>${d.responsible_unit||'-'}</td></tr>
                    <tr><th>發現單位</th><td>${d.found_unit||'-'}</td></tr>
                    ${d.bom_no ? `<tr><th>BOM號碼</th><td>${d.bom_no}</td></tr>` : ''}
                </table>
            </div>
            <div class="col-md-6">
                <table class="table table-condensed" style="font-size:13px;">
                    <tr><th style="width:35%;">5M+T分類</th><td>${m5t}</td></tr>
                    <tr><th>原因說明</th><td>${d.defect_detail||'-'}</td></tr>
                    <tr><th>處置方式</th><td>${disp}</td></tr>
                    <tr><th>處置說明</th><td>${d.disposition_note||'-'}</td></tr>
                    <tr><th>品管備註</th><td>${d.qa_ps||'-'}</td></tr>
                    <tr><th>建單人</th><td>${d.created_by_name||'-'}</td></tr>
                </table>
            </div>
            <div class="col-md-12" style="margin-top:-10px;">
                <div class="well well-sm" style="min-height:40px;">
                    <strong>異常現象：</strong>${d.abnormal_phenomenon||'(未填)'}
                </div>
            </div>
        `);

        // 流程表
        renderQAFlowTable(d.flow || [], orderId);
        // 通知回覆狀態（唯讀）
        loadQANotifyStatus(orderId);
    }, 'json');
}

// ── 通知回覆/回簽狀態（唯讀，資料來自公告通知系統 live_event） ──
function loadQANotifyStatus(orderId) {
    $('#qa_notify_status_area').hide();
    $.post(QA_API, { action: 'get_notify_status', abnormal_order_id: orderId }, function(res) {
        if (!res.success || !res.event) return;
        var esc = function(s){ return $('<i>').text(s==null?'':s).html(); };
        var modeMap = { read:'已閱', sign:'回簽', reply:'回覆+回簽' };
        var targets = (res.targets||[]).map(function(t){
            return '<span class="label label-info" style="margin-right:4px;display:inline-block;margin-bottom:2px;">' + esc(t.target_name||'') + '（' + (modeMap[t.mode]||t.mode) + '）</span>';
        }).join('');
        var rows = (res.responses||[]).map(function(r){
            var st = r.replied_at ? ('已回覆 ' + r.replied_at) : (r.signed_at ? ('已回簽 ' + r.signed_at) : (r.read_at ? ('已閱 ' + r.read_at) : '未處理'));
            return '<tr><td>' + esc(r.user_cname||('#'+r.user_id)) + '</td><td>' + esc(st) + '</td><td style="white-space:pre-wrap;">' + esc(r.reply_content||'') + '</td></tr>';
        }).join('');
        var followers = (res.followers||[]).map(function(f){ return esc(f.user_cname||''); }).join('、');
        var h = '<div style="margin-bottom:6px;"><strong>通知對象：</strong>' + (targets||'-') + '</div>';
        if (followers) h += '<div style="margin-bottom:6px;"><strong>追蹤人員：</strong>' + followers + '</div>';
        if (res.event.reply_deadline) h += '<div style="margin-bottom:6px;"><strong>回覆期限：</strong>' + esc(res.event.reply_deadline) + '</div>';
        h += '<table class="table table-bordered table-condensed" style="font-size:13px;">'
           + '<thead><tr><th style="width:15%;">人員</th><th style="width:22%;">狀態</th><th>回覆內容</th></tr></thead>'
           + '<tbody>' + (rows||'<tr><td colspan="3" class="text-center text-muted">尚無回應</td></tr>') + '</tbody></table>';
        $('#qa_notify_status_body').html(h);
        $('#qa_notify_status_area').show();
    }, 'json');
}

function renderQAFlowTable(flows, irId) {
    var lastFlowIdx = {};
    flows.forEach(function(f, i) { lastFlowIdx[f.dept_id] = i; });

    var html = '';
    if (!flows.length) {
        html = '<tr><td colspan="6" class="text-center text-muted">尚無流程</td></tr>';
    }

    flows.forEach(function(f, idx) {
        var isReturned = f.status === 'Returned';
        var isReceived = f.status === 'Received';
        var statusBadge = isReturned
            ? '<span class="flow-status-badge returned">已歸還</span>'
            : (isReceived
                ? '<span class="flow-status-badge received">處理中</span>'
                : '<span class="flow-status-badge pending">待送交</span>');

        var sendTime   = (isReceived||isReturned) ? (f.receive_date||'-') : '-';
        var returnTime = isReturned ? (f.return_date||'-') : '-';
        var designee   = f.receiver_name || '-';
        var replyHtml  = '';
        var actionHtml = '';

        if (!isReturned) {
            if (!isReceived) {
                if (f.receiver_name) {
                    designee = f.receiver_name;
                    actionHtml = `<button class="btn btn-xs btn-primary" onclick="qaFlowReceive(${f.flow_id},${irId})">送交</button>`;
                } else {
                    designee = `<select class="form-control input-sm qa-flow-user-select" id="qa_flow_user_${f.flow_id}" style="min-width:100px;"><option value="">載入中...</option></select>`;
                    loadQAFlowUsers(f.dept_id, f.flow_id, f.include_mode);
                    actionHtml = `<button class="btn btn-xs btn-primary" onclick="qaFlowReceive(${f.flow_id},${irId})">送交</button>`;
                }
                replyHtml = '<span class="text-muted">待送交</span>';
            } else {
                replyHtml = `<small class="text-muted" style="display:block;margin-bottom:2px;">Enter 自動儲存</small>
                    <textarea class="form-control input-sm" id="qa_flow_reply_${f.flow_id}" rows="2"
                    onkeydown="checkQAReplyEnter(event,${f.flow_id},this)">${f.reply_content||''}</textarea>`;
                actionHtml = `<button class="btn btn-xs btn-success" onclick="qaFlowReturn(${f.flow_id},${irId})">歸還品管</button>
                    <button class="btn btn-xs btn-danger" style="margin-left:4px;" onclick="qaFlowRollback(${f.flow_id},'Pending',${irId})">退回</button>`;
            }
        } else {
            replyHtml = `<div style="white-space:pre-wrap;max-height:60px;overflow-y:auto;">${f.reply_content||'(無回覆)'}</div>`;
            actionHtml = `<button class="btn btn-xs btn-default" disabled>已歸還</button>
                <button class="btn btn-xs btn-danger" style="margin-left:4px;" onclick="qaFlowRollback(${f.flow_id},'Received',${irId})">退回</button>`;
            if (lastFlowIdx[f.dept_id] === idx) {
                actionHtml += ` <button class="btn btn-xs btn-warning" style="margin-left:4px;" onclick="qaFlowResend(${f.flow_id},${irId})">再次送交</button>`;
            }
        }

        html += `<tr>
            <td>${f.dept_name}</td>
            <td>${designee}</td>
            <td>${statusBadge}<br><small>${sendTime}</small></td>
            <td>${replyHtml}</td>
            <td><small>${returnTime}</small></td>
            <td>${actionHtml}</td>
        </tr>`;
    });

    $('#qa_flow_tbody').html(html);
}

function loadQAFlowUsers(deptId, flowId, mode) {
    mode = mode || 0;
    var cacheKey = deptId + '_' + mode;
    var populate = function(users) {
        var sel = $('#qa_flow_user_' + flowId).empty().append('<option value="">請選擇人員</option>');
        (users||[]).forEach(function(u) {
            var pos = u.position_name ? ' ' + u.position_name : '';
            sel.append(`<option value="${u.id}">${u.user_cname}${pos}</option>`);
        });
    };
    if (deptUsersCache[cacheKey]) { populate(deptUsersCache[cacheKey]); return; }
    $.post(QA_API, { action: 'get_dept_users', dept_id: deptId, mode: mode }, function(res) {
        if (res.success) { deptUsersCache[cacheKey] = res.data; populate(res.data); }
    }, 'json');
}

function qaFlowReceive(flowId, irId) {
    var sel = $('#qa_flow_user_' + flowId);
    var userId = sel.length ? sel.val() : null;
    if (sel.length && !userId) { alert('請選擇送交對象'); return; }
    $.post(QA_API, { action: 'flow_receive', flow_id: flowId, target_user_id: userId || '' }, function(res) {
        if (res.success) { loadQADetail($('#qa_detail_order_id').val()); loadIRList(); }
        else alert('操作失敗');
    }, 'json');
}

function qaFlowReturn(flowId, irId) {
    var content = $('#qa_flow_reply_' + flowId).val();
    if (!content && !confirm('未填寫回覆，確定歸還？')) return;
    $.post(QA_API, { action: 'flow_return', flow_id: flowId, reply_content: content }, function(res) {
        if (res.success) { loadQADetail($('#qa_detail_order_id').val()); loadIRList(); }
        else alert('操作失敗');
    }, 'json');
}

function qaFlowRollback(flowId, target, irId) {
    if (!confirm('確定要退回狀態？')) return;
    $.post(QA_API, { action: 'flow_rollback', flow_id: flowId, target_status: target }, function(res) {
        if (res.success) { loadQADetail($('#qa_detail_order_id').val()); loadIRList(); }
        else alert('操作失敗：' + (res.message||''));
    }, 'json');
}

function qaFlowResend(flowId, irId) {
    if (!confirm('確定再次送交給此部門？')) return;
    $.post(QA_API, { action: 'flow_resend', flow_id: flowId }, function(res) {
        if (res.success) { loadQADetail($('#qa_detail_order_id').val()); loadIRList(); }
        else alert('操作失敗');
    }, 'json');
}

function checkQAReplyEnter(e, flowId, el) {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        $.post(QA_API, { action: 'flow_save_reply', flow_id: flowId, reply_content: el.value }, function(res) {
            if (res.success) {
                el.style.backgroundColor = '#dff0d8';
                setTimeout(function(){ el.style.backgroundColor = ''; }, 1000);
            }
        }, 'json');
    }
}

// ── 部門 Container（開立/編輯共用）─────────────────────────
function loadQADeptContainer(selector, existingFlows) {
    $.post(QA_API, { action: 'get_dept_config' }, function(res) {
        var container = $(selector).empty();
        if (!res.success || !res.config.length) {
            container.html('<p class="text-danger">尚未設定可用部門，請至「品管合併檢驗頁 → 設定 → 異常單回覆部門設定」配置。</p>');
            return;
        }
        var configMap = {};
        res.config.forEach(c => configMap[c.id] = c.mode);

        allDepts.forEach(function(d) {
            if (!configMap.hasOwnProperty(d.id) && !existingFlows.hasOwnProperty(d.id)) return;
            var defaultMode = configMap[d.id] !== undefined ? configMap[d.id] : 0;
            var flow = existingFlows[d.id];
            var isChecked  = !!flow;
            var isDisabled = !!(flow && flow.return_date);
            var note = isDisabled ? ' (已回覆，不可移除)' : '';
            var currentMode = flow ? (flow.include_mode||0) : defaultMode;
            var currentUser = flow ? (flow.user_id||null) : null;

            var html = `
                <div class="form-group row" style="margin-bottom:5px;border-bottom:1px solid #f0f0f0;padding:5px 0;">
                    <div class="col-xs-4">
                        <label style="font-weight:normal;">
                            <input type="checkbox" class="qa-dept-check" value="${d.id}" ${isChecked?'checked':''} ${isDisabled?'disabled':''}>
                            ${d.name}${note}
                        </label>
                    </div>
                    <div class="col-xs-4">
                        <select class="form-control input-sm qa-dept-mode" style="display:${isChecked?'block':'none'};" ${isDisabled?'disabled':''}>
                            <option value="0" ${currentMode==0?'selected':''}>本部門</option>
                            <option value="1" ${currentMode==1?'selected':''}>含下級部門</option>
                            <option value="2" ${currentMode==2?'selected':''}>僅下級主管</option>
                        </select>
                    </div>
                    <div class="col-xs-4" id="qa_user_container_${d.id}" style="display:${isChecked?'block':'none'};">
                        <select class="form-control input-sm qa-dept-user-select" id="qa_user_${d.id}" ${isDisabled?'disabled':''}>
                            <option value="">指定人員(選填)</option>
                        </select>
                    </div>
                </div>`;
            container.append(html);

            if (isChecked || configMap.hasOwnProperty(d.id)) {
                loadQADeptUsers(d.id, currentMode, currentUser);
            }
        });

        bindQADeptEvents(selector);
    }, 'json');
}

function bindQADeptEvents(selector) {
    $(selector + ' .qa-dept-check').off('change').on('change', function() {
        var deptId = $(this).val();
        var $row = $(this).closest('.row');
        if (this.checked) {
            $row.find('.qa-dept-mode, #qa_user_container_' + deptId).show();
            loadQADeptUsers(deptId, $row.find('.qa-dept-mode').val(), null);
        } else {
            $row.find('.qa-dept-mode, #qa_user_container_' + deptId).hide();
        }
    });
    $(selector + ' .qa-dept-mode').off('change').on('change', function() {
        var deptId = $(this).closest('.row').find('.qa-dept-check').val();
        loadQADeptUsers(deptId, $(this).val(), null);
    });
}

function loadQADeptUsers(deptId, mode, selectedUserId) {
    var cacheKey = deptId + '_' + (mode||0);
    var populate = function(users) {
        var sel = $('#qa_user_' + deptId).empty().append('<option value="">指定人員(選填)</option>');
        (users||[]).forEach(function(u) {
            var pos = u.position_name ? u.position_name : '';
            var ms  = (u.is_main==0) ? '(兼)' : '';
            sel.append(`<option value="${u.id}">${u.user_cname} ${pos}${ms}</option>`);
        });
        if (selectedUserId) sel.val(selectedUserId);
    };
    if (deptUsersCache[cacheKey]) { populate(deptUsersCache[cacheKey]); return; }
    $.post(QA_API, { action: 'get_dept_users', dept_id: deptId, mode: mode||0 }, function(res) {
        if (res.success) { deptUsersCache[cacheKey] = res.data; populate(res.data); }
    }, 'json');
}

// ── 部門設定 ────────────────────────────────────────────────
function loadAllDepts() {
    $.post(QA_API, { action: 'get_all_depts' }, function(res) {
        if (res.success) allDepts = res.data;
    }, 'json');
}

/* openDeptConfigModal / saveDeptConfig 已移至 品管合併檢驗頁（inspection_combined_prototype.php 設定選單→異常單回覆部門設定）2026-07-06 */

// ── 新增退貨單 ───────────────────────────────────────────────
function openNewIRModal() {
    $('#newIRForm')[0].reset();
    $('#client_container').html('<input type="text" class="form-control" name="client" id="client_input" placeholder="自動帶入或選擇">');
    $('#d_id').val('');
    $('#part_search').val('');
    $('#ir_date_input').val(new Date().toISOString().split('T')[0]);
    generateIRPrefix();
    $('#newIRModal').modal('show');
}

function generateIRPrefix() {
    var d = new Date();
    var roc = d.getFullYear() - 1911;
    var mm  = ('0'+(d.getMonth()+1)).slice(-2);
    var dd  = ('0'+d.getDate()).slice(-2);
    $('#ir_no').val('IR' + roc + mm + dd);
}

function saveIR() {
    var ir_no  = $('#ir_no').val();
    var d_id   = $('#d_id').val();
    var qty    = $('[name="qty"]').val();
    var ir_date = $('[name="ir_date"]').val();

    var d = new Date(); var roc = d.getFullYear()-1911;
    var mm = ('0'+(d.getMonth()+1)).slice(-2); var dd = ('0'+d.getDate()).slice(-2);
    if (ir_no === 'IR'+roc+mm+dd) { alert('請輸入完整退貨單號 (需包含流水號)'); return; }

    var missing = [];
    if (!ir_no)   missing.push('退貨單號');
    if (!ir_date) missing.push('退貨日期');
    if (!d_id)    missing.push('退貨料號');
    if (!qty)     missing.push('退貨數量');
    if (missing.length) { alert('請填寫：' + missing.join(', ')); return; }

    $.post(IR_API, {
        action: 'save_ir',
        ir_no:         ir_no,
        d_id:          d_id,
        client:        $('[name="client"]').val(),
        qty:           qty,
        reason:        $('[name="reason"]').val(),
        ir_date:       ir_date,
        c_ir:          $('[name="c_ir"]').val(),
        sale_assignee: $('[name="sale_assignee"]').val(),
        departments:   '[]'
    }, function(res) {
        if (res.success) { showToast('新增成功', true); $('#newIRModal').modal('hide'); loadIRList(); }
        else showToast('新增失敗：' + res.message, false);
    }, 'json');
}

function loadSalesUsers() {
    $.post(IR_API, { action: 'get_sales_users' }, function(res) {
        if (!res.success) return;
        // 新增表單下拉
        var sel = $('#sale_assignee_select').empty().append('<option value="">請選擇</option>');
        // 篩選下拉
        var filterSel = $('#filter-assignee').empty().append('<option value="">所有負責業務</option>');
        // 批次下拉
        var batchSel = $('#ir-batch-assignee-select').empty().append('<option value="">-- 清除業務 --</option>');
        res.data.forEach(function(u) {
            var label = u.user_cname + (u.position_name ? ' (' + u.position_name + ')' : '');
            sel.append(`<option value="${u.id}">${label}</option>`);
            filterSel.append(`<option value="${u.id}">${u.user_cname}</option>`);
            batchSel.append(`<option value="${u.id}">${u.user_cname}</option>`);
        });
    }, 'json');
}

$('#part_search').on('input', function() {
    var kw = $(this).val();
    if (kw.length < 2) { $('#part-suggestions').hide(); return; }
    $.post(IR_API, { action: 'get_part_data', keyword: kw }, function(res) {
        if (res.success && res.data.length) {
            var html = '';
            res.data.forEach(function(item) {
                html += `<div class="suggestion-item" onclick="selectPart('${item.d_id}','${item.D_Setting_Id}','${item.Client_Name||''}','${item.sales_id||''}')">
                    ${item.D_Setting_Id} - ${item.Client_Name||'無客戶'}
                </div>`;
            });
            $('#part-suggestions').html(html).show();
        } else { $('#part-suggestions').hide(); }
    }, 'json');
});
$('#part_search').on('dblclick', function() {
    $(this).val(''); $('#d_id').val(''); $('#client_input').val(''); $('#sale_assignee_select').val(''); $('#part-suggestions').hide();
});
function selectPart(did, dSettingId, clientName, salesId) {
    $('#d_id').val(did); $('#part_search').val(dSettingId); $('#part-suggestions').hide();
    $('#client_container').html(`<input type="text" class="form-control" name="client" value="${clientName}" readonly>`);
    if (salesId) $('#sale_assignee_select').val(salesId);
}

// ── 異常種類管理 ─────────────────────────────────────────────
function loadAbnormalTypes(selectedId) {
    $.post(QA_API, { action: 'get_abnormal_types' }, function(res) {
        var sel = $('#qa_abnormal_type').empty().append('<option value="">請選擇異常種類...</option>');
        if (res.success) {
            res.data.forEach(function(t) { sel.append(`<option value="${t.type_id}">${t.type_name}</option>`); });
            if (selectedId) sel.val(selectedId);
        }
    }, 'json');
}

function openAbnormalTypeManageModal() {
    loadAbnormalTypesList();
    $('#abnormalTypeManageModal').modal('show');
}
function loadAbnormalTypesList() {
    $.post(QA_API, { action: 'manage_abnormal_type', sub_action: 'get_all' }, function(res) {
        var tbody = $('#abnormal_type_list_tbody').empty();
        if (res.success) {
            res.data.forEach(function(t) {
                var btn = t.is_active==1
                    ? `<button class="btn btn-xs btn-success" onclick="toggleAbnormalType(${t.type_id},'${t.type_name}',0)">啟用中</button>`
                    : `<button class="btn btn-xs btn-default" onclick="toggleAbnormalType(${t.type_id},'${t.type_name}',1)">停用中</button>`;
                tbody.append(`<tr><td>${t.type_name}</td><td>${btn}</td><td><button class="btn btn-xs btn-danger" onclick="deleteAbnormalType(${t.type_id})"><i class="fa fa-trash"></i></button></td></tr>`);
            });
        }
    }, 'json');
}
function addAbnormalType() {
    var name = $('#new_type_name').val(); if (!name) return;
    $.post(QA_API, { action: 'manage_abnormal_type', sub_action: 'add', name: name }, function() {
        $('#new_type_name').val(''); loadAbnormalTypesList(); loadAbnormalTypes();
    }, 'json');
}
function toggleAbnormalType(id, name, active) {
    $.post(QA_API, { action: 'manage_abnormal_type', sub_action: 'update', id: id, name: name, active: active }, function() {
        loadAbnormalTypesList(); loadAbnormalTypes();
    }, 'json');
}
function deleteAbnormalType(id) {
    if (!confirm('確定刪除？')) return;
    $.post(QA_API, { action: 'manage_abnormal_type', sub_action: 'delete', id: id }, function() {
        loadAbnormalTypesList(); loadAbnormalTypes();
    }, 'json');
}

// ── 退貨性質管理 ─────────────────────────────────────────────
var allReturnTypes = [];

function loadReturnTypes(callback) {
    $.post(IR_API, { action: 'get_ir_return_types' }, function(res) {
        if (!res.success) return;
        allReturnTypes = res.data;

        var tbody = $('#returnTypeTable tbody').empty();
        res.data.forEach(function(t) {
            var noteBadge = t.is_note == 1
                ? '<span class="ir-type-badge is-note"><i class="fa fa-sticky-note-o"></i> 備註</span>'
                : '<span style="color:#bbb;">-</span>';
            var ncrBadge = t.allow_ncr == 1
                ? '<span class="label label-success" style="font-size:10px;">允許</span>'
                : '<span class="label label-default" style="font-size:10px;">禁止</span>';
            var statusBadge = t.is_active == 1
                ? '<span class="label label-primary" style="font-size:10px;">啟用</span>'
                : '<span class="label label-default" style="font-size:10px;">停用</span>';
            tbody.append(`<tr>
                <td><strong>${t.type_name}</strong></td>
                <td style="color:#888;font-size:12px;">${t.description || '-'}</td>
                <td style="text-align:center;">${noteBadge}</td>
                <td style="text-align:center;">${ncrBadge}</td>
                <td style="text-align:center;">${t.sort_order}</td>
                <td style="text-align:center;">${statusBadge}</td>
                <td style="text-align:center;">
                    <button class="btn btn-xs btn-default" onclick="editReturnType(${t.type_id})" title="編輯"><i class="fa fa-pencil"></i></button>
                    <button class="btn btn-xs btn-danger" onclick="deleteReturnType(${t.type_id})" title="刪除" style="margin-left:2px;"><i class="fa fa-trash"></i></button>
                </td>
            </tr>`);
        });

        // 更新批次下拉 + 篩選下拉
        var opts = '<option value="">-- 清除性質 --</option>';
        var filterOpts = '<option value="">所有退貨性質</option><option value="none">（無性質）</option>';
        res.data.filter(function(t){ return t.is_active == 1; }).forEach(function(t) {
            opts += `<option value="${t.type_id}">${t.type_name}</option>`;
            filterOpts += `<option value="${t.type_id}">${t.type_name}</option>`;
        });
        $('#ir-batch-type-select').html(opts);
        $('#filter-return-type').html(filterOpts);

        if (callback) callback();
    }, 'json');
}

function openReturnTypeModal() {
    loadReturnTypes();
    resetReturnTypeForm();
    $('#returnTypeModal').modal('show');
}

function editReturnType(id) {
    var t = allReturnTypes.find(function(x){ return x.type_id == id; });
    if (!t) return;
    $('#rt_id').val(t.type_id);
    $('#rt_name').val(t.type_name);
    $('#rt_desc').val(t.description || '');
    $('#rt_sort').val(t.sort_order);
    $('#rt_is_note').prop('checked', t.is_note == 1);
    $('#rt_allow_ncr').prop('checked', t.allow_ncr == 1);
    $('#rt_active').prop('checked', t.is_active == 1);
}

function resetReturnTypeForm() {
    $('#rt_id').val('');
    $('#rt_name,#rt_desc').val('');
    $('#rt_sort').val(0);
    $('#rt_is_note').prop('checked', false);
    $('#rt_allow_ncr,#rt_active').prop('checked', true);
}

function deleteReturnType(id) {
    if (!confirm('確定刪除？已使用此性質的退貨單將顯示為無性質。')) return;
    $.post(IR_API, { action: 'delete_ir_return_type', type_id: id }, function(res) {
        if (res.success) { loadReturnTypes(); loadIRList(); showToast('已刪除', true); }
        else alert('刪除失敗：' + (res.message || ''));
    }, 'json');
}

$('#returnTypeForm').on('submit', function(e) {
    e.preventDefault();
    var data = {
        action:      'save_ir_return_type',
        type_id:     $('#rt_id').val(),
        type_name:   $('#rt_name').val(),
        description: $('#rt_desc').val(),
        sort_order:  $('#rt_sort').val(),
        is_note:     $('#rt_is_note').is(':checked') ? 1 : 0,
        allow_ncr:   $('#rt_allow_ncr').is(':checked') ? 1 : 0,
        is_active:   $('#rt_active').is(':checked') ? 1 : 0
    };
    $.post(IR_API, data, function(res) {
        if (res.success) {
            showToast('儲存成功', true);
            loadReturnTypes();
            resetReturnTypeForm();
            loadIRList();
        } else { alert('儲存失敗：' + (res.message || '')); }
    }, 'json');
});

// ── 批次選取 ─────────────────────────────────────────────────
function onBatchCheck() {
    var count = $('.ir-batch-check:checked').length;
    $('#ir-batch-count').text('已選 ' + count + ' 筆');
    if (count > 0) { $('#ir-batch-bar').slideDown(180); }
    else { $('#ir-batch-bar').slideUp(180); }
}

function toggleSelectAll(cb) {
    $('.ir-batch-check').prop('checked', cb.checked);
    onBatchCheck();
}

function clearIRBatchSelection() {
    $('.ir-batch-check, #selectAll').prop('checked', false);
    $('#ir-batch-bar').slideUp(180);
}

function toggleIRStatus(irId, newStatus) {
    var msg = newStatus == 9 ? '確定將此退貨單設為結案？' : '確定重新開啟此退貨單？';
    if (!confirm(msg)) return;
    $.post(IR_API, { action: 'update_ir_status', ir_ids: JSON.stringify([irId]), status: newStatus }, function(res) {
        if (res.success) { showToast(newStatus == 9 ? '已結案' : '已重新開啟', true); loadIRList(); }
        else alert('操作失敗：' + (res.message || ''));
    }, 'json');
}

function irTableSearch(val) {
    if ($.fn.DataTable.isDataTable('#irTable')) {
        $('#irTable').DataTable().search(val).draw();
    }
}

function applyFilters() {
    currentAssigneeFilter   = $('#filter-assignee').val();
    currentReturnTypeFilter = $('#filter-return-type').val();
    renderTable();
}

function clearFilters() {
    currentAssigneeFilter = '';
    currentReturnTypeFilter = '';
    $('#filter-assignee, #filter-return-type').val('');
    $('#ir-global-search').val('');
    renderTable();
}

function submitIRBatchTypeUpdate() {
    var ids = [];
    $('.ir-batch-check:checked').each(function(){ ids.push($(this).val()); });
    if (!ids.length) return;
    var typeId   = $('#ir-batch-type-select').val();
    var typeName = typeId ? $('#ir-batch-type-select option:selected').text() : '（清除）';
    if (!confirm('確定將 ' + ids.length + ' 筆退貨單的退貨性質改為「' + typeName + '」？')) return;
    $.post(IR_API, { action: 'update_ir_return_type', ir_ids: JSON.stringify(ids), return_type_id: typeId }, function(res) {
        if (res.success) { showToast('批次修改成功', true); clearIRBatchSelection(); loadIRList(); }
        else { alert('修改失敗：' + (res.message || '')); }
    }, 'json');
}

function submitIRBatchAssigneeUpdate() {
    var ids = [];
    $('.ir-batch-check:checked').each(function(){ ids.push($(this).val()); });
    if (!ids.length) return;
    var assigneeId   = $('#ir-batch-assignee-select').val();
    var assigneeName = assigneeId ? $('#ir-batch-assignee-select option:selected').text() : '（清除）';
    if (!confirm('確定將 ' + ids.length + ' 筆退貨單的負責業務改為「' + assigneeName + '」？')) return;
    $.post(IR_API, { action: 'update_ir_assignee', ir_ids: JSON.stringify(ids), assignee_id: assigneeId }, function(res) {
        if (res.success) { showToast('批次修改成功', true); clearIRBatchSelection(); loadIRList(); }
        else { alert('修改失敗：' + (res.message || '')); }
    }, 'json');
}

function showToast(msg, ok) {
    var t = document.getElementById('custom-toast');
    t.textContent = msg;
    t.style.backgroundColor = ok ? '#26B99A' : '#d9534f';
    t.style.display = 'block';
    setTimeout(function(){ t.style.display = 'none'; }, 3000);
}

// ── BOM綁定 ───────────────────────────────────────────────────
function queryBomProcesses() {
    var bomNo = $('#qa_bom_no').val().trim();
    if (!bomNo) { alert('請輸入BOM號碼'); return; }
    $.post(QA_API, { action: 'get_bom_processes', bom_no: bomNo }, function(res) {
        if (!res.success || !res.data.length) {
            alert('查無此BOM的製程資料，請確認BOM號碼');
            return;
        }
        renderBomProcessList(res.data, []);
        $('#qa_bom_process_container').show();
        $('#qa_bom_clear_btn').show();
    }, 'json');
}

function queryBomProcessesForEdit(bomNo, preselectedFids) {
    $.post(QA_API, { action: 'get_bom_processes', bom_no: bomNo }, function(res) {
        if (!res.success || !res.data.length) return;
        renderBomProcessList(res.data, preselectedFids);
        $('#qa_bom_process_container').show();
        $('#qa_bom_clear_btn').show();
    }, 'json');
}

function renderBomProcessList(processes, preselectedFids) {
    var html = '';
    processes.forEach(function(p) {
        var vendorInfo = p.maker_name ? (' <small style="color:#888;">- ' + p.maker_name + '</small>') : '';
        var checked    = preselectedFids.indexOf(String(p.bom_ing_fid)) >= 0 ? 'checked' : '';
        html += `<label style="display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border:1px solid #ddd;border-radius:4px;cursor:pointer;font-size:13px;background:#f9f9f9;margin:2px;">
            <input type="checkbox" class="bom-proc-check" ${checked}
                   value="${p.bom_ing_fid}"
                   data-vendor-id="${p.maker_id_no || ''}"
                   data-vendor-name="${p.maker_name || ''}"
                   onchange="onBomProcChange()">
            製程${p.processing_sequence}${vendorInfo}
        </label>`;
    });
    $('#qa_bom_process_list').html(html);
    // 觸發一次以更新廠商列表
    onBomProcChange();
}

function onBomProcChange() {
    var vendors = {};
    $('.bom-proc-check:checked').each(function() {
        var vid = $(this).data('vendor-id');
        var vname = $(this).data('vendor-name');
        if (vid) vendors[vid] = vname;
    });
    var vList = Object.entries(vendors).map(function(e) { return { id: e[0], name: e[1] }; });
    if (vList.length > 0) {
        loadRespVendorFromBom(vList);
    } else {
        $('#qa_resp_vendor_select').hide().empty();
        $('#qa_resp_vendor_search_wrap').show();
    }
}

function clearBomBinding() {
    $('#qa_bom_no').val('');
    $('#qa_bom_process_container').hide();
    $('#qa_bom_process_list').empty();
    $('#qa_bom_clear_btn').hide();
    $('#qa_resp_vendor_select').hide().empty();
    $('#qa_resp_vendor_search_wrap').show();
}

// ── 責任單位 ─────────────────────────────────────────────────
function onRespTypeChange(type) {
    if (type === 'vendor') {
        $('#qa_resp_vendor_ui').show();
        $('#qa_resp_dept_ui').hide();
    } else if (type === 'dept') {
        $('#qa_resp_vendor_ui').hide();
        $('#qa_resp_dept_ui').show();
        if ($('#qa_resp_dept_select option').length <= 1) loadAllDeptsForResp();
    } else {
        $('#qa_resp_vendor_ui').hide();
        $('#qa_resp_dept_ui').hide();
    }
}

function loadRespVendorFromBom(vList) {
    var sel = $('#qa_resp_vendor_select').empty().append('<option value="">請選擇廠商</option>');
    vList.forEach(function(v) { sel.append(`<option value="${v.id}">${v.name}</option>`); });
    sel.show();
    $('#qa_resp_vendor_search_wrap').hide();
    if (vList.length === 1) {
        sel.val(vList[0].id);
        $('#qa_resp_vendor_id').val(vList[0].id);
        // 自動選廠商 radio
        $('input[name="qa_resp_type"][value="vendor"]').prop('checked', true);
        $('#qa_resp_vendor_ui').show();
        $('#qa_resp_dept_ui').hide();
    }
    sel.off('change.vendorsel').on('change.vendorsel', function() {
        $('#qa_resp_vendor_id').val($(this).val());
    });
}

function loadAllDeptsForResp(callback) {
    $.post(QA_API, { action: 'get_all_depts' }, function(res) {
        if (!res.success) return;
        var sel = $('#qa_resp_dept_select').empty().append('<option value="">選擇部門</option>');
        res.data.forEach(function(d) { sel.append(`<option value="${d.id}">${d.name}</option>`); });
        if (callback) callback();
    }, 'json');
}

function onRespDeptChange(callback) {
    var deptId = $('#qa_resp_dept_select').val();
    var sel = $('#qa_resp_person_select').empty().append('<option value="">選擇人員(選填)</option>');
    if (!deptId) { if (callback) callback(); return; }
    $.post(QA_API, { action: 'get_dept_users', dept_id: deptId, mode: 0 }, function(res) {
        if (res.success) {
            res.data.forEach(function(u) {
                var pos = u.position_name ? ' ' + u.position_name : '';
                sel.append(`<option value="${u.id}">${u.user_cname}${pos}</option>`);
            });
        }
        if (callback) callback();
    }, 'json');
}

// 廠商搜尋
function onVendorSearch(kw) {
    if (!kw) { $('#qa_vendor_suggestions').hide(); return; }
    $.post(QA_API, { action: 'search_vendors', keyword: kw }, function(res) {
        if (!res.success || !res.data.length) { $('#qa_vendor_suggestions').hide(); return; }
        var html = res.data.map(function(v) {
            return `<div class="suggestion-item" onclick="selectQAVendor('${v.maker_id_no}','${(v.maker_name||'').replace(/'/g,"\\'")}')">
                ${v.maker_name} <small style="color:#999;">${v.maker_id_no}</small>
            </div>`;
        }).join('');
        $('#qa_vendor_suggestions').html(html).show();
    }, 'json');
}

function selectQAVendor(id, name) {
    $('#qa_resp_vendor_id').val(id);
    $('#qa_resp_vendor_search').val(name);
    $('#qa_vendor_suggestions').hide();
}

$(document).on('click', function(e) {
    if (!$(e.target).closest('#qa_resp_vendor_ui').length) $('#qa_vendor_suggestions').hide();
});

// ── 附件上傳 ─────────────────────────────────────────────────
function openAttachUpload(fieldType) {
    qaCurrentAttachField = fieldType;
    $('#attach_file_input').val('').click();
}

function doUploadAttach(input) {
    if (!input.files || !input.files[0]) return;
    var formData = new FormData();
    formData.append('action', 'upload_attachment');
    formData.append('file', input.files[0]);
    formData.append('field_type', qaCurrentAttachField);

    var editId  = parseInt($('#qa_edit_id').val() || '0');
    var orderNo = editId > 0 ? ($('#qa_no').val() || '') : '';
    if (editId > 0 && orderNo) {
        formData.append('abnormal_order_id', editId);
        formData.append('abnormal_order_no', orderNo);
    } else {
        formData.append('temp_key', qaTempKey);
    }

    $.ajax({
        url: QA_API, type: 'POST', data: formData, processData: false, contentType: false,
        success: function(res) {
            if (res.success) {
                qaAttachments[qaCurrentAttachField].push({ id: res.id, file_name: res.file_name });
                renderAttachList(qaCurrentAttachField);
                showToast('附件上傳成功', true);
            } else {
                alert('附件上傳失敗：' + (res.message || ''));
            }
        },
        error: function() { alert('上傳發生錯誤'); }
    });
}

function renderAttachList(fieldType) {
    var list = qaAttachments[fieldType] || [];
    var html = list.map(function(a) {
        return `<span class="qa-attach-item">
            <i class="fa fa-file-o"></i> ${a.file_name}
            <button class="del-btn" onclick="deleteQAAttachment(${a.id},'${fieldType}')" title="刪除"><i class="fa fa-times"></i></button>
        </span>`;
    }).join('');
    $('#attachments_' + fieldType).html(html);
}

function renderAllAttachLists() {
    ['qa_ps', 'phenomenon', 'defect_detail'].forEach(renderAttachList);
}

function deleteQAAttachment(id, fieldType) {
    if (!confirm('確定刪除此附件？')) return;
    $.post(QA_API, { action: 'delete_attachment', id: id }, function(res) {
        if (res.success) {
            qaAttachments[fieldType] = qaAttachments[fieldType].filter(function(a) { return a.id !== id; });
            renderAttachList(fieldType);
        } else { alert('刪除失敗'); }
    }, 'json');
}

// ── 業務進度記錄 Modal ────────────────────────────────────
var ipmNewNoteFiles = [];

function openIrProgressModal(irId, irNo, tab) {
    $('#ipm-ir-id').val(irId);
    $('#ipm-ir-no').val(irNo);
    $('#ipmTitle').html('<i class="fa fa-comments"></i> 業務進度記錄 — ' + irNo);
    $('#ipm-new-note').val('');
    ipmNewNoteFiles = [];
    ipmRenderNewNoteFilesPreview();

    if (tab === 'attach') {
        $('#ipm-li-notes').removeClass('active');
        $('#ipm-li-attach').addClass('active');
        $('#ipm-tab-notes').removeClass('active');
        $('#ipm-tab-attach').addClass('active');
        ipmLoadIrAttachments();
    } else {
        $('#ipm-li-notes').addClass('active');
        $('#ipm-li-attach').removeClass('active');
        $('#ipm-tab-notes').addClass('active');
        $('#ipm-tab-attach').removeClass('active');
        ipmLoadNotes(irId);
    }
    $('#irProgressModal').modal('show');
}

function ipmLoadNotes(irId) {
    var id = irId || $('#ipm-ir-id').val();
    $('#ipm-notes-list').html('<div class="text-center text-muted" style="padding:20px;"><i class="fa fa-spinner fa-spin"></i></div>');
    $.post(IR_API, { action: 'get_progress_notes', ir_id: id }, function(res) {
        if (!res.success) { $('#ipm-notes-list').html('<p class="text-danger">載入失敗</p>'); return; }
        if (!res.data.length) {
            $('#ipm-notes-list').html('<p class="text-muted" style="text-align:center;padding:20px;font-size:13px;">尚無回覆記錄</p>');
            return;
        }
        var html = '';
        res.data.forEach(function(n) {
            var updInfo = n.updated_at ? ' <span style="color:#e67e22;font-size:11px;">[修改：' + escHtml(n.updated_by_name||'') + ' ' + (n.updated_at||'').substring(0,10) + ']</span>' : '';
            var attHtml = '';
            if (n.attachments && n.attachments.length) {
                attHtml = '<div class="qa-attach-list" style="margin-top:5px;display:flex;flex-wrap:wrap;gap:4px;">';
                n.attachments.forEach(function(a) {
                    attHtml += '<span class="qa-attach-item"><i class="fa fa-file-o"></i> ' + escHtml(a.original_name||a.file_name) + '<button class="del-btn" onclick="ipmDeleteAttachment(' + a.id + ',\'note\')" title="刪除附件"><i class="fa fa-times"></i></button></span>';
                });
                attHtml += '</div>';
            }
            html += '<div class="ipm-note-item" id="ipm-note-' + n.id + '" style="border-bottom:1px solid #eee;padding:8px 0 6px;">' +
                '<div style="display:flex;justify-content:space-between;align-items:flex-start;">' +
                '<div id="ipm-note-text-' + n.id + '" style="white-space:pre-wrap;font-size:13px;flex:1;line-height:1.5;">' + escHtml(n.note_text) + '</div>' +
                '<div style="white-space:nowrap;margin-left:8px;flex-shrink:0;">' +
                '<button class="btn btn-xs btn-link" onclick="ipmEditNote(' + n.id + ')" title="修改"><i class="fa fa-pencil"></i></button>' +
                '<button class="btn btn-xs btn-link text-danger" onclick="ipmDeleteNote(' + n.id + ')" title="刪除"><i class="fa fa-trash"></i></button>' +
                '<label class="btn btn-xs btn-default" style="cursor:pointer;padding:1px 6px;border-radius:10px;font-size:10px;margin:0;" title="上傳附件"><i class="fa fa-paperclip"></i><input type="file" style="display:none;" multiple onchange="ipmUploadNoteAttach(this,' + n.id + ')"></label>' +
                '</div></div>' + attHtml +
                '<div style="font-size:11px;color:#999;margin-top:4px;">' + escHtml(n.created_by_name||'') + ' ' + (n.created_at||'').substring(0,16) + updInfo + '</div>' +
                '</div>';
        });
        $('#ipm-notes-list').html(html);
    }, 'json');
}

function ipmAddNewNoteFiles(input) {
    Array.from(input.files).forEach(function(f) { ipmNewNoteFiles.push(f); });
    ipmRenderNewNoteFilesPreview();
    input.value = '';
}

function ipmRenderNewNoteFilesPreview() {
    var html = ipmNewNoteFiles.map(function(f, i) {
        return '<span class="qa-attach-item"><i class="fa fa-file-o"></i> ' + escHtml(f.name) + '<button class="del-btn" onclick="ipmRemoveNewFile(' + i + ')" type="button"><i class="fa fa-times"></i></button></span>';
    }).join('');
    $('#ipm-new-note-files-preview').html(html);
}

function ipmRemoveNewFile(idx) {
    ipmNewNoteFiles.splice(idx, 1);
    ipmRenderNewNoteFilesPreview();
}

function ipmAddNote() {
    var text = $('#ipm-new-note').val().trim();
    var irId = $('#ipm-ir-id').val();
    var irNo = $('#ipm-ir-no').val();
    if (!text && ipmNewNoteFiles.length === 0) { showToast('請輸入回覆內容或選擇附件', false); return; }

    var fd = new FormData();
    fd.append('action', 'save_progress_note');
    fd.append('ir_id', irId);
    fd.append('ir_no', irNo);
    fd.append('note_id', 0);
    fd.append('note_text', text || '（附件）');
    ipmNewNoteFiles.forEach(function(f) { fd.append('files[]', f); });

    $.ajax({ url: IR_API, type: 'POST', data: fd, processData: false, contentType: false, dataType: 'json',
        success: function(r) {
            if (r.success) {
                $('#ipm-new-note').val('');
                ipmNewNoteFiles = [];
                ipmRenderNewNoteFilesPreview();
                ipmLoadNotes(irId);
                showToast('回覆已送出', true);
            } else { showToast('儲存失敗：'+(r.message||''), false); }
        },
        error: function() { showToast('發生錯誤', false); }
    });
}

function ipmEditNote(noteId) {
    var curText = $('#ipm-note-text-' + noteId).text();
    $('#ipm-note-text-' + noteId).html(
        '<textarea class="form-control" id="ipm-edit-ta-' + noteId + '" rows="2" style="resize:none;">' + escHtml(curText) + '</textarea>' +
        '<div style="margin-top:4px;display:flex;gap:6px;">' +
        '<button class="btn btn-xs btn-primary" onclick="ipmSaveNoteEdit(' + noteId + ')">儲存</button>' +
        '<button class="btn btn-xs btn-default" onclick="ipmLoadNotes($(\'#ipm-ir-id\').val())">取消</button>' +
        '</div>'
    );
}

function ipmSaveNoteEdit(noteId) {
    var text = $('#ipm-edit-ta-' + noteId).val().trim();
    if (!text) { showToast('內容不可為空', false); return; }
    var irId = $('#ipm-ir-id').val();
    var irNo = $('#ipm-ir-no').val();
    $.post(IR_API, { action: 'save_progress_note', ir_id: irId, ir_no: irNo, note_id: noteId, note_text: text }, function(r) {
        if (r.success) ipmLoadNotes(irId); else showToast('儲存失敗', false);
    }, 'json');
}

function ipmDeleteNote(noteId) {
    if (!confirm('確定刪除此回覆記錄（含所有附件）？')) return;
    var irId = $('#ipm-ir-id').val();
    $.post(IR_API, { action: 'delete_progress_note', note_id: noteId }, function(r) {
        if (r.success) { ipmLoadNotes(irId); showToast('已刪除', true); }
        else showToast('刪除失敗', false);
    }, 'json');
}

function ipmUploadNoteAttach(input, noteId) {
    if (!input.files || !input.files.length) return;
    var irId = $('#ipm-ir-id').val();
    var irNo = $('#ipm-ir-no').val();
    var total = input.files.length; var done = 0;
    Array.from(input.files).forEach(function(file) {
        var fd = new FormData();
        fd.append('action', 'upload_note_attachment');
        fd.append('ir_id', irId); fd.append('ir_no', irNo); fd.append('note_id', noteId);
        fd.append('file', file);
        $.ajax({ url: IR_API, type: 'POST', data: fd, processData: false, contentType: false, dataType: 'json',
            success: function(r) { if (!r.success) showToast('上傳失敗：'+(r.message||''), false); if (++done === total) ipmLoadNotes(irId); }
        });
    });
    input.value = '';
}

function ipmDeleteAttachment(attachId, type) {
    if (!confirm('確定刪除此附件？刪除後無法復原。')) return;
    var irId = $('#ipm-ir-id').val();
    $.post(IR_API, { action: 'delete_ir_attachment', id: attachId }, function(r) {
        if (r.success) { if (type === 'note') ipmLoadNotes(irId); else ipmLoadIrAttachments(); }
        else showToast('刪除失敗', false);
    }, 'json');
}

function ipmLoadIrAttachments() {
    var irId = $('#ipm-ir-id').val();
    if (!irId) return;
    $('#ipm-ir-attachments-list').html('<div class="text-muted" style="font-size:13px;"><i class="fa fa-spinner fa-spin"></i> 載入中...</div>');
    $.post(IR_API, { action: 'get_ir_attachments', ir_id: irId }, function(res) {
        if (!res.success) { $('#ipm-ir-attachments-list').html('<p class="text-danger">載入失敗</p>'); return; }
        if (!res.data.length) {
            $('#ipm-ir-attachments-list').html('<p class="text-muted" style="font-size:13px;padding:10px 0;">尚無附件</p>');
            return;
        }
        var html = '<div class="qa-attach-list" style="display:flex;flex-wrap:wrap;gap:8px;">';
        res.data.forEach(function(a) {
            html += '<span class="qa-attach-item"><i class="fa fa-file-o"></i> ' + escHtml(a.original_name||a.file_name) + '<button class="del-btn" onclick="ipmDeleteAttachment(' + a.id + ',\'ir\')" title="刪除"><i class="fa fa-times"></i></button></span>';
        });
        html += '</div>';
        $('#ipm-ir-attachments-list').html(html);
    }, 'json');
}

function ipmUploadIrFiles(input) {
    if (!input.files || !input.files.length) return;
    var irId = $('#ipm-ir-id').val();
    var irNo = $('#ipm-ir-no').val();
    var total = input.files.length; var done = 0;
    Array.from(input.files).forEach(function(file) {
        var fd = new FormData();
        fd.append('action', 'upload_ir_attachment');
        fd.append('ir_id', irId); fd.append('ir_no', irNo);
        fd.append('file', file);
        $.ajax({ url: IR_API, type: 'POST', data: fd, processData: false, contentType: false, dataType: 'json',
            success: function(r) { if (!r.success) showToast('上傳失敗：'+(r.message||''), false); if (++done === total) ipmLoadIrAttachments(); }
        });
    });
    input.value = '';
}

function escHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── 附件路徑設定 ─────────────────────────────────────────────
function loadAttachRootPath() {
    $.post(QA_API, { action: 'get_setting', key: 'attach_root_path' }, function(res) {
        if (res.success) $('#attach_root_path_input').val(res.value || 'Z:\\BOM\\ERP\\品管\\異常單附件');
    }, 'json');
}

function saveAttachRootPath() {
    var val = $('#attach_root_path_input').val().trim();
    $.post(QA_API, { action: 'save_setting', key: 'attach_root_path', value: val }, function(res) {
        var msg = $('#attach_path_save_msg').show();
        if (res.success) { msg.css('color','#26B99A').text('已儲存'); }
        else { msg.css('color','#d9534f').text('儲存失敗'); }
        setTimeout(function() { msg.hide(); }, 2500);
    }, 'json');
}
</script>
</body>
</html>