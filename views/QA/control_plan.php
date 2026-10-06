<?php
/**
 * 管制計畫 CP（Control Plan）—— 2026-10-02 使用者交辦
 * ══════════════════════════════════════════════════════════════════════════════
 * 使用者的問題：「AS 航太認證內的 CP 管制計畫（老師說類似 QC 工程圖），該放哪個頁面？
 *               資料可以從現有網頁資料自動轉成嗎？」
 *
 * 【查證結論：系統裡原本沒有 CP，而且名字最像的那份不是 CP】
 * 2-QA-01-02「製程管制卡」解開紙本檔後確認是「隨貨流動的現場判定吊卡」
 * （件號/數量/箱種/品管合格判定/退修報廢特採），和 2-QA-01-08 首末吊卡同一類，不是 AIAG 的 CP。
 * 164 份 AS 文件裡沒有任何一份是管制計畫，故新建本模組。
 *
 * 【階段：AIAG 完整三段，管理員可停用（使用者 2026-10-02 拍板）】
 * AIAG 的三段不是「一張表三欄並排」而是「一張 CP 只屬於一個階段」，表頭勾一個。
 * 本公司實務上只有「訂單首件＝試作」與「首件過了＝生產」兩段（線上檢驗的 insp_kind
 * 早就只有 FIRST/NORMAL 兩種值），所以「試產」那一段若不使用可在設定停用，
 * 不會在表上留一整欄空白讓稽核追問。
 *
 * 【製程列以 BOM 為主（使用者 2026-10-02 補充定調）】
 * 「料號要認定設定為 AS 標籤所綁定的 BOM 製程，不是隨便找料號底下的一張 BOM」
 * 所以入口是「訂單」：管理員設定哪些訂單標籤代表 AS 認證 → 該訂單綁的製令 → 那張製令的製程鏈。
 * PFMEA 有評估、但這張製令沒走的製程只提示不自動加入。
 *
 * 判定規則一律在 src/common/control_plan_lib.php（唯一實作），本頁只負責畫面。
 */
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/QA/control_plan.php";
    header("Location:../../index.php");
    exit;
}
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/control_plan_lib.php';

$db = (new DBConnection())->getPDO();
cp_ensure_schema($db);
$P = cp_perms($db);
if (empty($_SESSION['cp_csrf'])) $_SESSION['cp_csrf'] = bin2hex(random_bytes(16));

// 本公司全名（列印大標題用，禁寫死＝ai-rules/16）
$companyName = '';
try {
    $companyName = (string)($db->query("SELECT customer_full FROM customer_list WHERE is_own_company=1 LIMIT 1")->fetchColumn() ?: '');
} catch (Throwable $e) {}

$asMeta = cp_print_meta($db);
$tagInfo = cp_order_tag_status($db);

