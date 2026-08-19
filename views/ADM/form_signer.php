<?php
/**
 * 表單簽核設計器 — 案件（一般使用者）— 2026-08-14 新增，同日第二版改為「案件自己上傳文件」
 * 資料一律走 src/store/FormSigner_API.php；權限 src/common/form_signer_lib.php fsd_perms()
 * 建立案件＝草稿(需上傳要簽核的文件+像樣板設計頁一樣拖放框選,白名單來自樣板已框選過的欄位)，
 * 存草稿或儲存並送出二擇一；送出後意見/決策回應；檢視合成文件並列印（線上疊圖層，不產生伺服器端合併PDF）。
 * 選單入口頁（鐵律6），樣板設計子頁 form_signer_template.php 由本頁連結進入不另外登記選單。
 */
session_start();
if (!isset($_SESSION['userName'])) {
    $_SESSION['lastpage'] = "../../views/ADM/form_signer.php";
    header("Location:../../index.php");
    exit;
}
include_once '../../src/common/_config.php';
include_once '../../src/common/DBConnection.php';
include_once '../../src/common/form_signer_lib.php';

$db = (new DBConnection())->getPDO();
$fsdUser = fsd_current_user($db);
$perms = fsd_perms($db, $fsdUser);
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>表單簽核設計器 - 案件</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/nprogress.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <style>
        #sidebar-menu { visibility: hidden; }
        .right_col .page-title { margin:8px 0 4px; overflow:hidden; clear:both; }
        .fsd-toolbar { display:flex; flex-wrap:wrap; gap:6px; align-items:center; clear:both;
            border:1.5px solid #E8D5B5; border-radius:8px; padding:8px 10px; margin-bottom:10px; background:#FDF8EF; }
        .fsd-toolbar button { height:30px; font-size:13px; padding:0 12px; border:1px solid #D8BE93; border-radius:4px;
            background:#fff; color:#5b3a1e; cursor:pointer; }
        .fsd-toolbar .btn-warm { background:#F0A24B; color:#fff; border-color:#d98a33; }
        .fsd-toolbar .btn-danger { color:#a13a24; border-color:#DD5138; }
        .page-help-btn { margin-left:auto; height:30px; font-size:13px; padding:0 14px; border:1px solid #D8BE93;
            border-radius:4px; background:#fff; color:#5b3a1e; cursor:pointer; }
        .help-doc h4 { font-size:14px; color:#8A5A2B; margin:10px 0 4px; }
        table.fsd-tbl { width:100%; border-collapse:collapse; font-size:13px; background:#fff; }
        table.fsd-tbl th, table.fsd-tbl td { border:1px solid #EADFC8; padding:6px 8px; }
        table.fsd-tbl thead th { background:#F7E0BD; color:#5b3a1e; }
        .fsd-table-wrap { overflow-x:auto; border:1px solid #E8D5B5; border-radius:6px; }
        .tag-on { color:#7a5217; font-weight:bold; } .tag-off { color:#b0a390; }
        .badge-stage { display:inline-block; padding:2px 8px; border-radius:9px; font-size:11.5px; }
        .badge-draft { background:#fbeadb; color:#b5762a; }
        .badge-progress { background:#F7E0BD; color:#5b3a1e; }
        .badge-approved { background:#dcefdc; color:#2e6b2e; }
        .badge-rejected { background:#f6d9d3; color:#a13a24; }
        .badge-void { background:#eee; color:#999; }
        .prog-wrap { display:flex; flex-wrap:wrap; align-items:center; gap:2px; }
        .prog-chip { display:inline-flex; align-items:center; gap:3px; padding:2px 8px; border-radius:14px; border:1px solid #D8BE93;
            background:#FBF3E4; color:#7a5217; font-size:12px; white-space:nowrap; }
        .prog-chip.done { background:#dcefdc; border-color:#8fc98f; color:#2e6b2e; }
        .prog-chip.pending { background:#F0A24B; border-color:#c97f30; color:#5b3a1e; font-weight:bold; }
        .prog-chip.skipped { background:#eee; border-color:#ddd; color:#999; }
        .prog-chip.not_started { background:#fff; color:#b0a390; }
        .prog-chip i.fa-check-circle { color:#2e6b2e; }
        .prog-arrow { color:#c9a876; font-size:12px; margin:0 1px; }
        .fsd-thumb-grid { display:flex; flex-wrap:wrap; gap:8px; margin:6px 0; min-height:0; }
        .fsd-thumb-grid .thumb { position:relative; width:72px; height:96px; border:1.5px solid #D8BE93; border-radius:5px;
            overflow:hidden; cursor:grab; background:#fff; }
        .fsd-thumb-grid .thumb.dragover { border-color:#F0A24B; box-shadow:0 0 0 2px #F0A24B inset; }
        .fsd-thumb-grid .thumb img { width:100%; height:100%; object-fit:cover; display:block; }
        /* PDF 沒辦法用 <img> 預覽，改用檔名磚（暖色系，ai-rules/10） */
        .fsd-thumb-grid .thumb .thumb-pdf { width:100%; height:100%; display:flex; flex-direction:column; align-items:center;
            justify-content:center; gap:3px; background:#FBF3E6; color:#8A5A2B; text-align:center; padding:4px; box-sizing:border-box; }
        .fsd-thumb-grid .thumb .thumb-pdf i { font-size:22px; color:#DD5138; }
        .fsd-thumb-grid .thumb .thumb-pdf span { font-size:9px; line-height:1.15; word-break:break-all; max-height:44px; overflow:hidden; }
        .fsd-thumb-grid .thumb .tno { position:absolute; left:2px; top:2px; background:#F0A24B; color:#fff; font-size:10px;
            padding:0 4px; border-radius:3px; }
        .fsd-thumb-grid .thumb .tdel { position:absolute; right:2px; top:2px; background:rgba(0,0,0,.55); color:#fff;
            font-size:11px; width:16px; height:16px; line-height:16px; text-align:center; border-radius:50%; cursor:pointer; }
        .flt-status-btn { height:28px; font-size:12.5px; padding:0 10px; border:1px solid #D8BE93; border-radius:14px;
            background:#fff; color:#5b3a1e; cursor:pointer; }
        .flt-status-btn.active { background:#F0A24B; color:#fff; border-color:#d98a33; }
        .fsd-mask { display:none; position:fixed; inset:0; background:rgba(60,40,20,.45); z-index:1050; }
        .fsd-modal { background:#fff; border-radius:8px; max-width:520px; margin:30px auto; box-shadow:0 5px 25px rgba(0,0,0,.3);
            max-height:90vh; display:flex; flex-direction:column; }
        .fsd-modal.wide { max-width:900px; }
        .fsd-modal .m-head { background:#F7E0BD; color:#5b3a1e; font-weight:bold; padding:10px 15px; border-radius:8px 8px 0 0;
            display:flex; justify-content:space-between; }
        .fsd-modal .m-head .m-close { cursor:pointer; color:#b5762a; }
        .fsd-modal .m-body { padding:15px; overflow-y:auto; }
        .fsd-modal .m-body label { display:block; font-size:13px; color:#5b3a1e; margin:9px 0 3px; }
        .fsd-modal .m-body input[type=text], .fsd-modal .m-body input[type=date], .fsd-modal .m-body input[type=password],
        .fsd-modal .m-body input[type=file], .fsd-modal .m-body select, .fsd-modal .m-body textarea {
            width:100%; border:1px solid #D8BE93; border-radius:4px; padding:5px 8px; font-size:13px; box-sizing:border-box; }
        .fsd-modal .m-foot { padding:10px 15px; border-top:1px solid #EADFC8; text-align:right; }
        .fsd-modal .m-foot button { height:30px; padding:0 16px; border-radius:4px; font-size:13px; border:1px solid #d98a33; cursor:pointer; }
        .fsd-modal .m-foot .b-ok { background:#F0A24B; color:#fff; }
        .fsd-modal .m-foot .b-cancel { background:#fff; color:#5b3a1e; border-color:#D8BE93; margin-right:6px; }
        #detailPanel, #fieldPanel { display:none; }
        .fsd-doc-grid { display:grid; grid-template-columns:1fr 1fr; gap:16px; max-height:75vh; overflow-y:auto; padding:4px; border:1px solid #E8D5B5; border-radius:8px; background:#faf6ee; }
        /* .fsd-doc-page 用 aspect-ratio(來自頁面實際width_pt/height_pt,inline style設定)固定高寬比，
           不讓瀏覽器依img載入時機自行reflow決定高度——這是列印會多出一堆空白頁/圖章位置跑掉的根因，
           容器高寬比不確定時列印引擎可能need「縮小以符合單頁」或把圖片切成兩截，疊圖層的絕對定位%就對不準了。 */
        .fsd-doc-page { position:relative; border:1px solid #E8D5B5; border-radius:6px; overflow:hidden; background:#fff; }
        .fsd-doc-page.landscape { grid-column:1 / -1; }
        .fsd-doc-page img { display:block; width:100%; height:100%; }
        .fsd-box { position:absolute; }
        .fsd-box.stamp { display:flex; align-items:center; justify-content:center; overflow:visible; }
        /* 無綁定圖章模板時的預設回墨印/掃描章(car-stamp)：畫面上縮放至框選框大小方便預覽，列印統一91px(下方@media print)。
           有綁定圖章模板(eg-stamp-tpl)：不縮放，畫面與列印都用模板設定的實際大小(不可縮小,使用者明確要求"所見即所印")。 */
        .fsd-box.stamp svg.car-stamp, .fsd-box.stamp img { width:100%; height:100%; display:block; }
        .fsd-box.stamp svg.eg-stamp-tpl { display:block; }
        .fsd-box.reply { background:rgba(255,255,255,.85); border:1px dashed #D8BE93; font-size:11px; color:#5b3a1e; padding:2px 4px; box-sizing:border-box; overflow:hidden; }
        .fsd-box .sod-note { color:#b0a390; font-size:10px; }
        .fsd-action-panel { border:1px solid #E8D5B5; border-radius:8px; background:#fff; padding:10px; margin-top:12px; }
        .fsd-resp-list { font-size:12.5px; }
        .fp-filler { margin-left:8px; font-size:12.5px; color:#5b3a1e; }
        .fp-filler.unset { color:#DD5138; font-weight:bold; }
        .fsd-resp-list .r-row { border-bottom:1px solid #F0E7D5; padding:5px 0; }
        /* 自動簽核標記：僅管理員在簽核紀錄區看得到，@media print 不需另外隱藏（列印時整個 .fsd-action-panel 已隱藏） */
        .fsd-resp-list .auto-sign-tag { display:inline-block; background:#F7E0BD; color:#8A5A2B; border:1px solid #D8BE93;
            border-radius:3px; font-size:10.5px; line-height:1.5; padding:0 5px; margin-left:4px; vertical-align:1px; }
        .fsd-design-layout { display:flex; gap:12px; align-items:flex-start; }
        .fsd-label-panel { flex:0 0 220px; border:1px solid #E8D5B5; border-radius:8px; background:#fff; max-height:74vh; overflow-y:auto; }
        .fsd-label-panel .lp-head { background:#F7E0BD; color:#5b3a1e; font-weight:bold; padding:6px 10px; border-radius:8px 8px 0 0; }
        .fsd-label { padding:6px 8px; margin:6px; border-radius:6px; font-size:12px; cursor:grab; border:1px dashed #D8BE93; background:#FDF8EF; color:#5b3a1e; }
        .fsd-label.type-stamp { border-color:#d98a33; } .fsd-label.type-reply { border-color:#8A5A2B; }
        .fsd-label .placed { float:right; color:#3f8a3f; }
        .fsd-page-grid { flex:1; display:grid; grid-template-columns:1fr 1fr; gap:16px; max-height:74vh; overflow-y:auto; padding:4px; }
        .fsd-page-wrap { border:1px solid #E8D5B5; border-radius:6px; padding:6px; background:#faf6ee; }
        .fsd-page-wrap .pno { font-size:11px; color:#8a6d45; margin-bottom:4px; }
        /* 補案件的圖章清單列（每章一格：頁次/人員/圖章模板/定位/刪除） */
        .bf-row { border-bottom:1px solid #F0E7D5; padding:6px 8px; }
        .bf-row.bad { background:#FDF2E6; }
        .bf-row .bf-row-hd { display:flex; align-items:center; gap:6px; font-size:12px; color:#5b3a1e; margin-bottom:4px; }
        .bf-row .bf-row-hd .bf-pg { color:#8a6d45; }
        .bf-row .bf-row-hd .bf-warn { color:#DD5138; }
        .bf-row .bf-row-hd button { height:22px; font-size:11px; padding:0 6px; }
        .bf-row .bf-row-hd button:last-child { margin-left:auto; }
        .bf-row select { width:100%; margin-bottom:4px; }
        .bf-row .eg-filter-box { max-width:100%; }   /* 共用篩選框在窄面板內要滿版，不要卡在 280px */
        .fsd-ref-panel { flex:0 0 200px; border:1px solid #E8D5B5; border-radius:8px; background:#fff; max-height:74vh; overflow-y:auto; padding:8px; }
        .fsd-ref-panel .rp-head { font-weight:bold; color:#5b3a1e; margin-bottom:6px; }
        .fsd-ref-page { position:relative; border:1px solid #EADFC8; margin-bottom:10px; }
        .fsd-ref-page img { display:block; width:100%; height:100%; }
        .fsd-ref-box { position:absolute; border:1px solid #d98a33; background:rgba(240,162,75,.18); font-size:8px; color:#8A5A2B; overflow:hidden; }
        .fsd-ref-box.reply { border-color:#8A5A2B; background:rgba(138,90,43,.12); }
        @media print {
            .page-help-btn, .fsd-toolbar, #listPanel, #fieldPanel, .fsd-action-panel, .top_nav, .left_col,
            .page-title, .clearfix { display:none !important; }
            .right_col { margin:0 !important; padding:0 !important; }
            .fsd-doc-grid { display:block; max-height:none; overflow:visible; border:none; padding:0; gap:0; }
            /* 每份頁面各自獨立分頁；page-break-after 只套在非最後一頁，否則最後一頁後面會多印一張空白頁。
               寬高改由 doPrint() 依可印範圍算好明確mm尺寸蓋掉aspect-ratio，保證單頁裝得下(不會被瀏覽器
               shrink-to-fit縮放整頁，那正是先前「圖章畫面跟列印大小不同/多空白頁」的共同根因)。 */
            .fsd-doc-page { page-break-after:always; page-break-inside:avoid; border:none; border-radius:0; }
            .fsd-doc-page:last-child { page-break-after:auto; }
            /* 未綁定圖章模板時列印統一91px(ai-rules/18第6條)；有綁定模板則維持模板設定的實際大小(見上方基本樣式)不在此覆蓋 */
            .fsd-box.stamp svg.car-stamp, .fsd-box.stamp img { width:91px !important; height:91px !important; }
            .fsd-box.stamp { overflow:visible; }
            .pt-asdoc { position:fixed; right:8mm; bottom:5mm; font-size:9pt; color:#333; }
            @page { margin:10mm 8mm; }
        }
    </style>
</head>
<body class="nav-sm">
<div class="container body">
<div class="main_container">
    <?php include '../partPage/sideAndTopBarMenu.html' ?>
    <div class="right_col" role="main">
        <div class="page-title" style="display:flex;align-items:center;flex-wrap:wrap;clear:both;">
            <h2 style="margin:6px 0;">表單簽核設計器 - 案件 <small style="color:#8a6d45;">上傳文件、框選、意見/決策回應、檢視並列印簽核完成文件</small></h2>
            <button class="page-help-btn" id="btnPageHelp"><i class="fa fa-question-circle"></i> 使用說明</button>
        </div>
        <div class="clearfix"></div>

<?php if (!$perms['canView']): ?>
        <div><h4><i class="fa fa-lock"></i> 無表單簽核設計器檢閱權限</h4><p>請洽系統管理者於「使用者權限設定」指派「表單簽核設計器」相關角色。</p></div>
<?php else: ?>
        <div id="listPanel">
            <div class="fsd-toolbar">
                <span>依樣板建立案件並上傳要簽核的文件，系統依序通知各關卡簽核人。</span>
                <button class="btn-warm" id="btnAddCase" style="display:none;margin-left:auto;"><i class="fa fa-plus"></i> 建立案件</button>
                <button id="btnBackfill" style="display:none;"><i class="fa fa-file-import"></i> 補案件</button>
                <button id="btnDeletedList" style="display:none;"><i class="fa fa-trash-o"></i> 已刪除案件</button>
                <a href="form_signer_template.php" id="lnkTplAdmin" style="display:none;height:30px;line-height:28px;padding:0 12px;border:1px solid #D8BE93;border-radius:4px;color:#5b3a1e;text-decoration:none;">樣板管理→</a>
            </div>
            <div class="fsd-toolbar" style="margin-top:0;">
                <input type="text" id="fltKeyword" placeholder="搜尋案件/樣板/業務日期…" style="width:220px;" oninput="applyCaseFilter()">
                <select id="fltApplicant" onchange="applyCaseFilter()" style="width:160px;" data-eg-filter="輸入申請人姓名篩選…"><option value="">全部申請人</option></select>
                <span style="color:#8a6d45;margin-left:6px;">狀態：</span>
                <button type="button" class="flt-status-btn active" data-status="" onclick="toggleStatusFilter(this)">全部</button>
                <button type="button" class="flt-status-btn" data-status="draft" onclick="toggleStatusFilter(this)">草稿</button>
                <button type="button" class="flt-status-btn" data-status="in_progress" onclick="toggleStatusFilter(this)">進行中</button>
                <button type="button" class="flt-status-btn" data-status="approved" onclick="toggleStatusFilter(this)">已完成</button>
                <button type="button" class="flt-status-btn" data-status="rejected" onclick="toggleStatusFilter(this)">已駁回</button>
            </div>
            <div class="fsd-table-wrap">
            <table class="fsd-tbl">
                <thead><tr><th>案件</th><th>樣板</th><th>申請人</th><th>業務日期</th><th>進度</th><th>狀態</th><th style="width:160px;">操作</th></tr></thead>
                <tbody id="caseBody"><tr><td colspan="7" style="text-align:center;color:#8a6d45;">載入中…</td></tr></tbody>
            </table>
            </div>
        </div>

        <!-- 草稿：上傳文件後拖放框選(白名單來自樣板已框選過的欄位) -->
        <div id="fieldPanel">
            <div class="fsd-toolbar">
                <button id="btnFieldBack"><i class="fa fa-arrow-left"></i> 返回列表（已自動存檔）</button>
                <b id="fpTitle" style="margin-left:6px;"></b>
                <button onclick="openReplaceFile()">更換文件</button>
                <button id="btnBfEditHead" style="display:none;" onclick="openBfCreate(FP_CASE)"><i class="fa fa-pen"></i> 編輯表頭</button>
                <span id="fpFillerInfo" class="fp-filler"></span>
                <button id="btnFpSetFiller" style="display:none;" onclick="openEditFiller('fp')"><i class="fa fa-user-edit"></i> 設定填表人</button>
                <button class="btn-warm" id="btnFpSubmit" style="margin-left:auto;" onclick="fpSubmit()"><i class="fa fa-check"></i> 儲存並送出</button>
            </div>
            <div class="fsd-toolbar">
                <span id="fpHintText">把左側「待框選標籤」拖到您上傳的文件對應位置；只能框選樣板本身已框選過的欄位（樣板沒有的欄位這裡也不會出現）。</span>
                <button class="btn-danger" style="margin-left:auto;" onclick="fpDeleteSelected()"><i class="fa fa-trash"></i> 刪除選取框</button>
            </div>
            <div class="fsd-design-layout">
                <div class="fsd-ref-panel" id="refPanel">
                    <div class="rp-head">樣板參考（僅供對照，不可編輯）</div>
                    <div id="refPages"></div>
                </div>
                <div class="fsd-label-panel" id="labelPanel">
                    <div class="lp-head">待框選標籤</div>
                    <div id="fpLabelList"></div>
                </div>
                <!-- 補案件專用：圖章清單（每個圖章各自選人員與圖章模板） -->
                <div class="fsd-label-panel" id="bfStampPanel" style="display:none;flex:0 0 330px;">
                    <div class="lp-head">圖章清單 <span id="bfCount" style="float:right;font-weight:normal;"></span></div>
                    <div style="padding:8px;border-bottom:1px solid #F0E7D5;">
                        <label style="font-size:12px;color:#5b3a1e;">預設圖章模板（新增的圖章都用它，可再逐個修改）</label>
                        <select id="bfDefaultTpl" style="width:100%;" data-eg-filter="輸入圖章模板名稱篩選…"></select>
                        <button type="button" style="margin-top:6px;width:100%;" onclick="bfApplyTplAll()"><i class="fa fa-copy"></i> 一次套用到全部圖章</button>
                        <p style="font-size:11.5px;color:#8a6d45;margin:6px 0 0;">在右邊每一頁上方按「＋新增圖章」加章，再拖曳/縮放到正確位置。每個圖章都要選人員才能完成。</p>
                    </div>
                    <div id="bfList"></div>
                </div>
                <div class="fsd-page-grid" id="fpPageGrid"></div>
            </div>
        </div>

        <div id="detailPanel">
            <div class="fsd-toolbar">
                <button id="btnBackList"><i class="fa fa-arrow-left"></i> 返回列表</button>
                <b id="dtlTitle" style="margin-left:6px;"></b>
                <span id="dtlStageInfo" style="margin-left:8px;color:#5b3a1e;font-size:12.5px;"></span>
                <span id="dtlFillerInfo" style="margin-left:8px;color:#8a6d45;font-size:12.5px;"></span>
                <button id="btnEditFiller" style="display:none;" onclick="openEditFiller('dtl')"><i class="fa fa-user-edit"></i> 設定填表人</button>
                <button style="margin-left:auto;" id="btnUrge"><i class="fa fa-bell"></i> 催辦</button>
                <button id="btnRestore" style="display:none;color:#2e6b2e;border-color:#7ab57a;"><i class="fa fa-undo"></i> 復原</button>
                <button id="btnDeleteHard" class="btn-danger" style="display:none;"><i class="fa fa-trash"></i> 永久刪除</button>
                <button id="btnDeleteSoft" class="btn-danger" style="display:none;"><i class="fa fa-trash"></i> 刪除</button>
                <button id="btnPdfOpen" style="display:none;" onclick="fsdOpenPdf(false)" title="開啟已存檔的合成PDF，可直接在檢視器內列印"><i class="fa fa-file-pdf-o"></i> PDF</button>
                <button id="btnPdfDl" style="display:none;" onclick="fsdOpenPdf(true)" title="下載合成PDF檔"><i class="fa fa-download"></i> 下載PDF</button>
                <button class="btn-warm" onclick="doPrint()"><i class="fa fa-print"></i> 列印</button>
            </div>
            <div class="fsd-doc-grid" id="docGrid"></div>

            <div class="fsd-action-panel" id="advisoryPanel" style="display:none;">
                <b>意見回應</b>（無駁回動作，不卡關；同意/不同意皆會記錄並顯示在對應回覆框）
                <div style="margin-top:8px;">
                    <textarea id="advReply" rows="2" placeholder="請輸入您的意見（可留空）"></textarea>
                    <div style="margin-top:6px;">
                        <button class="btn-warm" onclick="submitAdvisory('agree')"><i class="fa fa-check"></i> 同意</button>
                        <button onclick="submitAdvisory('disagree')" class="btn-danger"><i class="fa fa-times"></i> 不同意</button>
                    </div>
                </div>
            </div>
            <div class="fsd-action-panel" id="decisionPanel" style="display:none;">
                <b>決策回應</b>（您的決定將推動流程前進或終止；可先展開下方查看前面各意見階段的回應彙總）
                <div style="margin-top:8px;">
                    <textarea id="decNote" rows="2" placeholder="核准/駁回原因（駁回必填）"></textarea>
                    <div style="margin-top:6px;">
                        <button class="btn-warm" onclick="submitDecision('approved')"><i class="fa fa-check"></i> 核准</button>
                        <button onclick="submitDecision('rejected')" class="btn-danger"><i class="fa fa-times"></i> 駁回</button>
                    </div>
                </div>
            </div>
            <div class="fsd-action-panel">
                <b>回應紀錄</b>
                <div class="fsd-resp-list" id="respList" style="margin-top:6px;"></div>
            </div>
        </div>
<?php endif; ?>
    </div>
</div>
</div>

<!-- 建立案件 modal -->
<div class="fsd-mask" id="createMask"><div class="fsd-modal">
    <div class="m-head"><span>建立案件</span><span class="m-close" onclick="closeMask('createMask')">✕</span></div>
    <div class="m-body">
        <label>選擇樣板</label>
        <select id="crTpl"><option value="">請選擇…</option></select>
        <label>要簽核的文件（圖片 png/jpg 可一次選多張、每張各成一頁、可拖曳調整頁序；或改上傳一份多頁 PDF）</label>
        <input type="file" id="crFile" accept="image/png,image/jpeg,application/pdf" multiple onchange="crFilesChanged(this.files)">
        <p style="font-size:11.5px;color:#8a6d45;margin:2px 0 0;">上傳 PDF 時最終產出的 PDF 會直接沿用原檔內容（不重新轉圖，畫質完全不損）；PDF 與圖片不可混著傳，一次也只能傳一份 PDF。加密保護的 PDF 無法處理，會在上傳時擋下。</p>
        <div id="crThumbs" class="fsd-thumb-grid"></div>
        <label>填表人（可留空，之後再指定；<b>樣板有「填表人」圖章欄位時，未指定就不能送出</b>——那個章會蓋這個人）</label>
        <select id="crFiller" data-eg-filter="輸入姓名篩選…"></select>
        <label>案件標題（可留空，預設用樣板名稱）</label><input type="text" id="crTitle" maxlength="200">
        <label>業務日期</label><input type="date" id="crDate" max="9999-12-31">
        <p style="font-size:11.5px;color:#8a6d45;">建立後進入框選畫面，把樣板提供的欄位拖到您上傳的文件上對應位置，再選擇「存草稿」或「儲存並送出」。</p>
    </div>
    <div class="m-foot"><button class="b-cancel" onclick="closeMask('createMask')">取消</button>
        <button class="b-ok" onclick="submitCreate()">建立</button></div>
</div></div>

<!-- 補案件 modal（管理員把已簽好章的紙本補進系統：不需樣板、固定自動審核） -->
<div class="fsd-mask" id="bfCreateMask"><div class="fsd-modal">
    <div class="m-head"><span id="bfCreateTitle">補案件</span><span class="m-close" onclick="closeMask('bfCreateMask')">✕</span></div>
    <div class="m-body">
        <p style="font-size:12px;color:#8a6d45;margin:0 0 8px;">把紙本上「已經簽好章」的歷史文件掃描檔補進系統：不需要樣板、送出後直接完成（固定自動審核，不會通知任何人去簽）。</p>
        <label>文件標題</label><input type="text" id="bfTitle" maxlength="200" placeholder="例：2024年度供應商稽核計劃">
        <label>業務日期（＝所有圖章要印的簽章日期）</label><input type="date" id="bfDate" max="9999-12-31">
        <label>AS 文件編號（列印右下角；可不綁）</label>
        <div style="display:flex;gap:6px;align-items:center;">
            <input type="text" id="bfAsDocLabel" readonly style="flex:1;background:#f5f0e6;" value="尚未綁定">
            <button type="button" onclick="bfPickAsDoc()">挑選…</button>
        </div>
        <div id="bfFileWrap">
            <label>文件掃描檔（圖片 png/jpg 可一次選多張、每張各成一頁、可拖曳調整頁序；或改上傳一份多頁 PDF）</label>
            <input type="file" id="bfFile" accept="image/png,image/jpeg,application/pdf" multiple onchange="bfFilesChanged(this.files)">
            <div id="bfThumbs" class="fsd-thumb-grid"></div>
        </div>
    </div>
    <div class="m-foot"><button class="b-cancel" onclick="closeMask('bfCreateMask')">取消</button>
        <button class="b-ok" id="bfCreateOk" onclick="submitBfCreate()">建立並開始設定圖章</button></div>
</div></div>

<!-- 更換文件 modal（草稿階段） -->
<div class="fsd-mask" id="replaceMask"><div class="fsd-modal">
    <div class="m-head"><span>更換文件</span><span class="m-close" onclick="closeMask('replaceMask')">✕</span></div>
    <div class="m-body">
        <label>新文件（圖片 png/jpg 可一次多張、拖曳調整頁序；或改上傳一份多頁 PDF）</label>
        <input type="file" id="rpFile" accept="image/png,image/jpeg,application/pdf" multiple onchange="rpFilesChanged(this.files)">
        <div id="rpThumbs" class="fsd-thumb-grid"></div>
        <p style="font-size:11.5px;color:#8a6d45;">更換後之前框選的位置會清空，需要重新拖放。</p>
    </div>
    <div class="m-foot"><button class="b-cancel" onclick="closeMask('replaceMask')">取消</button>
        <button class="b-ok" onclick="submitReplaceFile()">更換</button></div>
</div></div>

<!-- A4/A3裁切框 modal：框住文件實際內容範圍，取代直接信任原始像素量測出的寬高比 -->
<div class="fsd-mask" id="cropMask"><div class="fsd-modal wide">
    <div class="m-head"><span>A4/A3 裁切框</span><span class="m-close" onclick="closeMask('cropMask')">✕</span></div>
    <div class="m-body">
        <p style="font-size:11.5px;color:#8a6d45;">拖曳/縮放橘色框，框住文件上實際的頁面內容範圍（比例已鎖定為所選紙張大小），之後的圖章最小尺寸與列印版面都會依此範圍換算的實際公分數計算。套用後會清空這一頁已框選的位置。</p>
        <div style="display:flex;gap:10px;margin-bottom:8px;align-items:center;">
            <label style="margin:0;">紙張</label>
            <select id="cropPaperSize" style="width:80px;"><option value="A4">A4</option><option value="A3">A3</option></select>
            <label style="margin:0;">方向</label>
            <select id="cropOrientation" style="width:90px;"><option value="portrait">直式</option><option value="landscape">橫式</option></select>
        </div>
        <div style="border:1px solid #E8D5B5;border-radius:6px;background:#faf6ee;padding:6px;text-align:center;">
            <canvas id="cropCanvas"></canvas>
        </div>
    </div>
    <div class="m-foot"><button class="b-cancel" onclick="closeMask('cropMask')">取消</button>
        <button class="b-ok" onclick="confirmCrop()">套用裁切框</button></div>
</div></div>

<!-- 操作確認密碼 modal（一般管理員軟刪用） -->
<div class="fsd-mask" id="pwMask"><div class="fsd-modal">
    <div class="m-head"><span>輸入操作確認密碼</span><span class="m-close" onclick="closeMask('pwMask')">✕</span></div>
    <div class="m-body">
        <label>此操作會刪除案件（留有刪除紀錄，管理員可復原）</label>
        <input type="password" id="pwInput" placeholder="請輸入您的操作確認密碼">
    </div>
    <div class="m-foot"><button class="b-cancel" onclick="closeMask('pwMask')">取消</button>
        <button class="b-ok" onclick="pwConfirm()">確認刪除</button></div>
</div></div>

<!-- 設定填表人 modal（草稿：申請人本人或管理員；已送出：僅超級管理員回改。填表人=表單實際歸屬者,簽核解析基準） -->
<div class="fsd-mask" id="fillerMask"><div class="fsd-modal">
    <div class="m-head"><span>設定填表人</span><span class="m-close" onclick="closeMask('fillerMask')">✕</span></div>
    <div class="m-body">
        <label>填表人（表單實際歸屬者；簽核人來源選「填表人」或「部門自動主管」未指定部門時，即以此人為解析基準）</label>
        <select id="fillerSel" data-eg-filter="輸入姓名篩選…"></select>
    </div>
    <div class="m-foot"><button class="b-cancel" onclick="closeMask('fillerMask')">取消</button>
        <button class="b-ok" onclick="submitEditFiller()">儲存</button></div>
</div></div>

<!-- 已刪除案件 modal（管理員） -->
<div class="fsd-mask" id="deletedMask"><div class="fsd-modal wide">
    <div class="m-head"><span>已刪除案件（可復原）</span><span class="m-close" onclick="closeMask('deletedMask')">✕</span></div>
    <div class="m-body">
        <table class="fsd-tbl">
            <thead><tr><th>案件</th><th>樣板</th><th>刪除人</th><th>刪除時間</th><th>原狀態</th><th style="width:90px;"></th></tr></thead>
            <tbody id="deletedBody"></tbody>
        </table>
    </div>
    <div class="m-foot"><button class="b-ok" onclick="closeMask('deletedMask')">關閉</button></div>
</div></div>

<!-- 使用說明 modal（鐵律7） -->
<div class="fsd-mask" id="helpUseMask"><div class="fsd-modal" style="max-width:820px;">
    <div class="m-head"><span>使用說明 — 表單簽核設計器（案件）</span><span class="m-close" onclick="closeMask('helpUseMask')">✕</span></div>
    <div class="m-body help-doc" style="font-size:13px;color:#5b3a1e;line-height:1.8;">
        <h4>功能說明</h4>
        依管理員設計好的樣板建立案件並上傳「實際要簽核的文件」；樣板本身的框選結果只作為欄位提示（白名單）與參考位置，不是實際背景文件。系統依序通知各關卡簽核人，簽核人以圖章模板蓋章回應，回覆內容顯示在您自己框選好的對應位置，最終呈現一份已簽核完成的合成文件（線上檢視＋瀏覽器列印），並在案件完成時自動產生一份合成 PDF 存檔，之後可隨時重複開啟列印或下載。
        <h4>操作步驟</h4>
        <b>①建立案件</b>：選擇樣板、上傳要簽核的文件、填標題與業務日期。文件可以是<b>多張圖片</b>（png/jpg，每張各成一頁、可拖曳調整頁序），也可以是<b>一份多頁 PDF</b>；兩者不可混傳，一次也只能傳一份 PDF。<b>填表人預設「未選定」</b>，可以在這裡先選，也可以之後在框選畫面再選。<br>
        <b>②框選</b>：進入框選畫面，左側「待框選標籤」只會列出樣板本身已框選過的欄位（樣板沒有的欄位這裡也不會出現），拖到您上傳的文件對應位置；右上角可參考樣板原本的框選位置提示。完成後選「存草稿」（暫存，之後可回來繼續）或「儲存並送出」（開始跑第一關）。工具列上會顯示目前的<b>填表人</b>，可在這裡設定；若這張案件框了「填表人」的圖章欄位卻還沒指定填表人，送出時會被擋下並自動打開設定視窗。<br>
        <b>③意見階段</b>：該階段的每位槽位成員各自表示同意/不同意並可留言，沒有駁回動作、不會互相卡關，全部人（扣除自動迴避的）都回應後自動進入下一關。<br>
        <b>④決策階段</b>：1~2位決策者其中一人核准或駁回即決定流程走向；核准才會繼續跑下一關（或結案），駁回則案件立即終止。<br>
        <b>⑤催辦</b>：案件申請人或管理員可對目前階段尚未回應的人重新發送一次通知（不會強制略過或自動代為回應）。<br>
        <b>⑥列印</b>：進入案件詳情後按「列印」，瀏覽器會印出目前已疊上所有圖章/回覆內容的合成文件（自動依文件是直式或橫式調整版面，每頁各自分頁列印）。<br>
        <b>⑦PDF</b>：案件<b>完成的當下會自動產生一份合成 PDF 存檔</b>，詳情頁的「PDF」可直接開起來列印、「下載PDF」可存到自己電腦；列表上已產生 PDF 的案件也有一顆 PDF 鈕可直接開啟。同一份案件重複開啟拿到的都是同一個檔案。<b>只有已完成的案件才能匯出</b>（避免半成品被當成正式文件流出去）。<br>
        <b>⑧刪除</b>：草稿可直接刪除；已送出的案件，超級管理員可永久刪除（不留紀錄），一般管理員需輸入操作確認密碼刪除（會留刪除紀錄，可在「已刪除案件」復原）。
        <h4>補案件（管理員專屬）</h4>
        用途：把「紙本上已經簽好章」的歷史文件掃描檔補進系統。<b>不需要樣板</b>、<b>固定自動審核</b>（送出＝直接完成，不會通知任何人去簽）。<br>
        <b>①</b>列表右上角按「補案件」，填標題、業務日期（＝所有圖章要印的簽章日期）、可選一份 AS 文件（列印右下角編號，版次依業務日期回推當時生效版），上傳文件掃描檔（多張圖片可拖曳縮圖排頁序，或一份多頁 PDF）。<br>
        <b>②</b>建立後進入設定畫面：左側先選好「預設圖章模板」，再到右邊每一頁上方按「＋新增圖章」，把章拖曳/縮放到紙本上原本蓋章的位置。<br>
        <b>③</b>左側圖章清單裡逐個選「這個章是誰的」（<b>含已離職人員</b>，補舊表單用）與「這個章用哪個圖章模板」；按「一次套用到全部圖章」可把全部圖章的模板一次改成目前預設值，之後仍可逐個修改。<br>
        <b>④</b>圖章上限 <b>30</b> 個；每個圖章都必須指定人員才能按「儲存並完成」。完成後即為「已完成」狀態，可直接檢視與列印。
        <h4>重要行為</h4>
        ・補案件的所有圖章都印<b>案件業務日期</b>（不逐章設定日期）。<br>
        ・補案件的<b>圖章大小固定</b>＝該章所選圖章模板的實際尺寸（所見即所印），只能移動位置、不能拉大縮小；要換大小就換一個圖章模板（換模板時框會自動變成新尺寸）。<br>
        ・槽位解析出的人若剛好是案件申請人本人，該槽位自動略過（強制迴避），不會顯示在回覆框裡等待回應。<br>
        ・已核准/已駁回/已作廢的案件不可再回應，只能檢視與列印。<br>
        ・<b>上傳 PDF 不會被轉成圖片</b>：匯出的 PDF 直接沿用原始 PDF 的頁面內容，畫質與原檔完全相同（掃描影像是原封不動搬過去的）；畫面上看到的預覽底圖才是轉圖產生的，只用來給您拖曳定位，不影響最終檔案。<br>
        ・<b>加密保護的 PDF 無法處理</b>，會在上傳當下就擋下並說明原因，請改上傳未加密的 PDF 或圖片檔——系統不會自動改用畫質較差的方式硬做。<br>
        ・PDF 裡的圖章大小與位置跟列印版一致（未綁定圖章模板＝固定 91px，有綁定＝該模板設定的實際尺寸，皆置中於框內）。<br>
        ・<b>填表人＝這張表單實際上是誰填的</b>，也是簽核來源選「填表人」時圖章要蓋的人；簽核來源選「部門自動主管」但沒指定部門時，也是用填表人的部門去找主管（沒選填表人才退回用申請人的部門）。<br>・<b>填表人預設未選定</b>（以前會自動帶成建立案件的人，管理員代別人建案件時圖章就會蓋到管理員，所以改掉）。<b>只有框了「填表人」圖章欄位的案件，未指定填表人就不能送出</b>；沒用到填表人圖章的案件不強迫選，但仍可自願填。<br>・設定填表人的權限：<b>草稿階段</b>由申請人本人或管理員設定；<b>送出後</b>只有超級管理員可以回改。<br>・<b>回改填表人會連已經蓋好的填表人圖章一起換成新的人</b>，儲存前會跳出確認告訴您會動到幾個章；同時已產生的合成 PDF 會作廢，下次開啟案件時自動用新的章重新產生。<br>・<b>要查自動簽核紀錄請看案件詳情下方的「簽核紀錄」區</b>：管理員會在該筆紀錄後面看到橘色的「系統自動簽核」標記（補案件的每個圖章也各有一筆）。此標記<b>只出現在這裡</b>，文件本身、列印版與匯出的 PDF 上一律不顯示，一般使用者看到的也是正常的簽核紀錄。
        <h4>設定入口</h4>
        樣板的階段/槽位/框選提示由管理員在「樣板管理」頁設定；操作確認密碼在「修改個人密碼」頁設定（需超級管理員先授權）。
        <h4>權限角色</h4>
        表單簽核設計器檢閱＝看得到自己建立的案件；檢視全部案件＝看得到所有人的案件；建立/送出案件＝可建立新案件；樣板管理＝管理員專屬（<b>補案件也屬於此權限</b>，含一般管理員，不必另外設定角色）。
    </div>
    <div class="m-foot"><button class="b-ok" onclick="closeMask('helpUseMask')">關閉</button></div>
</div></div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<script src="../../resource/js/eg_input_rules.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_input_rules.js') ?>"></script>
<script src="../../resource/js/eg_stamp.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_stamp.js') ?>"></script>
<script src="../../resource/js/eg_stamp_tpl.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_stamp_tpl.js') ?>"></script>
<script src="../../resource/js/eg_date_fmt.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_date_fmt.js') ?>"></script>
<script src="../../resource/js/fabric.min.js?v=<?= @filemtime(__DIR__.'/../../resource/js/fabric.min.js') ?>"></script>
<script src="../../resource/js/eg_asdoc_picker.js?v=<?= @filemtime(__DIR__.'/../../resource/js/eg_asdoc_picker.js') ?>"></script>
<script>
var API = '../../src/store/FormSigner_API.php';
var META = {}, CASES = [], TEMPLATES = [];
var CUR_CASE = null, CUR_SCHEMA = null, CUR_RESPONSES = null, CUR_AS_DOC_NO = '', CUR_CASE_PAGES = [], CUR_FIELDS = [];
var FP_CASE = null, FP_TPL_SCHEMA = null, FP_WHITELIST = [], FP_CANVASES = {}, FP_SELECTED = null;
/* API 用 HTTP 狀態碼回錯(jerr 400/401/403…)，jQuery 在非 2xx 時不會呼叫 success，
   各處 `if(!res.ok){alert(...)}` 全都跑不到＝畫面完全沒反應、只有 console 一行紅字。
   統一在這裡把錯誤訊息顯示出來，讓使用者看得到原因(鐵律8：錯誤要顯示原因，不可靜默失敗)。 */
$(document).ajaxError(function(ev, xhr, opt){
    if (!opt || String(opt.url||'').indexOf('FormSigner_API.php') < 0) return;
    if (xhr.statusText === 'abort') return;
    var msg = '';
    try { msg = (JSON.parse(xhr.responseText || '{}') || {}).error || ''; } catch(e){}
    alert(msg || ('操作失敗（HTTP ' + (xhr.status || 0) + '），請重新整理後再試一次'));
});
function esc(s){ return $('<div>').text(s==null?'':s).html(); }
function dispDate(s){ return (window.egFmtDate && s) ? egFmtDate(s) : (s||''); }
/* 簽核紀錄要顯示時間(ai-rules/21隨機錯開分鐘規定的重點就在時間，date-only的egFmtDate()不適用這裡)。 */
function dispDateTime(s){
    if (!s) return '';
    var d = dispDate(s.substring(0,10));
    var t = s.length >= 16 ? s.substring(11,16) : '';
    return t ? (d + ' ' + t) : d;
}
function openMask(id){ $('#'+id).css('display','block'); }
function closeMask(id){ $('#'+id).css('display','none'); }
$(document).ready(function(){
    var $am = $('#sidebar-menu .nav.side-menu > li.active');
    if ($am.length) { $am.removeClass('active').find('ul.child_menu').hide(); $am.find('li.current-page').removeClass('current-page'); }
    $('#sidebar-menu').css('visibility','visible');
});
$('#btnPageHelp').on('click', function(){ openMask('helpUseMask'); });

function loadMeta(cb){
    $.getJSON(API, {action:'meta'}, function(res){
        if (!res.ok){ alert(res.error||'載入失敗'); return; }
        META = res;
        window.__ownCompany = META.company_name;
        if (!$('#crDate').val()) $('#crDate').val(META.today);
        if (META.perms.canCreate) $('#btnAddCase').show();
        if (META.perms.canAdmin) { $('#lnkTplAdmin').show(); $('#btnDeletedList').show(); $('#btnBackfill').show(); }
        if (cb) cb();
    });
}
function statusBadge(s){
    var map = {draft:['草稿','badge-draft'], in_progress:['進行中','badge-progress'], approved:['已完成','badge-approved'], rejected:['已駁回','badge-rejected'], void:['已刪除','badge-void']};
    var m = map[s] || [s,'badge-progress'];
    return '<span class="badge-stage '+m[1]+'">'+m[0]+'</span>';
}
/** 建立案件視窗的填表人下拉：預設「（未選定）」（2026-08-19 使用者要求，不再自動帶成建立者）。 */
function fillCreateFillerOptions(){
    $('#crFiller').html('<option value="">（未選定）</option>' + (META.people||[]).map(function(p){
        return '<option value="'+p.id+'">'+esc(p.display)+'</option>';
    }).join(''));
}
function loadTemplateOptionsForCreate(){
    $.getJSON(API, {action:'template_list'}, function(res){
        if (!res.ok) return;
        TEMPLATES = (res.templates||[]).filter(function(t){ return t.status==='active' && t.published_version>0; });
        $('#crTpl').html('<option value="">請選擇…</option>' + TEMPLATES.map(function(t){ return '<option value="'+t.id+'">'+esc(t.name)+'</option>'; }).join(''));
    });
}
/** 進度欄位：每個簽核槽位畫成一顆「按鈕」樣式的膠囊(如規格「()表示按鈕」)，已簽核加綠色勾勾圖示；
 *  決策階段(線性)槽位間用箭頭串接(如：(審核 林雅婷)->(核准 陳俊宏))，意見階段(並簽)槽位間並列無箭頭；
 *  不同階段之間一律視為串接(本系統各階段本來就是逐關推進，2026-08-14使用者明確要求)。 */
function renderProgressChips(c){
    if (c.case_kind === 'backfill') {
        return c.status==='draft' ? '<span style="color:#8a6d45;">待設定圖章</span>'
                                  : '<span class="prog-chip done"><i class="fa fa-check-circle"></i> (補登)</span>';
    }
    if (!c.progress || !c.progress.length) return c.status==='draft' ? '<span style="color:#8a6d45;">待框選/送出</span>' : '—';
    var parts = [];
    c.progress.forEach(function(s, si){
        var chips = (s.signers||[]).map(function(sg){
            var cls = 'prog-chip ' + sg.status;
            var icon = sg.status==='done' ? '<i class="fa fa-check-circle"></i> ' : (sg.status==='skipped' ? '<i class="fa fa-minus-circle"></i> ' : '');
            var nameTxt = sg.name ? ' '+esc(sg.name) : '';
            return '<span class="'+cls+'" title="'+esc(s.name)+'">'+icon+'('+esc(sg.label)+nameTxt+')</span>';
        });
        var innerJoiner = s.stage_type==='decision' ? '<span class="prog-arrow">→</span>' : ' ';
        parts.push(chips.join(innerJoiner));
    });
    return '<div class="prog-wrap">' + parts.join('<span class="prog-arrow">→</span>') + '</div>';
}
function renderCaseRow(c){
    var stageTxt = renderProgressChips(c);
    var isOwner = String(c.applicant_id)===String(META.uid);
    var isBf = c.case_kind === 'backfill';
    var actions = '';
    if (c.status === 'draft') {
        actions += '<button onclick="openFieldDesigner('+c.id+')">'+(isBf?'繼續設定圖章':'繼續框選')+'</button> ';
        if (isOwner || META.perms.canAdmin) actions += '<button class="btn-danger" onclick="deleteDraftFromList('+c.id+')"><i class="fa fa-trash"></i></button>';
    } else {
        actions += '<button onclick="openCase('+c.id+')">檢視</button>';
        // 已完成並產生過合成PDF的案件，列表就能直接開起來列印/下載，不必先進詳情
        if (c.status === 'approved' && c.export_pdf_name)
            actions += ' <button onclick="window.open(API+\'?action=case_export_file&id='+c.id+'\',\'_blank\')" title="開啟合成PDF（可直接列印）"><i class="fa fa-file-pdf-o"></i></button>';
    }
    var tplCell = isBf ? '<span class="badge-stage badge-draft">補案件</span>' : esc(c.template_name);
    return '<tr><td>'+esc(c.title||c.template_name)+'</td><td>'+tplCell+'</td><td>'+esc(c.applicant_name)+'</td>'
        + '<td>'+dispDate(c.business_date)+'</td><td>'+stageTxt+'</td><td>'+statusBadge(c.status)+'</td>'
        + '<td>'+actions+'</td></tr>';
}
/** 篩選：申請人為下拉(僅列出實際有案件的人)；案件/樣板/業務日期共用同一個模糊搜尋框；狀態為單選按鈕列(2026-08-14使用者明確要求)。 */
var FLT_STATUS = '';
function buildApplicantFilterOptions(){
    var seen = {}, opts = [];
    CASES.forEach(function(c){
        if (!seen[c.applicant_id]) { seen[c.applicant_id] = true; opts.push({id:c.applicant_id, name:c.applicant_name}); }
    });
    opts.sort(function(a,b){ return String(a.name).localeCompare(String(b.name), 'zh-Hant'); });
    $('#fltApplicant').html('<option value="">全部申請人</option>' + opts.map(function(o){ return '<option value="'+o.id+'">'+esc(o.name)+'</option>'; }).join(''));
}
function toggleStatusFilter(btn){
    $('.flt-status-btn').removeClass('active');
    $(btn).addClass('active');
    FLT_STATUS = $(btn).data('status') || '';
    applyCaseFilter();
}
function applyCaseFilter(){
    var kw = $.trim($('#fltKeyword').val()).toLowerCase();
    var applicant = $('#fltApplicant').val();
    var list = CASES.filter(function(c){
        if (FLT_STATUS && c.status !== FLT_STATUS) return false;
        if (applicant && String(c.applicant_id) !== String(applicant)) return false;
        if (kw) {
            var hay = [c.title, c.template_name, c.business_date].join(' ').toLowerCase();
            if (hay.indexOf(kw) === -1) return false;
        }
        return true;
    });
    var h = list.map(renderCaseRow).join('');
    $('#caseBody').html(h || '<tr><td colspan="7" style="text-align:center;color:#8a6d45;padding:10px;">'+(CASES.length?'沒有符合篩選條件的案件':'尚無案件')+'</td></tr>');
}
function loadCases(){
    $.getJSON(API, {action:'case_list'}, function(res){
        if (!res.ok){ alert(res.error||'載入失敗'); return; }
        CASES = (res.cases||[]).filter(function(c){ return c.status !== 'void'; });
        buildApplicantFilterOptions();
        applyCaseFilter();
    });
}
function deleteDraftFromList(id){
    if (!confirm('確定刪除此草稿？')) return;
    $.post(API, {action:'case_delete', csrf:META.csrf, case_id:id}, function(res){
        if (!res.ok){ alert(res.error||'刪除失敗'); return; }
        loadCases();
    }, 'json');
}
$('#btnAddCase').on('click', function(){ loadTemplateOptionsForCreate(); fillCreateFillerOptions(); CR_FILES=[]; renderCrThumbs(); $('#crFile').val(''); openMask('createMask'); });
/** 建立案件的多圖選擇+拖曳排序(2026-08-14使用者明確要求：案件只能傳圖片,可一次多張,拖曳排序決定頁序)。 */
var CR_FILES = [];
/* -------- 上傳檔案共用處理（圖片多張 或 單一多頁PDF，2026-08-19 重新開放 PDF） --------
   PDF 沒辦法用 <img> 預覽，縮圖改顯示檔名磚；混傳/多份PDF 在選檔當下就擋掉（後端 API 也會再擋一次，
   不做只擋前端的半套，鐵律8）。 */
function fsdIsPdfFile(f){ return /\.pdf$/i.test(f.name || '') || f.type === 'application/pdf'; }
function fsdThumbInner(f){
    if (fsdIsPdfFile(f)) return '<div class="thumb-pdf"><i class="fa fa-file-pdf-o"></i><span>'+esc(f.name)+'</span></div>';
    return '<img src="'+URL.createObjectURL(f)+'">';
}
/** 回傳整理過的檔案陣列；不合格時 alert 說明原因並回傳 null（呼叫端就維持原本已選的清單不動）。 */
function fsdCheckFiles(list){
    var files = Array.prototype.slice.call(list);
    if (!files.length) return files;
    var pdfs = files.filter(fsdIsPdfFile);
    if (pdfs.length && pdfs.length !== files.length){ alert('PDF 不能和圖片混在一起上傳。\n請擇一：整份 PDF，或多張圖片。'); return null; }
    if (pdfs.length > 1){ alert('一次只能上傳一份 PDF（PDF 本身可以是多頁）。'); return null; }
    var bad = files.filter(function(f){ return !fsdIsPdfFile(f) && !/\.(png|jpe?g)$/i.test(f.name || ''); });
    if (bad.length){ alert('只接受圖片(png/jpg)或 PDF：\n' + bad.map(function(f){ return f.name; }).join('\n')); return null; }
    return files;
}
function crFilesChanged(fileList){ var f = fsdCheckFiles(fileList); if (f === null){ $('#crFile').val(''); return; } CR_FILES = f; renderCrThumbs(); }
function crRemoveThumb(i){ CR_FILES.splice(i,1); renderCrThumbs(); }
function renderCrThumbs(){
    var h = '';
    CR_FILES.forEach(function(f, i){
        h += '<div class="thumb" draggable="true" data-idx="'+i+'">'+fsdThumbInner(f)
           + '<span class="tno">'+(i+1)+'</span><span class="tdel" onclick="crRemoveThumb('+i+')">×</span></div>';
    });
    var $g = $('#crThumbs').html(h);
    var dragIdx = null;
    $g.find('.thumb').on('dragstart', function(){ dragIdx = $(this).data('idx'); });
    $g.find('.thumb').on('dragover', function(e){ e.preventDefault(); $(this).addClass('dragover'); });
    $g.find('.thumb').on('dragleave', function(){ $(this).removeClass('dragover'); });
    $g.find('.thumb').on('drop', function(e){
        e.preventDefault(); $(this).removeClass('dragover');
        var dropIdx = $(this).data('idx');
        if (dragIdx === null || dragIdx === dropIdx) return;
        var moved = CR_FILES.splice(dragIdx, 1)[0];
        CR_FILES.splice(dropIdx, 0, moved);
        renderCrThumbs();
    });
}
function submitCreate(){
    var tid = $('#crTpl').val();
    if (!tid){ alert('請選擇樣板'); return; }
    if (!CR_FILES.length){ alert('請上傳要簽核的文件（圖片或 PDF）'); return; }
    var fd = new FormData();
    fd.append('action','case_create_draft'); fd.append('csrf', META.csrf); fd.append('template_id', tid);
    fd.append('title', $.trim($('#crTitle').val())); fd.append('business_date', $('#crDate').val());
    fd.append('filler_id', $('#crFiller').val() || 0);
    CR_FILES.forEach(function(f){ fd.append('files[]', f); });
    fetch(API, {method:'POST', body:fd}).then(function(r){ return r.json(); }).then(function(res){
        if (!res.ok){ alert(res.error||'建立失敗'); return; }
        closeMask('createMask'); $('#crTitle').val(''); $('#crFile').val(''); $('#crFiller').val(''); CR_FILES=[]; renderCrThumbs();
        openFieldDesigner(res.id);
    }).catch(function(){ alert('建立失敗（連線錯誤）'); });
}

/* ============================================================ 補案件（管理員補登已簽好章的紙本；不需樣板、固定自動審核） ============================================================
   跟一般案件的差別：沒有樣板＝沒有待框選標籤白名單，改成自己按「＋新增圖章」加章，每個章各自選「誰的章」與
   「用哪個圖章模板」（模板可先用左側預設值一次套用再逐個改），上限 30 個；送出＝直接完成，不跑關卡不發通知。
   簽章日期一律用案件業務日期（2026-08-17 使用者拍板）。 */
var BF_META = null, BF_FILES = [], BF_AS_DOC_ID = 0, BF_EDIT_CASE = null;
var FP_BACKFILL = false, BF_FIELDS = [], BF_OBJS = {};

function bfMaxStamps(){ return (BF_META && BF_META.max_stamps) || 30; }
function bfEnsureMeta(cb){
    if (BF_META){ cb(); return; }
    $.getJSON(API, {action:'backfill_meta'}, function(res){
        if (!res.ok){ alert(res.error||'載入補案件資料失敗'); return; }
        BF_META = res; cb();
    });
}
function bfPeopleOptionsHtml(selId){
    var h = '<option value="">請選擇人員…</option>';
    ((BF_META&&BF_META.people)||[]).forEach(function(p){
        h += '<option value="'+p.id+'"'+(String(p.id)===String(selId||'')?' selected':'')+'>'+esc(p.label)+'</option>';
    });
    return h;
}
function bfTplOptionsHtml(selId){
    var h = '<option value="0"'+(!selId||String(selId)==='0'?' selected':'')+'>（系統預設回墨印）</option>';
    ((BF_META&&BF_META.stamp_tpls)||[]).forEach(function(t){
        var nm = t.tpl_name + (t.type_name ? '（'+t.type_name+'）' : '');
        h += '<option value="'+t.id+'"'+(String(t.id)===String(selId||'')?' selected':'')+'>'+esc(nm)+'</option>';
    });
    return h;
}
function bfTplSchema(tplId){
    var out = null;
    ((BF_META&&BF_META.stamp_tpls)||[]).forEach(function(t){ if (String(t.id)===String(tplId||'')) out = t.schema||null; });
    return out;
}
function bfDefaultTplId(){ return parseInt($('#bfDefaultTpl').val(), 10) || 0; }
function bfFieldById(fid){
    var out = null;
    BF_FIELDS.forEach(function(f){ if (String(f.id)===String(fid)) out = f; });
    return out;
}
function bfFieldBySlot(slotKey){
    var out = null;
    BF_FIELDS.forEach(function(f){ if (f.slot_key === slotKey) out = f; });
    return out;
}

/* -------- 建立/編輯表頭 -------- */
$('#btnBackfill').on('click', function(){ bfEnsureMeta(function(){ openBfCreate(null); }); });
function openBfCreate(caseObj){
    bfEnsureMeta(function(){
        var isEdit = !!(caseObj && caseObj.id);
        BF_EDIT_CASE = isEdit ? caseObj : null;
        $('#bfCreateTitle').text(isEdit ? '編輯補案件表頭' : '補案件');
        $('#bfCreateOk').text(isEdit ? '儲存' : '建立並開始設定圖章');
        $('#bfFileWrap').toggle(!isEdit);   // 編輯表頭不換檔案(要換檔走「更換文件」)
        $('#bfTitle').val(isEdit ? (caseObj.title||'') : '');
        $('#bfDate').val(isEdit ? (caseObj.business_date||'') : (META.today||''));
        BF_AS_DOC_ID = isEdit ? (parseInt(caseObj.as_doc_id,10)||0) : 0;
        renderBfAsDocLabel();
        BF_FILES = []; $('#bfFile').val(''); renderBfThumbs();
        openMask('bfCreateMask');
    });
}
function renderBfAsDocLabel(){
    var doc = null;
    ((BF_META&&BF_META.as_docs)||[]).forEach(function(d){ if (String(d.id)===String(BF_AS_DOC_ID)) doc = d; });
    $('#bfAsDocLabel').val(window.EGAsDoc ? EGAsDoc.label(doc) : (doc ? doc.doc_no : '尚未綁定'));
}
/* AS文件一律走共用挑選器(打編號即時篩選)，禁止純下拉——AS文件已160多份(ai-rules/16 第一之三節) */
function bfPickAsDoc(){
    if (!window.EGAsDoc){ alert('AS文件挑選器未載入'); return; }
    EGAsDoc.open({ docs:(BF_META&&BF_META.as_docs)||[], current:BF_AS_DOC_ID, title:'補案件 AS 文件編號（列印右下角）',
        onSave: function(id){ BF_AS_DOC_ID = id||0; renderBfAsDocLabel(); } });
}
function bfFilesChanged(fileList){ var f = fsdCheckFiles(fileList); if (f === null){ $('#bfFile').val(''); return; } BF_FILES = f; renderBfThumbs(); }
function bfRemoveThumb(i){ BF_FILES.splice(i,1); renderBfThumbs(); }
function renderBfThumbs(){
    var h = '';
    BF_FILES.forEach(function(f, i){
        h += '<div class="thumb" draggable="true" data-idx="'+i+'">'+fsdThumbInner(f)
           + '<span class="tno">'+(i+1)+'</span><span class="tdel" onclick="bfRemoveThumb('+i+')">×</span></div>';
    });
    var $g = $('#bfThumbs').html(h);
    var dragIdx = null;
    $g.find('.thumb').on('dragstart', function(){ dragIdx = $(this).data('idx'); });
    $g.find('.thumb').on('dragover', function(e){ e.preventDefault(); $(this).addClass('dragover'); });
    $g.find('.thumb').on('dragleave', function(){ $(this).removeClass('dragover'); });
    $g.find('.thumb').on('drop', function(e){
        e.preventDefault(); $(this).removeClass('dragover');
        var dropIdx = $(this).data('idx');
        if (dragIdx === null || dragIdx === dropIdx) return;
        var moved = BF_FILES.splice(dragIdx, 1)[0];
        BF_FILES.splice(dropIdx, 0, moved);
        renderBfThumbs();
    });
}
function submitBfCreate(){
    var title = $.trim($('#bfTitle').val()), bizDate = $('#bfDate').val();
    if (!bizDate){ alert('請填業務日期（所有圖章都會印這個日期）'); return; }
    if (BF_EDIT_CASE){
        $.post(API, {action:'backfill_update_head', csrf:META.csrf, case_id:BF_EDIT_CASE.id,
                     title:title, business_date:bizDate, as_doc_id:BF_AS_DOC_ID}, function(res){
            if (!res.ok){ alert(res.error||'儲存失敗'); return; }
            closeMask('bfCreateMask');
            if (FP_CASE && String(FP_CASE.id)===String(BF_EDIT_CASE.id)) { FP_CASE.title=res.case.title; FP_CASE.business_date=res.case.business_date; FP_CASE.as_doc_id=res.case.as_doc_id; $('#fpTitle').text(FP_CASE.title||''); }
            BF_EDIT_CASE = null;
        }, 'json');
        return;
    }
    if (!BF_FILES.length){ alert('請上傳文件掃描檔（圖片或 PDF）'); return; }
    var fd = new FormData();
    fd.append('action','backfill_create_draft'); fd.append('csrf', META.csrf);
    fd.append('title', title); fd.append('business_date', bizDate); fd.append('as_doc_id', BF_AS_DOC_ID);
    BF_FILES.forEach(function(f){ fd.append('files[]', f); });
    fetch(API, {method:'POST', body:fd}).then(function(r){ return r.json(); }).then(function(res){
        if (!res.ok){ alert(res.error||'建立失敗'); return; }
        closeMask('bfCreateMask'); BF_FILES=[]; $('#bfFile').val(''); renderBfThumbs();
        openFieldDesigner(res.id);
    }).catch(function(){ alert('建立失敗（連線錯誤）'); });
}

/* -------- 圖章清單（每章各自選人員/模板） -------- */
function renderBfList(){
    $('#bfCount').text(BF_FIELDS.length + ' / ' + bfMaxStamps() + ' 個');
    var h = '';
    BF_FIELDS.forEach(function(f, i){
        h += '<div class="bf-row'+(f.signer_user_id?'':' bad')+'" id="bfrow_'+f.id+'">'
           + '<div class="bf-row-hd"><b>圖章 '+(i+1)+'</b><span class="bf-pg">第'+f.page_no+'頁</span>'
           + '<span class="bf-warn"'+(f.signer_user_id?' style="display:none;"':'')+'>未指定人員</span>'
           + '<button type="button" onclick="bfFocus('+f.id+')">定位</button>'
           + '<button type="button" class="btn-danger" onclick="bfDeleteStamp('+f.id+')"><i class="fa fa-trash"></i></button></div>'
           + '<select onchange="bfSetSigner('+f.id+',this.value)" data-eg-filter="打部分姓名／部門／職稱即可篩選…">'+bfPeopleOptionsHtml(f.signer_user_id)+'</select>'
           + '<select onchange="bfSetTpl('+f.id+',this.value)" data-eg-filter="輸入圖章模板名稱篩選…">'+bfTplOptionsHtml(f.stamp_tpl_id)+'</select>'
           + '</div>';
    });
    $('#bfList').html(h || '<p style="padding:8px;color:#8a6d45;font-size:12px;">尚未新增圖章，請到右邊頁面上方按「＋新增圖章」。</p>');
}
/** 只更新某一列的狀態（改人員/改模板時用）：整份 renderBfList() 會把使用者正在打字的篩選框洗掉、捲軸也跳回頂端。 */
function bfRefreshRow(fid){
    var f = bfFieldById(fid); if (!f) return;
    var $row = $('#bfrow_'+fid);
    $row.toggleClass('bad', !f.signer_user_id);
    $row.find('.bf-warn').toggle(!f.signer_user_id);
    $row.find('.bf-pg').text('第'+f.page_no+'頁');
}
/** 共用存檔：把某個圖章目前的位置＋人員＋模板整包送後端（尺寸由後端依模板決定，30 個上限也由後端擋）。
 *  skipRender=true 時只更新該列狀態，不整份重畫清單。 */
function bfSaveField(fieldObj, onDone, onFail, skipRender){
    $.post(API, {action:'backfill_field_save', csrf:META.csrf, case_id:FP_CASE.id, field:JSON.stringify(fieldObj)}, function(res){
        if (!res.ok){ alert(res.error||'儲存失敗'); if (onFail) onFail(); return; }
        BF_FIELDS = res.fields || [];
        if (skipRender) bfRefreshRow(res.id); else renderBfList();
        if (onDone) onDone(res);
    }, 'json').fail(function(){ if (onFail) onFail(); });
}
function bfSaveFieldPosition(pageNo, obj){
    var cv = FP_CANVASES[pageNo];
    if (!cv || !obj) return;
    var cur = obj.fieldId ? bfFieldById(obj.fieldId) : null;
    var f = {
        id: obj.fieldId||0, page_no: pageNo,
        x: obj.left/cv.width, y: obj.top/cv.height,
        w: (obj.width*obj.scaleX)/cv.width, h: (obj.height*obj.scaleY)/cv.height,
        signer_user_id: cur ? (cur.signer_user_id||0) : 0,
        stamp_tpl_id: cur ? (cur.stamp_tpl_id||0) : bfDefaultTplId()
    };
    bfSaveField(f, function(res){
        obj.fieldId = res.id; BF_OBJS[res.id] = obj;
        bfSyncBox(res.id);   // 尺寸/夾邊以後端為準（圖章大小固定）
    }, function(){
        // 被後端擋下(例如框太小)：新框直接移除、既有框重新載入回上一個已存檔狀態，畫面不留下假資料
        if (!obj.fieldId) { cv.remove(obj); FP_SELECTED = null; cv.renderAll(); }
        else openFieldDesigner(FP_CASE.id);
    });
}
function bfAddStamp(pageNo){
    if (BF_FIELDS.length >= bfMaxStamps()){ alert('圖章數量已達上限 '+bfMaxStamps()+' 個'); return; }
    var p = (FP_CASE.pages||[]).filter(function(x){ return x.page_no==pageNo; })[0];
    if (!p){ alert('找不到第'+pageNo+'頁'); return; }
    // 先用同一套算法在本機估一個框（存檔後 bfSyncBox 會以後端算出的固定尺寸為準）
    var minFrac = fieldMinFrac(p, bfTplSchema(bfDefaultTplId()));
    var wFrac = Math.min(0.98, minFrac.min_w), hFrac = Math.min(0.98, minFrac.min_h);
    // 依已有張數往右下角錯開一點，連續新增才不會整疊在同一個位置上互相蓋住
    var off = (BF_FIELDS.filter(function(f){ return f.page_no==pageNo; }).length % 6) * 0.04;
    var xFrac = Math.max(0, Math.min(1-wFrac, 0.10 + off)), yFrac = Math.max(0, Math.min(1-hFrac, 0.70 + off));
    var g = fpAddFieldBox(pageNo, '', 'stamp', xFrac, yFrac, wFrac, hFrac, 0);
    bfSaveFieldPosition(pageNo, g);
}
function bfSetSigner(fid, uid){
    var f = bfFieldById(fid); if (!f) return;
    bfSaveField({id:f.id, page_no:f.page_no, x:f.x, y:f.y, w:f.w, h:f.h,
                 signer_user_id:parseInt(uid,10)||0, stamp_tpl_id:f.stamp_tpl_id||0},
        function(){ bfPaintBoxLabel(BF_OBJS[fid]); }, function(){ renderBfList(); }, true);
}
function bfSetTpl(fid, tplId){
    var f = bfFieldById(fid); if (!f) return;
    bfSaveField({id:f.id, page_no:f.page_no, x:f.x, y:f.y, w:f.w, h:f.h,
                 signer_user_id:f.signer_user_id||0, stamp_tpl_id:parseInt(tplId,10)||0},
        function(){ bfSyncBox(fid); },   // 換模板＝章的實際大小跟著變，框要重畫成新尺寸
        function(){ renderBfList(); }, true);
}
function bfApplyTplAll(){
    if (!BF_FIELDS.length){ alert('目前還沒有圖章'); return; }
    var tplId = bfDefaultTplId();
    if (!confirm('確定把全部 '+BF_FIELDS.length+' 個圖章的模板都改成目前選的預設模板？（之後仍可逐個修改）')) return;
    $.post(API, {action:'backfill_apply_tpl_all', csrf:META.csrf, case_id:FP_CASE.id, stamp_tpl_id:tplId}, function(res){
        if (!res.ok){ alert(res.error||'套用失敗'); return; }
        BF_FIELDS = res.fields || [];
        renderBfList();
        BF_FIELDS.forEach(function(f){ bfSyncBox(f.id); });   // 全部換模板＝全部的框尺寸都要跟著重畫
    }, 'json');
}
function bfDeleteStamp(fid){
    if (!confirm('確定刪除這個圖章？')) return;
    $.post(API, {action:'case_field_delete', csrf:META.csrf, case_id:FP_CASE.id, field_id:fid}, function(res){
        if (!res.ok){ alert(res.error||'刪除失敗'); return; }
        var obj = BF_OBJS[fid];
        if (obj && FP_CANVASES[obj.pageNo]) { FP_CANVASES[obj.pageNo].remove(obj); FP_CANVASES[obj.pageNo].renderAll(); }
        delete BF_OBJS[fid];
        BF_FIELDS = res.fields || [];
        FP_SELECTED = null;
        renderBfList();
    }, 'json');
}
function bfFocus(fid){
    var obj = BF_OBJS[fid]; if (!obj) return;
    var cv = FP_CANVASES[obj.pageNo]; if (!cv) return;
    cv.setActiveObject(obj); cv.renderAll();
    FP_SELECTED = {canvas:cv, obj:obj};
    var el = document.getElementById('fpcv_'+obj.pageNo);
    if (el && el.scrollIntoView) el.scrollIntoView({block:'center', behavior:'smooth'});
}
/** 圖章框固定大小：尺寸與夾邊都由後端算好，畫面上的框一律以後端回來的值為準（不一致就整個重畫那一個框）。 */
function bfSyncBox(fid){
    var f = bfFieldById(fid); if (!f) return;
    var cv = FP_CANVASES[f.page_no]; if (!cv) return;
    var obj = BF_OBJS[fid];
    var needL = parseFloat(f.x)*cv.width, needT = parseFloat(f.y)*cv.height;
    var needW = parseFloat(f.w)*cv.width, needH = parseFloat(f.h)*cv.height;
    if (obj) {
        var curW = obj.width*obj.scaleX, curH = obj.height*obj.scaleY;
        if (Math.abs(curW-needW) < 0.8 && Math.abs(curH-needH) < 0.8 &&
            Math.abs(obj.left-needL) < 0.8 && Math.abs(obj.top-needT) < 0.8) { bfPaintBoxLabel(obj); return; }
        cv.remove(obj);
    }
    var g = fpAddFieldBox(f.page_no, f.slot_key, 'stamp', parseFloat(f.x), parseFloat(f.y), parseFloat(f.w), parseFloat(f.h), f.id);
    BF_OBJS[fid] = g;
    cv.renderAll();
}
/** 框內顯示的字：補案件顯示這個章是誰的（沒選人顯示「未指定」），一眼看得出哪個位置蓋誰的章。 */
function bfPaintBoxLabel(obj){
    if (!obj || !obj._objects || !obj._objects[1]) return;
    var f = obj.fieldId ? bfFieldById(obj.fieldId) : null;
    obj._objects[1].set({text: (f && f.signer_name) ? f.signer_name : '未指定'});
    if (FP_CANVASES[obj.pageNo]) FP_CANVASES[obj.pageNo].renderAll();
}

/* ============================================================ PDF.js（案件文件/樣板參考共用） ============================================================ */
const PDFJS_BASE = '../../resource/js/pdfjs/';
const PDFJS_V = '<?= @filemtime(__DIR__.'/../../resource/js/pdfjs/pdf.min.js') ?>';
let pdfjsLoading = null;
function ensurePdfJs(){
    if (window.pdfjsLib) return Promise.resolve(window.pdfjsLib);
    if (pdfjsLoading) return pdfjsLoading;
    pdfjsLoading = new Promise(function(resolve, reject){
        var s = document.createElement('script');
        s.src = PDFJS_BASE + 'pdf.min.js?v=' + PDFJS_V;
        s.onload = function(){
            if (!window.pdfjsLib){ pdfjsLoading = null; reject(new Error('pdfjsLib未載入')); return; }
            window.pdfjsLib.GlobalWorkerOptions.workerSrc = PDFJS_BASE + 'pdf.worker.min.js?v=' + PDFJS_V;
            resolve(window.pdfjsLib);
        };
        s.onerror = function(){ pdfjsLoading = null; reject(new Error('pdf.min.js載入失敗')); };
        document.head.appendChild(s);
    });
    return pdfjsLoading;
}
/** 把某文件(image或pdf)的每一頁畫成dataURL/URL陣列回呼 cb(pageNo, src)，pdf額外提供量測到的width_pt/height_pt(cbMeasured可選)。 */
/** 把來源(img)依rotation(0/90/180/270)轉正畫到新canvas，回傳該canvas；旋轉會交換寬高。 */
function rotateToCanvas(src, srcW, srcH, rotationDeg){
    rotationDeg = ((rotationDeg||0) % 360 + 360) % 360;
    var swapped = (rotationDeg === 90 || rotationDeg === 270);
    var outW = swapped ? srcH : srcW, outH = swapped ? srcW : srcH;
    var cv = document.createElement('canvas'); cv.width = outW; cv.height = outH;
    var ctx = cv.getContext('2d');
    ctx.translate(outW/2, outH/2);
    ctx.rotate(rotationDeg * Math.PI/180);
    ctx.drawImage(src, -srcW/2, -srcH/2, srcW, srcH);
    return cv;
}
/** 每頁各自的 rotation(0/90/180/270,人工修正掃描歪斜方向用)：PDF直接用pdf.js viewport rotation參數轉正
 *  最省事；圖片(image類型整份文件只有1頁)用canvas重繪轉正。回呼cb(pageNo, dataURL)。 */
/** 裁切(A4/A3裁切框)：從來源(已轉正)畫面擷取crop_x/y/w/h(0~1分數)範圍畫到新canvas。crop_w/h=1且x/y=0時視為不裁切。 */
function cropCanvas(src, srcW, srcH, cx, cy, cw, ch){
    if ((!cx && !cy && cw>=1 && ch>=1)) return src;
    var outW = Math.round(srcW*cw), outH = Math.round(srcH*ch);
    var cv = document.createElement('canvas'); cv.width = outW; cv.height = outH;
    var ctx = cv.getContext('2d');
    ctx.drawImage(src, srcW*cx, srcH*cy, outW, outH, 0, 0, outW, outH);
    return cv;
}
/** 案件文件URL解析：pdf型別維持單一多頁檔案(case_file)；image型別一律走case_page_file(每頁各自檔案，
 *  向下相容單檔舊案件——後端case_page_file找不到該頁自己的檔名時會自動退回case.file_name)。 */
function caseDocUrl(caseObj, fileType){
    if (fileType === 'pdf') return API + '?action=case_file&id=' + caseObj.id;
    return function(pageNo){ return API + '?action=case_page_file&id=' + caseObj.id + '&page_no=' + pageNo; };
}
function renderDocPages(fileType, fileUrl, pages, cb) {
    if (fileType === 'pdf') {
        ensurePdfJs().then(function(lib){ return lib.getDocument({url:fileUrl, withCredentials:true}).promise; }).then(function(doc){
            pages.forEach(function(p){
                doc.getPage(p.page_no).then(function(page){
                    var rotation = (p.rotation||0) % 360;
                    var base = page.getViewport({scale:1, rotation:rotation});
                    // 列印畫質：base是72dpi的pt尺寸,scale=目標dpi/72；原本用1000px長邊上限換算約只有70~120dpi,
                    // 印出來會糊(2026-08-14使用者實測回報)。改成約220dpi、長邊另設3500px上限防止巨大PDF記憶體爆掉。
                    var scale = Math.min(220/72, 3500/Math.max(base.width, base.height));
                    var vp = page.getViewport({scale:scale, rotation:rotation});
                    var cv = document.createElement('canvas'); cv.width = Math.round(vp.width); cv.height = Math.round(vp.height);
                    var ctx = cv.getContext('2d'); ctx.fillStyle = '#fff'; ctx.fillRect(0,0,cv.width,cv.height);
                    page.render({canvasContext:ctx, viewport:vp}).promise.then(function(){
                        var out = cropCanvas(cv, cv.width, cv.height, p.crop_x||0, p.crop_y||0, p.crop_w!=null?p.crop_w:1, p.crop_h!=null?p.crop_h:1);
                        cb(p.page_no, out.toDataURL('image/png'));
                    });
                });
            });
        }).catch(function(e){ alert('PDF讀取失敗：'+(e.message||e)); });
    } else {
        pages.forEach(function(p){
            // image型別的fileUrl可以是字串(舊版單檔案案件/樣板)或函式(新版每頁各自檔案,依page_no解析各自URL)
            var url = (typeof fileUrl === 'function') ? fileUrl(p.page_no) : fileUrl;
            var hasCrop = (p.crop_x||0)>0 || (p.crop_y||0)>0 || (p.crop_w!=null && p.crop_w<1) || (p.crop_h!=null && p.crop_h<1);
            if (!p.rotation && !hasCrop) { cb(p.page_no, url); return; }
            var img = new Image();
            img.onload = function(){
                var base = p.rotation ? rotateToCanvas(img, img.naturalWidth, img.naturalHeight, p.rotation) : img;
                var baseW = base.width || img.naturalWidth, baseH = base.height || img.naturalHeight;
                var out = cropCanvas(base, baseW, baseH, p.crop_x||0, p.crop_y||0, p.crop_w!=null?p.crop_w:1, p.crop_h!=null?p.crop_h:1);
                cb(p.page_no, out.toDataURL ? out.toDataURL('image/png') : url);
            };
            img.src = url;
        });
    }
}
/** 文件整體是直式或橫式：以頁面寬高比自動判斷(width_pt>=height_pt視為橫式)，供檢視版面與列印@page自動套用。 */
function isLandscapeDoc(pages){
    if (!pages || !pages.length) return false;
    return parseFloat(pages[0].width_pt) >= parseFloat(pages[0].height_pt);
}

/* ============================================================ 案件詳情：合成文件疊圖層 + 回應 ============================================================ */
function openCase(id){
    $.getJSON(API, {action:'case_get', id:id}, function(res){
        if (!res.ok){ alert(res.error||'載入失敗'); return; }
        if (res.case.status === 'draft') { openFieldDesigner(id); return; }
        CUR_CASE = res.case; CUR_SCHEMA = res.schema; CUR_RESPONSES = res.responses;
        $('#listPanel,#fieldPanel').hide(); $('#detailPanel').show();
        $('#dtlTitle').text(CUR_CASE.title || '');
        var stageTxt = CUR_CASE.status==='in_progress' ? ('目前第'+CUR_CASE.current_stage_seq+'關：'+(res.current_stage?res.current_stage.name:'')) : statusBadge(CUR_CASE.status).replace(/<[^>]+>/g,'');
        $('#dtlStageInfo').html(stageTxt);
        $('#advisoryPanel').toggle(!!res.can_advisory_respond);
        $('#decisionPanel').toggle(!!res.can_decision_respond);
        $('#advReply').val(''); $('#decNote').val('');
        $('#btnDeleteHard').toggle(!!res.can_delete_hard);
        $('#btnDeleteSoft').toggle(!!res.can_delete_soft);
        $('#btnRestore').toggle(CUR_CASE.status==='void' && META.perms.canAdmin);
        CUR_FILLER_STAMPED = parseInt(res.filler_stamped, 10) || 0;
        // 填表人一律顯示出來（含「未選定」），不再只有跟申請人不同時才顯示——看不到就不會發現章要蓋誰還沒決定
        $('#dtlFillerInfo').html(CUR_CASE.filler_id
            ? '填表人：'+esc(CUR_CASE.filler_name||'')
            : '<span style="color:#DD5138;font-weight:bold;">填表人：未選定</span>');
        $('#btnEditFiller').toggle(!!res.can_set_filler);
        CUR_AS_DOC_NO = res.as_doc_no || '';
        CUR_CASE_PAGES = res.pages || []; // 案件自己上傳文件的頁面(不是CUR_SCHEMA.pages那份樣板參考頁!)，doPrint()量版面一定要用這份
        CUR_FIELDS = res.fields || [];
        $('#btnUrge').toggle(CUR_CASE.case_kind !== 'backfill'); // 補案件沒有待處理人，催辦沒有意義
        renderResponses();
        renderDocGrid(CUR_CASE_PAGES, CUR_FIELDS);
        renderPdfButtons();
        // 已完成但還沒有合成PDF（剛簽完的、或當初產生失敗的舊案件）→ 背景補產一份，失敗不吵使用者
        if (CUR_CASE.status === 'approved' && !CUR_CASE.export_pdf_name) fsdExportPdf(CUR_CASE.id, true);
    });
}
$('#btnBackList').on('click', function(){ $('#detailPanel').hide(); $('#listPanel').show(); CUR_CASE=null; loadCases(); });
$('#btnUrge').on('click', function(){
    if (!CUR_CASE) return;
    $.post(API, {action:'case_urge', csrf:META.csrf, case_id:CUR_CASE.id}, function(res){
        alert(res.ok ? '已重新通知尚未回應的人' : (res.error||'催辦失敗'));
    }, 'json');
});
$('#btnDeleteHard').on('click', function(){
    if (!CUR_CASE) return;
    if (!confirm('確定要永久刪除此案件？不會留下任何刪除紀錄，無法復原！')) return;
    $.post(API, {action:'case_delete', csrf:META.csrf, case_id:CUR_CASE.id}, function(res){
        if (!res.ok){ alert(res.error||'刪除失敗'); return; }
        $('#detailPanel').hide(); $('#listPanel').show(); loadCases();
    }, 'json');
});
$('#btnDeleteSoft').on('click', function(){ $('#pwInput').val(''); openMask('pwMask'); });
function pwConfirm(){
    var pw = $('#pwInput').val();
    if (!pw){ alert('請輸入密碼'); return; }
    $.post(API, {action:'case_delete', csrf:META.csrf, case_id:CUR_CASE.id, password:pw}, function(res){
        if (!res.ok){ alert(res.error||'刪除失敗'); return; }
        closeMask('pwMask'); $('#detailPanel').hide(); $('#listPanel').show(); loadCases();
    }, 'json');
}
$('#btnRestore').on('click', function(){
    if (!CUR_CASE) return;
    $.post(API, {action:'case_restore', csrf:META.csrf, case_id:CUR_CASE.id}, function(res){
        if (!res.ok){ alert(res.error||'復原失敗'); return; }
        alert('已復原'); openCase(CUR_CASE.id);
    }, 'json');
});
$('#btnDeletedList').on('click', function(){
    $.getJSON(API, {action:'case_deleted_list'}, function(res){
        if (!res.ok){ alert(res.error||'載入失敗'); return; }
        var h = '';
        (res.cases||[]).forEach(function(c){
            var log = c.delete_log || {};
            h += '<tr><td>'+esc(c.title||c.template_name)+'</td><td>'+esc(c.template_name)+'</td><td>'+esc(log.deleted_by_name||'')+'</td>'
               + '<td>'+dispDateTime(log.deleted_at)+'</td><td>'+esc(log.prior_status||'')+'</td>'
               + '<td><button onclick="restoreFromList('+c.id+')">復原</button></td></tr>';
        });
        $('#deletedBody').html(h || '<tr><td colspan="6" style="text-align:center;color:#8a6d45;">尚無已刪除案件</td></tr>');
        openMask('deletedMask');
    });
});
function restoreFromList(id){
    $.post(API, {action:'case_restore', csrf:META.csrf, case_id:id}, function(res){
        if (!res.ok){ alert(res.error||'復原失敗'); return; }
        $('#btnDeletedList').click();
        loadCases();
    }, 'json');
}
/* ============================================================ 填表人 ============================================================
   填表人＝這張表單「實際上是誰填的」，也是簽核來源選「填表人」時圖章要蓋的人。
   2026-08-19 使用者要求改為**預設未選定**（以前一律自動帶成建立者，管理員代建案件時章就蓋到管理員），
   並在**有框「填表人圖章欄位」的案件送出前強制補選**（後端 fsd_case_submit 同步擋，前端只是先講清楚）。 */
var CUR_FILLER_STAMPED = 0, FP_NEEDS_FILLER = false, FP_CAN_SET_FILLER = false, FILLER_CTX = 'dtl';

/** 框選畫面上的填表人狀態列：未選定且這張案件用得到填表人圖章時，紅字提醒（送出會被擋）。 */
function renderFpFiller(){
    if (!FP_CASE || FP_BACKFILL){ $('#fpFillerInfo').hide(); $('#btnFpSetFiller').hide(); return; }
    var has = !!FP_CASE.filler_id;
    $('#fpFillerInfo').show().toggleClass('unset', !has).text(
        has ? ('填表人：' + (FP_CASE.filler_name || ''))
            : (FP_NEEDS_FILLER ? '填表人：未選定（本案件有填表人圖章欄位，未指定不能送出）' : '填表人：未選定'));
    $('#btnFpSetFiller').toggle(FP_CAN_SET_FILLER);
}
/** ctx='fp' 改草稿（框選畫面）／'dtl' 改已送出的案件（詳情頁，僅超管）。 */
function openEditFiller(ctx){
    FILLER_CTX = (ctx === 'fp') ? 'fp' : 'dtl';
    var c = (FILLER_CTX === 'fp') ? FP_CASE : CUR_CASE;
    if (!c) return;
    var opts = '<option value="">（未選定）</option>' + (META.people||[]).map(function(p){
        return '<option value="'+p.id+'"'+(String(p.id)===String(c.filler_id)?' selected':'')+'>'+esc(p.display)+'</option>';
    }).join('');
    $('#fillerSel').html(opts);
    openMask('fillerMask');
}
function submitEditFiller(){
    var c = (FILLER_CTX === 'fp') ? FP_CASE : CUR_CASE;
    if (!c) return;
    var fid = $('#fillerSel').val();
    if (!fid){ alert('請選擇填表人'); return; }
    if (String(fid) === String(c.filler_id)){ closeMask('fillerMask'); return; }
    // 已經蓋過填表人章才需要確認（使用者拍板：自動換，但要先跳確認說明會動到什麼）
    var stamped = (FILLER_CTX === 'fp') ? 0 : CUR_FILLER_STAMPED;
    if (stamped > 0) {
        var who = $('#fillerSel option:selected').text();
        if (!confirm('這張案件已經蓋了 ' + stamped + ' 個「填表人」的圖章，目前蓋的是「' + (c.filler_name||'') + '」。\n\n'
            + '儲存後文件上這 ' + stamped + ' 個章會全部改成「' + who + '」，已產生的合成 PDF 也會作廢並在下次開啟時重新產生。\n\n'
            + '確定要更換嗎？')) return;
    }
    $.post(API, {action:'case_set_filler', csrf:META.csrf, case_id:c.id, filler_id:fid}, function(res){
        if (!res.ok){ alert(res.error||'設定失敗'); return; }
        closeMask('fillerMask');
        if (FILLER_CTX === 'fp'){
            FP_CASE.filler_id = res.filler_id; FP_CASE.filler_name = res.filler_name;
            renderFpFiller();
        } else {
            openCase(c.id);
        }
    }, 'json');
}
function submitAdvisory(decision){
    $.post(API, {action:'advisory_respond', csrf:META.csrf, case_id:CUR_CASE.id, decision:decision, reply_text:$.trim($('#advReply').val())}, function(res){
        if (!res.ok){ alert(res.error||'回應失敗'); return; }
        openCase(CUR_CASE.id);
    }, 'json');
}
function submitDecision(decision){
    var note = $.trim($('#decNote').val());
    if (decision === 'rejected' && !note){ alert('駁回必須填寫原因'); return; }
    $.post(API, {action:'decision_respond', csrf:META.csrf, case_id:CUR_CASE.id, decision:decision, note:note}, function(res){
        if (!res.ok){ alert(res.error||'決策失敗'); return; }
        openCase(CUR_CASE.id);
    }, 'json');
}
/** 「系統自動簽核」標記：**只有管理員看得到**（後端 fsd_sanitize_responses_for_viewer() 已把
 *  非管理員的 is_auto 整個拿掉），而且只出現在這份簽核紀錄清單裡——文件本身、列印版與匯出的 PDF
 *  一律不顯示，維持 2026-08-14 使用者定下的口徑：對外看起來與真人簽核無異，但內部查得到。 */
function autoSignTag(r){
    if (!r || !r.is_auto) return '';
    return ' <span class="auto-sign-tag" title="這一筆是系統自動產生的簽核紀錄，非本人當場操作。僅管理員看得到此標記。">系統自動簽核</span>';
}
function renderResponses(){
    if (CUR_CASE && CUR_CASE.case_kind === 'backfill') {
        // 補案件沒有關卡，紀錄就是「哪個位置蓋了誰的章」；日期一律案件業務日期
        var hb = '<div class="r-row"><b>補案件（管理員補登紙本，固定自動審核）</b>｜建立人：'+esc(CUR_CASE.applicant_name||'')+'｜業務日期：'+dispDate(CUR_CASE.business_date)+'</div>';
        var bfBySlot = {};
        (CUR_RESPONSES||[]).forEach(function(r){ bfBySlot[r.slot_key] = r; });
        (CUR_FIELDS||[]).forEach(function(f, i){
            hb += '<div class="r-row" style="padding-left:10px;">圖章 '+(i+1)+'（第'+f.page_no+'頁）：'+esc(f.signer_name||'（未指定）')
                + (f.stamp_tpl && f.stamp_tpl.tpl_name ? '｜模板：'+esc(f.stamp_tpl.tpl_name) : '｜模板：系統預設回墨印')
                + autoSignTag(bfBySlot[f.slot_key]) + '</div>';
        });
        $('#respList').html(hb);
        return;
    }
    var stages = CUR_SCHEMA.stages || [];
    var bySlot = {};
    (CUR_RESPONSES||[]).forEach(function(r){ bySlot[r.slot_key] = r; });
    var h = '';
    stages.forEach(function(s){
        h += '<div class="r-row"><b>第'+s.seq+'關｜'+esc(s.name)+'</b>（'+(s.stage_type==='advisory'?'意見階段':'決策階段')+'）</div>';
        (s.signers||[]).forEach(function(sg){
            var r = bySlot[sg.slot_key];
            var who = sg.label || '（'+({user:'固定人員',dept_auto_manager:'部門自動主管',submitter_supervisor:'上一階主管',top_approver:'最高決策者'}[sg.mode]||sg.mode)+'）';
            var txt;
            if (!r) txt = '<span style="color:#8a6d45;">尚未開始</span>';
            else if (r.decision === 'skipped_sod') txt = '<span class="sod-note">（本人迴避,自動略過）</span>';
            else if (r.decision === null) txt = '<span style="color:#b5762a;">待回應（'+esc(r.resolved_user_name||'')+'）</span>';
            else {
                var decLabel = {agree:'同意', disagree:'不同意', approved:'核准', rejected:'駁回'}[r.decision] || r.decision;
                txt = esc(r.resolved_user_name||'') + '｜' + decLabel + (r.reply_text ? '｜'+esc(r.reply_text) : '') + '｜' + dispDateTime(r.responded_at) + autoSignTag(r);
            }
            h += '<div class="r-row" style="padding-left:10px;">'+esc(who)+'：'+txt+'</div>';
        });
    });
    $('#respList').html(h || '<span style="color:#8a6d45;">（無資料）</span>');
}
/* ============================================================ 圖章/回覆內容：畫面疊圖層與匯出PDF的共用判斷 ============================================================ */
/** 樣板綁定的圖章模板 schema：本地覆蓋 noScale:true＝所見即所印，不縮小（使用者明確要求）；
 *  不動 DB 裡模板本身的設定（同一個模板可能被其他頁面用 fillRatio 縮放模式消費，不能共用同一份物件改）。 */
function fsdCaseStampSchema(){
    if (CUR_SCHEMA && CUR_SCHEMA.stamp_tpl && CUR_SCHEMA.stamp_tpl.schema) return $.extend({}, CUR_SCHEMA.stamp_tpl.schema, {noScale:true});
    return null;
}
/** 決定某個框現在該顯示什麼。畫面疊圖層(paintOverlay)與匯出PDF(fsdBuildOverlay)一律走這裡，不各寫一套——
 *  兩邊規則一旦走鐘，就會變成「畫面看到的」跟「PDF 印出來的」不一樣。
 *  回傳 {kind:'stamp',html} / {kind:'text',text} / {kind:'sod',text} / null（這個框現在沒東西） */
function fsdBoxContent(f, r){
    if (f.box_type === 'stamp' && CUR_CASE && CUR_CASE.case_kind === 'backfill'){
        // 補案件：每個章各自帶人員與自己的圖章模板；日期一律用案件業務日期(2026-08-17使用者拍板)
        var bfSchema = (f.stamp_tpl && f.stamp_tpl.schema) ? $.extend({}, f.stamp_tpl.schema, {noScale:true}) : null;
        if (f.signer_name && window.EGStamp) return {kind:'stamp', html:EGStamp.stamp(f.signer_name, dispDate(CUR_CASE.business_date), false, bfSchema)};
        return null;
    }
    if (f.box_type === 'stamp'){
        // 圖章日期一律走 dispDate() 顯示成 YYYY.MM.DD(ai-rules/20)，不可直接丟原始的 YYYY-MM-DD
        if (r && r.decision && r.decision !== 'skipped_sod' && window.EGStamp)
            return {kind:'stamp', html:EGStamp.stamp(r.resolved_user_name, dispDate((r.responded_at||'').substring(0,10)), false, fsdCaseStampSchema())};
        if (r && r.decision === 'skipped_sod') return {kind:'sod', text:'（迴避）'};
        return null;
    }
    if (r && r.decision && r.decision !== 'skipped_sod'){
        var decLabel = {agree:'同意', disagree:'不同意', approved:'核准', rejected:'駁回'}[r.decision] || r.decision;
        return {kind:'text', text:'【'+decLabel+'】'+(r.reply_text||'')};
    }
    return null;
}

/* ============================================================ 匯出合成 PDF ============================================================
   案件完成時自動產生一份定版 PDF 存進 NAS，列表可重複開啟列印與下載（2026-08-19 使用者要求）。
   【為什麼圖章要在瀏覽器這邊轉成 PNG 再送後端】章長什麼樣子是 eg_stamp.js / eg_stamp_tpl.js 在瀏覽器算出來的
   （含掃描實體章、圖章模板、代理「代」字），後端沒有同一套渲染器；要嘛在 PHP 重寫一份（兩邊遲早畫得不一樣），
   要嘛把畫面上算好的章原樣送過去。選後者，所以「畫面看到的章」＝「PDF 裡的章」。
   【畫質】章以 450dpi 等效解析度轉 PNG；文件本體則完全不經過轉圖——後端用 FPDI 直接匯入原始 PDF 頁面
   （實測影像串流位元組 100% 原封不動搬過去），回覆文字也不轉圖，交給 TCPDF 畫成向量文字。
   先前「PDF 比自己轉圖再上傳還糊」的根因是把整頁重畫成點陣圖，這條路徑完全不做那件事。 */
var FSD_STAMP_DPI = 450, FSD_STAMP_MAX_PX = 1600, FSD_EXPORTING = false;

/** 章列印時的實際尺寸(px)：未綁定圖章模板的回墨印/掃描章一律 91px（ai-rules/18 第6條，@media print 也是這個值）；
 *  有綁定模板則用模板自己設定的大小。跟列印版同一套口徑，PDF 裡的章大小才會跟印出來的一致。 */
function fsdStampPxSize($svg){
    if ($svg.hasClass('car-stamp')) return {w:91, h:91};
    var w = parseFloat($svg.attr('width')), h = parseFloat($svg.attr('height'));
    if (!w || !h) return {w:91, h:91};
    return {w:w, h:h};
}
/** 掃描實體章的底圖是 <image href="API?action=asset_img...">，SVG 被塞進 <img> 渲染時不會去載外部資源，
 *  所以轉圖前一定要先換成 data: URI，否則畫出來會是一張沒有底圖的空章。 */
function fsdInlineSvgImages(svgEl){
    var nodes = svgEl.querySelectorAll('image');
    if (!nodes.length) return Promise.resolve();
    var jobs = [];
    for (var i = 0; i < nodes.length; i++) {
        (function(node){
            var href = node.getAttribute('href') || node.getAttribute('xlink:href') || '';
            if (!href || /^data:/i.test(href)) return;
            jobs.push(fetch(href, {credentials:'same-origin'}).then(function(rp){ return rp.blob(); }).then(function(b){
                return new Promise(function(res){
                    var fr = new FileReader();
                    fr.onload = function(){
                        node.setAttribute('href', fr.result);
                        node.setAttributeNS('http://www.w3.org/1999/xlink', 'xlink:href', fr.result);
                        res();
                    };
                    fr.onerror = function(){ res(); };
                    fr.readAsDataURL(b);
                });
            }).catch(function(){}));
        })(nodes[i]);
    }
    return Promise.all(jobs);
}
/** 單一個章：SVG → 去背 PNG dataURL（畫布不鋪白，章必須透明才不會蓋掉底下的文件內容）。失敗回 null。 */
function fsdStampToPng(svgEl, pxW, pxH){
    return fsdInlineSvgImages(svgEl).then(function(){
        svgEl.setAttribute('width', pxW);
        svgEl.setAttribute('height', pxH);
        if (!svgEl.getAttribute('xmlns')) svgEl.setAttribute('xmlns', 'http://www.w3.org/2000/svg');
        var url = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(new XMLSerializer().serializeToString(svgEl));
        return new Promise(function(resolve){
            var img = new Image();
            img.onload = function(){
                var cv = document.createElement('canvas'); cv.width = pxW; cv.height = pxH;
                cv.getContext('2d').drawImage(img, 0, 0, pxW, pxH);
                try { resolve(cv.toDataURL('image/png')); } catch(e){ resolve(null); }
            };
            img.onerror = function(){ resolve(null); };
            img.src = url;
        });
    });
}
/** 蒐集整份案件要疊上去的東西 → {stamps:[{page_no,x,y,w,h,png}], texts:[{page_no,x,y,w,h,text}]}
 *  座標一律換算成「相對該頁的 0~1 分數」，跟畫面疊圖層用的是同一組數字。 */
function fsdBuildOverlay(){
    var bySlot = {};
    (CUR_RESPONSES||[]).forEach(function(r){ bySlot[r.slot_key] = r; });
    var pageById = {};
    (CUR_CASE_PAGES||[]).forEach(function(p){ pageById[p.page_no] = p; });
    var texts = [], jobs = [];
    (CUR_FIELDS||[]).forEach(function(f){
        var c = fsdBoxContent(f, bySlot[f.slot_key]);
        if (!c) return;
        var box = {page_no:parseInt(f.page_no,10), x:parseFloat(f.x), y:parseFloat(f.y), w:parseFloat(f.w), h:parseFloat(f.h)};
        if (c.kind !== 'stamp'){ texts.push($.extend({text:c.text}, box)); return; }
        var $svg = $('<div>').html(c.html).find('svg').first();
        if (!$svg.length) return;
        var p = pageById[box.page_no] || {width_pt:595, height_pt:842};
        var docWmm = parseFloat(p.width_pt)/72*25.4, docHmm = parseFloat(p.height_pt)/72*25.4;
        // 章不是拉滿整個框：畫面上 .fsd-box.stamp 是 flex 置中、列印用固定實際大小，這裡照同一套規則
        // 換算出「章自己的矩形」（在框內置中），送給後端的是章的位置與大小，不是框的
        var px = fsdStampPxSize($svg);
        var wFrac = Math.min(1, (px.w/96*25.4) / docWmm), hFrac = Math.min(1, (px.h/96*25.4) / docHmm);
        var rect = {page_no:box.page_no,
                    x:Math.max(0, Math.min(1-wFrac, box.x + (box.w - wFrac)/2)),
                    y:Math.max(0, Math.min(1-hFrac, box.y + (box.h - hFrac)/2)),
                    w:wFrac, h:hFrac};
        var pxW = Math.max(40, Math.min(FSD_STAMP_MAX_PX, Math.round(rect.w*docWmm/25.4*FSD_STAMP_DPI)));
        var pxH = Math.max(40, Math.min(FSD_STAMP_MAX_PX, Math.round(rect.h*docHmm/25.4*FSD_STAMP_DPI)));
        jobs.push({rect:rect, svg:$svg[0], pxW:pxW, pxH:pxH});
    });
    var stamps = [];
    var chain = Promise.resolve();
    jobs.forEach(function(j){
        chain = chain.then(function(){
            return fsdStampToPng(j.svg, j.pxW, j.pxH).then(function(png){
                if (png) stamps.push($.extend({png:png}, j.rect));
            });
        });
    });
    return chain.then(function(){ return {stamps:stamps, texts:texts}; });
}
/** 產生並存檔。silent=true 為背景自動產生（失敗不吵使用者，只留 console，之後開啟 PDF 時會再試一次）。 */
function fsdExportPdf(caseId, silent){
    if (FSD_EXPORTING) return Promise.resolve(false);
    FSD_EXPORTING = true;
    return new Promise(function(resolve){
        // 掃描實體章的對照表是非同步載入的，沒等到就轉圖會把有實體章的人存成預設SVG章（跟畫面看到的不一樣）
        EGStamp.whenReady(function(){
            fsdBuildOverlay().then(function(ov){
                return $.post(API, {action:'case_export_pdf', csrf:META.csrf, case_id:caseId,
                                    stamps:JSON.stringify(ov.stamps), texts:JSON.stringify(ov.texts)}, null, 'json');
            }).then(function(res){
                FSD_EXPORTING = false;
                if (res && res.ok){
                    if (CUR_CASE && String(CUR_CASE.id) === String(caseId)){ CUR_CASE.export_pdf_name = res.file_name; renderPdfButtons(); }
                    resolve(true);
                } else { if (!silent) alert((res && res.error) || 'PDF 產生失敗'); resolve(false); }
            }).catch(function(e){
                FSD_EXPORTING = false;
                if (!silent) alert('PDF 產生失敗：' + (e && e.message ? e.message : '連線錯誤'));
                else if (window.console) console.warn('[fsd] 背景產生 PDF 失敗', e);
                resolve(false);
            });
        });
    });
}
/** 開啟（瀏覽器內建檢視器，可直接按列印）／下載已存檔的合成PDF；還沒產生就先產生再開。 */
function fsdOpenPdf(dl){
    if (!CUR_CASE) return;
    var id = CUR_CASE.id;
    var go = function(){ window.open(API + '?action=case_export_file&id=' + id + (dl ? '&dl=1' : ''), '_blank'); };
    if (CUR_CASE.export_pdf_name){ go(); return; }
    $('#btnPdfOpen,#btnPdfDl').prop('disabled', true);
    fsdExportPdf(id, false).then(function(ok){
        $('#btnPdfOpen,#btnPdfDl').prop('disabled', false);
        if (ok) go();
    });
}
/** PDF 按鈕只在「已完成」的案件上出現（2026-08-19 使用者拍板：未簽完不可匯出，避免半成品被當成正式文件） */
function renderPdfButtons(){
    $('#btnPdfOpen,#btnPdfDl').toggle(!!(CUR_CASE && CUR_CASE.status === 'approved'));
}
function renderDocGrid(pages, fields){
    var bySlot = {};
    (CUR_RESPONSES||[]).forEach(function(r){ bySlot[r.slot_key] = r; });
    var lands = isLandscapeDoc(pages);
    var h = '';
    pages.forEach(function(p){
        h += '<div class="fsd-doc-page'+(lands?' landscape':'')+'" id="docpg_'+p.page_no+'" data-w="'+p.width_pt+'" data-h="'+p.height_pt+'"'
           + ' style="aspect-ratio:'+p.width_pt+'/'+p.height_pt+';"></div>';
    });
    $('#docGrid').html(h);
    var fileType = CUR_CASE.file_type || 'image';
    var fileUrl = caseDocUrl(CUR_CASE, fileType);
    function paintOverlay(pageNo){
        var $pg = $('#docpg_'+pageNo);
        fields.filter(function(f){ return f.page_no == pageNo; }).forEach(function(f){
            var $box = $('<div class="fsd-box '+f.box_type+'"></div>').css({left:(f.x*100)+'%', top:(f.y*100)+'%', width:(f.w*100)+'%', height:(f.h*100)+'%'});
            var c = fsdBoxContent(f, bySlot[f.slot_key]);
            if (c && c.kind === 'stamp') $box.html(c.html);
            else if (c && c.kind === 'sod') $box.html('<span class="sod-note">'+esc(c.text)+'</span>');
            else if (c && c.kind === 'text') $box.text(c.text);
            $pg.append($box);
        });
    }
    renderDocPages(fileType, fileUrl, pages, function(pageNo, src){
        $('#docpg_'+pageNo).prepend('<img src="'+src+'">');
        paintOverlay(pageNo);
    });
}
/** 列印：依文件直橫式動態插入@page size；並依可印範圍(A4扣邊界)幫每一頁算出明確mm尺寸蓋掉aspect-ratio，
 *  保證單頁一定裝得下、不會被瀏覽器整頁shrink-to-fit縮放(那正是先前多空白頁/圖章列印跟畫面大小不一致的根因)；
 *  AS文件編號比照ai-rules/16釘在頁面右下角，同一次列印工作只對應同一份文件，position:fixed安全無虞。 */
function doPrint(){
    // 一定要用案件自己的頁面(CUR_CASE_PAGES)，不能用CUR_SCHEMA.pages(那是樣板參考頁，尺寸/頁數
    // 跟案件實際上傳的文件通常對不上——先前這裡誤用樣板頁面算版面，正是「明明只傳1頁卻印出2張紙」的根因)。
    var pages = CUR_CASE_PAGES || [];
    var lands = isLandscapeDoc(pages);
    var pageWmm = lands ? 297 : 210, pageHmm = lands ? 210 : 297;
    var marginLR = 8, marginTB = 10;
    // 0.97安全係數：吸收版型本身可能殘留的微量padding等未預期偏移，避免算出來剛好貼邊反而又溢出一點點
    var printW = (pageWmm - marginLR*2) * 0.97, printH = (pageHmm - marginTB*2) * 0.97;
    pages.forEach(function(p){
        var srcWmm = parseFloat(p.width_pt)/72*25.4, srcHmm = parseFloat(p.height_pt)/72*25.4;
        var scale = Math.min(printW/srcWmm, printH/srcHmm, 1);
        $('#docpg_'+p.page_no).css({width:(srcWmm*scale)+'mm', height:(srcHmm*scale)+'mm', margin:'0 auto'});
    });
    $('#fsdPrintCss').remove();
    // 頁碼左下角(多頁才顯示)+AS文件編號右下角，比照ai-rules/16第二、三節全站列印標準
    var pageCounterCss = pages.length > 1 ? " @bottom-left{ content:'第 ' counter(page) ' 頁／共 ' counter(pages) ' 頁'; font-size:9pt; color:#333; }" : '';
    $('<style id="fsdPrintCss">@media print{ @page{ size:A4 '+(lands?'landscape':'portrait')+'; margin:10mm 8mm;'+pageCounterCss+' } }</style>').appendTo('head');
    $('#printAsDocFoot').remove();
    if (CUR_AS_DOC_NO) $('<div id="printAsDocFoot" class="pt-asdoc"></div>').text(CUR_AS_DOC_NO).appendTo('#detailPanel');
    function restore(){
        pages.forEach(function(p){ $('#docpg_'+p.page_no).css({width:'', height:'', margin:''}); });
        $('#printAsDocFoot').remove();
        window.removeEventListener('afterprint', restore);
    }
    window.addEventListener('afterprint', restore);
    setTimeout(function(){ window.print(); }, 50);
}

/* ============================================================ 草稿框選畫面（案件自己上傳的文件，白名單來自樣板） ============================================================ */
function openFieldDesigner(id){
    $.getJSON(API, {action:'case_get', id:id}, function(res){
        if (!res.ok){ alert(res.error||'載入失敗'); return; }
        if (!res.can_edit_fields){ alert('此案件不可編輯（可能已送出或無權限）'); return; }
        FP_CASE = res.case; FP_TPL_SCHEMA = res.schema; FP_WHITELIST = res.field_whitelist || [];
        // 頁面清單是 API 另外回傳的 res.pages，不在 res.case 裡；一定要掛上去，
        // 否則 buildFpCanvases()/fpRotatePage()/openCropModal()/bfAddStamp() 讀 FP_CASE.pages 全都會落空
        // （先前多圖案件在框選畫面只畫得出一頁 595x842 的預設頁、補案件按新增圖章會說「找不到第1頁」，都是這個原因）
        FP_CASE.pages = res.pages || [];
        FP_BACKFILL = FP_CASE.case_kind === 'backfill';
        $('#listPanel,#detailPanel').hide(); $('#fieldPanel').show();
        $('#fpTitle').text(FP_CASE.title || '');
        FP_NEEDS_FILLER = !!res.needs_filler;
        FP_CAN_SET_FILLER = !!res.can_set_filler;
        renderFpFiller();
        // 補案件沒有樣板：關掉樣板參考與待框選標籤，改用圖章清單面板
        $('#refPanel').toggle(!FP_BACKFILL);
        $('#labelPanel').toggle(!FP_BACKFILL);
        $('#bfStampPanel').toggle(FP_BACKFILL);
        $('#btnBfEditHead').toggle(FP_BACKFILL);
        $('#btnFpSubmit').html(FP_BACKFILL ? '<i class="fa fa-check"></i> 儲存並完成（自動審核）' : '<i class="fa fa-check"></i> 儲存並送出');
        if (FP_BACKFILL) $('#fpHintText').text('在每一頁上方按「＋新增圖章」，再把章拖到紙本原本蓋章的位置。圖章大小固定＝該章圖章模板的實際尺寸（所見即所印），只能移動位置、不能拉大縮小；要改大小請改用別的圖章模板。');
        else $('#fpHintText').text('把左側「待框選標籤」拖到您上傳的文件對應位置；只能框選樣板本身已框選過的欄位（樣板沒有的欄位這裡也不會出現）。');
        if (FP_BACKFILL) {
            bfEnsureMeta(function(){
                BF_FIELDS = res.fields || []; BF_OBJS = {};
                // 預設模板：沿用目前圖章已經在用的那一個(回來繼續設定時不會被洗掉)，都沒有才空白
                var pre = 0;
                BF_FIELDS.forEach(function(f){ if (!pre && f.stamp_tpl_id) pre = f.stamp_tpl_id; });
                $('#bfDefaultTpl').html(bfTplOptionsHtml(pre));
                buildFpCanvases(BF_FIELDS);
            });
            return;
        }
        buildSlotColorMap();
        renderRefPanel();
        if (!res.pages || !res.pages.length) {
            measureAndSaveCasePages(function(){ buildFpCanvases(res.fields||[]); });
        } else {
            buildFpCanvases(res.fields||[]);
        }
    });
}
$('#btnFieldBack').on('click', function(){ $('#fieldPanel').hide(); $('#listPanel').show(); FP_CASE=null; loadCases(); });
function openReplaceFile(){ $('#rpFile').val(''); RP_FILES=[]; renderRpThumbs(); openMask('replaceMask'); }
var RP_FILES = [];
function rpFilesChanged(fileList){ var f = fsdCheckFiles(fileList); if (f === null){ $('#rpFile').val(''); return; } RP_FILES = f; renderRpThumbs(); }
function rpRemoveThumb(i){ RP_FILES.splice(i,1); renderRpThumbs(); }
function renderRpThumbs(){
    var h = '';
    RP_FILES.forEach(function(f, i){
        h += '<div class="thumb" draggable="true" data-idx="'+i+'">'+fsdThumbInner(f)
           + '<span class="tno">'+(i+1)+'</span><span class="tdel" onclick="rpRemoveThumb('+i+')">×</span></div>';
    });
    var $g = $('#rpThumbs').html(h);
    var dragIdx = null;
    $g.find('.thumb').on('dragstart', function(){ dragIdx = $(this).data('idx'); });
    $g.find('.thumb').on('dragover', function(e){ e.preventDefault(); $(this).addClass('dragover'); });
    $g.find('.thumb').on('dragleave', function(){ $(this).removeClass('dragover'); });
    $g.find('.thumb').on('drop', function(e){
        e.preventDefault(); $(this).removeClass('dragover');
        var dropIdx = $(this).data('idx');
        if (dragIdx === null || dragIdx === dropIdx) return;
        var moved = RP_FILES.splice(dragIdx, 1)[0];
        RP_FILES.splice(dropIdx, 0, moved);
        renderRpThumbs();
    });
}
function submitReplaceFile(){
    if (!RP_FILES.length){ alert('請選擇新文件（圖片或 PDF）'); return; }
    var fd = new FormData();
    fd.append('action','case_replace_file'); fd.append('csrf', META.csrf); fd.append('case_id', FP_CASE.id);
    RP_FILES.forEach(function(f){ fd.append('files[]', f); });
    fetch(API, {method:'POST', body:fd}).then(function(r){ return r.json(); }).then(function(res){
        if (!res.ok){ alert(res.error||'更換失敗'); return; }
        closeMask('replaceMask'); RP_FILES=[]; renderRpThumbs();
        openFieldDesigner(FP_CASE.id);
    }).catch(function(){ alert('更換失敗（連線錯誤）'); });
}
/* 圖章框最小尺寸：有給圖章模板 schema 就照該模板的實際大小換算(跟後端 fsd_field_min_frac 同一套算法)，
   沒給就沿用全站列印 91px 標準。既有呼叫端只傳 page(不傳 schema)，行為與先前完全相同。 */
function fieldMinFrac(page, stampSchema){
    var mmW, mmH;
    if (stampSchema && stampSchema.size) {
        var sizePx = Math.min(600, Math.max(24, parseFloat(stampSchema.size)||91));
        var ratio  = Math.min(3, Math.max(0.3, parseFloat(stampSchema.ratio)||1));
        mmW = sizePx/96*25.4; mmH = sizePx*ratio/96*25.4;
    } else { mmW = mmH = 91/96*25.4; }
    var widthMm = (page.width_pt||0)/72*25.4, heightMm = (page.height_pt||0)/72*25.4;
    return {min_w: widthMm>0 ? mmW/widthMm : 0.05, min_h: heightMm>0 ? mmH/heightMm : 0.05};
}
function measureAndSaveCasePages(done){
    var fileUrl = API + '?action=case_file&id=' + FP_CASE.id;
    if (FP_CASE.file_type === 'pdf') {
        ensurePdfJs().then(function(lib){ return lib.getDocument({url:fileUrl, withCredentials:true}).promise; }).then(function(doc){
            var pages = []; var i = 1;
            function next(){
                if (i > doc.numPages) {
                    $.post(API, {action:'case_pages_save', csrf:META.csrf, case_id:FP_CASE.id, pages:JSON.stringify(pages)}, function(res){
                        if (!res.ok){ alert(res.error||'頁面量測儲存失敗'); return; }
                        FP_CASE.pages = res.pages; if (done) done(res.pages);
                    }, 'json');
                    return;
                }
                doc.getPage(i).then(function(page){
                    var vp = page.getViewport({scale:1});
                    pages.push({page_no:i, width_pt:vp.width, height_pt:vp.height});
                    i++; next();
                });
            }
            next();
        }).catch(function(e){ alert('PDF讀取失敗：'+(e.message||e)); });
    } else {
        var img = new Image();
        img.onload = function(){
            var widthPt = img.naturalWidth/96*72, heightPt = img.naturalHeight/96*72;
            $.post(API, {action:'case_pages_save', csrf:META.csrf, case_id:FP_CASE.id, pages:JSON.stringify([{page_no:1, width_pt:widthPt, height_pt:heightPt}])}, function(res){
                if (!res.ok){ alert(res.error||'頁面量測儲存失敗'); return; }
                FP_CASE.pages = res.pages; if (done) done(res.pages);
            }, 'json');
        };
        img.onerror = function(){ alert('圖片讀取失敗'); };
        img.src = fileUrl;
    }
}
/* 待框選標籤跟樣板參考縮圖用同一套顏色對應同一個槽位(slot_key)，方便肉眼比對「這個標籤=參考圖裡哪一個框」
   (2026-08-14使用者明確要求)。暖色系調色盤(ai-rules/10)，依樣板stages/signers出現順序依序指派、循環使用。 */
var SLOT_COLORS = ['#F0A24B','#DD5138','#C0782D','#8A5A2B','#D98A33','#B5762A','#E8A33D','#A0662E','#CC7722','#996633'];
var SLOT_COLOR_MAP = {};
function buildSlotColorMap(){
    SLOT_COLOR_MAP = {};
    var i = 0;
    (FP_TPL_SCHEMA.stages||[]).forEach(function(s){
        (s.signers||[]).forEach(function(sg){ SLOT_COLOR_MAP[sg.slot_key] = SLOT_COLORS[i % SLOT_COLORS.length]; i++; });
    });
}
function slotColor(slotKey){ return SLOT_COLOR_MAP[slotKey] || '#F0A24B'; }
function renderRefPanel(){
    var refPages = FP_TPL_SCHEMA.pages || [];
    var refFields = FP_TPL_SCHEMA.fields || [];
    var h = '';
    refPages.forEach(function(p){ h += '<div class="fsd-ref-page" id="refpg_'+p.page_no+'" style="aspect-ratio:'+p.width_pt+'/'+p.height_pt+';"><div class="pno" style="font-size:10px;color:#8a6d45;">第'+p.page_no+'頁</div></div>'; });
    $('#refPages').html(h || '<p style="font-size:11px;color:#8a6d45;">（樣板尚無參考位置）</p>');
    var fileUrl = API + '?action=template_file&id=' + FP_CASE.template_id;
    renderDocPages(FP_TPL_SCHEMA.file && FP_TPL_SCHEMA.file.file_type === 'pdf' ? 'pdf' : 'image', fileUrl, refPages, function(pageNo, src){
        var $pg = $('#refpg_'+pageNo);
        $pg.prepend('<img src="'+src+'">');
        refFields.filter(function(f){ return f.page_no == pageNo; }).forEach(function(f){
            var c = slotColor(f.slot_key);
            $('<div class="fsd-ref-box '+f.box_type+'"></div>').css({left:(f.x*100)+'%', top:(f.y*100)+'%', width:(f.w*100)+'%', height:(f.h*100)+'%',
                borderColor:c, backgroundColor:c+'2e', color:c})
                .text(f.box_type==='stamp'?'章':'覆').appendTo($pg);
        });
    });
}
function fpLabelText(slotKey, boxType){
    var stages = FP_TPL_SCHEMA.stages || [];
    for (var i=0;i<stages.length;i++) for (var j=0;j<(stages[i].signers||[]).length;j++) {
        if (stages[i].signers[j].slot_key === slotKey) {
            var sg = stages[i].signers[j];
            return '第'+stages[i].seq+'關-'+(sg.label||sg.mode)+'('+(boxType==='stamp'?'圖章框':'回覆框')+')';
        }
    }
    return slotKey + '(' + boxType + ')';
}
function renderFpLabelList(placedKeys){
    var h = '';
    FP_WHITELIST.forEach(function(key){
        var us = key.lastIndexOf('_');
        var slotKey = key.substring(0,us), boxType = key.substring(us+1);
        var isPlaced = placedKeys.indexOf(key) > -1;
        var c = slotColor(slotKey);
        h += '<div class="fsd-label type-'+boxType+'" draggable="true" data-slot="'+slotKey+'" data-boxtype="'+boxType+'" style="border-color:'+c+';background:'+c+'22;">'+esc(fpLabelText(slotKey,boxType))
           + (isPlaced ? '<span class="placed"><i class="fa fa-check"></i></span>' : '') + '</div>';
    });
    $('#fpLabelList').html(h || '<p style="padding:8px;color:#8a6d45;font-size:12px;">樣板尚未框選任何欄位，請聯絡管理員先在樣板設計頁完成框選</p>');
}
$(document).on('dragstart', '#fpLabelList .fsd-label', function(e){
    var data = JSON.stringify({slotKey:$(this).data('slot'), boxType:$(this).data('boxtype')});
    e.originalEvent.dataTransfer.setData('text/plain', data);
});
/** 旋轉該頁90度(修正掃描歪斜方向)：交換有效寬高、清空該頁既有框選(座標系已變)、存檔後整個重繪。 */
function fpRotatePage(pageNo){
    var p = (FP_CASE.pages||[]).filter(function(x){ return x.page_no==pageNo; })[0];
    if (!p){ alert('找不到第'+pageNo+'頁的資料'); return; }
    if (!confirm('旋轉這一頁會清空此頁已框選的位置(白名單標籤會變回未框選)，確定要旋轉嗎？')) return;
    var newRotation = ((p.rotation||0) + 90) % 360;
    var newWidthPt = p.height_pt, newHeightPt = p.width_pt;
    $.post(API, {action:'case_field_delete_page', csrf:META.csrf, case_id:FP_CASE.id, page_no:pageNo}, function(res0){
        if (!res0.ok){ alert(res0.error||'清空框選失敗'); return; }
        p.rotation = newRotation; p.width_pt = newWidthPt; p.height_pt = newHeightPt;
        $.post(API, {action:'case_pages_save', csrf:META.csrf, case_id:FP_CASE.id, pages:JSON.stringify(FP_CASE.pages)}, function(res){
            if (!res.ok){ alert(res.error||'旋轉失敗'); return; }
            FP_CASE.pages = res.pages;
            buildFpCanvases([]);
        }, 'json').fail(function(xhr){ alert('旋轉失敗(連線錯誤 '+xhr.status+')：'+xhr.responseText); });
    }, 'json').fail(function(xhr){ alert('清空框選失敗(連線錯誤 '+xhr.status+')：'+xhr.responseText); });
}

/* -------- A4/A3裁切框：用固定比例的框框住文件實際內容範圍，取代直接信任原始像素量測出的寬高比 -------- */
var CROP_CANVAS = null, CROP_RECT = null, CROP_PAGE_NO = 0;
function cropRatio(){
    var r = 1/Math.SQRT2; // A4/A3同屬ISO 216 A系列,長寬比皆為1:根號2
    return $('#cropOrientation').val()==='landscape' ? 1/r : r;
}
function openCropModal(pageNo){
    var p = (FP_CASE.pages||[]).filter(function(x){ return x.page_no==pageNo; })[0];
    if (!p) return;
    CROP_PAGE_NO = pageNo;
    $('#cropPaperSize').val(p.paper_size || 'A4');
    $('#cropOrientation').val(parseFloat(p.width_pt) >= parseFloat(p.height_pt) ? 'landscape' : 'portrait');
    openMask('cropMask');
    var dispW = 600, dispH = Math.round(dispW * (p.height_pt / p.width_pt || 1.414));
    $('#cropCanvas').attr({width:dispW, height:dispH});
    if (CROP_CANVAS) { CROP_CANVAS.dispose(); CROP_CANVAS = null; }
    CROP_CANVAS = new fabric.Canvas('cropCanvas', {width:dispW, height:dispH, selection:false});
    renderDocPages(FP_CASE.file_type, caseDocUrl(FP_CASE, FP_CASE.file_type), [p], function(pageNo2, src){
        fabric.Image.fromURL(src, function(img){
            img.set({left:0, top:0, scaleX:dispW/img.width, scaleY:dispH/img.height, selectable:false, evented:false});
            CROP_CANVAS.setBackgroundImage(img, CROP_CANVAS.renderAll.bind(CROP_CANVAS));
            addCropRect();
        }, {crossOrigin:'anonymous'});
    });
}
function addCropRect(){
    if (CROP_RECT) { CROP_CANVAS.remove(CROP_RECT); CROP_RECT = null; }
    var cv = CROP_CANVAS, ratio = cropRatio();
    var w = cv.width * 0.8, h = w / ratio;
    if (h > cv.height * 0.9) { h = cv.height * 0.9; w = h * ratio; }
    var rect = new fabric.Rect({
        left:(cv.width-w)/2, top:(cv.height-h)/2, width:w, height:h,
        fill:'rgba(240,162,75,.15)', stroke:'#d98a33', strokeWidth:2, lockRotation:true, hasRotatingPoint:false,
    });
    rect.on('scaling', function(){
        var newW = rect.width * rect.scaleX;
        rect.set({scaleY: (newW/ratio) / rect.height});
    });
    cv.add(rect); cv.setActiveObject(rect); cv.renderAll();
    CROP_RECT = rect;
}
$('#cropPaperSize, #cropOrientation').on('change', function(){ if (CROP_CANVAS && CROP_RECT) addCropRect(); });
function confirmCrop(){
    if (!CROP_CANVAS || !CROP_RECT) return;
    if (!confirm('確定套用此裁切框？會清空這一頁已框選的位置。')) return;
    var cv = CROP_CANVAS, rect = CROP_RECT;
    var cx = rect.left / cv.width, cy = rect.top / cv.height;
    var cw = (rect.width * rect.scaleX) / cv.width, ch = (rect.height * rect.scaleY) / cv.height;
    var paper = $('#cropPaperSize').val(), orient = $('#cropOrientation').val();
    var mm = paper === 'A4' ? [210,297] : [297,420];
    var wMm = orient === 'landscape' ? Math.max(mm[0],mm[1]) : Math.min(mm[0],mm[1]);
    var hMm = orient === 'landscape' ? Math.min(mm[0],mm[1]) : Math.max(mm[0],mm[1]);
    var p = (FP_CASE.pages||[]).filter(function(x){ return x.page_no==CROP_PAGE_NO; })[0];
    p.paper_size = paper; p.crop_x = cx; p.crop_y = cy; p.crop_w = cw; p.crop_h = ch;
    p.width_pt = wMm/25.4*72; p.height_pt = hMm/25.4*72;
    $.post(API, {action:'case_field_delete_page', csrf:META.csrf, case_id:FP_CASE.id, page_no:CROP_PAGE_NO}, function(){
        $.post(API, {action:'case_pages_save', csrf:META.csrf, case_id:FP_CASE.id, pages:JSON.stringify(FP_CASE.pages)}, function(res){
            if (!res.ok){ alert(res.error||'裁切失敗'); return; }
            FP_CASE.pages = res.pages;
            closeMask('cropMask');
            buildFpCanvases([]);
        }, 'json');
    }, 'json');
}

function buildFpCanvases(existingFields){
    FP_CANVASES = {};
    var pages = FP_CASE.pages || [{page_no:1, width_pt:595, height_pt:842}];
    var h = '';
    pages.forEach(function(p){
        h += '<div class="fsd-page-wrap"><div class="pno">第 '+p.page_no+' 頁'
           + (p.paper_size ? ' <span style="color:#3f8a3f;">['+p.paper_size+'已裁切]</span>' : '')
           + ' <button type="button" onclick="fpRotatePage('+p.page_no+')" style="height:20px;font-size:11px;padding:0 6px;border:1px solid #D8BE93;background:#fff;border-radius:3px;cursor:pointer;"><i class="fa fa-rotate-right"></i> 旋轉90°</button>'
           + ' <button type="button" onclick="openCropModal('+p.page_no+')" style="height:20px;font-size:11px;padding:0 6px;border:1px solid #D8BE93;background:#fff;border-radius:3px;cursor:pointer;"><i class="fa fa-crop"></i> A4/A3裁切</button>'
           + (FP_BACKFILL ? ' <button type="button" onclick="bfAddStamp('+p.page_no+')" style="height:20px;font-size:11px;padding:0 6px;border:1px solid #d98a33;background:#F0A24B;color:#fff;border-radius:3px;cursor:pointer;"><i class="fa fa-plus"></i> 新增圖章</button>' : '')
           + '</div><canvas id="fpcv_'+p.page_no+'"></canvas></div>';
    });
    $('#fpPageGrid').html(h);
    var fileUrl = caseDocUrl(FP_CASE, FP_CASE.file_type);
    pages.forEach(function(p){
        var dispW = 480, dispH = Math.round(dispW * (p.height_pt / p.width_pt || 1.414));
        var cv = new fabric.Canvas('fpcv_'+p.page_no, {width:dispW, height:dispH, selection:false});
        FP_CANVASES[p.page_no] = cv;
        renderDocPages(FP_CASE.file_type, fileUrl, [p], function(pageNo, src){
            fabric.Image.fromURL(src, function(img){
                img.set({left:0, top:0, scaleX:dispW/img.width, scaleY:dispH/img.height, selectable:false, evented:false});
                cv.setBackgroundImage(img, cv.renderAll.bind(cv));
            }, {crossOrigin:'anonymous'});
        });
        cv.on('selection:created', function(e){ FP_SELECTED = {canvas:cv, obj:e.selected[0]}; });
        cv.on('selection:updated', function(e){ FP_SELECTED = {canvas:cv, obj:e.selected[0]}; });
        cv.on('selection:cleared', function(){ FP_SELECTED = null; });
        cv.on('object:modified', function(e){ fpSaveFieldPosition(p.page_no, e.target); });

        var wrapEl = cv.upperCanvasEl;
        wrapEl.addEventListener('dragover', function(ev){ ev.preventDefault(); });
        wrapEl.addEventListener('drop', function(ev){
            ev.preventDefault();
            var data = ev.dataTransfer.getData('text/plain');
            if (!data) return;
            var d = JSON.parse(data);
            var rect = wrapEl.getBoundingClientRect();
            var xFrac = (ev.clientX - rect.left) / dispW, yFrac = (ev.clientY - rect.top) / dispH;
            var minFrac = fieldMinFrac(p);
            var wFrac = d.boxType === 'stamp' ? Math.max(minFrac.min_w + 0.02, 0.12) : 0.26;
            var hFrac = d.boxType === 'stamp' ? Math.max(minFrac.min_h + 0.02, 0.09) : 0.07;
            xFrac = Math.max(0, Math.min(1-wFrac, xFrac - wFrac/2));
            yFrac = Math.max(0, Math.min(1-hFrac, yFrac - hFrac/2));
            var g = fpAddFieldBox(p.page_no, d.slotKey, d.boxType, xFrac, yFrac, wFrac, hFrac, 0);
            fpSaveFieldPosition(p.page_no, g);
        });
    });
    existingFields.forEach(function(f){
        var g = fpAddFieldBox(f.page_no, f.slot_key, f.box_type, f.x, f.y, f.w, f.h, f.id);
        if (FP_BACKFILL && g) BF_OBJS[f.id] = g;
    });
    if (FP_BACKFILL) renderBfList();
    else renderFpLabelList(existingFields.map(function(f){ return f.slot_key+'_'+f.box_type; }));
}
function fpAddFieldBox(pageNo, slotKey, boxType, xFrac, yFrac, wFrac, hFrac, fieldId){
    var cv = FP_CANVASES[pageNo];
    if (!cv) return;
    var color = slotColor(slotKey); // 跟待框選標籤/樣板參考同一套顏色，方便對照是哪一個槽位
    var label = boxType === 'stamp' ? '章' : '覆';
    if (FP_BACKFILL) { var bff = bfFieldBySlot(slotKey); label = (bff && bff.signer_name) ? bff.signer_name : '未指定'; }
    var rect = new fabric.Rect({originX:'left', originY:'top', fill:color, opacity:0.32, stroke:color, strokeWidth:1.5});
    var text = new fabric.Text(label, {fontSize:13, fill:'#5b3a1e', originX:'center', originY:'center'});
    var group = new fabric.Group([rect, text], {
        left: xFrac*cv.width, top: yFrac*cv.height, width: wFrac*cv.width, height: hFrac*cv.height,
        lockRotation:true, hasRotatingPoint:false,
    });
    text.set({left: (wFrac*cv.width)/2, top: (hFrac*cv.height)/2});
    group.fieldId = fieldId||0; group.slotKey = slotKey; group.boxType = boxType; group.pageNo = pageNo;
    // 補案件的圖章大小固定（＝該章圖章模板的實際尺寸），只能搬位置不能拉大縮小：拿掉縮放控制點
    // （使用者2026-08-17明確要求；印出來的大小本來就由模板決定，拉框只會讓畫面跟列印對不起來）
    if (FP_BACKFILL) group.set({lockScalingX:true, lockScalingY:true, hasControls:false, hasBorders:true});
    cv.add(group);
    return group;
}
function fpSaveFieldPosition(pageNo, obj){
    if (!obj || obj.pageNo === undefined) return;
    if (FP_BACKFILL) { bfSaveFieldPosition(pageNo, obj); return; }  // 補案件走自己的存檔(沒有樣板槽位白名單)
    var cv = FP_CANVASES[pageNo];
    var xFrac = obj.left / cv.width, yFrac = obj.top / cv.height;
    var wFrac = (obj.width * obj.scaleX) / cv.width, hFrac = (obj.height * obj.scaleY) / cv.height;
    var field = {id:obj.fieldId||0, slot_key:obj.slotKey, box_type:obj.boxType, page_no:pageNo, x:xFrac, y:yFrac, w:wFrac, h:hFrac};
    $.post(API, {action:'case_field_save', csrf:META.csrf, case_id:FP_CASE.id, field:JSON.stringify(field)}, function(res){
        if (!res.ok){ alert(res.error||'儲存失敗，請調整後再試一次'); return; }
        obj.fieldId = res.id;
        renderFpLabelList((res.fields||[]).map(function(f){ return f.slot_key+'_'+f.box_type; }));
    }, 'json');
}
function fpDeleteSelected(){
    if (!FP_SELECTED){ alert('請先點選一個框'); return; }
    var obj = FP_SELECTED.obj, cv = FP_SELECTED.canvas;
    if (!obj.fieldId){ cv.remove(obj); FP_SELECTED = null; return; }
    $.post(API, {action:'case_field_delete', csrf:META.csrf, case_id:FP_CASE.id, field_id:obj.fieldId}, function(res){
        if (!res.ok){ alert(res.error||'刪除失敗'); return; }
        cv.remove(obj); FP_SELECTED = null;
        renderFpLabelList((res.fields||[]).map(function(f){ return f.slot_key+'_'+f.box_type; }));
    }, 'json');
}
function fpSubmit(){
    if (FP_BACKFILL) {
        if (!BF_FIELDS.length){ alert('請至少新增一個圖章'); return; }
        var bad = BF_FIELDS.filter(function(f){ return !f.signer_user_id; });
        if (bad.length){ alert('還有 '+bad.length+' 個圖章沒有指定人員，請每個圖章都選好是誰的章再完成'); return; }
        if (!confirm('確定要完成嗎？補案件送出後直接標記為已完成（固定自動審核），不會通知任何人簽核，圖章內容也不可再修改。')) return;
    } else {
        // 送出前先確認有選定填表人（後端 fsd_case_submit 也會擋，這裡只是先講清楚、順手把設定視窗打開）
        if (!FP_CASE.filler_id && FP_NEEDS_FILLER){
            alert('這張案件有「填表人」的圖章欄位，請先指定填表人是誰再送出（那個章會蓋這個人）。');
            if (FP_CAN_SET_FILLER) openEditFiller('fp');
            return;
        }
        if (!confirm('確定要送出嗎？送出後開始通知第一關的簽核人，框選內容將不可再修改。')) return;
    }
    $.post(API, {action:'case_submit', csrf:META.csrf, case_id:FP_CASE.id}, function(res){
        if (!res.ok){
            alert(res.error||'送出失敗');
            // 後端才發現沒選填表人（例如框選完才加上填表人圖章框，前端旗標是載入當下算的會過期）
            if (res.need_filler){ FP_NEEDS_FILLER = true; renderFpFiller(); if (FP_CAN_SET_FILLER) openEditFiller('fp'); }
            return;
        }
        $('#fieldPanel').hide();
        openCase(FP_CASE.id);
    }, 'json');
}

loadMeta(loadCases);
</script>
</body>
</html>
