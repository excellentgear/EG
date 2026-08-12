<?php
/**
 * 產品開發評估表（AS 2-TD-02-01）
 * 固定模板 32 項確認項目（人/機/料/法/發展/產品安全/仿冒零件的防制/其他）+ APQP 小組簽認
 * （技術/業務/管理/生產/品保/資材課，各部門任一主管皆可簽）+ 生產課可行性決行 + 總經理決行。
 * 資料/權限/簽核人解析見 src/common/td_dev_eval_lib.php；資料操作走 src/store/TdDevEval_API.php。
 * 部門簽核人綁定：views/admin/org_role_setting.php，重用全站既有部門角色（技術=rd_dept／業務=sales_dept／
 * 管理=hr_dept／生產=prod_dept／品保=qc_dept，本來就設定過），僅資材課無對應部門，新增 material_dept 角色。
 * 總經理欄沿用全站 top_approver + delegate_lib 代理解析（ai-rules/18）。
 */
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/TD/td_dev_eval.php";
    header("Location:../../index.php");
    exit;
}
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/asdoc_lib.php';
include_once '../../src/common/org_role_lib.php';
include_once '../../src/common/confirm_password_lib.php';
include_once '../../src/common/td_dev_eval_lib.php';
include_once '../../src/common/td_dev_eval_suggest_lib.php';

