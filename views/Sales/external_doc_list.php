<?php
/**
 * 外來文件清單（AS9100 外來文件管制）
 * 附件標籤勾「列入外來文件」者（quotation_file_categories.is_external_doc）依料號/報價附件彙整成清單。
 * 欄位：客戶、料號、文件名稱、外來文件類別、發行日期(上傳日)、發行單位(SALES_SETTING 業務單位)。
 * 可切換「只看有訂單綁定的料號 / 所有有附件的料號」、指定客戶、年度；列印依客戶分組、右下角帶綁定的 AS 文件編號。
 * PFMEA 連動（2026-08-19）：每列顯示該料號是否已在 views/TD/pfmea.php 建表並可篩選；
 *   「PFMEA 缺件偵測」找出 PFMEA 已建立卻沒有任何外來文件的料號 → 建立「待補檔案」項目（external_doc_pending）
 *   → 上傳補檔存成該料號的料號附件，附件上傳日期＝使用者輸入的文件日期（＝清單的發行日期）。
 * 資料一律走 src/store/ExternalDoc_API.php。
 */
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/Sales/external_doc_list.php";
    header("Location:../../index.php");
    exit;
}
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/role_features_helper.php';

$db  = (new DBConnection())->getPDO();
$uid = (int)($_SESSION['id'] ?? 0);

// 權限：頁面 ACRUD 矩陣 OR external_doc 模組角色（與 ExternalDoc_API 同邏輯）
$extFeatures    = $uid ? rf_load_user_features_override($db, $uid, 'external_doc') : [];
$extIsRoleAdmin = in_array('all', $extFeatures, true);
$extPagePerm = '';
try {
    $st = $db->prepare("SELECT page_id, group_id FROM system_module_pages WHERE page_url LIKE '%views/Sales/external_doc_list.php' LIMIT 1");
    $st->execute();
    $pg = $st->fetch(PDO::FETCH_ASSOC);
    if ($pg) {
        $st = $db->prepare("SELECT permission FROM user_module_permissions WHERE user_id=? AND scope='page' AND module_code=?");
        $st->execute([$uid, $pg['page_id']]);
        $perms = $st->fetchAll(PDO::FETCH_COLUMN);
        if (empty($perms) && !empty($pg['group_id'])) {
            $st = $db->prepare("SELECT module_code FROM system_modules WHERE group_id=? LIMIT 1");
            $st->execute([$pg['group_id']]);
            $gCode = $st->fetchColumn();
            if ($gCode) {
                $st = $db->prepare("SELECT permission FROM user_module_permissions WHERE user_id=? AND scope='group' AND module_code=?");
                $st->execute([$uid, $gCode]);
                $perms = $st->fetchAll(PDO::FETCH_COLUMN);
            }
        }
        $chars = [];
        foreach ($perms as $p) { $chars = array_merge($chars, str_split($p)); }
        $extPagePerm = implode('', array_unique($chars));
    }
} catch (Exception $e) {}
$canView   = $extIsRoleAdmin || strpos($extPagePerm, 'A') !== false || strpos($extPagePerm, 'R') !== false
           || in_array('extdoc_view', $extFeatures, true);
