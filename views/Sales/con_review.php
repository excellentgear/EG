<?php
/**
 * 合約訂單審查表（AS 2-SM-01-06）
 * 架構說明與設計決策全部寫在 src/common/con_review_lib.php 檔頭，這裡只是畫面。
 * 一張訂單一份（稽核製程標籤才需要）、業務日期鎖接單日、內容部門逐項自填自簽、
 * 業務課決行、總經理核准。資料操作走 src/store/ConReview_API.php。
 */
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/Sales/con_review.php";
    header("Location:../../index.php");
    exit;
}
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/con_review_lib.php';

if (!function_exists('safe_html')) { function safe_html($v) { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); } }

$db = (new DBConnection())->getPDO();
cnrv_ensure_schema($db);
$cnrvUser = cnrv_current_user($db);
$perms = cnrv_perms($db, $cnrvUser);
$roleLabel = $perms['isAdmin'] ? '管理者' : ($perms['canAdmin'] ? '審查表管理員' : ($perms['canCreate'] ? '可建立審查' : ($perms['canView'] ? '檢視/填寫' : '無權限')));
$companyName = eg_company_full_name($db);
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>合約訂單審查表</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <style>
        #sidebar-menu { visibility: hidden; }
        .right_col .page-title { margin:8px 0 4px; overflow:hidden; clear:both; }
        .page-help-btn { height:30px; font-size:13px; padding:0 12px; border:1px solid #d98a33; border-radius:15px;
            background:#F0A24B; color:#fff; cursor:pointer; margin-left:auto; }
        .page-help-btn:hover { background:#d98a33; }
        @media print { .page-help-btn { display:none !important; } }
        .help-doc { font-size:13px; color:#5b3a1e; line-height:1.75; }
        .help-doc h4 { color:#8A5A2B; border-bottom:2px solid #F7E0BD; padding-bottom:3px; margin:14px 0 6px; font-size:15px; }
        .help-doc h4:first-child { margin-top:0; }
        .help-doc b { color:#8A5A2B; }

        .cr-toolbar { display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-bottom:12px; }
        .cr-toolbar select, .cr-toolbar input[type=text] { height:32px; border:1px solid #E8D5B5; border-radius:4px; padding:0 8px; }
        .cr-btn { height:32px; padding:0 14px; border:1px solid #d98a33; background:#F0A24B; color:#fff; border-radius:4px; cursor:pointer; font-size:13px; }
        .cr-btn:hover { background:#d98a33; }
        .cr-btn.b-plain { background:#fff; color:#8a6d45; border-color:#E8D5B5; }
        .cr-btn.b-plain:hover { background:#FDF6EC; }
        .cr-btn:disabled { opacity:.5; cursor:not-allowed; }

        table.cr-tbl { width:100%; border-collapse:collapse; background:#fff; }
        table.cr-tbl th, table.cr-tbl td { border:1px solid #EFE3D1; padding:6px 8px; font-size:13px; }
        table.cr-tbl th { background:#FDF6EC; color:#8a6d45; text-align:left; }
        table.cr-tbl tr:hover { background:#FFFBF3; }
        .st-badge { display:inline-block; padding:1px 8px; border-radius:10px; font-size:11.5px; line-height:18px; }
        .st-draft { background:#F3EADC; color:#8a6d45; }
        .st-submitted { background:#FFF3E2; color:#8a5a2b; border:1px solid #E4D3BC; }
        .st-closed { background:#E7F3E8; color:#2d6a3e; }
        .st-void { background:#F0F0F0; color:#999; }
        .dc-accept { color:#2d6a3e; font-weight:600; }
        .dc-conditional { color:#b5862f; font-weight:600; }
        .dc-reject { color:#c0392b; font-weight:600; }

        .cr-mask { display:none; position:fixed; inset:0; background:rgba(0,0,0,.4); z-index:9000; overflow:auto; padding:30px 0; }
        .cr-modal { background:#fff; border-radius:8px; margin:0 auto; max-width:960px; box-shadow:0 8px 30px rgba(0,0,0,.25); }
        .cr-modal.narrow { max-width:520px; }
        .m-head { display:flex; justify-content:space-between; align-items:center; padding:14px 18px; border-bottom:1px solid #EFE3D1; font-size:16px; font-weight:600; color:#5b3a1e; }
        .m-close { cursor:pointer; color:#aaa; font-size:18px; }
        .m-close:hover { color:#666; }
        .m-body { padding:16px 18px; max-height:72vh; overflow:auto; }
        .m-foot { padding:12px 18px; border-top:1px solid #EFE3D1; text-align:right; }
        .m-body label { display:block; font-size:12.5px; color:#8a6d45; margin:8px 0 2px; }
        .m-body input[type=text], .m-body input[type=date], .m-body select, .m-body textarea { width:100%; border:1px solid #E8D5B5; border-radius:4px; padding:5px 8px; font-size:13px; }

        .cr-sec-title { font-size:14px; font-weight:600; color:#5b3a1e; margin:16px 0 8px; border-left:4px solid #F0A24B; padding-left:8px; }
        .cr-hdr-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:10px 18px; background:#FDF6EC; border:1px solid #E8D5B5; border-radius:6px; padding:12px 14px; font-size:13px; }
        .cr-hdr-grid .k { color:#8a6d45; } .cr-hdr-grid .v { font-weight:600; color:#333; }

        .cr-grp-row td { background:#F7F0E4; font-weight:600; color:#8a5a2b; }
        .cr-dept-box { border:1px solid #E8D5B5; border-radius:6px; padding:10px 12px; margin-bottom:8px; }
        .cr-dept-box.signed { background:#E7F3E8; border-color:#bfe0c4; }
        .cr-dept-box .hd { display:flex; justify-content:space-between; align-items:center; }
        .ord-opt { padding:6px 8px; border-bottom:1px solid #F3EADC; cursor:pointer; }
        .ord-opt:hover { background:#FDF6EC; }
        .ord-opt.disabled { cursor:not-allowed; opacity:.55; }
        .rf-mini-btn { font-size:11px; color:#b5862f; cursor:pointer; text-decoration:underline; }

        @media print { .cr-toolbar, .page-help-btn, .cr-print-hide { display:none !important; } }
    </style>
</head>
<body class="nav-sm">
<div class="container body">
<div class="main_container">
<?php include '../partPage/sideAndTopBarMenu.html' ?>

<div class="right_col" role="main" style="min-height:100vh;">
    <div class="page-title" style="display:flex;align-items:center;flex-wrap:wrap;">
        <h3 style="margin:6px 0;">合約訂單審查表 <small>（2-SM-01-06）</small></h3>
        <span style="font-size:12.5px;color:#8a6d45;margin-left:12px;">身分：<?= safe_html($roleLabel) ?></span>
        <button type="button" class="page-help-btn" id="btnPageHelp" style="margin-left:auto;"><i class="fa fa-question-circle"></i> 使用說明</button>
    </div>
    <div class="clearfix"></div>

    <div class="cr-toolbar cr-print-hide">
        <?php if ($perms['canCreate']): ?><button type="button" class="cr-btn" id="btnAdd"><i class="fa fa-plus"></i> 新增審查</button>
        <button type="button" class="cr-btn b-plain" id="btnSuggest"><i class="fa fa-magic"></i> 建議建立清單</button><?php endif; ?>
        <select id="filterStatus"><option value="">全部狀態</option><option value="draft">草稿</option><option value="submitted">審查中</option><option value="closed">已結案</option></select>
        <input type="text" id="filterKw" placeholder="訂單編號／客戶／料號／編號 搜尋" style="width:220px;">
        <button type="button" class="cr-btn b-plain" id="btnSearch"><i class="fa fa-search"></i> 查詢</button>
        <?php if ($perms['canAdmin']): ?>
        <label style="display:flex;align-items:center;gap:4px;font-size:12px;color:#8a6d45;cursor:pointer;margin-left:6px;">
            <input type="checkbox" id="chkAdminSignCol"> 顯示管理員代簽標記
        </label>
        <button type="button" class="cr-btn b-plain" id="btnTpl" style="margin-left:auto;"><i class="fa fa-list"></i> 範本維護</button>
        <button type="button" class="cr-btn b-plain" id="btnPrintSet"><i class="fa fa-cog"></i> 列印設定</button>
        <button type="button" class="cr-btn b-plain" id="btnPermCheck"><i class="fa fa-user-circle-o"></i> 簽核人員權限檢查</button>
        <?php endif; ?>
    </div>

    <table class="cr-tbl">
        <thead><tr><th style="width:120px;">審查單號</th><th>訂單</th><th>客戶</th><th>料號</th><th style="width:110px;">AS 認定</th><th style="width:90px;">業務日期</th><th style="width:70px;">狀態</th><th style="width:120px;">決行</th>
            <?php if ($perms['canAdmin']): ?><th class="cr-admin-col" style="width:120px;display:none;">管理員代簽</th><?php endif; ?>
            <th style="width:70px;"></th></tr></thead>
        <tbody id="listBody"><tr><td colspan="<?= $perms['canAdmin'] ? 10 : 9 ?>" style="text-align:center;color:#999;">載入中…</td></tr></tbody>
    </table>
    <div style="margin-top:10px;font-size:12.5px;color:#8a6d45;" id="listTotal"></div>
</div></div></div>

<!-- 新增審查：挑訂單 -->
<div class="cr-mask" id="addMask"><div class="cr-modal narrow">
    <div class="m-head"><span>新增合約訂單審查</span><span class="m-close" onclick="closeMask('addMask')">✕</span></div>
    <div class="m-body">
        <label>來源訂單<span style="color:#c0392b;">＊</span></label>
        <input type="text" id="addOrdKw" placeholder="輸入訂單編號／客戶／料號搜尋（至少 2 個字）" autocomplete="off">
        <div id="addOrdList" style="max-height:220px;overflow:auto;border:1px solid #E8D5B5;border-radius:4px;margin-top:4px;display:none;"></div>
        <div id="addOrdPicked" style="display:none;background:#FDF6EC;border:1px solid #E8D5B5;border-radius:4px;padding:8px 10px;margin-top:6px;font-size:12.5px;line-height:1.7;"></div>
        <div id="addOrdErr" style="color:#c0392b;font-size:12px;display:none;margin-top:4px;"></div>
    </div>
    <div class="m-foot"><button type="button" class="cr-btn b-plain" onclick="closeMask('addMask')">取消</button> <button type="button" class="cr-btn" onclick="submitAdd()">建立</button></div>
</div></div>

<!-- 詳情 -->
<div class="cr-mask" id="viewMask"><div class="cr-modal">
    <div class="m-head"><span id="vwTitle">合約訂單審查</span><span class="m-close" onclick="closeMask('viewMask')">✕</span></div>
    <div class="m-body" id="vwBody">載入中…</div>
    <div class="m-foot cr-print-hide"><button type="button" class="cr-btn b-plain" onclick="closeMask('viewMask')">關閉</button> <button type="button" class="cr-btn b-plain" onclick="printDoc()"><i class="fa fa-print"></i> 列印</button></div>
</div></div>

<!-- 建議建立清單 -->
<div class="cr-mask" id="suggestMask"><div class="cr-modal">
    <div class="m-head"><span>建議建立清單</span><span class="m-close" onclick="closeMask('suggestMask')">✕</span></div>
    <div class="m-body">
        <p style="font-size:12.5px;color:#8a6d45;">以下是「AS 認定需要審查、但還沒建立審查表單」的訂單（舊資料不強制補，這裡只是方便一次補齊，由您決定要不要建立）。</p>
        <div style="display:flex;gap:8px;align-items:center;margin-bottom:8px;">
            <label style="margin:0;font-size:12.5px;">範圍：</label>
            <select id="suggestDays" style="height:30px;border:1px solid #E8D5B5;border-radius:4px;padding:0 6px;">
                <option value="30">最近 30 天</option><option value="60">最近 60 天</option><option value="90">最近 90 天</option><option value="0">不限（全部）</option>
            </select>
            <button type="button" class="cr-btn b-plain" style="height:30px;" onclick="loadSuggest()">重新查詢</button>
            <input type="text" id="suggestClientKw" placeholder="輸入客戶部份ID或部份名稱篩選" style="height:30px;width:200px;border:1px solid #E8D5B5;border-radius:4px;padding:0 8px;">
            <span id="suggestTotal" style="font-size:12.5px;color:#8a6d45;margin-left:auto;"></span>
        </div>
        <table class="cr-tbl">
            <thead><tr><th style="width:36px;text-align:center;"><input type="checkbox" id="suggestAll" onchange="suggestToggleAll(this.checked)"></th><th>訂單</th><th>客戶</th><th>料號</th><th style="width:90px;">接單日期</th><th style="width:110px;">AS 認定</th></tr></thead>
            <tbody id="suggestBody"><tr><td colspan="6" style="text-align:center;color:#999;">載入中…</td></tr></tbody>
        </table>
    </div>
    <div class="m-foot"><span id="suggestPickedCount" style="float:left;font-size:12.5px;color:#8a6d45;margin-top:6px;"></span>
        <button type="button" class="cr-btn b-plain" onclick="closeMask('suggestMask')">關閉</button>
        <button type="button" class="cr-btn" onclick="suggestBatchCreate()"><i class="fa fa-magic"></i> 一鍵建立已勾選</button></div>
</div></div>

<!-- 自動填寫並簽核 -->
<div class="cr-mask" id="autoFillMask"><div class="cr-modal narrow">
    <div class="m-head"><span>自動填寫並簽核</span><span class="m-close" onclick="closeMask('autoFillMask')">✕</span></div>
    <div class="m-body" id="autoFillBody">
        <p style="font-size:12.5px;color:#8a6d45;">尚未送出會先自動送出；尚未填寫的項目用範本設定的「預設值」帶入（沒有設定預設值的項目會跳過，不會憑空填上去）；內容部門逐一嘗試自動簽核，<b>只有當天真的有上班的人才會被記為簽核人，當天不在的一律不簽、改列原因讓您自己決定</b>。已填寫/已簽核的內容不會被覆蓋。</p>
        <label>簽核日期</label>
        <input type="date" id="autoFillDate" max="9999-12-31">
        <div id="autoFillDateHint" style="font-size:11.5px;color:#8a6d45;margin-top:2px;"></div>
        <div id="autoFillResult" style="margin-top:12px;"></div>
    </div>
    <div class="m-foot"><button type="button" class="cr-btn b-plain" onclick="closeMask('autoFillMask')">關閉</button>
        <button type="button" class="cr-btn" id="btnAutoFillGo" onclick="submitAutoFillSign()"><i class="fa fa-magic"></i> 執行</button></div>
</div></div>

<!-- 範本維護 -->
<div class="cr-mask" id="tplMask"><div class="cr-modal">
    <div class="m-head"><span>範本項目維護</span><span class="m-close" onclick="closeMask('tplMask')">✕</span></div>
    <div class="m-body">
        <div style="margin-bottom:10px;">
            <button type="button" class="cr-btn b-plain" onclick="tplAdd()"><i class="fa fa-plus"></i> 新增項目</button>
            <button type="button" class="cr-btn b-plain" onclick="tplSeedFromDevEval()" title="把產品開發評估表 2-TD-02-01 的 32 項確認項目複製過來，之後兩邊各自獨立"><i class="fa fa-download"></i> 從產品開發評估表複製項目</button>
        </div>
        <table class="cr-tbl">
            <thead><tr><th style="width:30px;" title="拖曳調整順序"><i class="fa fa-sort"></i></th><th style="width:70px;">區分</th><th>項目內容</th><th style="width:110px;">負責部門</th><th style="width:140px;">額外選項(逗號分隔)</th><th style="width:100px;">預設-是否</th><th style="width:100px;">預設-額外</th><th style="width:50px;">啟用</th><th style="width:60px;"></th></tr></thead>
            <tbody id="tplBody"></tbody>
        </table>
    </div>
    <div class="m-foot"><button type="button" class="cr-btn b-plain" onclick="closeMask('tplMask')">關閉</button></div>
</div></div>

<!-- 列印設定（AS 文件編號綁定＋簽章圖章模板，僅管理員，ai-rules/16一之三／ai-rules/18） -->
<div class="cr-mask" id="printSetMask"><div class="cr-modal narrow">
    <div class="m-head"><span>列印設定</span><span class="m-close" onclick="closeMask('printSetMask')">✕</span></div>
    <div class="m-body">
        <label>綁定 AS 文件編號</label>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <span id="psAsDocLabel" style="flex:1;min-width:200px;padding:6px 10px;background:#FDF6EC;border:1px solid #E8D5B5;border-radius:4px;color:#8a6d45;">尚未綁定</span>
            <button type="button" class="cr-btn b-plain" style="height:30px;" onclick="pickPrintAsDoc()"><i class="fa fa-search"></i> 選擇</button>
            <button type="button" class="cr-btn b-plain" style="height:30px;" onclick="clearPrintAsDoc()"><i class="fa fa-times"></i> 取消</button>
        </div>
        <div style="font-size:11.5px;color:#8a6d45;margin-top:4px;">列印表頭取這份文件的名稱，頁尾右下角印文件編號（版次依該筆審查的接單日期回推）。</div>

        <label style="margin-top:14px;">簽章圖章模板</label>
        <select id="psStampTpl"><option value="0">（用系統預設印章）</option></select>
        <div style="font-size:11.5px;color:#8a6d45;margin-top:4px;">各內容部門簽核、業務課決行、總經理核准，列印與畫面上一律蓋這個模板的圖章；未指定則用系統預設回墨印。模板在「圖章管理 → 線上圖章設計」建立。</div>
    </div>
    <div class="m-foot"><button type="button" class="cr-btn b-plain" onclick="closeMask('printSetMask')">取消</button> <button type="button" class="cr-btn" onclick="savePrintSetting()"><i class="fa fa-save"></i> 儲存</button></div>
</div></div>

<!-- 簽核人員權限檢查（僅管理員，2026-10-05 使用者提問交辦）：解析出來的部門/決行/核准人員
     有沒有實際被指派本頁的檢視權限，沒有的人點開待簽通知只會看到 403，系統不會主動提醒 -->
<div class="cr-mask" id="permCheckMask"><div class="cr-modal">
    <div class="m-head"><span>簽核人員權限檢查</span><span class="m-close" onclick="closeMask('permCheckMask')">✕</span></div>
    <div class="m-body">
        <p style="font-size:12.5px;color:#8a6d45;">內容部門填寫/簽核、業務課決行、總經理核准，系統都是依組織架構（部門主管／業務課主管／最高核准人員）自動解析出候選人；但要真的打得開本頁或 API，這個人還要<b>另外被指派「合約訂單審查-檢視/填寫」角色（或以上）</b>才行——兩者是分開的設定，部門主管換人、組織角色改綁定時，新人選很可能完全沒有這個角色指派。以下是目前解析出來的每一位候選人，<span style="color:#c0392b;">紅字＝這個人目前打不開本頁</span>。</p>
        <div id="permCheckBody" style="margin:10px 0;">載入中…</div>
        <div id="permCheckGapBar" style="display:none;background:#FFF3EE;border:1px dashed #DD5138;border-radius:6px;padding:8px 10px;font-size:12.5px;color:#c0392b;margin-top:6px;"></div>
    </div>
    <div class="m-foot">
        <span id="permCheckFixHint" style="float:left;font-size:12px;color:#8a6d45;margin-top:6px;"></span>
        <button type="button" class="cr-btn b-plain" onclick="closeMask('permCheckMask')">關閉</button>
        <button type="button" class="cr-btn" id="btnPermFix" style="display:none;" onclick="submitPermFix()"><i class="fa fa-magic"></i> 一鍵補上缺少的檢視權限</button>
    </div>
</div></div>

<!-- 使用說明 -->
<div class="cr-mask" id="helpUseMask"><div class="cr-modal">
    <div class="m-head"><span>使用說明</span><span class="m-close" onclick="closeMask('helpUseMask')">✕</span></div>
    <div class="m-body help-doc">
        <h4>這張表單在做什麼</h4>
        <p>AS9100 7.2 合約訂單審查：接到一張訂單時，評估「人機料法環」等能力是否足以接單。<b>只有訂單追蹤設定的「稽核製程標籤」判定為需要審查的訂單</b>（AS 認證範圍內的製程）才需要建立這張表單，其餘訂單（全製、單製非AS認證、廠內治具）不需要。</p>
        <h4>操作步驟</h4>
        <ul>
            <li>按「新增審查」挑選一張需要審查的訂單（已建過的會標示出來、不可重複建立）；或按「建議建立清單」一次列出所有還沒建立的訂單，勾選後「一鍵建立已勾選」。</li>
            <li>建立後業務日期自動帶入該訂單的接單日期，<b>不可修改</b>——審查的是「接單那一刻」的狀況；訂單後續若有變更，走另一份文件「訂單修改審查記錄表」。</li>
            <li>按「送出」後，負責各項目的部門才能開始填寫並簽核。</li>
            <li>各部門填完自己負責的項目後按「本課確認」簽核；<b>全部內容部門簽完</b>才能進行業務課決行。</li>
            <li>業務課決行（可接單／可接單需客戶確認條件／不可接單）後，交由總經理核准，核准後表單結案。</li>
        </ul>
        <h4>項目範本</h4>
        <p>項目範本由管理員在「範本維護」裡增刪修改，可一次從產品開發評估表複製 32 項當起點——<b>複製後兩邊完全獨立</b>，之後互不影響。最左邊拖曳 <i class="fa fa-bars"></i> 可調整項目順序（順序自動編號，不必自己填數字）。每個項目的結果固定可選「是／否／N/A」三選一，管理員可再加「額外選項」（逗號分隔），<b>部門實際填寫答案時</b>可下拉選擇也可以自己打字輸入。</p>
        <p><b>「預設-是否」與「預設-額外」是兩個各自獨立的設定</b>，不是合併成同一個下拉選一個——可以兩個都設、只設一個，或都不設：「預設-是否」只能選「是／否／N/A」三者之一；「預設-額外」只能從這個項目目前設定的額外選項裡選一個（改了額外選項，候選清單會跟著更新，原本選的如果不在新清單裡會自動清空要求重選）。自動填寫時，兩邊有設定的部分會合併成最終答案（例如「否、需要外包」）。</p>
        <h4>管理員：自動填寫並簽核</h4>
        <p>給例行、低風險的訂單快速走完內容部門這一段：未送出的會先自動送出，尚未填寫的項目依範本設定的「預設值」帶入（沒設定預設值的項目會跳過），內容部門逐一嘗試自動簽核。<b>簽核日期預設是接單日期，可改成之後的日期但必須是工作日</b>；<b>只有當天真的有上班（在職、沒請假、沒整天公出）的人才會被記為簽核人</b>，當天都不在就不自動簽、列出原因讓管理員自己處理，絕不會蓋一個當天根本不在的人的章。已填寫的項目、已簽核的部門不會被覆蓋。業務課決行／總經理核准仍需要人工進行，不會被這個功能代勞。</p>
        <h4>管理員：刪除表單</h4>
        <p>只能刪除<b>還沒結案</b>的審查表單（草稿或審查中）——已結案代表總經理已經核准，是正式紀錄，不可刪除。刪除後該訂單可以重新建立一張新的審查表單；刪除留有紀錄（誰在什麼時候刪的），不是真的從資料庫消失。</p>
        <h4>誰能填寫／簽核</h4>
        <p>每個項目指定一個負責部門，<b>該部門的人</b>（部門主管優先，沒有主管時部門內任一人）就能填寫與簽核該部門的項目，不需要額外指派角色；要能進入本頁檢視/填寫，仍需要管理員指派「con_review_view」角色。</p>
        <h4>權限角色</h4>
        <ul><li><b>con_review_view</b>：檢視清單、填寫/簽核自己部門的項目</li><li><b>con_review_create</b>：建立新的審查表單</li><li><b>con_review_admin</b>：範本維護、補資料、作廢</li></ul>
    </div>
    <div class="m-foot"><button type="button" class="cr-btn b-plain" onclick="closeMask('helpUseMask')">關閉</button></div>
</div></div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/fastclick.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script src="../../resource/js/Sortable.min.js"></script>
<script src="../../resource/js/eg_date_fmt.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_date_fmt.js') ?>"></script>
<script src="../../resource/js/eg_stamp_tpl.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_stamp_tpl.js') ?>"></script>
<script src="../../resource/js/eg_stamp.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_stamp.js') ?>"></script>
<script src="../../resource/js/eg_asdoc_picker.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_asdoc_picker.js') ?>"></script>
<script src="../../resource/js/eg_input_rules.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_input_rules.js') ?>"></script>
<script>
$(document).ready(function(){ $('#sidebar-menu').css('visibility','visible'); });
$('#btnPageHelp').on('click', function(){ openMask('helpUseMask'); });

var API = '../../src/store/ConReview_API.php';
var META = {};
var DECISIONS = {};
window.__ownCompany = <?= json_encode($companyName, JSON_UNESCAPED_UNICODE) ?>;

function esc(s){ return $('<div>').text(s==null?'':String(s)).html(); }
function dispDate(d, withTime){ return d ? egFmtDate(d, withTime) : ''; }   // eg_date_fmt.js 只匯出 egFmtDate()，dispDate() 是各頁慣例自己包一層（ai-rules/20）
function openMask(id){ $('#'+id).css('display','block'); }
function closeMask(id){ $('#'+id).css('display','none'); }
// 簽章一律走 eg_stamp.js 產生帶日期印章（ai-rules/18），不可只印人名文字；模板由管理員在「列印設定」指定
// （META.stamp_tpl，未指定則 EGStamp.stamp 自動退回系統預設回墨印），畫面與列印共用同一支。
function stampHtml(name, dateStr, isDeputy){
    if (!name) return '';
    var schema = (META.stamp_tpl && META.stamp_tpl.schema) ? META.stamp_tpl.schema : null;
    try { if (window.EGStamp && EGStamp.stamp) return EGStamp.stamp(name, dateStr||'', !!isDeputy, schema); } catch(e){}
    return esc(name);
}
var STATUS_LABEL = {draft:'草稿', submitted:'審查中', closed:'已結案', void:'已作廢'};

function loadMeta(cb){
    $.getJSON(API, {action:'meta'}, function(res){
        if (!res.ok){ alert(res.error||'載入失敗'); return; }
        META = res; DECISIONS = res.decisions || {};
        if (cb) cb();
    });
}

/* ───────────────── 清單 ───────────────── */
// 「顯示管理員代簽標記」開關：只有管理員（canAdmin＝全站超管或本頁管理員角色）看得到
// （後端 list 的 has_admin_sign 欄位同規則才會收到），開了才在清單多一欄；2026-10-05 使用者
// 更正——這才是「LOG」該有的樣子，不是在表單畫面上掛一塊寫著 LOG 字樣的小標籤。
// 這裡一定要用 canAdmin 不可以用 isAdmin——PHP 端的表頭（<th class="cr-admin-col">）是用
// canAdmin 決定要不要輸出的，這裡判斷錯邊會讓本頁管理員（canAdmin=true, isAdmin=false）的
// 表頭有這一欄、資料列卻少一個 <td>，整排往左擠一欄，「開啟」按鈕就會跑到「管理員代簽」
// 欄位底下（2026-10-05 使用者截圖回報的症狀）。
var SHOW_ADMIN_SIGN_COL = false;
function listColspan(){ return (META.perms && META.perms.canAdmin) ? 10 : 9; }
function loadList(){
    $.getJSON(API, {action:'list', status:$('#filterStatus').val(), keyword:$.trim($('#filterKw').val())}, function(res){
        if (!res.ok){ alert(res.error||'載入失敗'); return; }
        var isAdmin = !!(META.perms && META.perms.canAdmin);
        var h = '';
        (res.rows||[]).forEach(function(r){
            var dc = r.decision ? '<span class="dc-'+r.decision+'">'+esc(DECISIONS[r.decision]||r.decision)+'</span>' : '<span style="color:#bbb;">—</span>';
            // 2026-10-05 使用者要求：這欄要顯示代簽的管理員「名稱＋換行＋日期時間」，不是靜態的
            // 「管理員代簽」標籤文字——一張單可能有好幾段代簽（內容部門自動帶入/補登、決行、
            // 核准），逐段各自一行名稱+日期時間疊著顯示；仍抓不到姓名時退回舊的靜態標籤。
            var evHtml = (r.admin_sign_events || []).map(function(ev){
                var at = String(ev.at || ''), dPart = at.substring(0,10), tPart = at.substring(11,16);
                return '<div style="margin-bottom:2px;">' + esc(ev.name) + '<br>' + dispDate(dPart) + (tPart ? (' ' + tPart) : '') + '</div>';
            }).join('');
            if (!evHtml && r.has_admin_sign) evHtml = '管理員代簽';
            var adminCell = isAdmin
                ? '<td class="cr-admin-col" style="'+(SHOW_ADMIN_SIGN_COL?'':'display:none;')+'font-size:11px;color:#b5862f;line-height:1.3;">'
                  + evHtml + '</td>'
                : '';
            h += '<tr><td>'+esc(r.doc_no)+'</td><td>'+esc(r.order_oo)+'</td><td>'+esc(r.client_name)+'</td><td>'+esc(r.part_no_text)+'</td>'
               + '<td>'+esc(r.tag_label||'')+'</td>'
               + '<td>'+dispDate(r.business_date)+'</td><td><span class="st-badge st-'+r.status+'">'+STATUS_LABEL[r.status]+'</span></td>'
               + '<td>'+dc+'</td>'+adminCell+'<td><button type="button" class="cr-btn b-plain" style="padding:2px 10px;height:26px;" onclick="openView('+r.id+')">開啟</button></td></tr>';
        });
        $('#listBody').html(h || '<tr><td colspan="'+listColspan()+'" style="text-align:center;color:#999;">沒有符合條件的資料</td></tr>');
        $('#listTotal').text('共 ' + (res.total||0) + ' 筆');
    });
}
$('#chkAdminSignCol').on('change', function(){
    SHOW_ADMIN_SIGN_COL = this.checked;
    $('.cr-admin-col').toggle(SHOW_ADMIN_SIGN_COL);
});
$('#btnSearch').on('click', loadList);
$('#filterStatus').on('change', loadList);
$('#filterKw').on('keydown', function(e){ if (e.key==='Enter') loadList(); });

/* ───────────────── 新增（挑訂單） ───────────────── */
var ADD_ORD = null, ADD_T = null, ADD_SEQ = 0;
$('#btnAdd').on('click', function(){
    ADD_ORD = null; $('#addOrdPicked').hide().empty(); $('#addOrdKw').val(''); $('#addOrdList').hide().empty(); $('#addOrdErr').hide();
    openMask('addMask');
});
$('#addOrdKw').on('input', function(){
    var kw = $.trim(this.value);
    clearTimeout(ADD_T);
    if (kw.length < 2) { $('#addOrdList').hide().empty(); return; }
    ADD_T = setTimeout(function(){
        var seq = ++ADD_SEQ;
        $.getJSON(API, {action:'order_search', kw:kw}, function(res){
            if (seq !== ADD_SEQ || !res.ok) return;
            var h = (res.orders||[]).map(function(o){
                var done = o.doc_id ? '<span style="color:#c0392b;">（已建過）</span>' : '';
                return '<div class="ord-opt'+(o.doc_id?' disabled':'')+'" data-id="'+o.Order_id+'">'
                     + '<b>'+esc(o.Order_oo||('#'+o.Order_id))+'</b> '+done
                     + '<div style="font-size:11.5px;color:#8a6d45;">'+esc(o.client_name_txt||o.Client_name||'')
                     + '｜'+esc(o.d_id||'')+'｜'+Number(o.Qty||0).toLocaleString()+' 件｜接單 '+dispDate(o.Order_date)
                     + '｜<span style="color:#b5862f;">'+esc(o.tag_label||'')+'</span></div></div>';
            }).join('');
            if (!h) h = '<div style="padding:8px;color:#8a6d45;font-size:12px;">查不到符合的訂單（只列出「稽核製程」標籤的訂單）</div>';
            $('#addOrdList').html(h).show();
        });
    }, 300);
});
$(document).on('click', '#addOrdList .ord-opt:not(.disabled)', function(){
    var id = $(this).data('id');
    $.getJSON(API, {action:'order_get', order_id:id}, function(res){
        if (!res.ok){ alert(res.error||'讀取訂單失敗'); return; }
        var o = res.order;
        if (o.doc_id){ $('#addOrdErr').text('這張訂單已經建立過審查表單').show(); return; }
        if (!o.need_review){ $('#addOrdErr').text('這張訂單的 AS 認定不需要做合約訂單審查').show(); return; }
        ADD_ORD = o; $('#addOrdErr').hide(); $('#addOrdList').hide().empty(); $('#addOrdKw').val('');
        $('#addOrdPicked').html('<b>'+esc(o.Order_oo)+'</b>　<span class="rf-mini-btn" onclick="addOrdClear()">改選</span>'
            + '<br>客戶：'+esc(o.client_name_txt||o.Client_name||'（未綁客戶主檔）')
            + '<br>料號：'+esc(o.d_id||'')+'　數量：'+Number(o.Qty||0).toLocaleString()+' 件'
            + '<br>接單日期：'+dispDate(o.Order_date)+'　交期：'+dispDate(o.Delivery_date)
            + '<br>AS 認定：'+esc(o.tag_label||'')).show();
    });
});
function addOrdClear(){ ADD_ORD = null; $('#addOrdPicked').hide().empty(); $('#addOrdKw').val('').focus(); }
function submitAdd(){
    if (!ADD_ORD){ $('#addOrdErr').text('請先選擇來源訂單').show(); return; }
    $.post(API, {action:'create', csrf:META.csrf, order_id:ADD_ORD.Order_id}, function(res){
        if (!res.ok){ alert(res.error||'建立失敗'); return; }
        closeMask('addMask'); loadList(); openView(res.id);
    }, 'json');
}

/* ───────────────── 建議建立清單（批次一鍵建立） ───────────────── */
var SUGGEST_ROWS = [], SUGGEST_PICKED = {};   // order_id => true，用物件而非只看畫面勾選，篩選前後才不會把已勾的洗掉
$('#btnSuggest').on('click', function(){ SUGGEST_PICKED = {}; $('#suggestClientKw').val(''); openMask('suggestMask'); loadSuggest(); });
function loadSuggest(){
    $('#suggestBody').html('<tr><td colspan="6" style="text-align:center;color:#999;">載入中…</td></tr>');
    $.getJSON(API, {action:'suggest_list', days:$('#suggestDays').val()}, function(res){
        if (!res.ok){ alert(res.error||'載入失敗'); return; }
        SUGGEST_ROWS = res.rows || [];
        SUGGEST_LAST_TOTAL = res.total || 0;
        suggestApplyFilter();
    });
}
/* 客戶部份ID或部份名稱即時篩選（2026-10-05 使用者要求）：資料已經整批載入畫面，
   直接在前端過濾即時顯示，不必每打一個字都打一次後端。 */
var SUGGEST_LAST_TOTAL = 0;
function suggestApplyFilter(){
    var kw = $.trim($('#suggestClientKw').val()).toLowerCase();
    var rows = !kw ? SUGGEST_ROWS : SUGGEST_ROWS.filter(function(o){
        var idTxt = String(o.client_id||'').toLowerCase();
        var nameTxt = String(o.client_name_txt||o.Client_name||'').toLowerCase();
        return idTxt.indexOf(kw) !== -1 || nameTxt.indexOf(kw) !== -1;
    });
    var h = rows.map(function(o){
        var checked = SUGGEST_PICKED[o.Order_id] ? ' checked' : '';
        return '<tr><td style="text-align:center;"><input type="checkbox" class="suggest-chk" value="'+o.Order_id+'"'+checked+'></td>'
             + '<td>'+esc(o.Order_oo||('#'+o.Order_id))+'</td><td>'+esc(o.client_name_txt||o.Client_name||'')+(o.client_id?' <span style="color:#b5862f;font-size:11px;">('+esc(o.client_id)+')</span>':'')+'</td>'
             + '<td>'+esc(o.d_id||'')+'</td><td>'+dispDate(o.Order_date)+'</td><td>'+esc(o.tag_label||'')+'</td></tr>';
    }).join('');
    $('#suggestBody').html(h || '<tr><td colspan="6" style="text-align:center;color:#999;">'+(kw?'沒有符合「'+esc(kw)+'」的訂單':'目前沒有待建議建立的訂單')+'</td></tr>');
    $('#suggestAll').prop('checked', rows.length>0 && rows.every(function(o){ return SUGGEST_PICKED[o.Order_id]; }));
    var shown = SUGGEST_ROWS.length;
    $('#suggestTotal').text(kw ? ('篩選出 '+rows.length+' / 已載入 '+shown+' 筆')
                               : (SUGGEST_LAST_TOTAL > shown ? ('顯示 '+shown+' / 共 '+SUGGEST_LAST_TOTAL+'（調整範圍可看到更多）') : ('共 '+SUGGEST_LAST_TOTAL+' 筆')));
    suggestUpdateCount();
}
$('#suggestClientKw').on('input', suggestApplyFilter);
function suggestToggleAll(on){
    $('.suggest-chk').prop('checked', on).each(function(){ SUGGEST_PICKED[$(this).val()] = on ? true : undefined; });
    suggestUpdateCount();
}
$(document).on('change', '.suggest-chk', function(){ SUGGEST_PICKED[this.value] = this.checked ? true : undefined; suggestUpdateCount(); });
function suggestUpdateCount(){
    var n = Object.keys(SUGGEST_PICKED).filter(function(k){ return SUGGEST_PICKED[k]; }).length;
    $('#suggestPickedCount').text(n ? ('已勾選 '+n+' 筆（篩選不會洗掉已勾選的）') : '');
}
function suggestBatchCreate(){
    // 用 SUGGEST_PICKED 不是 $('.suggest-chk:checked')——篩選過後只有「目前看得到」的列在 DOM 裡，
    // 篩選前勾選、篩選後不在畫面上的那些也要一起送出，否則會悄悄漏掉使用者已經勾選的訂單。
    var ids = Object.keys(SUGGEST_PICKED).filter(function(k){ return SUGGEST_PICKED[k]; });
    if (!ids.length){ alert('請先勾選要建立的訂單'); return; }
    if (!confirm('確定要一次建立 '+ids.length+' 張合約訂單審查表嗎？')) return;
    $.post(API, {action:'batch_create', csrf:META.csrf, order_ids:ids.join(',')}, function(res){
        if (!res.ok){ alert(res.error||'建立失敗'); return; }
        var failN = Object.keys(res.failed||{}).length;
        var msg = '已建立 '+res.created_count+' 張';
        if (failN) msg += '，'+failN+' 張失敗：\n' + Object.values(res.failed).join('\n');
        alert(msg);
        closeMask('suggestMask'); loadList();
    }, 'json');
}

/* ───────────────── 詳情 ───────────────── */
var CUR = null;
function openView(id){
    $.getJSON(API, {action:'get', id:id}, function(res){
        if (!res.ok){ alert(res.error||'載入失敗'); return; }
        CUR = res;
        $('#vwTitle').text('合約訂單審查 — ' + esc(res.doc.doc_no));
        renderView();
        openMask('viewMask');
    });
}
// 2026-10-05 使用者要求：結果一律隱藏「是/否/N-A」字樣、直接顯示額外選項內容（兩者過去是用
// 「、」接在一起存成同一個 answer_value，舊資料也是這種格式）；若整個答案就只有「是/否/N-A」
// 本身、後面沒有接額外文字，仍然顯示原值——不然那一格會整個空白、完全看不出其實已經填過。
function resultText(v){
    v = String(v==null?'':v);
    var stripped = v.replace(/^(是|否|N\/A)[、,，]\s*/, '');
    return stripped.trim() !== '' ? stripped : v;
}
function itemFieldHtml(it, editable){
    // 回簽（填寫）時不再提供「是/否/N-A」選項，只提供這個項目自己的額外選項（cnrv_fill_options()
    // 已經在後端把基礎三選項濾掉了），但 <input type=text> 本來就還是能自由輸入任何文字。
    var opts = it.options || [];
    var dlId = 'dl_'+it.id;
    var dl = opts.length ? '<datalist id="'+dlId+'">'+opts.map(function(o){return '<option value="'+esc(o)+'"></option>';}).join('')+'</datalist>' : '';
    if (!editable) return esc(resultText(it.answer_value)) + dl;
    return '<input type="text" list="'+dlId+'" value="'+esc(resultText(it.answer_value))+'" placeholder="可選擇或自行填寫" '
         + 'onchange="itemSave('+it.id+',this.value,null)" style="width:100%;border:1px solid #E8D5B5;border-radius:4px;padding:3px 6px;font-size:12.5px;">' + dl;
}
function renderView(){
    var d = CUR.doc, items = CUR.items, depts = CUR.depts;
    var h = '<div class="cr-hdr-grid">'
        + '<div><span class="k">訂單編號：</span><span class="v">'+esc(d.order_oo)+'</span></div>'
        + '<div><span class="k">客戶：</span><span class="v">'+esc(d.client_name)+'</span></div>'
        + '<div><span class="k">料號：</span><span class="v">'+esc(d.part_no_text)+'</span></div>'
        + '<div><span class="k">數量：</span><span class="v">'+Number(d.qty||0).toLocaleString()+'</span></div>'
        + '<div><span class="k">接單日期(業務日期)：</span><span class="v">'+dispDate(d.business_date)+'</span></div>'
        + '<div><span class="k">交期：</span><span class="v">'+dispDate(d.delivery_date)+'</span></div>'
        + '<div><span class="k">AS 認定：</span><span class="v">'+esc(d.tag_label)+'</span></div>'
        + '<div><span class="k">狀態：</span><span class="v"><span class="st-badge st-'+d.status+'">'+STATUS_LABEL[d.status]+'</span></span></div>'
        + '<div><span class="k">審查單號：</span><span class="v">'+esc(d.doc_no)+'</span></div>'
        + '</div>';

    // 管理員「自動填寫並簽核」（2026-10-05 使用者交辦）：給例行、低風險訂單一鍵快速走完
    // 送出＋逐項帶入範本預設值＋內容部門自動簽核；業務課決行／總經理核准仍要人工進行。
    // 刪除（2026-10-05 使用者交辦「可刪除未審核的」，同日再交辦「也要能刪除舊有已決行(含已結案)
    // 的表單」）：管理員不論狀態一律可刪，放在最右側並用警示色與既有按鈕明顯區隔，避免誤按。
    if (CUR.is_admin) {
        var adminPanel = '';
        if (d.status==='draft' || d.status==='submitted') {
            adminPanel += '<button type="button" class="cr-btn b-plain" onclick="openAutoFillSign()"><i class="fa fa-magic"></i> 自動填寫並簽核</button>'
                        + '<span style="font-size:11.5px;color:#8a6d45;">用範本設定的預設值快速帶入尚未填寫的項目，並自動完成內容部門簽核（已填寫/已簽核的不會被覆蓋）。</span>';
        }
        adminPanel += '<button type="button" class="cr-btn" style="margin-left:auto;background:#fff;color:#c0392b;border-color:#e4b2ac;" onclick="deleteDoc()"><i class="fa fa-trash"></i> 刪除此表單</button>';
        h += '<div style="margin:10px 0;padding:10px 12px;background:#FDF6EC;border:1px dashed #E8D5B5;border-radius:6px;display:flex;align-items:center;flex-wrap:wrap;gap:8px;">'+adminPanel+'</div>';
    }

    h += '<div class="cr-sec-title">審查項目</div>';
    h += '<table class="cr-tbl"><thead><tr><th style="width:60px;">區分</th><th>項目內容</th><th style="width:90px;">負責部門</th><th style="width:170px;">結果</th><th style="width:120px;">填寫人</th></tr></thead><tbody>';
    var lastGrp = null;
    items.forEach(function(it){
        var dp = depts.find(function(x){ return x.dept_id===it.dept_id; });
        var editable = d.status==='submitted' && dp && dp.can_fill && !dp.signed;
        h += '<tr><td>'+esc(it.group_label)+'</td><td>'+esc(it.item_text)+'</td><td>'+esc(it.dept_name||'（未指定）')+'</td>'
           + '<td>'+itemFieldHtml(it, editable)+'</td><td style="font-size:11.5px;color:#8a6d45;">'+esc(it.filled_by_name||'')+'</td></tr>';
    });
    h += '</tbody></table>';

    h += '<div class="cr-sec-title">內容部門簽核</div>';
    depts.forEach(function(dp){
        h += '<div class="cr-dept-box'+(dp.signed?' signed':'')+'"><div class="hd"><b>'+esc(dp.dept_name)+'</b>';
        if (dp.signed) {
            // 簽核一律走圖章（ai-rules/18），不只印人名文字。is_auto_sign／is_backfill（這一章是
            // 管理員自動帶入／補登的）在這裡完全不顯示任何字樣——2026-10-05 使用者更正：這個紀錄
            // 要改成「清單上有個開關，開了才多一欄顯示管理員代簽」，不是在表單畫面上掛標籤。
            h += stampHtml(dp.signed_by_name, dispDate(String(dp.signed_at||'').substring(0,10)));
        } else if (d.status==='submitted' && (dp.can_fill || CUR.is_admin)) {
            h += '<span><input type="text" id="deptNote_'+dp.dept_id+'" placeholder="意見(選填)" style="width:200px;border:1px solid #E8D5B5;border-radius:4px;padding:3px 6px;font-size:12px;margin-right:6px;">'
               + '<button type="button" class="cr-btn" style="height:26px;padding:0 10px;font-size:12px;" onclick="deptSign('+dp.dept_id+')">本課確認</button></span>';
        } else {
            h += '<span style="color:#bbb;font-size:12px;">尚未簽核</span>';
        }
        h += '</div>'+(dp.note?('<div style="font-size:12px;color:#8a6d45;margin-top:4px;">意見：'+esc(dp.note)+'</div>'):'')+'</div>';
    });

    h += '<div class="cr-sec-title">決行與核准</div>';
    if (d.status==='draft') {
        h += '<p style="color:#8a6d45;font-size:13px;">尚未送出，送出後各負責部門才能填寫與簽核。</p>';
        if (CUR.can_edit_header) h += '<button type="button" class="cr-btn" onclick="submitDoc()">送出</button>';
    } else {
        // 決行／核准一律走圖章（ai-rules/18）。sales_decided_is_proxy／gm_approved_is_proxy
        // （操作者不是真正該簽的人、由管理員代為操作）在這裡完全不顯示任何字樣，查核入口在
        // 清單頁的「顯示管理員代簽標記」開關（2026-10-05 使用者更正）。
        h += '<div class="cr-hdr-grid" style="grid-template-columns:1fr 1fr;">';
        var decideStampHtml = d.decision
            ? ('<span class="dc-'+d.decision+'">'+esc(DECISIONS[d.decision])+'</span>　'+stampHtml(d.sales_decided_by_name, dispDate(String(d.sales_decided_at||'').substring(0,10))))
            : '尚未決行';
        h += '<div><span class="k">業務課決行：</span><span class="v">'+decideStampHtml+'</span></div>';
        var gmStampHtml = d.gm_approved_by_name
            ? stampHtml(d.gm_approved_by_name, dispDate(String(d.gm_approved_at||'').substring(0,10)), d.gm_is_deputy)
            : '尚未核准';
        h += '<div><span class="k">總經理核准：</span><span class="v">'+gmStampHtml+'</span></div>';
        h += '</div>';
        if (d.decision===null && d.status==='submitted') {
            if (CUR.all_depts_signed && (CUR.can_decide || CUR.is_admin)) {
                var opts = Object.keys(DECISIONS).map(function(k){ return '<option value="'+k+'">'+esc(DECISIONS[k])+'</option>'; }).join('');
                h += '<div style="margin-top:8px;"><select id="decideSel" style="width:220px;border:1px solid #E8D5B5;border-radius:4px;padding:5px;">'+opts+'</select> '
                   + '<input type="text" id="decideNote" placeholder="決行意見(選填)" style="width:260px;border:1px solid #E8D5B5;border-radius:4px;padding:5px 8px;"> '
                   + '<button type="button" class="cr-btn" onclick="decideDoc()">決行</button></div>';
            } else if (!CUR.all_depts_signed) {
                h += '<p style="color:#8a6d45;font-size:13px;margin-top:6px;">尚有內容部門未完成簽核，不可決行。</p>';
            }
        } else if (d.decision!==null && d.status==='submitted') {
            if (CUR.gm_signer && (CUR.gm_signer.id===META.uid || CUR.is_admin)) {
                h += '<div style="margin-top:8px;"><button type="button" class="cr-btn" onclick="approveDoc()">核准並結案</button></div>';
            } else {
                h += '<p style="color:#8a6d45;font-size:13px;margin-top:6px;">等待總經理'+(CUR.gm_signer?('（'+esc(CUR.gm_signer.user_cname)+'）'):'')+'核准。</p>';
            }
        }
    }
    $('#vwBody').html(h);
}
function itemSave(itemId, value, note){
    $.post(API, {action:'item_save', csrf:META.csrf, doc_id:CUR.doc.id, item_id:itemId, value:value||'', note:note||''}, function(res){
        if (!res.ok){ alert(res.error||'存檔失敗'); openView(CUR.doc.id); return; }
    }, 'json');
}
function deptSign(deptId){
    var note = $('#deptNote_'+deptId).val();
    $.post(API, {action:'dept_sign', csrf:META.csrf, doc_id:CUR.doc.id, dept_id:deptId, note:note}, function(res){
        if (!res.ok){ alert(res.error||'簽核失敗'); return; }
        openView(CUR.doc.id); loadList();
    }, 'json');
}
function submitDoc(){
    if (!confirm('送出後表頭將鎖定，確定送出？')) return;
    $.post(API, {action:'submit', csrf:META.csrf, doc_id:CUR.doc.id}, function(res){
        if (!res.ok){ alert(res.error||'送出失敗'); return; }
        openView(CUR.doc.id); loadList();
    }, 'json');
}
function decideDoc(){
    var decision = $('#decideSel').val(), note = $('#decideNote').val();
    $.post(API, {action:'decide', csrf:META.csrf, doc_id:CUR.doc.id, decision:decision, note:note}, function(res){
        if (!res.ok){ alert(res.error||'決行失敗'); return; }
        openView(CUR.doc.id); loadList();
    }, 'json');
}
function approveDoc(){
    if (!confirm('核准後本表單結案，確定核准？')) return;
    $.post(API, {action:'approve', csrf:META.csrf, doc_id:CUR.doc.id}, function(res){
        if (!res.ok){ alert(res.error||'核准失敗'); return; }
        openView(CUR.doc.id); loadList();
    }, 'json');
}
/* ───────────────── 列印（ai-rules/16：公司全名動態取、表頭取綁定AS文件表單名稱、頁碼左下
   只有多頁才印、AS編號右下每頁都印且版次依業務日期回推；ai-rules/18：簽章一律走圖章） ───────────────── */
function printDoc(){
    if (!CUR) return;
    $.getJSON(API, {action:'print_get', id:CUR.doc.id}, function(res){
        if (!res.ok){ alert(res.error||'載入列印資料失敗'); return; }
        var d = res.doc, items = res.items||[], depts = res.depts||[];
        var schema = (res.stamp_tpl && res.stamp_tpl.schema) ? res.stamp_tpl.schema : null;
        var pStamp = function(name, dt, isDeputy){
            if (!name) return '<div style="min-height:50px;"></div>';
            try { if (window.EGStamp && EGStamp.stamp) return EGStamp.stamp(name, dt||'', !!isDeputy, schema); } catch(e){}
            return esc(name);
        };
        var itemRows = items.map(function(it){
            return '<tr><td>'+esc(it.group_label)+'</td><td class="tl">'+esc(it.item_text)+'</td><td>'+esc(it.dept_name||'')+'</td>'
                 + '<td class="tl">'+esc(resultText(it.answer_value))+'</td><td>'+esc(it.filled_by_name||'')+'</td></tr>';
        }).join('');
        // 內容部門簽核改兩欄並列（2026-10-05 使用者要求）：每列放兩個部門，省掉的垂直空間讓整張表
        // 更容易印在一頁內；部門數是單數時最後一列右邊補空白儲存格補滿欄數。
        var deptCell = function(dp){
            if (!dp) return '<td class="dept"></td><td class="tl"></td>';
            return '<td class="dept">'+esc(dp.dept_name)+'</td><td class="tl">'
                 + (dp.note?('<div style="font-size:10px;color:#555;margin-bottom:2px;">'+esc(dp.note)+'</div>'):'')
                 + pStamp(dp.signed_by_name, dispDate(String(dp.signed_at||'').substring(0,10)))
                 + '</td>';
        };
        var deptRows = '';
        for (var di=0; di<depts.length; di+=2) deptRows += '<tr>'+deptCell(depts[di])+deptCell(depts[di+1])+'</tr>';
        var decisionTxt = d.decision ? esc(DECISIONS[d.decision]||d.decision) : '（未決行）';
        var body = '<div class="p-comp">'+esc(res.company_name)+'</div>'
            + '<div class="p-title">'+esc(res.as_doc_name)+'</div>'
            + '<table class="p-hd">'
            + '<tr><td>訂單編號</td><td>'+esc(d.order_oo)+'</td><td>客戶</td><td>'+esc(d.client_name)+'</td><td>料號</td><td>'+esc(d.part_no_text)+'</td></tr>'
            + '<tr><td>數量</td><td>'+Number(d.qty||0).toLocaleString()+'</td><td>接單日期</td><td>'+dispDate(d.business_date)+'</td><td>交期</td><td>'+dispDate(d.delivery_date)+'</td></tr>'
            + '<tr><td>AS 認定</td><td colspan="3">'+esc(d.tag_label||'')+'</td><td>審查單號</td><td>'+esc(d.doc_no)+'</td></tr>'
            + '</table>'
            + '<table class="p-tb"><thead><tr><th style="width:50px;">區分</th><th>項目內容</th><th style="width:70px;">負責部門</th><th style="width:110px;">結果</th><th style="width:70px;">填寫人</th></tr></thead><tbody>'+itemRows+'</tbody></table>'
            + '<div class="p-sec">內容部門簽核</div>'
            + '<table class="p-tb"><tbody>'+deptRows+'</tbody></table>'
            // 決行與核准改並列同一列（2026-10-05 使用者要求）：業務課決行／總經理核准各佔一半寬度。
            + '<div class="p-sec">決行與核准　決行結果：'+decisionTxt+'</div>'
            + '<table class="p-tb"><tr>'
            + '<td class="dept">業務課決行</td><td class="tl">'+pStamp(d.sales_decided_by_name, dispDate(String(d.sales_decided_at||'').substring(0,10)))+'</td>'
            + '<td class="dept">總經理核准</td><td class="tl">'+pStamp(d.gm_approved_by_name, dispDate(String(d.gm_approved_at||'').substring(0,10)), d.gm_is_deputy)+'</td>'
            + '</tr></table>';
        var css = 'body{font-family:"Microsoft JhengHei",sans-serif;margin:0;padding:0 6mm;color:#222;-webkit-print-color-adjust:exact;print-color-adjust:exact;}'
            + '.p-comp{font-size:22px;font-weight:bold;text-align:center;margin-bottom:1px;}'
            + '.p-title{font-size:17px;font-weight:bold;text-align:center;letter-spacing:4px;margin-bottom:10px;}'
            + '.p-sec{font-size:13px;font-weight:bold;color:#8A5A2B;border-left:4px solid #F0A24B;padding-left:6px;margin:10px 0 4px;break-after:avoid;}'
            + 'table.p-hd{width:100%;border-collapse:collapse;font-size:11px;margin-bottom:6px;}'
            + 'table.p-hd td{border:1px solid #666;padding:3px 5px;} table.p-hd td:nth-child(odd){background:#f3ead6;font-weight:bold;white-space:nowrap;}'
            + 'table.p-tb{width:100%;table-layout:fixed;border-collapse:collapse;font-size:10.5px;margin-bottom:4px;}'
            + 'table.p-tb thead{display:table-header-group;}'
            + 'table.p-tb th,table.p-tb td{border:1px solid #666;padding:3px 5px;text-align:center;overflow-wrap:anywhere;}'
            + 'table.p-tb thead th{background:#f3ead6;} table.p-tb td.tl{text-align:left;} table.p-tb td.dept{font-weight:bold;background:#f3ead6;width:70px;}'
            + 'table.p-tb tr{break-inside:avoid;}'
            + '.stamp-wrap{display:inline-block;text-align:center;margin:2px 10px 2px 0;}'
            // 列印預設 A4 直式（2026-10-05 使用者回報瀏覽器列印對話框一直跳出橫向，要求固定直式）：
            // size 要明講 portrait，不寫的話瀏覽器會沿用「使用者上次列印選的方向」，不是網頁內容的方向。
            + '@page{size:A4 portrait;margin:12mm 10mm 18mm;'
            + (res.as_doc_no ? " @bottom-right{ content:'"+String(res.as_doc_no).replace(/['\\]/g,'')+"'; font-size:9pt; color:#333; vertical-align:top; padding-top:1mm; }" : '')
            + '}';
        var w = window.open('', '_blank');
        w.document.write('<html><head><meta charset="utf-8"><title>合約訂單審查表</title><style>'+css+'</style></head><body>'+body+'</body></html>');
        w.document.close();
        // 頁碼左下角只有多頁才印（ai-rules/16）：寫入後量實際內容高度，超過單頁可印高度才補加頁碼樣式，
        // 不事先寫死，避免單頁報表也印出「第 1 頁／共 1 頁」。
        setTimeout(function(){
            try {
                var h = w.document.body.scrollHeight;
                if (h > 1000) {
                    var st = w.document.createElement('style');
                    st.textContent = "@page{ @bottom-left{ content:'第 ' counter(page) ' 頁／共 ' counter(pages) ' 頁'; font-size:9pt; color:#333; vertical-align:top; padding-top:1mm; } }";
                    w.document.head.appendChild(st);
                }
            } catch(e){}
            w.print();
        }, 250);
    });
}
function deleteDoc(){
    if (!confirm('確定要刪除這張審查表單嗎？\n訂單 '+CUR.doc.order_oo+'（'+CUR.doc.client_name+'），審查單號 '+CUR.doc.doc_no+'\n\n刪除後這張訂單可以重新建立一張新的審查表單，此動作無法由畫面復原。')) return;
    $.post(API, {action:'delete', csrf:META.csrf, doc_id:CUR.doc.id}, function(res){
        if (!res.ok){ alert(res.error||'刪除失敗'); return; }
        closeMask('viewMask'); loadList();
    }, 'json');
}

/* ───────────────── 管理員：自動填寫並簽核 ───────────────── */
function openAutoFillSign(){
    $('#autoFillDate').val(CUR.doc.business_date);
    $('#autoFillDateHint').text('不可早於接單日期 '+dispDate(CUR.doc.business_date)+'，且必須是工作日');
    $('#autoFillResult').empty();
    $('#btnAutoFillGo').prop('disabled', false).text('執行');
    openMask('autoFillMask');
}
function submitAutoFillSign(){
    var signDate = $('#autoFillDate').val();
    if (!signDate){ alert('請選擇簽核日期'); return; }
    $('#btnAutoFillGo').prop('disabled', true).text('執行中…');
    $.post(API, {action:'admin_auto_fill_sign', csrf:META.csrf, doc_id:CUR.doc.id, sign_date:signDate}, function(res){
        $('#btnAutoFillGo').prop('disabled', false).text('執行');
        if (!res.ok){ alert(res.error||'執行失敗'); return; }
        var msg = '已帶入 '+res.filled+' 個項目的預設值'+(res.skipped_no_default?('，'+res.skipped_no_default+' 個項目沒有設定預設值已略過'):'')+'。';
        var signedKeys = Object.keys(res.signed_depts||{});
        if (signedKeys.length) {
            msg += '\n\n已自動簽核：';
            signedKeys.forEach(function(k){
                var info = res.signed_depts[k];
                msg += '\n・'+info.dept_name+'　由 '+info.signer_name+' 簽核'
                     + (info.note_warnings && info.note_warnings.length ? '（原候選人當天不在：'+info.note_warnings.join('；')+'，已改由下一位）' : '');
            });
        }
        var unsignedKeys = Object.keys(res.unsigned_depts||{});
        if (unsignedKeys.length) {
            msg += '\n\n未能自動簽核（請自行處理）：';
            unsignedKeys.forEach(function(k){
                var info = res.unsigned_depts[k];
                msg += '\n・'+info.dept_name+'：'+info.reason;
            });
        }
        // 2026-10-05 使用者要求：執行完要關閉跳窗，不要還停留在原畫面，避免誤以為沒有完成——
        // 結果改用 alert 一次講清楚，關窗後詳情畫面本來就會即時重新整理顯示真實的簽核結果。
        closeMask('autoFillMask');
        alert(msg);
        openView(CUR.doc.id); loadList();
    }, 'json').fail(function(){ $('#btnAutoFillGo').prop('disabled', false).text('執行'); });
}

/* ───────────────── 列印設定（AS 文件編號綁定＋簽章圖章模板，僅管理員） ───────────────── */
var PRINT_SET = {docs:[], docId:0, doc:null};
$('#btnPrintSet').on('click', function(){
    $.getJSON(API, {action:'print_setting_get'}, function(res){
        if (!res.ok){ alert(res.error||'載入設定失敗'); return; }
        PRINT_SET.docs = res.as_docs||[]; PRINT_SET.docId = parseInt(res.as_doc_id||0)||0; PRINT_SET.doc = res.as_doc||null;
        renderPrintAsDocLabel();
        var $s = $('#psStampTpl').html('<option value="0">（用系統預設印章）</option>');
        (res.stamp_tpls||[]).forEach(function(t){
            $s.append('<option value="'+t.id+'">'+esc(t.tpl_name)+(t.type_name?'（'+esc(t.type_name)+'）':'')+'</option>');
        });
        $s.val(String(parseInt(res.stamp_tpl_id||0)||0));
        if ($s.val()===null) $s.val('0');
        openMask('printSetMask');
    });
});
function renderPrintAsDocLabel(){
    var txt = (window.EGAsDoc && EGAsDoc.label) ? EGAsDoc.label(PRINT_SET.doc) : (PRINT_SET.doc ? PRINT_SET.doc.doc_no : '尚未綁定');
    $('#psAsDocLabel').text(txt);
}
function pickPrintAsDoc(){
    EGAsDoc.open({docs:PRINT_SET.docs, current:PRINT_SET.docId, title:'合約訂單審查表－AS 文件編號綁定',
        onSave:function(id, doc){ PRINT_SET.docId = parseInt(id)||0; PRINT_SET.doc = doc||null; renderPrintAsDocLabel(); }});
}
function clearPrintAsDoc(){ PRINT_SET.docId = 0; PRINT_SET.doc = null; renderPrintAsDocLabel(); }
function savePrintSetting(){
    $.post(API, {action:'print_setting_save', csrf:META.csrf, as_doc_id:PRINT_SET.docId, stamp_tpl_id:parseInt($('#psStampTpl').val()||0)||0}, function(res){
        if (!res.ok){ alert(res.error||'儲存失敗'); return; }
        closeMask('printSetMask');
        loadMeta();   // 重新載入 META.as_doc／META.stamp_tpl，讓畫面上的圖章與 AS 編號立刻套用新設定
    }, 'json');
}

/* ───────────────── 簽核人員權限檢查（2026-10-05 使用者提問交辦） ───────────────── */
var PERM_CHECK_GAP_IDS = [];
$('#btnPermCheck').on('click', function(){ openMask('permCheckMask'); loadPermCheck(); });
function loadPermCheck(){
    $('#permCheckBody').html('載入中…');
    $('#permCheckGapBar').hide().empty();
    $('#btnPermFix').hide();
    $('#permCheckFixHint').text('');
    $.getJSON(API, {action:'perm_check'}, function(res){
        if (!res.ok){ $('#permCheckBody').html('<span style="color:#c0392b;">'+esc(res.error||'載入失敗')+'</span>'); return; }
        renderPermCheck(res);
    });
}
function permRowHtml(label, people){
    if (!people || !people.length) return '<tr><td style="font-weight:600;white-space:nowrap;">'+esc(label)+'</td><td style="color:#bbb;">（查無候選人員，請先到組織角色綁定設定）</td></tr>';
    var names = people.map(function(p){
        var style = p.can_view ? 'color:#2d6a3e;' : 'color:#c0392b;font-weight:600;';
        return '<span style="'+style+'">'+esc(p.user_cname)+(p.can_view?'':'（無本頁權限）')+'</span>';
    }).join('、');
    return '<tr><td style="font-weight:600;white-space:nowrap;vertical-align:top;">'+esc(label)+'</td><td>'+names+'</td></tr>';
}
function renderPermCheck(res){
    var h = '<table class="cr-tbl"><tbody>';
    (res.depts||[]).forEach(function(dp){ h += permRowHtml(dp.dept_name, dp.people); });
    h += permRowHtml('業務課決行', res.sales);
    h += permRowHtml('總經理核准', res.gm ? [res.gm] : []);
    h += '</tbody></table>';
    $('#permCheckBody').html(h);

    var gaps = res.gaps || [];
    PERM_CHECK_GAP_IDS = gaps.map(function(g){ return g.id; });
    if (gaps.length) {
        var list = gaps.map(function(g){ return esc(g.user_cname)+'（'+esc((g.scopes||[]).join('、'))+'）'; }).join('；');
        $('#permCheckGapBar').show().html('<b>以下 '+gaps.length+' 人目前打不開本頁，簽核通知點進去只會看到「沒有權限」：</b><br>'+list);
        $('#btnPermFix').show();
        $('#permCheckFixHint').text('一鍵補上＝幫上面這些人指派「合約訂單審查-檢視/填寫」角色（最低門檻，不會動到其他權限）。');
    } else {
        $('#permCheckGapBar').hide().empty();
        $('#btnPermFix').hide();
        $('#permCheckFixHint').text('目前解析出來的候選人都已經有本頁的檢視權限。');
    }
}
function submitPermFix(){
    if (!PERM_CHECK_GAP_IDS.length) return;
    if (!confirm('確定要幫這 '+PERM_CHECK_GAP_IDS.length+' 位補上「合約訂單審查-檢視/填寫」角色嗎？')) return;
    $.post(API, {action:'perm_fix', csrf:META.csrf, ids:PERM_CHECK_GAP_IDS.join(',')}, function(res){
        if (!res.ok){ alert(res.error||'補上失敗'); return; }
        alert('已補上 '+res.fixed+' 人。');
        renderPermCheck(res.result);
    }, 'json');
}

/* ───────────────── 範本維護 ───────────────── */
function loadTpl(){
    $.getJSON(API, {action:'tpl_list'}, function(res){
        if (!res.ok){ alert(res.error||'載入失敗'); return; }
        renderTpl(res.items);
    });
}
var CNRV_BASE_OPTIONS = ['是','否','N/A'];   // 跟後端 CNRV_BASE_OPTIONS 同一份固定基礎選項
/* 固定三選項下拉（是/否/N-A），與「額外選項」完全無關、不受它影響。 */
function tplBaseOptsHtml(curVal){
    var h = '<option value="">（不設定）</option>';
    CNRV_BASE_OPTIONS.forEach(function(o){ h += '<option value="'+esc(o)+'"'+(o===curVal?' selected':'')+'>'+esc(o)+'</option>'; });
    return h;
}
/* 額外選項下拉：只列這一列目前設定的額外選項（2026-10-05 使用者更正：這是跟「是/否/N-A預設」
   各自獨立的第二個設定，不是合併成同一個下拉選一個——可以兩個都設、也可以只設一個）。
   這一列還沒設定任何額外選項時顯示停用的提示，不給選。 */
function tplExtraOptsHtml(customOptsArr, curVal){
    var opts = customOptsArr || [];
    if (!opts.length) return '<option value="">（尚未設定額外選項）</option>';
    var h = '<option value="">（不設定）</option>';
    opts.forEach(function(o){ h += '<option value="'+esc(o)+'"'+(o===curVal?' selected':'')+'>'+esc(o)+'</option>'; });
    return h;
}
function renderTpl(items){
    var deptOpts = '<option value="">（未指定）</option>' + (META.departments||[]).map(function(d){ return '<option value="'+d.id+'">'+esc(d.name)+'</option>'; }).join('');
    var h = (items||[]).map(function(it){
        var sel = deptOpts.replace('value="'+it.dept_id+'"', 'value="'+it.dept_id+'" selected');
        return '<tr data-id="'+it.id+'">'
             + '<td class="tpl-drag-handle" style="text-align:center;cursor:move;color:#b5862f;" title="拖曳調整順序"><i class="fa fa-bars"></i></td>'
             + '<td><input type="text" data-f="group_label" value="'+esc(it.group_label)+'" style="width:60px;" onchange="tplEdit('+it.id+',this)"></td>'
             + '<td><input type="text" data-f="item_text" value="'+esc(it.item_text)+'" onchange="tplEdit('+it.id+',this)"></td>'
             + '<td><select data-f="dept_id" onchange="tplEdit('+it.id+',this)">'+sel+'</select></td>'
             + '<td><input type="text" data-f="options" value="'+esc((it.custom_options||[]).join(','))+'" title="在「是／否／N/A」固定三選項之外，這個項目額外可選的回覆" onchange="tplOptionsChanged('+it.id+',this)"></td>'
             // 兩個「預設值」各自獨立（2026-10-05 使用者明確要求「這兩個是分開設定，不是只能從
             // 裡面選一個」）：可以兩個都設、只設一個，或都不設。自動填寫時會把有值的部分合併。
             + '<td><select data-f="default_value" title="自動填寫並簽核時，若此項目尚未填寫答案，是/否/N-A 的預設值" onchange="tplEdit('+it.id+',this)">'+tplBaseOptsHtml(it.default_value)+'</select></td>'
             + '<td><select data-f="default_extra" title="自動填寫並簽核時，若此項目尚未填寫答案，額外選項的預設值（可與上面的是/否/N-A同時生效）" onchange="tplEdit('+it.id+',this)">'+tplExtraOptsHtml(it.custom_options, it.default_extra)+'</select></td>'
             + '<td style="text-align:center;"><input type="checkbox" data-f="is_active" '+(it.is_active?'checked':'')+' onchange="tplEdit('+it.id+',this)"></td>'
             + '<td style="text-align:center;"><span class="rf-mini-btn" onclick="tplDel('+it.id+')"><i class="fa fa-times"></i> 刪除</span></td></tr>';
    }).join('');
    $('#tplBody').html(h || '<tr><td colspan="9" style="text-align:center;color:#999;">尚無項目，可按上方「從產品開發評估表複製項目」當起點</td></tr>');
    initTplSortable();
}
/* 拖曳排序（使用者要求「範本項目維護順序要可以拖移更改」）：把手限定最左邊那一格
   （本頁其餘欄位全是輸入框/下拉，整列可拖會讓在欄位裡打字、選字變成拖列——
   跟站上 SOP/SIP 等頁面同一條既有規則）。放開當下依目前 DOM 順序整批重編號並存檔。 */
function initTplSortable(){
    var tbody = document.getElementById('tplBody');
    if (!tbody || typeof Sortable === 'undefined') return;
    if (tbody._sortable) tbody._sortable.destroy();
    tbody._sortable = Sortable.create(tbody, {
        animation: 150, handle: '.tpl-drag-handle',
        onEnd: function(){
            var ids = Array.prototype.map.call(tbody.querySelectorAll('tr[data-id]'), function(tr){ return tr.getAttribute('data-id'); });
            $.post(API, {action:'tpl_reorder', csrf:META.csrf, ids:ids.join(',')}, function(res){
                if (!res.ok){ alert(res.error||'排序儲存失敗'); loadTpl(); return; }
            }, 'json');
        }
    });
}
/* 改用 data-f 屬性而非位置索引讀欄位——新增欄位時位置索引很容易悄悄錯位（本頁已有前車之鑑）。 */
function tplRowData(tr){
    return {
        id: tr.data('id'),
        group_label: tr.find('[data-f=group_label]').val(),
        item_text: tr.find('[data-f=item_text]').val(),
        dept_id: tr.find('[data-f=dept_id]').val(),
        options: tr.find('[data-f=options]').val(),
        default_value: tr.find('[data-f=default_value]').val(),
        default_extra: tr.find('[data-f=default_extra]').val(),
        is_active: tr.find('[data-f=is_active]').is(':checked') ? 1 : 0,
    };
}
function tplEdit(id){
    var tr = $('#tplBody tr[data-id='+id+']');
    var data = tplRowData(tr);
    $.post(API, {action:'tpl_save', csrf:META.csrf, id:id, group_label:data.group_label,
                 item_text:data.item_text, dept_id:data.dept_id, options:data.options,
                 default_value:data.default_value, default_extra:data.default_extra, is_active:data.is_active}, function(res){
        if (!res.ok){ alert(res.error||'儲存失敗'); loadTpl(); return; }
    }, 'json');
}
/* 改「額外選項」時，「額外選項預設值」的候選清單要立刻跟著重建（select 是 renderTpl() 畫表格
   當下就定型的，不重建的話剛打的新選項要存檔+整表重畫才選得到）；原本選的值如果已經不在新
   清單裡就清空要求重選。**不影響「是/否/N-A預設值」那個下拉**——兩者各自獨立，改額外選項
   不會動到是/否/N-A的設定（2026-10-05 使用者明確要求兩者分開）。 */
function tplOptionsChanged(id, el){
    var tr = $('#tplBody tr[data-id='+id+']');
    var customOpts = $.trim(el.value).split(',').map(function(s){ return $.trim(s); }).filter(Boolean);
    var $de = tr.find('[data-f=default_extra]');
    var curVal = $de.val();
    $de.html(tplExtraOptsHtml(customOpts, curVal));
    tplEdit(id);
}
function tplAdd(){
    // 順序不必填，後端自動接在最後面（2026-10-05 使用者要求「順序請自動給」）。
    $.post(API, {action:'tpl_save', csrf:META.csrf, id:0, group_label:'', item_text:'（新項目，請編輯內容）', dept_id:0, options:'', default_value:'', default_extra:'', is_active:1}, function(res){
        if (!res.ok){ alert(res.error||'新增失敗'); return; }
        renderTpl(res.items);
    }, 'json');
}
function tplDel(id){
    if (!confirm('確定刪除此範本項目？（已建立的審查表單不受影響，只影響之後新建的）')) return;
    $.post(API, {action:'tpl_delete', csrf:META.csrf, id:id}, function(res){
        if (!res.ok){ alert(res.error||'刪除失敗'); return; }
        renderTpl(res.items);
    }, 'json');
}
function tplSeedFromDevEval(){
    $.getJSON(API, {action:'tpl_list'}, function(res){
        var has = (res.items||[]).length;
        var msg = has ? ('目前已經有 '+has+' 項，複製會「接在後面」不會覆蓋。要繼續嗎？')
                      : '要把產品開發評估表的 32 項確認項目複製過來嗎？\n\n複製過來之後兩邊各自獨立，可自行刪減修改。';
        if (!confirm(msg)) return;
        $.post(API, {action:'tpl_seed_from_dev_eval', csrf:META.csrf}, function(r2){
            if (!r2.ok){ alert(r2.error||'複製失敗'); return; }
            renderTpl(r2.items);
            alert('已複製 ' + r2.added + ' 項，請確認內容是否符合需要。');
        }, 'json');
    });
}
$('#btnTpl').on('click', function(){ loadTpl(); openMask('tplMask'); });

/* 深連結（2026-10-05）：從訂單追蹤頁的入口連結過來，語意比照 part_viewer 的 ?tags_setting=1——
   頁面照常載入之後多做一件事，守門與必填一字不動；查不到就安靜退回正常畫面，不留白畫面。
   這裡是一般 <a target="_blank"> 真的被使用者點擊開新分頁，不是 JS 觸發的 window.open()，
   不會被彈出視窗封鎖，不需要 document.open/write/close 那一套。 */
var Q_OPEN_ID = parseInt(new URLSearchParams(location.search).get('open_id'), 10) || 0;
var Q_NEW_ORDER = parseInt(new URLSearchParams(location.search).get('new_order'), 10) || 0;
function openAddForOrder(orderId){
    $('#btnAdd').trigger('click');
    $.getJSON(API, {action:'order_get', order_id:orderId}, function(res){
        if (!res.ok || !res.order) return;      // 查不到就停在「已開啟新增表單」，使用者自己挑
        var o = res.order;
        if (o.doc_id){ $('#addOrdErr').text('這張訂單已經建立過審查表單（#'+o.doc_id+'），請直接開啟原本那一張').show(); return; }
        if (!o.need_review){ $('#addOrdErr').text('這張訂單的 AS 認定不需要做合約訂單審查').show(); return; }
        ADD_ORD = o; $('#addOrdErr').hide();
        $('#addOrdPicked').html('<b>'+esc(o.Order_oo)+'</b>　<span class="rf-mini-btn" onclick="addOrdClear()">改選</span>'
            + '<br>客戶：'+esc(o.client_name_txt||o.Client_name||'（未綁客戶主檔）')
            + '<br>料號：'+esc(o.d_id||'')+'　數量：'+Number(o.Qty||0).toLocaleString()+' 件'
            + '<br>接單日期：'+dispDate(o.Order_date)+'　交期：'+dispDate(o.Delivery_date)
            + '<br>AS 認定：'+esc(o.tag_label||'')).show();
    });
}
loadMeta(function(){
    loadList();
    if (Q_OPEN_ID) openView(Q_OPEN_ID);
    else if (Q_NEW_ORDER) openAddForOrder(Q_NEW_ORDER);
});
</script>
</body>
</html>
