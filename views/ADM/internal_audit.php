<?php
/**
 * 內部稽核（2-GM-06）— 一頁控管整個內稽流程（2026-08-25 建立）
 * 六個分頁：總覽／年度計畫(06-01)／稽核案件·通知單(06-02)／查檢表(06-03·04·06)／
 *           不符合通知單(06-07)／稽核報告表(06-08)
 * 會議紀錄不重複建立：按鈕自動建 meeting_record 草稿後，新分頁開 meeting_record.php?id=
 * 資料一律走 src/store/InternalAudit_API.php；共用邏輯 src/common/internal_audit_lib.php
 */
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/ADM/internal_audit.php";
    header("Location:../../index.php");
    exit;
}
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/internal_audit_lib.php';

$db = (new DBConnection())->getPDO();
ia_ensure_schema($db);
$iaUser = ia_current_user($db);
$perms  = ia_perms($db, $iaUser);
$roleLabel = ia_role_label($perms);
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>內部稽核</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <style>
        #sidebar-menu { visibility: hidden; }
        .right_col .page-title { margin:8px 0 4px; overflow:hidden; clear:both; }
        .page-help-btn { height:30px; font-size:13px; padding:0 12px; border:1px solid #d98a33; border-radius:15px;
            background:#F0A24B; color:#fff; cursor:pointer; }
        .page-help-btn:hover { background:#d98a33; }
        @media print { .page-help-btn { display:none !important; } }
        .help-doc { font-size:13px; color:#5b3a1e; line-height:1.75; }
        .help-doc h4 { color:#8A5A2B; border-bottom:2px solid #F7E0BD; padding-bottom:3px; margin:14px 0 6px; font-size:15px; }
        .help-doc h4:first-child { margin-top:0; }
        .help-doc b { color:#8A5A2B; }
        .help-doc ul { padding-left:20px; margin:4px 0; }

        /* ---- 分頁 ---- */
        .ia-tabs { display:flex; flex-wrap:wrap; gap:4px; border-bottom:2px solid #E0BE86; margin:6px 0 10px; clear:both; }
        .ia-tab { padding:7px 16px; font-size:14px; color:#8a6d45; background:#FBF5EA; border:1px solid #E8D5B5;
            border-bottom:none; border-radius:6px 6px 0 0; cursor:pointer; margin-bottom:-2px; }
        .ia-tab:hover { background:#F7E0BD; }
        .ia-tab.on { background:#F0A24B; color:#fff; border-color:#d98a33; font-weight:bold; }
        .ia-pane { display:none; }
        .ia-pane.on { display:block; }

        /* ---- 工具列 ---- */
        .ia-toolbar { display:flex; flex-wrap:wrap; gap:6px; align-items:center; clear:both;
            border:1.5px solid #E8D5B5; border-radius:8px; padding:8px 10px; margin-bottom:10px; background:#FDF8EF; }
        .ia-toolbar label { margin:0; font-size:13px; color:#5b3a1e; }
        /* 年度狀態（2026-09-21 使用者回報：這段字太長，把工具列的按鈕擠到下一列）。
           ①工具列上只放**短標**，細節移到工具列下方自己的一列（#yearStatMore），怎麼長都不會動到按鈕
           ②短標仍設成「可以被壓縮」＝不會把 flex 撐開：min-width:0＋overflow hidden＋ellipsis，
             萬一哪天文字又變長，它只會被截斷，不會把按鈕推下去 */
        #yearStatBar { clear:both; margin:-4px 0 10px; font-size:12.5px; }
        #yearStatBar #yearStat { display:inline-block; padding:0 2px; }
        #yearStatMore { display:none; margin-top:5px; padding:7px 12px;
            border:1px solid #E8D5B5; border-left:4px solid #d98a33; border-radius:6px;
            background:#FDF6EA; color:#6b4a20; line-height:1.9; }
        #yearStatMore b { color:#5b3a1e; }
        #yearStat .ys-more { margin-left:6px; cursor:pointer; text-decoration:underline; color:#a0703a; }
        .ia-toolbar select, .ia-toolbar input, .ia-toolbar button {
            height:30px; font-size:13px; line-height:1; padding:0 10px; border:1px solid #D8BE93;
            border-radius:4px; background:#fff; color:#5b3a1e; }
        .ia-toolbar button { cursor:pointer; }
        .ia-toolbar button:hover { background:#F7E0BD; }
        .ia-toolbar .btn-warm { background:#F0A24B; color:#fff; border-color:#d98a33; }
        .ia-toolbar .btn-warm:hover { background:#d98a33; }
        .ia-toolbar .btn-danger { background:#DD5138; border-color:#C4442D; color:#fff; }
        .ia-toolbar .btn-danger:hover { background:#C4442D; }
        .ia-role-badge { margin-left:auto; font-size:13px; color:#5b3a1e; background:#F7E0BD; border-radius:12px; padding:4px 12px; }

        /* ---- 表格 ---- */
        .ia-table-wrap { overflow-x:auto; border:1px solid #E8D5B5; border-radius:6px; background:#fff; }
        table.ia-table { width:100%; border-collapse:collapse; font-size:13px; }
        table.ia-table th, table.ia-table td { border:1px solid #EADFC8; padding:5px 8px; text-align:center; }
        table.ia-table thead th { background:#F7E0BD; color:#5b3a1e; font-weight:bold; white-space:nowrap; }
        table.ia-table tbody tr:nth-child(even) { background:#FBF6EC; }
        table.ia-table td.l { text-align:left; }
        table.ia-table tr.hdr-row td { background:#F3E4C9; font-weight:bold; text-align:left; color:#6b4a20; }
        /* 查檢表跳窗：表頭凍結（2026-09-21 使用者要求；系統稽核紀錄表動輒幾十列，捲下去就看不出欄位是什麼）。
           ①捲動容器一定要是「表格自己那一層」——直接讓 .ia-mbody 捲的話，sticky 只黏在它 padding box 的邊，
             列會從上方那 14px 內距穿過表頭（QC 待驗清單踩過同一個坑）。
           ②border-collapse 的框線不會跟著 sticky 走，捲動時上下框線會消失，改用 inset box-shadow 補回來。 */
        #checkMask .ia-mbody { display:flex; flex-direction:column; }
        #checkMask .ia-table-wrap { flex:1 1 auto; min-height:180px; overflow:auto; }
        #checkMask table.ia-table thead th { position:sticky; top:0; z-index:3;
            box-shadow:inset 0 1px 0 #EADFC8, inset 0 -1px 0 #EADFC8; }
        .ia-op { color:#b5762a; cursor:pointer; margin:0 4px; white-space:nowrap; }
        .ia-op:hover { color:#8A5A2B; text-decoration:underline; }
        .ia-op.danger { color:#C4442D; }
        .ia-pager { display:flex; justify-content:flex-end; align-items:center; gap:6px; margin:6px 0; font-size:13px; color:#5b3a1e; }
        /* 條文題庫拖曳排序（2026-08-26 使用者要求：拖移後自動更新順序） */
        .cl-drag { cursor:grab; color:#b5762a; text-align:center; white-space:nowrap; user-select:none; }
        .cl-drag:active { cursor:grabbing; }
        .cl-seq { color:#8a6d45; font-size:12px; }
        tr.cl-dragging { opacity:.45; background:#FDF3E3; }
        #clauseBody tr[draggable="true"]:hover .cl-drag { color:#8A5A2B; }
        /* 條文的作業項目欄（2026-09-11）：項目設在 AS 文件管理，這裡只勾「這一條對應到哪幾個」 */
        .cl-task-doc { font-size:11px; color:#8a6d45; font-weight:bold; margin:3px 0 1px; }
        .cl-task-chk { display:inline-block; font-size:12px; color:#5b3a1e; font-weight:normal;
            background:#FBF5EA; border:1px solid #E8D5B5; border-radius:10px; padding:1px 8px; margin:0 3px 3px 0; cursor:pointer; }
        .cl-task-chk input { margin-right:2px; vertical-align:-1px; }
        .cl-task-hint { font-size:11px; color:#b0a390; margin:2px 0; }
        /* 自動儲存的狀態字（2026-09-11：拿掉「存」按鈕後，要看得出來到底存了沒） */
        .cl-st { font-size:11px; color:#7a9a5e; margin-top:2px; min-height:14px; line-height:14px; }
        .cl-st.bad { color:#C4442D; font-weight:bold; }
        /* 打字模糊篩選的建議清單（相關表單編號／違反條文共用） */
        .ia-sug-wrap { position:relative; }
        .ia-sug { position:absolute; z-index:60; left:0; right:0; top:100%; max-height:230px; overflow-y:auto;
            background:#fff; border:1px solid #D8BE93; border-radius:0 0 5px 5px; box-shadow:0 6px 14px rgba(90,60,20,.18); }
        .ia-sug div { padding:5px 8px; font-size:12px; color:#5b3a1e; cursor:pointer; border-bottom:1px solid #F3E7D2; }
        .ia-sug .sug-extra { padding:1px 0 0; border:none; font-size:11px; color:#a08356; }
        .ia-sug div:last-child { border-bottom:0; }
        .ia-sug div:hover, .ia-sug div.on { background:#FDF3E3; }
        .ia-sug .no { color:#b5762a; font-weight:bold; margin-right:6px; }
        .ia-sug .empty { color:#b0a390; cursor:default; }
        /* AS 文件挑選跳窗 */
        #docPickBox { max-height:340px; overflow-y:auto; border:1px solid #EADFC8; border-radius:6px; padding:6px 8px; }
        #docPickBox label { display:flex; gap:6px; align-items:baseline; font-size:12px; color:#5b3a1e;
            font-weight:normal; margin:0 0 3px; cursor:pointer; line-height:1.5; }
        #docPickBox .dp-no { color:#b5762a; font-weight:bold; min-width:88px; }
        #docPickBox .dp-type { color:#8a6d45; font-size:11px; }
        .ia-pager button { height:26px; padding:0 9px; border:1px solid #D8BE93; background:#fff; border-radius:4px; cursor:pointer; }
        .ia-pager button.on { background:#F0A24B; color:#fff; border-color:#d98a33; }
        .ia-empty { padding:18px; color:#8a6d45; text-align:center; }

        /* ---- 狀態標籤（暖色系，ai-rules/10） ---- */
        .st { display:inline-block; padding:1px 9px; border-radius:10px; font-size:12px; white-space:nowrap; }
        .st-draft     { background:#EFE7D8; color:#6b5535; }
        .st-issued    { background:#F7E0BD; color:#8A5A2B; }
        .st-replied   { background:#F0C98A; color:#6b4a20; }
        .st-verified  { background:#F0A24B; color:#fff; }
        .st-closed    { background:#C9B18A; color:#fff; }
        .st-done      { background:#F0A24B; color:#fff; }
        .st-overdue   { background:#DD5138; color:#fff; }
        .st-major     { background:#DD5138; color:#fff; }
        .st-minor     { background:#F0A24B; color:#fff; }
        .st-observe   { background:#F7E0BD; color:#8A5A2B; }

        /* ---- 年度計畫格狀表 ---- */
        table.plan-grid { border-collapse:collapse; font-size:13px; background:#fff; }
        table.plan-grid th, table.plan-grid td { border:1px solid #D8BE93; padding:3px 6px; text-align:center; }
        table.plan-grid thead th { background:#F7E0BD; color:#5b3a1e; }
        table.plan-grid th.mon { width:66px; text-align:right; background:#FBF5EA; font-weight:normal; }
        table.plan-grid td.cell { width:66px; height:30px; cursor:pointer; font-size:16px; color:#8A5A2B; user-select:none; }
        table.plan-grid td.cell:hover { background:#FDF3E2; }
        table.plan-grid td.cell.ro { cursor:default; }
        table.plan-grid td.cell.ro:hover { background:transparent; }
        .plan-legend { font-size:13px; color:#5b3a1e; margin:8px 0; }
        .plan-legend b { color:#8A5A2B; }

        /* ---- 儀表板卡片 ---- */
        .ia-cards { display:flex; flex-wrap:wrap; gap:10px; margin-bottom:12px; }
        .ia-card { flex:1 1 170px; min-width:170px; border:1.5px solid #E8D5B5; border-radius:8px; background:#FDF8EF; padding:10px 14px; }
        .ia-card .t { font-size:13px; color:#8a6d45; }
        .ia-card .v { font-size:26px; font-weight:bold; color:#8A5A2B; line-height:1.3; }
        .ia-card .s { font-size:12px; color:#a08356; }
        .ia-card.warn { background:#FBEAE4; border-color:#E4A897; }
        .ia-card.warn .v { color:#C4442D; }

        /* ---- 跳窗 ---- */
        .ia-mask { display:none; position:fixed; inset:0; background:rgba(60,40,20,.45); z-index:9000; overflow:auto; }
        .ia-modal { background:#fff; border-radius:8px; margin:30px auto; max-width:900px; width:96%; box-shadow:0 8px 30px rgba(0,0,0,.3); }
        .ia-modal.narrow { max-width:560px; }
        .ia-modal.wide   { max-width:1180px; }
        .ia-mhead { padding:10px 16px; border-bottom:2px solid #F7E0BD; display:flex; align-items:center; }
        .ia-mhead h4 { margin:0; font-size:16px; color:#8A5A2B; }
        .ia-mhead .x { margin-left:auto; cursor:pointer; color:#a08356; font-size:20px; line-height:1; }
        .ia-mbody { padding:14px 16px; max-height:74vh; overflow:auto; }
        .ia-mfoot { padding:10px 16px; border-top:1px solid #EADFC8; text-align:right; background:#FDF8EF; border-radius:0 0 8px 8px; }
        .ia-mfoot button, .ia-mhead button { height:32px; font-size:13px; padding:0 14px; border:1px solid #D8BE93;
            border-radius:4px; background:#fff; color:#5b3a1e; cursor:pointer; margin-left:6px; }
        .ia-mfoot button.btn-warm { background:#F0A24B; color:#fff; border-color:#d98a33; }
        .ia-mfoot button.btn-danger { background:#DD5138; color:#fff; border-color:#C4442D; }

        /* ---- 表單欄位 ---- */
        .ia-form { display:grid; grid-template-columns:110px 1fr 110px 1fr; gap:8px 10px; align-items:center; font-size:13px; color:#5b3a1e; }
        .ia-form .full { grid-column:2 / span 3; }
        .ia-form .fullrow { grid-column:1 / span 4; }
        /* 措施內容 ＋ 它自己的完成日期並排一列（2026-09-21 使用者要求：日期要在措施右側，
           標題不可以被擠到隔壁格去）。.with-due 掛在 .full 上，所以整列從第 2 欄一路用到底。 */
        .ia-form .with-due { display:flex; gap:10px; align-items:flex-start; }
        .ia-form .with-due > .wd-main { flex:1 1 auto; min-width:0; }   /* min-width:0 才不會被 textarea 撐開 */
        .ia-form .with-due > .wd-due  { flex:0 0 200px; }
        .ia-form .with-due .wd-lab { font-size:12px; color:#6b5535; margin-bottom:3px; white-space:nowrap; }
        .ia-form label { margin:0; text-align:right; color:#6b5535; }
        .ia-form input[type=text], .ia-form input[type=date], .ia-form select, .ia-form textarea {
            width:100%; border:1px solid #D8BE93; border-radius:4px; padding:4px 8px; font-size:13px; color:#5b3a1e; background:#fff; }
        .ia-form textarea { min-height:64px; resize:vertical; line-height:1.6; }
        .ia-form input[readonly], .ia-form textarea[readonly], .ia-form select[disabled], .ia-form input[disabled] {
            background:#F2ECE0; color:#7a6444; }
        .ia-sec { border:1px solid #E8D5B5; border-radius:6px; padding:10px 12px; margin-bottom:12px; background:#FFFDF9; }
        .ia-sec > h5 { margin:0 0 8px; font-size:14px; color:#8A5A2B; border-bottom:1px solid #F0E0C4; padding-bottom:5px; }
        .ia-sec.locked { background:#F6F1E7; }
        .ia-sec .lock-note { float:right; font-size:12px; font-weight:normal; color:#a08356; }
        /* 表頭的「全部同一日期」小工具（受稽日期／預定完成改善） */
        .ia-allday { display:flex; gap:2px; margin-top:3px; font-weight:normal; }
        .ia-allday input[type=date] { flex:1 1 auto; min-width:0; border:1px solid #D8BE93; border-radius:3px;
                                      padding:1px 2px; font-size:11px; }
        .ia-allday button { flex:0 0 auto; border:1px solid #D8BE93; border-radius:3px; background:#fff;
                            font-size:11px; padding:0 5px; cursor:pointer; color:#8a6d45; }
        .ia-allday button:hover { background:#F7E0BD; }
        /* 稽核員／陪檢員可多位：已選的人做成標籤，下方下拉再加人（2026-08-27） */
        .ia-ppl { display:flex; flex-wrap:wrap; gap:3px; }
        .ia-ppl .ppl-chip { display:inline-flex; align-items:center; gap:4px; max-width:100%;
            background:#F7E0BD; border:1px solid #E0BE86; border-radius:10px;
            padding:1px 5px; font-size:12px; color:#6B4423; line-height:1.5; }
        .ia-ppl .ppl-chip .nm { overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .ia-ppl .ppl-chip .x { color:#C4442D; cursor:pointer; font-weight:bold; }
        .ia-ppl .ppl-chip.bad { background:#FBEAE4; border-color:#DD5138; }
        .ia-ppl .ppl-add { width:100%; border:1px dashed #D8BE93; border-radius:3px; font-size:12px;
            color:#8a6d45; background:#FFFDF9; }
        .ia-ppl .ppl-none { font-size:12px; color:#a08356; }
        .err-msg { color:#C4442D; font-size:12px; display:none; margin-top:2px; }
        .err-msg.on { display:block; }
        input.err, textarea.err, select.err { border-color:#DD5138 !important; background:#FDF1EE !important; }
        /* 稽核員／陪檢員是標籤不是輸入框，缺人時整格標紅 */
        #cDeptBody td.err-cell { background:#FDF1EE; box-shadow:inset 0 0 0 1px #DD5138; }
        .ia-hint { font-size:12px; color:#8a6d45; line-height:1.7; background:#FBF5EA; border:1px solid #EADFC8;
            border-left:4px solid #F0A24B; border-radius:5px; padding:7px 10px; margin-bottom:10px; }
        .ia-hint b { color:#8A5A2B; }
        .ia-proxy { background:#FBEAE4; border-left-color:#DD5138; }
        /* IA 單佐證附件（2026-09-21）。檔名可能很長，一律限寬截字、完整檔名掛 title。 */
        .ia-att { margin-top:8px; border-top:1px dashed #E0BE86; padding-top:6px; }
        .ia-att-h { font-size:12px; font-weight:bold; color:#8A5A2B; margin-bottom:4px; }
        .ia-att-h em { font-weight:normal; font-style:normal; color:#a08356; }
        .ia-att-list { display:flex; flex-wrap:wrap; gap:4px 8px; margin-bottom:5px; }
        .ia-att-item { display:inline-flex; align-items:center; gap:5px; max-width:330px;
                       border:1px solid #E0C79A; border-radius:4px; background:#FDF6EA;
                       padding:2px 7px; font-size:12px; line-height:19px; }
        .ia-att-item a { color:#8A5A2B; text-decoration:none; max-width:210px; overflow:hidden;
                         text-overflow:ellipsis; white-space:nowrap; }
        .ia-att-item a:hover { text-decoration:underline; }
        .ia-att-item .sz { color:#a08356; font-size:11px; }
        .ia-att-item .del { color:#C4442D; cursor:pointer; font-size:11px; }
        .ia-att-none { font-size:12px; color:#a08356; }
        .ia-att-up { display:flex; flex-wrap:wrap; align-items:center; gap:6px; font-size:12px; }
        .ia-att-up input[type=file] { font-size:12px; max-width:250px; }
        .ia-att-up input[type=text] { height:26px; font-size:12px; width:190px; padding:0 6px;
                                      border:1px solid #D8BE93; border-radius:4px; }
        .ia-att-up button { height:26px; font-size:12px; padding:0 10px; border:1px solid #D8BE93;
                            border-radius:4px; background:#fff; color:#5b3a1e; cursor:pointer; }
        .ia-att-up button:hover { background:#F7E0BD; }
        .ia-att-msg { font-size:12px; color:#C4442D; }
        .ia-log { font-size:12px; color:#7a6444; border-top:1px dashed #E0BE86; margin-top:8px; padding-top:6px; }
        .ia-log div { padding:1px 0; }
        .ia-log .proxy { color:#C4442D; }
        .pick-wrap { max-height:340px; overflow:auto; border:1px solid #E8D5B5; border-radius:5px; background:#fff; }
        .pick-wrap label { display:block; padding:4px 10px; margin:0; font-size:13px; color:#5b3a1e; cursor:pointer; border-bottom:1px solid #F3EADA; }
        .pick-wrap label:hover { background:#FDF3E2; }
        .pick-wrap label.hdr { background:#F3E4C9; font-weight:bold; color:#6b4a20; }
        /* 章節標題列＝分隔用，不是題目，所以沒有勾選框 */
        .pick-wrap .hdr-row { display:flex; align-items:center; gap:8px; padding:4px 10px; font-size:13px;
            font-weight:bold; color:#8a7355; background:#F7EFE0; border-bottom:1px solid #F3EADA; }
        .pick-wrap .hdr-row.on { color:#6b4a20; background:#F3E4C9; }
        .pick-wrap .hdr-row .hdr-n { margin-left:auto; font-weight:normal; font-size:11px; color:#a08356; }
        .pick-wrap .hdr-row.on .hdr-n { color:#8A5A2B; }
        /* 建立查檢表：作業項目標籤（點一下＝只勾這個用途的條文）與每一列的用途徽章 */
        .nk-chips { max-height:56px; overflow:hidden; margin-top:3px; }
        .nk-chips.open { max-height:190px; overflow-y:auto; }
        .nk-chip { display:inline-block; font-size:12px; color:#8A5A2B; background:#FBF5EA; border:1px solid #E8D5B5;
            border-radius:11px; padding:1px 10px; margin:0 4px 4px 0; cursor:pointer; user-select:none; }
        .nk-chip:hover { background:#F7E0BD; }
        .nk-chip.on { background:#F0A24B; color:#fff; border-color:#d98a33; font-weight:bold; }
        .nk-chip.clear { color:#C4442D; }
        .nk-task { display:inline-block; font-size:11px; color:#8A5A2B; background:#F7E0BD; border:1px solid #E8D5B5;
            border-radius:9px; padding:0 7px; margin-left:4px; }
        /* 建立查檢表：左右分割（左＝作業項目標籤依部門分類，右＝已選標籤＋題目清單）。
           2026-09-14 使用者回報：162 個標籤平鋪在上方「不方便閱讀」，且點完看不出自己選了什麼。 */
        .nk-split { display:flex; gap:10px; align-items:stretch; }
        .nk-side { flex:0 0 250px; width:250px; display:flex; flex-direction:column;
            border:1px solid #E8D5B5; border-radius:5px; background:#FFFCF6; overflow:hidden; }
        .nk-side-hd { flex:0 0 auto; padding:6px 8px; border-bottom:1px solid #EEDCC0; background:#F7EEDF; }
        .nk-side-hd b { color:#8A5A2B; font-size:13px; }
        .nk-side-hd input { width:100%; margin-top:4px; border:1px solid #D8BE93; border-radius:4px;
            padding:2px 6px; font-size:12px; }
        .nk-side-body { flex:1 1 auto; overflow-y:auto; padding:4px 6px 8px; }
        .nk-grp { margin-top:4px; }
        .nk-grp-hd { font-size:12px; font-weight:bold; color:#6b4a20; background:#F3E4C9; border-radius:4px;
            padding:2px 7px; cursor:pointer; user-select:none; display:flex; align-items:center; gap:4px; }
        .nk-grp-hd .n { margin-left:auto; font-weight:normal; color:#8a6d45; }
        .nk-grp-body { padding:4px 2px 2px; }
        .nk-main { flex:1 1 auto; min-width:0; display:flex; flex-direction:column; }
        .nk-sel { border:1px solid #E8D5B5; border-radius:5px; background:#FDF6EA; padding:5px 8px; margin-bottom:6px; }
        .nk-sel .lb { font-size:12px; color:#8a6d45; margin-right:4px; }
        .nk-sel .nk-chip { cursor:default; }
        .nk-sel .nk-chip .x { margin-left:5px; cursor:pointer; font-weight:bold; }
        /* 已選的作業項目各自掛在哪幾份文件（點了標籤看不出挑到哪張表單／程序書＝使用者回報） */
        .nk-seldoc { margin-top:5px; padding-top:5px; border-top:1px dashed #E8D5B5;
            font-size:12px; color:#7a6444; max-height:92px; overflow:auto; }
        .nk-seldoc b { color:#8A5A2B; }
        /* 右側「已選擇」欄（2026-09-17）：標籤只是聚焦用，選好的表單一律留在這裡，
           取消標籤不會把它們洗掉，使用者隨時看得到這次到底要建哪幾張表單。 */
        .nk-selside { flex:0 0 260px; width:260px; display:flex; flex-direction:column;
                      border:1px solid #E8D5B5; border-radius:5px; background:#FDF6EA; }
        .nk-selside-hd { flex:0 0 auto; padding:6px 8px; border-bottom:1px solid #EEDCC0; background:#F7EEDF; }
        .nk-selside-hd b { color:#8A5A2B; font-size:13px; }
        .nk-selside-hd .sub { font-size:11px; color:#a08356; display:block; margin-top:2px; }
        .nk-selside-body { flex:1 1 auto; overflow-y:auto; padding:4px 6px 8px; max-height:420px; }
        .nk-selrow { display:flex; gap:6px; align-items:flex-start; padding:4px 2px;
                     border-bottom:1px dashed #E8D5B5; font-size:12px; color:#5b3a1e; }
        .nk-selrow .tx { flex:1 1 auto; min-width:0; word-break:break-all; }
        .nk-selrow .no { font-weight:bold; color:#8A5A2B; }
        .nk-selrow .dp { display:block; color:#a08356; font-size:11px; }
        .nk-selrow .x  { flex:0 0 auto; color:#C4442D; cursor:pointer; font-weight:bold; }
        .nk-selside-ft { flex:0 0 auto; padding:5px 8px; border-top:1px solid #EEDCC0; font-size:12px; color:#8a6d45; }
        .pick-wrap label.dim { color:#b0a390; }
        .pick-wrap label.dim .nk-task { opacity:.55; }
        /* 勾選清單一律對齊：勾選框固定欄寬、名稱固定欄寬、右側說明自己一欄，
           不用全形空白做縮排（那會讓每一列的文字起點都不一樣，看起來歪七扭八） */
        .pick-wrap label.pick-row { display:flex; align-items:center; gap:8px; }
        .pick-wrap label.pick-row > input[type=checkbox] { flex:0 0 14px; margin:0; }
        .pick-wrap label.pick-row .pk-name { flex:0 0 150px; }
        .pick-wrap label.pick-row .pk-sub { flex:1 1 auto; color:#a08356; font-size:12px;
            white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .pick-wrap label.pick-row .pk-tag { flex:0 0 auto; font-size:11px; color:#8A5A2B;
            background:#F7E0BD; border-radius:8px; padding:0 7px; }
        .ia-top { position:fixed; right:24px; bottom:24px; width:40px; height:40px; border-radius:20px; background:#F0A24B;
            color:#fff; border:none; cursor:pointer; display:none; z-index:100; }
        .ia-noperm { border:1.5px solid #E4A897; background:#FBEAE4; border-radius:8px; padding:16px; color:#8A3A28; }
    </style>
</head>
<body class="nav-sm">
<div class="container body">
<div class="main_container">
    <?php include '../partPage/sideAndTopBarMenu.html' ?>
    <div class="right_col" role="main">
        <div class="page-title" style="display:flex;align-items:center;flex-wrap:wrap;">
            <h2 style="margin:6px 0;">內部稽核
                <small style="color:#8a6d45;">2-GM-06　年度計畫→稽核通知→查檢→不符合改善→稽核報告，一頁控管</small></h2>
            <button id="btnPageHelp" class="page-help-btn" style="margin-left:auto;"><i class="fa fa-question-circle"></i> 使用說明</button>
        </div>
        <div class="clearfix"></div>

<?php if (!$perms['uid']): ?>
        <div class="ia-noperm">
            <h4><i class="fa fa-lock"></i> 無內部稽核使用權限</h4>
            <p>此帳號目前不是在職狀態，無法使用本模組。如有疑問請洽人事或系統管理者。</p>
        </div>
<?php else: ?>
        <div class="ia-toolbar">
            <label>年度</label>
            <select id="yearSel" style="width:132px;"></select>
<?php if ($perms['canAdmin']): ?>
            <!-- 年度選單只列「有資料的年度＋今年明年」，要補更舊的資料得先把年度加進來（2026-09-17 使用者要求） -->
            <span class="ia-op" id="btnYearAdd" title="補一個舊年度進選單（只有內稽管理員可以）"><i class="fa fa-plus"></i> 補舊年度</span>
<?php endif; ?>
            <button id="btnReload"><i class="fa fa-refresh"></i> 重新整理</button>
            <?php if ($perms['canAdmin']): ?>
            <button id="btnSetting"><i class="fa fa-cog"></i> 設定</button>
            <button id="btnUnitSetting"><i class="fa fa-sitemap"></i> 受稽單位</button>
            <button id="btnQualify"><i class="fa fa-user-plus"></i> 稽核員資格</button>
            <button id="btnTeam"><i class="fa fa-users"></i> 稽核小組</button>
            <button id="btnTplSetting"><i class="fa fa-clone"></i> 稽核範本</button>
            <button id="btnClauseBank"><i class="fa fa-list-ol"></i> AS條文題庫</button>
            <?php endif; ?>
            <span class="ia-role-badge">目前身分：<?= htmlspecialchars($roleLabel) ?>
                <i class="fa fa-question-circle" id="btnRoleHelp" style="cursor:pointer;"></i></span>
        </div>
        <!-- 這一年做到哪了（2026-09-17 使用者要求）：✔＝有內稽資料且報告完整產出、沙漏＝有建立但還沒完成。
             **刻意自己佔一列、不放進工具列**（2026-09-21 使用者回報）：放在工具列裡的話，
             字一長就會把「AS條文題庫」那幾顆按鈕擠到下一列；實測 1366／1280 寬度下，
             光是這個短標（163px）就足以讓工具列折行，拿掉就穩定維持一列。 -->
        <div id="yearStatBar">
            <span id="yearStat"></span>
            <div id="yearStatMore"></div>
        </div>

        <div class="ia-tabs">
            <div class="ia-tab on" data-pane="dash">總覽</div>
            <div class="ia-tab" data-pane="plan">年度計畫<small>（06-01）</small></div>
            <div class="ia-tab" data-pane="case">稽核通知單<small>（06-02）</small></div>
            <div class="ia-tab" data-pane="check">查檢表<small>（06-03·04·06）</small></div>
            <div class="ia-tab" data-pane="nc">不符合通知單<small>（06-07）</small></div>
            <div class="ia-tab" data-pane="report">稽核報告表<small>（06-08）</small></div>
        </div>

        <!-- ============ 總覽 ============ -->
        <div class="ia-pane on" id="pane-dash">
            <div class="ia-cards" id="dashCards"></div>
            <div class="ia-hint">這一頁只是看板。實際操作請切到上方各分頁；<b>順序是</b>年度計畫 → 稽核通知單（含事前會議） → 查檢表 → 不符合通知單 → 稽核報告表（含結束會議）。</div>
            <h4 style="font-size:15px;color:#8A5A2B;">即將到期／逾期的不符合改善</h4>
            <div class="ia-table-wrap"><table class="ia-table"><thead><tr>
                <th>IA編號</th><th>受稽核單位</th><th>要求完成期限</th><th>目前狀態</th><th>操作</th>
            </tr></thead><tbody id="dashNcBody"></tbody></table></div>
        </div>

        <!-- ============ 年度計畫 ============ -->
        <div class="ia-pane" id="pane-plan">
            <div class="ia-toolbar">
                <span id="planStatusBox" style="font-size:13px;color:#5b3a1e;"></span>
                <?php if ($perms['canAdmin']): ?>
                <button id="btnPlanCreate" class="btn-warm"><i class="fa fa-plus"></i> 建立本年度計畫表</button>
                <button id="btnPlanDepts"><i class="fa fa-sitemap"></i> 受稽單位</button>
                <button id="btnPlanSave" class="btn-warm"><i class="fa fa-save"></i> 儲存排定</button>
                <button id="btnPlanSubmit"><i class="fa fa-paper-plane"></i> 送審</button>
                <button id="btnPlanApprove"><i class="fa fa-check"></i> 核准</button>
                <button id="btnPlanDelete" class="btn-danger"><i class="fa fa-trash"></i> 刪除計畫表</button>
                <?php endif; ?>
                <button id="btnPlanPrint"><i class="fa fa-print"></i> 列印</button>
            </div>
            <div class="ia-hint">
                <b>○ 計畫實施</b>＝人工排定，點格子切換。<b>◎ 實際實施</b>＝該部門在該月真的被稽核了（稽核通知單狀態設為「執行中／已結案」後自動出現），<b>不必手動點</b>。
                沒排卻做了的月份也會自動出現 ◎（紙本 2024 年就是這種情況）。
            </div>
            <div class="ia-table-wrap" style="padding:10px;"><div id="planGrid"></div></div>
            <div class="plan-legend">備註：<b>○</b>計畫實施　<b>◎</b>實際實施</div>
            <div style="margin-top:8px;">
                <label style="font-size:13px;color:#6b5535;">表下備註</label>
                <input type="text" id="planRemark" style="width:100%;max-width:640px;border:1px solid #D8BE93;border-radius:4px;padding:4px 8px;font-size:13px;">
            </div>
            <!-- 製表人可事後修改（2026-09-14 使用者回報：原本建檔當下寫死、改不了） -->
            <div style="margin-top:8px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;" id="planMakerBox">
                <label style="font-size:13px;color:#6b5535;margin:0;">製表人</label>
                <select id="planMaker" data-eg-filter="輸入姓名篩選…" style="min-width:240px;border:1px solid #D8BE93;border-radius:4px;padding:3px 6px;font-size:13px;"></select>
                <label style="font-size:13px;color:#6b5535;margin:0;">製表日期</label>
                <input type="date" id="planMakerDate" style="border:1px solid #D8BE93;border-radius:4px;padding:3px 6px;font-size:13px;">
                <span style="font-size:12px;color:#8a6d45;">改完按上方「儲存排定」，列印版的製表圖章會跟著換。</span>
            </div>
        </div>

        <!-- ============ 稽核通知單 ============ -->
        <div class="ia-pane" id="pane-case">
            <div class="ia-toolbar">
                <label>狀態</label>
                <select id="caseStatus" style="width:120px;">
                    <option value="">全部</option>
                    <option value="draft">草稿</option>
                    <option value="issued">已發出</option>
                    <option value="executing">執行中</option>
                    <option value="closed">已結案</option>
                </select>
                <input type="text" id="caseKw" placeholder="搜尋 件號／組長／受稽單位／稽核員…" style="width:260px;">
                <button id="btnCaseSearch"><i class="fa fa-search"></i> 查詢</button>
                <?php if ($perms['canAdmin']): ?>
                <button id="btnCaseNew" class="btn-warm"><i class="fa fa-plus"></i> 新增稽核通知單</button>
                <?php endif; ?>
            </div>
            <div class="ia-pager" id="casePager"></div>
            <div class="ia-table-wrap"><table class="ia-table"><thead><tr>
                <th>稽核件號</th><th>次別</th><th>通知日期</th><th>稽核期間</th><th>稽核組長</th>
                <th>受稽單位</th><th>查檢表</th><th>缺失</th><th>會議</th><th>狀態</th><th>操作</th>
            </tr></thead><tbody id="caseBody"></tbody></table></div>
        </div>

        <!-- ============ 查檢表 ============ -->
        <div class="ia-pane" id="pane-check">
            <div class="ia-toolbar">
                <label>種類</label>
                <select id="checkKind" style="width:180px;"><option value="">全部</option></select>
                <input type="text" id="checkKw" placeholder="搜尋 標題／稽核人／項目內容…" style="width:240px;">
                <button id="btnCheckSearch"><i class="fa fa-search"></i> 查詢</button>
                <?php if ($perms['canAudit']): ?>
                <button id="btnCheckNew" class="btn-warm"><i class="fa fa-plus"></i> 建立查檢表</button>
                <?php endif; ?>
            </div>
            <div class="ia-hint">先選<b>種類</b>，畫面才會長出該種類要填的欄位與挑題方式：<b>AS稽核查檢表</b>帶 AS9100 條文題庫、<b>系統稽核紀錄表</b>帶 AS 表單編號與名稱、<b>績效執行稽核查檢表</b>自動帶去年整年的 KPI 與達成與否。前兩種的<b>左欄標籤（部門／作業項目）只是把中間清單聚焦</b>，<b>不會自動勾選</b>；勾好的一律列在<b>最右側「已選擇」欄</b>，取消標籤不會把它們清掉。判定「不合格」的開<b>內稽不符合通知單</b>、「沒達成」的開<b>異常矯正處理單</b>。</div>
            <!-- 能自動建立的就自動建立，人工才要填的地方主動提醒（2026-09-14 使用者要求） -->
            <div class="ia-hint" id="checkAutoHint" style="display:none;background:#FDF0DC;border-color:#F0A24B;"></div>
            <div class="ia-pager" id="checkPager"></div>
            <div class="ia-table-wrap"><table class="ia-table"><thead><tr>
                <th>種類</th><th>標題</th><th>所屬件號</th><th>稽核人</th><th>稽核日期</th>
                <th>項目數</th><th>不合格</th><th>未判定</th><th>狀態</th><th>操作</th>
            </tr></thead><tbody id="checkBody"></tbody></table></div>
        </div>

        <!-- ============ 不符合通知單 ============ -->
        <div class="ia-pane" id="pane-nc">
            <div class="ia-toolbar">
                <label>階段</label>
                <select id="ncStage" style="width:160px;"><option value="">全部</option></select>
                <label><input type="checkbox" id="ncOverdue" style="height:auto;"> 只看逾期</label>
                <input type="text" id="ncKw" placeholder="搜尋 IA編號／單位／事實／措施…" style="width:250px;">
                <button id="btnNcSearch"><i class="fa fa-search"></i> 查詢</button>
                <?php if ($perms['canAudit']): ?>
                <button id="btnNcNew" class="btn-warm"><i class="fa fa-plus"></i> 開立不符合通知單</button>
                <?php endif; ?>
            </div>
            <div class="ia-pager" id="ncPager"></div>
            <div class="ia-table-wrap"><table class="ia-table"><thead><tr>
                <th>IA編號</th><th>受稽核單位</th><th>受審核人</th><th>稽核日期</th><th>類型</th>
                <th>相關表單</th><th>要求完成期限</th><th>階段</th><th>操作</th>
            </tr></thead><tbody id="ncBody"></tbody></table></div>
        </div>

        <!-- ============ 稽核報告表 ============ -->
        <div class="ia-pane" id="pane-report">
            <div class="ia-toolbar">
                <span id="reportStatusBox" style="font-size:13px;color:#5b3a1e;"></span>
                <?php if ($perms['canAdmin']): ?>
                <button id="btnReportSave" class="btn-warm"><i class="fa fa-save"></i> 儲存</button>
                <button id="btnReportSubmit"><i class="fa fa-paper-plane"></i> 送出</button>
                <button id="btnReportNotifySet"><i class="fa fa-bell"></i> 通知對象設定</button>
                <button id="btnReportDelete" class="btn-danger"><i class="fa fa-trash"></i> 刪除報告表</button>
                <?php endif; ?>
                <button id="btnReportPrint"><i class="fa fa-print"></i> 列印</button>
                <label style="font-size:12px;color:#6b5535;font-weight:normal;margin:0 0 0 auto;cursor:pointer;">
                    <input type="checkbox" id="rptShowAll" data-eg-skip style="vertical-align:-1px;">
                    顯示全部受稽單位（預設只列有缺失的）</label>
            </div>
            <div class="ia-hint">缺點數與缺點記錄<b>全部由該年度的不符合通知單自動算出</b>，不用手打；只有「預定完成改善時間」與下方補充文字可以人工調整。</div>
            <div class="ia-table-wrap"><table class="ia-table"><thead><tr>
                <th rowspan="2">受稽單位</th><th colspan="3">缺點數</th><th colspan="2">受稽時間</th>
                <th rowspan="2">稽核員</th><th rowspan="2">預定完成改善時間</th><th rowspan="2">已結案</th>
            </tr><tr><th>主</th><th>次</th><th>觀</th><th>日期</th><th>時間</th></tr></thead>
            <tbody id="reportBody"></tbody></table></div>
            <h4 style="font-size:15px;color:#8A5A2B;margin-top:14px;">缺點記錄</h4>
            <div class="ia-table-wrap"><table class="ia-table"><thead><tr>
                <th>受稽單位</th><th>IA編號</th><th>相關表單</th><th>不合格事實</th><th>狀態</th>
            </tr></thead><tbody id="reportRecBody"></tbody></table></div>
            <div style="margin-top:10px;">
                <label style="font-size:13px;color:#6b5535;">補充文字（列印時接在缺點記錄後面）</label>
                <textarea id="reportNote" style="width:100%;min-height:70px;border:1px solid #D8BE93;border-radius:4px;padding:6px 8px;font-size:13px;"></textarea>
            </div>
            <!-- 2026-09-17 使用者拍板：稽核報告表**不要核准、也不要製表人**（紙本本來就沒有這兩格），
                 改成一顆「送出」，送出後自動通知管理員設定好的那些部門的那些職位。 -->
        </div>
<?php endif; ?>

        <button id="btnTop" class="ia-top"><i class="fa fa-arrow-up"></i></button>
    </div><!-- right_col -->
</div></div>

<!-- ============================ 使用說明（鐵律7） ============================ -->
<div class="ia-mask" id="helpUseMask"><div class="ia-modal">
    <div class="ia-mhead"><h4><i class="fa fa-question-circle"></i> 內部稽核　使用說明</h4><span class="x" data-close>&times;</span></div>
    <div class="ia-mbody help-doc">
        <h4>這個頁面在做什麼</h4>
        <p>把一整年的內部稽核（AS9100 2-GM-06）從頭到尾放在同一頁控管：年度排程 → 發稽核通知 → 現場查檢 → 開不符合通知單追改善 → 年度稽核報告。
        <b>會議紀錄不在這裡重複建立</b>，按鈕會自動幫你在既有的「會議紀錄」模組建好草稿並帶好與會人員，再開新分頁讓你填。</p>

        <h4>操作步驟</h4>
        <ul>
            <li><b>①年度計畫（2-GM-06-01）</b>：先按「建立本年度計畫表」，選要納入的受稽單位，再在格狀表點格子排定 ○。存檔後送審、核准。</li>
            <li><b>②稽核通知單（2-GM-06-02）</b>：新增一張，填通知日期、稽核期間、稽核組長，下方逐列填「稽核起始主過程／受稽單位／稽核員／陪檢員」（稽核員與陪檢員都可以指定多位）。
                稽核件號會依<b>通知日期</b>自動產生（西元年後兩碼+月日+流水，例 241216001）。存檔後可按「事前會議」建立會議紀錄草稿。</li>
            <li><b>③查檢表</b>：<b>先選種類</b>，畫面才會長出該種類要填的欄位與挑題方式；建立時填「建立（稽核）日期」，再勾這次要查的項目。
                <b>建立查檢表預設一題都不勾</b>（2026-09-14 起），請先從左欄點選，或直接在右側逐題勾選。三種的差別：
                <ul>
                    <li><b>系統稽核紀錄表的受稽人</b>：預設帶<b>該份文件所屬部門</b>的陪檢員，可改選的名單<b>來自該年度的「稽核小組」</b>
                        （工具列的「稽核小組」設定），下拉只分<b>稽核人員（含稽核組長）／陪檢員</b>兩組。
                        清單一律顯示「部門　職稱　姓名」，部門職稱<b>依稽核日期回推當時的</b>，
                        <b>存檔與結案時會自動依「受稽人部門 → 表單編號」重新排序</b>（同一個部門的表單排在一起），
                        <b>兼任會標「（兼任）」</b>；同一個人在不同部門各有一個職務時會各列一筆，請挑對他這次是以哪個身分受稽。
                        <b>標題不用自己取</b>——自動顯示為對應稽核通知單的「第 N 次　日期」。</li>
                    <li><b>開不符合通知單時會自動帶好</b>：受稽核單位、受稽核人（都還可以手動改）、相關表單編號<b>與名稱</b>、
                        稽核日期、所屬件號，以及<b>要求完成期限＝該受稽單位在通知單上填的「預定完成改善」</b>；
                        <b>違反條文</b>會把「綁定這份 AS 文件的條文」列出來讓您勾（也可以自己打字改）。
                        開完之後查檢表該列的備註會自動填上 IA 編號，並<b>順手把該年度的稽核報告表建起來</b>。
                        表單的<b>編號與名稱都會存快照</b>，日後改編號、改名甚至廢止，已開的單仍印得出當時的內容。</li>
                    <li><b>另一條開單路徑：資料稽核</b>（2026-09-21 新增）——
                        「資料稽核」頁的<b>流程順序稽核</b>查出來的缺失（查不到報價單、出貨早於訂單、製令早於訂單…），
                        可以在那一頁<b>直接開成不符合通知單</b>，受稽核單位、相關表單編號、違反條文、不合格事實都會自動帶好，
                        也可以勾選多筆一次開完。詳見該頁的使用說明。</li>
                    <li><b>系統稽核紀錄表（2-GM-06-06）</b>：稽核對象是「AS 表單」，建立跳窗的清單上<b>直接列表單編號與名稱</b>（品質管理系統要求改成滑鼠移上去才顯示，開不符合通知單時「違反條文」照樣自動帶入）。
                        <b>左欄挑部門</b>（依 AS 文件編號的部門代碼分類）只是把中間清單<b>聚焦</b>到該部門的表單，<b>不會自動勾選</b>；要整批勾請按<b>「全選」</b>（只動目前顯示的），或勾上<b>「點標籤時自動勾選底下的表單」</b>。
                        勾好的表單會列在<b>最右側「已選擇」欄</b>（編號／名稱／對應部門，可按 × 單筆取消）——<b>取消左欄的部門不會把已選的表單清掉</b>，所以可以一個部門一個部門挑完再一次建立。逐列選受稽人、判定合格／不合格。</li>
                    <li><b>AS稽核查檢表（2-GM-06-04）</b>：題目＝AS9100 條文題庫，挑題方式與系統稽核紀錄表相同——<b>左側的作業項目標籤</b>（品管檢測／外包加工…）只負責把中間清單聚焦（已選的標籤下方會列出它掛在哪幾份文件表單），要勾請自己勾或按「全選」，已勾的列在最右側且不受標籤增減影響；
                        也可以在「自動判定來源」選一張已填好的<b>系統稽核紀錄表</b>，建立時就依它自動判定合格／不合格，並在「所見證據或建議」列出是哪幾份表單不合格（含 IA 單號）方便比對。</li>
                    <li><b>績效執行稽核查檢表（2-GM-06-03）</b>：稽核<b>去年一整年</b>的 KPI（2026 年建立＝稽核 2025 年度），<b>不分上下半年</b>。部門、指標、目標、受稽人（KPI 頁面設定的<b>擔當者</b>，兼任者取該指標登記部門的職稱）與<b>達成／沒達成全部自動判定</b>——該年度只要有<b>任一次</b>未達標就算沒達成。您只要確認建立日期與要查哪幾項。</li>
                </ul>
                判「不合格」的列按「開不符合單」、績效「沒達成」的列按「開矯正單」；表格上方會統計還有幾件沒開單，下方「<b>一鍵開立</b>」可一次全部開完。
                <b>建好之後還可以增刪項目</b>（限結案前）：下方「<b>新增項目</b>」從題庫補上漏掉的（已經有的不會重複列出），
                每一列最後面的垃圾桶可以刪掉那一列——<b>已經開過不符合通知單／矯正單的那一列不給刪</b>（刪了那張單會變成孤兒）。
                <b>受稽人不可以是稽核人本人</b>，選到當下就會退回（自己稽核自己等於沒有稽核）。
                <b>列印版的大標題是「種類名稱」</b>（系統稽核紀錄表／AS稽核查檢表／績效執行稽核查檢表），次別與日期印在標題下那一列。
                <b>點下「不合格」當下開單鈕就會出現</b>，不必先存檔也不必重開這張表；改回「合格」就自動收掉（切換判定不會影響您在別欄已經打好、還沒存的字）。
                <b>表頭會固定在上方</b>，往下捲仍看得到每一欄是什麼。</li>
            <li><b>④不符合通知單（2-GM-06-07）</b>：分四段填，各段只有該角色能填（見下）。系統會通知受稽單位主管，期限前與逾期會自動再提醒。</li>
            <li><b>⑤稽核報告表（2-GM-06-08）</b>：缺點數與缺點記錄自動彙總，只要調整「預定完成改善時間」與補充文字，然後<b>送出</b>、列印。
                <b>預設只列「有缺失」的單位</b>（每個單位都列一遍的話絕大多數是空白列，反而看不出哪裡有問題）——要看全部請勾右上角「顯示全部受稽單位」，畫面與列印會一起跟著變。
                本表<b>沒有核准、也沒有製表人</b>（紙本本來就沒有這兩格）；按<b>送出</b>後會自動通知「<b>通知對象設定</b>」裡登記的那些<b>部門 × 職位</b>的人
                （可勾含子部門；沒設定也送得出去，只是不會發通知）。</li>
        </ul>

        <h4>不符合通知單的四段分工</h4>
        <ul>
            <li><b>段一 稽核員填</b>：不合格事實描述、不合格類型（主要／次要／觀察）、違反條文、要求完成期限。</li>
            <li><b>段二 受稽單位填</b>：原因分析、糾正措施＋<b>完成時間</b>、預防措施＋<b>預計完成時間</b>、責任主管。
                兩個日期都是<b>獨立的日期欄（點月曆選，就在各自措施內容的右側）</b>，不要再打在措施內容裡，送出時兩個都必填。
                填完按「送出回覆」才會進到下一段。<b>稽核員／內稽管理員可以代填</b>（對方不方便用電腦、或補歷史紙本時），代填會在下方歷程留下紅字紀錄。
                <b>送出之後這一段就鎖起來了</b>（避免之後不小心改到）；真的要修正時，<b>內稽管理員</b>可以按段落標題旁的「解鎖修改」，
                解鎖只對這一次開啟有效，關掉重開又是鎖住的。</li>
            <li><b>段三 稽核組長填</b>：只有<b>驗證描述</b>與<b>驗證通過／不通過</b>兩項（紙本的「結束」欄已取消，
                列印版改印<b>結案日期</b>）。<b>不通過會退回段二</b>並重新通知受稽單位。
                <b>送出驗證之後這一段也會鎖起來</b>（與段二相同）；要修正一樣由<b>內稽管理員</b>按「解鎖修改」。
                <b>稽核組長不是「按下驗證的人」</b>，而是<b>該年度稽核小組設定的組長</b>（工具列「稽核小組」設定），
                所以管理員代填也不會把自己變成組長；小組沒設組長時才沿用這張稽核通知單上登記的人。
                <b>設定改過之後，舊單一打開就會自動更正</b>（不必重新存檔），畫面與列印版拿到的一定是同一個人。</li>
            <li><b>段四 管理代表填</b>：管理代表意見，按「結案」本單結束、通知受稽單位。
                <b>管理代表同樣是自動帶的</b>——取自全站的<b>組織角色綁定</b>（管理代表），不是按下結案按鈕的人。</li>
            <li><b>結案之後還是可以改</b>：內稽管理員在跳窗下方會多一顆<b>「取消結案」</b>，
                按下去退回「驗證完成」狀態，四段就可以再修正（例如稽核組長被寫成代填的人），改完再按一次「結案」即可。
                <b>刻意只退到「驗證完成」不退到最前面</b>——退到最前面會把受稽單位回覆整段重新打開、還會再發一次通知給對方，
                只是要修一個簽章名字沒必要驚動人。受稽單位那一段若要改，仍是按該段的「解鎖修改」。</li>
        </ul>

        <h4>列印版（2-GM-06-07）</h4>
        <ul>
            <li><b>固定印成 A4 一頁</b>。四個圖章一律在各自格子的<b>右下角</b>：稽核員／責任主管／
                （驗證段）<b>管理代表＋稽核組長並排</b>，結案日期印在驗證段的左下角。</li>
            <li><b>還沒簽的格子印成空白橫線</b>，不會先蓋一個沒有日期的章——那等於在還沒驗證、還沒結案的單上
                印出「已經簽好」的假簽章。等該段真的簽核（有簽核日期）之後才會出現圖章。</li>
            <li><b>印出來左右被裁掉的話</b>：本表的左右留白已經留 20mm（上 18mm、下 16mm）。
                如果還是被裁，多半是<b>瀏覽器列印視窗裡的「邊界」被設成「無」或「最小」</b>——
                請改回「預設」；那個設定會蓋掉網頁自己指定的留白。</li>
        </ul>

        <h4>自動暫存與簽章日期（2026-09-21 新增）</h4>
        <ul>
            <li><b>改完會自動幫您暫存</b>：停止輸入約 1 秒後自動存一次，該段右下角會顯示「已自動暫存 時:分:秒」，
                不會跳任何訊息、也不會把畫面重載（正在打字不會被打斷）。關閉跳窗前也會再存一次。
                <b>自動暫存只會「暫存」，永遠不會幫您送出</b>——送出、驗證、結案這些會發通知、會鎖段的動作，一律要自己按。
                段落<b>鎖住或唯讀時不會自動暫存</b>（鎖起來就是為了避免誤改，自動存反而會把誤改寫進去）。</li>
            <li><b>補資料的單，簽章日期不會預設成今天</b>：稽核日期在<b>今天往前半年以前</b>的單一律視為補資料，
                所有空白的簽章日期會自動帶成<b>該單的稽核日期</b>，跳窗標題也會標出來。
                今天的日期看起來像真的那天簽的，補一整批舊單很容易就這樣簽下去，紙本與系統從此對不起來。
                已經填過的日期不會被改掉；要簽別的日期自己改即可（後端用同一條規則，不會偷偷寫成今天）。</li>
        </ul>

        <h4>佐證附件（2026-09-21 新增）</h4>
        <ul>
            <li><b>段一、段二、段三各有自己的附件區</b>：稽核員可以附「不合格事實」的照片或掃描檔，
                受稽單位在<b>改善</b>那一段附改善後的佐證，<b>稽核組長驗證</b>那一段附驗證佐證。
                選好檔案（可一次選好幾個）、要的話填一行說明，按「上傳」即可。</li>
            <li><b>誰能傳＝誰能填那一段</b>：段別鎖住的時候上傳鈕就不會出現，
                所以<b>段三要等受稽單位按了「送出回覆」之後</b>稽核組長才附得了檔（和填寫欄位同一個規則）；
                <b>結案之後三段都不能再增刪附件</b>。已經上傳的檔案唯讀時仍然看得到、也點得開。</li>
            <li><b>單檔 20MB 以內</b>，照片、PDF、Word／Excel、文字檔都可以；
                可執行檔與腳本（.exe、.bat、.php…）一律不收。檔名點一下就開新分頁預覽或下載。</li>
            <li>上傳與刪除都會記在下方的<b>填寫歷程</b>，看得到是誰在什麼時候動的。
                <b>刪除會連同附件資料夾裡的檔案一起移除，無法復原</b>。</li>
        </ul>

        <h4>重要行為／常見疑問</h4>
        <ul>
            <li><b>可以自動建立的都會自動建立</b>：查檢表分頁上方的橘色提示列會告訴你「今年的績效執行稽核查檢表還沒建」「已經有系統稽核紀錄表，可以直接建 AS稽核查檢表並自動判定」「還有幾張表判定出不合格卻沒開單」，按提示列上的連結就會把能自動帶的全部帶好，<b>只留建立日期這種一定要人工確認的欄位</b>。</li>
            <li><b>績效沒達成開的是「異常矯正處理單」不是 IA 單</b>：紙本備註欄本來就印矯正單編號。按「開矯正單」會自動帶出年度、哪幾個月沒達成、當時的數值與 KPI 目標，並附上「請說明原因及確認是否需要調整KPI目標?」，責任單位＝該指標的部門、回覆人＝擔當者；開完單號自動寫回備註欄。</li>
            <li><b>管理員可以刪除</b>：年度計畫表、稽核通知單、查檢表、不符合通知單、稽核報告表在各自的清單／工具列上都有刪除鈕（限內稽管理員）。已經開過不符合通知單的查檢表要先刪掉那幾張 IA 單才刪得掉。</li>
            <li><b>年度計畫表的 ◎ 不用手動點</b>：把稽核通知單的狀態改成「執行中」或「已結案」，該單位那個月就會自動變 ◎。沒排 ○ 卻做了也會出現 ◎。</li>
            <li><b>好幾個部門是同一個受稽單位</b>（例：生產部＋生產1廠＋生產2廠＋生產3廠）：到工具列「受稽單位」綁成一個群組。
                綁定後計畫表上是<b>一欄</b>、報告表上是<b>一列</b>，稽核其中任何一個廠都算這個單位已執行；這個單位底下所有部門的人都看得到並可回覆該單位的不符合通知單。一個部門只能屬於一個受稽單位。</li>
            <li><b>誰可以當稽核員／陪檢員</b>：工具列「稽核員資格」可指定名單，有<b>三種來源、可並用</b>：
                ①<b>依職位</b>＝認到「部門＋職稱」、<b>不指定人名</b>，人名是建單當下才抓該職務的在職人員，所以人員異動、新人接任都不必回頭改名單；兼任的職務是獨立一列（品管課課長與總經理室總經理分開設定），「兼任才有資格」設定得出來。
                ②<b>職位＋指定人員</b>＝只有<b>這個人的這個職務</b>有資格，<b>同部門同職稱的其他人不會跟著有</b>；可以填<b>任期</b>——<b>本人請假由代理人暫代</b>就是用這個，期間一過自動失效，不必記得回來刪。
                ③<b>AS 文件負責人自動具備稽核員資格</b>（不必設定），期間比照 AS 文件管理→系統設定→結構總覽列印 的<b>任期</b>設定。
                <br>挑選時挑的仍是「某人的某個職務」（圖章的部門職稱才印得對）。<b>①②都留空＝不限制</b>。</li>
            <li><b>補以前年度的單據時，當時在職、現在已離職的人一樣挑得到</b>（2026-09-16）：所有人員下拉都以<b>該單據自己的業務日期</b>判定在職與職稱——稽核通知單看<b>稽核起日</b>、查檢表看<b>稽核日期</b>、製表人看<b>製表日期</b>。
                日期一改，下拉會立刻重抓那一天的人；職稱印的也是<b>當時</b>的職稱，不會被現在新兼的職務蓋掉。資格的任期同樣用這個日期判定。</li>
            <li><b>稽核小組</b>（工具列，限內稽管理員）：這一年度的內稽是誰在做。<b>建稽核通知單前先組好</b>——之後「自動建立會議紀錄」的與會人員就是小組成員、<b>主席固定為稽核組長</b>；還沒建小組時會退回用該通知單上各受稽單位的稽核員與陪檢員。
                常態小組每年差不多，可用「<b>從其他年度複製</b>」整批帶過來再增減（原職務已異動或離職的成員會自動略過並列出來）。<b>稽核組長只能有一位</b>。</li>
            <li><b>受稽日期只能選稽核期間內的日子</b>：日曆本身就會把稽核起～迄以外的日期鎖起來（不得早於稽核起日期），
                改了上方的稽核期間，下面每一列的可選範圍會即時跟著換；既有資料若超出範圍會紅字標出是第幾列，存檔前必須先改好。</li>
            <li><b>受稽日期／預定完成改善要全部同一天</b>：這兩欄的表頭各有一個日期欄＋「全部」鈕，按下去就套用到每一列；表頭沒填時會自動沿用<b>第一列已經填好的那個值</b>。</li>
            <li><b>年度選單會顯示這一年做到哪了</b>：<b>✔ 已完成</b>＝這一年有內稽資料<b>而且報告完整產出</b>
                （每一張稽核報告表都已送出，且沒有未結案的不符合通知單）；<b>⏳ 進行中</b>＝<b>已經有年度計畫表</b>但還沒有（或還沒送出）稽核報告表，
                後面會寫「還差 N 項」——<b>滑鼠移上去</b>看得到完整內容，按<b>「看明細」</b>會在下方一項一行列出來（例「還沒有稽核報告表」「還有 2 張不符合通知單未結案」），
                展開或收合會記住，下次進來維持同樣的狀態；
                <b>沒有年度計畫表的年度一律不顯示圖示</b>（一年的內稽是從年度計畫表開始的；若該年已有零星資料，旁邊會寫「還沒有年度計畫表」）。
                年度選單<b>只列「已經有資料的年度」與今年、明年</b>（不再一路往前補十年的空年度）；要補更舊的資料，
                請<b>內稽管理員</b>按年度旁的「<b>補舊年度</b>」把那一年加進來（該年度一旦有資料就不能再從選單移除）。</li>
            <li><b>分頁會依進度逐步出現</b>：這一年還沒建<b>年度計畫表</b>時，只看得到「總覽」與「年度計畫」；
                建了計畫表才出現<b>稽核通知單</b>，建了通知單才出現<b>查檢表／不符合通知單／稽核報告表</b>。
                分頁上方會寫出「還差什麼」。年度一打開<b>自動停在進行中的那一年</b>（沒有進行中的才停在今年）。</li>
            <li><b>稽核通知單要按「完成」</b>：填好內容按下方的<b>完成</b>——
                <b>送出前每一列受稽單位都要填齊</b>：稽核起始主過程、受稽單位、稽核員、陪檢員、受稽日期、時間、預定完成改善，
                缺一格就會擋下來並把那一格標紅（<b>儲存草稿不檢查</b>，可以邊排邊填）。
                <b>受稽時間也會一起檢查</b>：最後一個單位必須在<b>結束會議開始前 30 分鐘</b>做完
                （依表格裡<b>實際填的時間</b>加上「每單位分鐘」計算，不是看表頭的開始時間），
                來不及就擋下來並告訴您最後一列要幾點以前開始；結束會議排在別天則不受此限。
                <b>完成之後整張單就鎖定不可修改</b>，而且<b>完成之後才會送審核</b>（管理員若已開啟自動簽核，核准與審查會在這一刻直接簽完）。
                （自動簽核要不要開，在<b>設定 → 自動簽核</b>，<b>年度計畫表與稽核通知單各有一個開關</b>；
                <b>勾選通知單那一個並儲存時，已經完成但還沒有章的通知單會一次補上核准與審查</b>，並告訴您補了哪幾張——
                已經有人簽過的一律不動）。
                <b>還沒按完成的通知單，列印時核准／審查兩格一律留白</b>——沒完成就印出簽好的章是不實的簽章。
                <b>按「完成」會先把畫面上的修改存起來再完成</b>——不然剛改好還沒按儲存的內容（例如稽核日期）
                不會進資料庫，完成後重新載入就跳回舊值。
                按完成時會請您確認<b>簽章日期</b>：預設＝<b>通知日期</b>，補歷史紙本時可以往前挑，但<b>不可以晚於通知日期</b>
                （通知單是先簽好才發出去的）；這個日期同時會成為<b>製表日期</b>。
                要再修改只有<b>內稽管理員</b>按「取消完成」並輸入<b>操作確認密碼</b>
                （<b>沒有那個密碼權限的人不會看到這顆按鈕</b>，畫面會直接告訴他要找誰授權；
                密碼連錯 3 次會把這項功能鎖 7 天，可由超級管理員在<b>修改個人資料 → 操作確認密碼鎖定名單</b>提前解鎖）；取消完成會把自動簽核蓋上的核准／審查一併清掉
                （內容要改，那兩個章就不成立了）。已結案的單不給取消完成，請先把狀態改回執行中。</li>
            <li><b>建立查檢表選了「所屬件號」之後</b>：建立日期會<b>自動帶成該件號的受稽日期</b>（跨好幾天的通知單會多一個日期下拉讓您挑，挑哪一天就只列那一天的內容）；
                下方直接列出<b>那一天要稽核哪些單位、起始主過程是什麼</b>，不必另外開通知單查；
                <b>稽核人也會自動帶成該件號上的稽核員</b>，下拉裡仍然是「<b>該稽核日期當時有稽核員資格</b>」的人，可以自己改成別人。
                日期是依<b>該件號的稽核期間</b>帶的（受稽單位列上的受稽日期若與稽核期間對不起來，會直接標紅字告訴您）。</li>
            <li><b>受稽時間可以自動排</b>：「時間」欄的表頭填<b>開始時間</b>（結束時間可留空）後按<b>「自動排」</b>。
                <b>「每單位 N 分」是每個受稽單位需要的時間</b>（不是空檔）：第一個單位從開始時間起，下一個就是上一個做完的時間；
                <b>會自動跳過午休 12:00~13:00</b>。
                <b>最後一個單位一定會排在結束會議開始前 30 分鐘做完</b>（結束會議排在<b>別天</b>時就不受這一條限制）。
                <b>結束時間不用自己填</b>——填了開始時間就<b>即時算出來顯示</b>（唯讀），旁邊同時告訴您
                「做到幾點、最晚要幾點做完、行不行得通」；<b>不行的話當場就給建議</b>
                （例：「保持每單位 60 分，改成 08:30 開始」或「保持 09:30 開始，每單位改成 45 分」），
                不必按了自動排才知道排不下。按自動排時若真的不夠也會擋住，而且<b>不會動到已經填好的時間</b>。
                之後<b>手動改中間任何一列的時間，後面幾列會自動順延</b>（前面的不動）；想逐列自己填就把表頭的「改一列就自動順延後面」取消勾選。</li>
            <li><b>要補以前年度的資料</b>：左上角年度下拉本來就含近十年，直接切到那一年再建立即可，不必先有當年的資料。</li>
            <li><b>稽核起始主過程要填什麼</b>：這次稽核從哪一段流程切入，稽核員由這裡開始循序把相關過程查完。紙本備註列了三類可填：<b>主過程</b>（客戶需求檢討→開發→訂單/合約審查→生產→倉儲出貨→客戶回饋）、<b>管理過程</b>（文件/記錄管理、人力資源訓練、不符合管理、資料分析、內部稽核、矯正/預防措施管理、持續改善、管理責任…）、<b>支援過程</b>（採購、供應商管理、IQC/FAI/IPQC/FQC、儀器/量具、機器/治具、生管、型態(鑑別追溯)、特殊特性…）。起點<b>不必等於該單位的日常業務</b>——紙本備註第 1 條要求「跳過自己的直接職務」，讓稽核員從別人的角度切入。<b>同一次稽核裡不可以有兩列填相同的起始主過程</b>，重複會即時標紅、也存不進去。</li>
            <li><b>稽核員與陪檢員怎麼帶</b>：選了範本之後，該列的稽核員／陪檢員下拉會縮到範本指定的部門範圍內、且只列有資格的職務；<b>候選只有一位就自動帶入</b>。系統<b>先決定稽核員</b>，陪檢員的候選會自動排除稽核員本人（同一人不可兩邊都當，即使是不同職務）。<b>陪檢員可以不填</b>。</li>
            <li><b>稽核員／陪檢員都可以有多位</b>（2026-08-27 起）：已選的人會變成一個個標籤，按標籤上的 <b>×</b> 移除、用下方的「＋加入稽核員／＋加入陪檢員」再加人，<b>每一種最多 <?= IA_CD_PERSON_MAX ?> 位</b>。已經被選走的人（不管在哪一邊）不會再出現在候選裡，所以不會不小心把同一個人排成兩種身分。列印版的稽核員／陪檢員欄會一位一行印出來；自動建會議紀錄時，<b>全部</b>稽核員與陪檢員都會被帶進與會人員。</li>
            <li><b>「受審查單位主管」欄位已取消</b>（2026-09-21）：表單與列印版都不再有這一格與它的簽核日期。
                開單後要通知誰，改成<b>依受稽核單位即時解析當時的單位主管</b>（加上受審核人），不靠單上存的那個欄位。</li>
            <li><b>稽核員可以由內稽管理員更換</b>（段一最下面那一欄）：管理員代別人開單時，稽核員原本會被自動填成按下按鈕的人＝管理員自己，
                但那張單實際上不是他去稽核的。候選只列<b>設定為稽核員／稽核組長</b>的人（依稽核日期回推當時的資格與職稱）；
                一般稽核員看得到這一欄但改不動，原本掛著的人即使現在已無資格也會保留在選項裡。換人會留在下方的填寫歷程。</li>
            <li><b>受稽核人與責任主管兩個下拉只列「該單位的主管」</b>，不是全公司的人：兩個都依<b>受稽核單位</b>決定。
                <b>責任主管的簽核日期限「稽核日期起一週內」</b>（日曆日，含假日）——月曆直接選不到範圍外的日期，
                送出時前後端再各驗一次。<b>預防措施的預計完成時間刻意沒有這個限制</b>（改善本來就可能排到很久以後）。
                名單一律<b>依稽核日期回推當時在職的人與當時的職稱</b>。
                該單位的職稱在<b>職級設定</b>裡都沒有登記職級時（查不到任何主管），會改列該單位全部人員並標「非主管」，不會給您一個空的下拉；
                要讓名單正確，請到部門職稱設定把該職稱的職級補上。舊單上原本掛著的人即使已調職或離職，仍會保留在選項裡不會被洗掉。</li>
            <li><b>IA 編號依稽核日期產生</b>（IA+西元後兩碼+月日+流水，例 IA24121601），補歷史紙本時編號會跟表單上的日期對得起來。</li>
            <li><b>稽核件號也是依稽核日期產生</b>（西元後兩碼＋月日＋3 位流水，例 251204001＝2025.12.04 的第 1 件；沒填稽核起才退回通知日期）。
                <b>改了稽核日期，件號會自動跟著重編</b>並在存檔後告訴你新舊號——但<b>只有還是草稿的才重編</b>，已發出／執行中／已結案的紙本上印著舊號，一律不動。</li>
            <li><b>製表人可以改</b>（2026-09-14 起，限內稽管理員）：<b>年度計畫</b>（表格下方）、<b>稽核通知單</b>（基本資料區）、<b>稽核報告表</b>（補充文字下方）三張都有「製表人／製表日期」，
                改完存檔，<b>列印版的製表圖章會跟著換</b>。原本的製表人已離職時仍會留在下拉選項裡（標「已離職／非在職」），不會因為開來存個檔就被洗掉。</li>
            <li><b>到期提醒</b>：期限前 N 天（預設 7 天，可在「設定」改）與逾期後，每天最多發一則通知給受稽單位主管與受審核人。提醒是有人用到這個模組時順便檢查，不是背景排程。</li>
            <li><b>查檢表結案前必須每一項都判定過</b>合格／不合格，否則不讓結案（避免漏查）。</li>
            <li><b>建錯的稽核通知單怎麼刪</b>（2026-09-11 起）：<b>內稽管理員</b>可以刪除<b>尚未結案</b>的通知單——清單操作欄的垃圾桶圖示，或開啟後按下方的「刪除」。兩個限制：<b>已結案的不給刪</b>（要刪請先把狀態改回「執行中」）、<b>底下還有不符合通知單的不給刪</b>（那些 IA 單會變孤兒、仍留在清單與稽核報告表裡，請先到「不符合通知單」分頁處理或刪除）。刪除會<b>連同底下的查檢表一起刪</b>（含已填好的結果），年度計畫表上這一次稽核的 ◎ 也會一併消失；<b>已建立的會議紀錄不會被刪除</b>，那是會議紀錄模組自己的資料，請自行過去處理。</li>
            <li><b>條文題庫刪不掉</b>：已經被既有查檢表引用的 AS 條文按刪除會自動改成「停用」（不再出現在新建的查檢表），舊表內容不受影響。</li>
            <li><b>作業項目：看不懂條文在查什麼的解法</b>（2026-09-11 起）。AS 條文是原文（「8.4 外部提供的過程、產品和服務的控制」），看不出實務上對應公司哪一段作業，所以加了一層白話的<b>作業項目</b>：
                <br>⑴<b>項目本身設在 AS 文件管理</b>：該文件的 <b>⚙ → 作業項目</b>（管理員限定），一份文件可以寫好幾個（同一份文件常常不只做一件事），例如供應商管理程序＝外包加工、供應商評鑑。
                <br>⑵<b>條文題庫</b>的「作業項目」欄再挑「這一條對應到哪幾個」，候選就是該條文左欄列出來的那幾份文件底下的項目。
                <br>⑶<b>建立查檢表</b>時上方會長出這些標籤，點一下＝只勾有這個用途的條文（可多選，再點一次取消）；每一列也同時顯示品質管理系統要求、對應的文件表單與作業項目。
                <br>⑷<b>填寫查檢表</b>時每一列的要求下方會顯示作業項目；<b>不符合通知單</b>的「違反條文」打字建議也會一併顯示，打「外包加工」就找得到對應條文。
                <br>⑸查檢表顯示的是<b>目前最新</b>的作業項目（不存快照），所以文件的項目改了，舊查檢表打開也會跟著是新的說法；<b>列印版不印作業項目</b>，紙本版面維持原樣。
                <br>⑹在 AS 文件管理刪掉某個作業項目時，條文這邊已經挑好的對應會一起解除（存檔後會告訴你解除了幾筆）。</li>
            <li><b>列印</b>：每張表都是 A4，公司全名、表頭表單名稱、頁尾右下角的 AS 文件編號都由「設定」裡綁定的 AS 文件推導，<b>版次依該單據的日期回推當時生效的版次</b>。按下列印會留下列印紀錄（列印與簽核紀錄頁查得到）。</li>
        </ul>

        <h4>設定入口</h4>
        <ul>
            <li><b>設定</b>（右上工具列，限內稽管理員）：七份表單各自的 AS 文件編號綁定、簽章圖章模板、核准／審查格的簽章人來源、<b>自動簽核開關</b>、到期提醒天數、會議主旨預設文字、<b>稽核通知單「備註」預設文字</b>。</li>
            <li><b>年度計畫的「審查／核准」是誰</b>：一律依設定裡<b>「審查格」「核准格」</b>解析（管理代表／最高核准人員／稽核組長／製表人），<b>不是按下按鈕的那個人</b>——這樣畫面上顯示的跟列印蓋出來的章才會是同一個人。設成「留白，紙本手蓋」時才會記成實際操作者。
                另可開啟<b>自動簽核</b>：按「送審」時直接一併完成核准（業務日期＝送出日，簽核時間會與送出時間錯開 5～180 分鐘且不跨日）。</li>
            <li><b>稽核通知單的備註要怎麼改</b>：按「新增稽核通知單」時自動帶進備註欄的那段文字，內容在<b>設定 → 稽核通知單「備註」預設文字</b>（<b>全站只有一份</b>，限內稽管理員）。改了只影響<b>之後新增</b>的通知單，已經建好的舊單不會被改動；每一張單仍可各自修改自己的備註。整段清空並儲存＝新增時不帶備註，按「還原內建預設文字」可帶回紙本 2-GM-06-02 印好的附註。</li>
            <li><b>受稽單位</b>（右上工具列，限內稽管理員）：把多個部門綁成同一個受稽單位。</li>
            <li><b>稽核範本</b>（右上工具列，限內稽管理員）：預先設定「稽核起始主過程→受稽單位→稽核員／陪檢員從哪些部門挑」，填通知單時一列選一個就帶入。</li>
            <li><b>範本組合＝把常一起稽核的那幾個範本存成一組</b>（2026-09-14 起）：在「稽核範本」跳窗下方的<b>範本組合</b>建立，
                填稽核通知單時按受稽單位區塊的「<b>帶入範本組合</b>」選一次，那幾列就整批長出來，不必一列一列挑。
                組合只記「有哪些範本」，所以<b>範本本身改了組合帶出來的內容就跟著改</b>；組合裡的範本被停用或刪除時那一列自動不帶，其餘照常。
                表格上<b>已經有的起始主過程不會重複加</b>（同一次稽核裡本來就不可以重複），帶完會告訴你跳過了哪幾個。</li>
            <li><b>稽核員資格</b>（右上工具列，限內稽管理員）：稽核員／陪檢員的合格名單（依職位／職位＋指定人員，可設任期）。</li>
            <li><b>稽核小組</b>（右上工具列，限內稽管理員）：逐年度的小組成員與稽核組長，供會議紀錄自動帶入。</li>
            <li><b>AS條文題庫</b>（右上工具列，限內稽管理員）：AS稽核查檢表的題目來源，建一次每年沿用。</li>
            <li>部門清單來自組織架構（部門管理），簽章人來源與管理代表來自「組織角色綁定設定」，人員清單來自員工管理，這裡都不另存一份。</li>
        </ul>

        <h4>權限角色</h4>
        <div id="helpRoleBox">（載入中…）</div>
    </div>
    <div class="ia-mfoot"><button data-close>關閉</button></div>
</div></div>

<!-- ============================ 角色說明（即時查現況，鐵律4） ============================ -->
<div class="ia-mask" id="roleHelpMask"><div class="ia-modal narrow">
    <div class="ia-mhead"><h4><i class="fa fa-users"></i> 內部稽核　角色權限說明</h4><span class="x" data-close>&times;</span></div>
    <div class="ia-mbody help-doc"><div id="roleHelpBox">（載入中…）</div></div>
    <div class="ia-mfoot"><button data-close>關閉</button></div>
</div></div>

<!-- ============================ 設定 ============================ -->
<div class="ia-mask" id="settingMask"><div class="ia-modal">
    <div class="ia-mhead"><h4><i class="fa fa-cog"></i> 內部稽核　設定</h4><span class="x" data-close>&times;</span></div>
    <div class="ia-mbody">
        <div class="ia-sec"><h5>AS 文件編號綁定</h5>
            <div class="ia-hint">列印時的<b>表頭表單名稱</b>與<b>頁尾右下角編號</b>都由這裡的綁定推導，不寫死。點「選擇」可打編號即時篩選。</div>
            <div class="ia-table-wrap"><table class="ia-table"><thead><tr>
                <th style="width:200px;">表單</th><th>目前綁定</th><th style="width:120px;">操作</th>
            </tr></thead><tbody id="setAsBody"></tbody></table></div>
        </div>
        <div class="ia-sec"><h5>列印簽章</h5>
            <div class="ia-form">
                <label>圖章模板</label>
                <div><select id="setStampTpl" data-eg-filter="輸入模板名稱篩選…"></select></div>
                <label>核准格</label>
                <div><select id="setSignApprove"></select></div>
                <label>審查格</label>
                <div><select id="setSignReview"></select></div>
                <label>自動簽核</label>
                <div>
                    <label style="font-weight:normal;cursor:pointer;">
                        <input type="checkbox" id="setAutoSign" style="vertical-align:-2px;">
                        年度計畫表按下「送審」時，直接完成審查與核准
                    </label>
                    <br>
                    <!-- 稽核通知單有自己的開關（2026-09-18 使用者回報：原本只有年度計畫表那一個） -->
                    <label style="font-weight:normal;cursor:pointer;">
                        <input type="checkbox" id="setAutoSignCase" style="vertical-align:-2px;">
                        稽核通知單按下「完成」時，直接完成審查與核准
                    </label>
                    <div style="font-size:12px;color:#8a6d45;margin-top:3px;">
                        關閉（預設）＝送審後仍要有人按「核准」。<br>
                        開啟後：<b>審查人與核准人＝上面兩格設定的人</b>（不是按下按鈕的人），
                        <b>業務日期＝送出日</b>，簽核時間會刻意與送出時間錯開 5～180 分鐘且不跨日（ai-rules/21）。
                    </div>
                </div>
                <label>&nbsp;</label>
                <div style="font-size:12px;color:#8a6d45;">
                    <b>核准格／審查格決定的不只是列印的圖章</b>——畫面上狀態列顯示的「審查：○○○／核准：○○○」
                    也是依這裡解析，兩邊一定一致。設成「留白，紙本手蓋」時才會記成實際按下按鈕的人。
                </div>
            </div>
        </div>
        <div class="ia-sec"><h5>缺失到期提醒</h5>
            <div class="ia-form">
                <label>提前幾天</label>
                <div><input type="text" id="setRemindDays" style="width:90px;"> 天（0～365；設 0＝只在逾期後提醒）
                     <div class="err-msg" id="errRemindDays"></div></div>
                <label>&nbsp;</label><div></div>
            </div>
        </div>
        <div class="ia-sec"><h5>稽核通知單「備註」預設文字</h5>
            <div class="ia-hint">按「新增稽核通知單」時會把這段文字<b>自動帶進備註欄</b>（每一張單都還是可以各自改，改過的舊單不受影響）。
                <b>整個清空並儲存＝新增時不帶任何備註</b>；按「還原內建預設文字」可以帶回紙本 2-GM-06-02 上印好的那段附註。</div>
            <div><textarea id="setCaseRemark" rows="6" style="width:100%;" placeholder="留空＝新增通知單時不自動帶入備註"></textarea>
                 <div class="err-msg" id="errCaseRemark"></div>
                 <div style="margin-top:4px;"><button type="button" id="btnCaseRemarkDefault">還原內建預設文字</button>
                      <span style="font-size:12px;color:#8a6d45;margin-left:6px;" id="setCaseRemarkCnt"></span></div>
            </div>
        </div>
        <div class="ia-sec"><h5>會議主旨預設文字</h5>
            <div class="ia-form">
                <label>事前會議</label><div><input type="text" id="setMeetPre" placeholder="留空＝○○年度 內稽事前會議"></div>
                <label>結束會議</label><div><input type="text" id="setMeetEnd" placeholder="留空＝○○年度 內稽結束會議"></div>
            </div>
        </div>
    </div>
    <div class="ia-mfoot"><button data-close>關閉</button><button id="btnSettingSave" class="btn-warm">儲存設定</button></div>
</div></div>

<!-- ============ AS 文件挑選（條文題庫的「建立的文件、表單」用；打編號或名稱模糊篩選後多選） ============ -->
<div class="ia-mask" id="docPickMask"><div class="ia-modal">
    <div class="ia-mhead"><h4><i class="fa fa-files-o"></i> 選擇文件、表單</h4>
        <span class="x" data-close style="margin-left:auto;">&times;</span></div>
    <div class="ia-mbody">
        <div class="ia-hint">資料來源就是<b>AS 文件管理</b>的文件清單，打<b>文件編號或名稱</b>都可以篩選（空白分隔可多個關鍵字），勾選後按「加入」。</div>
        <input type="text" id="docPickKw" placeholder="輸入文件編號或名稱篩選…　例：2-SM　或　客戶基本資料"
               data-eg-skip autocomplete="off" style="width:100%;margin-bottom:6px;">
        <div id="docPickBox"></div>
        <div style="font-size:12px;color:#8a6d45;margin-top:5px;" id="docPickCnt"></div>
    </div>
    <div class="ia-mfoot"><button data-close>取消</button><button id="btnDocPickOk" class="btn-warm">加入</button></div>
</div></div>

<!-- ============================ AS 條文題庫 ============================ -->
<div class="ia-mask" id="clauseMask"><div class="ia-modal wide">
    <div class="ia-mhead"><h4><i class="fa fa-list-ol"></i> AS 稽核查檢表　條文題庫</h4>
        <button id="btnClauseAdd" style="margin-left:auto;"><i class="fa fa-plus"></i> 新增條文</button>
        <span class="x" data-close style="margin-left:10px;">&times;</span></div>
    <div class="ia-mbody">
        <div class="ia-hint">這是<b>AS稽核查檢表</b>的題目來源，建一次每年沿用。勾「章節標題」的列在查檢表上只當分隔標題、不判定合格與否。
        已被既有查檢表引用的條文按刪除會自動改成停用（不再出現在新表），舊表內容不受影響。<br>
        <b>順序用拖的</b>：抓住左邊的 <i class="fa fa-bars"></i> 上下拖曳，放開就自動重新編號並存檔，不必手動輸入數字。
        「建立的文件、表單」按<b>＋選文件</b>可以打編號或名稱模糊篩選後多選。<br>
        <b>改完自動儲存</b>：文字欄位離開欄位、勾選框點下去當下就會存，右側會顯示「已儲存」，不必再按存檔鈕。<br>
        <b>作業項目</b>＝這一條實務上在查哪幾件事（品管檢測／外包加工…）。項目本身設在
        <b>AS 文件管理 → 該文件的 ⚙ → 作業項目</b>（一份文件可以有好幾個），這裡只挑「這一條對應到哪幾個」，
        候選就是左欄那幾份文件底下的項目；勾好之後，<b>建立查檢表</b>可以直接用它一鍵挑題、<b>填寫查檢表</b>時每一列也看得到。</div>
        <div class="ia-table-wrap"><table class="ia-table"><thead><tr>
            <th style="width:64px;">順序</th><th style="width:70px;">標題列</th><th>品質管理系統要求</th>
            <th style="width:240px;">建立的文件、表單</th>
            <th style="width:230px;" title="這一條實務上對應到哪幾件事，勾了才會顯示在建立／填寫查檢表上">作業項目</th>
            <th style="width:70px;">啟用</th><th style="width:110px;">操作</th>
        </tr></thead><tbody id="clauseBody"></tbody></table></div>
    </div>
    <div class="ia-mfoot"><button data-close>關閉</button></div>
</div></div>

<!-- ============================ 年度計畫：選受稽單位 ============================ -->
<div class="ia-mask" id="planDeptMask"><div class="ia-modal narrow">
    <div class="ia-mhead"><h4><i class="fa fa-sitemap"></i> 選擇受稽單位</h4><span class="x" data-close>&times;</span></div>
    <div class="ia-mbody">
        <div class="ia-hint">勾選這一年度要列進計畫表的單位（就是表格上方那一排欄）。<b>取消勾選會連同該單位已排的格子一起移除。</b></div>
        <div class="pick-wrap" id="planDeptPick"></div>
    </div>
    <div class="ia-mfoot"><button data-close>取消</button><button id="btnPlanDeptSave" class="btn-warm">確定</button></div>
</div></div>

<!-- ============================ 稽核通知單 ============================ -->
<div class="ia-mask" id="caseMask"><div class="ia-modal wide">
    <div class="ia-mhead"><h4 id="caseTitle">稽核通知單</h4><span class="x" data-close>&times;</span></div>
    <div class="ia-mbody">
        <!-- 這一年度還沒建稽核小組時的提示（2026-09-16 使用者要求：建通知單前先組好小組） -->
        <div id="caseTeamHint" class="ia-hint" style="display:none;background:#FDF3E3;border-color:#E9C892;"></div>
        <div class="ia-sec"><h5>基本資料</h5>
            <div class="ia-form">
                <label>稽核件號</label><div><input type="text" id="cNo" readonly data-ro-always placeholder="存檔後依通知日期自動產生"></div>
                <label>次別</label><div><input type="text" id="cSeq" readonly data-ro-always></div>
                <label>通知日期<span style="color:#DD5138;">*</span></label>
                <div><input type="date" id="cNotify"><div class="err-msg" id="errCNotify"></div></div>
                <label>稽核組長</label><div><select id="cLeader" data-eg-filter="輸入人員姓名篩選…"></select></div>
                <label>稽核起</label><div><input type="date" id="cFrom"></div>
                <label>稽核迄</label><div><input type="date" id="cTo"><div class="err-msg" id="errCTo"></div></div>
                <label>結束會議</label><div><input type="date" id="cMeetDate"></div>
                <label>時間</label>
                <div><input type="text" id="cMeetStart" data-eg-hint="直接輸入，例 16:00（打 1600 或 16 也可以）" style="width:80px;"> ～
                     <input type="text" id="cMeetEnd" data-eg-hint="直接輸入，例 16:30" style="width:80px;">
                     <div class="err-msg" id="errCMeetTime"></div></div>
                <label>地點</label><div class="full"><input type="text" id="cMeetPlace" placeholder="二樓會議室"></div>
<?php if ($perms['canAdmin']): ?>
                <label>製表人</label>
                <div><select id="cMaker" data-eg-filter="輸入姓名篩選…"></select></div>
                <!-- 製表日期不可晚於通知日期（與簽章同一張紙、同一條規則）；後端存檔時同規則再擋一次 -->
                <label>製表日期</label>
                <div><input type="date" id="cMakerDate"><div class="err-msg" id="errCMakerDate"></div></div>
<?php endif; ?>
                <label>備註</label><div class="full"><textarea id="cRemark"></textarea></div>
            </div>
        </div>
        <div class="ia-sec"><h5>受稽單位　<span class="lock-note">在最後一列按 ↓ 自動加一列</span>
<?php if ($perms['canAdmin']): ?>
            <button id="btnCaseTplSet" style="margin-left:10px;height:24px;font-size:12px;padding:0 10px;border:1px solid #D8BE93;border-radius:4px;background:#fff;cursor:pointer;"><i class="fa fa-object-group"></i> 帶入範本組合</button>
<?php endif; ?>
        </h5>
            <div class="err-msg" id="cDupWarn" style="margin-bottom:4px;"></div>
            <div class="err-msg" id="errCAudited" style="margin-bottom:4px;"></div>
            <!-- 送出（完成）前的必填檢查結果（2026-09-18 使用者要求） -->
            <div class="err-msg" id="errCRequired" style="margin-bottom:4px;"></div>
            <!-- 受稽時間的設定與試算結果（放這裡不會影響表格欄寬） -->
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;font-size:12px;color:#6b5535;margin-bottom:4px;">
                <b style="color:#8A5A2B;">受稽時間</b>
                <span>每單位 <input type="text" id="cTimeStep" value="60" data-eg-skip
                      style="width:34px;border:1px solid #D8BE93;border-radius:3px;padding:1px 4px;font-size:12px;"> 分</span>
                <span>結束 <input type="text" id="cTimeTo" readonly data-ro-always
                      title="依開始時間與每單位分鐘自動算出來的結束時間"
                      style="width:52px;background:#f5efe4;cursor:not-allowed;border:1px solid #D8BE93;border-radius:3px;padding:1px 4px;font-size:12px;">
                      <span style="color:#a08356;">（自動算）</span></span>
                <label style="font-weight:normal;margin:0;cursor:pointer;">
                    <input type="checkbox" id="cTimeCascade" checked data-eg-skip style="vertical-align:-1px;">
                    改一列就自動順延後面</label>
                <span id="cTimeCalc" style="color:#a08356;">填開始時間就會自動算出結束時間（跳過午休 12:00~13:00，最後一個單位要在結束會議前 30 分鐘做完）</span>
            </div>
            <div class="err-msg" id="cEscWarn" style="margin-bottom:4px;"></div>
            <div class="ia-table-wrap"><table class="ia-table"><thead><tr>
                <th style="width:170px;">帶入範本</th>
                <th style="width:170px;">稽核起始主過程</th><th style="width:140px;">受稽單位</th>
                <th style="width:165px;">稽核員</th><th style="width:165px;">陪檢員</th>
                <!-- 全部同一天是常態（一天內跑完所有單位），所以直接在表頭給一個「全部」按鈕，
                     不必一列一列點日曆（2026-09-16 使用者要求） -->
                <th style="width:150px;">受稽日期
                    <div class="ia-allday"><input type="date" id="cAllAudited"><button type="button" id="btnAllAudited" title="把這個日期套用到下面每一列">全部</button></div>
                </th>
                <!-- 受稽時間自動排（2026-09-17 使用者要求）：一天跑好幾個單位時一列一列打時間很花時間，
                     這裡填開始時間就依間隔往後排，並自動跳過午休。 -->
                <!-- 表頭只留控制項：說明與試算結果一律放到表格上方那一整列，
                     擠在這裡會把「時間」欄撐寬（2026-09-18 使用者回報）。 -->
                <th style="width:96px;">時間
                    <div class="ia-allday" style="white-space:nowrap;">
                        <input type="text" id="cTimeFrom" data-eg-hint="開始時間，例 09:00" style="width:46px;"
                               title="填開始時間，結束時間會自動算出來">
                        <button type="button" id="btnAllTime" title="從開始時間起，依每個單位需要的分鐘往下排">自動排</button>
                    </div>
                </th>
                <th style="width:150px;">預定完成改善
                    <div class="ia-allday"><input type="date" id="cAllDue"><button type="button" id="btnAllDue" title="把這個日期套用到下面每一列">全部</button></div>
                </th>
                <th style="width:44px;"></th>
            </tr></thead><tbody id="cDeptBody" data-eg-row-add="caseRowAdd" data-eg-row-del="caseRowDel"></tbody></table></div>
        </div>
        <div class="ia-sec" id="cMeetingSec"><h5>會議紀錄</h5>
            <div class="ia-hint">會議紀錄走既有的「會議紀錄」模組，這裡只負責<b>自動建好草稿並帶入與會人員</b>，再開新分頁給你填。同一張通知單重複按不會建出第二筆。
            <br>與會人員＝<b>該年度的稽核小組成員</b>（工具列「稽核小組」設定），<b>主席固定為稽核組長</b>；還沒建小組時退回用這張通知單上各受稽單位的稽核員與陪檢員。
            <br>每個人的<b>部門與職稱是依「會議日期」回推當時的</b>，所以補舊年度的會議也會印當時那個職務，不會被現在的兼任職蓋掉。
            <br><b>名單上登記的職務，會議當天還不成立時會自動改印他當時真正的身分</b>（例：某人的兼任課長是 12/09 才生效，11/03 的會議就印他當時的工程師）——
            補舊資料時印上他當時還沒有的職稱會造成身分錯亂。發生時建立完會跳出提示告訴你換了誰。</div>
            <div id="cMeetingBox" style="font-size:13px;color:#5b3a1e;"></div>
        </div>
    </div>
    <div class="ia-mfoot">
        <button data-close>關閉</button>
        <button id="btnCasePrint"><i class="fa fa-print"></i> 列印</button>
<?php if ($perms['canAdmin']): ?>
        <button id="btnCaseDelete" class="btn-danger" style="display:none;"><i class="fa fa-trash"></i> 刪除</button>
<?php endif; ?>
        <button id="btnCaseSave" class="btn-warm">儲存</button>
<?php if ($perms['canAdmin']): ?>
        <!-- 完成＝這張通知單填好了，之後不可修改；要改回去得輸入操作確認密碼（2026-09-18 使用者要求） -->
        <button id="btnCaseComplete" class="btn-warm" style="display:none;"><i class="fa fa-check-circle"></i> 完成</button>
        <button id="btnCaseReopen" style="display:none;"><i class="fa fa-unlock"></i> 取消完成</button>
<?php endif; ?>
    </div>
</div></div>

<!-- ============================ 取消完成：操作確認密碼 ============================ -->
<div class="ia-mask" id="caseReopenMask"><div class="ia-modal" style="max-width:460px;">
    <div class="ia-mhead"><h4><i class="fa fa-unlock"></i> 取消完成</h4><span class="x" data-close>&times;</span></div>
    <div class="ia-mbody">
        <div class="ia-hint">取消完成之後這張通知單就可以再修改。
            <br><b>自動簽核蓋上的核准／審查會一併清掉</b>——內容要改，那兩個章就不成立了；
            改完重新按「完成」會再簽一次。</div>
        <div class="ia-form">
            <label>操作確認密碼<span style="color:#DD5138;">*</span></label>
            <div><input type="password" id="caseReopenPw" data-eg-skip autocomplete="new-password" style="width:220px;">
                 <div class="err-msg" id="errCaseReopen"></div></div>
        </div>
    </div>
    <div class="ia-mfoot"><button data-close>取消</button><button id="btnCaseReopenGo" class="btn-warm">確認取消完成</button></div>
</div></div>

<!-- ============================ 建立查檢表 ============================ -->
<div class="ia-mask" id="checkNewMask"><div class="ia-modal wide">
    <div class="ia-mhead"><h4><i class="fa fa-plus"></i> 建立查檢表</h4><span class="x" data-close>&times;</span></div>
    <div class="ia-mbody">
        <div class="ia-form">
            <label>種類<span style="color:#DD5138;">*</span></label>
            <div><select id="nkKind"></select>
                 <div id="nkKindHint" style="font-size:12px;color:#8a6d45;margin-top:3px;"></div></div>
            <label>建立（稽核）日期<span style="color:#DD5138;">*</span></label>
            <div><input type="date" id="nkDate"><div class="err-msg" id="errNkDate"></div></div>
            <label>稽核人</label><div><select id="nkAuditor" data-eg-filter="輸入人員姓名篩選…"></select></div>
            <label>所屬件號</label>
            <div><select id="nkCase" data-eg-filter="輸入件號或日期篩選…"></select>
                 <!-- 選了件號之後：自動帶受稽日期（多天可挑），並列出當天要稽核哪些主過程與單位
                      —— 不然使用者要另外開通知單才知道這次要查哪些表單（2026-09-18 使用者要求） -->
                 <span id="nkCaseDayWrap" style="display:none;margin-left:8px;font-size:12px;color:#6b5535;">
                     受稽日期 <select id="nkCaseDay" style="min-width:130px;"></select>
                 </span>
                 <div id="nkCaseInfo" class="ia-hint" style="display:none;margin-top:4px;"></div></div>
            <label id="nkYearLab">稽核年度</label>
            <div id="nkYearWrap"><input type="text" id="nkYearShow" readonly
                 style="width:120px;background:#f5efe4;border:1px solid #D8BE93;border-radius:4px;padding:3px 6px;">
                 <span style="font-size:12px;color:#8a6d45;">　績效執行稽核查檢表稽核的是<b>去年一整年</b>，年度由上面的建立日期自動推導，不分上／下半年。</span></div>
            <label id="nkSrcLab">自動判定來源</label>
            <div id="nkSrcWrap"><select id="nkSrc" style="min-width:320px;"></select>
                 <div style="font-size:12px;color:#8a6d45;margin-top:3px;">選一張已經填好的<b>系統稽核紀錄表</b>，建立時會自動把合格／不合格判定過來，
                 並在「所見證據或建議」列出是哪幾份表單不合格，方便兩張表互相比對。</div></div>
            <label>標題</label><div><input type="text" id="nkTitle" placeholder="留空＝用種類名稱"></div>
        </div>
        <div style="margin-top:10px;" class="nk-split">
            <!-- 左欄：作業項目標籤（只有 AS 查檢表有）。條文原文看不出實務上在查什麼，
                 點一下就只勾該用途的條文。項目設在 AS 文件管理 → ⚙ 作業項目，條文題庫逐條挑對應。
                 2026-09-14 起依 AS 文件編號的部門代碼分類，並移到左側分割欄（原本平鋪在上方看不完）。 -->
            <div class="nk-side" id="nkTaskSide" style="display:none;">
                <div class="nk-side-hd">
                    <b id="nkTaskTitle">依作業項目挑題</b>
                    <input type="text" id="nkTaskFilter" placeholder="篩選作業項目…">
                </div>
                <div class="nk-side-body" id="nkTaskGroups"></div>
            </div>
            <div class="nk-main">
                <!-- 已選的標籤另外列在這裡，方便確認自己挑了什麼（使用者 2026-09-14 要求） -->
                <div class="nk-sel" id="nkTaskSel" style="display:none;"></div>
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;flex-wrap:wrap;">
                    <b style="color:#8A5A2B;font-size:14px;">這次要查的項目</b>
                    <input type="text" id="nkFilter" placeholder="輸入關鍵字篩選…" style="border:1px solid #D8BE93;border-radius:4px;padding:3px 8px;font-size:13px;width:200px;">
                    <button id="nkAll" style="height:26px;font-size:12px;border:1px solid #D8BE93;border-radius:4px;background:#fff;cursor:pointer;">全選</button>
                    <button id="nkNone" style="height:26px;font-size:12px;border:1px solid #D8BE93;border-radius:4px;background:#fff;cursor:pointer;">全不選</button>
                    <label style="font-size:12px;color:#8a6d45;font-weight:normal;margin:0;cursor:pointer;">
                        <input type="checkbox" id="nkAutoPick" data-eg-skip style="vertical-align:-1px;">
                        點標籤時自動勾選底下的表單</label>
                    <span id="nkCount" style="font-size:12px;color:#8a6d45;"></span>
                </div>
                <div class="pick-wrap" id="nkPick" style="max-height:360px;"></div>
                <div class="err-msg" id="errNkPick"></div>
            </div>
            <!-- 右欄：已選擇的項目（2026-09-17 使用者要求）。標籤／部門只負責「聚焦顯示」，
                 選好的一律留在這裡，取消標籤不會連帶取消選取。 -->
            <div class="nk-selside" id="nkSelSide">
                <div class="nk-selside-hd"><b id="nkSelHd">已選擇的表單</b>
                    <span class="sub" id="nkSelSub">從中間清單勾選，這裡就會列出來</span></div>
                <div class="nk-selside-body" id="nkSelBody"></div>
                <div class="nk-selside-ft"><a href="javascript:void(0)" id="nkSelClear" style="color:#C4442D;">全部取消選取</a></div>
            </div>
        </div>
    </div>
    <div class="ia-mfoot"><button data-close>取消</button><button id="btnCheckCreate" class="btn-warm">建立</button></div>
</div></div>

<!-- ============================ 查檢表填寫 ============================ -->
<div class="ia-mask" id="checkMask"><div class="ia-modal wide">
    <div class="ia-mhead"><h4 id="ckTitle">查檢表</h4><span class="x" data-close>&times;</span></div>
    <div class="ia-mbody">
        <div class="ia-form" style="margin-bottom:10px;">
            <label>標題</label>
            <div><input type="text" id="ckTitleInput">
                 <span id="ckTitleAuto" style="display:none;font-size:11px;color:#8a6d45;">（自動：對應稽核通知單的次別）</span></div>
            <label>稽核日期</label><div><input type="date" id="ckDate"></div>
            <label>稽核人</label><div><select id="ckAuditor" data-eg-filter="輸入人員姓名篩選…"></select></div>
            <label>狀態</label><div><input type="text" id="ckStatus" readonly></div>
        </div>
        <div id="ckAutoBar" class="ia-hint" style="display:none;"></div>
        <div class="ia-table-wrap"><table class="ia-table"><thead id="ckHead"></thead><tbody id="ckBody"></tbody></table></div>
    </div>
    <div class="ia-mfoot">
        <button data-close>關閉</button>
        <button id="btnCheckAddItem"><i class="fa fa-plus"></i> 新增項目</button>
        <button id="btnCheckPrint"><i class="fa fa-print"></i> 列印</button>
        <button id="btnCheckAuto" class="btn-warm"><i class="fa fa-magic"></i> 一鍵開立</button>
        <button id="btnCheckReopen">取消結案</button>
        <button id="btnCheckSave">儲存</button>
        <button id="btnCheckDone" class="btn-warm">結案</button>
    </div>
</div></div>

<!-- ============================ 查檢表：補加項目（2026-09-21 使用者要求） ============================ -->
<div class="ia-mask" id="ckAddMask"><div class="ia-modal">
    <div class="ia-mhead"><h4><i class="fa fa-plus"></i> 新增查檢項目</h4><span class="x" data-close>&times;</span></div>
    <div class="ia-mbody">
        <div class="ia-hint">只列出<b>這張表還沒有的項目</b>；勾選後按「加入」會接在表格最後面，
            存檔時會再依受稽人部門與表單編號自動重排。<b>已結案的查檢表不能增刪項目</b>（要先取消結案）。</div>
        <div style="margin-bottom:6px;">
            <input type="text" id="ckAddKw" data-eg-skip placeholder="輸入編號或名稱篩選…" style="width:260px;">
            <span style="margin-left:8px;font-size:12px;color:#8a6d45;" id="ckAddCnt"></span>
            <a href="javascript:void(0)" id="ckAddAll" style="margin-left:10px;font-size:12px;">全選目前顯示</a>
            <a href="javascript:void(0)" id="ckAddNone" style="margin-left:8px;font-size:12px;color:#C4442D;">全部取消</a>
        </div>
        <div id="ckAddList" style="max-height:52vh;overflow:auto;border:1px solid #E8D5B5;border-radius:6px;
             background:#fff;padding:6px 10px;font-size:13px;"></div>
    </div>
    <div class="ia-mfoot"><button data-close>取消</button>
        <button id="btnCkAddGo" class="btn-warm">加入</button></div>
</div></div>

<!-- ============================ 稽核報告表：通知對象設定 ============================ -->
<div class="ia-mask" id="rptNotifyMask"><div class="ia-modal">
    <div class="ia-mhead"><h4><i class="fa fa-bell"></i> 稽核報告表送出後要通知誰</h4><span class="x" data-close>&times;</span></div>
    <div class="ia-mbody">
        <div class="ia-hint">一條規則是「<b>部門 × 職位</b>」：兩個都選＝該部門掛這個職位的人；
            只選部門＝該部門全部的人；只選職位＝全公司掛這個職位的人。<b>兩個都不選的那一列會被忽略</b>（否則等於全公司廣播）。
            <br>勾「含子部門」時，該部門底下的組室也一起通知（組織是樹狀的，只比單一部門會漏掉底下的組）。
            <br>通知一律送給<b>目前在職</b>的人。</div>
        <div class="ia-table-wrap"><table class="ia-table"><thead><tr>
            <th style="width:210px;">部門</th><th style="width:70px;">含子部門</th><th style="width:210px;">職位</th><th style="width:44px;"></th>
        </tr></thead><tbody id="rptNotifyBody"></tbody></table></div>
        <button id="btnRptNotifyAdd" style="margin-top:6px;height:26px;font-size:12px;border:1px solid #D8BE93;border-radius:4px;background:#fff;cursor:pointer;"><i class="fa fa-plus"></i> 增加一條</button>
        <div style="margin-top:10px;font-size:13px;color:#5b3a1e;">
            <b>目前會通知到</b>（<span id="rptNotifyCount">0</span> 人）：<span id="rptNotifyPreview" style="color:#8a6d45;"></span>
        </div>
    </div>
    <div class="ia-mfoot"><button data-close>取消</button><button id="btnRptNotifySave" class="btn-warm">儲存設定</button></div>
</div></div>

<!-- ============================ 一鍵開立不符合通知單（批次） ============================ -->
<div class="ia-mask" id="ckBulkMask"><div class="ia-modal">
    <div class="ia-mhead"><h4><i class="fa fa-magic"></i> 一鍵開立不符合通知單</h4><span class="x" data-close>&times;</span></div>
    <div class="ia-mbody">
        <div class="ia-hint" id="ckBulkHint"></div>
        <div class="ia-form">
            <label>不合格類型<span style="color:#DD5138;">*</span></label>
            <div><select id="ckBulkType"></select>
                 <div class="err-msg" id="ckBulkTypeErr"></div>
                 <div style="font-size:11px;color:#8a6d45;margin-top:2px;">紙本上這一欄是稽核員判斷的，系統不替您決定；這批先統一用同一種，開完可以逐張再改。</div></div>
        </div>
        <div class="ia-table-wrap" style="max-height:240px;">
            <table class="ia-table"><thead><tr><th>項次</th><th>項目</th><th>受稽人</th></tr></thead>
            <tbody id="ckBulkList"></tbody></table>
        </div>
    </div>
    <div class="ia-mfoot">
        <button data-close>取消</button>
        <button id="btnCkBulkGo" class="btn-warm"><i class="fa fa-magic"></i> 開始開立</button>
    </div>
</div></div>

<!-- ============================ 不符合通知單 ============================ -->
<div class="ia-mask" id="ncMask"><div class="ia-modal wide">
    <div class="ia-mhead"><h4 id="ncTitle">內稽不符合通知單</h4><span class="x" data-close>&times;</span></div>
    <div class="ia-mbody">
        <div id="ncProxyNote" class="ia-hint ia-proxy" style="display:none;">
            <b>您正在代替受稽單位填寫這一段。</b>送出後會在下方歷程留下代填紀錄。
        </div>

        <!-- 段一：稽核員 -->
        <div class="ia-sec" id="ncSec1"><h5>一、稽核員填寫<span class="lock-note" id="ncSec1Lock"></span></h5>
            <div class="ia-form">
                <label>IA編號</label><div><input type="text" id="nNo" readonly></div>
                <label>稽核日期</label><div><input type="text" id="nAuditDate" readonly></div>
                <label>受稽核單位</label><div><input type="text" id="nDept" readonly></div>
                <label>受審核人</label><div><select id="nAuditee" data-eg-filter="輸入人員姓名篩選…"></select></div>
                <label>不合格事實<span style="color:#DD5138;">*</span></label>
                <div class="full"><textarea id="nFact"></textarea><div class="err-msg" id="errNFact"></div></div>
                <label>不合格類型<span style="color:#DD5138;">*</span></label>
                <div><select id="nType"></select><div class="err-msg" id="errNType"></div></div>
                <label>相關表單編號</label><div><input type="text" id="nFormNo" placeholder="例 2-SM-02-01"></div>
                <label>違反條文</label><div class="full"><input type="text" id="nClause" placeholder="例 8.3.3設計與開發的輸入 d)組織承諾採用的標準及規範"></div>
                <label>要求完成期限</label><div><input type="date" id="nDue"><div class="err-msg" id="errNDue"></div></div>
                <label>稽核員</label>
                <div><select id="nAuditor" data-eg-filter="輸入人員姓名篩選…"></select>
                     <span id="nAuditorNote" style="font-size:12px;color:#8a6d45;"></span>
                     <div class="err-msg" id="errNAuditor"></div></div>
            </div>
            <div class="ia-att" id="ncAttsec1" data-sec="sec1">
                <div class="ia-att-h">佐證附件（不合格事實） <em>（可附照片、掃描檔、Excel／PDF，單檔 20MB 以內）</em></div>
                <div class="ia-att-list"></div>
                <div class="ia-att-up">
                    <input type="file" class="ia-att-file" data-eg-skip multiple>
                    <input type="text" class="ia-att-note" data-eg-skip data-eg-hint="這份附件是什麼，例：改善後的檢驗紀錄" placeholder="說明（選填）">
                    <button type="button" class="ia-att-btn"><i class="fa fa-upload"></i> 上傳</button>
                    <span class="ia-att-msg"></span>
                </div>
            </div>
            <div style="text-align:right;margin-top:8px;">
                <button id="btnNcSaveSec1" style="height:30px;font-size:13px;padding:0 14px;border:1px solid #d98a33;border-radius:4px;background:#F0A24B;color:#fff;cursor:pointer;">儲存稽核員填寫區</button>
            </div>
        </div>

        <!-- 段二：受稽單位 -->
        <div class="ia-sec" id="ncSec2"><h5>二、受稽單位回覆<span class="lock-note" id="ncSec2Lock"></span>
            <span id="btnSec2Unlock" style="display:none;font-size:12px;font-weight:normal;margin-left:8px;
                  cursor:pointer;color:#C4442D;text-decoration:underline;">解鎖修改</span></h5>
            <div class="ia-form">
                <label>原因分析<span style="color:#DD5138;">*</span></label>
                <div class="full"><textarea id="nCause"></textarea><div class="err-msg" id="errNCause"></div></div>
                <label>糾正措施<span style="color:#DD5138;">*</span></label>
                <div class="full with-due">
                    <div class="wd-main"><textarea id="nCorr"></textarea><div class="err-msg" id="errNCorr"></div></div>
                    <div class="wd-due"><div class="wd-lab">完成時間<span style="color:#DD5138;">*</span></div>
                         <input type="date" id="nCorrDue"><div class="err-msg" id="errNCorrDue"></div></div>
                </div>
                <label>預防措施<span style="color:#DD5138;">*</span></label>
                <div class="full with-due">
                    <div class="wd-main"><textarea id="nPrev"></textarea><div class="err-msg" id="errNPrev"></div></div>
                    <div class="wd-due"><div class="wd-lab">預計完成時間<span style="color:#DD5138;">*</span></div>
                         <input type="date" id="nPrevDue"><div class="err-msg" id="errNPrevDue"></div></div>
                </div>
                <label>責任主管</label>
                <div><select id="nResp" data-eg-filter="輸入人員姓名篩選…"></select>
                     <span id="nRespNote" style="font-size:12px;color:#8a6d45;"></span></div>
                <label>簽核日期</label>
                <div><input type="date" id="nRespDate">
                     <span id="nRespDateNote" style="font-size:12px;color:#8a6d45;"></span>
                     <div class="err-msg" id="errNRespDate"></div></div>
            </div>
            <div class="ia-att" id="ncAttsec2" data-sec="sec2">
                <div class="ia-att-h">改善佐證附件 <em>（可附照片、掃描檔、Excel／PDF，單檔 20MB 以內）</em></div>
                <div class="ia-att-list"></div>
                <div class="ia-att-up">
                    <input type="file" class="ia-att-file" data-eg-skip multiple>
                    <input type="text" class="ia-att-note" data-eg-skip data-eg-hint="這份附件是什麼，例：改善後的檢驗紀錄" placeholder="說明（選填）">
                    <button type="button" class="ia-att-btn"><i class="fa fa-upload"></i> 上傳</button>
                    <span class="ia-att-msg"></span>
                </div>
            </div>
            <div style="text-align:right;margin-top:8px;">
                <button id="btnNcSaveSec2" style="height:30px;font-size:13px;padding:0 14px;border:1px solid #D8BE93;border-radius:4px;background:#fff;color:#5b3a1e;cursor:pointer;">暫存</button>
                <button id="btnNcSubmitSec2" style="height:30px;font-size:13px;padding:0 14px;border:1px solid #d98a33;border-radius:4px;background:#F0A24B;color:#fff;cursor:pointer;margin-left:6px;">送出回覆</button>
            </div>
        </div>

        <!-- 段三：驗證 -->
        <div class="ia-sec" id="ncSec3"><h5>三、稽核組長驗證<span class="lock-note" id="ncSec3Lock"></span>
            <span id="btnSec3Unlock" style="display:none;font-size:12px;font-weight:normal;margin-left:8px;
                  cursor:pointer;color:#C4442D;text-decoration:underline;">解鎖修改</span></h5>
            <div class="ia-form">
                <label>驗證描述<span style="color:#DD5138;">*</span></label>
                <div class="full"><textarea id="nVerify" placeholder="糾正和預防措施執行狀況驗證描述"></textarea>
                     <div class="err-msg" id="errNVerify"></div></div>
                <label>驗證結果<span style="color:#DD5138;">*</span></label>
                <div><select id="nVerifyRes">
                        <option value="">（請選擇）</option>
                        <option value="pass">通過，可結束</option>
                        <option value="fail">不通過，退回重提措施</option>
                     </select><div class="err-msg" id="errNVerifyRes"></div></div>
                <label>稽核組長</label>
                <div><input type="text" id="nLeader" readonly>
                     <span id="nLeaderNote" style="font-size:12px;color:#8a6d45;"></span></div>
                <label>簽核日期</label><div><input type="date" id="nLeaderDate"></div>
            </div>
            <div class="ia-att" id="ncAttsec3" data-sec="sec3">
                <div class="ia-att-h">驗證佐證附件 <em>（可附照片、掃描檔、Excel／PDF，單檔 20MB 以內）</em></div>
                <div class="ia-att-list"></div>
                <div class="ia-att-up">
                    <input type="file" class="ia-att-file" data-eg-skip multiple>
                    <input type="text" class="ia-att-note" data-eg-skip data-eg-hint="這份附件是什麼，例：改善後的檢驗紀錄" placeholder="說明（選填）">
                    <button type="button" class="ia-att-btn"><i class="fa fa-upload"></i> 上傳</button>
                    <span class="ia-att-msg"></span>
                </div>
            </div>
            <div style="text-align:right;margin-top:8px;">
                <button id="btnNcSaveSec3" style="height:30px;font-size:13px;padding:0 14px;border:1px solid #D8BE93;border-radius:4px;background:#fff;color:#5b3a1e;cursor:pointer;">暫存</button>
                <button id="btnNcSubmitSec3" style="height:30px;font-size:13px;padding:0 14px;border:1px solid #d98a33;border-radius:4px;background:#F0A24B;color:#fff;cursor:pointer;margin-left:6px;">送出驗證</button>
            </div>
        </div>

        <!-- 段四：管理代表 -->
        <div class="ia-sec" id="ncSec4"><h5>四、管理代表意見<span class="lock-note" id="ncSec4Lock"></span>
            <span id="nMgrWho" style="font-size:12px;color:#8a6d45;font-weight:normal;"></span></h5>
            <div class="ia-form">
                <label>管理代表意見</label><div class="full"><textarea id="nMgrNote"></textarea></div>
                <label>簽核日期</label><div><input type="date" id="nMgrDate"></div>
            </div>
            <div style="text-align:right;margin-top:8px;">
                <button id="btnNcSaveSec4" style="height:30px;font-size:13px;padding:0 14px;border:1px solid #D8BE93;border-radius:4px;background:#fff;color:#5b3a1e;cursor:pointer;">儲存意見</button>
                <button id="btnNcClose" style="height:30px;font-size:13px;padding:0 14px;border:1px solid #d98a33;border-radius:4px;background:#F0A24B;color:#fff;cursor:pointer;margin-left:6px;">結案</button>
            </div>
        </div>

        <div class="ia-log" id="ncLog"></div>
    </div>
    <div class="ia-mfoot">
        <button data-close>關閉</button>
        <button id="btnNcResend"><i class="fa fa-bell"></i> 重發通知</button>
        <button id="btnNcPrint"><i class="fa fa-print"></i> 列印</button>
        <button id="btnNcReopen">取消結案</button>
        <button id="btnNcDelete" class="btn-danger">刪除</button>
    </div>
</div></div>

<!-- ============================ 開立不符合通知單 ============================ -->
<div class="ia-mask" id="ncNewMask"><div class="ia-modal">
    <div class="ia-mhead"><h4><i class="fa fa-plus"></i> 開立內稽不符合通知單</h4><span class="x" data-close>&times;</span></div>
    <div class="ia-mbody">
        <div class="ia-hint">開立後會<b>立即通知受稽核單位主管</b>填寫原因分析與改善措施，期限前與逾期也會自動提醒。
        要通知誰由系統<b>依受稽核單位與稽核日期回推當時的單位主管</b>自動判定。</div>
        <div class="ia-form">
            <label>稽核日期<span style="color:#DD5138;">*</span></label>
            <div><input type="date" id="nnDate"><div class="err-msg" id="errNnDate"></div></div>
            <label>所屬件號</label><div><select id="nnCase"></select></div>
            <label>受稽核單位<span style="color:#DD5138;">*</span></label>
            <div><select id="nnDept" data-eg-filter="輸入單位名稱篩選…"></select><div class="err-msg" id="errNnDept"></div></div>
            <label>受審核人</label><div><select id="nnAuditee" data-eg-filter="輸入人員姓名篩選…"></select></div>
            <label>不合格事實<span style="color:#DD5138;">*</span></label>
            <div class="full"><textarea id="nnFact"></textarea><div class="err-msg" id="errNnFact"></div></div>
            <label>不合格類型<span style="color:#DD5138;">*</span></label>
            <div><select id="nnType"></select><div class="err-msg" id="errNnType"></div></div>
            <label>相關表單編號</label><div><input type="text" id="nnFormNo"></div>
            <label>違反條文</label>
            <div class="full">
                <!-- 綁定這份 AS 文件的條文直接列出來勾（2026-09-18 使用者要求）：
                     條文題庫的 doc_ref 本來就寫著「這一條建立了哪些文件表單」，反過來查就是這張表對應哪幾條。 -->
                <div id="nnClausePick" style="display:none;border:1px solid #E8D5B5;border-radius:5px;background:#FDF6EA;
                     padding:5px 8px;margin-bottom:4px;max-height:110px;overflow:auto;font-size:12px;"></div>
                <textarea id="nnClause" style="min-height:48px;"></textarea>
            </div>
            <label>要求完成期限</label><div><input type="date" id="nnDue"><div class="err-msg" id="errNnDue"></div></div>
        </div>
    </div>
    <div class="ia-mfoot"><button data-close>取消</button><button id="btnNcCreate" class="btn-warm">開立並通知</button></div>
</div></div>


<!-- ============================ 受稽單位群組 ============================ -->
<div class="ia-mask" id="unitMask"><div class="ia-modal">
    <div class="ia-mhead"><h4><i class="fa fa-sitemap"></i> 受稽單位設定</h4>
        <button id="btnUnitNew" style="margin-left:auto;"><i class="fa fa-plus"></i> 新增群組</button>
        <span class="x" data-close style="margin-left:10px;">&times;</span></div>
    <div class="ia-mbody">
        <div class="ia-hint">組織上是好幾個部門、稽核時卻是同一個單位的（例：<b>生產部＋生產1廠＋生產2廠＋生產3廠</b>），
        在這裡綁成一個<b>受稽單位</b>。綁定後：年度計畫表上是<b>一欄</b>、稽核報告表上是<b>一列</b>，
        稽核其中任何一個廠都會算成這個單位已執行（◎）；這個單位底下所有部門的人都算受稽單位的人，看得到並可回覆該單位的不符合通知單。
        <br>一個部門只能屬於一個受稽單位。沒有被綁進群組的部門，各自就是一個獨立的受稽單位。</div>
        <div class="ia-table-wrap"><table class="ia-table"><thead><tr>
            <th style="width:150px;">受稽單位名稱</th><th>涵蓋部門</th>
            <th style="width:110px;">代表部門</th><th style="width:110px;">操作</th>
        </tr></thead><tbody id="unitBody"></tbody></table></div>
    </div>
    <div class="ia-mfoot"><button data-close>關閉</button></div>
</div></div>

<!-- 群組編輯 -->
<div class="ia-mask" id="unitEditMask"><div class="ia-modal narrow">
    <div class="ia-mhead"><h4 id="unitEditTitle">受稽單位群組</h4><span class="x" data-close>&times;</span></div>
    <div class="ia-mbody">
        <div class="ia-form" style="grid-template-columns:100px 1fr;">
            <label>單位名稱<span style="color:#DD5138;">*</span></label>
            <div><input type="text" id="ueName" placeholder="例：生產部">
                 <div class="err-msg" id="errUeName"></div></div>
            <label>代表部門<span style="color:#DD5138;">*</span></label>
            <div><select id="ueMain"></select>
                 <div style="font-size:12px;color:#8a6d45;">資料一律掛在代表部門上，通常選最上層那個部門。</div>
                 <div class="err-msg" id="errUeMain"></div></div>
        </div>
        <div style="margin-top:10px;">
            <b style="color:#8A5A2B;font-size:14px;">涵蓋哪些部門</b>
            <span style="font-size:12px;color:#8a6d45;">（至少兩個；已被其他群組收編的部門會標示出來且不能選）</span>
            <div class="pick-wrap" id="uePick" style="max-height:300px;margin-top:5px;"></div>
            <div class="err-msg" id="errUePick"></div>
        </div>
    </div>
    <div class="ia-mfoot"><button data-close>取消</button><button id="btnUnitSave" class="btn-warm">儲存</button></div>
</div></div>

<!-- ============================ 稽核員／陪檢員資格名單 ============================ -->
<div class="ia-mask" id="qualifyMask"><div class="ia-modal">
    <div class="ia-mhead"><h4><i class="fa fa-user-plus"></i> 稽核員／陪檢員資格名單</h4><span class="x" data-close>&times;</span></div>
    <div class="ia-mbody">
        <div class="ia-hint">設定誰可以被指派為<b>稽核員</b>或<b>陪檢員</b>，有兩種設法，<b>兩種可以並用</b>：
        <br>①<b>依職位</b>（下半部的勾選清單）＝認到<b>部門＋職稱</b>、<b>不指定人名</b>。<b>人名是建稽核通知單的當下才抓</b>該部門該職稱**當時**的在職人員，
        所以<b>人員異動、離職、新人接任都不必回頭改名單</b>；同一個職稱有兩個人時兩位都會出現在候選裡，由填表人挑。
        兼任的職務是獨立一列（例：<b>品管課 課長</b> 與 <b>總經理室 總經理</b> 分開設定），所以「兼任才有資格」設定得出來。
        <br>②<b>職位＋指定人員</b>（上半部）＝只有<b>這一個人的這個職務</b>有資格，<b>同部門同職稱的其他人不會跟著有</b>。
        可以給<b>任期</b>（起／迄日，空＝不限）——<b>本人請假由代理人暫代</b>時就是用這個：把代理人加一列、期間填請假那幾天，過期自動失效，不必記得回來刪。
        <br>③<b>AS 文件負責人自動具備稽核員資格</b>（不必在這裡設定），期間比照
        <b>AS 文件管理 → 系統設定 → 結構總覽列印 → 修改（製表）簽章人員任期</b>；換人時這裡自動跟著換。
        <br>資格<b>一律以單據的業務日期判定</b>（稽核通知單＝稽核起日、查檢表＝稽核日期、製表人＝製表日期），
        所以補歷史單據時<b>當時在職、現在已離職的人一樣挑得到</b>，職稱也是當時的。<b>①②都留空＝不限制</b>。</div>
        <div class="ia-tabs" style="margin-top:4px;">
            <div class="ia-tab on q-tab" data-kind="auditor">稽核員</div>
            <div class="ia-tab q-tab" data-kind="escort">陪檢員</div>
        </div>

        <div id="qAsBox" style="font-size:12px;color:#5b3a1e;background:#FDF3E3;border:1px solid #E9C892;
             border-radius:4px;padding:6px 8px;margin-bottom:8px;display:none;"></div>

        <h5 style="margin:4px 0 6px;font-size:13px;color:#8a6d45;">職位＋指定人員（可設任期；代理人暫代就用這裡）</h5>
        <div style="display:flex;align-items:center;gap:6px;margin-bottom:6px;flex-wrap:wrap;">
            <select id="quAddPost" data-eg-filter="輸入部門、職稱或姓名篩選…"
                    style="min-width:280px;border:1px solid #D8BE93;border-radius:4px;padding:4px 6px;font-size:13px;"></select>
            <button id="btnQuAdd" class="btn-warm" style="height:28px;font-size:13px;">＋加入指定人員</button>
        </div>
        <div class="ia-table-wrap" style="max-height:170px;"><table class="ia-table"><thead><tr>
            <th style="width:120px;">部門</th><th style="width:100px;">職稱</th><th style="width:90px;">姓名</th>
            <th style="width:130px;">任期起</th><th style="width:130px;">任期迄</th><th>備註</th><th style="width:50px;">操作</th>
        </tr></thead><tbody id="quBody"></tbody></table></div>

        <h5 style="margin:12px 0 6px;font-size:13px;color:#8a6d45;">依職位（該職務上的人都有資格）</h5>
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
            <input type="text" id="qFilter" placeholder="輸入部門、職稱或姓名篩選…"
                   style="border:1px solid #D8BE93;border-radius:4px;padding:4px 8px;font-size:13px;width:230px;">
            <button id="qAll" style="height:26px;font-size:12px;border:1px solid #D8BE93;border-radius:4px;background:#fff;cursor:pointer;">全選</button>
            <button id="qNone" style="height:26px;font-size:12px;border:1px solid #D8BE93;border-radius:4px;background:#fff;cursor:pointer;">全部清空</button>
            <span id="qCount" style="font-size:12px;color:#8a6d45;"></span>
        </div>
        <div class="pick-wrap" id="qPick" style="max-height:260px;"></div>
    </div>
    <div class="ia-mfoot"><button data-close>關閉</button><button id="btnQualifySave" class="btn-warm">儲存這一分頁的名單</button></div>
</div></div>


<!-- ============================ 稽核小組（年度） ============================ -->
<div class="ia-mask" id="teamMask"><div class="ia-modal wide">
    <div class="ia-mhead"><h4><i class="fa fa-users"></i> 稽核小組</h4>
        <select id="teamYear" style="margin-left:12px;border:1px solid #D8BE93;border-radius:4px;padding:3px 6px;font-size:13px;"></select>
        <label style="margin:0 0 0 14px;font-size:13px;font-weight:normal;color:#6b5535;">基準日</label>
        <input type="date" id="teamBaseDate" style="margin-left:6px;border:1px solid #D8BE93;border-radius:4px;padding:3px 6px;font-size:13px;">
        <button id="btnTeamBaseReset" title="清空，改用系統推算值（過去年度＝該年年底／當年以後＝今天）"
                style="margin-left:4px;height:26px;font-size:12px;border:1px solid #D8BE93;border-radius:4px;background:#fff;cursor:pointer;color:#8a6d45;">預設</button>
        <span id="teamBaseNote" style="margin-left:8px;font-size:12px;color:#8a6d45;"></span>
        <span class="x" data-close style="margin-left:auto;">&times;</span></div>
    <div class="ia-mbody">
        <div class="ia-hint"><b>基準日</b>（右上）：候選人員、<b>誰在哪個職務</b>、部門與職稱名稱，全部以<b>這一天</b>判定
        ——沒有日期就無從判斷「當時」是誰在那個職務上。改日期會立刻重算候選清單與名單上每個人的部門職稱（先試算，按「儲存小組名單」才存）。
        留空＝<b>系統推算</b>（過去年度＝該年 12/31、當年度以後＝今天），按「預設」可清回去。
        <br>這一年度的內稽<b>是誰在做</b>——建稽核通知單前先組好，
        之後<b>自動建立會議紀錄時與會人員就是這份名單，主席固定為稽核組長</b>，不必每次重挑。
        <br><b>稽核組長只能有一位</b>；候選只列該年度有<b>稽核員或陪檢員資格</b>的職務（資格名單留空時＝全體）。
        <br>成員存的是「某人的某個職務」，所以會議紀錄與圖章上的<b>部門職稱會印當時那個職務的</b>，不會被兼任職蓋掉。
        <br><b>部門／職稱一律印「目前的名稱」</b>（改名不是改組織，同一個單位改過名就印新名）；但**是不是這個職務**仍以單據日期判定——
        名單登記的職務在該單據日期還不成立時，會自動改印他當時真正的身分。
        <br><b>同一個人可以用不同職務各掛一筆</b>（例：主職技術課工程師負責技術課的資料、兼任生管組組長負責生管組的，就掛兩筆）；
        <b>會議紀錄那邊仍只會列他一次</b>（取名單上排在前面的那個職務）。
        <br>常態小組每年差不多，可用右上「從其他年度複製」整批帶過來再增減；<b>原職務已異動或離職的成員會自動略過並列出來</b>。</div>
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;flex-wrap:wrap;">
            <select id="teamAddPost" data-eg-filter="輸入部門、職稱或姓名篩選…"
                    style="min-width:300px;border:1px solid #D8BE93;border-radius:4px;padding:4px 6px;font-size:13px;"></select>
            <select id="teamAddRole" style="border:1px solid #D8BE93;border-radius:4px;padding:4px 6px;font-size:13px;"></select>
            <button id="btnTeamAdd" class="btn-warm" style="height:28px;font-size:13px;">＋加入成員</button>
            <span style="margin-left:auto;"></span>
            <select id="teamCopyFrom" style="border:1px solid #D8BE93;border-radius:4px;padding:4px 6px;font-size:13px;"></select>
            <button id="btnTeamCopy" style="height:28px;font-size:13px;border:1px solid #D8BE93;border-radius:4px;background:#fff;cursor:pointer;">從其他年度複製</button>
        </div>
        <div class="ia-table-wrap"><table class="ia-table"><thead><tr>
            <th style="width:110px;">身分</th><th style="width:130px;">部門</th><th style="width:110px;">職稱</th>
            <th style="width:100px;">姓名</th><th>備註</th><th style="width:60px;">操作</th>
        </tr></thead><tbody id="teamBody"></tbody></table></div>
        <div id="teamMsg" style="font-size:12px;color:#8a6d45;margin-top:6px;"></div>
    </div>
    <div class="ia-mfoot"><button data-close>關閉</button><button id="btnTeamSave" class="btn-warm">儲存小組名單</button></div>
</div></div>


<!-- ============================ 稽核範本設定 ============================ -->
<div class="ia-mask" id="tplMask"><div class="ia-modal wide">
    <div class="ia-mhead"><h4><i class="fa fa-clone"></i> 稽核範本設定</h4>
        <button id="btnTplNew" style="margin-left:auto;"><i class="fa fa-plus"></i> 新增範本</button>
        <span class="x" data-close style="margin-left:10px;">&times;</span></div>
    <div class="ia-mbody">
        <div class="ia-hint">預先把「<b>稽核起始主過程 → 受稽單位 → 稽核員／陪檢員從哪些部門挑</b>」設定好，
        填稽核通知單時每一列選一個範本就自動帶入，不必每年重打。<br>
        候選部門是<b>多選</b>，實際人員仍由填表人挑；<b>候選範圍內只有一位有資格時會自動帶入</b>。
        <b>先決定稽核員</b>，陪檢員的候選會自動把稽核員本人排除掉（同一人不可兩邊都當）。陪檢員可以不填。
        候選部門只是<b>縮小挑選範圍</b>，實際要派幾位、派誰仍由填表人在通知單上決定（稽核員與陪檢員都可以多位）。</div>
        <div class="ia-table-wrap"><table class="ia-table"><thead><tr>
            <th style="width:150px;">稽核起始主過程</th><th style="width:120px;">受稽單位</th>
            <th>稽核員候選部門</th><th>陪檢員候選部門</th>
            <th style="width:70px;">啟用</th><th style="width:110px;">操作</th>
        </tr></thead><tbody id="tplBody"></tbody></table></div>

        <!-- 範本組合（2026-09-14 使用者交辦）：常一起稽核的那幾個範本存成一組，
             填通知單時選一次就整批帶入好幾列，不必一列一列挑。 -->
        <div style="display:flex;align-items:center;gap:10px;margin-top:16px;">
            <h4 style="font-size:15px;color:#8A5A2B;margin:0;"><i class="fa fa-object-group"></i> 範本組合</h4>
<?php if ($perms['canAdmin']): ?>
            <button id="btnTplSetNew" style="margin-left:auto;"><i class="fa fa-plus"></i> 新增組合</button>
<?php endif; ?>
        </div>
        <div class="ia-hint">把<b>常一起稽核的那幾個範本存成一組</b>，填稽核通知單時按「<b>帶入範本組合</b>」選一次，
        那幾列受稽單位就整批長出來（起始主過程、受稽單位、稽核員／陪檢員候選都照各自的範本帶）。<br>
        組合只記「有哪些範本」，<b>範本本身改了組合帶出來的內容就跟著改</b>；
        組合裡的範本被停用或刪除時<b>那一列自動不帶</b>，其餘照常。</div>
        <div class="ia-table-wrap"><table class="ia-table"><thead><tr>
            <th style="width:180px;">組合名稱</th><th>包含的範本</th>
            <th style="width:70px;">啟用</th><th style="width:110px;">操作</th>
        </tr></thead><tbody id="tplSetBody"></tbody></table></div>
    </div>
    <div class="ia-mfoot"><button data-close>關閉</button></div>
</div></div>

<!-- ============================ 範本組合編輯 ============================ -->
<div class="ia-mask" id="tplSetEditMask"><div class="ia-modal">
    <div class="ia-mhead"><h4 id="tplSetEditTitle">稽核範本組合</h4><span class="x" data-close>&times;</span></div>
    <div class="ia-mbody">
        <div class="ia-form" style="grid-template-columns:100px 1fr;">
            <label>組合名稱<span style="color:#DD5138;">*</span></label>
            <div><input type="text" id="tsName" placeholder="例：上半年度全廠稽核">
                 <div class="err-msg" id="errTsName"></div></div>
            <label>備註</label><div><input type="text" id="tsNote" placeholder="選填"></div>
            <label>啟用</label><div><label style="font-weight:normal;"><input type="checkbox" id="tsActive" checked> 出現在填表時的組合清單</label></div>
        </div>
        <div style="margin-top:10px;">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                <b style="color:#8A5A2B;font-size:14px;">包含哪些範本<span style="color:#DD5138;">*</span></b>
                <input type="text" id="tsFilter" placeholder="輸入主過程或單位篩選…" style="border:1px solid #D8BE93;border-radius:4px;padding:3px 8px;font-size:13px;width:220px;">
                <span id="tsCount" style="font-size:12px;color:#8a6d45;"></span>
            </div>
            <div class="pick-wrap" id="tsPick" style="max-height:300px;"></div>
            <div class="err-msg" id="errTsPick"></div>
            <div style="font-size:12px;color:#8a6d45;margin-top:4px;">帶入通知單時的列順序＝這份清單由上到下的順序。</div>
        </div>
    </div>
    <div class="ia-mfoot"><button data-close>取消</button><button id="btnTplSetSave" class="btn-warm">儲存</button></div>
</div></div>

<!-- ============================ 通知單：挑範本組合帶入 ============================ -->
<div class="ia-mask" id="tplSetPickMask"><div class="ia-modal narrow">
    <div class="ia-mhead"><h4><i class="fa fa-object-group"></i> 帶入範本組合</h4><span class="x" data-close>&times;</span></div>
    <div class="ia-mbody">
        <div class="ia-hint">選一個組合，底下那幾個範本會<b>一次全部加成受稽單位的列</b>。
        已經在表格上的起始主過程<b>不會重複加</b>（同一次稽核裡不可以重複）。</div>
        <div id="tplSetPickBody"></div>
    </div>
    <div class="ia-mfoot"><button data-close>取消</button></div>
</div></div>

<div class="ia-mask" id="tplEditMask"><div class="ia-modal">
    <div class="ia-mhead"><h4 id="tplEditTitle">稽核範本</h4><span class="x" data-close>&times;</span></div>
    <div class="ia-mbody">
        <div class="ia-form" style="grid-template-columns:120px 1fr;">
            <label>稽核起始主過程<span style="color:#DD5138;">*</span></label>
            <div><input type="text" id="teName" list="teProcList" placeholder="例：教育訓練資料">
                 <datalist id="teProcList"></datalist>
                 <div style="font-size:12px;color:#8a6d45;">可直接打字，或從下拉挑常用的（主過程／管理過程／支援過程）。</div>
                 <div class="err-msg" id="errTeName"></div></div>
            <label>受稽單位<span style="color:#DD5138;">*</span></label>
            <div><select id="teUnit" data-eg-filter="輸入單位名稱篩選…"></select>
                 <div class="err-msg" id="errTeUnit"></div></div>
            <label>備註</label><div><input type="text" id="teNote" placeholder="選填"></div>
            <label>啟用</label><div><label style="font-weight:normal;"><input type="checkbox" id="teActive" checked> 出現在填表時的範本清單</label></div>
        </div>
        <div style="display:flex;gap:12px;margin-top:10px;flex-wrap:wrap;">
            <div style="flex:1 1 300px;min-width:280px;">
                <b style="color:#8A5A2B;font-size:14px;">稽核員候選部門<span style="color:#DD5138;">*</span></b>
                <span style="font-size:12px;color:#8a6d45;">（多選，含子部門）</span>
                <div class="pick-wrap" id="teAuditorPick" style="max-height:260px;margin-top:5px;"></div>
                <div id="teAuditorInfo" style="font-size:12px;color:#8a6d45;margin-top:4px;"></div>
                <div class="err-msg" id="errTeAuditor"></div>
            </div>
            <div style="flex:1 1 300px;min-width:280px;">
                <b style="color:#8A5A2B;font-size:14px;">陪檢員候選部門</b>
                <span style="font-size:12px;color:#8a6d45;">（多選，可不選＝不指定陪檢員）</span>
                <div class="pick-wrap" id="teEscortPick" style="max-height:260px;margin-top:5px;"></div>
                <div id="teEscortInfo" style="font-size:12px;color:#8a6d45;margin-top:4px;"></div>
            </div>
        </div>
    </div>
    <div class="ia-mfoot"><button data-close>取消</button><button id="btnTplSave" class="btn-warm">儲存</button></div>
</div></div>

<!-- ============================ 通用：輸入業務日期後確認 ============================ -->
<div class="ia-mask" id="dateMask"><div class="ia-modal narrow">
    <div class="ia-mhead"><h4 id="dateTitle">確認</h4><span class="x" data-close>&times;</span></div>
    <div class="ia-mbody">
        <div class="ia-hint" id="dateHint"></div>
        <div class="ia-form">
            <label>業務日期</label><div><input type="date" id="dateVal"></div>
            <label id="dateNoteLab" style="display:none;">意見</label>
            <div class="full" id="dateNoteWrap" style="display:none;"><textarea id="dateNote"></textarea></div>
        </div>
    </div>
    <div class="ia-mfoot"><button data-close>取消</button><button id="btnDateOk" class="btn-warm">確定</button></div>
</div></div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/fastclick.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script src="../../resource/js/eg_stamp.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_stamp.js') ?>"></script>
<script src="../../resource/js/eg_stamp_tpl.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_stamp_tpl.js') ?>"></script>
<script src="../../resource/js/eg_date_fmt.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_date_fmt.js') ?>"></script>
<script src="../../resource/js/eg_asdoc_picker.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_asdoc_picker.js') ?>"></script>
<script src="../../resource/js/eg_print_log.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_print_log.js') ?>"></script>
<script src="../../resource/js/eg_input_rules.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_input_rules.js') ?>"></script>
<script>
$(document).ready(function(){ $('#sidebar-menu').css('visibility','visible'); });

var API  = '../../src/store/InternalAudit_API.php';
var META = null, YEAR = 0, PLAN = null, CASES = [];
/* 內稽管理員＝各分頁的刪除鈕都看得到（2026-09-14 使用者要求：稽核內建立完的單據都要能刪） */
var IS_ADMIN = <?= $perms['canAdmin'] ? 'true' : 'false' ?>;
var CAN_AUDIT = <?= $perms['canAudit'] ? 'true' : 'false' ?>;   // 能不能開不符合通知單／矯正單
var PAGE = {case:1, check:1, nc:1}, PER = 20;
var LIST = {case:[], check:[], nc:[]};

function esc(s){ return $('<div>').text(s==null?'':s).html(); }
/* PHP 的空關聯陣列 json_encode 出來是 []（陣列）不是 {}，直接拿來當 map 用會出事——一律先正規化 */
function toPlainMap(v){
    var out = {};
    if (!v) return out;
    Object.keys(v).forEach(function(k){ out[k] = v[k]; });
    return out;
}
/* 顯示用日期一律 YYYY.MM.DD（ai-rules/20，唯一實作 egFmtDate，不自寫） */
function dispDate(d){ return (window.egFmtDate ? egFmtDate(d) : (d||'')) || ''; }
/* <input type=date> 要的是 Y-m-d，不能吃 YYYY.MM.DD */
function inputDate(d){ if(!d) return ''; return String(d).substr(0,10); }
/* 跳窗可以疊跳窗（例：條文題庫 →「＋選文件」）。所有 .ia-mask 的 z-index 都是 9000，
   同層時**誰在 HTML 裡寫得晚誰就蓋在上面**——「選文件」寫在「條文題庫」前面，
   於是後開的選文件反而被壓在後面：看得到一點點、卻完全點不到（2026-09-11 使用者回報）。
   修法：開啟時一律算出目前最上層的跳窗再往上疊，關掉就還原，不必逐個跳窗去記 z-index。 */
var IA_MASK_Z = 9000;
function openMask(id){
    var $m = $('#'+id), top = IA_MASK_Z;
    $('.ia-mask:visible').each(function(){
        if (this === $m[0]) return;
        var z = parseInt($(this).css('z-index'), 10);
        if (z && z > top) top = z;
    });
    $m.css('z-index', top + 10).show();
}
function closeMask(id){ $('#'+id).hide().css('z-index',''); }
function hideMask($m){ $m.hide().css('z-index',''); }
$(document).on('click','[data-close]', function(){ hideMask($(this).closest('.ia-mask')); });
$(document).on('click','.ia-mask', function(e){ if(e.target===this) hideMask($(this)); });
/* API 用 HTTP 狀態碼回錯，jQuery 非 2xx 不會進 success，錯誤只會掉進 console —— 統一顯示出來 */
$(document).ajaxError(function(_e, xhr){
    if (xhr && xhr.status && xhr.status !== 200) {
        var m = '';
        try { m = (JSON.parse(xhr.responseText)||{}).error || ''; } catch(err) {}
        if (m) alert(m);
    }
});

function fieldErr($el, id, msg){
    if (msg) { $el.addClass('err'); $('#'+id).addClass('on').text(msg); }
    else { $el.removeClass('err'); $('#'+id).removeClass('on').text(''); }
    return !msg;
}
function clearErrs($scope){ $scope.find('.err').removeClass('err'); $scope.find('.err-msg').removeClass('on').text(''); }

/* ---------- 人員／部門下拉（一律用 meta 帶回來的清單，不各頁自己查） ---------- */
/* 人員下拉。list 可傳 META.auditors／META.escorts（只列有資格的人），
   不傳就是全體在職員工。欄位順序固定「部門/職稱/姓名」（ai-rules/08 第五節）。 */
function peopleOptions(list, cur, blank){
    var h = '<option value="">'+(blank||'（未指定）')+'</option>';
    (list && list.length ? list : (META.people||[])).forEach(function(p){
        var label = (p.dept_name?p.dept_name+'　':'') + (p.position_name?p.position_name+'　':'') + p.user_cname
                  + (p.leave_note ? '（'+p.leave_note+'）' : '');
        h += '<option value="'+p.id+'"'+(String(cur)===String(p.id)?' selected':'')+'>'+esc(label)+'</option>';
    });
    return h;
}
/* 製表人下拉（2026-09-14 使用者交辦：年度計畫／稽核通知單／稽核報告表都要能事後改製表人）。
   補歷史表單時原本的製表人可能已經離職，eg_people_list 不會列離職者，
   不特別處理的話「打開來存個檔」就會把製表人洗掉，所以查不到就把原本那位補進選項。

   2026-09-15 使用者交辦兩件：
   ①**只列有稽核員或陪檢員資格的人**——內稽的表單本來就是稽核小組在製作，全公司 40 幾個人
     攤在下拉裡根本找不到人。資格由後端 META.auditors／META.escorts 帶回（已依業務日期回推）。
   ②**同部門的人要排在一起**——eg_people_list() 的排序是「職稱 → 部門」，所以同一個部門的人
     會被不同職稱拆散在清單各處；這裡改成**部門優先**（部門 sort_order → 職稱 sort_order → 姓名），
     部門取「顯示標籤用的那個職務」＝主職，跟眼睛看到的字才對得起來。 */
function makerQualifiedPeople(){
    var ok = {};
    (META.auditors||[]).concat(META.escorts||[]).forEach(function(p){ ok[String(p.id)] = 1; });
    var list = (META.people||[]);
    // 資格名單留空＝不限制，這時後端回的就是全體，ok 自然涵蓋所有人
    var rows = list.filter(function(p){ return ok[String(p.id)]; });
    if (!rows.length) rows = list.slice();          // 一個都對不上時寧可全列，也不要讓下拉變空的
    return rows.slice().sort(function(a, b){
        var ha = personHeadPost(a), hb = personHeadPost(b);
        return (ha.dept_sort - hb.dept_sort) || ((ha.dept_id||0) - (hb.dept_id||0))
            || (ha.position_sort - hb.position_sort)
            || String(a.user_cname||'').localeCompare(String(b.user_cname||''), 'zh-Hant');
    });
}
/** 新建單據時的製表人預設值：本人有資格才預選本人，否則留「（不指定）」。
    不這樣做的話，像超級管理員這種不在人員清單裡的帳號一開單就會被加成
    「超級管理員（已離職／無稽核資格）」這種看起來像壞掉的選項（2026-09-16 實測抓到）。 */
function makerDefaultSelf(){
    var rows = makerQualifiedPeople();
    var hit = rows.some(function(p){ return String(p.id) === String(META.me.id); });
    return hit ? META.me.id : '';
}
function makerOptions(curId, curName){
    var h = '<option value="">（不指定）</option>', found = false, rows = makerQualifiedPeople();
    rows.forEach(function(p){ if (String(p.id)===String(curId||'')) found = true; });
    if (curId && !found) h += '<option value="'+esc(curId)+'" selected>'+esc((curName||'#'+curId)+'（已離職／無稽核資格）')+'</option>';
    var lastDept = null;
    rows.forEach(function(p){
        var d = personHeadPost(p).dept_name || '（未設部門）';
        if (d !== lastDept) { lastDept = d; }        // 同部門相鄰＝排序已達成，不另加分隔以免下拉打字篩選被干擾
        h += '<option value="'+p.id+'"'+(String(curId||'')===String(p.id)?' selected':'')+'>'+esc(personPostLabel(p))+'</option>';
    });
    return h;
}
/** 這個人「標籤上顯示的那個職務」＝主職，沒有主職就用後端挑好的那一筆 */
function personHeadPost(p){
    var main = null;
    (p.posts||[]).forEach(function(x){ if (!main && +x.is_main === 1) main = x; });
    if (main) return {dept_id:main.dept_id, dept_name:main.dept_name, dept_sort:+main.dept_sort||999,
                      position_sort:+main.position_sort||999};
    return {dept_id:p.dept_id, dept_name:p.dept_name, dept_sort:+p.dept_sort||999,
            position_sort:+p.position_sort||999};
}
/* 「部門　職稱　姓名」；多職務者以**主職**為準，兼任接在括號裡（2026-09-14 使用者回報：
   製表人下拉只看得到兼任職位——eg_people_list() 一人只回一列、而且挑的是「職級最高」那一筆，
   所以主職會被兼任蓋掉。posts＝這個人所有職務，由後端 ia_annotate_posts() 帶回來。） */
function personPostLabel(p){
    var posts = p.posts || [], main = null, others = [];
    posts.forEach(function(x){ if (!main && +x.is_main === 1) main = x; else others.push(x); });
    var head = main || {dept_name:p.dept_name, position_name:p.position_name};
    var label = ((head.dept_name||'') ? head.dept_name+'\u3000' : '')
              + ((head.position_name||'') ? head.position_name+'\u3000' : '') + p.user_cname;
    if (main && others.length) {
        label += '\uff08\u517c ' + others.map(function(x){
            return ((x.dept_name||'')+' '+(x.position_name||'')).trim();
        }).join('\u3001') + '\uff09';
    }
    return label;
}
/**
 * 稽核員／陪檢員／稽核組長／稽核人的下拉：選的是「職務」不是「人」。
 * 資格認到 人員＋部門＋職稱，兼任的人主職與兼任職可能一個有資格一個沒有，
 * 所以同一個人會出現多個選項，值是 'uid:deptId:posId'。
 * curKey 對得上就選它；對不上（舊資料只存了 user_id）就退而選同一個人的第一個職務。
 */
function postOptions(list, curKey, curUid, blank){
    var h = '<option value="">'+(blank||'（未指定）')+'</option>';
    var rows = list || [];
    var exact = false;
    rows.forEach(function(p){ if (p.post_key3 && String(p.post_key3)===String(curKey||'')) exact = true; });
    var usedFallback = false;
    rows.forEach(function(p){
        var key = p.post_key3 || (p.id+':'+(p.dept_id||0)+':'+(p.position_id||0));
        var sel = false;
        if (exact) sel = (String(key)===String(curKey||''));
        else if (curUid && String(p.id)===String(curUid) && !usedFallback) { sel = true; usedFallback = true; }
        var label = (p.dept_name?p.dept_name+'　':'') + (p.position_name?p.position_name+'　':'') + p.user_cname
                  + (+p.is_main === 0 ? '（兼任）' : '')
                  + (p.leave_note ? '（'+p.leave_note+'）' : '');
        h += '<option value="'+esc(key)+'"'+(sel?' selected':'')+'>'+esc(label)+'</option>';
    });
    return h;
}
/** 由單據上存的三個欄位組回職務鍵 */
function postKeyOf(uid, deptId, posId){ return (uid||0)+':'+(deptId||0)+':'+(posId||0); }
/* 受稽單位下拉：列「受稽單位」不是「部門」——已設群組的（如 生產部＋生產1/2/3廠）合併成一列，
   值一律是代表部門 id。群組會在名稱後面標出涵蓋哪些部門，避免看不出來合併了什麼。 */
function deptOptions(cur, blank){
    var h = '<option value="">'+(blank||'（未指定）')+'</option>';
    (META.units||[]).forEach(function(u){
        var label = u.name + (u.is_group ? ('（' + (u.members||[]).join('、') + '）') : '');
        h += '<option value="'+u.key+'"'+(String(cur)===String(u.key)?' selected':'')+'>'+esc(label)+'</option>';
    });
    return h;
}
function caseOptions(cur){
    var h = '<option value="">（不綁定）</option>';
    CASES.forEach(function(c){
        h += '<option value="'+c.case_id+'"'+(String(cur)===String(c.case_id)?' selected':'')+'>'
           + esc((c.case_no||('#'+c.case_id)) + '　第'+c.seq_no+'次　' + dispDate(c.notify_date)) + '</option>';
    });
    return h;
}

/* ---------- 分頁（>10 筆分頁、分頁鈕在右上、可選每頁筆數） ---------- */
function renderPager(key, total){
    var pages = Math.max(1, Math.ceil(total/PER));
    if (PAGE[key] > pages) PAGE[key] = pages;
    var h = '<span>共 '+total+' 筆</span>'
          + '<select class="pgPer" data-key="'+key+'" style="height:26px;border:1px solid #D8BE93;border-radius:4px;">';
    [5,10,20,50].forEach(function(n){ h += '<option value="'+n+'"'+(PER===n?' selected':'')+'>'+n+' 筆/頁</option>'; });
    h += '</select>';
    if (pages > 1) {
        h += '<button class="pgBtn" data-key="'+key+'" data-p="'+Math.max(1,PAGE[key]-1)+'">‹</button>';
        for (var i=1;i<=pages;i++){
            if (pages>9 && Math.abs(i-PAGE[key])>2 && i!==1 && i!==pages) { if(Math.abs(i-PAGE[key])===3) h+='<span>…</span>'; continue; }
            h += '<button class="pgBtn'+(i===PAGE[key]?' on':'')+'" data-key="'+key+'" data-p="'+i+'">'+i+'</button>';
        }
        h += '<button class="pgBtn" data-key="'+key+'" data-p="'+Math.min(pages,PAGE[key]+1)+'">›</button>';
    }
    return h;
}
function pageSlice(key){ var s=(PAGE[key]-1)*PER; return LIST[key].slice(s, s+PER); }
$(document).on('change','.pgPer', function(){ PER = +$(this).val(); var k=$(this).data('key'); PAGE[k]=1; renderTab(k); });
$(document).on('click','.pgBtn', function(){ var k=$(this).data('key'); PAGE[k]=+$(this).data('p'); renderTab(k); });
function renderTab(k){ if(k==='case') renderCases(); else if(k==='check') renderChecks(); else if(k==='nc') renderNcs(); }

/* ---------- 分頁切換：切過去一律重抓該分頁資料（點開即刷新鐵則） ---------- */
/* 只處理主頁六個分頁。跳窗裡的分頁（例：資格名單的稽核員／陪檢員）沿用同一套外觀所以也有 .ia-tab，
   沒有 data-pane 就直接跳過——否則按跳窗裡的分頁會把主頁六個分頁全部取消選取、
   六個 .ia-pane 也全被藏起來（關掉跳窗後畫面一片空白）。 */
$('.ia-tab[data-pane]').on('click', function(){
    var p = $(this).data('pane');
    if (!p) return;
    $('.ia-tab[data-pane]').removeClass('on'); $(this).addClass('on');
    $('.ia-pane').removeClass('on'); $('#pane-'+p).addClass('on');
    loadPane(p);
});
function loadPane(p){
    if (p==='dash')   loadDash();
    if (p==='plan')   loadPlan();
    if (p==='case')   loadCases();
    if (p==='check')  loadChecks();
    if (p==='nc')     loadNcs();
    if (p==='report') loadReport();
}
function currentPane(){ return $('.ia-tab.on').data('pane') || 'dash'; }
/** 切到某個分頁（走分頁鈕自己的 click，不另寫一套切換邏輯） */
function switchPane(p){ $('.ia-tab[data-pane="'+p+'"]').trigger('click'); }

/* ---------- 年度完成狀態（2026-09-17 使用者要求） ----------
   ✔＝這一年有年度計畫表**而且報告完整產出**（每一張稽核報告表都已送出，且沒有未結案的 IA 單）；
   沙漏＝**有年度計畫表但還沒有（或還沒送出）稽核報告表**；
   **沒有年度計畫表的年度一律不顯示圖示**（2026-09-18 使用者定調：一年的內稽是從年度計畫表開始的）。
   判定規則寫在後端 ia_year_status() 一處，這裡只負責顯示（畫面不另算一份＝鐵律4）。 */
function yearStatOf(y){ return ((META && META.year_status) || {})[String(y)] || null; }
/* 分頁依進度逐步出現（2026-09-18 使用者要求）：內稽的順序是
   年度計畫 → 稽核通知單 → 查檢表／不符合通知單／稽核報告表，
   還沒走到那一步的分頁先不要出現，免得使用者在空分頁上找不到東西。
   **總覽與年度計畫永遠看得到**（否則新的一年什麼都點不了）。 */
function applyPaneGate(){
    var s = yearStatOf(YEAR) || {};
    var hasPlan = (+s.plan || 0) > 0, hasCase = (+s.cases || 0) > 0;
    var vis = {dash:true, plan:true, case:hasPlan, check:hasCase, nc:hasCase, report:hasCase};
    $('.ia-tab[data-pane]').each(function(){
        var k = String($(this).data('pane'));
        $(this).toggle(vis[k] !== false);
    });
    // 目前停在被隱藏的分頁時（例如切到還沒開始的年度）自動退回看得到的第一個
    var cur = currentPane();
    if (vis[cur] === false) switchPane(hasPlan ? 'plan' : 'dash');
    var $hint = $('#paneGateHint');
    if (!$hint.length) $hint = $('<div id="paneGateHint" class="ia-hint" style="margin:6px 0;"></div>').insertAfter($('.ia-tabs'));
    if (!hasPlan) {
        $hint.show().html('這個年度還沒有<b>年度稽核計劃表</b>。先在「年度計畫」分頁建立，稽核通知單等分頁才會出現。');
    } else if (!hasCase) {
        $hint.show().html('這個年度還沒有<b>稽核通知單</b>。先在「稽核通知單」分頁建立，查檢表／不符合通知單／稽核報告表才會出現。');
    } else { $hint.hide().empty(); }
}
function yearMark(y){
    var st = (yearStatOf(y)||{}).state || 'none';
    return st === 'done' ? '　✔' : (st === 'doing' ? '　⏳' : '');
}
/* 年度狀態（2026-09-21 使用者回報：原本把「還差什麼」整串印在工具列上，
   字一長就把「AS條文題庫」那幾顆按鈕擠到下一列）。
   改成兩段：工具列上只留**短標**（進行中／已完成＋還差幾項），
   細節放到工具列下方自己的一列，點「看明細」才展開——按鈕永遠不會被推下去。
   滑鼠移到短標上仍然看得到完整內容（title），不必非得點開。 */
function renderYearStat(){
    var s = yearStatOf(YEAR);
    var $more = $('#yearStatMore');
    if (!s || s.state === 'none') {
        // 沒有年度計畫表時也是「不顯示圖示」，但要把原因講出來（可能已經有零星資料）
        var w0 = (s && s.why && s.why.length) ? s.why : ['尚未建立任何內稽資料'];
        yearStatSet('<span style="color:#a08356;">尚未開始</span>', w0, w0.join('；'));
        return;
    }
    if (s.state === 'done') {
        var doneTip = '報告已完整產出：稽核報告表 '+s.reports_approved+'／'+s.reports
                    + ' 張已送出，沒有未結案的不符合通知單';
        yearStatSet('<span style="color:#7a5217;"><i class="fa fa-check-circle"></i> 已完成</span>',
                    [doneTip], doneTip);
        return;
    }
    // 進行中：短標只寫「還差幾項」，實際內容進明細列
    var why = (s.why || []);
    var tip = '進行中' + (why.length ? '：' + why.join('；') : '')
            + '（年度計畫 '+s.plan+'　通知單 '+s.cases_done+'／'+s.cases+' 已執行　查檢表 '+s.checks+'）';
    yearStatSet('<span style="color:#d98a33;"><i class="fa fa-hourglass-half"></i> 進行中</span>'
              + (why.length ? '<span style="color:#a08356;">（還差 '+why.length+' 項）</span>' : ''),
                why, tip);
}
/* 短標＋明細列一起設定（唯一實作，三種狀態共用）。
   明細列的展開／收合狀態記在 localStorage，換年度、重新整理之後維持使用者的選擇。 */
function yearStatSet(shortHtml, lines, tip){
    var open = false;
    try { open = localStorage.getItem('ia_year_stat_open') === '1'; } catch (e) {}
    $('#yearStat').attr('title', tip || '').html(shortHtml
        + (lines && lines.length
            ? '<span class="ys-more" id="btnYearStatMore">' + (open ? '收合' : '看明細') + '</span>' : ''));
    var h = (lines || []).map(function(t, i){
        return '<div>' + ((lines.length > 1) ? ('<b>' + (i+1) + '.</b> ') : '') + esc(t) + '</div>';
    }).join('');
    $('#yearStatMore').html(h).toggle(!!(open && h));
}
$(document).on('click', '#btnYearStatMore', function(){
    var open = !$('#yearStatMore').is(':visible');
    try { localStorage.setItem('ia_year_stat_open', open ? '1' : '0'); } catch (e) {}
    $('#yearStatMore').toggle(open);
    $(this).text(open ? '收合' : '看明細');
});

/* ============================ meta ============================ */
function loadMeta(cb){
    $.getJSON(API, {action:'meta'}, function(res){
        if (!res.ok) { alert(res.error||'載入失敗'); return; }
        META = res;
        var ysel = $('#yearSel').empty();
        (res.years||[]).forEach(function(y){
            ysel.append('<option value="'+y+'">'+y+' 年'+yearMark(y)+'</option>');
        });
        /* 預設年度（2026-09-18 使用者要求：自動停在**進行中**的年度）。
           優先序：①有「進行中」的年度就挑它（最新的那一個）②沒有就今年
           ③今年不在選單裡才退回選單第一個。
           為什麼不直接用今年：內稽常常是跨年度在補，今年可能根本還沒開始排，
           一開頁面停在空的今年，使用者每次都要自己切一次。 */
        var cy = +String(res.today).substr(0,4);
        var ys = res.years || [], stMap = res.year_status || {};
        var doing = ys.filter(function(y){ return (stMap[y] || {}).state === 'doing'; });
        YEAR = doing.length ? +doing[0]
             : ((ys.indexOf(cy) >= 0) ? cy : +(ys.length ? ys[0] : cy));
        ysel.val(YEAR);
        renderYearStat();
        applyPaneGate();
        // 種類／階段／類型下拉一律由後端常數帶出來，畫面不另寫一份對照（鐵律4）
        var kh = '<option value="">全部</option>';
        $.each(res.check_kinds||{}, function(k,v){ kh += '<option value="'+k+'">'+esc(v.label)+'</option>'; });
        $('#checkKind').html(kh);
        var nh = '<option value="">全部</option>';
        $.each(res.nc_stages||{}, function(k,v){ nh += '<option value="'+k+'">'+esc(v)+'</option>'; });
        $('#ncStage').html(nh);
        if (cb) cb();
    });
}
$('#yearSel').on('change', function(){
    YEAR = +$(this).val(); renderYearStat(); applyPaneGate();
    PAGE={case:1,check:1,nc:1}; loadPane(currentPane());
});
$('#btnReload').on('click', function(){ ASOF_CACHE = {}; loadMeta(function(){ loadPane(currentPane()); }); });

/* ---------- 依業務日期回推的人員清單（ai-rules/22，2026-09-16 使用者交辦） ----------
   單據上的人一律要以**該單據的業務日期**為準：
     ①當時在職、現在已離職的人要挑得到（例：文管中心負責人葉卿雅 2025-11 還在職）
     ②部門與職稱要印當時的，不是現在的
   meta 帶回來的是「今天」的版本，所以開單／改日期時要把 META.people／auditors／escorts／templates
   換成該日期的版本。同一個日期只跟後端要一次（ASOF_CACHE）。 */
var ASOF_CACHE = {}, ASOF_NOW = '';
function asofDate(d){ return (d && /^\d{4}-\d{2}-\d{2}$/.test(d)) ? d : (META.today||''); }
function applyAsof(r){
    META.people    = r.people    || META.people;
    META.auditors  = r.auditors  || META.auditors;
    META.escorts   = r.escorts   || META.escorts;
    META.templates = r.templates || META.templates;
    ASOF_NOW = r.date || '';
}
function peopleAsof(date, cb){
    var d = asofDate(date);
    if (!d) { if (cb) cb(); return; }
    if (ASOF_NOW === d) { if (cb) cb(); return; }
    if (ASOF_CACHE[d]) { applyAsof(ASOF_CACHE[d]); if (cb) cb(); return; }
    $.getJSON(API, {action:'people_asof', date:d}, function(res){
        // 拿不到就沿用現有清單，不要讓整張表單開不起來
        if (res && res.ok) { ASOF_CACHE[d] = res; applyAsof(res); }
        if (cb) cb();
    }).fail(function(){ if (cb) cb(); });
}

/* ============================ 總覽 ============================ */
function loadDash(){
    $.getJSON(API, {action:'dashboard', year:YEAR}, function(res){
        if (!res.ok) return;
        var st = res.nc_by_stage||{}, ty = res.nc_by_type||{};
        var open = (st.issued||0)+(st.replied||0)+(st.verified||0);
        var h = '';
        h += card('年度稽核場次', res.case_cnt||0, '已執行 '+(res.case_done||0)+' 場');
        h += card('查檢表', res.check_cnt||0, '本年度建立份數');
        h += card('不符合通知單', (open+(st.closed||0)), '未結案 '+open+'　已結案 '+(st.closed||0));
        h += card('缺失類型', (ty.major||0)+'／'+(ty.minor||0)+'／'+(ty.observe||0), '主要／次要／觀察');
        h += card('逾期未結案', res.nc_overdue||0, res.nc_overdue ? '請盡快追蹤' : '目前沒有逾期', res.nc_overdue>0);
        if (res.has_plan) {
            h += card('計畫達成', (res.plan_actual||0)+'／'+(res.plan_planned||0),
                      '排定格數對實際執行' + ((res.plan_extra||0) ? '（另有 '+res.plan_extra+' 次計畫外）' : ''));
        } else {
            h += card('年度計畫', '未建立', '請先到「年度計畫」分頁建立');
        }
        $('#dashCards').html(h);

        var rows = res.nc_soon||[];
        if (!rows.length) { $('#dashNcBody').html('<tr><td colspan="5" class="ia-empty">沒有待追蹤的缺失</td></tr>'); return; }
        var b = '';
        rows.forEach(function(r){
            var over = r.due_date && r.due_date < META.today;
            b += '<tr><td>'+esc(r.nc_no||'')+'</td><td>'+esc(r.dept_name||'')+'</td>'
               + '<td>'+dispDate(r.due_date)+(over?' <span class="st st-overdue">逾期</span>':'')+'</td>'
               + '<td><span class="st st-'+esc(r.stage)+'">'+esc((META.nc_stages||{})[r.stage]||r.stage)+'</span></td>'
               + '<td><span class="ia-op" onclick="openNc('+r.nc_id+')"><i class="fa fa-edit"></i> 開啟</span></td></tr>';
        });
        $('#dashNcBody').html(b);
    });
}
function card(t, v, s, warn){
    return '<div class="ia-card'+(warn?' warn':'')+'"><div class="t">'+esc(t)+'</div>'
         + '<div class="v">'+esc(v)+'</div><div class="s">'+esc(s||'')+'</div></div>';
}

/* ============================ 年度計畫 2-GM-06-01 ============================ */
function loadPlan(){
    $.getJSON(API, {action:'plan_get', year:YEAR}, function(res){
        if (!res.ok) return;
        PLAN = res.plan;
        // 後端已經改成回物件，這裡再保一層：cells／actual 一律正規化成純物件。
        // 若是陣列（PHP 空關聯陣列會變成 []），在上面加字串鍵之後走訪不到，存檔就會送出空清單。
        if (PLAN) { PLAN.cells = toPlainMap(PLAN.cells); PLAN.actual = toPlainMap(PLAN.actual); }
        if (!PLAN) {
            $('#planGrid').html('<div class="ia-empty">'+YEAR+' 年度還沒有稽核計劃表'
                + '<?= $perms['canAdmin'] ? "，請按上方「建立本年度計畫表」" : "，請洽內稽管理員建立" ?></div>');
            $('#planStatusBox').text(''); $('#planRemark').val('');
            $('#btnPlanCreate').show(); $('#btnPlanDepts,#btnPlanSave,#btnPlanSubmit,#btnPlanApprove').hide();
            $('#planMakerBox').hide();
            return;
        }
        $('#btnPlanCreate').hide(); $('#btnPlanDepts,#btnPlanSave,#btnPlanSubmit,#btnPlanApprove').show();
        $('#planRemark').val(PLAN.remark||'');
        // 製表人（可改）；已核准的計劃表比照其他欄位只有系統管理員動得了
        $('#planMakerBox').toggle(<?= $perms['canAdmin'] ? 'true' : 'false' ?>);
        // 製表人清單以**製表日期**當時的在職狀態與職稱為準（ai-rules/22）
        peopleAsof(PLAN.maker_date, function(){
            $('#planMaker').html(makerOptions(PLAN.maker_id, PLAN.maker_name)).prop('disabled', planReadonly());
        });
        $('#planMakerDate').val(inputDate(PLAN.maker_date)).prop('readonly', planReadonly());
        var stLabel = {draft:'草稿', submitted:'已送審', approved:'已核准'}[PLAN.status] || PLAN.status;
        var box = '狀態：<span class="st st-'+(PLAN.status==='approved'?'done':PLAN.status)+'">'+esc(stLabel)+'</span>';
        if (PLAN.maker_name)    box += '　製表：'+esc(PLAN.maker_name)+' '+dispDate(PLAN.maker_date);
        if (PLAN.reviewer_name) box += '　審查：'+esc(PLAN.reviewer_name)+' '+dispDate(PLAN.reviewer_date);
        if (PLAN.approver_name) box += '　核准：'+esc(PLAN.approver_name)+' '+dispDate(PLAN.approver_date);
        $('#planStatusBox').html(box);
        renderPlanGrid();
    });
}
/* 改製表日期＝當時在職的人可能不同，下拉要跟著換（ai-rules/22） */
$(document).on('change', '#planMakerDate', function(){
    var cur = $('#planMaker').val();
    peopleAsof($(this).val(), function(){
        $('#planMaker').html(makerOptions(cur, (PLAN && PLAN.maker_name) || ''));
    });
});

function planReadonly(){
    return !(<?= $perms['canAdmin'] ? 'true' : 'false' ?>) || (PLAN && PLAN.status==='approved' && !<?= $perms['isAdmin'] ? 'true' : 'false' ?>);
}
function renderPlanGrid(){
    if (!PLAN) return;
    var ro = planReadonly();
    var h = '<table class="plan-grid"><thead><tr><th class="mon">月份</th>';
    PLAN.depts.forEach(function(d){
        // 部門名稱直排（比照紙本），用逐字換行
        h += '<th>'+esc(d.dept_name||d.cur_name||'').split('').join('<br>')+'</th>';
    });
    h += '</tr></thead><tbody>';
    for (var m=1;m<=12;m++){
        h += '<tr><th class="mon">'+m+'月</th>';
        PLAN.depts.forEach(function(d){
            var k = d.dept_id+'-'+m;
            var planned = !!PLAN.cells[k], actual = !!PLAN.actual[k];
            var mark = (actual ? '◎' : '') + (planned ? '○' : '');
            h += '<td class="cell'+(ro?' ro':'')+'" data-d="'+d.dept_id+'" data-m="'+m+'"'
               + ' title="'+(planned?'已排定計畫':'未排定')+(actual?'；該月實際已執行稽核':'')+'">'+mark+'</td>';
        });
        h += '</tr>';
    }
    h += '</tbody></table>';
    $('#planGrid').html(h);
}
$(document).on('click','.plan-grid td.cell:not(.ro)', function(){
    var d = $(this).data('d'), m = $(this).data('m'), k = d+'-'+m;
    if (PLAN.cells[k]) delete PLAN.cells[k]; else PLAN.cells[k] = '1';
    renderPlanGrid();
});
$('#btnPlanCreate').on('click', function(){ openPlanDeptPick(true); });
$('#btnPlanDepts').on('click', function(){ openPlanDeptPick(false); });
/* 列「受稽單位」不是「部門」：已設群組的合併成一列，值＝代表部門 id。
   版面用固定欄寬（勾選框／名稱／涵蓋部門）讓每一列對齊，不再用全形空白做階層縮排。 */
function openPlanDeptPick(isCreate){
    var cur = {};
    if (!isCreate && PLAN) PLAN.depts.forEach(function(d){ cur[d.dept_id]=1; });
    var h = '';
    (META.units||[]).forEach(function(u){
        h += '<label class="pick-row"><input type="checkbox" class="pdChk" value="'+u.key+'"'
           + (cur[u.key]?' checked':'')+'>'
           + '<span class="pk-name">'+esc(u.name)+'</span>'
           + '<span class="pk-sub">'+(u.is_group ? esc((u.members||[]).join('、')) : '')+'</span>'
           + '</label>';
    });
    $('#planDeptPick').html(h || '<div class="ia-empty">沒有可選的受稽單位</div>');
    $('#btnPlanDeptSave').data('create', isCreate?1:0);
    openMask('planDeptMask');
}
$('#btnPlanDeptSave').on('click', function(){
    var ids = $('.pdChk:checked').map(function(){ return +$(this).val(); }).get();
    if (!ids.length) { alert('請至少選一個受稽單位'); return; }
    var isCreate = +$(this).data('create')===1;
    if (isCreate) {
        $.post(API, {action:'plan_create', year:YEAR, dept_ids:JSON.stringify(ids)}, function(res){
            if (!res.ok) { alert(res.error||'建立失敗'); return; }
            closeMask('planDeptMask'); loadPlan();
        }, 'json');
    } else {
        $.post(API, {action:'plan_set_depts', plan_id:PLAN.plan_id, dept_ids:JSON.stringify(ids)}, function(res){
            if (!res.ok) { alert(res.error||'儲存失敗'); return; }
            closeMask('planDeptMask'); loadPlan();
        }, 'json');
    }
});
$('#btnPlanSave').on('click', function(){
    if (!PLAN) return;
    var cells = [];
    // 用 Object.keys 不用 $.each：$.each 遇到「像陣列」的東西只會跑數字索引，字串鍵會被整批跳過
    Object.keys(PLAN.cells).forEach(function(k){ var p=k.split('-'); cells.push({dept_id:+p[0], month:+p[1]}); });
    $.post(API, {action:'plan_save_cells', plan_id:PLAN.plan_id, cells:JSON.stringify(cells),
                 remark:$('#planRemark').val(),
                 maker_id:($('#planMaker').val()||''), maker_date:($('#planMakerDate').val()||'')}, function(res){
        if (!res.ok) { alert(res.error||'儲存失敗'); return; }
        alert('已儲存'); loadPlan();
    }, 'json');
});
$('#btnPlanSubmit').on('click', function(){
    if (!PLAN) return;
    var auto = String((META.settings||{}).ia_auto_sign||'') === '1';
    askDate('送審年度稽核計劃表',
        '審查日期會印在表格下方「審查」欄。審查人＝設定裡「審查格」指定的那一位。'
        + (auto ? '\n目前已開啟「自動簽核」：按下去會一併完成核准（核准人＝設定裡「核准格」指定的那一位）。' : ''),
        function(d){
        $.post(API, {action:'plan_decide', plan_id:PLAN.plan_id, status:'submitted', biz_date:d}, function(res){
            if (!res.ok) { alert(res.error||'失敗'); return; }
            if (res.auto_signed) alert('已自動完成簽核：審查 ' + (res.reviewer||'（留白）')
                + '／核准 ' + (res.approver||'（留白）'));
            loadPlan();
        }, 'json');
    });
});
$('#btnPlanApprove').on('click', function(){
    if (!PLAN) return;
    askDate('核准年度稽核計劃表', '核准日期會印在表格下方「核准」欄。', function(d, note){
        $.post(API, {action:'plan_decide', plan_id:PLAN.plan_id, status:'approved', biz_date:d, note:note}, function(res){
            if (!res.ok) { alert(res.error||'失敗'); return; }
            loadPlan();
        }, 'json');
    }, true);
});
/* 刪除年度計畫表（管理員限定；API 早就有，只是畫面上一直沒有按鈕＝使用者 2026-09-14 回報） */
$('#btnPlanDelete').on('click', function(){
    if (!PLAN) { alert('本年度還沒有計畫表'); return; }
    if (!confirm('確定刪除 '+YEAR+' 年度稽核計劃表？\n排定的○格子會一起刪除；已建立的稽核通知單與查檢表不受影響。')) return;
    $.post(API, {action:'plan_delete', plan_id:PLAN.plan_id}, function(res){
        if (!res.ok) { alert(res.error||'刪除失敗'); return; }
        loadPlan();
    }, 'json');
});

/* 通用「輸入業務日期後確認」跳窗 */
var DATE_CB = null;
/* opt（2026-09-18 新增）：{def:預設日期, max:不可晚於這一天, maxMsg:超過時要講的話}
   ——稽核通知單的簽章日期不可晚於通知日期，這個限制要在**挑日期的當下**就擋，
   不要等按下去才被後端退回。 */
var DATE_OPT = {};
function askDate(title, hint, cb, withNote, opt){
    DATE_OPT = opt || {};
    $('#dateTitle').text(title); $('#dateHint').text(hint||'');
    $('#dateVal').val(DATE_OPT.def || META.today).attr('max', DATE_OPT.max || null);
    $('#dateNote').val('');
    $('#dateNoteLab,#dateNoteWrap').toggle(!!withNote);
    DATE_CB = cb; openMask('dateMask');
}
$('#btnDateOk').on('click', function(){
    var d = $('#dateVal').val();
    if (!d) { alert('請填業務日期'); return; }
    if (DATE_OPT.max && d > DATE_OPT.max) {
        alert(DATE_OPT.maxMsg || ('日期不可晚於 ' + dispDate(DATE_OPT.max)));
        return;
    }
    closeMask('dateMask');
    if (DATE_CB) DATE_CB(d, $('#dateNote').val());
});
</script>
<script>
/* ============================ 稽核通知單 2-GM-06-02 ============================ */
function loadCases(cb){
    $.getJSON(API, {action:'case_list', year:YEAR, status:$('#caseStatus').val(), kw:$('#caseKw').val()}, function(res){
        if (!res.ok) return;
        LIST.case = res.rows||[]; CASES = LIST.case;
        renderCases();
        if (cb) cb();
    });
}
$('#btnCaseSearch').on('click', function(){ PAGE.case=1; loadCases(); });
$('#caseStatus').on('change', function(){ PAGE.case=1; loadCases(); });
$('#caseKw').on('keydown', function(e){ if(e.which===13){ PAGE.case=1; loadCases(); } });

/* 狀態一律顯示中文（2026-08-27 使用者回報畫面出現英文 approved）。
   approved／submitted 不是稽核通知單的正式狀態，但舊資料可能存到，一併給中文避免又露出英文。 */
var CASE_ST = {draft:'草稿', issued:'已發出', executing:'執行中', closed:'已結案',
               approved:'已核准', submitted:'已送審', rejected:'已退回'};
function stLabel(map, v){ return map[v] || (v ? String(v) : '') || '—'; }
function renderCases(){
    $('#casePager').html(renderPager('case', LIST.case.length));
    var rows = pageSlice('case');
    if (!rows.length) { $('#caseBody').html('<tr><td colspan="11" class="ia-empty">沒有符合條件的稽核通知單</td></tr>'); return; }
    var h = '';
    rows.forEach(function(r){
        var meet = '';
        if (+r.pre_meeting_id) meet += '<span class="ia-op" onclick="openMeeting('+r.pre_meeting_id+')">事前</span>';
        if (+r.end_meeting_id) meet += '<span class="ia-op" onclick="openMeeting('+r.end_meeting_id+')">結束</span>';
        if (!meet) meet = '<span style="color:#a08356;">—</span>';
        h += '<tr>'
          + '<td>'+esc(r.case_no||'—')+'</td>'
          + '<td>第'+esc(r.seq_no)+'次</td>'
          + '<td>'+dispDate(r.notify_date)+'</td>'
          + '<td>'+(r.audit_from ? dispDate(r.audit_from)+'～'+dispDate(r.audit_to||r.audit_from) : '—')+'</td>'
          + '<td>'+esc(r.leader_name||'—')+'</td>'
          + '<td class="l">'+esc(r.dept_list||'—')+'</td>'
          + '<td>'+esc(r.check_cnt||0)+'</td>'
          + '<td>'+(+r.nc_cnt ? '<b style="color:#C4442D;">'+esc(r.nc_cnt)+'</b>' : '0')+'</td>'
          + '<td>'+meet+'</td>'
          + '<td><span class="st st-'+(r.status==='closed'?'closed':(r.status==='draft'?'draft':'issued'))+'">'
          + esc(CASE_ST[r.status]||r.status)+'</span></td>'
          + '<td><span class="ia-op" onclick="openCase('+r.case_id+')"><i class="fa fa-edit"></i> 開啟</span>'
          + '<span class="ia-op" onclick="printCase('+r.case_id+')"><i class="fa fa-print"></i></span>'
          + (CASE_CAN_DEL && r.status!=='closed'
              ? '<span class="ia-op danger" onclick="delCase('+r.case_id+')" title="刪除這張稽核通知單"><i class="fa fa-trash"></i></span>'
              : '')
          + '</td></tr>';
    });
    $('#caseBody').html(h);
}

/* 刪除稽核通知單（管理員限定，且只能刪「尚未結案」的；2026-09-11 使用者交辦）。
   依 ai-rules/08 第六節「點開即刷新」：按下當下先向後端拿這一筆的最新狀態再判斷，
   不用清單上的快取——別人剛結案或剛開了 IA 單時要當場擋下並重新整理清單。
   後端 case_delete 會用同一組規則再擋一次（鐵律8）。 */
var CASE_CAN_DEL = <?= $perms['canAdmin'] ? 'true' : 'false' ?>;
function delCase(id){
    if (!CASE_CAN_DEL) return;
    $.getJSON(API, {action:'case_get', case_id:id}, function(res){
        if (!res.ok) { alert(res.error||'載入失敗，請重新整理清單'); loadCases(); return; }
        var c = res.row;
        if (c.status === 'closed') {
            alert('這張通知單已結案，不可刪除。\n要刪除請先把狀態改回「執行中」。');
            loadCases(); return;
        }
        var ncCnt = (c.ncs||[]).length, ckCnt = (c.checks||[]).length;
        if (ncCnt > 0) {
            alert('這張通知單底下還有 '+ncCnt+' 張不符合通知單，請先到「不符合通知單」分頁處理或刪除。');
            loadCases(); return;
        }
        var msg = '確定刪除稽核通知單「'+(c.case_no || ('第'+c.seq_no+'次'))+'」？\n\n';
        if (ckCnt) msg += '・底下 '+ckCnt+' 份查檢表會一起刪除（含已填好的結果）\n';
        msg += '・年度計畫表上這一次稽核的「◎ 實際實施」會一併消失\n';
        if (+c.pre_meeting_id || +c.end_meeting_id) msg += '・已建立的會議紀錄不會被刪除，請自行到會議紀錄模組處理\n';
        if (!confirm(msg)) return;
        $.post(API, {action:'case_delete', case_id:id}, function(r){
            if (!r.ok) { alert(r.error||'刪除失敗'); loadCases(); return; }
            closeMask('caseMask');
            PAGE.case = 1;
            loadCases(); loadChecks();
            if (currentPane()==='dash')  loadDash();
            if (currentPane()==='plan')  loadPlan();
        }, 'json');
    });
}

var CASE_ID = 0, CASE_ROWS = [];
$('#btnCaseNew').on('click', function(){ openCase(0); });
$('#btnCaseDelete').on('click', function(){ if (CASE_ID) delCase(CASE_ID); });

/* ---------- 稽核通知單「完成」（2026-09-18 使用者要求） ----------
   使用者回報「狀態一直是草稿」：原因是推進狀態的 API 從來沒有任何按鈕呼叫它。
   現在流程是 草稿 →（完成）→ 已發出；**完成之後整張單鎖起來不可修改**，
   要改只有內稽管理員輸入操作確認密碼取消完成，而且**完成之後才會送審核／自動簽核**。 */
var CASE_DONE = false;
function caseIsDone(c){ return !!c && ['issued','executing','closed'].indexOf(String(c.status||'')) >= 0; }
function applyCaseLock(c){
    CASE_DONE = caseIsDone(c);
    var $m = $('#caseMask');
    // 鎖定：所有輸入欄位與表格列都設唯讀（列印、關閉仍可用）
    /* data-ro-always＝本來就唯讀的欄位（件號、次別、算出來的結束時間），
       解鎖時**不可以把它們變成可編輯**——2026-09-18 實測踩到：
       結束時間欄的 readonly 被這裡一律設成 false，變成使用者可以亂改計算結果。 */
    $m.find('input,select,textarea').not('[data-eg-filter-box]').not('[data-ro-always]').each(function(){
        var t = (this.type||'').toLowerCase();
        if (t === 'checkbox' || t === 'radio' || this.tagName === 'SELECT') $(this).prop('disabled', CASE_DONE);
        else $(this).prop('readonly', CASE_DONE);
    });
    $m.find('[data-ro-always]').prop('readonly', true);
    $m.find('.ia-op, .nk-chip, button[id^=btnCaseTpl], #btnAllAudited, #btnAllDue, #btnAllTime')
      .css({'pointer-events': CASE_DONE ? 'none' : '', 'opacity': CASE_DONE ? .5 : ''});
    $('#btnCaseSave').toggle(!CASE_DONE);
    $('#btnCaseComplete').toggle(!!CASE_ID && !CASE_DONE);
    /* 取消完成要輸入操作確認密碼——**沒有那個權限的人就不要讓他按**（2026-09-18 使用者回報）：
       按了也只會白試密碼，而且連錯三次會把這個功能鎖七天。改成顯示一段說明，講清楚要找誰。 */
    var canReopen = !!CASE_ID && CASE_DONE && String((c||{}).status) !== 'closed';
    var hasPw = +((META||{}).can_confirm_pw) === 1;
    $('#btnCaseReopen').toggle(canReopen && hasPw);
    var $noPw = $('#caseReopenNoPw');
    if (!$noPw.length) $noPw = $('<span id="caseReopenNoPw" style="font-size:12px;color:#a08356;margin-left:8px;"></span>')
                                 .insertBefore($('#btnCaseReopen'));
    $noPw.toggle(canReopen && !hasPw).text(canReopen && !hasPw
        ? '（取消完成需要「操作確認密碼」權限，請洽超級管理員在「修改個人資料 → 操作確認密碼授權管理」授權）' : '');
    var $box = $('#caseLockBox');
    if (!$box.length) { $box = $('<div id="caseLockBox" class="ia-hint" style="margin-bottom:8px;"></div>').prependTo($('#caseMask .ia-mbody')); }
    if (CASE_DONE) {
        $box.show().html('<b style="color:#7a5217;"><i class="fa fa-lock"></i> 這張通知單已完成，內容已鎖定不可修改。</b>'
            + ((c && c.completed_by_name) ? '　完成：'+esc(c.completed_by_name)+(c.completed_at ? (' '+String(c.completed_at).substr(0,16)) : '') : '')
            + ((c && c.approver_name) ? '　核准：'+esc(c.approver_name) : '')
            + ((c && c.reviewer_name) ? '　審查：'+esc(c.reviewer_name) : '')
            + '<br>要修改請按下方「取消完成」（限內稽管理員，需輸入操作確認密碼）。');
    } else { $box.hide().empty(); }
}
$('#btnCaseComplete').on('click', function(){
    if (!CASE_ID) { alert('請先儲存這張通知單'); return; }
    if (!validateCase()) { alert('有欄位需要修正，請看紅字說明（完成前會先自動存檔）'); return; }
    // 必填欄位要填齊才可以送出（草稿存檔不檢查）
    if (!caseRequiredCheck()) {
        alert('受稽單位還有必填欄位沒填完，不能送出。\n\n請看表格上方的紅字說明（缺的格子已標紅）。');
        $('#errCRequired')[0].scrollIntoView({block:'center'});
        return;
    }
    if (!confirm('確定把這張稽核通知單標記為「完成」嗎？\n\n'
               + '・完成之後內容就鎖定不可修改（要改得由內稽管理員輸入操作確認密碼取消完成）\n'
               + (String((META.settings||{}).ia_auto_sign_case||'') === '1'
                    ? '・目前已開啟稽核通知單的自動簽核：按下去會一併完成審查與核准'
                    : '・目前沒有開啟稽核通知單的自動簽核，核准／審查兩格會留白（可在「設定」裡開啟）'))) return;
    /* 簽章日期（2026-09-18 使用者要求）：**一律是通知日期或更早**。
       通知單是先簽好才發出去的，章蓋在通知日期之後紙本上不成立；
       補歷史紙本時可以往前挑，但不可以往後。這個日期同時套到自動簽章與製表日期。 */
    var nd = $('#cNotify').val() || '';
    askDate('完成稽核通知單', '這個日期會印在核准／審查的章上，也會成為製表日期。'
          + (nd ? ('預設＝通知日期 ' + dispDate(nd) + '；可以往前挑，但不可以晚於通知日期。') : ''),
        function(d){
            /* **先把畫面上的修改存起來再完成**（2026-09-18 使用者回報）：
               完成本身只送 case_id，所以剛改好還沒按儲存的內容（例如稽核日期）不會進資料庫，
               完成後重新載入就跳回舊值，看起來像「改了又自己變回去」。 */
            $.post(API, caseSavePayload(), function(sv){
                if (!sv.ok) { alert('完成前自動存檔失敗：' + (sv.error||'') + '\n\n請先修正後再按完成。'); return; }
                CASE_ID = sv.case_id || CASE_ID;
                if (sv.no_changed) alert('稽核日期改變，稽核件號已由 ' + sv.no_old + ' 重編為 ' + sv.case_no);
                caseDoComplete(d);
            }, 'json');
        }, false, {def: nd, max: nd, maxMsg: '簽章／製表日期不可晚於通知日期（' + (nd ? dispDate(nd) : '') + '）'});
    return;
});
/** 真正送出「完成」（存檔成功之後才會走到這裡） */
function caseDoComplete(d){
            $.post(API, {action:'case_complete', case_id:CASE_ID, sign_date:d}, function(res){
                if (!res.ok) { alert(res.error||'完成失敗'); return; }
                alert('已完成（簽章／製表日期 '+dispDate(d)+'）' + (+res.auto_signed
                    ? ('，並已自動簽核：\n核准 '+(res.approver||'（未設定）')+'　審查 '+(res.reviewer||'（未設定）'))
                    : '。\n（目前沒有開啟自動簽核，核准／審查兩格留白，請依紙本流程簽核）'));
                loadCases(function(){ openCase(CASE_ID); });
            }, 'json');
}
$('#btnCaseReopen').on('click', function(){
    $('#caseReopenPw').val(''); clearErrs($('#caseReopenMask')); openMask('caseReopenMask');
    // 跳窗一開就把游標放進密碼欄（使用者回報過「無法輸入密碼」，先排除焦點沒進到欄位的可能）
    setTimeout(function(){ $('#caseReopenPw').trigger('focus'); }, 60);
});
$('#btnCaseReopenGo').on('click', function(){
    var pw = $('#caseReopenPw').val();
    if (!pw) { $('#errCaseReopen').addClass('on').text('請輸入操作確認密碼'); return; }
    $.post(API, {action:'case_reopen', case_id:CASE_ID, password:pw}, function(res){
        if (!res.ok) { $('#errCaseReopen').addClass('on').text(res.error||'取消失敗'); return; }
        closeMask('caseReopenMask');
        alert('已取消完成，這張通知單可以再修改了（自動簽核的章已一併清除）');
        loadCases(function(){ openCase(CASE_ID); });
    }, 'json');
});
/** 這張通知單的「業務日期」＝稽核起日，沒填就退回通知日期，再沒有就今天。
    人員資格、在職狀態、部門職稱一律以它為準（ai-rules/22）。 */
function caseBizDate(c){
    if (c) return asofDate(c.audit_from || c.notify_date || META.today);
    return asofDate($('#cFrom').val() || $('#cNotify').val() || META.today);
}
function openCase(id){
    CASE_ID = id;
    if (!id) {
        peopleAsof(META.today, function(){
        $('#caseTitle').text('新增稽核通知單');
        $('#cNo,#cSeq').val(''); $('#cNotify').val(META.today);
        $('#cFrom,#cTo,#cMeetDate,#cMeetStart,#cMeetEnd,#cMeetPlace,#cAllAudited,#cAllDue').val('');
        $('#cRemark').val(defaultCaseRemark());
        $('#cTimeStep').val(60); $('#cTimeFrom,#cTimeTo').val('');
        $('#cLeader').html(postOptions(META.auditors, '', '', '（未指定）'));
        $('#cMaker').html(makerOptions(makerDefaultSelf(), META.me.name));
        $('#cMakerDate').val(META.today);
        CASE_ROWS = [newCaseRow(),newCaseRow(),newCaseRow()];
        renderCaseRows(); $('#cMeetingSec').hide();
        $('#btnCaseDelete').hide();
        applyCaseLock(null);
        teamHintForCase(META.today);
        clearErrs($('#caseMask')); openMask('caseMask');
        });
        return;
    }
    // 點開即刷新：直接向後端拿這一筆的最新狀態，不用清單上的快取
    $.getJSON(API, {action:'case_get', case_id:id}, function(res){
        if (!res.ok) { alert(res.error||'載入失敗'); return; }
        var c = res.row;
        // 先把人員清單換成「這張單的業務日期當時」的版本，再畫下拉（否則當時在職、現已離職的人挑不到）
        peopleAsof(caseBizDate(c), function(){ openCaseRender(c); });
    });
}
function openCaseRender(c){
    {
        $('#caseTitle').text('稽核通知單　'+(c.case_no||'')+'（'+(CASE_ST[c.status]||c.status)+'）');
        $('#cNo').val(c.case_no||''); $('#cSeq').val('第'+c.seq_no+'次');
        $('#cNotify').val(inputDate(c.notify_date)); $('#cFrom').val(inputDate(c.audit_from));
        $('#cTo').val(inputDate(c.audit_to)); $('#cMeetDate').val(inputDate(c.end_meet_date));
        $('#cMeetStart').val(c.end_meet_start||''); $('#cMeetEnd').val(c.end_meet_end||'');
        $('#cMeetPlace').val(c.end_meet_place||''); $('#cRemark').val(c.remark||'');
        $('#cTimeStep').val(c.unit_minutes || 60);   // 每單位分鐘存在單據上，重開不會變回預設值
        $('#cAllAudited,#cAllDue').val('');
        $('#cMaker').html(makerOptions(c.maker_id, c.maker_name));
        $('#cMakerDate').val(inputDate(c.maker_date));
        $('#cLeader').html(postOptions(META.auditors, postKeyOf(c.leader_id, c.leader_dept_id, c.leader_position_id), c.leader_id, '（未指定）'));
        CASE_ROWS = (c.depts||[]).map(function(d){ return {
            start_process:d.start_process||'', dept_id:d.dept_id||'',
            tpl_id:'',
            // 稽核員／陪檢員都可多位，帶的是「職務」清單（uid:deptId:posId）
            auditor_keys: peopleToKeys(d.auditors), auditor_people: d.auditors||[],
            escort_keys:  peopleToKeys(d.escorts),  escort_people:  d.escorts||[],
            audited_date:inputDate(d.audited_date), audited_time:d.audited_time||'',
            improve_due:inputDate(d.improve_due)
        }; });
        if (!CASE_ROWS.length) CASE_ROWS = [{}];
        renderCaseRows();
        renderCaseMeeting(c);
        $('#cMeetingSec').show();
        // 已結案的不給刪（後端同規則再擋一次）
        $('#btnCaseDelete').toggle(CASE_CAN_DEL && c.status !== 'closed');
        applyCaseLock(c);
        teamHintForCase(caseBizDate(c));
        clearErrs($('#caseMask')); openMask('caseMask');
    }
}
/* 受稽日期／預定完成改善「全部同一日期」（2026-09-16 使用者要求）。
   一天之內跑完所有受稽單位是常態，一列一列點日曆很花時間。
   表頭沒填日期時，自動沿用**第一列已經填好的那個值**——多數情況使用者就是填完第一列才想到要全部一樣。 */
function caseFillAllDate(field, headSel){
    var v = $(headSel).val();
    if (!v) {
        for (var i = 0; i < CASE_ROWS.length; i++) { if (CASE_ROWS[i][field]) { v = CASE_ROWS[i][field]; break; } }
    }
    if (!v) { alert('請先在上方的日期欄選一個日期，或先填好其中一列'); $(headSel).focus(); return; }
    CASE_ROWS.forEach(function(r){ r[field] = v; });
    $(headSel).val(v);
    renderCaseRows();
}
/* ---------- 受稽時間自動排（2026-09-17 使用者要求） ----------
   ①填開始時間 → 依間隔（預設 30 分）往下排每一列
   ②**跳過午休 12:00~13:00**：算出來的時間落在午休內就直接改成 13:00 再往後排
     （寧可中間空一段，也不要把受稽時間排在午休）
   ③手動改了中間某一列，後面的自動順延（可用「改一列就自動順延後面」關掉）
   ④有填結束時間而排不完時只提示、不擅自壓縮——要壓縮請自己把間隔改小。 */
var IA_LUNCH_FROM = 12 * 60, IA_LUNCH_TO = 13 * 60;
var IA_MEET_GAP = 30;        // 最後一個單位稽核完，要在結束會議開始前留這麼多分鐘
function iaT2M(s){ var n = normTime(s); if (!n) return null; var p = n.split(':'); return (+p[0]) * 60 + (+p[1]); }
function iaM2T(m){ m = Math.max(0, Math.min(24 * 60 - 1, Math.round(m)));
                   return ('0' + Math.floor(m / 60)).slice(-2) + ':' + ('0' + (m % 60)).slice(-2); }
/** 某一列從 t 開始做 dur 分鐘的「做完時間」（做到 12:00 是合法的，跨進午休才推到 13:00） */
function iaEndOf(t, dur){
    var raw = t + dur;
    return (raw <= IA_LUNCH_FROM) ? raw : iaNextSlot(t, dur);
}
/** 從 min 往後推 step 分鐘；落在午休內一律推到午休結束 */
function iaNextSlot(min, step){
    var t = min + step;
    if (t >= IA_LUNCH_FROM && t < IA_LUNCH_TO) t = IA_LUNCH_TO;
    return t;
}
/** 「每個部門需要幾分鐘」（2026-09-18 使用者定調：這個數字是**時長**不是空檔） */
function iaTimeStep(){
    var v = parseInt($('#cTimeStep').val(), 10);
    return (v > 0 && v <= 600) ? v : 30;
}
/**
 * 從 start 開始、每個單位 dur 分鐘、跳過午休，排 n 個單位。
 * 回傳 {starts:[每個單位的開始時間], end:最後一個單位的結束時間}
 * ——**end 是「最後一個單位做完」的時間**，不是最後一個開始時間（要拿它跟結束會議比）。
 */
function iaPlanTimes(start, dur, n){
    var cur = start, starts = [];
    if (cur >= IA_LUNCH_FROM && cur < IA_LUNCH_TO) cur = IA_LUNCH_TO;   // 起點落在午休就從 13:00 起
    for (var i = 0; i < n; i++) {
        if (i > 0) cur = iaNextSlot(cur, dur);
        starts.push(cur);
    }
    var end = starts.length ? iaNextSlot(starts[starts.length - 1], dur) : cur;
    // iaNextSlot 會把落在午休的結束時間推到 13:00，但「做到 12:00」本身是合法的結束
    if (starts.length) {
        var raw = starts[starts.length - 1] + dur;
        if (raw <= IA_LUNCH_FROM) end = raw;
    }
    return {starts: starts, end: end};
}
/**
 * 這一天最晚必須做完的時間（2026-09-18 使用者要求）：
 * 結束會議**當天**時，最後一個單位要在結束會議開始前 IA_MEET_GAP 分鐘做完；
 * 另外使用者若自己填了「結束時間」，兩者取較早的那一個。
 * 結束會議排在別天（例：隔天開會）就不受這一條限制。
 * @return {limit:分鐘或 null, why:文字說明}
 */
function iaTimeLimit(auditDate){
    // 結束時間欄現在是「算出來的結果」，不再是使用者給的上限，所以上限只看結束會議
    var lim = null, why = '';
    var md = $('#cMeetDate').val(), ms = normTime($('#cMeetStart').val());
    if (md && ms && (!auditDate || md === auditDate)) {
        var m = iaT2M(ms) - IA_MEET_GAP;
        if (lim === null || m < lim) { lim = m; why = '結束會議 ' + ms + ' 前 ' + IA_MEET_GAP + ' 分鐘（' + iaM2T(m) + '）'; }
    }
    return {limit: lim, why: why};
}
/** 排不下時給建議：①保持間隔往前挪開始時間 ②保持開始時間縮短間隔（取 5 分鐘倍數） */
function iaTimeSuggest(n, dur, start, limit){
    var out = [];
    // ①往前挪：用二分逼近找「最晚可以幾點開始」
    var lo = 6 * 60, hi = start, best = null;
    while (lo <= hi) {
        var mid = Math.floor((lo + hi) / 2 / 5) * 5;
        if (iaPlanTimes(mid, dur, n).end <= limit) { best = mid; lo = mid + 5; } else { hi = mid - 5; }
        if (hi < lo) break;
    }
    if (best !== null && best < start) {
        out.push('・保持每個單位 ' + dur + ' 分鐘，改成 ' + iaM2T(best) + ' 開始（做到 '
               + iaM2T(iaPlanTimes(best, dur, n).end) + '）');
    }
    // ②縮短每個單位的時間（5 分鐘為單位）
    for (var d = dur - 5; d >= 15; d -= 5) {
        if (iaPlanTimes(start, d, n).end <= limit) {
            out.push('・保持 ' + iaM2T(start) + ' 開始，每個單位改成 ' + d + ' 分鐘（做到 '
                   + iaM2T(iaPlanTimes(start, d, n).end) + '）');
            break;
        }
    }
    return out;
}
/** 從第 idx 列的時間往後重排（idx 那一列不動） */
function iaCascadeTimes(idx){
    var step = iaTimeStep();
    var cur = iaT2M(CASE_ROWS[idx] && CASE_ROWS[idx].audited_time);
    if (cur === null) return;
    for (var i = idx + 1; i < CASE_ROWS.length; i++) {
        if (!caseRowHasContent(CASE_ROWS[i])) continue;   // 還沒填東西的空列不排
        cur = iaNextSlot(cur, step);
        CASE_ROWS[i].audited_time = iaM2T(cur);
    }
}
/** 這一列有沒有實際內容（空的末列不必排時間） */
function caseRowHasContent(r){
    if (!r) return false;
    return !!(r.start_process || r.dept_id || (r.auditor_keys||[]).length || (r.escort_keys||[]).length
              || r.audited_date || r.improve_due);
}
/* 填了開始時間就**即時算出結束時間**並顯示行不行得通（2026-09-18 使用者要求）：
   以前要按了自動排才知道排不下，改一次試一次很煩。這裡在開始時間／每單位分鐘／
   受稽單位列數／結束會議時間任何一項變動時就重算，結束時間欄是唯讀的計算結果。 */
function iaTimeRecalc(){
    if (!$('#caseMask').is(':visible')) return;
    var $calc = $('#cTimeCalc');
    var n = CASE_ROWS.filter(caseRowHasContent).length;
    var from = normTime($('#cTimeFrom').val()), start = from ? iaT2M(from) : null;
    /* **表頭的開始時間只是自動排用的**——每一列都自己填好時間時不需要它
       （使用者多半是直接在表格裡打時間，沒碰過表頭那一格；原本沒填就整個不算，
        於是手動排的時間永遠不會被檢查）。 */
    var allTimed = n > 0 && CASE_ROWS.filter(caseRowHasContent)
                                     .every(function(r){ return iaT2M(r.audited_time) !== null; });
    if (!n || (start === null && !allTimed)) {
        $('#cTimeTo').val('');
        $calc.css('color', '#a08356').text(n ? '填開始時間就會自動算出結束時間（或直接在表格裡逐列填）'
                                            : '先填受稽單位，再填開始時間');
        return;
    }
    var dur = iaTimeStep();
    /* **以「表格裡實際填的時間」為準**（2026-09-18 使用者回報）：
       原本一律拿表頭的開始時間 ×（列數 × 每單位分鐘）試算，所以手動把某一列改晚
       （例：最後一列改成 15:30、每單位 60 分＝做到 16:30）畫面還是顯示「做到 15:00 ✔」。
       每一列都填了時間就看最晚的那一列做完是幾點；還有列沒填時間才退回自動排的試算。 */
    var rows = CASE_ROWS.filter(caseRowHasContent), timed = [], lastT = null, lastIdx = 0;
    rows.forEach(function(r, k){
        var t = iaT2M(r.audited_time);
        if (t === null) return;
        timed.push(t);
        if (lastT === null || t > lastT) { lastT = t; lastIdx = k + 1; }
    });
    var end, base;
    if (timed.length === n) {
        end  = iaEndOf(lastT, dur);
        base = '目前填的時間：最後一個單位第 ' + lastIdx + ' 列 ' + iaM2T(lastT) + ' 起、'
             + dur + ' 分 → 做到 ' + iaM2T(end);
        start = Math.min.apply(null, timed);
    } else {
        var plan = iaPlanTimes(start, dur, n);
        end  = plan.end;
        base = n + ' 單位 × ' + dur + ' 分 → 做到 ' + iaM2T(end)
             + (timed.length ? ('（還有 ' + (n - timed.length) + ' 列沒填時間，以自動排試算）') : '');
    }
    $('#cTimeTo').val(iaM2T(end));

    var auditDate = '';
    for (var i = 0; i < CASE_ROWS.length && !auditDate; i++) {
        if (caseRowHasContent(CASE_ROWS[i]) && CASE_ROWS[i].audited_date) auditDate = CASE_ROWS[i].audited_date;
    }
    /* 逐列自己填時間時，兩列之間的間隔可能比「每單位分鐘」還短＝時間會重疊，
       這不擋送出（現場有可能兩組人同時進行），但一定要講出來，否則畫面看起來完全正常。 */
    var lap = '';
    if (timed.length === n && n > 1) {
        var srt = timed.slice().sort(function(a, b){ return a - b; });
        for (var q = 1; q < srt.length; q++) {
            if (srt[q] - srt[q - 1] < dur) {
                lap = '　⚠ 有兩個單位只間隔 ' + (srt[q] - srt[q - 1]) + ' 分，比每單位 ' + dur + ' 分短（時間重疊）';
                break;
            }
        }
    }
    var lim = iaTimeLimit(auditDate);
    if (lim.limit === null) { $calc.css('color', '#a08356').text(base + lap + '（沒有同一天的結束會議，不另設上限）'); return; }
    if (end <= lim.limit) {
        $calc.css('color', lap ? '#B07A2B' : '#5b8a3a')
             .text(base + '　✔ 在 ' + iaM2T(lim.limit) + ' 之前（' + lim.why + '）' + lap);
    } else {
        /* 建議分兩種講法：時間是自己逐列填的，就直接講「最後那一列要幾點以前開始」——
           拿自動排的試算去建議（做到 13:55 之類）跟他手上排的表對不起來，看了只會更困惑。 */
        var sug;
        if (timed.length === n) {
            sug = ['・把最後一個單位（第 ' + lastIdx + ' 列）改到 ' + iaM2T(lim.limit - dur) + ' 以前開始'];
            if (lim.limit - lastT >= 15) sug.push('・或把每個單位改成 ' + (lim.limit - lastT) + ' 分鐘以內');
            sug.push('・或把結束會議往後移');
        } else {
            sug = iaTimeSuggest(n, dur, start, lim.limit);
            if (!sug.length) sug = ['・即使每單位縮到 15 分也排不完，請分兩天或把結束會議往後移'];
        }
        $calc.css('color', '#C4442D').html(base + '　✘ 超過 ' + iaM2T(lim.limit) + '（' + lim.why + '）'
            + '<br>' + sug.join('<br>'));
    }
}
/** 送出前用：最後一個單位會不會做到結束會議前 30 分鐘之後（回傳說明文字，沒問題回空字串） */
function caseTimeOverrun(){
    var rows = CASE_ROWS.filter(caseRowHasContent), dur = iaTimeStep();
    var md = $('#cMeetDate').val();
    if (!md) return '';
    var lastT = null, lastIdx = 0;
    rows.forEach(function(r, k){
        if (String(r.audited_date || '') !== md) return;       // 只管與結束會議同一天的那幾列
        var t = iaT2M(r.audited_time);
        if (t === null) return;
        if (lastT === null || t > lastT) { lastT = t; lastIdx = k + 1; }
    });
    if (lastT === null) return '';
    var lim = iaTimeLimit(md);
    if (lim.limit === null || iaEndOf(lastT, dur) <= lim.limit) return '';
    return '最後一個受稽單位（第 ' + lastIdx + ' 列 ' + iaM2T(lastT) + ' 起、每單位 ' + dur + ' 分）會做到 '
         + iaM2T(iaEndOf(lastT, dur)) + '，超過 ' + iaM2T(lim.limit) + '（' + lim.why + '）。'
         + '請把受稽時間往前挪、縮短每單位分鐘，或把結束會議往後移。';
}
$(document).on('input change', '#cTimeFrom, #cTimeStep, #cMeetStart, #cMeetDate', iaTimeRecalc);

$(document).on('click', '#btnAllTime', function(){
    var from = normTime($('#cTimeFrom').val());
    if (!from) { alert('請先填開始時間（例 09:00）'); $('#cTimeFrom').focus(); return false; }
    $('#cTimeFrom').val(from);
    var to = normTime($('#cTimeTo').val());
    if (to) $('#cTimeTo').val(to);

    var rows = CASE_ROWS.filter(caseRowHasContent);
    if (!rows.length) { alert('目前沒有已填內容的受稽單位列，請先填好單位再按自動排'); return false; }
    var dur = iaTimeStep(), start = iaT2M(from);
    var plan = iaPlanTimes(start, dur, rows.length);

    /* 最後一個單位要在結束會議開始前 30 分鐘做完（2026-09-18 使用者要求）。
       結束會議日期與受稽日期不同天時不受這一條限制。 */
    var auditDate = '';
    for (var i = 0; i < CASE_ROWS.length && !auditDate; i++) {
        if (caseRowHasContent(CASE_ROWS[i]) && CASE_ROWS[i].audited_date) auditDate = CASE_ROWS[i].audited_date;
    }
    var lim = iaTimeLimit(auditDate);
    if (lim.limit !== null && plan.end > lim.limit) {
        var sug = iaTimeSuggest(rows.length, dur, start, lim.limit);
        alert('時間不夠：' + rows.length + ' 個受稽單位、每個 ' + dur + ' 分鐘，'
            + '從 ' + from + ' 開始會做到 ' + iaM2T(plan.end) + '（已扣掉午休 12:00~13:00），\n'
            + '但必須在 ' + iaM2T(lim.limit) + ' 之前做完——' + lim.why + '。\n\n'
            + (sug.length ? ('建議改成：\n' + sug.join('\n')) : '即使縮到每個單位 15 分鐘也排不完，請改成分兩天稽核，或把結束會議往後移。')
            + '\n\n（時間沒有被更動，請調整後再按一次自動排）');
        return false;
    }

    var k = 0;
    CASE_ROWS.forEach(function(r){
        if (!caseRowHasContent(r)) return;
        r.audited_time = iaM2T(plan.starts[k++]);
    });
    renderCaseRows();
    iaTimeRecalc();
    alert('已排好 ' + rows.length + ' 個單位：' + from + ' 起，每個單位 ' + dur + ' 分鐘，'
        + '做到 ' + iaM2T(plan.end) + '（跳過午休 12:00~13:00）'
        + (lim.limit !== null ? ('。\n最晚必須做完的時間是 ' + iaM2T(lim.limit) + '（' + lim.why + '），符合。') : '.'));
    return false;
});
$(document).on('click', '#btnAllAudited', function(){ caseFillAllDate('audited_date', '#cAllAudited'); return false; });
$(document).on('click', '#btnAllDue',     function(){ caseFillAllDate('improve_due',  '#cAllDue');     return false; });

/* 改了稽核起日／通知日期＝業務日期換了，人員清單（在職狀態、職稱、資格任期）要跟著換。
   不換的話畫面上還是舊日期那批人，挑完存檔後端會用新日期再驗一次＝存不進去又看不出原因。 */
/* 製表日期不可晚於通知日期：改任一邊都即時檢查並把欄位上限設好（前端擋一次、後端再擋一次） */
function caseSyncMakerDateMax(){
    var nd = $('#cNotify').val() || '';
    $('#cMakerDate').attr('max', nd || null);
    var md = $('#cMakerDate').val() || '';
    var bad = !!(nd && md && md > nd);
    fieldErr($('#cMakerDate'), 'errCMakerDate', bad ? ('不可晚於通知日期（' + dispDate(nd) + '）') : '');
    return !bad;
}
$(document).on('change', '#cNotify, #cMakerDate', caseSyncMakerDateMax);

$(document).on('change', '#cFrom, #cNotify', function(){
    if (!$('#caseMask').hasClass('on')) return;
    var d = caseBizDate(null);
    if (d === ASOF_NOW) return;
    peopleAsof(d, function(){
        $('#cLeader').html(postOptions(META.auditors, $('#cLeader').val(), '', '（未指定）'));
        renderCaseRows();
        teamHintForCase(d);
    });
});
/* 新增通知單時自動帶入的備註＝設定跳窗裡管理員設的那一段（只有一個版本，全站共用）。
   從來沒設定過時後端會回內建預設文字；管理員存成空白＝不自動帶入。 */
function defaultCaseRemark(){
    return String(((META.settings||{}).ia_case_remark_tpl) || '');
}
/* 稽核員／陪檢員可多位（2026-08-27）：CASE_ROWS 上存的是職務鍵陣列 auditor_keys／escort_keys */
function newCaseRow(){ return {auditor_keys:[], escort_keys:[]}; }
function peopleToKeys(list){
    return (list||[]).map(function(x){ return x.post_key3 || postKeyOf(x.user_id, x.dept_id, x.position_id); })
                     .filter(function(k){ return k && k.split(':')[0] !== '0'; });
}
function rowKeys(r, kind){ return (r && r[kind+'_keys']) || (r[kind+'_keys'] = []); }
/** 職務鍵 → 顯示用的人（先查候選清單，查不到再退回開檔時帶進來的名單，最後只剩姓名） */
function postByKey(key, cands, fallback){
    var hit = null;
    (cands||[]).forEach(function(p){ if (String(p.post_key3||postKeyOf(p.id,p.dept_id,p.position_id))===String(key)) hit = p; });
    if (hit) return {name:hit.user_cname, label:(hit.dept_name?hit.dept_name+' ':'')+(hit.position_name?hit.position_name+' ':'')+hit.user_cname
                     + (+hit.is_main===0?'（兼任）':'')};
    var uid = String(key).split(':')[0];
    var f = null;
    (fallback||[]).forEach(function(x){ if (String(x.user_id)===uid) f = x; });
    if (f) return {name:f.user_name, label:f.user_name};
    return {name:'#'+uid, label:'#'+uid};
}
function renderCaseRows(){
    setTimeout(function(){ iaTimeRecalc(); caseSyncDateRange(); }, 0);   // 列數一變，結束時間與日期範圍跟著重算
    var ro = !<?= $perms['canAdmin'] ? 'true' : 'false' ?>;
    var h = '';
    CASE_ROWS.forEach(function(r, i){
        // 有選範本就把候選縮到範本指定的部門範圍，沒選就是全部合格職務
        var aList = (r.auditor_cands && r.auditor_cands.length) ? r.auditor_cands : (META.auditors||[]);
        var eList = (r.escort_cands  && r.escort_cands.length)  ? r.escort_cands  : (META.escorts||[]);
        h += '<tr data-i="'+i+'">'
          + '<td><select class="cr" data-f="tpl_id" '+(ro?'disabled':'')+' data-eg-filter="輸入主過程或單位篩選…" style="width:100%;border:1px solid #D8BE93;border-radius:3px;font-size:12px;">'+tplOptions(r.tpl_id)+'</select></td>'
          + '<td><input type="text" class="cr" data-f="start_process" value="'+esc(r.start_process||'')+'" '+(ro?'readonly':'')+' style="width:100%;border:1px solid #D8BE93;border-radius:3px;padding:2px 5px;font-size:12px;"></td>'
          + '<td><select class="cr" data-f="dept_id" '+(ro?'disabled':'')+' style="width:100%;border:1px solid #D8BE93;border-radius:3px;font-size:12px;">'+deptOptions(r.dept_id,'（請選）')+'</select></td>'
          + '<td>'+peopleCell(i, r, 'auditor', aList, ro)+'</td>'
          + '<td>'+peopleCell(i, r, 'escort',  eList, ro)+'</td>'
          + '<td><input type="date" class="cr" data-f="audited_date" value="'+esc(r.audited_date||'')+'"'
          + caseDateRangeAttr() + ' '+(ro?'readonly':'')+' style="width:100%;border:1px solid #D8BE93;border-radius:3px;padding:2px;font-size:12px;"></td>'
          + '<td><input type="text" class="cr" data-f="audited_time" value="'+esc(r.audited_time||'')+'" data-eg-hint="直接輸入，例 13:15；或用表頭的「自動排」" '+(ro?'readonly':'')+' style="width:100%;border:1px solid #D8BE93;border-radius:3px;padding:2px 4px;font-size:12px;"></td>'
          + '<td><input type="date" class="cr" data-f="improve_due" value="'+esc(r.improve_due||'')+'" '+(ro?'readonly':'')+' style="width:100%;border:1px solid #D8BE93;border-radius:3px;padding:2px;font-size:12px;"></td>'
          + '<td>'+(ro?'':'<span class="ia-op danger" onclick="caseRowDel('+i+')"><i class="fa fa-times"></i></span>')+'</td>'
          + '</tr>';
    });
    $('#cDeptBody').html(h);
    if (typeof checkDupProcess === 'function') { checkDupProcess(); checkEscortConflict(); }
}
/**
 * 稽核員／陪檢員的欄位：已選的人做成標籤（可按 × 移除），下方下拉再加人。
 * 兩邊互相排除（同一個人不可同時當稽核員與陪檢員），所以候選清單會即時把對面已選的人拿掉。
 */
var IA_PPL_MAX = <?= IA_CD_PERSON_MAX ?>;
function peopleCell(i, r, kind, cands, ro){
    var label = (kind==='auditor') ? '稽核員' : '陪檢員';
    var mine  = rowKeys(r, kind), other = rowKeys(r, kind==='auditor'?'escort':'auditor');
    var fb    = r[kind+'_people'] || [];
    var usedU = {};
    mine.concat(other).forEach(function(k){ usedU[String(k).split(':')[0]] = 1; });

    var h = '<div class="ia-ppl">';
    mine.forEach(function(k){
        var p = postByKey(k, cands, fb);
        h += '<span class="ppl-chip" title="'+esc(p.label)+'"><span class="nm">'+esc(p.name)+'</span>'
           + (ro?'':'<span class="x" onclick="caseDelPerson('+i+',\''+kind+'\',\''+esc(k)+'\')">×</span>')+'</span>';
    });
    if (!mine.length && ro) h += '<span class="ppl-none">'+(kind==='escort'?'（未指定）':'（未指定）')+'</span>';
    if (!ro && mine.length < IA_PPL_MAX) {
        var opts = '<option value="">＋加入'+label+(mine.length?'':(kind==='escort'?'（可不填）':''))+'</option>';
        (cands||[]).forEach(function(p){
            var key = p.post_key3 || postKeyOf(p.id, p.dept_id, p.position_id);
            if (usedU[String(p.id)]) return;                 // 已在任一邊選過的人不再出現
            opts += '<option value="'+esc(key)+'">'
                  + esc((p.dept_name?p.dept_name+'　':'')+(p.position_name?p.position_name+'　':'')+p.user_cname
                        + (+p.is_main===0?'（兼任）':'') + (p.leave_note?'（'+p.leave_note+'）':''))
                  + '</option>';
        });
        h += '<select class="ppl-add" data-i="'+i+'" data-kind="'+kind+'" data-eg-filter="輸入姓名篩選…">'+opts+'</select>';
    }
    return h + '</div>';
}
function caseAddPerson(i, kind, key){
    if (!key) return;
    var r = CASE_ROWS[i] || (CASE_ROWS[i] = newCaseRow());
    var mine = rowKeys(r, kind), other = rowKeys(r, kind==='auditor'?'escort':'auditor');
    var uid = String(key).split(':')[0];
    var dup = mine.concat(other).some(function(k){ return String(k).split(':')[0] === uid; });
    if (dup || mine.length >= IA_PPL_MAX) { renderCaseRows(); return; }
    mine.push(key);
    renderCaseRows(); checkEscortConflict();
}
function caseDelPerson(i, kind, key){
    var r = CASE_ROWS[i]; if (!r) return;
    r[kind+'_keys'] = rowKeys(r, kind).filter(function(k){ return String(k) !== String(key); });
    renderCaseRows(); checkEscortConflict();
}
$(document).on('change', '#cDeptBody .ppl-add', function(){
    caseAddPerson(+$(this).data('i'), $(this).data('kind'), $(this).val());
});
/* 可增列表格鐵則：末列按 ↓ 自動加列、空白末列按 ↑ 自動移除，由 eg_input_rules.js 呼叫這兩支 */
function caseRowAdd(i){ CASE_ROWS.splice((i==null?CASE_ROWS.length:i+1), 0, newCaseRow()); renderCaseRows(); }
function caseRowDel(i){ if (CASE_ROWS.length<=1) return; CASE_ROWS.splice(i,1); renderCaseRows(); }
$(document).on('change','.cr', function(){
    var i = +$(this).closest('tr').data('i'), f = $(this).data('f');
    CASE_ROWS[i] = CASE_ROWS[i] || {};
    CASE_ROWS[i][f] = $(this).val();
    // 改了某一列的受稽時間 → 後面的自動順延（使用者可在表頭關掉）
    if (f === 'audited_time' && $('#cTimeCascade').is(':checked')) {
        var n = normTime($(this).val());
        if (n) { CASE_ROWS[i].audited_time = n; iaCascadeTimes(i); renderCaseRows(); }
    }
    // 任何一列的時間或日期改動，表頭的結束時間與「來不來得及」都要跟著重算
    if (f === 'audited_time' || f === 'audited_date') iaTimeRecalc();
});
function collectCaseRows(){
    var out = [];
    $('#cDeptBody tr').each(function(){
        var i = +$(this).data('i'), src = CASE_ROWS[i] || {};
        var r = {};
        $(this).find('.cr').each(function(){ r[$(this).data('f')] = $(this).val(); });
        // 整列全空的不送（末列常常是按 ↓ 加出來還沒填的）
        var any = false;
        $.each(r, function(_k,v){ if (String(v||'').trim()!=='') any = true; });
        // 稽核員／陪檢員是標籤不是輸入框，直接取 CASE_ROWS 上的職務鍵陣列
        r.auditor_keys = (src.auditor_keys||[]).slice();
        r.escort_keys  = (src.escort_keys||[]).slice();
        if (r.auditor_keys.length || r.escort_keys.length) any = true;
        if (any) out.push(r);
    });
    return out;
}
function validateCase(){
    clearErrs($('#caseMask'));
    var ok = true;
    // 使用者指定的兩條規則（後端 case_save 也會再擋一次）
    if (!checkDupProcess()) ok = false;
    if (!checkEscortConflict()) ok = false;
    ok = fieldErr($('#cNotify'), 'errCNotify', $('#cNotify').val() ? '' : '請填通知日期') && ok;
    var f = $('#cFrom').val(), t = $('#cTo').val();
    if (f && t && t < f) ok = fieldErr($('#cTo'), 'errCTo', '結束日期不可早於開始日期') && ok;
    var s = $('#cMeetStart').val().trim(), e = $('#cMeetEnd').val().trim();
    if (s && e && normTime(s) && normTime(e) && normTime(e) < normTime(s)) {
        ok = fieldErr($('#cMeetEnd'), 'errCMeetTime', '結束時間不可早於開始時間') && ok;
    }
    if (!checkAuditedDates()) ok = false;
    if (!caseSyncMakerDateMax()) ok = false;
    return ok;
}
/* 受稽日期必須落在稽核期間內（2026-09-18 使用者指正：要在存檔當下就擋住）。
   後端 case_save 同規則再擋一次（鐵律8）；這裡負責即時標紅並講明白是哪一列。 */
/* 受稽日期只能落在稽核期間內（2026-09-18 使用者要求：不得早於稽核起日期）。
   日曆本身就限制起迄（min／max），比「選了才報錯」好用；
   存檔時前端仍會再檢查一次、後端也擋一次（既有資料可能超出範圍）。 */
function caseDateRangeAttr(){
    var f = $('#cFrom').val(), t = $('#cTo').val() || f;
    return (f ? (' min="' + f + '"') : '') + (t ? (' max="' + t + '"') : '');
}
function caseSyncDateRange(){
    var f = $('#cFrom').val() || '', t = $('#cTo').val() || f;
    $('#cDeptBody input[data-f=audited_date], #cAllAudited').each(function(){
        if (f) $(this).attr('min', f); else $(this).removeAttr('min');
        if (t) $(this).attr('max', t); else $(this).removeAttr('max');
    });
}
$(document).on('change', '#cFrom, #cTo', function(){
    if ($('#caseMask').is(':visible')) { caseSyncDateRange(); checkAuditedDates(); }
});

function checkAuditedDates(){
    var f = $('#cFrom').val(), t = $('#cTo').val() || f;
    $('#cDeptBody input[data-f=audited_date]').removeClass('err');
    $('#errCAudited').removeClass('on').text('');
    if (!f) return true;                       // 還沒填稽核期間就不判（送出前 notify/期間自己有檢查）
    var bad = [];
    $('#cDeptBody tr').each(function(i){
        var $d = $(this).find('input[data-f=audited_date]'), v = $d.val();
        if (!v) return;
        if (v < f || v > t) { $d.addClass('err'); bad.push('第 ' + (i + 1) + ' 列（' + dispDate(v) + '）'); }
    });
    if (!bad.length) return true;
    $('#errCAudited').addClass('on').html('下列受稽日期不在稽核期間（' + dispDate(f)
        + (t !== f ? ('～' + dispDate(t)) : '') + '）內：' + bad.join('、')
        + '<br>請改受稽日期，或把上方的稽核期間調整成涵蓋這幾天。');
    return false;
}
$(document).on('change', '#cFrom, #cTo, #cDeptBody input[data-f=audited_date]', function(){
    if ($('#caseMask').is(':visible')) checkAuditedDates();
});
/* 送出（完成）前的必填檢查（2026-09-18 使用者要求）：稽核起始主過程／受稽單位／稽核員／
   陪檢員／受稽日期／時間／預定完成改善，每一列都要填齊。
   **草稿存檔刻意不檢查**（現場本來就是邊排邊填），只有按「完成」＝這張單要發出去時才擋；
   後端 ia_case_required_missing() 用同一組規則再擋一次（鐵律8）。 */
function caseRequiredCheck(){
    $('#cDeptBody .cr').removeClass('err');
    $('#cDeptBody td').removeClass('err-cell');
    $('#errCRequired').removeClass('on').empty();
    var bad = [];
    $('#cDeptBody tr').each(function(n){
        var $tr = $(this), i = +$tr.data('i'), src = CASE_ROWS[i] || {}, miss = [];
        [['start_process','稽核起始主過程'], ['dept_id','受稽單位'], ['audited_date','受稽日期'],
         ['audited_time','時間'], ['improve_due','預定完成改善']].forEach(function(f){
            var $el = $tr.find('.cr[data-f=' + f[0] + ']');
            if (String($el.val() || '').trim() === '') { $el.addClass('err'); miss.push(f[1]); }
        });
        if (!(src.auditor_keys || []).length) { $tr.find('td').eq(3).addClass('err-cell'); miss.push('稽核員'); }
        if (!(src.escort_keys  || []).length) { $tr.find('td').eq(4).addClass('err-cell'); miss.push('陪檢員'); }
        if (miss.length) bad.push('第 ' + (n + 1) + ' 列：' + miss.join('、'));
    });
    var over = (typeof caseTimeOverrun === 'function') ? caseTimeOverrun() : '';
    if (!bad.length && !over) return true;
    $('#errCRequired').addClass('on').html(
        (bad.length ? ('這張通知單要發出去之前，下列必填欄位還沒填（已用紅色標出來）：<br>' + bad.join('<br>')) : '')
        + (bad.length && over ? '<br>' : '') + (over ? ('受稽時間排不下：' + over) : ''));
    return false;
}
/* 時間欄位一律直接輸入、離開欄位正規化（0900/900/9 → 09:00），禁用下拉選時間 */
function normTime(v){
    v = String(v||'').trim(); if (v==='') return '';
    var m = v.match(/^(\d{1,2}):(\d{2})$/) || v.match(/^(\d{1,2})(\d{2})$/);
    if (m) { var h=+m[1], i=+m[2]; if(h<=23&&i<=59) return ('0'+h).slice(-2)+':'+('0'+i).slice(-2); return null; }
    if (/^\d{1,2}$/.test(v)) { var hh=+v; if(hh<=23) return ('0'+hh).slice(-2)+':00'; }
    return null;
}
$(document).on('blur','#cMeetStart,#cMeetEnd,input[data-f=audited_time]', function(){
    var n = normTime($(this).val());
    if (n === null) { $(this).addClass('err'); } else { $(this).removeClass('err').val(n); }
});
/** 存檔要送的內容（存檔與「完成」共用同一份，兩邊各寫一次遲早走鐘） */
function caseSavePayload(){
    return {action:'case_save', case_id:CASE_ID, notify_date:$('#cNotify').val(),
        audit_from:$('#cFrom').val(), audit_to:$('#cTo').val(), leader_key:$('#cLeader').val(),
        end_meet_date:$('#cMeetDate').val(), end_meet_start:$('#cMeetStart').val(), end_meet_end:$('#cMeetEnd').val(),
        end_meet_place:$('#cMeetPlace').val(), remark:$('#cRemark').val(),
        maker_id:($('#cMaker').val()||''), maker_date:($('#cMakerDate').val()||''),
        unit_minutes:iaTimeStep(),          // 每個受稽單位需要幾分鐘（送出前的時間檢查後端也要用）
        depts:JSON.stringify(collectCaseRows())};
}
$('#btnCaseSave').on('click', function(){
    if (!validateCase()) return;
    $.post(API, caseSavePayload(), function(res){
        if (!res.ok) { alert(res.error||'儲存失敗'); return; }
        // 稽核日期改過的話件號會跟著重編，要講出來（不然使用者只會覺得編號莫名其妙變了）
        alert('已儲存' + (res.no_changed ? ('\n\n稽核日期改變，稽核件號已由 ' + res.no_old + ' 重編為 ' + res.case_no) : ''));
        CASE_ID = res.case_id;
        loadCases(function(){ openCase(CASE_ID); });
    }, 'json');
});

/* ---- 會議紀錄：自動建草稿 → 新分頁開既有模組 ---- */
function renderCaseMeeting(c){
    var admin = <?= $perms['canAdmin'] ? 'true' : 'false' ?>;
    var h = '';
    [['pre','事前會議'],['end','結束會議']].forEach(function(k){
        var m = c[k[0]+'_meeting'];
        h += '<div style="margin-bottom:6px;"><b style="color:#8A5A2B;">'+k[1]+'：</b>';
        if (m) {
            h += esc(m.subject)+'　'+dispDate(m.meeting_date)
               + ' <span class="ia-op" onclick="openMeeting('+m.meeting_id+')"><i class="fa fa-external-link"></i> 開啟會議紀錄</span>';
            if (admin) h += ' <span class="ia-op" onclick="syncMeetingAtt(\''+k[0]+'\')" title="依目前的稽核小組重新帶入出席人員；會議內容與已簽到狀態都保留"><i class="fa fa-refresh"></i> 重新帶入與會人員</span>'
                          + ' <span class="ia-op" onclick="unlinkMeeting(\''+k[0]+'\')">解除連結</span>';
        } else {
            h += '<span style="color:#a08356;">尚未建立</span>';
            if (admin) h += ' <span class="ia-op" onclick="createMeeting(\''+k[0]+'\')"><i class="fa fa-plus"></i> 自動建立並開啟</span>';
        }
        h += '</div>';
    });
    $('#cMeetingBox').html(h);
}
function openMeeting(id){ window.open('meeting_record.php?id='+id, '_blank'); }
/* 帶人進會議時要主動講明兩件事（使用者要求，不可以安靜換掉）：
   ①shifted＝名單上登記的職務在會議日期當天還不成立，已改印他「當時」真正的身分
   ②dropped＝當天還沒兼任的那個職務沒有列出來（小組是一筆職務一列，會議是一人一列）
   ③removed＝重新帶入時被移出名單、而且已經簽到過的人（他的簽到會跟著不見） */
function meetingPeopleNotice(res){
    var msg = [];
    if (res.shifted && res.shifted.length) {
        msg.push('下列人員在會議日期（' + dispDate(res.meeting_date) + '）當天還不是稽核小組名單上登記的那個職務，\n'
               + '已改印他們「當時」真正的身分：\n'
               + res.shifted.map(function(s){
                     return '　・' + s.name + '：名單登記「' + s.listed + '」→ 當天實際「' + s.actual + '」';
                 }).join('\n')
               + '\n（這是刻意的：補舊資料時印上他當時還沒有的職稱會造成身分錯亂。\n'
               + '　若確實是職務異動紀錄補登不完整，請到 員工管理 → 異動紀錄 補正。）');
    }
    if (res.dropped && res.dropped.length) {
        msg.push('下列兼任職務在會議日期當天尚未成立，未列入出席名單：\n'
               + res.dropped.map(function(d){ return '　・' + d.name + '：' + d.post; }).join('\n'));
    }
    if (res.removed && res.removed.length) {
        msg.push('下列人員已不在目前的稽核小組名單內，已從出席名單移除（原本的簽到紀錄一併消失）：\n'
               + '　' + res.removed.join('、'));
    }
    return msg;
}
function createMeeting(kind){
    if (!CASE_ID) { alert('請先儲存稽核通知單'); return; }
    $.post(API, {action:'meeting_create', case_id:CASE_ID, kind:kind}, function(res){
        if (!res.ok) { alert(res.error||'建立失敗'); return; }
        var msg = meetingPeopleNotice(res);
        if (msg.length) alert('會議紀錄已建立（出席 '+(res.attendees||0)+' 人），但請注意：\n\n'+msg.join('\n\n'));
        openMeeting(res.meeting_id);
        loadCases(function(){ openCase(CASE_ID); });
    }, 'json');
}
/* 會議建立之後才改稽核小組時用這顆（2026-09-17 使用者要求）：只重寫出席人員，
   會議本身與會議要項都不動、已簽到的人保留簽到狀態。 */
function syncMeetingAtt(kind){
    if (!CASE_ID) return;
    if (!confirm('要依「目前的稽核小組」重新帶入【' + (kind==='pre'?'事前會議':'結束會議') + '】的出席人員嗎？\n\n'
               + '・只會重寫出席人員名單，會議主題／日期／地點／會議要項都不會變動\n'
               + '・已經簽到的人保留簽到狀態\n'
               + '・已不在小組名單內的人會被移除（若他已簽到，簽到紀錄會一併消失）')) return;
    $.post(API, {action:'meeting_sync_att', case_id:CASE_ID, kind:kind}, function(res){
        if (!res.ok) { alert(res.error||'重新帶入失敗'); return; }
        var msg = meetingPeopleNotice(res);
        alert('已依目前的稽核小組重新帶入出席人員，共 '+(res.attendees||0)+' 人'
            + (res.from_team ? '' : '（本年度尚未建立稽核小組，改用這張通知單的稽核員與陪檢員）')
            + (msg.length ? '\n\n請注意：\n\n'+msg.join('\n\n') : ''));
        loadCases(function(){ openCase(CASE_ID); });
    }, 'json');
}
function unlinkMeeting(kind){
    if (!confirm('解除連結只是讓這張通知單不再指向該筆會議紀錄，會議紀錄本身不會被刪除。確定？')) return;
    $.post(API, {action:'meeting_link', case_id:CASE_ID, kind:kind, meeting_id:''}, function(res){
        if (!res.ok) { alert(res.error||'失敗'); return; }
        loadCases(function(){ openCase(CASE_ID); });
    }, 'json');
}

/* ============================ 查檢表 ============================ */
function loadChecks(){
    $.getJSON(API, {action:'check_list', year:YEAR, kind:$('#checkKind').val(), kw:$('#checkKw').val()}, function(res){
        if (!res.ok) return;
        LIST.check = res.rows||[]; renderChecks();
    });
}
$('#btnCheckSearch').on('click', function(){ PAGE.check=1; loadChecks(); });
$('#checkKind').on('change', function(){ PAGE.check=1; loadChecks(); });
$('#checkKw').on('keydown', function(e){ if(e.which===13){ PAGE.check=1; loadChecks(); } });

function kindLabel(k){ return ((META.check_kinds||{})[k]||{}).label || k; }
function renderChecks(){
    $('#checkPager').html(renderPager('check', LIST.check.length));
    var rows = pageSlice('check');
    if (!rows.length) {
        $('#checkBody').html('<tr><td colspan="10" class="ia-empty">沒有符合條件的查檢表</td></tr>');
        renderCheckAutoHint();     // 一張都還沒建的時候最需要這條提示，不可以提早 return 就跳過
        return;
    }
    var h = '';
    rows.forEach(function(r){
        h += '<tr>'
          + '<td>'+esc(kindLabel(r.kind))+(r.half?('（'+(r.half==='H1'?'上':'下')+'半年）'):'')+'</td>'
          + '<td class="l">'+esc(r.title||kindLabel(r.kind))+'</td>'
          + '<td>'+esc(r.case_no||'—')+'</td>'
          + '<td>'+esc(r.auditor_name||'')+'</td>'
          + '<td>'+dispDate(r.check_date)+'</td>'
          + '<td>'+esc(r.item_cnt)+'</td>'
          + '<td>'+(+r.ng_cnt ? '<b style="color:#C4442D;">'+esc(r.ng_cnt)+'</b>' : '0')+'</td>'
          + '<td>'+(+r.todo_cnt ? '<b style="color:#d98a33;">'+esc(r.todo_cnt)+'</b>' : '0')+'</td>'
          + '<td><span class="st st-'+(r.status==='done'?'done':'draft')+'">'+(r.status==='done'?'已結案':'填寫中')+'</span></td>'
          + '<td><span class="ia-op" onclick="openCheck('+r.check_id+')"><i class="fa fa-edit"></i> 開啟</span>'
          + '<span class="ia-op" onclick="printCheck('+r.check_id+')"><i class="fa fa-print"></i></span>'
          + (IS_ADMIN ? '<span class="ia-op danger" onclick="delCheck('+r.check_id+')" title="刪除這張查檢表"><i class="fa fa-trash"></i></span>' : '')
          + '</td></tr>';
    });
    $('#checkBody').html(h);
    renderCheckAutoHint();
}
/* 「可以自動建立的就自動建立，人工才要填的再提醒」（2026-09-14 使用者要求）。
   ①去年整年的績效執行稽核查檢表還沒建 → 一鍵建立（只要求填建立日期，其餘全自動）
   ②已有填好的系統稽核紀錄表、但還沒有 AS稽核查檢表 → 一鍵依它自動判定建立
   ③有判定不合格／沒達成卻還沒開單的 → 提醒去那張表按「一鍵開立」 */
function renderCheckAutoHint(){
    if (!<?= $perms['canAudit'] ? 'true' : 'false' ?>) { $('#checkAutoHint').hide(); return; }
    // 只在「沒有另外篩種類」時提示，否則篩成單一種類會誤報成沒建立
    if ($('#checkKind').val()) { $('#checkAutoHint').hide(); return; }
    var all = LIST.check||[], msgs = [];
    var kpiYear = YEAR - 1;                       // 本年度這張表稽核的是去年
    var hasKpi = all.some(function(r){ return r.kind==='kpi'; });
    if (!hasKpi) {
        msgs.push('<b>'+YEAR+' 年建立的績效執行稽核查檢表（稽核 '+kpiYear+' 年度）</b>還沒建立。'
            + '<span class="ia-op" onclick="autoNewCheck(\'kpi\')"><i class="fa fa-magic"></i> 自動建立（只要填建立日期）</span>');
    }
    var sysDone = all.filter(function(r){ return r.kind==='system' && r.status==='done'; });
    if (sysDone.length && !all.some(function(r){ return r.kind==='as'; })) {
        msgs.push('已經有填好的<b>系統稽核紀錄表</b>，可直接建立 <b>AS稽核查檢表</b>並自動判定合格／不合格。'
            + '<span class="ia-op" onclick="autoNewCheck(\'as\')"><i class="fa fa-magic"></i> 依系統稽核紀錄表建立</span>');
    }
    var pend = all.filter(function(r){ return +r.ng_cnt > 0; }).length;
    if (pend) msgs.push('有 '+pend+' 張查檢表判定出不合格／沒達成的項目，開啟後可用「一鍵開立」批次開單。');
    $('#checkAutoHint').toggle(msgs.length>0).html(msgs.join('<br>'));
}
/** 由提示列直接開建立跳窗：種類先選好、題目全勾、日期留給管理員確認 */
function autoNewCheck(kind){
    $('#btnCheckNew').trigger('click');
    setTimeout(function(){
        $('#nkKind').val(kind);
        nkKindChanged();
        setTimeout(function(){
            $('#nkAll').trigger('click');            // 全部帶入（要取消的自己取消）
            if (kind==='as') {                       // 自動判定來源預設選最近一張系統稽核紀錄表
                var $s = $('#nkSrc');
                if ($s.find('option').length > 1) $s.val($s.find('option').eq(1).val());
            }
            $('#nkDate').focus();
        }, 600);
    }, 0);
}
/* 刪除查檢表（管理員限定）。已經開過不符合通知單的擋在後端，這裡先把原因講清楚。 */
function delCheck(id){
    if (!IS_ADMIN) return;
    if (!confirm('確定刪除這張查檢表？\n（已經開過不符合通知單的查檢表不可刪除，請先刪掉那幾張 IA 單）')) return;
    $.post(API, {action:'check_delete', check_id:id}, function(res){
        if (!res.ok) { alert(res.error||'刪除失敗'); return; }
        loadChecks();
    }, 'json');
}

/* ---- 建立查檢表：先看題庫再勾 ---- */
var BANK = [];
$('#btnCheckNew').on('click', function(){
    var kh = '';
    $.each(META.check_kinds||{}, function(k,v){ kh += '<option value="'+k+'">'+esc(v.label)+'</option>'; });
    $('#nkKind').html(kh);
    // 清單上已經篩了種類就沿用（使用者多半是在那個種類的清單上按下「建立查檢表」）
    if ($('#checkKind').val()) $('#nkKind').val($('#checkKind').val());
    $('#nkDate').val(META.today);
    nkSelBusy('#nkAuditor', true);
    peopleAsof(META.today, function(){
        $('#nkAuditor').html(postOptions(META.auditors, '', META.me.id, '（未指定）'));
        nkSelBusy('#nkAuditor', false);
    });
    /* 件號下拉自己去撈（點開即刷新）：CASES 只有在使用者「去過稽核通知單分頁」時才有值，
       直接從查檢表分頁按建立的話，下拉會是空的——這是選了件號才帶日期／主過程的前提。 */
    $('#nkCase').html(caseOptions(''));
    NK_CASE = null; $('#nkCaseDay').removeData('case').empty(); nkRenderCaseInfo();
    nkSelBusy('#nkCase', true);
    $.getJSON(API, {action:'case_list', year:YEAR}, function(res){
        if (res && res.ok) { CASES = res.rows || []; $('#nkCase').html(caseOptions('')); }
    }).always(function(){ nkSelBusy('#nkCase', false); });
    $('#nkTitle').val(''); $('#nkFilter').val('');
    clearErrs($('#checkNewMask'));
    nkKindChanged();
    openMask('checkNewMask');
});
/* 先選種類，畫面再依種類長出對應的選項（2026-09-14 使用者要求）。 */
var NK_KIND_HINT = {
    as:     'AS稽核查檢表：題目＝AS9100 條文題庫。左欄的「作業項目」只是把中間清單聚焦到相關條文（不會自動勾選），勾好的會列在最右側；也可以指定一張已填好的系統稽核紀錄表，建立時自動判定合格／不合格。',
    system: '系統稽核紀錄表：稽核對象是「AS 表單」，清單上直接列表單編號與名稱。左欄挑部門＝把中間清單聚焦到該部門的表單（不會自動勾選），逐張勾或按「全選」；已選的表單列在最右側，取消部門不會把它們清掉。',
    kpi:    '績效執行稽核查檢表：稽核「去年一整年」的 KPI。部門／指標／目標／受稽人（擔當者）與達成／沒達成全部自動帶入，您只要確認建立日期與要查哪幾項。'
};
function kpiAuditYear(d){ var y = parseInt(String(d||META.today).substr(0,4),10)||0; return y-1; }
function nkKindChanged(){
    var kind = $('#nkKind').val(), isKpi = (kind==='kpi'), isAs = (kind==='as');
    $('#nkKindHint').text(NK_KIND_HINT[kind]||'');
    // **不可以用 `$('#nkYearLab').closest('div')`**：label 的 closest('div') 是整個 .ia-form，
    // 一 toggle 會把種類／日期整區都藏起來（2026-09-14 踩過一次）。
    $('#nkYearLab').toggle(isKpi); $('#nkYearWrap').toggle(isKpi);
    $('#nkSrcLab').toggle(isAs);   $('#nkSrcWrap').toggle(isAs);
    $('#nkYearShow').val(isKpi ? (kpiAuditYear($('#nkDate').val())+' 年度（去年整年）') : '');
    if (isAs) loadSrcChecks();
    loadBank();
}
$('#nkKind').on('change', nkKindChanged);
$('#nkDate').on('change', function(){
    if ($('#nkKind').val()==='kpi') nkKindChanged();      // 稽核年度＝建立日期的前一年，日期一改要跟著換題庫
    // 稽核人清單以**稽核日期**當時的在職狀態與職稱為準（ai-rules/22）
    var cur = $('#nkAuditor').val();
    nkSelBusy('#nkAuditor', true);
    peopleAsof($(this).val(), function(){
        $('#nkAuditor').html(postOptions(META.auditors, cur, META.me.id, '（未指定）'));
        nkSelBusy('#nkAuditor', false);
    });
});
/* 下拉在等 AJAX 時不要變成「零選項」（2026-09-18 使用者回報「下拉按鈕突然不能按，一下後又好了」）。
   量測結果：#nkAuditor、#nkSrc 在資料回來之前各有約 150~250ms 的空窗，
   使用者剛好在那一瞬間點下去，看到的就是一個打不開／空的清單。
   作法：空窗期放一個「載入中…」並暫時停用，資料回來再換成真正的選項——
   狀態明確，也不會讓人以為壞掉。**只在本來就沒有選項時才動它**，
   已經有選項的下拉一律保持可用（換選項用整批取代，不留空窗）。 */
function nkSelBusy(sel, on){
    var $s = $(sel);
    if (!$s.length) return;
    if (on) {
        if (!$s.find('option').length) $s.html('<option value="">載入中…</option>').prop('disabled', true);
    } else {
        $s.prop('disabled', false);
    }
}

/* ---------- 所屬件號 → 受稽日期／當天稽核內容（2026-09-18 使用者要求） ----------
   ①**建立日期自動＝該件號的受稽日期**；一張通知單常常跨好幾天，所以多天時另外給一個下拉讓使用者挑
     （挑哪一天，下面就只列那一天要稽核的單位，建立日期也跟著換）。
   ②列出那一天的**稽核起始主過程**與**受稽單位**——建查檢表時要照這個挑表單，
     不然使用者得另外開通知單才知道這次要查什麼。 */
var NK_CASE = null;
/* 這張通知單「可以挑哪幾天」（2026-09-18 使用者指定：**以件號的稽核期間為準**）。
   為什麼不是以受稽單位列上的受稽日期為準：那一欄常常沒填，填了也可能跟表頭的稽核期間對不起來
   （實際資料：251203001 的稽核期間是 12/03，五個受稽單位卻都填 12/04）。
   稽核期間才是這張通知單「哪幾天要去稽核」的正式來源。
   退路：沒有稽核期間才用受稽單位列上的日期，再沒有才用通知日期。
   期間跨很多天時最多列 31 天，避免下拉爆掉。 */
function nkCaseDays(c){
    if (!c) return [];
    var days = [], from = String(c.audit_from || '').substr(0, 10), to = String(c.audit_to || '').substr(0, 10);
    if (from) {
        var cur = new Date(from + 'T00:00:00'), end = new Date((to || from) + 'T00:00:00'), n = 0;
        while (cur <= end && n < 31) {
            days.push(cur.getFullYear() + '-' + ('0'+(cur.getMonth()+1)).slice(-2) + '-' + ('0'+cur.getDate()).slice(-2));
            cur.setDate(cur.getDate() + 1); n++;
        }
        return days;
    }
    (c.depts || []).forEach(function(d){
        var v = String(d.audited_date || '').substr(0, 10);
        if (v && days.indexOf(v) < 0) days.push(v);
    });
    if (days.length) { days.sort(); return days; }
    var nd = String(c.notify_date || '').substr(0, 10);
    return nd ? [nd] : [];
}
/** 挑到的那一天有哪些受稽單位；**當天一個都對不上時退回全部列**（並由呼叫端標出不一致） */
function nkRowsOfDay(c, pick){
    var all = (c && c.depts) || [];
    if (!pick) return {rows: all, mismatch: false};
    var hit = all.filter(function(d){ return String(d.audited_date || '').substr(0, 10) === pick; });
    if (hit.length) return {rows: hit, mismatch: false};
    // 有填受稽日期、卻沒有一列落在這一天＝資料不一致，要講出來而不是顯示空白
    var dated = all.filter(function(d){ return !!String(d.audited_date || '').substr(0, 10); });
    return {rows: all, mismatch: dated.length > 0};
}
function nkRenderCaseInfo(){
    var c = NK_CASE;
    if (!c) { $('#nkCaseDayWrap').hide(); $('#nkCaseInfo').hide().empty(); return; }
    var days = nkCaseDays(c), pick = $('#nkCaseDay').val() || '';
    // 多天才給下拉；只有一天就直接用那一天（下拉出現卻只有一個選項只是干擾）
    if (days.length > 1) {
        if (!$('#nkCaseDay option').length || $('#nkCaseDay').data('case') !== c.case_id) {
            $('#nkCaseDay').data('case', c.case_id)
                .html(days.map(function(d){ return '<option value="'+d+'">'+dispDate(d)+'</option>'; }).join(''));
            pick = days[0];
            $('#nkCaseDay').val(pick);
        }
        $('#nkCaseDayWrap').show();
    } else {
        $('#nkCaseDayWrap').hide();
        pick = days[0] || '';
    }
    // 建立日期自動跟著受稽日期（使用者仍可自己改）
    if (pick && $('#nkDate').val() !== pick) { $('#nkDate').val(pick).trigger('change'); }

    var got = nkRowsOfDay(c, pick), rows = got.rows;
    /* 版面（2026-09-18 使用者定調）：**不要摘要那一行、直接展開明細**。
       一列一個單位、小字，限制高度可捲動（這個跳窗下半部還有挑題區要用空間）。 */
    if (!rows.length) {
        $('#nkCaseInfo').show().html('<span style="color:#a08356;">這一天沒有受稽單位（請確認通知單上的受稽日期）</span>');
        return;
    }
    var warn = got.mismatch
        ? ('<div style="color:#C4442D;margin-bottom:2px;">⚠ 這張通知單的受稽單位列填的受稽日期，'
           + '與表頭的稽核期間（' + dispDate(pick) + '）對不起來，下面先列出全部受稽單位。</div>')
        : '';
    $('#nkCaseInfo').show().html(warn +
        '<div style="max-height:110px;overflow:auto;font-size:12px;line-height:1.7;">'
        + rows.map(function(d){
              return '<div>' + esc(d.audited_time || '　　') + '　<b>' + esc(d.dept_name || '') + '</b>　'
                   + '<span style="color:#8a6d45;">' + esc(d.start_process || '—') + '</span></div>';
          }).join('') + '</div>');
}
$(document).on('change', '#nkCase', function(){
    var id = +$(this).val();
    $('#nkCaseDay').removeData('case').empty();
    if (!id) { NK_CASE = null; nkRenderCaseInfo(); return; }
    $.getJSON(API, {action:'case_get', case_id:id}, function(res){
        NK_CASE = (res && res.ok) ? res.row || res.case || res : null;
        if (NK_CASE && !NK_CASE.depts && res.depts) NK_CASE.depts = res.depts;
        nkRenderCaseInfo();
        nkPickAuditorFromCase();
    });
});
$(document).on('change', '#nkCaseDay', function(){ nkRenderCaseInfo(); nkPickAuditorFromCase(); });

/* 稽核人自動帶成「這張通知單上的稽核員」（2026-09-18 使用者要求）。
   一張通知單每個受稽單位都可以有好幾位稽核員，這裡取**當天第一位**當預設值；
   下拉本身仍然是「該稽核日期當時有稽核員資格的人」，使用者可以自己改成別人。
   候選清單裡找不到那個職務時（例如他當時還沒取得資格）就不動預設值，也不會報錯。 */
function nkPickAuditorFromCase(){
    var c = NK_CASE;
    if (!c) return;
    var pick = $('#nkCaseDay').val() || (nkCaseDays(c)[0] || '');
    var rows = nkRowsOfDay(c, pick).rows;
    var key = '';
    for (var i = 0; i < rows.length && !key; i++) {
        var ppl = rows[i].auditors || [];
        for (var j = 0; j < ppl.length && !key; j++) {
            var p = ppl[j];
            var k = p.post_key3 || postKeyOf(p.user_id, p.dept_id, p.position_id);
            if (k && String(k).split(':')[0] !== '0') key = k;
        }
        // 舊資料只有 auditor_id 沒有人員清單時，用 id 當後備
        if (!key && rows[i].auditor_id) key = postKeyOf(rows[i].auditor_id, rows[i].auditor_dept_id, rows[i].auditor_position_id);
    }
    if (!key) return;
    // 稽核日期換了，候選清單也要換（資格與職稱都依當天判定）
    nkSelBusy('#nkAuditor', true);
    peopleAsof($('#nkDate').val(), function(){
        var cur = $('#nkAuditor').val();
        $('#nkAuditor').html(postOptions(META.auditors, key, String(key).split(':')[0], '（未指定）'));
        if (!$('#nkAuditor').val() && cur) $('#nkAuditor').val(cur);
        nkSelBusy('#nkAuditor', false);
    });
}

/** AS 查檢表的「自動判定來源」下拉：已建立的系統稽核紀錄表 */
function loadSrcChecks(){
    nkSelBusy('#nkSrc', true);
    $.getJSON(API, {action:'check_list', kind:'system'}, function(res){
        var h = '<option value="">（不自動判定，合格／不合格留白自己填）</option>';
        (res.rows||[]).forEach(function(r){
            h += '<option value="'+r.check_id+'">'
               + esc(dispDate(r.check_date)+'　'+(r.title||'系統稽核紀錄表')
                     +'（'+r.item_cnt+' 項，不合格 '+r.ng_cnt+'）')+'</option>';
        });
        $('#nkSrc').html(h);
    }).always(function(){ nkSelBusy('#nkSrc', false); });
}
function loadBank(){
    var kind = $('#nkKind').val();
    NK_TASKS = []; NK_CHECKED = {}; NK_GRP_OPEN = {};   // 換種類＝重來一次，不要把上一種的勾選帶過去
    NK_AUTOPICK = false; $('#nkAutoPick').prop('checked', false);
    $('#nkTaskFilter').val('');
    $.getJSON(API, {action:'check_bank', kind:kind, year:YEAR, check_date:$('#nkDate').val()}, function(res){
        if (!res.ok) { $('#nkPick').html('<div class="ia-empty">'+esc(res.error||'載入失敗')+'</div>'); return; }
        BANK = res.rows||[];
        NK_DEPT_CODES = res.dept_codes || {};   // AS 文件編號的部門代碼→部門名稱（標籤分類用）
        renderBank();
    });
}
/* 一列題目在畫面上的樣子。tags＝左欄用哪些標籤挑得到這一列（AS＝作業項目、其餘＝部門）。 */
function bankRow(kind, r){
    if (kind==='as')     return {id:+r.clause_id,  hdr:+r.is_header===1,
                                 text:r.clause_text, sub:r.doc_ref||'',
                                 no:'', name:r.clause_text||'', dept:r.doc_ref||'',
                                 tags:(r.tasks||[]).map(function(t){ return t.task_name; }),
                                 badges:(r.tasks||[]).map(function(t){ return t.task_name; })};
    // 系統稽核紀錄表：這張表稽核的是「表單」，所以列上就只印 **AS 表單編號＋名稱**
    // （2026-09-17 使用者要求）。品質管理系統要求（條文）改成滑鼠移上去才看得到的提示，
    // 資料本身沒有拿掉——開不符合通知單時的「違反條文」還是照樣自動帶入。
    if (kind==='system') return {id:+r.id, hdr:false,
                                 text:(r.doc_no||'')+'　'+(r.doc_name||''),
                                 no:r.doc_no||'', name:r.doc_name||'', sub:'',
                                 tip:(r.clauses||[]).map(function(c){ return c.clause_text; }).join('；'),
                                 dept:r.dept_name||'未分類',
                                 tags:[r.dept_name||'未分類'], badges:[r.dept_name||'未分類']};
    var res = r.result==='ng' ? '沒達成' : (r.result==='ok' ? '達成' : '資料不足');
    return {id:+r.indicator_id, hdr:false, no:'', name:r.name||'', dept:r.dept_name||'',
            text:(r.dept_name?r.dept_name+'　':'')+(r.name||''),
            sub:'目標：'+(r.target_text||'—')
                +'　受稽人：'+(r.owner_name||'（KPI 未設定擔當者）')
                +(r.owner_position_name?('（'+r.owner_position_name+'）'):'')
                +'　自動判定：'+res,
            tags:[r.dept_name||'未分類'], badges:[r.freq_label||'', res].filter(Boolean)};
}
/* 作業項目標籤（只有 AS 查檢表）：點一下＝只勾有這個用途的條文，再點一下取消。
   同時選多個＝聯集。**一個都沒選＝一題都不勾**（2026-09-14 使用者指定改成預設全不選）。
   標籤依 AS 文件編號第二段的部門代碼分類、長在左側分割欄；已選的另外列在右側上方方便確認。 */
var NK_TASKS = [], NK_GRP_OPEN = {}, NK_DEPT_CODES = {};
/** 標籤 → 它出現在哪些部門（同一個作業項目名稱可能掛在不同文件底下，那就兩個群組都列） */
function nkTaskGroupMap(){
    var m = {};
    BANK.forEach(function(raw){
        (raw.tasks||[]).forEach(function(t){
            var mm = /^\s*\d-([A-Za-z]{2})-/.exec(String(t.doc_no||''));
            var code = mm ? mm[1].toUpperCase() : '';
            var g = NK_DEPT_CODES[code] || (code || '未分類');
            (m[t.task_name] || (m[t.task_name] = {}))[g] = 1;
        });
    });
    return m;
}
function nkChip(n, on, withX, label){
    return '<span class="nk-chip'+(on?' on':'')+'" data-t="'+esc(n)+'">'+esc(label==null?n:label)
         + (withX ? '<span class="x">×</span>' : '') + '</span>';
}
/** 左欄挑題模式：AS＝作業項目（依部門分組）／系統稽核紀錄表與績效查檢表＝直接挑部門 */
function nkMode(){ return $('#nkKind').val()==='as' ? 'task' : 'dept'; }
/** 標籤 → 目前題庫裡有幾列掛著它（部門模式要顯示「業務課（6）」才知道挑下去會勾到幾張表單） */
function nkTagCount(){
    var kind = $('#nkKind').val(), c = {};
    BANK.forEach(function(raw){
        var r = bankRow(kind, raw);
        if (r.hdr) return;
        (r.tags||[]).forEach(function(t){ c[t] = (c[t]||0) + 1; });
    });
    return c;
}
/** AS 專用：作業項目 → 掛在哪幾份文件（點了標籤看不出挑到哪張表單／程序書＝2026-09-14 使用者回報） */
function nkTaskDocs(){
    var m = {};
    BANK.forEach(function(raw){
        (raw.tasks||[]).forEach(function(t){
            var key = ((t.doc_no||'')+' '+(t.doc_name||'')).trim();
            var a = m[t.task_name] || (m[t.task_name] = []);
            if (a.indexOf(key) < 0) a.push(key);
        });
    });
    return m;
}
function renderTaskPanel(){
    var kind = $('#nkKind').val(), mode = nkMode();
    $('#nkTaskTitle').text(mode==='task' ? '依作業項目挑題' : '依部門挑題');
    $('#nkTaskFilter').attr('placeholder', mode==='task' ? '篩選作業項目…' : '篩選部門…');

    if (mode === 'dept') {                       // 系統稽核紀錄表／績效查檢表：左欄直接列部門
        var cnt = nkTagCount(), dnames = Object.keys(cnt).sort(function(a,b){
            if (a === '未分類') return 1;
            if (b === '未分類') return -1;
            return a.localeCompare(b, 'zh-Hant');
        });
        NK_TASKS = NK_TASKS.filter(function(n){ return dnames.indexOf(n) >= 0; });
        if (!dnames.length) { $('#nkTaskSide').hide(); $('#nkTaskSel').hide(); NK_TASKS = []; return; }
        var kw2 = $('#nkTaskFilter').val().trim().toLowerCase();
        $('#nkTaskGroups').html('<div class="nk-grp"><div class="nk-grp-body">'
            + dnames.filter(function(n){ return !kw2 || n.toLowerCase().indexOf(kw2)>=0 || NK_TASKS.indexOf(n)>=0; })
                    .map(function(n){ return nkChip(n, NK_TASKS.indexOf(n)>=0, false, n+'（'+cnt[n]+'）'); }).join('')
            + '</div></div>');
        $('#nkTaskSide').show();
        if (NK_TASKS.length) {
            $('#nkTaskSel').show().html('<span class="lb">聚焦中的部門 '+NK_TASKS.length+' 個（只影響中間顯示，不影響已選取的表單）：</span>'
                + NK_TASKS.map(function(n){ return nkChip(n, true, true, n); }).join('')
                + '<span class="nk-chip clear" data-clear="1">✕ 全部清除</span>');
        } else {
            $('#nkTaskSel').show().html('<span class="lb">尚未選擇部門——從左側點一個部門，中間就只會顯示該部門的表單；'
                + '<b>點部門不會自動勾選</b>，請在中間逐張勾，或按「全選」把目前顯示的整批勾起來。'
                + '已勾好的會列在最右側，<b>取消部門也不會消失</b>。</span>');
        }
        return;
    }

    var gm = nkTaskGroupMap(), names = Object.keys(gm).sort();
    if (!names.length) { $('#nkTaskSide').hide(); $('#nkTaskSel').hide(); NK_TASKS = []; return; }
    NK_TASKS = NK_TASKS.filter(function(n){ return names.indexOf(n) >= 0; });

    // 左欄：依部門分組。**已選中的標籤一定留著**——被篩掉就再也取消不了了
    var kw = $('#nkTaskFilter').val().trim().toLowerCase();
    var groups = {};
    names.forEach(function(n){
        if (kw && n.toLowerCase().indexOf(kw) < 0 && NK_TASKS.indexOf(n) < 0) return;
        Object.keys(gm[n]).forEach(function(g){ (groups[g] || (groups[g] = [])).push(n); });
    });
    var gnames = Object.keys(groups).sort(function(a,b){
        if (a === '未分類') return 1;
        if (b === '未分類') return -1;
        return a.localeCompare(b, 'zh-Hant');
    });
    var h = '';
    gnames.forEach(function(g){
        // 打了關鍵字就自動展開（不然篩完還要一個一個點開＝等於沒篩）；有選中的那一組也一定展開
        var sel  = groups[g].filter(function(n){ return NK_TASKS.indexOf(n) >= 0; }).length;
        var open = !!NK_GRP_OPEN[g] || !!kw || sel > 0;
        h += '<div class="nk-grp"><div class="nk-grp-hd" data-g="'+esc(g)+'">'
           + '<span>'+(open?'▾':'▸')+'</span><span>'+esc(g)+'</span>'
           + '<span class="n">'+(sel?('已選 '+sel+' / '):'')+groups[g].length+'</span></div>'
           + (open ? '<div class="nk-grp-body">'
                   + groups[g].map(function(n){ return nkChip(n, NK_TASKS.indexOf(n)>=0, false); }).join('')
                   + '</div>' : '')
           + '</div>';
    });
    $('#nkTaskGroups').html(h || '<div class="ia-empty" style="padding:10px;">沒有符合的作業項目</div>');
    $('#nkTaskSide').show();

    // 右欄上方：已選清單（按 × 取消）——點完標籤看不出自己挑了什麼，所以另外列出來；
    // 2026-09-14 使用者再回報「看不出來是哪個表單或程序書被選到」，故一併列出該項目掛在哪幾份文件。
    if (NK_TASKS.length) {
        var docs = nkTaskDocs();
        $('#nkTaskSel').show().html('<span class="lb">聚焦中的作業項目 '+NK_TASKS.length+' 個（只影響中間顯示，不影響已選取的條文）：</span>'
            + NK_TASKS.map(function(n){ return nkChip(n, true, true); }).join('')
            + '<span class="nk-chip clear" data-clear="1">✕ 全部清除</span>'
            + '<div class="nk-seldoc">' + NK_TASKS.map(function(n){
                  return '<div><b>'+esc(n)+'</b>：'+esc((docs[n]||[]).join('、') || '（題庫裡沒有對應的文件）')+'</div>';
              }).join('') + '</div>');
    } else {
        $('#nkTaskSel').show().html('<span class="lb">尚未選擇作業項目——從左側點一個項目，中間就只會顯示相關條文；'
            + '<b>點標籤不會自動勾選</b>，請在中間逐題勾，或按「全選」把目前顯示的整批勾起來。'
            + '已勾好的會列在最右側，<b>取消標籤也不會消失</b>。</span>');
    }
}
/* 標籤群組展開／收合（預設全部收合，一百多顆標籤攤開會看不完） */
$(document).on('click', '#nkTaskGroups .nk-grp-hd', function(){
    var g = String($(this).data('g'));
    NK_GRP_OPEN[g] = !NK_GRP_OPEN[g];
    renderTaskPanel();
});
/* 標籤／部門＝**只負責聚焦顯示**（2026-09-17 使用者要求，取代舊的「點標籤就整批勾起來」）：
   ①點下去只是把中間清單縮到這個標籤底下的表單，**預設一張都不勾**
   ②要整批勾就開上方「點標籤時自動勾選底下的表單」，或直接按「全選」（全選只動看得到的那幾列）
   ③**取消標籤絕對不可以把已經選好的表單洗掉**——標籤是拿來找表單的，不是選取本身
     （舊版每次點標籤都 NK_CHECKED={} 重來，使用者一移除標籤，剛剛挑好的就整批不見）。 */
$(document).on('click', '#nkTaskGroups .nk-chip, #nkTaskSel .nk-chip', function(){
    if ($(this).data('clear')) { NK_TASKS = []; }
    else {
        var n = String($(this).data('t')), i = NK_TASKS.indexOf(n);
        if (i >= 0) NK_TASKS.splice(i, 1);
        else {
            NK_TASKS.push(n);
            if (NK_AUTOPICK) nkCheckByTag(n, true);   // 使用者自己開了自動勾選才整批勾
        }
    }
    renderBank();
});
/** 把某個標籤（AS＝作業項目／其餘＝部門）底下的項目整批勾起來或取消 */
function nkCheckByTag(tag, on){
    var kind = $('#nkKind').val();
    BANK.forEach(function(raw){
        var r = bankRow(kind, raw);
        if (r.hdr) return;
        if ((r.tags||[]).indexOf(tag) >= 0) NK_CHECKED[r.id] = !!on;
    });
}
var NK_AUTOPICK = false;
$('#nkAutoPick').on('change', function(){
    NK_AUTOPICK = $(this).is(':checked');
    // 開啟當下就把目前已聚焦的標籤底下的表單勾起來（不然使用者會以為開關沒作用）
    if (NK_AUTOPICK) NK_TASKS.forEach(function(n){ nkCheckByTag(n, true); });
    renderBank();
});
$('#nkTaskFilter').on('input', renderTaskPanel);
/** 這一列有沒有落在目前聚焦的標籤內。**一個標籤都沒點＝不篩選，全部列出來**
    （2026-09-17 起這個函式只決定「看不看得到」，不再決定「勾不勾」）。 */
function rowHitTask(r){
    if (!NK_TASKS.length) return true;
    for (var i=0;i<NK_TASKS.length;i++) if ((r.tags||[]).indexOf(NK_TASKS[i]) >= 0) return true;
    return false;
}
/* 勾選狀態記在 NK_CHECKED（id => true/false），**不以畫面上的勾選框為準**：
   打關鍵字篩選時不符合的列根本不在畫面上，只讀畫面＝剛剛勾好的東西會在篩選後整批消失
   （原本的「已勾 N 項」與建立時送出的清單都有這個問題）。沒被動過的列才回退到標籤判定。 */
var NK_CHECKED = {};
function nkIsChecked(r){
    // 2026-09-17 起**只認使用者真的勾過的**：標籤改成聚焦用，不再隱含「選取」的意思
    return !!NK_CHECKED[r.id];
}
/** 每個章節標題列底下勾了幾題（題庫是照順序排的：一個標題列管到下一個標題列為止）
    2026-09-15 使用者回報：標題列固定勾住又取消不掉。原因是它被當成「一定要帶進去」，
    所以做成 checked＋onclick:return false。改成標題列不是勾選框、也不無條件帶入，
    只有「底下真的有題目被勾」的那幾章才會跟著建進查檢表（否則會建出空的章節標題）。 */
function nkHdrPicked(){
    var kind = $('#nkKind').val(), cnt = {}, cur = null;
    BANK.forEach(function(raw){
        var r = bankRow(kind, raw);
        if (r.hdr) { cur = r.id; if (cnt[cur] === undefined) cnt[cur] = 0; return; }
        if (cur !== null && nkIsChecked(r)) cnt[cur]++;
    });
    return cnt;
}
/** 目前實際勾選的（跨篩選、跨標籤），回 {ids:[含有題目的章節標題列], real:非標題列的筆數} */
function nkPicked(){
    var kind = $('#nkKind').val(), ids = [], real = 0, hc = nkHdrPicked();
    BANK.forEach(function(raw){
        var r = bankRow(kind, raw);
        if (r.hdr) { if (hc[r.id] > 0) ids.push(r.id); return; }   // 這一章有題目才帶標題列
        if (nkIsChecked(r)) { ids.push(r.id); real++; }
    });
    return {ids:ids, real:real};
}
function renderBank(){
    var kind = $('#nkKind').val();
    var kw = $('#nkFilter').val().trim().toLowerCase();
    renderTaskPanel();
    var HDRCNT = nkHdrPicked();      // 每章勾了幾題（標題列上顯示）

    /* 先算出「哪幾列要顯示」：聚焦的標籤／部門 ∩ 關鍵字。
       2026-09-17 起標籤是篩選條件（以前是勾選條件），所以章節標題列底下一列都沒有時
       就不要印那個標題，否則畫面會剩下一排空章節。 */
    var rows = BANK.map(function(raw){ return bankRow(kind, raw); });
    var vis = [], lastHdr = -1, hdrHasChild = {};
    rows.forEach(function(r, i){
        if (r.hdr) { lastHdr = i; return; }
        var hay = (r.text+' '+r.sub+' '+(r.badges||[]).join(' ')+' '+(r.tags||[]).join(' ')).toLowerCase();
        if (kw && hay.indexOf(kw) < 0) return;
        if (!rowHitTask(r)) return;
        vis[i] = 1;
        if (lastHdr >= 0) hdrHasChild[lastHdr] = 1;
    });

    var h = '', shown = 0;
    rows.forEach(function(r, i){
        if (r.hdr) {
            if (!hdrHasChild[i]) return;
            var hn = HDRCNT[r.id] || 0;
            h += '<div class="hdr-row' + (hn ? ' on' : '') + '" data-h="' + r.id + '">' + esc(r.text)
               + '<span class="hdr-n">' + (hn ? ('本章已勾 ' + hn + ' 題') : '本章未勾選') + '</span></div>';
            return;
        }
        if (!vis[i]) return;
        shown++;
        var on = nkIsChecked(r);
        h += '<label'+(!on ? ' class="dim"' : '')+(r.tip ? ' title="'+esc(r.tip)+'"' : '')+'>'
           + '<input type="checkbox" class="bkChk" value="'+r.id+'"'+(on?' checked':'')+'> '+esc(r.text)
           + (r.sub ? '<span style="color:#a08356;font-size:12px;">　'+esc(r.sub)+'</span>' : '')
           + (r.badges||[]).map(function(t){ return '<span class="nk-task">'+esc(t)+'</span>'; }).join('')
           + '</label>';
    });
    var empty = NK_TASKS.length
        ? '這個標籤底下沒有符合的項目（標籤只是用來聚焦，已經選好的仍留在右側）'
        : '題庫沒有符合的項目';
    $('#nkPick').html(h || '<div class="ia-empty">'+empty+'</div>');
    renderSelBox();
    updateBankCount(shown);
}
/* 右側「已選擇」欄（2026-09-17 使用者要求）：列出這次真的要建進查檢表的項目。
   系統稽核紀錄表列「表單編號／名稱／對應部門」，AS 查檢表列「條文／掛在哪份文件」。
   這一欄的內容**與目前聚焦的標籤無關**——取消標籤不會讓已選的項目消失。 */
function renderSelBox(){
    var kind = $('#nkKind').val();
    var sel = [];
    BANK.forEach(function(raw){
        var r = bankRow(kind, raw);
        if (!r.hdr && nkIsChecked(r)) sel.push(r);
    });
    $('#nkSelHd').text(kind==='system' ? ('已選擇的表單（'+sel.length+'）')
                     : (kind==='as' ? ('已選擇的條文（'+sel.length+'）') : ('已選擇的指標（'+sel.length+'）')));
    $('#nkSelSub').text(sel.length ? '取消左側標籤不會影響這裡；按 × 可單筆取消。'
                                   : '從中間清單勾選，這裡就會列出來。');
    if (!sel.length) {
        $('#nkSelBody').html('<div style="font-size:12px;color:#a08356;padding:6px 2px;">尚未選擇任何項目。<br>'
            + '左側標籤只是把清單聚焦到該部門／作業項目，<b>不會自動勾選</b>；<br>'
            + '要整批勾請按「全選」，或勾上「點標籤時自動勾選底下的表單」。</div>');
        return;
    }
    $('#nkSelBody').html(sel.map(function(r){
        var main = (kind==='system')
            ? '<span class="no">'+esc(r.no||'')+'</span> '+esc(r.name||'')
            : esc(r.text||'');
        var sub = (kind==='system') ? (r.dept||'') : (r.sub||r.dept||'');
        return '<div class="nk-selrow"><span class="tx">'+main
             + (sub ? '<span class="dp">'+esc(sub)+'</span>' : '')
             + '</span><span class="x" data-uncheck="'+r.id+'" title="取消選取">×</span></div>';
    }).join(''));
}
$(document).on('click', '#nkSelBody .x', function(){
    NK_CHECKED[+$(this).data('uncheck')] = false;
    renderBank();
});
$('#nkSelClear').on('click', function(){ NK_CHECKED = {}; renderBank(); });
function updateBankCount(shown){
    var p = nkPicked();
    $('#nkCount').text('已勾 '+p.real+' 項'+(shown!=null?('／顯示 '+shown+' 列'):''));
}
/** 只更新章節標題列上的「本章已勾 N 題」（不整份重繪，否則捲動位置會彈回最上面） */
function updateHdrCounts(){
    var hc = nkHdrPicked();
    $('#nkPick .hdr-row').each(function(){
        var n = hc[+$(this).attr('data-h')] || 0;
        $(this).toggleClass('on', n > 0)
               .find('.hdr-n').text(n ? ('本章已勾 ' + n + ' 題') : '本章未勾選');
    });
}
$(document).on('change','.bkChk', function(){
    NK_CHECKED[+$(this).val()] = $(this).is(':checked');
    $(this).closest('label').toggleClass('dim', !$(this).is(':checked'));
    updateHdrCounts();
    renderSelBox();          // 右側「已選擇」要即時跟上，否則使用者看不出這一勾有沒有生效
    updateBankCount();
});
$('#nkFilter').on('input', renderBank);
/* 全選／全不選以「目前篩選出來的列」為準（看得到的才動），但記在 NK_CHECKED 而不是畫面上 */
$('#nkAll').on('click', function(){
    $('#nkPick .bkChk:not(.bkHdr)').each(function(){ NK_CHECKED[+$(this).val()] = true; });
    renderBank(); return false;
});
$('#nkNone').on('click', function(){
    $('#nkPick .bkChk:not(.bkHdr)').each(function(){ NK_CHECKED[+$(this).val()] = false; });
    renderBank(); return false;
});
$('#btnCheckCreate').on('click', function(){
    clearErrs($('#checkNewMask'));
    var kind = $('#nkKind').val(), ok = true;
    ok = fieldErr($('#nkDate'), 'errNkDate', $('#nkDate').val() ? '' : '請填建立（稽核）日期') && ok;
    // 篩選中被藏起來的項目仍然算數（否則使用者打了關鍵字就只會建出看得到的那幾題）
    var p = nkPicked(), picked = p.ids, real = p.real;
    if (!real) { $('#errNkPick').addClass('on').text('請至少勾選一個要查核的項目'); ok = false; }
    if (!ok) return;
    $.post(API, {action:'check_create', kind:kind, check_date:$('#nkDate').val(),
        auditor_key:$('#nkAuditor').val(), case_id:$('#nkCase').val(), title:$('#nkTitle').val(),
        src_check_id:(kind==='as' ? ($('#nkSrc').val()||'') : ''),
        pick:JSON.stringify(picked)}, function(res){
        if (!res.ok) { alert(res.error||'建立失敗'); return; }
        closeMask('checkNewMask');
        if (res.applied) {
            alert('已依系統稽核紀錄表自動判定：不合格 '+res.applied.ng+' 條、合格 '+res.applied.ok+' 條、'
                + '未判定 '+res.applied.skip+' 條（未判定＝這一條列的表單這次沒有查到，請自行填寫）。');
        }
        loadChecks(); openCheck(res.check_id);
    }, 'json');
});

/* ---- 查檢表填寫 ---- */
var CHK = null;
function openCheck(id){
    $.getJSON(API, {action:'check_get', check_id:id}, function(res){
        if (!res.ok) { alert(res.error||'載入失敗'); return; }
        CHK = res.row;
        $('#ckTitle').text(CHK.kind_label
            + (CHK.kind==='kpi' && CHK.audit_year ? '（稽核 '+CHK.audit_year+' 年度）' : '')
            + (CHK.half ? '（'+(CHK.half==='H1'?'上':'下')+'半年度）' : ''));
        /* 系統稽核紀錄表不另外取標題（2026-09-18 使用者要求）：
           自動顯示為對應稽核通知單的「第 N 次」，欄位改成唯讀。
           2026-09-21 使用者要求**只留次別、不要再接通知日期**——清單上本來就有「稽核日期」欄，
           標題再印一次日期只是把欄位撐寬，而且那個日期是通知日、跟旁邊的稽核日期還不一樣，看了更混亂。 */
        var autoTitle = (CHK.kind === 'system' && CHK.case_seq_no)
            ? ('第' + CHK.case_seq_no + '次') : '';
        $('#ckTitleInput').val(autoTitle || CHK.title || '')
                          .prop('readonly', !!autoTitle)
                          .css('background', autoTitle ? '#f5efe4' : '');
        $('#ckTitleAuto').toggle(!!autoTitle);
        $('#ckDate').val(inputDate(CHK.check_date));
        $('#ckStatus').val(CHK.status==='done' ? '已結案' : '填寫中');
        var ro = !CHK.can_edit;
        fillCkAuditor(CHK.check_date);
        /* 自動標題的欄位一律維持唯讀，不可被「這張表可以編輯」解鎖（否則上面那行 readonly 會被洗掉） */
        $('#ckDate').prop('readonly', ro);
        $('#ckTitleInput').prop('readonly', ro || !!autoTitle);
        $('#ckAuditor').prop('disabled', ro);
        $('#btnCheckSave,#btnCheckDone,#btnCheckAddItem').toggle(!ro);
        $('#btnCheckReopen').toggle(CHK.status==='done' && <?= $perms['canAdmin'] ? 'true' : 'false' ?>);
        renderCheckItems();
        renderCheckAutoBar(ro);
        openMask('checkMask');
    });
}
/* 稽核人下拉（2026-09-17 使用者要求：事後可改，原本只有建檔當下決定得了）。
   ①資格與職稱一律依**這張表的稽核日期**回推（ai-rules/22）——用今天判定的話，補歷史查檢表時
     「當時有資格、現在已離職或調職」的人一個都挑不到，而且完全不報錯。
   ②原本就掛在這張表上的那一位，即使現在已不在候選清單內也一定要留著，
     否則光是改個標題存檔就會把稽核人洗掉（後端同樣放行「沒有更動」的情況）。 */
function fillCkAuditor(dateStr){
    var curKey = postKeyOf(CHK.auditor_id, CHK.auditor_dept_id, CHK.auditor_position_id);
    peopleAsof(dateStr, function(){
        var h = postOptions(META.auditors, curKey, CHK.auditor_id, '（未指定）');
        if (CHK.auditor_id && h.indexOf('value="'+curKey+'"') < 0) {
            h = h.replace('</option>',
                '</option><option value="'+esc(curKey)+'" selected>'
                + esc((CHK.auditor_name||'') + '（原稽核人，目前已不在候選名單）') + '</option>');
        }
        $('#ckAuditor').html(h);
    });
}
$('#ckDate').on('change', function(){ fillCkAuditor($(this).val()); });
/* 受稽人不可以是稽核人本人（2026-09-21 使用者要求）：自己稽核自己等於沒有稽核。
   選到的當下就退回並說明，不要等到按儲存才報錯（後端同規則再擋一次＝鐵律8）。 */
function ckAuditorUid(){ return String($('#ckAuditor').val()||'').split(':')[0] || ''; }
/* 每一列「上一個合法的受稽人」。**不可以靠 focus 事件去記**——focus 不會冒泡，
   委派綁定在不同 jQuery 版本行為不一致（實測就是沒被觸發，被擋下之後值還留在錯的人身上）。
   改成渲染當下先記一份，之後每次合法變更再更新。 */
var CK_WHO_PREV = {};
$('#ckBody').on('change', '.ckWho', function(){
    var id = $(this).data('id'), au = ckAuditorUid(), v = String($(this).val()||'');
    if (!au || v.split(':')[0] !== au) { CK_WHO_PREV[id] = v; return; }
    alert('受稽人不可以是稽核人本人（' + ($('#ckAuditor option:selected').text() || '稽核人') + '）。\n'
        + '自己稽核自己等於沒有稽核，請改指派別人。');
    $(this).val(CK_WHO_PREV[id] === undefined ? '' : CK_WHO_PREV[id]);
});
/* 換稽核人時，若這張表已經有人被指派成他自己，一樣要擋下來 */
$('#ckAuditor').on('change', function(){
    var au = ckAuditorUid();
    if (!au) return;
    var hit = [];
    $('#ckBody .ckWho').each(function(){
        if (String($(this).val()||'').split(':')[0] === au) hit.push(1);
    });
    if (hit.length) {
        alert('這張表裡已經有 ' + hit.length + ' 列把這個人指派為受稽人，不能再把他設成稽核人。\n'
            + '請先改掉那幾列的受稽人。');
        fillCkAuditor($('#ckDate').val());
    }
});
var CK_HEADS = {
    as:     ['項次','品質管理系統要求','建立的文件、表單','合格','不合格','所見證據或建議','備註'],
    system: ['序號','表單編號','表單名稱','受稽人','合格','不合格','備註（內稽不符合通知單編號）'],
    kpi:    ['序','部門','內容','目標','受稽人','達成','沒達成','備註（異常矯正處理單編號）']
};
function renderCheckItems(){
    var k = CHK.kind, ro = !CHK.can_edit;
    $('#ckHead').html('<tr>'+CK_HEADS[k].map(function(t){ return '<th>'+esc(t)+'</th>'; }).join('')+'</tr>');
    var h = '', n = 0;
    (CHK.items||[]).forEach(function(it){
        if (+it.is_header===1) {
            h += '<tr class="hdr-row"><td colspan="'+CK_HEADS[k].length+'">'+esc(it.col_a)+'</td></tr>';
            return;
        }
        n++;
        var okChk = '<input type="radio" name="r'+it.item_id+'" class="ckR" data-id="'+it.item_id+'" value="ok"'
                  + (it.result==='ok'?' checked':'')+(ro?' disabled':'')+'>';
        var ngChk = '<input type="radio" name="r'+it.item_id+'" class="ckR" data-id="'+it.item_id+'" value="ng"'
                  + (it.result==='ng'?' checked':'')+(ro?' disabled':'')+'>';
        var remark = '<input type="text" class="ckF" data-id="'+it.item_id+'" data-f="remark" value="'+esc(it.remark||'')+'"'
                   + (ro?' readonly':'')+' style="width:100%;border:1px solid #D8BE93;border-radius:3px;padding:2px 4px;font-size:12px;">';
        // 績效沒達成開的是「異常矯正處理單」（紙本備註欄本來就印 CAR 單號），不是 IA 單
        var ncBtn = ncBtnHtml(it, ro);
        h += '<tr>';
        if (k==='as') {
            // 作業項目：條文原文看不出實務上在查什麼，這裡用小徽章補上（列印版不帶，維持紙本版面）
            var tks = (it.tasks||[]).map(function(t){ return '<span class="nk-task">'+esc(t.task_name)+'</span>'; }).join('');
            h += '<td>'+n+'</td><td class="l">'+esc(it.col_a)+(tks?('<div style="margin-top:2px;">'+tks+'</div>'):'')+'</td>'
              + '<td class="l" style="font-size:12px;color:#7a6444;">'+esc(it.col_b||'')+'</td>'
              + '<td>'+okChk+'</td><td>'+ngChk+'</td>'
              + '<td><input type="text" class="ckF" data-id="'+it.item_id+'" data-f="evidence" value="'+esc(it.evidence||'')+'"'
              + (ro?' readonly':'')+' style="width:100%;border:1px solid #D8BE93;border-radius:3px;padding:2px 4px;font-size:12px;"></td>'
              + '<td>'+remark+ncBtn+'</td>';
        } else if (k==='system') {
            h += '<td>'+n+'</td><td>'+esc(it.col_a)+'</td><td class="l">'+esc(it.col_b||'')+'</td>'
              + '<td><select class="ckF ckWho" data-id="'+it.item_id+'" data-f="auditee_key"'+(ro?' disabled':'')
              + ' data-eg-filter="輸入姓名或部門篩選…" style="width:100%;border:1px solid #D8BE93;border-radius:3px;font-size:12px;">'
              + escortOptions(it)+'</select></td>'
              + '<td>'+okChk+'</td><td>'+ngChk+'</td><td>'+remark+ncBtn+'</td>';
        } else {
            h += '<td>'+n+'</td><td>'+esc(it.col_a||'')+'</td><td class="l">'+esc(it.col_b||'')+'</td>'
              + '<td>'+esc(it.col_c||'')+'</td>'
              + '<td><select class="ckF" data-id="'+it.item_id+'" data-f="col_d"'+(ro?' disabled':'')
              + ' data-eg-filter="輸入姓名篩選…" style="width:100%;border:1px solid #D8BE93;border-radius:3px;font-size:12px;">'
              + nameOptions(it.col_d)+'</select></td>'
              + '<td>'+okChk+'</td><td>'+ngChk+'</td><td>'+remark+ncBtn+'</td>';
        }
        // 結案前可以刪除某一列（已開過不符合通知單／矯正單的不給刪，那張單會變孤兒）
        if (!ro && CAN_AUDIT && !it.nc_id && !it.car_id) {
            h = h.replace(/<\/td>$/, '<span class="ia-op danger" title="從這張查檢表刪除這一列"'
                + ' onclick="delCheckItem(' + it.item_id + ',\'' + esc(String(it.col_b || it.col_a || '')).replace(/'/g, '') + '\')">'
                + '<i class="fa fa-trash"></i></span></td>');
        }
        h += '</tr>';
    });
    $('#ckBody').html(h);
    // 記下每一列目前的受稽人，當作「選到稽核人本人被擋下時要還原成什麼」的基準
    CK_WHO_PREV = {};
    $('#ckBody .ckWho').each(function(){ CK_WHO_PREV[$(this).data('id')] = String($(this).val()||''); });
}
/* 點「不合格」當下就要看到「開不符合單」（2026-09-21 使用者回報：原本要按儲存、重開這張表才會出現）。
   刻意不整張重畫——備註、所見證據、受稽人那幾欄很可能已經打了字還沒存，重畫就會全部被洗掉；
   這裡只把該列最後一格的開單鈕換掉，並同步上方那條統計列。 */
$('#ckBody').on('change', '.ckR', function(){
    var id = +$(this).data('id'), val = String($(this).val()||'');
    var it = null;
    (CHK.items||[]).forEach(function(x){ if (+x.item_id === id) it = x; });
    if (!it) return;
    it.result = val;
    var $cell = $(this).closest('tr').find('td').last();
    $cell.find('.ia-op').remove();
    var btn = ncBtnHtml(it, !CHK.can_edit);
    if (btn) $cell.append(btn);
    renderCheckAutoBar(!CHK.can_edit);
});
/* 這一列的最後一格該放什麼（已開的單號／開單鈕／什麼都不放）。
   renderCheckItems() 與上面的即時切換共用同一份規則，否則兩邊遲早長得不一樣。 */
function ncBtnHtml(it, ro){
    var k = CHK.kind;
    if (k === 'kpi') {
        if (it.car_id) return '<span class="ia-op" onclick="openCar('+it.car_id+')">'+esc(it.car_no||'矯正單')+'</span>';
        if (it.result==='ng' && !ro && CAN_AUDIT)
            return '<span class="ia-op" onclick="carFromItem('+it.item_id+')"><i class="fa fa-plus"></i> 開矯正單</span>';
        return '';
    }
    if (it.nc_id) return '<span class="ia-op" onclick="openNc('+it.nc_id+')">'+esc(it.nc_no||'IA單')+'</span>';
    if (it.result==='ng' && !ro && CAN_AUDIT)
        return '<span class="ia-op" onclick="newNcFromItem('+it.item_id+')"><i class="fa fa-plus"></i> 開不符合單</span>';
    return '';
}
/* 刪除這一列（2026-09-21 使用者要求：建好之後才發現多了一列，原本完全刪不掉）。
   已開過單的那一列不給刪（刪了那張 IA 單會變孤兒），已結案的整張表都不給改。 */
function delCheckItem(itemId, label){
    if (!confirm('要從這張查檢表刪除「'+(label||('項目 '+itemId))+'」這一列嗎？\n刪除後已填的判定與備註也會一起消失。')) return;
    $.post(API, {action:'check_item_del', check_id:CHK.check_id, item_id:itemId}, function(res){
        if (!res.ok) { alert(res.error||'刪除失敗'); return; }
        openCheck(CHK.check_id); loadChecks();
    }, 'json');
}
/* 「可以自動建立的就自動建立，人工才要填的再提醒管理員」（2026-09-14 使用者要求）。
   這條提示列統計這張表還有幾件可以一鍵做掉、又有哪幾件一定要人工填。 */
function renderCheckAutoBar(ro){
    var k = CHK.kind, items = (CHK.items||[]).filter(function(x){ return +x.is_header!==1; });
    var ng = items.filter(function(x){ return x.result==='ng'; });
    var pending = ng.filter(function(x){ return k==='kpi' ? !x.car_id : !x.nc_id; });
    var todo = items.filter(function(x){ return !x.result; });
    var manual = [];
    if (k==='system') manual = items.filter(function(x){ return !x.col_c; });      // 受稽人沒填
    if (k==='kpi')    manual = items.filter(function(x){ return !x.col_d || !x.result; });
    var msg = [];
    if (pending.length) msg.push('<b style="color:#C4442D;">有 '+pending.length+' 項判定為'
        + (k==='kpi'?'沒達成但還沒開矯正單':'不合格但還沒開不符合通知單')+'</b>，可按下方「一鍵開立」一次處理。');
    if (todo.length)   msg.push('還有 '+todo.length+' 項沒有判定。');
    if (manual.length) msg.push('有 '+manual.length+' 項需要人工確認（'
        + (k==='system' ? '受稽人未填' : 'KPI 沒有實績資料或未設定擔當者') + '）。');
    if (k==='kpi' && CHK.audit_year) msg.unshift('本表自動判定的是 <b>'+CHK.audit_year
        + ' 年度</b>的 KPI 實績：該年度只要有任一次未達標就算「沒達成」。');
    $('#ckAutoBar').toggle(msg.length>0).html(msg.join('<br>'));
    $('#btnCheckAuto').toggle(!ro && pending.length>0 && k!=='as')
        .html('<i class="fa fa-magic"></i> 一鍵開立'+(k==='kpi'?'矯正單':'不符合通知單')+'（'+pending.length+'）');
}
/* ============================ 查檢表：補加項目（2026-09-21 使用者要求） ============================
   題庫與建立查檢表時用的是**同一支 check_bank**，不另外刻一份挑題邏輯；
   這張表已經有的（ref_kind+ref_id）在前端先濾掉，後端再擋一次重複（鐵律8）。 */
var CK_ADD_ROWS = [];
$('#btnCheckAddItem').on('click', function(){
    if (!CHK) return;
    var year = (CHK.kind==='kpi') ? '' : CHK.year;
    $('#ckAddList').html('<div style="color:#a08356;">載入題庫中…</div>');
    $('#ckAddKw').val(''); openMask('ckAddMask');
    $.getJSON(API, {action:'check_bank', kind:CHK.kind, year:year || String(CHK.check_date||'').slice(0,4)}, function(res){
        if (!res.ok) { $('#ckAddList').html('<div style="color:#C4442D;">'+esc(res.error||'載入失敗')+'</div>'); return; }
        // 這張表已經有的就不要再列出來
        var have = {};
        (CHK.items||[]).forEach(function(x){ have[(x.ref_kind||'')+':'+(+x.ref_id||0)] = 1; });
        CK_ADD_ROWS = (res.rows||[]).filter(function(r){
            if (+r.is_header === 1) return false;                       // 章節標題列不給單獨加
            return !have[ckBankRefKind()+':'+(+ckBankId(r))];
        });
        renderCkAdd();
    });
});
/* 題庫每一種的 id 欄位與 ref_kind 名稱不同，收斂在這兩支，不要散在各處 */
function ckBankRefKind(){
    // 必須與 ia_check_build_items() 寫進 ia_check_item.ref_kind 的值一字不差，
    // 不然「這張表已經有的」會濾不掉，補加時就會出現兩列一模一樣的項目
    return CHK.kind==='as' ? 'as_clause' : (CHK.kind==='system' ? 'as_document' : 'kpi_indicator');
}
function ckBankId(r){
    return CHK.kind==='as' ? r.clause_id : (CHK.kind==='system' ? r.id : r.indicator_id);
}
function ckBankLabel(r){
    if (CHK.kind==='as')     return (r.clause_text||'');
    if (CHK.kind==='system') return (r.doc_no||'') + '　' + (r.doc_name||'');
    return (r.dept_name||'') + '　' + (r.name||'');
}
function renderCkAdd(){
    var kw = String($('#ckAddKw').val()||'').trim().toLowerCase();
    var shown = 0;
    var h = CK_ADD_ROWS.map(function(r){
        var lab = ckBankLabel(r), id = +ckBankId(r);
        if (kw && lab.toLowerCase().indexOf(kw) < 0) return '';
        shown++;
        return '<label style="display:block;font-weight:normal;margin:2px 0;cursor:pointer;">'
             + '<input type="checkbox" class="ckAddChk" data-eg-skip value="'+id+'" style="vertical-align:-1px;"> '
             + esc(lab) + '</label>';
    }).join('');
    $('#ckAddList').html(h || '<div style="color:#a08356;">沒有可以加入的項目（題庫裡的項目這張表都已經有了）</div>');
    $('#ckAddCnt').text('可加入 '+CK_ADD_ROWS.length+' 項'+(kw ? ('，符合「'+kw+'」'+shown+' 項') : ''));
}
$('#ckAddKw').on('input', renderCkAdd);
$('#ckAddAll').on('click', function(){ $('#ckAddList .ckAddChk').prop('checked', true); });
$('#ckAddNone').on('click', function(){ $('#ckAddList .ckAddChk').prop('checked', false); });
$('#btnCkAddGo').on('click', function(){
    var pick = $('#ckAddList .ckAddChk:checked').map(function(){ return +this.value; }).get();
    if (!pick.length) { alert('請至少勾選一個要加入的項目'); return; }
    $.post(API, {action:'check_item_add', check_id:CHK.check_id, pick:JSON.stringify(pick)}, function(res){
        if (!res.ok) { alert(res.error||'加入失敗'); return; }
        closeMask('ckAddMask');
        alert('已加入 '+res.added+' 項'+(+res.skipped ? ('（略過 '+res.skipped+' 項已存在）') : ''));
        openCheck(CHK.check_id); loadChecks();
    }, 'json');
});

/* 一鍵開立：不合格／沒達成的列一次全部開單。
   不符合通知單的「不合格類型」是人工判斷，所以先問一次要用哪一種，其餘欄位（受稽單位、受稽人、
   相關表單編號、違反條文、事實描述）全部自動帶好；開完直接回到查檢表可逐張再修。 */
$('#btnCheckAuto').on('click', function(){
    var k = CHK.kind;
    var items = (CHK.items||[]).filter(function(x){
        return +x.is_header!==1 && x.result==='ng' && (k==='kpi' ? !x.car_id : !x.nc_id);
    });
    if (!items.length) { alert('沒有需要開單的項目'); return; }
    if (k==='kpi') {
        if (!confirm('要為 '+items.length+' 項「沒達成」各開立一張異常矯正處理單嗎？\n'
                   + '單號會自動寫回備註欄，並通知該單位擔當者說明原因。')) return;
        var okN = 0, errs = [];
        (function next(i){
            if (i >= items.length) {
                alert('完成：已開立 '+okN+' 張矯正單'+(errs.length?('\n失敗 '+errs.length+' 張：\n'+errs.join('\n')):''));
                openCheck(CHK.check_id); loadChecks(); return;
            }
            $.post(API, {action:'car_from_item', item_id:items[i].item_id}, function(res){
                if (res.ok) okN++; else errs.push((items[i].col_b||'')+'：'+(res.error||'失敗'));
                next(i+1);
            }, 'json').fail(function(){ errs.push((items[i].col_b||'')+'：連線失敗'); next(i+1); });
        })(0);
        return;
    }
    // system：一律先問不合格類型（紙本上這欄是稽核員判斷的，不可以替他決定）。
    // 2026-09-17 改成正式跳窗：原本用瀏覽器 prompt() 要使用者「輸入數字」，
    // 打錯一個字整批就取消，而且看不到這次到底要為哪幾項開單。
    CK_BULK = items;
    var types = META.nc_types || {};
    var th = '';
    $.each(types, function(k2, v){ th += '<option value="'+esc(k2)+'">'+esc(v)+'</option>'; });
    $('#ckBulkType').html(th);
    clearErrs($('#ckBulkMask'));
    $('#ckBulkHint').html('要為 <b>'+items.length+'</b> 項判定不合格的查核項目，各開立一張內稽不符合通知單。<br>'
        + '受稽單位、受稽人、相關表單編號、違反條文與不合格事實都會自動帶入，開完可逐張修改。');
    $('#ckBulkList').html(items.map(function(it, i){
        return '<tr><td>'+(i+1)+'</td><td class="l">'+esc((it.col_a||'')+' '+(it.col_b||''))+'</td>'
             + '<td>'+esc(it.col_c||'（未填）')+'</td></tr>';
    }).join(''));
    openMask('ckBulkMask');
});
/* 這批要開的項目（由「一鍵開立」按鈕帶進跳窗） */
var CK_BULK = [];
$('#btnCkBulkGo').on('click', function(){
    var type = $('#ckBulkType').val() || '';
    if (!type) { $('#ckBulkTypeErr').addClass('on').text('請選擇不合格類型'); $('#ckBulkType').addClass('err'); return; }
    var items = CK_BULK || [];
    if (!items.length) { closeMask('ckBulkMask'); return; }
    var $btn = $(this), btnHtml = $btn.html();
    $btn.prop('disabled', true);                         // 逐張送出時擋住重複點擊
    var okN2 = 0, errs2 = [];
    (function next(i){
        if (i >= items.length) {
            $btn.prop('disabled', false).html(btnHtml);
            closeMask('ckBulkMask');
            alert('完成：已開立 '+okN2+' 張不符合通知單'+(errs2.length?('\n失敗 '+errs2.length+' 張：\n'+errs2.join('\n')):''));
            openCheck(CHK.check_id); loadChecks(); loadNcs(); return;
        }
        $btn.text('開立中… '+(i+1)+'／'+items.length);
        var pre = ncPrefillFromItem(items[i]);
        $.post(API, {action:'nc_create', audit_date:pre.audit_date, case_id:pre.case_id,
            dept_id:(pre.dept_id || guessDeptId(pre)), auditee_id:(pre.auditee_id || guessUserId(pre.auditee_name)),
            fact:pre.fact || ((items[i].col_a||'')+' '+(items[i].col_b||'')+' 稽核不合格'),
            nc_type:type, clause_ref:pre.clause_ref, ref_form_no:pre.ref_form_no,
            ref_form_name:pre.ref_form_name || '', due_date:pre.due_date || '',
            src_kind:pre.src_kind, src_item_id:pre.src_item_id}, function(res){
            if (res.ok) okN2++; else errs2.push((items[i].col_a||'')+'：'+(res.error||'失敗'));
            next(i+1);
        }, 'json').fail(function(){ errs2.push((items[i].col_a||'')+'：連線失敗'); next(i+1); });
    })(0);
});

/* 受稽人欄位存的是姓名字串（紙本就是簽人名），用人員清單當下拉但存名字 */
function nameOptions(cur){
    var h = '<option value="">（未指定）</option>', found=false;
    (META.people||[]).forEach(function(p){
        var s = (String(cur||'')===String(p.user_cname));
        if (s) found = true;
        h += '<option value="'+esc(p.user_cname)+'"'+(s?' selected':'')+'>'+esc(p.user_cname)+'</option>';
    });
    if (cur && !found) h += '<option value="'+esc(cur)+'" selected>'+esc(cur)+'（已不在名單）</option>';
    return h;
}
/* 系統稽核紀錄表的「受稽人」＝**這張通知單上的陪檢員**（2026-09-18 使用者要求）。
   ①預設帶該份文件所屬部門的陪檢員（文件編號第二段的部門代碼 → 受稽單位）
   ②可以改成別的部門的陪檢員
   ③清單一律顯示「部門　職稱　姓名」，**兼任也要看得出來**（ai-rules/08 第五節的欄位順序）
   值是職務鍵 uid:deptId:posId，存檔時一併存 user_id，開 IA 單就不必再用姓名去猜人。 */
function escortOptions(it){
    var list = (CHK.escorts || []), cur = String(it.auditee_id || '') ;
    var curKey = it.auditee_id ? postKeyOf(it.auditee_id, it.auditee_dept_id, it.auditee_position_id) : '';
    var h = '<option value="">（未指定）</option>';
    if (!list.length) return h + (it.col_c ? '<option value="" selected>'+esc(it.col_c)+'（這張通知單沒有陪檢員名單）</option>' : '');
    var pickedKey = curKey;
    if (!pickedKey) {
        // 預設：這份文件所屬部門的陪檢員（對不到就不預設，讓使用者自己挑）
        for (var i = 0; i < list.length && !pickedKey; i++) {
            if (!list[i].unit_name) continue;        // 預設只從「這張通知單上的陪檢員」挑
            if (it.dept_name && String(list[i].unit_name) === String(it.dept_name)) {
                pickedKey = postKeyOf(list[i].user_id, list[i].dept_id, list[i].position_id);
            }
        }
        // 舊資料只有姓名時，用姓名對回去
        for (var j = 0; j < list.length && !pickedKey; j++) {
            if (it.col_c && String(list[j].user_name) === String(it.col_c)) {
                pickedKey = postKeyOf(list[j].user_id, list[j].dept_id, list[j].position_id);
            }
        }
    }
    /* 只分兩組（2026-09-18 使用者要求）：**稽核人員（含稽核組長）／陪檢員**——
       原本還另外拆出「稽核組長」與「本單陪檢員」兩組，同一個人會在選單裡出現好幾個地方。
       「本單陪檢員」不另立一組，但仍靠 unit_name 認得出來，預設值只從他們裡面挑。 */
    var seen = {}, groups = {};
    list.forEach(function(p){
        var k = postKeyOf(p.user_id, p.dept_id, p.position_id);
        if (seen[k]) return; seen[k] = 1;
        var g = p.group || '本單陪檢員';
        (groups[g] || (groups[g] = [])).push({k: k, p: p});
    });
    ['稽核人員', '陪檢員'].concat(Object.keys(groups)).forEach(function(g){
        if (!groups[g]) return;
        var rows = groups[g]; delete groups[g];
        h += '<optgroup label="'+esc(g)+'">';
        rows.forEach(function(x){
            var p = x.p;
            var label = (p.dept_name ? p.dept_name + '　' : '') + (p.position_name ? p.position_name + '　' : '')
                      + p.user_name + (+p.is_main === 0 ? '（兼任）' : '')
                      + (p.unit_name ? ('　〔' + p.unit_name + '〕') : '');
            h += '<option value="'+esc(x.k)+'"'+(x.k === pickedKey ? ' selected' : '')+'>'+esc(label)+'</option>';
        });
        h += '</optgroup>';
    });
    if (it.col_c && !pickedKey) h += '<option value="" selected>'+esc(it.col_c)+'（不在陪檢員名單內）</option>';
    return h;
}
function collectCheckItems(){
    var map = {};
    $('#ckBody .ckF').each(function(){
        var id = $(this).data('id');
        map[id] = map[id] || {item_id:id};
        map[id][$(this).data('f')] = $(this).val();
    });
    /* 受稽人下拉的值是職務鍵，但**姓名也要一起存**（col_c）——列印與報告表都是印姓名，
       只存 id 的話那些地方會變空白。 */
    $('#ckBody .ckWho').each(function(){
        var id = $(this).data('id'), key = String($(this).val()||''), nm = '';
        (CHK.escorts||[]).forEach(function(p){
            if (postKeyOf(p.user_id, p.dept_id, p.position_id) === key) nm = p.user_name;
        });
        map[id] = map[id] || {item_id:id};
        map[id].col_c = nm;
    });
    $('#ckBody .ckR:checked').each(function(){
        var id = $(this).data('id');
        map[id] = map[id] || {item_id:id};
        map[id].result = $(this).val();
    });
    return Object.keys(map).map(function(k){ return map[k]; });
}
$('#btnCheckSave').on('click', function(){ saveCheck(false); });
function saveCheck(silent, cb){
    $.post(API, {action:'check_save_items', check_id:CHK.check_id, title:$('#ckTitleInput').val(),
        check_date:$('#ckDate').val(), auditor_key:($('#ckAuditor').val()||''),
        items:JSON.stringify(collectCheckItems())}, function(res){
        if (!res.ok) { alert(res.error||'儲存失敗'); return; }
        if (!silent) alert('已儲存' + (+res.resorted ? '（已依受稽人部門、表單編號重新排序）' : ''));
        loadChecks();
        // 順序是後端排的，重新載入這張表才看得到新的排列
        if (+res.resorted && !cb) { openCheck(CHK.check_id); return; }
        if (cb) cb();
    }, 'json');
}
$('#btnCheckDone').on('click', function(){
    saveCheck(true, function(){
        $.post(API, {action:'check_done', check_id:CHK.check_id, status:'done'}, function(res){
            if (!res.ok) { alert(res.error||'無法結案'); return; }
            alert('已結案'); closeMask('checkMask'); loadChecks();
        }, 'json');
    });
});
$('#btnCheckReopen').on('click', function(){
    if (!confirm('取消結案後這張查檢表可以再修改，確定？')) return;
    $.post(API, {action:'check_done', check_id:CHK.check_id, status:'draft'}, function(res){
        if (!res.ok) { alert(res.error||'失敗'); return; }
        openCheck(CHK.check_id); loadChecks();
    }, 'json');
});
/* 績效「沒達成」→ 自動開立異常矯正處理單（CAR），單號自動寫回備註欄（2026-09-15 使用者交辦）。
   單上會自動帶出：稽核的是哪個年度、哪幾個月沒達成、當時的數值與 KPI 目標，
   並固定附上「請說明原因及確認是否需要調整KPI目標?」。 */
function carFromItem(itemId){
    var it = null;
    (CHK.items||[]).forEach(function(x){ if (+x.item_id===+itemId) it = x; });
    if (!it) return;
    if (!confirm('要為「'+(it.col_b||'')+'」開立異常矯正處理單嗎？\n'
               + '責任單位：'+(it.col_a||'—')+'　回覆人：'+(it.col_d||'（未設定擔當者，將通知該單位主管指派）'))) return;
    saveCheck(true, function(){
        $.post(API, {action:'car_from_item', item_id:itemId}, function(res){
            if (!res.ok) { alert(res.error||'開立失敗'); return; }
            alert('已開立異常矯正處理單 '+res.car_no+'，單號已寫入備註欄。');
            openCheck(CHK.check_id); loadChecks();
        }, 'json');
    });
}
function openCar(carId){ window.open('../QA/correction_order.php?open_id='+carId, '_blank'); }

/* 由查檢表的不合格列直接開不符合通知單，欄位自動帶好 */
function newNcFromItem(itemId){
    var it = null;
    (CHK.items||[]).forEach(function(x){ if (+x.item_id===+itemId) it = x; });
    if (!it) return;
    saveCheck(true, function(){
        openNcNew(ncPrefillFromItem(it));
    });
}
/* 一列不合格 → 不符合通知單要帶的欄位。
   系統稽核紀錄表的「違反條文」＝該表單對應到的品質管理系統要求（由條文題庫的「建立的文件、表單」
   反查，後端 check_get 已經算好帶在 it.clause_ref；2026-09-15 使用者交辦）。 */
/* 開不符合通知單時要自動帶的東西（2026-09-18 使用者要求）：
   受稽核單位、受稽核人、相關表單編號**與名稱快照**、稽核日期、所屬件號、要求完成期限。
   單位與人都還是可以在開單畫面手動改；期限取該受稽單位的「預定完成改善」。 */
function ncPrefillFromItem(it){
    var sysNo = CHK.kind==='system' ? (it.col_a||'') : '';
    var dept  = CHK.kind==='kpi' ? (it.col_a||'') : (it.dept_name||'');
    return {
        case_id: CHK.case_id || '',
        audit_date: inputDate(CHK.check_date),
        src_kind: CHK.kind,
        src_item_id: it.item_id,
        ref_form_no: sysNo,
        ref_form_name: CHK.kind==='system' ? (it.col_b||'') : '',
        clause_ref: CHK.kind==='as' ? (it.col_a||'') : (it.clause_ref||''),
        clauses: it.clauses || [],                 // 綁定這份文件的條文，開單畫面列出來勾
        fact: sysNo ? (sysNo+' '+(it.col_b||'')+' 稽核不合格' + (it.evidence ? ('：'+it.evidence) : '')) : (it.evidence||''),
        auditee_name: CHK.kind==='system' ? (it.col_c||'') : (CHK.kind==='kpi' ? (it.col_d||'') : ''),
        auditee_id: (CHK.kind==='system' ? (it.auditee_id||'') : ''),
        dept_hint: dept,
        dept_id: ((CHK.dept_id_by_name||{})[dept] || ''),
        due_date: ((CHK.due_by_dept||{})[dept] || '')
    };
}
</script>
<script>
/* ============================ 不符合通知單 2-GM-06-07 ============================ */
function loadNcs(){
    $.getJSON(API, {action:'nc_list', year:YEAR, stage:$('#ncStage').val(),
                    overdue:$('#ncOverdue').is(':checked')?1:'', kw:$('#ncKw').val()}, function(res){
        if (!res.ok) return;
        LIST.nc = res.rows||[]; renderNcs();
    });
}
$('#btnNcSearch').on('click', function(){ PAGE.nc=1; loadNcs(); });
$('#ncStage').on('change', function(){ PAGE.nc=1; loadNcs(); });
$('#ncOverdue').on('change', function(){ PAGE.nc=1; loadNcs(); });
$('#ncKw').on('keydown', function(e){ if(e.which===13){ PAGE.nc=1; loadNcs(); } });

function renderNcs(){
    $('#ncPager').html(renderPager('nc', LIST.nc.length));
    var rows = pageSlice('nc');
    if (!rows.length) { $('#ncBody').html('<tr><td colspan="9" class="ia-empty">沒有符合條件的不符合通知單</td></tr>'); return; }
    var h = '';
    rows.forEach(function(r){
        h += '<tr>'
          + '<td>'+esc(r.nc_no||'')+'</td>'
          + '<td>'+esc(r.dept_name||'')+'</td>'
          + '<td>'+esc(r.auditee_name||'—')+'</td>'
          + '<td>'+dispDate(r.audit_date)+'</td>'
          + '<td><span class="st st-'+esc(r.nc_type||'observe')+'">'+esc(r.type_label||'')+'</span></td>'
          + '<td>'+esc(r.ref_form_no||'—')+'</td>'
          + '<td>'+(r.due_date ? dispDate(r.due_date) : '—')
          + (+r.overdue ? ' <span class="st st-overdue">逾期</span>' : '')+'</td>'
          + '<td><span class="st st-'+esc(r.stage)+'">'+esc(r.stage_label||'')+'</span></td>'
          + '<td><span class="ia-op" onclick="openNc('+r.nc_id+')"><i class="fa fa-edit"></i> 開啟</span>'
          + '<span class="ia-op" onclick="printNc('+r.nc_id+')"><i class="fa fa-print"></i></span>'
          + (IS_ADMIN ? '<span class="ia-op danger" onclick="delNcRow('+r.nc_id+',\''+esc(r.nc_no||'').replace(/\'/g,'')+'\')" title="刪除這張不符合通知單"><i class="fa fa-trash"></i></span>' : '')
          + '</td></tr>';
    });
    $('#ncBody').html(h);
}

/* ---- 開立 ---- */
$('#btnNcNew').on('click', function(){ openNcNew({}); });
/* 開立不符合通知單（2026-09-18 使用者回報「跳窗上的下拉點不動，過一下又好了」）。
   根因：這個跳窗的受稽核單位／受稽核人是**直接拿 META 當下的清單**畫出來的，
   而 META.people／META.depts 會被別處的 people_asof 非同步換掉——換的那一瞬間清單是空的，
   於是開出來的下拉「看起來正常、點下去什麼都不會發生」（空的 select 不會展開），
   等下一次重畫才又正常。而且那份清單是「今天」的，本來就該依**這張單的稽核日期**回推（ai-rules/22）。
   修法：跳窗先開起來、四個下拉一律先顯示「載入中…」並停用，人員清單到齊才真正畫選項。 */
function openNcNew(pre){
    // 開立畫面要用到案件清單當下拉；不在稽核通知單分頁時清單可能還沒載入過
    if (!CASES.length) { loadCases(function(){ openNcNew(pre); }); return; }
    ['#nnCase', '#nnDept', '#nnAuditee', '#nnType'].forEach(function(sel){
        $(sel).html('<option value="">載入中…</option>').prop('disabled', true);
    });
    clearErrs($('#ncNewMask'));
    openMask('ncNewMask');
    // 人員與職稱依這張單的稽核日期回推，回來才畫（清單沒到齊就畫＝空下拉）
    peopleAsof(pre.audit_date || META.today, function(){ openNcNewFill(pre); });
}
function openNcNewFill(pre){
    $('#nnCase,#nnDept,#nnAuditee,#nnType').prop('disabled', false);
    var typeH = '<option value="">（請選擇）</option>';
    $.each(META.nc_types||{}, function(k,v){ typeH += '<option value="'+k+'">'+esc(v)+'</option>'; });
    $('#nnType').html(typeH); $('#nnCase').html(caseOptions(pre.case_id||''));
    $('#nnDate').val(pre.audit_date || META.today);
    $('#nnFact').val(pre.fact||''); $('#nnClause').val(pre.clause_ref||'');
    $('#nnFormNo').val(pre.ref_form_no||'');
    // 要求完成期限＝該受稽單位在稽核通知單上填的「預定完成改善」（2026-09-18 使用者要求）
    $('#nnDue').val(inputDate(pre.due_date||''));
    // 受稽核單位：優先用查檢表帶來的部門 id，沒有才用名稱去猜
    $('#nnDept').html(deptOptions(pre.dept_id || guessDeptId(pre), '（請選擇）'));
    // 受稽核人：只列該受稽核單位的主管（2026-09-21 使用者要求），單位或日期一改就重抓
    nnLoadAuditee(pre.auditee_id || guessUserId(pre.auditee_name), pre.auditee_name || '');
    ncClausePick(pre.clauses || []);
    $('#btnNcCreate').data('src', JSON.stringify({src_kind:pre.src_kind||'', src_item_id:pre.src_item_id||'',
                                                 ref_form_name:pre.ref_form_name||''}));
    /* 打字篩選框的選項快照是在 select 被畫出來當下取的，這裡整批換過選項後要它重取，
       否則使用者一打字就會被舊快照（多半是「載入中…」那一筆）洗掉。 */
    ['nnDept', 'nnAuditee'].forEach(function(id){
        var el = document.getElementById(id);
        if (el && typeof el.egFilterResnap === 'function') el.egFilterResnap();
    });
}
/* 受稽核人候選＝這個受稽核單位在稽核日期當時的主管。
   換單位或換日期都要重抓；抓的期間先停用下拉並寫「載入中…」，
   不然會出現「看起來有選項、點下去卻是上一個單位的人」。 */
function nnLoadAuditee(curId, curName){
    var dept = $('#nnDept').val() || '', date = $('#nnDate').val() || META.today;
    if (!dept) {
        $('#nnAuditee').html('<option value="">（請先選擇受稽核單位）</option>').prop('disabled', true);
        nnAuditeeResnap(); return;
    }
    $('#nnAuditee').html('<option value="">載入中…</option>').prop('disabled', true);
    $.getJSON(API, {action:'nc_cands', dept_id:dept, date:date}, function(res){
        var list = (res && res.ok) ? (res.auditee||[]) : [];
        var keep = '', want = String(curId||'');
        list.forEach(function(p){ if (String(p.id) === want) keep = want; });
        $('#nnAuditee').html(candOptions(list, keep || (want && curName ? want : ''), curName))
                       .prop('disabled', false);
        nnAuditeeResnap();
    }).fail(function(){
        $('#nnAuditee').html('<option value="">（載入失敗，請重開這個跳窗）</option>').prop('disabled', false);
        nnAuditeeResnap();
    });
}
function nnAuditeeResnap(){
    var el = document.getElementById('nnAuditee');
    if (el && typeof el.egFilterResnap === 'function') el.egFilterResnap();
}
$('#nnDept').on('change', function(){ nnLoadAuditee('', ''); });
$('#nnDate').on('change', function(){ nnLoadAuditee($('#nnAuditee').val()||'', ''); });
/* 違反條文：把「綁定這份 AS 文件的條文」列出來勾，勾了就加進文字框（也可以自己打字改）。
   清單為空時整區不顯示（不是每張表單都在題庫裡對得到條文）。 */
function ncClausePick(list){
    var $box = $('#nnClausePick');
    if (!list || !list.length) { $box.hide().empty(); return; }
    $box.show().html('<div style="color:#8a6d45;margin-bottom:3px;">這份表單對應到的品質管理系統要求（勾選就會加進下方欄位）：</div>'
        + list.map(function(c, i){
              return '<label style="display:block;font-weight:normal;margin:1px 0;cursor:pointer;">'
                   + '<input type="checkbox" class="nnCl" data-t="'+esc(c.clause_text||'')+'" data-eg-skip'
                   + (i === 0 ? ' checked' : '') + ' style="vertical-align:-1px;"> ' + esc(c.clause_text||'') + '</label>';
          }).join(''));
    ncClauseSync();
}
function ncClauseSync(){
    var t = [];
    $('#nnClausePick .nnCl:checked').each(function(){ var x = String($(this).data('t')||''); if (x && t.indexOf(x)<0) t.push(x); });
    if (t.length) $('#nnClause').val(t.join('\n'));
}
$(document).on('change', '#nnClausePick .nnCl', ncClauseSync);

function guessDeptId(pre){
    // 部門代碼同名時 ia_as_dept_code_names() 會在後面標代碼（例「生管組（PD）」），比對前要去掉
    var name = String(pre.dept_hint||'').trim().replace(/（[A-Za-z]{2,3}）$/, '');
    if (name) {
        var hit = 0;
        (META.depts||[]).forEach(function(d){ if (!hit && d.name===name) hit = d.id; });
        if (hit) return hit;
    }
    return '';
}
function guessUserId(name){
    if (!name) return '';
    var hit = '';
    (META.people||[]).forEach(function(p){ if (!hit && p.user_cname===name) hit = p.id; });
    return hit;
}
$('#btnNcCreate').on('click', function(){
    clearErrs($('#ncNewMask'));
    var ok = true;
    ok = fieldErr($('#nnDate'), 'errNnDate', $('#nnDate').val() ? '' : '請填稽核日期') && ok;
    ok = fieldErr($('#nnDept'), 'errNnDept', $('#nnDept').val() ? '' : '請選擇受稽核單位') && ok;
    ok = fieldErr($('#nnFact'), 'errNnFact', $('#nnFact').val().trim() ? '' : '請填不合格事實描述') && ok;
    ok = fieldErr($('#nnType'), 'errNnType', $('#nnType').val() ? '' : '請選擇不合格類型') && ok;
    var d = $('#nnDate').val(), due = $('#nnDue').val();
    if (due && d && due < d) ok = fieldErr($('#nnDue'), 'errNnDue', '要求完成期限不可早於稽核日期') && ok;
    if (!ok) return;
    var src = {}; try { src = JSON.parse($(this).data('src')||'{}'); } catch(e) {}
    $.post(API, $.extend({action:'nc_create', audit_date:d, case_id:$('#nnCase').val(),
        dept_id:$('#nnDept').val(), auditee_id:$('#nnAuditee').val(), fact:$('#nnFact').val(),
        nc_type:$('#nnType').val(), clause_ref:$('#nnClause').val(), ref_form_no:$('#nnFormNo').val(),
        due_date:due}, src), function(res){
        if (!res.ok) { alert(res.error||'建立失敗'); return; }
        closeMask('ncNewMask');
        alert('已開立 '+res.nc_no+'，並已通知受稽核單位主管。');
        if (CHK) openCheck(CHK.check_id);
        loadNcs();
        openNc(res.nc_id);
    }, 'json');
});

/* ---- 四段填寫 ---- */
var NC = null;
function openNc(id){
    $.getJSON(API, {action:'nc_get', nc_id:id}, function(res){
        if (!res.ok) { alert(res.error||'載入失敗'); return; }
        NC = res.row;
        var p = NC.perm;
        $('#ncTitle').text('內稽不符合通知單　'+(NC.nc_no||'')+'　'+(NC.stage_label||'')
                           + (+NC.overdue ? '（已逾期）' : ''));
        // 段一
        $('#nNo').val(NC.nc_no||''); $('#nAuditDate').val(dispDate(NC.audit_date));
        $('#nDept').val(NC.dept_name||''); fillNcAuditor();
        $('#nAuditee').html(candOptions((NC.cands||{}).auditee, NC.auditee_id, NC.auditee_name));
        $('#nFact').val(NC.fact||''); $('#nFormNo').val(NC.ref_form_no||'');
        $('#nClause').val(NC.clause_ref||''); $('#nDue').val(inputDate(NC.due_date));
        var typeH = '<option value="">（請選擇）</option>';
        $.each(META.nc_types||{}, function(k,v){
            typeH += '<option value="'+k+'"'+(NC.nc_type===k?' selected':'')+'>'+esc(v)+'</option>';
        });
        $('#nType').html(typeH);
        // 段二
        $('#nCause').val(NC.cause||''); $('#nCorr').val(NC.corrective||''); $('#nPrev').val(NC.preventive||'');
        $('#nCorrDue').val(inputDate(NC.corrective_due)); $('#nPrevDue').val(inputDate(NC.preventive_due));
        fillRespCands(NC.cands||{}, NC.resp_id, NC.resp_name);
        /* 責任主管簽核日期限「稽核日期起一週內（日曆日，含假日）」——使用者指定。
           先把 min/max 掛上去，月曆就直接選不到範圍外的日期；送出時前後端再各驗一次。
           預設值若落在範圍外（例如補資料的單用稽核日期當預設，那一定在範圍內）就夾回範圍。 */
        var rg = NC.resp_date_range || {};
        $('#nRespDate').attr('min', rg.min || null).attr('max', rg.max || null)
                       .val(inputDate(NC.resp_date) || ncSignDefault());
        $('#nRespDateNote').text(rg.min
            ? ('　限 ' + dispDate(rg.min) + ' ～ ' + dispDate(rg.max) + '（稽核日起一週內，含假日）') : '');
        // 段三
        $('#nVerify').val(NC.verify_desc||''); $('#nVerifyRes').val(NC.verify_result||'');
        /* 稽核組長／管理代表都是**自動帶的**（2026-09-21 使用者要求）。
           後端 nc_get 讀取當下就已經依設定補正過，所以這裡直接顯示 NC.leader_name 就是正確的那一位
           （上一版只顯示 DB 舊值、要重新存檔才會變，使用者回報「還是代入錯誤」就是這個原因）。 */
        $('#nLeader').val(NC.leader_name || '');
        $('#nLeaderNote').text(NC.suggest_leader
            ? ('　自動帶入：' + NC.suggest_leader.name
               + (NC.suggest_leader.src === 'team' ? '（本年度稽核小組組長）' : '（本次稽核通知單登記的組長）'))
            : '　（本年度稽核小組還沒設定組長，沿用本單原本登記的人；要改請到工具列「稽核小組」設定）');
        $('#nLeaderDate').val(inputDate(NC.leader_date) || ncSignDefault());
        // 段四
        $('#nMgrNote').val(NC.mgr_note||'');
        $('#nMgrWho').text(NC.suggest_mgr
            ? ('　管理代表：' + NC.suggest_mgr.name + '（全站組織角色綁定，非按下按鈕的人）')
            : '　（尚未在組織角色綁定設定「管理代表」，將沿用操作者）'); $('#nMgrDate').val(inputDate(NC.mgr_date) || ncSignDefault());

        lockSec('#ncSec1', p.sec1, '#ncSec1Lock', '只有稽核員／內稽管理員能填');
        /* 段二送出後鎖定（2026-09-21 使用者要求）。管理員看得到「解鎖修改」，
           按下去才動得了——預設鎖住，滑鼠誤點也不會被自動暫存寫進去。 */
        SEC2_UNLOCKED = false;
        lockSec('#ncSec2', p.sec2, '#ncSec2Lock',
                p.sec2_locked_why || '只有受稽單位／稽核員代填');
        $('#btnSec2Unlock').toggle(!!p.sec2_admin).text('解鎖修改');
        SEC3_UNLOCKED = false;
        lockSec('#ncSec3', p.sec3, '#ncSec3Lock',
                p.sec3_locked_why || '只有稽核組長／稽核員能填');
        $('#btnSec3Unlock').toggle(!!p.sec3_admin).text('解鎖修改').css('color', '#C4442D');
        lockSec('#ncSec4', p.sec4, '#ncSec4Lock', '只有內稽管理員（管理代表）能填');
        /* 稽核員只有「內稽管理員」改得動（2026-09-21 使用者要求）。
           起因：管理員代別人開單時，稽核員會被自動填成按下按鈕的人＝管理員自己，
           而那張單實際上不是他去稽核的。一般稽核員看得到欄位但不能改，並寫明原因。
           ※ 一定要放在 lockSec() 之後——lockSec 會把整段的 select 一起停用，先設會被它蓋掉。 */
        $('#nAuditor').prop('disabled', !(p.sec1 && IS_ADMIN));
        $('#nAuditorNote').text(!p.sec1 ? '' : (IS_ADMIN ? '　可改（限內稽管理員）' : '　只有內稽管理員能更換稽核員'));
        $('#ncProxyNote').toggle(!!(p.sec2 && p.proxy));
        $('#btnNcDelete').toggle(!!p.del);
        // 已結案的單：內稽管理員可以取消結案回頭修正（例如稽核組長被寫成代填的人）
        $('#btnNcReopen').toggle(!!p.reopen);
        $('#btnNcResend').toggle(NC.stage!=='closed' && <?= $perms['canAudit'] ? 'true' : 'false' ?>);
        $('#btnNcClose').prop('disabled', NC.stage!=='verified')
                        .css('opacity', NC.stage!=='verified' ? .5 : 1)
                        .attr('title', NC.stage!=='verified' ? '要先由稽核組長完成驗證才能結案' : '');

        var lg = '<b>填寫歷程</b>';
        (NC.logs||[]).forEach(function(l){
            lg += '<div'+(+l.is_proxy ? ' class="proxy"' : '')+'>'
               + dispDate(l.created_at)+'　'+esc(l.by_name||'')+'　'+esc(l.note||l.action)
               + (+l.is_proxy ? '（代'+esc(l.on_behalf_name||'受稽單位')+'填寫）' : '')+'</div>';
        });
        $('#ncLog').html(lg);
        renderNcAtt();
        clearErrs($('#ncMask'));
        /* 自動暫存的比對基準要在「畫面填好之後」重抓一次，
           否則一開啟跳窗就會被判定成「內容有變」而無故寫一筆進去 */
        ncAutoSaveReset();
        // 補資料的單在標題上標出來，提醒現在填的日期是回溯的
        $('#ncTitle').append(+NC.is_backfill
            ? '<span style="font-size:12px;color:#C4442D;margin-left:8px;">（補資料：簽章日期預設為稽核日期 '
              + esc(dispDate(NC.audit_date)) + '，不是今天）</span>' : '');
        openMask('ncMask');
    });
}
/* 受稽核人／責任主管的下拉（2026-09-21 使用者要求）：
   一律**只列受稽核單位的主管**，不再攤開全公司的人（原本連生產3廠的組員都列得出來，
   那份清單在畫面上根本挑不到人，也很容易挑到別單位的人）。
   **原本就掛在這張單上的那一位一定要留著**——他可能已經調職或離職，清單裡不會有他，
   不留著的話光是改一行文字存檔就會把人洗掉（後端同樣放行「沒有更動」的情況）。 */
function candOptions(list, curId, curName){
    list = list || [];
    var h = '<option value="">（未指定）</option>', has = false;
    list.forEach(function(p){
        if (String(curId||'') === String(p.id)) has = true;
        var label = (p.department_name ? p.department_name+'　' : '')
                  + (p.position_name ? p.position_name+'　' : '') + p.name
                  + (+p.is_manager ? '' : '（非主管）');
        h += '<option value="'+p.id+'"'+(String(curId||'')===String(p.id)?' selected':'')+'>'+esc(label)+'</option>';
    });
    if (curId && !has) {
        h += '<option value="'+curId+'" selected>'
           + esc((curName||('#'+curId)) + '（原指定人員，已不在本單位主管名單）') + '</option>';
    }
    if (!list.length) h += '<option value="" disabled>（查不到這個單位在稽核日期當時的人員）</option>';
    return h;
}
/* 責任主管：候選範圍＝**受稽核單位**的主管（受審查單位主管欄位取消後就直接看受稽核單位） */
function fillRespCands(cd, curId, curName){
    $('#nResp').html(candOptions(cd.resp, curId, curName));
    var el = document.getElementById('nResp');
    if (el && typeof el.egFilterResnap === 'function') el.egFilterResnap();
    $('#nRespNote').text(cd.resp_dept_name
        ? ('　候選：' + cd.resp_dept_name + ' 的主管' + (+cd.resp_fallback ? '（查不到主管，改列該單位全部人員）' : ''))
        : '');
}
/* 稽核員下拉（2026-09-21 使用者要求：管理員代填時不要自動變成稽核員）。
   ①候選就是「設定為稽核員／稽核組長的人」——`META.auditors` 是 ia_qualified_posts(auditor)，
     稽核通知單上的稽核組長本來就是從同一份名單挑的，所以不必另外湊一份。
   ②資格與職稱一律依**這張單的稽核日期**回推（ai-rules/22），否則補舊單時當時有資格的人挑不到。
   ③原本掛在這張單上的那一位即使已不在候選清單也一定要留著，不然光是改一行字存檔就會把人洗掉。 */
function fillNcAuditor(){
    var curKey = postKeyOf(NC.auditor_id, NC.auditor_dept_id, NC.auditor_position_id);
    peopleAsof(NC.audit_date, function(){
        var h = postOptions(META.auditors, curKey, NC.auditor_id, '（未指定）');
        if (NC.auditor_id && h.indexOf('value="' + esc(curKey) + '"') < 0
            && h.indexOf('value="' + NC.auditor_id + ':') < 0) {
            h = h.replace('</option>',
                '</option><option value="' + esc(curKey) + '" selected>'
                + esc((NC.auditor_name || '') + '（原稽核員，目前已不在稽核員名單）') + '</option>');
        }
        $('#nAuditor').html(h);
        var el = document.getElementById('nAuditor');
        if (el && typeof el.egFilterResnap === 'function') el.egFilterResnap();
    });
}
/* 簽章日期的預設值（2026-09-21 使用者要求）：
   補資料的單（稽核日期已經是半年以前）一律預設成**稽核日期**，不可以預設成今天——
   今天的日期看起來像真的那天簽的，補一整批舊單很容易就這樣簽下去，紙本與系統從此對不起來。
   後端存檔時用同一條規則再算一次（ia_nc_sign_default），所以繞過前端也不會寫進今天。 */
function ncSignDefault(){
    if (!NC) return META.today;
    return inputDate(NC.sign_default || '') || META.today;
}
var SEC2_UNLOCKED = false, SEC3_UNLOCKED = false;
/* 管理員解鎖段三（已送出驗證的單，要修正驗證描述或簽核日期時）。與段二同一套：
   預設鎖住、按了才動得了、關掉重開又是鎖住的，自動暫存在鎖住時一律不作用。 */
$('#btnSec3Unlock').on('click', function(){
    if (!NC || !(NC.perm||{}).sec3_admin) return;
    SEC3_UNLOCKED = !SEC3_UNLOCKED;
    if (SEC3_UNLOCKED && !confirm('這張單的驗證已經送出。\n解鎖後您的修改會直接覆蓋原本的驗證內容，確定要修改嗎？')) {
        SEC3_UNLOCKED = false; return;
    }
    lockSec('#ncSec3', SEC3_UNLOCKED, '#ncSec3Lock',
            (NC.perm||{}).sec3_locked_why || '已送出驗證，不可再更動');
    $(this).show().text(SEC3_UNLOCKED ? '鎖回唯讀' : '解鎖修改')
           .css('color', SEC3_UNLOCKED ? '#7a5217' : '#C4442D');
});
/* 管理員解鎖段二（已送出回覆的單，紙本補錯字時還是得改得了）。
   解鎖是「這一次開啟有效」，關掉重開又是鎖住的狀態。 */
$('#btnSec2Unlock').on('click', function(){
    if (!NC || !(NC.perm||{}).sec2_admin) return;
    SEC2_UNLOCKED = !SEC2_UNLOCKED;
    if (SEC2_UNLOCKED && !confirm('這張單的受稽單位回覆已經送出。\n解鎖後您的修改會直接覆蓋原本的回覆內容，確定要修改嗎？')) {
        SEC2_UNLOCKED = false; return;
    }
    lockSec('#ncSec2', SEC2_UNLOCKED, '#ncSec2Lock',
            (NC.perm||{}).sec2_locked_why || '已送出回覆，不可再更動');
    $(this).show().text(SEC2_UNLOCKED ? '鎖回唯讀' : '解鎖修改')
           .css('color', SEC2_UNLOCKED ? '#7a5217' : '#C4442D');
});
/* ============================ 自動暫存（2026-09-21 使用者要求：改完自動幫我存，免得忘記） ============================
   ①只存「暫存」不送出——送出是流程動作（會發通知、會鎖段），永遠只能由人按
   ②停止打字 1.2 秒才送，並且**內容真的有變**才送（開啟跳窗當下不會無故寫一筆）
   ③段落是唯讀／鎖定時一律不存（段二鎖起來之後尤其重要）
   ④存完只在該段右下角寫一行「已自動暫存 HH:MM:SS」，不跳 alert、不重載整張單
     （重載會把游標與捲動位置吃掉，正在打字的人會被踢走） */
var AUTOSAVE = {t:null, last:{}};
function ncSecSnapshot(sec){
    var v = [];
    $(sec).find('input,select,textarea').each(function(){
        if (this.type === 'file' || $(this).hasClass('ia-att-note')) return;
        v.push((this.id||this.name||'') + '=' + String($(this).val()||''));
    });
    return v.join('|');
}
function ncAutoSaveMark(sec, txt){
    var $m = $(sec).find('.ia-autosave');
    if (!$m.length) {
        $m = $('<span class="ia-autosave" style="font-size:12px;color:#7a5217;margin-right:10px;"></span>');
        $(sec).find('div[style*="text-align:right"]').first().prepend($m);
    }
    $m.text(txt);
}
function ncAutoSaveNow(){
    if (!NC) return;
    var p = NC.perm || {};
    var jobs = [];
    if (p.sec1)                      jobs.push(['#ncSec1', function(cb){ saveSec1(true, cb); }]);
    if (p.sec2 || SEC2_UNLOCKED)     jobs.push(['#ncSec2', function(cb){ saveSec2(false, true, cb); }]);
    if (p.sec3 || SEC3_UNLOCKED)     jobs.push(['#ncSec3', function(cb){ saveSec3(false, true, cb); }]);
    if (p.sec4)                      jobs.push(['#ncSec4', function(cb){ saveSec4(false, true, cb); }]);
    jobs.forEach(function(j){
        var sec = j[0], snap = ncSecSnapshot(sec);
        if (AUTOSAVE.last[sec] === undefined) { AUTOSAVE.last[sec] = snap; return; }  // 首次只記錄，不送
        if (AUTOSAVE.last[sec] === snap) return;                                      // 沒變就不送
        AUTOSAVE.last[sec] = snap;
        ncAutoSaveMark(sec, '自動暫存中…');
        j[1](function(okRes){
            ncAutoSaveMark(sec, okRes ? ('已自動暫存 ' + new Date().toTimeString().slice(0,8)) : '自動暫存失敗，請按下方按鈕手動存檔');
        });
    });
}
function ncAutoSaveReset(){
    AUTOSAVE.last = {};
    ['#ncSec1','#ncSec2','#ncSec3','#ncSec4'].forEach(function(sec){
        AUTOSAVE.last[sec] = ncSecSnapshot(sec);
        $(sec).find('.ia-autosave').text('');
    });
}
$('#ncMask').on('input change', 'input,select,textarea', function(){
    if (!NC || $(this).prop('disabled') || this.type === 'file') return;
    clearTimeout(AUTOSAVE.t);
    AUTOSAVE.t = setTimeout(ncAutoSaveNow, 1200);
});
/* 關掉跳窗前先把還沒送出的變更存起來（使用者按 X 或點遮罩時，debounce 可能還沒觸發） */
$('#ncMask').on('mousedown', '[data-close],.x', function(){ clearTimeout(AUTOSAVE.t); ncAutoSaveNow(); });

function lockSec(sel, allow, lockSel, why){
    var $s = $(sel);
    $s.toggleClass('locked', !allow);
    $s.find('input,select,textarea').prop('disabled', !allow);
    $s.find('button').toggle(!!allow);
    $(lockSel).text(allow ? '' : '（唯讀：'+why+'）');
}

/* ============================ IA 單佐證附件（2026-09-21 使用者要求） ============================
   能不能傳一律沿用「這一段能不能填」（NC.perm.secN），不另外發明一套權限；
   lockSec() 已經把整段的 input 停用、button 隱藏，所以唯讀時上傳列自然就不見了，
   這裡只要多做一件事：唯讀時不要輸出「刪除」連結（那是 span，lockSec 管不到）。 */
function renderNcAtt(){
    ['sec1','sec2','sec3'].forEach(function(sec){
        var $box = $('#ncAtt'+sec);
        var rows = ((NC && NC.attach) || {})[sec] || [];
        var canEdit = !!(NC && NC.perm && NC.perm[sec]);
        var h = rows.map(function(a){
            var nm = a.orig_name || a.file_name;
            return '<span class="ia-att-item" title="'+esc(nm+'　'+(a.uploaded_by_name||'')+' '+(a.uploaded_at||'')
                        +(a.note?('　'+a.note):''))+'">'
                 + '<i class="fa fa-paperclip" style="color:#a08356"></i>'
                 + '<a href="'+API+'?action=nc_attach_get&att_id='+(+a.att_id)+'" target="_blank" rel="noopener">'
                 + esc(nm)+'</a>'
                 + (a.size_text ? '<span class="sz">'+esc(a.size_text)+'</span>' : '')
                 + (canEdit ? '<span class="del" data-att="'+(+a.att_id)+'" title="刪除這個附件">刪除</span>' : '')
                 + '</span>';
        }).join('');
        if (!h) h = '<span class="ia-att-none">' + (canEdit ? '尚未上傳佐證附件。' : '沒有附件。') + '</span>';
        $box.find('.ia-att-list').html(h);
        $box.find('.ia-att-msg').text('');
        $box.find('.ia-att-file').val('');
        $box.find('.ia-att-note').val('');
    });
}
/* 上傳：一次可選好幾個檔，逐一送出（後端一次收一個，錯誤才講得清楚是哪一個檔）。
   依記憶 file_upload_change_event 的三鐵則：原生 file input 直接可見、上傳鈕常駐、
   送出當下直讀 input.files（不依賴 change 事件——這台環境的 change 會被靜默吞掉）。 */
$(document).on('click', '#ncMask .ia-att-btn', function(){
    var $box = $(this).closest('.ia-att'), sec = $box.data('sec');
    var el = $box.find('.ia-att-file')[0];
    var files = (el && el.files) ? Array.prototype.slice.call(el.files) : [];
    var $msg = $box.find('.ia-att-msg');
    if (!files.length) { $msg.text('請先選擇檔案'); return; }
    if (!NC || !NC.nc_id) return;
    var note = $box.find('.ia-att-note').val() || '';
    var $btn = $(this).prop('disabled', true);
    var done = 0, errs = [];
    (function next(){
        if (!files.length) {
            $btn.prop('disabled', false);
            if (errs.length) { $msg.text('失敗 '+errs.length+' 個：'+errs.join('；')); }
            if (done) openNc(NC.nc_id);          // 重新載入＝附件清單與歷程一起更新
            return;
        }
        var f = files.shift();
        var fd = new FormData();
        fd.append('action', 'nc_attach_upload'); fd.append('nc_id', NC.nc_id);
        fd.append('section', sec); fd.append('note', note); fd.append('file', f);
        $msg.text('上傳中… '+f.name);
        $.ajax({url:API, type:'POST', data:fd, processData:false, contentType:false, dataType:'json'})
         .done(function(r){ if (r && r.ok) done++; else errs.push(f.name+'（'+((r&&r.error)||'失敗')+'）'); })
         .fail(function(x){ errs.push(f.name+'（'+(((x.responseJSON||{}).error)||'連線失敗')+'）'); })
         .always(next);
    })();
});
$(document).on('click', '#ncMask .ia-att-item .del', function(){
    var id = +$(this).data('att');
    if (!id || !confirm('刪除這個附件？檔案會一併從附件資料夾移除，無法復原。')) return;
    $.post(API, {action:'nc_attach_del', att_id:id}, function(r){
        if (!r.ok) { alert(r.error||'刪除失敗'); return; }
        openNc(NC.nc_id);
    }, 'json');
});
/* silent＝自動暫存呼叫（不跳訊息、不重載整張單，否則正在打字的人游標會被踢掉） */
function saveSec1(silent, cb){
    clearErrs($('#ncSec1'));
    var ok = true;
    // 自動暫存時必填欄位還沒填完是正常的（人還在打），不擋也不跳紅字；只有真的按儲存才驗
    if (!silent) {
        ok = fieldErr($('#nFact'), 'errNFact', $('#nFact').val().trim() ? '' : '請填不合格事實描述') && ok;
        ok = fieldErr($('#nType'), 'errNType', $('#nType').val() ? '' : '請選擇不合格類型') && ok;
    }
    var due = $('#nDue').val();
    if (due && NC.audit_date && due < inputDate(NC.audit_date)) {
        ok = fieldErr($('#nDue'), 'errNDue', '要求完成期限不可早於稽核日期') && ok;
    }
    if (!ok) { if (cb) cb(false); return; }
    $.post(API, {action:'nc_save_sec1', nc_id:NC.nc_id, fact:$('#nFact').val(), nc_type:$('#nType').val(),
        clause_ref:$('#nClause').val(), due_date:due, ref_form_no:$('#nFormNo').val(),
        auditee_id:$('#nAuditee').val(),
        auditor_key:($('#nAuditor').prop('disabled') ? '' : ($('#nAuditor').val()||''))}, function(res){
        if (!res.ok) { if (!silent) alert(res.error||'儲存失敗'); if (cb) cb(false); return; }
        if (!silent) { alert('已儲存'); openNc(NC.nc_id); }
        loadNcs(); if (cb) cb(true);
    }, 'json').fail(function(){ if (cb) cb(false); });
}
$('#btnNcSaveSec1').on('click', function(){ saveSec1(false); });
function saveSec2(submit, silent, cb){
    clearErrs($('#ncSec2'));
    if (submit) {
        var ok = true;
        ok = fieldErr($('#nCause'), 'errNCause', $('#nCause').val().trim() ? '' : '請填原因分析') && ok;
        ok = fieldErr($('#nCorr'),  'errNCorr',  $('#nCorr').val().trim()  ? '' : '請填糾正措施') && ok;
        ok = fieldErr($('#nCorrDue'),'errNCorrDue',$('#nCorrDue').val()     ? '' : '請選擇糾正措施的完成日期') && ok;
        ok = fieldErr($('#nPrev'),  'errNPrev',  $('#nPrev').val().trim()  ? '' : '請填預防措施') && ok;
        ok = fieldErr($('#nPrevDue'),'errNPrevDue',$('#nPrevDue').val()     ? '' : '請選擇預防措施的預計完成日期') && ok;
        // 預防措施的預計完成時間刻意沒有範圍限制（改善本來就可能排到很久以後）
        var rg2 = NC.resp_date_range || {}, rd = $('#nRespDate').val();
        if ($('#nResp').val() && rg2.min && rd && (rd < rg2.min || rd > rg2.max)) {
            ok = fieldErr($('#nRespDate'), 'errNRespDate',
                 '責任主管簽核日期限 ' + dispDate(rg2.min) + ' ～ ' + dispDate(rg2.max)
                 + '（稽核日起一週內，含假日）') && ok;
        }
        if (!ok) return;
        if (!confirm('送出後這一段會鎖定並通知稽核組長驗證，確定？')) return;
    }
    // 段二鎖住時一律不自動暫存（鎖起來就是為了避免誤改，自動存反而會把誤改寫進去）
    if (silent && !((NC.perm||{}).sec2 || SEC2_UNLOCKED)) { if (cb) cb(false); return; }
    $.post(API, {action:'nc_save_sec2', nc_id:NC.nc_id, submit:submit?1:'', cause:$('#nCause').val(),
        corrective:$('#nCorr').val(), corrective_due:$('#nCorrDue').val(),
        preventive:$('#nPrev').val(), preventive_due:$('#nPrevDue').val(),
        resp_id:$('#nResp').val(), resp_date:$('#nRespDate').val()}, function(res){
        if (!res.ok) { if (!silent) alert(res.error||'儲存失敗'); if (cb) cb(false); return; }
        if (!silent) {
            alert(submit ? ('已送出回覆'+(res.proxy?'（已記錄為代填）':'')) : '已暫存');
            openNc(NC.nc_id);
        }
        loadNcs(); if (cb) cb(true);
    }, 'json').fail(function(){ if (cb) cb(false); });
}
$('#btnNcSaveSec2').on('click', function(){ saveSec2(false, false); });
$('#btnNcSubmitSec2').on('click', function(){ saveSec2(true, false); });
function saveSec3(submit, silent, cb){
    clearErrs($('#ncSec3'));
    // 段三鎖住時一律不自動暫存（鎖起來就是為了避免誤改）
    if (silent && !((NC.perm||{}).sec3 || SEC3_UNLOCKED)) { if (cb) cb(false); return; }
    if (submit) {
        var ok = true;
        ok = fieldErr($('#nVerify'), 'errNVerify', $('#nVerify').val().trim() ? '' : '請填驗證描述') && ok;
        ok = fieldErr($('#nVerifyRes'), 'errNVerifyRes', $('#nVerifyRes').val() ? '' : '請選擇驗證結果') && ok;
        if (!ok) return;
        if ($('#nVerifyRes').val()==='fail' && !confirm('驗證不通過會退回受稽單位重新提出措施，並重新發通知。確定？')) return;
    }
    $.post(API, {action:'nc_save_sec3', nc_id:NC.nc_id, submit:submit?1:'', verify_desc:$('#nVerify').val(),
        verify_result:$('#nVerifyRes').val(),
        leader_date:$('#nLeaderDate').val()}, function(res){
        if (!res.ok) { if (!silent) alert(res.error||'儲存失敗'); if (cb) cb(false); return; }
        if (!silent) { alert(submit ? '已送出驗證' : '已暫存'); openNc(NC.nc_id); }
        loadNcs(); if (cb) cb(true);
    }, 'json').fail(function(){ if (cb) cb(false); });
}
$('#btnNcSaveSec3').on('click', function(){ saveSec3(false, false); });
$('#btnNcSubmitSec3').on('click', function(){ saveSec3(true, false); });
function saveSec4(close, silent, cb){
    if (close && !confirm('結案後本單即結束，並會通知受稽單位。確定？')) return;
    $.post(API, {action:'nc_save_sec4', nc_id:NC.nc_id, close:close?1:'', mgr_note:$('#nMgrNote').val(),
        mgr_date:$('#nMgrDate').val()}, function(res){
        if (!res.ok) { if (!silent) alert(res.error||'儲存失敗'); if (cb) cb(false); return; }
        if (!silent) {
            alert(close ? '已結案' : '已儲存');
            if (close) closeMask('ncMask'); else openNc(NC.nc_id);
        }
        loadNcs(); if (cb) cb(true);
    }, 'json').fail(function(){ if (cb) cb(false); });
}
$('#btnNcSaveSec4').on('click', function(){ saveSec4(false, false); });
$('#btnNcClose').on('click', function(){ if (!$(this).prop('disabled')) saveSec4(true, false); });
$('#btnNcReopen').on('click', function(){
    if (!confirm('取消結案後這張不符合通知單會退回「驗證完成」狀態，四個段落可以再修改。\n'
               + '（受稽單位回覆那一段仍是鎖住的，要改請按該段的「解鎖修改」。）\n確定要取消結案嗎？')) return;
    $.post(API, {action:'nc_reopen', nc_id:NC.nc_id}, function(res){
        if (!res.ok) { alert(res.error||'取消結案失敗'); return; }
        alert('已取消結案，現在可以修改；改完請再按一次「結案」。');
        openNc(NC.nc_id); loadNcs();
    }, 'json');
});
$('#btnNcResend').on('click', function(){
    $.post(API, {action:'nc_resend', nc_id:NC.nc_id}, function(res){
        if (!res.ok) { alert(res.error||'失敗'); return; }
        alert(res.sent ? '已重新發送通知' : '找不到可通知的對象（受稽核單位查不到當時的主管，請先指定受審核人）');
    }, 'json');
});
$('#btnNcDelete').on('click', function(){
    if (!confirm('刪除後這張不符合通知單將不再出現在清單與稽核報告表。確定？')) return;
    $.post(API, {action:'nc_delete', nc_id:NC.nc_id}, function(res){
        if (!res.ok) { alert(res.error||'刪除失敗'); return; }
        closeMask('ncMask'); loadNcs();
    }, 'json');
});
/* 清單上直接刪（管理員限定；原本只有打開單據才刪得掉，找不到刪除鈕＝使用者 2026-09-14 回報） */
function delNcRow(id, no){
    if (!IS_ADMIN) return;
    if (!confirm('確定刪除不符合通知單 '+(no||'')+'？\n刪除後它不再出現在清單與稽核報告表，對應的查檢表那一列也會解除連結。')) return;
    $.post(API, {action:'nc_delete', nc_id:id}, function(res){
        if (!res.ok) { alert(res.error||'刪除失敗'); return; }
        loadNcs();
        if (CHK) openCheck(CHK.check_id);
    }, 'json');
}

/* ============================ 稽核報告表 2-GM-06-08 ============================ */
var REPORT = null;
/* 稽核報告表要列哪些單位（2026-09-17 使用者要求）：
   **預設只列「有缺失」的單位**——紙本上每個單位都列一遍，絕大多數是空白列，
   反而看不出來這一年到底哪裡有問題。勾右上角「顯示全部受稽單位」才列全部。
   畫面與列印走同一個判斷，不會一邊有一邊沒有。 */
function reportRows(){
    var all = (REPORT && REPORT.rows) || [];
    if ($('#rptShowAll').is(':checked')) return all;
    return all.filter(function(d){
        return ((+d.major||0) + (+d.minor||0) + (+d.observe||0)) > 0;
    });
}
$(document).on('change', '#rptShowAll', function(){ if (REPORT) loadReport(); });

function loadReport(){
    $.getJSON(API, {action:'report_get', year:YEAR}, function(res){
        if (!res.ok) return;
        REPORT = res;
        var r = res.report;
        // 2026-09-17 起走「送出」不走核准；舊資料若是 approved 也一律顯示為已送出
        var done = r && (r.status==='submitted' || r.status==='approved');
        var sName = r ? (r.submitted_by_name || r.approver_name || '') : '';
        var sDate = r ? (r.submit_date || r.approver_date || '') : '';
        var box = r ? ('狀態：<span class="st st-'+(done?'done':'draft')+'">'+(done?'已送出':'草稿')+'</span>'
                      + (done && sName ? '　送出：'+esc(sName)+' '+dispDate(sDate) : ''))
                    : '<span style="color:#8a6d45;">尚未建立（按「儲存」即建立）</span>';
        $('#reportStatusBox').html(box);
        $('#reportNote').val(r ? (r.extra_note||'') : '');
        var rows = reportRows(), admin = <?= $perms['canAdmin'] ? 'true' : 'false' ?>;
        if (!rows.length) {
            var allN = (res.rows||[]).length;
            $('#reportBody').html('<tr><td colspan="9" class="ia-empty">'
                + (allN ? ('本年度 '+allN+' 個受稽單位都沒有缺失（勾右上角「顯示全部受稽單位」可以看全部）')
                        : (YEAR+' 年度還沒有稽核紀錄')) + '</td></tr>');
        } else {
            var h = '';
            rows.forEach(function(d){
                h += '<tr><td class="l">'+esc(d.dept_name)+'</td>'
                  + '<td>'+(d.major||'')+'</td><td>'+(d.minor||'')+'</td><td>'+(d.observe||'')+'</td>'
                  + '<td>'+dispDate(d.audited_date)+'</td><td>'+esc(d.audited_time||'')+'</td>'
                  + '<td>'+auditorLines(d, 110)+'</td>'
                  + '<td><input type="date" class="rpDue" data-dept="'+esc(d.dept_name)+'" value="'
                  + esc(inputDate(d.improve_due))+'"'+(admin?'':' readonly')
                  + ' style="border:1px solid #D8BE93;border-radius:3px;padding:2px;font-size:12px;"></td>'
                  + '<td>'+(d.total ? (d.closed+'／'+d.total) : '—')+'</td></tr>';
            });
            $('#reportBody').html(h);
        }
        var recs = res.records||[];
        if (!recs.length) {
            $('#reportRecBody').html('<tr><td colspan="5" class="ia-empty">本年度沒有缺點記錄</td></tr>');
        } else {
            var b = '';
            recs.forEach(function(r2){
                b += '<tr><td>'+esc(r2.dept_name)+'</td>'
                  + '<td><span class="ia-op" onclick="openNc('+r2.nc_id+')">'+esc(r2.nc_no)+'</span></td>'
                  + '<td class="l">'+esc(r2.form_no||'—')
                  + (r2.form_name ? '<br><span style="color:#8a6d45;font-size:11px;">'+esc(r2.form_name)+'</span>' : '')+'</td>'
                  + '<td class="l">'+esc(r2.fact||'')+'</td>'
                  + '<td><span class="st st-'+esc(r2.stage)+'">'+esc((META.nc_stages||{})[r2.stage]||r2.stage)+'</span></td></tr>';
            });
            $('#reportRecBody').html(b);
        }
    });
}
$('#btnReportSave').on('click', function(){
    var dues = $('.rpDue').map(function(){
        return {dept_name:$(this).data('dept'), improve_due:$(this).val()};
    }).get();
    $.post(API, {action:'report_save', year:YEAR, extra_note:$('#reportNote').val(),
                 dues:JSON.stringify(dues)}, function(res){
        if (!res.ok) { alert(res.error||'儲存失敗'); return; }
        alert('已儲存'); loadReport();
    }, 'json');
});
/* 送出（取代原本的核准）：送出後自動通知管理員設定好的那些部門的那些職位。
   沒設定通知對象也送得出去，只是回報通知 0 人——不要因為沒設定就把流程擋住。 */
$('#btnReportSubmit').on('click', function(){
    if (!REPORT || !REPORT.report) { alert(YEAR+' 年度還沒有建立稽核報告表，請先按「儲存」'); return; }
    /* 2026-09-21 使用者要求：送出不要再問日期——**報告表上根本沒有印送出日期**
       （列印版的表頭日期用的是核准／製表日期，不是這個），問了也沒有地方會顯示，
       只是多一個跳窗。送出時間仍照常記在 submit_date（畫面狀態列與年度完成狀態要用）。 */
    if (!confirm('要送出 '+YEAR+' 年度的稽核報告表嗎？\n送出後會通知「通知對象設定」裡登記的人員。')) return;
    $.post(API, {action:'report_submit', year:YEAR}, function(res){
        if (!res.ok) { alert(res.error||'送出失敗'); return; }
        alert('已送出\n'
            + (res.notified ? ('已通知 '+res.notified+' 人：'+(res.users||[]).join('、'))
                            : '目前沒有設定通知對象，所以沒有發出通知。\n可按工具列的「通知對象設定」設定要通知哪些部門的哪些職位。'));
        loadMeta(function(){ loadReport(); });      // 年度完成狀態要跟著更新
    }, 'json');
});

/* ---------- 通知對象設定（部門 × 職位） ---------- */
var RPT_RULES = [];
function rptNotifyRender(){
    var h = '';
    RPT_RULES.forEach(function(r, i){
        var dh = '<option value="0">（不限部門）</option>';
        (META.depts||[]).forEach(function(d){
            dh += '<option value="'+d.id+'"'+(+r.dept_id===+d.id?' selected':'')+'>'+esc(d.name)+'</option>';
        });
        var ph = '<option value="0">（不限職位）</option>';
        (META.positions||[]).forEach(function(p){
            ph += '<option value="'+p.id+'"'+(+r.position_id===+p.id?' selected':'')+'>'+esc(p.name)+'</option>';
        });
        h += '<tr data-i="'+i+'">'
          + '<td><select class="rptR" data-f="dept_id" data-eg-filter="輸入部門名稱篩選…" style="width:100%;">'+dh+'</select></td>'
          + '<td><input type="checkbox" class="rptR" data-f="with_sub" data-eg-skip'+(+r.with_sub?' checked':'')+'></td>'
          + '<td><select class="rptR" data-f="position_id" data-eg-filter="輸入職位名稱篩選…" style="width:100%;">'+ph+'</select></td>'
          + '<td><span class="ia-op danger" onclick="rptNotifyDel('+i+')"><i class="fa fa-times"></i></span></td></tr>';
    });
    $('#rptNotifyBody').html(h || '<tr><td colspan="4" class="ia-empty">還沒有設定，送出報告表時不會通知任何人</td></tr>');
    rptNotifyPreview();
}
function rptNotifyDel(i){ RPT_RULES.splice(i,1); rptNotifyRender(); }
function rptNotifyPreview(){
    $.post(API, {action:'report_notify_preview', rules:JSON.stringify(RPT_RULES)}, function(res){
        var list = (res && res.preview) || [];
        $('#rptNotifyCount').text(list.length);
        $('#rptNotifyPreview').text(list.length
            ? list.map(function(u){ return (u.dept_name?u.dept_name+' ':'')+(u.position_name?u.position_name+' ':'')+u.name; }).join('、')
            : '（沒有符合的人員）');
    }, 'json');
}
$(document).on('change', '.rptR', function(){
    var i = +$(this).closest('tr').data('i'), f = $(this).data('f');
    if (!RPT_RULES[i]) return;
    RPT_RULES[i][f] = (f==='with_sub') ? ($(this).is(':checked')?1:0) : +$(this).val();
    rptNotifyPreview();
});
$('#btnRptNotifyAdd').on('click', function(){ RPT_RULES.push({dept_id:0, position_id:0, with_sub:0}); rptNotifyRender(); });
$('#btnReportNotifySet').on('click', function(){
    $.getJSON(API, {action:'report_notify_get'}, function(res){
        if (!res.ok) { alert(res.error||'載入失敗'); return; }
        RPT_RULES = (res.rules||[]).map(function(r){ return {dept_id:+r.dept_id, position_id:+r.position_id, with_sub:+r.with_sub?1:0}; });
        rptNotifyRender();
        openMask('rptNotifyMask');
    });
});
$('#btnRptNotifySave').on('click', function(){
    $.post(API, {action:'report_notify_save', rules:JSON.stringify(RPT_RULES)}, function(res){
        if (!res.ok) { alert(res.error||'儲存失敗'); return; }
        alert('已儲存：共 '+res.count+' 條規則，目前會通知 '+((res.preview||[]).length)+' 人');
        closeMask('rptNotifyMask');
    }, 'json');
});

/* ---------- 管理員補舊年度 ---------- */
$(document).on('click', '#btnYearAdd', function(){
    var y = prompt('要補哪一個年度的內稽資料？（請輸入西元年，例 2023）\n'
                 + '年度選單只會列出「已經有資料的年度」與今年、明年，補進來之後才選得到。');
    if (y === null) return;
    y = parseInt(String(y).trim(), 10);
    if (!(y >= 2000 && y <= (+String(META.today).substr(0,4)) + 1)) { alert('年度不正確'); return; }
    $.post(API, {action:'year_add', year:y}, function(res){
        if (!res.ok) { alert(res.error||'失敗'); return; }
        loadMeta(function(){ $('#yearSel').val(y).trigger('change'); });
    }, 'json');
});
/* 刪除稽核報告表（管理員限定）。表格內容本來就是由不符合通知單即時算出來的，
   刪掉的只有「補充文字＋製表／核准」這張表本身，稽核資料一筆都不會少。 */
$('#btnReportDelete').on('click', function(){
    if (!REPORT) { alert(YEAR+' 年度還沒有建立稽核報告表（按「儲存」才會建立）'); return; }
    if (!confirm('確定刪除 '+YEAR+' 年度稽核報告表？\n表格上的缺點數是即時算出來的不受影響，刪掉的是補充文字與送出紀錄。')) return;
    $.post(API, {action:'report_delete', year:YEAR}, function(res){
        if (!res.ok) { alert(res.error||'刪除失敗'); return; }
        loadReport();
    }, 'json');
});

/* ============================ 設定 ============================ */
$('#btnSetting').on('click', function(){
    var s = META.settings||{};
    var h = '';
    $.each(META.as_docs||{}, function(k,v){
        h += '<tr><td class="l">'+esc(v.label)+'</td>'
          + '<td class="l" id="asdoc-'+k+'">'+(v.bound
                ? esc(v.doc_no+'　'+v.doc_name)
                : '<span style="color:#C4442D;">尚未綁定（列印會用預設編號 '+esc(v.doc_no)+'）</span>')+'</td>'
          + '<td><span class="ia-op" onclick="pickAsDoc(\''+k+'\')">選擇</span>'
          + (v.bound ? '<span class="ia-op danger" onclick="clearAsDoc(\''+k+'\')">清除</span>' : '')+'</td></tr>';
    });
    $('#setAsBody').html(h);
    var th = '<option value="">（不指定，用預設回墨印）</option>';
    (META.stamp_tpls||[]).forEach(function(t){
        th += '<option value="'+t.id+'"'+(String(s.ia_stamp_tpl_id)===String(t.id)?' selected':'')+'>'
            + esc(t.tpl_name+(t.type_name?('（'+t.type_name+'）'):''))+'</option>';
    });
    $('#setStampTpl').html(th);
    var sh = '';
    $.each(META.sign_sources||{}, function(k,v){ sh += '<option value="'+k+'">'+esc(v)+'</option>'; });
    $('#setSignApprove').html(sh).val(s.ia_sign_approve||'');
    $('#setSignReview').html(sh).val(s.ia_sign_review||'');
    $('#setAutoSign').prop('checked', String(s.ia_auto_sign||'') === '1');
    $('#setAutoSignCase').prop('checked', String(s.ia_auto_sign_case||'') === '1');
    $('#setRemindDays').val(s.ia_remind_days||'7');
    $('#setMeetPre').val(s.ia_meeting_pre_subject||'');
    $('#setMeetEnd').val(s.ia_meeting_end_subject||'');
    $('#setCaseRemark').val(s.ia_case_remark_tpl||'');
    clearErrs($('#settingMask'));
    caseRemarkCnt();                       // clearErrs 之後才算，否則紅字會被清掉
    openMask('settingMask');
});
function pickAsDoc(key){
    if (!window.EGAsDoc) { alert('AS 文件挑選器未載入'); return; }
    var docs = META.as_doc_list || [];
    if (!docs.length) { alert('讀不到 AS 文件清單，請重新整理頁面再試（需要內稽管理員權限）'); return; }
    var info = (META.as_docs||{})[key] || {};
    // 共用挑選器的參數是 docs/current/title/onSave（不是 onPick），docs 沒傳就會是空清單
    EGAsDoc.open({
        docs   : docs,
        current: info.doc_id || 0,
        title  : 'AS 文件編號綁定　—　' + (info.label || ''),
        onSave : function(id){
            $.post(API, {action:'save_asdoc', key:key, doc_id:id}, function(res){
                if (!res.ok) { alert(res.error||'儲存失敗'); return; }
                loadMeta(function(){ $('#btnSetting').click(); });
            }, 'json');
        }
    });
}
function clearAsDoc(key){
    if (!confirm('清除綁定後，該表單列印時會用預設編號、表頭用內建名稱。確定？')) return;
    $.post(API, {action:'save_asdoc', key:key, doc_id:0}, function(res){
        if (!res.ok) { alert(res.error||'失敗'); return; }
        loadMeta(function(){ $('#btnSetting').click(); });
    }, 'json');
}
$('#setRemindDays').on('input', function(){
    var v = $(this).val().trim();
    fieldErr($(this), 'errRemindDays', (v==='' || (/^\d+$/.test(v) && +v<=365)) ? '' : '請填 0～365 的整數');
});
/* 備註預設文字：即時顯示字數並在超過上限當下就標紅（後端同規則再擋一次） */
function caseRemarkCnt(){
    var n = $('#setCaseRemark').val().replace(/\r\n/g,'\n').length;
    $('#setCaseRemarkCnt').text('目前 '+n+' 字／上限 2000 字'+(n===0?'（空白＝新增通知單時不帶備註）':''));
    fieldErr($('#setCaseRemark'), 'errCaseRemark', n>2000 ? '備註預設文字最多 2000 字' : '');
    return n;
}
$('#setCaseRemark').on('input', caseRemarkCnt);
$('#btnCaseRemarkDefault').on('click', function(){
    var d = META.case_remark_default || '';
    if (!d) { alert('讀不到內建預設文字，請重新整理頁面再試'); return; }
    if ($('#setCaseRemark').val().trim() && !confirm('會覆蓋目前這段文字，確定還原成內建預設？')) return;
    $('#setCaseRemark').val(d); caseRemarkCnt();
});
$('#btnSettingSave').on('click', function(){
    var v = $('#setRemindDays').val().trim();
    if (!(v==='' || (/^\d+$/.test(v) && +v<=365))) { fieldErr($('#setRemindDays'),'errRemindDays','請填 0～365 的整數'); return; }
    if (caseRemarkCnt() > 2000) { $('#setCaseRemark').focus(); return; }
    var jobs = [
        ['ia_stamp_tpl_id',       $('#setStampTpl').val()],
        ['ia_sign_approve',       $('#setSignApprove').val()],
        ['ia_sign_review',        $('#setSignReview').val()],
        ['ia_auto_sign',          $('#setAutoSign').prop('checked') ? '1' : '0'],
        ['ia_auto_sign_case',     $('#setAutoSignCase').prop('checked') ? '1' : '0'],
        ['ia_remind_days',        v],
        ['ia_meeting_pre_subject',$('#setMeetPre').val()],
        ['ia_meeting_end_subject',$('#setMeetEnd').val()],
        ['ia_case_remark_tpl',    $('#setCaseRemark').val().replace(/\r\n/g,'\n')]
    ];
    var done = 0, failed = '', backfill = null;
    jobs.forEach(function(j){
        $.post(API, {action:'save_setting', key:j[0], value:j[1]}, function(res){
            if (!res.ok) failed = res.error||'儲存失敗';
            // 開啟通知單自動簽核時，後端會把「已完成但還沒有章」的單一次補簽，這裡要講出來
            if (res && res.backfill) backfill = res.backfill;
        }, 'json').always(function(){
            if (++done === jobs.length) {
                if (failed) { alert(failed); return; }
                var msg = '設定已儲存';
                if (backfill && +backfill.filled > 0) {
                    msg += '\n\n已完成但還沒有簽章的稽核通知單共 ' + backfill.filled + ' 張，已一併補上核准與審查：\n'
                         + (backfill.cases||[]).join('、');
                }
                alert(msg); closeMask('settingMask'); loadMeta();
            }
        });
    });
});

/* ======== AS 文件挑選 ＋ 打字模糊篩選建議（2026-08-26 使用者要求） ========
   ①條文題庫的「建立的文件、表單」：按「＋選文件」開跳窗，打編號或名稱篩選後多選
   ②IA 不符合通知單的「相關表單編號」：輸入後即時列出 AS 文件供選
   ③IA 不符合通知單的「違反條文」：從 AS 條文題庫（品質管理系統要求）自動建議
   三處共用同一份資料，各只抓一次就快取起來，不重複打 API。 */
var ASDOCS = null, CLAUSE_BANK = null, DOCPICK_TR = null;

function loadAsDocs(cb){
    if (ASDOCS) { cb(ASDOCS); return; }
    $.getJSON(API, {action:'asdoc_pick_list'}, function(res){
        ASDOCS = (res && res.ok) ? (res.rows||[]) : [];
        cb(ASDOCS);
    }).fail(function(){ ASDOCS = []; cb(ASDOCS); });
}
function loadClauseBank(cb){
    if (CLAUSE_BANK) { cb(CLAUSE_BANK); return; }
    $.getJSON(API, {action:'clause_list'}, function(res){
        // 章節標題列（4.組織背景…）不是條文本身，不拿來當「違反條文」的建議
        CLAUSE_BANK = (res && res.ok) ? (res.rows||[]).filter(function(c){ return +c.is_header !== 1; }) : [];
        cb(CLAUSE_BANK);
    }).fail(function(){ CLAUSE_BANK = []; cb(CLAUSE_BANK); });
}
/**
 * 中文正規化（NFC）——**這一步不能省**。
 * AS 條文題庫是從 2024 的 .xls 匯進來的，裡面有 60 個欄位夾帶 Unicode「CJK 相容表意文字」
 * （例：8.2.4 的「變更」，那個「更」是 U+F901 而不是一般的 U+66F4）。兩者**畫面上長得一模一樣**，
 * 但字碼不同 → 使用者打「變更」永遠搜不到那一條，而且完全不會報錯。
 * String.normalize('NFC') 會把相容字換回一般字，比對前兩邊都要過一次。
 */
function nfc(v){
    v = String(v||'');
    try { return v.normalize('NFC'); } catch(e) { return v; }   // 極舊瀏覽器沒有 normalize 就照原樣比
}
/** 多關鍵字（空白分隔）全部命中才算，比對整串文字 */
function kwHit(hay, kw){
    hay = nfc(hay).toLowerCase();
    var ws = nfc(kw).toLowerCase().split(/\s+/).filter(Boolean);
    if (!ws.length) return true;
    for (var i=0;i<ws.length;i++) if (hay.indexOf(ws[i]) < 0) return false;
    return true;
}

/* ---- ①「＋選文件」跳窗 ---- */
function openDocPick(el){
    DOCPICK_TR = $(el).closest('tr');
    $('#docPickKw').val('');
    loadAsDocs(function(){ renderDocPick(); openMask('docPickMask'); $('#docPickKw').focus(); });
}
function renderDocPick(){
    var kw = $('#docPickKw').val(), h = '', n = 0;
    (ASDOCS||[]).forEach(function(d){
        if (!kwHit(d.doc_no + ' ' + d.doc_name, kw)) return;
        n++;
        h += '<label><input type="checkbox" class="dpck" data-eg-skip value="'+esc(d.doc_no)+'" data-name="'+esc(d.doc_name)+'">'
           + '<span class="dp-no">'+esc(d.doc_no)+'</span><span>'+esc(d.doc_name)+'</span>'
           + (d.doc_type?'<span class="dp-type">'+esc(d.doc_type)+'</span>':'')+'</label>';
    });
    $('#docPickBox').html(h || '<div style="color:#b0a390;font-size:12px;padding:6px;">查無符合的文件</div>');
    $('#docPickCnt').text('符合 '+n+' 筆'+((ASDOCS||[]).length?('／共 '+ASDOCS.length+' 筆'):''));
}
$('#docPickKw').on('input', renderDocPick);
$('#btnDocPickOk').on('click', function(){
    if (!DOCPICK_TR) { closeMask('docPickMask'); return; }
    var add = [];
    $('#docPickBox .dpck:checked').each(function(){
        add.push($(this).data('name') + '(' + $(this).val() + ')');   // 沿用題庫既有的「名稱(編號)」寫法
    });
    if (!add.length) { alert('請先勾選要加入的文件'); return; }
    var $ta = DOCPICK_TR.find('textarea[data-f="doc_ref"]');
    var cur = String($ta.val()||'').trim();
    // 已經有的不重複加
    add = add.filter(function(x){ return cur.indexOf(x) < 0; });
    $ta.val((cur ? cur + ' ' : '') + add.join(' '));
    closeMask('docPickMask');
    if (add.length) autoSaveClause(DOCPICK_TR);   // 自動儲存，不再要求使用者按「存」
});

/* ---- ②③ 打字即時建議（附掛在既有 input 上，不改欄位本身） ---- */
function attachSuggest($inp, getList){
    if (!$inp.length || $inp.data('sugOn')) return;
    $inp.data('sugOn', 1);
    $inp.wrap('<span class="ia-sug-wrap" style="display:block;"></span>');
    var $wrap = $inp.parent(), $box = $('<div class="ia-sug" style="display:none;"></div>').appendTo($wrap);
    function close(){ $box.hide().empty(); }
    function open(){
        getList(function(list){
            var kw = $inp.val(), h = '', n = 0;
            list.forEach(function(o){
                if (n >= 30) return;
                if (!kwHit(o.search, kw)) return;
                n++;
                h += '<div data-v="'+esc(o.value)+'">'+(o.no?'<span class="no">'+esc(o.no)+'</span>':'')+esc(o.label)
                   + (o.extra ? '<div class="sug-extra">'+esc(o.extra)+'</div>' : '')+'</div>';
            });
            $box.html(h || '<div class="empty">查無符合項目</div>').show();
        });
    }
    $inp.on('focus input', open);
    $inp.on('keydown', function(e){
        if (e.key === 'Escape') { close(); return; }
        if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp' && e.key !== 'Enter') return;
        var $items = $box.find('div[data-v]');
        if (!$items.length) return;
        var i = $items.index($box.find('div.on'));
        if (e.key === 'Enter') { if (i >= 0) { e.preventDefault(); $items.eq(i).trigger('mousedown'); } return; }
        e.preventDefault();
        i = (e.key === 'ArrowDown') ? Math.min(i+1, $items.length-1) : Math.max(i-1, 0);
        $items.removeClass('on').eq(i).addClass('on');
        var el = $items.get(i); if (el && el.scrollIntoView) el.scrollIntoView({block:'nearest'});
    });
    $box.on('mousedown', 'div[data-v]', function(e){ e.preventDefault(); $inp.val($(this).data('v')); close(); });
    $inp.on('blur', function(){ setTimeout(close, 150); });
}
/** 相關表單編號：選了就填編號本身（單據上要印的是編號） */
function sugAsDocs(cb){
    loadAsDocs(function(rows){
        cb(rows.map(function(d){
            return {value:d.doc_no, no:d.doc_no, label:d.doc_name, search:d.doc_no+' '+d.doc_name};
        }));
    });
}
/** 違反條文：直接取「品質管理系統要求」全文（紙本就是照抄這一段） */
function sugClauses(cb){
    loadClauseBank(function(rows){
        cb(rows.map(function(c){
            // 一併正規化，避免相容字被原封不動存進 ia_nc.clause_ref，之後查詢一樣找不到
            var t  = nfc(c.clause_text).replace(/\s+/g,' ').trim();
            var tk = (c.tasks||[]).map(function(x){ return x.task_name; });
            // 建議清單上把「這一條在查什麼」一起顯示出來（作業項目＋對應文件），
            // 光看 AS 條文原文開單的人根本認不出來是哪一條；打「外包加工」也要找得到。
            return {value:t, no:'', label:t, search:t + ' ' + (c.doc_ref||'') + ' ' + tk.join(' '),
                    extra:(tk.length ? '作業項目：'+tk.join('、') : '') +
                          ((c.doc_ref||'') ? (tk.length ? '　' : '') + '文件：'+String(c.doc_ref).replace(/\s+/g,'、') : '')};
        }));
    });
}
$(function(){
    attachSuggest($('#nFormNo'),  sugAsDocs);
    attachSuggest($('#nnFormNo'), sugAsDocs);
    attachSuggest($('#nClause'),  sugClauses);
    attachSuggest($('#nnClause'), sugClauses);
});

/* ============================ AS 條文題庫 ============================ */
$('#btnClauseBank').on('click', loadClauses);
function loadClauses(){
    $.getJSON(API, {action:'clause_list'}, function(res){
        if (!res.ok) { alert(res.error||'載入失敗'); return; }
        var h = '';
        (res.rows||[]).forEach(function(c){
            h += clauseRowHtml(c);
        });
        $('#clauseBody').html(h || '<tr><td colspan="7" class="ia-empty">題庫是空的</td></tr>');
        clauseRenumber();
        openMask('clauseMask');
    });
}
/* 一列的 HTML（新增列與載入共用同一份，避免兩邊欄位走鐘＝鐵律4） */
function clauseRowHtml(c){
    c = c || {};
    return '<tr data-id="'+(c.clause_id||'')+'" draggable="true">'
      + '<td class="cl-drag" title="拖曳調整順序"><i class="fa fa-bars"></i> <span class="cl-seq"></span></td>'
      + '<td><input type="checkbox" class="clF" data-f="is_header"'+(+c.is_header?' checked':'')+'></td>'
      + '<td class="l"><textarea class="clF" data-f="clause_text" style="width:100%;min-height:38px;border:1px solid #D8BE93;border-radius:3px;padding:3px 5px;font-size:12px;">'+esc(c.clause_text||'')+'</textarea></td>'
      + '<td class="l"><textarea class="clF" data-f="doc_ref" style="width:100%;min-height:34px;border:1px solid #D8BE93;border-radius:3px;padding:3px 5px;font-size:12px;">'+esc(c.doc_ref||'')+'</textarea>'
      + '<span class="ia-op" style="margin-top:2px;" onclick="openDocPick(this)"><i class="fa fa-plus"></i> 選文件</span></td>'
      + '<td class="l cl-task">'+clauseTaskHtml(c)+'</td>'
      + '<td><input type="checkbox" class="clF" data-f="is_active"'+(c.clause_id===undefined||+c.is_active?' checked':'')+'></td>'
      + '<td><span class="ia-op danger" onclick="delClause(this)"><i class="fa fa-trash"></i></span>'
      + '<div class="cl-st"></div></td></tr>';
}
/* 作業項目欄（2026-09-11 使用者交辦）：
   條文原文看不出實務上在查什麼，所以掛「作業項目」（品管檢測／外包加工…）。
   項目本身是設在 **AS 文件管理 → ⚙ 作業項目**（一份文件可以有好幾個），
   這裡只負責挑「這一條對應到哪幾個」，候選一律限這一條左欄列出來的那幾份文件。 */
function clauseTaskHtml(c){
    c = c || {};
    var docs = c.doc_tasks || [], picked = {};
    (c.tasks||[]).forEach(function(t){ picked[t.task_id] = 1; });
    if (!docs.length) {
        return '<span class="cl-task-hint">左欄先填／選文件，才挑得到作業項目</span>';
    }
    var h = '', any = false;
    docs.forEach(function(d){
        if (!(d.tasks||[]).length) {
            h += '<div class="cl-task-hint">'+esc(d.doc_no)+' 尚未設定作業項目</div>';
            return;
        }
        any = true;
        h += '<div class="cl-task-doc" title="'+esc(d.doc_no+' '+d.doc_name)+'">'+esc(d.doc_no)+'</div>';
        d.tasks.forEach(function(t){
            h += '<label class="cl-task-chk"><input type="checkbox" class="clTask" data-eg-skip value="'+t.task_id+'"'
               + (picked[t.task_id]?' checked':'')+'> '+esc(t.task_name)+'</label>';
        });
    });
    if (!any) h += '<div class="cl-task-hint">到 AS 文件管理 → ⚙ 作業項目 設定後就會出現在這裡</div>';
    return h;
}
/* 勾選作業項目＝直接存（跟其他欄位一樣自動儲存） */
$('#clauseBody').on('change', '.clTask', function(){ autoSaveClause($(this).closest('tr')); });
function clauseTaskIds($tr){
    return $tr.find('.clTask:checked').map(function(){ return +$(this).val(); }).get();
}
/* 畫面上的序號只是顯示（1,2,3…）；真正的 sort_order 由後端重新編成 10,20,30… */
function clauseRenumber(){
    $('#clauseBody tr[data-id]').each(function(i){ $(this).find('.cl-seq').text(i+1); });
}
/* 拖曳排序：放開就送後端重新編號（使用者要求不要手動輸入順序） */
var CL_DRAG = null;
$('#clauseBody').on('dragstart', 'tr', function(e){
    CL_DRAG = this; $(this).addClass('cl-dragging');
    try { e.originalEvent.dataTransfer.effectAllowed = 'move';
          e.originalEvent.dataTransfer.setData('text/plain', ''); } catch(err){}
});
$('#clauseBody').on('dragend', 'tr', function(){ $(this).removeClass('cl-dragging'); CL_DRAG = null; });
$('#clauseBody').on('dragover', 'tr', function(e){
    if (!CL_DRAG || CL_DRAG === this) return;
    e.preventDefault();
    var r = this.getBoundingClientRect();
    var after = (e.originalEvent.clientY - r.top) > r.height / 2;
    $(this)[after ? 'after' : 'before'](CL_DRAG);
});
$('#clauseBody').on('drop', 'tr', function(e){ e.preventDefault(); clauseSaveOrder(); });
function clauseSaveOrder(){
    clauseRenumber();
    var ids = $('#clauseBody tr[data-id]').map(function(){ return $(this).data('id'); }).get()
              .filter(function(x){ return x !== '' && x !== undefined; });
    if (!ids.length) return;
    $.post(API, {action:'clause_reorder', ids:JSON.stringify(ids)}, function(res){
        if (!res.ok) { alert(res.error||'排序儲存失敗'); loadClauses(); }
    }, 'json').fail(function(){ alert('排序儲存失敗'); loadClauses(); });
}
function rowClause($tr){
    var o = {clause_id: $tr.data('id')||''};
    $tr.find('.clF').each(function(){
        var f = $(this).data('f');
        o[f] = ($(this).attr('type')==='checkbox') ? ($(this).is(':checked')?1:'') : $(this).val();
    });
    o.task_ids = JSON.stringify(clauseTaskIds($tr));
    return o;
}
/* 自動儲存（2026-09-11 使用者要求：改完就存，不要再按一次「存」）。
   textarea 的 change 是離開欄位、勾選框是點下去當下觸發，兩者都只在值真的變了才發。
   刻意不做「每打一個字就送」：那會在打長條文時打出幾十次寫入。 */
$('#clauseBody').on('change', '.clF', function(){ autoSaveClause($(this).closest('tr')); });
function clauseFlash($tr, msg, bad){
    var $s = $tr.find('.cl-st').text(msg||'').toggleClass('bad', !!bad);
    if (msg && !bad) setTimeout(function(){ if ($s.text()===msg) $s.text(''); }, 2000);
}
function autoSaveClause($tr, cb){
    var o = rowClause($tr);
    var $ta = $tr.find('textarea[data-f="clause_text"]');
    if (!String(o.clause_text||'').trim()) {
        // 空白不送（新列還沒打字就切走很常見），但要當場講清楚為什麼沒存
        $ta.addClass('err');
        clauseFlash($tr, $tr.data('id') ? '未存：要求不可空白' : '未存：請先填要求', true);
        return;
    }
    $ta.removeClass('err');
    clauseFlash($tr, '儲存中…');
    $.post(API, $.extend({action:'clause_save'}, o), function(res){
        if (!res.ok) { clauseFlash($tr, '儲存失敗', true); return; }
        var isNew = !$tr.data('id');
        $tr.attr('data-id', res.clause_id).data('id', res.clause_id);
        // 文件欄剛被改過的話，可挑的作業項目也跟著變了——用後端回來的重畫，不要讓畫面停在舊候選
        $tr.find('.cl-task').html(clauseTaskHtml({doc_tasks:res.doc_tasks, tasks:res.tasks}));
        clauseFlash($tr, '已儲存');
        if (isNew) clauseSaveOrder();   // 新列存完才有 id，順帶把整份順序寫回去
        if (cb) cb();
    }, 'json').fail(function(){ clauseFlash($tr, '儲存失敗', true); });
}
function delClause(el){
    var $tr = $(el).closest('tr');
    var id = $tr.data('id');
    if (!id) { $tr.remove(); return; }
    if (!confirm('刪除這一條？已被既有查檢表引用的條文會自動改為停用，不會真的刪掉。')) return;
    $.post(API, {action:'clause_delete', clause_id:id}, function(res){
        if (!res.ok) { alert(res.error||'刪除失敗'); return; }
        if (res.note) alert(res.note);
        loadClauses();
    }, 'json');
}
$('#btnClauseAdd').on('click', function(){
    $('#clauseBody').find('.ia-empty').closest('tr').remove();
    $('#clauseBody').append(clauseRowHtml());
    clauseRenumber();
});

/* ============================ 角色說明（即時查現況，不寫死角色清單＝鐵律4） ============================ */
function loadRoleHelp(){
    $.getJSON('../../src/store/Roles_API.php', {action:'list', module:'internal_audit'}, function(res){
        var rows = (res && (res.rows || res.roles)) || [];
        var h = '<ul>';
        if (rows.length) {
            rows.forEach(function(r){
                h += '<li><b>'+esc(r.role_name||r.role_code)+'</b>（'+esc(r.role_code)+'）</li>';
            });
        } else {
            h += '<li>目前這個模組沒有設定任何角色。</li>';
        }
        h += '<li><b>管理者</b>：固定擁有全部權限。</li>'
           + '<li><b>其他在職員工</b>：不需要角色，也能收到自己單位的不符合通知單並填寫回覆。</li></ul>'
           + '<p style="color:#8a6d45;">角色的指派在「使用者權限設定」頁面。以上清單是<b>即時查詢目前實際角色設定</b>，不是寫死的說明文字。</p>';
        $('#roleHelpBox,#helpRoleBox').html(h);
    }).fail(function(){
        $('#roleHelpBox,#helpRoleBox').html('<p style="color:#8a6d45;">角色清單載入失敗，請到「使用者權限設定」頁面查看內部稽核的角色。</p>');
    });
}
$('#btnRoleHelp').on('click', function(){ loadRoleHelp(); openMask('roleHelpMask'); });
$('#btnPageHelp').on('click', function(){ loadRoleHelp(); openMask('helpUseMask'); });

/* ============================ 其他 ============================ */
$(window).on('scroll', function(){ $('#btnTop').toggle($(window).scrollTop() > 300); });
$('#btnTop').on('click', function(){ $('html,body').animate({scrollTop:0}, 200); });
$(function(){
    loadMeta(function(){
        var q = new URLSearchParams(location.search);
        if (q.get('nc_id')) {
            $('.ia-tab[data-pane=nc]').click();
            openNc(+q.get('nc_id'));
            return;
        }
        if (q.get('case_id')) {
            $('.ia-tab[data-pane=case]').click();
            loadCases(function(){ openCase(+q.get('case_id')); });
            return;
        }
        loadDash();
    });
});
</script>
<script>
/* ============================ 列印（ai-rules/16） ============================
   三個固定元素：①大標題＝本公司全名（動態取，禁寫死）②頁碼「第X頁／共Y頁」左下、多頁才印
   ③綁定的 AS 文件編號右下角每頁都印；表頭表單名稱一律取綁定文件的 doc_name。
   版次依該單據的業務日期回推當時生效的版次（後端 print_meta 已處理）。
   簽章一律走 eg_stamp.js 產生帶日期印章，不只印人名。                              */

function iaPrintWindow(title, bodyHtml, extraCss, docNo, landscape){
    var asCss = String(docNo||'').replace(/['\\]/g,'');
    // 版面留白（2026-09-17 使用者回報「上方與左右都沒留空，很難看」）：上 18mm／左右 15mm／下 16mm
    /* 版面留白（2026-09-21 使用者回報：左右被裁掉／上方完全沒有留白）。
       **兩段式**：@page 給 14mm（適度、不過度），body 再補 5mm 當保險。
       為什麼要補 body padding——瀏覽器列印視窗裡的「邊界」若被選成「無」或「最小」，
       Chrome 會**直接蓋掉 @page 的 margin**，那時只剩 body padding 撐著，
       至少還有 5mm 不會貼邊被裁；選「預設」時兩者相加約 19mm，看起來剛好。
       上下用同一個數字，單頁高度判定才算得準（下方 onePage 就是用 MG*2 推的）。 */
    var MG = 14;                       // @page 四邊留白（mm）
    var PAD = 5;                       // body 保險留白（mm）
    var css = '@page{size:A4 '+(landscape?'landscape':'portrait')+';margin:'+MG+'mm;'
            + (asCss ? " @bottom-right{ content:'"+asCss+"'; font-size:9pt; color:#333; }" : '')
            + '}'
            + 'body{font-family:"Microsoft JhengHei","微軟正黑體",sans-serif;color:#000;'
            + 'margin:0;padding:'+PAD+'mm;'
            + '-webkit-print-color-adjust:exact;print-color-adjust:exact;}'
            + '.pt-head{text-align:center;margin-bottom:8px;}'
            + '.pt-head .co{font-size:20px;font-weight:bold;letter-spacing:2px;}'
            + '.pt-head .en{font-size:11px;letter-spacing:1px;}'
            + '.pt-head .tt{font-size:17px;font-weight:bold;margin-top:5px;letter-spacing:3px;}'
            + 'table.ia-p{width:100%;border-collapse:collapse;font-size:12px;}'
            + 'table.ia-p th,table.ia-p td{border:1px solid #333;padding:4px 6px;text-align:center;vertical-align:top;}'
            + 'table.ia-p th{font-weight:bold;background:#fff;}'
            + 'table.ia-p td.l{text-align:left;}'
            + 'table.ia-p td.pre{text-align:left;white-space:pre-wrap;line-height:1.6;}'
            /* 段落標題（原因分析／糾正措施…）放大加粗，一眼看得出哪裡是標題（2026-09-21 使用者要求） */
            + 'table.ia-p .sec-h{font-size:14px;font-weight:bold;letter-spacing:1px;margin:10px 0 2px;}'
            + 'table.ia-p .sec-h:first-child{margin-top:0;}'
            /* 圖章靠右下（2026-09-21 使用者要求）。格子先用 padding-right 把章的寬度讓出來，
               文字就不會壓到章上面；一個章讓 130px，兩個章（管理代表＋稽核組長）讓 265px。 */
            /* 讓出來的寬度是**實測**的：一個章（含左邊的標籤）約 160px、兩個並排約 320px，
               各多留一點餘裕，文字才不會壓到章上面。 */
            + 'table.ia-p td.sig-cell{position:relative;padding-right:175px;}'
            + 'table.ia-p td.sig-cell2{padding-right:335px;}'
            + 'table.ia-p .sig-br{position:absolute;right:6px;bottom:4px;white-space:nowrap;text-align:right;}'
            + 'table.ia-p .sig-br .sig-one{display:inline-block;margin-left:12px;vertical-align:bottom;}'
            /* 還沒簽的格子：留一塊與圖章同寬的空白讓人手簽，版面不會因為少一個章而位移 */
            + 'table.ia-p .sig-blank{display:inline-block;width:94px;height:94px;'
            + 'border-bottom:1px solid #666;vertical-align:bottom;}'
            + '.ia-sign{display:flex;margin-top:14px;font-size:12px;}'
            + '.ia-sign .cell{flex:1;border:1px solid #333;min-height:76px;padding:4px 6px;text-align:center;}'
            + '.ia-sign .cell .lb{font-weight:bold;margin-bottom:3px;}'
            + '.ia-sign .cell + .cell{border-left:none;}'
            /* 圖章尺寸依 ai-rules/18：有空間的簽核欄一律 91px 不縮小 */
            + '.ia-sign svg.car-stamp,.ia-sign .stamp-wrap svg{width:91px !important;height:91px !important;}'
            + '.ia-sign .eg-stamp-tpl{-webkit-print-color-adjust:exact;print-color-adjust:exact;}'
            + '.stamp-inline svg.car-stamp{width:70px !important;height:70px !important;vertical-align:middle;}'
            + '.ia-note{font-size:11px;line-height:1.7;white-space:pre-wrap;margin-top:6px;}'
            + (extraCss||'');
    var w = window.open('', '_blank');
    if (!w){ alert('請允許彈出視窗才能列印'); return; }
    // 只有真的超過一頁才注入頁碼（單頁表單印「第1頁／共1頁」很醜，紙本也沒有）
    // 可印高度＝紙張高 − 上下 @page 留白 − 上下 body padding（換算成 px @96dpi）
    var onePage = ((landscape?210:297) - MG*2 - PAD*2) * 96 / 25.4;
    var js = 'if(document.body.scrollHeight>'+Math.round(onePage*0.92)+'){'
           + 'var st=document.createElement("style");'
           + 'st.textContent="@page{ @bottom-left{ content:\'第 \' counter(page) \' 頁／共 \' counter(pages) \' 頁\'; font-size:9pt; color:#333; } }";'
           + 'document.head.appendChild(st);}';
    w.document.write('<html><head><meta charset="utf-8"><title>'+esc(title)+'</title><style>'+css+'</style></head><body>'
        + bodyHtml
        + '<scr'+'ipt>window.onload=function(){'+js+'setTimeout(function(){window.print();},250);};</scr'+'ipt></body></html>');
    w.document.close();
}
/** 列印用：一個人一列，「部門 姓名」印在同一列不可拆行；字太長時自動縮小字級塞進欄寬。
 *  同一格裡的每一列一律用同一個字級（取最長的那一列回推），不可以逐列各自算——
 *  逐列算會讓同一格內的部門與人名大小不一（2026-09-02 使用者回報）。
 *  （2026-09-02 使用者要求：稽核員要顯示部門、要一人一列、部門與人名不可分段、大小要一致） */
function personLines(names, maxW){
    var ns = (names||[]).map(function(t){ return String(t||'').trim(); })
                        .filter(function(t){ return t !== ''; });
    if (!ns.length) return '';
    maxW = maxW || 130;
    // 中文字寬約等於字級，空白算半個字；由「最長的那一列」回推字級，最小 8px、最大 12px
    var n = 1;
    ns.forEach(function(t){
        n = Math.max(n, t.replace(/\s/g,'').length + (/\s/.test(t) ? 0.5 : 0));
    });
    var size = Math.max(8, Math.min(12, Math.floor(maxW / n * 10) / 10));
    return ns.map(function(t){
        return '<div style="white-space:nowrap;font-size:'+size+'px;line-height:1.5;">'+esc(t)+'</div>';
    }).join('');
}
/** 一列受稽單位的稽核員 → 多列「部門 姓名」（沒有人員清單時退回舊的姓名字串） */
function auditorLines(d, maxW){
    var ns = (d.auditors||[]).map(function(x){
        return String(x.dept_name ? (x.dept_name+' '+x.user_name) : (x.user_name||''));
    }).filter(function(x){ return x.trim()!==''; });
    if (!ns.length) ns = String(d.auditor_name||'').split(/[、／\/]/).filter(function(x){ return x.trim()!==''; });
    return personLines(ns, maxW);
}
function printHead(meta, titleOverride){
    return '<div class="pt-head"><div class="co">'+esc(meta.company||'')+'</div>'
         + '<div class="en">EXCELLENT GEAR TECHNOLOGY CO.,LTD</div>'
         + '<div class="tt">'+esc(titleOverride || meta.doc_name || '')+'</div></div>';
}
/* 簽章：一律用 eg_stamp.js 產生帶日期的印章，禁止只印姓名或底線
   **日期一定要先過 dispDate()**（ai-rules/20）：eg_stamp.js 是把傳進去的字串原樣畫在章上、
   不會自己格式化，直接把 DB 的 Y-m-d 丟進去，章上就會印成 2024-11-22 而不是 2024.11.22。
   收斂在這一個函式，14 個呼叫點（計畫表/通知單/查檢表/IA單/報告表）一次全部正確。 */
function stampHtml(meta, person, date){
    if (!person || !person.name) return '';
    var d = date ? dispDate(date) : '';
    // 沒有明確給 dept/position 時，用 IDENT（該業務日期當時的職務）補上，圖章模板才畫得出部門那一列
    if (person.id && (!person.dept && !person.position)) {
        var idt = IDENT[identKey(person.id, person._d || date)] || IDENT[identKey(person.id, '')];
        if (idt) { person = $.extend({}, person, {dept:idt.dept||'', position:idt.position||''}); }
    }
    try {
        if (window.EGStamp && EGStamp.stamp) {
            return EGStamp.stamp(person.name, d, false,
                                 meta.stamp_tpl ? meta.stamp_tpl.schema : null,
                                 person.dept||'', person.position||'');
        }
    } catch(e){}
    return esc(person.name) + (d ? ('　'+d) : '');
}
function signCells(meta, cells){
    var h = '<div class="ia-sign">';
    cells.forEach(function(c){
        h += '<div class="cell"><div class="lb">'+esc(c.label)+'</div>'+(c.html||'')+'</div>';
    });
    return h + '</div>';
}
/* 取列印中繼資料（AS 編號依業務日期回推版次），拿到才開列印視窗 */
/* 圖章要印的「部門／職稱」（2026-08-27 使用者回報：列印的章跟圖章模板設計的格式不同、部門不見了）
   圖章模板 schema 是「{部門} {姓名}／{日期}」兩列，列印端多數呼叫只給姓名，模板取不到部門就空著。
   解法：withPrintMeta 多收一個 people 清單（要蓋章的人＋該單據業務日期），一併向後端要回
   「當時的部門／職稱」（ai-rules/22 由 ia_identity_asof 回推，前端不自己猜），存進 IDENT 供 stampHtml 用。 */
var IDENT = {};
function identKey(id, date){ return (id||0) + '@' + (date||''); }
/** 組一個給 stampHtml 用的人物件；有 id 就會自動補上當時的部門/職稱 */
function sp(id, name, date){ return {id:id||0, name:name||'', _d:date||''}; }
function withPrintMeta(key, bizDate, ctx, cb, people){
    var q = $.extend({action:'print_meta', key:key, biz_date:bizDate||''}, ctx||{});
    $.getJSON(API, q, function(res){
        if (!res.ok) { alert(res.error||'列印資料載入失敗'); return; }
        // eg_stamp.js 的回墨印上半格印的是「本公司全名」，它讀的是全域 window.__ownCompany；
        // 本頁沒設過這個變數，所以章的上半格一直是空的（2026-09-02 使用者回報「公司名稱顯示不完全」）。
        // 名稱本來就跟著 print_meta 回來（禁寫死＝ai-rules/16），在這裡補上，14 個蓋章點一次全部正確。
        if (res.company) window.__ownCompany = res.company;
        var need = (people||[]).filter(function(x){ return x && +x.id > 0 && !IDENT[identKey(x.id, x.date)]; });
        var go = function(){
            // 掃描實體章對照表是非同步載入的，沒等它有實體章的人會印成預設 SVG 章
            if (window.EGStamp && EGStamp.whenReady) EGStamp.whenReady(function(){ cb(res); });
            else cb(res);
        };
        if (!need.length) { go(); return; }
        $.getJSON(API, {action:'identity_asof', people:JSON.stringify(need)}, function(r2){
            if (r2 && r2.ok) $.each(r2.map||{}, function(k, v){ IDENT[k] = v; });
            go();
        }).fail(go);          // 解析不到就照舊只印姓名，不擋列印
    });
}
function logPrint(name, refTable, refId){
    try { if (window.EGPrintLog) EGPrintLog.record({source:'internal_audit', doc_kind:'form',
            doc_name:name, ref_table:refTable, ref_id:refId}); } catch(e){}
}

/* ---------- ① 年度稽核計劃表 2-GM-06-01 ---------- */
$('#btnPlanPrint').on('click', function(){
    if (!PLAN) { alert('本年度還沒有稽核計劃表'); return; }
    var biz = PLAN.approved_date || PLAN.submit_date || PLAN.maker_date || META.today;
    var planD = PLAN.maker_date;
    withPrintMeta('plan', biz, {leader_id:'', maker_id:PLAN.maker_id||'', maker_name:PLAN.maker_name||''}, function(m){
        var h = printHead(m, (PLAN.title || (PLAN.year + ' 年內部稽核計畫表')));
        h += '<table class="ia-p"><thead><tr><th rowspan="2" style="width:70px;">稽核組別<br>月份</th>';
        PLAN.depts.forEach(function(d){
            h += '<th style="width:46px;">'+esc(d.dept_name||d.cur_name||'').split('').join('<br>')+'</th>';
        });
        h += '</tr><tr style="display:none;"></tr></thead><tbody>';
        for (var mo=1;mo<=12;mo++){
            h += '<tr><td>'+mo+'月</td>';
            PLAN.depts.forEach(function(d){
                var k = d.dept_id+'-'+mo;
                h += '<td style="height:20px;font-size:13px;">'
                   + (PLAN.actual[k] ? '◎' : '') + (PLAN.cells[k] ? '○' : '') + '</td>';
            });
            h += '</tr>';
        }
        h += '</tbody></table>';
        // 2026-08-27 使用者要求：核准／審查的日期不好判定，一律跟「製表日期」相同
        var planDate = PLAN.maker_date;
        h += signCells(m, [
            {label:'核准', html: stampHtml(m, PLAN.approver_id ? sp(PLAN.approver_id, PLAN.approver_name, planDate) : m.sign_approve, planDate)},
            {label:'審查', html: stampHtml(m, PLAN.reviewer_id ? sp(PLAN.reviewer_id, PLAN.reviewer_name, planDate) : m.sign_review, planDate)},
            {label:'製表', html: stampHtml(m, PLAN.maker_id ? sp(PLAN.maker_id, PLAN.maker_name, planDate) : null, planDate)}
        ]);
        h += '<div class="ia-note">備註: ○計畫實施　◎實際實施'
           + (PLAN.remark ? ('\n'+PLAN.remark) : '') + '</div>';
        logPrint((PLAN.year+' 年內部稽核計畫表'), 'ia_plan', PLAN.plan_id);
        iaPrintWindow(PLAN.year+' 年內部稽核計畫表', h, '', m.doc_no, false);
    }, [{id:PLAN.approver_id, date:planD}, {id:PLAN.reviewer_id, date:planD}, {id:PLAN.maker_id, date:planD}]);
});

/* ---------- ② 稽核通知單 2-GM-06-02 ---------- */
function printCase(id){
    $.getJSON(API, {action:'case_get', case_id:id}, function(res){
        if (!res.ok) { alert(res.error||'載入失敗'); return; }
        var c = res.row;
        var biz = c.notify_date || META.today;
        var caseD = c.maker_date || c.notify_date;
        withPrintMeta('case', biz, {leader_id:c.leader_id||'', leader_name:c.leader_name||'',
                                    maker_id:c.maker_id||'', maker_name:c.maker_name||''}, function(m){
            var h = printHead(m);
            h += '<table class="ia-p" style="margin-bottom:6px;"><tr>'
              + '<td class="l" style="border:none;">通知日期: '+dispDate(c.notify_date)+'</td>'
              + '<td style="border:none;width:110px;">'+esc(c.year)+' 年度</td>'
              + '<td style="border:none;width:90px;">第 '+esc(c.seq_no)+' 次</td></tr></table>';
            h += '<table class="ia-p"><tr>'
              + '<th style="width:90px;">稽核時間</th><td class="l" colspan="3">'
              + dispDate(c.audit_from)+' 至 '+dispDate(c.audit_to||c.audit_from)+'</td></tr>'
              + '<tr><th>稽核件號</th><td>'+esc(c.case_no||'')+'</td>'
              + '<th style="width:90px;">稽核組長</th><td>'+esc(c.leader_name||'')+'</td></tr></table>';

            var ds = c.depts||[];
            // 稽核員／陪檢員可多位，一位一行；一列印「部門 姓名」且部門與姓名不可被拆到兩行
            var nameLines = function(list, fallback){
                var ns = (list||[]).map(function(x){
                    return String(x.dept_name ? (x.dept_name + ' ' + x.user_name) : (x.user_name||''));
                }).filter(function(x){ return x.trim()!==''; });
                if (!ns.length && fallback) ns = String(fallback).split(/[、／\/]/).filter(function(x){ return x!==''; });
                // 欄寬 140px 扣掉左右 padding ≈ 128px；不可寫成 ns.map(personLines) 之類，
                // map 會把「索引」當成第二個參數傳進去（原本的 bug：第二個人以後都變 8px）
                return personLines(ns, 128);
            };
            // 2026-08-27 使用者要求：標題改在上面（與畫面上的受稽單位列表同一種讀法），
            // 一個受稽單位一列；<thead> 讓表頭跨頁自然重複（列印分頁交給瀏覽器引擎）
            h += '<table class="ia-p" style="margin-top:6px;"><thead><tr>'
              + '<th>稽核起始主過程</th><th style="width:140px;">受稽單位</th>'
              + '<th style="width:140px;">稽核員</th><th style="width:140px;">陪檢員</th>'
              + '</tr></thead><tbody>';
            ds.forEach(function(d){
                h += '<tr><td class="l">'+esc(d.start_process||'')+'</td>'
                   + '<td>'+esc(d.dept_name||'')+'</td>'
                   + '<td>'+nameLines(d.auditors, d.auditor_name)+'</td>'
                   + '<td>'+nameLines(d.escorts, d.escort_name)+'</td></tr>';
            });
            if (!ds.length) h += '<tr><td style="height:22px;">&nbsp;</td><td></td><td></td><td></td></tr>';
            h += '</tbody></table>';

            h += '<table class="ia-p" style="margin-top:6px;"><tr><th style="width:90px;">備註</th>'
              + '<td class="pre">'+esc(c.remark||'')+'</td></tr>'
              + '<tr><th>結束會議</th><td class="l">'
              + (c.end_meet_date ? (dispDate(c.end_meet_date)+'　'+esc(c.end_meet_start||'')+' 至 '+esc(c.end_meet_end||'')) : '')
              + '　地點: '+esc(c.end_meet_place||'')+'</td></tr></table>';

            // 2026-08-27 使用者要求：核准／審查日期一律跟「製表日期」相同
            var caseDate = c.maker_date || c.notify_date;
            /* **還沒按「完成」的通知單一律不印核准／審查的章**（2026-09-18 使用者回報）。
               這兩格原本在單據沒有簽核人時會退回「設定裡指定的那一位」，等於草稿也印得出
               一份看起來已經簽好的表——那是不實的簽章。完成之後才會有 approver_id／reviewer_id。
               製表格不受影響（製表人是誰填的表，跟完成與否無關）。 */
            var caseDone = ['issued','executing','closed'].indexOf(String(c.status||'')) >= 0;
            h += signCells(m, [
                {label:'核准', html: (caseDone && c.approver_id) ? stampHtml(m, sp(c.approver_id, c.approver_name, caseDate), caseDate) : ''},
                {label:'審查', html: (caseDone && c.reviewer_id) ? stampHtml(m, sp(c.reviewer_id, c.reviewer_name, caseDate), caseDate) : ''},
                {label:'製表', html: stampHtml(m, c.maker_id ? sp(c.maker_id, c.maker_name, caseDate) : null, caseDate)}
            ]);
            logPrint('稽核通知單 '+(c.case_no||('#'+id)), 'ia_case', id);
            iaPrintWindow('稽核通知單 '+(c.case_no||''), h, '', m.doc_no, false);
        }, [{id:c.approver_id, date:caseD}, {id:c.reviewer_id, date:caseD}, {id:c.maker_id, date:caseD}]);
    });
}
$('#btnCasePrint').on('click', function(){ if (CASE_ID) printCase(CASE_ID); else alert('請先儲存'); });

/* ---------- ③ 查檢表（三種版面） 2-GM-06-03·04·06 ---------- */
function printCheck(id){
    $.getJSON(API, {action:'check_get', check_id:id}, function(res){
        if (!res.ok) { alert(res.error||'載入失敗'); return; }
        var k = res.row;
        withPrintMeta(k.kind, k.check_date || META.today,
                      {maker_id:k.auditor_id||'', maker_name:k.auditor_name||''}, function(m){
            /* 大標題印**種類名稱**（系統稽核紀錄表／AS稽核查檢表／績效執行稽核查檢表），
               也就是清單上「種類」那一欄（2026-09-21 使用者要求）。
               原本印的是 k.title（「第2次　2025.11.03」），那是次別與日期、不是表單名稱。
               次別與日期改印在標題下方那一列，與稽核人、日期同一行。 */
            var h = printHead(m, k.kind_label || m.doc_name || k.title);
            var d = String(k.check_date||'').split('-');
            h += '<div style="font-size:12px;margin-bottom:5px;overflow:hidden;">'
               + '<span>稽核人: '+esc(k.auditor_name||'')+'</span>'
               + (k.title ? '<span style="margin-left:18px;">'+esc(k.title)+'</span>' : '')
               + '<span style="float:right;">'+(d[0]||'')+' 年 '+(d[1]||'')+' 月 '+(d[2]||'')+' 日</span></div>';
            h += '<table class="ia-p"><thead><tr>';
            var heads = (k.kind==='as')
                ? ['項次','品質管理系統要求','建立的文件、表單','合格','不合格','所見證據或建議']
                : (k.kind==='system')
                    ? ['序號','表單編號','表單名稱','受稽人','合格','不合格','備註']
                    : ['序','部門','內容','目標','受稽人','達成','沒達成','備註(異常矯正處理單編號)'];
            var widths = (k.kind==='as') ? ['34px','','170px','36px','40px','130px']
                       : (k.kind==='system') ? ['34px','86px','','66px','36px','40px','92px']
                       : ['28px','62px','','76px','60px','36px','44px','110px'];
            heads.forEach(function(t,i){ h += '<th'+(widths[i]?(' style="width:'+widths[i]+';"'):'')+'>'+esc(t)+'</th>'; });
            h += '</tr></thead><tbody>';
            var n = 0;
            (k.items||[]).forEach(function(it){
                if (+it.is_header===1) {
                    h += '<tr><td class="l" colspan="'+heads.length+'" style="font-weight:bold;">'+esc(it.col_a)+'</td></tr>';
                    return;
                }
                n++;
                var okM = it.result==='ok' ? 'V' : '';
                var ngM = it.result==='ng' ? 'V' : '';
                if (k.kind==='as') {
                    h += '<tr><td>'+n+'</td><td class="l">'+esc(it.col_a)+'</td>'
                      + '<td class="pre" style="font-size:11px;">'+esc(it.col_b||'')+'</td>'
                      + '<td>'+okM+'</td><td>'+ngM+'</td><td class="pre">'+esc(it.evidence||'')+'</td></tr>';
                } else if (k.kind==='system') {
                    h += '<tr><td>'+n+'</td><td>'+esc(it.col_a||'')+'</td><td class="l">'+esc(it.col_b||'')+'</td>'
                      + '<td>'+esc(it.col_c||'')+'</td><td>'+okM+'</td><td>'+ngM+'</td>'
                      + '<td>'+esc(it.nc_no || it.remark || '')+'</td></tr>';
                } else {
                    h += '<tr><td>'+n+'</td><td>'+esc(it.col_a||'')+'</td><td class="l">'+esc(it.col_b||'')+'</td>'
                      + '<td>'+esc(it.col_c||'')+'</td><td>'+esc(it.col_d||'')+'</td>'
                      + '<td>'+okM+'</td><td>'+ngM+'</td><td>'+esc(it.remark||'')+'</td></tr>';
                }
            });
            h += '</tbody></table>';
            h += '<div class="ia-note">'
               + (k.kind==='as' ? '' : '確認項目及結果；以「V」表示之。') + '</div>';
            // 2026-08-27 使用者要求：稽核員的簽章跟一般表格的「製表」一樣靠右，不要放左下角
            h += '<div style="margin-top:10px;font-size:12px;text-align:right;">稽核員: <span class="stamp-inline">'
               + stampHtml(m, sp(k.auditor_id, k.auditor_name, k.check_date), k.check_date) + '</span></div>';
            // 2026-08-27 使用者要求：右側已經印了稽核日期，標題就不要重複出現日期
            // （標題常被存成「系統稽核紀錄表 2024-12-16」，這裡把結尾的日期去掉）
            var ckTitle = String(k.title || m.doc_name || '')
                          .replace(/[\s　]*\d{4}[-\/.]\d{1,2}[-\/.]\d{1,2}[\s　]*$/, '').trim()
                          || (m.doc_name || '');
            logPrint(ckTitle + ' ' + dispDate(k.check_date), 'ia_check', id);
            iaPrintWindow(ckTitle, h, '', m.doc_no, false);
        }, [{id:k.auditor_id, date:k.check_date}]);
    });
}
$('#btnCheckPrint').on('click', function(){ if (CHK) printCheck(CHK.check_id); });

/* ---------- ④ 內稽不符合通知單 2-GM-06-07 ---------- */
function printNc(id){
    $.getJSON(API, {action:'nc_get', nc_id:id}, function(res){
        if (!res.ok) { alert(res.error||'載入失敗'); return; }
        var n = res.row;
        withPrintMeta('nc', n.audit_date || META.today,
                      {leader_id:n.leader_id||'', leader_name:n.leader_name||'',
                       maker_id:n.auditor_id||'', maker_name:n.auditor_name||''}, function(m){
            var h = printHead(m);
            h += '<div style="font-size:12px;margin-bottom:5px;">表單編號: '+esc(n.nc_no||'')+'</div>';
            /* 版面依紙本 2-GM-06-07：「不合格類型」加框放在事實描述上方、「違反條文:」粗體、
               沒有「單位主管核示」那一行；稽核員段／受稽單位段／驗證段各自併成一大格
               （紙本中間沒有橫線），圖章一律靠右下，段與段之間用一條粗線分開。 */
            var pre = function (t) { return '<div class="pre">' + esc(t || '') + '</div>'; };
            var signOne = function (label, id, name, date) {
                return esc(label) + ': <span class="stamp-inline">' + stampHtml(m, sp(id, name, date), date) + '</span>';
            };
            /* 2026-09-21 使用者要求的版面調整：
               ①**整張一定要壓在 A4 一頁內**（原本會印成兩頁）：拿掉不需要的格、把固定高度改小
               ②取消「受審查單位主管」與其簽章（稽核員段只留稽核員一個章）
               ③取消「管理代表意見」整格
               ④「糾正和預防措施執行狀況驗證描述」與「稽核組長」簽章合併成同一格
               ⑤「結束」改成印**結案日期**（＝稽核組長蓋章日期），放在該格左下角
               ⑥責任主管的章不要被推到很下面——緊接在措施內容之後
               ⑦「原因分析」這類段落標題字放大，一眼看得出是標題 */
            var sh = function (t) { return '<div class="sec-h">' + esc(t) + '</div>'; };
            /* 圖章一律**靠右下角**（2026-09-21 使用者要求）：用絕對定位釘在該格右下，
               而不是接在內容後面自成一行——接在後面的話，內容一短章就被推到奇怪的位置，
               內容一長又會把整格撐高、整張印不進一頁。
               格子本身 padding-right 先把章的寬度讓出來，文字永遠不會壓到章上面。
               一格可以放不只一個章（驗證段是「管理代表」＋「稽核組長」兩個並排）。 */
            var stampBR = function (list) {
                return '<div class="sig-br">'
                     + list.map(function (x) {
                           /* **沒有簽核日期＝這一格還沒簽**，只印標籤留白給人簽，絕不可以蓋出一個章。
                              稽核組長／管理代表的姓名現在是依設定自動補正的，若不看日期就蓋章，
                              一張還沒驗證、還沒結案的單會印出「已經簽好」的假簽章。 */
                           return '<span class="sig-one">'
                                + (x[3] ? signOne(x[0], x[1], x[2], x[3])
                                        : (esc(x[0]) + ': <span class="sig-blank"></span>'))
                                + '</span>';
                       }).join('')
                     + '</div>';
            };
            h += '<table class="ia-p">'
              + '<tr><th style="width:100px;">受稽核單位</th><td style="width:150px;">'+esc(n.dept_name||'')+'</td>'
              + '<th style="width:80px;">受審核人</th><td style="width:110px;">'+esc(n.auditee_name||'')+'</td>'
              + '<th style="width:80px;">稽核日期</th><td>'+dispDate(n.audit_date)+'</td></tr>'

              /* ---- 稽核員段（只有稽核員一個章：受審查單位主管已依使用者要求取消） ---- */
              + '<tr><td colspan="6" class="l sig-cell" style="height:170px;vertical-align:top;">'
              + stampBR([['稽核員', n.auditor_id, n.auditor_name, n.auditor_date]])
              + '<div style="display:inline-block;border:1px solid #000;padding:2px 10px;margin-bottom:8px;">'
              + '不合格類型: '+esc(n.type_label||'')+'</div>'
              + sh('不合格事實描述:') + pre(n.fact)
              + '<div style="margin-top:8px;" class="pre"><b>違反條文:</b> '+esc(n.clause_ref||'')+'</div>'
              + '<div style="clear:both;"></div>'
              + '</td></tr>'

              /* 要求完成期限之後是受稽單位要填的部分，用一條粗線分開 */
              + '<tr><td class="l" colspan="6" style="border-bottom:2px solid #000;">要求完成期限: '
              + (n.due_date?dispDate(n.due_date):'')+'</td></tr>'

              /* ---- 受稽單位段（原因分析／糾正／預防，紙本是同一大格） ---- */
              + '<tr><td colspan="6" class="l sig-cell" style="height:270px;vertical-align:top;">'
              + stampBR([['責任主管', n.resp_id, n.resp_name, n.resp_date]])
              + sh('原因分析:') + pre(n.cause)
              + sh('糾正措施及完成時間:') + pre(n.corrective)
              + (n.corrective_due ? '<div class="pre">完成時間：'+dispDate(n.corrective_due)+'</div>' : '')
              + sh('預防措施及預計完成時間:') + pre(n.preventive)
              + (n.preventive_due ? '<div class="pre">預計完成時間：'+dispDate(n.preventive_due)+'</div>' : '')
              + '<div style="clear:both;"></div>'
              + '</td></tr>'

              /* ---- 驗證段：驗證描述＋結案日期＋稽核組長章，全部在同一格 ---- */
              + '<tr><td colspan="6" class="l sig-cell sig-cell2" style="height:190px;vertical-align:top;">'
              /* 管理代表的章放在稽核組長**左側**（2026-09-21 使用者要求）；
                 管理代表是全站組織角色綁定的那一位，不是按下結案的人。 */
              + stampBR([['管理代表', n.mgr_id, n.mgr_name, n.mgr_date],
                         ['稽核組長', n.leader_id, n.leader_name, n.leader_date]])
              + sh('糾正和預防措施執行狀況驗證描述:') + pre(n.verify_desc)
              // 結案日期＝稽核組長蓋章日期，依使用者指定放在這一格的左下角
              + '<div style="margin-top:8px;font-weight:bold;">結案日期: '
              + (n.leader_date ? dispDate(n.leader_date) : '') + '</div>'
              + '</td></tr>'
              + '</table>';
            logPrint('內稽不符合通知單 '+(n.nc_no||('#'+id)), 'ia_nc', id);
            iaPrintWindow('內稽不符合通知單 '+(n.nc_no||''), h, '', m.doc_no, false);
        }, [{id:n.auditor_id, date:n.auditor_date}, {id:n.resp_id, date:n.resp_date},
            {id:n.leader_id, date:n.leader_date}, {id:n.mgr_id, date:n.mgr_date}]);
    });
}
$('#btnNcPrint').on('click', function(){ if (NC) printNc(NC.nc_id); });

/* ---------- ⑤ 稽核報告表 2-GM-06-08 ---------- */
$('#btnReportPrint').on('click', function(){
    if (!REPORT) { alert('請先載入資料'); return; }
    var r = REPORT.report;
    var biz = (r && (r.approver_date || r.maker_date)) || META.today;
    withPrintMeta('report', biz, {maker_id:(r&&r.maker_id)||'', maker_name:(r&&r.maker_name)||''}, function(m){
        var h = printHead(m);
        h += '<table class="ia-p"><thead>'
           + '<tr><th rowspan="2" style="width:90px;">受稽單位</th><th colspan="3">缺點數</th>'
           + '<th colspan="2">受稽時間</th><th rowspan="2" style="width:96px;">稽核員</th>'
           + '<th rowspan="2" style="width:110px;">預定完成改善時間</th></tr>'
           + '<tr><th style="width:34px;">主</th><th style="width:34px;">次</th><th style="width:34px;">觀</th>'
           + '<th style="width:82px;">日期</th><th style="width:56px;">時間</th></tr></thead><tbody>';
        var rows = reportRows();
        rows.forEach(function(d){
            h += '<tr><td>'+esc(d.dept_name)+'</td>'
              + '<td>'+(d.major||'')+'</td><td>'+(d.minor||'')+'</td><td>'+(d.observe||'')+'</td>'
              + '<td>'+dispDate(d.audited_date)+'</td><td>'+esc(d.audited_time||'')+'</td>'
              + '<td>'+auditorLines(d, 90)+'</td><td>'+dispDate(d.improve_due)+'</td></tr>';
        });
        // 紙本這張表下半部是空白列，保留可手寫的空間
        for (var i=rows.length; i<12; i++){
            h += '<tr><td style="height:18px;">&nbsp;</td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>';
        }
        h += '</tbody></table>';
        var recs = REPORT.records||[];
        h += '<div style="margin-top:8px;font-size:12px;"><b>缺點記錄</b></div>'
           + '<div class="ia-note" style="border:1px solid #333;padding:6px 8px;min-height:70px;">';
        /* 一列＝「部門-IA單號　表單編號　表單中文名稱」（2026-09-18 使用者要求）。
           **不印不合格事實**——那段字很長、印成一行看不出重點，要看內容請開該張不符合通知單。
           表單名稱由編號即時回查 as_document（不另存一份，表單改名這裡才不會對不起來）。 */
        recs.forEach(function(x){
            var parts = [x.dept_name+'-'+x.nc_no];
            if (x.form_no)   parts.push(x.form_no);
            if (x.form_name) parts.push(x.form_name);
            h += esc(parts.join('　'))+'\n';
        });
        if (r && r.extra_note) h += esc(r.extra_note);
        h += '</div>';
        // 2026-08-27 使用者要求：稽核報告表不需要核准／審查／製表區塊與圖章（紙本本來就沒有）
        logPrint(YEAR+' 年度稽核報告表', 'ia_report', (r&&r.report_id)||'');
        iaPrintWindow(YEAR+' 年度稽核報告表', h, '', m.doc_no, false);
    });
});
</script>
<script>
/* ============================ 受稽單位群組 ============================ */
var UNITS = [];
$('#btnUnitSetting').on('click', loadUnits);
function loadUnits(){
    $.getJSON(API, {action:'unit_list'}, function(res){
        if (!res.ok) { alert(res.error||'載入失敗'); return; }
        UNITS = res.units||[];
        var h = '';
        UNITS.forEach(function(u){
            if (!u.is_group) return;                       // 表格只列群組；沒綁群組的部門本來就各自獨立
            h += '<tr><td class="l"><b>'+esc(u.name)+'</b></td>'
              + '<td class="l">'+esc((u.members||[]).join('、'))+'</td>'
              + '<td>'+esc(unitDeptName(u.key))+'</td>'
              + '<td><span class="ia-op" onclick="openUnitEdit('+u.unit_id+')"><i class="fa fa-edit"></i> 編輯</span>'
              + '<span class="ia-op danger" onclick="delUnit('+u.unit_id+',\''+esc(u.name).replace(/'/g,"\\'")+'\')"><i class="fa fa-times"></i> 解散</span>'
              + '</td></tr>';
        });
        $('#unitBody').html(h || '<tr><td colspan="4" class="ia-empty">還沒有設定群組，目前每個部門各自是一個受稽單位</td></tr>');
        openMask('unitMask');
    });
}
function unitDeptName(id){
    var n = '';
    (META.depts||[]).forEach(function(d){ if (+d.id === +id) n = d.name; });
    return n;
}
$('#btnUnitNew').on('click', function(){ openUnitEdit(0); });
function openUnitEdit(unitId){
    // 只有 unit_id>0 才是群組；沒綁群組的單一部門 unit_id 也是 0，
    // 不加這個條件的話「新增群組」會誤抓到清單裡最後一個單一部門，把它預先勾起來（實測踩過）
    var u = null;
    if (+unitId > 0) UNITS.forEach(function(x){ if (+x.unit_id === +unitId) u = x; });
    $('#unitEditTitle').text(unitId ? ('編輯受稽單位　'+(u?u.name:'')) : '新增受稽單位群組');
    $('#ueName').val(u ? u.name : '');
    $('#btnUnitSave').data('unit-id', unitId);
    // 已被「其他」群組收編的部門不能再選（一個部門只能屬於一個受稽單位）
    var takenBy = {};
    UNITS.forEach(function(x){
        if (!x.is_group || +x.unit_id === +unitId) return;
        (x.dept_ids||[]).forEach(function(d){ takenBy[d] = x.name; });
    });
    var mine = {};
    if (u) (u.dept_ids||[]).forEach(function(d){ mine[d] = 1; });
    var h = '';
    (META.depts||[]).forEach(function(d){
        var lock = takenBy[d.id];
        h += '<label class="pick-row"'+(lock?' style="opacity:.55;"':'')+'>'
           + '<input type="checkbox" class="ueChk" value="'+d.id+'"'
           + (mine[d.id]?' checked':'') + (lock?' disabled':'') + '>'
           + '<span class="pk-name">'+esc(d.name)+'</span>'
           + '<span class="pk-sub">'+(lock ? ('已屬於「'+esc(lock)+'」') : '')+'</span>'
           + '</label>';
    });
    $('#uePick').html(h);
    clearErrs($('#unitEditMask'));
    renderUeMain();
    openMask('unitEditMask');
}
/* 代表部門的候選＝目前勾選的那些部門（不是全部部門，否則會選到不在群組裡的） */
function renderUeMain(){
    var cur = $('#ueMain').val();
    var ids = $('.ueChk:checked').map(function(){ return +$(this).val(); }).get();
    var h = '<option value="">（請選擇）</option>';
    ids.forEach(function(id){
        h += '<option value="'+id+'"'+(String(cur)===String(id)?' selected':'')+'>'+esc(unitDeptName(id))+'</option>';
    });
    $('#ueMain').html(h);
    if (!$('#ueMain').val() && ids.length) $('#ueMain').val(ids[0]);   // 預設第一個（通常是最上層部門）
}
$(document).on('change', '.ueChk', renderUeMain);
$('#btnUnitSave').on('click', function(){
    clearErrs($('#unitEditMask'));
    var unitId = +$(this).data('unit-id') || 0;
    var ids = $('.ueChk:checked').map(function(){ return +$(this).val(); }).get();
    var ok = true;
    ok = fieldErr($('#ueName'), 'errUeName', $('#ueName').val().trim() ? '' : '請填單位名稱') && ok;
    if (ids.length < 2) { $('#errUePick').addClass('on').text('群組至少要有兩個部門（只有一個部門不需要設群組）'); ok = false; }
    ok = fieldErr($('#ueMain'), 'errUeMain', $('#ueMain').val() ? '' : '請選代表部門') && ok;
    if (!ok) return;
    $.post(API, {action:'unit_save', unit_id:unitId, unit_name:$('#ueName').val(),
                 main_dept_id:$('#ueMain').val(), dept_ids:JSON.stringify(ids)}, function(res){
        if (!res.ok) { alert(res.error||'儲存失敗'); return; }
        closeMask('unitEditMask');
        loadMeta(function(){ loadUnits(); loadPane(currentPane()); });
    }, 'json');
});
function delUnit(unitId, name){
    if (!confirm('解散「'+name+'」這個受稽單位群組？\n解散後底下各部門會各自變回獨立的受稽單位，既有的計畫表、通知單、不符合單資料不會被刪除。')) return;
    $.post(API, {action:'unit_delete', unit_id:unitId}, function(res){
        if (!res.ok) { alert(res.error||'解散失敗'); return; }
        loadMeta(function(){ loadUnits(); loadPane(currentPane()); });
    }, 'json');
}

/* ============================ 稽核員／陪檢員資格名單 ============================ */
/* 名單認到「部門＋職稱」（QJOBS，鍵 'deptId:posId'），不認人名——
   人員會異動，但職稱不會；人名一律在建稽核通知單的當下即時抓（使用者要求 2026-09-09）。 */
var QMAP = {}, QKIND = 'auditor', QJOBS = [], QUSERS = {}, QPOSTS = [], QASTERMS = [];
$('#btnQualify').on('click', function(){
    $.getJSON(API, {action:'qualify_get'}, function(res){
        if (!res.ok) { alert(res.error||'載入失敗'); return; }
        QMAP = res.map||{}; QJOBS = res.jobs||[];
        QUSERS = res.users||{auditor:[],escort:[]}; QPOSTS = res.posts||[]; QASTERMS = res.as_terms||[];
        QKIND = 'auditor';
        $('.q-tab').removeClass('on'); $('.q-tab[data-kind=auditor]').addClass('on');
        $('#qFilter').val('');
        renderQualify();
        openMask('qualifyMask');
    });
});
$(document).on('click', '.q-tab', function(){
    // 切分頁前先把目前這一頁的勾選記回 QMAP，不然切回來會發現剛剛勾的不見了。
    // 一定要走 qSyncMap()（內部用 qCheckedIds()）：值是「deptId:posId」字串，
    // 用 +val() 轉數字會全部變成 NaN，存進 QMAP 之後切回來就整份名單都沒勾（2026-08-26 使用者回報的症狀）。
    qSyncMap();
    $('.q-tab').removeClass('on'); $(this).addClass('on');
    QKIND = $(this).data('kind');
    renderQualify();
});
/* 一個「部門＋職稱」一列。**右側人名只是現況顯示、不是設定值**，
   所以人員異動時這份名單不必動——建通知單時後端會重新抓該職務目前的在職人員。 */
function renderQualify(){
    var picked = {};
    (QMAP[QKIND]||[]).forEach(function(k){ picked[k] = 1; });
    var kw = $('#qFilter').val().trim().toLowerCase();
    var h = '', shown = 0;
    (QJOBS||[]).forEach(function(j){
        var names = (j.people||[]).join('、');
        var hay = ((j.dept_name||'')+' '+(j.position_name||'')+' '+names).toLowerCase();
        if (kw && hay.indexOf(kw) < 0) return;
        shown++;
        var key = j.job_key;
        // 欄位順序固定「部門/職稱/人員」（ai-rules/08 第五節鐵則6）
        h += '<label class="pick-row"><input type="checkbox" class="qChk" value="'+esc(key)+'"'
           + (picked[key]?' checked':'')+'>'
           + '<span class="pk-name">'+esc(j.dept_name||'')+'</span>'
           + '<span class="pk-name" style="flex:0 0 90px;">'+esc(j.position_name||'')+'</span>'
           + '<span class="pk-sub" style="color:#5b3a1e;">'
           + (names ? ('目前：'+esc(names))
                    : '<span style="color:#C4442D;">目前無人在任（有人接任就自動有資格）</span>')
           + '</span></label>';
    });
    $('#qPick').html(h || '<div class="ia-empty">沒有符合的職務</div>');
    updateQCount(shown);
    renderQualifyUsers();
}
/* 「職位＋指定人員」清單（可設任期）。代理人暫代、AS 負責人以外的個案都走這裡。 */
function renderQualifyUsers(){
    var rows = QUSERS[QKIND] || [];
    // 已經加進來的職務不再出現在候選（同一個職務重複設沒有意義）
    var used = {};
    rows.forEach(function(r){ used[r.post_key3] = 1; });
    var oh = '<option value="">（請選人員職務）</option>';
    QPOSTS.forEach(function(p){
        if (used[p.post_key3]) return;
        oh += '<option value="'+esc(p.post_key3)+'">'+esc(p.display||((p.dept_name||'')+'　'+(p.position_name||'')+'　'+p.user_cname))+'</option>';
    });
    $('#quAddPost').html(oh);

    var h = '';
    rows.forEach(function(r, i){
        h += '<tr data-i="'+i+'">'
          + '<td>'+esc(r.dept_name||'')+'</td><td>'+esc(r.position_name||'')+'</td>'
          + '<td>'+esc(r.user_name||'')+(r.resigned?' <span style="color:#C4442D;" title="目前已離職；任期內的舊單據仍可指派">（已離職）</span>':'')+'</td>'
          + '<td><input type="date" class="qur" data-f="start_date" value="'+esc(r.start_date||'')+'" style="width:100%;border:1px solid #D8BE93;border-radius:3px;padding:2px;font-size:12px;"></td>'
          + '<td><input type="date" class="qur" data-f="end_date" value="'+esc(r.end_date||'')+'" style="width:100%;border:1px solid #D8BE93;border-radius:3px;padding:2px;font-size:12px;"></td>'
          + '<td><input type="text" class="qur" data-f="note" value="'+esc(r.note||'')+'" placeholder="例：代理葉卿雅（請假）" style="width:100%;border:1px solid #D8BE93;border-radius:3px;padding:2px 5px;font-size:12px;"></td>'
          + '<td><span class="ia-op danger" onclick="quDel('+i+')"><i class="fa fa-times"></i></span></td>'
          + '</tr>';
    });
    $('#quBody').html(h || '<tr><td colspan="7" class="ia-empty">沒有指定人員（只靠下方的「依職位」判定）</td></tr>');

    // AS 文件負責人自動具備稽核員資格：唯讀說明，讓使用者知道為什麼名單上沒設的人也挑得到
    if (QKIND === 'auditor' && QASTERMS.length) {
        var t = QASTERMS.map(function(x){
            return esc(x.name||('#'+x.user_id)) + '（'
                 + (x.start_date ? dispDate(x.start_date) : '最早') + ' ～ '
                 + (x.end_date ? dispDate(x.end_date) : '至今') + '）';
        }).join('、');
        $('#qAsBox').html('<b>AS 文件負責人自動具備稽核員資格</b>（不必在這裡設定）：' + t
            + '　<span style="color:#8a6d45;">任期請到 AS 文件管理 → 系統設定 → 結構總覽列印 修改。</span>').show();
    } else { $('#qAsBox').hide().html(''); }
}
$(document).on('change', '.qur', function(){
    var i = +$(this).closest('tr').data('i');
    (QUSERS[QKIND]||[])[i][$(this).data('f')] = $(this).val();
});
function quDel(i){ (QUSERS[QKIND]||[]).splice(i,1); renderQualifyUsers(); }
$('#btnQuAdd').on('click', function(){
    var key = $('#quAddPost').val();
    if (!key) { alert('請先選要指定的人員職務'); return false; }
    var p = null;
    QPOSTS.forEach(function(x){ if (x.post_key3 === key) p = x; });
    if (!p) return false;
    if (!QUSERS[QKIND]) QUSERS[QKIND] = [];
    QUSERS[QKIND].push({post_key3:p.post_key3, user_id:p.id, user_name:p.user_cname,
                        dept_id:p.dept_id, dept_name:p.dept_name,
                        position_id:p.position_id, position_name:p.position_name,
                        start_date:'', end_date:'', note:'', resigned:(+p.is_former===1)});
    renderQualifyUsers();
    return false;
});
/** 目前畫面上勾起來的職務鍵 */
function qCheckedIds(){
    return $('#qPick .qChk:checked').map(function(){ return $(this).val(); }).get();
}
/** 目前畫面上「有畫出來」的職務鍵（被關鍵字篩掉的不算） */
function qVisibleKeys(){
    var v = {};
    $('#qPick .qChk').each(function(){ v[$(this).val()] = 1; });
    return v;
}
/** 目前還存在的職務鍵（部門或職稱被刪掉了才會不在裡面；沒人在任的職務仍算存在） */
function qKnownKeys(){
    var m = {};
    (QJOBS||[]).forEach(function(j){ m[j.job_key] = j; });
    return m;
}
/** 把畫面上的勾選合併回 QMAP。**一定要合併不能直接覆寫**——被關鍵字篩掉的列根本沒畫出來，
    直接 QMAP[QKIND]=qCheckedIds() 會把那些職務默默從名單裡刷掉（打完關鍵字就少一批）。 */
function qSyncMap(){
    var visible = qVisibleKeys();
    var keep = (QMAP[QKIND]||[]).filter(function(k){ return !visible[k]; });
    QMAP[QKIND] = keep.concat(qCheckedIds());
}
function updateQCount(shown){
    var visible = qVisibleKeys(), known = qKnownKeys();
    var prev = QMAP[QKIND] || [];
    var hidden = prev.filter(function(k){ return !visible[k] && known[k]; });   // 被篩選藏起來、仍有效
    var stale  = prev.filter(function(k){ return !known[k]; });                 // 部門或職稱已不存在
    var keys = hidden.concat(qCheckedIds());
    var heads = 0, vacant = 0;                     // 這些職務目前總共涵蓋幾個人
    keys.forEach(function(k){
        var n = known[k] ? (known[k].people_count||0) : 0;
        heads += n; if (!n) vacant++;
    });
    var kindLab = (META.qualify_kinds||{})[QKIND] || QKIND;
    $('#qCount').html(esc(kindLab + '：已選 ' + keys.length + ' 個職務（目前涵蓋 ' + heads + ' 人'
        + (vacant ? ('，其中 ' + vacant + ' 個職務目前無人在任') : '') + '）'
        + (shown != null ? ('／顯示 ' + shown + ' 列') : '')
        + (hidden.length ? ('　其中 ' + hidden.length + ' 個被關鍵字篩選隱藏，儲存時一併保留') : '')
        + (keys.length === 0 ? '　不限制，全體在職員工的所有職務都可指派' : ''))
        + (stale.length ? ('　<span style="color:#C4442D;">另有 ' + stale.length
            + ' 個職務的部門或職稱已不存在，儲存時會自動移除</span>') : ''));
}
$(document).on('change', '.qChk', function(){ updateQCount(); });
$('#qFilter').on('input', function(){
    qSyncMap();
    renderQualify();
});
$('#qAll').on('click', function(){ $('#qPick .qChk').prop('checked', true); updateQCount(); return false; });
$('#qNone').on('click', function(){ $('#qPick .qChk').prop('checked', false); updateQCount(); return false; });
$('#btnQualifySave').on('click', function(){
    // 篩選中被藏起來的職務也要一起送，否則打了關鍵字再存會把沒顯示的整批刷掉
    var visible = qVisibleKeys(), known = qKnownKeys();
    var checked = qCheckedIds();
    var keep = (QMAP[QKIND]||[]).filter(function(k){ return !visible[k] && known[k]; });
    // 部門或職稱已不存在的舊資料不送出去，名單裡卡一筆舊資料不該讓整份存不了
    var stale = (QMAP[QKIND]||[]).filter(function(k){ return !known[k]; }).length;
    var ids = keep.concat(checked);
    // 指定人員那一段和職位一起送（同一分頁的設定要一次存完，不然使用者會以為只存了一半）
    var urs = (QUSERS[QKIND]||[]).map(function(r){
        return {post_key3:r.post_key3, start_date:r.start_date||'', end_date:r.end_date||'', note:r.note||''};
    });
    var bad = urs.filter(function(r){ return r.start_date && r.end_date && r.start_date > r.end_date; });
    if (bad.length) { alert('指定人員的任期起日不可晚於迄日，請修正後再儲存'); return; }
    $.post(API, {action:'qualify_save', kind:QKIND, job_keys:JSON.stringify(ids),
                 user_rules:JSON.stringify(urs)}, function(res){
        if (!res.ok) { alert(res.error||'儲存失敗'); return; }
        QMAP[QKIND] = ids;
        alert((META.qualify_kinds||{})[QKIND] + ' 名單已儲存（職位 ' + res.count + ' 個'
              + '、指定人員 ' + (res.user_count||0) + ' 位）'
              + ((res.count === 0 && !res.user_count) ? '\n目前＝不限制，全體員工的所有職務都可指派。' : '')
              + (stale ? ('\n另清除 ' + stale + ' 個部門或職稱已不存在的舊設定。') : ''));
        renderQualify();
        loadMeta();
    }, 'json');
});
</script>

<script>
/* ============================ 稽核小組（年度，2026-09-16 使用者交辦） ============================
   建稽核通知單前先組好這一年的小組；自動建立會議紀錄時與會人員＝小組成員、主席固定為稽核組長。 */
var TEAM = {year:0, members:[], candidates:[], years:[]};
$('#btnTeam').on('click', function(){ openTeam(YEAR); });
/* $baseDate 有給就是「試算」——使用者剛改了基準日、還沒按儲存，畫面要先依新日期重算。
   markDirty＝重載後仍標成「未儲存」，否則使用者會以為日期已經存進去了。 */
function openTeam(year, baseDate, markDirty){
    var q = {action:'team_get', year:year};
    if (baseDate) q.base_date = baseDate;
    $.getJSON(API, q, function(res){
        if (!res.ok) { alert(res.error||'載入失敗'); return; }
        TEAM = {year:+res.year, members:res.members||[], candidates:res.candidates||[], years:res.years||[],
                base_date:res.base_date||'', base_default:res.base_default||'', asof:res.asof||'',
                _dirty:!!markDirty};
        var yh = '';
        (META.years||[]).forEach(function(y){ yh += '<option value="'+y+'">'+y+' 年度</option>'; });
        $('#teamYear').html(yh).val(TEAM.year);
        var rh = '';
        $.each(res.roles||{}, function(k,v){ rh += '<option value="'+k+'">'+esc(v)+'</option>'; });
        $('#teamAddRole').html(rh).val('auditor');
        // 日期欄顯示「實際採用的那一天」；沒自訂過就顯示系統推算值並在旁邊註明
        $('#teamBaseDate').val(inputDate(TEAM.asof || TEAM.base_default));
        renderTeamBaseNote();
        renderTeam();
        openMask('teamMask');
    });
}
/** 基準日旁邊的說明：是自訂的還是系統推算的，以及還沒存的話要提醒。
    **「未儲存」一定要跟「已存的值」比，不可以跟 TEAM.asof 比**——試算重載之後 asof 就是那個
    試算日期，兩者永遠相等，於是畫面停在「系統推算（2025.12.31）」但欄位明明是 2025-11-03
    （2026-09-17 無頭瀏覽器實測抓到；這種說明文字與實際值不符最容易讓人以為已經存好了）。 */
function renderTeamBaseNote(){
    var cur   = $('#teamBaseDate').val();
    var saved = inputDate(TEAM.base_date || TEAM.base_default);   // 目前真正存在 DB 的效果
    var t = '';
    if (cur !== saved)      t = '<span style="color:#C4442D;">已改成 ' + esc(dispDate(cur)) + '（試算中，按「儲存小組名單」才會存起來）</span>';
    else if (TEAM.base_date) t = '自訂（' + esc(dispDate(TEAM.base_date)) + '）';
    else                     t = '系統推算（' + esc(dispDate(TEAM.base_default)) + '）';
    $('#teamBaseNote').html(t);
}
$('#teamYear').on('change', function(){
    // 換年度＝換一份名單，沒存的先問一次（直接切走會讓剛加的人默默不見）
    if (TEAM._dirty && !confirm('這一年度的小組還沒儲存，切換年度會放棄剛才的修改，確定嗎？')) {
        $(this).val(TEAM.year); return;
    }
    openTeam(+$(this).val());
});
/* 改基準日＝「當時是誰在那個職務上」整個變了，候選清單與名單上每個人的部門職稱都要重算。
   這裡只做試算（不寫 DB），按「儲存小組名單」才連同日期一起存。
   **重算會把名單重新讀回來**，所以名單本身有沒存的修改要先問一次，不可以默默蓋掉。 */
var TEAM_BASE_PREV = '';
$('#teamBaseDate').on('focus', function(){ TEAM_BASE_PREV = $(this).val(); });
$('#teamBaseDate').on('change', function(){
    var d = $(this).val() || inputDate(TEAM.base_default);
    if (TEAM._dirty && !confirm('名單還沒儲存。改基準日會依新日期重新載入，剛才對名單的修改會被放棄，確定嗎？')) {
        $(this).val(TEAM_BASE_PREV || inputDate(TEAM.asof)); renderTeamBaseNote(); return;
    }
    // 跟「目前存在 DB 的效果」相同就不算未儲存（例：按了預設鈕、或改一改又改回原本那天）
    var same = (d === inputDate(TEAM.base_date || TEAM.base_default));
    openTeam(TEAM.year, d, !same);
});
$('#btnTeamBaseReset').on('click', function(){
    $('#teamBaseDate').val(inputDate(TEAM.base_default)).trigger('change');
    return false;
});
function renderTeam(){
    /* 已加入的「職務」不再出現在候選——**去重的單位是職務不是人**（2026-09-16 使用者回報）。
       同一個人本來就會用不同職務各掛一筆：何沐桐主職技術課工程師、兼生管組組長，
       資格名單把「技術課 工程師」單獨指定成陪檢員（他負責技術課的資料），那是兩個身分、兩筆。
       原本按人去重＝他只要先以組長身分進了小組，技術課工程師那一筆連選項都看不到。 */
    var used = {};
    TEAM.members.forEach(function(m){ used[String(m.post_key3)] = 1; });
    var oh = '<option value="">（請選要加入的人員職務）</option>';
    TEAM.candidates.forEach(function(p){
        if (used[String(p.post_key3)]) return;
        oh += '<option value="'+esc(p.post_key3)+'">'+esc(p.display||((p.dept_name||'')+'　'+(p.position_name||'')+'　'+p.user_cname))+'</option>';
    });
    $('#teamAddPost').html(oh);

    var h = '';
    TEAM.members.forEach(function(m, i){
        var rh = '';
        $.each((META.team_roles||{leader:'稽核組長',auditor:'稽核員',escort:'陪檢員'}), function(k,v){
            rh += '<option value="'+k+'"'+(m.role===k?' selected':'')+'>'+esc(v)+'</option>';
        });
        h += '<tr data-i="'+i+'">'
          + '<td><select class="tmr" data-f="role" style="width:100%;border:1px solid #D8BE93;border-radius:3px;font-size:12px;">'+rh+'</select></td>'
          + '<td>'+esc(m.dept_name||'')+'</td><td>'+esc(m.position_name||'')+'</td>'
          + '<td>'+esc(m.user_name||'')+(m.missing?' <span style="color:#C4442D;" title="這個人在該年度已不在這個職務上（離職或調動），儲存時會被擋下，請改選現在的職務">⚠</span>':'')+'</td>'
          + '<td><input type="text" class="tmr" data-f="note" value="'+esc(m.note||'')+'" placeholder="例：代理葉卿雅" style="width:100%;border:1px solid #D8BE93;border-radius:3px;padding:2px 5px;font-size:12px;"></td>'
          + '<td><span class="ia-op danger" onclick="teamDel('+i+')"><i class="fa fa-times"></i></span></td>'
          + '</tr>';
    });
    $('#teamBody').html(h || '<tr><td colspan="6" class="ia-empty">這一年度還沒有稽核小組，請從上方加入成員，或從其他年度複製</td></tr>');

    var ch = '<option value="">（選擇來源年度）</option>';
    (TEAM.years||[]).forEach(function(y){ if (+y !== +TEAM.year) ch += '<option value="'+y+'">'+y+' 年度</option>'; });
    $('#teamCopyFrom').html(ch);

    var leaders = TEAM.members.filter(function(m){ return m.role==='leader'; }).length;
    var heads = {}; TEAM.members.forEach(function(m){ heads[String(m.user_id)] = 1; });
    var nHead = Object.keys(heads).length;
    $('#teamMsg').html(esc(TEAM.year + ' 年度共 ' + TEAM.members.length + ' 筆職務'
        + (nHead === TEAM.members.length ? ('（' + nHead + ' 位成員）')
                                         : ('／' + nHead + ' 位成員　有人用了不只一個職務，會議紀錄仍只列一次'))
        + '　基準日 ' + dispDate(TEAM.asof || $('#teamBaseDate').val()))
        + (leaders === 1 ? '' : '　<span style="color:#C4442D;">稽核組長目前有 ' + leaders
             + ' 位（必須剛好一位，會議紀錄的主席固定用他）</span>'));
}
$(document).on('change', '.tmr', function(){
    var i = +$(this).closest('tr').data('i');
    TEAM.members[i][$(this).data('f')] = $(this).val();
    TEAM._dirty = true;
    if ($(this).data('f') === 'role') renderTeam();
});
function teamDel(i){ TEAM.members.splice(i,1); TEAM._dirty = true; renderTeam(); }
$('#btnTeamAdd').on('click', function(){
    var key = $('#teamAddPost').val();
    if (!key) { alert('請先選要加入的人員職務'); return; }
    var p = null;
    TEAM.candidates.forEach(function(x){ if (x.post_key3 === key) p = x; });
    if (!p) return;
    var role = $('#teamAddRole').val() || 'auditor';
    if (role === 'leader' && TEAM.members.some(function(m){ return m.role==='leader'; })) {
        alert('稽核組長只能有一位，請先把原本那位改成稽核員或移除'); return;
    }
    TEAM.members.push({role:role, user_id:p.id, user_name:p.user_cname, dept_id:p.dept_id,
                       dept_name:p.dept_name, position_id:p.position_id, position_name:p.position_name,
                       post_key3:p.post_key3, note:'', missing:0});
    TEAM._dirty = true;
    renderTeam();
    return false;
});
$('#btnTeamCopy').on('click', function(){
    var from = $('#teamCopyFrom').val();
    if (!from) { alert('請先選來源年度'); return false; }
    if (TEAM.members.length && !confirm(TEAM.year + ' 年度目前已有 ' + TEAM.members.length
        + ' 位成員，從 ' + from + ' 年度複製會**整批取代**現有名單，確定嗎？')) return false;
    $.post(API, {action:'team_copy', from_year:from, to_year:TEAM.year}, function(res){
        if (!res.ok) { alert(res.error||'複製失敗'); return; }
        TEAM.members = res.members||[]; TEAM._dirty = false;
        renderTeam();
        alert('已從 ' + from + ' 年度複製 ' + res.count + ' 筆職務。'
            + '\n（能不能複製過來是用 ' + TEAM.year + ' 年度自己的基準日 ' + dispDate(TEAM.asof) + ' 判定的）'
            + ((res.skipped && res.skipped.length) ? ('\n\n下列成員已不在原職務上（離職或調動），沒有複製過來：\n'
                 + res.skipped.join('\n')) : ''));
        loadMeta();
    }, 'json');
    return false;
});
$('#btnTeamSave').on('click', function(){
    var ms = TEAM.members.map(function(m){ return {post_key3:m.post_key3, role:m.role, note:m.note||''}; });
    // 基準日跟名單同一張表單，一起送（分兩次存會出現「名單存了、日期沒存」）。
    // 等於系統推算值時送空字串＝不自訂，往後年度推進會自動跟著走。
    var bd = $('#teamBaseDate').val() || '';
    if (bd === inputDate(TEAM.base_default)) bd = '';
    $.post(API, {action:'team_save', year:TEAM.year, members:JSON.stringify(ms), base_date:bd}, function(res){
        if (!res.ok) { alert(res.error||'儲存失敗'); return; }
        TEAM.members = res.members||[]; TEAM._dirty = false;
        TEAM.base_date = res.base_date||''; TEAM.asof = res.asof||TEAM.asof;
        $('#teamBaseDate').val(inputDate(TEAM.asof));
        renderTeamBaseNote();
        renderTeam();
        alert(TEAM.year + ' 年度稽核小組已儲存（' + res.count + ' 筆職務）\n基準日：' + dispDate(TEAM.asof)
              + (TEAM.base_date ? '（自訂）' : '（系統推算）'));
        loadMeta();
    }, 'json');
});
/* 稽核通知單上的提示：這一年度還沒建小組時講清楚會影響什麼，並給一鍵開啟 */
function teamHintForCase(bizDate){
    var y = parseInt(String(bizDate||META.today).substr(0,4),10) || YEAR;
    var has = ((META.team_years||[]).indexOf(y) >= 0);
    var $b = $('#caseTeamHint');
    if (!$b.length) return;
    if (has) { $b.hide().html(''); return; }
    $b.html('<b>' + y + ' 年度還沒有建立稽核小組。</b>'
        + '建議先建立——之後「自動建立會議紀錄」的與會人員就是小組成員、主席固定為稽核組長；'
        + '沒有小組時會退回用這張通知單上各受稽單位的稽核員與陪檢員。'
        + ' <a href="#" id="caseTeamOpen" style="color:#B45309;text-decoration:underline;">現在建立／從其他年度複製</a>')
      .show();
}
$(document).on('click', '#caseTeamOpen', function(){
    var y = parseInt(String(caseBizDate(null)).substr(0,4),10) || YEAR;
    openTeam(y);
    return false;
});
</script>

<script>
/* ============================ 稽核範本設定 ============================ */
var TPLS = [];
/* 由 META.depts 的 parent_id 算出某部門的子樹（含自己）——候選部門是「含子部門」的 */
function deptSubtree(deptId){
    var out = [+deptId], queue = [+deptId];
    while (queue.length) {
        var cur = queue.shift();
        (META.depts||[]).forEach(function(d){
            if (+d.parent_id === cur && out.indexOf(+d.id) < 0) { out.push(+d.id); queue.push(+d.id); }
        });
    }
    return out;
}
/* 常用的起始主過程（來自 2-GM-06-02 備註的三類過程）。只是輸入建議，可以自己打別的。 */
var IA_PROC_SUGGEST = [
    '客戶需求檢討','開發','訂單/合約審查','生產','倉儲出貨','客戶回饋',
    '文件/記錄管理','人力資源訓練','教育訓練資料','文件留存','不符合管理','資料分析',
    '內部稽核','矯正/預防措施管理','持續改善','管理責任',
    '採購流程','供應商管理','IQC/FAI/IPQC/FQC','儀器/量具','機器/治具','生管',
    '型態(鑑別追溯)','特殊特性','生產流程'
];
/* 不可寫成 .on('click', loadTpls)：jQuery 會把事件物件當成第一個參數傳進去，
   loadTpls 的 cb 就變成 Event，執行到 cb() 直接 TypeError，跳窗開不起來。 */
$('#btnTplSetting').on('click', function(){ loadTpls(); });
function loadTpls(cb){
    $.getJSON(API, {action:'tpl_list'}, function(res){
        if (!res.ok) { alert(res.error||'載入失敗'); return; }
        TPLS = res.rows||[];
        var h = '';
        TPLS.forEach(function(t){
            var aWho = t.auditor_auto
                ? '　<span style="color:#8A5A2B;">→ 只有一位，自動帶入</span>'
                : ('　<span style="color:#a08356;">（' + (t.auditor_cands||[]).length + ' 位候選）</span>');
            var eWho = (t.escort_dept_ids||[]).length
                ? (t.escort_auto ? '　<span style="color:#8A5A2B;">→ 只有一位，自動帶入</span>'
                                 : '　<span style="color:#a08356;">（' + (t.escort_cands||[]).length + ' 位候選）</span>')
                : '';
            h += '<tr'+(+t.is_active?'':' style="opacity:.55;"')+'>'
              + '<td class="l"><b>'+esc(t.process_name)+'</b></td>'
              + '<td>'+esc(t.unit_name||'')+'</td>'
              + '<td class="l">'+esc((t.auditor_dept_names||[]).join('、'))+aWho+'</td>'
              + '<td class="l">'+(esc((t.escort_dept_names||[]).join('、'))||'<span style="color:#a08356;">（不指定）</span>')+eWho+'</td>'
              + '<td>'+(+t.is_active?'✓':'—')+'</td>'
              + '<td><span class="ia-op" onclick="openTplEdit('+t.tpl_id+')"><i class="fa fa-edit"></i> 編輯</span>'
              + '<span class="ia-op danger" onclick="delTpl('+t.tpl_id+',\''+esc(t.process_name).replace(/'/g,"\\'")+'\')"><i class="fa fa-times"></i></span>'
              + '</td></tr>';
        });
        $('#tplBody').html(h || '<tr><td colspan="6" class="ia-empty">還沒有範本，按右上「新增範本」建立</td></tr>');
        loadTplSets(function(){
            if (cb) { cb(); return; }
            openMask('tplMask');
        });
    });
}
$('#btnTplNew').on('click', function(){ openTplEdit(0); });
function openTplEdit(tplId){
    var t = null;
    if (+tplId > 0) TPLS.forEach(function(x){ if (+x.tpl_id === +tplId) t = x; });
    $('#tplEditTitle').text(tplId ? ('編輯範本　'+(t?t.process_name:'')) : '新增稽核範本');
    $('#btnTplSave').data('tpl-id', tplId);
    $('#teName').val(t ? t.process_name : '');
    $('#teNote').val(t ? (t.note||'') : '');
    $('#teActive').prop('checked', t ? !!+t.is_active : true);
    $('#teUnit').html(deptOptions(t ? t.unit_dept_id : '', '（請選擇）'));
    $('#teProcList').html(IA_PROC_SUGGEST.map(function(p){ return '<option value="'+esc(p)+'">'; }).join(''));
    renderTplDeptPick('teAuditorPick', t ? (t.auditor_dept_ids||[]) : []);
    renderTplDeptPick('teEscortPick',  t ? (t.escort_dept_ids||[])  : []);
    clearErrs($('#tplEditMask'));
    updateTplInfo();
    openMask('tplEditMask');
}
function renderTplDeptPick(boxId, cur){
    var picked = {};
    (cur||[]).forEach(function(d){ picked[d] = 1; });
    var h = '';
    (META.depts||[]).forEach(function(d){
        h += '<label class="pick-row"><input type="checkbox" class="teChk" data-box="'+boxId+'" value="'+d.id+'"'
           + (picked[d.id]?' checked':'')+'>'
           + '<span class="pk-name">'+esc(d.name)+'</span>'
           + '<span class="pk-sub"></span></label>';
    });
    $('#'+boxId).html(h);
}
/* 即時告訴使用者「這樣選會有幾位候選、會不會自動帶入」——設定當下就看得到結果，不用存完才知道 */
function updateTplInfo(){
    ['teAuditorPick','teEscortPick'].forEach(function(box){
        var kind = (box === 'teAuditorPick') ? 'auditor' : 'escort';
        var ids = $('#'+box+' .teChk:checked').map(function(){ return +$(this).val(); }).get();
        var pool = (kind === 'auditor') ? (META.auditors||[]) : (META.escorts||[]);
        var scope = {};
        ids.forEach(function(d){ deptSubtree(d).forEach(function(x){ scope[x]=1; }); });
        var cands = pool.filter(function(p){ return scope[+p.dept_id]; });
        var $t = $(box === 'teAuditorPick' ? '#teAuditorInfo' : '#teEscortInfo');
        if (!ids.length) { $t.text(kind === 'auditor' ? '尚未選擇部門' : '不選＝這個範本不指定陪檢員'); return; }
        if (cands.length === 0) {
            $t.html('<span style="color:#C4442D;">這些部門底下目前沒有具備'
                + (kind==='auditor'?'稽核員':'陪檢員') + '資格的人員，填表時會挑不到人</span>');
        } else if (cands.length === 1) {
            $t.html('<span style="color:#8A5A2B;">只有一位：'
                + esc(cands[0].dept_name+' '+cands[0].position_name+' '+cands[0].user_cname)
                + '　→ 填表時自動帶入</span>');
        } else {
            $t.text(cands.length + ' 位候選，填表時由填表人挑');
        }
    });
}
$(document).on('change', '.teChk', updateTplInfo);
$('#btnTplSave').on('click', function(){
    clearErrs($('#tplEditMask'));
    var ok = true;
    ok = fieldErr($('#teName'), 'errTeName', $('#teName').val().trim() ? '' : '請填稽核起始主過程') && ok;
    ok = fieldErr($('#teUnit'), 'errTeUnit', $('#teUnit').val() ? '' : '請選擇受稽單位') && ok;
    var aIds = $('#teAuditorPick .teChk:checked').map(function(){ return +$(this).val(); }).get();
    var eIds = $('#teEscortPick .teChk:checked').map(function(){ return +$(this).val(); }).get();
    if (!aIds.length) { $('#errTeAuditor').addClass('on').text('請至少選一個稽核員候選部門'); ok = false; }
    if (!ok) return;
    $.post(API, {action:'tpl_save', tpl_id:(+$(this).data('tpl-id')||0),
        process_name:$('#teName').val(), unit_dept_id:$('#teUnit').val(), note:$('#teNote').val(),
        is_active:$('#teActive').is(':checked')?1:'',
        auditor_dept_ids:JSON.stringify(aIds), escort_dept_ids:JSON.stringify(eIds)}, function(res){
        if (!res.ok) { alert(res.error||'儲存失敗'); return; }
        closeMask('tplEditMask');
        loadMeta(function(){ loadTpls(); });
    }, 'json');
});
function delTpl(tplId, name){
    if (!confirm('刪除範本「'+name+'」？\n已經填進稽核通知單的內容是當時的快照，不會受影響。')) return;
    $.post(API, {action:'tpl_delete', tpl_id:tplId}, function(res){
        if (!res.ok) { alert(res.error||'刪除失敗'); return; }
        loadMeta(function(){ loadTpls(); });
    }, 'json');
}

/* ============================ 稽核範本組合（2026-09-14 使用者交辦） ============================
   常一起稽核的那幾個範本存成一組，填通知單時選一次就整批帶入好幾列。
   組合只記「有哪些範本」，主過程／受稽單位／候選人員一律即時由範本算出來（鐵律4）。 */
var TPL_SETS = [];
/* 組合裡的範本一律**一列一個**，不用「、」串成一行（2026-09-16 使用者回報）。
   串成一行時「客戶需求與合約審查　→　業務課、工程圖面　→　技術課」很容易被讀成
   「工程圖面是業務課」——分隔號跟項目內的箭頭混在一起，眼睛分不出斷點在哪。
   每一列都自己帶「主過程　→　受稽單位（部門）」，所以不會再誤會。 */
function tplNameList(names){
    return (names||[]).map(function(n){
        return '<div style="white-space:nowrap;">・'+esc(n)+'</div>';
    }).join('');
}
function loadTplSets(cb){
    $.getJSON(API, {action:'tplset_list'}, function(res){
        if (!res.ok) { if (cb) cb(); return; }
        TPL_SETS = res.rows||[];
        var admin = <?= $perms['canAdmin'] ? 'true' : 'false' ?>;
        var h = '';
        TPL_SETS.forEach(function(t){
            var names = (t.tpl_names||[]);
            h += '<tr'+(+t.is_active?'':' style="opacity:.55;"')+'>'
              + '<td class="l"><b>'+esc(t.set_name)+'</b>'
              + (t.note ? '<div style="font-size:12px;color:#a08356;">'+esc(t.note)+'</div>' : '')+'</td>'
              + '<td class="l">'+(names.length ? tplNameList(names) : '<span style="color:#a08356;">（沒有範本）</span>')
              + '<div style="color:#a08356;font-size:12px;margin-top:2px;">共 '+names.length+' 個</div></td>'
              + '<td>'+(+t.is_active?'✓':'—')+'</td>'
              + '<td>'+(admin
                  ? ('<span class="ia-op" onclick="openTplSetEdit('+t.set_id+')"><i class="fa fa-edit"></i> 編輯</span>'
                   + '<span class="ia-op danger" onclick="delTplSet('+t.set_id+',\''+esc(t.set_name).replace(/'/g,"\\'")+'\')"><i class="fa fa-times"></i></span>')
                  : '—')+'</td></tr>';
        });
        $('#tplSetBody').html(h || '<tr><td colspan="4" class="ia-empty">還沒有範本組合'
            + (admin ? '，按右上「新增組合」建立' : '')+'</td></tr>');
        if (cb) cb();
    });
}
$('#btnTplSetNew').on('click', function(){ openTplSetEdit(0); });
function openTplSetEdit(setId){
    var t = null;
    if (+setId > 0) TPL_SETS.forEach(function(x){ if (+x.set_id === +setId) t = x; });
    $('#tplSetEditTitle').text(setId ? ('編輯組合　'+(t?t.set_name:'')) : '新增範本組合');
    $('#btnTplSetSave').data('set-id', setId);
    $('#tsName').val(t ? t.set_name : '');
    $('#tsNote').val(t ? (t.note||'') : '');
    $('#tsActive').prop('checked', t ? !!+t.is_active : true);
    $('#tsFilter').val('');
    TS_PICK = (t ? (t.all_ids||[]) : []).slice();     // 勾選順序＝帶入通知單的列順序
    renderTsPick();
    clearErrs($('#tplSetEditMask'));
    openMask('tplSetEditMask');
}
/* 勾選狀態記在 TS_PICK（依勾選順序），不以畫面上的勾選框為準——
   打了關鍵字時被篩掉的列根本不在畫面上，只讀畫面＝剛剛勾好的會整批消失。 */
var TS_PICK = [];
function renderTsPick(){
    var kw = $('#tsFilter').val().trim().toLowerCase(), h = '', shown = 0;
    TPLS.forEach(function(t){
        var hay = (t.process_name+' '+(t.unit_name||'')).toLowerCase();
        if (kw && hay.indexOf(kw) < 0) return;
        shown++;
        var on = TS_PICK.indexOf(+t.tpl_id) >= 0;
        h += '<label class="pick-row"'+(+t.is_active?'':' style="opacity:.55;"')+'>'
           + '<input type="checkbox" class="tsChk" value="'+t.tpl_id+'"'+(on?' checked':'')+'>'
           + '<span class="pk-name">'+esc(t.process_name)+'</span>'
           + '<span class="pk-sub">'+esc(t.unit_name||'')+(+t.is_active?'':'（已停用，帶入時會跳過）')+'</span>'
           + '</label>';
    });
    $('#tsPick').html(h || '<div class="ia-empty">沒有符合的範本</div>');
    $('#tsCount').text('已選 '+TS_PICK.length+' 個／顯示 '+shown+' 個');
}
$('#tsFilter').on('input', renderTsPick);
$(document).on('change', '.tsChk', function(){
    var id = +$(this).val(), i = TS_PICK.indexOf(id);
    if ($(this).is(':checked')) { if (i < 0) TS_PICK.push(id); }
    else if (i >= 0) TS_PICK.splice(i, 1);
    $('#tsCount').text('已選 '+TS_PICK.length+' 個');
});
$('#btnTplSetSave').on('click', function(){
    clearErrs($('#tplSetEditMask'));
    var ok = fieldErr($('#tsName'), 'errTsName', $('#tsName').val().trim() ? '' : '請填組合名稱');
    if (!TS_PICK.length) { $('#errTsPick').addClass('on').text('請至少勾選一個範本'); ok = false; }
    if (!ok) return;
    $.post(API, {action:'tplset_save', set_id:(+$(this).data('set-id')||0),
        set_name:$('#tsName').val(), note:$('#tsNote').val(),
        is_active:$('#tsActive').is(':checked')?1:'',
        tpl_ids:JSON.stringify(TS_PICK)}, function(res){
        if (!res.ok) { alert(res.error||'儲存失敗'); return; }
        closeMask('tplSetEditMask');
        loadMeta(function(){ loadTplSets(); });
    }, 'json');
});
function delTplSet(setId, name){
    if (!confirm('刪除範本組合「'+name+'」？\n只是刪掉這個「一次帶入多列」的捷徑，底下的範本本身不會被刪。')) return;
    $.post(API, {action:'tplset_delete', set_id:setId}, function(res){
        if (!res.ok) { alert(res.error||'刪除失敗'); return; }
        loadMeta(function(){ loadTplSets(); });
    }, 'json');
}

/* ---- 通知單：一次帶入一整組範本 ---- */
$('#btnCaseTplSet').on('click', function(){
    var sets = (META.tpl_sets||[]).filter(function(t){ return (t.tpl_ids||[]).length; });
    if (!sets.length) {
        alert('還沒有可用的範本組合。\n請到工具列「稽核範本」→ 下方「範本組合」→「新增組合」建立。');
        return;
    }
    var h = '';
    sets.forEach(function(t){
        h += '<div class="pick-wrap" style="max-height:none;margin-bottom:8px;">'
           + '<label style="cursor:pointer;" onclick="applyTplSet('+t.set_id+')">'
           + '<b style="color:#8A5A2B;">'+esc(t.set_name)+'</b>'
           + '<span style="color:#a08356;font-size:12px;">　共 '+(t.tpl_ids||[]).length+' 列</span>'
           + (t.note ? '<div style="font-size:12px;color:#a08356;">'+esc(t.note)+'</div>' : '')
           + '<div style="font-size:12px;color:#8a6d45;margin-top:2px;">'+tplNameList(t.tpl_names||[])+'</div>'
           + '</label></div>';
    });
    $('#tplSetPickBody').html(h);
    openMask('tplSetPickMask');
});
/** 把整組範本一次加成受稽單位的列。
 *  已經在表格上的起始主過程不重複加（同一次稽核裡不可以重複，加了只會被擋在存檔前）；
 *  末尾那些「按 ↓ 加出來還沒填」的空列先拿掉，不然新列會被推到空列後面看起來很亂。 */
function applyTplSet(setId){
    var t = null;
    (META.tpl_sets||[]).forEach(function(x){ if (+x.set_id === +setId) t = x; });
    if (!t) return;
    // 目前已經有的起始主過程（比對時大小寫與前後空白不算數，跟 checkDupProcess 同一套）
    var have = {};
    CASE_ROWS.forEach(function(r){
        var v = String(r.start_process||'').trim().toLowerCase();
        if (v) have[v] = 1;
    });
    // 整列全空的列（含剛按 ↓ 加出來的）先清掉
    CASE_ROWS = CASE_ROWS.filter(function(r){
        return String(r.start_process||'').trim() !== '' || r.dept_id
            || (r.auditor_keys||[]).length || (r.escort_keys||[]).length
            || r.audited_date || r.audited_time || r.improve_due;
    });
    var added = 0, skipped = [];
    (t.tpl_ids||[]).forEach(function(tid){
        var tpl = null;
        (META.templates||[]).forEach(function(x){ if (+x.tpl_id === +tid) tpl = x; });
        if (!tpl) return;
        var key = String(tpl.process_name||'').trim().toLowerCase();
        if (key && have[key]) { skipped.push(tpl.process_name); return; }
        have[key] = 1;
        var r = newCaseRow();
        CASE_ROWS.push(r);
        applyTpl(CASE_ROWS.length - 1, tpl.tpl_id);   // 帶入與逐列選範本走同一支，規則不會走鐘
        added++;
    });
    if (!CASE_ROWS.length) CASE_ROWS = [newCaseRow()];
    renderCaseRows();
    closeMask('tplSetPickMask');
    if (skipped.length) {
        alert('已帶入 '+added+' 列。\n\n以下 '+skipped.length+' 個起始主過程表格上已經有了，沒有重複加入：\n'
            + skipped.join('\n'));
    }
}

/* ============================ 通知單：逐列帶入範本 ============================ */
function tplOptions(cur){
    var h = '<option value="">（手動填寫）</option>';
    (META.templates||[]).forEach(function(t){
        h += '<option value="'+t.tpl_id+'"'+(String(cur)===String(t.tpl_id)?' selected':'')+'>'
           + esc(t.process_name+'　→　'+t.unit_name)+'</option>';
    });
    return h;
}
/** 選了範本：帶入起始主過程／受稽單位，並把該列的稽核員、陪檢員候選縮到範本指定的範圍 */
function applyTpl(rowIdx, tplId){
    var r = CASE_ROWS[rowIdx] || (CASE_ROWS[rowIdx] = {});
    r.tpl_id = tplId || '';
    if (!tplId) { r.auditor_cands = null; r.escort_cands = null; renderCaseRows(); return; }
    // 換範本＝候選範圍換了，原本選的人若不在新範圍內留著也沒關係（後端仍會驗資格）
    var t = null;
    (META.templates||[]).forEach(function(x){ if (+x.tpl_id === +tplId) t = x; });
    if (!t) return;
    r.start_process = t.process_name;
    r.dept_id       = t.unit_dept_id;
    r.auditor_cands = t.auditor_cands || [];
    r.escort_cands  = t.escort_cands  || [];
    // 只有一位候選就自動帶入；先決定稽核員，陪檢員再排除他本人。
    // 使用者已經自己挑過人的那一列不覆蓋掉（範本只是帶入預設）。
    if (!rowKeys(r,'auditor').length && t.auditor_auto) r.auditor_keys = [t.auditor_auto];
    if (!rowKeys(r,'escort').length  && t.escort_auto)  r.escort_keys  = [t.escort_auto];
    renderCaseRows();
    checkDupProcess(); checkEscortConflict();
}
/** 同一次稽核裡相同的稽核起始主過程不可重複——輸入當下就標紅，不要等送出（表單三總則③） */
function checkDupProcess(){
    var seen = {}, dup = {};
    $('#cDeptBody tr').each(function(){
        var v = String($(this).find('[data-f=start_process]').val()||'').trim().toLowerCase();
        if (v === '') return;
        if (seen[v] !== undefined) { dup[seen[v]] = 1; dup[$(this).data('i')] = 1; }
        else seen[v] = $(this).data('i');
    });
    $('#cDeptBody tr').each(function(){
        var i = $(this).data('i');
        $(this).find('[data-f=start_process]').toggleClass('err', !!dup[i]);
    });
    var n = Object.keys(dup).length;
    $('#cDupWarn').toggle(n > 0).text(n ? '有 ' + n + ' 列的「稽核起始主過程」重複了，同一次稽核裡不可以重複' : '');
    return n === 0;
}
/** 陪檢員不可與稽核員同一人（不同職務也不行，因為是同一個人）。
 *  新資料在挑人時就互相排除挑不到，這裡是防舊資料與程式化塞入（後端 case_save 也會再擋一次）。 */
function checkEscortConflict(){
    var bad = 0;
    $('#cDeptBody tr').each(function(){
        var i = +$(this).data('i'), r = CASE_ROWS[i] || {};
        var au = {}, clash = false;
        (r.auditor_keys||[]).forEach(function(k){ au[String(k).split(':')[0]] = 1; });
        (r.escort_keys||[]).forEach(function(k){ if (au[String(k).split(':')[0]]) clash = true; });
        $(this).find('td').eq(4).find('.ppl-chip').toggleClass('bad', clash);
        if (clash) bad++;
    });
    $('#cEscWarn').toggle(bad > 0).text(bad ? '有 ' + bad + ' 列的陪檢員與稽核員是同一個人，請改選' : '');
    return bad === 0;
}
$(document).on('change', '#cDeptBody [data-f=start_process]', checkDupProcess);
$(document).on('input',  '#cDeptBody [data-f=start_process]', checkDupProcess);
$(document).on('change', '#cDeptBody [data-f=tpl_id]', function(){
    applyTpl(+$(this).closest('tr').data('i'), $(this).val());
});
</script>

</body>
</html>