$canManage = $extIsRoleAdmin || strpos($extPagePerm, 'A') !== false || in_array('extdoc_manage', $extFeatures, true);
$roleLabel = $extIsRoleAdmin ? '管理者' : ($canManage ? '外來文件管理' : ($canView ? '外來文件檢閱' : '無權限'));
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>外來文件清單</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <style>
        #sidebar-menu { visibility: hidden; }
        .right_col .page-title { margin:8px 0 4px; overflow:hidden; }
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
        .xd-toolbar { display:flex; flex-wrap:wrap; gap:6px 10px; align-items:center; clear:both;
            border:1.5px solid #E8D5B5; border-radius:8px; padding:8px 10px; margin-bottom:10px; background:#FDF8EF; }
        .xd-toolbar label { margin:0; font-size:13px; color:#5b3a1e; }
        .xd-toolbar select, .xd-toolbar button {
            height:30px; font-size:13px; line-height:1; padding:0 10px; border:1px solid #D8BE93;
            border-radius:4px; background:#fff; color:#5b3a1e; cursor:pointer; }
        .xd-toolbar button:hover { background:#F7E0BD; }
        .xd-toolbar .btn-warm { background:#F0A24B; color:#fff; border-color:#d98a33; }
        .xd-toolbar .btn-warm:hover { background:#d98a33; }
        .xd-role-badge { margin-left:auto; font-size:13px; color:#5b3a1e; background:#F7E0BD; border-radius:12px; padding:4px 12px; }
        .xd-role-badge .fa-question-circle { cursor:pointer; color:#b5762a; margin-left:5px; }
        .xd-mode { display:flex; border:1px solid #D8BE93; border-radius:4px; overflow:hidden; }
        .xd-mode button { border:none; border-radius:0; height:28px; }
        .xd-mode button.active { background:#F0A24B; color:#fff; }
        .xd-asdoc { font-size:12px; color:#8a6d45; margin-bottom:6px; }
        .xd-asdoc b { color:#8A5A2B; }
        .xd-pagebar { display:flex; justify-content:flex-end; align-items:center; gap:6px; margin-bottom:6px; font-size:13px; color:#5b3a1e; }
        .xd-pagebar select { height:26px; font-size:12px; border:1px solid #D8BE93; border-radius:4px; }
        .xd-pagebar button { height:26px; font-size:12px; padding:0 8px; border:1px solid #D8BE93; border-radius:4px; background:#fff; color:#5b3a1e; cursor:pointer; }
        .xd-pagebar button:disabled { color:#c9bda9; cursor:default; }
        .xd-pagebar button.cur { background:#F0A24B; color:#fff; border-color:#d98a33; }
        .xd-table-wrap { overflow-x:auto; border:1px solid #E8D5B5; border-radius:6px; background:#fff; }
        table.xd-table { width:100%; border-collapse:collapse; font-size:13px; }
        table.xd-table th, table.xd-table td { border:1px solid #EADFC8; padding:5px 8px; text-align:center; }
        table.xd-table thead th { background:#F7E0BD; color:#5b3a1e; font-weight:bold; white-space:nowrap; }
        table.xd-table tbody tr:nth-child(even) { background:#FBF6EC; }
        table.xd-table tbody tr:hover { background:#FBF0DD; }
        table.xd-table td.t-left { text-align:left; }
        .src-pill { display:inline-block; font-size:11px; border-radius:10px; padding:1px 8px; }
        .src-part { background:#F7E0BD; color:#7a5217; }
        .src-quote { background:#FFF3E2; color:#C77C1A; border:1px solid #E4D3BC; }
        .cat-pill { display:inline-block; font-size:11px; border:1px solid rgba(122,82,23,.25);
            border-radius:10px; padding:1px 8px; margin:1px 2px; }
        .cat-btn { height:26px; font-size:12px; padding:0 12px; border:1px solid rgba(122,82,23,.3);
            border-radius:13px; cursor:pointer; opacity:.55; }
        .cat-btn.active { opacity:1; box-shadow:0 0 0 2px #8A5A2B inset; font-weight:bold; }
        .xd-tabs { display:flex; gap:4px; margin-bottom:8px; border-bottom:2px solid #E8D5B5; }
        .xd-tab { border:1px solid #E8D5B5; border-bottom:none; background:#FBF3E5; color:#8a6d45; cursor:pointer;
            padding:7px 16px; font-size:14px; border-radius:6px 6px 0 0; margin-bottom:-2px; }
        .xd-tab.active { background:#fff; color:#5b3a1e; font-weight:bold; border-bottom:2px solid #fff; }
        .xd-cnt { display:inline-block; min-width:18px; padding:0 5px; margin-left:4px; border-radius:9px;
            background:#F0A24B; color:#fff; font-size:11px; line-height:16px; }
        .xd-cnt.zero { background:#E8D5B5; color:#8a6d45; }
        .pf-pill { display:inline-block; font-size:11px; border-radius:10px; padding:1px 8px; white-space:nowrap; }
        .pf-yes { background:#F0A24B; color:#fff; }
        .pf-no  { background:#F5EFE3; color:#8a6d45; border:1px solid #E8D5B5; }
        .xd-scan-tb { width:100%; border-collapse:collapse; font-size:12px; }
        .xd-scan-tb th, .xd-scan-tb td { border:1px solid #EADFC8; padding:4px 6px; text-align:center; }
        .xd-scan-tb thead th { background:#F7E0BD; position:sticky; top:0; }
        .xd-scan-tb td.tl { text-align:left; }
        .xd-req { color:#DD5138; }
        .xd-err { color:#DD5138; font-size:12px; margin-top:3px; min-height:16px; }
        .xd-catbox { border:1px solid #D8BE93; border-radius:4px; padding:6px 8px; max-height:130px; overflow-y:auto; background:#FDF8EF; }
        .xd-catbox label { display:inline-block !important; margin:2px 10px 2px 0; font-weight:normal; }
        a.xd-doclink { color:#b5762a; text-decoration:underline; }
        a.xd-doclink:hover { color:#8A5A2B; }
        /* 版本狀態（同料號＋同類別只留最新版）：暖色系，舊版整列淡化 */
        .ver-pill { display:inline-block; font-size:11px; border-radius:10px; padding:1px 8px; white-space:nowrap; }
        .ver-cur  { background:#F7E0BD; color:#6b4a1c; }
        .ver-pin  { background:#F0A24B; color:#fff; }
        .ver-keep { background:#EAD3A2; color:#6b4a1c; }
        .ver-old  { background:#EDE4D6; color:#8a7355; border:1px dashed #c9b48c; }
        tr.xd-oldver td { background:#FAF6EF; color:#8a7355; }
        .xd-op { color:#b5762a; cursor:pointer; white-space:nowrap; }
        .xd-op:hover { color:#DD5138; text-decoration:underline; }
        .xd-note-edit { border:1px solid #D8BE93; border-radius:4px; padding:3px 6px; font-size:12px; width:95%; }
        .xd-noperm { margin:40px auto; max-width:520px; text-align:center; border:1.5px solid #E8D5B5; border-radius:10px;
            padding:30px; background:#FDF8EF; color:#5b3a1e; }
        .xd-mask { display:none; position:fixed; inset:0; background:rgba(60,40,20,.45); z-index:1050; }
        .xd-modal { background:#fff; border-radius:8px; max-width:560px; margin:36px auto; box-shadow:0 5px 25px rgba(0,0,0,.3);
            max-height:90vh; display:flex; flex-direction:column; }
        .xd-modal .m-head { background:#F7E0BD; color:#5b3a1e; font-weight:bold; padding:10px 15px; border-radius:8px 8px 0 0;
            display:flex; justify-content:space-between; }
        .xd-modal .m-head .m-close { cursor:pointer; color:#b5762a; }
        .xd-modal .m-body { padding:15px; overflow-y:auto; font-size:13px; color:#5b3a1e; }
        .xd-modal .m-body label { display:block; font-size:13px; color:#5b3a1e; margin:9px 0 3px; }
        .xd-modal .m-body input[type=text], .xd-modal .m-body select { width:100%; border:1px solid #D8BE93; border-radius:4px;
            padding:5px 8px; font-size:13px; box-sizing:border-box; }
        .xd-modal .m-foot { padding:10px 15px; border-top:1px solid #EADFC8; text-align:right; }
        .xd-modal .m-foot button { height:30px; padding:0 16px; border-radius:4px; font-size:13px; border:1px solid #d98a33; cursor:pointer; }
        .xd-modal .m-foot .b-ok { background:#F0A24B; color:#fff; }
        .xd-modal .m-foot .b-no { background:#fff; color:#5b3a1e; border-color:#D8BE93; margin-left:6px; }
    </style>
</head>
<body class="nav-sm">
<div class="container body">
<div class="main_container">
    <?php include '../partPage/sideAndTopBarMenu.html' ?>
    <div class="right_col" role="main">
        <div class="page-title" style="display:flex;align-items:center;flex-wrap:wrap;">
            <h2 style="margin:6px 0;">外來文件清單
                <small style="color:#8a6d45;">附件標籤勾選「列入外來文件」者自動彙整（AS9100 外來文件管制）</small></h2>
            <button id="btnPageHelp" class="page-help-btn" style="margin-left:auto;"><i class="fa fa-question-circle"></i> 使用說明</button>
        </div>
        <div class="clearfix"></div>

<?php if (!$canView): ?>
        <div class="xd-noperm">
            <h4><i class="fa fa-lock"></i> 無外來文件清單檢閱權限</h4>
            <p>請洽管理者於「使用者權限設定」指派「外來文件檢閱／管理」角色。</p>
        </div>
<?php else: ?>
        <div class="xd-toolbar">
            <label>範圍</label>
            <span class="xd-mode">
                <button type="button" id="modeBound" class="active" title="只列出曾被任何訂單綁定過的料號">有訂單綁定的料號</button>
                <button type="button" id="modeAll" title="列出所有掛了外來文件附件的料號">所有有附件的料號</button>
            </span>
            <label>客戶</label>
            <input type="text" id="custKw" placeholder="ID/名稱模糊搜尋" style="height:30px;width:130px;border:1px solid #D8BE93;border-radius:4px;padding:0 8px;font-size:13px;">
            <select id="custSel"><option value="">全部客戶</option></select>
            <label>料號</label>
            <input type="text" id="partKw" placeholder="料號模糊搜尋" title="可打片段，多個關鍵字用空白分隔（每個都要命中）；不分大小寫、忽略空白" style="height:30px;width:150px;border:1px solid #D8BE93;border-radius:4px;padding:0 8px;font-size:13px;">
            <label>年度</label>
            <select id="yearSel"><option value="">全部年度</option></select>
            <label>PFMEA</label>
            <select id="pfmeaSel" title="是否已在 PFMEA（潛在失效模式及效應分析）建立此料號">
                <option value="">全部</option>
                <option value="yes">PFMEA 已建立</option>
                <option value="no">PFMEA 未建立</option>
            </select>
            <label id="histWrap" style="display:inline-flex;align-items:center;gap:4px;cursor:pointer;"
                   title="同一料號＋同一類別只列最新版；勾選後連被取代的舊版一起列出（灰色標示）">
                <input type="checkbox" id="chkHistory" style="margin:0;"> 顯示歷史版本
            </label>
            <button id="btnCsv"><i class="fa fa-file-text-o"></i> 匯出CSV</button>
            <button class="btn-warm" id="btnPrint"><i class="fa fa-print"></i> 列印清單</button>
            <?php if ($canManage): ?>
            <button id="btnPfmeaScan" title="找出「應該要有外來文件、但清單上一筆都沒有」的料號（來源可選：PFMEA 已建立／有專案但未建立）"><i class="fa fa-search"></i> 缺件偵測</button>
            <button id="btnAsDoc"><i class="fa fa-bookmark-o"></i> AS文件編號綁定</button>
            <?php endif; ?>
            <span class="xd-role-badge">目前角色：<b><?= htmlspecialchars($roleLabel) ?></b>
                <i class="fa fa-question-circle" id="btnRoleHelp" title="角色權限說明"></i></span>
        </div>

        <div class="xd-asdoc" id="asDocBar">AS 文件編號：<b id="asDocNo">尚未綁定</b>
            <span id="issueUnitBar" style="margin-left:14px;">發行單位：<b id="issueUnit">—</b></span></div>

        <div id="catFilterBar" style="display:none;flex-wrap:wrap;gap:5px;align-items:center;margin-bottom:8px;font-size:13px;color:#5b3a1e;">
            <span>類別：</span>
        </div>

        <div class="xd-tabs">
            <button type="button" class="xd-tab active" id="tabActive"><i class="fa fa-list"></i> 外來文件清單</button>
            <button type="button" class="xd-tab" id="tabExcluded"><i class="fa fa-ban"></i> 已排除</button>
            <button type="button" class="xd-tab" id="tabPending"><i class="fa fa-upload"></i> 待補檔案
                <span class="xd-cnt" id="pendCnt">0</span></button>
        </div>
        <div id="pendBar" style="display:none;margin-bottom:8px;font-size:12px;color:#8a6d45;">
            這裡是「PFMEA 已建立、但外來文件清單一筆都沒有」的料號，補上傳檔案後就會自動出現在正式清單（本分頁不列印、不匯出）。
            <b>從主檔管理／型態識別文件管制表／報價單上傳的外來文件也算數</b>，系統一偵測到就會自動把該筆移出待補清單。
            <label style="margin-left:10px;font-size:12px;"><input type="checkbox" id="chkIgnored" data-eg-skip> 顯示已標記「不列入」的（<span id="ignCnt">0</span>）</label>
        </div>

        <div class="xd-pagebar">
            <span id="totalInfo">共 0 筆</span>
            <label style="margin-left:8px;">每頁</label>
            <select id="perPageSel" data-eg-skip>
                <option>5</option><option selected>10</option><option>20</option><option>50</option>
            </select>
            <span id="pageBtns"></span>
        </div>

        <div class="xd-table-wrap">
            <table class="xd-table" id="xdTable">
                <thead><tr id="xdHead"></tr></thead>
                <tbody id="xdBody"></tbody>
            </table>
        </div>
        <div style="font-size:11px;color:#8a6d45;margin-top:4px;">
            發行日期＝附件上傳日期。「外來文件類別」顯示標籤設定的類別名稱（未設定則用標籤名稱）。
            點<b>料號</b>可開啟文件；備註直接回寫到附件本體（其他頁面看到同一筆備註）；列印不帶備註。
            同一份文件在報價單與料號都掛了附件而重複時，用「排除」把重複那筆移到「已排除」分頁（可隨時加回）。
            要把某類附件納入本清單：至報價單頁或主檔管理的「附件類別標籤設定」勾選「列入外來文件清單」。
            工具列的<b>料號</b>框可打片段模糊搜尋（多個關鍵字用空白分隔、不分大小寫、忽略空白），結果連動列印與 CSV。
            <b>PFMEA</b> 欄顯示該料號是否已在 PFMEA（潛在失效模式及效應分析）建表，可用工具列下拉篩選；
            「PFMEA 缺件偵測」找出 PFMEA 已建立卻沒有任何外來文件的料號，建立成「待補檔案」後可逐筆上傳補檔（可指定文件日期）。
        </div>
<?php endif; ?>
    </div>
</div>
</div>

<!-- PFMEA 缺件偵測結果 -->
<div class="xd-mask" id="scanMask"><div class="xd-modal" style="max-width:820px;">
    <div class="m-head"><span><i class="fa fa-search"></i> 缺件偵測</span><span class="m-close" onclick="closeMask('scanMask')">✕</span></div>
    <div class="m-body">
        <div style="font-size:12px;color:#8a6d45;margin-bottom:6px;">
            以下料號<b>應該要有外來文件、但清單上一筆都沒有</b>。
            勾選後按「建立待補項目」，就會出現在「待補檔案」分頁，可逐筆上傳檔案補齊。
        </div>
        <div style="border:1px solid #EADFC8;border-radius:6px;padding:6px 10px;margin-bottom:8px;font-size:12px;color:#5b3a1e;">
            <b>偵測來源</b>（可複選）：
            <label style="display:inline;margin-left:10px;"><input type="checkbox" class="scan-src" value="pfmea" checked data-eg-skip> PFMEA 已建立</label>
            <label style="display:inline;margin-left:14px;" title="有建立專案(2-GM-02)、但這個料號在外來文件清單一筆都沒有">
                <input type="checkbox" class="scan-src" value="project" data-eg-skip> 有專案但未建立</label>
            <button type="button" id="btnScanReload" style="margin-left:12px;height:24px;padding:0 10px;border:1px solid #D8BE93;border-radius:4px;background:#fff;cursor:pointer;">重新偵測</button>
        </div>
        <div id="scanSum" style="font-size:13px;color:#5b3a1e;margin-bottom:6px;"></div>
        <div style="max-height:340px;overflow:auto;border:1px solid #EADFC8;border-radius:4px;">
            <table class="xd-scan-tb">
                <thead><tr>
                    <th style="width:36px;"><input type="checkbox" id="scanAll" data-eg-skip title="全選/全不選"></th>
                    <th style="width:24%;">客戶</th><th>料號</th><th style="width:24%;">來源</th><th style="width:15%;">狀態</th>
                </tr></thead>
                <tbody id="scanBody"></tbody>
            </table>
        </div>
        <div id="scanUnres" style="font-size:12px;color:#DD5138;margin-top:6px;"></div>
    </div>
    <div class="m-foot">
        <button class="b-ok" id="btnScanCreate">建立待補項目</button>
        <button class="b-no" onclick="closeMask('scanMask')">關閉</button>
    </div>
</div></div>

<!-- 補檔上傳（存成料號附件；上傳日期＝輸入的文件日期） -->
<div class="xd-mask" id="upMask"><div class="xd-modal" style="max-width:600px;">
    <div class="m-head"><span><i class="fa fa-upload"></i> 上傳補檔</span><span class="m-close" onclick="closeMask('upMask')">✕</span></div>
    <div class="m-body">
        <div style="background:#FFF7E8;border:1px dashed #F0A24B;border-radius:6px;padding:6px 10px;font-size:12px;color:#5b3a1e;">
            料號：<b id="upPartNo"></b>　客戶：<b id="upCust"></b><br>
            檔案會存成<b>該料號的料號附件</b>（主檔管理／報價單頁看到的是同一份），存檔後自動出現在正式清單。
        </div>
        <label>選擇檔案 <span class="xd-req">*</span></label>
        <input type="file" id="upFile" style="width:100%;font-size:13px;" data-eg-skip>
        <div class="xd-err" id="errFile"></div>
        <label>外來文件類別 <span class="xd-req">*</span>（至少勾一個，未勾不可存檔）</label>
        <div class="xd-catbox" id="upCats"></div>
        <div class="xd-err" id="errCat"></div>
        <label>文件日期 <span class="xd-req">*</span>（＝清單的發行日期；補歷史文件請填文件上的實際日期）</label>
        <input type="date" id="upDate" style="width:100%;" data-eg-skip>
        <div class="xd-err" id="errDate"></div>
        <label>備註（選填，會寫進附件本體）</label>
        <input type="text" id="upNote" maxlength="500" placeholder="例如：客戶 2023 年提供之原始圖面">
    </div>
    <div class="m-foot">
        <button class="b-ok" id="btnUpSave">上傳並補入清單</button>
        <button class="b-no" onclick="closeMask('upMask')">取消</button>
    </div>
</div></div>

<!-- AS 文件編號綁定 -->
<div class="xd-mask" id="asMask"><div class="xd-modal">
    <div class="m-head">AS 文件編號綁定<span class="m-close" onclick="closeMask('asMask')">✕</span></div>
    <div class="m-body">
        <label>搜尋文件編號 / 名稱</label>
        <input type="text" id="asKw" placeholder="輸入關鍵字過濾" data-eg-skip>
        <label>選擇 AS 文件</label>
        <select id="asSel" size="10" style="height:auto;"></select>
        <div style="font-size:12px;color:#8a6d45;margin-top:6px;">綁定後，列印頁右下角會固定帶出此文件編號。選「（不綁定）」＝清除綁定。</div>
    </div>
    <div class="m-foot">
        <button class="b-ok" onclick="saveAsDoc()">儲存</button>
        <button class="b-no" onclick="closeMask('asMask')">取消</button>
    </div>
</div></div>

<!-- 頁面使用說明 -->
<div class="xd-mask" id="helpUseMask"><div class="xd-modal" style="max-width:760px;">
    <div class="m-head"><span><i class="fa fa-question-circle"></i> 外來文件清單 使用說明</span><span class="m-close" onclick="closeMask('helpUseMask')">✕</span></div>
    <div class="m-body help-doc">
        <h4>一、這頁在做什麼</h4>
        <p>依 AS9100 外來文件管制需求，把「客戶提供的文件」（客戶圖面、客供 3D、客戶規格…）自動彙整成一份清單。
        資料來源＝<b>料號附件</b>與<b>報價單附件</b>中，附件標籤有勾「<b>列入外來文件清單</b>」的那些附件；不必另外登錄，附件上傳掛對標籤就會自動出現。</p>
        <h4>二、操作</h4>
        <ul>
            <li><b>範圍</b>：「有訂單綁定的料號」＝該料號曾被任何訂單綁定過（正式生產過的）；「所有有附件的料號」＝包含只報過價的。</li>
            <li><b>客戶</b>：可在搜尋框輸入客戶 ID 或名稱片段模糊過濾；<b>年度</b>＝附件上傳日期年度。</li>
            <li><b>料號</b>：輸入料號片段即可模糊搜尋（不必打完整料號），不分大小寫、忽略空白；打多個關鍵字用空白分隔＝每個都要命中（例：「rc105 a」找得到 RC105-N03-A）。搜尋結果連動客戶/年度/類別選項、列印與 CSV，待補檔案分頁同樣適用。</li>
            <li><b>類別鈕</b>：點類別標籤只看該類別（顏色與列表標籤一致）。</li>
            <li><b>點料號</b>＝開啟該份文件；<b>備註</b>點鉛筆直接輸入，Enter 儲存、Esc 取消，會回寫到附件本體（主檔/報價頁看到同一筆備註）。</li>
            <li><b>排除</b>：同一份文件在報價單與料號兩邊都上傳過會重複出現，點「排除」把重複那筆移出清單；到「<b>已排除</b>」分頁可隨時「加回」。排除後列印與 CSV 也不會出現。</li>
            <li><b>列印</b>：依客戶分組；大標題＝本公司全名（主檔客戶頁「定為本公司」者）、左下角頁碼、右下角綁定的 AS 文件編號；<b>不帶備註</b>。CSV 有帶備註與檔名。</li>
            <li><b>發行日期</b>：＝附件上傳日期。補登舊文件時上傳日不等於文件實際日期，管理角色可點日期旁鉛筆直接改成文件上的日期（會回寫附件本體，主檔管理看到的上傳日期也跟著變）。</li>
        </ul>
        <h4>三、PFMEA 缺件偵測與待補檔案</h4>
        <ul>
            <li><b>PFMEA 欄</b>：顯示該料號有沒有在 <b>PFMEA（潛在失效模式及效應分析，3-TD-01-02）</b> 建過表；有的顯示「PFMEA已建立」（滑鼠移上去看 PFMEA 文件編號）。工具列的 <b>PFMEA</b> 下拉可只看「已建立／未建立」，篩選結果連動列印與 CSV。</li>
            <li><b>PFMEA 缺件偵測</b>（管理角色）：找出「PFMEA 已建立、但外來文件清單一筆都沒有」的料號 —— 這代表已經在做失效分析、卻沒有把客戶提供的圖面/規格納入外來文件管制。勾選後按「建立待補項目」。</li>
            <li><b>待補檔案分頁</b>：待補項目點「上傳補檔」→ 選檔案、勾外來文件類別、填<b>文件日期</b>，存檔後檔案會存成<b>該料號的料號附件</b>（主檔管理／報價單頁看到的是同一份），並自動出現在正式清單，發行日期＝你填的文件日期。</li>
            <li><b>不是只認這裡上傳的檔案</b>：在主檔管理、型態識別文件管制表、報價單等任何地方替該料號上傳了外來文件類別的附件，下次開啟本頁時該筆待補項目會<b>自動結案並從待補清單消失</b>（不必回來這裡再補一次）。反之若文件之後被刪掉，再按一次「PFMEA 缺件偵測」就能重新建立待補項目。</li>
            <li><b>不列入</b>：該料號本來就沒有外來文件（例如自家開發品）時，按「不列入」，之後偵測就不會再吵；勾「顯示已標記不列入的」可加回。</li>
            <li>待補項目<b>不會</b>出現在正式清單、列印與 CSV（沒有檔案的空列不進管制清單）。</li>
        </ul>
        <h4>四、同一份文件只留最新版（自動）</h4>
        <ul>
            <li><b>怎麼分組</b>：以「<b>同一料號＋同一外來文件類別</b>」為一組（例如某料號的「原圖」）。同組內<b>發行日期最新的那一份是現行版</b>，較舊的自動變成舊版。</li>
            <li><b>舊版會怎樣</b>：清單、列印、CSV 預設<b>都不會出現</b>；勾工具列的「顯示歷史版本」才會列出來（灰底、標「舊版（已由 ○○ 版取代）」），檔案本身不會被刪，隨時點得開。</li>
            <li><b>同一天上傳的多份都算現行版</b>：一份圖掃成兩三個檔一起上傳時不會被吃掉。</li>
            <li><b>要換新版怎麼做</b>：直接把新版上傳成該料號同一個類別的附件即可，清單會自動改用新的、舊的自動退場，<b>不必手動去排除舊檔</b>。</li>
            <li><b>發行日期就是版本先後</b>：補歷史文件時記得用鉛筆把發行日期改成正確的文件日期，否則會以上傳日判斷新舊。</li>
            <li><b>判錯了可以人工覆寫</b>（需管理角色，按鈕在該列「操作」欄，只有同組真的有多份時才出現）：
                <ul>
                    <li><b>釘選為現行版</b>：不看發行日期，指定某一份為現行版。</li>
                    <li><b>兩份都要</b>：同一個標籤下本來就是不同文件（例如同一料號的兩張不同加工圖）時，這一組改成不做版本判定、全部保留。</li>
                    <li><b>取消釘選／恢復自動判定</b>：回到「發行日期最新者為現行版」。</li>
                </ul>
            </li>
            <li>已「排除」的項目不參與版本判定，也不會把別的版本擠成舊版。</li>
        </ul>
        <h4>五、設定入口</h4>
        <ul>
            <li><b>哪些標籤算外來文件</b>：報價單頁「附件類別」分頁，或主檔管理「附件類別標籤設定」——勾「列入外來文件清單」，可另設清單顯示用的類別名稱（兩邊同一組設定）。</li>
            <li><b>AS 文件編號</b>：本頁「AS文件編號綁定」按鈕（需管理角色），從 AS9100 文件管理主檔挑選。</li>
            <li><b>發行單位</b>：同 Sales_Track 的業務單位設定（BOM 總覽頁修改）。</li>
        </ul>
        <h4>六、權限角色</h4>
        <ul>
            <li><b>外來文件檢閱</b>：看清單、開文件、匯出、列印。</li>
            <li><b>外來文件管理</b>：檢閱＋綁 AS 編號、編輯備註、修改發行日期、排除/加回、版本釘選與「兩份都要」、PFMEA 缺件偵測與上傳補檔。</li>
            <li>管理者固定全權；未指派角色者無法檢視本頁。指派入口：使用者權限設定 →「外來文件清單」區塊。</li>
        </ul>
        <div class="tip">發行日期＝附件上傳日期（可用鉛筆修改）。同料號同類別只列最新版，舊版要看請勾「顯示歷史版本」。若清單是空的，通常是還沒有任何標籤勾「列入外來文件清單」。</div>
    </div>
    <div class="m-foot"><button class="b-ok" onclick="closeMask('helpUseMask')">我知道了</button></div>
</div></div>

<!-- 角色說明 -->
<div class="xd-mask" id="helpMask"><div class="xd-modal">
    <div class="m-head">角色權限說明<span class="m-close" onclick="closeMask('helpMask')">✕</span></div>
    <div class="m-body" style="line-height:1.8;">
        <b>外來文件檢閱</b>：檢視清單（含點料號開啟文件）、匯出 CSV、列印。<br>
        <b>外來文件管理</b>：檢閱＋綁定 AS 文件編號、編輯附件備註、修改發行日期、排除/加回清單項目、版本人工覆寫（釘選現行版／兩份都要）、PFMEA 缺件偵測與待補檔案上傳。<br>
        <b>管理者</b>：系統管理者固定擁有全部權限。<br>
        <hr style="border-color:#EADFC8;">
        清單來源＝附件標籤有勾「列入外來文件清單」的料號附件與報價附件；
        「有訂單綁定的料號」＝該料號曾被任何訂單綁定（不受年度篩選影響）。
    </div>
</div></div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/fastclick.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script src="../../resource/js/eg_input_rules.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_input_rules.js') ?>"></script>
<script src="../../resource/js/eg_date_fmt.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_date_fmt.js') ?>"></script>
<script>
$(document).ready(function(){
    var $am = $('#sidebar-menu .nav.side-menu > li.active');
    if ($am.length) { $am.removeClass('active').find('ul.child_menu').hide(); $am.find('li.current-page').removeClass('current-page'); }
    $('#sidebar-menu').css('visibility','visible');
});

var API = '../../src/store/ExternalDoc_API.php';
var canView = <?= $canView ? 'true' : 'false' ?>;
var canManage = <?= $canManage ? 'true' : 'false' ?>;
var MODE = 'bound', VIEW = 'active', PAGE = 1, TOTAL = 0, AS_DOC = null, AS_DOCS = [], ISSUE_UNIT = '';
var CAT = 0, CATS = [], CAT_COLOR = {}, COMPANY = '', CUSTOMERS = [], EXT_CATS = [];

// 類別固定調色盤（暖色系，依 ai-rules/10；同類別同色，列表/篩選鈕/列印一致）
var CAT_PALETTE = [
    ['#F7E0BD','#6b4a1c'], ['#F0A24B','#ffffff'], ['#E07856','#ffffff'], ['#C77C1A','#ffffff'],
    ['#F5C6A5','#7a4a1e'], ['#B85C38','#ffffff'], ['#9C6B3F','#ffffff'], ['#EAD3A2','#6b4a1c']
];
function catColor(cid){ return CAT_COLOR[cid] || CAT_PALETTE[0]; }
function catPill(cid, name){
    var c = catColor(cid);
    return '<span class="cat-pill" style="background:'+c[0]+';color:'+c[1]+';">'+esc(name)+'</span>';
}

function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){
    return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
function closeMask(id){ document.getElementById(id).style.display='none'; }
function openMask(id){ document.getElementById(id).style.display='block'; }
// 日期顯示一律 YYYY.MM.DD（ai-rules/20，唯一實作 eg_date_fmt.js 的 egFmtDate）
function dispDate(d, withTime){ return (typeof egFmtDate === 'function') ? egFmtDate(d, !!withTime) : (d||''); }

function filters(){
    return { mode: MODE, customer_id: $('#custSel').val()||'', year: $('#yearSel').val()||0,
             category: CAT, pfmea: $('#pfmeaSel').val()||'',
             part_kw: $.trim($('#partKw').val()||''),
             show_history: $('#chkHistory').prop('checked') ? 1 : 0 };
}
function isPending(){ return VIEW === 'pending' || VIEW === 'ignored'; }

// PFMEA（views/TD/pfmea.php）建立狀態標示
function pfmeaCell(r){
    return r.has_pfmea
        ? '<span class="pf-pill pf-yes" title="PFMEA 文件編號：'+esc(r.pfmea_doc_no||'')+'">PFMEA已建立</span>'
        : '<span class="pf-pill pf-no">未建立</span>';
}

function renderHead(){
    var h;
    if (isPending()){
        h = '<th>客戶</th><th>料號</th><th>PFMEA</th><th>缺件原因</th><th>建立時間</th><th>建立者</th>';
        if (canManage) h += '<th>操作</th>';
    } else {
        h = '<th>客戶</th><th>料號</th><th>外來文件類別</th><th>發行日期</th>';
        if (VIEW === 'active') h += '<th style="width:130px;">版本</th>';
        h += '<th>發行單位</th><th>PFMEA</th><th>來源</th><th>備註</th>';
        if (VIEW === 'excluded') h += '<th>排除資訊</th>';
        if (canManage) h += '<th>操作</th>';
    }
    $('#xdHead').html(h);
}
function colCount(){
    if (isPending()) return 6 + (canManage?1:0);
    return 8 + (VIEW==='active'?1:0) + (VIEW==='excluded'?1:0) + (canManage?1:0);
}

// ── 版本狀態（同料號＋同類別只留最新版；ver_* 欄位由後端 extdoc_mark_versions 算好）──
function verCell(r){
    if (r.ver_state === 'old')
        return '<span class="ver-pill ver-old" title="這一份已被較新的版本取代，正式清單/列印/CSV 預設不列">舊版</span>'
             + (r.ver_superseded ? '<div style="font-size:10px;color:#a08a6a;">已由 '+esc(dispDate(r.ver_superseded))+' 版取代</div>' : '');
    if (r.ver_keep_all)
        return '<span class="ver-pill ver-keep" title="這一組已設定為不做版本判定，同標籤下的每一份都是有效文件">並存</span>';
    if (r.ver_pinned)
        return '<span class="ver-pill ver-pin" title="已人工釘選為現行版，不受發行日期影響">現行版（釘選）</span>';
    if ((r.ver_total||1) > 1)
        return '<span class="ver-pill ver-cur" title="同料號同類別共 '+(r.ver_total||1)+' 份，這是發行日期最新的">現行版</span>';
    return '<span style="color:#c9bda9;">—</span>';
}
// 版本操作（管理權限；只有真的有多份時才出現，避免每列都掛一堆用不到的按鈕）
function verOps(r){
    if (!canManage || VIEW !== 'active' || (r.ver_total||1) <= 1) return '';
    var h = '';
    if (r.ver_state === 'old')
        h += ' <span class="xd-op xd-pin-btn" title="不管發行日期，指定這一份為現行版"><i class="fa fa-thumb-tack"></i> 釘選為現行版</span>';
    else if (r.ver_pinned)
        h += ' <span class="xd-op xd-auto-btn" title="取消釘選，回到「發行日期最新者為現行版」"><i class="fa fa-magic"></i> 取消釘選</span>';
    if (r.ver_keep_all)
        h += ' <span class="xd-op xd-auto-btn" title="回到自動版本判定"><i class="fa fa-magic"></i> 恢復自動判定</span>';
    else
        h += ' <span class="xd-op xd-keep-btn" title="這幾份不是改版，是不同的文件：全部保留在清單上"><i class="fa fa-clone"></i> 兩份都要</span>';
    return h;
}

function renderCustOptions(kw){
    kw = (kw||'').toLowerCase();
    var cur = $('#custSel').val();
    var $s = $('#custSel').empty().append('<option value="">全部客戶</option>');
    var hits = [];
    CUSTOMERS.forEach(function(c){
        if (kw && (c.customer_id+' '+c.customer).toLowerCase().indexOf(kw) === -1) return;
        hits.push(c.customer_id);
        $s.append('<option value="'+esc(c.customer_id)+'">'+esc(c.customer_id)+'　'+esc(c.customer)+'</option>');
    });
    // 原選擇仍在候選中就保留；模糊搜尋剛好剩一家＝直接選定
    if (cur && hits.indexOf(cur) !== -1) $s.val(cur);
    else if (kw && hits.length === 1) $s.val(hits[0]);
    else $s.val('');
}

function renderCatBar(){
    var $bar = $('#catFilterBar');
    if (!CATS.length){ $bar.hide(); return; }
    $bar.find('.cat-btn').remove();
    var mk = function(id, name){
        var c = id ? catColor(id) : ['#fff','#5b3a1e'];
        return '<button type="button" class="cat-btn'+(CAT===id?' active':'')+'" data-cid="'+id+'"'
             + ' style="background:'+c[0]+';color:'+c[1]+';">'+esc(name)+'</button>';
    };
    var h = mk(0, '全部');
    CATS.forEach(function(c){ h += mk(c.id, c.name); });
    $bar.append(h).css('display','flex');
}
$(document).on('click', '#catFilterBar .cat-btn', function(){
    CAT = parseInt($(this).data('cid'))||0;
    renderCatBar(); refreshAll();
});

// 選項（客戶/年度/類別鈕）跟著目前篩選連動：沒外來文件的客戶不會出現在下拉裡
function loadOptions(first){
    var f = filters();
    f.action = 'get_options';
    $.post(API, f, function(res){
        if (!res.success) return;
        CUSTOMERS = res.customers||[];
        var beforeCust = $('#custSel').val()||'';
        renderCustOptions($('#custKw').val()||'');
        // 年度：保留原選擇；該年度在目前篩選下已無資料則退回全部
        var beforeYear = $('#yearSel').val()||'';
        $('#yearSel').find('option:not(:first)').remove();
        (res.years||[]).forEach(function(y){
            $('#yearSel').append('<option value="'+y+'">'+y+' 年</option>');
        });
        $('#yearSel').val(($('#yearSel').find('option[value="'+beforeYear+'"]').length) ? beforeYear : '');
        // 配色以「全部外來文件標籤」為基準（跨篩選穩定同色）；按鈕只列目前篩選下實際出現的
        CAT_COLOR = {};
        (res.all_categories||[]).forEach(function(c, i){ CAT_COLOR[c.id] = CAT_PALETTE[i % CAT_PALETTE.length]; });
        CATS = res.categories||[];
        EXT_CATS = res.ext_cats||[];      // 補檔上傳可勾的類別（＝清單認列的外來文件標籤）
        setCounts(res);
        if (CAT && !CATS.some(function(c){ return c.id === CAT; })) CAT = 0;
        renderCatBar();
        if (isPending()) $('#catFilterBar').hide();
        COMPANY = res.company_name||'';
        AS_DOCS = res.as_docs||[];
        renderAsDoc(res.as_doc);
        ISSUE_UNIT = res.issue_unit||'';
        $('#issueUnit').text(ISSUE_UNIT || '（未設定業務單位）');
        if (first) loadList();
        // 連動後原選擇被移除（客戶/年度已無資料）→ 以新值重載一次列表
        else if (($('#custSel').val()||'') !== beforeCust || ($('#yearSel').val()||'') !== beforeYear) loadList();
    }, 'json');
}
// 任一篩選變更：重載列表＋重算其他維度的選項
function refreshAll(){ PAGE = 1; loadList(); loadOptions(false); }
var custKwT = null;
$('#custKw').on('input', function(){
    var kw = $(this).val();
    clearTimeout(custKwT);
    custKwT = setTimeout(function(){
        var before = $('#custSel').val();
        renderCustOptions(kw);
        if ($('#custSel').val() !== before) refreshAll();
    }, 250);
});

var partKwT = null;
$('#partKw').on('input', function(){
    clearTimeout(partKwT);
    partKwT = setTimeout(refreshAll, 250);   // 料號模糊搜尋：連動列表/客戶年度類別選項/列印/CSV
});

function renderAsDoc(doc){
    AS_DOC = doc || null;
    $('#asDocNo').text(AS_DOC ? (AS_DOC.doc_no + '（' + AS_DOC.doc_name + '）') : '尚未綁定');
}

function loadList(){
    var f = filters();
    f.action = 'get_list';
    f.show = VIEW;
    f.page = PAGE;
    f.per_page = parseInt($('#perPageSel').val())||10;
    renderHead();
    $('#xdBody').html('<tr><td colspan="'+colCount()+'" style="padding:20px;color:#8a6d45;">載入中…</td></tr>');
    $.post(API, f, function(res){
        if (!res.success){ $('#xdBody').html('<tr><td colspan="'+colCount()+'" style="padding:20px;color:#DD5138;">'+esc(res.message||'載入失敗')+'</td></tr>'); return; }
        TOTAL = res.total;
        ISSUE_UNIT = res.issue_unit||ISSUE_UNIT;
        $('#issueUnit').text(ISSUE_UNIT || '（未設定業務單位）');
        renderAsDoc(res.as_doc);
        setCounts(res);
        var h = '';
        if (isPending()){
            (res.rows||[]).forEach(function(r){
                h += '<tr data-pid="'+r.pending_id+'" data-dpk="'+r.ds_pk+'" data-pno="'+esc(r.part_no)+'" data-cust="'+esc(r.customer_name)+'">'
                   + '<td class="t-left">'+esc(r.customer_name)+'</td>'
                   + '<td class="t-left">'+esc(r.part_no)+(r.part_missing?' <span style="color:#DD5138;font-size:11px;">(料號已刪除)</span>':'')+'</td>'
                   + '<td>'+pfmeaCell(r)+'</td>'
                   + '<td style="font-size:12px;color:#8a6d45;">PFMEA 已建立，但外來文件清單一筆都沒有</td>'
                   + '<td style="font-size:12px;">'+esc(dispDate(r.created_at, true))+'</td>'
                   + '<td style="font-size:12px;">'+esc(r.created_by||'')+'</td>';
                if (canManage) h += '<td style="white-space:nowrap;">'
                   + (VIEW==='pending'
                        ? '<span class="xd-op xd-up-btn"><i class="fa fa-upload"></i> 上傳補檔</span>'
                        + ' <span class="xd-op xd-ign-btn" title="這個料號本來就沒有外來文件，不要再列入待補"><i class="fa fa-ban"></i> 不列入</span>'
                        : '<span class="xd-op xd-unign-btn"><i class="fa fa-undo"></i> 加回待補</span>')
                   + '</td>';
                h += '</tr>';
            });
            var pMsg = VIEW==='pending'
                ? '目前沒有待補項目（按工具列「PFMEA 缺件偵測」找出 PFMEA 已建立但沒有外來文件的料號）'
                : '沒有標記「不列入」的項目';
            $('#xdBody').html(h || '<tr><td colspan="'+colCount()+'" style="padding:20px;color:#8a6d45;">'+pMsg+'</td></tr>');
            renderPager();
            return;
        }
        (res.rows||[]).forEach(function(r){
            var cats = (r.categories||[]).map(function(c, i){ return catPill((r.category_ids||[])[i], c); }).join('');
            var src = r.source==='part' ? '<span class="src-pill src-part">料號附件</span>'
                    : '<span class="src-pill src-quote" title="報價單 '+esc(r.quote_no)+'">報價附件</span>';
            var partCell = r.file_url
                ? '<a class="xd-doclink" href="'+esc(r.file_url)+'" target="_blank" title="開啟文件：'+esc(r.doc_name)+'">'+esc(r.part_no)+'</a>'
                : '<span title="'+esc(r.doc_name)+'">'+esc(r.part_no)+'</span>';
            var noteCell = esc(r.note||'');
            if (canManage) noteCell += ' <i class="fa fa-pencil xd-note-pen" style="cursor:pointer;color:#b5762a;" title="編輯備註（回寫到附件本體）"></i>';
            var dateCell = esc(dispDate(r.doc_date));
            if (canManage) dateCell += ' <i class="fa fa-pencil xd-date-pen" style="cursor:pointer;color:#b5762a;" title="修改發行日期（回寫附件的上傳日期）"></i>';
            h += '<tr class="'+(r.ver_state==='old'?'xd-oldver':'')+'" data-src="'+r.source+'" data-aid="'+r.attach_id+'" data-dpk="'+r.ds_pk+'" data-pno="'+esc(r.part_no)+'">'
               + '<td class="t-left">'+esc(r.customer_name)+'</td>'
               + '<td class="t-left">'+partCell+'</td>'
               + '<td>'+(cats||'<span style="color:#c9bda9;">—</span>')+'</td>'
               + '<td class="xd-date" data-date="'+esc(r.doc_date)+'" style="white-space:nowrap;">'+dateCell+'</td>'
               + (VIEW==='active' ? '<td>'+verCell(r)+'</td>' : '')
               + '<td>'+esc(ISSUE_UNIT)+'</td>'
               + '<td>'+pfmeaCell(r)+'</td>'
               + '<td>'+src+'</td>'
               + '<td class="t-left xd-note" data-note="'+esc(r.note||'')+'" style="min-width:120px;">'+noteCell+'</td>';
            if (VIEW === 'excluded')
                h += '<td style="font-size:11px;color:#8a6d45;">'+esc(r.excluded_by||'')+'<br>'+esc(r.excluded_at||'')+'</td>';
            if (canManage)
                h += '<td style="white-space:nowrap;">'+(VIEW==='excluded'
                        ? '<span class="xd-op xd-re-btn"><i class="fa fa-undo"></i> 加回</span>'
                        : '<span class="xd-op xd-ex-btn"><i class="fa fa-ban"></i> 排除</span>' + verOps(r))+'</td>';
            h += '</tr>';
        });
        var emptyMsg = VIEW==='excluded' ? '沒有被排除的項目'
                     : '無符合條件的外來文件（先到附件類別標籤設定勾選「列入外來文件清單」）';
        $('#xdBody').html(h || '<tr><td colspan="'+colCount()+'" style="padding:20px;color:#8a6d45;">'+emptyMsg+'</td></tr>');
        renderPager();
    }, 'json');
}

// ── 分頁籤：清單 / 已排除 / 待補檔案（列印與 CSV 只針對正式清單）──────────
function switchTab(view, $btn){
    VIEW = view; PAGE = 1;
    $('.xd-tabs .xd-tab').removeClass('active'); $btn.addClass('active');
    var official = (view === 'active');
    $('#btnPrint,#btnCsv').prop('disabled', !official).css('opacity', official ? 1 : .45);
    // 待補分頁沒有附件，年度/類別/PFMEA 這些附件維度的篩選不適用
    $('#pendBar').toggle(isPending());
    $('#histWrap').toggle(view === 'active');   // 版本判定只作用在正式清單
    $('#yearSel,#pfmeaSel').prop('disabled', isPending()).css('opacity', isPending() ? .5 : 1);
    if (isPending() || !CATS.length) $('#catFilterBar').hide();
    else $('#catFilterBar').css('display', 'flex');
    loadList();
}
$('#tabActive').on('click',   function(){ switchTab('active', $(this)); });
$('#tabExcluded').on('click', function(){ switchTab('excluded', $(this)); });
$('#tabPending').on('click',  function(){ switchTab($('#chkIgnored').prop('checked') ? 'ignored' : 'pending', $(this)); });
$('#chkIgnored').on('change', function(){
    VIEW = $(this).prop('checked') ? 'ignored' : 'pending'; PAGE = 1; loadList();
});
function setCounts(res){
    if (res.pending_count !== undefined)
        $('#pendCnt').text(res.pending_count).toggleClass('zero', !res.pending_count);
    if (res.ignored_count !== undefined) $('#ignCnt').text(res.ignored_count);
}

// ── 排除 / 加回 ─────────────────────────────────────────────
$(document).on('click', '.xd-ex-btn', function(){
    var tr = $(this).closest('tr');
    if (!confirm('將「'+tr.data('pno')+'」這筆附件自外來文件清單排除？（可在「已排除」分頁加回）')) return;
    $.post(API, {action:'exclude_item', source:tr.data('src'), attach_id:tr.data('aid'),
                 ds_pk:tr.data('dpk'), part_no:tr.data('pno')}, function(res){
        if (!res.success){ alert(res.message||'排除失敗'); return; }
        loadList();
    }, 'json');
});
$(document).on('click', '.xd-re-btn', function(){
    var tr = $(this).closest('tr');
    $.post(API, {action:'restore_item', source:tr.data('src'), attach_id:tr.data('aid'),
                 ds_pk:tr.data('dpk')}, function(res){
        if (!res.success){ alert(res.message||'加回失敗'); return; }
        loadList();
    }, 'json');
});

// ── 版本判定的人工覆寫（釘選現行版／兩份都要／恢復自動判定）──────────────
function verAction(tr, action, msg){
    $.post(API, {action:action, source:tr.data('src'), attach_id:tr.data('aid'),
                 ds_pk:tr.data('dpk')}, function(res){
        if (!res.success){ alert(res.message||'設定失敗'); return; }
        loadList(); loadOptions(false);
    }, 'json');
}
$(document).on('click', '.xd-pin-btn', function(){
    var tr = $(this).closest('tr');
    if (!confirm('把「'+tr.data('pno')+'」的這一份指定為現行版？\n（不再看發行日期，同料號同類別的其他份會被視為舊版）')) return;
    verAction(tr, 'version_pin');
});
$(document).on('click', '.xd-keep-btn', function(){
    var tr = $(this).closest('tr');
    if (!confirm('「'+tr.data('pno')+'」同料號同類別的這幾份不是改版關係，全部保留在清單上？')) return;
    verAction(tr, 'version_keep_all');
});
$(document).on('click', '.xd-auto-btn', function(){
    var tr = $(this).closest('tr');
    verAction(tr, 'version_auto');
});

// ── 備註即時編輯（回寫附件本體：part_attachments.note / quotation_attachments.note）──
$(document).on('click', '.xd-note-pen', function(){
    var td = $(this).closest('td');
    if (td.find('input').length) return;
    var cur = td.attr('data-note') || '';
    td.html('<input type="text" class="xd-note-edit" data-eg-skip maxlength="500" value="'+esc(cur)+'" placeholder="輸入備註後 Enter 儲存，Esc 取消">');
    var inp = td.find('input');
    inp.focus();
    inp.on('keydown', function(ev){
        if (ev.key === 'Enter'){ ev.preventDefault(); saveNote(td, inp.val()); }
        else if (ev.key === 'Escape'){ td.data('saving', 1); loadList(); }
    });
    inp.on('blur', function(){ saveNote(td, inp.val()); });
});
function saveNote(td, val){
    if (td.data('saving')) return;
    td.data('saving', 1);
    var tr = td.closest('tr');
    $.post(API, {action:'save_note', source:tr.data('src'), attach_id:tr.data('aid'), note:val}, function(res){
        if (!res.success) alert(res.message||'備註儲存失敗');
        loadList();
    }, 'json').fail(function(){ alert('備註儲存失敗'); loadList(); });
}

// ── 發行日期修改（回寫附件的上傳日期；補歷史文件時要能填文件實際日期）────
$(document).on('click', '.xd-date-pen', function(){
    var td = $(this).closest('td');
    if (td.find('input').length) return;
    var cur = td.attr('data-date') || '';
    td.html('<input type="date" class="xd-note-edit" data-eg-skip style="width:140px;" value="'+esc(cur)+'">'
          + ' <span class="xd-op xd-date-ok" title="儲存"><i class="fa fa-check"></i></span>'
          + ' <span class="xd-op xd-date-no" title="取消"><i class="fa fa-times"></i></span>');
    td.find('input').focus();
});
$(document).on('click', '.xd-date-no', function(){ loadList(); });
$(document).on('keydown', '.xd-date .xd-note-edit', function(ev){
    if (ev.key === 'Enter'){ ev.preventDefault(); $(this).closest('td').find('.xd-date-ok').click(); }
    else if (ev.key === 'Escape'){ loadList(); }
});
$(document).on('click', '.xd-date-ok', function(){
    var td = $(this).closest('td'), tr = td.closest('tr');
    var val = td.find('input').val() || '';
    if (!val){ alert('請選擇日期'); return; }
    if (td.data('saving')) return;
    td.data('saving', 1);
    $.post(API, {action:'save_doc_date', source:tr.data('src'), attach_id:tr.data('aid'), doc_date:val}, function(res){
        if (!res.success) alert(res.message||'日期儲存失敗');
        refreshAll();
    }, 'json').fail(function(){ alert('日期儲存失敗'); loadList(); });
});

// ── 待補項目：不列入 / 加回 ─────────────────────────────────────
$(document).on('click', '.xd-ign-btn', function(){
    var tr = $(this).closest('tr');
    if (!confirm('「'+tr.data('pno')+'」不列入待補清單？（可勾「顯示已標記不列入的」加回）')) return;
    $.post(API, {action:'pending_ignore', pending_id:tr.data('pid')}, function(res){
        if (!res.success){ alert(res.message||'操作失敗'); return; }
        loadList();
    }, 'json');
});
$(document).on('click', '.xd-unign-btn', function(){
    var tr = $(this).closest('tr');
    $.post(API, {action:'pending_restore', pending_id:tr.data('pid')}, function(res){
        if (!res.success){ alert(res.message||'操作失敗'); return; }
        loadList();
    }, 'json');
});

// ── 上傳補檔（存成料號附件，上傳日期＝輸入的文件日期）──────────────────
// 註：file input 用原生可見控制項、類別勾選與存檔鈕常駐顯示、送出時直讀 input.files
//     （使用者環境的 change 事件可能被擴充功能吞掉，見記憶 file_upload_change_event）
var UP_PID = 0, UP_DPK = 0;
function renderUpCats(){
    var h = '';
    (EXT_CATS||[]).forEach(function(c){
        h += '<label><input type="checkbox" class="up-cat" data-eg-skip value="'+c.id+'"> '+esc(c.name)+'</label>';
    });
    $('#upCats').html(h || '<span style="color:#DD5138;">尚無「列入外來文件清單」的附件類別標籤，請先到附件類別標籤設定勾選。</span>');
}
$(document).on('click', '.xd-up-btn', function(){
    var tr = $(this).closest('tr');
    UP_PID = parseInt(tr.data('pid'))||0; UP_DPK = parseInt(tr.data('dpk'))||0;
    $('#upPartNo').text(tr.data('pno')); $('#upCust').text(tr.data('cust'));
    $('#upFile').val(''); $('#upNote').val(''); $('#upDate').val('');
    $('.xd-err').text('');
    renderUpCats();
    openMask('upMask');
});
// 錯誤即時偵測（表單三總則③）：填/選當下就把紅字消掉
$(document).on('change', '#upFile', function(){ $('#errFile').text(''); });
$(document).on('change', '#upCats .up-cat', function(){ $('#errCat').text(''); });
$('#upDate').on('change input', function(){ $('#errDate').text(''); });
$('#btnUpSave').on('click', function(){
    var fileEl = document.getElementById('upFile');
    var files  = fileEl ? fileEl.files : null;      // 直讀現值，不靠 change 事件同步
    var cats = [];
    $('#upCats .up-cat:checked').each(function(){ cats.push($(this).val()); });
    var d = $('#upDate').val() || '';
    var ok = true;
    $('.xd-err').text('');
    if (!files || !files.length){ $('#errFile').text('請選擇要上傳的檔案'); ok = false; }
    if (!cats.length){ $('#errCat').text('請至少勾選一個外來文件類別（沒有類別的附件不會出現在清單）'); ok = false; }
    if (!d){ $('#errDate').text('請輸入文件日期（清單的發行日期會用這個日期）'); ok = false; }
    else if (d > new Date().toISOString().substr(0,10)){ $('#errDate').text('文件日期不可晚於今天'); ok = false; }
    if (!ok) return;
    var fd = new FormData();
    fd.append('action', 'upload_fill');
    fd.append('pending_id', UP_PID);
    fd.append('ds_pk', UP_DPK);
    fd.append('category_ids', cats.join(','));
    fd.append('doc_date', d);
    fd.append('note', $('#upNote').val()||'');
    fd.append('file', files[0]);
    var $b = $('#btnUpSave').text('上傳中…');
    $.ajax({ url: API, type:'POST', data: fd, processData:false, contentType:false, dataType:'json' })
     .done(function(res){
        $b.text('上傳並補入清單');
        if (!res.success){ alert(res.message||'上傳失敗'); return; }
        closeMask('upMask');
        alert(res.message||'已上傳');
        refreshAll();
     })
     .fail(function(){ $b.text('上傳並補入清單'); alert('上傳失敗（伺服器沒有回應或檔案過大）'); });
});

// ── PFMEA 缺件偵測 ───────────────────────────────────────────
var SCAN_ROWS = [];
$('#btnPfmeaScan').on('click', function(){ openMask('scanMask'); runScan(); });
$('#btnScanReload').on('click', runScan);
$(document).on('change', '.scan-src', runScan);
function runScan(){
    var srcs = $('.scan-src:checked').map(function(){ return this.value; }).get();
    if (!srcs.length){
        $('#scanBody').html('<tr><td colspan="5" style="padding:14px;color:#8a6d45;">請至少勾選一個偵測來源</td></tr>');
        $('#scanSum').text(''); $('#scanUnres').text('');
        return;
    }
    // 點開即刷新：一律向後端重新偵測，不用畫面上的快取
    $('#scanBody').html('<tr><td colspan="5" style="padding:14px;color:#8a6d45;">偵測中…</td></tr>');
    $('#scanSum').text(''); $('#scanUnres').text(''); $('#scanAll').prop('checked', false);
    $.post(API, {action:'pfmea_scan', sources: srcs.join(',')}, function(res){
        if (!res.success){ $('#scanBody').html('<tr><td colspan="5" style="padding:14px;color:#DD5138;">'+esc(res.message||'偵測失敗')+'</td></tr>'); return; }
        SCAN_ROWS = res.rows||[];
        var newCnt = 0, h = '';
        SCAN_ROWS.forEach(function(r, i){
            var stat = r.already ? '<span style="color:#C77C1A;">已在待補清單</span>'
                     : (r.ignored ? '<span style="color:#8a6d45;">已標記不列入</span>'
                     : (r.done ? '<span style="color:#8a6d45;" title="之前補過檔案，但目前清單上又找不到文件（可能已刪除）">曾補檔（文件已不在清單）</span>'
                     : '<span style="color:#DD5138;">缺件</span>'));
            var selectable = !r.already;
            if (selectable) newCnt++;
            h += '<tr><td>'+(selectable
                    ? '<input type="checkbox" class="scan-ck" data-eg-skip data-dpk="'+r.ds_pk+'" checked>'
                    : '')+'</td>'
               + '<td class="tl">'+esc(r.customer_name)+'</td>'
               + '<td class="tl">'+esc(r.part_no)+'</td>'
               + '<td>'+esc(r.src_label || r.pfmea_doc_no || '')
               + (r.pfmea_doc_no && r.src_label ? '<br><span style="font-size:11px;color:#8a6d45;">'+esc(r.pfmea_doc_no)+'</span>' : '')
               + '</td>'
               + '<td>'+stat+'</td></tr>';
        });
        $('#scanBody').html(h || '<tr><td colspan="5" style="padding:14px;color:#8a6d45;">沒有缺件：所選來源的料號都已經有外來文件了</td></tr>');
        $('#scanSum').html('偵測到 <b>'+SCAN_ROWS.length+'</b> 個應該要有外來文件卻一筆都沒有的料號，其中 <b>'+newCnt+'</b> 個可建立待補項目。');
        $('#scanAll').prop('checked', newCnt > 0);
        if ((res.unresolved||[]).length){
            $('#scanUnres').html('註：另有 '+res.unresolved.length+' 筆 PFMEA 是手動輸入的料號、在料號主檔找不到（'
                + res.unresolved.map(function(u){ return esc(u.part_no); }).join('、')
                + '），無法自動建立，請先於主檔建立該料號。');
        }
    }, 'json');
}
$('#scanAll').on('change', function(){ $('#scanBody .scan-ck').prop('checked', $(this).prop('checked')); });
$('#btnScanCreate').on('click', function(){
    var ids = [];
    $('#scanBody .scan-ck:checked').each(function(){ ids.push($(this).data('dpk')); });
    if (!ids.length){ alert('請至少勾選一筆料號'); return; }
    $.post(API, {action:'pending_create', ds_pks: ids}, function(res){
        if (!res.success){ alert(res.message||'建立失敗'); return; }
        alert(res.message);
        closeMask('scanMask');
        $('#tabPending').click();
    }, 'json');
});

function renderPager(){
    var per = parseInt($('#perPageSel').val())||10;
    var pages = Math.max(1, Math.ceil(TOTAL/per));
    if (PAGE > pages) PAGE = pages;
    $('#totalInfo').text('共 '+TOTAL+' 筆');
    var h = '<button '+(PAGE<=1?'disabled':'')+' onclick="goPage('+(PAGE-1)+')">‹</button>';
    var s = Math.max(1, PAGE-2), e = Math.min(pages, s+4); s = Math.max(1, e-4);
    for (var p=s; p<=e; p++) h += '<button class="'+(p===PAGE?'cur':'')+'" onclick="goPage('+p+')">'+p+'</button>';
    h += '<button '+(PAGE>=pages?'disabled':'')+' onclick="goPage('+(PAGE+1)+')">›</button>';
    $('#pageBtns').html(h);
}
function goPage(p){ PAGE = Math.max(1, p); loadList(); }

$('#modeBound').on('click', function(){ MODE='bound'; $(this).addClass('active'); $('#modeAll').removeClass('active'); refreshAll(); });
$('#modeAll').on('click', function(){ MODE='all'; $(this).addClass('active'); $('#modeBound').removeClass('active'); refreshAll(); });
$('#custSel, #yearSel, #pfmeaSel').on('change', function(){ refreshAll(); });
$('#chkHistory').on('change', function(){ refreshAll(); });
$('#perPageSel').on('change', function(){ PAGE=1; loadList(); });

$('#btnCsv').on('click', function(){
    var f = filters();
    location.href = API + '?action=export_csv&mode='+f.mode+'&customer_id='+encodeURIComponent(f.customer_id)
                  + '&year='+f.year+'&category='+f.category+'&pfmea='+encodeURIComponent(f.pfmea)
                  + '&part_kw='+encodeURIComponent(f.part_kw)
                  + '&show_history='+f.show_history;
});

// ── 列印：依客戶分組；右下角固定頁尾＝AS 文件編號 ─────────────────────
$('#btnPrint').on('click', function(){
    var f = filters();
    f.action = 'get_print';
    $.post(API, f, function(res){
        if (!res.success){ alert(res.message||'載入失敗'); return; }
        var unit = res.issue_unit||'';
        var company = res.company_name || COMPANY || '';
        // 列印表頭＝已綁定 AS 文件的表單名稱 doc_name（ai-rules/16：禁寫死；沒綁定才退回頁面名稱）
        var title = (res.as_doc && res.as_doc.doc_name) ? res.as_doc.doc_name : '外來文件清單';
        // 列印不再印篩選條件副標（年度/客戶/筆數/列印日期）——使用者明確要求拿掉
        var body = '<div class="p-comp">'+esc(company)+'</div>'
                 + '<div class="p-title">'+esc(title)+'</div>';
        (res.groups||[]).forEach(function(g){
            body += '<div class="p-cust">客戶：'+esc(g.customer_name)+'</div>';
            body += '<table class="p-tb"><thead><tr><th style="width:30%;">料號</th>'
                  + '<th>外來文件類別</th><th style="width:16%;">發行日期</th><th style="width:16%;">發行單位</th></tr></thead><tbody>';
            g.rows.forEach(function(r){
                var cats = (r.categories||[]).map(function(c, i){ return catPill((r.category_ids||[])[i], c); }).join('');
                // 舊版只有在勾了「顯示歷史版本」時才會出現在資料裡；印出來一定要標明，避免被當成現行文件
                var dateTxt = esc(dispDate(r.doc_date))
                            + (r.ver_state === 'old' ? '<div style="font-size:9px;">（舊版）</div>' : '');
                body += '<tr'+(r.ver_state==='old'?' class="p-old"':'')+'><td class="tl">'+esc(r.part_no)+'</td>'
                      + '<td>'+cats+'</td><td>'+dateTxt+'</td><td>'+esc(unit)+'</td></tr>';
            });
            body += '</tbody></table>';
        });
        if (!(res.groups||[]).length) body += '<div style="padding:20px;color:#666;">無符合條件的外來文件</div>';
        // 頁尾走 @page margin box（列印引擎繪製，每頁都有）：右下=AS 文件編號、左下=頁碼（多頁才顯示）
        var asTxt = res.as_doc ? String(res.as_doc.doc_no).replace(/['\\]/g,'') : '';
        // 左右各留 6mm 安全邊：邊界選「最小值」時 @page 的 10mm 會被覆蓋，最右欄(發行單位)會被印表機不可印區裁掉
        var css = 'body{font-family:"Microsoft JhengHei",sans-serif;margin:0;padding:0 6mm;color:#222;-webkit-print-color-adjust:exact;print-color-adjust:exact;}'
            + '.p-comp{font-size:22px;font-weight:bold;text-align:center;margin-bottom:1px;}'
            + '.p-title{font-size:17px;font-weight:bold;text-align:center;letter-spacing:6px;margin-bottom:10px;}'
            + '.p-cust{font-size:14px;font-weight:bold;margin:10px 0 3px;border-left:4px solid #F0A24B;padding-left:6px;break-after:avoid;}'
            + 'table.p-tb{width:100%;table-layout:fixed;border-collapse:collapse;font-size:11px;margin-bottom:6px;}'
            + 'table.p-tb thead{display:table-header-group;}'   // 跨頁時每頁重印表頭
            + 'table.p-tb th,table.p-tb td{border:1px solid #666;padding:2px 5px;text-align:center;overflow-wrap:anywhere;}'
            + 'table.p-tb thead th{background:#f3ead6;}'
            + 'table.p-tb td.tl{text-align:left;word-break:break-all;}'
            + 'table.p-tb tr.p-old td{color:#777;}'
            + 'table.p-tb tr{break-inside:avoid;}'
            + '.cat-pill{display:inline-block;font-size:10px;border:1px solid rgba(122,82,23,.25);border-radius:9px;padding:0 6px;margin:1px 2px;}'
            // 左右邊界 10mm、下邊界 18mm：RICOH 等實體印表機邊緣約 4~5mm 印不到，太貼邊會被裁掉
            // 註：瀏覽器頁首(日期/標題)不受 @page 邊界控制，CSS 無法關掉，要在列印跳窗「顯示更多設定」取消勾選「頁首及頁尾」
            + '@page{margin:12mm 10mm 18mm;'
            + (asTxt ? " @bottom-right{ content:'"+asTxt+"'; font-size:9pt; color:#333; vertical-align:top; padding-top:1mm; }" : '')
            + '}';
        var w = window.open('', '_blank');
        w.document.write('<html><head><meta charset="utf-8"><title>'+esc(title)+'</title><style>'+css+'</style></head><body>'+body
            +'<scr'+'ipt>window.onload=function(){'
            // 內容超過一頁(以A4概算)才加頁碼——只影響顯示不影響分頁；counter(pages) 由列印引擎在列印當下計算
            +'var onePageA4=(297-30)*96/25.4;'
            +'if(document.body.scrollHeight>onePageA4*0.92){'
            +'var st=document.createElement(\'style\');'
            +'st.textContent="@page{ @bottom-left{ content:\'第 \' counter(page) \' 頁／共 \' counter(pages) \' 頁\'; font-size:9pt; color:#333; vertical-align:top; padding-top:1mm; } }";'
            +'document.head.appendChild(st);}'
            +'setTimeout(function(){window.print();},200);};</scr'+'ipt></body></html>');
        w.document.close();
    }, 'json');
});

// ── AS 文件編號綁定 ─────────────────────────────────────────────
function renderAsSel(kw){
    kw = (kw||'').toLowerCase();
    var $s = $('#asSel').html('<option value="0">（不綁定）</option>');
    AS_DOCS.forEach(function(d){
        var t = d.doc_no + ' ' + d.doc_name;
        if (kw && t.toLowerCase().indexOf(kw) === -1) return;
        $s.append('<option value="'+d.id+'">'+esc(d.doc_no)+'　'+esc(d.doc_name)+'</option>');
    });
    $s.val(AS_DOC ? String(AS_DOC.id) : '0');
    if ($s.val() === null) $s.val('0');
}
$('#btnAsDoc').on('click', function(){ $('#asKw').val(''); renderAsSel(''); openMask('asMask'); });
$('#asKw').on('input', function(){ renderAsSel($(this).val()); });
function saveAsDoc(){
    $.post(API, {action:'save_as_doc', as_doc_id: $('#asSel').val()||0}, function(res){
        if (!res.success){ alert(res.message||'儲存失敗'); return; }
        renderAsDoc(res.as_doc);
        closeMask('asMask');
    }, 'json');
}

$('#btnRoleHelp').on('click', function(){ openMask('helpMask'); });
$('#btnPageHelp').on('click', function(){ openMask('helpUseMask'); });
$('.xd-mask').on('click', function(e){ if (e.target === this) this.style.display='none'; });

if (canView){ loadOptions(true); }   // 選項(含類別配色)載好後由 loadOptions 觸發 loadList
</script>
</body>
</html>
