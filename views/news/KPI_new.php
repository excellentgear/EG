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
$curM = (int)date('n');
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
$ATTACH_COUNTS = $kpiPerms['canView'] ? kps_attach_counts_year($db, $YEAR) : [];

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

        /* ===== 單一欄位修改／明細（2026-10-05 續）===== */
        td.ks-cell { cursor:pointer; }
        td.ks-cell:hover { background:#FBF0DD; box-shadow:inset 0 0 0 1px #D8BE93; }
        .ks-attach-badge { font-size:9px; color:#8a6d45; margin-left:3px; vertical-align:1px; }
        .att-row { display:flex; gap:8px; align-items:center; border-bottom:1px dashed #EADFC8; padding:6px 0; font-size:13px; }
        .att-row .att-name { color:#b5762a; cursor:pointer; text-decoration:underline; flex:1;
            overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .att-row .att-note { color:#8a6d45; max-width:150px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .att-row .att-del { color:#DD5138; cursor:pointer; }
        .att-missing { color:#c9bda9; text-decoration:line-through; }

        /* 補登模式（管理員補資料）：整張表變成可直接填寫的格子，比照 KPI.php */
        #btnFill.on { background:#F0A24B; color:#fff; border-color:#d98a33; }
        #fillBar { margin:0 0 10px; padding:6px 10px; border:1px solid #D8BE93; background:#FBF5EA;
            color:#5b3a1e; font-size:13px; border-radius:4px; }
        #fillBar input[type=text] { height:26px; border:1px solid #D8BE93; border-radius:4px; padding:0 8px;
            font-size:12px; width:260px; margin-left:6px; }
        #fillBar .fb-n { margin-left:10px; color:#8a6d45; }
        #fillBar button.warm { height:26px; padding:0 12px; border-radius:4px; font-size:12px; margin-left:8px;
            border:1px solid #d98a33; background:#F0A24B; color:#fff; cursor:pointer; }
        table.ks-tbl td .fillIn { width:100%; min-width:44px; box-sizing:border-box; height:22px; font-size:12px;
            text-align:center; border:1px solid #E8D5B5; border-radius:3px; padding:0 2px; background:#fff; color:#5b3a1e; }
        table.ks-tbl td .fillIn:focus { border-color:#F0A24B; outline:none; background:#FFFBF3; }
        table.ks-tbl td .fillIn.ov { background:#FDF3E3; }
        table.ks-tbl td .fillIn.dirty { border-color:#C2601C; background:#FBEBD6; font-weight:bold; }

        /* 儲存格右鍵選單式小面板（仿 KPI.php #cellMenu）。
           一定要用 position:absolute 搭配 e.pageX/pageY（文件座標，含捲動量）——
           用 position:fixed 搭配 pageX/pageY 會在頁面/表格有捲動時整個對不上點擊位置
           （fixed 是視窗座標、pageX/Y 是文件座標，兩者混用捲動一多就差了一截）。 */
        .cm-menu { position:absolute; z-index:1200; background:#fff; border:1px solid #D8BE93; border-radius:6px;
            box-shadow:0 4px 16px rgba(0,0,0,.18); min-width:190px; font-size:13px; display:none; }
        .cm-head { padding:7px 12px; background:#F7E0BD; color:#5b3a1e; font-weight:bold; border-radius:6px 6px 0 0; }
        .cm-item { padding:7px 12px; color:#5b3a1e; cursor:pointer; }
        .cm-item:hover { background:#FDF8EF; }
        .cm-info { padding:5px 12px; font-size:11px; color:#8a6d45; border-top:1px solid #EADFC8; }

        /* 填寫／覆寫 迷你跳窗 */
        .fillm-row { display:flex; gap:8px; align-items:center; margin-bottom:8px; flex-wrap:wrap; }
        .fillm-row label { font-size:12.5px; color:#8a6d45; min-width:64px; }
        .fillm-row input[type=text], .fillm-row textarea { border:1px solid #D8BE93; border-radius:4px;
            padding:4px 8px; font-size:13px; color:#5b3a1e; flex:1; min-width:160px; }
        .fillm-row textarea { min-height:50px; resize:vertical; }

        /* 數值明細／不符合標準的明細（仿 KPI.php #vioMask） */
        .vio-head { font-size:14px; margin-bottom:6px; }
        .vio-mode { font-size:11px; padding:1px 7px; border-radius:8px; margin-left:6px; }
        .vio-mode.deny { background:#F1ECE3; color:#8a6d45; }
        .vio-note { font-size:12.5px; color:#8a6d45; background:#FDF8EF; border:1px solid #EADFC8;
            border-radius:6px; padding:7px 10px; margin-bottom:8px; line-height:1.7; }
        .vio-rules { font-size:12.5px; color:#5b3a1e; background:#FFFBF2; border:1px dashed #D8BE93;
            border-radius:6px; padding:8px 10px; margin-bottom:8px; }
        .vr-chip { display:inline-flex; align-items:center; gap:4px; background:#F1ECE3; border-radius:12px;
            padding:2px 8px 2px 10px; margin:3px 4px 0 0; font-size:12px; }
        .vr-chip.allyr { background:#F0E2C8; }
        .vr-scope { font-size:10px; color:#8a6d45; background:#fff; border-radius:6px; padding:0 5px; }
        .vr-x { cursor:pointer; color:#DD5138; font-weight:bold; margin-left:2px; }
        .vr-new { margin-top:8px; display:flex; gap:6px; align-items:center; flex-wrap:wrap; }
        .vr-new select, .vr-new input[type=text] { height:28px; border:1px solid #D8BE93; border-radius:4px;
            padding:0 6px; font-size:12.5px; }
        .vr-new button.warm { height:28px; padding:0 12px; border:1px solid #d98a33; background:#F0A24B;
            color:#fff; border-radius:4px; cursor:pointer; font-size:12.5px; }
        .vr-list { margin-top:6px; max-height:140px; overflow-y:auto; border:1px solid #EADFC8; border-radius:6px;
            background:#fff; }
        .vr-it { padding:4px 10px; font-size:12.5px; cursor:pointer; color:#5b3a1e; }
        .vr-it:hover { background:#FDF8EF; } .vr-it.on { background:#F7E0BD; }
        .vr-id { color:#8a6d45; font-size:11px; margin-left:4px; }
        .vr-src { float:right; font-size:10px; color:#a08356; }
        .vr-none { padding:8px 10px; font-size:12px; color:#a08356; }
        .vr-picked { margin-top:4px; font-size:12px; color:#5b3a1e; }
        .vr-chip2 { display:inline-flex; align-items:center; gap:3px; background:#F0E2C8; border-radius:10px;
            padding:1px 7px; margin:2px 3px 0 0; font-size:11.5px; }
        .vr-x2 { cursor:pointer; color:#DD5138; font-weight:bold; }
        .vio-filter { display:flex; gap:10px; align-items:center; flex-wrap:wrap; margin-bottom:8px; font-size:12.5px; }
        .vio-filter label { color:#8a6d45; }
        .vio-filter select, .vio-filter input[type=text] { height:26px; border:1px solid #D8BE93; border-radius:4px;
            padding:0 6px; font-size:12.5px; }
        .vf-clear { color:#b5762a; cursor:pointer; text-decoration:underline; }
        .vio-tblwrap { overflow-x:auto; max-height:360px; border:1px solid #EADFC8; border-radius:6px; }
        table.vio-tbl { width:100%; border-collapse:collapse; font-size:12.5px; }
        table.vio-tbl th, table.vio-tbl td { border-bottom:1px solid #EADFC8; padding:4px 7px; text-align:left; }
        table.vio-tbl thead th { background:#FDF8EF; color:#8a6d45; position:sticky; top:0; }
        table.vio-tbl tr.ex { background:#F1ECE3; color:#a08356; text-decoration:line-through; }
        table.vio-tbl tr.rex td { color:#a08356; }
        .vio-exbtn { height:22px; font-size:11px; padding:0 8px; border:1px solid #D8BE93; border-radius:3px;
            background:#fff; color:#5b3a1e; cursor:pointer; }
        .vio-exbtn.undo { border-color:#a08356; }
        .vio-foot { padding:8px 0 0; display:flex; gap:8px; align-items:center; }
        .vio-warn { font-size:12.5px; color:#8A5A2B; background:#FDF8EF; border:1px solid #E8D5B5;
            border-radius:6px; padding:7px 10px; margin-bottom:8px; }

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
            <?php if ($kpiPerms['isAdmin'] || $kpiPerms['canAdmin']): ?>
            <button id="btnFill" onclick="toggleFill()" title="整張表直接填寫（補舊年度資料用，不需逐格填原因）">
                <i class="fa fa-table"></i> 補登模式</button>
            <?php endif; ?>
            <button onclick="doPrint()"><i class="fa fa-print"></i> 列印（A3 橫式）</button>
            <a class="btn" href="KPI.php" style="line-height:28px;"><i class="fa fa-table"></i> 回正式 KPI 表</a>
            <span class="ks-role-badge">目前角色：<b><?= htmlspecialchars($roleLabel) ?></b></span>
        </div>

        <div id="fillBar" style="display:none;">
            補登模式：點格子直接輸入數值（寫入方式＝手動覆寫，不需逐格填原因；空白＝清除覆寫）。
            說明　<input type="text" id="fillNote" maxlength="200" placeholder="（選填）這批資料的補登說明，留空則用預設說明">
            <button class="warm" onclick="fillSave()"><i class="fa fa-save"></i> 送出補登</button>
            <span class="fb-n">已修改 <b id="fillCount">0</b> 格</span>
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
                    $iid = (int)$row['indicator_id'];
                    $vals = $VALUES[$itemNo] ?? null;
                    $nums = [];
                    if ($vals) foreach ($vals as $c) { if ($c !== null && $c['v'] !== null) $nums[] = (float)$c['v']; }
                    $agg = null; $aggLbl = '';
                    if ($nums) {
                        if ($row['value_type'] === 'count') { $agg = array_sum($nums); $aggLbl = '合計'; }
                        else { $agg = array_sum($nums) / count($nums); $aggLbl = '平均'; }
                    }
                    $validMonths = kpi_as_valid_months($row);
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
                        if (!in_array($m, $validMonths, true)) { echo '<td class="ks-na">NA</td>'; continue; }
                        $future = ($YEAR > $curY) || ($YEAR === $curY && $m > $curM);
                        $c = ($vals && array_key_exists($m, $vals)) ? $vals[$m] : null;
                        $attN = $ATTACH_COUNTS[$iid][$m] ?? 0;
                        $attBadge = $attN > 0 ? '<span class="ks-attach-badge" title="佐證附件 ' . $attN . ' 件"><i class="fa fa-paperclip"></i>' . $attN . '</span>' : '';
                        $srcAttr = ' data-iid="' . $iid . '" data-m="' . $m . '" data-future="' . ($future ? 1 : 0) . '" data-src="'
                                 . htmlspecialchars((string)($c['src'] ?? '')) . '" data-rawv="'
                                 . htmlspecialchars($c !== null && $c['v'] !== null ? (string)round((float)$c['v'], 4) : '') . '"';
                        if ($c === null || $c['v'] === null) {
                            echo '<td class="ks-cell"' . $srcAttr . '><span class="ks-na">' . ($future ? 'NA' : '?') . '</span>' . $attBadge . '</td>';
                            continue;
                        }
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
                        if (($c['src'] ?? '') === 'override') {
                            $mk .= '<span class="ks-mk man" title="手動覆寫：' . htmlspecialchars((string)($c['ov_reason'] ?? '')) . '">✱</span>';
                        } elseif (($c['src'] ?? '') === 'manual') {
                            $mk .= '<span class="ks-mk man" title="人工填寫">填</span>';
                        }
                        if (isset($c['den']) && $c['den'] !== null && $c['den'] !== '')
                            $tipParts[] = '分子 ' . (string)$c['num'] . ' ／ 分母 ' . (string)$c['den'];
                        elseif (isset($c['num']) && $c['num'] !== null)
                            $tipParts[] = '件數 ' . (string)$c['num'];
                        $tip = $tipParts ? ' title="' . htmlspecialchars(implode('；', $tipParts)) . '"' : '';
                        echo '<td class="ks-cell"' . $srcAttr . $tip . '><span class="' . ($bad ? 'ks-below' : '') . '">'
                           . htmlspecialchars(kpsFmt($v, $row['value_type'])) . '</span>' . $mk . $attBadge . '</td>';
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

<!-- 儲存格選單（單一欄位修改／明細，仿 KPI.php #cellMenu） -->
<div class="cm-menu" id="cellMenu"></div>

<!-- 單一欄位修改：填寫（人工）／手動覆寫（自動） -->
<div class="ks-mask" id="fillMask">
    <div class="ks-modal" style="max-width:480px;">
        <div class="m-head"><span id="fillTitle">填寫</span>
            <span class="m-close" onclick="closeMask('fillMask')">&times;</span></div>
        <div class="m-body" id="fillBody"></div>
    </div>
</div>

<!-- 數值明細／不符合標準的明細（增減個別調整排除＋查看單一月份統計資料，仿 KPI.php #vioMask） -->
<div class="ks-mask" id="vioMask">
    <div class="ks-modal" style="max-width:920px;">
        <div class="m-head"><span id="vioTitle">數值明細</span>
            <span class="m-close" onclick="closeMask('vioMask')">&times;</span></div>
        <div class="m-body" id="vioBody"></div>
        <div class="vio-foot" id="vioFoot" style="padding:0 15px 12px;"></div>
    </div>
</div>

<!-- 佐證附件 -->
<div class="ks-mask" id="attMask">
    <div class="ks-modal" style="max-width:560px;">
        <div class="m-head"><span id="attTitle">佐證附件</span>
            <span class="m-close" onclick="closeMask('attMask')">&times;</span></div>
        <div class="m-body">
            <div id="attList" style="min-height:40px;"></div>
            <div id="attUpBox" style="margin-top:10px;border-top:1px dashed #EADFC8;padding-top:8px;">
                <div style="font-size:12px;color:#8a6d45;margin-bottom:4px;" id="attLimitTxt"></div>
                <input type="file" id="attFile" multiple
                       accept=".jpg,.jpeg,.png,.gif,.webp,.bmp,.pdf,.xls,.xlsx,.xlsm,.xlsb,.doc,.docx,.docm,.ppt,.pptx,.csv,.txt,.zip,.7z,.rar,.odt,.ods">
                <div style="font-size:11px;color:#8a6d45;margin-top:2px;">可一次選多個檔案；單檔 20MB。</div>
                <input type="text" id="attNote" maxlength="200" placeholder="附件說明（選填）"
                       style="width:100%;height:28px;border:1px solid #D8BE93;border-radius:4px;padding:0 8px;margin-top:6px;">
                <div style="text-align:right;margin-top:6px;">
                    <button class="ksc-btn-save" onclick="submitAttach()"><i class="fa fa-upload"></i> 上傳</button>
                </div>
            </div>
        </div>
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
                <li>選「月受訂目標達成率／月銷貨目標達成率（本方案自有目標）」時，<b>各月目標金額直接在這個編輯面板填寫</b>，
                    完全不依賴正式 KPI 系統、也不必去 KPI 設定頁（KPI.php）調整；可勾選「春節月份自動放大達成率」。</li>
                <li>存檔前可按「試算目前設定」，在不存檔的情況下先看這組設定會算出什麼結果。</li>
                <li>「新增指標」會開同一個編輯面板（空白狀態），新增完就是一個完整可用的指標。</li>
                <li>停用一個指標不會刪除歷史試算結果（反正本頁不存快照），只是總覽分頁不再列出它。</li>
            </ul>

            <h4>總覽：單一欄位修改／逐筆排除與排除規則／查看單一月份統計資料</h4>
            <ul>
                <li>點任何一格數字（或「–」「?」空格）會彈出選單：<b>自動計算</b>指標可開「數值明細 / 不符合標準的明細」
                    或「手動覆寫」；<b>人工填寫</b>指標可開「填寫 / 修改」。</li>
                <li>手動覆寫／清除覆寫、填寫／清除填寫一律寫進本方案自己的 kpi_scheme_monthly_value，
                    與正式系統的 kpi_as_monthly_value 完全分離；覆寫原因必填（供追溯）。</li>
                <li>「數值明細」只有部分計算方式支援（目前：產能達成率—插齒、插齒製程不良率、
                    月受訂／月銷貨目標達成率（本方案自有目標）、產品開發評估完成時效、型態識別文件確認率、
                    客供料點收檢驗不良率、包裝效率、採購進貨準交率、矯正措施按時結案率、全廠 KPI 總體達標率），
                    不支援的會直接說明原因。</li>
                <li><b>逐筆排除</b>：在明細清單勾選真正不應該算進這個月績效的那幾筆，按「排除選取」——
                    排除只影響 KPI 計算，<b>不會修改任何一筆真實資料</b>，也可以隨時「取消排除」。</li>
                <li><b>排除規則</b>（整年度依客戶／料號等維度一次排除）只有「重用正式計算模組」的那幾種
                    計算方式才有（因為要有現成的維度可選）；逐筆排除則所有支援明細的計算方式都可以用。</li>
                <li>這一整套（覆寫、填寫、逐筆排除、排除規則）都是即時試算，不會寫進任何月快照，
                    頁面重整就會用最新設定重新算一次。</li>
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

/* ===== 指標說明（同時涵蓋總覽與設定分頁的 info 圖示），兼做「單一欄位修改／明細」用的指標資料 ===== */
var ITEM_INFO = {};
var IID2ITEM = {};   // indicator_id -> item_no，儲存格只帶 iid，查 ITEM_INFO 要先轉一次
<?php foreach ($ROWS as $row):
    $itemNo = (int)$row['item_no'];
    $iid = (int)$row['indicator_id'];
    $reg = kpi_scheme_registry();
    $calcDesc = ($row['calculator_key'] && isset($reg[$row['calculator_key']]))
        ? $reg[$row['calculator_key']]['desc'] : '';
?>
ITEM_INFO[<?= $itemNo ?>] = <?= json_encode([
    'iid'=>$iid, 'name'=>$row['name'], 'block'=>$row['block'], 'calc_key'=>$row['calculator_key'],
    'calc_desc'=>$calcDesc, 'params'=>$row['params_json'], 'note'=>$row['note'],
    'source_mode'=>$row['source_mode'], 'active'=>(int)$row['year_active'],
    'value_type'=>$row['value_type'], 'freq'=>$row['freq'], 'target_text'=>kpsTargetTextRow($row),
], JSON_UNESCAPED_UNICODE) ?>;
IID2ITEM[<?= $iid ?>] = <?= $itemNo ?>;
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

/* ============================================================
 * 單一欄位修改／增減個別調整排除／查看單一月份統計資料（2026-10-05 續，比照 KPI.php）
 * ============================================================ */
var YESNO = <?= json_encode(kpi_as_yesno_tokens(), JSON_UNESCAPED_UNICODE) ?>;
var FILL = false;            // 補登模式（管理員補資料）開關
var FILL_DIRTY = {};         // iid_m -> 使用者輸入但尚未儲存的值
var FILL_ORIG = {};          // iid_m -> 進補登模式前這格的原始 HTML（離開時原樣還原，不必整頁重load）
function kpiParseInputJs(type, raw){
    var s = $.trim(String(raw == null ? '' : raw));
    if (s === '') return null;
    if (type === 'yesno') {
        var t = s.toUpperCase();
        if (YESNO.yes.indexOf(t) >= 0) return 1;
        if (YESNO.no.indexOf(t) >= 0) return 0;
        return isNaN(Number(s)) ? null : (Number(s) >= 1 ? 1 : 0);
    }
    return isNaN(Number(s)) ? null : Number(s);
}

/* ---------- 儲存格點擊選單 ---------- */
$(document).on('click', 'td.ks-cell', function(e){
    if (FILL) return;                                     // 補登模式：點格子＝直接編輯，不開選單
    var $td = $(this);
    var iid = +$td.data('iid'), m = +$td.data('m'), src = $td.data('src') || '';
    var itemNo = IID2ITEM[iid]; var it = ITEM_INFO[itemNo];
    if (!it) return;
    var items = [];
    if (it.source_mode === 'auto') {
        items.push({t:'<i class="fa fa-info-circle"></i> 數值明細 / 不符合標準的明細', f:function(){ openVio(iid, m); }});
        items.push({t:'<i class="fa fa-hand-paper-o"></i> 手動覆寫', f:function(){ openFillMask(iid, m, 'override'); }});
        if (src === 'override') items.push({t:'<i class="fa fa-eraser"></i> 清除覆寫', f:function(){ doClearOverride(iid, m); }});
    } else {
        items.push({t:'<i class="fa fa-pencil"></i> 填寫 / 修改', f:function(){ openFillMask(iid, m, 'fill'); }});
        if (src === 'manual') items.push({t:'<i class="fa fa-eraser"></i> 清除填寫', f:function(){ doClearFill(iid, m); }});
    }
    items.push({t:'<i class="fa fa-paperclip"></i> 佐證附件', f:function(){ openAttach(iid, m); }});
    var html = '<div class="cm-head">' + itemNo + '. ' + esc(it.name) + '｜' + m + '月</div>';
    items.forEach(function(x, i){ html += '<div class="cm-item" data-i="' + i + '">' + x.t + '</div>'; });
    var $menu = $('#cellMenu').html(html).show();
    // position:absolute 用的是文件座標（跟 e.pageX/pageY 同一套），下限/上限都要
    // 加回目前捲動量才夾得對——只用 $(window).width()/height() 夾會在頁面捲動後把選單
    // 夾到視窗外看不見的地方。
    var scrollL = $(window).scrollLeft(), scrollT = $(window).scrollTop();
    var maxLeft = scrollL + $(window).width() - 220;
    var maxTop  = scrollT + $(window).height() - (items.length * 34 + 40);
    var left = Math.min(e.pageX, maxLeft);
    var top  = Math.min(e.pageY + 4, maxTop);
    $menu.css({left: Math.max(scrollL + 4, left), top: Math.max(scrollT + 4, top)});
    $menu.find('.cm-item').off('click').on('click', function(){ $menu.hide(); items[+$(this).data('i')].f(); });
    e.stopPropagation();
});
$(document).on('click', function(){ $('#cellMenu').hide(); });

/* ============================================================
 * 補登模式（管理員補資料；使用者要求比照 KPI.php 的整張表直接填寫）
 * ------------------------------------------------------------
 * 整張表的每一格換成輸入框，離開補登模式時用進入前存下的原始 HTML 直接換回去
 * （不必整頁重新整理），寫入一律走 bulk_override（顯示優先序最高的覆寫值），
 * 不要求逐格填原因，一次送出整批。
 * ============================================================ */
function fillCanUse(){ return <?= ($kpiPerms['isAdmin'] || $kpiPerms['canAdmin']) ? 'true' : 'false' ?>; }
function fillKey(iid, m){ return iid + '_' + m; }
function toggleFill(){
    if (!fillCanUse()) { alert('補登模式僅 KPI 管理員／系統管理員可用'); return; }
    if (FILL && Object.keys(FILL_DIRTY).length) {
        if (!confirm('有 ' + Object.keys(FILL_DIRTY).length + ' 格還沒儲存，確定離開補登模式？')) return;
    }
    FILL = !FILL;
    FILL_DIRTY = {};
    $('#btnFill').toggleClass('on', FILL);
    $('#fillBar').toggle(FILL);
    renderFillCells();
}
function fillCellHtml(td){
    var $td = $(td);
    var iid = +$td.data('iid'), m = +$td.data('m');
    var itemNo = IID2ITEM[iid]; var it = ITEM_INFO[itemNo];
    if (!it) return $td.html();                          // 不是可編輯指標格（理論上不會發生，保底）
    var key = fillKey(iid, m);
    var v = (key in FILL_DIRTY) ? FILL_DIRTY[key] : ($td.data('rawv') == null ? '' : String($td.data('rawv')));
    var cls = 'fillIn' + ($td.data('src') === 'override' ? ' ov' : '') + ((key in FILL_DIRTY) ? ' dirty' : '');
    var at = ' data-i="' + iid + '" data-m="' + m + '"';
    if (it.value_type === 'yesno') {
        var sv = (v === '' || v === null) ? '' : (Number(v) >= 1 ? '1' : '0');
        return '<select class="' + cls + '"' + at + '>'
             + '<option value=""' + (sv === ''  ? ' selected' : '') + '>—</option>'
             + '<option value="1"' + (sv === '1' ? ' selected' : '') + '>Yes</option>'
             + '<option value="0"' + (sv === '0' ? ' selected' : '') + '>No</option></select>';
    }
    return '<input type="text" class="' + cls + '"' + at + ' value="' + esc(String(v)) + '" autocomplete="off">';
}
function renderFillCells(){
    $('table.ks-tbl td.ks-cell').each(function(){
        var $td = $(this);
        var iid = +$td.data('iid'), m = +$td.data('m');
        var key = fillKey(iid, m);
        if (FILL) {
            if ($td.data('future') == 1) return;          // 未來月份不開放補登，維持原樣（NA）
            if (!(key in FILL_ORIG)) FILL_ORIG[key] = $td.html();
            $td.html(fillCellHtml(this));
        } else if (key in FILL_ORIG) {
            $td.html(FILL_ORIG[key]);
            delete FILL_ORIG[key];
        }
    });
}
$(document).on('input change', 'table.ks-tbl .fillIn', function(){
    var $i = $(this);
    FILL_DIRTY[fillKey($i.attr('data-i'), $i.attr('data-m'))] = $i.val();
    $i.addClass('dirty');
    $('#fillCount').text(Object.keys(FILL_DIRTY).length);
});
/* 點到已有資料的格子＝整個值選起來，直接打字就換掉（比照 KPI.php 同一套規則） */
$(document).on('focus', 'table.ks-tbl .fillIn', function(){
    var el = this;
    if (el.value === '') return;
    el.__selAll = true;
    setTimeout(function(){
        if (document.activeElement !== el) return;
        try { el.select(); } catch (err) {}
    }, 0);
});
$(document).on('mouseup', 'table.ks-tbl .fillIn', function(e){
    if (this.__selAll) { e.preventDefault(); this.__selAll = false; }
});
$(document).on('blur', 'table.ks-tbl .fillIn', function(){ this.__selAll = false; });
/* Enter＝往右一格（換行接下一列開頭）；↑↓＝同一個月份上下移動 */
$(document).on('keydown', 'table.ks-tbl .fillIn', function(e){
    var k = e.key;
    if (k !== 'Enter' && k !== 'ArrowDown' && k !== 'ArrowUp') return;
    e.preventDefault();
    if (k === 'Enter') {
        var all = $('table.ks-tbl .fillIn');
        var i = all.index(this);
        if (i >= 0 && i + 1 < all.length) all.eq(i + 1).focus().select();
        return;
    }
    var m = $(this).attr('data-m');
    var col = $('table.ks-tbl .fillIn[data-m="' + m + '"]');
    var idx = col.index(this);
    var to = (k === 'ArrowUp') ? idx - 1 : idx + 1;
    if (to >= 0 && to < col.length) col.eq(to).focus().select();
});
function fillSave(){
    var keys = Object.keys(FILL_DIRTY);
    if (!keys.length) { alert('沒有變更'); return; }
    var bad = [];
    var cells = keys.map(function(k){
        var p = k.split('_'), iid = +p[0];
        var it = ITEM_INFO[IID2ITEM[iid]];
        var v = $.trim(String(FILL_DIRTY[k]));
        if (v !== '' && it && kpiParseInputJs(it.value_type, v) === null) bad.push(v);
        return {i:iid, m:+p[1], v:v};
    });
    if (bad.length) { alert('有 ' + bad.length + ' 格的值無法辨識：' + bad.slice(0,5).join('、')
        + '\n數字型請填數字；Yes/No 型請填 ' + YESNO.yes.slice(0,3).join('／') + ' 或 '
        + YESNO.no.slice(0,3).join('／') + '，也可以清空該格。'); return; }
    var note = $('#fillNote').val();
    if (!confirm('把 ' + cells.length + ' 格寫進 ' + KS_YEAR + ' 年度？\n（寫入方式＝手動覆寫，不需要逐格填原因；空白的格子＝清除覆寫）')) return;
    $.post(API, {action:'bulk_override', year:KS_YEAR, note:note, cells:JSON.stringify(cells)}, function(res){
        if (!res || !res.ok) { alert((res && res.error) || '儲存失敗'); return; }
        var msg = '已寫入 ' + res.saved + ' 格' + (res.cleared ? ('，清除 ' + res.cleared + ' 格') : '') + '。';
        if (res.skipped && res.skipped.length) msg += '\n略過 ' + res.skipped.length + ' 格：\n' + res.skipped.slice(0,8).join('\n');
        alert(msg);
        location.reload();
    }, 'json').fail(function(x){ alert('儲存失敗：' + ((x.responseJSON && x.responseJSON.error) || x.status)); });
}

/* ---------- 佐證附件 ---------- */
var ATT_CTX = null;
function openAttach(iid, m){
    ATT_CTX = {iid:iid, m:m};
    var itemNo = IID2ITEM[iid]; var it = ITEM_INFO[itemNo];
    document.getElementById('attTitle').textContent = '佐證附件：' + itemNo + '. ' + it.name + '　' + KS_YEAR + '年' + m + '月';
    refreshAttList();
    openMask('attMask');
}
function refreshAttList(){
    if (!ATT_CTX) return;
    $.getJSON(API, {action:'attach_list', indicator_id:ATT_CTX.iid, year:KS_YEAR, month:ATT_CTX.m}, function(res){
        if (!res || !res.ok) { $('#attList').html(esc((res && res.error) || '載入失敗')); return; }
        $('#attLimitTxt').text('每月每項上限 ' + res.max + ' 件，單檔 20MB');
        if (!res.list.length) { $('#attList').html('<span style="color:#8a6d45;font-size:12px;">尚無附件</span>'); return; }
        var h = '';
        res.list.forEach(function(a){
            h += '<div class="att-row">';
            h += a.exists
               ? '<span class="att-name" title="開啟" onclick="window.open(API+\'?action=attach_open&attach_id=' + a.attach_id + '\')">📄 ' + esc(a.original_name) + '</span>'
               : '<span class="att-name att-missing" title="檔案不存在（NAS路徑可能已變更）">📄 ' + esc(a.original_name) + '</span>';
            h += '<span class="att-note" title="' + esc(a.note || '') + '">' + esc(a.note || '') + '</span>';
            h += '<span style="color:#8a6d45;font-size:11px;">' + esc(a.uploaded_by_name || '') + ' ' + esc((a.created_at || '').substr(5,11)) + '</span>';
            if (a.can_delete) h += '<span class="att-del" title="刪除" onclick="delAttach(' + a.attach_id + ')"><i class="fa fa-trash"></i></span>';
            h += '</div>';
        });
        $('#attList').html(h);
    });
}
/* 一次多選上傳：後端一支請求收一個檔，逐檔送、全部送完才回報（比照 KPI.php）。
   送出當下直接讀 input.files，不靠 change 事件記住檔案（見記憶 file_upload_change_event）。 */
function submitAttach(){
    if (!ATT_CTX) return;
    var f = document.getElementById('attFile');
    var files = f.files ? Array.prototype.slice.call(f.files) : [];
    if (!files.length) { alert('請選擇檔案'); return; }
    var note = $('#attNote').val();
    var okN = 0, errs = [];
    (function next(i){
        if (i >= files.length) {
            f.value = ''; $('#attNote').val('');
            refreshAttList();
            if (errs.length) alert('成功 ' + okN + ' 個，失敗 ' + errs.length + ' 個：\n' + errs.join('\n'));
            else if (okN > 1) alert('已上傳 ' + okN + ' 個檔案。');
            return;
        }
        var fd = new FormData();
        fd.append('action', 'attach_upload');
        fd.append('indicator_id', ATT_CTX.iid);
        fd.append('year', KS_YEAR);
        fd.append('month', ATT_CTX.m);
        fd.append('note', note);
        fd.append('file', files[i]);
        $.ajax({url:API, method:'POST', data:fd, processData:false, contentType:false, dataType:'json'})
            .done(function(res){
                if (res && res.ok) okN++; else errs.push(files[i].name + '：' + ((res && res.error) || '上傳失敗'));
                next(i + 1);
            })
            .fail(function(x){
                errs.push(files[i].name + '：' + ((x.responseJSON && x.responseJSON.error) || x.status));
                next(i + 1);
            });
    })(0);
}
function delAttach(aid){
    if (!confirm('刪除此附件？（NAS上的檔案將一併刪除）')) return;
    $.post(API, {action:'attach_delete', attach_id:aid}, function(res){
        if (!res || !res.ok) { alert((res && res.error) || '刪除失敗'); return; }
        refreshAttList();
    }, 'json').fail(function(){ alert('刪除失敗：連線異常'); });
}

/* ---------- 單一欄位修改：填寫（人工）／手動覆寫（自動） ---------- */
function openFillMask(iid, m, mode){
    var itemNo = IID2ITEM[iid]; var it = ITEM_INFO[itemNo];
    var hint = it.value_type === 'percent' ? '例：85（代表 85%）'
             : it.value_type === 'yesno' ? '輸入 Yes/No 或 1/0'
             : '請輸入數字';
    document.getElementById('fillTitle').textContent = (mode === 'fill' ? '填寫' : '手動覆寫')
        + '　' + itemNo + '. ' + it.name + '　' + KS_YEAR + '年' + m + '月';
    var h = '<div class="fillm-row"><label>數值</label><input type="text" id="fmVal" placeholder="' + esc(hint) + '"></div>';
    if (mode === 'override') {
        h += '<div class="fillm-row"><label>覆寫原因</label><textarea id="fmReason" placeholder="必填，供追溯（AS9100 可追溯要求）"></textarea></div>';
    } else {
        h += '<div class="fillm-row"><label>備註</label><textarea id="fmNote" placeholder="選填"></textarea></div>';
    }
    h += '<div class="ksc-err" id="fmErr" style="display:none;"></div>';
    h += '<div class="ksc-foot" style="padding:10px 0 0;border-top:none;">'
       + '<button class="ksc-btn-save" onclick="doFillSave(' + iid + ',' + m + ',\'' + mode + '\')"><i class="fa fa-save"></i> 儲存</button>'
       + '<button class="ksc-btn-cancel" onclick="closeMask(\'fillMask\')">取消</button></div>';
    document.getElementById('fillBody').innerHTML = h;
    openMask('fillMask');
}
function doFillSave(iid, m, mode){
    var raw = $('#fmVal').val();
    var err = document.getElementById('fmErr');
    err.style.display = 'none';
    var post = {action: mode === 'fill' ? 'fill' : 'override', indicator_id: iid, year: KS_YEAR, month: m, value: raw};
    if (mode === 'fill') post.note = $('#fmNote').val();
    else post.reason = $('#fmReason').val();
    $.post(API, post, function(res){
        if (!res || !res.ok) { err.style.display = 'block'; err.textContent = (res && res.error) || '儲存失敗'; return; }
        closeMask('fillMask');
        location.reload();
    }, 'json').fail(function(x){
        err.style.display = 'block'; err.textContent = '儲存失敗：' + ((x.responseJSON && x.responseJSON.error) || x.status);
    });
}
function doClearFill(iid, m){
    if (!confirm('確定要清除這一格的填寫內容？')) return;
    $.post(API, {action:'clear_fill', indicator_id:iid, year:KS_YEAR, month:m}, function(res){
        if (!res || !res.ok) { alert((res && res.error) || '清除失敗'); return; }
        location.reload();
    }, 'json').fail(function(){ alert('清除失敗：連線異常'); });
}
function doClearOverride(iid, m){
    if (!confirm('確定要清除這一格的手動覆寫？（會恢復成自動計算的值）')) return;
    $.post(API, {action:'clear_override', indicator_id:iid, year:KS_YEAR, month:m}, function(res){
        if (!res || !res.ok) { alert((res && res.error) || '清除失敗'); return; }
        location.reload();
    }, 'json').fail(function(){ alert('清除失敗：連線異常'); });
}

/* ---------- 數值明細／不符合標準的明細（含逐筆排除＋整年度排除規則） ---------- */
var VIO = null;
function openVio(iid, m){
    var itemNo = IID2ITEM[iid]; var it = ITEM_INFO[itemNo];
    VIO = {iid:iid, m:m, itemNo:itemNo, data:null, filt:{}, kw:'', kind:'bad',
           rdim:'', rkw:'', rsel:[], rfound:[], rsrc:'', rbusy:0, rscope:'year'};
    document.getElementById('vioTitle').textContent = itemNo + '. ' + it.name + '　' + KS_YEAR + '年' + m + '月';
    document.getElementById('vioBody').innerHTML = '<div style="padding:16px;color:#8a6d45;">載入中…</div>';
    document.getElementById('vioFoot').innerHTML = '';
    openMask('vioMask');
    $.getJSON(API, {action:'detail_rows', indicator_id:iid, year:KS_YEAR, month:m}, function(res){
        if (!res || !res.ok) { $('#vioBody').html('<div style="padding:16px;color:#DD5138;">' + esc((res&&res.error)||'載入失敗') + '</div>'); return; }
        VIO.data = res;
        renderVio();
    }).fail(function(x){
        $('#vioBody').html('<div style="padding:16px;color:#DD5138;">載入失敗：' + esc((x.responseJSON&&x.responseJSON.error)||x.status) + '</div>');
    });
}
function vioResetFilter(){ VIO.filt = {}; VIO.kw = ''; VIO.kind = 'bad'; }
function vioRowVisible(x){
    var d = VIO.data;
    if (VIO.kind === 'bad' && x.kind === 'info' && !+x.excluded && !x.rule_ex) return false;
    for (var k in VIO.filt) {
        if (!VIO.filt[k]) continue;
        var v = (x.dims && x.dims[k] != null) ? String(x.dims[k]) : '';
        if (v !== VIO.filt[k]) return false;
    }
    if (VIO.kw) {
        var hay = '';
        d.cols.forEach(function(c){ hay += ' ' + (x.vals[c.k] == null ? '' : x.vals[c.k]); });
        hay += ' ' + (x.why || '');
        var ws = VIO.kw.split(/\s+/);
        for (var i = 0; i < ws.length; i++) { if (ws[i] && hay.toUpperCase().indexOf(ws[i].toUpperCase()) < 0) return false; }
    }
    return true;
}
function vioFilterHtml(){
    var d = VIO.data, h = '<div class="vio-filter">';
    (d.dims || []).forEach(function(dm){
        if (!dm.opts.length) return;
        h += '<label>' + esc(dm.t) + '：<select class="vioF" data-k="' + esc(dm.k) + '" data-eg-filter="輸入' + esc(dm.t) + '篩選…">'
           + '<option value="">全部（' + dm.opts.length + '）</option>';
        dm.opts.forEach(function(o){
            var txt = o.v + (o.id && o.id !== o.v ? ('（' + o.id + '）') : '');
            h += '<option value="' + esc(o.v) + '"' + (VIO.filt[dm.k] === o.v ? ' selected' : '') + '>' + esc(txt) + '</option>';
        });
        h += '</select></label>';
    });
    h += '<label>關鍵字：<input type="text" id="vioKw" value="' + esc(VIO.kw || '') + '" placeholder="製令／單號／代號…"></label>';
    var hasInfo = false;
    d.rows.forEach(function(x){ if (x.kind === 'info') hasInfo = true; });
    if (hasInfo) {
        h += '<label>顯示：<select id="vioKind">'
           + '<option value="bad"' + (VIO.kind === 'bad' ? ' selected' : '') + '>只看不符合標準</option>'
           + '<option value="all"' + (VIO.kind === 'all' ? ' selected' : '') + '>全部（含參考用的正常資料）</option>'
           + '</select></label>';
    }
    h += '<span class="vf-clear" id="vioClr">清除篩選</span></div>';
    return h;
}
function vioDimById(k){ var r = null; ((VIO.data && VIO.data.dims) || []).forEach(function(d){ if (d.k === k) r = d; }); return r; }
function vioRuleWords(){ return $.trim(VIO.rkw || '').split(/\s+/).filter(function(w){ return w.length > 0; }); }
function vioRuleMatch(o, ws){
    var hay = (o.v + ' ' + (o.id || '')).toUpperCase();
    for (var i = 0; i < ws.length; i++) if (hay.indexOf(ws[i].toUpperCase()) < 0) return false;
    return true;
}
function vioRenderRuleList(){
    var dm = vioDimById($('#vrDim').val()), ws = vioRuleWords(), h = '', n = 0, total = 0;
    var picked = {}; (VIO.rsel || []).forEach(function(v){ picked[v] = 1; });
    var dimK = $('#vrDim').val(), done = {};
    ((VIO.data && VIO.data.rules) || []).forEach(function(x){ if (x.dim === dimK) done[x.val] = 1; });
    var cap = 60, seen = {}, list = [];
    ((dm && dm.opts) || []).forEach(function(o){
        if (!vioRuleMatch(o, ws) || seen[o.v]) return; seen[o.v] = 1; list.push({v:o.v, id:o.id, src:'本月'});
    });
    var fsrc = VIO.rsrc || '主檔';
    (VIO.rfound || []).forEach(function(o){ if (seen[o.v]) return; seen[o.v] = 1; list.push({v:o.v, id:o.id, src:fsrc}); });
    list = list.filter(function(o){ return !done[o.v]; });
    list.forEach(function(o){
        total++; if (n >= cap) return; n++;
        h += '<div class="vr-it' + (picked[o.v] ? ' on' : '') + '" data-v="' + esc(o.v) + '">'
           + esc(o.v) + (o.id ? ('<span class="vr-id">' + esc(o.id) + '</span>') : '')
           + '<span class="vr-src">' + esc(o.src) + '</span>' + (picked[o.v] ? '　✔ 已選' : '') + '</div>';
    });
    if (total > cap) h += '<div class="vr-none">還有 ' + (total - cap) + ' 筆沒顯示，請再輸入關鍵字縮小範圍。</div>';
    if (!total) {
        var kw = $.trim(VIO.rkw || '');
        h = VIO.rbusy ? '<div class="vr-none">查詢中…</div>'
          : (kw ? '<div class="vr-none">找不到「' + esc(kw) + '」。排除規則比對的是<b>名稱</b>，'
                + '真的要直接使用請按 <a href="#" id="vrFree">直接使用「' + esc(kw) + '」</a>。</div>'
               : '<div class="vr-none">這個年度的資料裡沒有可選的項目，請輸入代號或名稱搜尋主檔。</div>');
    }
    $('#vrList').html(h);
    var ph = ''; (VIO.rsel || []).forEach(function(v){
        ph += '<span class="vr-chip2">' + esc(v) + '<span class="vr-x2" data-v="' + esc(v) + '">×</span></span>';
    });
    $('#vrPicked').html('已選 ' + (VIO.rsel || []).length + ' 項：' + (ph || '<span style="color:#a08356;">（尚未選擇）</span>'));
}
function vioRulesHtml(){
    var d = VIO.data, h = '';
    var rs = d.rules || [], lb = d.dim_labels || {};
    h += '<div class="vio-rules"><b>排除規則</b>（' + KS_YEAR + ' 年度整年適用，只影響 KPI 計算、不會修改任何真實資料）：';
    if (!rs.length) h += '<span style="color:#a08356;">目前沒有設定任何規則。</span>';
    rs.forEach(function(r){
        h += '<span class="vr-chip' + (r.scope === 'all' ? ' allyr' : '') + '" title="'
           + esc(r.created_by_name || '') + ' ' + esc((r.created_at || '').substr(0, 16))
           + (r.reason ? ('｜' + esc(r.reason)) : '') + '">'
           + esc(lb[r.dim] || r.dim) + '：' + esc(r.val)
           + '<span class="vr-scope">' + (r.scope === 'all' ? '所有年度' : (r.year + ' 年度')) + '</span>'
           + (+d.can_adjust ? ('<span class="vr-x" data-id="' + r.rule_id + '" title="取消這條規則">×</span>') : '')
           + '</span>';
    });
    if (+d.can_adjust && (d.dims || []).length) {
        h += '<div class="vr-new">新增：<select id="vrDim">';
        (d.dims || []).forEach(function(dm){
            if (!dm.opts.length) return;
            h += '<option value="' + esc(dm.k) + '"' + (VIO.rdim === dm.k ? ' selected' : '') + '>' + esc(dm.t) + '</option>';
        });
        h += '</select><input type="text" id="vrKw" value="' + esc(VIO.rkw || '') + '" placeholder="輸入代號或名稱模糊搜尋">'
           + '<label style="margin:0;font-weight:normal;font-size:12.5px;">適用：<select id="vrScope">'
           + '<option value="year"' + (VIO.rscope === 'all' ? '' : ' selected') + '>僅 ' + KS_YEAR + ' 年度</option>'
           + '<option value="all"' + (VIO.rscope === 'all' ? ' selected' : '') + '>所有年度</option>'
           + '</select></label>'
           + '<button id="vrAdd" class="warm">建立排除規則（立即存檔）</button></div>'
           + '<div class="vr-list" id="vrList"></div><div class="vr-picked" id="vrPicked"></div>'
           + '<div class="vio-seltip" style="font-size:11.5px;color:#8a6d45;margin-top:4px;">'
           + '清單預設只列本年度資料裡真的有的；這個年度沒有的，打代號或名稱就會從主檔搜出來。'
           + '點一下加入／再點一次取消，不必填原因，系統會記下是誰在什麼時候設定的。</div>';
    }
    h += '</div>';
    return h;
}
function renderVio(){
    var d = VIO.data, h = '';
    if (!+d.supported) {
        h += '<div class="vio-note">' + esc(d.msg || '這個指標還沒有做數值明細。') + '</div>';
        $('#vioBody').html(h); $('#vioFoot').html(''); return;
    }
    var exN = 0; d.rows.forEach(function(x){ if (+x.excluded) exN++; });
    h += '<div class="vio-note">' + esc(d.note || '')
       + '<br>不符合標準 <b>' + d.total + '</b> 筆' + (exN ? ('，其中 <b>' + exN + '</b> 筆已逐筆排除') : '')
       + (+d.rule_ex ? ('，另有 <b>' + d.rule_ex + '</b> 筆被排除規則排掉') : '')
       + (+d.truncated ? '（畫面最多顯示 500 筆）' : '') + '。</div>';
    if (+d.readonly) {
        h += '<div class="vio-warn">這是沿用<b>正式 KPI 表</b>背後計算模組所算出來的明細，<b>僅供檢視</b>——'
           + '本方案的指標本身是直接讀正式表的月快照，不會自己重算，所以這裡不提供排除。'
           + '要調整請到 <a href="KPI.php" target="_blank" rel="noopener">正式 KPI 表</a> 操作。</div>';
    } else if (+d.can_adjust) {
        h += '<div class="vio-warn">確實不符合標準的請保持原樣；不該算進績效的那幾筆才勾選後按「排除選取」——'
           + '<b>排除只影響 KPI 計算，不會動到任何一筆真實資料</b>。</div>';
    }
    if (!+d.readonly) h += vioRulesHtml();
    h += vioFilterHtml();

    var showChk = (+d.can_adjust && d.rows.length) ? 1 : 0;
    h += '<div class="vio-tblwrap"><table class="vio-tbl"><colgroup>';
    if (showChk) h += '<col style="width:26px;">';
    d.cols.forEach(function(){ h += '<col>'; });
    h += '<col style="width:18%;">';
    h += '</colgroup><thead><tr>';
    if (showChk) h += '<th><input type="checkbox" id="vioAll" title="全選目前篩選出來的列"></th>';
    d.cols.forEach(function(c){ h += '<th>' + esc(c.t) + '</th>'; });
    h += '<th>不符合的原因</th></tr></thead><tbody id="vioTb">';
    var shown = 0;
    d.rows.forEach(function(x, ix){
        if (!vioRowVisible(x)) return;
        shown++;
        var cls = []; if (+x.excluded) cls.push('ex'); if (x.rule_ex) cls.push('rex');
        h += '<tr class="' + cls.join(' ') + '" data-k="' + esc(x.key) + '" data-ix="' + ix + '">';
        if (showChk) h += '<td>' + (x.excluded || x.rule_ex ? '' : '<input type="checkbox" class="vioChk">') + '</td>';
        d.cols.forEach(function(c){ h += '<td>' + esc(x.vals[c.k] == null ? '' : x.vals[c.k]) + '</td>'; });
        h += '<td>' + esc(x.why || '')
           + (x.rule_ex ? ('　<span style="color:#a08356;">（規則排除：' + esc(x.rule_ex) + '）</span>') : '')
           + (+x.excluded ? ('　<button class="vio-exbtn undo" data-k="' + esc(x.key) + '" data-act="undo">取消排除</button>'
                              + '<span style="color:#a08356;font-size:11px;"> ' + esc(x.ex_by || '') + ' '
                              + esc((x.ex_at || '').substr(0,10)) + (x.ex_reason ? ('｜' + esc(x.ex_reason)) : '') + '</span>')
             : '') + '</td></tr>';
    });
    if (!shown) h += '<tr><td colspan="' + (d.cols.length + (showChk?2:1)) + '" style="text-align:center;color:#a08356;">（沒有符合篩選條件的項目）</td></tr>';
    h += '</tbody></table></div>';
    $('#vioBody').html(h);

    var foot = '';
    if (+d.can_adjust) {
        foot = '<input type="text" id="vioExReason" placeholder="排除原因（選填）" style="flex:1;height:30px;border:1px solid #D8BE93;border-radius:4px;padding:0 8px;font-size:13px;">'
             + '<button class="ksc-btn-save" id="vioExBtn"><i class="fa fa-ban"></i> 排除選取</button>';
    }
    $('#vioFoot').html(foot);

    $('#vioKw').off('input').on('input', function(){ VIO.kw = $(this).val(); renderVio(); });
    $('.vioF').off('change').on('change', function(){ VIO.filt[$(this).data('k')] = $(this).val(); renderVio(); });
    $('#vioKind').off('change').on('change', function(){ VIO.kind = $(this).val(); renderVio(); });
    $('#vioClr').off('click').on('click', function(){ vioResetFilter(); renderVio(); });
    $('#vioAll').off('change').on('change', function(){ $('.vioChk').prop('checked', $(this).prop('checked')); });
    $('.vio-exbtn').off('click').on('click', function(){
        var k = $(this).data('k');
        $.post(API, {action:'adjust_del', indicator_id:VIO.iid, year:KS_YEAR, month:VIO.m, keys:JSON.stringify([k])}, function(res){
            if (!res || !res.ok) { alert((res && res.error) || '取消排除失敗'); return; }
            openVio(VIO.iid, VIO.m);
        }, 'json').fail(function(){ alert('連線異常'); });
    });
    $('#vioExBtn').off('click').on('click', function(){
        var keys = []; $('#vioTb tr').each(function(){ if ($(this).find('.vioChk').prop('checked')) keys.push($(this).data('k')); });
        if (!keys.length) { alert('請先勾選要排除的項目'); return; }
        $.post(API, {action:'adjust_add', indicator_id:VIO.iid, year:KS_YEAR, month:VIO.m,
                     keys:JSON.stringify(keys), reason:$('#vioExReason').val()}, function(res){
            if (!res || !res.ok) { alert((res && res.error) || '排除失敗'); return; }
            openVio(VIO.iid, VIO.m);
        }, 'json').fail(function(){ alert('連線異常'); });
    });
    $('.vr-x').off('click').on('click', function(){
        if (!confirm('確定要取消這條排除規則？')) return;
        $.post(API, {action:'excl_rule_del', indicator_id:VIO.iid, year:KS_YEAR, rule_ids:JSON.stringify([$(this).data('id')])}, function(res){
            if (!res || !res.ok) { alert((res && res.error) || '取消失敗'); return; }
            openVio(VIO.iid, VIO.m);
        }, 'json').fail(function(){ alert('連線異常'); });
    });
    $('#vrDim').off('change').on('change', function(){ VIO.rdim = $(this).val(); VIO.rsel = []; VIO.rfound = []; VIO.rkw = ''; $('#vrKw').val(''); vioRenderRuleList(); });
    var vrTimer = null;
    $('#vrKw').off('input').on('input', function(){
        VIO.rkw = $(this).val();
        if (vrTimer) clearTimeout(vrTimer);
        vrTimer = setTimeout(function(){
            var q = $.trim(VIO.rkw || '');
            if (q === '') { VIO.rfound = []; VIO.rsrc = ''; vioRenderRuleList(); return; }
            VIO.rbusy = 1; vioRenderRuleList();
            $.getJSON(API, {action:'excl_dim_search', indicator_id:VIO.iid, year:KS_YEAR, dim:$('#vrDim').val(), q:q}, function(res){
                VIO.rbusy = 0;
                if (!res || !res.ok) { VIO.rfound = []; vioRenderRuleList(); return; }
                VIO.rfound = res.rows || []; VIO.rsrc = res.src || '主檔'; vioRenderRuleList();
            }).fail(function(){ VIO.rbusy = 0; vioRenderRuleList(); });
        }, 280);
    });
    if (VIO.rdim === '') { var $firstDim = $('#vrDim option:first'); if ($firstDim.length) VIO.rdim = $firstDim.val(); }
    $(document).off('click.vrit').on('click.vrit', '.vr-it', function(){
        var v = $(this).data('v'); var i = VIO.rsel.indexOf(v);
        if (i >= 0) VIO.rsel.splice(i, 1); else VIO.rsel.push(v);
        vioRenderRuleList();
    });
    $(document).off('click.vrx2').on('click.vrx2', '.vr-x2', function(){
        var v = $(this).data('v'); var i = VIO.rsel.indexOf(v);
        if (i >= 0) VIO.rsel.splice(i, 1);
        vioRenderRuleList();
    });
    $(document).off('click.vrfree').on('click.vrfree', '#vrFree', function(e){
        e.preventDefault();
        var kw = $.trim(VIO.rkw || ''); if (kw && VIO.rsel.indexOf(kw) < 0) VIO.rsel.push(kw);
        vioRenderRuleList();
    });
    $('#vrAdd').off('click').on('click', function(){
        if (!VIO.rsel.length) { alert('請先點選要排除的項目'); return; }
        $.post(API, {action:'excl_rule_add', indicator_id:VIO.iid, year:KS_YEAR, dim:$('#vrDim').val(),
                     vals:JSON.stringify(VIO.rsel), scope:$('#vrScope').val()}, function(res){
            if (!res || !res.ok) { alert((res && res.error) || '建立失敗'); return; }
            openVio(VIO.iid, VIO.m);
        }, 'json').fail(function(){ alert('連線異常'); });
    });
    if ($('#vrDim').length) vioRenderRuleList();
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
        if (p.type === 'months_map') {
            // 各月目標金額——本方案自己的指標直接在這裡填（不必去正式 KPI 設定頁 KPI.php 調整），
            // 寬度要放 12 個月份輸入框，不能套用其他參數共用的窄欄位（w2=170px）
            var mv = (v && typeof v === 'object') ? v : {};
            var mmHtml = '<div style="width:100%;margin-bottom:6px;"><label style="font-size:11.5px;color:#8a6d45;display:block;margin-bottom:3px;">'
                       + esc(p.label) + '</label><div id="' + id + '" style="display:flex;flex-wrap:wrap;gap:4px;">';
            for (var mm = 1; mm <= 12; mm++) {
                var mval = mv[mm] !== undefined ? mv[mm] : '';
                mmHtml += '<div style="width:72px;"><div style="font-size:10.5px;color:#8a6d45;text-align:center;">' + mm + '月</div>'
                        + '<input type="text" class="mm-input" data-m="' + mm + '" value="' + esc(mval) + '" '
                        + 'style="width:100%;height:24px;border:1px solid #D8BE93;border-radius:3px;padding:0 4px;text-align:right;font-size:11.5px;"></div>';
            }
            h += mmHtml + '</div></div>';
            return;
        }
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
        } else if (p.type === 'bool') {
            var checked = !!v;
            h += '<label style="font-size:12.5px;"><input type="checkbox" id="' + id + '"' + (checked ? ' checked' : '') + '> 啟用</label>';
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
        } else if (p.type === 'bool') {
            var elb = document.getElementById(id);
            out[p.key] = !!(elb && elb.checked);
        } else if (p.type === 'months_map') {
            var mm = {};
            document.querySelectorAll('#' + id + ' .mm-input').forEach(function(inp){
                var mv = inp.value.trim();
                if (mv !== '' && !isNaN(mv)) mm[inp.getAttribute('data-m')] = parseFloat(mv);
            });
            out[p.key] = mm;
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
