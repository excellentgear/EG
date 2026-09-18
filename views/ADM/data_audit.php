<?php
/**
 * 資料稽核（流程順序稽核 ／ 基本資料稽核）— 2026-09-18 建立（使用者交辦）
 *
 * 分頁一：報價 → 訂單 → 製令 → 出貨 的日期順序、數量、單價、製程比對
 * 分頁二：客戶／廠商基本資料的編碼原則與欄位完整性檢核
 *
 * 資料一律走 src/store/DataAudit_API.php；共用邏輯 src/common/data_audit_lib.php
 */
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/ADM/data_audit.php";
    header("Location:../../index.php");
    exit;
}
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/data_audit_lib.php';

$db = (new DBConnection())->getPDO();
dqa_ensure_schema($db);
$perms = dqa_perms($db, dqa_current_user($db));
if (empty($_SESSION['dqa_csrf'])) $_SESSION['dqa_csrf'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['dqa_csrf'];
$roleLabel = $perms['isAdmin'] ? '系統管理者' : ($perms['canAdmin'] ? '資料稽核管理員' : ($perms['canView'] ? '檢閱' : '無權限'));
$thisYear = (int)date('Y');
$ownCompany = '';
try {
    $ownCompany = (string)$db->query("SELECT customer_full FROM customer_list WHERE is_own_company=1 LIMIT 1")->fetchColumn();
} catch (Throwable $e) {}
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>資料稽核</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <style>
        /* 側欄：CSS 藏起來、ready 時再顯示（鐵律6，CSS 與 JS 必須成對） */
        #sidebar-menu { visibility: hidden; }
        :root{ --ink:#4A3524; --cream:#FCF7F0; --sand:#F7E0BD; --amber:#F0A24B;
               --amber-d:#C77C1A; --coral:#DD5138; --line:#E4D3BC; --ok:#5C8A4A; }
        body { background:#F6F1EA; }
        .right_col .page-title { margin:8px 0 4px; overflow:hidden; clear:both; }
        .page-title h3 { color:var(--ink); margin:0; display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
        .page-help-btn { height:30px; font-size:13px; padding:0 12px; border:1px solid var(--amber-d);
                         border-radius:15px; background:#fff; color:var(--amber-d); }
        .page-help-btn:hover { background:var(--amber-d); color:#fff; }
        @media print { .page-help-btn { display:none !important; } }
        .warm-panel { background:#fff; border:1px solid var(--line); border-radius:8px; padding:12px; margin-bottom:12px; }
        .btn-warm { background:var(--amber); border:1px solid var(--amber-d); color:var(--ink); font-weight:bold; }
        .btn-warm:hover,.btn-warm:focus { background:var(--amber-d); color:#fff; }
        .btn-warm-o { background:#fff; border:1px solid var(--amber-d); color:var(--amber-d); }
        .btn-warm-o:hover { background:var(--sand); }
        .role-tag { font-size:12px; background:#EFE3CF; color:#6B4423; border-radius:10px; padding:2px 10px; font-weight:normal; }
        .muted-help { font-size:12px; color:#8a7560; }
        /* 分頁 */
        .dq-tabs { display:flex; gap:6px; border-bottom:2px solid var(--line); margin-bottom:12px; }
        .dq-tab { padding:8px 18px; cursor:pointer; border:1px solid var(--line); border-bottom:none;
                  border-radius:8px 8px 0 0; background:#EFE7DB; color:#6B4423; font-weight:bold; margin-bottom:-2px; }
        .dq-tab.on { background:#fff; color:var(--ink); border-bottom:2px solid #fff; }
        /* 篩選列 */
        .dq-bar { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
        .dq-bar label { margin:0; font-weight:normal; color:#6B4423; font-size:13px; }
        .dq-bar input[type=text], .dq-bar input[type=date], .dq-bar select {
            height:30px; border:1px solid var(--line); border-radius:4px; padding:2px 8px; font-size:13px; }
        /* 統計 */
        .dq-stat { display:flex; gap:8px; flex-wrap:wrap; align-items:stretch; }
        .dq-card { border:1px solid var(--line); border-radius:8px; padding:8px 14px; background:#fff; min-width:96px; text-align:center; }
        .dq-card b { display:block; font-size:22px; line-height:26px; }
        .dq-card span { font-size:12px; color:#8a7560; }
        .dq-card.c-critical b { color:var(--coral); }
        .dq-card.c-warn b { color:var(--amber-d); }
        .dq-card.c-ok b { color:var(--ok); }
        .dq-chips { display:flex; gap:6px; flex-wrap:wrap; margin-top:8px; }
        .dq-chip { font-size:12px; border:1px solid var(--line); border-radius:12px; padding:2px 10px;
                   background:#fff; cursor:pointer; color:#6B4423; }
        .dq-chip:hover { background:var(--sand); }
        .dq-chip.on { background:var(--amber-d); color:#fff; border-color:var(--amber-d); }
        .dq-chip.lv-critical { border-color:var(--coral); color:var(--coral); }
        .dq-chip.lv-critical.on { background:var(--coral); color:#fff; }
        /* 表格 */
        .dq-tbl { width:100%; border-collapse:collapse; font-size:13px; table-layout:fixed; }
        .dq-tbl th { background:var(--sand); color:#6B4423; padding:6px 8px; border:1px solid var(--line);
                     text-align:left; font-weight:bold; position:sticky; top:0; z-index:2; }
        .dq-tbl td { padding:5px 8px; border:1px solid var(--line); vertical-align:top; word-break:break-all; line-height:18px; }
        .dq-tbl tr.r-critical td { background:#FDF0EC; }
        .dq-tbl tr.r-warn td { background:#FFF9EF; }
        .dq-tbl tr.r-major td { background:#FFF9EF; }
        .dq-tbl tbody tr:hover td { background:#F7EFE2; }
        .dq-periods { display:inline-flex; gap:4px; flex-wrap:wrap; }
        .dq-periods button { border:1px solid var(--line); background:#fff; color:#6B4423;
                             border-radius:4px; height:28px; padding:0 12px; font-size:13px; }
        .dq-periods button:hover { background:var(--sand); }
        .dq-periods button.on { background:var(--amber-d); color:#fff; border-color:var(--amber-d); font-weight:bold; }
        .dq-pager { display:flex; align-items:center; gap:6px; justify-content:flex-end; margin:0 0 6px; font-size:13px; }
        .dq-pager button { border:1px solid var(--line); background:#fff; color:#6B4423; border-radius:4px;
                           min-width:28px; height:26px; padding:0 8px; }
        .dq-pager button.on { background:var(--amber-d); color:#fff; border-color:var(--amber-d); }
        .dq-pager button:disabled { color:#c9bba7; }
        .dq-pager select { height:26px; border:1px solid var(--line); border-radius:4px; font-size:12px; }
        .dq-wrap { max-height:62vh; overflow:auto; border:1px solid var(--line); border-radius:6px; background:#fff; }
        .lv-badge { display:inline-block; font-size:11px; line-height:18px; padding:0 8px; border-radius:9px; color:#fff; white-space:nowrap; }
        .lv-badge.critical { background:var(--coral); }
        .lv-badge.warn, .lv-badge.major { background:var(--amber-d); }
        .lv-badge.minor { background:#A79376; }
        .lv-badge.ok { background:var(--ok); }
        .src-tag { display:inline-block; font-size:10px; line-height:15px; padding:0 5px; border-radius:7px;
                   background:#EFE3CF; color:#8a7560; margin-left:3px; white-space:nowrap; }
        .src-tag.guess { background:#F3E2D0; color:#B07A3A; }
        .node { white-space:nowrap; }
        .node.bad { color:var(--coral); font-weight:bold; }
        .node em { font-style:normal; color:#8a7560; font-size:11px; }
        .doc-no { display:block; font-size:11px; line-height:16px; color:var(--amber-d); white-space:nowrap;
                  text-decoration:none; overflow:hidden; text-overflow:ellipsis; }
        a.doc-no:hover { text-decoration:underline; background:var(--sand); }
        .doc-no.more { color:#8a7560; }
        .issue-line { font-size:12px; margin:1px 0; }
        .issue-line i { width:14px; }
        .ex-line { font-size:11px; color:#8a7560; }
        .lnk { color:var(--amber-d); cursor:pointer; }
        .lnk:hover { text-decoration:underline; }
        /* 跳窗 */
        .m-mask { display:none; position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:10500; }
        .m-mask.on { display:flex; align-items:center; justify-content:center; }
        .m-box { background:#fff; border-radius:10px; width:880px; max-width:96vw; max-height:92vh; display:flex; flex-direction:column; }
        .m-head { padding:10px 16px; border-bottom:1px solid var(--line); font-weight:bold; color:var(--ink);
                  display:flex; align-items:center; gap:10px; }
        .m-head .x { margin-left:auto; cursor:pointer; color:#8a7560; font-size:20px; line-height:20px; }
        .m-body { padding:14px 16px; overflow:auto; }
        .m-foot { padding:10px 16px; border-top:1px solid var(--line); text-align:right; }
        .help-doc h4 { color:var(--amber-d); margin:14px 0 6px; font-size:15px; }
        .help-doc p, .help-doc li { font-size:13px; line-height:21px; color:#4A3524; }
        .set-tbl { width:100%; border-collapse:collapse; font-size:13px; }
        .set-tbl th, .set-tbl td { border:1px solid var(--line); padding:5px 8px; }
        .set-tbl th { background:var(--sand); color:#6B4423; text-align:left; }
        .doc-tag { display:inline-block; font-size:12px; background:var(--sand); color:#6B4423; border-radius:10px;
                   padding:2px 8px 2px 10px; margin:2px 4px 2px 0; }
        .doc-tag b { font-weight:bold; }
        .doc-tag .x { cursor:pointer; color:#B07A3A; margin-left:6px; font-weight:bold; }
        @media print { .no-print { display:none !important; } }
    </style>
</head>
<body class="nav-sm">
<div class="container body"><div class="main_container">
<?php include '../partPage/sideAndTopBarMenu.html'; ?>
<div class="right_col" role="main">
    <div class="page-title">
        <h3>
            資料稽核
            <span class="role-tag">目前角色：<?= htmlspecialchars($roleLabel) ?></span>
            <button type="button" class="page-help-btn" id="btnPageHelp"><i class="fa fa-question-circle"></i> 使用說明</button>
        </h3>
    </div>

<?php if (!$perms['canView']): ?>
    <div class="warm-panel" style="color:#B23A2A">
        您沒有「資料稽核」的檢閱權限。請洽系統管理員到「使用者權限設定」指派 <b>資料稽核檢閱</b> 或 <b>資料稽核管理員</b> 角色。
    </div>
<?php else: ?>

    <div class="dq-tabs no-print">
        <div class="dq-tab on" data-tab="trace">① 流程順序稽核（報價→訂單→製令→出貨）</div>
        <div class="dq-tab" data-tab="master">② 基本資料稽核（客戶／廠商）</div>
        <div style="margin-left:auto; display:flex; align-items:center; gap:6px;">
            <button class="btn btn-sm btn-warm-o" id="btnScope"><i class="fa fa-link"></i> 稽核對象</button>
            <?php if ($perms['canAdmin']): ?>
            <button class="btn btn-sm btn-warm-o" id="btnSetting"><i class="fa fa-cog"></i> 設定</button>
            <?php endif; ?>
            <button class="btn btn-sm btn-warm-o" id="btnRuns"><i class="fa fa-history"></i> 留存紀錄</button>
        </div>
    </div>

    <!-- ══════════ 分頁一：流程順序稽核 ══════════ -->
    <div id="pane-trace">
        <div class="warm-panel no-print">
            <div class="dq-bar" style="margin-bottom:6px">
                <label>年度</label>
                <select id="tYear" style="width:92px"></select>
                <label>期間</label>
                <select id="tGran" style="width:88px">
                    <option value="year">全年</option>
                    <option value="half">半年</option>
                    <option value="quarter" selected>季</option>
                    <option value="month">月</option>
                </select>
                <span id="tPeriods" class="dq-periods"></span>
            </div>
            <div class="dq-bar">
                <label>訂單日期</label>
                <input type="date" id="tFrom" value="<?= $thisYear ?>-01-01">
                <span>～</span>
                <input type="date" id="tTo" value="<?= $thisYear ?>-12-31">
                <label style="margin-left:8px">客戶</label>
                <select id="tClient" data-eg-filter="輸入客戶名稱篩選…" style="min-width:150px"><option value="">全部</option></select>
                <label>料號</label>
                <input type="text" id="tPart" placeholder="料號關鍵字" style="width:150px">
                <label style="margin-left:8px"><input type="checkbox" id="tOnlyBad" checked> 只看有異常的</label>
                <label><input type="checkbox" id="tProc"> 比對製程</label>
                <button class="btn btn-sm btn-warm" id="btnTraceRun"><i class="fa fa-search"></i> 開始稽核</button>
                <span class="muted-help" id="tTiming"></span>
            </div>
            <div class="muted-help" style="margin-top:6px">
                比對規則：報價日 ≦ 訂單日 ≦ 製令開立日 ≦ 出貨日；訂單設定「自動轉生管」者不必開製令，只比訂單日。
                有綁定的走綁定，沒綁定的用「同料號（主檔）／同客戶同料號」推測並標成
                <span class="src-tag guess">推測</span>，推測配對不判定數量不符。
                <b>製程比對預設關閉</b>——訂單的製程是手打、出貨的製程與規格混在同一欄，差異本來就大，需要時再勾。
            </div>
        </div>
        <div class="warm-panel no-print" id="tStatBox" style="display:none"></div>
        <div class="dq-pager no-print" id="tPager"></div>
        <div class="dq-wrap">
            <table class="dq-tbl" id="tTbl">
                <colgroup>
                    <col style="width:70px"><col style="width:120px"><col style="width:92px"><col style="width:132px">
                    <col style="width:118px"><col style="width:118px"><col style="width:118px"><col>
                </colgroup>
                <thead><tr>
                    <th>判定</th><th>訂單</th><th>客戶</th><th>料號</th>
                    <th>報價</th><th>製令</th><th>出貨</th><th>發現的問題</th>
                </tr></thead>
                <tbody><tr><td colspan="8" style="text-align:center; color:#8a7560; padding:24px">
                    請設定期間後按「開始稽核」。
                </td></tr></tbody>
            </table>
        </div>
        <div class="dq-bar no-print" style="margin-top:8px">
            <span class="muted-help" id="tFoot"></span>
            <span style="margin-left:auto"></span>
            <button class="btn btn-sm btn-warm-o" id="btnTraceCsv"><i class="fa fa-file-excel-o"></i> CSV</button>
            <button class="btn btn-sm btn-warm-o" id="btnTracePrint"><i class="fa fa-print"></i> 列印</button>
            <?php if ($perms['canAdmin']): ?>
            <button class="btn btn-sm btn-warm" id="btnTraceKeep"><i class="fa fa-save"></i> 留存稽核結果</button>
            <?php endif; ?>
        </div>
    </div>

    <!-- ══════════ 分頁二：基本資料稽核 ══════════ -->
    <div id="pane-master" style="display:none">
        <div class="warm-panel no-print">
            <div class="dq-bar">
                <label>對象</label>
                <select id="mType"><option value="customer">客戶基本資料表</option><option value="maker">廠商基本資料表</option></select>
                <label style="margin-left:8px">關鍵字</label>
                <input type="text" id="mQ" placeholder="編號或名稱" style="width:170px">
                <label style="margin-left:8px"><input type="checkbox" id="mOnlyBad" checked> 只看有缺失的</label>
                <label><input type="checkbox" id="mInact"> 含已停用</label>
                <button class="btn btn-sm btn-warm" id="btnMasterRun"><i class="fa fa-search"></i> 開始稽核</button>
                <span class="muted-help" id="mTiming"></span>
            </div>
            <div class="muted-help" style="margin-top:6px">
                <b>已停用者一律不納入稽核</b>（客戶＝停用勾選、廠商＝狀態 X）。
                缺失分三級：<span class="lv-badge critical">重要缺失</span> 影響聯絡、開立發票與帳務；
                <span class="lv-badge major">一般缺失</span> 該有但不影響日常；
                <span class="lv-badge minor">建議補齊</span>。
                確定不需開發票（現金交易）者，可對該筆標「已核可例外」，之後就不再列為缺失。
            </div>
        </div>
        <div class="warm-panel no-print" id="mStatBox" style="display:none"></div>
        <div class="dq-pager no-print" id="mPager"></div>
        <div class="dq-wrap">
            <table class="dq-tbl" id="mTbl">
                <colgroup><col style="width:80px"><col style="width:110px"><col style="width:150px"><col></colgroup>
                <thead><tr><th>判定</th><th>編號</th><th>名稱</th><th>缺少／不符的項目</th></tr></thead>
                <tbody><tr><td colspan="4" style="text-align:center; color:#8a7560; padding:24px">
                    請按「開始稽核」。
                </td></tr></tbody>
            </table>
        </div>
        <div class="dq-bar no-print" style="margin-top:8px">
            <span class="muted-help" id="mFoot"></span>
            <span style="margin-left:auto"></span>
            <button class="btn btn-sm btn-warm-o" id="btnMasterCsv"><i class="fa fa-file-excel-o"></i> CSV</button>
            <button class="btn btn-sm btn-warm-o" id="btnMasterPrint"><i class="fa fa-print"></i> 列印</button>
            <?php if ($perms['canAdmin']): ?>
            <button class="btn btn-sm btn-warm" id="btnMasterKeep"><i class="fa fa-save"></i> 留存稽核結果</button>
            <?php endif; ?>
        </div>
    </div>

<?php endif; ?>
</div></div></div>

<!-- 使用說明 -->
<div class="m-mask" id="helpUseMask"><div class="m-box" style="width:820px">
    <div class="m-head">資料稽核　使用說明 <span class="x" data-close>&times;</span></div>
    <div class="m-body help-doc">
        <h4>這頁在做什麼</h4>
        <p>把「同一支料號從報價一路到出貨」的四個節點攤開來比對，以及檢查客戶／廠商主檔的編號與欄位是否完善，
           作為 AS9100 內部稽核的客觀證據。兩個分頁都可以綁定「這次稽核涵蓋哪幾份 AS 表單」，
           留存結果後內部稽核就帶得出來。</p>

        <h4>① 流程順序稽核</h4>
        <p>操作步驟：<b>選年度 → 選期間粒度（全年／半年／季／月）→ 點期間鈕</b>（點下去就直接稽核），
           需要的話再選客戶或料號。上方統計橫幅點任一項目，下方表格就只留該項目，方便一次處理一種問題。</p>
        <p><b>為什麼預設是「季」</b>：一年的訂單有三、四千張，單次稽核上限 3,000 張，
           全年一次跑會被截斷、畫面也看不完。切成季或月逐段稽核最好查。
           兩個日期欄位仍可手動輸入任意區間。</p>
        <ul>
            <li><b>日期規則</b>：報價日 ≦ 訂單日、製令開立日 ≧ 訂單日、出貨日 ≧ 製令開立日。
                訂單若設定了「自動轉生管」＝不必開製令，這時只要求出貨日 ≧ 訂單日，也不會報「查不到製令」。</li>
            <li><b>製令開立日怎麼算</b>：優先用製令編號回推（B-民國年月日流水），
                因為 Created_At 是匯入這套系統的時間，不是現場實際開立的日期。</li>
            <li><b>怎麼配對</b>：有建立綁定的（訂單上的報價單號、訂單↔製令分配表、訂單↔出貨分配表）一律走綁定；
                沒綁定的用「料號主檔相同」為主、「同客戶同料號」為輔推測，並標上
                <span class="src-tag guess">推測</span>。推測配對<b>不判定數量不符</b>，
                也不會把日期先後判成嚴重——因為推測本來就可能配到同料號別張訂單的資料。</li>
            <li><b>製程比對</b>預設關閉。訂單的製程是人工手打（「齒研+雷刻」「代料完成」），
                出貨的製程由 ERP 轉出時與規格混在同一欄，所以差異很大；需要查的時候再勾「比對製程」。</li>
            <li><b>四種單號都可以點，開新分頁並自動帶好篩選</b>：
                報價單號 → 報價單管理（帶該報價年度並自動搜尋單號）；
                訂單編號 → 訂單追蹤（填進全表搜尋，該頁一有全表搜尋值就會自動切成「全部年份」）。</li>
            <li><b>製令與出貨的數量下方會列出單號，點下去直接開相關頁面並帶好篩選</b>：
                製令 → BOM 總表（自動填入該製令編號搜尋）；出貨 → 快速出貨的「近期出貨單」
                （自動以該單號查詢，日期區間帶該單前後 7 天）。都是開新分頁，不影響這一頁的稽核結果。
                一張出貨單在系統裡是好幾個明細列，這裡已依單號合併，所以看到的是張數不是列數。</li>
            <li>同一支料號的多張訂單對到同一張報價單是正常的，不會被判成異常。
                製令與訂單、出貨也不是一對一，所以數量一律用<b>合計</b>比對。</li>
        </ul>

        <h4>② 基本資料稽核</h4>
        <ul>
            <li>編號是否符合編碼原則（預設：英文 2 碼＋數字 3 碼，可再加英文 1 碼，如 <b>AB001</b>、<b>AB001A</b>），
                規則可在「設定」調整。</li>
            <li>欄位完整性依三級列出缺少什麼。傳真、EMAIL 這種「不是每間都有」的預設不檢查；
                結帳方式、結帳日、付款方式這類帳務資訊一律列為重要缺失。</li>
            <li>已停用者不納入稽核。確定無統編（現金交易）、或編號沿用舊制不打算改的，
                按該列的「標為例外」，之後就不再列為缺失，並會記下是誰、什麼時候、為什麼核可的。</li>
        </ul>

        <h4>稽核對象與留存</h4>
        <p><b>稽核對象</b>＝這次稽核是在查哪幾份 AS 表單（可多選）。
           這與「某個頁面是某張 AS 表單的網頁版」那種一對一綁定不同，所以另外存放、不會影響其他頁面的表頭表尾。</p>
        <p>按<b>留存稽核結果</b>會把當下的統計存起來（含期間與涵蓋的表單），
           內部稽核的系統稽核紀錄表就能引用「這份表單最近一次資料稽核發現幾筆問題」。</p>

        <h4>權限</h4>
        <ul>
            <li><b>資料稽核檢閱</b>（dqa_view）：查詢、匯出、列印。</li>
            <li><b>資料稽核管理員</b>（dqa_admin）：另可改設定、標記例外、綁定稽核對象、留存結果。</li>
            <li>系統管理者一律具備全部權限。</li>
        </ul>
    </div>
    <div class="m-foot"><button class="btn btn-sm btn-warm" data-close>關閉</button></div>
</div></div>

<!-- 稽核對象 -->
<div class="m-mask" id="scopeMask"><div class="m-box" style="width:720px">
    <div class="m-head">稽核對象：這次稽核涵蓋哪幾份 AS 表單 <span class="x" data-close>&times;</span></div>
    <div class="m-body">
        <p class="muted-help">
            綁定之後，按「留存稽核結果」時會把這些表單一起記下來，內部稽核（系統稽核紀錄表）就查得到
            「這份表單最近一次資料稽核發現幾筆問題」。<br>
            這裡綁的是<b>稽核涵蓋範圍（可多選）</b>，和「某個頁面＝某張表單的網頁版」那種一對一綁定是兩回事，
            不會影響任何表單的表頭表尾編號。
        </p>
        <div style="margin:10px 0">
            <b>① 流程順序稽核</b>
            <div id="scopeTrace" style="margin:6px 0; min-height:28px"></div>
            <button class="btn btn-xs btn-warm-o" data-add="trace"><i class="fa fa-plus"></i> 加入表單</button>
        </div>
        <div style="margin:14px 0 0">
            <b>② 基本資料稽核</b>
            <div id="scopeMaster" style="margin:6px 0; min-height:28px"></div>
            <button class="btn btn-xs btn-warm-o" data-add="master"><i class="fa fa-plus"></i> 加入表單</button>
        </div>
    </div>
    <div class="m-foot">
        <button class="btn btn-sm btn-default" data-close>關閉</button>
        <?php if ($perms['canAdmin']): ?>
        <button class="btn btn-sm btn-warm" id="btnScopeSave">儲存</button>
        <?php endif; ?>
    </div>
</div></div>

<!-- 設定 -->
<div class="m-mask" id="setMask"><div class="m-box" style="width:900px">
    <div class="m-head">資料稽核設定 <span class="x" data-close>&times;</span></div>
    <div class="m-body" id="setBody"></div>
    <div class="m-foot">
        <button class="btn btn-sm btn-default" data-close>關閉</button>
        <button class="btn btn-sm btn-warm" id="btnSetSave">儲存設定</button>
    </div>
</div></div>

<!-- 例外 -->
<div class="m-mask" id="exMask"><div class="m-box" style="width:560px">
    <div class="m-head">標記為已核可的例外 <span class="x" data-close>&times;</span></div>
    <div class="m-body">
        <p id="exWhat" style="font-weight:bold; color:var(--ink)"></p>
        <p class="muted-help">標記之後這一項就不再列為缺失，並會記下是誰、什麼時候、為什麼核可的。<br>
           例如：這家客戶是現金交易不需開發票（無統編）、編號沿用舊制不打算變更。</p>
        <label style="font-weight:normal">原因（選填，建議填寫以便日後查核）</label>
        <input type="text" id="exReason" class="form-control" maxlength="300" placeholder="">
    </div>
    <div class="m-foot">
        <button class="btn btn-sm btn-default" data-close>取消</button>
        <button class="btn btn-sm btn-warm" id="btnExSave">確認標記</button>
    </div>
</div></div>

<!-- 留存紀錄 -->
<div class="m-mask" id="runMask"><div class="m-box" style="width:820px">
    <div class="m-head">留存的稽核結果 <span class="x" data-close>&times;</span></div>
    <div class="m-body" id="runBody"></div>
    <div class="m-foot"><button class="btn btn-sm btn-default" data-close>關閉</button></div>
</div></div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/fastclick.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script src="../../resource/js/eg_date_fmt.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_date_fmt.js') ?>"></script>
<script src="../../resource/js/eg_asdoc_picker.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_asdoc_picker.js') ?>"></script>
<script src="../../resource/js/eg_print_log.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_print_log.js') ?>"></script>
<script src="../../resource/js/eg_input_rules.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_input_rules.js') ?>"></script>
<script>
$(document).ready(function(){ $('#sidebar-menu').css('visibility','visible'); });

var API  = '../../src/store/DataAudit_API.php';
var CSRF = <?= json_encode($CSRF) ?>;
var CAN_ADMIN = <?= $perms['canAdmin'] ? 'true' : 'false' ?>;
var OWN_COMPANY = <?= json_encode($ownCompany) ?>;
var ST = { tab:'trace', trace:null, master:null, filter:'', mFilter:'', settings:null, ex:null,
           tPage:1, tPer:50, mPage:1, mPer:50 };

/* 分頁列（鈕在列表右上；CSV／列印一律用全部符合條件的資料，不是只有這一頁） */
function renderPager(boxId, total, page, per, onGo){
    var box = $('#'+boxId);
    if (!total){ box.empty(); return; }
    var pages = (per === 0) ? 1 : Math.max(1, Math.ceil(total/per));
    if (page > pages) page = pages;
    var h = '<span class="muted-help">共 ' + total + ' 筆</span>';
    h += '<select data-per>' + [20,50,100,0].map(function(n){
        return '<option value="'+n+'"'+(per===n?' selected':'')+'>'+(n===0?'全部':('每頁 '+n))+'</option>';
    }).join('') + '</select>';
    if (pages > 1){
        h += '<button data-go="1"'+(page===1?' disabled':'')+'>«</button>';
        h += '<button data-go="'+(page-1)+'"'+(page===1?' disabled':'')+'>‹</button>';
        var s0 = Math.max(1, page-2), e0 = Math.min(pages, s0+4); s0 = Math.max(1, e0-4);
        for (var i=s0; i<=e0; i++) h += '<button data-go="'+i+'"'+(i===page?' class="on"':'')+'>'+i+'</button>';
        h += '<button data-go="'+(page+1)+'"'+(page===pages?' disabled':'')+'>›</button>';
        h += '<button data-go="'+pages+'"'+(page===pages?' disabled':'')+'>»</button>';
    }
    box.html(h);
    box.off('click.pg change.pg')
       .on('click.pg','button[data-go]', function(){ onGo(parseInt($(this).data('go'),10), per); })
       .on('change.pg','[data-per]', function(){ onGo(1, parseInt($(this).val(),10)); });
}

function esc(s){ return String(s===null||s===undefined?'':s)
    .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function dispDate(s){ return (window.egFmtDate ? egFmtDate(s) : (s||'')); }
function openMask(id){ $('#'+id).addClass('on'); }
function closeMask(id){ $('#'+id).removeClass('on'); }
$(document).on('click','[data-close]', function(){ $(this).closest('.m-mask').removeClass('on'); });
$('.m-mask').on('click', function(e){ if(e.target===this) $(this).removeClass('on'); });
$('#btnPageHelp').on('click', function(){ openMask('helpUseMask'); });
$(document).ajaxError(function(e, xhr){
    var m = '';
    try { m = (JSON.parse(xhr.responseText)||{}).error || ''; } catch(err){ m = ''; }
    if (m) alert(m); else if (xhr.status) alert('操作失敗（HTTP ' + xhr.status + '）');
});

/* ══ 分頁切換 ══ */
$('.dq-tab').on('click', function(){
    var t = $(this).data('tab');
    ST.tab = t;
    $('.dq-tab').removeClass('on'); $(this).addClass('on');
    $('#pane-trace').toggle(t==='trace');
    $('#pane-master').toggle(t==='master');
});

/* ══════════ 分頁一：流程順序稽核 ══════════ */
/* 期間切換：年度 × 粒度（全年／半年／季／月）
 * 使用者要求「分成每季或每月每半年呈現，避免資料過多」——一年的訂單有三千張，
 * 全年一次稽核會撞到單次上限、畫面也看不完，切成季或月逐段看才查得動。 */
function periodList(){
    var y = parseInt($('#tYear').val(), 10) || (new Date()).getFullYear();
    var g = $('#tGran').val();
    var pad = function(n){ return (n < 10 ? '0' : '') + n; };
    var last = function(m){ return new Date(y, m, 0).getDate(); };      // 該月最後一天
    var seg = function(name, m1, m2){
        return {name:name, from:y + '-' + pad(m1) + '-01', to:y + '-' + pad(m2) + '-' + pad(last(m2))};
    };
    if (g === 'year')    return [seg('全年', 1, 12)];
    if (g === 'half')    return [seg('上半年', 1, 6), seg('下半年', 7, 12)];
    if (g === 'quarter') return [seg('Q1', 1, 3), seg('Q2', 4, 6), seg('Q3', 7, 9), seg('Q4', 10, 12)];
    var out = [];
    for (var m = 1; m <= 12; m++) out.push(seg(m + '月', m, m));
    return out;
}
function renderPeriods(autoPick){
    var list = periodList(), f = $('#tFrom').val(), t = $('#tTo').val();
    var hit = -1;
    list.forEach(function(p, i){ if (p.from === f && p.to === t) hit = i; });
    if (hit < 0 && autoPick !== false){
        // 換年度或換粒度時，自動挑「包含目前起日」的那一段，沒有就挑第一段
        hit = 0;
        list.forEach(function(p, i){ if (f >= p.from && f <= p.to) hit = i; });
        $('#tFrom').val(list[hit].from); $('#tTo').val(list[hit].to);
    }
    $('#tPeriods').html(list.map(function(p, i){
        return '<button type="button" data-pi="' + i + '"' + (i === hit ? ' class="on"' : '') + '>' + esc(p.name) + '</button>';
    }).join(''));
}
$('#tYear, #tGran').on('change', function(){ renderPeriods(true); loadClients(); });
$(document).on('click', '#tPeriods button', function(){
    var p = periodList()[parseInt($(this).data('pi'), 10)];
    if (!p) return;
    $('#tFrom').val(p.from); $('#tTo').val(p.to);
    renderPeriods(false); loadClients();
    $('#btnTraceRun').click();
});
/* 手動改日期就把期間按鈕的選取狀態同步掉（不硬把日期改回去，自訂區間照樣可用） */
$('#tFrom, #tTo').on('change', function(){ renderPeriods(false); });

function loadYears(){
    $.get(API, {action:'trace_years'}, function(r){
        if(!r || !r.ok) return;
        var ys = (r.years||[]), cur = (new Date()).getFullYear();
        if (!ys.length) ys = [cur];
        $('#tYear').html(ys.map(function(y){
            return '<option value="' + y + '"' + (+y === cur ? ' selected' : '') + '>' + y + '</option>';
        }).join(''));
        if (ys.indexOf(cur) < 0) $('#tYear').val(ys[0]);
        renderPeriods(true);
        loadClients();
    });
}
function loadClients(){
    $.get(API, {action:'trace_clients', from:$('#tFrom').val(), to:$('#tTo').val()}, function(r){
        if(!r || !r.ok) return;
        var cur = $('#tClient').val();
        var h = '<option value="">全部</option>';
        (r.rows||[]).forEach(function(x){
            h += '<option value="'+esc(x.c)+'">'+esc(x.c)+'（'+x.n+'）</option>';
        });
        $('#tClient').html(h).val(cur);
    });
}

$('#btnTraceRun').on('click', function(){
    var t0 = Date.now();
    $('#tTiming').text('稽核中…');
    $('#tTbl tbody').html('<tr><td colspan="8" style="text-align:center;padding:24px;color:#8a7560">稽核中，請稍候…</td></tr>');
    $.get(API, {
        action:'trace_list', from:$('#tFrom').val(), to:$('#tTo').val(),
        client:$('#tClient').val(), part:$('#tPart').val(),
        cmp_process: $('#tProc').is(':checked') ? 1 : 0,
        only_bad: $('#tOnlyBad').is(':checked') ? 1 : 0
    }, function(r){
        if(!r || !r.ok) return;
        ST.trace = r; ST.filter = ''; ST.tPage = 1;
        renderTraceStat(); renderTrace();
        $('#tTiming').text('耗時 ' + ((Date.now()-t0)/1000).toFixed(1) + ' 秒');
    });
});

function renderTraceStat(){
    var r = ST.trace; if(!r) return;
    var s = r.stat, items = r.items || {};
    var h = '<div class="dq-stat">';
    h += '<div class="dq-card"><b>' + (r.scanned||0) + '</b><span>掃描訂單</span></div>';
    h += '<div class="dq-card c-critical"><b>' + (s.critical||0) + '</b><span>嚴重</span></div>';
    h += '<div class="dq-card c-warn"><b>' + (s.warn||0) + '</b><span>提醒</span></div>';
    h += '<div class="dq-card c-ok"><b>' + (s.ok||0) + '</b><span>正常</span></div>';
    h += '</div><div class="dq-chips">';
    h += '<span class="dq-chip' + (ST.filter===''?' on':'') + '" data-f="">全部（' + s.total + '）</span>';
    var codes = Object.keys(s.by_code||{}).sort(function(a,b){ return s.by_code[b]-s.by_code[a]; });
    codes.forEach(function(c){
        var it = items[c] || [c,'warn'];
        h += '<span class="dq-chip lv-' + esc(it[1]) + (ST.filter===c?' on':'') + '" data-f="' + esc(c) + '">'
           + esc(it[0]) + '（' + s.by_code[c] + '）</span>';
    });
    h += '</div>';
    if (r.truncated) h += '<div class="muted-help" style="margin-top:6px;color:#B23A2A">'
        + '⚠ 這個期間共有 ' + (r.order_total||0) + ' 張訂單，超過單次上限 ' + (r.limit||0)
        + ' 張，只稽核了最近的 ' + (r.scanned||0) + ' 張。'
        + '請用上方的<b>期間</b>切成季或月逐段稽核，或指定客戶／料號。</div>';
    $('#tStatBox').show().html(h);
}
$(document).on('click', '#tStatBox .dq-chip', function(){
    ST.filter = String($(this).data('f')||''); ST.tPage = 1; renderTraceStat(); renderTrace();
});

function traceRows(){
    var rows = (ST.trace && ST.trace.rows) || [];
    if (!ST.filter) return rows;
    return rows.filter(function(r){
        return r.issues.some(function(i){ return i.code === ST.filter; });
    });
}
function nodeHtml(txt, sub, bad, src, docs){
    var h = '<span class="node' + (bad?' bad':'') + '">' + esc(txt);
    if (src) h += '<span class="src-tag' + (src==='guess'?' guess':'') + '">'
        + (src==='guess'?'推測':(src==='bind'||src==='map'?'綁定':'舊綁定')) + '</span>';
    h += '</span>';
    if (sub) h += '<br><em>' + esc(sub) + '</em>';
    if (docs && docs.length) h += docLinks(docs);
    return h;
}
/* 單號連結：點了直接開相關頁面並自動帶入這個單號當篩選條件
 *   製令 → BOM 總表（本來就支援 ?global_search=，不必改那一頁）
 *   出貨 → 快速出貨（新版）的「近期出貨單」，同時把日期區間設成該單前後 7 天，
 *          不然那個跳窗預設只載入最近一段期間、舊單會查不到 */
function docLinks(docs){
    var out = '', max = 4;
    docs.slice(0, max).forEach(function(d){
        var u = d.url || '';
        out += u ? ('<a class="doc-no" href="' + esc(u) + '" target="_blank" rel="noopener" title="'
                    + esc(d.tip || d.no) + '">' + esc(d.no) + '</a>')
                 : ('<span class="doc-no">' + esc(d.no) + '</span>');
    });
    if (docs.length > max) out += '<span class="doc-no more">…另 ' + (docs.length - max) + ' 張</span>';
    return out;
}
function shiftDate(d, n){
    var t = Date.parse(String(d||'').slice(0,10));
    if (isNaN(t)) return '';
    var x = new Date(t + n*86400000);
    return x.getFullYear() + '-' + ('0'+(x.getMonth()+1)).slice(-2) + '-' + ('0'+x.getDate()).slice(-2);
}
function bomDocs(list){
    return (list||[]).map(function(b){
        return {no:b.no, url:'../pm/OreadyReply_ForPm_BaseOfTime.php?global_search=' + encodeURIComponent(b.no),
                tip:'在 BOM 總表搜尋 ' + b.no + '（' + (b.date||'') + '，' + b.qty + ' 支）'};
    });
}
function quoteUrl(no, date){
    var y = String(date||'').slice(0,4);
    return '../Sales/quotation_list_NEW.php' + (y ? ('?year=' + y + '&kw=') : '?kw=') + encodeURIComponent(no);
}
/* 訂單追蹤的全表搜尋一有值就會自動切成「全部年份」，所以不必帶年度 */
function orderUrl(no){
    return '../Sales/NewOrder_Track.php?kw=' + encodeURIComponent(no);
}
function shipDocs(list){
    return (list||[]).map(function(x){
        var f = shiftDate(x.date, -7), t = shiftDate(x.date, 7), u = '../Sales/Shipping_Quick.php?is_no=' + encodeURIComponent(x.no);
        if (f && t) u += '&from=' + f + '&to=' + t;
        return {no:x.no, url:u, tip:'在快速出貨的近期出貨單查 ' + x.no + '（' + (x.date||'') + '，' + x.qty + ' 支）'};
    });
}
function renderTrace(){
    var all = traceRows();
    renderPager('tPager', all.length, ST.tPage, ST.tPer, function(p, per){
        ST.tPage = p; ST.tPer = per; renderTrace();
    });
    var pages = ST.tPer === 0 ? 1 : Math.max(1, Math.ceil(all.length/ST.tPer));
    if (ST.tPage > pages) ST.tPage = pages;
    var rows = ST.tPer === 0 ? all : all.slice((ST.tPage-1)*ST.tPer, ST.tPage*ST.tPer);
    if (!rows.length){
        $('#tTbl tbody').html('<tr><td colspan="8" style="text-align:center;padding:24px;color:#8a7560">'
            + (ST.trace ? '沒有符合條件的資料。' : '請按「開始稽核」。') + '</td></tr>');
        $('#tFoot').text(ST.trace ? '顯示 0 筆' : '');
        return;
    }
    var badCodes = {};
    var h = rows.map(function(r){
        badCodes = {}; r.issues.forEach(function(i){ badCodes[i.code] = 1; });
        var qBad = badCodes.q_late || badCodes.q_none || badCodes.q_old;
        var bBad = badCodes.b_early || badCodes.b_none;
        var sBad = badCodes.s_early_bom || badCodes.s_early_order || badCodes.s_none;
        var q = r.quote
            ? nodeHtml(dispDate(r.quote.date), '＠' + r.quote.price, qBad, r.quote.src,
                       [{no:r.quote.no, url:quoteUrl(r.quote.no, r.quote.date),
                         tip:'在報價單管理搜尋 ' + r.quote.no}])
            : '<span class="node bad">無報價</span>';
        var b = r.bom.cnt
            ? nodeHtml(dispDate(r.bom.date), r.bom.cnt + ' 張／' + r.bom.qty + ' 支', bBad, r.bom.src, bomDocs(r.bom.list))
            : (r.auto_pm ? '<span class="node"><em>自動轉生管<br>不需製令</em></span>' : '<span class="node bad">無製令</span>');
        var sh = r.ship.cnt
            ? nodeHtml(dispDate(r.ship.date), (r.ship.doc_cnt||r.ship.cnt) + ' 張／' + r.ship.qty + ' 支',
                       sBad, r.ship.src, shipDocs(r.ship.list))
            : '<span class="node' + (r.closed?' bad':'') + '">未出貨</span>';
        var iss = r.issues.map(function(i){
            return '<div class="issue-line"><span class="lv-badge ' + i.level + '">'
                 + (i.level==='critical'?'嚴重':'提醒') + '</span> ' + esc(i.text)
                 + (CAN_ADMIN ? ' <span class="lnk" data-ex-trace="' + r.order_id + '" data-code="' + esc(i.code) + '">標為例外</span>' : '')
                 + '</div>';
        }).join('');
        if (!iss) iss = '<span style="color:#5C8A4A">四個節點的日期、數量與單價都對得起來。</span>';
        var exKeys = Object.keys(r.exempt||{});
        if (exKeys.length) iss += exKeys.map(function(k){
            var e = r.exempt[k];
            return '<div class="ex-line">已核可例外：' + esc(k==='*'?'整筆不稽核':k) + '（' + esc(e.by) + ' ' + dispDate(e.at) + '）'
                 + (CAN_ADMIN ? ' <span class="lnk" data-exdel-trace="' + r.order_id + '" data-code="' + esc(k) + '">取消</span>' : '')
                 + '</div>';
        }).join('');
        return '<tr class="r-' + r.level + '">'
            + '<td><span class="lv-badge ' + r.level + '">'
                + (r.level==='critical'?'嚴重':(r.level==='warn'?'提醒':'正常')) + '</span></td>'
            + '<td><a class="doc-no" style="font-size:13px" target="_blank" rel="noopener" href="'
                + esc(orderUrl(r.order_no)) + '" title="在訂單追蹤全表搜尋 ' + esc(r.order_no) + '">'
                + esc(r.order_no) + '</a><em class="muted-help">' + dispDate(r.odate) + '</em>'
                + (r.closed?'<br><em class="muted-help">已結案</em>':'') + '</td>'
            + '<td>' + esc(r.client) + '</td>'
            + '<td>' + esc(r.part) + '<br><em class="muted-help">' + r.oqty + ' 支 ＠' + r.oprice + '</em></td>'
            + '<td>' + q + '</td><td>' + b + '</td><td>' + sh + '</td>'
            + '<td>' + iss + '</td></tr>';
    }).join('');
    $('#tTbl tbody').html(h);
    $('#tFoot').text('本頁 ' + rows.length + ' 筆／符合條件 ' + all.length + ' 筆（共稽核 '
        + (ST.trace.scanned||0) + ' 張訂單）');
}

/* ══════════ 分頁二：基本資料稽核 ══════════ */
$('#btnMasterRun').on('click', function(){
    var t0 = Date.now();
    $('#mTiming').text('稽核中…');
    $.get(API, {
        action:'master_list', type:$('#mType').val(), q:$('#mQ').val(),
        only_bad: $('#mOnlyBad').is(':checked') ? 1 : 0,
        include_inactive: $('#mInact').is(':checked') ? 1 : 0
    }, function(r){
        if(!r || !r.ok) return;
        ST.master = r; ST.mFilter = ''; ST.mPage = 1;
        renderMasterStat(); renderMaster();
        $('#mTiming').text('耗時 ' + ((Date.now()-t0)/1000).toFixed(1) + ' 秒');
    });
});
$('#mType').on('change', function(){ if(ST.master) $('#btnMasterRun').click(); });

function renderMasterStat(){
    var r = ST.master; if(!r) return;
    var s = r.stat, f = r.fields || {};
    var h = '<div class="dq-stat">';
    h += '<div class="dq-card"><b>' + s.total + '</b><span>納入稽核</span></div>';
    h += '<div class="dq-card c-critical"><b>' + (s.critical||0) + '</b><span>重要缺失</span></div>';
    h += '<div class="dq-card c-warn"><b>' + (s.major||0) + '</b><span>一般缺失</span></div>';
    h += '<div class="dq-card"><b>' + (s.minor||0) + '</b><span>建議補齊</span></div>';
    h += '<div class="dq-card c-ok"><b>' + (s.ok||0) + '</b><span>資料完善</span></div>';
    h += '</div><div class="dq-chips">';
    h += '<span class="dq-chip' + (ST.mFilter===''?' on':'') + '" data-mf="">全部</span>';
    var codes = Object.keys(s.by_code||{}).sort(function(a,b){ return s.by_code[b]-s.by_code[a]; });
    codes.forEach(function(c){
        var nm = c==='dup_name' ? '名稱疑似重複建檔' : ((f[c]||[c])[0]);
        var lv = (r.levels||{})[c] || 'major';
        h += '<span class="dq-chip lv-' + esc(lv) + (ST.mFilter===c?' on':'') + '" data-mf="' + esc(c) + '">'
           + esc(nm) + '（' + s.by_code[c] + '）</span>';
    });
    h += '</div><div class="muted-help" style="margin-top:6px">編碼原則：' + esc(r.rule_text) + '</div>';
    $('#mStatBox').show().html(h);
}
$(document).on('click', '#mStatBox .dq-chip', function(){
    ST.mFilter = String($(this).data('mf')||''); ST.mPage = 1; renderMasterStat(); renderMaster();
});

function masterRows(){
    var rows = (ST.master && ST.master.rows) || [];
    if (!ST.mFilter) return rows;
    return rows.filter(function(r){ return r.issues.some(function(i){ return i.code === ST.mFilter; }); });
}
function renderMaster(){
    var all = masterRows();
    var type = (ST.master && ST.master.type) || 'customer';
    renderPager('mPager', all.length, ST.mPage, ST.mPer, function(p, per){
        ST.mPage = p; ST.mPer = per; renderMaster();
    });
    var pages = ST.mPer === 0 ? 1 : Math.max(1, Math.ceil(all.length/ST.mPer));
    if (ST.mPage > pages) ST.mPage = pages;
    var rows = ST.mPer === 0 ? all : all.slice((ST.mPage-1)*ST.mPer, ST.mPage*ST.mPer);
    if (!rows.length){
        $('#mTbl tbody').html('<tr><td colspan="4" style="text-align:center;padding:24px;color:#8a7560">'
            + (ST.master ? '沒有符合條件的資料。' : '請按「開始稽核」。') + '</td></tr>');
        $('#mFoot').text(ST.master ? '顯示 0 筆' : '');
        return;
    }
    var h = rows.map(function(r){
        var iss = r.issues.map(function(i){
            return '<div class="issue-line"><span class="lv-badge ' + i.level + '">'
                 + (i.level==='critical'?'重要':(i.level==='major'?'一般':'建議')) + '</span> ' + esc(i.text)
                 + (CAN_ADMIN ? ' <span class="lnk" data-ex-master="' + esc(r.key) + '" data-code="' + esc(i.code) + '">標為例外</span>' : '')
                 + '</div>';
        }).join('');
        if (!iss) iss = '<span style="color:#5C8A4A">資料完善。</span>';
        var exKeys = Object.keys(r.exempt||{});
        if (exKeys.length) iss += exKeys.map(function(k){
            var e = r.exempt[k], f = (ST.master.fields||{})[k];
            return '<div class="ex-line">已核可例外：' + esc(k==='*'?'整筆不稽核':(f?f[0]:k))
                 + '（' + esc(e.by) + ' ' + dispDate(e.at) + (e.reason?'：'+esc(e.reason):'') + '）'
                 + (CAN_ADMIN ? ' <span class="lnk" data-exdel-master="' + esc(r.key) + '" data-code="' + esc(k) + '">取消</span>' : '')
                 + '</div>';
        }).join('');
        return '<tr class="r-' + r.level + '">'
            + '<td><span class="lv-badge ' + r.level + '">'
                + (r.level==='critical'?'重要缺失':(r.level==='major'?'一般缺失':(r.level==='minor'?'建議補齊':'完善')))
                + '</span></td>'
            + '<td>' + esc(r.key) + (r.code_ok?'':' <i class="fa fa-exclamation-triangle" style="color:#DD5138" title="編號不符編碼原則"></i>')
                + (r.inactive?'<br><em class="muted-help">已停用</em>':'') + '</td>'
            + '<td>' + esc(r.name) + '</td><td>' + iss + '</td></tr>';
    }).join('');
    $('#mTbl tbody').html(h);
    $('#mFoot').text('本頁 ' + rows.length + ' 筆／符合條件 ' + all.length + ' 筆（'
        + (type==='maker'?'廠商':'客戶') + '，共納入稽核 ' + ST.master.stat.total + ' 筆）');
}

/* ══ 例外 ══ */
$(document).on('click','[data-ex-trace]', function(){
    ST.ex = {scope:'trace', key:String($(this).data('ex-trace')), item:String($(this).data('code'))};
    var it = ((ST.trace.items||{})[ST.ex.item]||[ST.ex.item])[0];
    $('#exWhat').text('訂單 ' + ST.ex.key + '　項目：' + it);
    $('#exReason').val(''); openMask('exMask');
});
$(document).on('click','[data-ex-master]', function(){
    var code = String($(this).data('code'));
    ST.ex = {scope: ST.master.type, key:String($(this).data('ex-master')), item:code};
    var f = (ST.master.fields||{})[code];
    $('#exWhat').text(ST.ex.key + '　項目：' + (code==='dup_name' ? '名稱疑似重複建檔' : (f?f[0]:code)));
    $('#exReason').val(''); openMask('exMask');
});
$('#btnExSave').on('click', function(){
    if(!ST.ex) return;
    $.post(API, {action:'exempt_set', csrf:CSRF, scope:ST.ex.scope, key:ST.ex.key,
                 item:ST.ex.item, reason:$('#exReason').val()}, function(r){
        if(!r || !r.ok) return;
        closeMask('exMask');
        if (ST.ex.scope === 'trace') $('#btnTraceRun').click(); else $('#btnMasterRun').click();
    });
});
$(document).on('click','[data-exdel-trace]', function(){
    if(!confirm('取消這個例外之後，這一項會重新納入稽核。確定嗎？')) return;
    $.post(API, {action:'exempt_del', csrf:CSRF, scope:'trace',
                 key:String($(this).data('exdel-trace')), item:String($(this).data('code'))},
        function(r){ if(r && r.ok) $('#btnTraceRun').click(); });
});
$(document).on('click','[data-exdel-master]', function(){
    if(!confirm('取消這個例外之後，這一項會重新納入稽核。確定嗎？')) return;
    $.post(API, {action:'exempt_del', csrf:CSRF, scope:ST.master.type,
                 key:String($(this).data('exdel-master')), item:String($(this).data('code'))},
        function(r){ if(r && r.ok) $('#btnMasterRun').click(); });
});

/* ══ 稽核對象（多選 AS 表單）══ */
var SCOPE = {trace:[], master:[]}, AS_DOCS = [];
function loadSettings(cb){
    $.get(API, {action:'settings_get'}, function(r){
        if(!r || !r.ok) return;
        ST.settings = r; AS_DOCS = r.as_docs || [];
        SCOPE.trace  = (r.scope_trace  || []).slice();
        SCOPE.master = (r.scope_master || []).slice();
        if (cb) cb(r);
    });
}
function renderScope(){
    ['trace','master'].forEach(function(t){
        var box = $(t==='trace' ? '#scopeTrace' : '#scopeMaster');
        if (!SCOPE[t].length){ box.html('<span class="muted-help">尚未綁定任何表單</span>'); return; }
        box.html(SCOPE[t].map(function(d){
            return '<span class="doc-tag"><b>' + esc(d.doc_no) + '</b> ' + esc(d.doc_name)
                 + (CAN_ADMIN ? '<span class="x" data-rm="' + t + '" data-id="' + d.id + '">&times;</span>' : '') + '</span>';
        }).join(''));
    });
}
$('#btnScope').on('click', function(){ loadSettings(function(){ renderScope(); openMask('scopeMask'); }); });
$(document).on('click','[data-rm]', function(){
    var t = String($(this).data('rm')), id = parseInt($(this).data('id'),10);
    SCOPE[t] = SCOPE[t].filter(function(d){ return parseInt(d.id,10) !== id; });
    renderScope();
});
$(document).on('click','[data-add]', function(){
    var t = String($(this).data('add'));
    if (!CAN_ADMIN){ alert('需要「資料稽核管理員」權限才能修改稽核對象'); return; }
    EGAsDoc.open({
        docs: AS_DOCS, current: 0, title: '選擇這次稽核涵蓋的 AS 表單',
        onSave: function(id){
            var doc = null;
            AS_DOCS.forEach(function(d){ if (parseInt(d.id,10) === parseInt(id,10)) doc = d; });
            if (!doc) return;
            if (SCOPE[t].some(function(d){ return parseInt(d.id,10) === parseInt(id,10); })) return;
            SCOPE[t].push({id:doc.id, doc_no:doc.doc_no, doc_name:doc.doc_name});
            renderScope();
        }
    });
});
$('#btnScopeSave').on('click', function(){
    var done = 0;
    ['trace','master'].forEach(function(t){
        $.post(API, {action:'scope_save', csrf:CSRF, tab:t,
                     ids: JSON.stringify(SCOPE[t].map(function(d){ return d.id; }))}, function(r){
            if (r && r.ok) { done++; if (done === 2){ alert('稽核對象已儲存'); closeMask('scopeMask'); } }
        });
    });
});

/* ══ 設定 ══ */
$('#btnSetting').on('click', function(){ loadSettings(function(r){ renderSetting(r); openMask('setMask'); }); });
function lvSel(name, cur, opts){
    return '<select data-set="' + esc(name) + '">' + opts.map(function(o){
        return '<option value="' + o[0] + '"' + (cur===o[0]?' selected':'') + '>' + o[1] + '</option>';
    }).join('') + '</select>';
}
function renderSetting(r){
    var cr = r.code_rule || {}, tol = r.tolerance || {};
    var h = '<h4 style="color:#C77C1A">編碼原則</h4>';
    h += '<div class="dq-bar">英文 <input type="text" id="setLetters" style="width:52px" value="' + (cr.letters||2) + '"> 碼'
       + ' ＋ 數字 <input type="text" id="setDigits" style="width:52px" value="' + (cr.digits||3) + '"> 碼'
       + ' <label style="margin-left:10px"><input type="checkbox" id="setSuffix"' + (cr.allow_suffix?' checked':'') + '> 允許再加英文 '
       + '<input type="text" id="setSuffixLen" style="width:42px" value="' + (cr.suffix||1) + '"> 碼</label>'
       + ' <label><input type="checkbox" id="setUpper"' + (cr.upper_only?' checked':'') + '> 英文限大寫</label></div>';
    h += '<div class="muted-help">目前規則：' + esc(r.rule_text) + '（例：AB001、AB001A）</div>';

    h += '<h4 style="color:#C77C1A;margin-top:16px">流程稽核的容許誤差</h4>';
    h += '<div class="dq-bar">數量差異超過 <input type="text" id="setQtyPct" style="width:60px" value="' + (tol.qty_pct||0) + '"> % 才算不符'
       + '　單價差異超過 <input type="text" id="setPricePct" style="width:60px" value="' + (tol.price_pct||1) + '"> % 才算不符'
       + '　報價超過 <input type="text" id="setValidDays" style="width:60px" value="' + (tol.quote_valid_days||365) + '"> 天未重報視為過期</div>';

    h += '<h4 style="color:#C77C1A;margin-top:16px">流程稽核：檢核項目</h4>';
    h += '<table class="set-tbl"><tr><th style="width:55%">項目</th><th>等級</th></tr>';
    Object.keys(r.trace_items||{}).forEach(function(k){
        h += '<tr><td>' + esc(r.trace_items[k][0]) + '</td><td>'
           + lvSel('trace_items.' + k, (r.trace_levels||{})[k] || r.trace_items[k][1],
                   [['critical','嚴重'],['warn','提醒'],['off','不檢查']]) + '</td></tr>';
    });
    h += '</table>';

    [['customer','客戶基本資料表'],['maker','廠商基本資料表']].forEach(function(t){
        var fields = r['fields_' + t[0]] || {}, lv = r['levels_' + t[0]] || {};
        h += '<h4 style="color:#C77C1A;margin-top:16px">' + t[1] + '：欄位要求</h4>';
        h += '<table class="set-tbl"><tr><th style="width:26%">欄位</th><th style="width:44%">說明</th><th>等級</th></tr>';
        Object.keys(fields).forEach(function(k){
            h += '<tr><td>' + esc(fields[k][0]) + '</td><td class="muted-help">' + esc(fields[k][3]||'') + '</td><td>'
               + lvSel('fields_' + t[0] + '.' + k, lv[k] || fields[k][2],
                       [['critical','重要缺失'],['major','一般缺失'],['minor','建議補齊'],['off','不檢查']]) + '</td></tr>';
        });
        h += '</table>';
    });
    $('#setBody').html(h);
}
$('#btnSetSave').on('click', function(){
    var groups = {trace_items:{}, fields_customer:{}, fields_maker:{}};
    $('#setBody [data-set]').each(function(){
        var p = String($(this).data('set')).split('.');
        if (groups[p[0]]) groups[p[0]][p[1]] = $(this).val();
    });
    $.post(API, {
        action:'settings_save', csrf:CSRF,
        code_rule: JSON.stringify({
            letters: parseInt($('#setLetters').val(),10) || 2,
            digits: parseInt($('#setDigits').val(),10) || 3,
            suffix: parseInt($('#setSuffixLen').val(),10) || 1,
            allow_suffix: $('#setSuffix').is(':checked') ? 1 : 0,
            upper_only: $('#setUpper').is(':checked') ? 1 : 0
        }),
        tolerance: JSON.stringify({
            qty_pct: parseFloat($('#setQtyPct').val()) || 0,
            price_pct: parseFloat($('#setPricePct').val()) || 0,
            quote_valid_days: parseInt($('#setValidDays').val(),10) || 0
        }),
        trace_items: JSON.stringify(groups.trace_items),
        fields_customer: JSON.stringify(groups.fields_customer),
        fields_maker: JSON.stringify(groups.fields_maker)
    }, function(r){
        if(!r || !r.ok) return;
        alert(r.msg || '已儲存');
        closeMask('setMask');
        if (ST.trace)  $('#btnTraceRun').click();
        if (ST.master) $('#btnMasterRun').click();
    });
});

/* ══ 留存稽核結果 ══ */
function keepRun(tab){
    var d;
    if (tab === 'trace'){
        if (!ST.trace){ alert('請先按「開始稽核」跑出結果'); return; }
        d = {tab:'trace', sub_type:'', from:$('#tFrom').val(), to:$('#tTo').val(),
             total:ST.trace.stat.total, critical:ST.trace.stat.critical, warn:ST.trace.stat.warn,
             stat:JSON.stringify(ST.trace.stat.by_code||{})};
    } else {
        if (!ST.master){ alert('請先按「開始稽核」跑出結果'); return; }
        d = {tab:'master', sub_type:ST.master.type, from:'', to:'',
             total:ST.master.stat.total, critical:ST.master.stat.critical,
             warn:(ST.master.stat.major||0), stat:JSON.stringify(ST.master.stat.by_code||{})};
    }
    d.note = prompt('備註（選填，例如：2026 年度第一次內部稽核）', '') || '';
    d.action = 'run_save'; d.csrf = CSRF;
    $.post(API, d, function(r){ if (r && r.ok) alert(r.msg); });
}
$('#btnTraceKeep').on('click', function(){ keepRun('trace'); });
$('#btnMasterKeep').on('click', function(){ keepRun('master'); });
$('#btnRuns').on('click', function(){
    $.get(API, {action:'run_list'}, function(r){
        if(!r || !r.ok) return;
        var rows = r.rows || [];
        if (!rows.length){ $('#runBody').html('<p class="muted-help">尚未留存過任何稽核結果。</p>'); openMask('runMask'); return; }
        var h = '<p class="muted-help">留存的結果會被內部稽核（系統稽核紀錄表）引用，作為「這份表單稽核發現什麼」的依據。</p>';
        h += '<table class="set-tbl"><tr><th>留存時間</th><th>分頁</th><th>期間</th><th>筆數</th><th>涵蓋表單</th><th>留存人</th></tr>';
        rows.forEach(function(x){
            h += '<tr><td>' + esc(x.created) + '</td>'
               + '<td>' + (x.tab==='trace' ? '流程順序' : ('基本資料' + (x.sub_type==='maker'?'（廠商）':'（客戶）'))) + '</td>'
               + '<td>' + esc((x.period_from||'') + (x.period_to?' ~ '+x.period_to:'')) + '</td>'
               + '<td>共 ' + x.total + '，嚴重 ' + x.critical_cnt + '，提醒 ' + x.warn_cnt + '</td>'
               + '<td>' + (x.scope||[]).length + ' 份</td><td>' + esc(x.created_by_name||'') + '</td></tr>';
        });
        h += '</table>';
        $('#runBody').html(h); openMask('runMask');
    });
});

/* ══ CSV ══ */
function csvDown(head, rows, name){
    var q = function(v){ v = (v===null||v===undefined)?'':String(v); return '"' + v.replace(/"/g,'""') + '"'; };
    var csv = head.map(q).join(',') + '\r\n' + rows.map(function(r){ return r.map(q).join(','); }).join('\r\n');
    var blob = new Blob(['﻿' + csv], {type:'text/csv;charset=utf-8;'});
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob); a.download = name;
    document.body.appendChild(a); a.click(); document.body.removeChild(a);
}
$('#btnTraceCsv').on('click', function(){
    var rows = traceRows();
    if (!rows.length){ alert('目前沒有資料可以匯出'); return; }
    csvDown(['判定','訂單編號','訂單日期','客戶','料號','訂單數量','訂單單價',
             '報價單號','報價日','報價單價','報價來源','製令張數','製令開立日','製令數量','製令來源',
             '出貨張數','出貨首日','出貨數量','出貨來源','製令編號','出貨單號','發現的問題'],
        rows.map(function(r){
            return [r.level==='critical'?'嚴重':(r.level==='warn'?'提醒':'正常'), r.order_no, r.odate, r.client, r.part,
                r.oqty, r.oprice,
                r.quote?r.quote.no:'', r.quote?r.quote.date:'', r.quote?r.quote.price:'', r.quote?r.quote.src:'',
                r.bom.cnt, r.bom.date, r.bom.qty, r.bom.src,
                (r.ship.doc_cnt||r.ship.cnt), r.ship.date, r.ship.qty, r.ship.src,
                (r.bom.list||[]).map(function(x){ return x.no; }).join('、'),
                (r.ship.list||[]).map(function(x){ return x.no; }).join('、'),
                r.issues.map(function(i){ return i.text; }).join('；')];
        }), '流程順序稽核_' + $('#tFrom').val() + '_' + $('#tTo').val() + '.csv');
});
$('#btnMasterCsv').on('click', function(){
    var rows = masterRows();
    if (!rows.length){ alert('目前沒有資料可以匯出'); return; }
    csvDown(['判定','編號','名稱','編號是否符合原則','已停用','缺少／不符的項目'],
        rows.map(function(r){
            return [r.level==='critical'?'重要缺失':(r.level==='major'?'一般缺失':(r.level==='minor'?'建議補齊':'完善')),
                r.key, r.name, r.code_ok?'符合':'不符', r.inactive?'是':'',
                r.issues.map(function(i){ return i.text; }).join('；')];
        }), (ST.master.type==='maker'?'廠商':'客戶') + '基本資料稽核.csv');
});

/* ══ 列印（A4 橫式，ai-rules/16）══ */
function printDoc(title, condLine, statLine, headCols, bodyRows){
    var scope = (ST.settings ? (ST.tab==='trace' ? ST.settings.scope_trace : ST.settings.scope_master) : []) || [];
    var scopeTxt = scope.length
        ? scope.map(function(d){ return d.doc_no + ' ' + d.doc_name; }).join('　／　')
        : '（尚未綁定稽核對象）';
    var now = new Date();
    var d = now.getFullYear() + '.' + ('0'+(now.getMonth()+1)).slice(-2) + '.' + ('0'+now.getDate()).slice(-2);
    var w = window.open('', '_blank');
    if (!w){ alert('列印視窗被瀏覽器封鎖，請允許快顯視窗後再試'); return; }
    var css = '@page{size:A4 landscape;margin:14mm 14mm 16mm;'
        + '@bottom-left{content:"第 " counter(page) " 頁／共 " counter(pages) " 頁";font-size:9pt;color:#555;}'
        + '@bottom-right{content:"資料稽核報告";font-size:9pt;color:#555;}}'
        + 'body{font-family:"Microsoft JhengHei",sans-serif;font-size:10pt;color:#000;margin:0;}'
        + 'h1{font-size:17pt;text-align:center;margin:0 0 2mm;}'
        + 'h2{font-size:13pt;text-align:center;margin:0 0 3mm;font-weight:normal;}'
        + '.meta{font-size:9pt;color:#333;margin:0 0 2mm;line-height:16px;}'
        + 'table{width:100%;border-collapse:collapse;table-layout:fixed;}'
        + 'thead{display:table-header-group;}'
        + 'th,td{border:1px solid #333;padding:3px 5px;font-size:9pt;word-break:break-all;vertical-align:top;}'
        + 'th{background:#EFE7DB;text-align:left;}'
        + 'tr{page-break-inside:avoid;}';
    var h = '<!DOCTYPE html><html lang="zh-Hant"><head><meta charset="utf-8"><title>' + esc(title)
        + '</title><style>' + css + '</style></head><body>'
        + '<h1>' + esc(OWN_COMPANY || '') + '</h1>'
        + '<h2>' + esc(title) + '</h2>'
        + '<div class="meta">稽核對象（AS 表單）：' + esc(scopeTxt) + '<br>'
        + esc(condLine) + '<br>' + esc(statLine) + '<br>列印日期：' + d + '</div>'
        + '<table><thead><tr>' + headCols.map(function(c){
              return '<th' + (c[1] ? ' style="width:' + c[1] + '"' : '') + '>' + esc(c[0]) + '</th>'; }).join('')
        + '</tr></thead><tbody>'
        + bodyRows.map(function(r){ return '<tr>' + r.map(function(c){ return '<td>' + esc(c) + '</td>'; }).join('') + '</tr>'; }).join('')
        + '</tbody></table></body></html>';
    w.document.write(h); w.document.close();
    if (window.EGPrintLog) EGPrintLog.record({source:'data_audit', doc_name:title, doc_kind:'form'});
    setTimeout(function(){ w.focus(); w.print(); }, 400);
}
$('#btnTracePrint').on('click', function(){
    var rows = traceRows();
    if (!rows.length){ alert('目前沒有資料可以列印'); return; }
    var s = ST.trace.stat;
    printDoc('資料稽核報告－流程順序稽核（報價→訂單→製令→出貨）',
        '稽核期間（訂單日期）：' + $('#tFrom').val() + ' ~ ' + $('#tTo').val()
            + (($('#tClient').val())?('　客戶：' + $('#tClient').val()):'')
            + (($('#tPart').val())?('　料號：' + $('#tPart').val()):''),
        '掃描訂單 ' + (ST.trace.scanned||0) + ' 張；嚴重 ' + s.critical + ' 筆、提醒 ' + s.warn + ' 筆、正常 ' + s.ok + ' 筆',
        [['序號','5%'],['判定','6%'],['訂單編號／日期','13%'],['客戶','9%'],['料號','13%'],
         ['報價','10%'],['製令','14%'],['出貨','14%'],['發現的問題','']],
        rows.map(function(r, i){
            return [i+1, (r.level==='critical'?'嚴重':(r.level==='warn'?'提醒':'正常')),
                r.order_no + ' / ' + r.odate, r.client, r.part,
                r.quote ? (r.quote.date + ' ' + r.quote.no) : '無',
                r.bom.cnt ? (r.bom.date + ' ' + (r.bom.list||[]).map(function(x){ return x.no; }).join(' ')) : (r.auto_pm ? '自動轉生管' : '無'),
                r.ship.cnt ? (r.ship.date + ' ' + (r.ship.list||[]).map(function(x){ return x.no; }).join(' ')) : '未出貨',
                r.issues.map(function(x){ return x.text; }).join('；')];
        }));
});
$('#btnMasterPrint').on('click', function(){
    var rows = masterRows();
    if (!rows.length){ alert('目前沒有資料可以列印'); return; }
    var s = ST.master.stat, isM = ST.master.type === 'maker';
    printDoc('資料稽核報告－' + (isM ? '廠商' : '客戶') + '基本資料表',
        '編碼原則：' + ST.master.rule_text + '（已停用者不納入稽核）',
        '納入稽核 ' + s.total + ' 筆；重要缺失 ' + s.critical + ' 筆、一般缺失 ' + s.major
            + ' 筆、建議補齊 ' + s.minor + ' 筆、資料完善 ' + s.ok + ' 筆',
        [['序號','5%'],['判定','9%'],['編號','12%'],['名稱','16%'],['缺少／不符的項目','']],
        rows.map(function(r, i){
            return [i+1, (r.level==='critical'?'重要缺失':(r.level==='major'?'一般缺失':(r.level==='minor'?'建議補齊':'完善'))),
                r.key, r.name, r.issues.map(function(x){ return x.text; }).join('；')];
        }));
});

<?php if ($perms['canView']): ?>
loadYears();
loadSettings();
<?php endif; ?>
</script>
</body>
</html>
