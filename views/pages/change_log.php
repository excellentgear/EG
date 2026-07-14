<?php
// EGsystem\views\pages\change_log.php
// 各頁面修改紀錄：依頁面分類、搜尋簡易/詳細說明、篩選修改日期，並提供新增/修改/刪除
// 「對應頁面」顯示系統選單中的中文網頁名稱（來源：system_module_pages）
ini_set('session.gc_maxlifetime', 43200);
session_set_cookie_params(43200);
session_start();

if (!isset($_SESSION['userName']) && !isset($_SESSION['user_id']) && !isset($_SESSION['id'])) {
    header("Location:../../index.php");
    exit;
}

function cl_safe($v) { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }
function cl_user() {
    return $_SESSION['userName'] ?? $_SESSION['user_cname'] ?? $_SESSION['id'] ?? 'system';
}

include_once '../../src/common/DBConnection.php';
include_once '../../src/common/_config.php';

// ── 確保資料表存在（第一次載入自動建立）─────────────────────────────────
function cl_ensure_table(PDO $pdo) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS page_change_log (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          page_name  VARCHAR(190) NOT NULL COMMENT '對應頁面',
          summary    VARCHAR(255) NOT NULL COMMENT '修改項目簡易說明',
          detail     TEXT NULL         COMMENT '詳細說明',
          changed_at DATETIME NOT NULL COMMENT '修改日期時間',
          created_by VARCHAR(100) NULL COMMENT '記錄者',
          created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '建立時間',
          updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '更新時間',
          PRIMARY KEY (id),
          KEY idx_page (page_name),
          KEY idx_changed_at (changed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='各頁面修改紀錄';
    ");
}

// 正規化前端傳來的日期時間（datetime-local：YYYY-MM-DDTHH:MM）→ MySQL DATETIME
function cl_norm_dt($s) {
    $s = trim((string)$s);
    if ($s === '') return date('Y-m-d H:i:s');
    $s = str_replace('T', ' ', $s);
    if (strlen($s) === 16) $s .= ':00';   // 補秒
    return $s;
}

// 正規化頁面路徑（供對應 system_module_pages.page_url）
function cl_norm_path($p) {
    $p = strtolower(trim((string)$p));
    $p = str_replace('\\', '/', $p);
    $p = preg_replace('#^/+#', '', $p);          // 去開頭斜線
    $p = preg_replace('#^egsystem/#', '', $p);   // 去 EGsystem/ 前綴
    return $p;
}

// 建立「正規化路徑 → 中文網頁名稱」對照表
function cl_page_map(PDO $pdo) {
    $map = [];
    try {
        $rows = $pdo->query("SELECT page_name, page_url FROM system_module_pages WHERE page_url <> ''")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $k = cl_norm_path($r['page_url']);
            if ($k !== '' && !isset($map[$k])) $map[$k] = $r['page_name'];
        }
    } catch (Exception $e) {}
    return $map;
}

// 取得某路徑的顯示名稱（找不到 → 退回檔名）
function cl_page_display($path, $map) {
    $k = cl_norm_path($path);
    if (isset($map[$k])) return $map[$k];
    $bn = basename(str_replace('\\', '/', (string)$path));
    return $bn !== '' ? $bn : (string)$path;
}

