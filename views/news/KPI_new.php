<?php
/**
 * KPI 新方案 — views/news/KPI_new.php
 *
 * 依「附件C 品質管理系統流程圖」的八大區塊（COP01~04／SP01·02／MP01·02）規劃的 KPI 方案。
 * 2026-10-05 起正式功能化：指標資料改存進獨立新表 kpi_scheme_indicator／
 * kpi_scheme_indicator_year（與正式表完全分離），並在本頁內新增「設定」分頁可直接維護。
 *
 * 與正式 KPI 頁（KPI.php）的關係（使用者明確要求的架構界線）：
 *   ① 這一頁完全不寫入 kpi_as_indicator／kpi_as_indicator_year／kpi_as_monthly_value，
 *      只有 kps_from_snapshot() 會唯讀 SELECT 那幾張表（來源模式＝existing/existing_cny
 *      時，用來沿用正式指標的月快照），確保 views/news/KPI.php 不受本頁任何操作影響。
 *   ② 本頁自己的指標設定存在 kpi_scheme_indicator／kpi_scheme_indicator_year（全新、
 *      獨立的表），寫入走 src/store/KpiSchemeCny_API.php 的 save_indicator／
 *      save_indicator_year。
 *   ③ 不做月快照/鎖定——全部是「即時試算」，每次開頁面都用目前的設定重新算一次。
 *   ④ 春節目標調整沿用既有的 kpi_scheme_cny_adjust 表與邏輯，不受影響。
 *
 * 權限沿用 kpi 模組既有角色（看得到正式 KPI 的人就看得到這一頁；要設定要有 canAdmin）。
 */
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/news/KPI_new.php?in=999";
    header("Location:../../index.php");
    exit;
}
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/kpi_as_lib.php';
include_once '../../src/common/kpi_scheme_lib.php';

$db = (new DBConnection())->getPDO();
kpi_as_ensure_schema($db);
kpi_scheme_ind_ensure_schema($db);
$kpiUser  = kpi_as_current_user($db);
$kpiPerms = kpi_as_perms($db, $kpiUser);
$roleLabel = $kpiPerms['isAdmin'] ? '管理者'
           : ($kpiPerms['canAdmin'] ? 'KPI管理員'
           : ($kpiPerms['canFill'] ? 'KPI填報'
           : ($kpiPerms['canView'] ? 'KPI檢閱（唯讀）' : '無權限')));

/* 公司全名（ai-rules/16：一律動態取，禁寫死） */
$companyName = '';
try {
    $r = $db->query("SELECT customer_full, customer FROM customer_list WHERE is_own_company=1 LIMIT 1")
            ->fetch(PDO::FETCH_ASSOC);
    if ($r) $companyName = trim((string)($r['customer_full'] ?: $r['customer']));
} catch (Throwable $e) {}

/* 部門名稱（即時查，不寫死＝鐵律4） */
$deptName = [];
foreach ($db->query("SELECT id, name FROM department")->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $deptName[(int)$r['id']] = (string)$r['name'];
}

$curY = (int)date('Y');
$YEAR = (int)($_GET['year'] ?? $curY);
if ($YEAR < 2020 || $YEAR > $curY + 2) $YEAR = $curY;
$BLOCKS = kpi_scheme_blocks();

/* ---- 依 DB 設定逐項試算（唯讀；只有有檢閱權才跑，省掉無權限者的查詢成本） ---- */
$ROWS = $kpiPerms['canView'] ? kpi_scheme_list_year($db, $YEAR) : [];
$VALUES = [];
$calcMs = 0;
if ($kpiPerms['canView']) {
    $t0 = microtime(true);
    foreach ($ROWS as $row) {
        if ((int)$row['ind_active'] !== 1) continue;
        $VALUES[(int)$row['item_no']] = kpi_scheme_preview_row($db, $row, $YEAR);
    }
    $calcMs = (int)round((microtime(true) - $t0) * 1000);
}

