<?php
/**
 * 型態識別文件管制表
 * 每個料號「一份」型態配置清單（2026-08-12 使用者拍板改版，原本一個料號×一種製程各開一份，
 * 造成同一張共用圖面在多份管制表重複出現）：逐列記錄定義該料號目前狀態的文件（原圖/報價單/
 * 加工圖/產品開發評估表/PFMEA/檢驗報告…），每一列自己標記「所屬製程」——來源可辨識出製程的
 * （報價附件對應到的報價項目有勾選製程）自動帶入，共用文件（如原圖，或無法辨識製程來源的
 * 料號附件）留空即代表適用全部製程；標籤可手動修改或清空。
 * 可連結「外來文件清單」既有附件（即時查詢顯示，不落地快照，來源更新這裡就跟著變）。
 * 本頁自身的 AS 文件編號一律走 asdoc_lib 動態綁定顯示（管理員於「AS文件綁定」設定），
 * 不可寫死——填寫範本檔名裡的 RTD630EC0A00 其實是範本內的「產品編號」欄位值，不是本表編號，
 * 2026-08-11 使用者發現先前誤植後修正，往後也不要再從檔名反推 AS 編號。
 * 資料/權限見 src/common/type_id_ctrl_lib.php；資料操作走 src/store/ConfigIdDoc_API.php。
 */
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/TD/type_id_ctrl_doc.php";
    header("Location:../../index.php");
    exit;
}
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/asdoc_lib.php';
include_once '../../src/common/type_id_ctrl_lib.php';