$roleLabel = $P['admin'] ? '管制計畫管理員' : ($P['approve'] ? '可核准' : ($P['edit'] ? '可建立修改' : ($P['view'] ? '檢閱' : '無權限')));
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>管制計畫 CP</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <style>
        #sidebar-menu { visibility: hidden; }
        /* Gentelella 的 .top_nav 高度為 0 且浮動溢出，right_col 第一個子元素要 clear:both
           否則自成 BFC 時會被壓成寬度 0（標題整條消失）——CLAUDE.md 鐵律6 */
        .right_col .page-title { margin:8px 0 4px; overflow:hidden; clear:both; }
        .page-help-btn { height:30px; font-size:13px; padding:0 12px; border:1px solid #d98a33; border-radius:15px;
            background:#F0A24B; color:#fff; cursor:pointer; }
        .page-help-btn:hover { background:#d98a33; }
        @media print { .page-help-btn, .cp-tabs, .cp-toolbar { display:none !important; } }
        .help-doc { font-size:13px; color:#5b3a1e; line-height:1.75; }
        .help-doc h4 { color:#8A5A2B; border-bottom:2px solid #F7E0BD; padding-bottom:3px; margin:14px 0 6px; font-size:15px; }
        .help-doc h4:first-child { margin-top:0; }
        .help-doc b { color:#8A5A2B; }
        .help-doc ul { margin:4px 0 8px; padding-left:20px; }

        /* ── 配色一律暖色系（ai-rules/10）；綠/紅只用在語意（通過/需處理）── */
        .cp-tabs { display:flex; gap:4px; border-bottom:2px solid #F7E0BD; margin:6px 0 10px; clear:both; flex-wrap:wrap; }
        .cp-tab { padding:7px 16px; font-size:14px; cursor:pointer; border:1px solid #e6d5bb; border-bottom:none;
            border-radius:6px 6px 0 0; background:#FDF6EC; color:#8A5A2B; margin-bottom:-2px; }
        .cp-tab.on { background:#F0A24B; color:#fff; border-color:#d98a33; font-weight:bold; }
        .cp-pane { display:none; }
        .cp-pane.on { display:block; }

        .cp-toolbar { display:flex; flex-wrap:wrap; gap:6px; align-items:center; margin-bottom:8px; }
        .cp-toolbar input[type=text], .cp-toolbar select, .cp-toolbar input[type=date] {
            height:30px; font-size:13px; padding:2px 8px; border:1px solid #d8c3a0; border-radius:4px; }
        .btn-w { height:30px; font-size:13px; padding:0 12px; border:1px solid #d98a33; border-radius:4px;
            background:#F0A24B; color:#fff; cursor:pointer; }
        .btn-w:hover { background:#d98a33; }
        .btn-w2 { height:30px; font-size:13px; padding:0 12px; border:1px solid #d8c3a0; border-radius:4px;
            background:#FDF6EC; color:#8A5A2B; cursor:pointer; }
        .btn-w2:hover { background:#F7E0BD; }
        .btn-w[disabled], .btn-w2[disabled] { opacity:.5; cursor:not-allowed; }
        .btn-xs2 { height:24px; font-size:12px; padding:0 8px; line-height:22px; }

        .cp-note { font-size:12.5px; color:#6b4a28; background:#FDF6EC; border:1px solid #e6d5bb;
            border-left:4px solid #F0A24B; border-radius:4px; padding:7px 10px; margin-bottom:8px; line-height:1.7; }
        .cp-note.warn { background:#FBE3DD; border-color:#DD5138; border-left-color:#DD5138; color:#8c2d18; }

        table.cp-t { width:100%; border-collapse:collapse; font-size:13px; }
        table.cp-t th, table.cp-t td { border:1px solid #e6d5bb; padding:5px 6px; vertical-align:top; }
        table.cp-t thead th { background:#F7E0BD; color:#6b4a28; text-align:center; white-space:nowrap; }
        table.cp-t tbody tr:nth-child(even) { background:#FDFAF4; }
        table.cp-t tbody tr:hover { background:#FDF6EC; }

        /* 狀態與階段籤：一律自己指定 line-height，否則繼承 Gentelella 全站 td span{line-height:28px}
           把 10px 的字撐成 28px 高、整列變高（記憶 td_span_line_height_trap，已踩三次） */
        .tag-s { display:inline-block; font-size:11px; line-height:16px; padding:1px 7px; border-radius:9px;
            border:1px solid transparent; white-space:nowrap; }
        .st-draft { background:#eee; color:#666; border-color:#ddd; }
        .st-submitted { background:#F0A24B; color:#fff; border-color:#d98a33; }
        .st-approved { background:#DFF0D8; color:#3c763d; border-color:#c3e0b0; }
        .sg-1 { background:#F7E0BD; color:#6b4a28; border-color:#e0c79a; }
        .sg-2 { background:#E8B977; color:#4a2f14; border-color:#d3a25c; }
        .sg-3 { background:#F0A24B; color:#fff; border-color:#d98a33; }
        .sg-x { background:#f3f3f3; color:#999; border-color:#e3e3e3; }

        /* 特性列表格欄位多：只讓它自己橫向捲，頁面本身不可橫捲（UI 規範） */
        .cp-scroll { overflow-x:auto; border:1px solid #e6d5bb; border-radius:4px; background:#fff; }
        .cp-scroll table { min-width:1180px; margin:0; border:none; }

        .proc-box { border:1px solid #e6d5bb; border-radius:6px; margin-bottom:10px; background:#fff; }
        .proc-head { display:flex; flex-wrap:wrap; gap:6px; align-items:center; padding:7px 10px;
            background:#F7E0BD; border-bottom:1px solid #e6d5bb; border-radius:6px 6px 0 0; }
        .proc-head .pname { font-weight:bold; color:#6b4a28; font-size:14px; }
        .proc-body { padding:8px 10px; }
        .proc-f { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:7px; }
        .proc-f label { font-size:12px; color:#8A5A2B; display:block; margin:0 0 2px; }
        .proc-f input { height:28px; font-size:13px; padding:2px 7px; border:1px solid #d8c3a0; border-radius:4px; }

        .hd-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(210px,1fr)); gap:8px 12px;
            background:#FDF6EC; border:1px solid #e6d5bb; border-radius:6px; padding:10px 12px; margin-bottom:10px; }
        .hd-grid label { font-size:12px; color:#8A5A2B; display:block; margin:0 0 2px; }
        .hd-grid input, .hd-grid select, .hd-grid textarea {
            width:100%; height:30px; font-size:13px; padding:2px 7px; border:1px solid #d8c3a0; border-radius:4px; }
        .hd-grid textarea { height:52px; }
        .hd-grid .ro { background:#f6f1e7; color:#6b4a28; }
        .fld-err { border-color:#DD5138 !important; background:#FBE3DD; }
        .err-msg { color:#8c2d18; font-size:12px; margin-top:2px; }

        .m-mask { display:none; position:fixed; left:0; top:0; right:0; bottom:0; background:rgba(0,0,0,.45); z-index:9000; }
        .m-mask.on { display:block; }
        .m-win { position:absolute; left:50%; top:30px; transform:translateX(-50%); background:#fff;
            border-radius:8px; box-shadow:0 8px 30px rgba(0,0,0,.3); max-height:calc(100vh - 60px); display:flex; flex-direction:column; }
        .m-hd { padding:10px 14px; border-bottom:1px solid #e6d5bb; background:#F7E0BD; border-radius:8px 8px 0 0;
            font-weight:bold; color:#6b4a28; display:flex; align-items:center; }
        .m-bd { padding:12px 14px; overflow:auto; flex:1 1 auto; }
        .m-ft { padding:9px 14px; border-top:1px solid #e6d5bb; background:#FDF6EC; border-radius:0 0 8px 8px; text-align:right; }
        .m-x { margin-left:auto; cursor:pointer; color:#8A5A2B; font-size:18px; background:none; border:none; }

        .ac-box { position:relative; }
        .ac-list { position:fixed; z-index:9500; background:#fff; border:1px solid #d8c3a0; border-radius:4px;
            max-height:260px; overflow:auto; box-shadow:0 4px 14px rgba(0,0,0,.18); display:none; min-width:260px; }
        .ac-list.on { display:block; }
        .ac-item { padding:5px 9px; font-size:13px; cursor:pointer; border-bottom:1px solid #f3ead9; }
        .ac-item:hover, .ac-item.sel { background:#F7E0BD; }
        .ac-item small { color:#8a6d45; }

        .pg { display:flex; gap:4px; align-items:center; justify-content:flex-end; margin-bottom:6px; flex-wrap:wrap; }
        .pg button { height:26px; min-width:28px; font-size:12px; border:1px solid #d8c3a0; background:#FDF6EC;
            color:#8A5A2B; border-radius:3px; cursor:pointer; }
        .pg button.on { background:#F0A24B; color:#fff; border-color:#d98a33; }
        .muted { color:#8a6d45; font-size:12px; }
        .ro-auto { background:#f6f1e7; }
        .src-tag { display:inline-block; font-size:10px; line-height:14px; padding:0 5px; border-radius:3px;
            background:#F7E0BD; color:#6b4a28; margin-left:4px; }
    </style>
</head>
<body class="nav-sm">
<div class="container body">
<div class="main_container">
    <?php include '../partPage/sideAndTopBarMenu.html' ?>
    <div class="right_col" role="main">
        <div class="page-title" style="display:flex;align-items:center;flex-wrap:wrap;">
            <h2 style="margin:6px 0;">管制計畫 CP
                <small style="color:#8a6d45;">Control Plan／類似 QC 工程圖：製程 → 特性 → 規格 → 量測 → 頻率 → 管制方法 → 反應計畫</small></h2>
            <span class="muted" style="margin-left:14px;">目前權限：<?= htmlspecialchars($roleLabel) ?></span>
            <button id="btnPageHelp" class="page-help-btn" style="margin-left:auto;"><i class="fa fa-question-circle"></i> 使用說明</button>
        </div>
        <div class="clearfix"></div>

<?php if (!$P['view']): ?>
        <div class="cp-note warn">
            您沒有管制計畫的檢視權限。請管理員在「使用者權限設定」指派「管制計畫」的角色
            （檢閱 <code>cp_view</code>／建立修改 <code>cp_edit</code>／核准 <code>cp_approve</code>／管理員 <code>cp_admin</code>）。
            品管部門人員自動具備檢視與建立修改權限。
        </div>
<?php else: ?>

        <div class="cp-tabs">
            <div class="cp-tab on" data-pane="list"><i class="fa fa-list"></i> 管制計畫清單</div>
            <div class="cp-tab" data-pane="edit"><i class="fa fa-pencil-square-o"></i> 編輯</div>
            <div class="cp-tab" data-pane="auto"><i class="fa fa-magic"></i> 自動帶入／建議建立</div>
<?php if ($P['admin']): ?>
            <div class="cp-tab" data-pane="cfg"><i class="fa fa-cog"></i> 設定</div>
<?php endif; ?>
        </div>

        <!-- ══════════ 分頁一：清單 ══════════ -->
        <div class="cp-pane on" id="pane-list">
            <div class="cp-toolbar">
                <select id="fStage" data-eg-filter="輸入階段名稱篩選…"><option value="">全部階段</option></select>
                <select id="fStatus">
                    <option value="">全部狀態</option>
                    <option value="draft">草稿</option>
                    <option value="submitted">待核准</option>
                    <option value="approved">已核准</option>
                </select>
                <input type="text" id="fKw" placeholder="編號／料號／品名／客戶／訂單／製令" style="min-width:260px;">
                <select id="fPer"><option>5</option><option selected>10</option><option>20</option><option>50</option></select>
                <span class="muted">筆/頁</span>
<?php if ($P['edit']): ?>
                <button class="btn-w" id="btnNew" style="margin-left:auto;"><i class="fa fa-plus"></i> 建立管制計畫</button>
<?php endif; ?>
            </div>
            <div class="cp-note" id="listNote" style="display:none;"></div>
            <div class="pg" id="pgList"></div>
            <div class="cp-scroll">
                <table class="cp-t" id="tList">
                    <thead><tr>
                        <th style="width:140px;">CP 編號</th><th style="width:88px;">階段</th>
                        <th style="width:150px;">料號／產品族</th><th>品名</th>
                        <th style="width:90px;">客戶</th><th style="width:52px;">版次</th>
                        <th style="width:96px;">表單日期</th><th style="width:74px;">狀態</th>
                        <th style="width:120px;">來源</th><th style="width:70px;">製程/特性</th>
                        <th style="width:210px;">操作</th>
                    </tr></thead>
                    <tbody><tr><td colspan="11" style="text-align:center;color:#8a6d45;">載入中…</td></tr></tbody>
                </table>
            </div>
        </div>

        <!-- ══════════ 分頁二：編輯 ══════════ -->
        <div class="cp-pane" id="pane-edit">
            <div class="cp-note" id="editHint">請先到「管制計畫清單」按「建立管制計畫」，或到「自動帶入／建議建立」挑一張訂單自動帶入。</div>
            <div id="editWrap" style="display:none;">
                <div class="cp-toolbar">
                    <b id="edTitle" style="color:#8A5A2B;font-size:15px;"></b>
                    <span id="edTags"></span>
                    <button class="btn-w" id="btnSave" style="margin-left:auto;"><i class="fa fa-save"></i> 儲存</button>
                    <button class="btn-w2" id="btnSubmit"><i class="fa fa-paper-plane"></i> 送出核准</button>
                    <button class="btn-w2" id="btnApprove" style="display:none;"><i class="fa fa-check"></i> 核准</button>
                    <button class="btn-w2" id="btnReject" style="display:none;"><i class="fa fa-undo"></i> 退回</button>
                    <button class="btn-w2" id="btnRevise" style="display:none;"><i class="fa fa-code-fork"></i> 改版</button>
                    <button class="btn-w2" id="btnPrint"><i class="fa fa-print"></i> 列印</button>
                </div>
                <div id="edMsg"></div>

                <div class="hd-grid">
                    <div><label>階段 <span style="color:#DD5138;">*</span></label><select id="eStage"></select>
                        <div class="muted" id="eStageNote"></div></div>
                    <div><label>範圍</label><select id="eScope">
                        <option value="part">單一料號</option><option value="family">產品族（多個料號共用）</option></select></div>
                    <div id="wPart"><label>料號 <span style="color:#DD5138;">*</span></label>
                        <div class="ac-box"><input type="text" id="ePart" placeholder="打字搜尋料號或圖號" autocomplete="off"></div>
                        <input type="hidden" id="ePartId"><div class="muted" id="ePartInfo"></div></div>
                    <div id="wFamily" style="display:none;"><label>產品族名稱 <span style="color:#DD5138;">*</span></label>
                        <input type="text" id="eFamily" placeholder="例：SMA 系列齒輪"></div>
                    <div><label>品名</label><input type="text" id="eProdName"></div>
                    <div><label>客戶</label><input type="text" id="eCustName"><input type="hidden" id="eCustId"></div>
                    <div><label>圖面版次</label><input type="text" id="ePartRev"></div>
                    <div><label>CP 版次</label><input type="text" id="eVer" class="ro" readonly></div>
                    <div><label>表單日期 <span style="color:#DD5138;">*</span></label><input type="date" id="eFormDate">
                        <div class="muted">編號依這個日期產生（草稿階段改日期會重編）</div></div>
                    <div><label>來源訂單</label>
                        <div class="ac-box"><input type="text" id="eOrder" placeholder="打字搜尋訂單編號／料號／客戶" autocomplete="off"></div>
                        <input type="hidden" id="eOrderId"></div>
                    <div><label>製程來源製令</label><select id="eBom"></select>
                        <div class="muted" id="eBomInfo"></div></div>
                    <div><label>對應 PFMEA</label><input type="text" id="ePfmeaTxt" class="ro" readonly>
                        <input type="hidden" id="ePfmeaId"></div>
                    <div><label>核心小組</label><input type="text" id="eCoreTeam" placeholder="例：品管課 高志宏、技術課 何沐桐"></div>
                    <div><label>主要聯絡人／電話</label><input type="text" id="eKeyContact"></div>
                    <div><label>組織／工廠代碼</label><input type="text" id="eOrgCode"></div>
                    <div><label>客戶工程核准／日期</label><input type="text" id="eCustEng"></div>
                    <div><label>客戶品保核准／日期</label><input type="text" id="eCustQa"></div>
                    <div><label>其他核准</label><input type="text" id="eOtherAppr"></div>
                    <div style="grid-column:1/-1;"><label>備註</label><textarea id="eNote"></textarea></div>
                </div>

                <div id="wFamilyParts" style="display:none;" class="cp-note">
                    <b>本產品族涵蓋的料號</b>
                    <span class="muted">（這張 CP 對這些料號都適用；稽核會問「為什麼可以共用」，請在備註寫明製程與特性相同的理由）</span>
                    <div style="margin-top:6px;">
                        <div class="ac-box" style="display:inline-block;">
                            <input type="text" id="eFamAdd" placeholder="打字搜尋料號後點選加入" autocomplete="off" style="height:28px;min-width:260px;">
                        </div>
                        <div id="famList" style="margin-top:6px;"></div>
                    </div>
                </div>

                <div class="cp-toolbar">
                    <b style="color:#8A5A2B;">製程與管制特性</b>
                    <span class="muted">製程以 BOM 為主；每道製程下面是該製程要管制的特性列</span>
                    <button class="btn-w2 btn-xs2" id="btnAddProc" style="margin-left:auto;"><i class="fa fa-plus"></i> 新增製程</button>
                    <button class="btn-w2 btn-xs2" id="btnRefillFromSrc"><i class="fa fa-refresh"></i> 重新由來源帶入</button>
                    <button class="btn-w2 btn-xs2" id="btnRecalcInsp" title="依客供料自動判定／「設定」分頁的包裝代號／AS稽核製程規則，重新判定每一列的檢驗類別（只套在目前畫面，按儲存才會留下）"><i class="fa fa-stethoscope"></i> 重新判定檢驗類別</button>
                </div>
                <div id="procWrap"></div>
            </div>
        </div>

        <!-- ══════════ 分頁三：自動帶入／建議建立 ══════════ -->
        <div class="cp-pane" id="pane-auto">
            <div class="cp-note" id="autoNote"></div>
            <div class="cp-toolbar">
                <b style="color:#8A5A2B;">從訂單自動帶入</b>
                <div class="ac-box"><input type="text" id="aOrder" placeholder="打字搜尋訂單編號／料號／客戶" autocomplete="off" style="min-width:280px;"></div>
                <input type="hidden" id="aOrderId">
                <select id="aStage"></select>
                <select id="aBom" style="display:none;"></select>
                <button class="btn-w" id="btnPreview"><i class="fa fa-search"></i> 預覽帶入內容</button>
            </div>
            <div id="aTagBox" style="margin:-4px 0 8px;"></div>
            <div id="prevWrap"></div>

            <div class="cp-toolbar" style="margin-top:14px;border-top:2px solid #F7E0BD;padding-top:10px;">
                <b style="color:#8A5A2B;">建議建立清單</b>
                <span class="muted" id="sugMode"></span>
                <select id="sStage" style="margin-left:auto;"><option value="">不分階段（任一階段都沒有才列出）</option></select>
                <button class="btn-w2" id="btnSug"><i class="fa fa-refresh"></i> 重新整理</button>
                <button class="btn-w2" id="btnIgnoredList"><i class="fa fa-eye-slash"></i> 忽略名單</button>
            </div>
            <div class="cp-note" id="sugSummary" style="display:none;"></div>
            <div class="pg" id="pgSug" style="margin-bottom:4px;"></div>
            <div class="cp-scroll">
                <table class="cp-t" id="tSug" style="min-width:1120px;">
                    <thead><tr>
                        <th style="width:150px;">料號</th><th style="width:120px;">品名</th>
                        <th style="width:80px;">客戶</th><th style="width:50px;">版次</th>
                        <th style="width:110px;">稽核製程標籤</th>
                        <th style="width:120px;">最近訂單</th>
                        <th style="width:180px;">自動帶得出什麼</th>
                        <th style="width:110px;">已有的階段</th>
                        <th style="width:160px;">操作</th>
                    </tr></thead>
                    <tbody><tr><td colspan="9" style="text-align:center;color:#8a6d45;">按「重新整理」載入</td></tr></tbody>
                </table>
            </div>
        </div>

<?php if ($P['admin']): ?>
        <!-- ══════════ 分頁四：設定 ══════════ -->
        <div class="cp-pane" id="pane-cfg">
            <div class="cp-note">
                <b>階段</b>：AIAG 的三段都在這裡。本公司若沒有量產導入期，把「試產」的啟用取消即可
                ——停用之後新 CP 不能選它，<b>但已經建好的舊 CP 仍然看得到、印得出來</b>（不會變成空白階段）。
                每一段可以設「預設樣本／頻率」，自動帶入時會優先用它蓋掉 SIP 的頻率
                （例：試作段填「100% 全尺寸（首件）」，帶入時每一列的頻率就是這個）。
            </div>
            <div class="cp-scroll" style="margin-bottom:14px;">
                <table class="cp-t" id="tStage" style="min-width:860px;">
                    <thead><tr><th style="width:110px;">代碼</th><th style="width:170px;">顯示名稱</th>
                        <th style="width:200px;">預設樣本／頻率</th><th>說明</th>
                        <th style="width:66px;">排序</th><th style="width:60px;">啟用</th></tr></thead>
                    <tbody></tbody>
                </table>
            </div>
            <div style="margin-bottom:18px;"><button class="btn-w" id="btnSaveStage"><i class="fa fa-save"></i> 儲存階段設定</button></div>

            <div class="cp-note">
                <b>特殊特性分類</b>：CP 上那一欄的符號與名稱，<b>依 AS 文件 3-TD-01「失效模式及效應分析應用辦法」第5.14節</b>
                定為「關鍵特性 CC」與「重要特性 SC」（2026-10-02 經使用者指正修訂——原本用的是
                AIAG 通用符號 ◇▽☆，不是公司自己文件規定的符號）。
                <div style="margin-top:4px;">自動帶入時<b>直接依 PFMEA 失效模式的嚴重度／發生率數值</b>比對下面設的門檻判定，
                <b>不比對 PFMEA 填的文字</b>——PFMEA 自己的自動判定目前只有二分法（沒有 9~10 那一段），
                只比文字永遠配不到「關鍵特性」。嚴重度落在範圍內「或」發生率落在範圍內，任一成立即命中；
                同時命中多個分類時取排序在前（較嚴重）的那一個。門檻留空＝那個條件不比對。</div>
                <div style="margin-top:4px;"><b>已經被 CP 用到的分類不會被真的刪除，只會停用</b>——真刪掉舊 CP 那一欄會變空白。</div>
            </div>
            <div class="cp-scroll" style="margin-bottom:14px;">
                <table class="cp-t" id="tClass" style="min-width:980px;" data-eg-row-add="clsAdd" data-eg-row-del="clsDel">
                    <thead><tr><th style="width:70px;">符號</th><th style="width:130px;">名稱</th><th>說明</th>
                        <th style="width:150px;">嚴重度範圍</th><th style="width:150px;">發生率範圍</th>
                        <th style="width:66px;">排序</th><th style="width:60px;">啟用</th><th style="width:46px;"></th></tr></thead>
                    <tbody></tbody>
                </table>
            </div>
            <div style="margin-bottom:18px;">
                <button class="btn-w" id="btnSaveClass"><i class="fa fa-save"></i> 儲存特殊特性分類</button>
                <button class="btn-w2" id="btnAddClass"><i class="fa fa-plus"></i> 新增一列</button>
            </div>

            <div class="cp-note">
                <b>反應計畫常用語</b>：CP 最後一欄的下拉選項（現場發現不符合時要做什麼）。
                預設值取自 2-QA-01-02 製程管制卡紙本上的判定（退修／報廢／特採）與品質異常處理單的流程。
                <div style="margin-top:6px;"><b>「預設」那一欄只能勾一個</b>：自動帶入時每一列特性的反應計畫會先填它
                ——這一欄是稽核必看的，整欄空白一定被問；但它是業務判斷，所以建議勾最通用的
                「隔離標示，通知品管判定」，<b>不要勾退修／報廢／特採</b>（那是發生之後才決定的處置結論）。</div>
            </div>
            <div class="cp-scroll" style="margin-bottom:14px;">
                <table class="cp-t" id="tReact" style="min-width:680px;" data-eg-row-add="reactAdd" data-eg-row-del="reactDel">
                    <thead><tr><th>內容</th><th style="width:66px;">排序</th><th style="width:60px;">啟用</th>
                        <th style="width:60px;">預設</th><th style="width:46px;"></th></tr></thead>
                    <tbody></tbody>
                </table>
            </div>
            <div style="margin-bottom:18px;">
                <button class="btn-w" id="btnSaveReact"><i class="fa fa-save"></i> 儲存反應計畫選項</button>
                <button class="btn-w2" id="btnAddReact"><i class="fa fa-plus"></i> 新增一列</button>
            </div>

            <div class="cp-note">
                <b>管制方法快選庫</b>：自動帶入時依<b>每一列特性自己的名稱</b>（產品特性欄，不是看製程）
                比對「關鍵字」，命中就自動填入對應的管制方法；填寫畫面也有下拉可手動挑選，不必每次都打字。
                <div style="margin-top:6px;">「關鍵字」是<b>特性名稱裡有包含到就算命中</b>（例：關鍵字「外觀」會命中
                「外觀」「表面外觀」）；同時命中多筆關鍵字時，<b>取字數最長的那一筆</b>（較具體的優先，
                例：「表面外觀不良」會贏過「外觀」）。帶入後仍是人工可覆蓋的欄位。</div>
            </div>
            <div class="cp-scroll" style="margin-bottom:14px;">
                <table class="cp-t" id="tCtrlMethod" style="min-width:760px;" data-eg-row-add="ctrlMethodAdd" data-eg-row-del="ctrlMethodDel">
                    <thead><tr><th style="width:160px;">特性名稱關鍵字</th><th>預設管制方法</th>
                        <th style="width:66px;">排序</th><th style="width:60px;">啟用</th><th style="width:46px;"></th></tr></thead>
                    <tbody></tbody>
                </table>
            </div>
            <div style="margin-bottom:18px;">
                <button class="btn-w" id="btnSaveCtrlMethod"><i class="fa fa-save"></i> 儲存管制方法快選庫</button>
                <button class="btn-w2" id="btnAddCtrlMethod"><i class="fa fa-plus"></i> 新增一列</button>
            </div>

            <div class="cp-note">
                <b>檢驗類別自動判定（IQC／IPQC／FQC）</b>：自動帶入製程列時，依下面規則判定每一道製程後面
                接哪種檢驗。判定結果存進每一道製程列的「檢驗類別」欄位，<b>之後是人工可覆蓋的欄位</b>
                （編輯畫面逐列下拉可改，或按「重新判定檢驗類別」套用目前規則；但「重新判定」只重算分類，
                不會重新插入下面的 FQC 合成列，那個只在自動帶入預覽時處理一次）。
                <div style="margin-top:6px;">
                    <b>IQC（進料檢驗）</b>＝客供料的檢驗項目，<b>系統自動判定、不必設定</b>：
                    一條製程鏈裡最早出現的「客供料製程」（下方①目前認定清單）本身就是 IQC；查無 SIP 時
                    會退回「線上檢驗」自己的檢驗標準庫（SOP/SIP 都還沒建時至少看得到現場在用的標準）。<br>
                    <b>IPQC（製程檢驗）</b>＝SOP 中的檢驗項目，<b>預設都是 IPQC</b>（介於 IQC 與 FQC 之間的其他
                    製程維持 IPQC 不必另設）；若該製程查無已核准的 SOP，畫面會標出「缺SOP」提醒補建。<br>
                    <b>FQC（最終檢驗）</b>＝<b>只要鏈上有包裝，包裝前一道一律自動插入一道新的合成列</b>
                    （不是任何既有列被改判——例如齒研，齒研自己仍然是 IPQC，另外多一列「齒研（最終檢驗）」
                    ＝FQC），<b>不必登記任何製程代號</b>。內容依②門檻二選一：總製程數少時借用包裝前一道
                    自己的 SIP；製程數多時改用「出貨檢驗」內容（跨製程彙整，見下方②說明）。查無內容時
                    畫面會標出「缺SIP」或「查無出貨檢驗」提醒補建。<br>
                    <b>OQC（出貨檢驗）</b>＝包裝自主檢驗，走獨立的
                    <a href="../pm/packing_schedule.php" target="_blank">包裝排程模組</a>（自己有檢驗單號），
                    <b>本頁刻意不做 OQC 分類</b>，包裝那一列維持「非檢驗點」。
                </div>
            </div>
            <div style="margin-bottom:6px;"><b>目前認定的客供料製程（IQC）</b>
                <span class="muted">（ProcessNo=138 或製程名稱含「客供料」，與成本推算同一份判準；
                要調整請到主檔管理改製程名稱，這裡唯讀）</span></div>
            <div id="kgCodeList" style="margin-bottom:18px;min-height:26px;"></div>
            <div style="margin-bottom:6px;"><b>①目前認定的包裝製程</b>
                <span class="muted">（讀「<a href="../pm/packing_schedule.php" target="_blank">包裝排程</a>→
                包裝製程設定」的登記，與線上檢驗排除包裝站用的是同一份來源；要調整請到那一頁改，這裡唯讀）</span></div>
            <div id="packCodeList" style="margin-bottom:18px;min-height:26px;"></div>
            <div style="margin-bottom:6px;"><b>②FQC 內容切換門檻</b>
                <span class="muted">（插入前的總製程數——含客供料／包裝都算在內——
                <b>≤門檻</b>：借用包裝前一道製程自己的 SIP；<b>＞門檻</b>：改用該料號的
                「<a href="../QC/inspection_entry_v2.php" target="_blank">線上檢驗</a>→出貨檢驗」內容，
                那是跨製程彙整的成品確認、較適合工序較多的料號）</span></div>
            <div style="margin-bottom:18px;">
                <input type="number" id="fqcThreshold" min="1" style="width:70px;height:28px;font-size:13px;">
                <button class="btn-w2" id="btnSaveFqcThreshold"><i class="fa fa-save"></i> 儲存門檻</button>
            </div>

            <div class="cp-note <?= $tagInfo['ready'] ? '' : 'warn' ?>">
                <b>哪些訂單需要建管制計畫</b>
                <?php if ($tagInfo['ready']): ?>
                    ：依訂單追蹤的<b>「稽核製程」標籤</b>認定——訂單掛了稽核製程標籤
                    （例「單製齒研」「全製含插齒」）就需要管制計畫。
                    <b>預設全部稽核製程都要求</b>，不必在這裡做任何設定；
                    下面只是提供「某個稽核製程暫時不做 CP」時的排除選項。
                    <div style="margin-top:6px;">
                        目前認定結果：<b><?= (int)$tagInfo['n_tag'] ?></b> 個稽核製程、
                        <b><?= (int)$tagInfo['n_order'] ?></b> 張訂單、
                        <b><?= (int)$tagInfo['n_part'] ?></b> 個料號需要管制計畫。
                    </div>
                    <div style="margin-top:4px;">內建的「全製」「單製非AS認證」「廠內治具」與管理員自加的
                        「其他固定選項」（例「單/全製含齒研(不列AS認證)」）<b>都不算稽核製程，不需要 CP</b>。</div>
                <?php else: ?>
                    ：<?= htmlspecialchars($tagInfo['reason']) ?>
                    目前「建議建立清單」暫以<b>已建 PFMEA 的料號</b>為母體（那批本來就是客戶要求 APQP 的對象）。
                <?php endif; ?>
                <div style="margin-top:6px;">標籤定義本身<b>只能在訂單追蹤的「設定→稽核製程標籤」改</b>，
                    本模組只讀不寫（鐵律4：同一份定義不留第二份）。</div>
                <div style="margin-top:6px;"><b>「次站檢驗類別」</b>：這個稽核製程本身是否要指定「下一道製程」
                    固定接 IQC（不指定＝照上面①②的一般規則判，判不到就是 IPQC）。
                    這是管理員對該稽核製程的業務判斷，<b>優先序最高</b>——會覆蓋①②判出來的結果。
                    （FQC 已改成「包裝前一道自動插入」，不在這裡設定，見「檢驗類別自動判定」區塊）</div>
            </div>
            <div class="cp-scroll" style="margin-bottom:10px;">
                <table class="cp-t" id="tAsTag" style="min-width:940px;">
                    <thead><tr>
                        <th style="width:70px;">標籤 id</th><th style="width:130px;">稽核製程</th>
                        <th style="width:190px;">訂單上會長出的標籤</th>
                        <th style="width:90px;">掛了訂單</th><th style="width:80px;">涉及料號</th>
                        <th style="width:70px;">啟用</th><th style="width:150px;">要求建 CP</th>
                        <th style="width:140px;">次站檢驗類別</th>
                    </tr></thead>
                    <tbody><tr><td colspan="8" style="text-align:center;color:#8a6d45;">載入中…</td></tr></tbody>
                </table>
            </div>
            <div style="margin-bottom:18px;">
                <button class="btn-w" id="btnSaveTags" <?= $tagInfo['ready'] ? '' : 'disabled' ?>><i class="fa fa-save"></i> 儲存稽核製程設定</button>
                <span class="muted" style="margin-left:8px;">同時存「要求建CP」排除名單與「次站檢驗類別」；取消勾選「要求建CP」＝該稽核製程的訂單不再出現在建議建立清單</span>
            </div>

            <div class="cp-note">
                <b>AS 文件綁定</b>：決定列印版表頭的表單名稱與頁尾右下角的 AS 編號（版次依每張 CP 的表單日期回推＝ai-rules/16）。
                目前綁定：<b id="asNow"><?= $asMeta['as_doc_id'] ? htmlspecialchars($asMeta['doc_name'] . '（' . $asMeta['doc_no'] . '）') : '尚未綁定' ?></b>
                <div style="margin-top:4px;">系統裡還沒有「管制計畫」這份 AS 文件，要先到 AS 文件管理建立（建議編號 <code>2-QA-01-10</code> 之後的空號），再回來綁定。</div>
            </div>
            <div style="margin-bottom:18px;">
                <button class="btn-w2" id="btnPickAs"><i class="fa fa-link"></i> 選擇要綁定的 AS 文件</button>
            </div>
        </div>
<?php endif; ?>

<?php endif; /* canView */ ?>

    </div><!-- /right_col -->
</div></div>

<!-- 使用說明 -->
<div class="m-mask" id="helpUseMask">
    <div class="m-win" style="width:860px;">
        <div class="m-hd">管制計畫 CP 使用說明 <button class="m-x" data-close="helpUseMask">&times;</button></div>
        <div class="m-bd help-doc">
            <h4>這一頁是什麼</h4>
            <p>AS9100 要求的 <b>管制計畫（Control Plan，CP）</b>，也就是老師說的「類似 QC 工程圖」。
            一張 CP 回答的是：這個料號<b>每一道製程</b>要管什麼特性、規格公差多少、用什麼量具量、
            多久量一次、怎麼管制、以及<b>發現不符合時要做什麼（反應計畫）</b>。</p>

            <h4>階段：為什麼有三段、而我們只用兩段</h4>
            <p>AIAG 的 CP 分試作（Prototype）／試產（Pre-launch）／量產（Production）三段，
            <b>一張 CP 只屬於一個階段</b>（不是一張表三欄並排）。本公司是接單加工：
            訂單來的第一顆是首件（＝試作），首件過了就正式生產，<b>沒有量產導入期</b>，
            所以「試產」那一段可以在設定裡停用。線上檢驗的檢驗類別本來也只有「首件／一般」兩種。</p>
            <p><b>同一個料號可以有兩張 CP</b>（試作一張、生產一張），這是正常的——首件是 100% 全尺寸、
            生產是抽驗，兩張的「樣本／頻率」欄本來就不同。建生產段時可以用「改版」或重新帶入。</p>

            <h4>哪些訂單需要建管制計畫</h4>
            <p>依訂單追蹤的<b>「稽核製程」標籤</b>認定（2026-10-02 起）：訂單掛了稽核製程標籤
            （例「單製齒研」「全製含插齒」）就是需要管制計畫的訂單。
            內建的「全製」「單製非AS認證」「廠內治具」與管理員自加的「其他固定選項」
            （例「單/全製含齒研(不列AS認證)」）<b>都不算稽核製程，不需要 CP</b>。</p>
            <ul>
                <li><b>單製○○</b>：客戶送料來、只做這一道稽核製程 → 這張 CP 只需涵蓋該製程。</li>
                <li><b>全製含○○</b>：從頭做到成品、過程中有這一道 → 這張 CP 要涵蓋整條製程鏈。</li>
            </ul>
            <p>標籤定義只能在<b>訂單追蹤 → 設定 → 稽核製程標籤</b>維護，本頁只讀不寫。
            本頁的設定只提供「某個稽核製程暫時不要求建 CP」的排除選項，<b>預設全部都要求</b>。</p>
            <p>每張 CP 會記下建立當時的標籤快照當作<b>認定依據</b>；之後訂單標籤被改掉時，
            編輯畫面會提示不一致，但<b>不會自動改寫</b>那張 CP 的依據（當初就是依當時的認定建的）。</p>

            <h4>資料從哪裡自動帶來</h4>
            <ul>
                <li><b>製程列</b>＝該訂單綁定的<b>製令（BOM）</b>的製程鏈，依製程序排列。
                    機台是從<b>報工紀錄</b>抓該製程實際用過的機台（製令本身的機台欄幾乎沒人填）。</li>
                <li><b>產品特性／規格公差／檢驗方法／檢具編號／頻率</b>＝該料號該製程的
                    <b>SIP 標準檢驗指導書</b>的檢驗項目（只取已核准的版次）。找不到時退回「該製程的檢驗項目預設值範本」。</li>
                <li><b>特殊分類／管制方法</b>＝<b>一律留白，由人逐列填</b>。PFMEA 的失效模式是「製程」粒度、
                    不是逐一特性分別記錄，同一製程常同時有好幾種失效模式（例：尺寸偏擺／外觀刮傷／咬合精度），
                    各自的分類與管制方法都不一樣，自動套到每一列會出現「外觀被套上尺寸管制」這種語意錯誤
                    （2026-10-06 實測發現後改為留白）；PFMEA 的全部失效模式會整理成一段<b>參考文字</b>顯示在
                    每道製程下方（淡黃底），供填寫時逐列對照判斷。</li>
                <li><b>樣本／頻率</b>：階段若設了預設頻率就用它（試作段＝100% 全尺寸），否則用 SIP 上的頻率。</li>
            </ul>
            <p>帶不出來的欄位會<b>明確標示要人工填</b>，不會假裝有資料。</p>

            <h4>檢驗類別：IQC／IPQC／FQC</h4>
            <p>自動帶入時每道製程會判定出該接哪種檢驗，判定結果就是<b>每一道製程列的「檢驗類別」欄位</b>
            （也會在製程名稱旁顯示徽章），<b>判定完之後是人工可覆蓋的欄位</b>，不是每次都重算。</p>
            <ul>
                <li>預設都是 <b>IPQC（製程檢驗）</b>——不是下面幾種的其他製程維持 IPQC 不必另設。</li>
                <li><b>客供料製程</b>（系統自動判定，ProcessNo=138 或名稱含「客供料」）——同一條製程鏈只認
                    最早出現的那一個，命中的那一列本身就是 <b>IQC（進料檢驗）</b>。</li>
                <li><b>包裝製程</b>（讀「包裝排程」設定，與線上檢驗同一份）——包裝本身不算檢驗點。</li>
                <li><b>FQC（最終檢驗）</b>：只要鏈上有包裝，<b>包裝前一道一律自動插入一道新的合成列</b>，
                    不是既有列被改判。內容依「設定→②FQC內容切換門檻」二選一：總製程數≤門檻借用包裝前一道
                    的 SIP、＞門檻改用該料號的出貨檢驗內容。</li>
                <li><b>AS 稽核製程</b>（訂單追蹤的「稽核製程」標籤）可逐個標籤額外指定「下一站固定 IQC」，
                    優先序最高，會覆蓋上面的一般規則。</li>
            </ul>
            <p>編輯畫面「製程與管制特性」工具列有<b>「重新判定檢驗類別」</b>按鈕——手動調整過製程順序或增刪列之後，
            可依目前的設定重新套用判定（只套用在畫面上，按「儲存」才會留下）。</p>

            <h4>操作步驟</h4>
            <ul>
                <li>到「自動帶入／建議建立」→ 搜尋並點選訂單 → 選階段 →「預覽帶入內容」→ 確認後「建立」。</li>
                <li>或在「管制計畫清單」按「建立管制計畫」從空白開始，製程列自己新增。</li>
                <li>編輯畫面可逐列增刪特性、改規格與頻率、選檢具、挑反應計畫。</li>
                <li>填完按「儲存」，再按「送出核准」。核准人按「核准」或「退回」（退回要填原因）。</li>
                <li>已核准的不能直接改，要按「改版」產生新版次（A→B→C），舊版保留。</li>
            </ul>

            <h4>送出前會檢查什麼</h4>
            <p>每一列特性至少要有<b>檢驗方法或檢具編號</b>、以及<b>樣本或頻率</b>——這兩欄是稽核必查的。
            其餘欄位留白不擋，由核准人判斷。</p>

            <h4>權限角色</h4>
            <ul>
                <li><code>cp_view</code> 檢閱／<code>cp_edit</code> 建立修改／<code>cp_approve</code> 核准／<code>cp_admin</code> 管理員（可改設定、可刪除）。</li>
                <li><b>品管部門人員自動具備檢視與建立修改權限</b>，不必另外指派。</li>
                <li>不可核准自己送出的 CP（職務分離）。</li>
            </ul>

            <h4>設定入口</h4>
            <p>「設定」分頁（限管理員）：階段、特殊特性分類、反應計畫常用語、檢驗類別（IQC代號／包裝代號／
            AS稽核製程次站檢驗類別）、哪些訂單標籤需要建 CP、AS 文件綁定。</p>
        </div>
        <div class="m-ft"><button class="btn-w2" data-close="helpUseMask">關閉</button></div>
    </div>
</div>

<!-- 通用小跳窗（退回原因／改版說明／忽略原因／AS 文件挑選） -->
<div class="m-mask" id="mAsk">
    <div class="m-win" style="width:560px;">
        <div class="m-hd" id="askTitle">　<button class="m-x" data-close="mAsk">&times;</button></div>
        <div class="m-bd"><div id="askDesc" class="cp-note" style="display:none;"></div>
            <textarea id="askText" style="width:100%;height:90px;font-size:13px;padding:6px 8px;border:1px solid #d8c3a0;border-radius:4px;"></textarea>
            <div class="err-msg" id="askErr" style="display:none;"></div></div>
        <div class="m-ft"><button class="btn-w2" data-close="mAsk">取消</button>
            <button class="btn-w" id="askOk">確定</button></div>
    </div>
</div>

<div class="m-mask" id="mAs">
    <div class="m-win" style="width:720px;">
        <div class="m-hd">選擇要綁定的 AS 文件 <button class="m-x" data-close="mAs">&times;</button></div>
        <div class="m-bd">
            <input type="text" id="asKw" placeholder="輸入文件編號或名稱篩選" style="width:100%;height:30px;font-size:13px;padding:2px 8px;border:1px solid #d8c3a0;border-radius:4px;margin-bottom:8px;">
            <div id="asRows" style="max-height:420px;overflow:auto;"></div>
        </div>
        <div class="m-ft"><button class="btn-w2" data-close="mAs">關閉</button></div>
    </div>
</div>

<div class="m-mask" id="mIgn">
    <div class="m-win" style="width:720px;">
        <div class="m-hd">忽略名單 <button class="m-x" data-close="mIgn">&times;</button></div>
        <div class="m-bd"><div id="ignRows"></div></div>
        <div class="m-ft"><button class="btn-w2" data-close="mIgn">關閉</button></div>
    </div>
</div>

<div class="ac-list" id="acList"></div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script src="../../resource/js/eg_date_fmt.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_date_fmt.js') ?>"></script>
<script src="../../resource/js/eg_input_rules.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_input_rules.js') ?>"></script>
<script src="../../resource/js/eg_print_log.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_print_log.js') ?>"></script>
<script>
$(document).ready(function(){
    var $am = $('#sidebar-menu .nav.side-menu > li.active');
    if ($am.length) { $am.removeClass('active').find('ul.child_menu').hide(); $am.find('li.current-page').removeClass('current-page'); }
    $('#sidebar-menu').css('visibility','visible');
});

var API      = '../../src/store/ControlPlan_API.php';
var CSRF     = <?= json_encode($_SESSION['cp_csrf']) ?>;
var COMPANY  = <?= json_encode($companyName) ?>;
var CANEDIT  = <?= $P['edit'] ? 'true' : 'false' ?>;
var CANAPPR  = <?= $P['approve'] ? 'true' : 'false' ?>;
var CANADMIN = <?= $P['admin'] ? 'true' : 'false' ?>;
var CANVIEW  = <?= $P['view'] ? 'true' : 'false' ?>;

var STAGES = [], CLASSES = [], REACTS = [], CTRL_METHODS = [], ASMETA = {}, TAGSTATUS = {};
var ASTAGDEFS = [], EXCLTAGS = [], REQTAGS = [];   // 稽核製程標籤定義／被排除的／要求建 CP 的
var KGCODES = [], PACKCODES = [], FQC_THRESHOLD = 4, ASTAGINSP = {};   // 檢驗類別自動判定：客供料(IQC,唯讀)／包裝(唯讀)／FQC內容切換門檻／AS稽核製程次站設定
var DOC = null;          // 目前編輯中的 CP
var LIST_PAGE = 1;
var PREVIEW = null;      // 自動帶入預覽結果

/* 顯示用日期一律走共用檔（ai-rules/20：YYYY.MM.DD） */
function dispDate(d){ try { return egFmtDate(d); } catch(e){ return d || ''; } }
function esc(s){ return $('<div>').text(s == null ? '' : String(s)).html(); }
function toast(msg, isErr){
    var $t = $('<div>').css({position:'fixed',right:'18px',bottom:'18px',zIndex:99999,
        background:isErr?'#DD5138':'#F0A24B',color:'#fff',padding:'9px 16px',borderRadius:'5px',
        boxShadow:'0 3px 12px rgba(0,0,0,.25)',fontSize:'13px',maxWidth:'420px',whiteSpace:'pre-line'}).text(msg);
    $('body').append($t); setTimeout(function(){ $t.fadeOut(300, function(){ $t.remove(); }); }, isErr ? 4200 : 2200);
}
function openMask(id){ $('#'+id).addClass('on'); }
function closeMask(id){ $('#'+id).removeClass('on'); }
$(document).on('click', '[data-close]', function(){ closeMask($(this).data('close')); });
$(document).on('click', '.m-mask', function(e){ if (e.target === this) $(this).removeClass('on'); });
$('#btnPageHelp').on('click', function(){ openMask('helpUseMask'); });

/* AJAX 錯誤統一顯示——否則非 2xx 時各處 if(!res.success) 根本跑不到，畫面上只會「按了沒反應」 */
$(document).ajaxError(function(e, xhr){
    if (xhr.status === 0) return;
    var m = '';
    try { m = (JSON.parse(xhr.responseText) || {}).message || ''; } catch(err){}
    toast(m || ('伺服器回應錯誤（HTTP ' + xhr.status + '）'), true);
});

function post(action, data, cb){
    data = data || {}; data.action = action; data.csrf = CSRF;
    $.post(API, data, function(res){
        if (!res || !res.success) { if (res && res.message) toast(res.message, true); return; }
        if (cb) cb(res);
    }, 'json');
}
function get(action, data, cb){
    data = data || {}; data.action = action;
    $.get(API, data, function(res){
        if (!res || !res.success) { if (res && res.message) toast(res.message, true); return; }
        if (cb) cb(res);
    }, 'json');
}

/* ────────────────── 分頁切換 ────────────────── */
$('.cp-tab').on('click', function(){
    var p = $(this).data('pane');
    $('.cp-tab').removeClass('on'); $(this).addClass('on');
    $('.cp-pane').removeClass('on'); $('#pane-' + p).addClass('on');
    if (p === 'list') loadList();
    if (p === 'cfg')  renderCfg();
    if (p === 'auto') loadSug();   // 切進來就自動帶第一頁，不必再按一次「重新整理」
});

/* ────────────────── 啟動 ────────────────── */
function boot(){
    if (!CANVIEW) return;
    get('bootstrap', {}, function(res){
        STAGES = res.stages || []; CLASSES = res.special_classes || [];
        REACTS = res.reaction_opts || []; CTRL_METHODS = res.ctrl_method_defaults || []; ASMETA = res.as_doc || {};
        TAGSTATUS = res.tag_status || {};
        ASTAGDEFS = res.as_tag_defs || []; EXCLTAGS = res.excluded_as_tags || []; REQTAGS = res.required_as_tags || [];
        KGCODES = res.kg_codes || []; PACKCODES = res.pack_codes || []; FQC_THRESHOLD = res.fqc_threshold || 4;
        ASTAGINSP = res.as_tag_insp || {};
        fillStageSelects();
        loadList();
        renderAutoNote();
        if (CANADMIN) renderCfg();
    });
}
function fillStageSelects(){
    var optAll = '<option value="">全部階段</option>';
    var optAct = '', optSug = '<option value="">不分階段（任一階段都沒有才列出）</option>';
    STAGES.forEach(function(s){
        optAll += '<option value="'+s.stage_id+'">'+esc(s.stage_name)+'</option>';
        // 停用的階段不給新 CP 選（與 #aStage／#eStage 同規則）；建議建立清單的篩選也比照，
        // 否則「已停用」在畫面上看起來像沒生效（2026-10-06 使用者回報：量產設不啟用，前端還是顯示）。
        if (+s.is_active === 1) { optSug += '<option value="'+s.stage_id+'">'+esc(s.stage_name)+'</option>'; }
        if (+s.is_active === 1) optAct += '<option value="'+s.stage_id+'">'+esc(s.stage_name)+'</option>';
    });
    $('#fStage').html(optAll);
    $('#sStage').html(optSug);
    $('#aStage').html(optAct);
    $('#eStage').html(optAct);
}

/* ────────────────── 分頁一：清單 ────────────────── */
var kwTimer = null;
$('#fKw').on('input', function(){ clearTimeout(kwTimer); kwTimer = setTimeout(function(){ LIST_PAGE = 1; loadList(); }, 350); });
$('#fStage,#fStatus,#fPer').on('change', function(){ LIST_PAGE = 1; loadList(); });

function loadList(){
    get('list', { stage_id: $('#fStage').val() || 0, status: $('#fStatus').val() || '',
                  kw: $('#fKw').val() || '', page: LIST_PAGE, per: $('#fPer').val() || 10 },
    function(res){
        var rows = res.rows || [], tb = '';
        if (!rows.length) {
            tb = '<tr><td colspan="11" style="text-align:center;color:#8a6d45;padding:18px;">'
               + (res.total ? '這一頁沒有資料' : '還沒有任何管制計畫。' + (CANEDIT ? '請按右上角「建立管制計畫」，或到「自動帶入／建議建立」從訂單帶入。' : '')) + '</td></tr>';
        }
        rows.forEach(function(r){
            var sg = 'sg-' + (r.stage_id || 'x');
            var ops = '<button class="btn-w2 btn-xs2" data-open="'+r.cp_id+'"><i class="fa fa-folder-open-o"></i> 開啟</button> '
                    + '<button class="btn-w2 btn-xs2" data-print="'+r.cp_id+'"><i class="fa fa-print"></i> 列印</button>';
            if (CANADMIN) ops += ' <button class="btn-w2 btn-xs2" data-del="'+r.cp_id+'" style="border-color:#DD5138;color:#8c2d18;"><i class="fa fa-trash"></i></button>';
            var src = '';
            if (r.src_order_oo) src += '<div class="muted">單 '+esc(r.src_order_oo)+'</div>';
            if (r.src_bom) src += '<div class="muted">令 '+esc(r.src_bom)+'</div>';
            tb += '<tr>'
               + '<td><b>'+esc(r.cp_no)+'</b></td>'
               + '<td><span class="tag-s '+sg+'">'+esc(r.stage_name || '—')+'</span></td>'
               + '<td>'+esc(r.scope === 'family' ? (r.family_name || '') : (r.part_no_text || ''))
               +   (r.scope === 'family' ? '<span class="src-tag">產品族</span>' : '') + '</td>'
               + '<td>'+esc(r.product_name || '')+'</td>'
               + '<td>'+esc(r.customer_name || '')+'</td>'
               + '<td style="text-align:center;">'+esc(r.ver_no || '')+'</td>'
               + '<td>'+esc(r.form_date_disp || '')+'</td>'
               + '<td style="text-align:center;"><span class="tag-s st-'+esc(r.status)+'">'+esc(r.status_label)+'</span></td>'
               + '<td>'+(src || '<span class="muted">手動建立</span>')+'</td>'
               + '<td style="text-align:center;">'+(r.proc_cnt||0)+' / '+(r.item_cnt||0)+'</td>'
               + '<td>'+ops+'</td></tr>';
        });
        $('#tList tbody').html(tb);
        renderPager(res.total || 0, res.per || 10, res.page || 1);
    });
}
function renderPager(total, per, page){
    var pages = Math.max(1, Math.ceil(total / per)), h = '';
    h += '<span class="muted">共 '+total+' 筆 / '+pages+' 頁</span>';
    if (pages > 1) {
        h += ' <button data-pg="1">&laquo;</button>';
        var s = Math.max(1, page - 2), e = Math.min(pages, s + 4);
        for (var i = s; i <= e; i++) h += ' <button data-pg="'+i+'"'+(i===page?' class="on"':'')+'>'+i+'</button>';
        h += ' <button data-pg="'+pages+'">&raquo;</button>';
    }
    $('#pgList').html(h);
}
$(document).on('click', '#pgList button', function(){ LIST_PAGE = +$(this).data('pg'); loadList(); });
$(document).on('click', '[data-open]', function(){ openDoc(+$(this).data('open')); });
$(document).on('click', '[data-print]', function(){ printDoc(+$(this).data('print')); });
$(document).on('click', '[data-del]', function(){
    var id = +$(this).data('del');
    if (!confirm('確定要刪除這份管制計畫嗎？\n（軟刪除，資料仍留在資料庫）')) return;
    post('delete', { cp_id: id }, function(res){ toast(res.message); loadList(); });
});

/* ────────────────── 分頁二：編輯 ────────────────── */
$('#btnNew').on('click', function(){
    DOC = { cp_id: 0, stage_id: (STAGES.filter(function(s){return +s.is_active===1;})[0]||{}).stage_id || '',
            scope: 'part', ver_no: 'A', status: 'draft',
            form_date: new Date().toISOString().substring(0,10), processes: [], family_parts: [] };
    renderEdit(); gotoPane('edit');
});
function gotoPane(p){ $('.cp-tab[data-pane="'+p+'"]').trigger('click'); }

function openDoc(cpId){
    get('get', { cp_id: cpId }, function(res){ DOC = res.doc; renderEdit(); gotoPane('edit'); });
}

function renderEdit(){
    if (!DOC) { $('#editWrap').hide(); $('#editHint').show(); return; }
    $('#editHint').hide(); $('#editWrap').show();

    $('#edTitle').text(DOC.cp_id ? ('管制計畫 ' + (DOC.cp_no || '')) : '新增管制計畫（尚未儲存）');
    var tg = '';
    if (DOC.cp_id) {
        tg += ' <span class="tag-s st-'+esc(DOC.status)+'">'+esc(DOC.status_label || DOC.status)+'</span>';
        if (DOC.stage_name) tg += ' <span class="tag-s sg-'+(DOC.stage_id||'x')+'">'+esc(DOC.stage_name)+'</span>';
        if (DOC.ver_no) tg += ' <span class="tag-s sg-1">版次 '+esc(DOC.ver_no)+'</span>';
    }
    $('#edTags').html(tg);

    $('#eStage').val(DOC.stage_id || '');
    $('#eScope').val(DOC.scope || 'part');
    $('#ePart').val(DOC.part_no_text || ''); $('#ePartId').val(DOC.part_d_id || '');
    $('#eFamily').val(DOC.family_name || '');
    $('#eProdName').val(DOC.product_name || '');
    $('#eCustName').val(DOC.customer_name || ''); $('#eCustId').val(DOC.customer_id || '');
    $('#ePartRev').val(DOC.part_rev || '');
    $('#eVer').val(DOC.ver_no || 'A');
    $('#eFormDate').val((DOC.form_date || '').substring(0,10));
    $('#eOrder').val(DOC.src_order_oo || ''); $('#eOrderId').val(DOC.src_order_id || '');
    $('#ePfmeaId').val(DOC.pfmea_doc_id || '');
    $('#ePfmeaTxt').val(DOC.pfmea_doc_id ? ('PFMEA #' + DOC.pfmea_doc_id) : '（這個料號沒有 PFMEA）');
    $('#eCoreTeam').val(DOC.core_team || '');
    $('#eKeyContact').val(DOC.key_contact || '');
    $('#eOrgCode').val(DOC.org_code || '');
    $('#eCustEng').val(DOC.customer_eng_appr || '');
    $('#eCustQa').val(DOC.customer_qa_appr || '');
    $('#eOtherAppr').val(DOC.other_appr || '');
    $('#eNote').val(DOC.note || '');

    var bomOpt = '<option value="">（未指定）</option>';
    if (DOC.src_bom) bomOpt += '<option value="'+esc(DOC.src_bom)+'" selected>'+esc(DOC.src_bom)+'</option>';
    $('#eBom').html(bomOpt);
    $('#eBomInfo').text(DOC.src_bom_date ? ('製令開立日 ' + dispDate(DOC.src_bom_date)) : '');

    toggleScope();
    renderStageNote();
    renderFamily();
    renderProcs();

    var ro = (DOC.status === 'approved' && !CANADMIN) || !CANEDIT;
    $('#editWrap').find('input,select,textarea').prop('disabled', ro);
    $('#btnSave').prop('disabled', ro || !CANEDIT);
    $('#btnSubmit').toggle(!!DOC.cp_id && DOC.status === 'draft' && CANEDIT);
    $('#btnApprove').toggle(!!DOC.cp_id && DOC.status === 'submitted' && CANAPPR);
    $('#btnReject').toggle(!!DOC.cp_id && DOC.status === 'submitted' && CANAPPR);
    $('#btnRevise').toggle(!!DOC.cp_id && DOC.status === 'approved' && CANEDIT);
    $('#btnPrint').toggle(!!DOC.cp_id);
    var msg = '';
    if (ro && DOC.status === 'approved') {
        msg += '<div class="cp-note">這份管制計畫已核准，不可直接修改。要修改請按「改版」建立新版次（舊版會保留）。</div>';
    }
    // 認定依據：建立時的稽核製程標籤快照，以及現在是否已被改掉
    if (DOC.src_as_tag_label) {
        msg += '<div class="cp-note"><b>認定依據</b>：建立時來源訂單的稽核製程標籤為'
             + ' <span class="tag-s sg-3">' + esc(DOC.src_as_tag_label) + '</span>';
        if (+DOC.as_tag_changed === 1) {
            var nw = DOC.as_tag_now || {};
            msg += '<div style="margin-top:5px;color:#8c2d18;">⚠ 該訂單的標籤<b>現在已經不一樣</b>了：'
                 + esc(nw.label || '（已清除）')
                 + (nw.need_cp ? '' : '（依現況已不需要管制計畫：' + esc(nw.reason || '') + '）')
                 + '。這張 CP 的認定依據刻意保留當初的快照不自動改寫，'
                 + '請確認是標籤改錯了、還是這張 CP 該作廢。</div>';
        }
        msg += '</div>';
    } else if (DOC.src_order_id && DOC.as_tag_now && !DOC.as_tag_now.need_cp) {
        msg += '<div class="cp-note warn"><b>注意</b>：來源訂單依稽核製程標籤的認定<b>不需要</b>管制計畫'
             + '（' + esc(DOC.as_tag_now.reason || '') + '）。這張 CP 仍然有效，但請確認是刻意建立的。</div>';
    }
    $('#edMsg').html(msg);
}

$('#eStage').on('change', function(){ renderStageNote(); });
function renderStageNote(){
    var s = STAGES.filter(function(x){ return +x.stage_id === +$('#eStage').val(); })[0];
    $('#eStageNote').text(s ? (s.default_freq ? ('預設頻率：' + s.default_freq) : (s.note || '')) : '');
}
$('#eScope').on('change', toggleScope);
function toggleScope(){
    var f = $('#eScope').val() === 'family';
    $('#wPart').toggle(!f); $('#wFamily').toggle(f); $('#wFamilyParts').toggle(f);
}

function renderFamily(){
    var h = '';
    (DOC.family_parts || []).forEach(function(p, i){
        h += '<span class="tag-s sg-1" style="margin:2px 4px 2px 0;font-size:12px;line-height:18px;padding:2px 8px;">'
           + esc(p.D_Setting_Id || p.part_no_text) + ' <a href="#" data-famdel="'+i+'" style="color:#8c2d18;">&times;</a></span>';
    });
    $('#famList').html(h || '<span class="muted">尚未加入任何料號</span>');
}
$(document).on('click', '[data-famdel]', function(e){
    e.preventDefault();
    DOC.family_parts.splice(+$(this).data('famdel'), 1); renderFamily();
});

/* 製程與特性列 */
function renderProcs(){
    var h = '';
    (DOC.processes || []).forEach(function(p, pi){
        h += '<div class="proc-box" data-pi="'+pi+'">'
          +  '<div class="proc-head">'
          +   '<span class="pname">'+(pi+1)+'. '+esc(p.process_name || ('製程 ' + (p.process_no || '')))+'</span>'
          +   (p.process_no ? '<span class="src-tag">#'+esc(p.process_no)+'</span>' : '')
          +   (p.src === 'bom' ? '<span class="src-tag">來自製令</span>'
               : p.src === 'fqc_insert' ? '<span class="src-tag" title="包裝前一道自動插入，內容借用前一道製程的SIP，可自行調整或刪除">自動插入(FQC)</span>'
               : p.src === 'fqc_insert_ship' ? '<span class="src-tag" title="包裝前一道自動插入，製程數超過門檻改用出貨檢驗內容，可自行調整或刪除">自動插入(FQC/出貨檢驗)</span>'
               : '<span class="src-tag">手動</span>')
          +   (+p.is_outsource ? '<span class="tag-s sg-2">委外</span>' : '')
          +   inspBadge(p.insp_stage, p.insp_gap, DOC.part_no_text)
          +   (p.process_no ? '<button class="btn-w2 btn-xs2" data-swsip="'+pi+'" title="改用同製程大類的其他通用SIP，取代這一列目前的特性內容"><i class="fa fa-exchange"></i> 切換SIP</button>' : '')
          +   '<button class="btn-w2 btn-xs2" data-additem="'+pi+'" style="margin-left:auto;"><i class="fa fa-plus"></i> 新增特性</button>'
          +   '<button class="btn-w2 btn-xs2" data-upproc="'+pi+'"><i class="fa fa-arrow-up"></i></button>'
          +   '<button class="btn-w2 btn-xs2" data-dnproc="'+pi+'"><i class="fa fa-arrow-down"></i></button>'
          +   '<button class="btn-w2 btn-xs2" data-delproc="'+pi+'" style="border-color:#DD5138;color:#8c2d18;"><i class="fa fa-trash"></i></button>'
          +  '</div><div class="proc-body">'
          +  '<div class="proc-f">'
          +   '<div><label>製程名稱</label><input type="text" data-pf="process_name" data-pi="'+pi+'" value="'+esc(p.process_name||'')+'" style="width:150px;"></div>'
          +   '<div><label>作業說明</label><input type="text" data-pf="op_desc" data-pi="'+pi+'" value="'+esc(p.op_desc||'')+'" style="width:260px;"></div>'
          +   '<div><label>機器／裝置</label><input type="text" data-pf="machine" data-pi="'+pi+'" value="'+esc(p.machine||'')+'" style="width:170px;"></div>'
          +   '<div><label>治具／工具</label><input type="text" data-pf="jig_tool" data-pi="'+pi+'" value="'+esc(p.jig_tool||'')+'" style="width:170px;"></div>'
          +   '<div><label>委外廠商</label><input type="text" data-pf="maker_name" data-pi="'+pi+'" value="'+esc(p.maker_name||'')+'" style="width:140px;"></div>'
          +   '<div><label>檢驗類別</label>' + inspSelect(p, pi) + '</div>'
          +  '</div>';

        if (p.hint) h += '<div class="cp-note" style="margin:0 0 6px;">'+esc(p.hint)+'</div>';
        // PFMEA 參考（製程層級彙整，不逐列套用）：供填「特殊分類」「管制方法」時自己對照，
        // 兩欄一律留白由人判斷（2026-10-06 使用者實測抓到「外觀套到尺寸管制」的語意錯誤）。
        if (p.pfmea_note) h += '<div class="cp-note" style="margin:0 0 6px;white-space:pre-line;background:#F7E0BD;border-color:#e0c79a;color:#6b4a28;">'+esc(p.pfmea_note)+'</div>';

        h += '<div class="cp-scroll"><table class="cp-t"><thead><tr>'
          +   '<th style="width:58px;">特性號</th><th style="width:150px;">產品特性</th><th style="width:130px;">製程特性</th>'
          +   '<th style="width:92px;">分類</th><th style="width:170px;">規格／公差</th>'
          +   '<th style="width:130px;">檢驗方法</th><th style="width:110px;">檢具編號</th>'
          +   '<th style="width:74px;">樣本</th><th style="width:120px;">頻率</th>'
          +   '<th style="width:190px;">管制方法</th><th style="width:170px;">反應計畫</th><th style="width:40px;"></th>'
          +  '</tr></thead><tbody>';
        var items = p.items || [];
        if (!items.length) {
            h += '<tr><td colspan="12" style="text-align:center;color:#8a6d45;">這道製程還沒有特性列，按「新增特性」加入</td></tr>';
        }
        items.forEach(function(it, ii){
            var clsOpt = '<option value="">—</option>';
            CLASSES.forEach(function(c){
                var selById = +it.special_class_id === +c.class_id;
                var selByTxt = !it.special_class_id && it.special_class_text &&
                               (String(it.special_class_text).indexOf(c.class_name) >= 0);
                clsOpt += '<option value="'+c.class_id+'"'+((selById||selByTxt)?' selected':'')+'>'
                        + esc((c.symbol?c.symbol+' ':'')+c.class_name)+'</option>';
            });
            var reOpt = '<option value="">—</option>';
            REACTS.forEach(function(o){
                if (+o.is_active !== 1 && it.reaction_plan !== o.opt_text) return;
                reOpt += '<option'+(it.reaction_plan === o.opt_text ? ' selected' : '')+'>'+esc(o.opt_text)+'</option>';
            });
            var custom = it.reaction_plan && !REACTS.some(function(o){ return o.opt_text === it.reaction_plan; });
            if (custom) reOpt += '<option selected>'+esc(it.reaction_plan)+'</option>';

            h += '<tr data-pi="'+pi+'" data-ii="'+ii+'">'
              + td(pi,ii,'char_no',it.char_no,'width:52px;')
              + td(pi,ii,'char_product',it.char_product,'width:144px;')
              + td(pi,ii,'char_process',it.char_process,'width:124px;')
              + '<td><select data-if="special_class_id" data-pi="'+pi+'" data-ii="'+ii+'" style="width:86px;font-size:12px;height:26px;">'+clsOpt+'</select></td>'
              + td(pi,ii,'spec_text',it.spec_text,'width:164px;')
              + td(pi,ii,'eval_method',it.eval_method,'width:124px;')
              + '<td><input type="text" data-if="tool_no" data-pi="'+pi+'" data-ii="'+ii+'" value="'+esc(it.tool_no||'')+'" class="tool-pick" style="width:104px;font-size:12px;height:26px;" autocomplete="off">'
              +   '<input type="hidden" data-if="tool_id" data-pi="'+pi+'" data-ii="'+ii+'" value="'+esc(it.tool_id||'')+'"></td>'
              + td(pi,ii,'sample_size',it.sample_size,'width:68px;')
              + td(pi,ii,'sample_freq',it.sample_freq,'width:114px;')
              + '<td>' + ctrlMethodPickHtml(pi,ii) + td(pi,ii,'control_method',it.control_method,'width:184px;margin-top:2px;',true,true) + '</td>'
              + '<td><select data-if="reaction_plan" data-pi="'+pi+'" data-ii="'+ii+'" style="width:164px;font-size:12px;height:26px;">'+reOpt+'</select></td>'
              + '<td style="text-align:center;"><a href="#" data-delitem="1" data-pi="'+pi+'" data-ii="'+ii+'" style="color:#8c2d18;"><i class="fa fa-times"></i></a></td>'
              + '</tr>';
        });
        h += '</tbody></table></div></div></div>';
    });
    $('#procWrap').html(h || '<div class="cp-note">還沒有製程列。按「新增製程」手動加入，或到「自動帶入／建議建立」從訂單的製令帶入。</div>');
    if (DOC.status === 'approved' && !CANADMIN) $('#procWrap').find('input,select').prop('disabled', true);
}
/* 檢驗類別（IQC/IPQC/FQC）：顯示徽章＋可改的下拉，自動帶入會算好，之後是人工可覆蓋欄位。
   gap='sop'/'sip' 時另外標一顆警示角標——分類本身不變，只是提醒該製程背後該有的
   SOP/SIP 文件還查無已核准版本（cp_insp_gap_annotate，見 control_plan_lib.php 六之二節）。 */
var INSP_LABEL = { IQC: 'IQC', IPQC: 'IPQC', FQC: 'FQC' };
var INSP_GAP_LABEL = { sop: '缺SOP', sip: '缺SIP' };
var INSP_GAP_TAB   = { sop: 'sop', sip: 'sip' };
var INSP_GAP_TITLE = { sop: '查無此製程已核准的 SOP（製造製程說明書）——點一下直接開 SOP/SIP 模組查這個料號',
                        sip: '查無此製程已核准的 SIP（標準檢驗指導書）——點一下直接開 SOP/SIP 模組查這個料號' };
/* gap 標籤做成可點的深連結（ai-rules/08：缺資料要能直接帶去設定頁，不是只有一句提示字），
   帶料號當關鍵字＝sop_sip.php 既有的 ?tab=sop|sip&kw= 深連結（專案管理「文件檢核」已經在用
   同一套，不是本頁新發明一套）。partNo 沒有時（設定頁的預覽等情境）退回純文字不可點。 */
function inspBadge(stage, gap, partNo){
    var h = '';
    if (stage && INSP_LABEL[stage]) {
        var cls = stage === 'IQC' ? 'sg-1' : (stage === 'FQC' ? 'sg-3' : 'sg-2');
        h = '<span class="tag-s '+cls+'" title="檢驗類別（可在下方「檢驗類別」欄位改）">'+INSP_LABEL[stage]+'</span>';
    }
    /* 包裝本身沒有 insp_stage（不是檢驗點）仍可能帶 gap='sip'——這裡就是不早退、
       讓下面的缺口籤照樣畫出來的原因（見 cp_insp_gap_annotate 說明）。 */
    if (gap && INSP_GAP_LABEL[gap]) {
        var gapStyle = 'background:#f3e2c7;color:#8c2d18;border:1px solid #DD5138;margin-left:2px;';
        if (partNo) {
            var url = '../QA/sop_sip.php?tab='+INSP_GAP_TAB[gap]+'&kw='+encodeURIComponent(partNo);
            h += '<a href="'+url+'" target="_blank" class="tag-s" style="'+gapStyle+'text-decoration:none;" title="'+INSP_GAP_TITLE[gap]+'"><i class="fa fa-exclamation-triangle"></i> '+INSP_GAP_LABEL[gap]+' <i class="fa fa-external-link" style="font-size:9px;"></i></a>';
        } else {
            h += '<span class="tag-s" style="'+gapStyle+'" title="'+INSP_GAP_TITLE[gap]+'"><i class="fa fa-exclamation-triangle"></i> '+INSP_GAP_LABEL[gap]+'</span>';
        }
    }
    return h;
}
/* 特性列右上角小籤：這一列的規格是哪裡來的（cp_sip_items 的 src）——顯示一律大寫縮寫，不要印原始小寫代碼 */
var ITEM_SRC_LABEL = { sip: 'SIP', sip_general: 'SIP(通用)', sip_general_cat: 'SIP(同大類通用)',
    qcv2: '線上檢驗標準', ship: '出貨檢驗', tpl: '範本', tpl_cat: '範本(同大類)', manual: '手動' };
function itemSrcLabel(src){ return ITEM_SRC_LABEL[src] || (src || ''); }
function inspSelect(p, pi){
    var opts = [['', '—（包裝／不適用）'], ['IQC','IQC（進料檢驗）'], ['IPQC','IPQC（製程檢驗）'], ['FQC','FQC（最終檢驗）']];
    var h = '<select data-pf="insp_stage" data-pi="'+pi+'" style="width:150px;">';
    opts.forEach(function(o){ h += '<option value="'+o[0]+'"'+((p.insp_stage||'')===o[0]?' selected':'')+'>'+o[1]+'</option>'; });
    return h + '</select>';
}
function td(pi, ii, f, v, style, isTa, bare){
    var open = bare ? '' : '<td>', close = bare ? '' : '</td>';
    if (isTa) {
        return open + '<textarea data-if="'+f+'" data-pi="'+pi+'" data-ii="'+ii+'" style="'+(style||'')
             + 'font-size:12px;min-height:26px;height:46px;padding:2px 4px;border:1px solid #d8c3a0;border-radius:3px;">'
             + esc(v||'') + '</textarea>' + close;
    }
    return open + '<input type="text" data-if="'+f+'" data-pi="'+pi+'" data-ii="'+ii+'" value="'+esc(v||'')
         + '" style="'+(style||'')+'font-size:12px;height:26px;padding:2px 4px;border:1px solid #d8c3a0;border-radius:3px;">' + close;
}
/* 管制方法快選下拉：選一個就直接覆蓋這一列的管制方法內容（不是附加——避免選錯又要手動清） */
function ctrlMethodPickHtml(pi, ii){
    if (!CTRL_METHODS.length) return '';
    var h = '<select class="ctrl-method-pick" data-pi="'+pi+'" data-ii="'+ii+'" style="width:184px;font-size:11px;height:22px;margin-bottom:2px;">'
          + '<option value="">快選…</option>';
    CTRL_METHODS.forEach(function(c){
        if (+c.is_active !== 1) return;
        var preview = (c.control_method||'').replace(/\n/g,'／');
        if (preview.length > 16) preview = preview.substring(0,16) + '…';
        h += '<option value="'+c.id+'">['+esc(c.match_text)+'] '+esc(preview)+'</option>';
    });
    return h + '</select><br>';
}
$(document).on('change', '.ctrl-method-pick', function(){
    var id = +$(this).val(); if (!id) return;
    var pi = +$(this).data('pi'), ii = +$(this).data('ii');
    var hit = CTRL_METHODS.filter(function(c){ return +c.id === id; })[0];
    if (!hit || !DOC || !DOC.processes[pi] || !DOC.processes[pi].items[ii]) return;
    DOC.processes[pi].items[ii].control_method = hit.control_method;
    $(this).val('');
    renderProcs();
});

/* 欄位改動即寫回 DOC（不等存檔，避免重繪時遺失） */
$(document).on('input change', '[data-if]', function(){
    var pi = +$(this).data('pi'), ii = +$(this).data('ii'), f = $(this).data('if');
    if (!DOC || !DOC.processes[pi] || !DOC.processes[pi].items[ii]) return;
    DOC.processes[pi].items[ii][f] = $(this).val();
    if (f === 'special_class_id') {
        var c = CLASSES.filter(function(x){ return +x.class_id === +$(this).val(); }.bind(this))[0];
        DOC.processes[pi].items[ii].special_class_text = c ? c.class_name : '';
    }
});
$(document).on('input change', '[data-pf]', function(){
    var pi = +$(this).data('pi'), f = $(this).data('pf');
    if (!DOC || !DOC.processes[pi]) return;
    DOC.processes[pi][f] = $(this).val();
});
$(document).on('click', '[data-additem]', function(){
    var pi = +$(this).data('additem');
    DOC.processes[pi].items = DOC.processes[pi].items || [];
    var s = STAGES.filter(function(x){ return +x.stage_id === +$('#eStage').val(); })[0];
    DOC.processes[pi].items.push({ char_no: (pi+1)+'-'+(DOC.processes[pi].items.length+1),
        sample_freq: s && s.default_freq ? s.default_freq : '', src: 'manual' });
    renderProcs();
});
$(document).on('click', '[data-swsip]', function(){
    var pi = +$(this).data('swsip'), p = DOC.processes[pi], $btn = $(this);
    if (!p || !p.process_no) return;
    get('sip_candidates', { process_no: p.process_no }, function(res){
        var rows = res.rows || [];
        if (!rows.length) { toast('同製程大類找不到其他已核准的通用 SIP 可切換。', true); return; }
        acShow($btn, rows, function(r){
            return '<b>#'+esc(r.process_no)+' '+esc(r.process_name||'')+'</b>'
                 + '　'+esc(r.title||('doc#'+r.doc_id))+'　<span class="muted">'+esc(r.ver_no||'')+'</span>';
        }, function(r){
            if (!confirm('改用「#'+r.process_no+' '+(r.process_name||'')+'　'+(r.title||'')+'」的 SIP 內容？\n這一列目前的特性會被取代（還沒按「儲存」前都可以再改回來）。')) return;
            get('sip_switch_items', { process_no: r.process_no, stage_id: $('#eStage').val() || 0 }, function(res2){
                var items = (res2.items || []).map(function(it, k){
                    it.char_no = (pi + 1) + '-' + (k + 1);
                    return it;
                });
                DOC.processes[pi].items = items;
                renderProcs();
                toast('已改用 #' + r.process_no + ' 的通用 SIP，共 ' + items.length + ' 項特性，請確認後按「儲存」。');
            });
        });
    });
});
$(document).on('click', '[data-delitem]', function(e){
    e.preventDefault();
    var pi = +$(this).data('pi'), ii = +$(this).data('ii');
    DOC.processes[pi].items.splice(ii, 1); renderProcs();
});
$(document).on('click', '[data-delproc]', function(){
    var pi = +$(this).data('delproc');
    var n = (DOC.processes[pi].items || []).length;
    if (n && !confirm('這道製程底下有 ' + n + ' 列特性，確定一起刪除？')) return;
    DOC.processes.splice(pi, 1); renderProcs();
});
$(document).on('click', '[data-upproc]', function(){
    var pi = +$(this).data('upproc'); if (pi <= 0) return;
    var t = DOC.processes[pi-1]; DOC.processes[pi-1] = DOC.processes[pi]; DOC.processes[pi] = t; renderProcs();
});
$(document).on('click', '[data-dnproc]', function(){
    var pi = +$(this).data('dnproc'); if (pi >= DOC.processes.length - 1) return;
    var t = DOC.processes[pi+1]; DOC.processes[pi+1] = DOC.processes[pi]; DOC.processes[pi] = t; renderProcs();
});
$('#btnAddProc').on('click', function(){
    DOC.processes = DOC.processes || [];
    DOC.processes.push({ process_name: '', src: 'manual', items: [] });
    renderProcs();
});
$('#btnRefillFromSrc').on('click', function(){
    var oid = +$('#eOrderId').val() || 0, bom = $('#eBom').val() || '';
    if (!oid && !bom) { toast('這份 CP 沒有來源訂單或製令，無法重新帶入。請到「自動帶入」分頁操作。', true); return; }
    if (!confirm('會用來源製令的製程重新帶入，目前畫面上的製程與特性列會被取代。\n（尚未儲存的修改會遺失）確定嗎？')) return;
    get('autofill', { order_id: oid, bom: bom, stage_id: $('#eStage').val() || 0 }, function(res){
        DOC.processes = (res.processes || []).map(mapPrevProc);
        renderProcs();
        toast('已重新帶入 ' + DOC.processes.length + ' 道製程。');
    });
});
$('#btnRecalcInsp').on('click', function(){
    if (!DOC.processes || !DOC.processes.length) { toast('還沒有任何製程列。', true); return; }
    // 只送 process_no，順序＝畫面目前的順序（客供料自動判定/包裝代號/AS稽核製程規則依此重算）
    var rows = DOC.processes.map(function(p){ return { process_no: p.process_no }; });
    post('recalc_insp', { processes: JSON.stringify(rows), part_d_id: $('#ePartId').val() || 0 }, function(res){
        (res.processes || []).forEach(function(r, i){
            if (!DOC.processes[i]) return;
            DOC.processes[i].insp_stage = r.insp_stage || '';
            DOC.processes[i].insp_src = r.insp_src || '';
            DOC.processes[i].insp_gap = r.insp_gap || '';
        });
        renderProcs();
        toast('已依設定重新判定，請確認後按「儲存」。');
    });
});

/* ── 存檔與流程 ── */
function collectHead(){
    return {
        cp_id: DOC.cp_id || 0,
        stage_id: $('#eStage').val() || 0,
        scope: $('#eScope').val(),
        part_d_id: $('#ePartId').val() || 0,
        part_no_text: $('#ePart').val(),
        product_name: $('#eProdName').val(),
        family_name: $('#eFamily').val(),
        customer_id: $('#eCustId').val(),
        customer_name: $('#eCustName').val(),
        part_rev: $('#ePartRev').val(),
        ver_no: $('#eVer').val(),
        form_date: $('#eFormDate').val(),
        src_order_id: $('#eOrderId').val() || 0,
        src_order_oo: $('#eOrder').val(),
        src_bom: $('#eBom').val() || '',
        src_bom_date: DOC.src_bom_date || '',
        pfmea_doc_id: $('#ePfmeaId').val() || 0,
        org_code: $('#eOrgCode').val(),
        key_contact: $('#eKeyContact').val(),
        core_team: $('#eCoreTeam').val(),
        customer_eng_appr: $('#eCustEng').val(),
        customer_qa_appr: $('#eCustQa').val(),
        other_appr: $('#eOtherAppr').val(),
        note: $('#eNote').val(),
        processes: JSON.stringify(DOC.processes || []),
        family_parts: JSON.stringify(DOC.family_parts || [])
    };
}
/* 前端即時擋一次（鐵律8：後端 cp_save() 用同一套規則再擋一次） */
function validateHead(){
    var err = [];
    $('.fld-err').removeClass('fld-err');
    if (!$('#eStage').val()) { err.push('請選擇階段'); $('#eStage').addClass('fld-err'); }
    if ($('#eScope').val() === 'part' && !$('#ePartId').val()) { err.push('請從清單點選料號（不能只打字）'); $('#ePart').addClass('fld-err'); }
    if ($('#eScope').val() === 'family' && !$('#eFamily').val().trim()) { err.push('請填寫產品族名稱'); $('#eFamily').addClass('fld-err'); }
    var fd = $('#eFormDate').val();
    if (!fd) { err.push('請填寫表單日期'); $('#eFormDate').addClass('fld-err'); }
    else if (fd > new Date().toISOString().substring(0,10)) { err.push('表單日期不可以是未來日期'); $('#eFormDate').addClass('fld-err'); }
    return err;
}
$('#btnSave').on('click', function(){
    var err = validateHead();
    if (err.length) { toast('無法儲存：\n・' + err.join('\n・'), true); return; }
    post('save', collectHead(), function(res){
        toast(res.message);
        openDoc(res.cp_id);
        loadList();
    });
});
$('#btnSubmit').on('click', function(){
    if (!DOC.cp_id) { toast('請先儲存。', true); return; }
    if (!confirm('送出後進入待核准，期間不可修改。確定送出？')) return;
    post('submit', { cp_id: DOC.cp_id }, function(res){ toast(res.message); openDoc(DOC.cp_id); loadList(); });
});
$('#btnApprove').on('click', function(){
    if (!confirm('確定核准這份管制計畫？\n核准後不可直接修改，要改需另外改版。')) return;
    post('approve', { cp_id: DOC.cp_id }, function(res){ toast(res.message); openDoc(DOC.cp_id); loadList(); });
});
$('#btnReject').on('click', function(){
    ask('退回管制計畫', '退回後回到草稿狀態，填寫人可以繼續修改。退回原因會記在版次履歷裡。', '請填寫退回原因（必填）', function(txt, done){
        if (!txt.trim()) { done('請填寫退回原因。'); return; }
        post('reject', { cp_id: DOC.cp_id, reason: txt }, function(res){
            closeMask('mAsk'); toast(res.message); openDoc(DOC.cp_id); loadList();
        });
    });
});
$('#btnRevise').on('click', function(){
    ask('建立新版次', '會把目前的內容整份複製成新版次（草稿），舊版保留不動。', '改版原因／變更說明（選填）', function(txt, done){
        post('revise', { cp_id: DOC.cp_id, note: txt }, function(res){
            closeMask('mAsk'); toast(res.message); openDoc(res.cp_id); loadList();
        });
    });
});

var askCb = null;
function ask(title, desc, ph, cb){
    $('#askTitle').html(esc(title) + ' <button class="m-x" data-close="mAsk">&times;</button>');
    if (desc) $('#askDesc').text(desc).show(); else $('#askDesc').hide();
    $('#askText').val('').attr('placeholder', ph || '');
    $('#askErr').hide(); askCb = cb; openMask('mAsk');
}
$('#askOk').on('click', function(){
    if (!askCb) return;
    askCb($('#askText').val(), function(e){ $('#askErr').text(e).show(); });
});

/* ────────────────── 自動完成：料號／訂單／量具 ────────────────── */
var acFor = null, acTimer = null;
function acShow($inp, rows, render, pick){
    var r = $inp[0].getBoundingClientRect();
    var h = '';
    rows.forEach(function(row, i){ h += '<div class="ac-item" data-i="'+i+'">' + render(row) + '</div>'; });
    if (!rows.length) h = '<div class="ac-item" style="color:#8a6d45;cursor:default;">查無資料</div>';
    // 位置用 fixed 由 JS 算：跳窗與捲動容器會把 absolute 的清單整個裁掉（記憶 eng_log 的坑）
    $('#acList').html(h).css({ left: r.left + 'px', top: (r.bottom + 2) + 'px', minWidth: Math.max(260, r.width) + 'px' }).addClass('on');
    acFor = { rows: rows, pick: pick };
}
function acHide(){ $('#acList').removeClass('on'); acFor = null; }
$(document).on('click', '#acList .ac-item', function(){
    var i = $(this).data('i');
    if (acFor && typeof i !== 'undefined' && acFor.rows[i]) acFor.pick(acFor.rows[i]);
    acHide();
});
$(document).on('click', function(e){
    if (!$(e.target).closest('#acList').length && !$(e.target).is('input')) acHide();
});

function bindPartAc($inp, onPick){
    $inp.on('input', function(){
        var kw = $(this).val().trim(), $me = $(this);
        clearTimeout(acTimer);
        if (!kw) { acHide(); return; }
        acTimer = setTimeout(function(){
            get('search_part', { kw: kw }, function(res){
                acShow($me, res.rows || [], function(r){
                    return '<b>'+esc(r.D_Setting_Id)+'</b>'
                         + (r.Revision ? ' <small>Rev.'+esc(r.Revision)+'</small>' : '')
                         + (r.customer ? ' <small>｜'+esc(r.customer)+'</small>' : '')
                         + (r.Drawing_No ? ' <small>｜圖號 '+esc(r.Drawing_No)+'</small>' : '')
                         + ' <small>#'+r.d_id+'</small>';
                }, onPick);
            });
        }, 300);
    });
}
bindPartAc($('#ePart'), function(r){
    $('#ePart').val(r.D_Setting_Id); $('#ePartId').val(r.d_id);
    if (!$('#eProdName').val()) $('#eProdName').val(r.Remark || '');
    if (!$('#ePartRev').val()) $('#ePartRev').val(r.Revision || '');
    $('#eCustName').val(r.customer || ''); $('#eCustId').val(r.Customer_Id || '');
    $('#ePartInfo').text('料號主檔 #' + r.d_id + (r.customer ? '（' + r.customer + '）' : ''));
});
bindPartAc($('#eFamAdd'), function(r){
    DOC.family_parts = DOC.family_parts || [];
    if (!DOC.family_parts.some(function(p){ return +p.part_d_id === +r.d_id; })) {
        DOC.family_parts.push({ part_d_id: r.d_id, part_no_text: r.D_Setting_Id, D_Setting_Id: r.D_Setting_Id });
    }
    $('#eFamAdd').val(''); renderFamily();
});

function bindOrderAc($inp, $hid, onPick){
    $inp.on('input', function(){
        var kw = $(this).val().trim(), $me = $(this);
        clearTimeout(acTimer);
        if (!kw) { acHide(); return; }
        acTimer = setTimeout(function(){
            get('search_order', { kw: kw }, function(res){
                acShow($me, res.rows || [], function(r){
                    return '<b>'+esc(r.Order_oo)+'</b> <small>'+esc(r.d_id)+'</small>'
                         + ' <small>｜'+esc(r.Client_name)+'</small>'
                         + ' <small>｜'+esc(r.Qty)+' 支｜'+dispDate(r.Order_date)+'</small>';
                }, function(r){ $me.val(r.Order_oo); $hid.val(r.Order_id); if (onPick) onPick(r); });
            });
        }, 300);
    });
}
bindOrderAc($('#eOrder'), $('#eOrderId'), function(r){
    get('order_boms', { order_id: r.Order_id }, function(res){
        var o = '<option value="">（未指定）</option>';
        (res.boms || []).forEach(function(b){
            o += '<option value="'+esc(b.bom)+'">'+esc(b.bom)+'（製程 '+(b.proc_count||0)+' 道'
               + (b.open_date ? '，' + dispDate(b.open_date) : '') + '）</option>';
        });
        $('#eBom').html(o);
    });
});
bindOrderAc($('#aOrder'), $('#aOrderId'), function(r){
    showOrderTag(r.Order_id, $('#aTagBox'));
    get('order_boms', { order_id: r.Order_id }, function(res){
        var boms = res.boms || [];
        if (boms.length > 1) {
            var o = '';
            boms.forEach(function(b){
                o += '<option value="'+esc(b.bom)+'">'+esc(b.bom)+'（製程 '+(b.proc_count||0)+' 道'
                   + (b.open_date ? '，' + dispDate(b.open_date) : '') + '）</option>';
            });
            $('#aBom').html(o).show();
            toast('這張訂單綁了 ' + boms.length + ' 張製令，請選定製程來源。');
        } else {
            $('#aBom').hide().html(boms.length ? '<option value="'+esc(boms[0].bom)+'">'+esc(boms[0].bom)+'</option>' : '');
        }
    });
});

/* 量具挑選（走共用 qc_tool_pick_rows，顯示字串由後端算） */
$(document).on('input', '.tool-pick', function(){
    var $me = $(this), kw = $me.val().trim();
    clearTimeout(acTimer);
    acTimer = setTimeout(function(){
        get('search_tool', { kw: kw, asof: $('#eFormDate').val() || '' }, function(res){
            acShow($me, res.rows || [], function(r){
                return '<b>'+esc(r.Tool_No)+'</b> <small>'+esc(r.disp || r.spec_desc || '')+'</small>'
                     + (r.state && +r.state === 1 ? ' <small style="color:#8c2d18;">（已停用）</small>' : '');
            }, function(r){
                var pi = +$me.data('pi'), ii = +$me.data('ii');
                $me.val(r.Tool_No);
                $me.siblings('[data-if=tool_id]').val(r.Tool_id);
                if (DOC && DOC.processes[pi] && DOC.processes[pi].items[ii]) {
                    DOC.processes[pi].items[ii].tool_no = r.Tool_No;
                    DOC.processes[pi].items[ii].tool_id = r.Tool_id;
                }
            });
        });
    }, 300);
});

/* ────────────────── 分頁三：自動帶入／建議建立 ────────────────── */
function renderAutoNote(){
    var h = '';
    if (TAGSTATUS.ready) {
        h += '<b>哪些訂單需要 CP</b>：訂單追蹤掛了<b>「稽核製程」標籤</b>的訂單'
          +  '（目前 ' + (TAGSTATUS.n_tag||0) + ' 個稽核製程、' + (TAGSTATUS.n_order||0) + ' 張訂單、'
          +  (TAGSTATUS.n_part||0) + ' 個料號）。挑到訂單後會直接告訴你這張需不需要。<br>';
    } else {
        h += '<div style="color:#8c2d18;margin-bottom:6px;">' + esc(TAGSTATUS.reason || '') + '</div>';
    }
    h += '<b>資料從哪裡來</b>：製程列＝該訂單綁定的<b>製令</b>的製程鏈（依製程序）；'
      +  '產品特性／規格公差／檢驗方法／檢具編號／頻率＝該料號該製程的 <b>SIP</b>（只取已核准版次），'
      +  '找不到時退回該製程的<b>檢驗項目預設值範本</b>；<b>分類（特殊分類）／管制方法一律留白由人逐列填</b>'
      +  '（PFMEA 是製程粒度、不是逐特性記錄，自動套用會出現語意錯誤，整理過的 PFMEA 參考改顯示在每道製程下方）。'
      +  '機台是從<b>報工紀錄</b>抓該製程實際用過的機台。帶不出來的欄位會標示要人工填，不會假裝有資料。';
    $('#autoNote').html(h);
}

/* 挑到訂單就即時顯示「這張需不需要 CP」——不必等按預覽 */
function showOrderTag(orderId, $box){
    if (!orderId) { $box.html(''); return; }
    get('order_as_tag', { order_id: orderId }, function(res){
        var t = res.as_tag || {};
        if (t.need_cp) {
            $box.html('<span class="tag-s st-approved">需要 CP</span> '
                + '<span class="tag-s sg-3">' + esc(t.label) + '</span> '
                + '<span class="muted">' + esc(t.scope_hint || '') + '</span>');
        } else {
            $box.html('<span class="tag-s" style="background:#FBE3DD;color:#8c2d18;border-color:#DD5138;">'
                + '依認定不需要 CP</span> <span class="muted">' + esc(t.reason || '') + '</span>');
        }
    });
}
$('#btnPreview').on('click', function(){
    var oid = +$('#aOrderId').val() || 0;
    if (!oid) { toast('請先從清單點選一張訂單。', true); return; }
    var bom = $('#aBom').is(':visible') ? ($('#aBom').val() || '') : ($('#aBom').find('option').first().val() || '');
    get('autofill', { order_id: oid, bom: bom, stage_id: $('#aStage').val() || 0 }, function(res){
        PREVIEW = res; renderPreview(res);
    });
});
$(document).on('click', '#btnCopyFromCp', function(){
    var srcId = +$(this).data('srccpid');
    if (!srcId || !PREVIEW) return;
    get('copy_from_cp', { src_cp_id: srcId }, function(res){
        PREVIEW.processes = res.processes || PREVIEW.processes;
        renderPreview(PREVIEW);
        toast('已複製該份管制計畫的內容，確認無誤後按「用這些內容建立管制計畫」。');
    });
});
function renderPreview(r){
    var h = '';
    (r.warn || []).forEach(function(w){ h += '<div class="cp-note warn">'+esc(w)+'</div>'; });
    (r.info || []).forEach(function(w){ h += '<div class="cp-note">'+esc(w)+'</div>'; });

    if (r.bom_choices && r.bom_choices.length > 1) {
        h += '<div class="cp-note">這張訂單綁了 '+r.bom_choices.length+' 張製令：';
        r.bom_choices.forEach(function(b){
            h += '<button class="btn-w2 btn-xs2" data-pickbom="'+esc(b.bom)+'" style="margin:2px;">'
               + esc(b.bom) + '（' + (b.proc_count||0) + ' 道）</button>';
        });
        h += ' <span class="muted">製程可能不同，請點選要用哪一張</span></div>';
    }

    var hd = r.head || {}, src = r.src || {};
    h += '<div class="cp-note"><b>將建立</b>：'
      +  '料號 <b>'+esc(hd.part_no_text||'')+'</b>'
      +  (hd.part_rev ? '（Rev.'+esc(hd.part_rev)+'）' : '')
      +  '　客戶 '+esc(hd.customer_name||'—')
      +  '　階段 <b>'+esc(src.stage_name||'—')+'</b>'
      +  '　來源 訂單 '+esc(src.order_oo||'—')+' ／ 製令 '+esc(src.bom||'—')
      +  (src.bom_date ? '（'+dispDate(src.bom_date)+'）' : '')
      +  '　PFMEA '+(hd.pfmea_doc_id ? ('#'+hd.pfmea_doc_id) : '<span style="color:#8c2d18;">無</span>')
      +  '</div>';

    /* 同料號若已有一份製程鏈完全相同的既有CP，提供「複製」——那份的管制方法／特殊分類
       是人工確認過的內容，比這裡自動帶入永遠留白再手動填一次更有效率（見
       cp_find_similar_cp 說明），兩條路並存，使用者自己選。 */
    if (r.similar_cp) {
        var sc = r.similar_cp;
        h += '<div class="cp-note" style="background:#F7E0BD;border-color:#e0c79a;color:#6b4a28;">'
          +  '<b>這個料號已有一份製程完全相同的管制計畫</b>：'+esc(sc.cp_no||('#'+sc.cp_id))
          +  '　階段 '+esc(sc.stage_name||'—')+'　狀態 '+esc(sc.status_label||sc.status)
          +  '　更新於 '+dispDate(sc.updated_at)
          +  '　<button class="btn-w2 btn-xs2" id="btnCopyFromCp" data-srccpid="'+sc.cp_id+'">'
          +  '<i class="fa fa-copy"></i> 複製這份內容（含已確認的管制方法／特殊分類）</button></div>';
    }

    var procs = r.processes || [], nItem = 0;
    procs.forEach(function(p){ nItem += (p.items||[]).length; });

    if (!procs.length) {
        h += '<div class="cp-note warn">帶不出任何製程，無法建立。請確認這張訂單有綁定製令、且該製令有製程資料。</div>';
        $('#prevWrap').html(h); return;
    }

    h += '<div class="cp-toolbar"><b style="color:#8A5A2B;">帶入預覽：'+procs.length+' 道製程、'+nItem+' 列特性</b>'
      +  (CANEDIT ? '<button class="btn-w" id="btnCreateFromPrev" style="margin-left:auto;"><i class="fa fa-check"></i> 用這些內容建立管制計畫</button>' : '')
      +  '</div>';

    // 「特殊分類」「管制方法」兩欄下面表格一律空白（2026-10-06 起不再自動套用，見下方說明），
    // PFMEA 整理過的參考資訊改在這裡逐製程列出，供建立前先核對。
    procs.forEach(function(p){
        if (p.pfmea_note) h += '<div class="cp-note" style="white-space:pre-line;background:#F7E0BD;border-color:#e0c79a;color:#6b4a28;">'
            + '<b>'+esc(p.process_name||('製程'+p.process_no))+'：</b>'+esc(p.pfmea_note)+'</div>';
    });

    h += '<div class="cp-scroll"><table class="cp-t" style="min-width:1200px;"><thead><tr>'
      +  '<th style="width:40px;">#</th><th style="width:120px;">製程</th><th style="width:120px;">機器／廠商</th>'
      +  '<th style="width:150px;">產品特性</th><th style="width:88px;">分類</th><th style="width:160px;">規格／公差</th>'
      +  '<th style="width:120px;">檢驗方法</th><th style="width:96px;">檢具編號</th><th style="width:120px;">頻率</th>'
      +  '<th>管制方法</th></tr></thead><tbody>';
    procs.forEach(function(p){
        var items = p.items || [];
        if (!items.length) {
            h += '<tr><td style="text-align:center;">'+p.seq+'</td>'
              +  '<td><b>'+esc(p.process_name||('製程'+p.process_no))+'</b>'
              +  (p.process_no?'<span class="src-tag">#'+p.process_no+'</span>':'') + inspBadge(p.insp_stage, p.insp_gap, hd.part_no_text) + '</td>'
              +  '<td>'+esc(p.machine||'—')+(p.maker_name?'<div class="muted">'+esc(p.maker_name)+'</div>':'')+'</td>'
              +  '<td colspan="7" style="color:#8c2d18;">'+esc(p.hint||'查不到檢驗項目，要人工填')+'</td></tr>';
            return;
        }
        items.forEach(function(it, ii){
            h += '<tr>';
            if (ii === 0) {
                h += '<td rowspan="'+items.length+'" style="text-align:center;">'+p.seq+'</td>'
                  +  '<td rowspan="'+items.length+'"><b>'+esc(p.process_name||('製程'+p.process_no))+'</b>'
                  +  (p.process_no?'<span class="src-tag">#'+p.process_no+'</span>':'')
                  +  inspBadge(p.insp_stage, p.insp_gap, hd.part_no_text)
                  +  (+p.is_outsource?'<div><span class="tag-s sg-2">委外</span></div>':'')+'</td>'
                  +  '<td rowspan="'+items.length+'">'+esc(p.machine||'—')
                  +  (p.maker_name?'<div class="muted">'+esc(p.maker_name)+'</div>':'')+'</td>';
            }
            h += '<td>'+esc(it.char_product||'')+'<span class="src-tag">'+esc(itemSrcLabel(it.src))+'</span></td>'
              +  '<td>'+esc(it.special_class_text||'—')+'</td>'
              +  '<td>'+esc(it.spec_text||'—')+'</td>'
              +  '<td>'+esc(it.eval_method||'—')+'</td>'
              +  '<td>'+esc(it.tool_no||'—')+'</td>'
              +  '<td>'+esc(it.sample_freq||'<span style="color:#8c2d18;">未設</span>')+'</td>'
              +  '<td style="font-size:12px;white-space:pre-line;">'+esc(it.control_method||'—')+'</td></tr>';
        });
    });
    h += '</tbody></table></div>';
    $('#prevWrap').html(h);
}
$(document).on('click', '[data-pickbom]', function(){
    $('#aBom').val($(this).data('pickbom'));
    if (!$('#aBom').find('option[value="'+$(this).data('pickbom')+'"]').length) {
        $('#aBom').append('<option value="'+$(this).data('pickbom')+'" selected>'+$(this).data('pickbom')+'</option>');
    }
    $('#btnPreview').trigger('click');
});
function mapPrevProc(p){
    // op_desc 要帶後端算好的真值（PFMEA 製程功能/要求），寫死掉等於白算了
    // （2026-10-02 修正，原本遺漏）。special_class_id／control_method 2026-10-06 起
    // 改為一律留白由人填，不再從 PFMEA 自動帶（見 control_plan_lib.php 說明），
    // pfmea_note 是製程層級的 PFMEA 參考文字，供人逐列判斷用、本身不寫進任何欄位。
    return { process_no: p.process_no, process_name: p.process_name, op_desc: p.op_desc || '',
             machine: p.machine, jig_tool: p.jig_tool || '', maker_id_no: p.maker_id_no, maker_name: p.maker_name,
             is_outsource: p.is_outsource, insp_stage: p.insp_stage || '', insp_src: p.insp_src || '', insp_gap: p.insp_gap || '',
             bom_sn: p.bom_sn, src: p.src || 'bom', note: '',
             hint: p.hint || '', pfmea_note: p.pfmea_note || '', items: (p.items || []).map(function(it){
                 return { char_no: it.char_no, char_product: it.char_product, char_process: it.char_process,
                          special_class_id: it.special_class_id || null, special_class_text: it.special_class_text,
                          spec_text: it.spec_text, up_limit: it.up_limit, lo_limit: it.lo_limit,
                          eval_method: it.eval_method, tool_id: it.tool_id, tool_no: it.tool_no,
                          sample_size: it.sample_size, sample_freq: it.sample_freq,
                          control_method: it.control_method, reaction_plan: it.reaction_plan,
                          src: it.src, src_ref: it.src_ref, note: it.note };
             }) };
}
$(document).on('click', '#btnCreateFromPrev', function(){
    if (!PREVIEW) return;
    var hd = PREVIEW.head || {}, src = PREVIEW.src || {};
    DOC = {
        cp_id: 0, stage_id: src.stage_id || $('#aStage').val(), scope: 'part',
        part_d_id: hd.part_d_id, part_no_text: hd.part_no_text, product_name: hd.product_name,
        customer_id: hd.customer_id, customer_name: hd.customer_name, part_rev: hd.part_rev,
        ver_no: 'A', status: 'draft', form_date: new Date().toISOString().substring(0,10),
        src_order_id: src.order_id, src_order_oo: src.order_oo,
        src_bom: src.bom, src_bom_date: src.bom_date, pfmea_doc_id: hd.pfmea_doc_id,
        processes: (PREVIEW.processes || []).map(mapPrevProc), family_parts: []
    };
    renderEdit();
    // 製令下拉要有這一筆才存得進去
    if (DOC.src_bom) $('#eBom').html('<option value="'+esc(DOC.src_bom)+'" selected>'+esc(DOC.src_bom)+'</option>');
    gotoPane('edit');
    toast('已帶入，請確認內容後按「儲存」。');
});

/* 建議建立清單：先載入第一頁、其餘背景載入（ai-rules/08 資料列表規則），
   不要讓使用者點進這個分頁還要再按一次「重新整理」才看得到東西。 */
$('#btnSug').on('click', loadSug);
$('#sStage').on('change', loadSug);
var SUG_ROWS = [], SUG_PAGE = 1, SUG_PER = 20, SUG_TOTAL = 0, SUG_LOADING_MORE = false;
function loadSug(){
    var stageId = $('#sStage').val() || 0;
    SUG_LOADING_MORE = false;
    get('suggest', { stage_id: stageId, per: SUG_PER, page: 1 }, function(res){
        $('#sugMode').text(res.note || '');
        SUG_ROWS = res.rows || []; SUG_PAGE = 1;
        SUG_TOTAL = (res.total != null) ? res.total : SUG_ROWS.length;
        renderSugSummary(res.summary || {});
        renderSugPage();
        // 第一頁已經可以看、可以操作了，其餘筆數背景補齊（同一個 stage_id，不帶 per 就是全部）
        if (SUG_TOTAL > SUG_ROWS.length) {
            SUG_LOADING_MORE = true;
            renderSugPage();
            get('suggest', { stage_id: stageId }, function(res2){
                if (($('#sStage').val() || 0) != stageId) return;   // 期間篩選條件被使用者換掉了，這批結果不要蓋上去
                SUG_ROWS = res2.rows || []; SUG_TOTAL = SUG_ROWS.length; SUG_LOADING_MORE = false;
                renderSugPage();
            });
        }
    });
}
function renderSugSummary(s){
    if (!s.total) { $('#sugSummary').hide(); return; }
    /* 848 筆清單若不講清楚「有多少帶得出東西」，使用者不知道從哪裡開始。
       而且「有 SIP 但還是草稿」要單獨講——那是一個可以馬上行動的提示。 */
    var h = '<b>共 ' + s.total + ' 個料號需要建管制計畫</b>（已建好的不再列出）。'
          + '自動帶入目前能帶出：製程列 <b>' + (s.with_proc||0) + '</b> 個料號、'
          + '管制方法與特殊特性（PFMEA）<b>' + (s.with_pfmea||0) + '</b> 個、'
          + '規格公差與檢驗方法（已核准 SIP）<b>' + (s.with_sip||0) + '</b> 個。';
    if (s.with_sip_draft) {
        h += '<div style="margin-top:5px;color:#8c2d18;">另有 <b>' + s.with_sip_draft
           + '</b> 個料號的 SIP 還是草稿——<b>把它核准之後，規格公差與檢驗方法就帶得出來了</b>'
           + '（自動帶入只取已核准版次，草稿的公差不該印在管制計畫上）。</div>';
    }
    if (!s.with_sip) {
        h += '<div style="margin-top:5px;">目前沒有任何需要 CP 的料號有已核准的 SIP，'
           + '所以規格公差／檢驗方法／頻率這幾欄要人工填。這不是系統問題，是那些料號的 SIP 還沒建或還沒核准。</div>';
    }
    $('#sugSummary').html(h).show();
}
function renderSugPage(){
    var rows = SUG_ROWS, tb = '';
    if (!SUG_TOTAL) {
        $('#tSug tbody').html('<tr><td colspan="9" style="text-align:center;color:#8a6d45;padding:16px;">沒有需要建立的項目</td></tr>');
        $('#pgSug').html(''); return;
    }
    var st = (SUG_PAGE - 1) * SUG_PER;
    var pageRows = rows.slice(st, st + SUG_PER);
    if (!pageRows.length && SUG_LOADING_MORE) {
        // 背景還在補齊全部筆數，使用者已經先翻到還沒載到的那一頁——一瞬間的事，不要顯示空白表格
        $('#tSug tbody').html('<tr><td colspan="9" style="text-align:center;color:#8a6d45;padding:16px;">載入中…</td></tr>');
        renderSugPager();
        return;
    }
    pageRows.forEach(function(r){
        var have = (r.have_stages || []).map(function(sid){
            var s = STAGES.filter(function(x){ return +x.stage_id === +sid; })[0];
            return s ? '<span class="tag-s sg-'+sid+'">'+esc(s.stage_name)+'</span>' : '';
        }).join(' ');
        // 「自動帶得出什麼」：用籤直接講，不要只印有/無讓使用者自己猜
        var rd = '';
        rd += r.n_proc ? '<span class="tag-s st-approved">製程 '+r.n_proc+' 道</span> '
                       : '<span class="tag-s" style="background:#FBE3DD;color:#8c2d18;border-color:#DD5138;">無製令</span> ';
        rd += r.pfmea_doc_id ? '<span class="tag-s st-approved">PFMEA</span> '
                             : '<span class="tag-s st-draft">無 PFMEA</span> ';
        rd += r.has_sip ? '<span class="tag-s st-approved">SIP</span>'
                        : (r.sip_draft ? '<span class="tag-s st-submitted">SIP 待核准</span>'
                                       : '<span class="tag-s st-draft">無 SIP</span>');
        tb += '<tr>'
           + '<td><b>'+esc(r.part_no_text)+'</b> <span class="muted">#'+r.part_d_id+'</span></td>'
           + '<td>'+esc(r.product_name||'')+'</td>'
           + '<td>'+esc(r.customer_name||'')+'</td>'
           + '<td style="text-align:center;">'+esc(r.part_rev||'')+'</td>'
           + '<td>'+(r.as_tag_label ? '<span class="tag-s sg-3">'+esc(r.as_tag_label)+'</span>' : '<span class="muted">—</span>')+'</td>'
           + '<td>'+esc(r.order_oo||'—')
           +   (r.n_order > 1 ? '<div class="muted">共 '+r.n_order+' 張</div>' : '')+'</td>'
           + '<td>'+rd+'</td>'
           + '<td>'+(have||'<span class="muted">—</span>')+'</td>'
           + '<td>'
           + (r.order_id ? '<button class="btn-w2 btn-xs2" data-sugord="'+r.order_id+'" data-sugoo="'+esc(r.order_oo||'')+'"><i class="fa fa-magic"></i> 帶入</button> ' : '')
           + '<button class="btn-w2 btn-xs2" data-sugpart="'+r.part_d_id+'" data-sugpn="'+esc(r.part_no_text)+'"><i class="fa fa-plus"></i> 建空白</button> '
           + '<button class="btn-w2 btn-xs2" data-sugign="'+r.part_d_id+'" style="border-color:#DD5138;color:#8c2d18;" title="加入忽略名單"><i class="fa fa-eye-slash"></i></button>'
           + '</td></tr>';
    });
    $('#tSug tbody').html(tb);
    renderSugPager();
}
function renderSugPager(){
    var pages = Math.max(1, Math.ceil(SUG_TOTAL / SUG_PER)), h = '';
    h += '<span class="muted">共 '+SUG_TOTAL+' 筆 / '+pages+' 頁（依「自動帶得出多少」排序，帶得出來的在前）</span>';
    if (SUG_LOADING_MORE) h += ' <span class="muted"><i class="fa fa-spinner fa-spin"></i> 其餘筆數背景載入中…</span>';
    if (pages > 1) {
        h += ' <button data-sugpg="1">&laquo;</button>';
        var s0 = Math.max(1, SUG_PAGE - 2), e0 = Math.min(pages, s0 + 4);
        for (var i = s0; i <= e0; i++) h += ' <button data-sugpg="'+i+'"'+(i===SUG_PAGE?' class="on"':'')+'>'+i+'</button>';
        h += ' <button data-sugpg="'+pages+'">&raquo;</button>';
    }
    $('#pgSug').html(h);
}
$(document).on('click', '#pgSug button', function(){ SUG_PAGE = +$(this).data('sugpg'); renderSugPage(); });
$(document).on('click', '[data-sugord]', function(){
    $('#aOrderId').val($(this).data('sugord'));
    $('#aOrder').val($(this).data('sugoo'));
    // 從建議清單按「帶入」是另一條路徑，這裡也要顯示即時判定
    // （漏掉的話使用者從清單進來就看不到那一行，只有手動打字才看得到）
    showOrderTag($(this).data('sugord'), $('#aTagBox'));
    get('order_boms', { order_id: $(this).data('sugord') }, function(res){
        var boms = res.boms || [], o = '';
        boms.forEach(function(b){ o += '<option value="'+esc(b.bom)+'">'+esc(b.bom)+'（'+(b.proc_count||0)+' 道）</option>'; });
        $('#aBom').html(o).toggle(boms.length > 1);
        $('#btnPreview').trigger('click');
        $('html,body').animate({ scrollTop: $('#prevWrap').offset().top - 70 }, 250);
    });
});
$(document).on('click', '[data-sugpart]', function(){
    var pid = +$(this).data('sugpart'), pn = $(this).data('sugpn');
    DOC = { cp_id: 0, stage_id: $('#sStage').val() || (STAGES.filter(function(s){return +s.is_active===1;})[0]||{}).stage_id,
            scope: 'part', part_d_id: pid, part_no_text: pn, ver_no: 'A', status: 'draft',
            form_date: new Date().toISOString().substring(0,10), processes: [], family_parts: [] };
    renderEdit(); gotoPane('edit');
});
$(document).on('click', '[data-sugign]', function(){
    var pid = +$(this).data('sugign');
    ask('加入忽略名單', '這個料號之後不會再出現在建議建立清單裡（可以在「忽略名單」移出）。', '原因（選填）', function(txt){
        post('suggest_ignore', { part_d_id: pid, stage_id: $('#sStage').val() || 0, reason: txt }, function(res){
            closeMask('mAsk'); toast(res.message); loadSug();
        });
    });
});
$('#btnIgnoredList').on('click', function(){
    get('suggest_ignored_list', {}, function(res){
        var rows = res.rows || [], h = '';
        if (!rows.length) h = '<div class="muted">忽略名單是空的</div>';
        else {
            h = '<table class="cp-t"><thead><tr><th>料號</th><th style="width:110px;">階段</th><th>原因</th>'
              + '<th style="width:110px;">設定者</th><th style="width:70px;"></th></tr></thead><tbody>';
            rows.forEach(function(r){
                h += '<tr><td>'+esc(r.D_Setting_Id || ('#'+r.part_d_id))+'</td>'
                  +  '<td>'+esc(r.stage_name || '全部')+'</td>'
                  +  '<td>'+esc(r.reason||'')+'</td>'
                  +  '<td>'+esc(r.user_cname||'')+'</td>'
                  +  '<td><button class="btn-w2 btn-xs2" data-unign="'+r.part_d_id+'" data-unst="'+(r.stage_id||0)+'">移出</button></td></tr>';
            });
            h += '</tbody></table>';
        }
        $('#ignRows').html(h); openMask('mIgn');
    });
});
$(document).on('click', '[data-unign]', function(){
    post('suggest_unignore', { part_d_id: +$(this).data('unign'), stage_id: +$(this).data('unst') }, function(res){
        toast(res.message); $('#btnIgnoredList').trigger('click'); loadSug();
    });
});

/* ────────────────── 分頁四：設定 ────────────────── */
function renderCfg(){
    if (!CANADMIN) return;
    var h = '';
    STAGES.forEach(function(s){
        h += '<tr data-sid="'+s.stage_id+'">'
          +  '<td><code>'+esc(s.stage_code)+'</code></td>'
          +  '<td><input type="text" data-sf="stage_name" value="'+esc(s.stage_name)+'" style="width:100%;height:26px;font-size:13px;"></td>'
          +  '<td><input type="text" data-sf="default_freq" value="'+esc(s.default_freq||'')+'" style="width:100%;height:26px;font-size:13px;" data-eg-hint="這一段的預設樣本或頻率，自動帶入時會優先用它（例：100% 全尺寸）"></td>'
          +  '<td><input type="text" data-sf="note" value="'+esc(s.note||'')+'" style="width:100%;height:26px;font-size:13px;"></td>'
          +  '<td><input type="number" data-sf="sort_order" value="'+(s.sort_order||0)+'" style="width:56px;height:26px;font-size:13px;"></td>'
          +  '<td style="text-align:center;"><input type="checkbox" data-sf="is_active"'+(+s.is_active===1?' checked':'')+'></td>'
          +  '</tr>';
    });
    $('#tStage tbody').html(h);

    h = '';
    CLASSES.forEach(function(c){ h += clsRow(c); });
    $('#tClass tbody').html(h || clsRow({}));

    h = '';
    REACTS.forEach(function(o){ h += reactRow(o); });
    $('#tReact tbody').html(h || reactRow({}));

    h = '';
    CTRL_METHODS.forEach(function(o){ h += ctrlMethodRow(o); });
    $('#tCtrlMethod tbody').html(h || ctrlMethodRow({}));

    renderCodeChips('#kgCodeList', KGCODES, true);
    renderCodeChips('#packCodeList', PACKCODES, true);
    $('#fqcThreshold').val(FQC_THRESHOLD);
    renderTagCfg();
}

/* 製程代號清單：唯讀標籤式 chip（客供料／包裝，兩者都改自動判定，這裡只是顯示目前認定結果） */
function renderCodeChips(sel, list){
    var h = '';
    (list || []).forEach(function(c){
        h += '<span class="tag-s sg-1" style="margin:2px 4px 2px 0;font-size:12px;line-height:18px;padding:2px 8px;">'
           + '#'+esc(c.no)+' '+esc(c.name||'') + '</span>';
    });
    $(sel).html(h || '<span class="muted">尚未設定</span>');
}
$('#btnSaveFqcThreshold').on('click', function(){
    var v = +$('#fqcThreshold').val() || 0;
    if (v <= 0) { toast('門檻需為正整數。', true); return; }
    post('fqc_threshold_save', { threshold: v }, function(res){
        FQC_THRESHOLD = res.fqc_threshold || v;
        $('#fqcThreshold').val(FQC_THRESHOLD);
        toast('已儲存。');
    });
});
function clsRow(c){
    // 嚴重度/發生率門檻：留空＝那個條件不比對（存 NULL）。範圍輸入框一律 1~10（S/O 量表上限）。
    function rangeInput(f1, f2, v1, v2) {
        return '<div style="display:flex;gap:3px;align-items:center;">'
          + '<input type="number" min="1" max="10" data-cf="'+f1+'" value="'+(v1==null?'':v1)+'" placeholder="下限" style="width:54px;height:26px;font-size:12px;">'
          + '<span class="muted">~</span>'
          + '<input type="number" min="1" max="10" data-cf="'+f2+'" value="'+(v2==null?'':v2)+'" placeholder="上限" style="width:54px;height:26px;font-size:12px;">'
          + '</div>';
    }
    return '<tr data-cid="'+(c.class_id||'')+'">'
      + '<td><input type="text" data-cf="symbol" value="'+esc(c.symbol||'')+'" style="width:100%;height:26px;font-size:13px;text-align:center;"></td>'
      + '<td><input type="text" data-cf="class_name" value="'+esc(c.class_name||'')+'" style="width:100%;height:26px;font-size:13px;"></td>'
      + '<td><input type="text" data-cf="note" value="'+esc(c.note||'')+'" style="width:100%;height:26px;font-size:13px;"></td>'
      + '<td>'+rangeInput('sev_min','sev_max',c.sev_min,c.sev_max)+'</td>'
      + '<td>'+rangeInput('occ_min','occ_max',c.occ_min,c.occ_max)+'</td>'
      + '<td><input type="number" data-cf="sort_order" value="'+(c.sort_order||0)+'" style="width:56px;height:26px;font-size:13px;"></td>'
      + '<td style="text-align:center;"><input type="checkbox" data-cf="is_active"'+(c.class_id?(+c.is_active===1?' checked':''):' checked')+'></td>'
      + '<td style="text-align:center;"><a href="#" class="rowdel" style="color:#8c2d18;"><i class="fa fa-times"></i></a></td></tr>';
}
function reactRow(o){
    return '<tr data-oid="'+(o.opt_id||'')+'">'
      + '<td><input type="text" data-rf="opt_text" value="'+esc(o.opt_text||'')+'" style="width:100%;height:26px;font-size:13px;"></td>'
      + '<td><input type="number" data-rf="sort_order" value="'+(o.sort_order||0)+'" style="width:56px;height:26px;font-size:13px;"></td>'
      + '<td style="text-align:center;"><input type="checkbox" data-rf="is_active"'+(o.opt_id?(+o.is_active===1?' checked':''):' checked')+'></td>'
      // 預設只能一個，所以用 radio（同名互斥），不用 checkbox
      + '<td style="text-align:center;"><input type="radio" name="reactDef" data-rf="is_default"'+(+o.is_default===1?' checked':'')+'></td>'
      + '<td style="text-align:center;"><a href="#" class="rowdel" style="color:#8c2d18;"><i class="fa fa-times"></i></a></td></tr>';
}
function ctrlMethodRow(o){
    return '<tr data-cmid="'+(o.id||'')+'">'
      + '<td><input type="text" data-cmf="match_text" value="'+esc(o.match_text||'')+'" style="width:100%;height:26px;font-size:13px;" placeholder="例：外觀"></td>'
      + '<td><textarea data-cmf="control_method" style="width:100%;min-height:26px;height:46px;font-size:13px;padding:2px 4px;border:1px solid #d8c3a0;border-radius:3px;">'+esc(o.control_method||'')+'</textarea></td>'
      + '<td><input type="number" data-cmf="sort_order" value="'+(o.sort_order||0)+'" style="width:56px;height:26px;font-size:13px;"></td>'
      + '<td style="text-align:center;"><input type="checkbox" data-cmf="is_active"'+(o.id?(+o.is_active===1?' checked':''):' checked')+'></td>'
      + '<td style="text-align:center;"><a href="#" class="rowdel" style="color:#8c2d18;"><i class="fa fa-times"></i></a></td></tr>';
}
/* 可增列表格一律走共用檔的 data-eg-row-add/del（UI 規範，不自刻鍵盤邏輯） */
function clsAdd($tb){ $tb = $tb && $tb.length ? $tb : $('#tClass tbody'); $tb.append(clsRow({})); }
function clsDel($tr){ $tr = $tr && $tr.length ? $tr : $('#tClass tbody tr:last'); if ($('#tClass tbody tr').length > 1) $tr.remove(); }
function reactAdd($tb){ $tb = $tb && $tb.length ? $tb : $('#tReact tbody'); $tb.append(reactRow({})); }
function reactDel($tr){ $tr = $tr && $tr.length ? $tr : $('#tReact tbody tr:last'); if ($('#tReact tbody tr').length > 1) $tr.remove(); }
function ctrlMethodAdd($tb){ $tb = $tb && $tb.length ? $tb : $('#tCtrlMethod tbody'); $tb.append(ctrlMethodRow({})); }
function ctrlMethodDel($tr){ $tr = $tr && $tr.length ? $tr : $('#tCtrlMethod tbody tr:last'); if ($('#tCtrlMethod tbody tr').length > 1) $tr.remove(); }
window.clsAdd = clsAdd; window.clsDel = clsDel; window.reactAdd = reactAdd; window.reactDel = reactDel;
window.ctrlMethodAdd = ctrlMethodAdd; window.ctrlMethodDel = ctrlMethodDel;
$('#btnAddClass').on('click', function(){ clsAdd(); });
$('#btnAddReact').on('click', function(){ reactAdd(); });
$('#btnAddCtrlMethod').on('click', function(){ ctrlMethodAdd(); });
$(document).on('click', '.rowdel', function(e){
    e.preventDefault();
    var $tb = $(this).closest('tbody');
    if ($tb.find('tr').length > 1) $(this).closest('tr').remove();
});

$('#btnSaveStage').on('click', function(){
    var rows = [];
    $('#tStage tbody tr').each(function(i){
        var $t = $(this);
        rows.push({ stage_id: $t.data('sid'),
            stage_name: $t.find('[data-sf=stage_name]').val(),
            default_freq: $t.find('[data-sf=default_freq]').val(),
            note: $t.find('[data-sf=note]').val(),
            sort_order: $t.find('[data-sf=sort_order]').val() || (i+1),
            is_active: $t.find('[data-sf=is_active]').is(':checked') ? 1 : 0 });
    });
    if (!rows.some(function(r){ return +r.is_active === 1; })) {
        toast('至少要有一個階段是啟用的，否則無法建立任何管制計畫。', true); return;
    }
    post('stage_save', { rows: JSON.stringify(rows) }, function(res){
        toast(res.message); STAGES = res.stages || STAGES; fillStageSelects(); renderCfg();
    });
});
$('#btnSaveClass').on('click', function(){
    var rows = [], numOrNull = function(v){ v = ($.trim(v)||''); return v === '' ? null : +v; };
    $('#tClass tbody tr').each(function(i){
        var $t = $(this), nm = $t.find('[data-cf=class_name]').val().trim();
        if (!nm) return;
        rows.push({ class_id: $t.data('cid') || 0, symbol: $t.find('[data-cf=symbol]').val(),
            class_name: nm, note: $t.find('[data-cf=note]').val(),
            sort_order: $t.find('[data-cf=sort_order]').val() || (i+1),
            is_active: $t.find('[data-cf=is_active]').is(':checked') ? 1 : 0,
            sev_min: numOrNull($t.find('[data-cf=sev_min]').val()), sev_max: numOrNull($t.find('[data-cf=sev_max]').val()),
            occ_min: numOrNull($t.find('[data-cf=occ_min]').val()), occ_max: numOrNull($t.find('[data-cf=occ_max]').val()) });
    });
    // 前端先擋一次（鐵律8，後端 excluded_as_tags_save 同款規則再擋一次）：下限不可大於上限
    for (var i = 0; i < rows.length; i++) {
        var r = rows[i];
        if ((r.sev_min != null && r.sev_max != null && r.sev_min > r.sev_max)
         || (r.occ_min != null && r.occ_max != null && r.occ_min > r.occ_max)) {
            toast('「' + r.class_name + '」的範圍下限不可大於上限。', true); return;
        }
    }
    post('special_class_save', { rows: JSON.stringify(rows) }, function(res){
        toast(res.message); CLASSES = res.rows || CLASSES; renderCfg();
    });
});
$('#btnSaveReact').on('click', function(){
    var rows = [];
    $('#tReact tbody tr').each(function(i){
        var $t = $(this), tx = $t.find('[data-rf=opt_text]').val().trim();
        if (!tx) return;
        rows.push({ opt_id: $t.data('oid') || 0, opt_text: tx,
            sort_order: $t.find('[data-rf=sort_order]').val() || (i+1),
            is_active: $t.find('[data-rf=is_active]').is(':checked') ? 1 : 0,
            is_default: $t.find('[data-rf=is_default]').is(':checked') ? 1 : 0 });
    });
    var defRow = rows.filter(function(r){ return +r.is_default === 1; })[0];
    if (defRow && +defRow.is_active !== 1) {
        toast('被勾為「預設」的那一列必須同時是啟用的，否則自動帶入會帶不出東西。', true); return;
    }
    post('reaction_opt_save', { rows: JSON.stringify(rows) }, function(res){
        toast(res.message); REACTS = res.rows || REACTS; renderCfg();
    });
});
$('#btnSaveCtrlMethod').on('click', function(){
    var rows = [];
    $('#tCtrlMethod tbody tr').each(function(i){
        var $t = $(this), mt = $t.find('[data-cmf=match_text]').val().trim(),
            cm = $t.find('[data-cmf=control_method]').val().trim();
        if (!mt || !cm) return;
        rows.push({ id: $t.data('cmid') || 0, match_text: mt, control_method: cm,
            sort_order: $t.find('[data-cmf=sort_order]').val() || (i+1),
            is_active: $t.find('[data-cmf=is_active]').is(':checked') ? 1 : 0 });
    });
    post('ctrl_method_default_save', { rows: JSON.stringify(rows) }, function(res){
        toast(res.message); CTRL_METHODS = res.rows || CTRL_METHODS; renderCfg();
    });
});

function renderTagCfg(){
    get('as_tag_list', {}, function(res){
        var rows = res.rows || [];
        if (!rows.length) {
            $('#tAsTag tbody').html('<tr><td colspan="8" style="text-align:center;color:#8c2d18;padding:14px;">'
              + '目前沒有任何「稽核製程」標籤定義。請先到<b>訂單追蹤 → 設定 → 稽核製程標籤</b>新增'
              + '（例：齒研、插齒），本頁才認定得出哪些訂單需要管制計畫。</td></tr>');
            return;
        }
        var h = '';
        rows.forEach(function(t){
            // 要求建 CP ＝ 沒被排除（排除名單存的是「不要求」的，所以勾選框是反向的）
            var req = !+t.excluded;
            var off = +t.is_active !== 1;
            var insp = t.insp_next || '';
            h += '<tr'+(off?' style="opacity:.55;"':'')+'>'
              +  '<td style="text-align:center;">'+t.tag_id+'</td>'
              +  '<td><b>'+esc(t.proc_name)+'</b></td>'
              +  '<td><span class="tag-s sg-1">'+esc(t.label_single)+'</span>'
              +    (t.scope === 'both' || t.scope === 'full' ? ' <span class="tag-s sg-3">'+esc(t.label_full)+'</span>' : '')
              +  '</td>'
              +  '<td style="text-align:center;">'+(t.n_order||0)+'</td>'
              +  '<td style="text-align:center;">'+(t.n_part||0)+'</td>'
              +  '<td style="text-align:center;">'+(off?'<span class="muted">停用</span>':'✓')+'</td>'
              +  '<td style="text-align:center;"><label style="font-weight:normal;margin:0;">'
              +    '<input type="checkbox" class="tagck" value="'+t.tag_id+'"'+(req?' checked':'')+(off?' disabled':'')+'> '
              +    (off ? '<span class="muted">停用中不認定</span>' : '要求') + '</label></td>'
              +  '<td><select class="tagInspSel" data-tagid="'+t.tag_id+'" style="width:100%;height:26px;font-size:12px;">'
              +    '<option value=""'+(insp===''||insp==='FQC'?' selected':'')+'>（不指定）</option>'
              +    '<option value="IQC"'+(insp==='IQC'?' selected':'')+'>下一站固定 IQC</option>'
              +  '</select>'
              +    (insp==='FQC' ? '<div class="muted" style="font-size:11px;">原設定「下一站固定FQC」已改為自動判定（見上方②），存檔後會清掉這個舊值</div>' : '')
              +  '</td>'
              +  '</tr>';
        });
        $('#tAsTag tbody').html(h);
    });
}
$('#btnSaveTags').on('click', function(){
    // 畫面上勾的是「要求建 CP」，存進去的是「排除名單」＝沒勾的那些
    var excluded = [];
    $('#tAsTag .tagck').each(function(){
        if (!$(this).is(':checked') && !$(this).is(':disabled')) excluded.push(+$(this).val());
    });
    var nReq = $('#tAsTag .tagck:checked').length;
    if (nReq === 0 && !confirm('所有稽核製程都被排除了，建議建立清單會退回以「已建 PFMEA 的料號」為母體。\n確定要這樣存嗎？')) return;
    var inspMap = {};
    $('#tAsTag .tagInspSel').each(function(){
        var v = $(this).val();
        if (v) inspMap[$(this).data('tagid')] = v;
    });
    post('excluded_as_tags_save', { tag_ids: JSON.stringify(excluded) }, function(res){
        TAGSTATUS = res.tag_status || TAGSTATUS;
        post('as_tag_insp_save', { map: JSON.stringify(inspMap) }, function(res2){
            ASTAGINSP = res2.as_tag_insp || ASTAGINSP;
            toast(res.message);
            renderTagCfg();
        });
    });
});

/* AS 文件挑選（走共用 eg_asdoc_picker 的同一組資料來源） */
$('#btnPickAs').on('click', function(){
    $.get('../../src/store/AS_Document_API.php', { action: 'list_documents' }, function(res){
        var docs = (res && (res.data || res.documents || res.rows)) || [];
        window._ASDOCS = docs;
        renderAsRows('');
        openMask('mAs');
    }, 'json').fail(function(){ toast('讀取 AS 文件清單失敗，請改用 AS 文件管理頁面綁定。', true); });
});
$('#asKw').on('input', function(){ renderAsRows($(this).val().trim()); });
function renderAsRows(kw){
    var docs = window._ASDOCS || [], h = '', n = 0;
    docs.forEach(function(d){
        var no = d.doc_no || d.document_no || '', nm = d.doc_name || d.document_name || '';
        if (kw && (no + nm).toLowerCase().indexOf(kw.toLowerCase()) < 0) return;
        if (n++ > 300) return;
        h += '<div class="ac-item" data-asid="'+(d.id||d.doc_id)+'"><b>'+esc(no)+'</b> '+esc(nm)+'</div>';
    });
    $('#asRows').html(h || '<div class="muted">查無符合的文件</div>');
}
$(document).on('click', '[data-asid]', function(){
    post('asdoc_save', { doc_id: +$(this).data('asid') }, function(res){
        toast(res.message); closeMask('mAs');
        ASMETA = res.as_doc || ASMETA;
        $('#asNow').text(ASMETA.as_doc_id ? (ASMETA.doc_name + '（' + ASMETA.doc_no + '）') : '尚未綁定');
    });
});

/* ────────────────── 列印（A3 橫式）────────────────── */
$('#btnPrint').on('click', function(){ if (DOC && DOC.cp_id) printDoc(DOC.cp_id); });
function printDoc(cpId){
    get('print_meta', { cp_id: cpId }, function(res){
        var w = window.open('', '_blank');
        if (!w) { toast('列印視窗被瀏覽器封鎖了，請允許這個網站開啟彈出視窗。', true); return; }
        w.document.open();
        w.document.write(buildPrintHtml(res.doc, res.meta, res.special_classes || []));
        w.document.close();
        post('print_log', { cp_id: cpId, doc_name: (res.meta.doc_name || '管制計畫') + ' ' + (res.doc.cp_no||''),
                            part_no: res.doc.part_no_text || res.doc.family_name || '' });
    });
}
function buildPrintHtml(d, meta, classes){
    var procs = d.processes || [];
    var rows = '';
    procs.forEach(function(p){
        var items = p.items || [];
        if (!items.length) items = [{}];
        items.forEach(function(it, ii){
            rows += '<tr>';
            if (ii === 0) {
                rows += '<td rowspan="'+items.length+'" class="c">'+esc(p.seq)+'</td>'
                     +  '<td rowspan="'+items.length+'">'+esc(p.process_name||'')
                     +  (p.insp_stage ? '<div class="sm">檢驗：'+esc(p.insp_stage)+'</div>' : '')
                     +  (p.op_desc ? '<div class="sm">'+esc(p.op_desc)+'</div>' : '')
                     +  (+p.is_outsource && p.maker_name ? '<div class="sm">委外：'+esc(p.maker_name)+'</div>' : '')
                     +  '</td>'
                     +  '<td rowspan="'+items.length+'">'+esc(p.machine||'')
                     +  (p.jig_tool ? '<div class="sm">'+esc(p.jig_tool)+'</div>' : '')+'</td>';
            }
            var sym = '';
            if (it.special_class_id) {
                var c = classes.filter(function(x){ return +x.class_id === +it.special_class_id; })[0];
                sym = c ? ((c.symbol||'') + ' ' + c.class_name) : (it.special_class_text||'');
            } else sym = it.special_class_text || '';
            rows += '<td class="c">'+esc(it.char_no||'')+'</td>'
                 +  '<td>'+esc(it.char_product||'')+'</td>'
                 +  '<td>'+esc(it.char_process||'')+'</td>'
                 +  '<td class="c">'+esc(sym)+'</td>'
                 +  '<td>'+esc(it.spec_text||'')+'</td>'
                 +  '<td>'+esc(it.eval_method||'')+'</td>'
                 +  '<td class="c">'+esc(it.tool_no||'')+'</td>'
                 +  '<td class="c">'+esc(it.sample_size||'')+'</td>'
                 +  '<td class="c">'+esc(it.sample_freq||'')+'</td>'
                 +  '<td>'+esc(it.control_method||'')+'</td>'
                 +  '<td>'+esc(it.reaction_plan||'')+'</td></tr>';
        });
    });
    if (!rows) rows = '<tr><td colspan="14" class="c">（尚無製程與特性資料）</td></tr>';

    var stageBoxes = '';
    (window.STAGES_FOR_PRINT || STAGES).forEach(function(s){
        if (+s.is_active !== 1 && +s.stage_id !== +d.stage_id) return;
        stageBoxes += '<span class="sb">' + (+s.stage_id === +d.stage_id ? '■' : '□') + ' ' + esc(s.stage_name) + '</span>';
    });

    var pageCount = Math.max(1, Math.ceil((procs.reduce(function(a,p){ return a + Math.max(1,(p.items||[]).length); }, 0)) / 16));

    return '<!DOCTYPE html><html lang="zh-Hant"><head><meta charset="utf-8">'
     + '<title>' + esc((meta.doc_name||'管制計畫') + ' ' + (d.cp_no||'')) + '</title><style>'
     /* A3 橫式；頁碼左下、AS 編號右下（ai-rules/16） */
     + '@page{size:420mm 297mm;margin:12mm 12mm 14mm 12mm;'
     + (pageCount > 1 ? '@bottom-left{content:"第 " counter(page) " 頁／共 " counter(pages) " 頁";font-size:8pt;color:#333;}' : '')
     + '@bottom-right{content:"' + esc(meta.doc_no||'') + '";font-size:8pt;color:#333;}}'
     + '*{box-sizing:border-box;}'
     + 'body{font-family:"Microsoft JhengHei","微軟正黑體",sans-serif;margin:0;color:#000;}'
     + 'h1{font-size:15pt;text-align:center;margin:0 0 2mm;}'
     + 'h2{font-size:12pt;text-align:center;margin:0 0 3mm;font-weight:normal;}'
     + '.stg{text-align:center;margin:0 0 3mm;font-size:10pt;}'
     + '.sb{margin:0 6mm;}'
     + 'table{width:100%;border-collapse:collapse;table-layout:fixed;}'
     + 'th,td{border:1px solid #000;padding:1.5mm 1.5mm;font-size:8pt;vertical-align:top;word-break:break-word;}'
     + 'thead th{background:#eee;text-align:center;}'
     + 'thead{display:table-header-group;}'      /* 表頭跨頁重複 */
     + 'tr{page-break-inside:avoid;}'            /* 資料列不被切成兩半 */
     + '.c{text-align:center;}'
     + '.sm{font-size:7pt;color:#444;}'
     + '.hd{width:100%;margin-bottom:3mm;}'
     + '.hd td{border:1px solid #000;font-size:8.5pt;padding:1.5mm;}'
     + '.hd .lb{background:#f3f3f3;width:26mm;font-weight:bold;}'
     + '.ft{margin-top:3mm;font-size:8pt;}'
     + '</style></head><body>'
     + '<h1>' + esc(COMPANY || '') + '</h1>'
     + '<h2>' + esc(meta.doc_name || '管制計畫') + '　Control Plan</h2>'
     + '<div class="stg">' + stageBoxes + '</div>'
     + '<table class="hd"><tr>'
     +   '<td class="lb">管制計畫編號</td><td>' + esc(d.cp_no||'') + '</td>'
     +   '<td class="lb">' + (d.scope === 'family' ? '產品族' : '零件號碼') + '</td>'
     +   '<td>' + esc(d.scope === 'family' ? (d.family_name||'') : (d.part_no_text||'')) + '</td>'
     +   '<td class="lb">圖面版次</td><td>' + esc(d.part_rev||'') + '</td>'
     +   '<td class="lb">CP 版次</td><td>' + esc(d.ver_no||'') + '</td>'
     + '</tr><tr>'
     +   '<td class="lb">品名</td><td>' + esc(d.product_name||'') + '</td>'
     +   '<td class="lb">客戶</td><td>' + esc(d.customer_name||'') + '</td>'
     +   '<td class="lb">表單日期</td><td>' + dispDate(d.form_date) + '</td>'
     +   '<td class="lb">核心小組</td><td>' + esc(d.core_team||'') + '</td>'
     + '</tr><tr>'
     +   '<td class="lb">主要聯絡人</td><td>' + esc(d.key_contact||'') + '</td>'
     +   '<td class="lb">組織／工廠</td><td>' + esc(d.org_code||'') + '</td>'
     +   '<td class="lb">客戶工程核准</td><td>' + esc(d.customer_eng_appr||'') + '</td>'
     +   '<td class="lb">客戶品保核准</td><td>' + esc(d.customer_qa_appr||'') + '</td>'
     + '</tr></table>'
     + '<table><thead><tr>'
     +   '<th rowspan="2" style="width:9mm;">製程<br>編號</th>'
     +   '<th rowspan="2" style="width:34mm;">製程名稱／作業說明</th>'
     +   '<th rowspan="2" style="width:30mm;">機器、裝置<br>治具、工具</th>'
     +   '<th colspan="4">特性</th>'
     +   '<th rowspan="2" style="width:40mm;">產品／製程<br>規格及公差</th>'
     +   '<th colspan="2">評估／量測技術</th>'
     +   '<th colspan="2">樣本</th>'
     +   '<th rowspan="2" style="width:46mm;">管制方法</th>'
     +   '<th rowspan="2" style="width:40mm;">反應計畫</th>'
     + '</tr><tr>'
     +   '<th style="width:10mm;">編號</th><th style="width:34mm;">產品特性</th>'
     +   '<th style="width:30mm;">製程特性</th><th style="width:14mm;">特殊<br>分類</th>'
     +   '<th style="width:28mm;">方法</th><th style="width:20mm;">檢具</th>'
     +   '<th style="width:13mm;">大小</th><th style="width:24mm;">頻率</th>'
     + '</tr></thead><tbody>' + rows + '</tbody></table>'
     + (d.note ? '<div class="ft"><b>備註：</b>' + esc(d.note).replace(/\n/g,'<br>') + '</div>' : '')
     + (d.scope === 'family' && (d.family_parts||[]).length
         ? '<div class="ft"><b>本產品族涵蓋料號：</b>'
           + (d.family_parts||[]).map(function(p){ return esc(p.D_Setting_Id || p.part_no_text); }).join('、') + '</div>'
         : '')
     + '<div class="ft">列印日期：' + dispDate(new Date().toISOString().substring(0,10))
     +   '　狀態：' + esc(d.status_label || d.status) + '</div>'
     /* 列印觸發不可依賴 window.onload（本視窗是 document.write 出來的，load 可能已經跑完） */
     + '<scr' + 'ipt>setTimeout(function(){window.print();},300);</scr' + 'ipt>'
     + '</body></html>';
}

/* ────────────────── 啟動 ────────────────── */
boot();
</script>
</body>
</html>
