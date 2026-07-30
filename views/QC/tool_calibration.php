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
        table.bt-tbl td select { font-size:12px; border:1px solid #D8BE93; border-radius:3px; }
        .bt-quick button { height:24px; font-size:12px; padding:0 8px; border:1px solid #D8BE93; border-radius:3px;
            background:#fff; color:#5b3a1e; cursor:pointer; margin-right:4px; }
        .bt-quick button:hover { background:#F7E0BD; }
        .att-row { border:1px dashed #D8BE93; border-radius:4px; padding:5px 8px; margin-bottom:5px; font-size:12px; background:#FDF8EF; }
        .att-row .fn { font-weight:bold; color:#5b3a1e; }
        .att-row .op { color:#b5762a; cursor:pointer; margin-left:8px; }
        .att-row .op.del { color:#DD5138; }
        .att-map { margin-top:5px; padding:5px; background:#fff; border:1px solid #EADFC8; border-radius:4px;
            max-height:150px; overflow-y:auto; display:none; }
        .att-map label { display:inline-block; width:32%; font-weight:normal; font-size:12px; margin:0; }
        .tc-modal .m-head { background:#F7E0BD; color:#5b3a1e; font-weight:bold; padding:10px 15px; border-radius:8px 8px 0 0;
            display:flex; justify-content:space-between; }
        .tc-modal .m-head .m-close { cursor:pointer; color:#b5762a; }
        .tc-modal .m-body { padding:15px; overflow-y:auto; }
        .tc-modal .m-body label { display:block; font-size:13px; color:#5b3a1e; margin:9px 0 3px; }
        .tc-modal .m-body input[type=text], .tc-modal .m-body input[type=number], .tc-modal .m-body input[type=date],
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
        .ck-all-lab { font-weight:normal; font-size:11px; color:#8a6d45; margin:0; cursor:pointer; }
        table.hist td select.sel-grp { font-size:12px; border:1px solid #D8BE93; border-radius:4px; padding:2px 4px; max-width:150px; }
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
                <option value="managed">僅納管</option>
                <option value="overdue">逾期</option>
                <option value="soon">即將到期</option>
                <option value="ok">正常</option>
                <option value="nobaseline">未設基準</option>
                <option value="unmanaged">未納管</option>
            </select>
            <input type="text" id="kwSel" placeholder="搜尋編號" style="width:120px;">
            <button class="btn-warm" id="btnBatch" style="display:none;"><i class="fa fa-check-square-o"></i> 批次校驗</button>
            <button id="btnBatchList"><i class="fa fa-list-alt"></i> 批次紀錄</button>
            <button class="btn-warm" id="btnAdd" style="display:none;"><i class="fa fa-plus"></i> 新增儀器</button>
            <button id="btnCatSet" style="display:none;"><i class="fa fa-sliders"></i> 類別設定</button>
            <button id="btnAttSet" style="display:none;"><i class="fa fa-folder-open-o"></i> 附件設定</button>
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
                    <th>量具編號</th><th>類別</th><th>週期(月)</th><th>校驗方式</th>
                    <th>下次應校驗日</th><th>狀態</th><th>最近校驗</th><th>納入管理</th><th>操作</th>
                </tr></thead>
                <tbody id="tcBody"><tr><td colspan="9" style="padding:20px;color:#8a6d45;">載入中…</td></tr></tbody>
            </table>
        </div>
        <div style="font-size:11px;color:#8a6d45;margin-top:4px;">
            狀態：<span class="st-pill st-overdue">逾期</span> <span class="st-pill st-soon">30天內到期</span>
            <span class="st-pill st-ok">正常</span> <span class="st-pill st-nobaseline">未設基準</span>
            <span class="st-pill st-unmanaged">未納管</span>。
            「納入管理」者才計入 KPI；下次應校驗日＝上次校驗日＋週期（登錄完成後自動前滾）。
            <span id="tcExcluded" style="color:#b5762a;"></span>
        </div>
<?php endif; ?>
    </div>
    <?php include '../partPage/footer.html' ?>
</div>
</div>

<!-- 登錄校驗 modal -->
<div class="tc-mask" id="recMask"><div class="tc-modal">
    <div class="m-head"><span id="recTitle">登錄校驗</span><span class="m-close" onclick="closeMask('recMask')">✕</span></div>
    <div class="m-body">
        <div style="font-size:12px;color:#8a6d45;" id="recInfo"></div>
        <div class="grid2">
            <div><label>校驗完成日 *</label><input type="date" id="recDate"></div>
            <div><label>判定結果</label><select id="recResult">
                <option value="pass">合格</option><option value="pass_adjust">校正後合格</option><option value="fail">不合格</option>
            </select></div>
            <div><label>校驗方式</label><select id="recMethod">
                <option value="">—</option><option value="內校">內校</option><option value="外校">外校</option>
            </select></div>
            <div><label>校驗人員／單位</label><input type="text" id="recOperator" maxlength="50"></div>
            <div><label>憑證／報告編號</label><input type="text" id="recCert" maxlength="50"></div>
            <div><label>備註</label><input type="text" id="recNote" maxlength="200"></div>
        </div>
        <div style="font-size:12px;color:#b5762a;margin-top:8px;" id="recRoll"></div>
    </div>
    <div class="m-foot">
        <button class="b-cancel" onclick="closeMask('recMask')">取消</button>
        <button class="b-ok" onclick="submitRec()">登錄</button>
    </div>
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
            <div><label>下次應校驗日（基準）</label><input type="date" id="setBase"></div>
            <div><label>納入校驗管理（計入 KPI）</label><select id="setManaged">
                <option value="1">是</option><option value="0">否</option>
            </select></div>
        </div>
        <div style="font-size:12px;color:#8a6d45;margin-top:8px;">
            設定基準到期日後，之後每次登錄校驗會依週期自動前滾，不需再手動維護。<br>
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
                <div><label>校驗人員／單位（外校廠商）</label><input type="text" id="btOperator" maxlength="50"></div>
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
                        <th>量具編號</th><th>類別</th><th>週期</th><th>下次應校驗日</th><th>狀態</th><th>本次結果</th></tr></thead>
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

<!-- 校驗附件設定 modal（管理員） -->
<div class="tc-mask" id="attSetMask"><div class="tc-modal">
    <div class="m-head"><span>校驗附件設定</span><span class="m-close" onclick="closeMask('attSetMask')">✕</span></div>
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
        <button class="b-cancel" onclick="closeMask('attSetMask')">取消</button>
        <button class="b-ok" onclick="submitAttSet()">儲存</button>
    </div>
</div></div>

<!-- 類別設定 modal（只設校驗屬性；類別本身的新增/更名/刪除在 線上檢驗－量具設定） -->
<div class="tc-mask" id="catMask"><div class="tc-modal wide">
    <div class="m-head"><span>量具類別設定</span><span class="m-close" onclick="closeMask('catMask')">✕</span></div>
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
        <button class="b-cancel" onclick="closeMask('catMask')">取消</button>
        <button class="b-ok" onclick="submitCats()">儲存</button>
    </div>
</div></div>

<!-- 歷史 modal -->
<div class="tc-mask" id="hisMask"><div class="tc-modal wide">
    <div class="m-head"><span id="hisTitle">校驗歷史</span><span class="m-close" onclick="closeMask('hisMask')">✕</span></div>
    <div class="m-body" id="hisBody" style="font-size:13px;color:#5b3a1e;"></div>
</div></div>

<!-- 角色說明 modal -->
<div class="tc-mask" id="helpMask"><div class="tc-modal">
    <div class="m-head"><span>角色權限說明</span><span class="m-close" onclick="closeMask('helpMask')">✕</span></div>
    <div class="m-body" style="font-size:13px;color:#5b3a1e;line-height:1.8;">
        <b>校驗唯讀</b>：檢視儀器清單、校驗歷史、當月統計與匯出。<br>
        <b>校驗登錄</b>：唯讀＋登錄各儀器的校驗完成紀錄。<br>
        <b>校驗管理員</b>：登錄＋新增儀器、設定週期/納管/基準到期日、刪除誤登紀錄。<br>
        <b>管理者</b>：系統管理者固定擁有全部權限。<br>
        <hr style="border-color:#EADFC8;">
        本頁資料為 KPI「量測儀器按時校驗率(#18)」的計算來源；納入管理的儀器每月依到期日自動計入 KPI。
    </div>
</div></div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
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
var canView = <?= $perms['canView'] ? 'true' : 'false' ?>;
var RESULT_LABEL = {pass:'合格', pass_adjust:'校正後合格', fail:'不合格'};
var STATUS_LABEL = {overdue:'逾期', soon:'即將到期', ok:'正常', nobaseline:'未設基準', unmanaged:'未納管'};

function closeMask(id){ document.getElementById(id).style.display='none'; }
function openMask(id){ document.getElementById(id).style.display='block'; }
function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){
    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
function fmtDate(d){ return d ? String(d).substr(0,10) : ''; }

function loadMeta(cb){
    $.getJSON(API, {action:'meta'}, function(m){
        if (!m.ok){ alert(m.error||'載入失敗'); return; }
        META = m; PERMS = m.perms;
        $('#ymSel').val(m.cur_ym);
        TABS_DEF = m.tabs || [];
        ATT_CFG = m.attach || ATT_CFG;
        setCats(m.categories);
        if (m.perms.canEdit)  { $('#btnBatch').show(); }
        if (m.perms.canAdmin) { $('#btnAdd').show(); $('#btnCatSet').show(); $('#btnAttSet').show(); }
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
        html += '<td>'+(r.calib_cycle_months==null?'—':r.calib_cycle_months)+'</td>';
        html += '<td>'+esc(r.calib_method||'—')+'</td>';
        html += '<td>'+(fmtDate(r.calibration_due)||'—')+'</td>';
        html += '<td>'+statPill(r.status)+'</td>';
        html += '<td>'+last+'</td>';
        html += '<td class="'+(r.calib_managed===1?'managed-yes':'managed-no')+'">'+(r.calib_managed===1?'✔ 是':'否')+'</td>';
        html += '<td>';
        html += canEdit ? '<span class="tc-op" onclick="openRec('+r.Tool_id+')"><i class="fa fa-pencil"></i>登錄</span>'
                        : '<span class="tc-op disabled"><i class="fa fa-pencil"></i>登錄</span>';
        html += canAdmin ? '<span class="tc-op" onclick="openSet('+r.Tool_id+')"><i class="fa fa-gear"></i>設定</span>' : '';
        html += '<span class="tc-op" onclick="openHis('+r.Tool_id+')"><i class="fa fa-history"></i>歷史</span>';
        html += '</td></tr>';
    });
    $('#tcBody').html(html || '<tr><td colspan="9" style="padding:16px;color:#8a6d45;">無符合條件的儀器</td></tr>');
}

$('#statSel').on('change', function(){ tcPage=1; renderTable(); });
$('#kwSel').on('input', function(){ tcPage=1; renderTable(); });
$('#tcPageSize').on('change', function(){ tcPage=1; renderTable(); });
$('#tcPrev').on('click', function(){ if(tcPage>1){ tcPage--; renderTable(); } });
$('#tcNext').on('click', function(){ tcPage++; renderTable(); });
$('#ymSel').on('change', loadList);

/* ---------- 登錄/編輯校驗 ---------- */
var recTool = null, editCalibId = null;
function openRec(tid){
    var r = ROWS.find(function(x){ return x.Tool_id===tid; });
    recTool = r; editCalibId = null;
    $('#recTitle').text('登錄校驗：'+r.Tool_No+'（'+(r.category_name||'')+'）');
    $('#recInfo').html('目前下次應校驗日：<b>'+(fmtDate(r.calibration_due)||'（未設定）')+'</b>　週期：'
        +(r.calib_cycle_months==null?'（未設）':r.calib_cycle_months+' 月'));
    $('#recDate').val(META.today);
    $('#recResult').val('pass');
    $('#recMethod').val(r.calib_method||'');
    $('#recOperator').val(''); $('#recCert').val(''); $('#recNote').val('');
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
    $('#recInfo').html('本次應校驗到期日：<b>'+(fmtDate(a.due_date)||'（無）')+'</b>（編輯不改到期基準，僅修正內容）');
    $('#recDate').val(fmtDate(a.calib_date));
    $('#recResult').val(a.result);
    $('#recMethod').val(a.method||'');
    $('#recOperator').val(a.operator||''); $('#recCert').val(a.cert_no||''); $('#recNote').val(a.note||'');
    updateRoll();
    closeMask('hisMask'); openMask('recMask');
    setTimeout(function(){ $('#recDate').focus(); }, 100);
}
function updateRoll(){
    var cyc = recTool.calib_cycle_months, d = $('#recDate').val();
    if (cyc==null || !d){ $('#recRoll').text(''); return; }
    var dt = new Date(d); dt.setMonth(dt.getMonth()+parseInt(cyc,10));
    $('#recRoll').text('登錄後下次應校驗日將前滾為約 '+dt.toISOString().substr(0,10)+'（依週期 '+cyc+' 月）');
}
$('#recDate').on('change', updateRoll);
function submitRec(){
    if (!$('#recDate').val()){ alert('請選擇校驗完成日'); return; }
    var data = {calib_date:$('#recDate').val(), result:$('#recResult').val(), method:$('#recMethod').val(),
        operator:$('#recOperator').val(), cert_no:$('#recCert').val(), note:$('#recNote').val()};
    if (editCalibId){ data.action='edit_calib'; data.calib_id=editCalibId; }
    else { data.action='record_calib'; data.tool_id=recTool.Tool_id; }
    $.post(API, data, function(res){
        if (!res.ok){ alert(res.error||'儲存失敗'); return; }
        closeMask('recMask'); loadList();
    }, 'json').fail(function(x){ alert('儲存失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
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
        $('#setBase').val(fmtDate(setTool.calibration_due));
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
            + '<td>'+c.tool_cnt+(c.managed_cnt>0 ? '（納管 '+c.managed_cnt+'）' : '')+'</td>'
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
$('#btnCatSet').on('click', function(){ renderTabChips(); renderCatBody(null); openMask('catMask'); });

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
        if (!req && (c.managed_cnt||0) > 0) warn.push('・'+c.QC_Tool+'（'+c.managed_cnt+' 支已納管）');
        items.push({id:id, calib_required:req,
                    has_tool_no:$tr.find('.ck-hasno').prop('checked')?1:0,
                    calib_tab:$tr.find('.ck-tab').prop('checked')?1:0,
                    calib_tab_group:$tr.find('.sel-grp').val() || 0});
    });
    if (warn.length && !confirm('下列類別取消「需校驗」後，其已納管量具將不再顯示於本頁、也不計入 KPI：\n'
        + warn.join('\n') + '\n\n確定儲存？')) return;
    $.post(API, {action:'save_categories', items: JSON.stringify(items)}, function(res){
        if (!res.ok){ alert(res.error||'儲存失敗'); return; }
        if (res.tabs) TABS_DEF = res.tabs;
        setCats(res.categories); closeMask('catMask'); loadList();
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
        var h = '<table class="hist"><thead><tr><th>應校驗到期日</th><th>校驗完成日</th><th>準時</th><th>結果</th>'
              + '<th>方式</th><th>人員/單位</th><th>憑證編號</th><th>下次到期</th><th>附件</th><th>登錄者</th>'
              + ((canEdit||canDel)?'<th>操作</th>':'') + '</tr></thead><tbody>';
        res.list.forEach(function(a){
            var ontime = (a.due_date && a.calib_date) ? (fmtDate(a.calib_date)<=fmtDate(a.due_date)) : null;
            h += '<tr>';
            h += '<td>'+(fmtDate(a.due_date)||'—')+'</td>';
            h += '<td>'+fmtDate(a.calib_date)+'</td>';
            h += '<td>'+(ontime===null?'—':(ontime?'<span style="color:#8A5A2B;">準時</span>':'<span style="color:#DD5138;">逾期</span>'))+'</td>';
            h += '<td>'+(RESULT_LABEL[a.result]||a.result)+'</td>';
            h += '<td>'+esc(a.method||'—')+'</td>';
            h += '<td>'+esc(a.operator||'—')+'</td>';
            h += '<td>'+esc(a.cert_no||'—')+'</td>';
            h += '<td>'+(fmtDate(a.next_due)||'—')+'</td>';
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
    $('#btOperator').val(''); $('#btCert').val(''); $('#btNote').val('');
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
            + '<td>'+(fmtDate(r.calibration_due)||'—')+'</td>'
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
                 operator:$('#btOperator').val(), cert_no:$('#btCert').val(), note:$('#btNote').val(),
                 tools:JSON.stringify(tools), attach:JSON.stringify(attach)}, function(res){
        NProgress.done();
        if (!res.ok){ alert(res.error||'登錄失敗'); return; }
        BT_ATT = []; BT_SEL = {};
        closeMask('batMask');
        alert('已登錄 '+res.done+' 支量具的校驗紀錄。');
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
        h += '<table class="hist"><thead><tr><th>量具編號</th><th>類別</th><th>應校驗到期日</th><th>校驗日</th><th>準時</th><th>結果</th></tr></thead><tbody>';
        res.tools.forEach(function(t){
            var ontime = (t.due_date && t.calib_date) ? (fmtDate(t.calib_date) <= fmtDate(t.due_date)) : null;
            h += '<tr><td><b>'+esc(t.Tool_No)+'</b></td><td>'+esc(t.category_name||'')+'</td>'
               + '<td>'+(fmtDate(t.due_date)||'—')+'</td><td>'+fmtDate(t.calib_date)+'</td>'
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

/* ---------- 校驗附件設定（管理員） ---------- */
$('#btnAttSet').on('click', function(){
    $('#asDir').val(ATT_CFG.dir||''); $('#asExt').val(ATT_CFG.ext_raw||'');
    $('#asMax').val(ATT_CFG.maxmb||20); $('#asTypes').val(ATT_CFG.types_raw||'');
    openMask('attSetMask');
});
function submitAttSet(){
    $.post(API, {action:'save_attach_settings', dir:$('#asDir').val(), ext:$('#asExt').val(),
                 maxmb:$('#asMax').val(), types:$('#asTypes').val()}, function(res){
        if (!res.ok){ alert(res.error||'儲存失敗'); return; }
        ATT_CFG = res.attach;
        closeMask('attSetMask');
        alert('已儲存附件設定。');
    }, 'json').fail(function(x){ alert('儲存失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}

/* ---------- 匯出 CSV ---------- */
$('#btnCsv').on('click', function(){
    var rows = [['量具編號','類別','週期(月)','校驗方式','下次應校驗日','狀態','最近校驗日','最近結果','納入管理']];
    ROWS.forEach(function(r){
        rows.push([r.Tool_No, r.category_name||'', r.calib_cycle_months==null?'':r.calib_cycle_months,
            r.calib_method||'', fmtDate(r.calibration_due), STATUS_LABEL[r.status]||r.status,
            r.last?fmtDate(r.last.calib_date):'', r.last?(RESULT_LABEL[r.last.result]||r.last.result):'',
            r.calib_managed===1?'是':'否']);
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
$(document).on('dblclick', '.tc-modal input[type=text], .tc-modal input[type=number]', function(){ this.value=''; });

if (canView) loadMeta(function(){ loadList(); });
</script>
</body>
</html>
