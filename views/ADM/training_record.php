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
// 角色說明一律「當下查資料庫現況」組出，不寫死角色清單——管理員在角色設定改名/合併/刪除角色後，
// 這裡要立刻跟著變，不可以讓已經砍掉的角色繼續出現在說明文字裡（鐵律4：改動可自訂設定時不可留一份寫死的對照表）。
$roleRows = [];
try {
    $roleRows = $db->query("SELECT role_id, role_code, role_name, is_system FROM roles
                            WHERE module='training' OR is_system=1 ORDER BY is_system DESC, role_id")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}
$featLabel = [];
foreach (TRAINING_FEATURES as $f) $featLabel[$f['code']] = $f['label'];
$roleExplain = [];   // [role_name, 說明字串]
foreach ($roleRows as $rr) {
    if ((int)$rr['is_system'] === 1) { $roleExplain[] = [$rr['role_name'], '系統角色，固定擁有全部權限（不可修改）']; continue; }
    $codes = [];
    try {
        $fc = $db->prepare("SELECT feature_code FROM role_features WHERE role_id=?");
        $fc->execute([(int)$rr['role_id']]);
        $codes = $fc->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {}
    if (in_array('all', $codes, true)) $desc = '固定擁有全部權限';
    else {
        $labels = array_values(array_filter(array_map(fn($c) => $featLabel[$c] ?? null, $codes)));
        $desc = $labels ? implode('；', $labels) : '（尚未在「角色設定」勾選任何功能，目前無任何權限）';
    }
    $roleExplain[] = [$rr['role_name'], $desc];
}
// 目前角色徽章：秀出「本人實際被指派」的角色名稱（可能不只一個），而不是用權限高低猜一個固定字——
// 這樣即使管理員把原本 4 個內建角色合併改名，這裡顯示的永遠是實際存在的角色名。
$myRoleNames = [];
if ($trUser) {
    try {
        $st = $db->prepare("SELECT DISTINCT r.role_name FROM user_roles ur JOIN roles r ON r.role_id=ur.role_id
                            WHERE ur.user_id=? AND (r.module='training' OR (r.role_code='admin' AND r.is_system=1))");
        $st->execute([(int)$trUser['id']]);
        $myRoleNames = $st->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {}
}
$roleLabel = $perms['isAdmin'] ? ($myRoleNames ? implode('、', $myRoleNames) : '管理者')
           : ($myRoleNames ? implode('、', $myRoleNames) : ($perms['canView'] ? '（角色已被刪除，權限來自職稱指派）' : '無權限'));
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
        .page-help-btn { margin-left:auto; height:30px; font-size:13px; padding:0 14px; border:1px solid #D8BE93;
            border-radius:4px; background:#fff; color:#5b3a1e; cursor:pointer; }
        .page-help-btn:hover { background:#F7E0BD; }
        .help-doc h4 { font-size:14px; color:#8A5A2B; margin:10px 0 4px; }
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
        /* 計畫中：整列底色淺金黃，蓋過斑馬紋與 hover，一眼辨識哪些場次還沒排定。
           改過兩次：綠色不搭全站暖色調、珊瑚紅在傳產現場會被誤讀成「NG／不良」，改用不帶紅色的暖金黃色。 */
        table.tr-table tbody tr.row-planned,
        table.tr-table tbody tr.row-planned:nth-child(even),
        table.tr-table tbody tr.row-planned:hover { background:#FBEFD1; }
        table.tr-table td.t-left { text-align:left; }
        .st-pill { display:inline-block; font-size:12px; border-radius:10px; padding:2px 9px; }
        .st-planned { background:#F2C86D; color:#5C3D00; border:1px solid #D9A233; font-weight:bold; }
        .st-scheduled { background:#E8B77A; color:#4d2f10; }
        .st-done { background:#F0A24B; color:#fff; }
        .st-cancelled { background:#efe7d8; color:#b0a390; text-decoration:line-through; }
        /* 狀態點擊篩選鈕：沿用狀態徽章配色，加游標與選中態(暗邊框+內縮陰影)方便辨識目前篩選中的是哪個 */
        .stat-filter { display:inline-flex; gap:5px; }
        .sf-btn { cursor:pointer; opacity:.55; border:1.5px solid transparent; transition:opacity .15s; }
        .sf-btn:hover { opacity:.85; }
        .sf-btn.on { opacity:1; border-color:#8A5A2B; box-shadow:inset 0 0 0 1px #fff; }
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
        /* 主分頁（訓練場次／達標狀況）與月份分頁 */
        .tr-tabs { display:flex; gap:4px; margin:6px 0 8px; flex-wrap:wrap; }
        .tr-tabs .tab { cursor:pointer; padding:5px 16px; font-size:13px; border:1.5px solid #E8D5B5; border-bottom:none;
            border-radius:8px 8px 0 0; background:#FDF8EF; color:#8a6d45; }
        .tr-tabs .tab.on { background:#F0A24B; color:#fff; border-color:#d98a33; font-weight:bold; }
        .mon-tabs { display:flex; gap:3px; flex-wrap:wrap; margin-bottom:8px; }
        .mon-tabs .mt { cursor:pointer; font-size:12.5px; padding:3px 10px; border:1px solid #E8D5B5; border-radius:6px;
            background:#fff; color:#5b3a1e; }
        .mon-tabs .mt.on { background:#F0A24B; color:#fff; border-color:#d98a33; }
        .mon-tabs .mt.has { background:#F7E0BD; }
        .mon-tabs .mt.on.has { background:#F0A24B; }
        /* 清單展開明細 */
        tr.det-row > td { background:#FFFBF3 !important; text-align:left; padding:10px 14px !important; }
        .det-box { font-size:12.5px; color:#5b3a1e; line-height:1.7; }
        .det-box h5 { margin:0 0 4px; font-size:13px; color:#8A5A2B; font-weight:bold; }
        .det-box .kv { margin-bottom:6px; }
        .det-box .kv b { color:#8A5A2B; }
        .det-box pre.ol { white-space:pre-wrap; font-family:inherit; background:#FDF8EF; border:1px dashed #E8D5B5;
            border-radius:6px; padding:6px 9px; margin:2px 0 8px; }
        .det-box table { border-collapse:collapse; font-size:12px; background:#fff; }
        .det-box table th, .det-box table td { border:1px solid #EADFC8; padding:3px 8px; }
        .det-box table th { background:#F7E0BD; color:#5b3a1e; }
        .ev-pass { color:#7a5217; background:#F7E0BD; border-radius:9px; padding:1px 8px; font-size:11.5px; }
        .ev-fail { color:#fff; background:#DD5138; border-radius:9px; padding:1px 8px; font-size:11.5px; }
        .ev-exempt { color:#8a6d45; background:#efe7d8; border-radius:9px; padding:1px 8px; font-size:11.5px; }
        .ev-none { color:#b0a390; font-size:11.5px; }
        .tr-clickable { cursor:pointer; }
        /* 對象部門：可搜尋的多選（部門多時用打字找，不用在一堆勾選框裡找） */
        .dp-pick { position:relative; border:1px solid #D8BE93; border-radius:4px; background:#fff; padding:3px 4px; }
        .dp-tags { display:flex; flex-wrap:wrap; gap:3px; }
        .dp-tags .tg { background:#F7E0BD; color:#5b3a1e; border-radius:10px; font-size:11.5px; padding:1px 6px 1px 8px; }
        .dp-tags .tg i { cursor:pointer; color:#b5762a; margin-left:4px; }
        .dp-pick > input { width:100%; border:none !important; outline:none; font-size:12.5px; padding:3px 4px !important; }
        .dp-list { display:none; position:absolute; left:0; right:0; top:100%; z-index:20; background:#fff;
            border:1px solid #D8BE93; border-radius:0 0 4px 4px; max-height:190px; overflow-y:auto; box-shadow:0 4px 10px rgba(0,0,0,.12); }
        .dp-list div { padding:4px 9px; font-size:12.5px; color:#5b3a1e; cursor:pointer; }
        .dp-list div:hover { background:#FBF0DD; }
        .dp-list div.on { color:#b0a390; }
        /* 角色設定（沿用全站 Roles_API + role_features，比照 purchase_request.php 的角色管理三欄樣式） */
        #roleList { max-height:280px; overflow-y:auto; }
        .role-item { padding:6px 10px; border-bottom:1px solid #EADFC8; cursor:pointer; font-size:13px; }
        .role-item:hover { background:#FFF3E0; }
        .role-item.on { background:#F0A24B; color:#fff; font-weight:bold; }
        .role-item.sys { color:#b0a390; cursor:not-allowed; }
        .role-feat { display:block; font-size:13px; font-weight:normal; padding:2px 0; cursor:pointer; }
        .role-feat input { width:auto; margin:0 6px 0 0; }
        /* 目標設定：週期與次數同一列並排，不要上下堆疊 */
        /* 目標設定表：table-layout:fixed + 百分比欄寬，避免欄位亂飄出現大片空白與橫向拉桿 */
        #tgSetTbl { table-layout:fixed; width:100%; }
        #tgSetTbl th, #tgSetTbl td { padding:5px 6px; overflow:hidden; }
        td.tg-cell { white-space:nowrap; }
        td.tg-cell select { width:64px; height:26px; border:1px solid #D8BE93; border-radius:4px; font-size:12px; padding:0 2px; }
        td.tg-cell input { width:42px; height:26px; border:1px solid #D8BE93; border-radius:4px; font-size:12px;
            padding:0 3px; margin:0 3px; text-align:center; }
        td.tg-cell span { font-size:12px; color:#5b3a1e; }
        /* 部門合併設定：跳窗放高、群組內部不再出現第二層捲軸 */
        .grp-box .att-people { max-height:none !important; overflow:visible !important; }
        #grpList { max-height:none; }
        .ok-yes { color:#7a5217; font-weight:bold; }
        .ok-no  { color:#DD5138; font-weight:bold; }
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
            <button class="page-help-btn" id="btnPageHelp"><i class="fa fa-question-circle"></i> 使用說明</button>
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
            <select id="statSel" style="display:none;">
                <option value="">全部</option><option value="planned">計畫中</option>
                <option value="scheduled">已排定</option>
                <option value="done">已完成</option><option value="cancelled">取消</option>
            </select>
            <span class="stat-filter" id="statFilter">
                <span class="sf-btn st-planned" data-v="planned">計畫中</span>
                <span class="sf-btn st-scheduled" data-v="scheduled">已排定</span>
                <span class="sf-btn st-done" data-v="done">已完成</span>
                <span class="sf-btn st-cancelled" data-v="cancelled">取消</span>
            </span>
            <input type="text" id="kwSel" placeholder="搜尋課程" style="width:130px;">
            <button class="btn-warm" id="btnAdd" style="display:none;"><i class="fa fa-plus"></i> 新增訓練場次</button>
            <button id="btnSetting" style="display:none;"><i class="fa fa-sliders"></i> 模組設定</button>
            <button id="btnCsv"><i class="fa fa-file-text-o"></i> 匯出CSV</button>
            <button id="btnPrintPlan"><i class="fa fa-print"></i> 列印訓練計劃表</button>
            <button id="btnPrintResult" style="display:none;"><i class="fa fa-print"></i> 列印訓練結果明細表</button>
            <button id="btnSubmitPlan" style="display:none;"><i class="fa fa-paper-plane"></i> 送審計劃表</button>
            <span id="planStat" style="font-size:12px;color:#8a6d45;"></span>
            <span class="tr-role-badge">目前角色：<b><?= htmlspecialchars($roleLabel) ?></b>
                <i class="fa fa-question-circle" id="btnRoleHelp" title="角色權限說明"></i></span>
        </div>

        <div class="tr-tabs" id="mainTabs">
            <span class="tab on" data-tab="list">訓練場次</span>
            <span class="tab" data-tab="target">達標狀況（各部門訓練次數）</span>
            <span class="tab" data-tab="apply">教育訓練需求申請
                <span id="reqTabBadge" style="display:none;background:#DD5138;color:#fff;border-radius:9px;
                    padding:1px 6px;font-size:11px;margin-left:4px;" title="已核准但尚未轉為計畫"></span></span>
        </div>

<div id="paneList">
        <div class="tr-stat" id="statBar">
            <span class="yr" id="yrRate">—</span>
            <span id="monWrap"></span>
        </div>

        <div class="tr-table-wrap">
            <table class="tr-table" id="trTable">
                <thead><tr>
                    <th>月份</th><th>對象部門</th><th>課程名稱</th><th>類型</th><th>講師/開課單位</th><th>時數</th><th>費用</th>
                    <th>應到</th><th>實到</th><th>評鑑</th><th>狀態</th><th>開課日期</th><th>操作</th>
                </tr></thead>
                <tbody id="trBody"><tr><td colspan="13" style="padding:20px;color:#8a6d45;">載入中…</td></tr></tbody>
            </table>
        </div>
        <div style="font-size:11px;color:#8a6d45;margin-top:3px;">點任一列可展開該場次明細（課程大綱、上課時段、參加人員與評鑑結果、附件）。</div>
</div>

<div id="paneTarget" style="display:none;">
        <div class="tr-toolbar">
            <b style="color:#8A5A2B;">達標狀況</b>
            <span style="font-size:12px;color:#8a6d45;">依「顯示單位」統計；子部門可合併成一個單位一起算。未指定部門的全公司課程，每個單位都計入。</span>
            <button id="btnTarget" style="display:none;"><i class="fa fa-bullseye"></i> 目標次數設定</button>
            <button id="btnGroup" style="display:none;"><i class="fa fa-sitemap"></i> 部門合併設定</button>
        </div>
        <div class="mon-tabs" id="monTabs"></div>
        <div class="tr-table-wrap">
            <table class="tr-table" id="tgTable">
                <thead id="tgHead"></thead>
                <tbody id="tgBody"><tr><td style="padding:20px;color:#8a6d45;">載入中…</td></tr></tbody>
            </table>
        </div>
        <div style="font-size:11px;color:#8a6d45;margin-top:4px;">
            達標判定：目標設「每月 N 次」→ 逐月各自要 N 次；設「每年 N 次」→ 看年度累計。
            <span class="ok-yes">達標</span>／<span class="ok-no">未達標</span>；括號內為該月已排定或計畫中的場次（尚未完成，不計入達標）。
        </div>
</div>

<div id="paneApply" style="display:none;">
        <div class="tr-toolbar">
            <button id="btnReqAdd" style="display:none;"><i class="fa fa-plus"></i> 新增申請單</button>
            <span style="font-size:12px;color:#8a6d45;">2-MM-01-05 教育訓練需求申請單線上化：填寫送出 → <span id="reqFlowHint"></span> → 訓練管理員核准後可轉為訓練計畫。</span>
        </div>
        <div class="tr-table-wrap">
            <table class="tr-table" id="reqTable">
                <thead><tr><th>申請日期</th><th>申請單位</th><th>主旨</th><th>受訓人員</th><th>受訓時間</th>
                    <th>狀態</th><th>操作</th></tr></thead>
                <tbody id="reqBody"><tr><td colspan="7" style="padding:20px;color:#8a6d45;">載入中…</td></tr></tbody>
            </table>
        </div>
        <div style="font-size:11px;color:#8a6d45;margin-top:4px;">
            狀態：<span class="st-pill st-planned">草稿</span> <span class="st-pill st-scheduled">待核准</span>
            <span class="st-pill st-done">已核准</span> <span class="st-pill st-cancelled">已駁回</span>
            <span class="st-pill" style="background:#F0A24B;color:#fff;">已轉計畫</span>。
            草稿僅本人可編輯；送出後由申請單位主管核准（可於模組設定切換免簽核）；訓練管理員將已核准的申請轉為訓練計畫。
        </div>
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
            <div><label>對象部門（可複選；未選＝全公司）</label>
                <div id="edDeptPick" class="dp-pick">
                    <div class="dp-tags" id="edDeptTags"></div>
                    <input type="text" id="edDeptSearch" placeholder="輸入部門名稱搜尋，或點這裡選擇…" autocomplete="off" data-eg-skip>
                    <div class="dp-list" id="edDeptList"></div>
                </div>
                <div style="font-size:11px;color:#8a6d45;margin-top:2px;">
                    <a href="javascript:;" onclick="edDeptAll(1)" style="color:#b5762a;">全選</a>
                    <a href="javascript:;" onclick="edDeptAll(0)" style="color:#b5762a;">清除（＝全公司）</a>
                    　勾選的每個部門都認定有做這場訓練</div></div>
            <div><label>訓練類型</label><select id="edType"><option value="internal">內訓</option><option value="external">外訓</option></select></div>
            <div><label>預計天數（多天課程）</label><input type="number" id="edDays" step="1" min="1" max="60" placeholder="1">
                <div class="errmsg" id="errEdDays"></div></div>
            <div><label>預計總時數</label><input type="number" id="edHours" step="any" min="0">
                <div class="errmsg" id="errEdHours"></div></div>
            <div><label>訓練費用</label><input type="number" id="edCost" step="any" min="0" placeholder="0"></div>
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
        <div class="tr-hint" id="edFromReqHint" style="display:none;background:#FFF7E8;"><i class="fa fa-share-square-o"></i></div>
        <div class="tr-hint"><i class="fa fa-info-circle"></i>
            上課日期、每日時間、上課地點與參加人員，請於清單按 <b>確認實行</b> 登錄；計畫存檔後狀態為「計畫中」。
            多天課程在此填「預計天數」，確認實行時會自動排出連續日期供逐日調整。</div>
    </div>
    <div class="m-foot">
        <button class="b-cancel" onclick="closeMask('edMask')">取消</button>
        <button class="b-ok" onclick="submitEd()">儲存計畫</button>
    </div>
</div></div>

<!-- 教育訓練需求申請單 modal（2-MM-01-05 線上化，仿新增計畫跳窗樣式） -->
<div class="tr-mask" id="reqMask"><div class="tr-modal wide" data-eg-form data-eg-submit=".m-foot .b-ok">
    <div class="m-head"><span id="reqTitle">新增教育訓練需求申請單</span><span class="m-close" onclick="closeMask('reqMask')">✕</span></div>
    <div class="m-body">
        <div class="grid2">
            <div><label>申請單位 *（限本人所屬部門，含兼職部門）</label><select id="reqDept"><option value="">請選擇</option></select></div>
            <div><label>申請日期 *</label>
                <div style="display:flex;gap:5px;">
                    <input type="date" id="reqApplyDate" max="9999-12-31" style="flex:1;">
                    <button type="button" class="b-att nw" id="btnReqApplyDateSave" style="display:none;" onclick="reqSetApplyDate()">更新日期</button>
                </div>
            </div>
            <div style="grid-column:1/3;"><label>主旨 *</label><input type="text" id="reqSubject" maxlength="100"></div>
            <div style="grid-column:1/3;"><label>一、簡述內容</label><textarea id="reqContent" rows="2" maxlength="2000"></textarea></div>
            <div style="grid-column:1/3;"><label>二、主管要求學習重點</label><textarea id="reqFocus" rows="2" maxlength="2000"></textarea></div>
        </div>

        <div class="att-sec" style="border-top:none;margin-top:2px;">
            <div style="font-weight:bold;color:#5b3a1e;margin:6px 0 4px;">三、受訓人員 <small id="reqAttCount" style="color:#8a6d45;font-weight:normal;"></small></div>
            <div id="reqAttAddRow" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-bottom:4px;">
                <span id="reqAttDeptHint" style="font-size:12px;color:#8a6d45;">請先選申請單位</span>
                <button type="button" class="b-att nw" onclick="reqAttAddChecked()"><i class="fa fa-user-plus"></i> 加入勾選人員</button>
                <label style="margin:0;font-size:12px;color:#8a6d45;"><input type="checkbox" id="reqAttPickAll"> 全選</label>
            </div>
            <div id="reqAttPeopleBox" class="att-people"></div>
            <div class="att-list-wrap" style="max-height:130px;">
                <table class="att-tbl"><thead><tr><th>部門</th><th>職稱</th><th>姓名</th><th style="width:26px;"></th></tr></thead>
                <tbody id="reqAttBody"></tbody></table>
            </div>
        </div>

        <div style="font-weight:bold;color:#5b3a1e;margin:10px 0 4px;">四、受訓時間 <span id="reqDaysHint" style="font-weight:normal;color:#8a6d45;font-size:12px;"></span></div>
        <div class="att-list-wrap" style="max-height:170px;">
            <table class="day-tbl">
                <thead><tr><th style="width:50px;">第</th><th style="width:130px;">日期</th><th style="width:76px;">開始</th>
                    <th style="width:76px;">結束</th><th>檢查</th><th style="width:26px;"></th></tr></thead>
                <tbody id="reqDayBody" data-eg-row-add="reqDayAdd" data-eg-row-del="reqDayDelLast"></tbody>
            </table>
        </div>
        <div style="font-size:11px;color:#8a6d45;margin:3px 0 8px;">
            <button type="button" class="b-att nw" onclick="reqDayAdd()"><i class="fa fa-plus"></i> 新增一天</button>
            時間可直接輸入（09:00、0900、9 都可）；末列按 ↓ 自動加一天。此階段免設休息時間，休息與實際時數留到「確認開課」再算——
            即使多天課程每天時段不同也沒關係，逐列各自填。
        </div>

        <div class="grid2">
            <div><label>總計時數（選填）</label><input type="number" id="reqHours" min="0" step="any"></div>
            <div><label>四、受訓地點</label><input type="text" id="reqLocation" maxlength="100"></div>
            <div><label>五、受訓費用</label><input type="text" id="reqCost" maxlength="100" placeholder="例：免費／$3,000"></div>
            <div><label>會辦管理：簡章份數</label><input type="number" id="reqBrochure" min="0" step="1"></div>
        </div>
        <div class="tr-hint" id="reqStatusHint" style="display:none;"></div>
    </div>
    <div class="m-foot">
        <button class="b-cancel" onclick="printRequestForm()"><i class="fa fa-print"></i> 列印</button>
        <button class="b-cancel" onclick="closeMask('reqMask')">取消</button>
        <button class="b-ok" id="reqSaveBtn" onclick="submitReq(0)">儲存草稿</button>
        <button class="b-ok" id="reqSubmitBtn" style="margin-left:6px;" onclick="submitReq(1)"><i class="fa fa-paper-plane"></i> 儲存並送出</button>
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
            <div><label>評鑑方式</label>
                <select id="exEvalMethod"><option value="">（未指定）</option></select></div>
            <div style="display:flex;align-items:flex-end;padding-bottom:6px;">
                <span id="exEvalHint" style="font-size:12px;color:#8a6d45;"></span></div>
        </div>
        <label style="display:block;font-size:13px;color:#5b3a1e;margin:9px 0 3px;">課程大綱（會印在簽到表上）</label>
        <textarea id="exOutline" rows="3" maxlength="5000" placeholder="條列本次課程內容重點，例如：&#10;1. ISO 9001 條文說明&#10;2. 內部稽核實務演練"></textarea>

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
            <div class="batch-box" style="margin:4px 0 0;">
                <b>批次設定實到</b>
                <button type="button" class="b-att nw" onclick="attAttendAll(1)"><i class="fa fa-check-square-o"></i> 全部設為實到</button>
                <button type="button" class="b-att nw" style="background:#fff;color:#8A5A2B;" onclick="attAttendAll(0)">全部取消實到</button>
                <span style="color:#8a6d45;">（先全設實到，再把沒來的幾個取消，最快）</span>
            </div>
            <div class="batch-box" id="evalBatchBox" style="margin:4px 0 6px;">
                <b>批次設定評鑑</b>
                <button type="button" class="b-att nw" onclick="attEvalAll('pass')">全設合格</button>
                <button type="button" class="b-att nw" style="background:#fff;color:#8A5A2B;" onclick="attEvalAll('fail')">全設不合格</button>
                <button type="button" class="b-att nw" style="background:#fff;color:#8A5A2B;" onclick="attEvalAll('')">清空評鑑</button>
                <label style="margin:0;"><input type="checkbox" id="evalOnlyAttended" checked> 只套用到「實到」的人</label>
                <span id="evalSummary" style="color:#8A5A2B;"></span>
            </div>
            <div class="att-list-wrap">
                <table class="att-tbl"><thead><tr><th>部門</th><th>職稱</th><th>姓名</th><th style="width:42px;">實到</th>
                    <th style="width:96px;">評鑑結果</th><th style="width:58px;">分數</th><th style="width:120px;">備註</th>
                    <th style="width:46px;">簽名</th><th style="width:26px;"></th></tr></thead>
                <tbody id="attBody"></tbody></table>
            </div>
            <div style="font-size:11px;color:#8a6d45;margin-top:3px;">開課前先建名單並列印簽到表；上完課回來勾「實到」、填評鑑結果，再按「登錄完成」。
                名單一律綁員工 ID，日後可依人查詢受訓紀錄。分數為選填（0~100）。</div>
        </div>

        <!-- 考核表（原OJT/實作口試考核表；未來可能與其他頁面通用）：僅內訓、已排定開課後可建立（本場講師本人或訓練管理員）。
             各考核細項仍留白供現場手寫評分，考核完成後掃描回來以附件上傳佐證；表尾總體評核結果／分數若上方「參加人員」名單已填則直接印出。 -->
        <div class="att-sec" id="ojtSec" style="display:none;">
            <div style="font-weight:bold;color:#5b3a1e;margin:12px 0 4px;">考核表 <small id="ojtCount" style="color:#8a6d45;font-weight:normal;"></small></div>
            <div id="ojtLockHint" style="font-size:12px;color:#DD5138;display:none;margin-bottom:4px;">此場次的考核項目僅本場講師本人或訓練管理員可編輯。</div>
            <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-bottom:6px;">
                <label style="margin:0;font-size:12px;color:#5b3a1e;">考官</label>
                <input type="text" id="ojtAssessor" maxlength="50" placeholder="預設帶入講師姓名，可修改" style="width:170px;">
            </div>
            <div class="att-list-wrap" style="max-height:210px;">
                <table class="day-tbl">
                    <thead><tr><th style="width:40px;">項次</th><th>考核／口試重點</th><th style="width:110px;">方式</th><th style="width:26px;"></th></tr></thead>
                    <tbody id="ojtBody" data-eg-row-add="ojtAdd" data-eg-row-del="ojtDelLast"></tbody>
                </table>
            </div>
            <div id="ojtOps" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:4px 0 2px;">
                <button type="button" class="b-att nw" onclick="ojtAdd()"><i class="fa fa-plus"></i> 新增項目</button>
                <button type="button" class="b-att nw" onclick="ojtSave()"><i class="fa fa-save"></i> 儲存考核項目清單</button>
                <button type="button" class="b-att nw" style="background:#fff;color:#8A5A2B;" onclick="printOjtSheet()"><i class="fa fa-print"></i> 列印考核表</button>
                <span id="ojtMsg" style="font-size:12px;color:#8a6d45;"></span>
            </div>
            <div style="font-size:11px;color:#8a6d45;margin-top:3px;">列印表單<b>各考核細項</b>仍留白，供現場考核時手寫勾選合格／不合格並評分；表尾「總體評核結果」與分數<b>會直接印出上方「參加人員」名單已填的評鑑結果／分數</b>（尚未填則留白）；每位參加人員各印一份（考核項目少時自動併印多人於同一頁節省紙張），表單上附「未到考」勾選欄供考官現場勾填。
                考核完成掃描後，請至下方「附件」上傳並勾選「考核表」類別佐證，作為簽到表評鑑結果的客觀證據。</div>
        </div>

        <!-- 附件：簽到表掃描、教材、試卷…（DB 只存檔名，路徑由模組設定即時組出） -->
        <div class="att-sec">
            <div style="font-weight:bold;color:#5b3a1e;margin:12px 0 4px;">附件 <small id="atCount" style="color:#8a6d45;font-weight:normal;"></small>
                <small style="color:#8a6d45;font-weight:normal;">（簽到表掃描件、教材/講義、試卷、上課照片…）</small></div>
            <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-bottom:4px;">
                <span style="font-size:12px;color:#5b3a1e;">類別（可複選）</span>
                <span id="atCatBox" style="display:flex;gap:2px 12px;flex-wrap:wrap;"></span>
                <input type="file" id="atFile" multiple style="display:none;">
                <button type="button" class="b-att nw" onclick="document.getElementById('atFile').click()"><i class="fa fa-upload"></i> 選擇檔案上傳</button>
                <span id="atMsg" style="font-size:12px;color:#8a6d45;"></span>
            </div>
            <div style="font-size:11px;color:#8a6d45;margin-bottom:4px;">同一份掃描 PDF 若同時是簽到表和試卷，兩個類別都勾即可（一個檔案可屬多個類別）。</div>
            <div class="att-list-wrap" style="max-height:150px;">
                <table class="att-tbl"><thead><tr><th style="width:88px;">類別</th><th class="t-left">檔名</th>
                    <th style="width:70px;">大小</th><th style="width:80px;">上傳者</th><th style="width:120px;">上傳時間</th><th style="width:26px;"></th></tr></thead>
                <tbody id="atBody"></tbody></table>
            </div>
        </div>
    </div>
    <div class="m-foot">
        <div style="text-align:left;font-size:11.5px;color:#8a6d45;line-height:1.6;margin-bottom:6px;">
            <b style="color:#8A5A2B;">確認開課</b>＝<u>課還沒上</u>，只是確定要開（狀態→已排定、寫入行事曆、可印簽到表）；
            <b style="color:#8A5A2B;">登錄完成</b>＝<u>課已經上完</u>、實到也勾好了（狀態→已完成，<b>此時才計入當月教育訓練達成率</b>）。
            當天上完課可直接按「登錄完成」，不必先按「確認開課」。
        </div>
        <button class="b-cancel" onclick="printSignSheet(false)"><i class="fa fa-print"></i> 列印簽到表</button>
        <button class="b-cancel" onclick="printSignSheet(true)" title="不帶目前名單，整張印成空白列供現場手寫簽到"><i class="fa fa-file-o"></i> 列印空白簽到表</button>
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
        <div class="tr-tabs" style="margin-top:0;">
            <span class="tab on set-tab" data-p="1">一般</span>
            <span class="tab set-tab" data-p="2">達標統計</span>
            <span class="tab set-tab" data-p="3">文件編號與送審</span>
            <span class="tab set-tab" data-p="4" id="setTab4" style="display:none;">角色設定</span>
        </div>
        <div id="setPane1">
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
        <label>附件儲存路徑（NAS 實體資料夾）</label>
        <input type="text" id="setAttNas" maxlength="200">
        <div class="tr-hint" style="margin-top:6px;">全站附件預設放在 <b id="setAttRoot" style="word-break:break-all;"></b> 之下，
            再以<b>頁面名稱</b>為子資料夾（本頁＝「教育訓練」），不存在會自動建立；要放別的位置就直接改這一欄。<br>
            附件在 DB <b>只存檔名</b>，完整路徑一律讀取當下用這個設定值現場組出——換 NAS 或搬資料夾時把檔案原封不動複製過去、
            改這一個設定即可，舊附件立刻讀得到（鐵律5）。<b>不需要填網址</b>：附件一律經本頁 API 下載，已自動套用權限檢查。</div>

        <div style="border-top:1px dashed #EADFC8;margin:14px 0 0;"></div>
        <label>行事曆類別綁定 — 內訓</label>
        <select id="setCatIn"><option value="">（自動：以名稱「課程(內訓)」尋找）</option></select>
        <label>行事曆類別綁定 — 外訓</label>
        <select id="setCatEx"><option value="">（自動：以名稱「課程(外訓)」尋找）</option></select>
        <div class="tr-hint" style="margin-top:6px;">綁定存的是類別 <b>id</b>，所以日後在行事曆把類別改名（例如「課程(內訓)」→「內部訓練」）綁定依然有效。
            未綁定時才用名稱尋找，找不到就不寫行事曆（不影響存檔）。<br>
            <span id="setCatEff" style="color:#8A5A2B;"></span></div>
        </div>

        <div id="setPane2" style="display:none;">
            <label>排除教育訓練的部門（勾選者不列入達標統計，也不會出現在統計表）</label>
            <div id="setExDept" class="att-people" style="max-height:220px;"></div>
            <div class="tr-hint" style="margin-top:6px;">例如「董事長室」「總經理室」等不需要納入年度訓練次數考核的單位。
                排除後該部門仍可被指定為訓練對象、仍會出現在名單裡，只是<b>不列入達標統計</b>。</div>
        </div>

        <div id="setPane3" style="display:none;">
            <label>「訓練場次」分頁／年度訓練計劃表 — AS 文件編號</label>
            <select id="setDocPlan"><option value="">（未對應，列印時不顯示文件編號）</option></select>
            <label>訓練結果明細表 — AS 文件編號</label>
            <select id="setDocResult"><option value="">（未對應）</option></select>
            <label>「達標狀況」分頁 — AS 文件編號</label>
            <select id="setDocTarget"><option value="">（未對應）</option></select>
            <label>教育訓練需求申請單 — AS 文件編號</label>
            <select id="setDocRequest"><option value="">（未對應）</option></select>
            <label>簽到表 — AS 文件編號</label>
            <select id="setDocSignsheet"><option value="">（未對應）</option></select>
            <label>簽到表空白列（簽名+簽日期欄位較高，太少人時可加列讓紙本看起來完整）</label>
            <div style="display:flex;gap:6px;align-items:center;">
                <select id="setSignBlankMode" style="max-width:220px;">
                    <option value="0">不自動加空白列</option>
                    <option value="fixed">固定加幾列</option>
                    <option value="fill16">補到滿頁（最多 16 列，含實際人數）</option>
                </select>
                <input type="number" id="setSignBlankN" min="0" max="16" step="1" style="width:70px;display:none;" placeholder="列數">
            </div>
            <div class="tr-hint" style="margin-top:6px;">綁定的是 AS 文件<b>本身（存 id）</b>，列印時才解出編號印在<b>頁尾右下角</b>；
                文件改編號不必回來改設定。未對應＝該表不印文件編號（見 <b>ai-rules/16 列印文件標準</b>）。</div>

            <div style="border-top:1px dashed #EADFC8;margin:14px 0 0;"></div>
            <label>簽到表／訓練紀錄等「參加人員本人簽名」自動產生的圖章樣式</label>
            <select id="setStampTpl"><option value="0">（預設印章樣式）</option></select>
            <div class="tr-hint" style="margin-top:6px;">套用哪個模板請到「圖章管理 → 線上圖章設計」建立/挑選；有上傳掃描實體章的人一律優先用掃描章，這裡只影響沒掃描章時自動產生的印章樣式。</div>

            <label style="margin-top:10px;">核准／審核／人事／考官等「核准類」圖章樣式</label>
            <select id="setApprovalStampTpl"><option value="0">（預設圓形圖章）</option></select>
            <div class="tr-hint" style="margin-top:6px;">考核表考官簽章、計畫表/結果明細表的核准／審核／人事、需求申請單的批示／會辦等核准類圖章，跟上面「參加人員本人簽名」是不同性質（一個是本人簽到證明、一個是主管核准戳），分開設定；未設定＝標準圓形圖章。</div>

            <div style="border-top:1px dashed #EADFC8;margin:14px 0 0;"></div>
            <label>教育訓練需求申請單是否需要主管簽核</label>
            <select id="setReqNeedAppr">
                <option value="0">免簽核（送出即視同核准，列印仍自動蓋上核准圖章＝申請單位主管）</option>
                <option value="1">需要簽核（送出後通知申請單位主管核准／退回）</option>
            </select>
            <div class="tr-hint" style="margin-top:6px;">找不到申請單位的部門主管、或申請人本人就是主管時，一律自動核准（不論此設定），避免卡關。此設定僅系統管理者可改。</div>

            <div style="border-top:1px dashed #EADFC8;margin:14px 0 0;"></div>
            <label>年度訓練計劃表是否需要送審</label>
            <select id="setNeedAppr">
                <option value="0">不需送審（列印時直接顯示全部簽章，日期＝送審日）</option>
                <option value="1">需要送審（審核 → 核准，依序通知）</option>
            </select>
            <label>計劃表簽章日期（免送審時列印用；留空＝自動取該年度計畫的最後異動日）</label>
            <div style="display:flex;gap:6px;align-items:center;">
                <input type="date" id="setSignDate" max="9999-12-31" style="max-width:180px;">
                <span id="setSignAuto" style="font-size:12px;color:#8a6d45;"></span>
            </div>

            <div class="tr-hint" style="margin-top:6px;">
                送審流程：送審 → 通知<b id="setSgReview">（未設定審核者）</b>審核 → 通過後通知 <b id="setSgApprover">（未設定核准者）</b>核准。
                通知可直接<b>核准／退回</b>，退回必須填原因並會通知送審者（見 <b>ai-rules/17 審核通知標準</b>）。<br>
                簽章人員（人事／審核／核准）一律取自<b>全站「組織角色綁定設定」</b>：
                <a href="../admin/org_role_setting.php" target="_blank" style="color:#b5762a;">開啟設定頁</a>，本模組不另外設定，改組織只要改那一頁。
            </div>
        </div>

        <div id="setPane4" style="display:none;">
            <p class="tr-hint" style="margin:0 0 8px;">左邊選或新增角色 → 右邊改名稱、勾這個角色能看到什麼／能做什麼。
                <b>權限由上而下包含</b>：勾了「訓練管理員」就自動含登錄與檢閱，不必逐個勾。「管理者」固定擁有全部權限、不可修改。</p>
            <p class="tr-hint" style="margin:0 0 8px;background:#FFF7EA;">
                <i class="fa fa-info-circle"></i> <b>「誰擁有這個角色」不在這裡設定</b>——人員對應角色全站統一在
                <a href="../user/user_permissions.php" target="_blank">人員權限設定頁</a>的「教育訓練管理 角色指派」區塊，
                這裡只負責定義角色的名稱與內容。</p>
            <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-start;">
                <div style="border:1px solid #E8D5B5;border-radius:6px;background:#fff;flex:0 0 190px;">
                    <div style="background:#F7E0BD;color:#5b3a1e;font-size:12px;font-weight:bold;padding:5px 10px;
                        border-radius:6px 6px 0 0;display:flex;justify-content:space-between;align-items:center;">角色
                        <button type="button" class="b-att nw" id="btnRoleAdd" style="padding:1px 8px;">＋ 新增</button></div>
                    <div id="roleList"></div>
                </div>
                <div style="border:1px solid #E8D5B5;border-radius:6px;background:#fff;flex:1;min-width:260px;">
                    <div style="background:#F7E0BD;color:#5b3a1e;font-size:12px;font-weight:bold;padding:5px 10px;border-radius:6px 6px 0 0;">角色內容</div>
                    <div id="roleEdit" style="display:none;padding:10px;">
                        <label>角色名稱</label>
                        <div style="display:flex;gap:6px;">
                            <input type="text" id="roleName" style="flex:1;">
                            <button type="button" class="b-att nw" id="btnRoleRename">改名</button>
                            <button type="button" class="b-att nw" style="color:#DD5138;" id="btnRoleDel">刪除</button>
                        </div>
                        <div style="font-size:12px;font-weight:bold;color:#8A5A2B;margin:10px 0 4px;">可視內容（看得到什麼）</div>
                        <div id="featView"></div>
                        <div style="font-size:12px;font-weight:bold;color:#8A5A2B;margin:10px 0 4px;">可操作（能做什麼）</div>
                        <div id="featOp"></div>
                        <button type="button" class="b-att nw" id="btnRoleFeatSave" style="margin-top:10px;background:#F0A24B;color:#fff;">
                            <i class="fa fa-save"></i> 儲存功能</button>
                    </div>
                    <div id="roleEditHint" style="padding:24px;text-align:center;color:#8a6d45;">請在左側選一個角色，或按「＋ 新增」</div>
                </div>
            </div>
        </div>
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

<!-- 目標次數設定 modal（限訓練管理員） -->
<div class="tr-mask" id="tgMask"><div class="tr-modal wide" data-eg-form data-eg-submit=".m-foot .b-ok">
    <div class="m-head"><span>訓練次數目標設定 — <span id="tgYear"></span> 年度</span><span class="m-close" onclick="closeMask('tgMask')">✕</span></div>
    <div class="m-body">
        <div class="tr-hint" style="margin:0 0 8px;">第一列「<b>全公司統一</b>」是預設值：沒有個別設定的單位一律套用它。
            某個單位要不一樣，就把該列的「套用統一」取消勾選再填數字。週期可各自選「每月」或「每年」。</div>
        <div class="att-list-wrap" style="max-height:400px;">
            <table class="att-tbl" id="tgSetTbl">
                <thead><tr><th class="t-left" style="width:30%;">單位</th><th style="width:14%;">套用統一</th>
                    <th style="width:28%;">內訓目標</th><th style="width:28%;">外訓目標</th></tr></thead>
                <tbody id="tgSetBody"></tbody>
            </table>
        </div>
    </div>
    <div class="m-foot">
        <button class="b-cancel" onclick="closeMask('tgMask')">取消</button>
        <button class="b-ok" onclick="saveTargets()">儲存目標</button>
    </div>
</div></div>

<!-- 部門合併設定 modal（限訓練管理員）：子部門併入母部門一起算達標 -->
<div class="tr-mask" id="grpMask"><div class="tr-modal wide" data-eg-form data-eg-submit=".m-foot .b-ok">
    <div class="m-head"><span>部門合併設定（達標統計的顯示單位）</span><span class="m-close" onclick="closeMask('grpMask')">✕</span></div>
    <div class="m-body">
        <div class="tr-hint" style="margin:0 0 8px;">把子部門勾進同一個群組，達標統計就<b>合併成一列</b>計算（例如「管理課」含其下各組）。
            一個部門只能屬於一個群組；沒被勾進任何群組的部門各自獨立成一列。</div>
        <div id="grpList"></div>
        <button type="button" class="b-att nw" onclick="grpAdd()"><i class="fa fa-plus"></i> 新增合併群組</button>
    </div>
    <div class="m-foot">
        <button class="b-cancel" onclick="closeMask('grpMask')">取消</button>
        <button class="b-ok" onclick="saveGroups()">儲存合併設定</button>
    </div>
</div></div>

<!-- 場次檢視（唯讀）modal -->
<div class="tr-mask" id="viewMask"><div class="tr-modal wide">
    <div class="m-head"><span>訓練場次檢視</span><span class="m-close" onclick="closeMask('viewMask')">✕</span></div>
    <div class="m-body" id="viewBody"></div>
    <div class="m-foot">
        <button class="b-cancel" onclick="printViewSignSheet()"><i class="fa fa-print"></i> 列印簽到表</button>
        <button class="b-cancel" id="viewOjtBtn" onclick="printViewOjtSheet()"><i class="fa fa-print"></i> 列印考核表</button>
        <button class="b-ok" onclick="closeMask('viewMask')">關閉</button>
    </div>
</div></div>

<!-- 現場簽到（免編輯權限；選人→輸入本人密碼→Enter，比照會議記錄的密碼簽到模式） -->
<div class="tr-mask" id="checkinMask"><div class="tr-modal">
    <div class="m-head"><span>現場簽到</span><span class="m-close" onclick="closeMask('checkinMask')">✕</span></div>
    <div class="m-body">
        <div id="checkinInfo" style="margin-bottom:8px;color:#5b3a1e;"></div>
        <div id="checkinDayBox" style="margin-bottom:8px;display:none;">
            <label style="display:inline;margin-right:6px;">上課日期</label>
            <select id="checkinDaySel" onchange="checkinDayChange()"></select>
            <span style="font-size:11px;color:#8a6d45;margin-left:6px;">多天課程一天要簽一次，逐日各自簽到</span>
        </div>
        <table class="att-tbl"><thead><tr><th>部門</th><th>職稱</th><th>姓名</th><th style="width:180px;">簽到</th></tr></thead>
            <tbody id="checkinBody"></tbody></table>
        <div class="tr-hint" style="margin-top:8px;">共用一台裝置輪流簽：選自己的姓名那一列，輸入<b>本人密碼</b>按 Enter 即完成簽到（不是密碼反查身分，密碼只用來驗證是不是本人）。</div>
    </div>
    <div class="m-foot"><button class="b-ok" onclick="closeMask('checkinMask')">關閉</button></div>
</div></div>

<!-- 使用說明 modal（鐵律7） -->
<div class="tr-mask" id="helpUseMask"><div class="tr-modal wide">
    <div class="m-head"><span>使用說明 — 教育訓練管理</span><span class="m-close" onclick="closeMask('helpUseMask')">✕</span></div>
    <div class="m-body help-doc" style="font-size:13px;color:#5b3a1e;line-height:1.8;">
        <h4>功能說明</h4>
        年度教育訓練的計畫、開課、簽到、評鑑與達標統計；本頁同時是 KPI「人員教育訓練達成率(#19)」的資料來源。
        <h4>操作步驟</h4>
        <b>①計畫</b>：新增訓練場次，填年月、課程、<b>對象部門（可多選，打字搜尋）</b>、講師/開課單位、時數。<br>
        <b>②確認開課</b>：填上課日期時段、地點、課程大綱、評鑑方式、參加人員 → 狀態轉「已排定」，可印簽到表、自動寫入行事曆。<br>
        <b>③登錄完成</b>：上完課勾實到、填評鑑結果（可批次）、上傳簽到表掃描等附件 → 狀態轉「已完成」，才計入達成率。<br>
        <b>④送審計劃表</b>：工具列「送審計劃表」把年度計畫送審核→核准（是否需要送審在模組設定切換）。<br>
        <b>⑤教育訓練需求申請單</b>（2-MM-01-05）：「需求申請」分頁可新增申請單（草稿）、儲存並送出（申請單位主管核准，或模組設定免簽核自動核准），
        訓練管理員將已核准的申請按「轉為計畫」帶入①的新增計畫視窗確認後存檔即完成轉換。<br>
        <b>⑥現場簽到</b>：場次為「已排定」或「已完成」時，清單上會出現「現場簽到」按鈕，開啟後不需要編輯權限，共用一台裝置給學員自己選姓名、輸入<b>本人密碼</b>按 Enter 完成電子簽到（密碼只驗證是不是本人，不是密碼反查身分）。
        <h4>重要行為</h4>
        ・<b>訓練需求申請人</b>是獨立角色（在使用者權限設定的「教育訓練管理」角色指派裡指派），只能新增/送出/檢視申請單，
        看得到訓練場次列表（唯讀）但**不能**修改計畫或任何設定，避免誤把整頁編輯權限一起給出去。<br>
        ・休息時間<b>系統自動算</b>（上課時間 ∩ 休息時段），欄位灰底不可改。<br>
        ・已排定/已完成的場次，<b>計畫內容僅訓練管理員可改</b>。<br>
        ・刪除場次要連續兩次輸入大寫 <b>Y</b>，且會一併刪除附件實體檔。<br>
        ・點清單任一列可展開該場次明細（大綱、時段、名單與評鑑、附件）。<br>
        ・「達標狀況」分頁依顯示單位統計；子部門可合併成一列，也可排除不列入的部門。<br>
        ・「檢視」內的「列印簽到表」「列印考核表」跟「實行資料」modal 裡的同名按鈕輸出完全相同（共用同一份版面），差別只在「檢視」是唯讀場次可隨時重印，不需要先開編輯畫面；外訓或免評鑑（宣導）課程不提供列印考核表。<br>
        ・考核表「總體評核結果」與分數，會自動印出上方「參加人員」名單已填的評鑑結果／分數；未填則留白供現場手寫。
        <h4>設定入口</h4>
        工具列「模組設定」：班別／休息時段／行事曆類別／附件路徑（一般）、排除部門（達標統計）、AS 文件編號與是否送審（文件編號與送審）、<b>簽到表/訓練紀錄的簽名圖章樣式</b>（參加人員本人簽名用，套用「圖章管理→線上圖章設計」哪個模板；未設定＝預設印章樣式）、<b>核准/審核/人事/考官等核准類圖章樣式</b>（跟本人簽名分開設定；未設定＝標準圓形圖章）。<br>
        簽章人員（人事／審核／核准）一律取自全站
        <a href="../admin/org_role_setting.php" target="_blank" style="color:#b5762a;">組織角色綁定設定</a>，本頁不另外設定。
        <h4>權限角色</h4>
        訓練檢閱＝看；訓練登錄＝新增/編輯計畫、開課、登錄完成、送審；訓練管理員＝＋刪除、模組設定、改已定案計畫；管理者全權。
    </div>
    <div class="m-foot"><button class="b-ok" onclick="closeMask('helpUseMask')">關閉</button></div>
</div></div>

<!-- 角色說明 modal -->
<div class="tr-mask" id="helpMask"><div class="tr-modal">
    <div class="m-head"><span>角色權限說明</span><span class="m-close" onclick="closeMask('helpMask')">✕</span></div>
    <div class="m-body" style="font-size:13px;color:#5b3a1e;line-height:1.8;">
        <?php if (!$roleExplain): ?>
            <p style="color:#8a6d45;">尚未建立任何角色，請洽系統管理者到「模組設定 → 角色設定」建立。</p>
        <?php else: foreach ($roleExplain as $re): ?>
            <div><b><?= htmlspecialchars($re[0]) ?></b>：<?= htmlspecialchars($re[1]) ?></div>
        <?php endforeach; endif; ?>
        <p style="font-size:12px;color:#8a6d45;margin:6px 0 0;">以上直接讀取「模組設定 → 角色設定」目前實際存在的角色與勾選內容，改名／合併／刪除角色後這裡會立即跟著變，不會殘留已刪除的角色。</p>
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
<!-- 共用簽章圖章（有上傳掃描章的人自動換成實體章＋動態日期帶）：簽章一律用章，不可只印姓名文字 -->
<script src="../../resource/js/eg_stamp.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_stamp.js') ?>"></script>
<!-- 圖章模板設計器渲染引擎：沒掃描實體章時，模組設定選的「簽名圖章樣式」要靠這支才畫得出來（漏載會靜默退回泛用SVG章） -->
<script src="../../resource/js/eg_stamp_tpl.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_stamp_tpl.js') ?>"></script>
<!-- 西元年日期顯示格式（YYYY.MM.DD）：畫面/列印顯示用，不影響 <input type=date> 與送後端的查詢值，唯一實作見 ai-rules/20 -->
<script src="../../resource/js/eg_date_fmt.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_date_fmt.js') ?>"></script>
<script>
$(document).ready(function(){
    var $am = $('#sidebar-menu .nav.side-menu > li.active');
    if ($am.length) { $am.removeClass('active').find('ul.child_menu').hide(); $am.find('li.current-page').removeClass('current-page'); }
    $('#sidebar-menu').css('visibility','visible');
});

var API = '../../src/store/Training_API.php';
var META = null, ROWS = [], PERMS = null;
var canView = <?= $perms['canView'] ? 'true' : 'false' ?>;
var canApply = <?= $perms['canApply'] ? 'true' : 'false' ?>;
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
function fmtDate(d){ return d ? String(d).substr(0,10) : ''; }   // 內部用（Y-m-d 原始值）：<input type=date>、日期加減、簽到日對照鍵…不可改動格式
function dispDate(d, withTime){ return d ? egFmtDate(d, withTime) : ''; }   // 顯示用（YYYY.MM.DD）：畫面文字/列印文件/圖章日期，唯一實作見 ai-rules/20
function numTrim(v){ if (v==null||v==='') return ''; var n=parseFloat(v); return (Math.round(n*10)/10)+''; }

function loadMeta(cb){
    $.getJSON(API, {action:'meta'}, function(m){
        if (!m.ok){ alert(m.error||'載入失敗'); return; }
        META = m; PERMS = m.perms;
        var $y = $('#yearSel').empty();
        m.years.forEach(function(y){ $y.append('<option value="'+y+'">'+y+'</option>'); });
        $y.val(m.cur_year);
        var $d = $('#deptSel'), $td = $('#edTrainerDept'), $ad = $('#attDept'), $rd = $('#reqDept'), dh='';
        DEPTS = m.departments || [];
        DEPTS.forEach(function(d){
            $d.append('<option value="'+d.id+'">'+esc(d.name)+'</option>');
            $td.append('<option value="'+d.id+'">'+esc(d.name)+'</option>');
            $ad.append('<option value="'+d.id+'">'+esc(d.name)+'</option>');
            $rd.append('<option value="'+d.id+'">'+esc(d.name)+'</option>');
        });
        edDeptRender();
        var $em = $('#edMonth').empty();
        for (var i=1;i<=12;i++) $em.append('<option value="'+i+'">'+i+'月</option>');
        LOCS = m.locations || []; renderLocSel();
        SHIFTS = m.shifts || []; SETTINGS = m.settings || {}; CATS = m.event_categories || [];
        applyBreakSetting();
        ATT_CATS = m.att_cats || {}; EVAL_METHODS = m.eval_methods || {}; OJT_TYPES = m.ojt_item_types || {};
        ATT_DIRS = {nas:m.attach_nas_dir||'', root:m.attach_root||''};
        GROUPS = m.dept_groups || []; UNITS = m.units || [];
        AS_DOCS = m.as_docs || []; DOC_NO = m.doc_no || {}; DOC_NAME = m.doc_name || {}; COMPANY = m.company_name || '';
        SETTINGS.stamp_template = m.stamp_template || null;
        SETTINGS.approval_stamp_template = m.approval_stamp_template || null;
        TR_FEATURES = m.features || [];
        MY_DEPTS = m.my_depts || []; REQ_SIGNERS = m.request_signers || {};
        window.__ownCompany = COMPANY;      // eg_stamp.js 畫章時要用（公司全名）
        SIGNERS = m.plan_signers || {}; PLAN_APPR = m.plan_approval || {status:'none'};
        PLAN_LASTMOD = m.plan_last_modified || '';
        renderPlanStatus();
        var eh='<option value="">（未指定）</option>', ah='';
        $.each(EVAL_METHODS, function(k,v){ eh += '<option value="'+k+'">'+esc(v)+'</option>'; });
        $.each(ATT_CATS, function(k,v){ ah += '<label style="font-size:12px;color:#5b3a1e;margin:0;font-weight:normal;">'
            + '<input type="checkbox" class="at-cat" value="'+k+'"'+(k==='sign'?' checked':'')+'> '+esc(v)+'</label>'; });
        $('#exEvalMethod').html(eh); $('#atCatBox').html(ah);
        CAT_EFF = {internal:m.cat_internal_eff||null, external:m.cat_external_eff||null};
        renderShiftSel();
        if (m.perms.canEdit) $('#btnAdd').show();
        if (m.perms.canAdmin) { $('#btnSetting').show(); $('#btnTarget').show(); $('#btnGroup').show(); }
        if (m.perms.canApply) $('#btnReqAdd').show();
        // 角色設定（改名/勾功能）實際寫入 Roles_API 要求「系統管理者」，訓練管理員(canAdmin)看得到模組設定但這個分頁只給真正的系統管理者
        if (m.perms.isAdmin) $('#setTab4').show();
        applyUrlParams();
        loadRequests();   // 背景先載一次，讓「需求申請」分頁標籤上的待轉計畫數字一開頁就看得到
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
    var ds = (r.days||[]).map(function(d){ return dispDate(d.day_date); }).filter(Boolean).sort();
    if (!ds.length) return dispDate(r.done_date) || '—';
    if (ds.length===1) return ds[0];
    var t = ds[0]+' ~ '+ds[ds.length-1].substr(5)+'<br><span style="font-size:11px;color:#8a6d45;">共 '+ds.length+' 天</span>';
    return '<span title="'+ds.join('、')+'">'+t+'</span>';
}

/* 清單「評鑑」欄：合格/不合格/未評 一眼看出 */
function evalCell(r){
    var e = r.eval || {pass:0,fail:0,exempt:0,none:0};
    if (r.eval_method==='notice') return '<span class="ev-exempt">免評鑑</span>';
    if (!e.pass && !e.fail && !e.exempt && !e.none) return '—';
    var h = '';
    if (e.pass)   h += '<span class="ev-pass">合格 '+e.pass+'</span> ';
    if (e.fail)   h += '<span class="ev-fail">不合格 '+e.fail+'</span> ';
    if (e.exempt) h += '<span class="ev-exempt">免評 '+e.exempt+'</span> ';
    if (e.none)   h += '<span class="ev-none">未評 '+e.none+'</span>';
    return h || '—';
}
function renderTable(){
    var dep = $('#deptSel').val(), stt = $('#statSel').val(), kw = $.trim($('#kwSel').val()).toLowerCase();
    var html = '';
    ROWS.forEach(function(r){
        if (dep && (r.dept_ids||[]).map(String).indexOf(String(dep))<0) return;   // 對象部門複選：任一符合即列出
        if (stt && r.status!==stt) return;
        if (kw && String(r.course_name).toLowerCase().indexOf(kw)<0) return;
        var ext = r.train_type==='external';
        html += '<tr class="tr-clickable'+(r.status==='planned'?' row-planned':'')+'" onclick="toggleDetail(event,'+r.session_id+')">';
        html += '<td>'+r.plan_month+'月</td>';
        html += '<td>'+esc(r.dept_name||'')+'</td>';
        html += '<td class="t-left"><b>'+esc(r.course_name)+'</b>'
             +  (r.attach_count>0 ? ' <span title="已上傳 '+r.attach_count+' 個附件" style="color:#b5762a;font-size:11px;"><i class="fa fa-paperclip"></i>'+r.attach_count+'</span>' : '')
             +  (r.eval_method ? ' <span style="color:#8a6d45;font-size:11px;">['+esc(EVAL_METHODS[r.eval_method]||r.eval_method)+']</span>' : '')
             +  '</td>';
        html += '<td>'+(ext?'<span style="color:#c0762c;">外訓</span>':'內訓')+'</td>';
        html += '<td>'+esc((ext?r.org_unit:r.trainer)||'—')+'</td>';
        // 時數：已排定/已完成有登錄實際時數就顯示實際值（與計畫不同時標示）
        var showH = r.actual_hours!=null ? r.actual_hours : r.hours, diffH = r.actual_hours!=null && r.hours!=null
            && Math.abs(parseFloat(r.actual_hours)-parseFloat(r.hours))>0.05;
        html += '<td'+(diffH?' title="計畫時數 '+numTrim(r.hours)+'，實際 '+numTrim(r.actual_hours)+'"':'')+'>'
             +  (showH==null?'—':numTrim(showH))+(diffH?' <span style="color:#DD5138;">*</span>':'')+'</td>';
        html += '<td>'+(r.cost==null||r.cost===''?'—':numTrim(r.cost))+'</td>';
        html += '<td>'+(r.target_headcount==null?'—':r.target_headcount)+'</td>';
        html += '<td>'+(r.actual_headcount==null?'—':r.actual_headcount)+'</td>';
        html += '<td>'+evalCell(r)+'</td>';
        html += '<td>'+statPill(r.status)+'</td>';
        html += '<td>'+dateRangeText(r)+'</td>';
        html += '<td style="white-space:nowrap;" onclick="event.stopPropagation();">';
        html += '<span class="tr-op" onclick="openView('+r.session_id+')" title="檢視完整內容（含名單與評鑑結果）"><i class="fa fa-search-plus"></i>檢視</span>';
        if (r.status==='scheduled' || r.status==='done') {
            // 免編輯權限：現場裝置給學員自己選人輸入密碼簽到，不需要 training_edit
            html += '<span class="tr-op" onclick="openCheckin('+r.session_id+')" title="現場裝置給學員自己選人輸入本人密碼簽到"><i class="fa fa-pencil-square-o"></i>現場簽到</span>';
        }
        if (PERMS.canEdit) {
            // 已排定/已完成＝計畫已定案，只有訓練管理員可再改計畫內容
            var lock = (r.status==='scheduled' || r.status==='done') && !PERMS.canAdmin;
            html += lock
                ? '<span class="tr-op" style="color:#c4b79c;cursor:not-allowed;" title="已'+(r.status==='done'?'完成':'排定開課')+'，計畫內容僅訓練管理員可修改"><i class="fa fa-lock"></i>計畫</span>'
                : '<span class="tr-op" onclick="openEd('+r.session_id+')" title="修改計畫內容"><i class="fa fa-pencil"></i>計畫</span>';
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
        html += '</td></tr>';
    });
    $('#trBody').html(html || '<tr><td colspan="13" style="padding:16px;color:#8a6d45;">無符合條件的訓練場次</td></tr>');
    OPEN_DET = {};
}
/* ---------- 點列展開明細（課程大綱/時段/名單與評鑑/附件） ---------- */
var OPEN_DET = {};
function toggleDetail(ev, sid){
    var $tr = $(ev.currentTarget);
    if (OPEN_DET[sid]){ $tr.next('tr.det-row').remove(); delete OPEN_DET[sid]; return; }
    OPEN_DET[sid] = 1;
    $tr.after('<tr class="det-row"><td colspan="13"><span style="color:#8a6d45;">載入中…</span></td></tr>');
    var $td = $tr.next('tr.det-row').find('td');
    $.getJSON(API, {action:'session_detail', session_id:sid}, function(res){
        if (!res.ok){ $td.html('<span style="color:#DD5138;">'+esc(res.error||'載入失敗')+'</span>'); return; }
        $td.html(detailHtml(res));
    }).fail(function(){ $td.html('<span style="color:#DD5138;">載入失敗</span>'); });
}
function evalPill(v){
    return v==='pass' ? '<span class="ev-pass">合格</span>'
         : v==='fail' ? '<span class="ev-fail">不合格</span>'
         : v==='exempt' ? '<span class="ev-exempt">免評鑑</span>' : '<span class="ev-none">未評</span>';
}
function detailHtml(res){
    var s=res.session, ext=s.train_type==='external';
    var h='<div class="det-box">';
    h+='<div class="kv"><b>對象部門：</b>'+(res.dept_names&&res.dept_names.length?esc(res.dept_names.join('、')):'全公司')
      +'　<b>'+(ext?'開課單位':'講師')+'：</b>'+esc((ext?s.org_unit:s.trainer)||'—')
      +'　<b>地點：</b>'+esc(s.location||'—')
      +'　<b>評鑑方式：</b>'+(s.eval_method?esc(EVAL_METHODS[s.eval_method]||s.eval_method):'—')
      +'　<b>實際時數：</b>'+(s.actual_hours==null?'—':numTrim(s.actual_hours))+'</div>';
    if (s.outline) h+='<h5>課程大綱</h5><pre class="ol">'+esc(s.outline)+'</pre>';
    if (res.days && res.days.length){
        h+='<h5>上課日期</h5><table><tr><th>第</th><th>日期</th><th>時間</th><th>休息(分)</th><th>時數</th></tr>';
        res.days.forEach(function(d){
            h+='<tr><td>'+d.day_no+'</td><td>'+dispDate(d.day_date)+'</td><td>'+esc((d.start_time||'')+(d.end_time?'~'+d.end_time:''))
             +'</td><td>'+(d.break_minutes||0)+'</td><td>'+(d.hours==null?'':numTrim(d.hours))+'</td></tr>';
        });
        h+='</table>';
    }
    h+='<h5 style="margin-top:8px;">參加人員（'+(res.attendees||[]).length+' 人）</h5>';
    if (!(res.attendees||[]).length) h+='<div style="color:#8a6d45;">尚未建立名單</div>';
    else {
        h+='<table><tr><th>部門</th><th>職稱</th><th>姓名</th><th>實到</th><th>評鑑結果</th><th>分數</th><th>備註</th></tr>';
        res.attendees.forEach(function(a){
            h+='<tr><td>'+esc(a.dept_name||'')+'</td><td>'+esc(a.position_name||'')+'</td><td>'+esc(a.user_name||'')+'</td>'
             +'<td>'+(+a.attended?'✔':'—')+'</td><td>'+evalPill(a.eval_result)+'</td>'
             +'<td>'+(a.eval_score==null?'':numTrim(a.eval_score))+'</td><td>'+esc(a.eval_note||'')+'</td></tr>';
        });
        h+='</table>';
    }
    h+='<h5 style="margin-top:8px;">附件（'+(res.attachments||[]).length+'）</h5>';
    if (!(res.attachments||[]).length) h+='<div style="color:#8a6d45;">無附件</div>';
    else {
        h+='<div>';
        res.attachments.forEach(function(f){
            h+='<div><a href="'+API+'?action=download_attach&att_id='+f.att_id+'" target="_blank" style="color:#b5762a;">'
             +'<i class="fa fa-paperclip"></i> '+esc(f.original_name||f.file_name)+'</a> '
             +'<span style="color:#8a6d45;">['+esc(catLabels(f.cat))+'　'+fmtSize(f.file_size)+']</span></div>';
        });
        h+='</div>';
    }
    h+='</div>';
    return h;
}
function catLabels(cat){
    return String(cat||'').split(',').map(function(c){ return ATT_CATS[c]||c; }).join('、');
}

$('#deptSel,#statSel').on('change', renderTable);
/* 狀態點擊篩選鈕：點一下＝篩選該狀態，再點一次同一個＝取消篩選(顯示全部) */
$('#statFilter .sf-btn').on('click', function(){
    var v = $(this).data('v'), cur = $('#statSel').val();
    $('#statSel').val(cur===v ? '' : v).trigger('change');
    $('#statFilter .sf-btn').removeClass('on');
    if (cur!==v) $(this).addClass('on');
});
$('#kwSel').on('input', renderTable);
$('#yearSel').on('change', function(){
    loadList();
    TGSTATS = null;                                   // 年度一換，達標狀況也要跟著重抓
    if ($('#paneTarget').is(':visible')) loadTargetStats();
    loadPlanStatus();
});

/* ---------- 新增/編輯 ---------- */
var ATT = [];   // 應參加名單 [{user_id,user_name,dept_name,attended,signed}]
var DAY_SIGNS = {};   // 逐日簽到 {user_id_日期: signed_at}，多天課程一天一次；由 day_signs 陣列組出，見 daySignMap()
function daySignMap(rows){ var m={}; (rows||[]).forEach(function(r){ m[r.user_id+'_'+r.day_date]=r.signed_at; }); return m; }
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
    $('#edMask').data('sid', r ? r.session_id : 0).removeData('fromRequestId');
    $('#edFromReqHint').hide();
    $('#edYear').val(r ? r.year : $('#yearSel').val());
    $('#edMonth').val(r ? r.plan_month : (META.cur_month));
    edDeptSet((r && r.dept_ids) ? r.dept_ids : []);
    $('#edDeptSearch').val(''); $('#edDeptList').hide();
    $('#edCourse').val(r ? r.course_name : '');
    $('#edType').val(r ? (r.train_type||'internal') : 'internal'); applyType();
    $('#edTrainer').val(r ? (r.trainer||'') : ''); $('#edTrainerDept').val(''); $('#edTrainerPerson').html('<option value="">人員</option>');
    $('#edOrgUnit').val(r ? (r.org_unit||'') : '');
    $('#edHours').val(r && r.hours!=null ? numTrim(r.hours) : '');
    $('#edCost').val(r && r.cost!=null ? numTrim(r.cost) : '');
    $('#edDays').val(r && r.plan_days!=null ? r.plan_days : '');
    $('#edNote').val(r ? (r.note||'') : '');
    edValidate();
    openMask('edMask');
    setTimeout(function(){ $('#edCourse').focus(); }, 100);
}
$('#btnAdd').on('click', function(){ openEd(0); });
/* 需求申請單「轉為計畫」：預填新增計畫跳窗，讓訓練管理員確認/補齊(講師、內外訓等申請單沒有的欄位)後再存檔 */
function openEdFromRequest(req){
    openEd(0);
    $('#edMask').data('fromRequestId', req.request_id);
    $('#edCourse').val(req.subject);
    var y = (req.start_date||req.apply_date||'').substr(0,4), m = (req.start_date||req.apply_date||'').substr(5,2);
    if (y) $('#edYear').val(y);
    if (m) $('#edMonth').val(parseInt(m,10));
    edDeptSet(req.dept_id ? [req.dept_id] : []);
    if (req.days) $('#edDays').val(req.days);
    if (req.hours!=null && req.hours!=='') $('#edHours').val(numTrim(req.hours));
    // 來源用專屬提示區顯示，不再塞進備註文字；受訓人員與每日時段存檔後由後端自動帶入「確認開課」，屆時仍可增減
    $('#edFromReqHint').html('來源：<b>需求申請單 #'+req.request_id+'</b>（主旨：'+esc(req.subject)+'，申請人：'+esc(req.user_name||'')+'）。'
        + '受訓人員與每日上課時段將於存檔後自動帶入「確認開課」，屆時仍可再增減。').show();
    edValidate();
}
/* ---------- 對象部門：可搜尋多選 ---------- */
var ED_DEPTS = [];      // 已選 dept_id 字串陣列
function edDeptSet(ids){ ED_DEPTS = (ids||[]).map(String); edDeptRender(); }
function edDeptIds(){ return ED_DEPTS.slice(); }
function edDeptAll(on){
    ED_DEPTS = on ? DEPTS.map(function(d){ return String(d.id); }) : [];
    edDeptRender();
}
function edDeptRender(){
    var h='';
    ED_DEPTS.forEach(function(id){
        var d=deptById(id); if(!d) return;
        h += '<span class="tg">'+esc(d.name)+'<i class="fa fa-times" onclick="edDeptDel(\''+id+'\')"></i></span>';
    });
    $('#edDeptTags').html(h);
    $('#edDeptSearch').attr('placeholder', ED_DEPTS.length ? '再加入部門…' : '未選＝全公司；輸入部門名稱搜尋…');
}
function edDeptDel(id){ ED_DEPTS = ED_DEPTS.filter(function(x){ return x!==String(id); }); edDeptRender(); edDeptList(); }
function edDeptList(){
    var kw=$.trim($('#edDeptSearch').val()).toLowerCase(), h='';
    DEPTS.forEach(function(d){
        if (kw && String(d.name).toLowerCase().indexOf(kw)<0) return;
        var on = ED_DEPTS.indexOf(String(d.id))>=0;
        h += '<div class="'+(on?'on':'')+'" data-id="'+d.id+'">'+(on?'✔ ':'')+esc(d.name)+'</div>';
    });
    $('#edDeptList').html(h||'<div style="color:#b0a390;">查無部門</div>').show();
}
$('#edDeptSearch').on('focus input', edDeptList);
$(document).on('click', '#edDeptList div[data-id]', function(){
    var id=String($(this).data('id'));
    if (ED_DEPTS.indexOf(id)<0) ED_DEPTS.push(id); else edDeptDel(id);
    edDeptRender(); edDeptList(); $('#edDeptSearch').val('').focus();
});
$(document).on('click', function(e){ if (!$(e.target).closest('#edDeptPick').length) $('#edDeptList').hide(); });
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
/* 點「確認實行/實行資料」一律先向後端要一次這筆場次的最新狀態才開窗——
   避免 A 剛把這筆改成「已排定/已完成」，B 手上還是舊快取、點開來還是「計畫中」的畫面繼續填，
   兩人同時存檔會互相覆蓋或重複寫入。發現狀態跟畫面上顯示的不一樣，就擋下來、告知、刷新清單，不開窗。
   （鐵則見 ai-rules/08 第六節：會造成資料重複/動作重複又存不進去的按鈕，一律點下去當下先向後端抓最新狀態） */
function openEx(sid){
    var was = (ROWS.find(function(x){ return String(x.session_id)===String(sid); })||{}).status;
    NProgress.start();
    $.getJSON(API, {action:'session_detail', session_id:sid}, function(res){
        NProgress.done();
        if (!res.ok){ alert(res.error||'載入失敗，請重試'); return; }
        var idx = ROWS.findIndex(function(x){ return String(x.session_id)===String(sid); });
        var fresh = $.extend({}, idx>=0?ROWS[idx]:{}, res.session);
        fresh.days = res.days;
        fresh.dept_name = (res.dept_names&&res.dept_names.length) ? res.dept_names.join('、') : (fresh.dept_id==null?'全公司':fresh.dept_name);
        if (idx>=0) ROWS[idx]=fresh; else ROWS.push(fresh);
        if (was && was!==fresh.status){
            renderTable();
            alert('這筆場次的狀態剛被更新為「'+(STATUS_LABEL[fresh.status]||fresh.status)+'」（可能是其他人剛處理過），畫面已重新整理，請確認最新狀態後再操作。');
            return;
        }
        openExBody(sid);
    }).fail(function(){ NProgress.done(); alert('載入失敗，請重試'); });
}
function openExBody(sid){
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
    // OJT/實作口試考核表：僅內訓；建立/編輯限本場講師本人或訓練管理員（見 ai-rules/08 第六節同精神：以目前狀態判定，不猜權限）
    OJT_EDITABLE = !ext && !!(PERMS.canAdmin || (r.trainer_id && +r.trainer_id === +(META.uid||0)));
    $('#ojtSec').toggle(!ext);
    $('#ojtLockHint').toggle(!ext && !OJT_EDITABLE);
    $('#ojtOps').toggle(OJT_EDITABLE);
    $('#ojtAssessor').prop('disabled', !OJT_EDITABLE);
    if (!ext) loadOjt(r.session_id); else { OJT_ITEMS = []; }
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
    $('#exOutline').val(r.outline||'');
    $('#exEvalMethod').val(r.eval_method||''); applyEvalMethod();
    loadAttach(r.session_id);
    renderDays();
    $('#attDept').val(''); $('#attPeopleBox').html('<span class="empty">選部門載入人員</span>');
    $('#attPickAll').prop('checked', false);
    ATT = [];
    $('#attNote').text('');
    DAY_SIGNS = {};
    $.getJSON(API, {action:'get_attendees', session_id:r.session_id}, function(res){
        if (res.ok) ATT = res.attendees.map(function(a){ return {user_id:+a.user_id, user_name:a.user_name, dept_name:a.dept_name,
            position_name:a.position_name||'', attended:+a.attended, signed:+a.signed, signed_at:a.signed_at||'',
            eval_result:a.eval_result||'', eval_score:(a.eval_score==null?'':numTrim(a.eval_score)), eval_note:a.eval_note||''}; });
        if (res.ok) DAY_SIGNS = daySignMap(res.day_signs);
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
            // 只改這一列的幾個 input（不可整表重繪）——change 是「按 Enter/↑↓ 跳到下一欄」時才觸發的，
            // 重繪會把剛拿到焦點的那個欄位整個換掉，游標就掉了（使用者回報「Enter 沒跳下一欄」的真正原因）。
            var $in = $('#dayBody tr').eq(i).find('input');
            $in.eq(1).val(DAYS[i].start); $in.eq(2).val(DAYS[i].end);
            $in.eq(3).val(DAYS[i].brk);   $in.eq(4).val(DAYS[i].hours);
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
/* 補舊資料時挑人要用「當時」的職務（依職務異動紀錄解析，ai-rules/14）：以上課首日為準 */
function classAtDate(){
    if (DAYS.length && DAYS[0].date) return DAYS[0].date;
    return $('#exDone').val() || '';
}
/* 講師：部門→人員（帶上課日期，過去日期會依當時職務列人） */
$('#edTrainerDept').on('change', function(){
    var did=$(this).val(); var $p=$('#edTrainerPerson').html('<option value="">人員</option>');
    if(did) $.getJSON(API,{action:'people',dept_id:did,at_date:classAtDate()},function(res){ if(res.ok) res.people.forEach(function(u){ $p.append('<option value="'+u.id+'">'+esc(u.user_cname)+'</option>'); }); });
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
    $.getJSON(API,{action:'people',dept_id:did,at_date:classAtDate()},function(res){
        if(!res.ok){ $b.html('<span class="empty">載入失敗</span>'); return; }
        var deptName=$('#attDept option:selected').text();
        var h='';
        // at_date＝上課首日是過去日期時，後端依職務異動紀錄解析「當時」在此部門的人與當時職稱（補舊資料用）
        if (res.at_date) h+='<div style="flex-basis:100%;color:#8a6d45;font-size:12px;">名單依 '+esc(res.at_date)+' 當時職務顯示（依職務異動紀錄解析；已離職但當時在職者也會列出）</div>';
        res.people.forEach(function(u){
            var pos=u.position_name||'';
            if (isTrainer(u.id, u.user_cname)){          // 講師：顯示但不可勾（讓人看得出來是刻意排除的）
                h+='<label style="color:#b0a390;" title="本場講師，不列入參加人員"><input type="checkbox" disabled> '
                  +esc(u.user_cname)+'（講師，不列入）</label>';
                return;
            }
            var inList=ATT.some(function(a){return a.user_id===+u.id;});
            h+='<label><input type="checkbox" class="att-ck" value="'+u.id+'" data-name="'+esc(u.user_cname)+'" data-dept="'+esc(deptName)+'" data-pos="'+esc(pos)+'"'+(inList?' checked disabled':'')+'> '
              +esc(u.user_cname)+(pos?'<span style="color:#8a6d45;">（'+esc(pos)+'）</span>':'')
              +(+u.resigned?'<span style="color:#b0722a;">（已離職）</span>':'')+(inList?'(已加)':'')+'</label>';
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
                      position_name:$(this).data('pos')||'', attended:0, signed:0,
                      eval_result:'', eval_score:'', eval_note:''});
    });
    renderAtt();
    $('#attDept').trigger('change');
}
/* ---------- 評鑑結果（合格/不合格，可批次設定；宣導課程一律免評鑑） ---------- */
var EVAL_LABEL = {pass:'合格', fail:'不合格', exempt:'免評鑑'};
function isNoticeCourse(){ return $('#exEvalMethod').val()==='notice'; }
/* 選了「宣導（免評鑑）」→ 評鑑欄整組鎖住並標成免評鑑，避免現場還去一個個點 */
function applyEvalMethod(){
    var m = $('#exEvalMethod').val(), notice = (m==='notice');
    $('#exEvalHint').html(m==='' ? '未指定評鑑方式（仍可自行填每個人的評鑑結果）'
        : (notice ? '<b style="color:#8A5A2B;">宣導課程免評鑑</b>，參加人員一律記「免評鑑」'
                  : '評鑑方式：<b>'+esc(EVAL_METHODS[m]||m)+'</b>；上完課於下方名單逐人填合格／不合格（可批次）'));
    $('#evalBatchBox').css('opacity', notice?0.5:1);
    $('#evalBatchBox button, #evalOnlyAttended').prop('disabled', notice);
    // 宣導(免評鑑)課程不需要考核表：直接隱藏整個考核表區塊，避免建了考核項目卻用不到
    if (notice) $('#ojtSec').hide();
    else if (EXROW && EXROW.train_type!=='external') $('#ojtSec').show();
    renderAtt();
}
$('#exEvalMethod').on('change', applyEvalMethod);
function attAttendAll(v){ ATT.forEach(function(a){ a.attended = v?1:0; }); renderAtt(); }
function attEvalAll(v){
    var only = $('#evalOnlyAttended').prop('checked');
    ATT.forEach(function(a){ if (only && v!=='' && !a.attended) return; a.eval_result = v; });
    renderAtt();
}
function renderAtt(){
    var h='', notice=isNoticeCourse();
    ATT.forEach(function(a,i){
        var ev = notice ? 'exempt' : (a.eval_result||'');
        var sel = '<select onchange="ATT['+i+'].eval_result=this.value;attCount()"'+(notice?' disabled':'')+'>'
                + '<option value=""'+(ev===''?' selected':'')+'>—</option>'
                + '<option value="pass"'+(ev==='pass'?' selected':'')+'>合格</option>'
                + '<option value="fail"'+(ev==='fail'?' selected':'')+'>不合格</option>'
                + '<option value="exempt"'+(ev==='exempt'?' selected':'')+'>免評鑑</option></select>';
        h+='<tr><td>'+esc(a.dept_name||'')+'</td><td>'+esc(a.position_name||'—')+'</td>'
          +'<td class="t-left">'+esc(a.user_name||'')+'</td>'
          +'<td><input type="checkbox" '+(a.attended?'checked':'')+' onchange="ATT['+i+'].attended=this.checked?1:0;attCount()"></td>'
          +'<td>'+sel+'</td>'
          +'<td><input type="number" step="any" min="0" max="100" style="width:52px;" value="'+esc(a.eval_score==null?'':a.eval_score)
          +'" onchange="ATT['+i+'].eval_score=this.value;attCount()"'+(notice?' disabled':'')+'></td>'
          +'<td><input type="text" maxlength="100" style="width:112px;" value="'+esc(a.eval_note||'')
          +'" onchange="ATT['+i+'].eval_note=this.value"></td>'
          +'<td>'+(a.signed?'<span style="color:#8A5A2B;">已簽</span>':'—')+'</td>'
          +'<td><span class="att-del" onclick="attDel('+i+')"><i class="fa fa-times"></i></span></td></tr>';
    });
    $('#attBody').html(h||'<tr><td colspan="9" style="color:#8a6d45;padding:8px;">尚未加入人員</td></tr>');
    attCount();
}
function attCount(){
    var a=ATT.filter(function(x){return x.attended;}).length;
    $('#attCount').text('（應到 '+ATT.length+'　實到 '+a+'）');
    var notice=isNoticeCourse();
    var p=ATT.filter(function(x){return notice||x.eval_result==='pass';}).length;
    var f=ATT.filter(function(x){return !notice&&x.eval_result==='fail';}).length;
    var n=ATT.filter(function(x){return !notice&&!x.eval_result;}).length;
    $('#evalSummary').text(ATT.length ? '合格 '+p+'　不合格 '+f+(n?'　未評 '+n:'') : '');
}
function attDel(i){ ATT.splice(i,1); renderAtt(); if($('#attDept').val()) $('#attDept').trigger('change'); }

/* ================= 年度訓練計劃表：送審與簽章（見 ai-rules/17） ================= */
var AS_DOCS = [], DOC_NO = {}, DOC_NAME = {}, COMPANY = '', SIGNERS = {}, PLAN_APPR = {status:'none'}, PLAN_LASTMOD = '';
var APPR_LABEL = {none:'尚未送審', review_pending:'審核中', reviewed:'審核通過，待核准',
                  approve_pending:'待核准', approved:'已核准', rejected:'已退回'};
function loadPlanStatus(){
    $.getJSON(API, {action:'plan_status', year:$('#yearSel').val()}, function(res){
        if (!res.ok) return;
        PLAN_APPR = res.approval || {status:'none'};
        SIGNERS = res.signers || {};
        PLAN_LASTMOD = res.plan_last_modified || '';
        if (SETTINGS) SETTINGS.training_plan_sign_date = res.plan_sign_date || '';
        if (SETTINGS) SETTINGS.training_need_approval = res.need_approval;
        renderPlanStatus();
    });
}
function renderPlanStatus(){
    var s = (PLAN_APPR && PLAN_APPR.status) || 'none', need = +(SETTINGS.training_need_approval||0);
    var txt = '計劃表：' + (APPR_LABEL[s]||s);
    if (s==='rejected'){
        var r = (PLAN_APPR.approve && PLAN_APPR.approve.status==='rejected') ? PLAN_APPR.approve : PLAN_APPR.review;
        txt += '（' + (r && r.approver_name ? r.approver_name : '') + '：' + ((r && r.note) || '') + '）';
    } else if (s==='approved'){
        var a = PLAN_APPR.approve || {};
        txt += '（' + (a.approver_name||'') + '　' + dispDate(a.decided_at) + '）';
    }
    $('#planStat').html('<span style="color:'+(s==='rejected'?'#DD5138':(s==='approved'?'#7a5217':'#8a6d45'))+';">'+esc(txt)+'</span>');
    if (PERMS && PERMS.canEdit) {
        var busy = (s==='review_pending' || s==='approve_pending' || s==='approved');
        $('#btnSubmitPlan').toggle(!busy).text(need ? ' 送審計劃表' : ' 確認計劃表(免送審)')
            .prepend('<i class="fa fa-paper-plane"></i>');
    }
}
$('#btnSubmitPlan').on('click', function(){
    var need = +(SETTINGS.training_need_approval||0);
    if (!confirm(need ? '將 '+$('#yearSel').val()+' 年度訓練計劃表送出審核？\n（依序通知：'
                 + ((SIGNERS.reviewer&&SIGNERS.reviewer.name)||'未設定審核者') + ' 審核 → '
                 + ((SIGNERS.approver&&SIGNERS.approver.name)||'未設定核准者') + ' 核准）'
                 : '模組設定為「不需送審」，確認後計劃表即視同完成、列印時直接顯示全部簽章（日期＝今天）。要繼續嗎？')) return;
    $.post(API, {action:'plan_submit', year:$('#yearSel').val()}, function(res){
        if (!res.ok){ alert(res.error||'送審失敗'); return; }
        loadPlanStatus();
        alert(res.status==='approved' ? '已確認完成（免送審）。' : '已送出審核，已通知審核人員。');
    }, 'json').fail(function(x){ alert('送審失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
});
/* 模組設定的三個分頁 */
$(document).on('click', '.set-tab', function(){
    var p=$(this).data('p');
    $('.set-tab').removeClass('on'); $(this).addClass('on');
    $('#setPane1,#setPane2,#setPane3,#setPane4').hide(); $('#setPane'+p).show();
    if (String(p)==='4' && !ROLES.length) loadRoles();
});

/* ================= 達標狀況（各單位每月/每年內外訓次數） ================= */
var UNITS = [], GROUPS = [], DEPTS = [], TGSTATS = null, TGTARGETS = {}, TGMON = 0;   // TGMON: 0=全年
/* 只綁主分頁（設定跳窗內也有 .tab，不能一起被清掉選取狀態） */
/* 切換分頁＝所有分頁的資料一律重新整理（不只是使用者切過去看的那個），只是畫面仍停在切換到的那個分頁。
   起因：A 已把「計畫中」場次確認開課，B 若還停在舊快取，點開的仍是「計畫中」的確認實行畫面，
   兩人同時送出容易互相覆蓋／重複寫入。切分頁是使用者操作中最自然會發生、頻率也夠高的時機，藉此順便同步全頁資料。 */
$('#mainTabs .tab').on('click', function(){
    var t = $(this).data('tab');
    $('#mainTabs .tab').removeClass('on'); $(this).addClass('on');
    $('#paneList').toggle(t==='list'); $('#paneTarget').toggle(t==='target'); $('#paneApply').toggle(t==='apply');
    // 列印鈕依目前分頁切換，一次只出現一顆（需求申請單改列印個別單據，見清單操作欄）
    $('#btnPrintPlan').toggle(t==='list');
    $('#btnPrintResult').toggle(t==='target');
    loadList(); loadTargetStats(); loadRequests();
});
function loadTargetStats(){
    NProgress.start();
    $.getJSON(API, {action:'target_stats', year:$('#yearSel').val()}, function(res){
        NProgress.done();
        if (!res.ok){ alert(res.error||'載入失敗'); return; }
        TGSTATS = res.stats; TGTARGETS = res.targets||{}; UNITS = res.units||UNITS;
        renderMonTabs(); renderTargetTable();
    }).fail(function(){ NProgress.done(); });
}
function renderMonTabs(){
    var h = '<span class="mt'+(TGMON===0?' on':'')+'" onclick="setMon(0)">全年</span>';
    for (var m=1;m<=12;m++){
        var has=false;
        (TGSTATS||[]).forEach(function(s){ if (s.months.internal[m].done+s.months.internal[m].plan
                                             + s.months.external[m].done+s.months.external[m].plan) has=true; });
        h += '<span class="mt'+(TGMON===m?' on':'')+(has?' has':'')+'" onclick="setMon('+m+')">'+m+'月</span>';
    }
    $('#monTabs').html(h);
}
function setMon(m){ TGMON=m; renderMonTabs(); renderTargetTable(); }
/* 目標文字：每月N次 / 每年N次；0＝未設定 */
function targetText(p, n){ return n>0 ? (p==='month'?'每月 ':'每年 ')+n+' 次' : '未設定'; }
function renderTargetTable(){
    if (!TGSTATS){ $('#tgBody').html('<tr><td style="padding:16px;color:#8a6d45;">載入中…</td></tr>'); return; }
    var m = TGMON, head, body='';
    if (m===0){
        // 月份當表頭、下方直接放次數；0 不顯示（避免整片 0/0 看不出重點）
        var mh = '';
        for (var k=1;k<=12;k++) mh += '<th style="width:36px;">'+k+'月</th>';
        head = '<tr><th class="t-left">單位</th><th>內訓目標</th><th>內訓完成</th><th>外訓目標</th><th>外訓完成</th>'
             + '<th>達標</th>' + mh + '</tr>';
        TGSTATS.forEach(function(s){
            var t=s.target, okI=yearOk(s,'internal'), okE=yearOk(s,'external');
            var cells='';
            for (var i=1;i<=12;i++){
                var di=s.months.internal[i], de=s.months.external[i];
                var done=(di.done||0)+(de.done||0), pl=(di.plan||0)+(de.plan||0);
                var txt = done ? '<b>'+done+'</b>' : '';
                if (pl) txt += '<span style="color:#c0762c;font-size:11px;">('+pl+')</span>';
                cells += '<td title="'+i+'月：完成 '+done+' 場'+(pl?'、待辦 '+pl+' 場':'')+'">'+txt+'</td>';
            }
            body += '<tr><td class="t-left">'+esc(s.unit.name)+(s.unit.is_group?'<span style="color:#8a6d45;font-size:11px;">（合併）</span>':'')+'</td>'
                 + '<td>'+targetText(t.internal_period,t.internal_times)+'</td>'
                 + '<td><b>'+s.year_done.internal+'</b></td>'
                 + '<td>'+targetText(t.external_period,t.external_times)+'</td>'
                 + '<td><b>'+s.year_done.external+'</b></td>'
                 + '<td>'+okLabel(okI && okE)+'</td>' + cells + '</tr>';
        });
    } else {
        head = '<tr><th class="t-left">單位</th><th>內訓目標</th><th>'+m+'月完成</th><th>外訓目標</th><th>'+m+'月完成</th>'
             + '<th>達標</th><th class="t-left">'+m+'月場次（含尚未完成）</th></tr>';
        TGSTATS.forEach(function(s){
            var t=s.target, di=s.months.internal[m], de=s.months.external[m];
            var okI=monOk(s,'internal',m), okE=monOk(s,'external',m);
            var items=[].concat(di.items||[], de.items||[]).map(function(it){
                return '<span class="mon-pill" title="'+STATUS_LABEL[it.status]+'">'+esc(it.course_name)
                     + '<b> '+STATUS_LABEL[it.status]+'</b>'+(it.company_wide?'<span style="color:#8a6d45;">(全公司)</span>':'')+'</span>';
            }).join(' ');
            body += '<tr><td class="t-left">'+esc(s.unit.name)+(s.unit.is_group?'<span style="color:#8a6d45;font-size:11px;">（合併）</span>':'')+'</td>'
                 + '<td>'+targetText(t.internal_period,t.internal_times)+'</td>'
                 + '<td><b>'+di.done+'</b>'+(di.plan?'<span style="color:#c0762c;"> +'+di.plan+' 待辦</span>':'')+'</td>'
                 + '<td>'+targetText(t.external_period,t.external_times)+'</td>'
                 + '<td><b>'+de.done+'</b>'+(de.plan?'<span style="color:#c0762c;"> +'+de.plan+' 待辦</span>':'')+'</td>'
                 + '<td>'+okLabel(okI && okE)+'</td>'
                 + '<td class="t-left">'+(items||'<span style="color:#b0a390;">本月無場次</span>')+'</td></tr>';
        });
    }
    $('#tgHead').html(head);
    $('#tgBody').html(body || '<tr><td style="padding:16px;color:#8a6d45;">尚無部門資料</td></tr>');
}
function okLabel(ok){ return ok ? '<span class="ok-yes">達標</span>' : '<span class="ok-no">未達標</span>'; }
/* 每月制→該月要達到 N 次；每年制→年度累計要達到 N 次（未設定目標視為達標） */
function monOk(s, type, m){
    var t=s.target, p=t[type+'_period'], n=t[type+'_times'];
    if (!n) return true;
    return p==='month' ? s.months[type][m].done >= n : s.year_done[type] >= n;
}
function yearOk(s, type){
    var t=s.target, p=t[type+'_period'], n=t[type+'_times'];
    if (!n) return true;
    if (p==='year') return s.year_done[type] >= n;
    for (var m=1;m<=12;m++) if (s.months[type][m].done < n) return false;   // 每月制＝每個月都要達標
    return true;
}

/* ---------- 目標次數設定（限管理員） ---------- */
$('#btnTarget').on('click', function(){
    if (!TGSTATS) { loadTargetStats(); setTimeout(openTargetSet, 600); } else openTargetSet();
});
function openTargetSet(){
    $('#tgYear').text($('#yearSel').val());
    var rows = [{key:'ALL', name:'全公司統一（預設值）'}].concat((UNITS||[]).map(function(u){
        return {key:u.key, name:u.name + (u.is_group?'（合併）':'')};
    }));
    var h='';
    rows.forEach(function(r){
        var t = TGTARGETS[r.key] || null, isAll = r.key==='ALL';
        var ip = t? t.internal_period : 'year', it = t? +t.internal_times : 0;
        var ep = t? t.external_period : 'year', et = t? +t.external_times : 0;
        h+='<tr data-key="'+r.key+'"'+(isAll?' style="background:#FFF7E8;"':'')+'><td class="t-left"><b>'+esc(r.name)+'</b></td>'
          +'<td style="text-align:center;">'+(isAll?'—':'<input type="checkbox" class="tg-def"'+(t?'':' checked')+'>')+'</td>'
          +'<td class="tg-cell"><select class="tg-ip"><option value="year"'+(ip==='year'?' selected':'')+'>每年</option>'
          +'<option value="month"'+(ip==='month'?' selected':'')+'>每月</option></select>'
          +'<input type="number" class="tg-it" min="0" max="999" value="'+it+'"><span>次</span></td>'
          +'<td class="tg-cell"><select class="tg-ep"><option value="year"'+(ep==='year'?' selected':'')+'>每年</option>'
          +'<option value="month"'+(ep==='month'?' selected':'')+'>每月</option></select>'
          +'<input type="number" class="tg-et" min="0" max="999" value="'+et+'"><span>次</span></td></tr>';
    });
    $('#tgSetBody').html(h);
    $('#tgSetBody').off('change.def').on('change.def', '.tg-def', function(){
        $(this).closest('tr').find('.tg-ip,.tg-it,.tg-ep,.tg-et').prop('disabled', this.checked);
    });
    $('#tgSetBody .tg-def').trigger('change');
    openMask('tgMask');
}
function saveTargets(){
    var list=[];
    $('#tgSetBody tr').each(function(){
        var $t=$(this);
        list.push({unit_key:$t.data('key'), use_default:$t.find('.tg-def').prop('checked')?1:0,
            internal_period:$t.find('.tg-ip').val(), internal_times:$t.find('.tg-it').val(),
            external_period:$t.find('.tg-ep').val(), external_times:$t.find('.tg-et').val()});
    });
    $.post(API, {action:'save_targets', year:$('#yearSel').val(), targets:JSON.stringify(list)}, function(res){
        if (!res.ok){ alert(res.error||'儲存失敗'); return; }
        closeMask('tgMask'); TGSTATS=null; loadTargetStats(); alert('目標已儲存。');
    }, 'json').fail(function(x){ alert('儲存失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}

/* ---------- 部門合併設定（限管理員） ---------- */
$('#btnGroup').on('click', function(){ renderGroups(GROUPS); openMask('grpMask'); });
function renderGroups(gs){
    var h='';
    (gs||[]).forEach(function(g,i){ h += groupBoxHtml(g, i); });
    $('#grpList').html(h || '<div style="color:#8a6d45;font-size:12px;margin-bottom:6px;">尚未設定合併群組（各部門各自獨立統計）</div>');
    $('#grpList .grp-box').each(function(i){ grpSyncName($(this), (gs[i]||{}).group_name); });
}
function groupBoxHtml(g, i){
    var ids=(g.dept_ids||[]).map(String);
    var chk='';
    DEPTS.forEach(function(d){
        chk += '<label><input type="checkbox" class="gp-dept" value="'+d.id+'"'+(ids.indexOf(String(d.id))>=0?' checked':'')+'> '+esc(d.name)+'</label>';
    });
    return '<div class="batch-box grp-box" data-gid="'+(g.group_id||0)+'" style="display:block;">'
        + '<div style="display:flex;gap:6px;align-items:center;margin-bottom:4px;flex-wrap:wrap;">'
        + '<b>顯示名稱</b><select class="gp-name" style="min-width:170px;height:28px;border:1px solid #D8BE93;border-radius:4px;"></select>'
        + '<span class="gp-hint" style="font-size:11.5px;color:#8a6d45;"></span>'
        + '<span class="att-del" style="margin-left:auto;" onclick="$(this).closest(\'.grp-box\').remove();" title="移除此群組"><i class="fa fa-times"></i></span></div>'
        + '<div class="att-people">'+chk+'</div></div>';
}
/* 群組名稱＝自動認定成員中「最高層級」的部門（department.level 最小；同層級時讓使用者選） */
function deptById(id){ for (var i=0;i<DEPTS.length;i++) if (String(DEPTS[i].id)===String(id)) return DEPTS[i]; return null; }
function grpSyncName($box, keep){
    var ids=[]; $box.find('.gp-dept:checked').each(function(){ ids.push(this.value); });
    var $sel=$box.find('.gp-name'), cur = keep || $sel.val();
    if (!ids.length){ $sel.html('<option value="">（請先勾選部門）</option>'); $box.find('.gp-hint').text(''); return; }
    var top = null;
    ids.forEach(function(id){ var d=deptById(id); if(!d) return;
        var lv = d.level==null ? 99 : +d.level;
        if (top===null || lv < top) top = lv; });
    var tops = ids.map(deptById).filter(function(d){ return d && (d.level==null?99:+d.level)===top; });
    var h='';
    tops.forEach(function(d){ h += '<option value="'+esc(d.name)+'"'+(cur===d.name?' selected':'')+'>'+esc(d.name)+'</option>'; });
    $sel.html(h);
    if (cur && tops.some(function(d){ return d.name===cur; })) $sel.val(cur);
    $box.find('.gp-hint').text(tops.length>1
        ? '有 '+tops.length+' 個同層級部門，請選一個當顯示名稱'
        : '自動採用最高層級部門「'+tops[0].name+'」');
}
$(document).on('change', '.grp-box .gp-dept', function(){ grpSyncName($(this).closest('.grp-box')); });
function grpAdd(){
    if (!$('#grpList .grp-box').length) $('#grpList').empty();
    $('#grpList').append(groupBoxHtml({group_id:0, group_name:'', dept_ids:[]}, 0));
    grpSyncName($('#grpList .grp-box').last());
}
function saveGroups(){
    var list=[], dup={}, bad='';
    $('#grpList .grp-box').each(function(){
        var $b=$(this), ids=[];
        $b.find('.gp-dept:checked').each(function(){
            if (dup[this.value]) bad = '同一個部門不可同時屬於兩個群組';
            dup[this.value]=1; ids.push(+this.value);
        });
        var nm=$.trim($b.find('.gp-name').val()||'');
        if (!nm && ids.length) bad = '有群組沒有選顯示名稱';
        if (nm && ids.length) list.push({group_id:+$b.data('gid'), group_name:nm, dept_ids:ids});
    });
    if (bad){ alert(bad); return; }
    $.post(API, {action:'save_dept_groups', groups:JSON.stringify(list)}, function(res){
        if (!res.ok){ alert(res.error||'儲存失敗'); return; }
        GROUPS = res.dept_groups||[]; UNITS = res.units||[];
        closeMask('grpMask'); TGSTATS=null; loadTargetStats(); alert('合併設定已儲存。');
    }, 'json').fail(function(x){ alert('儲存失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}

/* ---------- 場次附件（簽到表掃描/教材/試卷）：DB 只存檔名，路徑由後端即時組（鐵律5） ---------- */
var ATT_CATS = {}, EVAL_METHODS = {}, ATT_DIRS = {nas:'', url:''};
var OJT_ITEMS = [], OJT_TYPES = {}, OJT_EDITABLE = false;
var FILES = [], TEMP_ATT = [];      // TEMP_ATT＝場次還沒 id 時的暫存附件（本頁場次一定已存在，保留機制備用）
function fmtSize(n){
    n = +n||0;
    return n<1024 ? n+' B' : (n<1048576 ? (Math.round(n/102.4)/10)+' KB' : (Math.round(n/104857.6)/10)+' MB');
}
/* ---------- OJT／實作口試考核表：考核項目清單（僅內訓，講師本人或訓練管理員可編輯） ---------- */
function loadOjt(sid){
    OJT_ITEMS = []; renderOjt();
    $('#ojtAssessor').val('');
    if (!sid) return;
    $.getJSON(API, {action:'ojt_list', session_id:sid}, function(res){
        if (!res.ok) return;
        OJT_ITEMS = (res.items||[]).map(function(it){ return {item_type:it.item_type, content:it.content}; });
        $('#ojtAssessor').val(res.assessor_name || (EXROW&&EXROW.trainer) || '');
        renderOjt();
    });
}
function renderOjt(){
    var editable = OJT_EDITABLE, h='';
    OJT_ITEMS.forEach(function(it,i){
        h+='<tr><td>'+(i+1)+'</td>'
          +'<td><input type="text" maxlength="200" placeholder="例：[實作] 能否正確完成開機並登入 MES？" value="'+esc(it.content||'')+'"'
          +' onchange="ojtEdit('+i+',\'content\',this.value)"'+(editable?'':' disabled')+'></td>'
          +'<td><select onchange="ojtEdit('+i+',\'item_type\',this.value)"'+(editable?'':' disabled')+'>'
          + Object.keys(OJT_TYPES).map(function(k){ return '<option value="'+k+'"'+(it.item_type===k?' selected':'')+'>'+esc(OJT_TYPES[k])+'</option>'; }).join('')
          + '</select></td>'
          +'<td>'+(editable?'<span class="att-del" onclick="ojtDel('+i+')"><i class="fa fa-times"></i></span>':'')+'</td></tr>';
    });
    $('#ojtBody').html(h || '<tr><td colspan="4" style="color:#8a6d45;padding:6px;">尚未建立考核項目</td></tr>');
    $('#ojtCount').text(OJT_ITEMS.length ? '（共 '+OJT_ITEMS.length+' 項）' : '');
}
function ojtEdit(i, key, val){ if (!OJT_ITEMS[i]) return; OJT_ITEMS[i][key] = val; }
function ojtAdd(){ if (!OJT_EDITABLE) return; OJT_ITEMS.push({item_type:'practice', content:''}); renderOjt(); }
function ojtDelLast(){ if (!OJT_EDITABLE || !OJT_ITEMS.length) return; OJT_ITEMS.pop(); renderOjt(); }
function ojtDel(i){ if (!OJT_EDITABLE) return; OJT_ITEMS.splice(i,1); renderOjt(); }
function ojtSave(){
    if (!OJT_EDITABLE){ alert('此場次的 OJT 考核項目僅本場講師本人或訓練管理員可編輯'); return; }
    var sid = $('#exMask').data('sid');
    var bad = OJT_ITEMS.some(function(it){ return !$.trim(it.content||''); });
    if (bad){ alert('有考核項目未填寫內容，請填寫或刪除該列'); return; }
    $('#ojtMsg').text('儲存中…');
    $.post(API, {action:'ojt_save', session_id:sid, assessor_name:$('#ojtAssessor').val(), items:JSON.stringify(OJT_ITEMS)},
        function(res){
            if (!res.ok){ alert(res.error||'儲存失敗'); $('#ojtMsg').text(''); return; }
            $('#ojtMsg').text('已儲存'); setTimeout(function(){ $('#ojtMsg').text(''); }, 3000);
        }, 'json').fail(function(x){ alert('儲存失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); $('#ojtMsg').text(''); });
}
/* 列印：一人一份，考核日期固定為課程最後一天；各考核細項仍留白供現場手寫評分（現場口試/實作逐項考核用途不變），
   但表尾「總體評核結果」若參加人員名單（實行紀錄）已填評鑑結果／分數，直接印出既有結果，不必再手抄一次；未填則留白供事後填寫。
   每份考核表卡片標記「不可被分頁切開」，實際每頁塞幾份交給瀏覽器依內容高度原生分頁決定，
   不用考核項目數猜頁數（猜錯會跟瀏覽器實際分頁打架，見 print_pagination 鐵則）。
   src：選用，訓練場次檢視（唯讀）呼叫時帶入 {r,days,attendees,ojtItems,assessor} 取代 EXROW/DAYS/ATT/OJT_ITEMS 與表單欄位當下值，
   確保「訓練場次檢視」與「實行資料」的列印考核表輸出一致。 */
function printOjtSheet(src){
    var r = (src && src.r) || EXROW || {};
    if (r.train_type==='external'){ alert('外訓不提供考核表'); return; }
    var items = src ? (src.ojtItems||[]) : OJT_ITEMS;
    if (!items.length){ alert('請先建立至少一項考核項目並儲存'); return; }
    var days = src ? (src.days||[]) : DAYS;
    var attendees = src ? (src.attendees||[]) : ATT;
    var list = attendees.length ? attendees.slice() : [];
    if (!list.length){ alert('尚未加入參加人員，無法列印'); return; }
    var course = r.course_name || '（課程名稱）';
    var lastDay = dispDate((days.length ? days[days.length-1].date : '') || r.done_date || '');
    var assessor = (src ? src.assessor : $('#ojtAssessor').val()) || r.trainer || '';
    var loc = src ? (r.location||'') : ($('#exLocSel option:selected').text() || r.location || '');
    var itemRows = items.map(function(it,i){
        return '<tr><td>'+(i+1)+'</td><td class="t-left">'+esc(it.content||'')+'</td>'
             + '<td>'+esc(OJT_TYPES[it.item_type]||it.item_type)+'</td>'
             + '<td></td><td style="white-space:nowrap;">☐合格　☐不合格</td><td></td></tr>';
    }).join('');
    var html = '';
    list.forEach(function(a, idx){
        // 總體評核結果：已有 eval_result 就直接印出明確結論（粗體+色塊，不用 ☑/☐ 勾選框——那組符號在部分印表機/字型下
        // 「已勾」「未勾」兩態幾乎印不出視覺差異，改印文字結論才不會讓人分不清楚）；還沒填的維持空白雙選框供現場手寫。
        var scoreTxt = (a.eval_score!=null && a.eval_score!=='') ? '　總分：'+esc(numTrim(a.eval_score)) : '';
        var resultLine;
        if (a.eval_result==='pass') resultLine = '總體評核結果：<b style="color:#cf3a2b;">判定合格（已具備獨立作業能力）</b>'+scoreTxt;
        else if (a.eval_result==='fail') resultLine = '總體評核結果：<b style="color:#cf3a2b;">需再進行補訓／複考</b>'+scoreTxt;
        else if (a.eval_result==='exempt') resultLine = '總體評核結果：<b style="color:#cf3a2b;">列為免評鑑</b>';
        else resultLine = '總體評核結果：☐ 判定合格（已具備獨立作業能力）　☐ 需再進行補訓／複考';
        html += '<div class="pg"><table class="sf"><thead>'
            + '<tr><th colspan="6" style="border:none;padding:0;"><div class="pt-head"><div class="co">'+esc(COMPANY)+'</div>'
            + '<div class="tt">考核表</div></div></th></tr>'
            + '<tr><td colspan="6" class="sf-i">課程名稱：'+esc(course)+'　　地點：'+esc(loc||'—')+'　　考核日期：'+esc(lastDay||'____.__.__')+'</td></tr>'
            + '<tr><td colspan="6" class="sf-i">受訓人員：'+esc(a.dept_name||'')+'　'+esc(a.position_name||'')+'　'+esc(a.user_name||'')
            + '　　<b>☐ 未到考（未到考者以下免填）</b></td></tr>'
            + '<tr><th style="width:32px;">項次</th><th>考核／口試重點</th><th style="width:76px;">方式</th>'
            + '<th style="width:50px;">分數</th><th style="width:150px;">評鑑結果</th><th style="width:100px;">備註</th></tr>'
            + '</thead><tbody>'+itemRows+'</tbody></table>'
            + '<div style="margin-top:8px;font-size:13px;">'+resultLine+'</div>'
            + '<div style="margin-top:16px;font-size:13px;display:flex;align-items:flex-end;gap:10px;">考官簽章：'+egApprovalStampHtml(assessor, lastDay)+'</div>'
            + '</div>';
    });
    var css = 'table.sf{width:100%;border-collapse:collapse;font-size:13px;margin-top:8px;}'
        + 'table.sf th,table.sf td{border:1px solid #333;padding:6px;text-align:center;}'
        + 'table.sf td.t-left{text-align:left;}'
        + 'table.sf td.sf-i{border:1px solid #999;padding:5px 8px;text-align:left;font-size:12.5px;background:#fff;}'
        + '.pg{margin-bottom:14px;page-break-inside:avoid;break-inside:avoid;}';
    egPrintWindow('考核表', html, css, '', false, true);
}
function loadAttach(sid){
    FILES = []; renderAttach();
    if (!sid) return;
    $.getJSON(API, {action:'list_attach', session_id:sid}, function(res){
        if (res.ok){ FILES = res.attachments||[]; renderAttach(); }
    });
}
function renderAttach(){
    var h='';
    FILES.forEach(function(f){
        h+='<tr><td>'+esc(catLabels(f.cat))+'</td>'
          +'<td class="t-left"><a href="'+API+'?action=download_attach&att_id='+f.att_id+'" target="_blank" style="color:#b5762a;">'
          +'<i class="fa fa-paperclip"></i> '+esc(f.original_name||f.file_name)+'</a></td>'
          +'<td>'+fmtSize(f.file_size)+'</td><td>'+esc(f.user_name||'')+'</td>'
          +'<td>'+esc(dispDate(f.created_at, true))+'</td>'
          +'<td>'+(PERMS && PERMS.canEdit ? '<span class="att-del" onclick="attachDel('+f.att_id+')" title="刪除附件"><i class="fa fa-times"></i></span>' : '')+'</td></tr>';
    });
    $('#atBody').html(h||'<tr><td colspan="6" style="color:#8a6d45;padding:8px;">尚未上傳附件</td></tr>');
    $('#atCount').text(FILES.length ? '（'+FILES.length+' 個檔案）' : '');
}
$('#atFile').on('change', function(){
    var files = this.files, sid = $('#exMask').data('sid');
    if (!files || !files.length) return;
    var cats = [];                                  // 類別可複選（同一份 PDF 可能同時是簽到表＋試卷）
    $('#atCatBox .at-cat:checked').each(function(){ cats.push(this.value); });
    if (!cats.length){ alert('請至少勾選一個附件類別'); this.value=''; return; }
    var cat = cats.join(','), done = 0, fail = [];
    $('#atMsg').text('上傳中… 0/'+files.length);
    var upload = function(idx){
        if (idx >= files.length){
            $('#atMsg').text(fail.length ? ('完成，'+fail.length+' 個失敗：'+fail.join('、')) : '上傳完成');
            setTimeout(function(){ $('#atMsg').text(''); }, 4000);
            loadAttach(sid);
            return;
        }
        var fd = new FormData();
        fd.append('action','upload_attach'); fd.append('session_id', sid||0); fd.append('cat', cat);
        fd.append('file', files[idx]);
        $.ajax({url:API, type:'POST', data:fd, processData:false, contentType:false, dataType:'json'})
         .done(function(res){ if(!res.ok) fail.push(files[idx].name+'('+(res.error||'')+')');
                              else if(!sid) TEMP_ATT.push(res.att_id); })
         .fail(function(x){ fail.push(files[idx].name+'('+((x.responseJSON&&x.responseJSON.error)||x.status)+')'); })
         .always(function(){ done++; $('#atMsg').text('上傳中… '+done+'/'+files.length); upload(idx+1); });
    };
    upload(0);
    this.value = '';        // 同一個檔案可以再選一次
});
function attachDel(aid){
    if (!confirm('刪除此附件？（實體檔一併刪除，無法復原）')) return;
    $.post(API, {action:'del_attach', att_id:aid}, function(res){
        if (!res.ok){ alert(res.error||'刪除失敗'); return; }
        loadAttach($('#exMask').data('sid'));
    }, 'json').fail(function(x){ alert('刪除失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}

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
        year:$('#edYear').val(), plan_month:$('#edMonth').val(), dept_ids:edDeptIds().join(','),
        course_name:$('#edCourse').val(), train_type:$('#edType').val(),
        trainer:$('#edTrainer').val(), trainer_id:$('#edTrainerPerson').val(), org_unit:$('#edOrgUnit').val(),
        hours:$('#edHours').val(), cost:$('#edCost').val(), plan_days:$('#edDays').val(), note:$('#edNote').val()},
    function(res){
        if (!res.ok){ alert(res.error||'儲存失敗'); return; }
        var fromReq = $('#edMask').data('fromRequestId');
        closeMask('edMask'); loadList();
        if (fromReq) {   // 由需求申請單轉來的計畫：存檔成功後回填申請單狀態＝已轉
            $.post(API, {action:'request_mark_converted', request_id:fromReq, session_id:res.session_id}, function(){
                $('#edMask').removeData('fromRequestId'); loadRequests();
            }, 'json');
        }
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
    if (markDone && $('#exEvalMethod').val()!=='notice'){
        var noEval = ATT.filter(function(a){ return a.attended && !a.eval_result; }).length;
        if (noEval && !confirm('還有 '+noEval+' 位實到人員沒有填評鑑結果（合格／不合格），仍要登錄完成？')) return;
    }
    $.post(API, {action:'save_execution', session_id:sid, location:$('#exLocSel').val(),
        shift_type_id:$('#exShift').val(), outline:$('#exOutline').val(),
        eval_method:$('#exEvalMethod').val(), temp_att_ids:TEMP_ATT.join(','),
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
    $('#setAttNas').val(ATT_DIRS.nas||''); $('#setAttRoot').text(ATT_DIRS.root||'');
    // 達標統計：排除部門
    var ex = String(SETTINGS.training_exclude_depts||'').split(',');
    var eh='';
    DEPTS.forEach(function(d){
        eh += '<label><input type="checkbox" class="set-ex" value="'+d.id+'"'+(ex.indexOf(String(d.id))>=0?' checked':'')+'> '+esc(d.name)+'</label>';
    });
    $('#setExDept').html(eh);
    // AS 文件編號綁定
    var dh='<option value="">（未對應）</option>';
    (AS_DOCS||[]).forEach(function(d){ dh += '<option value="'+d.id+'">'+esc(d.doc_no+'　'+d.doc_name)+'</option>'; });
    $('#setDocPlan').html(dh).val(SETTINGS.training_as_doc_plan||'');
    $('#setDocResult').html(dh).val(SETTINGS.training_as_doc_result||'');
    $('#setDocTarget').html(dh).val(SETTINGS.training_as_doc_target||'');
    $('#setDocRequest').html(dh).val(SETTINGS.training_as_doc_request||'');
    $('#setDocSignsheet').html(dh).val(SETTINGS.training_as_doc_signsheet||'');
    loadStampTplOptions();
    var sbr = String(SETTINGS.training_signsheet_blank_rows||'0');
    if (sbr==='fill16'){ $('#setSignBlankMode').val('fill16'); $('#setSignBlankN').val(''); }
    else if (sbr && sbr!=='0'){ $('#setSignBlankMode').val('fixed'); $('#setSignBlankN').val(sbr); }
    else { $('#setSignBlankMode').val('0'); $('#setSignBlankN').val(''); }
    $('#setSignBlankMode').trigger('change');
    $('#setNeedAppr').val(String(SETTINGS.training_need_approval||0));
    $('#setReqNeedAppr').val(String(SETTINGS.training_request_need_approval==null?1:SETTINGS.training_request_need_approval));
    $('#setSgReview').text(SIGNERS.reviewer ? SIGNERS.reviewer.name : '（未設定審核者）');
    $('#setSgApprover').text(SIGNERS.approver ? SIGNERS.approver.name : '（未設定核准者）');
    $('#setSignDate').val(SETTINGS.training_plan_sign_date || '');
    $('#setSignAuto').text(PLAN_LASTMOD ? '（留空時自動採用：'+PLAN_LASTMOD+'）' : '（本年度尚無資料，留空時採用今天）');
    $('#setBrkStart').val(SETTINGS.training_break_start||'');
    $('#setBrkEnd').val(SETTINGS.training_break_end||'');
    setBrkCheck();
    var nm=function(id){ var f=CATS.filter(function(c){ return String(c.id)===String(id); }); return f.length?f[0].category_name:'（找不到）'; };
    $('#setCatEff').html('目前實際使用：內訓＝'+(CAT_EFF.internal?esc(nm(CAT_EFF.internal))+'（id '+CAT_EFF.internal+'）':'未設定→不寫行事曆')
        +'　外訓＝'+(CAT_EFF.external?esc(nm(CAT_EFF.external))+'（id '+CAT_EFF.external+'）':'未設定→不寫行事曆'));
    openMask('setMask');
}
$('#btnSetting').on('click', openSetting);
$('#setSignBlankMode').on('change', function(){ $('#setSignBlankN').toggle(this.value==='fixed'); });
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
    var exIds=[]; $('#setExDept .set-ex:checked').each(function(){ exIds.push(this.value); });
    $.post(API, {action:'save_settings', default_shift_id:$('#setShift').val(),
        cat_internal:$('#setCatIn').val(), cat_external:$('#setCatEx').val(),
        break_start:bs.val, break_end:be.val, exclude_depts:exIds.join(','),
        as_doc_plan:$('#setDocPlan').val(), as_doc_result:$('#setDocResult').val(),
        as_doc_target:$('#setDocTarget').val(), need_approval:$('#setNeedAppr').val(),
        as_doc_request:$('#setDocRequest').val(), request_need_approval:$('#setReqNeedAppr').val(),
        as_doc_signsheet:$('#setDocSignsheet').val(), stamp_tpl_id:$('#setStampTpl').val(),
        approval_stamp_tpl_id:$('#setApprovalStampTpl').val(),
        signsheet_blank_rows:(function(){ var m=$('#setSignBlankMode').val();
            return m==='fill16' ? 'fill16' : (m==='fixed' ? ($('#setSignBlankN').val()||'0') : '0'); })(),
        plan_sign_date:$('#setSignDate').val()}, function(res){
        if (!res.ok){ alert(res.error||'設定儲存失敗'); return; }
        SETTINGS = res.settings||{}; UNITS = res.units||UNITS; DOC_NO = res.doc_no||DOC_NO; DOC_NAME = res.doc_name||DOC_NAME;
        SETTINGS.stamp_template = res.stamp_template || null;
        SETTINGS.approval_stamp_template = res.approval_stamp_template || null;
        TGSTATS = null; if ($('#paneTarget').is(':visible')) loadTargetStats();
        loadPlanStatus();
        CAT_EFF = {internal:res.cat_internal_eff||null, external:res.cat_external_eff||null};
        renderShiftSel(); applyBreakSetting(); renderReqFlowHint(); if (REQUESTS) renderRequestList();
        if (DAYS.length){ DAYS.forEach(function(d){ dayRecalc(d); }); renderDays(); }   // 設定改了，開著的實行畫面同步重算
        saveAttachPath(function(){ closeMask('setMask');
            alert('設定已儲存。日後行事曆類別改名不影響綁定（存的是類別 id）；附件路徑改變不影響舊附件（DB 只存檔名）。'); });
    }, 'json').fail(function(x){ alert('設定儲存失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}
/* 簽到表/訓練紀錄等自動產生印章要套用的模板：SETTINGS.stamp_template 由 meta/save_settings 帶回（見 trStampSchema/egStampHtml）；
   核准/審核/人事/考官等核准類圖章是另一組獨立設定 SETTINGS.approval_stamp_template（見 trApprovalStampSchema/egApprovalStampHtml），
   兩邊都用同一份模板清單（圖章管理→線上圖章設計建立的模板），只是各自記各自選的 id，互不影響。 */
function loadStampTplOptions(){
    $.getJSON(API, {action:'stamp_tpl_options'}, function(res){
        if (!res.ok) return;
        var opt = function(selId, defLabel){
            var cur = selId ? (SETTINGS[selId] ? SETTINGS[selId].id : 0) : 0;
            var h = '<option value="0">'+defLabel+'</option>';
            (res.templates||[]).forEach(function(t){
                h += '<option value="'+t.id+'"'+(String(t.id)===String(cur)?' selected':'')+'>'+(t.type_name?esc(t.type_name)+'｜':'')+esc(t.tpl_name)+'</option>';
            });
            return h;
        };
        $('#setStampTpl').html(opt('stamp_template', '（預設印章樣式）'));
        $('#setApprovalStampTpl').html(opt('approval_stamp_template', '（預設圓形圖章）'));
    });
}
/* 附件路徑（限訓練管理員；沒改就不打這支 API） */
function saveAttachPath(cb){
    var nas=$.trim($('#setAttNas').val());
    if (!PERMS || !PERMS.canAdmin || nas===ATT_DIRS.nas) { cb(); return; }
    if (!nas){ alert('附件儲存路徑不可為空（未修改附件路徑，其餘設定已儲存）'); cb(); return; }
    $.post(API, {action:'save_attach_path', nas_dir:nas}, function(res){
        if (!res.ok){ alert('附件路徑儲存失敗：'+(res.error||'')); cb(); return; }
        ATT_DIRS.nas = res.attach_nas_dir;
        cb();
    }, 'json').fail(function(x){ alert('附件路徑儲存失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); cb(); });
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
/* 列印簽到表（確認實行 modal 中的場次＋名單；「訓練場次檢視」的列印簽到表也共用本函式，見 viewPrintSrc()，
   確保兩處輸出一致，不重複維護兩份版面）
   多天課程＝一天一頁（每天各自簽名），單天＝一頁 */
/* 簽到表：學員自己的簽名欄就是簽到證明，不需要另外留一行講師/主管簽名底線；依 ai-rules/16 綁 AS 文件編號 */
/* blankOnly=true：不帶目前名單，整張印成空白列供現場手寫（開課前臨時要一張空白簽到表時用）。
   src：選用，訓練場次檢視（唯讀）呼叫時帶入 {r,days,attendees,daySigns}取代 EXROW/DAYS/ATT/DAY_SIGNS 與表單欄位當下值。 */
/* 課程資訊放進 <thead>（跟欄位標題同一張表），若當天簽到表人數多印到第二頁，
   瀏覽器分頁引擎會把整個 <thead> 原樣重印在每一頁——不必自己判斷有沒有跨頁，也不會有「第二頁不知道在簽什麼」的問題。
   多天課程：一天仍是一個獨立表格（page-break-before 換頁），該表格自己的表頭永遠只描述那一天，跨頁時也還是同一天的資訊。 */
function printSignSheet(blankOnly, src){
    var r = (src && src.r) || EXROW || {};
    var docTitle = DOC_NAME.signsheet || '簽到表';   // 有綁定AS文件時表頭一律用其doc_name，不寫死（ai-rules/16 第一之二節）
    var course=r.course_name||'（課程名稱）';
    var ext=r.train_type==='external';
    var lect=ext?('外訓／開課單位：'+(r.org_unit||'')):('講師：'+(r.trainer||''));
    var loc=(src ? r.location : $('#exLocSel').val())||'____________';
    var attendees = src ? (src.attendees||[]) : ATT;
    var daySigns = src ? (src.daySigns||{}) : DAY_SIGNS;
    // 空白列：模組設定可選「不加」／「固定加 N 列」／「補到滿頁最多16列」，絕不刪減實際名單；
    // 空白簽到表模式一律印滿（沿用設定的固定列數，未設定或設不加時退回 16 列，畢竟印「空白」表就是要給人寫的）。
    var list = blankOnly ? [] : (attendees.length ? attendees.slice() : []);
    var blankMode = String(SETTINGS.training_signsheet_blank_rows||'0');
    var blanks = 0;
    if (blankMode==='fill16') blanks = Math.max(0, 16 - list.length);
    else { var bn = parseInt(blankMode,10); if (!isNaN(bn) && bn>0) blanks = Math.min(16, bn); }
    if (blankOnly && blanks===0) blanks = 16;
    for (var bi=0; bi<blanks; bi++) list.push({});
    var days = src ? (src.days||[]) : DAYS;
    var ds=(days.length?days:[{date:'', start:'', end:'', hours:''}]);
    var em=src ? r.eval_method : $('#exEvalMethod').val(), emLabel=em?(EVAL_METHODS[em]||em):'（未設定）', noticeCourse=(em==='notice');
    var outline=$.trim((src ? r.outline : $('#exOutline').val())||'');
    // 多天課程：每個表頭都附一行「全部上課日期」，方便第 3 天才簽的人也看得到整體排程；
    // 天數不多(≤6)逐一列出、換行不了就用頓號分隔；太多天(>6)改用「首~末（共N天）」範圍格式，避免那一行印成一長串。
    var allDates = ds.map(function(d){ return dispDate(d.date)||'?'; });
    var allDatesLine = ds.length<=1 ? '' : (ds.length<=6
        ? '全部上課日期：'+allDates.join('、')
        : '全部上課日期：'+allDates[0]+' ~ '+allDates[allDates.length-1]+'（共 '+ds.length+' 天）');
    var html='';
    ds.forEach(function(d, di){
        var tm=(d.start||'')+(d.end?'~'+d.end:'');
        var hh=d.hours||'';
        var when='日期：'+(dispDate(d.date)||'____.__.__')+(tm?'　'+tm:'')+'　時數：'+(hh||'__')+' 小時';
        var rows='';
        list.forEach(function(a,i){
            // 評鑑結果一律印成空白勾選框讓現場圈選（紙本才是正本；線上已填的另有系統紀錄）；宣導(免評鑑)課程直接印「不須評鑑」不留勾選框
            // 簽名欄：該天現場密碼簽到過的人印出簽到章(章上日期＝該堂課的上課日，不是按鈕按下當下的日期)；還沒簽到的人維持空白供紙本簽名
            var daySigned = a.user_id!=null && d.date && daySigns[a.user_id+'_'+d.date];
            rows+='<tr><td>'+(i+1)+'</td><td>'+esc(a.dept_name||'')+'</td><td>'+esc(a.position_name||'')+'</td><td>'+esc(a.user_name||'')+'</td>'
                +'<td style="width:130px;">'+(daySigned?egStampHtml(a.user_name, dispDate(d.date), false, a.dept_name):'')+'</td>'
                +'<td style="width:112px;white-space:nowrap;">'+(noticeCourse?'不須評鑑':'☐ 合格　☐ 不合格')+'</td>'
                +'<td style="width:120px;"></td></tr>';
        });
        html+='<div class="pg'+(di>0?' pgbrk':'')+'">'
            +'<table class="sf"><thead>'
            +'<tr><th colspan="7" style="border:none;padding:0;"><div class="pt-head"><div class="co">'+esc(COMPANY)+'</div>'
            +'<div class="tt">'+esc(docTitle)+'</div></div></th></tr>'
            +'<tr><td colspan="7" class="sf-i">課程名稱：'+esc(course)+(ds.length>1?'　（第 '+(di+1)+' / '+ds.length+' 天）':'')+'</td></tr>'
            +(allDatesLine?'<tr><td colspan="7" class="sf-i">'+esc(allDatesLine)+'</td></tr>':'')
            +'<tr><td colspan="7" class="sf-i">評鑑方式：'+esc(emLabel)+'</td></tr>'
            +'<tr><td colspan="7" class="sf-i">'+esc(lect)+'　地點：'+esc(loc)+'</td></tr>'
            +'<tr><td colspan="7" class="sf-i">'+esc(when)+'</td></tr>'
            +(outline?'<tr><td colspan="7" class="sf-i ol">課程大綱：'+esc(outline)+'</td></tr>':'')
            +'<tr><th style="width:36px;">序</th><th>部門</th><th>職稱</th><th>姓名</th><th>簽名</th>'
            +'<th>評鑑結果</th><th>備註</th></tr>'
            +'</thead><tbody>'+rows+'</tbody></table>'
            +'</div>';
    });
    // 簽名＋簽日期要有足夠空間書寫：字級加大、列高至少 1.5 倍（30px→46px）
    var css='table.sf{width:100%;border-collapse:collapse;font-size:15px;margin-top:8px;}'
        +'table.sf th,table.sf td{border:1px solid #333;padding:10px 6px;text-align:center;height:46px;overflow:hidden;}'
        +'table.sf td.sf-i{border:1px solid #999;padding:5px 8px;text-align:left;font-size:13px;height:auto;background:#fff;overflow:visible;}'
        +'table.sf td.sf-i.ol{white-space:pre-wrap;line-height:1.5;}'
        // 簽到章不可把列高撐大：列高固定跟空白簽到表一樣，章縮小塞進既有列高內（覆蓋 egPrintWindow 內建的 91px 預設章尺寸）
        // 掃描實體章/泛用SVG章沒有模板自帶的填滿比例可用，這裡統一給 90% 當退回值；有模板 fillRatio 的走 eg_stamp.js 自己的 inline style（優先權更高，不受這裡影響）
        +'table.sf .stamp-wrap{height:90%;display:inline-flex;align-items:center;margin:0;}table.sf .stamp-wrap svg,table.sf svg.car-stamp{height:100%;width:auto;}'
        +'.pgbrk{page-break-before:always;}';
    // 人數多會跨頁，用 pageCount 模式（真頁碼＋表頭自動重印每一頁）
    egPrintWindow(docTitle, html, css, r.as_doc_signsheet_no||DOC_NO.signsheet, false, true);
}
/* 刪除：兩次都要輸入大寫 Y 才執行（連同上課日、參加名單、附件實體檔一起刪，無法復原） */
/* ================= 列印（依 ai-rules/16：大標題＝公司全名、頁碼左下、AS文件編號右下） ================= */
/* 共用列印視窗：
   ・@page margin:0 ＋ body padding ＝ 瀏覽器不再印自己的頁首/頁尾（網址、標題、日期），版面才乾淨
   ・大標題（公司全名）與副標題（表單名稱）拉出字級層次
   ・文件編號印在每頁右下（position:fixed 會在每一頁重複）
   ・欄寬用 table-layout:fixed 明確配置，避免中文被擠成一長條直排 */
/* pageCount=true：確定會分頁的報表（如簽到表人數多會跨頁）要用這個模式——
   放棄「margin:0 藏瀏覽器頁首頁尾」的乾淨版面，換取真的「第 X 頁／共 Y 頁」（ai-rules/16 四之二已預留此二選一）。
   此時課程資訊務必寫在該表格的 <thead> 裡（不是外面另一張表），瀏覽器分頁引擎才會自動把 thead 原樣重印在每一頁。 */
function egPrintWindow(title, bodyHtml, extraCss, docNo, landscape, pageCount){
    var asCss = String(docNo||'').replace(/['\\]/g,'');   // 塞進 CSS content 字串用
    var asHtml = esc(String(docNo||''));                   // 塞進 HTML 用
    var css = '@page{size:A4 '+(landscape?'landscape':'portrait')+';'
            + (pageCount
                ? 'margin:12mm 8mm 16mm;'
                  + " @bottom-left{ content:'第 ' counter(page) ' 頁／共 ' counter(pages) ' 頁'; font-size:9pt; color:#333; }"
                  + (asCss ? " @bottom-right{ content:'"+asCss+"'; font-size:9pt; color:#333; }" : '')
                : 'margin:0;')
            + '}'
            + (pageCount ? '' : 'html,body{margin:0;padding:0;}')
            + 'body{font-family:"Microsoft JhengHei","微軟正黑體",sans-serif;color:#000;'
            + (pageCount ? '' : 'padding:10mm 8mm 12mm;') + '-webkit-print-color-adjust:exact;print-color-adjust:exact;}'
            + '.pt-head{text-align:center;margin-bottom:6px;}'
            + '.pt-head .co{font-size:22px;font-weight:bold;letter-spacing:2px;}'
            + '.pt-head .tt{font-size:16px;font-weight:bold;margin-top:3px;letter-spacing:1px;}'
            + '.pt-head .sub{font-size:11px;color:#444;margin-top:2px;}'
            + 'table.pt{width:100%;border-collapse:collapse;font-size:12px;margin-top:6px;table-layout:fixed;}'
            + 'table.pt th,table.pt td{border:1px solid #333;padding:3px 4px;text-align:center;'
            + 'word-wrap:break-word;overflow-wrap:break-word;line-height:1.35;}'
            + 'table.pt th{background:#EFEFEF;font-weight:bold;}'
            + 'table.pt td.l{text-align:left;}'
            + '.pt-legend{font-size:11px;margin-top:6px;line-height:1.7;}'
            + '.pt-sign{margin-top:12px;font-size:12px;width:100%;border-collapse:collapse;table-layout:fixed;}'
            + '.pt-sign td{border:1px solid #333;height:76px;vertical-align:top;padding:3px 6px;width:33.33%;}'
            + '.pt-sign .lb{font-size:11px;color:#333;}'
            + '.pt-sign .stamp-box{text-align:center;margin-top:2px;}'
            + '.stamp-wrap svg,svg.car-stamp{width:91px;height:91px;}'
            + '.pt-foot{position:fixed;right:8mm;bottom:5mm;font-size:9pt;color:#333;}'
            + (extraCss||'');
    var w = window.open('', '_blank');
    if (!w){ alert('請允許彈出視窗'); return; }
    w.document.write('<html><head><meta charset="utf-8"><title>'+esc(title)+'</title><style>'+css+'</style></head><body>'
        + bodyHtml + (!pageCount && asHtml ? '<div class="pt-foot">'+asHtml+'</div>' : '')
        + '<scr'+'ipt>window.onload=function(){setTimeout(function(){window.print();},200);};</scr'+'ipt></body></html>');
    w.document.close();
}
/* 簽章區：核准（最高核准人員）／審核（人事表單審核者）／人事（人事簽章人員）
   免送審＝三欄一起顯示、日期同送審日；需送審＝依實際簽核結果顯示 */
/* 簽名圖章樣式：模組設定選的「圖章管理→線上圖章設計」模板（未設定＝null，EGStamp.stamp 會退回預設印章樣式） */
function trStampSchema(){ return SETTINGS.stamp_template ? SETTINGS.stamp_template.schema : null; }
/* 產生一顆簽章圖章（走共用 eg_stamp.js；載不到時退回姓名文字，至少不會整格空白）；
   有上傳掃描實體章的人一律優先用掃描章，trStampSchema() 只影響沒掃描章時自動產生的印章樣式。 */
function egStampHtml(name, date, isDeputy, dept){
    try {
        if (window.EGStamp && EGStamp.stamp) return EGStamp.stamp(name, date || '', !!isDeputy, trStampSchema(), dept);
    } catch (e) {}
    return '<span style="font-size:14px;">'+esc(name)+'</span>'
         + (date ? '<div style="font-size:10px;color:#555;">'+esc(date)+'</div>' : '');
}
/* 核准/審核/人事/考官等「核准類」圖章樣式：跟上面「參加人員本人簽名」是分開的兩組設定（SETTINGS.approval_stamp_template），
   未設定＝EGStamp.stamp 沒有 schema 可套時退回標準圓形回墨印，不會誤用「人員簽章(長方)」那種本人簽名樣式。 */
function trApprovalStampSchema(){ return SETTINGS.approval_stamp_template ? SETTINGS.approval_stamp_template.schema : null; }
function egApprovalStampHtml(name, date, isDeputy){
    try {
        if (window.EGStamp && EGStamp.stamp) return EGStamp.stamp(name, date || '', !!isDeputy, trApprovalStampSchema());
    } catch (e) {}
    return '<span style="font-size:14px;">'+esc(name)+'</span>'
         + (date ? '<div style="font-size:10px;color:#555;">'+esc(date)+'</div>' : '');
}
/* 年度計畫表的圖章日期（免送審＝設定的簽章日期/計畫最後異動日/今天；需送審＝實際簽核日期），
   訓練計畫表的「已完成✔」判定也要用同一個日期基準：圖章日期(含)之後才實施的不算數(見 printPlanTable)。 */
function planSignDate(){
    var s = PLAN_APPR || {}, need = +(SETTINGS.training_need_approval||0);
    var ap = s.approve || {}, rv = s.review || {};
    var d10 = function(x){ return String(x||'').substr(0,10); };
    var dt = d10(ap.decided_at || ap.submitted_at || rv.submitted_at);
    if (!need || !dt) dt = d10(SETTINGS.training_plan_sign_date || PLAN_LASTMOD || META.today);
    return dt;
}
function signRowHtml(){
    var s = PLAN_APPR || {}, need = +(SETTINGS.training_need_approval||0);
    var ap = s.approve || {}, rv = s.review || {};
    var dt = planSignDate(), appr, rev, hr;
    if (!need) {
        // 免送審：三個簽章欄直接顯示綁定的人（不必先按送審）
        appr = (SIGNERS.approver && SIGNERS.approver.name) || '';
        rev  = (SIGNERS.reviewer && SIGNERS.reviewer.name) || '';
        hr   = (SIGNERS.hr_signer && SIGNERS.hr_signer.name) || '';
    } else {
        // 需送審：依實際簽核結果顯示，沒簽到的欄位留白給紙本蓋章
        appr = (s.status==='approved') ? (ap.approver_name||'') : '';
        rev  = (s.status==='approved' || s.status==='approve_pending' || s.status==='reviewed') ? (rv.approver_name||'') : '';
        hr   = (SIGNERS.hr_signer && SIGNERS.hr_signer.name) || '';
    }
    // 簽章一律蓋「圖章」（有上傳掃描章的人自動用實體章，其餘用共用回墨印 SVG），不是印姓名文字
    var cell = function(lb, nm){
        var st = nm ? egApprovalStampHtml(nm, dispDate(dt)) : '';
        return '<td><div class="lb">'+lb+'</div><div class="stamp-box">'+st+'</div></td>';
    };
    return '<table class="pt-sign"><tr>'+cell('核准', appr)+cell('審核', rev)+cell('人事', hr)+'</tr></table>';
}
/* ① 年度教育訓練計畫表（AS 必備）：計畫月份用 ◎、已實施用 ✔ */
function printPlanTable(){
    var year=$('#yearSel').val(), rows=ROWS.filter(function(r){ return r.status!=='cancelled'; });
    signWarn();
    var docTitle = DOC_NAME.plan || '教育訓練計畫表';   // 表頭一律用綁定AS文件的doc_name，不寫死（ai-rules/16 第一之二節）
    var body='<div class="pt-head"><div class="co">'+esc(COMPANY)+'</div>'
           + '<div class="tt">'+esc(docTitle)+'</div></div>'
           + '<div style="text-align:left;font-size:13px;font-weight:bold;margin:4px 0 2px;">'+year+' 年</div>';
    // 欄寬用 % 明確配置（table-layout:fixed），課程名稱與訓練對象留足寬度才不會被擠成直排
    var cols = '<colgroup><col style="width:3%"><col style="width:20%"><col style="width:12%"><col style="width:6%">';
    for (var c=1;c<=12;c++) cols += '<col style="width:3%">';
    cols += '<col style="width:8%"><col style="width:13%"></colgroup>';
    var mh=''; for (var m=1;m<=12;m++) mh += '<th>'+m+'</th>';
    body += '<table class="pt">'+cols+'<thead>'
         + '<tr><th rowspan="2">NO.</th><th rowspan="2">課程名稱</th><th rowspan="2">訓練對象</th>'
         + '<th rowspan="2">實施方式</th><th colspan="12">計畫實施月份</th><th rowspan="2">費用</th><th rowspan="2">備註</th></tr>'
         + '<tr>'+mh+'</tr></thead><tbody>';
    if (!rows.length) body += '<tr><td colspan="18" style="height:24px;">（本年度尚無訓練計畫）</td></tr>';
    var signDt = planSignDate();
    rows.forEach(function(r,i){
        var doneM = {};
        if (r.status==='done') {
            // 圖章日期(含)之後才實施的不算「已完成」——本表印出來的日期就是圖章日期，
            // 之後才做完的事在蓋章當下還沒發生，不可以顯示成已完成。
            var ds=(r.days||[]).map(function(d){ return fmtDate(d.day_date); }).filter(Boolean);
            if (!ds.length && r.done_date) ds=[fmtDate(r.done_date)];
            ds = ds.filter(function(d){ return d && d < signDt; });
            ds.forEach(function(d){ doneM[parseInt(d.substr(5,2),10)]=1; });
        }
        var cells='';
        for (var k=1;k<=12;k++){
            var mark = (k===+r.plan_month ? '◎' : '') + (doneM[k] ? '✔' : '');
            cells += '<td style="font-size:13px;">'+mark+'</td>';
        }
        body += '<tr><td>'+(i+1)+'</td><td class="l">'+esc(r.course_name)+'</td>'
             + '<td class="l">'+esc(r.dept_name||'全公司')+'</td>'
             + '<td>'+(r.train_type==='external'?'外訓':'內訓')+'</td>'+cells
             + '<td>'+(r.cost!=null && r.cost!=='' ? numTrim(r.cost) : '')+'</td>'
             + '<td class="l" style="font-size:11px;">'+esc(r.note||'')+'</td></tr>';
    });
    body += '</tbody></table>'
         + '<div class="pt-legend"><b>圖示說明：</b>'
         + '<span style="font-size:14px;">◎</span> ＝ 預計實施月份（計畫）　'
         + '<span style="font-size:14px;">✔</span> ＝ 實際已實施（已完成）　'
         + '同一格同時出現 ◎✔ ＝ 該月依計畫完成；只有 ✔ ＝ 實際實施月份與原計畫不同。</div>'
         + signRowHtml();
    egPrintWindow(year+' '+docTitle, body, '', DOC_NO.plan, true);
}
/* 免送審卻沒綁簽章人時提醒一次（否則印出來三個欄位都空白） */
function signWarn(){
    if (+(SETTINGS.training_need_approval||0)) return;
    if ((SIGNERS.approver&&SIGNERS.approver.name) || (SIGNERS.reviewer&&SIGNERS.reviewer.name)
        || (SIGNERS.hr_signer&&SIGNERS.hr_signer.name)) return;
    alert('提醒：目前設定為「不需送審」，但尚未指定簽章人員，列印出來的核准／審核／人事欄會是空白。\n'
        + '請到「組織角色綁定設定」指定最高核准人員、人事表單審核者與人事簽章人員。');
}
/* ② 訓練結果明細表：只列已完成，含實施結果與評鑑 */
function printResultTable(){
    var year=$('#yearSel').val(), rows=ROWS.filter(function(r){ return r.status==='done'; });
    signWarn();
    var docTitle = DOC_NAME.result || '教育訓練結果明細表';   // 表頭一律用綁定AS文件的doc_name，不寫死（ai-rules/16 第一之二節）
    // 年份位置統一比照「年度教育訓練計畫表」印在表格左上角（獨立一行、左對齊），不要放進置中的 pt-head 副標
    var body='<div class="pt-head"><div class="co">'+esc(COMPANY)+'</div>'
           + '<div class="tt">'+esc(docTitle)+'</div></div>'
           + '<div style="text-align:left;font-size:13px;font-weight:bold;margin:4px 0 2px;">'+year+' 年</div>';
    body += '<table class="pt"><colgroup><col style="width:4%"><col style="width:4%"><col style="width:20%">'
         + '<col style="width:13%"><col style="width:6%"><col style="width:11%"><col style="width:12%">'
         + '<col style="width:5%"><col style="width:5%"><col style="width:5%"><col style="width:6%"><col style="width:9%">'
         + '</colgroup><thead><tr><th>NO.</th><th>月</th><th>課程名稱</th>'
         + '<th>訓練對象</th><th>方式</th><th>講師/開課單位</th>'
         + '<th>上課日期</th><th>時數</th><th>應到</th>'
         + '<th>實到</th><th>評鑑方式</th><th>評鑑結果</th></tr></thead><tbody>';
    if (!rows.length) body += '<tr><td colspan="12" style="height:24px;">（本年度尚無已完成的訓練）</td></tr>';
    rows.forEach(function(r,i){
        var ds=(r.days||[]).map(function(d){ return dispDate(d.day_date); }).filter(Boolean).sort();
        var e=r.eval||{}, ev=[];
        if (r.eval_method==='notice') ev.push('免評鑑');
        else { if(e.pass) ev.push('合格 '+e.pass); if(e.fail) ev.push('不合格 '+e.fail);
               if(e.exempt) ev.push('免評 '+e.exempt); if(e.none) ev.push('未評 '+e.none); }
        body += '<tr><td>'+(i+1)+'</td><td>'+r.plan_month+'</td><td class="l">'+esc(r.course_name)+'</td>'
             + '<td class="l">'+esc(r.dept_name||'全公司')+'</td>'
             + '<td>'+(r.train_type==='external'?'外訓':'內訓')+'</td>'
             + '<td class="l">'+esc((r.train_type==='external'?r.org_unit:r.trainer)||'')+'</td>'
             + '<td style="font-size:11px;">'+esc(ds.length?(ds[0]+(ds.length>1?'~'+ds[ds.length-1].substr(5):'')):dispDate(r.done_date))+'</td>'
             + '<td>'+(r.actual_hours==null?'':numTrim(r.actual_hours))+'</td>'
             + '<td>'+(r.target_headcount==null?'':r.target_headcount)+'</td>'
             + '<td>'+(r.actual_headcount==null?'':r.actual_headcount)+'</td>'
             + '<td>'+esc(r.eval_method?(EVAL_METHODS[r.eval_method]||r.eval_method):'')+'</td>'
             + '<td style="font-size:11px;">'+esc(ev.join('　'))+'</td></tr>';
    });
    body += '</tbody></table>' + signRowHtml();
    egPrintWindow(year+' '+docTitle, body, '', DOC_NO.result, true);
}
$('#btnPrintPlan').on('click', printPlanTable);
$('#btnPrintResult').on('click', printResultTable);

function delSession(sid){
    var r = ROWS.find(function(x){ return String(x.session_id)===String(sid); }) || {};
    var name = r.course_name || '此場次';
    var a1 = prompt('【刪除確認 1/2】將永久刪除訓練場次「'+name+'」，連同上課日期、參加人員名單與評鑑結果、'
        + (r.attach_count>0 ? '以及 '+r.attach_count+' 個附件（實體檔一併刪除）' : '所有附件')
        + '，且無法復原。\n\n確定要刪除請輸入大寫 Y：');
    if (a1 !== 'Y'){ if (a1!==null) alert('未輸入大寫 Y，已取消刪除。'); return; }
    var a2 = prompt('【刪除確認 2/2】再確認一次：真的要刪除「'+name+'」嗎？\n\n請再輸入一次大寫 Y：');
    if (a2 !== 'Y'){ if (a2!==null) alert('未輸入大寫 Y，已取消刪除。'); return; }
    $.post(API, {action:'delete_session', session_id:sid}, function(res){
        if (!res.ok){ alert(res.error||'刪除失敗'); return; }
        loadList(); alert('已刪除「'+name+'」（含附件）。');
    }, 'json').fail(function(x){ alert('刪除失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}

/* ---------- 檢視（唯讀）＋列印簽到表／考核表 ---------- */
var VIEW_RES = null;
function openView(sid){
    VIEW_RES = null;
    $('#viewBody').html('<span style="color:#8a6d45;">載入中…</span>');
    openMask('viewMask');
    $.getJSON(API, {action:'session_detail', session_id:sid}, function(res){
        if (!res.ok){ $('#viewBody').html('<span style="color:#DD5138;">'+esc(res.error||'載入失敗')+'</span>'); return; }
        VIEW_RES = res;
        var s=res.session, ext=s.train_type==='external';
        // 外訓或免評鑑(宣導)課程都不提供考核表，比照「實行資料」modal 的 applyEvalMethod() 同一套判斷
        $('#viewOjtBtn').toggle(!ext && s.eval_method!=='notice');
        $('#viewBody').html('<div class="ex-plan"><div><b>'+esc(s.course_name)+'</b> '+statPill(s.status)+'</div>'
            +'<div>計畫：'+s.year+' 年 '+s.plan_month+' 月　類型：'+(ext?'外訓':'內訓')
            +'　計畫時數：'+(s.hours==null?'—':numTrim(s.hours))+'　開課日：'+(dispDate(s.done_date)||'—')+'</div></div>'
            + detailHtml(res));
    }).fail(function(){ $('#viewBody').html('<span style="color:#DD5138;">載入失敗</span>'); });
}
/* 「訓練場次檢視」的列印簽到表／列印考核表：直接呼叫 printSignSheet()/printOjtSheet() 共用同一份版面（src 參數見各自定義處），
   確保跟「實行資料」modal 內的列印輸出一致，不重複維護第二份版面（原本各自獨立一份的 printRecord() 已移除）。
   VIEW_RES 的 days/attendees 欄位直接沿用 API 原始命名（day_date/start_time/end_time），這裡轉成 printSignSheet/printOjtSheet 共用的 {date,start,end,hours} 形狀。 */
function viewPrintSrc(){
    var res = VIEW_RES, s = res.session;
    var days = (res.days||[]).map(function(d){ return {date:d.day_date, start:d.start_time||'', end:d.end_time||'', hours:d.hours==null?'':numTrim(d.hours)}; });
    return {r:s, days:days, attendees:res.attendees||[], daySigns:daySignMap(res.day_signs),
            ojtItems:res.ojt_items||[], assessor:res.ojt_assessor_name||''};
}
function printViewSignSheet(){
    if (!VIEW_RES){ alert('資料尚未載入完成'); return; }
    printSignSheet(false, viewPrintSrc());
}
function printViewOjtSheet(){
    if (!VIEW_RES){ alert('資料尚未載入完成'); return; }
    printOjtSheet(viewPrintSrc());
}

/* ---------- 現場簽到（免 training_edit 權限；後端 checkin_meta/sign_attendee 已在 $publicActions 白名單） ----------
   多天課程一天一張簽到表就要簽一次：CHECKIN_DAY＝目前選的上課日，簽到章日期一律印該日期，不是按鈕按下當下的日期。 */
var CHECKIN = null, CHECKIN_DAY_SIGNS = {}, CHECKIN_DAY = '';
function openCheckin(sid){
    CHECKIN = null; CHECKIN_DAY_SIGNS = {}; CHECKIN_DAY = '';
    $('#checkinInfo').text('載入中…'); $('#checkinBody').html(''); $('#checkinDayBox').hide();
    openMask('checkinMask');
    $.getJSON(API, {action:'checkin_meta', session_id:sid}, function(res){
        if (!res.ok){ $('#checkinInfo').html('<span style="color:#DD5138;">'+esc(res.error||'載入失敗')+'</span>'); return; }
        CHECKIN = res;
        CHECKIN_DAY_SIGNS = daySignMap(res.day_signs);
        $('#checkinInfo').html('<b>'+esc(res.session.course_name)+'</b>　'+res.session.year+' 年 '+res.session.plan_month+' 月');
        var days = res.days||[], today = META.today;
        var cur = days.filter(function(d){ return d.day_date===today; })[0];
        CHECKIN_DAY = cur ? cur.day_date : (days[0] ? days[0].day_date : '');
        if (days.length>1){
            var h=''; days.forEach(function(d){ h+='<option value="'+d.day_date+'"'+(d.day_date===CHECKIN_DAY?' selected':'')+'>'+dispDate(d.day_date)+'</option>'; });
            $('#checkinDaySel').html(h); $('#checkinDayBox').show();
        }
        renderCheckinBody();
    }).fail(function(x){ $('#checkinInfo').html('<span style="color:#DD5138;">'+esc(x.responseJSON&&x.responseJSON.error||'載入失敗')+'</span>'); });
}
function checkinDayChange(){ CHECKIN_DAY = $('#checkinDaySel').val(); renderCheckinBody(); }
function renderCheckinBody(){
    var sid = CHECKIN.session.session_id, h='';
    (CHECKIN.attendees||[]).forEach(function(a){
        var signed = CHECKIN_DAY_SIGNS[a.user_id+'_'+CHECKIN_DAY];
        h += '<tr data-uid="'+a.user_id+'"><td>'+esc(a.dept_name||'')+'</td><td>'+esc(a.position_name||'')+'</td><td>'+esc(a.user_name||'')+'</td>'
           + '<td>'+(signed
                ? egStampHtml(a.user_name, dispDate(CHECKIN_DAY), false, a.dept_name)
                : '<input type="password" placeholder="本人密碼，按Enter簽到" id="ck-pw-'+a.user_id+'" data-eg-skip'
                  + ' onkeydown="if(event.key===\'Enter\'){event.preventDefault();checkinSign('+sid+','+a.user_id+');}">')+'</td></tr>';
    });
    $('#checkinBody').html(h || '<tr><td colspan="4" style="color:#8a6d45;padding:8px;">尚無名單</td></tr>');
}
function checkinSign(sid, uidv){
    var pw = $('#ck-pw-'+uidv).val();
    if (!pw){ alert('請輸入密碼'); return; }
    if (!CHECKIN_DAY){ alert('查無上課日期'); return; }
    $.post(API, {action:'sign_attendee', session_id:sid, user_id:uidv, day_date:CHECKIN_DAY, password:pw}, function(res){
        if (!res.ok){ alert(res.error||'簽到失敗'); $('#ck-pw-'+uidv).val('').select(); return; }
        CHECKIN_DAY_SIGNS[uidv+'_'+CHECKIN_DAY] = res.day_date;
        var a = (CHECKIN.attendees||[]).filter(function(x){ return String(x.user_id)===String(uidv); })[0];
        $('tr[data-uid="'+uidv+'"] td:last-child').html(egStampHtml(a?a.user_name:'', dispDate(CHECKIN_DAY), false, a?a.dept_name:''));
        var next = (CHECKIN.attendees||[]).filter(function(x){ return !CHECKIN_DAY_SIGNS[x.user_id+'_'+CHECKIN_DAY]; })[0];
        if (next) setTimeout(function(){ $('#ck-pw-'+next.user_id).focus(); }, 30);
        else alert('本日全員已簽到');
    }, 'json').fail(function(x){ alert(x.responseJSON&&x.responseJSON.error || '簽到失敗'); $('#ck-pw-'+uidv).val('').select(); });
}

/* 通知深連結：?tab=apply 開需求申請分頁；?apply_req=ID 直接開該筆申請單（唯讀或可編視狀態而定） */
function applyUrlParams(){
    var qs = new URLSearchParams(location.search);
    var tab = qs.get('tab'), rid = qs.get('apply_req');
    if (tab === 'apply' || rid) {
        $('#mainTabs .tab[data-tab=apply]').trigger('click');
        if (rid) loadRequests(function(){ openReq(parseInt(rid,10)); });
    }
}
/* ================= 教育訓練需求申請單（2-MM-01-05 線上化） ================= */
var REQUESTS = null;
var REQ_STATUS_LABEL = {draft:'草稿', submitted:'待核准', approved:'已核准', rejected:'已駁回', converted:'已轉計畫'};
var MY_DEPTS = [], REQ_SIGNERS = {};
function reqStatusPill(s){
    var cls = {draft:'st-planned', submitted:'st-scheduled', approved:'st-done', rejected:'st-cancelled', converted:'st-done'}[s] || 'st-planned';
    var extra = s==='converted' ? ' style="background:#F0A24B;color:#fff;"' : '';
    return '<span class="st-pill '+cls+'"'+extra+'>'+(REQ_STATUS_LABEL[s]||s)+'</span>';
}
function renderReqFlowHint(){
    $('#reqFlowHint').text(+(SETTINGS.training_request_need_approval==null?1:SETTINGS.training_request_need_approval)
        ? '申請單位主管核准' : '（模組設定為免簽核，送出即視同核准）');
}
function loadRequests(cb){
    $.getJSON(API, {action:'request_list'}, function(res){
        if (!res.ok){ alert(res.error||'載入失敗'); return; }
        REQUESTS = res.requests || [];
        renderRequestList();
        if (cb) cb();
    });
}
/* 已核准但尚未轉為計畫：一眼看出還有幾筆等著訓練管理員處理，顯示在分頁標籤上 */
function reqApprovedNotConverted(){ return (REQUESTS||[]).filter(function(r){ return r.status==='approved'; }).length; }
function renderReqBadge(){
    var n = reqApprovedNotConverted();
    $('#reqTabBadge').toggle(n>0).text(n);
}
function renderRequestList(){
    var h = '';
    (REQUESTS||[]).forEach(function(r){
        var mine = +r.user_id === +(META.uid||0);
        var canEditRow = (mine || PERMS.canAdmin) && (r.status==='draft' || r.status==='rejected');
        var canDecideRow = r.status==='submitted' && PERMS.canAdmin;   // 一般核准走通知裡的獨立審核頁；本頁管理員可直接處理
        var canConvert = r.status==='approved' && PERMS.canEdit;
        var canDel = (mine && r.status==='draft') || PERMS.canAdmin;
        var trNames = (r.trainees_list||[]).map(function(t){ return t.user_name; }).join('、');
        h += '<tr><td>'+esc(dispDate(r.apply_date))+'</td><td>'+esc(r.dept_name||'')+'</td><td class="t-left">'+esc(r.subject)+'</td>'
           + '<td class="t-left">'+esc(trNames)+'</td>'
           + '<td>'+esc((dispDate(r.start_date)||'')+(r.end_date?'~'+dispDate(r.end_date):''))+(r.days?'（'+r.days+'天）':'')+'</td>'
           + '<td>'+reqStatusPill(r.status)+(r.status==='rejected'&&r.reject_note?' <span title="'+esc(r.reject_note)+'" style="color:#DD5138;"><i class="fa fa-info-circle"></i></span>':'')+'</td>'
           + '<td style="white-space:nowrap;">'
           + '<span class="tr-op" onclick="openReq('+r.request_id+')"><i class="fa fa-search-plus"></i>檢視</span>';
        if (canEditRow) h += '<span class="tr-op" onclick="openReq('+r.request_id+')"><i class="fa fa-pencil"></i>編輯</span>';
        if (canDecideRow) h += '<span class="tr-op" onclick="reqDecideQuick('+r.request_id+',\'approved\')"><i class="fa fa-check"></i>核准</span>'
                              + '<span class="tr-op" style="color:#DD5138;" onclick="reqDecideQuick('+r.request_id+',\'rejected\')"><i class="fa fa-undo"></i>退回</span>';
        if (canConvert) h += '<span class="tr-op" onclick="reqConvert('+r.request_id+')"><i class="fa fa-share-square-o"></i>轉為計畫</span>';
        if (canDel) h += '<span class="tr-op" style="color:#DD5138;" onclick="reqDelete('+r.request_id+')"><i class="fa fa-trash"></i></span>';
        h += '</td></tr>';
    });
    $('#reqBody').html(h || '<tr><td colspan="7" style="padding:16px;color:#8a6d45;">尚無申請單</td></tr>');
    renderReqFlowHint(); renderReqBadge();
}
function reqFind(id){ return (REQUESTS||[]).find(function(x){ return +x.request_id===+id; }); }

/* ---------- 三、受訓人員：限申請單位底下人員，不排除申請人本人（比照確認開課的人員選擇器） ---------- */
var REQ_ATT = [];
function reqDeptOptionsFill(){
    var h = '<option value="">請選擇</option>';
    (MY_DEPTS||[]).forEach(function(d){ h += '<option value="'+d.id+'">'+esc(d.name)+'</option>'; });
    $('#reqDept').html(h);
}
function reqAttRender(editable){
    var h='';
    REQ_ATT.forEach(function(a,i){
        h += '<tr><td>'+esc(a.dept_name||'')+'</td><td>'+esc(a.position_name||'—')+'</td><td class="t-left">'+esc(a.user_name||'')+'</td>'
           + '<td>'+(editable?'<span class="att-del" onclick="reqAttDel('+i+')"><i class="fa fa-times"></i></span>':'')+'</td></tr>';
    });
    $('#reqAttBody').html(h || '<tr><td colspan="4" style="color:#8a6d45;padding:6px;">尚未加入人員</td></tr>');
    $('#reqAttCount').text(REQ_ATT.length ? '（共 '+REQ_ATT.length+' 人）' : '');
}
/* 受訓人員名單要依「申請日期」當時的部門人員顯示，不是一律列目前現況——補登舊申請單或申請日期填過去日期時，
   當時在此部門、現在已調走／離職的人也該列得出來（比照確認實行 modal 的 classAtDate() 同一套做法，ai-rules/14）。 */
function reqAttLoadPeople(){
    var did = $('#reqDept').val();
    if (!did){ $('#reqAttPeopleBox').html('<span class="empty">請先選申請單位</span>'); $('#reqAttDeptHint').text('請先選申請單位'); return; }
    var atDate = $('#reqApplyDate').val() || META.today;
    $('#reqAttDeptHint').text('僅列出「'+$('#reqDept option:selected').text()+'」部門人員（含申請人本人）');
    $('#reqAttPeopleBox').html('<span class="empty">載入中…</span>');
    $.getJSON(API, {action:'people', dept_id:did, at_date:atDate}, function(res){
        if (!res.ok){ $('#reqAttPeopleBox').html('<span class="empty">載入失敗</span>'); return; }
        var h='';
        if (res.at_date) h+='<div style="flex-basis:100%;color:#8a6d45;font-size:12px;">名單依 '+esc(res.at_date)+'（申請日期）當時職務顯示（依職務異動紀錄解析；已離職但當時在職者也會列出）</div>';
        res.people.forEach(function(u){
            var inList = REQ_ATT.some(function(a){ return a.user_id===+u.id; });
            var pos = u.position_name||'';
            h += '<label><input type="checkbox" class="req-att-ck" value="'+u.id+'" data-name="'+esc(u.user_cname)+'" '
               + 'data-dept="'+esc($('#reqDept option:selected').text())+'" data-pos="'+esc(pos)+'"'+(inList?' checked disabled':'')+'> '
               + esc(u.user_cname)+(pos?'<span style="color:#8a6d45;">（'+esc(pos)+'）</span>':'')+(inList?'(已加)':'')+'</label>';
        });
        $('#reqAttPeopleBox').html(h || '<span class="empty">此部門無人員</span>');
        $('#reqAttPickAll').prop('checked', false);
    });
}
$('#reqDept').on('change', function(){ REQ_ATT = []; reqAttRender(true); reqAttLoadPeople(); });
// 申請日期一改，人員候選名單要跟著換成「當時」的部門人員（已勾選加入的受訓人員不連動移除，只換候選清單）
$('#reqApplyDate').on('change', function(){ if ($('#reqDept').val()) reqAttLoadPeople(); });
$('#reqAttPickAll').on('change', function(){ $('#reqAttPeopleBox .req-att-ck:not(:disabled)').prop('checked', this.checked); });
function reqAttAddChecked(){
    $('#reqAttPeopleBox .req-att-ck:checked:not(:disabled)').each(function(){
        var id=+$(this).val();
        if (!REQ_ATT.some(function(a){ return a.user_id===id; }))
            REQ_ATT.push({user_id:id, user_name:$(this).data('name'), dept_name:$(this).data('dept'), position_name:$(this).data('pos')||''});
    });
    reqAttRender(true); reqAttLoadPeople();
}
function reqAttDel(i){ REQ_ATT.splice(i,1); reqAttRender(true); reqAttLoadPeople(); }

/* ---------- 四、受訓時間：逐日起訖（免休息設定，比照確認開課日期表但拿掉休息欄） ---------- */
var REQ_DAYS = [];
function reqDayRender(editable){
    var h='';
    REQ_DAYS.forEach(function(d,i){
        h += '<tr><td>第'+(i+1)+'天</td>'
           + '<td><input type="date" max="9999-12-31" value="'+esc(d.date)+'" '+(editable?'':'disabled')+' onchange="reqDayEdit('+i+',\'date\',this.value)"></td>'
           + '<td><input type="text" class="time-in" maxlength="5" placeholder="09:00" value="'+esc(d.start)+'" '+(editable?'':'disabled')+' oninput="reqDayEdit('+i+',\'start\',this.value,1)" onchange="reqDayEdit('+i+',\'start\',this.value)"></td>'
           + '<td><input type="text" class="time-in" maxlength="5" placeholder="17:00" value="'+esc(d.end)+'" '+(editable?'':'disabled')+' oninput="reqDayEdit('+i+',\'end\',this.value,1)" onchange="reqDayEdit('+i+',\'end\',this.value)"></td>'
           + '<td class="chk" id="reqDayChk'+i+'"></td>'
           + '<td>'+(editable&&REQ_DAYS.length>1?'<span class="att-del" onclick="reqDayDel('+i+')"><i class="fa fa-times"></i></span>':'')+'</td></tr>';
    });
    $('#reqDayBody').html(h);
    reqDayValidate();
}
function reqDayEdit(i, key, val, typing){
    if (!REQ_DAYS[i]) return;
    if (key==='start' || key==='end'){
        var p = parseTime(val);
        REQ_DAYS[i][key] = p.ok ? p.val : val;
        if (!typing){
            var $in = $('#reqDayBody tr').eq(i).find('input');
            $in.eq(key==='start'?1:2).val(REQ_DAYS[i][key]);
        }
    } else REQ_DAYS[i][key] = val;
    reqDayValidate();
}
function reqDayValidate(){
    var seen={}, bad=0;
    REQ_DAYS.forEach(function(d,i){
        var msg='', $tr=$('#reqDayBody tr').eq(i);
        var $din=$tr.find('input').eq(0), $sin=$tr.find('input').eq(1), $ein=$tr.find('input').eq(2);
        $din.removeClass('inv'); $sin.removeClass('inv'); $ein.removeClass('inv');
        if (!d.date){ msg='請填日期'; $din.addClass('inv'); }
        else if (!validDateStr(d.date)){ msg='日期不存在'; $din.addClass('inv'); }
        else if (seen[d.date]){ msg='與第'+seen[d.date]+'天重複'; $din.addClass('inv'); }
        else seen[d.date]=i+1;
        var ps=parseTime(d.start), pe=parseTime(d.end);
        if (!msg && !ps.ok){ msg='開始時間：'+ps.msg; $sin.addClass('inv'); }
        if (!msg && !pe.ok){ msg='結束時間：'+pe.msg; $ein.addClass('inv'); }
        if (!msg && ps.val && pe.val && timeToMin(pe.val)<=timeToMin(ps.val)){ msg='結束不可早於或等於開始'; $ein.addClass('inv'); }
        if (msg) bad++;
        $('#reqDayChk'+i).text(msg||'✓').toggleClass('ok', !msg);
    });
    $('#reqDaysHint').text(REQ_DAYS.length ? '共 '+REQ_DAYS.length+' 天' : '尚未設定日期');
    return bad===0;
}
function reqDayAdd(){
    var last = REQ_DAYS[REQ_DAYS.length-1] || {date:$('#reqApplyDate').val()||META.today, start:'', end:''};
    REQ_DAYS.push({date:last.date?addDaysStr(last.date,1):'', start:last.start, end:last.end});
    reqDayRender(true);
}
function reqDayDel(i){ if (REQ_DAYS.length<=1) return; REQ_DAYS.splice(i,1); reqDayRender(true); }
function reqDayDelLast(){ if (REQ_DAYS.length<=1) return; REQ_DAYS.pop(); reqDayRender(true); }

var REQ_CUR = null;
function reqEditableFor(r){
    var mine = +r.user_id === +(META.uid||0);
    return (mine || PERMS.canAdmin) && (r.status==='draft' || r.status==='rejected');
}
function openReq(id){
    var r = reqFind(id);
    if (!r) return;
    REQ_CUR = r;
    var editable = reqEditableFor(r);
    $('#reqMask').data('rid', id);
    $('#reqTitle').text((editable?'編輯':'檢視')+'教育訓練需求申請單'+(r.status!=='draft'?'（'+(REQ_STATUS_LABEL[r.status]||r.status)+'）':''));
    reqDeptOptionsFill();
    $('#reqDept').val(r.dept_id||'');
    $('#reqApplyDate').val(r.apply_date||'');
    $('#reqSubject').val(r.subject||'');
    $('#reqContent').val(r.content||'');
    $('#reqFocus').val(r.focus||'');
    $('#reqHours').val(r.hours!=null?numTrim(r.hours):'');
    $('#reqLocation').val(r.location||''); $('#reqCost').val(r.cost||'');
    $('#reqBrochure').val(r.brochure_count!=null?r.brochure_count:'');
    REQ_ATT = (r.trainees_list||[]).map(function(t){ return {user_id:+t.user_id, user_name:t.user_name, dept_name:t.dept_name, position_name:t.position_name||''}; });
    REQ_DAYS = (r.days||[]).map(function(d){ return {date:fmtDate(d.day_date), start:d.start_time||'', end:d.end_time||''}; });
    if (!REQ_DAYS.length && editable) REQ_DAYS.push({date:'', start:'', end:''});
    reqAttRender(editable);
    $('#reqAttAddRow').toggle(editable);
    if (editable) reqAttLoadPeople(); else { $('#reqAttDeptHint').text(''); $('#reqAttPeopleBox').empty(); }
    reqDayRender(editable);
    $('#reqMask .m-body input,#reqMask .m-body textarea,#reqMask .m-body select').prop('disabled', !editable);
    // 申請日期：一般規則鎖住時，有「可修改申請日期」功能的人仍能單獨改這一欄並直接更新（不必整張單解鎖）
    var canDateOnly = !editable && (PERMS.isAdmin || PERMS.canEditApplyDate);
    $('#reqApplyDate').prop('disabled', !(editable || canDateOnly));
    $('#btnReqApplyDateSave').toggle(canDateOnly);
    $('#reqSaveBtn,#reqSubmitBtn').toggle(editable);
    $('#reqStatusHint').toggle(!editable).html(!editable
        ? '狀態：<b>'+(REQ_STATUS_LABEL[r.status]||r.status)+'</b>'+(r.status==='rejected'&&r.reject_note?'　退回原因：'+esc(r.reject_note):'')
          +(r.approver_name?'　核准/處理人：'+esc(r.approver_name):'') : '');
    openMask('reqMask');
}
$('#btnReqAdd').on('click', function(){
    REQ_CUR = null;
    $('#reqMask').data('rid', 0);
    $('#reqTitle').text('新增教育訓練需求申請單');
    reqDeptOptionsFill();
    $('#reqDept').val(MY_DEPTS.length===1 ? MY_DEPTS[0].id : '');
    $('#reqApplyDate').val(META.today);
    ['#reqSubject','#reqContent','#reqFocus','#reqHours','#reqLocation','#reqCost','#reqBrochure'].forEach(function(sel){ $(sel).val(''); });
    REQ_ATT = []; REQ_DAYS = [{date:'', start:'', end:''}];
    $('#reqAttAddRow').show();
    reqAttRender(true); reqAttLoadPeople(); reqDayRender(true);
    $('#reqMask .m-body input,#reqMask .m-body textarea,#reqMask .m-body select').prop('disabled', false);
    $('#btnReqApplyDateSave').hide();
    $('#reqSaveBtn,#reqSubmitBtn').show(); $('#reqStatusHint').hide();
    openMask('reqMask');
    setTimeout(function(){ $('#reqSubject').focus(); }, 100);
});
/* mode: 0=只存草稿 1=存檔並送出審核 */
function submitReq(mode){
    if (!$.trim($('#reqSubject').val())){ alert('請填主旨'); $('#reqSubject').focus(); return; }
    if (!$('#reqDept').val()){ alert('請選申請單位'); return; }
    if (!REQ_DAYS.length){ alert('請至少設定一天受訓時間'); return; }
    if (!reqDayValidate()){ alert('受訓時間有錯誤，請先修正'); return; }
    $.post(API, {action:'request_save', request_id:$('#reqMask').data('rid'), dept_id:$('#reqDept').val(),
        apply_date:$('#reqApplyDate').val(), subject:$('#reqSubject').val(), content:$('#reqContent').val(),
        focus:$('#reqFocus').val(),
        trainees:JSON.stringify(REQ_ATT.map(function(a){ return {user_id:a.user_id, user_name:a.user_name, dept_name:a.dept_name, position_name:a.position_name}; })),
        days:JSON.stringify(REQ_DAYS.map(function(d){ return {day_date:d.date, start_time:d.start, end_time:d.end}; })),
        hours:$('#reqHours').val(), location:$('#reqLocation').val(), cost:$('#reqCost').val(), brochure_count:$('#reqBrochure').val()},
    function(res){
        if (!res.ok){ alert(res.error||'儲存失敗'); return; }
        if (!mode){ closeMask('reqMask'); loadRequests(); return; }
        $.post(API, {action:'request_submit', request_id:res.request_id}, function(res2){
            if (!res2.ok){ alert('已存草稿，但送出失敗：'+(res2.error||'')); closeMask('reqMask'); loadRequests(); return; }
            closeMask('reqMask'); loadRequests();
            alert(res2.status==='approved' ? '已送出並自動核准。' : '已送出，已通知申請單位主管核准。');
        }, 'json');
    }, 'json').fail(function(x){ alert('儲存失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}
/* 補登用：僅更新申請日期，不受一般編輯鎖限制（限有 canEditApplyDate 或系統管理者） */
function reqSetApplyDate(){
    var rid = $('#reqMask').data('rid');
    var d = $('#reqApplyDate').val();
    if (!rid || !d) return;
    $.post(API, {action:'request_set_apply_date', request_id:rid, apply_date:d}, function(res){
        if (!res.ok){ alert(res.error||'更新失敗'); return; }
        alert('已更新申請日期。'); loadRequests();
    }, 'json').fail(function(x){ alert('更新失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}
/* 清單上的快速核准/退回（僅訓練管理員；一般核准走通知裡的獨立審核頁） */
/* 核准/退回、轉為計畫都先向後端刷新一次最新狀態再往下走，避免另一人剛決行過／已轉過計畫，
   這裡還停在舊快取繼續操作造成重複決行或重複轉出（ai-rules/08 第六節鐵則）。 */
function reqDecideQuick(id, decision){
    loadRequests(function(){
        var r = reqFind(id);
        if (!r){ alert('找不到此申請單，畫面已重新整理。'); return; }
        if (r.status!=='submitted'){
            alert('這筆申請單目前狀態已是「'+(REQ_STATUS_LABEL[r.status]||r.status)+'」，可能已被處理過，畫面已重新整理。');
            return;
        }
        var note = '';
        if (decision==='rejected'){ note = prompt('退回原因（必填）：'); if (note===null) return; note=$.trim(note); if (!note){ alert('請填寫退回原因'); return; } }
        else if (!confirm('確定核准此申請單？')) return;
        $.post(API, {action:'request_decide', request_id:id, decision:decision, note:note}, function(res){
            if (!res.ok){ alert(res.error||'處理失敗'); return; }
            loadRequests();
        }, 'json').fail(function(x){ alert('處理失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
    });
}
function reqConvert(id){
    loadRequests(function(){
        var r = reqFind(id);
        if (!r){ alert('找不到此申請單，畫面已重新整理。'); return; }
        if (r.status!=='approved'){
            alert('這筆申請單目前狀態已是「'+(REQ_STATUS_LABEL[r.status]||r.status)+'」，可能已被轉過計畫或退回，畫面已重新整理。');
            return;
        }
        if (!confirm('開啟「新增計畫」並帶入此申請單內容，確認/補齊講師、內外訓等欄位後儲存即完成轉換？')) return;
        openEdFromRequest(r);
    });
}
function reqDelete(id){
    if (!confirm('刪除此申請單？（僅刪申請單本身，不影響已轉出的計畫）')) return;
    $.post(API, {action:'request_delete', request_id:id}, function(res){
        if (!res.ok){ alert(res.error||'刪除失敗'); return; }
        loadRequests();
    }, 'json').fail(function(x){ alert('刪除失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}
/* 列印單張申請單（依 ai-rules/16 大標題/頁碼/文件編號、ai-rules/18 簽章圖章）
   批示：核准(最高決策者) → 主管(申請單位) → 申請人（左到右）；會辦另一列＝人事(或其代理人)。
   簽章日期一律等同申請日期；狀態達 approved/converted 才蓋章，草稿/待核准/已駁回留白待簽。 */
function printRequestForm(){
    var r = REQ_CUR;
    if (!r){ alert('請先開啟一筆申請單'); return; }
    var deptName = $('#reqDept option:selected').text() || r.dept_name || '';
    var body = '<div class="pt-head"><div class="co">'+esc(COMPANY)+'</div><div class="tt">教育訓練需求申請單</div>'
        + '<div class="sub">申請單位：'+esc(deptName)+'　　申請日期：'+esc(dispDate(r.apply_date))+'</div></div>';
    var kv = function(lb, v){ return '<tr><th style="width:110px;background:#fff;text-align:right;border:none;">'+lb+'</th>'
        + '<td class="l" style="border:1px solid #333;">'+esc(v||'')+'</td></tr>'; };
    var trText = (r.trainees_list||[]).map(function(t){ return t.user_name; }).join('、');
    var dayText = (r.days||[]).map(function(d){ return dispDate(d.day_date)+' '+((d.start_time||'')+(d.end_time?'~'+d.end_time:'')); }).join('、');
    body += '<table class="pt" style="margin-top:10px;">'
        + kv('主旨', r.subject)
        + kv('一、簡述內容', r.content)
        + kv('二、主管要求學習重點', r.focus)
        + kv('三、受訓人員', trText)
        + kv('四、受訓時間', dayText+(r.days&&r.days.length?'，共 '+r.days.length+' 天':'')+(r.hours!=null&&r.hours!==''?'（預估總時數 '+numTrim(r.hours)+' 小時）':''))
        + kv('受訓地點', r.location)
        + kv('受訓費用', r.cost)
        + '</table>';
    var ready = (r.status==='approved' || r.status==='converted');
    var dt = ready ? dispDate(r.apply_date) : '';
    var stamp = function(nm){ return (ready && nm) ? egApprovalStampHtml(nm, dt) : ''; };
    var top = REQ_SIGNERS.top_approver, hr = REQ_SIGNERS.hr_signer;
    body += '<table class="pt-sign" style="margin-top:14px;"><tr>'
        + '<td><div class="lb">會辦（人事）</div><div class="stamp-box">'+stamp(hr?hr.name:'')+'</div></td>'
        + '<td colspan="3"></td></tr></table>';
    body += '<table class="pt-sign" style="margin-top:4px;"><tr>'
        + '<td><div class="lb">批示（核准．最高決策者）</div><div class="stamp-box">'+stamp(top?top.user_cname:'')+'</div></td>'
        + '<td><div class="lb">主管（申請單位）</div><div class="stamp-box">'+stamp(r.dept_signer_name)+'</div></td>'
        + '<td><div class="lb">申請人</div><div class="stamp-box">'+stamp(r.user_name)+'</div></td>'
        + '</tr></table>';
    egPrintWindow('教育訓練需求申請單', body, '', r.as_doc_no||DOC_NO.request, false);
}

/* ================= 角色設定（角色名稱與功能都可自訂，沿用全站 Roles_API + role_features，比照 purchase_request.php） ================= */
var RAPI = '../../src/store/Roles_API.php';
var ROLES = [], CURROLE = 0, TR_FEATURES = [];
function loadRoles(then){
    $.getJSON(RAPI, {action:'get_roles', module:'training'}, function(res){
        ROLES = res.data || [];
        var h = '';
        ROLES.forEach(function(r){
            var sys = String(r.is_system)==='1';
            h += '<div class="role-item'+(sys?' sys':'')+'" data-id="'+r.role_id+'">'
               + esc(r.role_name)+(sys?'（系統．固定全權）':'')+'</div>';
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
    (TR_FEATURES||[]).forEach(function(f){
        var row = '<label class="role-feat"><input type="checkbox" class="featcb" value="'+esc(f.code)+'"> '+esc(f.label)+'</label>';
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
    var n = prompt('新角色名稱（例：品管代訓、僅檢視統計）：');
    if (!n || !$.trim(n)) return;
    $.post(RAPI, {action:'save_role', role_name:$.trim(n), module:'training'}, function(r){
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
    if (!confirm('確定刪除此角色？擁有此角色的人會失去對應權限（不會刪到使用者本身）。')) return;
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

/* ---------- CSV ---------- */
$('#btnCsv').on('click', function(){
    var rows = [['年','月','對象部門','課程名稱','類型','講師/開課單位','計畫時數','實際時數','天數','應到','實到','狀態','開課日期','每日時段','休息(分)','地點','評鑑方式','附件數','課程大綱','備註']];
    ROWS.forEach(function(r){
        var ext=r.train_type==='external';
        var ds=(r.days||[]);
        rows.push([r.year, r.plan_month, r.dept_name||'', r.course_name, ext?'外訓':'內訓', (ext?r.org_unit:r.trainer)||'',
            r.hours==null?'':numTrim(r.hours), r.actual_hours==null?'':numTrim(r.actual_hours),
            ds.length||(r.plan_days||''),
            r.target_headcount==null?'':r.target_headcount,
            r.actual_headcount==null?'':r.actual_headcount, STATUS_LABEL[r.status]||r.status,
            ds.length ? ds.map(function(d){ return dispDate(d.day_date); }).join('、') : dispDate(r.done_date),
            ds.length ? ds.map(function(d){ return (d.start_time||'')+(d.end_time?'~'+d.end_time:''); }).join('、')
                      : (r.start_time||'')+(r.end_time?'~'+r.end_time:''),
            ds.length ? ds.map(function(d){ return (d.break_minutes==null?0:d.break_minutes); }).join('、') : '',
            r.location||'', r.eval_method?(EVAL_METHODS[r.eval_method]||r.eval_method):'', r.attach_count||0,
            r.outline||'', r.note||'']);
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
$('#btnPageHelp').on('click', function(){ openMask('helpUseMask'); });
$('.tr-mask').on('click', function(e){ if (e.target===this) this.style.display='none'; });
/* 雙擊清空／聚焦全選／Enter 跳欄／表格 ↑↓ 與自動增刪列 → 一律由 eg_input_rules.js 處理，此處不再手刻 */

if (canView) loadMeta(function(){ loadList(); });
</script>
</body>
</html>
