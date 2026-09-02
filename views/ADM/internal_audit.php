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
        /* 打字模糊篩選的建議清單（相關表單編號／違反條文共用） */
        .ia-sug-wrap { position:relative; }
        .ia-sug { position:absolute; z-index:60; left:0; right:0; top:100%; max-height:230px; overflow-y:auto;
            background:#fff; border:1px solid #D8BE93; border-radius:0 0 5px 5px; box-shadow:0 6px 14px rgba(90,60,20,.18); }
        .ia-sug div { padding:5px 8px; font-size:12px; color:#5b3a1e; cursor:pointer; border-bottom:1px solid #F3E7D2; }
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
        .ia-hint { font-size:12px; color:#8a6d45; line-height:1.7; background:#FBF5EA; border:1px solid #EADFC8;
            border-left:4px solid #F0A24B; border-radius:5px; padding:7px 10px; margin-bottom:10px; }
        .ia-hint b { color:#8A5A2B; }
        .ia-proxy { background:#FBEAE4; border-left-color:#DD5138; }
        .ia-log { font-size:12px; color:#7a6444; border-top:1px dashed #E0BE86; margin-top:8px; padding-top:6px; }
        .ia-log div { padding:1px 0; }
        .ia-log .proxy { color:#C4442D; }
        .pick-wrap { max-height:340px; overflow:auto; border:1px solid #E8D5B5; border-radius:5px; background:#fff; }
        .pick-wrap label { display:block; padding:4px 10px; margin:0; font-size:13px; color:#5b3a1e; cursor:pointer; border-bottom:1px solid #F3EADA; }
        .pick-wrap label:hover { background:#FDF3E2; }
        .pick-wrap label.hdr { background:#F3E4C9; font-weight:bold; color:#6b4a20; }
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
            <select id="yearSel" style="width:110px;"></select>
            <button id="btnReload"><i class="fa fa-refresh"></i> 重新整理</button>
            <?php if ($perms['canAdmin']): ?>
            <button id="btnSetting"><i class="fa fa-cog"></i> 設定</button>
            <button id="btnUnitSetting"><i class="fa fa-sitemap"></i> 受稽單位</button>
            <button id="btnQualify"><i class="fa fa-user-plus"></i> 稽核員資格</button>
            <button id="btnTplSetting"><i class="fa fa-clone"></i> 稽核範本</button>
            <button id="btnClauseBank"><i class="fa fa-list-ol"></i> AS條文題庫</button>
            <?php endif; ?>
            <span class="ia-role-badge">目前身分：<?= htmlspecialchars($roleLabel) ?>
                <i class="fa fa-question-circle" id="btnRoleHelp" style="cursor:pointer;"></i></span>
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
            <div class="ia-hint">建立時可勾選這次要查哪些項目：<b>AS稽核查檢表</b>帶 AS9100 條文題庫、<b>系統稽核紀錄表</b>帶 AS 文件的表單清單、<b>績效執行稽核查檢表</b>帶 KPI 指標與目標值。判定「不合格／沒達成」的項目可以直接開不符合通知單。</div>
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
                <button id="btnReportApprove"><i class="fa fa-check"></i> 核准</button>
                <?php endif; ?>
                <button id="btnReportPrint"><i class="fa fa-print"></i> 列印</button>
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
            <li><b>③查檢表</b>：三種各自建立，建立時勾選這次要查的項目。現場逐列判定合格／不合格並填所見證據。判「不合格」的列可直接按「開不符合單」。</li>
            <li><b>④不符合通知單（2-GM-06-07）</b>：分四段填，各段只有該角色能填（見下）。系統會通知受稽單位主管，期限前與逾期會自動再提醒。</li>
            <li><b>⑤稽核報告表（2-GM-06-08）</b>：缺點數與缺點記錄自動彙總，只要調整「預定完成改善時間」與補充文字，然後核准、列印。</li>
        </ul>

        <h4>不符合通知單的四段分工</h4>
        <ul>
            <li><b>段一 稽核員填</b>：不合格事實描述、不合格類型（主要／次要／觀察）、違反條文、要求完成期限。</li>
            <li><b>段二 受稽單位填</b>：原因分析、糾正措施及完成時間、預防措施及完成時間、責任主管。
                填完按「送出回覆」才會進到下一段。<b>稽核員／內稽管理員可以代填</b>（對方不方便用電腦、或補歷史紙本時），代填會在下方歷程留下紅字紀錄。</li>
            <li><b>段三 稽核組長填</b>：糾正和預防措施執行狀況驗證描述、驗證通過或不通過。<b>不通過會退回段二</b>並重新通知受稽單位。</li>
            <li><b>段四 管理代表填</b>：管理代表意見，按「結案」本單結束、通知受稽單位。</li>
        </ul>

        <h4>重要行為／常見疑問</h4>
        <ul>
            <li><b>年度計畫表的 ◎ 不用手動點</b>：把稽核通知單的狀態改成「執行中」或「已結案」，該單位那個月就會自動變 ◎。沒排 ○ 卻做了也會出現 ◎。</li>
            <li><b>好幾個部門是同一個受稽單位</b>（例：生產部＋生產1廠＋生產2廠＋生產3廠）：到工具列「受稽單位」綁成一個群組。
                綁定後計畫表上是<b>一欄</b>、報告表上是<b>一列</b>，稽核其中任何一個廠都算這個單位已執行；這個單位底下所有部門的人都看得到並可回覆該單位的不符合通知單。一個部門只能屬於一個受稽單位。</li>
            <li><b>誰可以當稽核員／陪檢員</b>：工具列「稽核員資格」可指定名單。<b>資格認到「人員＋部門＋職稱」</b>——兼任的人主職與兼任職各自獨立，可以只有其中一個有資格。指定後相關下拉只列名單內的職務，挑選時也是挑職務。<b>名單留空＝不限制</b>。離職者會自動失效。</li>
            <li><b>要補以前年度的資料</b>：左上角年度下拉本來就含近十年，直接切到那一年再建立即可，不必先有當年的資料。</li>
            <li><b>稽核起始主過程要填什麼</b>：這次稽核從哪一段流程切入，稽核員由這裡開始循序把相關過程查完。紙本備註列了三類可填：<b>主過程</b>（客戶需求檢討→開發→訂單/合約審查→生產→倉儲出貨→客戶回饋）、<b>管理過程</b>（文件/記錄管理、人力資源訓練、不符合管理、資料分析、內部稽核、矯正/預防措施管理、持續改善、管理責任…）、<b>支援過程</b>（採購、供應商管理、IQC/FAI/IPQC/FQC、儀器/量具、機器/治具、生管、型態(鑑別追溯)、特殊特性…）。起點<b>不必等於該單位的日常業務</b>——紙本備註第 1 條要求「跳過自己的直接職務」，讓稽核員從別人的角度切入。<b>同一次稽核裡不可以有兩列填相同的起始主過程</b>，重複會即時標紅、也存不進去。</li>
            <li><b>稽核員與陪檢員怎麼帶</b>：選了範本之後，該列的稽核員／陪檢員下拉會縮到範本指定的部門範圍內、且只列有資格的職務；<b>候選只有一位就自動帶入</b>。系統<b>先決定稽核員</b>，陪檢員的候選會自動排除稽核員本人（同一人不可兩邊都當，即使是不同職務）。<b>陪檢員可以不填</b>。</li>
            <li><b>稽核員／陪檢員都可以有多位</b>（2026-08-27 起）：已選的人會變成一個個標籤，按標籤上的 <b>×</b> 移除、用下方的「＋加入稽核員／＋加入陪檢員」再加人，<b>每一種最多 <?= IA_CD_PERSON_MAX ?> 位</b>。已經被選走的人（不管在哪一邊）不會再出現在候選裡，所以不會不小心把同一個人排成兩種身分。列印版的稽核員／陪檢員欄會一位一行印出來；自動建會議紀錄時，<b>全部</b>稽核員與陪檢員都會被帶進與會人員。</li>
            <li><b>受審查單位主管是誰，依稽核日期回推當時的職務</b>（不是現在的職務），所以補去年的舊單不會蓋到今年才上任的人。查不到當時的主管時寧可留白，不會亂帶人。</li>
            <li><b>IA 編號依稽核日期產生</b>（IA+西元後兩碼+月日+流水，例 IA24121601），補歷史紙本時編號會跟表單上的日期對得起來。</li>
            <li><b>到期提醒</b>：期限前 N 天（預設 7 天，可在「設定」改）與逾期後，每天最多發一則通知給受稽單位主管與受審核人。提醒是有人用到這個模組時順便檢查，不是背景排程。</li>
            <li><b>查檢表結案前必須每一項都判定過</b>合格／不合格，否則不讓結案（避免漏查）。</li>
            <li><b>條文題庫刪不掉</b>：已經被既有查檢表引用的 AS 條文按刪除會自動改成「停用」（不再出現在新建的查檢表），舊表內容不受影響。</li>
            <li><b>列印</b>：每張表都是 A4，公司全名、表頭表單名稱、頁尾右下角的 AS 文件編號都由「設定」裡綁定的 AS 文件推導，<b>版次依該單據的日期回推當時生效的版次</b>。按下列印會留下列印紀錄（列印與簽核紀錄頁查得到）。</li>
        </ul>

        <h4>設定入口</h4>
        <ul>
            <li><b>設定</b>（右上工具列，限內稽管理員）：七份表單各自的 AS 文件編號綁定、簽章圖章模板、核准／審查格的簽章人來源、到期提醒天數、會議主旨預設文字。</li>
            <li><b>受稽單位</b>（右上工具列，限內稽管理員）：把多個部門綁成同一個受稽單位。</li>
            <li><b>稽核範本</b>（右上工具列，限內稽管理員）：預先設定「稽核起始主過程→受稽單位→稽核員／陪檢員從哪些部門挑」，填通知單時一列選一個就帶入。</li>
            <li><b>稽核員資格</b>（右上工具列，限內稽管理員）：稽核員／陪檢員的合格人員名單。</li>
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
                <label>&nbsp;</label><div></div>
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
        「建立的文件、表單」按<b>＋選文件</b>可以打編號或名稱模糊篩選後多選。</div>
        <div class="ia-table-wrap"><table class="ia-table"><thead><tr>
            <th style="width:64px;">順序</th><th style="width:70px;">標題列</th><th>品質管理系統要求</th>
            <th style="width:260px;">建立的文件、表單</th><th style="width:70px;">啟用</th><th style="width:110px;">操作</th>
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
        <div class="ia-sec"><h5>基本資料</h5>
            <div class="ia-form">
                <label>稽核件號</label><div><input type="text" id="cNo" readonly placeholder="存檔後依通知日期自動產生"></div>
                <label>次別</label><div><input type="text" id="cSeq" readonly></div>
                <label>通知日期<span style="color:#DD5138;">*</span></label>
                <div><input type="date" id="cNotify"><div class="err-msg" id="errCNotify"></div></div>
                <label>稽核組長</label><div><select id="cLeader" data-eg-filter="輸入人員姓名篩選…"></select></div>
                <label>稽核起</label><div><input type="date" id="cFrom"></div>
                <label>稽核迄</label><div><input type="date" id="cTo"><div class="err-msg" id="errCTo"></div></div>
                <label>結束會議</label><div><input type="date" id="cMeetDate"></div>
                <label>時間</label>
                <div><input type="text" id="cMeetStart" placeholder="16:00" style="width:80px;"> ～
                     <input type="text" id="cMeetEnd" placeholder="16:30" style="width:80px;">
                     <div class="err-msg" id="errCMeetTime"></div></div>
                <label>地點</label><div class="full"><input type="text" id="cMeetPlace" placeholder="二樓會議室"></div>
                <label>備註</label><div class="full"><textarea id="cRemark"></textarea></div>
            </div>
        </div>
        <div class="ia-sec"><h5>受稽單位　<span class="lock-note">在最後一列按 ↓ 自動加一列</span></h5>
            <div class="err-msg" id="cDupWarn" style="margin-bottom:4px;"></div>
            <div class="err-msg" id="cEscWarn" style="margin-bottom:4px;"></div>
            <div class="ia-table-wrap"><table class="ia-table"><thead><tr>
                <th style="width:170px;">帶入範本</th>
                <th style="width:170px;">稽核起始主過程</th><th style="width:140px;">受稽單位</th>
                <th style="width:165px;">稽核員</th><th style="width:165px;">陪檢員</th>
                <th style="width:130px;">受稽日期</th><th style="width:80px;">時間</th>
                <th style="width:130px;">預定完成改善</th><th style="width:44px;"></th>
            </tr></thead><tbody id="cDeptBody" data-eg-row-add="caseRowAdd" data-eg-row-del="caseRowDel"></tbody></table></div>
        </div>
        <div class="ia-sec" id="cMeetingSec"><h5>會議紀錄</h5>
            <div class="ia-hint">會議紀錄走既有的「會議紀錄」模組，這裡只負責<b>自動建好草稿並帶入與會人員</b>（稽核組長＋各稽核員與陪檢員），再開新分頁給你填。同一張通知單重複按不會建出第二筆。</div>
            <div id="cMeetingBox" style="font-size:13px;color:#5b3a1e;"></div>
        </div>
    </div>
    <div class="ia-mfoot">
        <button data-close>關閉</button>
        <button id="btnCasePrint"><i class="fa fa-print"></i> 列印</button>
        <button id="btnCaseSave" class="btn-warm">儲存</button>
    </div>
</div></div>

<!-- ============================ 建立查檢表 ============================ -->
<div class="ia-mask" id="checkNewMask"><div class="ia-modal">
    <div class="ia-mhead"><h4><i class="fa fa-plus"></i> 建立查檢表</h4><span class="x" data-close>&times;</span></div>
    <div class="ia-mbody">
        <div class="ia-form">
            <label>種類<span style="color:#DD5138;">*</span></label>
            <div><select id="nkKind"></select></div>
            <label>稽核日期<span style="color:#DD5138;">*</span></label>
            <div><input type="date" id="nkDate"><div class="err-msg" id="errNkDate"></div></div>
            <label>稽核人</label><div><select id="nkAuditor" data-eg-filter="輸入人員姓名篩選…"></select></div>
            <label>所屬件號</label><div><select id="nkCase"></select></div>
            <label id="nkHalfLab">半年度</label>
            <div><select id="nkHalf"><option value="">（請選擇）</option><option value="H1">上半年度</option><option value="H2">下半年度</option></select>
                 <div class="err-msg" id="errNkHalf"></div></div>
            <label>標題</label><div><input type="text" id="nkTitle" placeholder="留空＝用種類名稱"></div>
        </div>
        <div style="margin-top:10px;">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                <b style="color:#8A5A2B;font-size:14px;">這次要查的項目</b>
                <input type="text" id="nkFilter" placeholder="輸入關鍵字篩選…" style="border:1px solid #D8BE93;border-radius:4px;padding:3px 8px;font-size:13px;width:220px;">
                <button id="nkAll" style="height:26px;font-size:12px;border:1px solid #D8BE93;border-radius:4px;background:#fff;cursor:pointer;">全選</button>
                <button id="nkNone" style="height:26px;font-size:12px;border:1px solid #D8BE93;border-radius:4px;background:#fff;cursor:pointer;">全不選</button>
                <span id="nkCount" style="font-size:12px;color:#8a6d45;"></span>
            </div>
            <div class="pick-wrap" id="nkPick" style="max-height:300px;"></div>
            <div class="err-msg" id="errNkPick"></div>
        </div>
    </div>
    <div class="ia-mfoot"><button data-close>取消</button><button id="btnCheckCreate" class="btn-warm">建立</button></div>
</div></div>

<!-- ============================ 查檢表填寫 ============================ -->
<div class="ia-mask" id="checkMask"><div class="ia-modal wide">
    <div class="ia-mhead"><h4 id="ckTitle">查檢表</h4><span class="x" data-close>&times;</span></div>
    <div class="ia-mbody">
        <div class="ia-form" style="margin-bottom:10px;">
            <label>標題</label><div><input type="text" id="ckTitleInput"></div>
            <label>稽核日期</label><div><input type="date" id="ckDate"></div>
            <label>稽核人</label><div><input type="text" id="ckAuditor" readonly></div>
            <label>狀態</label><div><input type="text" id="ckStatus" readonly></div>
        </div>
        <div class="ia-table-wrap"><table class="ia-table"><thead id="ckHead"></thead><tbody id="ckBody"></tbody></table></div>
    </div>
    <div class="ia-mfoot">
        <button data-close>關閉</button>
        <button id="btnCheckPrint"><i class="fa fa-print"></i> 列印</button>
        <button id="btnCheckReopen">取消結案</button>
        <button id="btnCheckSave">儲存</button>
        <button id="btnCheckDone" class="btn-warm">結案</button>
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
                <label>稽核員</label><div><input type="text" id="nAuditor" readonly></div>
            </div>
            <div style="text-align:right;margin-top:8px;">
                <button id="btnNcSaveSec1" style="height:30px;font-size:13px;padding:0 14px;border:1px solid #d98a33;border-radius:4px;background:#F0A24B;color:#fff;cursor:pointer;">儲存稽核員填寫區</button>
            </div>
        </div>

        <!-- 段二：受稽單位 -->
        <div class="ia-sec" id="ncSec2"><h5>二、受稽單位回覆<span class="lock-note" id="ncSec2Lock"></span></h5>
            <div class="ia-form">
                <label>受審查單位主管</label>
                <div><select id="nHead" data-eg-filter="輸入人員姓名篩選…"></select>
                     <span id="nHeadSuggest" style="font-size:12px;color:#8a6d45;"></span></div>
                <label>簽核日期</label><div><input type="date" id="nHeadDate"></div>
                <label>原因分析<span style="color:#DD5138;">*</span></label>
                <div class="full"><textarea id="nCause"></textarea><div class="err-msg" id="errNCause"></div></div>
                <label>糾正措施及<br>完成時間<span style="color:#DD5138;">*</span></label>
                <div class="full"><textarea id="nCorr"></textarea><div class="err-msg" id="errNCorr"></div></div>
                <label>預防措施及<br>完成時間<span style="color:#DD5138;">*</span></label>
                <div class="full"><textarea id="nPrev"></textarea><div class="err-msg" id="errNPrev"></div></div>
                <label>責任主管</label><div><select id="nResp" data-eg-filter="輸入人員姓名篩選…"></select></div>
                <label>簽核日期</label><div><input type="date" id="nRespDate"></div>
            </div>
            <div style="text-align:right;margin-top:8px;">
                <button id="btnNcSaveSec2" style="height:30px;font-size:13px;padding:0 14px;border:1px solid #D8BE93;border-radius:4px;background:#fff;color:#5b3a1e;cursor:pointer;">暫存</button>
                <button id="btnNcSubmitSec2" style="height:30px;font-size:13px;padding:0 14px;border:1px solid #d98a33;border-radius:4px;background:#F0A24B;color:#fff;cursor:pointer;margin-left:6px;">送出回覆</button>
            </div>
        </div>

        <!-- 段三：驗證 -->
        <div class="ia-sec" id="ncSec3"><h5>三、稽核組長驗證<span class="lock-note" id="ncSec3Lock"></span></h5>
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
                <label>結束</label><div><input type="text" id="nCloseNote" placeholder="紙本「結束」欄"></div>
                <label>稽核組長</label><div><input type="text" id="nLeader" readonly></div>
                <label>簽核日期</label><div><input type="date" id="nLeaderDate"></div>
            </div>
            <div style="text-align:right;margin-top:8px;">
                <button id="btnNcSaveSec3" style="height:30px;font-size:13px;padding:0 14px;border:1px solid #D8BE93;border-radius:4px;background:#fff;color:#5b3a1e;cursor:pointer;">暫存</button>
                <button id="btnNcSubmitSec3" style="height:30px;font-size:13px;padding:0 14px;border:1px solid #d98a33;border-radius:4px;background:#F0A24B;color:#fff;cursor:pointer;margin-left:6px;">送出驗證</button>
            </div>
        </div>

        <!-- 段四：管理代表 -->
        <div class="ia-sec" id="ncSec4"><h5>四、管理代表意見<span class="lock-note" id="ncSec4Lock"></span></h5>
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
        <button id="btnNcDelete" class="btn-danger">刪除</button>
    </div>
</div></div>

<!-- ============================ 開立不符合通知單 ============================ -->
<div class="ia-mask" id="ncNewMask"><div class="ia-modal">
    <div class="ia-mhead"><h4><i class="fa fa-plus"></i> 開立內稽不符合通知單</h4><span class="x" data-close>&times;</span></div>
    <div class="ia-mbody">
        <div class="ia-hint">開立後會<b>立即通知受稽核單位主管</b>填寫原因分析與改善措施，期限前與逾期也會自動提醒。
        受審查單位主管由系統<b>依稽核日期回推當時職務</b>自動判定。</div>
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
            <label>違反條文</label><div class="full"><input type="text" id="nnClause"></div>
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
        <div class="ia-hint">設定哪些<b>職務</b>可以被指派為<b>稽核員</b>或<b>陪檢員</b>——資格認到<b>人員＋部門＋職稱</b>，一個職務一列。
        <br>兼任的人會出現多列，<b>各列各自獨立</b>：主職沒有資格、兼任職有資格（或反過來）都設定得出來。
        <br>設定後，稽核通知單與查檢表的對應下拉<b>只會列出名單內的職務</b>，挑的時候也是挑職務，才知道他是以哪個身分執行稽核。
        <br><b>名單留空＝不限制</b>（全體在職員工的所有職務都可指派）。離職者會自動失效，不必手動移除。</div>
        <div class="ia-tabs" style="margin-top:4px;">
            <div class="ia-tab on q-tab" data-kind="auditor">稽核員</div>
            <div class="ia-tab q-tab" data-kind="escort">陪檢員</div>
        </div>
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
            <input type="text" id="qFilter" placeholder="輸入部門或姓名篩選…"
                   style="border:1px solid #D8BE93;border-radius:4px;padding:4px 8px;font-size:13px;width:230px;">
            <button id="qAll" style="height:26px;font-size:12px;border:1px solid #D8BE93;border-radius:4px;background:#fff;cursor:pointer;">全選</button>
            <button id="qNone" style="height:26px;font-size:12px;border:1px solid #D8BE93;border-radius:4px;background:#fff;cursor:pointer;">全部清空</button>
            <span id="qCount" style="font-size:12px;color:#8a6d45;"></span>
        </div>
        <div class="pick-wrap" id="qPick" style="max-height:340px;"></div>
    </div>
    <div class="ia-mfoot"><button data-close>關閉</button><button id="btnQualifySave" class="btn-warm">儲存這一分頁的名單</button></div>
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
    </div>
    <div class="ia-mfoot"><button data-close>關閉</button></div>
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
function openMask(id){ $('#'+id).show(); }
function closeMask(id){ $('#'+id).hide(); }
$(document).on('click','[data-close]', function(){ $(this).closest('.ia-mask').hide(); });
$(document).on('click','.ia-mask', function(e){ if(e.target===this) $(this).hide(); });
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

/* ============================ meta ============================ */
function loadMeta(cb){
    $.getJSON(API, {action:'meta'}, function(res){
        if (!res.ok) { alert(res.error||'載入失敗'); return; }
        META = res;
        var ysel = $('#yearSel').empty();
        (res.years||[]).forEach(function(y){ ysel.append('<option value="'+y+'">'+y+' 年</option>'); });
        YEAR = +(res.years && res.years.length ? res.years[0] : String(res.today).substr(0,4));
        ysel.val(YEAR);
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
$('#yearSel').on('change', function(){ YEAR = +$(this).val(); PAGE={case:1,check:1,nc:1}; loadPane(currentPane()); });
$('#btnReload').on('click', function(){ loadMeta(function(){ loadPane(currentPane()); }); });

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
            return;
        }
        $('#btnPlanCreate').hide(); $('#btnPlanDepts,#btnPlanSave,#btnPlanSubmit,#btnPlanApprove').show();
        $('#planRemark').val(PLAN.remark||'');
        var stLabel = {draft:'草稿', submitted:'已送審', approved:'已核准'}[PLAN.status] || PLAN.status;
        var box = '狀態：<span class="st st-'+(PLAN.status==='approved'?'done':PLAN.status)+'">'+esc(stLabel)+'</span>';
        if (PLAN.maker_name)    box += '　製表：'+esc(PLAN.maker_name)+' '+dispDate(PLAN.maker_date);
        if (PLAN.reviewer_name) box += '　審查：'+esc(PLAN.reviewer_name)+' '+dispDate(PLAN.reviewer_date);
        if (PLAN.approver_name) box += '　核准：'+esc(PLAN.approver_name)+' '+dispDate(PLAN.approver_date);
        $('#planStatusBox').html(box);
        renderPlanGrid();
    });
}
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
                 remark:$('#planRemark').val()}, function(res){
        if (!res.ok) { alert(res.error||'儲存失敗'); return; }
        alert('已儲存'); loadPlan();
    }, 'json');
});
$('#btnPlanSubmit').on('click', function(){
    if (!PLAN) return;
    askDate('送審年度稽核計劃表', '審查日期會印在表格下方「審查」欄。', function(d){
        $.post(API, {action:'plan_decide', plan_id:PLAN.plan_id, status:'submitted', biz_date:d}, function(res){
            if (!res.ok) { alert(res.error||'失敗'); return; }
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

/* 通用「輸入業務日期後確認」跳窗 */
var DATE_CB = null;
function askDate(title, hint, cb, withNote){
    $('#dateTitle').text(title); $('#dateHint').text(hint||'');
    $('#dateVal').val(META.today); $('#dateNote').val('');
    $('#dateNoteLab,#dateNoteWrap').toggle(!!withNote);
    DATE_CB = cb; openMask('dateMask');
}
$('#btnDateOk').on('click', function(){
    var d = $('#dateVal').val();
    if (!d) { alert('請填業務日期'); return; }
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
          + '</td></tr>';
    });
    $('#caseBody').html(h);
}

var CASE_ID = 0, CASE_ROWS = [];
$('#btnCaseNew').on('click', function(){ openCase(0); });
function openCase(id){
    CASE_ID = id;
    if (!id) {
        $('#caseTitle').text('新增稽核通知單');
        $('#cNo,#cSeq').val(''); $('#cNotify').val(META.today);
        $('#cFrom,#cTo,#cMeetDate,#cMeetStart,#cMeetEnd,#cMeetPlace').val('');
        $('#cRemark').val(defaultCaseRemark());
        $('#cLeader').html(postOptions(META.auditors, '', '', '（未指定）'));
        CASE_ROWS = [newCaseRow(),newCaseRow(),newCaseRow()];
        renderCaseRows(); $('#cMeetingSec').hide();
        clearErrs($('#caseMask')); openMask('caseMask');
        return;
    }
    // 點開即刷新：直接向後端拿這一筆的最新狀態，不用清單上的快取
    $.getJSON(API, {action:'case_get', case_id:id}, function(res){
        if (!res.ok) { alert(res.error||'載入失敗'); return; }
        var c = res.row;
        $('#caseTitle').text('稽核通知單　'+(c.case_no||'')+'（'+(CASE_ST[c.status]||c.status)+'）');
        $('#cNo').val(c.case_no||''); $('#cSeq').val('第'+c.seq_no+'次');
        $('#cNotify').val(inputDate(c.notify_date)); $('#cFrom').val(inputDate(c.audit_from));
        $('#cTo').val(inputDate(c.audit_to)); $('#cMeetDate').val(inputDate(c.end_meet_date));
        $('#cMeetStart').val(c.end_meet_start||''); $('#cMeetEnd').val(c.end_meet_end||'');
        $('#cMeetPlace').val(c.end_meet_place||''); $('#cRemark').val(c.remark||'');
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
        clearErrs($('#caseMask')); openMask('caseMask');
    });
}
function defaultCaseRemark(){
    return '1.稽核員以過程導向由稽核起始主過程開始循序完成所有相關過程；稽核項目除主過程外，應包含其相關管理及支援過程，但跳過自己的直接職務。\n'
         + '2.主過程:客戶需求檢討→開發→訂單/合約審查→生產→倉儲出貨→客戶回饋\n'
         + '　管理過程:包含但不限文件/記錄管理、人力資源訓練、不符合管理、資料分析、內部稽核、矯正/預防措施管理、持續改善、管理責任…等。\n'
         + '　支援過程:包含但不限採購、供應商管理、IQC/FAI/IPQC/FQC、儀器/量具、機器/治具、生管、型態(鑑別追溯)、特殊特性…等。';
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
          + '<td><input type="date" class="cr" data-f="audited_date" value="'+esc(r.audited_date||'')+'" '+(ro?'readonly':'')+' style="width:100%;border:1px solid #D8BE93;border-radius:3px;padding:2px;font-size:12px;"></td>'
          + '<td><input type="text" class="cr" data-f="audited_time" value="'+esc(r.audited_time||'')+'" placeholder="13:15" '+(ro?'readonly':'')+' style="width:100%;border:1px solid #D8BE93;border-radius:3px;padding:2px 4px;font-size:12px;"></td>'
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
    return ok;
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
$('#btnCaseSave').on('click', function(){
    if (!validateCase()) return;
    $.post(API, {action:'case_save', case_id:CASE_ID, notify_date:$('#cNotify').val(),
        audit_from:$('#cFrom').val(), audit_to:$('#cTo').val(), leader_key:$('#cLeader').val(),
        end_meet_date:$('#cMeetDate').val(), end_meet_start:$('#cMeetStart').val(), end_meet_end:$('#cMeetEnd').val(),
        end_meet_place:$('#cMeetPlace').val(), remark:$('#cRemark').val(),
        depts:JSON.stringify(collectCaseRows())}, function(res){
        if (!res.ok) { alert(res.error||'儲存失敗'); return; }
        alert('已儲存'); CASE_ID = res.case_id;
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
            if (admin) h += ' <span class="ia-op" onclick="unlinkMeeting(\''+k[0]+'\')">解除連結</span>';
        } else {
            h += '<span style="color:#a08356;">尚未建立</span>';
            if (admin) h += ' <span class="ia-op" onclick="createMeeting(\''+k[0]+'\')"><i class="fa fa-plus"></i> 自動建立並開啟</span>';
        }
        h += '</div>';
    });
    $('#cMeetingBox').html(h);
}
function openMeeting(id){ window.open('meeting_record.php?id='+id, '_blank'); }
function createMeeting(kind){
    if (!CASE_ID) { alert('請先儲存稽核通知單'); return; }
    $.post(API, {action:'meeting_create', case_id:CASE_ID, kind:kind}, function(res){
        if (!res.ok) { alert(res.error||'建立失敗'); return; }
        openMeeting(res.meeting_id);
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
    if (!rows.length) { $('#checkBody').html('<tr><td colspan="10" class="ia-empty">沒有符合條件的查檢表</td></tr>'); return; }
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
          + '</td></tr>';
    });
    $('#checkBody').html(h);
}

/* ---- 建立查檢表：先看題庫再勾 ---- */
var BANK = [];
$('#btnCheckNew').on('click', function(){
    var kh = '';
    $.each(META.check_kinds||{}, function(k,v){ kh += '<option value="'+k+'">'+esc(v.label)+'</option>'; });
    $('#nkKind').html(kh);
    $('#nkDate').val(META.today);
    $('#nkAuditor').html(postOptions(META.auditors, '', META.me.id, '（未指定）'));
    $('#nkCase').html(caseOptions(''));
    $('#nkTitle').val(''); $('#nkFilter').val(''); $('#nkHalf').val('');
    clearErrs($('#checkNewMask'));
    loadBank();
    openMask('checkNewMask');
});
$('#nkKind').on('change', loadBank);
function loadBank(){
    var kind = $('#nkKind').val();
    $('#nkHalfLab, #nkHalf').closest('div').toggle(kind==='kpi');
    $('#nkHalfLab').toggle(kind==='kpi');
    $.getJSON(API, {action:'check_bank', kind:kind, year:YEAR}, function(res){
        if (!res.ok) { $('#nkPick').html('<div class="ia-empty">'+esc(res.error||'載入失敗')+'</div>'); return; }
        BANK = res.rows||[];
        renderBank();
    });
}
function bankRow(kind, r){
    if (kind==='as')     return {id:+r.clause_id,  hdr:+r.is_header===1,
                                 text:r.clause_text, sub:r.doc_ref||''};
    if (kind==='system') return {id:+r.id, hdr:false, text:(r.doc_no||'')+'　'+(r.doc_name||''), sub:''};
    return {id:+r.indicator_id, hdr:false,
            text:(r.dept_name?r.dept_name+'　':'')+(r.name||''), sub:r.target_text?('目標：'+r.target_text):''};
}
function renderBank(){
    var kind = $('#nkKind').val();
    var kw = $('#nkFilter').val().trim().toLowerCase();
    var h = '', shown = 0;
    BANK.forEach(function(raw){
        var r = bankRow(kind, raw);
        var hay = (r.text+' '+r.sub).toLowerCase();
        if (kw && hay.indexOf(kw) < 0) return;
        shown++;
        if (r.hdr) {
            // 章節標題列一定跟著建進去（不然條文會沒有分隔），所以勾選框固定勾住且不給取消
            h += '<label class="hdr"><input type="checkbox" class="bkChk bkHdr" value="'+r.id+'" checked onclick="return false;"> '
               + esc(r.text)+'</label>';
        } else {
            h += '<label><input type="checkbox" class="bkChk" value="'+r.id+'" checked> '+esc(r.text)
               + (r.sub ? '<span style="color:#a08356;font-size:12px;">　'+esc(r.sub)+'</span>' : '')+'</label>';
        }
    });
    $('#nkPick').html(h || '<div class="ia-empty">題庫沒有符合的項目</div>');
    updateBankCount(shown);
}
function updateBankCount(shown){
    var n = $('#nkPick .bkChk:not(.bkHdr):checked').length;
    $('#nkCount').text('已勾 '+n+' 項'+(shown!=null?('／顯示 '+shown+' 列'):''));
}
$(document).on('change','.bkChk', function(){ updateBankCount(); });
$('#nkFilter').on('input', renderBank);
$('#nkAll').on('click', function(){ $('#nkPick .bkChk').prop('checked', true); updateBankCount(); return false; });
$('#nkNone').on('click', function(){ $('#nkPick .bkChk:not(.bkHdr)').prop('checked', false); updateBankCount(); return false; });
$('#btnCheckCreate').on('click', function(){
    clearErrs($('#checkNewMask'));
    var kind = $('#nkKind').val(), ok = true;
    ok = fieldErr($('#nkDate'), 'errNkDate', $('#nkDate').val() ? '' : '請填稽核日期') && ok;
    if (kind==='kpi') ok = fieldErr($('#nkHalf'), 'errNkHalf', $('#nkHalf').val() ? '' : '請選上／下半年度') && ok;
    // 篩選中被藏起來的項目仍然算數（否則使用者打了關鍵字就只會建出看得到的那幾題）
    var picked = $('#nkPick .bkChk:checked').map(function(){ return +$(this).val(); }).get();
    var real   = $('#nkPick .bkChk:not(.bkHdr):checked').length;
    if (!real) { $('#errNkPick').addClass('on').text('請至少勾選一個要查核的項目'); ok = false; }
    if (!ok) return;
    $.post(API, {action:'check_create', kind:kind, check_date:$('#nkDate').val(), half:$('#nkHalf').val(),
        auditor_key:$('#nkAuditor').val(), case_id:$('#nkCase').val(), title:$('#nkTitle').val(),
        pick:JSON.stringify(picked)}, function(res){
        if (!res.ok) { alert(res.error||'建立失敗'); return; }
        closeMask('checkNewMask');
        loadChecks(); openCheck(res.check_id);
    }, 'json');
});

/* ---- 查檢表填寫 ---- */
var CHK = null;
function openCheck(id){
    $.getJSON(API, {action:'check_get', check_id:id}, function(res){
        if (!res.ok) { alert(res.error||'載入失敗'); return; }
        CHK = res.row;
        $('#ckTitle').text(CHK.kind_label + (CHK.half ? '（'+(CHK.half==='H1'?'上':'下')+'半年度）' : ''));
        $('#ckTitleInput').val(CHK.title||'');
        $('#ckDate').val(inputDate(CHK.check_date));
        $('#ckAuditor').val(CHK.auditor_name||'');
        $('#ckStatus').val(CHK.status==='done' ? '已結案' : '填寫中');
        var ro = !CHK.can_edit;
        $('#ckTitleInput,#ckDate').prop('readonly', ro);
        $('#btnCheckSave,#btnCheckDone').toggle(!ro);
        $('#btnCheckReopen').toggle(CHK.status==='done' && <?= $perms['canAdmin'] ? 'true' : 'false' ?>);
        renderCheckItems();
        openMask('checkMask');
    });
}
var CK_HEADS = {
    as:     ['項次','品質管理系統要求','建立的文件、表單','合格','不合格','所見證據或建議','備註'],
    system: ['序號','表單編號','表單名稱','受稽人','合格','不合格','備註'],
    kpi:    ['序','部門','內容','目標','受稽人','達成','沒達成','備註']
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
        var ncBtn = '';
        if (it.nc_id) {
            ncBtn = '<span class="ia-op" onclick="openNc('+it.nc_id+')">'+esc(it.nc_no||'IA單')+'</span>';
        } else if (it.result==='ng' && !ro && <?= $perms['canAudit'] ? 'true' : 'false' ?>) {
            ncBtn = '<span class="ia-op" onclick="newNcFromItem('+it.item_id+')"><i class="fa fa-plus"></i> 開不符合單</span>';
        }
        h += '<tr>';
        if (k==='as') {
            h += '<td>'+n+'</td><td class="l">'+esc(it.col_a)+'</td><td class="l" style="font-size:12px;color:#7a6444;">'+esc(it.col_b||'')+'</td>'
              + '<td>'+okChk+'</td><td>'+ngChk+'</td>'
              + '<td><input type="text" class="ckF" data-id="'+it.item_id+'" data-f="evidence" value="'+esc(it.evidence||'')+'"'
              + (ro?' readonly':'')+' style="width:100%;border:1px solid #D8BE93;border-radius:3px;padding:2px 4px;font-size:12px;"></td>'
              + '<td>'+remark+ncBtn+'</td>';
        } else if (k==='system') {
            h += '<td>'+n+'</td><td>'+esc(it.col_a)+'</td><td class="l">'+esc(it.col_b||'')+'</td>'
              + '<td><select class="ckF" data-id="'+it.item_id+'" data-f="col_c"'+(ro?' disabled':'')
              + ' data-eg-filter="輸入姓名篩選…" style="width:100%;border:1px solid #D8BE93;border-radius:3px;font-size:12px;">'
              + nameOptions(it.col_c)+'</select></td>'
              + '<td>'+okChk+'</td><td>'+ngChk+'</td><td>'+remark+ncBtn+'</td>';
        } else {
            h += '<td>'+n+'</td><td>'+esc(it.col_a||'')+'</td><td class="l">'+esc(it.col_b||'')+'</td>'
              + '<td>'+esc(it.col_c||'')+'</td>'
              + '<td><select class="ckF" data-id="'+it.item_id+'" data-f="col_d"'+(ro?' disabled':'')
              + ' data-eg-filter="輸入姓名篩選…" style="width:100%;border:1px solid #D8BE93;border-radius:3px;font-size:12px;">'
              + nameOptions(it.col_d)+'</select></td>'
              + '<td>'+okChk+'</td><td>'+ngChk+'</td><td>'+remark+ncBtn+'</td>';
        }
        h += '</tr>';
    });
    $('#ckBody').html(h);
}
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
function collectCheckItems(){
    var map = {};
    $('#ckBody .ckF').each(function(){
        var id = $(this).data('id');
        map[id] = map[id] || {item_id:id};
        map[id][$(this).data('f')] = $(this).val();
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
        check_date:$('#ckDate').val(), items:JSON.stringify(collectCheckItems())}, function(res){
        if (!res.ok) { alert(res.error||'儲存失敗'); return; }
        if (!silent) alert('已儲存');
        loadChecks();
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
/* 由查檢表的不合格列直接開不符合通知單，欄位自動帶好 */
function newNcFromItem(itemId){
    var it = null;
    (CHK.items||[]).forEach(function(x){ if (+x.item_id===+itemId) it = x; });
    if (!it) return;
    saveCheck(true, function(){
        openNcNew({
            case_id: CHK.case_id || '',
            audit_date: inputDate(CHK.check_date),
            src_kind: CHK.kind,
            src_item_id: itemId,
            ref_form_no: CHK.kind==='system' ? (it.col_a||'') : '',
            clause_ref: CHK.kind==='as' ? (it.col_a||'') : '',
            fact: '',
            auditee_name: CHK.kind==='system' ? (it.col_c||'') : (CHK.kind==='kpi' ? (it.col_d||'') : ''),
            dept_hint: CHK.kind==='kpi' ? (it.col_a||'') : ''
        });
    });
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
          + '</td></tr>';
    });
    $('#ncBody').html(h);
}

/* ---- 開立 ---- */
$('#btnNcNew').on('click', function(){ openNcNew({}); });
function openNcNew(pre){
    // 開立畫面要用到案件清單當下拉；不在稽核通知單分頁時清單可能還沒載入過
    if (!CASES.length) { loadCases(function(){ openNcNew(pre); }); return; }
    var typeH = '<option value="">（請選擇）</option>';
    $.each(META.nc_types||{}, function(k,v){ typeH += '<option value="'+k+'">'+esc(v)+'</option>'; });
    $('#nnType').html(typeH); $('#nnCase').html(caseOptions(pre.case_id||''));
    $('#nnDate').val(pre.audit_date || META.today);
    $('#nnFact').val(pre.fact||''); $('#nnClause').val(pre.clause_ref||'');
    $('#nnFormNo').val(pre.ref_form_no||''); $('#nnDue').val('');
    // 受稽核單位：由所屬件號的受稽單位或績效查檢表的部門欄推一個預設值
    $('#nnDept').html(deptOptions(guessDeptId(pre), '（請選擇）'));
    $('#nnAuditee').html(peopleOptions('', guessUserId(pre.auditee_name), '（未指定）'));
    $('#btnNcCreate').data('src', JSON.stringify({src_kind:pre.src_kind||'', src_item_id:pre.src_item_id||''}));
    clearErrs($('#ncNewMask'));
    openMask('ncNewMask');
}
function guessDeptId(pre){
    var name = String(pre.dept_hint||'').trim();
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
        $('#nDept').val(NC.dept_name||''); $('#nAuditor').val((NC.auditor_name||'')+'　'+dispDate(NC.auditor_date));
        $('#nAuditee').html(peopleOptions('', NC.auditee_id, '（未指定）'));
        $('#nFact').val(NC.fact||''); $('#nFormNo').val(NC.ref_form_no||'');
        $('#nClause').val(NC.clause_ref||''); $('#nDue').val(inputDate(NC.due_date));
        var typeH = '<option value="">（請選擇）</option>';
        $.each(META.nc_types||{}, function(k,v){
            typeH += '<option value="'+k+'"'+(NC.nc_type===k?' selected':'')+'>'+esc(v)+'</option>';
        });
        $('#nType').html(typeH);
        // 段二
        $('#nHead').html(peopleOptions('', NC.head_id, '（未指定）'));
        $('#nHeadDate').val(inputDate(NC.head_date) || META.today);
        $('#nCause').val(NC.cause||''); $('#nCorr').val(NC.corrective||''); $('#nPrev').val(NC.preventive||'');
        $('#nResp').html(peopleOptions('', NC.resp_id, '（未指定）'));
        $('#nRespDate').val(inputDate(NC.resp_date) || META.today);
        $('#nHeadSuggest').text(NC.suggest_head
            ? ('　建議：'+NC.suggest_head.name+'（依稽核日期回推當時職務）')
            : '　（查不到該單位在稽核日期當時的主管，請手動指定）');
        // 段三
        $('#nVerify').val(NC.verify_desc||''); $('#nVerifyRes').val(NC.verify_result||'');
        $('#nCloseNote').val(NC.close_note||'');
        $('#nLeader').val(NC.leader_name||'');
        $('#nLeaderDate').val(inputDate(NC.leader_date) || META.today);
        // 段四
        $('#nMgrNote').val(NC.mgr_note||''); $('#nMgrDate').val(inputDate(NC.mgr_date) || META.today);

        lockSec('#ncSec1', p.sec1, '#ncSec1Lock', '只有稽核員／內稽管理員能填');
        lockSec('#ncSec2', p.sec2, '#ncSec2Lock', '只有受稽單位／稽核員代填');
        lockSec('#ncSec3', p.sec3, '#ncSec3Lock', NC.stage==='issued' ? '要等受稽單位送出回覆' : '只有稽核組長／稽核員能填');
        lockSec('#ncSec4', p.sec4, '#ncSec4Lock', '只有內稽管理員（管理代表）能填');
        $('#ncProxyNote').toggle(!!(p.sec2 && p.proxy));
        $('#btnNcDelete').toggle(!!p.del);
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
        clearErrs($('#ncMask'));
        openMask('ncMask');
    });
}
function lockSec(sel, allow, lockSel, why){
    var $s = $(sel);
    $s.toggleClass('locked', !allow);
    $s.find('input,select,textarea').prop('disabled', !allow);
    $s.find('button').toggle(!!allow);
    $(lockSel).text(allow ? '' : '（唯讀：'+why+'）');
}
$('#btnNcSaveSec1').on('click', function(){
    clearErrs($('#ncSec1'));
    var ok = true;
    ok = fieldErr($('#nFact'), 'errNFact', $('#nFact').val().trim() ? '' : '請填不合格事實描述') && ok;
    ok = fieldErr($('#nType'), 'errNType', $('#nType').val() ? '' : '請選擇不合格類型') && ok;
    var due = $('#nDue').val();
    if (due && NC.audit_date && due < inputDate(NC.audit_date)) {
        ok = fieldErr($('#nDue'), 'errNDue', '要求完成期限不可早於稽核日期') && ok;
    }
    if (!ok) return;
    $.post(API, {action:'nc_save_sec1', nc_id:NC.nc_id, fact:$('#nFact').val(), nc_type:$('#nType').val(),
        clause_ref:$('#nClause').val(), due_date:due, ref_form_no:$('#nFormNo').val(),
        auditee_id:$('#nAuditee').val()}, function(res){
        if (!res.ok) { alert(res.error||'儲存失敗'); return; }
        alert('已儲存'); openNc(NC.nc_id); loadNcs();
    }, 'json');
});
function saveSec2(submit){
    clearErrs($('#ncSec2'));
    if (submit) {
        var ok = true;
        ok = fieldErr($('#nCause'), 'errNCause', $('#nCause').val().trim() ? '' : '請填原因分析') && ok;
        ok = fieldErr($('#nCorr'),  'errNCorr',  $('#nCorr').val().trim()  ? '' : '請填糾正措施及完成時間') && ok;
        ok = fieldErr($('#nPrev'),  'errNPrev',  $('#nPrev').val().trim()  ? '' : '請填預防措施及完成時間') && ok;
        if (!ok) return;
        if (!confirm('送出後這一段會鎖定並通知稽核組長驗證，確定？')) return;
    }
    $.post(API, {action:'nc_save_sec2', nc_id:NC.nc_id, submit:submit?1:'', cause:$('#nCause').val(),
        corrective:$('#nCorr').val(), preventive:$('#nPrev').val(), head_id:$('#nHead').val(),
        head_date:$('#nHeadDate').val(),
        resp_id:$('#nResp').val(), resp_date:$('#nRespDate').val()}, function(res){
        if (!res.ok) { alert(res.error||'儲存失敗'); return; }
        alert(submit ? ('已送出回覆'+(res.proxy?'（已記錄為代填）':'')) : '已暫存');
        openNc(NC.nc_id); loadNcs();
    }, 'json');
}
$('#btnNcSaveSec2').on('click', function(){ saveSec2(false); });
$('#btnNcSubmitSec2').on('click', function(){ saveSec2(true); });
function saveSec3(submit){
    clearErrs($('#ncSec3'));
    if (submit) {
        var ok = true;
        ok = fieldErr($('#nVerify'), 'errNVerify', $('#nVerify').val().trim() ? '' : '請填驗證描述') && ok;
        ok = fieldErr($('#nVerifyRes'), 'errNVerifyRes', $('#nVerifyRes').val() ? '' : '請選擇驗證結果') && ok;
        if (!ok) return;
        if ($('#nVerifyRes').val()==='fail' && !confirm('驗證不通過會退回受稽單位重新提出措施，並重新發通知。確定？')) return;
    }
    $.post(API, {action:'nc_save_sec3', nc_id:NC.nc_id, submit:submit?1:'', verify_desc:$('#nVerify').val(),
        verify_result:$('#nVerifyRes').val(), close_note:$('#nCloseNote').val(),
        leader_date:$('#nLeaderDate').val()}, function(res){
        if (!res.ok) { alert(res.error||'儲存失敗'); return; }
        alert(submit ? '已送出驗證' : '已暫存');
        openNc(NC.nc_id); loadNcs();
    }, 'json');
}
$('#btnNcSaveSec3').on('click', function(){ saveSec3(false); });
$('#btnNcSubmitSec3').on('click', function(){ saveSec3(true); });
function saveSec4(close){
    if (close && !confirm('結案後本單即結束，並會通知受稽單位。確定？')) return;
    $.post(API, {action:'nc_save_sec4', nc_id:NC.nc_id, close:close?1:'', mgr_note:$('#nMgrNote').val(),
        mgr_date:$('#nMgrDate').val()}, function(res){
        if (!res.ok) { alert(res.error||'儲存失敗'); return; }
        alert(close ? '已結案' : '已儲存');
        if (close) closeMask('ncMask'); else openNc(NC.nc_id);
        loadNcs();
    }, 'json');
}
$('#btnNcSaveSec4').on('click', function(){ saveSec4(false); });
$('#btnNcClose').on('click', function(){ if (!$(this).prop('disabled')) saveSec4(true); });
$('#btnNcResend').on('click', function(){
    $.post(API, {action:'nc_resend', nc_id:NC.nc_id}, function(res){
        if (!res.ok) { alert(res.error||'失敗'); return; }
        alert(res.sent ? '已重新發送通知' : '找不到可通知的對象（請先指定受審查單位主管或受審核人）');
    }, 'json');
});
$('#btnNcDelete').on('click', function(){
    if (!confirm('刪除後這張不符合通知單將不再出現在清單與稽核報告表。確定？')) return;
    $.post(API, {action:'nc_delete', nc_id:NC.nc_id}, function(res){
        if (!res.ok) { alert(res.error||'刪除失敗'); return; }
        closeMask('ncMask'); loadNcs();
    }, 'json');
});

/* ============================ 稽核報告表 2-GM-06-08 ============================ */
var REPORT = null;
function loadReport(){
    $.getJSON(API, {action:'report_get', year:YEAR}, function(res){
        if (!res.ok) return;
        REPORT = res;
        var r = res.report;
        var box = r ? ('狀態：<span class="st st-'+(r.status==='approved'?'done':'draft')+'">'
                      + (r.status==='approved'?'已核准':'草稿')+'</span>'
                      + (r.maker_name ? '　製表：'+esc(r.maker_name)+' '+dispDate(r.maker_date) : '')
                      + (r.approver_name ? '　核准：'+esc(r.approver_name)+' '+dispDate(r.approver_date) : ''))
                    : '<span style="color:#8a6d45;">尚未建立（按「儲存」即建立）</span>';
        $('#reportStatusBox').html(box);
        $('#reportNote').val(r ? (r.extra_note||'') : '');
        var rows = res.rows||[], admin = <?= $perms['canAdmin'] ? 'true' : 'false' ?>;
        if (!rows.length) {
            $('#reportBody').html('<tr><td colspan="9" class="ia-empty">'+YEAR+' 年度還沒有稽核紀錄</td></tr>');
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
                  + '<td>'+esc(r2.form_no||'—')+'</td>'
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
$('#btnReportApprove').on('click', function(){
    askDate('核准稽核報告表', '核准日期會印在表格下方核准欄。', function(d){
        $.post(API, {action:'report_approve', year:YEAR, biz_date:d}, function(res){
            if (!res.ok) { alert(res.error||'失敗'); return; }
            loadReport();
        }, 'json');
    });
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
    $('#setRemindDays').val(s.ia_remind_days||'7');
    $('#setMeetPre').val(s.ia_meeting_pre_subject||'');
    $('#setMeetEnd').val(s.ia_meeting_end_subject||'');
    clearErrs($('#settingMask'));
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
$('#btnSettingSave').on('click', function(){
    var v = $('#setRemindDays').val().trim();
    if (!(v==='' || (/^\d+$/.test(v) && +v<=365))) { fieldErr($('#setRemindDays'),'errRemindDays','請填 0～365 的整數'); return; }
    var jobs = [
        ['ia_stamp_tpl_id',       $('#setStampTpl').val()],
        ['ia_sign_approve',       $('#setSignApprove').val()],
        ['ia_sign_review',        $('#setSignReview').val()],
        ['ia_remind_days',        v],
        ['ia_meeting_pre_subject',$('#setMeetPre').val()],
        ['ia_meeting_end_subject',$('#setMeetEnd').val()]
    ];
    var done = 0, failed = '';
    jobs.forEach(function(j){
        $.post(API, {action:'save_setting', key:j[0], value:j[1]}, function(res){
            if (!res.ok) failed = res.error||'儲存失敗';
        }, 'json').always(function(){
            if (++done === jobs.length) {
                if (failed) { alert(failed); return; }
                alert('設定已儲存'); closeMask('settingMask'); loadMeta();
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
    if (add.length) alert('已加入 '+add.length+' 份文件，記得按該列的「存」才會寫入。');
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
                h += '<div data-v="'+esc(o.value)+'">'+(o.no?'<span class="no">'+esc(o.no)+'</span>':'')+esc(o.label)+'</div>';
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
            var t = nfc(c.clause_text).replace(/\s+/g,' ').trim();
            return {value:t, no:'', label:t, search:t + ' ' + (c.doc_ref||'')};
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
        $('#clauseBody').html(h || '<tr><td colspan="6" class="ia-empty">題庫是空的</td></tr>');
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
      + '<td><input type="checkbox" class="clF" data-f="is_active"'+(c.clause_id===undefined||+c.is_active?' checked':'')+'></td>'
      + '<td><span class="ia-op" onclick="saveClause(this)"><i class="fa fa-save"></i> 存</span>'
      + '<span class="ia-op danger" onclick="delClause(this)"><i class="fa fa-trash"></i></span></td></tr>';
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
    return o;
}
function saveClause(el){
    var $tr = $(el).closest('tr');
    var o = rowClause($tr);
    if (!String(o.clause_text||'').trim()) { alert('請填品質管理系統要求'); return; }
    $.post(API, $.extend({action:'clause_save'}, o), function(res){
        if (!res.ok) { alert(res.error||'儲存失敗'); return; }
        $tr.attr('data-id', res.clause_id).data('id', res.clause_id);
        clauseSaveOrder();          // 新列存完才有 id，順帶把整份順序寫回去
        alert('已儲存');
    }, 'json');
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
    var css = '@page{size:A4 '+(landscape?'landscape':'portrait')+';margin:12mm 10mm 16mm;'
            + (asCss ? " @bottom-right{ content:'"+asCss+"'; font-size:9pt; color:#333; }" : '')
            + '}'
            + 'body{font-family:"Microsoft JhengHei","微軟正黑體",sans-serif;color:#000;'
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
    var onePage = ((landscape?210:297) - 28) * 96 / 25.4;
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
            h += signCells(m, [
                {label:'核准', html: stampHtml(m, c.approver_id ? sp(c.approver_id, c.approver_name, caseDate) : m.sign_approve, caseDate)},
                {label:'審查', html: stampHtml(m, c.reviewer_id ? sp(c.reviewer_id, c.reviewer_name, caseDate) : m.sign_review, caseDate)},
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
            var h = printHead(m, k.title || m.doc_name);
            var d = String(k.check_date||'').split('-');
            h += '<div style="font-size:12px;margin-bottom:5px;overflow:hidden;">'
               + '<span>稽核人: '+esc(k.auditor_name||'')+'</span>'
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
            /* 2026-08-27 使用者要求的版面（依紙本 2-GM-06-07）：
               ①「不合格類型」加框、移到「不合格事實描述」上方
               ②「違反條文:」是標題要粗體
               ③ 拿掉「單位主管核示」整行（紙本沒有這一格）
               稽核員段、受稽單位段、驗證段各自併成一大格（紙本中間沒有橫線），
               圖章一律靠右下，段與段之間用一條粗線分開。 */
            var pre = function (t) { return '<div class="pre">' + esc(t || '') + '</div>'; };
            var signOne = function (label, id, name, date) {
                return esc(label) + ': <span class="stamp-inline">' + stampHtml(m, sp(id, name, date), date) + '</span>';
            };
            var signRight = function (label, id, name, date) {
                return '<div style="text-align:right;margin-top:8px;">' + signOne(label, id, name, date) + '</div>';
            };
            /* 紙本這兩個章是並排在同一行（各佔半邊），不要上下疊——疊起來整格會多高出一個章 */
            var signPair = function (a, b) {
                return '<div style="margin-top:8px;overflow:hidden;">'
                     + '<div style="float:left;width:50%;text-align:right;">' + a + '</div>'
                     + '<div style="float:left;width:50%;text-align:right;">' + (b || '') + '</div></div>';
            };
            h += '<table class="ia-p">'
              + '<tr><th style="width:100px;">受稽核單位</th><td style="width:150px;">'+esc(n.dept_name||'')+'</td>'
              + '<th style="width:80px;">受審核人</th><td style="width:110px;">'+esc(n.auditee_name||'')+'</td>'
              + '<th style="width:80px;">稽核日期</th><td>'+dispDate(n.audit_date)+'</td></tr>'

              /* ---- 稽核員段 ---- */
              + '<tr><td colspan="6" class="l" style="height:160px;vertical-align:top;">'
              + '<div style="display:inline-block;border:1px solid #000;padding:2px 10px;margin-bottom:10px;">'
              + '不合格類型: '+esc(n.type_label||'')+'</div>'
              + '<div style="font-weight:bold;">不合格事實描述:</div>' + pre(n.fact)
              + '<div style="margin-top:10px;" class="pre"><b>違反條文:</b> '+esc(n.clause_ref||'')+'</div>'
              + signPair(signOne('稽核員', n.auditor_id, n.auditor_name, n.auditor_date),
                         n.head_name ? signOne('受審查單位主管', n.head_id, n.head_name, n.head_date) : '')
              + '</td></tr>'

              /* 要求完成期限之後是受稽單位要填的部分，用一條粗線分開 */
              + '<tr><td class="l" colspan="6" style="border-bottom:2px solid #000;">要求完成期限: '
              + (n.due_date?dispDate(n.due_date):'')+'</td></tr>'

              /* ---- 受稽單位段（原因分析／糾正／預防，紙本是同一大格） ---- */
              + '<tr><td colspan="6" class="l" style="height:250px;vertical-align:top;">'
              + '<div style="font-weight:bold;">原因分析:</div>' + pre(n.cause)
              + '<div style="font-weight:bold;margin-top:14px;">糾正措施及完成時間:</div>' + pre(n.corrective)
              + '<div style="font-weight:bold;margin-top:14px;">預防措施及完成時間:</div>' + pre(n.preventive)
              + signRight('責任主管', n.resp_id, n.resp_name, n.resp_date)
              + '</td></tr>'

              /* ---- 驗證段 ---- */
              + '<tr><td colspan="6" class="l" style="height:150px;vertical-align:top;">'
              + '<div style="font-weight:bold;">糾正和預防措施執行狀況驗證描述:</div>' + pre(n.verify_desc)
              + '</td></tr>'
              + '<tr><td colspan="6" class="l" style="height:60px;vertical-align:top;">'
              + '<div style="font-weight:bold;">結束:</div>' + pre(n.close_note)
              + signRight('稽核組長', n.leader_id, n.leader_name, n.leader_date)
              + '</td></tr>'

              /* ---- 管理代表段（紙本的「簽名」就是圖章本身，不另印一行空標籤） ---- */
              + '<tr><td colspan="6" class="l" style="height:80px;vertical-align:top;">'
              + '<div style="font-weight:bold;">管理代表意見:</div>' + pre(n.mgr_note)
              + (n.mgr_name ? signRight('簽名', n.mgr_id, n.mgr_name, n.mgr_date) : '')
              + '</td></tr>'
              + '</table>';
            logPrint('內稽不符合通知單 '+(n.nc_no||('#'+id)), 'ia_nc', id);
            iaPrintWindow('內稽不符合通知單 '+(n.nc_no||''), h, '', m.doc_no, false);
        }, [{id:n.auditor_id, date:n.auditor_date}, {id:n.head_id, date:n.head_date},
            {id:n.resp_id, date:n.resp_date}, {id:n.leader_id, date:n.leader_date},
            {id:n.mgr_id, date:n.mgr_date}]);
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
        var rows = REPORT.rows||[];
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
        recs.forEach(function(x){
            h += esc(x.dept_name+'-'+x.nc_no+(x.form_no?('　'+x.form_no):'')+'　'+String(x.fact||'').split('\n')[0])+'\n';
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
var QMAP = {}, QKIND = 'auditor', QPEOPLE = [], QPOSTS = [];
$('#btnQualify').on('click', function(){
    $.getJSON(API, {action:'qualify_get'}, function(res){
        if (!res.ok) { alert(res.error||'載入失敗'); return; }
        QMAP = res.map||{}; QPEOPLE = res.people||[]; QPOSTS = res.posts||[];
        QKIND = 'auditor';
        $('.q-tab').removeClass('on'); $('.q-tab[data-kind=auditor]').addClass('on');
        $('#qFilter').val('');
        renderQualify();
        openMask('qualifyMask');
    });
});
$(document).on('click', '.q-tab', function(){
    // 切分頁前先把目前這一頁的勾選記回 QMAP，不然切回來會發現剛剛勾的不見了。
    // 一定要走 qCheckedIds()：值是「uid:deptId:posId」字串，用 +val() 轉數字會全部變成 NaN，
    // 存進 QMAP 之後切回來就整份名單都沒勾（2026-08-26 使用者回報的症狀）。
    QMAP[QKIND] = qCheckedIds();
    $('.q-tab').removeClass('on'); $(this).addClass('on');
    QKIND = $(this).data('kind');
    renderQualify();
});
/* 一個職務一列，**各列各自獨立勾選**：資格認到 人員＋部門＋職稱。
   兼任的人可能主職沒有稽核員資格、兼任職才有（或反過來），所以不可以跨列連動。 */
function renderQualify(){
    var picked = {};
    (QMAP[QKIND]||[]).forEach(function(k){ picked[k] = 1; });
    var kw = $('#qFilter').val().trim().toLowerCase();
    var h = '', shown = 0;
    (QPOSTS||[]).forEach(function(p){
        var hay = ((p.dept_name||'')+' '+(p.position_name||'')+' '+(p.user_cname||'')).toLowerCase();
        if (kw && hay.indexOf(kw) < 0) return;
        shown++;
        var key = p.post_key3 || postKeyOf(p.id, p.dept_id, p.position_id);
        // 欄位順序固定「部門/職稱/姓名」（ai-rules/08 第五節鐵則6）
        h += '<label class="pick-row"><input type="checkbox" class="qChk" value="'+esc(key)+'"'
           + (picked[key]?' checked':'')+'>'
           + '<span class="pk-name">'+esc(p.dept_name||'')+'</span>'
           + '<span class="pk-name" style="flex:0 0 90px;">'+esc(p.position_name||'')+'</span>'
           + '<span class="pk-sub" style="color:#5b3a1e;">'+esc(p.user_cname||'')
           + (+p.is_main === 0 ? '<span style="color:#a08356;">（兼任）</span>' : '')
           + (p.leave_note ? ('　<span style="color:#C4442D;">'+esc(p.leave_note)+'</span>') : '')+'</span>'
           + '</label>';
    });
    $('#qPick').html(h || '<div class="ia-empty">沒有符合的職務</div>');
    updateQCount(shown);
}
/** 目前畫面上勾起來的職務鍵 */
function qCheckedIds(){
    return $('#qPick .qChk:checked').map(function(){ return $(this).val(); }).get();
}
function updateQCount(shown){
    var keys = qCheckedIds();
    var people = {};
    keys.forEach(function(k){ people[String(k).split(':')[0]] = 1; });
    var kindLab = (META.qualify_kinds||{})[QKIND] || QKIND;
    $('#qCount').text(kindLab + '：已勾 ' + keys.length + ' 個職務（' + Object.keys(people).length + ' 人）'
        + (shown != null ? ('／顯示 ' + shown + ' 列') : '')
        + (keys.length === 0 ? '　不限制，全體在職員工的所有職務都可指派' : ''));
}
$(document).on('change', '.qChk', function(){ updateQCount(); });
$('#qFilter').on('input', function(){
    QMAP[QKIND] = qCheckedIds();
    renderQualify();
});
$('#qAll').on('click', function(){ $('#qPick .qChk').prop('checked', true); updateQCount(); return false; });
$('#qNone').on('click', function(){ $('#qPick .qChk').prop('checked', false); updateQCount(); return false; });
$('#btnQualifySave').on('click', function(){
    // 篩選中被藏起來的人也要一起送，否則打了關鍵字再存會把沒顯示的人全部刷掉
    var visible = {};
    $('#qPick .qChk').each(function(){ visible[$(this).val()] = 1; });
    var checked = qCheckedIds();
    var keep = (QMAP[QKIND]||[]).filter(function(k){ return !visible[k]; });
    var ids = keep.concat(checked);
    $.post(API, {action:'qualify_save', kind:QKIND, post_keys:JSON.stringify(ids)}, function(res){
        if (!res.ok) { alert(res.error||'儲存失敗'); return; }
        QMAP[QKIND] = ids;
        alert((META.qualify_kinds||{})[QKIND] + ' 名單已儲存（' + res.count + ' 個職務'
              + (res.count === 0 ? '＝不限制' : '') + '）');
        loadMeta();
    }, 'json');
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
        if (cb) { cb(); return; }
        openMask('tplMask');
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
