<?php
/**
 * AS9100 關鍵績效指標總覽（2-GM-04-01）
 * 21項指標 × 12月 + 平均/去年平均；自動計算(快照鎖定)+手動填寫+管理者覆寫+佐證附件+前端試算
 * 資料一律走 src/store/KpiAs_API.php；設定頁 KPI_setting.php（僅KPI管理者）
 */
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/news/KPI.php?in=999";
    header("Location:../../index.php");
    exit;
}
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/kpi_as_lib.php';

$db = (new DBConnection())->getPDO();
kpi_as_ensure_schema($db);
$kpiUser = kpi_as_current_user($db);
$kpiPerms = kpi_as_perms($db, $kpiUser);
$roleLabel = $kpiPerms['isAdmin'] ? '管理者'
           : ($kpiPerms['canAdmin'] ? 'KPI管理員'
           : ($kpiPerms['canFill'] ? 'KPI填報'
           : ($kpiPerms['canView'] ? 'KPI檢閱（唯讀）' : '無權限')));
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>KPI 關鍵績效指標</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <style>
        #sidebar-menu { visibility: hidden; }
        .page-help-btn { margin-left:auto; height:30px; padding:0 12px; border:1px solid #D8BE93;
            background:#FDF8EF; color:#8A5A2B; border-radius:4px; cursor:pointer; font-size:13px; }
        .page-help-btn:hover { background:#F7E0BD; }
        @media print { .page-help-btn { display:none !important; } }
        .help-doc { font-size:13px; color:#5b3a1e; line-height:1.85; }
        .help-doc h4 { font-size:14px; color:#8A5A2B; margin:12px 0 4px; border-bottom:1px solid #EADFC8; padding-bottom:3px; }
        .help-doc ul { padding-left:20px; margin:4px 0; }
        .help-doc b { color:#8A5A2B; }
        .right_col .page-title { margin:8px 0 4px; overflow:hidden; }
        .right_col .page-title h2 { margin:6px 0; }
        .kpi-toolbar { clear:both; }
        /* ===== 暖色系配色（ai-rules/10）===== */
        .kpi-toolbar { display:flex; flex-wrap:wrap; gap:6px; align-items:center;
            border:1.5px solid #E8D5B5; border-radius:8px; padding:8px 10px; margin-bottom:10px; background:#FDF8EF; }
        .kpi-toolbar select, .kpi-toolbar button, .kpi-toolbar a.btn { height:30px; font-size:13px; line-height:1;
            padding:0 10px; border:1px solid #D8BE93; border-radius:4px; background:#fff; color:#5b3a1e; cursor:pointer; }
        .kpi-toolbar button:hover, .kpi-toolbar a.btn:hover { background:#F7E0BD; }
        .kpi-toolbar .btn-warm { background:#F0A24B; color:#fff; border-color:#d98a33; }
        .kpi-toolbar .btn-warm:hover { background:#d98a33; }
        .kpi-role-badge { margin-left:auto; font-size:13px; color:#5b3a1e; background:#F7E0BD;
            border-radius:12px; padding:4px 12px; }
        .kpi-role-badge .fa-question-circle { cursor:pointer; color:#b5762a; margin-left:5px; }
        .kpi-table-wrap { overflow:auto; max-height:calc(100vh - 235px); min-height:320px;
            border:1px solid #E8D5B5; border-radius:6px; background:#fff; }
        table.kpi-table { width:100%; border-collapse:collapse; font-size:13px; table-layout:auto; }
        table.kpi-table th, table.kpi-table td { border:1px solid #EADFC8; padding:4px 6px; white-space:nowrap; text-align:center; }
        table.kpi-table thead th { position:sticky; top:0; z-index:5; background:#F7E0BD; color:#5b3a1e; font-weight:bold;
            box-shadow:inset 0 -2px 0 #D8BE93; }
        table.kpi-table td.kpi-name { text-align:left; max-width:220px; overflow:hidden; text-overflow:ellipsis; cursor:help; }
        table.kpi-table tbody tr:nth-child(even) { background:#FBF6EC; }
        table.kpi-table tbody tr:hover { background:#FBF0DD; }
        td.kpi-cell { cursor:pointer; min-width:46px; position:relative; }
        td.kpi-cell:hover { outline:2px solid #F0A24B; outline-offset:-2px; }
        .kpi-below { color:#DD5138; font-weight:bold; }
        .kpi-na { color:#b0a390; }
        .kpi-none { color:#c4863a; }
        .kpi-preview { color:#F0A24B; font-style:italic; }
        .kpi-ov-mark { color:#DD5138; font-size:10px; vertical-align:super; }
        .kpi-attach-badge { display:inline-flex; align-items:center; gap:2px; font-size:10px; line-height:14px;
            height:14px; background:#F7E0BD; color:#8A5A2B; border:1px solid #E8D5B5; border-radius:7px;
            padding:0 5px; margin-left:3px; vertical-align:middle; font-weight:normal; }
        .kpi-attach-badge i { font-size:9px; }
        .kpi-attach-badge:hover { background:#F0A24B; color:#fff; border-color:#d98a33; }
        .kpi-avg { background:#FDF3E0; font-weight:bold; }
        /* 補登模式：整張表變成可直接填寫的格子 */
        #fillBar { margin:6px 0 0; padding:6px 10px; border:1px solid #D8BE93; background:#FBF5EA;
            color:#5b3a1e; font-size:13px; border-radius:4px; }
        #fillBar input[type=text] { height:26px; border:1px solid #D8BE93; border-radius:4px; padding:0 8px;
            font-size:12px; width:260px; margin-left:8px; }
        #fillBar .fb-n { margin-left:10px; color:#8a6d45; }
        #fillBar button { height:26px; padding:0 12px; border-radius:4px; font-size:12px; margin-left:6px;
            border:1px solid #D8BE93; background:#fff; color:#5b3a1e; cursor:pointer; }
        #fillBar button.warm { background:#F0A24B; color:#fff; border-color:#d98a33; }
        #btnFill.on { background:#F0A24B; color:#fff; border-color:#d98a33; }
        table.kpi-table td .fillIn { width:100%; min-width:44px; box-sizing:border-box; height:22px; font-size:12px;
            text-align:center; border:1px solid #E8D5B5; border-radius:3px; padding:0 2px; background:#fff; color:#5b3a1e; }
        table.kpi-table td .fillIn:focus { border-color:#F0A24B; outline:none; background:#FFFBF3; }
        table.kpi-table td .fillIn.ov { background:#FDF3E3; }
        table.kpi-table td .fillIn.dirty { border-color:#C2601C; background:#FBEBD6; font-weight:bold; }
        /* 儲存格彈出選單 */
        #cellMenu { position:absolute; z-index:1000; background:#fff; border:1px solid #D8BE93; border-radius:6px;
            box-shadow:0 3px 10px rgba(90,58,30,.25); min-width:170px; display:none; }
        #cellMenu .cm-head { background:#F7E0BD; color:#5b3a1e; font-size:12px; padding:5px 10px;
            border-radius:6px 6px 0 0; font-weight:bold; }
        #cellMenu .cm-item { padding:6px 12px; font-size:13px; color:#5b3a1e; cursor:pointer; }
        #cellMenu .cm-item:hover { background:#FBF0DD; }
        #cellMenu .cm-item.disabled { color:#c9bda9; cursor:not-allowed; }
        #cellMenu .cm-info { padding:4px 12px; font-size:11px; color:#8a6d45; border-top:1px dashed #EADFC8; }
        /* 試算列 */
        .kpi-sim-bar { background:#FFF7E8; border:1px dashed #F0A24B; border-radius:6px; padding:6px 10px;
            margin:4px 0; font-size:12px; display:flex; flex-wrap:wrap; gap:8px; align-items:center; }
        .kpi-sim-bar input { width:110px; height:24px; font-size:12px; border:1px solid #D8BE93; border-radius:3px; padding:0 5px; }
        .kpi-sim-bar button { height:24px; font-size:12px; border:1px solid #d98a33; background:#F0A24B; color:#fff;
            border-radius:3px; cursor:pointer; padding:0 10px; }
        /* modal 共用 */
        .kpi-modal-mask { display:none; position:fixed; inset:0; background:rgba(60,40,20,.45); z-index:1050; }
        .kpi-modal { background:#fff; border-radius:8px; max-width:560px; margin:60px auto; padding:0;
            box-shadow:0 5px 25px rgba(0,0,0,.3); max-height:82vh; display:flex; flex-direction:column; }
        .kpi-modal .m-head { background:#F7E0BD; color:#5b3a1e; font-weight:bold; padding:10px 15px;
            border-radius:8px 8px 0 0; display:flex; justify-content:space-between; }
        .kpi-modal .m-head .m-close { cursor:pointer; color:#b5762a; }
        .kpi-modal .m-body { padding:15px; overflow-y:auto; }
        .kpi-modal .m-body label { display:block; font-size:13px; color:#5b3a1e; margin:8px 0 3px; }
        .kpi-modal .m-body input[type=text], .kpi-modal .m-body input[type=number],
        .kpi-modal .m-body select, .kpi-modal .m-body textarea { width:100%; border:1px solid #D8BE93;
            border-radius:4px; padding:5px 8px; font-size:13px; box-sizing:border-box; }
        .kpi-modal .m-foot { padding:10px 15px; border-top:1px solid #EADFC8; text-align:right; }
        .kpi-modal .m-foot button { height:30px; padding:0 16px; border-radius:4px; font-size:13px;
            border:1px solid #d98a33; cursor:pointer; }
        .kpi-modal .m-foot .b-ok { background:#F0A24B; color:#fff; }
        .kpi-modal .m-foot .b-cancel { background:#fff; color:#5b3a1e; border-color:#D8BE93; margin-right:6px; }
        input[type=number]::-webkit-outer-spin-button, input[type=number]::-webkit-inner-spin-button
            { -webkit-appearance:none; margin:0; }
        input[type=number] { -moz-appearance:textfield; }
        /* 不符合標準的明細跳窗 */
        .kpi-modal.vio-modal { max-width:1180px; }
        .vio-head { font-size:14px; color:#5b3a1e; padding-bottom:6px; border-bottom:1px solid #EADFC8; }
        .vio-mode { display:inline-block; font-size:12px; border-radius:10px; padding:1px 9px; margin-left:4px; }
        .vio-mode.deny { background:#F7E0BD; color:#8A5A2B; border:1px solid #E8D5B5; }
        .vio-mode.allow { background:#EFE3C8; color:#6b4a20; border:1px solid #D8BE93; }
        .vio-mode.na { background:#F1ECE3; color:#a08356; border:1px solid #E3D9C7; }
        .vio-note { font-size:12px; color:#8a6d45; margin:8px 0; }
        .vio-warn { font-size:12px; color:#8A5A2B; background:#FDF3E3; border:1px solid #F0A24B;
            border-radius:4px; padding:6px 10px; margin:6px 0; }
        .vio-warn.ok { border-color:#D8BE93; background:#FBF5EA; }
        /* 明細表格：一律不出現左右捲軸（使用者要求 2026-09-17「過長文字自動換行，不要有左右移動拉桿」）。
           作法＝表格寬度釘 100%＋table-layout:fixed，長字串(料號/製令/備註)用 word-break 斷行。 */
        .vio-tblwrap { max-height:44vh; overflow-y:auto; overflow-x:hidden; border:1px solid #EADFC8;
            border-radius:4px; margin-top:8px; }
        table.vio-tbl { width:100%; table-layout:fixed; border-collapse:collapse; font-size:12px; }
        table.vio-tbl th { position:sticky; top:0; background:#F7E0BD; color:#5b3a1e; padding:5px 7px;
            text-align:left; z-index:1; white-space:normal; word-break:break-word; }
        table.vio-tbl td { padding:4px 7px; border-top:1px solid #F3EADA; color:#5b3a1e; vertical-align:top;
            white-space:normal; overflow-wrap:anywhere; word-break:break-word;
            -webkit-user-select:text; user-select:text; cursor:text; }
        table.vio-tbl td:first-child { cursor:default; }
        table.vio-tbl tr.ex td { background:#F5F1E8; color:#a08356; }
        table.vio-tbl tr.rex td { background:#F3EFE6; color:#a08356; }
        table.vio-tbl tr.info td { background:#fff; color:#8a6d45; }
        table.vio-tbl tr.sel td { background:#FBE6C8; }
        table.vio-tbl .vio-why { color:#C2601C; }
        table.vio-tbl .vio-fix { color:#7a6046; }
        table.vio-tbl .vio-ex { color:#8A5A2B; margin-top:2px; font-size:11px; }
        table.vio-tbl tr.warn td { background:#FBF7EF; }
        table.vio-tbl tr.warn .vio-why { color:#8a6d45; }
        table.vio-tbl tr.info .vio-why { color:#a08356; }
        table.vio-tbl .kind-tag { display:inline-block; font-size:10px; line-height:15px; padding:0 5px;
            border-radius:8px; margin-right:4px; vertical-align:1px; }
        table.vio-tbl .kind-tag.warn { background:#F7E0BD; color:#8A5A2B; }
        table.vio-tbl .kind-tag.info { background:#F1ECE3; color:#a08356; }
        table.vio-tbl .kind-tag.rex  { background:#EFE3C8; color:#6b4a20; }
        /* 篩選列與排除規則 */
        .vio-filter { display:flex; flex-wrap:wrap; gap:6px 10px; align-items:center; margin:8px 0 2px;
            padding:6px 8px; background:#FBF5EA; border:1px solid #EADFC8; border-radius:4px; font-size:12px; }
        .vio-filter label { margin:0; font-weight:normal; color:#8a6d45; }
        .vio-filter select, .vio-filter input[type=text] { height:26px; border:1px solid #D8BE93;
            border-radius:4px; font-size:12px; color:#5b3a1e; background:#fff; padding:0 4px; max-width:190px; }
        .vio-filter .vf-count { color:#8A5A2B; margin-left:auto; }
        .vio-filter .vf-clear { cursor:pointer; color:#b5762a; text-decoration:underline; }
        .vio-rules { font-size:12px; color:#8a6d45; margin:6px 0; padding:6px 8px;
            background:#FDF8EF; border:1px solid #EADFC8; border-radius:4px; }
        .vio-rules .vr-chip { display:inline-block; background:#F7E0BD; color:#8A5A2B; border:1px solid #E8D5B5;
            border-radius:10px; padding:1px 8px; margin:2px 4px 2px 0; }
        .vio-rules .vr-chip .vr-x { cursor:pointer; color:#DD5138; margin-left:4px; font-weight:bold; }
        .vio-rules .vr-chip.fixed { background:#F1ECE3; color:#8a6d45; border-color:#E3D9C7; }
        .vio-rules .vr-chip.allyr { background:#F0A24B; color:#fff; border-color:#d98a33; }
        .vio-rules .vr-chip.allyr .vr-by { color:#FBEBD6 !important; }
        .vio-rules .vr-chip .vr-scope { font-size:10px; margin-left:5px; padding:0 5px;
            border-radius:8px; background:rgba(255,255,255,.55); color:#6b4a20; }
        .vio-rules .vr-chip .vr-by { color:#a08356; font-size:11px; margin-left:5px; }
        .vio-note .vio-exinfo { color:#a08356; margin-left:6px; }
        .vr-list .vr-it.dis { color:#a89a86; cursor:default; background:#F7F4EE; }
        .vr-list .vr-it.dis:hover { background:#F7F4EE; }
        .vio-seltip { font-size:11px; color:#a08356; margin-top:4px; }
        .vio-rules .vr-new { margin-top:6px; display:flex; flex-wrap:wrap; gap:6px; align-items:center; }
        .vio-rules .vr-new select, .vio-rules .vr-new input[type=text] { height:26px; border:1px solid #D8BE93;
            border-radius:4px; font-size:12px; color:#5b3a1e; background:#fff; padding:0 6px; }
        .vio-rules .vr-new input[type=text] { width:290px; }
        .vr-list { margin-top:5px; max-height:150px; overflow-y:auto; overflow-x:hidden;
            border:1px solid #EADFC8; border-radius:4px; background:#fff; }
        .vr-list .vr-it { padding:3px 8px; font-size:12px; color:#5b3a1e; cursor:pointer;
            border-bottom:1px solid #F5EFE3; overflow-wrap:anywhere; }
        .vr-list .vr-it:hover { background:#FBE6C8; }
        .vr-list .vr-it.on { background:#F7E0BD; color:#8A5A2B; }
        .vr-list .vr-it .vr-id { color:#a08356; font-size:11px; margin-left:6px; }
        .vr-list .vr-it .vr-src { float:right; font-size:10px; color:#8A5A2B; background:#F7E0BD;
            border-radius:8px; padding:0 6px; margin-left:6px; }
        .vr-list .vr-none a { color:#b5762a; text-decoration:underline; }
        .vr-list .vr-none { padding:6px 8px; font-size:12px; color:#a08356; }
        .vr-picked { margin-top:5px; font-size:12px; color:#8a6d45; }
        .vr-picked .vr-chip2 { display:inline-block; background:#EFE3C8; color:#6b4a20; border:1px solid #D8BE93;
            border-radius:10px; padding:1px 8px; margin:2px 4px 2px 0; }
        .vr-picked .vr-chip2 .vr-x2 { cursor:pointer; color:#DD5138; margin-left:5px; font-weight:bold; }
        table.vio-tbl .vio-edit { white-space:normal; }
        table.vio-tbl .ve-row { display:flex; align-items:center; gap:3px; margin:1px 0; }
        table.vio-tbl .ve-lb { font-size:10px; color:#8a6d45; width:54px; flex:0 0 54px; cursor:help;
            overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        table.vio-tbl .veF { height:24px; font-size:11px; border:1px solid #D8BE93; border-radius:3px;
            padding:0 3px; background:#fff; color:#5b3a1e; min-width:0; flex:1 1 auto; max-width:118px; }
        table.vio-tbl select.veF { max-width:118px; }
        #vioFoot button { height:30px; padding:0 14px; border-radius:4px; font-size:13px; margin-left:6px;
            border:1px solid #D8BE93; background:#fff; color:#5b3a1e; cursor:pointer; }
        #vioFoot button.warm { background:#F0A24B; color:#fff; border-color:#d98a33; }
        #vioFoot input[type=text] { height:28px; border:1px solid #D8BE93; border-radius:4px; padding:0 8px; font-size:13px; }
        #vioFoot .vio-set { margin-left:14px; font-size:12px; color:#8a6d45; }
        #vioFoot .vio-print { margin-left:14px; font-size:12px; color:#8a6d45; }
        #vioFoot .vio-savetip { font-size:11px; color:#8A5A2B; background:#FBF5EA; border:1px solid #EADFC8;
            border-radius:4px; padding:4px 8px; margin-top:6px; line-height:1.6; }
        #vioToast { display:none; position:fixed; left:50%; bottom:40px; transform:translateX(-50%);
            background:#5b3a1e; color:#fff; font-size:13px; padding:8px 16px; border-radius:20px;
            z-index:20000; box-shadow:0 2px 10px rgba(0,0,0,.25); max-width:80vw; }
        #vioFoot .vio-print select { height:28px; border:1px solid #D8BE93; border-radius:4px; font-size:12px; }
        #vioFoot .vio-set select { height:28px; border:1px solid #D8BE93; border-radius:4px; font-size:12px; }
        .att-row { display:flex; gap:8px; align-items:center; border-bottom:1px dashed #EADFC8; padding:6px 0; font-size:13px; }
        .att-row .att-name { color:#b5762a; cursor:pointer; text-decoration:underline; flex:1;
            overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .att-row .att-note { color:#8a6d45; max-width:150px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .att-row .att-del { color:#DD5138; cursor:pointer; }
        .att-missing { color:#c9bda9; text-decoration:line-through; }
        #chartBox { border:1.5px solid #E8D5B5; border-radius:8px; padding:10px; margin-top:12px; background:#fff; }
        #chartPicks { display:flex; flex-wrap:wrap; gap:4px 12px; font-size:12px; color:#5b3a1e; margin-bottom:6px; }
        #chartPicks label { cursor:pointer; margin:0; font-weight:normal; }
        .kpi-noperm { margin:40px auto; max-width:520px; text-align:center; border:1.5px solid #E8D5B5;
            border-radius:10px; padding:30px; background:#FDF8EF; color:#5b3a1e; }
        .kpi-print-head { display:none; text-align:center; }
        .kpi-print-comp { font-size:20px; font-weight:bold; }
        .kpi-print-title { font-size:15px; font-weight:bold; letter-spacing:3px; margin-top:2px; }
        .kpi-print-sub { font-size:11px; color:#555; margin-top:2px; }
        /* 快照過期提示：來源資料在結算之後又異動過 → 已自動重算（暖色系 ai-rules/10） */
        #staleBar { display:none; margin:6px 0 0; padding:6px 10px; border:1px solid #F0A24B;
                    background:#FDF3E3; color:#5b3a1e; font-size:13px; border-radius:4px; }
        #staleBar b { color:#C2601C; }
        #staleBar .sb-link { color:#C2601C; text-decoration:underline; cursor:pointer; margin-left:8px; }
        /* 過期標記畫在儲存格右上角的小三角，絕對定位＝完全不佔寬度（使用者 2026-09-21 回報）。
           原本是 inline 的 ⟳ 小籤，一次標到十幾格時每個月份欄都被撐寬約 23px、整張表多出近 270px，
           表格底下就冒出一條橫向捲軸，畫面還會跟著跳一下。說明文字改掛在 td 的 title 上，
           滑鼠移到那格的任何位置都看得到，不必去點那個小三角。 */
        td.kpi-cell.kpi-stale::after, .kpi-stale-mark::after {
            content:''; position:absolute; top:0; right:0; width:0; height:0;
            border-style:solid; border-width:0 7px 7px 0;
            border-color:transparent #C2601C transparent transparent; }
        .kpi-stale-mark { position:relative; display:inline-block; width:14px; height:14px;
            border:1px solid #EADFC8; background:#fff; vertical-align:middle; }
        .kpi-src-links a { color:#C2601C; }
        .kpi-src-links .noperm { color:#999; }
        /* 兩份規則刻意重複：@media print 是保險（萬一使用者直接 Ctrl+P 未走 printKpi()）；
           body.kpi-printing 是 printKpi() 按下當下同步套用，讓縮放量測時的版面跟真正列印時一致（見下方 printKpi()） */
        @media print {
            .page-title, .kpi-toolbar, #chartBox, #cellMenu, .nav_menu, .left_col, .kpi-sim-bar, footer,
            .kpi-role-badge .fa-question-circle, .kpi-ov-mark, .kpi-legend,
            #staleBar, .kpi-stale-mark, td.kpi-cell.kpi-stale::after, .kpi-modal-mask { display:none !important; }
            .right_col { margin:0 !important; padding:0 !important;
                min-height:0 !important; height:auto !important; }
            html, body, .container, .container.body, .main_container {
                min-height:0 !important; height:auto !important; }
            /* 列印時每一欄都照內容決定寬度（width:auto），不要用 width:100% 硬撐滿整頁——
               撐滿會讓指標內容欄灌水到 239px（最長文字只用得到 77px、右側全是空白），
               月份欄也被拉到 100px。表格變窄之後由 printKpi() 的縮放把它放大到貼齊紙張，
               字反而更大（實測 zoom 0.52 → 1.5 以上）。 */
            table.kpi-table { font-size:10px; width:auto; margin:0 auto; }
            table.kpi-table th, table.kpi-table td { padding:2px 4px; }
            table.kpi-table td.kpi-name { max-width:none; overflow:visible; text-overflow:clip; }
            .kpi-table-wrap { overflow:visible; border:none; max-height:none !important; min-height:0 !important; }
            table.kpi-table thead th { position:static; }
            .kpi-print-head { display:block !important; }
        }
        body.kpi-printing .page-title, body.kpi-printing .kpi-toolbar, body.kpi-printing #chartBox, body.kpi-printing #cellMenu,
        body.kpi-printing .nav_menu, body.kpi-printing .left_col, body.kpi-printing .kpi-sim-bar,
        body.kpi-printing footer, body.kpi-printing .kpi-role-badge .fa-question-circle,
        body.kpi-printing .kpi-ov-mark, body.kpi-printing .kpi-legend,
        body.kpi-printing #staleBar, body.kpi-printing .kpi-stale-mark,
        body.kpi-printing td.kpi-cell.kpi-stale::after,
        body.kpi-printing .kpi-modal-mask { display:none !important; }
        /* Gentelella 的 .right_col／container 有 min-height 撐著（實測內容只有幾百 px 卻量到 1296px），
           不歸零的話列印時會多出一整頁幾乎空白的第二頁——這就是使用者說的「列印超過邊界」。 */
        body.kpi-printing .right_col { margin:0 !important; padding:0 !important;
            min-height:0 !important; height:auto !important; }
        body.kpi-printing, body.kpi-printing html, body.kpi-printing .container,
        body.kpi-printing .container.body, body.kpi-printing .main_container,
        body.kpi-printing .nav-md .container.body .right_col {
            min-height:0 !important; height:auto !important; }
        body.kpi-printing table.kpi-table { font-size:10px; width:auto; margin:0 auto; }
        body.kpi-printing table.kpi-table th, body.kpi-printing table.kpi-table td { padding:2px 4px; }
        body.kpi-printing table.kpi-table td.kpi-name { max-width:none; overflow:visible; text-overflow:clip; }
        body.kpi-printing .kpi-table-wrap { overflow:visible; border:none; max-height:none !important; min-height:0 !important; }
        body.kpi-printing table.kpi-table thead th { position:static; }
        body.kpi-printing .kpi-print-head { display:block !important; }
    </style>
</head>
<body class="nav-sm">
<div class="container body">
<div class="main_container">
    <?php include '../partPage/sideAndTopBarMenu.html' ?>
    <div class="right_col" role="main">
        <div class="page-title" style="display:flex;align-items:center;flex-wrap:wrap;">
            <h2 style="margin:6px 0;">KPI 關鍵績效指標 <small style="color:#8a6d45;">2-GM-04-01（每月10號前完成填寫）</small></h2>
            <button type="button" class="page-help-btn" id="btnPageHelp" title="這一頁怎麼用">
                <i class="fa fa-question-circle"></i> 使用說明</button>
        </div>
        <div class="clearfix"></div>

<?php if (!$kpiPerms['canView']): ?>
        <div class="kpi-noperm">
            <h4><i class="fa fa-lock"></i> 無 KPI 檢閱權限</h4>
            <p>請洽管理者於「使用者權限設定」指派 KPI 角色，<br>或於 KPI 設定頁建立部門×主管階級授權規則。</p>
        </div>
<?php else: ?>
        <div class="kpi-toolbar">
            <label style="margin:0;font-size:13px;color:#5b3a1e;">年度</label>
            <select id="yearSel"></select>
            <button id="btnRecalcYear" title="重新計算本年度所有自動指標(已結束月份)" style="display:none;">
                <i class="fa fa-refresh"></i> 重算本年</button>
            <button id="btnFill" style="display:none;" onclick="toggleFill()" title="整張表直接填寫（補舊年度資料用，不需逐格填原因）">
                <i class="fa fa-table"></i> 補登模式</button>
            <button id="btnUpload"><i class="fa fa-paperclip"></i> 上傳佐證</button>
            <button id="btnCsv"><i class="fa fa-file-text-o"></i> 匯出CSV</button>
            <label style="margin:0;font-size:13px;color:#5b3a1e;">紙張</label>
            <select id="kpiPaperSel"><option value="A4">A4</option><option value="A3">A3</option></select>
            <button onclick="printKpi()"><i class="fa fa-print"></i> 列印</button>
            <a class="btn" id="btnSetting" href="KPI_setting.php" style="display:none;line-height:28px;">
                <i class="fa fa-gear"></i> 設定</a>
            <span class="kpi-role-badge">目前角色：<b><?= htmlspecialchars($roleLabel) ?></b>
                <i class="fa fa-question-circle" id="btnRoleHelp" title="角色權限說明"></i></span>
        </div>

        <div id="staleBar"></div>
        <div id="fillBar" style="display:none;">
            <b>補登模式</b>：直接在格子裡打字（<b>Enter 或 Tab＝往右一格</b>、<b>↑↓＝上下移動</b>），填完按「儲存全部」。
            空白＝清除該格的覆寫。<span style="color:#8a6d45;">未到期或該指標不適用的月份不給填；
            <b>Yes/No 指標</b>那幾列是下拉（游標停在上面直接按 <b>Y</b>／<b>N</b> 也選得到）。</span>
            <input type="text" id="fillNote" maxlength="200" placeholder="整批說明（選填，例：依 2025 紙本補登）">
            <span class="fb-n">未儲存 <b id="fillCount">0</b> 格</span>
            <button class="warm" onclick="fillSave()"><i class="fa fa-save"></i> 儲存全部</button>
            <button onclick="toggleFill()">離開補登模式</button>
        </div>

        <div class="kpi-print-head" id="kpiPrintHead">
            <div class="kpi-print-comp"></div>
            <div class="kpi-print-title"></div>
            <div class="kpi-print-sub"></div>
        </div>

        <div class="kpi-table-wrap">
            <table class="kpi-table" id="kpiTable">
                <thead>
                    <tr id="kpiHeadRow">
                        <th>項次</th><th>指標內容</th><th>擔當者</th><th>頻率</th><th>判定目標</th>
                        <th>1月</th><th>2月</th><th>3月</th><th>4月</th><th>5月</th><th>6月</th>
                        <th>7月</th><th>8月</th><th>9月</th><th>10月</th><th>11月</th><th>12月</th>
                        <th>平均</th><th>去年平均</th>
                    </tr>
                </thead>
                <tbody id="kpiBody"><tr><td colspan="19" style="padding:20px;color:#8a6d45;">載入中…</td></tr></tbody>
            </table>
        </div>
        <div class="kpi-legend" style="font-size:11px;color:#8a6d45;margin-top:4px;">
            說明：<span class="kpi-preview">橘色斜體</span>=當月即時試算(未定案)；<span class="kpi-below">紅字</span>=未達標；
            <span class="kpi-ov-mark">✱</span>=手動覆寫；<span class="kpi-attach-badge"><i class="fa fa-paperclip"></i>n</span>=佐證附件；<span class="kpi-stale-mark"></span>=格子右上角小三角，快照過期已自動重算（滑鼠移上去看原值）；?=無資料；NA=未到期；－=本期已填在其他月份。
            點儲存格可操作（明細/附件/填寫/覆寫/重算）；每季/每半年/每年的手動指標一期只能擇一月份填寫，如需改填其他月份請先清除。
        </div>

        <div id="chartBox">
            <div style="font-weight:bold;color:#5b3a1e;margin-bottom:4px;"><i class="fa fa-line-chart"></i> 趨勢圖</div>
            <div id="chartPicks"></div>
            <div id="kpiChart" style="height:320px;"></div>
        </div>
<?php endif; ?>
    </div>
    <?php include '../partPage/footer.html' ?>
</div>
</div>

<!-- 儲存格彈出選單 -->
<div id="cellMenu"></div>

<!-- 填寫 modal -->
<div class="kpi-modal-mask" id="fillMask"><div class="kpi-modal">
    <div class="m-head"><span id="fillTitle">填寫</span><span class="m-close" onclick="closeMask('fillMask')">✕</span></div>
    <div class="m-body">
        <div id="fillYesNoBox" style="display:none;">
            <label>結果</label>
            <select id="fillYesNo"><option value="1">Yes（期限內完成）</option><option value="0">No（未完成）</option></select>
        </div>
        <div id="fillNumBox">
            <label id="fillValueLabel">數值</label>
            <input type="number" id="fillValue" step="any">
        </div>
        <label>備註（選填）</label>
        <input type="text" id="fillNote" maxlength="200">
    </div>
    <div class="m-foot">
        <button class="b-cancel" onclick="closeMask('fillMask')">取消</button>
        <button class="b-ok" onclick="submitFill()">儲存</button>
    </div>
</div></div>

<!-- 覆寫 modal -->
<div class="kpi-modal-mask" id="ovMask"><div class="kpi-modal">
    <div class="m-head"><span id="ovTitle">手動覆寫</span><span class="m-close" onclick="closeMask('ovMask')">✕</span></div>
    <div class="m-body">
        <div style="font-size:12px;color:#8a6d45;" id="ovOrigInfo"></div>
        <div id="ovYesNoBox" style="display:none;">
            <label>覆寫值</label>
            <select id="ovYesNo"><option value="1">Yes（期限內完成）</option><option value="0">No（未完成）</option></select>
        </div>
        <div id="ovNumBox">
            <label>覆寫值</label>
            <input type="number" id="ovValue" step="any">
        </div>
        <label>覆寫原因（必填，寫入變更歷史）</label>
        <input type="text" id="ovReason" maxlength="200">
    </div>
    <div class="m-foot">
        <button class="b-cancel" onclick="closeMask('ovMask')">取消</button>
        <button class="b-ok" onclick="submitOverride()">覆寫</button>
    </div>
</div></div>

<!-- 附件 modal -->
<div class="kpi-modal-mask" id="attMask"><div class="kpi-modal">
    <div class="m-head"><span id="attTitle">佐證附件</span><span class="m-close" onclick="closeMask('attMask')">✕</span></div>
    <div class="m-body">
        <div id="attPickBox" style="display:none;border-bottom:1px solid #EADFC8;padding-bottom:8px;margin-bottom:8px;">
            <label>KPI 指標（僅列出您可填寫的項目）</label>
            <select id="attIndSel"></select>
            <label>月份</label>
            <select id="attMonthSel"></select>
        </div>
        <div id="attList" style="min-height:40px;"></div>
        <div id="attUpBox" style="margin-top:10px;border-top:1px dashed #EADFC8;padding-top:8px;">
            <label>新增附件（<span id="attLimitTxt"></span>）</label>
            <input type="file" id="attFile" multiple
                   accept=".jpg,.jpeg,.png,.gif,.webp,.bmp,.pdf,.xls,.xlsx,.xlsm,.xlsb,.doc,.docx,.docm,.ppt,.pptx,.csv,.txt,.zip,.7z,.rar,.odt,.ods">
            <div style="font-size:11px;color:#8a6d45;margin-top:2px;">可一次選多個檔案（Ctrl／Shift 多選）；支援 Excel（含 xlsm 巨集檔）、Word、PDF、圖片、壓縮檔，單檔 20MB。</div>
            <label>附件說明（選填）</label>
            <input type="text" id="attNote" maxlength="200" placeholder="例：6月客訴統計表、盤點紀錄掃描檔…">
            <div style="text-align:right;margin-top:6px;">
                <button class="b-ok" style="height:28px;padding:0 14px;border:1px solid #d98a33;background:#F0A24B;color:#fff;border-radius:4px;cursor:pointer;"
                    onclick="submitAttach()">上傳</button>
            </div>
        </div>
    </div>
</div></div>

<!-- 明細 modal -->
<div class="kpi-modal-mask" id="dtMask"><div class="kpi-modal">
    <div class="m-head"><span id="dtTitle">數值明細</span><span class="m-close" onclick="closeMask('dtMask')">✕</span></div>
    <div class="m-body" id="dtBody" style="font-size:13px;color:#5b3a1e;"></div>
</div></div>

<!-- 不符合標準的明細 modal（只列超過規定的那幾筆＋修改建議；列印不含此內容） -->
<div class="kpi-modal-mask" id="vioMask"><div class="kpi-modal vio-modal">
    <div class="m-head"><span id="vioTitle">不符合標準的明細</span><span class="m-close" onclick="closeMask('vioMask')">✕</span></div>
    <div class="m-body" id="vioBody" style="font-size:13px;color:#5b3a1e;"></div>
    <div class="m-foot" id="vioFoot" style="text-align:left;"></div>
</div></div>

<!-- 使用說明 modal（鐵律7） -->
<div class="kpi-modal-mask" id="helpUseMask"><div class="kpi-modal" style="max-width:860px;">
    <div class="m-head"><span>KPI 關鍵績效指標　使用說明</span><span class="m-close" onclick="closeMask('helpUseMask')">✕</span></div>
    <div class="m-body help-doc">
        <h4>這一頁在做什麼</h4>
        本頁是 AS9100 的「關鍵績效指標（2-GM-04-01）」年度總表：21 項指標 × 12 個月。
        每一格的值有四種來源——<b>自動計算</b>（由系統直接算來源模組的資料）、<b>手動填寫</b>、
        <b>手動覆寫</b>（蓋掉自動值，要填原因）、以及當月還沒結束時的<b>即時試算</b>。
        已結束的月份會結算成「快照」；來源資料事後又被改過時，畫面上方會出現提示並自動重算（該格標 ⟳）。

        <h4>操作步驟</h4>
        <ul>
            <li>先在工具列選<b>年度</b>（預設今年）。</li>
            <li><b>點任何一格</b>都會跳出選單：數值明細／佐證附件／不符合標準的明細／前往來源頁面／填寫修改／重算／手動覆寫。</li>
            <li>要補舊年度整張表時用<b>補登模式</b>（僅系統管理員），像 Excel 一樣直接打，不必逐格填原因。
                判定目標寫 <b>Yes/No</b> 的指標那幾列是下拉（直接按 Y／N 也選得到）；逐格填寫與手動覆寫的跳窗同樣是 Yes/No 選單。</li>
            <li>要看趨勢請勾下方<b>趨勢圖</b>的指標；要留存請用<b>匯出CSV</b>或列印（A4／A3 自動縮成一頁）。</li>
        </ul>

        <h4>「不符合標準的明細」怎麼用</h4>
        這個跳窗<b>只列沒有達到標準的那幾筆</b>，並附上每一筆「為什麼不符合」與「建議怎麼處理」。
        <ul>
            <li><b>篩選</b>：上方會依這份資料實際有的欄位長出客戶／料號／製程／廠商／機台／設計者等下拉，
                可再加關鍵字；有些指標（金額類、產能類）會連正常資料一起列出來方便核對，
                預設收起來，切成「全部」才看得到。</li>
            <li><b>一次選很多筆</b>：在表格上按住滑鼠左鍵往下<b>拖曳</b>即可連續勾選，不必一個一個點。</li>
            <li><b>排除這一筆</b>（逐筆）：勾選後填原因按「排除選取」——<b>只影響這一個月的 KPI 計算，
                不會修改任何一筆真實資料</b>，而且誰排的、什麼時候排的、原因都會留下來。</li>
            <li><b>排除規則</b>（整批）：把某個客戶／製程／廠商／料號整年度排除在這個指標之外，
                建立後該年度每一個月都會立刻重算；被規則排掉的列仍然會列出來並標成「規則排除」，
                隨時可以按 × 取消。</li>
            <li><b>直接修改來源資料</b>：只有管理員設定為「可直接修改」的指標才會出現最右邊那一欄，
                改完立刻重算並留下紀錄。<b>只有登錄錯誤才改</b>，確實不符合標準的請保持原樣。</li>
        </ul>

        <h4>重要行為／常見疑問</h4>
        <ul>
            <li><b>廠商準時交貨率沒登錄回廠日怎麼算？</b> 依序用「下一製程發包日 → QC檢驗日 → 出貨日 → 製令結案日」
                推估回廠日（明細上會標「推估」），四個都查不到才算真的未回廠。
                推估只用於判定，<b>不會寫回任何一筆資料</b>。</li>
            <li><b>準時出貨率的明細筆數比分母還多？</b> 分母取自訂單追蹤、未交量取自 ERP 未交清單，
                後者會累積更早月份還沒結清的訂單，說明列會寫出兩邊的筆數。</li>
            <li><b>隔年 2/1 起</b>舊年度會鎖定，重算／覆寫／補填只剩 KPI 管理者能做。</li>
            <li>明細一次最多顯示 500 筆（不符合標準的會排在最前面，不會被切掉）。</li>
        </ul>

        <h4>設定入口</h4>
        <ul>
            <li><b>KPI 設定頁</b>（views/news/KPI_setting.php）：指標、擔當者、判定目標、計算模組與參數、各月目標金額、AS 文件編號綁定。</li>
            <li><b>管理員設定</b>（明細跳窗右下角）：這個指標的來源資料可不可以直接修改。</li>
        </ul>

        <h4>權限角色</h4>
        <ul>
            <li><b>KPI檢閱</b>：看總覽、趨勢圖、附件與試算。</li>
            <li><b>KPI填報</b>：填寫／覆寫／重算／排除，並上傳佐證附件。</li>
            <li><b>KPI管理員</b>：以上全部，另可改設定、補登、鎖定年度後仍可調整。</li>
            <li>指標的<b>擔當者本人</b>（與其請假代理人）本來就能填自己那一列。</li>
        </ul>
    </div>
</div></div>

<!-- 角色說明 modal -->
<div class="kpi-modal-mask" id="helpMask"><div class="kpi-modal">
    <div class="m-head"><span>KPI 角色權限說明</span><span class="m-close" onclick="closeMask('helpMask')">✕</span></div>
    <div class="m-body" style="font-size:13px;color:#5b3a1e;line-height:1.8;">
        <b>KPI檢閱</b>：可看KPI總覽、趨勢圖、附件清單與開啟附件；可用「試算」預覽開放參數（不影響正式數值）。<br>
        <b>KPI填報</b>：檢閱＋可重算自動指標(當年度)。<br>
        <b>KPI管理員</b>：填報＋舊年度重算/補填/覆寫、KPI設定頁(指標/公式參數/目標/權限規則/NAS路徑)。<br>
        <b>管理者</b>：系統管理者固定擁有全部權限。<br>
        <b>手動指標填寫／手動覆寫／上傳佐證附件</b>：不分角色，一律僅該指標「擔當者」本人、或擔當者今天請假時系統解析出的代理人可操作(覆寫需填原因)；管理者不受此限。<br>
        <hr style="border-color:#EADFC8;">
        授權方式（聯集）：①權限設定頁指派 KPI 角色(個人/職稱)；②KPI設定頁建立「部門×主管階級」規則或指定人員為管理者。<br>
        年度鎖定：隔年 2/1 起該年度重算/補填/覆寫僅 KPI 管理員可操作，其他人僅能檢視快照。
    </div>
</div></div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../code/highcharts.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script src="../../resource/js/eg_print_log.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_print_log.js') ?>"></script>
<script src="../../resource/js/eg_date_fmt.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_date_fmt.js') ?>"></script>
<script>
$(document).ready(function(){
    var $activeMenu = $('#sidebar-menu .nav.side-menu > li.active');
    if ($activeMenu.length) {
        $activeMenu.removeClass('active').find('ul.child_menu').hide();
        $activeMenu.find('li.current-page').removeClass('current-page');
    }
    $('#sidebar-menu').css('visibility', 'visible');
});

var API = '../../src/store/KpiAs_API.php';
var META = null, MATRIX = null, YEAR = null;
var STALE = {}, STALE_INFO = null;   // 快照過期：{indicator_id:{month:{old,new,...}}}
var canView = <?= $kpiPerms['canView'] ? 'true' : 'false' ?>;
/* Yes/No 指標可以接受的寫法：由後端的 kpi_as_yesno_tokens() 輸出，前端不另外抄一份，
   所以「畫面收得進去、後端卻存不了」這種事不會發生（後端仍會用同一份再擋一次）。 */
var YESNO = <?= json_encode(kpi_as_yesno_tokens(), JSON_UNESCAPED_UNICODE) ?>;
/** 回傳 1／0／null（null＝這個值不合法） */
function kpiParseInput(type, raw){
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

/* ---------- 共用 ---------- */
function closeMask(id){ document.getElementById(id).style.display='none'; }
function openMask(id){ document.getElementById(id).style.display='block'; }
function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g, function(c){
    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
function fmtVal(v, type){
    if (v === null || v === undefined) return null;
    v = parseFloat(v);
    if (type === 'yesno') return v >= 1 ? 'Yes' : 'No';
    if (type === 'percent') return (Math.round(v*10)/10) + '%';
    if (type === 'rate' || type === 'score') return (Math.round(v*10)/10) + '';
    return (Math.round(v*10)/10) + '';
}
function freqName(f){ return {monthly:'每月',quarterly:'每季',halfyear:'半年',yearly:'每年'}[f] || f; }

/* ---------- 載入 ---------- */
function loadMeta(cb){
    $.getJSON(API, {action:'meta', year: YEAR || undefined}, function(m){
        if (!m.ok) { alert(m.error || '載入失敗'); return; }
        META = m;
        if (!YEAR) YEAR = m.cur_year;
        var $y = $('#yearSel').empty();
        m.years.forEach(function(y){ $y.append('<option value="'+y+'"'+(y===YEAR?' selected':'')+'>'+y+'</option>'); });
        if (m.perms.canAdmin) $('#btnSetting').show();
        renderPrintHead();
        if (cb) cb();
    });
}
function loadMatrix(afterScan){
    if (!afterScan) { STALE = {}; STALE_INFO = null; $('#staleBar').hide().empty(); }
    NProgress.start();
    $.getJSON(API, {action:'matrix', year: YEAR}, function(res){
        NProgress.done();
        if (!res.ok) { alert(res.error || '載入失敗'); return; }
        MATRIX = res;
        renderTable();
        renderChartPicks();
        renderChart();
        var anyRecalc = res.rows.some(function(r){ return r.can_recalc; });
        $('#btnRecalcYear').toggle(anyRecalc);
        $('#btnFill').toggle(!!res.is_admin);
        if (!afterScan) runStaleScan();   // 畫面先出來，過期偵測在背景跑
    }).fail(function(x){ NProgress.done(); alert('載入失敗：' + (x.responseJSON && x.responseJSON.error || x.status)); });
}

/* ---------- 快照過期偵測（使用者要求 2026-09-14） ----------
   自動指標的值是「快照」，來源資料事後補登不會重算也不會提示。這裡在畫面畫完之後
   非同步比對「快照結算時間」與「來源資料表最後異動時間」，過期就自動重算，
   並把真的變動的格子標上 ⟳、上方跳出提示條。已鎖定年度只標示、不寫入。 */
function runStaleScan(){
    var y = YEAR;
    $.post(API, {action:'stale_scan', year:y}, function(res){
        if (!res || !res.ok || y !== YEAR) return;
        var list = (res.updated || []).concat(res.stale || []);
        if (!list.length) return;
        STALE = {};
        list.forEach(function(x){
            if (!STALE[x.indicator_id]) STALE[x.indicator_id] = {};
            STALE[x.indicator_id][x.month] = x;
        });
        STALE_INFO = {list:list, can_write:+res.can_write === 1, truncated:!!res.truncated};
        showStaleBar();
        // 已鎖定年度只標示、後端一個字都沒寫回 → 值不會變，不必再整張重載一次
        // （白跑一趟只會讓畫面又閃一下）；只有真的重算過才重新拿新值。
        if (STALE_INFO.can_write) loadMatrix(true);
        else renderTable();
    }, 'json');
}
function staleCellTitle(x, canWrite){
    var o = (x.old === null || x.old === undefined) ? '無資料' : x.old;
    var n = (x.new === null || x.new === undefined) ? '無資料' : x.new;
    var base = '原結算時間：' + (x.old_at || '').substr(0,16)
             + '\n來源資料最後異動：' + (x.src_at || '').substr(0,16);
    return canWrite
        ? '快照已過期，已自動重算\n原值：' + o + '　→　新值：' + n + '\n' + base
        : '快照已過期（此年度已鎖定，未自動寫入）\n目前快照：' + o
          + '　→　依現行資料應為：' + n + '\n' + base
          + '\n請由 KPI 管理者按「重算」更新';
}
function showStaleBar(){
    if (!STALE_INFO) return;
    var n = STALE_INFO.list.length;
    var h = STALE_INFO.can_write
        ? '<i class="fa fa-refresh"></i> 快照已過期：有 <b>' + n + '</b> 格的來源資料在上次結算之後又異動過，<b>已自動重算並更新</b>（標 ⟳ 的格子）。'
        : '<i class="fa fa-exclamation-triangle"></i> 快照已過期：有 <b>' + n + '</b> 格與現行資料不符，但 ' + YEAR + ' 年度已鎖定，<b>未自動更新</b>，需由 KPI 管理者重算。';
    if (STALE_INFO.truncated) h += ' <span style="color:#DD5138;">(本次僅處理前 60 格，重新整理可繼續)</span>';
    h += '<span class="sb-link" onclick="openStaleList()">查看清單</span>';
    h += '<span class="sb-link" onclick="hideStaleBar()">關閉</span>';
    $('#staleBar').html(h).show();
}
function hideStaleBar(){ $('#staleBar').hide(); }
function openStaleList(){
    if (!STALE_INFO) return;
    var h = '<div style="margin-bottom:6px;color:#8a6d45;font-size:12px;">'
          + (STALE_INFO.can_write ? '下列儲存格的來源資料在上次結算之後又異動過，已自動重算：'
                                  : '下列儲存格與現行資料不符（年度已鎖定，未自動更新）：')
          + '</div>';
    h += '<table class="kpi-table" style="width:100%;font-size:12px;"><thead><tr>'
       + '<th>項次</th><th>指標</th><th>月份</th><th>原值</th><th>新值</th><th>分子/分母</th><th>原結算時間</th></tr></thead><tbody>';
    STALE_INFO.list.forEach(function(x){
        h += '<tr><td style="text-align:center;">' + x.item_no + '</td><td>' + esc(x.name) + '</td>'
           + '<td style="text-align:center;">' + x.month + '月</td>'
           + '<td style="text-align:center;color:#999;">' + (x.old === null ? '?' : x.old) + '</td>'
           + '<td style="text-align:center;color:#C2601C;font-weight:bold;">' + (x.new === null ? '?' : x.new) + '</td>'
           + '<td style="text-align:center;">' + (x.num === null || x.num === undefined ? '-' : (+x.num)) + ' / '
           + (x.den === null || x.den === undefined ? '-' : (+x.den)) + '</td>'
           + '<td style="text-align:center;">' + esc((x.old_at || '').substr(0,16)) + '</td></tr>';
        if (x.shadowed) h += '<tr><td></td><td colspan="6" style="color:#8a6d45;">↑ 這一格畫面上顯示的是手動覆寫／手動填寫值，自動值變動不影響顯示</td></tr>';
    });
    h += '</tbody></table>';
    $('#dtTitle').text('快照過期清單（' + YEAR + '年）');
    $('#dtBody').html(h);
    openMask('dtMask');
}

/* ---------- 表格渲染 ---------- */
function renderTable(){
    var html = '';
    MATRIX.rows.forEach(function(r, ri){
        var tip = '對應條文：' + (r.clause||'-') + '\n統計方式：' + (r.stat_desc||'-')
                + '\n來源：' + (r.source_mode==='auto' ? '自動計算' : '手動填寫')
                + (r.calculator_key ? '（'+r.calculator_key+'）' : '');
        html += '<tr data-ri="'+ri+'">';
        html += '<td>'+r.item_no+'</td>';
        html += '<td class="kpi-name" title="'+esc(tip)+'">'+esc(r.name)
              + (r.exposed_params.length ? ' <i class="fa fa-sliders" style="color:#F0A24B;cursor:pointer;" title="試算(調整開放參數)" onclick="toggleSim('+ri+', event)"></i>' : '')
              + '</td>';
        html += '<td style="font-size:12px;">'+esc(r.owner||'')+'</td>';
        html += '<td style="font-size:12px;">'+freqName(r.freq)+'</td>';
        html += '<td style="font-size:12px;">'+esc(r.target.text || '')+'</td>';
        for (var m=1; m<=12; m++){
            if (r.months.indexOf(m) < 0) { html += '<td class="kpi-na">—</td>'; continue; }
            var c = r.cells[m];
            if (FILL) {                                   // 補登模式：整格換成輸入框
                html += '<td class="kpi-cell fill-td" data-ri="'+ri+'" data-m="'+m+'">'
                      + (fillEditable(r, m) ? fillCellHtml(r, ri, m)
                                            : '<span class="kpi-na">'+(c.future?'NA':'—')+'</span>')
                      + '</td>';
                continue;
            }
            var cls = 'kpi-cell', txt;
            if (c.future) { txt = '<span class="kpi-na">NA</span>'; }
            else if (c.locked_month) { txt = '<span class="kpi-na" title="本期已於'+c.locked_month+'月填寫，如需改填此月份請先清除該月份內容">－</span>'; }
            else if (c.v === null) { txt = '<span class="kpi-none">?</span>'; }
            else {
                var f = fmtVal(c.v, r.value_type);
                if (c.src === 'preview') txt = '<span class="kpi-preview" title="當月即時試算，未定案">'+f+'</span>';
                else if (c.below) txt = '<span class="kpi-below">'+f+'</span>';
                else txt = f;
                if (c.src === 'override') txt += '<span class="kpi-ov-mark" title="手動覆寫：'+esc(c.ov_reason||'')+'">✱</span>';
            }
            if (c.attach > 0) txt += '<span class="kpi-attach-badge" title="佐證附件 '+c.attach+' 件（點儲存格→附件）">'
                                    + '<i class="fa fa-paperclip"></i>'+c.attach+'</span>';
            var sx = STALE[r.indicator_id] && STALE[r.indicator_id][m];
            // 標記只加 class（右上角小三角，由 CSS 絕對定位畫，不佔寬度），說明掛在 td 的 title
            var tdTitle = '';
            if (sx) { cls += ' kpi-stale';
                      tdTitle = ' title="'+esc(staleCellTitle(sx, STALE_INFO && STALE_INFO.can_write))+'"'; }
            html += '<td class="'+cls+'"'+tdTitle+' data-ri="'+ri+'" data-m="'+m+'">'+txt+'</td>';
        }
        var avgTxt, pavgTxt;
        if (r.value_type === 'yesno') {
            var yes=0, tot=0;
            r.months.forEach(function(m){ var c=r.cells[m];
                if (!c.future && c.v !== null && c.src !== 'preview') { tot++; if (c.v>=1) yes++; } });
            avgTxt = tot ? yes+'/'+tot : '';
            pavgTxt = '';
        } else {
            avgTxt = r.avg === null ? '' : fmtVal(r.avg, r.value_type);
            pavgTxt = r.prev_avg === null ? '' : fmtVal(r.prev_avg, r.value_type);
        }
        html += '<td class="kpi-avg">'+avgTxt+'</td><td class="kpi-avg" style="color:#8a6d45;">'+pavgTxt+'</td>';
        html += '</tr>';
        html += '<tr class="kpi-sim-row" id="simRow'+ri+'" style="display:none;"><td colspan="19"></td></tr>';
    });
    $('#kpiBody').html(html || '<tr><td colspan="19">無資料</td></tr>');
}

/* ---------- 儲存格選單 ---------- */
$(document).on('click', 'td.kpi-cell', function(e){
    if (FILL) return;                                   // 補登模式：點格子＝直接編輯，不開選單
    var ri = +$(this).data('ri'), m = +$(this).data('m');
    var r = MATRIX.rows[ri], c = r.cells[m];
    var items = [];
    items.push({t:'<i class="fa fa-info-circle"></i> 數值明細', f:function(){ showDetail(ri,m); }});
    items.push({t:'<i class="fa fa-paperclip"></i> 附件（'+c.attach+'）', f:function(){ openAttach(r.indicator_id, m, r); }});
    if (r.source_mode === 'auto' && !c.future)
        items.push({t:'<i class="fa fa-exclamation-triangle"></i> 不符合標準的明細', f:function(){ openVio(ri, m); }});
    ((r.source_info && r.source_info.links) || []).forEach(function(ln){
        if (!+ln.can || !ln.url) return;   // 沒權限的不出現在選單（網址後端本來就不回傳）
        items.push({t:'<i class="fa fa-external-link"></i> 前往：'+esc(ln.label),
                    f:function(){ window.open(ln.url, '_blank', 'noopener'); }});
    });
    if (r.can_fill && !c.future && !c.locked_month) items.push({t:'<i class="fa fa-pencil"></i> 填寫/修改', f:function(){ openFill(ri,m); }});
    if (r.can_fill && c.src === 'manual') items.push({t:'<i class="fa fa-eraser"></i> 清除填寫', f:function(){ doClearFill(r.indicator_id, m); }});
    if (r.can_recalc && !c.future && c.src !== 'preview')
        items.push({t:'<i class="fa fa-refresh"></i> 重算此月', f:function(){ doRecalc(r.indicator_id, m); }});
    if (r.can_override && !c.future && !c.locked_month) {
        items.push({t:'<i class="fa fa-hand-paper-o"></i> 手動覆寫', f:function(){ openOverride(ri,m); }});
        if (c.src === 'override') items.push({t:'<i class="fa fa-eraser"></i> 清除覆寫', f:function(){ doClearOverride(r.indicator_id, m); }});
    }
    var html = '<div class="cm-head">'+r.item_no+'. '+esc(r.name)+'｜'+m+'月</div>';
    items.forEach(function(it, i){ html += '<div class="cm-item" data-i="'+i+'">'+it.t+'</div>'; });
    if (c.filled_by) html += '<div class="cm-info">填寫：'+esc(c.filled_by)+' '+esc((c.filled_at||'').substr(0,16))+'</div>';
    if (c.ov_by) html += '<div class="cm-info">覆寫：'+esc(c.ov_by)+' '+esc((c.ov_at||'').substr(0,16))+'</div>';
    var $menu = $('#cellMenu').html(html).show();
    $menu.css({left: Math.min(e.pageX, $(window).width()-200), top: e.pageY + 5});
    $menu.find('.cm-item').on('click', function(){ $menu.hide(); items[+$(this).data('i')].f(); });
    e.stopPropagation();
});
$(document).on('click', function(){ $('#cellMenu').hide(); });

/* 資料來源頁面連結（要去哪一頁改真正的資料；沒權限只顯示灰字，後端不回網址） */
function srcLinksHtml(si, lead){
    var ls = (si && si.links) || [];
    if (!ls.length) return '';
    var h = '<div class="kpi-src-links" style="margin:4px 0;">' + esc(lead) + '：';
    ls.forEach(function(ln, i){
        if (i) h += '　';
        h += (+ln.can && ln.url)
           ? '<a href="' + esc(ln.url) + '" target="_blank" rel="noopener">' + esc(ln.label) + ' <i class="fa fa-external-link"></i></a>'
           : '<span class="noperm" title="您沒有這一頁的權限，請洽管理者">' + esc(ln.label) + '（無權限）</span>';
    });
    return h + '</div>';
}
function showDetail(ri, m){
    var r = MATRIX.rows[ri], c = r.cells[m];
    var si = r.source_info || {};
    var h = '<b>'+r.item_no+'. '+esc(r.name)+'</b>（'+YEAR+'年'+m+'月）<hr style="border-color:#EADFC8;margin:6px 0;">';
    h += '判定目標：'+esc(r.target.text||'-')+'<br>';
    h += '對應條文：'+esc(r.clause||'-')+'<br>';
    h += '統計方式：'+esc(r.stat_desc||'-')+'<br>';
    h += '資料來源：'+esc(si.label||(r.source_mode==='manual'?'手動填寫':'-'))
       + (si.page?'（'+esc(si.page)+'）':'')+'<br>';
    if (si.desc) h += '計算口徑：'+esc(si.desc)+'<br>';
    h += srcLinksHtml(si, '要調整數值請到');
    h += '擔當者：'+esc(r.owner||'-')+'　｜　頻率：'+freqName(r.freq)+'<br>';
    h += '<hr style="border-color:#EADFC8;margin:6px 0;">';
    h += '顯示值：<b>'+(c.v===null?'?':fmtVal(c.v, r.value_type))+'</b>（來源：'+({auto:'自動計算(快照)',manual:'手動填寫',override:'手動覆寫',preview:'當月即時試算',none:'無資料'}[c.src]||c.src)+'）<br>';
    if (c.num !== null || c.den !== null) {
        h += '分子／分母：'+(c.num===null?'-':(+c.num))+' ／ '+(c.den===null?'-':(+c.den));
        if (c.den !== null && +c.den !== 0 && (r.value_type==='percent'||r.value_type==='rate')) {
            h += '　=　'+(Math.round((+c.num)/(+c.den)*10000)/100)+(r.value_type==='percent'?'%':'');
        }
        h += '<br>';
    }
    if (c.computed_at) h += '結算時間：'+esc((c.computed_at||'').substr(0,16))+'<br>';
    if (c.src === 'override' && c.auto_v !== null) h += '被覆寫前自動值：'+fmtVal(c.auto_v, r.value_type)+'<br>';
    if (c.ov_by) h += '覆寫：'+esc(c.ov_by)+'（'+esc(c.ov_reason||'')+'）'+esc((c.ov_at||'').substr(0,16))+'<br>';
    if (c.filled_by) h += '填寫：'+esc(c.filled_by)+' '+esc((c.filled_at||'').substr(0,16))+'<br>';
    if (c.note) h += '備註：'+esc(c.note)+'<br>';
    if (r.source_mode === 'auto' && !c.future) {
        h += '<hr style="border-color:#EADFC8;margin:6px 0;">'
           + '<button onclick="closeMask(\'dtMask\');openVio('+ri+','+m+')" '
           + 'style="height:28px;padding:0 12px;border:1px solid #d98a33;background:#F0A24B;color:#fff;'
           + 'border-radius:4px;cursor:pointer;font-size:13px;">'
           + '<i class="fa fa-exclamation-triangle"></i> 看不符合標準的明細</button>';
    }
    $('#dtTitle').text('數值明細');
    $('#dtBody').html(h);
    openMask('dtMask');
}


/* ---------- 不符合標準的明細（使用者要求 2026-09-15） ----------
   只列「超過規定」的那幾筆＋每一筆的修改建議。
   mode=allow → 去來源頁面改真實資料；mode=deny → 只能排除這一筆（真實資料一個字都不動）。
   列印一律只印最後結果，這個跳窗與排除紀錄都不會出現在列印版上。 */
var VIO = null;
function openVio(ri, m){
    var r = MATRIX.rows[ri];
    VIO = {ri:ri, iid:r.indicator_id, m:m, row:r, data:null, sel:{}, filt:{}, kw:'', kind:'bad',
           rdim:'', rkw:'', rsel:[], rfound:[], rsrc:'', rbusy:0, rlast:null, rscope:'year'};
    $('#vioTitle').text('不符合標準的明細');
    $('#vioBody').html('<div style="padding:16px;color:#8a6d45;">載入中…</div>');
    $('#vioFoot').empty();
    openMask('vioMask');
    $.getJSON(API, {action:'detail_rows', indicator_id:r.indicator_id, year:YEAR, month:m}, function(res){
        if (!res || !res.ok) { $('#vioBody').html('<div style="padding:16px;color:#DD5138;">'+esc((res&&res.error)||'載入失敗')+'</div>'); return; }
        VIO.data = res;
        renderVio();
    }).fail(function(x){
        $('#vioBody').html('<div style="padding:16px;color:#DD5138;">載入失敗：'+esc((x.responseJSON&&x.responseJSON.error)||x.status)+'</div>');
    });
}
function vioModeBadge(d){
    if (d.mode === 'deny')  return '<span class="vio-mode deny">不可修改真實資料</span>';
    if (d.mode === 'allow') return '<span class="vio-mode allow">可直接修改真實資料</span>';
    return '<span class="vio-mode na">不適用</span>';
}
/* ---------- 篩選狀態（製程／廠商／客戶／料號…＋關鍵字＋種類） ----------
   選項一律由後端回傳的 dims 產生＝「資料裡有什麼就給什麼」，不在前端寫死任何清單。 */
function vioResetFilter(){ VIO.filt = {}; VIO.kw = ''; VIO.kind = 'bad'; }
function vioRowVisible(x){
    var d = VIO.data;
    // 預設只收起「參考」列（正常資料）；「提醒」列是要請人補資料的，一定要看得到
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
        for (var i = 0; i < ws.length; i++) {
            if (!ws[i]) continue;
            if (hay.toUpperCase().indexOf(ws[i].toUpperCase()) < 0) return false;
        }
    }
    return true;
}
function vioFilterHtml(){
    var d = VIO.data, h = '<div class="vio-filter">';
    (d.dims || []).forEach(function(dm){
        if (!dm.opts.length) return;
        // 選項文字帶上代號，這樣打字篩選（eg_input_rules.js 規則7 比對顯示文字）用代號也找得到
        h += '<label>' + esc(dm.t) + '：<select class="vioF" data-k="' + esc(dm.k) + '"'
           + ' data-eg-filter="輸入' + esc(dm.t) + '代號或名稱篩選…"><option value="">全部（' + dm.opts.length + '）</option>';
        dm.opts.forEach(function(o){
            var txt = o.v + (o.id && o.id !== o.v ? ('（' + o.id + '）') : '');
            h += '<option value="' + esc(o.v) + '"' + (VIO.filt[dm.k] === o.v ? ' selected' : '') + '>' + esc(txt) + '</option>';
        });
        h += '</select></label>';
    });
    h += '<label>關鍵字：<input type="text" id="vioKw" value="' + esc(VIO.kw || '') + '" placeholder="製令／料號／單號…"></label>';
    var hasInfo = false;
    d.rows.forEach(function(x){ if (x.kind === 'info') hasInfo = true; });
    if (hasInfo) {
        h += '<label>顯示：<select id="vioKind">'
           + '<option value="bad"' + (VIO.kind === 'bad' ? ' selected' : '') + '>只看不符合標準（含提醒）</option>'
           + '<option value="all"' + (VIO.kind === 'all' ? ' selected' : '') + '>全部（含參考用的正常資料）</option>'
           + '</select></label>';
    }
    h += '<span class="vf-count" id="vioCnt"></span>'
       + '<span class="vf-clear" id="vioClr">清除篩選</span></div>';
    return h;
}
/* ---------- 排除規則：整批排除某個客戶／製程／廠商／料號（整年度適用） ----------
   使用者要求 2026-09-18：客戶太多，不要一次攤開幾百個選項——
   改成「打字模糊搜尋（客戶ID／客戶名稱、廠商ID／名稱、製程ID／名稱、料號都可以）→ 從清單點選
   → 選到的列在下方」，而且不必填排除原因（誰在什麼時候設的系統本來就會記）。 */
function vioDimById(k){
    var r = null;
    ((VIO.data && VIO.data.dims) || []).forEach(function(d){ if (d.k === k) r = d; });
    return r;
}
function vioRuleWords(){
    return $.trim(VIO.rkw || '').split(/\s+/).filter(function(w){ return w.length > 0; });
}
function vioRuleMatch(o, ws){
    var hay = (o.v + ' ' + (o.id || '')).toUpperCase();
    for (var i = 0; i < ws.length; i++) if (hay.indexOf(ws[i].toUpperCase()) < 0) return false;
    return true;
}
function vioRenderRuleList(){
    var dm = vioDimById($('#vrDim').val()), ws = vioRuleWords(), h = '', n = 0, total = 0;
    var picked = {}; (VIO.rsel || []).forEach(function(v){ picked[v] = 1; });
    // 已經被「指標設定」或既有規則排除掉的，標出來不讓人重複設定
    var dimK = $('#vrDim').val(), done = {};
    ((VIO.data && VIO.data.param_excl) || []).forEach(function(x){ if (x.dim === dimK) done[x.val] = '指標設定'; });
    ((VIO.data && VIO.data.rules) || []).forEach(function(x){ if (x.dim === dimK) done[x.val] = '已有規則'; });
    var cap = 60, seen = {};
    // 先列這個月明細裡真的出現過的（最常用），再把主檔查到的補在後面
    var list = [];
    ((dm && dm.opts) || []).forEach(function(o){
        if (!vioRuleMatch(o, ws)) return;
        if (seen[o.v]) return;
        seen[o.v] = 1;
        list.push({v:o.v, id:o.id, src:'本月'});
    });
    var fsrc = VIO.rsrc || '主檔';
    (VIO.rfound || []).forEach(function(o){
        if (seen[o.v]) return;
        seen[o.v] = 1;
        list.push({v:o.v, id:o.id, src:fsrc});
    });
    // 已經排除掉的（指標設定或既有規則）直接不列出來——列出來只會讓人以為還要再設一次
    list = list.filter(function(o){ return !done[o.v]; });
    list.forEach(function(o){
        total++;
        if (n >= cap) return;
        n++;
        h += '<div class="vr-it' + (picked[o.v] ? ' on' : '') + '" data-v="' + esc(o.v) + '">'
           + esc(o.v) + (o.id ? ('<span class="vr-id">' + esc(o.id) + '</span>') : '')
           + '<span class="vr-src">' + esc(o.src) + '</span>'
           + (picked[o.v] ? '　✔ 已選' : '') + '</div>';
    });
    if (total > cap) h += '<div class="vr-none">還有 ' + (total - cap) + ' 筆沒顯示，請再輸入關鍵字縮小範圍。</div>';
    if (!total) {
        var kw = $.trim(VIO.rkw || '');
        var hidden = 0;
        for (var kk in done) if (!ws.length || vioRuleMatch({v:kk, id:''}, ws)) hidden++;
        h = VIO.rbusy
            ? '<div class="vr-none">查詢中…</div>'
            : (hidden
               ? '<div class="vr-none">符合的項目都已經排除了，不必再設定一次。</div>'
               : (kw
               ? '<div class="vr-none">主檔裡找不到「' + esc(kw) + '」。'
                 + '排除規則比對的是<b>名稱</b>，請改用名稱或正確的代號搜尋；'
                 + '真的要直接用這串字建立規則請按 <a href="#" id="vrFree">直接使用「' + esc(kw) + '」</a>。</div>'
               : '<div class="vr-none">這個年度的資料裡沒有可選的項目，請輸入代號或名稱搜尋主檔。</div>'));
    }
    $('#vrList').html(h);
    var ph = '';
    (VIO.rsel || []).forEach(function(v){
        ph += '<span class="vr-chip2">' + esc(v) + '<span class="vr-x2" data-v="' + esc(v) + '" title="移除">×</span></span>';
    });
    $('#vrPicked').html('已選 ' + (VIO.rsel || []).length + ' 項：' + (ph || '<span style="color:#a08356;">（尚未選擇）</span>'));
}
function vioRulesHtml(){
    var d = VIO.data, h = '';
    var rs = d.rules || [], lb = d.dim_labels || {};
    h += '<div class="vio-rules"><b>排除規則</b>（' + YEAR + ' 年度整年適用，只影響 KPI 計算、不會修改任何真實資料；'
       + '<b>列印版不會出現任何排除資訊</b>）：';
    // 指標設定（KPI 設定頁的參數）裡本來就排除掉的，也要列出來——
    // 那幾筆資料根本不會出現在明細上，畫面上看不到就會有人再建一條一模一樣的規則
    var pe = d.param_excl || [];
    pe.forEach(function(x){
        h += '<span class="vr-chip fixed" title="這是 KPI 設定頁的指標參數，要改請到 KPI 設定頁">'
           + esc(lb[x.dim] || x.dim) + '：' + esc(x.val)
           + '<span class="vr-by">指標設定</span></span>';
    });
    if (!rs.length && !pe.length) h += '<span style="color:#a08356;">目前沒有設定任何規則。</span>';
    rs.forEach(function(r){
        h += '<span class="vr-chip' + (r.scope === 'all' ? ' allyr' : '') + '" title="'
           + esc(r.created_by_name || '') + ' '
           + esc((r.created_at || '').substr(0, 16)) + (r.reason ? ('｜' + esc(r.reason)) : '') + ' 設定">'
           + esc(lb[r.dim] || r.dim) + '：' + esc(r.val)
           + '<span class="vr-scope">' + (r.scope === 'all' ? '所有年度' : (r.year + ' 年度')) + '</span>'
           + '<span class="vr-by" style="color:#a08356;font-size:11px;margin-left:5px;">'
           + esc(r.created_by_name || '') + ' ' + esc((r.created_at || '').substr(0, 10)) + '</span>'
           + (+d.can_adjust ? ('<span class="vr-x" data-id="' + r.rule_id + '" title="取消這條規則">×</span>') : '')
           + '</span>';
    });
    if (+d.can_adjust && (d.dims || []).length) {
        h += '<div class="vr-new">新增：<select id="vrDim">';
        (d.dims || []).forEach(function(dm){
            if (!dm.opts.length) return;
            h += '<option value="' + esc(dm.k) + '"' + (VIO.rdim === dm.k ? ' selected' : '') + '>' + esc(dm.t) + '</option>';
        });
        h += '</select><input type="text" id="vrKw" value="' + esc(VIO.rkw || '')
           + '" placeholder="輸入代號或名稱模糊搜尋（例：C2005、1R、和大、齒研、料號…）">'
           + '<label style="margin:0;font-weight:normal;">適用：<select id="vrScope">'
           + '<option value="year"' + (VIO.rscope === 'all' ? '' : ' selected') + '>僅 ' + YEAR + ' 年度</option>'
           + '<option value="all"' + (VIO.rscope === 'all' ? ' selected' : '') + '>所有年度</option>'
           + '</select></label>'
           + '<button id="vrAdd" class="warm">建立排除規則（立即存檔）</button></div>'
           + '<div class="vr-list" id="vrList"></div>'
           + '<div class="vr-picked" id="vrPicked"></div>'
           + (pe.length ? ('<div class="vio-seltip">標「指標設定」的是 KPI 設定頁參數裡本來就排除的，'
                           + '這裡不必也不能再建一次；要改請到 KPI 設定頁。</div>') : '')
           + '<div class="vio-seltip">清單預設只列<b>' + YEAR + ' 年度資料裡真的有的</b>'
           + '（標「本月」的是這個月明細就看得到的）；這個年度沒有的，打代號或名稱就會從主檔搜出來。'
           + '點一下加入／再點一次取消，<b>已經排除掉的不會出現在清單裡</b>。'
           + '存進規則的一律是<b>名稱</b>，所以挑清單裡的項目才比對得到。'
           + '不必填原因，系統會記下是誰在什麼時候設定的。</div>';
    }
    h += '</div>';
    return h;
}
function renderVio(){
    var d = VIO.data, r = VIO.row, h = '';
    h += '<div class="vio-head"><b>'+r.item_no+'. '+esc(r.name)+'</b>（'+YEAR+'年'+VIO.m+'月）　'
       + '判定目標：'+esc(r.target.text||'—')+'　'+vioModeBadge(d)+'</div>';
    if (!+d.supported) {
        h += '<div class="vio-note">'+esc(d.msg||'這個指標還沒有做不符合標準的明細。')+'</div>';
        h += srcLinksHtml(d, '要調整數值請到');
        $('#vioBody').html(h); $('#vioFoot').empty();
        return;
    }
    var exN = 0;
    d.rows.forEach(function(x){ if (+x.excluded) exN++; });
    h += '<div class="vio-note">'+esc(d.note||'')
       + (d.note_excl ? ('<span class="vio-exinfo">'+esc(d.note_excl)+'</span>') : '')
       + '<br>不符合標準 <b>'+d.total+'</b> 筆'+(exN?('，其中 <b>'+exN+'</b> 筆已逐筆排除'):'')
       + (+d.rule_ex ? ('，另有 <b>'+d.rule_ex+'</b> 筆被排除規則排掉') : '')
       + (+d.truncated ? '（畫面最多顯示 500 筆）' : '')+'。</div>';
    if (d.mode === 'deny') {
        h += '<div class="vio-warn">這個指標的來源資料<b>不開放直接修改</b>（'+esc(d.why||'')+'）。'
           + '如果某幾筆不應該算進這個月的績效，請勾選後按下方「排除選取」並填寫原因——'
           + '<b>排除只影響 KPI 計算，不會動到任何一筆真實資料</b>。</div>';
    } else if (d.mode === 'allow') {
        h += '<div class="vio-warn ok">這個指標的來源資料<b>可以修改</b>（'+esc(d.why||'')+'）：'
           + ((d.edit_fields||[]).length
              ? '可以直接在最右邊那一欄改（改完立刻重算，並留下誰改了什麼的紀錄），也可以到來源頁面處理。'
              : '請到下方來源頁面修正；這裡不提供就地修改。')
           + '<b>只有登錄錯誤才改</b>，確實不符合標準的請保持原樣，'
           + '不該算進績效的請用勾選＋「排除選取」處理。</div>';
    }
    h += srcLinksHtml(d, '來源頁面');
    h += vioRulesHtml();
    h += vioFilterHtml();

    var showChk = (+d.can_adjust && d.rows.length) ? 1 : 0;
    h += '<div class="vio-tblwrap"><table class="vio-tbl"><colgroup>';
    if (showChk) h += '<col style="width:26px;">';
    d.cols.forEach(function(){ h += '<col>'; });
    h += '<col style="width:15%;"><col style="width:20%;">';
    if (d.mode === 'allow' && (d.edit_fields||[]).length) h += '<col style="width:184px;">';
    h += '</colgroup><thead><tr>';
    if (showChk) h += '<th><input type="checkbox" id="vioAll" title="全選目前篩選出來的列"></th>';
    d.cols.forEach(function(c){ h += '<th>'+esc(c.t)+'</th>'; });
    h += '<th>不符合的原因</th><th>建議怎麼處理</th>';
    if (d.mode === 'allow' && (d.edit_fields||[]).length) h += '<th>直接修改</th>';
    h += '</tr></thead><tbody id="vioTb">';
    var shown = 0;
    d.rows.forEach(function(x, ix){
        if (!vioRowVisible(x)) return;
        shown++;
        var cls = [];
        if (+x.excluded) cls.push('ex');
        if (x.rule_ex) cls.push('rex');
        if (x.kind === 'warn') cls.push('warn');
        if (x.kind === 'info') cls.push('info');
        h += '<tr class="'+cls.join(' ')+'" data-k="'+esc(x.key)+'" data-ix="'+ix+'">';
        if (showChk) {
            var lockRow = (x.rule_ex && !+x.excluded) ? ' disabled' : '';
            h += '<td><input type="checkbox" class="vioChk" value="'+esc(x.key)+'"'
               + (+x.excluded?' checked':'')+lockRow+'></td>';
        }
        d.cols.forEach(function(c){ h += '<td>'+esc(x.vals[c.k]==null?'':x.vals[c.k])+'</td>'; });
        var tag = '';
        if (x.rule_ex) tag = '<span class="kind-tag rex">規則排除</span>';
        else if (x.kind === 'warn') tag = '<span class="kind-tag warn">提醒</span>';
        else if (x.kind === 'info') tag = '<span class="kind-tag info">參考</span>';
        h += '<td class="vio-why">'+tag+esc(x.why||'')+'</td>';
        h += '<td class="vio-fix">'+esc(x.fix||'')
           + (x.rule_ex ? ('<div class="vio-ex">被排除規則排掉（'+esc((VIO.data.dim_labels||{})[x.rule_ex]||x.rule_ex)
                + '），不列入本月計算。</div>') : '')
           + (+x.excluded ? ('<div class="vio-ex">已排除計算：'+esc(x.ex_reason||'')
                + '（'+esc(x.ex_by||'')+' '+esc((x.ex_at||'').substr(0,16))+'）</div>') : '')
           + '</td>';
        if (d.mode === 'allow' && (d.edit_fields||[]).length) {
            h += '<td class="vio-edit">';
            if (+d.can_edit && x.kind === 'bad') {
                d.edit_fields.forEach(function(f){
                    var cur = x.edit && x.edit[f.k] != null ? String(x.edit[f.k]) : '';
                    h += '<div class="ve-row"><span class="ve-lb" title="'+esc(f.hint||'')+'">'+esc(f.t)+'</span>';
                    if (f.type === 'select') {
                        h += '<select class="veF" data-k="'+esc(x.key)+'" data-f="'+esc(f.k)+'">';
                        h += '<option value="">（不變）</option>';
                        (f.opts||[]).forEach(function(o){
                            h += '<option value="'+esc(o.v)+'"'+(o.v===cur?' selected':'')+'>'+esc(o.t)+'</option>';
                        });
                        h += '</select>';
                    } else {
                        h += '<input type="date" class="veF" data-k="'+esc(x.key)+'" data-f="'+esc(f.k)+'" value="'+esc(cur)+'">';
                    }
                    h += '</div>';
                });
            } else {
                h += '<span style="color:#a08356;font-size:11px;">'+(+d.can_edit?'—':'無修改權限')+'</span>';
            }
            h += '</td>';
        }
        h += '</tr>';
    });
    if (!shown) h += '<tr><td colspan="20" style="padding:14px;color:#8a6d45;">'
                   + (d.rows.length ? '目前的篩選條件沒有符合的資料。' : '這個月沒有不符合標準的項目。')+'</td></tr>';
    h += '</tbody></table></div>';
    $('#vioBody').html(h);
    $('#vioCnt').text('顯示 '+shown+' / 共 '+d.rows.length+' 筆');
    if ($('#vrDim').length) { vioRenderRuleList(); vioRuleLookup(); }

    var f = '';
    if (+d.can_adjust) {
        f += '<button class="warm" id="vioDo">排除選取並存檔</button>'
           + '<button id="vioUndo">取消排除選取</button>'
           + '<input type="text" id="vioReason" placeholder="排除原因（可不填）" style="width:200px;margin-left:6px;">'
           + '<button id="vioDone" style="margin-left:6px;">完成，關閉</button>';
    } else {
        f += '<span style="color:#8a6d45;font-size:12px;">您沒有調整這個指標的權限，只能檢視。</span>';
    }
    if (d.rows.length || +d.supported) {
        // 選取複製（使用者要求 2026-09-18）：整欄一次複製，貼進 Excel 最快
        f += '<span class="vio-print">複製：<select id="vioCopyK">';
        d.cols.forEach(function(c){ f += '<option value="' + esc(c.k) + '">' + esc(c.t) + '</option>'; });
        f += '</select><button id="vioCopyCol"><i class="fa fa-clone"></i> 複製整欄</button></span>';
        f += '<span class="vio-print">列印：<select id="vioPrM"></select>'
           + '<button id="vioPrint"><i class="fa fa-print"></i> 列印明細</button></span>';
    }
    if (+d.can_set_mode) {
        f += '<span class="vio-set">管理員設定：'
           + '<select id="vioMode">'
           + '<option value="suggest"'+(d.setting==='suggest'?' selected':'')+'>依系統建議（'+esc(d.suggest)+'）</option>'
           + '<option value="allow"'+(d.setting==='allow'?' selected':'')+'>可直接修改真實資料</option>'
           + '<option value="deny"'+(d.setting==='deny'?' selected':'')+'>不可修改，只能排除</option>'
           + '</select></span>';
    }
    // 這個跳窗每個動作都是「按下去當下就存檔」，沒有另外的存檔鈕——
    // 使用者回報找不到存檔按鈕（2026-09-18），所以把這件事明講出來
    if (+d.can_adjust) {
        f += '<div class="vio-savetip">這個畫面<b>沒有另外的存檔按鈕</b>：按下「排除選取並存檔」「建立排除規則」'
           + '或改最右邊那一欄，<b>當下就已經存檔並重新計算</b>了（每一筆都會記下是誰在什麼時候做的）。'
           + '　<b>要複製料號／訂單編號</b>：直接用滑鼠在同一列上拖曳就選得起來（Ctrl+C），'
           + '雙擊那一格會直接複製整格，或用右下角「複製整欄」一次複製目前篩選出來的全部。'
           + '　拖到<b>別的一列</b>才會變成一次勾選多列。</div>';
    }
    $('#vioFoot').html(f);
    if ($('#vioPrM').length) {
        var mh = '';
        (r.months || []).forEach(function(mm){
            mh += '<option value="' + mm + '"' + (mm === VIO.m ? ' selected' : '') + '>' + mm + ' 月</option>';
        });
        mh += '<option value="all">本年度全部月份</option>';
        $('#vioPrM').html(mh);
    }
}
/* 篩選／關鍵字／種類：只重畫表格本身 */
$(document).on('change', '#vioBody .vioF', function(){
    VIO.filt[$(this).attr('data-k')] = $(this).val(); renderVio();
});
$(document).on('change', '#vioBody #vioKind', function(){ VIO.kind = $(this).val(); renderVio(); });
var vioKwT = null;
$(document).on('input', '#vioBody #vioKw', function(){
    var v = $(this).val();
    clearTimeout(vioKwT);
    vioKwT = setTimeout(function(){ VIO.kw = $.trim(v); renderVio(); $('#vioKw').focus(); }, 250);
});
$(document).on('click', '#vioBody #vioClr', function(){ vioResetFilter(); renderVio(); });
$(document).on('change', '#vioBody #vioAll', function(){
    var on = $(this).is(':checked');
    $('#vioBody .vioChk:not(:disabled)').prop('checked', on)
        .each(function(){ $(this).closest('tr').toggleClass('sel', on); });
});
/* ---------- 拖移一次多選（2026-09-17）＋ 選取複製（2026-09-18） ----------
   使用者要求料號／訂單編號要選得起來複製，但先前為了拖曳多選在 mousedown 就
   preventDefault()，等於把整張表的文字選取全部擋掉了。
   改成用「手勢」區分，兩種都能用：
     同一列之內拖曳  → 不攔，瀏覽器照常選字，可以直接 Ctrl+C
     拖到別的一列    → 這時才開始多選，並把剛剛選到的字清掉
   點在輸入元件上一律不攔（否則下拉與日期欄點不動）。 */
var VIODRAG = null;
function vioSetRow($tr, st){
    var $chk = $tr.find('.vioChk');
    if (!$chk.length || $chk.prop('disabled')) return;
    $chk.prop('checked', st);
    $tr.toggleClass('sel', st);
}
$(document).on('mousedown', '#vioBody tbody tr', function(e){
    if (e.which && e.which !== 1) return;                 // 只管左鍵
    var $chk = $(this).find('.vioChk');
    if (!$chk.length || $chk.prop('disabled')) return;
    if ($(e.target).is('input,select,textarea,button,a,option,label')) {
        if ($(e.target).hasClass('vioChk')) VIODRAG = {state: !$chk.prop('checked'), armed: true};
        return;
    }
    // 先只記著，還不動任何勾選狀態——等真的拖到別列再說（這樣同一列內就能正常選字）
    VIODRAG = {state: !$chk.prop('checked'), armed: false, from: this};
    // 直接點在勾選欄那一格＝立刻切換（不必拖）
    if ($(e.target).closest('td').find('.vioChk').length) {
        VIODRAG.armed = true;
        vioSetRow($(this), VIODRAG.state);
        e.preventDefault();
    }
});
$(document).on('mouseenter', '#vioBody tbody tr', function(){
    if (!VIODRAG) return;
    if (!VIODRAG.armed) {
        // 第一次跨到別的一列＝確定是要多選：起始列補上，並把剛剛拖出來的反白清掉
        VIODRAG.armed = true;
        if (VIODRAG.from) vioSetRow($(VIODRAG.from), VIODRAG.state);
        try { (window.getSelection().removeAllRanges || function(){})(); } catch (e) {}
    }
    vioSetRow($(this), VIODRAG.state);
});
$(document).on('mouseup', function(){ VIODRAG = null; });
/* 雙擊儲存格＝複製這一格的文字（料號／訂單編號／製令最常用） */
$(document).on('dblclick', '#vioBody tbody td', function(e){
    if ($(e.target).is('input,select,textarea,button,a')) return;
    var txt = $.trim($(this).clone().children('.vio-ex,.kind-tag').remove().end().text());
    if (!txt || txt === '—') return;
    vioCopy(txt, '已複製「' + txt + '」');
});
/* 複製文字（優先用 clipboard API，不支援就退回 execCommand） */
function vioCopy(txt, msg){
    var done = function(){ vioToast(msg || '已複製'); };
    try {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(txt).then(done, function(){ vioCopyFallback(txt, done); });
            return;
        }
    } catch (e) {}
    vioCopyFallback(txt, done);
}
function vioCopyFallback(txt, done){
    var ta = document.createElement('textarea');
    ta.value = txt;
    ta.style.cssText = 'position:fixed;left:-9999px;top:0;';
    document.body.appendChild(ta);
    ta.select();
    try { document.execCommand('copy'); done(); } catch (e) { alert('這個瀏覽器不支援自動複製，請手動選取'); }
    document.body.removeChild(ta);
}
function vioToast(msg){
    var $t = $('#vioToast');
    if (!$t.length) $t = $('<div id="vioToast"></div>').appendTo('body');
    $t.text(msg).stop(true, true).fadeIn(120).delay(1400).fadeOut(300);
}
/* 整欄複製：把目前篩選出來的那一欄全部複製成一行一個，直接貼進 Excel */
$(document).on('click', '#vioCopyCol', function(){
    var k = $('#vioCopyK').val();
    if (!k) { alert('請先選要複製哪一欄'); return; }
    var vals = [];
    VIO.data.rows.forEach(function(x){
        if (!vioRowVisible(x)) return;
        var v = (x.pvals && x.pvals[k] != null) ? x.pvals[k] : x.vals[k];
        v = $.trim(String(v == null ? '' : v));
        if (v !== '' && v !== '—') vals.push(v);
    });
    if (!vals.length) { alert('目前篩選出來的資料裡這一欄是空的'); return; }
    var uniq = [], seen = {};
    vals.forEach(function(v){ if (!seen[v]) { seen[v] = 1; uniq.push(v); } });
    vioCopy(uniq.join('\n'), '已複製 ' + uniq.length + ' 筆（重複的已合併）');
});
/* ---------- 排除規則 ---------- */
$(document).on('change', '#vioBody #vrDim', function(){
    VIO.rdim = $(this).val(); VIO.rsel = []; VIO.rfound = [];
    vioRenderRuleList(); vioRuleLookup(true);
});
var vioRkwT = null, vioRkwSeq = 0;
/* 打字 → 先用本月資料即時篩（有反應），再向後端查主檔補進來。
   一定要查主檔：這個月沒出貨的客戶本來就不在明細裡，只靠本月資料會挑不到人。 */
function vioRuleLookup(force){
    var dim = $('#vrDim').val(), q = $.trim(VIO.rkw || ''), seq;
    if (!dim) return;
    // 只有維度或關鍵字真的變了才去查主檔：renderVio() 在每次篩選／打字都會重畫，
    // 不擋的話每按一個鍵就多送一支查詢
    var key = dim + ' ' + q;
    if (!force && VIO.rlast === key) return;
    VIO.rlast = key;
    seq = ++vioRkwSeq;
    VIO.rbusy = 1;
    $.getJSON(API, {action:'excl_dim_search', indicator_id:VIO.iid, year:YEAR, dim:dim, q:q}, function(res){
        if (seq !== vioRkwSeq) return;              // 打字很快時只採用最後一次的結果
        VIO.rbusy = 0;
        VIO.rfound = (res && res.ok) ? (res.rows || []) : [];
        VIO.rsrc   = (res && res.src) ? res.src : '主檔';
        vioRenderRuleList();
    }).fail(function(){
        if (seq !== vioRkwSeq) return;
        VIO.rbusy = 0; VIO.rfound = []; vioRenderRuleList();
    });
}
$(document).on('input', '#vioBody #vrKw', function(){
    var v = $(this).val();
    clearTimeout(vioRkwT);
    vioRkwT = setTimeout(function(){
        VIO.rkw = v;
        vioRenderRuleList();       // 先畫本月的，畫面不會空著等
        vioRuleLookup();
    }, 250);
});
$(document).on('click', '#vioBody #vrFree', function(e){
    e.preventDefault();
    var kw = $.trim(VIO.rkw || '');
    if (!kw) return;
    VIO.rsel = VIO.rsel || [];
    if (VIO.rsel.indexOf(kw) < 0) VIO.rsel.push(kw);
    vioRenderRuleList();
});
$(document).on('click', '#vioBody .vr-it', function(){
    if ($(this).hasClass('dis')) return;
    var v = $(this).attr('data-v');
    if (!v) return;
    VIO.rsel = VIO.rsel || [];
    var i = VIO.rsel.indexOf(v);
    if (i >= 0) VIO.rsel.splice(i, 1); else VIO.rsel.push(v);
    vioRenderRuleList();
});
$(document).on('click', '#vioBody .vr-x2', function(e){
    e.stopPropagation();
    var v = $(this).attr('data-v'), i = (VIO.rsel || []).indexOf(v);
    if (i >= 0) VIO.rsel.splice(i, 1);
    vioRenderRuleList();
});
$(document).on('change', '#vioBody #vrScope', function(){ VIO.rscope = $(this).val(); });
function vioRuleResultMsg(res){
    var m = '';
    if (res.years && res.years.length) m += '\n影響年度：' + res.years.join('、') + '（共重算 ' + res.recalced + ' 個月份）';
    if (res.skipped_years && res.skipped_years.length)
        m += '\n' + res.skipped_years.join('、') + ' 年度已結案鎖定，快照沒有重算（需 KPI 管理者）。';
    if (res.dupe && res.dupe.length) m += '\n（' + res.dupe.join('、') + ' 在指標設定裡本來就排除了，略過）';
    return m;
}
$(document).on('click', '#vioDone', function(){ closeMask('vioMask'); });
$(document).on('click', '#vioBody #vrAdd', function(){
    var dim = $('#vrDim').val(), vals = (VIO.rsel || []).slice();
    var scope = $('#vrScope').val() || 'year';
    if (!vals.length) { alert('請先從下方清單點選要排除的項目（可先打字模糊搜尋）'); return; }
    var lb = $('#vrDim option:selected').text();
    var sp = (scope === 'all') ? '所有年度' : (YEAR + ' 年度');
    if (!confirm('把下列 '+lb+' 在【'+sp+'】都排除在這個指標的計算之外？\n\n'
                 + vals.join('、') + '\n\n（不會修改任何一筆真實資料，但涵蓋到的每一個月都會重算'
                 + (scope === 'all' ? '；已結案鎖定的年度不會動到' : '') + '）')) return;
    $.post(API, {action:'excl_rule_add', indicator_id:VIO.iid, year:YEAR,
                 dim:dim, scope:scope, vals:JSON.stringify(vals)}, function(res){
        if (!res.ok) { alert(res.error||'建立失敗'); return; }
        alert('已建立 '+res.added+' 條排除規則（適用：'+sp+'）。' + vioRuleResultMsg(res));
        VIO.rsel = []; VIO.rkw = '';
        openVio(VIO.ri, VIO.m); loadMatrix(true);
    }, 'json').fail(function(x){ alert('建立失敗：'+((x.responseJSON&&x.responseJSON.error)||x.status)); });
});
$(document).on('click', '#vioBody .vr-x', function(){
    var id = $(this).attr('data-id');
    var all = $(this).closest('.vr-chip').hasClass('allyr');
    if (!confirm('取消這一條排除規則？'
                 + (all ? '這是「所有年度」的規則，每一個年度都會重算。' : '該年度每一個月都會重算。'))) return;
    $.post(API, {action:'excl_rule_del', indicator_id:VIO.iid, year:YEAR,
                 rule_ids:JSON.stringify([id])}, function(res){
        if (!res.ok) { alert(res.error||'取消失敗'); return; }
        alert('已取消 '+res.removed+' 條規則。' + vioRuleResultMsg(res));
        openVio(VIO.ri, VIO.m); loadMatrix(true);
    }, 'json').fail(function(x){ alert('取消失敗：'+((x.responseJSON&&x.responseJSON.error)||x.status)); });
});
/* ---------- 列印「排除後」的月份明細（使用者要求 2026-09-18） ----------
   KPI 的值是排除之後才算出來的，所以佐證用的明細一定要印得出「這個月算了哪幾筆、
   哪幾筆被排掉、是誰在什麼時候排的」。可以只印目前這個月，也可以整年度一個月一頁一次印完。
   版面依 ai-rules/16：大標題本公司全名、表頭取綁定 AS 文件的表單名稱、頁碼左下、AS 編號右下。 */
function vioPrintRowsHtml(d, r, m, cell){
    /* 【這份是要給稽核老師看的正式清單】使用者要求 2026-09-18：
       不可以出現「不符合標準明細」「不符合的原因」「（推估：○○）」這類內部作業字樣，
       也不印判定方法與推估來源的說明；排除資訊更是一個字都不能有（見下方過濾）。
       只印：公司全名／表單名稱／指標與年月／判定目標與本月數值／一句表格說明／資料本身。 */
    var h = '';
    h += '<div class="pt-head"><div class="co">' + esc((META && META.company) || '') + '</div>'
       + '<div class="tt">' + esc((META && META.as_doc && META.as_doc.doc_name) || 'KPI 關鍵績效指標') + '</div>'
       + '<div class="sub">' + r.item_no + '. ' + esc(r.name) + '　｜　' + YEAR + ' 年 ' + m + ' 月'
       + '　｜　判定目標：' + esc(r.target.text || '—');
    if (cell) {
        h += '　｜　本月數值：' + (cell.v === null ? '無資料' : esc(fmtVal(cell.v, r.value_type)));
        if (cell.num !== null || cell.den !== null)
            h += '（' + (cell.num === null ? '-' : (+cell.num)) + '／' + (cell.den === null ? '-' : (+cell.den)) + '）';
    }
    h += '　｜　列印：' + egFmtDate(new Date().toISOString().substr(0, 10)) + '</div></div>';
    if (d.note_print) h += '<div class="pt-note">' + esc(d.note_print) + '</div>';

    // 被排除的列不列出來——印出來的就是「排除後」真正計入的那幾筆
    var shown = d.rows.filter(function(x){
        if (x.rule_ex || +x.excluded) return false;
        return vioRowVisible(x);
    });
    // 內部作業欄（標 p:0，例如「疑似已出貨(未綁)」）不列印
    var cols = d.cols.filter(function(c){ return c.p !== 0; });
    h += '<table class="pt"><thead><tr><th style="width:34px;">#</th>';
    cols.forEach(function(c){ h += '<th>' + esc(c.t) + '</th>'; });
    h += '</tr></thead><tbody>';
    if (!shown.length) h += '<tr><td colspan="' + (cols.length + 1) + '">本月無資料。</td></tr>';
    shown.forEach(function(x, i){
        h += '<tr><td>' + (i + 1) + '</td>';
        cols.forEach(function(c){
            // pvals＝列印專用值（例：回廠日只印日期，不印「（推估：○○）」）
            var v = (x.pvals && x.pvals[c.k] != null) ? x.pvals[c.k] : x.vals[c.k];
            h += '<td class="l">' + esc(v == null ? '' : v) + '</td>';
        });
        h += '</tr>';
    });
    h += '</tbody></table>';
    h += '<div class="pt-note">合計 ' + shown.length + ' 筆。</div>';
    return h;
}
function vioPrintOpen(title, body){
    var asTxt = String((META && META.as_doc_no) || '').replace(/['\\]/g, '');
    // 頁面留白（使用者要求 2026-09-18：列印太滿版，頁首頁尾與左右都要正常留空）
    // 上 16mm／左右 15mm／下 18mm——下緣要多留一點，頁碼與 AS 編號是印在下方頁邊區裡的
    var css = '@page{size:A4 landscape;margin:16mm 15mm 18mm;'
        + (asTxt ? " @bottom-right{content:'" + asTxt + "';font-size:9pt;color:#333;}" : '')
        + " @bottom-left{content:'第 ' counter(page) ' 頁／共 ' counter(pages) ' 頁';font-size:9pt;color:#333;}}"
        + 'body{font-family:"Microsoft JhengHei","微軟正黑體",sans-serif;color:#000;margin:0;padding:0;'
        + '-webkit-print-color-adjust:exact;print-color-adjust:exact;}'
        + '.pg{page-break-before:always;} .pg:first-child{page-break-before:auto;}'
        + '.pt-head{text-align:center;margin-bottom:4px;}'
        + '.pt-head .co{font-size:20px;font-weight:bold;letter-spacing:2px;}'
        + '.pt-head .tt{font-size:15px;font-weight:bold;margin-top:3px;letter-spacing:1px;}'
        + '.pt-head .sub{font-size:11px;color:#333;margin-top:2px;}'
        + '.pt-note{font-size:10px;color:#333;margin:4px 0;line-height:1.5;}'
        + 'table.pt{width:100%;border-collapse:collapse;font-size:10px;table-layout:fixed;}'
        + 'table.pt th,table.pt td{border:1px solid #333;padding:2px 3px;text-align:center;'
        + 'word-wrap:break-word;overflow-wrap:anywhere;line-height:1.35;}'
        + 'table.pt th{background:#EFEFEF;font-weight:bold;}'
        + 'table.pt td.l{text-align:left;}'
        + 'table.pt .sm{font-size:9px;color:#444;}'
        // 跨頁時表頭要跟著重複，資料列不可以被切成上下兩半
        + 'table.pt thead{display:table-header-group;}'
        + 'table.pt tr{page-break-inside:avoid;break-inside:avoid;}'
        + '.pt-head{break-after:avoid;page-break-after:avoid;}';
    var w = window.open('', '_blank');
    if (!w) { alert('請允許彈出視窗才能列印'); return; }
    w.document.write('<!DOCTYPE html><html><head><meta charset="utf-8"><title>' + esc(title)
        + '</title><style>' + css + '</style></head><body>' + body
        + '<scr' + 'ipt>window.onload=function(){setTimeout(function(){window.print();},250);};</scr' + 'ipt></body></html>');
    w.document.close();
}
function vioPrintLog(r, txt){
    try {
        if (window.EGPrintLog) EGPrintLog.record({
            source:'kpi_as', doc_kind:'form',
            doc_name:'KPI明細 ' + r.item_no + '.' + r.name + ' ' + YEAR + '年' + txt,
            ref_table:'kpi_as_indicator', ref_id:r.indicator_id
        });
    } catch (e) {}
}
$(document).on('click', '#vioPrint', function(){
    var scope = $('#vioPrM').val(), r = VIO.row;
    if (scope !== 'all') {
        var body = '<div class="pg">' + vioPrintRowsHtml(VIO.data, r, VIO.m, r.cells[VIO.m]) + '</div>';
        vioPrintLog(r, VIO.m + '月');
        vioPrintOpen(r.item_no + '.' + r.name + ' ' + YEAR + '-' + VIO.m + ' 明細', body);
        return;
    }
    // 整年度：一個月一頁，逐月向後端要「排除後」的明細（月份多所以依序抓，不要一次打十二支）
    var months = (r.months || []).filter(function(m){ return !r.cells[m].future; });
    if (!months.length) { alert('本年度沒有可列印的月份'); return; }
    var $b = $(this).prop('disabled', true), parts = [], i = 0, keepM = VIO.m, keepD = VIO.data;
    (function next(){
        if (i >= months.length) {
            VIO.m = keepM; VIO.data = keepD;
            $b.prop('disabled', false);
            vioPrintLog(r, '全年');
            vioPrintOpen(r.item_no + '.' + r.name + ' ' + YEAR + ' 全年明細', parts.join(''));
            return;
        }
        var m = months[i++];
        $b.text('載入 ' + m + ' 月…');
        $.getJSON(API, {action:'detail_rows', indicator_id:r.indicator_id, year:YEAR, month:m}, function(res){
            if (res && res.ok && +res.supported) {
                VIO.m = m; VIO.data = res;                 // vioRowVisible() 讀的是 VIO
                parts.push('<div class="pg">' + vioPrintRowsHtml(res, r, m, r.cells[m]) + '</div>');
            }
            next();
        }).fail(function(){ next(); });
    })();
    $(this).text('列印明細');
});
$(document).on('click', '#vioDo', function(){
    var keys = $('#vioBody .vioChk:checked').map(function(){ return $(this).val(); }).get();
    // 已經排除過的不用再送一次
    var already = {};
    VIO.data.rows.forEach(function(x){ if (+x.excluded) already[String(x.key)] = 1; });
    keys = keys.filter(function(k){ return !already[k]; });
    if (!keys.length) { alert('請勾選要排除的項目（已排除的不用再勾一次）'); return; }
    var reason = $.trim($('#vioReason').val());
    if (!confirm('把勾選的 '+keys.length+' 筆排除在這個月的計算之外？\n（不會修改任何一筆真實資料）')) return;
    $.post(API, {action:'adjust_add', indicator_id:VIO.iid, year:YEAR, month:VIO.m,
                 keys:JSON.stringify(keys), reason:reason}, function(res){
        if (!res.ok) { alert(res.error||'排除失敗'); return; }
        alert('已排除 '+res.added+' 筆，這一格重算為 '+(res.value===null?'無資料':res.value)
              +'（'+res.num+'／'+res.den+'）。');
        openVio(VIO.ri, VIO.m);
        loadMatrix(true);
    }, 'json').fail(function(x){ alert('排除失敗：'+((x.responseJSON&&x.responseJSON.error)||x.status)); });
});
$(document).on('click', '#vioUndo', function(){
    var keys = $('#vioBody .vioChk:checked').map(function(){ return $(this).val(); }).get();
    var already = {};
    VIO.data.rows.forEach(function(x){ if (+x.excluded) already[String(x.key)] = 1; });
    keys = keys.filter(function(k){ return already[k]; });
    if (!keys.length) { alert('請勾選「已排除」的項目才能取消排除'); return; }
    $.post(API, {action:'adjust_del', indicator_id:VIO.iid, year:YEAR, month:VIO.m,
                 keys:JSON.stringify(keys)}, function(res){
        if (!res.ok) { alert(res.error||'取消失敗'); return; }
        alert('已取消排除 '+res.removed+' 筆，這一格重算為 '+(res.value===null?'無資料':res.value)+'。');
        openVio(VIO.ri, VIO.m);
        loadMatrix(true);
    }, 'json').fail(function(x){ alert('取消失敗：'+((x.responseJSON&&x.responseJSON.error)||x.status)); });
});
/* 直接修改來源資料（allow 模式）：改一個欄位就送一次，成功後整份重載並同步主表 */
$(document).on('change', '#vioBody .veF', function(){
    var $f = $(this), key = $f.attr('data-k'), field = $f.attr('data-f'), val = $f.val();
    if (val === '' && $f.is('select')) return;                 // 下拉的「（不變）」
    var lb = $f.closest('.ve-row').find('.ve-lb').text();
    if (!confirm('確定把這一筆的「'+lb+'」改成「'+(val||'（清空）')+'」？\n這會直接修改來源資料，並立刻重算這一格。')) {
        openVio(VIO.ri, VIO.m); return;
    }
    $f.prop('disabled', true);
    $.post(API, {action:'src_edit', indicator_id:VIO.iid, year:YEAR, month:VIO.m,
                 row_key:key, field:field, value:val}, function(res){
        if (!res.ok) { alert(res.error||'修改失敗'); openVio(VIO.ri, VIO.m); return; }
        alert('已修改（'+(res.old||'空白')+' → '+(res.new||'空白')+'）。這一格重算為 '
              + (res.value===null?'無資料':res.value) + '（'+res.num+'／'+res.den+'）。'
              + (res.also_recalced ? ('\n這一筆已改算到 '+res.also_recalced+'，該月份也重算了。') : ''));
        openVio(VIO.ri, VIO.m);
        loadMatrix(true);
    }, 'json').fail(function(x){
        alert('修改失敗：'+((x.responseJSON&&x.responseJSON.error)||x.status));
        openVio(VIO.ri, VIO.m);
    });
});
$(document).on('change', '#vioMode', function(){
    var mode = $(this).val();
    $.post(API, {action:'edit_mode_save', indicator_id:VIO.iid, mode:mode}, function(res){
        if (!res.ok) { alert(res.error||'設定失敗'); return; }
        openVio(VIO.ri, VIO.m);
    }, 'json').fail(function(x){ alert('設定失敗：'+((x.responseJSON&&x.responseJSON.error)||x.status)); });
});


/* ---------- 補登模式（使用者要求 2026-09-15：補舊年度資料要像 Excel 一樣直接填） ----------
   打開之後整張表的每一格變成輸入框，直接打字、Tab／Enter／↑↓ 移動，最後按一次「儲存」整批送出。
   寫入的是「手動覆寫」，但**不要求逐格填原因**（整批寫同一句說明；誰改的與時間照樣留下）。
   僅系統管理員可用；未來月份與該指標不適用的月份不給輸入。 */
var FILL = false, FILL_DIRTY = {};
function fillCanUse(){ return MATRIX && MATRIX.is_admin; }
function toggleFill(){
    if (!fillCanUse()) { alert('補登模式僅系統管理員可用'); return; }
    if (FILL && Object.keys(FILL_DIRTY).length) {
        if (!confirm('有 ' + Object.keys(FILL_DIRTY).length + ' 格還沒儲存，確定離開補登模式？')) return;
    }
    FILL = !FILL;
    FILL_DIRTY = {};
    $('#btnFill').toggleClass('on', FILL);
    $('#fillBar').toggle(FILL);
    renderTable();
}
function fillKey(iid, m){ return iid + '_' + m; }
/** 這一格在補登模式下能不能填 */
function fillEditable(r, m){
    var c = r.cells[m];
    return !!c && !c.future && !c.locked_month && r.months.indexOf(m) >= 0;
}
function fillCellHtml(r, ri, m){
    var c = r.cells[m];
    var v = (c.v === null || c.v === undefined) ? '' : c.v;
    var k = fillKey(r.indicator_id, m);
    if (k in FILL_DIRTY) v = FILL_DIRTY[k];
    var cls = 'fillIn' + (c.src === 'override' ? ' ov' : '') + ((k in FILL_DIRTY) ? ' dirty' : '');
    var at = ' data-i="' + r.indicator_id + '" data-m="' + m + '" data-ri="' + ri + '"';
    if (r.value_type === 'yesno') {
        // Yes/No 指標的值本來就不是數字，給下拉最不會填錯；游標停在上面直接按 Y／N 也選得到
        var sv = (v === '' || v === null) ? '' : (Number(v) >= 1 ? '1' : '0');
        return '<select class="' + cls + '"' + at + '>'
             + '<option value=""' + (sv === ''  ? ' selected' : '') + '>—</option>'
             + '<option value="1"' + (sv === '1' ? ' selected' : '') + '>Yes</option>'
             + '<option value="0"' + (sv === '0' ? ' selected' : '') + '>No</option></select>';
    }
    return '<input type="text" class="' + cls + '"' + at
         + ' value="' + esc(String(v)) + '" autocomplete="off">';
}
$(document).on('input change', '#kpiBody .fillIn', function(){
    var $i = $(this);
    FILL_DIRTY[fillKey($i.attr('data-i'), $i.attr('data-m'))] = $i.val();
    $i.addClass('dirty');
    $('#fillCount').text(Object.keys(FILL_DIRTY).length);
});
/* 點到已有資料的格子＝整個值選起來，直接打字就換掉（2026-09-15 使用者要求）。
   本頁不在共用檔 eg_input_rules.js 的涵蓋名單內（見 input_rules_baseline.txt），
   而且補登模式的 Enter／↑↓ 是這頁自己處理的，整支載進來會動到頁面其他輸入框的行為，
   所以這裡只針對補登格子做同一件事，寫法比照共用檔規則 2（延後執行並再確認焦點還在，
   否則 Chrome 會把焦點從別的欄位搶回來）。
   滑鼠點擊會在 focus 之後把游標放到點到的位置＝取消選取，所以要吃掉那一次 mouseup。 */
$(document).on('focus', '#kpiBody .fillIn', function(){
    var el = this;
    if (el.value === '') return;
    el.__selAll = true;
    setTimeout(function(){
        if (document.activeElement !== el) return;
        try { el.select(); } catch (err) {}
    }, 0);
});
$(document).on('mouseup', '#kpiBody .fillIn', function(e){
    if (this.__selAll) { e.preventDefault(); this.__selAll = false; }
});
$(document).on('blur', '#kpiBody .fillIn', function(){ this.__selAll = false; });

/* 鍵盤移動（2026-09-15 使用者指定）：
   Enter＝往右一格（同一列的下一個月份，到列尾接下一列開頭，跟 Excel 的 Tab 一樣）
   ↑↓＝同一個月份上下移動　　←→ 不攔截，留給游標在數字裡面移動 */
$(document).on('keydown', '#kpiBody .fillIn', function(e){
    var k = e.key;
    if (k !== 'Enter' && k !== 'ArrowDown' && k !== 'ArrowUp') return;
    e.preventDefault();
    if (k === 'Enter') {
        var all = $('#kpiBody .fillIn');          // DOM 順序＝同列由左到右、再換下一列
        var i = all.index(this);
        if (i >= 0 && i + 1 < all.length) all.eq(i + 1).focus().select();
        return;
    }
    var m = $(this).attr('data-m');
    var col = $('#kpiBody .fillIn[data-m="' + m + '"]');
    var idx = col.index(this);
    var to = (k === 'ArrowUp') ? idx - 1 : idx + 1;
    if (to >= 0 && to < col.length) { col.eq(to).focus().select(); }
});
function fillSave(){
    var keys = Object.keys(FILL_DIRTY);
    if (!keys.length) { alert('沒有變更'); return; }
    var bad = [];
    var vtype = {};
    MATRIX.rows.forEach(function(r){ vtype[r.indicator_id] = r.value_type; });
    var cells = keys.map(function(k){
        var p = k.split('_'), v = $.trim(String(FILL_DIRTY[k]));
        // Yes/No 指標收 Y／N／是／否（規則與後端同一份 YESNO），不是只收數字
        if (v !== '' && kpiParseInput(vtype[+p[0]], v) === null) bad.push(v);
        return {i:+p[0], m:+p[1], v:v};
    });
    if (bad.length) { alert('有 ' + bad.length + ' 格的值無法辨識：' + bad.slice(0,5).join('、')
        + '\n數字型請填數字；Yes/No 型請填 ' + YESNO.yes.slice(0,3).join('／') + ' 或 '
        + YESNO.no.slice(0,3).join('／') + '，也可以清空該格。'); return; }
    var note = $('#fillNote').val();
    if (!confirm('把 ' + cells.length + ' 格寫進 ' + YEAR + ' 年度？\n（寫入方式＝手動覆寫，不需要逐格填原因；空白的格子＝清除覆寫）')) return;
    NProgress.start();
    $.post(API, {action:'bulk_override', year:YEAR, note:note, cells:JSON.stringify(cells)}, function(res){
        NProgress.done();
        if (!res.ok) { alert(res.error||'儲存失敗'); return; }
        var msg = '已寫入 ' + res.saved + ' 格' + (res.cleared ? ('，清除 ' + res.cleared + ' 格') : '') + '。';
        if (res.skipped && res.skipped.length) msg += '\n略過 ' + res.skipped.length + ' 格：\n' + res.skipped.slice(0,8).join('\n');
        alert(msg);
        FILL_DIRTY = {};
        $('#fillCount').text('0');
        loadMatrix(true);
    }, 'json').fail(function(x){ NProgress.done(); alert('儲存失敗：'+((x.responseJSON&&x.responseJSON.error)||x.status)); });
}

/* ---------- 重算 ---------- */
function doRecalc(iid, month){
    $.post(API, {action:'recalc', indicator_id:iid, year:YEAR, month:month||0}, function(res){
        if (!res.ok) { alert(res.error||'重算失敗'); return; }
        loadMatrix();
    }, 'json').fail(function(x){ alert('重算失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}
$('#btnRecalcYear').on('click', function(){
    if (!confirm('重新計算 '+YEAR+' 年度所有自動指標的已結束月份？（覆寫值不受影響）')) return;
    var autos = MATRIX.rows.filter(function(r){ return r.can_recalc; });
    var done = 0;
    NProgress.start();
    (function next(){
        if (done >= autos.length) { NProgress.done(); loadMatrix(); return; }
        $.post(API, {action:'recalc', indicator_id:autos[done].indicator_id, year:YEAR, month:0}, function(){ done++; next(); }, 'json')
         .fail(function(){ done++; next(); });
    })();
});

/* ---------- 填寫 ---------- */
var fillCtx = null;
function openFill(ri, m){
    var r = MATRIX.rows[ri], c = r.cells[m];
    fillCtx = {iid: r.indicator_id, m: m, type: r.value_type};
    $('#fillTitle').text('填寫：'+r.item_no+'. '+r.name+'（'+YEAR+'年'+m+'月）');
    if (r.value_type === 'yesno') {
        $('#fillYesNoBox').show(); $('#fillNumBox').hide();
        $('#fillYesNo').val(c.v !== null && c.v >= 1 ? '1' : (c.v === null ? '1' : '0'));
    } else {
        $('#fillYesNoBox').hide(); $('#fillNumBox').show();
        $('#fillValueLabel').text('數值' + (r.target.unit ? '（'+r.target.unit+'）' : ''));
        $('#fillValue').val(c.v === null ? '' : c.v);
    }
    $('#fillNote').val(c.note || '');
    openMask('fillMask');
    setTimeout(function(){ (r.value_type==='yesno' ? $('#fillYesNo') : $('#fillValue')).focus().select(); }, 100);
}
function submitFill(){
    var v = fillCtx.type === 'yesno' ? $('#fillYesNo').val() : $('#fillValue').val();
    if (v === '') { alert('請輸入數值'); return; }
    $.post(API, {action:'fill', indicator_id:fillCtx.iid, year:YEAR, month:fillCtx.m, value:v, note:$('#fillNote').val()},
        function(res){
            if (!res.ok) { alert(res.error||'儲存失敗'); return; }
            closeMask('fillMask'); loadMatrix();
        }, 'json').fail(function(x){ alert('儲存失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}
function doClearFill(iid, m){
    if (!confirm('清除此月份的填寫內容？(季/半年/年度指標可改填其他月份)')) return;
    $.post(API, {action:'clear_fill', indicator_id:iid, year:YEAR, month:m}, function(res){
        if (!res.ok) { alert(res.error||'失敗'); return; }
        loadMatrix();
    }, 'json');
}

/* ---------- 覆寫 ---------- */
var ovCtx = null;
function openOverride(ri, m){
    var r = MATRIX.rows[ri], c = r.cells[m];
    ovCtx = {iid: r.indicator_id, m: m, type: r.value_type};
    $('#ovTitle').text('手動覆寫：'+r.item_no+'. '+r.name+'（'+YEAR+'年'+m+'月）');
    $('#ovOrigInfo').text('目前顯示值：' + (c.v===null?'?':fmtVal(c.v, r.value_type)) + '（覆寫後原值保留可追溯）');
    // Yes/No 指標的覆寫值原本是數字框＝根本填不進去（打 Y 被瀏覽器吃掉、送出只會說請輸入覆寫值）
    var isYN = (r.value_type === 'yesno');
    $('#ovYesNoBox').toggle(isYN); $('#ovNumBox').toggle(!isYN);
    if (isYN) $('#ovYesNo').val(c.v !== null && c.v >= 1 ? '1' : '0');
    else      $('#ovValue').val(c.src==='override' && c.v!==null ? c.v : '');
    $('#ovReason').val('');
    openMask('ovMask');
    setTimeout(function(){ (isYN ? $('#ovYesNo') : $('#ovValue')).focus().select(); }, 100);
}
function submitOverride(){
    var ovv = ovCtx.type === 'yesno' ? $('#ovYesNo').val() : $('#ovValue').val();
    if (ovv === '') { alert('請輸入覆寫值'); return; }
    if ($.trim($('#ovReason').val()) === '') { alert('覆寫原因必填'); return; }
    $.post(API, {action:'override', indicator_id:ovCtx.iid, year:YEAR, month:ovCtx.m,
                 value:ovv, reason:$('#ovReason').val()},
        function(res){
            if (!res.ok) { alert(res.error||'覆寫失敗'); return; }
            closeMask('ovMask'); loadMatrix();
        }, 'json').fail(function(x){ alert('覆寫失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}
function doClearOverride(iid, m){
    if (!confirm('清除此格的手動覆寫，恢復顯示原值？')) return;
    $.post(API, {action:'clear_override', indicator_id:iid, year:YEAR, month:m}, function(res){
        if (!res.ok) { alert(res.error||'失敗'); return; }
        loadMatrix();
    }, 'json');
}

/* ---------- 附件 ---------- */
var attCtx = null;
function openAttach(iid, month, row){
    attCtx = {iid: iid, m: month};
    $('#attPickBox').hide();
    $('#attTitle').text('佐證附件：'+(row ? row.item_no+'. '+row.name : '')+'（'+YEAR+'年'+month+'月）');
    var mine = (META.my_indicators||[]).some(function(mi){ return mi.indicator_id === iid; });
    $('#attUpBox').toggle(mine || META.perms.canAdmin);
    refreshAttList();
    openMask('attMask');
}
$('#btnUpload').on('click', function(){
    var list = META.my_indicators || [];
    if (!list.length) { alert('您目前沒有可填寫/上傳佐證的 KPI 項目（僅指標擔當者與管理者可上傳）'); return; }
    var $s = $('#attIndSel').empty();
    list.forEach(function(mi){ $s.append('<option value="'+mi.indicator_id+'">'+mi.item_no+'. '+esc(mi.name)+'</option>'); });
    $('#attPickBox').show();
    $('#attUpBox').show();
    $('#attTitle').text('上傳佐證附件（'+YEAR+'年）');
    fillAttMonths();
    attCtx = null;
    refreshAttList();
    openMask('attMask');
});
function fillAttMonths(){
    var iid = +$('#attIndSel').val();
    var mi = (META.my_indicators||[]).find(function(x){ return x.indicator_id === iid; });
    var $m = $('#attMonthSel').empty();
    (mi ? mi.months : [1,2,3,4,5,6,7,8,9,10,11,12]).forEach(function(m){
        if (YEAR === META.cur_year && m > META.cur_month) return;
        $m.append('<option value="'+m+'">'+m+'月</option>');
    });
    if (YEAR === META.cur_year && $m.find('option').length) $m.val($m.find('option').last().val());
    refreshAttList();
}
$('#attIndSel').on('change', fillAttMonths);
$('#attMonthSel').on('change', refreshAttList);
function curAttCell(){
    if (attCtx) return attCtx;
    return {iid: +$('#attIndSel').val(), m: +$('#attMonthSel').val()};
}
function refreshAttList(){
    var c = curAttCell();
    if (!c.iid || !c.m) { $('#attList').html('<span style="color:#8a6d45;font-size:12px;">請選擇指標與月份</span>'); return; }
    $.getJSON(API, {action:'attach_list', indicator_id:c.iid, year:YEAR, month:c.m}, function(res){
        if (!res.ok) { $('#attList').html(esc(res.error||'載入失敗')); return; }
        $('#attLimitTxt').text('每月每項上限 '+res.max+' 件，單檔 20MB');
        if (!res.list.length) { $('#attList').html('<span style="color:#8a6d45;font-size:12px;">尚無附件</span>'); return; }
        var h = '';
        res.list.forEach(function(a){
            h += '<div class="att-row">';
            h += a.exists
               ? '<span class="att-name" title="開啟" onclick="window.open(API+\'?action=attach_open&attach_id='+a.attach_id+'\')">📄 '+esc(a.original_name)+'</span>'
               : '<span class="att-name att-missing" title="檔案不存在(NAS路徑可能已變更)">📄 '+esc(a.original_name)+'</span>';
            h += '<span class="att-note" title="'+esc(a.note||'')+'">'+esc(a.note||'')+'</span>';
            h += '<span style="color:#8a6d45;font-size:11px;">'+esc(a.uploaded_by_name||'')+' '+esc((a.created_at||'').substr(5,11))+'</span>';
            if (a.can_delete) h += '<span class="att-del" title="刪除" onclick="delAttach('+a.attach_id+')"><i class="fa fa-trash"></i></span>';
            h += '</div>';
        });
        $('#attList').html(h);
    });
}
/* 一次多選上傳（2026-09-15 使用者要求）：後端一支請求收一個檔，所以前端逐檔送、
   全部送完才回報。**送出當下直接讀 input.files**（不靠 change 事件記住檔案，
   見記憶 file_upload_change_event），中途失敗的那幾檔會個別列出來、不影響已成功的。 */
function submitAttach(){
    var c = curAttCell();
    var f = document.getElementById('attFile');
    if (!c.iid || !c.m) { alert('請選擇指標與月份'); return; }
    var files = f.files ? Array.prototype.slice.call(f.files) : [];
    if (!files.length) { alert('請選擇檔案'); return; }
    var note = $('#attNote').val();
    var okN = 0, errs = [];
    NProgress.start();
    (function next(i){
        if (i >= files.length) {
            NProgress.done();
            f.value = ''; $('#attNote').val('');
            refreshAttList(); loadMatrix();
            if (errs.length) alert('成功 ' + okN + ' 個，失敗 ' + errs.length + ' 個：\n' + errs.join('\n'));
            else if (okN > 1) alert('已上傳 ' + okN + ' 個檔案。');
            return;
        }
        var fd = new FormData();
        fd.append('action', 'attach_upload');
        fd.append('indicator_id', c.iid);
        fd.append('year', YEAR);
        fd.append('month', c.m);
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
        if (!res.ok) { alert(res.error||'刪除失敗'); return; }
        refreshAttList(); loadMatrix();
    }, 'json');
}

/* ---------- 前端試算（開放參數） ---------- */
function toggleSim(ri, ev){
    if (ev) ev.stopPropagation();
    var $row = $('#simRow'+ri);
    if ($row.is(':visible')) { $row.hide(); loadMatrix(); return; }
    var r = MATRIX.rows[ri];
    var h = '<div class="kpi-sim-bar"><b><i class="fa fa-sliders"></i> 試算</b>';
    r.exposed_params.forEach(function(p){
        var val = Array.isArray(p.value) ? p.value.join(',') : (p.value === null || typeof p.value === 'object' ? '' : p.value);
        h += '<label>'+esc(p.label)+'</label><input data-key="'+esc(p.key)+'" data-type="'+esc(p.type)+'" value="'+esc(val)+'">';
    });
    h += '<button onclick="runSim('+ri+')">試算</button>';
    if (MATRIX.can_admin) h += '<button style="background:#DD5138;border-color:#b53c28;" onclick="applySim('+ri+')" title="把目前試算參數寫回本年度設定並重算">套用修改</button>';
    h += '<button style="background:#fff;color:#5b3a1e;border-color:#D8BE93;" onclick="toggleSim('+ri+')">還原</button>';
    h += '<span style="color:#b5762a;">試算僅預覽；「套用修改」才會寫回本年度設定</span></div>';
    $row.show().find('td').html(h);
}
function runSim(ri){
    var r = MATRIX.rows[ri];
    var params = {};
    $('#simRow'+ri+' input').each(function(){
        var k = $(this).data('key'), t = $(this).data('type'), v = $.trim($(this).val());
        if (t === 'int' || t === 'num') params[k] = v === '' ? 0 : +v;
        else if (t === 'bool') params[k] = v === '1' || v === 'true' ? 1 : 0;
        else params[k] = v; // textlist/intlist/statuslist：後端以逗號切分
    });
    NProgress.start();
    $.getJSON(API, {action:'preview', indicator_id:r.indicator_id, year:YEAR, params:JSON.stringify(params)}, function(res){
        NProgress.done();
        if (!res.ok) { alert(res.error||'試算失敗'); return; }
        var $tr = $('tr[data-ri="'+ri+'"]');
        r.months.forEach(function(m){
            var $td = $tr.find('td[data-m="'+m+'"]');
            if (!$td.length) return;
            var pv = res.months[m];
            if (pv === null || pv === undefined) return;
            $td.html('<span class="kpi-preview" title="試算值 分子='+pv.num+' 分母='+pv.den+'">'+fmtVal(pv.v, r.value_type)+'</span>');
        });
    }).fail(function(){ NProgress.done(); alert('試算失敗'); });
}
function collectSimParams(ri){
    var params = {};
    $('#simRow'+ri+' input').each(function(){
        var k = $(this).data('key'), t = $(this).data('type'), v = $.trim($(this).val());
        if (t === 'int' || t === 'num') params[k] = v === '' ? 0 : +v;
        else if (t === 'bool') params[k] = v === '1' || v === 'true' ? 1 : 0;
        else params[k] = v;
    });
    return params;
}
function applySim(ri){
    var r = MATRIX.rows[ri];
    if (!confirm('把第 '+r.item_no+' 項目前試算的參數寫回 '+YEAR+' 年度設定，並重算已結束月份？')) return;
    NProgress.start();
    $.post(API, {action:'apply_params', indicator_id:r.indicator_id, year:YEAR, params:JSON.stringify(collectSimParams(ri))}, function(res){
        NProgress.done();
        if (!res.ok) { alert(res.error||'套用失敗'); return; }
        alert(res.changed > 0 ? ('已套用並重算（更新 '+res.changed+' 個參數）') : '參數無變更');
        loadMatrix();
    }, 'json').fail(function(x){ NProgress.done(); alert('套用失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}

/* ---------- 趨勢圖 ---------- */
var chartSel = null;
function renderChartPicks(){
    if (chartSel === null) {
        chartSel = {};
        MATRIX.rows.filter(function(r){ return r.source_mode==='auto'; }).slice(0,4)
            .forEach(function(r){ chartSel[r.indicator_id] = true; });
    }
    var h = '';
    MATRIX.rows.forEach(function(r){
        if (r.value_type === 'yesno') return;
        h += '<label><input type="checkbox" data-iid="'+r.indicator_id+'"'+(chartSel[r.indicator_id]?' checked':'')+'> '
           + r.item_no+'.'+esc(r.name)+'</label>';
    });
    $('#chartPicks').html(h);
    $('#chartPicks input').on('change', function(){
        chartSel[+$(this).data('iid')] = this.checked;
        renderChart();
    });
}
var warmColors = ['#F0A24B','#DD5138','#B5762A','#8A5A2B','#E8C170','#C98A5E','#A0522D','#D2A24C'];
function renderChart(){
    var series = [];
    var ci = 0;
    MATRIX.rows.forEach(function(r){
        if (!chartSel[r.indicator_id] || r.value_type === 'yesno') return;
        var data = [];
        for (var m=1; m<=12; m++){
            if (r.months.indexOf(m) < 0) { data.push(null); continue; }
            var c = r.cells[m];
            data.push(c.future || c.v === null ? null : +c.v);
        }
        series.push({name:r.item_no+'.'+r.name, data:data, color:warmColors[ci++ % warmColors.length]});
    });
    Highcharts.chart('kpiChart', {
        chart:{type:'line', backgroundColor:'transparent'},
        title:{text:null}, credits:{enabled:false},
        xAxis:{categories:['1月','2月','3月','4月','5月','6月','7月','8月','9月','10月','11月','12月']},
        yAxis:{title:{text:null}},
        tooltip:{shared:true},
        plotOptions:{line:{connectNulls:false, marker:{enabled:true, radius:3}}},
        series: series.length ? series : [{name:'（勾選上方指標）', data:[]}]
    });
}

/* ---------- 匯出 CSV ---------- */
$('#btnCsv').on('click', function(){
    if (!MATRIX) return;
    var rows = [['項次','指標內容','對應條文','擔當者','頻率','統計方式','判定目標',
                 '1月','2月','3月','4月','5月','6月','7月','8月','9月','10月','11月','12月','平均','去年平均']];
    MATRIX.rows.forEach(function(r){
        var line = [r.item_no, r.name, r.clause||'', r.owner||'', freqName(r.freq), r.stat_desc||'', r.target.text||''];
        for (var m=1; m<=12; m++){
            if (r.months.indexOf(m) < 0) { line.push('—'); continue; }
            var c = r.cells[m];
            line.push(c.future ? 'NA' : (c.v===null ? '?' : fmtVal(c.v, r.value_type) + (c.src==='override'?'*':'') + (c.src==='preview'?'(試算)':'')));
        }
        var avgTxt = r.value_type==='yesno' ? '' : (r.avg===null?'':fmtVal(r.avg, r.value_type));
        line.push(avgTxt, r.prev_avg===null?'':fmtVal(r.prev_avg, r.value_type));
        rows.push(line);
    });
    var csv = '﻿' + rows.map(function(l){
        return l.map(function(v){ return '"'+String(v==null?'':v).replace(/"/g,'""')+'"'; }).join(',');
    }).join('\r\n');
    var a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([csv], {type:'text/csv;charset=utf-8;'}));
    a.download = 'KPI_2-GM-04-01_' + YEAR + '.csv';
    a.click();
});

/* ---------- 列印（ai-rules/16：大標題本公司名/表頭綁定AS文件表單名稱/頁碼左下/AS文件編號右下；
   並按使用者選定紙張(A4/A3)自動縮放整份內容到一頁，見 printKpi()） ---------- */
function renderPrintHead(){
    if (!META) return;
    var docName = (META.as_doc && META.as_doc.doc_name) ? META.as_doc.doc_name : 'KPI 關鍵績效指標總覽（2-GM-04-01）';
    $('#kpiPrintHead .kpi-print-comp').text(META.company || '');
    $('#kpiPrintHead .kpi-print-title').text(docName);
    $('#kpiPrintHead .kpi-print-sub').text(YEAR + ' 年度｜列印日期：' + new Date().toISOString().substr(0,10));
}
var KPI_PAPER_MM = { A4:{w:297,h:210}, A3:{w:420,h:297} }; // 統一用橫式（19欄較容易排下）
/* 列印邊界（mm）——縮放計算與 @page 一定要用同一組數字，寫死兩份遲早對不起來 */
var KPI_MARGIN_MM = { top:14, side:14, bottom:16 };
function kpiMmToPx(mm){ return mm * 96 / 25.4; }
function applyPrintPageStyle(paper, asDocNo){
    var size = KPI_PAPER_MM[paper] || KPI_PAPER_MM.A4;
    var asTxt = String(asDocNo||'').replace(/['\\]/g, '');
    var mg = KPI_MARGIN_MM;
    var css = '@page{size:' + paper + ' landscape;margin:'
        + mg.top + 'mm ' + mg.side + 'mm ' + mg.bottom + 'mm;'
        + (asTxt ? " @bottom-right{content:'" + asTxt + "';font-size:9pt;color:#333;}" : '')
        + " @bottom-left{content:'第 ' counter(page) ' 頁／共 ' counter(pages) ' 頁';font-size:9pt;color:#333;}"
        + '}';
    var $st = $('#kpiPageStyle');
    if (!$st.length) $st = $('<style id="kpiPageStyle"></style>').appendTo('head');
    $st.text(css);
    return size;
}
/* 目前的縮放之下，列印內容塞不塞得進一頁？（量的是「套用縮放之後」的實際版面） */
function kpiPrintFits(pageWpx, pageHpx){
    var t = document.getElementById('kpiTable');
    var ph = document.getElementById('kpiPrintHead');
    if (!t) return true;
    // getBoundingClientRect() 會反映 zoom 之後的實際尺寸
    var w = t.getBoundingClientRect().width;
    var h = t.getBoundingClientRect().height + ((ph && ph.getBoundingClientRect) ? ph.getBoundingClientRect().height : 0) + 8;
    return (w <= pageWpx + 0.5) && (h <= pageHpx + 0.5);
}
function printKpi(){
    if (!MATRIX) return;
    renderPrintHead();
    var paper = $('#kpiPaperSel').val() || 'A4';
    var size = applyPrintPageStyle(paper, META && META.as_doc_no);
    document.body.classList.add('kpi-printing');
    document.body.style.zoom = 1;
    // 量測「即將列印」版面（此時工具列/圖表已被 body.kpi-printing 隱藏、表頭已顯示）算出縮放比例，塞滿選定紙張的一頁
    var natW = document.getElementById('kpiTable').scrollWidth;
    // 高度不可以拿 .right_col.scrollHeight——Gentelella 的 .right_col 有 min-height 撐著，
    // 實測內容只有 424px 卻量到 1296px，縮放比例因此被壓到 0.52，列印出來字小得看不清楚
    // （2026-09-15 使用者回報）。改成量「列印表頭＋表格」的實際高度。
    var _ph = document.getElementById('kpiPrintHead');
    var natH = (_ph && _ph.offsetHeight ? _ph.offsetHeight : 0)
             + document.getElementById('kpiTable').offsetHeight + 8;
    var safeMm = 4; // 印表機不可印邊界安全值（邊界已經拉大，這裡不必再扣太多）
    var pageWpx = kpiMmToPx(size.w - KPI_MARGIN_MM.side * 2 - safeMm);
    var pageHpx = kpiMmToPx(size.h - KPI_MARGIN_MM.top - KPI_MARGIN_MM.bottom - safeMm);
    var scale = Math.min(pageWpx / natW, pageHpx / natH);
    scale = Math.max(0.35, Math.min(scale, 2.5));
    // 【一定要套上去之後再量一次】使用者回報列印還是超過邊界（實測輸出 PDF 是 2 頁）：
    // 先量再乘上比例只是「估」——zoom 會改變版面（字級、換行、欄寬都會變），
    // 實際高度常常不等於「原高度 × 比例」。所以套上去之後重新量，還超出就再縮一點，
    // 最多試 10 次；這是唯一能保證真的塞得進一頁的做法。
    document.body.style.zoom = scale;
    var fit = kpiPrintFits(pageWpx, pageHpx);
    for (var i = 0; i < 10 && !fit && scale > 0.35; i++) {
        scale = Math.max(0.35, scale * 0.94);
        document.body.style.zoom = scale;
        fit = kpiPrintFits(pageWpx, pageHpx);
    }
    setTimeout(function(){ window.print(); }, 100);
}
window.addEventListener('afterprint', function(){
    document.body.style.zoom = '';
    document.body.classList.remove('kpi-printing');
});

/* ---------- 事件 ---------- */
$('#yearSel').on('change', function(){ YEAR = +this.value; chartSel = null; loadMeta(function(){ loadMatrix(); }); });
$('#btnRoleHelp').on('click', function(){ openMask('helpMask'); });
$('#btnPageHelp').on('click', function(){ openMask('helpUseMask'); });
$('.kpi-modal-mask').on('click', function(e){ if (e.target === this) this.style.display='none'; });
// 輸入欄位規則：聚焦全選、雙擊清空、Enter 送出
$(document).on('focus', '.kpi-modal input[type=text], .kpi-modal input[type=number]', function(){ this.select(); });
$(document).on('dblclick', '.kpi-modal input[type=text], .kpi-modal input[type=number]', function(){ this.value=''; });
$(document).on('keydown', '#fillMask input', function(e){ if (e.key==='Enter') submitFill(); });
$(document).on('keydown', '#ovMask input', function(e){ if (e.key==='Enter') submitOverride(); });

if (canView) loadMeta(function(){ loadMatrix(); });
</script>
</body>
</html>
