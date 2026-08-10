<?php
/**
 * 報工紀錄查詢列印（2026-08-10 新增，測試功能）
 * 逐筆列出 pm_process_daily_report 每一筆報工紀錄（含臨時加工），供查找/列印用；
 * 與 process_schedule.php 的「查詢已報工工單」跳窗不同——那支是依工單(bom_ing_fid)彙總、
 * 用來恢復任務/改綁BOM等操作，這支是純瀏覽/列印每一筆報工紀錄，兩者並存不互相取代。
 * 篩選：日期區間(預設近30天，清空=不限日期查全部)、製程、機台、人員(設置/生產人員合併比對)、備註。
 * 分頁走後端(不一次撈全部)；列印/CSV匯出走後端依目前篩選條件抓「全部」符合筆數(不受分頁限制)。
 */
include_once '../../src/common/_config.php';
include "../../src/common/DBConnection.php";
include_once '../../src/common/people_lib.php';

// 登入檢查（比照 process_schedule.php，AJAX-aware）
if (!isset($_SESSION['user_id']) && !isset($_SESSION['id'])) {
    $is_ajax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest')
        || isset($_POST['action']);
    if ($is_ajax) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => '連線逾時，請重新登入', 'timeout' => true, 'redirect' => '../../index.php']);
        exit;
    } else {
        echo "<script>alert('連線逾時，請重新登入'); window.location.href='../../index.php';</script>";
        exit;
    }
}

$db = new DBConnection();
$pdo = $db->getPDO();

// --- 權限檢查（比照 process_schedule.php 既有 user_module_permissions 機制，唯讀工具只需「有任一權限」即可檢視） ---
$id = intval($_SESSION['id'] ?? 0);
$current_script_path = $_SERVER['PHP_SELF'];
$permission_code = null;
try {
    $sql_page_info = "
        SELECT smp.page_id, smp.page_url, smp.page_url_readonly, smp.group_id
        FROM system_module_pages smp
        WHERE (:script LIKE CONCAT('%', smp.page_url) AND smp.page_url IS NOT NULL AND smp.page_url != '')
           OR (:script LIKE CONCAT('%', smp.page_url_readonly) AND smp.page_url_readonly IS NOT NULL AND smp.page_url_readonly != '')
        LIMIT 1";
    $stmt_page_info = $pdo->prepare($sql_page_info);
    $stmt_page_info->execute([':script' => $current_script_path]);
    $page_info = $stmt_page_info->fetch(PDO::FETCH_ASSOC);

    if ($page_info) {
        $page_id = $page_info['page_id'];
        $group_id = $page_info['group_id'];
        $group_module_code = null;
        if (!empty($group_id)) {
            $stmt_gm = $pdo->prepare("SELECT module_code FROM system_modules WHERE group_id = :gid LIMIT 1");
            $stmt_gm->execute([':gid' => $group_id]);
            $group_module_code = $stmt_gm->fetchColumn();
        }
        $user_perms = [];
        $stmt_pp = $pdo->prepare("SELECT permission FROM user_module_permissions WHERE user_id=:uid AND scope='page' AND module_code=:pid");
        $stmt_pp->execute([':uid' => $id, ':pid' => $page_id]);
        $pf = array_filter($stmt_pp->fetchAll(PDO::FETCH_COLUMN));
        if ($pf) {
            $user_perms = $pf;
        } elseif (!empty($group_module_code)) {
            $stmt_gp = $pdo->prepare("SELECT permission FROM user_module_permissions WHERE user_id=:uid AND scope='group' AND module_code=:mc");
            $stmt_gp->execute([':uid' => $id, ':mc' => $group_module_code]);
            $gf = array_filter($stmt_gp->fetchAll(PDO::FETCH_COLUMN));
            if ($gf) $user_perms = $gf;
        }
        $all = [];
        foreach ($user_perms as $p) { $all = array_merge($all, str_split($p)); }
        $uniq = array_unique($all);
        $permission_code = $uniq ? implode('', $uniq) : null;
    }
} catch (Exception $e) {
    $permission_code = null;
}

