<?php
/**
 * 量測儀器校驗管理（KPI 2-GM-04-01 第18項 量測儀器按時校驗率 的來源頁）
 * 儀器主檔沿用 qc_tool；週期自動推算下次應校驗日；登錄完成即前滾到期。
 * 資料一律走 src/store/ToolCalib_API.php；權限 tool_calib_lib.php
 */
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/QC/tool_calibration.php";
    header("Location:../../index.php");
    exit;
}
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/tool_calib_lib.php';

$db = (new DBConnection())->getPDO();
tool_calib_ensure_schema($db);
$tcUser = tool_calib_current_user($db);
$perms = tool_calib_perms($db, $tcUser);
$roleLabel = $perms['isAdmin'] ? '管理者'
           : ($perms['canAdmin'] ? '校驗管理員'
           : ($perms['canEdit'] ? '校驗登錄'
           : ($perms['canView'] ? '校驗唯讀' : '無權限')));
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>量測儀器校驗管理</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <style>
        #sidebar-menu { visibility: hidden; }
        .right_col .page-title { margin:8px 0 4px; overflow:hidden; }
        .tc-toolbar { display:flex; flex-wrap:wrap; gap:6px; align-items:center; clear:both;
            border:1.5px solid #E8D5B5; border-radius:8px; padding:8px 10px; margin-bottom:10px; background:#FDF8EF; }
        .tc-toolbar label { margin:0; font-size:13px; color:#5b3a1e; }
        .tc-toolbar select, .tc-toolbar input[type=month], .tc-toolbar button, .tc-toolbar a.btn {
            height:30px; font-size:13px; line-height:1; padding:0 10px; border:1px solid #D8BE93;
            border-radius:4px; background:#fff; color:#5b3a1e; cursor:pointer; }
        .tc-toolbar button:hover, .tc-toolbar a.btn:hover { background:#F7E0BD; }
        .tc-toolbar .btn-warm { background:#F0A24B; color:#fff; border-color:#d98a33; }
        .tc-toolbar .btn-warm:hover { background:#d98a33; }
        .tc-role-badge { margin-left:auto; font-size:13px; color:#5b3a1e; background:#F7E0BD; border-radius:12px; padding:4px 12px; }
        .tc-role-badge .fa-question-circle { cursor:pointer; color:#b5762a; margin-left:5px; }
        /* 當月統計條 */
        .tc-stat { display:flex; flex-wrap:wrap; gap:18px; align-items:center; margin-bottom:10px;
            border:1.5px solid #E8D5B5; border-radius:8px; padding:10px 14px; background:#FFF7E8; }
        .tc-stat .s-num { font-size:22px; font-weight:bold; color:#8A5A2B; }
        .tc-stat .s-lab { font-size:12px; color:#8a6d45; }
        .tc-stat .s-rate.below { color:#DD5138; }
        .tc-stat .s-rate.ok { color:#8A5A2B; }
        .tc-stat-ops { display:flex; gap:6px; align-items:center; }
        .tc-stat-ops button { height:30px; font-size:13px; line-height:1; padding:0 12px; border:1px solid #D8BE93;
            border-radius:4px; background:#fff; color:#5b3a1e; cursor:pointer; }
        .tc-stat-ops button:hover { background:#F7E0BD; }
        /* 類別分頁列（哪些類別要當分頁＝類別設定的 calib_tab） */
        .tc-tabs { display:flex; flex-wrap:wrap; gap:4px; align-items:flex-end; margin:0 0 8px;
            border-bottom:2px solid #E8D5B5; }
        .tc-tabs .tab { padding:6px 14px; font-size:13px; color:#8a6d45; background:#FBF6EC;
            border:1px solid #E8D5B5; border-bottom:none; border-radius:6px 6px 0 0; cursor:pointer; margin-bottom:-2px; }
        .tc-tabs .tab:hover { background:#F7E0BD; }
        .tc-tabs .tab.active { background:#F0A24B; color:#fff; border-color:#d98a33; font-weight:bold; }
        .tc-tabs .tab .cnt { font-size:11px; margin-left:4px; opacity:.85; }
        .tc-tabs .tab-hint { font-size:12px; color:#b5762a; margin:0 0 6px 10px; }
        .tc-pager { display:flex; flex-wrap:wrap; gap:6px; align-items:center; margin:4px 0 6px; font-size:13px; color:#5b3a1e; }
        .tc-pager select, .tc-pager button { height:28px; font-size:13px; border:1px solid #D8BE93; border-radius:4px;
            background:#fff; color:#5b3a1e; cursor:pointer; padding:0 10px; }
        .tc-pager button:hover:not(:disabled) { background:#F7E0BD; }
        .tc-pager button:disabled { color:#c9bda9; cursor:not-allowed; }
        .tc-table-wrap { overflow-x:auto; border:1px solid #E8D5B5; border-radius:6px; background:#fff; }
        table.tc-table { width:100%; border-collapse:collapse; font-size:13px; }
        table.tc-table th, table.tc-table td { border:1px solid #EADFC8; padding:5px 8px; white-space:nowrap; text-align:center; }
        table.tc-table thead th { position:sticky; top:0; z-index:5; background:#F7E0BD; color:#5b3a1e; font-weight:bold; }
        table.tc-table tbody tr:nth-child(even) { background:#FBF6EC; }
        table.tc-table tbody tr:hover { background:#FBF0DD; }
        table.tc-table td.t-left { text-align:left; }
        .st-pill { display:inline-block; font-size:12px; border-radius:10px; padding:2px 9px; }
        .st-overdue { background:#DD5138; color:#fff; }
        .st-soon { background:#F0A24B; color:#fff; }
        .st-ok { background:#F7E0BD; color:#7a5217; }
        .st-nobaseline { background:#fff; color:#c4863a; border:1px dashed #D8BE93; }
        .st-unmanaged { background:#efe7d8; color:#b0a390; }
        .tc-op { color:#b5762a; cursor:pointer; margin:0 4px; }
        .tc-op:hover { color:#8A5A2B; text-decoration:underline; }
        .tc-op.disabled { color:#c9bda9; cursor:not-allowed; text-decoration:none; }
        .managed-yes { color:#8A5A2B; font-weight:bold; }
        .managed-no { color:#b0a390; }
        input[type=number]::-webkit-outer-spin-button, input[type=number]::-webkit-inner-spin-button { -webkit-appearance:none; margin:0; }
        input[type=number] { -moz-appearance:textfield; }
        /* modal */
        .tc-mask { display:none; position:fixed; inset:0; background:rgba(60,40,20,.45); z-index:1050; }
        .tc-modal { background:#fff; border-radius:8px; max-width:560px; margin:52px auto; box-shadow:0 5px 25px rgba(0,0,0,.3);
            max-height:84vh; display:flex; flex-direction:column; }
        .tc-modal.wide { max-width:760px; }
        .tc-modal.xwide { max-width:1080px; }
        /* 批次校驗 */
        .bt-sec { border:1px solid #EADFC8; border-radius:6px; padding:8px 10px; margin-bottom:8px; }
        .bt-sec > .h { font-size:13px; font-weight:bold; color:#5b3a1e; margin-bottom:6px; }
        .bt-tools { max-height:34vh; overflow-y:auto; border:1px solid #EADFC8; border-radius:4px; }
        table.bt-tbl { width:100%; border-collapse:collapse; font-size:12px; }
        table.bt-tbl th, table.bt-tbl td { border:1px solid #EADFC8; padding:3px 6px; text-align:center; white-space:nowrap; }
        table.bt-tbl thead th { position:sticky; top:0; background:#F7E0BD; color:#5b3a1e; z-index:2; }
        table.bt-tbl tbody tr.sel { background:#FFF3DF; }
        .chain-row { display:flex; align-items:center; gap:8px; margin-bottom:4px; }
        .chain-row select { height:28px; font-size:13px; border:1px solid #D8BE93; border-radius:4px; }
        table.bt-tbl td select { font-size:12px; border:1px solid #D8BE93; border-radius:3px; }
        .bt-quick button { height:24px; font-size:12px; padding:0 8px; border:1px solid #D8BE93; border-radius:3px;
            background:#fff; color:#5b3a1e; cursor:pointer; margin-right:4px; }
        .bt-quick button:hover { background:#F7E0BD; }
        .bt-quick button.btn-warm-on { background:#F0A24B; color:#fff; border-color:#d98a33; font-weight:bold; }
        .att-row { border:1px dashed #D8BE93; border-radius:4px; padding:5px 8px; margin-bottom:5px; font-size:12px; background:#FDF8EF; }
        .att-row .fn { font-weight:bold; color:#5b3a1e; }
        .att-row .op { color:#b5762a; cursor:pointer; margin-left:8px; }
        .att-row .op.del { color:#DD5138; }
        .att-map { margin-top:5px; padding:5px; background:#fff; border:1px solid #EADFC8; border-radius:4px;
            max-height:150px; overflow-y:auto; display:none; }
        .att-map label { display:inline-block; width:32%; font-weight:normal; font-size:12px; margin:0; }
        /* 校驗人員／外校廠商控制項 */
        .op-box { position:relative; }
        .op-box select, .op-box input[type=text] { width:100%; border:1px solid #D8BE93; border-radius:4px;
            padding:5px 8px; font-size:13px; box-sizing:border-box; }
        .op-box .op-vlist { position:absolute; z-index:20; left:0; right:0; background:#fff; border:1px solid #D8BE93;
            border-radius:0 0 4px 4px; max-height:180px; overflow-y:auto; display:none; box-shadow:0 3px 10px rgba(0,0,0,.15); }
        .op-box .op-vlist div { padding:4px 8px; font-size:12px; cursor:pointer; color:#5b3a1e; }
        .op-box .op-vlist div:hover { background:#F7E0BD; }
        .op-box .op-vlist div .id { color:#b5762a; font-size:11px; margin-left:5px; }
        .op-box .op-sel { font-size:12px; color:#5b3a1e; margin-top:3px; }
        .op-box .op-sel b { color:#8A5A2B; }
        .op-box .op-warn { font-size:12px; color:#DD5138; margin-top:3px; }
        .op-box .op-clear { color:#b5762a; cursor:pointer; margin-left:6px; }
        .tc-modal .m-head { background:#F7E0BD; color:#5b3a1e; font-weight:bold; padding:10px 15px; border-radius:8px 8px 0 0;
            display:flex; justify-content:space-between; }
        .tc-modal .m-head .m-close { cursor:pointer; color:#b5762a; }
        .tc-modal .m-body { padding:15px; overflow-y:auto; }
        .tc-modal .m-body label { display:block; font-size:13px; color:#5b3a1e; margin:9px 0 3px; }
        .tc-modal .m-body input[type=text], .tc-modal .m-body input[type=number], .tc-modal .m-body input[type=date],
        .tc-modal .m-body input[type=password],
        .tc-modal .m-body select, .tc-modal .m-body textarea { width:100%; border:1px solid #D8BE93; border-radius:4px;
            padding:5px 8px; font-size:13px; box-sizing:border-box; }
        .tc-modal .m-foot { padding:10px 15px; border-top:1px solid #EADFC8; text-align:right; }
        .tc-modal .m-foot button { height:30px; padding:0 16px; border-radius:4px; font-size:13px; border:1px solid #d98a33; cursor:pointer; }
        .tc-modal .m-foot .b-ok { background:#F0A24B; color:#fff; }
        .tc-modal .m-foot .b-cancel { background:#fff; color:#5b3a1e; border-color:#D8BE93; margin-right:6px; }
        .grid2 { display:grid; grid-template-columns:1fr 1fr; gap:0 14px; }
        table.hist { width:100%; border-collapse:collapse; font-size:12px; }
        table.hist th, table.hist td { border:1px solid #EADFC8; padding:4px 6px; text-align:center; }
        table.hist thead th { background:#F7E0BD; color:#5b3a1e; }
        /* 統一設定視窗的分頁 */
        .cfg-tabs { display:flex; flex-wrap:wrap; gap:4px; align-items:flex-end; padding:8px 15px 0;
            border-bottom:2px solid #E8D5B5; background:#FDF8EF; }
        .cfg-tabs .tab { padding:6px 16px; font-size:13px; color:#8a6d45; background:#fff;
            border:1px solid #E8D5B5; border-bottom:none; border-radius:6px 6px 0 0; cursor:pointer; margin-bottom:-2px; }
        .cfg-tabs .tab:hover { background:#F7E0BD; }
        .cfg-tabs .tab.active { background:#F0A24B; color:#fff; border-color:#d98a33; font-weight:bold; }
        .cfg-pane { display:flex; flex-direction:column; min-height:0; }
        .ck-all-lab { font-weight:normal; font-size:11px; color:#8a6d45; margin:0; cursor:pointer; }
        table.hist td select.sel-grp { font-size:12px; border:1px solid #D8BE93; border-radius:4px; padding:2px 4px; max-width:150px; }
        /* 量具料號對應：表格內的小型輸入欄 */
        table.hist td .sp-in { font-size:12px; padding:2px 4px; }
        table.hist td.sp-prev { font-size:12px; color:#8A5A2B; }
        .tab-chip { border:1px solid #D8BE93; border-radius:12px; padding:2px 8px; background:#fff; color:#5b3a1e; }
        .tab-chip i { cursor:pointer; color:#b5762a; margin-left:5px; }
        .tab-chip i.del { color:#DD5138; }
        .tc-noperm { margin:40px auto; max-width:520px; text-align:center; border:1.5px solid #E8D5B5; border-radius:10px;
            padding:30px; background:#FDF8EF; color:#5b3a1e; }
        @media print {
            .tc-toolbar, .nav_menu, .left_col, footer, .tc-role-badge .fa-question-circle, .tc-op { display:none !important; }
            .right_col { margin:0 !important; padding:0 !important; }
            /* 只留目前分頁當標題，讓列印看得出印的是哪一類 */
            .tc-tabs { border:none; }
            .tc-tabs .tab:not(.active), .tc-tabs .tab-hint { display:none !important; }
            .tc-tabs .tab.active { background:none !important; color:#5b3a1e !important; border:none !important;
                font-size:14px; padding:0 0 4px; }
            table.tc-table thead th { position:static; }
        }
    </style>
</head>
<body class="nav-sm">
<div class="container body">
<div class="main_container">
    <?php include '../partPage/sideAndTopBarMenu.html' ?>
    <div class="right_col" role="main">
        <div class="page-title" style="display:flex;align-items:center;flex-wrap:wrap;">
            <h2 style="margin:6px 0;">量測儀器校驗管理
                <small style="color:#8a6d45;">KPI 2-GM-04-01 #18 量測儀器按時校驗率 來源頁</small></h2>
        </div>
        <div class="clearfix"></div>

<?php if (!$perms['canView']): ?>
        <div class="tc-noperm">
            <h4><i class="fa fa-lock"></i> 無量測儀器校驗檢閱權限</h4>
            <p>請洽管理者於「使用者權限設定」指派「校驗唯讀／登錄／管理員」角色。</p>
        </div>
<?php else: ?>
        <div class="tc-toolbar">
            <label>統計月份</label>
            <input type="month" id="ymSel">
            <label>狀態</label>
            <select id="statSel">
                <option value="">全部</option>
                <option value="managed">僅列入統計</option>
                <option value="overdue">逾期</option>
                <option value="soon">即將到期</option>
                <option value="ok">正常</option>
                <option value="nobaseline">未設基準</option>
                <option value="unmanaged">未列入統計</option>
            </select>
            <input type="text" id="kwSel" placeholder="搜尋編號" style="width:120px;">
            <button class="btn-warm" id="btnBatch" style="display:none;"><i class="fa fa-check-square-o"></i> 批次校驗</button>
            <button id="btnBatchList"><i class="fa fa-list-alt"></i> 批次紀錄</button>
            <button id="btnPending" style="display:none;position:relative;"><i class="fa fa-hourglass-half"></i> 待核准
                <span id="pendBadge" style="display:none;position:absolute;top:-7px;right:-7px;background:#DD5138;color:#fff;border-radius:9px;font-size:10px;line-height:16px;min-width:16px;height:16px;text-align:center;padding:0 3px;">0</span></button>
            <button class="btn-warm" id="btnAdd" style="display:none;"><i class="fa fa-plus"></i> 新增儀器</button>
            <button id="btnCfg" style="display:none;"><i class="fa fa-cog"></i> 設定</button>
            <button id="btnCsv"><i class="fa fa-file-text-o"></i> 匯出CSV</button>
            <button onclick="window.print()"><i class="fa fa-print"></i> 列印</button>
            <span class="tc-role-badge">目前角色：<b><?= htmlspecialchars($roleLabel) ?></b>
                <i class="fa fa-question-circle" id="btnRoleHelp" title="角色權限說明"></i></span>
        </div>

        <div class="tc-stat" id="statBar">
            <div><span class="s-num" id="stDen">—</span> <span class="s-lab">當月應校驗</span></div>
            <div><span class="s-num" id="stNum">—</span> <span class="s-lab">準時完成</span></div>
            <div><span class="s-num s-rate" id="stRate">—</span> <span class="s-lab">按時校驗率（目標 ≥95%）</span></div>
            <div class="s-lab" id="stHint" style="margin-left:auto;"></div>
            <!-- 年度紀錄／計畫表、週期批次設定：與統計數字同一列（使用者 2026-07-30 指示） -->
            <span class="tc-stat-ops">
                <button id="btnYear"><i class="fa fa-calendar"></i> 年度紀錄／計畫表</button>
                <button id="btnCycleSet" style="display:none;"><i class="fa fa-clock-o"></i> 週期批次設定</button>
            </span>
        </div>

        <div class="tc-tabs" id="tcTabs"></div>

        <div class="tc-pager" id="tcPager">
            <span id="tcCount" style="margin-right:auto;color:#8a6d45;"></span>
            <label>每頁</label>
            <select id="tcPageSize"><option>5</option><option selected>10</option><option>20</option><option>50</option></select>
            <button id="tcPrev">‹ 上一頁</button>
            <span id="tcPageInfo"></span>
            <button id="tcNext">下一頁 ›</button>
        </div>
        <div class="tc-table-wrap">
            <table class="tc-table" id="tcTable">
                <thead><tr>
                    <th>量具編號</th><th>類別</th><th>規格</th><th>週期(月)</th><th>校驗方式</th>
                    <th>下次應校驗月</th><th>狀態</th><th>最近校驗</th><th>列入校驗率統計</th><th>操作</th>
                </tr></thead>
                <tbody id="tcBody"><tr><td colspan="10" style="padding:20px;color:#8a6d45;">載入中…</td></tr></tbody>
            </table>
        </div>
        <div style="font-size:11px;color:#8a6d45;margin-top:4px;">
            狀態：<span class="st-pill st-overdue">逾期</span> <span class="st-pill st-soon">本月或下月到期</span>
            <span class="st-pill st-ok">正常</span> <span class="st-pill st-nobaseline">未設基準</span>
            <span class="st-pill st-unmanaged">未列入統計</span>。
            「列入校驗率統計」者才計入 KPI；下次應校驗月＝上次校驗月＋週期（登錄完成後自動前滾）；同月內完成即算準時。
            <span id="tcExcluded" style="color:#b5762a;"></span>
        </div>
<?php endif; ?>
    </div>
    <?php include '../partPage/footer.html' ?>
</div>
</div>

<!-- 登錄校驗 modal -->
<div class="tc-mask" id="recMask"><div class="tc-modal">
    <div class="m-head"><span id="recTitle">登錄校驗</span><span class="m-close" onclick="closeRec()">✕</span></div>
    <div class="m-body">
        <div style="font-size:12px;color:#8a6d45;" id="recInfo"></div>
        <div class="grid2">
            <div><label>校驗完成日 *</label><input type="date" id="recDate"></div>
            <div><label>判定結果</label><select id="recResult">
                <option value="pass">合格</option><option value="pass_adjust">校正後合格</option><option value="fail">不合格</option>
            </select></div>
            <div><label>校驗方式</label><select id="recMethod">
                <option value="外校">外校</option><option value="內校">內校</option><option value="">—</option>
            </select></div>
            <div><label>校驗人員／單位 *</label><div id="recOpBox" class="op-box"></div></div>
            <div id="recReviewerBox" style="display:none;"><label>覆驗者（內校）*</label>
                <select id="recReviewer"></select></div>
            <div><label>憑證／報告編號</label><input type="text" id="recCert" maxlength="50"></div>
            <div><label>備註</label><input type="text" id="recNote" maxlength="200"></div>
        </div>
        <div style="font-size:12px;color:#b5762a;margin-top:8px;" id="recRoll"></div>
        <div class="bt-sec" id="recAttSec" style="margin-top:10px;">
            <div class="h">校驗報告／相關資料附件（可設定文件類別）</div>
            <div style="margin-bottom:6px;">
                <input type="file" id="recFile" multiple style="font-size:12px;">
                <span id="recAttHint" style="font-size:11px;color:#8a6d45;"></span>
            </div>
            <div id="recAttList"></div>
        </div>
    </div>
    <div class="m-foot">
        <button class="b-cancel" onclick="closeRec()">取消</button>
        <button class="b-ok" onclick="submitRec()">登錄</button>
    </div>
</div></div>

<!-- 待核准（內校）modal -->
<div class="tc-mask" id="pendMask"><div class="tc-modal xwide">
    <div class="m-head"><span>待我核准的內校紀錄</span><span class="m-close" onclick="closeMask('pendMask')">✕</span></div>
    <div class="m-body" id="pendBody" style="font-size:13px;color:#5b3a1e;"></div>
    <div class="m-foot"><button class="b-ok" onclick="closeMask('pendMask')">關閉</button></div>
</div></div>

<!-- 設定/新增儀器 modal -->
<div class="tc-mask" id="setMask"><div class="tc-modal">
    <div class="m-head"><span id="setTitle">儀器設定</span><span class="m-close" onclick="closeMask('setMask')">✕</span></div>
    <div class="m-body">
        <div class="grid2">
            <div id="setNoBox"><label>量具編號 *</label><input type="text" id="setNo" maxlength="30"></div>
            <div id="setCatBox"><label>類別 *</label>
                <select id="setCat"></select></div>
            <div><label>校驗週期（月）</label><input type="number" id="setCycle" step="1" min="0" placeholder="例：12"></div>
            <div><label>校驗方式（預設）</label><select id="setMethod">
                <option value="">—</option><option value="內校">內校</option><option value="外校">外校</option>
            </select></div>
            <div><label>下次應校驗月（基準）</label><input type="month" id="setBase"></div>
            <div><label>列入校驗率統計（計入 KPI）</label><select id="setManaged">
                <option value="1">是</option><option value="0">否</option>
            </select></div>
        </div>
        <div style="font-size:12px;color:#8a6d45;margin-top:8px;">
            到期以「月」為單位——同一月份內完成校驗即算準時。設定基準到期月後，之後每次登錄校驗會依週期自動前滾，不需再手動維護。<br>
            類別下拉只列出「需校驗且可設定量具編號」的類別；類別的新增／更名／刪除請至
            <a href="inspection_combined_prototype.php" target="_blank" style="color:#b5762a;">線上檢驗－量具設定</a>，
            其校驗屬性則於本頁工具列「類別設定」勾選。
        </div>
    </div>
    <div class="m-foot">
        <button class="b-cancel" onclick="closeMask('setMask')">取消</button>
        <button class="b-ok" onclick="submitSet()">儲存</button>
    </div>
</div></div>

<!-- 批次校驗 modal（外校/廠內批量校驗：一次多支量具＋共用報告附件） -->
<div class="tc-mask" id="batMask"><div class="tc-modal xwide">
    <div class="m-head"><span>批次校驗登錄</span><span class="m-close" onclick="closeBatch()">✕</span></div>
    <div class="m-body">
        <div class="bt-sec">
            <div class="h">1. 本批共用資訊</div>
            <div class="grid2" style="grid-template-columns:repeat(3,1fr);">
                <div><label>校驗完成日 *</label><input type="date" id="btDate"></div>
                <div><label>校驗方式</label><select id="btMethod">
                    <option value="外校">外校</option><option value="內校">內校</option><option value="">—</option>
                </select></div>
                <div><label>校驗人員／單位（外校廠商）*</label><div id="btOpBox" class="op-box"></div></div>
                <div id="btReviewerBox" style="display:none;"><label>覆驗者（內校）*</label>
                    <select id="btReviewer"></select></div>
                <div><label>憑證／報告編號</label><input type="text" id="btCert" maxlength="50"></div>
                <div><label>判定結果（套用到全部）</label><select id="btResult">
                    <option value="pass">合格</option><option value="pass_adjust">校正後合格</option><option value="fail">不合格</option>
                </select></div>
                <div><label>備註</label><input type="text" id="btNote" maxlength="200"></div>
            </div>
        </div>

        <div class="bt-sec">
            <div class="h">2. 選擇本批量具　<span style="font-weight:normal;color:#8a6d45;font-size:12px;" id="btSelInfo"></span></div>
            <div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-bottom:6px;">
                <label style="margin:0;font-size:13px;">類別</label>
                <select id="btCat" style="height:26px;font-size:12px;border:1px solid #D8BE93;border-radius:4px;"></select>
                <input type="text" id="btKw" placeholder="搜尋編號" style="height:26px;font-size:12px;width:110px;border:1px solid #D8BE93;border-radius:4px;padding:0 6px;">
                <span class="bt-quick">
                    <button type="button" onclick="btPick('all')">全選</button>
                    <button type="button" onclick="btPick('none')">全不選</button>
                    <button type="button" onclick="btPick('overdue')">只選逾期</button>
                    <button type="button" onclick="btPick('due')">只選逾期＋30天內</button>
                </span>
            </div>
            <div class="bt-tools">
                <table class="bt-tbl" id="btTbl">
                    <thead><tr><th style="width:34px;"><input type="checkbox" id="btCkAll"></th>
                        <th>量具編號</th><th>類別</th><th>週期</th><th>下次應校驗月</th><th>狀態</th><th>本次結果</th></tr></thead>
                    <tbody id="btBody"></tbody>
                </table>
            </div>
        </div>

        <div class="bt-sec">
            <div class="h">3. 校驗報告／相關資料附件
                <span style="font-weight:normal;color:#8a6d45;font-size:12px;">（一份檔案可對應多支量具編號；上傳後按「對應量具」勾選）</span></div>
            <div style="margin-bottom:6px;">
                <input type="file" id="btFile" multiple style="font-size:12px;">
                <span id="btAttHint" style="font-size:11px;color:#8a6d45;"></span>
            </div>
            <div id="btAttList"></div>
        </div>
    </div>
    <div class="m-foot">
        <button class="b-cancel" onclick="closeBatch()">取消</button>
        <button class="b-ok" onclick="submitBatch()">登錄本批校驗</button>
    </div>
</div></div>

<!-- 批次紀錄 modal -->
<div class="tc-mask" id="batListMask"><div class="tc-modal xwide">
    <div class="m-head"><span>批次校驗紀錄</span><span class="m-close" onclick="closeMask('batListMask')">✕</span></div>
    <div class="m-body" id="batListBody" style="font-size:13px;color:#5b3a1e;"></div>
</div></div>


<!-- 週期批次設定 modal（管理員；依類別套用到底下量具） -->
<div class="tc-mask" id="cycMask"><div class="tc-modal xwide">
    <div class="m-head"><span>校驗週期批次設定（依類別）</span><span class="m-close" onclick="closeMask('cycMask')">✕</span></div>
    <div class="m-body">
        <div style="font-size:12px;color:#8a6d45;margin-bottom:8px;line-height:1.7;">
            對整個類別底下的量具一次設定校驗週期／基準到期月／是否列入校驗率統計。到期以「月」為單位，同月內完成即算準時。<br>
            <b>覆寫</b>未勾＝只補「目前空白」的量具（已個別設定過的不動）；勾了＝該類別全部覆寫。留空的欄位不會被更動。
        </div>
        <div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-bottom:6px;font-size:12px;color:#5b3a1e;">
            快速填入：週期 <input type="number" id="cycFillVal" min="0" step="1" style="width:70px;height:26px;border:1px solid #D8BE93;border-radius:4px;padding:0 6px;"> 月
            <button type="button" id="cycFillBtn" style="height:26px;font-size:12px;border:1px solid #D8BE93;border-radius:4px;background:#fff;color:#5b3a1e;cursor:pointer;padding:0 10px;">填入所有類別</button>
            <label style="margin:0;font-weight:normal;"><input type="checkbox" id="cycOvrAll"> 全部勾覆寫</label>
        </div>
        <div style="max-height:48vh;overflow-y:auto;">
            <table class="hist" id="cycTable">
                <thead><tr><th style="text-align:left;">類別</th><th>量具數</th><th>目前週期</th>
                    <th>新週期(月)</th><th>基準到期月</th><th>列入校驗率統計</th><th>覆寫</th></tr></thead>
                <tbody id="cycBody"></tbody>
            </table>
        </div>
    </div>
    <div class="m-foot">
        <button class="b-cancel" onclick="closeMask('cycMask')">取消</button>
        <button class="b-ok" onclick="submitCycle()">套用</button>
    </div>
</div></div>

<!-- 年度校驗紀錄／計畫表 modal -->
<div class="tc-mask" id="yearMask"><div class="tc-modal xwide">
    <div class="m-head"><span>年度校驗紀錄／計畫表</span><span class="m-close" onclick="closeMask('yearMask')">✕</span></div>
    <div class="m-body">
        <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:8px;">
            <label style="margin:0;font-size:13px;">年度</label>
            <select id="yrSel" style="height:28px;font-size:13px;border:1px solid #D8BE93;border-radius:4px;"></select>
            <span class="bt-quick">
                <button type="button" id="yrTabRec" class="btn-warm-on">校驗紀錄</button>
                <button type="button" id="yrTabPlan">校驗計畫表</button>
            </span>
            <button type="button" id="yrPrint" style="height:26px;font-size:12px;border:1px solid #d98a33;border-radius:4px;background:#F0A24B;color:#fff;cursor:pointer;padding:0 12px;margin-left:auto;">
                <i class="fa fa-print"></i> 列印／匯出PDF</button>
            <button type="button" id="yrCsv" style="height:26px;font-size:12px;border:1px solid #D8BE93;border-radius:4px;background:#fff;color:#5b3a1e;cursor:pointer;padding:0 12px;">匯出CSV</button>
        </div>
        <div id="yrPlanApprovalBar" style="display:none;font-size:12px;color:#5b3a1e;background:#FBF0DD;border:1px solid #E8D5B5;border-radius:6px;padding:6px 10px;margin-bottom:8px;"></div>
        <div id="yrBody" style="font-size:12px;color:#5b3a1e;max-height:62vh;overflow:auto;"></div>
    </div>
</div></div>


<!-- 類別設定 modal（只設校驗屬性；類別本身的新增/更名/刪除在 線上檢驗－量具設定） -->
<!-- 統一設定 modal（分頁：類別設定／校驗人員資格／附件設定；週期批次設定屬逐類別操作，仍留在工具列） -->
<div class="tc-mask" id="cfgMask"><div class="tc-modal xwide">
    <div class="m-head"><span>量測儀器校驗設定</span><span class="m-close" onclick="closeMask('cfgMask')">✕</span></div>
    <div class="cfg-tabs">
        <div class="tab active" data-pane="cfgCat">類別設定</div>
        <div class="tab" data-pane="cfgStaff">校驗人員資格</div>
        <div class="tab" data-pane="cfgAtt">附件設定</div>
        <div class="tab" data-pane="cfgApproval">核准與圖章</div>
        <div class="tab" data-pane="cfgAsdoc">AS文件編號綁定</div>
        <div class="tab" data-pane="cfgSpec">量具料號對應</div>
<?php if ($perms['isAdmin'] && (int)($tcUser['id'] ?? 0) === 1): ?>
        <div class="tab" data-pane="cfgClean" style="color:#DD5138;">清除測試資料</div>
<?php endif; ?>
    </div>
    <div class="cfg-pane" id="cfgCat">
<div class="m-body">
        <div style="font-size:12px;color:#8a6d45;margin-bottom:8px;line-height:1.7;">
            類別的<b>新增／更名／刪除</b>請至
            <a href="inspection_combined_prototype.php" target="_blank" style="color:#b5762a;">線上檢驗－量具設定</a>（本頁不重複提供）。<br>
            <b>需校驗</b>：不是實體量具、只是檢驗方式者（例如「目視」）請取消勾選，其量具不會出現在本頁、也不列入 KPI。<br>
            <b>可設定量具編號</b>：取消後該類別不能再新增／移入量具編號。<br>
            <b>列入分頁</b>：勾選者會在清單上方出現專屬分頁；需先勾「需校驗」才能設定，未列入分頁者歸在「其他」分頁。<br>
            <b>分頁名稱</b>：選「（自成一頁）」＝用類別名當分頁；也可把數個類別指到同一個自訂分頁合併顯示。
        </div>
        <div style="border:1px solid #EADFC8;border-radius:6px;padding:8px 10px;margin-bottom:8px;background:#FDF8EF;">
            <div style="font-size:12px;color:#5b3a1e;margin-bottom:6px;">
                <b>自訂分頁</b>（例如新增「分厘卡」，再把盤式／跨珠／針狀／外徑／珠徑分厘卡都指到它）
                <a href="#" onclick="addTab();return false;" style="color:#b5762a;margin-left:8px;"><i class="fa fa-plus"></i> 新增分頁</a>
            </div>
            <div id="tabChips" style="display:flex;flex-wrap:wrap;gap:6px;font-size:12px;"></div>
        </div>
        <div style="max-height:42vh;overflow-y:auto;">
        <table class="hist" id="catTable">
            <thead><tr>
                <th style="text-align:left;">類別</th><th>量具數</th>
                <th>需校驗<br><label class="ck-all-lab"><input type="checkbox" class="ck-all" data-col="req"> 全選</label></th>
                <th>可設定量具編號<br><label class="ck-all-lab"><input type="checkbox" class="ck-all" data-col="hasno"> 全選</label></th>
                <th>列入分頁<br><label class="ck-all-lab"><input type="checkbox" class="ck-all" data-col="tab"> 全選</label></th>
                <th>分頁名稱</th>
            </tr></thead>
            <tbody id="catBody"></tbody>
        </table>
        </div>
    </div>
    <div class="m-foot">
        <button class="b-cancel" onclick="closeMask('cfgMask')">取消</button>
        <button class="b-ok" onclick="submitCats()">儲存</button>
    </div>
    </div>
    <div class="cfg-pane" id="cfgStaff" style="display:none;">
<div class="m-body">
        <div style="font-size:12px;color:#8a6d45;margin-bottom:8px;line-height:1.7;">
            候選名單＝<b>品管部門（含子部門）</b>底下<b>未離職</b>人員，依職稱排序（名單已限定在該部門，故不另列部門與職稱欄）；品管部門一律沿用全站「組織角色綁定設定」的<b>品管部門</b>（含子部門），本頁不另設一份。<br>
            勾選者才會出現在「內校」的校驗人員下拉。長期請假（留職停薪／育嬰留停等）者會標記假別與期間，請自行判斷是否勾選。
        </div>
        <div id="staffHint" style="font-size:12px;color:#DD5138;margin-bottom:6px;"></div>
        <div style="max-height:46vh;overflow-y:auto;">
            <table class="hist" id="staffTable">
                <thead><tr><th style="text-align:left;">姓名</th><th>長期請假</th>
                    <th>具校驗資格<br><label class="ck-all-lab"><input type="checkbox" id="staffCkAll"> 全選</label></th></tr></thead>
                <tbody id="staffBody"></tbody>
            </table>
        </div>
    </div>
    <div class="m-foot">
        <button class="b-cancel" onclick="closeMask('cfgMask')">取消</button>
        <button class="b-ok" onclick="submitStaff()">儲存</button>
    </div>
    </div>
    <div class="cfg-pane" id="cfgAtt" style="display:none;">
<div class="m-body">
        <div style="font-size:12px;color:#8a6d45;margin-bottom:6px;line-height:1.7;">
            資料庫只存檔名，完整路徑一律用此處設定值即時組出——換 NAS 或搬資料夾時，
            把資料夾內檔案原樣複製過去、改這裡的路徑即可，舊附件立即可讀（不需改資料）。
        </div>
        <label>附件存放路徑（建議 UNC，例 \\NAS\ERP\量測儀器校驗\）</label>
        <input type="text" id="asDir" maxlength="255">
        <label>允許的副檔名（逗號分隔）</label>
        <input type="text" id="asExt" maxlength="255">
        <label>單檔大小上限（MB）</label>
        <input type="number" id="asMax" min="1" max="500" step="1">
        <label>文件類別（逗號分隔，供上傳時挑選）</label>
        <input type="text" id="asTypes" maxlength="255">
    </div>
    <div class="m-foot">
        <button class="b-cancel" onclick="closeMask('cfgMask')">取消</button>
        <button class="b-ok" onclick="submitAttSet()">儲存</button>
    </div>
    </div>

    <!-- 核准與圖章（管理員）：內校主管核准開關/核准鏈、逐列簽章/製表核准圖章樣式、三份列印文件的 AS 文件編號綁定 -->
    <div class="cfg-pane" id="cfgApproval" style="display:none;">
<div class="m-body">
        <div class="bt-sec"><div class="h" style="font-weight:bold;color:#8A5A2B;margin-bottom:6px;">內校主管核准</div>
            <label style="font-weight:normal;"><input type="checkbox" id="apNeed"> 內校紀錄需要主管核准（覆驗者一律要選，不受此開關影響；此開關只決定要不要再送一關主管核准）</label>
            <div style="font-size:12px;color:#8a6d45;margin:6px 0;">核准人員優先序：由上而下依序嘗試，取第一個有結果的方法；解析到送出登錄者本人會自動跳下一順位（迴避球員兼裁判）。</div>
            <div id="apChainBox"></div>
            <div class="grid2">
                <div><label>「部門或人員」方法 — 綁部門</label><select id="apDept"><option value="">（未設定）</option></select></div>
                <div><label>「部門或人員」方法 — 綁人員（優先於部門）</label><select id="apUser"><option value="">（未設定）</option></select></div>
            </div>
        </div>
        <div class="bt-sec" style="margin-top:14px;"><div class="h" style="font-weight:bold;color:#8A5A2B;margin-bottom:6px;">圖章樣式</div>
            <div class="grid2">
                <div><label>逐列簽章樣式（校驗人員／覆驗者／核准人）</label><select id="apListStamp"><option value="0">（預設樣式）</option></select></div>
                <div><label>製表／核准簽章樣式（年度校驗計畫表頁尾）</label><select id="apFooterStamp"><option value="0">（預設樣式）</option></select></div>
            </div>
            <div style="font-size:12px;color:#8a6d45;">圖章樣式請至「圖章管理 → 線上圖章設計」建立/挑選；有上傳掃描實體章的人一律優先用掃描章，這裡只影響沒掃描章時自動產生的印章樣式。</div>
        </div>
    </div>
    <div class="m-foot">
        <button class="b-cancel" onclick="closeMask('cfgMask')">取消</button>
        <button class="b-ok" onclick="submitApproval()">儲存</button>
    </div>
    </div>

    <!-- AS 文件編號綁定（管理員）：三份列印文件各自可綁一份 AS 文件 -->
    <div class="cfg-pane" id="cfgAsdoc" style="display:none;">
<div class="m-body">
        <div style="font-size:12px;color:#8a6d45;margin-bottom:8px;">列印表頭／頁尾的 AS 文件編號一律綁定這裡，不寫死；未綁定者列印時該欄留白。</div>
        <div id="asdocRows"></div>
    </div>
    <div class="m-foot"><button class="b-ok" onclick="closeMask('cfgMask')">關閉</button></div>
    </div>

    <!-- 量具料號對應（管理員）：規格掛到採購料號 purchase_item→purchase_spec，不另建量具規格主檔 -->
    <div class="cfg-pane" id="cfgSpec" style="display:none;">
<div class="m-body">
        <div style="font-size:12px;color:#8a6d45;margin-bottom:8px;line-height:1.7;">
            量具的<b>規格</b>掛到「<b>採購料號</b>」（品項 <code>purchase_item</code> → 規格 <code>purchase_spec</code>），
            全公司只留採購料號與產品料號兩種；<b>實體量具仍以量具編號為主</b>，這裡只是把它指向規格。<br>
            舊資料的規格是人工塞在量具編號裡（例 <code>A-200-Q 電子(0-25mm)</code>），
            按「<b>產生草稿</b>」會自動解析成 品項（＝類別）／規格文字（括號內）／型式（編號有「電子」者），
            <b>品牌與型號編號看不出來，請自行補</b>。<br>
            <b>品牌 ≠ 購買廠商</b>：品牌是「誰做的」（Mitutoyo…），廠商是「跟誰買的」。品牌欄可直接打字，
            也可從下拉挑<b>採購建立的品牌清單</b>；<b>品牌清單與「同一料號跟哪幾家買」都在「申請採購 → 採購品主檔」維護</b>（本頁只選不建）。<br>
            <b>確認無誤再按「確認並建立料號」才會寫入</b>：同名品項沿用既有、同品項＋同規格沿用既有規格，
            重複執行不會產生重複料號。校驗週期仍維持「依類別設定」，不受本功能影響。<br>
            <b>單位預設 PCS</b>（量具以支計）；沿用既有料號時只補「目前空白」的單位，已設定過的不會被覆寫。
        </div>
        <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:6px;font-size:12px;color:#5b3a1e;">
            <label style="margin:0;">採購品類別（新建品項掛在哪一類）</label>
            <select id="spCat" style="width:auto;height:26px;font-size:12px;border:1px solid #D8BE93;border-radius:4px;"></select>
            <label style="margin:0;">單位</label>
            <select id="spUnit" style="width:auto;height:26px;font-size:12px;border:1px solid #D8BE93;border-radius:4px;"></select>
            <button type="button" id="spGen" style="height:26px;font-size:12px;border:1px solid #D8BE93;border-radius:4px;background:#fff;color:#5b3a1e;cursor:pointer;padding:0 12px;">
                <i class="fa fa-magic"></i> 產生草稿</button>
            <label style="margin:0;font-weight:normal;"><input type="checkbox" id="spOnlyUnbound" checked> 只列尚未對應料號者</label>
            <span id="spHint" style="color:#b5762a;"></span>
            <span id="spBrandHint" style="color:#8a6d45;"></span>
            <datalist id="spBrandOptions"></datalist>
        </div>
        <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:6px;padding:6px 10px;
                    border:1px dashed #D8BE93;border-radius:6px;background:#FDF8EF;font-size:12px;color:#5b3a1e;">
            <b>批次自動建立</b>
            <span style="color:#8a6d45;">不必逐列確認，直接依解析結果一次建立並綁定（品牌留空，日後可在「申請採購→採購品主檔」補）</span>
            <button type="button" id="spAutoUnbound" style="height:26px;font-size:12px;border:1px solid #d98a33;border-radius:4px;background:#F0A24B;color:#fff;cursor:pointer;padding:0 12px;">
                <i class="fa fa-bolt"></i> 自動建立「尚未對應」的量具</button>
            <button type="button" id="spAutoAll" style="height:26px;font-size:12px;border:1px solid #D8BE93;border-radius:4px;background:#fff;color:#5b3a1e;cursor:pointer;padding:0 12px;">
                全部量具（含已對應者重新綁定）</button>
        </div>
        <div style="max-height:44vh;overflow:auto;">
            <table class="hist" id="spTable">
                <thead><tr>
                    <th style="width:52px;">建立<br><label class="ck-all-lab"><input type="checkbox" id="spCkAll"> 全選</label></th>
                    <th style="text-align:left;">量具編號</th><th>品項名稱</th><th>規格文字</th>
                    <th>型式</th><th>品牌</th><th>型號</th><th style="text-align:left;">將建立／沿用的規格</th><th>目前對應</th>
                </tr></thead>
                <tbody id="spBody"><tr><td colspan="9" style="padding:12px;color:#8a6d45;">請先按「產生草稿」</td></tr></tbody>
            </table>
        </div>
    </div>
    <div class="m-foot">
        <span id="spInfo" style="float:left;font-size:12px;color:#8a6d45;padding-top:7px;"></span>
        <button class="b-cancel" onclick="closeMask('cfgMask')">關閉</button>
        <button class="b-ok" onclick="submitSpec()">確認並建立料號</button>
    </div>
    </div>

<?php if ($perms['isAdmin'] && (int)($tcUser['id'] ?? 0) === 1): ?>
    <!-- 清除測試資料（僅超級管理員 id=1；破壞性操作，需密碼＋大寫 Y 雙重確認） -->
    <div class="cfg-pane" id="cfgClean" style="display:none;">
<div class="m-body">
        <div style="border:2px solid #DD5138;border-radius:6px;padding:10px 12px;background:#FFF3EF;
                    font-size:13px;color:#8A2B16;line-height:1.8;margin-bottom:10px;">
            <b><i class="fa fa-exclamation-triangle"></i> 這是破壞性操作，不可回復。</b><br>
            僅清除<b>本模組的校驗交易資料</b>，用於測試後歸零；量具主檔本身（編號／類別／採購料號對應）不會被刪除。
        </div>
        <div style="display:flex;flex-wrap:wrap;gap:14px;font-size:12px;">
            <div style="flex:1;min-width:260px;">
                <div style="font-weight:bold;color:#DD5138;margin-bottom:4px;">將被清除</div>
                <table class="hist" id="clDelTable"><tbody id="clDelBody"></tbody></table>
            </div>
            <div style="flex:1;min-width:260px;">
                <div style="font-weight:bold;color:#5b3a1e;margin-bottom:4px;">保留不動</div>
                <table class="hist" id="clKeepTable"><tbody id="clKeepBody"></tbody></table>
            </div>
        </div>
        <label>最高權限帳號（員工 id=1）的密碼 *</label>
        <input type="password" id="clPw" autocomplete="new-password">
        <label>請輸入大寫 <b>Y</b> 確認 *</label>
        <input type="text" id="clY" maxlength="1" style="width:90px;" autocomplete="off">
        <div id="clResult" style="font-size:12px;margin-top:10px;"></div>
    </div>
    <div class="m-foot">
        <button class="b-cancel" onclick="closeMask('cfgMask')">取消</button>
        <button class="b-ok" style="background:#DD5138;border-color:#c33f26;" onclick="submitClean()">
            <i class="fa fa-trash"></i> 清除測試資料</button>
    </div>
    </div>
<?php endif; ?>
</div></div>

<!-- 歷史 modal -->
<div class="tc-mask" id="hisMask"><div class="tc-modal wide">
    <div class="m-head"><span id="hisTitle">校驗歷史</span><span class="m-close" onclick="closeMask('hisMask')">✕</span></div>
    <div class="m-body" id="hisBody" style="font-size:13px;color:#5b3a1e;"></div>
</div></div>

<!-- 使用紀錄 modal（此量具反查用在哪些檢驗單） -->
<div class="tc-mask" id="useMask"><div class="tc-modal wide">
    <div class="m-head"><span id="useTitle">使用紀錄</span><span class="m-close" onclick="closeMask('useMask')">✕</span></div>
    <div class="m-body" id="useBody" style="font-size:13px;color:#5b3a1e;"></div>
</div></div>

<!-- 角色說明 modal -->
<div class="tc-mask" id="helpMask"><div class="tc-modal">
    <div class="m-head"><span>角色權限說明</span><span class="m-close" onclick="closeMask('helpMask')">✕</span></div>
    <div class="m-body" style="font-size:13px;color:#5b3a1e;line-height:1.8;">
        <b>校驗唯讀</b>：檢視儀器清單、校驗歷史、當月統計與匯出。<br>
        <b>校驗登錄</b>：唯讀＋登錄各儀器的校驗完成紀錄。<br>
        <b>校驗管理員</b>：登錄＋新增儀器、設定週期/列入統計/基準到期日、刪除誤登紀錄。<br>
        <b>管理者</b>：系統管理者固定擁有全部權限。<br>
        <hr style="border-color:#EADFC8;">
        本頁資料為 KPI「量測儀器按時校驗率(#18)」的計算來源；勾「列入校驗率統計」的儀器每月依到期日自動計入 KPI。
    </div>
</div></div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script src="../../resource/js/eg_date_fmt.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_date_fmt.js') ?>"></script>
<script src="../../resource/js/eg_stamp.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_stamp.js') ?>"></script>
<script src="../../resource/js/eg_asdoc_picker.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_asdoc_picker.js') ?>"></script>
<script>
$(document).ready(function(){
    var $am = $('#sidebar-menu .nav.side-menu > li.active');
    if ($am.length) { $am.removeClass('active').find('ul.child_menu').hide(); $am.find('li.current-page').removeClass('current-page'); }
    $('#sidebar-menu').css('visibility','visible');
});

var API = '../../src/store/ToolCalib_API.php';
var META = null, ROWS = [], PERMS = null, CATS = [], TABS_DEF = [];
var ATT_CFG = {types:[], ext:[], maxmb:20, dir:'', ext_raw:'', types_raw:''};
var curTab = '';   // 目前分頁：'' 全部 ｜ 類別id ｜ 'other' 其他（需校驗但未設為分頁）
var SEE_SPEC_CODE = false;   // 採購料號代碼只給採購看（後端 tool_calib_can_see_spec_code 決定）
var canView = <?= $perms['canView'] ? 'true' : 'false' ?>;
var RESULT_LABEL = {pass:'合格', pass_adjust:'校正後合格', fail:'不合格'};
var STATUS_LABEL = {overdue:'逾期', soon:'即將到期', ok:'正常', nobaseline:'未設基準', unmanaged:'未列入統計'};

function closeMask(id){ document.getElementById(id).style.display='none'; }
function openMask(id){ document.getElementById(id).style.display='block'; }
function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){
    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
function fmtDate(d){ return d ? String(d).substr(0,10) : ''; }
/* 顯示用日期（ai-rules/20：西元年一律 YYYY.MM.DD），新增的列印內容一律呼叫這支；既有 fmtDate() 保留給內部運算/既有畫面用 */
function dispDate(d){ return d ? egFmtDate(String(d).substr(0,10)) : ''; }
/* 到期一律以「月」為單位（同月內完成即算準時） */
function fmtMonth(d){ return d ? String(d).substr(0,7) : ''; }
function monthEnd(d){
    if (!d) return '';
    var y = parseInt(String(d).substr(0,4),10), m = parseInt(String(d).substr(5,2),10);
    var last = new Date(y, m, 0).getDate();
    return y + '-' + ('0'+m).slice(-2) + '-' + ('0'+last).slice(-2);
}
/** 準時＝實際校驗日 ≤ 到期月月底 */
function isOnTime(calibDate, dueDate){
    if (!calibDate || !dueDate) return null;
    return fmtDate(calibDate) <= monthEnd(dueDate);
}

function loadMeta(cb){
    $.getJSON(API, {action:'meta'}, function(m){
        if (!m.ok){ alert(m.error||'載入失敗'); return; }
        META = m; PERMS = m.perms;
        $('#ymSel').val(m.cur_ym);
        TABS_DEF = m.tabs || [];
        ATT_CFG = m.attach || ATT_CFG;
        STAFF = m.staff || []; STAFF_MULTI_DEPT = !!m.staff_multi_dept; QC_DEPT_SET = !!m.qc_dept_set;
        window.__ownCompany = m.company_name || '';
        setCats(m.categories);
        if (m.perms.canEdit)  { $('#btnBatch').show(); }
        if (m.perms.canAdmin) { $('#btnAdd').show(); $('#btnCycleSet').show(); $('#btnCfg').show(); }
        loadPendingCount();   // 核准人未必有登錄/管理權限，但要能看到待核准清單
        if (cb) cb();
    });
}
/* 類別清單（含旗標）：新增/更名/刪除類別在「線上檢驗－量具設定」，本頁只設校驗屬性 */
function setCats(cats){
    CATS = cats || [];
    if (META) META.categories = CATS;
    var sel = $('#setCat').val(), $sc = $('#setCat').empty();
    CATS.forEach(function(c){
        if (c.calib_required !== 1 || c.has_tool_no !== 1) return;   // 只列可掛量具編號的校驗類別
        $sc.append('<option value="'+c.QC_Tool_List_id+'">'+esc(c.QC_Tool)+'</option>');
    });
    $('#setCat').val(sel);
    renderTabs();
}

function loadList(){
    NProgress.start();
    $.getJSON(API, {action:'list', ym:$('#ymSel').val()}, function(res){
        NProgress.done();
        if (!res.ok){ alert(res.error||'載入失敗'); return; }
        ROWS = res.rows; PERMS = res.perms;
        SEE_SPEC_CODE = !!res.see_spec_code;
        if (res.tabs) TABS_DEF = res.tabs;
        if (res.categories) setCats(res.categories); else renderTabs();
        $('#tcExcluded').text(res.excluded > 0
            ? '　另有 '+res.excluded+' 支量具所屬類別未設為「需校驗」，未列入本頁與 KPI。' : '');
        renderStat(res.stat, res.ym);
        renderTable();
    }).fail(function(x){ NProgress.done(); alert('載入失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}

/* ---------- 類別分頁列（自成一頁＝類別名；合併分頁＝自訂名稱含數個類別） ---------- */
var TABS = [];      // [{key, name, catIds:[], ord}]
function buildTabs(){
    var reqCats = CATS.filter(function(c){ return c.calib_required===1 && c.calib_tab===1; });
    var out = [];
    // 1) 自訂合併分頁（有成員才出現）
    TABS_DEF.forEach(function(t){
        var mem = reqCats.filter(function(c){ return Number(c.calib_tab_group) === Number(t.tab_id); });
        if (!mem.length) return;
        out.push({key:'g'+t.tab_id, name:t.tab_name,
                  catIds:mem.map(function(c){ return String(c.QC_Tool_List_id); }),
                  ord:Math.min.apply(null, mem.map(function(c){ return Number(c.sort_order)||0; }))});
    });
    // 2) 自成一頁（未指定合併分頁者）
    reqCats.forEach(function(c){
        if (c.calib_tab_group) return;
        out.push({key:String(c.QC_Tool_List_id), name:c.QC_Tool,
                  catIds:[String(c.QC_Tool_List_id)], ord:Number(c.sort_order)||0});
    });
    out.sort(function(a,b){ return a.ord - b.ord; });
    return out;
}
function tabbedCatIds(){
    var s = [];
    TABS.forEach(function(t){ t.catIds.forEach(function(id){ if (s.indexOf(id)<0) s.push(id); }); });
    return s;
}
function renderTabs(){
    TABS = buildTabs();
    var inTab = tabbedCatIds(), cnt = {}, other = 0;
    ROWS.forEach(function(r){
        var k = String(r.QC_Tool_List_id);
        cnt[k] = (cnt[k]||0) + 1;
        if (inTab.indexOf(k) < 0) other++;
    });
    // 目前分頁若被取消設定/刪除 → 回到全部
    if (curTab !== '' && curTab !== 'other' && !TABS.some(function(t){ return t.key===curTab; })) curTab = '';
    var h = '<div class="tab'+(curTab===''?' active':'')+'" data-tab="">全部<span class="cnt">'+ROWS.length+'</span></div>';
    TABS.forEach(function(t){
        var n = 0;
        t.catIds.forEach(function(id){ n += (cnt[id]||0); });
        h += '<div class="tab'+(curTab===t.key?' active':'')+'" data-tab="'+t.key+'">'+esc(t.name)
           + '<span class="cnt">'+n+'</span></div>';
    });
    if (other > 0 || curTab === 'other')
        h += '<div class="tab'+(curTab==='other'?' active':'')+'" data-tab="other">其他<span class="cnt">'+other+'</span></div>';
    if (!TABS.length)
        h += '<span class="tab-hint">尚未設定分頁類別'+((PERMS&&PERMS.canAdmin)?'，可按工具列「類別設定」勾選':'')+'</span>';
    $('#tcTabs').html(h);
}
$('#tcTabs').on('click', '.tab', function(){
    curTab = String($(this).attr('data-tab')); tcPage = 1; renderTabs(); renderTable();
});

function renderStat(stat, ym){
    $('#stDen').text(stat.den);
    $('#stNum').text(stat.num);
    var $r = $('#stRate');
    if (stat.value === null){ $r.text('—').removeClass('below ok'); $('#stHint').text(ym+' 無應校驗儀器'); }
    else {
        var v = Math.round(stat.value*10)/10;
        $r.text(v+'%').toggleClass('below', v<95).toggleClass('ok', v>=95);
        $('#stHint').text(ym+'：'+stat.num+' / '+stat.den+' 準時完成');
    }
}

function statPill(s){ return '<span class="st-pill st-'+s+'">'+(STATUS_LABEL[s]||s)+'</span>'; }

var tcPage = 1;
function filteredRows(){
    var stt = $('#statSel').val(), kw = $.trim($('#kwSel').val()).toLowerCase();
    var inTab = tabbedCatIds();
    var cur = TABS.filter(function(t){ return t.key===curTab; })[0];
    return ROWS.filter(function(r){
        var cid = String(r.QC_Tool_List_id);
        if (curTab === 'other') { if (inTab.indexOf(cid) >= 0) return false; }
        else if (curTab !== '' && (!cur || cur.catIds.indexOf(cid) < 0)) return false;
        if (stt === 'managed' && r.calib_managed!==1) return false;
        if (stt && stt!=='managed' && r.status!==stt) return false;
        if (kw && String(r.Tool_No).toLowerCase().indexOf(kw)<0) return false;
        return true;
    });
}
function renderTable(){
    var list = filteredRows();
    var size = parseInt($('#tcPageSize').val(),10) || 10;
    var pages = Math.max(1, Math.ceil(list.length/size));
    if (tcPage > pages) tcPage = pages;
    if (tcPage < 1) tcPage = 1;
    var start = (tcPage-1)*size;
    var pageRows = list.slice(start, start+size);
    $('#tcCount').text('共 '+list.length+' 支量具');
    $('#tcPageInfo').text(tcPage+' / '+pages+' 頁');
    $('#tcPrev').prop('disabled', tcPage<=1);
    $('#tcNext').prop('disabled', tcPage>=pages);
    var html = '';
    pageRows.forEach(function(r){
        var last = r.last ? (fmtDate(r.last.calib_date)+'（'+(RESULT_LABEL[r.last.result]||r.last.result)+'）') : '—';
        var canEdit = PERMS.canEdit, canAdmin = PERMS.canAdmin;
        html += '<tr>';
        html += '<td class="t-left"><b>'+esc(r.Tool_No)+'</b></td>';
        html += '<td>'+esc(r.category_name||'')+'</td>';
        html += '<td class="t-left">'+specCell(r)+'</td>';
        html += '<td>'+(r.calib_cycle_months==null?'—':r.calib_cycle_months)+'</td>';
        html += '<td>'+esc(r.calib_method||'—')+'</td>';
        html += '<td>'+(fmtMonth(r.calibration_due)||'—')+'</td>';
        html += '<td>'+statPill(r.status)+'</td>';
        html += '<td>'+last+'</td>';
        html += '<td class="'+(r.calib_managed===1?'managed-yes':'managed-no')+'">'+(r.calib_managed===1?'✔ 是':'否')+'</td>';
        html += '<td>';
        html += canEdit ? '<span class="tc-op" onclick="openRec('+r.Tool_id+')"><i class="fa fa-pencil"></i>登錄</span>'
                        : '<span class="tc-op disabled"><i class="fa fa-pencil"></i>登錄</span>';
        html += canAdmin ? '<span class="tc-op" onclick="openSet('+r.Tool_id+')"><i class="fa fa-gear"></i>設定</span>' : '';
        html += '<span class="tc-op" onclick="openHis('+r.Tool_id+')"><i class="fa fa-history"></i>歷史</span>';
        html += '<span class="tc-op" onclick="openUse('+r.Tool_id+')"><i class="fa fa-search"></i>使用紀錄</span>';
        html += '<span class="tc-op" onclick="printDossier('+r.Tool_id+')"><i class="fa fa-id-card-o"></i>履歷表</span>';
        html += '</td></tr>';
    });
    $('#tcBody').html(html || '<tr><td colspan="10" style="padding:16px;color:#8a6d45;">無符合條件的儀器</td></tr>');
}
/**
 * 規格欄：只顯示規格文字，**不重複顯示品項名稱（＝左邊「類別」欄已經有了）**。
 * 採購料號代碼(spec_code)只給採購看得到，其他人用中文品名／規格查即可（使用者 2026-07-30 指示）。
 */
function specCell(r){
    if (!r.purchase_spec_id) return '<span style="color:#b0a390;">未對應料號</span>';
    // 品牌是獨立欄位（≠ 購買廠商），跟規格文字一起顯示才看得出是哪一支
    var t = $.trim(($.trim(r.spec_brand||'') + ' ' + $.trim(r.spec_text||''))) || '（未填規格）';
    var h = esc(t);
    if (SEE_SPEC_CODE && r.spec_code)
        h += ' <span style="color:#b0a390;font-size:11px;">'+esc(r.spec_code)+'</span>';
    return h;
}

$('#statSel').on('change', function(){ tcPage=1; renderTable(); });
$('#kwSel').on('input', function(){ tcPage=1; renderTable(); });
$('#tcPageSize').on('change', function(){ tcPage=1; renderTable(); });
$('#tcPrev').on('click', function(){ if(tcPage>1){ tcPage--; renderTable(); } });
$('#tcNext').on('click', function(){ tcPage++; renderTable(); });
$('#ymSel').on('change', loadList);

/* ---------- 登錄/編輯校驗 ---------- */
var recTool = null, editCalibId = null;
var REC_ATT = [];   // 新增登錄用的附件（同批次附件機制，先存 temp，登錄時轉正並對應到這一支量具）
function openRec(tid){
    var r = ROWS.find(function(x){ return x.Tool_id===tid; });
    recTool = r; editCalibId = null;
    $('#recTitle').text('登錄校驗：'+r.Tool_No+'（'+(r.category_name||'')+'）');
    $('#recInfo').html('目前下次應校驗月：<b>'+(fmtMonth(r.calibration_due)||'（未設定）')+'</b>　週期：'
        +(r.calib_cycle_months==null?'（未設）':r.calib_cycle_months+' 月'));
    $('#recDate').val(META.today);
    $('#recResult').val('pass');
    $('#recMethod').val(r.calib_method || '外校');
    opInit('recOpBox', $('#recMethod').val(), null);
    refreshReviewerBox('recOpBox');
    $('#recCert').val(''); $('#recNote').val('');
    REC_ATT = []; $('#recFile').val('');
    $('#recAttHint').text('可用格式：' + (ATT_CFG.ext||[]).join('、') + '；單檔上限 ' + ATT_CFG.maxmb + ' MB');
    renderRecAtt();
    $('#recAttSec').show();
    updateRoll();
    openMask('recMask');
    setTimeout(function(){ $('#recDate').focus(); }, 100);
}
function editHis(cid){
    var a = (HISTORY||[]).find(function(x){ return String(x.calib_id)===String(cid); });
    if (!a) return;
    recTool = ROWS.find(function(x){ return x.Tool_id===HIST_TID; }) || {calib_cycle_months:null};
    editCalibId = cid;
    $('#recTitle').text('編輯校驗紀錄');
    $('#recInfo').html('本次應校驗到期月：<b>'+(fmtMonth(a.due_date)||'（無）')+'</b>（編輯不改到期基準，僅修正內容；覆驗者／附件請於「批次校驗」重新登錄該次紀錄調整）');
    $('#recDate').val(fmtDate(a.calib_date));
    $('#recResult').val(a.result);
    $('#recMethod').val(a.method||'');
    opInit('recOpBox', a.method||'', {userId:a.operator_user_id||0, vendorId:a.vendor_id||'',
                                      vendorName:(a.vendor_id ? (a.operator||'') : ''), text:(a.operator||'')});
    $('#recCert').val(a.cert_no||''); $('#recNote').val(a.note||'');
    $('#recReviewerBox').hide();
    $('#recAttSec').hide();
    updateRoll();
    closeMask('hisMask'); openMask('recMask');
    setTimeout(function(){ $('#recDate').focus(); }, 100);
}
function updateRoll(){
    var cyc = recTool.calib_cycle_months, d = $('#recDate').val();
    if (cyc==null || !d){ $('#recRoll').text(''); return; }
    var dt = new Date(d); dt.setMonth(dt.getMonth()+parseInt(cyc,10));
    $('#recRoll').text('登錄後下次應校驗月將前滾為 '+dt.toISOString().substr(0,7)+'（依週期 '+cyc+' 月）');
}
$('#recDate').on('change', updateRoll);

/* ---------- 登錄校驗附件（單支；沿用批次附件的 temp/active 機制，登錄時對應到這一支量具） ---------- */
$('#recFile').on('change', function(){
    var files = this.files;
    if (!files || !files.length) return;
    var i = 0;
    function next(){
        if (i >= files.length){ $('#recFile').val(''); renderRecAtt(); return; }
        var fd = new FormData();
        fd.append('action', 'upload_attach'); fd.append('batch_id', 0);
        fd.append('category_id', recTool.QC_Tool_List_id || 0);
        fd.append('doc_type', (ATT_CFG.types||[])[0] || '校驗報告');
        fd.append('file', files[i]);
        NProgress.start();
        $.ajax({url:API, type:'POST', data:fd, processData:false, contentType:false, dataType:'json'})
            .done(function(res){
                if (!res.ok) { alert(res.error||'上傳失敗'); return; }
                REC_ATT.push({attach_id:res.attach_id, name:res.original_name, doc_type:res.doc_type||'', note:''});
            })
            .fail(function(x){ alert('上傳失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); })
            .always(function(){ NProgress.done(); i++; renderRecAtt(); next(); });
    }
    next();
});
function renderRecAtt(){
    var h = REC_ATT.map(function(a, idx){
        return '<div class="att-row" data-idx="'+idx+'">'
            + '<span class="fn"><i class="fa fa-file-o"></i> '+esc(a.name)+'</span>'
            + ' <select class="rec-att-type">'+typeOptions(a.doc_type)+'</select>'
            + ' <input type="text" class="rec-att-note" placeholder="附件備註" value="'+esc(a.note)+'" style="width:150px;font-size:12px;border:1px solid #D8BE93;border-radius:3px;padding:1px 4px;">'
            + ' <span class="op del"><i class="fa fa-trash"></i> 刪除</span></div>';
    }).join('');
    $('#recAttList').html(h || '<div style="font-size:12px;color:#8a6d45;">尚未上傳附件（可不上傳）。</div>');
}
$('#recAttList').on('change', '.rec-att-type', function(){ REC_ATT[+$(this).closest('.att-row').attr('data-idx')].doc_type = this.value; });
$('#recAttList').on('input', '.rec-att-note', function(){ REC_ATT[+$(this).closest('.att-row').attr('data-idx')].note = this.value; });
$('#recAttList').on('click', '.op.del', function(){
    var idx = +$(this).closest('.att-row').attr('data-idx'), a = REC_ATT[idx];
    if (!confirm('刪除附件「'+a.name+'」？')) return;
    $.post(API, {action:'delete_attach', attach_id:a.attach_id}, function(res){
        if (!res.ok){ alert(res.error||'刪除失敗'); return; }
        REC_ATT.splice(idx, 1); renderRecAtt();
    }, 'json').fail(function(x){ alert('刪除失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
});

function submitRec(){
    if (!$('#recDate').val()){ alert('請選擇校驗完成日'); return; }
    var op = opValue('recOpBox');
    if (!op){
        alert($('#recMethod').val()==='內校' ? '請選擇校驗人員（內校）'
            : ($('#recMethod').val()==='外校' ? '請搜尋並選擇外校廠商' : '請填寫校驗人員／單位'));
        return;
    }
    if (editCalibId){
        var data = {action:'edit_calib', calib_id:editCalibId, calib_date:$('#recDate').val(), result:$('#recResult').val(),
            method:$('#recMethod').val(), operator:op.operator, operator_user_id:op.operator_user_id, vendor_id:op.vendor_id,
            cert_no:$('#recCert').val(), note:$('#recNote').val()};
        $.post(API, data, function(res){
            if (!res.ok){ alert(res.error||'儲存失敗'); return; }
            closeMask('recMask'); loadList();
        }, 'json').fail(function(x){ alert('儲存失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
        return;
    }
    // 新增登錄：走批次校驗共用的 create_batch（只選這一支量具），一併帶覆驗者與附件
    var reviewerUid = '';
    if ($('#recMethod').val()==='內校') {
        reviewerUid = $('#recReviewer').val();
        if (!reviewerUid){ alert('內校請選擇覆驗者'); return; }
        if (String(reviewerUid)===String(op.operator_user_id)){ alert('覆驗者不可與校驗人員為同一人'); return; }
    }
    var attach = REC_ATT.map(function(a){
        return {attach_id:a.attach_id, doc_type:a.doc_type, note:a.note, category_id:recTool.QC_Tool_List_id||0, tool_ids:[recTool.Tool_id]};
    });
    NProgress.start();
    $.post(API, {action:'create_batch', calib_date:$('#recDate').val(), method:$('#recMethod').val(),
                 operator:op.operator, operator_user_id:op.operator_user_id, vendor_id:op.vendor_id,
                 reviewer_user_id:reviewerUid, cert_no:$('#recCert').val(), note:$('#recNote').val(),
                 tools:JSON.stringify([{tool_id:recTool.Tool_id, result:$('#recResult').val()}]),
                 attach:JSON.stringify(attach)}, function(res){
        NProgress.done();
        if (!res.ok){ alert(res.error||'儲存失敗'); return; }
        REC_ATT = [];
        closeMask('recMask');
        if (res.approval_status==='pending') alert('已登錄，本筆內校紀錄需主管核准，已送出通知。');
        loadList();
    }, 'json').fail(function(x){ NProgress.done(); alert('儲存失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}

/* ---------- 設定 / 新增 ---------- */
var setTool = null;
function openSet(tid){
    setTool = tid ? ROWS.find(function(x){ return x.Tool_id===tid; }) : null;
    $('#setCat option.tmp-cat').remove();          // 清掉上一支量具補上的「目前類別」暫時選項
    if (!$('#setCat option').length && !setTool){
        alert('目前沒有「需校驗且可設定量具編號」的類別。\n請先按工具列「類別設定」勾選，或至「線上檢驗－量具設定」新增類別。');
        return;
    }
    if (setTool){
        $('#setTitle').text('儀器設定：'+setTool.Tool_No);
        $('#setNoBox,#setCatBox').show();
        $('#setNo').val(setTool.Tool_No);
        // 該量具目前類別若已被設為不可掛編號（下拉不含）→ 補一個選項，避免存檔時被誤改類別
        var cid = String(setTool.QC_Tool_List_id);
        if (!$('#setCat option[value="'+cid+'"]').length)
            $('#setCat').append('<option class="tmp-cat" value="'+cid+'">'+esc(setTool.category_name||cid)+'（目前類別）</option>');
        $('#setCat').val(cid);
        $('#setCycle').val(setTool.calib_cycle_months==null?'':setTool.calib_cycle_months);
        $('#setMethod').val(setTool.calib_method||'');
        $('#setBase').val(fmtMonth(setTool.calibration_due));
        $('#setManaged').val(String(setTool.calib_managed));
    } else {
        $('#setTitle').text('新增儀器');
        $('#setNoBox,#setCatBox').show();
        $('#setNo').val(''); $('#setCat').prop('selectedIndex',0);
        $('#setCycle').val(12); $('#setMethod').val(''); $('#setBase').val(''); $('#setManaged').val('1');
    }
    openMask('setMask');
}
$('#btnAdd').on('click', function(){ openSet(null); });
function submitSet(){
    if (!$.trim($('#setNo').val())){ alert('請填量具編號'); return; }
    var data = {cycle:$('#setCycle').val(), managed:$('#setManaged').val(),
                method:$('#setMethod').val(), baseline_due:$('#setBase').val(),
                tool_no:$('#setNo').val(), category_id:$('#setCat').val()};
    if (setTool){ data.action='save_tool'; data.tool_id=setTool.Tool_id; }
    else { data.action='create_tool'; }
    $.post(API, data, function(res){
        if (!res.ok){ alert(res.error||'儲存失敗'); return; }
        closeMask('setMask'); loadList();
    }, 'json').fail(function(x){ alert('儲存失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}

/* ---------- 類別設定（管理員；校驗屬性旗標＋自訂合併分頁） ---------- */
/** 讀取 modal 目前畫面上的勾選狀態（新增/刪除分頁後重繪時保留未存的編輯） */
function collectCatUI(){
    var st = {};
    $('#catBody tr[data-id]').each(function(){
        var $tr = $(this);
        st[String($tr.attr('data-id'))] = {
            req:  $tr.find('.ck-req').prop('checked') ? 1 : 0,
            hasNo:$tr.find('.ck-hasno').prop('checked') ? 1 : 0,
            tab:  $tr.find('.ck-tab').prop('checked') ? 1 : 0,
            grp:  $tr.find('.sel-grp').val() || ''
        };
    });
    return st;
}
function grpOptions(sel){
    var h = '<option value="">（自成一頁）</option>';
    TABS_DEF.forEach(function(t){
        h += '<option value="'+t.tab_id+'"'+(String(sel)===String(t.tab_id)?' selected':'')+'>'+esc(t.tab_name)+'</option>';
    });
    return h;
}
function renderCatBody(state){
    var h = CATS.map(function(c){
        var id = String(c.QC_Tool_List_id), s = state && state[id];
        var req   = s ? s.req===1   : c.calib_required===1;
        var hasNo = s ? s.hasNo===1 : c.has_tool_no===1;
        var tab   = (s ? s.tab===1  : c.calib_tab===1) && req;
        var grp   = s ? s.grp : (c.calib_tab_group || '');
        if (grp && !TABS_DEF.some(function(t){ return String(t.tab_id)===String(grp); })) grp = '';   // 分頁已被刪除
        return '<tr data-id="'+id+'">'
            + '<td style="text-align:left;">'+esc(c.QC_Tool)+'</td>'
            + '<td>'+c.tool_cnt+(c.managed_cnt>0 ? '（列入統計 '+c.managed_cnt+'）' : '')+'</td>'
            + '<td><input type="checkbox" class="ck-req"'+(req?' checked':'')+'></td>'
            + '<td><input type="checkbox" class="ck-hasno"'+(hasNo?' checked':'')+'></td>'
            + '<td><input type="checkbox" class="ck-tab"'+(tab?' checked':'')+(req?'':' disabled')+'></td>'
            + '<td><select class="sel-grp"'+(tab?'':' disabled')+'>'+grpOptions(tab?grp:'')+'</select></td>'
            + '</tr>';
    }).join('');
    $('#catBody').html(h || '<tr><td colspan="6" style="color:#8a6d45;padding:12px;">尚無量具類別</td></tr>');
    syncCkAll();
}
function renderTabChips(){
    var h = TABS_DEF.map(function(t){
        return '<span class="tab-chip" data-id="'+t.tab_id+'">'+esc(t.tab_name)
             + '（'+t.cat_cnt+' 類）<i class="fa fa-pencil" title="改名"></i>'
             + '<i class="fa fa-trash del" title="刪除（成員類別退回自成一頁）"></i></span>';
    }).join('');
    $('#tabChips').html(h || '<span style="color:#8a6d45;">尚未建立自訂分頁（每個勾選的類別各自一頁）</span>');
}
function loadCatPane(){ renderTabChips(); renderCatBody(null); }

/** 單欄全選／全不選；「列入分頁」只作用在有勾「需校驗」的列 */
$('#catTable').on('change', '.ck-all', function(){
    var col = $(this).attr('data-col'), on = this.checked;
    $('#catBody tr[data-id]').each(function(){
        var $tr = $(this);
        if (col === 'req')        $tr.find('.ck-req').prop('checked', on);
        else if (col === 'hasno') $tr.find('.ck-hasno').prop('checked', on);
        else if ($tr.find('.ck-req').prop('checked')) $tr.find('.ck-tab').prop('checked', on);
        syncCatRow($tr);
    });
});
/** 單列連動：需校驗→列入分頁→分頁名稱，逐層鎖住 */
function syncCatRow($tr){
    var req = $tr.find('.ck-req').prop('checked');
    $tr.find('.ck-tab').prop('disabled', !req);
    if (!req) $tr.find('.ck-tab').prop('checked', false);
    var tab = $tr.find('.ck-tab').prop('checked');
    $tr.find('.sel-grp').prop('disabled', !tab);
    if (!tab) $tr.find('.sel-grp').val('');
}
/** 表頭全選框狀態跟著列的實際勾選數走 */
function syncCkAll(){
    var n = $('#catBody tr[data-id]').length;
    [['req','.ck-req'],['hasno','.ck-hasno'],['tab','.ck-tab']].forEach(function(p){
        var c = $('#catBody '+p[1]+':checked').length;
        $('#catTable .ck-all[data-col="'+p[0]+'"]').prop('checked', n>0 && c===n);
    });
}
$('#catBody').on('change', '.ck-req, .ck-tab', function(){ syncCatRow($(this).closest('tr')); syncCkAll(); });
$('#catBody').on('change', '.ck-hasno', syncCkAll);

/* 自訂分頁：新增／改名／刪除（即時寫 DB，畫面上未存的勾選會保留） */
function addTab(){
    var name = prompt('新增分頁名稱（例：分厘卡）：');
    if (name === null) return;
    name = $.trim(name);
    if (!name) return;
    saveTab(0, name);
}
function saveTab(tabId, name){
    var st = collectCatUI();
    $.post(API, {action:'save_tab', tab_id:tabId, name:name}, function(res){
        if (!res.ok){ alert(res.error||'儲存失敗'); return; }
        TABS_DEF = res.tabs; CATS = res.categories;
        renderTabChips(); renderCatBody(st); renderTabs();
    }, 'json').fail(function(x){ alert('儲存失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}
$('#tabChips').on('click', '.fa-pencil', function(){
    var $c = $(this).closest('.tab-chip'), id = $c.attr('data-id');
    var t = TABS_DEF.filter(function(x){ return String(x.tab_id)===String(id); })[0] || {};
    var name = prompt('分頁改名：', t.tab_name || '');
    if (name === null) return;
    name = $.trim(name);
    if (!name) return;
    saveTab(id, name);
});
$('#tabChips').on('click', '.fa-trash', function(){
    var id = $(this).closest('.tab-chip').attr('data-id');
    if (!confirm('刪除此自訂分頁？（原本指到它的類別會退回「自成一頁」，量具資料不受影響）')) return;
    var st = collectCatUI();
    $.post(API, {action:'delete_tab', tab_id:id}, function(res){
        if (!res.ok){ alert(res.error||'刪除失敗'); return; }
        TABS_DEF = res.tabs; CATS = res.categories;
        renderTabChips(); renderCatBody(st); renderTabs();
    }, 'json').fail(function(x){ alert('刪除失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
});

function submitCats(){
    var items = [], warn = [];
    $('#catBody tr[data-id]').each(function(){
        var $tr = $(this), id = $tr.attr('data-id');
        var c = CATS.filter(function(x){ return String(x.QC_Tool_List_id)===String(id); })[0] || {};
        var req = $tr.find('.ck-req').prop('checked') ? 1 : 0;
        if (!req && (c.managed_cnt||0) > 0) warn.push('・'+c.QC_Tool+'（'+c.managed_cnt+' 支已列入統計）');
        items.push({id:id, calib_required:req,
                    has_tool_no:$tr.find('.ck-hasno').prop('checked')?1:0,
                    calib_tab:$tr.find('.ck-tab').prop('checked')?1:0,
                    calib_tab_group:$tr.find('.sel-grp').val() || 0});
    });
    if (warn.length && !confirm('下列類別取消「需校驗」後，其量具將不再顯示於本頁、也不計入 KPI：\n'
        + warn.join('\n') + '\n\n確定儲存？')) return;
    $.post(API, {action:'save_categories', items: JSON.stringify(items)}, function(res){
        if (!res.ok){ alert(res.error||'儲存失敗'); return; }
        if (res.tabs) TABS_DEF = res.tabs;
        setCats(res.categories); closeMask('cfgMask'); loadList();
    }, 'json').fail(function(x){ alert('儲存失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}

/* ---------- 歷史 ---------- */
var HISTORY = [], HIST_TID = null;
function openHis(tid){
    HIST_TID = tid;
    $.getJSON(API, {action:'history', tool_id:tid}, function(res){
        if (!res.ok){ alert(res.error||'載入失敗'); return; }
        HISTORY = res.list;
        $('#hisTitle').text('校驗歷史：'+res.tool.Tool_No+'（'+(res.tool.category_name||'')+'）');
        if (!res.list.length){ $('#hisBody').html('<div style="color:#8a6d45;padding:12px;">尚無校驗紀錄</div>'); openMask('hisMask'); return; }
        var canEdit = PERMS.canEdit, canDel = res.can_delete;
        var h = '<table class="hist"><thead><tr><th>應校驗到期月</th><th>校驗完成日</th><th>準時</th><th>結果</th>'
              + '<th>方式</th><th>人員/單位</th><th>憑證編號</th><th>下次到期</th><th>附件</th><th>登錄者</th>'
              + ((canEdit||canDel)?'<th>操作</th>':'') + '</tr></thead><tbody>';
        res.list.forEach(function(a){
            var ontime = isOnTime(a.calib_date, a.due_date);
            h += '<tr>';
            h += '<td>'+(fmtMonth(a.due_date)||'—')+'</td>';
            h += '<td>'+fmtDate(a.calib_date)+'</td>';
            h += '<td>'+(ontime===null?'—':(ontime?'<span style="color:#8A5A2B;">準時</span>':'<span style="color:#DD5138;">逾期</span>'))+'</td>';
            h += '<td>'+(RESULT_LABEL[a.result]||a.result)+'</td>';
            h += '<td>'+esc(a.method||'—')+'</td>';
            h += '<td>'+esc(a.operator||'—')+'</td>';
            h += '<td>'+esc(a.cert_no||'—')+'</td>';
            h += '<td>'+(fmtMonth(a.next_due)||'—')+'</td>';
            // 批次校驗的共用報告：一份附件可對應多支量具，這裡只列對應到本支者
            var att = (a.attaches||[]).map(function(x){
                return '<a href="'+API+'?action=download_attach&attach_id='+x.attach_id+'" target="_blank" '
                     + 'style="color:#b5762a;" title="'+esc(x.original_name||'')+'"><i class="fa fa-paperclip"></i>'
                     + esc(x.doc_type||'附件')+'</a>';
            }).join('　');
            h += '<td>'+(att || '—')+'</td>';
            h += '<td>'+esc(a.created_by_name||'')+'</td>';
            if (canEdit || canDel){
                h += '<td>';
                if (canEdit) h += '<span class="tc-op" onclick="editHis('+a.calib_id+')"><i class="fa fa-pencil"></i></span>';
                if (canDel) h += '<span class="tc-op" style="color:#DD5138;" onclick="delCalib('+a.calib_id+')"><i class="fa fa-trash"></i></span>';
                h += '</td>';
            }
            h += '</tr>';
        });
        h += '</tbody></table>';
        $('#hisBody').html(h);
        openMask('hisMask');
    });
}
function delCalib(cid){
    if (!confirm('刪除此校驗紀錄？（將依剩餘紀錄修復下次應校驗日）')) return;
    $.post(API, {action:'delete_calib', calib_id:cid}, function(res){
        if (!res.ok){ alert(res.error||'刪除失敗'); return; }
        closeMask('hisMask'); loadList();
    }, 'json');
}

/* ---------- 使用紀錄（此量具反查用在哪些檢驗單，資料來自 qc_measurement.tool_id）---------- */
function openUse(tid){
    $.getJSON(API, {action:'usage_history', tool_id:tid}, function(res){
        if (!res.ok){ alert(res.error||'載入失敗'); return; }
        $('#useTitle').text('使用紀錄：'+res.tool.Tool_No+'（'+(res.tool.category_name||'')+'）');
        if (!res.list.length){ $('#useBody').html('<div style="color:#8a6d45;padding:12px;">此量具尚未用於任何檢驗紀錄</div>'); openMask('useMask'); return; }
        var h = '<div style="color:#8a6d45;margin-bottom:6px;">共 '+res.list.length+' 筆檢驗紀錄使用過此量具</div>'
              + '<table class="hist"><thead><tr><th>檢驗日期</th><th>料號</th><th>製程</th>'
              + '<th>測項數</th><th>整體判定</th><th>檢驗人員</th></tr></thead><tbody>';
        res.list.forEach(function(a){
            h += '<tr>';
            h += '<td>'+fmtDate(a.check_date)+'</td>';
            h += '<td class="t-left">'+esc(a.part_no||'—')+'</td>';
            h += '<td class="t-left">'+esc(a.process_name||'—')+'</td>';
            h += '<td>'+a.item_count+'</td>';
            h += '<td>'+(a.check_result==='NG' ? '<span style="color:#DD5138;">不良</span>' : '<span style="color:#8A5A2B;">合格</span>')+'</td>';
            h += '<td>'+esc(a.creator_name||'')+'</td>';
            h += '</tr>';
        });
        h += '</tbody></table>';
        $('#useBody').html(h);
        openMask('useMask');
    });
}

/* ---------- 檢驗設備履歷表（沿用「歷史」既有資料出列印版，使用者 2026-08-12 明確要求） ---------- */
function printDossier(tid){
    $.getJSON(API, {action:'history', tool_id:tid}, function(res){
        if (!res.ok){ alert(res.error||'載入失敗'); return; }
        var t = res.tool;
        var specTxt = t.spec ? $.trim(($.trim(t.spec.brand||'')+' '+$.trim(t.spec.spec_text||''))) : '（未對應料號規格）';
        var h = '<table class="hist" style="margin-bottom:14px;"><tbody>'
          + '<tr><th style="width:110px;">量具編號</th><td><b>'+esc(t.Tool_No)+'</b></td><th style="width:110px;">類別</th><td>'+esc(t.category_name||'')+'</td></tr>'
          + '<tr><th>規格</th><td>'+esc(specTxt)+'</td><th>校驗週期</th><td>'+(t.calib_cycle_months==null?'（未設）':t.calib_cycle_months+' 月')+'</td></tr>'
          + '<tr><th>校驗方式</th><td>'+esc(t.calib_method||'—')+'</td><th>目前下次應校驗月</th><td>'+(fmtMonth(t.calibration_due)||'（未設定）')+'</td></tr>'
          + '<tr><th>列入校驗率統計</th><td colspan="3">'+(t.calib_managed===1?'是':'否')+'</td></tr>'
          + '</tbody></table>';
        h += '<div style="font-weight:bold;color:#5b3a1e;margin-bottom:4px;">校驗歷史（共 '+res.list.length+' 筆）</div>';
        if (!res.list.length) {
            h += '<div style="color:#8a6d45;padding:8px 0;">尚無校驗紀錄</div>';
        } else {
            h += '<table class="hist"><thead><tr><th>校驗完成日</th><th>應校驗到期月</th><th>結果</th><th>方式</th>'
               + '<th>校驗人員／單位</th><th>覆驗者</th><th>核准</th><th>憑證編號</th><th>下次到期</th><th>附件</th></tr></thead><tbody>';
            res.list.forEach(function(a){
                h += '<tr><td>'+dispDate(a.calib_date)+'</td><td>'+(fmtMonth(a.due_date)||'—')+'</td>'
                   + '<td>'+(RESULT_LABEL[a.result]||a.result)+'</td><td>'+esc(a.method||'—')+'</td>'
                   + '<td style="text-align:left;">'+signerCellHtml(a.operator, a.calib_date, a.method, true)+'</td>'
                   + '<td style="text-align:left;">'+signerCellHtml(a.reviewer_name, a.calib_date, a.method, true)+'</td>'
                   + '<td>'+(a.approval_status==='approved' ? signerCellHtml(a.approver_name, a.approved_at, '內校', true)
                            : (a.approval_status==='pending' ? '（核准中）' : (a.approval_status==='rejected' ? '（已退回）' : '（免核准）')))+'</td>'
                   + '<td>'+esc(a.cert_no||'—')+'</td><td>'+(fmtMonth(a.next_due)||'—')+'</td>'
                   + '<td style="text-align:left;">'+attachCellHtml(a.attaches)+'</td></tr>';
            });
            h += '</tbody></table>';
        }
        var docNo = asdocNo(META && META.as_docs ? META.as_docs['tool_calib_dossier'] : null);
        var title = '檢驗設備履歷表：'+t.Tool_No;
        var w = window.open('', '_blank');
        if (!w){ alert('瀏覽器阻擋了列印視窗，請允許彈出視窗'); return; }
        w.document.write('<!DOCTYPE html><html lang="zh-Hant"><head><meta charset="utf-8"><title>'+title+'</title><style>'
          + 'body{font-family:"Microsoft JhengHei",sans-serif;color:#3b2a17;margin:0;}'
          + '.pg{margin:12mm 8mm 16mm;}'
          + '.co{text-align:center;font-size:20px;font-weight:bold;letter-spacing:2px;}'
          + 'h2{font-size:15px;margin:2px 0 10px;text-align:center;}'
          + 'table{width:100%;border-collapse:collapse;font-size:11px;margin-bottom:4px;}'
          + 'th,td{border:1px solid #999;padding:3px 5px;text-align:center;}'
          + 'table.hist th{background:#F7E0BD;-webkit-print-color-adjust:exact;print-color-adjust:exact;}'
          + 'thead{display:table-header-group;} tbody tr{page-break-inside:avoid;}'
          + 'svg{width:66px !important;height:66px !important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}'
          + '@page{size:A4 landscape;margin:10mm 8mm 16mm 8mm;'
          + (docNo ? '@bottom-right{content:"'+docNo.replace(/["\\]/g,'')+'";font-size:9pt;color:#333;}' : '')
          + '@bottom-left{content:"第" counter(page) "頁／共" counter(pages) "頁";font-size:9pt;color:#333;}}'
          + '</style></head><body><div class="pg">'
          + '<div class="co">'+esc((META&&META.company_name)||'')+'</div>'
          + '<h2>'+title+'</h2>'
          + h + '</div></body></html>');
        w.document.close();
        w.focus();
        setTimeout(function(){ w.print(); }, 300);
    });
}

/* ================= 批次校驗（外校/廠內批量校驗：一次多支＋共用報告附件） ================= */
var BT_SEL = {};    // {tool_id: result}
var BT_ATT = [];    // [{attach_id, name, size, doc_type, note, category_id, toolIds:null|[ids]}]  toolIds=null → 對應本批全部
function resOptions(sel){
    return ['pass','pass_adjust','fail'].map(function(v){
        return '<option value="'+v+'"'+(v===sel?' selected':'')+'>'+RESULT_LABEL[v]+'</option>';
    }).join('');
}
function typeOptions(sel){
    var list = (ATT_CFG.types||[]);
    if (!list.length) list = ['校驗報告'];
    return list.map(function(v){
        return '<option value="'+esc(v)+'"'+(v===sel?' selected':'')+'>'+esc(v)+'</option>';
    }).join('');
}
$('#btnBatch').on('click', openBatch);
function openBatch(){
    if (!ROWS.length){ alert('目前清單沒有量具可登錄'); return; }
    BT_SEL = {}; BT_ATT = [];
    $('#btDate').val(META.today); $('#btMethod').val('外校'); $('#btResult').val('pass');
    opInit('btOpBox', '外校', null);
    refreshReviewerBox('btOpBox');
    $('#btCert').val(''); $('#btNote').val('');
    $('#btFile').val(''); $('#btKw').val('');
    $('#btAttHint').text('可用格式：' + (ATT_CFG.ext||[]).join('、') + '；單檔上限 ' + ATT_CFG.maxmb + ' MB');
    // 類別下拉（含「目前分頁」快捷）
    var cur = TABS.filter(function(t){ return t.key===curTab; })[0];
    var h = '';
    if (cur) h += '<option value="__tab__">目前分頁：'+esc(cur.name)+'</option>';
    h += '<option value="">全部類別</option>';
    var seen = [];
    ROWS.forEach(function(r){
        var id = String(r.QC_Tool_List_id);
        if (seen.indexOf(id) >= 0) return;
        seen.push(id);
        var n = ROWS.filter(function(x){ return String(x.QC_Tool_List_id)===id; }).length;
        h += '<option value="'+id+'">'+esc(r.category_name||id)+'（'+n+'）</option>';
    });
    $('#btCat').html(h);
    renderBtTools(); renderBtAtt();
    openMask('batMask');
}
function closeRec(){
    if (REC_ATT.length && !confirm('取消將刪除本次已上傳的 '+REC_ATT.length+' 份附件，確定取消？')) return;
    var ids = REC_ATT.map(function(a){ return a.attach_id; });
    REC_ATT = [];
    closeMask('recMask');
    ids.forEach(function(id){ $.post(API, {action:'delete_attach', attach_id:id}, function(){}, 'json'); });
}
function closeBatch(){
    if (BT_ATT.length && !confirm('取消將刪除本次已上傳的 '+BT_ATT.length+' 份附件，確定取消？')) return;
    var ids = BT_ATT.map(function(a){ return a.attach_id; });
    BT_ATT = []; BT_SEL = {};
    closeMask('batMask');
    ids.forEach(function(id){ $.post(API, {action:'delete_attach', attach_id:id}, function(){}, 'json'); });
}
function btFilteredTools(){
    var cat = $('#btCat').val(), kw = $.trim($('#btKw').val()).toLowerCase();
    var cur = TABS.filter(function(t){ return t.key===curTab; })[0];
    return ROWS.filter(function(r){
        var cid = String(r.QC_Tool_List_id);
        if (cat === '__tab__') { if (!cur || cur.catIds.indexOf(cid) < 0) return false; }
        else if (cat && cid !== cat) return false;
        if (kw && String(r.Tool_No).toLowerCase().indexOf(kw) < 0) return false;
        return true;
    });
}
function renderBtTools(){
    var list = btFilteredTools(), def = $('#btResult').val();
    var h = list.map(function(r){
        var on = BT_SEL.hasOwnProperty(r.Tool_id);
        return '<tr data-id="'+r.Tool_id+'"'+(on?' class="sel"':'')+'>'
            + '<td><input type="checkbox" class="bt-ck"'+(on?' checked':'')+'></td>'
            + '<td style="text-align:left;"><b>'+esc(r.Tool_No)+'</b></td>'
            + '<td>'+esc(r.category_name||'')+'</td>'
            + '<td>'+(r.calib_cycle_months==null?'<span style="color:#DD5138;">未設</span>':r.calib_cycle_months+' 月')+'</td>'
            + '<td>'+(fmtMonth(r.calibration_due)||'—')+'</td>'
            + '<td>'+statPill(r.status)+'</td>'
            + '<td><select class="bt-res">'+resOptions(on?BT_SEL[r.Tool_id]:def)+'</select></td></tr>';
    }).join('');
    $('#btBody').html(h || '<tr><td colspan="7" style="padding:12px;color:#8a6d45;">無符合條件的量具</td></tr>');
    var all = list.length>0 && list.every(function(r){ return BT_SEL.hasOwnProperty(r.Tool_id); });
    $('#btCkAll').prop('checked', all);
    updateBtInfo();
}
function updateBtInfo(){
    var ids = Object.keys(BT_SEL);
    var noCycle = ids.filter(function(id){
        var r = ROWS.filter(function(x){ return String(x.Tool_id)===String(id); })[0];
        return r && r.calib_cycle_months == null;
    }).length;
    $('#btSelInfo').html('已選 <b>'+ids.length+'</b> 支'
        + (noCycle ? '；其中 <span style="color:#DD5138;">'+noCycle+' 支未設校驗週期</span>，登錄後不會自動算下次到期日' : ''));
    renderBtAtt();   // 附件「本批全部」的支數會跟著變
}
$('#btCat').on('change', renderBtTools);
$('#btKw').on('input', renderBtTools);
$('#btResult').on('change', function(){
    var v = this.value;
    Object.keys(BT_SEL).forEach(function(id){ BT_SEL[id] = v; });
    renderBtTools();
});
$('#btCkAll').on('change', function(){
    var on = this.checked, def = $('#btResult').val();
    btFilteredTools().forEach(function(r){
        if (on) { if (!BT_SEL.hasOwnProperty(r.Tool_id)) BT_SEL[r.Tool_id] = def; }
        else delete BT_SEL[r.Tool_id];
    });
    renderBtTools();
});
$('#btBody').on('change', '.bt-ck', function(){
    var $tr = $(this).closest('tr'), id = $tr.attr('data-id');
    if (this.checked) BT_SEL[id] = $tr.find('.bt-res').val() || $('#btResult').val();
    else delete BT_SEL[id];
    $tr.toggleClass('sel', this.checked);
    var list = btFilteredTools();
    $('#btCkAll').prop('checked', list.length>0 && list.every(function(r){ return BT_SEL.hasOwnProperty(r.Tool_id); }));
    updateBtInfo();
});
$('#btBody').on('change', '.bt-res', function(){
    var $tr = $(this).closest('tr'), id = $tr.attr('data-id');
    if (BT_SEL.hasOwnProperty(id)) BT_SEL[id] = this.value;
    else { BT_SEL[id] = this.value; $tr.addClass('sel').find('.bt-ck').prop('checked', true); updateBtInfo(); }
});
/** 快捷挑選（作用在目前篩選範圍內） */
function btPick(mode){
    var def = $('#btResult').val(), list = btFilteredTools();
    if (mode === 'none'){ list.forEach(function(r){ delete BT_SEL[r.Tool_id]; }); renderBtTools(); return; }
    list.forEach(function(r){
        var hit = (mode === 'all') || (mode === 'overdue' && r.status === 'overdue')
               || (mode === 'due' && (r.status === 'overdue' || r.status === 'soon'));
        if (hit) { if (!BT_SEL.hasOwnProperty(r.Tool_id)) BT_SEL[r.Tool_id] = def; }
        else if (mode !== 'all') delete BT_SEL[r.Tool_id];
    });
    renderBtTools();
}

/* ---------- 批次附件：上傳（先存 temp，登錄本批時轉正） ---------- */
$('#btFile').on('change', function(){
    var files = this.files;
    if (!files || !files.length) return;
    var cat = $('#btCat').val();
    cat = (cat && cat !== '__tab__') ? parseInt(cat, 10) : 0;
    var i = 0;
    function next(){
        if (i >= files.length){ $('#btFile').val(''); renderBtAtt(); return; }
        var fd = new FormData();
        fd.append('action', 'upload_attach'); fd.append('batch_id', 0);
        fd.append('category_id', cat); fd.append('doc_type', (ATT_CFG.types||[])[0] || '校驗報告');
        fd.append('file', files[i]);
        NProgress.start();
        $.ajax({url:API, type:'POST', data:fd, processData:false, contentType:false, dataType:'json'})
            .done(function(res){
                if (!res.ok) { alert(res.error||'上傳失敗'); return; }
                BT_ATT.push({attach_id:res.attach_id, name:res.original_name, size:res.file_size,
                             doc_type:res.doc_type||'', note:'', category_id:cat, toolIds:null});
            })
            .fail(function(x){ alert('上傳失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); })
            .always(function(){ NProgress.done(); i++; renderBtAtt(); next(); });
    }
    next();
});
function renderBtAtt(){
    var selCnt = Object.keys(BT_SEL).length;
    var h = BT_ATT.map(function(a, idx){
        var lab = (a.toolIds === null) ? ('本批全部 '+selCnt+' 支') : (a.toolIds.length + ' 支');
        return '<div class="att-row" data-idx="'+idx+'">'
            + '<span class="fn"><i class="fa fa-file-o"></i> '+esc(a.name)+'</span>'
            + ' <select class="att-type">'+typeOptions(a.doc_type)+'</select>'
            + ' <input type="text" class="att-note" placeholder="附件備註" value="'+esc(a.note)+'" style="width:150px;font-size:12px;border:1px solid #D8BE93;border-radius:3px;padding:1px 4px;">'
            + ' <span class="op map"><i class="fa fa-link"></i> 對應量具（'+lab+'）</span>'
            + ' <span class="op del"><i class="fa fa-trash"></i> 刪除</span>'
            + '<div class="att-map"></div></div>';
    }).join('');
    $('#btAttList').html(h || '<div style="font-size:12px;color:#8a6d45;">尚未上傳附件（可不上傳）。上傳後預設對應本批全部量具，可再逐支調整。</div>');
}
$('#btAttList').on('change', '.att-type', function(){
    BT_ATT[+$(this).closest('.att-row').attr('data-idx')].doc_type = this.value;
});
$('#btAttList').on('input', '.att-note', function(){
    BT_ATT[+$(this).closest('.att-row').attr('data-idx')].note = this.value;
});
$('#btAttList').on('click', '.op.del', function(){
    var idx = +$(this).closest('.att-row').attr('data-idx'), a = BT_ATT[idx];
    if (!confirm('刪除附件「'+a.name+'」？')) return;
    $.post(API, {action:'delete_attach', attach_id:a.attach_id}, function(res){
        if (!res.ok){ alert(res.error||'刪除失敗'); return; }
        BT_ATT.splice(idx, 1); renderBtAtt();
    }, 'json').fail(function(x){ alert('刪除失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
});
/** 附件對應量具（一對多）：只在本批已選量具中勾選；全不勾＝跟隨本批全部 */
$('#btAttList').on('click', '.op.map', function(){
    var $row = $(this).closest('.att-row'), idx = +$row.attr('data-idx'), a = BT_ATT[idx], $p = $row.find('.att-map');
    if ($p.is(':visible')){ $p.hide(); return; }
    var sel = Object.keys(BT_SEL);
    if (!sel.length){ alert('請先於上方「選擇本批量具」勾選量具，再設定附件對應'); return; }
    var cur = (a.toolIds === null) ? sel.map(String) : a.toolIds.map(String);
    var h = '<div style="margin-bottom:4px;color:#8a6d45;">'
          + '<a href="#" class="mp-all" style="color:#b5762a;">全選</a>　'
          + '<a href="#" class="mp-none" style="color:#b5762a;">全不選</a>　全不勾＝跟隨本批全部量具</div>';
    h += sel.map(function(id){
        var r = ROWS.filter(function(x){ return String(x.Tool_id)===String(id); })[0] || {};
        return '<label><input type="checkbox" class="mp-ck" value="'+id+'"'+(cur.indexOf(String(id))>=0?' checked':'')+'> '
             + esc(r.Tool_No||id)+'</label>';
    }).join('');
    $p.html(h).show();
});
$('#btAttList').on('click', '.mp-all, .mp-none', function(e){
    e.preventDefault();
    var $p = $(this).closest('.att-map');
    $p.find('.mp-ck').prop('checked', $(this).hasClass('mp-all'));
    $p.find('.mp-ck').first().trigger('change');
});
$('#btAttList').on('change', '.mp-ck', function(){
    var $row = $(this).closest('.att-row'), idx = +$row.attr('data-idx');
    var ids = $row.find('.mp-ck:checked').map(function(){ return parseInt(this.value, 10); }).get();
    BT_ATT[idx].toolIds = ids.length ? ids : null;
    var lab = (BT_ATT[idx].toolIds === null) ? ('本批全部 '+Object.keys(BT_SEL).length+' 支') : (ids.length+' 支');
    $row.find('.op.map').html('<i class="fa fa-link"></i> 對應量具（'+lab+'）');
});

function submitBatch(){
    var date = $('#btDate').val();
    if (!date){ alert('請選擇校驗完成日'); $('#btDate').focus(); return; }
    var op = opValue('btOpBox');
    if (!op){
        alert($('#btMethod').val()==='內校' ? '請選擇校驗人員（內校）'
            : ($('#btMethod').val()==='外校' ? '請搜尋並選擇外校廠商' : '請填寫校驗人員／單位'));
        return;
    }
    var reviewerUid = '';
    if ($('#btMethod').val()==='內校') {
        reviewerUid = $('#btReviewer').val();
        if (!reviewerUid){ alert('內校請選擇覆驗者'); return; }
        if (String(reviewerUid)===String(op.operator_user_id)){ alert('覆驗者不可與校驗人員為同一人'); return; }
    }
    var ids = Object.keys(BT_SEL);
    if (!ids.length){ alert('請至少選擇一支量具'); return; }
    var tools = ids.map(function(id){ return {tool_id:parseInt(id,10), result:BT_SEL[id]}; });
    var attach = BT_ATT.map(function(a){
        return {attach_id:a.attach_id, doc_type:a.doc_type, note:a.note, category_id:a.category_id||0,
                tool_ids:(a.toolIds === null) ? ids.map(Number) : a.toolIds};
    });
    if (!confirm('確定登錄本批校驗？\n量具 '+ids.length+' 支、附件 '+BT_ATT.length+' 份。\n各量具的下次應校驗日會依其週期自動前滾。')) return;
    NProgress.start();
    $.post(API, {action:'create_batch', calib_date:date, method:$('#btMethod').val(),
                 operator:op.operator, operator_user_id:op.operator_user_id, vendor_id:op.vendor_id,
                 reviewer_user_id:reviewerUid,
                 cert_no:$('#btCert').val(), note:$('#btNote').val(),
                 tools:JSON.stringify(tools), attach:JSON.stringify(attach)}, function(res){
        NProgress.done();
        if (!res.ok){ alert(res.error||'登錄失敗'); return; }
        BT_ATT = []; BT_SEL = {};
        closeMask('batMask');
        alert('已登錄 '+res.done+' 支量具的校驗紀錄。' + (res.approval_status==='pending' ? '（本批需主管核准，已送出通知）' : ''));
        loadList();
    }, 'json').fail(function(x){ NProgress.done(); alert('登錄失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}

/* ---------- 批次紀錄 ---------- */
$('#btnBatchList').on('click', function(){
    $.getJSON(API, {action:'batch_list'}, function(res){
        if (!res.ok){ alert(res.error||'載入失敗'); return; }
        if (!res.list.length){ $('#batListBody').html('<div style="padding:12px;color:#8a6d45;">尚無批次校驗紀錄</div>'); openMask('batListMask'); return; }
        var h = '<table class="hist"><thead><tr><th>校驗日</th><th>方式</th><th>人員／單位</th><th>憑證編號</th>'
              + '<th>量具數</th><th>附件</th><th>備註</th><th>登錄者</th><th>明細</th></tr></thead><tbody>';
        res.list.forEach(function(b){
            h += '<tr><td>'+fmtDate(b.calib_date)+'</td><td>'+esc(b.method||'—')+'</td><td>'+esc(b.operator||'—')+'</td>'
               + '<td>'+esc(b.cert_no||'—')+'</td><td>'+b.tool_count+'</td><td>'+b.attach_count+'</td>'
               + '<td style="text-align:left;">'+esc(b.note||'')+'</td><td>'+esc(b.created_by_name||'')+'</td>'
               + '<td><span class="tc-op" onclick="openBatchDetail('+b.batch_id+')">明細</span></td></tr>';
        });
        h += '</tbody></table>';
        $('#batListBody').html(h);
        openMask('batListMask');
    }).fail(function(x){ alert('載入失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
});
function openBatchDetail(bid){
    $.getJSON(API, {action:'batch_detail', batch_id:bid}, function(res){
        if (!res.ok){ alert(res.error||'載入失敗'); return; }
        var b = res.batch;
        var h = '<div style="margin-bottom:8px;"><span class="tc-op" onclick="$(\'#btnBatchList\').click()">‹ 返回批次列表</span></div>';
        h += '<div style="font-size:13px;color:#5b3a1e;margin-bottom:8px;">校驗日 <b>'+fmtDate(b.calib_date)+'</b>　方式 '
           + esc(b.method||'—')+'　人員／單位 '+esc(b.operator||'—')+'　憑證 '+esc(b.cert_no||'—')
           + '　登錄者 '+esc(b.created_by_name||'')+(b.note?'<br>備註：'+esc(b.note):'')+'</div>';
        h += '<div style="font-size:13px;font-weight:bold;color:#5b3a1e;margin:6px 0 3px;">本批量具（'+res.tools.length+'）</div>';
        h += '<table class="hist"><thead><tr><th>量具編號</th><th>類別</th><th>應校驗到期月</th><th>校驗日</th><th>準時</th><th>結果</th></tr></thead><tbody>';
        res.tools.forEach(function(t){
            var ontime = isOnTime(t.calib_date, t.due_date);
            h += '<tr><td><b>'+esc(t.Tool_No)+'</b></td><td>'+esc(t.category_name||'')+'</td>'
               + '<td>'+(fmtMonth(t.due_date)||'—')+'</td><td>'+fmtDate(t.calib_date)+'</td>'
               + '<td>'+(ontime===null?'—':(ontime?'<span style="color:#8A5A2B;">準時</span>':'<span style="color:#DD5138;">逾期</span>'))+'</td>'
               + '<td>'+(RESULT_LABEL[t.result]||t.result)+'</td></tr>';
        });
        h += '</tbody></table>';
        h += '<div style="font-size:13px;font-weight:bold;color:#5b3a1e;margin:10px 0 3px;">附件（'+res.attaches.length+'）</div>';
        if (!res.attaches.length) h += '<div style="font-size:12px;color:#8a6d45;">本批無附件</div>';
        else {
            h += '<table class="hist"><thead><tr><th>文件類別</th><th>檔名</th><th>對應量具編號</th><th>備註</th><th>下載</th>'
               + (res.can_admin?'<th>刪除</th>':'')+'</tr></thead><tbody>';
            res.attaches.forEach(function(a){
                h += '<tr><td>'+esc(a.doc_type||'—')+'</td><td style="text-align:left;">'+esc(a.original_name||a.file_name)+'</td>'
                   + '<td style="text-align:left;">'+esc(a.tool_nos||'（未對應）')+'</td><td>'+esc(a.note||'')+'</td>'
                   + '<td><a href="'+API+'?action=download_attach&attach_id='+a.attach_id+'" target="_blank" style="color:#b5762a;"><i class="fa fa-download"></i> 下載</a></td>'
                   + (res.can_admin?'<td><span class="tc-op" style="color:#DD5138;" onclick="delAttach('+a.attach_id+','+bid+')"><i class="fa fa-trash"></i></span></td>':'')
                   + '</tr>';
            });
            h += '</tbody></table>';
        }
        $('#batListBody').html(h);
        openMask('batListMask');
    });
}
function delAttach(aid, bid){
    if (!confirm('刪除此附件？（實體檔案一併刪除，無法復原）')) return;
    $.post(API, {action:'delete_attach', attach_id:aid}, function(res){
        if (!res.ok){ alert(res.error||'刪除失敗'); return; }
        if (bid) openBatchDetail(bid); else if (HIST_TID) openHis(HIST_TID);
    }, 'json').fail(function(x){ alert('刪除失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}

/* ---------- 內校主管核准（使用者 2026-08-12 明確要求；核准人走核准鏈，管理員可設定是否需要） ---------- */
function loadPendingCount(){
    $.getJSON(API, {action:'pending_approvals'}, function(res){
        if (!res.ok) return;
        var n = (res.list||[]).length;
        $('#btnPending').show();
        if (n){ $('#pendBadge').text(n).show(); } else { $('#pendBadge').hide(); }
    });
}
$('#btnPending').on('click', function(){
    $.getJSON(API, {action:'pending_approvals'}, function(res){
        if (!res.ok){ alert(res.error||'載入失敗'); return; }
        if (!res.list.length){ $('#pendBody').html('<div style="padding:12px;color:#8a6d45;">目前沒有待您核准的內校紀錄</div>'); openMask('pendMask'); return; }
        var h = '<table class="hist"><thead><tr><th>校驗日</th><th>量具編號</th><th>校驗人員</th><th>覆驗者</th>'
              + '<th>憑證編號</th><th>登錄者</th><th>操作</th></tr></thead><tbody>';
        res.list.forEach(function(b){
            h += '<tr><td>'+fmtDate(b.calib_date)+'</td><td style="text-align:left;">'+esc(b.tool_nos||'')+'</td>'
               + '<td>'+esc(b.operator||'—')+'</td><td>'+esc(b.reviewer_name||'—')+'</td><td>'+esc(b.cert_no||'—')+'</td>'
               + '<td>'+esc(b.created_by_name||'')+'</td>'
               + '<td><span class="tc-op" onclick="pendDecide('+b.batch_id+',\'approved\')"><i class="fa fa-check"></i> 核准</span>'
               + '<span class="tc-op" style="color:#DD5138;" onclick="pendDecide('+b.batch_id+',\'rejected\')"><i class="fa fa-times"></i> 退回</span>'
               + '<span class="tc-op" onclick="openBatchDetail('+b.batch_id+')"><i class="fa fa-eye"></i> 明細</span></td></tr>';
        });
        h += '</tbody></table>';
        $('#pendBody').html(h);
        openMask('pendMask');
    }).fail(function(x){ alert('載入失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
});
function pendDecide(bid, decision){
    var note = '';
    if (decision === 'rejected'){
        note = prompt('請填寫退回原因：');
        if (note === null) return;
        note = $.trim(note);
        if (!note){ alert('請填寫退回原因'); return; }
    } else if (!confirm('確定核准此筆內校紀錄？')) return;
    $.post(API, {action:'batch_decide', batch_id:bid, decision:decision, note:note}, function(res){
        if (!res.ok){ alert(res.error||'處理失敗'); return; }
        $('#btnPending').click();
        loadPendingCount();
    }, 'json').fail(function(x){ alert('處理失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}

/* ---------- 校驗附件設定（管理員） ---------- */
function loadAttPane(){
    $('#asDir').val(ATT_CFG.dir||''); $('#asExt').val(ATT_CFG.ext_raw||'');
    $('#asMax').val(ATT_CFG.maxmb||20); $('#asTypes').val(ATT_CFG.types_raw||'');
}
function submitAttSet(){
    $.post(API, {action:'save_attach_settings', dir:$('#asDir').val(), ext:$('#asExt').val(),
                 maxmb:$('#asMax').val(), types:$('#asTypes').val()}, function(res){
        if (!res.ok){ alert(res.error||'儲存失敗'); return; }
        ATT_CFG = res.attach;
        closeMask('cfgMask');
        alert('已儲存附件設定。');
    }, 'json').fail(function(x){ alert('儲存失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}

/* ================= 校驗人員／外校廠商控制項（批次與單筆登錄共用） ================= */
var STAFF = [], STAFF_MULTI_DEPT = false, QC_DEPT_SET = false, OP = {};
function opInit(boxId, method, preset){
    preset = preset || {};
    OP[boxId] = {method:method||'', userId:preset.userId||0, vendorId:preset.vendorId||'',
                 vendorName:preset.vendorName||'', text:preset.text||''};
    opRender(boxId);
}
function opRender(boxId){
    var st = OP[boxId] || {method:''}, h = '';
    if (st.method === '內校'){
        if (!STAFF.length){
            h = '<div class="op-warn">尚無具校驗人員資格的人員'
              + ((PERMS && PERMS.canAdmin) ? '，請按工具列「校驗人員資格」設定' : '，請洽管理者設定')
              + (QC_DEPT_SET ? '' : '；品管部門也尚未設定，請先於「組織角色綁定設定」設定品管部門') + '</div>';
        } else {
            // 名單已限定在品管部門底下，故不顯示部門與職稱（使用者 2026-07-30 指示）；
            // 排序仍依職稱(people_lib)，長期請假者仍標記假別與期間
            h = '<select class="op-staff"><option value="">— 請選擇校驗人員 —</option>'
              + STAFF.map(function(s){
                    var lab = s.user_cname + (s.on_leave ? '［'+s.leave_note+'］' : '');
                    return '<option value="'+s.id+'"'+(String(st.userId)===String(s.id)?' selected':'')+'>'
                         + esc(lab)+'</option>'; }).join('')
              + '</select>';
        }
    } else if (st.method === '外校'){
        h = '<input type="text" class="op-vkw" placeholder="輸入廠商ID或名稱模糊搜尋…" autocomplete="off">'
          + '<div class="op-vlist"></div>'
          + (st.vendorId
              ? '<div class="op-sel">已選：<b>'+esc(st.vendorName)+'</b>（'+esc(st.vendorId)+'）<i class="fa fa-times op-clear" title="清除"></i></div>'
              : '<div class="op-sel" style="color:#8a6d45;">尚未選擇廠商（資料來源＝主檔管理的廠商資料）</div>');
    } else {
        h = '<input type="text" class="op-free" maxlength="50" placeholder="請填校驗人員或單位" value="'+esc(st.text||'')+'">';
    }
    $('#'+boxId).html(h);
}
/** 取值；未填/未選回 null（呼叫端負責擋下） */
function opValue(boxId){
    var st = OP[boxId] || {};
    if (st.method === '內校'){
        var v = $('#'+boxId+' .op-staff').val();
        if (!v) return null;
        var s = STAFF.filter(function(x){ return String(x.id)===String(v); })[0];
        return {operator:(s?s.user_cname:''), operator_user_id:v, vendor_id:''};
    }
    if (st.method === '外校'){
        if (!st.vendorId) return null;
        return {operator:st.vendorName, operator_user_id:0, vendor_id:st.vendorId};
    }
    var t = $.trim($('#'+boxId+' .op-free').val() || '');
    if (!t) return null;
    return {operator:t, operator_user_id:0, vendor_id:''};
}
var _vTimer = null;
$(document).on('input', '.op-vkw', function(){
    var $box = $(this).closest('.op-box'), kw = $.trim(this.value);
    clearTimeout(_vTimer);
    if (!kw){ $box.find('.op-vlist').hide().empty(); return; }
    _vTimer = setTimeout(function(){
        $.getJSON(API, {action:'vendor_search', kw:kw}, function(res){
            if (!res.ok) return;
            var $l = $box.find('.op-vlist');
            if (!res.list.length){ $l.html('<div style="color:#8a6d45;">查無廠商</div>').show(); return; }
            $l.html(res.list.map(function(v){
                var nm = v.maker_id_all || v.maker_id;
                return '<div data-id="'+esc(v.maker_id_no)+'" data-nm="'+esc(nm)+'">'+esc(nm)
                     + '<span class="id">'+esc(v.maker_id_no)+(v.maker_id?' ／ '+esc(v.maker_id):'')+'</span></div>';
            }).join('')).show();
        });
    }, 250);
});
$(document).on('click', '.op-vlist div[data-id]', function(){
    var boxId = $(this).closest('.op-box').attr('id');
    OP[boxId].vendorId = $(this).attr('data-id');
    OP[boxId].vendorName = $(this).attr('data-nm');
    opRender(boxId);
});
$(document).on('click', '.op-clear', function(){
    var boxId = $(this).closest('.op-box').attr('id');
    OP[boxId].vendorId = ''; OP[boxId].vendorName = ''; opRender(boxId);
});
$(document).on('change', '.op-staff', function(){
    var boxId = $(this).closest('.op-box').attr('id');
    OP[boxId].userId = this.value;
    refreshReviewerBox(boxId);
});
$(document).on('input', '.op-free', function(){
    var boxId = $(this).closest('.op-box').attr('id');
    OP[boxId].text = this.value;
});
$('#btMethod').on('change', function(){ opInit('btOpBox', this.value, OP['btOpBox']); refreshReviewerBox('btOpBox'); });
$('#recMethod').on('change', function(){ opInit('recOpBox', this.value, OP['recOpBox']); refreshReviewerBox('recOpBox'); });

/* ---------- 內校覆驗者（使用者 2026-08-12 明確要求；池同校驗人員資格，不可與校驗人員同一人） ---------- */
function reviewerWrapIdFor(opBoxId){ return opBoxId==='recOpBox' ? 'recReviewerBox' : 'btReviewerBox'; }
function reviewerSelIdFor(opBoxId){ return opBoxId==='recOpBox' ? 'recReviewer' : 'btReviewer'; }
function renderReviewerSelect(selId, excludeUid, selectedUid){
    var h = '<option value="">— 請選擇覆驗者 —</option>';
    (STAFF||[]).forEach(function(s){
        if (excludeUid && String(s.id)===String(excludeUid)) return;
        var lab = s.user_cname + (s.on_leave ? '［'+s.leave_note+'］' : '');
        h += '<option value="'+s.id+'"'+(String(selectedUid||'')===String(s.id)?' selected':'')+'>'+esc(lab)+'</option>';
    });
    $('#'+selId).html(h);
}
function refreshReviewerBox(opBoxId){
    var method = (OP[opBoxId]||{}).method, wrapId = reviewerWrapIdFor(opBoxId), selId = reviewerSelIdFor(opBoxId);
    if (method === '內校') {
        $('#'+wrapId).show();
        renderReviewerSelect(selId, OP[opBoxId].userId, $('#'+selId).val());
    } else {
        $('#'+wrapId).hide();
        $('#'+selId).val('');
    }
}

/* ================= 校驗人員資格設定（管理員） ================= */
/* 統一設定視窗：切分頁時才載入該分頁資料 */
function openCfg(pane){
    cfgSwitch(pane || 'cfgCat');
    openMask('cfgMask');
}
function cfgSwitch(pane){
    $('#cfgMask .cfg-tabs .tab').each(function(){ $(this).toggleClass('active', $(this).attr('data-pane')===pane); });
    $('#cfgMask .cfg-pane').hide();
    $('#'+pane).show();
    if (pane === 'cfgCat')      loadCatPane();
    if (pane === 'cfgStaff')    loadStaffPane();
    if (pane === 'cfgAtt')      loadAttPane();
    if (pane === 'cfgApproval') loadApprovalPane();
    if (pane === 'cfgAsdoc')    loadAsdocPane();
    if (pane === 'cfgSpec')     loadSpecPane();
    if (pane === 'cfgClean')    loadCleanPane();
}
$('#btnCfg').on('click', function(){ openCfg('cfgCat'); });
$('#cfgMask').on('click', '.cfg-tabs .tab', function(){ cfgSwitch($(this).attr('data-pane')); });

/* ================= 核准與圖章設定（管理員） ================= */
function renderApChainBox(chain){
    var methods = {dept_or_user:'部門或人員', auto_supervisor:'自動抓上一階主管', top_approver:'最高決策者'};
    var h = '';
    for (var i=0;i<3;i++){
        h += '<div class="chain-row"><span style="width:44px;color:#8a6d45;">第'+(i+1)+'順位</span><select class="ap-chain-sel" data-idx="'+i+'"><option value="">不使用</option>';
        Object.keys(methods).forEach(function(k){ h += '<option value="'+k+'">'+methods[k]+'</option>'; });
        h += '</select></div>';
    }
    $('#apChainBox').html(h);
    (chain||['top_approver']).forEach(function(m,i){ $('.ap-chain-sel[data-idx='+i+']').val(m); });
}
function loadApprovalStampOptions(cb){
    $.getJSON(API, {action:'stamp_tpl_options'}, function(res){
        if (!res.ok) return;
        var h = '<option value="0">（預設樣式）</option>';
        (res.templates||[]).forEach(function(t){ h += '<option value="'+t.id+'">'+(t.type_name?esc(t.type_name)+'｜':'')+esc(t.tpl_name)+'</option>'; });
        $('#apListStamp,#apFooterStamp').html(h);
        if (cb) cb();
    });
}
function loadApprovalPane(){
    var deptOpts = '<option value="">（未設定）</option>' + (META.departments||[]).map(function(d){ return '<option value="'+d.id+'">'+esc(d.name)+'</option>'; }).join('');
    $('#apDept').html(deptOpts);
    var userOpts = '<option value="">（未設定）</option>' + (STAFF||[]).map(function(p){ return '<option value="'+p.id+'">'+esc(p.user_cname)+'</option>'; }).join('');
    $('#apUser').html(userOpts);
    loadApprovalStampOptions(function(){
        var ap = META.approval || {};
        $('#apNeed').prop('checked', ap.need_approval==1);
        $('#apDept').val(ap.approver_dept_id||'');
        $('#apUser').val(ap.approver_user_id||'');
        renderApChainBox(ap.approver_chain);
        $('#apListStamp').val(ap.list_stamp_tpl_id||0);
        $('#apFooterStamp').val(ap.footer_stamp_tpl_id||0);
    });
}
function submitApproval(){
    var chain = [];
    $('.ap-chain-sel').each(function(){ var v=$(this).val(); if (v) chain.push(v); });
    $.post(API, {action:'save_approval_settings', need_approval:$('#apNeed').is(':checked')?1:0,
        approver_dept_id:$('#apDept').val(), approver_user_id:$('#apUser').val(),
        approver_chain:JSON.stringify(chain.length?chain:['top_approver']),
        list_stamp_tpl_id:$('#apListStamp').val(), footer_stamp_tpl_id:$('#apFooterStamp').val()}, function(res){
        if (!res.ok){ alert(res.error||'儲存失敗'); return; }
        META.approval = res.approval;
        alert('已儲存核准與圖章設定。');
        closeMask('cfgMask');
    }, 'json').fail(function(x){ alert('儲存失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}

/* ================= AS 文件編號綁定設定（管理員） ================= */
var ASDOC_MODULE_LABEL = {tool_calib_record:'校驗紀錄（年度校驗紀錄／登錄憑證）', tool_calib_plan:'校驗計畫表（年度校驗計畫表）', tool_calib_dossier:'檢驗設備履歷表'};
function loadAsdocPane(){
    var h = '';
    Object.keys(ASDOC_MODULE_LABEL).forEach(function(m){
        var doc = (META.as_docs||{})[m];
        h += '<div style="display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid #F0E4CC;">'
           + '<div style="width:220px;">'+esc(ASDOC_MODULE_LABEL[m])+'</div>'
           + '<span id="asdocLabel_'+m+'" style="color:#5b3a1e;flex:1;">'+(doc ? esc(doc.doc_no+' '+doc.doc_name) : '未綁定')+'</span>'
           + '<button type="button" onclick="openAsdocPicker(\''+m+'\')" style="height:26px;font-size:12px;border:1px solid #D8BE93;border-radius:4px;background:#fff;cursor:pointer;">選擇…</button>'
           + '</div>';
    });
    $('#asdocRows').html(h);
}
function openAsdocPicker(module){
    $.getJSON(API, {action:'asdoc_list'}, function(res){
        if (!res.ok){ alert(res.error||'載入失敗'); return; }
        var cur = (META.as_docs||{})[module];
        EGAsDoc.open({ docs: res.docs||[], current: cur ? cur.id : 0, title: ASDOC_MODULE_LABEL[module]+' AS 文件綁定',
            onSave: function(id, doc){
                $.post(API, {action:'save_asdoc', module:module, doc_id:id}, function(r){
                    if (!r.ok){ alert(r.error||'儲存失敗'); return; }
                    META.as_docs[module] = r.doc;
                    $('#asdocLabel_'+module).text(r.doc ? (r.doc.doc_no+' '+r.doc.doc_name) : '未綁定');
                }, 'json').fail(function(x){ alert('儲存失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
            }
        });
    });
}

function loadStaffPane(){
    $.getJSON(API, {action:'staff_candidates'}, function(res){
        if (!res.ok){ alert(res.error||'載入失敗'); return; }
        $('#staffHint').text(res.qc_dept_set ? ''
            : '尚未設定品管部門：請先到「系統管理－組織角色綁定設定」設定「品管部門」，這裡才會出現候選人員。');
        // 名單已限定在品管部門底下 → 不顯示部門與職稱（仍依職稱排序）
        var h = res.list.map(function(u){
            return '<tr data-id="'+u.id+'"><td style="text-align:left;">'+esc(u.user_cname)+'</td>'
                 + '<td>'+(u.on_leave ? '<span style="color:#DD5138;">'+esc(u.leave_note)+'</span>' : '—')+'</td>'
                 + '<td><input type="checkbox" class="ck-staff"'+(u.qualified===1?' checked':'')+'></td></tr>';
        }).join('');
        $('#staffBody').html(h || '<tr><td colspan="3" style="padding:12px;color:#8a6d45;">品管部門底下查無未離職人員</td></tr>');
        syncStaffAll();
    }).fail(function(x){ alert('載入失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}
function syncStaffAll(){
    var n = $('#staffBody tr[data-id]').length, c = $('#staffBody .ck-staff:checked').length;
    $('#staffCkAll').prop('checked', n>0 && c===n);
}
$('#staffCkAll').on('change', function(){ $('#staffBody .ck-staff').prop('checked', this.checked); });
$('#staffBody').on('change', '.ck-staff', syncStaffAll);
function submitStaff(){
    var ids = $('#staffBody tr[data-id]').filter(function(){ return $(this).find('.ck-staff').prop('checked'); })
                .map(function(){ return parseInt($(this).attr('data-id'), 10); }).get();
    $.post(API, {action:'save_staff', user_ids:JSON.stringify(ids)}, function(res){
        if (!res.ok){ alert(res.error||'儲存失敗'); return; }
        STAFF = res.staff || []; STAFF_MULTI_DEPT = !!res.staff_multi_dept;
        closeMask('cfgMask');
        alert('已儲存校驗人員資格（'+STAFF.length+' 人）。');
        if (OP['recOpBox']) opRender('recOpBox');
        if (OP['btOpBox']) opRender('btOpBox');
    }, 'json').fail(function(x){ alert('儲存失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}

/* ================= 量具料號對應（管理員） =================
 * 使用者 2026-07-30 定案：量具規格掛到「採購料號」(purchase_item→purchase_spec)，不另建量具規格主檔；
 * 實體仍以量具編號為主。舊資料先「解析成草稿」給使用者改，確認後才寫入。
 */
var SP_ROWS = [], SP_LOADED = false, SP_BRANDS = [];
/** 與後端 tool_calib_compose_spec_text() 同一套組法（規格 型號 型式）——**品牌是獨立欄位，不併進來** */
function spCompose(r){
    var p = [];
    [r.spec, r.model].forEach(function(v){ v = $.trim(v||''); if (v) p.push(v); });
    if ($.trim(r.type||'') === '電子') p.push('電子');
    return $.trim(p.join(' ').replace(/\s+/g, ' '));
}
function loadSpecPane(){
    if (SP_LOADED){ renderSpecBody(); return; }        // 已產生過草稿→保留畫面上未存的修改
    $('#spBody').html('<tr><td colspan="9" style="padding:12px;color:#8a6d45;">解析中…</td></tr>');
    $.getJSON(API, {action:'spec_draft'}, function(res){
        if (!res.ok){ alert(res.error||'載入失敗'); return; }
        var cats = res.purchase_categories || [];
        $('#spCat').html(cats.map(function(c){
            return '<option value="'+c.category_id+'"'
                 + (Number(c.category_id)===Number(res.default_category_id)?' selected':'')+'>'
                 + esc(c.category_name)+(c.category_code?'（'+esc(c.category_code)+'）':'')+'</option>';
        }).join('') || '<option value="">（查無採購品類別）</option>');
        // 單位預設 PCS（使用者指定；量具以支計）
        $('#spUnit').html((res.units || []).map(function(u){
            return '<option value="'+u.unit_id+'"'
                 + (Number(u.unit_id)===Number(res.default_unit_id)?' selected':'')+'>'
                 + esc(u.unit_name)+'</option>';
        }).join('') || '<option value="">（查無單位）</option>');
        // 品牌清單由採購維護（申請採購→採購品主檔→品牌清單），本頁只能選或手動打字
        SP_BRANDS = res.brands || [];
        $('#spBrandOptions').html(SP_BRANDS.map(function(b){ return '<option value="'+esc(b)+'">'; }).join(''));
        $('#spBrandHint').text(SP_BRANDS.length ? ('品牌清單共 '+SP_BRANDS.length+' 個（由採購維護）')
                                               : '採購尚未建立品牌清單，品牌欄可先手動輸入');
        SP_ROWS = (res.list || []).map(function(r){ r.pick = !r.bound; return r; });   // 已對應者預設不重做
        SP_LOADED = true;
        renderSpecBody();
    }).fail(function(x){ alert('載入失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}
function spVisibleRows(){
    var only = $('#spOnlyUnbound').prop('checked');
    return SP_ROWS.filter(function(r){ return !only || !r.bound; });
}
function renderSpecBody(){
    var h = spVisibleRows().map(function(r){
        var i = SP_ROWS.indexOf(r);
        return '<tr data-i="'+i+'">'
          + '<td><input type="checkbox" class="sp-ck"'+(r.pick?' checked':'')+'></td>'
          + '<td style="text-align:left;"><b>'+esc(r.Tool_No)+'</b>'
          + '<div style="font-size:11px;color:#8a6d45;">'+esc(r.category_name||'')+'</div>'
          + (r.parsed ? '' : '<div style="font-size:11px;color:#DD5138;">編號看不出規格，請自行填寫</div>')+'</td>'
          + '<td><input type="text" class="sp-in sp-item" value="'+esc(r.item_name)+'" maxlength="100" style="width:110px;"></td>'
          + '<td><input type="text" class="sp-in sp-spec" value="'+esc(r.spec)+'" maxlength="60" style="width:90px;" placeholder="例 0-25mm"></td>'
          + '<td><select class="sp-in sp-type" style="width:64px;">'
          + '<option value="機械"'+(r.type==='機械'?' selected':'')+'>機械</option>'
          + '<option value="電子"'+(r.type==='電子'?' selected':'')+'>電子</option></select></td>'
          + '<td><input type="text" class="sp-in sp-brand" list="spBrandOptions" value="'+esc(r.brand)+'" maxlength="60" style="width:96px;" placeholder="可打字或選"></td>'
          + '<td><input type="text" class="sp-in sp-model" value="'+esc(r.model)+'" maxlength="40" style="width:78px;"></td>'
          + '<td class="sp-prev" style="text-align:left;">'+esc(spPreview(r))+'</td>'
          + '<td>'+(r.bound ? '<span style="color:#8A5A2B;">'+esc(r.bound_code||'已對應')+'</span>'
                            : '<span style="color:#b0a390;">未對應</span>')+'</td></tr>';
    }).join('');
    $('#spBody').html(h || '<tr><td colspan="9" style="padding:12px;color:#8a6d45;">沒有符合條件的量具</td></tr>');
    syncSpCkAll(); updateSpInfo();
}
function spPreview(r){
    var s = spCompose(r), b = $.trim(r.brand||'');
    return $.trim($.trim(r.item_name||'') + ' ' + b + ' ' + s) + (s ? '' : '（規格空白）');
}
function updateSpInfo(){
    var n = SP_ROWS.filter(function(r){ return r.pick; }).length;
    var b = SP_ROWS.filter(function(r){ return r.bound; }).length;
    $('#spInfo').text('共 '+SP_ROWS.length+' 支量具，已對應 '+b+' 支；本次勾選 '+n+' 支');
    var noSpec = SP_ROWS.filter(function(r){ return r.pick && !spCompose(r); }).length;
    $('#spHint').text(noSpec ? ('勾選中有 '+noSpec+' 支規格空白，請自行填寫') : '');
}
function syncSpCkAll(){
    var vis = spVisibleRows();
    $('#spCkAll').prop('checked', vis.length>0 && vis.every(function(r){ return r.pick; }));
}
$('#spBody').on('input change', '.sp-in', function(){
    var $tr = $(this).closest('tr'), r = SP_ROWS[+$tr.attr('data-i')];
    if (!r) return;
    r.item_name = $tr.find('.sp-item').val();
    r.spec      = $tr.find('.sp-spec').val();
    r.type      = $tr.find('.sp-type').val();
    r.brand     = $tr.find('.sp-brand').val();
    r.model     = $tr.find('.sp-model').val();
    $tr.find('.sp-prev').text(spPreview(r));
});
$('#spBody').on('change', '.sp-ck', function(){
    var r = SP_ROWS[+$(this).closest('tr').attr('data-i')];
    if (r) r.pick = this.checked;
    syncSpCkAll(); updateSpInfo();
});
$('#spCkAll').on('change', function(){
    var on = this.checked;
    spVisibleRows().forEach(function(r){ r.pick = on; });
    $('#spBody .sp-ck').prop('checked', on);
    updateSpInfo();
});
$('#spOnlyUnbound').on('change', renderSpecBody);
$('#spGen').on('click', function(){
    if (SP_LOADED && !confirm('重新產生草稿會捨棄表格上目前的修改，確定？')) return;
    SP_LOADED = false; loadSpecPane();
});
function submitSpec(){
    var cat = parseInt($('#spCat').val(), 10) || 0;
    if (!cat){ alert('請選擇採購品類別（新建品項要掛在哪一類）'); return; }
    var picked = SP_ROWS.filter(function(r){ return r.pick; });
    if (!picked.length){ alert('請至少勾選一支量具'); return; }
    var blank = picked.filter(function(r){ return !$.trim(r.item_name||''); }).length;
    if (blank){ alert('有 '+blank+' 列的品項名稱空白，請先補齊'); return; }
    var noSpec = picked.filter(function(r){ return !spCompose(r); }).length;
    var redo   = picked.filter(function(r){ return r.bound; }).length;
    if (!confirm('確定建立／沿用採購料號並綁定 '+picked.length+' 支量具？\n'
        + '同名品項與同規格會沿用既有料號，不會重複建立。'
        + (noSpec ? '\n其中 '+noSpec+' 支規格空白，同類別的空白規格會共用同一個料號。' : '')
        + (redo  ? '\n其中 '+redo+' 支已對應過，將依目前內容重新綁定。' : ''))) return;
    specPost({action:'spec_apply', category_id:cat, unit_id:$('#spUnit').val()||0,
              items:JSON.stringify(picked.map(function(r){
        return {tool_id:r.Tool_id, item_name:r.item_name, spec:r.spec, type:r.type, brand:r.brand, model:r.model};
    }))});
}
/** 送出建立料號的共用流程（逐列確認與批次自動建立共用） */
function specPost(data){
    NProgress.start();
    $.post(API, data, function(res){
        NProgress.done();
        if (!res.ok){ alert(res.error||'建立失敗'); return; }
        alert('已完成：新建品項 '+res.new_items+' 個、新建規格（採購料號）'+res.new_specs+' 個、綁定量具 '+res.bound+' 支。'
            + (res.skipped && res.skipped.length ? '\n（'+res.skipped.length+' 支量具已不存在，略過）' : ''));
        SP_LOADED = false; loadSpecPane(); loadList();
    }, 'json').fail(function(x){ NProgress.done(); alert('建立失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}
/** 批次自動建立：不逐列確認，直接依後端解析結果整批建立並綁定 */
function specAuto(scope){
    var cat = parseInt($('#spCat').val(), 10) || 0;
    if (!cat){ alert('請選擇採購品類別（新建品項要掛在哪一類）'); return; }
    var unitTxt = $('#spUnit option:selected').text() || '（未設定）';
    var n = SP_LOADED ? (scope === 'all' ? SP_ROWS.length : SP_ROWS.filter(function(r){ return !r.bound; }).length) : 0;
    var noSpec = SP_LOADED ? SP_ROWS.filter(function(r){
        return (scope === 'all' || !r.bound) && !spCompose(r); }).length : 0;
    if (SP_LOADED && !n){ alert(scope === 'all' ? '目前沒有量具資料' : '沒有尚未對應料號的量具（全部都已對應）'); return; }
    if (!confirm('批次自動建立採購料號\n\n範圍：' + (scope === 'all' ? '全部量具（已對應者會依解析結果重新綁定）' : '尚未對應料號的量具')
        + (SP_LOADED ? '，共 ' + n + ' 支' : '')
        + '\n採購品類別：' + ($('#spCat option:selected').text() || '')
        + '\n單位：' + unitTxt
        + '\n\n・品項名稱＝量具類別，規格＝編號括號內容，型式依「電子」判定'
        + '\n・品牌一律留空（編號看不出來），日後可在「申請採購→採購品主檔」補'
        + (noSpec ? '\n・其中 ' + noSpec + ' 支編號看不出規格，會建立規格空白的料號（同類別共用一個）' : '')
        + '\n・同名品項／同規格一律沿用既有，不會產生重複料號'
        + '\n\n確定執行？')) return;
    specPost({action:'spec_apply', auto:1, scope:scope, category_id:cat, unit_id:$('#spUnit').val()||0});
}
$('#spAutoUnbound').on('click', function(){ specAuto('unbound'); });
$('#spAutoAll').on('click', function(){ specAuto('all'); });

/* ================= 清除測試資料（僅超級管理員 id=1；破壞性操作） ================= */
function loadCleanPane(){
    $('#clPw').val(''); $('#clY').val(''); $('#clResult').html('');
    $('#clDelBody').html('<tr><td style="padding:8px;color:#8a6d45;">讀取中…</td></tr>');
    $('#clKeepBody').html('');
    $.getJSON(API, {action:'clean_preview'}, function(res){
        if (!res.ok){ $('#clDelBody').html('<tr><td style="color:#DD5138;padding:8px;">'+esc(res.error||'讀取失敗')+'</td></tr>'); return; }
        var c = res.counts;
        function row(a, b){ return '<tr><td style="text-align:left;">'+a+'</td><td style="width:90px;">'+b+'</td></tr>'; }
        $('#clDelBody').html(
            row('校驗紀錄（qc_tool_calibration）', c.calibration+' 筆')
          + row('批次校驗單（qc_tool_calib_batch）', c.batch+' 筆')
          + row('校驗附件（含實體檔）', c.attach+' 筆')
          + row('附件↔量具對應', c.attach_map+' 筆')
          + row('量具的週期／到期月／列入統計／校驗方式 → 還原為空', c.tool_reset+' 支'));
        $('#clKeepBody').html(
            row('量具主檔本身（編號／類別／採購料號對應）', c.keep_tool+' 支')
          + row('類別旗標（qc_tool_list）', c.keep_category+' 類')
          + row('自訂合併分頁（qc_tool_calib_tab）', c.keep_tab+' 個')
          + row('校驗人員資格（qc_tool_calib_staff）', c.keep_staff+' 人')
          + row('附件設定（system_settings tool_calib_*）', '保留'));
    }).fail(function(x){ $('#clDelBody').html('<tr><td style="color:#DD5138;padding:8px;">讀取失敗</td></tr>'); });
}
function submitClean(){
    var pw = $('#clPw').val() || '', y = $.trim($('#clY').val() || '');
    if (!pw){ alert('請輸入最高權限帳號（員工 id=1）的密碼'); $('#clPw').focus(); return; }
    if (y !== 'Y'){ alert('請於確認欄輸入大寫 Y'); $('#clY').focus(); return; }
    if (!confirm('最後確認：即將清除本模組全部校驗紀錄、批次、附件（含實體檔），\n並把量具的週期／到期月／列入統計／校驗方式還原為空。\n\n此操作不可回復，確定執行？')) return;
    NProgress.start();
    $.post(API, {action:'clean_test_data', password:pw, confirm:y}, function(res){
        NProgress.done();
        if (!res.ok){ $('#clResult').html('<span style="color:#DD5138;">'+esc(res.error||'清除失敗')+'</span>'); return; }
        var d = res.deleted;
        $('#clPw').val(''); $('#clY').val('');
        $('#clResult').html('<span style="color:#8A5A2B;">已清除：校驗紀錄 '+d.calibration+' 筆、批次 '+d.batch
            + ' 筆、附件 '+d.attach+' 筆（實體檔 '+res.files_deleted+' 個）、附件對應 '+d.attach_map
            + ' 筆；還原量具欄位 '+d.tool_reset+' 支。已寫入 page_change_log。</span>');
        loadCleanPane();
        loadList();
    }, 'json').fail(function(x){
        NProgress.done();
        $('#clResult').html('<span style="color:#DD5138;">清除失敗：'+esc(x.responseJSON&&x.responseJSON.error||x.status)+'</span>');
    });
}

/* ================= 校驗週期批次設定（依類別） ================= */
$('#btnCycleSet').on('click', function(){
    var cats = CATS.filter(function(c){ return c.calib_required===1; });
    var h = cats.map(function(c){
        // 目前週期分布（同類別可能有不同設定）
        var cyc = {}, base = 0;
        ROWS.filter(function(r){ return String(r.QC_Tool_List_id)===String(c.QC_Tool_List_id); }).forEach(function(r){
            var k = (r.calib_cycle_months==null ? '未設' : r.calib_cycle_months+'月');
            cyc[k] = (cyc[k]||0)+1;
            if (!r.calibration_due) base++;
        });
        var cycTxt = Object.keys(cyc).map(function(k){ return k+'×'+cyc[k]; }).join('、') || '—';
        return '<tr data-id="'+c.QC_Tool_List_id+'">'
            + '<td style="text-align:left;">'+esc(c.QC_Tool)+'</td>'
            + '<td>'+c.tool_cnt+'</td>'
            + '<td style="font-size:11px;">'+esc(cycTxt)+(base?'<br><span style="color:#DD5138;">未設基準 '+base+' 支</span>':'')+'</td>'
            + '<td><input type="number" class="cy-cycle" min="0" step="1" style="width:64px;border:1px solid #D8BE93;border-radius:3px;padding:1px 4px;"></td>'
            + '<td><input type="month" class="cy-base" style="border:1px solid #D8BE93;border-radius:3px;padding:1px 4px;font-size:11px;"></td>'
            + '<td><select class="cy-managed" style="font-size:11px;border:1px solid #D8BE93;border-radius:3px;">'
            + '<option value="-1">不變</option><option value="1">是</option><option value="0">否</option></select></td>'
            + '<td><input type="checkbox" class="cy-ovr"></td></tr>';
    }).join('');
    $('#cycBody').html(h || '<tr><td colspan="7" style="padding:12px;color:#8a6d45;">沒有需校驗的類別</td></tr>');
    $('#cycFillVal').val(''); $('#cycOvrAll').prop('checked', false);
    openMask('cycMask');
});
$('#cycFillBtn').on('click', function(){
    var v = $('#cycFillVal').val();
    if (v === ''){ alert('請先填要套用的週期月數'); return; }
    $('#cycBody .cy-cycle').val(v);
});
$('#cycOvrAll').on('change', function(){ $('#cycBody .cy-ovr').prop('checked', this.checked); });
function submitCycle(){
    var items = [];
    $('#cycBody tr[data-id]').each(function(){
        var $tr = $(this);
        var cyc = $.trim($tr.find('.cy-cycle').val()), base = $tr.find('.cy-base').val(),
            mg = $tr.find('.cy-managed').val(), ovr = $tr.find('.cy-ovr').prop('checked') ? 1 : 0;
        if (cyc === '' && !base && mg === '-1') return;
        items.push({category_id:parseInt($tr.attr('data-id'),10), cycle:cyc, baseline_due:base||'',
                    managed:parseInt(mg,10), overwrite:ovr});
    });
    if (!items.length){ alert('沒有要套用的設定（請至少填一個類別的週期／基準月／統計選項）'); return; }
    if (!confirm('確定套用到 '+items.length+' 個類別底下的量具？\n未勾「覆寫」者只補目前空白的欄位。')) return;
    $.post(API, {action:'bulk_set_cycle', items:JSON.stringify(items)}, function(res){
        if (!res.ok){ alert(res.error||'套用失敗'); return; }
        closeMask('cycMask');
        alert('已套用，更新 '+res.total+' 支量具。');
        loadList();
    }, 'json').fail(function(x){ alert('套用失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}

/* ================= 年度校驗紀錄／年度校驗計畫表 ================= */
var YR_MODE = 'rec', YR_DATA = null;
$('#btnYear').on('click', function(){
    var cur = (META && META.cur_year) || new Date().getFullYear();
    var h = '';
    for (var y = cur + 1; y >= cur - 6; y--) h += '<option value="'+y+'"'+(y===cur?' selected':'')+'>'+y+'</option>';
    $('#yrSel').html(h);
    YR_MODE = 'rec'; yrTabStyle();
    loadYear();
    openMask('yearMask');
});
function yrTabStyle(){
    $('#yrTabRec').toggleClass('btn-warm-on', YR_MODE==='rec');
    $('#yrTabPlan').toggleClass('btn-warm-on', YR_MODE==='plan');
}
$('#yrTabRec').on('click', function(){ YR_MODE='rec'; yrTabStyle(); loadYear(); });
$('#yrTabPlan').on('click', function(){ YR_MODE='plan'; yrTabStyle(); loadYear(); });
$('#yrSel').on('change', loadYear);
function loadYear(){
    var y = $('#yrSel').val();
    $('#yrBody').html('<div style="padding:12px;color:#8a6d45;">載入中…</div>');
    $('#yrPlanApprovalBar').hide();
    $.getJSON(API, {action:(YR_MODE==='rec' ? 'year_records' : 'year_plan'), year:y}, function(res){
        if (!res.ok){ $('#yrBody').html('<div style="padding:12px;color:#DD5138;">'+esc(res.error||'載入失敗')+'</div>'); return; }
        YR_DATA = res;
        $('#yrBody').html(YR_MODE==='rec' ? yrRecHtml(res, false) : yrPlanHtml(res, false));
        if (YR_MODE === 'plan') renderPlanApprovalBar(res);
    }).fail(function(x){ $('#yrBody').html('<div style="padding:12px;color:#DD5138;">載入失敗</div>'); });
}
/** 年度校驗計畫表送出核准／核准退回（使用者 2026-08-12 明確要求；核准人走核准鏈 ai-rules/19） */
function renderPlanApprovalBar(res){
    var ap = res.approval, y = res.year, h = '';
    if (!ap) {
        h = '（本年度計畫表尚未送出核准）';
        if (PERMS && PERMS.canEdit) h += ' <span class="tc-op" onclick="planSubmit('+y+')"><i class="fa fa-paper-plane"></i> 送出核准</span>';
    } else if (ap.status === 'pending') {
        h = '核准中：'+esc(ap.submitted_by_name||'')+' 於 '+dispDate(ap.submitted_at)+' 送出。';
        h += ' <span class="tc-op" onclick="planDecide('+y+',\'approved\')"><i class="fa fa-check"></i> 核准</span>'
           + ' <span class="tc-op" style="color:#DD5138;" onclick="planDecide('+y+',\'rejected\')"><i class="fa fa-times"></i> 退回</span>';
    } else if (ap.status === 'approved') {
        h = '已核准：'+esc(ap.approver_name||'')+' 於 '+dispDate(ap.decided_at)+' 核准。';
        if (PERMS && PERMS.canEdit) h += ' <span class="tc-op" onclick="planSubmit('+y+')"><i class="fa fa-refresh"></i> 內容有異動，重新送出核准</span>';
    } else {
        h = '已退回：'+esc(ap.approver_name||'')+' 於 '+dispDate(ap.decided_at)+'。原因：'+esc(ap.note||'');
        if (PERMS && PERMS.canEdit) h += ' <span class="tc-op" onclick="planSubmit('+y+')"><i class="fa fa-paper-plane"></i> 重新送出核准</span>';
    }
    $('#yrPlanApprovalBar').html(h).show();
}
function planSubmit(y){
    if (!confirm(y+' 年度校驗計畫表確定送出核准？')) return;
    $.post(API, {action:'plan_submit', year:y}, function(res){
        if (!res.ok){ alert(res.error||'送出失敗'); return; }
        loadYear();
    }, 'json').fail(function(x){ alert('送出失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}
function planDecide(y, decision){
    var note = '';
    if (decision === 'rejected'){
        note = prompt('請填寫退回原因：');
        if (note === null) return;
        note = $.trim(note);
        if (!note){ alert('請填寫退回原因'); return; }
    } else if (!confirm(y+' 年度校驗計畫表確定核准？')) return;
    $.post(API, {action:'plan_decide', year:y, decision:decision, note:note}, function(res){
        if (!res.ok){ alert(res.error||'處理失敗'); return; }
        loadYear();
    }, 'json').fail(function(x){ alert('處理失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}
/** 附件欄：類別＋檔名清單（使用者 2026-08-12 明確要求，取代只顯示份數） */
function attachCellHtml(list){
    if (!list || !list.length) return '—';
    return list.map(function(a){ return esc(a.doc_type||'附件')+'：'+esc(a.name||a.original_name||''); }).join('<br>');
}
/** 校驗人員／覆驗者：列印版一律用圖章(ai-rules/18)，畫面預覽維持文字；只有內校才蓋人員章(外校對象是廠商) */
function signerCellHtml(name, calibDate, method, forPrint){
    if (!name) return '—';
    if (!forPrint || method !== '內校' || !window.EGStamp) return esc(name);
    var schema = (META && META.list_stamp) ? META.list_stamp.schema : null;
    return EGStamp.stamp(name, dispDate(calibDate), false, schema);
}
function yrRecHtml(res, forPrint){
    if (!res.list.length) return '<div style="padding:12px;color:#8a6d45;">'+res.year+' 年度尚無校驗紀錄</div>';
    var h = '<table class="hist"><thead><tr><th>校驗完成日</th><th>量具編號</th><th>類別</th><th>應校驗到期月</th>'
          + '<th>準時</th><th>結果</th><th>方式</th><th>校驗人員／單位</th><th>覆驗者</th><th>核准</th><th>憑證編號</th><th>下次到期</th><th>附件</th><th>登錄者</th></tr></thead><tbody>';
    res.list.forEach(function(r){
        var ontime = isOnTime(r.calib_date, r.due_date);
        h += '<tr><td>'+(forPrint?dispDate(r.calib_date):fmtDate(r.calib_date))+'</td><td><b>'+esc(r.Tool_No)+'</b></td><td>'+esc(r.category_name||'')+'</td>'
           + '<td>'+(fmtMonth(r.due_date)||'—')+'</td>'
           + '<td>'+(ontime===null?'—':(ontime?'<span style="color:#8A5A2B;">準時</span>':'<span style="color:#DD5138;">逾期</span>'))+'</td>'
           + '<td>'+(RESULT_LABEL[r.result]||r.result)+'</td><td>'+esc(r.method||'—')+'</td>'
           + '<td style="text-align:left;">'+signerCellHtml(r.operator, r.calib_date, r.method, forPrint)+'</td>'
           + '<td style="text-align:left;">'+signerCellHtml(r.reviewer_name, r.calib_date, r.method, forPrint)+'</td>'
           + '<td>'+(r.approval_status==='approved' ? signerCellHtml(r.approver_name, r.approved_at, '內校', forPrint)
                    : (r.approval_status==='pending' ? '（核准中）' : (r.approval_status==='rejected' ? '（已退回）' : '（免核准）')))+'</td>'
           + '<td>'+esc(r.cert_no||'—')+'</td>'
           + '<td>'+(fmtMonth(r.next_due)||'—')+'</td>'
           + '<td style="text-align:left;">'+(forPrint?attachCellHtml(r.attach_list):(r.attach_count>0?r.attach_count+' 份':'—'))+'</td>'
           + '<td>'+esc(r.created_by_name||'')+'</td></tr>';
    });
    return h + '</tbody></table>';
}
function yrPlanHtml(res){
    if (!res.list.length) return '<div style="padding:12px;color:#8a6d45;">'
        + res.year+' 年度沒有需排定校驗的量具（需勾「列入校驗率統計」並設週期或基準到期日）</div>';
    var h = '<table class="hist"><thead><tr><th style="text-align:left;">量具編號</th><th>類別</th><th>週期</th><th>方式</th>';
    for (var m = 1; m <= 12; m++) h += '<th>'+m+'月</th>';
    h += '</tr></thead><tbody>';
    res.list.forEach(function(r){
        h += '<tr><td style="text-align:left;"><b>'+esc(r.Tool_No)+'</b></td><td>'+esc(r.category_name||'')+'</td>'
           + '<td>'+(r.cycle?r.cycle+'月':'—')+'</td><td>'+esc(r.method||'—')+'</td>';
        for (var m = 1; m <= 12; m++){
            var c = r.months[m] || {};
            var txt = '', bg = '';
            if (c.done){ txt = '✔' + String(c.done).substr(8,2) + '日'; bg = c.late ? 'background:#F0A24B;color:#fff;' : 'background:#F7E0BD;'; }
            else if (c.plan){ txt = '●'; bg = 'background:#FBF0DD;color:#8A5A2B;'; }
            h += '<td style="'+bg+'font-size:11px;">'+txt+'</td>';
        }
        h += '</tr>';
    });
    h += '</tbody></table>'
       + '<div style="font-size:11px;color:#8a6d45;margin-top:6px;">● ＝該月應校驗（計畫）；✔＝已完成（數字為完成日）；'
       + '橘底＝逾期後才完成。計畫月份依各量具的下次應校驗日與週期推算。</div>';
    return h;
}
/** AS 文件編號（僅四階附加版次，比照 eg_asdoc_no()）；多筆彙總的現況清單一律印「現在最新版」，不回推業務日期 */
function asdocNo(doc){
    if (!doc || !doc.doc_no) return '';
    return doc.doc_no + (doc.doc_level==='四階' ? (doc.current_version||'') : '');
}
/** 製表/核准 圖章（用於年度校驗計畫表頁尾，使用者 2026-08-12 明確要求） */
function footerStampHtml(name, date){
    if (!name) return '';
    var schema = (META && META.footer_stamp) ? META.footer_stamp.schema : null;
    return window.EGStamp ? EGStamp.stamp(name, dispDate(date), false, schema) : esc(name);
}
function planFooterHtml(ap){
    var h = '<table class="yr-p-foot"><tr>';
    h += '<td><div class="foot-lbl">制表</div>' + (ap ? footerStampHtml(ap.submitted_by_name, ap.submitted_at) : '<span class="foot-na">（尚未送出核准，無制表紀錄）</span>') + '</td>';
    h += '<td><div class="foot-lbl">核准</div>' + (
        !ap ? '<span class="foot-na">（尚未送出核准）</span>'
        : ap.status==='approved' ? footerStampHtml(ap.approver_name, ap.decided_at)
        : ap.status==='pending' ? '<span class="foot-na">（核准中）</span>'
        : '<span class="foot-na">（已退回：'+esc(ap.note||'')+'）</span>'
    ) + '</td>';
    h += '</tr></table>';
    return h;
}
/** 列印：另開視窗只輸出表格（單一表格交給瀏覽器原生分頁，不自算分頁）；比照 ai-rules/16：大標題公司名、
 *  頁碼左下角(瀏覽器原生分頁交給 counter(pages)，本頁只有單一表格、單一文件，符合鐵則允許用 fixed/counter)、
 *  AS 文件編號右下角(多筆彙總清單一律印現況最新版)；校驗計畫表另加頁尾制表/核准圖章。 */
$('#yrPrint').on('click', function(){
    if (!YR_DATA){ alert('資料尚未載入'); return; }
    var isRec = YR_MODE === 'rec';
    var title = YR_DATA.year + ' 年度' + (isRec ? '量測儀器校驗紀錄' : '量測儀器校驗計畫表');
    var body = isRec ? yrRecHtml(YR_DATA, true) : yrPlanHtml(YR_DATA, true);
    if (!isRec) body += planFooterHtml(YR_DATA.approval);
    var docNo = asdocNo(META && META.as_docs ? META.as_docs[isRec ? 'tool_calib_record' : 'tool_calib_plan'] : null);
    var w = window.open('', '_blank');
    if (!w){ alert('瀏覽器阻擋了列印視窗，請允許彈出視窗'); return; }
    w.document.write('<!DOCTYPE html><html lang="zh-Hant"><head><meta charset="utf-8"><title>'+title+'</title><style>'
      + 'body{font-family:"Microsoft JhengHei",sans-serif;color:#3b2a17;margin:0;}'
      + '.pg{margin:12mm 8mm 16mm;}'
      + '.co{text-align:center;font-size:20px;font-weight:bold;letter-spacing:2px;}'
      + 'h2{font-size:15px;margin:2px 0;text-align:center;} .sub{font-size:11px;color:#6b5637;margin-bottom:8px;text-align:center;}'
      + 'table{width:100%;border-collapse:collapse;font-size:11px;}'
      + 'th,td{border:1px solid #999;padding:3px 4px;text-align:center;}'
      + 'thead th{background:#F7E0BD;-webkit-print-color-adjust:exact;print-color-adjust:exact;}'
      + 'thead{display:table-header-group;} tbody tr{page-break-inside:avoid;}'
      + 'table.yr-p-foot{margin-top:14px;} table.yr-p-foot td{width:50%;padding:6px;vertical-align:top;}'
      + 'table.yr-p-foot .foot-lbl{margin-bottom:4px;} table.yr-p-foot .foot-na{color:#888;font-size:11px;}'
      + 'table.yr-p-foot svg{width:76px !important;height:76px !important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}'
      + '@page{size:A4 landscape;margin:10mm 8mm 16mm 8mm;'
      + (docNo ? '@bottom-right{content:"'+docNo.replace(/["\\]/g,'')+'";font-size:9pt;color:#333;}' : '')
      + '@bottom-left{content:"第" counter(page) "頁／共" counter(pages) "頁";font-size:9pt;color:#333;}}'
      + '</style></head><body><div class="pg">'
      + '<div class="co">'+esc((META&&META.company_name)||'')+'</div>'
      + '<h2>'+title+'</h2><div class="sub">KPI 2-GM-04-01 #18 量測儀器按時校驗率</div>'
      + body + '</div></body></html>');
    w.document.close();
    w.focus();
    setTimeout(function(){ w.print(); }, 300);
});
$('#yrCsv').on('click', function(){
    if (!YR_DATA){ return; }
    var rows = [];
    if (YR_MODE === 'rec'){
        rows.push(['校驗完成日','量具編號','類別','應校驗到期月','準時','結果','方式','校驗人員／單位','憑證編號','下次到期月','附件份數','登錄者']);
        YR_DATA.list.forEach(function(r){
            var ot = isOnTime(r.calib_date, r.due_date);
            var ontime = (ot === null) ? '' : (ot ? '準時' : '逾期');
            rows.push([fmtDate(r.calib_date), r.Tool_No, r.category_name||'', fmtMonth(r.due_date), ontime,
                       RESULT_LABEL[r.result]||r.result, r.method||'', r.operator||'', r.cert_no||'',
                       fmtMonth(r.next_due), r.attach_count||0, r.created_by_name||'']);
        });
    } else {
        var head = ['量具編號','類別','週期(月)','方式'];
        for (var m=1;m<=12;m++) head.push(m+'月');
        rows.push(head);
        YR_DATA.list.forEach(function(r){
            var line = [r.Tool_No, r.category_name||'', r.cycle||'', r.method||''];
            for (var m=1;m<=12;m++){
                var c = r.months[m]||{};
                line.push(c.done ? ('完成 '+c.done+(c.late?'(逾期)':'')) : (c.plan ? '應校驗' : ''));
            }
            rows.push(line);
        });
    }
    var csv = '﻿' + rows.map(function(l){
        return l.map(function(v){ return '"'+String(v==null?'':v).replace(/"/g,'""')+'"'; }).join(',');
    }).join('\r\n');
    var a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([csv], {type:'text/csv;charset=utf-8;'}));
    a.download = YR_DATA.year + (YR_MODE==='rec' ? '年度校驗紀錄' : '年度校驗計畫表') + '.csv';
    a.click();
});

/* ---------- 匯出 CSV ---------- */
$('#btnCsv').on('click', function(){
    // 採購料號代碼只有採購看得到 → 沒權限時連 CSV 也不出這一欄
    var rows = [['量具編號','類別'].concat(SEE_SPEC_CODE ? ['採購料號'] : [])
        .concat(['規格','週期(月)','校驗方式','下次應校驗月','狀態','最近校驗日','最近結果','列入校驗率統計'])];
    ROWS.forEach(function(r){
        rows.push([r.Tool_No, r.category_name||'']
          .concat(SEE_SPEC_CODE ? [r.purchase_spec_id ? (r.spec_code||'') : ''] : [])
          .concat([
            r.purchase_spec_id ? $.trim(($.trim(r.spec_brand||'')+' '+$.trim(r.spec_text||''))) : '未對應料號',
            r.calib_cycle_months==null?'':r.calib_cycle_months,
            r.calib_method||'', fmtMonth(r.calibration_due), STATUS_LABEL[r.status]||r.status,
            r.last?fmtDate(r.last.calib_date):'', r.last?(RESULT_LABEL[r.last.result]||r.last.result):'',
            r.calib_managed===1?'是':'否']));
    });
    var csv = '﻿' + rows.map(function(l){
        return l.map(function(v){ return '"'+String(v==null?'':v).replace(/"/g,'""')+'"'; }).join(',');
    }).join('\r\n');
    var a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([csv], {type:'text/csv;charset=utf-8;'}));
    a.download = '量測儀器校驗清單_'+$('#ymSel').val()+'.csv';
    a.click();
});

$('#btnRoleHelp').on('click', function(){ openMask('helpMask'); });
$('.tc-mask').on('click', function(e){ if (e.target===this) this.style.display='none'; });
// UI 規範：聚焦全選、雙擊清空
$(document).on('focus', '.tc-modal input[type=text], .tc-modal input[type=number]', function(){ this.select(); });
// 清空後補送 input 事件，讓有在監聽的欄位（附件備註、量具料號對應草稿…）同步到記憶體狀態
$(document).on('dblclick', '.tc-modal input[type=text], .tc-modal input[type=number]', function(){ this.value=''; $(this).trigger('input'); });

if (canView) loadMeta(function(){ loadList(); });
</script>
</body>
</html>
