<?php
/**
 * qc_process_sync.php — QC/生管製程同步提醒
 * 比對 QC 最新確認做到的製程序號，跟生管系統裡仍停在「加工中/QC待驗」、序號較舊的落後製程，列出不同步清單。
 * 後端：src/store/QcProcessSync_API.php ｜ 權限沿用既有 oready(BOM總覽) 模組角色。
 * 骨架參考 views/pm/bom_tracking.php。
 */
session_start();

require_once __DIR__ . '/../../src/common/_config.php';
require_once __DIR__ . '/../../src/common/DBConnection.php';
require_once __DIR__ . '/../../src/common/role_features_helper.php';

if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/pm/qc_process_sync.php";
    header('Location: ../../index.php');
    exit;
}

$conn = new DBConnection();
$db = $conn->getPDO();
$my_id = (int)$_SESSION['id'];
$has_access = rf_has_module_role($db, $my_id, 'oready');
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QC/生管製程同步提醒</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <link rel="stylesheet" href="https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css">
    <style>
        input[type=number]::-webkit-inner-spin-button,
        input[type=number]::-webkit-outer-spin-button { -webkit-appearance:none; margin:0; }
        input[type=number]{ -moz-appearance:textfield; appearance:textfield; }

        .no-access-box{ max-width:520px; margin:80px auto; text-align:center; padding:40px; background:#fff; border-radius:8px; box-shadow:0 2px 5px rgba(0,0,0,.08); }
        .table-toolbar { display:flex; justify-content:space-between; align-items:center; margin-bottom:8px; flex-wrap:wrap; gap:8px; }
        .filter-bar { display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin-bottom:12px; }
        .main-card { background:#fff; border-radius:6px; padding:15px; box-shadow:0 1px 3px rgba(0,0,0,.08); }
        .badge-stuck { display:inline-block; padding:2px 8px; border-radius:10px; font-size:11px; color:#fff; }
        .badge-ing { background:#1a7a1a; }
        .badge-Q { background:#0056b3; }
        .qc-hint { color:#888; font-size:11px; }
    </style>
</head>
<body class="nav-sm">
<div class="container body">
  <div class="main_container">
    <?php include '../partPage/sideAndTopBarMenu.html'; ?>

    <div class="right_col" role="main">
<?php if (!$has_access): ?>
      <div class="no-access-box">
          <i class="fa fa-lock" style="font-size:40px;color:#e74c3c;"></i>
          <h3 style="margin-top:15px;">請先申請權限</h3>
          <p style="color:#888;">您目前沒有「BOM總覽」相關功能的使用權限，請聯絡管理者至「使用者權限管理」頁面指派角色。</p>
      </div>
<?php else: ?>
      <div class="page-title">
        <div class="title_left"><h3>QC/生管製程同步提醒</h3></div>
      </div>
      <div class="clearfix"></div>
      <p class="qc-hint" style="margin-bottom:12px;">列出 QC 已確認做到後面某製程、但生管系統裡還有更早序號的製程仍停在「加工中/QC待驗」未結案的 BOM，多半是忘記按移轉或急件跳關漏做，可直接快速補同步。</p>

      <div class="filter-bar">
        <input type="text" id="filterKw" class="form-control input-sm" placeholder="BOM編號／客戶關鍵字" style="width:220px;">
        <button class="btn btn-primary btn-sm" id="btnFilter">篩選</button>
        <div style="margin-left:auto; display:flex; gap:8px;">
          <button class="btn btn-info btn-sm" id="btnExportCsv">轉 CSV</button>
          <button class="btn btn-info btn-sm" id="btnExportPdf">轉 PDF</button>
        </div>
      </div>

      <div class="main-card">
        <div class="table-toolbar">
          <div style="color:#888;font-size:12px;">共 <span id="totalCount">0</span> 筆不同步項目</div>
          <div style="display:flex;align-items:center;gap:8px;">
            <div id="pageInfo" style="color:#888;font-size:12px;"></div>
            <select id="pageSizeSelect" class="form-control input-sm" style="width:80px;">
              <option value="5">5</option>
              <option value="10" selected>10</option>
              <option value="20">20</option>
              <option value="50">50</option>
            </select>
            <button class="btn btn-default btn-xs" id="btnPrevPage"><i class="fa fa-chevron-left"></i></button>
            <span id="pageNum">1</span>
            <button class="btn btn-default btn-xs" id="btnNextPage"><i class="fa fa-chevron-right"></i></button>
          </div>
        </div>
        <div style="overflow-x:auto;width:100%;">
          <table class="table table-striped" id="mismatchTable">
            <thead><tr>
              <th>BOM</th><th>客戶</th><th>交期</th>
              <th>目前卡住的製程</th><th>QC最新確認到</th><th>操作</th>
            </tr></thead>
            <tbody id="mismatchTbody"><tr><td colspan="6" class="text-center text-muted">載入中...</td></tr></tbody>
          </table>
        </div>
      </div>
<?php endif; ?>
    </div>
  </div>
</div>

<?php if ($has_access): ?>
<!-- ══ 快速更新 Modal ══ -->
<div class="modal fade" id="quickSyncModal" role="dialog" tabindex="-1">
  <div class="modal-dialog" style="width:420px;">
    <div class="modal-content">
      <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button>
        <h4 class="modal-title">快速更新目前製程：<span id="qsProcessLabel"></span></h4></div>
      <div class="modal-body">
        <div class="form-group">
          <label>移轉日期</label>
          <input type="text" id="qsTransferDate" class="form-control" placeholder="請選擇日期">
        </div>
        <div class="form-group">
          <label>廠商</label>
          <input type="text" id="qsMakerInput" class="form-control" list="qsMakerList" placeholder="輸入廠商編號或名稱..." autocomplete="off">
          <datalist id="qsMakerList"></datalist>
          <input type="hidden" id="qsMakerNo">
          <input type="hidden" id="qsMakerName">
        </div>
        <p class="qc-hint">將以今天做為回廠日期；若此製程 QC 已完工會自動跳到「待移轉」，否則比照一般回廠規則。</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
        <button type="button" class="btn btn-primary" id="qsApply">確認移轉</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="https://code.jquery.com/ui/1.12.1/jquery-ui.js"></script>
<script src="../../resource/js/pdfmake.min.js"></script>
<?php if ($has_access): ?>
<script>
var API = '../../src/store/QcProcessSync_API.php';
var state = { page: 1, pageSize: 10, total: 0, rows: [], canWrite: false };
var qsCurrentFid = null;

function apiGet(action, params) {
    return $.get(API, Object.assign({ action: action }, params || {}), null, 'json');
}
function apiPost(action, params) {
    return $.post(API, Object.assign({ action: action }, params || {}), null, 'json');
}
function escapeHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
}

function stateBadge(st) {
    var cls = st === 'ing' ? 'badge-ing' : 'badge-Q';
    var txt = st === 'ing' ? '加工中' : 'QC待驗';
    return '<span class="badge-stuck ' + cls + '">' + txt + '</span>';
}

function loadList() {
    $('#mismatchTbody').html('<tr><td colspan="6" class="text-center text-muted">載入中...</td></tr>');
    apiGet('get_mismatch_list', { page: state.page, pageSize: state.pageSize, keyword: $('#filterKw').val() }).done(function (res) {
        if (!res.success) {
            $('#mismatchTbody').html('<tr><td colspan="6" class="text-center text-muted">' + (res.message || '讀取失敗') + '</td></tr>');
            return;
        }
        state.rows = res.data || [];
        state.total = res.total || 0;
        state.canWrite = !!res.can_write;
        renderRows();
        updatePager();
    });
}

function renderRows() {
    var $tbody = $('#mismatchTbody').empty();
    if (!state.rows.length) {
        $tbody.html('<tr><td colspan="6" class="text-center text-muted">目前沒有偵測到不同步的製程，狀況良好</td></tr>');
        return;
    }
    state.rows.forEach(function (r) {
        var $tr = $('<tr>');
        $tr.append($('<td>').text(r.bom));
        $tr.append($('<td>').text(r.Client_Name || ''));
        $tr.append($('<td>').text(r.delivery_date || '無交期'));
        $tr.append($('<td>').html(
            escapeHtml(r.stuck_sn) + ' ' + escapeHtml(r.stuck_process_name) + ' ' + stateBadge(r.stuck_state) +
            '<div class="qc-hint">移出日：' + escapeHtml(r.stuck_outsource_date || '') + '</div>'
        ));
        $tr.append($('<td>').html(
            escapeHtml(r.max_qc_sn) + ' ' + escapeHtml(r.qc_process_name) +
            '<div class="qc-hint">' + escapeHtml(r.qc_date || '') + '</div>'
        ));
        var $opTd = $('<td>');
        if (state.canWrite) {
            var $btn = $('<button class="btn btn-warning btn-xs">快速更新</button>');
            $btn.on('click', (function (row) {
                return function () { openQuickSync(row); };
            })(r));
            $opTd.append($btn);
        } else {
            $opTd.html('<span class="qc-hint">無操作權限</span>');
        }
        $tr.append($opTd);
        $tbody.append($tr);
    });
}

function updatePager() {
    var totalPages = Math.max(1, Math.ceil(state.total / state.pageSize));
    $('#pageNum').text(state.page);
    $('#totalCount').text(state.total);
    $('#pageInfo').text('第 ' + state.page + ' / ' + totalPages + ' 頁');
    $('#btnPrevPage').prop('disabled', state.page <= 1);
    $('#btnNextPage').prop('disabled', state.page >= totalPages);
}

$('#btnFilter').on('click', function () { state.page = 1; loadList(); });
$('#filterKw').on('keydown', function (e) { if (e.key === 'Enter') { state.page = 1; loadList(); } });
$('#pageSizeSelect').on('change', function () { state.pageSize = parseInt($(this).val(), 10); state.page = 1; loadList(); });
$('#btnPrevPage').on('click', function () { if (state.page > 1) { state.page--; loadList(); } });
$('#btnNextPage').on('click', function () { state.page++; loadList(); });

// ── 快速更新 Modal ──────────────────────────────────────────────────────
function openQuickSync(row) {
    qsCurrentFid = row.stuck_fid;
    $('#qsProcessLabel').text(row.bom + ' / ' + row.stuck_sn + ' ' + row.stuck_process_name);
    var today = new Date();
    var todayStr = today.getFullYear() + '-' + String(today.getMonth() + 1).padStart(2, '0') + '-' + String(today.getDate()).padStart(2, '0');
    $('#qsTransferDate').val(todayStr);
    $('#qsMakerInput').val(''); $('#qsMakerNo').val(''); $('#qsMakerName').val('');
    $('#quickSyncModal').modal('show');
}

try {
    $('#qsTransferDate').datepicker({ format: 'yyyy-mm-dd', autoclose: true, language: 'zh-TW', todayHighlight: true });
} catch (e) {}

var _qsTimer = null;
$('#qsMakerInput').on('input', function () {
    var term = $(this).val().trim();
    $('#qsMakerNo').val(''); $('#qsMakerName').val('');
    clearTimeout(_qsTimer);
    if (!term) { $('#qsMakerList').empty(); return; }
    _qsTimer = setTimeout(function () {
        apiPost('search_maker', { term: term }).done(function (res) {
            var $dl = $('#qsMakerList').empty();
            (res.data || []).forEach(function (m) {
                $dl.append($('<option>').val(m.maker_id_no + ' ' + m.maker_id).attr('data-no', m.maker_id_no).attr('data-name', m.maker_id));
            });
        });
    }, 300);
});
$('#qsMakerInput').on('change', function () {
    var val = $(this).val();
    var matched = null;
    $('#qsMakerList option').each(function () {
        if ($(this).val() === val) matched = { no: $(this).attr('data-no'), name: $(this).attr('data-name') };
    });
    if (matched) { $('#qsMakerNo').val(matched.no); $('#qsMakerName').val(matched.name); }
});

$('#qsApply').on('click', function () {
    var transferDate = $('#qsTransferDate').val().trim();
    var makerNo = $('#qsMakerNo').val().trim();
    var makerName = $('#qsMakerName').val().trim();
    if (!transferDate || !makerNo || !makerName) {
        alert('請選擇移轉日期，並從下拉選單中選擇一個廠商');
        return;
    }
    apiPost('quick_sync_transfer', {
        bom_ing_fid: qsCurrentFid, transfer_date: transferDate, maker_no: makerNo, maker_name: makerName
    }).done(function (res) {
        if (res.success) {
            $('#quickSyncModal').modal('hide');
            loadList();
        } else {
            alert(res.message || '更新失敗');
        }
    });
});

// ── 匯出 ──────────────────────────────────────────────────────────────
function fetchAllForExport(cb) {
    apiGet('get_mismatch_list', { page: 1, pageSize: 100000, keyword: $('#filterKw').val() }).done(function (res) {
        cb(res.success ? (res.data || []) : []);
    });
}
$('#btnExportCsv').on('click', function () {
    fetchAllForExport(function (rows) {
        var lines = ['BOM,客戶,交期,卡住製程序號,卡住製程名稱,卡住狀態,移出日,QC確認到序號,QC確認到製程,QC確認時間'];
        rows.forEach(function (r) {
            var cells = [r.bom, r.Client_Name || '', r.delivery_date || '', r.stuck_sn, r.stuck_process_name || '',
                         r.stuck_state === 'ing' ? '加工中' : 'QC待驗', r.stuck_outsource_date || '',
                         r.max_qc_sn, r.qc_process_name || '', r.qc_date || ''];
            lines.push(cells.map(function (c) { return '"' + String(c).replace(/"/g, '""') + '"'; }).join(','));
        });
        var blob = new Blob(["﻿" + lines.join("\n")], { type: 'text/csv;charset=utf-8;' });
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = 'qc_process_sync.csv';
        a.click();
    });
});
$('#btnExportPdf').on('click', function () {
    fetchAllForExport(function (rows) {
        var body = [['BOM', '客戶', '交期', '卡住製程', '卡住狀態', '移出日', 'QC確認到', 'QC確認時間']];
        rows.forEach(function (r) {
            body.push([r.bom, r.Client_Name || '', r.delivery_date || '', r.stuck_sn + ' ' + (r.stuck_process_name || ''),
                       r.stuck_state === 'ing' ? '加工中' : 'QC待驗', r.stuck_outsource_date || '',
                       r.max_qc_sn + ' ' + (r.qc_process_name || ''), r.qc_date || '']);
        });
        pdfMake.createPdf({
            pageOrientation: 'landscape',
            content: [{ text: 'QC/生管製程同步提醒', fontSize: 14, margin: [0, 0, 0, 10] }, { table: { headerRows: 1, body: body } }],
            defaultStyle: { fontSize: 9 }
        }).download('qc_process_sync.pdf');
    });
});

loadList();
</script>
<?php endif; ?>
</body>
</html>