// ── AJAX 入口 ─────────────────────────────────────────────────────────────
if (isset($_POST['action']) || isset($_GET['action'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? $_GET['action'];
    try {
        $pdo = (new DBConnection())->getPDO();
        cl_ensure_table($pdo);

        switch ($action) {

            // 系統頁面清單（給新增/編輯下拉用，依主項目群組分組；來源同 system_module_setting）
            case 'site_pages': {
                $rows = $pdo->query("
                    SELECT p.page_name, p.page_url, g.group_name
                    FROM system_module_pages p
                    LEFT JOIN system_module_groups g ON p.group_id = g.group_id
                    WHERE p.page_url <> ''
                    ORDER BY g.sort_order ASC, p.sort_order ASC, p.page_name ASC
                ")->fetchAll(PDO::FETCH_ASSOC);
                echo json_encode(['success' => true, 'pages' => $rows], JSON_UNESCAPED_UNICODE);
                break;
            }

            // 篩選用的頁面分類（已存在的紀錄，附中文名稱與筆數）
            case 'pages': {
                $map = cl_page_map($pdo);
                $rows = $pdo->query("SELECT page_name AS val, COUNT(*) AS c FROM page_change_log GROUP BY page_name ORDER BY page_name")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($rows as &$r) { $r['label'] = cl_page_display($r['val'], $map); }
                unset($r);
                echo json_encode(['success' => true, 'pages' => $rows], JSON_UNESCAPED_UNICODE);
                break;
            }

            // 查詢清單（含篩選 + 伺服器端分頁）
            case 'list': {
                $pageFilter = trim($_POST['page_name'] ?? '');
                $kw   = trim($_POST['keyword'] ?? '');
                $from = trim($_POST['date_from'] ?? '');
                $to   = trim($_POST['date_to'] ?? '');

                // 分頁參數
                $pageNo  = max(1, (int)($_POST['page'] ?? 1));
                $perPage = (int)($_POST['per_page'] ?? 10);
                if ($perPage < 1)   $perPage = 10;
                if ($perPage > 200) $perPage = 200;   // 上限保護

                $where = []; $params = [];
                if ($pageFilter !== '') { $where[] = "page_name = ?"; $params[] = $pageFilter; }
                if ($kw !== '')   { $where[] = "(summary LIKE ? OR detail LIKE ?)"; $params[] = "%$kw%"; $params[] = "%$kw%"; }
                if ($from !== '') { $where[] = "changed_at >= ?"; $params[] = $from . ' 00:00:00'; }
                if ($to !== '')   { $where[] = "changed_at <= ?"; $params[] = $to . ' 23:59:59'; }
                $whereSql = $where ? (" WHERE " . implode(' AND ', $where)) : "";

                // 先取總筆數（同篩選條件）
                $countStmt = $pdo->prepare("SELECT COUNT(*) FROM page_change_log" . $whereSql);
                $countStmt->execute($params);
                $total = (int)$countStmt->fetchColumn();

                // 修正頁碼（避免篩選後頁碼超出範圍）
                $totalPages = max(1, (int)ceil($total / $perPage));
                if ($pageNo > $totalPages) $pageNo = $totalPages;
                $offset = ($pageNo - 1) * $perPage;

                // 取當頁資料（LIMIT/OFFSET 以整數內嵌，數值已轉型安全）
                $sql = "SELECT id, page_name, summary, detail, changed_at, created_by FROM page_change_log"
                     . $whereSql
                     . " ORDER BY changed_at DESC, id DESC LIMIT $perPage OFFSET $offset";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $map = cl_page_map($pdo);
                foreach ($rows as &$r) { $r['page_display'] = cl_page_display($r['page_name'], $map); }
                unset($r);

                echo json_encode([
                    'success'  => true,
                    'rows'     => $rows,
                    'total'    => $total,
                    'page'     => $pageNo,
                    'per_page' => $perPage,
                ], JSON_UNESCAPED_UNICODE);
                break;
            }

            // 新增
            case 'add': {
                $page    = trim($_POST['page_name'] ?? '');
                $summary = trim($_POST['summary'] ?? '');
                $detail  = trim($_POST['detail'] ?? '');
                $dt      = cl_norm_dt($_POST['changed_at'] ?? '');
                if ($page === '' || $summary === '') throw new Exception('「對應頁面」與「簡易說明」為必填');
                $stmt = $pdo->prepare("INSERT INTO page_change_log (page_name, summary, detail, changed_at, created_by) VALUES (?,?,?,?,?)");
                $stmt->execute([$page, $summary, $detail, $dt, cl_user()]);
                echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
                break;
            }

            // 修改
            case 'update': {
                $id      = (int)($_POST['id'] ?? 0);
                $page    = trim($_POST['page_name'] ?? '');
                $summary = trim($_POST['summary'] ?? '');
                $detail  = trim($_POST['detail'] ?? '');
                $dt      = cl_norm_dt($_POST['changed_at'] ?? '');
                if ($id <= 0) throw new Exception('缺少 id');
                if ($page === '' || $summary === '') throw new Exception('「對應頁面」與「簡易說明」為必填');
                $stmt = $pdo->prepare("UPDATE page_change_log SET page_name=?, summary=?, detail=?, changed_at=? WHERE id=?");
                $stmt->execute([$page, $summary, $detail, $dt, $id]);
                echo json_encode(['success' => true]);
                break;
            }

            // 刪除
            case 'delete': {
                $id = (int)($_POST['id'] ?? 0);
                if ($id <= 0) throw new Exception('缺少 id');
                $stmt = $pdo->prepare("DELETE FROM page_change_log WHERE id=?");
                $stmt->execute([$id]);
                echo json_encode(['success' => true]);
                break;
            }

            default:
                echo json_encode(['success' => false, 'message' => '未知動作']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>頁面修改紀錄</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <style>
        .cl-filter {
            background:#fff; border:1px solid #e3e6ec; border-radius:6px;
            padding:12px 14px; margin-bottom:14px; display:flex; flex-wrap:wrap;
            gap:10px; align-items:flex-end;
        }
        .cl-filter .fg { display:flex; flex-direction:column; }
        .cl-filter .fg label { font-size:12px; color:#666; margin-bottom:3px; }
        .cl-filter .form-control { height:32px; font-size:13px; }
        .cl-filter .fg.kw { flex:1; min-width:180px; }
        input[type=number]::-webkit-outer-spin-button,
        input[type=number]::-webkit-inner-spin-button { -webkit-appearance:none; margin:0; }
        input[type=number] { -moz-appearance:textfield; appearance:textfield; }

        table.log { width:100%; border-collapse:collapse; font-size:13px; }
        table.log th, table.log td { padding:9px 11px; border-bottom:1px solid #eef0f3; vertical-align:top; text-align:left; }
        table.log th { background:#f7f8fa; color:#555; font-weight:600; font-size:12px; white-space:nowrap; }
        table.log tr:hover td { background:#fafbfc; }
        .pg-badge { display:inline-block; background:#eaf2fb; color:#2870c2; border-radius:3px; padding:2px 7px; font-size:12px; word-break:break-all; }
        .pg-path { display:block; color:#aaa; font-size:10px; margin-top:2px; word-break:break-all; }
        .col-summary { font-weight:600; color:#333; }
        .col-detail { color:#666; white-space:pre-wrap; max-width:380px; font-size:12px; }
        .col-detail.clip { max-height:46px; overflow:hidden; position:relative; cursor:pointer; }
        .col-detail.clip::after { content:'… 展開'; position:absolute; right:0; bottom:0; background:#fff; color:#2870c2; padding-left:18px; }
        .col-date { white-space:nowrap; color:#444; }
        .col-by { white-space:nowrap; color:#888; }
        .row-act { white-space:nowrap; }
        .row-act .btn { padding:2px 8px; font-size:12px; }
        .empty { text-align:center; color:#999; padding:34px 0; }
        .count-hint { color:#888; font-size:12px; margin:0 0 8px; }
        .modal-body .form-group label { font-size:13px; }
        textarea.detail-input { min-height:130px; font-size:13px; }
    </style>
</head>

<body class="nav-sm">
<div class="container body">
<div class="main_container">
    <?php include '../partPage/sideAndTopBarMenu.html' ?>

    <div class="right_col" role="main">

        <div class="page-title">
            <div class="title_left">
                <h3><i class="fa fa-history"></i> 頁面修改紀錄 <small>記錄各頁面的修改歷程</small></h3>
            </div>
        </div>
        <div class="clearfix"></div>

        <div class="row">
            <div class="col-md-12 col-sm-12 col-xs-12">
                <div class="x_panel">
                    <div class="x_content">

                        <!-- 篩選列 -->
                        <div class="cl-filter">
                            <div class="fg">
                                <label>頁面分類</label>
                                <select id="f-page" class="form-control" style="min-width:200px;">
                                    <option value="">全部頁面</option>
                                </select>
                            </div>
                            <div class="fg kw">
                                <label>關鍵字（簡易／詳細說明）</label>
                                <input type="text" id="f-kw" class="form-control" placeholder="輸入關鍵字後按查詢或 Enter">
                            </div>
                            <div class="fg">
                                <label>修改日期（起）</label>
                                <input type="date" id="f-from" class="form-control">
                            </div>
                            <div class="fg">
                                <label>修改日期（迄）</label>
                                <input type="date" id="f-to" class="form-control">
                            </div>
                            <div class="fg">
                                <button id="btn-search" class="btn btn-primary btn-sm" style="height:32px;"><i class="fa fa-search"></i> 查詢</button>
                            </div>
                            <div class="fg">
                                <button id="btn-clear" class="btn btn-default btn-sm" style="height:32px;"><i class="fa fa-eraser"></i> 清除</button>
                            </div>
                            <div class="fg" style="margin-left:auto;">
                                <button id="btn-add" class="btn btn-success btn-sm" style="height:32px;"><i class="fa fa-plus"></i> 新增紀錄</button>
                            </div>
                        </div>

                        <p class="count-hint" id="count-hint"></p>

                        <!-- 清單 -->
                        <div class="table-responsive">
                            <table class="log">
                                <thead>
                                    <tr>
                                        <th style="width:200px;">對應頁面</th>
                                        <th style="width:230px;">簡易說明</th>
                                        <th>詳細說明</th>
                                        <th style="width:140px;">修改時間</th>
                                        <th style="width:90px;">記錄者</th>
                                        <th style="width:110px;">操作</th>
                                    </tr>
                                </thead>
                                <tbody id="log-body">
                                    <tr><td colspan="6" class="empty"><i class="fa fa-spinner fa-spin"></i> 載入中...</td></tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- 分頁 -->
                        <div id="pager" class="text-center"></div>

                    </div>
                </div>
            </div>
        </div>

    </div>
    <!-- /right_col -->
</div>
</div>

<!-- 新增/編輯 Modal -->
<div class="modal fade" id="editModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
                <h4 class="modal-title" id="editModalTitle">新增紀錄</h4>
            </div>
            <div class="modal-body">
                <input type="hidden" id="m-id">
                <div class="form-group">
                    <label>對應頁面 <span style="color:#d9534f;">*</span></label>
                    <select id="m-page-select" class="form-control">
                        <option value="">— 請選擇頁面 —</option>
                        <option value="__custom__">★ 自行輸入路徑（選單外頁面）</option>
                    </select>
                    <input type="text" id="m-page-custom" class="form-control" style="display:none; margin-top:6px;" placeholder="例：views/pm/part_viewer.php">
                </div>
                <div class="form-group">
                    <label>簡易說明 <span style="color:#d9534f;">*</span></label>
                    <input type="text" id="m-summary" class="form-control" placeholder="一句話描述這次修改">
                </div>
                <div class="form-group">
                    <label>詳細說明</label>
                    <textarea id="m-detail" class="form-control detail-input" placeholder="可分點描述修改細節（選填）"></textarea>
                </div>
                <div class="form-group">
                    <label>修改日期時間 <span style="color:#d9534f;">*</span></label>
                    <input type="datetime-local" id="m-dt" class="form-control" style="max-width:260px;">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
                <button type="button" class="btn btn-primary" id="m-save"><i class="fa fa-floppy-o"></i> 儲存</button>
            </div>
        </div>
    </div>
</div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script>
function esc(s){ return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function normPath(p){ return String(p||'').toLowerCase().replace(/\\/g,'/').replace(/^\/+/,'').replace(/^egsystem\//,'').trim(); }

var _rowsById = {};   // 清單資料（供編輯取用）
var _page = 1, _perPage = 10, _total = 0;   // 分頁狀態（伺服器端分頁）
function _totalPages(){ return Math.max(1, Math.ceil(_total / _perPage)); }

// 載入篩選用頁面分類
function loadPages() {
    $.post('', { action:'pages' }, function(res){
        if(!res.success) return;
        var sel = $('#f-page');
        sel.find('option:not(:first)').remove();
        (res.pages||[]).forEach(function(p){
            sel.append('<option value="'+esc(p.val)+'">'+esc(p.label)+' ('+p.c+')</option>');
        });
    }, 'json');
}

// 載入系統頁面清單（新增/編輯下拉，依主項目群組分組顯示子頁面名稱）
function loadSitePages() {
    $.post('', { action:'site_pages' }, function(res){
        if(!res.success) return;
        var sel = $('#m-page-select');
        // 保留第一個「請選擇」與最後「自行輸入」，其餘清空
        sel.find('option, optgroup').not('[value=""]').not('[value="__custom__"]').remove();
        var custom = sel.find('option[value="__custom__"]').detach();
        // 依群組分組
        var groups = {}, order = [];
        (res.pages||[]).forEach(function(p){
            var g = p.group_name || '未分類';
            if(!groups[g]){ groups[g] = []; order.push(g); }
            groups[g].push(p);
        });
        order.forEach(function(g){
            var $og = $('<optgroup label="'+esc(g)+'">');
            groups[g].forEach(function(p){
                $og.append('<option value="'+esc(p.page_url)+'">'+esc(p.page_name)+'</option>');
            });
            sel.append($og);
        });
        sel.append(custom);
    }, 'json');
}

// 查詢清單（伺服器端分頁：只載入當前頁）
function loadList(page) {
    if (page) _page = page;
    var params = {
        action: 'list',
        page: _page,
        per_page: _perPage,
        page_name: $('#f-page').val(),
        keyword: $('#f-kw').val().trim(),
        date_from: $('#f-from').val(),
        date_to: $('#f-to').val()
    };
    $('#log-body').html('<tr><td colspan="6" class="empty"><i class="fa fa-spinner fa-spin"></i> 載入中...</td></tr>');
    $.post('', params, function(res){
        if(!res.success){ $('#log-body').html('<tr><td colspan="6" class="empty">載入失敗：'+esc(res.message)+'</td></tr>'); $('#pager').empty(); return; }
        var rows = res.rows || [];
        _rowsById = {};
        _total = res.total || 0;
        _page  = res.page  || 1;
        var from = _total ? ((_page - 1) * _perPage + 1) : 0;
        var to   = (_page - 1) * _perPage + rows.length;
        $('#count-hint').text(_total ? ('共 ' + _total + ' 筆，顯示第 ' + from + '–' + to + ' 筆（第 ' + _page + ' / ' + _totalPages() + ' 頁）') : '共 0 筆');
        if(rows.length === 0){ $('#log-body').html('<tr><td colspan="6" class="empty"><i class="fa fa-inbox"></i> 無符合的紀錄</td></tr>'); renderPager(); return; }
        var html = '';
        rows.forEach(function(r){
            _rowsById[r.id] = r;
            var detail = (r.detail||'').trim();
            var clip = detail.length > 60 ? ' clip' : '';
            html += '<tr data-id="'+r.id+'">'
                + '<td><span class="pg-badge">'+esc(r.page_display||r.page_name)+'</span>'
                +     '<span class="pg-path">'+esc(r.page_name)+'</span></td>'
                + '<td class="col-summary">'+esc(r.summary)+'</td>'
                + '<td><div class="col-detail'+clip+'" title="點擊展開/收合">'+(detail?esc(detail):'<span style="color:#bbb;">—</span>')+'</div></td>'
                + '<td class="col-date">'+esc((r.changed_at||'').substring(0,16))+'</td>'
                + '<td class="col-by">'+esc(r.created_by||'')+'</td>'
                + '<td class="row-act">'
                +   '<button class="btn btn-default btn-edit" title="編輯"><i class="fa fa-pencil"></i></button> '
                +   '<button class="btn btn-danger btn-del" title="刪除"><i class="fa fa-trash"></i></button>'
                + '</td>'
                + '</tr>';
        });
        $('#log-body').html(html);
        renderPager();
    }, 'json').fail(function(){
        $('#log-body').html('<tr><td colspan="6" class="empty">連線失敗，請重試</td></tr>');
        $('#pager').empty();
    });
}

// 繪製分頁列（最多顯示目前頁前後各 2 頁，含首/末頁與省略號）
function renderPager() {
    var tp = _totalPages(), p = _page;
    if (tp <= 1) { $('#pager').empty(); return; }
    function item(label, page, opts) {
        opts = opts || {};
        var cls = (opts.active ? 'active' : '') + (opts.disabled ? ' disabled' : '');
        if (opts.disabled || opts.active) return '<li class="'+cls+'"><span>'+label+'</span></li>';
        return '<li><a href="#" data-page="'+page+'">'+label+'</a></li>';
    }
    var html = '';
    html += item('«', p - 1, { disabled: p <= 1 });
    var win = 2, start = Math.max(1, p - win), end = Math.min(tp, p + win);
    if (start > 1) { html += item('1', 1); if (start > 2) html += '<li class="disabled"><span>…</span></li>'; }
    for (var i = start; i <= end; i++) html += item(i, i, { active: i === p });
    if (end < tp) { if (end < tp - 1) html += '<li class="disabled"><span>…</span></li>'; html += item(tp, tp); }
    html += item('»', p + 1, { disabled: p >= tp });
    $('#pager').html('<ul class="pagination" style="margin:10px 0 0;">' + html + '</ul>');
}

// 分頁點擊
$(document).on('click', '#pager a[data-page]', function(e){
    e.preventDefault();
    var pg = parseInt($(this).data('page'), 10);
    if (pg >= 1 && pg <= _totalPages() && pg !== _page) loadList(pg);
});

// 詳細說明 展開/收合
$(document).on('click', '.col-detail', function(){
    if($(this).hasClass('clip')) $(this).removeClass('clip');
    else if(($(this).text()||'').length > 60) $(this).addClass('clip');
});

// datetime-local 目前本機時間
function nowLocal() {
    var d = new Date(), p = function(n){ return ('0'+n).slice(-2); };
    return d.getFullYear()+'-'+p(d.getMonth()+1)+'-'+p(d.getDate())+'T'+p(d.getHours())+':'+p(d.getMinutes());
}

// 設定「對應頁面」欄位（依路徑挑下拉或落到自行輸入）
function setPageField(rawPath){
    var target = '';
    $('#m-page-select option').each(function(){
        var v = $(this).val();
        if(v && v !== '__custom__' && normPath(v) === normPath(rawPath)){ target = v; return false; }
    });
    if(target){ $('#m-page-select').val(target); $('#m-page-custom').hide().val(''); }
    else if(rawPath){ $('#m-page-select').val('__custom__'); $('#m-page-custom').show().val(rawPath); }
    else { $('#m-page-select').val(''); $('#m-page-custom').hide().val(''); }
}

// 下拉切換 → 顯示/隱藏自行輸入
$('#m-page-select').on('change', function(){
    if($(this).val() === '__custom__'){ $('#m-page-custom').show().focus(); }
    else { $('#m-page-custom').hide(); }
});

// 開啟新增
$('#btn-add').on('click', function(){
    $('#editModalTitle').text('新增紀錄');
    $('#m-id').val('');
    setPageField('');
    $('#m-summary').val('');
    $('#m-detail').val('');
    $('#m-dt').val(nowLocal());
    $('#editModal').modal('show');
});

// 開啟編輯
$(document).on('click', '.btn-edit', function(){
    var id = $(this).closest('tr').data('id');
    var r = _rowsById[id];
    if(!r) return;
    $('#editModalTitle').text('編輯紀錄');
    $('#m-id').val(id);
    setPageField(r.page_name);
    $('#m-summary').val(r.summary);
    $('#m-detail').val(r.detail || '');
    var dt = (r.changed_at||'').substring(0,16).replace(' ','T');
    $('#m-dt').val(dt.length===16 ? dt : nowLocal());
    $('#editModal').modal('show');
});

// 儲存（新增或編輯）
$('#m-save').on('click', function(){
    var id = $('#m-id').val();
    var pageVal = $('#m-page-select').val();
    if(pageVal === '__custom__') pageVal = $('#m-page-custom').val().trim();
    var data = {
        action: id ? 'update' : 'add',
        id: id,
        page_name: pageVal,
        summary: $('#m-summary').val().trim(),
        detail: $('#m-detail').val(),
        changed_at: $('#m-dt').val()
    };
    if(!data.page_name){ alert('請選擇或輸入「對應頁面」'); return; }
    if(!data.summary){ alert('「簡易說明」為必填'); return; }
    var $btn = $(this).prop('disabled', true);
    $.post('', data, function(res){
        $btn.prop('disabled', false);
        if(res.success){ $('#editModal').modal('hide'); loadPages(); loadList(id ? _page : 1); }
        else alert('儲存失敗：'+(res.message||'未知錯誤'));
    }, 'json').fail(function(){ $btn.prop('disabled', false); alert('連線失敗'); });
});

// 刪除
$(document).on('click', '.btn-del', function(){
    var tr = $(this).closest('tr');
    var id = tr.data('id');
    var summary = tr.find('.col-summary').text();
    if(!confirm('確定刪除這筆紀錄？\n\n'+summary)) return;
    $.post('', { action:'delete', id:id }, function(res){
        if(res.success){ loadPages(); loadList(); }
        else alert('刪除失敗：'+(res.message||'未知錯誤'));
    }, 'json');
});

// 篩選事件（任何篩選/查詢都從第 1 頁重新載入）
$('#btn-search').on('click', function(){ loadList(1); });
$('#btn-clear').on('click', function(){ $('#f-page').val(''); $('#f-kw').val(''); $('#f-from').val(''); $('#f-to').val(''); loadList(1); });
$('#f-kw').on('keydown', function(e){ if(e.key==='Enter') loadList(1); });
$('#f-page, #f-from, #f-to').on('change', function(){ loadList(1); });

// 初始化：只預先載入第 1 頁（10 筆），其餘換頁/篩選時才陸續載入
loadPages();
loadSitePages();
loadList(1);
</script>
</body>
</html>
