<?php
/**
 * 表格模板管理（像 Excel 一樣設計表單模板）— 2026-10-14 新增
 * 資料走 src/store/SheetForm_API.php；權限／驗證／簽核流程在 src/common/sheet_form_lib.php、sheet_form_flow_lib.php；
 * 試算表元件＝Univer（Apache-2.0，本機打包 resource/js/eg_univer.js），前端包裝＝resource/js/eg_sheet_form.js。
 * 管理員：設計版面、待填欄位、簽核流程、維護人員、圖章樣式、AS 綁定；維護人員：只能改該模板的「預設明細內容」（標準項目）。
 */
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/ADM/sheet_form_template.php";
    header("Location:../../index.php");
    exit;
}
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/sheet_form_lib.php';

$db = (new DBConnection())->getPDO();
sf_ensure_schema($db);
$sfUser = sf_current_user($db);
$perms = sf_perms($db, $sfUser);
$mids = sf_maintainable_ids($db, $perms, (int)$sfUser['id']);
$isMaint = is_array($mids) && count($mids) > 0;
$_v = function ($rel) { return @filemtime(__DIR__ . '/../../' . $rel); };
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>表格模板管理</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <style>
        #sidebar-menu { visibility: hidden; }
        .right_col .page-title { margin:8px 0 4px; overflow:hidden; clear:both; }
        .sf-toolbar { display:flex; flex-wrap:wrap; gap:6px; align-items:center; clear:both; border:1.5px solid #E8D5B5; border-radius:8px;
            padding:8px 10px; margin-bottom:10px; background:#FDF8EF; }
        .sf-toolbar button, .sf-toolbar a.sf-btn { height:30px; line-height:28px; font-size:13px; padding:0 12px; border:1px solid #D8BE93; border-radius:4px;
            background:#fff; color:#5b3a1e; cursor:pointer; text-decoration:none; }
        .sf-toolbar .btn-warm { background:#F0A24B; color:#fff; border-color:#d98a33; }
        .page-help-btn { margin-left:auto; height:30px; font-size:13px; padding:0 14px; border:1px solid #D8BE93; border-radius:4px; background:#fff; color:#5b3a1e; cursor:pointer; }
        .help-doc h4 { font-size:14px; color:#8A5A2B; margin:10px 0 4px; }
        table.sf-tbl { width:100%; border-collapse:collapse; font-size:13px; background:#fff; }
        table.sf-tbl th, table.sf-tbl td { border:1px solid #EADFC8; padding:6px 8px; }
        table.sf-tbl thead th { background:#F7E0BD; color:#5b3a1e; }
        table.sf-tbl tr.arch td { background:#FBF6EE; color:#8a7a66; }
        .sf-wrap { overflow-x:auto; border:1px solid #E8D5B5; border-radius:6px; }
        .st-badge { display:inline-block; padding:1px 6px; border-radius:3px; font-size:11px; line-height:16px; border:1px solid transparent; }
        .st-active { background:#F7E0BD; color:#7a5217; border-color:#E0BE86; } .st-archived { background:#EFE7DA; color:#8a6d45; border-color:#D8C6A8; }
        .lnk { color:#b5762a; cursor:pointer; margin-right:8px; } .lnk.del { color:#DD5138; } .lnk.off { color:#b0a390; }
        .sf-mask { display:none; position:fixed; inset:0; background:rgba(60,40,20,.45); z-index:1050; }
        .sf-modal { background:#fff; border-radius:8px; max-width:640px; margin:30px auto; box-shadow:0 5px 25px rgba(0,0,0,.3); max-height:90vh; display:flex; flex-direction:column; }
        .sf-modal.wide { max-width:860px; }
        .sf-modal .m-head { background:#F7E0BD; color:#5b3a1e; font-weight:bold; padding:10px 15px; border-radius:8px 8px 0 0; display:flex; justify-content:space-between; }
        .sf-modal .m-head .m-close { cursor:pointer; color:#b5762a; }
        .sf-modal .m-body { padding:15px; overflow-y:auto; font-size:13px; color:#5b3a1e; }
        .sf-modal .m-body label { display:block; margin:8px 0 3px; }
        .sf-modal .m-body input[type=text], .sf-modal .m-body select { border:1px solid #D8BE93; border-radius:4px; padding:4px 6px; font-size:13px; }
        .sf-modal .m-foot { padding:10px 15px; border-top:1px solid #EADFC8; text-align:right; }
        .sf-modal .m-foot button { height:30px; padding:0 16px; border-radius:4px; font-size:13px; border:1px solid #d98a33; cursor:pointer; background:#F0A24B; color:#fff; }
        .sf-modal .m-foot button.b-cancel { background:#fff; color:#5b3a1e; border-color:#D8BE93; margin-right:6px; }
        .flow-row { display:flex; gap:6px; align-items:center; flex-wrap:wrap; border:1px solid #EADFC8; border-radius:6px; background:#FDF8EF; padding:6px 8px; margin-bottom:6px; }
        .flow-row .no { font-weight:bold; color:#8A5A2B; width:46px; }
        .mt-tags { display:flex; flex-wrap:wrap; gap:4px; margin:4px 0; }
        .mt-tags .tg { background:#F7E0BD; color:#5b3a1e; border-radius:9px; font-size:12px; padding:1px 8px; }
        .mt-tags .tg i { cursor:pointer; color:#b5762a; margin-left:4px; font-style:normal; }
        .hint { font-size:11.5px; color:#8a6d45; margin:3px 0; line-height:1.5; }

        /* 設計器：全螢幕覆蓋 */
        #dz { display:none; position:fixed; inset:0; z-index:10050; background:#fff; flex-direction:column; }
        #dz.on { display:flex; }
        .dz-top { display:flex; flex-wrap:wrap; gap:8px; align-items:center; padding:6px 10px; background:#FDF8EF; border-bottom:1.5px solid #E8D5B5; font-size:13px; color:#5b3a1e; }
        .dz-top input[type=text], .dz-top select { height:28px; border:1px solid #D8BE93; border-radius:4px; padding:0 6px; font-size:13px; }
        .dz-top button { height:28px; font-size:13px; padding:0 12px; border:1px solid #D8BE93; border-radius:4px; background:#fff; color:#5b3a1e; cursor:pointer; }
        .dz-top .btn-warm { background:#F0A24B; color:#fff; border-color:#d98a33; }
        .dz-main { flex:1; display:flex; min-height:0; }
        #dzSheet { flex:1; min-width:0; position:relative; }
        .dz-side { width:300px; border-left:1.5px solid #E8D5B5; background:#FFFCF6; overflow-y:auto; padding:10px; font-size:12.5px; color:#5b3a1e; }
        .dz-side h5 { font-size:13px; color:#8A5A2B; margin:10px 0 6px; font-weight:bold; }
        .dz-side label { display:block; margin:7px 0 2px; }
        .dz-side input[type=text], .dz-side select, .dz-side textarea { width:100%; border:1px solid #D8BE93; border-radius:4px; padding:4px 6px; font-size:12.5px; box-sizing:border-box; }
        .dz-side button { height:28px; font-size:12.5px; padding:0 10px; border:1px solid #D8BE93; border-radius:4px; background:#fff; color:#5b3a1e; cursor:pointer; margin:2px 4px 2px 0; }
        .dz-side .btn-warm { background:#F0A24B; color:#fff; border-color:#d98a33; }
        .fld-item { display:flex; gap:6px; align-items:center; padding:3px 5px; border-bottom:1px solid #F0E7D5; cursor:pointer; }
        .fld-item:hover { background:#FBF0DD; }
        .fld-item .tg { background:#F7E0BD; color:#5b3a1e; border-radius:9px; font-size:11px; line-height:16px; padding:0 7px; }
        .err { color:#DD5138; font-size:12px; }
        .sf-toast { position:fixed; left:50%; bottom:30px; transform:translateX(-50%); background:#5b3a1e; color:#fff; padding:8px 18px; border-radius:6px; z-index:20000; display:none; font-size:13px; }
        #dfMask, #helpUseMask, #stMask { z-index:10100; }
        #fmMask { z-index:10150; }
        #fmMask.preview #btnFmPrint, #fmMask.preview #btnFmSave, #fmMask.preview #btnFmDone { display:none; }
        @media print { .page-help-btn { display:none; } }
    </style>
</head>
<body class="nav-sm">
<div class="container body">
<div class="main_container">
    <?php include '../partPage/sideAndTopBarMenu.html' ?>
    <div class="right_col" role="main">
        <div class="page-title" style="display:flex;align-items:center;flex-wrap:wrap;clear:both;">
            <h2 style="margin:6px 0;">表格模板管理 <small style="color:#8a6d45;">像 Excel 一樣設計表單模板，再依模板填寫與列印</small></h2>
            <button class="page-help-btn" id="btnPageHelp"><i class="fa fa-question-circle"></i> 使用說明</button>
        </div>
        <div class="clearfix"></div>
<?php if (!$perms['canAdmin'] && !$isMaint): ?>
        <div><h4><i class="fa fa-lock"></i> 沒有模板管理權限</h4><p>請洽系統管理者於「使用者權限設定」指派「表格模板」的模板管理角色，或將您指定為某模板的維護人員。要填寫表單請前往 <a href="sheet_form.php">表格模板填寫</a>。</p></div>
<?php else: ?>
        <div class="sf-toolbar">
<?php if ($perms['canAdmin']): ?>
            <span>設計好格子、合併、框線、公式，再把要讓人填的格子標成「待填欄位」，並設定簽核流程。</span>
            <button class="btn-warm" id="btnNew" style="margin-left:auto;"><i class="fa fa-plus"></i> 新增模板</button>
<?php else: ?>
            <span>您是下列模板的維護人員，可以修改它們的「標準項目」（建立單據時自動帶入的明細）。</span>
<?php endif; ?>
            <a class="sf-btn" href="sheet_form.php"<?= $perms['canAdmin'] ? '' : ' style="margin-left:auto;"' ?>>前往「填寫表單」→</a>
        </div>
        <div class="sf-wrap">
        <table class="sf-tbl">
            <thead><tr><th>模板名稱</th><th>綁定AS文件</th><th>紙張</th><th>待填欄位</th><th>簽核關卡</th><th>已填單據</th><th>版本</th><th style="width:70px;">狀態</th><th style="width:300px;">操作</th></tr></thead>
            <tbody id="tplBody"><tr><td colspan="9" style="text-align:center;color:#8a6d45;">載入中…</td></tr></tbody>
        </table>
        </div>
<?php endif; ?>
    </div>
</div>
</div>

<?php if ($perms['canAdmin'] || $isMaint): ?>
<?php if ($perms['canAdmin']): ?>
<!-- 設計器 -->
<div id="dz">
    <div class="dz-top">
        <b>模板名稱</b><input type="text" id="dzName" maxlength="100" style="width:220px" placeholder="例：外觀檢查記錄表">
        <b>紙張</b><select id="dzPaper"><option value="A4">A4</option><option value="A3">A3</option></select>
        <select id="dzOrient"><option value="landscape">橫式</option><option value="portrait">直式</option></select>
        <b>AS 文件</b><span id="dzDoc" style="color:#5b3a1e;">未綁定</span><button type="button" id="btnDzDoc">選擇…</button>
        <button type="button" id="btnDzFlow"><i class="fa fa-sitemap"></i> 簽核與維護設定<span id="flowInfo" style="color:#8a6d45;margin-left:4px;"></span></button>
        <button type="button" id="btnDzPreview"><i class="fa fa-eye"></i> 預覽填寫畫面</button>
        <span style="margin-left:auto;"></span>
        <span id="dzDirty" style="color:#DD5138;display:none;">● 尚未儲存</span>
        <button class="btn-warm" id="btnDzSave"><i class="fa fa-save"></i> 儲存</button>
        <button id="btnDzClose">關閉</button>
    </div>
    <div class="dz-main">
        <div id="dzSheet"></div>
        <div class="dz-side">
            <h5>目前選取：<span id="selText" style="color:#b5762a;">—</span></h5>
            <div id="selNow" class="hint"></div>
            <h5>設為「待填欄位」</h5>
            <label>填寫型別</label>
            <select id="fdType">
                <option value="text">文字</option><option value="number">數字</option><option value="date">日期（日曆挑選）</option>
                <option value="select">下拉選單</option><option value="checkbox">勾選框</option><option value="person">人員（全公司在職者）</option>
                <option value="part">料號（搜尋主檔）</option><option value="customer">客戶（搜尋主檔）</option>
            </select>
            <div id="optBox" style="display:none;"><label>下拉選項（一行一個）</label><textarea id="fdOpt" rows="4"></textarea></div>
            <label>欄位名稱（填寫畫面的欄位標籤；留空則明細列取正上方表頭、其他取左邊格子的文字）</label><input type="text" id="fdLabel" maxlength="40">
            <label><input type="checkbox" id="fdReq"> 必填（送出時檢查）</label>
            <label><input type="checkbox" id="fdG"> 這一列是「明細列」（填寫時可按「新增一筆」）</label>
            <div class="hint">明細列：同一張模板只能有一列；要放進明細列的每一格都要各自設為待填欄位並勾這個選項。合計列（如 =SUM）會在加列時自動延伸範圍。</div>
            <div style="margin:6px 0;"><button type="button" id="btnDef"><i class="fa fa-list"></i> 預設明細內容…</button> <span id="defInfo" class="hint">尚未設定</span></div>
            <div class="hint">標準項目（例如每年固定要審的 7 個項目）可先在這裡貼上，建立單據時就會自動帶入，不用每次重打。</div>
            <div><button class="btn-warm" id="btnApply">套用到選取的格子</button><button id="btnClear">取消待填</button></div>
            <div id="fdErr" class="err"></div>
            <h5>已設定的待填欄位（<span id="fdCount">0</span>）</h5>
            <div id="fdList"></div>
            <div class="hint" style="margin-top:10px;">淡黃色底＝待填欄位（只在設計與填寫畫面顯示，列印不會有）。沒設成待填的格子，填寫時是鎖定的。合併儲存格請先合併再設定。</div>
        </div>
    </div>
</div>

<!-- 簽核與維護設定 -->
<div class="sf-mask" id="stMask"><div class="sf-modal wide">
    <div class="m-head"><span>簽核與維護設定</span><span class="m-close" onclick="closeMask('stMask')">✕</span></div>
    <div class="m-body">
        <div style="font-weight:bold;color:#8A5A2B;">簽核流程（依序簽，最多 4 關；一關都不設＝送出即完成，不需簽核）</div>
        <div class="hint">簽核人方式：<b>部門任一主管</b>＝該部門任何一位主管簽即可（勾「會簽」則每一位主管都要簽）；<b>指定人員</b>＝固定那個人；<b>送出者的單位主管</b>＝依組織圖自動找（組長→課長，只追到課級）；<b>最高核准人員</b>＝全站組織角色綁定的那位。解析到送出者本人會自動跳過（不能自己簽自己的單）。簽核人請假且設有代理人時，代理人也能簽。勾「系統自動簽核」＝不等真人，送出後系統立即簽完（簽核時間會隨機錯開幾分鐘、不跨天，補歷史單據用）。</div>
        <div id="flowRows"></div>
        <button type="button" id="btnFlowAdd" style="height:28px;border:1px dashed #D8BE93;border-radius:4px;background:#FDF8EF;color:#8A5A2B;cursor:pointer;padding:0 12px;">＋ 新增一關</button>
        <div style="font-weight:bold;color:#8A5A2B;margin-top:16px;">簽章圖章樣式</div>
        <select id="stStamp" style="min-width:240px"><option value="0">（預設樣式）</option></select>
        <div class="hint">列印時填表人與各關簽核人的圖章樣式；有上傳掃描實體章的人一律優先用掃描章。樣式請到「圖章管理」建立。</div>
        <div style="font-weight:bold;color:#8A5A2B;margin-top:16px;">維護人員（只能修改這張模板的「預設明細內容」，不能動版面與簽核設定）</div>
        <label>維護部門（該部門任一主管都能維護）</label>
        <select id="stMDept" data-eg-filter="輸入部門名稱篩選…" style="min-width:240px"><option value="0">（不指定）</option></select>
        <label>指定維護人員</label>
        <select id="stMUser" data-eg-filter="輸入姓名篩選…" style="min-width:240px"><option value="">請選擇要加入的人員…</option></select>
        <div class="mt-tags" id="stMTags"></div>
        <div id="stErr" class="err"></div>
    </div>
    <div class="m-foot"><button class="b-cancel" onclick="closeMask('stMask')">取消</button><button id="btnStOk">套用（按「儲存」才會寫入）</button></div>
</div></div>
<?php endif; ?>

<!-- 預設明細內容 -->
<div class="sf-mask" id="dfMask"><div class="sf-modal wide">
    <div class="m-head"><span id="dfTitle">預設明細內容</span><span class="m-close" onclick="closeMask('dfMask')">✕</span></div>
    <div class="m-body">
        <div class="hint" style="font-size:12.5px;line-height:1.7;">建立單據時，這些列會自動帶入明細（使用者仍可新增、刪除、修改）。<b>一列一筆、欄位用 Tab 分隔</b>，可直接從 Excel 複製貼上。欄位順序：<b id="dfCols"></b>。沒有要預設的欄位留空即可（連續兩個 Tab）。</div>
        <textarea id="dfText" rows="12" style="width:100%;font-size:13px;border:1px solid #D8BE93;border-radius:4px;padding:6px;box-sizing:border-box;white-space:pre;" data-eg-skip></textarea>
        <div id="dfErr" class="err"></div>
    </div>
    <div class="m-foot"><button class="b-cancel" onclick="closeMask('dfMask')">取消</button><button id="btnDfOk">套用</button></div>
</div></div>

<?php if ($perms['canAdmin']): ?>
<?php include __DIR__ . '/_sheet_form_modal.php'; ?>
<?php endif; ?>

<div class="sf-mask" id="helpUseMask"><div class="sf-modal wide">
    <div class="m-head"><span>使用說明 — 表格模板管理</span><span class="m-close" onclick="closeMask('helpUseMask')">✕</span></div>
    <div class="m-body help-doc" style="line-height:1.8;">
        <h4>功能說明</h4>
        用「像 Excel 的格子」直接畫出表單：可調欄寬列高、合併儲存格、框線、底色、字型、公式。再把要讓人填的格子標成「待填欄位」，並設定簽核流程。使用者填寫時看到的是一般網頁表單，不是 Excel。取代舊的「審核表單模板管理」（舊模組保留不動）。
        <h4>操作步驟</h4>
        <b>①新增模板</b>：按「新增模板」進入設計畫面，用上方工具列像 Excel 一樣編輯（開始／插入／公式／資料／檢視），可直接貼上 Excel 內容。<br>
        <b>②設定待填欄位</b>：選取要讓人填的格子，在右側選「填寫型別」（文字／數字／日期／下拉選單／勾選框／人員／料號／客戶），可勾必填，按「套用到選取的格子」。料號、客戶欄填寫時會從主檔搜尋挑選。標好的格子會變淡黃色，右側清單可點選跳到該格。<br>
        <b>③明細列</b>：像「項次／品名／數量」這種不固定筆數的列，把那一列每個要填的格子都設為待填並勾「明細列」。填寫畫面每一筆是一張卡片，可新增／刪除，合計公式（SUM 等）自動延伸。<br>
        <b>④預設明細內容</b>：每年固定要審的標準項目，按「預設明細內容…」貼上（Tab 分隔，可從 Excel 複製），建立單據時自動帶入。<br>
        <b>⑤簽核與維護設定</b>：設定簽核關卡（部門任一主管／指定人員／送出者的單位主管／最高核准人員，可勾系統自動簽核）、圖章樣式、維護部門與維護人員。維護人員只能改標準項目。<br>
        <b>⑥預覽填寫畫面</b>：不用先存檔，直接看使用者會看到的網頁表單長什麼樣子。<br>
        <b>⑦儲存／AS 編號</b>：上方可設紙張與方向、綁定 AS 文件編號（列印表頭取文件名稱、右下角印編號，版次依表單業務日期回推）。<br>
        <b>⑧停用／刪除／複製</b>：已有填寫單據的模板不能刪除，請改用「停用」（既有單據照樣查得到、印得出來）。複製可以拿現有模板當範本改。
        <h4>重要行為</h4>
        ・填寫單據建立時會把當下的模板（版面、欄位、簽核流程）完整複製進單據，之後再改模板不影響已建立的單據。<br>
        ・只有「待填欄位」的內容會被存起來；其他格子填寫時是鎖定的，後端也會再檢查。<br>
        ・同一模板只支援一個明細列。簽核關卡最多 4 關。
        <h4>權限角色</h4>
        模板管理＝本頁全部功能；管理者全權；維護人員只看得到自己被指定的模板、只能改標準項目。一般使用者請到「表格模板填寫」。
    </div>
    <div class="m-foot"><button onclick="closeMask('helpUseMask')">關閉</button></div>
</div></div>
<?php endif; ?>
<div class="sf-toast" id="sfToast"></div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script src="../../resource/js/eg_input_rules.js?v=<?= $_v('resource/js/eg_input_rules.js') ?>"></script>
<script src="../../resource/js/eg_asdoc_picker.js?v=<?= $_v('resource/js/eg_asdoc_picker.js') ?>"></script>
<?php if ($perms['canAdmin']): ?>
<link rel="stylesheet" href="../../resource/css/eg_univer.css?v=<?= $_v('resource/css/eg_univer.css') ?>">
<script src="../../resource/js/eg_univer.js?v=<?= $_v('resource/js/eg_univer.js') ?>"></script>
<script src="../../resource/js/eg_sheet_form.js?v=<?= $_v('resource/js/eg_sheet_form.js') ?>"></script>
<script src="../../resource/js/eg_sheet_form_ui.js?v=<?= $_v('resource/js/eg_sheet_form_ui.js') ?>"></script>
<?php endif; ?>
<script>
var API = '../../src/store/SheetForm_API.php';
var META = null, CUR = null /* {id, ctl, docId, dirty, defaults, flow, maintainDept, maintainers, stampTpl} */, DFCTX = null;
var MODE_LABEL = {dept:'部門任一主管', user:'指定人員', supervisor:'送出者的單位主管', top:'最高核准人員'};
function toast(m){ var $t=$('#sfToast'); $t.text(m).stop(true,true).fadeIn(120); setTimeout(function(){ $t.fadeOut(300); }, 2600); }
function openMask(id){ $('#'+id).show(); } function closeMask(id){ $('#'+id).hide(); }
function colName(c){ var s=''; c++; while(c>0){ var m=(c-1)%26; s=String.fromCharCode(65+m)+s; c=Math.floor((c-1)/26); } return s; }
function a1(r,c){ return colName(c)+(r+1); }
function esc(s){ return String(s==null?'':s).replace(/[&<>"]/g,function(m){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[m];}); }
$(document).ready(function(){
    var $am = $('#sidebar-menu .nav.side-menu > li.active');
    if ($am.length) { $am.removeClass('active').find('ul.child_menu').hide(); $am.find('li.current-page').removeClass('current-page'); }
    $('#sidebar-menu').css('visibility','visible');
});
$('#btnPageHelp').on('click', function(){ openMask('helpUseMask'); });
<?php if ($perms['canAdmin'] || $isMaint): ?>
$.getJSON(API, {action:'meta'}, function(res){ if(res.ok){ META = res; loadList(); } else alert(res.error||'載入失敗'); });

function loadList(){
    $.getJSON(API, {action:'template_list'}, function(res){
        if (!res.ok){ alert(res.error); return; }
        var h = '', admin = META.perms.canAdmin;
        (res.templates||[]).forEach(function(t){
            if (!admin && !t.can_maintain) return;
            var arch = t.status==='archived';
            var del = t.instance_count>0 ? '<span class="lnk off" onclick="delTpl('+t.id+','+t.instance_count+')">刪除</span>' : '<span class="lnk del" onclick="delTpl('+t.id+',0)">刪除</span>';
            var ops = (admin ? '<span class="lnk" onclick="openDesigner('+t.id+')"><i class="fa fa-pencil"></i> 設計</span>' : '')
              + (t.can_maintain ? '<span class="lnk" onclick="openDefaultsFor('+t.id+')"><i class="fa fa-list"></i> 標準項目</span>' : '')
              + (admin ? '<span class="lnk" onclick="copyTpl('+t.id+')">複製</span><span class="lnk" onclick="toggleTpl('+t.id+',\''+(arch?'active':'archived')+'\')">'+(arch?'啟用':'停用')+'</span>'+del : '');
            h += '<tr class="'+(arch?'arch':'')+'"><td>'+esc(t.name)+'</td><td>'+(t.as_doc?esc(t.as_doc.doc_no+' '+t.as_doc.doc_name):'<span style="color:#b0a390">未綁定</span>')+'</td>'
              + '<td>'+t.paper_size+(t.orientation==='landscape'?' 橫':' 直')+'</td><td>'+t.field_count+'</td><td>'+(t.flow_count?t.flow_count+' 關':'<span style="color:#b0a390">無</span>')+'</td><td>'+t.instance_count+'</td><td>v'+t.version+'</td>'
              + '<td><span class="st-badge '+(arch?'st-archived':'st-active')+'">'+(arch?'已停用':'使用中')+'</span></td><td>'+ops+'</td></tr>';
        });
        $('#tplBody').html(h || '<tr><td colspan="9" style="text-align:center;color:#8a6d45;">'+(admin?'尚無模板，按右上「新增模板」開始':'沒有您可以維護的模板')+'</td></tr>');
    });
}
function post(data, cb){ data.csrf = META.csrf; $.post(API, data, function(res){ if(!res.ok){ alert(res.error||'失敗'); return; } cb&&cb(res); }, 'json').fail(function(x){ var m='失敗'; try{ m=JSON.parse(x.responseText).error||m; }catch(e){} alert(m); }); }
function toggleTpl(id, st){ post({action:'template_status', id:id, status:st}, function(){ loadList(); }); }
function copyTpl(id){ post({action:'template_copy', id:id}, function(){ toast('已複製'); loadList(); }); }
function delTpl(id, cnt){
    if (cnt>0){ alert('這個模板已經有 '+cnt+' 張填寫單據，不能刪除（刪了那些單據會失去對應）。請改用「停用」。'); return; }
    if (!confirm('確定刪除這個模板？此動作無法復原。')) return;
    post({action:'template_delete', id:id}, function(){ loadList(); });
}
$('#btnNew').on('click', function(){ openDesigner(0); });

/* ---------------- 預設明細內容（設計器內＝暫存在畫面，按儲存寫入；維護人員＝直接寫入） ---------------- */
function openDefaultsFor(id){
    $.getJSON(API, {action:'template_get', id:id}, function(res){
        if (!res.ok){ alert(res.error); return; }
        var t = res.template, gf = t.fields.filter(function(f){ return f.g; }).sort(function(a,b){ return a.c-b.c; });
        DFCTX = {mode:'maint', id:id, gf:gf, defaults:t.defaults&&t.defaults.rows ? t.defaults : null};
        showDefaults('預設明細內容（標準項目）— '+t.name);
    });
}
function showDefaults(title){
    var gf = DFCTX.gf;
    if (!gf.length){ alert('這張模板還沒有「明細列」欄位。請先把明細那一列的格子設為待填欄位並勾選「明細列」。'); return; }
    $('#dfTitle').text(title);
    $('#dfCols').text(gf.map(function(f){ return (f.label||a1(f.r,f.c)); }).join(' → '));
    var lines = [], d = DFCTX.defaults;
    if (d){ for (var i=0;i<d.rows;i++) lines.push(gf.map(function(f){ var v=((d.values||{})[f.id]||[])[i]; return v==null||v===false?'':String(v).replace(/[\t\r\n]+/g,' '); }).join('\t')); }
    $('#dfText').val(lines.join('\n')); $('#dfErr').text(''); openMask('dfMask');
}
$('#btnDfOk').on('click', function(){
    var gf = DFCTX.gf, rows = [];
    $('#dfText').val().split(/\r?\n/).forEach(function(l){ if ($.trim(l.replace(/\t/g,''))==='') return; rows.push(l.split('\t')); });
    if (rows.length>200){ $('#dfErr').text('最多 200 列'); return; }
    var def = null;
    if (rows.length){
        var vals = {}; gf.forEach(function(f,ci){ vals[f.id] = rows.map(function(r){ return $.trim(r[ci]||''); }); });
        for (var ci=0; ci<gf.length; ci++){
            var f = gf[ci];
            for (var ri=0; ri<rows.length; ri++){
                var v = vals[f.id][ri]; if (v==='') continue;
                if (f.t==='select' && f.opt.indexOf(v)<0){ $('#dfErr').text('第 '+(ri+1)+' 列「'+(f.label||'')+'」的值「'+v+'」不在下拉選項內'); return; }
                if (f.t==='number' && isNaN(Number(v))){ $('#dfErr').text('第 '+(ri+1)+' 列「'+(f.label||'')+'」必須是數字'); return; }
            }
        }
        def = {rows:rows.length, values:vals};
    }
    if (DFCTX.mode==='maint'){
        post({action:'template_defaults_save', id:DFCTX.id, defaults_json: def ? JSON.stringify(def) : ''}, function(){ toast('標準項目已更新'); closeMask('dfMask'); });
    } else {
        CUR.defaults = def; CUR.dirty = true; $('#dzDirty').show(); refreshDefInfo(); closeMask('dfMask');
    }
});
<?php endif; ?>
<?php if ($perms['canAdmin']): ?>
/* ---------------- 設計器 ---------------- */
function openDesigner(id){
    if (id){ $.getJSON(API, {action:'template_get', id:id}, function(res){ if(!res.ok){ alert(res.error); return; } startDesigner(res.template); }); }
    else startDesigner(null);
}
function startDesigner(t){
    if (CUR && CUR.ctl) { CUR.ctl.dispose(); }
    $('#dzSheet').empty().append('<div id="dzU" style="position:absolute;inset:0;"></div>');
    $('#dz').addClass('on');
    CUR = { id: t?t.id:0, docId: t&&t.as_doc?t.as_doc.id:0, dirty:false, defaults: (t && t.defaults && t.defaults.rows) ? t.defaults : null,
            flow: t ? (t.flow||[]) : [], maintainDept: t ? (+t.maintain_dept_id||0) : 0, maintainers: t ? (t.maintainers||[]) : [], stampTpl: t ? (+t.stamp_tpl_id||0) : 0 };
    $('#dzName').val(t?t.name:''); $('#dzPaper').val(t?t.paper_size:'A4'); $('#dzOrient').val(t?t.orientation:'landscape');
    $('#dzDoc').text(t&&t.as_doc ? t.as_doc.doc_no+' '+t.as_doc.doc_name : '未綁定');
    $('#dzDirty').hide();
    CUR.ctl = EGSheetForm.mount({ el:'dzU', mode:'design', book: t?t.book:null, people: META.people, toast: toast });
    CUR.ctl.wb.onCommandExecuted(function(c){ if (!/selection|scroll|cursor|zoom|set-worksheet-active/i.test(c.id||'')) { CUR.dirty = true; $('#dzDirty').show(); } });
    CUR.ctl.onSelection(refreshSel);
    refreshFlowInfo();
    setTimeout(function(){ refreshSel(); refreshList(); refreshDefInfo(); $('#dzDirty').hide(); CUR.dirty = false; }, 700);
}
function selRange(){
    var s = CUR.ctl.getSelection(); if (!s) return '';
    var a = EGSheetForm.a1(s.r1,s.c1), b = EGSheetForm.a1(s.r2,s.c2);
    return a===b ? a : a+':'+b;
}
function refreshSel(){
    if (!CUR||!CUR.ctl) return;
    var s = CUR.ctl.getSelection(); $('#selText').text(selRange()||'—');
    if (!s) return;
    var f = (s.r1===s.r2&&s.c1===s.c2) ? CUR.ctl.fieldAtCell(s.r1,s.c1) : null;
    if (f){
        $('#selNow').html('這一格已是待填欄位：'+esc(EGSheetForm.TYPE_LABEL[f.t]||f.t)+(f.req?'・必填':'')+(f.g?'・明細列':''));
        $('#fdType').val(f.t); $('#fdLabel').val(f.label||''); $('#fdReq').prop('checked',!!f.req); $('#fdG').prop('checked',!!f.g); $('#fdOpt').val((f.opt||[]).join('\n'));
    } else { $('#selNow').text('不是待填欄位（填寫時鎖定）'); $('#fdLabel').val(''); }
    $('#optBox').toggle($('#fdType').val()==='select');
}
$('#fdType').on('change', function(){ $('#optBox').toggle($(this).val()==='select'); });
function refreshList(){
    var fs = CUR.ctl.listFields(); $('#fdCount').text(fs.length);
    var h=''; fs.forEach(function(f){ h += '<div class="fld-item" data-r="'+f.r+'" data-c="'+f.c+'"><b>'+EGSheetForm.a1(f.r,f.c)+'</b><span class="tg">'+esc(EGSheetForm.TYPE_LABEL[f.t]||f.t)+'</span><span>'+esc(f.label||'')+'</span>'+(f.req?'<span style="color:#DD5138">必填</span>':'')+(f.g?'<span class="tg">明細</span>':'')+'</div>'; });
    $('#fdList').html(h || '<div class="hint">還沒有待填欄位</div>');
}
$('#fdList').on('click', '.fld-item', function(){
    var r=+$(this).data('r'), c=+$(this).data('c');
    try { CUR.ctl.wb.getActiveSheet().getRange(r,c).activate(); } catch(e){}
});
$('#btnApply').on('click', function(){
    $('#fdErr').text('');
    var t = $('#fdType').val(), opt = [];
    if (t==='select'){
        $('#fdOpt').val().split(/\r?\n/).forEach(function(x){ x=$.trim(x); if (x && opt.indexOf(x)<0) opt.push(x); });
        if (!opt.length){ $('#fdErr').text('下拉選單至少要輸入一個選項'); return; }
    }
    var s = CUR.ctl.getSelection(); if (!s){ $('#fdErr').text('請先選取要設定的格子'); return; }
    var label = $.trim($('#fdLabel').val());
    if (!label && $('#fdG').is(':checked') && s.r1>0){ try { var uv = CUR.ctl.wb.getActiveSheet().getRange(s.r1-1, s.c1).getValue(); if (typeof uv==='string' && uv.charAt(0)!=='=') label = String(uv).substring(0,40); } catch(e){} }
    if (!label && s.c1>0){ try { var lv = CUR.ctl.wb.getActiveSheet().getRange(s.r1, s.c1-1).getValue(); if (typeof lv==='string' && lv.charAt(0)!=='=') label = String(lv).substring(0,40); } catch(e){} }
    var g = $('#fdG').is(':checked') ? 'd' : '';
    if (g){
        var rows = {}; CUR.ctl.listFields().forEach(function(f){ if (f.g) rows[f.r]=1; });
        for (var r=s.r1;r<=s.r2;r++) rows[r]=1;
        if (Object.keys(rows).length>1){ $('#fdErr').text('明細列只能設在同一列，目前選取範圍或既有明細橫跨多列'); return; }
    }
    var n = CUR.ctl.applyField({t:t, req:$('#fdReq').is(':checked'), opt:opt, g:g, label:label});
    toast('已設定 '+n+' 格'); CUR.dirty = true; $('#dzDirty').show();
    setTimeout(function(){ refreshList(); refreshSel(); }, 200);
});
$('#btnClear').on('click', function(){
    var n = CUR.ctl.clearField(); toast('已取消 '+n+' 格的待填設定'); CUR.dirty = true; $('#dzDirty').show();
    setTimeout(function(){ refreshList(); refreshSel(); }, 200);
});
function detailFields(){ return CUR.ctl.listFields().filter(function(f){ return f.g; }).sort(function(a,b){ return a.c-b.c; }); }
function refreshDefInfo(){ $('#defInfo').text(CUR && CUR.defaults ? '已設定 '+CUR.defaults.rows+' 列' : '尚未設定'); }
$('#btnDef').on('click', function(){ DFCTX = {mode:'designer', gf:detailFields(), defaults:CUR.defaults}; showDefaults('預設明細內容（標準項目）'); });
$('#btnDzDoc').on('click', function(){
    $.getJSON(API, {action:'asdoc_list'}, function(res){
        EGAsDoc.open({ docs:res.docs||[], current:CUR.docId||0, title:'模板 AS 文件綁定', onSave:function(id, doc){
            CUR.docId = id||0; $('#dzDoc').text(doc ? doc.doc_no+' '+doc.doc_name : '未綁定'); CUR.dirty = true; $('#dzDirty').show();
        }});
    });
});

/* ---------------- 簽核與維護設定 ---------------- */
function refreshFlowInfo(){
    var n = (CUR.flow||[]).length;
    $('#flowInfo').text(n ? '（'+n+' 關'+(CUR.maintainers.length||CUR.maintainDept?'・有維護人員':'')+'）' : '（無簽核'+(CUR.maintainers.length||CUR.maintainDept?'・有維護人員':'')+'）');
}
function flowRowHtml(i, s){
    var depts = '<option value="0">請選擇部門</option>' + (META.departments||[]).map(function(d){ return '<option value="'+d.id+'"'+(+s.dept_id===+d.id?' selected':'')+'>'+esc(d.name)+'</option>'; }).join('');
    var users = '<option value="0">請選擇人員</option>' + (META.people_full||[]).map(function(p){ return '<option value="'+p.id+'"'+(+s.user_id===+p.id?' selected':'')+'>'+esc(p.dept+' '+p.pos+' '+p.name)+'</option>'; }).join('');
    var modes = Object.keys(MODE_LABEL).map(function(k){ return '<option value="'+k+'"'+(s.mode===k?' selected':'')+'>'+MODE_LABEL[k]+'</option>'; }).join('');
    return '<div class="flow-row" data-i="'+i+'"><span class="no">第 '+(i+1)+' 關</span>'
      + '<input type="text" class="fl-label" maxlength="10" placeholder="關卡名稱（審核／核准）" value="'+esc(s.label||'')+'" style="width:150px">'
      + '<select class="fl-mode">'+modes+'</select>'
      + '<span class="fl-dept-w" style="'+(s.mode==='dept'?'':'display:none;')+'"><select class="fl-dept" data-eg-filter="輸入部門篩選…" style="min-width:150px">'+depts+'</select></span>'
      + '<span class="fl-user-w" style="'+(s.mode==='user'?'':'display:none;')+'"><select class="fl-user" data-eg-filter="輸入姓名篩選…" style="min-width:180px">'+users+'</select></span>'
      + '<label class="fl-all-w" style="margin:0;'+(s.mode==='dept'?'':'display:none;')+'"><input type="checkbox" class="fl-all"'+(s.all?' checked':'')+'> 會簽（每位主管都要簽）</label>'
      + '<label style="margin:0;"><input type="checkbox" class="fl-auto"'+(s.auto?' checked':'')+'> 系統自動簽核</label>'
      + '<span class="lnk del fl-del" style="margin-left:auto;">刪除</span></div>';
}
function renderFlowRows(){
    $('#flowRows').html((CUR._ef||[]).map(function(s,i){ return flowRowHtml(i,s); }).join('') || '<div class="hint">目前沒有簽核關卡＝送出後直接完成。</div>');
    $('#btnFlowAdd').toggle((CUR._ef||[]).length<4);
}
function readFlowRows(){
    var out=[]; $('#flowRows .flow-row').each(function(){
        var $r=$(this); out.push({label:$.trim($r.find('.fl-label').val()), mode:$r.find('.fl-mode').val(), dept_id:+$r.find('.fl-dept').val()||0, user_id:+$r.find('.fl-user').val()||0, auto:$r.find('.fl-auto').is(':checked')?1:0, all:($r.find('.fl-mode').val()==='dept' && $r.find('.fl-all').is(':checked'))?1:0});
    });
    return out;
}
function renderMTags(){
    $('#stMTags').html((CUR._em||[]).map(function(m){ return '<span class="tg">'+esc(m.name)+'<i data-id="'+m.id+'">✕</i></span>'; }).join('') || '<span class="hint">尚未指定維護人員</span>');
}
$('#btnDzFlow').on('click', function(){
    CUR._ef = JSON.parse(JSON.stringify(CUR.flow||[])); CUR._em = JSON.parse(JSON.stringify(CUR.maintainers||[]));
    $('#stStamp').html('<option value="0">（預設樣式）</option>' + (META.stamp_tpls||[]).map(function(t){ return '<option value="'+t.id+'">'+esc(t.tpl_name||t.name||('樣式 '+t.id))+'</option>'; }).join('')).val(CUR.stampTpl||0);
    $('#stMDept').html('<option value="0">（不指定）</option>' + (META.departments||[]).map(function(d){ return '<option value="'+d.id+'">'+esc(d.name)+'</option>'; }).join('')).val(CUR.maintainDept||0);
    $('#stMUser').html('<option value="">請選擇要加入的人員…</option>' + (META.people_full||[]).map(function(p){ return '<option value="'+p.id+'">'+esc(p.dept+' '+p.pos+' '+p.name)+'</option>'; }).join(''));
    renderFlowRows(); renderMTags(); $('#stErr').text(''); openMask('stMask');
});
$('#btnFlowAdd').on('click', function(){ CUR._ef = readFlowRows(); if (CUR._ef.length>=4) return; CUR._ef.push({label: CUR._ef.length===0?'審核':'核准', mode:'dept', dept_id:0, user_id:0, auto:0}); renderFlowRows(); });
$('#flowRows').on('click', '.fl-del', function(){ CUR._ef = readFlowRows(); CUR._ef.splice(+$(this).closest('.flow-row').attr('data-i'),1); renderFlowRows(); });
$('#flowRows').on('change', '.fl-mode', function(){ var $r=$(this).closest('.flow-row'), m=$(this).val(); $r.find('.fl-dept-w').toggle(m==='dept'); $r.find('.fl-user-w').toggle(m==='user'); $r.find('.fl-all-w').toggle(m==='dept'); });
$('#stMUser').on('change', function(){
    var id=+$(this).val(); if (!id) return;
    var p=(META.people_full||[]).filter(function(x){ return x.id===id; })[0];
    if (p && !(CUR._em||[]).some(function(m){ return m.id===id; })) CUR._em.push({id:id, name:p.name});
    $(this).val(''); renderMTags();
});
$('#stMTags').on('click', 'i', function(){ var id=+$(this).data('id'); CUR._em=(CUR._em||[]).filter(function(m){ return m.id!==id; }); renderMTags(); });
$('#btnStOk').on('click', function(){
    var fl = readFlowRows();
    for (var i=0;i<fl.length;i++){
        if (!fl[i].label){ $('#stErr').text('第 '+(i+1)+' 關請輸入關卡名稱'); return; }
        if (fl[i].mode==='dept' && !fl[i].dept_id){ $('#stErr').text('第 '+(i+1)+' 關請選擇簽核部門'); return; }
        if (fl[i].mode==='user' && !fl[i].user_id){ $('#stErr').text('第 '+(i+1)+' 關請選擇簽核人員'); return; }
    }
    CUR.flow = fl; CUR.stampTpl = +$('#stStamp').val()||0; CUR.maintainDept = +$('#stMDept').val()||0; CUR.maintainers = CUR._em||[];
    CUR.dirty = true; $('#dzDirty').show(); refreshFlowInfo(); closeMask('stMask');
});

/* ---------------- 預覽填寫畫面（用目前畫面上的內容，不必先存檔） ---------------- */
var PV = null;
$('#btnDzPreview').on('click', function(){
    var fields = CUR.ctl.listFields();
    if (!fields.length){ alert('還沒有任何待填欄位，沒有東西可預覽。'); return; }
    var book = CUR.ctl.exportBook(), def = CUR.defaults, values = {}, extra = 0;
    if (def && def.rows){ values = def.values; extra = def.rows-1; }
    $('#fmEngine').empty().append('<div id="fmU" style="position:absolute;inset:0;"></div>');
    window.__ownCompany = '';
    $('#fmTitle').text(($.trim($('#dzName').val())||'（未命名模板）')+' — 預覽'); $('#fmState').html('<span class="st-badge st-active">預覽</span>');
    $('#fmName').val('').prop('disabled', true); $('#fmDate').val(META.today).prop('disabled', true); $('#fmWho').text('預覽（內容不會儲存）');
    $('#fmDecide').hide().html('');
    $('#fmFlow').html((CUR.flow||[]).length ? '<div class="fm-flow"><b style="color:#8A5A2B">簽核流程：</b>填表 → '+CUR.flow.map(function(s){ return esc(s.label)+'（'+MODE_LABEL[s.mode]+(s.all?'・會簽':'')+(s.auto?'・自動':'')+'）'; }).join(' → ')+'</div>' : '');
    $('#fmBase,#fmDetail,#fmCalc').html('<div style="padding:20px;color:#8a6d45">載入中…</div>'); $('#fmErr').text('');
    $('#fmMask').addClass('on preview');
    PV = { ctl: EGSheetForm.mount({ el:'fmU', mode:'fill', book:book, fields:fields, values:values, extraRows:extra, people:META.people, toast:toast }) };
    PV.ctl.ready.then(function(){
        PV.ui = EGSheetFormUI.create({ root:'#fmMask', ctl:PV.ctl, fields:fields, canEdit:true, people:META.people, lookupUrl:API });
        PV.ui.build();
    });
});
function closePreview(){ if (PV){ if (PV.ui) PV.ui.destroy(); if (PV.ctl) PV.ctl.dispose(); PV = null; } $('#fmMask').removeClass('on preview'); $('#fmEngine').empty(); }
$('#btnFmClose,#btnFmX').on('click', closePreview);

function saveDesigner(cb){
    var name = $.trim($('#dzName').val());
    if (!name){ alert('請輸入模板名稱'); $('#dzName').focus(); return; }
    var bookJson = JSON.stringify(CUR.ctl.exportBook());
    post({action:'template_save', id:CUR.id, name:name, paper_size:$('#dzPaper').val(), orientation:$('#dzOrient').val(), book_json:bookJson, as_doc_id:CUR.docId||0,
          defaults_json: CUR.defaults ? JSON.stringify(CUR.defaults) : '', flow_json: (CUR.flow&&CUR.flow.length) ? JSON.stringify(CUR.flow) : '',
          maintain_dept_id: CUR.maintainDept||0, maintainers: JSON.stringify((CUR.maintainers||[]).map(function(m){ return m.id; })), stamp_tpl_id: CUR.stampTpl||0}, function(res){
        CUR.id = res.id; CUR.dirty = false; $('#dzDirty').hide(); toast('已儲存'); loadList(); cb&&cb();
    });
}
$('#btnDzSave').on('click', function(){ saveDesigner(); });
$('#dzPaper,#dzOrient,#dzName').on('change input', function(){ if (CUR){ CUR.dirty = true; $('#dzDirty').show(); } });
$('#btnDzClose').on('click', function(){
    if (CUR && CUR.dirty && !confirm('還有尚未儲存的修改，確定要關閉嗎？')) return;
    if (CUR && CUR.ctl) CUR.ctl.dispose();
    CUR = null; $('#dz').removeClass('on'); $('#dzSheet').empty();
});
<?php endif; ?>
</script>
</body>
</html>
