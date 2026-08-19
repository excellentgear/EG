<?php
/**
 * 文件制、修申請單（2-DC-01-01）— 全新頁面（2026-08-19 建立）
 * 紙本：FOR CODEING 說明文件/AS9100(各組維護版)/文管中心/文管中心 2-DC/2-DC-01 文件管理程序/2-DC-01-01-文件制、修申請單.xls
 * 流程：申請人(填表) → 單位主管(審查) → 管理代表(會簽單位及審查) → 總經理(核准) → 文管中心(發行、回收)
 * 資料一律走 src/store/DocApply_API.php；共用邏輯 src/common/doc_apply_lib.php
 * 列印為 A4 直式 1:1（@page size:A4 portrait; margin:0），版面以 mm 定寸，避免縮放讓圖章失真。
 */
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/ADM/doc_apply.php";
    header("Location:../../index.php");
    exit;
}
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/doc_apply_lib.php';

$db = (new DBConnection())->getPDO();
da_ensure_schema($db);
$daUser = da_current_user($db);
$perms  = da_perms($db, $daUser);
/* 嵌入模式（?embed=1&apply_id=n）：只顯示這一筆的檢視跳窗，供其他頁面（如 AS 文件管理）用 iframe 叫出來，
   列印仍是本頁自己的列印（不在別頁重刻一份），權限一樣由 API 的 da_can_see 把關。 */
$daEmbed = !empty($_GET['embed']);
$roleLabel = $perms['isAdmin'] ? '管理者'
           : ($perms['canAdmin'] ? '文件制修申請單管理員'
           : ($perms['canEdit'] ? '文件制修申請單申請' : ($perms['canView'] ? '文件制修申請單檢閱' : '無角色（僅能處理指派給你的會簽）')));