$db = (new DBConnection())->getPDO();
eg_org_ensure_schema($db);
td_dev_eval_ensure_schema($db);
td_dev_eval_suggest_ensure_schema($db);
$teUser = td_dev_eval_current_user($db);
$perms = td_dev_eval_perms($db, $teUser);
$suggestPending = $perms['canAdmin'] ? td_dev_eval_suggest_pending_count($db) : 0;
$canBackfill = $teUser ? eg_confirm_password_allowed($db, (int)$teUser['id']) : false;
$roleLabel = $perms['isAdmin'] ? '管理者' : ($perms['canAdmin'] ? '評估表管理員' : ($perms['canEdit'] ? '評估表登錄' : ($perms['canView'] ? '評估表檢閱' : '無權限')));
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>產品開發評估表</title>
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
        .te-toolbar { display:flex; flex-wrap:wrap; gap:6px; align-items:center; clear:both;
            border:1.5px solid #E8D5B5; border-radius:8px; padding:8px 10px; margin-bottom:10px; background:#FDF8EF; }
        .te-toolbar label { margin:0; font-size:13px; color:#5b3a1e; }
        .te-toolbar input[type=text], .te-toolbar button {
            height:30px; font-size:13px; line-height:1; padding:0 10px; border:1px solid #D8BE93;
            border-radius:4px; background:#fff; color:#5b3a1e; cursor:pointer; }
        .te-toolbar button:hover { background:#F7E0BD; }
        .te-toolbar .btn-warm { background:#F0A24B; color:#fff; border-color:#d98a33; }
        .te-toolbar .btn-warm:hover { background:#d98a33; }
        .te-role-badge { margin-left:auto; font-size:13px; color:#5b3a1e; background:#F7E0BD; border-radius:12px; padding:4px 12px; }
        .te-role-badge .fa-question-circle { cursor:pointer; color:#b5762a; margin-left:5px; }
        .te-table-wrap { overflow-x:auto; border:1px solid #E8D5B5; border-radius:6px; background:#fff; }
        table.te-table { width:100%; border-collapse:collapse; font-size:13px; }
        table.te-table th, table.te-table td { border:1px solid #EADFC8; padding:5px 8px; text-align:center; }
        table.te-table thead th { background:#F7E0BD; color:#5b3a1e; font-weight:bold; }
        table.te-table tbody tr:nth-child(even) { background:#FBF6EC; }
        table.te-table tbody tr:hover { background:#FBF0DD; }
        table.te-table td.t-left { text-align:left; }
        .te-op { color:#b5762a; cursor:pointer; margin:0 4px; }
        .te-op:hover { color:#8A5A2B; text-decoration:underline; }
        .te-mask { display:none; position:fixed; inset:0; background:rgba(60,40,20,.45); z-index:1050; }
        .te-modal { background:#fff; border-radius:8px; max-width:600px; margin:36px auto; box-shadow:0 5px 25px rgba(0,0,0,.3);
            max-height:90vh; display:flex; flex-direction:column; }
        .te-modal.xwide { max-width:1180px; }
        .te-modal .m-head { background:#F7E0BD; color:#5b3a1e; font-weight:bold; padding:10px 15px; border-radius:8px 8px 0 0;
            display:flex; justify-content:space-between; }
        .te-modal .m-head .m-close { cursor:pointer; color:#b5762a; }
        .te-modal .m-body { padding:15px; overflow-y:auto; }
        .te-modal .m-body label { display:block; font-size:13px; color:#5b3a1e; margin:9px 0 3px; }
        .te-modal .m-body input[type=text], .te-modal .m-body input[type=date], .te-modal .m-body input[type=number],
        .te-modal .m-body select, .te-modal .m-body textarea { width:100%; border:1px solid #D8BE93; border-radius:4px;
            padding:5px 8px; font-size:13px; box-sizing:border-box; }
        .te-modal .m-foot { padding:10px 15px; border-top:1px solid #EADFC8; text-align:right; }
        .te-modal .m-foot button { height:30px; padding:0 16px; border-radius:4px; font-size:13px; border:1px solid #d98a33; cursor:pointer; }
        .te-modal .m-foot .b-ok { background:#F0A24B; color:#fff; }
        .te-modal .m-foot .b-cancel { background:#fff; color:#5b3a1e; border-color:#D8BE93; margin-right:6px; }
        .te-head-grid { display:grid; grid-template-columns:1fr 1fr 1fr; gap:0 14px; }
        .te-row-btn { border:1px solid #D8BE93; background:#fff; color:#5b3a1e; border-radius:4px; padding:2px 6px; font-size:11px; cursor:pointer; }
        .te-row-btn:hover { background:#F7E0BD; }
        .te-noperm { margin:40px auto; max-width:520px; text-align:center; border:1.5px solid #E8D5B5; border-radius:10px;
            padding:30px; background:#FDF8EF; color:#5b3a1e; }
        .te-sec-title { font-size:14px; font-weight:bold; color:#8A5A2B; border-left:4px solid #F0A24B; padding-left:8px; margin:16px 0 6px; }
        table.te-chk { width:100%; border-collapse:collapse; font-size:12px; margin-top:4px; }
        table.te-chk th, table.te-chk td { border:1px solid #EADFC8; padding:3px 5px; }
        table.te-chk thead th { background:#F7E0BD; color:#5b3a1e; }
        table.te-chk td.cat { background:#FBF3E5; font-weight:bold; white-space:nowrap; }
        table.te-chk td.q { text-align:left; }
        table.te-chk td.unit { white-space:nowrap; color:#8a6d45; }
        .te-radio-grp { display:flex; gap:8px; justify-content:center; white-space:nowrap; }
        .te-radio-grp label { margin:0; font-weight:normal; cursor:pointer; font-size:12px; }
        table.te-slot { width:100%; border-collapse:collapse; font-size:12px; margin-top:4px; }
        table.te-slot th, table.te-slot td { border:1px solid #EADFC8; padding:5px 6px; vertical-align:top; }
        table.te-slot thead th { background:#F7E0BD; color:#5b3a1e; }
        table.te-slot td.dept { font-weight:bold; color:#8A5A2B; white-space:nowrap; width:80px; }
        table.te-slot textarea.note-in { min-height:44px; resize:vertical; }
        .te-sign-cell { white-space:nowrap; }
        .te-sign-done { color:#8A5A2B; font-weight:bold; }
        .te-sign-todo { color:#b0a390; }
        .te-badge-deputy { font-size:10px; color:#fff; background:#DD5138; border-radius:8px; padding:0 6px; margin-left:4px; }
        .te-badge-backfill { font-size:10px; color:#7a5217; background:#F7E0BD; border-radius:8px; padding:0 6px; margin-left:4px; }
        .te-pool-hint { font-size:11px; color:#8a6d45; }
        .te-decision-grp { display:flex; gap:14px; flex-wrap:wrap; margin:6px 0; }
        .te-decision-grp label { margin:0; cursor:pointer; font-size:13px; }
        .te-status { font-size:11px; padding:2px 8px; border-radius:10px; white-space:nowrap; }
        .te-status-draft { background:#EADFC8; color:#5b3a1e; }
        .te-status-submitted { background:#F7E0BD; color:#8A5A2B; }
        .te-status-closed { background:#F0A24B; color:#fff; }
        .te-hdr-locked-tip { background:#FFF7E8; border:1px dashed #F0A24B; border-radius:6px; padding:6px 10px; margin-bottom:8px; font-size:12px; color:#8A5A2B; }
        .te-blocked-hint { font-size:11px; color:#b0a390; }
        table.te-chk td.q.te-mine { background:#FFF7E8; }
        table.te-slot .te-item-mini { width:100%; border-collapse:collapse; margin-top:6px; font-size:11px; }
        table.te-slot .te-item-mini td { border:1px solid #EADFC8; padding:2px 4px; }
        table.te-slot .te-item-mini td.q { text-align:left; }
        .te-admin-panel { border:1.5px dashed #DD5138; border-radius:8px; padding:10px; margin-top:14px; background:#FFF3EE; }
        .te-admin-panel h5 { margin:0 0 8px; color:#DD5138; font-size:13px; }
        @media print { .te-toolbar, .nav_menu, .left_col, footer { display:none !important; } .right_col { margin:0 !important; padding:0 !important; } }
    </style>
</head>
<body class="nav-sm">
<div class="container body">
<div class="main_container">
    <?php include '../partPage/sideAndTopBarMenu.html' ?>
    <div class="right_col" role="main">
        <div class="page-title" style="display:flex;align-items:center;flex-wrap:wrap;">
            <h2 style="margin:6px 0;">產品開發評估表
                <small style="color:#8a6d45;">AS文件編號：<span id="hdrAsDocNo">載入中…</span> ｜ 2-TD-02-01</small></h2>
            <button id="btnPageHelp" class="page-help-btn" style="margin-left:auto;"><i class="fa fa-question-circle"></i> 使用說明</button>
        </div>
        <div class="clearfix"></div>

<?php if (!$perms['canView']): ?>
        <div class="te-noperm">
            <h4><i class="fa fa-lock"></i> 無產品開發評估表檢閱權限</h4>
            <p>請洽管理者於「使用者權限設定」指派「評估表檢閱／登錄／管理員」角色。</p>
        </div>
<?php else: ?>
        <div class="te-toolbar">
            <label>搜尋</label>
            <input type="text" id="kwInput" placeholder="表單編號／料號／產品名稱／客戶" style="width:200px;">
            <button class="btn-warm" id="btnAdd" style="<?= $perms['canEdit']?'':'display:none;' ?>"><i class="fa fa-plus"></i> 新增</button>
            <button id="btnSuggest" style="<?= $perms['canAdmin']?'':'display:none;' ?>"><i class="fa fa-lightbulb-o"></i> 建議建立料號清單<?= $suggestPending>0 ? ' <span class="te-badge-deputy" style="background:#DD5138;">'.(int)$suggestPending.'</span>' : '' ?></button>
            <button id="btnAsDoc" style="<?= $perms['canAdmin']?'':'display:none;' ?>"><i class="fa fa-link"></i> AS文件綁定</button>
            <button id="btnCsv"><i class="fa fa-file-text-o"></i> 匯出CSV</button>
            <span class="te-role-badge">目前角色：<b><?= htmlspecialchars($roleLabel) ?></b>
                <i class="fa fa-question-circle" id="btnRoleHelp" title="角色權限說明"></i></span>
        </div>

        <div class="te-table-wrap">
            <table class="te-table" id="teTable">
                <thead><tr>
                    <th>表單編號</th><th>客戶名稱</th><th>產品件號</th><th>產品名稱</th>
                    <th>填表日期</th><th>狀態</th><th>決行</th><th>簽核進度</th><th>操作</th>
                </tr></thead>
                <tbody id="teBody"><tr><td colspan="9" style="padding:20px;color:#8a6d45;">載入中…</td></tr></tbody>
            </table>
        </div>
<?php endif; ?>
    </div>
</div>
</div>

<!-- 新增/編輯 -->
<div class="te-mask" id="editMask"><div class="te-modal xwide">
    <div class="m-head"><span id="editTitle">產品開發評估表</span> <span class="te-status" id="editStatusBadge" style="display:none;"></span><span class="m-close" onclick="closeMask('editMask')">✕</span></div>
    <div class="m-body">
        <div class="te-hdr-locked-tip" id="hdrLockedTip" style="display:none;">
            <i class="fa fa-lock"></i> 已送出：表頭與確認項目改由各部門於下方「APQP 小組簽認」自行填寫本部門負責的項次，僅系統管理員可整批修改。
        </div>
        <div class="te-head-grid">
            <div>
                <label>客戶名稱</label>
                <input type="text" id="fCustomerName" placeholder="選定產品件號後自動帶出，亦可手動輸入">
            </div>
            <div>
                <label>產品件號(料號)</label>
                <input type="text" id="fPartNo" placeholder="輸入部分料號或圖號搜尋；查無時可直接手動輸入新產品件號" autocomplete="off">
                <input type="hidden" id="fPartDId" value="0">
            </div>
            <div>
                <label>產品名稱</label>
                <input type="text" id="fProductName">
            </div>
            <div>
                <label>預估需求量 (PCS/月)</label>
                <input type="number" id="fEstQty" min="0">
            </div>
            <div>
                <label>填表日期</label>
                <input type="date" id="fFillDate">
            </div>
            <div>
                <label>送樣時間</label>
                <input type="text" id="fSampleTime" placeholder="例：2週內">
            </div>
        </div>
        <div style="margin-top:6px;font-size:12px;color:#8a6d45;">表單編號：<b id="fDocNo">存檔後自動產生</b>
            ｜ 建立：<span id="fCreatedInfo">—</span></div>

        <div class="te-sec-title">確認項目及結果</div>
        <table class="te-chk">
            <thead><tr><th style="width:60px;">區分</th><th style="width:36px;">項次</th><th>評估項目</th>
                <th style="width:70px;">評估單位</th><th style="width:120px;">評估結果</th></tr></thead>
            <tbody id="chkBody"></tbody>
        </table>

        <div class="te-sec-title">APQP 小組簽認</div>
        <table class="te-slot">
            <thead><tr><th class="dept">單位</th><th>意見</th><th style="width:220px;">簽核</th></tr></thead>
            <tbody id="apqpBody"></tbody>
        </table>

        <div class="te-sec-title">生產課決行</div>
        <div class="te-decision-grp" id="decisionGrp"></div>
        <table class="te-slot"><tbody id="prodDecisionBody"></tbody></table>

        <div class="te-sec-title">總經理決行</div>
        <table class="te-slot"><tbody id="gmBody"></tbody></table>

        <div style="margin-top:10px;<?= $canBackfill?'':'display:none;' ?>">
            <button type="button" class="te-row-btn" id="btnBackfillOpen"><i class="fa fa-user-secret"></i> 補登簽核（操作確認密碼）</button>
        </div>

        <div class="te-admin-panel" id="adminQuickPanel" style="<?= $perms['isAdmin']?'':'display:none;' ?>">
            <h5><i class="fa fa-user-secret"></i> 系統管理員快速設定（僅補歷史紙本資料用，會跳過送出/簽核流程）</h5>
            <div style="font-size:12px;color:#8a6d45;margin-bottom:6px;">上方「確認項目及結果」32項與各部門簽認欄位不受一般流程限制，管理員可直接編輯後按下方按鈕，一次把尚未簽核的欄位全部自動簽核。</div>
            <label style="display:inline-block;margin:0 8px 0 0;">簽核業務日期</label>
            <input type="date" id="adminAutoSignDate" style="width:160px;display:inline-block;">
            <button type="button" class="te-row-btn" id="btnAdminAutoSignAll" style="margin-left:6px;"><i class="fa fa-magic"></i> 全部自動簽核</button>
        </div>
    </div>
    <div class="m-foot">
        <button class="b-cancel" onclick="closeMask('editMask')">取消</button>
        <button class="b-ok" id="btnSave" onclick="saveHeader()">儲存</button>
        <button class="b-ok" id="btnSubmitDoc" style="background:#DD5138;border-color:#c9432a;" onclick="submitDoc()"><i class="fa fa-paper-plane"></i> 送出</button>
    </div>
</div></div>

<!-- 補登簽核 -->
<div class="te-mask" id="backfillMask" style="z-index:1200;"><div class="te-modal">
    <div class="m-head"><span>補登簽核</span><span class="m-close" onclick="closeMask('backfillMask')">✕</span></div>
    <div class="m-body">
        <div class="tip">補資料用：把還沒有人即時簽核的欄位一次補齊。<b>每一格仍須指定當初實際簽核的人</b>，系統會記錄「此筆為補登、由誰執行補登」，不會顯示成您本人簽核。</div>
        <div id="backfillList"></div>
        <label style="margin-top:10px;">操作確認密碼</label>
        <input type="password" id="backfillPassword" autocomplete="off">
    </div>
    <div class="m-foot">
        <button class="b-cancel" onclick="closeMask('backfillMask')">取消</button>
        <button class="b-ok" onclick="submitBackfill()">送出補登</button>
    </div>
</div></div>

<!-- AS 文件綁定 -->
<div class="te-mask" id="asDocMask"><div class="te-modal">
    <div class="m-head"><span>AS 文件編號綁定</span><span class="m-close" onclick="closeMask('asDocMask')">✕</span></div>
    <div class="m-body">
        <div style="margin-bottom:8px;">目前綁定：<b id="asDocLabel">尚未綁定</b></div>
        <button type="button" class="te-row-btn" onclick="openAsDocPicker()">變更綁定</button>
    </div>
    <div class="m-foot"><button class="b-cancel" onclick="closeMask('asDocMask')">關閉</button></div>
</div></div>

<!-- 角色權限說明 -->
<div class="te-mask" id="roleHelpMask"><div class="te-modal">
    <div class="m-head"><span>角色權限說明</span><span class="m-close" onclick="closeMask('roleHelpMask')">✕</span></div>
    <div class="m-body" style="font-size:13px;color:#5b3a1e;line-height:1.8;">
        <b>評估表檢閱</b>：檢視清單、開啟查看、列印。<br>
        <b>評估表登錄</b>：檢閱＋新增/編輯、逐項填寫、本人依部門身分簽核。<br>
        <b>評估表管理員</b>：登錄＋刪除、AS 文件編號綁定、取消他人簽核。<br>
        <b>管理者</b>：系統管理者固定擁有全部權限。<br>
        <hr style="border-color:#EADFC8;">
        APQP 小組簽認各部門欄位由「該部門任一主管」簽核，重用<a href="../admin/org_role_setting.php" target="_blank">組織角色綁定設定</a>頁全站既有的部門綁定（技術／業務／管理／生產／品保部門本來就設定過，資材部門為本表新增）；總經理欄沿用全站「最高核准人員」設定。<br>
        角色指派請洽管理者於「使用者權限設定」（<a href="../user/user_permissions.php" target="_blank">開啟</a>）→「產品開發評估表」區塊。未被指派角色者無法進入本頁。
    </div>
</div></div>

<div class="te-mask" id="helpUseMask"><div class="te-modal xwide">
    <div class="m-head"><span><i class="fa fa-question-circle"></i> 產品開發評估表 使用說明</span><span class="m-close" onclick="closeMask('helpUseMask')">✕</span></div>
    <div class="m-body help-doc">
        <h4>功能說明</h4>
        <p>APQP 產品開發階段的評估表（2-TD-02-01），固定 32 項確認項目（人/機/料/法/發展/產品安全/仿冒零件的防制/其他），逐項填是／否／N-A，再由技術/業務/管理/生產/品保/資材課六個單位分別簽認意見，最後由生產課決行（可行自製／可行委外／再評估／中止）並送總經理決行。</p>
        <h4>操作步驟</h4>
        <ul>
            <li>按「新增」→ 填客戶名稱、產品件號（打部分字元搜尋，選定後自動帶出客戶名稱；查無此料號時可直接手動輸入新產品件號，不強制要求已存在的料號）、產品名稱、預估需求量、填表日期、送樣時間。</li>
            <li>逐項勾選 32 項確認結果（是／否／N-A）。</li>
            <li>APQP 小組簽認：屬於該部門且有權限登錄的使用者，會在自己部門那一列看到「簽核」按鈕，填意見後按下即完成簽核並蓋章；六個部門任一位主管都可以簽，不限定特定一人。</li>
            <li>六部門簽認後由生產課勾選「可行自製／可行委外／再評估／中止」並簽核，最後由總經理決行簽核。</li>
        </ul>
        <h4>建議建立料號清單（管理員）</h4>
        <ul>
            <li>工具列「建議建立料號清單」按鈕（僅管理員可見，紅色數字為近一年候選筆數提醒）：依管理員設定的客戶名單，掃描區間內曾有訂單/報工/BOM/出貨記錄、但尚未建立評估表的料號，可一次批次建立多筆草稿，避免漏建。詳見該頁「使用說明」。</li>
        </ul>
        <h4>補登簽核（歷史紙本資料建檔用）</h4>
        <ul>
            <li>具「操作確認密碼」資格者，可用「補登簽核」一次把尚未簽核的欄位補齊——<b>仍須逐格指定當初實際簽核的人</b>，系統會清楚記錄「此筆為補登，由誰執行補登」，跟本人即時線上簽核的紀錄分開標示，不會誤植成操作者本人簽的。</li>
        </ul>
        <h4>其他行為／常見疑問</h4>
        <ul>
            <li>產品件號可點擊開啟圖面查閱（比照報價單頁做法）。</li>
            <li>簽章使用全站通用圓形姓名章（若本人有上傳掃描實體章會優先用掃描章）；由代理人代簽時右下角加「代」字。</li>
            <li>列印比照全站標準（ai-rules/16）：大標題為本公司名稱、頁尾右下角印本頁綁定的 AS 文件編號。</li>
        </ul>
        <h4>設定入口</h4>
        <p>AS 文件編號綁定：工具列「AS文件綁定」按鈕（僅管理員可見）。<b>部門簽核人</b>：<a href="../admin/org_role_setting.php" target="_blank">組織角色綁定設定</a>頁「一、部門綁定」，重用全站既有的技術／業務／管理／生產／品保部門綁定，資材部門若尚未設定請在該頁一併綁定。<b>總經理</b>沿用該頁「最高核准人員」設定。<b>角色指派</b>：<a href="../user/user_permissions.php" target="_blank">使用者權限設定</a>頁→「產品開發評估表」區塊。</p>
        <h4>權限角色</h4>
        <p>評估表檢閱／登錄／管理員（管理者固定擁有全部權限）。</p>
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
var API = '../../src/store/TdDevEval_API.php';
var PART_API = '../../src/store/PartPicker_API.php';
var VIEWER_URL = '../pm/bom_viewer.php';
var CAN_EDIT = <?= $perms['canEdit'] ? 'true' : 'false' ?>;
var CAN_ADMIN = <?= $perms['canAdmin'] ? 'true' : 'false' ?>;
var IS_SUPER_ADMIN = <?= $perms['isAdmin'] ? 'true' : 'false' ?>;
var CUR_USER_NAME = <?= json_encode($teUser ? $teUser['user_cname'] : '', JSON_UNESCAPED_UNICODE) ?>;
var CUR_ID = 0, CUR_STATUS = 'draft', TEMPLATE = {}, SLOTS = {}, DECISIONS = {}, AS_DOCS = [], AS_DOC = null, CUR_SLOTS = {};
var RESULT_OPTS = [['yes','是'],['no','否'],['na','N/A']];
var STATUS_LABELS = {draft:'草稿', submitted:'簽核中', closed:'已結案'};

function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];}); }
function closeMask(id){ document.getElementById(id).style.display='none'; }
function openMask(id){ document.getElementById(id).style.display='block'; }
function fmtDate(s){ return (window.egFmtDate ? egFmtDate(s) : (s||'')); }
function stampHtml(name, date, isDeputy){
    try { if (window.EGStamp && EGStamp.stamp) return EGStamp.stamp(name, date||'', !!isDeputy); } catch(e){}
    return esc(name);
}

/* ---------- 載入固定模板 ---------- */
function loadTemplate(cb){
    $.getJSON(API, {action:'get_template'}, function(res){
        if (!res.success) return;
        TEMPLATE = res.template; SLOTS = res.slots; DECISIONS = res.decisions;
        if (cb) cb();
    });
}

/* ---------- 清單 ---------- */
function loadList(){
    $.getJSON(API, {action:'list', kw:$('#kwInput').val()||''}, function(res){
        if (!res.success){ $('#teBody').html('<tr><td colspan="9" style="padding:20px;color:#DD5138;">'+esc(res.message||'載入失敗')+'</td></tr>'); return; }
        if (!res.rows.length){ $('#teBody').html('<tr><td colspan="9" style="padding:20px;color:#8a6d45;">尚無資料</td></tr>'); return; }
        var html = '';
        res.rows.forEach(function(r){
            var slotTotal = Object.keys(SLOTS).length || 8;
            html += '<tr>'
                + '<td>'+esc(r.doc_no)+'</td>'
                + '<td>'+esc(r.customer_name||'')+'</td>'
                + '<td class="t-left">'+(r.part_no?EGPartPicker.viewerLink(r.part_no, VIEWER_URL):'')+'</td>'
                + '<td class="t-left">'+esc(r.product_name||'')+'</td>'
                + '<td>'+fmtDate(r.fill_date)+'</td>'
                + '<td><span class="te-status te-status-'+esc(r.status)+'">'+esc(r.status_label||r.status)+'</span></td>'
                + '<td>'+esc(r.decision_label||'')+'</td>'
                + '<td>'+r.signed_count+' / '+slotTotal+(r.is_complete?' <i class="fa fa-check-circle" style="color:#8A5A2B;"></i>':'')+'</td>'
                + '<td>'
                + '<span class="te-op" title="'+(CAN_EDIT?'編輯':'檢視')+'" onclick="openEdit('+r.id+')"><i class="fa fa-'+(CAN_EDIT?'pencil':'eye')+'"></i></span>'
                + '<span class="te-op" title="列印" onclick="printDoc('+r.id+')"><i class="fa fa-print"></i></span>'
                + (CAN_ADMIN ? '<span class="te-op" title="刪除" onclick="delDoc('+r.id+')"><i class="fa fa-trash"></i></span>' : '')
                + '</td></tr>';
        });
        $('#teBody').html(html);
    });
}
var kwT=null;
$('#kwInput').on('input', function(){ clearTimeout(kwT); kwT=setTimeout(loadList, 300); });
$('#btnCsv').on('click', function(){
    $.getJSON(API, {action:'list', kw:$('#kwInput').val()||''}, function(res){
        if (!res.success) return;
        var lines = ['表單編號,客戶名稱,產品件號,產品名稱,填表日期,狀態,決行,簽核進度,建立人,建立時間'];
        var slotTotal = Object.keys(SLOTS).length || 8;
        res.rows.forEach(function(r){
            lines.push([r.doc_no, r.customer_name||'', r.part_no||'', r.product_name||'', fmtDate(r.fill_date), r.status_label||r.status,
                r.decision_label||'', r.signed_count+'/'+slotTotal, r.created_by_name||'', (r.created_at||'').substring(0,10)]
                .map(function(v){ return '"'+String(v).replace(/"/g,'""')+'"'; }).join(','));
        });
        var blob = new Blob(["\uFEFF"+lines.join("\n")], {type:'text/csv;charset=utf-8;'});
        var a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = '產品開發評估表.csv';
        document.body.appendChild(a); a.click(); document.body.removeChild(a);
    });
});

/* ---------- 新增/編輯 ---------- */
/** 送出後，某項次是否為「目前登入者可以填」的項次：屬於自己現在能簽的部門欄，且該欄尚未簽核 */
function itemOwnerSlot(no){
    var found = null;
    Object.keys(CUR_SLOTS).forEach(function(k){
        var s = CUR_SLOTS[k];
        if (s && s.item_nos && s.item_nos.indexOf(parseInt(no,10)) >= 0) found = k;
    });
    return found;
}
function itemEditable(no){
    if (IS_SUPER_ADMIN) return true;
    if (CUR_STATUS === 'draft') return CAN_EDIT;
    if (CUR_STATUS === 'submitted') {
        var k = itemOwnerSlot(no), s = k ? CUR_SLOTS[k] : null;
        return !!(s && s.can_sign);
    }
    return false;
}
function renderChecklist(answers){
    answers = answers || {};
    var html = '', lastCat = null;
    Object.keys(TEMPLATE).forEach(function(no){
        var t = TEMPLATE[no], cat = t[0], text = t[1], unit = t[2];
        var editable = itemEditable(no);
        var mine = CUR_STATUS === 'submitted' && !IS_SUPER_ADMIN && editable;
        var radios = RESULT_OPTS.map(function(o){
            return '<label><input type="radio" name="res_'+no+'" value="'+o[0]+'"'+(answers[no]===o[0]?' checked':'')+(editable?'':' disabled')+'> '+o[1]+'</label>';
        }).join('');
        html += '<tr data-item-no="'+no+'">'
            + (cat!==lastCat ? '<td class="cat" rowspan="'+catSpan(cat)+'">'+esc(cat)+'</td>' : '')
            + '<td>'+no+'</td><td class="q'+(mine?' te-mine':'')+'" title="'+(mine?'待您填寫':'')+'">'+esc(text)+'</td><td class="unit">'+esc(unit)+'</td>'
            + '<td><div class="te-radio-grp">'+radios+'</div></td></tr>';
        lastCat = cat;
    });
    $('#chkBody').html(html);
}
function catSpan(cat){
    var n = 0;
    Object.keys(TEMPLATE).forEach(function(no){ if (TEMPLATE[no][0] === cat) n++; });
    return n;
}
function collectAnswers(){
    var out = {};
    Object.keys(TEMPLATE).forEach(function(no){
        var v = $('input[name="res_'+no+'"]:checked').val();
        if (v) out[no] = v;
    });
    return out;
}

function slotRowHtml(slotKey, s){
    var label = SLOTS[slotKey] ? SLOTS[slotKey][0] : slotKey;
    var signCell;
    if (s.signed_by_name) {
        signCell = '<span class="te-sign-done">' + stampHtml(s.signed_by_name, fmtDate((s.signed_at||'').substring(0,10)), s.is_deputy) + '</span>'
            + (s.is_deputy ? '<span class="te-badge-deputy">代</span>' : '')
            + (s.is_backfill ? '<span class="te-badge-backfill" title="'+esc(s.backfill_by_name||'')+' 補登">補登</span>' : '');
    } else if (s.can_sign) {
        signCell = '<button type="button" class="te-row-btn" onclick="signSlot(\''+slotKey+'\')"><i class="fa fa-pencil-square-o"></i> 我要簽核</button>';
    } else if (s.blocked_reason) {
        signCell = '<span class="te-blocked-hint"><i class="fa fa-hourglass-half"></i> '+esc(s.blocked_reason)+'</span>';
    } else {
        var names = (s.pool||[]).map(function(p){ return esc(p.user_cname); }).join('、');
        signCell = '<span class="te-sign-todo">待簽核</span><br><span class="te-pool-hint">'+(names?('可簽核：'+names):'尚未設定部門簽核人')+'</span>';
    }
    var itemHint = (s.item_nos && s.item_nos.length)
        ? '<div class="te-blocked-hint">本部門負責項次：'+s.item_nos.join('、')+(s.can_sign?'（請至上方「確認項目及結果」表格內醒目標示列填寫）':'')+'</div>' : '';
    return '<tr data-slot="'+slotKey+'"><td class="dept">'+esc(label)+'</td>'
        + '<td>'+itemHint+'<textarea class="note-in" data-slot-note="'+slotKey+'" placeholder="意見(非必填)"'+(s.can_sign?'':' readonly')+'>'+esc(s.note||'')+'</textarea></td>'
        + '<td class="te-sign-cell">'+signCell+'</td></tr>';
}
function renderSlots(slots){
    var apqpHtml = '', prodDecHtml = '', gmHtml = '';
    ['tech','sales','mgmt','prod','qa','material'].forEach(function(k){ apqpHtml += slotRowHtml(k, slots[k]); });
    prodDecHtml = slotRowHtml('prod_decision', slots['prod_decision']);
    gmHtml = slotRowHtml('gm', slots['gm']);
    $('#apqpBody').html(apqpHtml);
    $('#prodDecisionBody').html(prodDecHtml);
    $('#gmBody').html(gmHtml);
}
function renderDecisionGrp(cur, prodSlot){
    var editable = IS_SUPER_ADMIN || (prodSlot && prodSlot.can_sign);
    var html = '';
    Object.keys(DECISIONS).forEach(function(k){
        html += '<label><input type="radio" name="decision" value="'+k+'"'+(cur===k?' checked':'')+(editable?'':' disabled')+'> '+esc(DECISIONS[k])+'</label>';
    });
    $('#decisionGrp').html(html);
}
$(document).on('change', 'input[name="decision"]', function(){
    if (!CUR_ID) return;
    $.post(API, {action:'save_decision', id:CUR_ID, decision:$(this).val()}, function(res){
        if (!res.success){ alert(res.message||'儲存失敗'); openEdit(CUR_ID); } // 點開即刷新鐵則
    }, 'json');
});

/** 依目前狀態顯示：草稿=可編表頭+存檔+送出；已送出/已結案=鎖表頭、隱藏存檔與送出(管理員仍可存檔) */
function applyStatusUI(){
    var badge = $('#editStatusBadge');
    if (CUR_ID) {
        badge.show().attr('class', 'te-status te-status-'+CUR_STATUS).text(STATUS_LABELS[CUR_STATUS]||CUR_STATUS);
    } else badge.hide();
    var locked = CUR_ID && CUR_STATUS !== 'draft' && !IS_SUPER_ADMIN;
    $('#hdrLockedTip').toggle(!!locked);
    $('#fCustomerName,#fPartNo,#fProductName,#fEstQty,#fFillDate,#fSampleTime').prop('disabled', !!locked);
    $('#btnSave').toggle(!locked);
    $('#btnSubmitDoc').toggle(!!CUR_ID && CUR_STATUS === 'draft' && CAN_EDIT);
    $('#adminAutoSignDate').val($('#adminAutoSignDate').val() || (new Date()).toISOString().substring(0,10));
    $('#btnAdminAutoSignAll').prop('disabled', !CUR_ID);
}
function resetEditForm(){
    CUR_ID = 0; CUR_STATUS = 'draft'; CUR_SLOTS = {};
    $('#fCustomerName').val(''); $('#fPartNo').val(''); $('#fPartDId').val('0');
    $('#fProductName').val(''); $('#fEstQty').val(''); $('#fFillDate').val(''); $('#fSampleTime').val('');
    $('#fDocNo').text('存檔後自動產生'); $('#fCreatedInfo').text('—');
    renderChecklist({});
    renderDecisionGrp('', null);
    applyStatusUI();
}
function openEdit(id){
    resetEditForm();
    $('#editTitle').text(id ? '編輯產品開發評估表' : '新增產品開發評估表');
    if (!id){
        var blankSlots = {}; Object.keys(SLOTS).forEach(function(k){ blankSlots[k] = {note:'',signed_by_name:'',can_sign:false,pool:[],item_nos:[]}; });
        renderSlots(blankSlots);
        openMask('editMask');
        return;
    }
    $.getJSON(API, {action:'get', id:id}, function(res){
        if (!res.success){ alert(res.message||'載入失敗'); return; }
        CUR_ID = id; CUR_STATUS = res.doc.status || 'draft'; CUR_SLOTS = res.slots || {};
        $('#fCustomerName').val(res.doc.customer_name||'');
        $('#fPartNo').val(res.doc.part_no||''); $('#fPartDId').val(res.doc.part_d_id||0);
        $('#fProductName').val(res.doc.product_name||''); $('#fEstQty').val(res.doc.est_qty||'');
        $('#fFillDate').val(res.doc.fill_date||''); $('#fSampleTime').val(res.doc.sample_time||'');
        $('#fDocNo').text(res.doc.doc_no);
        $('#fCreatedInfo').text((res.doc.created_by_name||'')+' '+fmtDate((res.doc.created_at||'').substring(0,10)));
        renderChecklist(res.answers || {});
        renderSlots(CUR_SLOTS);
        renderDecisionGrp(res.doc.decision || '', CUR_SLOTS['prod_decision']);
        applyStatusUI();
        openMask('editMask');
    });
}
$('#btnAdd').on('click', function(){ openEdit(0); });

EGPartPicker.attach(document.getElementById('fPartNo'), {
    apiUrl: PART_API,
    onSelect: function(row){
        $('#fPartDId').val(row.d_id);
        if (row.customer_name || row.customer_id) $('#fCustomerName').val(row.customer_name||row.customer_id);
    }
});
$('#fPartNo').on('input', function(){ $('#fPartDId').val('0'); });

function saveHeader(){
    var payload = {
        action: 'save', id: CUR_ID,
        customer_name: $('#fCustomerName').val(),
        part_d_id: $('#fPartDId').val() || 0,
        part_no_text: (($('#fPartDId').val()|0) ? '' : $('#fPartNo').val()),
        product_name: $('#fProductName').val(),
        est_qty: $('#fEstQty').val(),
        fill_date: $('#fFillDate').val(),
        sample_time: $('#fSampleTime').val(),
        answers: JSON.stringify(collectAnswers()),
    };
    $.post(API, payload, function(res){
        if (!res.success){ alert(res.message||'儲存失敗'); return; }
        closeMask('editMask'); loadList();
    }, 'json');
}

function delDoc(id){
    if (!confirm('確定刪除此筆產品開發評估表？')) return;
    $.post(API, {action:'delete_header', id:id}, function(res){
        if (!res.success){ alert(res.message||'刪除失敗'); return; }
        loadList();
    }, 'json');
}

/* ---------- 送出 ---------- */
function submitDoc(){
    if (!CUR_ID){ alert('請先儲存後再送出'); return; }
    if (!confirm('確定送出嗎？送出後表頭與確認項目將鎖定，改由各部門於簽核關卡自行填寫負責的項目並簽核。')) return;
    $.post(API, {action:'submit', id:CUR_ID}, function(res){
        if (!res.success){ alert(res.message||'送出失敗'); openEdit(CUR_ID); return; } // 點開即刷新鐵則
        openEdit(CUR_ID); loadList();
    }, 'json');
}

/* ---------- 簽核 ---------- */
window.signSlot = function(slotKey){
    if (!CUR_ID){ alert('請先儲存後再簽核'); return; }
    var note = $('textarea[data-slot-note="'+slotKey+'"]').val();
    var answers = {};
    var itemNos = (CUR_SLOTS[slotKey] && CUR_SLOTS[slotKey].item_nos) || [];
    var all = collectAnswers();
    itemNos.forEach(function(no){ if (all[no]) answers[no] = all[no]; });
    $.post(API, {action:'sign', doc_id:CUR_ID, slot_key:slotKey, note:note, answers:JSON.stringify(answers)}, function(res){
        if (!res.success){ alert(res.message||'簽核失敗'); openEdit(CUR_ID); return; } // 點開即刷新鐵則：失敗也要重新載入看最新狀態
        openEdit(CUR_ID); loadList();
    }, 'json');
};

/* ---------- 系統管理員：全部自動簽核(補舊資料用) ---------- */
$('#btnAdminAutoSignAll').on('click', function(){
    if (!CUR_ID){ alert('請先儲存後再使用'); return; }
    var bizDate = $('#adminAutoSignDate').val();
    if (!bizDate){ alert('請先選擇簽核業務日期'); return; }
    if (!confirm('確定要把此筆尚未簽核的欄位，全部以「'+bizDate+'」自動簽核完成嗎？此功能僅供補歷史紙本資料使用。')) return;
    $.post(API, {action:'admin_auto_sign_all', doc_id:CUR_ID, biz_date:bizDate}, function(res){
        if (!res.success){ alert(res.message||'自動簽核失敗'); return; }
        openEdit(CUR_ID); loadList();
    }, 'json');
});

/* ---------- 補登簽核 ---------- */
var backfillPool = {};
$('#btnBackfillOpen').on('click', function(){
    if (!CUR_ID){ alert('請先儲存後再補登'); return; }
    $.getJSON(API, {action:'get', id:CUR_ID}, function(res){
        if (!res.success) return;
        var html = '';
        Object.keys(SLOTS).forEach(function(k){
            var s = res.slots[k];
            if (s.signed_by_name) return; // 已簽的不需要補登
            var poolOpts = '<option value="">— 指定原簽核人 —</option>';
            (s.pool||[]).forEach(function(p){ poolOpts += '<option value="'+p.id+'">'+esc(p.user_cname)+'</option>'; });
            html += '<div style="border:1px solid #EADFC8;border-radius:6px;padding:8px;margin-bottom:8px;">'
                + '<b>'+esc(SLOTS[k][0])+'</b>'
                + '<select class="bf-signer" data-slot="'+k+'" style="margin-top:4px;">'+poolOpts+'</select>'
                + '<input type="text" class="bf-note" data-slot="'+k+'" placeholder="意見(可留白)" style="margin-top:4px;">'
                + '<input type="date" class="bf-date" data-slot="'+k+'" style="margin-top:4px;">'
                + '</div>';
        });
        if (!html) html = '<div style="color:#8a6d45;padding:10px;">全部欄位都已簽核，無需補登。</div>';
        $('#backfillList').html(html);
        $('#backfillPassword').val('');
        openMask('backfillMask');
    });
});
function submitBackfill(){
    var assignments = [];
    $('#backfillList .bf-signer').each(function(){
        var slot = $(this).data('slot'), signerId = $(this).val();
        if (!signerId) return;
        assignments.push({
            slot_key: slot, signer_user_id: signerId,
            note: $('.bf-note[data-slot="'+slot+'"]').val(),
            signed_at: $('.bf-date[data-slot="'+slot+'"]').val() || '',
        });
    });
    if (!assignments.length){ alert('請至少指定一格的原簽核人'); return; }
    var password = $('#backfillPassword').val();
    if (!password){ alert('請輸入操作確認密碼'); return; }
    $.post(API, {action:'backfill_sign_all', doc_id:CUR_ID, password:password, assignments:JSON.stringify(assignments)}, function(res){
        if (!res.success){ alert(res.message||'補登失敗'); return; }
        closeMask('backfillMask');
        openEdit(CUR_ID);
    }, 'json');
}

/* ---------- 列印（ai-rules/16） ---------- */
function printDoc(id){
    $.getJSON(API, {action:'print_get', id:id}, function(res){
        if (!res.success){ alert(res.message||'載入失敗'); return; }
        var d = res.doc, answers = res.answers || {}, signoffs = res.signoffs || {};
        window.__ownCompany = res.company_name || '';
        var chkRows = '';
        Object.keys(TEMPLATE).forEach(function(no){
            var t = TEMPLATE[no];
            var r = answers[no];
            var mark = r === 'yes' ? '是' : (r === 'no' ? '否' : (r === 'na' ? 'N/A' : ''));
            chkRows += '<tr><td>'+esc(t[0])+'</td><td>'+no+'</td><td class="tl">'+esc(t[1])+'</td><td>'+esc(t[2])+'</td><td>'+mark+'</td></tr>';
        });
        var slotCell = function(k){
            var s = signoffs[k];
            if (!s || !s.signed_by_name) return '<div style="min-height:50px;"></div>';
            return '<div style="font-size:11px;color:#555;margin-bottom:2px;">'+esc(s.note||'')+'</div>'
                + stampHtml(s.signed_by_name, fmtDate((s.signed_at||'').substring(0,10)), s.is_deputy);
        };
        var apqpRows = '';
        ['tech','sales','mgmt','prod','qa','material'].forEach(function(k){
            apqpRows += '<tr><td class="dept">'+esc(SLOTS[k][0])+'</td><td class="tl">'+slotCell(k)+'</td></tr>';
        });
        var body = '<div class="p-comp">'+esc(res.company_name)+'</div>'
            + '<div class="p-title">'+esc(res.as_doc_name)+'</div>'
            + '<table class="p-hd"><tr><td>客戶名稱</td><td>'+esc(d.customer_name||'')+'</td><td>預估需求量</td><td>'+esc(d.est_qty||'')+' PCS/月</td><td>填表日期</td><td>'+fmtDate(d.fill_date)+'</td></tr>'
            + '<tr><td>產品名稱</td><td>'+esc(d.product_name||'')+'</td><td>產品件號</td><td>'+esc(d.part_no||'')+'</td><td>送樣時間</td><td>'+esc(d.sample_time||'')+'</td></tr></table>'
            + '<table class="p-tb"><thead><tr><th style="width:60px;">區分</th><th style="width:30px;">項次</th><th>評估項目</th><th style="width:60px;">評估單位</th><th style="width:50px;">結果</th></tr></thead><tbody>'+chkRows+'</tbody></table>'
            + '<div class="p-sec">APQP 小組簽認</div>'
            + '<table class="p-tb"><tbody>'+apqpRows+'</tbody></table>'
            + '<div class="p-sec">生產課決行：'+esc(DECISIONS[d.decision]||'（未決行）')+'</div>'
            + '<table class="p-tb"><tr><td class="dept">生產課</td><td class="tl">'+slotCell('prod_decision')+'</td></tr></table>'
            + '<div class="p-sec">總經理決行</div>'
            + '<table class="p-tb"><tr><td class="dept">總經理</td><td class="tl">'+slotCell('gm')+'</td></tr></table>';
        var css = 'body{font-family:"Microsoft JhengHei",sans-serif;margin:0;padding:0 6mm;color:#222;-webkit-print-color-adjust:exact;print-color-adjust:exact;}'
            + '.p-comp{font-size:22px;font-weight:bold;text-align:center;margin-bottom:1px;}'
            + '.p-title{font-size:17px;font-weight:bold;text-align:center;letter-spacing:4px;margin-bottom:10px;}'
            + '.p-sec{font-size:13px;font-weight:bold;color:#8A5A2B;border-left:4px solid #F0A24B;padding-left:6px;margin:8px 0 4px;break-after:avoid;}'
            + 'table.p-hd{width:100%;border-collapse:collapse;font-size:11px;margin-bottom:6px;}'
            + 'table.p-hd td{border:1px solid #666;padding:3px 5px;} table.p-hd td:nth-child(odd){background:#f3ead6;font-weight:bold;white-space:nowrap;}'
            + 'table.p-tb{width:100%;table-layout:fixed;border-collapse:collapse;font-size:10.5px;margin-bottom:4px;}'
            + 'table.p-tb thead{display:table-header-group;}'
            + 'table.p-tb th,table.p-tb td{border:1px solid #666;padding:2px 4px;text-align:center;overflow-wrap:anywhere;}'
            + 'table.p-tb thead th{background:#f3ead6;} table.p-tb td.tl{text-align:left;} table.p-tb td.dept{font-weight:bold;background:#f3ead6;width:70px;}'
            + 'table.p-tb tr{break-inside:avoid;}'
            + '.stamp-wrap{display:inline-block;text-align:center;margin:2px 10px 2px 0;}'
            + '@page{margin:12mm 10mm 18mm;'
            + (res.as_doc_no ? " @bottom-right{ content:'"+String(res.as_doc_no).replace(/['\\]/g,'')+"'; font-size:9pt; color:#333; vertical-align:top; padding-top:1mm; }" : '')
            + '}';
        var w = window.open('', '_blank');
        w.document.write('<html><head><meta charset="utf-8"><title>產品開發評估表</title><style>'+css+'</style></head><body>'+body
            +'<scr'+'ipt>window.onload=function(){'
            +'var onePageA4=(297-30)*96/25.4;'
            +'if(document.body.scrollHeight>onePageA4*0.92){'
            +'var st=document.createElement(\'style\');'
            +'st.textContent="@page{ @bottom-left{ content:\'第 \' counter(page) \' 頁／共 \' counter(pages) \' 頁\'; font-size:9pt; color:#333; vertical-align:top; padding-top:1mm; } }";'
            +'document.head.appendChild(st);}'
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
        docs: AS_DOCS, current: AS_DOC ? AS_DOC.id : 0, title: '產品開發評估表 AS 文件綁定',
        onSave: function(id){
            $.post(API, {action:'as_doc_save', doc_id:id}, function(res){
                if (!res.success){ alert(res.message||'儲存失敗'); return; }
                AS_DOC = res.as_doc; renderAsDocLabel();
            }, 'json');
        }
    });
}

$('#btnSuggest').on('click', function(){ window.location.href = 'td_dev_eval_suggest.php'; });
$('#btnPageHelp').on('click', function(){ openMask('helpUseMask'); });
$('#btnRoleHelp').on('click', function(){ openMask('roleHelpMask'); });
$('.te-mask').on('click', function(e){ if (e.target === this) this.style.display='none'; });

<?php if ($perms['canView']): ?>
loadTemplate(function(){ loadList(); });
loadAsDocCurrent();
<?php endif; ?>
</script>
</body>
</html>