$db = (new DBConnection())->getPDO();
type_id_ctrl_ensure_schema($db);
$icUser = type_id_ctrl_current_user($db);
$perms = type_id_ctrl_perms($db, $icUser);
if (!$perms['canView']) {
    // 沒有本模組角色，仍放行（AS9100 文件全員可檢閱），但不給編輯操作
}
$roleLabel = $perms['isAdmin'] ? '管理者' : ($perms['canAdmin'] ? '型態文件管理員' : ($perms['canEdit'] ? '型態文件登錄' : ($perms['canView'] ? '型態文件檢閱' : '無權限')));
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>型態識別文件管制表</title>
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
        .help-doc ul { margin:4px 0 8px; padding-left:20px; }
        .help-doc li { margin:2px 0; }
        .help-doc .tip { background:#FFF7E8; border:1px dashed #F0A24B; border-radius:6px; padding:6px 10px; margin:6px 0; }
        .ic-toolbar { display:flex; flex-wrap:wrap; gap:6px; align-items:center; clear:both;
            border:1.5px solid #E8D5B5; border-radius:8px; padding:8px 10px; margin-bottom:10px; background:#FDF8EF; }
        .ic-toolbar label { margin:0; font-size:13px; color:#5b3a1e; }
        .ic-toolbar select, .ic-toolbar input[type=text], .ic-toolbar button {
            height:30px; font-size:13px; line-height:1; padding:0 10px; border:1px solid #D8BE93;
            border-radius:4px; background:#fff; color:#5b3a1e; cursor:pointer; }
        .ic-toolbar button:hover { background:#F7E0BD; }
        .ic-toolbar .btn-warm { background:#F0A24B; color:#fff; border-color:#d98a33; }
        .ic-toolbar .btn-warm:hover { background:#d98a33; }
        .ic-role-badge { margin-left:auto; font-size:13px; color:#5b3a1e; background:#F7E0BD; border-radius:12px; padding:4px 12px; }
        .ic-role-badge .fa-question-circle { cursor:pointer; color:#b5762a; margin-left:5px; }
        .ic-pager { display:flex; align-items:center; gap:6px; justify-content:flex-end; margin-bottom:6px; font-size:13px; color:#5b3a1e; }
        .ic-pager label { margin:0; }
        .ic-pager select { height:26px; font-size:12px; padding:0 4px; border:1px solid #D8BE93; border-radius:4px; background:#fff; color:#5b3a1e; }
        .ic-pager .ic-row-btn:disabled { opacity:.4; cursor:default; }
        .ic-table-wrap { overflow-x:auto; border:1px solid #E8D5B5; border-radius:6px; background:#fff; }
        table.ic-table { width:100%; border-collapse:collapse; font-size:13px; }
        table.ic-table th, table.ic-table td { border:1px solid #EADFC8; padding:5px 8px; text-align:center; }
        table.ic-table thead th { background:#F7E0BD; color:#5b3a1e; font-weight:bold; }
        table.ic-table tbody tr:nth-child(even) { background:#FBF6EC; }
        table.ic-table tbody tr:hover { background:#FBF0DD; }
        table.ic-table td.t-left { text-align:left; }
        .ic-op { color:#b5762a; cursor:pointer; margin:0 4px; }
        .ic-op:hover { color:#8A5A2B; text-decoration:underline; }
        .ic-part-lnk { color:#b5762a; text-decoration:underline; cursor:pointer; }
        .ic-mask { display:none; position:fixed; inset:0; background:rgba(60,40,20,.45); z-index:1050; }
        .ic-modal { background:#fff; border-radius:8px; max-width:600px; margin:36px auto; box-shadow:0 5px 25px rgba(0,0,0,.3);
            max-height:90vh; display:flex; flex-direction:column; }
        .ic-modal.xwide { max-width:1080px; }
        .ic-modal .m-head { background:#F7E0BD; color:#5b3a1e; font-weight:bold; padding:10px 15px; border-radius:8px 8px 0 0;
            display:flex; justify-content:space-between; }
        .ic-modal .m-head .m-close { cursor:pointer; color:#b5762a; }
        .ic-modal .m-body { padding:15px; overflow-y:auto; }
        .ic-modal .m-body label { display:block; font-size:13px; color:#5b3a1e; margin:9px 0 3px; }
        .ic-modal .m-body input[type=text], .ic-modal .m-body input[type=date], .ic-modal .m-body select {
            width:100%; border:1px solid #D8BE93; border-radius:4px; padding:5px 8px; font-size:13px; box-sizing:border-box; }
        .ic-modal .m-foot { padding:10px 15px; border-top:1px solid #EADFC8; text-align:right; }
        .ic-modal .m-foot button { height:30px; padding:0 16px; border-radius:4px; font-size:13px; border:1px solid #d98a33; cursor:pointer; }
        .ic-modal .m-foot .b-ok { background:#F0A24B; color:#fff; }
        .ic-modal .m-foot .b-cancel { background:#fff; color:#5b3a1e; border-color:#D8BE93; margin-right:6px; }
        .ic-head-grid { display:grid; grid-template-columns:1fr 1fr 1fr; gap:0 14px; }
        .ic-part-box { display:flex; gap:6px; align-items:center; }
        .ic-part-box input[readonly] { background:#F7F2E6; }
        table.ic-item-table { width:100%; border-collapse:collapse; font-size:12px; margin-top:6px; }
        table.ic-item-table th, table.ic-item-table td { border:1px solid #EADFC8; padding:3px 4px; }
        table.ic-item-table thead th { background:#F7E0BD; color:#5b3a1e; }
        table.ic-item-table input[type=text], table.ic-item-table input[type=date], table.ic-item-table select {
            width:100%; box-sizing:border-box; border:1px solid #D8BE93; border-radius:3px; padding:3px 4px; font-size:12px; }
        table.ic-item-table input[disabled] { background:#F7F2E6; color:#5b3a1e; }
        table.ic-item-table input.f-proc-hint:placeholder-shown { background:#FFF3E2; border-color:#F0A24B; }
        table.ic-item-table td.seq { width:32px; text-align:center; color:#8a6d45; }
        table.ic-item-table td.op { width:100px; white-space:nowrap; text-align:center; }
        .ic-link-badge { font-size:10px; color:#8A5A2B; background:#F7E0BD; border-radius:8px; padding:0 6px; margin-left:2px; white-space:nowrap; }
        .ic-broken-badge { font-size:10px; color:#DD5138; background:#ffe1de; border-radius:8px; padding:0 6px; margin-left:2px; }
        .ic-row-btn { border:1px solid #D8BE93; background:#fff; color:#5b3a1e; border-radius:4px; padding:2px 6px; font-size:11px; cursor:pointer; }
        .ic-row-btn:hover { background:#F7E0BD; }
        .ic-row-btn.del { color:#DD5138; border-color:#f0c4bd; }
        /* 建議名單「來源」標籤（暖色系固定調色盤，見 ai-rules/10；顏色不是唯一資訊，另有文字） */
        .ic-src { display:inline-block; padding:1px 7px; border-radius:9px; font-size:11px; white-space:nowrap; }
        .ic-src.s-ext   { background:#F7E0BD; color:#8A5A2B; }
        .ic-src.s-pfmea { background:#F0A24B; color:#fff; }
        .ic-src.s-both  { background:#C9772A; color:#fff; }
        /* 上傳跳窗：即時錯誤原因（表單三總則③，不可只在送出時丟一句「資料有誤」） */
        .ic-err { color:#DD5138; font-size:12px; margin:3px 0 0; display:none; }
        .ic-err.on { display:block; }
        .ic-bad { border-color:#DD5138 !important; background:#FFF3F0; }
        .ic-cat-list { border:1px solid #D8BE93; border-radius:4px; padding:6px 9px; max-height:150px; overflow-y:auto; background:#fffdf8; }
        .ic-cat-list label { display:inline-block !important; margin:2px 14px 2px 0 !important; font-size:13px; cursor:pointer; }
        .ic-cat-list input { margin-right:4px; }
        .ic-noperm { margin:40px auto; max-width:520px; text-align:center; border:1.5px solid #E8D5B5; border-radius:10px;
            padding:30px; background:#FDF8EF; color:#5b3a1e; }
        .ic-status { display:inline-block; font-size:11px; border-radius:10px; padding:2px 9px; white-space:nowrap; }
        .ic-status.st-pending { background:#F7E0BD; color:#7a5217; }
        .ic-status.st-recheck { background:#DD5138; color:#fff; }
        .ic-status.st-confirmed { background:#F0A24B; color:#fff; }
        td.drg { width:22px; text-align:center; color:#b0a390; cursor:grab; }
        td.drg:active { cursor:grabbing; }
        table.ic-item-table tr.ic-excluded { opacity:.5; background:#f3ede0 !important; }
        table.ic-item-table tr.drag-over { outline:2px dashed #F0A24B; outline-offset:-2px; }
        .ic-chk { display:flex; align-items:center; gap:4px; font-size:11px; white-space:nowrap; cursor:pointer; margin:0; }
        .ic-hdr-info { margin-top:8px; padding:6px 10px; background:#FFF7E8; border:1px dashed #F0A24B; border-radius:6px;
            font-size:12px; color:#5b3a1e; display:flex; flex-wrap:wrap; gap:4px 16px; align-items:center; }
        .stamp-wrap { display:inline-block; text-align:center; margin:2px 10px 2px 0; }
        @media print { .ic-toolbar, .ic-pager, #btnBackTop, .nav_menu, .left_col, footer { display:none !important; } .right_col { margin:0 !important; padding:0 !important; } }
    </style>
</head>
<body class="nav-sm">
<div class="container body">
<div class="main_container">
    <?php include '../partPage/sideAndTopBarMenu.html' ?>
    <div class="right_col" role="main">
        <div class="page-title" style="display:flex;align-items:center;flex-wrap:wrap;">
            <h2 style="margin:6px 0;">型態識別文件管制表
                <small style="color:#8a6d45;">AS文件編號：<span id="hdrAsDocNo">載入中…</span> ｜ 每料號一份配置文件清單</small></h2>
            <button id="btnPageHelp" class="page-help-btn" style="margin-left:auto;"><i class="fa fa-question-circle"></i> 使用說明</button>
        </div>
        <div class="clearfix"></div>

<?php if (!$perms['canView']): ?>
        <div class="ic-noperm">
            <h4><i class="fa fa-lock"></i> 無型態識別文件檢閱權限</h4>
            <p>請洽管理者於「使用者權限設定」指派「型態文件檢閱／登錄／管理員」角色。</p>
        </div>
<?php else: ?>
        <div class="ic-toolbar">
            <label>搜尋</label>
            <input type="text" id="kwInput" placeholder="文件編號／料號／客戶" style="width:160px;">
            <label>客戶</label>
            <input type="text" id="filterCustomer" placeholder="客戶ID或名稱" style="width:130px;">
            <label>料號</label>
            <input type="text" id="filterPartNo" placeholder="料號" style="width:130px;">
            <label>狀態</label>
            <select id="statusFilter">
                <option value="">全部</option>
                <option value="pending">待確認</option>
                <option value="needs_recheck">需重新確認</option>
                <option value="confirmed">已確認</option>
            </select>
            <label>PFMEA</label>
            <select id="pfmeaFilter" title="是否已在「潛在失效模式及效應分析」建檔">
                <option value="">全部</option>
                <option value="yes">PFMEA 已建立</option>
                <option value="no">PFMEA 未建立</option>
            </select>
            <button class="btn-warm" id="btnAdd" style="<?= $perms['canEdit']?'':'display:none;' ?>"><i class="fa fa-plus"></i> 新增</button>
            <span style="border-left:1px solid #D8BE93;height:20px;"></span>
            <button class="btn-warm" id="btnScanMissing" style="<?= $perms['canEdit']?'':'display:none;' ?>" title="掃描「外來文件清單有附件」或「PFMEA 已建檔」、但還沒建立型態識別文件管制表的料號"><i class="fa fa-search"></i> 掃描待建立料號</button>
            <input type="text" id="syncPartNo" placeholder="或手動指定單一料號同步" style="width:150px;<?= $perms['canEdit']?'':'display:none;' ?>" autocomplete="off">
            <button id="btnSyncPart" style="<?= $perms['canEdit']?'':'display:none;' ?>" title="依此料號的訂單/報價單製程，自動建立(或更新)各製程的型態識別文件管制表，並同步外來文件清單附件"><i class="fa fa-refresh"></i> 同步</button>
            <button id="btnAsDoc" style="<?= $perms['canAdmin']?'':'display:none;' ?>"><i class="fa fa-link"></i> AS文件綁定</button>
            <button id="btnOwnDrawCats" style="<?= $perms['canAdmin']?'':'display:none;' ?>" title="設定哪些廠內「自家出的圖」標籤也要納入本模組的自動同步來源"><i class="fa fa-picture-o"></i> 廠內圖面標籤設定</button>
            <span style="border-left:1px solid #D8BE93;height:20px;<?= $perms['canAdmin']?'':'display:none;' ?>"></span>
            <button id="btnBatchConfirm" style="<?= $perms['canAdmin']?'':'display:none;' ?>" title="批次確認勾選的清單，確認者自動記為目前登入者"><i class="fa fa-check-square-o"></i> 批次確認清單</button>
            <button id="btnCsv"><i class="fa fa-file-text-o"></i> 匯出CSV</button>
            <button id="btnPrintAll" title="依目前篩選條件，逐筆列印所有搜尋結果"><i class="fa fa-print"></i> 列印全部搜尋結果</button>
            <span class="ic-role-badge">目前角色：<b><?= htmlspecialchars($roleLabel) ?></b>
                <i class="fa fa-question-circle" id="btnRoleHelp" title="角色權限說明"></i></span>
        </div>

        <div class="ic-pager">
            <span id="pagerInfo" style="color:#8a6d45;">共 0 筆</span>
            <label>每頁</label>
            <select id="pageSize">
                <option value="5">5</option>
                <option value="10" selected>10</option>
                <option value="20">20</option>
                <option value="50">50</option>
            </select>
            <button type="button" class="ic-row-btn" id="pagePrev"><i class="fa fa-chevron-left"></i></button>
            <span id="pagerLabel">第 1／1 頁</span>
            <button type="button" class="ic-row-btn" id="pageNext"><i class="fa fa-chevron-right"></i></button>
        </div>
        <div class="ic-table-wrap">
            <table class="ic-table" id="icTable">
                <thead><tr>
                    <th style="<?= $perms['canAdmin']?'':'display:none;' ?>"><input type="checkbox" id="ckAll" data-eg-skip="1" title="全選/取消全選"></th>
                    <th>文件編號</th><th>客戶</th><th>產品編號(料號)</th><th>內容項次</th><th>PFMEA</th><th>確認狀態</th>
                    <th>建立人</th><th>建立時間</th><th>操作</th>
                </tr></thead>
                <tbody id="icBody"><tr><td colspan="10" style="padding:20px;color:#8a6d45;">載入中…</td></tr></tbody>
            </table>
        </div>
<?php endif; ?>
    </div>
</div>
<button id="btnBackTop" title="回頂端" style="display:none;position:fixed;right:26px;bottom:26px;width:42px;height:42px;border-radius:50%;background:#F0A24B;color:#fff;border:1px solid #d98a33;box-shadow:0 2px 8px rgba(0,0,0,.25);font-size:16px;cursor:pointer;z-index:900;"><i class="fa fa-arrow-up"></i></button>
</div>

<!-- 新增/編輯 -->
<div class="ic-mask" id="editMask"><div class="ic-modal xwide">
    <div class="m-head"><span id="editTitle">型態識別文件管制表</span><span class="m-close" onclick="closeMask('editMask')">✕</span></div>
    <div class="m-body">
        <div class="ic-head-grid" style="grid-template-columns:1fr 1fr;">
            <div>
                <label>客戶</label>
                <input type="text" id="fCustomerName" readonly data-eg-skip="1">
                <input type="hidden" id="fCustomerId" value="">
            </div>
            <div>
                <label>建立日期(最早外來文件日期)</label>
                <input type="text" id="fEarliestDate" readonly data-eg-skip="1">
            </div>
        </div>
        <div class="ic-head-grid" style="grid-template-columns:1fr 1fr;margin-top:8px;">
            <div>
                <label>料號 <span style="color:#DD5138;">*</span></label>
                <input type="text" id="fPartNo" placeholder="輸入部分料號或圖號即可搜尋" autocomplete="off">
                <input type="hidden" id="fPartDId" value="0">
            </div>
            <div>
                <label>製程</label>
                <input type="text" id="fProcessSummary" readonly data-eg-skip="1">
            </div>
        </div>
        <div style="margin-top:6px;font-size:12px;color:#8a6d45;">文件編號：<b id="fDocNo">存檔後自動產生</b>
            ｜ 建立：<span id="fCreatedInfo">—</span></div>
        <div class="ic-hdr-info">
            <span>簽章日期(最新日期)：<b id="fLatestDate">—</b></span>
            <span>確認狀態：<span id="fReviewBadge" class="ic-status st-pending">待確認</span></span>
            <span id="fConfirmedInfo" style="color:#8a6d45;"></span>
            <button type="button" class="ic-row-btn" id="btnConfirm" style="margin-left:auto;<?= $perms['canEdit']?'':'display:none;' ?>" onclick="saveAll(true)"><i class="fa fa-check"></i> 確認清單</button>
        </div>

        <table class="ic-item-table">
            <thead><tr>
                <th style="width:20px;"></th>
                <th style="width:26px;">項次</th>
                <th style="width:13%;">型態項目名稱</th>
                <th style="width:10%;">型態生效日期</th>
                <th style="width:9%;">型態類別</th>
                <th style="width:12%;">所屬製程</th>
                <th>版別／文件編號</th>
                <th class="op">操作</th>
            </tr></thead>
            <tbody id="itemBody" data-eg-row-add="icAddRow" data-eg-row-del="icDelRow"></tbody>
        </table>
        <div style="margin-top:6px;">
            <button type="button" class="ic-row-btn" onclick="icAddRow()"><i class="fa fa-plus"></i> 新增一列</button>
            <button type="button" class="ic-row-btn" id="btnUpload" onclick="openUpload()" style="<?= $perms['canEdit']?'':'display:none;' ?>" title="直接上傳檔案給這個料號，上傳完會自動加成下方的項目列"><i class="fa fa-upload"></i> 上傳檔案</button>
        </div>
        <div class="tip" style="margin-top:8px;">選定產品編號(料號)後會自動列出「外來文件清單」中此料號的附件（拖曳列前的 <i class="fa fa-ellipsis-v"></i> 可調整順序，項次自動重編）；「所屬製程」欄能辨識來源（報價附件對應到有勾選製程的報價項目）時會自動帶入，共用文件（如原圖，或無法辨識製程來源）留空即代表適用全部製程，可手動修改或清空。這些自動列出的列用「納入」勾選框決定是否套用，取消勾選＝人工確認此文件不適用，不會被之後的同步再次加回。手動新增的列可按「選外來文件」自行連結，或直接手動輸入版別／文件編號。</div>
    </div>
    <div class="m-foot">
        <button class="b-cancel" onclick="closeMask('editMask')">取消</button>
        <button class="b-ok" id="btnSave" onclick="saveAll(false)">儲存</button>
    </div>
</div></div>

<!-- 從外來文件清單選取 -->
<div class="ic-mask" id="extMask" style="z-index:1200;"><div class="ic-modal">
    <div class="m-head"><span>從外來文件清單選取</span><span class="m-close" onclick="closeMask('extMask')">✕</span></div>
    <div class="m-body">
        <div id="extEmpty" style="color:#8a6d45;padding:10px;">載入中…</div>
        <table class="ic-item-table" id="extTable" style="display:none;">
            <thead><tr><th>檔名</th><th style="width:100px;">日期</th><th style="width:60px;">來源</th><th style="width:50px;"></th></tr></thead>
            <tbody id="extBody"></tbody>
        </table>
    </div>
    <div class="m-foot"><button class="b-cancel" onclick="closeMask('extMask')">取消</button></div>
</div></div>

<!-- 從訂單/報價製程挑選（此料號有多種不同製程紀錄時，供項目列「所屬製程」欄快速挑選） -->
<div class="ic-mask" id="procMask" style="z-index:1200;"><div class="ic-modal">
    <div class="m-head"><span>此料號的訂單/報價製程</span><span class="m-close" onclick="closeMask('procMask')">✕</span></div>
    <div class="m-body"><div id="procList"></div></div>
    <div class="m-foot"><button class="b-cancel" onclick="closeMask('procMask')">取消</button></div>
</div></div>

<!-- 掃描待建立料號（來源：外來文件清單附件／PFMEA 已建檔） -->
<div class="ic-mask" id="missingMask"><div class="ic-modal xwide">
    <div class="m-head"><span>掃描到的待建立料號</span><span class="m-close" onclick="closeMask('missingMask')">✕</span></div>
    <div class="m-body">
        <div id="missingEmpty" style="color:#8a6d45;padding:10px;">載入中…</div>
        <div id="missingCnt" style="font-size:12px;color:#8a6d45;margin-bottom:6px;"></div>
        <div id="missingTools" style="display:none;margin-bottom:6px;">
            <label style="display:inline-block !important;margin:0 10px 0 0 !important;">來源篩選
                <select id="missingSrcFilter" data-eg-skip="1" style="height:26px;font-size:12px;padding:0 4px;">
                    <option value="">全部</option>
                    <option value="ext">只看 外來文件</option>
                    <option value="pfmea">只看 PFMEA</option>
                    <option value="both">只看 兩者皆有</option>
                </select>
            </label>
            <button type="button" class="ic-row-btn" onclick="missingSelect('all')">全選</button>
            <button type="button" class="ic-row-btn" onclick="missingSelect('pfmea')">只選 PFMEA 來源</button>
            <button type="button" class="ic-row-btn" onclick="missingSelect('ext')">只選 外來文件來源</button>
            <button type="button" class="ic-row-btn" onclick="missingSelect('none')">全不選</button>
        </div>
        <table class="ic-item-table" id="missingTable" style="display:none;">
            <thead><tr>
                <th style="width:30px;"><input type="checkbox" id="ckMissAll" data-eg-skip="1" title="全選/取消全選(目前篩選出的)"></th>
                <th style="width:20%;">料號</th><th style="width:18%;">客戶</th>
                <th style="width:14%;">來源</th><th style="width:12%;">外來文件筆數</th><th style="width:12%;">PFMEA 筆數</th>
            </tr></thead>
            <tbody id="missingBody"></tbody>
        </table>
    </div>
    <div class="m-foot">
        <button class="b-cancel" onclick="closeMask('missingMask')">取消</button>
        <button class="b-ok" id="btnBuildAll" onclick="buildSelectedMissing()"><i class="fa fa-magic"></i> 建立勾選的</button>
    </div>
</div></div>

<!-- 上傳檔案（存成料號附件，上傳完自動加成項目列） -->
<div class="ic-mask" id="upMask" style="z-index:1200;"><div class="ic-modal">
    <div class="m-head"><span>上傳檔案</span><span class="m-close" onclick="closeMask('upMask')">✕</span></div>
    <div class="m-body">
        <div class="tip" style="background:#FFF7E8;border:1px dashed #F0A24B;border-radius:6px;padding:6px 10px;font-size:12px;color:#8a6d45;">
            檔案會存成這個料號的<b>料號附件</b>（跟主檔管理／外來文件清單是同一份檔案，不會變成兩套），
            上傳成功後自動加到下方項目列，記得再按<b>儲存</b>保存這張管制表。
        </div>
        <label>料號</label>
        <input type="text" id="upPartNo" readonly data-eg-skip="1">
        <label>選擇檔案 <span style="color:#DD5138;">*</span></label>
        <input type="file" id="upFile" style="display:block;width:100%;font-size:13px;padding:4px 0;">
        <div class="ic-err" id="upFileErr"></div>
        <label>附件類別標籤 <span style="color:#DD5138;">*</span>（至少勾一個；決定它以什麼名稱出現在項目列）</label>
        <div id="upCatList" class="ic-cat-list">載入中…</div>
        <div class="ic-err" id="upCatErr"></div>
        <div class="ic-head-grid" style="grid-template-columns:1fr 1fr 1fr;">
            <div><label>版次</label><input type="text" id="upRevision" placeholder="例：A（沒有可留空）"></div>
            <div><label>文件日期 <span style="color:#DD5138;">*</span></label><input type="date" id="upDocDate"><div class="ic-err" id="upDocErr"></div></div>
            <div><label id="upIssueLabel">發行章日期</label><input type="date" id="upIssueDate"><div class="ic-err" id="upIssueErr"></div></div>
        </div>
        <div id="upDateHint" style="font-size:12px;color:#8a6d45;margin-top:4px;"></div>
        <label>備註</label>
        <input type="text" id="upNote" placeholder="選填">
    </div>
    <div class="m-foot">
        <button class="b-cancel" onclick="closeMask('upMask')">取消</button>
        <button class="b-ok" id="btnUpDo"><i class="fa fa-upload"></i> 上傳</button>
    </div>
</div></div>

<!-- AS 文件綁定 -->
<div class="ic-mask" id="asDocMask"><div class="ic-modal">
    <div class="m-head"><span>AS 文件編號綁定</span><span class="m-close" onclick="closeMask('asDocMask')">✕</span></div>
    <div class="m-body">
        <div style="margin-bottom:8px;">目前綁定：<b id="asDocLabel">尚未綁定</b></div>
        <button type="button" class="ic-row-btn" onclick="openAsDocPicker()">變更綁定</button>
    </div>
    <div class="m-foot"><button class="b-cancel" onclick="closeMask('asDocMask')">關閉</button></div>
</div></div>

<!-- 廠內圖面標籤設定 -->
<div class="ic-mask" id="ownDrawMask"><div class="ic-modal">
    <div class="m-head"><span>廠內圖面標籤設定</span><span class="m-close" onclick="closeMask('ownDrawMask')">✕</span></div>
    <div class="m-body">
        <div class="tip" style="margin-bottom:8px;">下方只列出主檔管理已標記「自家出的圖」的附件類別。勾選的類別，其料號附件會比照外來文件清單一併同步進本模組（版別／文件編號優先顯示<b>版次</b>，型態生效日期優先用<b>發行章日期</b>；未填版次/發行章日期時退回檔名與上傳日）。<br>「顯示名稱」留空則沿用類別原名，同步進本模組後會直接成為項目列的「型態項目名稱」（與外來文件清單共用同一顯示名稱設定，若該類別同時列入外來文件清單，改名會兩邊一起變）。勾選「需要顯示製程」後，同步出的項目列若「所屬製程」留空會加上提示色塊，僅供提醒不強制填寫。<br><b>更新已同步項目名稱</b>：只改設定不會回頭改到之前已經同步進來的項目列，按這顆按鈕會先儲存目前設定、再用最新的顯示名稱／需要顯示製程覆蓋回所有目前仍連結有效附件的項目列（手動輸入的項目不受影響），不必整批刪除重轉。</div>
        <div id="ownDrawEmpty" style="color:#8a6d45;padding:10px;">載入中…</div>
        <div id="ownDrawList"></div>
    </div>
    <div class="m-foot">
        <button class="b-cancel" onclick="closeMask('ownDrawMask')">取消</button>
        <button class="b-cancel" onclick="refreshSyncedItemNames()" title="先儲存目前設定，再用最新顯示名稱／需要顯示製程覆蓋回既有已同步項目"><i class="fa fa-refresh"></i> 更新已同步項目名稱</button>
        <button class="b-ok" onclick="saveOwnDrawCats()">儲存</button>
    </div>
</div></div>

<!-- 角色權限說明 -->
<div class="ic-mask" id="roleHelpMask"><div class="ic-modal">
    <div class="m-head"><span>角色權限說明</span><span class="m-close" onclick="closeMask('roleHelpMask')">✕</span></div>
    <div class="m-body" style="font-size:13px;color:#5b3a1e;line-height:1.8;">
        <b>型態文件檢閱</b>：檢視清單、開啟查看、列印。<br>
        <b>型態文件登錄</b>：檢閱＋新增/編輯、「掃描待建立料號」與自動產生/同步、確認清單（含排除項目）。<br>
        <b>型態文件管理員</b>：登錄＋刪除、AS 文件編號綁定、批次確認清單。<br>
        <b>管理者</b>：系統管理者固定擁有全部權限。<br>
        <hr style="border-color:#EADFC8;">
        角色指派請洽管理者於「使用者權限設定」（<a href="../user/user_permissions.php" target="_blank">開啟</a>）→「型態識別文件管制表」區塊指派。未被指派角色者無法進入本頁。
    </div>
</div></div>

<div class="ic-mask" id="helpUseMask"><div class="ic-modal xwide">
    <div class="m-head"><span><i class="fa fa-question-circle"></i> 型態識別文件管制表 使用說明</span><span class="m-close" onclick="closeMask('helpUseMask')">✕</span></div>
    <div class="m-body help-doc">
        <h4>功能說明</h4>
        <p><b>每個料號建立一份</b>「型態識別文件管制表」，逐列記錄目前定義該料號狀態的文件（原圖、報價單、加工圖、產品開發評估表、PFMEA、檢驗報告…），用來追溯「這個料號現在的配置由哪些文件定義」。項目列以「外來文件清單」為主要來源，可自動產生／同步，也可以手動增加。</p>
        <h4>所屬製程（同一張圖若多個製程共用，不會重複列出）</h4>
        <ul>
            <li>清單的 <b>內容項次</b> 欄顯示該筆管制表裡<b>有資料</b>的項目列筆數——只計算「未排除，且已連結到來源文件（料號／報價附件）或手動填了文件編號、型態生效日期」的列；只有項目名稱、還沒帶到任何文件的空白列<b>不計入</b>（滑鼠停留可看見項目列總數）。數字是每次查詢當下即時算出、不是快照，匯出 CSV 也含這一欄。</li>
            <li>清單的 <b>PFMEA</b> 欄顯示這個料號是否已在「潛在失效模式及效應分析」建檔（<b>PFMEA已建立</b>／未建立，點擊可開啟 PFMEA 頁面），是每次查詢當下即時查 pfmea_doc 的結果、不是快照；工具列的「PFMEA」下拉可篩選出已建立／未建立的清單，匯出 CSV 也含這一欄。</li>
            <li>每一列項目可標記「所屬製程」：來源是報價附件、且能對應到<b>有勾選製程</b>的報價項目時，系統會自動帶入該製程（可手動修改或清空）；無法辨識來源製程的文件（例如料號附件、或不特定的共用圖面如原圖）留空，代表<b>適用全部製程</b>，不會特別標記。</li>
            <li>找不到需要的製程文字時，按欄位旁的 <i class="fa fa-list"></i> 從此料號的訂單/報價紀錄挑選。</li>
        </ul>
        <h4>兩種建立方式</h4>
        <ul>
            <li><b>自動產生／同步（推薦）</b>：工具列輸入料號→按「同步」，系統把外來文件清單中此料號的所有附件同步進項目列，每列依來源自動帶入所屬製程。之後每次執行都是「同步」：新出現的外來文件會被加入、已確認過的清單會被改成「需重新確認」提醒覆核。</li>
            <li><b>掃描待建立料號（批次）</b>：列出所有還沒建立管制表的料號，來源有兩種並在「來源」欄各自標示——<b>外來文件</b>＝外來文件清單／廠內圖面標籤裡有附件的料號；<b>PFMEA</b>＝已在「潛在失效模式及效應分析」建檔的料號（<b>就算一份附件都沒有也會列出來</b>，這是刻意的：那正是需要補建管制表再上傳資料的情況）。清單可逐筆勾選，並有「只選 PFMEA 來源」「只選 外來文件來源」可以獨立整批選取，另有「來源篩選」只影響顯示、不影響已勾選的項目。PFMEA 來的料號若沒有附件，會先建成<b>空白清單</b>，開啟後用「上傳檔案」把資料補上去。</li>
            <li><b>上傳檔案（直接補資料）</b>：編輯跳窗項目列下方的「上傳檔案」按鈕（需登錄權限，且要先選定料號）。檔案存成該料號的<b>料號附件</b>——跟主檔管理／外來文件清單是同一份檔案，不會變成兩套，同一張圖不用傳兩次；上傳成功後自動加成項目列，記得再按「儲存」保存這張管制表。<b>附件類別標籤至少要勾一個</b>（沒勾不准存檔，前端擋、後端也擋），候選只列出會被本模組同步進來的類別，避免傳了卻不會出現在清單上。<b>文件日期</b>必填（預設今天）：料號附件沒有獨立的文件日期欄位，本表的「型態生效日期」在沒有發行章日期時是取附件建立日期，所以存檔時會把附件建立日期一併改成你填的文件日期（只改日期、保留時分秒），補傳舊文件才不會全部變成上傳當天。若勾到「自家出的圖」類別，<b>發行章日期必填</b>且型態生效日期改以發行章日期為準（判準見圖面變更規定）；系統若判定這次上傳屬於「圖面變更」，會提示到「圖面變更紀錄」頁補登變更內容與簽收名單。</li>
            <li><b>新增（手動）</b>：按「新增」→ 選擇「產品編號(料號)」（打部分字元直接搜尋，不需先點按鈕；選定後自動帶出客戶、外來文件清單中此料號的資料也會自動列出），可再用「新增一列」手動加項目，或用列上的「選外來文件」挑選既有附件連結。</li>
        </ul>
        <h4>人工確認（審查是否有文件不適用）</h4>
        <ul>
            <li>自動列出的項目預設「納入」（打勾）；若某份文件其實不該出現在此清單，把「納入」勾選框取消即可——會記為<b>已排除</b>，之後同步不會再自動加回來。</li>
            <li>逐項確認後按「確認清單」：記錄確認人與確認時間，狀態變成「已確認」；製表人／簽章日期即取這次確認人與清單上最新的文件日期。</li>
            <li>之後只要有新的外來文件同步進來，「已確認」會自動變回「需重新確認」，提醒重新逐項審視。清單上方「狀態」篩選可分別看「待確認／需重新確認／已確認」。</li>
            <li><b>批次確認清單</b>（僅型態文件管理員／管理員）：清單左側勾選要確認的多筆文件（表頭全選框可一次勾全部），按工具列「批次確認清單」；確認者一律自動記為目前登入者，不提供指定他人。簽章日期不受影響——一律是各文件自己項目列上最新的文件日期，跟誰按確認、何時按確認無關。</li>
        </ul>
        <h4>篩選與列印全部</h4>
        <ul>
            <li>清單上方除「搜尋」（文件編號／料號／客戶模糊比對其一）外，另有獨立的「客戶」（可輸入客戶ID或客戶名稱模糊搜尋）與「料號」篩選欄，皆為即時搜尋（輸入後自動觸發，不需按 Enter）。篩選欄有值時<b>雙擊可清空並同時解除該欄篩選</b>（全站共用行為，見 eg_input_rules.js）。</li>
            <li><b>列印全部搜尋結果</b>：依目前「搜尋／客戶／料號／狀態」篩選條件，把畫面上列出的所有結果逐筆各自開視窗列印（不會合併成一份文件，所以每份文件仍是各自獨立算頁次、只有一頁時不顯示頁碼，跟單筆列印完全一樣）。結果筆數較多（超過 15 筆）時會先跳出確認，選擇繼續即自動依序逐筆觸發列印，不需要每筆手動點；若瀏覽器跳出「已封鎖快顯視窗」提示，請允許本頁彈出視窗才能讓後續筆數繼續列印。</li>
            <li>清單分頁顯示於表格右上角，預設每頁 10 筆（可改 5／20／50）；批次確認的「全選」、CSV 匯出、列印全部搜尋結果皆以目前篩選條件下的<b>全部</b>結果為準，不受目前停留在第幾頁影響。往下捲動導致看不到欄位標題列時，右下角會出現浮動的「回頂端」按鈕。</li>
        </ul>
        <h4>其他行為／常見疑問</h4>
        <ul>
            <li>「版別／文件編號」欄：連結自外來文件清單的列無法手動改文字——顯示內容一律即時查詢外來文件清單目前狀態（不落地快照，來源異動這裡會跟著變），若來源附件被刪除會顯示「來源已消失」；手動新增的列可直接輸入文字，也可按「選外來文件」改連結既有附件。欄位旁 <i class="fa fa-eye"></i> 圖示可直接點開附件內容以利確認（本機瀏覽器可預覽的檔案如PDF/圖片會直接開啟預覽）；沒有真正版次、退回顯示檔名充當辨識用途時，畫面仍會顯示檔名，但<b>列印不會印出檔名</b>（檔名不是真正的文件編號）。</li>
            <li>項目列可拖曳排序（列前 <i class="fa fa-ellipsis-v"></i> 圖示），放開後項次自動重新編號。</li>
            <li>建立日期＝清單上最早的文件日期；簽章日期＝清單上最新的文件日期（皆排除已排除的項目）；兩者隨清單內容即時算出，不需手動填。</li>
            <li>編輯跳窗與列印版都會顯示「製程」——直接取此料號所有相關<b>報價單／訂單</b>紀錄檢附的製程全部合併顯示（跟項目列旁挑選製程時查的是同一批來源），不是項目列「所屬製程」的彙總（那是給人工審閱單一項目用的細節欄，同一報價單裡此料號只有一個項目時會留空，不代表這個料號沒有製程）；查無任何相關紀錄時顯示「—」。此欄唯讀，不能手動填寫。</li>
            <li>列印比照全站標準（ai-rules/16）：大標題為本公司名稱、頁尾右下角印本頁綁定的 AS 文件編號、製表人簽章使用全站通用圓形姓名章（若本人有上傳掃描實體章會優先用掃描章，否則自動產生標準回墨章，不需另外設定模板）。</li>
        </ul>
        <h4>設定入口</h4>
        <p>AS 文件編號綁定：工具列「AS文件綁定」按鈕（僅管理員可見）。外來文件標籤設定：<a href="../Sales/external_doc_list.php" target="_blank">外來文件清單</a>頁的類別設定。<b>廠內圖面標籤</b>（哪些「自家出的圖」類別也要納入自動同步）：工具列「廠內圖面標籤設定」按鈕（僅管理員可見；類別本身要先在主檔管理→附件類別標籤設定勾選「自家出的圖」）。同一跳窗每個類別還可設定：<b>顯示名稱</b>（留空沿用類別原名；同步進本模組後即成為項目列的「型態項目名稱」，與外來文件清單共用同一顯示名稱欄位）與<b>需要顯示製程</b>（勾選後，該類別同步出的項目列若「所屬製程」留空，欄位會加提示色塊，僅供提醒不強制填寫）；改了設定不會回頭改到之前已同步的舊資料，跳窗內「更新已同步項目名稱」按鈕會把最新設定覆蓋回所有目前仍連結有效附件的既有項目列（不必整批刪除重轉；手動輸入的項目不受影響；已確認的清單若被更新會改回「需重新確認」）。<b>角色指派</b>（誰可以檢閱／登錄／管理本頁）：<a href="../user/user_permissions.php" target="_blank">使用者權限設定</a>頁→「型態識別文件管制表」區塊。</p>
        <h4>權限角色</h4>
        <p>型態文件檢閱／登錄／管理員（管理者固定擁有全部權限）；點頁面右上角「目前角色」旁的 <i class="fa fa-question-circle"></i> 可看各角色的權限說明。</p>
    </div>
    <div class="m-foot"><button class="b-ok" onclick="closeMask('helpUseMask')">我知道了</button></div>
</div></div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/fastclick.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script src="../../resource/js/eg_date_fmt.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_date_fmt.js') ?>"></script>
<script src="../../resource/js/eg_stamp.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_stamp.js') ?>"></script>
<script src="../../resource/js/eg_part_picker.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_part_picker.js') ?>"></script>
<script src="../../resource/js/eg_asdoc_picker.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_asdoc_picker.js') ?>"></script>
<script src="../../resource/js/eg_input_rules.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_input_rules.js') ?>"></script>
<script>
$(document).ready(function(){ $('#sidebar-menu').css('visibility','visible'); });
var API = '../../src/store/ConfigIdDoc_API.php';
var PART_API = '../../src/store/PartPicker_API.php';
var VIEWER_URL = '../pm/bom_viewer.php'; // 三分頁合併(圖面/報價/其他)唯讀檢視，比照報價單頁的作法（部分料號在 part_viewer.php 查無圖檔）
var CAN_EDIT = <?= $perms['canEdit'] ? 'true' : 'false' ?>;
var CAN_ADMIN = <?= $perms['canAdmin'] ? 'true' : 'false' ?>;
var TYPE_OPTS = [['drawing','圖面'],['jig','治夾具'],['report','報告'],['other','其他文件']];
var CUR_ID = 0, ITEMS = [], AS_DOCS = [], AS_DOC = null;

function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];}); }
function closeMask(id){ document.getElementById(id).style.display='none'; }
function openMask(id){ document.getElementById(id).style.display='block'; }
function fmtDate(s){ return (window.egFmtDate ? egFmtDate(s) : (s||'')); }
var STATUS_CLS = {pending:'st-pending', needs_recheck:'st-recheck', confirmed:'st-confirmed'};
function statusBadge(status, label){ return '<span class="ic-status '+(STATUS_CLS[status]||'st-pending')+'">'+esc(label||status)+'</span>'; }
/* 此料號是否已在 PFMEA(潛在失效模式及效應分析)建檔 —— 顯示與篩選都由後端即時查 pfmea_doc，不落快照 */
function pfmeaBadge(r){
    if (!r.has_pfmea) return '<span style="color:#b09a78;font-size:12px;">未建立</span>';
    var n = r.pfmea_count > 1 ? (' '+r.pfmea_count+' 份') : '';
    return '<a href="pfmea.php" target="_blank" class="ic-src s-pfmea" style="text-decoration:none;" title="已在 PFMEA 建檔，點擊開啟 PFMEA 頁面">PFMEA已建立'+n+'</a>';
}

/* 內容項次筆數：只計「有資料」的項目列（未排除，且已連結來源文件或手動填了文件編號/生效日期）；
   只有名稱、還沒帶到任何文件的空列不計入，滑鼠停留可看見項目列總數 */
function itemCountCell(r){
    var n = r.item_filled_count || 0, t = r.item_total_count || 0;
    var tip = '有資料 '+n+' 項'+(t>n ? ('，項目列共 '+t+' 項（其餘尚未帶到文件或已排除）') : '');
    if (!n) return '<span style="color:#b09a78;font-size:12px;" title="'+esc(tip)+'">0</span>';
    return '<b style="color:#8a5a1e;" title="'+esc(tip)+'">'+n+'</b>';
}

/* ---------- 清單（前端分頁：'list' 動作本身仍一次撈回全部符合篩選條件的資料，批次確認全選／
   匯出CSV／列印全部搜尋結果都要看全部結果，不能只看目前這一頁；分頁只影響畫面顯示哪一段） ---------- */
var CUR_LIST_ROWS = [];
var CUR_PAGE = 1;
var PAGE_SIZE = 10;
function curFilterParams(){
    return {
        kw: $('#kwInput').val()||'', status: $('#statusFilter').val()||'',
        customer: $('#filterCustomer').val()||'', part_no: $('#filterPartNo').val()||'',
        pfmea: $('#pfmeaFilter').val()||''
    };
}
function loadList(){
    $.getJSON(API, $.extend({action:'list'}, curFilterParams()), function(res){
        if (!res.success){ CUR_LIST_ROWS=[]; CUR_PAGE=1; $('#icBody').html('<tr><td colspan="10" style="padding:20px;color:#DD5138;">'+esc(res.message||'載入失敗')+'</td></tr>'); renderPager(); return; }
        CUR_LIST_ROWS = res.rows || [];
        CUR_PAGE = 1;
        renderTablePage();
    });
}
function rowHtml(r){
    return '<tr>'
        + '<td style="'+(CAN_ADMIN?'':'display:none;')+'"><input type="checkbox" class="ck-row" data-id="'+r.id+'" data-eg-skip="1"></td>'
        + '<td>'+esc(r.doc_no)+'</td>'
        + '<td>'+esc(r.customer_name||r.customer_id||'')+'</td>'
        + '<td class="t-left">'+(r.part_no?EGPartPicker.viewerLink(r.part_no, VIEWER_URL):esc(r.part_no))+'</td>'
        + '<td>'+itemCountCell(r)+'</td>'
        + '<td>'+pfmeaBadge(r)+'</td>'
        + '<td>'+statusBadge(r.review_status, r.review_status_label)+'</td>'
        + '<td>'+esc(r.created_by_name||'')+'</td>'
        + '<td>'+fmtDate((r.created_at||'').substring(0,10))+'</td>'
        + '<td>'
        + '<span class="ic-op" title="'+(CAN_EDIT?'編輯':'檢視')+'" onclick="openEdit('+r.id+')"><i class="fa fa-'+(CAN_EDIT?'pencil':'eye')+'"></i></span>'
        + '<span class="ic-op" title="列印" onclick="printDoc('+r.id+')"><i class="fa fa-print"></i></span>'
        + (CAN_ADMIN ? '<span class="ic-op" title="刪除" onclick="delDoc('+r.id+')"><i class="fa fa-trash"></i></span>' : '')
        + '</td></tr>';
}
function renderTablePage(){
    $('#ckAll').prop('checked', false);
    if (!CUR_LIST_ROWS.length){ $('#icBody').html('<tr><td colspan="10" style="padding:20px;color:#8a6d45;">尚無資料</td></tr>'); renderPager(); return; }
    var totalPages = Math.max(1, Math.ceil(CUR_LIST_ROWS.length / PAGE_SIZE));
    if (CUR_PAGE > totalPages) CUR_PAGE = totalPages;
    var start = (CUR_PAGE-1) * PAGE_SIZE;
    var pageRows = CUR_LIST_ROWS.slice(start, start + PAGE_SIZE);
    $('#icBody').html(pageRows.map(rowHtml).join(''));
    renderPager();
}
function renderPager(){
    var total = CUR_LIST_ROWS.length;
    var totalPages = Math.max(1, Math.ceil(total / PAGE_SIZE));
    $('#pagerInfo').text('共 '+total+' 筆');
    $('#pagerLabel').text('第 '+CUR_PAGE+'／'+totalPages+' 頁');
    $('#pagePrev').prop('disabled', CUR_PAGE <= 1);
    $('#pageNext').prop('disabled', CUR_PAGE >= totalPages);
}
$('#pageSize').on('change', function(){ PAGE_SIZE = parseInt(this.value,10)||10; CUR_PAGE = 1; renderTablePage(); });
$('#pagePrev').on('click', function(){ if (CUR_PAGE > 1){ CUR_PAGE--; renderTablePage(); } });
$('#pageNext').on('click', function(){ var totalPages = Math.max(1, Math.ceil(CUR_LIST_ROWS.length / PAGE_SIZE)); if (CUR_PAGE < totalPages){ CUR_PAGE++; renderTablePage(); } });

var kwT=null;
$('#kwInput').on('input', function(){ clearTimeout(kwT); kwT=setTimeout(loadList, 300); });
var custT=null;
$('#filterCustomer').on('input', function(){ clearTimeout(custT); custT=setTimeout(loadList, 300); });
var partT=null;
$('#filterPartNo').on('input', function(){ clearTimeout(partT); partT=setTimeout(loadList, 300); });
$('#statusFilter').on('change', loadList);
$('#pfmeaFilter').on('change', loadList);
$('#ckAll').on('change', function(){ $('#icBody .ck-row').prop('checked', this.checked); });

/* ---------- 批次確認清單（型態文件管理員／管理員；確認者自動記為目前登入者。簽章日期不受影響——
   一律取項目列最新文件日期即時算出，跟誰按確認、何時按確認無關） ---------- */
$('#btnBatchConfirm').on('click', function(){
    var ids = $('#icBody .ck-row:checked').map(function(){ return parseInt($(this).data('id'),10); }).get();
    if (!ids.length){ alert('請先在清單左側勾選要確認的項目'); return; }
    if (!confirm('確定要批次確認勾選的 '+ids.length+' 筆型態識別文件管制表嗎？確認者將記為目前登入者。')) return;
    $.post(API, {action:'batch_confirm', ids: JSON.stringify(ids)}, function(res){
        if (!res.success){ alert(res.message||'批次確認失敗'); return; }
        alert('已確認 '+res.confirmed_count+' 筆。');
        loadList();
    }, 'json');
});

$('#btnCsv').on('click', function(){
    $.getJSON(API, $.extend({action:'list'}, curFilterParams()), function(res){
        if (!res.success) return;
        var lines = ['文件編號,客戶,產品編號,內容項次,PFMEA,確認狀態,建立人,建立時間'];
        res.rows.forEach(function(r){
            lines.push([r.doc_no, r.customer_name||r.customer_id||'', r.part_no||'', (r.item_filled_count||0), (r.has_pfmea?'PFMEA已建立':'未建立'), r.review_status_label||'', r.created_by_name||'', (r.created_at||'').substring(0,10)]
                .map(function(v){ return '"'+String(v).replace(/"/g,'""')+'"'; }).join(','));
        });
        var blob = new Blob(["\uFEFF"+lines.join("\n")], {type:'text/csv;charset=utf-8;'});
        var a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = '型態識別文件管制表.csv';
        document.body.appendChild(a); a.click(); document.body.removeChild(a);
    });
});

/* ---------- 依料號自動產生/同步 ---------- */
var syncPartDId = 0;
EGPartPicker.attach(document.getElementById('syncPartNo'), {
    apiUrl: PART_API,
    onSelect: function(row){ syncPartDId = row.d_id; }
});
$('#syncPartNo').on('input', function(){ syncPartDId = 0; });
$('#btnSyncPart').on('click', function(){
    if (!syncPartDId){ alert('請先從清單中選擇一個料號'); return; }
    $.post(API, {action:'sync_part', part_d_id:syncPartDId}, function(res){
        if (!res.success){ alert(res.message||'同步失敗'); return; }
        alert((res.is_new ? '已建立新的型態識別文件管制表' : '已同步既有型態識別文件管制表')+'，新增 '+res.added_count+' 筆項目');
        $('#syncPartNo').val(''); syncPartDId = 0;
        loadList();
    }, 'json');
});

/* ---------- 掃描待建立料號 → 逐筆勾選建立 ----------
   來源有兩種並各自標示：ext=外來文件清單有附件、pfmea=PFMEA 已建檔（可能一份附件都沒有，
   建出來是空白清單，接著用編輯跳窗的「上傳檔案」把資料補上去）。
   列一律全部畫出來，「來源篩選」只是隱藏不重畫，這樣勾選狀態不會因為切篩選被洗掉，
   「只選 PFMEA 來源」也才能不受目前篩選影響獨立生效。 */
var missingRows = [];
$('#btnScanMissing').on('click', function(){
    $('#missingEmpty').show().text('掃描中…'); $('#missingTable').hide(); $('#missingTools').hide(); $('#missingCnt').text('');
    openMask('missingMask');
    $.getJSON(API, {action:'find_missing_parts'}, function(res){
        if (!res.success){ $('#missingEmpty').text(res.message||'掃描失敗'); return; }
        missingRows = res.rows || [];
        if (!missingRows.length){ $('#missingEmpty').text('沒有找到待建立的料號——外來文件清單與 PFMEA 裡的料號都已建立型態識別文件管制表。'); return; }
        var nPf = missingRows.filter(function(r){ return r.pfmea_count > 0; }).length;
        $('#missingEmpty').hide(); $('#missingTable').show(); $('#missingTools').show();
        $('#missingCnt').html('共找到 '+missingRows.length+' 個尚未建立型態識別文件管制表的料號（其中 <b>'+nPf+'</b> 個已在 PFMEA 建檔）。勾選要建立的料號後按右下角建立；PFMEA 來的料號若沒有任何外來文件附件，會先建成空白清單，再用編輯跳窗的「上傳檔案」補資料。');
        $('#missingSrcFilter').val('');
        $('#missingBody').html(missingRows.map(missingRowHtml).join(''));
        missingSelect('all');
    }, 'json');
});
function missingRowHtml(r){
    var cls = r.sources === 'both' ? 's-both' : (r.sources === 'pfmea' ? 's-pfmea' : 's-ext');
    return '<tr data-src="'+esc(r.sources)+'">'
        + '<td><input type="checkbox" class="ck-miss" data-id="'+r.d_id+'" data-eg-skip="1"></td>'
        + '<td class="t-left">'+esc(r.part_no)+'</td>'
        + '<td class="t-left">'+esc(r.customer_name||'')+'</td>'
        + '<td><span class="ic-src '+cls+'">'+esc(r.source_label)+'</span></td>'
        + '<td>'+esc(r.ext_count)+'</td>'
        + '<td>'+esc(r.pfmea_count)+'</td></tr>';
}
window.missingSelect = function(mode){
    $('#missingBody tr').each(function(){
        var src = $(this).attr('data-src'), on;
        if (mode === 'all')        on = true;
        else if (mode === 'none')  on = false;
        else if (mode === 'pfmea') on = (src === 'pfmea' || src === 'both');   // PFMEA 有的都算
        else if (mode === 'ext')   on = (src === 'ext');                        // 純外來文件，不含 PFMEA
        $(this).find('.ck-miss').prop('checked', !!on);
    });
    missingSyncFoot();
};
function missingApplyFilter(){
    var f = $('#missingSrcFilter').val() || '';
    $('#missingBody tr').each(function(){ $(this).toggle(!f || $(this).attr('data-src') === f); });
}
function missingSyncFoot(){
    var n = $('#missingBody .ck-miss:checked').length;
    $('#btnBuildAll').prop('disabled', n === 0).html('<i class="fa fa-magic"></i> 建立勾選的 ('+n+')');
    $('#ckMissAll').prop('checked', n > 0 && n === $('#missingBody .ck-miss').length);
}
$('#missingSrcFilter').on('change', missingApplyFilter);
$('#ckMissAll').on('change', function(){ $('#missingBody tr:visible .ck-miss').prop('checked', this.checked); missingSyncFoot(); });
$('#missingBody').on('change', '.ck-miss', missingSyncFoot);
function buildSelectedMissing(){
    var ids = $('#missingBody .ck-miss:checked').map(function(){ return parseInt($(this).data('id'),10); }).get();
    if (!ids.length){ alert('請先勾選要建立的料號'); return; }
    if (!confirm('確定要建立勾選的 '+ids.length+' 個料號的型態識別文件管制表嗎？')) return;
    $('#btnBuildAll').prop('disabled', true).text('建立中…');
    $.post(API, {action:'sync_all_missing', part_ids: JSON.stringify(ids)}, function(res){
        missingSyncFoot();
        if (!res.success){ alert(res.message||'建立失敗'); return; }
        alert('已處理 '+res.part_count+' 個料號，共新增 '+res.item_count+' 筆項目。\n沒有附件的料號會是空白清單，請開啟後用「上傳檔案」補資料。');
        closeMask('missingMask');
        loadList();
    }, 'json');
}

/* ---------- 上傳檔案（存成料號附件，上傳完直接加成項目列）----------
   刻意沿用既有的料號附件 API，不另開一套本模組專屬附件：這樣同一份檔案在主檔管理／外來文件
   清單／本頁看到的都是同一筆，不會出現「同一張圖要傳兩次」。附件類別標籤沒勾一律不准存
   （鐵律8 附件標籤鐵則，前端即時擋＋Part_Attachment_API 後端同步再擋一次）。
   file input 一律用可見的原生元件、送出時直接讀 input.files（不依賴 change 事件，
   使用者環境的 change 事件會被靜默吞掉，見記憶 file_upload_change_event）。 */
var PART_ATTACH_API = '../../src/store/Part_Attachment_API.php';
var UP_CATS = [], UP_DWG_URL = '../QC/drawing_change_log.php';
function todayStr(){ var d = new Date(); return d.getFullYear()+'-'+('0'+(d.getMonth()+1)).slice(-2)+'-'+('0'+d.getDate()).slice(-2); }
window.openUpload = function(){
    var dId = parseInt($('#fPartDId').val(),10) || 0;
    if (!dId){ alert('請先選擇產品編號(料號)——檔案要掛在料號底下才知道要放進哪一份清單。'); return; }
    $('#upPartNo').val($('#fPartNo').val()||'');
    $('#upFile').val(''); $('#upRevision').val(''); $('#upNote').val('');
    $('#upDocDate').val(todayStr()); $('#upIssueDate').val('');
    $('.ic-err').removeClass('on').text(''); $('#upFile,#upDocDate,#upIssueDate').removeClass('ic-bad');
    $('#btnUpDo').prop('disabled', false).html('<i class="fa fa-upload"></i> 上傳');
    openMask('upMask');
    if (UP_CATS.length){ renderUpCats(); return; }
    $('#upCatList').text('載入中…');
    $.getJSON(API, {action:'upload_categories'}, function(res){
        if (!res.success){ $('#upCatList').text(res.message||'載入失敗'); return; }
        UP_CATS = res.rows || [];
        if (res.dwg_change_url) UP_DWG_URL = res.dwg_change_url;
        renderUpCats();
    });
};
function renderUpCats(){
    if (!UP_CATS.length){ $('#upCatList').text('目前沒有任何可用的附件類別（需先在外來文件清單或本頁「廠內圖面標籤設定」勾選）。'); return; }
    $('#upCatList').html(UP_CATS.map(function(c){
        // 顯示名稱可能重複(例：BOSS圖與單製++圖都顯示成「加工圖」)，重複時補上類別原名才分得出來
        var dup = UP_CATS.filter(function(x){ return x.name === c.name; }).length > 1;
        var label = esc(c.name) + (dup && c.raw_name !== c.name ? '<span style="color:#8a6d45;font-size:11px;">（'+esc(c.raw_name)+'）</span>' : '');
        return '<label><input type="checkbox" class="up-cat" value="'+c.id+'" data-need-issue="'+c.need_issue_date+'" data-eg-skip="1">'+label+(c.need_issue_date?'<span style="color:#b5762a;font-size:11px;">（自家出的圖）</span>':'')+'</label>';
    }).join(''));
    upRefreshDateRule();
}
function upSelectedCats(){ return $('#upCatList .up-cat:checked').map(function(){ return parseInt(this.value,10); }).get(); }
function upNeedIssue(){ return $('#upCatList .up-cat:checked').filter(function(){ return $(this).attr('data-need-issue') == 1; }).length > 0; }
function upRefreshDateRule(){
    var need = upNeedIssue();
    $('#upIssueLabel').html('發行章日期'+(need?' <span style="color:#DD5138;">*</span>':''));
    if (need && !$('#upIssueDate').val()) $('#upIssueDate').val(todayStr());
    $('#upDateHint').html(need
        ? '此類別屬「自家出的圖」，<b>型態生效日期會用發行章日期</b>（請填圖上實際的蓋章日）；文件日期仍會寫進附件的建立日期。'
        : '此類別沒有發行章日期，<b>型態生效日期會用文件日期</b>——存檔時附件的建立日期會一併改成這一天，不是用實際上傳的今天。');
}
$('#upCatList').on('change', '.up-cat', function(){ upRefreshDateRule(); upValidate(); });
$('#upFile').on('change', function(){ upValidate(); });
$('#upDocDate,#upIssueDate').on('change input', function(){ upValidate(); });
function upErr(id, inputSel, msg){
    var $e = $(id);
    if (msg){ $e.addClass('on').text(msg); if (inputSel) $(inputSel).addClass('ic-bad'); }
    else { $e.removeClass('on').text(''); if (inputSel) $(inputSel).removeClass('ic-bad'); }
    return !msg;
}
function upValidate(){
    var f = document.getElementById('upFile').files;   // 直讀 files，不依賴 change 事件
    var ok = true;
    ok = upErr('#upFileErr', '#upFile', (f && f.length) ? '' : '請選擇要上傳的檔案') && ok;
    ok = upErr('#upCatErr', null, upSelectedCats().length ? '' : '請至少勾選一個附件類別標籤（沒勾就不會出現在項目列，也不准存檔）') && ok;
    ok = upErr('#upDocErr', '#upDocDate', /^\d{4}-\d{2}-\d{2}$/.test($('#upDocDate').val()||'') ? '' : '請填文件日期（決定型態生效日期與附件建立日期）') && ok;
    ok = upErr('#upIssueErr', '#upIssueDate', (upNeedIssue() && !$('#upIssueDate').val()) ? '此標籤屬「自家出的圖」，發行章日期必填（請填圖上實際的蓋章日）' : '') && ok;
    return ok;
}
$('#btnUpDo').on('click', function(){
    if (!upValidate()) return;
    var dId = parseInt($('#fPartDId').val(),10) || 0;
    if (!dId){ alert('請先選擇產品編號(料號)'); return; }
    var files = document.getElementById('upFile').files;
    var fd = new FormData();
    fd.append('action', 'upload');
    fd.append('d_id', dId);
    fd.append('category_ids', upSelectedCats().join(','));
    fd.append('revision', $('#upRevision').val()||'');
    fd.append('issue_stamp_date', $('#upIssueDate').val()||'');
    fd.append('note', $('#upNote').val()||'');
    fd.append('file', files[0]);
    $('#btnUpDo').prop('disabled', true).text('上傳中…');
    $.ajax({url: PART_ATTACH_API, type:'POST', data: fd, processData:false, contentType:false, dataType:'json'})
    .done(function(res){
        if (!res || !res.success){
            $('#btnUpDo').prop('disabled', false).html('<i class="fa fa-upload"></i> 上傳');
            upErr('#upFileErr', '#upFile', (res && res.message) || '上傳失敗');
            return;
        }
        // 文件日期：料號附件沒有獨立的日期欄位，本模組的型態生效日期沒有發行章日期時會退回附件
        // 建立日期，所以這裡把建立日期改成使用者填的文件日期（只改日期、保留時分秒）
        $.post(API, {action:'set_attach_doc_date', attach_id: res.id, doc_date: $('#upDocDate').val()}, function(){
            $('#btnUpDo').prop('disabled', false).html('<i class="fa fa-upload"></i> 上傳');
            closeMask('upMask');
            upAppendNewRows(dId, res.dwg_verdict);
        }, 'json');
    })
    .fail(function(){
        $('#btnUpDo').prop('disabled', false).html('<i class="fa fa-upload"></i> 上傳');
        upErr('#upFileErr', '#upFile', '上傳失敗（連線或檔案過大）');
    });
});
/* 上傳後把新出現的附件直接加成項目列（不動已填未存的其他列，也不會提前替新增中的清單建表頭） */
function upAppendNewRows(dId, verdict){
    $.post(API, {action:'fetch_ext_for_part', part_d_id:dId}, function(res){
        var msg = '檔案已上傳。';
        if (res && res.success){
            var have = {};
            $('#itemBody tr').each(function(){
                var src = $(this).attr('data-ref-source')||'', aid = $(this).attr('data-ref-attach-id')||'';
                if (src) have[src+'|'+aid] = 1;
            });
            // 完全空白的手動列先移除，免得上傳完留下一列空的跟著被存進去
            $('#itemBody tr').each(function(){
                var $t = $(this);
                if (($t.attr('data-ref-source')||'') === '' && !$t.find('.f-name').val() && !$t.find('.f-docno').val() && !$t.find('.f-date').val()) $t.remove();
            });
            var added = 0;
            (res.rows||[]).forEach(function(r){
                if (have[r.ref_source+'|'+r.ref_attach_id]) return;
                $('#itemBody').append(itemRowHtml(r, $('#itemBody tr').length));
                added++;
            });
            renumberRows();
            msg += added ? ('已加入 '+added+' 筆項目列，請按「儲存」保存這張管制表。')
                         : '（此檔案的類別標籤不在本模組同步範圍，項目列沒有變動。）';
        }
        if (verdict && verdict.kind === 'change'){
            msg += '\n\n注意：系統依發行章日期判定這是一次「圖面變更」，變更內容與簽收名單請到「圖面變更紀錄」頁補登（' + UP_DWG_URL + '）。';
        }
        alert(msg);
    }, 'json');
}

/* ---------- 新增/編輯 ---------- */
function resetEditForm(){
    CUR_ID = 0; ITEMS = [];
    $('#fPartNo').val(''); $('#fPartDId').val('0'); $('#fCustomerName').val(''); $('#fCustomerId').val('');
    $('#fDocNo').text('存檔後自動產生'); $('#fCreatedInfo').text('—');
    $('#fProcessSummary').val('—');
    $('#fEarliestDate').val('—'); $('#fLatestDate').text('—');
    $('#fReviewBadge').attr('class','ic-status st-pending').text('待確認'); $('#fConfirmedInfo').text('');
    $('#itemBody').empty();
}
function openEdit(id){
    resetEditForm();
    $('#editTitle').text(id ? '編輯型態識別文件管制表' : '新增型態識別文件管制表');
    if (!id){ openMask('editMask'); icAddRow(); return; }
    $.getJSON(API, {action:'get', id:id}, function(res){
        if (!res.success){ alert(res.message||'載入失敗'); return; }
        CUR_ID = id;
        $('#fPartNo').val(res.doc.part_no||''); $('#fPartDId').val(res.doc.part_d_id||0);
        $('#fCustomerName').val(res.doc.customer_name||''); $('#fCustomerId').val(res.doc.customer_id||'');
        $('#fDocNo').text(res.doc.doc_no);
        $('#fCreatedInfo').text((res.doc.created_by_name||'')+' '+fmtDate((res.doc.created_at||'').substring(0,10)));
        $('#fProcessSummary').val(res.process_summary ? res.process_summary : '—');
        $('#fEarliestDate').val(res.doc_date_earliest ? fmtDate(res.doc_date_earliest) : '—');
        $('#fLatestDate').text(res.sign_date_latest ? fmtDate(res.sign_date_latest) : '—');
        $('#fReviewBadge').attr('class','ic-status '+(STATUS_CLS[res.doc.review_status]||'st-pending')).text(res.doc.review_status_label||'待確認');
        $('#btnConfirm').text(res.doc.review_status === 'pending' ? ' 確認清單' : ' 重新確認').prepend('<i class="fa fa-check"></i>');
        $('#fConfirmedInfo').text(res.doc.confirmed_by_name ? ('（'+res.doc.confirmed_by_name+' '+fmtDate((res.doc.confirmed_at||'').substring(0,10))+' 確認）') : '');
        ITEMS = res.items || [];
        renderItems();
        openMask('editMask');
    });
}
window.btnAddClick = function(){ openEdit(0); };
$('#btnAdd').on('click', function(){ openEdit(0); });

EGPartPicker.attach(document.getElementById('fPartNo'), {
    apiUrl: PART_API,
    onSelect: function(row){
        $('#fPartDId').val(row.d_id);
        $('#fCustomerName').val(row.customer_name||row.customer_id||''); $('#fCustomerId').val(row.customer_id||'');
        // 新增流程(尚未存檔)：選定料號後自動列出外來文件清單中此料號的資料到項目列
        if (!CUR_ID) {
            $.post(API, {action:'fetch_ext_for_part', part_d_id:row.d_id}, function(res){
                if (res && res.success && res.rows.length){ ITEMS = res.rows; renderItems(); }
            }, 'json');
        }
    }
});
// 直接打字修改料號文字但沒有從清單點選＝視同尚未選定有效料號，清空 d_id 避免存到舊選取值
$('#fPartNo').on('input', function(){ $('#fPartDId').val('0'); $('#fCustomerName').val(''); $('#fCustomerId').val(''); });

function pickProcessForRow(btn){
    var dId = $('#fPartDId').val();
    if (!dId || dId === '0'){ alert('請先選擇產品編號(料號)'); return; }
    var $tr = $(btn).closest('tr');
    $.post(API, {action:'get_order_process', part_d_id:dId}, function(res){
        if (!res.success){ alert(res.message||'查詢失敗'); return; }
        if (!res.rows.length){ alert('查無此料號的訂單/報價製程紀錄'); return; }
        if (res.rows.length === 1){ $tr.find('.f-proc').val(res.rows[0].process); return; }
        var html = '';
        res.rows.forEach(function(r, i){
            html += '<div class="eg-pp-item" style="padding:6px 9px;border-bottom:1px solid #F3E9D6;cursor:pointer;" onclick="applyProcessToRow('+i+');">'
                + '<b>'+esc(r.process)+'</b><span style="color:#8a6d45;font-size:11px;margin-left:8px;">'+esc(r.order_oo)+'／'+fmtDate(r.order_date)+'</span></div>';
        });
        $('#procList').html(html);
        window._procRows = res.rows;
        window._procTarget = $tr;
        openMask('procMask');
    }, 'json');
}
window.applyProcessToRow = function(i){
    if (window._procTarget) window._procTarget.find('.f-proc').val(window._procRows[i].process);
    closeMask('procMask');
};

function itemRowHtml(it, idx){
    var linked = it.is_linked;
    var excluded = !!it.is_excluded;
    var needProc = !!it.need_process_hint;
    var typeOpts = TYPE_OPTS.map(function(t){ return '<option value="'+t[0]+'"'+(it.item_type===t[0]?' selected':'')+'>'+t[1]+'</option>'; }).join('');
    var linkBadge = linked ? '<span class="ic-link-badge"><i class="fa fa-link"></i> 外來文件</span>' : (it.ref_broken ? '<span class="ic-broken-badge">來源已消失</span>' : '');
    var docNoCell = '<div class="ic-part-box"><input type="text" class="f-docno" value="'+esc(it.doc_no_text||'')+'"'+(linked?' disabled':'')+' placeholder="版別／文件編號">'
        + (linked && it.file_url ? ' <a href="'+esc(it.file_url)+'" target="_blank" class="ic-row-btn" title="點開附件確認內容"><i class="fa fa-eye"></i></a>' : '')
        + '</div>';
    var procCell = '<div class="ic-part-box"><input type="text" class="f-proc'+(needProc?' f-proc-hint':'')+'" data-need-process="'+(needProc?'1':'0')+'" value="'+esc(it.process_tag||'')+'" placeholder="共用(空白)"'+(needProc?' title="此類別文件建議標示所屬製程（僅提示，不強制）"':'')+'>'
        + '<button type="button" class="ic-row-btn" onclick="pickProcessForRow(this)" title="從此料號的訂單/報價製程挑選"><i class="fa fa-list"></i></button></div>';
    var opCell = linked
        ? '<label class="ic-chk"><input type="checkbox" class="f-included"'+(excluded?'':' checked')+' onchange="toggleExcluded(this)"> 納入</label>'
        : '<button type="button" class="ic-row-btn" onclick="pickExtDoc(this)"'+ (($('#fPartDId').val()|0) ? '' : ' disabled title="請先選擇料號"') +'>選外來文件</button>'
          + ' <button type="button" class="ic-row-btn del" onclick="$(this).closest(\'tr\').remove(); renumberRows();">刪除</button>';
    return '<tr draggable="true" class="'+(excluded?'ic-excluded':'')+'" data-ref-source="'+esc(it.ref_source||'')+'" data-ref-attach-id="'+esc(it.ref_attach_id||'')+'" data-ref-ds-pk="'+esc(it.ref_ds_pk||'')+'" data-id="'+esc(it.id||0)+'">'
        + '<td class="drg" title="拖曳調整順序"><i class="fa fa-ellipsis-v"></i></td>'
        + '<td class="seq">'+(idx+1)+'</td>'
        + '<td><input type="text" class="f-name" value="'+esc(it.item_name||'')+'" placeholder="型態項目名稱"></td>'
        + '<td><input type="date" class="f-date" value="'+esc(it.effective_date||'')+'"'+(linked?' disabled':'')+'></td>'
        + '<td><select class="f-type">'+typeOpts+'</select></td>'
        + '<td>'+procCell+'</td>'
        + '<td>'+docNoCell+' '+linkBadge+'</td>'
        + '<td class="op">'+opCell+'</td>'
        + '</tr>';
}
function renderItems(){
    var html = '';
    ITEMS.forEach(function(it, idx){ html += itemRowHtml(it, idx); });
    $('#itemBody').html(html);
    if (!ITEMS.length) icAddRow();
}
function renumberRows(){ $('#itemBody tr').each(function(i){ $(this).find('td.seq').text(i+1); }); }
window.toggleExcluded = function(chk){
    $(chk).closest('tr').toggleClass('ic-excluded', !chk.checked);
};
/* 拖曳排序：純 HTML5 原生 DnD，放開後重新編項次 */
var dragSrcRow = null;
$('#itemBody').on('dragstart', 'tr', function(e){
    dragSrcRow = this;
    if (e.originalEvent.dataTransfer) { e.originalEvent.dataTransfer.effectAllowed = 'move'; try { e.originalEvent.dataTransfer.setData('text/plain', ''); } catch(e2){} }
});
$('#itemBody').on('dragover', 'tr', function(e){
    e.preventDefault();
    if (e.originalEvent.dataTransfer) e.originalEvent.dataTransfer.dropEffect = 'move';
    $(this).addClass('drag-over');
});
$('#itemBody').on('dragleave', 'tr', function(){ $(this).removeClass('drag-over'); });
$('#itemBody').on('drop', 'tr', function(e){
    e.preventDefault();
    $(this).removeClass('drag-over');
    if (!dragSrcRow || this === dragSrcRow) return;
    var rows = $('#itemBody tr').get();
    var srcIdx = rows.indexOf(dragSrcRow), tgtIdx = rows.indexOf(this);
    if (srcIdx < tgtIdx) $(this).after(dragSrcRow); else $(this).before(dragSrcRow);
    renumberRows();
});
$('#itemBody').on('dragend', 'tr', function(){ dragSrcRow = null; $('#itemBody tr').removeClass('drag-over'); });

window.icAddRow = function(){
    var blank = {id:0, item_name:'', item_type:'other', process_tag:'', need_process_hint:false, effective_date:'', doc_no_text:'', is_linked:false, is_excluded:false, ref_source:null, ref_attach_id:null, ref_ds_pk:null};
    $('#itemBody').append(itemRowHtml(blank, $('#itemBody tr').length));
    renumberRows();
    return true;
};
window.icDelRow = function(){
    var rows = $('#itemBody tr');
    if (rows.length <= 1) return false;
    rows.last().remove();
    renumberRows();
    return true;
};

function pickExtDoc(btn){
    var dsPk = $('#fPartDId').val();
    if (!dsPk || dsPk === '0'){ alert('請先選擇料號'); return; }
    var $tr = $(btn).closest('tr');
    $('#extEmpty').show().text('載入中…'); $('#extTable').hide();
    openMask('extMask');
    $.post(API, {action:'search_ext_doc', ds_pk: dsPk}, function(res){
        if (!res.success || !res.rows.length){ $('#extEmpty').show().text('外來文件清單中查無此料號的附件'); $('#extTable').hide(); return; }
        $('#extEmpty').hide(); $('#extTable').show();
        var html = '';
        res.rows.forEach(function(r, i){
            html += '<tr><td class="t-left">'+esc(r.doc_name)+'</td><td>'+fmtDate(r.doc_date)+'</td><td>'+(r.source==='part'?'料號附件':'報價附件')+'</td>'
                + '<td><button type="button" class="ic-row-btn" onclick="applyExtDoc('+i+')">選取</button></td></tr>';
        });
        $('#extBody').html(html);
        window._extRows = res.rows; window._extTarget = $tr;
    }, 'json');
}
window.applyExtDoc = function(i){
    var r = window._extRows[i], $tr = window._extTarget;
    $tr.attr('data-ref-source', r.source).attr('data-ref-attach-id', r.attach_id).attr('data-ref-ds-pk', r.ds_pk);
    var idx = $tr.index();
    ITEMS[idx] = collectRow($tr);
    ITEMS[idx].is_linked = true; ITEMS[idx].ref_source = r.source; ITEMS[idx].ref_attach_id = r.attach_id; ITEMS[idx].ref_ds_pk = r.ds_pk;
    ITEMS[idx].doc_no_text = r.doc_name; ITEMS[idx].effective_date = r.doc_date;
    ITEMS[idx].need_process_hint = !!r.need_process;
    $tr.replaceWith(itemRowHtml(ITEMS[idx], idx));
    closeMask('extMask');
};
function collectRow($tr){
    var linked = !!$tr.attr('data-ref-source');
    return {
        id: parseInt($tr.attr('data-id'),10) || 0,
        item_name: $tr.find('.f-name').val(),
        item_type: $tr.find('.f-type').val(),
        process_tag: $tr.find('.f-proc').val(),
        need_process_hint: $tr.find('.f-proc').attr('data-need-process') === '1',
        effective_date: $tr.find('.f-date').val(),
        doc_no_text: $tr.find('.f-docno').val(),
        is_linked: linked,
        is_excluded: linked ? !$tr.find('.f-included').is(':checked') : false,
        ref_source: $tr.attr('data-ref-source') || null,
        ref_attach_id: $tr.attr('data-ref-attach-id') || null,
        ref_ds_pk: $tr.attr('data-ref-ds-pk') || null,
    };
}

function saveAll(confirm){
    var partDId = $('#fPartDId').val();
    if (!partDId || partDId === '0'){ alert('請先選擇產品編號(料號)'); return; }
    var items = [];
    $('#itemBody tr').each(function(){
        var it = collectRow($(this));
        var payload = {
            id: it.id, item_name: it.item_name, item_type: it.item_type, process_tag: it.process_tag,
            need_process_hint: it.need_process_hint ? 1 : 0,
            ref_source: it.is_linked ? it.ref_source : '',
            ref_attach_id: it.is_linked ? it.ref_attach_id : 0,
            ref_ds_pk: it.is_linked ? it.ref_ds_pk : 0,
            is_excluded: it.is_excluded ? 1 : 0,
            manual_effective_date: it.is_linked ? '' : it.effective_date,
            manual_doc_no: it.is_linked ? '' : it.doc_no_text,
        };
        items.push(payload);
    });
    $.post(API, {
        action: 'save_all', id: CUR_ID, customer_id: $('#fCustomerId').val(), part_d_id: partDId,
        items: JSON.stringify(items), confirm: confirm ? 1 : 0
    }, function(res){
        if (!res.success){ alert(res.message||'儲存失敗'); return; }
        closeMask('editMask'); loadList();
    }, 'json');
}

function delDoc(id){
    if (!confirm('確定刪除此筆型態識別文件管制表？')) return;
    $.post(API, {action:'delete_header', id:id}, function(res){
        if (!res.success){ alert(res.message||'刪除失敗'); return; }
        loadList();
    }, 'json');
}

/* ---------- 列印（ai-rules/16：大標題本公司名／頁尾右下AS編號／頁碼左下；製表人簽章走 eg_stamp.js ---------- */
/* onDone：供「列印全部搜尋結果」排隊呼叫用，每筆各自獨立開視窗/各自獨立算頁次（不做合併分頁，
   每份文件本來就是各自獨立的列印工作，第幾頁/共幾頁自然不會互相干擾，只有一頁時也維持原本邏輯不顯示頁碼） */
function printDoc(id, onDone){
    $.getJSON(API, {action:'print_get', id:id}, function(res){
        if (!res.success){ alert(res.message||'載入失敗'); if (onDone) onDone(); return; }
        var d = res.doc;
        window.__ownCompany = res.company_name || '';
        var typeLabel = {drawing:'圖面', jig:'治夾具', report:'報告', other:'其他文件'};
        var activeItems = (res.items||[]).filter(function(it){ return !it.is_excluded; });
        var body = '<div class="p-comp">'+esc(res.company_name)+'</div>'
            + '<div class="p-title">'+esc(res.as_doc_name)+'</div>'
            + '<table class="p-hd"><tr><td>客戶</td><td>'+esc(d.customer_name||'')+'</td><td>建立日期</td><td>'+(res.doc_date_earliest?fmtDate(res.doc_date_earliest):'')+'</td></tr>'
            + '<tr><td>料號</td><td>'+esc(d.part_no||'')+'</td><td>製程</td><td>'+esc(res.process_summary||'')+'</td></tr>'
            + '</table>'
            + '<table class="p-tb"><thead><tr><th style="width:26px;">項次</th><th>型態項目名稱</th><th style="width:85px;">型態生效日期</th><th style="width:65px;">型態類別</th><th style="width:90px;">所屬製程</th><th>版別／文件編號</th></tr></thead><tbody>';
        activeItems.forEach(function(it, i){
            body += '<tr><td>'+(i+1)+'</td><td class="tl">'+esc(it.item_name)+'</td><td>'+fmtDate(it.effective_date)+'</td><td>'+(typeLabel[it.item_type]||'')+'</td><td>'+esc(it.process_tag||'共用')+'</td><td class="tl">'+esc(it.print_doc_no||'')+'</td></tr>';
        });
        body += '</tbody></table>';
        var makerName = d.confirmed_by_name || '';
        var makerDate = res.sign_date_latest ? fmtDate(res.sign_date_latest) : '';
        var makerStamp = makerName ? EGStamp.stamp(makerName, makerDate) : '<span style="color:#999;font-size:12px;">（尚未確認）</span>';
        body += '<div style="margin-top:16px;display:flex;justify-content:flex-end;align-items:flex-end;gap:6px;">'
              + '<span style="font-size:12px;color:#777;margin-bottom:8px;">製表：</span>' + makerStamp + '</div>';
        var css = 'body{font-family:"Microsoft JhengHei",sans-serif;margin:0;padding:0 6mm;color:#222;-webkit-print-color-adjust:exact;print-color-adjust:exact;}'
            + '.p-comp{font-size:22px;font-weight:bold;text-align:center;margin-bottom:1px;}'
            + '.p-title{font-size:17px;font-weight:bold;text-align:center;letter-spacing:4px;margin-bottom:10px;}'
            + 'table.p-hd{width:100%;border-collapse:collapse;font-size:12px;margin-bottom:8px;}'
            + 'table.p-hd td{border:1px solid #666;padding:3px 6px;} table.p-hd td:nth-child(odd){background:#f3ead6;width:12%;font-weight:bold;}'
            + 'table.p-tb{width:100%;table-layout:fixed;border-collapse:collapse;font-size:11px;}'
            + 'table.p-tb thead{display:table-header-group;}'
            + 'table.p-tb th,table.p-tb td{border:1px solid #666;padding:2px 5px;text-align:center;overflow-wrap:anywhere;}'
            + 'table.p-tb thead th{background:#f3ead6;} table.p-tb td.tl{text-align:left;}'
            + 'table.p-tb tr{break-inside:avoid;}'
            + '.stamp-wrap{display:inline-block;text-align:center;margin:2px 10px 2px 0;}'
            + '@page{margin:12mm 10mm 18mm;'
            + (res.as_doc_no ? " @bottom-right{ content:'"+String(res.as_doc_no).replace(/['\\]/g,'')+"'; font-size:9pt; color:#333; vertical-align:top; padding-top:1mm; }" : '')
            + '}';
        var w = window.open('', '_blank');
        w.document.write('<html><head><meta charset="utf-8"><title>型態識別文件管制表</title><style>'+css+'</style></head><body>'+body
            +'<scr'+'ipt>window.onload=function(){'
            +'var onePageA4=(297-30)*96/25.4;'
            +'if(document.body.scrollHeight>onePageA4*0.92){'
            +'var st=document.createElement(\'style\');'
            +'st.textContent="@page{ @bottom-left{ content:\'第 \' counter(page) \' 頁／共 \' counter(pages) \' 頁\'; font-size:9pt; color:#333; vertical-align:top; padding-top:1mm; } }";'
            +'document.head.appendChild(st);}'
            +'setTimeout(function(){window.print();},200);};</scr'+'ipt></body></html>');
        w.document.close();
        if (onDone) setTimeout(onDone, 500);
    });
}

/* ---------- 列印全部搜尋結果（依目前篩選條件，逐筆各自開視窗列印；結果較多時先確認、自動分批排隊觸發，
   不合併成單一份文件，所以「每份文件自己一份頁次、只有一頁不顯示頁次」直接沿用單筆列印既有邏輯） ---------- */
var PRINT_ALL_BATCH_THRESHOLD = 15;
$('#btnPrintAll').on('click', function(){
    var ids = CUR_LIST_ROWS.map(function(r){ return r.id; });
    if (!ids.length){ alert('目前沒有搜尋結果可列印'); return; }
    if (ids.length > PRINT_ALL_BATCH_THRESHOLD) {
        if (!confirm('目前搜尋結果共 '+ids.length+' 筆，數量較多，一次列印可能造成瀏覽器負擔。\n是否改為自動分批列印（依序逐筆觸發，不需手動操作）？\n（若瀏覽器跳出「已封鎖快顯視窗」提示，請允許本頁彈出視窗）')) return;
    }
    var idx = 0;
    function next(){
        if (idx >= ids.length){ alert('已完成列印 '+ids.length+' 筆。'); return; }
        var cur = ids[idx++];
        printDoc(cur, next);
    }
    next();
});

/* ---------- AS 文件綁定 ---------- */
function renderAsDocLabel(){
    $('#asDocLabel').text(EGAsDoc.label(AS_DOC));
    $('#hdrAsDocNo').text(AS_DOC && AS_DOC.doc_no ? AS_DOC.doc_no : '尚未綁定');
}
function loadAsDocCurrent(){
    $.getJSON(API, {action:'asdoc_get'}, function(res){
        AS_DOC = (res && res.success) ? res.as_doc : null;
        renderAsDocLabel();
    });
}
$('#btnAsDoc').on('click', function(){
    $.getJSON(API, {action:'asdoc_list'}, function(res){
        if (!res.success) return;
        AS_DOCS = res.docs || [];
        loadAsDocCurrent();
        openMask('asDocMask');
    });
});
function openAsDocPicker(){
    EGAsDoc.open({
        docs: AS_DOCS, current: AS_DOC ? AS_DOC.id : 0, title: '型態識別文件管制表 AS 文件綁定',
        onSave: function(id){
            $.post(API, {action:'as_doc_save', doc_id:id}, function(res){
                if (!res.success){ alert(res.message||'儲存失敗'); return; }
                AS_DOC = res.as_doc; renderAsDocLabel();
            }, 'json');
        }
    });
}

/* ---------- 廠內圖面標籤設定 ---------- */
$('#btnOwnDrawCats').on('click', function(){
    $('#ownDrawEmpty').show().text('載入中…'); $('#ownDrawList').empty();
    openMask('ownDrawMask');
    $.getJSON(API, {action:'get_own_drawing_categories'}, function(res){
        if (!res.success){ $('#ownDrawEmpty').text(res.message||'載入失敗'); return; }
        if (!res.rows.length){ $('#ownDrawEmpty').text('主檔管理目前沒有任何標記「自家出的圖」的附件類別，請先到主檔管理→附件類別標籤設定勾選。'); return; }
        $('#ownDrawEmpty').hide();
        var html = '';
        res.rows.forEach(function(r){
            html += '<div class="own-draw-row" data-id="'+r.id+'" style="display:flex;align-items:center;gap:8px;margin:6px 0;padding:6px 8px;border:1px solid #EADFC8;border-radius:6px;flex-wrap:wrap;">'
                + '<label class="ic-chk" style="flex:0 0 auto;"><input type="checkbox" class="own-draw-ck"'+(r.type_id_ctrl_include?' checked':'')+'> '+esc(r.category_name)+'</label>'
                + '<input type="text" class="own-draw-name" placeholder="顯示名稱(留空用「'+esc(r.category_name)+'」)" value="'+esc(r.external_doc_name||'')+'" style="flex:1 1 140px;height:28px;font-size:12px;padding:0 6px;border:1px solid #D8BE93;border-radius:4px;box-sizing:border-box;">'
                + '<label class="ic-chk" style="flex:0 0 auto;color:#8A5A2B;" title="勾選後，此類別同步出的項目列在「所屬製程」欄位若留空會加提示色塊，僅視覺提示不強制"><input type="checkbox" class="own-draw-proc"'+(r.type_id_ctrl_need_process?' checked':'')+'> 需要顯示製程</label>'
                + '</div>';
        });
        $('#ownDrawList').html(html);
    });
});
function collectOwnDrawRows(){
    var rows = [];
    $('#ownDrawList .own-draw-row').each(function(){
        rows.push({
            id: $(this).data('id'),
            included: $(this).find('.own-draw-ck').is(':checked') ? 1 : 0,
            name: $(this).find('.own-draw-name').val(),
            need_process: $(this).find('.own-draw-proc').is(':checked') ? 1 : 0,
        });
    });
    return rows;
}
function saveOwnDrawCats(){
    $.post(API, {action:'save_own_drawing_categories', rows: JSON.stringify(collectOwnDrawRows())}, function(res){
        if (!res.success){ alert(res.message||'儲存失敗'); return; }
        closeMask('ownDrawMask');
    }, 'json');
}
function refreshSyncedItemNames(){
    if (!$('#ownDrawList .own-draw-row').length) return;
    if (!confirm('確定要用目前的「顯示名稱／需要顯示製程」設定，更新所有已同步項目的型態項目名稱嗎？\n（會先儲存目前設定；僅影響來源仍連結有效附件的項目列，手動輸入的項目不受影響；已確認的清單若被更新會改回「需重新確認」）')) return;
    $.post(API, {action:'save_own_drawing_categories', rows: JSON.stringify(collectOwnDrawRows())}, function(res){
        if (!res.success){ alert(res.message||'儲存失敗'); return; }
        $.post(API, {action:'refresh_item_names_by_category'}, function(res2){
            if (!res2.success){ alert(res2.message||'更新失敗'); return; }
            alert('已更新 '+res2.updated_count+' 筆項目名稱'+(res2.affected_docs?'，其中 '+res2.affected_docs+' 份原已確認的清單已改回「需重新確認」':'')+'。');
            closeMask('ownDrawMask');
            loadList();
        }, 'json');
    }, 'json');
}

$('#btnPageHelp').on('click', function(){ openMask('helpUseMask'); });
$('#btnRoleHelp').on('click', function(){ openMask('roleHelpMask'); });
$('.ic-mask').on('click', function(e){ if (e.target === this) this.style.display='none'; });

/* ---------- 回頂端（往下捲動看不到列表標題列時，右下角浮動按鈕可快速回頂端） ---------- */
$(window).on('scroll', function(){ $('#btnBackTop').toggle($(window).scrollTop() > 280); });
$('#btnBackTop').on('click', function(){ $('html,body').animate({scrollTop:0}, 250); });

<?php if ($perms['canView']): ?>
loadList();
loadAsDocCurrent();
<?php endif; ?>
</script>
</body>
</html>
