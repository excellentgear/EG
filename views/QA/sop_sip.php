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
        .lst-wrap { max-height:60vh; overflow:auto; border:1px solid var(--line); border-radius:6px; }
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
        .sec { border:1px solid var(--line); border-radius:7px; padding:9px 11px; margin-bottom:10px; background:#FFFDF8; }
        .sec h5 { margin:0 0 8px; font-size:13.5px; color:var(--ink2); display:flex; align-items:center; gap:8px; }
        table.grid { width:100%; border-collapse:collapse; font-size:12.5px; }
        table.grid th, table.grid td { border:1px solid var(--line); padding:3px 5px; vertical-align:top; }
        table.grid thead th { background:var(--sand); color:var(--ink2); text-align:center; font-size:12px; }
        table.grid input, table.grid textarea, table.grid select {
            width:100%; border:1px solid transparent; background:transparent; font-size:12.5px; padding:2px 3px; }
        table.grid input:focus, table.grid textarea:focus { border-color:var(--amber-d); background:#fff; }
        .err { color:var(--coral); font-size:12px; }
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
        <div class="pager" id="pagerBottom"></div>
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

            <!-- 機台：綁的是型號（同型號好幾台共用一份 SOP），底下勾要涵蓋哪幾台 -->
            <label id="nModelLab" class="mrow">機台型號 *</label>
            <div class="wide ac-wrap mrow">
                <input type="text" id="nModel" data-eg-hint="打型號（HGH250）、機台名稱（滾齒機）或機器編號（EG-016）">
                <input type="hidden" id="nModelVal">
                <div class="muted-help" id="nModelHint">同一個型號常常有好幾台，選了型號會自動把在用的機台全部帶進來，再勾掉不適用的。</div>
            </div>
            <label class="mrow">機器編號</label>
            <div class="wide mrow"><div id="nMachines" class="pickbox muted-help">先選機台型號。</div></div>

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
<div class="ss-mask" id="maskSet"><div class="ss-modal" style="width:900px;">
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
        <h4>操作步驟</h4>
        <ol>
            <li>按「新增」選版面與適用範圍，綁好機台型號或料號（<b>一定要從清單挑</b>，打字不選存不進去）。
                <b>文件名稱會自動產生</b>，要改直接改掉即可。</li>
            <li>在文件跳窗填內容；明細表格<b>在最後一列按 ↓ 會自動長出新的一列</b>，沒填東西的末列按 ↑ 會自動移除。</li>
            <li>要帶圖面時按「挑圖面」，清單就是<b>這個料號的料號附件</b>，挑一個帶入（只建立關聯，不複製檔案）。</li>
            <li>填完按「送出簽核」，依序完成 製表 → 審核 → 核准，三格都蓋完這一版就變成已核准。</li>
            <li>要改版按「建立新版次」，內容會整份帶過來，舊版仍查得到印得出來，修訂履歷自動由各版次組出。</li>
        </ol>
        <h4>綁定、製程與重複</h4>
        <ul>
            <li><b>「工程名稱」就是製程</b>（日式用語，工程＝工序），所以只有一欄「製程」，一律從製程主檔挑。</li>
            <li><b>同一個對象＋同一個製程只能有一份文件</b>：建立時如果撞到既有的，會直接擋下並附上
                「開啟並更新這一份」的按鈕。同一個料號的「粗滾」與「齒研」可以各有一份。</li>
            <li><b>設備操作說明書綁的是機台型號，不是單一台機器</b>——同型號（例 HGH250 有三台）共用一份 SOP。
                選了型號會自動把在用的機台全部帶進來，不適用的逐台勾掉；同型號日後新增的機台
                <b>不會自動加入</b>，清單與表頭會標出「還有幾台未納入」。機器製造商／名稱／型式規格／
                加工適用範圍建立時由機台主檔自動帶入，仍然可以改成紙本上的寫法。</li>
            <li><b>客戶綁了料號就由料號主檔自動帶入</b>，不可手打（同一個料號文字常常分屬好幾家客戶）；
                只有通用型文件可以自己挑客戶。</li>
            <li>製令單號與數量已取消，紙本上那兩格不再印。</li>
        </ul>
        <h4>檢驗項目的預設值</h4>
        <ul>
            <li>設定分兩層：<b>標準項目</b>（每份都有的那幾列，如精度等級／外觀／包裝）與
                <b>製程專屬項目</b>（某個製程才有的，如齒研的跨齒厚）。</li>
            <li>建立文件時會依該製程的設定自動代入（先專屬、後標準），<b>代入之後仍然可以逐列刪掉不要的</b>；
                也可以在文件裡按「代入預設項目」隨時再帶一次。逐製程可以各自設定
                「要不要自動代入」與「代入時要不要一併帶標準項目」。</li>
            <li><b>擔當者只能挑部門</b>，但顯示文字可以由管理員逐部門改寫——現場講「包裝」，
                組織上並沒有包裝這個部門，就把負責的那個部門顯示成「包裝」。</li>
            <li><b>檢驗方法</b>的選項由管理員挑幾個量具類型混合，再加上自己打的項目（例「依包裝指導書要求」）；
                <b>檢具編號先選量具類型再選編號</b>，方法本身就是一種量具時會直接跳到該類型底下。</li>
        </ul>
        <h4>附件圖與旋轉</h4>
        <ul>
            <li>操作方法／使用注意事項／保養維修要點／注意事項這些段落都可以加附件圖，
                上傳後畫面立刻出現縮圖，<b>列印時接在該段文字下方</b>。</li>
            <li>圖面與附件圖都可以左轉／右轉。<b>旋轉只影響這份文件的畫面與列印，不會動到原始檔案</b>
                ——帶進來的圖面多半是料號附件，那張圖在料號主檔與圖面查閱仍然是原來的方向。</li>
        </ul>
        <h4>刪除</h4>
        <p>管理員可以刪除任何一份文件；一般使用者<b>只能刪除自己建立、而且一個版次都還沒核准過的</b>。
            已經核准過的不可刪除，要停用請把該版次「作廢」。</p>
        <h4>簽核人員的限制</h4>
        <p>可以簽的人一律是<b>表單日期當時在職</b>的人，部門職稱也印當時的（所以補舊文件時，
            當時在職、現在已離職的人仍然挑得到）；而且<b>簽章日期當天不能請整天假</b>——
            半天假仍可簽。這兩條前端會直接把人標成不可選並寫明原因，後端存檔時也會再擋一次。</p>
        <h4>列印</h4>
        <p>按「列印」開出 A3 正式版：大標題是公司全名、表頭取綁定 AS 文件的表單名稱、
            右下角是 AS 編號（<b>版次依表單日期回推當時生效的版次</b>，不是一律印最新版）、
            多頁時左下角才會有頁碼。簽章一律是帶日期的圖章，日期＝該格的簽章日期。</p>
        <h4>權限</h4>
        <p>SOP 與 SIP <b>分開授權</b>（兩個分頁是不同課室在用）：生產課／技術課可編輯與簽核 SOP，
            品管課可編輯與簽核 SIP，兩邊互相看得到但改不動。角色代碼：
            <code>sop_edit</code>／<code>sop_sign</code>／<code>sip_edit</code>／<code>sip_sign</code>／
            <code>sopsip_view</code>／<code>sopsip_admin</code>。管理員另外可以設定自動簽核、
            預設簽核人、圖章模板、AS 文件編號綁定與上班時段。</p>
    </div>
    <div class="m-foot"><span class="sp"></span><button class="btn btn-sm btn-warm" data-close="helpUseMask">知道了</button></div>
</div></div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/fastclick.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script src="../../resource/js/eg_input_rules.js?v=<?= @filemtime(__DIR__ . '/../../resource/js/eg_input_rules.js') ?>"></script>
<script src="../../resource/js/eg_date_fmt.js?v=<?= @filemtime(__DIR__ . '/../../resource/js/eg_date_fmt.js') ?>"></script>
<script src="../../resource/js/eg_asdoc_picker.js?v=<?= @filemtime(__DIR__ . '/../../resource/js/eg_asdoc_picker.js') ?>"></script>
<script>
var SS_API   = '../../src/store/SopSip_API.php';
var SS_CSRF  = '<?= $CSRF ?>';
var SS_PERMS = <?= json_encode($P, JSON_UNESCAPED_UNICODE) ?>;
var SS_KINDS = <?= json_encode($KINDS, JSON_UNESCAPED_UNICODE) ?>;
var SS_SCOPES = <?= json_encode($SCOPES, JSON_UNESCAPED_UNICODE) ?>;
var SS_SLOTS = <?= json_encode($SLOTS, JSON_UNESCAPED_UNICODE) ?>;
var SS_STATUSES = <?= json_encode($STATUSES, JSON_UNESCAPED_UNICODE) ?>;
var SS_TODAY = '<?= date('Y-m-d') ?>';
</script>
<script src="sop_sip_ui.js?v=<?= @filemtime(__DIR__ . '/sop_sip_ui.js') ?>"></script>
<script>
$(function () { $('#sidebar-menu').css('visibility', 'visible'); });
</script>
</body>
</html>
