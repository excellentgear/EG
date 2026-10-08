<?php
/**
 * 詢價單 —— 業務詢外包加工／生管詢外包材料／採購詢相關採購　三部門共用（2026-10-08 建立）
 * 格式參照 FOR CODEING 說明文件/詢價單.xlsx（AS 3-OB-01-05）。
 * 共用邏輯一律在 src/common/inquiry_lib.php，資料介面 src/store/Inquiry_API.php。
 */
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/pm/inquiry_sheet.php";
    header("Location:../../index.php");
    exit;
}
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/inquiry_lib.php';

$db = (new DBConnection())->getPDO();
inq_ensure_schema($db);
$uid = (int)($_SESSION['id'] ?? 0);
$perms = inq_perms($db, $uid);
if (empty($_SESSION['inq_csrf'])) $_SESSION['inq_csrf'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['inq_csrf'];
$roleLabel = $perms['isAdmin'] ? '管理者' : ($perms['canAdmin'] ? '詢價單管理員' : ($perms['canCreate'] ? '可建立詢價（' . implode('、', array_map('inq_dept_label', $perms['myDepts'])) . '）' : ($perms['canView'] ? '檢視' : '無權限')));
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>詢價單</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <style>
        #sidebar-menu { visibility: hidden; }
        .right_col .page-title { margin:8px 0 4px; overflow:hidden; clear:both; }
        .page-help-btn, .page-set-btn { height:30px; font-size:13px; padding:0 12px; border:1px solid #d98a33; border-radius:15px;
            background:#F0A24B; color:#fff; cursor:pointer; margin-left:8px; }
        .page-help-btn:hover, .page-set-btn:hover { background:#d98a33; }
        @media print { .page-help-btn, .page-set-btn { display:none !important; } }
        .help-doc { font-size:13px; color:#5b3a1e; line-height:1.75; }
        .help-doc h4 { color:#8A5A2B; border-bottom:2px solid #F7E0BD; padding-bottom:3px; margin:14px 0 6px; font-size:15px; }
        .help-doc h4:first-child { margin-top:0; }
        .help-doc b { color:#8A5A2B; }
        .iq-role { margin-left:auto; font-size:13px; color:#5b3a1e; background:#F7E0BD; border-radius:12px; padding:4px 12px; }
        .iq-toolbar { display:flex; flex-wrap:wrap; gap:6px; align-items:center; clear:both;
            border:1.5px solid #E8D5B5; border-radius:8px; padding:8px 10px; margin-bottom:10px; background:#FDF8EF; }
        .iq-toolbar label { margin:0; font-size:13px; color:#5b3a1e; }
        .iq-toolbar select, .iq-toolbar input, .iq-toolbar button {
            height:30px; font-size:13px; line-height:1; padding:0 10px; border:1px solid #D8BE93;
            border-radius:4px; background:#fff; color:#5b3a1e; }
        .iq-toolbar button { cursor:pointer; }
        .iq-toolbar button:hover { background:#F7E0BD; }
        .btn-warm { background:#F0A24B !important; color:#fff !important; border-color:#d98a33 !important; }
        .btn-warm:hover { background:#d98a33 !important; }
        .btn-danger-o { background:#DD5138 !important; color:#fff !important; border-color:#C4442D !important; }
        .btn-danger-o:hover { background:#C4442D !important; }
        .iq-table-wrap { overflow-x:auto; }
        /* 每個詢價案是一張獨立的卡片，標頭資訊＋子單清單全部直接展開顯示（內容量不大，不需要點了才看得到）；
           卡片之間有明顯間距與邊框，一眼就看得出這是哪一案、誰開的，不會混在一起。 */
        .iq-card { border:1.5px solid #E8D5B5; border-radius:8px; background:#fff; margin-bottom:14px; overflow:hidden; }
        .iq-card-hd { display:flex; gap:14px; padding:12px 14px; background:#FDF8EF; border-bottom:1px solid #EADFC8; flex-wrap:wrap; }
        .iq-card-id { flex:none; font-size:20px; font-weight:bold; color:#b5762a; align-self:center; min-width:38px; }
        .iq-card-cat { flex:none; align-self:center; font-size:12.5px; font-weight:bold; color:#fff; background:#8A5A2B;
            border-radius:4px; padding:4px 11px; white-space:nowrap; }
        .iq-card-main { flex:1 1 320px; min-width:260px; }
        .iq-card-title { font-size:14.5px; font-weight:bold; color:#5b3a1e; margin-bottom:3px; }
        .iq-card-title .dt { font-weight:normal; color:#8a6d45; margin-left:8px; }
        .iq-card-row { font-size:12.5px; color:#6b5535; margin-top:2px; line-height:1.6; }
        .iq-card-row b { color:#8A5A2B; font-weight:bold; }
        .iq-card-ops { flex:none; display:flex; flex-wrap:wrap; align-content:flex-start; gap:6px; align-self:center; }
        .iq-card-ops button { height:28px; padding:0 11px; font-size:12px; border:1px solid #D8BE93; background:#fff;
            color:#8A5A2B; border-radius:4px; cursor:pointer; white-space:nowrap; }
        .iq-card-ops button:hover { background:#F7E0BD; }
        .iq-card-ops button.danger { color:#DD5138; border-color:#E8BDB3; }
        .iq-card-ops button.danger:hover { background:#FBEAE6; }
        .iq-card-body { padding:10px 14px 12px; }
        table.iq-d { width:100%; border-collapse:collapse; font-size:12.5px; }
        table.iq-d th, table.iq-d td { border:1px solid #EADFC8; padding:6px 8px; text-align:center; }
        table.iq-d th { background:#FDF3E3; color:#8A5A2B; }
        table.iq-d .opcell { display:flex; flex-wrap:wrap; justify-content:center; gap:5px; }
        .iq-op { color:#b5762a; cursor:pointer; white-space:nowrap; padding:2px 8px; border:1px solid #D8BE93; border-radius:3px; background:#fff; }
        .iq-op:hover { background:#F7E0BD; color:#8A5A2B; }
        .iq-op.danger { color:#DD5138; border-color:#E8BDB3; }
        .iq-op.danger:hover { background:#FBEAE6; }
        .iq-card-foot { margin-top:8px; display:flex; flex-wrap:wrap; gap:8px; align-items:center; }
        .iq-card-foot button { height:28px; padding:0 12px; font-size:12px; border-radius:4px; cursor:pointer; }
        .iq-badge { display:inline-block; padding:1px 8px; border-radius:10px; font-size:11px; }
        .iq-badge.follow { background:#E7F0E3; color:#4a7a3a; }
        .iq-badge.detach { background:#F3EADB; color:#8a6d45; }
        .iq-badge.open { background:#F7E0BD; color:#8A5A2B; }
        .iq-badge.replied { background:#E7F0E3; color:#4a7a3a; }
        .iq-badge.void { background:#EFE7D8; color:#999; }
        .iq-badge.closed { background:#EFE7D8; color:#777; }
        .iq-pager { display:flex; justify-content:flex-end; align-items:center; gap:6px; margin:6px 0; font-size:13px; color:#5b3a1e; }
        .iq-pager button { height:26px; padding:0 9px; border:1px solid #D8BE93; background:#fff; border-radius:4px; cursor:pointer; }
        .iq-pager button.on { background:#F0A24B; color:#fff; border-color:#d98a33; }
        .iq-mask { display:none; position:fixed; inset:0; background:rgba(60,40,20,.45); z-index:9000; overflow:auto; }
        .iq-modal { background:#fff; border-radius:8px; margin:30px auto; max-width:900px; width:94%; box-shadow:0 8px 30px rgba(0,0,0,.3); }
        .iq-modal.narrow { max-width:520px; }
        .m-head { display:flex; align-items:center; justify-content:space-between; padding:12px 16px; border-bottom:1px solid #EADFC8; font-size:16px; font-weight:bold; color:#5b3a1e; }
        .m-close { cursor:pointer; color:#999; font-size:18px; }
        .m-close:hover { color:#DD5138; }
        .m-body { padding:14px 16px; max-height:70vh; overflow:auto; }
        .m-foot { padding:10px 16px; border-top:1px solid #EADFC8; text-align:right; }
        .m-foot button, .b-att { height:32px; padding:0 14px; border-radius:4px; border:1px solid #D8BE93; background:#fff; color:#5b3a1e; cursor:pointer; }
        .m-foot .b-ok { background:#F0A24B; color:#fff; border-color:#d98a33; margin-left:6px; }
        .grid2 { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
        .grid4 { display:grid; grid-template-columns:1fr 1fr 1fr 1fr; gap:10px; }
        .m-body label { display:block; font-size:13px; color:#5b3a1e; margin:0 0 3px; font-weight:normal; }
        .m-body input[type=text], .m-body input[type=date], .m-body input[type=number], .m-body select, .m-body textarea {
            width:100%; border:1px solid #D8BE93; border-radius:4px; padding:5px 8px; font-size:13px; color:#5b3a1e; box-sizing:border-box; }
        .m-body textarea { resize:vertical; }
        .ro-auto, .m-body input:disabled, .m-body input[readonly] { background:#F3EADB !important; color:#7a6446; cursor:not-allowed; }
        .iq-hint { font-size:12px; color:#8a6d45; line-height:1.7; margin:4px 0 8px; }
        .iq-cat-wrap { display:flex; flex-wrap:wrap; gap:5px; margin-bottom:6px; }
        .iq-cat-grp { font-size:11px; color:#999; align-self:center; margin-right:-2px; }
        .iq-chip { display:inline-block; padding:2px 10px; border-radius:11px; font-size:12px; border:1px solid #D8BE93;
            background:#fff; color:#5b3a1e; cursor:pointer; user-select:none; }
        .iq-chip:hover { background:#FDF3E3; }
        .iq-chip.on { background:#F0A24B; color:#fff; border-color:#d98a33; }
        /* 廠商加工類別：先選大類、才展開小類，避免一次塞幾十個標籤把畫面擠亂 */
        .iq-cat-main-row { display:flex; flex-wrap:wrap; gap:6px; margin-bottom:4px; }
        .iq-cat-main-chip { display:inline-block; padding:4px 13px; border-radius:4px; font-size:12.5px; font-weight:bold;
            border:1.5px solid #D8BE93; background:#FDF8EF; color:#8A5A2B; cursor:pointer; user-select:none; }
        .iq-cat-main-chip:hover { background:#F7E0BD; }
        .iq-cat-main-chip.act { background:#8A5A2B; color:#fff; border-color:#6b4520; }
        .iq-cat-main-chip.act:after { content:" ▾"; }
        .iq-cat-sub-row:empty { display:none; }
        .iq-note-tpl { display:flex; flex-wrap:wrap; gap:5px; align-items:center; margin-top:6px; }
        .iq-note-tpl .iq-chip { max-width:220px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .iq-note-manage { font-size:12px; color:#b5762a; cursor:pointer; text-decoration:underline; }
        .iq-tpl-row { display:flex; align-items:center; gap:8px; padding:6px 0; border-bottom:1px solid #EADFC8; font-size:13px; }
        .iq-tpl-row .ct { flex:1; }
        .iq-tpl-row .badge { font-size:11px; padding:1px 8px; border-radius:10px; background:#F7E0BD; color:#8A5A2B; white-space:nowrap; }
        .iq-tpl-row .by { font-size:11px; color:#999; white-space:nowrap; }
        .iq-err { color:#DD5138; font-size:12px; margin-top:2px; min-height:16px; }
        table.item-tbl { width:100%; border-collapse:collapse; font-size:13px; }
        table.item-tbl th, table.item-tbl td { border:1px solid #EADFC8; padding:4px 5px; text-align:center; position:relative; }
        table.item-tbl th { background:#F7E0BD; color:#5b3a1e; }
        table.item-tbl input { width:100%; border:1px solid #E8D5B5; border-radius:3px; padding:4px 5px; font-size:13px; box-sizing:border-box; }
        table.item-tbl input[readonly] { background:#F3EADB; color:#7a6446; }
        .item-del { color:#DD5138; cursor:pointer; }
        .vendor-box { border:1px solid #D8BE93; border-radius:4px; max-height:220px; overflow:auto; padding:6px; background:#fff; }
        .vendor-box label { display:block; font-weight:normal; font-size:13px; margin:2px 0; cursor:pointer; }
        .vendor-box .vi-proc { color:#999; font-size:11.5px; }
        .iq-selvendor { display:flex; flex-wrap:wrap; gap:5px; margin-bottom:6px; min-height:0; }
        .iq-selvendor:empty { display:none; }
        .iq-selvendor .chip { display:inline-flex; align-items:center; gap:5px; background:#F0A24B; color:#fff;
            border-radius:11px; padding:2px 6px 2px 11px; font-size:12px; }
        .iq-selvendor .chip .x { cursor:pointer; opacity:.85; font-weight:bold; }
        .iq-selvendor .chip .x:hover { opacity:1; }
        /* 項目列的 BOM/製程綁定 */
        td.bom-cell { min-width:110px; }
        .bom-bind-btn { font-size:11px; padding:3px 7px; border-radius:3px; border:1px solid #D8BE93; background:#fff; color:#8A5A2B; cursor:pointer; }
        .bom-bind-btn:hover { background:#F7E0BD; }
        .bom-bound-tag { font-size:11px; line-height:1.5; color:#5b3a1e; text-align:left; }
        .bom-bound-tag b { color:#8A5A2B; }
        .bom-bound-tag .x { color:#DD5138; cursor:pointer; margin-left:4px; }
        .part-link { color:#b5762a; text-decoration:underline; cursor:pointer; }
        .part-link:hover { color:#8A5A2B; }
        /* BOM 綁定挑選跳窗 */
        .bom-pick-step { margin-bottom:10px; }
        .bom-proc-row { display:flex; align-items:center; gap:8px; padding:7px 9px; border:1px solid #EADFC8; border-radius:5px;
            margin-bottom:5px; cursor:pointer; font-size:13px; }
        .bom-proc-row:hover { background:#FDF3E3; }
        .bom-proc-row .nm { font-weight:bold; color:#5b3a1e; width:90px; flex:none; }
        .bom-proc-row .sp { flex:1; color:#777; font-size:12px; }
        .bom-proc-row .sg { font-size:10.5px; padding:1px 7px; border-radius:9px; background:#E7F0E3; color:#4a7a3a; flex:none; }
        .ac-list { position:fixed; z-index:9999; background:#fff; border:1px solid #D8BE93; border-radius:4px; box-shadow:0 4px 14px rgba(0,0,0,.2);
            max-height:220px; overflow:auto; font-size:12.5px; display:none; text-align:left; }
        .ac-list div { padding:5px 9px; cursor:pointer; }
        .ac-list div:hover { background:#F7E0BD; }
        .iq-noperm { border:1.5px solid #E8D5B5; background:#FDF8EF; border-radius:8px; padding:24px; color:#5b3a1e; }
        .iq-set-row { display:flex; align-items:center; gap:14px; padding:8px 0; border-bottom:1px solid #EADFC8; }
        .iq-set-row .lb { width:70px; font-weight:bold; color:#5b3a1e; }
        .iq-set-row label { font-weight:normal; margin:0 10px 0 0; display:inline; }
        .iq-bindtag { display:inline-block; background:#FDF3E3; color:#8A5A2B; border:1px solid #E0BE86; border-radius:4px; padding:1px 8px; font-size:12px; }
    </style>
</head>
<body class="nav-sm">
<div class="container body">
<div class="main_container">
    <?php include '../partPage/sideAndTopBarMenu.html' ?>
    <div class="right_col" role="main">
        <div class="page-title" style="display:flex;align-items:center;flex-wrap:wrap;">
            <h2 style="margin:6px 0;">詢價單
                <small style="color:#8a6d45;" id="hdrAsDocNo">3-OB-01-05　業務／生管／採購共用</small></h2>
            <?php if ($perms['canAdmin']): ?><button id="btnSettings" class="page-set-btn" style="margin-left:auto;"><i class="fa fa-cog"></i> 模組設定</button><?php endif; ?>
            <button id="btnPageHelp" class="page-help-btn" style="<?= $perms['canAdmin'] ? '' : 'margin-left:auto;' ?>"><i class="fa fa-question-circle"></i> 使用說明</button>
        </div>
        <div class="clearfix"></div>

<?php if (!$perms['canView']): ?>
        <div class="iq-noperm">
            <h4><i class="fa fa-lock"></i> 無詢價單使用權限</h4>
            <p>目前帳號不屬於業務／生管／採購部門，也未被指派檢視權限，無法使用本頁。如有需要請洽系統管理者。</p>
        </div>
<?php else: ?>
        <div class="iq-toolbar">
            <label>分類</label>
            <select id="fDept"><option value="">全部</option><option value="sales">業務</option><option value="pmc">生管</option><option value="purchase">採購</option></select>
            <label>狀態</label>
            <select id="fStatus"><option value="">全部</option><option value="open">進行中</option><option value="closed">已結案</option></select>
            <label>日期</label>
            <input type="date" id="fFrom" style="width:130px;"><span>～</span><input type="date" id="fTo" style="width:130px;">
            <input type="text" id="fKw" placeholder="關鍵字：廠商／料號／單號／規格…" style="width:220px;">
            <button id="btnSearch" class="btn-warm"><i class="fa fa-search"></i> 查詢</button>
            <?php if ($perms['canCreate']): ?><button id="btnNew" class="btn-warm" style="margin-left:auto;"><i class="fa fa-plus"></i> 新增詢價</button><?php endif; ?>
            <span class="iq-role"><?= htmlspecialchars($roleLabel) ?></span>
        </div>

        <div id="gList"><div style="text-align:center;color:#999;padding:20px;">載入中…</div></div>
        <div class="iq-pager" id="pager"></div>
<?php endif; ?>
    </div>
</div>
</div>

<!-- ══ 新增 / 編輯 詢價案（母單） ══ -->
<div class="iq-mask" id="gMask"><div class="iq-modal">
    <div class="m-head"><span id="gTitle">新增詢價</span><span class="m-close" onclick="closeMask('gMask')">✕</span></div>
    <div class="m-body">
        <div class="grid4">
            <div><label>分類</label><select id="gDept"></select></div>
            <div><label>詢價人員</label><input type="text" id="gReq" readonly class="ro-auto" title="固定為目前登入者，不可修改"></div>
            <div><label>詢價日期</label><input type="date" id="gDate" disabled class="ro-auto" title="固定為今天，不可修改"></div>
            <div><label>幣別</label><select id="gCurr"><option>NTD</option><option>USD</option><option>CNY</option><option>JPY</option><option>EUR</option></select></div>
        </div>
        <div style="margin-top:10px;">
            <label>綁定對象（選填，可之後再補）</label>
            <div style="display:flex;gap:8px;align-items:center;">
                <select id="gBindType" style="width:120px;"><option value="">不綁定</option><option value="purchase_request">請購單</option></select>
                <input type="text" id="gBindKw" placeholder="輸入關鍵字搜尋…" style="flex:1;" disabled>
                <span id="gBindTag" style="display:none;" class="iq-bindtag"></span>
                <button type="button" id="gBindClear" class="btn" style="display:none;height:32px;">清除</button>
            </div>
            <div class="iq-hint">料號的 BOM／製程綁定改在下方逐項目設定（一張詢價單可以同時問好幾張不同 BOM）。</div>
        </div>
        <div style="margin-top:12px;">
            <label>詢價項目</label>
            <table class="item-tbl" id="gItemTbl">
                <thead><tr><th style="width:26px;">項次</th><th id="gBomColHead" style="width:120px;display:none;">BOM／製程</th><th>產品編號</th><th>規格</th><th style="width:90px;">數量</th><th style="width:30px;"></th></tr></thead>
                <tbody data-eg-row-add="gItemAdd" data-eg-row-del="gItemDel"></tbody>
            </table>
            <div class="iq-hint" id="gItemHint"></div>
        </div>
        <div id="gVendorWrap" style="margin-top:12px;">
            <label>廠商（可多選，建立後自動展開成多張詢價單）</label>
            <div class="iq-selvendor" id="gVendorSel"></div>
            <div class="iq-cat-wrap" id="gVendorCats"></div>
            <input type="text" id="gVendorKw" placeholder="輸入廠商名稱或代號篩選…" style="margin-bottom:6px;">
            <div class="vendor-box" id="gVendorBox"></div>
        </div>
        <div style="margin-top:12px;">
            <label>備註</label>
            <textarea id="gNote" rows="2"></textarea>
            <div class="iq-note-tpl" id="gNoteTpl"><span class="iq-note-manage" id="btnNoteManage">管理常用用語</span></div>
        </div>
        <div class="iq-err" id="gErr"></div>
    </div>
    <div class="m-foot"><button onclick="closeMask('gMask')">取消</button><button class="b-ok" id="gSave">儲存</button></div>
</div></div>

<!-- ══ 新增廠商（既有詢價案追加） ══ -->
<div class="iq-mask" id="avMask"><div class="iq-modal narrow">
    <div class="m-head"><span>新增廠商</span><span class="m-close" onclick="closeMask('avMask')">✕</span></div>
    <div class="m-body">
        <div class="iq-hint">會依這個詢價案目前的項目內容，各自展開成一張新的詢價單（跟隨母單）。</div>
        <div class="iq-selvendor" id="avVendorSel"></div>
        <div class="iq-cat-wrap" id="avVendorCats"></div>
        <input type="text" id="avVendorKw" placeholder="輸入廠商名稱或代號篩選…" style="margin-bottom:6px;">
        <div class="vendor-box" id="avVendorBox"></div>
        <div class="iq-err" id="avErr"></div>
    </div>
    <div class="m-foot"><button onclick="closeMask('avMask')">取消</button><button class="b-ok" id="avSave">新增</button></div>
</div></div>

<!-- ══ 綁定 BOM／製程（詢價項目用） ══ -->
<div class="iq-mask" id="bomPickMask"><div class="iq-modal narrow">
    <div class="m-head"><span>綁定 BOM／製程</span><span class="m-close" onclick="closeMask('bomPickMask')">✕</span></div>
    <div class="m-body">
        <div class="bom-pick-step">
            <label>①先選 BOM</label>
            <input type="text" id="bpBomKw" placeholder="輸入 BOM 編號或料號搜尋…">
            <div id="bpBomPicked" class="iq-hint" style="display:none;"></div>
        </div>
        <div class="bom-pick-step" id="bpProcWrap" style="display:none;">
            <label>②選擇這張 BOM 內的製程（規格會自動帶入；標「建議」是管理員設定的常見詢價製程，<b>其他製程一樣可以選</b>）</label>
            <div id="bpProcList"></div>
            <button type="button" id="bpNoProc" style="margin-top:4px;height:30px;border:1px solid #D8BE93;background:#fff;border-radius:4px;cursor:pointer;">只綁定 BOM，不指定特定製程</button>
        </div>
        <div class="iq-err" id="bpErr"></div>
    </div>
    <div class="m-foot"><button onclick="closeMask('bomPickMask')">取消</button><button class="b-ok" id="bpClear" style="background:#DD5138;border-color:#C4442D;">清除綁定</button></div>
</div></div>

<!-- ══ 編輯子單（廠商回覆） ══ -->
<div class="iq-mask" id="dMask"><div class="iq-modal">
    <div class="m-head"><span id="dTitle">詢價單</span><span class="m-close" onclick="closeMask('dMask')">✕</span></div>
    <div class="m-body">
        <div class="grid4">
            <div><label>廠商</label><input type="text" id="dVendor" readonly class="ro-auto"></div>
            <div><label>聯絡人員</label><input type="text" id="dContact" readonly class="ro-auto"></div>
            <div><label>聯絡電話</label><input type="text" id="dPhone" readonly class="ro-auto"></div>
            <div><label>傳真號碼</label><input type="text" id="dFax" readonly class="ro-auto"></div>
        </div>
        <div class="iq-hint" id="dFollowHint"></div>
        <table class="item-tbl" id="dItemTbl">
            <thead><tr><th style="width:26px;">項次</th><th id="dBomColHead" style="width:120px;display:none;">BOM／製程</th><th>產品編號</th><th>規格</th><th style="width:85px;">數量</th><th style="width:110px;">單價</th><th style="width:30px;"></th></tr></thead>
            <tbody data-eg-row-add="dItemAdd" data-eg-row-del="dItemDel"></tbody>
        </table>
        <div class="iq-hint" id="dItemHint"></div>
        <div class="grid2" style="margin-top:10px;">
            <div><label>狀態</label><select id="dStatus"><option value="open">等待報價</option><option value="replied">已回覆單價</option><option value="void">不再使用</option></select></div>
        </div>
        <div class="iq-err" id="dErr"></div>
    </div>
    <div class="m-foot">
        <button onclick="printOneDoc(CUR_DOC_ID)"><i class="fa fa-print"></i> 列印這張</button>
        <button onclick="closeMask('dMask')">取消</button><button class="b-ok" id="dSave">儲存</button>
    </div>
</div></div>

<!-- ══ 模組設定（AS 文件綁定 + 各部門使用規則） ══ -->
<div class="iq-mask" id="setMask"><div class="iq-modal narrow">
    <div class="m-head"><span>模組設定</span><span class="m-close" onclick="closeMask('setMask')">✕</span></div>
    <div class="m-body">
        <label>綁定 AS 文件編號</label>
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:14px;">
            <span id="asDocLabel" style="flex:1;color:#5b3a1e;"></span>
            <button type="button" id="btnAsDoc" class="btn">選擇</button>
        </div>
        <label>子單單號前綴</label>
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">
            <input type="text" id="docPrefixInput" maxlength="8" style="width:100px;text-transform:uppercase;">
            <button type="button" id="btnDocPrefix" class="btn">儲存</button>
            <span id="docPrefixPreview" style="font-size:12px;color:#8a6d45;"></span>
        </div>
        <div class="iq-hint" style="margin:0 0 14px;">單號規則＝前綴＋YYYYMMDD＋流水3碼，<b>同一天所有廠商共用同一組流水號</b>（不分廠商各自起跳）。只影響之後新產生的單號，不會改掉已經存在的單號。</div>
        <label>各部門使用規則</label>
        <div id="deptSetRows"></div>
        <label style="margin-top:14px;display:block;">生管詢價建議的製程大項<span style="font-weight:normal;color:#999;">（軟性建議，勾選的會在挑選 BOM 製程時標「建議」優先顯示；沒勾的製程照樣可以選，只是不特別標示）</span></label>
        <div class="iq-cat-wrap" id="procTypeRows" style="margin-top:6px;"></div>
    </div>
    <div class="m-foot"><button class="b-ok" onclick="closeMask('setMask')">關閉</button></div>
</div></div>

<!-- ══ 備註常用用語管理 ══ -->
<div class="iq-mask" id="tplMask"><div class="iq-modal narrow">
    <div class="m-head"><span>備註常用用語</span><span class="m-close" onclick="closeMask('tplMask')">✕</span></div>
    <div class="m-body">
        <div class="iq-hint">任何能新增詢價單的人都可以新增，並編輯／刪除<b>自己設定</b>的項目；「部門」只有同部門的人看得到可以選用，「公開」所有來源部門都看得到。</div>
        <div id="tplList"></div>
        <div style="margin-top:12px;border-top:1px solid #EADFC8;padding-top:10px;">
            <label>新增用語</label>
            <textarea id="tplContent" rows="2" placeholder="輸入常用備註內容…"></textarea>
            <div class="grid2" style="margin-top:6px;">
                <div><label>適用範圍</label><select id="tplScope"><option value="public">公開（所有部門）</option><option value="dept">部門限定</option></select></div>
                <div id="tplDeptWrap" style="display:none;"><label>部門</label><select id="tplDeptSel"></select></div>
            </div>
            <div class="iq-err" id="tplErr"></div>
            <div style="text-align:right;margin-top:8px;"><button type="button" class="b-ok" id="tplAdd" style="height:32px;border-radius:4px;border:1px solid #d98a33;background:#F0A24B;color:#fff;cursor:pointer;">新增</button></div>
        </div>
    </div>
    <div class="m-foot"><button class="b-ok" onclick="closeMask('tplMask')">關閉</button></div>
</div></div>

<!-- ══ 使用說明 ══ -->
<div class="iq-mask" id="helpUseMask"><div class="iq-modal">
    <div class="m-head"><span>使用說明</span><span class="m-close" onclick="closeMask('helpUseMask')">✕</span></div>
    <div class="m-body help-doc">
        <h4>功能說明</h4>
        <p>業務詢外包加工、生管詢外包材料、採購詢相關採購，三個部門共用這一頁詢價單。建立一次詢價（母單）時可以一次勾選多家廠商，
        系統自動展開成多張「子單」（一家廠商一張，各自有獨立單號、聯絡資料、單價欄位）。</p>
        <h4>母單與子單的關係</h4>
        <p>子單預設「<b>跟隨母單</b>」：改母單的詢價項目（產品編號／規格／數量）會自動同步到每一張跟隨中的子單。
        <b>直接編輯某一張子單的項目內容</b>會讓那一張自動脫離跟隨，之後母單再怎麼改都不會動到它；可以再按「重新跟隨母單」接回去
        （會立刻用母單目前內容整批覆蓋，單價會被清空）。單價只存在子單，是廠商回覆的資料，母單本身不會有單價。</p>
        <h4>綁定對象（可選、可後補）</h4>
        <p>業務通常把「產品編號」綁到實際料號主檔；採購可以把整個詢價案綁到一張請購單。三種都不是必填，之後隨時可以在編輯畫面補上或改掉。</p>
        <h4>生管：逐項目綁定 BOM／製程</h4>
        <p>生管身分建立詢價時，每一列詢價項目都可以各自綁定不同的 BOM（一張詢價單可以同時問好幾張不同 BOM）。
        綁了 BOM 之後可以進一步指定是哪一關製程——選了製程會自動帶出該關的規格備註，料號一律由 BOM 帶出；
        不指定製程也可以，這時只會帶出料號。<b>同一張詢價單裡，有綁製程的項目必須屬於同一個廠商加工大類</b>
        （例如都是「加工廠」底下的製程，不能混加工廠跟耗材供應商），避免同一份清單寄給廠商時出現看不懂的項目。
        管理員可在「模組設定」勾選哪些製程大項要優先標示「建議」，但<b>沒被勾選的製程照樣可以選</b>，這只是方便尋找的標記，不是限制。</p>
        <h4>廠商清單</h4>
        <p>廠商勾選框右側會顯示該廠商登記的加工項目說明（主檔管理裡的資料），方便確認是不是要找的廠商；
        上方「已選廠商」會即時列出目前勾選的廠商，點 × 可以直接取消。</p>
        <h4>查看料號圖面</h4>
        <p>詢價項目一旦填了料號（不論是綁料號主檔、綁 BOM，還是自己打字），產品編號欄旁會出現放大鏡圖示，
        點一下另開一個可自由移動縮放的瀏覽器視窗顯示該料號的圖面，方便邊填邊核對。</p>
        <h4>部門使用規則（管理員可設定）</h4>
        <p>管理員可以在「模組設定」逐部門（業務／生管／採購）決定：是否允許用「非實際存在的料號」詢價（產品編號可以純打字不綁主檔），
        以及是否允許自訂規格（不是由料號主檔帶出的唯讀文字）。兩者預設都關閉（要求綁真實料號、規格由主檔帶出）。</p>
        <h4>詢價人員／詢價日期／來源部門</h4>
        <p>詢價人員固定是目前登入者本人、詢價日期固定是今天，建立後都不能再改。來源部門只會列出自己實際任職或兼任的部門——
        不屬於業務／生管／採購任何一個部門的帳號，無法新增詢價單（只能視權限檢視或設定）；<b>管理者不受此限制</b>，可用任一部門身分新增，確保隨時都能測試或補登。</p>
        <h4>廠商篩選</h4>
        <p>廠商清單可以依「加工類別」標籤（與主檔管理廠商分頁同一套分類）點選縮小範圍，也可以同時打關鍵字篩選；
        篩選只是「目前看得到誰」，換篩選條件或清空篩選，已經勾選的廠商不會被取消。</p>
        <h4>備註常用用語</h4>
        <p>備註欄下方可以點選常用用語直接帶入（可多次點選疊加），不會強制套用。任何能新增詢價單的人都可以在「管理常用用語」
        新增自己的用語，並編輯／刪除<b>自己設定的</b>項目；用語分「部門」（僅同部門看得到）與「公開」（所有部門都看得到）兩種。</p>
        <h4>列印</h4>
        <p>每張子單可以單獨列印，也可以在詢價案列表按「列印全部」把這個詢價案展開的所有子單一次印出（各佔一頁）。
        列印版會動態帶出公司全名、地址、電話、傳真，右下角印綁定的 AS 文件編號（依詢價日期回推版次）。</p>
        <h4>權限</h4>
        <p>業務／生管／採購部門的人員可以建立並編輯自己部門的詢價案；管理員可以設定 AS 文件綁定與各部門使用規則，並可編輯任何詢價案。</p>
    </div>
    <div class="m-foot"><button class="b-ok" onclick="closeMask('helpUseMask')">我知道了</button></div>
</div></div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/fastclick.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script src="../../resource/js/eg_stamp.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_stamp.js') ?>"></script>
<script src="../../resource/js/eg_date_fmt.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_date_fmt.js') ?>"></script>
<script src="../../resource/js/eg_asdoc_picker.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_asdoc_picker.js') ?>"></script>
<script src="../../resource/js/eg_input_rules.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_input_rules.js') ?>"></script>
<script>
$(document).ready(function(){
    var $am = $('#sidebar-menu .nav.side-menu > li.active');
    if ($am.length) { $am.removeClass('active').find('ul.child_menu').hide(); $am.find('li.current-page').removeClass('current-page'); }
    $('#sidebar-menu').css('visibility','visible');
});

var API = '../../src/store/Inquiry_API.php';
var CSRF = '<?= $CSRF ?>';
var PERM = <?= json_encode($perms, JSON_UNESCAPED_UNICODE) ?>;
var MY_NAME = <?= json_encode($perms['name'], JSON_UNESCAPED_UNICODE) ?>;
var DEPT_LABEL = {sales:'業務', pmc:'生管', purchase:'採購'};
var DEPT_SET = {};
var GROUPS = [], PAGE = 1, PSIZE = 10;
var CUR_GROUP = null, CUR_DOC_ID = 0, CUR_BIND = null;

function esc(s){ return $('<div>').text(s==null?'':String(s)).html(); }
function openMask(id){ $('#'+id).css('display','block'); }
function closeMask(id){ $('#'+id).css('display','none'); }
function money(v){ if (v===null||v===undefined||v==='') return ''; var n=parseFloat(v); if (isNaN(n)) return ''; return (Math.round(n*10000)/10000).toString(); }

function post(action, data, cb){
    data = $.extend({action:action, csrf:CSRF}, data||{});
    $.post(API, data, function(res){
        if (!res || !res.success) { alert((res&&res.message)||'操作失敗'); return; }
        cb && cb(res);
    }, 'json').fail(function(){ alert('連線失敗'); });
}

/* ───────────────────── 清單 ───────────────────── */
function loadDeptSettings(cb){
    $.getJSON(API, {action:'dept_settings'}, function(res){ if (res.success){ DEPT_SET = res.dept_set; cb && cb(); } });
}
function loadList(){
    var p = {dept:$('#fDept').val(), status:$('#fStatus').val(), date_from:$('#fFrom').val(), date_to:$('#fTo').val(), kw:$('#fKw').val()};
    $.getJSON(API, $.extend({action:'list'}, p), function(res){
        if (!res.success) return;
        DEPT_SET = res.dept_set || DEPT_SET;
        GROUPS = res.rows || [];
        PAGE = 1;
        renderList();
    });
}
function itemSummary(items){
    if (!items || !items.length) return '（尚未填寫項目）';
    var s = items.map(function(it){
        var label = it.part_no_text || (it.bom ? ('BOM:'+it.bom) : '（未填料號）');
        return label + (it.qty!=null && it.qty!=='' ? '×'+money(it.qty) : '');
    });
    return s.slice(0,4).join('、') + (s.length>4 ? '…共'+s.length+'項' : '');
}
function renderList(){
    var $list = $('#gList').empty();
    if (!GROUPS.length) { $list.append('<div style="text-align:center;color:#999;padding:20px;">沒有符合條件的詢價案</div>'); $('#pager').empty(); return; }
    var total = GROUPS.length, pages = Math.max(1, Math.ceil(total/PSIZE));
    if (PAGE > pages) PAGE = pages;
    var slice = GROUPS.slice((PAGE-1)*PSIZE, PAGE*PSIZE);
    slice.forEach(function(g){ $list.append(renderGroupCard(g)); });
    var pg = $('#pager').empty();
    for (var i=1;i<=pages;i++){ (function(i){ var b=$('<button>'+i+'</button>').toggleClass('on', i===PAGE).on('click', function(){ PAGE=i; renderList(); }); pg.append(b); })(i); }
}
function renderGroupCard(g){
    var canEdit = PERM.canAdmin || (PERM.myDepts||[]).indexOf(g.source_dept) >= 0;
    var card = $('<div class="iq-card"></div>');
    var hd = $('<div class="iq-card-hd"></div>');
    hd.append('<div class="iq-card-id">#'+g.id+'</div>');
    hd.append('<div class="iq-card-cat">'+DEPT_LABEL[g.source_dept]+'</div>');

    var main = $('<div class="iq-card-main"></div>');
    main.append('<div class="iq-card-title">'+esc(g.requester_name)+'<span class="dt">'+esc(g.inquiry_date)+'</span>'
        +' <span class="iq-badge '+(g.status==='closed'?'closed':'open')+'">'+(g.status==='closed'?'已結案':'進行中')+'</span></div>');
    main.append('<div class="iq-card-row"><b>項目：</b>'+esc(itemSummary(g.items))+'</div>');
    var bindTxt = g.bind_label ? ('<b>綁定：</b>' + (g.bind_type==='bom'?'BOM':'請購單') + '　' + esc(g.bind_label)) : '<b>綁定：</b><span style="color:#999;">未綁定</span>';
    main.append('<div class="iq-card-row">'+bindTxt+'　<b>廠商數：</b>'+(g.doc_count||0)+'</div>');
    if (g.note) main.append('<div class="iq-card-row"><b>備註：</b>'+esc(g.note)+'</div>');

    var ops = $('<div class="iq-card-ops"></div>');
    if (canEdit) {
        ops.append('<button data-act="edit">編輯</button>');
        ops.append('<button data-act="bind">綁定</button>');
        ops.append('<button data-act="addv">新增廠商</button>');
        ops.append('<button data-act="toggle">'+(g.status==='closed'?'重新開啟':'結案')+'</button>');
        ops.append('<button class="danger" data-act="del">刪除</button>');
    }
    ops.find('button').on('click', function(){ groupOp($(this).data('act'), g.id); });

    hd.append(main).append(ops);
    card.append(hd);
    card.append(renderDocsTable(g));
    return card;
}
function renderDocsTable(g){
    var canEdit = PERM.canAdmin || (PERM.myDepts||[]).indexOf(g.source_dept) >= 0;
    var body = $('<div class="iq-card-body"></div>');
    var tbl = $('<table class="iq-d"><thead><tr><th style="width:140px;">單號</th><th>廠商</th><th>聯絡人員</th><th style="width:90px;">狀態</th><th style="width:90px;">跟隨母單</th><th style="width:56px;">項目數</th><th style="width:90px;">單價合計</th><th style="width:170px;">操作</th></tr></thead><tbody></tbody></table>');
    var tb = tbl.find('tbody');
    (g.docs||[]).forEach(function(d){
        var total = 0, hasPrice = false;
        (d.items||[]).forEach(function(it){ if (it.unit_price!=null && it.unit_price!=='') { hasPrice = true; total += parseFloat(it.unit_price) * (parseFloat(it.qty)||0); } });
        var row = $('<tr><td>'+esc(d.doc_no)+'</td><td>'+esc(d.vendor_name)+'</td><td>'+esc(d.contact_person||'')+'</td>'
            +'<td><span class="iq-badge '+d.status+'">'+({open:'等待報價',replied:'已回覆',void:'不再使用'}[d.status]||d.status)+'</span></td>'
            +'<td><span class="iq-badge '+(d.follow_parent?'follow':'detach')+'">'+(d.follow_parent?'跟隨母單':'已脫離')+'</span></td>'
            +'<td>'+(d.items||[]).length+'</td><td>'+(hasPrice ? money(total) : '—')+'</td><td></td></tr>');
        row.find('td').eq(5).attr('title', itemSummary(d.items));
        var opcell = $('<div class="opcell"></div>');
        opcell.append('<span class="iq-op" data-act="print">列印</span>');
        if (canEdit) {
            opcell.append('<span class="iq-op" data-act="edit">編輯</span>');
            if (!d.follow_parent) opcell.append('<span class="iq-op" data-act="refollow">重新跟隨</span>');
            opcell.append('<span class="iq-op danger" data-act="del">刪除</span>');
        }
        opcell.find('.iq-op').on('click', function(){ docOp($(this).data('act'), d.id); });
        row.find('td').last().append(opcell);
        tb.append(row);
    });
    body.append(tbl);
    var foot = $('<div class="iq-card-foot"></div>');
    if (canEdit) foot.append('<button class="btn-warm" onclick="openAddVendor('+g.id+')">新增廠商</button>');
    foot.append('<button style="border:1px solid #D8BE93;background:#fff;color:#8A5A2B;" onclick="printAllDocs('+g.id+')">列印全部（展開一次列印）</button>');
    body.append(foot);
    return body;
}

function groupOp(act, gid){
    if (act==='edit') openGroupEdit(gid);
    else if (act==='bind') openBind(gid);
    else if (act==='addv') openAddVendor(gid);
    else if (act==='toggle') {
        var g = GROUPS.find(function(x){return x.id===gid;});
        post('group_status', {id:gid, status: g.status==='closed'?'open':'closed'}, loadList);
    } else if (act==='del') {
        if (!confirm('確定要刪除這個詢價案？底下的所有子單也會一併刪除。')) return;
        post('group_delete', {id:gid}, loadList);
    }
}
function docOp(act, did){
    if (act==='edit') openDocEdit(did);
    else if (act==='print') printOneDoc(did);
    else if (act==='refollow') { if (confirm('重新跟隨母單會用母單目前的內容整批覆蓋這張子單，單價會被清空，確定？')) post('doc_refollow', {id:did}, loadList); }
    else if (act==='del') { if (confirm('確定要刪除這張子單？')) post('doc_delete', {id:did}, loadList); }
}

/* ───────────────────── 項目列表編輯（共用於新增/母單編輯/子單編輯） ───────────────────── */
/* allowBom：這個部門（目前只有生管）才顯示「BOM／製程」欄，業務/採購沿用原本的純料號輸入 */
function itemRowHtml(seq, it, deptSet, showPrice, allowBom){
    it = it || {};
    var hasBom = !!(it.bom);
    var lockSpec = !!deptSet && !deptSet.allow_custom_spec && (it.part_id||it.part_no_text||hasBom);
    var lockPart = hasBom;   // 料號由 BOM 帶出時鎖定，不給手改（要改請先清除綁定）
    var row = '<tr data-part-id="'+(it.part_id||'')+'" data-gi="'+(it.group_item_id||'')+'"'
        +' data-bom="'+esc(it.bom||'')+'" data-bom-ing-fid="'+(it.bom_ing_fid||'')+'"'
        +' data-main-cat-id="'+(it._main_cat_id||'')+'" data-main-cat-name="'+esc(it._main_cat_name||'')+'"'
        +' data-proc-name="'+esc(it._proc_name||'')+'">'
        +'<td class="rseq">'+seq+'</td>';
    if (allowBom) row += '<td class="bom-cell">'+bomCellHtml(it)+'</td>';
    row += '<td><input type="text" class="it-part" value="'+esc(it.part_no_text||'')+'" placeholder="輸入料號搜尋…"'+(lockPart?' readonly':'')+'>'
        + (it.part_no_text ? ' <span class="part-link fa fa-search" title="開啟料號圖面"></span>' : '') + '</td>'
        +'<td><input type="text" class="it-spec" value="'+esc(it.spec_text||'')+'"'+(lockSpec?' readonly':'')+'></td>'
        +'<td><input type="number" step="0.001" class="it-qty" value="'+(it.qty!=null?it.qty:'')+'"></td>';
    if (showPrice) row += '<td><input type="number" step="0.0001" class="it-price" value="'+(it.unit_price!=null?it.unit_price:'')+'"></td>';
    row += '<td><span class="item-del fa fa-times" title="刪除這一列"></span></td></tr>';
    return row;
}
function bomCellHtml(it){
    if (it.bom) {
        return '<div class="bom-bound-tag"><b>'+esc(it.bom)+'</b><br>'
            + (it.bom_ing_fid ? esc(it._proc_name||('製程#'+it.bom_ing_fid)) : '（未指定製程）')
            + '<span class="x fa fa-times" title="清除綁定"></span></div>';
    }
    return '<button type="button" class="bom-bind-btn">+綁定BOM</button>';
}
function renumberItems($tbody){ $tbody.find('tr').each(function(i){ $(this).find('td.rseq').text(i+1); }); }
function openPartViewerPopup(partNo, bom){
    if (!partNo) return;
    // 比照 OreadyReply_ForPm_BaseOfTime.php 開料號圖面的做法：用一般瀏覽器彈出視窗（天生可移動、可縮放），
    // 不自己刻一個「可移動」的自訂跳窗（鐵律4，沿用同一套既有模式）。
    var w = Math.min(1100, Math.round(screen.availWidth * 0.8));
    var h = Math.min(900, Math.round(screen.availHeight * 0.88));
    var l = Math.round((screen.availWidth - w) / 2);
    var t = Math.round((screen.availHeight - h) / 2);
    var url = '../pm/part_viewer.php?d_id=' + encodeURIComponent(partNo) + (bom ? '&bom=' + encodeURIComponent(bom) : '');
    window.open(url, 'inq_part_' + partNo, 'width=' + w + ',height=' + h + ',left=' + l + ',top=' + t + ',resizable=yes,scrollbars=yes');
}
/* 同一張單（同一個 $tbody）已綁定 BOM 製程的其他列，製程大類是否跟 newMainCatId 衝突（排除 $excludeTr 自己） */
function rowMainCatConflict($tbody, $excludeTr, newMainCatId, newMainCatName){
    if (!newMainCatId) return null;
    var hit = null;
    $tbody.find('tr').each(function(){
        if (this === $excludeTr[0]) return;
        var mc = $(this).attr('data-main-cat-id');
        if (mc && mc !== String(newMainCatId)) { hit = {name: $(this).attr('data-main-cat-name'), part: $(this).find('.it-part').val()}; return false; }
    });
    return hit;
}
function wireItemRow($tr, deptSet, allowBom){
    if (!$tr.attr('data-bom')) { bindPartSearch($tr.find('.it-part'), deptSet); }
    $tr.find('.item-del').on('click', function(){ var $tb=$tr.closest('tbody'); if ($tb.find('tr').length>1) { $tr.remove(); renumberItems($tb); } });
    $tr.find('.part-link').on('click', function(){ openPartViewerPopup($tr.find('.it-part').val(), $tr.attr('data-bom')); });
    if (allowBom) { wireBomCell($tr, deptSet); }
}
/* 「綁定BOM」鈕／已綁定時「清除」的事件，獨立出來以便清除綁定後只重綁這一小塊、不重複綁整列 */
function wireBomCell($tr, deptSet){
    $tr.find('.bom-bind-btn').off('click').on('click', function(){ openBomPick($tr, deptSet); });
    $tr.find('.bom-bound-tag .x').off('click').on('click', function(e){
        e.stopPropagation();
        $tr.attr('data-bom','').attr('data-bom-ing-fid','').attr('data-main-cat-id','').attr('data-main-cat-name','').attr('data-proc-name','');
        $tr.find('.bom-cell').html(bomCellHtml({}));
        $tr.find('.it-part').prop('readonly', false).removeClass('ro-auto');
        $tr.find('.it-spec').prop('readonly', !!deptSet && !deptSet.allow_custom_spec && !!$tr.find('.it-part').val()).toggleClass('ro-auto', !!deptSet && !deptSet.allow_custom_spec);
        bindPartSearch($tr.find('.it-part'), deptSet);
        wireBomCell($tr, deptSet);
    });
}
function bindPartSearch($input, deptSet, specLockTarget){
    var $list = $('<div class="ac-list"></div>').appendTo('body');
    var timer = null;
    function hide(){ $list.hide(); }
    $input.on('input', function(){
        clearTimeout(timer);
        var kw = $(this).val();
        $input.closest('tr').attr('data-part-id','').attr('data-gi','');
        if (!kw) { hide(); return; }
        timer = setTimeout(function(){
            $.getJSON(API, {action:'part_search', kw:kw}, function(res){
                if (!res.success) return;
                $list.empty();
                (res.rows||[]).forEach(function(r){
                    var d = $('<div></div>').text(r.label).data('row', r);
                    d.on('click', function(){
                        var row = $(this).data('row');
                        $input.val(row.part_no);
                        $input.closest('tr').attr('data-part-id', row.part_id);
                        if (deptSet && !deptSet.allow_custom_spec) {
                            $input.closest('tr').find('.it-spec').val(row.spec||'').attr('readonly', true).addClass('ro-auto');
                        } else {
                            $input.closest('tr').find('.it-spec').val(row.spec||'');
                        }
                        hide();
                    });
                    $list.append(d);
                });
                if (!res.rows || !res.rows.length) { $list.append('<div style="color:#999;">查無符合的料號</div>'); }
                var r = $input[0].getBoundingClientRect();
                $list.css({left:r.left+'px', top:(r.bottom+2)+'px', width:Math.max(220,r.width)+'px'}).show();
            });
        }, 280);
    });
    $input.on('blur', function(){ setTimeout(hide, 200); });
}
function addItemRow($tbody, deptSet, showPrice, allowBom){
    var seq = $tbody.find('tr').length+1;
    var $tr = $(itemRowHtml(seq, {}, deptSet, showPrice, allowBom)).appendTo($tbody);
    wireItemRow($tr, deptSet, allowBom);
    return $tr;
}
function collectItems($tbody, showPrice){
    var out = [];
    $tbody.find('tr').each(function(){
        var $tr = $(this);
        var partNo = $tr.find('.it-part').val().trim();
        var spec = $tr.find('.it-spec').val().trim();
        var qty = $tr.find('.it-qty').val();
        var bom = $tr.attr('data-bom') || '';
        var bomIngFid = $tr.attr('data-bom-ing-fid') || '';
        if (!partNo && !spec && !qty && !bom) return;
        var it = {
            part_id: $tr.attr('data-part-id') || '',
            part_no_text: partNo,
            spec_text: spec,
            qty: qty === '' ? '' : qty,
            group_item_id: $tr.attr('data-gi') || '',
            bom: bom,
            bom_ing_fid: bomIngFid
        };
        if (showPrice) it.unit_price = $tr.find('.it-price').val();
        out.push(it);
    });
    return out;
}

/* window.gItemAdd / gItemDel / dItemAdd / dItemDel —— 供共用檔 eg_input_rules.js 的鍵盤↓↑增刪列呼叫（無參數） */
window.gItemAdd = function(){ var dept=$('#gDept').val(); var ds = DEPT_SET[dept] || {}; addItemRow($('#gItemTbl tbody'), ds, false, dept==='pmc'); };
window.gItemDel = function(){ var $tb=$('#gItemTbl tbody'); if ($tb.find('tr').length>1){ $tb.find('tr').last().remove(); renumberItems($tb); } };
window.dItemAdd = function(){ var dept = CUR_GROUP ? CUR_GROUP.source_dept : ''; var ds = DEPT_SET[dept]||{}; addItemRow($('#dItemTbl tbody'), ds, true, dept==='pmc'); };
window.dItemDel = function(){ var $tb=$('#dItemTbl tbody'); if ($tb.find('tr').length>1){ $tb.find('tr').last().remove(); renumberItems($tb); } };

/* ───────────────────── 廠商多選框 ───────────────────── */
var VENDOR_ROWS = null;
function loadVendors(cb){
    if (VENDOR_ROWS) { cb(VENDOR_ROWS); return; }
    $.getJSON(API, {action:'vendors'}, function(res){ VENDOR_ROWS = res.rows||[]; cb(VENDOR_ROWS); });
}
function renderVendorBox($box, rows, checkedIds, disabledIds){
    checkedIds = checkedIds || []; disabledIds = disabledIds || [];
    $box.empty();
    rows.forEach(function(v){
        var dis = disabledIds.indexOf(v.maker_id_no) >= 0;
        var chk = checkedIds.indexOf(v.maker_id_no) >= 0;
        var procTxt = (v.m_process_items||'').trim();
        var lb = $('<label></label>').attr('data-cats', (v.sub_cat_ids||[]).join(',')).attr('data-name', v.maker_id).append(
            $('<input type="checkbox">').val(v.maker_id_no).prop('checked', chk).prop('disabled', dis)
        ).append(' ' + v.maker_id_no + '　' + esc(v.maker_id) + (dis ? '（已在詢價案內）' : ''))
         .append(procTxt ? $('<span class="vi-proc"></span>').text('　' + procTxt) : '');
        $box.append(lb);
    });
}
/* 「已選廠商」摘要區：勾選框一變動就重畫，點 × 等同取消勾選那一家（鐵律：篩選/選取狀態要讓使用者一眼看到）。 */
function wireSelectedVendors($box, $sel){
    function render(){
        $sel.empty();
        $box.find('input:checked').each(function(){
            var vid = $(this).val();
            var $lb = $(this).closest('label');
            var chip = $('<span class="chip"></span>').append($('<span></span>').text(vid+' '+$lb.attr('data-name')));
            var $x = $('<span class="x fa fa-times"></span>').on('click', function(){
                $box.find('input').filter(function(){ return $(this).val()===vid; }).prop('checked', false).trigger('change');
            });
            chip.append($x);
            $sel.append(chip);
        });
    }
    $box.off('change.sel').on('change.sel', 'input[type=checkbox]', render);
    render();
}
/* 關鍵字與加工類別標籤一起篩（AND）；標籤本身是「符合任一個已選標籤」（OR）。
   不要求一次篩選就要勾選完——這裡只是顯示/隱藏，已勾選的 checkbox 狀態完全不受影響。 */
function filterVendorBox($box, kw, selCats){
    kw = (kw||'').toLowerCase();
    selCats = selCats || [];
    $box.find('label').each(function(){
        var $lb = $(this);
        var okKw = !kw || $lb.text().toLowerCase().indexOf(kw) >= 0;
        var okCat = true;
        if (selCats.length) {
            var cats = ($lb.attr('data-cats')||'').split(',').filter(function(x){return x;});
            okCat = cats.some(function(c){ return selCats.indexOf(c) >= 0; });
        }
        $lb.toggle(okKw && okCat);
    });
}
/* 加工類別標籤篩選區：點擊切換 .on，重新套用篩選（跟關鍵字共用 filterVendorBox）。
   目前已選的標籤一律**當場從畫面上的 .on 讀出**，不額外存一份陣列，避免兩邊對不起來。 */
var VENDOR_CATS = null;
function loadVendorCats(cb){
    if (VENDOR_CATS) { cb(VENDOR_CATS); return; }
    $.getJSON(API, {action:'vendor_categories'}, function(res){ VENDOR_CATS = res.rows||[]; cb(VENDOR_CATS); });
}
function selectedCats($wrap){ return $wrap.find('.iq-chip.on').map(function(){ return String($(this).data('id')); }).get(); }
/* 先選大類（加工廠／耗材供應商…）才展開該大類底下的小類可勾，避免 50 幾個小類一次攤開把畫面擠亂。
   切換大類會清掉上一個大類已勾的小類（兩個大類的標籤同時攤在畫面上、卻只有其中一組在起作用，
   使用者分不出來）；再點一次已展開的大類＝收合、回到沒有類別篩選的狀態。 */
function renderCatChips($wrap, $box, $kwInput){
    loadVendorCats(function(groups){
        $wrap.empty();
        if (!groups.length) return;
        var $mainRow = $('<div class="iq-cat-main-row"></div>');
        var $subRow  = $('<div class="iq-cat-wrap iq-cat-sub-row" style="margin-top:4px;"></div>');
        groups.forEach(function(g){
            var pill = $('<span class="iq-cat-main-chip"></span>').text(g.main_cat_name);
            pill.on('click', function(){
                var wasActive = pill.hasClass('act');
                $mainRow.find('.iq-cat-main-chip').removeClass('act');
                $subRow.empty();
                if (!wasActive) {
                    pill.addClass('act');
                    g.subs.forEach(function(s){
                        var chip = $('<span class="iq-chip"></span>').text(s.sub_cat_name).data('id', s.sub_cat_id);
                        chip.on('click', function(e){
                            e.stopPropagation();
                            chip.toggleClass('on');
                            filterVendorBox($box, $kwInput.val(), selectedCats($subRow));
                        });
                        $subRow.append(chip);
                    });
                }
                filterVendorBox($box, $kwInput.val(), selectedCats($subRow));   // 剛切換大類還沒勾小類＝不篩
            });
            $mainRow.append(pill);
        });
        $wrap.append($mainRow).append($subRow);
        $kwInput.off('input.cat').on('input.cat', function(){ filterVendorBox($box, $(this).val(), selectedCats($subRow)); });
    });
}

/* ───────────────────── 新增 / 編輯 詢價案 ───────────────────── */
function fillDeptOptions($sel){
    $sel.empty();
    // 管理員不受部門限制（可用任何部門身分新增，方便測試/補登）；一般使用者只列自己實際所屬的部門
    var depts = PERM.canAdmin ? ['sales','pmc','purchase'] : (PERM.myDepts||[]);
    depts.forEach(function(d){ $sel.append('<option value="'+d+'">'+DEPT_LABEL[d]+'</option>'); });
}
function openNewGroup(){
    CUR_GROUP = null; CUR_BIND = null;
    $('#gTitle').text('新增詢價');
    fillDeptOptions($('#gDept'));
    $('#gDept').prop('disabled', false).off('change').on('change', refreshGDeptUI);
    $('#gReq').val(MY_NAME);
    $('#gDate').val(new Date().toISOString().slice(0,10));
    $('#gCurr').val('NTD');
    $('#gNote').val('請協助提供單價與交期，謝謝。');
    $('#gBindType').val('').prop('disabled', false);
    $('#gBindKw').val('').prop('disabled', true);
    $('#gBindTag').hide(); $('#gBindClear').hide();
    $('#gVendorWrap').show();
    $('#gErr').text('');
    $('#gVendorKw').val('');
    $('#gVendorSel').empty();
    $('#gNoteTpl').show();
    var $tb = $('#gItemTbl tbody').empty();
    $('#gBomColHead').toggle($('#gDept').val()==='pmc');
    loadDeptSettings(function(){
        addItemRow($tb, DEPT_SET[$('#gDept').val()]||{}, false, $('#gDept').val()==='pmc');
        loadVendors(function(rows){
            renderVendorBox($('#gVendorBox'), rows, [], []);
            renderCatChips($('#gVendorCats'), $('#gVendorBox'), $('#gVendorKw'));
            wireSelectedVendors($('#gVendorBox'), $('#gVendorSel'));
        });
    });
    renderNoteTplChips();
    $('#gSave').off('click').on('click', saveNewGroup);
    openMask('gMask');
}
/* 部門切換（只有新增詢價時才能切）：BOM／製程欄只有生管才有，切換部門時把目前項目重新渲染一次
   （保留已填的料號/規格/數量；離開生管身分的話綁定的 BOM／製程沒有意義，一併清掉）。 */
function refreshGDeptUI(){
    var dept = $('#gDept').val(), ds = DEPT_SET[dept] || {}, allowBom = (dept==='pmc');
    var wasAllowBom = $('#gBomColHead').is(':visible');
    $('#gBomColHead').toggle(allowBom);
    if (allowBom !== wasAllowBom) {
        var items = collectItems($('#gItemTbl tbody'), false);
        var $tb = $('#gItemTbl tbody').empty();
        if (!allowBom) { items.forEach(function(it){ it.bom=''; it.bom_ing_fid=''; }); }
        (items.length ? items : [{}]).forEach(function(it){
            var $tr = $(itemRowHtml($tb.find('tr').length+1, it, ds, false, allowBom)).appendTo($tb);
            wireItemRow($tr, ds, allowBom);
        });
    } else {
        $('#gItemTbl tbody tr').each(function(){
            var $tr = $(this);
            $tr.find('.it-spec').prop('readonly', !ds.allow_custom_spec && !!($tr.attr('data-part-id')||$tr.find('.it-part').val())).toggleClass('ro-auto', !ds.allow_custom_spec);
        });
    }
    renderNoteTplChips();
}
function saveNewGroup(){
    var items = collectItems($('#gItemTbl tbody'), false);
    if (!items.length) { $('#gErr').text('至少要有一列詢價項目'); return; }
    var vendorIds = $('#gVendorBox input:checked').map(function(){return $(this).val();}).get();
    if (!vendorIds.length) { $('#gErr').text('至少要選一家廠商'); return; }
    $('#gErr').text('');
    post('create', {
        source_dept: $('#gDept').val(), items: JSON.stringify(items), vendor_ids: JSON.stringify(vendorIds),
        bind_type: $('#gBindType').val(), bind_ref: CUR_BIND ? CUR_BIND.ref : '',
        currency: $('#gCurr').val(), note: $('#gNote').val()
    }, function(res){ closeMask('gMask'); loadList(); alert('已建立，展開成 '+res.doc_ids.length+' 張詢價單'); });
}
function openGroupEdit(gid){
    $.getJSON(API, {action:'get', id:gid}, function(res){
        if (!res.success) { alert(res.message); return; }
        var g = res.group;
        CUR_GROUP = g; CUR_BIND = g.bind_type ? {type:g.bind_type, ref:g.bind_ref, label:g.bind_label} : null;
        $('#gTitle').text('編輯詢價案 #'+g.id);
        $('#gDept').empty().append('<option value="'+g.source_dept+'">'+DEPT_LABEL[g.source_dept]+'</option>').prop('disabled', true).off('change');
        $('#gReq').val(g.requester_name);
        $('#gDate').val(g.inquiry_date);
        $('#gCurr').val(g.currency);
        $('#gNote').val(g.note||'');
        $('#gBindType').val(g.bind_type||'').prop('disabled', true);
        $('#gBindKw').prop('disabled', true);
        if (g.bind_label) { $('#gBindTag').text((g.bind_type==='bom'?'BOM：':'請購單：')+g.bind_label).show(); } else { $('#gBindTag').hide(); }
        $('#gBindClear').hide();
        $('#gVendorWrap').hide();
        $('#gNoteTpl').show();
        renderNoteTplChips(g.source_dept);
        $('#gErr').text('');
        var allowBom = (g.source_dept === 'pmc');
        $('#gBomColHead').toggle(allowBom);
        loadDeptSettings(function(){
            var ds = DEPT_SET[g.source_dept] || {};
            enrichItemsProcNames(g.items, function(items){
                var $tb = $('#gItemTbl tbody').empty();
                (items.length ? items : [{}]).forEach(function(it, i){
                    var $tr = $(itemRowHtml(i+1, it, ds, false, allowBom)).appendTo($tb);
                    wireItemRow($tr, ds, allowBom);
                });
            });
        });
        $('#gSave').off('click').on('click', function(){ saveGroupEdit(g.id); });
        openMask('gMask');
    });
}
/* 項目列若綁了 bom_ing_fid，補上該製程的名稱文字（給 BOM/製程欄顯示用，不影響送出的資料）。
   逐一相異的 BOM 各查一次製程清單，項目列數通常很少，不會是效能問題。 */
function enrichItemsProcNames(items, cb){
    var boms = [];
    items.forEach(function(it){ if (it.bom_ing_fid && boms.indexOf(it.bom) < 0) boms.push(it.bom); });
    if (!boms.length) { cb(items); return; }
    var map = {}, left = boms.length;
    boms.forEach(function(bom){
        $.getJSON(API, {action:'bom_processes', bom:bom}, function(res){
            (res.rows||[]).forEach(function(p){ map[p.bom_ing_fid] = p.process_name; });
            if (--left === 0) {
                items.forEach(function(it){ if (it.bom_ing_fid) it._proc_name = map[it.bom_ing_fid] || ''; });
                cb(items);
            }
        });
    });
}
function saveGroupEdit(gid){
    var items = collectItems($('#gItemTbl tbody'), false);
    if (!items.length) { $('#gErr').text('至少要有一列詢價項目'); return; }
    $('#gErr').text('');
    post('update_group', {
        id: gid, items: JSON.stringify(items), currency: $('#gCurr').val(), note: $('#gNote').val()
    }, function(){ closeMask('gMask'); loadList(); });
}

/* ───────────────────── 詢價項目綁定 BOM／製程（只有生管身分看得到這個欄位） ───────────────────── */
var BOM_PICK_TR = null, BOM_PICK_DEPTSET = null, BOM_PICK_PART = null;
function openBomPick($tr, deptSet){
    BOM_PICK_TR = $tr; BOM_PICK_DEPTSET = deptSet; BOM_PICK_PART = null;
    $('#bpBomKw').val('').prop('disabled', false);
    $('#bpBomPicked').hide().text('');
    $('#bpProcWrap').hide();
    $('#bpProcList').empty();
    $('#bpErr').text('');
    $('#bpClear').toggle(!!$tr.attr('data-bom'));
    openMask('bomPickMask');
    setTimeout(function(){ $('#bpBomKw').trigger('focus'); }, 50);
}
function bomPickSelectBom(bom){
    $('#bpBomKw').prop('disabled', true);
    $('#bpErr').text('');
    $.getJSON(API, {action:'bom_part', bom:bom}, function(res){
        if (!res.success) { $('#bpErr').text(res.message||'找不到這張 BOM'); return; }
        BOM_PICK_PART = res;
        $('#bpBomPicked').html('已選 BOM：<b>'+esc(bom)+'</b>　料號：'+esc(res.part_no||'（無）')+'　<span class="iq-note-manage" id="bpReBom">重選</span>').show();
        $('#bpReBom').on('click', function(){ openBomPick(BOM_PICK_TR, BOM_PICK_DEPTSET); });
        $.getJSON(API, {action:'bom_processes', bom:bom}, function(res2){
            var $list = $('#bpProcList').empty();
            (res2.rows||[]).forEach(function(p){
                var row = $('<div class="bom-proc-row"></div>')
                    .append('<span class="nm">'+esc(p.process_name||('製程#'+p.process_no))+'</span>')
                    .append('<span class="sp">'+esc(p.single_bet_ps||'（無規格備註）')+'</span>');
                if (p.suggested) row.append('<span class="sg">建議</span>');
                row.on('click', function(){ bomPickApply(bom, p); });
                $list.append(row);
            });
            if (!res2.rows || !res2.rows.length) $list.append('<div class="iq-hint">這張 BOM 目前沒有任何製程紀錄。</div>');
            $('#bpProcWrap').show();
        });
    });
}
function bomPickApply(bom, proc){
    var mainCatId = proc && proc.cat ? proc.cat.main_cat_id : null;
    var mainCatName = proc && proc.cat ? proc.cat.main_cat_name : '';
    var conflict = rowMainCatConflict(BOM_PICK_TR.closest('tbody'), BOM_PICK_TR, mainCatId, mainCatName);
    if (conflict) {
        $('#bpErr').text('製程類別跟這張單裡已經綁定的「'+conflict.part+'」（'+conflict.name+'）不一致，同一張詢價單的 BOM 製程必須是同一個加工類別大類，請改選同大類的製程，或另開一張詢價單。');
        return;
    }
    BOM_PICK_TR.attr('data-bom', bom)
        .attr('data-bom-ing-fid', proc ? proc.bom_ing_fid : '')
        .attr('data-main-cat-id', mainCatId || '')
        .attr('data-main-cat-name', mainCatName || '')
        .attr('data-proc-name', proc ? proc.process_name : '');
    var partId = BOM_PICK_PART ? BOM_PICK_PART.part_id : null;
    var partNo = BOM_PICK_PART ? BOM_PICK_PART.part_no : '';
    BOM_PICK_TR.attr('data-part-id', partId || '');
    BOM_PICK_TR.find('.it-part').val(partNo).prop('readonly', true).addClass('ro-auto');
    var specVal = proc ? (proc.single_bet_ps || '') : '';
    var lockSpec = !BOM_PICK_DEPTSET || !BOM_PICK_DEPTSET.allow_custom_spec || !specVal;
    if (specVal || !BOM_PICK_TR.find('.it-spec').val()) { BOM_PICK_TR.find('.it-spec').val(specVal); }
    BOM_PICK_TR.find('.it-spec').prop('readonly', lockSpec).toggleClass('ro-auto', lockSpec);
    if (!BOM_PICK_TR.find('.it-qty').val() && BOM_PICK_PART && BOM_PICK_PART.sqty) { BOM_PICK_TR.find('.it-qty').val(BOM_PICK_PART.sqty); }
    BOM_PICK_TR.find('.bom-cell').html(bomCellHtml({bom:bom, bom_ing_fid: proc ? proc.bom_ing_fid : null, _proc_name: proc ? proc.process_name : ''}));
    wireBomCell(BOM_PICK_TR, BOM_PICK_DEPTSET);
    BOM_PICK_TR.find('.part-link').remove();
    if (partNo) { BOM_PICK_TR.find('.it-part').after(' <span class="part-link fa fa-search" title="開啟料號圖面"></span>'); BOM_PICK_TR.find('.part-link').on('click', function(){ openPartViewerPopup(partNo, bom); }); }
    // 若目前視窗有廠商加工類別篩選區（新增詢價時），自動依這個製程的分類展開/勾選，方便直接看到相關廠商
    if (mainCatId && $('#gVendorCats').is(':visible')) { autoApplyVendorCat(mainCatId, proc.cat.sub_cat_id); }
    closeMask('bomPickMask');
}
function autoApplyVendorCat(mainCatId, subCatId){
    $('#gVendorCats .iq-cat-main-chip').each(function(){
        var txt = $(this).text().replace(' ▾','');
        var grp = (VENDOR_CATS||[]).find(function(g){ return g.main_cat_name===txt; });
        if (grp && grp.main_cat_id===mainCatId && !$(this).hasClass('act')) { $(this).trigger('click'); }
    });
    setTimeout(function(){
        $('#gVendorCats .iq-cat-sub-row .iq-chip').each(function(){
            if ($(this).data('id')===subCatId && !$(this).hasClass('on')) { $(this).trigger('click'); }
        });
    }, 30);
}
(function(){
    var $list = $('<div class="ac-list"></div>').appendTo('body');
    var timer = null;
    $('#bpBomKw').on('input', function(){
        clearTimeout(timer);
        var kw = $(this).val();
        if (!kw) { $list.hide(); return; }
        timer = setTimeout(function(){
            $.getJSON(API, {action:'bom_search', kw:kw}, function(res){
                if (!res.success) return;
                $list.empty();
                (res.rows||[]).forEach(function(r){
                    var d = $('<div></div>').text(r.label).on('click', function(){ $list.hide(); bomPickSelectBom(r.bom); });
                    $list.append(d);
                });
                if (!res.rows || !res.rows.length) $list.append('<div style="color:#999;">查無符合的 BOM／料號</div>');
                var r0 = document.getElementById('bpBomKw').getBoundingClientRect();
                $list.css({left:r0.left+'px', top:(r0.bottom+2)+'px', width:Math.max(260,r0.width)+'px'}).show();
            });
        }, 280);
    });
    $('#bpBomKw').on('blur', function(){ setTimeout(function(){ $list.hide(); }, 200); });
})();
$('#bpNoProc').on('click', function(){
    if (!BOM_PICK_PART) return;
    var bom = $('#bpBomPicked').find('b').text();
    bomPickApply(bom, null);
});
$('#bpClear').on('click', function(){
    if (!BOM_PICK_TR) return;
    BOM_PICK_TR.find('.bom-bound-tag .x').trigger('click');
    closeMask('bomPickMask');
});

/* ───────────────────── 綑定 BOM／請購單 ───────────────────── */
function openBind(gid){
    var g = GROUPS.find(function(x){return x.id===gid;});
    $('#gBindType').val(g.bind_type||'').prop('disabled', false);
    $('#gBindKw').val(g.bind_label||'').prop('disabled', !g.bind_type);
    CUR_BIND = g.bind_type ? {type:g.bind_type, ref:g.bind_ref} : null;
    $('#gBindTag').hide(); $('#gBindClear').toggle(!!g.bind_type);
    $('#gVendorWrap').hide();
    // 重用新增詢價的綑定輸入格，但這裡是獨立動作：改標題與儲存行為
    $('#gTitle').text('詢價案 #'+gid+'　綑定對象');
    $('#gDept').empty().append('<option>'+DEPT_LABEL[g.source_dept]+'</option>').prop('disabled', true).off('change');
    $('#gReq').val(g.requester_name);   // 一律 readonly，不需要再切換
    $('#gDate').val(g.inquiry_date);    // 一律 disabled，不需要再切換
    $('#gCurr').val(g.currency).prop('disabled', true);
    $('#gNote').val(g.note||'').prop('disabled', true);
    $('#gNoteTpl').hide();
    $('#gItemTbl').closest('div').hide();
    $('#gSave').off('click').on('click', function(){
        post('bind', {id:gid, bind_type:$('#gBindType').val(), bind_ref: CUR_BIND ? CUR_BIND.ref : ''}, function(){
            closeMask('gMask'); loadList();
            $('#gCurr,#gNote').prop('disabled', false); $('#gNoteTpl').show(); $('#gItemTbl').closest('div').show();
        });
    });
    openMask('gMask');
}
$('#gBindType').on('change', function(){
    var t = $(this).val();
    $('#gBindKw').prop('disabled', !t).val('');
    $('#gBindClear').toggle(!!t); $('#gBindTag').hide(); CUR_BIND = null;
});
$('#gBindClear').on('click', function(){ $('#gBindType').val(''); $('#gBindKw').prop('disabled', true).val(''); $('#gBindTag').hide(); CUR_BIND = null; $(this).hide(); });
(function(){
    var $list = $('<div class="ac-list"></div>').appendTo('body');
    var timer = null;
    $('#gBindKw').on('input', function(){
        clearTimeout(timer);
        var kw = $(this).val(), type = $('#gBindType').val();
        if (!kw || !type) { $list.hide(); return; }
        timer = setTimeout(function(){
            $.getJSON(API, {action: type==='bom' ? 'bom_search' : 'preq_search', kw:kw}, function(res){
                if (!res.success) return;
                $list.empty();
                (res.rows||[]).forEach(function(r){
                    var ref = type==='bom' ? r.bom : r.ref;
                    var d = $('<div></div>').text(r.label).on('click', function(){
                        $('#gBindKw').val(r.label);
                        CUR_BIND = {type:type, ref:ref, label:r.label};
                        $('#gBindTag').text(r.label).show();
                        $list.hide();
                    });
                    $list.append(d);
                });
                if (!res.rows || !res.rows.length) $list.append('<div style="color:#999;">查無符合的資料</div>');
                var r0 = document.getElementById('gBindKw').getBoundingClientRect();
                $list.css({left:r0.left+'px', top:(r0.bottom+2)+'px', width:Math.max(260,r0.width)+'px'}).show();
            });
        }, 280);
    });
    $('#gBindKw').on('blur', function(){ setTimeout(function(){ $list.hide(); }, 200); });
})();

/* ───────────────────── 新增廠商 ───────────────────── */
var ADDV_GID = 0;
function openAddVendor(gid){
    ADDV_GID = gid;
    $('#avErr').text('');
    var g = GROUPS.find(function(x){return x.id===gid;});
    var existing = (g.docs||[]).map(function(d){return d.vendor_id;});
    $('#avVendorSel').empty();
    loadVendors(function(rows){
        renderVendorBox($('#avVendorBox'), rows, [], existing);
        $('#avVendorKw').val('');
        renderCatChips($('#avVendorCats'), $('#avVendorBox'), $('#avVendorKw'));
        wireSelectedVendors($('#avVendorBox'), $('#avVendorSel'));
    });
    openMask('avMask');
}
$('#avSave').on('click', function(){
    var vendorIds = $('#avVendorBox input:checked').map(function(){return $(this).val();}).get();
    if (!vendorIds.length) { $('#avErr').text('至少要選一家廠商'); return; }
    post('add_vendors', {id:ADDV_GID, vendor_ids:JSON.stringify(vendorIds)}, function(res){ closeMask('avMask'); loadList(); alert('已新增 '+res.doc_ids.length+' 張詢價單'); });
});

/* ───────────────────── 編輯子單 ───────────────────── */
function openDocEdit(did){
    // 子單細節走 group list 快取即可（list 已回傳 docs+items）
    var found = null, parentG = null;
    GROUPS.forEach(function(g){ (g.docs||[]).forEach(function(d){ if (d.id===did) { found=d; parentG=g; } }); });
    if (!found) return;
    CUR_DOC_ID = did; CUR_GROUP = parentG;
    $('#dTitle').text('詢價單 ' + found.doc_no);
    $('#dVendor').val(found.vendor_name);
    $('#dContact').val(found.contact_person||'');
    $('#dPhone').val(found.contact_phone||'');
    $('#dFax').val(found.contact_fax||'');
    $('#dStatus').val(found.status);
    $('#dFollowHint').text(found.follow_parent ? '目前跟隨母單，母單異動時項目會自動同步。' : '目前已脫離母單跟隨，母單異動不會影響這張。');
    $('#dErr').text('');
    var dept = parentG ? parentG.source_dept : '';
    var ds = DEPT_SET[dept] || {};
    var allowBom = (dept === 'pmc');
    $('#dBomColHead').toggle(allowBom);
    enrichItemsProcNames(found.items, function(items){
        var $tb = $('#dItemTbl tbody').empty();
        (items.length ? items : [{}]).forEach(function(it, i){
            var $tr = $(itemRowHtml(i+1, it, ds, true, allowBom)).appendTo($tb);
            wireItemRow($tr, ds, allowBom);
        });
    });
    $('#dSave').off('click').on('click', function(){ saveDocEdit(did); });
    openMask('dMask');
}
function saveDocEdit(did){
    var items = collectItems($('#dItemTbl tbody'), true);
    post('update_doc', {id:did, items: JSON.stringify(items), status: $('#dStatus').val()}, function(){ closeMask('dMask'); loadList(); });
}

/* ───────────────────── 列印 ───────────────────── */
function partyRowsHtml(items){
    var rows = '';
    items.forEach(function(it, i){
        rows += '<tr><td>'+(i+1)+'</td><td class="l">'+esc(it.part_no_text||'')+'</td><td class="l">'+esc(it.spec_text||'')+'</td>'
              + '<td>'+(it.qty!=null && it.qty!=='' ? money(it.qty) : '')+'</td><td>'+(it.unit_price!=null && it.unit_price!=='' ? money(it.unit_price) : '')+'</td></tr>';
    });
    for (var i=items.length; i<10; i++) rows += '<tr><td>&nbsp;</td><td></td><td></td><td></td><td></td></tr>';
    return rows;
}
function oneDocHtml(data){
    var c = data.company, d = data.doc, g = data.group;
    var stampHtml = (window.EGStamp && EGStamp.stamp) ? EGStamp.stamp(g.requester_name, egFmtDate(g.inquiry_date), false, null) : esc(g.requester_name);
    return '<div class="iq-sheet">'
        + '<div class="co">'+esc(c.customer_full)+'</div>'
        + '<div class="addr">'+esc(c.customer_address)+'　電話：'+esc(c.customer_tel)+'　傳真：'+esc(c.customer_fax)+'</div>'
        + '<div class="tt">詢　價　單</div>'
        + '<table class="hd"><tr><td class="lb">廠商名稱：</td><td>'+esc(d.vendor_name)+'</td><td class="lb">單　　號：</td><td>'+esc(d.doc_no)+'</td></tr>'
        + '<tr><td class="lb">聯絡人員：</td><td>'+esc(d.contact_person||'')+'</td><td class="lb">詢價人員：</td><td>'+esc(g.requester_name)+'</td></tr>'
        + '<tr><td class="lb">聯絡電話：</td><td>'+esc(d.contact_phone||'')+'</td><td class="lb">傳真號碼：</td><td>'+esc(d.contact_fax||'')+'</td></tr>'
        + '<tr><td class="lb">幣　　別：</td><td>'+esc(g.currency)+'</td><td class="lb">詢價日期：</td><td>'+egFmtDate(g.inquiry_date)+'</td></tr></table>'
        + '<table class="it"><thead><tr><th style="width:36px;">項次</th><th>產品編號</th><th>規格</th><th style="width:70px;">數量</th><th style="width:90px;">單價</th></tr></thead><tbody>'
        + partyRowsHtml(d.items) + '</tbody></table>'
        + '<div class="note">備註：' + esc(g.note||'') + '</div>'
        + '<table class="sg"><tr><td><div class="lb">經辦：</div><div class="sb">'+stampHtml+'</div></td><td><div class="lb">審核：</div></td><td><div class="lb">簽收：</div></td></tr></table>'
        + '</div>';
}
function printCss(){
    return '@page{size:A4 portrait;margin:14mm 12mm 16mm;}'
        + 'body{font-family:"Microsoft JhengHei","微軟正黑體",sans-serif;color:#000;-webkit-print-color-adjust:exact;print-color-adjust:exact;}'
        + '.iq-sheet{page-break-after:always;}'
        + '.iq-sheet:last-child{page-break-after:auto;}'
        + '.co{text-align:center;font-size:20px;font-weight:bold;letter-spacing:2px;}'
        + '.addr{text-align:center;font-size:12px;color:#333;margin-top:2px;}'
        + '.tt{text-align:center;font-size:17px;font-weight:bold;margin:10px 0;letter-spacing:4px;}'
        + 'table.hd{width:100%;border-collapse:collapse;font-size:13px;margin-bottom:8px;}'
        + 'table.hd td{border:1px solid #999;padding:5px 7px;}'
        + 'table.hd td.lb{background:#F2F2F2;font-weight:bold;width:90px;}'
        + 'table.it{width:100%;border-collapse:collapse;font-size:12.5px;table-layout:fixed;}'
        + 'table.it th,table.it td{border:1px solid #999;padding:4px 6px;text-align:center;word-break:break-all;}'
        + 'table.it th{background:#F2F2F2;}'
        + 'table.it td.l{text-align:left;}'
        + '.note{font-size:12px;margin-top:6px;}'
        + 'table.sg{width:100%;border-collapse:collapse;margin-top:14px;table-layout:fixed;}'
        + 'table.sg td{border:1px solid #999;height:78px;vertical-align:top;padding:4px 6px;width:33.33%;}'
        + '.sg .lb{font-size:12px;color:#333;}'
        + '.sg .sb{text-align:center;margin-top:2px;}'
        + '.stamp-wrap svg,svg.car-stamp{width:85px;height:85px;}'
        + '.iq-foot{position:fixed;right:10mm;bottom:6mm;font-size:9pt;color:#333;}';
}
function egPrintWindow(title, bodyHtml, docNo, pageCount){
    var asHtml = esc(String(docNo||''));
    var css = printCss();
    var w = window.open('', '_blank');
    if (!w) { alert('請允許彈出視窗'); return; }
    var onloadJs = (pageCount ? ('var st=document.createElement("style");st.textContent="@page{ @bottom-left{ content:\'第 \' counter(page) \' 頁／共 \' counter(pages) \' 頁\'; font-size:9pt; color:#333; } @bottom-right{ content:\''+String(docNo||'').replace(/[\'\\\\]/g,'')+'\'; font-size:9pt; color:#333; } }";document.head.appendChild(st);') : '');
    var trigJs = onloadJs + 'setTimeout(function(){window.print();},250);';
    w.document.open();
    w.document.write('<!DOCTYPE html><html><head><meta charset="utf-8"><title>'+esc(title)+'</title><style>'+css+'</style></head><body>'
        + bodyHtml + '<scr'+'ipt>window.onload=function(){'+trigJs+'};</scr'+'ipt></body></html>');
    w.document.close();
    return w;
}
function printOneDoc(did){
    if (!did) return;
    $.getJSON(API, {action:'print_data', id:did}, function(res){
        if (!res.success) { alert(res.message||'取得列印資料失敗'); return; }
        var html = oneDocHtml(res);
        egPrintWindow('詢價單 '+res.doc.doc_no, html, res.as_no, true);
        $.post(API, {action:'print_log', csrf:CSRF, id:did});
    });
}
function printAllDocs(gid){
    var g = GROUPS.find(function(x){return x.id===gid;});
    if (!g || !g.docs || !g.docs.length) { alert('這個詢價案還沒有展開任何子單'); return; }
    var ids = g.docs.map(function(d){return d.id;});
    var htmls = [], asNo = '', doneCnt = 0;
    ids.forEach(function(did, idx){
        $.getJSON(API, {action:'print_data', id:did}, function(res){
            htmls[idx] = res.success ? oneDocHtml(res) : '';
            if (res.success) asNo = res.as_no;
            doneCnt++;
            if (doneCnt === ids.length) {
                egPrintWindow('詢價單（'+ids.length+'家廠商）', htmls.join(''), asNo, true);
                ids.forEach(function(did){ $.post(API, {action:'print_log', csrf:CSRF, id:did}); });
            }
        });
    });
}

/* ───────────────────── 備註常用用語 ───────────────────── */
var NOTE_TPLS = null;
function loadNoteTpls(cb){ $.getJSON(API, {action:'note_tpl_list'}, function(res){ NOTE_TPLS = res.rows||[]; cb && cb(NOTE_TPLS); }); }
/* 建立模式沒給 deptKey 時用目前選的 #gDept；編輯模式呼叫端直接傳該詢價案的 source_dept */
function renderNoteTplChips(deptKey){
    deptKey = deptKey || $('#gDept').val();
    loadNoteTpls(function(rows){
        var $wrap = $('#gNoteTpl').empty();
        rows.filter(function(r){ return r.scope==='public' || r.dept_key===deptKey; }).forEach(function(r){
            var chip = $('<span class="iq-chip"></span>').text(r.content).attr('title', r.content + (r.scope==='dept' ? '（'+DEPT_LABEL[r.dept_key]+'限定）' : '（公開）'));
            chip.on('click', function(){
                var cur = $('#gNote').val();
                $('#gNote').val(cur ? (cur + '\n' + r.content) : r.content);
            });
            $wrap.append(chip);
        });
        $wrap.append('<span class="iq-note-manage" id="btnNoteManage">管理常用用語</span>');
        $('#btnNoteManage').off('click').on('click', openNoteTplManage);
    });
}
function tplDeptSelFill(){
    var $sel = $('#tplDeptSel').empty();
    (PERM.myDepts||[]).forEach(function(d){ $sel.append('<option value="'+d+'">'+DEPT_LABEL[d]+'</option>'); });
}
function renderTplList(){
    loadNoteTpls(function(rows){
        var box = $('#tplList').empty();
        if (!rows.length) { box.append('<div class="iq-hint">目前還沒有任何常用用語。</div>'); return; }
        rows.forEach(function(r){
            var badge = r.scope==='public' ? '公開' : ('部門：'+DEPT_LABEL[r.dept_key]);
            var row = $('<div class="iq-tpl-row"></div>')
                .append('<span class="ct">'+esc(r.content)+'</span>')
                .append('<span class="badge">'+badge+'</span>')
                .append('<span class="by">'+esc(r.created_by_name||'')+'</span>');
            if (r.can_edit) {
                row.append($('<span class="iq-op" style="color:#DD5138;">刪除</span>').on('click', function(){
                    if (!confirm('確定要刪除這個常用用語？')) return;
                    post('note_tpl_delete', {id:r.id}, function(res){ NOTE_TPLS=res.rows; renderTplList(); renderNoteTplChips(); });
                }));
            }
            box.append(row);
        });
    });
}
function openNoteTplManage(){
    $('#tplContent').val(''); $('#tplScope').val('public'); $('#tplDeptWrap').hide(); $('#tplErr').text('');
    tplDeptSelFill();
    renderTplList();
    openMask('tplMask');
}
$('#tplScope').on('change', function(){ $('#tplDeptWrap').toggle($(this).val()==='dept'); });
$('#tplAdd').on('click', function(){
    var content = $('#tplContent').val().trim();
    if (!content) { $('#tplErr').text('請輸入內容'); return; }
    var scope = $('#tplScope').val();
    post('note_tpl_save', {content:content, scope:scope, dept_key: scope==='dept' ? $('#tplDeptSel').val() : ''}, function(res){
        $('#tplContent').val(''); $('#tplErr').text('');
        NOTE_TPLS = res.rows; renderTplList(); renderNoteTplChips();
    });
});

/* ───────────────────── 模組設定（AS 文件綁定＋部門規則） ───────────────────── */
var AS_DOC = null, AS_DOCS = [];
function renderAsDocLabel(){ $('#asDocLabel').text(EGAsDoc.label(AS_DOC)); $('#hdrAsDocNo').text(AS_DOC && AS_DOC.doc_no ? (AS_DOC.doc_no+'　'+AS_DOC.doc_name) : '尚未綁定 AS 文件'); }
function loadAsDocCurrent(){ $.getJSON(API, {action:'asdoc_get'}, function(res){ AS_DOC = (res&&res.success)?res.as_doc:null; renderAsDocLabel(); }); }
$('#btnAsDoc').on('click', function(){
    $.getJSON(API, {action:'asdoc_list'}, function(res){
        if (!res.success) return;
        AS_DOCS = res.docs||[];
        EGAsDoc.open({ docs:AS_DOCS, current: AS_DOC?AS_DOC.id:0, title:'詢價單　AS 文件綁定',
            onSave:function(id){ post('as_doc_save', {doc_id:id}, function(res){ AS_DOC = res.as_doc; renderAsDocLabel(); }); } });
    });
});
function renderDeptSetRows(){
    var box = $('#deptSetRows').empty();
    ['sales','pmc','purchase'].forEach(function(k){
        var s = DEPT_SET[k] || {allow_free_part:0, allow_custom_spec:0};
        var row = $('<div class="iq-set-row"><div class="lb">'+DEPT_LABEL[k]+'</div>'
            + '<label><input type="checkbox" class="st-free"'+(s.allow_free_part?' checked':'')+'> 允許非實際存在之料號詢價</label>'
            + '<label><input type="checkbox" class="st-spec"'+(s.allow_custom_spec?' checked':'')+'> 允許自訂規格</label></div>');
        row.find('input').on('change', function(){
            post('dept_setting_save', {dept_key:k, allow_free_part:row.find('.st-free').is(':checked')?1:0, allow_custom_spec:row.find('.st-spec').is(':checked')?1:0}, function(res){ DEPT_SET = res.dept_set; });
        });
        box.append(row);
    });
}
function renderProcTypeRows(){
    $.getJSON(API, {action:'process_type_list'}, function(res){
        var box = $('#procTypeRows').empty();
        (res.rows||[]).forEach(function(pt){
            var chip = $('<span class="iq-chip"></span>').text(pt.process_type)
                .toggleClass('on', !!pt.enabled)
                .attr('title', pt.cat ? ('對應廠商類別：'+pt.cat.main_cat_name+'／'+pt.cat.sub_cat_name) : '（沒有對應的廠商加工類別）');
            chip.on('click', function(){
                var next = !chip.hasClass('on');
                post('process_type_setting_save', {process_type_id:pt.process_type_id, enabled: next?1:0}, function(){
                    chip.toggleClass('on', next);
                });
            });
            box.append(chip);
        });
    });
}
function docPrefixPreviewText(prefix){
    var d = new Date(), ymd = d.getFullYear()+String(d.getMonth()+1).padStart(2,'0')+String(d.getDate()).padStart(2,'0');
    return '例如：' + (prefix||'RFQ') + ymd + '001';
}
$('#docPrefixInput').on('input', function(){ $('#docPrefixPreview').text(docPrefixPreviewText($(this).val())); });
$('#btnDocPrefix').on('click', function(){
    post('doc_prefix_save', {prefix:$('#docPrefixInput').val()}, function(res){
        $('#docPrefixInput').val(res.prefix);
        $('#docPrefixPreview').text(docPrefixPreviewText(res.prefix));
    });
});
$('#btnSettings').on('click', function(){
    loadAsDocCurrent();
    loadDeptSettings(renderDeptSetRows);
    renderProcTypeRows();
    $.getJSON(API, {action:'doc_prefix_get'}, function(res){
        $('#docPrefixInput').val(res.prefix);
        $('#docPrefixPreview').text(docPrefixPreviewText(res.prefix));
    });
    openMask('setMask');
});
$('#btnPageHelp').on('click', function(){ openMask('helpUseMask'); });
$('#btnNew').on('click', openNewGroup);
$('#btnSearch').on('click', loadList);
$('#fKw').on('keydown', function(e){ if (e.key==='Enter') loadList(); });

if (PERM.canView) { loadDeptSettings(function(){ loadList(); loadAsDocCurrent(); }); }
</script>
</body>
</html>
