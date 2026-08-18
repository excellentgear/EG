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
$companyName = eg_company_full_name($db);
$defaultProductName = td_dev_eval_default_product_name_get($db);
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
        .te-back-top { position:fixed; right:22px; bottom:22px; width:38px; height:38px; border-radius:50%; background:#F0A24B;
            color:#fff; text-align:center; line-height:38px; box-shadow:0 2px 8px rgba(0,0,0,.25); cursor:pointer; display:none; z-index:900; }
        .te-back-top:hover { background:#d98a33; }
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
            <input type="text" id="kwInput" placeholder="表單編號／料號／產品名稱／客戶名稱／客戶編號" style="width:220px;">
            <button class="btn-warm" id="btnAdd" style="<?= $perms['canEdit']?'':'display:none;' ?>"><i class="fa fa-plus"></i> 新增</button>
            <button id="btnSuggest" style="<?= $perms['canAdmin']?'':'display:none;' ?>"><i class="fa fa-lightbulb-o"></i> 建議建立料號清單<?= $suggestPending>0 ? ' <span class="te-badge-deputy" style="background:#DD5138;">'.(int)$suggestPending.'</span>' : '' ?></button>
            <button id="btnAsDoc" style="<?= $perms['canAdmin']?'':'display:none;' ?>"><i class="fa fa-link"></i> AS文件綁定</button>
            <button id="btnSlotOverride" style="<?= $perms['canAdmin']?'':'display:none;' ?>"><i class="fa fa-user-circle-o"></i> 部門簽核人設定</button>
            <button id="btnPrintAll" title="依目前搜尋結果，逐筆列印所有表單"><i class="fa fa-print"></i> 批次列印</button>
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
<div class="te-back-top" id="btnBackTop" title="回到頂端"><i class="fa fa-arrow-up"></i></div>

<!-- 新增/編輯 -->
<div class="te-mask" id="editMask"><div class="te-modal xwide">
    <div class="m-head"><span id="editTitle">產品開發評估表</span> <span class="te-status" id="editStatusBadge" style="display:none;"></span>
        <span id="editHdrInfo" style="margin-left:auto;margin-right:10px;font-weight:normal;font-size:13px;"></span>
        <span class="m-close" onclick="closeMask('editMask')">✕</span></div>
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
                <label>料號</label>
                <input type="text" id="fPartNo" placeholder="輸入部分料號或圖號搜尋；查無時可直接手動輸入新產品件號" autocomplete="off">
                <input type="hidden" id="fPartDId" value="0">
            </div>
            <div>
                <label>產品名稱<i class="fa fa-cog" id="btnSetFixedName" title="設定產品名稱預設值(全部產品通用)" style="margin-left:4px;color:#b5762a;cursor:pointer;<?= $perms['canAdmin']?'':'display:none;' ?>"></i></label>
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
        <div id="chkDraftTip" class="te-blocked-hint" style="display:none;margin-bottom:4px;"><i class="fa fa-hourglass-half"></i> 尚未送出，送出後才能由各部門在自己的簽核關卡填寫負責的項次。</div>
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

        <div class="te-sec-title">總經理決行（最終決策，可沿用或覆蓋生產課決行結果）</div>
        <div id="gmDecisionInfo" style="font-size:13px;color:#8A5A2B;margin-bottom:4px;"></div>
        <div class="te-decision-grp" id="gmDecisionGrp"></div>
        <table class="te-slot"><tbody id="gmBody"></tbody></table>

        <div style="margin-top:10px;<?= $perms['isAdmin']?'':'display:none;' ?>">
            <button type="button" class="te-row-btn" id="btnFullEditMode"><i class="fa fa-unlock-alt"></i> 開啟全表填寫模式（操作確認密碼）</button>
            <span style="font-size:11px;color:#8a6d45;margin-left:6px;">僅系統管理員可用：輸入操作確認密碼後可自行填寫上方全部32項確認結果，不受部門/簽核順序限制；填完後仍需用下方「補登簽核」或「全部自動簽核」正式完成簽核。</span>
        </div>
        <div style="margin-top:10px;<?= $canBackfill?'':'display:none;' ?>">
            <button type="button" class="te-row-btn" id="btnBackfillOpen"><i class="fa fa-user-secret"></i> 補登簽核（操作確認密碼）</button>
        </div>

        <div class="te-admin-panel" id="adminQuickPanel" style="<?= $perms['isAdmin']?'':'display:none;' ?>">
            <h5><i class="fa fa-user-secret"></i> 系統管理員快速設定（僅補歷史紙本資料用，會跳過送出/簽核流程）</h5>
            <div style="font-size:12px;color:#8a6d45;margin-bottom:6px;">確認項目結果請先按上方「開啟全表填寫模式」輸入密碼後編輯；決行結果請在下方選擇（不是上方表格內的選項——上方選項一樣要照正常流程走完六部門/生產課才會開放），選好後按下方按鈕一次把尚未簽核的欄位全部自動簽核。</div>
            <label style="display:inline-block;margin:0 8px 0 0;">決行結果</label>
            <select id="adminDecisionSelect" style="width:140px;display:inline-block;">
                <option value="">（未選擇）</option>
                <?php foreach (TD_DEV_EVAL_DECISIONS as $dk => $dv): ?>
                    <option value="<?= htmlspecialchars($dk) ?>"><?= htmlspecialchars($dv) ?></option>
                <?php endforeach; ?>
            </select>
            <label style="display:inline-block;margin:0 8px 0 0;">簽核業務日期</label>
            <input type="date" id="adminAutoSignDate" style="width:160px;display:inline-block;">
            <label style="display:inline-block;font-weight:normal;margin-left:10px;">
                <input type="checkbox" id="adminApplyDefaults"> 未填項次套用預設值
            </label>
            <button type="button" class="te-row-btn" id="btnAdminAutoSignAll" style="margin-left:6px;"><i class="fa fa-magic"></i> 全部自動簽核</button>
            <button type="button" class="te-row-btn" id="btnAdminDefaultsSetting" style="margin-left:6px;"><i class="fa fa-sliders"></i> 設定確認項目預設值</button>
        </div>
    </div>
    <div class="m-foot">
        <button class="b-cancel" onclick="closeMask('editMask')">取消</button>
        <button class="b-ok" id="btnSave" onclick="saveHeader()">儲存</button>
        <button class="b-ok" id="btnSubmitDoc" style="background:#DD5138;border-color:#c9432a;" onclick="submitDoc()"><i class="fa fa-paper-plane"></i> 送出</button>
    </div>
</div></div>

<!-- 開啟全表填寫模式（僅系統管理員，操作確認密碼） -->
<div class="te-mask" id="fullEditMask" style="z-index:1200;"><div class="te-modal">
    <div class="m-head"><span>開啟全表填寫模式</span><span class="m-close" onclick="closeMask('fullEditMask')">✕</span></div>
    <div class="m-body">
        <div class="tip">僅系統管理員可用，補歷史紙本資料時使用。輸入操作確認密碼後，可自行填寫全部32項確認結果，不受部門/簽核順序限制；填完後仍需用「補登簽核」逐格指定原簽核人、或系統管理員快速設定的「全部自動簽核」，才能正式完成簽核。</div>
        <label style="margin-top:10px;">操作確認密碼</label>
        <input type="password" id="fullEditPassword" autocomplete="off">
    </div>
    <div class="m-foot">
        <button class="b-cancel" onclick="closeMask('fullEditMask')">取消</button>
        <button class="b-ok" onclick="submitFullEditUnlock()">確認開啟</button>
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

<!-- 確認項目及結果預設值設定（系統管理員，供全部自動簽核選擇是否套用） -->
<div class="te-mask" id="defaultsMask" style="z-index:1200;"><div class="te-modal xwide">
    <div class="m-head"><span>確認項目及結果 預設值設定</span><span class="m-close" onclick="closeMask('defaultsMask')">✕</span></div>
    <div class="m-body">
        <div class="tip">僅供「全部自動簽核」補歷史紙本資料時，勾選「未填項次套用預設值」才會使用；只補未填的項次，不會覆蓋已有的答案。未設定的項次留白＝不套用。</div>
        <table class="te-chk">
            <thead><tr><th style="width:60px;">區分</th><th style="width:36px;">項次</th><th>評估項目</th>
                <th style="width:70px;">評估單位</th><th style="width:150px;">預設結果</th></tr></thead>
            <tbody id="defaultsChkBody"></tbody>
        </table>
    </div>
    <div class="m-foot">
        <button class="b-cancel" onclick="closeMask('defaultsMask')">取消</button>
        <button class="b-ok" onclick="saveDefaultsSetting()">儲存</button>
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

<!-- 部門簽核人設定（2026-08-13使用者明確要求：部門組織圖主管實務上不回覆此表單時，可指定專屬簽核人取代自動解析） -->
<div class="te-mask" id="slotOverrideMask"><div class="te-modal xwide">
    <div class="m-head"><span>部門簽核人設定</span><span class="m-close" onclick="closeMask('slotOverrideMask')">✕</span></div>
    <div class="m-body">
        <div class="tip">每個部門欄位三選一：<b>自動</b>＝預設由「組織角色綁定設定」該部門的主管自動解析（多位主管皆可簽）；<b>指定人員</b>＝勾選一至多位人員皆可簽（例：技術課兩位工程師都要能簽，不限一人）；<b>主管以外皆可簽</b>＝該部門除了目前解析出的主管之外，其他成員都可以簽（例：技術課無專職課長、由總經理兼任，但總經理只回覆「技術課審核」不回覆本表單，用這個選項讓其他工程師直接簽）。只影響本模組，不會動到全站的組織角色綁定，其他表單仍照組織圖正常運作；指定人員全部離職、或排除主管後部門內沒有其他人時，會自動退回自動解析。</div>
        <div id="slotOverrideList"></div>
    </div>
    <div class="m-foot">
        <button class="b-cancel" onclick="closeMask('slotOverrideMask')">取消</button>
        <button class="b-ok" onclick="saveSlotOverrides()">儲存</button>
    </div>
</div></div>

<!-- 角色權限說明 -->
<div class="te-mask" id="roleHelpMask"><div class="te-modal">
    <div class="m-head"><span>角色權限說明</span><span class="m-close" onclick="closeMask('roleHelpMask')">✕</span></div>
    <div class="m-body" style="font-size:13px;color:#5b3a1e;line-height:1.8;">
        <b>評估表檢閱</b>：檢視清單、開啟查看、列印。<br>
        <b>評估表登錄</b>：檢閱＋新增/編輯（草稿階段）、送出、依部門身分填寫負責項目並簽核。<br>
        <b>評估表管理員</b>：登錄＋刪除、AS 文件編號綁定、取消他人簽核、部門簽核人設定。<br>
        <b>管理者</b>：系統管理者固定擁有全部權限。<br>
        <hr style="border-color:#EADFC8;">
        APQP 小組簽認各部門欄位預設由「該部門任一主管」簽核，重用<a href="../admin/org_role_setting.php" target="_blank">組織角色綁定設定</a>頁全站既有的部門綁定（技術／業務／管理／生產／品保部門本來就設定過，資材部門為本表新增）；若該部門組織圖主管實務上不回覆本表單（例如某課無專職主管、由總經理兼任但只回覆別的表單），評估表管理員可在工具列「部門簽核人設定」指定專屬簽核人取代，只影響本模組。總經理欄沿用全站「最高核准人員」設定。<br>
        角色指派請洽管理者於「使用者權限設定」（<a href="../user/user_permissions.php" target="_blank">開啟</a>）→「產品開發評估表」區塊。未被指派角色者無法進入本頁。
    </div>
</div></div>

<div class="te-mask" id="helpUseMask"><div class="te-modal xwide">
    <div class="m-head"><span><i class="fa fa-question-circle"></i> 產品開發評估表 使用說明</span><span class="m-close" onclick="closeMask('helpUseMask')">✕</span></div>
    <div class="m-body help-doc">
        <h4>功能說明</h4>
        <p>APQP 產品開發階段的評估表（2-TD-02-01），固定 32 項確認項目（人/機/料/法/發展/產品安全/仿冒零件的防制/其他），逐項是／否／N-A，由技術/業務/管理/生產/品保/資材課六個單位分別填寫自己負責的項目結果並簽認意見，最後由生產課決行（可行自製／可行委外／再評估／中止）並送總經理決行。</p>
        <h4>操作步驟</h4>
        <ul>
            <li>按「新增」→ 填客戶名稱、產品件號（打部分字元搜尋，選定後自動帶出客戶名稱；查無此料號時可直接手動輸入新產品件號，不強制要求已存在的料號）、產品名稱、預估需求量、填表日期、送樣時間。此階段（草稿）僅能編輯表頭；32 項確認結果任何人都還不能點選填寫（含評估表管理員），要等送出後由各部門在自己的簽核關卡才能填自己負責的項次——確認項目及結果的填寫/簽核權限只看「本人是否在該部門目前的簽核池內」，跟評估表登錄／管理員這種頁面操作角色無關。</li>
            <li>填好後按「送出」：表頭與 32 項確認結果隨即鎖定（僅系統管理員可再整批修改），系統會通知六部門（技術/業務/管理/生產/品保/資材課）的合格簽核人開始填寫。</li>
            <li>APQP 小組簽認：輪到自己部門簽核時，「確認項目及結果」表格中屬於本部門的項次列會醒目標示且可編輯，填完後在「APQP 小組簽認」該部門列填意見（非必填）按「我要簽核」即完成並蓋章；六部門不限順序、任一位主管都可以簽，不限定特定一人。</li>
            <li>六部門<b>全部</b>簽認完成後，「生產課決行」才會開放：勾選「可行自製／可行委外／再評估／中止」並簽核（點選當下不會存檔，要按「我要簽核」才正式生效）；決行完成後「總經理決行」才會開放，總經理同樣要從這四個選項中選一個並簽核——<b>總經理的選擇才是最終決策</b>，可以直接沿用生產課的結果，也可以改選後覆蓋；總經理簽完系統自動將本表單標記為「已結案」。<b>生產課與總經理各自選的決行結果會分別留存</b>，列印時兩格各印各的（總經理覆蓋後，生產課那格仍印生產課當初選的結果）。</li>
        </ul>
        <h4>建議建立料號清單（管理員）</h4>
        <ul>
            <li>工具列「建議建立料號清單」按鈕（僅管理員可見，紅色數字為近一年候選筆數提醒）：依管理員設定的客戶名單，掃描區間內曾有訂單/報工/BOM/出貨記錄、但尚未建立評估表的料號，可一次批次建立多筆草稿，避免漏建。詳見該頁「使用說明」。</li>
        </ul>
        <h4>開啟全表填寫模式／補登簽核／系統管理員快速設定（歷史紙本資料建檔用）</h4>
        <ul>
            <li><b>開啟全表填寫模式</b>（僅系統管理員，非「評估表管理員」角色即可，需輸入操作確認密碼）：開啟後可自行填寫上方全部 32 項確認結果，不受部門/簽核順序限制；每次重新開啟此筆的編輯視窗都要重新輸入密碼，不會記住。填完後仍需用下方「補登簽核」或「全部自動簽核」才能正式完成簽核，本身不會直接完成簽核。</li>
            <li>具「操作確認密碼」資格者，可用「補登簽核」一次把已送出但尚未簽核的欄位補齊——<b>仍須逐格指定當初實際簽核的人</b>，系統會清楚記錄「此筆為補登，由誰執行補登」，跟本人即時線上簽核的紀錄分開標示，不會誤植成操作者本人簽的。</li>
            <li>系統管理員另有「全部自動簽核」：於快速設定面板選擇決行結果、指定一個業務日期，一次把尚未簽核的欄位全部自動簽核完成（簽核人取該欄目前解析池第一位），連同尚未送出的表單一併補上送出紀錄；此功能不受送出/簽核狀態限制，僅供補歷史紙本資料使用。</li>
        </ul>
        <h4>其他行為／常見疑問</h4>
        <ul>
            <li>產品件號可點擊開啟圖面查閱（比照報價單頁做法）。</li>
            <li>簽章使用全站通用圓形姓名章（若本人有上傳掃描實體章會優先用掃描章）；由代理人代簽時右下角加「代」字。</li>
            <li>列印比照全站標準（ai-rules/16）：大標題為本公司名稱、頁尾右下角印本頁綁定的 AS 文件編號。</li>
        </ul>
        <h4>設定入口</h4>
        <p>AS 文件編號綁定：工具列「AS文件綁定」按鈕（僅管理員可見）。<b>部門簽核人</b>：<a href="../admin/org_role_setting.php" target="_blank">組織角色綁定設定</a>頁「一、部門綁定」，重用全站既有的技術／業務／管理／生產／品保部門綁定，資材部門若尚未設定請在該頁一併綁定；若該部門的組織圖主管實務上不回覆<b>本表單</b>（例如某課由總經理兼任但只回覆別的表單），改到工具列「部門簽核人設定」指定專屬簽核人取代，只影響本模組、不動全站組織角色綁定。<b>總經理</b>沿用「組織角色綁定設定」頁「最高核准人員」設定。<b>角色指派</b>：<a href="../user/user_permissions.php" target="_blank">使用者權限設定</a>頁→「產品開發評估表」區塊。</p>
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
window.__ownCompany = <?= json_encode($companyName, JSON_UNESCAPED_UNICODE) ?>; // eg_stamp.js 簽章圖章要靠這個顯示公司名稱，跳窗內即時簽核也要有，不是只有列印時才設
var DEFAULT_PRODUCT_NAME = <?= json_encode($defaultProductName, JSON_UNESCAPED_UNICODE) ?>; // 產品名稱預設值：全部產品通用單一值，不是特定料號（2026-08-13使用者更正）
var CUR_ID = 0, CUR_STATUS = 'draft', TEMPLATE = {}, SLOTS = {}, DECISIONS = {}, AS_DOCS = [], AS_DOC = null, CUR_SLOTS = {};
var FULL_EDIT_MODE = false; // 系統管理員輸入操作確認密碼後才開啟，逐筆重新開啟編輯視窗時要重新輸入，不記憶
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
var CUR_LIST_ROWS = [];
function loadList(){
    $.getJSON(API, {action:'list', kw:$('#kwInput').val()||''}, function(res){
        if (!res.success){ $('#teBody').html('<tr><td colspan="9" style="padding:20px;color:#DD5138;">'+esc(res.message||'載入失敗')+'</td></tr>'); return; }
        CUR_LIST_ROWS = res.rows || [];
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

/* ---------- 批次列印（依目前搜尋結果，逐筆各自開視窗列印；結果較多時自動分批排隊，ai-rules/16） ---------- */
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
/**
 * 確認項目及結果的填寫權限，一律只看「本人是否在該項次所屬部門目前的簽核池內、且輪到這關」，
 * 跟「評估表登錄/評估表管理員」這種模組角色完全無關——評估表管理員能刪表單、能綁AS文件，
 * 不代表可以代填技術課負責的項次（使用者明確要求：各使用者只能點選自己能簽核與回覆的範圍）。
 * 草稿階段本來就還沒送出、六部門都還沒輪到自己的關卡，所以草稿階段任何人都不能點選確認項目，
 * 一律要送出後才由各部門在自己的簽核關卡填寫；原本草稿階段用CAN_EDIT整批放行是錯的，已移除。
 * 系統管理員需先按「開啟全表填寫模式」輸入操作確認密碼，才能不受此限制編輯任一項次（比照補登簽核）。
 */
function itemEditable(no){
    if (FULL_EDIT_MODE) return true;
    if (CUR_STATUS !== 'submitted') return false;
    var k = itemOwnerSlot(no), s = k ? CUR_SLOTS[k] : null;
    return !!(s && s.can_sign);
}
var CUR_ANSWERS = {};
function renderChecklist(answers){
    answers = answers || {};
    CUR_ANSWERS = answers;
    var html = '', lastCat = null;
    Object.keys(TEMPLATE).forEach(function(no){
        var t = TEMPLATE[no], cat = t[0], text = t[1], unit = t[2];
        var editable = itemEditable(no);
        var mine = CUR_STATUS === 'submitted' && !FULL_EDIT_MODE && editable;
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
/* 填項次當下即時解鎖對應部門的意見/簽核區，不必等重新整理（推導欄位鐵則：來源一改就重算）；
   重繪簽核表格前先保留使用者正在打的意見草稿，避免因為改動其他項次而被清空 */
$(document).on('change', '#chkBody input[type=radio]', function(){
    var no = $(this).closest('tr').data('item-no');
    CUR_ANSWERS[no] = $(this).val();
    if (!CUR_ID) return;
    var draftNotes = {};
    $('textarea[data-slot-note]').each(function(){ draftNotes[$(this).data('slot-note')] = $(this).val(); });
    renderSlots(CUR_SLOTS);
    Object.keys(draftNotes).forEach(function(k){ $('textarea[data-slot-note="'+k+'"]').val(draftNotes[k]); });
});

/** 該部門負責的項次是否已全部填有結果（使用者明確要求：填完項次才能填意見跟簽核） */
function slotItemsComplete(s){
    if (!s.item_nos || !s.item_nos.length) return true;
    return s.item_nos.every(function(no){ return !!CUR_ANSWERS[no]; });
}
function slotRowHtml(slotKey, s){
    var label = SLOTS[slotKey] ? SLOTS[slotKey][0] : slotKey;
    var itemsDone = slotItemsComplete(s);
    var signCell, noteCell;
    if (s.signed_by_name) {
        signCell = '<span class="te-sign-done">' + stampHtml(s.signed_by_name, fmtDate((s.signed_at||'').substring(0,10)), s.is_deputy) + '</span>'
            + (s.is_deputy ? '<span class="te-badge-deputy">代</span>' : '')
            + (s.is_backfill ? '<span class="te-badge-backfill" title="'+esc(s.backfill_by_name||'')+' 補登">補登</span>' : '');
    } else if (s.can_sign && !itemsDone) {
        signCell = '<span class="te-blocked-hint"><i class="fa fa-exclamation-triangle"></i> 請先在上方表格填完本部門負責的項次</span>';
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
    noteCell = (s.can_sign && !itemsDone && !FULL_EDIT_MODE)
        ? '<textarea class="note-in" placeholder="意見(非必填)" readonly></textarea>'
        : '<textarea class="note-in" data-slot-note="'+slotKey+'" placeholder="意見(非必填)"'+((s.can_sign||FULL_EDIT_MODE)?'':' readonly')+'>'+esc(s.note||'')+'</textarea>';
    return '<tr data-slot="'+slotKey+'"><td class="dept">'+esc(label)+'</td>'
        + '<td>'+itemHint+noteCell+'</td>'
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
/**
 * 決行選項（生產課／總經理共用同一組四選項）：不論一般使用者或系統管理員，點選都只是畫面上暫存，
 * 不會立即存檔——原本管理員點下去也會立刻存檔，被使用者實測抓到「沒送出，清單卻已經顯示決行結果」。
 * 真正要存檔一律得經過「我要簽核」（一般流程）或「系統管理員快速設定」的全部自動簽核（補資料流程）。
 * 生產課決行需六部門全簽完才開放；總經理決行需生產課決行完成才開放——這條規則對管理員自行手動操作
 * 也一樣適用（使用者明確要求），管理員唯一的例外通道是「全部自動簽核」按鈕本身。
 */
function renderDecisionRadios($grp, name, cur, editable){
    var html = '';
    Object.keys(DECISIONS).forEach(function(k){
        html += '<label><input type="radio" name="'+name+'" value="'+k+'"'+(cur===k?' checked':'')+(editable?'':' disabled')+'> '+esc(DECISIONS[k])+'</label>';
    });
    $grp.html(html);
}
/* cur = 表頭最終決行結果；生產課那組要顯示「生產課自己簽的那個結果」（總經理覆蓋後表頭已經是總經理的值，
   拿表頭去填生產課那組會把總經理的選擇誤植成生產課的），故優先讀該欄簽核當下留存的 decision_value */
function renderDecisionGrp(cur, prodSlot){
    var prodCur = (prodSlot && prodSlot.decision_value) ? prodSlot.decision_value : cur;
    var gmSlot = CUR_SLOTS['gm'];
    var gmCur = (gmSlot && gmSlot.decision_value) ? gmSlot.decision_value : cur; // 總經理未簽時預設沿用生產課的值，可自行覆蓋
    renderDecisionRadios($('#decisionGrp'), 'decision', prodCur, FULL_EDIT_MODE || !!(prodSlot && prodSlot.can_sign));
    renderDecisionRadios($('#gmDecisionGrp'), 'gm_decision', gmCur, FULL_EDIT_MODE || !!(gmSlot && gmSlot.can_sign));
    // 總經理決行時要看得到生產課決行了什麼，不用往上滑動翻找（使用者明確要求）
    $('#gmDecisionInfo').html(prodCur ? ('生產課決行結果：<b>'+esc(DECISIONS[prodCur]||prodCur)+'</b>') : '<span style="color:#b0a390;">（生產課尚未決行）</span>');
    $('#adminDecisionSelect').val(cur || '');
}
/* 全表填寫模式下改上方決行選項，要同步進管理員快速設定面板自己的下拉選單，「全部自動簽核」才真的會用這個值——
   兩邊各自獨立會讓使用者在表格內選了卻沒作用（跟本次修正的其他bug同一種問題），乾脆同步掉 */
$(document).on('change', 'input[name="decision"], input[name="gm_decision"]', function(){
    if (FULL_EDIT_MODE) $('#adminDecisionSelect').val($(this).val());
});

/** 依目前狀態顯示：草稿=可編表頭+存檔+送出；已送出/已結案=鎖表頭、隱藏存檔與送出(管理員仍可存檔) */
function applyStatusUI(){
    var badge = $('#editStatusBadge');
    if (CUR_ID) {
        badge.show().attr('class', 'te-status te-status-'+CUR_STATUS).text(STATUS_LABELS[CUR_STATUS]||CUR_STATUS);
    } else badge.hide();
    var locked = CUR_ID && CUR_STATUS !== 'draft' && !IS_SUPER_ADMIN;
    $('#hdrLockedTip').toggle(!!locked);
    $('#chkDraftTip').toggle(CUR_STATUS === 'draft' && !FULL_EDIT_MODE);
    $('#fCustomerName,#fPartNo,#fProductName,#fEstQty,#fFillDate,#fSampleTime').prop('disabled', !!locked);
    $('#btnSave').toggle(!locked);
    $('#btnSubmitDoc').toggle(!!CUR_ID && CUR_STATUS === 'draft' && CAN_EDIT);
    $('#adminAutoSignDate').val($('#adminAutoSignDate').val() || (new Date()).toISOString().substring(0,10));
    $('#btnAdminAutoSignAll').prop('disabled', !CUR_ID);
}
function resetEditForm(){
    CUR_ID = 0; CUR_STATUS = 'draft'; CUR_SLOTS = {}; FULL_EDIT_MODE = false;
    $('#fCustomerName').val(''); $('#fPartNo').val(''); $('#fPartDId').val('0');
    $('#fProductName').val(DEFAULT_PRODUCT_NAME || ''); $('#fEstQty').val(''); $('#fFillDate').val(''); $('#fSampleTime').val('');
    $('#fDocNo').text('存檔後自動產生'); $('#fCreatedInfo').text('—');
    updateEditHdrInfo();
    renderChecklist({});
    renderDecisionGrp('', null);
    applyStatusUI();
}
/** 跳窗標題右側顯示填表日期／料號，方便使用者一眼判斷目前開的是哪一筆（使用者明確要求） */
function updateEditHdrInfo(){
    var fillDate = $('#fFillDate').val(), partNo = $('#fPartNo').val();
    var parts = [];
    if (fillDate) parts.push('填表日期：'+fmtDate(fillDate));
    if (partNo) parts.push('料號：'+esc(partNo));
    $('#editHdrInfo').text(parts.join('　｜　'));
}
$('#fFillDate').on('change', updateEditHdrInfo);
$('#fPartNo').on('input', updateEditHdrInfo);
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
        updateEditHdrInfo();
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
        maybeEstimateQty();
        updateEditHdrInfo();
    }
});
$('#fPartNo').on('input', function(){ $('#fPartDId').val('0'); });

/* ---------- 預估需求量自動試算（2026-08-13使用者明確要求，只是帶入預設值，欄位仍可手動改） ---------- */
function maybeEstimateQty(){
    var partDId = parseInt($('#fPartDId').val(), 10) || 0;
    var fillDate = $('#fFillDate').val();
    if (!partDId || !fillDate || $('#fEstQty').prop('disabled')) return;
    $.getJSON(API, {action:'estimate_qty', part_d_id:partDId, fill_date:fillDate}, function(res){
        if (res.success && res.est_qty) $('#fEstQty').val(res.est_qty);
    });
}
$('#fFillDate').on('change', maybeEstimateQty);

/* ---------- 產品名稱預設值設定（全部產品通用單一值，不是特定料號；評估表管理員/系統管理員可設，2026-08-13使用者更正） ---------- */
$('#btnSetFixedName').on('click', function(){
    var name = window.prompt('設定「產品名稱」預設值\n（此為全部產品通用的單一預設值，不是針對特定料號；之後新建立表單時會自動帶入，仍可手動修改；留空＝取消預設值）', DEFAULT_PRODUCT_NAME || '');
    if (name === null) return; // 取消
    $.post(API, {action:'default_product_name_save', product_name:name.trim()}, function(r){
        if (!r.success){ alert(r.message||'儲存失敗'); return; }
        DEFAULT_PRODUCT_NAME = name.trim() || null;
        if (!CUR_ID && DEFAULT_PRODUCT_NAME) $('#fProductName').val(DEFAULT_PRODUCT_NAME);
        alert('已儲存。');
    }, 'json');
});

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
    var itemNos = (CUR_SLOTS[slotKey] && CUR_SLOTS[slotKey].item_nos) || [];
    var all = collectAnswers();
    var answers = {};
    itemNos.forEach(function(no){ if (all[no]) answers[no] = all[no]; });
    if (itemNos.length && itemNos.some(function(no){ return !answers[no]; })){
        alert('請先在上方「確認項目及結果」表格內填完本部門負責的項次，才能填意見與簽核。');
        return;
    }
    var note = $('textarea[data-slot-note="'+slotKey+'"]').val();
    var payload = {action:'sign', doc_id:CUR_ID, slot_key:slotKey, note:note, answers:JSON.stringify(answers)};
    if (slotKey === 'prod_decision' || slotKey === 'gm') {
        var radioName = slotKey === 'gm' ? 'gm_decision' : 'decision';
        var decision = $('input[name="'+radioName+'"]:checked').val();
        if (!decision){ alert('請先選擇決行結果（可行自製／可行委外／再評估／中止），再按我要簽核。'); return; }
        payload.decision = decision;
    }
    $.post(API, payload, function(res){
        if (!res.success){ alert(res.message||'簽核失敗'); openEdit(CUR_ID); return; } // 點開即刷新鐵則：失敗也要重新載入看最新狀態
        openEdit(CUR_ID); loadList();
    }, 'json');
};

/* ---------- 系統管理員：全部自動簽核(補舊資料用) ---------- */
$('#btnAdminAutoSignAll').on('click', function(){
    if (!CUR_ID){ alert('請先儲存後再使用'); return; }
    var bizDate = $('#adminAutoSignDate').val();
    if (!bizDate){ alert('請先選擇簽核業務日期'); return; }
    var decision = $('#adminDecisionSelect').val();
    if (!decision){ alert('請先在上方選擇決行結果，才能全部自動簽核。'); return; }
    var applyDefaults = $('#adminApplyDefaults').prop('checked');
    if (!confirm('確定要把此筆尚未簽核的欄位，全部以「'+bizDate+'」自動簽核完成嗎？決行結果將設為「'+esc(DECISIONS[decision]||decision)+'」。'+(applyDefaults?'（未填項次會套用預設值）':'')+'此功能僅供補歷史紙本資料使用。')) return;
    $.post(API, {action:'admin_auto_sign_all', doc_id:CUR_ID, biz_date:bizDate, decision:decision, apply_defaults:applyDefaults?1:0}, function(res){
        if (!res.success){ alert(res.message||'自動簽核失敗'); return; }
        openEdit(CUR_ID); loadList();
    }, 'json');
});

/* ---------- 系統管理員：確認項目及結果 預設值設定 ---------- */
$('#btnAdminDefaultsSetting').on('click', function(){
    $.getJSON(API, {action:'answer_defaults_get'}, function(res){
        if (!res.success) return;
        renderDefaultsChecklist(res.defaults || {});
        openMask('defaultsMask');
    });
});
function renderDefaultsChecklist(defaults){
    var html = '', lastCat = null;
    Object.keys(TEMPLATE).forEach(function(no){
        var t = TEMPLATE[no], cat = t[0], text = t[1], unit = t[2];
        var opts = [['','（無預設）']].concat(RESULT_OPTS);
        var radios = opts.map(function(o){
            return '<label><input type="radio" name="def_'+no+'" value="'+o[0]+'"'+(( defaults[no]||'')===o[0]?' checked':(!defaults[no]&&o[0]===''?' checked':''))+'> '+o[1]+'</label>';
        }).join('');
        html += '<tr>'
            + (cat!==lastCat ? '<td class="cat" rowspan="'+catSpan(cat)+'">'+esc(cat)+'</td>' : '')
            + '<td>'+no+'</td><td class="q">'+esc(text)+'</td><td class="unit">'+esc(unit)+'</td>'
            + '<td><div class="te-radio-grp">'+radios+'</div></td></tr>';
        lastCat = cat;
    });
    $('#defaultsChkBody').html(html);
}
function saveDefaultsSetting(){
    var map = {};
    Object.keys(TEMPLATE).forEach(function(no){
        var v = $('input[name="def_'+no+'"]:checked').val();
        if (v) map[no] = v;
    });
    $.post(API, {action:'answer_defaults_save', defaults:JSON.stringify(map)}, function(res){
        if (!res.success){ alert(res.message||'儲存失敗'); return; }
        closeMask('defaultsMask');
    }, 'json');
}

/* ---------- 開啟全表填寫模式（僅系統管理員，操作確認密碼） ---------- */
$('#btnFullEditMode').on('click', function(){
    if (!CUR_ID){ alert('請先儲存後再使用'); return; }
    if (FULL_EDIT_MODE){ alert('已經是全表填寫模式了。'); return; }
    $('#fullEditPassword').val('');
    openMask('fullEditMask');
});
function submitFullEditUnlock(){
    var password = $('#fullEditPassword').val();
    if (!password){ alert('請輸入操作確認密碼'); return; }
    $.post(API, {action:'admin_full_edit_check', password:password}, function(res){
        if (!res.success){ alert(res.message||'密碼錯誤'); return; }
        FULL_EDIT_MODE = true;
        closeMask('fullEditMask');
        // 開啟當下把管理員設定的確認項目預設值套用到「還沒填」的項次(不覆蓋已有答案)，畫面上先帶入、未存檔前不會真的變更
        $.getJSON(API, {action:'answer_defaults_get'}, function(dres){
            if (dres.success && dres.defaults) {
                Object.keys(dres.defaults).forEach(function(no){
                    if (!CUR_ANSWERS[no]) CUR_ANSWERS[no] = dres.defaults[no];
                });
            }
            renderChecklist(CUR_ANSWERS);
            renderSlots(CUR_SLOTS);
            renderDecisionGrp($('#adminDecisionSelect').val() || '', CUR_SLOTS['prod_decision']);
            applyStatusUI();
        });
        alert('已開啟全表填寫模式：可自行填寫上方全部確認項目、決行選項與各部門意見（尚未填的確認項目已先帶入預設值，仍可修改，未存檔前不會真的變更）；填完後請用「補登簽核」或系統管理員快速設定的「全部自動簽核」正式完成簽核。');
    }, 'json');
}

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

/* ---------- 列印（ai-rules/16：批次列印時逐筆各自開視窗、各自獨立算頁次，onDone供排隊呼叫用） ---------- */
function printDoc(id, onDone){
    $.getJSON(API, {action:'print_get', id:id}, function(res){
        if (!res.success){ alert(res.message||'載入失敗'); if (onDone) onDone(); return; }
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
            return '<div style="font-size:11px;color:#555;margin-bottom:2px;">'+(s.note?esc(s.note):'（無意見）')+'</div>'
                + stampHtml(s.signed_by_name, fmtDate((s.signed_at||'').substring(0,10)), s.is_deputy);
        };
        /* 決行結果逐欄印：生產課與總經理各自印自己簽核當下選的結果（總經理可覆蓋生產課，只印表頭最終值會讓
           總經理那格看起來沒有結果、也會把總經理的選擇誤植到生產課那格）。舊資料沒存逐欄值時退回表頭 decision */
        var decisionText = function(k){
            var s = signoffs[k];
            var v = (s && s.decision_value) ? s.decision_value : ((s && s.signed_by_name) ? d.decision : '');
            return esc(DECISIONS[v] || '（未決行）');
        };
        var apqpRows = '';
        ['tech','sales','mgmt','prod','qa','material'].forEach(function(k){
            apqpRows += '<tr><td class="dept">'+esc(SLOTS[k][0])+'</td><td class="tl">'+slotCell(k)+'</td></tr>';
        });
        var body = '<div class="p-comp">'+esc(res.company_name)+'</div>'
            + '<div class="p-title">'+esc(res.as_doc_name)+'</div>'
            + '<table class="p-hd"><tr><td>客戶名稱</td><td>'+esc(d.customer_name||'')+'</td><td>預估需求量</td><td>'+esc(d.est_qty||'')+' PCS/月</td><td>填表日期</td><td>'+fmtDate(d.fill_date)+'</td></tr>'
            + '<tr><td>產品名稱</td><td>'+esc(d.product_name||'')+'</td><td>料號</td><td>'+esc(d.part_no||'')+'</td><td>送樣時間</td><td>'+esc(d.sample_time||'')+'</td></tr></table>'
            + '<table class="p-tb"><thead><tr><th style="width:60px;">區分</th><th style="width:30px;">項次</th><th>評估項目</th><th style="width:60px;">評估單位</th><th style="width:50px;">結果</th></tr></thead><tbody>'+chkRows+'</tbody></table>'
            + '<div class="p-sec p-sec-break">APQP 小組簽認</div>'
            + '<table class="p-tb"><tbody>'+apqpRows+'</tbody></table>'
            + '<div class="p-sec">生產課決行　決行結果：'+decisionText('prod_decision')+'</div>'
            + '<table class="p-tb"><tr><td class="dept">生產課</td><td class="tl">'+slotCell('prod_decision')+'</td></tr></table>'
            + '<div class="p-sec">總經理決行（最終決策）　決行結果：'+decisionText('gm')+'</div>'
            + '<table class="p-tb"><tr><td class="dept">總經理</td><td class="tl">'+slotCell('gm')+'</td></tr></table>';
        var css = 'body{font-family:"Microsoft JhengHei",sans-serif;margin:0;padding:0 6mm;color:#222;-webkit-print-color-adjust:exact;print-color-adjust:exact;}'
            + '.p-comp{font-size:22px;font-weight:bold;text-align:center;margin-bottom:1px;}'
            + '.p-title{font-size:17px;font-weight:bold;text-align:center;letter-spacing:4px;margin-bottom:10px;}'
            + '.p-sec{font-size:13px;font-weight:bold;color:#8A5A2B;border-left:4px solid #F0A24B;padding-left:6px;margin:8px 0 4px;break-after:avoid;}'
            + '.p-sec-break{page-break-before:always;break-before:page;}'
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
        // APQP 小組簽認固定另起第二頁（p-sec-break），本表列印一定至少2頁，頁碼直接固定顯示，不必再靠scrollHeight判斷是否只有一頁
        w.document.write('<html><head><meta charset="utf-8"><title>產品開發評估表</title><style>'+css
            +'@page{ @bottom-left{ content:\'第 \' counter(page) \' 頁／共 \' counter(pages) \' 頁\'; font-size:9pt; color:#333; vertical-align:top; padding-top:1mm; } }'
            +'</style></head><body>'+body
            +'<scr'+'ipt>window.onload=function(){setTimeout(function(){window.print();},200);};</scr'+'ipt></body></html>');
        w.document.close();
        if (onDone) setTimeout(onDone, 500);
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

/* ---------- 部門簽核人設定（評估表管理員可用，2026-08-13使用者明確要求；同日二次修正支援複選人員/排除主管模式，
   起因技術課兩位工程師都要能簽，不是只能挑一人） ---------- */
var SLOT_OVERRIDE_PEOPLE = [];
$('#btnSlotOverride').on('click', function(){
    $.getJSON(API, {action:'slot_overrides_get'}, function(res){
        if (!res.success) return;
        SLOT_OVERRIDE_PEOPLE = res.people || [];
        renderSlotOverrideList(res.overrides || {});
        openMask('slotOverrideMask');
    });
});
function renderSlotOverrideList(overrides){
    var html = '';
    ['tech','sales','mgmt','prod','qa','material'].forEach(function(k){
        var label = SLOTS[k] ? SLOTS[k][0] : k;
        var ov = overrides[k] || {mode:'auto'};
        var mode = ov.mode || 'auto';
        var selectedIds = (ov.user_ids || []).map(String);
        var peopleHtml = SLOT_OVERRIDE_PEOPLE.map(function(p){
            var plabel = p.user_cname + (p.position_name?'（'+p.position_name+'）':'') + (p.dept_name?'／'+p.dept_name:'');
            return '<label class="ov-people-row" data-kw="'+esc(plabel.toUpperCase())+'" style="display:block;font-weight:normal;margin:2px 0;cursor:pointer;">'
                + '<input type="checkbox" class="ov-people-chk" value="'+p.id+'"'+(selectedIds.indexOf(String(p.id))>=0?' checked':'')+'> '+esc(plabel)+'</label>';
        }).join('');
        html += '<div class="ov-slot-block" data-slot="'+k+'" style="border:1px solid #EADFC8;border-radius:6px;padding:8px;margin-bottom:8px;">'
            + '<b>'+esc(label)+'</b>'
            + '<div style="margin-top:4px;font-size:12px;">'
            + '<label style="font-weight:normal;margin-right:10px;cursor:pointer;"><input type="radio" name="ov_mode_'+k+'" class="ov-mode-radio" value="auto"'+(mode==='auto'?' checked':'')+'> 自動（部門主管）</label>'
            + '<label style="font-weight:normal;margin-right:10px;cursor:pointer;"><input type="radio" name="ov_mode_'+k+'" class="ov-mode-radio" value="people"'+(mode==='people'?' checked':'')+'> 指定人員（可複選）</label>'
            + '<label style="font-weight:normal;cursor:pointer;"><input type="radio" name="ov_mode_'+k+'" class="ov-mode-radio" value="exclude_manager"'+(mode==='exclude_manager'?' checked':'')+'> 主管以外皆可簽</label>'
            + '</div>'
            + '<div class="ov-people-box" style="margin-top:6px;'+(mode==='people'?'':'display:none;')+'">'
            + '<input type="text" class="ov-people-filter" placeholder="輸入人員姓名篩選…" style="width:100%;margin-bottom:4px;height:26px;font-size:12px;border:1px solid #D8BE93;border-radius:4px;padding:0 6px;box-sizing:border-box;">'
            + '<div class="ov-people-list" style="max-height:140px;overflow-y:auto;border:1px solid #EADFC8;border-radius:4px;padding:6px;">'+peopleHtml+'</div>'
            + '</div></div>';
    });
    $('#slotOverrideList').html(html);
}
$(document).on('change', '.ov-mode-radio', function(){
    $(this).closest('.ov-slot-block').find('.ov-people-box').toggle($(this).val() === 'people');
});
$(document).on('input', '.ov-people-filter', function(){
    var kw = $(this).val().trim().toUpperCase();
    $(this).closest('.ov-people-box').find('.ov-people-row').each(function(){
        $(this).toggle(!kw || $(this).data('kw').indexOf(kw) >= 0);
    });
});
function saveSlotOverrides(){
    var map = {};
    $('.ov-slot-block').each(function(){
        var slot = $(this).data('slot');
        var mode = $(this).find('.ov-mode-radio:checked').val();
        if (mode === 'people') {
            var ids = $(this).find('.ov-people-chk:checked').map(function(){ return $(this).val(); }).get();
            if (ids.length) map[slot] = {mode:'people', user_ids:ids};
        } else if (mode === 'exclude_manager') {
            map[slot] = {mode:'exclude_manager'};
        }
    });
    $.post(API, {action:'slot_overrides_save', overrides:JSON.stringify(map)}, function(res){
        if (!res.success){ alert(res.message||'儲存失敗'); return; }
        closeMask('slotOverrideMask');
        alert('已儲存，立即生效。');
    }, 'json');
}

$('#btnSuggest').on('click', function(){ window.location.href = 'td_dev_eval_suggest.php'; });
$('#btnPageHelp').on('click', function(){ openMask('helpUseMask'); });
$('#btnRoleHelp').on('click', function(){ openMask('roleHelpMask'); });
$('.te-mask').on('click', function(e){ if (e.target === this) this.style.display='none'; });

/* ---------- 回到頂端（清單較長時看不到列表標題與工具列，右下角提供快捷鈕） ---------- */
$(window).on('scroll', function(){ $('#btnBackTop').toggle($(window).scrollTop() > 400); });
$('#btnBackTop').on('click', function(){ $('html,body').animate({scrollTop:0}, 200); });

<?php if ($perms['canView']): ?>
loadTemplate(function(){ loadList(); });
loadAsDocCurrent();
<?php endif; ?>
</script>
</body>
</html>
