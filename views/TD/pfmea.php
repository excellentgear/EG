<?php
/**
 * PFMEA 潛在失效模式及效應分析（AS 3-TD-01-02）
 * 每個料號一份分析表，逐列記錄一個潛在失效模式；RPN=嚴重度(S)×發生度(O)×偵測度(D) 系統自動計算
 * 不給手填。嚴重度/發生度/偵測度分級對照表為固定顯示參考（下方 PFMEA_RATING_* 常數），非逐份填寫內容。
 * 資料/權限見 src/common/pfmea_lib.php；資料操作走 src/store/Pfmea_API.php。
 */
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/TD/pfmea.php";
    header("Location:../../index.php");
    exit;
}
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/asdoc_lib.php';
include_once '../../src/common/pfmea_lib.php';

$db = (new DBConnection())->getPDO();
pfmea_ensure_schema($db);
$pfUser = pfmea_current_user($db);
$perms = pfmea_perms($db, $pfUser);
$roleLabel = $perms['isAdmin'] ? '管理者' : ($perms['canAdmin'] ? 'PFMEA管理員' : ($perms['canEdit'] ? 'PFMEA登錄' : ($perms['canView'] ? 'PFMEA檢閱' : '無權限')));
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PFMEA潛在失效模式及效應分析</title>
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
        .pf-toolbar { display:flex; flex-wrap:wrap; gap:6px; align-items:center; clear:both;
            border:1.5px solid #E8D5B5; border-radius:8px; padding:8px 10px; margin-bottom:10px; background:#FDF8EF; }
        .pf-toolbar label { margin:0; font-size:13px; color:#5b3a1e; }
        .pf-toolbar input[type=text], .pf-toolbar button {
            height:30px; font-size:13px; line-height:1; padding:0 10px; border:1px solid #D8BE93;
            border-radius:4px; background:#fff; color:#5b3a1e; cursor:pointer; }
        .pf-toolbar button:hover { background:#F7E0BD; }
        .pf-toolbar .btn-warm { background:#F0A24B; color:#fff; border-color:#d98a33; }
        .pf-toolbar .btn-warm:hover { background:#d98a33; }
        .pf-role-badge { margin-left:auto; font-size:13px; color:#5b3a1e; background:#F7E0BD; border-radius:12px; padding:4px 12px; }
        .pf-role-badge .fa-question-circle { cursor:pointer; color:#b5762a; margin-left:5px; }
        .pf-table-wrap { overflow-x:auto; border:1px solid #E8D5B5; border-radius:6px; background:#fff; }
        table.pf-table { width:100%; border-collapse:collapse; font-size:13px; }
        table.pf-table th, table.pf-table td { border:1px solid #EADFC8; padding:5px 8px; text-align:center; }
        table.pf-table thead th { background:#F7E0BD; color:#5b3a1e; font-weight:bold; }
        table.pf-table tbody tr:nth-child(even) { background:#FBF6EC; }
        table.pf-table tbody tr:hover { background:#FBF0DD; }
        table.pf-table td.t-left { text-align:left; }
        .pf-op { color:#b5762a; cursor:pointer; margin:0 4px; }
        .pf-op:hover { color:#8A5A2B; text-decoration:underline; }
        .pf-mask { display:none; position:fixed; inset:0; background:rgba(60,40,20,.45); z-index:1050; }
        .pf-modal { background:#fff; border-radius:8px; max-width:960px; width:calc(100% - 40px); margin:36px auto;
            box-shadow:0 5px 25px rgba(0,0,0,.3); max-height:90vh; display:flex; flex-direction:column; }
        /* 跳窗寬度一律用固定像素上限（比照全站其他跳窗，如型態識別文件管制表 .ic-modal.xwide），
           不可用 vw 相對整個瀏覽器視窗寬度（會蓋過側邊選單、超出內頁實際可視寬度，已踩過一次坑）；
           卡片展開/收合改由 pf-card-grid 的 auto-fit/minmax 自動換行因應，跳窗本身寬度不隨之變動。 */
        .pf-modal.xwide { max-width:1080px; }
        .pf-modal .m-head { background:#F7E0BD; color:#5b3a1e; font-weight:bold; padding:10px 15px; border-radius:8px 8px 0 0;
            display:flex; justify-content:space-between; }
        .pf-modal .m-head .m-close { cursor:pointer; color:#b5762a; }
        .pf-modal .m-body { padding:15px; overflow-y:auto; }
        .pf-modal .m-body label { display:block; font-size:13px; color:#5b3a1e; margin:9px 0 3px; }
        .pf-modal .m-body input[type=text] { width:100%; border:1px solid #D8BE93; border-radius:4px;
            padding:5px 8px; font-size:13px; box-sizing:border-box; }
        .pf-modal .m-foot { padding:10px 15px; border-top:1px solid #EADFC8; text-align:right; }
        .pf-modal .m-foot button { height:30px; padding:0 16px; border-radius:4px; font-size:13px; border:1px solid #d98a33; cursor:pointer; }
        .pf-modal .m-foot .b-ok { background:#F0A24B; color:#fff; }
        .pf-modal .m-foot .b-cancel { background:#fff; color:#5b3a1e; border-color:#D8BE93; margin-right:6px; }
        .pf-head-grid { display:grid; grid-template-columns:1fr 1fr; gap:0 14px; }
        .pf-chk-row { display:flex; flex-wrap:wrap; gap:4px 16px; margin-top:4px; }
        .pf-chk { display:flex; align-items:center; gap:4px; font-size:12px; color:#5b3a1e; margin:0; cursor:pointer; white-space:nowrap; }
        .pf-suggest-table { width:100%; border-collapse:collapse; font-size:12px; }
        .pf-suggest-table th, .pf-suggest-table td { border:1px solid #EADFC8; padding:5px 8px; text-align:left; }
        .pf-suggest-table thead th { background:#F7E0BD; color:#5b3a1e; }
        .pf-proc-box { display:flex; gap:4px; }
        .pf-proc-box input { flex:1 1 auto; }
        .pf-proc-box button { flex:0 0 auto; white-space:nowrap; }
        table.pf-tpl-table { width:100%; border-collapse:collapse; font-size:12px; }
        table.pf-tpl-table th, table.pf-tpl-table td { border:1px solid #EADFC8; padding:5px 8px; text-align:left; }
        table.pf-tpl-table thead th { background:#F7E0BD; color:#5b3a1e; }
        .pf-rt-tabs { display:flex; gap:4px; border-bottom:2px solid #E8D5B5; margin-bottom:10px; }
        .pf-rt-tab { padding:7px 16px; font-size:13px; color:#8a6d45; cursor:pointer; border-radius:6px 6px 0 0; }
        .pf-rt-tab:hover { background:#FDF2E0; }
        .pf-rt-tab.active { background:#F0A24B; color:#fff; font-weight:bold; }
        table.pf-rt-table { width:100%; border-collapse:collapse; font-size:12px; }
        table.pf-rt-table th, table.pf-rt-table td { border:1px solid #EADFC8; padding:6px 8px; text-align:left; vertical-align:top; }
        table.pf-rt-table thead th { background:#F7E0BD; color:#5b3a1e; text-align:center; }
        table.pf-rt-table td.lv { text-align:center; font-weight:bold; color:#8A5A2B; white-space:nowrap; }
        .pf-rt-note { font-size:12px; color:#8a6d45; margin-top:8px; white-space:pre-line; background:#FFF7E8; border:1px dashed #F0A24B; border-radius:6px; padding:8px 10px; }
        .pf-rt-pane { display:none; }
        .pf-row-btn { border:1px solid #D8BE93; background:#fff; color:#5b3a1e; border-radius:4px; padding:2px 6px; font-size:11px; cursor:pointer; }
        .pf-row-btn:hover { background:#F7E0BD; }
        .pf-row-btn.del { color:#DD5138; border-color:#f0c4bd; }
        .pf-noperm { margin:40px auto; max-width:520px; text-align:center; border:1.5px solid #E8D5B5; border-radius:10px;
            padding:30px; background:#FDF8EF; color:#5b3a1e; }
        .pf-sec-title { font-size:14px; font-weight:bold; color:#8A5A2B; border-left:4px solid #F0A24B; padding-left:8px; margin:16px 0 6px; }
        .pf-sec-title.pf-collapsible { cursor:pointer; user-select:none; }
        .pf-sec-title.pf-collapsible:hover { color:#d98a33; }
        .pf-sec-title.pf-collapsible .fa { width:14px; }
        /* 固定評級對照表(固定顯示參考，非填寫內容) */
        table.pf-rating { border-collapse:collapse; font-size:11px; width:100%; margin-bottom:4px; }
        table.pf-rating th, table.pf-rating td { border:1px solid #EADFC8; padding:3px 6px; text-align:center; }
        table.pf-rating thead th { background:#F7E0BD; color:#5b3a1e; }
        table.pf-rating td.lv { font-weight:bold; color:#8A5A2B; white-space:nowrap; }
        .pf-rating-wrap { display:grid; grid-template-columns:1fr 1fr 1fr 1fr; gap:10px; margin-bottom:6px; }
        .pf-rpn-note { font-size:11px; color:#8a6d45; margin-top:4px; }
        /* 可增列分析表：改卡片式逐項顯示，畫面上不需要橫向捲動(列印仍走原橫式表格，見printDoc()) */
        .pf-card { border:1.5px solid #E8D5B5; border-radius:8px; background:#FDF8EF; padding:10px 12px; margin-bottom:12px; }
        .pf-card-hd { display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;
            padding-bottom:6px; border-bottom:1px dashed #E8D5B5; cursor:pointer; }
        .pf-card.collapsed .pf-card-hd { margin-bottom:0; padding-bottom:0; border-bottom:none; }
        .pf-card-hd b { color:#8A5A2B; font-size:13px; }
        .pf-card-hd .toggle-ic { color:#b5762a; margin-right:4px; width:12px; display:inline-block; }
        .pf-card-summary { color:#8a6d45; font-size:12px; font-weight:normal; margin-left:10px; }
        .pf-card:not(.collapsed) .pf-card-summary { display:none; }
        .pf-card.collapsed .pf-card-body { display:none; }
        .pf-card-grp-title { font-size:11px; font-weight:bold; color:#b5762a; margin:8px 0 4px; }
        .pf-card-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(150px, 1fr)); gap:6px 10px; }
        .pf-card-grid .f-sm { grid-column:span 1; }
        .pf-card-grid label { display:block; font-size:11px; color:#8a6d45; margin-bottom:2px; }
        .pf-card-grid input[type=text], .pf-card-grid input[type=date], .pf-card-grid input[type=number], .pf-card-grid textarea {
            width:100%; box-sizing:border-box; border:1px solid #D8BE93; border-radius:3px; padding:4px 6px; font-size:12px; background:#fff; }
        .pf-card-grid textarea { min-height:38px; resize:vertical; }
        .pf-card-grid input.rating { text-align:center; }
        .pf-card-grid input.rpn-out { text-align:center; background:#F7F2E6; font-weight:bold; color:#8A5A2B; }
        .pf-card-grid .rpn-hi { color:#DD5138 !important; border-color:#DD5138 !important; }
        .pf-rating-quad { display:grid; grid-template-columns:repeat(4,1fr); gap:6px 10px; }
        @media print { .pf-toolbar, .nav_menu, .left_col, footer { display:none !important; } .right_col { margin:0 !important; padding:0 !important; } }
    </style>
</head>
<body class="nav-sm">
<div class="container body">
<div class="main_container">
    <?php include '../partPage/sideAndTopBarMenu.html' ?>
    <div class="right_col" role="main">
        <div class="page-title" style="display:flex;align-items:center;flex-wrap:wrap;">
            <h2 style="margin:6px 0;">PFMEA潛在失效模式及效應分析
                <small style="color:#8a6d45;">AS文件編號：<span id="hdrAsDocNo">載入中…</span> ｜ 3-TD-01-02</small></h2>
            <button id="btnPageHelp" class="page-help-btn" style="margin-left:auto;"><i class="fa fa-question-circle"></i> 使用說明</button>
        </div>
        <div class="clearfix"></div>

<?php if (!$perms['canView']): ?>
        <div class="pf-noperm">
            <h4><i class="fa fa-lock"></i> 無PFMEA檢閱權限</h4>
            <p>請洽管理者於「使用者權限設定」指派「PFMEA檢閱／登錄／管理員」角色。</p>
        </div>
<?php else: ?>
        <div class="pf-toolbar">
            <label>搜尋</label>
            <input type="text" id="kwInput" placeholder="表單編號／料號" style="width:200px;">
            <button class="btn-warm" id="btnAdd" style="<?= $perms['canEdit']?'':'display:none;' ?>"><i class="fa fa-plus"></i> 新增</button>
            <button id="btnSuggest" style="<?= $perms['canEdit']?'':'display:none;' ?>" title="從已建立產品開發評估表(2-TD-02-01)、但還沒建立PFMEA的料號自動列出建議清單"><i class="fa fa-magic"></i> 建議建立清單</button>
            <button id="btnAsDoc" style="<?= $perms['canAdmin']?'':'display:none;' ?>"><i class="fa fa-link"></i> AS文件綁定</button>
            <button id="btnCsv"><i class="fa fa-file-text-o"></i> 匯出CSV</button>
            <span class="pf-role-badge">目前角色：<b><?= htmlspecialchars($roleLabel) ?></b>
                <i class="fa fa-question-circle" id="btnRoleHelp" title="角色權限說明"></i></span>
        </div>

        <div class="pf-table-wrap">
            <table class="pf-table" id="pfTable">
                <thead><tr>
                    <th>表單編號</th><th>料號</th><th>客戶</th><th>項目數</th><th>最高RPN</th>
                    <th>建立人</th><th>建立時間</th><th>操作</th>
                </tr></thead>
                <tbody id="pfBody"><tr><td colspan="8" style="padding:20px;color:#8a6d45;">載入中…</td></tr></tbody>
            </table>
        </div>
<?php endif; ?>
    </div>
</div>
</div>

<!-- 新增/編輯 -->
<div class="pf-mask" id="editMask"><div class="pf-modal">
    <div class="m-head"><span id="editTitle">PFMEA潛在失效模式及效應分析</span><span class="m-close" onclick="closeMask('editMask')">✕</span></div>
    <div class="m-body">
        <div class="pf-head-grid">
            <div>
                <label>料號</label>
                <input type="text" id="fPartNo" placeholder="輸入部分料號或圖號搜尋；查無時可直接手動輸入" autocomplete="off">
                <input type="hidden" id="fPartDId" value="0">
            </div>
            <div>
                <label>客戶名稱</label>
                <input type="text" id="fCustomerName" readonly data-eg-skip="1">
            </div>
        </div>
        <div class="pf-head-grid" style="margin-top:8px;">
            <div>
                <label>產品名稱</label>
                <input type="text" id="fProductName" placeholder="產品名稱">
            </div>
            <div>
                <label>規格描述</label>
                <input type="text" id="fSpecDesc" placeholder="規格描述">
            </div>
        </div>
        <label style="margin-top:8px;">分類</label>
        <div class="pf-chk-row">
            <label class="pf-chk"><input type="radio" name="fItemType" value="part" checked> 零件</label>
            <label class="pf-chk"><input type="radio" name="fItemType" value="assembly"> 組合件</label>
        </div>
        <label style="margin-top:8px;">相關部門</label>
        <div class="pf-chk-row" id="fDeptChecks"></div>

        <div style="margin-top:6px;font-size:12px;color:#8a6d45;">表單編號：<b id="fDocNo">存檔後自動產生</b>
            ｜ 建立：<span id="fCreatedInfo">—</span></div>

        <div class="pf-sec-title pf-collapsible" onclick="openRatingInfo()">
            <i class="fa fa-question-circle"></i> 評級對照表（固定參考，不隨本表個別修改；點擊標題查看完整說明文字）
        </div>
        <div class="pf-rating-wrap">
            <table class="pf-rating"><thead><tr><th colspan="2">嚴重度(S)</th></tr></thead><tbody>
                <tr><td class="lv">1</td><td>無影響</td></tr>
                <tr><td class="lv">2</td><td>次要阻礙</td></tr>
                <tr><td class="lv">3~6</td><td>中等阻礙</td></tr>
                <tr><td class="lv">7</td><td>顯著阻礙</td></tr>
                <tr><td class="lv">8</td><td>嚴重阻礙</td></tr>
                <tr><td class="lv">9~10</td><td>符合安全和/或法規要求之失效</td></tr>
            </tbody></table>
            <table class="pf-rating"><thead><tr><th colspan="2">發生率(O)</th></tr></thead><tbody>
                <tr><td class="lv">1</td><td>很低</td></tr>
                <tr><td class="lv">2~3</td><td>低</td></tr>
                <tr><td class="lv">4~6</td><td>中等</td></tr>
                <tr><td class="lv">7~9</td><td>高</td></tr>
                <tr><td class="lv">10</td><td>很高</td></tr>
            </tbody></table>
            <table class="pf-rating"><thead><tr><th colspan="2">偵測度(D)</th></tr></thead><tbody>
                <tr><td class="lv">1</td><td>幾乎確定</td></tr>
                <tr><td class="lv">2</td><td>極高</td></tr>
                <tr><td class="lv">3</td><td>高</td></tr>
                <tr><td class="lv">4</td><td>高中等</td></tr>
                <tr><td class="lv">5</td><td>中等</td></tr>
                <tr><td class="lv">6</td><td>低</td></tr>
                <tr><td class="lv">7</td><td>非常低</td></tr>
                <tr><td class="lv">8~9</td><td>可能性極小</td></tr>
                <tr><td class="lv">10</td><td>幾乎不可能</td></tr>
            </tbody></table>
            <table class="pf-rating"><thead><tr><th colspan="2">風險優先指數(RPN)</th></tr></thead><tbody>
                <tr><td class="lv">1~50</td><td>低</td></tr>
                <tr><td class="lv">51~100</td><td>普通</td></tr>
                <tr><td class="lv">101~200</td><td>高</td></tr>
                <tr><td class="lv">201~1000</td><td>非常高</td></tr>
            </tbody></table>
        </div>
        <div class="pf-rpn-note">風險優先指數 RPN = S × O × D（系統自動計算，不可手填），改善後RPN = 評價S × 評價O × 評價D，超過200需優先改善。</div>

        <datalist id="dl_process"></datalist>
        <div class="pf-sec-title">失效模式分析（逐項卡片，預設收合成一行；<b>點擊卡片標題展開才會顯示完整輸入欄位</b>，列印仍是您提供的橫式表格格式）</div>
        <div id="itemBody"></div>
        <div style="margin-top:6px;">
            <button type="button" class="pf-row-btn" onclick="pfAddRow()"><i class="fa fa-plus"></i> 新增一項失效模式分析</button>
        </div>
    </div>
    <div class="m-foot">
        <button class="b-cancel" onclick="closeMask('editMask')">取消</button>
        <button class="b-ok" id="btnSave" onclick="saveHeader()">儲存</button>
    </div>
</div></div>

<!-- 建議建立清單 -->
<div class="pf-mask" id="suggestMask"><div class="pf-modal">
    <div class="m-head"><span>建議建立清單</span><span class="m-close" onclick="closeMask('suggestMask')">✕</span></div>
    <div class="m-body">
        <div class="tip" style="background:#FFF7E8;border:1px dashed #F0A24B;border-radius:6px;padding:6px 10px;margin-bottom:10px;font-size:12px;color:#5b3a1e;">
            下方列出已建立「產品開發評估表(2-TD-02-01)」、但還沒建立 PFMEA 分析表的料號。勾選要建立的項目（表頭全選框可一次勾全部），按「建立勾選項目」即批次建立表頭殼（料號／客戶／產品名稱／分類自動帶入，分析項目仍需逐份手動填寫）。
        </div>
        <div id="suggestEmpty" style="color:#8a6d45;padding:10px;">載入中…</div>
        <table class="pf-suggest-table" id="suggestTable" style="display:none;">
            <thead><tr><th style="width:26px;"><input type="checkbox" id="suggestCkAll" data-eg-skip="1"></th><th>客戶</th><th>料號</th><th>產品名稱</th></tr></thead>
            <tbody id="suggestBody"></tbody>
        </table>
    </div>
    <div class="m-foot">
        <button class="b-cancel" onclick="closeMask('suggestMask')">取消</button>
        <button class="b-ok" id="btnSuggestCreate" onclick="createSuggested()">建立勾選項目</button>
    </div>
</div></div>

<!-- 整組列表（此製程代號的樣板套用） -->
<div class="pf-mask" id="templateMask" style="z-index:1200;"><div class="pf-modal">
    <div class="m-head"><span>此製程的整組樣板列表</span><span class="m-close" onclick="closeMask('templateMask')">✕</span></div>
    <div class="m-body">
        <div id="templateEmpty" style="color:#8a6d45;padding:10px;">載入中…</div>
        <table class="pf-tpl-table" id="templateTable" style="display:none;">
            <thead><tr><th>組名</th><th style="width:60px;">S</th><th style="width:60px;">O</th><th style="width:60px;">D</th><th style="width:70px;">RPN</th><th style="width:50px;"><?= $perms['canAdmin']?'刪除':'' ?></th></tr></thead>
            <tbody id="templateBody"></tbody>
        </table>
    </div>
    <div class="m-foot"><button class="b-cancel" onclick="closeMask('templateMask')">關閉</button></div>
</div></div>

<!-- 評級對照表說明（嚴重度/發生率/偵測度/R.P.N值 四分頁，內容比照 3-TD-01-02-潛在失效模式及效應分析.xlsm） -->
<div class="pf-mask" id="ratingInfoMask" style="z-index:1200;"><div class="pf-modal xwide">
    <div class="m-head"><span>評級對照表說明</span><span class="m-close" onclick="closeMask('ratingInfoMask')">✕</span></div>
    <div class="m-body">
        <div class="pf-rt-tabs">
            <div class="pf-rt-tab" data-tab="s" onclick="switchRatingTab('s')">嚴重度 (S)</div>
            <div class="pf-rt-tab" data-tab="o" onclick="switchRatingTab('o')">發生率 (O)</div>
            <div class="pf-rt-tab" data-tab="d" onclick="switchRatingTab('d')">偵測度 (D)</div>
            <div class="pf-rt-tab" data-tab="rpn" onclick="switchRatingTab('rpn')">R.P.N值</div>
        </div>
        <div class="pf-rt-pane" data-tab="s">
            <table class="pf-rt-table">
                <thead><tr><th style="width:50px;">等級</th><th>標準：製程之影響嚴重程度（製程/組裝影響）</th><th>標準：產品之影響嚴重程度（客戶影響）</th></tr></thead>
                <tbody>
                    <tr><td class="lv">10</td><td><b>符合安全和/或法規要求之失效</b><br>可能危害操作者(機構或組裝)而無預警</td><td><b>符合安全和/或法規要求之失效</b><br>潛在的失效模式影響到產品安全操作，及/或與政府法規不符，而無預警</td></tr>
                    <tr><td class="lv">9</td><td><b>符合安全和/或法規要求之失效</b><br>可能危害操作者(機構或組裝)而有預警</td><td><b>符合安全和/或法規要求之失效</b><br>潛在的失效模式影響到產品安全操作，及/或與政府法規不符，而有預警</td></tr>
                    <tr><td class="lv">8</td><td><b>嚴重阻礙</b><br>產品可能必須100%廢棄，生產線停止或出貨中止</td><td><b>主要功能</b><br>失去主要功能產品無法正常運轉作動</td></tr>
                    <tr><td class="lv">7</td><td><b>顯著阻礙</b><br>部分產品必須100%廢棄，變異來自於前製程，包括降低生產線流速或增加人力</td><td><b>失效或降低</b><br>降低主要功能(產品可運轉作動，但精度有所降低)</td></tr>
                    <tr><td class="lv">6</td><td><b>中等阻礙</b><br>100%生產中產品，必須線外重工和合格</td><td><b>次要功能</b><br>失去次要功能(產品可運轉作動，但產品無法乘載正常載重量)</td></tr>
                    <tr><td class="lv">5</td><td><b>中等阻礙</b><br>部分生產中產品，必須線外重工和合格</td><td><b>失效</b><br>降低次要功能(產品可運轉作動，但產品可乘載載重量降低)</td></tr>
                    <tr><td class="lv">4</td><td><b>中等阻礙</b><br>100%生產中產品，必須於生產工站重工，於投入生產前</td><td><b>使用者煩惱</b><br>外觀或可聽見噪音產品運轉作動大多數顧客會有感覺到不舒適(&gt;75%)</td></tr>
                    <tr><td class="lv">3</td><td><b>中等阻礙</b><br>部分生產中產品，必須於生產工站重工，於投入生產前</td><td><b>使用者煩惱</b><br>外觀或可聽見噪音，產品運轉作動多數顧客會有感覺到不舒適(50%)</td></tr>
                    <tr><td class="lv">2</td><td><b>次要阻礙</b><br>在製程，操作，或對操作者有輕微不方便</td><td><b>使用者煩惱</b><br>外觀或可聽見噪音，產品運轉作動顧客會有感覺到不舒適(&lt;25%)</td></tr>
                    <tr><td class="lv">1</td><td><b>無影響</b><br>無可辨別的影響</td><td><b>無影響</b><br>無可辨別的影響</td></tr>
                </tbody>
            </table>
        </div>
        <div class="pf-rt-pane" data-tab="o">
            <table class="pf-rt-table">
                <thead><tr><th style="width:50px;">等級</th><th>標準：發生原因-PFMEA</th><th style="width:120px;">可能失效</th></tr></thead>
                <tbody>
                    <tr><td class="lv">10</td><td>≧100/1,000或≧1/10</td><td>很高</td></tr>
                    <tr><td class="lv">9</td><td>50/1,000或1/20</td><td>高</td></tr>
                    <tr><td class="lv">8</td><td>20/1,000或1/50</td><td>高</td></tr>
                    <tr><td class="lv">7</td><td>10/1,000或1/100</td><td>高</td></tr>
                    <tr><td class="lv">6</td><td>2/1,000或1/500</td><td>中等</td></tr>
                    <tr><td class="lv">5</td><td>0.5/1,000或1/2,000</td><td>中等</td></tr>
                    <tr><td class="lv">4</td><td>0.1/1,000或1/10,000</td><td>中等</td></tr>
                    <tr><td class="lv">3</td><td>0.01/1,000或1/100,000</td><td>低</td></tr>
                    <tr><td class="lv">2</td><td>≦0.001/1,000或1/1,000,000</td><td>低</td></tr>
                    <tr><td class="lv">1</td><td>透過預防管制阻止失效</td><td>很低</td></tr>
                </tbody>
            </table>
        </div>
        <div class="pf-rt-pane" data-tab="d">
            <table class="pf-rt-table">
                <thead><tr><th style="width:50px;">等級</th><th style="width:100px;">偵測可能性</th><th style="width:140px;">偵測機會</th><th>標準：偵測性經由製程管制</th></tr></thead>
                <tbody>
                    <tr><td class="lv">10</td><td>幾乎不可能</td><td>無偵測機會</td><td>無現行製程管制；無法偵測或分析</td></tr>
                    <tr><td class="lv">9</td><td>可能性極小</td><td>任何階段不可偵測</td><td>失效模式和/或錯誤(原因)無法偵測(亦即：亂數稽核)</td></tr>
                    <tr><td class="lv">8</td><td>可能性極小</td><td>後製程偵測問題</td><td>後製程失效模式偵測，經由操作員之視覺/觸覺/聽覺的手段</td></tr>
                    <tr><td class="lv">7</td><td>非常低</td><td>問題偵查來源</td><td>製程工站失效模式偵測，經由操作員之視覺/觸覺/聽覺的手段，或後製程之計數值量測(Go/No-Go，扭力板手…等)</td></tr>
                    <tr><td class="lv">6</td><td>低</td><td>後製程問題偵測</td><td>後製程失效模式偵測，經由操作員之計量值量測，或製程之計數值量測(Go/No-Go，扭力板手…等)</td></tr>
                    <tr><td class="lv">5</td><td>中等</td><td>問題偵查來源</td><td>製程工站失效模式或錯誤(原因)偵測，經由操作員之計量值量測或製程工站自動控制，其將偵測異常零件和通知操作員(燈號，蜂鳴器…等)</td></tr>
                    <tr><td class="lv">4</td><td>高中等</td><td>後製程問題偵測</td><td>後製程失效模式偵測，經由自動控制，其將偵測異常零件和自動鎖定於製程工站，防止流入製程</td></tr>
                    <tr><td class="lv">3</td><td>高</td><td>問題偵查來源</td><td>製程工站失效模式偵測錯誤(原因)偵測，經由自動控制，其將偵測異常零件和自動鎖定於製程工站，防止流入製程</td></tr>
                    <tr><td class="lv">2</td><td>極高</td><td>錯誤偵測和/貨問題預防</td><td>製程工站錯誤(原因)偵測，經由自動控制，其將偵測錯誤和防止零件被生產</td></tr>
                    <tr><td class="lv">1</td><td>幾乎確定</td><td>不適用偵測；缺失預防</td><td>錯誤(原因)預防，經由治具設計，機械設計，零件設計。異常零件無法被生產，因為於產品/製程設計已設定防誤裝置</td></tr>
                </tbody>
            </table>
        </div>
        <div class="pf-rt-pane" data-tab="rpn">
            <table class="pf-rt-table">
                <thead><tr><th style="width:140px;">R.P.N值</th><th>說明</th></tr></thead>
                <tbody>
                    <tr><td class="lv">1≦RPN≦26</td><td>輕微的製造或商業風險，不需改善。</td></tr>
                    <tr><td class="lv">27≦RPN≦63</td><td>低度風險，由專案負責人自行判斷是否採取措施。</td></tr>
                    <tr><td class="lv">64≦RPN≦400</td><td>中度風險，須對設計做評價後，再作改善。</td></tr>
                    <tr><td class="lv">400≦RPN</td><td>高度風險，須對設計作重新變更。</td></tr>
                </tbody>
            </table>
            <div class="pf-rt-note">a. 400≦RPN時，須對設計作重新變更。
b. 當O(發生頻率指數)或D(探測度)大於7時，建議改善措施必須能降到3以下。
c. 當S(嚴重度指數)大於9時，O(發生頻率指數)和D(探測度)必須是2或是更低。
d. 當其中任何一項是大於9時，必須進行設計變更或是適當的處置，使其降為3以下。</div>
        </div>
    </div>
    <div class="m-foot"><button class="b-ok" onclick="closeMask('ratingInfoMask')">關閉</button></div>
</div></div>

<!-- AS 文件綁定 -->
<div class="pf-mask" id="asDocMask"><div class="pf-modal">
    <div class="m-head"><span>AS 文件編號綁定</span><span class="m-close" onclick="closeMask('asDocMask')">✕</span></div>
    <div class="m-body">
        <div style="margin-bottom:8px;">目前綁定：<b id="asDocLabel">尚未綁定</b></div>
        <button type="button" class="pf-row-btn" onclick="openAsDocPicker()">變更綁定</button>
    </div>
    <div class="m-foot"><button class="b-cancel" onclick="closeMask('asDocMask')">關閉</button></div>
</div></div>

<!-- 角色權限說明 -->
<div class="pf-mask" id="roleHelpMask"><div class="pf-modal">
    <div class="m-head"><span>角色權限說明</span><span class="m-close" onclick="closeMask('roleHelpMask')">✕</span></div>
    <div class="m-body" style="font-size:13px;color:#5b3a1e;line-height:1.8;">
        <b>PFMEA檢閱</b>：檢視清單、開啟查看、列印。<br>
        <b>PFMEA登錄</b>：檢閱＋新增/編輯分析列。<br>
        <b>PFMEA管理員</b>：登錄＋刪除、AS 文件編號綁定。<br>
        <b>管理者</b>：系統管理者固定擁有全部權限。<br>
        <hr style="border-color:#EADFC8;">
        角色指派請洽管理者於「使用者權限設定」（<a href="../user/user_permissions.php" target="_blank">開啟</a>）→「PFMEA潛在失效模式及效應分析」區塊。未被指派角色者無法進入本頁。
    </div>
</div></div>

<div class="pf-mask" id="helpUseMask"><div class="pf-modal xwide" style="max-width:820px;">
    <div class="m-head"><span><i class="fa fa-question-circle"></i> PFMEA潛在失效模式及效應分析 使用說明</span><span class="m-close" onclick="closeMask('helpUseMask')">✕</span></div>
    <div class="m-body help-doc">
        <h4>功能說明</h4>
        <p>製程潛在失效模式及效應分析（PFMEA，AS 3-TD-01-02），每個料號一份分析表。表頭欄位（料號／客戶名稱／產品名稱／規格描述／分類／相關部門）與分析表格欄位皆比照官方紙本表單(F-11210-UE2-0001)。逐列記錄一個潛在失效模式：從項目、功能、要求、潛在失效模式、失效模式潛在後果、分類、失效潛在原因，評出嚴重度(S)/發生率(O)，填現行設計管制（控制預防／控制偵測）、評出偵測度(D)，系統自動算出風險優先指數 RPN=S×O×D；針對高 RPN 項目填建議措施、責任者、目標完成日，改善後填採行措施、生效日期，再評一次新的 S/O/D/RPN。</p>
        <h4>操作步驟</h4>
        <ul>
            <li>按「新增」→ 選擇「料號」（打部分字元搜尋，查無此料號時可直接手動輸入；選定後客戶名稱與「分類」零件/組合件自動帶入，可手動修改）、填「產品名稱」「規格描述」、勾選「相關部門」。</li>
            <li>每個潛在失效模式是一張卡片，欄位由上到下分「基本資料／風險評估與現行設計管制／建議措施／措施結果」四區，不需要橫向捲動；S/O/D 每格填 1-10，<b>RPN 由系統自動計算，不可手動輸入</b>。按「新增一項失效模式分析」可再加一張卡片。</li>
            <li><b>製程代號</b>：卡片內輸入已建立的製程代號會自動帶出該製程的「潛在失效模式」下拉選項（也可直接手動輸入新值，儲存時會自動加進清單供下次選用）；輸入清單中沒有的新代號會詢問製程名稱並即時建立。「控制預防」「控制偵測」同樣是下拉可選/可手動輸入。按「整組列表」可叫出此製程所有樣板（組名＝製程名稱_項目名稱），點選後直接把該筆的基本資料/評級/控制/建議措施/評價欄位整批帶入，帶入後仍可個別修改。這些清單新增不限身分，僅管理員能刪除。</li>
            <li><b>建議建立清單</b>：工具列同名按鈕，自動列出已建立「產品開發評估表(2-TD-02-01)」、但還沒建立 PFMEA 的料號，勾選（可全選）後一次建立表頭殼（料號／客戶／產品名稱／分類自動帶入），分析項目仍需逐份手動填寫。</li>
        </ul>
        <h4>其他行為／常見疑問</h4>
        <ul>
            <li>「評級對照表」隨時可見一組精簡的嚴重度(S)／發生率(O)／偵測度(D)／風險優先指數(RPN)速查小表（比照官方表單版面）；點擊標題列另外開跳窗顯示完整官方說明文字，分四個分頁。兩者內容皆為固定參考，不隨每份分析表個別修改。</li>
            <li>料號可點擊開啟圖面查閱（比照報價單頁做法）。</li>
            <li>列印比照官方紙本表單版面（表頭資訊＋評級對照表＋相關部門置於上方，分析表格逐列對齊官方欄位順序與分組），同時比照全站列印標準（ai-rules/16）：大標題為本公司名稱、頁尾右下角印本頁綁定的 AS 文件編號。</li>
            <li><b>修訂履歷</b>：列印版右上角顯示本筆分析表自己的「編號／日期／修訂內容／準備」記錄（比照官方表單，取消批准/檢查欄位）。第一次存檔自動記1筆「新增文件」，準備人為當時建立者；之後修改存檔時會詢問是否要記為新版本，選是才會新增一列「修改文件」（準備人為當次修改者），選否代表只是小幅調整、不記版次，避免每次存檔都一直跳號。（此為本筆填寫紀錄自己的履歷，跟 AS 文件本身的版次管理是兩件事，AS 文件範本本身的改版仍由 AS 文件管理維護。）</li>
        </ul>
        <h4>設定入口</h4>
        <p>AS 文件編號綁定：工具列「AS文件綁定」按鈕（僅管理員可見）。角色指派：<a href="../user/user_permissions.php" target="_blank">使用者權限設定</a>頁→「PFMEA潛在失效模式及效應分析」區塊。</p>
        <h4>權限角色</h4>
        <p>PFMEA檢閱／登錄／管理員（管理者固定擁有全部權限）。</p>
    </div>
    <div class="m-foot"><button class="b-ok" onclick="closeMask('helpUseMask')">我知道了</button></div>
</div></div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/fastclick.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script src="../../resource/js/eg_date_fmt.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_date_fmt.js') ?>"></script>
<script src="../../resource/js/eg_part_picker.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_part_picker.js') ?>"></script>
<script src="../../resource/js/eg_asdoc_picker.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_asdoc_picker.js') ?>"></script>
<script src="../../resource/js/eg_input_rules.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_input_rules.js') ?>"></script>
<script>
$(document).ready(function(){ $('#sidebar-menu').css('visibility','visible'); });
var API = '../../src/store/Pfmea_API.php';
var PART_API = '../../src/store/PartPicker_API.php';
var VIEWER_URL = '../pm/bom_viewer.php';
var CAN_EDIT = <?= $perms['canEdit'] ? 'true' : 'false' ?>;
var CAN_ADMIN = <?= $perms['canAdmin'] ? 'true' : 'false' ?>;
var CUR_ID = 0, AS_DOCS = [], AS_DOC = null, SUGGEST_ROWS = [];
var RENDER_SEQ = 0;
var PROCESS_LIST = []; // [{id,process_code,process_name}] 製程代號主檔，跳窗開啟時載入一次
var CONTROL_OPTIONS = {prevention:[], detection:[]}; // 控制預防/控制偵測固定選項，跳窗開啟時載入一次
var PROCESS_ID_BY_CODE = {}; // process_code -> id 對照，製程代號連動查失效模式/整組樣板用
var TEMPLATE_ROWS = [], TEMPLATE_TARGET = null; // 整組列表跳窗暫存
/* 官方紙本表單(F-11210-UE2-0001)固定的相關部門勾選清單，跟Pfmea_API.php的PFMEA_DEPT_LIST同一份 */
var DEPT_LIST = ['管理課','技術課','業務組','品保組','倉管組','採購組','生管組','生產課'];
var FIELDS = ['process_code','process_desc','function_desc','requirement','failure_mode','failure_effect',
    'severity','classification','failure_cause','occurrence','prevention_controls','detection_controls','detection',
    'recommended_actions','target_date','action_date',
    'new_severity','new_occurrence','new_detection'];

function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];}); }
function closeMask(id){ document.getElementById(id).style.display='none'; }
function openMask(id){ document.getElementById(id).style.display='block'; }
function fmtDate(s){ return (window.egFmtDate ? egFmtDate(s) : (s||'')); }

/* ---------- 清單 ---------- */
function loadList(){
    $.getJSON(API, {action:'list', kw:$('#kwInput').val()||''}, function(res){
        if (!res.success){ $('#pfBody').html('<tr><td colspan="8" style="padding:20px;color:#DD5138;">'+esc(res.message||'載入失敗')+'</td></tr>'); return; }
        if (!res.rows.length){ $('#pfBody').html('<tr><td colspan="8" style="padding:20px;color:#8a6d45;">尚無資料</td></tr>'); return; }
        var html = '';
        res.rows.forEach(function(r){
            var rpnCls = (r.max_rpn && r.max_rpn > 200) ? ' style="color:#DD5138;font-weight:bold;"' : '';
            html += '<tr>'
                + '<td>'+esc(r.doc_no)+'</td>'
                + '<td class="t-left">'+(r.part_no?EGPartPicker.viewerLink(r.part_no, VIEWER_URL):'')+'</td>'
                + '<td class="t-left">'+esc(r.customer_name||'')+'</td>'
                + '<td>'+esc(r.item_count)+'</td>'
                + '<td'+rpnCls+'>'+(r.max_rpn!=null?r.max_rpn:'—')+'</td>'
                + '<td>'+esc(r.created_by_name||'')+'</td>'
                + '<td>'+fmtDate((r.created_at||'').substring(0,10))+'</td>'
                + '<td>'
                + '<span class="pf-op" title="'+(CAN_EDIT?'編輯':'檢視')+'" onclick="openEdit('+r.id+')"><i class="fa fa-'+(CAN_EDIT?'pencil':'eye')+'"></i></span>'
                + '<span class="pf-op" title="列印" onclick="printDoc('+r.id+')"><i class="fa fa-print"></i></span>'
                + (CAN_ADMIN ? '<span class="pf-op" title="刪除" onclick="delDoc('+r.id+')"><i class="fa fa-trash"></i></span>' : '')
                + '</td></tr>';
        });
        $('#pfBody').html(html);
    });
}
var kwT=null;
$('#kwInput').on('input', function(){ clearTimeout(kwT); kwT=setTimeout(loadList, 300); });
$('#btnCsv').on('click', function(){
    $.getJSON(API, {action:'list', kw:$('#kwInput').val()||''}, function(res){
        if (!res.success) return;
        var lines = ['表單編號,料號,客戶,項目數,最高RPN,建立人,建立時間'];
        res.rows.forEach(function(r){
            lines.push([r.doc_no, r.part_no||'', r.customer_name||'', r.item_count, r.max_rpn!=null?r.max_rpn:'', r.created_by_name||'', (r.created_at||'').substring(0,10)]
                .map(function(v){ return '"'+String(v).replace(/"/g,'""')+'"'; }).join(','));
        });
        var blob = new Blob(["\uFEFF"+lines.join("\n")], {type:'text/csv;charset=utf-8;'});
        var a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = 'PFMEA潛在失效模式及效應分析.csv';
        document.body.appendChild(a); a.click(); document.body.removeChild(a);
    });
});

/* ---------- 新增/編輯 ---------- */
/**
 * 卡片預設收合成一行摘要（項次＋失效模式＋RPN），避免欄位一次全部攤開遮蔽畫面；
 * 點擊卡片標題才展開該卡片的完整欄位。跳窗本身寬度固定（見 .pf-modal，禁用 vw 相對視窗寬度，
 * 已踩過跳窗超出內頁寬度的坑），展開/收合只影響卡片內容高度，不會讓跳窗跟著變寬——
 * 卡片欄位格線 .pf-card-grid 用 auto-fit/minmax 在固定寬度內自動換行因應。
 * 新增的卡片（使用者當下要輸入）預設直接展開；既有資料載入時預設全部收合。
 */
function cardSummaryText(it){
    var rpn = it.rpn != null ? it.rpn : '';
    return '失效模式：' + esc(it.failure_mode || '（尚未填寫）') + (rpn !== '' ? '　RPN：' + rpn : '');
}
function itemCardHtml(it, idx, expanded){
    it = it || {};
    expanded = !!expanded;
    function fld(field, label, type){
        var v = it[field] != null ? it[field] : '';
        var dis = CAN_EDIT ? '' : ' disabled';
        var ctrl;
        if (type === 'rating') ctrl = '<input type="number" min="1" max="10" class="rating sod-in" data-f="'+field+'" value="'+esc(v)+'"'+dis+'>';
        else if (type === 'date') ctrl = '<input type="date" data-f="'+field+'" value="'+esc(v)+'"'+dis+'>';
        else if (type && type.indexOf('list:') === 0) {
            var dlId = 'dl_'+field+'_'+(++RENDER_SEQ);
            ctrl = '<input type="text" data-f="'+field+'" value="'+esc(v)+'" list="'+dlId+'"'+dis+'><datalist id="'+dlId+'" class="dl-'+type.substring(5)+'"></datalist>';
        }
        else ctrl = '<textarea data-f="'+field+'"'+dis+'>'+esc(v)+'</textarea>';
        return '<div><label>'+label+'</label>'+ctrl+'</div>';
    }
    var rpn = it.rpn != null ? it.rpn : '';
    var newRpn = it.new_rpn != null ? it.new_rpn : '';
    var rpnCls = (it.rpn != null && it.rpn > 200) ? ' rpn-hi' : '';
    var newRpnCls = (it.new_rpn != null && it.new_rpn > 200) ? ' rpn-hi' : '';
    var procCode = it.process_code != null ? it.process_code : '';
    var dis = CAN_EDIT ? '' : ' disabled';
    return '<div class="pf-card'+(expanded?'':' collapsed')+'" data-id="'+esc(it.id||0)+'">'
        + '<div class="pf-card-hd" onclick="toggleCard(this)">'
        + '<span><i class="fa fa-chevron-'+(expanded?'down':'right')+' toggle-ic"></i><b>項次 <span class="seq">'+(idx+1)+'</span></b>'
        + '<span class="pf-card-summary">'+cardSummaryText(it)+'</span></span>'
        + '<button type="button" class="pf-row-btn del" onclick="event.stopPropagation();removeCard(this)"><i class="fa fa-trash"></i> 刪除此項</button>'
        + '</div>'
        + '<div class="pf-card-body">'
        + '<div class="pf-card-grp-title">基本資料</div>'
        + '<div class="pf-card-grid">'
        + '<div><label>製程代號</label><div class="pf-proc-box">'
        + '<input type="text" class="f-proccode" data-f="process_code" value="'+esc(procCode)+'" list="dl_process" placeholder="輸入製程代號"'+dis+'>'
        + '<button type="button" class="pf-row-btn" onclick="openTemplatePicker(this)" title="此製程的整組樣板列表"'+dis+'><i class="fa fa-list"></i> 整組列表</button>'
        + '</div></div>'
        + fld('process_desc','項目') + fld('function_desc','功能') + fld('requirement','要求')
        + fld('failure_mode','潛在失效模式','list:failure_mode') + fld('failure_effect','失效模式潛在後果') + fld('classification','分類')
        + '</div>'
        + '<div class="pf-card-grp-title">風險評估與現行設計管制（RPN 系統自動計算）</div>'
        + '<div class="pf-rating-quad">'
        + fld('severity','嚴重度 S','rating') + fld('failure_cause','失效潛在原因')
        + fld('occurrence','發生率 O','rating') + fld('prevention_controls','控制預防','list:prevention')
        + fld('detection_controls','控制偵測','list:detection') + fld('detection','偵測度 D','rating')
        + '<div><label>RPN</label><input type="text" class="rpn-out'+rpnCls+'" data-rpn value="'+rpn+'" readonly></div>'
        + '</div>'
        + '<div class="pf-card-grp-title">建議措施</div>'
        + '<div class="pf-card-grid">'
        + fld('recommended_actions','建議措施') + fld('target_date','目標完成日','date')
        + '</div>'
        + '<div class="pf-card-grp-title">措施結果</div>'
        + '<div class="pf-card-grid">'
        + fld('action_date','生效日期','date')
        + '</div>'
        + '<div class="pf-rating-quad">'
        + fld('new_severity','評價 S','rating') + fld('new_occurrence','評價 O','rating') + fld('new_detection','評價 D','rating')
        + '<div><label>改善後RPN</label><input type="text" class="rpn-out'+newRpnCls+'" data-new-rpn value="'+newRpn+'" readonly></div>'
        + '</div>'
        + '</div>'
        + '</div>';
}
function renderItems(items){
    if (items && items.length) {
        var html = '';
        items.forEach(function(it, idx){ html += itemCardHtml(it, idx, false); });
        $('#itemBody').html(html);
        refreshAllCardDatalists();
    } else {
        $('#itemBody').html('');
        pfAddRow();
    }
}
/* 每張卡片的控制預防/控制偵測下拉一律可直接填；有預帶製程代號的卡片(既有資料載入時)要順便帶出
   該製程的潛在失效模式下拉，不必使用者手動再觸發一次 change 事件 */
function refreshAllCardDatalists(){
    $('#itemBody .pf-card').each(function(){
        var $card = $(this);
        populateCardControlDatalists($card);
        var code = $card.find('.f-proccode').val().trim();
        if (code && PROCESS_ID_BY_CODE[code]) loadFailureModesForCard($card, PROCESS_ID_BY_CODE[code].id);
    });
}
function renumberRows(){ $('#itemBody .pf-card').each(function(i){ $(this).find('.seq').text(i+1); }); }
window.openRatingInfo = function(){ openMask('ratingInfoMask'); switchRatingTab('s'); };
window.switchRatingTab = function(tab){
    $('#ratingInfoMask .pf-rt-tab').removeClass('active');
    $('#ratingInfoMask .pf-rt-tab[data-tab="'+tab+'"]').addClass('active');
    $('#ratingInfoMask .pf-rt-pane').hide();
    $('#ratingInfoMask .pf-rt-pane[data-tab="'+tab+'"]').show();
};
window.toggleCard = function(hdEl){
    var $card = $(hdEl).closest('.pf-card');
    var willCollapse = !$card.hasClass('collapsed');
    if (willCollapse) {
        var it = {failure_mode: $card.find('[data-f="failure_mode"]').val(), rpn: $card.find('[data-rpn]').val() || null};
        $card.find('.pf-card-summary').html(cardSummaryText(it));
    }
    $card.toggleClass('collapsed', willCollapse);
    $card.find('.toggle-ic').attr('class', 'fa fa-chevron-'+(willCollapse?'right':'down')+' toggle-ic');
};
window.pfAddRow = function(){
    $('#itemBody').append(itemCardHtml({}, $('#itemBody .pf-card').length, true));
    renumberRows();
    populateCardControlDatalists($('#itemBody .pf-card').last());
    return true;
};
window.pfDelRow = function(){
    var cards = $('#itemBody .pf-card');
    if (cards.length <= 1) return false;
    cards.last().remove();
    renumberRows();
    return true;
};
window.removeCard = function(btn){
    if ($('#itemBody .pf-card').length <= 1){ alert('至少要保留一項'); return; }
    $(btn).closest('.pf-card').remove();
    renumberRows();
};
/* RPN 即時重算(僅顯示用，實際以送出後後端重算為準) */
$(document).on('input', '#itemBody .sod-in', function(){
    var $card = $(this).closest('.pf-card');
    var s = parseInt($card.find('[data-f="severity"]').val(), 10);
    var o = parseInt($card.find('[data-f="occurrence"]').val(), 10);
    var d = parseInt($card.find('[data-f="detection"]').val(), 10);
    var rpn = (s && o && d) ? s*o*d : '';
    $card.find('[data-rpn]').val(rpn).toggleClass('rpn-hi', rpn !== '' && rpn > 200);
    var ns = parseInt($card.find('[data-f="new_severity"]').val(), 10);
    var no = parseInt($card.find('[data-f="new_occurrence"]').val(), 10);
    var nd = parseInt($card.find('[data-f="new_detection"]').val(), 10);
    var newRpn = (ns && no && nd) ? ns*no*nd : '';
    $card.find('[data-new-rpn]').val(newRpn).toggleClass('rpn-hi', newRpn !== '' && newRpn > 200);
});

function collectItems(){
    var out = [];
    $('#itemBody .pf-card').each(function(){
        var $card = $(this);
        var row = {id: parseInt($card.attr('data-id'),10) || 0};
        FIELDS.forEach(function(f){ row[f] = $card.find('[data-f="'+f+'"]').val(); });
        out.push(row);
    });
    return out;
}

/* ---------- 製程代號連動下拉／整組樣板套用（2026-08-13 使用者要求）----------
 * 製程代號、控制預防、控制偵測都用 <input list=datalist> 實作：既能從清單挑選，也能直接手動輸入
 * 新值（清單本身可填表人就能新增，僅管理員能刪除，設定入口見下方 loadRefLists 載入的清單資料）。
 */
function fillDatalist(dl, items, valueFn, labelFn){
    dl.innerHTML = items.map(function(it){
        var v = esc(valueFn(it));
        var l = labelFn ? ' label="'+esc(labelFn(it))+'"' : '';
        return '<option value="'+v+'"'+l+'>';
    }).join('');
}
function loadProcessList(cb){
    $.getJSON(API, {action:'ref_process_list'}, function(res){
        if (!res.success) return;
        PROCESS_LIST = res.rows || [];
        PROCESS_ID_BY_CODE = {};
        PROCESS_LIST.forEach(function(p){ PROCESS_ID_BY_CODE[p.process_code] = p; });
        fillDatalist(document.getElementById('dl_process'), PROCESS_LIST, function(p){ return p.process_code; }, function(p){ return p.process_name; });
        if (cb) cb();
    });
}
function loadControlOptions(cb){
    $.getJSON(API, {action:'ref_control_options'}, function(res){
        if (!res.success) return;
        CONTROL_OPTIONS = res.options || {prevention:[], detection:[]};
        $('#itemBody .pf-card').each(function(){ populateCardControlDatalists($(this)); });
        if (cb) cb();
    });
}
function populateCardControlDatalists($card){
    $card.find('datalist.dl-prevention').each(function(){ fillDatalist(this, CONTROL_OPTIONS.prevention, function(o){ return o.option_text; }); });
    $card.find('datalist.dl-detection').each(function(){ fillDatalist(this, CONTROL_OPTIONS.detection, function(o){ return o.option_text; }); });
}
/* 製程代號欄位變更：解析出對應製程(代號已存在於PROCESS_ID_BY_CODE直接用；輸入的是新代號則問一次
   製程名稱、呼叫ref_process_add即時註冊——可填表人就能新增，不必等管理員先設定好)，
   並帶出該製程的潛在失效模式下拉選項供「潛在失效模式」欄位挑選/自行輸入 */
function loadFailureModesForCard($card, pid){
    var $dl = $card.find('datalist.dl-failure_mode');
    $.getJSON(API, {action:'ref_failure_mode_list', process_id:pid}, function(res){
        if (!res.success) return;
        $dl.each(function(){ fillDatalist(this, res.rows, function(r){ return r.failure_mode; }); });
    });
}
$(document).on('change', '#itemBody .f-proccode', function(){
    var $input = $(this), $card = $input.closest('.pf-card');
    var code = $input.val().trim();
    if (!code){ $card.find('datalist.dl-failure_mode').each(function(){ this.innerHTML=''; }); return; }
    if (PROCESS_ID_BY_CODE[code]){ loadFailureModesForCard($card, PROCESS_ID_BY_CODE[code].id); return; }
    if (!CAN_EDIT) return;
    var name = window.prompt('製程代號「'+code+'」尚未建立，請輸入製程名稱以新增：', '');
    if (!name){ $input.val(''); return; }
    $.post(API, {action:'ref_process_add', process_code:code, process_name:name}, function(res){
        if (!res.success){ alert(res.message||'新增製程失敗'); $input.val(''); return; }
        loadProcessList(function(){ loadFailureModesForCard($card, res.id); });
    }, 'json');
});

window.openTemplatePicker = function(btn){
    var $card = $(btn).closest('.pf-card');
    var code = $card.find('.f-proccode').val().trim();
    if (!code || !PROCESS_ID_BY_CODE[code]){ alert('請先輸入已建立的製程代號'); return; }
    TEMPLATE_TARGET = $card;
    $('#templateEmpty').show().text('載入中…'); $('#templateTable').hide();
    openMask('templateMask');
    $.getJSON(API, {action:'ref_item_templates', process_id:PROCESS_ID_BY_CODE[code].id}, function(res){
        if (!res.success || !res.rows.length){ $('#templateEmpty').text('此製程尚無整組樣板資料'); return; }
        TEMPLATE_ROWS = res.rows;
        $('#templateEmpty').hide(); $('#templateTable').show();
        var html = '';
        TEMPLATE_ROWS.forEach(function(t, i){
            var rpn = (t.severity&&t.occurrence&&t.detection) ? (t.severity*t.occurrence*t.detection) : '';
            html += '<tr><td class="pf-op" onclick="applyTemplate('+i+')" style="cursor:pointer;color:#b5762a;text-decoration:underline;">'+esc(t.group_name)+'</td>'
                + '<td>'+esc(t.severity)+'</td><td>'+esc(t.occurrence)+'</td><td>'+esc(t.detection)+'</td><td>'+rpn+'</td>'
                + '<td>'+(CAN_ADMIN?'<i class="fa fa-trash pf-op" title="刪除此樣板" onclick="event.stopPropagation();deleteTemplate('+t.id+')"></i>':'')+'</td></tr>';
        });
        $('#templateBody').html(html);
    });
};
window.applyTemplate = function(i){
    var t = TEMPLATE_ROWS[i], $card = TEMPLATE_TARGET;
    if (!t || !$card) return;
    var map = {process_desc:t.item_name, failure_mode:t.failure_mode, function_desc:t.function_desc, failure_effect:t.failure_effect,
        severity:t.severity, failure_cause:t.failure_cause, occurrence:t.occurrence, prevention_controls:t.prevention_controls,
        detection_controls:t.detection_controls, detection:t.detection, recommended_actions:t.recommended_actions,
        new_severity:t.new_severity, new_occurrence:t.new_occurrence, new_detection:t.new_detection};
    Object.keys(map).forEach(function(f){
        if (map[f] == null) return;
        $card.find('[data-f="'+f+'"]').val(map[f]).trigger('input');
    });
    var it = {failure_mode: $card.find('[data-f="failure_mode"]').val(), rpn: $card.find('[data-rpn]').val() || null};
    $card.find('.pf-card-summary').html(cardSummaryText(it));
    closeMask('templateMask');
};
window.deleteTemplate = function(id){
    if (!confirm('確定刪除此整組樣板？(僅管理員可操作，刪除後不影響已套用過的資料)')) return;
    $.post(API, {action:'ref_item_template_delete', id:id}, function(res){
        if (!res.success){ alert(res.message||'刪除失敗'); return; }
        TEMPLATE_ROWS = TEMPLATE_ROWS.filter(function(t){ return t.id !== id; });
        $('#templateBody tr').filter(function(){ return $(this).find('[onclick*="deleteTemplate('+id+')"]').length>0; }).remove();
    }, 'json');
};

function deptChecksHtml(checked){
    checked = checked || [];
    return DEPT_LIST.map(function(d){
        return '<label class="pf-chk"><input type="checkbox" class="dept-ck" value="'+esc(d)+'"'+(checked.indexOf(d)>=0?' checked':'')+'> '+esc(d)+'</label>';
    }).join('');
}
function resetEditForm(){
    CUR_ID = 0;
    $('#fPartNo').val(''); $('#fPartDId').val('0'); $('#fCustomerName').val('');
    $('#fProductName').val(''); $('#fSpecDesc').val('');
    $('input[name=fItemType][value=part]').prop('checked', true);
    $('#fDeptChecks').html(deptChecksHtml());
    $('#fDocNo').text('存檔後自動產生'); $('#fCreatedInfo').text('—');
    renderItems([]);
}
function openEdit(id){
    resetEditForm();
    $('#editTitle').text(id ? '編輯PFMEA分析表' : '新增PFMEA分析表');
    if (!id){ openMask('editMask'); return; }
    $.getJSON(API, {action:'get', id:id}, function(res){
        if (!res.success){ alert(res.message||'載入失敗'); return; }
        CUR_ID = id;
        $('#fPartNo').val(res.doc.part_no||''); $('#fPartDId').val(res.doc.part_d_id||0);
        $('#fCustomerName').val(res.doc.customer_name||'');
        $('#fProductName').val(res.doc.product_name||''); $('#fSpecDesc').val(res.doc.spec_desc||'');
        $('input[name=fItemType][value='+(res.doc.item_type==='assembly'?'assembly':'part')+']').prop('checked', true);
        $('#fDeptChecks').html(deptChecksHtml((res.doc.related_depts||'').split(',').filter(Boolean)));
        $('#fDocNo').text(res.doc.doc_no);
        $('#fCreatedInfo').text((res.doc.created_by_name||'')+' '+fmtDate((res.doc.created_at||'').substring(0,10)));
        renderItems(res.items || []);
        openMask('editMask');
    });
}
$('#btnAdd').on('click', function(){ openEdit(0); });

EGPartPicker.attach(document.getElementById('fPartNo'), {
    apiUrl: PART_API,
    onSelect: function(row){
        $('#fPartDId').val(row.d_id);
        $('#fCustomerName').val(row.customer_name||'');
        $('input[name=fItemType][value='+((row.is_assembly=='1'||row.is_assembly===1)?'assembly':'part')+']').prop('checked', true);
    }
});
$('#fPartNo').on('input', function(){ $('#fPartDId').val('0'); $('#fCustomerName').val(''); });

/* 存檔前，把卡片上手動輸入、不在目前下拉清單裡的失效模式/控制預防/控制偵測新值註冊進參考清單，
   下次同製程就能直接挑選（可填表人就能新增，僅管理員能刪除——見 pfmea_reference_lib.php） */
function registerNewRefValues(){
    $('#itemBody .pf-card').each(function(){
        var $card = $(this);
        var code = $card.find('.f-proccode').val().trim();
        var pid = code && PROCESS_ID_BY_CODE[code] ? PROCESS_ID_BY_CODE[code].id : 0;
        if (pid) {
            var fm = $card.find('[data-f="failure_mode"]').val().trim();
            var known = $card.find('datalist.dl-failure_mode option').map(function(){ return this.value; }).get();
            if (fm && known.indexOf(fm) < 0) $.post(API, {action:'ref_failure_mode_add', process_id:pid, failure_mode:fm});
        }
        ['prevention_controls','detection_controls'].forEach(function(f){
            var type = f === 'prevention_controls' ? 'prevention' : 'detection';
            var v = $card.find('[data-f="'+f+'"]').val().trim();
            var known2 = (CONTROL_OPTIONS[type]||[]).map(function(o){ return o.option_text; });
            if (v && known2.indexOf(v) < 0) $.post(API, {action:'ref_control_option_add', option_type:type, option_text:v});
        });
    });
}
function saveHeader(){
    // 既有文件修改存檔時，問使用者是否要記為新版本（修訂履歷才新增一列，避免小幅調整就一直跳版次；
    // 新建文件不用問，第一次存檔一律自動記1筆「新增文件」，見 Pfmea_API.php save 動作）
    var newRevision = 0;
    if (CUR_ID) newRevision = confirm('是否要將此次修改記錄為新版本？\n（是＝修訂履歷新增一列「修改文件」；否＝僅存檔不加版次記錄，適用小幅調整）') ? 1 : 0;
    var payload = {
        action: 'save', id: CUR_ID,
        part_d_id: $('#fPartDId').val() || 0,
        part_no_text: (($('#fPartDId').val()|0) ? '' : $('#fPartNo').val()),
        item_type: $('input[name=fItemType]:checked').val() || 'part',
        product_name: $('#fProductName').val(),
        spec_desc: $('#fSpecDesc').val(),
        related_depts: JSON.stringify($('#fDeptChecks .dept-ck:checked').map(function(){ return $(this).val(); }).get()),
        items: JSON.stringify(collectItems()),
        new_revision: newRevision,
    };
    if (CAN_EDIT) registerNewRefValues();
    $.post(API, payload, function(res){
        if (!res.success){ alert(res.message||'儲存失敗'); return; }
        closeMask('editMask'); loadList();
    }, 'json');
}

function delDoc(id){
    if (!confirm('確定刪除此筆PFMEA分析表？')) return;
    $.post(API, {action:'delete_header', id:id}, function(res){
        if (!res.success){ alert(res.message||'刪除失敗'); return; }
        loadList();
    }, 'json');
}

/* ---------- 建議建立清單（來源：已有產品開發評估表、還沒有PFMEA的料號） ---------- */
$('#btnSuggest').on('click', function(){
    $('#suggestEmpty').show().text('載入中…'); $('#suggestTable').hide();
    openMask('suggestMask');
    $.getJSON(API, {action:'suggest_list'}, function(res){
        if (!res.success){ $('#suggestEmpty').text(res.message||'載入失敗'); return; }
        SUGGEST_ROWS = res.rows || [];
        if (!SUGGEST_ROWS.length){ $('#suggestEmpty').text('目前沒有候選料號——已建立產品開發評估表的料號都已有對應的PFMEA分析表。'); return; }
        $('#suggestEmpty').hide(); $('#suggestTable').show();
        var html = '';
        SUGGEST_ROWS.forEach(function(r, i){
            html += '<tr><td><input type="checkbox" class="suggest-ck" data-idx="'+i+'"></td>'
                + '<td>'+esc(r.customer_name||'')+'</td>'
                + '<td class="t-left">'+esc(r.part_no_text||'')+'</td>'
                + '<td>'+esc(r.product_name||'')+'</td></tr>';
        });
        $('#suggestBody').html(html);
        $('#suggestCkAll').prop('checked', false);
    });
});
$('#suggestCkAll').on('change', function(){ $('#suggestBody .suggest-ck').prop('checked', this.checked); });
function createSuggested(){
    var idxs = $('#suggestBody .suggest-ck:checked').map(function(){ return parseInt($(this).data('idx'),10); }).get();
    if (!idxs.length){ alert('請先勾選要建立的料號'); return; }
    var rows = idxs.map(function(i){ return SUGGEST_ROWS[i]; });
    if (!confirm('確定要建立勾選的 '+rows.length+' 筆PFMEA分析表表頭嗎？（僅建立表頭，分析項目仍需逐份手動填寫）')) return;
    $.post(API, {action:'suggest_bulk_create', rows: JSON.stringify(rows)}, function(res){
        if (!res.success){ alert(res.message||'建立失敗'); return; }
        var msg = '已建立 '+res.created+' 筆。';
        if (res.errors && res.errors.length) msg += '\n以下未建立：\n' + res.errors.join('\n');
        alert(msg);
        closeMask('suggestMask');
        loadList();
    }, 'json');
}

/* ---------- 列印（比照官方紙本表單 F-11210-UE2-0001 版面：左側基本資料＋右側S/O/D/RPN評級表＋
   相關部門勾選，下方為橫式分析表格，欄位順序與分組皆與官方表單一致；表單自身版次履歷由AS文件
   管理維護，本頁不印） ---------- */
var ITEM_TYPE_LABEL = {part:'零件', assembly:'組合件'};
function printDoc(id){
    $.getJSON(API, {action:'print_get', id:id}, function(res){
        if (!res.success){ alert(res.message||'載入失敗'); return; }
        var d = res.doc;
        var rows = '';
        (res.items||[]).forEach(function(it, i){
            rows += '<tr><td>'+(i+1)+'</td><td class="tl">'+esc(it.process_desc)+'</td><td class="tl">'+esc(it.function_desc)+'</td>'
                + '<td class="tl">'+esc(it.requirement)+'</td>'
                + '<td class="tl">'+esc(it.failure_mode)+'</td><td class="tl">'+esc(it.failure_effect)+'</td>'
                + '<td>'+esc(it.severity)+'</td><td class="tl">'+esc(it.classification)+'</td>'
                + '<td class="tl">'+esc(it.failure_cause)+'</td><td>'+esc(it.occurrence)+'</td>'
                + '<td class="tl">'+esc(it.prevention_controls)+'</td><td class="tl">'+esc(it.detection_controls)+'</td>'
                + '<td>'+esc(it.detection)+'</td><td>'+esc(it.rpn)+'</td>'
                + '<td class="tl">'+esc(it.recommended_actions)+'</td><td>'+fmtDate(it.target_date)+'</td>'
                + '<td>'+fmtDate(it.action_date)+'</td>'
                + '<td>'+esc(it.new_severity)+'</td><td>'+esc(it.new_occurrence)+'</td><td>'+esc(it.new_detection)+'</td><td>'+esc(it.new_rpn)+'</td></tr>';
        });
        var depts = (d.related_depts||'').split(',').filter(Boolean);
        var deptsHtml = DEPT_LIST.map(function(dp){ return '<label>'+(depts.indexOf(dp)>=0?'&#9632;':'&#9633;')+' '+esc(dp)+'</label>'; }).join('');
        var revRows = '';
        (res.revisions||[]).forEach(function(r){
            revRows += '<tr><td>'+esc(r.rev_no)+'</td><td>'+fmtDate(r.rev_date)+'</td><td>'+esc(r.rev_content)+'</td><td>'+esc(r.prepared_by_name||'')+'</td></tr>';
        });
        var body = '<div class="p-topwrap">'
            + '<div class="p-comp-wrap"><div class="p-comp">'+esc(res.company_name)+'</div><div class="p-title">'+esc(res.as_doc_name)+'</div></div>'
            + '<table class="p-rev"><thead><tr><th>編號</th><th>日期</th><th>修訂內容</th><th>準備</th></tr></thead><tbody>'+revRows+'</tbody></table>'
            + '</div>'
            + '<div class="p-hdwrap">'
            + '<table class="p-info">'
            + '<tr><td>料號</td><td>'+esc(d.part_no||'')+'</td></tr>'
            + '<tr><td>分類</td><td>'+esc(ITEM_TYPE_LABEL[d.item_type]||'零件')+'</td></tr>'
            + '<tr><td>規格描述</td><td>'+esc(d.spec_desc||'')+'</td></tr>'
            + '<tr><td>產品名稱</td><td>'+esc(d.product_name||'')+'</td></tr>'
            + '<tr><td>客戶名稱</td><td>'+esc(d.customer_name||'')+'</td></tr>'
            + '</table>'
            + '<table class="p-rate"><thead><tr><th colspan="2">嚴重度(S)</th></tr></thead><tbody>'
            + '<tr><td>1</td><td>無影響</td></tr><tr><td>2</td><td>次要阻礙</td></tr><tr><td>3~6</td><td>中等阻礙</td></tr>'
            + '<tr><td>7</td><td>顯著阻礙</td></tr><tr><td>8</td><td>嚴重阻礙</td></tr><tr><td>9~10</td><td>安全/法規失效</td></tr>'
            + '</tbody></table>'
            + '<table class="p-rate"><thead><tr><th colspan="2">發生率(O)</th></tr></thead><tbody>'
            + '<tr><td>1</td><td>很低</td></tr><tr><td>2~3</td><td>低</td></tr><tr><td>4~6</td><td>中等</td></tr>'
            + '<tr><td>7~9</td><td>高</td></tr><tr><td>10</td><td>很高</td></tr>'
            + '</tbody></table>'
            + '<table class="p-rate"><thead><tr><th colspan="2">偵測度(D)</th></tr></thead><tbody>'
            + '<tr><td>1</td><td>幾乎確定</td></tr><tr><td>2</td><td>極高</td></tr><tr><td>3</td><td>高</td></tr>'
            + '<tr><td>4</td><td>高中等</td></tr><tr><td>5</td><td>中等</td></tr><tr><td>6</td><td>低</td></tr>'
            + '<tr><td>7</td><td>非常低</td></tr><tr><td>8~9</td><td>可能性極小</td></tr><tr><td>10</td><td>幾乎不可能</td></tr>'
            + '</tbody></table>'
            + '<table class="p-rate"><thead><tr><th colspan="2">風險優先指數(RPN)</th></tr></thead><tbody>'
            + '<tr><td>1~50</td><td>低</td></tr><tr><td>51~100</td><td>普通</td></tr>'
            + '<tr><td>101~200</td><td>高</td></tr><tr><td>201~1000</td><td>非常高</td></tr>'
            + '</tbody></table>'
            + '<div class="p-depts"><span class="dh">相關部門</span>'+deptsHtml+'</div>'
            + '</div>'
            + '<table class="p-tb"><thead>'
            + '<tr><th rowspan="2">NO</th><th rowspan="2">項目</th><th rowspan="2">功能</th><th rowspan="2">要求</th>'
            + '<th rowspan="2">潛在失效模式</th><th rowspan="2">失效模式潛在後果</th><th rowspan="2">嚴重度S</th>'
            + '<th rowspan="2">分類</th><th rowspan="2">失效潛在原因</th><th rowspan="2">發生率O</th>'
            + '<th colspan="2">現行設計管制</th><th rowspan="2">偵測度D</th><th rowspan="2">RPN</th>'
            + '<th rowspan="2">建議措施</th><th rowspan="2">目標完成日</th>'
            + '<th colspan="5">措施結果</th></tr>'
            + '<tr><th>控制預防</th><th>控制偵測</th><th>生效日期</th><th>S</th><th>O</th><th>D</th><th>RPN</th></tr>'
            + '</thead><tbody>'+rows+'</tbody></table>';
        var css = 'body{font-family:"Microsoft JhengHei",sans-serif;margin:0;padding:0 6mm;color:#222;-webkit-print-color-adjust:exact;print-color-adjust:exact;}'
            + '.p-topwrap{display:flex;gap:6px;align-items:flex-start;margin-bottom:2px;}'
            + '.p-comp-wrap{flex:1 1 auto;}'
            + '.p-comp{font-size:20px;font-weight:bold;text-align:center;margin-bottom:1px;}'
            + '.p-title{font-size:15px;font-weight:bold;text-align:center;letter-spacing:3px;margin-bottom:6px;}'
            + 'table.p-rev{flex:0 0 auto;border-collapse:collapse;font-size:7.5px;align-self:flex-start;}'
            + 'table.p-rev th,table.p-rev td{border:1px solid #666;padding:1px 4px;text-align:center;white-space:nowrap;}'
            + 'table.p-rev thead th{background:#f3ead6;}'
            + '.p-hdwrap{display:flex;gap:4px;align-items:stretch;margin-bottom:6px;}'
            + 'table.p-info{border-collapse:collapse;font-size:9px;flex:0 0 200px;}'
            + 'table.p-info td{border:1px solid #666;padding:2px 4px;overflow-wrap:anywhere;}'
            + 'table.p-info td:first-child{background:#f3ead6;font-weight:bold;white-space:nowrap;width:56px;}'
            + 'table.p-rate{border-collapse:collapse;font-size:7.5px;flex:0 0 auto;}'
            + 'table.p-rate th,table.p-rate td{border:1px solid #666;padding:1px 3px;text-align:center;white-space:nowrap;}'
            + 'table.p-rate thead th{background:#f3ead6;}'
            + '.p-depts{flex:1 1 auto;font-size:8.5px;border:1px solid #666;padding:2px 5px;display:grid;grid-template-columns:1fr 1fr;gap:0 6px;align-content:start;}'
            + '.p-depts .dh{grid-column:1/-1;font-weight:bold;background:#f3ead6;padding:1px 3px;margin:-2px -5px 2px;}'
            + '.p-depts label{white-space:nowrap;}'
            + 'table.p-tb{width:100%;table-layout:fixed;border-collapse:collapse;font-size:8px;}'
            + 'table.p-tb thead{display:table-header-group;}'
            + 'table.p-tb th,table.p-tb td{border:1px solid #666;padding:2px 3px;text-align:center;overflow-wrap:anywhere;}'
            + 'table.p-tb thead th{background:#f3ead6;} table.p-tb td.tl{text-align:left;}'
            + 'table.p-tb tr{break-inside:avoid;}'
            + '@page{margin:10mm 8mm 16mm;size:A4 landscape;'
            + (res.as_doc_no ? " @bottom-right{ content:'"+String(res.as_doc_no).replace(/['\\]/g,'')+"'; font-size:9pt; color:#333; vertical-align:top; padding-top:1mm; }" : '')
            + '}';
        var w = window.open('', '_blank');
        w.document.write('<html><head><meta charset="utf-8"><title>PFMEA潛在失效模式及效應分析</title><style>'+css+'</style></head><body>'+body
            +'<scr'+'ipt>window.onload=function(){'
            +'var st=document.createElement(\'style\');'
            +'st.textContent="@page{ @bottom-left{ content:\'第 \' counter(page) \' 頁／共 \' counter(pages) \' 頁\'; font-size:9pt; color:#333; vertical-align:top; padding-top:1mm; } }";'
            +'document.head.appendChild(st);'
            +'setTimeout(function(){window.print();},200);};</scr'+'ipt></body></html>');
        w.document.close();
    });
}

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
        docs: AS_DOCS, current: AS_DOC ? AS_DOC.id : 0, title: 'PFMEA AS 文件綁定',
        onSave: function(id){
            $.post(API, {action:'as_doc_save', doc_id:id}, function(res){
                if (!res.success){ alert(res.message||'儲存失敗'); return; }
                AS_DOC = res.as_doc; renderAsDocLabel();
            }, 'json');
        }
    });
}

$('#btnPageHelp').on('click', function(){ openMask('helpUseMask'); });
$('#btnRoleHelp').on('click', function(){ openMask('roleHelpMask'); });
$('.pf-mask').on('click', function(e){ if (e.target === this) this.style.display='none'; });

<?php if ($perms['canView']): ?>
loadList();
loadAsDocCurrent();
loadProcessList();
loadControlOptions();
<?php endif; ?>
</script>
</body>
</html>
