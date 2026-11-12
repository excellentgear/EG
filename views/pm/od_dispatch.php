<?php
/**
 * 批次發單（外發加工單）— 2026-11-12 建立
 * 一次挑多張 BOM 的多個製程 → 依廠商合併成一廠一張 → 發單並列印（點陣式中一刀）。
 * 資料走 src/store/OdDispatch_API.php；共用邏輯 src/common/od_dispatch_lib.php
 */
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/pm/od_dispatch.php";
    header("Location:../../index.php");
    exit;
}
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/od_dispatch_lib.php';

$db = (new DBConnection())->getPDO();
od_ensure_schema($db);
$odUser = od_current_user($db);
$perms  = od_perms($db, $odUser);
$roleLabel = $perms['isAdmin'] ? '管理者' : ($perms['canAdmin'] ? '批次發單管理員' : ($perms['canIssue'] ? '發單人員' : ($perms['canView'] ? '檢閱' : '無角色')));
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>批次發單</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <style>
        #sidebar-menu { visibility: hidden; }
        .right_col .page-title { margin:8px 0 4px; overflow:hidden; clear:both; }
        .page-help-btn { height:30px; font-size:13px; padding:0 12px; border:1px solid #d98a33; border-radius:15px; background:#F0A24B; color:#fff; cursor:pointer; }
        .page-help-btn:hover { background:#d98a33; }
        @media print { .page-help-btn { display:none !important; } }
        .help-doc { font-size:13px; color:#5b3a1e; line-height:1.75; }
        .help-doc h4 { color:#8A5A2B; border-bottom:2px solid #F7E0BD; padding-bottom:3px; margin:14px 0 6px; font-size:15px; }
        .help-doc h4:first-child { margin-top:0; }
        .help-doc ul { padding-left:20px; margin:4px 0; }

        .od-tabs { display:flex; flex-wrap:wrap; gap:4px; border-bottom:2px solid #E0BE86; margin:6px 0 10px; clear:both; }
        .od-tab { padding:7px 16px; font-size:14px; color:#8a6d45; background:#FBF5EA; border:1px solid #E8D5B5; border-bottom:none; border-radius:6px 6px 0 0; cursor:pointer; margin-bottom:-2px; }
        .od-tab:hover { background:#F7E0BD; }
        .od-tab.on { background:#F0A24B; color:#fff; border-color:#d98a33; font-weight:bold; }
        .od-pane { display:none; } .od-pane.on { display:block; }
        .od-card { background:#fff; border:1px solid #EADFC8; border-radius:6px; padding:10px 12px; margin-bottom:10px; min-width:0; }
        .od-card h4 { margin:0 0 8px; font-size:15px; color:#8A5A2B; }
        .hint { font-size:12px; color:#8a6d45; line-height:1.6; }
        .od-filters { display:flex; flex-wrap:wrap; gap:8px; align-items:flex-end; margin-bottom:8px; }
        .od-filters label { font-size:12px; color:#8a6d45; display:flex; flex-direction:column; gap:2px; margin:0; font-weight:normal; }
        .od-filters input, .od-filters select, .od-inp { border:1px solid #D8BE93; border-radius:4px; padding:4px 7px; font-size:13px; background:#FFFDF8; color:#5b3a1e; max-width:100%; }
        .btn-o { height:30px; padding:0 14px; border-radius:4px; font-size:13px; cursor:pointer; border:1px solid #D8BE93; background:#fff; color:#5b3a1e; }
        .btn-o:hover { background:#F7E0BD; }
        .btn-o.pri { background:#F0A24B; border-color:#d98a33; color:#fff; font-weight:bold; }
        .btn-o.pri:hover { background:#d98a33; }
        .btn-o.sm { height:24px; padding:0 8px; font-size:12px; }
        .btn-o:disabled { opacity:.5; cursor:not-allowed; }

        .lg { display:flex; flex-wrap:wrap; gap:6px; align-items:center; font-size:12px; margin-bottom:6px; }
        .pc { display:inline-flex; flex-direction:column; border:2px solid #CDB89B; border-radius:5px; padding:1px 8px; min-width:84px; font-size:12px; cursor:pointer; user-select:none; background:#fff; color:#3E2B1A; line-height:1.2; }
        .lg .pc { min-width:0; cursor:default; display:inline-block; }
        .pc i { font-style:normal; font-weight:700; }
        .pc small { font-size:10.5px; opacity:.9; display:block; line-height:1.2; }
        .pc.s-partial { background:#FBEBC0; color:#4a2c0a; }
        .pc.s-done { background:#E3F0D3; color:#2f6b1f; border-color:#A9C47C; }
        .pc.s-skip { background:#F1ECE4; color:#8A7F72; border-style:dashed; cursor:default; }
        .pc.sug { border-color:transparent; background-image:linear-gradient(90deg,#2F6FB5 50%,transparent 50%),linear-gradient(180deg,#2F6FB5 50%,transparent 50%),linear-gradient(270deg,#2F6FB5 50%,transparent 50%),linear-gradient(0deg,#2F6FB5 50%,transparent 50%); background-size:12px 3px,3px 12px,12px 3px,3px 12px; background-position:0 0,100% 0,100% 100%,0 100%; background-repeat:repeat-x,repeat-y,repeat-x,repeat-y; animation:odcw .7s linear infinite; }
        @keyframes odcw { to { background-position:12px 0,100% 12px,calc(100% - 12px) 100%,0 calc(100% - 12px); } }
        @media (prefers-reduced-motion:reduce) { .pc.sug { animation:none; } }
        .pc.sel { padding-top:0; padding-bottom:0; background-color:#DCE9F5; background-image:none; color:#1f3a56; border:3px solid #2F6FB5; padding:0 7px; animation:none; }
        .pc.sel::after { content:"✓ 已選取"; font-size:10px; font-weight:700; }
        .pc small.qcl { font-weight:700; color:#2f6b1f; }
        .pc small.qcl.qp { color:#111; }
        .bomcard { border:1px solid #EADFC8; border-radius:6px; padding:4px 8px; margin-bottom:5px; background:#fff; }
        .bh { display:flex; flex-wrap:wrap; gap:2px 10px; align-items:center; margin-bottom:3px; font-size:13px; line-height:1.25; }
        .bh .btn-o.sm { height:22px; }
        .chain { display:flex; flex-wrap:wrap; gap:4px; }
        .qtybox { margin-top:5px; padding:4px 10px; background:#FDF3E3; border:1px solid #E4D3BC; border-radius:5px; font-size:13px; }
        .qtybox input { width:90px; text-align:right; border:1px solid #D8BE93; border-radius:4px; padding:3px 6px; }
        .qtybox input.bad, .od-tbl input.bad { border:2px solid #DD5138; background:#FDE8E2; }
        .warn { color:#B8431F; font-size:12px; }
        .pill { display:inline-block; padding:0 7px; border-radius:9px; font-size:11px; line-height:18px; background:#F7E0BD; color:#5b3a12; }
        .pill.u { background:#F0A24B; } .pill.e { background:#DD5138; color:#fff; }

        .od-tbl { width:100%; border-collapse:collapse; font-size:12.5px; }
        .od-tbl th, .od-tbl td { border-bottom:1px solid #EADFC8; padding:5px 7px; text-align:left; vertical-align:middle; white-space:nowrap; }
        .od-tbl th { background:#F7E0BD; color:#5b3a1e; }
        .od-tbl .num { text-align:right; font-variant-numeric:tabular-nums; }
        .od-tbl input[type=number] { width:76px; text-align:right; border:1px solid #D8BE93; border-radius:4px; padding:2px 5px; }
        .od-tbl input[type=date], .od-tbl input.rm { border:1px solid #D8BE93; border-radius:4px; padding:2px 5px; }
        .od-tbl input.rm { width:160px; }
        .od-tbl tr.grp td { background:#FDF3E3; font-weight:bold; color:#B8431F; }
        .od-tbl tr[draggable=true] { cursor:grab; }
        .tw { overflow-x:auto; }
        .tray { display:flex; gap:8px; flex-wrap:wrap; align-items:center; padding:8px; border:1px dashed #d98a33; border-radius:6px; background:#FDF3E3; margin-bottom:8px; }
        .vchip { border:1px solid #d98a33; background:#fff; border-radius:14px; padding:3px 12px; font-size:13px; cursor:pointer; font-weight:700; color:#5b3a1e; }
        .vchip.drop { background:#F0A24B; color:#fff; }
        .vchip.armed { background:#2F6FB5; color:#fff; border-color:#2F6FB5; }
        .od-tbl tr.armed-row { cursor:copy; }
        .vchip small { font-weight:normal; color:#8a6d45; margin-left:4px; }
        .vtag { padding:0 8px; border-radius:9px; background:#fff; border:1px solid #d98a33; font-size:12px; }
        .vtag.x { border-color:#DD5138; color:#DD5138; }
        .vtag.none { border-color:#DD5138; color:#DD5138; border-style:dashed; }
        .od-bar { position:sticky; bottom:0; background:#fff; border:1px solid #d98a33; border-radius:6px; padding:8px 12px; display:flex; gap:10px; align-items:center; flex-wrap:wrap; justify-content:space-between; z-index:5; }
        .slipbox { border:1px solid #d98a33; border-radius:6px; padding:8px; background:#FDF3E3; margin-bottom:10px; }
        .slipbox .hd { display:flex; justify-content:space-between; gap:8px; flex-wrap:wrap; margin-bottom:6px; font-weight:bold; color:#5b3a1e; }
        .slip-prev { background:#fff; color:#222; border:1px solid #999; padding:8px 12px; font-family:"MingLiU","Noto Sans TC",monospace; font-size:12px; overflow-x:auto; }
        .od-mask { display:none; position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:10500; align-items:center; justify-content:center; }
        .od-mask.on { display:flex; }
        .od-modal { background:#fff; border-radius:6px; box-shadow:0 6px 32px rgba(0,0,0,.25); width:760px; max-width:96vw; max-height:90vh; display:flex; flex-direction:column; }
        .od-mhd { padding:10px 16px; border-bottom:1px solid #E8D5B5; display:flex; justify-content:space-between; font-weight:bold; color:#5b3a1e; }
        .od-mhd .x { cursor:pointer; font-size:20px; color:#aaa; line-height:1; }
        .od-mbd { padding:12px 16px; overflow:auto; }
        .od-mft { padding:8px 16px; border-top:1px solid #E8D5B5; text-align:right; }
        .fld { display:flex; gap:8px; align-items:center; margin:6px 0; font-size:13px; flex-wrap:wrap; }
        .fld b { min-width:96px; font-weight:600; color:#5b3a1e; }
        .chip { border:1px solid #D8BE93; border-radius:14px; padding:2px 10px; font-size:12px; background:#fff; cursor:pointer; display:inline-block; margin:2px; }
        .chip.on { background:#F0A24B; color:#fff; border-color:#d98a33; }
        .err { color:#DD5138; font-size:12px; }
    </style>
</head>
<body class="nav-sm">
<div class="container body">
<div class="main_container">
    <?php include '../partPage/sideAndTopBarMenu.html' ?>
    <div class="right_col" role="main">
        <div class="page-title" style="display:flex;align-items:center;flex-wrap:wrap;">
            <h2 style="margin:6px 0;">批次發單
                <small style="color:#8a6d45;">一次挑多個製程，依廠商合併成一廠一張外發單並列印</small></h2>
            <button id="btnPageHelp" class="page-help-btn" style="margin-left:auto;"><i class="fa fa-question-circle"></i> 使用說明</button>
        </div>
        <div class="clearfix"></div>
<?php if (!$perms['canView']): ?>
        <div class="od-card" style="margin-top:12px;">
            <h4 style="color:#DD5138;"><i class="fa fa-lock"></i> 沒有批次發單權限</h4>
            請洽管理員在「使用者權限設定」指派「批次發單」角色（檢閱／發單人員／管理員）。
        </div>
<?php else: ?>
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin:6px 0;">
            <span class="hint">目前角色：<b><?= htmlspecialchars($roleLabel) ?></b></span>
            <span id="asdocHint" class="hint"></span>
            <?php if ($perms['canAdmin']): ?><button id="btnAsdoc" class="btn-o sm">綁定 AS 文件</button><?php endif; ?>
        </div>
        <div class="od-tabs" id="tabs">
            <?php if ($perms['canIssue']): ?>
            <span class="od-tab on" data-t="p1">① 挑製程加入</span>
            <span class="od-tab" data-t="p2">② 發單清單・指派廠商</span>
            <span class="od-tab" data-t="p3">③ 依廠商分張・列印</span>
            <?php endif; ?>
            <?php if ($perms['canAdmin']): ?><span class="od-tab" data-t="p4">④ 列印格式設定</span><?php endif; ?>
            <span class="od-tab<?= $perms['canIssue'] ? '' : ' on' ?>" data-t="p5">⑤ 發單與列印紀錄</span>
        </div>

        <!-- ① 挑製程 -->
        <div class="od-pane<?= $perms['canIssue'] ? ' on' : '' ?>" id="p1">
          <div class="od-card">
            <h4>加入發單：每張 BOM 的製程一覽，直接點製程挑選</h4>
            <div class="od-filters">
                <label>料號<input id="fPart" size="12" placeholder="打字篩選"></label>
                <label>BOM<input id="fBom" size="12" placeholder="B-1151…"></label>
                <label>客戶<input id="fClient" size="10"></label>
                <label>製程大類<select id="fType" data-eg-filter="輸入製程大類篩選…"><option value="">全部</option></select></label>
                <label>上一關回廠／QC進度<select id="fQc">
                    <option value="issuable">可以發單的（預設）</option>
                    <option value="any_returned">已有回廠的（QC全部或部分完成皆可）</option>
                    <option value="all">全部</option><option value="qc_full">本批QC全數檢驗完成</option>
                    <option value="qc_partial">本批QC部分檢驗完成</option><option value="no_qc">回廠後尚未檢驗</option>
                    <option value="none">尚未發單的製程</option></select></label>
                <label>急件<select id="fUrg"><option value="">全部</option><option value="U">U 急件</option><option value="E">E 特急</option></select></label>
                <button class="btn-o pri" id="btnSearch"><i class="fa fa-search"></i> 查詢</button>
            </div>
            <div class="lg"><b>顏色：</b>
                <span class="pc">尚未發單</span><span class="pc s-partial">已發出一部分或尚未回齊</span>
                <span class="pc s-done"><i>全數回廠</i><small>✓ 發500</small></span><span class="pc s-skip">跳過</span>
                <span class="pc sug">本次建議</span><span class="pc sel">已選取</span>
            </div>
            <div class="hint" style="margin:4px 0 8px;">點製程方塊＝選取（再點取消，可多選，也可跨 BOM）。藍色虛線是依上一關進度建議現在該發的製程。可發量＝上一關<b>已驗合格數</b>－本製程已發量；第一關客供料（製程 138）實收數量可多於或少於 BOM，只提醒不擋。</div>
            <div id="chainBox"><div class="hint">按「查詢」載入。</div></div>
            <div id="moreBox" style="margin-top:6px;"></div>
          </div>
          <div class="od-bar"><span id="selinfo">已選 <b>0</b> 個製程（<b>0</b> 張 BOM）</span><button class="btn-o pri" id="btnToList">加入發單清單 ▶</button></div>
        </div>

        <!-- ② 發單清單 -->
        <div class="od-pane" id="p2">
          <div class="od-card">
            <h4>發單清單：把製程指派給廠商</h4>
            <div class="fld"><b>發單日</b><input type="date" id="issueDate" class="od-inp"> <span class="hint">交期預設＝發單日＋5 個工作日（依公司行事曆，可逐列修改）</span></div>
            <div class="tray" id="tray"><span class="hint">加入製程後這裡會出現常用廠商</span></div>
            <div class="hint" style="margin-bottom:6px;">把列拖到廠商上；或先勾列再點廠商；<b>也可以先點廠商，再逐列點下去指派</b>。<span id="armHint" class="warn"></span><br>選到不是這個製程大類歷史上用過的廠商，會標紅色「非此製程廠商」提醒，但允許發單。</div>
            <div class="tw"><table class="od-tbl" id="listTbl"></table></div>
          </div>
          <div class="od-bar"><span id="listInfo"></span>
            <span><button class="btn-o" data-go="p1">◀ 回上一步</button> <button class="btn-o pri" id="btnToSlips">下一步：依廠商分張預覽 ▶</button></span></div>
        </div>

        <!-- ③ 分張 -->
        <div class="od-pane" id="p3">
          <div class="od-card">
            <div class="fld"><b>列印格式</b><select id="fmtSel" class="od-inp"></select>
              <label style="font-weight:normal;"><input type="checkbox" id="twoPart"> 印成兩聯（中一刀為兩聯紙；預設否）</label></div>
            <div class="hint">每間廠商一張單、不同廠商換紙；同廠商所有列印在同一張（列數太多才自動續頁，每頁都印表頭）。可逐張檢視後個別列印，或全部列印。</div>
          </div>
          <div id="slipPrev"></div>
          <div class="od-bar"><span class="hint">按「確認發單」才會真的寫入製程發包資料並配單號；預覽不會異動任何資料。</span>
            <span><button class="btn-o" data-go="p2">◀ 回上一步</button>
              <button class="btn-o" id="btnIssueOnly">只發單不列印</button>
              <button class="btn-o pri" id="btnIssuePrint">確認發單・全部列印</button></span></div>
          <div id="issuedBox"></div>
        </div>

        <!-- ④ 格式 -->
        <div class="od-pane" id="p4">
          <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <div class="od-card" style="flex:1 1 240px;"><h4>格式清單</h4><div id="fmtList"></div>
              <div style="margin-top:8px;"><button class="btn-o sm" id="fmtNew">＋ 新增</button></div></div>
            <div class="od-card" style="flex:2 1 420px;"><h4 id="fmtTitle">編輯格式</h4><div id="fmtForm"><span class="hint">請從左側選一個格式。</span></div></div>
          </div>
        </div>

        <!-- ⑤ 紀錄 -->
        <div class="od-pane<?= $perms['canIssue'] ? '' : ' on' ?>" id="p5">
          <div class="od-card">
            <div class="od-filters">
              <label>發單日（起）<input type="date" id="rFrom"></label><label>（迄）<input type="date" id="rTo"></label>
              <label>單號／廠商／BOM／料號<input id="rKw" size="18"></label>
              <button class="btn-o pri" id="btnRecs">查詢</button>
            </div>
            <div class="tw"><table class="od-tbl" id="recTbl"></table></div>
            <div class="hint" style="margin-top:6px;">每次列印都會記到「列印與簽核紀錄」（列印人、時間、登入電腦）。重印會累加次數。</div>
          </div>
        </div>
<?php endif; ?>
    </div>
</div>
</div>

<!-- 使用說明 -->
<div class="od-mask" id="helpMask"><div class="od-modal"><div class="od-mhd"><span><i class="fa fa-question-circle"></i> 批次發單　使用說明</span><span class="x" data-close>&times;</span></div>
<div class="od-mbd help-doc">
<h4>這個頁面做什麼</h4>
<p>一次挑多張 BOM 的多個製程發給外包廠商。<b>同一廠商自動合併成一張單</b>，不同廠商各一張，列印時換紙。發單的結果與 BOM 總覽的「發單」相同（寫入發包日、廠商、狀態轉加工中、開一批流水帳），BOM 總覽原有的單筆發單不受影響。</p>
<h4>操作步驟</h4>
<ul><li><b>① 挑製程</b>：篩選後點每張 BOM 的製程方塊（可多選、可跨 BOM）。同一張 BOM 選的多個製程共用同一個發單量，預設＝上一關已驗合格數－已發量，可修改；超過會即時變紅。</li>
<li><b>② 指派廠商</b>：把列拖到廠商盤，或勾列後點廠商。可用「其他廠商」搜尋全部廠商。</li>
<li><b>③ 分張列印</b>：檢視每間廠商的單，確認後發單並列印。可選「印成兩聯」。</li>
<li><b>④ 列印格式</b>（管理員）：自訂欄位順序、每頁列數、表尾文字、紙張；可存多種並設預設。表頭固定為本公司全名，表單名稱綁定 AS 文件。</li></ul>
<h4>注意</h4>
<ul><li>已全數發單回廠的製程預設發單量 0；只有後段報廢補料或訂單追加（須先改 BOM 數量）才需重發。</li>
<li>發單量少於 BOM 總量時，該站回廠結清後請到 BOM 總覽按「發單完成（其餘不再發）」，與原本分批發單相同。</li>
<li>備註欄預設帶入 BOM 製程列的備註（ps），可逐列修改，會印在單上。</li></ul>
<h4>權限</h4><p>角色：批次發單檢閱／發單人員／管理員（在使用者權限設定頁指派），管理者固定全權。</p>
</div></div></div>

<!-- 其他廠商搜尋 -->
<div class="od-mask" id="mkMask"><div class="od-modal" style="width:720px;"><div class="od-mhd"><span>選擇廠商（只列加工廠與客戶；右側為廠商主檔設定的加工項目）</span><span class="x" data-close>&times;</span></div>
<div class="od-mbd"><input id="mkQ" class="od-inp" style="width:100%;" placeholder="輸入廠商編號或名稱…"><div id="mkList" style="margin-top:8px;"></div></div></div></div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/fastclick.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script src="../../resource/js/eg_date_fmt.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_date_fmt.js') ?>"></script>
<script src="../../resource/js/eg_asdoc_picker.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_asdoc_picker.js') ?>"></script>
<script src="../../resource/js/eg_input_rules.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_input_rules.js') ?>"></script>
<script>
$(document).ready(function(){ $('#sidebar-menu').css('visibility','visible'); });
<?php if ($perms['canView']): ?>
(function(){
var API='../../src/store/OdDispatch_API.php', META=null, CSRF='';
var CHAIN=[], CHAINTOTAL=0, OFFSET=0, MORE=false, ARMED=null, EXTRA=[];      // ① 查詢結果
var SELQ={};                                // bomIndex -> 使用者改過的發單量
var LINES=[];                               // ② 發單清單
var ISSUED=[];                              // ③ 已發的單
var FMT=null;                               // ④ 編輯中的格式
function esc(s){return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];});}
function fd(d){return d?(window.egFmtDate?egFmtDate(d):d):'';}
function num(v){var n=+v;return isFinite(n)?n:0;}
function fmtQ(v){return String(Math.round(num(v)*1000)/1000);}
function snL(n){n=+n;return (n%10===0)?String(n/10):String(n);}
function get(p,cb){$.getJSON(API,p,function(r){if(r&&r.ok)cb(r);else alert((r&&r.error)||'失敗');}).fail(xhrErr);}
function post(p,cb){p.csrf=CSRF;$.post(API,p,function(r){if(r&&r.ok)cb(r);else alert((r&&r.error)||'失敗');},'json').fail(xhrErr);}
function xhrErr(x){var m='連線失敗';try{m=JSON.parse(x.responseText).error||m;}catch(e){}alert(m);}
function openM(id){$('#'+id).addClass('on');}
$(document).on('click','[data-close]',function(){$(this).closest('.od-mask').removeClass('on');});
$('#btnPageHelp').on('click',function(){openM('helpMask');});
function go(t){$('.od-tab').removeClass('on');$('.od-tab[data-t='+t+']').addClass('on');$('.od-pane').removeClass('on');$('#'+t).addClass('on');
  if(t==='p2')renderList();if(t==='p3')renderSlipPrev();if(t==='p4')loadFmtTab();if(t==='p5')loadRecs();}
$('#tabs').on('click','.od-tab',function(){go($(this).data('t'));});
$(document).on('click','[data-go]',function(){go($(this).data('go'));});

/* ───── meta ───── */
get({action:'meta'},function(m){
  META=m;CSRF=m.csrf;
  $.each(m.process_types,function(i,t){$('#fType').append('<option value="'+t.id+'">'+esc(t.name)+'</option>');});
  $('#issueDate').val(m.today);
  renderAsdoc();renderFmtSel();
  var t=new Date();var r=new Date(t.getTime()-30*864e5);
  $('#rFrom').val(r.toISOString().slice(0,10));$('#rTo').val(m.today);
  if(m.perms.canIssue)search(true);else loadRecs();
});
function renderAsdoc(){var d=META.asdoc;$('#asdocHint').text(d?('表單名稱：'+d.doc_name+'　編號：'+d.doc_no):'尚未綁定 AS 文件（列印表單名稱暫用「外發加工單」，無編號）');}
$('#btnAsdoc').on('click',function(){EGAsDoc.open({docs:META.asdoc_list,current:META.asdoc?META.asdoc.id:0,title:'AS 文件編號綁定　外發加工單',
  onSave:function(id){post({action:'asdoc_save',doc_id:id||0},function(r){META.asdoc=r.asdoc;renderAsdoc();});}});});

/* ───── ① 挑製程 ───── */
var QCG='<svg width="13" height="13" viewBox="0 0 14 14" style="vertical-align:-2px"><path d="M2 7.5l3.5 3.5L12 3" fill="none" stroke="#2f6b1f" stroke-width="2.2"/></svg>';
var QCH='<svg width="15" height="15" viewBox="0 0 16 16" style="vertical-align:-3px"><path d="M8 1.5L15 14H1z" fill="#F0A24B" stroke="#111" stroke-width="1.3" stroke-linejoin="round"/><path d="M8 6v4" stroke="#111" stroke-width="1.8" stroke-linecap="round"/><circle cx="8" cy="12" r="1" fill="#111"/></svg>';
function chipHtml(c){
  var cls='pc';var sub='';
  if(c.state==='skip'){cls+=' s-skip';sub='跳過';}
  else if(c.state==='done'){cls+=' s-done';sub='✓ 發'+fmtQ(c.sent);}
  else if(c.state==='partial'){cls+=' s-partial';sub='發'+fmtQ(c.sent)+' 回'+fmtQ(c.ret)+(c.scrap>0?' 廢'+fmtQ(c.scrap):'');}
  else{sub=c.suggest?'未發・建議':'未發';}
  if(c.suggest)cls+=' sug';
  var qc='';
  if(c.qc.mode==='full')qc='<small class="qcl">'+QCG+' QC檢驗</small>';
  else if(c.qc.mode==='partial')qc='<small class="qcl qp">'+QCH+' QC檢驗 '+fmtQ(c.qc.insp)+'/'+fmtQ(c.qc.ret)+'</small>';
  return '<span class="'+cls+'" data-fid="'+c.bom_ing_fid+'"><i>'+esc(snL(c.bom_sn))+' '+esc(c.name)+'</i><small>'+sub+'</small>'+qc+'</span>';
}
function renderChain(){
  var h='';
  $.each(CHAIN,function(bi,b){
    var urg=b.priority_type==='U'?'<span class="pill u">U</span>':(b.priority_type==='E'?'<span class="pill e">E</span>':'');
    h+='<div class="bomcard" data-bi="'+bi+'"><div class="bh"><b>'+esc(b.bom)+'</b> '+esc(b.part_no)+'　'+esc(b.client)+' '+urg
      +'<span class="hint">BOM總量 '+fmtQ(b.sqty)+(b.Delivery_date?'　交期 '+fd(b.Delivery_date):'')+'</span>'
      +'<span style="margin-left:auto;"><button class="btn-o sm" data-sug="'+bi+'">選建議製程</button></span></div><div class="chain">';
    $.each(b.chain,function(i,c){h+=chipHtml(c);});
    h+='</div><div class="qtyslot"></div></div>';
  });
  $('#chainBox').html(h||'<div class="hint">沒有符合的 BOM。</div>');
  $('#moreBox').html(MORE?'<button class="btn-o" id="btnMore">再載入更多（已列出 '+CHAIN.length+' 張；BOM 共 '+CHAINTOTAL+' 張，依條件往後掃描）</button>':(CHAIN.length?'<span class="hint">已列出全部符合條件的 BOM（共 '+CHAIN.length+' 張）</span>':''));
  refreshSel();
}
function search(reset){
  if(reset){OFFSET=0;CHAIN=[];SELQ={};}
  get({action:'chain',part:$('#fPart').val(),bom:$('#fBom').val(),client:$('#fClient').val(),process_type_id:$('#fType').val(),
       urgent:$('#fUrg').val(),qc_mode:$('#fQc').val(),limit:30,offset:OFFSET},function(r){
    $.each(r.rows,function(i,b){CHAIN.push(b);});CHAINTOTAL=r.total;OFFSET=r.next_offset;MORE=!!r.more;renderChain();});
}
$('#btnSearch').on('click',function(){search(true);});
$('#fPart,#fBom,#fClient').on('keydown',function(e){if(e.which===13)search(true);});
$(document).on('click','#btnMore',function(){search(false);});
$(document).on('click','.chain .pc',function(){
  if($(this).hasClass('s-skip'))return;
  var fid=+$(this).data('fid'),c=findChip(fid);
  if(c&&c.state==='done'&&!$(this).hasClass('sel')&&!confirm('此製程已全數發單且回廠。只有「後段關卡報廢補料」或「訂單追加（須先修改 BOM 數量）」才需要重發，確定要選取？'))return;
  $(this).toggleClass('sel');refreshSel();
});
$(document).on('click','[data-sug]',function(){var bi=+$(this).data('sug');$('.bomcard[data-bi='+bi+'] .pc.sug').addClass('sel');refreshSel();});
function findChip(fid){var r=null;$.each(CHAIN,function(bi,b){$.each(b.chain,function(i,c){if(c.bom_ing_fid===fid)r={b:b,c:c,bi:bi};});});return r?r.c:null;}
function selOfBom(bi){var out=[];$('.bomcard[data-bi='+bi+'] .pc.sel').each(function(){out.push(findChip(+$(this).data('fid')));});return out;}
/* 發單量規則：同一張 BOM 選多個製程共用一個量；預設＝可發量（done=0）；第一關客供料不設上限 */
function bomLimit(sel){
  var lim=null,done=false,first=false;
  $.each(sel,function(i,c){
    if(c.state==='done')done=true;
    if(c.is_customer_material||c.avail===null){first=true;return;}
    lim=(lim===null)?c.avail:Math.min(lim,c.avail);
  });
  if(done)lim=0;
  return {lim:lim,done:done,first:first};
}
function refreshSel(){
  var n=0,nb=0;
  $('.bomcard').each(function(){
    var bi=+$(this).data('bi'),sel=selOfBom(bi),slot=$(this).find('.qtyslot');slot.empty();
    if(!sel.length)return;n+=sel.length;nb++;
    var L=bomLimit(sel),b=CHAIN[bi],def=(L.lim===null)?b.sqty:L.lim;
    if(SELQ[bi]===undefined||!SELQ[bi].edited)SELQ[bi]={v:def,edited:false};
    var why=L.done?'已全數發單且回廠，預設 0。只有後段報廢補料或訂單追加（須先修改 BOM 數量）才需要重新發。'
      :(L.lim===null?'客供料／第一關：不設可發上限，預設帶 BOM 總量':'＝ 上一關已驗合格數－本製程已發量 ＝ '+fmtQ(L.lim)+'（可修改）');
    slot.html('<div class="qtybox"><b>此次發單量</b> <input type="number" min="0" step="any" class="qtyin" value="'+SELQ[bi].v+'"> 件　<span class="hint">'+why
      +'</span>　<span class="hint">已選：'+$.map(sel,function(c){return esc(snL(c.bom_sn)+' '+c.name);}).join('、')+'（同一發單量）</span><div class="warn qerr"></div></div>');
    checkQty(slot.find('.qtyin'),L);
  });
  $('#selinfo').html('已選 <b>'+n+'</b> 個製程（<b>'+nb+'</b> 張 BOM）');
}
function checkQty($i,L){
  var v=num($i.val()),er=$i.closest('.qtybox').find('.qerr');$i.removeClass('bad');er.text('');
  if(v<0){$i.addClass('bad');er.text('發單量不可為負數');return false;}
  if(L.lim!==null&&v>L.lim+1e-9){
    if(L.first){er.text('提醒：高於可發量 '+fmtQ(L.lim)+'（客供料以實收為準，可繼續）');return true;}
    $i.addClass('bad');er.text(L.done?'此製程已全數發單，若要追加請先到 BOM 修改數量；目前最多只能發 0 件':'超過可發量：最多只能發 '+fmtQ(L.lim)+' 件');return false;}
  if(v===0){er.text('發單量為 0 時無法加入發單清單');return false;}
  return true;
}
$(document).on('input','.qtyin',function(){
  var bi=+$(this).closest('.bomcard').data('bi');SELQ[bi]={v:$(this).val(),edited:true};checkQty($(this),bomLimit(selOfBom(bi)));
});
$(document).on('focus click','.qtyin',function(){var t=this;setTimeout(function(){t.select();},0);});

$('#btnToList').on('click',function(){
  var add=[],bad='';
  $('.bomcard').each(function(){
    var bi=+$(this).data('bi'),sel=selOfBom(bi);if(!sel.length)return;
    var b=CHAIN[bi],L=bomLimit(sel),inp=$(this).find('.qtyin'),v=num(inp.val());
    if(!checkQty(inp,L)){bad=bad||('「'+b.bom+'」發單量不合法，請修正紅框');return;}
    $.each(sel,function(i,c){
      if(LINES.some(function(x){return x.fid===c.bom_ing_fid;}))return;
      add.push({fid:c.bom_ing_fid,bom:b.bom,part:b.part_no,client:b.client,name:c.name,sn:c.bom_sn,ptype:c.ptype_id,ptypeName:c.ptype,
        qty:v,lim:L.lim,first:L.first,maker_no:c.maker_no||'',maker:c.maker||'',flag:false,remark:[c.remark,c.remark2].filter(function(x){return x;}).join(' '),due:'',sel:false});
    });
  });
  if(bad){alert(bad);return;}
  if(!add.length){alert('請先點選要發單的製程（已在清單內的不會重複加入）。');return;}
  LINES=LINES.concat(add);
  $.getJSON(API,{action:'due_date',from:$('#issueDate').val()||META.today,days:META.due_days},function(r){
    $.each(LINES,function(i,l){if(!l.due)l.due=r.due;});go('p2');});
});

/* ───── ② 發單清單 ───── */
var COMMON={};
function ensureCommon(cb){
  var need=[];$.each(LINES,function(i,l){if(l.ptype&&COMMON[l.ptype]===undefined&&need.indexOf(l.ptype)<0)need.push(l.ptype);});
  if(!need.length)return cb();
  get({action:'common_makers',ptypes:need.join(',')},function(r){$.each(need,function(i,t){COMMON[t]=r.by_type[t]||[];});cb();});
}
function renderTray(){
  var seen={},h='';
  $.each(LINES,function(i,l){$.each(COMMON[l.ptype]||[],function(j,m){if(seen[m.maker_id_no])return;seen[m.maker_id_no]=1;
    h+='<span class="vchip" data-no="'+esc(m.maker_id_no)+'" data-nm="'+esc(m.maker_id)+'" title="'+esc(m.items||'')+'">'+esc(m.maker_id)+'<small>'+esc(l.ptypeName)+'常用'+(m.items?'・'+esc(m.items.length>14?m.items.slice(0,14)+'…':m.items):'')+'</small></span>';});});
  $.each(EXTRA,function(i,m){if(seen[m.no])return;seen[m.no]=1;h+='<span class="vchip" data-no="'+esc(m.no)+'" data-nm="'+esc(m.nm)+'">'+esc(m.nm)+'<small>已加入</small></span>';});
  h+='<span class="vchip" id="mkOther" style="border-style:dashed;">＋其他廠商…</span>';
  $('#tray').html(h);
  $('#tray .vchip[data-no]').each(function(){if(ARMED&&String($(this).data('no'))===ARMED.no)$(this).addClass('armed');});
  $('#armHint').text(ARMED?'已選廠商「'+ARMED.nm+'」：直接點下方的列（或勾選的列再點廠商）即可指派，再點一次廠商取消。':'');
}
function isCommon(l,no){var f=false;$.each(COMMON[l.ptype]||[],function(i,m){if(m.maker_id_no===no)f=true;});return f;}
function renderList(){
  ensureCommon(function(){
    renderTray();
    var h='<tr><th></th><th><input type="checkbox" id="selAll"></th><th>BOM／料號</th><th>製程</th><th>廠商</th><th class="num">發包數量</th><th>交期</th><th>備註（BOM_ING）</th><th></th></tr>';
    var order=LINES.map(function(l,i){return i;}).sort(function(a,b){var A=LINES[a],B=LINES[b];return (A.ptypeName||'').localeCompare(B.ptypeName||'')||A.bom.localeCompare(B.bom)||A.sn-B.sn;});
    var last=null;
    $.each(order,function(k,i){var l=LINES[i];
      if(l.ptypeName!==last){last=l.ptypeName;h+='<tr class="grp"><td colspan="9">▼ '+esc(l.ptypeName||'未分類')+'（相同大類排在一起，預設同一廠商）</td></tr>';}
      var tag=l.maker_no?'<span class="vtag'+(l.flag?' x':'')+'">'+esc(l.maker)+(l.flag?'　非此製程廠商':'')+'</span>':'<span class="vtag none">尚未指定</span>';
      h+='<tr draggable="true" data-i="'+i+'"><td>⠿</td><td><input type="checkbox" class="rowSel"'+(l.sel?' checked':'')+'></td><td>'+esc(l.bom)+' '+esc(l.part)+'</td><td>'+esc(snL(l.sn)+' '+l.name)+'</td><td>'+tag+'</td>'
        +'<td class="num"><input type="number" min="0" step="any" class="rowQty" value="'+esc(l.qty)+'"><div class="warn rowErr"></div></td>'
        +'<td><input type="date" class="rowDue" value="'+esc(l.due)+'"></td><td><input class="rm rowRm" maxlength="200" value="'+esc(l.remark)+'"></td>'
        +'<td><button class="btn-o sm rowDel">移除</button></td></tr>';});
    $('#listTbl').html(LINES.length?h:'<tr><td class="hint">發單清單是空的，請回「① 挑製程」加入。</td></tr>');
    $('#listTbl .rowQty').each(function(){validRow($(this));});
    $('#listInfo').html('共 <b>'+LINES.length+'</b> 列，<b>'+groupCount()+'</b> 間廠商'+(LINES.some(function(l){return !l.maker_no;})?'　<span class="warn">有列尚未指定廠商</span>':''));
  });
}
function groupCount(){var m={};$.each(LINES,function(i,l){if(l.maker_no)m[l.maker_no]=1;});return Object.keys(m).length;}
function validRow($q){
  var i=+$q.closest('tr').data('i'),l=LINES[i],v=num($q.val()),er=$q.next('.rowErr');l.qty=$q.val();$q.removeClass('bad');er.text('');
  if(v<=0){$q.addClass('bad');er.text('需大於 0');return false;}
  if(l.lim!==null&&!l.first&&v>l.lim+1e-9){$q.addClass('bad');er.text('超過可發 '+fmtQ(l.lim));return false;}
  if(l.lim!==null&&l.first&&v>l.lim+1e-9){er.text('高於 BOM 可發量（客供料提醒）');}
  return true;
}
$(document).on('input','.rowQty',function(){validRow($(this));});
$(document).on('focus click','.rowQty',function(){var t=this;setTimeout(function(){t.select();},0);});
$(document).on('input','.rowDue',function(){LINES[+$(this).closest('tr').data('i')].due=$(this).val();});
$(document).on('input','.rowRm',function(){LINES[+$(this).closest('tr').data('i')].remark=$(this).val();});
$(document).on('change','.rowSel',function(){LINES[+$(this).closest('tr').data('i')].sel=this.checked;});
$(document).on('change','#selAll',function(){var c=this.checked;$.each(LINES,function(i,l){l.sel=c;});$('.rowSel').prop('checked',c);});
$(document).on('click','.rowDel',function(){LINES.splice(+$(this).closest('tr').data('i'),1);renderList();});
$('#issueDate').on('change',function(){ // 發單日改了，交期跟著重算（只重算尚未手動改過的？一律重算，提示使用者）
  var d=$(this).val();if(!d)return;
  $.getJSON(API,{action:'due_date',from:d,days:META.due_days},function(r){$.each(LINES,function(i,l){l.due=r.due;});if($('#p2').hasClass('on'))renderList();});
});
function assign(rows,no,nm){$.each(rows,function(i,l){l.maker_no=no;l.maker=nm;l.flag=!isCommon(l,no)&&!l._other;});}
$(document).on('click','#tray .vchip[data-no]',function(){
  var no=String($(this).data('no')),nm=String($(this).data('nm'));
  var rows=LINES.filter(function(l){return l.sel;});
  if(rows.length){assign(rows,no,nm);ARMED=null;renderList();return;}
  ARMED=(ARMED&&ARMED.no===no)?null:{no:no,nm:nm};renderList();   // 沒勾列：先選廠商，之後逐列點下去指派
});
$(document).on('click','#listTbl tr[data-i]',function(e){
  if(!ARMED||$(e.target).is('input,button,a'))return;
  assign([LINES[+$(this).data('i')]],ARMED.no,ARMED.nm);renderList();
});
var dragI=null;
$(document).on('dragstart','#listTbl tr[draggable]',function(){dragI=+$(this).data('i');});
$(document).on('dragover','#tray .vchip[data-no]',function(e){e.preventDefault();$(this).addClass('drop');});
$(document).on('dragleave','#tray .vchip',function(){$(this).removeClass('drop');});
$(document).on('drop','#tray .vchip[data-no]',function(e){e.preventDefault();$(this).removeClass('drop');if(dragI===null)return;
  var rows=LINES[dragI].sel?LINES.filter(function(l){return l.sel;}):[LINES[dragI]];assign(rows,String($(this).data('no')),String($(this).data('nm')));dragI=null;renderList();});
$(document).on('click','#mkOther',function(){openM('mkMask');$('#mkQ').val('').focus();loadMk('');});
var mkT=null;$('#mkQ').on('input',function(){clearTimeout(mkT);var q=$(this).val();mkT=setTimeout(function(){loadMk(q);},250);});
function loadMk(q){
  var pt=0;$.each(LINES,function(i,l){if(l.sel&&!pt)pt=l.ptype;});if(!pt&&LINES.length)pt=LINES[0].ptype;
  get({action:'makers',q:q,ptype:pt},function(r){
    var h='';$.each(r.makers,function(i,m){h+='<div style="padding:4px 0;border-bottom:1px solid #EADFC8;display:flex;gap:8px;align-items:baseline;"><button class="btn-o sm mkPick" data-no="'+esc(m.maker_id_no)+'" data-nm="'+esc(m.maker_id)+'">選用</button><b style="white-space:nowrap;">'+esc(m.maker_id)+'</b><span class="hint" style="white-space:nowrap;">'+esc(m.maker_id_no)+(m.internal?'　廠內':'')+(m.used_for_type>0?'　此製程大類用過 '+m.used_for_type+' 次':'')+'</span><span style="color:#8A5A2B;font-size:12px;">'+esc(m.items||'')+'</span></div>';});
    $('#mkList').html(h||'<span class="hint">查無廠商</span>');});
}
$(document).on('click','.mkPick',function(){
  var no=String($(this).data('no')),nm=String($(this).data('nm'));
  if(!EXTRA.some(function(m){return m.no===no;}))EXTRA.push({no:no,nm:nm});   // 挑過的廠商加進廠商盤，之後免再搜尋
  var rows=LINES.filter(function(l){return l.sel;});
  $('#mkMask').removeClass('on');
  if(rows.length){var fl=!isCommon(rows[0],no);assign(rows,no,nm);if(fl)$.each(rows,function(i,l){l.flag=!isCommon(l,no);});ARMED=null;}
  else{ARMED={no:no,nm:nm};}   // 沒勾列：先選好廠商，再逐列點下去指派
  renderList();
});
$('#btnToSlips').on('click',function(){
  if(!LINES.length){alert('發單清單是空的');return;}
  var bad='';$.each(LINES,function(i,l){if(!bad){if(!l.maker_no)bad='「'+l.bom+' '+l.name+'」尚未指定廠商';else if(num(l.qty)<=0)bad='「'+l.bom+' '+l.name+'」發單量必須大於 0';else if(l.lim!==null&&!l.first&&num(l.qty)>l.lim+1e-9)bad='「'+l.bom+' '+l.name+'」發單量超過可發量 '+fmtQ(l.lim);}});
  if(bad){alert(bad);return;}
  go('p3');
});

/* ───── ③ 分張預覽／發單／列印 ───── */
function renderFmtSel(){var h='';$.each(META.formats,function(i,f){h+='<option value="'+f.format_id+'"'+(f.is_default==1?' selected':'')+'>'+esc(f.name)+(f.is_default==1?'（預設）':'')+'</option>';});$('#fmtSel').html(h);fmtChanged();}
function curFmt(){var id=+$('#fmtSel').val(),r=null;$.each(META.formats,function(i,f){if(+f.format_id===id)r=f;});return r||META.formats[0];}
function fmtChanged(){var f=curFmt();if(f)$('#twoPart').prop('checked',!!+f.config.two_part);}
$('#fmtSel').on('change',function(){fmtChanged();renderSlipPrev();});
function groups(){var g={};$.each(LINES,function(i,l){(g[l.maker_no]=g[l.maker_no]||{no:l.maker_no,name:l.maker,lines:[]}).lines.push(l);});return $.map(g,function(v){return v;});}
function renderSlipPrev(){
  var f=curFmt(),h='';
  $.each(groups(),function(i,g){
    var pay={slip:{slip_no:'（確認發單後配號）',maker_id:g.name,maker_addr:'（取自廠商主檔）',maker_tel:'',issue_date:$('#issueDate').val(),issued_by_name:META.perms.name,
      lines:$.map(g.lines,function(l,k){return {sort_no:k+1,bom:l.bom,part_no:l.part,client_name:l.client,process_name:l.name,qty:l.qty,due_date:l.due,remark:l.remark};})},
      config:f.config,company:'（本公司全名）',form_name:META.asdoc?META.asdoc.doc_name:'外發加工單',doc_no:META.asdoc?META.asdoc.doc_no:'',columns:META.columns};
    h+='<div class="slipbox"><div class="hd"><span>'+esc(g.name)+'　'+g.lines.length+' 列／合計 '+fmtQ(g.lines.reduce(function(a,l){return a+num(l.qty);},0))+' 件</span></div><div class="slip-prev">'+slipBody(pay,0,1,false)+'</div></div>';
  });
  $('#slipPrev').html(h||'<div class="hint">沒有資料</div>');
}
function doIssue(thenPrint){
  var items=$.map(LINES,function(l){return {bom_ing_fid:l.fid,qty:num(l.qty),maker_no:l.maker_no,due_date:l.due,remark:l.remark};});
  if(!confirm('確認發單？將寫入 '+items.length+' 個製程的發包資料（發包日、廠商、狀態轉加工中），依廠商分成 '+groupCount()+' 張單。'))return;
  $('#btnIssueOnly,#btnIssuePrint').prop('disabled',true);
  $.post(API,{action:'issue',csrf:CSRF,items:JSON.stringify(items),issue_date:$('#issueDate').val(),format_id:curFmt().format_id,two_part:$('#twoPart').is(':checked')?1:0},function(r){
    $('#btnIssueOnly,#btnIssuePrint').prop('disabled',false);
    if(!r.ok){alert(r.error);return;}
    ISSUED=r.slips;LINES=[];CHAIN=[];
    var h='<div class="od-card"><h4>已發單 '+r.slips.length+' 張</h4>';
    $.each(r.slips,function(i,s){h+='<div class="fld"><b>'+esc(s.slip_no)+'</b> '+esc(s.maker)+'　'+s.lines+' 列　<button class="btn-o sm pr1" data-id="'+s.slip_id+'">列印此張</button></div>';});
    h+='<button class="btn-o pri" id="prAll">全部列印（'+r.slips.length+' 張，每廠商換紙）</button></div>';
    $('#issuedBox').html(h);$('#slipPrev').empty();
    if(thenPrint)printSlips($.map(r.slips,function(s){return s.slip_id;}));
  },'json').fail(function(x){$('#btnIssueOnly,#btnIssuePrint').prop('disabled',false);xhrErr(x);});
}
$('#btnIssueOnly').on('click',function(){doIssue(false);});
$('#btnIssuePrint').on('click',function(){doIssue(true);});
$(document).on('click','.pr1',function(){printSlips([+$(this).data('id')]);});
$(document).on('click','#prAll',function(){printSlips($.map(ISSUED,function(s){return s.slip_id;}));});

/* ───── 列印版（單頁一廠商；列數太多才續頁） ───── */
function slipBody(p,pageIdx,pages,forPrint,copyLabel){
  var s=p.slip,cfg=p.config,cols=cfg.cols,C=p.columns,lines=s.lines,per=cfg.rows_per_page;
  var chunks=[];for(var i=0;i<lines.length;i+=per)chunks.push(lines.slice(i,i+per));if(!chunks.length)chunks=[[]];
  var h='';
  $.each(chunks,function(ci,ch){
    var last=ci===chunks.length-1;
    h+='<div class="pg'+(forPrint?'':' pgprev')+'">';
    h+='<div class="t1">'+esc(p.company)+'</div><div class="t2">'+esc(p.form_name)+(copyLabel?'　<span class="cp">'+esc(copyLabel)+'</span>':'')+'</div>';
    h+='<table class="hd"><tr><td class="vn"><div class="vname">'+esc(s.maker_id)+'</div><div>'+(s.maker_addr?'地址：'+esc(s.maker_addr):'')+'</div><div>'+(s.maker_tel?'電話：'+esc(s.maker_tel):'')+'</div></td>'
      +'<td class="mt">單號：'+esc(s.slip_no)+'<br>發單日：'+esc(fd(s.issue_date))+'　發單人：'+esc(s.issued_by_name||'')+(chunks.length>1?'<br>第 '+(ci+1)+' / '+chunks.length+' 頁（同廠商續頁）':'')+'</td></tr></table>';
    h+='<table class="ln"><tr>';$.each(cols,function(k,c){h+='<th>'+esc(C[c])+'</th>';});h+='</tr>';
    $.each(ch,function(k,l){h+='<tr>';$.each(cols,function(j,c){var v='';
      if(c==='no')v=l.sort_no;else if(c==='bom')v=l.bom;else if(c==='part_no')v=l.part_no;else if(c==='drawing_no')v=l.drawing_no||'';else if(c==='client')v=l.client_name;
      else if(c==='process')v=l.process_name;else if(c==='qty')v=fmtQ(l.qty);else if(c==='due')v=l.due_date?fd(l.due_date).slice(5):'';else if(c==='remark')v=l.remark;
      h+='<td'+(c==='qty'?' style="text-align:right"':'')+'>'+esc(v)+'</td>';});h+='</tr>';});
    h+='</table>';
    if(last){
      var tot=0;$.each(lines,function(i,l){tot+=num(l.qty);});
      h+='<div class="ft">'+(cfg.show_total?'合計 '+fmtQ(tot)+' 件　　':'')+(cfg.show_sign?'收貨簽收：__________　　':'')+'</div>';
      if(cfg.footer_text)h+='<div class="ft2">'+esc(cfg.footer_text)+'</div>';
    }
    h+='<div class="bt"><span>'+(chunks.length>1?'第 '+(ci+1)+' 頁／共 '+chunks.length+' 頁':'')+'</span><span>'+esc(p.doc_no||'')+'</span></div></div>';
  });
  return h;
}
function paperCss(cfg){
  if(cfg.paper==='a4p')return '@page{size:210mm 297mm;margin:0}.pg{width:210mm;height:297mm;padding:12mm 12mm 14mm;}';
  if(cfg.paper==='a5l')return '@page{size:210mm 148mm;margin:0}.pg{width:210mm;height:148mm;padding:8mm 10mm 10mm;}';
  return '@page{size:241mm 140mm;margin:0}.pg{width:241mm;height:140mm;padding:6mm 9mm 8mm;}'; // 中一刀 9.5"×5.5"
}
var PCSS='body{margin:0;font-family:"MingLiU","Noto Sans TC","Microsoft JhengHei",monospace;color:#000;font-size:12px}.pg{box-sizing:border-box;page-break-after:always;position:relative;overflow:hidden}'
 +'.t1{text-align:center;font-size:16px;font-weight:bold}.t2{text-align:center;font-size:14px;margin-bottom:4px}.cp{font-size:11px;border:1px solid #000;padding:0 4px}'
 +'table{border-collapse:collapse;width:100%}table.hd td{vertical-align:top;border:0;padding:0 2px}.vname{font-size:15px;font-weight:bold}td.mt{text-align:right;width:45%}'
 +'table.ln{margin-top:4px}table.ln th,table.ln td{border:1px solid #000;padding:1px 4px;font-size:12px}table.ln th{font-weight:bold}'
 +'.ft{margin-top:4px}.ft2{font-size:11px;margin-top:2px}.bt{position:absolute;left:9mm;right:9mm;bottom:4mm;display:flex;justify-content:space-between;font-size:10px}';
function printSlips(ids){
  var w=window.open('','_blank');if(!w){alert('瀏覽器擋住了列印視窗，請允許本站的彈出視窗後再按一次。');return;}
  w.document.write('<html><body style="font-family:sans-serif">準備列印中…</body></html>');
  var docs=[],idx=0;
  (function next(){
    if(idx>=ids.length){finish();return;}
    var id=ids[idx++];
    $.getJSON(API,{action:'slip_print',slip_id:id},function(r){
      if(!r.ok){w.close();alert(r.error);return;}
      docs.push(r);
      $.post(API,{action:'print_record',csrf:CSRF,slip_id:id},function(){},'json');
      next();
    }).fail(function(x){w.close();xhrErr(x);});
  })();
  function finish(){
    var body='',cfg0=docs[0].config;
    $.each(docs,function(i,p){
      var two=!!+p.slip.two_part;
      body+=slipBody(p,0,1,true,two?'廠商聯':'');
      if(two)body+=slipBody(p,0,1,true,'存根聯');
    });
    w.document.open();
    w.document.write('<!DOCTYPE html><html><head><meta charset="utf-8"><title>外發加工單</title><style>'+PCSS+paperCss(cfg0)+'.pg:last-of-type{page-break-after:auto}</style></head><body>'+body+'<scr'+'ipt>window.onload=function(){setTimeout(function(){window.print();},250);};</scr'+'ipt></body></html>');
    w.document.close();
  }
}

/* ───── ④ 格式設定（管理員） ───── */
function loadFmtTab(){if(!META.perms.canAdmin)return;renderFmtList();}
function renderFmtList(){
  var h='';$.each(META.formats,function(i,f){h+='<div class="fld"><span class="chip'+(FMT&&+FMT.format_id===+f.format_id?' on':'')+'" data-fid="'+f.format_id+'">'+esc(f.name)+(f.is_default==1?' ★預設':'')+'</span></div>';});
  $('#fmtList').html(h);
}
$(document).on('click','#fmtList .chip',function(){var id=+$(this).data('fid');$.each(META.formats,function(i,f){if(+f.format_id===id)editFmt($.extend(true,{},f));});});
$('#fmtNew').on('click',function(){editFmt({format_id:0,name:'',is_default:0,config:{paper:'continuous',rows_per_page:8,cols:['no','bom','part_no','process','qty','due','remark'],footer_text:'',two_part:0,show_total:1,show_sign:1},maker_ids:[]});});
function editFmt(f){
  FMT=f;renderFmtList();$('#fmtTitle').text(f.format_id?'編輯：'+f.name:'新增格式');
  var C=META.columns,cfg=f.config,h='<div class="fld"><b>格式名稱</b><input id="fmN" class="od-inp" size="26" maxlength="60" value="'+esc(f.name)+'"></div>'
   +'<div class="fld"><b>表頭大標題</b><span class="hint">自動帶入本公司全名（客戶主檔「本公司」），不可改</span></div>'
   +'<div class="fld"><b>表單名稱</b><span class="hint">取自綁定的 AS 文件（上方「綁定 AS 文件」）</span></div>'
   +'<div class="fld"><b>紙張</b><select id="fmP" class="od-inp"><option value="continuous">中一刀 9.5"×5.5"（點陣）</option><option value="a4p">A4 直式</option><option value="a5l">A5 橫式</option></select></div>'
   +'<div class="fld"><b>每頁列數</b><input id="fmR" type="number" min="3" max="40" class="od-inp" style="width:70px;" value="'+cfg.rows_per_page+'"> <span class="hint">同廠商超過才續頁，每頁都印表頭</span></div>'
   +'<div class="fld"><b>欄位（勾選＋排序）</b><div id="fmCols"></div></div>'
   +'<div class="fld"><b>表尾文字</b><input id="fmF" class="od-inp" size="46" maxlength="300" value="'+esc(cfg.footer_text)+'"></div>'
   +'<div class="fld"><b>其他</b><label><input type="checkbox" id="fmT"'+(cfg.show_total?' checked':'')+'> 印合計</label> <label><input type="checkbox" id="fmS"'+(cfg.show_sign?' checked':'')+'> 印收貨簽收欄</label> <label><input type="checkbox" id="fm2"'+(+cfg.two_part?' checked':'')+'> 預設印成兩聯</label></div>'
   +'<div style="margin-top:10px;"><button class="btn-o pri" id="fmSave">儲存格式</button> '+(f.format_id&&!+f.is_default?'<button class="btn-o" id="fmDef">設為預設</button> <button class="btn-o" id="fmDel">刪除</button>':'')+'</div><div class="err" id="fmErr"></div>';
  $('#fmtForm').html(h);$('#fmP').val(cfg.paper);renderFmCols();
}
function renderFmCols(){
  var C=META.columns,cols=FMT.config.cols,h='';
  $.each(cols,function(i,k){h+='<span class="chip on">'+esc(C[k])+' <a href="#" class="fmUp" data-k="'+k+'">↑</a> <a href="#" class="fmDn" data-k="'+k+'">↓</a> <a href="#" class="fmRm" data-k="'+k+'">×</a></span>';});
  h+='<br>';$.each(C,function(k,t){if(cols.indexOf(k)<0)h+='<span class="chip fmAdd" data-k="'+k+'">＋'+esc(t)+'</span>';});
  $('#fmCols').html(h);
}
$(document).on('click','.fmUp,.fmDn,.fmRm',function(e){e.preventDefault();var k=$(this).data('k'),c=FMT.config.cols,i=c.indexOf(k);
  if($(this).hasClass('fmRm')){if(c.length<=1)return;c.splice(i,1);}else if($(this).hasClass('fmUp')&&i>0){c.splice(i,1);c.splice(i-1,0,k);}else if($(this).hasClass('fmDn')&&i<c.length-1){c.splice(i,1);c.splice(i+1,0,k);}
  renderFmCols();});
$(document).on('click','.fmAdd',function(){FMT.config.cols.push($(this).data('k'));renderFmCols();});
$(document).on('click','#fmSave',function(){
  var n=$.trim($('#fmN').val());if(!n){$('#fmErr').text('請輸入格式名稱');return;}
  var cfg={paper:$('#fmP').val(),rows_per_page:+$('#fmR').val()||8,cols:FMT.config.cols,footer_text:$('#fmF').val(),two_part:$('#fm2').is(':checked')?1:0,show_total:$('#fmT').is(':checked')?1:0,show_sign:$('#fmS').is(':checked')?1:0};
  post({action:'format_save',format_id:FMT.format_id,name:n,config:JSON.stringify(cfg),maker_ids:JSON.stringify(FMT.maker_ids||[])},function(r){META.formats=r.formats;renderFmtSel();FMT=null;renderFmtList();$('#fmtForm').html('<span class="hint">已儲存。</span>');});
});
$(document).on('click','#fmDef',function(){post({action:'format_default',format_id:FMT.format_id},function(r){META.formats=r.formats;renderFmtSel();FMT=null;renderFmtList();$('#fmtForm').html('<span class="hint">已設為預設。</span>');});});
$(document).on('click','#fmDel',function(){if(!confirm('刪除此格式？（已印過的單據不受影響）'))return;post({action:'format_delete',format_id:FMT.format_id},function(r){META.formats=r.formats;renderFmtSel();FMT=null;renderFmtList();$('#fmtForm').html('<span class="hint">已刪除。</span>');});});

/* ───── ⑤ 紀錄 ───── */
function loadRecs(){
  get({action:'slips',from:$('#rFrom').val(),to:$('#rTo').val(),kw:$('#rKw').val()},function(r){
    var h='<tr><th>單號</th><th>廠商</th><th>內容</th><th>發單人</th><th>發單日</th><th class="num">列印次數</th><th>最後列印</th><th></th></tr>';
    $.each(r.slips,function(i,s){h+='<tr><td>'+esc(s.slip_no)+'</td><td>'+esc(s.maker_id)+'</td><td>'+s.line_count+' 列／'+fmtQ(s.total_qty)+' 件</td><td>'+esc(s.issued_by_name)+'</td><td>'+esc(fd(s.issue_date))+'</td><td class="num">'+s.print_count+'</td><td>'+esc(s.last_printed_at||'尚未列印')+'</td>'
      +'<td>'+(META.perms.canIssue?'<button class="btn-o sm pr1" data-id="'+s.slip_id+'">'+(+s.print_count>0?'重印':'列印')+'</button>':'')+'</td></tr>';});
    $('#recTbl').html(r.slips.length?h:'<tr><td class="hint">沒有符合的單據</td></tr>');});
}
$('#btnRecs').on('click',loadRecs);
})();
<?php endif; ?>
</script>
</body>
</html>
