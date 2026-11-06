<?php
session_start();
if (!isset($_SESSION['userName'])) {
    header("Location:../../index.php");
    exit;
}

include '../../src/common/DBConnection.php';
include '../../src/store/_setting.php';
include '../../src/common/_config.php';
include_once '../../src/common/date_fmt_lib.php';

$conn = new DBConnection();
$pdo = $conn->getPDO();

// ===== 0. 排除廠商設定（system_parameters：MACHINE_EFF / miss_excl_makers，存 maker_id_no 陣列）=====
if (empty($_SESSION['mpe_csrf'])) $_SESSION['mpe_csrf'] = bin2hex(random_bytes(16));
$excl_makers = [];
try {
    $st0 = $pdo->prepare("SELECT param_value FROM system_parameters WHERE param_group='MACHINE_EFF' AND param_key='miss_excl_makers' LIMIT 1");
    $st0->execute();
    $d0 = json_decode((string)$st0->fetchColumn(), true);
    if (is_array($d0)) $excl_makers = array_values(array_map('strval', $d0));
} catch (Throwable $e) {}
if (($_POST['action'] ?? '') === 'save_excl') {
    header('Content-Type: application/json; charset=utf-8');
    if (!hash_equals((string)$_SESSION['mpe_csrf'], (string)($_POST['csrf'] ?? ''))) { echo json_encode(['ok' => 0, 'msg' => '連線憑證失效，請重新整理頁面']); exit; }
    $ids = json_decode((string)($_POST['ids'] ?? '[]'), true);
    if (!is_array($ids)) $ids = [];
    $valid = $pdo->query("SELECT maker_id_no FROM maker_list WHERE internal = 1")->fetchAll(PDO::FETCH_COLUMN);
    $ids = array_values(array_unique(array_intersect(array_map('strval', $ids), array_map('strval', $valid))));
    $json = json_encode($ids, JSON_UNESCAPED_UNICODE);
    $by = (string)($_SESSION['userName'] ?? '');
    $ex = $pdo->query("SELECT id FROM system_parameters WHERE param_group='MACHINE_EFF' AND param_key='miss_excl_makers' LIMIT 1")->fetchColumn();
    if ($ex) $pdo->prepare("UPDATE system_parameters SET param_value=?, updated_by=?, updated_at=NOW() WHERE id=?")->execute([$json, $by, $ex]);
    else $pdo->prepare("INSERT INTO system_parameters (param_group,param_key,param_value,description,updated_by,updated_at) VALUES ('MACHINE_EFF','miss_excl_makers',?,?,?,NOW())")->execute([$json, '機台與人員效率分析：廠內加工未報工清單排除的廠商', $by]);
    echo json_encode(['ok' => 1]); exit;
}
$internal_makers = $pdo->query("SELECT maker_id_no, maker_id AS nm FROM maker_list WHERE internal = 1 ORDER BY maker_id")->fetchAll(PDO::FETCH_ASSOC);

// ===== 1. 篩選參數 =====
$ymd = function ($v, $def) { return (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) ? $v : $def; };
$start_date = $ymd($_GET['start_date'] ?? '', date('Y-m-01'));
$end_date   = $ymd($_GET['end_date'] ?? '', date('Y-m-d'));
if ($end_date < $start_date) { $t = $start_date; $start_date = $end_date; $end_date = $t; }
$filter_machine_type = (string)($_GET['machine_type_id'] ?? '');
$filter_machine      = (string)($_GET['machine_id'] ?? '');
$filter_user         = (string)($_GET['user_id'] ?? '');
$hours_per_day       = (int)($_GET['hpd'] ?? 24);
$unit = in_array(($_GET['unit'] ?? ''), ['month', 'quarter', 'half', 'year'], true) ? $_GET['unit'] : '';
if (!in_array($hours_per_day, [8, 16, 24], true)) $hours_per_day = 24;

$days_diff = (int)round((strtotime($end_date) - strtotime($start_date)) / 86400) + 1;
$capacity_per_machine = $days_diff * $hours_per_day; // 稼動率分母(小時)

// ===== 2. 篩選選單 =====
$machine_types = $pdo->query("SELECT process_type_id AS id, process_type AS nm FROM process_type ORDER BY process_type_id")->fetchAll(PDO::FETCH_ASSOC);
$machines = $pdo->query("SELECT machine_id, COALESCE(NULLIF(field_no,''),machine) AS nm, machine_type_id FROM machine_list WHERE (state IS NULL OR state != '1') ORDER BY nm")->fetchAll(PDO::FETCH_ASSOC);
$users = $pdo->query("SELECT DISTINCT u.id, u.user_cname FROM user u JOIN pm_process_daily_report r ON (u.id = r.setup_user_id OR u.id = r.production_user_id) ORDER BY u.user_cname")->fetchAll(PDO::FETCH_ASSOC);

// ===== 3. 報工資料 =====
$sql = "
    SELECT r.report_id, r.report_date, r.machine_id,
           COALESCE(NULLIF(m.field_no,''), m.machine) AS machine, m.machine_type_id,
           r.setup_user_id, u_s.user_cname AS setup_user_name,
           r.production_user_id, u_p.user_cname AS prod_user_name,
           r.produced_qty, r.is_finished,
           (SELECT COALESCE(SUM(ng_qty),0) FROM pm_process_daily_ng WHERE report_id = r.report_id) AS ng_qty,
           r.setup_start_time, r.setup_end_time, r.production_start_time, r.production_end_time,
           TIMESTAMPDIFF(SECOND, r.setup_start_time, r.setup_end_time) AS setup_seconds,
           TIMESTAMPDIFF(SECOND, r.production_start_time, r.production_end_time) AS prod_seconds,
           bi.bom, b.Client_Name, pn.ProcessName
    FROM pm_process_daily_report r
    LEFT JOIN machine_list m ON r.machine_id = m.machine_id
    LEFT JOIN user u_s ON r.setup_user_id = u_s.id
    LEFT JOIN user u_p ON r.production_user_id = u_p.id
    LEFT JOIN bom_ing bi ON r.bom_ing_fid = bi.bom_ing_fid
    LEFT JOIN bom b ON bi.bom = b.bom
    LEFT JOIN process_no pn ON r.process_no = pn.ProcessNo
    WHERE r.report_date BETWEEN :start AND :end";
