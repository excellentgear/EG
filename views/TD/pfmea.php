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
include_once '../../src/common/td_dev_eval_lib.php';

$db = (new DBConnection())->getPDO();
pfmea_ensure_schema($db);
$pfUser = pfmea_current_user($db);
$perms = pfmea_perms($db, $pfUser);
$defaultProductName = td_dev_eval_default_product_name_get($db);
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
        .rs-list { max-height:150px; overflow-y:auto; border:1px solid #EADFC8; border-radius:4px; margin-bottom:4px; }
        .rs-row { display:flex; justify-content:space-between; align-items:center; padding:3px 8px; font-size:12px; border-bottom:1px solid #F3EAD6; cursor:pointer; color:#5b3a1e; }
        .rs-row:last-child { border-bottom:none; }
        .rs-row:hover { background:#FFF7E8; }
        .rs-row.active { background:#F7E0BD; font-weight:bold; }
        .rs-row .fa-trash { color:#DD5138; cursor:pointer; }
        .rs-empty { padding:6px 8px; font-size:12px; color:#8a6d45; }
        .pf-sym-row { display:flex; flex-wrap:wrap; gap:3px; margin:3px 0 6px; }
        .pf-sym-row button { width:28px; height:26px; border:1px solid #D8BE93; border-radius:3px; background:#fff; cursor:pointer; font-size:13px; color:#5b3a1e; padding:0; }
        .pf-sym-row button:hover { background:#FFF7E8; border-color:#F0A24B; }
        .rs-cat-block { border-bottom:1px solid #F3EAD6; padding:4px 0; }
        .rs-cat-block:last-child { border-bottom:none; }
        .rs-cat-hd { display:block; font-size:12.5px; color:#5b3a1e; padding:2px 4px; cursor:pointer; }
        .rs-proc-ck-row { display:block; font-size:12px; color:#5b3a1e; padding:2px 4px 2px 22px; cursor:pointer; }
        .rs-proc-ck-row:hover, .rs-cat-hd:hover { background:#FFF7E8; }
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
            <button id="btnRefSettings" style="<?= $perms['canAdmin']?'':'display:none;' ?>"><i class="fa fa-cogs"></i> 參考資料設定</button>
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
        <div style="display:flex;gap:16px;align-items:flex-start;">
        <div style="flex:1;min-width:0;">
        <div class="pf-head-grid">
            <div>
                <label>料號</label>
                <div class="pf-proc-box">
                    <input type="text" id="fPartNo" placeholder="輸入部分料號或圖號搜尋；查無時可直接手動輸入" autocomplete="off">
                    <button type="button" id="btnOpenDrawing" onclick="openPartDrawing()" style="display:none;height:30px;padding:0 8px;border-radius:4px;border:1px solid #D8BE93;background:#fff;color:#b5762a;cursor:pointer;" title="開新視窗看圖面填寫參考"><i class="fa fa-image"></i> 開圖</button>
                </div>
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
                <input type="text" id="fProductName" placeholder="產品名稱（綁定料號時自動帶入建議值，可自行修改）">
            </div>
            <div>
                <label>規格描述</label>
                <input type="text" id="fSpecDesc" list="dl_specDesc" placeholder="規格描述（綁定料號時自動偵測齒輪規格，可自行修改）"><datalist id="dl_specDesc"></datalist>
            </div>
        </div>
        <div class="pf-head-grid" style="margin-top:8px;">
            <div>
                <label>業務日期<span style="font-weight:normal;color:#8a6d45;">（目標完成日/生效日期預設帶此日期）</span></label>
                <input type="date" id="fBizDate">
            </div>
            <div>
                <label>快速套用參考日期</label>
                <div class="pf-chk-row" id="fBizDateQuick" style="margin-top:6px;"></div>
            </div>
        </div>
        <label style="margin-top:8px;">分類</label>
        <div class="pf-chk-row">
            <label class="pf-chk"><input type="radio" name="fItemType" value="part" checked> 零件</label>
            <label class="pf-chk"><input type="radio" name="fItemType" value="assembly"> 組合件</label>
        </div>
        <label style="margin-top:8px;">相關部門
            <span id="btnDeptDefaultSave" class="pf-op" style="font-weight:normal;color:#b5762a;text-decoration:underline;cursor:pointer;display:none;margin-left:8px;" onclick="saveDeptDefaults()">設為預設勾選值(管理員)</span>
        </label>
        <div class="pf-chk-row" id="fDeptChecks"></div>
        </div>
        <div id="fOrderProcPanel" style="display:none;width:230px;flex:0 0 230px;background:#FFF7E8;border:1px solid #EADFC8;border-radius:6px;padding:8px 10px;font-size:12px;color:#5b3a1e;max-height:320px;overflow-y:auto;">
            <b>此料號訂單/報價製程履歷</b>
            <div id="fOrderProcList" style="margin-top:6px;"></div>
        </div>
        </div>

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

        <div id="procSearchDD" style="display:none;position:fixed;z-index:2000;background:#fff;border:1px solid #D8BE93;border-radius:4px;box-shadow:0 4px 14px rgba(0,0,0,.18);max-height:260px;overflow-y:auto;"></div>
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

<!-- 建議措施樣板挑選（可複選，套用時自動加編號，2026-08-14使用者要求） -->
<div class="pf-mask" id="actionPickerMask" style="z-index:1200;"><div class="pf-modal">
    <div class="m-head"><span>選擇建議措施樣板（可複選）</span><span class="m-close" onclick="closeMask('actionPickerMask')">✕</span></div>
    <div class="m-body">
        <div id="actionPickerList" style="max-height:320px;overflow-y:auto;"></div>
    </div>
    <div class="m-foot">
        <button class="b-cancel" onclick="closeMask('actionPickerMask')">取消</button>
        <button class="b-ok" onclick="applyActionPicker()">套用（附加編號）</button>
    </div>
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

<!-- 檢視（唯讀，第8段使用者要求）：版面跟列印完全一樣塞進iframe顯示，另提供評級對照表說明/開圖 -->
<div class="pf-mask" id="viewMask"><div class="pf-modal xwide" style="max-width:1200px;">
    <div class="m-head"><span>檢視PFMEA分析表（唯讀）</span><span class="m-close" onclick="closeMask('viewMask')">✕</span></div>
    <div class="m-body" style="padding:0;">
        <div style="padding:8px 15px;border-bottom:1px solid #EADFC8;">
            <button type="button" class="pf-row-btn" onclick="openRatingInfo()"><i class="fa fa-question-circle"></i> 評級對照表說明</button>
            <button type="button" class="pf-row-btn" id="btnViewDrawing" onclick="openViewDrawing()" style="margin-left:6px;"><i class="fa fa-image"></i> 開圖</button>
        </div>
        <iframe id="viewFrame" style="width:100%;height:70vh;border:0;"></iframe>
    </div>
    <div class="m-foot"><button class="b-cancel" onclick="closeMask('viewMask')">關閉</button></div>
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

<!-- 參考資料設定（2026-08-14使用者要求：找不到能個別設定各欄下拉選單/階層的地方）僅管理員 -->
<div class="pf-mask" id="refSettingsMask"><div class="pf-modal xwide" style="max-width:1100px;">
    <div class="m-head"><span>參考資料設定（僅管理員）</span><span class="m-close" onclick="closeMask('refSettingsMask')">✕</span></div>
    <div class="m-body">
        <div class="pf-rt-tabs">
            <div class="pf-rt-tab" data-rstab="proc" onclick="switchRsTab('proc')">製程與階層</div>
            <div class="pf-rt-tab" data-rstab="tpl" onclick="switchRsTab('tpl')">整組樣板</div>
            <div class="pf-rt-tab" data-rstab="link" onclick="switchRsTab('link')">欄位個別設定對應</div>
        </div>

        <div class="pf-rt-pane" data-rstab="proc">
        <div class="pf-sec-title">製程開放使用設定
            <span class="pf-op" onclick="rsSyncProcesses()" style="font-weight:normal;color:#b5762a;text-decoration:underline;cursor:pointer;margin-left:10px;"><i class="fa fa-refresh"></i> 從全站製程主檔同步</span>
            <span class="pf-op" onclick="rsEnableConfigured()" style="font-weight:normal;color:#b5762a;text-decoration:underline;cursor:pointer;margin-left:10px;"><i class="fa fa-check-square-o"></i> 一鍵開放已設定製程</span>
        </div>
        <div style="font-size:12px;color:#8a6d45;margin-bottom:4px;">勾選大項分類＝該分類底下製程全選/取消全選；仍可個別勾選調整。只有勾選開放的製程會出現在分析表的製程代號下拉。</div>
        <div id="rsProcessEnableList" style="max-height:220px;overflow-y:auto;border:1px solid #EADFC8;border-radius:4px;padding:6px 8px;margin-bottom:10px;"></div>

        <div class="pf-sec-title">選擇製程以設定項目／功能／潛在失效模式／要求（僅列出已開放使用的製程）</div>
        <div class="rs-list" id="rsProcessList"></div>
        <div class="pf-proc-box"><input type="text" id="rsProcCodeNew" placeholder="製程代號" style="flex:0 0 100px;"><input type="text" id="rsProcNameNew" placeholder="製程名稱（手動新增，非主檔製程）"><button type="button" class="pf-row-btn" onclick="rsAddProcess()">新增</button></div>

        <div id="rsProcessScope" style="display:none;">
            <div style="color:#8a6d45;font-size:12px;margin:10px 0 6px;padding-top:10px;border-top:1px dashed #EADFC8;">目前選擇製程：<b id="rsCurProcessLabel"></b></div>
            <div class="pf-sec-title">項目（此製程底下）</div>
            <div class="rs-list" id="rsItemList"></div>
            <div class="pf-proc-box"><input type="text" id="rsItemNew" placeholder="項目名稱"><button type="button" class="pf-row-btn" onclick="rsAddItem()">新增</button></div>

            <div id="rsItemScope" style="display:none;">
                <div style="color:#8a6d45;font-size:12px;margin:10px 0 6px;">目前選擇項目：<b id="rsCurItemLabel"></b></div>
                <div class="pf-sec-title">功能（此項目底下）</div>
                <div class="rs-list" id="rsFuncList"></div>
                <div class="pf-proc-box"><input type="text" id="rsFuncNew" placeholder="功能"><button type="button" class="pf-row-btn" onclick="rsAddFunc()">新增</button></div>
            </div>

            <div class="pf-sec-title" style="margin-top:10px;">潛在失效模式（<span id="rsFmScopeLabel">此製程通用</span>）</div>
            <div class="rs-list" id="rsFmList"></div>
            <div class="pf-proc-box"><input type="text" id="rsFmNew" placeholder="潛在失效模式"><button type="button" class="pf-row-btn" onclick="rsAddFm()">新增</button></div>

            <div class="pf-sec-title" style="margin-top:10px;">要求（<span id="rsReqScopeLabel">此製程通用</span>）</div>
            <div class="rs-list" id="rsReqList"></div>
            <div class="pf-proc-box"><input type="text" id="rsReqNew" placeholder="要求文字（此處只新增通用值；料號專屬要求填分析表時自動建立，僅能在此刪除）"><button type="button" class="pf-row-btn" onclick="rsAddReq()">新增</button></div>
        </div>

        <div class="pf-sec-title" style="margin-top:16px;">控制預防／控制偵測／建議措施（全域通用，不分製程）</div>
        <div style="display:flex;gap:16px;">
            <div style="flex:1;"><b style="font-size:12px;color:#5b3a1e;">控制預防</b>
                <div class="rs-list" id="rsPrevList"></div>
                <div class="pf-proc-box"><input type="text" id="rsPrevNew" placeholder="新增控制預防"><button type="button" class="pf-row-btn" onclick="rsAddControl('prevention')">新增</button></div></div>
            <div style="flex:1;"><b style="font-size:12px;color:#5b3a1e;">控制偵測</b>
                <div class="rs-list" id="rsDetList"></div>
                <div class="pf-proc-box"><input type="text" id="rsDetNew" placeholder="新增控制偵測"><button type="button" class="pf-row-btn" onclick="rsAddControl('detection')">新增</button></div></div>
            <div style="flex:1;"><b style="font-size:12px;color:#5b3a1e;">建議措施（樣板句庫，卡片內可多選組成整段建議措施）</b>
                <div class="rs-list" id="rsActionList"></div>
                <div class="pf-proc-box"><input type="text" id="rsActionNew" placeholder="新增建議措施句子"><button type="button" class="pf-row-btn" onclick="rsAddControl('action')">新增</button></div></div>
        </div>
        </div>

        <div class="pf-rt-pane" data-rstab="tpl">
        <div style="font-size:12px;color:#8a6d45;margin-bottom:6px;">整組樣板：套用時一次把項目/功能/潛在失效模式/失效模式潛在後果/評級/控制/建議措施/評價欄位整批帶入分析表卡片。可直接在此新增/編輯/刪除，不必再靠xlsm匯入。</div>
        <label style="font-size:13px;color:#5b3a1e;">選擇製程</label>
        <select id="rsTplProcSel" style="width:260px;border:1px solid #D8BE93;border-radius:4px;padding:5px 8px;font-size:13px;" data-eg-filter="輸入製程代號或名稱篩選…"></select>
        <button type="button" class="pf-row-btn" style="margin-left:8px;" onclick="rsOpenTplForm(0)"><i class="fa fa-plus"></i> 新增樣板</button>
        <table class="pf-tpl-table" id="rsTplTable" style="margin-top:10px;">
            <thead><tr><th>組名</th><th style="width:50px;">S</th><th style="width:50px;">O</th><th style="width:50px;">D</th><th style="width:60px;">RPN</th><th style="width:80px;">操作</th></tr></thead>
            <tbody id="rsTplBody"></tbody>
        </table>

        <div id="rsTplForm" style="display:none;margin-top:14px;padding-top:10px;border-top:1px dashed #EADFC8;">
            <input type="hidden" id="rsTplId" value="0">
            <div class="pf-card-grid">
                <div><label>項目</label><input type="text" id="rsTplItemName"></div>
                <div><label>功能</label><input type="text" id="rsTplFunctionDesc"></div>
                <div><label>潛在失效模式</label><input type="text" id="rsTplFailureMode"></div>
                <div><label>失效模式潛在後果</label><input type="text" id="rsTplFailureEffect"></div>
                <div><label>嚴重度 S</label><input type="number" min="1" max="10" id="rsTplSeverity"></div>
                <div><label>失效潛在原因</label><input type="text" id="rsTplFailureCause"></div>
                <div><label>發生率 O</label><input type="number" min="1" max="10" id="rsTplOccurrence"></div>
                <div><label>控制預防</label><input type="text" id="rsTplPrevention"></div>
                <div><label>控制偵測</label><input type="text" id="rsTplDetectionCtrl"></div>
                <div><label>偵測度 D</label><input type="number" min="1" max="10" id="rsTplDetection"></div>
                <div><label>建議措施</label><input type="text" id="rsTplRecAction"></div>
                <div><label>評價S</label><input type="number" min="1" max="10" id="rsTplNewSeverity"></div>
                <div><label>評價O</label><input type="number" min="1" max="10" id="rsTplNewOccurrence"></div>
                <div><label>評價D</label><input type="number" min="1" max="10" id="rsTplNewDetection"></div>
            </div>
            <div style="margin-top:8px;">
                <button type="button" class="pf-row-btn" onclick="rsSaveTpl()">儲存樣板</button>
                <button type="button" class="pf-row-btn" onclick="$('#rsTplForm').hide();">取消</button>
            </div>
        </div>
        </div>

        <div class="pf-rt-pane" data-rstab="link">
        <div style="font-size:12px;color:#8a6d45;margin-bottom:6px;">欄位個別設定對應：填了來源欄位的值就建議帶出目標欄位的值（如潛在失效模式→失效模式潛在後果/分類/失效潛在原因、料號＋製程代號→規格描述）。
            <span class="pf-op" onclick="rsBackfillLinks()" style="color:#b5762a;text-decoration:underline;cursor:pointer;"><i class="fa fa-download"></i> 從整組樣板回填</span>
        </div>
        <label style="font-size:13px;color:#5b3a1e;">對應組合</label>
        <select id="rsLinkPairSel" style="width:320px;border:1px solid #D8BE93;border-radius:4px;padding:5px 8px;font-size:13px;">
            <option value="failure_mode|failure_effect">潛在失效模式 → 失效模式潛在後果</option>
            <option value="failure_mode|classification">潛在失效模式 → 分類</option>
            <option value="failure_mode|failure_cause">潛在失效模式 → 失效潛在原因</option>
            <option value="part_process|spec_desc">料號＋製程代號 → 圖面要求</option>
        </select>
        <div style="display:flex;gap:16px;margin-top:10px;">
            <div style="flex:1;">
                <label style="font-size:12px;color:#5b3a1e;">來源值（<span id="rsLinkSourceLabel">潛在失效模式</span>）</label>
                <div class="pf-proc-box" id="rsLinkSourceNewTextBox">
                    <input type="text" id="rsLinkSourceNewText" placeholder="輸入新的潛在失效模式文字以新增來源">
                    <button type="button" class="pf-row-btn" onclick="rsSelectNewFailureModeSource()">選定</button>
                </div>
                <div id="rsLinkSourceNewPartProcessBox" style="display:none;">
                    <div class="pf-proc-box"><input type="text" id="rsLinkSourceNewPart" placeholder="輸入部分料號搜尋"></div>
                    <div id="rsLinkPartBoundStatus" style="font-size:11px;margin:3px 0;"></div>
                    <div class="pf-proc-box" style="margin-top:4px;">
                        <select id="rsLinkSourceNewProc" style="flex:1;border:1px solid #D8BE93;border-radius:4px;padding:5px 8px;font-size:13px;" data-eg-filter="輸入製程代號或名稱篩選…"></select>
                        <button type="button" class="pf-row-btn" onclick="rsSelectNewPartProcessSource()">選定</button>
                    </div>
                </div>
                <div class="rs-list" id="rsLinkSourceList" style="max-height:150px;margin-top:6px;"></div>
            </div>
            <div style="flex:1;">
                <label style="font-size:12px;color:#5b3a1e;">對應的目標值（<span id="rsLinkTargetLabel">失效模式潛在後果</span>）</label>
                <div class="rs-list" id="rsLinkTargetList" style="max-height:220px;"></div>
                <div class="pf-proc-box"><input type="text" id="rsLinkTargetNew" placeholder="新增對應值" disabled><button type="button" class="pf-row-btn" onclick="rsAddLinkTarget()" disabled id="rsLinkTargetAddBtn">新增</button></div>
                <div style="margin-top:4px;"><b style="font-size:11px;color:#8a6d45;">工程符號（點選插入目前游標位置）</b><div class="pf-sym-row" id="rsEngSymRow"></div></div>
                <div><b style="font-size:11px;color:#8a6d45;">幾何公差／特殊項目</b><div class="pf-sym-row" id="rsGdtSymRow"></div></div>
            </div>
        </div>
        </div>
    </div>
    <div class="m-foot"><button class="b-cancel" onclick="closeMask('refSettingsMask')">關閉</button></div>
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
        <p>製程潛在失效模式及效應分析（PFMEA，AS 3-TD-01-02），每個料號一份分析表。表頭欄位（料號／客戶名稱／產品名稱／規格描述／業務日期／分類／相關部門）與分析表格欄位皆比照官方紙本表單(F-11210-UE2-0001)。逐列記錄一個潛在失效模式：從項目、功能、要求、潛在失效模式、失效模式潛在後果、分類、失效潛在原因，評出嚴重度(S)/發生率(O)，填現行設計管制（控制預防／控制偵測）、評出偵測度(D)，系統自動算出風險優先指數 RPN=S×O×D；針對高 RPN 項目填建議措施、目標完成日，改善後填生效日期，再評一次新的 S/O/D/RPN（改善後RPN=評價S×評價O×評價D）。</p>
        <h4>操作步驟</h4>
        <ul>
            <li>按「新增」→ 選擇「料號」（打部分字元搜尋，查無此料號時可直接手動輸入；選定後客戶名稱與「分類」零件/組合件自動帶入，可手動修改；「規格描述」自動偵測齒輪規格、「產品名稱」自動帶入預設值，皆可手動改）。綁定料號後「料號」欄旁出現「開圖」按鈕（開新視窗看圖面），右側出現此料號的訂單/報價製程履歷面板可對照填寫。</li>
            <li><b>業務日期</b>：手動新建的紀錄，綁定料號後下方會列出「套用BOM日期／套用最早報工日期／套用最早訂單日期」按鈕，點擊即帶入（比照建議建立清單同一套參考日期邏輯）；由「建議建立清單」批次轉入的紀錄，此欄自動沿用產品開發評估表的建立日期。新增失效模式分析列時，「目標完成日」「生效日期」預設帶入業務日期，可個別修改；編輯既有文件時若某列內容有異動，存檔前會詢問是否要一併把該列這兩個日期更新為業務日期。</li>
            <li>每個潛在失效模式是一張卡片，欄位由上到下分「基本資料／風險評估與現行設計管制／建議措施／措施結果」四區，不需要橫向捲動；S/O/D 每格填 1-10，<b>RPN 由系統自動計算，不可手動輸入</b>。按「新增一項失效模式分析」可再加一張卡片。</li>
            <li><b>製程代號</b>：卡片內輸入已建立的製程代號會自動帶出該製程的「項目」下拉選項；輸入清單中沒有的新代號會詢問製程名稱並即時建立。「控制預防」「控制偵測」同樣是下拉可選/可手動輸入。按「整組列表」可叫出此製程所有樣板（組名＝製程名稱_項目名稱），點選後直接把該筆的基本資料/評級/控制/建議措施/評價欄位整批帶入，帶入後仍可個別修改。這些清單新增不限身分，僅管理員能刪除。</li>
            <li><b>項目→功能→要求／潛在失效模式 階層式連動</b>：填完「項目」離開該欄位，會自動帶出這個項目底下的「功能」下拉選項；填完「功能」離開該欄位，會自動帶出這個功能底下的「要求」下拉（優先顯示綁定的料號專屬要求，沒有才顯示該功能通用要求）與更精確的「潛在失效模式」下拉（優先套用這個功能專屬的清單，還沒累積過資料才逐層退回項目層級、製程層級的通用清單）。四層清單都可以直接手動輸入新值，離開欄位或存檔時會自動加進清單供下次選用，僅管理員能刪除。</li>
            <li><b>建議建立清單</b>：工具列同名按鈕，自動列出已建立「產品開發評估表(2-TD-02-01)」、但還沒建立 PFMEA 的料號，勾選（可全選）後一次建立表頭殼（料號／客戶／產品名稱／分類／業務日期自動帶入），分析項目仍需逐份手動填寫。</li>
            <li><b>製程代號</b>：改可從全站製程主檔同步帶入（含大項分類），輸入時同時模糊搜尋代號/名稱/大項分類（多關鍵字皆需命中），顯示清單供點選。</li>
            <li><b>參考資料設定</b>：工具列同名按鈕（僅管理員可見），分三個頁籤：①<b>製程與階層</b>——「從全站製程主檔同步」拉入公司所有製程（含大項分類），大項分類可一鍵批次開放/取消其底下所有製程（仍可個別覆蓋），只有開放的製程會出現在分析表下拉；並可逐層鑽取設定該製程底下的項目、項目底下的功能，以及目前鑽取深度對應的潛在失效模式／要求清單（鑽到功能就是功能專屬、只選到項目就是項目通用、只選製程就是製程通用），還有全域共用的控制預防／控制偵測／建議措施選項（皆可新增/刪除，僅管理員可刪除）。②<b>整組樣板</b>——選製程後可新增/編輯/刪除整組樣板（不必再靠 xlsm 匯入）。③<b>欄位個別設定對應</b>——瀏覽/新增/刪除「潛在失效模式→失效模式潛在後果/分類/失效潛在原因」「料號＋製程代號→規格描述」的對應清單。刪除只影響清單設定本身，不會動到已經填寫存檔的分析表資料。</li>
            <li><b>建議措施樣板</b>：「建議措施」欄位標題旁「選樣板（可複選）」可開跳窗勾選預先在參考資料設定建立好的建議措施句庫，套用時自動接續編號（1. 2. 3.…）；手動輸入時只要目前這行是「數字.」開頭，按 Enter 換行會自動接下一個編號，不必自己算。</li>
            <li><b>基本資料欄位自動縮小字級</b>：項目/功能/要求/潛在失效模式/失效模式潛在後果/分類這幾欄是可挑選也可手動輸入的欄位，受限於瀏覽器限制無法真正多行換行，文字太長時會自動縮小字級（最小9px）以盡量完整顯示，欄位變短時字級會自動還原。</li>
            <li><b>檢視（唯讀）</b>：只有檢閱權限、無登錄權限的使用者，清單「操作」欄看到的是眼睛圖示「檢視」而非鉛筆「編輯」，點開版面跟列印版完全一樣（不會誤觸修改），並提供「評級對照表說明」「開圖」兩個按鈕方便對照查閱，不會觸發實際列印動作。</li>
        </ul>
        <h4>其他行為／常見疑問</h4>
        <ul>
            <li>「評級對照表」隨時可見一組精簡的嚴重度(S)／發生率(O)／偵測度(D)／風險優先指數(RPN)速查小表（比照官方表單版面）；點擊標題列另外開跳窗顯示完整官方說明文字，分四個分頁。兩者內容皆為固定參考，不隨每份分析表個別修改。S/O/D（含評價S/O/D）只能填1~10之間的整數，超出範圍會跳窗擋下並提示合法範圍。</li>
            <li><b>評價S／評價O／評價D 建議值</b>：填完「失效模式潛在後果」「嚴重度」「失效潛在原因」後，系統會依「製程+項目+功能+潛在失效模式+失效模式潛在後果+嚴重度+失效潛在原因」完整組合，自動查過去是否有填過一樣的組合，有的話自動帶入當時的評價S/O/D（只有還沒填的欄位才會被帶入，不覆蓋您已手動填的值）；此機制<b>只在新增列（尚未存檔）時生效</b>，存檔後該列即鎖定，之後編輯不會再被自動覆蓋。若手動輸入的數值跟建議值不在同一個評級對照表級距內，會跳提示但仍會採用您輸入的數值。</li>
            <li><b>欄位個別設定對應</b>：潛在失效模式跟失效模式潛在後果/分類/失效潛在原因、產品名稱跟規格描述，都可以個別設定「填了A就建議帶出B」的對應關係——離開來源欄位時系統會自動查詢並帶出建議清單，仍可手動輸入新值，存檔時會自動記住新的組合供下次使用。</li>
            <li>料號可點擊開啟圖面查閱（比照報價單頁做法）。</li>
            <li><b>相關部門預設值</b>：勾選好常用部門後，點旁邊「設為預設勾選值」（僅管理員可見）即可設定新建文件時自動帶勾的部門，仍可逐份調整。</li>
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
var DEFAULT_PRODUCT_NAME = <?= json_encode($defaultProductName, JSON_UNESCAPED_UNICODE) ?>; // 產品名稱預設值(全部產品通用單一值，設定入口在td_dev_eval.php，PFMEA只讀取套用)
var ITEM_ORIG = {}; // 編輯既有文件時，各列原始內容快照(id -> 排除target_date/action_date後的JSON)，存檔時比對是否異動供提示更新日期
var RENDER_SEQ = 0;
var PROCESS_LIST = []; // [{id,process_code,process_name}] 製程代號主檔，跳窗開啟時載入一次
var CONTROL_OPTIONS = {prevention:[], detection:[]}; // 控制預防/控制偵測固定選項，跳窗開啟時載入一次
var PROCESS_ID_BY_CODE = {}; // process_code -> id 對照，製程代號連動查失效模式/整組樣板用
var TEMPLATE_ROWS = [], TEMPLATE_TARGET = null; // 整組列表跳窗暫存
var RS_PROC_ID = 0, RS_ITEM_OPT_ID = 0, RS_FUNC_OPT_ID = 0; // 參考資料設定跳窗目前鑽取到哪一層
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
                + (CAN_EDIT ? '<span class="pf-op" title="編輯" onclick="openEdit('+r.id+')"><i class="fa fa-pencil"></i></span>' : '<span class="pf-op" title="檢視(唯讀)" onclick="viewDoc('+r.id+')"><i class="fa fa-eye"></i></span>')
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
        + '<input type="text" class="f-proccode" data-f="process_code" value="'+esc(procCode)+'" autocomplete="off" placeholder="輸入製程代號/名稱/大項分類模糊搜尋"'+dis+'>'
        + '<button type="button" class="pf-row-btn" onclick="openTemplatePicker(this)" title="此製程的整組樣板列表"'+dis+'><i class="fa fa-list"></i> 整組列表</button>'
        + '</div></div>'
        + fld('process_desc','項目','list:item') + fld('function_desc','功能','list:function') + fld('requirement','要求','list:requirement')
        + fld('failure_mode','潛在失效模式','list:failure_mode') + fld('failure_effect','失效模式潛在後果','list:failure_effect') + fld('classification','分類','list:classification')
        + '</div>'
        + '<div class="pf-card-grp-title">風險評估與現行設計管制（RPN 系統自動計算）</div>'
        + '<div class="pf-rating-quad">'
        + fld('severity','嚴重度 S','rating') + fld('failure_cause','失效潛在原因','list:failure_cause')
        + fld('occurrence','發生率 O','rating') + fld('prevention_controls','控制預防','list:prevention')
        + fld('detection_controls','控制偵測','list:detection') + fld('detection','偵測度 D','rating')
        + '<div><label>RPN</label><input type="text" class="rpn-out'+rpnCls+'" data-rpn value="'+rpn+'" readonly></div>'
        + '</div>'
        + '<div class="pf-card-grp-title">建議措施</div>'
        + '<div class="pf-card-grid">'
        + '<div><label>建議措施<span class="pf-op" onclick="openActionPicker(this)" style="font-weight:normal;color:#b5762a;text-decoration:underline;cursor:pointer;margin-left:8px;"'+dis+'><i class="fa fa-list"></i> 選樣板（可複選）</span></label>'
        + '<textarea data-f="recommended_actions" class="f-action">'+esc(it.recommended_actions!=null?it.recommended_actions:'')+'</textarea></div>'
        + fld('target_date','目標完成日','date')
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
   該製程的項目/潛在失效模式下拉，並把既有的項目/功能文字回頭解析成id(補登記進參考清單、順便接上
   功能→要求/潛在失效模式下一層下拉)，不必使用者手動再觸發一次 change/blur 事件 */
function refreshAllCardDatalists(){
    $('#itemBody .pf-card').each(function(){
        var $card = $(this);
        populateCardControlDatalists($card);
        var code = $card.find('.f-proccode').val().trim();
        if (!code || !PROCESS_ID_BY_CODE[code]) return;
        var pid = PROCESS_ID_BY_CODE[code].id;
        loadItemOptionsForCard($card, pid);
        loadRequirementOptionsForCard($card, 0); // 製程層級要求先帶出，功能層級解析完成後會再刷新一次(見resolveFunctionOption)
        if ($card.find('[data-f="process_desc"]').val().trim() && CAN_EDIT) {
            resolveItemOption($card, function(itemOptId){
                if (itemOptId && $card.find('[data-f="function_desc"]').val().trim()) resolveFunctionOption($card);
            });
        } else {
            loadFailureModesForCard($card, pid, 0, 0);
        }
        var fmVal = $card.find('[data-f="failure_mode"]').val().trim();
        if (fmVal) {
            ['failure_effect','classification','failure_cause'].forEach(function(f){
                loadFieldLink($card.find('datalist.dl-'+f), 'failure_mode', fmVal, f);
            });
        }
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
    // 展開後欄位才有實際寬度可量測，順便檢查基本資料欄位文字是否過長要縮小字級
    if (!willCollapse) $card.find('.pf-card-grid input[list]').each(function(){ autoShrinkFit($(this)); });
};
window.pfAddRow = function(){
    // 新增一列失效模式分析：目標完成日/生效日期預設帶入業務日期，減少逐列手填（2026-08-13使用者要求）
    var bizDate = $('#fBizDate').val();
    $('#itemBody').append(itemCardHtml(bizDate ? {target_date:bizDate, action_date:bizDate} : {}, $('#itemBody .pf-card').length, true));
    renumberRows();
    populateCardControlDatalists($('#itemBody .pf-card').last());
    return true;
};
// 業務日期填入/變更時，順便回填目前還空白的目標完成日/生效日期欄位（已填過的列不覆蓋）
$(document).on('change', '#fBizDate', function(){
    var v = $(this).val();
    if (!v) return;
    $('#itemBody .pf-card').each(function(){
        var $card = $(this);
        if (!$card.find('[data-f="target_date"]').val()) $card.find('[data-f="target_date"]').val(v);
        if (!$card.find('[data-f="action_date"]').val()) $card.find('[data-f="action_date"]').val(v);
    });
});
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

/* ---------- 評價S/O/D建議規則（2026-08-14使用者要求第8段）----------
 * 嚴重度/發生率/偵測度(含評價S/O/D)一律只能是評級對照表定義範圍內的數值(1~10整數，超出直接
 * 擋下並提示合法範圍)；評價S/O/D另外依「製程+項目+功能+潛在失效模式+失效模式潛在效果+嚴重度+
 * 失效潛在原因」完整組合自動帶建議值——只在新增列(尚未存檔，data-id=0)生效，存檔後鎖定不再自動
 * 覆蓋；手動輸入跟建議值不同級距時允許但跳警示。 */
var S_TIERS = [[1,1],[2,2],[3,6],[7,7],[8,8],[9,10]];
var O_TIERS = [[1,1],[2,3],[4,6],[7,9],[10,10]];
var D_TIERS = [[1,1],[2,2],[3,3],[4,4],[5,5],[6,6],[7,7],[8,9],[10,10]];
function tierOf(tiers, v){ for (var i=0;i<tiers.length;i++){ if (v>=tiers[i][0] && v<=tiers[i][1]) return i; } return -1; }
$(document).on('blur', '#itemBody .sod-in', function(){
    var raw = $(this).val().trim();
    if (raw === '') return;
    var v = parseInt(raw, 10);
    if (isNaN(v) || v < 1 || v > 10 || String(v) !== raw) {
        alert('數值必須是1~10之間的整數（評級對照表整體有效範圍），請重新輸入。');
        $(this).val('').trigger('input');
    }
});
function ratingRuleKey($card){
    var code = $card.find('.f-proccode').val().trim();
    return {
        process_id: code && PROCESS_ID_BY_CODE[code] ? PROCESS_ID_BY_CODE[code].id : 0,
        item_option_id: parseInt($card.attr('data-item-opt-id'),10) || 0,
        function_option_id: parseInt($card.attr('data-func-opt-id'),10) || 0,
        failure_mode: $card.find('[data-f="failure_mode"]').val().trim(),
        failure_effect: $card.find('[data-f="failure_effect"]').val().trim(),
        severity: parseInt($card.find('[data-f="severity"]').val(),10) || 0,
        failure_cause: $card.find('[data-f="failure_cause"]').val().trim(),
    };
}
function maybeSuggestRating($card){
    if ($card.attr('data-id')|0) return; // 已存檔的既有列鎖定，不再自動帶入覆蓋
    var k = ratingRuleKey($card);
    if (!k.process_id || !k.failure_mode || !k.failure_effect || !k.severity || !k.failure_cause) return;
    $.getJSON(API, {action:'rating_rule_lookup', process_id:k.process_id, item_option_id:k.item_option_id, function_option_id:k.function_option_id,
        failure_mode:k.failure_mode, failure_effect:k.failure_effect, severity:k.severity, failure_cause:k.failure_cause}, function(res){
        if (!res.success || !res.rule) return;
        var r = res.rule;
        if (!$card.find('[data-f="new_severity"]').val()) $card.find('[data-f="new_severity"]').val(r.new_severity).trigger('input');
        if (!$card.find('[data-f="new_occurrence"]').val()) $card.find('[data-f="new_occurrence"]').val(r.new_occurrence).trigger('input');
        if (!$card.find('[data-f="new_detection"]').val()) $card.find('[data-f="new_detection"]').val(r.new_detection).trigger('input');
        $card.data('ratingSuggest', r);
    });
}
$(document).on('blur', '#itemBody [data-f="failure_effect"], #itemBody [data-f="severity"], #itemBody [data-f="failure_cause"]', function(){
    maybeSuggestRating($(this).closest('.pf-card'));
});
$(document).on('blur', '#itemBody [data-f="new_severity"], #itemBody [data-f="new_occurrence"], #itemBody [data-f="new_detection"]', function(){
    var $card = $(this).closest('.pf-card'), suggest = $card.data('ratingSuggest');
    if (!suggest) return;
    var f = $(this).attr('data-f');
    var tiers = f==='new_severity' ? S_TIERS : (f==='new_occurrence' ? O_TIERS : D_TIERS);
    var suggestVal = f==='new_severity' ? suggest.new_severity : (f==='new_occurrence' ? suggest.new_occurrence : suggest.new_detection);
    var v = parseInt($(this).val(),10);
    if (!v || tierOf(tiers, v) === tierOf(tiers, suggestVal)) return;
    alert('您輸入的數值（'+v+'）跟系統依過去紀錄建議的數值（'+suggestVal+'）不在同一個評級對照表級距內，仍會採用您輸入的數值。');
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

/* 基本資料欄位文字太長時自動縮小字級（2026-08-14使用者要求）：這些欄位是<input list=datalist>
   實作(挑選/自行輸入二選一)，datalist只能綁在單行input上、瀏覽器不支援真正的多行自動換行，
   退而求其次做「內容溢出時自動縮小字級直到放得下(最小9px)」，欄位變短/清空時字級自動還原。 */
function autoShrinkFit($el){
    var el = $el && $el[0];
    if (!el || !el.offsetParent) return; // 欄位不可見(如卡片收合中)時無法量測寬度，略過
    $el.css('font-size', '');
    var min = 9, size = 12;
    while (el.scrollWidth > el.clientWidth + 1 && size > min) {
        size--;
        $el.css('font-size', size + 'px');
    }
}
$(document).on('input', '#itemBody .pf-card-grid input[list]', function(){ autoShrinkFit($(this)); });

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
   並帶出該製程的潛在失效模式下拉選項供「潛在失效模式」欄位挑選/自行輸入。
   潛在失效模式改階層式查詢：帶item_option_id/function_option_id，後端優先套用功能層級專屬清單，
   逐層退回項目層級、製程層級（2026-08-13使用者要求，見pfmea_reference_lib.php說明） */
function loadFailureModesForCard($card, pid, itemOptId, funcOptId){
    var $dl = $card.find('datalist.dl-failure_mode');
    $.getJSON(API, {action:'ref_failure_mode_list', process_id:pid, item_option_id:itemOptId||0, function_option_id:funcOptId||0}, function(res){
        if (!res.success) return;
        $dl.each(function(){ fillDatalist(this, res.rows, function(r){ return r.failure_mode; }); });
    });
}
/* ---------- 料號-製程-項目-功能-要求 階層式連動（2026-08-13使用者要求）----------
 * 項目/功能欄位失焦時即時解析成id(get_or_add，輸入新值就自動註冊，跟製程代號同一套慣例但不用
 * 跳窗詢問——項目/功能是自我描述的文字，不像製程代號需要另外一個「名稱」)，解析出的id存在卡片的
 * data-item-opt-id/data-func-opt-id，供下一層的下拉選項查詢使用。 */
function loadItemOptionsForCard($card, pid){
    var $dl = $card.find('datalist.dl-item');
    if (!pid){ $dl.each(function(){ this.innerHTML=''; }); return; }
    $.getJSON(API, {action:'ref_item_options_list', process_id:pid}, function(res){
        if (res.success) $dl.each(function(){ fillDatalist(this, res.rows, function(r){ return r.item_name; }); });
    });
}
function loadFunctionOptionsForCard($card, itemOptId){
    var $dl = $card.find('datalist.dl-function');
    if (!itemOptId){ $dl.each(function(){ this.innerHTML=''; }); return; }
    $.getJSON(API, {action:'ref_function_options_list', item_option_id:itemOptId}, function(res){
        if (res.success) $dl.each(function(){ fillDatalist(this, res.rows, function(r){ return r.function_desc; }); });
    });
}
/* 要求下拉：優先套用funcOptId(功能層級)專屬清單，沒有funcOptId或該層級查無資料時後端會退回
   process_id(製程層級，如製作表單.xlsm匯入的舊資料)——這裡兩個id都傳，讓後端決定退回順序 */
function loadRequirementOptionsForCard($card, funcOptId){
    var $dl = $card.find('datalist.dl-requirement');
    var code = $card.find('.f-proccode').val().trim();
    var pid = code && PROCESS_ID_BY_CODE[code] ? PROCESS_ID_BY_CODE[code].id : 0;
    if (!funcOptId && !pid){ $dl.each(function(){ this.innerHTML=''; }); return; }
    var partDId = $('#fPartDId').val() || 0;
    var partText = (partDId|0) ? '' : $('#fPartNo').val();
    $.getJSON(API, {action:'ref_requirement_options_list', function_option_id:funcOptId||0, process_id:pid, part_d_id:partDId, part_no_text:partText}, function(res){
        if (res.success) $dl.each(function(){ fillDatalist(this, res.rows, function(r){ return r.requirement_text; }); });
    });
}
function refreshFailureModeForCard($card){
    var code = $card.find('.f-proccode').val().trim();
    var pid = code && PROCESS_ID_BY_CODE[code] ? PROCESS_ID_BY_CODE[code].id : 0;
    if (!pid) return;
    loadFailureModesForCard($card, pid, parseInt($card.attr('data-item-opt-id'),10)||0, parseInt($card.attr('data-func-opt-id'),10)||0);
}
/* ---------- 欄位個別設定對應（2026-08-14使用者要求：基本資料內欄位可個別設定對應到其他欄位）----------
 * 通用機制：任一欄位值可設定對應到另一欄位的建議清單，如潛在失效模式->失效模式潛在後果/分類/
 * 失效潛在原因、料號+製程代號(複合鍵)->規格描述。離開來源欄位時查詢並帶出建議清單，仍可直接
 * 手動輸入新值（存檔時自動註冊，見registerNewRefValues）。 */
function loadFieldLink($dl, sourceField, sourceValue, targetField){
    if (!sourceValue){ $dl.each(function(){ this.innerHTML=''; }); return; }
    $.getJSON(API, {action:'field_link_list', source_field:sourceField, source_value:sourceValue, target_field:targetField}, function(res){
        if (res.success) $dl.each(function(){ fillDatalist(this, res.rows, function(r){ return r.target_value; }); });
    });
}
$(document).on('blur', '#itemBody [data-f="failure_mode"]', function(){
    var $card = $(this).closest('.pf-card'), v = $(this).val().trim();
    loadFieldLink($card.find('datalist.dl-failure_effect'), 'failure_mode', v, 'failure_effect');
    loadFieldLink($card.find('datalist.dl-classification'), 'failure_mode', v, 'classification');
    loadFieldLink($card.find('datalist.dl-failure_cause'), 'failure_mode', v, 'failure_cause');
});
function resolveItemOption($card, cb){
    var code = $card.find('.f-proccode').val().trim();
    var itemName = $card.find('[data-f="process_desc"]').val().trim();
    if (!code || !PROCESS_ID_BY_CODE[code] || !itemName || !CAN_EDIT){ if (cb) cb(0); return; }
    $.post(API, {action:'ref_item_option_add', process_id:PROCESS_ID_BY_CODE[code].id, item_name:itemName}, function(res){
        var id = (res && res.success) ? res.id : 0;
        $card.attr('data-item-opt-id', id);
        loadFunctionOptionsForCard($card, id);
        refreshFailureModeForCard($card);
        if (cb) cb(id);
    }, 'json');
}
function resolveFunctionOption($card, cb){
    var itemOptId = parseInt($card.attr('data-item-opt-id'),10) || 0;
    var funcDesc = $card.find('[data-f="function_desc"]').val().trim();
    if (!itemOptId || !funcDesc || !CAN_EDIT){ if (cb) cb(0); return; }
    $.post(API, {action:'ref_function_option_add', item_option_id:itemOptId, function_desc:funcDesc}, function(res){
        var id = (res && res.success) ? res.id : 0;
        $card.attr('data-func-opt-id', id);
        refreshFailureModeForCard($card);
        loadRequirementOptionsForCard($card, id);
        if (cb) cb(id);
    }, 'json');
}
$(document).on('blur', '#itemBody [data-f="process_desc"]', function(){ resolveItemOption($(this).closest('.pf-card')); });
$(document).on('blur', '#itemBody [data-f="function_desc"]', function(){ resolveFunctionOption($(this).closest('.pf-card')); });
$(document).on('change', '#itemBody .f-proccode', function(){
    var $input = $(this), $card = $input.closest('.pf-card');
    var code = $input.val().trim();
    $card.attr('data-item-opt-id', 0).attr('data-func-opt-id', 0);
    $card.find('datalist.dl-function, datalist.dl-requirement').each(function(){ this.innerHTML=''; });
    if (!code){ $card.find('datalist.dl-failure_mode, datalist.dl-item').each(function(){ this.innerHTML=''; }); return; }
    if (PROCESS_ID_BY_CODE[code]){
        loadFailureModesForCard($card, PROCESS_ID_BY_CODE[code].id, 0, 0);
        loadItemOptionsForCard($card, PROCESS_ID_BY_CODE[code].id);
        loadRequirementOptionsForCard($card, 0); // 製程層級要求(如製作表單.xlsm匯入的舊資料)，項目/功能還沒填也能先帶出
        loadSpecDescSuggestion(code);
        return;
    }
    if (!CAN_EDIT) return;
    var name = window.prompt('製程代號「'+code+'」尚未建立，請輸入製程名稱以新增：', '');
    if (!name){ $input.val(''); return; }
    $.post(API, {action:'ref_process_add', process_code:code, process_name:name}, function(res){
        if (!res.success){ alert(res.message||'新增製程失敗'); $input.val(''); return; }
        loadProcessList(function(){ loadFailureModesForCard($card, res.id, 0, 0); loadItemOptionsForCard($card, res.id); loadRequirementOptionsForCard($card, 0); loadSpecDescSuggestion(code); });
    }, 'json');
});
/* 規格描述建議：依「文件綁定的料號」+「這張卡片的製程代號」複合鍵查詢(2026-08-14使用者更正，
   不能只依料號，要多一層製程代號) */
function loadSpecDescSuggestion(processCode){
    var partNo = $('#fPartNo').val().trim();
    if (!partNo || !processCode) return;
    loadFieldLink($('#dl_specDesc'), 'part_process', partNo + PART_PROCESS_SEP + processCode, 'spec_desc');
}

/* ---------- 製程代號模糊搜尋（2026-08-14使用者要求）----------
 * 輸入時同時比對製程代號/名稱/大項分類(多關鍵字空白分隔、每個都要命中)，顯示清單供點選，
 * 取代原生datalist(只會前綴比對、無法連大項分類一起搜)。 */
var PROC_SEARCH_TARGET = null;
function procSearchRender(kw){
    var terms = kw.toLowerCase().split(/\s+/).filter(Boolean);
    var rows = PROCESS_LIST.filter(function(p){
        var hay = (p.process_code+' '+p.process_name+' '+(p.category_name||'')).toLowerCase();
        return terms.every(function(t){ return hay.indexOf(t) >= 0; });
    });
    if (!rows.length){ $('#procSearchDD').hide(); return; }
    var html = rows.slice(0, 50).map(function(p){
        return '<div class="proc-search-item" data-code="'+esc(p.process_code)+'" style="padding:6px 10px;cursor:pointer;font-size:12px;border-bottom:1px solid #F3EAD6;">'
            + '<b>'+esc(p.process_code)+'</b> '+esc(p.process_name)
            + (p.category_name ? ' <span style="color:#8a6d45;">['+esc(p.category_name)+']</span>' : '')
            + '</div>';
    }).join('');
    $('#procSearchDD').html(html).show();
}
$(document).on('mouseover', '#procSearchDD .proc-search-item', function(){ $(this).css('background', '#FFF7E8'); });
$(document).on('mouseout', '#procSearchDD .proc-search-item', function(){ $(this).css('background', ''); });
$(document).on('focus input', '#itemBody .f-proccode', function(){
    PROC_SEARCH_TARGET = $(this);
    var rect = this.getBoundingClientRect();
    $('#procSearchDD').css({left:rect.left+'px', top:(rect.bottom+2)+'px', width:Math.max(rect.width,260)+'px'});
    procSearchRender($(this).val().trim());
});
$(document).on('blur', '#itemBody .f-proccode', function(){ $('#procSearchDD').hide(); });
$(document).on('mousedown', '#procSearchDD .proc-search-item', function(e){
    e.preventDefault(); // 防止輸入框先blur把清單關掉，選取事件就進不來
    if (!PROC_SEARCH_TARGET) return;
    PROC_SEARCH_TARGET.val($(this).attr('data-code')).trigger('change');
    $('#procSearchDD').hide();
});

/* ---------- 建議措施樣板挑選（可複選，自動加編號；2026-08-14使用者要求）----------
 * 樣板句庫沿用控制選項同一張表(option_type='action')，在參考資料設定畫面維護；套用時依目前欄位
 * 內容已有的最大編號接續往下編，手動輸入時Enter偵測目前行是否為「N.」格式，是的話自動接下一號。 */
var ACTION_PICKER_TARGET = null;
window.openActionPicker = function(el){
    if (!CAN_EDIT) return;
    ACTION_PICKER_TARGET = $(el).closest('.pf-card').find('[data-f="recommended_actions"]');
    var opts = (CONTROL_OPTIONS.action || []);
    var html = opts.map(function(o){
        return '<label class="pf-chk" style="display:block;margin-bottom:6px;"><input type="checkbox" value="'+esc(o.option_text)+'"> '+esc(o.option_text)+'</label>';
    }).join('') || '<div class="rs-empty">尚無資料，請先到工具列「參考資料設定→製程與階層」頁籤新增建議措施樣板</div>';
    $('#actionPickerList').html(html);
    openMask('actionPickerMask');
};
function actionNextNumber(text){
    var maxNum = 0;
    (text||'').split('\n').forEach(function(l){
        var m = l.match(/^(\d+)\./);
        if (m) maxNum = Math.max(maxNum, parseInt(m[1],10));
    });
    return maxNum + 1;
}
window.applyActionPicker = function(){
    var checked = $('#actionPickerList input:checked').map(function(){ return $(this).val(); }).get();
    closeMask('actionPickerMask');
    if (!checked.length || !ACTION_PICKER_TARGET) return;
    var cur = ACTION_PICKER_TARGET.val();
    var n = actionNextNumber(cur);
    var newLines = checked.map(function(t, i){ return (n+i)+'.'+t; });
    var combined = cur.trim() ? cur.replace(/\n+$/, '') + '\n' + newLines.join('\n') : newLines.join('\n');
    ACTION_PICKER_TARGET.val(combined).trigger('input');
};
// 手動輸入時Enter換行自動接續編號：偵測目前這行是否為「N.」開頭，是的話換行後自動補「N+1.」
$(document).on('keydown', '#itemBody .f-action', function(e){
    if (e.key !== 'Enter') return;
    var el = this, val = el.value, pos = el.selectionStart;
    var currentLineStart = val.substring(0, pos).lastIndexOf('\n') + 1;
    var currentLine = val.substring(currentLineStart, pos);
    var m = currentLine.match(/^(\d+)\./);
    if (!m) return;
    e.preventDefault();
    var insert = '\n' + (parseInt(m[1],10) + 1) + '.';
    el.value = val.substring(0, pos) + insert + val.substring(pos);
    var newPos = pos + insert.length;
    el.setSelectionRange(newPos, newPos);
    $(el).trigger('input');
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
    // 套用樣板後，項目/功能欄位是程式塞值不會觸發blur，這裡手動接上項目→功能連動關係
    resolveItemOption($card, function(){ resolveFunctionOption($card); });
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
    CUR_ID = 0; ITEM_ORIG = {};
    $('#fPartNo').val(''); $('#fPartDId').val('0'); $('#fCustomerName').val('');
    $('#fProductName').val(DEFAULT_PRODUCT_NAME || ''); $('#fSpecDesc').val('');
    $('#fBizDate').val(''); $('#fBizDateQuick').html('');
    $('#btnOpenDrawing').hide(); $('#fOrderProcPanel').hide();
    $('input[name=fItemType][value=part]').prop('checked', true);
    $('#fDeptChecks').html(deptChecksHtml());
    $('#btnDeptDefaultSave').toggle(!!CAN_ADMIN);
    $.getJSON(API, {action:'dept_defaults_get'}, function(res){
        if (res.success && res.depts && res.depts.length && CUR_ID === 0) $('#fDeptChecks').html(deptChecksHtml(res.depts));
    });
    $('#fDocNo').text('存檔後自動產生'); $('#fCreatedInfo').text('—');
    renderItems([]);
}
/* 綁定料號後共用動作：開圖按鈕/訂單製程履歷側欄/齒輪規格自動偵測/建議建立日期快速套用按鈕
   （2026-08-13使用者要求，皆綁定料號後才有意義，未綁定料號時全部隱藏/清空） */
function onPartBound(partDId, partText, custName){
    if (!partDId && !partText) {
        $('#btnOpenDrawing').hide(); $('#fOrderProcPanel').hide(); $('#fBizDateQuick').html('');
        return;
    }
    $('#btnOpenDrawing').show();
    if (partDId) {
        $('#fOrderProcPanel').show();
        $.getJSON(API, {action:'order_process_list', part_d_id:partDId}, function(res){
            if (!res.success) return;
            var rows = res.rows || [];
            $('#fOrderProcList').html(rows.length ? rows.map(function(r){
                return '<div style="margin-bottom:4px;padding-bottom:4px;border-bottom:1px dashed #EADFC8;">'
                    + esc(r.ref_kind||'')+' '+esc(r.ref_no||'')+'<br>'+esc(r.process||'')+'　'+fmtDate(r.ref_date||'')+'</div>';
            }).join('') : '<span style="color:#8a6d45;">查無紀錄</span>');
        });
        $.getJSON(API, {action:'gear_spec_get', part_d_id:partDId}, function(res){
            if (res.success && res.spec) $('#fSpecDesc').val(res.spec);
        });
    } else {
        $('#fOrderProcPanel').hide();
    }
    if (!CUR_ID) loadBizDateQuick(partDId, partText, custName || '');
    // 料號重新綁定時，已經填了製程代號的卡片要重新查一次規格描述建議(依料號+製程複合鍵，2026-08-14使用者更正)
    // 跟要求清單(要求依綁定的料號而不同)
    $('#itemBody .pf-card').each(function(){
        var code = $(this).find('.f-proccode').val().trim();
        if (code) loadSpecDescSuggestion(code);
        var funcOptId = parseInt($(this).attr('data-func-opt-id'),10) || 0;
        if (funcOptId) loadRequirementOptionsForCard($(this), funcOptId);
    });
}
/* 手動建立的紀錄綁定料號後，比照 td_dev_eval_suggest.php 的建議建立日期機制，
   提供「套用BOM日期／套用最早報工日期／套用最早訂單日期」快速按鈕，點擊即帶入業務日期欄 */
function loadBizDateQuick(partDId, partText, custName){
    $('#fBizDateQuick').html('載入中…');
    $.getJSON(API, {action:'biz_date_suggest', part_d_id:partDId||0, part_no_text:partText||'', customer_name:custName||''}, function(res){
        if (!res.success){ $('#fBizDateQuick').html(''); return; }
        var r = res.ref || {};
        var btns = '';
        if (r.bom_created_at) btns += '<button type="button" class="pf-row-btn" onclick="$(\'#fBizDate\').val(\''+r.bom_created_at.substring(0,10)+'\')">套用BOM日期('+fmtDate(r.bom_created_at)+')</button>';
        if (r.earliest_report_date) btns += '<button type="button" class="pf-row-btn" onclick="$(\'#fBizDate\').val(\''+r.earliest_report_date.substring(0,10)+'\')">套用最早報工日期('+fmtDate(r.earliest_report_date)+')</button>';
        if (r.earliest_order_date_all_time) btns += '<button type="button" class="pf-row-btn" onclick="$(\'#fBizDate\').val(\''+r.earliest_order_date_all_time.substring(0,10)+'\')">套用最早訂單日期('+fmtDate(r.earliest_order_date_all_time)+')</button>';
        $('#fBizDateQuick').html(btns || '<span style="color:#8a6d45;font-size:12px;">查無可參考日期，請手動輸入</span>');
    });
}
function openPartDrawing(){
    // part_viewer/bom_viewer 用「料號字串」(D_Setting_Id)查表，不是 d_setting.d_id 數字主鍵
    var partNo = $('#fPartNo').val();
    if (partNo) EGPartPicker.openViewer(partNo, VIEWER_URL);
}
window.saveDeptDefaults = function(){
    var depts = $('#fDeptChecks .dept-ck:checked').map(function(){ return $(this).val(); }).get();
    $.post(API, {action:'dept_defaults_save', depts:JSON.stringify(depts)}, function(res){
        if (!res.success){ alert(res.message||'儲存失敗'); return; }
        alert('已將目前勾選的相關部門設為新建文件的預設值。');
    }, 'json');
};
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
        $('#fBizDate').val((res.doc.biz_date||'').substring(0,10));
        $('input[name=fItemType][value='+(res.doc.item_type==='assembly'?'assembly':'part')+']').prop('checked', true);
        $('#fDeptChecks').html(deptChecksHtml((res.doc.related_depts||'').split(',').filter(Boolean)));
        $('#fDocNo').text(res.doc.doc_no);
        $('#fCreatedInfo').text((res.doc.created_by_name||'')+' '+fmtDate((res.doc.created_at||'').substring(0,10)));
        renderItems(res.items || []);
        ITEM_ORIG = {};
        (res.items || []).forEach(function(it){
            if (!it.id) return;
            ITEM_ORIG[it.id] = JSON.stringify(FIELDS.filter(function(f){ return f!=='target_date' && f!=='action_date'; })
                .map(function(f){ return it[f]==null ? '' : String(it[f]); }));
        });
        onPartBound(res.doc.part_d_id||0, res.doc.part_no||'', res.doc.customer_name||'');
        openMask('editMask');
    });
}
$('#btnAdd').on('click', function(){ openEdit(0); });

EGPartPicker.attach(document.getElementById('fPartNo'), {
    apiUrl: PART_API,
    onSelect: function(row){
        $('#fPartDId').val(row.d_id);
        $('#fCustomerName').val(row.customer_name||'');
        onPartBound(row.d_id, row.part_no||row.d_id, row.customer_name||'');
        $('input[name=fItemType][value='+((row.is_assembly=='1'||row.is_assembly===1)?'assembly':'part')+']').prop('checked', true);
    }
});
$('#fPartNo').on('input', function(){ $('#fPartDId').val('0'); $('#fCustomerName').val(''); onPartBound(0, '', ''); });
$('#fPartNo').on('blur', function(){
    // 沒對到料號選擇器、純手動輸入文字料號時，離開欄位才觸發一次(避免逐字打字就狂call API)
    if (!($('#fPartDId').val()|0) && $(this).val().trim()) onPartBound(0, $(this).val().trim(), '');
});

/* 存檔前，把卡片上手動輸入、不在目前下拉清單裡的失效模式/控制預防/控制偵測/要求新值註冊進參考清單，
   下次同製程/同功能就能直接挑選（可填表人就能新增，僅管理員能刪除——見 pfmea_reference_lib.php）。
   項目/功能欄位本身已在blur當下即時註冊(resolveItemOption/resolveFunctionOption)，這裡不必重複。 */
function registerNewRefValues(){
    $('#itemBody .pf-card').each(function(){
        var $card = $(this);
        var code = $card.find('.f-proccode').val().trim();
        var pid = code && PROCESS_ID_BY_CODE[code] ? PROCESS_ID_BY_CODE[code].id : 0;
        var itemOptId = parseInt($card.attr('data-item-opt-id'),10) || 0;
        var funcOptId = parseInt($card.attr('data-func-opt-id'),10) || 0;
        if (pid) {
            var fm = $card.find('[data-f="failure_mode"]').val().trim();
            var known = $card.find('datalist.dl-failure_mode option').map(function(){ return this.value; }).get();
            if (fm && known.indexOf(fm) < 0) $.post(API, {action:'ref_failure_mode_add', process_id:pid, failure_mode:fm, item_option_id:itemOptId, function_option_id:funcOptId});
        }
        if (funcOptId || pid) {
            var req = $card.find('[data-f="requirement"]').val().trim();
            var knownReq = $card.find('datalist.dl-requirement option').map(function(){ return this.value; }).get();
            if (req && knownReq.indexOf(req) < 0) {
                var partDId = $('#fPartDId').val() || 0;
                var partText = (partDId|0) ? '' : $('#fPartNo').val();
                $.post(API, {action:'ref_requirement_option_add', function_option_id:funcOptId, process_id:pid, part_d_id:partDId, part_no_text:partText, requirement_text:req});
            }
        }
        ['prevention_controls','detection_controls'].forEach(function(f){
            var type = f === 'prevention_controls' ? 'prevention' : 'detection';
            var v = $card.find('[data-f="'+f+'"]').val().trim();
            var known2 = (CONTROL_OPTIONS[type]||[]).map(function(o){ return o.option_text; });
            if (v && known2.indexOf(v) < 0) $.post(API, {action:'ref_control_option_add', option_type:type, option_text:v});
        });
        // 欄位個別設定對應：潛在失效模式->失效模式潛在後果/分類/失效潛在原因，手動輸入的新組合順便註冊
        var fmVal = $card.find('[data-f="failure_mode"]').val().trim();
        if (fmVal) {
            ['failure_effect','classification','failure_cause'].forEach(function(f){
                var v = $card.find('[data-f="'+f+'"]').val().trim();
                var known3 = $card.find('datalist.dl-'+f+' option').map(function(){ return this.value; }).get();
                if (v && known3.indexOf(v) < 0) $.post(API, {action:'field_link_add', source_field:'failure_mode', source_value:fmVal, target_field:f, target_value:v});
            });
        }
        // 評價S/O/D建議規則：只有新增列(尚未存檔)才登記，存檔後鎖定不回頭改規則
        if (!($card.attr('data-id')|0)) {
            var k = ratingRuleKey($card);
            var ns2 = parseInt($card.find('[data-f="new_severity"]').val(),10) || 0;
            var no2 = parseInt($card.find('[data-f="new_occurrence"]').val(),10) || 0;
            var nd2 = parseInt($card.find('[data-f="new_detection"]').val(),10) || 0;
            if (k.process_id && k.failure_mode && k.failure_effect && k.severity && k.failure_cause && ns2 && no2 && nd2) {
                $.post(API, {action:'rating_rule_add', process_id:k.process_id, item_option_id:k.item_option_id, function_option_id:k.function_option_id,
                    failure_mode:k.failure_mode, failure_effect:k.failure_effect, severity:k.severity, failure_cause:k.failure_cause,
                    new_severity:ns2, new_occurrence:no2, new_detection:nd2});
            }
        }
    });
    // 料號＋製程代號->規格描述（2026-08-14使用者更正：要多一層製程代號，不能只依料號/產品名稱）
    // 一份文件可能有多張卡片用不同製程，逐一為文件目前用到的每個製程代號都登記一次複合鍵
    var pnNo = $('#fPartNo').val().trim(), sd = $('#fSpecDesc').val().trim();
    if (pnNo && sd) {
        var seenCodes = {};
        $('#itemBody .pf-card').each(function(){
            var code = $(this).find('.f-proccode').val().trim();
            if (!code || seenCodes[code]) return;
            seenCodes[code] = true;
            $.post(API, {action:'field_link_add', source_field:'part_process', source_value:pnNo+PART_PROCESS_SEP+code, target_field:'spec_desc', target_value:sd});
        });
    }
}
/* 編輯既有文件時，若某列的分析內容(不含目標完成日/生效日期本身)有異動，詢問是否要一併把該列的
   目標完成日/生效日期更新為業務日期(2026-08-13使用者要求) */
function maybeUpdateItemDates(){
    if (!CUR_ID) return;
    var bizDate = $('#fBizDate').val();
    if (!bizDate) return;
    var changed = [];
    $('#itemBody .pf-card').each(function(){
        var $card = $(this);
        var id = parseInt($card.attr('data-id'),10) || 0;
        if (!id || !ITEM_ORIG[id]) return;
        var cur = JSON.stringify(FIELDS.filter(function(f){ return f!=='target_date' && f!=='action_date'; })
            .map(function(f){ return $card.find('[data-f="'+f+'"]').val() || ''; }));
        if (cur !== ITEM_ORIG[id]) changed.push($card);
    });
    if (!changed.length) return;
    if (!confirm('偵測到 '+changed.length+' 項失效模式分析內容有異動，是否將這些項目的「目標完成日／生效日期」一併更新為業務日期('+bizDate+')？\n（否＝維持原日期不變）')) return;
    changed.forEach(function($card){
        $card.find('[data-f="target_date"]').val(bizDate);
        $card.find('[data-f="action_date"]').val(bizDate);
    });
}
function saveHeader(){
    maybeUpdateItemDates();
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
        biz_date: $('#fBizDate').val(),
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
/* 列印／檢視共用：組出表頭合併表格+分析表格的body與css，printDoc開新視窗直接印，viewDoc(第8段
   使用者要求的唯讀檢視功能)則塞進本頁iframe跳窗顯示，兩者版面完全一致，避免維護兩份 */
function buildPrintDoc(res){
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
        var isAsm = d.item_type === 'assembly';
        var classifyBox = (isAsm?'□':'■')+'零件　'+(isAsm?'■':'□')+'組合件';
        var revRows = '';
        (res.revisions||[]).forEach(function(r){
            revRows += '<tr><td>'+esc(r.rev_no)+'</td><td>'+fmtDate(r.rev_date)+'</td><td>'+esc(r.rev_content)+'</td><td>'+esc(r.prepared_by_name||'')+'</td></tr>';
        });
        // 表頭資訊/S/O/D/RPN/相關部門合併成「同一張表」的視覺效果（2026-08-13使用者比對Excel原始檔
        // 後要求：像Excel把欄寬設很窄再合併儲存格那樣）。改用CSS Grid（固定列高的格線）取代HTML
        // table+rowspan：table的列高會依內容自動撐高、不同欄位撐高幅度不一致就會彼此對不齊(實測
        // 發現「原本置中的字跑掉」)；Grid的固定列高格線不會被內容撐大，才能真正對齊，比照Excel
        // 固定列高、內容溢出不影響格線的行為。
        function gridTwoCol(colStart, blocks, cls1, cls2){ // blocks:[[span,colA,colB],...] span合計需為9(格線列數)
            var html = ''; var r = 2; // 第1列格線留給群組標題(嚴重度(S)等)
            blocks.forEach(function(b){
                html += '<div class="hdc'+(cls1?' '+cls1:'')+'" style="grid-column:'+colStart+';grid-row:'+r+'/span '+b[0]+';">'+b[1]+'</div>'
                    + '<div class="hdc'+(cls2?' '+cls2:'')+'" style="grid-column:'+(colStart+1)+';grid-row:'+r+'/span '+b[0]+';">'+b[2]+'</div>';
                r += b[0];
            });
            return html;
        }
        var hdGrid = ''
            + '<div class="hdh" style="grid-column:1/span 2;grid-row:1;"></div>'
            + '<div class="hdh" style="grid-column:3/span 2;grid-row:1;">嚴重度(S)</div>'
            + '<div class="hdh" style="grid-column:5/span 2;grid-row:1;">發生率(O)</div>'
            + '<div class="hdh" style="grid-column:7/span 2;grid-row:1;">偵測度(D)</div>'
            + '<div class="hdh" style="grid-column:9/span 2;grid-row:1;">風險優先指數(RPN)</div>'
            + '<div class="hdh" style="grid-column:11/span 2;grid-row:1;">相關部門</div>'
            + gridTwoCol(1, [
                [2,'料號',esc(d.part_no||'')], [2,'分類',classifyBox], [2,'規格描述',esc(d.spec_desc||'')],
                [2,'產品名稱',esc(d.product_name||'')], [1,'客戶名稱',esc(d.customer_name||'')],
              ], 'hdc-label', 'hdc-value')
            + gridTwoCol(3, [
                [2,'1','無影響'], [2,'2','次要阻礙'], [2,'3~6','中等阻礙'],
                [1,'7','顯著阻礙'], [1,'8','嚴重阻礙'], [1,'9~10','安全/法規失效'],
              ])
            + gridTwoCol(5, [
                [2,'1','很低'], [2,'2~3','低'], [2,'4~6','中等'], [2,'7~9','高'], [1,'10','很高'],
              ])
            + gridTwoCol(7, [
                [1,'1','幾乎確定'], [1,'2','極高'], [1,'3','高'], [1,'4','高中等'], [1,'5','中等'],
                [1,'6','低'], [1,'7','非常低'], [1,'8~9','可能性極小'], [1,'10','幾乎不可能'],
              ])
            + gridTwoCol(9, [
                [3,'1~50','低'], [2,'51~100','普通'], [2,'101~200','高'], [2,'201~1000','非常高'],
              ])
            + gridTwoCol(11, DEPT_LIST.map(function(dp,i){
                return [i===0?2:1, esc(dp), depts.indexOf(dp)>=0?'v':''];
              }), 'hdc-deptname', 'hdc-deptck');
        var body = '<div class="p-topwrap">'
            + '<div class="p-comp-wrap"><div class="p-comp">'+esc(res.company_name)+'</div><div class="p-title">'+esc(res.as_doc_name)+'</div></div>'
            + '<table class="p-rev"><thead><tr><th>編號</th><th>日期</th><th>修訂內容</th><th>準備</th></tr></thead><tbody>'+revRows+'</tbody></table>'
            + '</div>'
            + '<div class="p-hdgrid">'+hdGrid+'</div>'
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
            // 表頭資訊+評級對照表+相關部門，比照Excel原始檔版面：改用CSS Grid固定列高格線（不是HTML
            // table+rowspan——table的列高會依內容自動撐高，不同欄位撐高幅度不一致就會彼此對不齊，
            // 使用者實測發現「原本置中的字跑掉」正是這個根因；Grid的固定列高格線不會被內容撐大，
            // 這裡才能真正做到像Excel那樣所有欄位共用同一套格線，2026-08-13使用者比對Excel畫面後要求）
            + '.p-hdgrid{display:grid;grid-template-columns:8% 16% 4% 9% 4% 8% 4% 9% 6% 8% 20% 4%;'
            + 'grid-auto-rows:10px;font-size:8px;margin-bottom:6px;border-top:1px solid #666;border-left:1px solid #666;}'
            + '.p-hdgrid > div{border-right:1px solid #666;border-bottom:1px solid #666;padding:0 3px;'
            + 'display:flex;align-items:center;justify-content:center;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;}'
            + '.p-hdgrid .hdh{background:#f3ead6;font-weight:bold;}'
            + '.p-hdgrid .hdc-label{background:#f3ead6;font-weight:bold;justify-content:flex-start;}'
            + '.p-hdgrid .hdc-value{justify-content:flex-start;white-space:normal;overflow-wrap:anywhere;line-height:1.15;}'
            + '.p-hdgrid .hdc-deptname{justify-content:flex-start;}'
            + '.p-hdgrid .hdc-deptck{font-weight:bold;}'
            + 'table.p-tb{width:100%;table-layout:fixed;border-collapse:collapse;font-size:8px;}'
            + 'table.p-tb thead{display:table-header-group;}'
            + 'table.p-tb th,table.p-tb td{border:1px solid #666;padding:2px 3px;text-align:center;overflow-wrap:anywhere;}'
            + 'table.p-tb thead th{background:#f3ead6;} table.p-tb td.tl{text-align:left;}'
            + 'table.p-tb tr{break-inside:avoid;}'
            + '@page{margin:10mm 8mm 16mm;size:A4 landscape;'
            + (res.as_doc_no ? " @bottom-right{ content:'"+String(res.as_doc_no).replace(/['\\]/g,'')+"'; font-size:9pt; color:#333; vertical-align:top; padding-top:1mm; }" : '')
            + '}';
    return {css:css, body:body, partNo:d.part_no||''};
}
function printDoc(id){
    $.getJSON(API, {action:'print_get', id:id}, function(res){
        if (!res.success){ alert(res.message||'載入失敗'); return; }
        var pd = buildPrintDoc(res);
        var w = window.open('', '_blank');
        w.document.write('<html><head><meta charset="utf-8"><title>PFMEA潛在失效模式及效應分析</title><style>'+pd.css+'</style></head><body>'+pd.body
            +'<scr'+'ipt>window.onload=function(){'
            +'var st=document.createElement(\'style\');'
            +'st.textContent="@page{ @bottom-left{ content:\'第 \' counter(page) \' 頁／共 \' counter(pages) \' 頁\'; font-size:9pt; color:#333; vertical-align:top; padding-top:1mm; } }";'
            +'document.head.appendChild(st);'
            +'setTimeout(function(){window.print();},200);};</scr'+'ipt></body></html>');
        w.document.close();
    });
}
/* ---------- 檢視（唯讀，2026-08-13使用者要求第8段）：跟列印版面完全一樣，塞進本頁iframe跳窗顯示
   （不觸發列印動作），另外提供「評級對照表說明」（沿用既有ratingInfoMask）跟「開圖」按鈕 ---------- */
var VIEW_PART_NO = '';
function viewDoc(id){
    $.getJSON(API, {action:'print_get', id:id}, function(res){
        if (!res.success){ alert(res.message||'載入失敗'); return; }
        var pd = buildPrintDoc(res);
        VIEW_PART_NO = pd.partNo;
        $('#btnViewDrawing').toggle(!!VIEW_PART_NO);
        openMask('viewMask');
        var doc = document.getElementById('viewFrame').contentWindow.document;
        doc.open();
        doc.write('<html><head><meta charset="utf-8"><style>'+pd.css+'body{padding:6mm;}</style></head><body>'+pd.body+'</body></html>');
        doc.close();
    });
}
window.openViewDrawing = function(){ if (VIEW_PART_NO) EGPartPicker.openViewer(VIEW_PART_NO, VIEWER_URL); };

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

/* ---------- 參考資料設定（2026-08-14使用者要求：找不到能個別設定各欄下拉選單/階層的地方）----------
 * 製程代號→項目→功能 逐層鑽取，潛在失效模式/要求隨鑽取深度自動切換到目前最深層級；控制預防/控制
 * 偵測為全域清單獨立顯示。刪除只影響清單設定本身，不會動到已經填寫存檔的分析表資料。 */
function rsRow(scope, id, label, activeId){
    return '<div class="rs-row'+(activeId===id?' active':'')+'" data-scope="'+scope+'" data-id="'+id+'"><span>'+esc(label)+'</span>'
        + (CAN_ADMIN ? '<i class="fa fa-trash" title="刪除"></i>' : '') + '</div>';
}
function rsLoadProcessList(){
    $.getJSON(API, {action:'ref_process_list'}, function(res){
        if (!res.success) return;
        var html = (res.rows||[]).map(function(p){ return rsRow('process', p.id, p.process_code+' '+p.process_name, RS_PROC_ID); }).join('');
        $('#rsProcessList').html(html || '<div class="rs-empty">尚無資料</div>');
    });
}
function rsSelectProcess(id, label){
    RS_PROC_ID = id; RS_ITEM_OPT_ID = 0; RS_FUNC_OPT_ID = 0;
    $('#rsCurProcessLabel').text(label);
    $('#rsProcessScope').show(); $('#rsItemScope').hide();
    rsLoadProcessList(); rsLoadItems(); rsLoadFm(); rsLoadReq();
}
window.rsAddProcess = function(){
    var code = $('#rsProcCodeNew').val().trim(), name = $('#rsProcNameNew').val().trim();
    if (!code || !name){ alert('請輸入製程代號與名稱'); return; }
    $.post(API, {action:'ref_process_add', process_code:code, process_name:name}, function(res){
        if (!res.success){ alert(res.message||'新增失敗'); return; }
        $('#rsProcCodeNew,#rsProcNameNew').val('');
        loadProcessList(); rsLoadProcessList();
    }, 'json');
};
function rsLoadItems(){
    if (!RS_PROC_ID) return;
    $.getJSON(API, {action:'ref_item_options_list', process_id:RS_PROC_ID}, function(res){
        if (!res.success) return;
        var html = (res.rows||[]).map(function(it){ return rsRow('item', it.id, it.item_name, RS_ITEM_OPT_ID); }).join('');
        $('#rsItemList').html(html || '<div class="rs-empty">尚無資料</div>');
    });
}
function rsSelectItem(id, label){
    RS_ITEM_OPT_ID = id; RS_FUNC_OPT_ID = 0;
    $('#rsCurItemLabel').text(label);
    $('#rsItemScope').show();
    rsLoadItems(); rsLoadFuncs(); rsLoadFm(); rsLoadReq();
}
window.rsAddItem = function(){
    var name = $('#rsItemNew').val().trim();
    if (!RS_PROC_ID || !name) return;
    $.post(API, {action:'ref_item_option_add', process_id:RS_PROC_ID, item_name:name}, function(res){
        if (!res.success){ alert(res.message||'新增失敗'); return; }
        $('#rsItemNew').val(''); rsLoadItems();
    }, 'json');
};
function rsLoadFuncs(){
    if (!RS_ITEM_OPT_ID) return;
    $.getJSON(API, {action:'ref_function_options_list', item_option_id:RS_ITEM_OPT_ID}, function(res){
        if (!res.success) return;
        var html = (res.rows||[]).map(function(f){ return rsRow('func', f.id, f.function_desc, RS_FUNC_OPT_ID); }).join('');
        $('#rsFuncList').html(html || '<div class="rs-empty">尚無資料</div>');
    });
}
function rsSelectFunc(id, label){
    RS_FUNC_OPT_ID = id;
    rsLoadFuncs(); rsLoadFm(); rsLoadReq();
}
window.rsAddFunc = function(){
    var name = $('#rsFuncNew').val().trim();
    if (!RS_ITEM_OPT_ID || !name) return;
    $.post(API, {action:'ref_function_option_add', item_option_id:RS_ITEM_OPT_ID, function_desc:name}, function(res){
        if (!res.success){ alert(res.message||'新增失敗'); return; }
        $('#rsFuncNew').val(''); rsLoadFuncs();
    }, 'json');
};
function rsLoadFm(){
    $('#rsFmScopeLabel').text(RS_FUNC_OPT_ID ? '此功能專屬' : (RS_ITEM_OPT_ID ? '此項目通用' : '此製程通用'));
    $.getJSON(API, {action:'ref_failure_mode_list_exact', process_id:RS_PROC_ID, item_option_id:RS_ITEM_OPT_ID, function_option_id:RS_FUNC_OPT_ID}, function(res){
        if (!res.success) return;
        var html = (res.rows||[]).map(function(r){ return rsRow('fm', r.id, r.failure_mode, -1); }).join('');
        $('#rsFmList').html(html || '<div class="rs-empty">尚無資料</div>');
    });
}
window.rsAddFm = function(){
    var text = $('#rsFmNew').val().trim();
    if (!RS_PROC_ID || !text) return;
    $.post(API, {action:'ref_failure_mode_add', process_id:RS_PROC_ID, failure_mode:text, item_option_id:RS_ITEM_OPT_ID, function_option_id:RS_FUNC_OPT_ID}, function(res){
        if (!res.success){ alert(res.message||'新增失敗'); return; }
        $('#rsFmNew').val(''); rsLoadFm();
    }, 'json');
};
function rsLoadReq(){
    $('#rsReqScopeLabel').text(RS_FUNC_OPT_ID ? '此功能' : '此製程');
    $.getJSON(API, {action:'ref_requirement_list_exact', process_id:RS_PROC_ID, function_option_id:RS_FUNC_OPT_ID}, function(res){
        if (!res.success) return;
        var html = (res.rows||[]).map(function(r){
            return rsRow('req', r.id, r.requirement_text + (r.part_label ? '　['+r.part_label+']' : '　[通用]'), -1);
        }).join('');
        $('#rsReqList').html(html || '<div class="rs-empty">尚無資料</div>');
    });
}
window.rsAddReq = function(){
    var text = $('#rsReqNew').val().trim();
    if (!RS_PROC_ID || !text) return;
    $.post(API, {action:'ref_requirement_option_add', function_option_id:RS_FUNC_OPT_ID, process_id:RS_PROC_ID, requirement_text:text}, function(res){
        if (!res.success){ alert(res.message||'新增失敗'); return; }
        $('#rsReqNew').val(''); rsLoadReq();
    }, 'json');
};
function rsLoadControlLists(){
    $.getJSON(API, {action:'ref_control_options'}, function(res){
        if (!res.success) return;
        CONTROL_OPTIONS = res.options || {prevention:[], detection:[], action:[]};
        $('#rsPrevList').html((res.options.prevention||[]).map(function(o){ return rsRow('prevention', o.id, o.option_text, -1); }).join('') || '<div class="rs-empty">尚無資料</div>');
        $('#rsDetList').html((res.options.detection||[]).map(function(o){ return rsRow('detection', o.id, o.option_text, -1); }).join('') || '<div class="rs-empty">尚無資料</div>');
        $('#rsActionList').html((res.options.action||[]).map(function(o){ return rsRow('action', o.id, o.option_text, -1); }).join('') || '<div class="rs-empty">尚無資料</div>');
    });
}
window.rsAddControl = function(type){
    var $input = type==='prevention' ? $('#rsPrevNew') : (type==='detection' ? $('#rsDetNew') : $('#rsActionNew'));
    var text = $input.val().trim();
    if (!text) return;
    $.post(API, {action:'ref_control_option_add', option_type:type, option_text:text}, function(res){
        if (!res.success){ alert(res.message||'新增失敗'); return; }
        $input.val(''); rsLoadControlLists();
    }, 'json');
};
function rsDeleteAction(scope){
    return {process:'ref_process_delete', item:'ref_item_option_delete', func:'ref_function_option_delete',
        fm:'ref_failure_mode_delete', req:'ref_requirement_option_delete',
        prevention:'ref_control_option_delete', detection:'ref_control_option_delete', action:'ref_control_option_delete', link:'field_link_delete'}[scope];
}
function rsReloadForScope(scope){
    if (scope==='process') { rsLoadProcessList(); loadProcessList(); }
    else if (scope==='item') rsLoadItems();
    else if (scope==='func') rsLoadFuncs();
    else if (scope==='fm') rsLoadFm();
    else if (scope==='req') rsLoadReq();
    else if (scope==='link') rsLoadLinkTargets();
    else rsLoadControlLists();
}
$(document).on('click', '#refSettingsMask .rs-list .fa-trash', function(e){
    e.stopPropagation();
    var $row = $(this).closest('.rs-row');
    var scope = $row.attr('data-scope'), id = parseInt($row.attr('data-id'),10);
    if (!confirm('確定刪除？（僅影響此清單設定，不影響已填寫存檔的分析表資料）')) return;
    $.post(API, {action:rsDeleteAction(scope), id:id}, function(res){
        if (!res.success){ alert(res.message||'刪除失敗'); return; }
        rsReloadForScope(scope);
    }, 'json');
});
$(document).on('click', '#refSettingsMask .rs-row', function(){
    var scope = $(this).attr('data-scope'), id = parseInt($(this).attr('data-id'),10), label = $(this).find('span').text();
    if (scope === 'process') rsSelectProcess(id, label);
    else if (scope === 'item') rsSelectItem(id, label);
    else if (scope === 'func') rsSelectFunc(id, label);
});
window.switchRsTab = function(tab){
    $('#refSettingsMask .pf-rt-tab').removeClass('active');
    $('#refSettingsMask .pf-rt-tab[data-rstab="'+tab+'"]').addClass('active');
    $('#refSettingsMask .pf-rt-pane').hide();
    $('#refSettingsMask .pf-rt-pane[data-rstab="'+tab+'"]').show();
};

/* ---------- 製程開放使用設定（2026-08-14使用者要求）----------
 * 製程代號改從全站製程主檔(process_no/process_type)同步；大項分類可一鍵全選/取消全選底下製程，
 * 仍可個別勾選調整；只有勾選開放的製程才會出現在分析表的製程代號下拉，避免全公司205筆製程一次
 * 全塞進去難以選擇。 */
function rsRenderProcessEnableList(){
    $.getJSON(API, {action:'process_list_all'}, function(res){
        if (!res.success) return;
        var byCat = {};
        (res.rows||[]).forEach(function(p){
            var cat = p.category_name || '（未分類／尚未同步主檔）';
            (byCat[cat] = byCat[cat] || []).push(p);
        });
        var html = '';
        Object.keys(byCat).sort().forEach(function(cat){
            var procs = byCat[cat];
            var allChecked = procs.every(function(p){ return p.is_enabled==1; });
            var typeId = procs[0].master_type_id || 0;
            html += '<div class="rs-cat-block">'
                + '<label class="rs-cat-hd"><input type="checkbox" class="rs-cat-ck" data-type="'+typeId+'"'+(allChecked?' checked':'')+'> <b>'+esc(cat)+'</b>　('+procs.length+'個)</label>';
            procs.forEach(function(p){
                var badge = p.fm_count > 0 ? ' <span style="color:#b5762a;">（已設定'+p.fm_count+'個潛在失效模式）</span>' : '';
                html += '<label class="rs-proc-ck-row"><input type="checkbox" class="rs-proc-ck" data-id="'+p.id+'"'+(p.is_enabled==1?' checked':'')+'> '+esc(p.process_code)+' '+esc(p.process_name)+badge+'</label>';
            });
            html += '</div>';
        });
        $('#rsProcessEnableList').html(html || '<div class="rs-empty">尚無資料，請先按「從全站製程主檔同步」</div>');
    });
}
window.rsSyncProcesses = function(){
    if (!confirm('從全站製程主檔（process_no）重新同步製程清單？\n已存在的製程代號不會被覆蓋名稱，只會補上大項分類關聯；新出現的製程預設不開放使用，需另外勾選。')) return;
    $.post(API, {action:'process_sync_master'}, function(res){
        if (!res.success){ alert(res.message||'同步失敗'); return; }
        alert('同步完成：新增 '+res.created+' 筆，補連結 '+res.linked+' 筆（主檔共 '+res.total_master+' 筆製程）。');
        rsRenderProcessEnableList(); loadProcessList();
    }, 'json');
};
window.rsEnableConfigured = function(){
    $.post(API, {action:'process_enable_configured'}, function(res){
        if (!res.success){ alert(res.message||'設定失敗'); return; }
        alert('已將 '+res.enabled+' 個已設定過潛在失效模式、但尚未開放的製程改為開放使用。');
        rsRenderProcessEnableList(); loadProcessList();
    }, 'json');
};
$(document).on('change', '#rsProcessEnableList .rs-proc-ck', function(){
    var id = parseInt($(this).data('id'),10), checked = this.checked;
    $.post(API, {action:'process_set_enabled', ids:JSON.stringify([id]), enabled:checked?1:0}, function(res){
        if (!res.success){ alert(res.message||'設定失敗'); return; }
        loadProcessList(); rsLoadProcessList();
    }, 'json');
    var $block = $(this).closest('.rs-cat-block');
    $block.find('.rs-cat-ck').prop('checked', $block.find('.rs-proc-ck').length === $block.find('.rs-proc-ck:checked').length);
});
$(document).on('change', '#rsProcessEnableList .rs-cat-ck', function(){
    var typeId = parseInt($(this).data('type'),10), checked = this.checked;
    var $block = $(this).closest('.rs-cat-block');
    $block.find('.rs-proc-ck').prop('checked', checked);
    if (!typeId) {
        var ids = $block.find('.rs-proc-ck').map(function(){ return parseInt($(this).data('id'),10); }).get();
        $.post(API, {action:'process_set_enabled', ids:JSON.stringify(ids), enabled:checked?1:0}, function(res){
            if (res.success) { loadProcessList(); rsLoadProcessList(); }
        }, 'json');
        return;
    }
    $.post(API, {action:'process_set_enabled_by_type', master_type_id:typeId, enabled:checked?1:0}, function(res){
        if (res.success) { loadProcessList(); rsLoadProcessList(); }
    }, 'json');
});

/* ---------- 整組樣板 新增/編輯/刪除（2026-08-14使用者要求：不再只能查看/刪除）---------- */
var RS_TPL_ROWS = [];
function rsLoadTplProcSel(){
    $.getJSON(API, {action:'ref_process_list'}, function(res){
        if (!res.success) return;
        var $sel = $('#rsTplProcSel');
        var cur = $sel.val();
        $sel.html((res.rows||[]).map(function(p){ return '<option value="'+p.id+'">'+esc(p.process_code)+' '+esc(p.process_name)+'</option>'; }).join(''));
        if (cur) $sel.val(cur);
        rsLoadTplList();
    });
}
function rsLoadTplList(){
    var pid = parseInt($('#rsTplProcSel').val(),10) || 0;
    $('#rsTplForm').hide();
    if (!pid) { $('#rsTplBody').html(''); return; }
    $.getJSON(API, {action:'ref_item_templates', process_id:pid}, function(res){
        if (!res.success) return;
        RS_TPL_ROWS = res.rows || [];
        var html = RS_TPL_ROWS.map(function(t){
            var rpn = (t.severity&&t.occurrence&&t.detection) ? (t.severity*t.occurrence*t.detection) : '';
            return '<tr><td class="pf-op" style="cursor:pointer;color:#b5762a;text-decoration:underline;" onclick="rsOpenTplForm('+t.id+')">'+esc(t.group_name)+'</td>'
                + '<td>'+esc(t.severity)+'</td><td>'+esc(t.occurrence)+'</td><td>'+esc(t.detection)+'</td><td>'+rpn+'</td>'
                + '<td><i class="fa fa-trash pf-op" title="刪除" onclick="rsDeleteTpl('+t.id+')"></i></td></tr>';
        }).join('');
        $('#rsTplBody').html(html || '<tr><td colspan="6" class="rs-empty">此製程尚無樣板</td></tr>');
    });
}
window.rsOpenTplForm = function(id){
    var t = id ? RS_TPL_ROWS.find(function(r){ return r.id===id; }) : {};
    t = t || {};
    $('#rsTplId').val(id||0);
    $('#rsTplItemName').val(t.item_name||''); $('#rsTplFunctionDesc').val(t.function_desc||'');
    $('#rsTplFailureMode').val(t.failure_mode||''); $('#rsTplFailureEffect').val(t.failure_effect||'');
    $('#rsTplSeverity').val(t.severity||''); $('#rsTplFailureCause').val(t.failure_cause||'');
    $('#rsTplOccurrence').val(t.occurrence||''); $('#rsTplPrevention').val(t.prevention_controls||'');
    $('#rsTplDetectionCtrl').val(t.detection_controls||''); $('#rsTplDetection').val(t.detection||'');
    $('#rsTplRecAction').val(t.recommended_actions||''); $('#rsTplNewSeverity').val(t.new_severity||'');
    $('#rsTplNewOccurrence').val(t.new_occurrence||''); $('#rsTplNewDetection').val(t.new_detection||'');
    $('#rsTplForm').show();
};
window.rsSaveTpl = function(){
    var pid = parseInt($('#rsTplProcSel').val(),10) || 0;
    if (!pid){ alert('請先選擇製程'); return; }
    var data = {
        item_name:$('#rsTplItemName').val(), function_desc:$('#rsTplFunctionDesc').val(),
        failure_mode:$('#rsTplFailureMode').val(), failure_effect:$('#rsTplFailureEffect').val(),
        severity:$('#rsTplSeverity').val(), failure_cause:$('#rsTplFailureCause').val(),
        occurrence:$('#rsTplOccurrence').val(), prevention_controls:$('#rsTplPrevention').val(),
        detection_controls:$('#rsTplDetectionCtrl').val(), detection:$('#rsTplDetection').val(),
        recommended_actions:$('#rsTplRecAction').val(), new_severity:$('#rsTplNewSeverity').val(),
        new_occurrence:$('#rsTplNewOccurrence').val(), new_detection:$('#rsTplNewDetection').val(),
    };
    $.post(API, {action:'ref_item_template_save', id:$('#rsTplId').val()||0, process_id:pid, data:JSON.stringify(data)}, function(res){
        if (!res.success){ alert(res.message||'儲存失敗'); return; }
        $('#rsTplForm').hide(); rsLoadTplList();
    }, 'json');
};
window.rsDeleteTpl = function(id){
    if (!confirm('確定刪除此整組樣板？（不影響已套用過的分析表資料）')) return;
    $.post(API, {action:'ref_item_template_delete', id:id}, function(res){
        if (!res.success){ alert(res.message||'刪除失敗'); return; }
        rsLoadTplList();
    }, 'json');
};
$('#rsTplProcSel').on('change', rsLoadTplList);

/* ---------- 欄位個別設定對應 瀏覽/新增/刪除（2026-08-14使用者要求）---------- */
var RS_LINK_SOURCE = '';
function rsLinkPair(){ return ($('#rsLinkPairSel').val()||'').split('|'); }
function rsMarkLinkSourceActive(v){
    $('#rsLinkSourceList .rs-row').removeClass('active');
    $('#rsLinkSourceList .rs-row').filter(function(){ return $(this).attr('data-src')===v; }).addClass('active');
}
/* 料號＋製程代號 → 規格描述：來源是複合鍵(partNo+'｜'+processCode)存在同一個source_value欄位裡，
   沿用field_link通用機制不必另建表；填表時卡片的製程代號一變更、且文件已綁定料號，就用這個複合鍵
   查建議規格描述（2026-08-14使用者更正：規格描述要依「料號+當下這張卡片的製程」才對，不是只依料號）。 */
var PART_PROCESS_SEP = '｜';
function rsPartProcessLabel(v){
    var parts = v.split(PART_PROCESS_SEP);
    var procName = (PROCESS_ID_BY_CODE[parts[1]] || {}).process_name || '';
    return '料號 '+parts[0]+'　製程 '+(parts[1]||'')+(procName?'('+procName+')':'');
}
function rsLoadLinkSources(){
    var pair = rsLinkPair();
    $('#rsLinkSourceLabel').text($('#rsLinkPairSel option:selected').text().split(' → ')[0]);
    $('#rsLinkTargetLabel').text($('#rsLinkPairSel option:selected').text().split(' → ')[1]);
    RS_LINK_SOURCE = ''; $('#rsLinkTargetList').html(''); $('#rsLinkTargetNew,#rsLinkTargetAddBtn').prop('disabled', true);
    $('#rsLinkSourceNewText').val(''); $('#rsLinkSourceNewPart').val('');
    rsUpdatePartBoundStatus(false);
    var isPartProcess = pair[0] === 'part_process';
    $('#rsLinkSourceNewTextBox').toggle(!isPartProcess);
    $('#rsLinkSourceNewPartProcessBox').toggle(isPartProcess);
    if (isPartProcess) {
        $('#rsLinkSourceNewProc').html((PROCESS_LIST||[]).map(function(p){ return '<option value="'+esc(p.process_code)+'">'+esc(p.process_code)+' '+esc(p.process_name)+'</option>'; }).join(''));
    }
    // 潛在失效模式：改用系統全部已知的失效模式(不分製程)，不再只看「已經設定過對應值」的來源，
    // 否則已建立但還沒設定過後果/原因的失效模式會找不到、無從新增（2026-08-14使用者要求）
    var action = pair[0] === 'failure_mode' ? 'field_link_all_failure_modes' : 'field_link_distinct_sources';
    var targetLabel = $('#rsLinkTargetLabel').text();
    $.getJSON(API, {action:action, source_field:pair[0], target_field:pair[1]}, function(res){
        if (!res.success) return;
        // 每筆帶目標值預覽(preview)，來源清單不用點進去就能大概看到已經設定了什麼
        // （2026-08-14使用者要求：料號+製程代號右側要能直接看到對應的目標值）
        var html = (res.rows||[]).map(function(r){
            var label = isPartProcess ? rsPartProcessLabel(r.value) : r.value;
            if (r.preview) label += '　'+targetLabel+'：'+r.preview;
            return '<div class="rs-row" data-src="'+esc(r.value)+'"><span>'+esc(label)+'</span></div>';
        }).join('');
        $('#rsLinkSourceList').html(html || '<div class="rs-empty">尚無資料，可用上方欄位新增</div>');
    });
}
window.rsSelectNewFailureModeSource = function(){
    var v = $('#rsLinkSourceNewText').val().trim();
    if (!v) return;
    RS_LINK_SOURCE = v;
    rsMarkLinkSourceActive(v);
    $('#rsLinkTargetNew,#rsLinkTargetAddBtn').prop('disabled', false);
    rsLoadLinkTargets();
};
window.rsSelectNewPartProcessSource = function(){
    var part = $('#rsLinkSourceNewPart').val().trim();
    var proc = $('#rsLinkSourceNewProc').val();
    if (!part || !proc) { alert('請輸入料號並選擇製程代號'); return; }
    if (!RS_LINK_PART_BOUND && !confirm('目前輸入的料號「'+part+'」不是從清單選定的，尚未綁定到真正的料號ID（只是純文字）。\n若這是舊料號/已無主檔資料的料號可以繼續；若只是打錯字，建議從下拉清單重新選擇。\n仍要繼續新增嗎？')) return;
    RS_LINK_SOURCE = part + PART_PROCESS_SEP + proc;
    rsMarkLinkSourceActive(RS_LINK_SOURCE);
    $('#rsLinkTargetNew,#rsLinkTargetAddBtn').prop('disabled', false);
    rsLoadLinkTargets();
};
/* 已選定料號提示（2026-08-14使用者要求）：從清單選到真正的料號ID才算「已綁定」，純打字沒有真的
   選到清單裡的項目時要清楚提示，避免使用者以為已經綁定成功、實際上只是存了一段文字 */
var RS_LINK_PART_BOUND = false;
function rsUpdatePartBoundStatus(bound){
    RS_LINK_PART_BOUND = bound;
    if (bound) $('#rsLinkPartBoundStatus').html('<span style="color:#3a8f4a;"><i class="fa fa-check-circle"></i> 已綁定料號</span>');
    else if ($('#rsLinkSourceNewPart').val().trim()) $('#rsLinkPartBoundStatus').html('<span style="color:#DD5138;"><i class="fa fa-exclamation-circle"></i> 尚未綁定料號ID（純文字，請從清單選擇）</span>');
    else $('#rsLinkPartBoundStatus').html('');
}
EGPartPicker.attach(document.getElementById('rsLinkSourceNewPart'), {
    apiUrl: PART_API,
    onSelect: function(row){ rsUpdatePartBoundStatus(true); }
});
$(document).on('input', '#rsLinkSourceNewPart', function(){ rsUpdatePartBoundStatus(false); });
function rsLoadLinkTargets(){
    var pair = rsLinkPair();
    if (!RS_LINK_SOURCE) return;
    $.getJSON(API, {action:'field_link_list', source_field:pair[0], source_value:RS_LINK_SOURCE, target_field:pair[1]}, function(res){
        if (!res.success) return;
        var html = (res.rows||[]).map(function(r){
            return '<div class="rs-row" data-scope="link" data-id="'+r.id+'"><span>'+esc(r.target_value)+'</span>'
                + (CAN_ADMIN ? '<i class="fa fa-trash" title="刪除"></i>' : '') + '</div>';
        }).join('');
        $('#rsLinkTargetList').html(html || '<div class="rs-empty">尚無資料</div>');
    });
}
$(document).on('click', '#rsLinkSourceList .rs-row', function(){
    $('#rsLinkSourceList .rs-row').removeClass('active');
    $(this).addClass('active');
    RS_LINK_SOURCE = $(this).attr('data-src');
    $('#rsLinkTargetNew,#rsLinkTargetAddBtn').prop('disabled', false);
    rsLoadLinkTargets();
});
window.rsAddLinkTarget = function(){
    var v = $('#rsLinkTargetNew').val().trim();
    if (!RS_LINK_SOURCE || !v) return;
    var pair = rsLinkPair();
    $.post(API, {action:'field_link_add', source_field:pair[0], source_value:RS_LINK_SOURCE, target_field:pair[1], target_value:v}, function(res){
        if (!res.success){ alert(res.message||'新增失敗'); return; }
        $('#rsLinkTargetNew').val(''); rsLoadLinkTargets();
    }, 'json');
};
$('#rsLinkPairSel').on('change', rsLoadLinkSources);
window.rsBackfillLinks = function(){
    if (!confirm('從已匯入的整組樣板(項目異常工作表)回填「失效模式→失效模式潛在後果」「失效模式→失效潛在原因」的對應清單？\n（已存在的對應不會重複新增）')) return;
    $.post(API, {action:'field_link_backfill'}, function(res){
        if (!res.success){ alert(res.message||'回填失敗'); return; }
        alert('回填處理完成（共處理 '+res.processed+' 筆組合，已存在的不重複新增）。');
        rsLoadLinkSources();
    }, 'json');
};

/* ---------- 符號按鈕：工程符號(比照 views/Sales/image_editor.php 既有符號) + 幾何公差／特殊項目
   (沿用 QC 模組 qc_special_characteristic 字典) ----------（2026-08-14使用者要求）
   兩組分開顯示；點擊插入「對應的目標值」輸入框目前游標位置，不影響其他文字。 */
var ENG_SYMBOLS = [
    ['Ø','直徑'], ['°','度'], ['±','正負公差'], ['▽','加工符號（研磨＝連按多個）'],
    ['↧','深度'], ['⌴','沉頭孔／柱坑'], ['⌵','錐坑'], ['□','正方形'],
    ['⌒','圓弧'], ['Ra','表面粗糙度'], ['×','乘號']
];
function rsRenderEngSymbols(){
    $('#rsEngSymRow').html(ENG_SYMBOLS.map(function(s){
        return '<button type="button" class="pf-sym-btn" data-sym="'+esc(s[0])+'" title="'+esc(s[1])+'">'+esc(s[0])+'</button>';
    }).join(''));
}
function rsRenderGdtSymbols(){
    $.getJSON(API, {action:'qc_special_characteristics_list'}, function(res){
        if (!res.success) return;
        $('#rsGdtSymRow').html((res.rows||[]).map(function(s){
            var t = s.name + (s.description ? '（'+s.description+'）' : '');
            return '<button type="button" class="pf-sym-btn" data-sym="'+esc(s.symbol)+'" title="'+esc(t)+'">'+esc(s.symbol)+'</button>';
        }).join('') || '<span class="rs-empty">尚無資料</span>');
    });
}
$(document).on('click', '#refSettingsMask .pf-sym-btn', function(){
    var sym = $(this).attr('data-sym');
    var el = document.getElementById('rsLinkTargetNew');
    if (el.disabled) { alert('請先選定左側來源值'); return; }
    var start = el.selectionStart || 0, end = el.selectionEnd || 0;
    var val = el.value;
    el.value = val.substring(0, start) + sym + val.substring(end);
    var newPos = start + sym.length;
    el.focus();
    el.setSelectionRange(newPos, newPos);
});

$('#btnRefSettings').on('click', function(){
    RS_PROC_ID = 0; RS_ITEM_OPT_ID = 0; RS_FUNC_OPT_ID = 0;
    $('#rsProcessScope, #rsItemScope').hide();
    openMask('refSettingsMask');
    switchRsTab('proc');
    rsRenderProcessEnableList(); rsLoadProcessList(); rsLoadControlLists();
    rsLoadTplProcSel();
    rsLoadLinkSources();
    rsRenderEngSymbols(); rsRenderGdtSymbols();
});

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
