<?php
/**
 * 審核表單模板管理（review_form 引擎）—— 2026-08-11 新增
 * 首發模板：2-TD-04-01 仿冒零件防制審核表／2-TD-03-01 產品安全審核表
 * 資料一律走 src/store/ReviewForm_API.php；權限 src/common/review_form_lib.php rvf_perms()
 * 管理員可設定模板全部設定；維護部門主管／被指派的維護人員只能編輯「項次欄位定義」。
 */
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/ADM/review_form_template.php";
    header("Location:../../index.php");
    exit;
}
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/review_form_lib.php';

$db = (new DBConnection())->getPDO();
$rvfUser = rvf_current_user($db);
$perms = rvf_perms($db, $rvfUser);
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>審核表單模板管理</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <style>
        #sidebar-menu { visibility: hidden; }
        .right_col .page-title { margin:8px 0 4px; overflow:hidden; clear:both; }
        .rf-toolbar { display:flex; flex-wrap:wrap; gap:6px; align-items:center; clear:both;
            border:1.5px solid #E8D5B5; border-radius:8px; padding:8px 10px; margin-bottom:10px; background:#FDF8EF; }
        .rf-toolbar button { height:30px; font-size:13px; padding:0 12px; border:1px solid #D8BE93; border-radius:4px;
            background:#fff; color:#5b3a1e; cursor:pointer; }
        .rf-toolbar .btn-warm { background:#F0A24B; color:#fff; border-color:#d98a33; }
        .page-help-btn { margin-left:auto; height:30px; font-size:13px; padding:0 14px; border:1px solid #D8BE93;
            border-radius:4px; background:#fff; color:#5b3a1e; cursor:pointer; }
        .help-doc h4 { font-size:14px; color:#8A5A2B; margin:10px 0 4px; }
        table.rf-tbl { width:100%; border-collapse:collapse; font-size:13px; background:#fff; }
        table.rf-tbl th, table.rf-tbl td { border:1px solid #EADFC8; padding:6px 8px; }
        table.rf-tbl thead th { background:#F7E0BD; color:#5b3a1e; }
        .rf-table-wrap { overflow-x:auto; border:1px solid #E8D5B5; border-radius:6px; }
        .tag-on { color:#7a5217; font-weight:bold; } .tag-off { color:#b0a390; }
        /* 已停用的模板：整列淡化＋左側橘邊，一眼看得出來，但仍看得到內容（既有表單還在用它列印） */
        table.rf-tbl tr.tpl-archived td { background:#FBF6EE; color:#8a7a66; }
        table.rf-tbl tr.tpl-archived td:first-child { border-left:3px solid #F0A24B; }
        .st-badge { display:inline-block; padding:1px 6px; border-radius:3px; font-size:11px; border:1px solid transparent; }
        .st-active { background:#F7E0BD; color:#7a5217; border-color:#E0BE86; }
        .st-archived { background:#EFE7DA; color:#8a6d45; border-color:#D8C6A8; }
        .btn-del { color:#DD5138; }
        /* 已被表單使用＝刪不掉，鈕標灰但仍可點（點下去會說明原因並引導改用「停用」） */
        .btn-del-off { color:#b0a390; }
        .rf-mask { display:none; position:fixed; inset:0; background:rgba(60,40,20,.45); z-index:1050; }
        .rf-modal { background:#fff; border-radius:8px; max-width:640px; margin:30px auto; box-shadow:0 5px 25px rgba(0,0,0,.3);
            max-height:90vh; display:flex; flex-direction:column; }
        .rf-modal.wide { max-width:960px; }
        .rf-modal .m-head { background:#F7E0BD; color:#5b3a1e; font-weight:bold; padding:10px 15px; border-radius:8px 8px 0 0;
            display:flex; justify-content:space-between; }
        .rf-modal .m-head .m-close { cursor:pointer; color:#b5762a; }
        .rf-modal .m-body { padding:15px; overflow-y:auto; }
        .rf-modal .m-body label { display:block; font-size:13px; color:#5b3a1e; margin:9px 0 3px; }
        .rf-modal .m-body input[type=text], .rf-modal .m-body input[type=number], .rf-modal .m-body input[type=date],
        .rf-modal .m-body select, .rf-modal .m-body textarea { width:100%; border:1px solid #D8BE93; border-radius:4px;
            padding:5px 8px; font-size:13px; box-sizing:border-box; }
        .rf-modal .m-foot { padding:10px 15px; border-top:1px solid #EADFC8; text-align:right; }
        .rf-modal .m-foot button { height:30px; padding:0 16px; border-radius:4px; font-size:13px; border:1px solid #d98a33; cursor:pointer; }
        .rf-modal .m-foot .b-ok { background:#F0A24B; color:#fff; }
        .rf-modal .m-foot .b-cancel { background:#fff; color:#5b3a1e; border-color:#D8BE93; margin-right:6px; }
        .rf-modal .m-foot .b-danger { background:#DD5138; color:#fff; border-color:#c23f28; }
        .grid2 { display:grid; grid-template-columns:1fr 1fr; gap:0 14px; }
        .rf-sec { border-top:1px dashed #EADFC8; margin-top:10px; padding-top:8px; }
        .rf-sec-title { font-weight:bold; color:#5b3a1e; margin:4px 0 6px; }
        .rf-hint { font-size:11.5px; color:#8a6d45; margin:2px 0 6px; }
        table.col-tbl { width:100%; border-collapse:collapse; font-size:12.5px; margin-bottom:8px; }
        table.col-tbl th, table.col-tbl td { border:1px solid #EADFC8; padding:4px 6px; vertical-align:top; }
        table.col-tbl thead th { background:#F7E0BD; color:#5b3a1e; }
        table.col-tbl input, table.col-tbl select { width:100%; border:1px solid #D8BE93; border-radius:4px; padding:3px 5px; font-size:12px; box-sizing:border-box; }
        .rf-del { color:#DD5138; cursor:pointer; }
        .fld-drag-handle { cursor:move; color:#b0a390; text-align:center; }
        .fld-drag-handle:hover { color:#8A5A2B; }
        tr.fld-row.drag-over { box-shadow:inset 0 2px 0 #F0A24B; }
        .chain-row { display:flex; align-items:center; gap:6px; margin-bottom:4px; }
        .chain-row select { flex:1; }
        .mt-tags { max-height:120px; overflow-y:auto; border:1px solid #EADFC8; border-radius:6px; padding:6px 8px; margin-bottom:6px; }
        .mt-tags .tg { display:inline-block; background:#F7E0BD; color:#5b3a1e; border-radius:9px; font-size:11px; padding:1px 8px; margin:2px; }
        .mt-tags .tg i { cursor:pointer; color:#b5762a; margin-left:4px; }
        .role-item { padding:6px 10px; font-size:12.5px; color:#5b3a1e; cursor:pointer; border-bottom:1px solid #F0E7D5; }
        .role-item:hover { background:#FBF0DD; } .role-item.on { background:#F7E0BD; font-weight:bold; } .role-item.sys { color:#b0a390; cursor:default; }
        .role-feat { font-size:12.5px; }
        @media print { .page-help-btn { display:none; } }
    </style>
</head>
<body class="nav-sm">
<div class="container body">
<div class="main_container">
    <?php include '../partPage/sideAndTopBarMenu.html' ?>
    <div class="right_col" role="main">
        <div class="page-title" style="display:flex;align-items:center;flex-wrap:wrap;clear:both;">
            <h2 style="margin:6px 0;">審核表單模板管理 <small style="color:#8a6d45;">管理員設定模板／維護人員維護項次內容</small></h2>
            <button class="page-help-btn" id="btnPageHelp"><i class="fa fa-question-circle"></i> 使用說明</button>
        </div>
        <div class="clearfix"></div>

<?php if (!$perms['canView']): ?>
        <div><h4><i class="fa fa-lock"></i> 無審核表單檢閱權限</h4><p>請洽系統管理者於「使用者權限設定」指派「審核表單」相關角色。</p></div>
<?php else: ?>
        <div class="rf-toolbar">
            <span>共用表單模板引擎：新建模板可綁定 AS 文件編號、設計項次欄位、設定審核/核准流程。</span>
            <button id="btnRoleSetting" style="display:none;"><i class="fa fa-users"></i> 角色設定</button>
            <button class="btn-warm" id="btnAddTpl" style="display:none;margin-left:auto;"><i class="fa fa-plus"></i> 新增模板</button>
            <a href="review_form.php" class="btn" style="height:30px;line-height:28px;padding:0 12px;border:1px solid #D8BE93;border-radius:4px;color:#5b3a1e;text-decoration:none;">前往「建立/填寫表單」→</a>
        </div>
        <div class="rf-table-wrap">
        <table class="rf-tbl">
            <thead><tr><th>模板名稱</th><th>綁定AS文件</th><th>紙張</th><th>審核</th><th>核准</th><th>維護部門</th><th style="width:70px;">狀態</th><th style="width:280px;">操作</th></tr></thead>
            <tbody id="tplBody"><tr><td colspan="8" style="text-align:center;color:#8a6d45;">載入中…</td></tr></tbody>
        </table>
        </div>
<?php endif; ?>
    </div>
</div>
</div>

<!-- 模板設定 modal（管理員） -->
<div class="rf-mask" id="settingMask"><div class="rf-modal">
    <div class="m-head"><span id="settingTitle">新增模板</span><span class="m-close" onclick="closeMask('settingMask')">✕</span></div>
    <div class="m-body">
        <input type="hidden" id="stId" value="0">
        <label>模板名稱</label><input type="text" id="stName" maxlength="100" placeholder="例：仿冒零件防制審核表">
        <div id="stNameHint" style="font-size:11px;color:#8a6d45;margin-top:-4px;margin-bottom:6px;display:none;">已綁定 AS 文件，名稱自動＝該文件的文件名稱，不可自訂；取消綁定後才能自訂名稱。</div>
        <label>綁定 AS 文件編號</label>
        <div><span id="stDocLabel" style="color:#5b3a1e;">未綁定</span>
            <button type="button" onclick="openTplAsDocPicker()" style="margin-left:8px;height:26px;font-size:12px;border:1px solid #D8BE93;border-radius:4px;background:#fff;cursor:pointer;">選擇…</button></div>
        <div class="grid2">
            <div><label>列印紙張大小</label><select id="stPaper"><option value="A4">A4</option><option value="A3">A3</option></select></div>
            <div><label>列印方向</label><select id="stOrientation"><option value="landscape">橫式</option><option value="portrait">直式</option></select></div>
        </div>
        <div class="grid2">
            <div><label>逐列簽章圖章樣式</label><select id="stListStamp"><option value="0">（預設樣式）</option></select></div>
            <div><label>製表/審核/核准簽章圖章樣式</label><select id="stFooterStamp"><option value="0">（預設樣式）</option></select></div>
        </div>
        <div class="rf-hint">圖章樣式請至「圖章管理 → 線上圖章設計」建立/挑選；有上傳掃描實體章的人一律優先用掃描章，這裡只影響沒掃描章時自動產生的印章樣式。</div>

        <div class="rf-sec"><div class="rf-sec-title">審核（可選）</div>
            <label><input type="checkbox" id="stNeedReview"> 需要審核</label>
            <label>審核部門（該部門任一主管審核通過即完成）</label>
            <select id="stReviewDept"><option value="">（未設定）</option></select>
            <label><input type="checkbox" id="stAutoReview"> 自動簽核（不等真人，送出後系統立即以審核池第一位的名義自動核准；簽核時間會刻意跟送出時間錯開幾分鐘，詳見 ai-rules/21）</label>
        </div>

        <div class="rf-sec"><div class="rf-sec-title">核准（可選）</div>
            <label><input type="checkbox" id="stNeedApproval"> 需要核准</label>
            <label><input type="checkbox" id="stAutoApproval"> 自動簽核（不等真人，比照上方審核的自動簽核規則）</label>
            <div class="rf-hint">核准人員優先序：由上而下依序嘗試，取第一個有結果的方法；解析到送出者本人會自動跳下一順位（迴避球員兼裁判）。預設僅「最高決策者」。</div>
            <div id="chainBox"></div>
            <div class="grid2">
                <div><label>「部門或人員」方法 — 綁部門</label><select id="stApproverDept"><option value="">（未設定）</option></select></div>
                <div><label>「部門或人員」方法 — 綁人員（優先於部門）</label><select id="stApproverUser" data-eg-filter="輸入人員姓名篩選…"><option value="">（未設定）</option></select></div>
            </div>
        </div>

        <div class="rf-sec"><div class="rf-sec-title">項次內容維護權限（可選）</div>
            <label>維護部門（此部門內主管、以及被指派的維護人員可修改「項次欄位定義」，其餘設定仍僅管理員可改）</label>
            <select id="stMaintainDept"><option value="">（未設定，僅管理員可維護）</option></select>
        </div>

        <div class="rf-sec"><div class="rf-sec-title">年度標題（可選）</div>
            <label><input type="checkbox" id="stHasYear"> 有年度標題（適用整年度彙總類表單，勾選後在「項次欄位定義」內設定年度格式與顯示位置，新建表單時會多一個年度輸入框）</label>
        </div>
    </div>
    <div class="m-foot"><button class="b-cancel" onclick="closeMask('settingMask')">取消</button>
        <button class="b-ok" onclick="submitTplSettings()">儲存</button></div>
</div></div>

<!-- 項次欄位定義 modal（管理員／維護人員） -->
<div class="rf-mask" id="schemaMask"><div class="rf-modal wide">
    <div class="m-head"><span id="schemaTitle">項次欄位定義</span><span class="m-close" onclick="closeMask('schemaMask')">✕</span></div>
    <div class="m-body">
        <input type="hidden" id="scTplId" value="0">

        <div class="rf-sec" style="border-top:none;margin-top:0;padding-top:0;"><div class="rf-sec-title">表格結構</div>
            <label><input type="checkbox" id="scNeedOwner" checked> 需要「負責單位／負責人」欄位</label>
            <div class="rf-hint">不勾＝填寫畫面與列印版都不出現「負責單位／負責人」欄，也不會要求任何人簽名（下方「負責人簽名方式」會自動停用）。</div>
            <label><input type="checkbox" id="scHeadRow"> 橫式標題<b>下方</b>再加一列可填入資料（每個欄位各填一格，整張表單只填一次，不是逐列）</label>
            <div style="margin:-2px 0 6px 22px;"><input type="text" id="scHeadRowLabel" maxlength="20" placeholder="這一列最左邊那格要顯示的文字（可留空）" style="max-width:320px;"></div>
            <label><input type="checkbox" id="scUseRowHead"> 使用直式標題（左側固定列標題，與上方的橫式欄位標題交叉成矩陣，例：SWOT／組織處境分析表）</label>
            <div class="rf-hint">開啟後最左欄改為下方定義的「列標題」（取代原本讓使用者自己打字的「項目」欄），建立表單時自動產生這些列，使用者<b>不可增刪列</b>、只能填交叉格的內容；每個列標題底下仍可用「＋小項」拆成多點。</div>
            <div id="rowHeadBox" style="display:none;border:1px dashed #E8D5B5;border-radius:6px;padding:8px;margin-top:6px;">
                <div class="grid2">
                    <div><label>欄的維度名稱（左上角斜線右上半）</label><input type="text" id="scCornerCol" maxlength="30" placeholder="例：內部問題"></div>
                    <div><label>列的維度名稱（左上角斜線左下半）</label><input type="text" id="scCornerRow" maxlength="30" placeholder="例：外部問題"></div>
                </div>
                <div class="rf-hint">兩個都留空＝左上角那格只寫「項目」、不畫斜線。</div>
                <label style="display:inline-block;margin-right:18px;"><input type="checkbox" id="scRowHeadCenter" checked> 列標題置中（取消＝靠左）</label>
                <label style="display:inline-block;"><input type="checkbox" id="scRowHeadVertical"> 列標題文字直書（由上而下一字一行，適合很窄的左欄）</label>
                <label><input type="checkbox" id="scRowSide"> 直式標題<b>右側</b>再加一欄可填入資料（逐列各填一格，紙本組織處境分析表左邊那個窄欄）</label>
                <div style="margin:-2px 0 6px 22px;"><input type="text" id="scRowSideLabel" maxlength="20" placeholder="這一欄的標題（可留空）" style="max-width:260px;"></div>
                <label>列標題（由上而下依序）</label>
                <table class="col-tbl">
                    <thead><tr><th style="width:8%;">順序</th><th>標題文字</th><th style="width:10%;"></th></tr></thead>
                    <tbody id="rowHeadBody" data-eg-row-add="rowHeadAdd" data-eg-row-del="rowHeadDelLast"></tbody>
                </table>
                <button type="button" onclick="rowHeadAdd()" style="height:26px;font-size:12px;border:1px solid #d98a33;background:#F0A24B;color:#fff;border-radius:4px;cursor:pointer;">+ 新增列標題</button>
            </div>
        </div>

        <div class="rf-sec-title">逐列可填欄位（除固定的「項目」文字外，額外可設定審查結果／其他欄位／日期欄位，可自由混合排序）</div>
        <div class="rf-hint">每列固定含「項目」文字欄；以下欄位會依此清單的順序顯示在項目欄之後，文字/下拉/日期/項次欄位可任意混合排序——拖動最左側 <i class="fa fa-bars"></i> 調整順序。「排版」選整行代表獨佔一列（適合長文字），選並排代表與其他並排欄位同一列；「內容對齊」管格子裡的內容，「標題」那一欄的兩個勾選管的是表頭文字要不要置中、要不要直書。輸入欄按鍵盤 ↓ 鍵在最後一列會自動新增一列，最後一列空白時按鍵盤 ↑ 鍵會自動移除。</div>
        <table class="col-tbl">
            <thead><tr><th style="width:5%;"></th><th style="width:13%;">標籤</th><th style="width:10%;">類型</th><th style="width:13%;">提示詞（灰字）</th><th style="width:13%;">選項(逗號分隔，僅下拉用)</th><th style="width:6%;">必填</th><th style="width:8%;">排版</th><th style="width:9%;" title="這一欄「內容」的對齊方式，與標題無關">內容對齊</th><th style="width:10%;" title="這一欄「標題」怎麼排：置中或靠左、要不要直書">標題</th><th style="width:9%;"></th></tr></thead>
            <tbody id="colBody" data-eg-row-add="fieldAdd" data-eg-row-del="fieldDelLast"></tbody>
        </table>
        <button type="button" onclick="fieldAdd()" style="height:26px;font-size:12px;border:1px solid #d98a33;background:#F0A24B;color:#fff;border-radius:4px;cursor:pointer;">+ 新增欄位</button>

        <div class="rf-sec" id="scYearBox" style="display:none;"><div class="rf-sec-title">年度標題設定（模板已勾選「有年度標題」）</div>
            <div class="grid2">
                <div><label>年度格式</label><select id="scYearFormat"><option value="ad">西元年</option><option value="roc">民國年</option></select></div>
                <div><label>顯示位置</label><select id="scYearPos"><option value="left">大標題左側</option><option value="center">置中（模板名稱下方）</option><option value="right">大標題右側</option></select></div>
            </div>
            <div class="rf-hint">新建表單時會多一個年度輸入框，需在建立日期年份的前一年～後一年之間。</div>
        </div>

        <div class="rf-sec"><div class="rf-sec-title">負責人簽名方式</div>
            <label><input type="radio" name="signMode" value="password"> 現場輸入本人密碼線上簽名</label>
            <label><input type="radio" name="signMode" value="notify"> 送出後改用通知請對方回簽</label>
            <label><input type="radio" name="signMode" value="none"> 不須簽名（負責單位/負責人僅供標示，不需線上簽名、也不發送通知簽章）</label>
            <div class="rf-hint" id="signModeHint" style="display:none;color:#DD5138;">本模板未勾選「需要負責單位／負責人欄位」，沒有可簽名的對象，故固定為「不須簽名」。</div>
        </div>

        <div class="rf-sec"><div class="rf-sec-title">維護人員名單</div>
        <div class="mt-tags" id="maintTags"></div>
        <select id="maintUserSel" data-eg-filter="輸入人員姓名篩選…" style="width:70%;"><option value="">選擇人員…</option></select>
        <button type="button" onclick="maintainerAdd()" style="height:26px;font-size:12px;border:1px solid #D8BE93;background:#fff;border-radius:4px;cursor:pointer;">加入</button>
        </div>

        <div class="rf-sec"><div class="rf-sec-title">是否連動更新 AS 文件版次</div>
            <label><input type="checkbox" id="scBumpAsDoc"> 本次修改要連動更新 AS 文件版次（存檔即生效）</label>
            <div id="bumpBox" style="display:none;border:1px dashed #E8D5B5;border-radius:6px;padding:8px;margin-top:6px;">
                <div class="grid2">
                    <div><label>新版次號</label><input type="text" id="bumpVersion" placeholder="例：B、2"></div>
                    <div><label>修訂生效日</label><input type="date" id="bumpDate" max="9999-12-31"></div>
                </div>
                <label>修訂重點</label><textarea id="bumpSummary" rows="2"></textarea>
                <label>新版文件檔（有「免附件補登」權限者可留空）</label><input type="file" id="bumpFile">
                <label>文件制修申請單附件一（有「免附件補登」權限者可留空）</label><input type="file" id="bumpApply">
            </div>
        </div>
    </div>
    <div class="m-foot">
        <button style="background:#fff;color:#5b3a1e;border-color:#D8BE93;" onclick="previewSchema()"><i class="fa fa-eye"></i> 試填預覽並列印（不會儲存）</button>
        <button class="b-cancel" onclick="closeMask('schemaMask')">取消</button>
        <button class="b-ok" onclick="submitSchema()">儲存項次欄位定義</button>
    </div>
</div></div>

<!-- 角色設定 modal（管理員；定義本模組角色能看到/做什麼，指派給誰在「使用者權限設定」頁） -->
<div class="rf-mask" id="roleSetMask"><div class="rf-modal wide">
    <div class="m-head"><span>角色設定</span><span class="m-close" onclick="closeMask('roleSetMask')">✕</span></div>
    <div class="m-body">
        <p class="rf-hint">左邊選或新增角色 → 右邊改名稱、勾這個角色能看到什麼／能做什麼。「誰擁有這個角色」在<a href="../user/user_permissions.php" target="_blank">人員權限設定頁</a>設定，這裡只定義角色內容。項次內容的維護權限不透過此處角色，另在各模板的「維護部門/維護人員」設定。</p>
        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-start;">
            <div style="border:1px solid #E8D5B5;border-radius:6px;background:#fff;flex:0 0 190px;">
                <div style="background:#F7E0BD;color:#5b3a1e;font-size:12px;font-weight:bold;padding:5px 10px;border-radius:6px 6px 0 0;display:flex;justify-content:space-between;align-items:center;">角色
                    <button type="button" id="btnRoleAdd" style="padding:1px 8px;height:22px;font-size:11px;border:1px solid #D8BE93;background:#fff;border-radius:4px;cursor:pointer;">＋ 新增</button></div>
                <div id="roleList" style="max-height:280px;overflow-y:auto;"></div>
            </div>
            <div style="border:1px solid #E8D5B5;border-radius:6px;background:#fff;flex:1;min-width:260px;">
                <div style="background:#F7E0BD;color:#5b3a1e;font-size:12px;font-weight:bold;padding:5px 10px;border-radius:6px 6px 0 0;">角色內容</div>
                <div id="roleEdit" style="display:none;padding:10px;">
                    <label>角色名稱</label>
                    <div style="display:flex;gap:6px;">
                        <input type="text" id="roleName" style="flex:1;">
                        <button type="button" id="btnRoleRename" style="height:28px;font-size:12px;border:1px solid #D8BE93;background:#fff;border-radius:4px;cursor:pointer;">改名</button>
                        <button type="button" id="btnRoleDel" style="height:28px;font-size:12px;border:1px solid #D8BE93;background:#fff;color:#DD5138;border-radius:4px;cursor:pointer;">刪除</button>
                    </div>
                    <div style="font-size:12px;font-weight:bold;color:#8A5A2B;margin:10px 0 4px;">可視內容（看得到什麼）</div>
                    <div id="featView"></div>
                    <div style="font-size:12px;font-weight:bold;color:#8A5A2B;margin:10px 0 4px;">可操作（能做什麼）</div>
                    <div id="featOp"></div>
                    <button type="button" id="btnRoleFeatSave" style="margin-top:10px;height:28px;font-size:12px;border:1px solid #d98a33;background:#F0A24B;color:#fff;border-radius:4px;cursor:pointer;"><i class="fa fa-save"></i> 儲存功能</button>
                </div>
                <div id="roleEditHint" style="padding:24px;text-align:center;color:#8a6d45;">請在左側選一個角色，或按「＋ 新增」</div>
            </div>
        </div>
    </div>
    <div class="m-foot"><button class="b-ok" onclick="closeMask('roleSetMask')">關閉</button></div>
</div></div>

<!-- 使用說明 modal（鐵律7） -->
<div class="rf-mask" id="helpUseMask"><div class="rf-modal wide">
    <div class="m-head"><span>使用說明 — 審核表單模板管理</span><span class="m-close" onclick="closeMask('helpUseMask')">✕</span></div>
    <div class="m-body help-doc" style="font-size:13px;color:#5b3a1e;line-height:1.8;">
        <h4>功能說明</h4>
        通用「審核表單」引擎：管理員可自建任意張表單模板（首發：2-TD-04-01 仿冒零件防制審核表、2-TD-03-01 產品安全審核表），各模板各自綁定一個 AS 文件編號。一般使用者依模板建立表單、逐列填寫並讓負責人線上簽名，模板可設定送出後要不要走審核/核准。
        <h4>操作步驟</h4>
        <b>①新增模板</b>：設定名稱、綁定 AS 文件編號、列印紙張大小（A4/A3）與方向（預設橫式，可改直式）、逐列簽章／製表核准簽章要套用的圖章樣式（不設定則用預設樣式，樣式請到「圖章管理→線上圖章設計」建立）、是否需要審核（設審核部門，任一主管審過即完成）、是否需要核准（可設核准優先序：綁部門或人員／自動抓送出者上一階主管／全站最高決策者，預設只用「最高決策者」，可調整順序或組合）、維護部門（可指派誰能修改項次內容）。<br>
        <b>②設定項次欄位定義</b>：除固定的「項目」文字欄外，可新增任意數量的自訂欄位（文字/多行文字/下拉選單/日期，四種類型可自由混合排序，例如：欄位、日期、欄位、日期…），每欄可設提示詞（填寫畫面上顯示的灰字）、是否必填、排版方式（並排/整行）；欄位順序可拖動最左側圖示或用 ▲▼ 調整；並選擇負責人簽名方式（現場密碼簽名／送出後通知回簽／不須簽名，三擇一）。<br>
        <b>②-1 表格結構（直式標題／負責人欄）</b>：在「項次欄位定義」最上方可設定 ——「需要負責單位／負責人欄位」不勾就完全不出現該欄，也不會要求任何人簽名；「使用直式標題」勾起來後，表格最左欄改成模板預先定義好的<b>列標題</b>（取代讓使用者自己打字的「項目」欄），與上方橫式的欄位標題交叉成矩陣（＝SWOT／組織處境分析表那種版面），建立表單時自動產生這些列、使用者不可增刪，只能填交叉格的內容（每個列標題底下仍可用「＋小項」拆成多點）；左上角那格可填「欄的維度名稱」與「列的維度名稱」，會自動畫成斜線分隔的兩半（兩個都留空就只寫「項目」不畫斜線）。另外有兩個<b>可選的額外填寫區</b>（預設都關閉＝跟原本一樣）：勾「橫式標題下方再加一列」會在欄位標題那一列的下面多一列，每個欄位各一格、<b>整張表單只填一次</b>（不是逐列，適合填該欄的補充說明或分類）；勾「直式標題右側再加一欄」會在左側列標題的右邊多一個窄欄，<b>逐列各填一格</b>（紙本組織處境分析表左邊那個窄欄就是這個）。兩者都可以各自填一個標題文字，留空就是空白格。另外每個欄位的「標題」可各自設定<b>置中或靠左</b>、要不要<b>直書</b>（文字由上而下一字一行，欄寬可以很窄），左側列標題也有同樣的置中與直書選項；欄位表裡的「內容對齊」管的是格子內容、與標題各自獨立。<br>
        <b>③維護人員</b>：管理員或維護部門內主管可指派特定人員為「維護人員」，該名單與維護部門主管都能修改「項次欄位定義」，但不能改模板其他設定（AS文件綁定/審核/核准/維護部門本身）。<br>
        <b>④連動 AS 文件改版</b>：修改項次欄位定義存檔時可勾選「連動更新 AS 文件版次」，需上傳新版文件檔與文件制修申請單（有「免附件補登」權限者可免附件），存檔後立即生效成為現行版本；已建立的舊表單仍顯示建立當下的欄位定義，不受影響。
        <b>⑤停用模板</b>（管理員）：不再使用的模板按「停用」，「建立/填寫表單」頁的模板下拉就不再列出它，沒有人能再用它開新表單；<b>已建立的表單完全不受影響</b>，照樣查得到、填得到、簽得了、印得出來（篩選下拉仍會列出並標示「已停用」）。停用是可逆的，隨時可按「啟用」放回去。<br>
        <b>⑥刪除模板</b>（管理員）：<b>只有「一張表單都還沒建立過」的模板才刪得掉</b>。已經有表單在用的一律擋下——模板一刪，那些表單的欄位定義與列印表頭會全部失去對應而變成空白且無法復原，這種情況請改用「停用」。可刪除時會一併清掉該模板的項次欄位定義版本歷程、維護人員名單與 AS 文件綁定（<b>AS 文件本身不會被刪</b>，只是解除綁定，之後可綁到別的模板）。
        <h4>重要行為</h4>
        ・項次欄位定義改版是「存檔即生效」，不另設草稿。已建立的表單各自記錄自己建立當下對應的模板版本，欄位顯示不受之後改版影響。<br>
        ・核准優先序解析到送出表單的本人時，會自動跳下一順位，不會球員兼裁判。<br>
        ・按下「停用／刪除」的當下會重新向後端確認這個模板目前的表單筆數與狀態，所以別人剛用它建了表單也不會被誤刪；畫面顯示的數字若已過期會自動重新整理。
        <h4>設定入口</h4>
        本頁清單「編輯設定」（管理員）／「編輯項次」（管理員或維護人員）／「複製・停用・刪除」（管理員）。
        <h4>權限角色</h4>
        審核表單檢閱＝看清單；審核表單建立＝可到「建立/填寫表單」頁使用；模板管理＝本頁全部設定；管理者全權。
    </div>
    <div class="m-foot"><button class="b-ok" onclick="closeMask('helpUseMask')">關閉</button></div>
</div></div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script src="../../resource/js/eg_input_rules.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_input_rules.js') ?>"></script>
<script src="../../resource/js/eg_asdoc_picker.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_asdoc_picker.js') ?>"></script>
<script>
var API = '../../src/store/ReviewForm_API.php';
var ASDOC_API = '../../src/store/AS_Document_API.php';
var META = {}, TEMPLATES = [];
function esc(s){ return $('<div>').text(s==null?'':s).html(); }
function openMask(id){ $('#'+id).css('display','block'); }
function closeMask(id){ $('#'+id).css('display','none'); }
$(document).ready(function(){
    var $am = $('#sidebar-menu .nav.side-menu > li.active');
    if ($am.length) { $am.removeClass('active').find('ul.child_menu').hide(); $am.find('li.current-page').removeClass('current-page'); }
    $('#sidebar-menu').css('visibility','visible');
});
$('#btnPageHelp').on('click', function(){ openMask('helpUseMask'); });

function loadMeta(cb){
    $.getJSON(API, {action:'meta'}, function(res){
        if (!res.ok){ alert(res.error||'載入失敗'); return; }
        META = res;
        var deptOpts = '<option value="">（未設定）</option>' + META.departments.map(function(d){ return '<option value="'+d.id+'">'+esc(d.name)+'</option>'; }).join('');
        $('#stReviewDept,#stApproverDept,#stMaintainDept').html(deptOpts);
        var userOpts = '<option value="">（未設定）</option>' + META.people.map(function(p){ return '<option value="'+p.id+'">'+esc(p.display)+'</option>'; }).join('');
        $('#stApproverUser').html(userOpts);
        $('#maintUserSel').html('<option value="">選擇人員…</option>' + META.people.map(function(p){ return '<option value="'+p.id+'">'+esc(p.display)+'</option>'; }).join(''));
        if (META.perms.canAdmin) { $('#btnAddTpl').show(); $('#btnRoleSetting').show(); loadStampTplOptions(); }
        renderChainBox();
        if (cb) cb();
    });
}
function loadStampTplOptions(){
    $.getJSON(API, {action:'stamp_tpl_options'}, function(res){
        if (!res.ok) return;
        var h = '<option value="0">（預設樣式）</option>';
        (res.templates||[]).forEach(function(t){ h += '<option value="'+t.id+'">'+(t.type_name?esc(t.type_name)+'｜':'')+esc(t.tpl_name)+'</option>'; });
        $('#stListStamp,#stFooterStamp').html(h);
    });
}
// 新增模板：帶 id=0 開設定跳窗（openSettingModal 內已有「新增」分支會把欄位全部重設成預設值）
$('#btnAddTpl').on('click', function(){ openSettingModal(0); });
$('#btnRoleSetting').on('click', function(){ openMask('roleSetMask'); loadRoles(); });
var RAPI = '../../src/store/Roles_API.php';
var ROLES = [], CURROLE = 0;
function loadRoles(then){
    $.getJSON(RAPI, {action:'get_roles', module:'review_form'}, function(res){
        ROLES = res.data || [];
        var h = '';
        ROLES.forEach(function(r){
            var sys = String(r.is_system)==='1';
            h += '<div class="role-item'+(sys?' sys':'')+'" data-id="'+r.role_id+'">'+esc(r.role_name)+(sys?'（系統．固定全權）':'')+'</div>';
        });
        $('#roleList').html(h || '<div style="padding:10px;color:#8a6d45;">尚無角色</div>');
        if (CURROLE) $('.role-item[data-id="'+CURROLE+'"]').addClass('on');
        if (typeof then==='function') then();
    });
}
function selRole(id){
    var r = ROLES.filter(function(x){ return String(x.role_id)===String(id); })[0];
    if (!r) return;
    if (String(r.is_system)==='1'){ alert('系統角色「'+r.role_name+'」固定擁有全部權限，不可修改'); return; }
    CURROLE = id;
    $('.role-item').removeClass('on'); $('.role-item[data-id="'+id+'"]').addClass('on');
    $('#roleEditHint').hide(); $('#roleEdit').show();
    $('#roleName').val(r.role_name);
    var vh='', oh='';
    (META.features||[]).forEach(function(f){
        var row = '<label class="role-feat" style="display:block;font-weight:normal;padding:2px 0;"><input type="checkbox" class="featcb" value="'+esc(f.code)+'"> '+esc(f.label)+'</label>';
        if (f.group==='view') vh += row; else oh += row;
    });
    $('#featView').html(vh); $('#featOp').html(oh);
    $.getJSON(RAPI, {action:'get_role_features', role_id:id}, function(res){
        var has = res.data || [];
        $('.featcb').each(function(){ $(this).prop('checked', has.indexOf(this.value)>-1 || has.indexOf('all')>-1); });
    });
}
$(document).on('click', '#roleList .role-item', function(){ selRole($(this).data('id')); });
$('#btnRoleAdd').on('click', function(){
    var n = prompt('新角色名稱：');
    if (!n || !$.trim(n)) return;
    $.post(RAPI, {action:'save_role', role_name:$.trim(n), module:'review_form'}, function(r){
        if (!r.success){ alert(r.message); return; }
        loadRoles(function(){ selRole(r.role_id); });
    }, 'json');
});
$('#btnRoleRename').on('click', function(){
    if (!CURROLE) return;
    var n = $.trim($('#roleName').val()||'');
    if (!n){ alert('請輸入角色名稱'); return; }
    $.post(RAPI, {action:'save_role', role_id:CURROLE, role_name:n}, function(r){
        if (!r.success){ alert(r.message); return; }
        loadRoles(); alert('已改名');
    }, 'json');
});
$('#btnRoleDel').on('click', function(){
    if (!CURROLE) return;
    if (!confirm('確定刪除此角色？擁有此角色的人會失去對應權限。')) return;
    $.post(RAPI, {action:'delete_role', role_id:CURROLE}, function(r){
        if (!r.success){ alert(r.message); return; }
        CURROLE = 0; $('#roleEdit').hide(); $('#roleEditHint').show();
        loadRoles();
    }, 'json');
});
$('#btnRoleFeatSave').on('click', function(){
    if (!CURROLE) return;
    var feats = $('.featcb:checked').map(function(){ return this.value; }).get();
    $.post(RAPI, {action:'save_role_features', role_id:CURROLE, features:JSON.stringify(feats)}, function(r){
        alert(r.success ? '已儲存。受影響的人重新整理頁面後生效。' : r.message);
    }, 'json');
});
function renderChainBox(){
    var methods = {dept_or_user:'部門或人員', auto_supervisor:'自動抓上一階主管', top_approver:'最高決策者'};
    var h = '';
    for (var i=0;i<3;i++){
        h += '<div class="chain-row"><span style="width:44px;color:#8a6d45;">第'+(i+1)+'順位</span><select class="chain-sel" data-idx="'+i+'"><option value="">不使用</option>';
        Object.keys(methods).forEach(function(k){ h += '<option value="'+k+'">'+methods[k]+'</option>'; });
        h += '</select></div>';
    }
    $('#chainBox').html(h);
}
function loadTemplates(){
    $.getJSON(API, {action:'template_list'}, function(res){
        if (!res.ok){ alert(res.error||'載入失敗'); return; }
        TEMPLATES = res.templates;
        var h = '';
        TEMPLATES.forEach(function(t){
            var docTxt = t.as_doc ? (t.as_doc.doc_no + ' ' + t.as_doc.doc_name) : '<span class="tag-off">未綁定</span>';
            var archived = (t.status === 'archived');
            var used = parseInt(t.instance_count||0, 10);   // 已被幾張表單使用（>0 就刪不掉，鈕標灰但仍可點＝點了會說明原因）
            h += '<tr'+(archived?' class="tpl-archived"':'')+'><td>'+esc(t.name)+'</td><td>'+docTxt+'</td><td>'+t.paper_size+(t.orientation==='portrait'?'直式':'橫式')+'</td>'
               + '<td>'+(t.need_review==1?'<span class="tag-on">需審核</span>':'<span class="tag-off">不需要</span>')+'</td>'
               + '<td>'+(t.need_approval==1?'<span class="tag-on">需核准</span>':'<span class="tag-off">不需要</span>')+'</td>'
               + '<td>'+(t.maintain_dept_id?deptName(t.maintain_dept_id):'<span class="tag-off">（未設定）</span>')+'</td>'
               + '<td><span class="st-badge '+(archived?'st-archived':'st-active')+'">'+(archived?'已停用':'啟用中')+'</span>'
               + (used>0 ? '<div style="font-size:11px;color:#8a6d45;margin-top:2px;">已建 '+used+' 張</div>' : '')+'</td>'
               + '<td>'
               + (META.perms.canAdmin ? '<button onclick="openSettingModal('+t.id+')" style="margin-right:4px;">編輯設定</button>' : '')
               + (t.can_edit_items ? '<button onclick="openSchemaModal('+t.id+')" style="margin-right:4px;">編輯項次</button>' : '')
               + (META.perms.canAdmin ? '<button onclick="duplicateTemplate('+t.id+')" style="margin-right:4px;">複製</button>' : '')
               + (META.perms.canAdmin ? '<button onclick="setTemplateStatus('+t.id+','+(archived?'0':'1')+')" style="margin-right:4px;">'+(archived?'啟用':'停用')+'</button>' : '')
               + (META.perms.canAdmin ? '<button class="'+(used>0?'btn-del-off':'btn-del')+'" title="'+(used>0?('已有 '+used+' 張表單使用，不可刪除（請改用「停用」）'):'刪除此模板')+'" onclick="deleteTemplate('+t.id+')">刪除</button>' : '')
               + '</td></tr>';
        });
        $('#tplBody').html(h || '<tr><td colspan="8" style="text-align:center;color:#8a6d45;padding:10px;">尚未建立任何模板</td></tr>');
    });
}
function deptName(id){ var d=(META.departments||[]).find(function(x){ return String(x.id)===String(id); }); return d?esc(d.name):''; }
/* 複製模板（2026-08-14 新增）：設定＋目前生效的項次欄位定義一起複製成新模板，名稱自動加「(複製)」；
   AS文件綁定不複製(每個模板要各自獨立綁)，複製完直接開「編輯設定」讓管理員改名/綁AS文件。 */
function duplicateTemplate(id){
    var t = TEMPLATES.find(function(x){ return String(x.id)===String(id); });
    if (!confirm('確定要複製模板「'+(t?t.name:'')+'」？會複製設定與目前的項次欄位定義，AS文件綁定需另外設定。')) return;
    $.post(API, {action:'template_duplicate', csrf:META.csrf, id:id}, function(res){
        if (!res.ok){ alert(res.error||'複製失敗'); return; }
        loadTemplates();
        openSettingModal(res.id);
    }, 'json');
}

/* 停用／啟用模板（2026-09-10 新增）：停用後「建立/填寫表單」頁的模板下拉不再列出它（沒有人能再用它開新表單），
   但既有表單完全不受影響——照樣看得到、填得到、印得出來，欄位定義各自吃自己建立當下的版本。 */
function setTemplateStatus(id, toArchived){
    tplUsage(id, function(u){
        var isArchived = (u.status === 'archived');
        if (isArchived === !!toArchived){ alert('此模板的狀態已被其他人變更，畫面已重新整理。'); loadTemplates(); return; }
        var msg = toArchived
            ? '確定要停用模板「'+u.name+'」？\n\n停用後沒有人能再用它建立新表單。\n目前已建立的 '+u.usage.instances+' 張表單不受影響（照樣可查看、填寫、簽名與列印），日後隨時可以再啟用。'
            : '確定要重新啟用模板「'+u.name+'」？啟用後所有人可再用它建立新表單。';
        if (!confirm(msg)) return;
        $.post(API, {action:'template_set_status', csrf:META.csrf, id:id, status:(toArchived?'archived':'active')}, function(res){
            if (!res.ok){ alert(res.error||'變更失敗'); loadTemplates(); return; }
            loadTemplates();
        }, 'json');
    });
}

/* 刪除模板（2026-09-10 新增，僅管理員）：已經有表單用這個模板就一律擋下並引導改用「停用」——
   rf_instance 只記 template_id 與建立當下的版本號，模板一刪那些表單的名稱／欄位定義／列印表頭全部查不到，
   畫面不會報錯只會變空白，是救不回來的資料破壞。後端 rvf_template_delete() 用同一條規則再擋一次（鐵律8）。 */
function deleteTemplate(id){
    tplUsage(id, function(u){
        if (u.usage.instances > 0){
            alert('模板「'+u.name+'」已經有 '+u.usage.instances+' 張表單在使用，不可刪除。\n\n'
                + '（刪掉模板會讓那些表單失去欄位定義與列印表頭，無法復原。）\n'
                + '若不希望再有人用它建立新表單，請改按「停用」——既有表單仍可查看與列印。');
            loadTemplates();
            return;
        }
        var d = ['確定要刪除模板「'+u.name+'」？此動作無法復原。', ''];
        d.push('・目前沒有任何表單使用此模板。');
        d.push('・會一併刪除：項次欄位定義的版本歷程 '+u.usage.versions+' 筆、維護人員名單 '+u.usage.maintainers+' 人'+(u.as_doc?('、AS 文件綁定（'+u.as_doc+'）'):'')+'。');
        d.push('・AS 文件本身不會被刪除，只是解除這個模板的綁定。');
        if (!confirm(d.join('\n'))) return;
        $.post(API, {action:'template_delete', csrf:META.csrf, id:id}, function(res){
            if (!res.ok){ alert(res.error||'刪除失敗'); loadTemplates(); return; }
            alert('已刪除模板「'+u.name+'」。');
            loadTemplates();
        }, 'json');
    });
}

/* 點開即刷新（ai-rules/08 第六節）：停用／刪除一律先向後端要這個模板「當下」的使用筆數與狀態，
   不可拿清單載入當時的快取判斷——別人剛用這個模板建了一張表單，照舊快取放行就會把它連同模板一起刪掉。 */
function tplUsage(id, cb){
    $.getJSON(API, {action:'template_usage', id:id}, function(res){
        if (!res.ok){ alert(res.error||'讀取模板狀態失敗'); loadTemplates(); return; }
        cb(res);
    }).fail(function(xhr){
        var m = ''; try { m = (JSON.parse(xhr.responseText)||{}).error; } catch(e){}
        alert(m || '讀取模板狀態失敗，請重新整理頁面後再試。');
        loadTemplates();
    });
}

/* ============ 模板設定 ============ */
/* 模板名稱一旦綁定 AS 文件編號，一律強制＝該文件的文件名稱（2026-08-14 使用者明確要求），未綁定才能自訂；
   後端 template_settings_save 會再次強制覆蓋，這裡只是即時 UI 回饋，不是唯一防線。 */
function applyStNameLock(doc){
    if (doc) { $('#stName').val(doc.doc_name).prop('readonly', true); $('#stNameHint').show(); }
    else { $('#stName').prop('readonly', false); $('#stNameHint').hide(); }
}
function openSettingModal(id){
    $('#stId').val(id||0);
    if (!id){
        $('#settingTitle').text('新增模板'); $('#stName').val(''); $('#stPaper').val('A4'); $('#stOrientation').val('landscape');
        $('#stListStamp').val('0'); $('#stFooterStamp').val('0');
        $('#stDocLabel').text('未綁定').data('id',0);
        applyStNameLock(null);
        $('#stNeedReview').prop('checked',false); $('#stAutoReview').prop('checked',false); $('#stReviewDept').val('');
        $('#stNeedApproval').prop('checked',false); $('#stAutoApproval').prop('checked',false); $('#stApproverDept').val(''); $('#stApproverUser').val('');
        renderChainBox(); $('.chain-sel[data-idx=0]').val('top_approver');
        $('#stMaintainDept').val('');
        $('#stHasYear').prop('checked',false);
        openMask('settingMask'); return;
    }
    $.getJSON(API, {action:'template_get', id:id}, function(res){
        if (!res.ok){ alert(res.error||'載入失敗'); return; }
        var t = res.template;
        $('#settingTitle').text('編輯模板：'+t.name);
        $('#stName').val(t.name); $('#stPaper').val(t.paper_size); $('#stOrientation').val(t.orientation||'landscape');
        $('#stListStamp').val(t.list_stamp_tpl_id||0); $('#stFooterStamp').val(t.footer_stamp_tpl_id||0);
        $('#stDocLabel').text(t.as_doc ? (t.as_doc.doc_no+' '+t.as_doc.doc_name) : '未綁定').data('id', t.as_doc?t.as_doc.id:0);
        applyStNameLock(t.as_doc||null);
        $('#stNeedReview').prop('checked', t.need_review==1); $('#stAutoReview').prop('checked', t.auto_review==1); $('#stReviewDept').val(t.review_dept_id||'');
        $('#stNeedApproval').prop('checked', t.need_approval==1); $('#stAutoApproval').prop('checked', t.auto_approval==1);
        $('#stApproverDept').val(t.approver_dept_id||''); $('#stApproverUser').val(t.approver_user_id||'');
        renderChainBox();
        (t.approver_chain||['top_approver']).forEach(function(m,i){ $('.chain-sel[data-idx='+i+']').val(m); });
        $('#stMaintainDept').val(t.maintain_dept_id||'');
        $('#stHasYear').prop('checked', t.has_year_heading==1);
        openMask('settingMask');
    });
}
function openTplAsDocPicker(){
    $.getJSON(API, {action:'asdoc_list'}, function(res){
        if (!res.ok){ alert(res.error||'載入失敗'); return; }
        EGAsDoc.open({ docs: res.docs||[], current: $('#stDocLabel').data('id')||0, title:'模板 AS 文件綁定',
            onSave: function(id, doc){ $('#stDocLabel').text(doc?(doc.doc_no+' '+doc.doc_name):'未綁定').data('id', id); applyStNameLock(doc); }
        });
    });
}
function submitTplSettings(){
    var name = $.trim($('#stName').val());
    if (!name){ alert('請輸入模板名稱'); return; }
    var chain = [];
    $('.chain-sel').each(function(){ var v=$(this).val(); if (v) chain.push(v); });
    $.post(API, {
        action:'template_settings_save', csrf:META.csrf, id:$('#stId').val(), name:name, paper_size:$('#stPaper').val(),
        orientation:$('#stOrientation').val(), list_stamp_tpl_id:$('#stListStamp').val(), footer_stamp_tpl_id:$('#stFooterStamp').val(),
        need_review: $('#stNeedReview').is(':checked')?1:0, auto_review: $('#stAutoReview').is(':checked')?1:0, review_dept_id:$('#stReviewDept').val(),
        need_approval: $('#stNeedApproval').is(':checked')?1:0, auto_approval: $('#stAutoApproval').is(':checked')?1:0,
        approver_dept_id:$('#stApproverDept').val(), approver_user_id:$('#stApproverUser').val(),
        approver_chain: JSON.stringify(chain.length?chain:['top_approver']),
        maintain_dept_id:$('#stMaintainDept').val(), as_doc_id:$('#stDocLabel').data('id')||0,
        has_year_heading: $('#stHasYear').is(':checked')?1:0
    }, function(res){
        if (!res.ok){ alert(res.error||'儲存失敗'); return; }
        closeMask('settingMask'); loadTemplates();
    }, 'json');
}

/* ============ 項次欄位定義 ============ */
var FIELDS = [], CUR_SCHEMA_TPL = null;
var FIELD_TYPES = {text:'單行文字', textarea:'多行文字', select:'下拉選單', date:'日期', seq:'項次（自動編號）'};
function fieldAdd(){ FIELDS.push({key:'', label:'', type:'text', placeholder:'', required:1, layout:'inline', align:'left', options:''}); renderFields(); }
function fieldDel(i){ FIELDS.splice(i,1); renderFields(); }
function fieldDelLast(){ if (FIELDS.length) FIELDS.pop(); renderFields(); }
function fieldEdit(i,k,v){ FIELDS[i][k]=v; if (k==='label' && !FIELDS[i]._keyManual) FIELDS[i].key = slugify(v); renderFields(); }
/* 標題最多接受3行手動換行（2026-08-14 使用者明確要求：Enter換行，最多3行；已滿3行時擋掉Enter）。 */
function fieldLabelKeydown(e, el){
    if (e.key !== 'Enter') return;
    var lines = (el.value.match(/\n/g) || []).length;
    if (lines >= 2) e.preventDefault();
}
function slugify(s){ return 'c_' + String(s).replace(/[^a-zA-Z0-9一-龥]+/g,'').substr(0,20) + '_' + Math.floor(Math.random()*900+100); }
function renderFields(){
    var h = '';
    FIELDS.forEach(function(c,i){
        h += '<tr class="fld-row" draggable="true" data-i="'+i+'">'
           + '<td class="fld-drag-handle"><i class="fa fa-bars"></i></td>'
           + '<td><textarea rows="2" style="resize:vertical;min-height:28px;" placeholder="Enter換行，最多3行" onkeydown="fieldLabelKeydown(event,this)" onchange="fieldEdit('+i+',\'label\',this.value)">'+esc(c.label)+'</textarea></td>'
           + '<td><select onchange="fieldEdit('+i+',\'type\',this.value)">'
           +   Object.keys(FIELD_TYPES).map(function(tp){ return '<option value="'+tp+'"'+(c.type===tp?' selected':'')+'>'+FIELD_TYPES[tp]+'</option>'; }).join('')
           + '</select></td>'
           + '<td><input type="text" value="'+esc(c.placeholder)+'" '+(c.type==='seq'?'disabled':'')+' onchange="fieldEdit('+i+',\'placeholder\',this.value)"></td>'
           + '<td><input type="text" value="'+esc(c.options)+'" '+(c.type!=='select'?'disabled':'')+' title="下拉選項用逗號分隔，例如：合格,不合格,其他" onchange="fieldEdit('+i+',\'options\',this.value)"></td>'
           + '<td style="text-align:center;"><input type="checkbox" '+(c.required?'checked':'')+' '+(c.type==='seq'?'disabled':'')+' onchange="fieldEdit('+i+',\'required\',this.checked?1:0)"></td>'
           + '<td><select onchange="fieldEdit('+i+',\'layout\',this.value)"><option value="inline"'+(c.layout==='inline'?' selected':'')+'>並排</option><option value="block"'+(c.layout==='block'?' selected':'')+'>整行</option></select></td>'
           + '<td><select '+(c.type==='text'||c.type==='textarea'?'':'disabled')+' onchange="fieldEdit('+i+',\'align\',this.value)" title="只有單行/多行文字欄位需要設定，其他類型不受影響">'
           +   ['left','center','right'].map(function(a){ return '<option value="'+a+'"'+((c.align||'left')===a?' selected':'')+'>'+({left:'靠左',center:'置中',right:'靠右'})[a]+'</option>'; }).join('')
           + '</select></td>'
           + '<td style="white-space:nowrap;font-size:11.5px;color:#5b3a1e;">'
           +   '<label style="display:block;margin:0;font-weight:normal;cursor:pointer;"><input type="checkbox" style="width:auto;" '+((c.hdr_center===undefined||Number(c.hdr_center))?'checked':'')+' title="取消＝標題靠左，預設為置中" onchange="fieldEdit('+i+',\'hdr_center\',this.checked?1:0)"> 置中</label>'
           +   '<label style="display:block;margin:0;font-weight:normal;cursor:pointer;"><input type="checkbox" style="width:auto;" '+(c.vertical?'checked':'')+' title="勾選＝這一欄的標題文字改成直書（由上而下一字一行）" onchange="fieldEdit('+i+',\'vertical\',this.checked?1:0)"> 直書</label>'
           + '</td>'
           + '<td style="text-align:center;white-space:nowrap;"><span class="rf-del" onclick="fieldDel('+i+')"><i class="fa fa-times"></i></span></td></tr>';
    });
    $('#colBody').html(h || '<tr><td colspan="10" style="text-align:center;color:#8a6d45;">尚未新增欄位</td></tr>');
}
/* ---- 直式標題（左側列標題）：模板預先定義，建立表單時自動產生成固定的列，使用者不可增刪（2026-09-09 使用者拍板） ---- */
var ROWHEADS = [];
function rowHeadAdd(){ ROWHEADS.push(''); renderRowHeads(); }
function rowHeadDel(i){ ROWHEADS.splice(i,1); renderRowHeads(); }
function rowHeadDelLast(){ if (ROWHEADS.length) ROWHEADS.pop(); renderRowHeads(); }
function rowHeadEdit(i,v){ ROWHEADS[i]=v; }
function renderRowHeads(){
    var h = ROWHEADS.map(function(t,i){
        return '<tr><td style="text-align:center;color:#8a6d45;">'+(i+1)+'</td>'
             + '<td><input type="text" maxlength="60" value="'+esc(t)+'" placeholder="例：機會" onchange="rowHeadEdit('+i+',this.value)"></td>'
             + '<td style="text-align:center;"><span class="rf-del" onclick="rowHeadDel('+i+')"><i class="fa fa-times"></i></span></td></tr>';
    }).join('');
    $('#rowHeadBody').html(h || '<tr><td colspan="3" style="text-align:center;color:#8a6d45;">尚未新增列標題</td></tr>');
}
$(document).on('change', '#scUseRowHead', function(){
    $('#rowHeadBox').toggle(this.checked);
    if (this.checked && !ROWHEADS.length) { ROWHEADS = ['','']; renderRowHeads(); }
});
/* 沒有負責單位/負責人就沒有人可以簽名，簽名方式一律鎖成「不須簽名」（後端 rvf_schema_sign_mode() 同規則再判一次）。 */
function syncSignModeEnabled(){
    var on = $('#scNeedOwner').is(':checked');
    $('input[name=signMode]').prop('disabled', !on);
    if (!on) $('input[name=signMode][value="none"]').prop('checked', true);
    $('#signModeHint').toggle(!on);
}
$(document).on('change', '#scNeedOwner', syncSignModeEnabled);
/* 拖移重排序（使用者明確要求「上下拖移」）：原生 HTML5 drag and drop，不引入額外套件。 */
var FLD_DRAG_FROM = null;
$(document).on('dragstart', '#colBody tr.fld-row', function(e){
    FLD_DRAG_FROM = $(this).data('i');
    if (e.originalEvent && e.originalEvent.dataTransfer) e.originalEvent.dataTransfer.effectAllowed = 'move';
});
$(document).on('dragover', '#colBody tr.fld-row', function(e){ e.preventDefault(); $(this).addClass('drag-over'); });
$(document).on('dragleave', '#colBody tr.fld-row', function(){ $(this).removeClass('drag-over'); });
$(document).on('drop', '#colBody tr.fld-row', function(e){
    e.preventDefault();
    $(this).removeClass('drag-over');
    var to = $(this).data('i');
    if (FLD_DRAG_FROM === null || to === FLD_DRAG_FROM) return;
    var item = FIELDS.splice(FLD_DRAG_FROM, 1)[0];
    FIELDS.splice(to, 0, item);
    FLD_DRAG_FROM = null;
    renderFields();
});
function openSchemaModal(id){
    $.getJSON(API, {action:'template_get', id:id}, function(res){
        if (!res.ok){ alert(res.error||'載入失敗'); return; }
        var t = res.template;
        CUR_SCHEMA_TPL = t;
        $('#scTplId').val(t.id);
        $('#schemaTitle').text('項次欄位定義：'+t.name);
        // fields[] 是現行格式（欄位可混合排序）；舊資料若還是 columns[]+date_fields[] 分開存放，載入時自動合併相容。
        // 存檔的 schema.options 是陣列，編輯表格內是逗號字串，載入時統一轉字串，否則存檔時 c.options.split 會炸掉（陣列沒有 split）。
        function toOptStr(c){ c.options = Array.isArray(c.options) ? c.options.join(',') : (c.options||''); return c; }
        if (t.schema.fields) {
            FIELDS = t.schema.fields.map(function(c){ return toOptStr($.extend({_keyManual:true}, c)); });
        } else {
            FIELDS = (t.schema.columns||[]).map(function(c){ return toOptStr($.extend({_keyManual:true}, c)); })
                .concat((t.schema.date_fields||[]).map(function(d){ return toOptStr($.extend({_keyManual:true, type:'date', placeholder:'', required:0, layout:'inline', options:''}, d)); }));
        }
        renderFields();
        // 表格結構（need_owner 舊資料沒有這個鍵＝維持原本一律顯示負責單位/負責人的行為）
        $('#scNeedOwner').prop('checked', t.schema.need_owner===undefined ? true : !!Number(t.schema.need_owner));
        ROWHEADS = (t.schema.row_mode==='fixed' && Array.isArray(t.schema.row_headings)) ? t.schema.row_headings.slice() : [];
        $('#scUseRowHead').prop('checked', t.schema.row_mode==='fixed');
        $('#rowHeadBox').toggle(t.schema.row_mode==='fixed');
        $('#scCornerCol').val(t.schema.corner_col_label||'');
        $('#scCornerRow').val(t.schema.corner_row_label||'');
        $('#scRowHeadVertical').prop('checked', !!Number(t.schema.row_head_vertical||0));
        $('#scRowHeadCenter').prop('checked', t.schema.row_head_center===undefined ? true : !!Number(t.schema.row_head_center));
        $('#scHeadRow').prop('checked', !!Number(t.schema.head_row||0));
        $('#scHeadRowLabel').val(t.schema.head_row_label||'');
        $('#scRowSide').prop('checked', !!Number(t.schema.row_side||0));
        $('#scRowSideLabel').val(t.schema.row_side_label||'');
        renderRowHeads();
        $('input[name=signMode][value="'+(t.schema.sign_mode||'password')+'"]').prop('checked',true);
        syncSignModeEnabled();
        renderMaintainers(t.maintainers||[]);
        $('#scYearBox').toggle(t.has_year_heading==1);
        $('#scYearFormat').val(t.schema.year_format||'ad');
        $('#scYearPos').val(t.schema.year_position||'left');
        $('#scBumpAsDoc').prop('checked',false); $('#bumpBox').hide();
        openMask('schemaMask');
    });
}
function renderMaintainers(list){
    var h = list.map(function(m){ return '<span class="tg">'+esc(m.user_cname)+'<i class="fa fa-times" onclick="maintainerRemove('+m.user_id+')"></i></span>'; }).join('');
    $('#maintTags').html(h || '<span style="color:#8a6d45;font-size:12px;">尚未指派維護人員</span>');
}
function maintainerAdd(){
    var uid = $('#maintUserSel').val();
    if (!uid){ alert('請選擇人員'); return; }
    $.post(API, {action:'maintainer_add', csrf:META.csrf, template_id:CUR_SCHEMA_TPL.id, user_id:uid}, function(res){
        if (!res.ok){ alert(res.error||'新增失敗'); return; }
        renderMaintainers(res.maintainers);
    }, 'json');
}
function maintainerRemove(uid){
    $.post(API, {action:'maintainer_remove', csrf:META.csrf, template_id:CUR_SCHEMA_TPL.id, user_id:uid}, function(res){
        if (!res.ok){ alert(res.error||'移除失敗'); return; }
        renderMaintainers(res.maintainers);
    }, 'json');
}
$('#scBumpAsDoc').on('change', function(){ $('#bumpBox').toggle(this.checked); });

function buildSchemaObj(){
    var useRow = $('#scUseRowHead').is(':checked');
    var rowHeads = ROWHEADS.map(function(s){ return $.trim(s||''); }).filter(function(s){ return s!==''; });
    return {
        fields: FIELDS.filter(function(c){ return $.trim(c.label)!==''; }).map(function(c){
            var optStr = Array.isArray(c.options) ? c.options.join(',') : String(c.options||'');
            return {key:c.key, label:c.label, type:c.type, placeholder:c.placeholder||'', required:c.required?1:0, layout:c.layout,
                     align: (c.type==='text'||c.type==='textarea') ? (c.align||'left') : 'left',
                     vertical: c.vertical?1:0, hdr_center: (c.hdr_center===undefined||Number(c.hdr_center))?1:0,
                     options: c.type==='select' ? optStr.split(',').map(function(s){return $.trim(s);}).filter(Boolean) : []};
        }),
        need_owner: $('#scNeedOwner').is(':checked') ? 1 : 0,
        row_mode: useRow ? 'fixed' : 'free',
        row_headings: useRow ? rowHeads : [],
        corner_col_label: useRow ? $.trim($('#scCornerCol').val()||'') : '',
        corner_row_label: useRow ? $.trim($('#scCornerRow').val()||'') : '',
        row_head_vertical: (useRow && $('#scRowHeadVertical').is(':checked')) ? 1 : 0,
        row_head_center: (useRow && !$('#scRowHeadCenter').is(':checked')) ? 0 : 1,
        head_row: $('#scHeadRow').is(':checked') ? 1 : 0,
        head_row_label: $('#scHeadRow').is(':checked') ? $.trim($('#scHeadRowLabel').val()||'') : '',
        row_side: (useRow && $('#scRowSide').is(':checked')) ? 1 : 0,
        row_side_label: (useRow && $('#scRowSide').is(':checked')) ? $.trim($('#scRowSideLabel').val()||'') : '',
        sign_mode: $('#scNeedOwner').is(':checked') ? ($('input[name=signMode]:checked').val() || 'password') : 'none',
        year_format: $('#scYearFormat').val() || 'ad',
        year_position: $('#scYearPos').val() || 'left'
    };
}
/* 試填預覽（2026-08-11 使用者明確要求）：用目前編輯中、尚未存檔的欄位定義開一個新分頁試填+試列印，
   完全不呼叫「儲存項次」或建立任何表單資料，只是把目前畫面上的設定丟到 sessionStorage 讓 review_form.php
   讀取渲染，方便邊調欄位定義邊看實際排版，不會因為送出/審核而弄髒正式資料。 */
function previewSchema(){
    var payload = {
        schema: buildSchemaObj(),
        tpl_id: CUR_SCHEMA_TPL.id, tpl_name: CUR_SCHEMA_TPL.name, paper_size: CUR_SCHEMA_TPL.paper_size
    };
    sessionStorage.setItem('rvf_preview_payload', JSON.stringify(payload));
    window.open('review_form.php?preview=1', '_blank');
}
function submitSchema(){
    if ($('#scUseRowHead').is(':checked') && !ROWHEADS.filter(function(s){ return $.trim(s||'')!==''; }).length) {
        alert('已勾選「使用直式標題」，請至少填一個列標題（或取消勾選）'); return;
    }
    var schema = buildSchemaObj();
    if (!$('#scBumpAsDoc').is(':checked')) {
        doSchemaSave(schema, null); return;
    }
    var fd = new FormData();
    fd.append('action','add_version'); fd.append('doc_id', CUR_SCHEMA_TPL.as_doc ? CUR_SCHEMA_TPL.as_doc.id : 0);
    fd.append('version', $('#bumpVersion').val()); fd.append('revised_date', $('#bumpDate').val());
    fd.append('revised_summary', $('#bumpSummary').val());
    if ($('#bumpFile')[0].files[0]) fd.append('file', $('#bumpFile')[0].files[0]);
    if ($('#bumpApply')[0].files[0]) fd.append('apply_form', $('#bumpApply')[0].files[0]);
    if (!CUR_SCHEMA_TPL.as_doc){ alert('此模板尚未綁定 AS 文件，請先請管理員到「編輯設定」綁定'); return; }
    fetch(ASDOC_API, {method:'POST', body:fd}).then(function(r){ return r.json(); }).then(function(r){
        if (r.status !== 'success'){ alert(r.message||'AS文件改版失敗'); return; }
        doSchemaSave(schema, r.version_id);
    }).catch(function(){ alert('AS文件改版失敗（連線錯誤）'); });
}
function doSchemaSave(schema, bumpedVersionId){
    $.post(API, {
        action:'template_schema_save', csrf:META.csrf, template_id:CUR_SCHEMA_TPL.id,
        schema: JSON.stringify(schema), bumped_as_doc_version_id: bumpedVersionId||0
    }, function(res){
        if (!res.ok){ alert(res.error||'儲存失敗'); return; }
        closeMask('schemaMask'); loadTemplates();
        // res.version 是內部 schema 快照序號(供舊表單對應建立當下的欄位定義用)，不是 AS 文件的正式版次，
        // 沒勾「連動更新AS文件版次」時不要提到「版」字，否則使用者會誤以為文件正式改版了。
        alert(bumpedVersionId ? '已儲存，AS 文件版次已一併更新' : '已儲存');
    }, 'json');
}

loadMeta(loadTemplates);
</script>
</body>
</html>
