<?php
/**
 * 報工紀錄查詢列印（2026-08-10 新增，測試功能）
 * 逐筆列出 pm_process_daily_report 每一筆報工紀錄（含臨時加工），供查找/列印用；
 * 與 process_schedule.php 的「查詢已報工工單」跳窗不同——那支是依工單(bom_ing_fid)彙總、
 * 用來恢復任務/改綁BOM等操作，這支是純瀏覽/列印每一筆報工紀錄，兩者並存不互相取代。
 * 篩選：日期區間(預設近30天，清空=不限日期查全部)、製程、機台、料號、人員(架機/生產人員合併比對)、備註。
 * 分頁走後端(不一次撈全部)；列印/CSV匯出走後端依目前篩選條件抓「全部」符合筆數(不受分頁限制)。
 * 製程/機台/料號/人員四個篩選皆為「動態連動清單」（get_facets action）：只列目前其餘篩選條件下仍有資料的選項，
 * 選了製程會連動縮小機台/人員清單，反之亦然，比照 pivot table 的 facet filter 做法，避免選出兜不出資料的組合。
 */
include_once '../../src/common/_config.php';
include "../../src/common/DBConnection.php";

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
// AS 文件編號綁定：後端一律走共用庫，禁止各頁自寫綁定讀寫 SQL（ai-rules/16 第一之三節）
require_once '../../src/common/asdoc_lib.php';
define('PRQ_ASDOC_MODULE', 'process_report_query');
// 料號建議清單上限：料號數以百計，全部塞進 datalist 只會拖慢且沒人捲得完；超過的仍可自行打字查（LIKE 模糊比對不受此限）
define('PRQ_PART_FACET_LIMIT', 500);

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

// 誰可以改「列印要綁哪一份 AS 文件」：具修改(U)或全權(A)者；其餘人只看得到目前綁定內容
$prq_can_bind = (strpos($permission_code, 'A') !== false || strpos($permission_code, 'U') !== false);

