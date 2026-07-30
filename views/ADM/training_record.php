<?php
/**
 * 教育訓練管理（KPI 2-GM-04-01 第19項 人員教育訓練達成率 的來源頁）
 * 管理員後端維護各部門/年度訓練計畫；達成率=當月完成場次/計畫場次。
 * 資料一律走 src/store/Training_API.php；權限 training_lib.php
 */
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/ADM/training_record.php";
    header("Location:../../index.php");
    exit;
}
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/training_lib.php';

$db = (new DBConnection())->getPDO();
training_ensure_schema($db);
$trUser = training_current_user($db);
$perms = training_perms($db, $trUser);
$roleLabel = $perms['isAdmin'] ? '管理者'
           : ($perms['canAdmin'] ? '訓練管理員'
           : ($perms['canEdit'] ? '訓練登錄'
           : ($perms['canView'] ? '訓練檢閱' : '無權限')));
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>教育訓練管理</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <style>
        #sidebar-menu { visibility: hidden; }
        .right_col .page-title { margin:8px 0 4px; overflow:hidden; }
        .tr-toolbar { display:flex; flex-wrap:wrap; gap:6px; align-items:center; clear:both;
            border:1.5px solid #E8D5B5; border-radius:8px; padding:8px 10px; margin-bottom:10px; background:#FDF8EF; }
        .tr-toolbar label { margin:0; font-size:13px; color:#5b3a1e; }
        .tr-toolbar select, .tr-toolbar input[type=text], .tr-toolbar button {
            height:30px; font-size:13px; line-height:1; padding:0 10px; border:1px solid #D8BE93;
            border-radius:4px; background:#fff; color:#5b3a1e; cursor:pointer; }
        .tr-toolbar button:hover { background:#F7E0BD; }
        .tr-toolbar .btn-warm { background:#F0A24B; color:#fff; border-color:#d98a33; }
        .tr-toolbar .btn-warm:hover { background:#d98a33; }
        .tr-role-badge { margin-left:auto; font-size:13px; color:#5b3a1e; background:#F7E0BD; border-radius:12px; padding:4px 12px; }
        .tr-role-badge .fa-question-circle { cursor:pointer; color:#b5762a; margin-left:5px; }
        .tr-stat { display:flex; flex-wrap:wrap; gap:6px; align-items:center; margin-bottom:10px;
            border:1.5px solid #E8D5B5; border-radius:8px; padding:8px 10px; background:#FFF7E8; }
        .tr-stat .yr { font-size:14px; color:#8A5A2B; font-weight:bold; margin-right:8px; }
        .mon-pill { display:inline-block; font-size:12px; border:1px solid #E8D5B5; border-radius:6px;
            padding:2px 7px; color:#5b3a1e; background:#fff; }
        .mon-pill b { color:#8A5A2B; }
        .mon-pill.below b { color:#DD5138; }
        .mon-pill.empty { color:#c4b79c; }
        .tr-table-wrap { overflow-x:auto; border:1px solid #E8D5B5; border-radius:6px; background:#fff; }
        table.tr-table { width:100%; border-collapse:collapse; font-size:13px; }
        table.tr-table th, table.tr-table td { border:1px solid #EADFC8; padding:5px 8px; white-space:nowrap; text-align:center; }
        table.tr-table thead th { position:sticky; top:0; z-index:5; background:#F7E0BD; color:#5b3a1e; font-weight:bold; }
        table.tr-table tbody tr:nth-child(even) { background:#FBF6EC; }
        table.tr-table tbody tr:hover { background:#FBF0DD; }
        table.tr-table td.t-left { text-align:left; }
        .st-pill { display:inline-block; font-size:12px; border-radius:10px; padding:2px 9px; }
        .st-planned { background:#F7E0BD; color:#7a5217; }
        .st-scheduled { background:#E8B77A; color:#4d2f10; }
        .st-done { background:#F0A24B; color:#fff; }
        .st-cancelled { background:#efe7d8; color:#b0a390; text-decoration:line-through; }
        .tr-op { color:#b5762a; cursor:pointer; margin:0 4px; }
        .tr-op:hover { color:#8A5A2B; text-decoration:underline; }
        input[type=number]::-webkit-outer-spin-button, input[type=number]::-webkit-inner-spin-button { -webkit-appearance:none; margin:0; }
        input[type=number] { -moz-appearance:textfield; }
        .tr-mask { display:none; position:fixed; inset:0; background:rgba(60,40,20,.45); z-index:1050; }
        .tr-modal { background:#fff; border-radius:8px; max-width:600px; margin:48px auto; box-shadow:0 5px 25px rgba(0,0,0,.3);
            max-height:86vh; display:flex; flex-direction:column; }
        .tr-modal .m-head { background:#F7E0BD; color:#5b3a1e; font-weight:bold; padding:10px 15px; border-radius:8px 8px 0 0;
            display:flex; justify-content:space-between; }
        .tr-modal .m-head .m-close { cursor:pointer; color:#b5762a; }
        .tr-modal .m-body { padding:15px; overflow-y:auto; }
        .tr-modal .m-body label { display:block; font-size:13px; color:#5b3a1e; margin:9px 0 3px; }
        .tr-modal .m-body input[type=text], .tr-modal .m-body input[type=number], .tr-modal .m-body input[type=date],
        .tr-modal .m-body select, .tr-modal .m-body textarea { width:100%; border:1px solid #D8BE93; border-radius:4px;
            padding:5px 8px; font-size:13px; box-sizing:border-box; }
        .tr-modal .m-foot { padding:10px 15px; border-top:1px solid #EADFC8; text-align:right; }
        .tr-modal .m-foot button { height:30px; padding:0 16px; border-radius:4px; font-size:13px; border:1px solid #d98a33; cursor:pointer; }
        .tr-modal .m-foot .b-ok { background:#F0A24B; color:#fff; }
        .tr-modal .m-foot .b-cancel { background:#fff; color:#5b3a1e; border-color:#D8BE93; margin-right:6px; }
        .grid2 { display:grid; grid-template-columns:1fr 1fr; gap:0 14px; }
        .tr-modal.wide { max-width:820px; }
        .tr-hint { margin-top:12px; font-size:12px; color:#8a6d45; background:#FDF8EF; border:1px dashed #E8D5B5;
            border-radius:6px; padding:7px 10px; line-height:1.6; }
        .tr-hint b { color:#8A5A2B; }
        .ex-plan { border:1px solid #E8D5B5; border-radius:6px; background:#FFF7E8; padding:8px 10px; font-size:13px;
            color:#5b3a1e; line-height:1.8; margin-bottom:4px; }
        .ex-plan b { color:#8A5A2B; }
        .ex-plan .st-pill { margin-left:4px; }
        /* 即時錯誤提示（欄位變紅底＋下方紅字說明原因） */
        .errmsg { color:#DD5138; font-size:11.5px; line-height:1.5; }
        .tr-modal .m-body input.inv, .tr-modal .m-body select.inv, .day-tbl input.inv {
            border-color:#DD5138 !important; background:#FFF4F0; }
        .batch-box { border:1px dashed #E8D5B5; background:#FDF8EF; border-radius:6px; padding:7px 9px;
            margin:10px 0 6px; display:flex; flex-wrap:wrap; gap:6px; align-items:center; font-size:12.5px; color:#5b3a1e; }
        .batch-box input { height:28px; border:1px solid #D8BE93; border-radius:4px; padding:0 6px; font-size:13px; background:#fff; }
        table.day-tbl { width:100%; border-collapse:collapse; font-size:12.5px; }
        table.day-tbl th, table.day-tbl td { border:1px solid #EADFC8; padding:3px 5px; text-align:center; }
        table.day-tbl thead th { position:sticky; top:0; background:#F7E0BD; color:#5b3a1e; z-index:2; }
        table.day-tbl tbody tr:nth-child(even) { background:#FBF6EC; }
        table.day-tbl input { width:100%; border:1px solid #D8BE93; border-radius:4px; padding:3px 5px;
            font-size:12.5px; box-sizing:border-box; }
        table.day-tbl td.chk { text-align:left; font-size:11.5px; color:#DD5138; }
        table.day-tbl td.chk.ok { color:#8a6d45; }
        /* 系統自動算出、不給手改的欄位（休息時間）：灰底＋灰字，一看就知道不能填 */
        input.ro-auto, input.ro-auto:focus { background:#EFEAE1 !important; color:#7a7166 !important;
            border-color:#D9D1C4 !important; cursor:not-allowed; }
        .b-att.nw { flex:0 0 auto; white-space:nowrap; }
        .time-in { letter-spacing:.5px; text-align:center; }
        .att-sec { border-top:1px dashed #EADFC8; margin-top:10px; }
        .att-people { max-height:130px; overflow-y:auto; border:1px solid #EADFC8; border-radius:6px; padding:6px 8px;
            display:flex; flex-wrap:wrap; gap:4px 14px; margin-bottom:6px; min-height:20px; }
        .att-people label { font-size:12px; color:#5b3a1e; margin:0; font-weight:normal; cursor:pointer; }
        .att-people .empty { color:#b0a390; font-size:12px; }
        button.b-att { height:28px; font-size:12px; border:1px solid #d98a33; background:#F0A24B; color:#fff; border-radius:4px; cursor:pointer; padding:0 10px; }
        .att-list-wrap { max-height:180px; overflow-y:auto; border:1px solid #EADFC8; border-radius:6px; }
        table.att-tbl { width:100%; border-collapse:collapse; font-size:12px; }
        table.att-tbl th, table.att-tbl td { border-bottom:1px solid #F0E7D5; padding:3px 8px; text-align:center; }
        table.att-tbl thead th { position:sticky; top:0; background:#F7E0BD; color:#5b3a1e; }
        table.att-tbl td.t-left { text-align:left; }
        .att-del { color:#DD5138; cursor:pointer; }
        .tr-noperm { margin:40px auto; max-width:520px; text-align:center; border:1.5px solid #E8D5B5; border-radius:10px;
            padding:30px; background:#FDF8EF; color:#5b3a1e; }
        @media print {
            .tr-toolbar, .nav_menu, .left_col, footer, .tr-role-badge .fa-question-circle, .tr-op { display:none !important; }
            .right_col { margin:0 !important; padding:0 !important; }
            table.tr-table thead th { position:static; }
        }
    </style>
</head>
<body class="nav-sm">
<div class="container body">
<div class="main_container">
    <?php include '../partPage/sideAndTopBarMenu.html' ?>
    <div class="right_col" role="main">
        <div class="page-title" style="display:flex;align-items:center;flex-wrap:wrap;">
            <h2 style="margin:6px 0;">教育訓練管理
                <small style="color:#8a6d45;">KPI 2-GM-04-01 #19 人員教育訓練達成率 來源頁</small></h2>
        </div>
        <div class="clearfix"></div>

<?php if (!$perms['canView']): ?>
        <div class="tr-noperm">
            <h4><i class="fa fa-lock"></i> 無教育訓練檢閱權限</h4>
            <p>請洽管理者於「使用者權限設定」指派「訓練檢閱／登錄／管理員」角色。</p>
        </div>
<?php else: ?>
        <div class="tr-toolbar">
            <label>年度</label>
            <select id="yearSel"></select>
            <label>部門</label>
            <select id="deptSel"><option value="">全部</option></select>
            <label>狀態</label>
            <select id="statSel">
                <option value="">全部</option><option value="planned">計畫中</option>
                <option value="scheduled">已排定</option>
                <option value="done">已完成</option><option value="cancelled">取消</option>
            </select>
            <input type="text" id="kwSel" placeholder="搜尋課程" style="width:130px;">
            <button class="btn-warm" id="btnAdd" style="display:none;"><i class="fa fa-plus"></i> 新增訓練場次</button>
            <button id="btnSetting" style="display:none;"><i class="fa fa-sliders"></i> 模組設定</button>
            <button id="btnCsv"><i class="fa fa-file-text-o"></i> 匯出CSV</button>
            <button onclick="window.print()"><i class="fa fa-print"></i> 列印</button>
            <span class="tr-role-badge">目前角色：<b><?= htmlspecialchars($roleLabel) ?></b>
                <i class="fa fa-question-circle" id="btnRoleHelp" title="角色權限說明"></i></span>
        </div>

        <div class="tr-stat" id="statBar">
            <span class="yr" id="yrRate">—</span>
            <span id="monWrap"></span>
        </div>

        <div class="tr-table-wrap">
            <table class="tr-table" id="trTable">
                <thead><tr>
                    <th>月份</th><th>對象部門</th><th>課程名稱</th><th>類型</th><th>講師/開課單位</th><th>時數</th>
                    <th>應到</th><th>實到</th><th>狀態</th><th>開課日期</th><th>操作</th>
                </tr></thead>
                <tbody id="trBody"><tr><td colspan="11" style="padding:20px;color:#8a6d45;">載入中…</td></tr></tbody>
            </table>
        </div>
        <div style="font-size:11px;color:#8a6d45;margin-top:4px;">
            狀態：<span class="st-pill st-planned">計畫中</span> <span class="st-pill st-scheduled">已排定</span>
            <span class="st-pill st-done">已完成</span> <span class="st-pill st-cancelled">取消</span>。
            流程分三步：<b>①計畫</b>（年月、課程、部門、講師/開課單位、時數）→
            <b>②確認實行</b>＝確定要開課（上課日期、每日時間、地點、參加人員 → 狀態轉「已排定」，此時就能列印簽到表給人員簽名；多天課程一天一頁）→
            <b>③登錄完成</b>＝上完課後回到同一畫面勾「實到」再按「登錄完成」（狀態轉「已完成」）。
            KPI 達成率＝當月「已完成」場次 ÷「計畫」場次（已排定算分母不算分子、取消不計入）；部門留空＝全公司課程。時數欄有 <span style="color:#DD5138;">*</span> 表示實際時數與計畫不同。
            時數＝(結束−開始)−休息；<b>休息不可手動修改</b>，由系統算「上課時間 ∩ 休息時段」的重疊分鐘（休息時段見「模組設定」，預設 12:00~13:00；沒跨到就不扣）。
            狀態轉「已排定/已完成」時會自動把每個上課日寫進<b>行事曆</b>（內訓/外訓各自類別，退回或取消會自動撤除）。
        </div>
<?php endif; ?>
    </div>
    <?php include '../partPage/footer.html' ?>
</div>
</div>

<!-- 新增/編輯「訓練計畫」modal（只填計畫內容，不含日期時間/地點/人員） -->
<div class="tr-mask" id="edMask"><div class="tr-modal" data-eg-form data-eg-submit=".m-foot .b-ok">
    <div class="m-head"><span id="edTitle">新增訓練計畫</span><span class="m-close" onclick="closeMask('edMask')">✕</span></div>
    <div class="m-body">
        <div class="grid2">
            <div><label>年度 *</label><input type="number" id="edYear" step="1"></div>
            <div><label>計畫月份 *</label><select id="edMonth"></select></div>
            <div><label>課程/訓練名稱 *</label><input type="text" id="edCourse" maxlength="100"></div>
            <div><label>對象部門（留空＝全公司）</label><select id="edDept"><option value="">全公司</option></select></div>
            <div><label>訓練類型</label><select id="edType"><option value="internal">內訓</option><option value="external">外訓</option></select></div>
            <div><label>預計天數（多天課程）</label><input type="number" id="edDays" step="1" min="1" max="60" placeholder="1">
                <div class="errmsg" id="errEdDays"></div></div>
            <div><label>預計總時數</label><input type="number" id="edHours" step="any" min="0">
                <div class="errmsg" id="errEdHours"></div></div>
            <div style="display:flex;align-items:flex-end;padding-bottom:8px;font-size:12px;color:#8a6d45;">
                <span id="edDayHint"></span></div>
        </div>
        <div id="edInternalBox">
            <label>講師（部門→人員；外部講師可直接打字）</label>
            <div style="display:flex;gap:6px;">
                <select id="edTrainerDept" style="flex:0 0 130px;"><option value="">部門</option></select>
                <select id="edTrainerPerson" style="flex:0 0 130px;"><option value="">人員</option></select>
                <input type="text" id="edTrainer" maxlength="50" placeholder="講師姓名" style="flex:1;">
            </div>
        </div>
        <div id="edExternalBox" style="display:none;">
            <label>開課單位／主辦（外訓）*</label><input type="text" id="edOrgUnit" maxlength="100" placeholder="例：中衛發展中心">
        </div>
        <label>備註</label><input type="text" id="edNote" maxlength="200">
        <div class="tr-hint"><i class="fa fa-info-circle"></i>
            上課日期、每日時間、上課地點與參加人員，請於清單按 <b>確認實行</b> 登錄；計畫存檔後狀態為「計畫中」。
            多天課程在此填「預計天數」，確認實行時會自動排出連續日期供逐日調整。</div>
    </div>
    <div class="m-foot">
        <button class="b-cancel" onclick="closeMask('edMask')">取消</button>
        <button class="b-ok" onclick="submitEd()">儲存計畫</button>
    </div>
</div></div>

<!-- 確認實行 modal（實際開課日期時間、地點、參加人員） -->
<div class="tr-mask" id="exMask"><div class="tr-modal wide" data-eg-form data-eg-submit="#exSave">
    <div class="m-head"><span id="exTitle">確認實行</span><span class="m-close" onclick="closeMask('exMask')">✕</span></div>
    <div class="m-body">
        <div class="ex-plan" id="exPlanInfo"></div>
        <div class="grid2">
            <div><label>開課首日 *</label><input type="date" id="exDone" max="9999-12-31">
                <div class="errmsg" id="errExDone"></div></div>
            <div><label>上課地點</label>
                <div style="display:flex;gap:5px;">
                    <select id="exLocSel" style="flex:1;"><option value="">（未指定）</option></select>
                    <button type="button" class="b-att" onclick="openLocMgr()" title="新增/管理上課地點"><i class="fa fa-cog"></i> 地點設定</button>
                </div></div>
            <div><label>上課天數 *</label><input type="number" id="exDays" step="1" min="1" max="60" value="1">
                <div class="errmsg" id="errExDays"></div></div>
            <div style="display:flex;align-items:flex-end;padding-bottom:6px;">
                <span id="exHourHint" style="font-size:12px;color:#8a6d45;"></span></div>
        </div>

        <div class="batch-box">
            <b>套用班別</b>
            <select id="exShift" style="max-width:210px;"><option value="">（不套用班別）</option></select>
            <span style="color:#8a6d45;">→ 帶入上下班時間與休息時間</span>
        </div>
        <div class="batch-box" style="margin-top:0;">
            <b>每日上課時間</b>
            <span>開始</span><input type="text" id="exBStart" class="time-in" maxlength="5" placeholder="09:00" style="width:66px;">
            <span>結束</span><input type="text" id="exBEnd" class="time-in" maxlength="5" placeholder="17:00" style="width:66px;">
            <span>休息</span><input type="number" id="exBBreak" class="ro-auto" style="width:62px;" readonly tabindex="-1"
                title="休息時間由系統依「上課時間 ∩ 休息時段」自動計算，不可手動修改"><span>分</span>
            <button type="button" class="b-att nw" onclick="dayApplyAll()"><i class="fa fa-clone"></i> 套用到全部日期</button>
            <button type="button" class="b-att nw" style="background:#fff;color:#8A5A2B;" onclick="dayRebuild()"><i class="fa fa-refresh"></i> 依首日與天數重建日期</button>
            <span class="errmsg" id="errBatch" style="margin:0;"></span>
            <span id="brkHint" style="flex-basis:100%;color:#8a6d45;font-size:11.5px;"></span>
        </div>
        <div class="att-list-wrap" style="max-height:210px;">
            <table class="day-tbl">
                <thead><tr><th style="width:52px;">第</th><th style="width:130px;">上課日期</th><th style="width:70px;">開始</th>
                    <th style="width:70px;">結束</th><th style="width:66px;" title="依休息時段自動計算，不可手改">休息(分)</th><th style="width:62px;">時數</th>
                    <th>檢查</th><th style="width:28px;"></th></tr></thead>
                <!-- 末列 ↓ 自動加一天、最末列沒填東西時 ↑ 自動移除（共用檔 eg_input_rules.js 規則6） -->
                <tbody id="dayBody" data-eg-row-add="dayAdd" data-eg-row-del="dayDelLast"></tbody>
            </table>
        </div>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:4px 0 2px;font-size:12px;color:#5b3a1e;">
            <button type="button" class="b-att nw" onclick="dayAdd()"><i class="fa fa-plus"></i> 新增一天</button>
            <span id="exTotalHint" style="flex:0 0 auto;"></span>
            <span style="color:#8a6d45;flex:1 1 260px;">時間可直接輸入（09:00、0900、9 都可）；先用上方「套用到全部日期」設定，再個別修改不同的那幾天。
                最末列按 <b>↓</b> 自動加一天、沒填東西的最末列按 <b>↑</b> 自動移除。
                休息由系統自動算（灰底不可改），時數＝(結束−開始)−休息，可自行覆寫。</span>
        </div>

        <div class="att-sec">
            <div style="font-weight:bold;color:#5b3a1e;margin:12px 0 4px;">參加人員名單 <small id="attCount" style="color:#8a6d45;font-weight:normal;"></small>
                <small id="attNote" style="color:#DD5138;font-weight:normal;"></small>
                <small style="color:#8a6d45;font-weight:normal;">（講師不列入名單）</small></div>
            <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-bottom:4px;">
                <select id="attDept" style="width:150px;height:28px;border:1px solid #D8BE93;border-radius:4px;"><option value="">選部門載入人員…</option></select>
                <button type="button" class="b-att" onclick="attAddChecked()"><i class="fa fa-user-plus"></i> 加入勾選人員</button>
                <label style="margin:0;font-size:12px;color:#8a6d45;"><input type="checkbox" id="attPickAll"> 全選</label>
            </div>
            <div id="attPeopleBox" class="att-people"></div>
            <div class="att-list-wrap">
                <table class="att-tbl"><thead><tr><th>姓名</th><th>部門</th><th>職稱</th><th>實到</th><th>簽名</th><th></th></tr></thead>
                <tbody id="attBody"></tbody></table>
            </div>
            <div style="font-size:11px;color:#8a6d45;margin-top:3px;">開課前先建名單並列印簽到表；上完課回來勾「實到」再按「登錄完成」。</div>
        </div>
    </div>
    <div class="m-foot">
        <div style="text-align:left;font-size:11.5px;color:#8a6d45;line-height:1.6;margin-bottom:6px;">
            <b style="color:#8A5A2B;">確認開課</b>＝<u>課還沒上</u>，只是確定要開（狀態→已排定、寫入行事曆、可印簽到表）；
            <b style="color:#8A5A2B;">登錄完成</b>＝<u>課已經上完</u>、實到也勾好了（狀態→已完成，<b>此時才計入當月教育訓練達成率</b>）。
            當天上完課可直接按「登錄完成」，不必先按「確認開課」。
        </div>
        <button class="b-cancel" onclick="printSignSheet()"><i class="fa fa-print"></i> 列印簽到表</button>
        <button class="b-cancel" id="exRevert" style="display:none;color:#DD5138;" onclick="revertPlanned()"><i class="fa fa-undo"></i> 退回計畫中</button>
        <button class="b-cancel" onclick="closeMask('exMask')">取消</button>
        <button class="b-ok" id="exSave" onclick="submitEx(0)" title="課還沒上：確定要開這堂課（狀態→已排定，可印簽到表）">確認開課</button>
        <button class="b-ok" id="exFinish" style="margin-left:6px;" onclick="submitEx(1)" title="課已上完：連同實到名單登錄完成（狀態→已完成，計入 KPI 達成率）">登錄完成</button>
    </div>
</div></div>

<!-- 模組設定 modal（限訓練管理員）：預設班別、行事曆類別綁定 -->
<div class="tr-mask" id="setMask"><div class="tr-modal" style="max-width:520px;" data-eg-form data-eg-submit=".m-foot .b-ok">
    <div class="m-head"><span>教育訓練模組設定</span><span class="m-close" onclick="closeMask('setMask')">✕</span></div>
    <div class="m-body">
        <label>預設套用班別（只用來帶入上下班時間；休息一律由下方「休息時段」計算）</label>
        <select id="setShift"><option value="">（不套用班別）</option></select>
        <div class="tr-hint" style="margin-top:6px;">班別資料與「輪值排班表」的固定班別共用（`shift_type`）；在此只是選預設值，確認實行時仍可逐場改，
            且<b>不限制上課時間必須落在班別的上下班時間內</b>。</div>

        <label>休息時段（上課時間跨到這段才扣休息；兩欄都留空＝不扣休息）</label>
        <div style="display:flex;gap:6px;align-items:center;">
            <input type="text" id="setBrkStart" class="time-in" maxlength="5" placeholder="12:00" style="width:80px;">
            <span style="color:#5b3a1e;">~</span>
            <input type="text" id="setBrkEnd" class="time-in" maxlength="5" placeholder="13:00" style="width:80px;">
            <span id="setBrkLen" style="font-size:12px;color:#8a6d45;"></span>
        </div>
        <div class="errmsg" id="errSetBrk"></div>
        <div class="tr-hint" style="margin-top:6px;">休息時間<b>不給手動修改</b>，一律由系統算「上課時間 ∩ 休息時段」的重疊分鐘數：
            08:00~17:00 的課跨過整段午休 → 扣 60 分；<b>11:00~12:00 的課沒跨到午休 → 扣 0 分</b>（不會再發生短時段課程被扣掉整個午休、時數算成負數的情況）。
            <span id="setBrkVsShift" style="color:#8A5A2B;"></span></div>

        <div style="border-top:1px dashed #EADFC8;margin:14px 0 0;"></div>
        <label>行事曆類別綁定 — 內訓</label>
        <select id="setCatIn"><option value="">（自動：以名稱「課程(內訓)」尋找）</option></select>
        <label>行事曆類別綁定 — 外訓</label>
        <select id="setCatEx"><option value="">（自動：以名稱「課程(外訓)」尋找）</option></select>
        <div class="tr-hint" style="margin-top:6px;">綁定存的是類別 <b>id</b>，所以日後在行事曆把類別改名（例如「課程(內訓)」→「內部訓練」）綁定依然有效。
            未綁定時才用名稱尋找，找不到就不寫行事曆（不影響存檔）。<br>
            <span id="setCatEff" style="color:#8A5A2B;"></span></div>
    </div>
    <div class="m-foot">
        <button class="b-cancel" onclick="closeMask('setMask')">取消</button>
        <button class="b-ok" onclick="saveSettings()">儲存設定</button>
    </div>
</div></div>

<!-- 上課地點設定 modal（地點主檔：新增後即可下拉選擇） -->
<div class="tr-mask" id="locMask"><div class="tr-modal" style="max-width:440px;" data-eg-form data-eg-submit=".m-body .b-att">
    <div class="m-head"><span>上課地點設定</span><span class="m-close" onclick="closeMask('locMask')">✕</span></div>
    <div class="m-body">
        <label>新增地點</label>
        <div style="display:flex;gap:6px;">
            <input type="text" id="locNew" maxlength="100" placeholder="例：二樓會議室" style="flex:1;">
            <button type="button" class="b-att" onclick="locAdd()"><i class="fa fa-plus"></i> 新增</button>
        </div>
        <div class="errmsg" id="errLocNew"></div>
        <div class="att-list-wrap" style="margin-top:10px;max-height:230px;">
            <table class="att-tbl"><thead><tr><th class="t-left">地點名稱</th><th style="width:60px;">停用</th></tr></thead>
            <tbody id="locBody"></tbody></table>
        </div>
        <div style="font-size:11px;color:#8a6d45;margin-top:4px;">停用只是不再出現在下拉選單，已登錄的舊紀錄不受影響（停用限訓練管理員）。</div>
    </div>
    <div class="m-foot"><button class="b-ok" onclick="closeMask('locMask')">關閉</button></div>
</div></div>

<!-- 角色說明 modal -->
<div class="tr-mask" id="helpMask"><div class="tr-modal">
    <div class="m-head"><span>角色權限說明</span><span class="m-close" onclick="closeMask('helpMask')">✕</span></div>
    <div class="m-body" style="font-size:13px;color:#5b3a1e;line-height:1.8;">
        <b>訓練檢閱</b>：檢視訓練計畫/紀錄、月達成率與匯出。<br>
        <b>訓練登錄</b>：檢閱＋新增/編輯計畫、確認實行（確定開課）、登錄完成、列印簽到表。<br>
        <b>訓練管理員</b>：登錄＋刪除場次。<br>
        <b>管理者</b>：系統管理者固定擁有全部權限。<br>
        <hr style="border-color:#EADFC8;">
        本頁資料為 KPI「人員教育訓練達成率(#19)」計算來源；達成率依「計畫月份」歸月計算。<br>
        作業流程：<b>計畫</b>（要辦什麼）→ <b>確認實行</b>（確定開課，狀態「已排定」，可印簽到表）→ <b>登錄完成</b>（上完課、勾實到，狀態「已完成」才計入達成率）。
    </div>
</div></div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<!-- 全站輸入欄位規則（雙擊清空/自動全選/Enter 跳欄/表格 ↑↓ 換列與自動增刪列）：CLAUDE.md「UI 規則」唯一實作 -->
<script src="../../resource/js/eg_input_rules.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_input_rules.js') ?>"></script>
<script>
$(document).ready(function(){
    var $am = $('#sidebar-menu .nav.side-menu > li.active');
    if ($am.length) { $am.removeClass('active').find('ul.child_menu').hide(); $am.find('li.current-page').removeClass('current-page'); }
    $('#sidebar-menu').css('visibility','visible');
});

var API = '../../src/store/Training_API.php';
var META = null, ROWS = [], PERMS = null;
var canView = <?= $perms['canView'] ? 'true' : 'false' ?>;
var STATUS_LABEL = {planned:'計畫中', scheduled:'已排定', done:'已完成', cancelled:'取消'};

/* ---------- 時間：直接輸入（09:00 / 0900 / 9 都可），即時檢查合理性 ----------
   使用者明確要求：不要下拉選時間，直接填最好用；但錯誤要當下就說明原因。 */
function parseTime(v){                 // 回傳 {ok:true,val:'HH:MM'} 或 {ok:false,msg:'原因'}
    var s = String(v==null?'':v).trim().replace(/[：]/g,':').replace(/\s+/g,'');
    if (s==='') return {ok:true, val:''};
    var hh, mm, m;
    if ((m = s.match(/^(\d{1,2}):(\d{1,2})$/))) { hh=+m[1]; mm=+m[2]; }
    else if ((m = s.match(/^(\d{1,2})$/)))      { hh=+m[1]; mm=0; }
    else if ((m = s.match(/^(\d)(\d{2})$/)))    { hh=+m[1]; mm=+m[2]; }
    else if ((m = s.match(/^(\d{2})(\d{2})$/))) { hh=+m[1]; mm=+m[2]; }
    else return {ok:false, msg:'時間格式應為 HH:MM（例 09:00，也可打 0900 或 9）'};
    if (hh>23) return {ok:false, msg:'小時 '+hh+' 不存在，須 0~23（沒有 '+hh+':00 這個時刻）'};
    if (mm>59) return {ok:false, msg:'分鐘 '+mm+' 不存在，須 0~59'};
    return {ok:true, val:(hh<10?'0':'')+hh+':'+(mm<10?'0':'')+mm};
}
function timeToMin(t){ if(!t) return null; var p=String(t).split(':'); return (+p[0])*60 + (+p[1]); }
function minToTime(m){ if(m==null) return ''; m=Math.round(m); if(m<0) m=0; if(m>1439) m=1439;
    var h=Math.floor(m/60), mm=m%60; return (h<10?'0':'')+h+':'+(mm<10?'0':'')+mm; }
function addDaysStr(dstr, n){                 // 'YYYY-MM-DD' +n 天
    var p=String(dstr).split('-'); var d=new Date(+p[0], (+p[1])-1, +p[2]);
    d.setDate(d.getDate()+n);
    return d.getFullYear()+'-'+('0'+(d.getMonth()+1)).slice(-2)+'-'+('0'+d.getDate()).slice(-2);
}
function validDateStr(s){
    if (!/^\d{4}-\d{2}-\d{2}$/.test(String(s||''))) return false;
    var p=s.split('-'), d=new Date(+p[0], (+p[1])-1, +p[2]);
    return d.getFullYear()===+p[0] && d.getMonth()===(+p[1])-1 && d.getDate()===+p[2];
}
/* 即時錯誤顯示：欄位變紅＋指定容器顯示原因 */
function setErr($el, msgBoxId, msg){
    $el.toggleClass('inv', !!msg);
    if (msgBoxId) $('#'+msgBoxId).text(msg||'');
    return !msg;
}

function closeMask(id){ document.getElementById(id).style.display='none'; }
function openMask(id){ document.getElementById(id).style.display='block'; }
function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){
    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
function fmtDate(d){ return d ? String(d).substr(0,10) : ''; }
function numTrim(v){ if (v==null||v==='') return ''; var n=parseFloat(v); return (Math.round(n*10)/10)+''; }

function loadMeta(cb){
    $.getJSON(API, {action:'meta'}, function(m){
        if (!m.ok){ alert(m.error||'載入失敗'); return; }
        META = m; PERMS = m.perms;
        var $y = $('#yearSel').empty();
        m.years.forEach(function(y){ $y.append('<option value="'+y+'">'+y+'</option>'); });
        $y.val(m.cur_year);
        var $d = $('#deptSel'), $ed = $('#edDept'), $td = $('#edTrainerDept'), $ad = $('#attDept');
        m.departments.forEach(function(d){
            $d.append('<option value="'+d.id+'">'+esc(d.name)+'</option>');
            $ed.append('<option value="'+d.id+'">'+esc(d.name)+'</option>');
            $td.append('<option value="'+d.id+'">'+esc(d.name)+'</option>');
            $ad.append('<option value="'+d.id+'">'+esc(d.name)+'</option>');
        });
        var $em = $('#edMonth').empty();
        for (var i=1;i<=12;i++) $em.append('<option value="'+i+'">'+i+'月</option>');
        LOCS = m.locations || []; renderLocSel();
        SHIFTS = m.shifts || []; SETTINGS = m.settings || {}; CATS = m.event_categories || [];
        applyBreakSetting();
        CAT_EFF = {internal:m.cat_internal_eff||null, external:m.cat_external_eff||null};
        renderShiftSel();
        if (m.perms.canEdit) $('#btnAdd').show();
        if (m.perms.canAdmin) $('#btnSetting').show();
        if (cb) cb();
    });
}

function loadList(){
    NProgress.start();
    $.getJSON(API, {action:'list', year:$('#yearSel').val()}, function(res){
        NProgress.done();
        if (!res.ok){ alert(res.error||'載入失敗'); return; }
        ROWS = res.rows; PERMS = res.perms;
        renderStat(res);
        renderTable();
    }).fail(function(x){ NProgress.done(); alert('載入失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}

function renderStat(res){
    $('#yrRate').text(res.year+' 年度達成率：'+(res.year_rate===null?'—':res.year_rate+'%')
        +'（'+res.year_num+'/'+res.year_den+' 場）');
    var h = '';
    for (var m=1;m<=12;m++){
        var s = res.summary[m];
        if (!s.den){ h += '<span class="mon-pill empty">'+m+'月 —</span> '; continue; }
        var rate = Math.round(s.num/s.den*1000)/10;
        h += '<span class="mon-pill'+(rate<95?' below':'')+'">'+m+'月 <b>'+rate+'%</b> ('+s.num+'/'+s.den+')</span> ';
    }
    $('#monWrap').html(h);
}

function statPill(s){ return '<span class="st-pill st-'+s+'">'+(STATUS_LABEL[s]||s)+'</span>'; }

/* 清單「開課日期」欄：多天課程顯示範圍與天數；計畫中/取消不顯示（尚未確認開課） */
function dateRangeText(r){
    if (r.status==='planned' || r.status==='cancelled') return '—';
    var ds = (r.days||[]).map(function(d){ return fmtDate(d.day_date); }).filter(Boolean).sort();
    if (!ds.length) return fmtDate(r.done_date) || '—';
    if (ds.length===1) return ds[0];
    var t = ds[0]+' ~ '+ds[ds.length-1].substr(5)+'<br><span style="font-size:11px;color:#8a6d45;">共 '+ds.length+' 天</span>';
    return '<span title="'+ds.join('、')+'">'+t+'</span>';
}

function renderTable(){
    var dep = $('#deptSel').val(), stt = $('#statSel').val(), kw = $.trim($('#kwSel').val()).toLowerCase();
    var html = '';
    ROWS.forEach(function(r){
        if (dep && String(r.dept_id)!==String(dep)) return;
        if (stt && r.status!==stt) return;
        if (kw && String(r.course_name).toLowerCase().indexOf(kw)<0) return;
        var ext = r.train_type==='external';
        html += '<tr>';
        html += '<td>'+r.plan_month+'月</td>';
        html += '<td>'+esc(r.dept_name||'')+'</td>';
        html += '<td class="t-left"><b>'+esc(r.course_name)+'</b></td>';
        html += '<td>'+(ext?'<span style="color:#c0762c;">外訓</span>':'內訓')+'</td>';
        html += '<td>'+esc((ext?r.org_unit:r.trainer)||'—')+'</td>';
        // 時數：已排定/已完成有登錄實際時數就顯示實際值（與計畫不同時標示）
        var showH = r.actual_hours!=null ? r.actual_hours : r.hours, diffH = r.actual_hours!=null && r.hours!=null
            && Math.abs(parseFloat(r.actual_hours)-parseFloat(r.hours))>0.05;
        html += '<td'+(diffH?' title="計畫時數 '+numTrim(r.hours)+'，實際 '+numTrim(r.actual_hours)+'"':'')+'>'
             +  (showH==null?'—':numTrim(showH))+(diffH?' <span style="color:#DD5138;">*</span>':'')+'</td>';
        html += '<td>'+(r.target_headcount==null?'—':r.target_headcount)+'</td>';
        html += '<td>'+(r.actual_headcount==null?'—':r.actual_headcount)+'</td>';
        html += '<td>'+statPill(r.status)+'</td>';
        html += '<td>'+dateRangeText(r)+'</td>';
        html += '<td style="white-space:nowrap;">';
        if (PERMS.canEdit) {
            html += '<span class="tr-op" onclick="openEd('+r.session_id+')" title="修改計畫內容"><i class="fa fa-pencil"></i>計畫</span>';
            if (r.status==='cancelled') {
                html += '<span class="tr-op" onclick="setStatus('+r.session_id+',\'planned\')" title="恢復為計畫中"><i class="fa fa-undo"></i>恢復</span>';
            } else if (r.status==='done') {
                html += '<span class="tr-op" onclick="openEx('+r.session_id+')" title="修改實行紀錄"><i class="fa fa-check-square-o"></i>實行紀錄</span>';
            } else if (r.status==='scheduled') {
                html += '<span class="tr-op" onclick="openEx('+r.session_id+')" title="修改開課資料/名單、印簽到表、登錄完成"><i class="fa fa-calendar-check-o"></i>實行資料</span>';
            } else {
                html += '<span class="tr-op" onclick="openEx('+r.session_id+')" title="確定開課：登錄開課日期/地點/參加人員"><i class="fa fa-check-square-o"></i>確認實行</span>';
                html += '<span class="tr-op" onclick="setStatus('+r.session_id+',\'cancelled\')" title="取消此計畫"><i class="fa fa-ban"></i>取消</span>';
            }
            html += '<span class="tr-op" onclick="copySession('+r.session_id+')" title="複製內容(不帶名單)"><i class="fa fa-copy"></i>複製</span>';
        }
        if (PERMS.canAdmin) html += '<span class="tr-op" style="color:#DD5138;" onclick="delSession('+r.session_id+')" title="刪除場次"><i class="fa fa-trash"></i></span>';
        if (!PERMS.canEdit && !PERMS.canAdmin) html += '—';
        html += '</td></tr>';
    });
    $('#trBody').html(html || '<tr><td colspan="11" style="padding:16px;color:#8a6d45;">無符合條件的訓練場次</td></tr>');
}

$('#deptSel,#statSel').on('change', renderTable);
$('#kwSel').on('input', renderTable);
$('#yearSel').on('change', loadList);

/* ---------- 新增/編輯 ---------- */
var ATT = [];   // 應參加名單 [{user_id,user_name,dept_name,attended,signed}]
function applyType(){
    var ext = $('#edType').val()==='external';
    $('#edExternalBox').toggle(ext);
    $('#edInternalBox').toggle(!ext);
}
$('#edType').on('change', applyType);
/* 計畫 modal：只有計畫內容（年月/部門/課程/類型/講師或單位/時數/備註） */
function openEd(sid){
    var r = sid ? ROWS.find(function(x){ return String(x.session_id)===String(sid); }) : null;
    $('#edTitle').text(r ? '編輯訓練計畫' : '新增訓練計畫');
    $('#edMask').data('sid', r ? r.session_id : 0);
    $('#edYear').val(r ? r.year : $('#yearSel').val());
    $('#edMonth').val(r ? r.plan_month : (META.cur_month));
    $('#edDept').val(r && r.dept_id!=null ? r.dept_id : '');
    $('#edCourse').val(r ? r.course_name : '');
    $('#edType').val(r ? (r.train_type||'internal') : 'internal'); applyType();
    $('#edTrainer').val(r ? (r.trainer||'') : ''); $('#edTrainerDept').val(''); $('#edTrainerPerson').html('<option value="">人員</option>');
    $('#edOrgUnit').val(r ? (r.org_unit||'') : '');
    $('#edHours').val(r && r.hours!=null ? numTrim(r.hours) : '');
    $('#edDays').val(r && r.plan_days!=null ? r.plan_days : '');
    $('#edNote').val(r ? (r.note||'') : '');
    edValidate();
    openMask('edMask');
    setTimeout(function(){ $('#edCourse').focus(); }, 100);
}
$('#btnAdd').on('click', function(){ openEd(0); });
/* 計畫欄位即時檢查（天數/時數） */
function edValidate(){
    var ok = true, dv = $.trim($('#edDays').val()), hv = $.trim($('#edHours').val());
    var d = dv==='' ? null : parseInt(dv,10), h = hv==='' ? null : parseFloat(hv);
    ok = setErr($('#edDays'), 'errEdDays',
        dv!=='' && (isNaN(d) || d<1) ? '天數須為 1 以上的整數' :
        (d!=null && d>60 ? '天數上限 60 天' : '')) && ok;
    ok = setErr($('#edHours'), 'errEdHours',
        hv!=='' && (isNaN(h) || h<0) ? '時數不可為負數' :
        (h!=null && h>500 ? '時數 '+numTrim(h)+' 不合理（上限 500）' : '')) && ok;
    $('#edDayHint').html(d && d>1 && h
        ? '多天課程：平均每天約 '+numTrim(Math.round(h/d*10)/10)+' 小時' : (d&&d>1 ? '多天課程' : ''));
    return ok;
}
$('#edDays,#edHours').on('input change', edValidate);

/* 確認實行 modal：開課日期/時段/地點/實際時數＋參加人員
   語意：確認實行＝「確定要開這堂課」（狀態轉已排定，可先印簽到表）；
        上完課後回到同一畫面勾實到、按「登錄完成」才轉已完成（計入 KPI）。 */
var EXROW = null;
function openEx(sid){
    var r = ROWS.find(function(x){ return String(x.session_id)===String(sid); });
    if (!r) return;
    EXROW = r;
    var done = r.status==='done', sch = r.status==='scheduled';
    $('#exTitle').text(done ? '實行紀錄（已完成）' : (sch ? '實行資料（已排定開課）' : '確認實行（確定開課）'));
    $('#exSave').text(done ? '儲存實行紀錄' : (sch ? '儲存' : '確認開課'));
    $('#exFinish').toggle(!done);
    $('#exRevert').toggle(done || sch);
    $('#exMask').data('sid', r.session_id);
    var ext = r.train_type==='external';
    $('#exPlanInfo').html(
        '<div><b>'+esc(r.course_name)+'</b> '+statPill(r.status)+'</div>'
      + '<div>計畫：'+r.year+' 年 '+r.plan_month+' 月　對象部門：'+esc(r.dept_name||'全公司')
      + '　類型：'+(ext?'外訓':'內訓')+'　'+(ext?'開課單位':'講師')+'：'+esc((ext?r.org_unit:r.trainer)||'—')
      + '　計畫時數：'+(r.hours==null?'—':numTrim(r.hours))+'</div>');
    // 上課日期明細：已登錄過就沿用；沒有就依「首日＋計畫天數」自動排連續日期
    var first = fmtDate(r.done_date) || META.today || '';
    // 班別：沿用本場已選的，否則用模組設定的預設班別
    var shiftId = r.shift_type_id || SETTINGS.training_default_shift_id || '';
    $('#exShift').val(shiftId || '');
    var sh = shiftById(shiftId);
    DAYS = (r.days||[]).map(function(d){
        return {date:fmtDate(d.day_date), start:d.start_time||'', end:d.end_time||'',
                brk:d.break_minutes==null?0:+d.break_minutes, hours:d.hours==null?'':numTrim(d.hours)};
    });
    if (!DAYS.length){
        var n = Math.max(1, parseInt(r.plan_days||1, 10) || 1);
        var st0 = r.start_time || (sh?sh.start_time:''), en0 = r.end_time || (sh?sh.end_time:'');
        for (var i=0;i<n;i++){
            var d0 = {date:first?addDaysStr(first,i):'', start:st0, end:en0, brk:0, hours:''};
            dayRecalc(d0);
            if (d0.hours==='' && r.hours!=null) d0.hours = numTrim(Math.round(parseFloat(r.hours)/n*10)/10);
            DAYS.push(d0);
        }
    } else {
        DAYS.forEach(function(d){ dayRecalc(d, 1); });   // 舊資料的休息一併依現行休息時段重算（時數保留）
    }
    $('#exDone').val(DAYS[0].date || first);
    $('#exDays').val(DAYS.length);
    $('#exBStart').val(DAYS[0].start||''); $('#exBEnd').val(DAYS[0].end||'');
    $('#exBBreak').val(DAYS[0].brk==null?0:DAYS[0].brk);
    setErr($('#exBStart'), null, ''); setErr($('#exBEnd'), null, ''); setErr($('#exBBreak'), 'errBatch', '');
    setLocSel(r.location||'');
    renderDays();
    $('#attDept').val(''); $('#attPeopleBox').html('<span class="empty">選部門載入人員</span>');
    $('#attPickAll').prop('checked', false);
    ATT = [];
    $('#attNote').text('');
    $.getJSON(API, {action:'get_attendees', session_id:r.session_id}, function(res){
        if (res.ok) ATT = res.attendees.map(function(a){ return {user_id:+a.user_id, user_name:a.user_name, dept_name:a.dept_name,
            position_name:a.position_name||'', attended:+a.attended, signed:+a.signed}; });
        // 講師不算參加人員（是上課的人，不是受訓的人）→ 名單內若有講師一律剔除
        var cut = [];
        ATT = ATT.filter(function(a){ if (isTrainer(a.user_id, a.user_name)){ cut.push(a.user_name); return false; } return true; });
        if (cut.length) $('#attNote').text('（已自動剔除講師：'+cut.join('、')+'，存檔後生效）');
        renderAtt();
    });
    renderAtt();
    openMask('exMask');
    setTimeout(function(){ $('#exDone').focus(); }, 100);
}
/* ---------- 多天課程：上課日期明細（每天一列，可全部套用後個別修改） ---------- */
var DAYS = [];   // [{date,start,end,brk,hours}]

/* ---------- 休息時間：不給手動改，一律由「上課時間 ∩ 休息時段」自動算 ----------
   起因：使用者把某天改成 11:00~12:00，但休息還停在班別帶進來的 60 分，
   變成「上課 60 分不足以扣休息 60 分」，時數也停在改之前的舊值。
   休息本來就該看課有沒有跨到午休，所以改成系統算、欄位灰底唯讀。 */
var BRK = {start:'', end:''};
function applyBreakSetting(){
    var s = SETTINGS.training_break_start || '', e = SETTINGS.training_break_end || '';
    var ok = timeToMin(s)!=null && timeToMin(e)!=null && timeToMin(e)>timeToMin(s);
    BRK = ok ? {start:s, end:e} : {start:'', end:''};
    $('#brkHint').html(ok
        ? '休息時間由系統自動計算（灰底不可修改）：上課時間與休息時段 <b>'+esc(s)+'~'+esc(e)+'</b>（'
          +(timeToMin(e)-timeToMin(s))+' 分）重疊幾分鐘就扣幾分鐘，沒跨到就不扣。休息時段可於「模組設定」修改。'
        : '目前未設定休息時段 → 一律不扣休息時間（可於「模組設定」設定）。');
}
/* 這段上課時間應扣的休息分鐘（與休息時段的重疊分鐘數） */
function autoBreakMin(s, e){
    var s1=timeToMin(s), e1=timeToMin(e), b1=timeToMin(BRK.start), b2=timeToMin(BRK.end);
    if (s1==null||e1==null||e1<=s1||b1==null||b2==null||b2<=b1) return 0;
    var ov = Math.min(e1,b2) - Math.max(s1,b1);
    return ov>0 ? ov : 0;
}
/* 重算某一天的休息與時數；keepHours=1 時保留使用者手填的時數（只重算休息） */
function dayRecalc(d, keepHours){
    d.brk = autoBreakMin(d.start, d.end);
    if (keepHours && d.hours!=='' && d.hours!=null) return;
    var hh = calcDayHours(d);
    d.hours = (hh==null ? '' : hh);      // 起訖無效就清空，不可留舊值（舊版停在改時間前的數字）
}
/* 當日時數＝(結束−開始)−休息 */
function calcDayHours(d){
    var s=timeToMin(d.start), e=timeToMin(d.end);
    if (s==null || e==null || e<=s) return null;
    var brk = parseInt(d.brk,10); if (isNaN(brk)||brk<0) brk=0;
    var mins = (e-s) - brk;
    if (mins <= 0) return null;
    return numTrim(Math.round(mins/60*10)/10);
}
function renderDays(){
    var h='';
    DAYS.forEach(function(d,i){
        h+='<tr>'
          +'<td>第'+(i+1)+'天</td>'
          +'<td><input type="date" max="9999-12-31" value="'+esc(d.date)+'" onchange="dayEdit('+i+',\'date\',this.value)"></td>'
          +'<td><input type="text" class="time-in" maxlength="5" placeholder="09:00" value="'+esc(d.start)+'" oninput="dayEdit('+i+',\'start\',this.value,1)" onchange="dayEdit('+i+',\'start\',this.value)"></td>'
          +'<td><input type="text" class="time-in" maxlength="5" placeholder="17:00" value="'+esc(d.end)+'" oninput="dayEdit('+i+',\'end\',this.value,1)" onchange="dayEdit('+i+',\'end\',this.value)"></td>'
          +'<td><input type="number" class="ro-auto" readonly tabindex="-1" data-eg-skip value="'+esc(d.brk==null?0:d.brk)+'"'
          +' title="休息由系統依休息時段自動計算，不可手動修改"></td>'
          +'<td><input type="number" step="any" min="0" value="'+esc(d.hours)+'" onchange="dayEdit('+i+',\'hours\',this.value)"></td>'
          +'<td class="chk" id="dayChk'+i+'"></td>'
          +'<td>'+(DAYS.length>1?'<span class="att-del" onclick="dayDel('+i+')"><i class="fa fa-times"></i></span>':'')+'</td>'
          +'</tr>';
    });
    $('#dayBody').html(h);
    $('#exDays').val(DAYS.length);
    if (DAYS[0] && DAYS[0].date) $('#exDone').val(DAYS[0].date);   // 首日欄與明細第一天一致
    dayValidate();
}
/* 單格編輯：typing=1 只即時檢查不改寫內容（不打斷輸入），change 才正規化成 HH:MM */
function dayEdit(i, key, val, typing){
    if (!DAYS[i]) return;
    if (key==='start' || key==='end'){
        var p = parseTime(val);
        DAYS[i][key] = p.ok ? p.val : val;
        if (!typing){                     // 離開欄位才正規化成 HH:MM，並重算休息與時數
            dayRecalc(DAYS[i]);
            renderDays(); return;
        }
    } else DAYS[i][key] = val;
    dayValidate();
}
function dayDel(i){ if (DAYS.length<=1) return; DAYS.splice(i,1); renderDays(); }
/* 供共用檔 eg_input_rules.js 規則6 呼叫：末列 ↓ 加一天、沒填東西的末列 ↑ 移除 */
function dayAdd(){
    var last = DAYS[DAYS.length-1] || {date:$('#exDone').val()||META.today, start:'', end:'', brk:0, hours:''};
    var d = {date:last.date?addDaysStr(last.date,1):'', start:last.start, end:last.end, brk:0, hours:''};
    dayRecalc(d);
    DAYS.push(d);
    renderDays();
}
function dayDelLast(){ if (DAYS.length<=1) return; DAYS.pop(); renderDays(); }
/* 依「開課首日＋上課天數」重建連續日期（時間沿用批次設定或第一天） */
function dayRebuild(){
    var first = $('#exDone').val();
    if (!validDateStr(first)){ setErr($('#exDone'),'errExDone','請先選擇正確的開課首日'); return; }
    var n = parseInt($('#exDays').val(),10);
    if (isNaN(n) || n<1){ setErr($('#exDays'),'errExDays','天數須為 1 以上的整數'); return; }
    if (n>60){ setErr($('#exDays'),'errExDays','天數上限 60 天'); return; }
    setErr($('#exDone'),'errExDone',''); setErr($('#exDays'),'errExDays','');
    var bs=parseTime($('#exBStart').val()), be=parseTime($('#exBEnd').val());
    var s = bs.ok?bs.val:(DAYS[0]?DAYS[0].start:''), e = be.ok?be.val:(DAYS[0]?DAYS[0].end:'');
    var old = DAYS.slice();
    DAYS = [];
    for (var i=0;i<n;i++){
        var d = {date:addDaysStr(first,i), start:s||(old[i]?old[i].start:''), end:e||(old[i]?old[i].end:''), brk:0, hours:''};
        dayRecalc(d);
        if (d.hours==='' && old[i]) d.hours = old[i].hours;
        DAYS.push(d);
    }
    renderDays();
}
/* 時間與休息套用到全部日期（不動日期） */
function dayApplyAll(){
    var bs=parseTime($('#exBStart').val()), be=parseTime($('#exBEnd').val());
    if (!bs.ok){ setErr($('#exBStart'),'errBatch','開始時間：'+bs.msg); return; }
    if (!be.ok){ setErr($('#exBEnd'),'errBatch','結束時間：'+be.msg); return; }
    if (bs.val && be.val && timeToMin(be.val)<=timeToMin(bs.val)){
        setErr($('#exBEnd'),'errBatch','結束時間（'+be.val+'）不可早於或等於開始時間（'+bs.val+'）'); return; }
    var brk = autoBreakMin(bs.val, be.val);          // 休息＝與休息時段的重疊，不讀輸入框
    if (bs.val && be.val && brk >= (timeToMin(be.val)-timeToMin(bs.val))){
        setErr($('#exBBreak'),'errBatch','上課時間 '+bs.val+'~'+be.val+' 全部落在休息時段（'+BRK.start+'~'+BRK.end+'），扣掉休息後沒有時數'); return; }
    setErr($('#exBStart'),null,''); setErr($('#exBEnd'),null,''); setErr($('#exBBreak'),'errBatch','');
    $('#exBStart').val(bs.val); $('#exBEnd').val(be.val); $('#exBBreak').val(brk);
    DAYS.forEach(function(d){ d.start=bs.val; d.end=be.val; dayRecalc(d); });
    renderDays();
}
/* 即時驗證每一天：日期存在/不重複、時間合法(擋 25:00)、同日結束不可早於開始、時數合理 */
function dayValidate(){
    var seen={}, bad=0, total=0, hasH=false, firstMsg='', firstIdx=-1;
    DAYS.forEach(function(d,i){
        var msg='', $tr=$('#dayBody tr').eq(i);
        var $din=$tr.find('input').eq(0), $sin=$tr.find('input').eq(1), $ein=$tr.find('input').eq(2),
            $bin=$tr.find('input').eq(3), $hin=$tr.find('input').eq(4);
        $din.removeClass('inv'); $sin.removeClass('inv'); $ein.removeClass('inv'); $bin.removeClass('inv'); $hin.removeClass('inv');
        if (!d.date){ msg='請填上課日期'; $din.addClass('inv'); }
        else if (!validDateStr(d.date)){ msg='日期不存在或格式錯誤'; $din.addClass('inv'); }
        else if (seen[d.date]){ msg='日期與第'+seen[d.date]+'天重複'; $din.addClass('inv'); }
        else seen[d.date]=i+1;
        var ps=parseTime(d.start), pe=parseTime(d.end);
        if (!msg && !ps.ok){ msg='開始時間：'+ps.msg; $sin.addClass('inv'); }
        if (!msg && !pe.ok){ msg='結束時間：'+pe.msg; $ein.addClass('inv'); }
        if (!msg && ps.ok && pe.ok && ps.val && pe.val && timeToMin(pe.val)<=timeToMin(ps.val)){
            msg='結束 '+pe.val+' 不可早於或等於開始 '+ps.val; $ein.addClass('inv'); }
        var bv = (d.brk===''||d.brk==null) ? 0 : parseInt(d.brk,10);
        if (isNaN(bv)||bv<0) bv=0;                       // 休息由系統算，不會有負數/超界，保險歸零
        if (!msg && ps.val && pe.val && bv >= (timeToMin(pe.val)-timeToMin(ps.val))){
            msg='上課時間 '+ps.val+'~'+pe.val+' 全部落在休息時段（'+BRK.start+'~'+BRK.end+'），扣掉休息後沒有時數';
            $sin.addClass('inv'); $ein.addClass('inv'); }
        var hv = d.hours===''||d.hours==null ? null : parseFloat(d.hours);
        if (!msg && hv!=null && (isNaN(hv)||hv<0)){ msg='時數不可為負數'; $hin.addClass('inv'); }
        if (!msg && hv!=null && hv>24){ msg='當日時數 '+numTrim(hv)+' 超過 24 小時'; $hin.addClass('inv'); }
        if (hv!=null && !isNaN(hv)){ total+=hv; hasH=true; }
        if (msg){ bad++; if(firstIdx<0){ firstMsg='第'+(i+1)+'天：'+msg; firstIdx=i; } }
        $('#dayChk'+i).text(msg||'').toggleClass('ok', !msg);
        if (!msg){
            var auto = calcDayHours(d);       // 手動覆寫時數要講清楚，不然看起來像算錯
            var over = (hv!=null && !isNaN(hv) && auto!=null && Math.abs(hv-parseFloat(auto))>0.05)
                     ? '（時數已手動覆寫，自動計算為 '+auto+' 小時）' : '';
            $('#dayChk'+i).text(d.start&&d.end
                ? '✓ '+d.start+'~'+d.end+(bv>0?'（扣休息 '+bv+' 分）':'（未跨休息時段，不扣休息）')+over : '✓'+over);
        }
    });
    // 首日欄與明細第一天連動顯示
    var pl = EXROW && EXROW.hours!=null ? parseFloat(EXROW.hours) : null;
    $('#exTotalHint').html('實際總時數：<b style="color:#8A5A2B;">'+(hasH?numTrim(Math.round(total*10)/10):'—')+'</b> 小時'
        + (DAYS.length>1 ? '（'+DAYS.length+' 天合計）' : ''));
    $('#exHourHint').html(pl!=null && hasH && Math.abs(pl-total)>0.05
        ? '<span style="color:#DD5138;">與計畫總時數 '+numTrim(pl)+' 不同</span>' : (pl!=null?'計畫總時數 '+numTrim(pl):''));
    DAY_ERR = firstMsg;
    return bad===0;
}
var DAY_ERR = '';
$('#exDone').on('change', function(){
    var v=$(this).val();
    if (v && !validDateStr(v)){ setErr($(this),'errExDone','日期不存在或格式錯誤'); return; }
    setErr($(this),'errExDone','');
    if (DAYS.length && v){                    // 首日改變 → 整段日期一起平移（保留各天時間）
        var diff = DAYS[0].date ? Math.round((new Date(v) - new Date(DAYS[0].date))/86400000) : null;
        if (diff===null || isNaN(diff)) DAYS[0].date=v;
        else DAYS.forEach(function(d){ if(d.date) d.date=addDaysStr(d.date,diff); });
        renderDays();
    }
});
$('#exDays').on('change', function(){
    var n=parseInt($(this).val(),10);
    if (isNaN(n)||n<1) return setErr($(this),'errExDays','天數須為 1 以上的整數');
    if (n>60) return setErr($(this),'errExDays','天數上限 60 天');
    setErr($(this),'errExDays','');
    if (n===DAYS.length) return;
    if (n<DAYS.length) DAYS = DAYS.slice(0,n);
    else while (DAYS.length<n) dayAddSilent();
    renderDays();
});
function dayAddSilent(){
    var last = DAYS[DAYS.length-1] || {date:$('#exDone').val(), start:'', end:'', brk:0, hours:''};
    var d = {date:last.date?addDaysStr(last.date,1):'', start:last.start, end:last.end, brk:0, hours:''};
    dayRecalc(d);
    DAYS.push(d);
}
/* 批次時間欄即時檢查 */
$('#exBStart,#exBEnd').on('input', function(){
    var p=parseTime($(this).val());
    setErr($(this), 'errBatch', p.ok?'':(this.id==='exBStart'?'開始時間：':'結束時間：')+p.msg);
});
/* 講師：部門→人員 */
$('#edTrainerDept').on('change', function(){
    var did=$(this).val(); var $p=$('#edTrainerPerson').html('<option value="">人員</option>');
    if(did) $.getJSON(API,{action:'people',dept_id:did},function(res){ if(res.ok) res.people.forEach(function(u){ $p.append('<option value="'+u.id+'">'+esc(u.user_cname)+'</option>'); }); });
});
$('#edTrainerPerson').on('change', function(){ var t=$(this).find('option:selected').text(); if($(this).val()) $('#edTrainer').val(t); });
/* 參加人員：講師是「上課的人」不是「受訓的人」，一律不列入名單（外訓沒有內部講師故不判斷）。
   trainer_id 是從部門→人員選出來的才有；手打講師姓名時退而以姓名比對。 */
function isTrainer(uid, uname){
    if (!EXROW || EXROW.train_type==='external') return false;
    if (EXROW.trainer_id && +EXROW.trainer_id === +uid) return true;
    var t = $.trim(EXROW.trainer||'');
    return t!=='' && t === $.trim(uname||'');
}
$('#attDept').on('change', function(){
    var did=$(this).val(); var $b=$('#attPeopleBox');
    if(!did){ $b.html('<span class="empty">選部門載入人員</span>'); return; }
    $b.html('<span class="empty">載入中…</span>');
    $.getJSON(API,{action:'people',dept_id:did},function(res){
        if(!res.ok){ $b.html('<span class="empty">載入失敗</span>'); return; }
        var deptName=$('#attDept option:selected').text();
        var h=''; res.people.forEach(function(u){
            var pos=u.position_name||'';
            if (isTrainer(u.id, u.user_cname)){          // 講師：顯示但不可勾（讓人看得出來是刻意排除的）
                h+='<label style="color:#b0a390;" title="本場講師，不列入參加人員"><input type="checkbox" disabled> '
                  +esc(u.user_cname)+'（講師，不列入）</label>';
                return;
            }
            var inList=ATT.some(function(a){return a.user_id===+u.id;});
            h+='<label><input type="checkbox" class="att-ck" value="'+u.id+'" data-name="'+esc(u.user_cname)+'" data-dept="'+esc(deptName)+'" data-pos="'+esc(pos)+'"'+(inList?' checked disabled':'')+'> '
              +esc(u.user_cname)+(pos?'<span style="color:#8a6d45;">（'+esc(pos)+'）</span>':'')+(inList?'(已加)':'')+'</label>';
        });
        $b.html(h||'<span class="empty">此部門無人員</span>');
        $('#attPickAll').prop('checked',false);
    });
});
$('#attPickAll').on('change', function(){ $('#attPeopleBox .att-ck:not(:disabled)').prop('checked', this.checked); });
function attAddChecked(){
    $('#attPeopleBox .att-ck:checked:not(:disabled)').each(function(){
        var id=+$(this).val();
        if (isTrainer(id, $(this).data('name'))) return;      // 保險：講師不進名單
        if(!ATT.some(function(a){return a.user_id===id;}))
            ATT.push({user_id:id, user_name:$(this).data('name'), dept_name:$(this).data('dept'),
                      position_name:$(this).data('pos')||'', attended:0, signed:0});
    });
    renderAtt();
    $('#attDept').trigger('change');
}
function renderAtt(){
    var h='';
    ATT.forEach(function(a,i){
        h+='<tr><td class="t-left">'+esc(a.user_name||'')+'</td><td>'+esc(a.dept_name||'')+'</td>'
          +'<td>'+esc(a.position_name||'—')+'</td>'
          +'<td><input type="checkbox" '+(a.attended?'checked':'')+' onchange="ATT['+i+'].attended=this.checked?1:0;attCount()"></td>'
          +'<td>'+(a.signed?'<span style="color:#8A5A2B;">已簽</span>':'—')+'</td>'
          +'<td><span class="att-del" onclick="attDel('+i+')"><i class="fa fa-times"></i></span></td></tr>';
    });
    $('#attBody').html(h||'<tr><td colspan="6" style="color:#8a6d45;padding:8px;">尚未加入人員</td></tr>');
    attCount();
}
function attCount(){ var a=ATT.filter(function(x){return x.attended;}).length; $('#attCount').text('（應到 '+ATT.length+'　實到 '+a+'）'); }
function attDel(i){ ATT.splice(i,1); renderAtt(); if($('#attDept').val()) $('#attDept').trigger('change'); }

/* 儲存計畫（不動實行欄位） */
function submitEd(){
    if (!$.trim($('#edCourse').val())){
        setErr($('#edCourse'), null, 'x'); alert('請填課程名稱'); $('#edCourse').focus(); return; }
    setErr($('#edCourse'), null, '');
    if ($('#edType').val()==='external' && !$.trim($('#edOrgUnit').val())){
        setErr($('#edOrgUnit'), null, 'x'); alert('外訓請填開課單位'); $('#edOrgUnit').focus(); return; }
    setErr($('#edOrgUnit'), null, '');
    if (!edValidate()){ alert('欄位有錯誤：'+($('#errEdDays').text()||$('#errEdHours').text())); return; }
    $.post(API, {action:'save_session', session_id:$('#edMask').data('sid'),
        year:$('#edYear').val(), plan_month:$('#edMonth').val(), dept_id:$('#edDept').val(),
        course_name:$('#edCourse').val(), train_type:$('#edType').val(),
        trainer:$('#edTrainer').val(), trainer_id:$('#edTrainerPerson').val(), org_unit:$('#edOrgUnit').val(),
        hours:$('#edHours').val(), plan_days:$('#edDays').val(), note:$('#edNote').val()},
    function(res){
        if (!res.ok){ alert(res.error||'儲存失敗'); return; }
        closeMask('edMask'); loadList();
    }, 'json').fail(function(x){ alert('儲存失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}

/* 確認實行：寫開課日期/時段/地點/實際時數＋參加名單
   markDone=0 → 已排定（確定開課，可印簽到表）；markDone=1 → 已完成（計入 KPI） */
function submitEx(markDone){
    var sid = $('#exMask').data('sid');
    if (!DAYS.length){ alert('請至少設定一天上課日期'); return; }
    if (!dayValidate()){ alert('上課日期有錯誤，請先修正：\n'+DAY_ERR); return; }
    if (!ATT.length && !confirm('尚未加入任何參加人員，仍要儲存？')) return;
    if (markDone && !confirm('確定此場訓練已上完課？登錄完成後將計入當月教育訓練達成率。')) return;
    $.post(API, {action:'save_execution', session_id:sid, location:$('#exLocSel').val(),
        shift_type_id:$('#exShift').val(),
        days:JSON.stringify(DAYS.map(function(d){
            return {day_date:d.date, start_time:d.start, end_time:d.end,
                    break_minutes:(d.brk===''||d.brk==null)?0:d.brk, hours:d.hours}; })),
        mark_done:markDone?1:0},
    function(res){
        if (!res.ok){ alert(res.error||'儲存失敗'); return; }
        var evMsg = res.events>0 ? '已同步 '+res.events+' 筆行事曆事件。'
                                 : '（未寫入行事曆：請於「模組設定」綁定行事曆類別）';
        $.post(API, {action:'save_attendees', session_id:sid, attendees:JSON.stringify(ATT)}, function(r2){
            if (!r2.ok){ alert('實行紀錄已存，但名單儲存失敗：'+(r2.error||'')); }
            closeMask('exMask'); loadList(); alert('已儲存。'+evMsg);
        }, 'json').fail(function(){ closeMask('exMask'); loadList(); });
    }, 'json').fail(function(x){ alert('儲存失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}
/* ---------- 固定班別（與輪值排班共用 shift_type）＋模組設定 ---------- */
var SHIFTS = [], SETTINGS = {}, CATS = [], CAT_EFF = {internal:null, external:null};
/* 班別只負責帶入上下班時間；休息一律由「休息時段」重疊算（班別的 break_minutes 不直接套用） */
function shiftLabel(s){
    return s.shift_name+'（'+s.start_time+'~'+s.end_time+'）';
}
function renderShiftSel(){
    var h = '<option value="">（不套用班別）</option>';
    SHIFTS.forEach(function(s){ h += '<option value="'+s.shift_type_id+'">'+esc(shiftLabel(s))+'</option>'; });
    $('#exShift').html(h);
    $('#setShift').html(h);
    if (SETTINGS.training_default_shift_id) $('#setShift').val(SETTINGS.training_default_shift_id);
}
function shiftById(id){
    for (var i=0;i<SHIFTS.length;i++) if (String(SHIFTS[i].shift_type_id)===String(id)) return SHIFTS[i];
    return null;
}
/* 選班別 → 帶入批次上下班時間（只是參考，不限制上課一定要在此區間內）；休息由 dayApplyAll 自動算 */
$('#exShift').on('change', function(){
    var s = shiftById($(this).val());
    if (!s) return;
    $('#exBStart').val(s.start_time); $('#exBEnd').val(s.end_time);
    setErr($('#exBStart'),null,''); setErr($('#exBEnd'),'errBatch','');
    dayApplyAll();
});
/* 模組設定（預設班別、行事曆類別綁定） */
function openSetting(){
    var h='<option value="">（自動：以名稱尋找）</option>';
    CATS.forEach(function(c){ h += '<option value="'+c.id+'">'+esc(c.category_name)+'</option>'; });
    $('#setCatIn').html(h.replace('以名稱尋找','以名稱「課程(內訓)」尋找')).val(SETTINGS.training_cat_internal||'');
    $('#setCatEx').html(h.replace('以名稱尋找','以名稱「課程(外訓)」尋找')).val(SETTINGS.training_cat_external||'');
    $('#setShift').val(SETTINGS.training_default_shift_id||'');
    $('#setBrkStart').val(SETTINGS.training_break_start||'');
    $('#setBrkEnd').val(SETTINGS.training_break_end||'');
    setBrkCheck();
    var nm=function(id){ var f=CATS.filter(function(c){ return String(c.id)===String(id); }); return f.length?f[0].category_name:'（找不到）'; };
    $('#setCatEff').html('目前實際使用：內訓＝'+(CAT_EFF.internal?esc(nm(CAT_EFF.internal))+'（id '+CAT_EFF.internal+'）':'未設定→不寫行事曆')
        +'　外訓＝'+(CAT_EFF.external?esc(nm(CAT_EFF.external))+'（id '+CAT_EFF.external+'）':'未設定→不寫行事曆'));
    openMask('setMask');
}
$('#btnSetting').on('click', openSetting);
/* 休息時段即時檢查＋與所選班別休息分鐘的對照（不一致只是提醒，不擋存檔） */
function setBrkCheck(){
    var ps=parseTime($('#setBrkStart').val()), pe=parseTime($('#setBrkEnd').val());
    var msg='';
    if (!ps.ok) msg='開始：'+ps.msg;
    else if (!pe.ok) msg='結束：'+pe.msg;
    else if ((ps.val==='') !== (pe.val==='')) msg='請「起、迄」兩個都填，或兩個都留空（＝不扣休息）';
    else if (ps.val && pe.val && timeToMin(pe.val)<=timeToMin(ps.val))
        msg='休息結束（'+pe.val+'）不可早於或等於休息開始（'+ps.val+'）';
    setErr($('#setBrkStart'), null, msg && !ps.ok ? 'x' : '');
    setErr($('#setBrkEnd'), 'errSetBrk', msg);
    var len = (!msg && ps.val && pe.val) ? (timeToMin(pe.val)-timeToMin(ps.val)) : null;
    $('#setBrkLen').text(len==null ? (msg?'':'（未設定＝不扣休息）') : '共 '+len+' 分鐘');
    var sh = shiftById($('#setShift').val());
    $('#setBrkVsShift').text(!sh || len==null ? ''
        : (len === +sh.break_minutes
            ? '　目前預設班別「'+sh.shift_name+'」的休息 '+(+sh.break_minutes)+' 分與此時段長度一致 ✓'
            : '　注意：預設班別「'+sh.shift_name+'」休息為 '+(+sh.break_minutes)+' 分，與此時段長度 '+len+' 分不同，實際扣除以此時段為準'));
    return !msg;
}
$('#setBrkStart,#setBrkEnd').on('input change', setBrkCheck);
$('#setShift').on('change', setBrkCheck);
function saveSettings(){
    if (!setBrkCheck()){ alert('休息時段有錯誤：'+$('#errSetBrk').text()); return; }
    var bs=parseTime($('#setBrkStart').val()), be=parseTime($('#setBrkEnd').val());
    $.post(API, {action:'save_settings', default_shift_id:$('#setShift').val(),
        cat_internal:$('#setCatIn').val(), cat_external:$('#setCatEx').val(),
        break_start:bs.val, break_end:be.val}, function(res){
        if (!res.ok){ alert(res.error||'設定儲存失敗'); return; }
        SETTINGS = res.settings||{};
        CAT_EFF = {internal:res.cat_internal_eff||null, external:res.cat_external_eff||null};
        renderShiftSel(); applyBreakSetting(); closeMask('setMask');
        if (DAYS.length){ DAYS.forEach(function(d){ dayRecalc(d); }); renderDays(); }   // 設定改了，開著的實行畫面同步重算
        alert('設定已儲存。日後行事曆類別改名不影響綁定（存的是類別 id）。');
    }, 'json').fail(function(x){ alert('設定儲存失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}

/* ---------- 上課地點主檔 ---------- */
var LOCS = [];
function renderLocSel(){
    var cur = $('#exLocSel').val();
    var h = '<option value="">（未指定）</option>';
    LOCS.forEach(function(l){ h += '<option value="'+esc(l.name)+'">'+esc(l.name)+'</option>'; });
    $('#exLocSel').html(h);
    if (cur) setLocSel(cur);
}
/* 舊紀錄的地點若不在主檔（或已停用）仍要選得到 */
function setLocSel(name){
    if (name && !$('#exLocSel').find('option').filter(function(){ return this.value===name; }).length)
        $('#exLocSel').append('<option value="'+esc(name)+'">'+esc(name)+'（舊紀錄）</option>');
    $('#exLocSel').val(name||'');
}
function openLocMgr(){ renderLocList(); setErr($('#locNew'),'errLocNew',''); $('#locNew').val(''); openMask('locMask');
    setTimeout(function(){ $('#locNew').focus(); }, 100); }
function renderLocList(){
    var h='';
    LOCS.forEach(function(l){
        h+='<tr><td class="t-left">'+esc(l.name)+'</td><td>'
          +(PERMS && PERMS.canAdmin ? '<span class="att-del" onclick="locDel('+l.loc_id+')" title="停用"><i class="fa fa-times"></i></span>' : '—')
          +'</td></tr>';
    });
    $('#locBody').html(h||'<tr><td colspan="2" style="color:#8a6d45;padding:8px;">尚未設定地點，於上方新增</td></tr>');
}
function locAdd(){
    var name=$.trim($('#locNew').val());
    if (!name) return setErr($('#locNew'),'errLocNew','請輸入地點名稱');
    if (name.length>100) return setErr($('#locNew'),'errLocNew','地點名稱過長（上限 100 字）');
    if (LOCS.some(function(l){ return l.name===name; })) return setErr($('#locNew'),'errLocNew','此地點已存在');
    setErr($('#locNew'),'errLocNew','');
    $.post(API, {action:'save_location', name:name}, function(res){
        if (!res.ok) return setErr($('#locNew'),'errLocNew', res.error||'新增失敗');
        LOCS = res.locations||[]; renderLocSel(); renderLocList();
        setLocSel(name); $('#locNew').val('').focus();
    }, 'json').fail(function(x){ setErr($('#locNew'),'errLocNew','新增失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}
$('#locNew').on('keydown', function(e){ if (e.which===13){ e.preventDefault(); locAdd(); } });
function locDel(locId){
    if (!confirm('停用此地點？（不影響已登錄的舊紀錄）')) return;
    $.post(API, {action:'del_location', loc_id:locId}, function(res){
        if (!res.ok){ alert(res.error||'停用失敗'); return; }
        LOCS = res.locations||[]; renderLocSel(); renderLocList();
    }, 'json').fail(function(x){ alert('停用失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}

/* 退回計畫中（實行 modal 內） */
function revertPlanned(){
    if (!confirm('退回為「計畫中」？開課日期將清空、已同步的行事曆事件會一併撤除（時段/地點/休息/名單保留），此場次不再計入當月完成數。')) return;
    setStatus($('#exMask').data('sid'), 'planned', true);
}
/* 狀態切換：取消計畫 / 恢復計畫 / 退回計畫中 */
function setStatus(sid, status, fromEx){
    if (!fromEx){
        var msg = status==='cancelled' ? '取消此訓練計畫？（取消的場次不計入 KPI 分母）' : '恢復為「計畫中」？';
        if (!confirm(msg)) return;
    }
    $.post(API, {action:'set_status', session_id:sid, status:status}, function(res){
        if (!res.ok){ alert(res.error||'狀態變更失敗'); return; }
        closeMask('exMask'); loadList();
    }, 'json').fail(function(x){ alert('狀態變更失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}
function copySession(sid){
    if (!confirm('複製此場次內容為新的一場（不含參加名單）？')) return;
    $.post(API, {action:'copy_session', session_id:sid}, function(res){
        if (!res.ok){ alert(res.error||'複製失敗'); return; }
        loadList(); alert('已複製為新場次，可再編輯調整並另建參加名單');
    }, 'json').fail(function(x){ alert('複製失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}
/* 列印簽到表（確認實行 modal 中的場次＋名單）
   多天課程＝一天一頁（每天各自簽名），單天＝一頁 */
function printSignSheet(){
    var r = EXROW || {};
    var course=r.course_name||'（課程名稱）';
    var ext=r.train_type==='external';
    var lect=ext?('外訓／開課單位：'+(r.org_unit||'')):('講師：'+(r.trainer||''));
    var loc=$('#exLocSel').val()||'____________';
    var list=(ATT.length?ATT:[{},{},{},{},{},{},{},{},{},{}]);
    var ds=(DAYS.length?DAYS:[{date:'', start:'', end:'', hours:''}]);
    var html='';
    ds.forEach(function(d, di){
        var tm=(d.start||'')+(d.end?'~'+d.end:'');
        var when='日期：'+(d.date||'____-__-__')+(tm?'  '+tm:'');
        var hh=d.hours||'';
        var where='地點：'+loc+'　時數：'+(hh||'__')+' 小時';
        var rows='';
        list.forEach(function(a,i){
            rows+='<tr><td>'+(i+1)+'</td><td>'+esc(a.user_name||'')+'</td><td>'+esc(a.dept_name||'')+'</td><td>'+esc(a.position_name||'')+'</td><td style="width:150px;"></td><td style="width:70px;"></td></tr>';
        });
        html+='<div class="pg">'
            +'<div style="text-align:center;"><div style="font-size:18px;font-weight:bold;">超正齒輪科技有限公司</div>'
            +'<div style="font-size:15px;margin-top:2px;">教育訓練簽到表</div></div>'
            +'<table class="sf-info"><tr><td colspan="2">課程名稱：'+esc(course)
            +(ds.length>1?'　（第 '+(di+1)+' / '+ds.length+' 天）':'')+'</td></tr>'
            +'<tr><td>'+esc(lect)+'</td><td>'+esc(where)+'</td></tr><tr><td colspan="2">'+esc(when)+'</td></tr></table>'
            +'<table class="sf"><thead><tr><th style="width:36px;">序</th><th>姓名</th><th>部門</th><th>職稱</th><th>簽名</th><th>時數確認</th></tr></thead><tbody>'+rows+'</tbody></table>'
            +'<div style="margin-top:14px;font-size:13px;">講師/主辦簽章：______________　　單位主管簽章：______________</div>'
            +'</div>';
    });
    var w=window.open('','_blank'); if(!w){alert('請允許彈出視窗');return;}
    var css='body{font-family:"Microsoft JhengHei","微軟正黑體",sans-serif;color:#000;padding:14px;}'
        +'table.sf{width:100%;border-collapse:collapse;font-size:13px;margin-top:8px;}table.sf th,table.sf td{border:1px solid #333;padding:6px;text-align:center;height:30px;}'
        +'table.sf-info{width:100%;border-collapse:collapse;font-size:13px;margin-top:10px;}table.sf-info td{border:1px solid #999;padding:5px 8px;text-align:left;}'
        +'.pg+.pg{page-break-before:always;}'
        +'@media print{@page{size:A4;margin:12mm;}}';
    w.document.write('<html><head><meta charset="utf-8"><title>教育訓練簽到表</title><style>'+css+'</style></head><body>'+html+'<scr'+'ipt>window.onload=function(){setTimeout(function(){window.print();},150);};</scr'+'ipt></body></html>');
    w.document.close();
}
function delSession(sid){
    if (!confirm('刪除此訓練場次？')) return;
    $.post(API, {action:'delete_session', session_id:sid}, function(res){
        if (!res.ok){ alert(res.error||'刪除失敗'); return; }
        loadList();
    }, 'json');
}

/* ---------- CSV ---------- */
$('#btnCsv').on('click', function(){
    var rows = [['年','月','對象部門','課程名稱','類型','講師/開課單位','計畫時數','實際時數','天數','應到','實到','狀態','開課日期','每日時段','休息(分)','地點','備註']];
    ROWS.forEach(function(r){
        var ext=r.train_type==='external';
        var ds=(r.days||[]);
        rows.push([r.year, r.plan_month, r.dept_name||'', r.course_name, ext?'外訓':'內訓', (ext?r.org_unit:r.trainer)||'',
            r.hours==null?'':numTrim(r.hours), r.actual_hours==null?'':numTrim(r.actual_hours),
            ds.length||(r.plan_days||''),
            r.target_headcount==null?'':r.target_headcount,
            r.actual_headcount==null?'':r.actual_headcount, STATUS_LABEL[r.status]||r.status,
            ds.length ? ds.map(function(d){ return fmtDate(d.day_date); }).join('、') : fmtDate(r.done_date),
            ds.length ? ds.map(function(d){ return (d.start_time||'')+(d.end_time?'~'+d.end_time:''); }).join('、')
                      : (r.start_time||'')+(r.end_time?'~'+r.end_time:''),
            ds.length ? ds.map(function(d){ return (d.break_minutes==null?0:d.break_minutes); }).join('、') : '',
            r.location||'', r.note||'']);
    });
    var csv = '﻿' + rows.map(function(l){
        return l.map(function(v){ return '"'+String(v==null?'':v).replace(/"/g,'""')+'"'; }).join(',');
    }).join('\r\n');
    var a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([csv], {type:'text/csv;charset=utf-8;'}));
    a.download = '教育訓練_'+$('#yearSel').val()+'.csv';
    a.click();
});

$('#btnRoleHelp').on('click', function(){ openMask('helpMask'); });
$('.tr-mask').on('click', function(e){ if (e.target===this) this.style.display='none'; });
/* 雙擊清空／聚焦全選／Enter 跳欄／表格 ↑↓ 與自動增刪列 → 一律由 eg_input_rules.js 處理，此處不再手刻 */

if (canView) loadMeta(function(){ loadList(); });
</script>
</body>
</html>