$params = [':start' => $start_date, ':end' => $end_date];
if ($filter_machine !== '') { $sql .= " AND r.machine_id = :mid"; $params[':mid'] = $filter_machine; }
elseif ($filter_machine_type !== '') { $sql .= " AND m.machine_type_id = :mtid"; $params[':mtid'] = $filter_machine_type; }
if ($filter_user !== '') { $sql .= " AND (r.setup_user_id = :uid1 OR r.production_user_id = :uid2)"; $params[':uid1'] = $filter_user; $params[':uid2'] = $filter_user; }
$sql .= " ORDER BY r.report_date DESC, r.report_id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ===== 4. 彙總 =====
$mach = [];  // 機台
$usr = [];   // 人員
$tot = ['setup' => 0, 'prod' => 0, 'qty' => 0, 'ng' => 0];
foreach ($rows as $r) {
    $ss = max(0, (int)$r['setup_seconds']);
    $ps = max(0, (int)$r['prod_seconds']);
    $q = (int)$r['produced_qty'];
    $ng = (int)$r['ng_qty'];
    $tot['setup'] += $ss; $tot['prod'] += $ps; $tot['qty'] += $q; $tot['ng'] += $ng;

    $mk = (string)$r['machine_id'];
    if (!isset($mach[$mk])) $mach[$mk] = ['name' => $r['machine'] ?: '未指定機台', 'type' => $r['machine_type_id'], 'n' => 0, 'setup' => 0, 'prod' => 0, 'qty' => 0, 'ng' => 0, 'days' => []];
    $mach[$mk]['n']++; $mach[$mk]['setup'] += $ss; $mach[$mk]['prod'] += $ps; $mach[$mk]['qty'] += $q; $mach[$mk]['ng'] += $ng;
    $mach[$mk]['days'][$r['report_date']] = 1;

    if ($r['prod_user_name']) {
        $k = $r['production_user_id'];
        if (!isset($usr[$k])) $usr[$k] = ['name' => $r['prod_user_name'], 'n' => 0, 'setup' => 0, 'prod' => 0, 'qty' => 0, 'ng' => 0, 'days' => []];
        $usr[$k]['n']++; $usr[$k]['prod'] += $ps; $usr[$k]['qty'] += $q; $usr[$k]['ng'] += $ng; $usr[$k]['days'][$r['report_date']] = 1;
    }
    if ($r['setup_user_name']) {
        $k = $r['setup_user_id'];
        if (!isset($usr[$k])) $usr[$k] = ['name' => $r['setup_user_name'], 'n' => 0, 'setup' => 0, 'prod' => 0, 'qty' => 0, 'ng' => 0, 'days' => []];
        $usr[$k]['setup'] += $ss; $usr[$k]['days'][$r['report_date']] = 1;
    }
}
$h = function ($sec) { return round($sec / 3600, 1); };
$pct = function ($a, $b) { return $b > 0 ? round($a * 100 / $b, 1) : 0; };
$rate = function ($qty, $sec) { return $sec > 0 ? round($qty / ($sec / 3600), 1) : null; };

foreach ($mach as &$m) { $m['util'] = $capacity_per_machine > 0 ? round($m['prod'] / 3600 * 100 / $capacity_per_machine, 1) : 0; $m['eff'] = $rate($m['qty'], $m['prod']); $m['ngr'] = $pct($m['ng'], $m['qty'] + $m['ng']); } unset($m);
foreach ($usr as &$u) { $u['eff'] = $rate($u['qty'], $u['prod']); $u['ngr'] = $pct($u['ng'], $u['qty'] + $u['ng']); } unset($u);
uasort($mach, function ($a, $b) { return ($b['prod'] + $b['setup']) <=> ($a['prod'] + $a['setup']); });
uasort($usr, function ($a, $b) { return ($b['prod'] + $b['setup']) <=> ($a['prod'] + $a['setup']); });

$tot_sec = $tot['setup'] + $tot['prod'];
$avg_eff = $rate($tot['qty'], $tot['prod']);
$ng_rate = $pct($tot['ng'], $tot['qty'] + $tot['ng']);