// ================= 共用：組出篩選條件（list / get_print / export_csv / get_facets 共用，避免各自重寫一份） =================
// $exclude：計算某個篩選欄位自己的可選清單(facet)時，要排除該欄位自己的條件，只套用「其餘」條件
function prq_build_filter($p, $exclude = []) {
    $where = [];
    $params = [];
    if (!in_array('date', $exclude, true)) {
        if (!empty($p['date_from'])) { $where[] = 'pdr.report_date >= ?'; $params[] = $p['date_from']; }
        if (!empty($p['date_to']))   { $where[] = 'pdr.report_date <= ?'; $params[] = $p['date_to']; }
    }
    if (!in_array('process_type_id', $exclude, true) && !empty($p['process_type_id'])) { $where[] = 'pn.process_type_id = ?'; $params[] = intval($p['process_type_id']); }
    if (!in_array('machine_id', $exclude, true) && !empty($p['machine_id'])) { $where[] = 'pdr.machine_id = ?'; $params[] = intval($p['machine_id']); }
    // 料號＝工單所屬 BOM 的料號；臨時加工(無綁工單)沒有料號，故一旦指定料號就不會出現在結果中
    if (!in_array('d_id', $exclude, true) && !empty($p['d_id'])) { $where[] = 'b.d_id LIKE ?'; $params[] = '%' . $p['d_id'] . '%'; }
    if (!in_array('person', $exclude, true) && !empty($p['person'])) {
        $where[] = '(CONVERT(u1.user_cname USING utf8mb4) LIKE ? OR CONVERT(u2.user_cname USING utf8mb4) LIKE ?)';
        $like = '%' . $p['person'] . '%';
        $params[] = $like; $params[] = $like;
    }
    if (!in_array('remark', $exclude, true) && !empty($p['remark'])) { $where[] = 'pdr.remark LIKE ?'; $params[] = '%' . $p['remark'] . '%'; }
    return [$where ? ('WHERE ' . implode(' AND ', $where)) : '', $params];
}
function prq_append_where($whereSql, $extra) {
    return $whereSql ? ($whereSql . ' AND ' . $extra) : ('WHERE ' . $extra);
}
function prq_time_range($start, $end) {
    $s = $start ? substr($start, 11, 5) : '';
    $e = $end ? substr($end, 11, 5) : '';
    if (!$s && !$e) return '';
    return $s . '~' . $e;
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
    pdr.setup_start_time, pdr.setup_end_time, pdr.production_start_time, pdr.production_end_time,
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
            fputcsv($out, ['日期', '製程', '機台', '工單', '料號', '客戶', '架機人員', '架機時間', '生產人員', '生產時間', '生產數量', '完工', '加工面', '備註']);
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $isTemp = $r['report_source'] === 'TEMP';
                fputcsv($out, [
                    $r['report_date'],
                    $r['ProcessName'],
                    $r['machine'],
                    $isTemp ? '臨時加工' : $r['bom'],
                    $isTemp ? '' : $r['d_id'],
                    $isTemp ? ($r['source_reason'] ?: '') : $r['Client_Name'],
                    $r['setup_user'],
                    prq_time_range($r['setup_start_time'], $r['setup_end_time']),
                    $r['prod_user'],
                    prq_time_range($r['production_start_time'], $r['production_end_time']),
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

            // 綁定的 AS 文件：表頭印 doc_name、頁尾右下印 doc_no（四階自動附加版次，由 eg_asdoc_no() 判斷）。
            // 本頁列印屬「多筆彙總的清單型列印」，依 ai-rules/16 第三之四節**不做**業務日期回推版次，一律印現況最新版。
            $doc = eg_asdoc_get($pdo, PRQ_ASDOC_MODULE);
            echo json_encode(['success' => true, 'rows' => $rows, 'total' => count($rows), 'company_name' => $company,
                'as_doc_name' => $doc['doc_name'] ?? '', 'as_doc_no' => eg_asdoc_no($doc)]);
        } elseif ($action === 'get_facets') {
            // 每個維度各自排除「自己」的篩選條件，只套用其餘條件，才能算出「若改選這個，還會有資料」的清單（雙向連動）
            // 製程按鈕比照 process_schedule.php「查詢已報工工單」：以製程大類(process_type)為單位，不是逐一製程
            list($whereP, $paramsP) = prq_build_filter($_POST, ['process_type_id']);
            $stmtP = $pdo->prepare("SELECT pn.process_type_id, pt.process_type AS category_name, COUNT(*) cnt
                $PRQ_FROM LEFT JOIN process_type pt ON pn.process_type_id = pt.process_type_id
                $whereP GROUP BY pn.process_type_id, pt.process_type ORDER BY pn.process_type_id");
            $stmtP->execute($paramsP);
            $processes = $stmtP->fetchAll(PDO::FETCH_ASSOC);

            list($whereM, $paramsM) = prq_build_filter($_POST, ['machine_id']);
            $stmtM = $pdo->prepare("SELECT pdr.machine_id, m.machine, COUNT(*) cnt $PRQ_FROM $whereM GROUP BY pdr.machine_id, m.machine ORDER BY m.position");
            $stmtM->execute($paramsM);
            $machines = $stmtM->fetchAll(PDO::FETCH_ASSOC);

            // 料號候選：只列目前其餘條件下真的有報工紀錄的料號（給前端 datalist 當建議清單用）
            list($whereD, $paramsD) = prq_build_filter($_POST, ['d_id']);
            $whereD = prq_append_where($whereD, "b.d_id IS NOT NULL AND b.d_id<>''");
            $stmtD = $pdo->prepare("SELECT DISTINCT b.d_id $PRQ_FROM $whereD ORDER BY b.d_id LIMIT " . PRQ_PART_FACET_LIMIT);
            $stmtD->execute($paramsD);
            $parts = $stmtD->fetchAll(PDO::FETCH_COLUMN);

            list($whereU, $paramsU) = prq_build_filter($_POST, ['person']);
            $whereU1 = prq_append_where($whereU, "u1.user_cname IS NOT NULL AND u1.user_cname<>''");
            $whereU2 = prq_append_where($whereU, "u2.user_cname IS NOT NULL AND u2.user_cname<>''");
            $stmtU = $pdo->prepare("(SELECT DISTINCT u1.user_cname AS nm $PRQ_FROM $whereU1) UNION (SELECT DISTINCT u2.user_cname AS nm $PRQ_FROM $whereU2) ORDER BY nm");
            $stmtU->execute(array_merge($paramsU, $paramsU));
            $people = $stmtU->fetchAll(PDO::FETCH_COLUMN);

            echo json_encode(['success' => true, 'processes' => $processes, 'machines' => $machines,
                'parts' => $parts, 'people' => $people]);
        } elseif ($action === 'asdoc_meta') {
            // 給 eg_asdoc_picker.js 用：可綁定文件清單＋目前綁定；can_bind 決定前端要不要顯示「變更綁定」鈕
            echo json_encode(['success' => true, 'docs' => eg_asdoc_list($pdo),
                'cur' => eg_asdoc_get($pdo, PRQ_ASDOC_MODULE), 'can_bind' => $prq_can_bind]);
        } elseif ($action === 'asdoc_save') {
            // 前端已依 can_bind 隱藏按鈕，後端仍再擋一次（鐵律8：不可只做前端擋）
            if (!$prq_can_bind) { echo json_encode(['success' => false, 'message' => '無權限修改 AS 文件綁定']); exit; }
            $uname = '';
            try {
                $stU = $pdo->prepare("SELECT user_cname FROM user WHERE id=?");
                $stU->execute([$id]);
                $uname = (string)$stU->fetchColumn();
            } catch (Exception $e) {}
            eg_asdoc_save($pdo, PRQ_ASDOC_MODULE, intval($_POST['doc_id'] ?? 0), $uname);
            echo json_encode(['success' => true, 'cur' => eg_asdoc_get($pdo, PRQ_ASDOC_MODULE)]);
        } else {
            echo json_encode(['success' => false, 'message' => '未知操作']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

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
        .prq-tabs { display: flex; flex-wrap: wrap; gap: 5px; align-items: center; clear: both;
            border: 1.5px solid #E8D5B5; border-radius: 8px; padding: 8px 10px; margin-bottom: 8px; background: #FDF8EF; }
        .prq-tab { height: 28px; font-size: 12px; line-height: 1; padding: 0 12px; border: 1px solid #D8BE93; border-radius: 14px;
            background: #fff; color: #5b3a1e; cursor: pointer; }
        .prq-tab:hover { background: #F7E0BD; }
        .prq-tab.active { background: #F0A24B; color: #fff; border-color: #d98a33; font-weight: bold; }
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

        <!-- 製程：比照 process_schedule.php 上方頁籤按鈕，只列目前其餘篩選條件下「有資料」的製程（雙向連動） -->
        <div class="prq-tabs" id="processTabs">
            <button class="prq-tab active" data-pn="">全部</button>
        </div>

        <div class="prq-toolbar">
            <div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;width:100%;">
                <label>日期</label>
                <input type="date" id="fDateFrom" max="9999-12-31">
                <span>～</span>
                <input type="date" id="fDateTo" max="9999-12-31">
                <label>機台</label>
                <select id="fMachine" data-eg-filter="輸入機台名稱篩選…" style="min-width:150px;">
                    <option value="">（全部）</option>
                </select>
                <label>備註</label>
                <input type="text" id="fRemark" placeholder="關鍵字" style="width:120px;">
                <button class="btn-warm" id="btnSearch"><i class="fa fa-search"></i> 查詢</button>
                <button id="btnClear"><i class="fa fa-eraser"></i> 清除篩選(查全部)</button>
                <button id="btnPrint" style="margin-left:auto;"><i class="fa fa-print"></i> 列印</button>
                <button id="btnExportCsv"><i class="fa fa-file-excel-o"></i> 匯出CSV</button>
            </div>
            <div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;width:100%;margin-top:6px;padding-top:6px;border-top:1px dashed #EADFC8;">
                <label style="margin-left:0;">料號</label>
                <input type="text" id="fPartNo" list="prqPartList" placeholder="料號關鍵字（可輸入部分字元）" style="width:180px;">
                <datalist id="prqPartList"></datalist>
                <label>人員</label>
                <input type="text" id="fPerson" list="prqPeopleList" placeholder="架機或生產人員姓名（目前篩選結果內才會出現在建議清單）" style="width:280px;">
                <datalist id="prqPeopleList"></datalist>
                <span style="margin-left:auto;display:flex;align-items:center;gap:6px;">
                    <label style="margin-left:0;">列印AS文件</label>
                    <!-- 表頭表單名稱與頁尾右下編號皆由綁定推導，一律唯讀反灰不給手填（ai-rules/16 第一之三節） -->
                    <input type="text" id="asDocLabel" readonly data-eg-skip="1" value="尚未綁定"
                        title="列印時：表頭顯示這份 AS 文件的表單名稱、頁尾右下角印文件編號（四階文件自動附加版次）。由綁定推導，不可手填。"
                        style="width:270px;background:#F3ECDF;color:#8a6d45;cursor:default;">
                    <button id="btnAsDocBind" style="display:none;"><i class="fa fa-link"></i> 變更綁定</button>
                </span>
            </div>
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
                    <th>日期</th><th>製程</th><th>機台</th><th class="t-left">工單</th><th class="t-left">料號</th><th>客戶</th>
                    <th>架機人員</th><th>架機時間</th><th>生產人員</th><th>生產時間</th><th>生產數量</th><th>完工</th><th>加工面</th><th class="t-left">備註</th>
                </tr></thead>
                <tbody id="prqTbody"><tr><td colspan="14" class="prq-empty">請設定篩選條件後查詢</td></tr></tbody>
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
            <li>上方篩選可組合使用：製程大類（頁籤按鈕，比照排程看板「查詢已報工工單」的分類方式）、日期區間、機台、料號、人員（同時比對架機/生產人員）、備註關鍵字。</li>
            <li>製程大類、機台、料號、人員的可選清單會依「目前其餘篩選條件」動態連動——只列真的有資料的選項，選了製程大類會連動縮小機台/料號/人員清單，反之亦然。</li>
            <li><b>料號</b>可直接打字（部分字元即可，例如打 <code>RC016</code> 就找得到 <code>RC016011-02</code>），也可從輸入框的建議清單挑選；建議清單只列目前篩選條件下真的有報工紀錄的料號、最多 500 筆，超過的部分仍可自行打字查得到。</li>
            <li>日期區間預設近30天；按「清除篩選(查全部)」可清空所有條件、改查全部歷史資料。</li>
            <li>列表分頁顯示（避免一次載入全部拖慢速度），可調整每頁筆數。</li>
            <li>「列印」「匯出CSV」皆依目前篩選條件抓「全部」符合筆數（不受分頁限制）。</li>
            <li>列印前可先確認右側「列印AS文件」顯示的綁定文件；具修改權限者按「變更綁定」即可用文件編號或名稱關鍵字搜尋、改綁其他 AS 文件。</li>
        </ul>
        <h4>重要行為/常見疑問</h4>
        <div class="tip">若篩選結果筆數較多（超過3000筆），列印/匯出前會先跳出確認提示，避免不小心產生過大的列印工作。</div>
        <ul>
            <li>臨時加工（無綁定工單）的「工單」欄會顯示「臨時加工」、「料號」欄留空，客戶欄改顯示加工原因；<b>因為沒有料號，只要有指定料號篩選，臨時加工的紀錄就不會出現在結果中</b>。</li>
            <li>「架機人員」＝原「設置人員」正名；架機/生產時間欄留空代表該筆報工未填該項時間。</li>
            <li><b>列印版的 AS 文件編號</b>：綁定後，列印時大標題下方那一行會自動改印該 AS 文件的<b>表單名稱</b>，頁尾<b>右下角每一頁</b>都會印出<b>文件編號</b>（四階文件會自動附加版次，例 2-PM-01-01A）。未綁定時表頭退回顯示「報工紀錄查詢列印」、右下角不印編號。</li>
            <li>本頁列印屬「多筆彙總的清單型列印」，印的是<b>當下現況</b>，所以文件版次一律取目前最新版，不會依報工日期回推舊版次。</li>
            <li>編號與表單名稱都直接取自 AS 文件管理主檔，日後該文件改名或改版，本頁列印會自動跟著變，不需回來改設定。</li>
        </ul>
        <h4>設定入口</h4>
        <p>報工資料無需另外設定，即時取自報工系統。列印用的 AS 文件編號綁定在本頁工具列右側「列印AS文件 → 變更綁定」；文件本身（名稱、編號、版次）於「AS 文件管理」維護。</p>
        <h4>權限角色</h4>
        <p>凡對「加工排程看板」（測試功能）具有任一權限（檢視/新增/修改/刪除）者即可使用本頁查詢與列印。<b>變更 AS 文件綁定</b>則另需<b>修改(U)或全權(A)</b>權限，其餘人只看得到目前綁定內容、不能更動。</p>
    </div>
    <div class="m-foot"><button onclick="document.getElementById('helpUseMask').style.display='none'">我知道了</button></div>
</div></div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script src="../../resource/js/eg_input_rules.js?v=<?= @filemtime(__DIR__ . '/../../resource/js/eg_input_rules.js') ?>"></script>
<script src="../../resource/js/eg_date_fmt.js?v=<?= @filemtime(__DIR__ . '/../../resource/js/eg_date_fmt.js') ?>"></script>
<script src="../../resource/js/eg_asdoc_picker.js?v=<?= @filemtime(__DIR__ . '/../../resource/js/eg_asdoc_picker.js') ?>"></script>
<script>
$(document).ready(function(){
    var $am = $('#sidebar-menu .nav.side-menu > li.active');
    if ($am.length) { $am.removeClass('active').find('ul.child_menu').hide(); $am.find('li.current-page').removeClass('current-page'); }
    $('#sidebar-menu').css('visibility','visible');
});

var COMPANY = '';
var lastTotal = 0;
var curPage = 1;
var curProcess = ''; // 製程改用「製程大類」頁籤按鈕，不再是逐一製程 <select>，用全域變數記目前選中的 process_type_id

function esc(s){ return $('<div>').text(s==null?'':String(s)).html(); }

function todayStr(){ var d=new Date(); return d.toISOString().substr(0,10); }
function addDaysStr(days){ var d=new Date(); d.setDate(d.getDate()+days); return d.toISOString().substr(0,10); }
function hm(dt){ // 只取 HH:MM
    if (!dt) return '';
    var m = String(dt).match(/(\d{2}):(\d{2})/);
    return m ? (m[1]+':'+m[2]) : '';
}
function timeRange(s, e){
    var a = hm(s), b = hm(e);
    if (!a && !b) return '-';
    return a + '~' + b;
}

// 預設近30天
$('#fDateFrom').val(addDaysStr(-29));
$('#fDateTo').val(todayStr());

function curFilters(){
    return {
        date_from: $('#fDateFrom').val(),
        date_to: $('#fDateTo').val(),
        process_type_id: curProcess,
        machine_id: $('#fMachine').val(),
        d_id: $.trim($('#fPartNo').val()),
        person: $.trim($('#fPerson').val()),
        remark: $.trim($('#fRemark').val())
    };
}

function rowToTr(r){
    var isTemp = r.report_source === 'TEMP';
    var bomTxt = isTemp ? '臨時加工' : esc(r.bom);
    var didTxt = isTemp ? '' : esc(r.d_id);
    var custTxt = isTemp ? esc(r.source_reason || '') : esc(r.Client_Name);
    return '<tr>'
        + '<td>' + esc(egFmtDate(r.report_date)) + '</td>'
        + '<td>' + esc(r.ProcessName) + '</td>'
        + '<td>' + esc(r.machine) + '</td>'
        + '<td class="t-left">' + bomTxt + '</td>'
        + '<td class="t-left">' + didTxt + '</td>'
        + '<td>' + custTxt + '</td>'
        + '<td>' + esc(r.setup_user) + '</td>'
        + '<td>' + esc(timeRange(r.setup_start_time, r.setup_end_time)) + '</td>'
        + '<td>' + esc(r.prod_user) + '</td>'
        + '<td>' + esc(timeRange(r.production_start_time, r.production_end_time)) + '</td>'
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
    $('#prqTbody').html('<tr><td colspan="14" class="prq-empty"><i class="fa fa-spinner fa-spin"></i> 載入中...</td></tr>');
    $.post('', f, function(res){
        if (!res.success){ $('#prqTbody').html('<tr><td colspan="14" class="prq-empty">' + esc(res.message||'查詢失敗') + '</td></tr>'); return; }
        lastTotal = res.total;
        $('#statTotal').text(res.total);
        if (!res.rows.length){
            $('#prqTbody').html('<tr><td colspan="14" class="prq-empty">查無符合條件的報工紀錄</td></tr>');
        } else {
            $('#prqTbody').html(res.rows.map(rowToTr).join(''));
        }
        renderPager(res.total, res.page, res.page_size);
    }, 'json');
}

// ── 動態連動篩選（facet）：製程大類頁籤／機台下拉／人員建議清單，皆依「目前其餘篩選條件」重新計算 ──
// 製程按鈕比照 process_schedule.php「查詢已報工工單」以製程大類(process_type)為單位，不是逐一製程。
// 只列有資料的選項；若目前選中的大類/機台在新清單裡消失了(跟其他篩選兜不出資料)，自動改回「全部」。
function renderProcessTabs(list){
    var $t = $('#processTabs').empty();
    var total = 0;
    list.forEach(function(p){ total += p.cnt; });
    $('<button class="prq-tab' + (curProcess===''?' active':'') + '">全部（' + total + '）</button>')
        .on('click', function(){ curProcess=''; applyFilters(); }).appendTo($t);
    var stillValid = false;
    list.forEach(function(p){
        var tid = String(p.process_type_id);
        if (tid === curProcess) stillValid = true;
        $('<button class="prq-tab' + (tid===curProcess?' active':'') + '">' + esc(p.category_name || '（未分類）') + '（' + p.cnt + '）</button>')
            .on('click', function(){ curProcess=tid; applyFilters(); }).appendTo($t);
    });
    if (curProcess !== '' && !stillValid) curProcess = ''; // 跟其他篩選兜不出資料，自動改回全部
}
function renderMachineOptions(list){
    var $sel = $('#fMachine');
    var cur = $sel.val();
    var html = '<option value="">（全部）</option>';
    var stillValid = false;
    list.forEach(function(m){
        var id = String(m.machine_id);
        if (id === cur) stillValid = true;
        html += '<option value="' + id + '">' + esc(m.machine) + '（' + m.cnt + '）</option>';
    });
    $sel[0].innerHTML = html;
    $sel.val(stillValid ? cur : '');
    if ($sel[0].egFilterResnap) $sel[0].egFilterResnap();
}
function renderPartDatalist(list){
    $('#prqPartList').html(list.map(function(d){ return '<option value="' + esc(d) + '">'; }).join(''));
}
function renderPeopleDatalist(list){
    $('#prqPeopleList').html(list.map(function(nm){ return '<option value="' + esc(nm) + '">'; }).join(''));
}

function refreshFacets(cb){
    var f = curFilters();
    f.action = 'get_facets';
    $.post('', f, function(res){
        if (res.success){
            renderProcessTabs(res.processes || []);
            renderMachineOptions(res.machines || []);
            renderPartDatalist(res.parts || []);
            renderPeopleDatalist(res.people || []);
        }
        if (cb) cb();
    }, 'json');
}

function applyFilters(){ refreshFacets(function(){ loadList(1); }); }

$('#btnSearch').on('click', applyFilters);
['#fDateFrom','#fDateTo','#fMachine'].forEach(function(sel){
    $(sel).on('change', applyFilters);
});
['#fPartNo','#fPerson','#fRemark'].forEach(function(sel){
    $(sel).on('keyup', function(e){ if (e.key==='Enter') applyFilters(); });
});
$('#pageSizeSel').on('change', function(){ loadList(1); });

$('#btnClear').on('click', function(){
    $('#fDateFrom, #fDateTo, #fPartNo, #fPerson, #fRemark').val('');
    curProcess = '';
    var sel = document.getElementById('fMachine');
    var box = sel.previousElementSibling;
    if (box && box.className && box.className.indexOf('eg-filter-box') >= 0) box.value = '';
    sel.value = '';
    applyFilters();
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
        var sub = '共 ' + res.total + ' 筆｜日期：' + dateTxt + '｜列印日期：' + egFmtDate(todayStr());
        // 表頭＝綁定 AS 文件的表單名稱（禁寫死）；未綁定才退回本頁預設標題（ai-rules/16 第一之二節）
        var docTitle = res.as_doc_name || '報工紀錄查詢列印';
        // 頁尾右下角文件編號：塞進 CSS content 前先濾掉 ' 與 \，避免撐破 CSS（ai-rules/16 第三節）
        var docNo = String(res.as_doc_no || '').replace(/['\\]/g, '');
        var body = '<div class="p-comp">' + esc(res.company_name||'') + '</div>'
                 + '<div class="p-title">' + esc(docTitle) + '</div>'
                 + '<div class="p-sub">' + esc(sub) + '</div>';
        body += '<table class="p-tb"><thead><tr><th>日期</th><th>製程</th><th>機台</th><th>工單</th><th>料號</th><th>客戶</th>'
              + '<th>架機人員</th><th>架機時間</th><th>生產人員</th><th>生產時間</th><th>數量</th><th>完工</th><th>備註</th></tr></thead><tbody>';
        res.rows.forEach(function(r){
            var isTemp = r.report_source === 'TEMP';
            var bomTxt = isTemp ? '臨時加工' : esc(r.bom);
            var didTxt = isTemp ? '' : esc(r.d_id);
            var custTxt = isTemp ? esc(r.source_reason||'') : esc(r.Client_Name);
            body += '<tr><td>'+esc(egFmtDate(r.report_date))+'</td><td>'+esc(r.ProcessName)+'</td><td>'+esc(r.machine)+'</td>'
                  + '<td class="tl">'+bomTxt+'</td><td class="tl">'+didTxt+'</td><td>'+custTxt+'</td>'
                  + '<td>'+esc(r.setup_user)+'</td><td>'+esc(timeRange(r.setup_start_time, r.setup_end_time))+'</td>'
                  + '<td>'+esc(r.prod_user)+'</td><td>'+esc(timeRange(r.production_start_time, r.production_end_time))+'</td>'
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
            + '@page{margin:12mm 10mm 18mm;'
            + (docNo ? " @bottom-right{ content:'"+docNo+"'; font-size:9pt; color:#333; vertical-align:top; padding-top:1mm; }" : '')
            + '}';
        var w = window.open('', '_blank');
        // 開頭一定要有 <!DOCTYPE html>：漏寫會落入 Quirks Mode，scrollHeight 恆等於視窗高度，
        // 單頁文件也會誤判成多頁而印出「第1頁／共1頁」（ai-rules/16 第三之二節踩坑）
        w.document.write('<!DOCTYPE html><html><head><meta charset="utf-8"><title>'+esc(docTitle)+'</title><style>'+css+'</style></head><body>'+body
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

// ── AS 文件編號綁定：UI 一律走共用 eg_asdoc_picker.js（打編號即時篩選＋清單），禁止自刻下拉（ai-rules/16 第一之三節）──
var AS_DOCS = [], AS_CUR = null;
function renderAsDocLabel(){
    $('#asDocLabel').val(AS_CUR ? EGAsDoc.label(AS_CUR) : '尚未綁定');
}
function loadAsDoc(){
    $.post('', {action:'asdoc_meta'}, function(res){
        if (!res.success) return;
        AS_DOCS = res.docs || [];
        AS_CUR = res.cur || null;
        if (res.can_bind) $('#btnAsDocBind').show();
        renderAsDocLabel();
    }, 'json');
}
$('#btnAsDocBind').on('click', function(){
    EGAsDoc.open({
        docs: AS_DOCS, current: AS_CUR ? AS_CUR.id : 0,
        title: '報工紀錄查詢列印 AS 文件編號綁定',
        onSave: function(id){
            $.post('', {action:'asdoc_save', doc_id:id}, function(res){
                if (!res.success){ alert(res.message || '儲存綁定失敗'); return; }
                AS_CUR = res.cur || null;
                renderAsDocLabel();
                alert(id ? '已儲存綁定' : '已解除綁定');
            }, 'json');
        }
    });
});

$('#btnPageHelp').on('click', function(){ $('#helpUseMask').css('display','block'); });

loadAsDoc();
applyFilters();
</script>
</body>
</html>
