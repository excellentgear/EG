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
        .iq-table-wrap { overflow-x:auto; border:1px solid #E8D5B5; border-radius:6px; background:#fff; }
        table.iq-g { width:100%; border-collapse:collapse; font-size:13px; }
        table.iq-g th, table.iq-g td { border:1px solid #EADFC8; padding:6px 8px; text-align:left; vertical-align:top; }
        table.iq-g thead th { background:#F7E0BD; color:#5b3a1e; font-weight:bold; white-space:nowrap; text-align:center; }
        tr.iq-grow { background:#FBF6EC; cursor:pointer; }
        tr.iq-grow:hover { background:#F7E0BD; }
        tr.iq-grow td { font-weight:bold; }
        table.iq-d { width:100%; border-collapse:collapse; font-size:12.5px; margin:4px 0; }
        table.iq-d th, table.iq-d td { border:1px solid #EADFC8; padding:4px 7px; text-align:center; }
        table.iq-d th { background:#FDF3E3; color:#8A5A2B; }
        .iq-op { color:#b5762a; cursor:pointer; margin:0 5px; white-space:nowrap; }
        .iq-op:hover { color:#8A5A2B; text-decoration:underline; }
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
            <label>來源部門</label>
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

        <div class="iq-table-wrap">
        <table class="iq-g">
            <thead><tr><th style="width:26px;"></th><th style="width:70px;">來源</th><th style="width:90px;">詢價日期</th><th>項目摘要</th><th style="width:140px;">詢價人員</th><th style="width:160px;">綁定對象</th><th style="width:70px;">廠商數</th><th style="width:70px;">狀態</th><th style="width:120px;">操作</th></tr></thead>
            <tbody id="gBody"><tr><td colspan="9" style="text-align:center;color:#999;">載入中…</td></tr></tbody>
        </table>
        </div>
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
            <div><label>來源部門</label><select id="gDept"></select></div>
            <div><label>詢價人員</label><input type="text" id="gReq" readonly class="ro-auto" title="固定為目前登入者，不可修改"></div>
            <div><label>詢價日期</label><input type="date" id="gDate" disabled class="ro-auto" title="固定為今天，不可修改"></div>
            <div><label>幣別</label><select id="gCurr"><option>NTD</option><option>USD</option><option>CNY</option><option>JPY</option><option>EUR</option></select></div>
        </div>
        <div style="margin-top:10px;">
            <label>綁定對象（選填，可之後再補）</label>
            <div style="display:flex;gap:8px;align-items:center;">
                <select id="gBindType" style="width:120px;"><option value="">不綁定</option><option value="bom">BOM</option><option value="purchase_request">請購單</option></select>
                <input type="text" id="gBindKw" placeholder="輸入關鍵字搜尋…" style="flex:1;" disabled>
                <span id="gBindTag" style="display:none;" class="iq-bindtag"></span>
                <button type="button" id="gBindClear" class="btn" style="display:none;height:32px;">清除</button>
            </div>
        </div>
        <div style="margin-top:12px;">
            <label>詢價項目</label>
            <table class="item-tbl" id="gItemTbl">
                <thead><tr><th style="width:26px;">項次</th><th>產品編號</th><th>規格</th><th style="width:90px;">數量</th><th style="width:30px;"></th></tr></thead>
                <tbody data-eg-row-add="gItemAdd" data-eg-row-del="gItemDel"></tbody>
            </table>
            <div class="iq-hint" id="gItemHint"></div>
        </div>
        <div id="gVendorWrap" style="margin-top:12px;">
            <label>廠商（可多選，建立後自動展開成多張詢價單）</label>
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
        <div class="iq-cat-wrap" id="avVendorCats"></div>
        <input type="text" id="avVendorKw" placeholder="輸入廠商名稱或代號篩選…" style="margin-bottom:6px;">
        <div class="vendor-box" id="avVendorBox"></div>
        <div class="iq-err" id="avErr"></div>
    </div>
    <div class="m-foot"><button onclick="closeMask('avMask')">取消</button><button class="b-ok" id="avSave">新增</button></div>
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
            <thead><tr><th style="width:26px;">項次</th><th>產品編號</th><th>規格</th><th style="width:85px;">數量</th><th style="width:110px;">單價</th><th style="width:30px;"></th></tr></thead>
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
        <label>各部門使用規則</label>
        <div id="deptSetRows"></div>
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
        <p>業務通常把「產品編號」綁到實際料號主檔；生管可以把整個詢價案綁到一張 BOM；採購可以綁到一張請購單。
        三種都不是必填，之後隨時可以在編輯畫面補上或改掉。</p>
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
    if (!items || !items.length) return '（無項目）';
    var s = items.map(function(it){ return (it.part_no_text||'（未填）') + (it.qty!=null && it.qty!=='' ? '×'+money(it.qty) : ''); });
    return s.slice(0,3).join('、') + (s.length>3 ? '…共'+s.length+'項' : '');
}
function renderList(){
    var tb = $('#gBody').empty();
    if (!GROUPS.length) { tb.append('<tr><td colspan="9" style="text-align:center;color:#999;">沒有符合條件的詢價案</td></tr>'); $('#pager').empty(); return; }
    var total = GROUPS.length, pages = Math.max(1, Math.ceil(total/PSIZE));
    if (PAGE > pages) PAGE = pages;
    var slice = GROUPS.slice((PAGE-1)*PSIZE, PAGE*PSIZE);
    slice.forEach(function(g){
        var canEdit = PERM.canAdmin || (PERM.myDepts||[]).indexOf(g.source_dept) >= 0;
        var bindTxt = g.bind_label ? ('<span class="iq-bindtag">' + (g.bind_type==='bom'?'BOM':'請購單') + '：' + esc(g.bind_label) + '</span>') : '<span style="color:#999;">未綁定</span>';
        var gr = $('<tr class="iq-grow" data-id="'+g.id+'"><td><i class="fa fa-caret-right"></i></td><td>'+DEPT_LABEL[g.source_dept]+'</td><td>'+esc(g.inquiry_date)+'</td>'
            +'<td>'+esc(itemSummary(g.items))+'</td><td>'+esc(g.requester_name)+'</td><td>'+bindTxt+'</td>'
            +'<td style="text-align:center;">'+(g.doc_count||0)+'</td>'
            +'<td style="text-align:center;"><span class="iq-badge '+(g.status==='closed'?'closed':'open')+'">'+(g.status==='closed'?'已結案':'進行中')+'</span></td>'
            +'<td class="noexp"></td></tr>');
        var ops = $('<td class="noexp"></td>');
        if (canEdit) {
            ops.append('<span class="iq-op" data-act="edit">編輯</span>');
            ops.append('<span class="iq-op" data-act="bind">綁定</span>');
            ops.append('<span class="iq-op" data-act="addv">新增廠商</span>');
            ops.append('<span class="iq-op" data-act="toggle">'+(g.status==='closed'?'重新開啟':'結案')+'</span>');
            ops.append('<span class="iq-op" data-act="del" style="color:#DD5138;">刪除</span>');
        }
        ops.find('span').attr('data-gid', g.id);
        gr.find('td.noexp').last().replaceWith(ops);
        gr.find('.iq-op').on('click', function(e){ e.stopPropagation(); groupOp($(this).data('act'), g.id); });
        tb.append(gr);
        var dRow = $('<tr class="iq-dsub" data-gid="'+g.id+'" style="display:none;"><td></td><td colspan="8"></td></tr>');
        dRow.find('td').last().append(renderDocsTable(g));
        tb.append(dRow);
        gr.on('click', function(){
            dRow.toggle();
            gr.find('i').toggleClass('fa-caret-right fa-caret-down');
        });
    });
    var pg = $('#pager').empty();
    for (var i=1;i<=pages;i++){ (function(i){ var b=$('<button>'+i+'</button>').toggleClass('on', i===PAGE).on('click', function(){ PAGE=i; renderList(); }); pg.append(b); })(i); }
}
function renderDocsTable(g){
    var canEdit = PERM.canAdmin || (PERM.myDepts||[]).indexOf(g.source_dept) >= 0;
    var tbl = $('<table class="iq-d"><thead><tr><th>單號</th><th>廠商</th><th>聯絡人員</th><th>狀態</th><th>跟隨母單</th><th>項目數</th><th>單價合計</th><th>操作</th></tr></thead><tbody></tbody></table>');
    var tb = tbl.find('tbody');
    (g.docs||[]).forEach(function(d){
        var total = 0, hasPrice = false;
        (d.items||[]).forEach(function(it){ if (it.unit_price!=null && it.unit_price!=='') { hasPrice = true; total += parseFloat(it.unit_price) * (parseFloat(it.qty)||0); } });
        var row = $('<tr><td>'+esc(d.doc_no)+'</td><td>'+esc(d.vendor_name)+'</td><td>'+esc(d.contact_person||'')+'</td>'
            +'<td><span class="iq-badge '+d.status+'">'+({open:'等待報價',replied:'已回覆',void:'不再使用'}[d.status]||d.status)+'</span></td>'
            +'<td><span class="iq-badge '+(d.follow_parent?'follow':'detach')+'">'+(d.follow_parent?'跟隨母單':'已脫離')+'</span></td>'
            +'<td>'+(d.items||[]).length+'</td><td>'+(hasPrice ? money(total) : '—')+'</td><td></td></tr>');
        var ops = row.find('td').last();
        ops.append('<span class="iq-op" data-act="print">列印</span>');
        if (canEdit) {
            ops.append('<span class="iq-op" data-act="edit">編輯</span>');
            if (!d.follow_parent) ops.append('<span class="iq-op" data-act="refollow">重新跟隨</span>');
            ops.append('<span class="iq-op" data-act="del" style="color:#DD5138;">刪除</span>');
        }
        ops.find('.iq-op').on('click', function(e){ e.stopPropagation(); docOp($(this).data('act'), d.id); });
        tb.append(row);
    });
    var foot = $('<div style="margin-top:4px;">');
    if (canEdit) foot.append('<button class="btn-warm" style="height:28px;font-size:12px;" onclick="openAddVendor('+g.id+')">新增廠商</button> ');
    foot.append('<button style="height:28px;font-size:12px;border:1px solid #D8BE93;background:#fff;border-radius:4px;" onclick="printAllDocs('+g.id+')">列印全部（展開一次列印）</button>');
    if (g.note) foot.append('<div class="iq-hint" style="margin-top:6px;">備註：'+esc(g.note)+'</div>');
    return $('<div></div>').append(tbl).append(foot);
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
function itemRowHtml(seq, it, deptSet, showPrice){
    it = it || {};
    var lockSpec = !!deptSet && !deptSet.allow_custom_spec && (it.part_id||it.part_no_text);
    var row = '<tr data-part-id="'+(it.part_id||'')+'" data-gi="'+(it.group_item_id||'')+'">'
        +'<td>'+seq+'</td>'
        +'<td><input type="text" class="it-part" value="'+esc(it.part_no_text||'')+'" placeholder="輸入料號搜尋…"></td>'
        +'<td><input type="text" class="it-spec" value="'+esc(it.spec_text||'')+'"'+(lockSpec?' readonly':'')+'></td>'
        +'<td><input type="number" step="0.001" class="it-qty" value="'+(it.qty!=null?it.qty:'')+'"></td>';
    if (showPrice) row += '<td><input type="number" step="0.0001" class="it-price" value="'+(it.unit_price!=null?it.unit_price:'')+'"></td>';
    row += '<td><span class="item-del fa fa-times" title="刪除這一列"></span></td></tr>';
    return row;
}
function renumberItems($tbody){ $tbody.find('tr').each(function(i){ $(this).find('td:first').text(i+1); }); }
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
function addItemRow($tbody, deptSet, showPrice){
    var seq = $tbody.find('tr').length+1;
    var $tr = $(itemRowHtml(seq, {}, deptSet, showPrice)).appendTo($tbody);
    bindPartSearch($tr.find('.it-part'), deptSet);
    $tr.find('.item-del').on('click', function(){ if ($tbody.find('tr').length>1) { $tr.remove(); renumberItems($tbody); } });
    return $tr;
}
function collectItems($tbody, showPrice){
    var out = [];
    $tbody.find('tr').each(function(){
        var $tr = $(this);
        var partNo = $tr.find('.it-part').val().trim();
        var spec = $tr.find('.it-spec').val().trim();
        var qty = $tr.find('.it-qty').val();
        if (!partNo && !spec && !qty) return;
        var it = {
            part_id: $tr.attr('data-part-id') || '',
            part_no_text: partNo,
            spec_text: spec,
            qty: qty === '' ? '' : qty,
            group_item_id: $tr.attr('data-gi') || ''
        };
        if (showPrice) it.unit_price = $tr.find('.it-price').val();
        out.push(it);
    });
    return out;
}

/* window.gItemAdd / gItemDel / dItemAdd / dItemDel —— 供共用檔 eg_input_rules.js 的鍵盤↓↑增刪列呼叫（無參數） */
window.gItemAdd = function(){ var ds = DEPT_SET[$('#gDept').val()] || {}; addItemRow($('#gItemTbl tbody'), ds, false); };
window.gItemDel = function(){ var $tb=$('#gItemTbl tbody'); if ($tb.find('tr').length>1){ $tb.find('tr').last().remove(); renumberItems($tb); } };
window.dItemAdd = function(){ var ds = CUR_GROUP ? (DEPT_SET[CUR_GROUP.source_dept]||{}) : {}; addItemRow($('#dItemTbl tbody'), ds, true); };
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
        var lb = $('<label></label>').attr('data-cats', (v.sub_cat_ids||[]).join(',')).append(
            $('<input type="checkbox">').val(v.maker_id_no).prop('checked', chk).prop('disabled', dis)
        ).append(' ' + v.maker_id_no + '　' + esc(v.maker_id) + (dis ? '（已在詢價案內）' : ''));
        $box.append(lb);
    });
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
function renderCatChips($wrap, $box, $kwInput){
    loadVendorCats(function(groups){
        $wrap.empty();
        if (!groups.length) return;
        groups.forEach(function(g){
            $wrap.append('<span class="iq-cat-grp">'+esc(g.main_cat_name)+'：</span>');
            g.subs.forEach(function(s){
                var chip = $('<span class="iq-chip"></span>').text(s.sub_cat_name).data('id', s.sub_cat_id);
                chip.on('click', function(){
                    chip.toggleClass('on');
                    filterVendorBox($box, $kwInput.val(), selectedCats($wrap));
                });
                $wrap.append(chip);
            });
        });
    });
    $kwInput.off('input.cat').on('input.cat', function(){ filterVendorBox($box, $(this).val(), selectedCats($wrap)); });
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
    $('#gNoteTpl').show();
    var $tb = $('#gItemTbl tbody').empty();
    loadDeptSettings(function(){
        addItemRow($tb, DEPT_SET[$('#gDept').val()]||{}, false);
        loadVendors(function(rows){
            renderVendorBox($('#gVendorBox'), rows, [], []);
            renderCatChips($('#gVendorCats'), $('#gVendorBox'), $('#gVendorKw'));
        });
    });
    renderNoteTplChips();
    $('#gSave').off('click').on('click', saveNewGroup);
    openMask('gMask');
}
function refreshGDeptUI(){
    var ds = DEPT_SET[$('#gDept').val()] || {};
    $('#gItemTbl tbody tr').each(function(){
        var $tr = $(this);
        $tr.find('.it-spec').prop('readonly', !ds.allow_custom_spec && ($tr.attr('data-part-id')||$tr.find('.it-part').val())).toggleClass('ro-auto', !ds.allow_custom_spec);
    });
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
        loadDeptSettings(function(){
            var ds = DEPT_SET[g.source_dept] || {};
            var $tb = $('#gItemTbl tbody').empty();
            (g.items.length ? g.items : [{}]).forEach(function(it, i){
                var $tr = $(itemRowHtml(i+1, it, ds, false)).appendTo($tb);
                bindPartSearch($tr.find('.it-part'), ds);
                $tr.find('.item-del').on('click', function(){ if ($tb.find('tr').length>1) { $tr.remove(); renumberItems($tb); } });
            });
        });
        $('#gSave').off('click').on('click', function(){ saveGroupEdit(g.id); });
        openMask('gMask');
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
    loadVendors(function(rows){
        renderVendorBox($('#avVendorBox'), rows, [], existing);
        $('#avVendorKw').val('');
        renderCatChips($('#avVendorCats'), $('#avVendorBox'), $('#avVendorKw'));
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
    $.getJSON(API, {action:'get', id: 0}, function(){}); // no-op guard
    // 子單細節走 group list 快取即可（list 已回傳 docs+items），但求保險改即時打一次 get 以母單帶出
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
    var ds = parentG ? (DEPT_SET[parentG.source_dept]||{}) : {};
    var $tb = $('#dItemTbl tbody').empty();
    (found.items.length ? found.items : [{}]).forEach(function(it, i){
        var $tr = $(itemRowHtml(i+1, it, ds, true)).appendTo($tb);
        bindPartSearch($tr.find('.it-part'), ds);
        $tr.find('.item-del').on('click', function(){ if ($tb.find('tr').length>1) { $tr.remove(); renumberItems($tb); } });
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
$('#btnSettings').on('click', function(){
    loadAsDocCurrent();
    loadDeptSettings(renderDeptSetRows);
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