/** 顯示值格式化 */
function kpsFmt($v, string $type): string {
    if ($v === null) return '';
    $v = (float)$v;
    if ($type === 'percent') return (round($v * 10) / 10) . '%';
    return (string)(round($v * 10) / 10);
}
/** 未達標？（與 KPI 模組同一套判定語意，直接吃 row 的 target_direction/target_value） */
function kpsBelowRow($v, array $row): bool {
    if ($v === null || $row['target_value'] === null) return false;
    $t = (float)$row['target_value']; $v = (float)$v;
    if ($row['target_direction'] === 'lte') return $v > $t;
    if ($row['target_direction'] === 'yes') return $v < 1;
    return $v < $t;
}
function kpsFreqName(string $f): string {
    return ['monthly'=>'每月','quarterly'=>'每季','halfyear'=>'半年','yearly'=>'每年'][$f] ?? $f;
}
function kpsTargetTextRow(array $row): string {
    if ($row['target_text']) return (string)$row['target_text'];
    if ($row['target_value'] === null) return '觀察期（未訂）';
    $op = $row['target_direction'] === 'lte' ? '≤' : ($row['target_direction'] === 'yes' ? '＝' : '≥');
    $t  = rtrim(rtrim(number_format((float)$row['target_value'], 2, '.', ''), '0'), '.');
    return $op . ' ' . $t . (string)($row['target_unit'] ?? '');
}
function kpsOwnerText(array $row, array $deptName): string {
    if (!empty($row['owner_display'])) return (string)$row['owner_display'];
    $d = $row['owner_dept_id'] ? ($deptName[(int)$row['owner_dept_id']] ?? '') : '';
    return $d !== '' ? $d : '（未設定）';
}
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>KPI 新方案</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <style>
        #sidebar-menu { visibility: hidden; }
        /* ===== 暖色系（ai-rules/10）===== */
        .page-help-btn { margin-left:auto; height:30px; padding:0 12px; border:1px solid #D8BE93;
            background:#FDF8EF; color:#8A5A2B; border-radius:4px; cursor:pointer; font-size:13px; }
        .page-help-btn:hover { background:#F7E0BD; }
        .right_col .page-title { margin:8px 0 4px; overflow:hidden; }
        .right_col .page-title h2 { margin:6px 0; }
        .ks-wrap { clear:both; }

        .ks-tabs { display:flex; gap:6px; margin-bottom:10px; }
        .ks-tab { height:34px; line-height:34px; padding:0 18px; border:1.5px solid #D8BE93; border-radius:6px 6px 0 0;
            background:#F1ECE3; color:#8a6d45; cursor:pointer; font-size:14px; font-weight:bold; }
        .ks-tab.on { background:#F7E0BD; color:#5b3a1e; border-bottom-color:#F7E0BD; }

        .ks-toolbar { display:flex; flex-wrap:wrap; gap:6px; align-items:center;
            border:1.5px solid #E8D5B5; border-radius:8px; padding:8px 10px; margin-bottom:10px; background:#FDF8EF; }
        .ks-toolbar button, .ks-toolbar a.btn { height:30px; font-size:13px; line-height:1; padding:0 10px;
            border:1px solid #D8BE93; border-radius:4px; background:#fff; color:#5b3a1e; cursor:pointer; }
        .ks-toolbar button:hover, .ks-toolbar a.btn:hover { background:#F7E0BD; }
        .ks-toolbar button:disabled { opacity:.45; cursor:not-allowed; }
        .ks-role-badge { margin-left:auto; font-size:13px; color:#5b3a1e; background:#F7E0BD;
            border-radius:12px; padding:4px 12px; }

        .ks-note { border:1.5px solid #E8D5B5; background:#FDF8EF; border-radius:8px;
            padding:10px 14px; margin-bottom:10px; font-size:13px; color:#5b3a1e; line-height:1.8; }
        .ks-note b { color:#C2601C; }

        /* 摘要卡 */
        .ks-cards { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:10px; }
        .ks-card { flex:1 1 128px; min-width:128px; border:1px solid #E8D5B5; border-radius:8px;
            background:#fff; padding:8px 12px; }
        .ks-card .n { font-size:21px; font-weight:bold; color:#8A5A2B; line-height:1.3; }
        .ks-card .t { font-size:12px; color:#8a6d45; }

        /* 主表 */
        .ks-tblwrap { overflow-x:auto; border:1px solid #E8D5B5; border-radius:6px; background:#fff; }
        table.ks-tbl { width:100%; border-collapse:collapse; font-size:13px; }
        table.ks-tbl th, table.ks-tbl td { border:1px solid #EADFC8; padding:4px 6px; text-align:center;
            white-space:nowrap; }
        table.ks-tbl thead th { background:#F7E0BD; color:#5b3a1e; font-weight:bold; position:sticky; top:0; z-index:5;
            box-shadow:inset 0 -2px 0 #D8BE93; }
        table.ks-tbl td.ks-name { text-align:left; min-width:190px; white-space:normal; }
        table.ks-tbl td.ks-left { text-align:left; }
        table.ks-tbl tbody tr:hover { background:#FBF0DD; }
        tr.ks-blk td { background:#F0E2C8; color:#6b4a20; font-weight:bold; text-align:left; font-size:13px; }
        tr.ks-blk .bk-code { display:inline-block; background:#8A5A2B; color:#fff; border-radius:4px;
            padding:1px 8px; margin-right:8px; font-size:12px; line-height:18px; }
        tr.ks-blk .bk-proc { font-weight:normal; color:#8a6d45; font-size:11.5px; display:block; margin-top:2px; }
        .ks-below { color:#DD5138; font-weight:bold; }
        .ks-na { color:#c4b7a3; }
        .ks-mk { font-size:9px; vertical-align:super; color:#C2601C; }
        .ks-mk.man { color:#8a6d45; }
        .ks-mk.cny { color:#DD5138; font-weight:bold; margin-left:1px; }

        /* 標籤（line-height 一定要自己指定，否則會繼承 custom.css 的 td span{line-height:28px} 把列撐高） */
        .ks-tag { display:inline-block; font-size:10px; line-height:16px; height:16px; padding:0 6px;
            border-radius:8px; margin-left:4px; vertical-align:1px; white-space:nowrap; }
        .ks-tag.off { background:#F1ECE3; color:#a08356; border:1px dashed #D8BE93; line-height:14px; }
        .ks-src { display:inline-block; font-size:10px; line-height:16px; height:16px; padding:0 6px;
            border-radius:8px; white-space:nowrap; }
        .ks-src.auto   { background:#EAF0E2; color:#4d6b33; }
        .ks-src.manual { background:#F1ECE3; color:#8a6d45; }
        .ks-i { cursor:pointer; color:#b5762a; margin-left:4px; }

        .ks-sec { border:1px solid #E8D5B5; border-radius:8px; background:#fff; margin-top:12px; }
        .ks-sec > h4 { margin:0; padding:8px 12px; background:#F7E0BD; color:#5b3a1e; font-size:14px;
            font-weight:bold; border-radius:8px 8px 0 0; }
        .ks-sec > div { padding:10px 14px; font-size:13px; color:#5b3a1e; line-height:1.85; }
        .ks-sec ul { padding-left:20px; margin:4px 0; }
        .ks-sec .ks-why { color:#7a6046; }

        /* 設定分頁 */
        .ksc-block { border:1px solid #E8D5B5; border-radius:8px; background:#fff; margin-bottom:10px; }
        .ksc-block > h5 { margin:0; padding:7px 12px; background:#F0E2C8; color:#6b4a20; font-size:13px;
            font-weight:bold; border-radius:8px 8px 0 0; }
        table.ksc-tbl { width:100%; border-collapse:collapse; font-size:12.5px; }
        table.ksc-tbl th, table.ksc-tbl td { border-bottom:1px solid #EADFC8; padding:5px 8px; text-align:left; }
        table.ksc-tbl th { background:#FDF8EF; color:#8a6d45; font-weight:bold; }
        table.ksc-tbl tbody tr:hover { background:#FBF0DD; }
        .ksc-edit-btn { height:24px; font-size:11.5px; line-height:1; padding:0 8px; border:1px solid #D8BE93;
            border-radius:3px; background:#fff; color:#5b3a1e; cursor:pointer; }
        .ksc-edit-btn:hover { background:#F7E0BD; }
        .ksc-badge-off { color:#a08356; font-size:11px; }

        /* 設定面板（抽屜） */
        .ksc-panel { background:#fff; border-radius:8px; max-width:880px; margin:30px auto; padding:0;
            box-shadow:0 5px 25px rgba(0,0,0,.3); max-height:90vh; display:flex; flex-direction:column; }
        .ksc-panel .m-head { background:#F7E0BD; color:#5b3a1e; font-weight:bold; padding:10px 15px;
            border-radius:8px 8px 0 0; display:flex; justify-content:space-between; }
        .ksc-panel .m-head .m-close { cursor:pointer; color:#b5762a; }
        .ksc-panel .m-body { padding:15px; overflow-y:auto; font-size:13px; color:#5b3a1e; }
        .ksc-sec { border:1px solid #E8D5B5; border-radius:6px; margin-bottom:10px; }
        .ksc-sec > div.hd { background:#FDF8EF; color:#8A5A2B; font-weight:bold; font-size:12.5px;
            padding:5px 10px; border-radius:6px 6px 0 0; }
        .ksc-sec > div.bd { padding:10px; display:flex; flex-wrap:wrap; gap:8px; }
        .ksc-fld { display:flex; flex-direction:column; gap:2px; }
        .ksc-fld label { font-size:11.5px; color:#8a6d45; }
        .ksc-fld input[type=text], .ksc-fld input[type=number], .ksc-fld select, .ksc-fld textarea {
            height:28px; border:1px solid #D8BE93; border-radius:4px; padding:0 6px; font-size:12.5px; color:#5b3a1e; }
        .ksc-fld textarea { height:50px; padding:4px 6px; resize:vertical; }
        .ksc-fld.w1 { width:110px; } .ksc-fld.w2 { width:170px; } .ksc-fld.w3 { width:240px; } .ksc-fld.wfull { width:100%; }
        .ksc-param-box { border:1px dashed #D8BE93; border-radius:6px; padding:8px; margin-top:6px; width:100%; background:#FFFBF2; }
        .ksc-param-box .pname { font-size:12px; font-weight:bold; color:#8A5A2B; margin-bottom:6px; }
        .ksc-foot { display:flex; gap:8px; align-items:center; padding:10px 15px; border-top:1px solid #EADFC8; }
        .ksc-foot button { height:32px; padding:0 16px; border-radius:4px; cursor:pointer; font-size:13px; }
        .ksc-btn-save { background:#F0A24B; color:#fff; border:1px solid #d98a33; }
        .ksc-btn-save:hover { background:#e8933a; }
        .ksc-btn-prev { background:#fff; color:#5b3a1e; border:1px solid #D8BE93; }
        .ksc-btn-prev:hover { background:#F7E0BD; }
        .ksc-btn-cancel { background:#fff; color:#8a6d45; border:1px solid #D8BE93; }
        .ksc-prev-result { margin-left:auto; font-size:12.5px; }
        .ksc-err { color:#DD5138; font-size:12px; margin-top:4px; }

        /* modal（指標說明/春節/使用說明） */
        .ks-mask { display:none; position:fixed; inset:0; background:rgba(60,40,20,.45); z-index:1050; overflow-y:auto; }
        .ks-modal { background:#fff; border-radius:8px; max-width:720px; margin:60px auto; padding:0;
            box-shadow:0 5px 25px rgba(0,0,0,.3); max-height:82vh; display:flex; flex-direction:column; }
        .ks-modal .m-head { background:#F7E0BD; color:#5b3a1e; font-weight:bold; padding:10px 15px;
            border-radius:8px 8px 0 0; display:flex; justify-content:space-between; }
        .ks-modal .m-head .m-close { cursor:pointer; color:#b5762a; }
        .ks-modal .m-body { padding:15px; overflow-y:auto; font-size:13px; color:#5b3a1e; line-height:1.85; }
        .ks-modal .m-body h5 { font-size:13px; color:#8A5A2B; margin:10px 0 3px; font-weight:bold; }
        .help-doc h4 { font-size:14px; color:#8A5A2B; margin:12px 0 4px; border-bottom:1px solid #EADFC8; padding-bottom:3px; }
        .help-doc ul { padding-left:20px; margin:4px 0; }
        .help-doc b { color:#8A5A2B; }
        .ks-noperm { border:1px solid #E8D5B5; background:#FDF8EF; border-radius:8px; padding:24px;
            color:#5b3a1e; text-align:center; }

        /* 列印：A3 橫式（ai-rules/16）。這是內部方案不是正式 AS 表單，故只印公司全名、不印 AS 編號。 */
        @media print {
            @page { size: A3 landscape; margin: 14mm 14mm 16mm 14mm; }
            @page { @bottom-left { content: "第 " counter(page) " 頁／共 " counter(pages) " 頁";
                                   font-size: 9pt; color:#555; } }
            .page-title, .ks-toolbar, .ks-tabs, .left_col, .nav_menu, footer, .ks-i, .ks-mask { display:none !important; }
            .right_col { margin:0 !important; padding:0 !important; min-height:0 !important; height:auto !important; }
            .container.body, .main_container { min-height:0 !important; height:auto !important; }
            body { background:#fff !important; }
            .ks-print-head { display:block !important; text-align:center; margin-bottom:8px; }
            .ks-print-head .c { font-size:16pt; font-weight:bold; color:#000; }
            .ks-print-head .t { font-size:13pt; color:#000; margin-top:2px; }
            .ks-print-head .s { font-size:9pt; color:#444; margin-top:2px; }
            .ks-tblwrap { overflow:visible !important; border:0; }
            table.ks-tbl { font-size:8.5pt; }
            table.ks-tbl thead { display:table-header-group; }
            table.ks-tbl tr { page-break-inside:avoid; }
            .ks-sec, #tabSetting { display:none !important; }
        }
        .ks-print-head { display:none; }
    </style>
</head>
<body class="nav-sm">
<div class="container body">
<div class="main_container">
    <?php include '../partPage/sideAndTopBarMenu.html' ?>
    <div class="right_col" role="main">
        <div class="page-title" style="display:flex;align-items:center;flex-wrap:wrap;clear:both;">
            <h2 style="margin:6px 0;">KPI 新方案
                <small style="color:#8a6d45;">依品質管理系統流程圖八大區塊規劃　<?= htmlspecialchars(kpi_scheme_version()) ?></small></h2>
            <button type="button" class="page-help-btn" id="btnPageHelp" title="這一頁怎麼用">
                <i class="fa fa-question-circle"></i> 使用說明</button>
        </div>
        <div class="clearfix"></div>

<?php if (!$kpiPerms['canView']): ?>
        <div class="ks-wrap">
            <div class="ks-noperm">
                <h4><i class="fa fa-lock"></i> 無 KPI 檢閱權限</h4>
                <p>這一頁沿用 KPI 模組的角色，請洽管理者於「使用者權限設定」指派 KPI 角色。</p>
            </div>
        </div>
<?php else: ?>
        <div class="ks-wrap">

        <div class="ks-print-head">
            <div class="c"><?= htmlspecialchars($companyName ?: '（尚未設定本公司全名）') ?></div>
            <div class="t">KPI 新方案　<?= htmlspecialchars(kpi_scheme_version()) ?></div>
            <div class="s">數字為 <?= $YEAR ?> 年即時試算</div>
        </div>

        <div class="ks-tabs">
            <div class="ks-tab on" id="tabBtnOverview" onclick="kscSwitchTab('overview')"><i class="fa fa-table"></i> 總覽</div>
            <div class="ks-tab" id="tabBtnSetting" onclick="kscSwitchTab('setting')"><i class="fa fa-cog"></i> 設定</div>
        </div>

        <div id="tabOverview">

        <div class="ks-toolbar">
            <span style="font-size:13px;color:#5b3a1e;">試算年度　<b><?= $YEAR ?></b></span>
            <button onclick="location.reload()"><i class="fa fa-refresh"></i> 重新試算</button>
            <button onclick="openCnyMask()"><i class="fa fa-calendar-check-o"></i> 春節目標調整設定</button>
            <button onclick="doPrint()"><i class="fa fa-print"></i> 列印（A3 橫式）</button>
            <a class="btn" href="KPI.php" style="line-height:28px;"><i class="fa fa-table"></i> 回正式 KPI 表</a>
            <span class="ks-role-badge">目前角色：<b><?= htmlspecialchars($roleLabel) ?></b></span>
        </div>

        <div class="ks-note">
            <b><i class="fa fa-info-circle"></i> 這是獨立於正式 KPI 的新方案頁。</b>
            正式 KPI 表（2-GM-04-01）維持原本 22 項，資料完全不受本頁影響；
            本頁的指標設定存在獨立的新表，寫入只會發生在你按「設定」分頁的儲存鈕時。<br>
            表格裡的數字是<b>用 <?= $YEAR ?> 年的資料即時試算</b>出來的（本次耗時 <?= $calcMs ?> ms），沒有月快照、每次開頁面都重新算一次。<br>
            格子右上角：<span class="ks-mk cny">春</span> ＝春節自動調整過（滑鼠移過去看原始值與調整後的差異）。
            <b>紅字</b>＝未達該項目標。
        </div>

        <div class="ks-cards">
            <?php
            $totalActive = 0; $autoCnt = 0; $manualCnt = 0; $hiddenCnt = 0;
            foreach ($ROWS as $row) {
                if ((int)$row['ind_active'] !== 1) continue;
                if ((int)$row['year_active'] !== 1) { $hiddenCnt++; continue; }
                $totalActive++;
                if ($row['source_mode'] === 'auto') $autoCnt++; else $manualCnt++;
            }
            ?>
            <div class="ks-card"><div class="n"><?= $totalActive ?></div><div class="t">啟用中指標</div></div>
            <div class="ks-card"><div class="n"><?= $autoCnt ?></div><div class="t">自動計算</div></div>
            <div class="ks-card"><div class="n"><?= $manualCnt ?></div><div class="t">人工填寫</div></div>
            <div class="ks-card"><div class="n"><?= $hiddenCnt ?></div><div class="t">已停用（本年度）</div></div>
        </div>

        <div class="ks-tblwrap">
        <table class="ks-tbl">
            <thead>
                <tr>
                    <th style="min-width:26px;">#</th>
                    <th style="min-width:190px;">指標名稱</th>
                    <th>負責部門</th>
                    <th>頻率</th>
                    <th>目標</th>
                    <th>資料來源</th>
                    <?php for ($m = 1; $m <= 12; $m++): ?><th><?= $m ?>月</th><?php endfor; ?>
                    <th>平均／合計</th>
                </tr>
            </thead>
            <tbody>
            <?php
            foreach ($BLOCKS as $bcode => $b):
                $bRows = array_values(array_filter($ROWS, fn($x) => $x['block'] === $bcode
                    && (int)$x['ind_active'] === 1 && (int)$x['year_active'] === 1));
                if (!$bRows) continue;
                $autoInBlk = count(array_filter($bRows, fn($x) => $x['source_mode'] === 'auto'));
            ?>
                <tr class="ks-blk"><td colspan="19">
                    <span class="bk-code"><?= htmlspecialchars($bcode) ?></span><?= htmlspecialchars($b['name']) ?>
                    <span style="font-weight:normal;color:#8a6d45;font-size:12px;">
                        （<?= count($bRows) ?> 項，其中自動 <?= $autoInBlk ?> 項）</span>
                    <span class="bk-proc">涵蓋程序：<?= htmlspecialchars($b['proc']) ?></span>
                </td></tr>
                <?php foreach ($bRows as $row):
                    $itemNo = (int)$row['item_no'];
                    $vals = $VALUES[$itemNo] ?? null;
                    $nums = [];
                    if ($vals) foreach ($vals as $c) { if ($c !== null && $c['v'] !== null) $nums[] = (float)$c['v']; }
                    $agg = null; $aggLbl = '';
                    if ($nums) {
                        if ($row['value_type'] === 'count') { $agg = array_sum($nums); $aggLbl = '合計'; }
                        else { $agg = array_sum($nums) / count($nums); $aggLbl = '平均'; }
                    }
                ?>
                <tr>
                    <td><?= $itemNo ?></td>
                    <td class="ks-name">
                        <?= htmlspecialchars($row['name']) ?>
                        <i class="fa fa-info-circle ks-i" onclick="showInfo(<?= $itemNo ?>)"
                           title="計算方式與備註"></i>
                    </td>
                    <td><?= htmlspecialchars(kpsOwnerText($row, $deptName)) ?></td>
                    <td><?= kpsFreqName($row['freq']) ?></td>
                    <td><?= htmlspecialchars(kpsTargetTextRow($row)) ?></td>
                    <td><span class="ks-src <?= htmlspecialchars($row['source_mode']) ?>"><?= $row['source_mode'] === 'auto' ? '自動' : '人工' ?></span></td>
                    <?php for ($m = 1; $m <= 12; $m++):
                        $c = ($vals && array_key_exists($m, $vals)) ? $vals[$m] : null;
                        if ($c === null || $c['v'] === null) { echo '<td class="ks-na">–</td>'; continue; }
                        $v   = (float)$c['v'];
                        $bad = kpsBelowRow($v, $row);
                        $mk  = '';
                        $cnyOrig = $c['cny_orig_v'] ?? null;
                        $tipParts = [];
                        if ($cnyOrig !== null) {
                            $mk .= '<span class="ks-mk cny" title="春節調整">春</span>';
                            $tipParts[] = sprintf('春節調整：原始 %s%% → 調整後 %s%%（比例 %s）',
                                rtrim(rtrim(number_format((float)$cnyOrig, 1), '0'), '.'),
                                rtrim(rtrim(number_format($v, 1), '0'), '.'),
                                rtrim(rtrim(number_format((float)($c['cny_ratio'] ?? 1), 4), '0'), '.'));
                        }
                        if (isset($c['den']) && $c['den'] !== null && $c['den'] !== '')
                            $tipParts[] = '分子 ' . (string)$c['num'] . ' ／ 分母 ' . (string)$c['den'];
                        elseif (isset($c['num']) && $c['num'] !== null)
                            $tipParts[] = '件數 ' . (string)$c['num'];
                        $tip = $tipParts ? ' title="' . htmlspecialchars(implode('；', $tipParts)) . '"' : '';
                        echo '<td' . $tip . '><span class="' . ($bad ? 'ks-below' : '') . '">'
                           . htmlspecialchars(kpsFmt($v, $row['value_type'])) . '</span>' . $mk . '</td>';
                    endfor; ?>
                    <td><?= $agg === null ? '<span class="ks-na">–</span>'
                            : '<b>' . htmlspecialchars(kpsFmt($agg, $row['value_type'])) . '</b>'
                              . '<span style="font-size:10px;color:#8a6d45;"> ' . $aggLbl . '</span>' ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>

        <?php
        $hiddenRows = array_values(array_filter($ROWS, fn($x) => (int)$x['ind_active'] === 1 && (int)$x['year_active'] !== 1));
        if ($hiddenRows):
        ?>
        <div class="ks-sec">
            <h4><i class="fa fa-eye-slash"></i> 已停用（<?= count($hiddenRows) ?> 項，本年度不計入總覽，隨時可在「設定」分頁重新啟用）</h4>
            <div><ul>
            <?php foreach ($hiddenRows as $row): ?>
                <li><b><?= htmlspecialchars($row['block']) ?>　<?= htmlspecialchars($row['name']) ?></b>
                    <i class="fa fa-info-circle ks-i" onclick="showInfo(<?= (int)$row['item_no'] ?>)"
                       title="計算方式與備註"></i></li>
            <?php endforeach; ?>
            </ul></div>
        </div>
        <?php endif; ?>

        </div><!-- /#tabOverview -->

        <div id="tabSetting" style="display:none;"></div>

        </div><!-- /.ks-wrap -->
<?php endif; ?>
    </div>
</div>
</div>

<!-- 單項說明 -->
<div class="ks-mask" id="infoMask">
    <div class="ks-modal">
        <div class="m-head"><span id="infoTitle">指標說明</span>
            <span class="m-close" onclick="closeMask('infoMask')">&times;</span></div>
        <div class="m-body" id="infoBody"></div>
    </div>
</div>

<!-- 春節目標調整設定 -->
<div class="ks-mask" id="cnyMask">
    <div class="ks-modal" style="max-width:860px;">
        <div class="m-head"><span>春節目標調整設定　<span id="cnyYearLabel"></span></span>
            <span class="m-close" onclick="closeMask('cnyMask')">&times;</span></div>
        <div class="m-body">
            <div class="ks-why" style="margin-bottom:10px;">
                影響來源模式設為「existing_cny」的指標（例如月份受訂目標達成率／月銷貨額達成率）。
                自動比例＝(30−春節損失工作天數)/30，損失天數取自行事曆上標題含「春節」的國定假日
                （只算週一到週五）；春節跨兩個月時兩個月各自計算。
                「額外調整率」是管理員疊加的手動修正（百分點，可正可負），用來微調自動算出來的跟實際出入太大的情況。
            </div>
            <div id="cnyNoEdit" class="vio-warn" style="display:none;">
                你沒有 KPI 管理者權限，以下僅供檢視，無法修改額外調整率。
            </div>
            <div style="overflow-x:auto;">
            <table class="ks-tbl" style="width:100%;">
                <thead><tr>
                    <th>月份</th><th>春節損失天數</th><th>自動比例</th>
                    <th>額外調整率(%)</th><th>最終比例</th><th>備註</th><th></th>
                </tr></thead>
                <tbody id="cnyTbody"></tbody>
            </table>
            </div>
        </div>
    </div>
</div>

<!-- 設定：單一指標編輯面板 -->
<div class="ks-mask" id="editMask">
    <div class="ksc-panel">
        <div class="m-head"><span id="editTitle">新增指標</span>
            <span class="m-close" onclick="closeMask('editMask')">&times;</span></div>
        <div class="m-body" id="editBody">載入中…</div>
    </div>
</div>

<!-- 使用說明（鐵律7） -->
<div class="ks-mask" id="helpUseMask">
    <div class="ks-modal">
        <div class="m-head"><span>使用說明</span>
            <span class="m-close" onclick="closeMask('helpUseMask')">&times;</span></div>
        <div class="m-body help-doc">
            <h4>這一頁是什麼</h4>
            <p>依「附件C 品質管理系統流程圖」的八大區塊（COP01~COP04、SP01·SP02、MP01·MP02）規劃的 KPI 方案，
               與正式 KPI 表（2-GM-04-01）<b>完全獨立</b>——兩者的指標資料存在不同的資料表，互不影響。</p>

            <h4>它會不會動到正式 KPI</h4>
            <ul>
                <li><b>不會。</b>本頁所有寫入只會發生在「設定」分頁，而那些寫入全部落在 kpi_scheme_indicator／
                    kpi_scheme_indicator_year 這兩張全新、獨立的表，正式表 kpi_as_indicator 等一行都不會動。</li>
                <li>「春節目標調整」用的是這個頁面自己的小表（kpi_scheme_cny_adjust），也與正式表無關。</li>
            </ul>

            <h4>總覽分頁怎麼看</h4>
            <ul>
                <li>每個區塊一條底色標題列，括號裡寫明該區塊有幾項、其中幾項設為自動計算。</li>
                <li>數字是<b>即時試算</b>（用 <?= $YEAR ?> 年資料），沒有月快照，每次開頁面重新算一次。</li>
                <li>滑鼠移到數字上會顯示<b>分子／分母</b>（或件數）。</li>
                <li>格子右上角：<span class="ks-mk cny">春</span>＝春節自動調整過；<b>紅字</b>＝未達該項目標。</li>
                <li>資料來源「existing／existing_cny」是直接沿用正式 KPI 表某一項的月快照，所以跟正式頁的那一項一定一致。</li>
            </ul>

            <h4>設定分頁怎麼用</h4>
            <ul>
                <li>僅 <b>KPI 管理員／管理者</b>可編輯；其他角色只能檢視。</li>
                <li>指標依區塊分組列出，點「編輯」開啟單一指標的完整編輯面板（基本資料／目標／擔當者／資料來源與參數）。</li>
                <li>資料來源選「自動計算」時，選擇計算方式後會列出該方式需要的參數；選「existing／existing_cny」時，
                    參數填的是<b>正式 KPI 表的項次編號</b>（可參考清單上的對照）。</li>
                <li>存檔前可按「試算目前設定」，在不存檔的情況下先看這組設定會算出什麼結果。</li>
                <li>「新增指標」會開同一個編輯面板（空白狀態），新增完就是一個完整可用的指標。</li>
                <li>停用一個指標不會刪除歷史試算結果（反正本頁不存快照），只是總覽分頁不再列出它。</li>
            </ul>

            <h4>列印</h4>
            <p>固定 A3 橫式。列印對話框請把紙張選成 A3。這是內部方案、不是正式 AS 表單，
               所以只印公司全名與頁碼，<b>不印 AS 文件編號</b>。</p>

            <h4>權限</h4>
            <p>沿用 KPI 模組既有角色：看得到正式 KPI 表的人就看得到本頁總覽；KPI 管理員／管理者才能用設定分頁。</p>
        </div>
    </div>
</div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/fastclick.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script src="../../resource/js/eg_input_rules.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_input_rules.js') ?>"></script>
<script src="../../resource/js/eg_print_log.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_print_log.js') ?>"></script>
<script>
$(document).ready(function(){
    var $activeMenu = $('#sidebar-menu .nav.side-menu > li.active');
    if ($activeMenu.length) {
        $activeMenu.removeClass('active').find('ul.child_menu').hide();
        $activeMenu.find('li.current-page').removeClass('current-page');
    }
    $('#sidebar-menu').css('visibility', 'visible');
});

var KS_YEAR = <?= $YEAR ?>;
var API = '../../src/store/KpiSchemeCny_API.php';

/* ===== 指標說明（同時涵蓋總覽與設定分頁的 info 圖示） ===== */
var ITEM_INFO = {};
<?php foreach ($ROWS as $row):
    $itemNo = (int)$row['item_no'];
    $reg = kpi_scheme_registry();
    $calcDesc = ($row['calculator_key'] && isset($reg[$row['calculator_key']]))
        ? $reg[$row['calculator_key']]['desc'] : '';
?>
ITEM_INFO[<?= $itemNo ?>] = <?= json_encode([
    'name'=>$row['name'], 'block'=>$row['block'], 'calc_key'=>$row['calculator_key'],
    'calc_desc'=>$calcDesc, 'params'=>$row['params_json'], 'note'=>$row['note'],
    'source_mode'=>$row['source_mode'], 'active'=>(int)$row['year_active'],
], JSON_UNESCAPED_UNICODE) ?>;
<?php endforeach; ?>

function openMask(id){ document.getElementById(id).style.display='block'; }
function closeMask(id){ document.getElementById(id).style.display='none'; }
function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g, function(c){
    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }

function showInfo(itemNo){
    var it = ITEM_INFO[itemNo];
    if (!it) return;
    document.getElementById('infoTitle').textContent = it.block + '　' + it.name;
    var h = '';
    h += '<h5>資料來源</h5><div>' + (it.source_mode === 'auto' ? '自動計算' : '人工填寫') +
         (it.calc_key ? '（' + esc(it.calc_key) + '）' : '') + '</div>';
    if (it.calc_desc) h += '<h5>計算方式</h5><div>' + esc(it.calc_desc) + '</div>';
    if (it.params) h += '<h5>參數</h5><div style="font-family:monospace;">' + esc(it.params) + '</div>';
    if (it.note) h += '<h5>備註</h5><div>' + esc(it.note) + '</div>';
    h += '<h5>本年度狀態</h5><div>' + (it.active ? '啟用' : '已停用（不計入總覽）') + '</div>';
    document.getElementById('infoBody').innerHTML = h;
    openMask('infoMask');
}

/* ===== 分頁切換 ===== */
var KSC_LOADED = false;
function kscSwitchTab(tab){
    document.getElementById('tabBtnOverview').className = 'ks-tab' + (tab === 'overview' ? ' on' : '');
    document.getElementById('tabBtnSetting').className  = 'ks-tab' + (tab === 'setting'  ? ' on' : '');
    document.getElementById('tabOverview').style.display = tab === 'overview' ? '' : 'none';
    document.getElementById('tabSetting').style.display  = tab === 'setting'  ? '' : 'none';
    if (tab === 'setting' && !KSC_LOADED) kscLoad();
}

/* ===== 春節目標調整（沿用既有邏輯） ===== */
var CNY_MONTH_NAME = ['','1月','2月','3月','4月','5月','6月','7月','8月','9月','10月','11月','12月'];

function openCnyMask(){
    openMask('cnyMask');
    document.getElementById('cnyYearLabel').textContent = KS_YEAR;
    document.getElementById('cnyTbody').innerHTML = '<tr><td colspan="7" style="text-align:center;color:#8a6d45;">載入中…</td></tr>';
    $.getJSON(API, { action:'list', year: KS_YEAR }, function(res){
        if (!res || !res.ok) { document.getElementById('cnyTbody').innerHTML =
            '<tr><td colspan="7" style="text-align:center;color:#DD5138;">載入失敗：' + esc((res && res.error) || '') + '</td></tr>'; return; }
        document.getElementById('cnyNoEdit').style.display = res.can_edit ? 'none' : 'block';
        var html = '';
        res.rows.forEach(function(r){
            var editable = res.can_edit;
            html += '<tr data-m="' + r.month + '">'
                 + '<td>' + CNY_MONTH_NAME[r.month] + '</td>'
                 + '<td>' + (r.lost_days > 0 ? r.lost_days + ' 天' : '<span class="ks-na">–</span>') + '</td>'
                 + '<td>' + (parseFloat(r.auto_ratio) < 1 ? (parseFloat(r.auto_ratio)*100).toFixed(1)+'%' : '<span class="ks-na">不調整</span>') + '</td>'
                 + '<td>' + (editable
                       ? '<input type="text" class="cny-extra" value="' + esc(r.extra_pct) + '" style="width:64px;height:24px;border:1px solid #D8BE93;border-radius:3px;padding:0 4px;text-align:right;">'
                       : esc(r.extra_pct)) + '</td>'
                 + '<td><b>' + (parseFloat(r.final_ratio)*100).toFixed(1) + '%</b></td>'
                 + '<td>' + (editable
                       ? '<input type="text" class="cny-note" value="' + esc(r.note || '') + '" placeholder="選填：調整原因" style="width:140px;height:24px;border:1px solid #D8BE93;border-radius:3px;padding:0 4px;">'
                       : esc(r.note || '')) + '</td>'
                 + '<td>' + (editable ? '<button onclick="saveCnyRow(' + r.month + ')" style="height:24px;font-size:12px;border:1px solid #d98a33;background:#F0A24B;color:#fff;border-radius:3px;cursor:pointer;padding:0 8px;">儲存</button>' : '') + '</td>'
                 + '</tr>';
        });
        document.getElementById('cnyTbody').innerHTML = html;
    }).fail(function(){
        document.getElementById('cnyTbody').innerHTML = '<tr><td colspan="7" style="text-align:center;color:#DD5138;">連線失敗</td></tr>';
    });
}

function saveCnyRow(month){
    var $tr = $('#cnyTbody tr[data-m="' + month + '"]');
    var extra = $tr.find('.cny-extra').val();
    var note = $tr.find('.cny-note').val();
    if (extra === '' || isNaN(parseFloat(extra))) { alert('額外調整率請輸入數字（可以是 0）'); return; }
    $.post(API, { action:'save', year: KS_YEAR, month: month, extra_pct: extra, note: note }, function(res){
        if (!res || !res.ok) { alert('儲存失敗：' + ((res && res.error) || '')); return; }
        location.reload();
    }, 'json').fail(function(){ alert('儲存失敗：連線異常'); });
}

function doPrint(){
    try {
        if (window.EGPrintLog && EGPrintLog.record) {
            EGPrintLog.record({ source:'kpi_scheme', doc_kind:'form',
                                doc_name:'KPI 新方案 ' + KS_YEAR });
        }
    } catch(e){}
    alert('列印對話框請把紙張選成 A3、方向選橫式。');
    window.print();
}

$(document).on('click', '.ks-mask', function(e){ if (e.target === this) this.style.display='none'; });
$(document).on('click', '#btnPageHelp', function(){ openMask('helpUseMask'); });

/* ============================================================
 * 設定分頁
 * ============================================================ */
var KSC_DATA = null;   // list_scheme 的完整回應
var KSC_CUR_ROW = null; // 目前編輯面板對應的資料列（row，來自 KSC_DATA.rows）；新增時為 null

function kscLoad(){
    document.getElementById('tabSetting').innerHTML = '<div style="padding:20px;text-align:center;color:#8a6d45;">載入中…</div>';
    $.getJSON(API, { action:'list_scheme', year: KS_YEAR }, function(res){
        if (!res || !res.ok) {
            document.getElementById('tabSetting').innerHTML =
                '<div class="ks-noperm">載入失敗：' + esc((res && res.error) || '') + '</div>';
            return;
        }
        KSC_DATA = res;
        KSC_LOADED = true;
        kscRender();
    }).fail(function(){
        document.getElementById('tabSetting').innerHTML = '<div class="ks-noperm">連線失敗</div>';
    });
}

function kscRender(){
    var canEdit = KSC_DATA.can_edit;
    var blocks = KSC_DATA.blocks;
    var rows = KSC_DATA.rows;
    var html = '<div class="ks-toolbar">'
        + '<span style="font-size:13px;color:#5b3a1e;">年度　<b>' + KS_YEAR + '</b></span>'
        + (canEdit ? '<button onclick="kscOpenEdit(null)"><i class="fa fa-plus"></i> 新增指標</button>' : '')
        + '<button onclick="kscLoad()"><i class="fa fa-refresh"></i> 重新載入</button>'
        + (canEdit ? '' : '<span style="color:#a08356;font-size:12.5px;">你沒有 KPI 管理者權限，僅供檢視</span>')
        + '</div>';

    Object.keys(blocks).forEach(function(bcode){
        var bRows = rows.filter(function(r){ return r.block === bcode; });
        if (!bRows.length) return;
        html += '<div class="ksc-block"><h5>' +
            '<span class="bk-code" style="display:inline-block;background:#8A5A2B;color:#fff;border-radius:4px;padding:1px 8px;margin-right:8px;font-size:12px;">' + esc(bcode) + '</span>'
            + esc(blocks[bcode].name) + '　（' + bRows.length + ' 項）</h5>';
        html += '<table class="ksc-tbl"><thead><tr>'
            + '<th style="width:36px;">#</th><th>名稱</th><th style="width:70px;">頻率</th>'
            + '<th style="width:110px;">目標</th><th style="width:140px;">擔當者</th>'
            + '<th style="width:70px;">來源</th><th style="width:60px;">狀態</th><th style="width:60px;"></th>'
            + '</tr></thead><tbody>';
        bRows.forEach(function(r){
            html += '<tr>'
                + '<td>' + r.item_no + '</td>'
                + '<td>' + esc(r.name) + ' <i class="fa fa-info-circle ks-i" onclick="showInfo(' + r.item_no + ')"></i></td>'
                + '<td>' + kscFreqName(r.freq) + '</td>'
                + '<td>' + esc(kscTargetText(r)) + '</td>'
                + '<td>' + esc(r.owner_display || '（未設定）') + '</td>'
                + '<td>' + (r.source_mode === 'auto' ? '自動' : '人工') + '</td>'
                + '<td>' + (String(r.year_active) === '1' ? '啟用' : '<span class="ksc-badge-off">已停用</span>') + '</td>'
                + '<td>' + (canEdit ? '<button class="ksc-edit-btn" onclick="kscOpenEdit(' + r.indicator_id + ')">編輯</button>' : '') + '</td>'
                + '</tr>';
        });
        html += '</tbody></table></div>';
    });

    document.getElementById('tabSetting').innerHTML = html;
}

function kscFreqName(f){ return {monthly:'每月',quarterly:'每季',halfyear:'半年',yearly:'每年'}[f] || f; }
function kscTargetText(r){
    if (r.target_text) return r.target_text;
    if (r.target_value === null || r.target_value === undefined || r.target_value === '') return '觀察期（未訂）';
    var op = r.target_direction === 'lte' ? '≤' : (r.target_direction === 'yes' ? '＝' : '≥');
    var t = parseFloat(r.target_value);
    t = (Math.round(t*100)/100).toString();
    return op + ' ' + t + (r.target_unit || '');
}

/* ---- 單一指標編輯面板 ---- */
function kscFindRow(indicatorId){
    if (!indicatorId) return null;
    var found = null;
    (KSC_DATA.rows || []).forEach(function(r){ if (String(r.indicator_id) === String(indicatorId)) found = r; });
    return found;
}

function kscOpenEdit(indicatorId){
    KSC_CUR_ROW = kscFindRow(indicatorId);
    document.getElementById('editTitle').textContent = KSC_CUR_ROW ? ('編輯指標　#' + KSC_CUR_ROW.item_no + '　' + KSC_CUR_ROW.name) : '新增指標';
    document.getElementById('editBody').innerHTML = kscEditFormHtml(KSC_CUR_ROW);
    kscBindSourceModeToggle();
    kscRenderParamFields(KSC_CUR_ROW ? KSC_CUR_ROW.calculator_key : '', KSC_CUR_ROW ? KSC_CUR_ROW.params_json : null);
    kscFillOwnerMembers(KSC_CUR_ROW ? KSC_CUR_ROW.owner_dept_id : null, KSC_CUR_ROW ? KSC_CUR_ROW.owner_user_id : null);
    openMask('editMask');
}

function kscEditFormHtml(r){
    var blocks = KSC_DATA.blocks;
    var depts = KSC_DATA.dicts.departments;
    var registry = KSC_DATA.registry;

    var blockOpts = Object.keys(blocks).map(function(bc){
        return '<option value="' + esc(bc) + '"' + (r && r.block === bc ? ' selected' : '') + '>' + esc(bc) + ' ' + esc(blocks[bc].name) + '</option>';
    }).join('');

    var deptOpts = '<option value="">（未設定）</option>' + depts.map(function(d){
        return '<option value="' + d.id + '"' + (r && String(r.owner_dept_id) === String(d.id) ? ' selected' : '') + '>' + esc(d.name) + '</option>';
    }).join('');

    var calcOpts = '<option value="">（請選擇）</option>' + Object.keys(registry).map(function(k){
        return '<option value="' + esc(k) + '"' + (r && r.calculator_key === k ? ' selected' : '') + '>' + esc(registry[k].name) + '（' + esc(k) + '）</option>';
    }).join('');

    var freqSel = function(v){ return ['monthly','quarterly','halfyear','yearly'].map(function(f){
        return '<option value="' + f + '"' + (v === f ? ' selected' : '') + '>' + kscFreqName(f) + '</option>'; }).join(''); };
    var vtSel = function(v){ return [['percent','百分比'],['count','件數'],['score','分數'],['rate','比率(非百分比)'],['yesno','是否']].map(function(p){
        return '<option value="' + p[0] + '"' + (v === p[0] ? ' selected' : '') + '>' + p[1] + '</option>'; }).join(''); };
    var dirSel = function(v){ return [['gte','≥ 大於等於'],['lte','≤ 小於等於'],['yes','＝ 是／否']].map(function(p){
        return '<option value="' + p[0] + '"' + (v === p[0] ? ' selected' : '') + '>' + p[1] + '</option>'; }).join(''); };

    var sourceMode = r ? r.source_mode : 'manual';

    var h = '';
    h += '<div class="ksc-sec"><div class="hd">基本資料</div><div class="bd">'
       + '<div class="ksc-fld w3"><label>名稱</label><input type="text" id="f_name" value="' + esc(r ? r.name : '') + '"></div>'
       + '<div class="ksc-fld w2"><label>區塊</label><select id="f_block">' + blockOpts + '</select></div>'
       + '<div class="ksc-fld w1"><label>頻率</label><select id="f_freq">' + freqSel(r ? r.freq : 'monthly') + '</select></div>'
       + '<div class="ksc-fld w1"><label>數值型態</label><select id="f_vtype">' + vtSel(r ? r.value_type : 'percent') + '</select></div>'
       + '<div class="ksc-fld w1"><label>啟用</label><select id="f_active">'
       +   '<option value="1"' + (!r || String(r.year_active) === '1' ? ' selected' : '') + '>啟用</option>'
       +   '<option value="0"' + (r && String(r.year_active) === '0' ? ' selected' : '') + '>停用</option></select></div>'
       + '</div></div>';

    h += '<div class="ksc-sec"><div class="hd">目標</div><div class="bd">'
       + '<div class="ksc-fld w1"><label>方向</label><select id="f_dir">' + dirSel(r ? r.target_direction : 'gte') + '</select></div>'
       + '<div class="ksc-fld w1"><label>數值（留空＝觀察期）</label><input type="text" id="f_tval" value="' + esc(r && r.target_value !== null ? r.target_value : '') + '"></div>'
       + '<div class="ksc-fld w1"><label>單位</label><input type="text" id="f_tunit" value="' + esc(r ? (r.target_unit || '') : '') + '"></div>'
       + '<div class="ksc-fld w2"><label>文字覆寫（留空則自動由方向/數值/單位組出）</label><input type="text" id="f_ttext" value="' + esc(r ? (r.target_text || '') : '') + '"></div>'
       + '</div></div>';

    h += '<div class="ksc-sec"><div class="hd">擔當者</div><div class="bd">'
       + '<div class="ksc-fld w2"><label>部門</label><select id="f_odept" data-eg-filter="輸入部門名稱篩選…" onchange="kscFillOwnerMembers(this.value,null)">' + deptOpts + '</select></div>'
       + '<div class="ksc-fld w2"><label>部門人員（選填）</label><select id="f_ouser" data-eg-filter="輸入姓名篩選…"><option value="">（不指定）</option></select></div>'
       + '<div class="ksc-fld w3"><label>顯示文字覆寫（留空則自動用部門/人員名稱）</label><input type="text" id="f_odisp" value="' + esc(r ? (r.owner_display || '') : '') + '"></div>'
       + '</div></div>';

    h += '<div class="ksc-sec"><div class="hd">資料來源</div><div class="bd">'
       + '<div class="ksc-fld w1"><label>模式</label><select id="f_srcmode" onchange="kscOnSourceModeChange()">'
       +   '<option value="manual"' + (sourceMode === 'manual' ? ' selected' : '') + '>人工填寫</option>'
       +   '<option value="auto"' + (sourceMode === 'auto' ? ' selected' : '') + '>自動計算</option></select></div>'
       + '<div class="ksc-fld w3" id="f_calcwrap" style="' + (sourceMode === 'auto' ? '' : 'display:none;') + '">'
       +   '<label>計算方式</label><select id="f_calckey" data-eg-filter="輸入計算方式名稱篩選…" onchange="kscOnCalcKeyChange()">' + calcOpts + '</select></div>'
       + '<div id="f_calcdesc" style="width:100%;font-size:12px;color:#8a6d45;' + (sourceMode === 'auto' ? '' : 'display:none;') + '"></div>'
       + '<div id="f_paramsbox" style="width:100%;' + (sourceMode === 'auto' ? '' : 'display:none;') + '"></div>'
       + '</div></div>';

    h += '<div class="ksc-sec"><div class="hd">備註</div><div class="bd">'
       + '<div class="ksc-fld wfull"><textarea id="f_note">' + esc(r ? (r.note || '') : '') + '</textarea></div>'
       + '</div></div>';

    h += '<div class="ksc-err" id="f_err" style="display:none;"></div>';

    h += '<div class="ksc-foot">'
       + '<button class="ksc-btn-save" onclick="kscSave()"><i class="fa fa-save"></i> 儲存</button>'
       + '<button class="ksc-btn-prev" onclick="kscPreview()"><i class="fa fa-calculator"></i> 試算目前設定</button>'
       + '<button class="ksc-btn-cancel" onclick="closeMask(\'editMask\')">取消</button>'
       + '<span class="ksc-prev-result" id="f_prevresult"></span>'
       + '</div>';

    return h;
}

function kscOnSourceModeChange(){
    var mode = document.getElementById('f_srcmode').value;
    document.getElementById('f_calcwrap').style.display = mode === 'auto' ? '' : 'none';
    document.getElementById('f_calcdesc').style.display = mode === 'auto' ? '' : 'none';
    document.getElementById('f_paramsbox').style.display = mode === 'auto' ? '' : 'none';
    if (mode === 'auto') kscOnCalcKeyChange();
}

function kscOnCalcKeyChange(){
    var key = document.getElementById('f_calckey').value;
    var reg = KSC_DATA.registry[key];
    document.getElementById('f_calcdesc').textContent = reg ? reg.desc : '';
    // 切換計算方式時，若目前編輯的指標本來就是這個 calculator_key，沿用原參數；否則空白重來
    var curParams = (KSC_CUR_ROW && KSC_CUR_ROW.calculator_key === key) ? KSC_CUR_ROW.params_json : null;
    kscRenderParamFields(key, curParams);
}

function kscRenderParamFields(calcKey, paramsJsonStr){
    var box = document.getElementById('f_paramsbox');
    if (!calcKey) { box.innerHTML = ''; return; }
    var reg = KSC_DATA.registry[calcKey];
    if (!reg) { box.innerHTML = ''; return; }
    var params = {};
    try { params = paramsJsonStr ? JSON.parse(paramsJsonStr) : {}; } catch(e){ params = {}; }

    if (!reg.params || !reg.params.length) { box.innerHTML = '<div style="color:#8a6d45;font-size:12px;">（此計算方式不需要參數）</div>'; return; }

    var isExisting = (calcKey === 'existing' || calcKey === 'existing_cny');
    var h = '<div class="ksc-param-box"><div class="pname">參數</div>';
    reg.params.forEach(function(p){
        var v = params[p.key];
        var id = 'fp_' + p.key;
        h += '<div class="ksc-fld w2" style="margin-right:8px;margin-bottom:6px;"><label>' + esc(p.label) + '</label>';
        if (isExisting && p.key === 'item_no') {
            // existing／existing_cny 的 item_no 不再是裸數字輸入，改成可打字搜尋的正式
            // 指標選擇器——這樣「要沿用正式系統哪一種計算模組」直接挑就好，不必自己
            // 對照下面那份清單手打項次編號。
            var items = KSC_DATA.dicts.official_items || [];
            var optsHtml = '<option value="">（請選擇正式指標）</option>' + items.map(function(o){
                var selAttr = (v !== undefined && v !== null && String(v) === String(o.item_no)) ? ' selected' : '';
                return '<option value="' + o.item_no + '"' + selAttr + '>#' + o.item_no + ' ' + esc(o.name)
                     + (o.calculator_key ? '（' + esc(o.calculator_key) + '）' : '') + '</option>';
            }).join('');
            h += '<select id="' + id + '" data-eg-filter="輸入正式指標名稱或項次篩選…" onchange="kscOnExistingItemNoChange()">' + optsHtml + '</select>';
        } else if (p.type === 'process_type_ids') {
            var sel = Array.isArray(v) ? v.map(String) : [];
            var opts = (KSC_DATA.dicts.process_types || []).map(function(pt){
                var checked = sel.indexOf(String(pt.process_type_id)) >= 0;
                return '<label style="display:inline-block;margin-right:8px;font-size:12px;"><input type="checkbox" class="pt-chk" data-pid="' + pt.process_type_id + '" ' + (checked?'checked':'') + '> ' + esc(pt.process_type) + '</label>';
            }).join('');
            h += '<div id="' + id + '" style="border:1px solid #D8BE93;border-radius:4px;padding:4px 6px;max-height:90px;overflow-y:auto;">' + opts + '</div>';
        } else if (p.type === 'int') {
            h += '<input type="number" id="' + id + '" value="' + (v !== undefined && v !== null ? v : '') + '">';
        } else {
            // textlist：逗號分隔文字，存檔時轉陣列
            var txt = Array.isArray(v) ? v.join(',') : (v !== undefined && v !== null ? v : '');
            h += '<input type="text" id="' + id + '" value="' + esc(txt) + '" placeholder="逗號分隔，例：1,2,3">';
        }
        h += '</div>';
    });
    if (isExisting) h += '<div id="f_officialinfo" style="width:100%;"></div>';
    h += '</div>';
    box.innerHTML = h;
    if (isExisting) kscRenderOfficialInfo(calcKey);
}

/** 找出某個正式 item_no 目前的名稱／計算方式／參數（list_scheme 一併帶回，純唯讀） */
function kscOfficialItemInfo(itemNo){
    var list = KSC_DATA.dicts.official_items || [];
    for (var i = 0; i < list.length; i++) { if (String(list[i].item_no) === String(itemNo)) return list[i]; }
    return null;
}
function kscMoney(n){ n = Math.round(Number(n) || 0); return n.toLocaleString('zh-Hant'); }

function kscOnExistingItemNoChange(){
    kscRenderOfficialInfo(document.getElementById('f_calckey').value);
}

/**
 * existing／existing_cny 選了哪個正式指標之後，順便唯讀顯示那個指標「目前用什麼算法」，
 * 若是月目標金額型（order_target_amount／shipping_target_amount）再列出逐月目標金額——
 * 這些數字只存在正式 KPI 系統（kpi_as_indicator_year），本頁不碰它一個字，要改請去
 * KPI_setting.php，這裡只給一個連結＋唯讀顯示讓管理員知道去哪裡調。
 */
function kscRenderOfficialInfo(calcKey){
    var box = document.getElementById('f_officialinfo');
    if (!box) return;
    if (calcKey !== 'existing' && calcKey !== 'existing_cny') { box.innerHTML = ''; return; }
    var sel = document.getElementById('fp_item_no');
    var itemNo = sel ? sel.value : '';
    var it = itemNo ? kscOfficialItemInfo(itemNo) : null;
    var h = '<div style="border:1px dashed #D8BE93;border-radius:6px;padding:8px;margin-top:6px;background:#FFFBF2;font-size:12px;color:#5b3a1e;">';
    if (!it) {
        h += '請先在上面選擇要沿用的正式 KPI 項次。';
    } else {
        h += '資料來源：讀取正式 KPI <b>#' + it.item_no + ' ' + esc(it.name) + '</b> 的月快照'
           + (calcKey === 'existing_cny' ? '（春節月份自動調整）' : '') + '；'
           + '正式指標目前的計算方式：<b>' + esc(it.calculator_key || '（尚未設定）') + '</b>。';
        var oparams = {};
        try { oparams = it.params_json ? JSON.parse(it.params_json) : {}; } catch(e){}
        if (it.calculator_key === 'order_target_amount' || it.calculator_key === 'shipping_target_amount') {
            var mt = (oparams.monthly_targets && oparams.monthly_targets.v && typeof oparams.monthly_targets.v === 'object')
                   ? oparams.monthly_targets.v : null;
            h += '<div style="margin-top:6px;">此指標依「各月目標金額」計算，<b>金額設定在正式 KPI 系統（KPI_setting.php）</b>，本頁僅唯讀顯示、不可在此修改：</div>';
            if (mt) {
                h += '<div style="overflow-x:auto;margin-top:4px;"><table style="border-collapse:collapse;font-size:11.5px;">'
                   + '<tr>' + CNY_MONTH_NAME.slice(1).map(function(n){ return '<td style="border:1px solid #EADFC8;padding:2px 6px;font-weight:bold;background:#FDF8EF;">' + n + '</td>'; }).join('') + '</tr>'
                   + '<tr>' + CNY_MONTH_NAME.slice(1).map(function(n, i){
                       var m = i + 1;
                       return '<td style="border:1px solid #EADFC8;padding:2px 6px;text-align:right;">'
                            + (mt[m] !== undefined ? kscMoney(mt[m]) : '<span class="ks-na">–</span>') + '</td>';
                     }).join('') + '</tr></table></div>';
            } else {
                h += '<div style="color:#DD5138;">尚未逐月設定目標金額（可能使用全站預設目標），請到正式 KPI 設定頁確認。</div>';
            }
        }
        h += '<div style="margin-top:6px;"><a href="KPI_setting.php" target="_blank" rel="noopener"><i class="fa fa-external-link"></i> 前往正式 KPI 設定頁調整 →</a></div>';
    }
    h += '</div>';
    box.innerHTML = h;
}

function kscCollectParams(calcKey){
    var reg = KSC_DATA.registry[calcKey];
    if (!reg || !reg.params || !reg.params.length) return {};
    var out = {};
    reg.params.forEach(function(p){
        var id = 'fp_' + p.key;
        if (p.type === 'process_type_ids') {
            var ids = [];
            document.querySelectorAll('#' + id + ' .pt-chk:checked').forEach(function(cb){ ids.push(parseInt(cb.getAttribute('data-pid'), 10)); });
            out[p.key] = ids;
        } else if (p.type === 'int') {
            var el = document.getElementById(id);
            out[p.key] = el && el.value !== '' ? parseInt(el.value, 10) : null;
        } else {
            var el2 = document.getElementById(id);
            var raw = el2 ? el2.value : '';
            out[p.key] = raw.split(',').map(function(s){ s = s.trim(); return s === '' ? null : (isNaN(s) ? s : parseFloat(s)); }).filter(function(s){ return s !== null; });
        }
    });
    return out;
}

function kscFillOwnerMembers(deptId, selectUserId){
    var sel = document.getElementById('f_ouser');
    if (!sel) return;
    var members = (deptId && KSC_DATA.dicts.dept_members[deptId]) ? KSC_DATA.dicts.dept_members[deptId] : [];
    var html = '<option value="">（不指定）</option>';
    members.forEach(function(m){
        html += '<option value="' + m.user_id + '"' + (selectUserId && String(selectUserId) === String(m.user_id) ? ' selected' : '') + '>'
             + esc(m.cname) + (m.position_name ? '（' + esc(m.position_name) + '）' : '') + '</option>';
    });
    sel.innerHTML = html;
}

function kscBindSourceModeToggle(){
    // 補一次 owner dept 的候選人員（新增/切換部門時可能還沒填 selectUserId）
}

function kscSetErr(msg){
    var el = document.getElementById('f_err');
    if (!msg) { el.style.display = 'none'; el.textContent = ''; return; }
    el.style.display = 'block'; el.textContent = msg;
}

function kscSave(){
    kscSetErr('');
    var name = document.getElementById('f_name').value.trim();
    if (!name) { kscSetErr('名稱必填'); return; }
    var post = {
        year: KS_YEAR,
        indicator_id: KSC_CUR_ROW ? KSC_CUR_ROW.indicator_id : 0,
        name: name,
        block: document.getElementById('f_block').value,
        freq: document.getElementById('f_freq').value,
        value_type: document.getElementById('f_vtype').value,
        sort_order: KSC_CUR_ROW ? KSC_CUR_ROW.sort_order : 0,
        is_active: document.getElementById('f_active').value,
    };
    $.post(API, { action:'save_indicator', year: post.year, indicator_id: post.indicator_id, name: post.name,
                  block: post.block, freq: post.freq, value_type: post.value_type, sort_order: post.sort_order,
                  is_active: post.is_active }, function(res1){
        if (!res1 || !res1.ok) { kscSetErr('主檔儲存失敗：' + ((res1 && res1.error) || '')); return; }
        var indicatorId = res1.indicator_id;
        var srcMode = document.getElementById('f_srcmode').value;
        var calcKey = srcMode === 'auto' ? document.getElementById('f_calckey').value : '';
        if (srcMode === 'auto' && !calcKey) { kscSetErr('自動模式請選擇計算方式'); return; }
        var params = (srcMode === 'auto' && calcKey) ? kscCollectParams(calcKey) : {};
        var odeptEl = document.getElementById('f_odept'), ouserEl = document.getElementById('f_ouser');
        var iyPost = {
            action:'save_indicator_year', indicator_id: indicatorId, year: KS_YEAR,
            owner_dept_id: odeptEl.value, owner_user_id: ouserEl.value, owner_display: document.getElementById('f_odisp').value,
            source_mode: srcMode, calculator_key: calcKey, params_json: JSON.stringify(params),
            target_direction: document.getElementById('f_dir').value, target_value: document.getElementById('f_tval').value,
            target_unit: document.getElementById('f_tunit').value, target_text: document.getElementById('f_ttext').value,
            note: document.getElementById('f_note').value, is_active: document.getElementById('f_active').value,
        };
        $.post(API, iyPost, function(res2){
            if (!res2 || !res2.ok) { kscSetErr('年度設定儲存失敗：' + ((res2 && res2.error) || '')); return; }
            closeMask('editMask');
            kscLoad();
            // 總覽分頁的數字也要跟著重新算，直接重整頁面最省事也最保證一致
            setTimeout(function(){ location.reload(); }, 150);
        }, 'json').fail(function(){ kscSetErr('年度設定儲存失敗：連線異常'); });
    }, 'json').fail(function(){ kscSetErr('主檔儲存失敗：連線異常'); });
}

function kscPreview(){
    var srcMode = document.getElementById('f_srcmode').value;
    var calcKey = srcMode === 'auto' ? document.getElementById('f_calckey').value : '';
    if (!calcKey) { kscSetErr('請先選擇計算方式才能試算'); return; }
    kscSetErr('');
    var params = kscCollectParams(calcKey);
    var now = new Date();
    document.getElementById('f_prevresult').textContent = '試算中…';
    $.post(API, { action:'preview_compute', calculator_key: calcKey, year: KS_YEAR, month: now.getMonth() + 1,
                  params_json: JSON.stringify(params) }, function(res){
        if (!res || !res.ok) { document.getElementById('f_prevresult').textContent = '試算失敗：' + ((res && res.error) || ''); return; }
        var r = res.result;
        if (!r || r.v === null || r.v === undefined) { document.getElementById('f_prevresult').textContent = '本月（' + (now.getMonth()+1) + '月）查無資料'; return; }
        var txt = '試算結果（' + (now.getMonth()+1) + '月）：' + r.v;
        if (r.den !== undefined && r.den !== null) txt += '（' + r.num + '/' + r.den + '）';
        document.getElementById('f_prevresult').textContent = txt;
    }, 'json').fail(function(){ document.getElementById('f_prevresult').textContent = '試算失敗：連線異常'; });
}
</script>
</body>
</html>
