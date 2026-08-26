<?php
/**
 * 機台設備一覽表（2026-08-14 建立）
 * 主檔沿用既有 machine_list（與 views/pm/kpi_main.php「機台資產設定」共用同一張表，兩邊即時同步）。
 * 新增：製造商欄位、保養人指派歷程（日期區間，跟人員在職狀態即時吻合）、機器設備履歴表（故障維修紀錄）、
 * 年度整份送簽核准（比照供應商稽核計劃模式）、AS 文件編號綁定（一覽表/履歴表各自獨立）。
 * 資料一律走 src/store/EquipMachineList_API.php；權限/共用邏輯 src/common/equip_list_lib.php
 */
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/pm/equipment_machine_list.php";
    header("Location:../../index.php");
    exit;
}
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/equip_list_lib.php';

$db = (new DBConnection())->getPDO();
equip_list_ensure_schema($db);
$eqUser = equip_list_current_user($db);
$perms = equip_list_perms($db, 'equip_machine', $eqUser);
$roleLabel = $perms['isAdmin'] ? '管理者'
           : ($perms['canAdmin'] ? '設備管理員'
           : ($perms['canEdit'] ? '設備登錄'
           : ($perms['canView'] ? '設備唯讀' : '無權限')));
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>機台設備一覽表</title>
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
        .eq-toolbar { display:flex; flex-wrap:wrap; gap:6px; align-items:center; clear:both;
            border:1.5px solid #E8D5B5; border-radius:8px; padding:8px 10px; margin-bottom:10px; background:#FDF8EF; }
        .eq-toolbar label { margin:0; font-size:13px; color:#5b3a1e; }
        .eq-toolbar select, .eq-toolbar input[type=text], .eq-toolbar button {
            height:30px; font-size:13px; line-height:1; padding:0 10px; border:1px solid #D8BE93;
            border-radius:4px; background:#fff; color:#5b3a1e; cursor:pointer; }
        .eq-toolbar button:hover { background:#F7E0BD; }
        .eq-toolbar .btn-warm { background:#F0A24B; color:#fff; border-color:#d98a33; }
        .eq-toolbar .btn-warm:hover { background:#d98a33; }
        .eq-role-badge { margin-left:auto; font-size:13px; color:#5b3a1e; background:#F7E0BD; border-radius:12px; padding:4px 12px; }
        .eq-table-wrap { overflow-x:auto; border:1px solid #E8D5B5; border-radius:6px; background:#fff; }
        table.eq-table { width:100%; border-collapse:collapse; font-size:13px; }
        table.eq-table th, table.eq-table td { border:1px solid #EADFC8; padding:5px 8px; white-space:nowrap; text-align:center; }
        table.eq-table thead th { position:sticky; top:0; z-index:5; background:#F7E0BD; color:#5b3a1e; font-weight:bold; }
        table.eq-table tbody tr:nth-child(even) { background:#FBF6EC; }
        table.eq-table tr.dis td { background:#efe7d8 !important; color:#b0a390; }
        .eq-op { color:#b5762a; cursor:pointer; margin:0 4px; }
        .eq-op:hover { color:#8A5A2B; text-decoration:underline; }
        .eq-warn { color:#DD5138; font-weight:bold; }
        .eq-leave { color:#8a6d45; font-size:11px; }
        .eq-none { color:#c9a876; }
        .eq-mask { display:none; position:fixed; inset:0; background:rgba(60,40,20,.45); z-index:1050; }
        .eq-modal { background:#fff; border-radius:8px; max-width:560px; margin:36px auto; box-shadow:0 5px 25px rgba(0,0,0,.3);
            max-height:90vh; display:flex; flex-direction:column; }
        .eq-modal.wide { max-width:860px; }
        .eq-modal .m-head { background:#F7E0BD; color:#5b3a1e; font-weight:bold; padding:10px 15px; border-radius:8px 8px 0 0;
            display:flex; justify-content:space-between; }
        .eq-modal .m-head .m-close { cursor:pointer; color:#b5762a; }
        .eq-modal .m-body { padding:15px; overflow-y:auto; }
        .eq-modal .m-body label { display:block; font-size:13px; color:#5b3a1e; margin:9px 0 3px; }
        .eq-modal .m-body input[type=text], .eq-modal .m-body input[type=date], .eq-modal .m-body input[type=number],
        .eq-modal .m-body select, .eq-modal .m-body textarea { width:100%; border:1px solid #D8BE93; border-radius:4px;
            padding:5px 8px; font-size:13px; box-sizing:border-box; }
        .eq-modal .m-foot { padding:10px 15px; border-top:1px solid #EADFC8; text-align:right; }
        .eq-modal .m-foot button { height:30px; padding:0 16px; border-radius:4px; font-size:13px; border:1px solid #d98a33; cursor:pointer; }
        .eq-modal .m-foot .b-ok { background:#F0A24B; color:#fff; }
        .eq-modal .m-foot .b-cancel { background:#fff; color:#5b3a1e; border-color:#D8BE93; margin-right:6px; }
        .eq-modal .m-foot .b-danger { background:#fff; color:#DD5138; border-color:#DD5138; float:left; }
        .grid2 { display:grid; grid-template-columns:1fr 1fr; gap:0 14px; }
        .eq-mask.top { z-index:1060; }   /* 常用語管理疊在履歴表跳窗之上 */
        .svc-ph-tools { float:right; font-weight:normal; display:flex; align-items:center; gap:6px; }
        .eq-modal .m-body select.svc-ph-sel { width:auto !important; max-width:230px; height:24px; padding:1px 4px !important;
            font-size:12px; color:#8a6d45; }
        .svc-kind { display:flex; flex-wrap:wrap; gap:14px; margin:3px 0 5px; }
        .eq-modal .m-body .svc-kind label { display:inline-flex !important; align-items:center; gap:4px; margin:0 !important; }
        .svc-hint { font-size:12px; color:#8a6d45; min-height:15px; margin-top:2px; }
        .svc-err { font-size:12px; color:#DD5138; min-height:15px; margin-top:2px; }
        .eq-modal .m-body input.err, .eq-modal .m-body select.err { border-color:#DD5138; background:#FFF6F3; }
        .ph-off { color:#b0a390; }
        table.hist { width:100%; border-collapse:collapse; font-size:12px; }
        table.hist th, table.hist td { border:1px solid #EADFC8; padding:4px 6px; text-align:center; }
        table.hist thead th { background:#F7E0BD; color:#5b3a1e; }
        .eq-noperm { margin:40px auto; max-width:520px; text-align:center; border:1.5px solid #E8D5B5; border-radius:10px;
            padding:30px; background:#FDF8EF; color:#5b3a1e; }
        .eq-totop { position:fixed; bottom:22px; right:22px; width:48px; height:48px; border:none; border-radius:50%;
            background:#F0A24B; color:#fff; font-size:12px; font-weight:bold; cursor:pointer; z-index:1000;
            box-shadow:0 4px 8px rgba(90,58,30,.3); display:none; }
        .eq-totop:hover { background:#d98a33; }
        @media print { .eq-totop { display:none !important; } }
        #eqListPrintHead { display:none; text-align:center; margin-bottom:8px; }
        @media print {
            .eq-toolbar, .nav_menu, .left_col, footer { display:none !important; }
            .right_col { margin:0 !important; padding:0 !important; }
            table.eq-table thead th { position:static; }
            body, .right_col, .container.body, .main_container { min-height:0 !important; height:auto !important; }
            #eqListPrintHead { display:block !important; }
            #eqListPrintHead .co { font-size:22px; font-weight:bold; letter-spacing:1px; }
            #eqListPrintHead .tt { font-size:16px; font-weight:bold; margin-top:3px; }
        }
    </style>
</head>
<body class="nav-sm">
<div class="container body">
<div class="main_container">
    <?php include '../partPage/sideAndTopBarMenu.html' ?>
    <div class="right_col" role="main">
        <div class="page-title" style="display:flex;align-items:center;flex-wrap:wrap;">
            <h2 style="margin:6px 0;">機台設備一覽表
                <small style="color:#8a6d45;">機台資產基本資料／保養人指派歷程／機器設備履歴表</small></h2>
            <button id="btnPageHelp" class="page-help-btn" style="margin-left:auto;"><i class="fa fa-question-circle"></i> 使用說明</button>
        </div>
        <div class="clearfix"></div>

<?php if (!$perms['canView']): ?>
        <div class="eq-noperm">
            <h4><i class="fa fa-lock"></i> 無機台設備一覽表檢閱權限</h4>
            <p>請洽管理者於「使用者權限設定」指派「設備唯讀／設備登錄／設備管理員」角色。</p>
        </div>
<?php else: ?>
        <div id="eqListPrintHead"></div>
        <div class="eq-toolbar">
            <label>機台種類</label>
            <select id="eqTypeSel"><option value="0">全部</option></select>
            <input type="text" id="eqKw" placeholder="機台名稱/編號/製造商" style="width:150px;">
            <label><input type="checkbox" id="eqShowDisabled"> 含已停用</label>
            <button id="btnSearch"><i class="fa fa-search"></i> 查詢</button>
            <button id="btnAdd" class="btn-warm" style="display:none;"><i class="fa fa-plus"></i> 新增機台</button>
            <button id="btnAsDoc" style="display:none;"><i class="fa fa-link"></i> AS文件綁定</button>
            <button id="btnSignSetting" style="display:none;"><i class="fa fa-pencil-square-o"></i> 送簽設定</button>
            <button id="btnPlan"><i class="fa fa-calendar-check-o"></i> 年度整份送簽</button>
            <button id="btnPrintAllService"><i class="fa fa-files-o"></i> 批次列印履歴表</button>
            <button id="btnPrintList"><i class="fa fa-print"></i> 列印清單</button>
            <span class="eq-role-badge">目前角色：<b><?= htmlspecialchars($roleLabel) ?></b></span>
        </div>

        <div class="eq-table-wrap">
            <table class="eq-table" id="eqTable">
                <thead><tr>
                    <th>機台種類</th><th>機器名稱</th><th>機台編號</th><th>現場編號</th>
                    <th>製造商</th><th>購入日期</th><th>保養人</th><th>狀態</th><th width="170">操作</th>
                </tr></thead>
                <tbody id="eqBody"><tr><td colspan="9" style="padding:20px;color:#8a6d45;">載入中…</td></tr></tbody>
            </table>
        </div>
        <button class="eq-totop" id="btnTotop" onclick="window.scrollTo({top:0,behavior:'smooth'});"><i class="fa fa-arrow-up"></i></button>
<?php endif; ?>
    </div>
</div>
</div>

<!-- ══ 機台資料 Modal ══ -->
<div class="eq-mask" id="miMask"><div class="eq-modal">
    <div class="m-head"><span id="miTitle"><i class="fa fa-cog"></i> 新增機台</span><span class="m-close" onclick="closeMask('miMask')">✕</span></div>
    <div class="m-body">
        <input type="hidden" id="mi-mid">
        <label>機器名稱 <span class="text-danger">*</span></label><input type="text" id="mi-name">
        <div class="grid2">
            <div><label>機台編號(公司財產編號)</label><input type="text" id="mi-asset-no"></div>
            <div><label>現場自訂編號</label><input type="text" id="mi-field-no"></div>
        </div>
        <div class="grid2">
            <div><label>機型</label><input type="text" id="mi-model"></div>
            <div><label>機台類型 <span class="text-danger">*</span></label><select id="mi-type"></select></div>
        </div>
        <div class="grid2">
            <div><label>製造商</label><input type="text" id="mi-manufacturer"></div>
            <div><label>位置(廠別)</label><select id="mi-position"></select></div>
        </div>
        <div class="grid2">
            <div><label>購入日期</label><input type="date" id="mi-pdate">
                <div style="font-size:11px;color:#8a6d45;margin-top:3px;">與生管 KPI「機台資產設定」共用同一筆，兩邊即時同步；留空＝不變更既有設定。</div></div>
        </div>
        <label>規格</label><input type="text" id="mi-spec">
        <label>備註</label><textarea id="mi-note" rows="2"></textarea>
        <div class="grid2">
            <div><label><input type="checkbox" id="mi-state"> 停用</label></div>
            <div id="mi-disabled-grp" style="display:none;"><label>停用日期(出售日期)</label><input type="date" id="mi-disabled-date"></div>
        </div>
    </div>
    <div class="m-foot">
        <button class="b-danger" id="mi-delete-btn" onclick="deleteMachine()" style="display:none;">停用機台</button>
        <button class="b-cancel" onclick="closeMask('miMask')">取消</button>
        <button class="b-ok" onclick="saveMachine()">儲存</button>
    </div>
</div></div>

<!-- ══ 保養人歷程 Modal ══ -->
<div class="eq-mask" id="asgMask"><div class="eq-modal wide">
    <div class="m-head"><span><i class="fa fa-user-circle-o"></i> 保養人指派歷程：<span id="asgMachineName"></span></span><span class="m-close" onclick="closeMask('asgMask')">✕</span></div>
    <div class="m-body">
        <div id="asgCurrent" style="margin-bottom:10px;"></div>
        <div id="asgAssignBox" style="border:1px dashed #E8D5B5;border-radius:6px;padding:10px;margin-bottom:12px;">
            <div class="grid2">
                <div><label>指派新保養人</label><select id="asg-user" data-eg-filter="輸入姓名篩選人員…"></select></div>
                <div><label>生效起日</label><input type="date" id="asg-start"></div>
            </div>
            <label>備註</label><input type="text" id="asg-note">
            <button class="b-ok" style="margin-top:8px;" onclick="assignNewAssignee()"><i class="fa fa-check"></i> 指派</button>
        </div>
        <div style="font-weight:bold;color:#5b3a1e;margin-bottom:4px;">歷史紀錄</div>
        <table class="hist"><thead><tr><th>保養人</th><th>起日</th><th>迄日</th><th>備註</th><th id="asgAdminCol" style="display:none;">操作</th></tr></thead>
        <tbody id="asgHistBody"></tbody></table>
    </div>
    <div class="m-foot"><button class="b-cancel" onclick="closeMask('asgMask')">關閉</button></div>
</div></div>

<!-- ══ 履歴表 Modal ══ -->
<div class="eq-mask" id="svcMask"><div class="eq-modal wide">
    <div class="m-head"><span><i class="fa fa-wrench"></i> 機器設備履歴表：<span id="svcMachineName"></span></span><span class="m-close" onclick="closeMask('svcMask')">✕</span></div>
    <div class="m-body">
        <div id="svcFormBox" style="border:1px dashed #E8D5B5;border-radius:6px;padding:10px;margin-bottom:12px;">
            <input type="hidden" id="svc-log-id">
            <div class="grid2">
                <div><label>日期 <span class="text-danger">*</span></label><input type="date" id="svc-date"></div>
                <div>
                    <label>廠商</label>
                    <input type="text" id="svc-vendor" placeholder="輸入廠商代號、簡稱或全名的部分字元，下方會列出可選清單">
                    <input type="hidden" id="svc-vendor-id">
                    <div class="svc-hint" id="svc-vendor-hint"></div>
                </div>
            </div>
            <label>問題
                <span class="svc-ph-tools">
                    <select id="svc-ph-problem" class="svc-ph-sel" data-eg-skip="1"></select>
                    <span class="eq-op" onclick="openPhraseModal('problem')"><i class="fa fa-cog"></i> 常用語</span>
                </span></label>
            <input type="text" id="svc-problem">
            <label>解決方式
                <span class="svc-ph-tools">
                    <select id="svc-ph-solution" class="svc-ph-sel" data-eg-skip="1"></select>
                    <span class="eq-op" onclick="openPhraseModal('solution')"><i class="fa fa-cog"></i> 常用語</span>
                </span></label>
            <input type="text" id="svc-solution">
            <div class="grid2">
                <div>
                    <label>執行者</label>
                    <div class="svc-kind">
                        <label><input type="radio" name="svcExecKind" value="user" checked> 廠內人員</label>
                        <label><input type="radio" name="svcExecKind" value="vendor"> 廠商</label>
                        <label><input type="radio" name="svcExecKind" value=""> 其他（自行輸入）</label>
                    </div>
                    <div id="svc-exec-user-wrap"><select id="svc-exec-user" data-eg-filter="輸入姓名篩選人員…"></select></div>
                    <input type="text" id="svc-exec-vendor" placeholder="輸入廠商代號、簡稱或全名的部分字元，下方會列出可選清單" style="display:none;">
                    <input type="hidden" id="svc-exec-vendor-id">
                    <input type="text" id="svc-executor" placeholder="執行者姓名" style="display:none;">
                    <div class="svc-err" id="svc-exec-err"></div>
                </div>
                <div><label>備註</label><input type="text" id="svc-note"></div>
            </div>
            <div style="margin-top:8px;">
                <button class="b-ok" onclick="saveServiceLog()"><i class="fa fa-save"></i> 儲存</button>
                <button class="b-cancel" onclick="resetServiceForm()">清除</button>
            </div>
        </div>
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
            <div style="font-weight:bold;color:#5b3a1e;">紀錄清單</div>
            <button onclick="printServiceLog(CUR_SVC_MID)"><i class="fa fa-print"></i> 列印本設備履歴表</button>
        </div>
        <table class="hist"><thead><tr><th>日期</th><th>廠商</th><th>問題</th><th>解決方式</th><th>執行者</th><th>核准</th><th width="90">操作</th></tr></thead>
        <tbody id="svcBody"></tbody></table>
    </div>
    <div class="m-foot"><button class="b-cancel" onclick="closeMask('svcMask')">關閉</button></div>
</div></div>

<!-- ══ 常用語管理 Modal（履歴表「問題／解決方式」預存片語） ══ -->
<div class="eq-mask top" id="phMask"><div class="eq-modal">
    <div class="m-head"><span><i class="fa fa-commenting-o"></i> 常用語管理</span><span class="m-close" onclick="closePhraseModal()">✕</span></div>
    <div class="m-body">
        <div style="font-size:12px;color:#8a6d45;margin-bottom:8px;">
            把現場常寫的問題與解決方式先存起來，填履歴表時直接從欄位右上的下拉帶出，不必每次重打；
            寫法一致，事後統計同一種故障才不會被拆成好幾種。停用的常用語不會出現在填表下拉，但已經填進紀錄的文字不受影響。
        </div>
        <div id="phFormBox" style="border:1px dashed #E8D5B5;border-radius:6px;padding:10px;margin-bottom:12px;">
            <input type="hidden" id="ph-id">
            <div class="grid2">
                <div><label>類別 <span class="text-danger">*</span></label><select id="ph-kind"></select></div>
                <div><label>排序（數字小的排前面）</label><input type="number" id="ph-sort" value="0"></div>
            </div>
            <label>內容 <span class="text-danger">*</span></label>
            <input type="text" id="ph-content" maxlength="500" placeholder="例：主軸異音／更換皮帶並校正張力">
            <div class="svc-err" id="ph-err"></div>
            <label style="display:inline-flex !important;align-items:center;gap:5px;">
                <input type="checkbox" id="ph-active" checked style="width:auto;"> 啟用（取消勾選＝停用，填表下拉不再出現）</label>
            <div style="margin-top:8px;">
                <button class="b-ok" onclick="savePhrase()"><i class="fa fa-save"></i> 儲存</button>
                <button class="b-cancel" onclick="resetPhraseForm()">清除</button>
            </div>
        </div>
        <table class="hist"><thead><tr><th width="80">類別</th><th>內容</th><th width="50">排序</th><th width="60">狀態</th><th width="90">操作</th></tr></thead>
        <tbody id="phBody"></tbody></table>
    </div>
    <div class="m-foot"><button class="b-cancel" onclick="closePhraseModal()">關閉</button></div>
</div></div>

<!-- ══ 年度整份送簽 Modal ══ -->
<div class="eq-mask" id="planMask"><div class="eq-modal">
    <div class="m-head"><span><i class="fa fa-calendar-check-o"></i> 年度整份送簽</span><span class="m-close" onclick="closeMask('planMask')">✕</span></div>
    <div class="m-body">
        <label>年度</label><select id="planYear"></select>
        <div id="planStatus" style="margin:10px 0;font-size:13px;"></div>
        <div class="grid2">
            <div><label>送出日期</label><input type="date" id="planSubmitDate"></div>
        </div>
        <div id="planDecideBox" style="display:none;margin-top:10px;border-top:1px dashed #EADFC8;padding-top:10px;">
            <label>核准日期</label><input type="date" id="planApproveDate">
            <label>退回原因(僅退回需填)</label><input type="text" id="planRejectNote">
        </div>
    </div>
    <div class="m-foot">
        <button class="b-cancel" onclick="closeMask('planMask')">關閉</button>
        <button class="b-cancel" id="planPrintBtn" onclick="printPlanSnapshot()" style="display:none;"><i class="fa fa-print"></i> 列印年度清單</button>
        <button class="b-ok" id="planSubmitBtn" style="display:none;" onclick="submitPlan()">送出</button>
        <button class="b-cancel" id="planRejectBtn" style="display:none;color:#DD5138;border-color:#DD5138;" onclick="decidePlan('rejected')">退回</button>
        <button class="b-ok" id="planApproveBtn" style="display:none;" onclick="decidePlan('approved')">核准</button>
    </div>
</div></div>

<!-- ══ 送簽設定 Modal ══ -->
<div class="eq-mask" id="signSetMask"><div class="eq-modal">
    <div class="m-head"><span><i class="fa fa-pencil-square-o"></i> 送簽設定</span><span class="m-close" onclick="closeMask('signSetMask')">✕</span></div>
    <div class="m-body">
        <label><input type="checkbox" id="ss-need"> 年度整份送簽需要主管核准</label>
        <div style="font-size:12px;color:#8a6d45;margin:8px 0 4px;">核准優先序（依序嘗試，取第一個有結果的方法；核准人不可為送出者本人）：</div>
        <label><input type="checkbox" class="ss-chain" value="dept_or_user" checked> 部門或指定人員（於「組織角色綁定設定」設定「機台設備一覽表年度核准」）</label>
        <label><input type="checkbox" class="ss-chain" value="auto_supervisor" checked> 自動抓送出者的上一階主管</label>
        <label><input type="checkbox" class="ss-chain" value="top_approver" checked> 全站最高決策者</label>
    </div>
    <div class="m-foot"><button class="b-cancel" onclick="closeMask('signSetMask')">取消</button><button class="b-ok" onclick="saveSignSetting()">儲存</button></div>
</div></div>

<!-- ══ 使用說明 Modal ══ -->
<div class="eq-mask" id="helpUseMask"><div class="eq-modal wide">
    <div class="m-head"><span><i class="fa fa-question-circle"></i> 機台設備一覽表 使用說明</span><span class="m-close" onclick="closeMask('helpUseMask')">✕</span></div>
    <div class="m-body help-doc">
        <h4>功能說明</h4>
        <p>本頁是機台設備的正式 AS9100 文件呈現（機台設備一覽表＋機器設備履歴表），資料與 <b>生管 KPI「機台資產設定」共用同一張機台主檔</b>，兩邊新增/編輯即時互相反映。</p>
        <h4>操作步驟</h4>
        <ul>
            <li>「新增機台」／點列表「機台資料」：編輯機器名稱、編號、機型、製造商、購入日期、規格、位置(廠別)、停用狀態等基本資料。位置(廠別)是<b>廠區下拉</b>（一廠/二廠/三廠，取自庫存區域設定），不是自由輸入。</li>
            <li>「保養人」：指派保養人並記錄日期區間；保養人異動時系統自動把前一位的生效區間結束、接續新一位。若目前指派中的保養人已離職，會以紅字警示提醒改派。</li>
            <li>「履歴」：登錄設備故障/維修紀錄（日期／廠商／問題／解決方式／執行者），可逐筆核准並列印。填寫時：
                <ul>
                    <li><b>廠商</b>：直接在欄位打廠商代號、簡稱或全名的部分字元，下方即時列出符合的廠商供點選（也可用方向鍵＋Enter）。
                        主檔沒有的臨時廠商可自行輸入文字，不會擋住存檔。</li>
                    <li><b>問題／解決方式</b>：欄位右上角的下拉可帶入預存的常用語（多選幾句會用「；」接在後面）；
                        按旁邊的「常用語」可新增／修改／停用／刪除常用語庫。想整段換掉時，照全站規則在欄位上<b>雙擊即可清空</b>。</li>
                    <li><b>執行者</b>：先選來源——<b>廠內人員</b>從在職名單挑（可打字篩選）、<b>廠商</b>比照廠商欄位打字挑選、
                        <b>其他</b>則自行輸入文字。選了來源卻沒挑到人/廠商會當場出現紅字提醒。</li>
                </ul></li>
            <li>「年度整份送簽」：每年將目前機台清單整份送出核准（比照供應商稽核計劃模式），核准後產生該年度快照，之後清單異動不影響已核准的快照內容。</li>
            <li>「批次列印履歴表」：依目前篩選結果，逐一列印每台機台的履歴表（筆數多時會提醒允許瀏覽器彈出視窗）。</li>
        </ul>
        <h4>重要行為</h4>
        <ul>
            <li>停用機台採軟刪除（<code>machine_list.state=1</code>），列表預設不顯示，勾選「含已停用」可查看。</li>
            <li><b>購入日期與生管 KPI「機台資產設定」是同一筆資料</b>（兩邊改都即時同步）。該筆同時存放購入金額／折舊方式等成本設定，故本頁購入日期<b>留空＝不變更</b>，不會把整筆折舊設定刪掉；金額與折舊參數請到 KPI 機台資產設定維護。</li>
            <li>AS 文件編號綁定：一覽表與履歴表各自獨立綁定，列印頁尾自動顯示對應編號；列印標題也取自綁定文件的名稱。</li>
            <li><b>列印清單</b>依目前查詢結果印出，欄位固定為：機器編號／機器名稱（機型自動換行印在名稱下方）／規格／製造商／保養人員／購買日期／備註，直式 A4、頁碼左下、AS 編號右下、左核准右製表。</li>
            <li>畫面上「機器名稱」欄位一格顯示兩行：第一行機器名稱、第二行機型。</li>
            <li>保養人下拉僅列在職人員（含留職停薪/育嬰留停會標記假別，離職者一律不可選）。</li>
        </ul>
        <h4>設定入口</h4>
        <p>AS文件綁定／送簽設定／歷史紀錄校正：僅「設備管理員」可見對應按鈕。</p>
        <h4>權限角色</h4>
        <p><b>設備唯讀</b>：僅可檢視。<b>設備登錄</b>：可新增/編輯機台、指派保養人、登錄履歴、送出年度清單。<b>設備管理員</b>：登錄權限＋刪除/校正歷史紀錄、AS文件綁定、送簽設定、核准/退回年度清單。管理者固定擁有全部權限，角色指派於「使用者權限設定」。</p>
    </div>
    <div class="m-foot"><button class="b-ok" onclick="closeMask('helpUseMask')">我知道了</button></div>
</div></div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script src="../../resource/js/eg_stamp.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_stamp.js') ?>"></script>
<script src="../../resource/js/eg_date_fmt.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_date_fmt.js') ?>"></script>
<script src="../../resource/js/eg_asdoc_picker.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_asdoc_picker.js') ?>"></script>
<script src="../../resource/js/eg_input_rules.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_input_rules.js') ?>"></script>
<script src="../../resource/js/eg_vendor_picker.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_vendor_picker.js') ?>"></script>
<script>
$(document).ready(function(){
    var $am = $('#sidebar-menu .nav.side-menu > li.active');
    if ($am.length) { $am.removeClass('active').find('ul.child_menu').hide(); $am.find('li.current-page').removeClass('current-page'); }
    $('#sidebar-menu').css('visibility','visible');
});

var API = '../../src/store/EquipMachineList_API.php';
var META = null, PERMS = null, ROWS = [];
var CUR_SVC_MID = 0, CUR_SVC_NAME = '', CUR_ASG_MID = 0;

function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){
    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
function dispDate(d){ return d ? egFmtDate(d) : ''; }
function closeMask(id){ document.getElementById(id).style.display='none'; }
function openMask(id){ document.getElementById(id).style.display='block'; }
function stampHtml(name, date, isDeputy){
    try { if (window.EGStamp && EGStamp.stamp) return EGStamp.stamp(name, date||'', !!isDeputy); } catch(e){}
    return esc(name||'');
}

function post(action, data, cb){
    NProgress.start();
    $.post(API, Object.assign({action:action}, data||{}), function(res){
        NProgress.done();
        if (!res.ok){ alert(res.error||'操作失敗'); return; }
        if (cb) cb(res);
    }, 'json').fail(function(x){ NProgress.done(); alert('連線失敗：'+(x.responseJSON&&x.responseJSON.error||x.status)); });
}

function loadMeta(cb){
    post('meta', {}, function(m){
        META = m; PERMS = m.perms; window.__ownCompany = m.company_name;
        var $t = $('#eqTypeSel');
        m.machine_types.forEach(function(t){ $t.append('<option value="'+t.process_type_id+'">'+esc(t.process_type)+'</option>'); });
        var $mt = $('#mi-type').empty().append('<option value="">-- 請選擇 --</option>');
        m.machine_types.forEach(function(t){ $mt.append('<option value="'+t.process_type_id+'">'+esc(t.process_type)+'</option>'); });
        var $ar = $('#mi-position').empty().append('<option value="0">-- 未設定 --</option>');
        (m.areas||[]).forEach(function(a){ $ar.append('<option value="'+a.area_id+'">'+esc(a.area_name)+'</option>'); });
        var $py = $('#planYear').empty();
        for (var y=m.cur_year+1; y>=m.cur_year-4; y--) $py.append('<option value="'+y+'">'+y+'</option>');
        $py.val(m.cur_year);
        if (m.perms.canEdit) $('#btnAdd').show();
        if (m.perms.canAdmin) { $('#btnAsDoc').show(); $('#btnSignSetting').show(); }
        if (cb) cb();
    });
}

function loadList(){
    post('list', {keyword: $('#eqKw').val(), machine_type_id: $('#eqTypeSel').val(), show_disabled: $('#eqShowDisabled').is(':checked')?1:0}, function(res){
        ROWS = res.rows; renderList(); renderPrintHead();
    });
}
function renderPrintHead(){
    $('#eqListPrintHead').html('<div class="co">'+esc((META&&META.company_name)||'')+'</div><div class="tt">機台設備一覽表</div>');
}
function assigneeCellHtml(a){
    if (!a) return '<span class="eq-none">尚未指派</span>';
    var h = esc(a.user_cname);
    if (a.is_resigned) h += ' <span class="eq-warn"><i class="fa fa-exclamation-triangle"></i> 已離職</span>';
    else if (a.on_leave) h += ' <span class="eq-leave">（'+esc(a.state_label)+'）</span>';
    return h;
}
// 機器名稱：名稱一行，機型自動換到下一行（使用者指定顯示方式）
function machineNameCell(r){
    var h = '<b>'+esc(r.machine)+'</b>';
    if (r.machine_model) h += '<br><span style="font-size:11px;color:#8a6d45;">'+esc(r.machine_model)+'</span>';
    return h;
}
function renderList(){
    var h = '';
    ROWS.forEach(function(r){
        var dis = r.state == 1;
        h += '<tr class="'+(dis?'dis':'')+'">'
           + '<td>'+esc(r.machine_type||'—')+'</td>'
           + '<td>'+machineNameCell(r)+'</td>'
           + '<td>'+esc(r.asset_no||'—')+'</td>'
           + '<td>'+esc(r.field_no||'—')+'</td>'
           + '<td>'+esc(r.manufacturer||'—')+'</td>'
           + '<td>'+(r.purchase_date ? dispDate(r.purchase_date) : '<span class="eq-none">未設定</span>')+'</td>'
           + '<td>'+assigneeCellHtml(r.assignee)+'</td>'
           + '<td>'+(dis?'停用':'正常')+'</td>'
           + '<td>'
           + '<span class="eq-op" onclick="openMachineModal('+r.machine_id+')">機台資料</span>'
           + '<span class="eq-op" onclick="openAssigneeModal('+r.machine_id+',\''+esc(r.machine).replace(/'/g,"\\'")+'\')">保養人</span>'
           + '<span class="eq-op" onclick="openServiceModal('+r.machine_id+',\''+esc(r.machine).replace(/'/g,"\\'")+'\')">履歴</span>'
           + '</td></tr>';
    });
    $('#eqBody').html(h || '<tr><td colspan="9" style="padding:20px;color:#8a6d45;">查無資料</td></tr>');
}
$('#btnSearch').on('click', loadList);
$('#eqKw').on('keydown', function(e){ if (e.key==='Enter') loadList(); });
window.addEventListener('scroll', function(){ $('#btnTotop').toggle(window.scrollY > 300); });

/* ── 機台資料 ── */
function openMachineModal(mid){
    if (!mid){
        $('#miTitle').html('<i class="fa fa-cog"></i> 新增機台'); $('#mi-mid').val('');
        $('#mi-name,#mi-asset-no,#mi-field-no,#mi-model,#mi-manufacturer,#mi-spec,#mi-note,#mi-pdate').val('');
        $('#mi-position').val('0');
        $('#mi-type').val(''); $('#mi-state').prop('checked', false); $('#mi-disabled-grp').hide(); $('#mi-disabled-date').val('');
        $('#mi-delete-btn').hide();
        openMask('miMask');
        return;
    }
    post('get', {machine_id: mid}, function(res){
        var m = res.row;
        $('#miTitle').html('<i class="fa fa-cog"></i> 編輯機台'); $('#mi-mid').val(m.machine_id);
        $('#mi-name').val(m.machine||''); $('#mi-asset-no').val(m.asset_no||''); $('#mi-field-no').val(m.field_no||'');
        $('#mi-model').val(m.machine_model||''); $('#mi-manufacturer').val(m.manufacturer||'');
        // 廠別選項若已被停用/刪除，補一個選項保住原值，避免存檔時被洗成未設定
        var pos = String(parseInt(m.position||0, 10) || 0);
        if (pos !== '0' && !$('#mi-position option[value="'+pos+'"]').length) {
            $('#mi-position').append('<option value="'+pos+'">（原設定廠別 #'+pos+'）</option>');
        }
        $('#mi-position').val(pos);
        $('#mi-spec').val(m.spec||''); $('#mi-note').val(m.note||''); $('#mi-type').val(m.machine_type_id||'');
        $('#mi-pdate').val(m.purchase_date||'');
        var disabled = String(m.state) === '1';
        $('#mi-state').prop('checked', disabled); $('#mi-disabled-grp').toggle(disabled); $('#mi-disabled-date').val(m.disabled_date||'');
        $('#mi-delete-btn').toggle(!!PERMS.canAdmin && !disabled);
        openMask('miMask');
    });
}
$('#mi-state').on('change', function(){
    $('#mi-disabled-grp').toggle(this.checked);
    if (this.checked && !$('#mi-disabled-date').val()) $('#mi-disabled-date').val(META.today);
});
$('#btnAdd').on('click', function(){ openMachineModal(0); });
function saveMachine(){
    var name = $('#mi-name').val().trim();
    if (!name){ alert('機器名稱不可為空'); return; }
    if (!$('#mi-type').val()){ alert('請選擇機台類型'); return; }
    post('save', {
        machine_id: $('#mi-mid').val(), machine: name, machine_type_id: $('#mi-type').val(),
        machine_model: $('#mi-model').val(), asset_no: $('#mi-asset-no').val(), field_no: $('#mi-field-no').val(),
        manufacturer: $('#mi-manufacturer').val(), position: $('#mi-position').val(), spec: $('#mi-spec').val(), note: $('#mi-note').val(),
        purchase_date: $('#mi-pdate').val(),
        disabled: $('#mi-state').is(':checked')?1:0, disabled_date: $('#mi-disabled-date').val()
    }, function(){ closeMask('miMask'); loadList(); });
}
function deleteMachine(){
    if (!confirm('確定要停用此機台？')) return;
    post('delete', {machine_id: $('#mi-mid').val()}, function(){ closeMask('miMask'); loadList(); });
}

/* ── 保養人歷程 ── */
function openAssigneeModal(mid, name){
    CUR_ASG_MID = mid;
    $('#asgMachineName').text(name);
    $('#asgAdminCol').toggle(!!PERMS.canAdmin);
    $('#asgAssignBox').toggle(!!PERMS.canEdit);
    $('#asg-start').val(META.today); $('#asg-note').val('');
    post('assignee_candidates', {}, function(res){
        var $s = $('#asg-user').empty().append('<option value="">-- 請選擇 --</option>');
        res.rows.forEach(function(p){ $s.append('<option value="'+p.id+'">'+esc(p.display)+'</option>'); });
    });
    loadAssigneeHistory();
    openMask('asgMask');
}
function loadAssigneeHistory(){
    post('assignee_history', {machine_id: CUR_ASG_MID}, function(res){
        $('#asgCurrent').html('<b>目前保養人：</b>'+assigneeCellHtml(res.current));
        var h = '';
        res.rows.forEach(function(r){
            h += '<tr><td>'+esc(r.user_cname)+(r.state===0?' <span class="eq-warn">(已離職)</span>':'')+'</td>'
               + '<td>'+dispDate(r.start_date)+'</td><td>'+(r.end_date?dispDate(r.end_date):'現任')+'</td>'
               + '<td>'+esc(r.note||'')+'</td>';
            if (PERMS.canAdmin) h += '<td><span class="eq-op" onclick="deleteAssigneeHist('+r.hist_id+')">刪除</span></td>';
            h += '</tr>';
        });
        $('#asgHistBody').html(h || '<tr><td colspan="5" style="color:#8a6d45;">尚無紀錄</td></tr>');
    });
}
function assignNewAssignee(){
    var uid = $('#asg-user').val();
    if (!uid){ alert('請選擇人員'); return; }
    post('assignee_assign', {machine_id: CUR_ASG_MID, user_id: uid, start_date: $('#asg-start').val(), note: $('#asg-note').val()}, function(){
        loadAssigneeHistory(); loadList();
    });
}
function deleteAssigneeHist(histId){
    if (!confirm('確定刪除此筆歷史紀錄？')) return;
    post('assignee_delete', {hist_id: histId}, function(){ loadAssigneeHistory(); loadList(); });
}

/* ── 履歴表 ── */
var VENDOR_API = '../../src/store/VendorPicker_API.php';
var PHRASES = [], PHRASE_KINDS = {problem:'問題', solution:'解決方式'}, CUR_PH_KIND = 'problem';

function openServiceModal(mid, name){
    CUR_SVC_MID = mid; CUR_SVC_NAME = name;
    $('#svcMachineName').text(name);
    $('#svcFormBox').toggle(!!PERMS.canEdit);
    loadExecutorCandidates();
    loadPhrases();
    resetServiceForm();
    loadServiceLog();
    openMask('svcMask');
}
/* 執行者的廠內人員名單：走共用 eg_people_list（只列在職、依職稱排序、跨部門顯示部門），與保養人指派同一份來源 */
function loadExecutorCandidates(){
    if (window.__execCands){ fillExecutorSelect(); return; }
    post('assignee_candidates', {}, function(res){ window.__execCands = res.rows || []; fillExecutorSelect(); });
}
function fillExecutorSelect(){
    var $s = $('#svc-exec-user'), keep = $s.val();
    $s.empty().append('<option value="">-- 請選擇人員 --</option>');
    (window.__execCands||[]).forEach(function(p){ $s.append('<option value="'+p.id+'">'+esc(p.display)+'</option>'); });
    if (keep) $s.val(keep);
}
function svcExecKind(){ return $('input[name=svcExecKind]:checked').val() || ''; }
function applyExecKind(){
    var k = svcExecKind();
    $('#svc-exec-user-wrap').toggle(k === 'user');   // 連 eg_input_rules 長出來的篩選框一起收合，所以是切換外層容器
    $('#svc-exec-vendor').toggle(k === 'vendor');
    $('#svc-executor').toggle(k === '');
    validateExec();
}
/* 錯誤即時偵測：選了來源卻沒挑到人/廠商就當場標紅字，不要等按儲存才報（UI 規則表單三總則） */
function validateExec(){
    var k = svcExecKind(), msg = '';
    if (k === 'user' && !$('#svc-exec-user').val()) msg = '請從名單挑選廠內人員；若執行者不是公司同仁請改選「廠商」或「其他」';
    if (k === 'vendor' && !$('#svc-exec-vendor').val().trim()) msg = '請輸入或從清單挑選廠商';
    $('#svc-exec-err').text(msg);
    $('#svc-exec-user').toggleClass('err', k === 'user' && !!msg);
    $('#svc-exec-vendor').toggleClass('err', k === 'vendor' && !!msg);
    return !msg;
}
function resetServiceForm(){
    $('#svc-log-id').val(''); $('#svc-date').val(META.today);
    $('#svc-vendor,#svc-vendor-id,#svc-problem,#svc-solution,#svc-executor,#svc-exec-vendor,#svc-exec-vendor-id,#svc-note').val('');
    $('#svc-vendor-hint').text('');
    $('#svc-exec-user').val('');
    $('input[name=svcExecKind][value="user"]').prop('checked', true);
    applyExecKind();
    $('#svc-exec-err').text('');
    $('#svc-exec-user,#svc-exec-vendor').removeClass('err');
}

/* ── 常用語（預存片語） ── */
function loadPhrases(cb){
    post('service_phrases', {include_inactive: (PERMS && PERMS.canEdit) ? 1 : 0}, function(res){
        PHRASES = res.rows || [];
        if (res.kinds) PHRASE_KINDS = res.kinds;
        renderPhraseSelects();
        if (cb) cb();
    });
}
function renderPhraseSelects(){
    Object.keys(PHRASE_KINDS).forEach(function(k){
        var $s = $('#svc-ph-'+k);
        if (!$s.length) return;
        $s.empty().append('<option value="">帶入常用'+esc(PHRASE_KINDS[k])+'…</option>');
        PHRASES.forEach(function(ph){
            if (ph.kind !== k || !ph.is_active) return;
            $s.append('<option value="'+esc(ph.content)+'">'+esc(ph.content)+'</option>');
        });
    });
}
function openPhraseModal(kind){
    CUR_PH_KIND = kind || 'problem';
    $('#phFormBox').toggle(!!PERMS.canEdit);
    var $k = $('#ph-kind').empty();
    Object.keys(PHRASE_KINDS).forEach(function(k){ $k.append('<option value="'+k+'">'+esc(PHRASE_KINDS[k])+'</option>'); });
    resetPhraseForm();
    renderPhraseList();
    openMask('phMask');
}
function closePhraseModal(){
    closeMask('phMask');
    renderPhraseSelects();   // 管理完直接反映到填表下拉，不必關掉履歴表再開一次
}
function resetPhraseForm(){
    $('#ph-id').val(''); $('#ph-content').val('').removeClass('err'); $('#ph-sort').val(0);
    $('#ph-active').prop('checked', true); $('#ph-kind').val(CUR_PH_KIND); $('#ph-err').text('');
}
function renderPhraseList(){
    var h = '';
    PHRASES.forEach(function(ph){
        h += '<tr class="'+(ph.is_active?'':'ph-off')+'"><td>'+esc(PHRASE_KINDS[ph.kind]||ph.kind)+'</td>'
           + '<td style="text-align:left;white-space:normal;">'+esc(ph.content)+'</td>'
           + '<td>'+ph.sort_order+'</td>'
           + '<td>'+(ph.is_active?'啟用':'停用')+'</td>'
           + '<td>'
           + (PERMS.canEdit ? '<span class="eq-op" onclick="editPhrase('+ph.phrase_id+')">編輯</span>' : '')
           + (PERMS.canAdmin ? '<span class="eq-op" onclick="deletePhrase('+ph.phrase_id+')">刪除</span>' : '')
           + '</td></tr>';
    });
    $('#phBody').html(h || '<tr><td colspan="5" style="color:#8a6d45;">尚無常用語</td></tr>');
}
function editPhrase(pid){
    var ph = PHRASES.find(function(x){ return x.phrase_id == pid; });
    if (!ph) return;
    $('#ph-id').val(ph.phrase_id); $('#ph-kind').val(ph.kind); $('#ph-content').val(ph.content).removeClass('err');
    $('#ph-sort').val(ph.sort_order); $('#ph-active').prop('checked', !!ph.is_active); $('#ph-err').text('');
}
function savePhrase(){
    var content = $('#ph-content').val().trim();
    if (!content){ $('#ph-err').text('內容不可空白'); $('#ph-content').addClass('err').focus(); return; }
    if (content.length > 500){ $('#ph-err').text('內容不可超過 500 字（目前 '+content.length+' 字）'); $('#ph-content').addClass('err').focus(); return; }
    post('service_phrase_save', {
        phrase_id: $('#ph-id').val(), kind: $('#ph-kind').val(), content: content,
        sort_order: $('#ph-sort').val() || 0, is_active: $('#ph-active').is(':checked') ? 1 : 0
    }, function(){
        loadPhrases(function(){ resetPhraseForm(); renderPhraseList(); });
    });
}
function deletePhrase(pid){
    if (!confirm('確定刪除此常用語？（已經填進履歴表的文字不受影響）')) return;
    post('service_phrase_delete', {phrase_id: pid}, function(){
        loadPhrases(function(){ resetPhraseForm(); renderPhraseList(); });
    });
}
function loadServiceLog(){
    post('service_list', {machine_id: CUR_SVC_MID}, function(res){
        var h = '';
        res.rows.forEach(function(r){
            h += '<tr><td>'+dispDate(r.service_date)+'</td><td>'+esc(r.vendor_name||'')+'</td>'
               + '<td style="white-space:normal;">'+esc(r.problem_desc||'')+'</td><td style="white-space:normal;">'+esc(r.solution_desc||'')+'</td>'
               + '<td>'+esc(r.executor_name||'')+(r.executor_kind==='vendor' ? ' <span style="font-size:11px;color:#8a6d45;">(廠商)</span>' : '')+'</td>'
               + '<td>'+(r.approved_by_name ? esc(r.approved_by_name)+' '+dispDate(r.approved_date) : (PERMS.canEdit ? '<span class="eq-op" onclick="approveServiceLog('+r.log_id+')">核准</span>' : '（未核准）'))+'</td>'
               + '<td>'
               + (PERMS.canEdit ? '<span class="eq-op" onclick="editServiceLog('+r.log_id+')">編輯</span>' : '')
               + (PERMS.canAdmin ? '<span class="eq-op" onclick="deleteServiceLog('+r.log_id+')">刪除</span>' : '')
               + '</td></tr>';
        });
        $('#svcBody').html(h || '<tr><td colspan="7" style="color:#8a6d45;">尚無紀錄</td></tr>');
        window.__svcRows = res.rows;
    });
}
function editServiceLog(logId){
    var r = (window.__svcRows||[]).find(function(x){ return x.log_id==logId; });
    if (!r) return;
    resetServiceForm();
    $('#svc-log-id').val(r.log_id); $('#svc-date').val(r.service_date);
    $('#svc-vendor').val(r.vendor_name||''); $('#svc-vendor-id').val(r.vendor_id||'');
    $('#svc-vendor-hint').text(r.vendor_id ? ('已對應廠商主檔：'+r.vendor_id) : '');
    $('#svc-problem').val(r.problem_desc||''); $('#svc-solution').val(r.solution_desc||'');
    $('#svc-note').val(r.note||'');
    // 舊紀錄沒有 executor_kind（那時候執行者是純文字欄）＝一律當「其他（自行輸入）」還原，不猜
    var k = r.executor_kind || '';
    $('input[name=svcExecKind][value="'+k+'"]').prop('checked', true);
    if (k === 'user') $('#svc-exec-user').val(r.executor_user_id||'');
    else if (k === 'vendor'){ $('#svc-exec-vendor').val(r.executor_name||''); $('#svc-exec-vendor-id').val(r.executor_vendor_id||''); }
    else $('#svc-executor').val(r.executor_name||'');
    applyExecKind();
}
function saveServiceLog(){
    if (!$('#svc-date').val()){ alert('請填日期'); $('#svc-date').focus(); return; }
    if (!validateExec()){ (svcExecKind()==='user' ? $('#svc-exec-user') : $('#svc-exec-vendor')).focus(); return; }
    var k = svcExecKind();
    post('service_save', {
        log_id: $('#svc-log-id').val(), machine_id: CUR_SVC_MID, service_date: $('#svc-date').val(),
        vendor_name: $('#svc-vendor').val(), vendor_id: $('#svc-vendor-id').val(),
        problem_desc: $('#svc-problem').val(), solution_desc: $('#svc-solution').val(),
        executor_kind: k,
        executor_user_id: k === 'user' ? $('#svc-exec-user').val() : '',
        executor_vendor_id: k === 'vendor' ? $('#svc-exec-vendor-id').val() : '',
        executor_name: k === 'vendor' ? $('#svc-exec-vendor').val() : (k === 'user' ? '' : $('#svc-executor').val()),
        note: $('#svc-note').val()
    }, function(){ resetServiceForm(); loadServiceLog(); });
}
function deleteServiceLog(logId){
    if (!confirm('確定刪除此筆履歴紀錄？')) return;
    post('service_delete', {log_id: logId}, function(){ loadServiceLog(); });
}
function approveServiceLog(logId){
    if (!confirm('確定核准此筆紀錄？')) return;
    post('service_approve', {log_id: logId, approved_date: META.today}, function(){ loadServiceLog(); });
}

/* ── 履歴表列印（單筆 + 批次排隊，ai-rules/16 第三之五節） ── */
function printServiceDoc(mid, mname, rows, onDone){
    var docNo = META.service_as_doc ? (META.service_as_doc.doc_no + (META.service_as_doc.doc_level==='四階'?(META.service_as_doc.current_version||''):'')) : '';
    var body = '<table class="hist" style="margin-bottom:10px;"><tbody><tr><th style="width:110px;">機台名稱</th><td>'+esc(mname)+'</td></tr></tbody></table>';
    if (!rows.length) body += '<div style="color:#8a6d45;padding:8px 0;">尚無履歴紀錄</div>';
    else {
        body += '<table class="hist"><thead><tr><th>日期</th><th>廠商</th><th>問題</th><th>解決方式</th><th>執行者</th><th>核准</th></tr></thead><tbody>';
        rows.forEach(function(r){
            body += '<tr><td>'+dispDate(r.service_date)+'</td><td>'+esc(r.vendor_name||'')+'</td>'
               + '<td style="text-align:left;">'+esc(r.problem_desc||'')+'</td><td style="text-align:left;">'+esc(r.solution_desc||'')+'</td>'
               + '<td>'+esc(r.executor_name||'')+'</td><td>'+(r.approved_by_name?esc(r.approved_by_name)+' '+dispDate(r.approved_date):'（未核准）')+'</td></tr>';
        });
        body += '</tbody></table>';
    }
    body += '<div style="display:flex;justify-content:space-between;margin-top:26px;">'
          + '<div>核准：'+stampHtml('', '', false)+'</div>'
          + '<div>製表：'+stampHtml(META.cur_user_name||'', META.today, false)+'</div></div>';
    var w = window.open('', '_blank');
    if (!w){ alert('瀏覽器阻擋了列印視窗，請允許彈出視窗'); if (onDone) onDone(); return; }
    w.document.write('<!DOCTYPE html><html lang="zh-Hant"><head><meta charset="utf-8"><title>機器設備履歴表：'+esc(mname)+'</title><style>'
      + 'body{font-family:"Microsoft JhengHei",sans-serif;color:#3b2a17;margin:0;}'
      + '.pg{margin:12mm 8mm 16mm;}.co{text-align:center;font-size:20px;font-weight:bold;letter-spacing:2px;}'
      + 'h2{font-size:15px;margin:2px 0 10px;text-align:center;}'
      + 'table{width:100%;border-collapse:collapse;font-size:11px;margin-bottom:4px;}'
      + 'th,td{border:1px solid #999;padding:3px 5px;text-align:center;}'
      + 'table th{background:#F7E0BD;-webkit-print-color-adjust:exact;print-color-adjust:exact;}'
      + 'svg{width:66px !important;height:66px !important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}'
      + '@page{size:A4 landscape;margin:10mm 8mm 16mm 8mm;'
      + (docNo ? '@bottom-right{content:"'+docNo.replace(/["\\]/g,'')+'";font-size:9pt;color:#333;}' : '')
      + '@bottom-left{content:"第" counter(page) "頁／共" counter(pages) "頁";font-size:9pt;color:#333;}}'
      + '</style></head><body><div class="pg"><div class="co">'+esc((META&&META.company_name)||'')+'</div>'
      + '<h2>機器設備履歴表</h2>' + body + '</div></body></html>');
    w.document.close();
    w.focus();
    setTimeout(function(){ w.print(); if (onDone) setTimeout(onDone, 500); }, 300);
}
function printServiceLog(mid){
    if (!mid) return;
    post('service_list', {machine_id: mid}, function(res){ printServiceDoc(mid, CUR_SVC_NAME, res.rows); });
}
var PRINT_ALL_BATCH_THRESHOLD = 15;
$('#btnPrintAllService').on('click', function(){
    if (!ROWS.length){ alert('目前沒有搜尋結果可列印'); return; }
    if (ROWS.length > PRINT_ALL_BATCH_THRESHOLD) {
        if (!confirm('共 '+ROWS.length+' 台機台，將逐一開啟列印視窗，請確認瀏覽器允許彈出視窗。是否繼續？')) return;
    }
    var idx = 0;
    function next(){
        if (idx >= ROWS.length){ alert('已完成列印 '+ROWS.length+' 筆履歴表。'); return; }
        var r = ROWS[idx++];
        post('service_list', {machine_id: r.machine_id}, function(res){
            printServiceDoc(r.machine_id, r.machine, res.rows, next);
        });
    }
    next();
});

/* ── AS 文件綁定 ── */
$('#btnAsDoc').on('click', function(){
    var kind = confirm('確定要設定「機台設備一覽表」的綁定？\n（取消則改設定「機器設備履歴表」）') ? 'list' : 'service';
    var cur = kind==='list' ? META.list_as_doc : META.service_as_doc;
    EGAsDoc.open({ docs: META.as_doc_list, current: cur?cur.id:0, title: (kind==='list'?'機台設備一覽表':'機器設備履歴表')+' AS文件綁定',
        onSave: function(id){ post('asdoc_save', {kind:kind, doc_id:id}, function(){ loadMeta(); alert('已儲存綁定'); }); } });
});

/* ── 送簽設定 ── */
$('#btnSignSetting').on('click', function(){
    $('#ss-need').prop('checked', !!META.sign_setting.need);
    $('.ss-chain').prop('checked', false);
    (META.approver_chain||[]).forEach(function(m){ $('.ss-chain[value="'+m+'"]').prop('checked', true); });
    openMask('signSetMask');
});
function saveSignSetting(){
    var chain = $('.ss-chain:checked').map(function(){ return this.value; }).get();
    post('sign_setting_save', {need: $('#ss-need').is(':checked')?1:0, chain: JSON.stringify(chain)}, function(){
        closeMask('signSetMask'); loadMeta(); alert('已儲存');
    });
}

/* ── 年度整份送簽 ── */
var CUR_PLAN_YEAR = null, CUR_PLAN_LOCK = null;
$('#btnPlan').on('click', function(){ openMask('planMask'); loadPlanData(); });
$('#planYear').on('change', loadPlanData);
function loadPlanData(){
    var year = $('#planYear').val() || META.cur_year;
    post('plan_data', {year: year}, function(res){
        CUR_PLAN_YEAR = res.year; CUR_PLAN_LOCK = res.lock;
        $('#planSubmitDate').val(META.today);
        var s = '';
        if (!res.lock) s = '尚未送出。';
        else {
            var stMap = {pending:'待核准', approved:'已核准', rejected:'已退回'};
            s = '狀態：<b>'+(stMap[res.lock.status]||res.lock.status)+'</b>　送出：'+esc(res.lock.submitted_by_name||'')+' '+dispDate(res.lock.submit_date);
            if (res.lock.status==='approved') s += '<br>核准：'+esc(res.lock.approved_by_name||'')+' '+dispDate(res.lock.approved_date);
        }
        $('#planStatus').html(s);
        var canSubmit = PERMS.canEdit && (!res.lock || res.lock.status==='rejected');
        $('#planSubmitBtn').toggle(canSubmit);
        $('#planDecideBox,#planApproveBtn,#planRejectBtn').toggle(!!res.can_decide);
        $('#planPrintBtn').toggle(res.lock && res.lock.status==='approved');
        if (res.can_decide) $('#planApproveDate').val(META.today);
    });
}
function submitPlan(){
    var snapshot = ROWS.map(function(r){ return {machine_id:r.machine_id, machine:r.machine, asset_no:r.asset_no, field_no:r.field_no,
        machine_model:r.machine_model, manufacturer:r.manufacturer, machine_type:r.machine_type,
        purchase_date:r.purchase_date, spec:r.spec, note:r.note,
        assignee: r.assignee ? r.assignee.user_cname : ''}; });
    post('plan_submit', {year: CUR_PLAN_YEAR||$('#planYear').val(), submit_date: $('#planSubmitDate').val(), snapshot: JSON.stringify(snapshot)}, function(){
        loadPlanData(); alert('已送出');
    });
}
function decidePlan(decision){
    if (decision==='rejected' && !$('#planRejectNote').val().trim()){ alert('請填寫退回原因'); return; }
    post('plan_decide', {year: CUR_PLAN_YEAR, decision: decision, approved_date: $('#planApproveDate').val(), note: $('#planRejectNote').val()}, function(){
        loadPlanData(); alert(decision==='approved'?'已核准':'已退回');
    });
}
/* ── 清單列印（欄位由使用者指定：機器編號／機器名稱／規格／製造商／保養人員／購買日期／備註） ── */
function machineListPrintCss(docNo){
    return 'body{font-family:"Microsoft JhengHei",sans-serif;color:#3b2a17;margin:0;}.pg{margin:12mm 8mm 16mm;}'
      + '.co{text-align:center;font-size:20px;font-weight:bold;letter-spacing:2px;}h2{font-size:15px;margin:2px 0 10px;text-align:center;}'
      + 'table{width:100%;border-collapse:collapse;font-size:11px;}th,td{border:1px solid #999;padding:3px 5px;text-align:center;vertical-align:middle;}'
      + 'th{background:#F7E0BD;-webkit-print-color-adjust:exact;print-color-adjust:exact;}'
      + 'tr{page-break-inside:avoid;}'
      + 'svg{width:66px !important;height:66px !important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}'
      + '@page{size:A4 portrait;margin:10mm 8mm 16mm 8mm;'
      + (docNo ? '@bottom-right{content:"'+docNo.replace(/["\\]/g,'')+'";font-size:9pt;color:#333;}' : '')
      + '@bottom-left{content:"第" counter(page) "頁／共" counter(pages) "頁";font-size:9pt;color:#333;}}';
}
function machineListPrintRows(rows, nameOf, assigneeOf){
    var body = '<table><thead><tr><th width="12%">機器編號</th><th width="20%">機器名稱</th><th>規格</th>'
             + '<th width="12%">製造商</th><th width="10%">保養人員</th><th width="12%">購買日期</th><th width="16%">備註</th></tr></thead><tbody>';
    rows.forEach(function(r){
        var nm = esc(nameOf(r));
        if (r.machine_model) nm += '<br><span style="font-size:10px;">'+esc(r.machine_model)+'</span>';
        body += '<tr><td>'+esc(r.asset_no||'')+'</td><td>'+nm+'</td>'
              + '<td style="text-align:left;">'+esc(r.spec||'')+'</td>'
              + '<td>'+esc(r.manufacturer||'')+'</td>'
              + '<td>'+esc(assigneeOf(r))+'</td>'
              + '<td>'+dispDate(r.purchase_date)+'</td>'
              + '<td style="text-align:left;">'+esc(r.note||'')+'</td></tr>';
    });
    return body + '</tbody></table>';
}
function openPrintWindow(title, heading, body, docNo){
    var w = window.open('', '_blank');
    if (!w){ alert('瀏覽器阻擋了列印視窗，請允許彈出視窗'); return; }
    w.document.write('<!DOCTYPE html><html lang="zh-Hant"><head><meta charset="utf-8"><title>'+esc(title)+'</title><style>'
      + machineListPrintCss(docNo)
      + '</style></head><body><div class="pg"><div class="co">'+esc((META&&META.company_name)||'')+'</div>'
      + '<h2>'+esc(heading)+'</h2>' + body + '</div></body></html>');
    w.document.close(); w.focus(); setTimeout(function(){ w.print(); }, 300);
}
// 表頭一律用綁定的 AS 文件名稱（ai-rules/16 第一節：禁寫死），未綁定才退回頁面名稱
function listDocName(doc){ return (doc && doc.doc_name) ? doc.doc_name : '機台設備一覽表'; }
function listDocNo(doc){ return doc ? (doc.doc_no + (doc.doc_level==='四階'?(doc.current_version||''):'')) : ''; }
$('#btnPrintList').on('click', function(){
    if (!ROWS.length){ alert('目前沒有搜尋結果可列印'); return; }
    var doc = META.list_as_doc;
    var body = machineListPrintRows(ROWS, function(r){ return r.machine||''; },
                                   function(r){ return r.assignee ? r.assignee.user_cname : ''; });
    body += '<div style="display:flex;justify-content:space-between;margin-top:26px;">'
          + '<div>核准：'+stampHtml('', '', false)+'</div>'
          + '<div>製表：'+stampHtml(META.cur_user_name||'', META.today, false)+'</div></div>';
    openPrintWindow(listDocName(doc), listDocName(doc), body, listDocNo(doc));
});

function printPlanSnapshot(){
    post('plan_data', {year: CUR_PLAN_YEAR}, function(res){
        var lock = res.lock;
        var rows = lock && lock.snapshot_json ? JSON.parse(lock.snapshot_json) : [];
        var doc = res.list_as_doc;
        var body = machineListPrintRows(rows, function(r){ return r.machine||''; }, function(r){ return r.assignee||''; });
        body += '<div style="display:flex;justify-content:space-between;margin-top:26px;">'
              + '<div>核准：'+stampHtml(lock.approved_by_name, lock.approved_date, false)+'</div>'
              + '<div>製表：'+stampHtml(lock.submitted_by_name, lock.submit_date, false)+'</div></div>';
        openPrintWindow(listDocName(doc)+' '+CUR_PLAN_YEAR+'年度',
                        listDocName(doc)+'（'+CUR_PLAN_YEAR+' 年度核准清單）', body, listDocNo(doc));
    });
}

/* ── 履歴表欄位的事件綁定（一次綁好，跳窗開關不重綁） ── */
$(document).on('change', 'input[name=svcExecKind]', applyExecKind);
$(document).on('change', '#svc-exec-user', validateExec);
$(document).on('input', '#svc-exec-vendor', validateExec);
// 常用語帶入：欄位已有內容時用「；」接在後面（可疊好幾句），要整段換掉就照全站規則雙擊欄位清空再選
$(document).on('change', '.svc-ph-sel', function(){
    var val = this.value;
    if (!val) return;
    var target = (this.id === 'svc-ph-problem') ? '#svc-problem' : '#svc-solution';
    var cur = $(target).val().trim();
    $(target).val(cur ? (cur.replace(/[；;]$/, '') + '；' + val) : val).focus();
    this.value = '';
});

$(document).ready(function(){
    if (!window.EGVendorPicker) return;
    EGVendorPicker.attach(document.getElementById('svc-vendor'), {
        apiUrl: VENDOR_API,
        onSelect: function(row){
            $('#svc-vendor-id').val(row.maker_id_no);
            $('#svc-vendor-hint').text('已對應廠商主檔：'+row.maker_id_no+(row.maker_id ? ('　'+row.maker_id) : ''));
        },
        // 使用者自行改字＝不再對應主檔那一筆，代號要跟著清掉，否則會留下名稱與代號兜不起來的紀錄
        onInput: function(){ $('#svc-vendor-id').val(''); $('#svc-vendor-hint').text(''); }
    });
    EGVendorPicker.attach(document.getElementById('svc-exec-vendor'), {
        apiUrl: VENDOR_API,
        onSelect: function(row){ $('#svc-exec-vendor-id').val(row.maker_id_no); validateExec(); },
        onInput: function(){ $('#svc-exec-vendor-id').val(''); validateExec(); }
    });
});

$('#btnPageHelp').on('click', function(){ openMask('helpUseMask'); });

<?php if ($perms['canView']): ?>
loadMeta(function(){ loadList(); });
<?php endif; ?>
</script>
</body>
</html>