$is_ajax_req = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') || isset($_POST['action']);
if (is_null($permission_code)) {
    if ($is_ajax_req) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => '無權限存取此功能']);
        exit;
    }
    echo "<script>alert('無權限存取此頁面'); window.location.href='../../index.php';</script>";
    exit;
}

// ================= 共用：組出篩選條件（list / get_print / export_csv 三處共用，避免各自重寫一份） =================
function prq_build_filter($p) {
    $where = [];
    $params = [];
    if (!empty($p['date_from'])) { $where[] = 'pdr.report_date >= ?'; $params[] = $p['date_from']; }
    if (!empty($p['date_to']))   { $where[] = 'pdr.report_date <= ?'; $params[] = $p['date_to']; }
    if (!empty($p['process_no'])) { $where[] = 'pdr.process_no = ?'; $params[] = intval($p['process_no']); }
    if (!empty($p['machine_id'])) { $where[] = 'pdr.machine_id = ?'; $params[] = intval($p['machine_id']); }
    if (!empty($p['person'])) {
        $where[] = '(CONVERT(u1.user_cname USING utf8mb4) LIKE ? OR CONVERT(u2.user_cname USING utf8mb4) LIKE ?)';
        $like = '%' . $p['person'] . '%';
        $params[] = $like; $params[] = $like;
    }
    if (!empty($p['remark'])) { $where[] = 'pdr.remark LIKE ?'; $params[] = '%' . $p['remark'] . '%'; }
    return [$where ? ('WHERE ' . implode(' AND ', $where)) : '', $params];
}

$PRQ_FROM = "FROM pm_process_daily_report pdr
    LEFT JOIN machine_list m ON pdr.machine_id = m.machine_id
    LEFT JOIN user u1 ON pdr.setup_user_id = u1.id
    LEFT JOIN user u2 ON pdr.production_user_id = u2.id
    LEFT JOIN process_no pn ON pdr.process_no = pn.ProcessNo
    LEFT JOIN bom_ing bi ON pdr.bom_ing_fid = bi.bom_ing_fid
    LEFT JOIN bom b ON bi.bom = b.bom";

