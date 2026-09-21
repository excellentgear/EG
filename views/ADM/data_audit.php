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
/* 開立不符合通知單要用內稽的權限判定（2026-09-21 使用者交辦：查得出缺失卻開不出 IA 單）。
   只讀權限、不動內稽的資料，實際開單一律走 InternalAudit_API 的 nc_create（唯一寫入點）。 */
include_once '../../src/common/internal_audit_lib.php';

$db = (new DBConnection())->getPDO();
dqa_ensure_schema($db);
$perms = dqa_perms($db, dqa_current_user($db));
$iaPerms = ['canAudit' => false, 'canAdmin' => false];
try { $iaPerms = ia_perms($db, ia_current_user($db)) + $iaPerms; } catch (Throwable $e) {}
$canNc    = !empty($iaPerms['canAudit']);   // 稽核員以上才開得了 IA 單
$canNcMap = !empty($iaPerms['canAdmin']);   // 內稽管理員才改得了「檢核項目→AS文件」對照
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
        .dq-card.pick { cursor:pointer; transition:background .12s, border-color .12s; }
        .dq-card.pick:hover { background:var(--sand); border-color:var(--amber-d); }
        .dq-card.sel { border:2px solid var(--amber-d); background:#FFF6E8;
                       box-shadow:0 0 0 2px rgba(199,124,26,.14); padding:7px 13px; }
        .dq-card small { display:block; font-size:10px; color:#b0a08c; margin-top:2px; }
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
        .excl-bar { background:#FFF4E2; border:1px solid var(--sand); border-radius:6px;
                    padding:6px 10px; margin-top:8px; font-size:12px; color:#6B4423; }
        .excl-tag { display:inline-block; background:#fff; border:1px solid var(--line); border-radius:10px;
                    padding:1px 8px; margin:2px 4px 2px 0; font-size:12px; }
        .excl-tag b { color:var(--amber-d); }
        .ex-tbl { width:100%; border-collapse:collapse; font-size:13px; }
        .ex-tbl th, .ex-tbl td { border:1px solid var(--line); padding:5px 8px; vertical-align:top; }
        .ex-tbl th { background:var(--sand); color:#6B4423; text-align:left; }
        .ex-tbl tr.off td { background:#F4F1EC; color:#9a8b78; }
        .ex-pick { position:relative; }
        .ex-sug { position:absolute; left:0; right:0; top:32px; z-index:20; background:#fff;
                  border:1px solid var(--amber-d); border-radius:6px; max-height:220px; overflow:auto;
                  box-shadow:0 4px 12px rgba(0,0,0,.12); display:none; }
        .ex-sug div { padding:5px 10px; cursor:pointer; font-size:13px; }
        .ex-sug div:hover { background:var(--sand); }
        .ex-chk { display:inline-flex; align-items:center; gap:4px; margin-right:12px; font-weight:normal; }
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
        /* 開立不符合通知單（2026-09-21）。勾選框與「開IA單」掛在每一條缺失上——
           一張訂單可能同時有好幾種缺失，那是好幾張 IA 單，不是一張。 */
        .nc-pick { vertical-align:-1px; margin:0 3px 0 6px; cursor:pointer; }
        .nc-go { color:#B2622A; cursor:pointer; font-weight:bold; }
        .nc-go:hover { text-decoration:underline; }
        .nc-done { color:#5C8A4A; }
        .nc-done a { color:#5C8A4A; text-decoration:underline; }
        .nc-map-row { display:flex; align-items:center; gap:8px; padding:4px 0; border-bottom:1px dashed var(--line); }
        .nc-map-row > label { width:170px; margin:0; font-weight:normal; font-size:13px; }
        .nc-map-row select { flex:1; min-width:220px; height:28px; font-size:12px; }
        .nc-map-row .to { width:230px; font-size:12px; color:#8a7560; }
        /* 綁定（2026-09-21）：每個節點一顆小鈕，點開挑候選單據 */
        .bind-go { display:inline-block; font-size:11px; line-height:16px; color:#B2622A; cursor:pointer;
                   border:1px solid #E0C49A; border-radius:8px; padding:0 6px; margin-top:2px; background:#FFF8EC; }
        .bind-go:hover { background:var(--amber-d); color:#fff; border-color:var(--amber-d); }
        .bd-sum { background:#FBF5EC; border:1px solid var(--line); border-radius:6px; padding:8px 10px;
                  font-size:13px; color:#4A3524; margin-bottom:10px; }
        .bd-sum b { color:var(--amber-d); }
        .bd-sum .n { display:inline-block; margin-right:14px; }
        .bd-tbl { width:100%; border-collapse:collapse; font-size:12px; table-layout:fixed; }
        .bd-tbl th { background:var(--sand); color:#6B4423; padding:5px 7px; border:1px solid var(--line);
                     text-align:left; position:sticky; top:0; z-index:2; }
        .bd-tbl td { padding:5px 7px; border:1px solid var(--line); vertical-align:top; word-break:break-all; }
        .bd-tbl tr.bound td { background:#EEF6EA; }
        .bd-tbl tr.risky td { background:#FFF4E2; }
        .bd-wrap { max-height:46vh; overflow:auto; border:1px solid var(--line); border-radius:6px; background:#fff; }
        .qin { width:68px; height:24px; border:1px solid var(--line); border-radius:4px; padding:1px 5px; font-size:12px; }
        .pill { display:inline-block; font-size:10px; line-height:15px; padding:0 6px; border-radius:8px;
                margin:1px 3px 1px 0; white-space:nowrap; }
        .pill.ok   { background:#E6F0E0; color:#3F6632; }
        .pill.warn { background:#FBE6D2; color:#A65A20; }
        .pill.bad  { background:#F8DED7; color:#9C3B27; }
        .pill.grey { background:#EFE3CF; color:#7a6750; }
        /* 節點的製程／備註（2026-09-21 使用者回報：看不到製程，沒辦法確認是不是同一種製程）。
           .node 是 nowrap，所以這一區塊一定要另外開一個 div 才換得了行；
           line-height 一律自己指定（Gentelella 全站 td span{line-height:28px} 會把小字撐成 28px）。 */
        .proc-line { margin-top:3px; white-space:normal; line-height:15px; }
        .proc-line .nt { font-size:11px; line-height:15px; color:#8a7560; word-break:break-all; }
        .proc-line .nt b { color:#7a6750; font-weight:normal; }
        .tierline { font-size:11px; color:#6B4423; background:#FFF6E8; border-left:3px solid var(--amber);
                    padding:1px 6px; margin:1px 0; line-height:17px; }
        /* 階梯報價要能只綁其中一階（2026-09-21 使用者回報：整列綁下去＝一次綁了三種價格） */
        .tierline.on { background:#EEF6EA; border-left-color:#5C8A4A; }
        .tier-go { display:inline-block; margin-left:6px; font-size:10px; line-height:15px; padding:0 6px;
                   border:1px solid #E0C49A; border-radius:8px; background:#fff; color:#B2622A; cursor:pointer; }
        .tier-go:hover { background:var(--amber-d); color:#fff; border-color:var(--amber-d); }
        .tier-go.on { background:#E6F0E0; border-color:#9CBE8C; color:#3F6632; }
        .qd-tbl { width:100%; border-collapse:collapse; font-size:12px; }
        .qd-tbl th { background:var(--sand); color:#6B4423; padding:5px 7px; border:1px solid var(--line); text-align:left; }
        .qd-tbl td { padding:5px 7px; border:1px solid var(--line); vertical-align:top; }
        .qd-tbl tr.noorder td { background:#FDF0EC; }
        .qd-tbl tr.noteonly td { background:#F4F1EC; color:#8a7560; }
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
        <div class="dq-tab" data-tab="quote">③ 報價項目追蹤（下單／出貨／收款）</div>
        <div style="margin-left:auto; display:flex; align-items:center; gap:6px;">
            <button class="btn btn-sm btn-warm-o" id="btnExcl"><i class="fa fa-ban"></i> 排除設定</button>
            <button class="btn btn-sm btn-warm-o" id="btnScope"><i class="fa fa-link"></i> 稽核對象</button>
            <?php if ($perms['canAdmin']): ?>
            <button class="btn btn-sm btn-warm-o" id="btnSetting"><i class="fa fa-cog"></i> 設定</button>
            <?php endif; ?>
            <button class="btn btn-sm btn-warm-o" id="btnRuns"><i class="fa fa-history"></i> 留存紀錄</button>
            <?php if ($canNcMap): ?>
            <button class="btn btn-sm btn-warm-o" id="btnNcMap" title="設定每一種缺失要開給哪一個受稽單位">
                <i class="fa fa-random"></i> 不符合通知單對照</button>
            <?php endif; ?>
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
                <span class="src-tag guess">推測</span>，推測配對<b>不</b>判定製令／出貨的數量不符。
                唯一例外是<b>報價數量與訂單數量不符</b>：那是量級差異、不論配到哪一張報價都值得看，所以推測配對也照判（列為嚴重，可在「設定」降級或關閉）。
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
            <?php if ($canNc): ?>
            <button class="btn btn-sm btn-warm" id="btnNcBulk" disabled>
                <i class="fa fa-file-text-o"></i> 開立不符合通知單（<span id="ncPickN">0</span>）</button>
            <?php endif; ?>
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

    <!-- ══════════ 分頁三：報價項目追蹤 ══════════
         分頁一是以「訂單」為主軸，所以「報價了但訂單根本沒建立」那種缺失在分頁一永遠看不到
         （那一列訂單不存在）。這一頁把主軸換成報價項目，治具／刀具漏開訂單才查得出來。 -->
    <div id="pane-quote" style="display:none">
        <div class="warm-panel no-print">
            <div class="dq-bar">
                <label>報價日期</label>
                <input type="date" id="qFrom" value="<?= $thisYear ?>-01-01">
                <span>～</span>
                <input type="date" id="qTo" value="<?= $thisYear ?>-12-31">
                <label style="margin-left:8px">客戶</label>
                <input type="text" id="qClient" placeholder="客戶關鍵字" style="width:140px">
                <label>料號</label>
                <input type="text" id="qPart" placeholder="料號關鍵字" style="width:140px">
                <label style="margin-left:8px"><input type="checkbox" id="qOnlyBad" checked> 只看有異常的</label>
                <button class="btn btn-sm btn-warm" id="btnQuoteRun"><i class="fa fa-search"></i> 開始稽核</button>
                <span class="muted-help" id="qTiming"></span>
            </div>
            <div class="muted-help" style="margin-top:6px">
                一列＝報價單上的一個項目。追到底：這一列<b>有沒有開訂單</b> → 訂單<b>有沒有出貨</b> → 出貨<b>有沒有打單價</b>。
                最該看的是 <span class="lv-badge critical">報價已成案，這一列卻沒有訂單</span>——
                整張報價單其他項目都開了訂單、只有這一列沒有，<b>治具、刀具這類項目最常漏開，漏了就是做了卻收不到錢</b>。
                「整張報價單都沒有訂單」多半只是沒接到案子，所以<b>預設不列</b>，要當成未成案清單用時到「設定」打開。
                報價單上標成<b>備註列</b>的項目一律不判定。
            </div>
        </div>
        <div class="warm-panel no-print" id="qStatBox" style="display:none"></div>
        <div class="dq-pager no-print" id="qPager"></div>
        <div class="dq-wrap">
            <table class="dq-tbl" id="qTbl">
                <colgroup>
                    <col style="width:70px"><col style="width:120px"><col style="width:88px"><col style="width:130px">
                    <col style="width:130px"><col style="width:120px"><col style="width:120px"><col>
                </colgroup>
                <thead><tr>
                    <th>判定</th><th>報價單</th><th>客戶</th><th>料號</th>
                    <th>數量／單價</th><th>訂單</th><th>出貨</th><th>發現的問題</th>
                </tr></thead>
                <tbody><tr><td colspan="8" style="text-align:center; color:#8a7560; padding:24px">
                    請設定報價日期區間後按「開始稽核」。
                </td></tr></tbody>
            </table>
        </div>
        <div class="dq-bar no-print" style="margin-top:8px">
            <span class="muted-help" id="qFoot"></span>
            <span style="margin-left:auto"></span>
            <button class="btn btn-sm btn-warm-o" id="btnQuoteCsv"><i class="fa fa-file-excel-o"></i> CSV</button>
            <button class="btn btn-sm btn-warm-o" id="btnQuotePrint"><i class="fa fa-print"></i> 列印</button>
        </div>
    </div>

<?php endif; ?>
</div></div></div>

<!-- 使用說明 -->
<div class="m-mask" id="helpUseMask"><div class="m-box" style="width:820px">
    <div class="m-head">資料稽核　使用說明 <span class="x" data-close>&times;</span></div>
    <div class="m-body help-doc">
        <h4>這頁在做什麼</h4>
        <p>把「同一支料號從報價一路到出貨」的四個節點攤開來比對、檢查客戶／廠商主檔的編號與欄位是否完善，
           再從報價單那一端追「報價了有沒有下單、有沒有出貨、有沒有收款」，
           作為 AS9100 內部稽核的客觀證據。可以綁定「這次稽核涵蓋哪幾份 AS 表單」，
           留存結果後內部稽核就帶得出來。<b>查出缺失之後可以直接在這一頁做綁定，也可以直接開立不符合通知單</b>。</p>

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
                報價單號 → 報價單管理（帶報價年度、客戶與單號）；
                訂單編號 → 訂單追蹤（<b>客戶、料號、全表搜尋三個欄位都會一起帶</b>）。
                <b>每個連結都會把客戶與料號一起帶過去</b>——只帶單號的話，同一個訂單編號會有好幾列
                （拆批、同編號多料號），無關的料號也會一起列出來，稽核時很容易改到不該改的那一列。</li>
            <li><b>製令與出貨的數量下方會列出單號，點下去直接開相關頁面並帶好篩選</b>：
                製令 → BOM 總表（填入客戶欄與 BOM 欄）；出貨 → 快速出貨的「近期出貨單」
                （以該單號查詢，日期區間帶該單前後 7 天，並在跳窗上標出要核對的客戶與料號——
                 一張出貨單的明細可能含別的料號，那些不在這次稽核範圍）。
                都是開新分頁，不影響這一頁的稽核結果。
                一張出貨單在系統裡是好幾個明細列，這裡已依單號合併，所以看到的是張數不是列數。</li>
            <li>同一支料號的多張訂單對到同一張報價單是正常的，不會被判成異常。
                製令與訂單、出貨也不是一對一，所以數量一律用<b>合計</b>比對。</li>
            <li><b>報價數量與訂單數量不符</b>會列為嚴重（階梯報價、以及已過期的報價不判——
                後者是「報價過期未重新報價」那一項要講的事）。
                <b style="color:#B4543B">注意：2026-09-21 之前用 ERP 報價單日報表匯入的報價，數量是錯的</b>——
                匯入程式把「4,000」的千分位逗號當成結尾、只讀到 <b>4</b>（已修正，但舊資料要重新匯入才會正確）。
                在重匯之前這一項會出現大量假的「數量不符」，可以先到「設定」把它改成不檢查。</li>
        </ul>

        <h4>在這一頁直接做綁定（需要「資料稽核管理員」權限）</h4>
        <p>缺失的成因十之八九就是「<b>沒有做綁定</b>」。查出來之後還要換到報價單管理、BOM 總表、
           快速出貨各綁一次，實務上就不會有人去綁，缺失會一直掛在那裡。
           所以報價／製令／出貨三個欄位下方各有一顆
           <span class="bind-go"><i class="fa fa-link"></i> 綁定</span>，<b>確認無誤就直接在這裡綁</b>。</p>
        <ul>
            <li>跳窗最上面一定會先把<b>這張訂單目前有沒有報價、有沒有製令、有沒有出貨單</b>一起列出來，
                不必切回去看。下方是候選單據，<b>已經綁在這張訂單上的一定排得出來</b>（才解除得掉），
                另外標出「已分配給幾張訂單」「已掛在別張訂單」「日期早於訂單」「未開價」。</li>
            <li><b>報價 → 訂單是多對多、不拆量</b>：一份報價本來就會被很多張訂單引用；
                反過來<b>一張訂單也可以同時綁本體與治具／刀具兩列報價</b>。
                治具那一列的料號與訂單不同，所以要先<b>取消「只列同料號」</b>才找得到。
                每一列都看得到<b>製程、料號備註與階梯報價的數量區間</b>，按「看整張報價單」可以攤開整張單的所有項目。</li>
            <li><b>訂單 → 製令、訂單 → 出貨是多對多而且會拆量</b>：一張製令可以分給好幾張訂單、
                一張出貨單也可以分屬好幾張訂單。分配量預設帶「兩邊剩餘量取小者」，可以直接改；
                超過可分配量會被擋下並告訴你還剩多少。</li>
            <li><b>會動到既有綁定時一定會先跳出提示</b>，把「會改到什麼」講清楚再讓你確認
                （例如這張出貨單目前掛在別張訂單上、綁過來之後它的主要訂單就會換成這一張）。</li>
            <li>綁完<b>只會重算那一列</b>，畫面上的缺失立刻更新，不必整份重跑（重跑要掃幾千張訂單）。</li>
            <li>寫入的位置與快速出貨的「追溯對照」完全相同（同一套分配表），
                所以在哪一頁綁都一樣，不會有兩份對不起來的資料。</li>
        </ul>

        <h4>③ 報價項目追蹤（報價了有沒有下單／出貨／收款）</h4>
        <p>分頁一是以<b>訂單</b>為主軸，所以「報價了但訂單根本沒建立」那種缺失在分頁一永遠看不到——
           那一列訂單不存在。這一頁把主軸換成<b>報價單上的每一個項目</b>，一路追到出貨與金額。</p>
        <ul>
            <li><b>最該看的是「報價已成案，這一列卻沒有訂單」</b>：整張報價單其他項目都開了訂單、
                只有這一列沒有。<b>治具、刀具這類項目最常漏開，漏了就是做了卻收不到錢</b>。</li>
            <li>「整張報價單都沒有訂單」多半只是沒接到這個案子，不是缺失，所以<b>預設不列</b>；
                要當成未成案清單用時，到「設定」把它打開。寬限天數（預設 30 天）也在「設定」調。</li>
            <li><b>收款分兩項，可各自關閉</b>：「出貨未開價」＝出貨單上沒有單價，這批貨收不到款（預設開）；
                「出貨未進對帳／未開發票」＝<b>預設關閉</b>，因為會計模組目前發票明細 0 筆、對帳底稿只有 84 筆，
                打開會整片報未收款。等會計上線後再打開。</li>
            <li><b>金額為 0 的報價、訂單、出貨都會抓出來</b>。已經被標成「備註列」的報價項目、
                以及在出貨分析頁按過「確認非異常」的出貨單不列入。訂單沒有這種旗標，
                確認是備註性質時請用該列的「標為例外」。</li>
        </ul>

        <h4>② 基本資料稽核</h4>
        <ul>
            <li>編號是否符合編碼原則（預設：英文 2 碼＋數字 3 碼，可再加英文 1 碼，如 <b>AB001</b>、<b>AB001A</b>），
                規則可在「設定」調整。</li>
            <li>欄位完整性依三級列出缺少什麼。傳真、EMAIL 這種「不是每間都有」的預設不檢查；
                結帳方式、結帳日、付款方式這類帳務資訊一律列為重要缺失。</li>
            <li>上方的「重要缺失／一般缺失／建議補齊／資料完善」卡片一樣可以點來篩選。</li>
            <li>已停用者不納入稽核。確定無統編（現金交易）、或編號沿用舊制不打算改的，
                按該列的「標為例外」，之後就不再列為缺失，並會記下是誰、什麼時候、為什麼核可的。</li>
        </ul>

        <h4>排除設定（右上角「排除設定」）</h4>
        <p>有些情況本來就不是缺失，卻每次稽核都被報出來。例如<b>某些客戶固定先下未來單</b>，
           製令會先開立、訂單事後才來綁，流程順序稽核就會一直報「製令早於訂單」。
           這時候建一條排除規則即可。</p>
        <ul>
            <li>一條規則＝<b>排除什麼</b>（客戶／廠商／料號＋對象）＋<b>套用到哪個分頁</b>＋
                （選填）<b>只排除哪幾個檢核項目</b>。項目不勾＝這個對象整筆不納入稽核。</li>
            <li><b>每個分頁適用的排除不同</b>：客戶可套流程順序稽核與基本資料稽核（客戶）；
                廠商只有基本資料稽核（廠商）；料號只有流程順序稽核（基本資料稽核查的是客戶廠商主檔，沒有料號）。</li>
            <li>對象要<b>打字從主檔挑</b>，不要自己硬打——打錯一個字這條規則就永遠不會命中，而且不會報錯。</li>
            <li>規則可以<b>停用</b>（先留著不生效）或<b>刪除</b>；稽核結果上方會列出
                「這次套用了哪些排除、各排掉幾筆」，所以隨時看得出有排除在作用。</li>
            <li>這和逐筆的「標為例外」互補：例外是「這一筆的這一項已核可」，排除是「往後凡是這個對象都不要再報」。</li>
        </ul>

        <h4>把缺失開成不符合通知單（2026-09-21 新增）</h4>
        <p>查出來的缺失可以<b>直接開成內部稽核的「不符合通知單」(IA 單，2-GM-06-07)</b>，
           不必再到內部稽核那邊從頭手打一次。需要<b>稽核員以上</b>的內稽權限才看得到這些按鈕。</p>
        <ul>
            <li><b>勾選框掛在「每一條缺失」上，不是整張訂單</b>：同一張訂單可能同時有「查不到報價單」與
                「出貨早於訂單」，那是兩個不同的不合格事實，要開兩張單，甚至可能是兩個不同的受稽單位。</li>
            <li>單筆按該條缺失右邊的<b>「開IA單」</b>；要一次開很多就逐條勾起來，再按下方的
                <b>「開立不符合通知單（N）」</b>（一次最多 50 張）。<b>勾選在換頁、換篩選之後仍然留著</b>。</li>
            <li>跳窗裡<b>稽核日期／不合格類型／要求完成期限</b>三項套用到全部，
                <b>受稽核單位與不合格事實可以逐筆修改</b>。按下去會立即通知各受稽核單位主管。</li>
            <li><b>同一張訂單的同一個檢核項目只會開一次</b>，重複的會自動略過；已經開過的那一條缺失上會直接
                顯示 IA 編號與目前階段（待回覆／待驗證／已結案），點得進去。</li>
        </ul>

        <h4>不符合通知單對照（右上角，限內稽管理員）</h4>
        <p>IA 單一定要有<b>受稽核單位</b>，但這一頁的一列是<b>一張訂單</b>不是一個部門。
           所以這裡設定「每一種缺失對應到哪一份 AS 表單」，系統就能一次推導出三個欄位：</p>
        <ul>
            <li><b>受稽核單位</b>＝AS 文件編號第二段的部門代碼（例 <code>2-SM-01-02</code> → SM → 業務課）。</li>
            <li><b>相關表單編號與名稱</b>＝那份文件本身。</li>
            <li><b>違反條文</b>＝由 AS9100 條文題庫的「建立的文件、表單」反查出來的條文。</li>
        </ul>
        <p>沒有設定的項目會退回<b>「稽核對象」綁定的第一份文件</b>，單還是開得出來，只是受稽核單位要自己選。
           建議一開始就把常開單的那幾個項目設好（例：查不到報價單 → 2-SM-01-02 報價單），往後就全自動帶入。</p>

        <h4>稽核對象與留存</h4>
        <p><b>稽核對象</b>＝這次稽核是在查哪幾份 AS 表單（可多選）。
           這與「某個頁面是某張 AS 表單的網頁版」那種一對一綁定不同，所以另外存放、不會影響其他頁面的表頭表尾。</p>
        <p>按<b>留存稽核結果</b>會把當下的統計存起來（含期間與涵蓋的表單），
           內部稽核的系統稽核紀錄表就能引用「這份表單最近一次資料稽核發現幾筆問題」。</p>

        <h4>權限</h4>
        <ul>
            <li><b>資料稽核檢閱</b>（dqa_view）：查詢、匯出、列印。</li>
            <li><b>資料稽核管理員</b>（dqa_admin）：另可改設定、標記例外、綁定稽核對象、留存結果。</li>
            <li><b>開立不符合通知單另外看內部稽核的權限</b>：要<b>稽核員</b>（ia_auditor）以上才看得到「開IA單」與批次開單鈕；
                「不符合通知單對照」要<b>內稽管理員</b>（ia_admin）。只有資料稽核權限、沒有內稽權限的人查得到缺失但開不了單。</li>
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

<!-- 排除設定 -->
<div class="m-mask" id="exclMask"><div class="m-box" style="width:860px">
    <div class="m-head">排除設定：整個客戶／廠商／料號不列為缺失 <span class="x" data-close>&times;</span></div>
    <div class="m-body">
        <p class="muted-help">
            有些情況本來就不是缺失，卻每次稽核都被報出來。例如
            <b>某些客戶固定先下未來單</b>，製令會先開立、訂單事後才來綁，流程順序稽核就會一直報
            「製令早於訂單」——那是這家客戶的作業方式，不是資料有問題。<br>
            一條規則＝<b>排除什麼</b>（客戶／廠商／料號＋值）＋<b>套用到哪個分頁</b>＋
            （選填）<b>只排除哪幾個檢核項目</b>。項目留空＝這個對象整筆不納入稽核。<br>
            <b>維度決定它能套到哪些分頁</b>：客戶可套流程順序稽核與基本資料稽核（客戶）；
            廠商只有基本資料稽核（廠商）；料號只有流程順序稽核。
        </p>
        <div class="warm-panel" style="background:#FFFBF4"<?= $perms['canAdmin'] ? '' : ' hidden' ?>>
            <div class="dq-bar" style="align-items:flex-start">
                <div>
                    <label style="display:block">排除什麼</label>
                    <select id="exDim" style="width:88px"></select>
                </div>
                <div class="ex-pick" style="flex:1; min-width:220px">
                    <label style="display:block">對象（打字搜尋主檔後點選）</label>
                    <input type="text" id="exVal" style="width:100%" autocomplete="off"
                           data-eg-hint="打客戶簡稱或編號，例如 和大 或 C2005">
                    <div class="ex-sug" id="exSug"></div>
                </div>
                <div style="flex:1; min-width:200px">
                    <label style="display:block">原因（選填，建議填寫）</label>
                    <input type="text" id="exReasonNew" style="width:100%" maxlength="300">
                </div>
            </div>
            <div style="margin-top:8px">
                <label style="display:block">套用分頁</label>
                <span id="exTabs"></span>
            </div>
            <div style="margin-top:8px" id="exItemsBox">
                <label style="display:block">只排除哪幾個檢核項目（不勾＝這個對象整筆不稽核）</label>
                <span id="exItems"></span>
            </div>
            <div style="margin-top:10px; text-align:right">
                <span class="muted-help" id="exMsg" style="margin-right:auto"></span>
                <button class="btn btn-sm btn-warm" id="btnExclAdd">新增排除規則</button>
            </div>
        </div>
<?php if (!$perms['canAdmin']): ?>
        <p class="muted-help" style="color:#B23A2A">您只有檢閱權限，看得到目前的排除設定但不能新增或修改；要調整請洽「資料稽核管理員」。</p>
<?php endif; ?>
        <div id="exclList"></div>
    </div>
    <div class="m-foot"><button class="btn btn-sm btn-default" data-close>完成，關閉</button></div>
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

<!-- 節點綁定（2026-09-21 使用者交辦：確認無誤就可以直接在稽核頁綁定）
     一張跳窗管三種節點（報價／製令／出貨），差別只在欄位與要不要填分配量，
     寫入一律走 trace_chain_lib 的 tc_link()／tc_unlink()，這裡不自己刻寫入邏輯。 -->
<div class="m-mask" id="bindMask"><div class="m-box" style="width:1040px">
    <div class="m-head"><span id="bdTitle">綁定</span> <span class="x" data-close>&times;</span></div>
    <div class="m-body">
        <div class="bd-sum" id="bdSum"></div>
        <div class="dq-bar" style="margin-bottom:8px">
            <label>搜尋</label>
            <input type="text" id="bdKw" placeholder="單號／料號關鍵字（打了字就不限客戶與料號）" style="width:300px">
            <label class="ex-chk"><input type="checkbox" id="bdSamePart" checked> 只列同料號</label>
            <label class="ex-chk"><input type="checkbox" id="bdAllClient"> 不限客戶</label>
            <button class="btn btn-sm btn-warm-o" id="bdSearch"><i class="fa fa-search"></i> 重新查詢</button>
            <span class="muted-help" id="bdHint" style="margin-left:auto"></span>
        </div>
        <div class="muted-help" id="bdTip" style="margin-bottom:6px"></div>
        <div class="bd-wrap"><table class="bd-tbl" id="bdTbl"><thead></thead><tbody></tbody></table></div>
    </div>
    <div class="m-foot">
        <span class="muted-help" style="float:left; text-align:left; line-height:16px" id="bdFoot"></span>
        <button class="btn btn-sm btn-default" data-close>完成，關閉</button>
    </div>
</div></div>

<!-- 整張報價單的內容（使用者：報價內常有其他治具、刀具…要能看到完整內容才確認得了） -->
<div class="m-mask" id="qdMask"><div class="m-box" style="width:1040px">
    <div class="m-head"><span id="qdTitle">報價單內容</span> <span class="x" data-close>&times;</span></div>
    <div class="m-body" id="qdBody"></div>
    <div class="m-foot"><button class="btn btn-sm btn-default" data-close>關閉</button></div>
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

<!-- ══════════ 開立不符合通知單（2026-09-21 使用者交辦） ══════════ -->
<div class="m-mask" id="ncMask"><div class="m-box" style="width:1000px">
    <div class="m-head">開立內稽不符合通知單 <span class="x" data-close>&times;</span></div>
    <div class="m-body">
        <p class="muted-help">
            每一筆缺失各開一張不符合通知單（2-GM-06-07），開完會<b>立即通知受稽核單位主管</b>填寫原因分析與改善措施。<br>
            <b>受稽核單位／相關表單／違反條文</b>是由「不符合通知單對照」設定的 AS 文件推導出來的，
            這裡還可以逐筆改；同一張訂單的同一個檢核項目<b>只會開一次</b>，重複的會自動略過。
        </p>
        <div class="dq-bar" style="margin-bottom:8px">
            <label>稽核日期</label><input type="date" id="ncDate" style="width:150px">
            <label>不合格類型</label><select id="ncType" style="width:150px"></select>
            <label>要求完成期限</label><input type="date" id="ncDue" style="width:150px">
            <span class="muted-help">這三項套用到下面每一筆</span>
        </div>
        <div id="ncWarn" class="muted-help" style="color:#B23A2A"></div>
        <div class="dq-wrap" style="max-height:44vh">
            <table class="dq-tbl" id="ncTbl">
                <colgroup><col style="width:112px"><col style="width:150px"><col style="width:170px"><col></colgroup>
                <thead><tr><th>檢核項目</th><th>訂單</th><th>受稽核單位</th><th>不合格事實（可改）</th></tr></thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
    <div class="m-foot">
        <button class="btn btn-sm btn-default" data-close>取消</button>
        <button class="btn btn-sm btn-warm" id="btnNcGo">開立並通知</button>
    </div>
</div></div>

<!-- ══════════ 檢核項目 → AS 文件 對照 ══════════ -->
<div class="m-mask" id="ncMapMask"><div class="m-box" style="width:940px">
    <div class="m-head">不符合通知單對照（檢核項目 → AS 文件） <span class="x" data-close>&times;</span></div>
    <div class="m-body">
        <p class="muted-help">
            不符合通知單一定要有<b>受稽核單位</b>，而資料稽核的一列是<b>一張訂單</b>不是一個部門。
            所以這裡設定「每一種缺失對應到哪一份 AS 表單」，系統就能一次推導出三個欄位：<br>
            <b>受稽核單位</b>（由文件編號第二段的部門代碼，例 <code>2-SM-01-02</code> → SM → 業務課）、
            <b>相關表單編號與名稱</b>、<b>違反條文</b>（由條文題庫的「建立的文件、表單」反查）。<br>
            沒有設定的項目會退回「稽核對象」綁定的第一份文件，單還是開得出來，只是受稽核單位要自己選。
        </p>
        <div id="ncMapBody"></div>
    </div>
    <div class="m-foot">
        <button class="btn btn-sm btn-default" data-close>關閉</button>
        <button class="btn btn-sm btn-warm" id="btnNcMapSave">儲存對照</button>
    </div>
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
/* 開立不符合通知單（2026-09-21 使用者交辦）：實際寫入一律走內稽自己的 API，
   這裡不另外開一支寫入端點——IA 單的編號、通知、歷程全都在那邊，複製一份必定走鐘。 */
var IA_API     = '../../src/store/InternalAudit_API.php';
var CAN_NC     = <?= $canNc ? 'true' : 'false' ?>;
var CAN_NC_MAP = <?= $canNcMap ? 'true' : 'false' ?>;
var NCB = null;          // dqa_prefill 回來的：檢核項目、每項推導出的表單/單位/條文、已開過的單
var NCSEL = {};          // 勾選中的缺失 'orderId|code' => {oid, code, label, detail, order_no, client, part}
var OWN_COMPANY = <?= json_encode($ownCompany) ?>;
var ST = { tab:'trace', trace:null, master:null, quote:null, filter:'', mFilter:'', qFilter:'',
           settings:null, ex:null,
           tPage:1, tPer:50, mPage:1, mPer:50, qPage:1, qPer:50, tLevel:'', mLevel:'', qLevel:'' };
/* 綁定（2026-09-21）：權限沿用資料稽核管理員（使用者拍板，不另開角色）。
   前端只是不顯示按鈕；真正的守門在 DataAudit_API 的 $WRITE 清單＋tc_link()（鐵律8）。 */
var CAN_BIND = CAN_ADMIN;
var BD = null;   // 目前開著的綁定跳窗：{orderId, kind, order, rows}

/* 卡片（判定等級）與項目籤可以同時使用，而且兩邊的數字互相反映對方的篩選：
   卡片數字＝套用「項目籤」之後各等級各有幾筆；項目籤數字＝套用「卡片」之後各項目各有幾筆。
   不這樣做的話，點了「嚴重」以後下面的項目籤還印著全部筆數，看起來像沒篩到。 */
function byCodeOf(rows){
    var m = {};
    rows.forEach(function(r){ r.issues.forEach(function(i){ m[i.code] = (m[i.code]||0) + 1; }); });
    return m;
}
function byLevelOf(rows){
    var m = {};
    rows.forEach(function(r){ m[r.level] = (m[r.level]||0) + 1; });
    return m;
}

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
    $('#pane-quote').toggle(t==='quote');
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
        ST.trace = r; ST.filter = ''; ST.tLevel = ''; ST.tPage = 1;
        NCSEL = {}; ncSyncBar();
        renderTraceStat(); renderTrace();
        $('#tTiming').text('耗時 ' + ((Date.now()-t0)/1000).toFixed(1) + ' 秒');
        loadNcMeta(function(){ renderTrace(); });      // 回來才知道哪幾筆已經開過單
    });
});

function renderTraceStat(){
    var r = ST.trace; if(!r) return;
    var s = r.stat, items = r.items || {};
    var lv = byLevelOf(traceFiltered(false, true));     // 卡片：只套用項目籤
    var bc = byCodeOf(traceFiltered(true, false));      // 項目籤：只套用卡片
    var card = function(key, cls, label){
        var n = lv[key] || 0;
        return '<div class="dq-card pick ' + cls + (ST.tLevel===key?' sel':'') + '" data-lv="' + key + '">'
             + '<b>' + n + '</b><span>' + label + '</span>'
             + '<small>' + (ST.tLevel===key ? '篩選中，再點取消' : '點一下只看這些') + '</small></div>';
    };
    var h = '<div class="dq-stat">';
    h += '<div class="dq-card"><b>' + (r.scanned||0) + '</b><span>掃描訂單</span>'
       + '<small>符合條件 ' + (r.order_total||r.scanned||0) + ' 張</small></div>';
    h += card('critical', 'c-critical', '嚴重');
    h += card('warn', 'c-warn', '提醒');
    h += card('ok', 'c-ok', '正常');
    h += '</div><div class="dq-chips">';
    h += '<span class="dq-chip' + (ST.filter===''?' on':'') + '" data-f="">全部（' + traceFiltered(true,false).length + '）</span>';
    var codes = Object.keys(bc).sort(function(a,b){ return bc[b]-bc[a]; });
    codes.forEach(function(c){
        var it = items[c] || [c,'warn'];
        h += '<span class="dq-chip lv-' + esc(it[1]) + (ST.filter===c?' on':'') + '" data-f="' + esc(c) + '">'
           + esc(it[0]) + '（' + bc[c] + '）</span>';
    });
    h += '</div>';
    h += exclBarHtml(r.excl_rules);
    if (r.truncated) h += '<div class="muted-help" style="margin-top:6px;color:#B23A2A">'
        + '⚠ 這個期間共有 ' + (r.order_total||0) + ' 張訂單，超過單次上限 ' + (r.limit||0)
        + ' 張，只稽核了最近的 ' + (r.scanned||0) + ' 張。'
        + '請用上方的<b>期間</b>切成季或月逐段稽核，或指定客戶／料號。</div>';
    $('#tStatBox').show().html(h);
}
$(document).on('click', '#tStatBox .dq-chip', function(){
    ST.filter = String($(this).data('f')||''); ST.tPage = 1; renderTraceStat(); renderTrace();
});
$(document).on('click', '#tStatBox .dq-card.pick', function(){
    var k = String($(this).data('lv')||'');
    ST.tLevel = (ST.tLevel === k) ? '' : k;      // 再點一次＝取消
    ST.tPage = 1; renderTraceStat(); renderTrace();
});

function traceFiltered(useLevel, useCode){
    var rows = (ST.trace && ST.trace.rows) || [];
    return rows.filter(function(r){
        if (useLevel && ST.tLevel && r.level !== ST.tLevel) return false;
        if (useCode && ST.filter && !r.issues.some(function(i){ return i.code === ST.filter; })) return false;
        return true;
    });
}
function traceRows(){ return traceFiltered(true, true); }
/* 製程做成標籤、備註是自由文字所以截字並把完整內容掛在 title（滑過去看得到全文）。 */
function procLine(procs, notes){
    var h = '';
    (procs||[]).forEach(function(p){ if (p) h += '<span class="pill grey">' + esc(p) + '</span>'; });
    var seen = {};
    (notes||[]).forEach(function(n){
        var t = String(n==null?'':n).replace(/\s+/g, ' ').trim();
        if (!t || seen[t]) return; seen[t] = 1;
        h += '<div class="nt" title="' + esc(t) + '">' + esc(t.length > 30 ? t.slice(0,30) + '…' : t) + '</div>';
    });
    return h ? '<div class="proc-line">' + h + '</div>' : '';
}
/* 出貨單價（2026-09-21 使用者回報：出貨欄沒印單價，沒辦法快速核對）。
   一張訂單常分好幾張出貨單、單價不一定一樣（實測「車+銑 ＠175」與「滾齒 ＠70」是同一張訂單），
   所以有幾種單價就印幾種，不可以只印第一張的。 */
function shipPrices(list){
    var seen = {}, out = [], none = 0;
    (list||[]).forEach(function(x){
        var p = +x.price || 0;
        if (p > 0) { if (!seen[p]) { seen[p] = 1; out.push(p); } } else none++;
    });
    return { list: out, none: none };
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
/* 單號連結：點了直接開相關頁面，並把**客戶與料號一起帶過去**當篩選條件。
 * ⚠ 只帶單號不夠（2026-09-18 使用者回報）：同一個訂單編號會有好幾列
 *   （拆批、同編號多料號），只篩單號會連無關料號一起列出來，很容易改到不該改的那一列。
 *   各目標頁能篩什麼就帶什麼：
 *     訂單 → 訂單追蹤：客戶、料號、全表搜尋三個欄位都帶
 *     製令 → BOM 總表：客戶欄 ＋ BOM 欄（那個欄位同時比對 BOM 編號與料號）
 *     報價 → 報價單管理：客戶下拉 ＋ 單號關鍵字（該頁一張單一列，沒有料號欄位）
 *     出貨 → 快速出貨：近期出貨單依單號 GROUP BY 只有一列，另把客戶料號顯示成提示條 */
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
/* 製令：**已完工的要連到別一頁**——BOM 總表只列未完工的製令，
   已完工的製令在那一頁一列都查不到（實測只帶 BOM 編號也是 0 列，不是篩選寫錯）。
   客戶一律用「這張製令自己的」Client_Name，拿訂單的客戶去篩別張表可能篩出 0 筆。 */
function bomDocs(list, r){
    return (list||[]).map(function(b){
        var cl = b.client || '', u, where;
        if (b.done){
            u = '../pm/OreadyReply_completed_query.php?kw=' + encodeURIComponent(b.no);
            where = '在「已完工BOM查詢列印」搜尋 ';
        } else {
            u = '../pm/OreadyReply_ForPm_BaseOfTime.php?bom_filter=' + encodeURIComponent(b.no);
            if (cl) u += '&customer_filter=' + encodeURIComponent(cl);
            where = '在 BOM 總表篩選 ';
        }
        return {no:b.no, url:u, done:!!b.done,
                tip:where + b.no + (cl?('（客戶 ' + cl + '）'):'')
                    + '（' + (b.date||'') + '，' + b.qty + ' 支'
                    + (b.done?'，已完工':'') + '）'
                    + ((b.procs&&b.procs.length)?('；製程 ' + b.procs.join('→')):'')};
    });
}
/* 報價：客戶要用**這張報價單自己的** client_name——報價單管理的客戶下拉是由該年度的報價單建出來的，
   拿訂單的客戶名稱過去可能在下拉裡根本沒有那一個選項（選項不存在就等於沒篩到）。 */
function quoteUrl(no, date, r, q){
    var y = String(date||'').slice(0,4);
    var u = '../Sales/quotation_list_NEW.php' + (y ? ('?year=' + y + '&kw=') : '?kw=') + encodeURIComponent(no);
    var cl = (q && q.client) || (r && r.client) || '';
    if (cl) u += '&client=' + encodeURIComponent(cl);
    return u;
}
/* 訂單追蹤：客戶、料號、全表搜尋三個欄位都帶（只帶單號會列出同編號的其他料號）。
   全表搜尋一有值該頁就自動切成「全部年份」，所以不必帶年度。 */
function orderUrl(r){
    var u = '../Sales/NewOrder_Track.php?kw=' + encodeURIComponent(r.order_no);
    if (r.part)   u += '&part=' + encodeURIComponent(r.part);
    if (r.client) u += '&client=' + encodeURIComponent(r.client);
    return u;
}
function shipDocs(list, r){
    return (list||[]).map(function(x){
        var f = shiftDate(x.date, -7), t = shiftDate(x.date, 7);
        var cl = x.client || (r && r.client) || '', pt = x.part || (r && r.part) || '';
        var u = '../Sales/Shipping_Quick.php?is_no=' + encodeURIComponent(x.no);
        if (f && t) u += '&from=' + f + '&to=' + t;
        if (pt) u += '&part=' + encodeURIComponent(pt);
        if (cl) u += '&client=' + encodeURIComponent(cl);
        return {no:x.no, url:u,
                tip:'在快速出貨的近期出貨單查 ' + x.no + '（' + (x.date||'') + '，' + x.qty + ' 支，'
                    + ((+x.price>0) ? ('＠' + x.price) : '未開價') + '）'
                    + (x.content?('；內容 ' + x.content):'') + (x.note?('；備註 ' + x.note):'')
                    + (pt?('；要核對的料號 ' + pt):'')};
    });
}
/* ══════════ 綁定（2026-09-21 使用者交辦）══════════
 * 使用者原話：「如果這個頁面同時也可以做綁定功能呢? 因為確認無誤就可以直接綁定」。
 * 缺失的成因多半就是「沒做綁定」，查出來還要換到另外三個頁面各綁一次，實務上就不會有人去綁。
 *
 * 三種節點的關係不一樣，所以跳窗長得不一樣：
 *   報價 → 訂單：多對多、**不拆量**（一份報價本來就會被很多張訂單引用）
 *   訂單 → 製令：多對多、要拆量
 *   訂單 → 出貨：多對多、要拆量
 * 拆量的那兩種一定要印出「本身數量／已分配／還剩多少」，不然按下去才發現超量被擋。
 */
var BD_LABEL = {quote:'報價單', bom:'製令', ship:'出貨單'};
function bindBtn(r, kind){
    var has = (kind==='quote') ? !!r.quote : (kind==='bom' ? r.bom.cnt>0 : r.ship.cnt>0);
    var isBound = (kind==='quote') ? (r.quote && r.quote.src==='bind')
                : (kind==='bom' ? (r.bom.src==='map'||r.bom.src==='legacy')
                                : (r.ship.src==='map'||r.ship.src==='legacy'));
    var lab = isBound ? '改綁' : (has ? '綁定（目前是推測）' : '綁定');
    return '<span class="bind-go" data-bind="' + r.order_id + '" data-kind="' + kind + '">'
         + '<i class="fa fa-link"></i> ' + lab + '</span>';
}
$(document).on('click','[data-bind]', function(){
    openBind(parseInt($(this).data('bind'),10), String($(this).data('kind')));
});
function openBind(orderId, kind){
    BD = {orderId:orderId, kind:kind, order:null, rows:[]};
    $('#bdTitle').text('綁定' + BD_LABEL[kind]);
    $('#bdKw').val(''); $('#bdSamePart').prop('checked', true); $('#bdAllClient').prop('checked', false);
    $('#bdTbl thead,#bdTbl tbody').empty();
    $('#bdSum').html('載入中…'); $('#bdHint').text(''); $('#bdFoot').html('');
    openMask('bindMask');
    loadBind();
}
function loadBind(){
    if (!BD) return;
    $.get(API, {action:'node_candidates', order_id:BD.orderId, kind:BD.kind,
                kw:$('#bdKw').val(), same_part:$('#bdSamePart').is(':checked')?1:0,
                all_client:$('#bdAllClient').is(':checked')?1:0}, function(r){
        if(!r || !r.ok) return;
        BD.order = r.order; BD.rows = r.rows || [];
        renderBindSum(); renderBindRows();
    });
}
$('#bdSearch').on('click', loadBind);
$('#bdKw').on('keydown', function(e){ if(e.key==='Enter'){ e.preventDefault(); loadBind(); } });
$('#bdSamePart,#bdAllClient').on('change', loadBind);

/* 表頭一定要把「這張訂單現在到底有沒有報價／製令／出貨」一起講出來——
   使用者要求「上面也要直接顯示是否有此筆出貨單」，在綁定當下才不必切回去看。 */
function renderBindSum(){
    var o = BD.order, row = findTraceRow(BD.orderId);
    var h = '<div><b>' + esc(o.no) + '</b>　' + esc(o.client) + '　' + esc(o.part)
          + '　' + o.qty + ' 支 ＠' + o.price + '　訂單日 ' + dispDate(o.odate)
          + (o.ddate ? ('　交期 ' + dispDate(o.ddate)) : '')
          + (o.closed ? '　<span class="pill grey">已結案</span>' : '')
          + (o.auto_pm ? '　<span class="pill grey">自動轉生管</span>' : '') + '</div>';
    if (o.proc) h += '<div class="muted-help">訂單製程：' + esc(o.proc) + '</div>';
    if (row){
        h += '<div style="margin-top:4px">'
           + '<span class="n">報價：' + nodeSum(row.quote ? ((row.quote.cnt>1?(row.quote.cnt+' 列／'):'') + row.quote.no
                 + '（' + (row.quote.tiered?'階梯':(row.quote.qty+' 支')) + ' ＠' + row.quote.price + '）') : '', row.quote && row.quote.src) + '</span>'
           + '<span class="n">製令：' + nodeSum(row.bom.cnt ? (row.bom.cnt + ' 張／' + row.bom.qty + ' 支') : '', row.bom.src) + '</span>'
           + '<span class="n">出貨：' + nodeSum(row.ship.cnt ? ((row.ship.doc_cnt||row.ship.cnt) + ' 張／' + row.ship.qty + ' 支') : '', row.ship.src) + '</span>'
           + '</div>';
    }
    $('#bdSum').html(h);
    $('#bdTip').html(BD.kind==='quote'
        ? '報價與訂單<b>不拆量</b>：一份報價本來就會被很多張訂單引用，所以這裡只要勾「這張訂單是依哪一列報價下的」。'
          + '<b>一張訂單可以同時綁本體與治具／刀具兩列</b>——治具那一列的料號與訂單不同，要先取消「只列同料號」才找得到。'
        : '這一種是<b>多對多而且會拆量</b>：一張' + BD_LABEL[BD.kind]
          + '可以分給好幾張訂單。「可分配」＝這張單自己的數量扣掉已經分配出去的，超過會被擋下。');
}
function nodeSum(txt, src){
    if (!txt) return '<span class="pill bad">沒有</span>';
    var lab = (src==='guess') ? '<span class="pill warn">推測</span>' : '<span class="pill ok">已綁定</span>';
    return esc(txt) + ' ' + lab;
}
function findTraceRow(oid){
    var rows = (ST.trace && ST.trace.rows) || [];
    for (var i=0;i<rows.length;i++) if (rows[i].order_id === oid) return rows[i];
    return null;
}

function renderBindRows(){
    var k = BD.kind, th, body;
    if (k === 'quote'){
        th = '<tr><th style="width:96px">報價單</th><th style="width:76px">報價日</th><th style="width:120px">料號</th>'
           + '<th style="width:130px">製程</th><th>備註／階梯區間</th><th style="width:96px">數量</th>'
           + '<th style="width:74px">單價</th><th style="width:150px">狀態</th><th style="width:120px"></th></tr>';
        body = BD.rows.map(function(x){
            var cls = x.bound ? 'bound' : (x.late ? 'risky' : '');
            /* 階梯報價一列有好幾個價格，整列綁下去等於一次綁了三種單價（2026-09-21 使用者回報），
               所以每一階各給一顆綁定鈕；已經綁住的那一階標出來、可單獨解除。 */
            var tiers = (x.tiers||[]).map(function(t){
                var on = (x.bound && x.bound_tier && +x.bound_tier === +t.tier_id);
                return '<div class="tierline' + (on?' on':'') + '">' + esc(t.range) + ' 支 ＠' + t.price
                     + (t.tol ? ('　容差 ' + esc(t.tol)) : '')
                     + (t.tol_note ? ('　' + esc(t.tol_note)) : '')
                     + (CAN_BIND ? ('<span class="tier-go' + (on?' on':'') + '" data-tier="' + t.tier_id
                                    + '" data-item="' + x.id + '">' + (on?'✓ 已綁這一階':'綁這一階') + '</span>') : '')
                     + '</div>';
            }).join('');
            var st = [];
            if (x.bound){
                var bt = null;
                (x.tiers||[]).forEach(function(t){ if (+t.tier_id === +(x.bound_tier||0)) bt = t; });
                st.push('<span class="pill ok">已綁這張訂單'
                    + (bt ? ('（' + bt.range + ' ＠' + bt.price + '）')
                          : (x.tiered ? '（整列，未指定階梯）' : '')) + '</span>');
            }
            if (x.used_by)   st.push('<span class="pill grey">' + x.used_by + ' 張訂單引用</span>');
            if (x.late)      st.push('<span class="pill bad">報價晚於訂單</span>');
            if (x.note_only) st.push('<span class="pill grey">備註列</span>');
            if (x.tiered)    st.push('<span class="pill warn">階梯報價</span>');
            if (x.part && BD.order && x.part.toLowerCase() !== String(BD.order.part||'').toLowerCase())
                st.push('<span class="pill warn">料號與訂單不同</span>');
            return '<tr class="' + cls + '">'
                 + '<td>' + esc(x.no) + '<div class="muted-help">' + esc(x.client) + '</div></td>'
                 + '<td>' + dispDate(x.date) + '</td>'
                 + '<td>' + esc(x.part) + '</td>'
                 + '<td>' + ((x.procs||[]).map(function(p){ return '<span class="pill grey">'+esc(p)+'</span>'; }).join('') || '<span class="muted-help">—</span>') + '</td>'
                 + '<td>' + (x.spec ? esc(x.spec) : '') + tiers
                        + '<div><span class="bind-go" data-qd="' + x.id + '">看整張報價單</span></div></td>'
                 + '<td>' + (x.tiered ? '見階梯' : (x.qty + ' ' + esc(x.unit||''))) + '</td>'
                 + '<td>' + x.price + '</td>'
                 + '<td>' + (st.join('') || '<span class="muted-help">可綁定</span>') + '</td>'
                 + '<td>' + bdActionBtn(x) + '</td></tr>';
        }).join('');
    } else if (k === 'bom'){
        th = '<tr><th style="width:130px">製令</th><th style="width:80px">開立日</th><th style="width:140px">料號</th>'
           + '<th style="width:92px">客戶</th><th style="width:150px">數量／可分配</th>'
           + '<th style="width:170px">狀態</th><th>分配量</th></tr>';
        body = BD.rows.map(bdQtyRow).join('');
    } else {
        th = '<tr><th style="width:130px">出貨單</th><th style="width:80px">出貨日</th><th style="width:140px">料號</th>'
           + '<th style="width:92px">客戶</th><th style="width:150px">數量／可分配</th>'
           + '<th style="width:170px">狀態</th><th>分配量</th></tr>';
        body = BD.rows.map(bdQtyRow).join('');
    }
    $('#bdTbl thead').html(th);
    $('#bdTbl tbody').html(body || '<tr><td colspan="9" style="text-align:center;padding:20px;color:#8a7560">'
        + '找不到候選單據。可以取消「只列同料號」、勾「不限客戶」，或直接用上方關鍵字搜尋單號。</td></tr>');
    var nb = BD.rows.filter(function(x){ return x.bound; }).length;
    $('#bdHint').text('候選 ' + BD.rows.length + ' 筆，其中 ' + nb + ' 筆已綁在這張訂單上');
}
function bdQtyRow(x){
    var cls = x.bound ? 'bound' : ((x.early || x.other_order || x.noprice) ? 'risky' : '');
    var st = [];
    if (x.bound)       st.push('<span class="pill ok">已綁這張訂單 ' + x.mine + '</span>');
    if (x.used_by)     st.push('<span class="pill grey">分給 ' + x.used_by + ' 張訂單</span>');
    if (x.other_order) st.push('<span class="pill warn">已掛在別張訂單</span>');
    if (x.done)        st.push('<span class="pill grey">已完工</span>');
    if (x.early)       st.push('<span class="pill bad">' + (BD.kind==='bom'?'早於訂單日':'早於訂單日') + '</span>');
    if (x.noprice)     st.push('<span class="pill bad">未開價</span>');
    if (x.part && BD.order && x.part.toLowerCase() !== String(BD.order.part||'').toLowerCase())
        st.push('<span class="pill warn">料號與訂單不同</span>');
    var def = bdDefaultQty(x);
    return '<tr class="' + cls + '">'
         + '<td>' + esc(x.no) + (x.spec ? ('<div class="muted-help">' + esc(x.spec) + '</div>') : '') + '</td>'
         + '<td>' + dispDate(x.date) + '</td>'
         + '<td>' + esc(x.part) + '</td>'
         + '<td>' + esc(x.client) + '</td>'
         + '<td>' + x.qty + ' 支　<span class="muted-help">已分配 ' + x.alloc + '／可分配 ' + x.free + '</span>'
                  + (BD.kind==='ship' ? ('<div class="muted-help">單價 ' + x.price + '</div>') : '') + '</td>'
         + '<td>' + (st.join('') || '<span class="muted-help">可綁定</span>') + '</td>'
         + '<td><input type="number" class="qin" data-q="' + esc(String(x.id)) + '" min="1" value="' + def + '"> '
                  + bdActionBtn(x) + '</td></tr>';
}
/* 預設分配量＝兩邊剩餘量取小者（這張單還剩多少可分配 vs 這張訂單還差多少沒分配）。
   使用者可以直接改；後端 tc_link() 會再擋一次超量。 */
function bdDefaultQty(x){
    if (x.bound) return x.mine || 1;
    var used = 0;
    BD.rows.forEach(function(y){ if (y.bound) used += (y.mine||0); });
    var need = Math.max(0, (BD.order ? BD.order.qty : 0) - used);
    var v = Math.min(x.free || 0, need || (x.free || 0));
    return Math.max(1, Math.round(v || x.free || 1));
}
function bdActionBtn(x){
    if (x.bound) return '<button class="btn btn-xs btn-default" data-unbind="' + esc(String(x.id)) + '">解除</button>';
    // 階梯報價的整列綁定要講明白是「不指定階梯」，不然會以為這顆跟階梯那幾顆是一樣的
    return '<button class="btn btn-xs btn-warm" data-dobind="' + esc(String(x.id)) + '">'
         + ((x.tiered && (x.tiers||[]).length) ? '整列綁定' : '綁定') + '</button>';
}

/* 會動到既有綁定時一定要先問一次（使用者明確要求）——
   把「會改到什麼」講清楚再讓人按下去，不要事後才發現別張訂單的貨被搬走了。 */
function bdConfirmText(x, kind){
    var w = [];
    if (kind === 'quote'){
        var cur = BD.rows.filter(function(y){ return y.bound; });
        if (cur.length) w.push('這張訂單目前已經綁了 ' + cur.length + ' 列報價（'
            + cur.map(function(y){ return y.no + ' ' + y.part; }).join('、')
            + '）。再綁一列之後，訂單上顯示的「主要報價」可能會換成料號相同的那一列。');
        if (x.used_by) w.push('這一列報價目前已經被 ' + x.used_by + ' 張訂單引用（多張訂單對同一份報價是正常的）。');
        if (x.part && BD.order && x.part.toLowerCase() !== String(BD.order.part||'').toLowerCase())
            w.push('報價料號「' + x.part + '」與訂單料號「' + BD.order.part + '」不同。若這是治具／刀具或組合件拆件才是對的。');
    } else {
        if (x.other_order) w.push('這張' + BD_LABEL[kind] + '目前掛在<別張訂單>上（Order_id ' + x.other_order
            + '）。綁到這張訂單之後，它的「主要訂單」會換成這一張。');
        if (x.used_by)  w.push('這張' + BD_LABEL[kind] + '已經分配給 ' + x.used_by + ' 張訂單，這次是再分一部分出來。');
        if (x.early)    w.push('它的日期早於訂單日，請先確認是不是配錯對象。');
        if (x.part && BD.order && x.part.toLowerCase() !== String(BD.order.part||'').toLowerCase())
            w.push('料號「' + x.part + '」與訂單料號「' + BD.order.part + '」不同。');
    }
    return w.length ? ('要綁定 ' + x.no + ' 嗎？\n\n・' + w.join('\n・') + '\n\n確定要繼續嗎？') : '';
}
$(document).on('click','[data-dobind]', function(){
    var id = String($(this).data('dobind'));
    var x = bdFind(id); if (!x) return;
    var msg = bdConfirmText(x, BD.kind);
    if (msg && !confirm(msg)) return;
    var qty = BD.kind==='quote' ? 0 : (parseInt($('.qin[data-q="'+id.replace(/"/g,'\\"')+'"]').val(),10) || 0);
    if (BD.kind!=='quote' && qty <= 0){ alert('請填分配量（要大於 0）'); return; }
    bdPost('bind', id, qty, $(this));
});
$(document).on('click','.tier-go', function(){
    var iid = String($(this).data('item')), tid = parseInt($(this).data('tier'), 10) || 0;
    var x = bdFind(iid); if (!x || !tid) return;
    var t = null; (x.tiers||[]).forEach(function(y){ if (+y.tier_id === tid) t = y; });
    if (!t) return;
    if (x.bound && +(x.bound_tier||0) === tid){
        if (!confirm('要解除「' + x.no + '」' + t.range + ' 支 ＠' + t.price
            + ' 這一階與這張訂單的對應嗎？\n\n解除只是拿掉對應關係，不會刪掉任何單據。')) return;
        bdPost('unbind', iid, 0, $(this)); return;
    }
    var w = [];
    if (x.bound) w.push('這一列報價目前綁的是'
        + (+(x.bound_tier||0) ? '另一階' : '整列（未指定階梯）') + '，改綁之後會以這一階的單價為準。');
    // 訂單數量落在哪一階本來就是判斷依據，不在區間內先講出來（後端也會再警示一次）
    var oq = BD.order ? +BD.order.qty : 0;
    if (oq > 0 && (oq < +t.min || (t.max !== null && oq > +t.max)))
        w.push('訂單數量 ' + oq + ' 不在這一階的區間（' + t.range + '）內，請確認是不是要綁別一階。');
    if (w.length && !confirm('要把這張訂單綁到「' + x.no + '」' + t.range + ' 支 ＠' + t.price
        + ' 這一階嗎？\n\n・' + w.join('\n・') + '\n\n確定要繼續嗎？')) return;
    bdPost('bind', iid, 0, $(this), tid);
});
$(document).on('click','[data-unbind]', function(){
    var id = String($(this).data('unbind'));
    var x = bdFind(id); if (!x) return;
    if (!confirm('要解除「' + x.no + '」與這張訂單的對應嗎？\n\n解除只是拿掉對應關係，不會刪掉任何單據。')) return;
    bdPost('unbind', id, 0, $(this));
});
function bdFind(id){
    for (var i=0;i<BD.rows.length;i++) if (String(BD.rows[i].id) === id) return BD.rows[i];
    return null;
}
function bdPost(act, id, qty, $btn, tierId){
    $btn.prop('disabled', true);
    $.post(API, {action:act, csrf:CSRF, order_id:BD.orderId, kind:BD.kind, target:id, qty:qty,
                 tier_id:(tierId||0)}, function(r){
        $btn.prop('disabled', false);
        if(!r || !r.ok) return;
        var w = (r.warn && r.warn.length) ? ('\n\n提醒：\n・' + r.warn.join('\n・')) : '';
        $('#bdFoot').html('<span style="color:#5C8A4A">' + esc(r.msg || '已更新')
            + (r.warn && r.warn.length ? ('　' + esc(r.warn.join('；'))) : '') + '</span>');
        if (w) alert((r.msg||'已更新') + w);
        applyRecheck(r.rows);
        loadBind();                       // 重抓候選，剩餘量與狀態才會跟著更新
    }).fail(function(){ $btn.prop('disabled', false); });
}
/* 綁完之後只把那一列換掉，不重跑整份稽核（重跑要掃幾千張訂單，而且會把畫面捲回最上面） */
function applyRecheck(rows){
    if (!rows || !rows.length || !ST.trace) return;
    rows.forEach(function(n){
        for (var i=0;i<ST.trace.rows.length;i++){
            if (ST.trace.rows[i].order_id === n.order_id){ ST.trace.rows[i] = n; return; }
        }
    });
    renderTraceStat(); renderTrace();
}

/* 整張報價單的內容（治具／刀具那幾列有沒有訂單、有沒有出貨、出貨有沒有開價） */
$(document).on('click','[data-qd]', function(){
    var iid = parseInt($(this).data('qd'),10);
    if (!iid) return;
    $('#qdTitle').text('報價單內容'); $('#qdBody').html('載入中…'); openMask('qdMask');
    $.get(API, {action:'quote_detail', item_id:iid}, function(r){
        if(!r || !r.ok) return;
        $('#qdTitle').text('報價單 ' + r.head.quote_no + '　' + r.head.client_name);
        var h = '<div class="bd-sum"><b>' + esc(r.head.quote_no) + '</b>　' + esc(r.head.client_name)
              + '　報價日 ' + dispDate(r.head.quote_date)
              + (r.head.valid_until ? ('　有效至 ' + dispDate(r.head.valid_until)) : '')
              + (r.head.inquiry_no ? ('　詢價單 ' + esc(r.head.inquiry_no)) : '')
              + '　合計 ' + (r.head.total_amount||0)
              + (r.head.note ? ('<div class="muted-help">備註：' + esc(r.head.note) + '</div>') : '')
              + '</div>'
              + '<div class="muted-help" style="margin-bottom:6px">'
              + '整張報價單的每一列都列在這裡（含治具、刀具與備註列）。'
              + '<b>紅底＝這一列沒有任何訂單</b>——如果同一張單的其他列都已經下單了，'
              + '那這一列就是漏開的，做了會收不到錢。</div>';
        h += '<table class="qd-tbl"><thead><tr><th style="width:130px">料號</th><th style="width:120px">製程</th>'
           + '<th>備註／階梯區間</th><th style="width:92px">數量</th><th style="width:70px">單價</th>'
           + '<th style="width:80px">金額</th><th style="width:170px">訂單</th><th style="width:170px">出貨</th></tr></thead><tbody>';
        r.items.forEach(function(x){
            var cls = x.note_only ? 'noteonly' : (x.orders.length ? '' : 'noorder');
            var tiers = (x.tiers||[]).map(function(t){
                return '<div class="tierline">' + esc(t.range) + ' 支 ＠' + t.price
                     + (t.tol ? ('　容差 ' + esc(t.tol)) : '') + '</div>'; }).join('');
            var ords = x.orders.length
                ? x.orders.map(function(o){ return '<div>' + esc(o.no) + '　' + dispDate(o.date)
                       + '　' + o.qty + ' 支' + (o.closed?' <span class="pill grey">已結案</span>':'') + '</div>'; }).join('')
                : (x.note_only ? '<span class="muted-help">備註列</span>' : '<span class="pill bad">沒有訂單</span>');
            var shs = x.ships.length
                ? x.ships.map(function(s){ return '<div>' + esc(s.no) + '　' + dispDate(s.date) + '　' + s.qty + ' 支'
                       + (s.price>0 ? ('　＠'+s.price) : ' <span class="pill bad">未開價</span>') + '</div>'; }).join('')
                : (x.orders.length ? '<span class="pill warn">尚未出貨</span>' : '<span class="muted-help">—</span>');
            h += '<tr class="' + cls + '"><td>' + esc(x.part)
               + (x.note_only ? ' <span class="pill grey">備註列</span>' : '') + '</td>'
               + '<td>' + ((x.procs||[]).map(function(p){ return '<span class="pill grey">'+esc(p)+'</span>'; }).join('') || '—') + '</td>'
               + '<td>' + esc(x.spec||'') + tiers + '</td>'
               + '<td>' + (x.tiered ? '見階梯' : (x.qty + ' ' + esc(x.unit||''))) + '</td>'
               + '<td>' + x.price + '</td><td>' + x.amount + '</td>'
               + '<td>' + ords + '</td><td>' + shs + '</td></tr>';
        });
        h += '</tbody></table>';
        $('#qdBody').html(h);
    });
});

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
        // 報價節點一定要印出「報價數量」（2026-09-21 使用者回報：原本只有日期與單價，
        // 而「報價數量與訂單數量不符」正是最常出現的那一條缺失，看不到數量就核對不了）
        var q;
        if (r.quote){
            // 綁到某一階就印那一階（整列的 unit_price 在階梯報價上常常是 0，印出來看不出東西）
            var qsub = r.quote.tier
                ? (r.quote.tier.range + ' 支 ＠' + r.quote.tier.price)
                : ((r.quote.tiered ? '階梯報價' : (r.quote.qty + ' 支')) + ' ＠' + r.quote.price);
            var qdocs = (r.quote_list && r.quote_list.length ? r.quote_list : [r.quote]).map(function(x){
                return {no:x.no, url:quoteUrl(x.no, x.date, r, x),
                        tip:'在報價單管理搜尋 ' + x.no + '（' + (x.part||'') + '、'
                            + (x.tier ? (x.tier.range + ' 支 ＠' + x.tier.price + ' 這一階')
                                      : (x.tiered ? '階梯報價（未指定階梯）' : (x.qty + ' 支 ＠' + x.price))) + '）'};
            });
            q = nodeHtml(dispDate(r.quote.date), qsub, qBad, r.quote.src, qdocs);
            if (r.quote.cnt > 1) q += '<span class="pill grey">共 ' + r.quote.cnt + ' 列</span>';
            if (r.quote.tiered && !r.quote.tier && r.quote.src !== 'guess')
                q += '<span class="pill warn" title="階梯報價一列有好幾個單價，沒有指定是哪一階就核對不出單價對不對。'
                   + '按下方的綁定鈕，在階梯那幾行各自有一顆「綁這一階」。">未指定階梯</span>';
            q += '<span class="bind-go" data-qd="' + (r.quote.item_id||0) + '">看整張報價單</span>';
            q += procLine(r.quote.procs, [r.quote.spec]);
        } else {
            q = '<span class="node bad">無報價</span>';
        }
        var bp = [], bpSeen = {};
        (r.bom.list||[]).forEach(function(x){ (x.procs||[]).forEach(function(n){
            if (n && !bpSeen[n]) { bpSeen[n] = 1; bp.push(n); } }); });
        var b = r.bom.cnt
            ? (nodeHtml(dispDate(r.bom.date), r.bom.cnt + ' 張／' + r.bom.qty + ' 支', bBad, r.bom.src, bomDocs(r.bom.list, r))
               + procLine(bp, null))
            : (r.auto_pm ? '<span class="node"><em>自動轉生管<br>不需製令</em></span>' : '<span class="node bad">無製令</span>');
        var sp = shipPrices(r.ship.list);
        var sh;
        if (r.ship.cnt){
            sh = nodeHtml(dispDate(r.ship.date),
                    (r.ship.doc_cnt||r.ship.cnt) + ' 張／' + r.ship.qty + ' 支'
                    + (sp.list.length ? '　＠' + sp.list.slice(0,3).join('／') + (sp.list.length>3?'…':'') : ''),
                    sBad, r.ship.src, shipDocs(r.ship.list, r));
            if (sp.none) sh += '<span class="pill bad">'
                + (sp.list.length ? (sp.none + ' 張未開價') : '未開價') + '</span>';
            sh += procLine(null, (r.ship.list||[]).map(function(x){ return x.content; })
                                 .concat((r.ship.list||[]).map(function(x){ return x.note; })));
        } else {
            sh = '<span class="node' + (r.closed?' bad':'') + '">未出貨</span>';
        }
        if (CAN_BIND){
            q  += bindBtn(r, 'quote');
            b  += bindBtn(r, 'bom');
            sh += bindBtn(r, 'ship');
        }
        var iss = r.issues.map(function(i){
            return '<div class="issue-line"><span class="lv-badge ' + i.level + '">'
                 + (i.level==='critical'?'嚴重':'提醒') + '</span> ' + esc(i.text)
                 + (CAN_ADMIN ? ' <span class="lnk" data-ex-trace="' + r.order_id + '" data-code="' + esc(i.code) + '">標為例外</span>' : '')
                 + ncCellHtml(r, i)
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
                + esc(orderUrl(r)) + '" title="在訂單追蹤篩選 ' + esc(r.order_no)
                + '（客戶 ' + esc(r.client) + '、料號 ' + esc(r.part) + '）">'
                + esc(r.order_no) + '</a><em class="muted-help">' + dispDate(r.odate) + '</em>'
                // 數量與單價是「這張訂單」的屬性不是料號的，放在訂單日期下方（使用者指定，2026-09-21）。
                // .muted-help 是 <em>＝inline，不補 <br> 會跟日期擠在同一行
                + '<br><em class="muted-help">' + r.oqty + ' 支 ＠' + r.oprice + '</em>'
                + (r.closed?'<br><em class="muted-help">已結案</em>':'') + '</td>'
            + '<td>' + esc(r.client) + '</td>'
            + '<td>' + esc(r.part) + procLine(r.oproc ? [r.oproc] : null, [r.ospec, r.ops]) + '</td>'
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
        ST.master = r; ST.mFilter = ''; ST.mLevel = ''; ST.mPage = 1;
        renderMasterStat(); renderMaster();
        $('#mTiming').text('耗時 ' + ((Date.now()-t0)/1000).toFixed(1) + ' 秒');
    });
});
$('#mType').on('change', function(){ if(ST.master) $('#btnMasterRun').click(); });

function renderMasterStat(){
    var r = ST.master; if(!r) return;
    var s = r.stat, f = r.fields || {};
    var lv = byLevelOf(masterFiltered(false, true));
    var bc = byCodeOf(masterFiltered(true, false));
    var card = function(key, cls, label){
        return '<div class="dq-card pick ' + cls + (ST.mLevel===key?' sel':'') + '" data-lv="' + key + '">'
             + '<b>' + (lv[key]||0) + '</b><span>' + label + '</span>'
             + '<small>' + (ST.mLevel===key ? '篩選中，再點取消' : '點一下只看這些') + '</small></div>';
    };
    var h = '<div class="dq-stat">';
    h += '<div class="dq-card"><b>' + s.total + '</b><span>納入稽核</span></div>';
    h += card('critical', 'c-critical', '重要缺失');
    h += card('major', 'c-warn', '一般缺失');
    h += card('minor', '', '建議補齊');
    h += card('ok', 'c-ok', '資料完善');
    h += '</div><div class="dq-chips">';
    h += '<span class="dq-chip' + (ST.mFilter===''?' on':'') + '" data-mf="">全部（'
       + masterFiltered(true,false).length + '）</span>';
    var codes = Object.keys(bc).sort(function(a,b){ return bc[b]-bc[a]; });
    codes.forEach(function(c){
        var nm = c==='dup_name' ? '名稱疑似重複建檔' : ((f[c]||[c])[0]);
        var lvc = (r.levels||{})[c] || 'major';
        h += '<span class="dq-chip lv-' + esc(lvc) + (ST.mFilter===c?' on':'') + '" data-mf="' + esc(c) + '">'
           + esc(nm) + '（' + bc[c] + '）</span>';
    });
    h += '</div><div class="muted-help" style="margin-top:6px">編碼原則：' + esc(r.rule_text) + '</div>';
    h += exclBarHtml(r.excl_rules);
    $('#mStatBox').show().html(h);
}
$(document).on('click', '#mStatBox .dq-chip', function(){
    ST.mFilter = String($(this).data('mf')||''); ST.mPage = 1; renderMasterStat(); renderMaster();
});
$(document).on('click', '#mStatBox .dq-card.pick', function(){
    var k = String($(this).data('lv')||'');
    ST.mLevel = (ST.mLevel === k) ? '' : k;
    ST.mPage = 1; renderMasterStat(); renderMaster();
});

function masterFiltered(useLevel, useCode){
    var rows = (ST.master && ST.master.rows) || [];
    return rows.filter(function(r){
        if (useLevel && ST.mLevel && r.level !== ST.mLevel) return false;
        if (useCode && ST.mFilter && !r.issues.some(function(i){ return i.code === ST.mFilter; })) return false;
        return true;
    });
}
function masterRows(){ return masterFiltered(true, true); }
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

/* ══════════ 分頁三：報價項目追蹤 ══════════
 * 主軸是**報價項目**不是訂單——「報價了但訂單根本沒建立」那種缺失，
 * 在以訂單為主軸的分頁一永遠不會出現（那一列訂單不存在）。 */
$('#btnQuoteRun').on('click', function(){
    var t0 = Date.now();
    $('#qTiming').text('稽核中…');
    $('#qTbl tbody').html('<tr><td colspan="8" style="text-align:center;padding:24px;color:#8a7560">稽核中，請稍候…</td></tr>');
    $.get(API, {action:'quote_list', from:$('#qFrom').val(), to:$('#qTo').val(),
                client:$('#qClient').val(), part:$('#qPart').val(),
                only_bad: $('#qOnlyBad').is(':checked') ? 1 : 0}, function(r){
        if(!r || !r.ok) return;
        ST.quote = r; ST.qFilter = ''; ST.qLevel = ''; ST.qPage = 1;
        renderQuoteStat(); renderQuote();
        $('#qTiming').text('耗時 ' + ((Date.now()-t0)/1000).toFixed(1) + ' 秒');
    });
});
function quoteFiltered(useLevel, useCode){
    var rows = (ST.quote && ST.quote.rows) || [];
    return rows.filter(function(r){
        if (useLevel && ST.qLevel && r.level !== ST.qLevel) return false;
        if (useCode && ST.qFilter && !r.issues.some(function(i){ return i.code === ST.qFilter; })) return false;
        return true;
    });
}
function quoteRowsF(){ return quoteFiltered(true, true); }
function renderQuoteStat(){
    var r = ST.quote; if(!r) return;
    var items = r.items || {};
    var lv = byLevelOf(quoteFiltered(false, true));
    var bc = byCodeOf(quoteFiltered(true, false));
    var card = function(key, cls, label){
        return '<div class="dq-card pick ' + cls + (ST.qLevel===key?' sel':'') + '" data-qlv="' + key + '">'
             + '<b>' + (lv[key]||0) + '</b><span>' + label + '</span>'
             + '<small>' + (ST.qLevel===key ? '篩選中，再點取消' : '點一下只看這些') + '</small></div>';
    };
    var h = '<div class="dq-stat">';
    h += '<div class="dq-card"><b>' + (r.scanned||0) + '</b><span>掃描報價項目</span>'
       + '<small>符合條件 ' + (r.item_total||r.scanned||0) + ' 列</small></div>';
    h += card('critical','c-critical','嚴重') + card('warn','c-warn','提醒') + card('ok','c-ok','正常');
    h += '</div><div class="dq-chips">';
    h += '<span class="dq-chip' + (ST.qFilter===''?' on':'') + '" data-qf="">全部（' + quoteFiltered(true,false).length + '）</span>';
    Object.keys(bc).sort(function(a,b){ return bc[b]-bc[a]; }).forEach(function(c){
        var it = items[c] || [c,'warn'];
        h += '<span class="dq-chip lv-' + esc(it[1]) + (ST.qFilter===c?' on':'') + '" data-qf="' + esc(c) + '">'
           + esc(it[0]) + '（' + bc[c] + '）</span>';
    });
    h += '</div>' + exclBarHtml(r.excl_rules);
    if (r.truncated) h += '<div class="muted-help" style="margin-top:6px;color:#B23A2A">'
        + '⚠ 這個期間共有 ' + (r.item_total||0) + ' 列報價項目，超過單次上限 ' + (r.limit||0)
        + '，只稽核了最近的 ' + (r.scanned||0) + ' 列。請把報價日期區間切小一點，或指定客戶／料號。</div>';
    $('#qStatBox').show().html(h);
}
$(document).on('click', '#qStatBox .dq-chip', function(){
    ST.qFilter = String($(this).data('qf')||''); ST.qPage = 1; renderQuoteStat(); renderQuote();
});
$(document).on('click', '#qStatBox .dq-card.pick', function(){
    var k = String($(this).data('qlv')||'');
    ST.qLevel = (ST.qLevel === k) ? '' : k; ST.qPage = 1; renderQuoteStat(); renderQuote();
});
function renderQuote(){
    var all = quoteRowsF();
    renderPager('qPager', all.length, ST.qPage, ST.qPer, function(p, per){
        ST.qPage = p; ST.qPer = per; renderQuote();
    });
    var pages = ST.qPer === 0 ? 1 : Math.max(1, Math.ceil(all.length/ST.qPer));
    if (ST.qPage > pages) ST.qPage = pages;
    var rows = ST.qPer === 0 ? all : all.slice((ST.qPage-1)*ST.qPer, ST.qPage*ST.qPer);
    if (!rows.length){
        $('#qTbl tbody').html('<tr><td colspan="8" style="text-align:center;padding:24px;color:#8a7560">'
            + (ST.quote ? '沒有符合條件的資料。' : '請按「開始稽核」。') + '</td></tr>');
        $('#qFoot').text(ST.quote ? '顯示 0 筆' : '');
        return;
    }
    var h = rows.map(function(r){
        var qty = r.tiered
            ? ((r.tiers||[]).map(function(t){ return '<div class="tierline">' + esc(t.range) + ' ＠' + t.price + '</div>'; }).join('')
               || '<span class="pill warn">階梯報價</span>')
            : (r.qty + ' ' + esc(r.unit||'') + '<br><em class="muted-help">＠' + r.price + '　小計 ' + r.amount + '</em>');
        var ords = r.orders.length
            ? r.orders.map(function(o){ return '<div><a class="doc-no" target="_blank" rel="noopener" href="'
                   + esc('../Sales/NewOrder_Track.php?kw=' + encodeURIComponent(o.no)
                         + '&part=' + encodeURIComponent(r.part) + '&client=' + encodeURIComponent(r.client))
                   + '" title="在訂單追蹤篩選 ' + esc(o.no) + '">' + esc(o.no) + '</a>'
                   + '<em class="muted-help">' + dispDate(o.date) + '　' + o.qty + ' 支'
                   + (o.closed?'　已結案':'') + '</em></div>'; }).join('')
            : (r.note_only ? '<span class="muted-help">備註列</span>' : '<span class="pill bad">沒有訂單</span>');
        var shs = r.ships.length
            ? r.ships.map(function(s){ return '<div>' + esc(s.no) + '<em class="muted-help">' + dispDate(s.date)
                   + '　' + s.qty + ' 支' + (s.price>0?('　＠'+s.price):'　未開價') + '</em></div>'; }).join('')
            : (r.orders.length ? '<span class="pill warn">尚未出貨</span>' : '<span class="muted-help">—</span>');
        var iss = r.issues.map(function(i){
            return '<div class="issue-line"><span class="lv-badge ' + i.level + '">'
                 + (i.level==='critical'?'嚴重':'提醒') + '</span> ' + esc(i.text)
                 + (CAN_ADMIN ? ' <span class="lnk" data-ex-quote="' + r.item_id + '" data-code="' + esc(i.code) + '">標為例外</span>' : '')
                 + '</div>';
        }).join('');
        if (!iss) iss = '<span style="color:#5C8A4A">報價、訂單、出貨與金額都對得起來。</span>';
        var exKeys = Object.keys(r.exempt||{});
        if (exKeys.length) iss += exKeys.map(function(k){
            var e = r.exempt[k];
            return '<div class="ex-line">已核可例外：' + esc(k==='*'?'整筆不稽核':k)
                 + '（' + esc(e.by) + ' ' + dispDate(e.at) + '）'
                 + (CAN_ADMIN ? ' <span class="lnk" data-exdel-quote="' + r.item_id + '" data-code="' + esc(k) + '">取消</span>' : '')
                 + '</div>';
        }).join('');
        return '<tr class="r-' + r.level + '">'
            + '<td><span class="lv-badge ' + r.level + '">'
                + (r.level==='critical'?'嚴重':(r.level==='warn'?'提醒':'正常')) + '</span></td>'
            + '<td><a class="doc-no" style="font-size:13px" target="_blank" rel="noopener" href="'
                + esc(quoteUrl(r.quote_no, r.qdate, r, r)) + '" title="在報價單管理搜尋 ' + esc(r.quote_no) + '">'
                + esc(r.quote_no) + '</a><em class="muted-help">' + dispDate(r.qdate) + '</em>'
                + '<div><span class="bind-go" data-qd="' + r.item_id + '">看整張報價單</span></div></td>'
            + '<td>' + esc(r.client) + '</td>'
            + '<td>' + esc(r.part) + (r.note_only ? ' <span class="pill grey">備註列</span>' : '')
                + (r.spec ? ('<div class="muted-help">' + esc(r.spec) + '</div>') : '')
                + ((r.procs||[]).map(function(p){ return '<span class="pill grey">'+esc(p)+'</span>'; }).join('')) + '</td>'
            + '<td>' + qty + '</td><td>' + ords + '</td><td>' + shs + '</td>'
            + '<td>' + iss + '</td></tr>';
    }).join('');
    $('#qTbl tbody').html(h);
    $('#qFoot').text('本頁 ' + rows.length + ' 筆／符合條件 ' + all.length + ' 筆（共稽核 '
        + (ST.quote.scanned||0) + ' 列報價項目）');
}
function qtyText(r){
    return r.tiered
        ? ((r.tiers||[]).map(function(t){ return t.range + '＠' + t.price; }).join(' / ') || '階梯報價')
        : (r.qty + ' ' + (r.unit||'') + ' ＠' + r.price);
}
$('#btnQuoteCsv').on('click', function(){
    var rows = quoteRowsF();
    if (!rows.length){ alert('目前沒有資料可以匯出'); return; }
    csvDown(['判定','報價單號','報價日','客戶','料號','料號備註','製程','數量','單位','單價','金額',
             '訂單張數','訂單單號','出貨張數','出貨單號','發現的問題'],
        rows.map(function(r){
            return [r.level==='critical'?'嚴重':(r.level==='warn'?'提醒':'正常'),
                r.quote_no, r.qdate, r.client, r.part, r.spec, (r.procs||[]).join('、'),
                r.tiered ? qtyText(r) : r.qty, r.unit, r.price, r.amount,
                r.orders.length, r.orders.map(function(o){ return o.no; }).join('、'),
                r.ships.length,  r.ships.map(function(s){ return s.no; }).join('、'),
                r.issues.map(function(i){ return i.text; }).join('；')];
        }), '報價項目追蹤_' + $('#qFrom').val() + '_' + $('#qTo').val() + '.csv');
});
$('#btnQuotePrint').on('click', function(){
    var rows = quoteRowsF();
    if (!rows.length){ alert('目前沒有資料可以列印'); return; }
    var s = ST.quote.stat;
    printDoc('資料稽核報告－報價項目追蹤（下單／出貨／收款）',
        '稽核期間（報價日期）：' + $('#qFrom').val() + ' ~ ' + $('#qTo').val()
            + (($('#qClient').val())?('　客戶：' + $('#qClient').val()):'')
            + (($('#qPart').val())?('　料號：' + $('#qPart').val()):''),
        '掃描報價項目 ' + (ST.quote.scanned||0) + ' 列；嚴重 ' + s.critical + ' 筆、提醒 ' + s.warn + ' 筆、正常 ' + s.ok + ' 筆',
        [['序號','5%'],['判定','6%'],['報價單號／日期','13%'],['客戶','9%'],['料號','13%'],
         ['數量／單價','11%'],['訂單','12%'],['出貨','12%'],['發現的問題','']],
        rows.map(function(r, i){
            return [i+1, (r.level==='critical'?'嚴重':(r.level==='warn'?'提醒':'正常')),
                r.quote_no + ' / ' + r.qdate, r.client,
                r.part + (r.spec ? ('（' + r.spec + '）') : ''),
                qtyText(r),
                r.orders.length ? r.orders.map(function(o){ return o.no + ' ' + o.date; }).join('　') : '無訂單',
                r.ships.length ? r.ships.map(function(x){ return x.no + ' ' + x.date; }).join('　') : (r.orders.length ? '尚未出貨' : '—'),
                r.issues.map(function(x){ return x.text; }).join('；')];
        }));
});

/* ══ 例外 ══ */
$(document).on('click','[data-ex-quote]', function(){
    ST.ex = {scope:'quote', key:String($(this).data('ex-quote')), item:String($(this).data('code'))};
    var it = ((ST.quote.items||{})[ST.ex.item]||[ST.ex.item])[0];
    $('#exWhat').text('報價項目 #' + ST.ex.key + '　項目：' + it);
    $('#exReason').val(''); openMask('exMask');
});
$(document).on('click','[data-exdel-quote]', function(){
    if(!confirm('取消這個例外之後，這一項會重新納入稽核。確定嗎？')) return;
    $.post(API, {action:'exempt_del', csrf:CSRF, scope:'quote',
                 key:String($(this).data('exdel-quote')), item:String($(this).data('code'))},
        function(r){ if(r && r.ok) $('#btnQuoteRun').click(); });
});
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
        if (ST.ex.scope === 'trace')      $('#btnTraceRun').click();
        else if (ST.ex.scope === 'quote') $('#btnQuoteRun').click();
        else                              $('#btnMasterRun').click();
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

/* ══ 排除設定 ══ */
var EXCL = {dims:{}, tabs:{}, items:{}, rows:[], sugTimer:null, sugSeq:0};

$('#btnExcl').on('click', function(){ loadExcl(true); });

function loadExcl(open){
    $.get(API, {action:'excl_list'}, function(r){
        if(!r || !r.ok) return;
        EXCL.dims = r.dims || {}; EXCL.tabs = r.tabs || {};
        EXCL.items = r.items || {}; EXCL.rows = r.rows || [];
        if (open){
            if (!$('#exDim option').length){
                $('#exDim').html(Object.keys(EXCL.dims).map(function(k){
                    return '<option value="' + esc(k) + '">' + esc(EXCL.dims[k].label) + '</option>';
                }).join(''));
            }
            renderExclForm();
            openMask('exclMask');
        }
        renderExclList();
    });
}

/* 維度換了，能套用的分頁跟著換（客戶／廠商／料號各自適用的分頁不同） */
function renderExclForm(){
    var dim = $('#exDim').val(), d = EXCL.dims[dim] || {tabs:[]};
    $('#exTabs').html((d.tabs||[]).map(function(t){
        return '<label class="ex-chk"><input type="checkbox" class="ex-tab" value="' + esc(t) + '" checked> '
             + esc(EXCL.tabs[t] || t) + '</label>';
    }).join('') || '<span class="muted-help">這個維度沒有可套用的分頁</span>');
    var hasTrace = (d.tabs||[]).indexOf('trace') >= 0;
    $('#exItemsBox').toggle(hasTrace);
    if (hasTrace){
        $('#exItems').html(Object.keys(EXCL.items).map(function(c){
            return '<label class="ex-chk"><input type="checkbox" class="ex-item" value="' + esc(c) + '"> '
                 + esc(EXCL.items[c][0]) + '</label>';
        }).join(''));
    }
    $('#exVal').val('').attr('data-eg-hint',
        dim==='client' ? '打客戶簡稱或編號，例如 和大 或 C2005'
      : dim==='maker'  ? '打廠商簡稱或編號'
                       : '打料號，例如 RC105-N03-A');
    $('#exSug').hide();
    $('#exMsg').text('');
}
$('#exDim').on('change', renderExclForm);

/* 對象一定要能用挑的：打錯一個字這條規則就永遠不會命中，而且完全不報錯 */
$('#exVal').on('input', function(){
    var kw = $(this).val().trim();
    clearTimeout(EXCL.sugTimer);
    if (kw.length < 1){ $('#exSug').hide(); return; }
    var seq = ++EXCL.sugSeq;
    EXCL.sugTimer = setTimeout(function(){
        $.get(API, {action:'excl_search', dim:$('#exDim').val(), kw:kw}, function(r){
            if(!r || !r.ok || seq !== EXCL.sugSeq) return;
            var rows = r.rows || [];
            if(!rows.length){ $('#exSug').html('<div class="muted-help">主檔查不到，確認名稱後可直接使用輸入的值</div>').show(); return; }
            $('#exSug').html(rows.map(function(x){
                return '<div data-v="' + esc(x.val) + '">' + esc(x.val)
                     + (x.code ? ' <span class="muted-help">' + esc(x.code) + '</span>' : '') + '</div>';
            }).join('')).show();
        });
    }, 220);
});
$(document).on('click', '#exSug div[data-v]', function(){
    $('#exVal').val(String($(this).data('v'))); $('#exSug').hide();
});
$(document).on('click', function(e){
    if (!$(e.target).closest('.ex-pick').length) $('#exSug').hide();
});

$('#btnExclAdd').on('click', function(){
    var val = $('#exVal').val().trim();
    if (!val){ $('#exMsg').css('color','#DD5138').text('請先指定要排除的對象'); $('#exVal').focus(); return; }
    var tabs = $('.ex-tab:checked').map(function(){ return this.value; }).get();
    if (!tabs.length){ $('#exMsg').css('color','#DD5138').text('請至少勾選一個要套用的分頁'); return; }
    var items = $('.ex-item:checked').map(function(){ return this.value; }).get();
    $.post(API, {action:'excl_save', csrf:CSRF, dim:$('#exDim').val(), val:val,
                 tabs:JSON.stringify(tabs), items:JSON.stringify(items),
                 reason:$('#exReasonNew').val()}, function(r){
        if(!r || !r.ok) return;
        EXCL.rows = r.rows || []; renderExclList();
        $('#exVal').val(''); $('#exReasonNew').val('');
        $('#exMsg').css('color','#5C8A4A').text(r.msg || '已儲存');
        refreshAfterExcl();
    });
});

function renderExclList(){
    var rows = EXCL.rows || [];
    if (!rows.length){
        $('#exclList').html('<p class="muted-help">目前沒有任何排除設定，稽核會列出全部的缺失。</p>');
        return;
    }
    var h = '<table class="ex-tbl"><tr><th style="width:70px">排除什麼</th><th style="width:150px">對象</th>'
          + '<th style="width:180px">套用分頁</th><th>只排除的項目／原因</th>'
          + '<th style="width:110px">建立</th><th style="width:120px">動作</th></tr>';
    rows.forEach(function(r){
        var d = EXCL.dims[r.dim] || {label:r.dim};
        var tabTxt = (r.tab_list||[]).map(function(t){ return EXCL.tabs[t] || t; }).join('、');
        var itemTxt = (r.item_list||[]).length
            ? (r.item_list||[]).map(function(c){ return (EXCL.items[c]||[c])[0]; }).join('、')
            : '<b>整筆不納入稽核</b>';
        h += '<tr' + (r.is_active ? '' : ' class="off"') + '>'
           + '<td>' + esc(d.label) + '</td><td>' + esc(r.val) + '</td>'
           + '<td>' + esc(tabTxt) + '</td>'
           + '<td>' + itemTxt + (r.reason ? '<br><span class="muted-help">' + esc(r.reason) + '</span>' : '') + '</td>'
           + '<td class="muted-help">' + esc(r.created_by_name||'') + '<br>' + dispDate(r.d) + '</td>'
           + '<td>' + (CAN_ADMIN
               ? ('<span class="lnk" data-ex-tog="' + r.id + '" data-on="' + (r.is_active?0:1) + '">'
                  + (r.is_active ? '停用' : '啟用') + '</span>　'
                  + '<span class="lnk" data-ex-rm="' + r.id + '">刪除</span>')
               : '<span class="muted-help">—</span>') + '</td></tr>';
    });
    $('#exclList').html(h + '</table>');
}
$(document).on('click','[data-ex-tog]', function(){
    $.post(API, {action:'excl_toggle', csrf:CSRF, id:$(this).data('ex-tog'), on:$(this).data('on')},
        function(r){ if(r && r.ok){ EXCL.rows = r.rows||[]; renderExclList(); refreshAfterExcl(); } });
});
$(document).on('click','[data-ex-rm]', function(){
    if(!confirm('刪除這條排除設定之後，這個對象會重新納入稽核。確定嗎？')) return;
    $.post(API, {action:'excl_del', csrf:CSRF, id:$(this).data('ex-rm')},
        function(r){ if(r && r.ok){ EXCL.rows = r.rows||[]; renderExclList(); refreshAfterExcl(); } });
});
/* 改了排除設定就把已跑出來的結果重算，否則畫面還是舊的、看起來像沒生效 */
function refreshAfterExcl(){
    if (ST.trace)  $('#btnTraceRun').click();
    if (ST.master) $('#btnMasterRun').click();
}

/* 稽核結果上要講出「這次套用了哪些排除、各排掉幾筆」——不講的話看不出有排除在作用 */
function exclBarHtml(rules){
    rules = (rules||[]).filter(function(x){ return true; });
    if (!rules.length) return '';
    var h = '<div class="excl-bar"><i class="fa fa-ban"></i> 已套用排除設定：';
    h += rules.map(function(x){
        var d = EXCL.dims[x.dim] || {label:x.dim};
        var it = (x.items||[]).length
            ? (x.items||[]).map(function(c){ return (EXCL.items[c]||[c])[0]; }).join('、')
            : '整筆';
        return '<span class="excl-tag">' + esc(d.label) + ' <b>' + esc(x.val) + '</b>（' + esc(it) + '）'
             + (x.hit ? '　排除 ' + x.hit + ' 筆' : '　本期間沒有命中') + '</span>';
    }).join('');
    h += ' <span class="lnk" id="lnkExcl">調整排除設定</span></div>';
    return h;
}
$(document).on('click','#lnkExcl', function(){ loadExcl(true); });

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
       + '　報價超過 <input type="text" id="setValidDays" style="width:60px" value="' + (tol.quote_valid_days||365) + '"> 天未重報視為過期'
       + '　報價出去超過 <input type="text" id="setNoOrderDays" style="width:60px" value="'
       + (tol.no_order_days===undefined?30:tol.no_order_days) + '"> 天還沒有訂單才列出來</div>';

    h += '<h4 style="color:#C77C1A;margin-top:16px">流程稽核：檢核項目</h4>';
    h += '<table class="set-tbl"><tr><th style="width:55%">項目</th><th>等級</th></tr>';
    Object.keys(r.trace_items||{}).forEach(function(k){
        h += '<tr><td>' + esc(r.trace_items[k][0]) + '</td><td>'
           + lvSel('trace_items.' + k, (r.trace_levels||{})[k] || r.trace_items[k][1],
                   [['critical','嚴重'],['warn','提醒'],['off','不檢查']]) + '</td></tr>';
    });
    h += '</table>';
    h += '<div class="muted-help">「出貨未進對帳／未開發票」<b>預設是關的</b>——'
       + '會計模組的發票明細目前 0 筆、對帳底稿只有 84 筆，打開會整片報未收款。等會計上線後再打開。</div>';

    h += '<h4 style="color:#C77C1A;margin-top:16px">報價項目追蹤：檢核項目</h4>';
    h += '<table class="set-tbl"><tr><th style="width:55%">項目</th><th>等級</th></tr>';
    Object.keys(r.quote_items||{}).forEach(function(k){
        h += '<tr><td>' + esc(r.quote_items[k][0]) + '</td><td>'
           + lvSel('quote_items.' + k, (r.quote_levels||{})[k] || r.quote_items[k][1],
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
    var groups = {trace_items:{}, quote_items:{}, fields_customer:{}, fields_maker:{}};
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
            quote_valid_days: parseInt($('#setValidDays').val(),10) || 0,
            no_order_days: parseInt($('#setNoOrderDays').val(),10) || 0
        }),
        trace_items: JSON.stringify(groups.trace_items),
        quote_items: JSON.stringify(groups.quote_items),
        fields_customer: JSON.stringify(groups.fields_customer),
        fields_maker: JSON.stringify(groups.fields_maker)
    }, function(r){
        if(!r || !r.ok) return;
        alert(r.msg || '已儲存');
        closeMask('setMask');
        if (ST.trace)  $('#btnTraceRun').click();
        if (ST.master) $('#btnMasterRun').click();
        if (ST.quote)  $('#btnQuoteRun').click();
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
    csvDown(['判定','訂單編號','訂單日期','客戶','料號','訂單數量','訂單單價','訂單製程','訂單備註',
             '報價單號','報價日','報價單價','報價製程','報價備註','報價來源',
             '製令張數','製令開立日','製令數量','製令製程','製令來源',
             '出貨張數','出貨首日','出貨數量','出貨單價','出貨製程／內容','出貨備註','出貨來源',
             '製令編號','出貨單號','發現的問題'],
        rows.map(function(r){
            var sp = shipPrices(r.ship.list), bp = [];
            (r.bom.list||[]).forEach(function(x){ (x.procs||[]).forEach(function(n){
                if (n && bp.indexOf(n) < 0) bp.push(n); }); });
            var uniq = function(a){ var o = []; (a||[]).forEach(function(v){
                v = String(v==null?'':v).replace(/\s+/g, ' ').trim();
                if (v && o.indexOf(v) < 0) o.push(v); }); return o.join('；'); };
            return [r.level==='critical'?'嚴重':(r.level==='warn'?'提醒':'正常'), r.order_no, r.odate, r.client, r.part,
                r.oqty, r.oprice, r.oproc||'', uniq([r.ospec, r.ops]),
                r.quote?r.quote.no:'', r.quote?r.quote.date:'', r.quote?r.quote.price:'',
                r.quote?(r.quote.procs||[]).join('、'):'', r.quote?(r.quote.spec||''):'', r.quote?r.quote.src:'',
                r.bom.cnt, r.bom.date, r.bom.qty, bp.join('→'), r.bom.src,
                (r.ship.doc_cnt||r.ship.cnt), r.ship.date, r.ship.qty,
                (sp.list.join('／') + (sp.none ? (sp.list.length?'（'+sp.none+' 張未開價）':'未開價') : '')),
                uniq((r.ship.list||[]).map(function(x){ return x.content; })),
                uniq((r.ship.list||[]).map(function(x){ return x.note; })), r.ship.src,
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
    // 分頁三（報價項目追蹤）與分頁一是同一個稽核對象（流程），只有分頁二是主檔那一組
    var scope = (ST.settings ? (ST.tab==='master' ? ST.settings.scope_master : ST.settings.scope_trace) : []) || [];
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
                r.quote ? (r.quote.date + ' ' + r.quote.no
                           + ((r.quote.procs||[]).length ? ('　' + r.quote.procs.join('、')) : '')) : '無',
                r.bom.cnt ? (r.bom.date + ' ' + (r.bom.list||[]).map(function(x){
                                 return x.no + ((x.procs||[]).length ? ('（' + x.procs.join('→') + '）') : ''); }).join(' '))
                          : (r.auto_pm ? '自動轉生管' : '無'),
                r.ship.cnt ? (r.ship.date + ' ' + (r.ship.list||[]).map(function(x){
                                 return x.no + '＠' + ((+x.price>0) ? x.price : '未開價')
                                      + (x.content ? ('（' + x.content + '）') : ''); }).join(' '))
                           : '未出貨',
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


/* ════════════════════════════════════════════════════════════════════
 * 資料稽核 → 不符合通知單（2026-09-21 使用者交辦）
 *
 * 使用者原話：「稽核要查詢訂單資料是否有缺失，無報價單、出貨後才開立訂單…這種，
 * 已經有做 data_audit.php 來檢核，但還是很難查」＝查得出缺失卻沒辦法直接變成 IA 單，
 * 只能把整份結果看完再到內稽那邊從頭手打，所以一直開不出不符合通知單。
 *
 * 【最小單位是「一條缺失」不是「一張訂單」】同一張訂單可能同時有「查不到報價單」與
 * 「出貨早於訂單」，那是兩個不同的不合格事實、要開兩張 IA 單、甚至可能是兩個不同的
 * 受稽單位，所以勾選框掛在每一條缺失上。防重複的鍵也是「訂單＋檢核項目」。
 *
 * 寫入一律走內稽自己的 nc_create（IA 編號、通知、歷程都在那邊），這裡不另開端點。
 * ════════════════════════════════════════════════════════════════════ */

function ncKey(oid, code){ return oid + '|' + code; }
function ncExisting(oid, code){ return ((NCB && NCB.existing && NCB.existing[oid]) || {})[code] || null; }

/** 每一條缺失右邊那一小段：已開單就顯示單號，還沒開就給勾選框與「開IA單」 */
function ncCellHtml(r, i){
    if (!CAN_NC || !NCB) return '';
    var ex = ncExisting(r.order_id, i.code);
    if (ex) {
        return ' <span class="nc-done">已開單 <a href="internal_audit.php" target="_blank" rel="noopener"'
             + ' title="到內部稽核的「不符合通知單」分頁查 ' + esc(ex.nc_no) + '">' + esc(ex.nc_no) + '</a>（'
             + esc(ex.stage_label || '') + '）</span>';
    }
    var k = ncKey(r.order_id, i.code);
    return ' <label style="font-weight:normal;margin:0" title="勾選後可一次開立多張">'
         + '<input type="checkbox" class="nc-pick" data-eg-skip data-k="' + esc(k) + '"'
         + (NCSEL[k] ? ' checked' : '') + '></label>'
         + '<span class="nc-go" data-k="' + esc(k) + '">開IA單</span>';
}

/** 勾選狀態要能在換頁／換篩選之後留著，所以存的是「這一筆缺失的完整資料」不是 DOM 狀態 */
function ncFindIssue(k){
    var p = String(k).split('|'), oid = +p[0], code = p[1];
    var rows = (ST.trace && ST.trace.rows) || [];
    for (var a = 0; a < rows.length; a++) {
        if (+rows[a].order_id !== oid) continue;
        for (var b = 0; b < rows[a].issues.length; b++) {
            if (rows[a].issues[b].code !== code) continue;
            return {oid:oid, code:code, order_no:rows[a].order_no, client:rows[a].client,
                    part:rows[a].part, odate:rows[a].odate, detail:rows[a].issues[b].text,
                    level:rows[a].issues[b].level};
        }
    }
    return null;
}
function ncSyncBar(){
    var n = Object.keys(NCSEL).length;
    $('#ncPickN').text(n);
    $('#btnNcBulk').prop('disabled', n === 0);
}
$(document).on('change', '.nc-pick', function(){
    var k = String($(this).data('k') || '');
    if (this.checked) { var it = ncFindIssue(k); if (it) NCSEL[k] = it; }
    else delete NCSEL[k];
    ncSyncBar();
});
$(document).on('click', '.nc-go', function(){
    var it = ncFindIssue(String($(this).data('k') || ''));
    if (it) openNcModal([it]);               // 單筆：只開這一條，不動已經勾好的那些
});
$('#btnNcBulk').on('click', function(){
    var list = Object.keys(NCSEL).map(function(k){ return NCSEL[k]; }).filter(Boolean);
    if (!list.length) return;
    if (list.length > 50) { alert('一次最多開 50 張，請分批處理（目前勾了 ' + list.length + ' 筆）。'); return; }
    openNcModal(list);
});

function loadNcMeta(cb){
    if (!CAN_NC) { if (cb) cb(); return; }
    var oids = ((ST.trace && ST.trace.rows) || []).map(function(r){ return r.order_id; });
    // 一定要用 POST：稽核結果動輒一兩千張訂單，訂單編號接成查詢字串就超過 8KB，
    // Apache 會直接回 414（Request-URI Too Long），而且是整頁都還沒畫出來就先跳錯（2026-09-21 使用者回報）
    $.post(IA_API, {action:'dqa_prefill', order_ids:oids.join(',')}, function(r){
        if (r && r.ok) NCB = r;
        if (cb) cb();
    }, 'json').fail(function(){ if (cb) cb(); });    // 內稽 API 掛了不可以把資料稽核整頁拖下水
}

var NCLIST = [];
function openNcModal(list){
    if (!NCB) { alert('內部稽核資料還在載入，請稍候再試。'); return; }
    NCLIST = list.filter(function(x){ return !ncExisting(x.oid, x.code); });   // 已開過的不再列
    if (!NCLIST.length) { alert('選取的缺失都已經開過不符合通知單了。'); return; }

    var th = '';
    $.each(NCB.nc_types || {}, function(k, v){ th += '<option value="' + esc(k) + '">' + esc(v) + '</option>'; });
    $('#ncType').html(th);
    $('#ncDate').val(NCB.today || '');
    $('#ncDue').val('');

    var miss = 0;
    var body = NCLIST.map(function(x, idx){
        var b = (NCB.bundles || {})[x.code] || {};
        var label = ((NCB.items || {})[x.code] || [x.code])[0];
        if (!+b.dept_id) miss++;
        var uh = '<option value="">（請選擇）</option>';
        (NCB.units || []).forEach(function(u){
            uh += '<option value="' + (+u.key) + '"' + ((+u.key === +b.dept_id) ? ' selected' : '') + '>'
                + esc(u.name || u.unit_name || '') + '</option>';
        });
        return '<tr><td>' + esc(label) + '</td>'
             + '<td>' + esc(x.order_no || '') + '<br><em class="muted-help">' + esc(x.client || '') + '<br>'
                      + esc(x.part || '') + '</em></td>'
             + '<td><select class="nc-unit" data-i="' + idx + '" data-eg-filter="輸入單位名稱篩選…" style="width:100%">'
             + uh + '</select><div class="muted-help">' + (b.doc_no
                   ? (esc(b.doc_no) + ' ' + esc(b.doc_name || '') + (b.mapped ? '' : '（未設定對照，暫用稽核對象）'))
                   : '<span style="color:#B23A2A">沒有對應的 AS 文件，請自行選單位</span>') + '</div></td>'
             + '<td><textarea class="nc-fact" data-i="' + idx + '" rows="2" style="width:100%;font-size:12px">'
             + esc(ncFactText(x)) + '</textarea></td></tr>';
    }).join('');
    $('#ncTbl tbody').html(body);
    $('#ncWarn').html(miss
        ? ('有 <b>' + miss + '</b> 筆推導不出受稽核單位，請逐筆選擇；'
           + (CAN_NC_MAP ? '或先到上方「不符合通知單對照」把檢核項目對應到 AS 文件，往後就會自動帶入。'
                         : '或請內稽管理員設定「不符合通知單對照」。'))
        : '');
    openMask('ncMask');
}
/* 不合格事實的預設文字。前端先組一份讓使用者當場看得到並可修改。 */
function ncFactText(x){
    var label = ((NCB.items || {})[x.code] || [x.code])[0];
    return '訂單 ' + (x.order_no || '') + '（客戶 ' + (x.client || '') + '、料號 ' + (x.part || '')
         + '、訂單日 ' + dispDate(x.odate) + '）：' + (x.detail || label);
}

$('#btnNcGo').on('click', function(){
    var d = $('#ncDate').val(), type = $('#ncType').val(), due = $('#ncDue').val();
    if (!d)    { alert('請填稽核日期'); return; }
    if (!type) { alert('請選擇不合格類型'); return; }
    if (due && due < d) { alert('要求完成期限不可早於稽核日期'); return; }
    var jobs = [], bad = 0;
    NCLIST.forEach(function(x, i){
        var dept = $('.nc-unit[data-i="' + i + '"]').val();
        if (!dept) { bad++; return; }
        var b = (NCB.bundles || {})[x.code] || {};
        jobs.push({action:'nc_create', audit_date:d, nc_type:type, due_date:due,
                   dept_id:dept, fact:$('.nc-fact[data-i="' + i + '"]').val(),
                   ref_form_no:b.doc_no || '', ref_form_name:b.doc_name || '',
                   clause_ref:(b.clauses || []).map(function(c){ return c.clause_text; }).join('\n'),
                   src_kind:'dqa', src_item_id:x.oid, src_code:x.code});
    });
    if (bad) { alert('還有 ' + bad + ' 筆沒有選受稽核單位。'); return; }
    if (!jobs.length) return;
    if (!confirm('要開立 ' + jobs.length + ' 張不符合通知單嗎？開立後會立即通知各受稽核單位主管。')) return;

    var $btn = $(this).prop('disabled', true).text('開立中…');
    var okN = 0, errs = [];
    (function next(){
        if (!jobs.length) {
            $btn.prop('disabled', false).text('開立並通知');
            closeMask('ncMask');
            alert('完成：已開立 ' + okN + ' 張不符合通知單'
                  + (errs.length ? ('\n未開立 ' + errs.length + ' 張：\n' + errs.join('\n')) : ''));
            NCSEL = {}; ncSyncBar();
            loadNcMeta(function(){ renderTrace(); });
            return;
        }
        var j = jobs.shift();
        $.post(IA_API, j, function(r){
            if (r && r.ok) okN++; else errs.push('・' + (r && r.error ? r.error : '失敗'));
        }, 'json').fail(function(x){
            errs.push('・' + (((x.responseJSON || {}).error) || ('HTTP ' + x.status)));
        }).always(next);
    })();
});

/* ── 檢核項目 → AS 文件 對照（內稽管理員） ───────────────────────── */
$('#btnNcMap').on('click', function(){
    $.getJSON(IA_API, {action:'dqa_map_get'}, function(r){
        if (!r || !r.ok) return;
        var h = '';
        $.each(r.items || {}, function(code, it){
            var cur = +((r.map || {})[code] || 0);
            var docH = '<option value="">（未設定）</option>';
            (r.docs || []).forEach(function(d){
                docH += '<option value="' + (+d.id) + '"' + ((+d.id === cur) ? ' selected' : '') + '>'
                      + esc(d.doc_no + ' ' + d.doc_name) + '</option>';
            });
            h += '<div class="nc-map-row"><label>' + esc(it[0]) + '</label>'
               + '<select class="nc-map-sel" data-code="' + esc(code) + '" data-eg-filter="輸入編號或名稱篩選…">'
               + docH + '</select>'
               + '<span class="to" data-code="' + esc(code) + '">' + ncMapTo((r.bundles || {})[code]) + '</span></div>';
        });
        $('#ncMapBody').html(h
            + '<p class="muted-help" style="margin-top:8px">「稽核對象」目前綁定：'
            + ((r.scope || []).map(function(d){ return esc(d.doc_no + ' ' + d.doc_name); }).join('、') || '（尚未綁定）')
            + '</p>');
        openMask('ncMapMask');
    });
});
function ncMapTo(b){
    if (!b || !b.doc_no) return '<span style="color:#B23A2A">推導不出受稽核單位</span>';
    return '→ ' + esc(b.dept_name || '（此文件編號對不到部門）')
         + ((b.clauses || []).length ? ('、條文 ' + b.clauses.length + ' 條') : '、無對應條文');
}
$('#btnNcMapSave').on('click', function(){
    var map = {};
    $('.nc-map-sel').each(function(){
        var v = +$(this).val(); if (v) map[String($(this).data('code'))] = v;
    });
    $.post(IA_API, {action:'dqa_map_save', map:JSON.stringify(map)}, function(r){
        if (!r || !r.ok) return;
        $('.nc-map-sel').each(function(){
            var code = String($(this).data('code'));
            $('.nc-map-row .to[data-code="' + code + '"]').html(ncMapTo((r.bundles || {})[code]));
        });
        alert('已儲存對照。');
        loadNcMeta(function(){ renderTrace(); });
    }, 'json');
});

<?php if ($perms['canView']): ?>
loadYears();
loadSettings();
loadExcl(false);
<?php endif; ?>
</script>
</body>
</html>
