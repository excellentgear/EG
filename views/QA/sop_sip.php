<?php
/**
 * sop_sip.php — 作業標準書(SOP)／標準檢驗指導書(SIP)
 * 建立：2026-09-21
 *
 * 一頁兩分頁：SOP（生產／技術課為主）與 SIP（品管課為主）。三種版面照紙本：
 *   設備操作說明書 3-TD-02-01（機台）／製造製程說明書 3-TD-02-02（通用或料號）／
 *   標準檢驗指導書 2-QA-02-01（通用或料號）
 * 規則一律在 src/common/sopsip_lib.php，本頁只負責畫面。
 */
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/QA/sop_sip.php";
    header("Location:../../index.php");
    exit;
}
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/sopsip_lib.php';
include_once '../../src/common/asdoc_lib.php';

$db = (new DBConnection())->getPDO();
ss_ensure_schema($db);
$uid = (int)($_SESSION['id'] ?? 0);
$P   = ss_perms($db, $uid);
if (empty($_SESSION['ss_csrf'])) $_SESSION['ss_csrf'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['ss_csrf'];

$roleLabel = $P['isAdmin'] ? '系統管理者' : ($P['canAdmin'] ? 'SOP／SIP 管理員'
           : (($P['canEditSop'] || $P['canEditSip']) ? '填寫／簽核'
           : ($P['canView'] ? '檢閱' : '無權限')));
$years = [];
try {
    foreach ($db->query("SELECT DISTINCT YEAR(form_date) y FROM ss_ver WHERE form_date IS NOT NULL ORDER BY y DESC")
                ->fetchAll(PDO::FETCH_COLUMN) as $y) if ($y) $years[] = (int)$y;
} catch (Throwable $e) {}
$thisYear = (int)date('Y');
if (!in_array($thisYear, $years, true)) array_unshift($years, $thisYear);
$KINDS = ss_kinds();
$SCOPES = ss_scopes();
$SLOTS = ss_slots();
$STATUSES = ss_statuses();
// 每個版面允許哪些適用範圍，一律由 ss_kind_scopes() 決定；前端不要再寫死一份
$KIND_SCOPES = [];
foreach (array_keys($KINDS) as $k) $KIND_SCOPES[$k] = ss_kind_scopes($k);
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>作業標準書 SOP／標準檢驗指導書 SIP</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <style>
        #sidebar-menu { visibility: hidden; }
        :root{ --ink:#4A3524; --ink2:#6B4423; --cream:#FCF7F0; --sand:#F7E0BD; --amber:#F0A24B;
               --amber-d:#C77C1A; --coral:#DD5138; --line:#E4D3BC; }
        body { background:#F6F1EA; }
        .right_col .page-title { margin:8px 0 6px; overflow:hidden; clear:both; }
        .page-title h3 { color:var(--ink); margin:0; display:flex; align-items:center; gap:10px; flex-wrap:wrap; font-size:20px; }
        .page-help-btn { height:30px; font-size:13px; padding:0 12px; border:1px solid var(--amber-d);
                         border-radius:15px; background:#fff; color:var(--amber-d); margin-left:auto; }
        .page-help-btn:hover { background:var(--amber-d); color:#fff; }
        @media print { .page-help-btn, .ss-toolbar, .ss-tabs { display:none !important; } }
        .as-tag,.role-tag { font-size:12px; background:var(--sand); color:var(--ink2); border-radius:10px; padding:2px 10px; font-weight:normal; }
        .role-tag { background:#EFE3CF; }
        .btn-warm { background:var(--amber); border:1px solid var(--amber-d); color:var(--ink); font-weight:bold; }
        .btn-warm:hover,.btn-warm:focus { background:var(--amber-d); color:#fff; }
        .btn-warm-o { background:#fff; border:1px solid var(--amber-d); color:var(--amber-d); }
        .btn-warm-o:hover { background:var(--sand); color:var(--ink); }
        .warm-panel { background:#fff; border:1px solid var(--line); border-radius:8px; padding:10px 12px; margin-bottom:10px; }
        .ss-tabs { display:flex; gap:6px; margin-bottom:10px; }
        .ss-tab { border:1px solid var(--line); border-bottom:none; background:#EFE3CF; color:var(--ink2);
                  padding:7px 20px; border-radius:7px 7px 0 0; cursor:pointer; font-size:14px; }
        .ss-tab.on { background:#fff; color:var(--ink); font-weight:bold; border-color:var(--amber-d); }
        .ss-toolbar { display:flex; gap:8px; align-items:flex-end; flex-wrap:wrap; }
        .fg label { display:block; font-size:12px; color:#8a7560; margin-bottom:2px; }
        .fg input, .fg select { border:1px solid var(--line); border-radius:4px; padding:3px 6px; font-size:13px; height:30px; }
        .muted-help { font-size:12px; color:#8a7560; }
        .note-box { font-size:12px; color:var(--ink2); background:var(--cream); border:1px solid var(--line);
                    border-radius:6px; padding:7px 10px; margin-bottom:8px; line-height:1.7; }
        table.lst { width:100%; border-collapse:collapse; font-size:12.5px; background:#fff; table-layout:fixed; }
        table.lst th, table.lst td { border:1px solid var(--line); padding:4px 6px; vertical-align:top;
                                     overflow-wrap:break-word; word-break:break-word; }
        table.lst thead th { background:var(--sand); color:var(--ink2); text-align:center; position:sticky; top:0; z-index:2; }
        table.lst td.c { text-align:center; }
        table.lst tbody tr:hover { background:#FFFDF8; cursor:pointer; }
        /* 刻意不設 max-height：設了就會在表格裡自己長出一條上下捲軸，
           每頁只有 8 筆時那條捲軸完全是多餘的（使用者 2026-09-21 指定）。
           橫向留 auto 當保險，table-layout:fixed 正常情況下不會超出。 */
        .lst-wrap { overflow-x:auto; border:1px solid var(--line); border-radius:6px; }
        /* 表格裡的小籤一定要自己指定 line-height：Gentelella 全站 td span{line-height:28px}，
           10px 的字會佔掉 28px 把整列撐高（本專案已踩三次） */
        .st { border-radius:11px; padding:1px 9px; font-size:11.5px; display:inline-block; line-height:18px; white-space:nowrap; }
        .st-draft { background:#EFE3CF; color:var(--ink2); }
        .st-submitted { background:var(--sand); color:var(--ink2); }
        .st-approved { background:#D8EBD4; color:#3C6B37; }
        .st-obsolete { background:#E8E0D8; color:#9A8A7A; text-decoration:line-through; }
        .pager { display:flex; gap:4px; align-items:center; justify-content:flex-end; margin:6px 0; font-size:12px; }
        .pager button { border:1px solid var(--line); background:#fff; border-radius:4px; height:26px; padding:0 9px; font-size:12px; }
        .pager button.on { background:var(--amber); border-color:var(--amber-d); font-weight:bold; }
        /* 跳窗：寬度一律固定像素，禁用 vw（會蓋過側邊選單） */
        .ss-mask { display:none; position:fixed; inset:0; background:rgba(60,42,25,.45); z-index:10200; }
        .ss-mask.on { display:flex; align-items:flex-start; justify-content:center; }
        .ss-modal { background:#fff; border-radius:9px; margin:24px 0; max-width:96%; display:flex; flex-direction:column;
                    max-height:92vh; box-shadow:0 10px 40px rgba(0,0,0,.3); }
        .m-head { padding:10px 14px; border-bottom:1px solid var(--line); display:flex; align-items:center; gap:10px;
                  color:var(--ink); font-weight:bold; font-size:15px; }
        .m-head .x { margin-left:auto; cursor:pointer; color:#9A8A7A; font-size:18px; background:none; border:none; }
        .m-body { padding:12px 14px; overflow:auto; }
        .m-foot { padding:9px 14px; border-top:1px solid var(--line); display:flex; gap:8px; align-items:center; }
        .m-foot .sp { margin-left:auto; }
        .frm { display:grid; grid-template-columns:110px 1fr 110px 1fr; gap:7px 10px; align-items:center; font-size:13px; }
        .frm label { color:var(--ink2); font-size:12.5px; margin:0; text-align:right; }
        .frm input, .frm select, .frm textarea { width:100%; border:1px solid var(--line); border-radius:4px;
                     padding:3px 7px; font-size:13px; color:var(--ink); }
        .frm input[readonly], .frm .ro-auto { background:#F4EEE6; color:#7A6650; }
        .frm .wide { grid-column:2 / span 3; }
        .frm .full { grid-column:1 / span 4; }
        .frm textarea { min-height:70px; line-height:1.6; }
        /* 客戶名稱置中（使用者 2026-09-22 指定，畫面與列印版一致） */
        .frm input.ta-c { text-align:center; }
        /* 綁定狀態小籤：真的綁到主檔才打勾並印出編號——打了字沒從清單挑不算綁定，
           而那種情況後端會安靜地不存（客戶）或直接擋下（製程），畫面上看不出來最傷。
           小籤在表格外，但一律自己指定 line-height（td span 的全站行高坑，記憶 td_span_line_height_trap）。 */
        .bt { display:inline-block; line-height:16px; font-size:11.5px; padding:1px 6px; border-radius:9px;
              white-space:nowrap; vertical-align:middle; }
        .bt-ok { background:#F3E3C6; color:#6B4A18; border:1px solid #D8B579; }
        .bt-no { background:#FBE0D6; color:#9C3312; border:1px solid #E8A88C; }
        .bindline { display:flex; align-items:center; gap:6px; }
        .bindline > input { flex:1; min-width:0; }
        .bindline > .ac-wrap { flex:1; min-width:0; position:relative; }
        .sec { border:1px solid var(--line); border-radius:7px; padding:9px 11px; margin-bottom:10px; background:#FFFDF8; }
        .sec h5 { margin:0 0 8px; font-size:13.5px; color:var(--ink2); display:flex; align-items:center; gap:8px; }
        table.grid { width:100%; border-collapse:collapse; font-size:12.5px; }
        table.grid th, table.grid td { border:1px solid var(--line); padding:3px 5px; vertical-align:top; }
        /* 表頭一律不換行：欄位被擠到剩幾個像素時，症狀就是表頭變成直書一個字一行。
           不給它換行，欄位被壓扁的時候會直接把表格撐出捲軸，看得出來而不是默默變形。 */
        table.grid th { white-space:nowrap; }
        /* 明細表格一律包一層可橫向捲動的容器：欄位多的時候寧可捲，也不要把某一欄壓成 0 */
        .gridwrap { overflow-x:auto; }
        table.grid thead th { background:var(--sand); color:var(--ink2); text-align:center; font-size:12px; }
        table.grid input, table.grid textarea, table.grid select {
            width:100%; border:1px solid transparent; background:transparent; font-size:12.5px; padding:2px 3px; }
        table.grid input:focus, table.grid textarea:focus { border-color:var(--amber-d); background:#fff; }
        .err { color:var(--coral); font-size:12px; }
        /* ── 明細表格的拖曳排序把手（使用者 2026-09-22 要求）──
           ☰ 一律走 ::before：序號那一格的文字由 renumber() 用 .text() 重寫，
           寫進 HTML 的 ☰ 會在下一次重新編號時被整個洗掉。 */
        table.grid td.dragh { cursor:grab; user-select:none; -webkit-user-select:none; white-space:nowrap; }
        table.grid td.dragh:active { cursor:grabbing; }
        table.grid td.dragh::before { content:'\2630'; color:#C9B79C; margin-right:3px; font-size:11px; }
        table.grid tr.ss-ghost { opacity:.45; background:#FFF3E2; }
        /* 右下角小提示（自動刪除空殼文件之類的，不用按確定的那種） */
        #ssToast { position:fixed; right:18px; bottom:18px; z-index:10600; background:rgba(74,53,36,.94); color:#fff;
                   padding:9px 14px; border-radius:6px; font-size:13px; max-width:420px; line-height:1.6;
                   box-shadow:0 6px 20px rgba(0,0,0,.3); opacity:0; transform:translateY(8px);
                   transition:opacity .18s, transform .18s; pointer-events:none; }
        #ssToast.on { opacity:1; transform:translateY(0); }
        /* ── 圖面放大檢視 ── */
        #maskImg { z-index:10500; }
        .ss-imgwin { width:1120px; }
        #imgBody { padding:0; background:#3A3027; overflow:auto; cursor:grab;
                   height:calc(92vh - 118px); text-align:center; }
        #imgBody.grabbing { cursor:grabbing; }
        /* 放大時是用 inline width 指定的，max-width 一定要是 none，否則會被 100% 夾住＝按了放大沒反應。
           「符合視窗」才掛 .fit 讓整張圖縮進畫面。 */
        #imgBody img { display:block; margin:0 auto; max-width:none; }
        #imgBody img.fit { max-width:100%; max-height:calc(92vh - 126px); width:auto; }
        #imgHead .btn-xs { height:24px; padding:1px 8px; font-size:12px; line-height:20px; }
        /* 釘在旁邊：遮罩不擋事件（pointer-events:none），底下的文件跳窗照樣可以打字 */
        #maskImg.pin { display:block; background:transparent; pointer-events:none; }
        #maskImg.pin .ss-modal { pointer-events:auto; position:fixed; width:620px; margin:0;
                                 max-height:72vh; border:1px solid var(--amber-d); box-shadow:0 10px 34px rgba(0,0,0,.38); }
        #maskImg.pin #imgHead { cursor:move; }
        #maskImg.pin #imgBody { height:calc(72vh - 118px); }
        #maskImg.pin #imgBody img.fit { max-height:calc(72vh - 126px); }
        .thumb { max-width:120px; max-height:70px; border:1px solid var(--line); border-radius:4px; cursor:pointer; }
        .sign-row { display:flex; gap:10px; flex-wrap:wrap; }
        .sign-box { flex:1 1 200px; border:1px solid var(--line); border-radius:6px; padding:7px 9px; background:#fff; min-width:200px; }
        .sign-box .t { font-size:12px; color:var(--ink2); margin-bottom:4px; }
        .sign-box .who { font-size:12.5px; color:var(--ink); }
        .ac-wrap { position:relative; }
        .ac-list { position:fixed; z-index:10400; background:#fff; border:1px solid var(--line); border-radius:5px;
                   max-height:230px; overflow:auto; box-shadow:0 6px 18px rgba(0,0,0,.17); display:none; min-width:260px; }
        .ac-list .it { padding:5px 9px; font-size:12.5px; cursor:pointer; border-bottom:1px solid #F3E9D6; }
        .ac-list .it:hover { background:var(--sand); }
        .ac-list .hit { font-weight:bold; color:var(--amber-d); }

        /* ── 2026-09-21（二次）新增那一批 ── */
        /* 機器編號勾選盒：同型號好幾台，一台一個勾選框 */
        .pickbox { border:1px solid var(--line); border-radius:5px; padding:6px 8px; background:#FFFDF9;
                   max-height:140px; overflow:auto; }
        .pickbox label { display:inline-block; font-weight:normal; text-align:left; margin:0 12px 3px 0;
                         font-size:12.5px; color:var(--ink2); cursor:pointer; }
        .pickbox .mno { font-weight:bold; color:var(--amber-d); }
        /* 挑使用設備：比照線上檢驗「選擇本單使用的量具」——① 先點分類 ② 再點設備，兩層都是大按鈕
           （使用者 2026-09-22 指定要跟那一頁一樣清楚；34 台平鋪成一片完全看不出哪台是哪一關的） */
        .eqgrid { display:flex; flex-wrap:wrap; gap:8px; }
        .eqgrid button { min-width:130px; min-height:52px; border:1px solid var(--line); background:#fff; color:var(--ink);
                         border-radius:8px; padding:8px 12px; font-size:15px; font-weight:bold; text-align:center; }
        .eqgrid button:hover { background:var(--sand); border-color:var(--amber-d); }
        .eqgrid button small { display:block; font-weight:normal; font-size:11px; color:#8a6a45; }
        .eqgrid button.eq-cat.has-sel { border-color:var(--amber-d); background:#FFF3E2; }
        .eqgrid button.eq-no.on { background:var(--amber); border-color:var(--amber-d); }
        .eqgrid button.eq-no.on small { color:#6B4A22; }
        /* 已選清單：兩個步驟都看得到，選到哪裡了一目瞭然；選很多支時自己捲，不把按鈕擠下去 */
        #eqPicked { background:var(--cream); border:1px solid var(--line); border-radius:6px; padding:5px 8px;
                    margin-bottom:10px; display:flex; flex-wrap:wrap; gap:4px; align-items:center;
                    min-height:30px; max-height:96px; overflow:auto; }
        #eqPicked > b { flex:0 0 auto; }
        .eq-chip { display:inline-flex; align-items:center; gap:3px; background:#fff; border:1px solid var(--amber-d);
                   border-radius:10px; padding:0 3px 0 8px; font-size:12px; color:var(--ink); line-height:1.7; }
        .eq-chip .c { font-size:11px; font-weight:normal; color:#8a6a45; }
        .eq-chip .x { border:0; background:transparent; color:#C0703A; font-size:13px; line-height:1; padding:0 2px; }
        .eq-chip .x:hover { color:#DD5138; }
        .eq-none { color:#C0703A; font-style:italic; font-size:13px; }
        .eq-sub { font-size:12.5px; color:var(--ink2); margin:0 0 6px; }
        /* 撞到既有文件時的提示（重複一律擋下，只能去更新既有那一份） */
        .dup-box { border:1px solid #E2A15A; background:#FDF3E3; border-radius:5px; padding:8px 10px; font-size:12.5px; }
        .dup-box .t { font-weight:bold; color:#A4541A; margin-bottom:4px; }
        .dup-box .row { padding:3px 0; border-top:1px dashed #E8D5B4; }
        .dup-box .row:first-of-type { border-top:0; }
        /* 段落附件的縮圖（列印時也要看得清楚，所以畫面上就不做太小） */
        .secfiles { display:flex; flex-wrap:wrap; gap:8px; margin-top:6px; }
        .secfile { border:1px solid var(--line); border-radius:5px; padding:4px; background:#fff; text-align:center; width:150px; }
        .secfile img { max-width:100%; max-height:100px; display:block; margin:0 auto 3px; cursor:pointer; }
        .secfile .nm { font-size:11px; color:#8a7560; word-break:break-all; line-height:1.35; }
        .secfile .ops { margin-top:3px; }
        .secfile .ops button { font-size:11px; line-height:1.4; padding:1px 5px; }
        /* 量具兩段式挑選（先類型再編號），與線上檢驗同一種操作方式 */
        .tpick { display:flex; flex-wrap:wrap; gap:6px; }
        .tpick button { font-size:12.5px; }
        .tpick .on { background:var(--amber-d); color:#fff; border-color:var(--amber-d); }
        /* 已停用（或表單日期當時就已停用）的量具：看得到但點不下去，不是安靜地消失 */
        .tpick button.tn-off[disabled] { opacity:.55; }
        /* 鎖定欄位：固定文字直接印出來，只有 {} 的位置給輸入格（使用者 2026-09-23） */
        .lk-row { display:flex; flex-wrap:wrap; align-items:center; gap:2px; }
        .lk-fix { font-size:12.5px; color:var(--ink); line-height:22px; white-space:pre-wrap; }
        .lk-slot { min-width:48px; max-width:110px; flex:0 1 auto; }
        .lk-row .btn { padding:0 5px; line-height:18px; font-size:11px; }
        /* 檢驗項目預設值：每一欄可以各自決定帶進文件之後鎖不鎖 */
        .tpl-lk { display:inline-block; font-weight:normal; font-size:11px; color:var(--ink2);
                  margin:2px 0 0; line-height:16px; }
        .tpl-lk input { margin-right:2px; vertical-align:-1px; }
        .nt-row textarea { font-family:inherit; }
        /* 段落附件放的不是圖片時（Word／Excel／PDF）不要擺一個破圖 */
        .secfile .nofile { display:block; padding:14px 0; color:#9A8A7A; text-decoration:none; font-size:11.5px; }
        .secfile .nofile:hover { color:var(--amber-d); }
        /* 簽核人設定：部門＋職稱兩個下拉並排 */
        .sgcfg select { max-width:150px; display:inline-block; }
        .help-doc { font-size:13px; color:#5b3a1e; line-height:1.75; }
        .help-doc h4 { color:#8A5A2B; border-bottom:2px solid var(--sand); padding-bottom:3px; margin:14px 0 6px; font-size:15px; }
    </style>
</head>
<body class="nav-sm">
<div class="container body"><div class="main_container">
    <?php include '../partPage/sideAndTopBarMenu.html'; ?>

    <div class="right_col" role="main">
        <div class="page-title">
            <h3>
                作業標準書 SOP／標準檢驗指導書 SIP
                <span class="role-tag">目前角色：<?= htmlspecialchars($roleLabel) ?></span>
                <?php if ($P['canAdmin']): ?>
                    <button id="btnSetting" class="btn btn-xs btn-warm-o"><i class="fa fa-cog"></i> 設定</button>
                <?php endif; ?>
                <button id="btnPageHelp" class="page-help-btn"><i class="fa fa-question-circle"></i> 使用說明</button>
            </h3>
        </div>

        <?php if (!$P['canView']): ?>
            <div class="warm-panel">沒有本頁的檢視權限。請洽系統管理者指派 SOP／SIP 相關角色。</div>
        <?php else: ?>

        <div class="ss-tabs">
            <div class="ss-tab on" data-tab="sop">作業標準書 SOP</div>
            <div class="ss-tab" data-tab="sip">標準檢驗指導書 SIP</div>
        </div>

        <div class="warm-panel">
            <div class="ss-toolbar">
                <div class="fg"><label>版面</label>
                    <select id="fKind"><option value="">全部</option></select></div>
                <div class="fg"><label>適用範圍</label>
                    <select id="fScope"><option value="">全部</option>
                        <?php foreach ($SCOPES as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?>
                    </select></div>
                <div class="fg"><label>狀態</label>
                    <select id="fStatus"><option value="">全部</option>
                        <?php foreach ($STATUSES as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?>
                    </select></div>
                <div class="fg"><label>年度（表單日期）</label>
                    <select id="fYear"><option value="">全部</option>
                        <?php foreach ($years as $y): ?><option value="<?= $y ?>"><?= $y ?></option><?php endforeach; ?>
                    </select></div>
                <div class="fg" style="flex:1 1 220px;"><label>關鍵字（料號／機器編號／名稱／製程／客戶）</label>
                    <input type="text" id="fKw" style="width:100%;"></div>
                <div class="fg"><label>&nbsp;</label><button id="btnSearch" class="btn btn-sm btn-warm-o">查詢</button></div>
                <div class="fg"><label>&nbsp;</label><button id="btnNew" class="btn btn-sm btn-warm"><i class="fa fa-plus"></i> 新增</button></div>
            </div>
        </div>

        <div class="note-box" id="listNote"></div>
        <div class="pager" id="pagerTop"></div>
        <div class="lst-wrap">
            <table class="lst" id="tblList">
                <thead><tr>
                    <th style="width:150px;">版面／適用</th>
                    <th style="width:180px;">料號／機器編號</th>
                    <th>文件名稱</th>
                    <th style="width:100px;">製程</th>
                    <th style="width:100px;">客戶</th>
                    <th style="width:70px;">版次</th>
                    <th style="width:100px;">表單日期</th>
                    <th style="width:100px;">狀態</th>
                    <th style="width:70px;">簽核</th>
                    <th style="width:150px;">操作</th>
                </tr></thead>
                <tbody></tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div></div>

<!-- ══════════ 編輯／檢視跳窗 ══════════ -->
<div class="ss-mask" id="maskDoc"><div class="ss-modal" style="width:1180px;">
    <div class="m-head"><span id="docTitle">文件</span>
        <span class="as-tag" id="docAsNo"></span>
        <span class="st" id="docStatus"></span>
        <button class="x" data-close="maskDoc">&times;</button></div>
    <div class="m-body" id="docBody"></div>
    <div class="m-foot" id="docFoot"></div>
</div></div>

<!-- ══════════ 新增跳窗 ══════════ -->
<div class="ss-mask" id="maskNew"><div class="ss-modal" style="width:760px;">
    <div class="m-head">新增文件<span class="as-tag" id="nTabTag"></span><button class="x" data-close="maskNew">&times;</button></div>
    <div class="m-body">
        <div class="frm">
            <label>表單版面 *</label><div class="wide"><select id="nKind"></select></div>
            <label>適用範圍 *</label><div class="wide"><select id="nScope"></select></div>

            <!-- 機台：綁的是型號（同型號好幾台共用一份 SOP），底下勾要涵蓋哪幾台。
                 型號與量具一律走兩層挑選器（先點製程／量具種類，再點項目），與線上檢驗挑量具同一套 -->
            <label id="nModelLab" class="mrow">機台型號 *</label>
            <div class="wide mrow">
                <div id="nModelPick"></div>
                <input type="hidden" id="nModelVal">
                <div class="muted-help" id="nModelHint">分類就是機台綁定的製程。選了型號會自動把在用的機台全部帶進來，再勾掉不適用的。</div>
            </div>
            <label class="mrow">機器編號</label>
            <div class="wide mrow"><div id="nMachines" class="pickbox muted-help">先選機台型號（可以多選）。</div></div>
            <!-- 綁料號時也可以再指定用哪幾台機器（使用者 2026-09-22：SOP 必定是此料號在特定機台上的規範） -->
            <label class="pmrow">使用機台<span class="muted-help">（可複選、選填）</span></label>
            <div class="wide pmrow">
                <div id="nPartMachines"></div>
                <div class="muted-help">這個料號實際在哪幾台機器上做；<b>不影響重複判定</b>（同一個料號＋同一個製程仍然只能有一份）。</div>
            </div>

            <!-- 量具（檢驗設備一覽表）：設備操作說明書的第二種綁法 -->
            <label class="trow">量具 *</label>
            <div class="wide trow">
                <div id="nToolPick"></div>
                <input type="hidden" id="nToolId">
                <div class="muted-help">先點量具種類再點編號；清單就是「檢驗設備一覽表」裡還在用的量具。</div>
            </div>

            <!-- 料號 -->
            <label id="nPartLab" class="prow">料號 *</label>
            <div class="wide ac-wrap prow">
                <input type="text" id="nPart" data-eg-hint="打料號從清單挑">
                <input type="hidden" id="nPartId">
                <div class="muted-help">同一個料號文字可能有好幾筆、分屬不同客戶，一定要從清單挑對那一筆。</div>
            </div>

            <!-- 製程＝紙本上的「工程名稱」，同一件事所以只留一欄 -->
            <label id="nProcLab">製程</label>
            <div class="wide ac-wrap">
                <input type="text" id="nProc" data-eg-hint="打製程名稱或編號（齒研、12）">
                <input type="hidden" id="nProcNo">
                <div class="muted-help" id="nProcHint">紙本上的「工程名稱」就是製程，一律從製程主檔挑。同一個對象的不同製程可以各有一份文件。</div>
            </div>

            <label>客戶</label>
            <div class="wide ac-wrap">
                <input type="text" id="nCus" data-eg-hint="打客戶編號或簡稱">
                <input type="hidden" id="nCusId">
                <div class="muted-help" id="nCusHint">綁了料號就由料號主檔自動帶入，不用也不可以自己打。</div>
            </div>

            <!-- 型式（使用者 2026-09-23）：同一個料號＋製程＋機台＋客戶底下可以再分最多三種型式。
                 它是重複判定的一部分——不填＝「未分型式」，那本身也算一種。 -->
            <label>型式</label>
            <div class="wide">
                <input type="text" id="nVariant" list="nVariantList" data-eg-hint="例如 有隆齒／上下料，可留空" style="max-width:220px;">
                <datalist id="nVariantList"></datalist>
                <div class="muted-help">同一個料號＋製程＋機台＋客戶底下，只要型式不同就可以各有一份
                    （例如同一台機器的<b>上下料／架機／偏擺確認</b>，或<b>有隆齒／無隆齒</b>），最多三種。</div>
            </div>

            <label>文件名稱 *</label>
            <div class="wide"><input type="text" id="nTitle">
                <div class="muted-help">系統會依綁定對象與製程自動命名，要改直接改掉即可（改過就不再自動蓋掉）。</div></div>
            <label>版次</label><div><input type="text" id="nVer" value="01"></div>
            <label>表單日期 *</label><div><input type="date" id="nDate" value="<?= date('Y-m-d') ?>"></div>
            <label>制/修訂事項</label><div class="wide"><input type="text" id="nNote" value="初訂"></div>
            <label class="srow">檢驗項目</label>
            <div class="wide srow"><label style="font-weight:normal;text-align:left;">
                <input type="checkbox" id="nApplyTpl" checked> 建立時代入預設的檢驗項目
                </label><div class="muted-help" id="nTplHint"></div></div>
        </div>
        <div class="err" id="nErr" style="margin-top:6px;"></div>
        <div id="nDup" style="margin-top:8px;"></div>
    </div>
    <div class="m-foot"><span class="muted-help">建立後會是草稿，填完內容再送簽。</span>
        <span class="sp"></span>
        <button class="btn btn-sm" data-close="maskNew">取消</button>
        <button id="nSave" class="btn btn-sm btn-warm">建立</button></div>
</div></div>

<!-- ══════════ 共用挑選跳窗（圖面／量具編號；刻意排在最後，才蓋得過其他跳窗） ══════════ -->
<div class="ss-mask" id="maskPick"><div class="ss-modal" style="width:820px;">
    <div class="m-head"><span id="pickTitle">挑選</span><button class="x" data-close="maskPick">&times;</button></div>
    <div class="m-body" id="pickBody"></div>
    <div class="m-foot"><span class="sp"></span><button class="btn btn-sm" data-close="maskPick">關閉</button></div>
</div></div>

<!-- ══════════ 送簽跳窗 ══════════ -->
<div class="ss-mask" id="maskSubmit"><div class="ss-modal" style="width:720px;">
    <div class="m-head">送出簽核<button class="x" data-close="maskSubmit">&times;</button></div>
    <div class="m-body" id="submitBody"></div>
    <div class="m-foot"><span class="sp"></span>
        <button class="btn btn-sm" data-close="maskSubmit">取消</button>
        <button id="sbGo" class="btn btn-sm btn-warm">送出</button></div>
</div></div>

<!-- ══════════ 設定跳窗（管理員） ══════════ -->
<?php if ($P['canAdmin']): ?>
<!-- 寬度比照編輯跳窗：檢驗項目預設值那張表欄位跟文件裡的檢驗項目一樣多，
     900px 放不下會把「品質特性」擠成一條（使用者 2026-09-21 回報） -->
<div class="ss-mask" id="maskSet"><div class="ss-modal" style="width:1180px;">
    <div class="m-head">SOP／SIP 設定<button class="x" data-close="maskSet">&times;</button></div>
    <div class="m-body" id="setBody"></div>
    <div class="m-foot"><span class="muted-help">自動簽核會在送出當下把審核與核准一起蓋好，時間依表單日期錯開且不跨日。</span>
        <span class="sp"></span>
        <button class="btn btn-sm" data-close="maskSet">關閉</button>
        <button id="setSave" class="btn btn-sm btn-warm">儲存設定</button></div>
</div></div>
<?php endif; ?>

<!-- ══════════ 使用說明（鐵律7） ══════════ -->
<div class="ss-mask" id="helpUseMask"><div class="ss-modal" style="width:860px;">
    <div class="m-head">使用說明<button class="x" data-close="helpUseMask">&times;</button></div>
    <div class="m-body help-doc">
        <h4>這一頁做什麼</h4>
        <p>把現場的三份紙本做成線上表單，並保留版次歷程與簽核紀錄：</p>
        <ul>
            <li><b>設備操作說明書（3-TD-02-01）</b>＝機台 SOP，綁機器編號；欄位是機器製造商／型式規格／
                加工適用範圍／操作方法／使用注意事項／保養維修要點。</li>
            <li><b>製造製程說明書（3-TD-02-02）</b>＝通用 SOP 與特定料號 SOP 都走這個版面；
                明細是「項次｜參考圖示｜操作步驟｜說明」。</li>
            <li><b>標準檢驗指導書（2-QA-02-01）</b>＝SIP，通用或綁特定料號；
                明細是「管理重點｜品質特性（上下限）｜擔當者｜檢驗方法｜檢具編號｜檢驗頻率｜備註」，
                左邊可帶入圖面。</li>
        </ul>
        <p><b>三種版面的「適用範圍」都是同樣四種</b>——機台／量具、檢驗設備／通用／特定料號
            （<b>新增文件</b>與<b>改綁定對象／適用範圍</b>兩個地方看到的選項完全一樣）。
            製造製程說明書與標準檢驗指導書一樣綁得了機台或量具，現場常有「這一台機器怎麼做」的製程說明書。</p>
        <h4>操作步驟</h4>
        <ol>
            <li>按「新增」選版面與適用範圍，綁好機台型號或料號（<b>一定要從清單挑</b>，打字不選存不進去）。
                <b>文件名稱會自動產生</b>，要改直接改掉即可。</li>
            <li>在文件跳窗填內容；明細表格<b>在最後一列按 ↓ 會自動長出新的一列</b>，沒填東西的末列按 ↑ 會自動移除。
                <b>要調整順序就按住最左邊那一格（☰ 項次／☰ #）上下拖曳</b>——操作步驟、檢驗項目、
                設定裡的檢驗項目預設值三張表都一樣；拖完序號會自動重編，<b>按存檔才會真的存下去</b>。
                把手刻意只放在那一格，這樣在欄位裡反白文字複製仍然不受影響。</li>
            <li>要帶圖面時按「挑圖面」，清單就是<b>這個料號的料號附件</b>，挑一個帶入（只建立關聯，不複製檔案）。
                <b>圖點下去會跳窗放大</b>（圖面、操作步驟的參考圖示、各段落的附件圖都可以）：
                滾輪縮放（以游標那一點為準）、按住拖曳平移、「符合視窗」整張看完、「100%」原始尺寸；
                按<b>「釘在旁邊」</b>會把它縮到畫面右側變成浮動視窗，<b>這時候底下的表單照樣可以打字</b>，
                可以一邊看圖一邊填上下限，標題列可以拖著搬位置。</li>
            <li>填完按「送出簽核」，依序完成 製表 → 審核 → 核准，三格都蓋完這一版就變成已核准。</li>
            <li>要改版按「建立新版次」，內容會整份帶過來，舊版仍查得到印得出來，修訂履歷自動由各版次組出。</li>
        </ol>
        <h4>綁定、製程與重複</h4>
        <ul>
            <li><b>怎麼確認真的綁到了</b>：需要綁定的欄位（料號／客戶／機台型號／量具／製程）右邊都有一個小籤——
                綠底打勾「<b>✓ 已綁定</b>」後面接的就是被綁定的編號（客戶編號、製程編號、主檔 #id），
                可以直接拿去核對；紅底「<b>未綁定</b>」代表**只打了字、沒有從下拉清單挑**。
                只打字不挑是最容易出事的一種：製程會在存檔時被擋下，客戶則會被安靜地丟掉（存完客戶欄變空的）。</li>
            <li><b>製程不會自動幫既有文件綁</b>：綁製程是一份一份自己挑的，系統不會回頭替已經建好的文件補綁——
                現有的製造製程說明書標題多半是機台操作類的名稱（例「KAPP 心軸上下工件」），
                跟製程主檔的製程名稱對不起來，自動猜只會猜錯。沒綁製程不影響列印
                （「製程名稱」那格會退回用文件名稱），只影響「工程名稱」欄與檢驗項目預設值的代入。</li>
            <li><b>「工程名稱」就是製程</b>（日式用語，工程＝工序），所以只有一欄「製程」，一律從製程主檔挑。</li>
            <li><b>三種版面都可以選「通用」或「特定料號」</b>；設備操作說明書另外還有「機台」「量具」。</li>
            <li><b>機台型號、量具、使用機台一律是「先點分類、再點項目」的兩層挑選器</b>
                （與線上檢驗「選擇本單使用的量具」同一套）：<b>機台的分類就是它在機台主檔綁定的製程</b>、
                量具的分類是量具種類；每張分類卡上標有幾項與已選幾項，也可以直接打字跨分類搜尋。
                只有<b>料號</b>維持打字挑——幾千筆排不成卡片。</li>
            <li><b>綁「特定料號」時還可以再指定用哪幾台機器</b>（選填、可複選、可以換型號再加別的）——
                現場的 SOP 本來就是「這個料號在這幾台機器上怎麼做」。</li>
            <li><b>「改綁定對象／適用範圍」只有草稿才有</b>（已送簽或已核准的版次不可改綁定，
                要改請先「取消送簽（退回草稿）」）。</li>
            <li><b>什麼情況才算重複</b>（2026-09-23 改）：<b>料號（或機台型號／量具）＋製程＋機台明細＋客戶＋型式，
                五項全部相同</b>才算同一份，建立時會直接擋下並附「開啟並更新這一份」的按鈕。
                只要其中一項不同就是不同的文件——所以同一個料號的「粗滾」與「齒研」可以各有一份，
                <b>同一台機器的「上下料」「架機」「偏擺確認」也可以各有一份</b>（用「型式」分）。
                同對象底下的其他文件會列在下方當提醒，並標出是<b>機台不同／客戶不同／型式不同</b>。</li>
            <li><b>「型式」是什麼</b>：同一個料號＋製程＋機台＋客戶底下再分的版本，
                例如<b>有隆齒／無隆齒</b>，或同一台機器的<b>上下料／架機／偏擺確認</b>。
                <b>最多三種</b>（留空＝「未分型式」，那本身也算一種）；建議選項在
                「設定 → 頻率／型式／注意事項」維護，仍然可以自己打。</li>
            <li><b>「挑使用設備」是兩層大按鈕</b>（跟線上檢驗挑量具那一頁一樣）：
                <b>① 先點分類</b>（機台依它在主檔綁的製程分類、量具依種類，卡片上標有幾項與已選幾項；
                未分到製程的收在「未分類」不會不見）<b>② 再點設備</b>，同一類可以連續點好幾項、
                再點一次取消，點「← 換一個分類」繼續加別類的。<b>也可以直接打字模糊搜尋</b>——
                打字時跨分類直接列出符合的設備（多個關鍵字用空白分開，每個都要命中），清空就回到分類。
                已選的一律列在上方，每一項都能按 × 單獨移除，左下角「清除全部」重選。
                按「帶入」把編號填進「使用設備」欄，之後仍然可以自己改文字；
                <b>重新開啟時會把欄位裡已經有的設備先勾起來</b>，不會把人填好的洗掉。
                <b>適用範圍已經是「機台」的文件不會出現這個欄位</b>——上面的機台型號與機器編號就是使用設備，
                列印時那一格直接帶綁定的機器編號，不必也不可以再打一次。</li>
            <li><b>設備操作說明書綁的是機台型號，不是單一台機器</b>——同型號（例 HGH250 有三台）共用一份 SOP。
                選了型號會自動把在用的機台全部帶進來，不適用的逐台勾掉；同型號日後新增的機台
                <b>不會自動加入</b>，清單與表頭會標出「還有幾台未納入」。機器製造商／名稱／型式規格／
                加工適用範圍建立時由機台主檔自動帶入，仍然可以改成紙本上的寫法。</li>
            <li><b>客戶綁了料號就由料號主檔自動帶入</b>，不可手打（同一個料號文字常常分屬好幾家客戶）；
                只有通用型文件可以自己挑客戶。</li>
            <li>製令單號與數量已取消，紙本上那兩格不再印。</li>
            <li>除了機台，也可以綁<b>「檢驗設備一覽表」裡的量具</b>
                （設備操作說明書綁量具時，機器製造商／名稱／規格會由量具主檔自動帶入）。</li>
            <li><b>綁料號的文件一定要帶一張圖面才送得出去</b>；存檔時可以先不放，送簽前補上就好。</li>
        </ul>
        <h4>檢驗項目的預設值</h4>
        <ul>
            <li>設定分兩層：<b>標準項目</b>（每份都有的那幾列，如精度等級／外觀／包裝）與
                <b>製程專屬項目</b>（某個製程才有的，如齒研的跨齒厚）。</li>
            <li><b>製程專屬優先</b>（2026-09-23 改）：這個製程只要設過專屬項目，建立文件時
                <b>就只帶它自己的</b>；一列都沒設才退回帶全站共用的標準項目。
                真的兩套都要，才在該製程的「代入設定」勾「另外再帶一份全站共用的標準項目」。
                代入之後仍然可以逐列刪掉不要的，也可以在文件裡按「代入預設項目」隨時再帶一次。</li>
            <li><b>鎖定與可填空</b>（2026-09-23 新增）：預設值的<b>管理重點</b>與<b>品質特性</b>各有一個「鎖定」
                勾選框（預設勾起來）——鎖住的欄位帶進文件之後<b>不可以被改掉</b>。
                要讓現場填數字，就在品質特性裡用 <b>{ }</b> 圈出可以填的位置：
                例如 <b>跨珠Ø{}</b> ＝固定印「跨珠Ø」、後面那一格由現場填 7.3152；
                <b>{ } 裡面可以寫提示字</b>（例 <code>{HRC}</code>），那只是輸入時的提示，不會印出來。
                按欄位旁的 <b>{ }</b> 鈕可以直接在游標處插入一組。
                後端存檔時會<b>拿樣板重新組一次字</b>，所以固定文字不可能被繞過改掉。</li>
            <li><b>擔當者只能挑部門</b>，但顯示文字可以由管理員逐部門改寫——現場講「包裝」，
                組織上並沒有包裝這個部門，就把負責的那個部門顯示成「包裝」。</li>
            <li><b>檢驗方法</b>的選項由管理員挑幾個量具類型混合，再加上自己打的項目（例「依包裝指導書要求」）。</li>
            <li><b>檢具編號一律只能點選、不可手打</b>（2026-09-23 改，避免打錯）：先選量具類型再選編號，
                方法本身就是一種量具時會直接跳到該類型底下；<b>沒有適用的檢具就按「清除」，會填成 N/A</b>。
                <b>存的是量具主檔的 id 不是編號文字</b>，所以量具在「量測儀器校驗管理」改了名字也不會失聯。
                清單上顯示哪幾個欄位（編號／機台名稱／規格…）由<b>量測儀器校驗管理的「類別設定」</b>決定，
                逐類別各自設，本頁不另設一份（見 ai-rules/25）。</li>
            <li><b>已停用的量具與機台仍然挑得到——只要表單日期在停用之前</b>：補 2022 年的舊文件時，
                2025 年才停用的那一台當然要掛得上去。清單會標明「已停用 ○○」，
                停用日之後的文件就點不下去。</li>
            <li><b>檢驗頻率是下拉選單</b>（選項在「設定 → 頻率／型式／注意事項」維護），
                清單裡沒有的寫法選「其他（自行輸入）…」自己打。</li>
            <li><b>品質特性可以插入符號</b>：按欄位旁的 <b>Ø±</b> 鈕，裡面是
                <b>工程符號</b>（Ø ° ± ▽ ↧ ⌴ ⌵ □ ⌒ Ra ×，與批圖編輯器同一份）
                ＋<b>幾何公差</b>（真圓度、圓柱度、平坦度…，直接取自線上檢驗的
                「幾何公差與特殊項目設定」主檔），兩邊重複的只會出現一次。</li>
        </ul>
        <h4>注意事項的範本</h4>
        <ul>
            <li>「設定 → 頻率／型式／注意事項」可以把常用的注意事項存成範本，<b>每一筆可以綁一個客戶</b>
                （不綁＝通用）。</li>
            <li>綁了客戶的範本，在<b>該客戶的文件建立當下會自動帶入</b>；其餘的一律列在注意事項欄上方的
                「帶入範本」，點一下接在現有內容後面（要整段換掉請先清空欄位）。</li>
            <li>客戶一律<b>打字從主檔挑</b>——打錯一個字那筆範本永遠不會被帶出來，而且完全不報錯。</li>
        </ul>
        <h4>附件圖與旋轉</h4>
        <ul>
            <li>操作方法／使用注意事項／保養維修要點／注意事項這些段落都可以加附件圖，
                上傳後畫面立刻出現縮圖，<b>列印時接在該段文字下方</b>。</li>
            <li>圖面與附件圖都可以左轉／右轉。<b>旋轉只影響這份文件的畫面與列印，不會動到原始檔案</b>
                ——帶進來的圖面多半是料號附件，那張圖在料號主檔與圖面查閱仍然是原來的方向。</li>
        </ul>
        <h4>列印</h4>
        <ul>
            <li>按「列印」會<b>直接跳出瀏覽器的列印預覽</b>，不必再按一次；印完（或取消）那個分頁會自己關掉。</li>
            <li>紙張預設：<b>綁機台或量具＝A4 直式</b>，<b>綁料號＝A3 橫式</b>（左邊要放圖面，直式塞不下）。
                逐份文件可以在表頭自己改，管理員也可以在「設定」裡改每個版面的預設。</li>
            <li>頁尾右下角是綁定的 AS 編號（<b>版次依表單日期回推當時生效的版次</b>），每一頁都會印。</li>
            <li><b>表頭標題會在表單名稱後面補上 SOP／SIP</b>（現場講的是「SOP」，只印「製造製程說明書」
                看不出是哪一種）；名稱裡本來就有那幾個字的不會重複加。</li>
            <li><b>只差一點點就放得下一張紙時會自動縮一點</b>，實際縮得進一張就印一張
                （只縮不放大，縮到 0.62 倍還放不下就乖乖印兩張，看不清楚沒有意義）。</li>
            <li>製造製程說明書照紙本排：<b>名稱與參考圖示在同一格</b>（名稱在上、圖在下），
                不另外開一欄；操作步驟的編號請直接打在內容裡（系統不會再補一次，否則會變成「1.1.」）。</li>
            <li>標準檢驗指導書照紙本排：左邊圖面、圖面下方是固定的「注意事項」（在設定裡維護，
                這一份自己填了就以自己的為準）、右邊是檢驗項目，<b>填幾列就印幾列</b>。
                <b>品質特性與上下限兩個都會印</b>（2026-09-23 修）——先印品質特性那一行
                （例「跨珠Ø7.3152」），下面才是上限／下限；原本只要填了上下限就整個不印品質特性，
                那一列到底在量什麼在紙本上完全看不到。</li>
        </ul>
        <h4>簽核人怎麼決定</h4>
        <ul>
            <li>自動簽核的審核與核准<b>設的是「部門＋職稱」不是某一個人</b>——設人的話，補歷史單據時
                那個人可能還沒到職，之後也可能已經離職。系統會依<b>簽章日期當時</b>的職務去找人。</li>
            <li>每一關都可以再設<b>一組代理部門職稱</b>：正選那個部門職稱當天找不到人時才會用代理。
                兩邊都找不到就停在那一關等人工簽，<b>不會亂猜人</b>。</li>
            <li>設定頁會直接顯示「今天會解析到誰」，設完可以馬上確認有沒有設對。</li>
            <li><b>部門下拉是整棵組織樹、依組織順序排</b>（董事長室→總經理室→各課→各組，子單位縮排），
                所以總經理室與董事長室也選得到；<b>職稱只會列出「選到的那個部門底下真的有的職稱」</b>，
                換部門時職稱跟著換（原本選的職稱在新部門也有就留著，沒有才退回「不限職稱」）。</li>
        </ul>
        <h4>操作步驟的項次編號</h4>
        <ul>
            <li><b>編號由系統代入，不要自己打</b>：在操作步驟欄按 <b>Enter</b> 換行就會自動接上下一個編號，
                離開欄位時整欄重編成 1.、2.、3.——中間插一行或刪一行之後號碼不會亂。</li>
            <li>舊寫法（<code>1、</code> <code>(1)</code> <code>１.</code> 全形數字、編號後多一個空白…）一律自動統一成
                <code>1.內容</code>；存檔時後端會再編一次，所以兩邊編出來的一定一樣。</li>
            <li><b>沒有編號的那一行會當成「上一項被折行的下半段」原樣留著</b>，不會被硬編成新的一項——
                既有資料就有「…並依需要更改加工設」換行「定。」這種，硬編會把一句話拆成兩項。</li>
        </ul>
        <h4>內容裡寫到 AS 文件編號</h4>
        <p>任何內容欄位（操作步驟、說明、注意事項、使用注意事項…）只要寫到 AS 編號（例 <code>3-TD-02-02</code>），
            系統會<b>自動綁定那份 AS 文件</b>，文件跳窗的「內容裡引用的 AS 文件」區塊會列出綁到了什麼，
            列印時那串編號會印成「<b>現行編號　文件名稱</b>」。
            <b>存的是文件 id 不是那串編號</b>，所以日後 AS 編號改了，舊資料會自動跟著顯示新編號，不必回頭改。
            對不到 AS 主檔的字串一律當成普通文字，不會亂綁。</p>
        <h4>簽章格的排法</h4>
        <p><b>簽的順序仍然是 製表 → 審核 → 核准</b>（前一關沒簽就不給簽下一關）；
            但<b>畫面與列印上由左到右排的是 核准、審核、製表</b>（職位高的在左，比照紙本）。
            兩者是分開的：順序由 <code>ss_slots()</code> 決定、排法由 <code>ss_slots_display()</code> 決定，
            日後關卡增減（例如只剩兩格）排法會自動跟著變，不會出現兩份對不起來的順序。</p>
        <h4>送出簽核那個視窗</h4>
        <ul>
            <li><b>「自動簽核」預設跟著設定裡這個版面的開關</b>；勾起來時，審核與核准會
                <b>依上面那套設定、以「簽章日期當時」的職務自動帶出人員</b>，管理員仍然可以直接改成別人。</li>
            <li><b>改簽章日期會把整份名單與解析結果重抓一次</b>——那一天的在職者、部門職稱與請假都不一樣。</li>
            <li>解析到的人如果<b>那個日期還沒到職、已經離職，或當天請整天假</b>，一律<b>不會硬填上去</b>，
                而是留白並在欄位下方寫清楚是誰、為什麼不能簽，請自己挑一位。</li>
            <li>請整天假的人<b>仍然列在名單上</b>（標明假別、不可選），不是安靜地消失——
                看不到人會以為是系統壞了。</li>
        </ul>
        <h4>核准之後還能做什麼</h4>
        <ul>
            <li>管理員可以<b>在核准後補附件</b>（內容仍然不可以改，要改請建立新版次）。</li>
            <li>簽錯了要重來，一律用管理員限定的<b>「取消送簽（退回草稿）」整版退回</b>：
                已經蓋好的簽章（含人工蓋的）會全部清掉、狀態回到草稿，改完再按「送出簽核」重送一次，
                管理員可以勾「自動簽核」一次把審核與核准蓋滿。
                <b>刻意沒有「只清掉其中一格」</b>——單格清掉會讓這一版停在「簽核中、卻一個章都沒有」，
                而重蓋是以登入者本人的身分蓋（超級管理員這種特殊帳號蓋不上去），等於整份文件卡死。</li>
            <li>管理員也可以改文件的<b>綁定對象與適用範圍</b>（自動建立的可能綁錯，不必刪掉重建）。</li>
        </ul>
        <h4>刪除</h4>
        <p>管理員可以刪除任何一份文件；一般使用者<b>只能刪除自己建立、而且一個版次都還沒核准過的</b>。
            已經核准過的不可刪除，要停用請把該版次「作廢」。</p>
        <p><b>按了「新增」卻沒有存過檔的文件，關掉跳窗時會自動刪除</b>——建了又改變主意是常有的事，
            留在清單上只會變成一份空殼，而且還會佔住「同一個對象＋同一個製程只能有一份」的名額，
            下次要建同一份反而被自己擋下來。<b>已經填了東西才關掉的會先問一句</b>：
            按【確定】先存檔留下來，按【取消】才刪掉。只要存過一次檔（或送過簽、帶過圖面、傳過附件），
            這份文件就不再是空殼，之後怎麼關都不會被自動刪除。</p>
        <h4>簽核人員的限制</h4>
        <p>可以簽的人一律是<b>表單日期當時在職</b>的人，部門職稱也印當時的（所以補舊文件時，
            當時在職、現在已離職的人仍然挑得到）；而且<b>簽章日期當天不能請整天假</b>——
            半天假仍可簽。這兩條前端會直接把人標成不可選並寫明原因，後端存檔時也會再擋一次。</p>
        <h4>列印</h4>
        <p>按「列印」開出 A3 正式版：大標題是公司全名、表頭取綁定 AS 文件的表單名稱、
            右下角是 AS 編號（<b>版次依表單日期回推當時生效的版次</b>，不是一律印最新版）、
            多頁時左下角才會有頁碼。簽章一律是帶日期的圖章，日期＝該格的簽章日期。</p>
        <p>標準檢驗指導書的表頭那一格是<b>圖面版次</b>；下方「修改記錄」照紙本只有三欄——
            <b>修改版次／修改日期／說明</b>。SOP 那兩份維持原本的「修訂履歷」五欄，多印製表人與狀態。</p>
        <p><b>「說明」那一欄怎麼來的</b>：最舊的那一版固定印「<b>制訂</b>」，其餘一律印「<b>修訂</b>　＋
            表頭「修改說明」欄填的內容（沒填就只印「修訂」）。匯入紙本時自動填進去的
            「紙本匯入」「紙本掃描匯入」「初訂」<b>一律不印</b>——那是匯入程式的備註，不是修訂事項。
            畫面上的「版次歷程」印的是同一份說法，兩邊不會不一樣。</p>
        <h4>權限</h4>
        <p>SOP 與 SIP <b>分開授權</b>（兩個分頁是不同課室在用）：生產課／技術課可編輯與簽核 SOP，
            品管課可編輯與簽核 SIP，兩邊互相看得到但改不動。角色代碼：
            <code>sop_edit</code>／<code>sop_sign</code>／<code>sip_edit</code>／<code>sip_sign</code>／
            <code>sopsip_view</code>／<code>sopsip_admin</code>。管理員另外可以設定自動簽核、
            預設簽核人、圖章模板、AS 文件編號綁定與上班時段。</p>
    </div>
    <div class="m-foot"><span class="sp"></span><button class="btn btn-sm btn-warm" data-close="helpUseMask">知道了</button></div>
</div></div>

<!-- ══════════ 圖面放大（使用者 2026-09-22：點圖面要能跳窗放大，方便一邊看圖一邊填） ══════════
     刻意放在最後面：Esc 關的是「.ss-mask.on 裡的最後一個」，放最後才會先關掉這個看圖視窗，
     而不是把底下正在編輯的文件跳窗關掉。 -->
<div class="ss-mask" id="maskImg"><div class="ss-modal ss-imgwin">
    <div class="m-head" id="imgHead">
        <span>圖面</span><span class="muted-help" id="imgName"></span>
        <span style="margin-left:auto;"></span>
        <button class="btn btn-xs btn-warm-o" id="imgOut" title="縮小">－</button>
        <span class="muted-help" id="imgZoom" style="min-width:42px;text-align:center;">100%</span>
        <button class="btn btn-xs btn-warm-o" id="imgIn" title="放大">＋</button>
        <button class="btn btn-xs btn-warm" id="imgFit" title="整張圖縮到看得完">符合視窗</button>
        <button class="btn btn-xs btn-warm-o" id="img100" title="原始尺寸">100%</button>
        <button class="btn btn-xs btn-warm-o" id="imgPin" title="把這張圖縮到旁邊，一邊看圖一邊填表">釘在旁邊</button>
        <a class="btn btn-xs btn-warm-o" id="imgOpenNew" href="#" target="_blank" title="用瀏覽器自己的檢視器開">另開分頁</a>
        <button class="x" data-close="maskImg">&times;</button>
    </div>
    <div class="m-body" id="imgBody">
        <img id="imgBig" class="fit" src="" alt="圖面">
        <div id="imgErr" style="display:none;color:#F7E0BD;padding:40px;text-align:center;">
            這個檔案不是圖片（多半是 PDF），沒辦法在這裡放大。<br>請按上方的「另開分頁」用瀏覽器開啟。
        </div>
    </div>
    <div class="m-foot">
        <span class="muted-help">滾輪縮放（以游標那一點為準）　按住拖曳可平移
            「釘在旁邊」之後底下的表單照樣可以打字，標題列可以拖著搬位置</span>
        <span class="sp"></span><button class="btn btn-sm" data-close="maskImg">關閉</button>
    </div>
</div></div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/fastclick.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script src="../../resource/js/eg_input_rules.js?v=<?= @filemtime(__DIR__ . '/../../resource/js/eg_input_rules.js') ?>"></script>
<script src="../../resource/js/eg_date_fmt.js?v=<?= @filemtime(__DIR__ . '/../../resource/js/eg_date_fmt.js') ?>"></script>
<script src="../../resource/js/eg_asdoc_picker.js?v=<?= @filemtime(__DIR__ . '/../../resource/js/eg_asdoc_picker.js') ?>"></script>
<!-- 明細表格的拖曳排序（站上既有的共用檔，不要再引 CDN 版） -->
<script src="../../resource/js/Sortable.min.js?v=<?= @filemtime(__DIR__ . '/../../resource/js/Sortable.min.js') ?>"></script>
<script>
var SS_API   = '../../src/store/SopSip_API.php';
var SS_CSRF  = '<?= $CSRF ?>';
var SS_PERMS = <?= json_encode($P, JSON_UNESCAPED_UNICODE) ?>;
var SS_KINDS = <?= json_encode($KINDS, JSON_UNESCAPED_UNICODE) ?>;
var SS_SCOPES = <?= json_encode($SCOPES, JSON_UNESCAPED_UNICODE) ?>;
var SS_SLOTS = <?= json_encode($SLOTS, JSON_UNESCAPED_UNICODE) ?>;
/* 簽的順序是 SS_SLOTS（製表→審核→核准）；畫面上由左到右排的是 SS_SLOTS_D（核准→審核→製表） */
var SS_SLOTS_D = <?= json_encode(ss_slots_display(), JSON_UNESCAPED_UNICODE) ?>;
var SS_STATUSES = <?= json_encode($STATUSES, JSON_UNESCAPED_UNICODE) ?>;
var SS_KIND_SCOPES = <?= json_encode($KIND_SCOPES, JSON_UNESCAPED_UNICODE) ?>;
var SS_TODAY = '<?= date('Y-m-d') ?>';
/* 型式的建議選項（管理員可在設定頁維護；只是建議，仍可自行輸入） */
var SS_VARIANTS = <?= json_encode(ss_variant_options($db), JSON_UNESCAPED_UNICODE) ?>;
var SS_VARIANT_MAX = <?= (int)SS_VARIANT_MAX ?>;
</script>
<script src="sop_sip_ui.js?v=<?= @filemtime(__DIR__ . '/sop_sip_ui.js') ?>"></script>
<script>
$(function () { $('#sidebar-menu').css('visibility', 'visible'); });
</script>
</body>
</html>
