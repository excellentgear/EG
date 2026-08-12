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
        .pf-modal { background:#fff; border-radius:8px; max-width:600px; margin:36px auto; box-shadow:0 5px 25px rgba(0,0,0,.3);
            max-height:90vh; display:flex; flex-direction:column; }
        .pf-modal.xwide { max-width:96vw; margin:12px auto; max-height:96vh; }
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
        .pf-head-grid { display:grid; grid-template-columns:1fr 2fr; gap:0 14px; }
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
        .pf-rating-wrap { display:grid; grid-template-columns:1fr 1fr 1fr; gap:10px; }
        .pf-rpn-note { font-size:11px; color:#8a6d45; margin-top:4px; }
        /* 可增列分析表(超寬，橫向捲動) */
        table.pf-item { border-collapse:collapse; font-size:11px; min-width:2400px; }
        table.pf-item th, table.pf-item td { border:1px solid #EADFC8; padding:3px 4px; vertical-align:top; }
        table.pf-item thead th { background:#F7E0BD; color:#5b3a1e; white-space:nowrap; }
        table.pf-item thead tr.grp th { background:#EFD9A8; }
        table.pf-item input[type=text], table.pf-item input[type=date], table.pf-item input[type=number], table.pf-item textarea {
            width:100%; box-sizing:border-box; border:1px solid #D8BE93; border-radius:3px; padding:3px 4px; font-size:11px; }
        table.pf-item textarea { min-height:32px; resize:vertical; }
        table.pf-item input.rating { width:44px; text-align:center; }
        table.pf-item input.rpn-out { width:50px; text-align:center; background:#F7F2E6; font-weight:bold; color:#8A5A2B; }
        table.pf-item td.seq { width:28px; text-align:center; color:#8a6d45; }
        table.pf-item .rpn-hi { color:#DD5138; font-weight:bold; }
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
            <button id="btnAsDoc" style="<?= $perms['canAdmin']?'':'display:none;' ?>"><i class="fa fa-link"></i> AS文件綁定</button>
            <button id="btnCsv"><i class="fa fa-file-text-o"></i> 匯出CSV</button>
            <span class="pf-role-badge">目前角色：<b><?= htmlspecialchars($roleLabel) ?></b>
                <i class="fa fa-question-circle" id="btnRoleHelp" title="角色權限說明"></i></span>
        </div>

        <div class="pf-table-wrap">
            <table class="pf-table" id="pfTable">
                <thead><tr>
                    <th>表單編號</th><th>產品件號</th><th>工作團隊</th><th>項目數</th><th>最高RPN</th>
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
<div class="pf-mask" id="editMask"><div class="pf-modal xwide">
    <div class="m-head"><span id="editTitle">PFMEA潛在失效模式及效應分析</span><span class="m-close" onclick="closeMask('editMask')">✕</span></div>
    <div class="m-body">
        <div class="pf-head-grid">
            <div>
                <label>產品件號(料號)</label>
                <input type="text" id="fPartNo" placeholder="輸入部分料號或圖號搜尋；查無時可直接手動輸入" autocomplete="off">
                <input type="hidden" id="fPartDId" value="0">
            </div>
            <div>
                <label>工作團隊 Team of Work</label>
                <input type="text" id="fTeam" placeholder="參與分析的人員/單位，以頓號分隔">
            </div>
        </div>
        <div style="margin-top:6px;font-size:12px;color:#8a6d45;">表單編號：<b id="fDocNo">存檔後自動產生</b>
            ｜ 建立：<span id="fCreatedInfo">—</span></div>

        <div class="pf-sec-title pf-collapsible" onclick="toggleRatingRef()">
            <i class="fa fa-chevron-right" id="ratingToggleIcon"></i> 評級對照表（固定參考，不隨本表個別修改，點擊展開/收合）
        </div>
        <div id="ratingRefBox" style="display:none;">
        <div class="pf-rating-wrap">
            <table class="pf-rating"><thead><tr><th colspan="2">嚴重度 Severity (S)</th></tr></thead><tbody>
                <tr><td class="lv">1</td><td>無影響</td></tr>
                <tr><td class="lv">2-3</td><td>輕微影響，客戶幾乎不會注意到</td></tr>
                <tr><td class="lv">4-6</td><td>中等影響，客戶會感到不滿意</td></tr>
                <tr><td class="lv">7-8</td><td>嚴重影響，主要功能喪失但不涉及安全</td></tr>
                <tr><td class="lv">9-10</td><td>非常嚴重，涉及安全或不符合法規</td></tr>
            </tbody></table>
            <table class="pf-rating"><thead><tr><th colspan="2">發生度 Occurrence (O)</th></tr></thead><tbody>
                <tr><td class="lv">1</td><td>極少發生</td></tr>
                <tr><td class="lv">2-3</td><td>少發生</td></tr>
                <tr><td class="lv">4-6</td><td>偶爾發生</td></tr>
                <tr><td class="lv">7-8</td><td>經常發生</td></tr>
                <tr><td class="lv">9-10</td><td>幾乎必然發生</td></tr>
            </tbody></table>
            <table class="pf-rating"><thead><tr><th colspan="2">偵測度 Detection (D)</th></tr></thead><tbody>
                <tr><td class="lv">1</td><td>幾乎確定可偵測</td></tr>
                <tr><td class="lv">2-3</td><td>高度可能偵測</td></tr>
                <tr><td class="lv">4-6</td><td>中度可能偵測</td></tr>
                <tr><td class="lv">7-8</td><td>低度可能偵測</td></tr>
                <tr><td class="lv">9-10</td><td>幾乎不可能偵測</td></tr>
            </tbody></table>
        </div>
        </div>
        <div class="pf-rpn-note">風險優先指數 RPN = S × O × D（系統自動計算，不可手填）：<b>&lt;50</b> 低風險｜<b>50-100</b> 中風險｜<b>101-200</b> 高風險｜<b>&gt;200</b> 極高風險，需優先改善。</div>

        <div class="pf-sec-title">失效模式分析（可拖曳橫向捲動查看全部欄位）</div>
        <div class="pf-table-wrap">
        <table class="pf-item">
            <thead>
                <tr class="grp"><th rowspan="2">項次</th><th colspan="6">初步分析</th><th colspan="6">評級</th><th colspan="3">改善措施</th><th colspan="6">改善後結果</th><th rowspan="2">操作</th></tr>
                <tr>
                    <th style="min-width:110px;">製程說明</th><th style="min-width:110px;">功能</th><th style="min-width:110px;">要求</th>
                    <th style="min-width:130px;">潛在失效模式</th><th style="min-width:130px;">潛在失效效應</th><th style="min-width:110px;">分類</th>
                    <th style="width:44px;">S</th><th style="min-width:130px;">潛在失效原因</th><th style="width:44px;">O</th>
                    <th style="min-width:130px;">現行製程管制</th><th style="width:44px;">D</th><th style="width:56px;">RPN</th>
                    <th style="min-width:130px;">建議改善措施</th><th style="min-width:80px;">責任者</th><th style="width:100px;">目標完成日</th>
                    <th style="min-width:130px;">已採取措施</th><th style="width:100px;">生效日</th>
                    <th style="width:44px;">S</th><th style="width:44px;">O</th><th style="width:44px;">D</th><th style="width:56px;">RPN</th>
                    <th style="min-width:110px;">預防管制</th><th style="min-width:110px;">偵測管制</th>
                </tr>
            </thead>
            <tbody id="itemBody" data-eg-row-add="pfAddRow" data-eg-row-del="pfDelRow"></tbody>
        </table>
        </div>
        <div style="margin-top:6px;">
            <button type="button" class="pf-row-btn" onclick="pfAddRow()"><i class="fa fa-plus"></i> 新增一列</button>
        </div>
    </div>
    <div class="m-foot">
        <button class="b-cancel" onclick="closeMask('editMask')">取消</button>
        <button class="b-ok" id="btnSave" onclick="saveHeader()">儲存</button>
    </div>
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
        <p>製程潛在失效模式及效應分析（PFMEA，AS 3-TD-01-02），每個料號一份分析表，逐列記錄一個潛在失效模式：從製程說明、功能、失效模式、失效效應、原因、現行管制，評出嚴重度(S)/發生度(O)/偵測度(D)，系統自動算出風險優先指數 RPN=S×O×D；針對高 RPN 項目填建議改善措施，改善後再評一次新的 S/O/D/RPN。</p>
        <h4>操作步驟</h4>
        <ul>
            <li>按「新增」→ 選擇「產品件號(料號)」（打部分字元搜尋，查無此料號時可直接手動輸入）、填「工作團隊」。</li>
            <li>逐列填分析內容，S/O/D 每格填 1-10，<b>RPN 由系統自動計算，不可手動輸入</b>；末列填寫後按 ↓ 或「新增一列」可再加一列。</li>
            <li>分析表欄位很多，用滑鼠或觸控在表格上左右拖曳即可看到全部欄位（製程說明→評級→改善措施→改善後結果）。</li>
        </ul>
        <h4>其他行為／常見疑問</h4>
        <ul>
            <li>「評級對照表」為固定的評分基準參考，不隨每份分析表個別修改；預設收合以節省畫面空間，點擊標題列可展開/收合；若需要調整用詞請直接告知管理員修改頁面內容。</li>
            <li>產品件號可點擊開啟圖面查閱（比照報價單頁做法）。</li>
            <li>列印比照全站標準（ai-rules/16）：大標題為本公司名稱、頁尾右下角印本頁綁定的 AS 文件編號。</li>
            <li>本表單自身的修訂履歷（版次、修訂內容、核准/查證/制定）由 AS 文件管理維護，不在本頁另外記錄。</li>
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
var CUR_ID = 0, AS_DOCS = [], AS_DOC = null;
var FIELDS = ['process_desc','function_desc','requirement','failure_mode','failure_effect','classification',
    'severity','failure_cause','occurrence','current_controls','detection',
    'recommended_actions','responsibility','target_date','action_taken','action_date',
    'new_severity','new_occurrence','new_detection','prevention_controls','detection_controls'];

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
                + '<td class="t-left">'+esc(r.team_of_work||'')+'</td>'
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
        var lines = ['表單編號,產品件號,工作團隊,項目數,最高RPN,建立人,建立時間'];
        res.rows.forEach(function(r){
            lines.push([r.doc_no, r.part_no||'', r.team_of_work||'', r.item_count, r.max_rpn!=null?r.max_rpn:'', r.created_by_name||'', (r.created_at||'').substring(0,10)]
                .map(function(v){ return '"'+String(v).replace(/"/g,'""')+'"'; }).join(','));
        });
        var blob = new Blob(["\uFEFF"+lines.join("\n")], {type:'text/csv;charset=utf-8;'});
        var a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = 'PFMEA潛在失效模式及效應分析.csv';
        document.body.appendChild(a); a.click(); document.body.removeChild(a);
    });
});

/* ---------- 新增/編輯 ---------- */
function itemRowHtml(it, idx){
    it = it || {};
    function inp(field, type, extraCls){
        var v = it[field] != null ? it[field] : '';
        if (type === 'rating') {
            return '<input type="number" min="1" max="10" class="rating sod-in" data-f="'+field+'" value="'+esc(v)+'"'+(CAN_EDIT?'':' disabled')+'>';
        }
        if (type === 'date') return '<input type="date" data-f="'+field+'" value="'+esc(v)+'"'+(CAN_EDIT?'':' disabled')+'>';
        return '<textarea data-f="'+field+'" '+(CAN_EDIT?'':'disabled')+'>'+esc(v)+'</textarea>';
    }
    var rpn = it.rpn != null ? it.rpn : '';
    var newRpn = it.new_rpn != null ? it.new_rpn : '';
    var rpnCls = (it.rpn != null && it.rpn > 200) ? ' rpn-hi' : '';
    var newRpnCls = (it.new_rpn != null && it.new_rpn > 200) ? ' rpn-hi' : '';
    return '<tr data-id="'+esc(it.id||0)+'">'
        + '<td class="seq">'+(idx+1)+'</td>'
        + '<td>'+inp('process_desc','text')+'</td>'
        + '<td>'+inp('function_desc','text')+'</td>'
        + '<td>'+inp('requirement','text')+'</td>'
        + '<td>'+inp('failure_mode','text')+'</td>'
        + '<td>'+inp('failure_effect','text')+'</td>'
        + '<td>'+inp('classification','text')+'</td>'
        + '<td>'+inp('severity','rating')+'</td>'
        + '<td>'+inp('failure_cause','text')+'</td>'
        + '<td>'+inp('occurrence','rating')+'</td>'
        + '<td>'+inp('current_controls','text')+'</td>'
        + '<td>'+inp('detection','rating')+'</td>'
        + '<td><input type="text" class="rpn-out'+rpnCls+'" data-rpn value="'+rpn+'" readonly></td>'
        + '<td>'+inp('recommended_actions','text')+'</td>'
        + '<td>'+inp('responsibility','text')+'</td>'
        + '<td>'+inp('target_date','date')+'</td>'
        + '<td>'+inp('action_taken','text')+'</td>'
        + '<td>'+inp('action_date','date')+'</td>'
        + '<td>'+inp('new_severity','rating')+'</td>'
        + '<td>'+inp('new_occurrence','rating')+'</td>'
        + '<td>'+inp('new_detection','rating')+'</td>'
        + '<td><input type="text" class="rpn-out'+newRpnCls+'" data-new-rpn value="'+newRpn+'" readonly></td>'
        + '<td>'+inp('prevention_controls','text')+'</td>'
        + '<td>'+inp('detection_controls','text')+'</td>'
        + '<td class="op"><button type="button" class="pf-row-btn del" onclick="$(this).closest(\'tr\').remove(); renumberRows();">刪除</button></td>'
        + '</tr>';
}
function renderItems(items){
    var html = '';
    (items||[]).forEach(function(it, idx){ html += itemRowHtml(it, idx); });
    $('#itemBody').html(html);
    if (!items || !items.length) pfAddRow();
}
function renumberRows(){ $('#itemBody tr').each(function(i){ $(this).find('td.seq').text(i+1); }); }
window.toggleRatingRef = function(){
    var box = document.getElementById('ratingRefBox');
    var show = box.style.display === 'none';
    box.style.display = show ? 'block' : 'none';
    document.getElementById('ratingToggleIcon').className = 'fa fa-chevron-' + (show ? 'down' : 'right');
};
window.pfAddRow = function(){
    $('#itemBody').append(itemRowHtml({}, $('#itemBody tr').length));
    renumberRows();
    return true;
};
window.pfDelRow = function(){
    var rows = $('#itemBody tr');
    if (rows.length <= 1) return false;
    rows.last().remove();
    renumberRows();
    return true;
};
/* RPN 即時重算(僅顯示用，實際以送出後後端重算為準) */
$(document).on('input', '#itemBody .sod-in', function(){
    var $tr = $(this).closest('tr');
    var s = parseInt($tr.find('[data-f="severity"]').val(), 10);
    var o = parseInt($tr.find('[data-f="occurrence"]').val(), 10);
    var d = parseInt($tr.find('[data-f="detection"]').val(), 10);
    var rpn = (s && o && d) ? s*o*d : '';
    $tr.find('[data-rpn]').val(rpn).toggleClass('rpn-hi', rpn !== '' && rpn > 200);
    var ns = parseInt($tr.find('[data-f="new_severity"]').val(), 10);
    var no = parseInt($tr.find('[data-f="new_occurrence"]').val(), 10);
    var nd = parseInt($tr.find('[data-f="new_detection"]').val(), 10);
    var newRpn = (ns && no && nd) ? ns*no*nd : '';
    $tr.find('[data-new-rpn]').val(newRpn).toggleClass('rpn-hi', newRpn !== '' && newRpn > 200);
});

function collectItems(){
    var out = [];
    $('#itemBody tr').each(function(){
        var $tr = $(this);
        var row = {id: parseInt($tr.attr('data-id'),10) || 0};
        FIELDS.forEach(function(f){ row[f] = $tr.find('[data-f="'+f+'"]').val(); });
        out.push(row);
    });
    return out;
}

function resetEditForm(){
    CUR_ID = 0;
    $('#fPartNo').val(''); $('#fPartDId').val('0'); $('#fTeam').val('');
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
        $('#fTeam').val(res.doc.team_of_work||'');
        $('#fDocNo').text(res.doc.doc_no);
        $('#fCreatedInfo').text((res.doc.created_by_name||'')+' '+fmtDate((res.doc.created_at||'').substring(0,10)));
        renderItems(res.items || []);
        openMask('editMask');
    });
}
$('#btnAdd').on('click', function(){ openEdit(0); });

EGPartPicker.attach(document.getElementById('fPartNo'), {
    apiUrl: PART_API,
    onSelect: function(row){ $('#fPartDId').val(row.d_id); }
});
$('#fPartNo').on('input', function(){ $('#fPartDId').val('0'); });

function saveHeader(){
    var payload = {
        action: 'save', id: CUR_ID,
        part_d_id: $('#fPartDId').val() || 0,
        part_no_text: (($('#fPartDId').val()|0) ? '' : $('#fPartNo').val()),
        team_of_work: $('#fTeam').val(),
        items: JSON.stringify(collectItems()),
    };
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

/* ---------- 列印 ---------- */
function printDoc(id){
    $.getJSON(API, {action:'print_get', id:id}, function(res){
        if (!res.success){ alert(res.message||'載入失敗'); return; }
        var d = res.doc;
        var rows = '';
        (res.items||[]).forEach(function(it, i){
            rows += '<tr><td>'+(i+1)+'</td><td class="tl">'+esc(it.process_desc)+'</td><td class="tl">'+esc(it.function_desc)+'</td>'
                + '<td class="tl">'+esc(it.failure_mode)+'</td><td class="tl">'+esc(it.failure_effect)+'</td>'
                + '<td>'+esc(it.severity)+'</td><td class="tl">'+esc(it.failure_cause)+'</td><td>'+esc(it.occurrence)+'</td>'
                + '<td class="tl">'+esc(it.current_controls)+'</td><td>'+esc(it.detection)+'</td><td>'+esc(it.rpn)+'</td>'
                + '<td class="tl">'+esc(it.recommended_actions)+'</td><td>'+esc(it.responsibility)+'</td>'
                + '<td>'+esc(it.new_severity)+'/'+esc(it.new_occurrence)+'/'+esc(it.new_detection)+'</td><td>'+esc(it.new_rpn)+'</td></tr>';
        });
        var body = '<div class="p-comp">'+esc(res.company_name)+'</div>'
            + '<div class="p-title">'+esc(res.as_doc_name)+'</div>'
            + '<table class="p-hd"><tr><td>產品件號</td><td>'+esc(d.part_no||'')+'</td><td>工作團隊</td><td>'+esc(d.team_of_work||'')+'</td></tr></table>'
            + '<table class="p-tb"><thead><tr><th>項次</th><th>製程說明</th><th>功能</th><th>失效模式</th><th>失效效應</th>'
            + '<th>S</th><th>失效原因</th><th>O</th><th>現行管制</th><th>D</th><th>RPN</th>'
            + '<th>建議改善</th><th>責任者</th><th>改善後S/O/D</th><th>改善後RPN</th></tr></thead><tbody>'+rows+'</tbody></table>';
        var css = 'body{font-family:"Microsoft JhengHei",sans-serif;margin:0;padding:0 6mm;color:#222;-webkit-print-color-adjust:exact;print-color-adjust:exact;}'
            + '.p-comp{font-size:20px;font-weight:bold;text-align:center;margin-bottom:1px;}'
            + '.p-title{font-size:15px;font-weight:bold;text-align:center;letter-spacing:3px;margin-bottom:8px;}'
            + 'table.p-hd{width:100%;border-collapse:collapse;font-size:11px;margin-bottom:6px;}'
            + 'table.p-hd td{border:1px solid #666;padding:3px 5px;} table.p-hd td:nth-child(odd){background:#f3ead6;font-weight:bold;white-space:nowrap;}'
            + 'table.p-tb{width:100%;border-collapse:collapse;font-size:9.5px;}'
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
<?php endif; ?>
</script>
</body>
</html>