// ===== 5. 廠內加工廠商：有檢驗紀錄但沒有報工 =====
// 廠內加工廠商 = maker_list.internal = 1；該站(bom_ing)在 qc_check 有檢驗、且 pm_process_daily_report 完全沒有該站報工
$miss = [];
$miss_err = '';
try {
    $ms = $pdo->prepare("
        SELECT bi.bom_ing_fid, bi.bom, bi.bom_sn, ml.maker_id AS maker_name, ml.maker_id_no,
               pn.ProcessName, b.Client_Name, b.d_id AS part_no,
               MAX(q.QC_check_date) AS last_qc, COUNT(*) AS qc_cnt,
               GROUP_CONCAT(DISTINCT q.QC_check ORDER BY q.QC_check SEPARATOR '/') AS qc_status,
               COALESCE(SUM(q.QC_ok_sqty),0) AS ok_qty, COALESCE(SUM(q.QC_QQ_sqty),0) AS qq_qty
        FROM qc_check q
        JOIN bom_ing bi ON bi.bom_ing_fid = CAST(q.bom_ing_fid_ref AS UNSIGNED)
        JOIN maker_list ml ON ml.maker_id_no = bi.maker_id_no AND ml.internal = 1
        LEFT JOIN process_no pn ON pn.ProcessNo = bi.process_no
        LEFT JOIN bom b ON b.bom = bi.bom
        WHERE q.QC_check_date >= :s AND q.QC_check_date < DATE_ADD(:e, INTERVAL 1 DAY)
          AND NOT EXISTS (SELECT 1 FROM pm_process_daily_report r WHERE r.bom_ing_fid = bi.bom_ing_fid)
          " . ($excl_makers ? "AND ml.maker_id_no NOT IN (" . implode(',', array_map(function ($v) use ($pdo) { return $pdo->quote($v); }, $excl_makers)) . ")" : "") . "
        GROUP BY bi.bom_ing_fid, bi.bom, bi.bom_sn, ml.maker_id, ml.maker_id_no, pn.ProcessName, b.Client_Name, b.d_id
        ORDER BY last_qc DESC");
    $ms->execute([':s' => $start_date, ':e' => $end_date]);
    $miss = $ms->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $miss_err = '查詢失敗：' . $e->getMessage();
}
$miss_by_maker = [];
foreach ($miss as $x) { $miss_by_maker[$x['maker_name']] = ($miss_by_maker[$x['maker_name']] ?? 0) + 1; }
arsort($miss_by_maker);

// ===== 5b. 有移轉單據、但該站缺 QC 檢驗或缺報工（資料來源：製程移轉單 bom_ing_transfer_log）=====
$tchk = [];
$tchk_err = '';
try {
    $ts = $pdo->prepare("
        SELECT bi.bom_ing_fid, t.bom, t.bom_sn, MAX(t.transfer_date) AS last_date,
               GROUP_CONCAT(DISTINCT t.transfer_no ORDER BY t.transfer_no SEPARATOR ', ') AS tr_nos, SUM(t.transfer_qty) AS tr_qty,
               MAX(m.maker_id) AS maker_name, MIN(t.maker_from) AS maker_code, MAX(m.internal) AS is_internal,
               MAX(pn.ProcessName) AS ProcessName, MAX(b.Client_Name) AS Client_Name, COALESCE(MAX(b.d_id), MAX(t.product_id)) AS part_no,
               (SELECT COUNT(*) FROM qc_check q WHERE q.bom_ing_fid_ref = CAST(bi.bom_ing_fid AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci) AS qc_cnt,
               (SELECT COUNT(*) FROM pm_process_daily_report r WHERE r.bom_ing_fid = bi.bom_ing_fid) AS rep_cnt
        FROM bom_ing_transfer_log t
        JOIN bom_ing bi ON bi.bom = t.bom AND bi.bom_sn = t.bom_sn
        LEFT JOIN bom b ON b.bom = t.bom
        LEFT JOIN maker_list m ON m.maker_id_no = t.maker_from
        LEFT JOIN process_no pn ON pn.ProcessNo = bi.process_no
        WHERE t.transfer_date BETWEEN :s AND :e
        GROUP BY bi.bom_ing_fid, t.bom, t.bom_sn
        HAVING qc_cnt = 0 OR rep_cnt = 0
        ORDER BY last_date DESC, t.bom, t.bom_sn");
    $ts->execute([':s' => $start_date, ':e' => $end_date]);
    $tchk = $ts->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $ex) { $tchk_err = '查詢失敗：' . $ex->getMessage(); }
$tc_noqc = $tc_norep = $tc_both = 0;
foreach ($tchk as $__c) { $a = (int)$__c['qc_cnt'] === 0; $b = (int)$__c['rep_cnt'] === 0; if ($a) $tc_noqc++; if ($b) $tc_norep++; if ($a && $b) $tc_both++; }

// ===== 6. 重點發現 =====
$insights = [];
if (count($miss) > 0) {
    $top = array_key_first($miss_by_maker);
    $insights[] = ['warn', '廠內加工有 ' . count($miss) . ' 站有檢驗紀錄卻沒有報工，最多的是「' . $top . '」(' . $miss_by_maker[$top] . ' 站)。', 'tab_miss'];
}
if ($tot['qty'] + $tot['ng'] > 0 && $ng_rate >= 3) {
    $insights[] = ['warn', '整體不良率 ' . $ng_rate . '%（NG ' . number_format($tot['ng']) . ' / 總產出 ' . number_format($tot['qty'] + $tot['ng']) . '）。', 'tab_mach'];
}
$mach_eff = array_filter($mach, function ($x) { return $x['eff'] !== null && $x['prod'] >= 3600 * 5; });
if (count($mach_eff) >= 2) {
    uasort($mach_eff, function ($a, $b) { return $b['eff'] <=> $a['eff']; });
    $best = reset($mach_eff); $worst = end($mach_eff);
    $insights[] = ['info', '時產最高機台：' . $best['name'] . '（' . $best['eff'] . ' PCS/H）；最低：' . $worst['name'] . '（' . $worst['eff'] . ' PCS/H）。（生產工時 5 小時以上才比較）', 'tab_mach'];
}
$idle = 0;
foreach ($machines as $mm) { if ($filter_machine === '' && $filter_machine_type === '' && !isset($mach[(string)$mm['machine_id']])) $idle++; }
if ($idle > 0) $insights[] = ['info', '期間內沒有任何報工的機台共 ' . $idle . ' 台。', 'tab_mach'];
if (!$insights) $insights[] = ['ok', '期間內沒有需要特別注意的項目。', ''];

// ===== 7. 圖表資料 =====
$c_m = ['cats' => [], 'prod' => [], 'setup' => []];
foreach (array_slice($mach, 0, 15, true) as $m) { $c_m['cats'][] = $m['name']; $c_m['prod'][] = $h($m['prod']); $c_m['setup'][] = $h($m['setup']); }
$c_u = ['cats' => [], 'prod' => [], 'setup' => []];
foreach (array_slice($usr, 0, 15, true) as $u) { $c_u['cats'][] = $u['name']; $c_u['prod'][] = $h($u['prod']); $c_u['setup'][] = $h($u['setup']); }
$c_e = ['cats' => [], 'eff' => []];
$usr_eff = array_filter($usr, function ($x) { return $x['eff'] !== null; });
uasort($usr_eff, function ($a, $b) { return $b['eff'] <=> $a['eff']; });
foreach (array_slice($usr_eff, 0, 15, true) as $u) { $c_e['cats'][] = $u['name']; $c_e['eff'][] = $u['eff']; }

$e = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
$tm = function ($s) { return $s ? substr($s, 11, 5) : ''; };
$qs_keep = http_build_query(['start_date' => $start_date, 'end_date' => $end_date]);
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>機台與人員效率分析</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <link href="../../resource/css/dataTables.bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/buttons.bootstrap.css" rel="stylesheet">
    <style>
        :root { --amber:#D98A33; --amber-d:#8A5A2B; --sand:#F7E0BD; --cream:#FFF7E8; --coral:#DD5138; --brown:#5b3a1e; }
        .right_col { color: var(--brown); }
        .right_col > .wrap { clear: both; }
        .pg-head { display:flex; align-items:center; gap:10px; margin:6px 0 12px; flex-wrap:wrap; }
        .pg-head h3 { margin:0; color:var(--amber-d); font-weight:700; }
        .page-help-btn { margin-left:auto; height:30px; font-size:13px; padding:0 12px; border:1px solid var(--amber); border-radius:15px; background:#fff; color:var(--amber-d); cursor:pointer; }
        .page-help-btn:hover { background:var(--amber); color:#fff; }
        @media print { .page-help-btn, .filter-box, .nav-tabs { display:none !important; } }
        .filter-box { background:var(--cream); border:1px solid var(--sand); border-radius:8px; padding:10px 14px; margin-bottom:14px; }
        .filter-box .fi { display:inline-block; margin:3px 12px 3px 0; vertical-align:middle; }
        .filter-box label { font-weight:600; margin:0 4px 0 0; font-size:13px; }
        .filter-box .form-control { height:30px; padding:2px 8px; font-size:13px; }
        .btn-amber { background:var(--amber); border-color:var(--amber); color:#fff; }
        .btn-amber:hover { background:var(--amber-d); border-color:var(--amber-d); color:#fff; }
        .kpis { display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:10px; margin-bottom:14px; }
        .kpi { background:#fff; border:1px solid var(--sand); border-left:5px solid var(--amber); border-radius:8px; padding:10px 14px; }
        .kpi .k-t { font-size:12px; color:var(--amber-d); }
        .kpi .k-v { font-size:26px; font-weight:700; line-height:1.3; color:var(--brown); }
        .kpi .k-u { font-size:12px; color:#8a7a68; }
        .kpi.bad { border-left-color:var(--coral); } .kpi.bad .k-v { color:var(--coral); }
        .kpi.link { cursor:pointer; } .kpi.link:hover { background:var(--cream); }
        .insights { background:#fff; border:1px solid var(--sand); border-radius:8px; padding:10px 14px; margin-bottom:14px; }
        .insights h5 { margin:0 0 6px; color:var(--amber-d); font-weight:700; }
        .ins { padding:5px 8px; margin:3px 0; border-radius:5px; font-size:13px; }
        .ins.warn { background:#FDE6DF; border-left:4px solid var(--coral); }
        .ins.info { background:var(--cream); border-left:4px solid var(--amber); }
        .ins.ok { background:#F3EBDD; border-left:4px solid #B59A6B; }
        .ins a { color:var(--amber-d); font-weight:600; margin-left:6px; cursor:pointer; text-decoration:underline; }
        .nav-tabs > li > a { color:var(--amber-d); }
        .nav-tabs > li.active > a { font-weight:700; border-top:3px solid var(--amber); color:var(--brown); }
        .panel-box { background:#fff; border:1px solid var(--sand); border-radius:8px; padding:12px 14px; margin:12px 0; }
        .panel-box h4 { margin:0 0 4px; font-size:15px; color:var(--amber-d); font-weight:700; }
        .panel-box .sub { font-size:12px; color:#8a7a68; margin-bottom:8px; }
        .chart-h { height:360px; }
        table.dataTable th, table.dataTable td { font-size:13px; vertical-align:middle; }
        table.dataTable thead th { background:var(--sand); color:var(--brown); }
        .num { text-align:right; white-space:nowrap; }
        table.dataTable td, table.dataTable th { white-space:nowrap; }
        .tbl-wrap { overflow-x:auto; }
        td.wrapok { white-space:normal !important; min-width:220px; max-width:320px; }
        .period-bar { display:inline-flex; align-items:center; gap:4px; vertical-align:middle; }
        .period-bar .btn.on { background:var(--amber); border-color:var(--amber); color:#fff; }
        .bar-wrap { background:#F3EBDD; border-radius:4px; height:14px; min-width:80px; position:relative; }
        .bar-fill { background:var(--amber); height:14px; border-radius:4px; }
        .bar-txt { position:absolute; left:6px; top:-2px; font-size:11px; line-height:18px; color:var(--brown); font-weight:600; }
        .bad-txt { color:var(--coral); font-weight:700; }
        .chip { display:inline-block; padding:2px 10px; margin:2px 4px 2px 0; border-radius:12px; border:1px solid var(--amber); background:#fff; color:var(--amber-d); cursor:pointer; font-size:12px; }
        .chip.on, .chip:hover { background:var(--amber); color:#fff; }
        .pill { display:inline-block; padding:0 8px; border-radius:9px; font-size:11px; line-height:18px; white-space:nowrap; background:var(--sand); color:var(--brown); }
        .pill.s { background:#FDE6DF; color:var(--coral); font-weight:700; }
        .help-doc { font-size:13px; line-height:1.75; color:var(--brown); }
        .help-doc h4 { color:var(--amber-d); border-bottom:2px solid var(--sand); padding-bottom:3px; margin:14px 0 6px; font-size:15px; }
        .help-doc h4:first-child { margin-top:0; }
        .help-doc .tip { background:var(--cream); border:1px dashed #F0A24B; border-radius:6px; padding:6px 10px; margin:6px 0; }
        .empty { color:#8a7a68; padding:18px; text-align:center; }
    </style>
</head>
<body class="nav-sm">
<div class="container body">
<div class="main_container">
<?php include '../partPage/sideAndTopBarMenu.html' ?>
<div class="right_col" role="main">
<div class="wrap" style="clear:both;">

    <div class="pg-head">
        <h3><i class="fa fa-tachometer"></i> 機台與人員效率分析</h3>
        <button type="button" id="btnPageHelp" class="page-help-btn"><i class="fa fa-question-circle"></i> 使用說明</button>
    </div>

    <!-- 篩選 -->
    <div class="filter-box">
        <form method="GET" id="filterForm">
            <span class="fi">
                <label>期間</label>
                <input type="date" class="form-control" style="width:140px;display:inline-block" name="start_date" id="start_date" value="<?= $e($start_date) ?>">
                ～
                <input type="date" class="form-control" style="width:140px;display:inline-block" name="end_date" id="end_date" value="<?= $e($end_date) ?>">
            </span>
            <span class="fi">
                <input type="hidden" name="unit" id="unit" value="<?= $e($unit) ?>">
                <span class="period-bar">
                    <button type="button" class="btn btn-default btn-sm" onclick="shift(-1)" title="往前一期"><i class="fa fa-chevron-left"></i></button>
                    <?php foreach (['month' => '月', 'quarter' => '季', 'half' => '半年', 'year' => '年'] as $uk => $ul): ?>
                        <button type="button" class="btn btn-default btn-sm <?= $unit === $uk ? 'on' : '' ?>" onclick="setUnit('<?= $uk ?>')"><?= $ul ?></button>
                    <?php endforeach; ?>
                    <button type="button" class="btn btn-default btn-sm" onclick="shift(1)" title="往後一期"><i class="fa fa-chevron-right"></i></button>
                </span>
                <button type="button" class="btn btn-default btn-sm" onclick="quick('d7')">近7天</button>
                <button type="button" class="btn btn-default btn-sm" onclick="quick('d30')">近30天</button>
            </span>
            <span class="fi">
                <label>機台種類</label>
                <select class="form-control" style="width:130px;display:inline-block" name="machine_type_id" id="machine_type_id">
                    <option value="">全部</option>
                    <?php foreach ($machine_types as $mt): ?>
                        <option value="<?= $e($mt['id']) ?>" <?= $filter_machine_type === (string)$mt['id'] ? 'selected' : '' ?>><?= $e($mt['nm']) ?></option>
                    <?php endforeach; ?>
                </select>
            </span>
            <span class="fi">
                <label>機台</label>
                <select class="form-control" style="width:130px;display:inline-block" name="machine_id" id="machine_id" data-eg-filter="輸入機台篩選…">
                    <option value="">全部</option>
                    <?php foreach ($machines as $mm): ?>
                        <option value="<?= $e($mm['machine_id']) ?>" data-type="<?= $e($mm['machine_type_id']) ?>" <?= $filter_machine === (string)$mm['machine_id'] ? 'selected' : '' ?>><?= $e($mm['nm']) ?></option>
                    <?php endforeach; ?>
                </select>
            </span>
            <span class="fi">
                <label>人員</label>
                <select class="form-control" style="width:120px;display:inline-block" name="user_id" data-eg-filter="輸入姓名篩選…">
                    <option value="">全部</option>
                    <?php foreach ($users as $uu): ?>
                        <option value="<?= $e($uu['id']) ?>" <?= $filter_user === (string)$uu['id'] ? 'selected' : '' ?>><?= $e($uu['user_cname']) ?></option>
                    <?php endforeach; ?>
                </select>
            </span>
            <span class="fi">
                <label title="稼動率 = 生產工時 ÷ (天數 × 每日可用小時)">每日可用</label>
                <select class="form-control" style="width:90px;display:inline-block" name="hpd">
                    <?php foreach ([8, 16, 24] as $hh): ?><option value="<?= $hh ?>" <?= $hours_per_day === $hh ? 'selected' : '' ?>><?= $hh ?> 小時</option><?php endforeach; ?>
                </select>
            </span>
            <span class="fi">
                <button type="submit" class="btn btn-amber btn-sm">查詢</button>
                <a href="Machine_Person_Efficiency.php" class="btn btn-default btn-sm">重置</a>
            </span>
        </form>
    </div>

    <!-- KPI -->
    <div class="kpis">
        <div class="kpi"><div class="k-t"><i class="fa fa-clock-o"></i> 總投入工時</div><div class="k-v"><?= number_format($tot_sec / 3600, 1) ?></div><div class="k-u">小時（生產 <?= number_format($tot['prod'] / 3600, 1) ?>／架機 <?= number_format($tot['setup'] / 3600, 1) ?>）</div></div>
        <div class="kpi"><div class="k-t"><i class="fa fa-cubes"></i> 良品產出</div><div class="k-v"><?= number_format($tot['qty']) ?></div><div class="k-u">PCS（<?= count($rows) ?> 筆報工）</div></div>
        <div class="kpi <?= $ng_rate >= 3 ? 'bad' : '' ?>"><div class="k-t"><i class="fa fa-exclamation-triangle"></i> NG 數量</div><div class="k-v"><?= number_format($tot['ng']) ?></div><div class="k-u">PCS（不良率 <?= $ng_rate ?>%）</div></div>
        <div class="kpi"><div class="k-t"><i class="fa fa-bolt"></i> 平均時產</div><div class="k-v"><?= $avg_eff === null ? '-' : number_format($avg_eff, 1) ?></div><div class="k-u">PCS／生產小時</div></div>
        <div class="kpi <?= count($miss) ? 'bad link' : 'link' ?>" onclick="goTab('tab_miss')" title="點此查看清單"><div class="k-t"><i class="fa fa-search"></i> 廠內加工 有檢驗未報工</div><div class="k-v"><?= count($miss) ?></div><div class="k-u">站（點此查看）</div></div>
    </div>

    <!-- 重點發現 -->
    <div class="insights">
        <h5><i class="fa fa-lightbulb-o"></i> 重點發現</h5>
        <?php foreach ($insights as $in): ?>
            <div class="ins <?= $in[0] ?>"><?= $e($in[1]) ?><?php if ($in[2]): ?><a onclick="goTab('<?= $in[2] ?>')">查看</a><?php endif; ?></div>
        <?php endforeach; ?>
    </div>

    <ul class="nav nav-tabs" role="tablist" id="mainTabs">
        <li class="active"><a href="#tab_mach" data-toggle="tab">機台</a></li>
        <li><a href="#tab_user" data-toggle="tab">人員</a></li>
        <li><a href="#tab_miss" data-toggle="tab">廠內加工未報工 <span class="pill <?= count($miss) ? 's' : '' ?>"><?= count($miss) ?></span></a></li>
        <li><a href="#tab_tchk" data-toggle="tab">移轉缺QC／缺報工 <span class="pill <?= count($tchk) ? 's' : '' ?>"><?= count($tchk) ?></span></a></li>
        <li><a href="#tab_detail" data-toggle="tab">報工明細 <span class="pill"><?= count($rows) ?></span></a></li>
    </ul>

    <div class="tab-content">
        <!-- 機台 -->
        <div class="tab-pane active" id="tab_mach">
            <div class="panel-box">
                <h4>機台明細</h4>
                <div class="sub">稼動率＝生產工時 ÷（<?= $days_diff ?> 天 × <?= $hours_per_day ?> 小時）。點表頭可排序。</div>
                <div class="tbl-wrap"><table id="tblMach" class="table table-bordered table-striped" style="width:100%">
                    <thead><tr><th>機台</th><th>報工天數</th><th>報工筆數</th><th>架機(H)</th><th>生產(H)</th><th style="min-width:130px">稼動率</th><th>良品</th><th>NG</th><th>不良率</th><th>時產(PCS/H)</th></tr></thead>
                    <tbody>
                    <?php foreach ($mach as $m): ?>
                        <tr>
                            <td><b><?= $e($m['name']) ?></b></td>
                            <td class="num"><?= count($m['days']) ?></td>
                            <td class="num"><?= $m['n'] ?></td>
                            <td class="num"><?= $h($m['setup']) ?></td>
                            <td class="num"><?= $h($m['prod']) ?></td>
                            <td data-order="<?= $m['util'] ?>"><div class="bar-wrap"><div class="bar-fill" style="width:<?= min(100, $m['util']) ?>%"></div><span class="bar-txt"><?= $m['util'] ?>%</span></div></td>
                            <td class="num"><?= number_format($m['qty']) ?></td>
                            <td class="num <?= $m['ng'] > 0 ? 'bad-txt' : '' ?>"><?= number_format($m['ng']) ?></td>
                            <td class="num <?= $m['ngr'] >= 3 ? 'bad-txt' : '' ?>"><?= $m['ngr'] ?>%</td>
                            <td class="num" data-order="<?= $m['eff'] ?? 0 ?>"><?= $m['eff'] === null ? '-' : $m['eff'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            </div>
        </div>

        <!-- 人員 -->
        <div class="tab-pane" id="tab_user">
            <div class="row">
                <div class="col-md-6">
                    <div class="panel-box">
                        <h4>人員工時（前 15 位）</h4>
                        <div class="sub">橘色＝生產、淺橘＝架機（小時）</div>
                        <?php if ($usr): ?><div id="chUserHours" class="chart-h"></div><?php else: ?><div class="empty">沒有資料</div><?php endif; ?>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="panel-box">
                        <h4>人員時產排名（前 15 位）</h4>
                        <div class="sub">良品數 ÷ 生產工時（PCS/小時）。不同製程的件單價與節拍不同，比較時請搭配下表的製程。</div>
                        <?php if ($c_e['cats']): ?><div id="chUserEff" class="chart-h"></div><?php else: ?><div class="empty">沒有資料</div><?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="panel-box">
                <h4>人員明細</h4>
                <div class="tbl-wrap"><table id="tblUser" class="table table-bordered table-striped" style="width:100%">
                    <thead><tr><th>人員</th><th>報工天數</th><th>報工筆數</th><th>架機(H)</th><th>生產(H)</th><th>合計(H)</th><th>良品</th><th>NG</th><th>不良率</th><th>時產(PCS/H)</th></tr></thead>
                    <tbody>
                    <?php foreach ($usr as $u): ?>
                        <tr>
                            <td><b><?= $e($u['name']) ?></b></td>
                            <td class="num"><?= count($u['days']) ?></td>
                            <td class="num"><?= $u['n'] ?></td>
                            <td class="num"><?= $h($u['setup']) ?></td>
                            <td class="num"><?= $h($u['prod']) ?></td>
                            <td class="num"><?= $h($u['setup'] + $u['prod']) ?></td>
                            <td class="num"><?= number_format($u['qty']) ?></td>
                            <td class="num <?= $u['ng'] > 0 ? 'bad-txt' : '' ?>"><?= number_format($u['ng']) ?></td>
                            <td class="num <?= $u['ngr'] >= 3 ? 'bad-txt' : '' ?>"><?= $u['ngr'] ?>%</td>
                            <td class="num" data-order="<?= $u['eff'] ?? 0 ?>"><?= $u['eff'] === null ? '-' : $u['eff'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            </div>
        </div>

        <!-- 廠內加工 有檢驗未報工 -->
        <div class="tab-pane" id="tab_miss">
            <div class="panel-box">
                <h4>廠內加工廠商：有檢驗紀錄、但該站完全沒有報工</h4>
                <div class="sub">
                    廠內加工廠商＝廠商主檔勾選「廠內加工廠商」者。依檢驗日期 <?= $e(eg_fmt_date($start_date)) ?> ～ <?= $e(eg_fmt_date($end_date)) ?> 查詢，<b>不受上方機台／人員篩選影響</b>（沒報工就沒有機台與人員可比對）。
                    「完全沒有報工」＝該站（BOM 的這一道製程）在報工紀錄裡一筆都沒有。
                </div>
                <div style="margin-bottom:8px">
                    <button type="button" class="btn btn-default btn-sm" id="btnExcl"><i class="fa fa-cog"></i> 排除廠商設定</button>
                    <?php if ($excl_makers): $nm = []; foreach ($internal_makers as $im) if (in_array((string)$im['maker_id_no'], $excl_makers, true)) $nm[] = $im['nm']; ?>
                        <span class="sub">目前排除：<b><?= $e(implode('、', $nm)) ?></b>（共 <?= count($nm) ?> 家，不列入下表與上方數字）</span>
                    <?php else: ?><span class="sub">目前沒有排除任何廠商</span><?php endif; ?>
                </div>
                <?php if ($miss_err): ?><div class="ins warn"><?= $e($miss_err) ?></div><?php endif; ?>
                <?php if ($miss_by_maker): ?>
                    <div style="margin-bottom:8px">
                        <span class="chip on" data-mk="">全部 <?= count($miss) ?></span>
                        <?php foreach ($miss_by_maker as $mk => $cnt): ?><span class="chip" data-mk="<?= $e($mk) ?>"><?= $e($mk) ?> <?= $cnt ?></span><?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <div class="tbl-wrap"><table id="tblMiss" class="table table-bordered table-striped" style="width:100%">
                    <thead><tr><th>最近檢驗日</th><th>客戶</th><th>BOM</th><th>料號</th><th>製程</th><th>廠商</th><th>檢驗結果</th><th>良品(ok)</th><th>待判(QQ)</th><th>檢驗筆數</th></tr></thead>
                    <tbody>
                    <?php foreach ($miss as $x): ?>
                        <tr>
                            <td data-order="<?= $e($x['last_qc']) ?>"><?= $e(eg_fmt_date(substr($x['last_qc'], 0, 10))) ?></td>
                            <td><?= $e($x['Client_Name']) ?></td>
                            <td><b><?= $e($x['bom']) ?></b></td>
                            <td><?= $e($x['part_no']) ?></td>
                            <td><?= $e($x['ProcessName']) ?></td>
                            <td><?= $e($x['maker_name']) ?></td>
                            <td><?= $e($x['qc_status']) ?></td>
                            <td class="num"><?= number_format($x['ok_qty']) ?></td>
                            <td class="num"><?= number_format($x['qq_qty']) ?></td>
                            <td class="num"><?= (int)$x['qc_cnt'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            </div>
        </div>

        <!-- 有移轉單據但缺 QC／缺報工 -->
        <div class="tab-pane" id="tab_tchk">
            <div class="panel-box">
                <h4>有移轉單據，但該站缺 QC 檢驗或缺報工</h4>
                <div class="sub">
                    資料來源：製程移轉單（製程移轉一覽表）。站別＝移轉單的製令＋站序；依移轉日期 <?= $e(eg_fmt_date($start_date)) ?> ～ <?= $e(eg_fmt_date($end_date)) ?> 查詢，<b>不受機台／人員篩選影響</b>。
                    「沒驗」＝該站在 QC 檢驗紀錄一筆都沒有；「沒報工」＝該站在報工紀錄一筆都沒有。外包廠商的站本來就不會有報工，可勾選「只看廠內加工」。
                    同樣的檢查也在 <a href="Transfer_Log_Analysis.php">製程移轉一覽表</a> 的「缺漏檢查」分頁。
                </div>
                <?php if ($tchk_err): ?><div class="ins warn"><?= $e($tchk_err) ?></div><?php endif; ?>
                <div style="margin-bottom:8px">
                    <span class="chip tc-chip on" data-f="">全部 <?= count($tchk) ?></span>
                    <span class="chip tc-chip" data-f="noqc">缺 QC <?= $tc_noqc ?></span>
                    <span class="chip tc-chip" data-f="norep">缺報工 <?= $tc_norep ?></span>
                    <span class="chip tc-chip" data-f="both">兩者都缺 <?= $tc_both ?></span>
                    <label style="margin-left:12px;font-weight:normal;cursor:pointer"><input type="checkbox" id="tcInternal"> 只看廠內加工</label>
                </div>
                <div class="tbl-wrap"><table id="tblTchk" class="table table-bordered table-striped" style="width:100%">
                    <thead><tr><th>最近移轉日</th><th>客戶</th><th>BOM</th><th>料號</th><th>站序</th><th>製程</th><th>廠商（移出）</th><th>廠商類別</th><th>移轉單據</th><th>移轉數量</th><th>QC檢驗</th><th>報工</th></tr></thead>
                    <tbody>
                    <?php foreach ($tchk as $c):
                        $nq = (int)$c['qc_cnt'] === 0; $nr = (int)$c['rep_cnt'] === 0; $ft = ($nq && $nr) ? 'both' : ($nq ? 'noqc' : 'norep'); ?>
                        <tr data-ft="<?= $ft ?>" data-in="<?= (int)$c['is_internal'] === 1 ? 1 : 0 ?>">
                            <td data-order="<?= $e($c['last_date']) ?>"><?= $e(eg_fmt_date($c['last_date'])) ?></td>
                            <td><?= $e($c['Client_Name']) ?></td>
                            <td><b><?= $e($c['bom']) ?></b></td>
                            <td><?= $e($c['part_no']) ?></td>
                            <td><?= (int)$c['bom_sn'] ?></td>
                            <td><?= $e($c['ProcessName']) ?></td>
                            <td><?= $e($c['maker_name'] ?: $c['maker_code']) ?></td>
                            <td><?= (int)$c['is_internal'] === 1 ? '廠內加工' : '外包／其他' ?></td>
                            <td class="wrapok"><small><?= $e($c['tr_nos']) ?></small></td>
                            <td class="num"><?= number_format((int)$c['tr_qty']) ?></td>
                            <td><?= $nq ? '<span class="pill s">沒驗</span>' : (int)$c['qc_cnt'] . ' 筆' ?></td>
                            <td><?= $nr ? '<span class="pill s">沒報工</span>' : (int)$c['rep_cnt'] . ' 筆' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            </div>
        </div>

        <!-- 報工明細 -->
        <div class="tab-pane" id="tab_detail">
            <div class="panel-box">
                <h4>報工明細</h4>
                <div class="tbl-wrap"><table id="tblDetail" class="table table-bordered table-striped" style="width:100%">
                    <thead><tr><th>日期</th><th>機台</th><th>BOM</th><th>客戶</th><th>製程</th><th>架機</th><th>生產</th><th>架機(分)</th><th>生產(分)</th><th>良品</th><th>NG</th><th>時產</th><th>完工</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $r):
                        $sm = (int)round(max(0, (int)$r['setup_seconds']) / 60);
                        $pm = (int)round(max(0, (int)$r['prod_seconds']) / 60);
                        $ef = $pm > 0 ? round($r['produced_qty'] / ($pm / 60), 1) : '-';
                    ?>
                        <tr>
                            <td data-order="<?= $e($r['report_date']) ?>"><?= $e(eg_fmt_date($r['report_date'])) ?></td>
                            <td><?= $e($r['machine']) ?></td>
                            <td><b><?= $e($r['bom']) ?></b></td>
                            <td><?= $e($r['Client_Name']) ?></td>
                            <td><?= $e($r['ProcessName']) ?></td>
                            <td><?= $e($r['setup_user_name']) ?><?= $r['setup_start_time'] ? '<br><small>' . $tm($r['setup_start_time']) . '~' . $tm($r['setup_end_time']) . '</small>' : '' ?></td>
                            <td><?= $e($r['prod_user_name']) ?><?= $r['production_start_time'] ? '<br><small>' . $tm($r['production_start_time']) . '~' . $tm($r['production_end_time']) . '</small>' : '' ?></td>
                            <td class="num"><?= $sm ?></td>
                            <td class="num"><?= $pm ?></td>
                            <td class="num"><?= number_format($r['produced_qty']) ?></td>
                            <td class="num <?= $r['ng_qty'] > 0 ? 'bad-txt' : '' ?>"><?= number_format($r['ng_qty']) ?></td>
                            <td class="num"><?= $ef ?></td>
                            <td class="text-center"><?= $r['is_finished'] ? '<i class="fa fa-check-circle" style="color:#D98A33"></i>' : '' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table></div>
            </div>
        </div>
    </div>

</div>
</div>
<?php include '../partPage/footer.html' ?>
</div>
</div>

<!-- 排除廠商設定 -->
<div class="modal fade" id="exclMask" tabindex="-1">
  <div class="modal-dialog" style="width:520px;max-width:96%">
    <div class="modal-content">
      <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button><h4 class="modal-title"><i class="fa fa-cog"></i> 排除廠商設定</h4></div>
      <div class="modal-body">
        <div class="sub" style="font-size:12px;color:#8a7a68;margin-bottom:8px">勾選的廠內加工廠商不會出現在「有檢驗未報工」清單與上方數字。全公司共用同一份設定。</div>
        <div style="max-height:360px;overflow:auto">
        <?php foreach ($internal_makers as $im): ?>
            <label style="display:block;font-weight:normal;margin:4px 0"><input type="checkbox" class="exclChk" value="<?= $e($im['maker_id_no']) ?>" <?= in_array((string)$im['maker_id_no'], $excl_makers, true) ? 'checked' : '' ?>> <?= $e($im['nm']) ?> <small style="color:#8a7a68"><?= $e($im['maker_id_no']) ?></small></label>
        <?php endforeach; ?>
        </div>
        <div id="exclMsg" class="bad-txt" style="margin-top:6px"></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-default" data-dismiss="modal">取消</button><button type="button" class="btn btn-amber" id="btnExclSave">儲存並重新查詢</button></div>
    </div>
  </div>
</div>

<!-- 使用說明 -->
<div class="modal fade" id="helpUseMask" tabindex="-1">
  <div class="modal-dialog" style="width:720px;max-width:96%">
    <div class="modal-content">
      <div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button><h4 class="modal-title"><i class="fa fa-question-circle"></i> 機台與人員效率分析 使用說明</h4></div>
      <div class="modal-body help-doc">
        <h4>這一頁做什麼</h4>
        <p>依「報工」紀錄統計機台與人員的工時、產出、不良與時產，並檢查「廠內加工廠商有檢驗卻沒報工」的站別。</p>
        <h4>怎麼看</h4>
        <ul>
          <li><b>上方五張卡</b>：總工時、良品、NG（含不良率）、平均時產、廠內加工未報工站數；最後一張可點開清單。</li>
          <li><b>重點發現</b>：系統依目前期間自動挑出需要注意的項目，按「查看」跳到對應分頁。</li>
          <li><b>機台／人員分頁</b>：先看圖，下方表格可按欄位排序、搜尋、匯出。</li>
          <li><b>報工明細</b>：每一筆報工的原始資料。</li>
        </ul>
        <h4>名詞</h4>
        <ul>
          <li><b>時產</b>＝良品數 ÷ 生產工時（不含架機）。</li>
          <li><b>稼動率</b>＝生產工時 ÷（期間天數 × 每日可用小時）。每日可用小時可在篩選列切換 8／16／24。</li>
          <li><b>不良率</b>＝NG ÷（良品＋NG）。人員的良品與 NG 歸生產人員；架機人員只計架機工時。</li>
        </ul>
        <h4>廠內加工未報工</h4>
        <div class="tip">廠內加工廠商＝廠商主檔（主檔管理→廠商）勾選「廠內加工廠商」者。條件：檢驗日期落在期間內，且該站（BOM 的這道製程）在報工紀錄裡<b>完全沒有</b>任何一筆。這份清單不受機台／人員篩選影響。</div>
        <h4>移轉缺QC／缺報工</h4>
        <p>依製程移轉單檢查：有移轉單據的站別（製令＋站序），若在 QC 檢驗紀錄或報工紀錄裡完全沒有紀錄就會列出。可篩選缺 QC、缺報工、兩者都缺，外包廠商的站本來沒有報工，可只看廠內加工。</p>
        <h4>權限</h4>
        <p>登入後即可查看，本頁只讀取資料，不會新增或修改任何紀錄。</p>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-amber" data-dismiss="modal">我知道了</button></div>
    </div>
  </div>
</div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/fastclick.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script src="../../resource/js/eg_input_rules.js?v=<?= @filemtime(__DIR__ . '/../../resource/js/eg_input_rules.js') ?>"></script>
<script src="../../resource/js/jquery.dataTables.min.js"></script>
<script src="../../resource/js/dataTables.bootstrap.min.js"></script>
<script src="../../resource/js/dataTables.buttons.min.js"></script>
<script src="../../resource/js/buttons.flash.min.js"></script>
<script src="../../resource/js/buttons.html5.min.js"></script>
<script src="../../resource/js/buttons.print.min.js"></script>
<script src="../../resource/js/jszip.min.js"></script>
<script src="../../resource/js/pdfmake.min.js"></script>
<script src="../../resource/js/vfs_fonts.js"></script>
<script src="../../code/highcharts.js"></script>
<script src="../../code/modules/exporting.js"></script>
<script>
function fmt(d) { var m = ('0' + (d.getMonth() + 1)).slice(-2), day = ('0' + d.getDate()).slice(-2); return d.getFullYear() + '-' + m + '-' + day; }
function pd(v) { var a = v.split('-'); return new Date(+a[0], +a[1] - 1, +a[2]); }
var UNIT_M = { month: 1, quarter: 3, half: 6, year: 12 };
function periodOf(base, u) {            // 回傳包含 base 日期的月／季／半年／年區間
    var m = UNIT_M[u], y = base.getFullYear(), idx = Math.floor(base.getMonth() / m) * m;
    return [new Date(y, idx, 1), new Date(y, idx + m, 0)];
}
function applyRange(s, e, u) {
    document.getElementById('start_date').value = fmt(s);
    document.getElementById('end_date').value = fmt(e);
    document.getElementById('unit').value = u || '';
    document.getElementById('filterForm').submit();
}
function setUnit(u) { var r = periodOf(pd(document.getElementById('start_date').value || fmt(new Date())), u); applyRange(r[0], r[1], u); }
function shift(dir) {
    var u = document.getElementById('unit').value, st = pd(document.getElementById('start_date').value || fmt(new Date())), en = pd(document.getElementById('end_date').value || fmt(new Date()));
    if (u) { var m = UNIT_M[u], base = new Date(st.getFullYear(), st.getMonth() + dir * m, 1), r = periodOf(base, u); applyRange(r[0], r[1], u); }
    else { var span = Math.round((en - st) / 86400000) + 1; applyRange(new Date(st.getTime() + dir * span * 86400000), new Date(en.getTime() + dir * span * 86400000), ''); }
}
function quick(k) {
    var t = new Date(), days = k === 'd7' ? 6 : 29;
    applyRange(new Date(t.getTime() - days * 86400000), t, '');
}
function goTab(id) { $('#mainTabs a[href="#' + id + '"]').tab('show'); $('html,body').animate({scrollTop: $('#mainTabs').offset().top - 20}, 200); }

$(function () {
    $('#start_date,#end_date').on('change', function () { $('#unit').val(''); $('.period-bar .btn.on').removeClass('on'); });
    $('#btnPageHelp').on('click', function () { $('#helpUseMask').modal('show'); });

    $('#btnExcl').on('click', function () { $('#exclMsg').text(''); $('#exclMask').modal('show'); });
    $('#btnExclSave').on('click', function () {
        var ids = $('.exclChk:checked').map(function () { return this.value; }).get();
        $.post(location.pathname, { action: 'save_excl', csrf: <?= json_encode($_SESSION['mpe_csrf']) ?>, ids: JSON.stringify(ids) }, function (r) {
            if (r && r.ok) { location.hash = 'tab_miss'; location.reload(); } else { $('#exclMsg').text((r && r.msg) || '儲存失敗'); }
        }, 'json').fail(function () { $('#exclMsg').text('儲存失敗，請重試'); });
    });
    if (location.hash === '#tab_miss') goTab('tab_miss');

    // 機台種類 → 機台連動
    $('#machine_type_id').on('change', function () {
        var t = $(this).val();
        $('#machine_id option').each(function () { var ok = !t || !$(this).val() || String($(this).data('type')) === t; $(this).prop('disabled', !ok).toggle(ok); });
        $('#machine_id').val('');
    });

    var dtLang = { search: '搜尋：', lengthMenu: '每頁 _MENU_ 筆', info: '第 _START_–_END_ 筆，共 _TOTAL_ 筆', infoEmpty: '沒有資料', zeroRecords: '沒有符合的資料', infoFiltered: '(從 _MAX_ 筆篩選)', paginate: { previous: '上一頁', next: '下一頁' } };
    function mk(sel, title, order, len) {
        return $(sel).DataTable({ dom: 'Bflrtip', buttons: [{ extend: 'csv', className: 'btn btn-default btn-sm', text: 'CSV', title: title, bom: true }, { extend: 'print', className: 'btn btn-default btn-sm', text: '列印' }],
            pageLength: len || 10, lengthMenu: [5, 10, 20, 50], order: order, language: dtLang });
    }
    mk('#tblMach', '機台效率', [[4, 'desc']]);
    mk('#tblUser', '人員效率', [[5, 'desc']]);
    var tMiss = mk('#tblMiss', '廠內加工有檢驗未報工', [[0, 'desc']], 20);
    mk('#tblDetail', '報工明細', [[0, 'desc']], 20);

    $('.chip').on('click', function () {
        $('.chip').removeClass('on'); $(this).addClass('on');
        var v = $(this).data('mk');
        tMiss.column(5).search(v ? '^' + $.fn.dataTable.util.escapeRegex(v) + '$' : '', true, false).draw();
    });

    var tT = mk('#tblTchk', '移轉缺QC缺報工', [[0, 'desc']], 20), tcF = '';
    $.fn.dataTable.ext.search.push(function (st, d, i) {
        if (st.nTable.id !== 'tblTchk') return true;
        var tr = tT.row(i).node(), ft = $(tr).data('ft');
        if (tcF === 'noqc' && ft === 'norep') return false;
        if (tcF === 'norep' && ft === 'noqc') return false;
        if (tcF === 'both' && ft !== 'both') return false;
        if ($('#tcInternal').is(':checked') && String($(tr).data('in')) !== '1') return false;
        return true;
    });
    $('.tc-chip').on('click', function () { $('.tc-chip').removeClass('on'); $(this).addClass('on'); tcF = $(this).data('f'); tT.draw(); });
    $('#tcInternal').on('change', function () { tT.draw(); });

    var C = { prod: '#D98A33', setup: '#F0C98A', eff: '#DD5138' };
    function stacked(id, d) {
        if (!document.getElementById(id)) return;
        Highcharts.chart(id, { chart: { type: 'bar' }, title: { text: null }, credits: { enabled: false },
            xAxis: { categories: d.cats }, yAxis: { min: 0, title: { text: '小時' } },
            legend: { reversed: true }, tooltip: { shared: true, valueSuffix: ' 小時' },
            plotOptions: { series: { stacking: 'normal' }, bar: { dataLabels: { enabled: true, style: { textOutline: 'none', fontSize: '10px' } } } },
            series: [{ name: '生產', data: d.prod, color: C.prod }, { name: '架機', data: d.setup, color: C.setup }] });
    }
    stacked('chUserHours', <?= json_encode($c_u) ?>);
    if (document.getElementById('chUserEff')) {
        var ce = <?= json_encode($c_e) ?>;
        Highcharts.chart('chUserEff', { chart: { type: 'bar' }, title: { text: null }, credits: { enabled: false }, legend: { enabled: false },
            xAxis: { categories: ce.cats }, yAxis: { min: 0, title: { text: 'PCS / 小時' } }, tooltip: { valueSuffix: ' PCS/H' },
            plotOptions: { bar: { dataLabels: { enabled: true, style: { textOutline: 'none', fontSize: '10px' } } } },
            series: [{ name: '時產', data: ce.eff, color: C.eff }] });
    }
    $('a[data-toggle="tab"]').on('shown.bs.tab', function () { $(window).trigger('resize'); Highcharts.charts.forEach(function (c) { if (c) c.reflow(); }); });
});
</script>
</body>
</html>