$PRQ_COLS = "pdr.report_id, pdr.report_date, pdr.report_source, pdr.remark, pdr.produced_qty, pdr.is_finished,
    pdr.process_face, pdr.source_reason,
    m.machine, u1.user_cname AS setup_user, u2.user_cname AS prod_user, pn.ProcessName,
    bi.bom, b.d_id, b.Client_Name";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'export_csv') {
        try {
            list($whereSql, $params) = prq_build_filter($_POST);
            $sql = "SELECT $PRQ_COLS $PRQ_FROM $whereSql ORDER BY pdr.report_date DESC, pdr.report_id DESC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="process_report_' . date('YmdHis') . '.csv"');
            echo "\xEF\xBB\xBF"; // Excel 判讀 UTF-8 BOM
            $out = fopen('php://output', 'w');
            fputcsv($out, ['日期', '製程', '機台', '工單/料號', '客戶', '設置人員', '生產人員', '生產數量', '完工', '加工面', '備註']);
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $isTemp = $r['report_source'] === 'TEMP';
                fputcsv($out, [
                    $r['report_date'],
                    $r['ProcessName'],
                    $r['machine'],
                    $isTemp ? '臨時加工' : ($r['bom'] . ($r['d_id'] ? (' / ' . $r['d_id']) : '')),
                    $isTemp ? ($r['source_reason'] ?: '') : $r['Client_Name'],
                    $r['setup_user'],
                    $r['prod_user'],
                    $r['produced_qty'],
                    $r['is_finished'] ? '是' : '否',
                    $r['process_face'],
                    $r['remark'],
                ]);
            }
            fclose($out);
        } catch (Exception $e) {
            header('Content-Type: text/plain; charset=utf-8');
            echo '匯出失敗：' . $e->getMessage();
        }
        exit;
    }

    header('Content-Type: application/json');
    try {
        if ($action === 'list') {
            list($whereSql, $params) = prq_build_filter($_POST);
            $page = max(1, intval($_POST['page'] ?? 1));
            $page_size = intval($_POST['page_size'] ?? 20);
            if (!in_array($page_size, [5, 10, 20, 50], true)) $page_size = 20;
            $offset = ($page - 1) * $page_size;

            $stmtCnt = $pdo->prepare("SELECT COUNT(*) $PRQ_FROM $whereSql");
            $stmtCnt->execute($params);
            $total = (int)$stmtCnt->fetchColumn();

            $sql = "SELECT $PRQ_COLS $PRQ_FROM $whereSql ORDER BY pdr.report_date DESC, pdr.report_id DESC LIMIT $page_size OFFSET $offset";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'total' => $total, 'page' => $page, 'page_size' => $page_size, 'rows' => $rows]);
        } elseif ($action === 'get_print') {
            list($whereSql, $params) = prq_build_filter($_POST);
            $sql = "SELECT $PRQ_COLS $PRQ_FROM $whereSql ORDER BY pdr.report_date DESC, pdr.report_id DESC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $company = '';
            $cr = $pdo->query("SELECT customer_full FROM customer_list WHERE is_own_company=1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            if ($cr) $company = $cr['customer_full'];

            echo json_encode(['success' => true, 'rows' => $rows, 'total' => count($rows), 'company_name' => $company]);
        } else {
            echo json_encode(['success' => false, 'message' => '未知操作']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ================= 頁面初次載入：預備篩選下拉選單資料 =================
$process_list = $pdo->query("SELECT ProcessNo, ProcessName FROM process_no ORDER BY ProcessName")->fetchAll(PDO::FETCH_ASSOC);
$machine_list = $pdo->query("SELECT machine_id, machine FROM machine_list WHERE (state IS NULL OR state != '1') ORDER BY position")->fetchAll(PDO::FETCH_ASSOC);
$people_list = eg_people_list($pdo);
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>報工紀錄查詢列印</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <style>
        #sidebar-menu { visibility: hidden; }
        .right_col .page-title { margin: 8px 0 4px; overflow: hidden; clear: both; }
        .page-help-btn { height: 30px; font-size: 13px; padding: 0 12px; border: 1px solid #d98a33; border-radius: 15px;
            background: #F0A24B; color: #fff; cursor: pointer; }
        .page-help-btn:hover { background: #d98a33; }
        @media print { .page-help-btn { display: none !important; } }
        .help-doc { font-size: 13px; color: #5b3a1e; line-height: 1.75; }
        .help-doc h4 { color: #8A5A2B; border-bottom: 2px solid #F7E0BD; padding-bottom: 3px; margin: 14px 0 6px; font-size: 15px; }
        .help-doc h4:first-child { margin-top: 0; }
        .help-doc b { color: #8A5A2B; }
        .help-doc ul { margin: 4px 0 8px; padding-left: 20px; }
        .help-doc li { margin: 2px 0; }
        .help-doc .tip { background: #FFF7E8; border: 1px dashed #F0A24B; border-radius: 6px; padding: 6px 10px; margin: 6px 0; }
        .prq-toolbar { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; clear: both;
            border: 1.5px solid #E8D5B5; border-radius: 8px; padding: 8px 10px; margin-bottom: 10px; background: #FDF8EF; }
        .prq-toolbar label { margin: 0 0 0 6px; font-size: 13px; color: #5b3a1e; }
        .prq-toolbar label:first-child { margin-left: 0; }
        .prq-toolbar select, .prq-toolbar input[type=text], .prq-toolbar input[type=date], .prq-toolbar button {
            height: 30px; font-size: 13px; line-height: 1; padding: 0 8px; border: 1px solid #D8BE93;
            border-radius: 4px; background: #fff; color: #5b3a1e; }
        .prq-toolbar button { cursor: pointer; }
        .prq-toolbar button:hover { background: #F7E0BD; }
        .prq-toolbar .btn-warm { background: #F0A24B; color: #fff; border-color: #d98a33; }
        .prq-toolbar .btn-warm:hover { background: #d98a33; }
        .prq-stat { display: flex; align-items: center; gap: 14px; margin-bottom: 8px; font-size: 13px; color: #5b3a1e; }
        .prq-stat b { color: #8A5A2B; font-size: 16px; }
        .prq-table-wrap { overflow-x: auto; border: 1px solid #E8D5B5; border-radius: 6px; background: #fff; }
        table.prq-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        table.prq-table th, table.prq-table td { border: 1px solid #EADFC8; padding: 5px 8px; white-space: nowrap; text-align: center; }
        table.prq-table thead th { background: #F7E0BD; color: #5b3a1e; font-weight: bold; }
        table.prq-table tbody tr:nth-child(even) { background: #FBF6EC; }
        table.prq-table tbody tr:hover { background: #FBF0DD; }
        table.prq-table td.t-left { text-align: left; white-space: normal; }
        .prq-pager { display: flex; justify-content: flex-end; align-items: center; gap: 5px; margin: 10px 2px 4px; flex-wrap: wrap; }
        .prq-pager .pg-info { font-size: 12px; color: #8a6d45; margin-right: auto; }
        .prq-pager button { min-width: 30px; height: 28px; padding: 0 9px; border: 1px solid #D8BE93; background: #fff; color: #5b3a1e; border-radius: 4px; cursor: pointer; font-size: 12px; }
        .prq-pager button:hover:not(:disabled) { background: #F7E0BD; }
        .prq-pager button.cur { background: #F0A24B; color: #fff; border-color: #F0A24B; font-weight: bold; }
        .prq-pager button:disabled { opacity: .4; cursor: default; }
        .prq-empty { padding: 30px; text-align: center; color: #8a6d45; }
        .prq-mask { display: none; position: fixed; inset: 0; background: rgba(60,40,20,.45); z-index: 1050; }
        .prq-modal { background: #fff; border-radius: 8px; max-width: 560px; margin: 60px auto; box-shadow: 0 5px 25px rgba(0,0,0,.3);
            max-height: 82vh; display: flex; flex-direction: column; }
        .prq-modal .m-head { background: #F7E0BD; color: #5b3a1e; font-weight: bold; padding: 10px 15px; border-radius: 8px 8px 0 0;
            display: flex; justify-content: space-between; }
        .prq-modal .m-head .m-close { cursor: pointer; color: #b5762a; }
        .prq-modal .m-body { padding: 15px; overflow-y: auto; }
        .prq-modal .m-foot { padding: 10px 15px; border-top: 1px solid #EADFC8; text-align: right; }
        .prq-modal .m-foot button { height: 30px; padding: 0 16px; border-radius: 4px; font-size: 13px; border: 1px solid #d98a33; cursor: pointer; background: #F0A24B; color: #fff; }
    </style>
</head>
<body class="nav-sm">
<div class="container body">
<div class="main_container">
    <?php include '../partPage/sideAndTopBarMenu.html' ?>
    <div class="right_col" role="main">
        <div class="page-title" style="display:flex;align-items:center;flex-wrap:wrap;">
            <h2 style="margin:6px 0;">報工紀錄查詢列印
                <small style="color:#8a6d45;">逐筆列出每一筆報工紀錄，可篩選/分頁/列印/匯出CSV</small></h2>
            <button id="btnPageHelp" class="page-help-btn" style="margin-left:auto;"><i class="fa fa-question-circle"></i> 使用說明</button>
        </div>
        <div class="clearfix"></div>

        <div class="prq-toolbar">
            <label>日期</label>
            <input type="date" id="fDateFrom" max="9999-12-31">
            <span>～</span>
            <input type="date" id="fDateTo" max="9999-12-31">
            <label>製程</label>
            <select id="fProcess" data-eg-filter="輸入製程名稱篩選…" style="min-width:140px;">
                <option value="">（全部）</option>
                <?php foreach ($process_list as $p): ?>
                <option value="<?= (int)$p['ProcessNo'] ?>"><?= htmlspecialchars($p['ProcessName']) ?></option>
                <?php endforeach; ?>
            </select>
            <label>機台</label>
            <select id="fMachine" data-eg-filter="輸入機台名稱篩選…" style="min-width:120px;">
                <option value="">（全部）</option>
                <?php foreach ($machine_list as $m): ?>
                <option value="<?= (int)$m['machine_id'] ?>"><?= htmlspecialchars($m['machine']) ?></option>
                <?php endforeach; ?>
            </select>
            <label>人員</label>
            <input type="text" id="fPerson" list="prqPeopleList" placeholder="設置或生產人員姓名" style="width:120px;">
            <datalist id="prqPeopleList">
                <?php foreach ($people_list as $pp): ?>
                <option value="<?= htmlspecialchars($pp['user_cname']) ?>">
                <?php endforeach; ?>
            </datalist>
            <label>備註</label>
            <input type="text" id="fRemark" placeholder="關鍵字" style="width:120px;">
            <button class="btn-warm" id="btnSearch"><i class="fa fa-search"></i> 查詢</button>
            <button id="btnClear"><i class="fa fa-eraser"></i> 清除篩選(查全部)</button>
            <button id="btnPrint" style="margin-left:auto;"><i class="fa fa-print"></i> 列印</button>
            <button id="btnExportCsv"><i class="fa fa-file-excel-o"></i> 匯出CSV</button>
        </div>

        <div class="prq-stat">
            <span>共 <b id="statTotal">0</b> 筆</span>
            <label style="margin-left:auto;">每頁</label>
            <select id="pageSizeSel" style="height:28px;">
                <option value="5">5</option>
                <option value="10">10</option>
                <option value="20" selected>20</option>
                <option value="50">50</option>
            </select>
            <span>筆</span>
        </div>

        <div class="prq-table-wrap">
            <table class="prq-table" id="prqTable">
                <thead><tr>
                    <th>日期</th><th>製程</th><th>機台</th><th class="t-left">工單/料號</th><th>客戶</th>
                    <th>設置人員</th><th>生產人員</th><th>生產數量</th><th>完工</th><th>加工面</th><th class="t-left">備註</th>
                </tr></thead>
                <tbody id="prqTbody"><tr><td colspan="11" class="prq-empty">請設定篩選條件後查詢</td></tr></tbody>
            </table>
        </div>
        <div class="prq-pager" id="prqPager"></div>
    </div>
</div>
</div>

<div class="prq-mask" id="helpUseMask"><div class="prq-modal">
    <div class="m-head"><span><i class="fa fa-question-circle"></i> 報工紀錄查詢列印 使用說明</span><span class="m-close" onclick="document.getElementById('helpUseMask').style.display='none'">✕</span></div>
    <div class="m-body help-doc">
        <h4>功能說明</h4>
        <p>逐筆列出「加工排程看板」每一筆報工紀錄（含臨時加工），供查找特定日期/人員/製程/機台的報工內容並列印或匯出，與看板上「查詢已報工工單」跳窗（依工單彙總、用於恢復任務/改綁BOM）用途不同、互不影響。</p>
        <h4>操作步驟</h4>
        <ul>
            <li>上方篩選列可組合使用：日期區間、製程、機台、人員（同時比對設置/生產人員）、備註關鍵字。</li>
            <li>日期區間預設近30天；按「清除篩選(查全部)」可清空所有條件、改查全部歷史資料。</li>
            <li>列表分頁顯示（避免一次載入全部拖慢速度），可調整每頁筆數。</li>
            <li>「列印」「匯出CSV」皆依目前篩選條件抓「全部」符合筆數（不受分頁限制）。</li>
        </ul>
        <h4>重要行為/常見疑問</h4>
        <div class="tip">若篩選結果筆數較多（超過3000筆），列印/匯出前會先跳出確認提示，避免不小心產生過大的列印工作。</div>
        <ul>
            <li>臨時加工（無綁定工單）的「工單/料號」欄會顯示「臨時加工」，客戶欄改顯示加工原因。</li>
        </ul>
        <h4>設定入口</h4>
        <p>無需另外設定，資料即時取自報工系統。</p>
        <h4>權限角色</h4>
        <p>凡對「加工排程看板」（測試功能）具有任一權限（檢視/新增/修改/刪除）者即可使用本頁查詢與列印。</p>
    </div>
    <div class="m-foot"><button onclick="document.getElementById('helpUseMask').style.display='none'">我知道了</button></div>
</div></div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script src="../../resource/js/eg_input_rules.js?v=<?= @filemtime(__DIR__ . '/../../resource/js/eg_input_rules.js') ?>"></script>
<script src="../../resource/js/eg_date_fmt.js?v=<?= @filemtime(__DIR__ . '/../../resource/js/eg_date_fmt.js') ?>"></script>
<script>
$(document).ready(function(){
    var $am = $('#sidebar-menu .nav.side-menu > li.active');
    if ($am.length) { $am.removeClass('active').find('ul.child_menu').hide(); $am.find('li.current-page').removeClass('current-page'); }
    $('#sidebar-menu').css('visibility','visible');
});

var COMPANY = '';
var lastTotal = 0;
var curPage = 1;

function esc(s){ return $('<div>').text(s==null?'':String(s)).html(); }

function todayStr(){ var d=new Date(); return d.toISOString().substr(0,10); }
function addDaysStr(days){ var d=new Date(); d.setDate(d.getDate()+days); return d.toISOString().substr(0,10); }

// 預設近30天
$('#fDateFrom').val(addDaysStr(-29));
$('#fDateTo').val(todayStr());

function curFilters(){
    return {
        date_from: $('#fDateFrom').val(),
        date_to: $('#fDateTo').val(),
        process_no: $('#fProcess').val(),
        machine_id: $('#fMachine').val(),
        person: $.trim($('#fPerson').val()),
        remark: $.trim($('#fRemark').val())
    };
}

function rowToTr(r){
    var isTemp = r.report_source === 'TEMP';
    var bomTxt = isTemp ? '臨時加工' : (esc(r.bom) + (r.d_id ? (' / ' + esc(r.d_id)) : ''));
    var custTxt = isTemp ? esc(r.source_reason || '') : esc(r.Client_Name);
    return '<tr>'
        + '<td>' + esc(egFmtDate(r.report_date)) + '</td>'
        + '<td>' + esc(r.ProcessName) + '</td>'
        + '<td>' + esc(r.machine) + '</td>'
        + '<td class="t-left">' + bomTxt + '</td>'
        + '<td>' + custTxt + '</td>'
        + '<td>' + esc(r.setup_user) + '</td>'
        + '<td>' + esc(r.prod_user) + '</td>'
        + '<td>' + esc(r.produced_qty) + '</td>'
        + '<td>' + (r.is_finished ? '是' : '否') + '</td>'
        + '<td>' + esc(r.process_face || '-') + '</td>'
        + '<td class="t-left">' + esc(r.remark || '') + '</td>'
        + '</tr>';
}

function renderPager(total, page, pageSize){
    var pages = Math.max(1, Math.ceil(total / pageSize));
    var $p = $('#prqPager').empty();
    $p.append('<span class="pg-info">第 ' + page + ' / ' + pages + ' 頁</span>');
    var $prev = $('<button><i class="fa fa-chevron-left"></i></button>').prop('disabled', page<=1).on('click', function(){ loadList(page-1); });
    $p.append($prev);
    var from = Math.max(1, page-2), to = Math.min(pages, from+4);
    from = Math.max(1, Math.min(from, to-4));
    for (var i=from; i<=to; i++){
        var $b = $('<button>'+i+'</button>');
        if (i===page) $b.addClass('cur');
        $b.on('click', (function(pn){ return function(){ loadList(pn); }; })(i));
        $p.append($b);
    }
    var $next = $('<button><i class="fa fa-chevron-right"></i></button>').prop('disabled', page>=pages).on('click', function(){ loadList(page+1); });
    $p.append($next);
}

function loadList(page){
    page = page || 1;
    curPage = page;
    var f = curFilters();
    f.action = 'list';
    f.page = page;
    f.page_size = $('#pageSizeSel').val();
    $('#prqTbody').html('<tr><td colspan="11" class="prq-empty"><i class="fa fa-spinner fa-spin"></i> 載入中...</td></tr>');
    $.post('', f, function(res){
        if (!res.success){ $('#prqTbody').html('<tr><td colspan="11" class="prq-empty">' + esc(res.message||'查詢失敗') + '</td></tr>'); return; }
        lastTotal = res.total;
        $('#statTotal').text(res.total);
        if (!res.rows.length){
            $('#prqTbody').html('<tr><td colspan="11" class="prq-empty">查無符合條件的報工紀錄</td></tr>');
        } else {
            $('#prqTbody').html(res.rows.map(rowToTr).join(''));
        }
        renderPager(res.total, res.page, res.page_size);
    }, 'json');
}

$('#btnSearch').on('click', function(){ loadList(1); });
['#fDateFrom','#fDateTo','#fProcess','#fMachine'].forEach(function(sel){
    $(sel).on('change', function(){ loadList(1); });
});
['#fPerson','#fRemark'].forEach(function(sel){
    $(sel).on('keyup', function(e){ if (e.key==='Enter') loadList(1); });
});
$('#pageSizeSel').on('change', function(){ loadList(1); });

$('#btnClear').on('click', function(){
    $('#fDateFrom, #fDateTo, #fPerson, #fRemark').val('');
    ['fProcess','fMachine'].forEach(function(id){
        var sel = document.getElementById(id);
        var box = sel.previousElementSibling;
        if (box && box.className && box.className.indexOf('eg-filter-box') >= 0) box.value = '';
        if (sel.egFilterResnap) sel.egFilterResnap();
        sel.value = '';
    });
    loadList(1);
});

function confirmLargeResult(actionLabel){
    if (lastTotal > 3000){
        return confirm('目前篩選結果共 ' + lastTotal + ' 筆，資料量較大，' + actionLabel + '可能需要一些時間，是否仍要繼續？');
    }
    return true;
}

// ── 列印：抓「全部」符合篩選條件的資料，開新視窗列印（不受分頁限制）──
$('#btnPrint').on('click', function(){
    if (!confirmLargeResult('列印')) return;
    var f = curFilters();
    f.action = 'get_print';
    $.post('', f, function(res){
        if (!res.success){ alert(res.message||'載入失敗'); return; }
        var dateTxt = (f.date_from||f.date_to) ? ((f.date_from||'不限')+' ～ '+(f.date_to||'不限')) : '不限日期(全部)';
        var sub = '共 ' + res.total + ' 筆｜日期：' + dateTxt + '｜列印日期：' + todayStr();
        var body = '<div class="p-comp">' + esc(res.company_name||'') + '</div>'
                 + '<div class="p-title">報工紀錄查詢列印</div>'
                 + '<div class="p-sub">' + esc(sub) + '</div>';
        body += '<table class="p-tb"><thead><tr><th>日期</th><th>製程</th><th>機台</th><th>工單/料號</th><th>客戶</th>'
              + '<th>設置人員</th><th>生產人員</th><th>數量</th><th>完工</th><th>備註</th></tr></thead><tbody>';
        res.rows.forEach(function(r){
            var isTemp = r.report_source === 'TEMP';
            var bomTxt = isTemp ? '臨時加工' : (esc(r.bom) + (r.d_id ? (' / '+esc(r.d_id)) : ''));
            var custTxt = isTemp ? esc(r.source_reason||'') : esc(r.Client_Name);
            body += '<tr><td>'+esc(egFmtDate(r.report_date))+'</td><td>'+esc(r.ProcessName)+'</td><td>'+esc(r.machine)+'</td>'
                  + '<td class="tl">'+bomTxt+'</td><td>'+custTxt+'</td><td>'+esc(r.setup_user)+'</td><td>'+esc(r.prod_user)+'</td>'
                  + '<td>'+esc(r.produced_qty)+'</td><td>'+(r.is_finished?'是':'否')+'</td><td class="tl">'+esc(r.remark||'')+'</td></tr>';
        });
        body += '</tbody></table>';
        if (!res.rows.length) body = body.replace('<tbody>','<tbody>').replace('</tbody></table>','</tbody></table>') + '<div style="padding:20px;color:#666;">查無符合條件的報工紀錄</div>';

        var css = 'body{font-family:"Microsoft JhengHei",sans-serif;margin:0;padding:0 6mm;color:#222;-webkit-print-color-adjust:exact;print-color-adjust:exact;}'
            + '.p-comp{font-size:22px;font-weight:bold;text-align:center;margin-bottom:1px;}'
            + '.p-title{font-size:17px;font-weight:bold;text-align:center;letter-spacing:6px;margin-bottom:2px;}'
            + '.p-sub{font-size:11px;text-align:center;color:#555;margin-bottom:10px;}'
            + 'table.p-tb{width:100%;table-layout:fixed;border-collapse:collapse;font-size:11px;margin-bottom:6px;}'
            + 'table.p-tb thead{display:table-header-group;}'
            + 'table.p-tb th,table.p-tb td{border:1px solid #666;padding:2px 4px;text-align:center;overflow-wrap:anywhere;}'
            + 'table.p-tb thead th{background:#f3ead6;}'
            + 'table.p-tb td.tl{text-align:left;word-break:break-all;}'
            + 'table.p-tb tr{break-inside:avoid;}'
            + '@page{margin:12mm 10mm 18mm;}';
        var w = window.open('', '_blank');
        w.document.write('<html><head><meta charset="utf-8"><title>報工紀錄查詢列印</title><style>'+css+'</style></head><body>'+body
            +'<scr'+'ipt>window.onload=function(){'
            +'var onePageA4=(297-30)*96/25.4;'
            +'if(document.body.scrollHeight>onePageA4*0.92){'
            +'var st=document.createElement(\'style\');'
            +'st.textContent="@page{ @bottom-left{ content:\'第 \' counter(page) \' 頁／共 \' counter(pages) \' 頁\'; font-size:9pt; color:#333; vertical-align:top; padding-top:1mm; } }";'
            +'document.head.appendChild(st);}'
            +'setTimeout(function(){window.print();},200);};</scr'+'ipt></body></html>');
        w.document.close();
    }, 'json');
});

// ── 匯出CSV：依目前篩選條件用表單送出觸發下載（非AJAX，才能觸發瀏覽器下載）──
$('#btnExportCsv').on('click', function(){
    if (!confirmLargeResult('匯出')) return;
    var f = curFilters();
    f.action = 'export_csv';
    var $form = $('<form method="POST" target="_blank"></form>').attr('action', '');
    $.each(f, function(k,v){ $form.append($('<input type="hidden">').attr('name', k).val(v)); });
    $('body').append($form);
    $form[0].submit();
    $form.remove();
});

$('#btnPageHelp').on('click', function(){ $('#helpUseMask').css('display','block'); });

loadList(1);
</script>
</body>
</html>
