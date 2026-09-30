<?php
/**
 * AS 文件排程（年度行事曆）—— 2026-09-30 使用者交辦
 * ══════════════════════════════════════════════════════════════════════════════
 * 使用者的問題：「有沒有可能做到全自動提醒 AS 相關文件的建置，例如幾月要做什麼」。
 *
 * 本頁＝方案 B（獨立的 AS 年度行事曆頁，含列印與提醒管線）。使用者拍板「兩個都做，先 B 後 A」，
 * A＝把同一份推導資料掛成現有行事曆（views/pages/calendar.php）的可勾選圖層，之後再做；
 * 兩者共用 src/common/asdoc_schedule_lib.php 的同一支推導函式，只是兩種渲染（判定不寫兩份）。
 *
 * 【資料一律即時推導，本頁不存任何排程】
 * 來源＝as_document.freq_type/freq_n/freq_months（AS 文件管理 2026-09-07 建的「更新頻率」）
 * ＋ as_doc_owner_dept（負責課室）＋ 各模組的實際完成紀錄。詳細理由見 lib 的檔頭。
 *
 * 【畫面上一定要講出來的三件事（不可假裝有資料）】
 *  ①月份是「管理員指定」還是「由過去紀錄推估」——推估的要標出來，否則使用者會以為系統在亂報。
 *  ②「系統查不到完成紀錄」與「確定沒做」是兩件事：前者標「無法判定」且**不算逾期、不發提醒**。
 *  ③每日／每週型是常態工作，不進月份排程也不算逾期，另外列在「常態工作」區。
 */
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/ADM/as_schedule.php";
    header("Location:../../index.php");
    exit;
}
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/asdoc_schedule_lib.php';

$db = (new DBConnection())->getPDO();
asched_ensure($db);
$aschedUser = asched_current_user($db);
$P = asched_perms($db, $aschedUser);
$roleLabel = asched_role_label($P);
if (empty($_SESSION['asched_csrf'])) $_SESSION['asched_csrf'] = bin2hex(random_bytes(16));

// 本公司全名（列印大標題用，禁寫死＝ai-rules/16）
$companyName = '';
try {
    $companyName = (string)($db->query("SELECT customer_full FROM customer_list WHERE is_own_company=1 LIMIT 1")->fetchColumn() ?: '');
} catch (Throwable $e) {}