?>
<!DOCTYPE html>
<html lang="zh-Hant"<?= $daEmbed ? ' class="da-embed"' : '' ?>>
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>文件制、修申請單</title>
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
        .da-toolbar { display:flex; flex-wrap:wrap; gap:6px; align-items:center; clear:both;
            border:1.5px solid #E8D5B5; border-radius:8px; padding:8px 10px; margin-bottom:10px; background:#FDF8EF; }
        .da-toolbar label { margin:0; font-size:13px; color:#5b3a1e; }
        .da-toolbar select, .da-toolbar input, .da-toolbar button {
            height:30px; font-size:13px; line-height:1; padding:0 10px; border:1px solid #D8BE93;
            border-radius:4px; background:#fff; color:#5b3a1e; }
        .da-toolbar button { cursor:pointer; }
        .da-toolbar button:hover { background:#F7E0BD; }
        .da-toolbar .btn-warm { background:#F0A24B; color:#fff; border-color:#d98a33; }
        .da-toolbar .btn-warm:hover { background:#d98a33; }
        .da-toolbar button.btn-danger { background:#DD5138; border-color:#C4442D; color:#fff; }
        .da-toolbar button.btn-danger:hover { background:#C4442D; }
        .da-role-badge { margin-left:auto; font-size:13px; color:#5b3a1e; background:#F7E0BD; border-radius:12px; padding:4px 12px; }
        .da-table-wrap { overflow-x:auto; border:1px solid #E8D5B5; border-radius:6px; background:#fff; }
        table.da-table { width:100%; border-collapse:collapse; font-size:13px; }
        table.da-table th, table.da-table td { border:1px solid #EADFC8; padding:5px 8px; text-align:center; }
        table.da-table thead th { background:#F7E0BD; color:#5b3a1e; font-weight:bold; white-space:nowrap; }
        table.da-table tbody tr:nth-child(even) { background:#FBF6EC; }
        table.da-table td.l { text-align:left; }
        .da-op { color:#b5762a; cursor:pointer; margin:0 4px; white-space:nowrap; }
        .da-op:hover { color:#8A5A2B; text-decoration:underline; }
        .da-pager { display:flex; justify-content:flex-end; align-items:center; gap:6px; margin:6px 0; font-size:13px; color:#5b3a1e; }
        .da-pager button { height:26px; padding:0 9px; border:1px solid #D8BE93; background:#fff; border-radius:4px; cursor:pointer; }
        .da-pager button.on { background:#F0A24B; color:#fff; border-color:#d98a33; }
        .st { display:inline-block; padding:1px 9px; border-radius:10px; font-size:12px; white-space:nowrap; }
        .st-draft { background:#EFE7D8; color:#6b5535; }
        .st-submitted { background:#F7E0BD; color:#8A5A2B; }
        .st-approved { background:#F0A24B; color:#fff; }
        .st-rejected { background:#DD5138; color:#fff; }
        .cs-none { background:#EFE7D8; color:#6b5535; }
        .cs-unset { background:#F3E4CB; color:#8A5A2B; }
        .cs-doing { background:#F7E0BD; color:#8A5A2B; }
        .cs-agreed { background:#F0A24B; color:#fff; }
        .cs-disagree { background:#DD5138; color:#fff; }
        .da-mask { display:none; position:fixed; inset:0; background:rgba(60,40,20,.45); z-index:9000; overflow:auto; }
        /* 嵌入模式：整頁只剩跳窗，遮罩底色仍由本頁的 .da-mask 畫（不依賴 iframe 透明度） */
        html.da-embed, body.da-embed { background:transparent !important; }
        body.da-embed .container.body { display:none !important; }
        .da-modal { background:#fff; border-radius:8px; margin:30px auto; max-width:1000px; width:96%; box-shadow:0 8px 30px rgba(0,0,0,.3); }
        .da-modal.narrow { max-width:520px; }
        .da-modal.mid { max-width:760px; }
        .m-head { background:#F7E0BD; color:#5b3a1e; font-weight:bold; padding:9px 14px; border-radius:8px 8px 0 0; display:flex; }
        .m-close { margin-left:auto; cursor:pointer; }
        .m-body { padding:14px; max-height:74vh; overflow:auto; }
        .m-foot { padding:10px 14px; border-top:1px solid #EADFC8; text-align:right; }
        .m-foot button, .b-att { height:32px; padding:0 14px; border-radius:4px; border:1px solid #D8BE93; background:#fff; color:#5b3a1e; cursor:pointer; }
        .m-foot .b-ok { background:#F0A24B; color:#fff; border-color:#d98a33; margin-left:6px; }
        .m-body label { display:block; font-size:13px; color:#5b3a1e; margin:0 0 3px; font-weight:normal; }
        .m-body input[type=text], .m-body input[type=date], .m-body input[type=number], .m-body select, .m-body textarea {
            width:100%; border:1px solid #D8BE93; border-radius:4px; padding:5px 8px; font-size:13px; color:#5b3a1e; }
        .m-body textarea { resize:vertical; }
        .ro-auto { background:#F3EADB; color:#7a6446; }
        .da-hint { font-size:12px; color:#8a6d45; line-height:1.7; }
        .da-err { color:#DD5138; font-size:12px; margin-top:2px; display:none; }
        .fld-bad input, .fld-bad select, .fld-bad textarea { border-color:#DD5138 !important; background:#FDF1EE; }
        .grid2 { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
        .grid3 { display:grid; grid-template-columns:1fr 1fr 1fr; gap:10px; }
        .sec { border:1px solid #EADFC8; border-radius:6px; padding:10px; margin-bottom:12px; background:#FDFAF4; }
        .sec > h5 { margin:0 0 8px; font-size:14px; color:#8A5A2B; font-weight:bold; border-bottom:1px solid #F0E2C7; padding-bottom:4px; }
        table.sub-tbl { width:100%; border-collapse:collapse; font-size:13px; background:#fff; }
        table.sub-tbl th, table.sub-tbl td { border:1px solid #EADFC8; padding:3px 5px; text-align:center; }
        table.sub-tbl th { background:#F7E0BD; color:#5b3a1e; white-space:nowrap; }
        table.sub-tbl input, table.sub-tbl select, table.sub-tbl textarea {
            width:100%; border:1px solid #E8D5B5; border-radius:3px; padding:3px 5px; font-size:13px; color:#5b3a1e; }
        table.sub-tbl textarea { resize:vertical; min-height:26px; }
        .da-noperm { border:1.5px solid #E8D5B5; background:#FDF8EF; border-radius:8px; padding:24px; color:#5b3a1e; }
        .da-top { position:fixed; right:24px; bottom:24px; width:40px; height:40px; border-radius:20px; background:#F0A24B;
            color:#fff; border:none; cursor:pointer; display:none; z-index:100; }
        .chip { display:inline-block; background:#F7E0BD; color:#5b3a1e; border-radius:11px; padding:2px 10px; margin:2px 3px 2px 0; font-size:12px; }
        .chip .x { cursor:pointer; color:#b5762a; margin-left:4px; font-weight:bold; }
    </style>
</head>
<body class="nav-sm<?= $daEmbed ? ' da-embed' : '' ?>">
<div class="container body">
<div class="main_container">
    <?php include '../partPage/sideAndTopBarMenu.html' ?>
    <div class="right_col" role="main">
        <div class="page-title" style="display:flex;align-items:center;flex-wrap:wrap;">
            <h2 style="margin:6px 0;">文件制、修申請單
                <small style="color:#8a6d45;">2-DC-01-01　文件制訂／修正／廢止／增發／補發的申請與會簽</small></h2>
            <button id="btnPageHelp" class="page-help-btn" style="margin-left:auto;"><i class="fa fa-question-circle"></i> 使用說明</button>
        </div>
        <div class="clearfix"></div>

        <div class="da-toolbar">
            <label>狀態</label>
            <select id="fStatus" style="width:120px;">
                <option value="">全部</option>
                <option value="draft">草稿</option>
                <option value="submitted">已送出</option>
                <option value="approved">已核准</option>
                <option value="rejected">已退回</option>
            </select>
            <label>申請日期</label>
            <input type="date" id="fFrom" max="9999-12-31" style="width:140px;">
            <span>～</span>
            <input type="date" id="fTo" max="9999-12-31" style="width:140px;">
            <input type="text" id="fKw" placeholder="單號/編碼/名稱/申請人" style="width:180px;">
            <button id="btnSearch" class="btn-warm"><i class="fa fa-search"></i> 查詢</button>
            <?php if ($perms['canEdit']): ?>
            <button id="btnAdd"><i class="fa fa-plus"></i> 新增申請單</button>
            <?php endif; ?>
            <button id="btnPrintSel"><i class="fa fa-print"></i> 批次列印所選</button>
            <button id="btnCsv" title="匯出目前搜尋條件的「全部」資料（不是只匯出這一頁）"><i class="fa fa-file-excel-o"></i> 匯出 CSV</button>
            <?php if ($perms['canAdmin']): ?>
            <button id="btnAutoSel"><i class="fa fa-bolt"></i> 批次自動簽核</button>
            <button id="btnSuggest"><i class="fa fa-magic"></i> 建議建立</button>
            <button id="btnCosDef"><i class="fa fa-sitemap"></i> 會簽預設</button>
            <button id="btnChgDef"><i class="fa fa-list-alt"></i> 制修訂內容預設</button>
            <button id="btnSetting"><i class="fa fa-cog"></i> 模組設定</button>
            <button id="btnDelSel" class="btn-danger"><i class="fa fa-trash"></i> 批次刪除所選</button>
            <?php endif; ?>
            <span class="da-role-badge">目前角色：<?= htmlspecialchars($roleLabel, ENT_QUOTES) ?></span>
        </div>

        <div class="da-pager">
            <span id="pgInfo"></span>
            <label>每頁</label>
            <select id="pgSize" style="height:26px;border:1px solid #D8BE93;border-radius:4px;">
                <option>5</option><option selected>10</option><option>20</option><option>50</option>
            </select>
            <span id="pgBtns"></span>
        </div>
        <div class="da-table-wrap">
            <table class="da-table">
                <thead><tr>
                    <th style="width:32px;"><input type="checkbox" id="chkAll"></th>
                    <th>單號</th><th>申請日期</th><th>文件狀況</th><th>類別</th>
                    <th>文件編碼</th><th>版本</th><th>文件名稱</th><th>申請部門</th><th>申請人</th>
                    <th>會簽狀態</th><th>單據狀態</th><th>列印狀態</th><th>操作</th>
                </tr></thead>
                <tbody id="listBody"><tr><td colspan="14" style="padding:20px;color:#8a6d45;">載入中…</td></tr></tbody>
            </table>
        </div>
        <button class="da-top" id="btnTop" title="回到頂端"><i class="fa fa-arrow-up"></i></button>
    </div>
</div>
</div>

<!-- ══════════ 申請單編輯 ══════════ -->
<div class="da-mask" id="editMask"><div class="da-modal" data-eg-form data-eg-submit="#btnSave">
    <div class="m-head"><span id="editTitle">新增文件制、修申請單</span><span class="m-close" onclick="closeMask('editMask')">✕</span></div>
    <div class="m-body">
        <div class="sec">
            <h5>表頭</h5>
            <div class="grid3">
                <div id="w_apply_date"><label>申請日期 *</label><input type="date" id="e_apply_date" max="9999-12-31"><div class="da-err"></div></div>
                <div id="w_doc_status"><label>文件狀況 *</label><select id="e_doc_status"></select><div class="da-err"></div></div>
                <div id="w_doc_type"><label>文件類別 *</label><select id="e_doc_type"></select><div class="da-err"></div></div>
            </div>
            <div class="grid2" style="margin-top:8px;">
                <div id="w_dept_id"><label>申請部門 *<span class="da-hint">（由申請人的職務自動帶出）</span></label>
                    <input type="text" id="e_dept_disp" class="ro-auto" readonly data-eg-skip placeholder="請先選擇申請人">
                    <input type="hidden" id="e_dept_id"><div class="da-err"></div></div>
                <div id="w_applicant_id"><label>申請人 *（填表人）<span class="da-hint" id="applicantAsofHint"></span></label>
                    <select id="e_applicant_id" data-eg-filter="輸入姓名篩選…"></select><div class="da-err"></div></div>
            </div>
            <div style="margin-top:8px;" id="w_doc_name"><label>文件名稱 *</label><input type="text" id="e_doc_name" maxlength="200"><div class="da-err"></div></div>
        </div>

        <div class="sec">
            <h5>文件編碼與版本</h5>
            <!-- 制訂：自動產生編碼（規則同 AS 文件管理）；其他狀況：挑既有 AS 文件 -->
            <div id="boxNew">
                <div class="grid3">
                    <div><label>文件階級（由類別推導）</label><input type="text" id="e_level" class="ro-auto" readonly data-eg-skip></div>
                    <div><label>母文件（表單掛在哪份程序書／標準書底下）</label>
                        <select id="e_parent" data-eg-filter="輸入編號或名稱篩選…"></select>
                        <div class="da-hint">沒有上階程序書可留空，直接在下方手動輸入既有的文件編碼。</div></div>
                    <div><label>部門代碼（同部門多組時選一）</label><select id="e_code"><option value="">（自動）</option></select></div>
                </div>
                <div style="margin-top:8px;display:flex;gap:6px;align-items:flex-end;">
                    <div style="flex:1;" id="w_doc_no"><label>文件編碼 *</label><input type="text" id="e_doc_no" maxlength="80" placeholder="按右側「自動產生」，或直接輸入既有編碼"><div class="da-err"></div></div>
                    <button type="button" class="b-att" id="btnGenNo"><i class="fa fa-magic"></i> 自動產生</button>
                </div>
            </div>
            <div id="boxOld" style="display:none;">
                <div id="w_as_doc_id"><label>要處理的 AS 文件 *</label>
                    <div style="display:flex;gap:6px;align-items:center;">
                        <input type="text" id="e_asdoc_label" class="ro-auto" readonly data-eg-skip placeholder="尚未選擇">
                        <button type="button" class="b-att" id="btnPickDoc"><i class="fa fa-search"></i> 選擇文件</button>
                    </div><div class="da-err"></div></div>
            </div>
            <div class="grid3" style="margin-top:8px;">
                <div id="w_version"><label>版本 <span id="verReq" style="color:#DD5138;">*</span></label>
                    <input type="text" id="e_version" maxlength="30"><div class="da-err"></div>
                    <div class="da-hint" id="verHint"></div></div>
                <div id="w_first_issue_date"><label>首次發行日期</label>
                    <input type="date" id="e_first_issue_date" class="ro-auto" readonly data-eg-skip max="9999-12-31"><div class="da-err"></div>
                    <div class="da-hint">「修正」時自動由 AS 文件的版本履歷帶入</div></div>
                <div><label>版本變更日期</label>
                    <input type="date" id="e_change_date" class="ro-auto" readonly data-eg-skip max="9999-12-31">
                    <div class="da-hint">＝本次申請日</div></div>
            </div>
        </div>

        <div class="sec">
            <h5>制修訂內容（頁次、項目、修訂前、修訂後）</h5>
            <div id="chgPresetBar" style="display:none;gap:6px;align-items:center;margin-bottom:6px;">
                <label style="margin:0;white-space:nowrap;">帶入預設內容</label>
                <select id="chgPreset" style="flex:1;"><option value="">— 請選擇 —</option></select>
                <button type="button" class="b-att" id="btnChgPreset"><i class="fa fa-download"></i> 帶入</button>
                <span class="da-hint">帶入後仍可自行修改</span>
            </div>
            <table class="sub-tbl">
                <thead><tr><th style="width:80px;">頁次</th><th style="width:150px;">項目</th><th>修訂前</th><th>修訂後</th><th style="width:34px;"></th></tr></thead>
                <tbody id="chgBody" data-eg-row-add="chgAddRow" data-eg-row-del="chgDelRow"></tbody>
            </table>
            <div class="da-err" id="err_changes"></div>
            <div class="da-hint">末列按 ↓ 自動加一列、空的末列按 ↑ 自動移除（共用輸入規則）。</div>
        </div>

        <div class="sec">
            <h5>核准後的連動與會簽</h5>
            <label style="display:flex;align-items:center;gap:6px;">
                <input type="checkbox" id="e_need_overview" style="width:auto;">
                文制修申請核准通過需同時更改「文件管制總覽表」或「品質記錄一覽表」
            </label>
            <label style="display:flex;align-items:center;gap:6px;margin-top:6px;">
                <input type="checkbox" id="e_need_cosign" style="width:auto;"> 是否需會簽
            </label>
            <div id="cosBox" style="margin-top:8px;">
                <label>會簽單位（預設由該文件所屬部門的設定帶入，可增減）</label>
                <div style="display:flex;gap:6px;align-items:center;">
                    <select id="e_cos_pick" data-eg-filter="輸入單位名稱篩選…" style="flex:1;"></select>
                    <button type="button" class="b-att" id="btnCosAdd"><i class="fa fa-plus"></i> 加入</button>
                </div>
                <div id="cosChips" style="margin-top:6px;"></div>
                <div class="da-err" id="err_cosign"></div>
            </div>
        </div>

        <div class="sec">
            <h5>文件／核發、回收記錄欄</h5>
            <table class="sub-tbl">
                <thead><tr><th style="width:150px;">部門（填寫單位）</th><th style="width:70px;">分發數</th><th style="width:135px;">分發日期</th>
                    <th style="width:110px;">簽收者</th><th style="width:70px;">回收數</th><th style="width:135px;">回收日期</th>
                    <th style="width:110px;">回收者</th><th>備註</th><th style="width:34px;"></th></tr></thead>
                <tbody id="distBody" data-eg-row-add="distAddRow" data-eg-row-del="distDelRow"></tbody>
            </table>
            <div class="da-hint">簽收者自動帶入該<b>填寫單位</b>的主管（非申請人）；回收者固定為<b>文管中心負責人</b>，由系統帶入不可改。</div>
        </div>
    </div>
    <div class="m-foot">
        <span id="editMsg" class="da-err" style="float:left;display:none;"></span>
        <button onclick="closeMask('editMask')">取消</button>
        <button class="b-ok" id="btnSave"><i class="fa fa-save"></i> 儲存草稿</button>
        <button class="b-ok" id="btnSubmit" style="background:#8A5A2B;border-color:#6f4720;"><i class="fa fa-paper-plane"></i> 儲存並送出</button>
    </div>
</div></div>

<!-- ══════════ 檢視／會簽 ══════════ -->
<div class="da-mask" id="viewMask"><div class="da-modal">
    <div class="m-head"><span id="viewTitle">申請單明細</span><span class="m-close" onclick="closeMask('viewMask')">✕</span></div>
    <div class="m-body" id="viewBody"></div>
    <div class="m-foot">
        <button onclick="closeMask('viewMask')">關閉</button>
        <button class="b-ok" id="btnViewPrint"><i class="fa fa-print"></i> 列印</button>
    </div>
</div></div>

<!-- ══════════ 會簽表態 ══════════ -->
<div class="da-mask" id="cosMask" style="z-index:9100;"><div class="da-modal narrow" data-eg-form data-eg-submit="#btnCosOk">
    <div class="m-head"><span>會簽</span><span class="m-close" onclick="closeMask('cosMask')">✕</span></div>
    <div class="m-body">
        <div id="cosInfo" class="da-hint" style="margin-bottom:10px;"></div>
        <label>同意 / 不同意 *（必須先選擇才能填意見）</label>
        <select id="cosAgree"><option value="">— 請選擇 —</option><option value="1">同意</option><option value="0">不同意</option></select>
        <div class="da-err" id="err_cosAgree"></div>
        <label style="margin-top:8px;">會簽意見（非必填）</label>
        <textarea id="cosOpinion" rows="3" maxlength="500" disabled class="ro-auto"></textarea>
    </div>
    <div class="m-foot"><button onclick="closeMask('cosMask')">取消</button>
        <button class="b-ok" id="btnCosOk"><i class="fa fa-pencil"></i> 簽名送出</button></div>
</div></div>

<!-- ══════════ 核准／退回 ══════════ -->
<div class="da-mask" id="decideMask" style="z-index:9100;"><div class="da-modal narrow" data-eg-form data-eg-submit="#btnDecideOk">
    <div class="m-head"><span>核准／退回</span><span class="m-close" onclick="closeMask('decideMask')">✕</span></div>
    <div class="m-body">
        <div id="decideInfo" class="da-hint" style="margin-bottom:10px;"></div>
        <label>決定 *</label>
        <select id="decideSel"><option value="approved">核准</option><option value="rejected">退回</option></select>
        <label style="margin-top:8px;">核准日期（業務日期）<span class="da-hint">— 預設＝本單申請日期</span></label>
        <input type="date" id="decideDate" max="9999-12-31">
        <label style="margin-top:8px;">意見／退回原因<span style="color:#DD5138;"> *（退回必填）</span></label>
        <textarea id="decideNote" rows="3" maxlength="500"></textarea>
        <div class="da-err" id="err_decide"></div>
    </div>
    <div class="m-foot"><button onclick="closeMask('decideMask')">取消</button>
        <button class="b-ok" id="btnDecideOk">確定</button></div>
</div></div>

<!-- ══════════ 自動簽核 ══════════ -->
<div class="da-mask" id="autoMask" style="z-index:9100;"><div class="da-modal narrow" data-eg-form data-eg-submit="#btnAutoOk">
    <div class="m-head"><span>管理員自動簽核</span><span class="m-close" onclick="closeMask('autoMask')">✕</span></div>
    <div class="m-body">
        <div id="autoInfo" class="da-hint" style="margin-bottom:10px;"></div>
        <label>本次填表人（可手動改，留空＝各單原本的申請人）</label>
        <select id="autoUser" data-eg-filter="輸入姓名篩選…"><option value="">（不變更）</option></select>
        <label style="margin-top:8px;">申請日期（可手動改，留空＝各單原本的申請日期）</label>
        <input type="date" id="autoDate" max="9999-12-31">
        <label style="margin-top:8px;">操作確認密碼 *</label>
        <input type="password" id="autoPw" style="width:100%;border:1px solid #D8BE93;border-radius:4px;padding:5px 8px;">
        <div class="da-err" id="err_auto"></div>
        <div class="da-hint" style="margin-top:8px;">自動簽核的<b>簽核日期＝申請日期</b>；精確時間戳會自動錯開 5～30 分鐘且不跨日（ai-rules/21）。
            會簽列（已勾選採用者）會一併自動簽為「同意」。</div>
    </div>
    <div class="m-foot"><button onclick="closeMask('autoMask')">取消</button>
        <button class="b-ok" id="btnAutoOk"><i class="fa fa-bolt"></i> 執行自動簽核</button></div>
</div></div>

<!-- ══════════ 建議建立 ══════════ -->
<div class="da-mask" id="sugMask"><div class="da-modal">
    <div class="m-head"><span>建議建立 — 掃描缺少文件制修申請單的文件／改版</span><span class="m-close" onclick="closeMask('sugMask')">✕</span></div>
    <div class="m-body">
        <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-bottom:8px;">
            <label style="margin:0;">只掃描此日期（含）之後修訂的文件</label>
            <input type="date" id="sugSince" max="9999-12-31" style="width:150px;">
            <button type="button" class="b-att" id="btnSugScan"><i class="fa fa-search"></i> 掃描</button>
            <button type="button" class="b-att" id="btnSugAll"><i class="fa fa-check-square-o"></i> 全選</button>
            <span class="da-hint" id="sugInfo" style="margin-left:auto;"></span>
        </div>
        <div class="da-table-wrap">
            <table class="da-table">
                <thead><tr><th style="width:32px;"><input type="checkbox" id="sugChkAll"></th>
                    <th>修訂日期</th><th>文件編碼</th><th>文件名稱</th><th>類別</th><th>部門</th>
                    <th>版本</th><th>建議狀況</th><th>紙本申請單</th></tr></thead>
                <tbody id="sugBody"><tr><td colspan="9" style="padding:16px;color:#8a6d45;">請按「掃描」</td></tr></tbody>
            </table>
        </div>
        <div class="da-hint" style="margin-top:8px;">建立出來的是<b>草稿</b>，日期以該版本的<b>修訂日</b>為準，並自動帶入頁次／制修訂摘要與會簽預設；
            可再逐筆修改，或直接用「批次自動簽核」完成。</div>
    </div>
    <div class="m-foot"><button onclick="closeMask('sugMask')">關閉</button>
        <button class="b-ok" id="btnSugCreate"><i class="fa fa-plus"></i> 建立所選</button></div>
</div></div>

<!-- ══════════ 會簽預設 ══════════ -->
<div class="da-mask" id="cosDefMask"><div class="da-modal mid">
    <div class="m-head"><span>會簽預設（部門分類 → 單一文件覆寫）</span><span class="m-close" onclick="closeMask('cosDefMask')">✕</span></div>
    <div class="m-body">
        <div class="sec">
            <h5>新增／修改一筆預設</h5>
            <div class="grid2">
                <div><label>套用範圍</label>
                    <select id="cd_type"><option value="global">全站預設</option><option value="dept">部門（該部門的文件）</option><option value="doc">單一 AS 文件（可到表單層）</option></select></div>
                <div id="cd_scope_dept"><label>部門</label><select id="cd_dept" data-eg-filter="輸入部門名稱篩選…"></select></div>
                <div id="cd_scope_doc" style="display:none;"><label>AS 文件</label>
                    <div style="display:flex;gap:6px;">
                        <input type="text" id="cd_doc_label" class="ro-auto" readonly data-eg-skip placeholder="尚未選擇">
                        <button type="button" class="b-att" id="btnCdPickDoc">選擇</button>
                    </div></div>
            </div>
            <label style="display:flex;align-items:center;gap:6px;margin-top:8px;">
                <input type="checkbox" id="cd_need" style="width:auto;"> 預設需要會簽
            </label>
            <label style="margin-top:6px;">預設會簽單位</label>
            <div style="display:flex;gap:6px;">
                <select id="cd_pick" data-eg-filter="輸入單位名稱篩選…" style="flex:1;"></select>
                <button type="button" class="b-att" id="btnCdAdd">加入</button>
            </div>
            <div id="cd_chips" style="margin-top:6px;"></div>
            <div style="text-align:right;margin-top:8px;"><button type="button" class="b-att" id="btnCdSave"><i class="fa fa-save"></i> 儲存這筆預設</button></div>
        </div>
        <div class="da-table-wrap">
            <table class="da-table">
                <thead><tr><th>範圍</th><th>對象</th><th>需會簽</th><th>會簽單位</th><th style="width:70px;">操作</th></tr></thead>
                <tbody id="cdBody"><tr><td colspan="5" style="padding:14px;color:#8a6d45;">載入中…</td></tr></tbody>
            </table>
        </div>
        <div class="da-hint" style="margin-top:8px;">解析優先序：<b>單一文件覆寫 → 該文件所屬部門 → 全站預設</b>。</div>
    </div>
    <div class="m-foot"><button onclick="closeMask('cosDefMask')">關閉</button></div>
</div></div>

<!-- ══════════ 制修訂內容預設組（管理員） ══════════ -->
<div class="da-mask" id="chgDefMask"><div class="da-modal">
    <div class="m-head"><span>制修訂內容預設組</span><span class="m-close" onclick="closeMask('chgDefMask')">✕</span></div>
    <div class="m-body">
        <div class="sec">
            <h5 id="cpEditTitle">新增一組預設</h5>
            <div class="grid3">
                <div><label>預設組名稱 *</label><input type="text" id="cp_name" maxlength="100" placeholder="例：全冊檢視及修正"></div>
                <div><label>排序（小的排前面）</label><input type="number" id="cp_sort" value="0"></div>
                <div><label>啟用</label>
                    <select id="cp_active"><option value="1">啟用（填單時可選）</option><option value="0">停用</option></select></div>
            </div>
            <label style="margin-top:8px;">內容（可多列，填單時整組帶入）</label>
            <table class="sub-tbl">
                <thead><tr><th style="width:80px;">頁次</th><th style="width:150px;">項目</th><th>修訂前</th><th>修訂後</th><th style="width:34px;"></th></tr></thead>
                <tbody id="cpBody" data-eg-row-add="cpAddRow" data-eg-row-del="cpDelRow"></tbody>
            </table>
            <div style="text-align:right;margin-top:8px;">
                <button type="button" class="b-att" id="btnCpNew">清空改為新增</button>
                <button type="button" class="b-att" id="btnCpSave" style="background:#F0A24B;color:#fff;border-color:#d98a33;">
                    <i class="fa fa-save"></i> 儲存這組</button>
            </div>
        </div>
        <div class="da-table-wrap">
            <table class="da-table">
                <thead><tr><th style="width:60px;">排序</th><th>名稱</th><th style="width:70px;">列數</th><th style="width:70px;">狀態</th>
                    <th class="l">預覽</th><th style="width:100px;">操作</th></tr></thead>
                <tbody id="cpListBody"><tr><td colspan="6" style="padding:14px;color:#8a6d45;">載入中…</td></tr></tbody>
            </table>
        </div>
    </div>
    <div class="m-foot"><button onclick="closeMask('chgDefMask')">關閉</button></div>
</div></div>

<!-- ══════════ 模組設定 ══════════ -->
<div class="da-mask" id="setMask"><div class="da-modal mid">
    <div class="m-head"><span>模組設定</span><span class="m-close" onclick="closeMask('setMask')">✕</span></div>
    <div class="m-body">
        <div class="sec">
            <h5>AS 文件編號綁定</h5>
            <div style="display:flex;gap:8px;align-items:center;">
                <span id="setAsDocLabel" style="flex:1;color:#5b3a1e;">尚未綁定</span>
                <button type="button" class="b-att" id="btnBindDoc"><i class="fa fa-link"></i> 變更綁定</button>
            </div>
            <div class="da-hint">列印的表頭表單名稱、頁尾右下角文件編號與版次都取自這裡（版次依單據日期回推）。</div>
        </div>
        <div class="sec">
            <h5>四格簽章來源（核准／管理代表／單位主管／申請人）</h5>
            <div class="grid2">
                <div><label>核准</label><select id="s_da_sign_approve"></select></div>
                <div><label>管理代表</label><select id="s_da_sign_mgmt"></select></div>
                <div><label>單位主管</label><select id="s_da_sign_sup"></select></div>
                <div><label>申請人</label><select id="s_da_sign_applicant"></select></div>
            </div>
            <div class="da-hint">預設：核准＝最高決策者、管理代表＝管理課主管、單位主管＝申請人的上一級主管、申請人＝填表人。
                實際人員一律即時查組織角色綁定，不寫死人名。</div>
        </div>
        <div class="sec">
            <h5>圖章模板</h5>
            <div class="grid3">
                <div><label>四格簽章</label><select id="s_da_stamp_tpl_id"></select></div>
                <div><label>會簽單位「簽名」欄</label><select id="s_da_cosign_stamp_tpl_id"></select></div>
                <div><label>核發／回收記錄簽收欄</label><select id="s_da_dist_stamp_tpl_id"></select></div>
            </div>
            <div class="da-hint">未指定＝使用預設回墨印章。模板於「圖章管理」維護；已上傳掃描實體章者一律優先用掃描章。</div>
        </div>
    </div>
    <div class="m-foot"><button onclick="closeMask('setMask')">關閉</button></div>
</div></div>

<!-- ══════════ 使用說明 ══════════ -->
<div class="da-mask" id="helpUseMask"><div class="da-modal">
    <div class="m-head"><span><i class="fa fa-question-circle"></i> 文件制、修申請單 使用說明</span><span class="m-close" onclick="closeMask('helpUseMask')">✕</span></div>
    <div class="m-body help-doc">
        <h4>功能說明</h4>
        <p>本頁是紙本 <b>2-DC-01-01 文件制、修申請單</b>的線上版。文件要<b>制訂／修正／廢止／增發／補發</b>時在這裡開單，
        送出後由會簽單位表態、再由核准人核准；核准後可列印成與紙本相同的 A4 直式表單存查。
        申請單會<b>自動依文件編碼與版本連結到「AS 文件管理」的版本履歷</b>，在該文件的「歷史版本」跳窗即可直接開啟對應的線上申請單。</p>

        <h4>操作步驟</h4>
        <ul>
            <li><b>新增申請單</b> → 選文件狀況與類別 → 填文件名稱、申請部門、申請人。</li>
            <li><b>制訂</b>：文件編碼按「自動產生」（規則與 AS 文件管理完全相同——表單依<b>母文件</b>遞增、其餘依<b>階級＋部門代碼</b>遞增）。
                <b>沒有上階程序書</b>的文件，母文件留空、直接在「文件編碼」欄手動輸入既有編碼即可，不會被擋下。</li>
            <li><b>修正／廢止／增發／補發</b>：改為「選擇文件」挑既有 AS 文件，<b>首次發行日期會自動帶入</b>；版本變更日期固定＝本次申請日。</li>
            <li>填<b>制修訂內容</b>（頁次／項目／修訂前／修訂後），末列按 ↓ 自動加列；管理員若已建好<b>預設內容</b>，
                上方會出現「帶入預設內容」下拉，選一組按<b>帶入</b>即整組接上去，帶入後仍可自行修改。</li>
            <li>勾選<b>是否需會簽</b>並指定會簽單位（會依該文件所屬部門的預設自動帶入）。</li>
            <li>按<b>「儲存並送出」</b>；未填的必填欄位會即時標紅並列出原因，<b>擋住送出</b>。</li>
            <li>送出後，被指定的會簽單位主管會收到<b>通知</b>，點通知即可開啟會簽：<b>必須先選同意／不同意</b>才能填寫會簽意見（意見非必填），送出即完成簽名。</li>
            <li>文管中心負責人／管理員在明細跳窗可勾選<b>「採用並簽」</b>的會簽單位，並看得到其他人的會簽意見與同意與否。</li>
            <li>核准後即可<b>列印</b>；列印會留下紀錄，清單「列印狀態」顯示最新列印日期與次數。</li>
        </ul>

        <h4>重要行為／常見疑問</h4>
        <ul>
            <li><b>版本規則</b>：手冊／程序／標準書<b>一律必填版本</b>；<b>表單「制訂」時沒有版本</b>（欄位反灰不可填），之後依 A、B、C… 遞增，改版時系統自動建議下一碼。</li>
            <li><b>不可填的欄位一律反灰</b>；送出前前端與後端<b>各驗一次</b>，直接打 API 也繞不過去。</li>
            <li><b>核准後需同時更改</b>「文件管制總覽表」或「品質記錄一覽表」是紙本上的勾選項，請據實勾選。</li>
            <li><b>回收記錄</b>：簽收者＝<b>填寫單位</b>的主管（不是申請人），回收者<b>固定為文管中心負責人</b>，由系統帶入。</li>
            <li><b>建議建立</b>（管理員）：掃描 AS 文件管理裡「有新文件或改版、但還沒有線上申請單」的版本，可設定只掃某日期之後、可多選或全選一次建立；建立出來的草稿<b>日期以修訂日為準</b>並自動帶入相關資料。</li>
            <li><b>自動簽核</b>（管理員）：需輸入<b>操作確認密碼</b>；簽核日期＝申請日期，精確時間戳自動錯開 5～30 分鐘且不跨日。可在跳窗<b>手動指定本次填表人與日期</b>（補歷史紙本用）。</li>
            <li><b>匯出</b>：「匯出 CSV」會依<b>目前的搜尋條件把全部資料</b>由後端組檔（不是只匯出畫面上這一頁）；
                需要 PDF 就用<b>列印</b>（列印目的地選「另存為 PDF」即可，版面與紙本完全一致）。</li>
            <li><b>列印是 A4 直式 1:1</b>：版面以 mm 定寸、不做縮放，避免圖章大小失真。頁尾右下角的文件編號與版次<b>依本單申請日期回推</b>當時生效的版本。</li>
            <li><b>簽章一律是帶日期的圖章</b>，不只印人名；代理人代簽時圖章右下角會有「代」字。</li>
            <li><b>核准日期預設＝本單申請日期</b>（可由系統管理者在核准跳窗改成其他日期）；四格簽章的圖章日期一律跟著核准日期，精確時間戳另存不影響業務日期。</li>
            <li><b>申請人本身就是單位主管時，「單位主管」欄一樣蓋他自己的章</b>——不做權責迴避、不往上找人、也不留白。</li>
            <li><b>申請人候選與所有簽章人都依「申請日期」回推當時的狀況</b>：候選只列<b>該日期當時在職</b>的人
                （含當時在職、<b>現在已離職</b>的人，會標「已離職」），職務也是<b>當時的部門與職稱</b>；
                單位主管、管理代表、會簽單位主管一律解析成<b>當時的那位主管</b>，不會蓋成現任者的章。
                職務回推取自「職務調動紀錄」，沒補登異動紀錄的人一律以現況計算。
                <span style="color:#b06f27;">「核准」欄綁的是固定人員（最高核准人員），不隨日期變動</span>——補很舊的單據時請自行確認該欄是否合適。</li>
            <li>申請日期一改，申請人候選就會<b>重新依新日期抓一次</b>；未核准的單在明細跳窗會顯示「預計會蓋誰」。</li>
            <li><b>申請人下拉是「逐職務」列出的</b>：一個人有兼任就會出現多列（標「兼任」），
                依<b>部門 → 職稱</b>順序排序（不是姓名筆畫）。<b>「申請部門」不是自己選的</b>——它由你挑的那一個職務
                自動帶出、欄位反灰不可手填（後端也用同一套規則再算一次，直打 API 也不會存進對不起來的部門）。
                所以要換部門，就選<b>該人在那個部門的那一列</b>。</li>
            <li><b>本人請假時可由代理人代為填表</b>：被代理人在<b>申請日期當天真的請假</b>時，申請人下拉會多出一列
                「<b>該部門　職稱　代理人姓名（代理 被代理人）</b>」，選它即可——<b>申請部門＝被代理人的那個部門</b>
                （例：文管中心），申請人印<b>代理人本人</b>的名字、圖章右下角自動加「<b>代</b>」字，清單與明細顯示
                「代理人（代理 被代理人）」。代理人名單取自<b>代理設定</b>（同一職務有多位時取第一順位）；
                代理人自己那天也請假、或當時不在職，就不會列出來。沒有請假就不會有這一列（避免下拉被長期代理設定灌爆）。
                後端存檔時會用同一套規則<b>再驗一次</b>，直打 API 也塞不進不成立的代理身分。</li>
            <li>把申請日期改到某人<b>當時還沒到職／已離職</b>的區間時，該人不會出現在候選裡；若是既有單據，
                系統會保留「原紀錄」那一筆並標示<b>（原紀錄，當時清單查無）</b>提醒你確認，<b>不會默默改選成別人</b>。</li>
        </ul>

        <h4>設定入口</h4>
        <p>工具列<b>「模組設定」</b>（管理員）：AS 文件編號綁定、四格簽章來源、三組圖章模板（四格簽章／會簽單位簽名欄／核發回收簽收欄）。<br>
        工具列<b>「制修訂內容預設」</b>（管理員）：建立幾組常用的制修訂內容（可多列、可排序、可停用），填單時一鍵帶入再修改。<br>
        工具列<b>「會簽預設」</b>（管理員）：以<b>部門</b>分類設定「預設是否會簽＋預設會簽單位」，也可對<b>單一 AS 文件（可到表單層）</b>個別覆寫；
        解析優先序＝單一文件 → 部門 → 全站預設。<br>
        部門主管、最高核准人員、管理課、文管中心的認定來自
        <a href="../admin/org_role_setting.php" target="_blank" style="color:#b5762a;">組織角色綁定設定</a>，本頁不寫死任何人名。</p>

        <h4>權限角色</h4>
        <p><b>文件制修申請單檢閱</b>：唯讀查看全部申請單與列印。<b>文件制修申請單申請</b>：檢閱＋開立／編輯／送出自己的申請單。
        <b>文件制修申請單管理員</b>：全部＋代他人開單、勾選會簽採用、核准／退回、自動簽核、建議建立、批次列印／刪除、會簽預設與模組設定。<br>
        <b>沒有任何角色的人</b>，若被指派為某張單的會簽單位簽核人（含代理人），仍可開啟該單並完成會簽。管理者固定擁有全部權限。
        角色指派於<a href="../user/user_permissions.php" target="_blank" style="color:#b5762a;">使用者權限設定</a>。</p>
    </div>
    <div class="m-foot"><button class="b-ok" onclick="closeMask('helpUseMask')">我知道了</button></div>
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
<script src="../../resource/js/eg_input_rules.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_input_rules.js') ?>"></script>
<script>
$(document).ready(function(){
    var $am = $('#sidebar-menu .nav.side-menu > li.active');
    if ($am.length) { $am.find('ul.child_menu').show(); }
    $('#sidebar-menu').css('visibility','visible');
});

var API   = '../../src/store/DocApply_API.php';
var PERMS = <?= json_encode($perms, JSON_UNESCAPED_UNICODE) ?>;
var META  = null, ASDOCS = null;
var DA_EMBED = <?= $daEmbed ? 'true' : 'false' ?>;   /* 被 iframe 嵌入時只跑檢視跳窗 */
var page = 1, pageSize = 10, listRows = [], curEditId = 0, curViewId = 0, curCosId = 0;
var cosSel = [], cdSel = [], sugRows = [], autoIds = [];

function esc(s){ return $('<div>').text(s == null ? '' : s).html(); }
/* 顯示用日期一律 YYYY.MM.DD（ai-rules/20；egFmtDate 由 eg_date_fmt.js 提供） */
function dispDate(v){ try { return window.egFmtDate ? egFmtDate(v) : (v || ''); } catch(e){ return v || ''; } }
function dispDT(v){ if(!v) return ''; var p = String(v).split(' '); return dispDate(p[0]) + (p[1] ? ' ' + p[1].substring(0,5) : ''); }
function openMask(id){ document.getElementById(id).style.display='block'; }
function closeMask(id){
    document.getElementById(id).style.display='none';
    /* 嵌入模式關掉檢視窗＝請外層頁面收掉 iframe（否則會留下一片空白遮罩） */
    if (DA_EMBED && id === 'viewMask') { try { parent.postMessage({t:'daEmbedClose'}, '*'); } catch(e){} }
}
/* API 用 HTTP 狀態碼回錯，jQuery 非 2xx 不進 success —— 統一在這裡顯示，避免「按了沒反應」 */
$(document).ajaxError(function(e, xhr){
    if (xhr && xhr.responseJSON && xhr.responseJSON.error) alert(xhr.responseJSON.error);
});
/** 申請人顯示文字：代理人代為填表時加註「（代理 被代理人）」 */
function applicantDisp(d){
    var n = d.applicant_name || '';
    return n + (d.applicant_on_behalf_name ? '（代理 ' + d.applicant_on_behalf_name + '）' : '');
}
function stampHtml(name, date, deputy, tpl){
    try { if (window.EGStamp && EGStamp.stamp) return EGStamp.stamp(name, date || '', !!deputy, tpl ? tpl.schema : null); }
    catch(e){}
    return esc(name || '');
}

/* ══════════════════ 初始化 ══════════════════ */
$.getJSON(API, {action:'meta'}, function(r){
    if (!r.ok) return;
    META = r;
    window.__ownCompany = r.company || '';
    var st = $('#e_doc_status').empty();
    (r.doc_status || []).forEach(function(s){ st.append('<option>' + esc(s) + '</option>'); });
    var tp = $('#e_doc_type').empty();
    (r.doc_types || []).forEach(function(s){ tp.append('<option>' + esc(s) + '</option>'); });

    var dep = $('#cd_dept').empty();
    (r.departments || []).forEach(function(d){ dep.append('<option value="' + d.id + '">' + esc(d.name) + '</option>'); });
    var cos = $('#e_cos_pick, #cd_pick').empty();
    (r.cosign_depts || []).forEach(function(d){ cos.append('<option value="' + d.id + '">' + esc(d.name) + '</option>'); });

    // 申請人／填表人：逐「職務」列出（含兼任），已由後端依 部門→職稱 sort_order 排序
    $('#e_applicant_id').empty();
    (r.people_posts || []).forEach(function(p){
        var o = '<option value="' + esc(p.post_key) + '" data-uid="' + p.id + '" data-dept="' + (p.dept_id || '')
              + '" data-deptname="' + esc(p.dept_name || '') + '" data-behalf="0" data-behalfname="">'
              + esc(p.display) + '</option>';
        $('#e_applicant_id').append(o);
        $('#autoUser').append(o);
    });

    // 設定跳窗
    var srcOpts = '';
    $.each(r.sign_sources || {}, function(k, v){ srcOpts += '<option value="' + esc(k) + '">' + esc(v) + '</option>'; });
    ['da_sign_approve','da_sign_mgmt','da_sign_sup','da_sign_applicant'].forEach(function(k){
        $('#s_' + k).html(srcOpts).val(r.settings[k] || '');
    });
    var tplOpts = '<option value="">（預設回墨印章）</option>';
    (r.stamp_tpls || []).forEach(function(t){ tplOpts += '<option value="' + t.id + '">' + esc(t.tpl_name) + '</option>'; });
    ['da_stamp_tpl_id','da_cosign_stamp_tpl_id','da_dist_stamp_tpl_id'].forEach(function(k){
        $('#s_' + k).html(tplOpts).val(r.settings[k] || '');
    });
    $('#setAsDocLabel').text(r.asdoc ? (r.asdoc.doc_no + '　' + r.asdoc.doc_name) : '尚未綁定');
    fillPresetSelect(r.change_presets || []);
    if (!DA_EMBED) loadList();   // 嵌入模式不需要清單，少打一次 API
    openFromQuery();
});

/* 由通知點進來：?cosign=cos_id 直接開會簽跳窗；?apply_id=n 直接開明細 */
function openFromQuery(){
    var q = new URLSearchParams(location.search);
    var aid = +q.get('apply_id') || 0, cid = +q.get('cosign') || 0;
    if (cid) {
        $.getJSON(API, {action:'cosign_row', cos_id:cid}, function(r){
            if (!r.ok) return;
            var c = r.cosign;
            openView(+c.apply_id);
            if (c.agree === null && parseInt(c.is_checked)) setTimeout(function(){ openCos(cid, c.dept_name); }, 500);
        });
    } else if (aid) { openView(aid); }
}

/* ══════════════════ 清單 ══════════════════ */
function loadList(){
    $.getJSON(API, {action:'list', kw:$('#fKw').val(), status:$('#fStatus').val(),
                    from:$('#fFrom').val(), to:$('#fTo').val(), page:page, size:pageSize}, function(r){
        if (!r.ok) return;
        listRows = r.rows || [];
        var b = $('#listBody').empty();
        if (!listRows.length) { b.append('<tr><td colspan="14" style="padding:20px;color:#8a6d45;">沒有符合的申請單</td></tr>'); }
        listRows.forEach(function(d){
            var cs = cosText(d);
            var pr = d.last_print_at ? (dispDT(d.last_print_at) + '（' + d.print_count + ' 次）') : '未列印';
            var ops = '<span class="da-op" onclick="openView(' + d.apply_id + ')"><i class="fa fa-eye"></i> 檢視</span>';
            if (d.status === 'draft' && (PERMS.canAdmin || PERMS.canEdit))
                ops += '<span class="da-op" onclick="openEdit(' + d.apply_id + ')"><i class="fa fa-pencil"></i> 編輯</span>';
            if (PERMS.canAdmin && d.status === 'submitted')
                ops += '<span class="da-op" onclick="openDecide(' + d.apply_id + ')"><i class="fa fa-gavel"></i> 核准</span>';
            ops += '<span class="da-op" onclick="doPrint([' + d.apply_id + '])"><i class="fa fa-print"></i> 列印</span>';
            b.append('<tr>'
                + '<td><input type="checkbox" class="chkRow" value="' + d.apply_id + '"></td>'
                + '<td>' + esc(d.apply_no || ('#' + d.apply_id)) + '</td>'
                + '<td>' + esc(dispDate(d.apply_date)) + '</td>'
                + '<td>' + esc(d.doc_status || '') + '</td>'
                + '<td>' + esc(d.doc_type || '') + '</td>'
                + '<td>' + esc(d.doc_no || '') + '</td>'
                + '<td>' + esc(d.version || '－') + '</td>'
                + '<td class="l">' + esc(d.doc_name || '') + '</td>'
                + '<td>' + esc(d.dept_name || '') + '</td>'
                + '<td>' + esc(applicantDisp(d)) + '</td>'
                + '<td><span class="st ' + cs.cls + '">' + esc(cs.txt) + '</span></td>'
                + '<td><span class="st st-' + esc(d.status) + '">' + esc(stName(d.status)) + '</span></td>'
                + '<td>' + esc(pr) + '</td>'
                + '<td>' + ops + '</td></tr>');
        });
        var totalPage = Math.max(1, Math.ceil(r.total / r.size));
        $('#pgInfo').text('共 ' + r.total + ' 筆／第 ' + r.page + '/' + totalPage + ' 頁');
        var pb = $('#pgBtns').empty();
        for (var i = 1; i <= totalPage; i++) {
            pb.append('<button class="' + (i === r.page ? 'on' : '') + '" onclick="gotoPage(' + i + ')">' + i + '</button>');
        }
        $('#chkAll').prop('checked', false);
    });
}
function gotoPage(p){ page = p; loadList(); }
function stName(s){ return {draft:'草稿', submitted:'已送出', approved:'已核准', rejected:'已退回'}[s] || s; }
function cosText(d){
    if (!parseInt(d.need_cosign)) return {cls:'cs-none', txt:'不需會簽'};
    var t = parseInt(d.cos_total || 0), done = parseInt(d.cos_done || 0), bad = parseInt(d.cos_bad || 0);
    if (!t) return {cls:'cs-unset', txt:'尚未指定'};
    if (done < t) return {cls:'cs-doing', txt:'會簽中 ' + done + '/' + t};
    if (bad) return {cls:'cs-disagree', txt:'有不同意（' + bad + '）'};
    return {cls:'cs-agreed', txt:'全部同意 ' + done + '/' + t};
}
$('#btnSearch').on('click', function(){ page = 1; loadList(); });
$('#pgSize').on('change', function(){ pageSize = +this.value; page = 1; loadList(); });
$('#chkAll').on('change', function(){ $('.chkRow').prop('checked', this.checked); });
function selIds(){ return $('.chkRow:checked').map(function(){ return +this.value; }).get(); }

/* ══════════════════ 編輯 ══════════════════ */
function clearErr(){ $('.da-err').hide().text(''); $('.fld-bad').removeClass('fld-bad'); $('#editMsg').hide(); }
function showErrs(fields){
    clearErr();
    var msgs = [];
    $.each(fields || {}, function(k, v){
        msgs.push(v);
        var $w = $('#w_' + k);
        if ($w.length) { $w.addClass('fld-bad').find('.da-err').text(v).show(); }
        else if (k === 'changes') $('#err_changes').text(v).show();
        else if (k === 'cosign')  $('#err_cosign').text(v).show();
    });
    $('#editMsg').text('尚有必填未完成：' + msgs.join('；')).show();
    alert('尚有必填欄位未完成：\n・' + msgs.join('\n・'));
}
$('#btnAdd').on('click', function(){ openEdit(0); });

function openEdit(id){
    curEditId = id; clearErr();
    $('#editTitle').text(id ? '編輯文件制、修申請單' : '新增文件制、修申請單');
    $('#chgBody').empty(); $('#distBody').empty(); cosSel = [];
    if (!id) {
        $('#e_apply_date').val(META.today); $('#e_change_date').val(META.today);
        $('#e_doc_status').val('制訂'); $('#e_doc_type').val('表單');
        $('#e_dept_id').val(''); $('#e_dept_disp').val('');
        $('#e_doc_name').val(''); $('#e_doc_no').val(''); $('#e_version').val('');
        $('#e_first_issue_date').val(''); $('#e_asdoc_label').val('').data('id', 0);
        $('#e_need_overview').prop('checked', true); $('#e_need_cosign').prop('checked', false);
        for (var i = 0; i < 4; i++) chgAddRow();
        for (var j = 0; j < 3; j++) distAddRow();
        loadPeopleAsof(function(){ setApplicant(META.me.id, META.me.dept_id); });
        syncMode(); renderCosChips(); loadParents(); openMask('editMask');
        return;
    }
    // 點開即刷新（ai-rules/08 第六節）：一律向後端抓最新狀態
    $.getJSON(API, {action:'detail', apply_id:id}, function(r){
        if (!r.ok) return;
        var d = r.row;
        if (!d.can_edit) { alert('此單已送出或非你可編輯，已改為檢視。'); openView(id); return; }
        $('#e_apply_date').val(d.apply_date); $('#e_change_date').val(d.change_date || d.apply_date);
        $('#e_doc_status').val(d.doc_status); $('#e_doc_type').val(d.doc_type);
        $('#e_dept_id').val(d.dept_id || ''); $('#e_dept_disp').val(d.dept_name || '');
        $('#e_doc_name').val(d.doc_name || ''); $('#e_doc_no').val(d.doc_no || '');
        $('#e_version').val(d.version || ''); $('#e_first_issue_date').val(d.first_issue_date || '');
        $('#e_asdoc_label').data('id', d.as_doc_id || 0).val(d.as_doc_id ? (d.doc_no + '　' + d.doc_name) : '');
        $('#e_need_overview').prop('checked', !!parseInt(d.need_overview));
        $('#e_need_cosign').prop('checked', !!parseInt(d.need_cosign));
        (d.changes || []).forEach(function(c){ chgAddRow(c); });
        for (var i = (d.changes || []).length; i < 4; i++) chgAddRow();
        (d.dists || []).forEach(function(x){ distAddRow(x); });
        for (var j = (d.dists || []).length; j < 3; j++) distAddRow();
        cosSel = (d.cosigns || []).map(function(c){ return {id:+c.dept_id, name:c.dept_name}; });
        loadPeopleAsof(function(){ setApplicant(d.applicant_id, d.dept_id, d.applicant_name, d.dept_name,
                                                 d.applicant_on_behalf_id); });
        syncMode(); renderCosChips(); loadParents(); openMask('editMask');
    });
}

function chgAddRow(c){
    c = c || {};
    $('#chgBody').append('<tr>'
        + '<td><input type="text" class="c-page" maxlength="60" value="' + esc(c.page_no || '') + '"></td>'
        + '<td><input type="text" class="c-item" maxlength="120" value="' + esc(c.item || '') + '"></td>'
        + '<td><textarea class="c-bf" rows="1">' + esc(c.before_txt || '') + '</textarea></td>'
        + '<td><textarea class="c-af" rows="1">' + esc(c.after_txt || '') + '</textarea></td>'
        + '<td><span class="da-op" onclick="$(this).closest(\'tr\').remove()">✕</span></td></tr>');
}
function chgDelRow(){ if ($('#chgBody tr').length > 1) $('#chgBody tr:last').remove(); }
function distAddRow(x){
    x = x || {};
    var opts = '<option value="">—</option>';
    (META.departments || []).forEach(function(d){
        opts += '<option value="' + d.id + '"' + (+x.dept_id === +d.id ? ' selected' : '') + '>' + esc(d.name) + '</option>';
    });
    $('#distBody').append('<tr>'
        + '<td><select class="d-dept" data-eg-filter="輸入單位篩選…">' + opts + '</select></td>'
        + '<td><input type="text" class="d-iq" maxlength="20" value="' + esc(x.issue_qty || '') + '"></td>'
        + '<td><input type="date" class="d-id" max="9999-12-31" value="' + esc(x.issue_date || '') + '"></td>'
        + '<td><input type="text" class="d-rn ro-auto" data-eg-skip readonly value="' + esc(x.receiver_name || '') + '"></td>'
        + '<td><input type="text" class="d-rq" maxlength="20" value="' + esc(x.recall_qty || '') + '"></td>'
        + '<td><input type="date" class="d-rd" max="9999-12-31" value="' + esc(x.recall_date || '') + '"></td>'
        + '<td><input type="text" class="d-rcn ro-auto" data-eg-skip readonly value="' + esc(x.recaller_name || '') + '"></td>'
        + '<td><input type="text" class="d-note" maxlength="200" value="' + esc(x.note || '') + '"></td>'
        + '<td><span class="da-op" onclick="$(this).closest(\'tr\').remove()">✕</span></td></tr>');
}
function distDelRow(){ if ($('#distBody tr').length > 1) $('#distBody tr:last').remove(); }

/* 文件狀況／類別改變 → 切換編碼區、版本必填與反灰（推導欄位鐵則：算不出就清空） */
function syncMode(){
    var stat = $('#e_doc_status').val(), type = $('#e_doc_type').val();
    var isNew = (stat === '制訂');
    $('#boxNew').toggle(isNew); $('#boxOld').toggle(!isNew);
    $('#e_level').val((META.type_level || {})[type] || '');
    var forbid = (type === '表單' && stat === '制訂');
    var required = forbid ? false : true;
    $('#e_version').prop('readonly', forbid).toggleClass('ro-auto', forbid);
    if (forbid) $('#e_version').val('');
    $('#verReq').toggle(required);
    $('#verHint').text(forbid ? '表單「制訂」時沒有版本，欄位不可填寫。'
        : (type === '表單' ? '表單改版依 A、B、C… 遞增（選定 AS 文件後自動建議下一碼）。' : type + '一律必須填寫版本。'));
    // 表單制訂才需要母文件（編碼由母文件遞增）；沒有上階程序書時可留空、手動輸入編碼
    $('#e_parent').closest('div').toggle(isNew && type === '表單');
    $('#e_code').closest('div').toggle(isNew && type !== '表單');
}
$('#e_doc_status, #e_doc_type').on('change', function(){
    syncMode();
    // 來源一改就重算，算不出就清空（推導欄位鐵則）
    if ($('#e_doc_status').val() === '制訂') { $('#e_first_issue_date').val(''); }
    else { var id = +$('#e_asdoc_label').data('id') || 0; if (id) pullAsDoc(id); }
});
$('#e_apply_date').on('change', function(){ $('#e_change_date').val($(this).val()); loadPeopleAsof(); });
/* 申請人候選一律依「申請日期」回推當時在職者與當時的職務（含當時在職、現已離職者）。
   來源日期一改就重抓，選不到原本那個人時清空讓使用者重選（推導欄位鐵則）。 */
function loadPeopleAsof(cb){
    var date = $('#e_apply_date').val() || META.today;
    var keep = $('#e_applicant_id').val();
    $.getJSON(API, {action:'people_asof', date:date}, function(r){
        if (!r.ok) return;
        var $s = $('#e_applicant_id').empty();
        (r.rows || []).forEach(function(p){
            $s.append('<option value="' + esc(p.post_key) + '" data-uid="' + p.id + '" data-dept="' + (p.dept_id || '')
                + '" data-deptname="' + esc(p.dept_name || '') + '" data-behalf="' + (p.on_behalf_id || 0)
                + '" data-behalfname="' + esc(p.on_behalf_name || '') + '">' + esc(p.display) + '</option>');
        });
        if (keep && $s.find('option[value="' + keep + '"]').length) {
            $s.val(keep);
        } else if (keep) {
            // 改了申請日期後原本選的職務不再成立（當時還沒到職／已離職／那天沒請假所以沒有代理列）：
            // 不可默默改選成清單第一個人，一律清空要求重選（推導欄位鐵則）
            $s.prepend('<option value="" data-uid="0" data-dept="" data-deptname="" data-behalf="0" data-behalfname="">— 請重新選擇申請人 —</option>').val('');
            $('#w_applicant_id').find('.da-err').text('原本選的職務在新的申請日期不成立，請重新選擇申請人。').show();
        }
        syncDeptFromApplicant();
        var nb = (r.rows || []).filter(function(p){ return +p.on_behalf_id > 0; }).length;
        $('#applicantAsofHint').text('（依申請日期 ' + dispDate(date) + ' 當時在職者與當時職務列出，共 '
            + (r.rows || []).length + ' 筆'
            + (nb ? '；含 ' + nb + ' 筆「本人當天請假、代理人代為填表」的職務' : '') + '）');
        if (cb) cb();
    });
}
/* 申請部門＝推導欄位：由選定的申請人「那一個職務」算出（含兼任），
   來源一改就重算、算不出就清空，不給手填（推導欄位鐵則） */
function syncDeptFromApplicant(){
    var $o = $('#e_applicant_id option:selected');
    var did = $o.data('dept'), dn = $o.attr('data-deptname') || '';
    if (did) { $('#e_dept_id').val(String(did)); $('#e_dept_disp').val(dn); }
    else     { $('#e_dept_id').val('');          $('#e_dept_disp').val(''); }
    $('#e_code').html('<option value="">（自動）</option>');   // 部門一變，部門代碼要重挑
}
$('#e_applicant_id').on('change', syncDeptFromApplicant);
$('#distBody').on('change', '.d-dept', function(){
    // 簽收者＝該填寫單位主管，由後端存檔時帶入；這裡先清空避免留著舊部門的人（推導欄位鐵則）
    $(this).closest('tr').find('.d-rn').val('');
});

function loadParents(){
    if (window.__daParents) { fillParents(); return; }
    $.getJSON(API, {action:'parent_docs'}, function(r){
        if (!r.ok) return; window.__daParents = r.parents || []; fillParents();
    });
}
function fillParents(){
    var cur = $('#e_parent').val();
    var s = $('#e_parent').empty().append('<option value="">— 請選擇母文件 —</option>');
    (window.__daParents || []).forEach(function(p){
        s.append('<option value="' + p.id + '">' + esc(p.doc_no + '　' + p.doc_name) + '</option>');
    });
    if (cur) s.val(cur);
}

$('#btnGenNo').on('click', function(){
    var p = {action:'suggest_doc_no', level:$('#e_level').val(),
             department_id:$('#e_dept_id').val() || 0, parent_doc_id:$('#e_parent').val() || 0,
             code:$('#e_code').val() || ''};
    // 表單的編碼是掛在母文件底下遞增，沒有母文件就算不出來——但不是死路：直接手動輸入既有編碼即可
    if ($('#e_doc_type').val() === '表單' && !p.parent_doc_id) {
        alert('表單的編碼是掛在母文件底下遞增，沒有選母文件就無法自動產生。\n\n'
            + '若這份表單沒有上階程序書、而你已經有既有的文件編碼，請直接在「文件編碼」欄手動輸入。');
        $('#e_doc_no').focus(); return;
    }
    $.getJSON(API, p, function(r){
        if (!r.ok) return;
        if (r.status === 'choose') {
            var s = $('#e_code').empty();
            (r.options || []).forEach(function(o){
                s.append('<option value="' + esc(o.code) + '" data-no="' + esc(o.doc_no) + '">'
                    + esc(o.code) + (o.label ? '（' + esc(o.label) + '）' : '') + ' → ' + esc(o.doc_no) + '</option>');
            });
            $('#e_doc_no').val(s.find('option:first').data('no') || '');
            alert('此部門有多組文件代碼，已列出可選項目，請確認「部門代碼」下拉的選擇。');
        } else {
            $('#e_doc_no').val(r.doc_no || '');
        }
    });
});
$('#e_code').on('change', function(){ $('#e_doc_no').val($(this).find('option:selected').data('no') || ''); });

$('#btnPickDoc').on('click', function(){
    withDocs(function(docs){
        EGAsDoc.open({docs:docs, current:+$('#e_asdoc_label').data('id') || 0, title:'選擇要處理的 AS 文件',
            onSave:function(id, doc){
                $('#e_asdoc_label').data('id', id).val(id ? (doc.doc_no + '　' + doc.doc_name) : '');
                if (id) pullAsDoc(id); else { $('#e_first_issue_date').val(''); }
            }});
    });
});
function withDocs(cb){
    if (ASDOCS) return cb(ASDOCS);
    $.getJSON(API, {action:'asdoc_list'}, function(r){ if (r.ok) { ASDOCS = r.docs || []; cb(ASDOCS); } });
}
/** 選定 AS 文件 → 帶入名稱、首次發行日期、建議下一版次、會簽預設 */
function pullAsDoc(id){
    $.getJSON(API, {action:'asdoc_info', doc_id:id}, function(r){
        if (!r.ok) return;
        var d = r.doc;
        $('#e_doc_no').val(d.doc_no || '');
        if (!$('#e_doc_name').val()) $('#e_doc_name').val(d.doc_name || '');
        $('#e_first_issue_date').val(d.first_issue_date || '');
        if ($('#e_doc_type').val() === '表單' && $('#e_doc_status').val() !== '制訂' && !$('#e_version').val()) {
            $('#e_version').val(d.next_version || 'A');
        }
        var cd = d.cosign_default || {};
        $('#e_need_cosign').prop('checked', !!parseInt(cd.need_cosign));
        if ((cd.dept_ids || []).length) {
            cosSel = cd.dept_ids.map(function(x){
                var m = (META.cosign_depts || []).filter(function(t){ return +t.id === +x; })[0];
                return {id:+x, name:m ? m.name : ('#' + x)};
            });
            renderCosChips();
        }
        toggleCosBox();
    });
}
function toggleCosBox(){ $('#cosBox').toggle($('#e_need_cosign').is(':checked')); }
$('#e_need_cosign').on('change', toggleCosBox);
$('#btnCosAdd').on('click', function(){
    var id = +$('#e_cos_pick').val(); if (!id) return;
    if (cosSel.some(function(c){ return c.id === id; })) return;
    cosSel.push({id:id, name:$('#e_cos_pick option:selected').text()});
    renderCosChips();
});
function renderCosChips(){
    var h = cosSel.map(function(c, i){
        return '<span class="chip">' + esc(c.name) + '<span class="x" onclick="cosDel(' + i + ')">✕</span></span>';
    }).join('');
    $('#cosChips').html(h || '<span class="da-hint">尚未指定會簽單位</span>');
    toggleCosBox();
}
function cosDel(i){ cosSel.splice(i, 1); renderCosChips(); }

/** 以「使用者＋部門（＋代理誰）」定位到那一個職務選項；該職務不存在時退回此人的第一個職務 */
function setApplicant(uid, deptId, fbName, fbDept, behalfId){
    var $s = $('#e_applicant_id');
    if (!uid) { $s.prop('selectedIndex', 0); syncDeptFromApplicant(); return; }
    var key = uid + ':' + (deptId || '') + (+behalfId ? ':b' + behalfId : '');
    if ($s.find('option[value="' + key + '"]').length) { $s.val(key); syncDeptFromApplicant(); return; }
    var $first = $s.find('option[data-uid="' + uid + '"]').first();
    if ($first.length) { $s.val($first.attr('value')); syncDeptFromApplicant(); return; }
    // 這個人在該申請日期當時不在職／查無職務（多半是舊單改了日期）：
    // 不能默默改選成別人 —— 補一個沿用原紀錄的選項，並提示使用者確認
    if (fbName) {
        $s.prepend('<option value="' + key + '" data-uid="' + uid + '" data-dept="' + (deptId || '')
            + '" data-deptname="' + esc(fbDept || '') + '">' + esc((fbDept ? fbDept + '　' : '') + fbName)
            + '（原紀錄，當時清單查無）</option>');
        $s.val(key);
    } else {
        $s.prop('selectedIndex', 0);
    }
    syncDeptFromApplicant();
}
function applicantUid(){ return +String($('#e_applicant_id').val() || '').split(':')[0] || 0; }
/** 代理人代為填表時＝被代理人的 user id，本人填表＝0 */
function applicantBehalf(){ return +$('#e_applicant_id option:selected').attr('data-behalf') || 0; }

function collectForm(){
    var changes = [];
    $('#chgBody tr').each(function(){
        var $t = $(this);
        changes.push({page_no:$t.find('.c-page').val(), item:$t.find('.c-item').val(),
                      before_txt:$t.find('.c-bf').val(), after_txt:$t.find('.c-af').val()});
    });
    var dists = [];
    $('#distBody tr').each(function(){
        var $t = $(this);
        if (!$t.find('.d-dept').val()) return;
        dists.push({dept_id:$t.find('.d-dept').val(), issue_qty:$t.find('.d-iq').val(), issue_date:$t.find('.d-id').val(),
                    recall_qty:$t.find('.d-rq').val(), recall_date:$t.find('.d-rd').val(), note:$t.find('.d-note').val()});
    });
    return {
        action:'save', apply_id:curEditId,
        apply_date:$('#e_apply_date').val(), doc_status:$('#e_doc_status').val(), doc_type:$('#e_doc_type').val(),
        doc_name:$('#e_doc_name').val(), doc_no:$('#e_doc_no').val(),
        as_doc_id:(+$('#e_asdoc_label').data('id') || 0),
        version:$('#e_version').val(), first_issue_date:$('#e_first_issue_date').val(),
        dept_id:$('#e_dept_id').val(), applicant_id:applicantUid(), on_behalf_id:applicantBehalf(),
        need_overview:$('#e_need_overview').is(':checked') ? 1 : 0,
        need_cosign:$('#e_need_cosign').is(':checked') ? 1 : 0,
        changes:JSON.stringify(changes), dists:JSON.stringify(dists),
        cosign_dept_ids:JSON.stringify(cosSel.map(function(c){ return c.id; }))
    };
}
/** 前端必填檢查（後端 da_validate 會再擋一次，不可只做半套） */
function frontValidate(p){
    var e = {};
    if (!p.apply_date) e.apply_date = '請填寫申請日期';
    if (!p.doc_name)   e.doc_name = '請填寫文件名稱';
    if (!p.dept_id)    e.dept_id = '請選擇申請部門';
    if (!p.applicant_id) e.applicant_id = '請選擇申請人';
    var forbid = (p.doc_type === '表單' && p.doc_status === '制訂');
    if (forbid && p.version) e.version = '表單「制訂」時沒有版本，不可填寫';
    if (!forbid && !p.version) e.version = (p.doc_type === '表單' ? '表單改版必須填寫版本（A、B、C…）' : p.doc_type + '必須填寫版本');
    if (p.doc_status === '制訂') { if (!p.doc_no) e.doc_no = '請按「自動產生」取得文件編碼'; }
    else {
        if (!p.as_doc_id) e.as_doc_id = '請選擇要' + p.doc_status + '的 AS 文件';
        if (p.doc_status === '修正' && !p.first_issue_date) e.first_issue_date = '查無此文件的首次發行日期（請先於 AS 文件管理補建版本履歷）';
    }
    if (p.doc_status === '修正') {
        var cs = JSON.parse(p.changes), has = false;
        cs.forEach(function(c){ if ((c.page_no||'') + (c.item||'') + (c.before_txt||'') + (c.after_txt||'') !== '') has = true; });
        if (!has) e.changes = '「修正」必須填寫制修訂內容（至少一列）';
    }
    if (+p.need_cosign === 1 && !JSON.parse(p.cosign_dept_ids).length) e.cosign = '已勾選「需會簽」，請至少指定一個會簽單位';
    return e;
}
$('#btnSave').on('click', function(){ doSave(false); });
$('#btnSubmit').on('click', function(){ doSave(true); });
function doSave(submit){
    var p = collectForm();
    if (submit) { var e = frontValidate(p); if (Object.keys(e).length) { showErrs(e); return; } }
    clearErr();
    $.post(API, p, function(r){
        if (!r.ok) return;
        if (!submit) { closeMask('editMask'); loadList(); alert('已儲存草稿'); return; }
        $.post(API, {action:'submit', apply_id:r.apply_id}, function(r2){
            if (!r2.ok) return;
            closeMask('editMask'); loadList();
            alert('已送出' + (r2.sent ? ('，已發出 ' + r2.sent + ' 則會簽通知') : ''));
        }, 'json').fail(function(xhr){
            var j = xhr.responseJSON || {};
            if (j.fields) showErrs(j.fields);
        });
    }, 'json');
}

/* ══════════════════ 檢視／會簽／核准 ══════════════════ */
function openView(id){
    curViewId = id;
    var jq = $.getJSON(API, {action:'detail', apply_id:id}, function(r){
        if (!r.ok) return;
        var d = r.row;
        $('#viewTitle').text('申請單 ' + (d.apply_no || ('#' + d.apply_id)) + '　' + (d.doc_no || '') + '　' + (d.doc_name || ''));
        var h = '<div class="sec"><h5>表頭</h5><div class="grid3">'
            + kv('申請日期', dispDate(d.apply_date)) + kv('文件狀況', d.doc_status) + kv('文件類別', d.doc_type)
            + kv('文件編碼', d.doc_no) + kv('版本', d.version || '－') + kv('文件名稱', d.doc_name)
            + kv('申請部門', d.dept_name) + kv('申請人', applicantDisp(d))
            + kv('首次發行日期', dispDate(d.first_issue_date)) + kv('版本變更日期', dispDate(d.change_date))
            + kv('單據狀態', stName(d.status))
            + kv('核准日期', dispDate(d.approved_date))
            + '</div>'
            + '<div style="margin-top:6px;">' + (parseInt(d.need_overview) ? '☑' : '☐')
            + ' 文制修申請核准通過需同時更改「文件管制總覽表」或「品質記錄一覽表」</div></div>';

        if ((d.changes || []).length) {
            h += '<div class="sec"><h5>制修訂內容</h5><table class="sub-tbl"><thead><tr><th>頁次</th><th>項目</th><th>修訂前</th><th>修訂後</th></tr></thead><tbody>';
            d.changes.forEach(function(c){
                h += '<tr><td>' + esc(c.page_no) + '</td><td>' + esc(c.item) + '</td><td>' + esc(c.before_txt) + '</td><td>' + esc(c.after_txt) + '</td></tr>';
            });
            h += '</tbody></table></div>';
        }

        h += '<div class="sec"><h5>會簽（' + esc(d.cosign_status.text) + '）</h5>';
        if (!parseInt(d.need_cosign)) { h += '<div class="da-hint">本申請單不需會簽。</div>'; }
        else {
            h += '<table class="sub-tbl"><thead><tr>'
               + (PERMS.canAdmin ? '<th style="width:70px;">採用並簽</th>' : '')
               + '<th style="width:130px;">會簽單位</th><th style="width:110px;">簽名</th><th style="width:60px;">同意</th>'
               + '<th style="width:60px;">不同意</th><th>會簽意見</th><th style="width:70px;">操作</th></tr></thead><tbody>';
            (d.cosigns || []).forEach(function(c){
                var mine = (+c.signer_id === +META.me.id) && c.agree === null && parseInt(c.is_checked) && d.status === 'submitted';
                var sig = (c.agree !== null && c.signer_name)
                        ? stampHtml(c.signer_name, dispDate(c.signed_date), parseInt(c.is_delegated), META.stamp_cosign) : '';
                h += '<tr>'
                   + (PERMS.canAdmin ? '<td><input type="checkbox" class="cosChk" value="' + c.cos_id + '"' + (parseInt(c.is_checked) ? ' checked' : '') + '></td>' : '')
                   + '<td>' + esc(c.dept_name) + '</td>'
                   + '<td>' + sig + '</td>'
                   + '<td>' + (+c.agree === 1 ? '✔' : '') + '</td>'
                   + '<td>' + (c.agree !== null && +c.agree === 0 ? '✔' : '') + '</td>'
                   + '<td class="l" style="text-align:left;">' + esc(c.opinion || '') + '</td>'
                   + '<td>' + (mine ? '<span class="da-op" onclick="openCos(' + c.cos_id + ',\'' + esc(c.dept_name) + '\')">會簽</span>'
                                    : (c.agree === null ? '<span class="da-hint">' + esc(c.signer_name || '未指定') + '</span>' : '')) + '</td>'
                   + '</tr>';
            });
            h += '</tbody></table>';
            if (PERMS.canAdmin) h += '<div style="text-align:right;margin-top:6px;"><button class="b-att" onclick="saveCosCheck(' + d.apply_id + ')"><i class="fa fa-save"></i> 儲存「採用並簽」勾選</button></div>';
        }
        h += '</div>';

        // 四格簽章
        h += '<div class="sec"><h5>簽章</h5><table class="sub-tbl"><thead><tr><th>核准</th><th>管理代表</th><th>單位主管</th><th>申請人</th></tr></thead><tbody><tr>'
           + '<td style="height:70px;">' + sigCell(d, 'approve') + '</td>'
           + '<td>' + sigCell(d, 'mgmt') + '</td>'
           + '<td>' + sigCell(d, 'sup') + '</td>'
           + '<td>' + sigCell(d, 'applicant') + '</td>'
           + '</tr></tbody></table></div>';

        if ((d.dists || []).length) {
            h += '<div class="sec"><h5>文件／核發、回收記錄欄</h5><table class="sub-tbl"><thead><tr>'
               + '<th>部門</th><th>分發數</th><th>分發日期</th><th>簽收者</th><th>回收數</th><th>回收日期</th><th>回收者</th><th>備註</th>'
               + '</tr></thead><tbody>';
            d.dists.forEach(function(x){
                h += '<tr><td>' + esc(x.dept_name) + '</td><td>' + esc(x.issue_qty) + '</td><td>' + esc(dispDate(x.issue_date)) + '</td>'
                   + '<td>' + esc(x.receiver_name) + '</td><td>' + esc(x.recall_qty) + '</td><td>' + esc(dispDate(x.recall_date)) + '</td>'
                   + '<td>' + esc(x.recaller_name) + '</td><td>' + esc(x.note) + '</td></tr>';
            });
            h += '</tbody></table></div>';
        }
        if ((d.prints || []).length) {
            h += '<div class="sec"><h5>列印紀錄（共 ' + d.prints.length + ' 筆）</h5><div class="da-hint">'
               + d.prints.map(function(p){ return esc(dispDT(p.printed_at) + '　' + p.printed_name); }).join('<br>') + '</div></div>';
        }
        $('#viewBody').html(h);
        openMask('viewMask');
    });
    /* 嵌入模式取不到資料（無權限/已刪）就請外層收掉 iframe，不要留一片空白 */
    if (DA_EMBED) jq.fail(function(){ try { parent.postMessage({t:'daEmbedClose'}, '*'); } catch(e){} });
}
function kv(k, v){ return '<div><label>' + esc(k) + '</label><div style="color:#5b3a1e;">' + esc(v || '－') + '</div></div>'; }
function sigCell(d, slot){
    var nm = d['sign_' + slot + '_name'], dt = d['sign_' + slot + '_date'], dep = parseInt(d['sign_' + slot + '_dep']);
    if (d.status === 'approved' && nm) return stampHtml(nm, dispDate(dt), dep, META.stamp_main);
    // 未核准：顯示「依本單申請日期回推、核准時會蓋誰」，讓補歷史單據時看得出來對不對
    var pv = (d.signer_preview || {})[slot];
    if (pv && pv.name) return '<span class="da-hint">（未核准）預計：' + esc(pv.name)
        + (pv.is_delegated ? '（代）' : '') + '</span>';
    return '<span class="da-hint">（未核准）</span>';
}
function saveCosCheck(id){
    var ids = $('.cosChk:checked').map(function(){ return +this.value; }).get();
    $.post(API, {action:'cosign_check', apply_id:id, cos_ids:JSON.stringify(ids)}, function(r){
        if (r.ok) { alert('已儲存'); openView(id); }
    }, 'json');
}
function openCos(cosId, deptName){
    curCosId = cosId;
    $('#cosInfo').html('會簽單位：<b>' + esc(deptName) + '</b>　請先選擇同意／不同意，再填寫意見（非必填）。');
    $('#cosAgree').val(''); $('#cosOpinion').val('').prop('disabled', true).addClass('ro-auto');
    $('#err_cosAgree').hide().text('');
    openMask('cosMask');
}
$('#cosAgree').on('change', function(){
    var on = this.value !== '';
    $('#cosOpinion').prop('disabled', !on).toggleClass('ro-auto', !on);
    $('#err_cosAgree').toggle(!on).text(on ? '' : '請先選擇同意／不同意');
});
$('#btnCosOk').on('click', function(){
    var a = $('#cosAgree').val();
    if (a === '') { $('#err_cosAgree').text('請先選擇同意／不同意，才能填寫意見與簽名').show(); return; }
    $.post(API, {action:'cosign_decide', cos_id:curCosId, agree:a, opinion:$('#cosOpinion').val()}, function(r){
        if (!r.ok) return;
        closeMask('cosMask'); openView(curViewId); loadList();
    }, 'json');
});

function openDecide(id){
    // 點開即刷新：確認這張單目前確實還可核准
    $.getJSON(API, {action:'detail', apply_id:id}, function(r){
        if (!r.ok) return;
        var d = r.row;
        if (d.status !== 'submitted') { alert('此單目前狀態為「' + stName(d.status) + '」，不可核准／退回。已重新整理清單。'); loadList(); return; }
        curViewId = id;
        $('#decideInfo').html('申請單：<b>' + esc(d.apply_no || ('#' + d.apply_id)) + '</b>　' + esc(d.doc_no || '') + '　' + esc(d.doc_name || '')
            + '<br>會簽狀態：' + esc(d.cosign_status.text));
        // 核准日期預設＝本單申請日期（使用者要求；仍可手改，回填權限由後端把關）
        $('#decideSel').val('approved'); $('#decideDate').val(d.apply_date || META.today); $('#decideNote').val('');
        $('#err_decide').hide();
        openMask('decideMask');
    });
}
$('#btnDecideOk').on('click', function(){
    var dec = $('#decideSel').val(), note = $('#decideNote').val();
    if (dec === 'rejected' && !note.trim()) { $('#err_decide').text('退回必須填寫原因').show(); return; }
    $.post(API, {action:'decide', apply_id:curViewId, decision:dec, note:note, approved_date:$('#decideDate').val()}, function(r){
        if (r.ok) { closeMask('decideMask'); closeMask('viewMask'); loadList(); }
    }, 'json');
});

/* ══════════════════ 批次操作 ══════════════════ */
$('#btnDelSel').on('click', function(){
    var ids = selIds(); if (!ids.length) { alert('請先勾選要刪除的申請單'); return; }
    if (!confirm('確定刪除所選 ' + ids.length + ' 張申請單？')) return;
    $.post(API, {action:'delete', apply_ids:JSON.stringify(ids)}, function(r){
        if (r.ok) { alert('已刪除 ' + r.done + ' 張'); loadList(); }
    }, 'json');
});
$('#btnAutoSel').on('click', function(){
    autoIds = selIds(); if (!autoIds.length) { alert('請先勾選要自動簽核的申請單'); return; }
    $('#autoInfo').html('將對所選 <b>' + autoIds.length + '</b> 張申請單執行自動簽核。');
    $('#autoUser').val(''); $('#autoDate').val(''); $('#autoPw').val(''); $('#err_auto').hide();
    openMask('autoMask');
});
$('#btnAutoOk').on('click', function(){
    if (!$('#autoPw').val()) { $('#err_auto').text('請輸入操作確認密碼').show(); return; }
    $.post(API, {action:'auto_sign', apply_ids:JSON.stringify(autoIds), confirm_password:$('#autoPw').val(),
                 override_applicant_id:(+String($('#autoUser').val() || '').split(':')[0] || 0),
                 override_dept_id:($('#autoUser option:selected').data('dept') || 0),
                 override_date:$('#autoDate').val() || ''}, function(r){
        if (!r.ok) return;
        closeMask('autoMask'); loadList();
        alert('已自動簽核 ' + r.done + ' 張' + ((r.skipped || []).length ? ('\n略過：\n・' + r.skipped.join('\n・')) : ''));
    }, 'json');
});

/* ══════════════════ 建議建立 ══════════════════ */
$('#btnSuggest').on('click', function(){
    $('#sugBody').html('<tr><td colspan="9" style="padding:16px;color:#8a6d45;">請按「掃描」</td></tr>');
    $('#sugInfo').text(''); openMask('sugMask');
});
$('#btnSugScan').on('click', function(){
    $.getJSON(API, {action:'suggest_scan', since:$('#sugSince').val()}, function(r){
        if (!r.ok) return;
        sugRows = r.rows || [];
        var b = $('#sugBody').empty();
        if (!sugRows.length) { b.append('<tr><td colspan="9" style="padding:16px;color:#8a6d45;">沒有缺少申請單的文件／改版</td></tr>'); }
        sugRows.forEach(function(v){
            b.append('<tr>'
                + '<td><input type="checkbox" class="sugChk" value="' + v.version_id + '"></td>'
                + '<td>' + esc(dispDate(v.revised_date)) + '</td>'
                + '<td>' + esc(v.doc_no) + '</td>'
                + '<td class="l">' + esc(v.doc_name) + '</td>'
                + '<td>' + esc(v.doc_type || v.doc_level || '') + '</td>'
                + '<td>' + esc(v.dept_name || '') + '</td>'
                + '<td>' + esc(v.version || '－') + '</td>'
                + '<td>' + esc(v.suggest_status) + '</td>'
                + '<td>' + (parseInt(v.has_paper) ? '已附掃描件' : '無') + '</td></tr>');
        });
        $('#sugInfo').text('掃描到 ' + sugRows.length + ' 筆');
    });
});
$('#sugChkAll, #btnSugAll').on('click', function(){
    var on = (this.id === 'btnSugAll') ? true : this.checked;
    $('.sugChk').prop('checked', on); $('#sugChkAll').prop('checked', on);
});
$('#btnSugCreate').on('click', function(){
    var ids = $('.sugChk:checked').map(function(){ return +this.value; }).get();
    if (!ids.length) { alert('請先勾選要建立的項目'); return; }
    $.post(API, {action:'suggest_create', version_ids:JSON.stringify(ids)}, function(r){
        if (!r.ok) return;
        alert('已建立 ' + r.created + ' 張草稿' + ((r.failed || []).length ? ('\n失敗：' + r.failed.join('、')) : ''));
        $('#btnSugScan').click(); loadList();
    }, 'json');
});

/* ══════════════════ 制修訂內容預設組 ══════════════════ */
var PRESETS = [];
function fillPresetSelect(rows){
    PRESETS = rows || [];
    var $s = $('#chgPreset').empty().append('<option value="">— 請選擇 —</option>');
    PRESETS.forEach(function(p){
        if (!parseInt(p.is_active)) return;
        $s.append('<option value="' + p.preset_id + '">' + esc(p.preset_name) + '（' + (p.rows || []).length + ' 列）</option>');
    });
    // 沒有任何可用預設就不佔版面
    $('#chgPresetBar').css('display', $s.find('option').length > 1 ? 'flex' : 'none');
}
$('#btnChgPreset').on('click', function(){
    var id = +$('#chgPreset').val() || 0;
    if (!id) { alert('請先選擇要帶入的預設內容'); return; }
    var p = PRESETS.filter(function(x){ return +x.preset_id === id; })[0];
    if (!p || !(p.rows || []).length) { alert('這組預設沒有內容'); return; }
    // 先清掉尾端空白列，再把預設整組接上去（既有已填內容不動）
    $('#chgBody tr').each(function(){
        var $t = $(this), empty = true;
        $t.find('input,textarea').each(function(){ if (String(this.value || '').trim() !== '') empty = false; });
        if (empty) $t.remove();
    });
    p.rows.forEach(function(c){ chgAddRow(c); });
    chgAddRow();     // 末尾留一列空白方便繼續填
});

$('#btnChgDef').on('click', function(){ cpReset(); loadPresets(); openMask('chgDefMask'); });
function cpAddRow(c){
    c = c || {};
    $('#cpBody').append('<tr>'
        + '<td><input type="text" class="p-page" maxlength="60" value="' + esc(c.page_no || '') + '"></td>'
        + '<td><input type="text" class="p-item" maxlength="120" value="' + esc(c.item || '') + '"></td>'
        + '<td><textarea class="p-bf" rows="1">' + esc(c.before_txt || '') + '</textarea></td>'
        + '<td><textarea class="p-af" rows="1">' + esc(c.after_txt || '') + '</textarea></td>'
        + '<td><span class="da-op cp-x">✕</span></td></tr>');
}
$('#cpBody').on('click', '.cp-x', function(){ if ($('#cpBody tr').length > 1) $(this).closest('tr').remove(); });
function cpDelRow(){ if ($('#cpBody tr').length > 1) $('#cpBody tr:last').remove(); }
function cpReset(){
    $('#cpEditTitle').text('新增一組預設');
    $('#btnCpSave').data('id', 0);
    $('#cp_name').val(''); $('#cp_sort').val(0); $('#cp_active').val('1');
    $('#cpBody').empty(); for (var i = 0; i < 3; i++) cpAddRow();
}
$('#btnCpNew').on('click', cpReset);
function cpEdit(id){
    var p = PRESETS.filter(function(x){ return +x.preset_id === id; })[0];
    if (!p) return;
    $('#cpEditTitle').text('修改預設組：' + p.preset_name);
    $('#btnCpSave').data('id', id);
    $('#cp_name').val(p.preset_name); $('#cp_sort').val(p.sort_order); $('#cp_active').val(String(p.is_active));
    $('#cpBody').empty();
    (p.rows || []).forEach(function(c){ cpAddRow(c); });
    cpAddRow();
    $('#chgDefMask .m-body').scrollTop(0);
}
$('#btnCpSave').on('click', function(){
    var name = $('#cp_name').val().trim();
    if (!name) { alert('請填寫預設組名稱'); return; }
    var rows = [];
    $('#cpBody tr').each(function(){
        var $t = $(this);
        var r = {page_no:$t.find('.p-page').val(), item:$t.find('.p-item').val(),
                 before_txt:$t.find('.p-bf').val(), after_txt:$t.find('.p-af').val()};
        if ((r.page_no + r.item + r.before_txt + r.after_txt).trim() !== '') rows.push(r);
    });
    if (!rows.length) { alert('請至少填寫一列制修訂內容'); return; }
    $.post(API, {action:'save_change_preset', preset_id:$(this).data('id') || 0, preset_name:name,
                 sort_order:$('#cp_sort').val() || 0, is_active:$('#cp_active').val(),
                 rows:JSON.stringify(rows)}, function(r){
        if (!r.ok) return;
        alert('已儲存'); cpReset(); loadPresets();
    }, 'json');
});
function cpDelete(id){
    if (!confirm('確定刪除這組預設？（已建立的申請單內容不受影響）')) return;
    $.post(API, {action:'delete_change_preset', preset_id:id}, function(r){ if (r.ok) { cpReset(); loadPresets(); } }, 'json');
}
function loadPresets(){
    $.getJSON(API, {action:'change_presets'}, function(r){
        if (!r.ok) return;
        PRESETS = r.rows || [];
        fillPresetSelect(PRESETS);
        var b = $('#cpListBody').empty();
        if (!PRESETS.length) { b.append('<tr><td colspan="6" style="padding:14px;color:#8a6d45;">尚未建立任何預設組</td></tr>'); return; }
        PRESETS.forEach(function(p){
            var prev = (p.rows || []).slice(0, 2).map(function(c){
                return [c.page_no, c.item, c.after_txt].filter(Boolean).join('／');
            }).join('　｜　') + ((p.rows || []).length > 2 ? ' …' : '');
            b.append('<tr><td>' + esc(p.sort_order) + '</td>'
                + '<td class="l">' + esc(p.preset_name) + '</td>'
                + '<td>' + (p.rows || []).length + '</td>'
                + '<td>' + (parseInt(p.is_active) ? '啟用' : '停用') + '</td>'
                + '<td class="l">' + esc(prev) + '</td>'
                + '<td><span class="da-op" onclick="cpEdit(' + p.preset_id + ')">修改</span>'
                + '<span class="da-op" onclick="cpDelete(' + p.preset_id + ')">刪除</span></td></tr>');
        });
    });
}

/* ══════════════════ 會簽預設 ══════════════════ */
$('#btnCosDef').on('click', function(){ cdSel = []; renderCdChips(); loadCosDef(); openMask('cosDefMask'); });
$('#cd_type').on('change', function(){
    $('#cd_scope_dept').toggle(this.value === 'dept');
    $('#cd_scope_doc').toggle(this.value === 'doc');
});
$('#btnCdPickDoc').on('click', function(){
    withDocs(function(docs){
        EGAsDoc.open({docs:docs, current:+$('#cd_doc_label').data('id') || 0, title:'選擇要覆寫設定的 AS 文件',
            onSave:function(id, doc){ $('#cd_doc_label').data('id', id).val(id ? (doc.doc_no + '　' + doc.doc_name) : ''); }});
    });
});
$('#btnCdAdd').on('click', function(){
    var id = +$('#cd_pick').val(); if (!id) return;
    if (cdSel.some(function(c){ return c.id === id; })) return;
    cdSel.push({id:id, name:$('#cd_pick option:selected').text()}); renderCdChips();
});
function renderCdChips(){
    $('#cd_chips').html(cdSel.map(function(c, i){
        return '<span class="chip">' + esc(c.name) + '<span class="x" onclick="cdDel(' + i + ')">✕</span></span>';
    }).join('') || '<span class="da-hint">尚未指定</span>');
}
function cdDel(i){ cdSel.splice(i, 1); renderCdChips(); }
$('#btnCdSave').on('click', function(){
    var t = $('#cd_type').val();
    var sid = t === 'dept' ? (+$('#cd_dept').val() || 0) : (t === 'doc' ? (+$('#cd_doc_label').data('id') || 0) : 0);
    if (t !== 'global' && !sid) { alert('請先選擇' + (t === 'dept' ? '部門' : 'AS 文件')); return; }
    $.post(API, {action:'save_cosign_default', scope_type:t, scope_id:sid,
                 need_cosign:$('#cd_need').is(':checked') ? 1 : 0,
                 dept_ids:JSON.stringify(cdSel.map(function(c){ return c.id; }))}, function(r){
        if (r.ok) { alert('已儲存'); loadCosDef(); }
    }, 'json');
});
function loadCosDef(){
    $.getJSON(API, {action:'cosign_defaults'}, function(r){
        if (!r.ok) return;
        var b = $('#cdBody').empty();
        var g = r.global || {};
        var names = function(ids){
            return (ids || []).map(function(x){
                var m = (META.cosign_depts || []).filter(function(t){ return +t.id === +x; })[0];
                return m ? m.name : ('#' + x);
            }).join('、');
        };
        b.append('<tr><td>全站預設</td><td>—</td><td>' + (parseInt(g.need_cosign) ? '是' : '否') + '</td><td class="l">'
               + esc(names(g.dept_ids) || '—') + '</td><td>—</td></tr>');
        (r.rows || []).forEach(function(d){
            var ids = String(d.dept_ids || '').split(',').filter(Boolean);
            b.append('<tr><td>' + (d.scope_type === 'dept' ? '部門' : '單一文件') + '</td>'
                + '<td class="l">' + esc(d.scope_name || ('#' + d.scope_id)) + '</td>'
                + '<td>' + (parseInt(d.need_cosign) ? '是' : '否') + '</td>'
                + '<td class="l">' + esc(names(ids) || '—') + '</td>'
                + '<td><span class="da-op" onclick="cdDelRow(' + d.def_id + ')">刪除</span></td></tr>');
        });
    });
}
function cdDelRow(id){
    if (!confirm('確定刪除這筆預設？')) return;
    $.post(API, {action:'delete_cosign_default', def_id:id}, function(r){ if (r.ok) loadCosDef(); }, 'json');
}

/* ══════════════════ 模組設定 ══════════════════ */
$('#btnSetting').on('click', function(){ openMask('setMask'); });
$('#btnBindDoc').on('click', function(){
    withDocs(function(docs){
        EGAsDoc.open({docs:docs, current:(META.asdoc ? +META.asdoc.id : 0), title:'AS 文件編號綁定',
            onSave:function(id, doc){
                $.post(API, {action:'save_asdoc_bind', doc_id:id}, function(r){
                    if (r.ok) { META.asdoc = id ? doc : null;
                        $('#setAsDocLabel').text(id ? (doc.doc_no + '　' + doc.doc_name) : '尚未綁定'); }
                }, 'json');
            }});
    });
});
$('#s_da_sign_approve, #s_da_sign_mgmt, #s_da_sign_sup, #s_da_sign_applicant, #s_da_stamp_tpl_id, #s_da_cosign_stamp_tpl_id, #s_da_dist_stamp_tpl_id')
.on('change', function(){
    var k = this.id.replace(/^s_/, '');
    $.post(API, {action:'save_setting', key:k, value:this.value}, function(r){
        if (r.ok) { META.settings[k] = $('#s_' + k).val(); }
    }, 'json');
});

/* ══════════════════ 列印（A4 直式 1:1） ══════════════════ */
$('#btnViewPrint').on('click', function(){ doPrint([curViewId]); });
$('#btnPrintSel').on('click', function(){
    var ids = selIds(); if (!ids.length) { alert('請先勾選要列印的申請單'); return; }
    printQueue(ids, 0);
});
function doPrint(ids){ printQueue(ids, 0); }
/* 批次列印：依序各自開視窗排隊（ai-rules/16 第三之五節），上一份關閉才開下一份 */
function printQueue(ids, i){
    if (i >= ids.length) { loadList(); return; }
    $.getJSON(API, {action:'print_meta', apply_id:ids[i]}, function(res){
        if (!res.ok) { printQueue(ids, i + 1); return; }
        window.__ownCompany = res.company || window.__ownCompany || '';
        var w = window.open('', '_blank');
        if (!w) { alert('請允許彈出視窗'); return; }
        w.document.write(printHtml(res));
        w.document.close();
        $.post(API, {action:'print_log', apply_id:ids[i]}, function(){}, 'json');
        var t = setInterval(function(){
            if (w.closed) { clearInterval(t); printQueue(ids, i + 1); }
        }, 700);
    });
}

function printHtml(res){
    var d = res.row, ok = (d.status === 'approved');
    var stampM = res.stamp_main, stampC = res.stamp_cosign, stampD = res.stamp_dist;
    var sg = function(slot){
        var nm = d['sign_' + slot + '_name'], dt = d['sign_' + slot + '_date'], dep = parseInt(d['sign_' + slot + '_dep']);
        return (ok && nm) ? stampHtml(nm, dispDate(dt), dep, stampM) : '';
    };
    var ymd = String(d.apply_date || '').split('-');
    var box = function(on){ return on ? '☑' : '☐'; };

    // 制修訂內容固定 4 列（不足補空白列，維持紙本版面）
    var chg = (d.changes || []).slice(0);
    while (chg.length < 4) chg.push({});
    var chgRows = chg.map(function(c){
        return '<tr><td class="c1">' + esc(c.page_no || '') + '</td><td class="c2">' + esc(c.item || '') + '</td>'
             + '<td class="c3">' + esc(c.before_txt || '') + '</td><td class="c4">' + esc(c.after_txt || '') + '</td></tr>';
    }).join('');

    // 會簽單位：紙本固定列出設定的單位（含未勾選者），勾選＝採用並簽
    var cosRows = (d.cosigns || []).map(function(c){
        var sig = (c.agree !== null && c.signer_name)
                ? stampHtml(c.signer_name, dispDate(c.signed_date), parseInt(c.is_delegated), stampC) : '';
        return '<tr><td class="k1">' + box(parseInt(c.is_checked)) + '　' + esc(c.dept_name) + '</td>'
             + '<td class="k2">' + sig + '</td>'
             + '<td class="k3">' + (+c.agree === 1 ? '✔' : '') + '</td>'
             + '<td class="k4">' + (c.agree !== null && +c.agree === 0 ? '✔' : '') + '</td>'
             + '<td class="k5">' + esc(c.opinion || '') + '</td></tr>';
    }).join('');
    var cosBody = cosRows || '<tr><td class="k1">（不需會簽）</td><td class="k2"></td><td class="k3"></td><td class="k4"></td><td class="k5"></td></tr>';

    // 核發／回收記錄固定 6 列
    var dist = (d.dists || []).slice(0);
    while (dist.length < 6) dist.push({});
    var distRows = dist.map(function(x){
        var rs = x.receiver_name ? stampHtml(x.receiver_name, dispDate(x.issue_date), 0, stampD) : '';
        var rc = x.recaller_name ? stampHtml(x.recaller_name, dispDate(x.recall_date), 0, stampD) : '';
        return '<tr><td>' + esc(x.dept_name || '') + '</td><td>' + esc(x.issue_qty || '') + '</td>'
             + '<td>' + esc(dispDate(x.issue_date)) + '</td><td class="sig">' + rs + '</td>'
             + '<td>' + esc(x.recall_qty || '') + '</td><td>' + esc(dispDate(x.recall_date)) + '</td>'
             + '<td class="sig">' + rc + '</td><td>' + esc(x.note || '') + '</td></tr>';
    }).join('');

    /* A4 直式 1:1：@page size A4 portrait margin 0，版面全部以 mm 定寸，
       瀏覽器不做縮放 → 圖章實際大小＝設計大小，不會失真（使用者明確要求的重點）。 */
    var css = '@page{size:A4 portrait;margin:0;}'
        + 'html,body{margin:0;padding:0;}'
        + 'body{width:210mm;font-family:"Microsoft JhengHei","微軟正黑體",sans-serif;color:#000;'
        +   'padding:8mm 8mm 6mm;box-sizing:border-box;-webkit-print-color-adjust:exact;print-color-adjust:exact;}'
        + '.hd{text-align:center;}'
        + '.hd .co{font-size:16pt;font-weight:bold;letter-spacing:2px;}'
        + '.hd .tt{font-size:15pt;font-weight:bold;letter-spacing:8px;margin-top:2mm;}'
        + '.ymd{text-align:right;font-size:10pt;margin:1mm 0 1.5mm;letter-spacing:1px;}'
        + 'table{border-collapse:collapse;width:100%;table-layout:fixed;}'
        + 'td,th{border:0.4mm solid #000;padding:0.8mm 1.2mm;font-size:9.5pt;vertical-align:middle;'
        +   'word-wrap:break-word;overflow-wrap:break-word;}'
        + '.lb{background:#F2F2F2;text-align:center;font-weight:bold;white-space:nowrap;overflow:hidden;}'
        /* 長標籤（首次發行日期／版本變更日期＝6 字）在 22mm 格內放不下 → 允許換行並縮字，'
           不可讓文字壓出格線（table-layout:fixed 下 nowrap 會直接溢出到隔壁欄） */
        + '.lbn{white-space:normal;word-break:keep-all;font-size:8pt;line-height:1.15;letter-spacing:0;padding:0.5mm 0.6mm;}'
        + '.h9{height:9mm;} .h7{height:7mm;}'
        + '.behalf{font-size:7.5pt;white-space:nowrap;}'   /* 申請人「（代理 ○○○）」不要撐爆 24mm 的欄寬 */
        + '.sec-t{border:0.4mm solid #000;border-bottom:none;background:#F2F2F2;font-size:9.5pt;'
        +   'font-weight:bold;padding:1mm 1.5mm;}'
        + '.chk{font-size:10pt;letter-spacing:1px;}'
        + '.note-row{border:0.4mm solid #000;border-top:none;padding:1mm 1.5mm;font-size:9.5pt;}'
        + 'table.chg td{height:9mm;text-align:left;}'
        + 'table.cos td{height:9mm;} .cos .k1{text-align:left;} .cos .k3,.cos .k4{text-align:center;} .cos .k5{text-align:left;}'
        + 'table.cos .k2{text-align:center;padding:0.5mm;}'
        /* 簽章格：min-height 讓圖章真實大小撐開，不縮放（ai-rules/18 第6條） */
        + 'table.sign td{height:26mm;text-align:center;vertical-align:middle;}'
        + 'table.sign td.lb{height:auto;}'
        + 'table.dist td{height:8mm;text-align:center;} table.dist td.sig{padding:0.5mm;}'
        + '.stamp-wrap{height:auto !important;display:inline-flex;align-items:center;}'
        + '.stamp-wrap svg,svg.car-stamp{width:76px;height:76px;-webkit-print-color-adjust:exact;print-color-adjust:exact;}'
        + 'table.dist .stamp-wrap svg,table.cos .stamp-wrap svg{width:56px;height:56px;}'
        + '.flow{font-size:8.5pt;margin-top:1.5mm;display:flex;}'
        + '.flow .no{margin-left:auto;font-weight:bold;}';

    var body = '<div class="hd"><div class="co">' + esc(res.company) + '</div>'
        + '<div class="tt">' + esc(res.doc_name || '文件制、修申請單') + '</div></div>'
        + '<div class="ymd">' + esc(ymd[0] || '　') + ' 年 ' + esc(ymd[1] || '　') + ' 月 ' + esc(ymd[2] || '　') + ' 日</div>'

        /* 表頭 */
        + '<table><colgroup><col style="width:22mm"><col style="width:54mm"><col style="width:22mm"><col style="width:54mm">'
        +   '<col style="width:18mm"><col style="width:24mm"></colgroup>'
        + '<tr class="h9"><td class="lb">文件狀況</td><td colspan="3" class="chk">'
        +   DA_STATUS_HTML(d.doc_status) + '</td>'
        +   '<td class="lb">文件類別</td><td class="chk" style="font-size:8.5pt;">' + esc(d.doc_type || '') + '</td></tr>'
        + '<tr class="h9"><td class="lb">文件名稱</td><td colspan="3">' + esc(d.doc_name || '') + '</td>'
        +   '<td class="lb">申請部門</td><td>' + esc(d.dept_name || '') + '</td></tr>'
        + '<tr class="h9"><td class="lb">文件編碼</td><td>' + esc(d.doc_no || '') + '</td>'
        +   '<td class="lb">版　本</td><td>' + esc(d.version || '') + '</td>'
        +   '<td class="lb">申 請 人</td><td>' + esc(d.applicant_name || '')
        +   (d.applicant_on_behalf_name ? '<span class="behalf">（代理 ' + esc(d.applicant_on_behalf_name) + '）</span>' : '')
        +   '</td></tr>'
        + '<tr class="h9"><td class="lb lbn">首次發行日期</td><td>' + esc(dispDate(d.first_issue_date)) + '</td>'
        +   '<td class="lb lbn">版本變更日期</td><td colspan="3">' + esc(dispDate(d.change_date || d.apply_date)) + '</td></tr>'
        + '</table>'

        /* 制修訂內容 */
        + '<div class="sec-t">制修訂內容：（頁次、項目、修訂前、修訂後）</div>'
        + '<table class="chg"><colgroup><col style="width:18mm"><col style="width:30mm"><col><col></colgroup>'
        + '<tr class="h7"><td class="lb">頁次</td><td class="lb">項目</td><td class="lb">修訂前</td><td class="lb">修訂後</td></tr>'
        + chgRows + '</table>'

        /* 勾選項＋是否需會簽 */
        + '<div class="note-row">' + box(parseInt(d.need_overview))
        +   ' 文制修申請核准通過需同時更改「文件管制總覽表」或「品質記錄一覽表」。</div>'
        + '<div class="note-row" style="border-top:none;">是否需會簽：'
        +   (parseInt(d.need_cosign) ? '☑ 是　☐ 否' : '☐ 是　☑ 否') + '</div>'

        /* 會簽單位 */
        + '<table class="cos"><colgroup><col style="width:34mm"><col style="width:26mm"><col style="width:14mm">'
        +   '<col style="width:16mm"><col></colgroup>'
        + '<tr class="h7"><td class="lb">會簽單位</td><td class="lb">簽名</td><td class="lb">同意</td>'
        +   '<td class="lb">不同意</td><td class="lb">會簽意見</td></tr>'
        + cosBody + '</table>'

        /* 四格簽章 */
        + '<table class="sign"><colgroup><col style="width:14mm"><col><col style="width:18mm"><col>'
        +   '<col style="width:18mm"><col><col style="width:16mm"><col></colgroup>'
        + '<tr><td class="lb">核准</td><td>' + sg('approve') + '</td>'
        +   '<td class="lb">管理代表</td><td>' + sg('mgmt') + '</td>'
        +   '<td class="lb">單位主管</td><td>' + sg('sup') + '</td>'
        +   '<td class="lb">申請人</td><td>' + sg('applicant') + '</td></tr></table>'

        /* 核發、回收記錄 */
        + '<div class="sec-t" style="border-top:none;">文件/核發、回收記錄欄</div>'
        + '<table class="dist"><colgroup><col style="width:26mm"><col style="width:14mm"><col style="width:22mm">'
        +   '<col style="width:22mm"><col style="width:14mm"><col style="width:22mm"><col style="width:22mm"><col></colgroup>'
        + '<tr class="h7"><td class="lb">部門</td><td class="lb">分發數</td><td class="lb">分發日期</td><td class="lb">簽收者</td>'
        +   '<td class="lb">回收數</td><td class="lb">回收日期</td><td class="lb">回收者</td><td class="lb">備註</td></tr>'
        + distRows + '</table>'

        + '<div class="flow"><span>表單流程：申請人(填表)→單位主管(審查)→管理代表(會簽單位及審查)→總經理(核准)→文管中心(發行、回收)</span>'
        +   '<span class="no">' + esc(res.doc_no || '') + '</span></div>';

    return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' + esc(res.doc_name || '文件制、修申請單') + '</title>'
         + '<style>' + css + '</style></head><body>' + body
         + '<scr' + 'ipt>window.onload=function(){setTimeout(function(){window.print();},250);};</scr' + 'ipt></body></html>';
}
/** 文件狀況的五個勾選格（依本單實際狀況打勾，選項來自後端不寫死） */
function DA_STATUS_HTML(cur){
    return (META.doc_status || []).map(function(s){
        return (s === cur ? '☑' : '☐') + s;
    }).join('　');
}

/* CSV 匯出：條件送給後端，由後端對「全部符合條件」的資料組檔（不可只用前端這一頁算） */
$('#btnCsv').on('click', function(){
    var q = $.param({action:'export_csv', kw:$('#fKw').val(), status:$('#fStatus').val(),
                     from:$('#fFrom').val(), to:$('#fTo').val()});
    window.location = API + '?' + q;
});

/* ══════════════════ 使用說明／回到頂端 ══════════════════ */
$('#btnPageHelp').on('click', function(){ openMask('helpUseMask'); });
$(window).on('scroll', function(){ $('#btnTop').toggle($(window).scrollTop() > 300); });
$('#btnTop').on('click', function(){ $('html,body').animate({scrollTop:0}, 200); });
</script>
</body>
</html>