$curYear = (int)date('Y');
$reqYear = isset($_GET['year']) ? max(2015, min($curYear + 3, (int)$_GET['year'])) : $curYear;
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>AS 文件排程</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <style>
        #sidebar-menu { visibility: hidden; }
        /* Gentelella 的 .top_nav 高度為 0 且浮動溢出，right_col 第一個子元素要 clear:both
           否則自成 BFC 時會被壓成寬度 0（標題整條消失）——CLAUDE.md 鐵律6，已踩過兩次 */
        .right_col .page-title { margin:8px 0 4px; overflow:hidden; clear:both; }
        .page-help-btn { height:30px; font-size:13px; padding:0 12px; border:1px solid #d98a33; border-radius:15px;
            background:#F0A24B; color:#fff; cursor:pointer; }
        .page-help-btn:hover { background:#d98a33; }
        @media print { .page-help-btn { display:none !important; } }
        .help-doc { font-size:13px; color:#5b3a1e; line-height:1.75; }
        .help-doc h4 { color:#8A5A2B; border-bottom:2px solid #F7E0BD; padding-bottom:3px; margin:14px 0 6px; font-size:15px; }
        .help-doc h4:first-child { margin-top:0; }
        .help-doc b { color:#8A5A2B; }
        .help-doc ul { margin:4px 0 8px; padding-left:20px; }
        .help-doc li { margin:2px 0; }
        .help-doc .tip { background:#FFF7E8; border:1px dashed #F0A24B; border-radius:6px; padding:6px 10px; margin:6px 0; }

        /* ── 工具列（暖色系，ai-rules/10）── */
        .as-toolbar { display:flex; flex-wrap:wrap; gap:6px; align-items:center; clear:both;
            border:1.5px solid #E8D5B5; border-radius:8px; padding:8px 10px; margin-bottom:10px; background:#FDF8EF; }
        .as-toolbar label { margin:0; font-size:13px; color:#5b3a1e; }
        .as-toolbar select, .as-toolbar input[type=text], .as-toolbar input[type=number], .as-toolbar button {
            height:30px; font-size:13px; line-height:1; padding:0 10px; border:1px solid #D8BE93;
            border-radius:4px; background:#fff; color:#5b3a1e; cursor:pointer; }
        .as-toolbar button:hover { background:#F7E0BD; }
        .as-toolbar .btn-warm { background:#F0A24B; color:#fff; border-color:#d98a33; }
        .as-toolbar .btn-warm:hover { background:#d98a33; }
        .as-role-badge { margin-left:auto; font-size:13px; color:#5b3a1e; background:#F7E0BD; border-radius:12px; padding:4px 12px; }

        /* ── KPI 卡 ── */
        .as-kpis { display:grid; grid-template-columns:repeat(auto-fit,minmax(130px,1fr)); gap:8px; margin-bottom:10px; }
        .as-kpi { border:1.5px solid #E8D5B5; border-radius:8px; padding:8px 10px; background:#fff; cursor:pointer; }
        .as-kpi:hover { background:#FFF7E8; }
        .as-kpi.on { border-color:#F0A24B; background:#FFF3E0; box-shadow:0 0 0 2px #F7E0BD inset; }
        .as-kpi .k-n { font-size:22px; font-weight:bold; line-height:1.1; }
        .as-kpi .k-l { font-size:12px; color:#8a6d45; margin-top:2px; }
        .as-kpi .k-h { font-size:11px; color:#a88f6a; }

        /* 狀態語意色：紅=需處理／橘=快到了／砂=還早／綠=完成／灰=判不出來
           （ai-rules/10：語意色維持全站通用，其餘一律暖色系） */
        .k-overdue  .k-n { color:#DD5138; }
        .k-due      .k-n { color:#F0A24B; }
        .k-upcoming .k-n { color:#B08A52; }
        .k-done     .k-n { color:#5F8A4F; }
        .k-unknown  .k-n { color:#9b9b9b; }

        .as-note { font-size:12px; color:#6b4f2a; background:#FFF7E8; border:1px dashed #E0B87A;
            border-radius:6px; padding:6px 10px; margin-bottom:10px; line-height:1.7; }

        /* ── 年度格子表（課室 × 12 月）── */
        .as-grid-wrap { overflow-x:auto; border:1.5px solid #E8D5B5; border-radius:8px; background:#fff; margin-bottom:12px; }
        table.as-grid { width:100%; border-collapse:collapse; font-size:12px; table-layout:fixed; }
        table.as-grid th, table.as-grid td { border:1px solid #E8D5B5; padding:4px 5px; vertical-align:top; }
        table.as-grid thead th { background:#F7E0BD; color:#5b3a1e; text-align:center; font-weight:bold; position:sticky; top:0; z-index:2; }
        table.as-grid th.c-dept { width:104px; text-align:left; background:#FDF8EF; position:sticky; left:0; z-index:3; }
        table.as-grid td.c-dept { width:104px; background:#FDF8EF; font-weight:bold; color:#5b3a1e;
            position:sticky; left:0; z-index:1; }
        table.as-grid td.c-m { width:auto; }
        table.as-grid td.now { background:#FFFBF2; }
        .as-chip { display:block; border-radius:4px; padding:2px 4px; margin-bottom:3px; cursor:pointer;
            font-size:11px; line-height:1.35; border:1px solid transparent; word-break:break-all; }
        .as-chip:last-child { margin-bottom:0; }
        .as-chip .cn { font-weight:bold; }
        .as-chip.s-overdue  { background:#FBE3DD; border-color:#DD5138; color:#8c2d18; }
        .as-chip.s-due      { background:#FDEBD3; border-color:#F0A24B; color:#7a4a12; }
        .as-chip.s-upcoming { background:#F6EEE0; border-color:#D8BE93; color:#5b3a1e; }
        .as-chip.s-done     { background:#E7F0E2; border-color:#7A9A6B; color:#3d5a32; }
        .as-chip.s-unknown  { background:#EFEFEF; border-color:#c4c4c4; color:#666; }
        .as-chip .est { font-size:10px; opacity:.85; }

        /* ── 明細表 ── */
        .as-tbl-wrap { overflow-x:auto; border:1.5px solid #E8D5B5; border-radius:8px; background:#fff; margin-bottom:12px; }
        table.as-tbl { width:100%; border-collapse:collapse; font-size:12.5px; }
        table.as-tbl th, table.as-tbl td { border:1px solid #E8D5B5; padding:5px 7px; vertical-align:middle; }
        table.as-tbl thead th { background:#F7E0BD; color:#5b3a1e; white-space:nowrap; }
        table.as-tbl tbody tr:hover { background:#FFFBF2; }
        /* 表格內的小籤一定要自己指定 line-height——Gentelella 全站 td span{line-height:28px}
           會把 11px 的字撐成 28px 高、整列被拉高（本專案已踩過多次） */
        .as-badge { display:inline-block; font-size:11px; line-height:16px; padding:0 6px; border-radius:9px;
            border:1px solid transparent; white-space:nowrap; }
        .b-overdue  { background:#DD5138; color:#fff; }
        .b-due      { background:#F0A24B; color:#fff; }
        .b-upcoming { background:#F7E0BD; color:#5b3a1e; }
        .b-done     { background:#7A9A6B; color:#fff; }
        .b-unknown  { background:#E4E4E4; color:#555; }
        .b-est      { background:#FFF3E0; color:#8a5a2b; border-color:#E0B87A; }
        .b-soft     { background:#F3EADB; color:#6b4f2a; }
        .as-sec-h { font-size:14px; color:#8A5A2B; font-weight:bold; margin:14px 0 6px; padding-bottom:3px;
            border-bottom:2px solid #F7E0BD; clear:both; }
        .as-sec-h small { font-weight:normal; color:#8a6d45; font-size:12px; }
        .as-mini { font-size:11px; color:#8a6d45; }
        .as-empty { padding:14px; text-align:center; color:#8a6d45; font-size:13px; }

        /* ── 缺口盤點 ── */
        .as-gaps { display:grid; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); gap:8px; }
        .as-gap { border:1.5px solid #E0B87A; border-radius:8px; background:#FFF7E8; padding:8px 10px; }
        .as-gap h5 { margin:0 0 4px; font-size:13px; color:#8A5A2B; font-weight:bold; }
        .as-gap ul { margin:0; padding-left:18px; font-size:12px; color:#6b4f2a; max-height:150px; overflow:auto; }

        /* ── 跳窗（寬度一律固定像素，不可用 vw——會蓋過側邊選單，見記憶 modal_width_convention）── */
        .m-mask { position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:10500; display:none; }
        .m-mask.on { display:block; }
        .m-box { position:absolute; left:50%; top:50%; transform:translate(-50%,-50%);
            background:#fff; border-radius:10px; box-shadow:0 8px 30px rgba(0,0,0,.3); display:flex; flex-direction:column;
            max-height:88vh; }
        .m-head { padding:10px 14px; border-bottom:1.5px solid #E8D5B5; background:#FDF8EF; border-radius:10px 10px 0 0;
            font-weight:bold; color:#5b3a1e; display:flex; align-items:center; }
        .m-head .x { margin-left:auto; cursor:pointer; color:#8a6d45; }
        .m-body { padding:12px 14px; overflow:auto; flex:1 1 auto; }
        .m-foot { padding:10px 14px; border-top:1.5px solid #E8D5B5; background:#FDF8EF; border-radius:0 0 10px 10px; text-align:right; }
        .m-foot button, .m-body button { height:30px; font-size:13px; padding:0 14px; border:1px solid #D8BE93;
            border-radius:4px; background:#fff; color:#5b3a1e; cursor:pointer; }
        .m-foot .b-ok, .m-body .b-ok { background:#F0A24B; color:#fff; border-color:#d98a33; }
        .m-row { display:flex; gap:8px; align-items:center; margin-bottom:8px; flex-wrap:wrap; }
        .m-row label { min-width:112px; margin:0; font-size:13px; color:#5b3a1e; }
        .m-row input[type=text], .m-row input[type=number], .m-row input[type=date], .m-row select, .m-row textarea {
            height:30px; font-size:13px; padding:0 8px; border:1px solid #D8BE93; border-radius:4px; color:#5b3a1e; }
        .m-row textarea { height:auto; padding:6px 8px; }
        .m-err { color:#DD5138; font-size:12px; margin:2px 0 6px 120px; }
        .m-help { font-size:12px; color:#6b4f2a; background:#FFF7E8; border:1px dashed #E0B87A;
            border-radius:6px; padding:6px 10px; margin-bottom:10px; line-height:1.7; }
        .chk-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(150px,1fr)); gap:2px 8px;
            max-height:190px; overflow:auto; border:1px solid #E8D5B5; border-radius:6px; padding:6px; }
        .chk-grid label { font-size:12.5px; font-weight:normal; color:#5b3a1e; margin:0; display:block; cursor:pointer; }
        .chk-grid label:hover { background:#FFF7E8; }

        @media print { body { background:#fff; } }
    </style>
</head>
<body class="nav-sm">
<div class="container body">
<div class="main_container">
    <?php include '../partPage/sideAndTopBarMenu.html' ?>
    <div class="right_col" role="main">
        <div class="page-title" style="display:flex;align-items:center;flex-wrap:wrap;">
            <h2 style="margin:6px 0;">AS 文件排程
                <small style="color:#8a6d45;">依 AS 文件管理登記的「更新頻率」推導：這一年誰幾月要做什麼、做了沒、逾期沒</small></h2>
            <button id="btnPageHelp" class="page-help-btn" style="margin-left:auto;"><i class="fa fa-question-circle"></i> 使用說明</button>
        </div>
        <div class="clearfix"></div>

<?php if (!$P['canView']): ?>
        <div class="as-note" style="color:#8c2d18;border-color:#DD5138;background:#FBE3DD;">
            您沒有 AS 文件排程的檢視權限。請管理員在「使用者權限設定」指派「AS排程檢閱」或「AS排程管理員」角色，
            或指派 AS 文件管理的檢閱權限（具 AS 文件檢閱權者自動可看本頁）。
        </div>
<?php else: ?>

        <div class="as-toolbar">
            <label>年度</label>
            <select id="fYear"></select>
            <label>負責課室</label>
            <select id="fDept" data-eg-filter="輸入課室名稱篩選…"><option value="">全部課室</option></select>
            <label>狀態</label>
            <select id="fState">
                <option value="">全部</option>
                <option value="overdue">逾期</option>
                <option value="due">即將到期</option>
                <option value="upcoming">尚未到期</option>
                <option value="done">已完成</option>
                <option value="unknown">無法判定</option>
                <option value="nomonth">月份未定</option>
            </select>
            <input type="text" id="fKw" placeholder="文件編號／名稱關鍵字" style="width:190px;">
            <button id="btnReload" class="btn-warm"><i class="fa fa-refresh"></i> 重新整理</button>
            <button id="btnPrint"><i class="fa fa-print"></i> 列印年度排程表</button>
<?php if ($P['canAdmin']): ?>
            <button id="btnSettings"><i class="fa fa-cog"></i> 提醒設定</button>
            <button id="btnNotify"><i class="fa fa-bell"></i> 通知對象</button>
<?php endif; ?>
            <span class="as-role-badge">目前身分：<?= htmlspecialchars($roleLabel) ?></span>
        </div>

        <div id="kpis" class="as-kpis"></div>
        <div id="noteBox" class="as-note"></div>

        <div class="as-sec-h">年度排程表　<small>課室 × 月份；點格子裡的文件可登記完成或開啟該模組</small></div>
        <div class="as-grid-wrap"><div id="gridBox"><div class="as-empty">載入中…</div></div></div>

        <div class="as-sec-h">明細清單　<small id="rowCnt"></small></div>
        <div class="as-tbl-wrap"><div id="tblBox"><div class="as-empty">載入中…</div></div></div>

        <div class="as-sec-h">常態工作　<small>每日／每週型：不列入月份排程也不算逾期</small></div>
        <div class="as-tbl-wrap"><div id="routineBox"><div class="as-empty">載入中…</div></div></div>

        <div class="as-sec-h">設定缺口　<small>這些不補，對應的文件永遠不會被提醒，而且系統不會報錯</small></div>
        <div id="gapBox"><div class="as-empty">載入中…</div></div>

<?php endif; ?>
    </div>
</div>
</div>

<!-- ══════════ 登記完成 ══════════ -->
<div class="m-mask" id="doneMask"><div class="m-box" style="width:520px;">
    <div class="m-head">登記完成<span class="x" onclick="closeMask('doneMask')"><i class="fa fa-times"></i></span></div>
    <div class="m-body">
        <div class="m-help" id="doneInfo"></div>
        <div class="m-row"><label>完成日期 <span style="color:#DD5138;">*</span></label>
            <input type="date" id="dDate" style="width:170px;"></div>
        <div class="m-err" id="dDateErr" style="display:none;"></div>
        <div class="m-row"><label>備註</label>
            <textarea id="dNote" rows="3" style="width:330px;" placeholder="例：紙本已歸檔於文管中心"></textarea></div>
        <div class="m-help">
            這裡登記的是「這一期做完了」的事實，用來讓排程不再顯示逾期。<br>
            完成日期請填 <b>實際完成的那一天</b>（不是今天），且年份必須與所登記的週期相同。
        </div>
    </div>
    <div class="m-foot">
        <button id="btnUnmark" style="float:left;display:none;">取消登記</button>
        <button onclick="closeMask('doneMask')">取消</button>
        <button class="b-ok" id="btnDoneSave">儲存</button>
    </div>
</div></div>

<!-- ══════════ 更新頻率 / 負責課室（就地設定，寫入打 AS_Document_API 的 save_doc_freq）══════════ -->
<div class="m-mask" id="freqMask"><div class="m-box" style="width:640px;">
    <div class="m-head">更新頻率 / 負責課室<span class="x" onclick="closeMask('freqMask')"><i class="fa fa-times"></i></span></div>
    <div class="m-body">
        <div class="m-help" id="fqDocInfo"></div>
        <div class="m-row"><label>更新頻率</label>
            <select id="fqType" style="width:150px;">
                <option value="">— 未設定 —</option>
                <option value="irregular">不定時</option>
                <option value="day">每 N 天</option>
                <option value="week">每 N 週</option>
                <option value="month">每 N 個月</option>
                <option value="quarter">每 N 季</option>
                <option value="year">每 N 年</option>
            </select>
            <input type="number" id="fqN" min="1" max="255" value="1" style="width:80px;" placeholder="數量">
            <span class="as-mini" id="fqNHint"></span>
        </div>
        <div class="m-row" id="fqMonthRow"><label>起始月份</label>
            <select id="fqMonth" style="width:110px;"><option value="">不指定</option></select>
            <span class="as-mini" id="fqMonthHint"></span>
        </div>
        <div class="m-err" id="fqMonthErr" style="display:none;"></div>
        <div class="m-row" id="fqNoteRow"><label>頻率備註</label>
            <textarea id="fqNote" rows="2" style="width:400px;" placeholder="選「不定時」時必填：什麼情況下會更新"></textarea></div>
        <div class="m-err" id="fqNoteErr" style="display:none;"></div>
        <div style="margin:10px 0 4px;font-size:13px;color:#8A5A2B;font-weight:bold;">
            負責課室（可複選）<span class="as-mini" style="font-weight:normal;">　決定提醒發給誰，也是年度排程表的分列依據</span></div>
        <div class="chk-grid" id="fqDeptBox"></div>
        <div class="m-help" style="margin-top:10px;">
            <b>起始月份</b>只要選一個，系統會依頻率自動算出整年度的排程月份（例：每 6 個月選 1 月＝1 月與 7 月）。<br>
            不指定月份時，系統會用<b>過去實際完成的月份</b>推估，畫面上會標「推估」；推不出來就顯示「月份未定」。<br>
            <b>這裡改的就是 AS 文件管理「更新頻率 / 負責課室」的同一份設定</b>，兩邊改任一處都會立刻反映在排程上。
        </div>
    </div>
    <div class="m-foot">
        <button onclick="closeMask('freqMask')">取消</button>
        <button class="b-ok" id="btnFreqSave">儲存</button>
    </div>
</div></div>

<!-- ══════════ 提醒設定 ══════════ -->
<div class="m-mask" id="setMask"><div class="m-box" style="width:560px;">
    <div class="m-head">提醒設定<span class="x" onclick="closeMask('setMask')"><i class="fa fa-times"></i></span></div>
    <div class="m-body">
        <div class="m-row"><label>提醒總開關</label>
            <label style="font-weight:normal;min-width:auto;"><input type="checkbox" id="sEnabled"> 啟用到期／逾期自動提醒</label></div>
        <div class="m-row"><label>到期前幾天提醒</label>
            <input type="number" id="sLead" min="0" max="365" style="width:90px;"> 天</div>
        <div class="m-row"><label>逾期重複提醒</label>
            <input type="number" id="sRepeat" min="0" max="180" style="width:90px;"> 天一次（0＝逾期只提醒一次）</div>
        <div class="m-row"><label>月份自動推估</label>
            <label style="font-weight:normal;min-width:auto;"><input type="checkbox" id="sInfer"> 沒指定月份時，用過去實際完成的月份推估</label></div>
        <div class="m-help">
            <b>提醒怎麼發：</b>站內通知（鈴鐺）＋ Web Push ＋ Telegram 三軌並行，沒綁手機或 Telegram 的人靠站內通知也收得到。<br>
            <b>發給誰：</b>該文件「負責課室」依「通知對象」設定解析出來的人；該課室沒設定時退回該單位最高主管。<br>
            <b>什麼情況不會發：</b>①狀態是「無法判定」的（系統查不到完成紀錄，發了等於每月吵一件可能早就做完的事）
            ②沒設負責課室的（不知道要發給誰）③每日／每週型的常態工作。這三種都列在下方「設定缺口」。<br>
            <b>月份推估：</b>關掉之後，沒有指定月份的文件一律變成「月份未定」，只會列出來不會有到期日與逾期天數。
        </div>
    </div>
    <div class="m-foot">
        <button onclick="closeMask('setMask')">取消</button>
        <button class="b-ok" id="btnSetSave">儲存設定</button>
    </div>
</div></div>

<!-- ══════════ 通知對象 ══════════ -->
<div class="m-mask" id="ntfMask"><div class="m-box" style="width:720px;">
    <div class="m-head">通知對象（逐課室設定）<span class="x" onclick="closeMask('ntfMask')"><i class="fa fa-times"></i></span></div>
    <div class="m-body">
        <div class="m-help">
            每個課室各自設定「收通知的職位」與「指定人員」，<b>兩者可以複選、也可以並用</b>。<br>
            ・<b>職位</b>：該課室（含下轄組）中擔任這些職位的在職人員都會收到——人員異動時不必回來改。<br>
            ・<b>指定人員</b>：不限部門，用於代理人、管理代表這種跨部門的情況。<br>
            ・兩者都不設＝退回「該單位最高主管」，不會變成沒人收到。
        </div>
        <div class="m-row"><label>課室</label>
            <select id="nDept" data-eg-filter="輸入課室名稱篩選…" style="min-width:260px;"></select>
            <span class="as-mini" id="nDeptHint"></span></div>
        <div style="margin:8px 0 4px;font-size:13px;color:#8A5A2B;font-weight:bold;">收通知的職位（可複選）</div>
        <div class="chk-grid" id="nPosBox"></div>
        <div style="margin:10px 0 4px;font-size:13px;color:#8A5A2B;font-weight:bold;">指定人員（可複選，不限部門）</div>
        <input type="text" id="nPeopleKw" placeholder="輸入姓名或部門篩選…" style="height:28px;font-size:12.5px;width:100%;
            border:1px solid #D8BE93;border-radius:4px;padding:0 8px;margin-bottom:4px;color:#5b3a1e;">
        <div class="chk-grid" id="nUserBox"></div>
        <div style="margin-top:10px;">
            <button id="btnNtfPreview"><i class="fa fa-eye"></i> 試算：這樣會發給誰</button>
            <span class="as-mini" id="nPreview" style="margin-left:6px;"></span>
        </div>
    </div>
    <div class="m-foot">
        <button onclick="closeMask('ntfMask')">關閉</button>
        <button class="b-ok" id="btnNtfSave">儲存這個課室</button>
    </div>
</div></div>

<!-- ══════════ 使用說明（鐵律7）══════════ -->
<div class="m-mask" id="helpUseMask"><div class="m-box" style="width:820px;">
    <div class="m-head">AS 文件排程 — 使用說明<span class="x" onclick="closeMask('helpUseMask')"><i class="fa fa-times"></i></span></div>
    <div class="m-body help-doc">
        <h4>這一頁在做什麼</h4>
        <p>把 AS 文件管理裡登記的「<b>更新頻率</b>」與「<b>負責課室</b>」，加上各模組實際的完成紀錄，
           推導出「<b>今年誰幾月要做什麼、做了沒、逾期沒</b>」，並在到期前與逾期時自動發提醒。</p>
        <div class="tip">本頁<b>不儲存任何排程</b>，每次開啟都是即時算出來的。所以在 AS 文件管理改了更新頻率或負責課室，
            這裡立刻就會跟著變，不會出現「兩邊對不起來」的情況。</div>

        <h4>資料是從哪裡來的</h4>
        <ul>
            <li><b>週期與月份</b>：AS 文件管理 → 該文件的 ⚙ →「更新頻率 / 負責課室」。</li>
            <li><b>做了沒</b>：優先查該文件對應模組的實際紀錄（內部稽核、供應商稽核、客戶滿意度等）；
                查不到就看 AS 文件管理的「填寫紀錄」（紙本掃描檔、線上表單）；再查不到就看本頁的人工登記。</li>
            <li><b>負責課室</b>：決定提醒發給誰，也是年度排程表的分列依據。</li>
        </ul>

        <h4>三個一定要知道的判定規則</h4>
        <ul>
            <li><b>月份標「推估」的意思</b>：那份文件只登記了「一年一次」卻沒指定月份，系統是拿
                <b>過去實際完成的月份</b>推出來的。<b>點那個「推估 ✎」標籤就能當場指定固定月份</b>，
                標示會變成不再有「推估」字樣（也可以到 AS 文件管理的「更新頻率 / 負責課室」改，兩邊是同一份設定）。</li>
            <li><b>「無法判定」不等於沒做</b>：系統查不到這份文件的任何完成紀錄（沒有對應模組、沒有紙本上傳、
                沒有人工登記）。這種<b>一律不算逾期、也不發提醒</b>——系統不知道不代表沒做，硬報逾期只會讓提醒變成雜訊。
                請用該列的「登記完成」把事實補進來。</li>
            <li><b>每日／每週型不進排程</b>：例如量測室溫濕度記錄表是每天要做的，一年 365 個點會把排程表整片蓋掉，
                所以獨立列在「常態工作」區，不算逾期。</li>
        </ul>

        <h4>操作步驟</h4>
        <ul>
            <li>上方選年度、負責課室、狀態或關鍵字即可篩選；<b>點 KPI 卡</b>也能直接切換狀態篩選。</li>
            <li><b>年度排程表</b>：一列一個課室、一欄一個月，格子裡的每個文件都可以點。</li>
            <li>點文件 →「<b>登記完成</b>」填實際完成那一天（不是今天），排程就不再顯示逾期；
                已登記過的可以「取消登記」。</li>
            <li>該文件有對應的系統頁面時，跳窗裡會有「<b>開啟該模組</b>」直接跳過去做。</li>
            <li><b>列印年度排程表</b>：A3 橫式，給稽核時看的正式版面（不印篩選鈕與內部提示）。</li>
        </ul>

        <h4>在這一頁直接設定更新頻率 / 負責課室</h4>
        <p>排程算不算得出來，全看那份文件的「更新頻率」與「負責課室」有沒有設好。
           以下三個地方都可以<b>當場點開設定</b>，不必換頁到 AS 文件管理：</p>
        <ul>
            <li>明細表「本期應完成」欄的「<b>推估 ✎</b>」或「<b>月份未定 ✎</b>」標籤</li>
            <li>明細表「負責課室」欄的「<b>未設定 ✎</b>」標籤、以及操作欄的「<b>頻率設定</b>」</li>
            <li>下方「設定缺口」區裡「沒設更新頻率」與「沒設負責課室」的每一份文件</li>
        </ul>
        <div class="tip"><b>起始月份只要選一個就好</b>——系統會依頻率自動算出整年度的排程月份
            （例：每 6 個月選 1 月＝1 月與 7 月；每季選 2 月＝2、5、8、11 月），所以不會選出不合法的組合。
            不指定月份時才會走「由過去完成紀錄推估」。</div>
        <p><b>這裡改的就是 AS 文件管理「更新頻率 / 負責課室」的同一份設定</b>（同一個寫入點），
           兩邊改任一處都會立刻反映在排程上，不會出現兩邊對不起來的情況。
           權限也與那邊相同：<b>只有 AS 文件管理員</b>看得到這些入口（其他人連按鈕都不會出現，
           就算直接呼叫也會被後端擋下）。</p>

        <h4>誰能登記完成</h4>
        <ul>
            <li><b>AS排程管理員</b>：所有課室都能登記，並可改提醒設定與通知對象。</li>
            <li><b>該文件負責課室的人員</b>：只能登記自己課室負責的文件。</li>
            <li>其他人只能看（前端不顯示登記鈕，後端也會再擋一次）。</li>
        </ul>

        <h4>提醒怎麼發</h4>
        <ul>
            <li>站內通知（鈴鐺）＋ Web Push ＋ Telegram <b>三軌並行</b>，沒綁手機或 Telegram 的人靠站內通知也收得到。</li>
            <li>到期前提醒<b>只發一次</b>；逾期可設定每 N 天再提醒一輪（0＝只發一次）。</li>
            <li>檢查是「順路觸發」的：有人開任何頁面時才會檢查（每小時最多一次），<b>不需要工作排程器</b>。
                半夜沒人用系統時不會檢查，會等隔天有人開頁面時補發。</li>
        </ul>

        <h4>設定缺口那一區在講什麼</h4>
        <ul>
            <li><b>沒設更新頻率</b>：那份文件完全不會出現在排程裡。</li>
            <li><b>沒設負責課室</b>：排程算得出來，但<b>不知道要發給誰，所以不會發提醒</b>。</li>
            <li><b>系統查不到完成紀錄</b>：需要人工登記，或請系統管理員把該模組接進來源登記表。</li>
            <li><b>課室沒設通知對象</b>：會退回該單位最高主管，建議明確指定。</li>
        </ul>

        <h4>權限角色</h4>
        <ul>
            <li><b>AS排程檢閱</b>（asched_view）：唯讀看全部課室。具 <b>AS 文件檢閱權</b>者自動涵蓋。</li>
            <li><b>AS排程管理員</b>（asched_admin）：檢閱 ＋ 提醒設定 ＋ 通知對象 ＋ 代所有課室登記完成。</li>
            <li>系統管理者固定全權。角色在「使用者權限設定」頁指派。</li>
        </ul>
        <div style="font-size:11px;color:#8a6d45;margin-top:8px;">
            列印文件標頭一律取「本公司」全名（主檔管理客戶分頁設為本公司之客戶全名）。
        </div>
    </div>
    <div class="m-foot"><button class="b-ok" onclick="closeMask('helpUseMask')">我知道了</button></div>
</div></div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script src="../../resource/js/eg_date_fmt.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_date_fmt.js') ?>"></script>
<script src="../../resource/js/eg_input_rules.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_input_rules.js') ?>"></script>
<script src="../../resource/js/eg_print_log.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_print_log.js') ?>"></script>
<script>
$(document).ready(function(){
    var $am = $('#sidebar-menu .nav.side-menu > li.active');
    if ($am.length) { $am.removeClass('active').find('ul.child_menu').hide(); $am.find('li.current-page').removeClass('current-page'); }
    $('#sidebar-menu').css('visibility','visible');
});

var API   = '../../src/store/AsSchedule_API.php';
var CSRF  = <?= json_encode($_SESSION['asched_csrf']) ?>;
var CANADMIN = <?= $P['canAdmin'] ? 'true' : 'false' ?>;
var COMPANY  = <?= json_encode($companyName) ?>;
var CUR_YEAR = <?= (int)$reqYear ?>;
var THIS_YEAR= <?= (int)$curYear ?>;
var DATA = null, NTF = null, CUR_DONE = null;
/* 能不能改「更新頻率／負責課室」——由後端用與 save_doc_freq 完全相同的判定回傳
   （eg_asdoc_is_admin），不在前端自己猜，否則會出現「按鈕在、按下去被擋」 */
var CAN_EDIT_FREQ = false;

function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g, function(c){
    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
/** 日期顯示一律 YYYY.MM.DD（ai-rules/20，走共用 egFmtDate 不自寫） */
function dispDate(s){ return (window.egFmtDate ? egFmtDate(s) : String(s||'')); }
function openMask(id){ $('#'+id).addClass('on'); }
function closeMask(id){ $('#'+id).removeClass('on'); }
function toast(msg, bad){
    var $t = $('<div>').text(msg).css({position:'fixed', right:'18px', bottom:'18px', zIndex:10600,
        background: bad?'#DD5138':'#5F8A4F', color:'#fff', padding:'9px 15px', borderRadius:'6px',
        fontSize:'13px', boxShadow:'0 3px 12px rgba(0,0,0,.25)'});
    $('body').append($t); setTimeout(function(){ $t.fadeOut(300, function(){ $t.remove(); }); }, 2600);
}
$(document).ajaxError(function(e, xhr){
    var m = '';
    try { m = (JSON.parse(xhr.responseText||'{}').error) || ''; } catch(err){}
    if (m) toast(m, true);
});

/* ────────────────── 載入 ────────────────── */
function buildYearSel(){
    var h = '';
    for (var y = THIS_YEAR + 1; y >= THIS_YEAR - 5; y--) h += '<option value="'+y+'">'+y+' 年</option>';
    $('#fYear').html(h).val(CUR_YEAR);
}

function load(){
    $('#gridBox,#tblBox,#routineBox').html('<div class="as-empty">載入中…</div>');
    var dept = $('#fDept').val() || '';
    $.getJSON(API, {action:'plan', year: CUR_YEAR, dept_ids: dept}, function(res){
        if (!res || !res.ok) return;
        DATA = res;
        CAN_EDIT_FREQ = !!(res.perm && res.perm.edit_freq);
        buildDeptSel(res);
        renderKpis(); renderNote(); renderGrid(); renderTable(); renderRoutine(); renderGaps();
    });
}

/** 課室下拉：只列「實際有週期文件掛在上面」的課室，不要把全公司部門都攤出來 */
function buildDeptSel(res){
    if ($('#fDept option').length > 1) return;      // 已建好就不重建（避免洗掉使用者選的值）
    var m = {};
    (res.rows||[]).forEach(function(r){ (r.depts||[]).forEach(function(d){ m[d.id] = d.name; }); });
    var ks = Object.keys(m).sort(function(a,b){ return m[a].localeCompare(m[b], 'zh-Hant'); });
    var h = '<option value="">全部課室</option>';
    ks.forEach(function(k){ h += '<option value="'+k+'">'+esc(m[k])+'</option>'; });
    $('#fDept').html(h);
}

function renderKpis(){
    var s = DATA.summary || {};
    var cards = [
        ['',        'total',    '本年度排程點', '共 ' + (s.total||0) + ' 個週期'],
        ['overdue', 'overdue',  '逾期未完成',   '需要馬上處理'],
        ['due',     'due',      '即將到期',     '在提醒範圍內'],
        ['upcoming','upcoming', '尚未到期',     '還有時間'],
        ['done',    'done',     '已完成',       '本年度已做'],
        ['unknown', 'unknown',  '無法判定',     '查不到完成紀錄'],
        ['nomonth', 'nomonth',  '月份未定',     '知道要做不知幾月']
    ];
    var cur = $('#fState').val() || '';
    var h = '';
    cards.forEach(function(c){
        var on = (c[0] !== '' && c[0] === cur) ? ' on' : '';
        h += '<div class="as-kpi k-'+c[1]+on+'" data-state="'+c[0]+'">'
           +   '<div class="k-n">'+(s[c[1]]||0)+'</div>'
           +   '<div class="k-l">'+c[2]+'</div><div class="k-h">'+c[3]+'</div>'
           + '</div>';
    });
    $('#kpis').html(h);
}

function renderNote(){
    var s = DATA.summary || {}, st = DATA.settings || {}, g = DATA.gaps || {};
    var t = [];
    t.push('<b>本頁資料是即時推導的</b>，來源＝AS 文件管理登記的「更新頻率」＋「負責課室」＋各模組實際完成紀錄，本頁不儲存排程。');
    t.push('提醒：到期前 <b>'+(st.lead_days||0)+'</b> 天開始通知'
        + (st.overdue_repeat > 0 ? ('，逾期後每 <b>'+st.overdue_repeat+'</b> 天再提醒一次') : '，逾期只提醒一次')
        + '；總開關目前 <b>'+(st.notify_enabled ? '已啟用' : '關閉')+'</b>。');
    if (s.unknown > 0)
        t.push('有 <b>'+s.unknown+'</b> 個排程點標為「無法判定」——系統查不到那些文件的完成紀錄，'
            + '<b>一律不算逾期也不發提醒</b>（系統不知道不代表沒做）。請用該列的「登記完成」把事實補進來。');
    if (s.nomonth > 0)
        t.push('有 <b>'+s.nomonth+'</b> 個排程點只知道「本年度要做一次」、推不出月份，故沒有到期日與逾期天數。');
    if ((g.no_dept||[]).length)
        t.push('有 <b>'+g.no_dept.length+'</b> 份文件沒設負責課室，<b>算得出排程但不會發提醒</b>（不知道要發給誰）。');
    if (s.routine > 0)
        t.push('另有 <b>'+s.routine+'</b> 份每日／每週型的常態工作，列在下方「常態工作」區，不進月份排程。');
    $('#noteBox').html(t.join('<br>'));
}

/** 篩選（狀態＋關鍵字；課室已由後端處理） */
function filtered(){
    var st = $('#fState').val() || '', kw = ($('#fKw').val() || '').trim().toLowerCase();
    return (DATA.rows || []).filter(function(r){
        if (st && r.state !== st) return false;
        if (kw) {
            var hay = (r.doc_no + ' ' + r.doc_name + ' ' + (r.depts||[]).map(function(d){return d.name;}).join(' ')).toLowerCase();
            if (hay.indexOf(kw) < 0) return false;
        }
        return true;
    });
}

/* ────────────────── 年度格子表 ────────────────── */
function renderGrid(){
    var rows = filtered().filter(function(r){ return r.due_month > 0; });
    if (!rows.length) { $('#gridBox').html('<div class="as-empty">目前的篩選條件下沒有已排定月份的排程。</div>'); return; }

    // 一列一個課室；沒設負責課室的併成一列「（未設負責課室）」，不可整批消失
    var byDept = {}, names = {};
    rows.forEach(function(r){
        var ds = (r.depts && r.depts.length) ? r.depts : [{id:0, name:'（未設負責課室）'}];
        ds.forEach(function(d){
            names[d.id] = d.name;
            byDept[d.id] = byDept[d.id] || {};
            (byDept[d.id][r.due_month] = byDept[d.id][r.due_month] || []).push(r);
        });
    });
    var keys = Object.keys(byDept).sort(function(a,b){
        if (a === '0') return 1; if (b === '0') return -1;
        return names[a].localeCompare(names[b], 'zh-Hant');
    });

    var nowM = (CUR_YEAR === THIS_YEAR) ? (new Date().getMonth() + 1) : 0;
    var h = '<table class="as-grid"><thead><tr><th class="c-dept">負責課室</th>';
    for (var m = 1; m <= 12; m++) h += '<th'+(m===nowM?' style="background:#F0A24B;color:#fff;"':'')+'>'+m+' 月</th>';
    h += '</tr></thead><tbody>';
    keys.forEach(function(k){
        h += '<tr><td class="c-dept">'+esc(names[k])+'</td>';
        for (var m = 1; m <= 12; m++) {
            var cell = (byDept[k][m] || []);
            h += '<td class="c-m'+(m===nowM?' now':'')+'">';
            cell.forEach(function(r){ h += chipHtml(r); });
            h += '</td>';
        }
        h += '</tr>';
    });
    h += '</tbody></table>';
    $('#gridBox').html(h);
}

function chipHtml(r){
    var est = (r.month_src === 'infer') ? '<div class="est">（月份推估）</div>' : '';
    return '<div class="as-chip s-'+r.state+'" data-doc="'+r.doc_id+'" data-pk="'+esc(r.period_key)+'" '
         + 'title="'+esc(r.doc_no+' '+r.doc_name+'｜'+r.state_text+'｜月份'+r.month_src_text+'｜完成紀錄'+r.src_kind_text)+'">'
         + '<span class="cn">'+esc(r.doc_no)+'</span> '+esc(r.doc_name)+est+'</div>';
}

/* ────────────────── 明細表 ────────────────── */
function renderTable(){
    var rows = filtered();
    $('#rowCnt').text('共 ' + rows.length + ' 列' + (rows.length !== (DATA.rows||[]).length ? ('（總計 '+(DATA.rows||[]).length+' 列）') : ''));
    if (!rows.length) { $('#tblBox').html('<div class="as-empty">目前的篩選條件下沒有資料。</div>'); return; }
    var h = '<table class="as-tbl"><thead><tr>'
          + '<th>狀態</th><th>文件編號</th><th>文件名稱</th><th>負責課室</th><th>更新頻率</th>'
          + '<th>本期應完成</th><th>上次完成</th><th>完成紀錄來源</th><th>操作</th></tr></thead><tbody>';
    rows.forEach(function(r){
        var due = r.due_date ? dispDate(r.due_date) : '<span class="as-mini">月份未定</span>';
        // 「推估」與「月份未定」都是可以當場解決的——標成可點，直接開設定跳窗指定固定月份
        if (r.due_date && r.month_src === 'infer')
            due += CAN_EDIT_FREQ
                 ? ' <span class="as-badge b-est b-freq" data-doc="'+r.doc_id+'" style="cursor:pointer;" title="點此指定固定月份">推估 ✎</span>'
                 : ' <span class="as-badge b-est">推估</span>';
        else if (!r.due_date && CAN_EDIT_FREQ)
            due = '<span class="as-badge b-unknown b-freq" data-doc="'+r.doc_id+'" style="cursor:pointer;" title="點此設定月份">月份未定 ✎</span>';
        h += '<tr>'
          +  '<td><span class="as-badge b-'+r.state+'">'+esc(r.state_text)+'</span></td>'
          +  '<td style="white-space:nowrap;">'+esc(r.doc_no)+'</td>'
          +  '<td>'+esc(r.doc_name)+'</td>'
          +  '<td>'+((r.depts||[]).length ? esc(r.depts.map(function(d){return d.name;}).join('、'))
                     : (CAN_EDIT_FREQ
                        ? '<span class="as-badge b-unknown b-freq" data-doc="'+r.doc_id+'" style="cursor:pointer;" title="點此設定負責課室">未設定 ✎</span>'
                        : '<span class="as-badge b-unknown">未設定</span>'))+'</td>'
          +  '<td style="white-space:nowrap;">'+esc(r.freq_label)+'</td>'
          +  '<td style="white-space:nowrap;">'+due+'</td>'
          +  '<td style="white-space:nowrap;">'+(r.last_done ? dispDate(r.last_done) : '<span class="as-mini">查無紀錄</span>')+'</td>'
          +  '<td><span class="as-badge '+(r.src_kind==='auto'?'b-done':(r.src_kind==='none'?'b-unknown':'b-soft'))+'">'
          +      esc(r.src_kind_text)+'</span></td>'
          +  '<td style="white-space:nowrap;">'
          +    (r.can_mark ? '<button class="b-mark" data-doc="'+r.doc_id+'" data-pk="'+esc(r.period_key)+'" '
          +        'style="height:24px;font-size:11.5px;padding:0 8px;border:1px solid #D8BE93;border-radius:4px;'
          +        'background:'+(r.done?'#E7F0E2':'#fff')+';color:#5b3a1e;cursor:pointer;">'
          +        (r.done ? '已登記' : '登記完成') + '</button>' : '')
          +    (r.page_url ? ' <a href="'+esc(r.page_url)+'" target="_blank" rel="noopener" class="as-mini">開啟模組</a>' : '')
          +    (CAN_EDIT_FREQ ? ' <a href="javascript:void(0)" class="b-freq as-mini" data-doc="'+r.doc_id+'">頻率設定</a>' : '')
          +  '</td></tr>';
    });
    $('#tblBox').html(h + '</tbody></table>');
}

function renderRoutine(){
    var rows = DATA.routine || [];
    if (!rows.length) { $('#routineBox').html('<div class="as-empty">沒有每日／每週型的常態工作。</div>'); return; }
    var h = '<table class="as-tbl"><thead><tr><th>文件編號</th><th>文件名稱</th><th>負責課室</th>'
          + '<th>頻率</th><th>上次完成</th><th>完成紀錄來源</th><th>說明</th></tr></thead><tbody>';
    rows.forEach(function(r){
        h += '<tr><td style="white-space:nowrap;">'+esc(r.doc_no)+'</td><td>'+esc(r.doc_name)+'</td>'
          +  '<td>'+((r.depts||[]).length ? esc(r.depts.map(function(d){return d.name;}).join('、')) : '<span class="as-badge b-unknown">未設定</span>')+'</td>'
          +  '<td style="white-space:nowrap;">'+esc(r.freq_label)+'</td>'
          +  '<td style="white-space:nowrap;">'+(r.last_done ? dispDate(r.last_done) : '<span class="as-mini">查無紀錄</span>')+'</td>'
          +  '<td><span class="as-badge b-soft">'+esc(r.src_kind_text||'')+'</span></td>'
          +  '<td class="as-mini">'+esc(r.reason||'')+'</td></tr>';
    });
    $('#routineBox').html(h + '</tbody></table>');
}

function renderGaps(){
    var g = DATA.gaps || {};
    // 前兩項是「在這裡就改得掉」的（改的是 AS 文件管理的同一份設定），直接讓每一列可點
    var defs = [
        ['no_freq',  '沒設更新頻率',     CAN_EDIT_FREQ ? '這些文件完全不會出現在排程裡。點文件即可設定頻率。'
                                                       : '這些文件完全不會出現在排程裡。請 AS 文件管理員到該文件的 ⚙ →「更新頻率」設定。', 1],
        ['no_dept',  '沒設負責課室',     CAN_EDIT_FREQ ? '排程算得出來，但不知道要發給誰，所以不會發提醒。點文件即可設定。'
                                                       : '排程算得出來，但不知道要發給誰，所以不會發提醒。', 1],
        ['no_source','系統查不到完成紀錄','需要人工登記完成，或請系統管理員把該模組接進來源登記表。', 0],
        ['no_notify','課室沒設通知對象', '會退回該單位最高主管。建議在「通知對象」明確指定職位或人員。', 0]
    ];
    var h = '', any = false;
    defs.forEach(function(d){
        var list = g[d[0]] || [];
        if (!list.length) return;
        any = true;
        h += '<div class="as-gap"><h5>'+d[1]+'（'+list.length+'）</h5>'
          +  '<div class="as-mini" style="margin-bottom:4px;">'+d[2]+'</div><ul>';
        list.forEach(function(x){
            var tx = esc(x.doc_no ? (x.doc_no + ' ' + x.doc_name) : x.dept_name);
            h += '<li>' + (d[3] && CAN_EDIT_FREQ && x.doc_id
                 ? '<a href="javascript:void(0)" class="b-freq" data-doc="'+x.doc_id+'" style="color:#b5762a;">'+tx+' ✎</a>'
                 : tx) + '</li>';
        });
        h += '</ul></div>';
    });
    $('#gapBox').html(any ? '<div class="as-gaps">'+h+'</div>'
        : '<div class="as-empty" style="color:#5F8A4F;">沒有設定缺口，所有有週期的文件都設好了負責課室與通知對象。</div>');
}

/* ────────────────── 登記完成 ────────────────── */
function openDone(docId, pk){
    var r = (DATA.rows||[]).filter(function(x){ return x.doc_id == docId && x.period_key === pk; })[0];
    if (!r) return;
    if (!r.can_mark) { toast('只有該文件負責課室的人員或 AS 排程管理員可以登記完成', true); return; }
    CUR_DONE = r;
    var due = r.due_date ? dispDate(r.due_date) : '本年度（月份未定）';
    $('#doneInfo').html('<b>'+esc(r.doc_no)+' '+esc(r.doc_name)+'</b><br>'
        + '負責課室：'+((r.depts||[]).length ? esc(r.depts.map(function(d){return d.name;}).join('、')) : '（未設定）')
        + '　更新頻率：'+esc(r.freq_label)+'<br>'
        + '本期應完成：'+due+'（'+esc(r.state_text)+'）<br>'
        + '完成紀錄來源：'+esc(r.src_kind_text)
        + (r.page_url ? '<br><a href="'+esc(r.page_url)+'" target="_blank" rel="noopener">開啟該模組 →</a>' : ''));
    // 預設帶今天，但若週期年份不是今年（補歷史），帶該年度的月底
    var today = new Date(), y = parseInt(pk.substring(0,4), 10);
    var def;
    if (y === today.getFullYear()) def = today.toISOString().substring(0,10);
    else if (r.due_date)          def = r.due_date;
    else                          def = y + '-12-31';
    $('#dDate').val(r.done_date || def);
    $('#dNote').val('');
    $('#dDateErr').hide();
    $('#btnUnmark').toggle(!!r.done && r.last_src === 'manual');
    openMask('doneMask');
}

$(document).on('click', '.as-chip', function(){ openDone($(this).data('doc'), $(this).data('pk')); });
$(document).on('click', '.b-mark',  function(){ openDone($(this).data('doc'), $(this).data('pk')); });
// 頻率設定的入口有好幾處（推估籤／月份未定／未設負責課室／缺口清單／操作欄），
// 一律走事件委派＝重繪後的列也涵蓋，不必逐處綁定
$(document).on('click', '.b-freq', function(e){ e.stopPropagation(); openFreq($(this).data('doc')); });

$('#btnDoneSave').on('click', function(){
    if (!CUR_DONE) return;
    var d = $('#dDate').val();
    // 前端即時驗證，後端 asched_mark_done() 會用同一組規則再擋一次（鐵律8）
    if (!d) { $('#dDateErr').text('請填寫完成日期').show(); return; }
    if (d > new Date().toISOString().substring(0,10)) { $('#dDateErr').text('完成日期不可以是未來的日期').show(); return; }
    if (d.substring(0,4) !== CUR_DONE.period_key.substring(0,4)) {
        $('#dDateErr').text('完成日期的年份（'+d.substring(0,4)+'）與所登記的週期（'
            + CUR_DONE.period_key.substring(0,4)+'）不符').show(); return; }
    $('#dDateErr').hide();
    $.post(API, {action:'mark_done', csrf:CSRF, doc_id:CUR_DONE.doc_id,
                 period_key:CUR_DONE.period_key, done_date:d, note:$('#dNote').val()},
      function(res){ if (res && res.ok) { closeMask('doneMask'); toast(res.msg||'已登記完成'); load(); } }, 'json');
});

$('#btnUnmark').on('click', function(){
    if (!CUR_DONE) return;
    if (!confirm('確定要取消「'+CUR_DONE.doc_no+'」這一期的完成登記嗎？\n取消後這一期會重新依到期日判定是否逾期。')) return;
    $.post(API, {action:'unmark_done', csrf:CSRF, doc_id:CUR_DONE.doc_id, period_key:CUR_DONE.period_key},
      function(res){ if (res && res.ok) { closeMask('doneMask'); toast(res.msg||'已取消登記'); load(); } }, 'json');
});

/* ────────────────── 更新頻率 / 負責課室（就地設定） ──────────────────
   寫入打的是 AS_Document_API 的 save_doc_freq（唯一寫入點），本頁不自己存。 */
var FQ_DOC = null, FQ_DEPTS = [];

/** 這個頻率一年要排幾個月、彼此間隔幾個月。
 *  ★與後端 asFreqMonthPlan()（asdoc_lib.php，唯一實作）是同一套規則。
 *  本頁刻意做成「只選起始月份、其餘自動補齊」而不是自由複選——算出來的組合一定合法，
 *  使用者不可能選出「每 6 個月卻挑了 3、8 月」這種要被擋下的組合。 */
function fqMonthPlan(type, n){
    n = parseInt(n,10) || 1; if (n < 1) n = 1;
    if (type === 'year')    return {slots:1, interval:12*n};
    var m;
    if (type === 'quarter') m = n*3;
    else if (type === 'month') m = n;
    else return null;                      // 天／週／不定時／未設定：沒有月份可談
    if (m <= 1)  return null;              // 每月＝每個月都要做
    if (m >= 12) return {slots:1, interval:m};
    if (12 % m === 0) return {slots:12/m, interval:m};
    return {slots:1, interval:m};
}
/** 由起始月份補齊整年度的排程月份 */
function fqMonthsFrom(type, n, start){
    var plan = fqMonthPlan(type, n);
    start = parseInt(start,10);
    if (!plan || !start) return [];
    var out = [start];
    for (var i=1; i<plan.slots; i++) out.push(((start - 1 + plan.interval*i) % 12) + 1);
    out.sort(function(a,b){ return a-b; });
    return out;
}
function fqSyncUI(){
    var t = $('#fqType').val(), n = $('#fqN').val();
    var isIrr = (t === 'irregular'), none = (t === '');
    $('#fqN').prop('disabled', isIrr || none).closest('.m-row').find('#fqNHint')
        .text(none ? '' : (isIrr ? '（不定時沒有數量）' : ''));
    var plan = fqMonthPlan(t, n);
    $('#fqMonthRow').toggle(!!plan);
    $('#fqNoteRow').find('label').html(isIrr ? '頻率備註 <span style="color:#DD5138;">*</span>' : '頻率備註');
    if (plan) {
        var ms = fqMonthsFrom(t, n, $('#fqMonth').val());
        $('#fqMonthHint').text(ms.length
            ? ('本年度排程月份：' + ms.map(function(m){ return m + ' 月'; }).join('、')
               + (plan.slots > 1 ? ('（每 ' + plan.interval + ' 個月一次）') : ''))
            : '不指定＝由過去實際完成的月份推估');
    }
}

function openFreq(docId){
    $.getJSON(API, {action:'doc_freq_get', doc_id:docId}, function(res){
        if (!res || !res.ok) return;
        if (!res.can_edit) { toast('只有 AS 文件管理員可以設定更新頻率與負責課室', true); return; }
        FQ_DOC = res.doc; FQ_DEPTS = res.depts || [];
        $('#fqDocInfo').html('<b>' + esc(res.doc.doc_no) + ' ' + esc(res.doc.doc_name) + '</b>');
        $('#fqType').val(res.doc.freq_type || '');
        $('#fqN').val(res.doc.freq_n || 1);
        $('#fqNote').val(res.doc.freq_note || '');
        // 月份下拉
        var mh = '<option value="">不指定</option>';
        for (var m=1; m<=12; m++) mh += '<option value="'+m+'">'+m+' 月</option>';
        $('#fqMonth').html(mh);
        var cur = String(res.doc.freq_months || '').split(',').map(function(v){ return parseInt(v,10); })
                      .filter(function(v){ return v>=1 && v<=12; }).sort(function(a,b){ return a-b; });
        $('#fqMonth').val(cur.length ? cur[0] : '');
        // 負責課室
        var own = res.owner_dept_ids || [];
        var dh = '';
        FQ_DEPTS.forEach(function(d){
            dh += '<label><input type="checkbox" class="fq-dept" value="'+d.id+'"'
               +  (own.indexOf(d.id) >= 0 ? ' checked' : '')+'> '+esc(d.name)+'</label>';
        });
        $('#fqDeptBox').html(dh || '<div class="as-mini">沒有部門資料</div>');
        $('#fqMonthErr,#fqNoteErr').hide();
        fqSyncUI();
        openMask('freqMask');
    });
}
$('#fqType,#fqN,#fqMonth').on('change input', fqSyncUI);

$('#btnFreqSave').on('click', function(){
    if (!FQ_DOC) return;
    var t = $('#fqType').val(), n = $('#fqN').val() || '1';
    // 前端即時驗證（後端 asFreqValidate() 有同一套規則再擋一次＝鐵律8）
    if (t === 'irregular' && !$('#fqNote').val().trim()) {
        $('#fqNoteErr').text('選「不定時」時，頻率備註為必填（請說明什麼情況下會更新）').show(); return;
    }
    $('#fqNoteErr').hide();
    if (t !== '' && t !== 'irregular' && (parseInt(n,10) < 1 || parseInt(n,10) > 255)) {
        $('#fqMonthErr').text('更新頻率的數量請填 1~255 的整數').show(); return;
    }
    $('#fqMonthErr').hide();
    var ms = fqMonthsFrom(t, n, $('#fqMonth').val());
    var depts = $('#fqDeptBox .fq-dept:checked').map(function(){ return this.value; }).get();
    // ★寫入打 AS_Document_API 的 save_doc_freq（唯一寫入點），不是本頁的 API
    $.post('../../src/store/AS_Document_API.php?action=save_doc_freq', {
        ids: FQ_DOC.id,
        freq_type: t,
        freq_n: (t === '' || t === 'irregular') ? '' : n,
        freq_months: ms.join(','),
        freq_note: $('#fqNote').val() || '',
        owner_dept_ids: depts.join(',')
    }, function(r){
        if (!r || r.status !== 'success') { toast((r && r.message) || '儲存失敗', true); return; }
        closeMask('freqMask');
        toast('已更新「' + FQ_DOC.doc_no + '」的更新頻率 / 負責課室');
        load();
    }, 'json');
});

/* ────────────────── 提醒設定 ────────────────── */
$('#btnSettings').on('click', function(){
    var s = (DATA && DATA.settings) || {};
    $('#sEnabled').prop('checked', !!s.notify_enabled);
    $('#sLead').val(s.lead_days);
    $('#sRepeat').val(s.overdue_repeat);
    $('#sInfer').prop('checked', !!s.infer_month);
    openMask('setMask');
});
$('#btnSetSave').on('click', function(){
    $.post(API, {action:'settings_save', csrf:CSRF,
        lead_days: $('#sLead').val(), overdue_repeat: $('#sRepeat').val(),
        infer_month: $('#sInfer').is(':checked') ? 1 : 0,
        notify_enabled: $('#sEnabled').is(':checked') ? 1 : 0},
      function(res){ if (res && res.ok) { closeMask('setMask'); toast(res.msg||'設定已儲存'); load(); } }, 'json');
});

/* ────────────────── 通知對象 ────────────────── */
$('#btnNotify').on('click', function(){
    $.getJSON(API, {action:'notify_cfg_get'}, function(res){
        if (!res || !res.ok) return;
        NTF = res;
        var h = '';
        Object.keys(res.depts).forEach(function(k){ h += '<option value="'+k+'">'+esc(res.depts[k])+'</option>'; });
        $('#nDept').html(h || '<option value="">（目前沒有掛週期文件的課室）</option>');
        renderNtfBoxes();
        openMask('ntfMask');
    });
});

function renderNtfBoxes(){
    if (!NTF) return;
    var did = $('#nDept').val() || '';
    var cfg = (NTF.cfg && NTF.cfg[did]) || {};
    var pos = cfg.position || [], usr = cfg.user || [];
    $('#nDeptHint').text(Object.keys(cfg).length ? '' : '（此課室尚未設定，目前會退回該單位最高主管）');

    var h = '';
    (NTF.positions||[]).forEach(function(p){
        h += '<label><input type="checkbox" class="n-pos" value="'+p.id+'"'
          +  (pos.indexOf(p.id) >= 0 ? ' checked' : '')+'> '+esc(p.name)+'</label>';
    });
    $('#nPosBox').html(h || '<div class="as-mini">沒有職位資料</div>');
    renderNtfPeople(usr);
    $('#nPreview').text('');
}

function renderNtfPeople(checkedIds){
    var kw = ($('#nPeopleKw').val() || '').trim().toLowerCase();
    var keep = checkedIds || $('#nUserBox .n-usr:checked').map(function(){ return parseInt(this.value,10); }).get();
    var h = '';
    (NTF.people||[]).forEach(function(p){
        var posTx = (p.posts||[]).join('／');
        if (kw && (p.name + ' ' + posTx).toLowerCase().indexOf(kw) < 0 && keep.indexOf(p.id) < 0) return;
        h += '<label title="'+esc(posTx)+'"><input type="checkbox" class="n-usr" value="'+p.id+'"'
          +  (keep.indexOf(p.id) >= 0 ? ' checked' : '')+'> '+esc(p.name)
          +  (posTx ? ' <span class="as-mini">'+esc(posTx)+'</span>' : '')
          +  (p.leave_note ? ' <span class="as-badge b-soft">'+esc(p.leave_note)+'</span>' : '')
          +  '</label>';
    });
    $('#nUserBox').html(h || '<div class="as-mini">沒有符合的人員</div>');
}

$('#nDept').on('change', renderNtfBoxes);
$('#nPeopleKw').on('input', function(){ renderNtfPeople(); });

$('#btnNtfPreview').on('click', function(){
    var did = $('#nDept').val();
    if (!did) return;
    $('#nPreview').text('試算中…');
    $.getJSON(API, {action:'notify_preview', dept_id:did}, function(res){
        if (!res || !res.ok) return;
        var ns = (res.targets||[]).map(function(t){ return t.user_cname; });
        $('#nPreview').text(ns.length
            ? ('會發給：' + ns.join('、') + (res.fallback ? '（尚未設定，退回單位最高主管）' : ''))
            : '目前解析不到任何收件人——這個課室的排程不會發提醒');
    });
});

$('#btnNtfSave').on('click', function(){
    var did = $('#nDept').val();
    if (!did) { toast('請先選擇課室', true); return; }
    var pos = $('#nPosBox .n-pos:checked').map(function(){ return parseInt(this.value,10); }).get();
    var usr = $('#nUserBox .n-usr:checked').map(function(){ return parseInt(this.value,10); }).get();
    $.post(API, {action:'notify_cfg_save', csrf:CSRF, dept_id:did,
        position_ids: JSON.stringify(pos), user_ids: JSON.stringify(usr)},
      function(res){
        if (!res || !res.ok) return;
        // 存完即時回報會發給誰——只說「已儲存」的話管理員永遠不知道設定有沒有效
        toast((res.targets && res.targets.length)
            ? ('已儲存，會發給：' + res.targets.join('、'))
            : '已儲存，但目前解析不到任何收件人');
        $.getJSON(API, {action:'notify_cfg_get'}, function(r2){ if (r2 && r2.ok) { NTF = r2; renderNtfBoxes(); } });
      }, 'json');
});

/* ────────────────── 篩選事件 ────────────────── */
$('#fYear').on('change', function(){ CUR_YEAR = parseInt(this.value,10); load(); });
$('#fDept').on('change', load);
$('#btnReload').on('click', load);
$('#fState').on('change', function(){ renderKpis(); renderGrid(); renderTable(); });
var kwT = null;
$('#fKw').on('input', function(){ clearTimeout(kwT); kwT = setTimeout(function(){ renderGrid(); renderTable(); }, 300); });
$(document).on('click', '.as-kpi', function(){
    var s = $(this).data('state') || '';
    $('#fState').val(s === undefined ? '' : s);
    renderKpis(); renderGrid(); renderTable();
});
$('#btnPageHelp').on('click', function(){ openMask('helpUseMask'); });

/* ────────────────── 列印（A3 橫式，依 ai-rules/16）────────────────── */
$('#btnPrint').on('click', function(){
    if (!DATA) return;
    var w = window.open('', '_blank');
    if (!w) { toast('列印視窗被瀏覽器封鎖，請允許彈出視窗後再試', true); return; }
    w.document.write(printHtml());
    w.document.close();
    // 留列印紀錄（ai-rules/23）
    $.post(API, {action:'print_log', csrf:CSRF, year:CUR_YEAR}, function(){}, 'json');
});

function printHtml(){
    var rows = filtered().filter(function(r){ return r.due_month > 0; });
    var byDept = {}, names = {};
    rows.forEach(function(r){
        var ds = (r.depts && r.depts.length) ? r.depts : [{id:0, name:'（未設負責課室）'}];
        ds.forEach(function(d){
            names[d.id] = d.name;
            byDept[d.id] = byDept[d.id] || {};
            (byDept[d.id][r.due_month] = byDept[d.id][r.due_month] || []).push(r);
        });
    });
    var keys = Object.keys(byDept).sort(function(a,b){
        if (a === '0') return 1; if (b === '0') return -1;
        return names[a].localeCompare(names[b], 'zh-Hant');
    });

    var st = DATA.settings || {};
    var g = '';
    keys.forEach(function(k){
        g += '<tr><td class="d">'+esc(names[k])+'</td>';
        for (var m = 1; m <= 12; m++) {
            g += '<td>';
            (byDept[k][m] || []).forEach(function(r){
                // 列印版只印事實（文件與是否完成），不印「無法判定」這種內部提示字樣
                var mark = r.state === 'done' ? '✔' : '○';
                g += '<div class="it"><b>'+esc(r.doc_no)+'</b> '+esc(r.doc_name)+' '+mark
                  +  (r.month_src === 'infer' ? '<span class="e">(推估)</span>' : '') + '</div>';
            });
            g += '</td>';
        }
        g += '</tr>';
    });

    /* 統計一定要只算「這張表實際印出來的那些列」（rows 已濾掉月份未定與常態工作）。
       直接套畫面上的 DATA.summary 會變成「排程點 34、已完成 12、未完成 9」——34−12≠9，
       因為那 34 含了沒印出來的 unknown/nomonth，稽核一看就會問，而且無法解釋。 */
    var pDone = 0, pUndone = 0;
    rows.forEach(function(r){ if (r.state === 'done') pDone++; else pUndone++; });
    return '<!DOCTYPE html><html lang="zh-Hant"><head><meta charset="utf-8">'
      + '<title>AS 文件年度排程表 '+CUR_YEAR+'</title><style>'
      /* A3 橫式：直接宣告尺寸，不做「A4 放不下自動升 A3」——CSS 宣告的紙張大小是網頁單方面說的，
         紙匣實際放什麼是設備決定的，宣告錯會被裁掉（project_mgmt_ui.js 已踩過這個坑） */
      + '@page{size:420mm 297mm;margin:14mm 12mm 16mm;'
      +   '@bottom-left{content:"第 " counter(page) " 頁／共 " counter(pages) " 頁";font-size:9pt;color:#555;}}'
      + 'body{font-family:"Microsoft JhengHei","微軟正黑體",sans-serif;color:#000;margin:0;}'
      + 'h1{font-size:17pt;text-align:center;margin:0 0 2mm;}'
      + 'h2{font-size:13pt;text-align:center;margin:0 0 3mm;font-weight:normal;}'
      + '.meta{font-size:9pt;color:#333;margin-bottom:3mm;display:flex;flex-wrap:wrap;gap:0 14px;}'
      + 'table{width:100%;border-collapse:collapse;table-layout:fixed;}'
      + 'th,td{border:1px solid #000;padding:2px 3px;font-size:8.5pt;vertical-align:top;word-break:break-all;}'
      + 'thead th{background:#eee;text-align:center;}'
      + 'thead{display:table-header-group;}'            /* 表頭跨頁重複 */
      + 'tr{page-break-inside:avoid;}'                  /* 資料列不被切成兩半 */
      + 'th.d,td.d{width:26mm;text-align:left;font-weight:bold;}'
      + '.it{margin-bottom:1px;line-height:1.3;}'
      + '.e{font-size:7pt;color:#555;}'
      + '.lg{font-size:8.5pt;margin-top:3mm;color:#333;}'
      + '</style></head><body>'
      + '<h1>'+esc(COMPANY || '')+'</h1>'
      + '<h2>AS 文件年度排程表　'+CUR_YEAR+' 年度</h2>'
      + '<div class="meta">'
      +   '<span>列印日期：'+dispDate(new Date().toISOString().substring(0,10))+'</span>'
      +   '<span>本表排程點：'+rows.length+'　已完成：'+pDone+'　尚未完成：'+pUndone+'</span>'
      +   '<span>提醒設定：到期前 '+(st.lead_days||0)+' 天</span>'
      + '</div>'
      + '<table><thead><tr><th class="d">負責課室</th>'
      + (function(){ var t=''; for (var m=1;m<=12;m++) t += '<th>'+m+' 月</th>'; return t; })()
      + '</tr></thead><tbody>'+(g || '<tr><td class="d">—</td><td colspan="12">本年度無已排定月份的排程</td></tr>')+'</tbody></table>'
      + '<div class="lg">說明：✔＝已完成　○＝尚未完成　(推估)＝該文件未指定固定月份，月份由過去實際完成的月份推估。'
      + '　本表依 AS 文件管理登記的更新頻率與負責課室推導產生。</div>'
      + '<scr'+'ipt>window.onload=function(){setTimeout(function(){window.print();},250);};</scr'+'ipt>'
      + '</body></html>';
}

/* ────────────────── 啟動 ────────────────── */
buildYearSel();
load();
</script>
</body>
</html>
