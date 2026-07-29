<?php
// =============================================================================
// views/QC/inspection_entry_v2.php   品管檢驗表 2.0（新版填寫介面）
// -----------------------------------------------------------------------------
// 為什麼有這一頁：舊頁 inspection_combined_prototype.php 把「設定標準」與「填實測值」
// 擠在同一張 9 欄表格，現場反映
//   ① 欄位太多、每 PCS 的輸入格只有 52~70px 很難點
//   ② 要在標準欄與實測欄之間跳來跳去、容易填錯列
//   ③ 平板/戴手套不好操作
//   ④ 填值時看不到該尺寸的公差，要自己心算有沒有超差
// 本頁針對這四點重做輸入介面：
//   - 三種檢視：逐項（一次專注一個尺寸）／逐件（一次專注一件）／總表（格狀，快速鍵盤）
//   - 規格（標準/上限/下限）永遠大字顯示在輸入格正上方，輸入即時判定並顯示偏差量
//   - 大觸控格 + 內建數字鍵盤（平板）；桌機保留 Enter/方向鍵連續輸入
//   - 「檢驗項目與標準」的編輯從主流程分離（總表按「編輯標準」才展開設定欄）
//
// ★ 後端完全沿用舊頁 API（inspection_combined_prototype.php 的 AJAX action）：
//   同一個 session、同一組 CSRF token、同一套 RBAC 與後端重算判定邏輯。
//   → 本頁不重複實作任何寫入邏輯，舊頁保持原狀可隨時對照/回退。
//   設定類跳窗（量具/幾何公差/樣板/抽樣規則/權限/異常單相關）與異常單決策流程
//   直接沿用舊頁同一份 HTML+JS（載入時由 build 區塊原樣併入，避免兩份分歧）。
//
// 載入效能：GET 只輸出 HTML，不做任何 DB 查詢；所有資料走 AJAX。
// =============================================================================
include_once '../../src/common/_config.php';

// CSRF：與舊頁共用同一組 session token（後端比對的就是 $_SESSION['qc_csrf']）
if (empty($_SESSION['qc_csrf'])) { $_SESSION['qc_csrf'] = bin2hex(random_bytes(16)); }
$CSRF = $_SESSION['qc_csrf'];

$isPopup = isset($_GET['popup']) && $_GET['popup'] == '1';
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>品管檢驗表 2.0</title>
    <link href="../../resource/css/bootstrap.css" rel="stylesheet">
    <link href="../../resource/css/font-awesome.css" rel="stylesheet">
    <link href="../../resource/css/custom.css" rel="stylesheet">
    <style>
    /* ===================== 配色（全站規範：一律暖色系） =====================
       深棕字 #4A3524 / 砂 #F7E0BD / 琥珀 #F0A24B / 珊瑚紅 #DD5138（NG）
       顏色不是唯一資訊：OK/NG 一律同時有 ✔ / ✘ 文字標籤              */
    :root{
        --ink:#4A3524; --ink2:#6B4423; --cream:#FCF7F0; --sand:#F7E0BD;
        --amber:#F0A24B; --amber-d:#C77C1A; --coral:#DD5138; --line:#E4D3BC;
    }
    body.qc2 { background:#F6F1EA; }
    .qc2 .page-title h3 { color:var(--ink); }
    input[type=number]::-webkit-inner-spin-button,
    input[type=number]::-webkit-outer-spin-button { -webkit-appearance:none; margin:0; }
    input[type=number]{ -moz-appearance:textfield; appearance:textfield; }

    .warm-panel { background:#fff; border:1px solid var(--line); border-radius:8px; padding:14px; margin-bottom:12px; }
    .btn-warm { background:var(--amber); border:1px solid var(--amber-d); color:#4A3524; font-weight:bold; }
    .btn-warm:hover,.btn-warm:focus { background:var(--amber-d); color:#fff; }
    .btn-warm-o { background:#fff; border:1px solid var(--amber-d); color:var(--amber-d); }
    .btn-warm-o:hover { background:var(--sand); color:var(--ink); }
    .btn-coral { background:var(--coral); border:1px solid #b9401f; color:#fff; font-weight:bold; }
    .btn-coral:hover,.btn-coral:focus { background:#b9401f; color:#fff; }

    /* ---------- 頂部固定情境列：料號/客戶/製程/數量隨時看得到 ---------- */
    .ctx-bar { position:sticky; top:0; z-index:900; background:#FFF8EE; border:1px solid var(--line);
               border-left:6px solid var(--amber); border-radius:6px; padding:8px 12px; margin-bottom:10px;
               display:flex; flex-wrap:wrap; gap:6px 20px; align-items:center; font-size:14px; color:var(--ink); }
    .ctx-bar b { color:#8a6a45; font-weight:normal; font-size:12px; display:block; line-height:1.1; }
    .ctx-bar .cv { font-weight:bold; font-size:15px; }
    .ctx-bar a.cv { color:var(--ink); text-decoration:underline; }

    /* ---------- 檢視切換 ---------- */
    .view-switch { display:inline-flex; border:1px solid var(--line); border-radius:20px; overflow:hidden; background:#fff; }
    .view-switch button { border:0; background:#fff; color:var(--ink2); padding:7px 16px; font-size:14px; }
    .view-switch button.on { background:var(--amber); color:#4A3524; font-weight:bold; }
    .toolbar-row { display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin-bottom:10px; }

    /* ---------- 項目/件別 膠囊列（可直接點跳，帶判定燈號） ---------- */
    .chips { display:flex; flex-wrap:wrap; gap:6px; margin-bottom:10px; }
    .chip { border:1px solid var(--line); background:#fff; border-radius:16px; padding:5px 12px; font-size:13px;
            color:var(--ink2); cursor:pointer; user-select:none; white-space:nowrap; }
    .chip.on { background:var(--ink2); border-color:var(--ink2); color:#fff; font-weight:bold; }
    .chip .dot { display:inline-block; width:9px; height:9px; border-radius:50%; margin-right:5px; background:#D9C8B0; }
    .chip .dot.ok { background:var(--amber); }
    .chip .dot.ng { background:var(--coral); }
    .chip .cnt { font-size:11px; opacity:.75; margin-left:4px; }

    /* ---------- 專注卡片 ---------- */
    .fcard { background:#fff; border:1px solid var(--line); border-radius:10px; box-shadow:0 1px 3px rgba(120,90,50,.08); }
    .fcard-hd { padding:12px 16px; border-bottom:1px solid var(--line); background:var(--cream); border-radius:10px 10px 0 0; }
    .fcard-hd .idx { display:inline-block; min-width:34px; height:34px; line-height:34px; text-align:center; border-radius:8px;
                     background:var(--ink2); color:#fff; font-weight:bold; margin-right:10px; font-size:16px; }
    .fcard-hd .nm { font-size:22px; font-weight:bold; color:var(--ink); vertical-align:middle; }
    .fcard-bd { padding:14px 16px; }
    /* 規格帶：標準值與上下限就在輸入格正上方（痛點④） */
    .specbar { display:flex; flex-wrap:wrap; gap:10px; margin-bottom:14px; }
    .spec { background:var(--sand); border:1px solid var(--line); border-radius:8px; padding:6px 14px; min-width:96px; text-align:center; }
    .spec .k { font-size:11px; color:#8a6a45; }
    .spec .v { font-size:20px; font-weight:bold; color:var(--ink); line-height:1.2; }
    /* 標準值＝主角(大)，上下限＝配角(小)，一眼分得出來 */
    .spec.std { padding:4px 18px; }
    .spec.std .v { font-size:30px; }
    .spec.lim { background:#FFF6EA; min-width:84px; padding:6px 10px; }
    .spec.lim .k { font-size:10px; }
    .spec.lim .v { font-size:17px; color:#7A5A35; }
    .spec.tool { background:#fff; text-align:left; min-width:200px; }
    .spec.tool .v { font-size:14px; }
    /* 量具改用「點按鈕開跳窗挑」，不再用又長又難點的下拉 */
    .tool-btn { display:block; width:100%; text-align:left; border:1px solid var(--line); background:#fff; color:var(--ink);
                border-radius:6px; padding:7px 10px; font-size:14px; line-height:1.25; }
    .tool-btn:hover { background:var(--cream); border-color:var(--amber-d); }
    .tool-btn .tcat { font-weight:bold; }
    .tool-btn .tno { color:#8a6a45; font-size:12px; }
    .tool-btn.none { color:#a08a6d; border-style:dashed; }
    #items-table .tool-btn { padding:4px 6px; font-size:12px; }
    /* 量具挑選跳窗：類型 → 編號，兩層都是大按鈕 */
    .tpick-grid { display:flex; flex-wrap:wrap; gap:8px; }
    .tpick-grid button { min-width:130px; min-height:52px; border:1px solid var(--line); background:#fff; color:var(--ink);
                         border-radius:8px; padding:8px 12px; font-size:15px; font-weight:bold; }
    .tpick-grid button:hover { background:var(--sand); border-color:var(--amber-d); }
    .tpick-grid button.on { background:var(--amber); border-color:var(--amber-d); }
    .tpick-grid button small { display:block; font-weight:normal; font-size:11px; color:#8a6a45; }
    .tpick-scope { background:var(--cream); border:1px solid var(--line); border-radius:6px; padding:8px 10px; margin-top:12px; font-size:13px; }
    .tpick-scope label { font-weight:normal; display:block; margin:2px 0; cursor:pointer; }

    /* ---------- 量測格（大觸控目標） ---------- */
    .cells { display:flex; flex-wrap:wrap; gap:10px; }
    .mcell { position:relative; width:118px; border:2px solid var(--line); border-radius:8px; background:#fff; padding:16px 6px 4px; text-align:center; }
    .mcell .mno { position:absolute; top:2px; left:6px; font-size:11px; color:#8a6a45; }
    .mcell .mval { width:100%; border:0; background:transparent; text-align:center; font-size:24px; font-weight:bold;
                   color:var(--ink); padding:0; height:34px; outline:none; }
    .mcell .mdev { display:block; font-size:11px; height:15px; line-height:15px; color:#8a6a45; }
    .mcell.c-ok { border-color:var(--amber); background:#FDF6EA; }
    .mcell.c-ok .mdev:before { content:'✔ '; }
    .mcell.c-ng { border-color:var(--coral); background:var(--coral); }
    .mcell.c-ng .mval { color:#fff; }
    .mcell.c-ng .mno { color:#FBE3DC; }
    .mcell.c-ng .mdev { color:#fff; font-weight:bold; }
    .mcell.c-ng .mdev:before { content:'✘ '; }
    .mcell.c-empty { border-style:dashed; border-color:#D9C8B0; }
    .mcell.focus-on { box-shadow:0 0 0 3px rgba(240,162,75,.45); }
    .mcell.okng { cursor:pointer; user-select:none; padding-bottom:8px; }
    .mcell.okng .mtxt { display:block; font-size:20px; font-weight:bold; color:var(--ink); height:34px; line-height:34px; }
    .mcell.okng.c-ng .mtxt { color:#fff; }
    .mcell.okng.c-empty .mtxt { color:#b9a68d; }
    /* 逐件模式：一列一個檢驗項目 */
    /* 逐件模式：項目名稱與輸入格靠在一起（max-width 讓兩者不會被拉到左右兩端） */
    .prow { display:flex; align-items:center; gap:16px; padding:8px 0; border-bottom:1px dashed var(--line); max-width:660px; }
    .prow:last-child { border-bottom:0; }
    .prow .pnm { flex:1 1 auto; min-width:0; }
    .prow .pnm .n { font-size:16px; font-weight:bold; color:var(--ink); }
    .prow .pnm .s { font-size:12px; color:#8a6a45; }
    .prow .pin { flex:0 0 auto; }
    /* 加量測（同尺寸用第二支量具/方法再量一次） */
    .rdbox { border:1px dashed var(--line); border-radius:8px; padding:10px; margin-top:10px; background:#FBF7F1; }
    .rdbox .rdhd { display:flex; align-items:center; gap:8px; margin-bottom:8px; font-size:13px; color:var(--ink2); }
    .rdbox .rdhd select { max-width:220px; }

    /* ---------- 總表模式 ---------- */
    #items-table { table-layout:fixed; width:100%; background:#fff; }
    #items-table thead th { background:var(--cream); color:var(--ink); position:sticky; top:0; z-index:2; text-align:center;
                            white-space:nowrap; border-color:var(--line) !important; font-size:13px; }
    #items-table td { vertical-align:middle; border-color:var(--line) !important; }
    #items-table .g-name { font-weight:bold; color:var(--ink); }
    #items-table .g-spec { font-size:13px; color:var(--ink2); white-space:nowrap; }
    #items-table .table-input { width:100%; min-width:0; border:1px solid #ccc; padding:3px 5px; border-radius:3px; }
    #items-table .mcell { width:96px; padding:14px 3px 3px; }
    #items-table .mcell .mval { font-size:18px; height:26px; }
    #items-table .mcell .mdev { font-size:10px; }
    #items-table .mcell.okng .mtxt { font-size:15px; height:26px; line-height:26px; }
    .gcells { display:flex; flex-wrap:wrap; gap:6px; }
    .pverdict { display:inline-block; min-width:82px; text-align:center; border-radius:6px; padding:6px 4px; font-weight:bold;
                border:2px solid var(--line); background:#fff; color:var(--ink); cursor:pointer; user-select:none; }
    .pverdict.ok { background:#FDF6EA; border-color:var(--amber); }
    .pverdict.ng { background:var(--coral); border-color:#b9401f; color:#fff; }
    .pverdict.manual { outline:2px dashed var(--amber-d); outline-offset:1px; }

    /* ---------- 底部固定摘要/動作列 ---------- */
    #dock { position:fixed; left:0; right:0; bottom:0; z-index:1000; background:#FFF8EE; border-top:2px solid var(--amber);
            box-shadow:0 -2px 8px rgba(120,90,50,.15); padding:8px 14px; }
    #dock .dockrow { display:flex; flex-wrap:wrap; align-items:center; gap:8px 18px; }
    #dock .stat { font-size:13px; color:var(--ink2); }
    #dock .stat b { font-size:18px; color:var(--ink); }
    #dock .stat.bad b { color:var(--coral); }
    .progbar { width:150px; height:8px; border-radius:4px; background:#EADFCE; overflow:hidden; display:inline-block; vertical-align:middle; }
    .progbar i { display:block; height:100%; background:var(--amber); }
    #dock-extra { display:none; padding-top:8px; border-top:1px dashed var(--line); margin-top:8px; }
    /* 底部空白由 JS 依 dock 實際高度設定（展開「數量/處置備註」時會變高），避免蓋住內容 */
    body.qc2 { padding-bottom:120px; }
    #dock .draft-note { font-size:12px; color:var(--amber-d); }

    /* ---------- 內建數字鍵盤（平板/戴手套） ---------- */
    #keypad { position:fixed; right:14px; bottom:104px; z-index:1001; background:#fff; border:1px solid var(--line);
              border-radius:10px; box-shadow:0 3px 12px rgba(120,90,50,.28); padding:8px; display:none; }
    #keypad .kp { display:grid; grid-template-columns:repeat(3,64px); gap:6px; }
    #keypad button { height:52px; font-size:20px; font-weight:bold; border:1px solid var(--line); background:var(--cream);
                     color:var(--ink); border-radius:8px; }
    #keypad button:active { background:var(--sand); }
    #keypad button.wide { grid-column:span 3; height:44px; font-size:16px; background:var(--amber); }
    #keypad .kphd { font-size:12px; color:#8a6a45; margin-bottom:6px; display:flex; justify-content:space-between; }

    .banner { border-radius:6px; padding:8px 12px; margin-bottom:10px; font-size:13px; }
    .banner-info { background:#FFF3E2; border:1px solid var(--line); color:var(--ink2); }
    .muted-help { color:#8a6a45; font-size:12px; }
    .batch-chip { display:inline-block; padding:6px 12px; margin:0 6px 6px 0; border-radius:18px; border:1px solid var(--line);
                  background:#fff; cursor:pointer; font-size:13px; user-select:none; color:var(--ink2); }
    .batch-chip.active { background:var(--ink2); border-color:var(--ink2); color:#fff; }
    .st-ok { color:var(--amber-d); } .st-ng { color:var(--coral); } .st-redo { color:#a9772f; } .st-wait { color:#8a6a45; }
    .batch-chip.active .st-ok,.batch-chip.active .st-ng,.batch-chip.active .st-redo,.batch-chip.active .st-wait { color:#fff; }
    .search-result-item { cursor:pointer; padding:8px 10px; border-bottom:1px solid var(--line); }
    .search-result-item:hover { background:var(--cream); }
    .page-title .dropdown-menu { right:0 !important; left:auto !important; }
    .history-row td { font-size:13px; }
    .tool-sel-label { font-size:11px; color:#6b5a45; }

    /* 平板：加大所有觸控目標 */
    @media (max-width:1024px), (pointer:coarse) {
        .mcell { width:132px; padding-top:18px; }
        .mcell .mval { font-size:26px; height:38px; }
        .chip { padding:8px 14px; font-size:14px; }
        .btn-sm,.btn-xs { padding:8px 13px; font-size:14px; }
        #items-table .mcell { width:96px; }
    }
    @media (max-width:600px){
        .mcell { width:calc(50% - 5px); }
        .spec { flex:1 1 45%; }
    }

    /* ---------- 列印：正式檢驗表版面（A4，交瀏覽器原生分頁，不用 JS 量高度） ---------- */
    #print-area { display:none; }
    @media print {
        @page { size:A4 portrait; margin:12mm 10mm; }
        body { background:#fff !important; padding-bottom:0 !important; }
        body * { visibility:hidden; }
        #print-area, #print-area * { visibility:visible; }
        #print-area { display:block; position:absolute; left:0; top:0; width:100%; color:#000; font-size:12px; }
        #print-area .pr-title { text-align:center; font-size:18px; font-weight:bold; margin-bottom:2px; }
        #print-area .pr-sub { text-align:center; font-size:12px; margin-bottom:8px; }
        #print-area .pr-meta { width:100%; border-collapse:collapse; margin-bottom:6px; }
        #print-area .pr-meta td { border:1px solid #000; padding:3px 6px; }
        #print-area .pr-meta .k { background:#f0f0f0; font-weight:bold; white-space:nowrap; width:70px; }
        #print-area table.pr-items { width:100%; border-collapse:collapse; }
        #print-area table.pr-items th, #print-area table.pr-items td { border:1px solid #000; padding:3px 4px; text-align:center; }
        #print-area table.pr-items thead th { background:#eee; }
        #print-area table.pr-items thead { display:table-header-group; }
        #print-area table.pr-items tr { page-break-inside:avoid; }
        #print-area .pr-ng { color:#000; font-weight:bold; text-decoration:underline; }
        #print-area .pr-sign { margin-top:14px; width:100%; border-collapse:collapse; }
        #print-area .pr-sign td { border:1px solid #000; padding:14px 6px 4px; text-align:center; vertical-align:bottom; height:46px; }
        #print-area .pr-sign .lbl { font-size:11px; color:#333; }
        #dock,#keypad { display:none !important; }
    }
    </style>
</head>
<body class="qc2 <?php echo $isPopup ? 'popup-mode' : 'nav-sm'; ?>">
<div class="container body">
    <div class="main_container">
        <?php if (!$isPopup) include '../partPage/sideAndTopBarMenu.html'; ?>

        <div class="<?php echo $isPopup ? 'col-md-12' : 'right_col'; ?>" role="main"<?php echo $isPopup ? ' style="width:100%;float:none;padding:15px;"' : ''; ?>>
            <div class="page-title">
                <div class="title_left"><h3>品管檢驗表 <small>2.0 新版填寫介面</small></h3></div>
                <div class="title_right">
                    <div class="pull-right">
                        <button class="btn btn-default btn-sm" id="btn-print"><i class="fa fa-print"></i> 列印</button>
                        <button class="btn btn-default btn-sm" id="btn-csv"><i class="fa fa-file-excel-o"></i> 匯出CSV</button>
                        <button class="btn btn-default btn-sm" id="btn-history"><i class="fa fa-history"></i> 歷史紀錄</button>
                        <div class="btn-group">
                            <button class="btn btn-default btn-sm dropdown-toggle" data-toggle="dropdown"><i class="fa fa-cog"></i> 設定 <span class="caret"></span></button>
                            <ul class="dropdown-menu dropdown-menu-right">
                                <li class="setting-menu-item" style="display:none;"><a href="#" id="btn-tool-setting"><i class="fa fa-wrench"></i> 量具設定</a></li>
                                <li class="setting-menu-item" style="display:none;"><a href="#" id="btn-special-setting"><i class="fa fa-cog"></i> 幾何公差管理</a></li>
                                <li class="setting-menu-item" style="display:none;"><a href="#" id="btn-template-setting"><i class="fa fa-list-alt"></i> 通用樣板管理</a></li>
                                <li class="sampling-menu-item" style="display:none;"><a href="#" id="btn-sampling-setting"><i class="fa fa-list-ol"></i> 抽樣規則設定</a></li>
                                <li class="setting-menu-item" style="display:none;"><a href="#" id="btn-qadept-setting"><i class="fa fa-sitemap"></i> 異常單回覆部門設定</a></li>
                                <li><a href="#" id="btn-qadecide-setting"><i class="fa fa-gavel"></i> 異常單處置決策設定</a></li>
                                <li class="divider"></li>
                                <li><a href="#" id="btn-perm-setting"><i class="fa fa-key"></i> 權限設定（角色）</a></li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
            <div class="clearfix"></div>

            <div id="no-view-hint" class="alert alert-danger" style="display:none;"></div>
            <div class="banner banner-info" id="mode-banner"></div>

            <!-- 示範/獨立瀏覽模式才出現的待驗搜尋 -->
            <div id="step-search" class="warm-panel" style="display:none;">
                <b>選擇待驗項目</b>
                <div class="row" style="margin-top:6px;">
                    <div class="col-md-6">
                        <div class="input-group">
                            <input type="text" id="search-kw" class="form-control" placeholder="輸入部分料號 / BOM / 客戶後按搜尋">
                            <span class="input-group-btn"><button class="btn btn-warm" id="btn-search">搜尋</button></span>
                        </div>
                        <div id="search-results" style="border:1px solid #eee; margin-top:4px;"></div>
                    </div>
                </div>
            </div>

            <!-- 固定情境列（料號/客戶/BOM/製程/數量） -->
            <div class="ctx-bar" id="ctx-bar" style="display:none;"></div>

            <div id="main-area" style="display:none;">
                <div id="no-part-hint" class="alert alert-danger" style="display:none;">
                    <i class="fa fa-exclamation-circle"></i> 此料件尚未建立料號，請先到 <b>基本設定</b> 建立料號後再檢驗（暫無法儲存）。
                </div>
                <div id="no-perm-hint" class="alert alert-danger" style="display:none;">
                    <i class="fa fa-ban"></i> 您沒有<b>「填寫檢驗表單」</b>權限，僅能檢視。請洽管理員於 設定 → 權限設定 開通。
                </div>
                <div id="edit-mode-banner" class="alert alert-info" style="display:none;">
                    <i class="fa fa-pencil"></i> <b>修改模式</b>：正在修改歷程 qc_form_id=<span id="edit-form-id"></span>，儲存時需填修改原因，存檔後此筆會自動回鎖。
                    <button class="btn btn-xs btn-default pull-right" id="btn-exit-edit">取消修改，回到新檢驗</button>
                </div>

                <!-- 批次 / 歷程（預設收合，不佔填寫版面） -->
                <div class="warm-panel" style="padding:8px 12px;">
                    <a href="#" id="btn-toggle-batch" style="color:var(--ink2);font-weight:bold;text-decoration:none;">
                        <i class="fa fa-caret-right"></i> 批次與檢驗歷程 <span class="muted-help" id="batch-summary"></span></a>
                    <div id="batch-zone" style="display:none; margin-top:10px;">
                        <div id="batch-bar" style="margin-bottom:6px;"></div>
                        <div id="batch-history"></div>
                    </div>
                </div>

                <div id="no-std-hint" class="alert alert-warning" style="display:none;">
                    <i class="fa fa-exclamation-triangle"></i> 此料號／製程<b>尚未設定檢驗標準</b>。可按下方「新增檢驗項目」自行建立，或
                    <button class="btn btn-xs btn-warm-o" id="btn-import-tpl"><i class="fa fa-download"></i> 匯入通用樣板</button>
                    後微調。<b>勾選底部「同步更新標準」存檔後即成此料號標準，下次自動帶出。</b>
                </div>

                <!-- 檢視切換列 -->
                <div class="toolbar-row">
                    <span class="view-switch">
                        <button data-view="ITEM" title="一次專注一個尺寸，量完 5 件再換下一個尺寸">逐項</button>
                        <button data-view="PCS"  title="一次專注一件，把該件所有尺寸量完">逐件</button>
                        <button data-view="GRID" title="傳統格狀總表，鍵盤連續輸入最快">總表</button>
                    </span>
                    <button class="btn btn-default btn-sm" id="btn-keypad"><i class="fa fa-keyboard-o"></i> 數字鍵盤</button>
                    <span class="muted-help" id="view-hint"></span>
                </div>

                <!-- 三種檢視（同一份資料模型，切換不會遺失已填內容） -->
                <div id="view-item" class="view-pane"></div>
                <div id="view-pcs"  class="view-pane" style="display:none;"></div>
                <div id="view-grid" class="view-pane" style="display:none;">
                    <div style="margin-bottom:6px;">
                        <label style="font-weight:normal;"><input type="checkbox" id="chk-std-edit"> 編輯標準（顯示項目名稱／公差／量具／型態欄位）</label>
                        <a href="#" id="btn-code-mode" class="pull-right muted-help" title="切換編號顯示方式"></a>
                    </div>
                    <div class="table-responsive" style="max-height:58vh; overflow:auto;">
                        <table class="table table-bordered" id="items-table">
                            <thead><tr id="grid-head"></tr></thead>
                            <tbody id="items-body"></tbody>
                            <tfoot><tr>
                                <td id="verdict-label" class="text-right" style="font-weight:bold;background:var(--cream);">判定結果<br>
                                    <span class="muted-help" style="font-weight:normal;">該件任一項 NG 即自動 NG；點擊可手動改判，雙擊恢復自動</span></td>
                                <td id="verdict-cells" style="background:var(--cream);"></td>
                                <td style="background:var(--cream);"></td>
                                <td style="background:var(--cream);"></td>
                            </tr></tfoot>
                        </table>
                    </div>
                </div>

                <div style="margin:10px 0 6px;">
                    <button class="btn btn-warm-o btn-sm" id="btn-add-row"><i class="fa fa-plus"></i> 新增檢驗項目</button>
                    <button class="btn btn-default btn-sm" id="btn-import-tpl2"><i class="fa fa-download"></i> 匯入通用樣板</button>
                </div>
            </div>
        </div>

        <?php if (!$isPopup) include '../partPage/footer.html'; ?>
    </div>
</div>

<!-- ===================== 底部固定摘要 / 動作列 ===================== -->
<div id="dock" style="display:none;">
    <div class="dockrow">
        <span class="stat">進度 <b id="dk-prog">0/0</b> <span class="progbar"><i id="dk-progbar" style="width:0%"></i></span></span>
        <span class="stat bad">不良 <b id="dk-ng">0</b> 件</span>
        <span class="stat">整體判定 <b id="dk-judge">—</b></span>
        <span class="draft-note" id="draft-status"></span>
        <span style="flex:1 1 auto;"></span>
        <button class="btn btn-default btn-sm" id="btn-dock-extra"><i class="fa fa-sliders"></i> 數量 / 處置備註</button>
        <button class="btn btn-default btn-sm" id="btn-cancel"><i class="fa fa-times"></i> 取消</button>
        <button class="btn btn-coral btn-sm" id="btn-redo"><i class="fa fa-undo"></i> 退回重做</button>
        <button class="btn btn-warm" id="btn-save"><i class="fa fa-save"></i> 儲存檢驗結果</button>
    </div>
    <div id="dock-extra">
        <div class="row">
            <div class="col-sm-2 form-group"><label class="muted-help">本批送驗數</label>
                <input type="number" class="form-control input-sm" id="inp-qty" value="0"></div>
            <div class="col-sm-2 form-group"><label class="muted-help">抽驗數（件）</label>
                <input type="number" class="form-control input-sm" id="inp-sample" value="5"></div>
            <div class="col-sm-2 form-group"><label class="muted-help">不良數（自動）</label>
                <input type="number" class="form-control input-sm" id="inp-ng" value="0" readonly></div>
            <div class="col-sm-2 form-group"><label class="muted-help">整體判定</label><div>
                <label class="radio-inline"><input type="radio" name="judge" value="OK" checked> 合格</label>
                <label class="radio-inline"><input type="radio" name="judge" value="NG"> 不良</label></div></div>
            <div class="col-sm-4 form-group"><label class="muted-help">處置 / 備註</label>
                <input type="text" class="form-control input-sm" id="inp-remark" placeholder="例：尺寸 A 超差，退回重做…"></div>
        </div>
        <label style="font-weight:normal;"><input type="checkbox" id="chk-save-std" checked> 存檔時同步更新此料號的檢驗標準（下次自動帶出）</label>
    </div>
</div>

<!-- ===================== 內建數字鍵盤 ===================== -->
<div id="keypad">
    <div class="kphd"><span>數字鍵盤</span><a href="#" id="kp-close">關閉</a></div>
    <div class="kp">
        <button data-k="7">7</button><button data-k="8">8</button><button data-k="9">9</button>
        <button data-k="4">4</button><button data-k="5">5</button><button data-k="6">6</button>
        <button data-k="1">1</button><button data-k="2">2</button><button data-k="3">3</button>
        <button data-k="-">−</button><button data-k="0">0</button><button data-k=".">.</button>
        <button data-k="BS"><i class="fa fa-long-arrow-left"></i></button>
        <button data-k="CL">清除</button>
        <button data-k="OK"><i class="fa fa-check"></i></button>
        <button class="wide" data-k="NEXT">下一格 <i class="fa fa-arrow-right"></i></button>
    </div>
</div>

<!-- ===================== 量具挑選跳窗：先點類型、再點編號 ===================== -->
<div class="modal fade" id="toolPickModal" tabindex="-1" role="dialog">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header" style="background:#FFF8EE;border-bottom:1px solid #E4D3BC;">
            <button type="button" class="close" data-dismiss="modal">&times;</button>
            <h4 class="modal-title" style="color:#4A3524;"><i class="fa fa-wrench"></i> 選擇量具 <small id="tp-for"></small></h4>
        </div>
        <div class="modal-body">
            <div id="tp-step1">
                <div class="muted-help" style="margin-bottom:6px;">① 先點量具<b>類型</b></div>
                <div class="tpick-grid" id="tp-cats"></div>
            </div>
            <div id="tp-step2" style="display:none;">
                <div class="muted-help" style="margin-bottom:6px;">
                    ② 再點量具<b>編號</b>　<a href="#" id="tp-back">← 換一個類型</a></div>
                <div class="tpick-grid" id="tp-nos"></div>
            </div>
            <div class="tpick-scope" id="tp-scope">
                <b>套用範圍</b>　<span class="muted-help">同一支量具常常好幾個尺寸共用，不必一欄一欄設</span>
                <label><input type="radio" name="tpscope" value="blank" checked> 套用到<b>所有尚未指定量具</b>的檢驗項目</label>
                <label><input type="radio" name="tpscope" value="one"> 只設定<b>這一個</b>項目</label>
                <label><input type="radio" name="tpscope" value="all"> 套用到<b>全部</b>檢驗項目（覆蓋既有設定）</label>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-default pull-left" id="tp-clear"><i class="fa fa-eraser"></i> 清除此項量具</button>
            <button type="button" class="btn btn-default" data-dismiss="modal">關閉</button>
        </div>
    </div></div>
</div>

<!-- 樣板選擇 Modal（示意：正式版接通用樣板） -->
<div class="modal fade" id="tplModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-sm"><div class="modal-content">
        <div class="modal-header"><button class="close" data-dismiss="modal">&times;</button><h4 class="modal-title">選擇通用樣板</h4></div>
        <div class="modal-body"><div class="list-group" id="tpl-list"></div></div>
    </div></div>
</div>

<!-- NG 後詢問是否開立異常單 Modal（必選：開立 或 填原因不開立） -->
<div class="modal fade" id="ngAskModal" tabindex="-1" role="dialog" data-backdrop="static" data-keyboard="false">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header" style="background:#d9534f;color:#fff;border-radius:6px 6px 0 0;">
            <h4 class="modal-title"><i class="fa fa-exclamation-triangle"></i> 檢驗判定不良（NG）</h4>
        </div>
        <div class="modal-body">
            <p id="ng-ask-info" style="font-size:15px;"></p>
            <div class="text-center" style="margin:16px 0;">
                <button class="btn btn-danger btn-lg" id="btn-ng-open" style="margin-right:14px;"><i class="fa fa-file-text-o"></i> 開立異常單</button>
                <button class="btn btn-default btn-lg" id="btn-ng-skip">不開立（填原因）</button>
            </div>
            <div id="ng-skip-area" style="display:none;">
                <label>不開立異常單的原因（必填，會記錄於檢驗歷程）</label>
                <textarea class="form-control" id="ng-skip-reason" rows="2" placeholder="例：輕微偏差已現場處置、客戶允收…"></textarea>
                <div class="text-right" style="margin-top:8px;">
                    <button class="btn btn-primary btn-sm" id="btn-ng-skip-confirm"><i class="fa fa-check"></i> 確認不開立</button>
                </div>
            </div>
        </div>
    </div></div>
</div>

<!-- 修改紀錄 Modal -->
<div class="modal fade" id="logModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg"><div class="modal-content">
        <div class="modal-header"><button class="close" data-dismiss="modal">&times;</button><h4 class="modal-title"><i class="fa fa-history"></i> 檢驗歷程修改紀錄</h4></div>
        <div class="modal-body" id="log-modal-body"></div>
    </div></div>
</div>

<!-- 刪除角色 Modal（先列出該角色人員，可轉移角色，需輸入大寫 Y） -->
<div class="modal fade" id="roleDeleteModal" tabindex="-1" role="dialog" style="z-index:10600;">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header"><button class="close" data-dismiss="modal">&times;</button>
            <h4 class="modal-title text-danger"><i class="fa fa-trash"></i> 刪除角色：<span id="del-role-name"></span></h4></div>
        <div class="modal-body">
            <div id="del-role-users" style="margin-bottom:10px;"></div>
            <div class="form-group" id="del-transfer-wrap" style="display:none;">
                <label>將上列人員轉移到角色：</label>
                <select id="del-transfer-role" class="form-control"></select>
                <p class="help-block">選「不轉移」則僅移除這些人員的此角色指派。</p>
            </div>
            <div class="form-group">
                <label class="text-danger">此操作無法復原。確認請輸入大寫 <b>Y</b>：</label>
                <input id="del-confirm-y" class="form-control" maxlength="1" style="width:80px;" autocomplete="off">
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-default" data-dismiss="modal">取消</button>
            <button class="btn btn-danger" id="btn-confirm-del-role"><i class="fa fa-trash"></i> 確定刪除角色</button>
        </div>
    </div></div>
</div>

<!-- 權限設定 Modal（角色↔功能；沿用既有 roles 框架） -->
<div class="modal fade" id="permModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg"><div class="modal-content">
        <div class="modal-header"><button class="close" data-dismiss="modal">&times;</button>
            <h4 class="modal-title"><i class="fa fa-key"></i> 權限設定（角色 → QC 功能）</h4></div>
        <div class="modal-body">
            <p class="text-muted" style="font-size:13px;">勾選各角色可使用的 QC 功能。<b>人員對應角色請至「人員權限(user_permissions)」頁面設定。</b>系統管理員角色預設擁有全部權限。</p>
            <div class="row">
                <div class="col-md-4">
                    <label>角色</label>
                    <div style="margin-bottom:6px;">
                        <button class="btn btn-xs btn-success" id="btn-add-role"><i class="fa fa-plus"></i> 新增</button>
                        <button class="btn btn-xs btn-default" id="btn-rename-role" disabled><i class="fa fa-pencil"></i> 重新命名</button>
                        <button class="btn btn-xs btn-danger" id="btn-delete-role" disabled><i class="fa fa-trash"></i> 刪除</button>
                    </div>
                    <div class="list-group" id="perm-role-list" style="max-height:320px;overflow:auto;"></div>
                </div>
                <div class="col-md-8">
                    <label>此角色可用的 QC 功能</label>
                    <div id="perm-feature-box" style="border:1px solid #eee;padding:10px;min-height:200px;">
                        <p class="text-muted">← 請先選擇角色</p>
                    </div>
                    <div class="text-right" style="margin-top:10px;">
                        <button class="btn btn-primary btn-sm" id="btn-save-perm" disabled><i class="fa fa-save"></i> 儲存此角色設定</button>
                    </div>
                </div>
            </div>
        </div>
    </div></div>
</div>

<!-- 量具設定 Modal（種類 + 編號；與 inspection_standard_setting.php 共用資料表） -->
<div class="modal fade" id="toolManageModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg"><div class="modal-content">
        <div class="modal-header"><button class="close" data-dismiss="modal">&times;</button>
            <h4 class="modal-title"><i class="fa fa-wrench"></i> 量具設定</h4></div>
        <div class="modal-body">
            <div class="row">
                <div class="col-md-5" style="border-right:1px solid #eee;">
                    <h4>1. 量具種類</h4>
                    <form id="tool-cat-form" class="form-inline" style="margin-bottom:10px;">
                        <input type="hidden" id="tc-id">
                        <div class="form-group"><input type="text" id="tc-name" class="form-control input-sm" placeholder="種類名稱 (如: 游標卡尺)" required></div>
                        <button type="submit" class="btn btn-primary btn-sm" id="btn-save-tc">新增</button>
                        <button type="button" class="btn btn-default btn-sm" id="btn-cancel-tc" style="display:none;">取消</button>
                    </form>
                    <div class="list-group" id="tool-cat-list" style="max-height:400px;overflow:auto;"></div>
                </div>
                <div class="col-md-7">
                    <h4>2. 量具編號</h4>
                    <div id="tool-instance-area" style="display:none;">
                        <p class="text-info">當前選擇種類：<strong id="current-cat-name"></strong></p>
                        <form id="tool-inst-form" class="form-inline" style="margin-bottom:10px;">
                            <input type="hidden" id="ti-id"><input type="hidden" id="ti-cat-id">
                            <div class="form-group"><input type="text" id="ti-no" class="form-control input-sm" placeholder="量具編號 (如: C01)" required></div>
                            <button type="submit" class="btn btn-success btn-sm" id="btn-save-ti">新增編號</button>
                            <button type="button" class="btn btn-default btn-sm" id="btn-cancel-ti" style="display:none;">取消</button>
                        </form>
                        <table class="table table-striped table-bordered table-condensed">
                            <thead><tr><th>編號</th><th width="80">操作</th></tr></thead>
                            <tbody id="tool-inst-list"></tbody>
                        </table>
                    </div>
                    <div id="tool-instance-empty" class="text-muted" style="padding-top:50px;text-align:center;">
                        <i class="fa fa-arrow-left"></i> 請先從左側選擇一個量具種類
                    </div>
                </div>
            </div>
        </div>
    </div></div>
</div>

<!-- 量具取代並刪除 Modal -->
<div class="modal fade" id="toolReplaceModal" tabindex="-1" role="dialog" style="z-index:10600;">
    <div class="modal-dialog modal-sm"><div class="modal-content">
        <div class="modal-header"><button class="close" data-dismiss="modal">&times;</button>
            <h4 class="modal-title">取代並刪除量具</h4></div>
        <div class="modal-body">
            <p class="text-danger">您即將刪除：<strong id="replace-old-name"></strong></p>
            <p>請選擇要將現有資料轉移到哪個量具種類：</p>
            <input type="hidden" id="replace-old-id">
            <select id="replace-new-id" class="form-control"></select>
            <p class="help-block"><small>執行後，舊種類將被刪除，所有關聯的檢驗項目與量具編號將移至新種類。</small></p>
        </div>
        <div class="modal-footer">
            <button class="btn btn-default" data-dismiss="modal">取消</button>
            <button class="btn btn-danger" id="btn-confirm-replace">確認取代並刪除</button>
        </div>
    </div></div>
</div>

<!-- 幾何公差管理 Modal -->
<div class="modal fade" id="specialItemManageModal" tabindex="-1" role="dialog">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header"><button class="close" data-dismiss="modal">&times;</button>
            <h4 class="modal-title"><i class="fa fa-cog"></i> 幾何公差與特殊項目設定</h4></div>
        <div class="modal-body">
            <div class="well well-sm">
                <form id="special-item-form" class="form-inline">
                    <input type="hidden" id="si-id">
                    <div class="form-group"><input type="text" id="si-name" class="form-control input-sm" placeholder="名稱 (如: 真圓度)" required></div>
                    <div class="form-group"><input type="text" id="si-symbol" class="form-control input-sm" placeholder="符號 (如: ○)" size="5"></div>
                    <div class="form-group"><input type="text" id="si-code" class="form-control input-sm" placeholder="代碼/英文" size="10"></div>
                    <button type="submit" class="btn btn-primary btn-sm" id="btn-save-si">新增</button>
                    <button type="button" class="btn btn-default btn-sm" id="btn-cancel-si" style="display:none;">取消</button>
                </form>
            </div>
            <div class="list-group" id="manage-special-list" style="max-height:300px;overflow:auto;"></div>
        </div>
    </div></div>
</div>

<!-- 通用樣板管理 Modal -->
<div class="modal fade" id="templateManageModal" tabindex="-1" role="dialog">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header"><button class="close" data-dismiss="modal">&times;</button>
            <h4 class="modal-title"><i class="fa fa-list-alt"></i> 通用檢驗樣板管理</h4></div>
        <div class="modal-body">
            <div class="alert alert-info" style="font-size:13px;">請先在主畫面「③ 檢驗項目」表格填好項目，再點「從當前表格建立樣板」。點「編輯」會把樣板載入主畫面表格修改後更新。</div>
            <div class="input-group" style="margin-bottom:10px;">
                <input type="text" id="new-template-name" class="form-control" placeholder="輸入新樣板名稱 (例如: 一般車件標準)">
                <span class="input-group-btn">
                    <button class="btn btn-default" type="button" id="btn-cancel-edit-template" style="display:none;">取消編輯</button>
                    <button class="btn btn-success" type="button" id="btn-create-template">從當前表格建立樣板</button>
                </span>
            </div>
            <hr style="margin:10px 0;">
            <div class="list-group" id="template-list"></div>
        </div>
    </div></div>
</div>

<!-- 抽樣規則設定 Modal -->
<div class="modal fade" id="samplingRuleModal" tabindex="-1" role="dialog">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header"><button class="close" data-dismiss="modal">&times;</button>
            <h4 class="modal-title"><i class="fa fa-list-ol"></i> 抽樣規則設定</h4></div>
        <div class="modal-body">
            <p class="text-muted" style="font-size:13px;">依「本批送驗數」落在哪個範圍決定建議抽驗數（載入待驗項目時自動帶入）。</p>
            <form id="rule-form" class="form-inline well well-sm">
                <input type="hidden" id="rule-id">
                <input type="number" id="rule-min" class="form-control input-sm" placeholder="最小數量" style="width:90px;" required>
                ~
                <input type="number" id="rule-max" class="form-control input-sm" placeholder="最大數量" style="width:90px;" required>
                ：抽
                <input type="number" id="rule-sample" class="form-control input-sm" placeholder="數量" style="width:70px;" required>
                <button type="submit" class="btn btn-primary btn-sm" id="btn-save-rule">新增</button>
                <button type="button" class="btn btn-default btn-sm" id="btn-cancel-rule" style="display:none;">取消</button>
            </form>
            <table class="table table-striped table-bordered table-condensed">
                <thead><tr><th>數量範圍</th><th>抽驗數</th><th width="110">操作</th></tr></thead>
                <tbody id="rule-list"></tbody>
            </table>
        </div>
    </div></div>
</div>

<script src="../../resource/js/jquery.min.js"></script>
<script src="../../resource/js/bootstrap.min.js"></script>
<script src="../../resource/js/fastclick.js"></script>
<script src="../../resource/js/nprogress.js"></script>
<script src="../../resource/js/custom.min.js"></script>
<?php include '../QA/qa_abnormal_modal.php'; // 共用「開立品質異常單」跳窗元件（QAAbnormalModal） ?>

<!-- 異常單回覆部門設定 Modal（自 IR_Track 移入 2026-07-06；開單跳窗的「回覆部門」清單來源） -->
<div class="modal fade" id="qaDeptCfgModal" tabindex="-1" role="dialog">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header" style="background:#2A3F54;color:#fff;border-radius:6px 6px 0 0;">
            <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:.9;"><span>&times;</span></button>
            <h4 class="modal-title"><i class="fa fa-sitemap"></i> 品質異常單 — 可用部門設定</h4>
        </div>
        <div class="modal-body" style="max-height:64vh;overflow-y:auto;">
            <p class="text-muted" style="font-size:12.5px;"><i class="fa fa-info-circle"></i> 勾選的部門會出現在「開立異常單」跳窗的回覆部門清單並預設勾選；右側模式決定可指定人員的範圍。</p>
            <div id="qadept_cfg_container"></div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
            <button type="button" class="btn btn-primary" id="btn-qadept-save">儲存設定</button>
        </div>
    </div></div>
</div>

<!-- 異常單處置決策設定 Modal（品管單位部門／首要決策者／最終決策者；代理人沿用 HR 使用者代理設定） -->
<div class="modal fade" id="qaDecideCfgModal" tabindex="-1" role="dialog">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header" style="background:#2A3F54;color:#fff;border-radius:6px 6px 0 0;">
            <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:.9;"><span>&times;</span></button>
            <h4 class="modal-title"><i class="fa fa-gavel"></i> 異常單處置決策設定</h4>
        </div>
        <div class="modal-body" style="max-height:66vh;overflow-y:auto;">
            <div class="form-group">
                <label>品管單位的部門 <small class="text-muted">（屬這些部門的可判定人員，系統會預設建議為首要決策者）</small></label>
                <div id="qadc_depts" style="max-height:150px;overflow-y:auto;border:1px solid #eee;border-radius:4px;padding:6px 10px;display:grid;grid-template-columns:1fr 1fr;"></div>
            </div>
            <div class="form-group">
                <label>首要決策者 <small class="text-muted">（異常單開立後優先送其判定）</small></label>
                <select class="form-control input-sm" id="qadc_primary"><option value="">請選擇...</option></select>
                <div id="qadc_primary_dep" style="font-size:12px;color:#5a6b7b;margin-top:3px;"></div>
            </div>
            <div class="form-group">
                <label>最終決策者 <small class="text-muted">（首要決策者判定「需最終裁決」或處置含「轉總經理裁示」時通知；裁決寫入總經理裁示欄位）</small></label>
                <select class="form-control input-sm" id="qadc_final"><option value="">請選擇...</option></select>
                <div id="qadc_final_dep" style="font-size:12px;color:#5a6b7b;margin-top:3px;"></div>
            </div>
            <div class="form-group">
                <label>次要決策者 <small class="text-muted">（首要決策者「請假」時，與首要決策者代理人一同收到判定通知、可代為判定；勾選並以 ↑↓ 排序。代理人同時具判定功能者，是否列為次要決策者在此設定）</small></label>
                <div id="qadc_secondary" style="border:1px solid #eee;border-radius:4px;padding:6px 10px;max-height:200px;overflow-y:auto;"></div>
            </div>
            <p class="text-muted" style="font-size:12px;">
                <i class="fa fa-info-circle"></i> 候選名單＝具「勾選/回覆異常處置」功能之人員；<b>屬品管單位者標示【品管】並建議設為首要決策者</b>。
                代理人沿用「人資設定 → 使用者代理設定」（user_delegate，含代理期間與順序），此處僅顯示不可修改。
                決策者當日尚有行程時，系統會同時通知其代理人（附行程時段），任一人判定後其他人即無須處理。
            </p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
            <button type="button" class="btn btn-primary" id="qadc_save"><i class="fa fa-save"></i> 儲存設定</button>
        </div>
    </div></div>
</div>

<!-- 異常單處置決策 Modal（首要判定 / 最終裁決；由決策通知點入 ?decide_abnormal=N） -->
<div class="modal fade" id="qaDecideModal" tabindex="-1" role="dialog" data-backdrop="static">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header" style="background:#c77c1a;color:#fff;border-radius:6px 6px 0 0;">
            <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:.9;"><span>&times;</span></button>
            <h4 class="modal-title"><i class="fa fa-gavel"></i> <span id="qadm_title">處置判定</span> — <span id="qadm_no"></span></h4>
        </div>
        <div class="modal-body" style="max-height:66vh;overflow-y:auto;">
            <table class="table table-bordered" style="font-size:13px;margin-bottom:10px;">
                <tr><th style="width:100px;background:#f8f9fa;">開單人</th><td id="qadm_creator"></td></tr>
                <tr><th style="background:#f8f9fa;">來源</th><td id="qadm_src"></td></tr>
                <tr><th style="background:#f8f9fa;">異常現象</th><td id="qadm_phen" style="white-space:pre-line;"></td></tr>
                <tr><th style="background:#f8f9fa;">原因說明</th><td id="qadm_detail" style="white-space:pre-line;"></td></tr>
                <tr id="qadm_prim_row" style="display:none;"><th style="background:#f8f9fa;">首要判定</th><td id="qadm_prim"></td></tr>
            </table>
            <div class="form-group">
                <label id="qadm_opt_label">處置方式 <span style="color:#d9534f;">*</span></label>
                <div id="qadm_opts" style="display:flex;flex-wrap:wrap;gap:6px;"></div>
            </div>
            <div class="form-group">
                <label id="qadm_note_label">處置說明</label>
                <textarea class="form-control" id="qadm_note" rows="3"></textarea>
            </div>
            <div class="form-group" id="qadm_final_wrap">
                <label style="font-weight:normal;cursor:pointer;"><input type="checkbox" id="qadm_need_final"> 需送「最終決策者」裁決 <small class="text-muted">（勾選「轉總經理裁示」時自動送出）</small></label>
            </div>
            <div style="text-align:right;">
                <a id="qadm_view" target="_blank" style="font-size:12.5px;">開啟異常單完整內容 <i class="fa fa-external-link"></i></a>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
            <button type="button" class="btn btn-primary" id="qadm_submit"><i class="fa fa-check"></i> 送出判定</button>
        </div>
    </div></div>
</div>

<!-- 異常單修改請求 Modal（無修改權限時：通知主管要求開放修改，原因必填） -->
<div class="modal fade" id="qaEditReqModal" tabindex="-1" role="dialog" data-backdrop="static">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header" style="background:#c77c1a;color:#fff;border-radius:6px 6px 0 0;">
            <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:.9;"><span>&times;</span></button>
            <h4 class="modal-title"><i class="fa fa-lock"></i> 無修改權限 — 異常單 <span id="qaer-no"></span></h4>
        </div>
        <div class="modal-body" style="padding:16px 20px;">
            <p>您目前沒有修改此異常單的權限（僅 管理員／QC主管／開單人／共同編輯者 可直接修改）。</p>
            <p><b>是否通知主管，要求開放修改此異常單？</b><br>
               <small class="text-muted">主管核准後僅您本人可修改，其他使用者仍不可修改。</small></p>
            <div class="form-group">
                <label>修改原因 <span style="color:#d9534f;">*</span></label>
                <textarea class="form-control" id="qaer-reason" rows="3" maxlength="255" placeholder="請說明需要修改此異常單的原因（必填）..."></textarea>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-default" data-dismiss="modal">取消</button>
            <button type="button" class="btn btn-warning" id="qaer-send"><i class="fa fa-paper-plane"></i> 通知主管要求開放修改</button>
        </div>
    </div></div>
</div>

<!-- 異常單修改請求核准 Modal（主管由通知點入，可快速開放修改） -->
<div class="modal fade" id="qaEditApproveModal" tabindex="-1" role="dialog" data-backdrop="static">
    <div class="modal-dialog"><div class="modal-content">
        <div class="modal-header" style="background:#2A3F54;color:#fff;border-radius:6px 6px 0 0;">
            <button type="button" class="close" data-dismiss="modal" style="color:#fff;opacity:.9;"><span>&times;</span></button>
            <h4 class="modal-title"><i class="fa fa-unlock-alt"></i> 異常單修改請求 — <span id="qaea-no"></span></h4>
        </div>
        <div class="modal-body" style="padding:16px 20px;">
            <table class="table table-bordered" style="margin-bottom:10px;font-size:13px;">
                <tr><th style="width:110px;background:#f8f9fa;">請求人</th><td id="qaea-requester"></td></tr>
                <tr><th style="background:#f8f9fa;">修改原因</th><td id="qaea-reason" style="white-space:pre-line;"></td></tr>
                <tr><th style="background:#f8f9fa;">提出時間</th><td id="qaea-time"></td></tr>
                <tr><th style="background:#f8f9fa;">狀態</th><td id="qaea-status"></td></tr>
            </table>
            <p class="text-muted" style="font-size:12.5px;"><i class="fa fa-info-circle"></i> 「開放修改」後，<b>僅提出請求的使用者本人</b>可修改此異常單，其他使用者不可修改；所有修改皆會留下編輯記錄。</p>
        </div>
        <div class="modal-footer">
            <a class="btn btn-default pull-left" id="qaea-view" target="_blank"><i class="fa fa-file-text-o"></i> 檢視異常單</a>
            <button type="button" class="btn btn-default" data-dismiss="modal">關閉</button>
            <button type="button" class="btn btn-success" id="qaea-approve"><i class="fa fa-unlock"></i> 開放修改</button>
        </div>
    </div></div>
</div>

<script>
$(function(){
    'use strict';
    var QA_API = '../../src/store/store_QA_Abnormal_API.php';
    var qs = new URLSearchParams(location.search);
    var qaEditAb  = parseInt(qs.get('edit_abnormal') || '', 10);
    var qaEditReq = parseInt(qs.get('edit_request') || '', 10);

    // 由公告/通知「編輯」或核准通知進入：開啟指定異常單的修改畫面（先檢查權限）
    if (qaEditAb){
        $.post(QA_API, {action:'check_edit_perm', id:qaEditAb}, function(r){
            if (!r || !r.success){ alert('無法檢查異常單修改權限：' + ((r && r.message) || '')); return; }
            if (r.can_edit){
                QAAbnormalModal.openEdit(qaEditAb, { title_suffix: r.order_no || '' });
            } else {
                $('#qaer-no').text(r.order_no || ('#' + qaEditAb));
                $('#qaer-reason').val('');
                $('#qaEditReqModal').data('oid', qaEditAb).modal('show');
            }
        }, 'json');
    }

    // ============ 處置決策（首要判定 / 最終裁決；?decide_abnormal=N 由決策通知點入） ============
    var qaDecideAb = parseInt(qs.get('decide_abnormal') || '', 10);
    var qadmStage = 'primary';
    var DISP_OPTS = ['特採','報廢','重工','需矯正','轉總經理裁示'];
    var GM_OPTS = ['特採','重工','報廢','需矯正'];
    function qadmRenderOpts(list, checkedCsv){
        var checked = String(checkedCsv || '').split(/[,、]/).map(function(s){ return s.trim(); });
        $('#qadm_opts').html(list.map(function(o){
            return '<label style="display:flex;align-items:center;gap:4px;padding:4px 10px;border:1px solid #ddd;border-radius:4px;cursor:pointer;font-size:13px;background:#f9f9f9;font-weight:normal;margin:0;">'
                 + '<input type="checkbox" class="qadm-opt" value="'+o+'" '+(checked.indexOf(o)>=0?'checked':'')+' style="margin:0;"> '+o+'</label>';
        }).join(''));
    }
    if (qaDecideAb){
        $.post(QA_API, {action:'get_decide_context', id:qaDecideAb}, function(r){
            if (!r || !r.success){ alert((r && r.message) || '載入決策情境失敗'); return; }
            if (!r.stage){
                if (confirm('此異常單目前沒有待決策事項（可能已完成判定）。要開啟異常單檢視頁嗎？')) window.open('../QA/qa_abnormal_view.php?id=' + qaDecideAb);
                return;
            }
            if (!r.allowed){ alert('您不是此階段的決策者或其代理人，無法判定。'); return; }
            qadmStage = r.stage;
            var o = r.order;
            $('#qadm_no').text(o.no || '');
            $('#qadm_creator').text(o.created_by_name || '');
            $('#qadm_src').text(o.source_desc || '');
            $('#qadm_phen').text(o.phenomenon || '');
            $('#qadm_detail').text(o.defect_detail || '');
            $('#qadm_view').attr('href', '../QA/qa_abnormal_view.php?id=' + o.id);
            if (r.stage === 'final'){
                $('#qadm_title').text('最終裁決');
                $('#qadm_opt_label').html('最終裁決 <small class="text-muted">（寫入總經理裁示欄位）</small>');
                $('#qadm_note_label').text('裁決說明');
                $('#qadm_prim_row').show();
                $('#qadm_prim').text((o.disposition || '').replace(/,/g, '、') + (o.disposition_note ? '｜' + o.disposition_note : ''));
                $('#qadm_final_wrap').hide();
                qadmRenderOpts(GM_OPTS, o.gm_decision);
                $('#qadm_note').val(o.gm_note || '');
            } else {
                $('#qadm_title').text('處置判定');
                $('#qadm_opt_label').html('處置方式 <span style="color:#d9534f;">*</span>');
                $('#qadm_note_label').text('處置說明');
                $('#qadm_prim_row').hide();
                $('#qadm_final_wrap').show();
                $('#qadm_need_final').prop('checked', false);
                qadmRenderOpts(DISP_OPTS, o.disposition);
                $('#qadm_note').val(o.disposition_note || '');
            }
            $('#qaDecideModal').data('oid', o.id).modal('show');
        }, 'json');
    }
    $('#qadm_submit').on('click', function(){
        var oid = $('#qaDecideModal').data('oid');
        var picked = $('.qadm-opt:checked').map(function(){ return this.value; }).get().join(',');
        var note = ($('#qadm_note').val() || '').trim();
        var data = { action:'decide', id:oid, stage:qadmStage };
        if (qadmStage === 'final'){
            if (!picked && !note){ alert('請勾選裁決或填寫裁決說明'); return; }
            data.gm_decision = picked; data.gm_note = note;
        } else {
            if (!picked){ alert('請至少勾選一項處置方式'); return; }
            data.disposition = picked; data.disposition_note = note;
            data.need_final = ($('#qadm_need_final').is(':checked') || picked.indexOf('轉總經理裁示') >= 0) ? 1 : 0;
        }
        var $b = $(this).prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> 送出中…');
        $.post(QA_API, data, function(r){
            $b.prop('disabled', false).html('<i class="fa fa-check"></i> 送出判定');
            if (!r || !r.success){ alert((r && r.message) || '送出失敗'); return; }
            $('#qaDecideModal').modal('hide');
            var msg = (qadmStage === 'final' ? '最終裁決已送出。' : '處置判定已送出。') + '相關人員的決策通知已自動結束。';
            if (r.warn) msg += '\n\n⚠ ' + r.warn;
            alert(msg);
        }, 'json').fail(function(){
            $b.prop('disabled', false).html('<i class="fa fa-check"></i> 送出判定');
            alert('連線失敗');
        });
    });

    // ============ 處置決策設定（品管單位部門／首要／最終決策者） ============
    $('#btn-qadecide-setting').on('click', function(e){
        e.preventDefault();
        $.post(QA_API, {action:'get_decision_setting'}, function(r){
            if (!r || !r.success){ alert('載入設定失敗'); return; }
            var qc = r.qc_dept_ids || [];
            $('#qadc_depts').html((r.departments || []).map(function(d){
                return '<label style="font-weight:normal;margin:0 0 3px;cursor:pointer;">'
                     + '<input type="checkbox" class="qadc-dept" value="'+d.id+'" '+(qc.indexOf(d.id)>=0?'checked':'')+'> '+$('<i>').text(d.name).html()+'</label>';
            }).join(''));
            function poolOpts(sel){
                var h = '<option value="">請選擇...</option>';
                (r.pool || []).forEach(function(p){
                    h += '<option value="'+p.id+'" data-dep="'+$('<i>').text((p.deputies||[]).map(function(x){return x.name;}).join('、')).html()+'">'
                       + (p.in_qc ? '【品管】' : '') + $('<i>').text(p.user_cname).html() + '</option>';
                });
                return h;
            }
            $('#qadc_primary').html(poolOpts()).val(String(r.primary || ''));
            $('#qadc_final').html(poolOpts()).val(String(r.final || ''));
            // ── 次要決策者：勾選＋排序（首要/最終本人不可勾；標示其是否為首要/最終的今日代理人）──
            (function renderSecondary(){
                var byId = {};
                (r.pool || []).forEach(function(p){ byId[p.id] = p; });
                var primDeps = (byId[r.primary] ? (byId[r.primary].deputies || []) : []).map(function(d){ return d.id; });
                var finDeps  = (byId[r.final]   ? (byId[r.final].deputies   || []) : []).map(function(d){ return d.id; });
                var sec = (r.secondary || []).filter(function(id){ return byId[id]; });
                var ordered = sec.concat((r.pool || []).map(function(p){ return p.id; }).filter(function(id){ return sec.indexOf(id) < 0; }));
                $('#qadc_secondary').html(ordered.map(function(id){
                    var p = byId[id];
                    var badge = '';
                    if (primDeps.indexOf(id) >= 0) badge += ' <span class="label label-primary">首要今日代理人</span>';
                    if (finDeps.indexOf(id)  >= 0) badge += ' <span class="label label-warning">最終今日代理人</span>';
                    return '<div class="qadc-sec-row" data-id="'+id+'" style="display:flex;align-items:center;gap:8px;padding:3px 0;border-bottom:1px solid #f5f5f5;">'
                         + '<label style="font-weight:normal;margin:0;cursor:pointer;flex:1;">'
                         + '<input type="checkbox" class="qadc-sec-chk" value="'+id+'" '+(sec.indexOf(id)>=0?'checked':'')+'> '
                         + (p.in_qc ? '【品管】' : '') + $('<i>').text(p.user_cname).html() + badge + '</label>'
                         + '<span class="qadc-sec-mv">'
                         + '<button type="button" class="btn btn-xs btn-default qadc-sec-up"><i class="fa fa-arrow-up"></i></button> '
                         + '<button type="button" class="btn btn-xs btn-default qadc-sec-down"><i class="fa fa-arrow-down"></i></button>'
                         + '</span></div>';
                }).join('') || '<span class="text-muted">尚無可判定人員</span>');
                function applySecDisable(){
                    var pid = String($('#qadc_primary').val() || ''), fid = String($('#qadc_final').val() || '');
                    $('#qadc_secondary .qadc-sec-row').each(function(){
                        var id = String($(this).data('id'));
                        var isPF = (id === pid || id === fid);
                        $(this).find('.qadc-sec-chk').prop('disabled', isPF);
                        if (isPF) $(this).find('.qadc-sec-chk').prop('checked', false);
                        $(this).css('opacity', isPF ? .5 : 1).attr('title', isPF ? '首要/最終決策者本人不需列為次要決策者' : '');
                    });
                }
                applySecDisable();
                $('#qadc_primary, #qadc_final').off('change.sec').on('change.sec', applySecDisable);
            })();
            function showDep(sel, box){
                var dep = $(sel + ' option:selected').data('dep') || '';
                $(box).html(sel && $(sel).val() ? ('今日生效代理人：' + (dep || '（無，可至 人資設定→使用者代理設定 指定）')) : '');
            }
            showDep('#qadc_primary', '#qadc_primary_dep'); showDep('#qadc_final', '#qadc_final_dep');
            $('#qadc_primary').off('change.dep').on('change.dep', function(){ showDep('#qadc_primary', '#qadc_primary_dep'); });
            $('#qadc_final').off('change.dep').on('change.dep', function(){ showDep('#qadc_final', '#qadc_final_dep'); });
            // 預設建議：尚未設定首要時，自動帶入第一位屬品管單位的可判定人員
            if (!r.primary){
                var sug = (r.pool || []).filter(function(p){ return p.in_qc; })[0];
                if (sug) { $('#qadc_primary').val(String(sug.id)).trigger('change.dep'); }
            }
            $('#qadc_save').prop('disabled', !r.can_manage).attr('title', r.can_manage ? '' : '僅主管（角色勾選「認定為主管」）可儲存');
            $('#qaDecideCfgModal').modal('show');
        }, 'json');
    });
    // 次要決策者排序（↑↓ 移整列）
    $(document).on('click', '.qadc-sec-up, .qadc-sec-down', function(){
        var $row = $(this).closest('.qadc-sec-row');
        if ($(this).hasClass('qadc-sec-up')) { var $prev = $row.prev('.qadc-sec-row'); if ($prev.length) $row.insertBefore($prev); }
        else { var $next = $row.next('.qadc-sec-row'); if ($next.length) $row.insertAfter($next); }
    });
    $('#qadc_save').on('click', function(){
        var qcDepts = $('.qadc-dept:checked').map(function(){ return parseInt(this.value, 10); }).get();
        var primaryId = $('#qadc_primary').val() || 0, finalId = $('#qadc_final').val() || 0;
        if (primaryId && primaryId === finalId){ alert('首要決策者與最終決策者不可為同一人'); return; }
        var secondary = $('#qadc_secondary .qadc-sec-chk:checked').map(function(){ return parseInt(this.value, 10); }).get();
        var $b = $(this).prop('disabled', true);
        $.post(QA_API, {action:'save_decision_setting', qc_dept_ids: JSON.stringify(qcDepts), primary: primaryId, final: finalId, secondary: JSON.stringify(secondary)}, function(r){
            $b.prop('disabled', false);
            if (!r || !r.success){ alert((r && r.message) || '儲存失敗'); return; }
            $('#qaDecideCfgModal').modal('hide');
            alert('處置決策設定已儲存');
        }, 'json').fail(function(){ $b.prop('disabled', false); alert('連線失敗'); });
    });

    // 送出修改請求（原因必填）→ 系統自動通知主管（主管通知內含快速開放修改按鈕）
    $('#qaer-send').on('click', function(){
        var oid = $('#qaEditReqModal').data('oid');
        var reason = ($('#qaer-reason').val() || '').trim();
        if (!reason){ alert('請填寫修改原因（必填）'); $('#qaer-reason').focus(); return; }
        var $btn = $(this).prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> 送出中…');
        $.post(QA_API, {action:'request_edit', id:oid, reason:reason}, function(r){
            $btn.prop('disabled', false).html('<i class="fa fa-paper-plane"></i> 通知主管要求開放修改');
            if (!r || !r.success){ alert('送出失敗：' + ((r && r.message) || '')); return; }
            $('#qaEditReqModal').modal('hide');
            if (r.no_supervisor){ alert(r.message || '目前尚無角色被勾選「認定為主管」，請洽管理員設定。'); }
            else alert('已通知主管。主管開放修改後，您會收到通知（點通知可直接進入修改畫面）。');
        }, 'json').fail(function(){
            $btn.prop('disabled', false).html('<i class="fa fa-paper-plane"></i> 通知主管要求開放修改');
            alert('連線失敗');
        });
    });

    // 主管由通知點入：載入修改請求並提供快速「開放修改」
    if (qaEditReq){
        $.post(QA_API, {action:'get_edit_request', id:qaEditReq}, function(r){
            if (!r || !r.success){ alert((r && r.message) || '載入修改請求失敗'); return; }
            var d = r.data;
            $('#qaea-no').text(d.abnormal_order_no || '');
            $('#qaea-requester').text(d.requester_name || ('#' + d.requested_by));
            $('#qaea-reason').text(d.reason || '');
            $('#qaea-time').text(d.created_at || '');
            $('#qaea-view').attr('href', '../QA/qa_abnormal_view.php?id=' + d.abnormal_order_id);
            if (d.status === 'approved'){
                $('#qaea-status').html('<span class="label label-success">已開放</span> ' + (d.approver_name ? '（' + d.approver_name + '）' : ''));
                $('#qaea-approve').prop('disabled', true).text('已開放修改');
            } else {
                $('#qaea-status').html('<span class="label label-warning">待處理</span>');
                $('#qaea-approve').prop('disabled', !d.is_supervisor);
                if (!d.is_supervisor) $('#qaea-approve').attr('title', '僅主管可開放修改');
            }
            $('#qaEditApproveModal').data('rid', qaEditReq).modal('show');
        }, 'json');
    }

    // ============ 異常單回覆部門設定（自 IR_Track 移入） ============
    $('#btn-qadept-setting').on('click', function(e){
        e.preventDefault();
        var $c = $('#qadept_cfg_container').html('<span class="text-muted">載入中…</span>');
        $.post(QA_API, { action:'get_all_depts' }, function(r1){
            var depts = (r1 && r1.data) || [];
            $.post(QA_API, { action:'get_dept_config' }, function(r2){
                var cfgMap = {};
                if (r2 && r2.success && r2.config) r2.config.forEach(function(c){ cfgMap[c.id] = c.mode; });
                var h = '';
                depts.forEach(function(d){
                    var on = cfgMap.hasOwnProperty(d.id);
                    var mode = on ? cfgMap[d.id] : 0;
                    h += '<div class="row" style="margin:0;border-bottom:1px solid #f0f0f0;padding:5px 0;">'
                       + '<div class="col-xs-6"><label style="font-weight:normal;margin:0;cursor:pointer;">'
                       + '<input type="checkbox" class="qadept-cfg-chk" value="'+d.id+'" '+(on?'checked':'')+'> '+$('<i>').text(d.name).html()+'</label></div>'
                       + '<div class="col-xs-6"><select class="form-control input-sm qadept-cfg-mode" style="display:'+(on?'block':'none')+';">'
                       + '<option value="0"'+(mode==0?' selected':'')+'>本部門</option>'
                       + '<option value="1"'+(mode==1?' selected':'')+'>含下級部門</option>'
                       + '<option value="2"'+(mode==2?' selected':'')+'>僅下級主管</option>'
                       + '</select></div></div>';
                });
                $c.html(h || '<span class="text-muted">尚無部門資料</span>');
                $('#qaDeptCfgModal').modal('show');
            }, 'json');
        }, 'json');
    });
    $(document).on('change', '.qadept-cfg-chk', function(){
        $(this).closest('.row').find('.qadept-cfg-mode').toggle(this.checked);
    });
    $('#btn-qadept-save').on('click', function(){
        var depts = [];
        $('.qadept-cfg-chk:checked').each(function(){
            depts.push({ dept_id: $(this).val(), mode: $(this).closest('.row').find('.qadept-cfg-mode').val() || 0 });
        });
        var $b = $(this).prop('disabled', true);
        $.post(QA_API, { action:'save_dept_config', depts: JSON.stringify(depts) }, function(res){
            $b.prop('disabled', false);
            if (res && res.success){ $('#qaDeptCfgModal').modal('hide'); alert('可用部門設定已儲存'); }
            else alert('儲存失敗：' + ((res && res.message) || ''));
        }, 'json').fail(function(){ $b.prop('disabled', false); alert('連線失敗'); });
    });

    // 開放修改（僅主管；核准後通知請求者，且僅該使用者可修改）
    $('#qaea-approve').on('click', function(){
        var rid = $('#qaEditApproveModal').data('rid');
        if (!confirm('確認開放修改？（僅提出請求的使用者本人可修改此異常單）')) return;
        var $btn = $(this).prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> 處理中…');
        $.post(QA_API, {action:'approve_edit_request', id:rid}, function(r){
            if (!r || !r.success){
                $btn.prop('disabled', false).html('<i class="fa fa-unlock"></i> 開放修改');
                alert('開放失敗：' + ((r && r.message) || ''));
                return;
            }
            $btn.html('<i class="fa fa-check"></i> 已開放修改');
            $('#qaea-status').html('<span class="label label-success">已開放</span>');
            alert('已開放修改並通知提出請求的使用者。');
        }, 'json').fail(function(){
            $btn.prop('disabled', false).html('<i class="fa fa-unlock"></i> 開放修改');
            alert('連線失敗');
        });
    });
});
</script>

<script>
$(function(){
    'use strict';
    // ★ 後端沿用舊頁的 AJAX API（同 session / 同 CSRF / 同 RBAC / 同後端重算判定）
    var API  = 'inspection_combined_prototype.php';
    var CSRF = <?php echo json_encode($CSRF, JSON_UNESCAPED_SLASHES); ?>;
    $.ajaxPrefilter(function(opts){
        var m = (opts.type || opts.method || 'GET').toUpperCase();
        if (m !== 'POST') return;
        if (typeof opts.data === 'string'){
            if (opts.data.indexOf('csrf=') === -1) opts.data += (opts.data ? '&' : '') + 'csrf=' + encodeURIComponent(CSRF);
        } else if (opts.data && typeof opts.data === 'object' && !(opts.data instanceof FormData)){
            if (opts.data.csrf === undefined) opts.data.csrf = CSRF;
        } else if (opts.data == null){ opts.data = { csrf: CSRF }; }
    });
    $('body').append('<div id="print-area"></div>');
    // 底部固定列會遮住內容（現場回報「下一項」按鈕被蓋住）→ 依 dock 實際高度撐出底部空白
    function syncDockPad(){
        var h = $('#dock').is(':visible') ? $('#dock').outerHeight() : 0;
        $('body').css('padding-bottom', (h+24)+'px');
    }
    $(window).on('resize', syncDockPad);

    // =====================================================================
    // 狀態與資料模型
    //   MODEL.items[i] = { item_id,name,std,up,lo,type,remark,
    //                      readings:[ {tool_id, tool_cat, vals:[每件一格的原始輸入]} ] }
    //   readings[0] = 主量測；readings[1..] = 「加量測」（同尺寸換量具/方法再量一次）
    //   MODEL.pcs[i]  = { v:'OK'|'NG', m:0|1 }  m=1 代表使用者手動改判
    //   ★ 三種檢視都只是這份模型的不同畫法，切換檢視不會遺失任何已填內容。
    // =====================================================================
    var ctx = null;
    var state = { sampleN:5, batches:[], curBatch:0, processes:[], curProc:0, demo:false,
                  is_supervisor:false, can_fill:true, canManageSettings:false, canManageSampling:false,
                  canView:true, editFormId:null, draftFormId:0 };
    var MODEL = { items:[], pcs:[] };
    var TOOLS = ['卡尺','分厘卡','投影機','三次元','針規','目視'];
    var TOOL_INSTANCES = [];                                  // [{id,no,cat}]
    var view      = localStorage.getItem('qc2_view')   || 'ITEM';   // ITEM / PCS / GRID
    var keypadOn  = localStorage.getItem('qc2_keypad') === '1';
    var codeMode  = localStorage.getItem('qc_item_code_mode') || 'ALPHA';
    var focusItem = 0, focusPcs = 0;
    var lastFocused = null;                                   // 給數字鍵盤用

    function esc(s){ return (s==null?'':String(s)).replace(/[&<>"]/g,function(c){ return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'})[c]; }); }
    function codeLabel(i){
        if (codeMode !== 'ALPHA') return String(i+1);
        var s='', n=i+1;
        while(n>0){ var r=(n-1)%26; s=String.fromCharCode(65+r)+s; n=Math.floor((n-1)/26); }
        return s;
    }
    function trimNum(v){ // 小數尾 0 省略（3.50→3.5）
        if(v===''||v==null) return '';
        var s=String(v); if(s.indexOf('.')<0) return s;
        s=s.replace(/0+$/,'').replace(/\.$/,''); return s===''||s==='-' ? '0' : s;
    }
    function blankVals(n, def){ var a=[]; for(var i=0;i<n;i++) a.push(def||''); return a; }

    // ---------- 判定（與後端 qc_inspection_lib 同一套規則；後端仍會重算為準） ----------
    function judge(it, raw){
        if(raw===''||raw==null) return '';
        if(it.type==='OKNG') return (raw==='NG') ? 'NG' : 'OK';
        var base=parseFloat(it.std), up=parseFloat(it.up), lo=parseFloat(it.lo), v=parseFloat(raw);
        if(isNaN(v)||isNaN(base)) return '';
        var hi=base+(isNaN(up)?0:up), low=base+(isNaN(lo)?0:lo);
        return (v>hi||v<low) ? 'NG' : 'OK';
    }
    function limits(it){
        var base=parseFloat(it.std);
        if(isNaN(base)) return null;
        var up=parseFloat(it.up), lo=parseFloat(it.lo);
        return { hi:base+(isNaN(up)?0:up), low:base+(isNaN(lo)?0:lo), base:base };
    }
    // 偏差量：告訴現場「離標準多少、超出多少」，不必自己心算（痛點④）
    // compact＝總表用的短版（格子窄，長文字會被切掉）：▲=超上限、▼=超下限
    function devText(it, raw, compact){
        if(it.type==='OKNG') return '';
        if(raw===''||raw==null) return '';
        var L=limits(it), v=parseFloat(raw);
        if(!L||isNaN(v)) return '';
        var d=v-L.base, sign=(d>0?'+':'');
        if(v>L.hi) return (compact?'▲':'超上限 ')+trimNum((v-L.hi).toFixed(4));
        if(v<L.low) return (compact?'▼':'超下限 ')+trimNum((L.low-v).toFixed(4));
        return sign+trimNum(d.toFixed(4));
    }
    function itemVerdict(it){
        var any=false, filled=false;
        it.readings.forEach(function(rd){
            rd.vals.forEach(function(v){
                var j=judge(it,v);
                if(j){ filled=true; if(j==='NG') any=true; }
            });
        });
        return any ? 'NG' : (filled ? 'OK' : '');
    }
    function itemFilledCount(it){
        var n=0;
        for(var s=0;s<state.sampleN;s++){
            for(var r=0;r<it.readings.length;r++){ if(it.readings[r].vals[s]!=='' && it.readings[r].vals[s]!=null){ n++; break; } }
        }
        return n;
    }
    function pcsAutoNG(s){
        for(var i=0;i<MODEL.items.length;i++){
            var it=MODEL.items[i];
            for(var r=0;r<it.readings.length;r++){ if(judge(it, it.readings[r].vals[s])==='NG') return true; }
        }
        return false;
    }

    // =====================================================================
    // 量具（實例）：值＝Tool_id，顯示「類型 / 編號」，可追溯到實際那一支
    // =====================================================================
    function loadToolInstances(){
        $.post(API, { action:'get_tool_manage_data' }, function(res){
            if(!res || !res.success) return;
            var cats={}; (res.categories||[]).forEach(function(c){ cats[c.QC_Tool_List_id]=c.QC_Tool; });
            TOOL_INSTANCES = (res.tools||[]).map(function(t){ return { id:String(t.Tool_id), no:t.Tool_No, cat:cats[t.QC_Tool_List_id]||'' }; });
            render();
        }, 'json');
    }
    function toolInstById(id){
        if(id==null || id==='') return null;
        for(var i=0;i<TOOL_INSTANCES.length;i++){ if(TOOL_INSTANCES[i].id===String(id)) return TOOL_INSTANCES[i]; }
        return null;
    }
    // 量具改用「按鈕 → 跳窗挑」：下拉選單選項太多又擠，現場很難點（2026-07-29 回饋）
    function toolBtn(i, r){
        var t = toolInstById(MODEL.items[i].readings[r].tool_id);
        return '<button type="button" class="tool-btn '+(t?'':'none')+'" data-i="'+i+'" data-r="'+r+'" title="點此選擇量具">'+
               (t ? '<span class="tcat">'+esc(t.cat||'量具')+'</span><span class="tno">'+esc(t.no)+'</span>'
                  : '<i class="fa fa-wrench"></i> 點此選擇量具')+'</button>';
    }
    function firstInstOfCat(catName){
        if(!catName) return '';
        for(var i=0;i<TOOL_INSTANCES.length;i++){ if(TOOL_INSTANCES[i].cat===catName) return TOOL_INSTANCES[i].id; }
        return '';
    }
    function toolLabelById(id){
        if(!id) return '';
        for(var i=0;i<TOOL_INSTANCES.length;i++){ if(TOOL_INSTANCES[i].id===String(id)) return (TOOL_INSTANCES[i].cat?TOOL_INSTANCES[i].cat+' / ':'')+TOOL_INSTANCES[i].no; }
        return '';
    }
    function refreshToolSelects(){ render(); }   // 相容：量具設定存檔後重繪

    // =====================================================================
    // 模型 ←→ 後端資料轉換
    // =====================================================================
    function normItem(r){
        var it = { item_id:(r.item_id||''), name:(r.name||''), std:(r.std==null?'':String(r.std)),
                   up:(r.up==null?'':String(r.up)), lo:(r.lo==null?'':String(r.lo)),
                   type:(r.type==='OKNG'?'OKNG':'NUM'), remark:(r.remark||''), readings:[] };
        var tid = (r.tool_id!=null && r.tool_id!=='') ? String(r.tool_id) : firstInstOfCat(r.tool||'');
        it.readings.push({ tool_id:tid, tool_cat:(r.tool||''), vals:valsFrom(r.samples, it.type) });
        (r.extra||[]).forEach(function(ex){
            it.readings.push({ tool_id:(ex.tool_id!=null && ex.tool_id!=='' ? String(ex.tool_id) : ''),
                               tool_cat:(ex.method||''), vals:valsFrom(ex.samples, it.type) });
        });
        return it;
    }
    function valsFrom(samples, type){
        // OK/NG 型沿用舊行為：未特別標示者視為 OK（現場只在不良時才標記）
        var a = blankVals(state.sampleN, type==='OKNG' ? 'OK' : '');
        (samples||[]).forEach(function(s,i){
            if(i>=a.length) return;
            var v = (s && typeof s==='object') ? s.v : s;
            a[i] = (v==null) ? '' : String(v);
        });
        return a;
    }
    function newItem(){
        return { item_id:'', name:'', std:'', up:'', lo:'', type:'NUM', remark:'',
                 readings:[{ tool_id:'', tool_cat:'', vals:blankVals(state.sampleN) }] };
    }
    function setSampleN(n){
        n = Math.max(1, parseInt(n)||1);
        if(n===state.sampleN) return;
        state.sampleN = n;
        MODEL.items.forEach(function(it){
            it.readings.forEach(function(rd){
                var def = (it.type==='OKNG') ? 'OK' : '';
                while(rd.vals.length<n) rd.vals.push(def);
                rd.vals.length = n;
            });
        });
        MODEL.pcs.length = 0;
        ensurePcs();
        if(focusPcs>=n) focusPcs=n-1;
        render();
    }
    function ensurePcs(){
        while(MODEL.pcs.length < state.sampleN) MODEL.pcs.push({ v:'OK', m:0 });
        MODEL.pcs.length = state.sampleN;
    }

    // 相容介面（供沿用自舊頁的「通用樣板管理」等程式碼呼叫）
    function renderItems(items){
        MODEL.items = (items||[]).map(normItem);
        MODEL.pcs = []; ensurePcs();
        focusItem = 0; focusPcs = 0;
        render();
    }
    // 送出用：完全維持舊頁 payload 格式（後端不用改）
    // 差異：數值型不再丟掉空格（改送完整長度陣列），空值後端會略過，
    //       修正舊版「第1件沒量、第2件的值被存成 sample_no=1」的錯位問題。
    function collectItems(){
        var out=[];
        MODEL.items.forEach(function(it){
            var name=(it.name||'').trim();
            if(!name) return;
            var mk=function(rd){
                var arr=[];
                for(var s=0;s<state.sampleN;s++){
                    var raw=rd.vals[s];
                    if(raw===''||raw==null){ arr.push({v:'', r:'OK'}); continue; }
                    var j=judge(it,raw);
                    arr.push({ v:String(raw), r:(j==='NG'?'NG':'OK') });
                }
                return arr;
            };
            var extra=[];
            for(var r=1;r<it.readings.length;r++){
                var rd=it.readings[r], s2=mk(rd);
                var any=s2.some(function(x){ return x.v!==''; });
                if(any || rd.tool_id) extra.push({ tool_id:rd.tool_id||'', samples:s2 });
            }
            out.push({
                item_id:it.item_id||'', name:name, std:it.std, up:it.up, lo:it.lo,
                tool_id:(it.readings[0].tool_id||''), tool:'',
                type:it.type, verdict:(itemVerdict(it)==='NG'?'NG':'OK'),
                samples:mk(it.readings[0]), extra:extra, remark:it.remark||''
            });
        });
        return out;
    }
    function collectPcsVerdicts(){ ensurePcs(); return MODEL.pcs.map(function(p){ return { v:p.v, m:p.m?1:0 }; }); }

    // =====================================================================
    // 繪製
    // =====================================================================
    function render(){
        ensurePcs();
        $('.view-switch button').removeClass('on').filter('[data-view="'+view+'"]').addClass('on');
        $('#view-item').toggle(view==='ITEM');
        $('#view-pcs').toggle(view==='PCS');
        $('#view-grid').toggle(view==='GRID');
        $('#view-hint').text(view==='ITEM' ? '一次專注一個尺寸：量完所有件再換下一個尺寸（換量具最少）'
                          : view==='PCS'  ? '一次專注一件：把該件所有尺寸量完再換下一件'
                                          : '格狀總表：Enter／方向鍵可連續輸入，適合桌機');
        if(view==='ITEM') renderItemView();
        else if(view==='PCS') renderPcsView();
        renderGrid();               // 總表恆繪（隱藏時也在 DOM，供列印/樣板等功能取用）
        $('#btn-code-mode').text(codeMode==='ALPHA' ? '編號：A,B,C…（點此切換）' : '編號：1,2,3…（點此切換）');
        recalc();
    }

    // ---------- 量測格 ----------
    function cellHtml(it, i, r, s, big){
        var raw = it.readings[r].vals[s];
        var cls, inner;
        if(it.type==='OKNG'){
            var v = (raw==='NG') ? 'NG' : (raw===''||raw==null ? '' : 'OK');
            cls = v==='NG' ? 'c-ng' : (v==='OK' ? 'c-ok' : 'c-empty');
            inner = '<span class="mtxt">'+(v==='NG'?'✘ NG':(v==='OK'?'✔ OK':'—'))+'</span>';
            return '<div class="mcell okng '+cls+'" data-i="'+i+'" data-r="'+r+'" data-s="'+s+'" tabindex="0" title="點擊切換 OK / NG">'+
                   '<span class="mno">#'+(s+1)+'</span>'+inner+'</div>';
        }
        var j = judge(it, raw);
        cls = j==='NG' ? 'c-ng' : (j==='OK' ? 'c-ok' : 'c-empty');
        return '<div class="mcell '+cls+'" data-i="'+i+'" data-r="'+r+'" data-s="'+s+'">'+
               '<span class="mno">#'+(s+1)+'</span>'+
               '<input type="text" inputmode="decimal" class="mval" value="'+esc(raw)+'" '+
                      'data-i="'+i+'" data-r="'+r+'" data-s="'+s+'">'+
               '<span class="mdev">'+esc(devText(it,raw,!big))+'</span></div>';
    }
    // 只更新單一格的外觀，避免重繪打斷輸入焦點
    function paintCell($cell){
        var i=+$cell.data('i'), r=+$cell.data('r'), s=+$cell.data('s');
        var it=MODEL.items[i]; if(!it) return;
        var raw=it.readings[r].vals[s];
        var j = (it.type==='OKNG') ? ((raw==='NG')?'NG':(raw===''?'':'OK')) : judge(it, raw);
        var repaint=function($c){
            var compact = $c.closest('#items-table').length>0;   // 總表格子窄→用短版偏差文字
            $c.removeClass('c-ok c-ng c-empty').addClass(j==='NG'?'c-ng':(j==='OK'?'c-ok':'c-empty'));
            if(it.type==='OKNG') $c.find('.mtxt').text(j==='NG'?'✘ NG':(j==='OK'?'✔ OK':'—'));
            else $c.find('.mdev').text(devText(it, raw, compact));
        };
        repaint($cell);
        // 同一格在別的檢視也要同步（總表恆在 DOM）
        $('.mcell[data-i="'+i+'"][data-r="'+r+'"][data-s="'+s+'"]').not($cell).each(function(){
            $(this).find('.mval').val(raw);
            repaint($(this));
        });
        updateItemVerdictCell(i);
    }
    // 總表「判定」欄：輸入後要立刻跟著變（原本只在整頁重繪時才更新，會停在「—」）
    function updateItemVerdictCell(i){
        var it=MODEL.items[i]; if(!it) return;
        var v=itemVerdict(it);
        $('#items-body td.g-verdict[data-i="'+i+'"]')
            .css('color', v==='NG'?'var(--coral)':'var(--ink)')
            .text(v==='NG'?'✘ NG':(v==='OK'?'✔ OK':'—'));
    }

    // ---------- 規格帶（標準/上限/下限/量具） ----------
    function specBar(it, i){
        var h='<div class="specbar">';
        if(it.type==='OKNG'){
            h+='<div class="spec std"><div class="k">判定方式</div><div class="v">OK / NG</div></div>'+
               '<div class="spec lim" style="min-width:200px;"><div class="k">判定基準</div><div class="v" style="font-size:15px;">'+esc(it.std||'目視/功能檢查')+'</div></div>';
        } else {
            var L=limits(it);
            h+='<div class="spec std"><div class="k">標準值</div><div class="v">'+esc(trimNum(it.std)||'—')+'</div></div>'+
               '<div class="spec lim"><div class="k">上限（'+esc(it.up||'0')+'）</div><div class="v">'+(L?trimNum(L.hi.toFixed(4)):'—')+'</div></div>'+
               '<div class="spec lim"><div class="k">下限（'+esc(it.lo||'0')+'）</div><div class="v">'+(L?trimNum(L.low.toFixed(4)):'—')+'</div></div>';
        }
        h+='<div class="spec tool"><div class="k">量具（可追溯編號）</div><div class="v">'+toolBtn(i,0)+'</div></div>';
        h+='</div>';
        return h;
    }

    // ---------- 檢視 A：逐項（預設） ----------
    function renderItemView(){
        var $p=$('#view-item');
        if(!MODEL.items.length){ $p.html(emptyHint()); return; }
        if(focusItem>=MODEL.items.length) focusItem=MODEL.items.length-1;
        var it=MODEL.items[focusItem], i=focusItem;

        var chips='<div class="chips">'+MODEL.items.map(function(x,ix){
            var v=itemVerdict(x), c=itemFilledCount(x);
            return '<span class="chip jump-item '+(ix===focusItem?'on':'')+'" data-ix="'+ix+'">'+
                   '<span class="dot '+(v==='NG'?'ng':(v==='OK'?'ok':''))+'"></span>'+codeLabel(ix)+' '+esc(x.name||'（未命名）')+
                   '<span class="cnt">'+c+'/'+state.sampleN+'</span></span>';
        }).join('')+'</div>';

        var h='<div class="fcard"><div class="fcard-hd">'+
              '<span class="idx">'+codeLabel(i)+'</span><span class="nm">'+esc(it.name||'（未命名項目）')+'</span>'+
              '<span class="pull-right" style="margin-top:4px;">'+
                '<button class="btn btn-xs btn-default btn-edit-std" data-i="'+i+'"><i class="fa fa-pencil"></i> 改標準</button> '+
                '<button class="btn btn-xs btn-default btn-add-reading" data-i="'+i+'" title="同尺寸再用其他量具/方法量一次（如三次元＋投影機）"><i class="fa fa-plus"></i> 加量測</button> '+
                '<button class="btn btn-xs btn-default btn-del-item" data-i="'+i+'"><i class="fa fa-trash"></i></button>'+
              '</span></div><div class="fcard-bd">';
        h+=specBar(it,i);
        h+='<div class="cells">';
        for(var s=0;s<state.sampleN;s++) h+=cellHtml(it,i,0,s,true);
        h+='</div>';
        for(var r=1;r<it.readings.length;r++){
            h+='<div class="rdbox"><div class="rdhd"><b>加量測 '+r+'</b>'+
               '<span style="min-width:220px;display:inline-block;">'+toolBtn(i,r)+'</span>'+
               '<a href="#" class="btn-del-reading" data-i="'+i+'" data-r="'+r+'" style="color:var(--coral);"><i class="fa fa-trash"></i> 移除</a></div><div class="cells">';
            for(var s2=0;s2<state.sampleN;s2++) h+=cellHtml(it,i,r,s2,true);
            h+='</div></div>';
        }
        h+='<div style="margin-top:12px;"><label class="muted-help">本項備註（如「毛邊已修」）</label>'+
           '<input type="text" class="form-control input-sm f-remark" data-i="'+i+'" value="'+esc(it.remark||'')+'" placeholder="選填"></div>';
        h+='</div><div class="fcard-hd" style="border-top:1px solid var(--line);border-bottom:0;border-radius:0 0 10px 10px;">'+
           '<button class="btn btn-warm-o nav-prev" '+(i<=0?'disabled':'')+'><i class="fa fa-arrow-left"></i> 上一項</button> '+
           '<span class="muted-help">第 '+(i+1)+' / '+MODEL.items.length+' 項</span> '+
           '<button class="btn btn-warm nav-next pull-right">'+(i>=MODEL.items.length-1?'完成，回總表':'下一項')+' <i class="fa fa-arrow-right"></i></button>'+
           '</div></div>';
        $p.html(chips+h);
    }

    // ---------- 檢視 B：逐件 ----------
    function renderPcsView(){
        var $p=$('#view-pcs');
        if(!MODEL.items.length){ $p.html(emptyHint()); return; }
        if(focusPcs>=state.sampleN) focusPcs=state.sampleN-1;
        var s=focusPcs;
        var chips='<div class="chips">';
        for(var k=0;k<state.sampleN;k++){
            var ng=pcsAutoNG(k), any=false;
            MODEL.items.forEach(function(it){ if(judge(it,it.readings[0].vals[k])) any=true; });
            chips+='<span class="chip jump-pcs '+(k===focusPcs?'on':'')+'" data-ix="'+k+'">'+
                   '<span class="dot '+(ng?'ng':(any?'ok':''))+'"></span>第 '+(k+1)+' 件</span>';
        }
        chips+='</div>';

        var h='<div class="fcard"><div class="fcard-hd"><span class="idx">'+(s+1)+'</span>'+
              '<span class="nm">第 '+(s+1)+' 件（共 '+state.sampleN+' 件）</span></div><div class="fcard-bd">';
        MODEL.items.forEach(function(it,i){
            var L=limits(it);
            var spec = it.type==='OKNG' ? esc(it.std||'目視/功能檢查')
                     : (esc(trimNum(it.std)||'—')+'　'+(L?('['+trimNum(L.low.toFixed(4))+' ~ '+trimNum(L.hi.toFixed(4))+']'):''));
            h+='<div class="prow"><div class="pnm"><div class="n">'+codeLabel(i)+'　'+esc(it.name||'（未命名）')+'</div>'+
               '<div class="s">'+spec+'　<span style="color:#a08a6d;">'+esc(toolLabelById(it.readings[0].tool_id))+'</span></div></div>'+
               '<div class="pin">'+cellHtml(it,i,0,s,true)+'</div></div>';
            for(var r=1;r<it.readings.length;r++){
                h+='<div class="prow" style="padding-left:24px;background:#FBF7F1;"><div class="pnm"><div class="s">↳ 加量測 '+r+'：'+esc(toolLabelById(it.readings[r].tool_id)||'未指定量具')+'</div></div>'+
                   '<div class="pin">'+cellHtml(it,i,r,s,true)+'</div></div>';
            }
        });
        h+='</div><div class="fcard-hd" style="border-top:1px solid var(--line);border-bottom:0;border-radius:0 0 10px 10px;">'+
           '<button class="btn btn-warm-o nav-prev" '+(s<=0?'disabled':'')+'><i class="fa fa-arrow-left"></i> 上一件</button> '+
           '<span class="muted-help">第 '+(s+1)+' / '+state.sampleN+' 件</span> '+
           '<button class="btn btn-warm nav-next pull-right">'+(s>=state.sampleN-1?'完成，回總表':'下一件')+' <i class="fa fa-arrow-right"></i></button>'+
           '</div></div>';
        $p.html(chips+h);
    }
    function emptyHint(){
        return '<div class="warm-panel text-center" style="padding:34px;color:#8a6a45;">'+
               '<i class="fa fa-list-alt" style="font-size:34px;"></i><br><br>尚未建立檢驗項目。<br>'+
               '請按下方「新增檢驗項目」或「匯入通用樣板」。</div>';
    }

    // ---------- 檢視 C：總表（標準欄預設收合＝只剩 4 欄，不再左右捲） ----------
    function renderGrid(){
        var stdEdit = $('#chk-std-edit').is(':checked');
        var head = '<th width="46">編號</th>';
        if(stdEdit){
            head += '<th width="150">檢驗項目</th><th width="82">標準值</th><th width="70">上公差</th><th width="70">下公差</th>'+
                    '<th width="140">量具</th><th width="76">型態</th>';
        } else {
            head += '<th width="190">檢驗項目</th><th width="180">標準（上/下限）</th><th width="150">量具</th>';
        }
        head += '<th>實測值（每件一格）</th><th width="70">判定</th><th width="48"></th>';
        $('#grid-head').html(head);
        var colsBefore = stdEdit ? 8 : 5;
        $('#verdict-label').attr('colspan', colsBefore-1);

        var body='';
        MODEL.items.forEach(function(it,i){
            var L=limits(it), v=itemVerdict(it);
            body += '<tr data-i="'+i+'"><td class="text-center">'+codeLabel(i)+'</td>';
            if(stdEdit){
                body += '<td><input class="table-input f-name" data-i="'+i+'" value="'+esc(it.name)+'"></td>'+
                        '<td><input class="table-input f-std" data-i="'+i+'" value="'+esc(it.std)+'"></td>'+
                        '<td><input class="table-input f-up" data-i="'+i+'" value="'+esc(it.up)+'" '+(it.type==='OKNG'?'readonly':'')+'></td>'+
                        '<td><input class="table-input f-lo" data-i="'+i+'" value="'+esc(it.lo)+'" '+(it.type==='OKNG'?'readonly':'')+'></td>'+
                        '<td>'+toolBtn(i,0)+'</td>'+
                        '<td><select class="table-input f-type" data-i="'+i+'">'+
                          '<option value="NUM" '+(it.type==='NUM'?'selected':'')+'>數值</option>'+
                          '<option value="OKNG" '+(it.type==='OKNG'?'selected':'')+'>OK/NG</option></select></td>';
            } else {
                body += '<td class="g-name">'+esc(it.name||'（未命名）')+
                            (it.remark?' <i class="fa fa-comment-o" title="'+esc(it.remark)+'"></i>':'')+'</td>'+
                        '<td class="g-spec">'+(it.type==='OKNG' ? esc(it.std||'OK/NG')
                            : (esc(trimNum(it.std)||'—')+(L?('<br><span class="muted-help">'+trimNum(L.low.toFixed(4))+' ~ '+trimNum(L.hi.toFixed(4))+'</span>'):'')))+'</td>'+
                        '<td>'+toolBtn(i,0)+'</td>';
            }
            var cells=''; for(var s=0;s<state.sampleN;s++) cells+=cellHtml(it,i,0,s,false);
            body += '<td><div class="gcells">'+cells+'</div></td>'+
                    '<td class="text-center g-verdict" data-i="'+i+'" style="font-weight:bold;color:'+(v==='NG'?'var(--coral)':'var(--ink)')+'">'+(v==='NG'?'✘ NG':(v==='OK'?'✔ OK':'—'))+'</td>'+
                    '<td class="text-center" style="white-space:nowrap">'+
                      '<i class="fa fa-plus btn-add-reading" data-i="'+i+'" style="cursor:pointer;color:var(--amber-d)" title="加量測"></i> '+
                      '<i class="fa fa-comment-o btn-item-note" data-i="'+i+'" style="cursor:pointer;color:'+(it.remark?'var(--amber-d)':'#bbb')+'" title="本項備註"></i> '+
                      '<i class="fa fa-trash btn-del-item" data-i="'+i+'" style="cursor:pointer;color:var(--coral)"></i></td></tr>';
            for(var r=1;r<it.readings.length;r++){
                var sub=''; for(var s3=0;s3<state.sampleN;s3++) sub+=cellHtml(it,i,r,s3,false);
                // 對齊主列欄位：空(編號) + 併格(項目…) + 量具 + [型態空格] + 實測 + 判定空 + 動作
                var toolCol = stdEdit ? 6 : 4;            // 量具是第幾欄
                var afterTool = (colsBefore - toolCol - 1); // 量具與實測值之間還有幾欄（型態）
                body += '<tr style="background:#FBF7F1;"><td></td><td colspan="'+(toolCol-2)+'" class="text-right muted-help">↳ 加量測 '+r+'</td>'+
                        '<td>'+toolBtn(i,r)+'</td>'+ (afterTool>0 ? '<td></td>' : '') +
                        '<td><div class="gcells">'+sub+'</div></td><td></td>'+
                        '<td class="text-center"><i class="fa fa-trash btn-del-reading" data-i="'+i+'" data-r="'+r+'" style="cursor:pointer;color:var(--coral)"></i></td></tr>';
            }
        });
        $('#items-body').html(body || '<tr><td colspan="'+(colsBefore+2)+'" class="text-center muted-help" style="padding:24px;">尚無檢驗項目</td></tr>');

        var vh='';
        for(var s4=0;s4<state.sampleN;s4++){
            var p=MODEL.pcs[s4]||{v:'OK',m:0};
            var ng=(p.m? p.v==='NG' : pcsAutoNG(s4));
            var none=!MODEL.items.length;
            vh+='<span class="pverdict '+(none?'':(ng?'ng':'ok'))+' '+(p.m?'manual':'')+'" data-s="'+s4+'" title="點擊手動改判，雙擊恢復自動">'+
                (none?'—':(ng?'✘ NG':'✔ OK'))+'</span> ';
        }
        $('#verdict-cells').html(vh);
    }

    // ---------- 彙總（進度 / 不良數 / 整體判定） ----------
    function recalc(){
        ensurePcs();
        var hasItems = MODEL.items.length>0;
        var total = hasItems ? MODEL.items.length*state.sampleN : 0, filled = 0, ngPcs = 0;
        MODEL.items.forEach(function(it){ filled += itemFilledCount(it); });
        for(var s=0;s<state.sampleN;s++){
            var p=MODEL.pcs[s];
            var ng = p.m ? (p.v==='NG') : pcsAutoNG(s);
            if(!p.m) p.v = ng ? 'NG' : 'OK';
            if(hasItems && ng) ngPcs++;
        }
        $('#dk-prog').text(filled+'/'+total);
        $('#dk-progbar').css('width', total? Math.round(filled*100/total)+'%' : '0%');
        $('#dk-ng').text(ngPcs);
        $('#dk-judge').text(hasItems ? (ngPcs>0?'✘ 不良':'✔ 合格') : '—');
        $('#inp-ng').val(hasItems ? ngPcs : '');
        if(hasItems) $('input[name=judge][value="'+(ngPcs>0?'NG':'OK')+'"]').prop('checked', true);
        else $('input[name=judge]').prop('checked', false);
        // 判定列與項目膠囊燈號同步
        $('#verdict-cells .pverdict').each(function(){
            var s=+$(this).data('s'), p=MODEL.pcs[s]||{v:'OK',m:0};
            var ng=(p.m? p.v==='NG' : pcsAutoNG(s));
            $(this).removeClass('ok ng manual').addClass(hasItems ? (ng?'ng':'ok') : '').addClass(p.m?'manual':'')
                   .text(hasItems ? (ng?'✘ NG':'✔ OK') : '—');
        });
        $('#view-item .chip.jump-item').each(function(){
            var ix=+$(this).data('ix'), it=MODEL.items[ix]; if(!it) return;
            var v=itemVerdict(it);
            $(this).find('.dot').removeClass('ok ng').addClass(v==='NG'?'ng':(v==='OK'?'ok':''));
            $(this).find('.cnt').text(itemFilledCount(it)+'/'+state.sampleN);
        });
        MODEL.items.forEach(function(it,i){ updateItemVerdictCell(i); });
    }

    // =====================================================================
    // 輸入事件（三種檢視共用同一組委派：一律先寫回 MODEL，再只重畫該格）
    // =====================================================================
    $(document).on('input', '.mval', function(){
        var i=+$(this).data('i'), r=+$(this).data('r'), s=+$(this).data('s');
        if(!MODEL.items[i]) return;
        MODEL.items[i].readings[r].vals[s] = $(this).val();
        paintCell($(this).closest('.mcell'));
        recalc(); scheduleDraftSave();
    });
    // UI 規範：聚焦自動全選、有值雙擊清空
    $(document).on('focus', '.mval', function(){
        lastFocused=this; var el=this;
        $('.mcell').removeClass('focus-on'); $(this).closest('.mcell').addClass('focus-on');
        setTimeout(function(){ try{ el.select(); }catch(_){ } }, 0);
    });
    $(document).on('dblclick', '.mval', function(){
        if($(this).val()===''){ return; }
        $(this).val('').trigger('input');
    });
    // OK/NG 格：點擊循環 OK → NG → OK
    $(document).on('click', '.mcell.okng', function(){
        var i=+$(this).data('i'), r=+$(this).data('r'), s=+$(this).data('s');
        var it=MODEL.items[i]; if(!it) return;
        var cur=it.readings[r].vals[s];
        it.readings[r].vals[s] = (cur==='NG') ? 'OK' : 'NG';
        paintCell($(this)); recalc(); scheduleDraftSave();
    });
    // 鍵盤導航：Enter/→ 下一格、← 上一格、↓↑ 同件切換項目（總表）或切換項目/件（專注模式）
    $(document).on('keydown', '.mval', function(e){
        var k=e.key;
        if(k!=='Enter' && k!=='ArrowRight' && k!=='ArrowLeft' && k!=='ArrowUp' && k!=='ArrowDown') return;
        var $cells=$('.view-pane:visible').find('.mval');
        var idx=$cells.index(this);
        if(k==='Enter' || k==='ArrowRight'){
            e.preventDefault();
            if(idx < $cells.length-1){ $cells.eq(idx+1).focus(); }
            else { autoAdvance(); }
        } else if(k==='ArrowLeft'){ e.preventDefault(); if(idx>0) $cells.eq(idx-1).focus(); }
        else if(k==='ArrowDown' || k==='ArrowUp'){
            e.preventDefault();
            var s=+$(this).data('s'), step=(k==='ArrowDown'?1:-1);
            var $col=$('.view-pane:visible').find('.mval[data-s="'+s+'"]');
            var ci=$col.index(this), ni=ci+step;
            if(ni>=0 && ni<$col.length) $col.eq(ni).focus();
            else if(view!=='GRID') autoAdvance(step);
        }
    });
    // 專注模式：最後一格填完自動翻到下一項/下一件
    function autoAdvance(step){
        step = step || 1;
        if(view==='ITEM'){
            var n=focusItem+step;
            if(n>=0 && n<MODEL.items.length){ focusItem=n; renderItemView(); recalc(); focusFirstCell(); }
        } else if(view==='PCS'){
            var m=focusPcs+step;
            if(m>=0 && m<state.sampleN){ focusPcs=m; renderPcsView(); recalc(); focusFirstCell(); }
        }
    }
    function focusFirstCell(){ setTimeout(function(){ $('.view-pane:visible').find('.mval').first().focus(); }, 30); }

    // 膠囊/導航切換
    $(document).on('click', '.jump-item', function(){ focusItem=+$(this).data('ix'); renderItemView(); recalc(); });
    $(document).on('click', '.jump-pcs',  function(){ focusPcs =+$(this).data('ix'); renderPcsView();  recalc(); });
    $(document).on('click', '#view-item .nav-prev', function(){ if(focusItem>0){ focusItem--; renderItemView(); recalc(); } });
    $(document).on('click', '#view-item .nav-next', function(){
        if(focusItem<MODEL.items.length-1){ focusItem++; renderItemView(); recalc(); }
        else { view='GRID'; localStorage.setItem('qc2_view',view); render(); }
    });
    $(document).on('click', '#view-pcs .nav-prev', function(){ if(focusPcs>0){ focusPcs--; renderPcsView(); recalc(); } });
    $(document).on('click', '#view-pcs .nav-next', function(){
        if(focusPcs<state.sampleN-1){ focusPcs++; renderPcsView(); recalc(); }
        else { view='GRID'; localStorage.setItem('qc2_view',view); render(); }
    });
    $('.view-switch').on('click','button', function(){
        view=$(this).data('view'); localStorage.setItem('qc2_view', view); render();
    });
    $('#chk-std-edit').on('change', function(){ renderGrid(); recalc(); });
    $('#btn-code-mode').on('click', function(e){
        e.preventDefault();
        codeMode = (codeMode==='ALPHA') ? 'NUM' : 'ALPHA';
        localStorage.setItem('qc_item_code_mode', codeMode); render();
    });

    // ---------- 標準/量具/型態/備註 編修 ----------
    // ---------- 量具挑選跳窗（類型 → 編號；可一次套用到多個項目） ----------
    var tpTarget=null;
    $(document).on('click', '.tool-btn', function(){ openToolPicker(+$(this).data('i'), +$(this).data('r')); });
    function openToolPicker(i, r){
        var it=MODEL.items[i]; if(!it) return;
        tpTarget={ i:i, r:r };
        $('#tp-for').text('（'+codeLabel(i)+' '+(it.name||'未命名')+(r>0?(' · 加量測'+r):'')+'）');
        // 只有主量測、且表內不只一個項目時，才需要問套用範圍
        $('#tp-scope').toggle(r===0 && MODEL.items.length>1);
        var cats=[], cnt={};
        TOOL_INSTANCES.forEach(function(t){ var c=t.cat||'（未分類）'; if(cnt[c]===undefined){ cnt[c]=0; cats.push(c); } cnt[c]++; });
        $('#tp-cats').html(cats.length ? cats.map(function(c){
            return '<button type="button" class="tp-cat" data-c="'+esc(c)+'">'+esc(c)+'<small>'+cnt[c]+' 支</small></button>';
        }).join('') : '<div class="text-muted">尚未建立任何量具，請至 設定 → 量具設定 新增。</div>');
        $('#tp-step1').show(); $('#tp-step2').hide();
        $('#toolPickModal').modal('show');
    }
    $(document).on('click', '.tp-cat', function(){
        var cat=String($(this).attr('data-c'));
        var list=TOOL_INSTANCES.filter(function(t){ return (t.cat||'（未分類）')===cat; });
        $('#tp-nos').html(list.map(function(t){
            return '<button type="button" class="tp-no" data-id="'+t.id+'">'+esc(t.no)+'<small>'+esc(t.cat||'')+'</small></button>';
        }).join(''));
        $('#tp-step1').hide(); $('#tp-step2').show();
    });
    $(document).on('click', '#tp-back', function(e){ e.preventDefault(); $('#tp-step2').hide(); $('#tp-step1').show(); });
    $(document).on('click', '.tp-no', function(){ applyTool(String($(this).attr('data-id'))); });
    $('#tp-clear').on('click', function(){ applyTool(''); });
    function applyTool(tid){
        if(!tpTarget) return;
        var i=tpTarget.i, r=tpTarget.r, n=0;
        var scope = ($('#tp-scope').is(':visible')) ? ($('input[name=tpscope]:checked').val()||'one') : 'one';
        if(scope==='one'){ MODEL.items[i].readings[r].tool_id=tid; n=1; }
        else {
            MODEL.items.forEach(function(it){
                if(scope==='blank' && it.readings[0].tool_id) return;   // 只補「還沒設定」的
                it.readings[0].tool_id=tid; n++;
            });
            if(scope==='blank' && !MODEL.items[i].readings[0].tool_id){ MODEL.items[i].readings[0].tool_id=tid; n++; }
        }
        $('#toolPickModal').modal('hide');
        render(); scheduleDraftSave();
        var t=toolInstById(tid);
        if(n>1) flashMsg('已套用「'+((t?(t.cat+' / '+t.no):'未指定'))+'」到 '+n+' 個檢驗項目');
    }
    function flashMsg(msg){
        var $m=$('#flash-msg');
        if(!$m.length) $m=$('<div id="flash-msg" style="position:fixed;left:50%;transform:translateX(-50%);bottom:140px;'+
            'background:#4A3524;color:#fff;padding:9px 20px;border-radius:20px;z-index:1100;font-size:14px;display:none;'+
            'box-shadow:0 2px 8px rgba(0,0,0,.25);"></div>').appendTo('body');
        $m.text(msg).stop(true,true).fadeIn(120).delay(2000).fadeOut(400);
    }
    $(document).on('input', '.f-name', function(){ var i=+$(this).data('i'); MODEL.items[i].name=$(this).val(); scheduleDraftSave(); });
    $(document).on('input', '.f-std',  function(){ var i=+$(this).data('i'); MODEL.items[i].std =$(this).val(); repaintItem(i); });
    $(document).on('input', '.f-up',   function(){ var i=+$(this).data('i'); MODEL.items[i].up  =$(this).val(); repaintItem(i); });
    $(document).on('input', '.f-lo',   function(){ var i=+$(this).data('i'); MODEL.items[i].lo  =$(this).val(); repaintItem(i); });
    $(document).on('input', '.f-remark',function(){ var i=+$(this).data('i'); MODEL.items[i].remark=$(this).val(); scheduleDraftSave(); });
    $(document).on('change', '.f-type', function(){
        var i=+$(this).data('i'), t=$(this).val()==='OKNG'?'OKNG':'NUM';
        var it=MODEL.items[i]; it.type=t;
        it.readings.forEach(function(rd){ rd.vals=blankVals(state.sampleN, t==='OKNG'?'OK':''); });
        render(); scheduleDraftSave();
    });
    function repaintItem(i){
        $('.mcell[data-i="'+i+'"]').each(function(){ paintCell($(this)); });
        recalc(); scheduleDraftSave();
    }
    $(document).on('click', '.btn-edit-std', function(){
        view='GRID'; localStorage.setItem('qc2_view',view);
        $('#chk-std-edit').prop('checked', true); render();
        $('html,body').animate({ scrollTop:$('#items-table').offset().top-120 }, 250);
    });
    $(document).on('click', '.btn-add-reading', function(e){
        e.preventDefault();
        var i=+$(this).data('i'), it=MODEL.items[i];
        it.readings.push({ tool_id:'', tool_cat:'', vals:blankVals(state.sampleN, it.type==='OKNG'?'OK':'') });
        render(); scheduleDraftSave();
    });
    $(document).on('click', '.btn-del-reading', function(e){
        e.preventDefault();
        var i=+$(this).data('i'), r=+$(this).data('r');
        MODEL.items[i].readings.splice(r,1); render(); scheduleDraftSave();
    });
    $(document).on('click', '.btn-del-item', function(){
        var i=+$(this).data('i');
        if(!confirm('確定刪除「'+(MODEL.items[i].name||'未命名項目')+'」？已填的實測值也會一併移除。')) return;
        MODEL.items.splice(i,1);
        if(focusItem>=MODEL.items.length) focusItem=Math.max(0,MODEL.items.length-1);
        render(); scheduleDraftSave();
    });
    $(document).on('click', '.btn-item-note', function(){
        var i=+$(this).data('i');
        var v=prompt('本項目備註（處置/狀況，如「毛邊已修」）：', MODEL.items[i].remark||'');
        if(v===null) return;
        MODEL.items[i].remark=String(v).slice(0,255); render(); scheduleDraftSave();
    });
    $('#btn-add-row').on('click', function(){
        MODEL.items.push(newItem());
        focusItem=MODEL.items.length-1;
        view='GRID'; localStorage.setItem('qc2_view',view);
        $('#chk-std-edit').prop('checked', true);
        $('#no-std-hint').hide(); render();
        setTimeout(function(){ $('#items-body tr[data-i="'+focusItem+'"] .f-name').focus(); },50);
    });
    // 判定列：點擊手動改判、雙擊恢復自動
    $(document).on('click', '#verdict-cells .pverdict', function(){
        var s=+$(this).data('s'); if(!MODEL.items.length) return;
        var p=MODEL.pcs[s]; var cur=(p.m? p.v==='NG' : pcsAutoNG(s));
        p.m=1; p.v=cur?'OK':'NG'; recalc(); scheduleDraftSave();
    });
    $(document).on('dblclick', '#verdict-cells .pverdict', function(){
        var s=+$(this).data('s'); MODEL.pcs[s].m=0; recalc(); scheduleDraftSave();
    });
    $('#inp-sample').on('change', function(){ setSampleN($(this).val()); scheduleDraftSave(); });
    $('#inp-qty,#inp-remark').on('input', scheduleDraftSave);
    $('#btn-dock-extra').on('click', function(){ $('#dock-extra').slideToggle(120, syncDockPad); });

    // =====================================================================
    // 內建數字鍵盤（平板/戴手套；桌機可關）
    // =====================================================================
    function applyKeypad(){ $('#keypad').toggle(!!keypadOn); $('#btn-keypad').toggleClass('btn-warm', !!keypadOn); }
    $('#btn-keypad').on('click', function(){ keypadOn=!keypadOn; localStorage.setItem('qc2_keypad', keypadOn?'1':'0'); applyKeypad(); });
    $('#kp-close').on('click', function(e){ e.preventDefault(); keypadOn=false; localStorage.setItem('qc2_keypad','0'); applyKeypad(); });
    $('#keypad').on('mousedown', 'button', function(e){ e.preventDefault(); });   // 不奪走輸入焦點
    $('#keypad').on('click','button', function(){
        var k=$(this).data('k');
        var el=lastFocused && document.body.contains(lastFocused) ? lastFocused : $('.view-pane:visible').find('.mval').get(0);
        if(!el){ return; }
        var $el=$(el), v=$el.val();
        if(k==='BS') v=v.slice(0,-1);
        else if(k==='CL') v='';
        else if(k==='OK'||k==='NEXT'){
            var $cells=$('.view-pane:visible').find('.mval'), idx=$cells.index(el);
            if(idx<$cells.length-1) $cells.eq(idx+1).focus(); else autoAdvance();
            return;
        }
        else if(k==='-'){ v = (v.charAt(0)==='-') ? v.slice(1) : ('-'+v); }
        else if(k==='.'){ if(v.indexOf('.')<0) v=v+'.'; }
        else v=v+k;
        $el.val(v).trigger('input');
        el.focus();
    });

    // =====================================================================
    // 載入情境（沿用舊頁 load_context）
    // =====================================================================
    function getFid(){ return new URLSearchParams(location.search).get('bom_ing_fid'); }
    function applyMenuPerms(){
        $('.setting-menu-item').toggle(!!state.canManageSettings);
        $('.sampling-menu-item').toggle(!!state.canManageSampling);
    }
    $.post(API, { action:'get_my_perms' }, function(res){
        if(!res || !res.success) return;
        state.can_fill = res.can_fill !== false;
        state.is_supervisor = !!res.is_supervisor;
        state.canManageSettings = !!res.can_manage_settings;
        state.canManageSampling = !!res.can_manage_sampling;
        state.canView = !!res.can_view;
        applyMenuPerms();
        if(!state.canView && state.demo){
            $('#no-view-hint').html('<i class="fa fa-ban"></i> 您沒有檢閱檢驗表的權限，請洽管理員於 設定 → 權限設定 開通「唯讀檢閱」').show();
            $('#step-search').hide();
        }
    }, 'json');
    loadToolInstances();
    applyKeypad();

    var fid = getFid();
    if(fid){
        $('#mode-banner').html('<i class="fa fa-link"></i> 來自待驗清單：bom_ing_fid = <b>'+esc(fid)+'</b>；資料為真實內容，儲存會寫入正式檢驗表。');
        loadContext(fid);
    } else {
        state.demo = true;
        $('#mode-banner').html('<i class="fa fa-info-circle"></i> <b>示範模式</b>（未帶 bom_ing_fid）：可搜尋待驗項目瀏覽動線。正式由「QC待驗清單」按檢驗開啟。');
        $('#step-search').show();
    }

    function loadContext(fid){
        $.post(API, { action:'load_context', bom_ing_fid:fid }, function(res){
            if(!res.success){
                if(res.no_view){
                    state.canView=false;
                    $('#no-view-hint').html('<i class="fa fa-ban"></i> '+esc(res.message||'您沒有檢閱檢驗表的權限')).show();
                    $('#main-area,#ctx-bar,#step-search,#dock').hide();
                    return;
                }
                alert('載入失敗：'+res.message); return;
            }
            ctx = res.context;
            if(res.tools && res.tools.length) TOOLS = res.tools;
            state.is_supervisor = !!res.is_supervisor;
            state.can_fill = res.can_fill !== false;
            state.canManageSettings = !!res.can_manage_settings;
            state.canManageSampling = !!res.can_manage_sampling;
            applyMenuPerms();
            state.sampleN = ctx.sample_qty || 5;
            state.processes = [ ctx.process || '檢驗' ];
            buildBatchesFromHistory(res.history || []);
            renderCtxBar();
            $('#main-area').show(); $('#dock').show(); syncDockPad();
            $('#inp-qty').val(ctx.order_qty || 0);
            $('#inp-sample').val(state.sampleN);
            renderBatches();
            renderItems(res.items || []);
            $('#no-std-hint').toggle(!res.has_std);
            var noPart = !ctx.d_id || ctx.d_id<=0;
            $('#no-part-hint').toggle(noPart);
            $('#no-perm-hint').toggle(!state.can_fill);
            $('#btn-save,#btn-redo').prop('disabled', noPart || !state.can_fill);
            maybeOfferDraft(res.draft_form_id || 0);
        }, 'json').fail(function(x){ alert('載入錯誤：'+x.responseText); });
    }
    function reloadContext(){ if(ctx) loadContext(ctx.bom_ing_fid); }

    function renderCtxBar(){
        $('#ctx-bar').show().html(
            '<div><b>料號</b><a href="javascript:void(0)" class="cv" id="lnk-part-drawing" title="點擊開啟圖檔預覽">'+esc(ctx.part_no)+' <i class="fa fa-picture-o"></i></a></div>'+
            '<div><b>客戶</b><span class="cv">'+esc(ctx.client||'—')+'</span></div>'+
            '<div><b>製令 / BOM</b><span class="cv">'+esc(ctx.bom||'—')+'</span></div>'+
            '<div><b>製程</b><span class="cv">'+esc(ctx.process||'—')+'</span></div>'+
            '<div><b>訂單數</b><span class="cv">'+(ctx.order_qty||0)+'</span></div>'+
            '<div><b>建議抽驗</b><span class="cv">'+(ctx.sample_qty||0)+' 件</span></div>');
    }
    $('#ctx-bar').on('click','#lnk-part-drawing', function(){
        if(!ctx) return;
        var w=screen.availWidth, h=screen.availHeight;
        var pw=Math.min(1400, Math.round(w*0.85)), ph=Math.min(900, Math.round(h*0.88));
        var url = ctx.part_no ? ('../pm/part_viewer.php?d_id='+encodeURIComponent(ctx.part_no)+(ctx.bom?'&bom='+encodeURIComponent(ctx.bom):''))
                              : ('../pm/bom_viewer.php?bom='+encodeURIComponent(ctx.bom||''));
        window.open(url, 'part_dv_'+(ctx.part_no||ctx.bom),
            'width='+pw+',height='+ph+',left='+Math.round((w-pw)/2)+',top='+Math.round((h-ph)/2)+',resizable=yes,scrollbars=yes');
    });

    // ---------- 待驗搜尋（示範模式） ----------
    function doSearch(){
        var kw=$('#search-kw').val().trim();
        $('#search-results').html('<div class="search-result-item muted-help">搜尋中…</div>');
        $.post(API,{action:'search_pending',keyword:kw},function(res){
            if(!res.success){ $('#search-results').html('<div class="search-result-item text-danger">搜尋失敗：'+esc(res.message||'')+'</div>'); return; }
            var d=res.data||[];
            if(!d.length){ $('#search-results').html('<div class="search-result-item muted-help">查無待驗項目</div>'); return; }
            $('#search-results').html(d.map(function(r){
                return '<div class="search-result-item" data-fid="'+r.bom_ing_fid+'"><b>'+esc(r.bom)+'</b> ／ 料號 '+esc(r.part_no||'')+' ／ '+esc(r.client||'')+
                       ' <span class="muted-help">'+esc(r.process||'')+' · 數量'+(r.sqty||0)+'</span></div>';
            }).join(''));
        },'json').fail(function(){ $('#search-results').html('<div class="search-result-item text-danger">搜尋錯誤</div>'); });
    }
    $('#btn-search').on('click', doSearch);
    $('#search-kw').on('keydown', function(e){ if(e.key==='Enter'){ e.preventDefault(); doSearch(); } });
    $('#search-results').on('click','.search-result-item[data-fid]', function(){
        var f=$(this).data('fid'); if(!f) return;
        var pop=new URLSearchParams(location.search).get('popup');
        location.search=(pop?'?popup=1&':'?')+'bom_ing_fid='+f;
    });

    // =====================================================================
    // 批次 / 檢驗歷程
    // =====================================================================
    function statusLabel(s){
        return ({ OK:'<span class="st-ok">✔合格</span>', NG:'<span class="st-ng">✘不良</span>',
                  REDO:'<span class="st-redo">⟳重做中</span>', WAIT:'<span class="st-wait">…待驗</span>' })[s]||'';
    }
    function buildBatchesFromHistory(history){
        state.batches=[]; var byBatch={};
        (history||[]).forEach(function(h){
            var b=h.batch_no||1;
            if(!byBatch[b]) byBatch[b]={ no:b, status:'WAIT', rounds:[] };
            byBatch[b].rounds.push({
                date:(h.check_date||h.created_at||''), status:(h.check_result==='NG'?'NG':'OK'),
                qc_form_id:h.qc_form_id, round_no:(h.round_no||1), ng_qty:(h.ng_qty||0),
                edit_unlocked:(parseInt(h.edit_unlocked)||0), self_grace:(parseInt(h.self_grace)||0),
                edit_log_count:(parseInt(h.edit_log_count)||0),
                last_edited_by:h.last_edited_by, last_edited_at:h.last_edited_at,
                ncr_decision:h.ncr_decision, ncr_skip_reason:h.ncr_skip_reason,
                abnormal_order_id:h.abnormal_order_id, abnormal_order_no:h.abnormal_order_no
            });
            byBatch[b].status=(h.check_result==='NG'?'NG':'OK');
        });
        Object.keys(byBatch).map(Number).sort(function(a,b){return a-b;}).forEach(function(k){ state.batches.push(byBatch[k]); });
        if(!state.batches.length) state.batches.push({ no:1, status:'WAIT', rounds:[] });
        state.curBatch = state.batches.length-1;
    }
    function renderBatches(){
        var h=state.batches.map(function(b,i){
            return '<span class="batch-chip '+(i===state.curBatch?'active':'')+'" data-i="'+i+'">批次'+b.no+' '+statusLabel(b.status)+'</span>';
        }).join('');
        h+='<span class="batch-chip" id="btn-new-batch" style="border-style:dashed;"><i class="fa fa-plus"></i> 新到貨批次</span>';
        $('#batch-bar').html(h);
        var b=state.batches[state.curBatch];
        $('#batch-summary').text('（目前批次'+(b?b.no:1)+'，已檢驗 '+(b?b.rounds.length:0)+' 次）');
        renderHistory();
    }
    $('#btn-toggle-batch').on('click', function(e){
        e.preventDefault();
        $('#batch-zone').slideToggle(120);
        $(this).find('i').toggleClass('fa-caret-right fa-caret-down');
    });
    $('#batch-bar').on('click','.batch-chip[data-i]', function(){ state.curBatch=$(this).data('i'); renderBatches(); });
    $('#batch-bar').on('click','#btn-new-batch', function(){
        state.batches.push({ no:state.batches.length+1, status:'WAIT', rounds:[] });
        state.curBatch=state.batches.length-1; renderBatches();
    });
    function renderHistory(){
        var b=state.batches[state.curBatch];
        if(!b || !b.rounds.length){ $('#batch-history').html('<div class="muted-help">此批次尚無檢驗紀錄。</div>'); return; }
        var rows=b.rounds.map(function(r,i){
            var act='';
            if(r.qc_form_id){
                var locked=!r.edit_unlocked, selfGrace=!!r.self_grace && state.can_fill;
                if(locked && !state.is_supervisor && !selfGrace) act+='<span class="muted-help" title="已鎖定，需主管開放"><i class="fa fa-lock"></i> 鎖定</span> ';
                if(locked && state.is_supervisor) act+='<button class="btn btn-xs btn-warm-o act-unlock" data-id="'+r.qc_form_id+'"><i class="fa fa-unlock-alt"></i> 開放修改</button> ';
                if((!locked && state.can_fill) || state.is_supervisor || selfGrace)
                    act+='<button class="btn btn-xs btn-warm act-edit" data-id="'+r.qc_form_id+'"'+(locked&&selfGrace?' title="本人寬限期內可自改"':'')+'><i class="fa fa-pencil"></i> 修改'+(locked&&selfGrace?'（本人）':'')+'</button> ';
                if(r.edit_log_count>0) act+='<button class="btn btn-xs btn-default act-log" data-id="'+r.qc_form_id+'"><i class="fa fa-history"></i> 紀錄</button>';
            }
            var edited=r.last_edited_at ? ('<br><small class="muted-help">最後修改：'+esc(r.last_edited_by||'')+' '+esc(r.last_edited_at)+'</small>') : '';
            var ncr='';
            if(r.status==='NG' && r.qc_form_id){
                if(r.abnormal_order_no) ncr='<a class="btn btn-xs btn-default" href="../QA/qa_abnormal_view.php?id='+r.abnormal_order_id+'" target="_blank">'+esc(r.abnormal_order_no)+'</a>';
                else if(r.ncr_decision==='SKIP') ncr='<span class="label label-default" title="不開單原因：'+esc(r.ncr_skip_reason||'')+'">不開單</span>';
                else if(state.can_fill || state.is_supervisor) ncr='<button class="btn btn-xs btn-coral act-open-ncr" data-id="'+r.qc_form_id+'"><i class="fa fa-file-text-o"></i> 開異常單</button>';
                else ncr='<span class="label label-default">未開單</span>';
            }
            return '<tr class="history-row"><td>第'+(r.round_no||(i+1))+'次</td><td>'+esc(r.date)+edited+'</td><td>'+statusLabel(r.status)+
                   '</td><td>不良 '+(r.ng_qty||0)+'</td><td>'+ncr+'</td><td>'+act+'</td></tr>';
        }).join('');
        $('#batch-history').html(
            '<table class="table table-condensed table-bordered" style="background:#fff;"><thead>'+
            '<tr><th width="70">次數</th><th width="180">日期</th><th width="90">結果</th><th width="80">不良</th><th width="110">異常單</th><th width="230">操作</th></tr>'+
            '</thead><tbody>'+rows+'</tbody></table>');
    }
    $('#batch-history').on('click','.act-unlock', function(){
        var id=$(this).data('id');
        var reason=prompt('開放此筆修改的原因（會記錄）：','');
        if(reason===null) return;
        $.post(API,{action:'unlock_record',qc_form_id:id,reason:reason},function(res){
            if(!res.success){ alert('開放失敗：'+res.message); return; }
            alert('已開放此筆修改。'); reloadContext();
        },'json');
    });
    $('#batch-history').on('click','.act-edit', function(){ openEditRecord($(this).data('id')); });
    $('#batch-history').on('click','.act-log',  function(){ viewEditLog($(this).data('id')); });
    $('#batch-history').on('click','.act-open-ncr', function(){
        var qid=$(this).data('id');
        $.post(API,{action:'get_history_record',qc_form_id:qid},function(res){
            if(!res.success){ alert('載入失敗：'+res.message); return; }
            if(res.header && res.header.abnormal_order_no){
                alert('此筆檢驗已開立異常單 '+res.header.abnormal_order_no+'，不可重複開立。'); reloadContext(); return;
            }
            QAAbnormalModal.open({
                source_type:'QC', source_id:qid,
                title_suffix:(ctx?('料號 '+ctx.part_no):''),
                prefill: ngPrefill(res.header.ng_qty, ngSummaryText(res.items)),
                onCreated:function(r){
                    $.post(API,{action:'set_ncr_decision',qc_form_id:qid,decision:'OPEN',abnormal_order_id:r.id},function(){
                        alert('異常單 '+r.no+' 已開立並發送通知。'); reloadContext();
                    },'json');
                }
            });
        },'json');
    });

    // ---------- 修改模式 ----------
    function openEditRecord(qcFormId){
        $.post(API,{action:'get_history_record',qc_form_id:qcFormId},function(res){
            if(!res.success){ alert('載入失敗：'+res.message); return; }
            if(!res.can_edit){ alert('此筆已鎖定，請主管先開放修改。'); return; }
            var h=res.header;
            state.editFormId=qcFormId;
            state.sampleN=h.sample_qty||state.sampleN;
            $('#inp-qty').val(h.incoming_qty||0);
            $('#inp-sample').val(state.sampleN);
            $('#inp-remark').val(h.main_remark||'');
            renderItems(res.items||[]);
            (h.pcs_verdicts||[]).forEach(function(pv,i){
                if(!pv || !pv.m || !MODEL.pcs[i]) return;
                MODEL.pcs[i].m=1; MODEL.pcs[i].v=(pv.v==='NG'?'NG':'OK');
            });
            recalc();
            $('#no-std-hint').hide();
            $('#edit-form-id').text(qcFormId);
            $('#edit-mode-banner').show();
            $('#chk-save-std').prop('checked',false).closest('label').hide();
            $('#btn-save').html('<i class="fa fa-save"></i> 儲存修改');
            $('#btn-redo').hide();
            $('html,body').animate({scrollTop:0},200);
        },'json').fail(function(x){ alert('載入錯誤：'+x.responseText); });
    }
    function exitEditMode(){
        state.editFormId=null;
        $('#edit-mode-banner').hide();
        $('#chk-save-std').prop('checked',true).closest('label').show();
        $('#btn-save').html('<i class="fa fa-save"></i> 儲存檢驗結果');
        $('#btn-redo').show();
        reloadContext();
    }
    $('#btn-exit-edit').on('click', function(){
        var qid=state.editFormId;
        if(qid){ $.post(API,{action:'relock_record',qc_form_id:qid},function(){ exitEditMode(); },'json').fail(exitEditMode); }
        else exitEditMode();
    });
    function viewEditLog(qcFormId){
        $.post(API,{action:'get_edit_log',qc_form_id:qcFormId},function(res){
            if(!res.success){ alert('查詢失敗：'+res.message); return; }
            var logs=res.logs||[];
            var html='<table class="table table-condensed table-bordered"><thead><tr><th>時間</th><th>行為</th><th>人員</th><th>原因/變更</th></tr></thead><tbody>';
            if(!logs.length) html+='<tr><td colspan="4" class="text-center muted-help">尚無修改紀錄</td></tr>';
            logs.forEach(function(l){
                var actMap={UNLOCK:'開放修改',EDIT:'修改',RELOCK:'回鎖'};
                var detail=esc(l.reason||'');
                if(l.changes_json) detail+=' <a href="#" class="show-diff" data-json=\''+esc(l.changes_json)+'\'>[改前/改後]</a>';
                html+='<tr><td>'+esc(l.changed_at)+'</td><td>'+(actMap[l.action]||l.action)+'</td><td>'+esc(l.user_cname||l.changed_by)+'</td><td>'+detail+'</td></tr>';
            });
            html+='</tbody></table>';
            $('#log-modal-body').html(html); $('#logModal').modal('show');
        },'json');
    }
    $('#log-modal-body').on('click','.show-diff', function(e){ e.preventDefault();
        try{ var d=JSON.parse($(this).attr('data-json')); alert('改前：\n'+JSON.stringify(d.before,null,2)+'\n\n改後：\n'+JSON.stringify(d.after,null,2)); }
        catch(_){ alert('無法解析變更內容'); }
    });

    // =====================================================================
    // 草稿 / 自動存檔（沿用 save_draft / get_draft / discard_draft）
    // =====================================================================
    var draftTimer=null, draftDirty=false;
    function draftEligible(){ return ctx && !state.demo && !state.editFormId && state.can_fill && ctx.d_id>0; }
    function scheduleDraftSave(){
        if(!draftEligible()) return;
        draftDirty=true;
        if(draftTimer) clearTimeout(draftTimer);
        draftTimer=setTimeout(saveDraftNow, 2500);
    }
    function saveDraftNow(){
        if(!draftEligible() || !draftDirty) return;
        var items=collectItems(); if(!items.length){ draftDirty=false; return; }
        var b=state.batches[state.curBatch]||{no:1,rounds:[]};
        $.post(API,{ action:'save_draft', bom_ing_fid:ctx.bom_ing_fid, d_id:ctx.d_id, process_name:ctx.process,
            batch_no:b.no, round_no:(b.rounds.length+1),
            incoming_qty:parseInt($('#inp-qty').val())||0, sample_qty:parseInt($('#inp-sample').val())||0,
            main_remark:$('#inp-remark').val(), items:JSON.stringify(items), pcs_verdicts:JSON.stringify(collectPcsVerdicts())
        }, function(res){
            if(res && res.success){
                draftDirty=false; state.draftFormId=res.draft_form_id;
                var t=new Date(), p=function(n){return('0'+n).slice(-2);};
                $('#draft-status').html('<i class="fa fa-check"></i> 已自動存草稿 '+p(t.getHours())+':'+p(t.getMinutes())+':'+p(t.getSeconds()));
            }
        }, 'json');
    }
    $(window).on('beforeunload', function(){ if(draftEligible() && draftDirty){ try{ saveDraftNow(); }catch(e){} } });
    function maybeOfferDraft(draftId){
        if(!draftId || state.editFormId){ $('#draft-banner').remove(); return; }
        state.draftFormId=draftId;
        if(!$('#draft-banner').length) $('#mode-banner').after('<div id="draft-banner" class="banner banner-info"></div>');
        $('#draft-banner').html('<i class="fa fa-clock-o"></i> 偵測到您先前<b>未送出的草稿</b>（關掉視窗前自動保存的內容）。'+
            '<button class="btn btn-xs btn-warm" id="btn-restore-draft" style="margin-left:8px;"><i class="fa fa-download"></i> 載回草稿</button> '+
            '<button class="btn btn-xs btn-default" id="btn-discard-draft"><i class="fa fa-trash"></i> 捨棄</button>').show();
    }
    $(document).on('click','#btn-restore-draft', function(){
        var did=state.draftFormId; if(!did) return;
        $.post(API,{action:'get_draft',qc_form_id:did}, function(res){
            if(!res.success || !res.draft){ alert('載回失敗或草稿已不存在'); $('#draft-banner').hide(); return; }
            var d=res.draft;
            state.sampleN=parseInt(d.sample_qty)||state.sampleN;
            $('#inp-qty').val(d.incoming_qty||0); $('#inp-sample').val(state.sampleN); $('#inp-remark').val(d.main_remark||'');
            renderItems(d.items||[]);
            (d.pcs||[]).forEach(function(pv,i){ if(pv && pv.m && MODEL.pcs[i]){ MODEL.pcs[i].m=1; MODEL.pcs[i].v=(pv.v==='NG'?'NG':'OK'); } });
            recalc();
            $('#no-std-hint').hide(); $('#draft-banner').hide();
        }, 'json');
    });
    $(document).on('click','#btn-discard-draft', function(){
        if(!confirm('確定捨棄此草稿？此動作無法復原。')) return;
        $.post(API,{action:'discard_draft',bom_ing_fid:(ctx?ctx.bom_ing_fid:0),qc_form_id:(state.draftFormId||0)}, function(){
            state.draftFormId=0; $('#draft-banner').hide();
        }, 'json');
    });

    // =====================================================================
    // 通用樣板匯入
    // =====================================================================
    function openTpl(){
        $('#tpl-list').html('<div class="list-group-item text-muted">載入中…</div>');
        $('#tplModal').modal('show');
        $.post(API,{action:'manage_templates',sub_action:'list'},function(res){
            if(!res.success){ $('#tpl-list').html('<div class="list-group-item text-danger">載入失敗</div>'); return; }
            var d=res.data||[];
            $('#tpl-list').html(d.length ? d.map(function(t){
                return '<a href="#" class="list-group-item tpl-pick" data-id="'+t.template_id+'">'+esc(t.template_name)+'</a>';
            }).join('') : '<div class="list-group-item text-muted">尚無樣板（可到 設定 → 通用樣板管理 建立）</div>');
        },'json');
    }
    $('#btn-import-tpl,#btn-import-tpl2').on('click', function(e){ e.preventDefault(); openTpl(); });
    $('#tpl-list').on('click','.tpl-pick', function(e){
        e.preventDefault();
        var tid=$(this).data('id');
        $.post(API,{action:'manage_templates',sub_action:'get_items',template_id:tid},function(res){
            if(!res.success){ alert('載入失敗：'+(res.message||'')); return; }
            var incoming=(res.items||[]).map(normItem);
            if(MODEL.items.length && !confirm('要「取代」目前的檢驗項目嗎？\n按「確定」取代，按「取消」則附加在後面。')){
                MODEL.items=MODEL.items.concat(incoming);
            } else {
                MODEL.items=incoming;
            }
            focusItem=0; $('#no-std-hint').hide(); $('#tplModal').modal('hide'); render(); scheduleDraftSave();
        },'json');
    });

    // =====================================================================
    // 儲存 / 退回重做
    // =====================================================================
    function doSave(asRedo){
        if(state.demo){ alert('示範模式不寫入資料庫，請由待驗清單開啟實際待驗項目。'); return; }
        var items=collectItems();
        if(!items.length){ alert('請至少輸入一個檢驗項目'); return; }
        var unfilled = MODEL.items.filter(function(it){ return itemFilledCount(it)<state.sampleN; }).length;
        if(!asRedo && unfilled>0 && !confirm('尚有 '+unfilled+' 個檢驗項目未填滿 '+state.sampleN+' 件，仍要儲存嗎？')) return;

        if(state.editFormId){
            var reason=prompt('請填寫修改原因（必填，會記錄於稽核）：','');
            if(reason===null) return;
            if(reason.trim()===''){ alert('必須填寫修改原因'); return; }
            var $eb=$('#btn-save').prop('disabled',true);
            $.post(API,{ action:'update_inspection', qc_form_id:state.editFormId, reason:reason,
                incoming_qty:parseInt($('#inp-qty').val())||0, sample_qty:parseInt($('#inp-sample').val())||0,
                main_remark:$('#inp-remark').val(), items:JSON.stringify(items),
                pcs_verdicts:JSON.stringify(collectPcsVerdicts())
            }, function(res){
                $eb.prop('disabled',false);
                if(!res.success){ alert('修改失敗：'+res.message); return; }
                var s=res.summary;
                if(window.opener && !window.opener.closed){
                    try{ window.opener.postMessage({type:'qc_inspection_done',bom_ing_fid:s.bom_ing_fid,summary:s,qc_form_id:s.qc_form_id,edited:true},'*'); }catch(e){}
                }
                alert('已儲存修改（qc_form_id='+s.qc_form_id+'）\n判定：'+(s.check_result==='NG'?'不良':'合格')+'　不良數：'+s.ng_qty+'\n此筆已自動回鎖。');
                exitEditMode();
            },'json').fail(function(x){ $eb.prop('disabled',false); alert('修改錯誤：'+x.responseText); });
            return;
        }

        var b=state.batches[state.curBatch];
        var payload={ action:'save_inspection', bom_ing_fid:ctx.bom_ing_fid, d_id:ctx.d_id, part_no:ctx.part_no,
            process_name:ctx.process, batch_no:b.no, round_no:(b.rounds.length+1),
            incoming_qty:parseInt($('#inp-qty').val())||0, sample_qty:parseInt($('#inp-sample').val())||0,
            main_remark:$('#inp-remark').val(), update_std:$('#chk-save-std').is(':checked')?'1':'0',
            items:JSON.stringify(items), pcs_verdicts:JSON.stringify(collectPcsVerdicts()) };
        var $btn=$(asRedo?'#btn-redo':'#btn-save').prop('disabled',true);
        $.post(API, payload, function(res){
            $btn.prop('disabled',false);
            if(!res.success){ alert('儲存失敗：'+res.message); return; }
            var s=res.summary;
            b.rounds.push({ date:'剛剛', status:(asRedo?'NG':s.check_result), qc_form_id:res.qc_form_id,
                            round_no:(b.rounds.length+1), ng_qty:s.ng_qty });
            b.status = asRedo ? 'REDO' : s.check_result;
            renderBatches();
            var hasOpener = window.opener && !window.opener.closed;
            if(hasOpener){
                try{ window.opener.postMessage({ type:'qc_inspection_done', bom_ing_fid:s.bom_ing_fid, summary:s, qc_form_id:res.qc_form_id, redo:!!asRedo }, '*'); }catch(e){}
            }
            function finishSave(){
                if(asRedo){ alert('已記錄退回重做（qc_form_id='+res.qc_form_id+'）。重做送回後可再驗一次。'); return; }
                if(hasOpener){
                    try{ window.opener.focus(); }catch(e){}
                    window.close();
                    setTimeout(function(){
                        if(confirm('檢驗結果已儲存並回傳待驗清單。\n判定：'+(s.check_result==='NG'?'不良':'合格')+'　不良數：'+s.ng_qty+'\n按確定關閉本視窗。')) window.close();
                    }, 400);
                } else {
                    alert('已儲存（qc_form_id='+res.qc_form_id+'）\n判定：'+(s.check_result==='NG'?'不良':'合格')+'　不良數：'+s.ng_qty+'　允收(讓步)：'+s.aod_qty);
                    reloadContext();
                }
            }
            if(s.check_result==='NG') openNgAsk(res.qc_form_id, s, items, finishSave);
            else finishSave();
        },'json').fail(function(x){ $btn.prop('disabled',false); alert('儲存錯誤：'+x.responseText); });
    }
    $('#btn-save').on('click', function(){ doSave(false); });
    $('#btn-redo').on('click', function(){ if($('#inp-remark').val().trim()===''){ $('#inp-remark').val('退回重做'); } doSave(true); });
    $('#btn-cancel').on('click', function(){
        if(!confirm('確定取消？尚未儲存的輸入將不會保留。')) return;
        if(window.opener && !window.opener.closed) window.close();
        else if(history.length>1) history.back();
        else location.href='QC_check_list_test.php';
    });

    // =====================================================================
    // NG → 是否開立品質異常單
    // =====================================================================
    var ngCtx=null;
    function ngSummaryText(items){
        var lines=['品管檢驗判定 NG，NG 項目：'], n=0;
        (items||[]).forEach(function(it){
            if(it.verdict!=='NG') return;
            n++;
            var tol=(it.up||it.lo)?('（公差 '+(it.up?'+'+it.up:'')+(it.lo?' / '+it.lo:'')+'）'):'';
            var ngVals=(it.samples||[]).filter(function(sv){ return sv.r==='NG'; }).map(function(sv){ return sv.v; }).filter(function(v){ return v!==''; }).join(', ');
            lines.push(n+'. '+it.name+'：標準 '+(it.std||'-')+tol+(ngVals?('，NG 實測值：'+ngVals):''));
        });
        return lines.join('\n');
    }
    function ngPrefill(sqty, phenomenon){
        return { sqty:sqty, phenomenon:phenomenon, qa_ps:$('#inp-remark').val(),
                 bom_no:(ctx?ctx.bom:''), bom_process_fids:(ctx?String(ctx.bom_ing_fid):'') };
    }
    function openNgAsk(qcFormId, s, items, done){
        ngCtx={ qcFormId:qcFormId, summary:s, items:items, done:done, decided:false };
        $('#ng-ask-info').html('本次檢驗判定為<b class="text-danger">不良</b>（不良 <b>'+s.ng_qty+'</b> 件）。是否開立品質異常單？<br><small class="text-muted">開立後將自動通知回覆部門與相關人員，並要求回覆回簽。</small>');
        $('#ng-skip-area').hide(); $('#ng-skip-reason').val('');
        $('#ngAskModal').modal('show');
    }
    $('#btn-ng-open').on('click', function(){
        if(!ngCtx) return;
        $('#ngAskModal').modal('hide');
        QAAbnormalModal.open({
            source_type:'QC', source_id:ngCtx.qcFormId,
            title_suffix:(ctx?('料號 '+ctx.part_no):''),
            prefill: ngPrefill(ngCtx.summary.ng_qty, ngSummaryText(ngCtx.items)),
            onCreated:function(r){
                ngCtx.decided=true;
                var qid=ngCtx.qcFormId;
                $.post(API,{ action:'set_ncr_decision', qc_form_id:qid, decision:'OPEN', abnormal_order_id:r.id }, function(){
                    alert('異常單 '+r.no+' 已開立並發送通知。');
                    var d=ngCtx.done; ngCtx=null; if(d) d();
                }, 'json');
            }
        });
    });
    $('#qamModal').on('hidden.bs.modal', function(){
        if(ngCtx && !ngCtx.decided) setTimeout(function(){ $('#ngAskModal').modal('show'); }, 300);
    });
    $('#btn-ng-skip').on('click', function(){ $('#ng-skip-area').slideDown(120); $('#ng-skip-reason').focus(); });
    $('#btn-ng-skip-confirm').on('click', function(){
        if(!ngCtx) return;
        var reason=$('#ng-skip-reason').val().trim();
        if(!reason){ alert('請填寫不開立異常單的原因'); $('#ng-skip-reason').focus(); return; }
        var qid=ngCtx.qcFormId;
        $.post(API,{action:'set_ncr_decision',qc_form_id:qid,decision:'SKIP',reason:reason}, function(r){
            if(!r.success){ alert(r.message||'儲存失敗'); return; }
            ngCtx.decided=true;
            $('#ngAskModal').modal('hide');
            var d=ngCtx.done; ngCtx=null; if(d) d();
        },'json');
    });

    // =====================================================================
    // 列印 / 匯出 CSV（版面沿用舊頁 2-QA-01-06，交瀏覽器原生分頁）
    // =====================================================================
    function currentMeta(){
        var ngPcs=0;
        for(var s=0;s<state.sampleN;s++){ var p=MODEL.pcs[s]; if(p && (p.m? p.v==='NG' : pcsAutoNG(s))) ngPcs++; }
        return { part:(ctx&&ctx.part_no)||'', client:(ctx&&ctx.client)||'', bom:(ctx&&ctx.bom)||'',
                 process:(ctx&&ctx.process)||'', incoming:parseInt($('#inp-qty').val())||0,
                 sample:parseInt($('#inp-sample').val())||0, remark:$('#inp-remark').val()||'',
                 judge:(MODEL.items.length && ngPcs>0)?'不良':'合格', ng:ngPcs };
    }
    function buildPrintHtml(){
        var m=currentMeta(), items=collectItems(), n=state.sampleN;
        var now=new Date(), pad=function(x){ return ('0'+x).slice(-2); };
        var dateStr=now.getFullYear()+'-'+pad(now.getMonth()+1)+'-'+pad(now.getDate());
        var head='<div class="pr-title">品管檢驗記錄表</div><div class="pr-sub">表單編號 2-QA-01-06（線上檢驗系統列印）</div>'+
            '<table class="pr-meta"><tr>'+
            '<td class="k">料號</td><td>'+esc(m.part)+'</td><td class="k">客戶</td><td>'+esc(m.client)+'</td><td class="k">製令/BOM</td><td>'+esc(m.bom)+'</td></tr>'+
            '<tr><td class="k">製程</td><td>'+esc(m.process)+'</td><td class="k">送驗數</td><td>'+m.incoming+'</td><td class="k">抽驗數</td><td>'+m.sample+'</td></tr>'+
            '<tr><td class="k">日期</td><td>'+dateStr+'</td><td class="k">整體判定</td><td>'+m.judge+'（不良 '+m.ng+'）</td><td class="k">備註</td><td>'+esc(m.remark)+'</td></tr></table>';
        var pcsHead=''; for(var i=1;i<=n;i++) pcsHead+='<th>'+i+'</th>';
        var body='';
        items.forEach(function(it,idx){
            var readings=[{tool:toolLabelById(it.tool_id), samples:it.samples}];
            (it.extra||[]).forEach(function(ex){ readings.push({tool:toolLabelById(ex.tool_id), samples:ex.samples}); });
            readings.forEach(function(rd,ri){
                var cells='';
                for(var i2=0;i2<n;i2++){
                    var sv=(rd.samples||[])[i2];
                    var v=(sv && sv.v!=null && sv.v!=='')?sv.v:'';
                    cells+='<td'+((sv&&sv.r==='NG'&&v!=='')?' class="pr-ng"':'')+'>'+esc(v)+'</td>';
                }
                body+='<tr>'+
                    (ri===0?('<td rowspan="'+readings.length+'">'+codeLabel(idx)+'</td><td rowspan="'+readings.length+'" style="text-align:left">'+esc(it.name)+'</td><td rowspan="'+readings.length+'">'+esc(it.std||'')+'</td><td rowspan="'+readings.length+'">'+esc((it.up||'')+(it.lo?(' / '+it.lo):''))+'</td>'):'')+
                    '<td>'+esc(rd.tool||'')+'</td>'+cells+
                    (ri===0?('<td rowspan="'+readings.length+'">'+(it.verdict==='NG'?'NG':'OK')+'</td>'):'')+'</tr>';
                if(ri===0 && it.remark) body+='<tr><td colspan="'+(5+n)+'" style="text-align:left;font-size:11px">備註：'+esc(it.remark)+'</td></tr>';
            });
        });
        var tbl='<table class="pr-items"><thead><tr><th>編號</th><th>檢驗項目</th><th>標準</th><th>公差</th><th>量具</th>'+pcsHead+'<th>判定</th></tr></thead><tbody>'+body+'</tbody></table>';
        var sign='<table class="pr-sign"><tr><td>檢驗員<div class="lbl">Inspector</div></td><td>主管審核<div class="lbl">Approved</div></td><td>日期<div class="lbl">Date</div></td></tr></table>';
        return head+tbl+sign;
    }
    $('#btn-print').on('click', function(){
        if(!ctx){ alert('請先由待驗清單開啟一筆檢驗再列印。'); return; }
        if(!collectItems().length){ alert('尚無檢驗項目可列印。'); return; }
        $('#print-area').html(buildPrintHtml());
        window.print();
    });
    $('#btn-csv').on('click', function(){
        if(!ctx){ alert('請先開啟一筆檢驗再匯出。'); return; }
        var items=collectItems(); if(!items.length){ alert('尚無檢驗項目可匯出。'); return; }
        var n=state.sampleN, m=currentMeta();
        var head=['編號','檢驗項目','標準','上公差','下公差','量具'];
        for(var i=1;i<=n;i++) head.push('第'+i+'件');
        head.push('判定','備註');
        var q=function(s){ s=(s==null?'':String(s)); return '"'+s.replace(/"/g,'""')+'"'; };
        var lines=[head.map(q).join(',')];
        items.forEach(function(it,idx){
            var readings=[{tool:toolLabelById(it.tool_id), samples:it.samples}];
            (it.extra||[]).forEach(function(ex){ readings.push({tool:toolLabelById(ex.tool_id), samples:ex.samples}); });
            readings.forEach(function(rd,ri){
                var row=[ri===0?codeLabel(idx):'', ri===0?it.name:'', ri===0?(it.std||''):'', ri===0?(it.up||''):'', ri===0?(it.lo||''):'', rd.tool||''];
                for(var i3=0;i3<n;i3++){ var sv=(rd.samples||[])[i3]; row.push((sv&&sv.v!=null)?sv.v:''); }
                row.push(ri===0?(it.verdict||''):'', ri===0?(it.remark||''):'');
                lines.push(row.map(q).join(','));
            });
        });
        var csv='﻿'+lines.join('\r\n');
        var blob=new Blob([csv],{type:'text/csv;charset=utf-8;'});
        var a=document.createElement('a'), url=URL.createObjectURL(blob);
        a.href=url; a.download='檢驗記錄_'+(m.part||'')+'_'+m.process+'.csv';
        document.body.appendChild(a); a.click(); document.body.removeChild(a); URL.revokeObjectURL(url);
    });

    // ============ 權限設定（角色 → QC 功能；用既有 Roles_API） ============
    var ROLES_API = '../../src/store/Roles_API.php';
    var QC_FEATURES = [
        { code:'qc_fill_inspection',   label:'填寫檢驗表單（儲存檢驗結果/退回重做）' },
        { code:'qc_edit_history',      label:'修改 / 開放檢驗歷程（主管）' },
        { code:'qc_manage_settings',   label:'管理檢驗設定（量具 / 幾何公差 / 通用樣板）' },
        { code:'qc_manage_sampling',   label:'抽樣規則設定（主管「修改/開放檢驗歷程」固定可用；此處可另單獨授權）' },
        { code:'qc_view_readonly',     label:'唯讀檢閱（僅可檢視檢驗表與異常單，不可修改/開單）' },
        { code:'qa_disposition_reply', label:'勾選 / 回覆異常單「異常處置方式、處置說明」' },
        { code:'qc_supervisor',        label:'認定為主管（收到並核准異常單修改請求、可直接修改異常單；與管理員不同）' }
    ];
    var QC_CODES = QC_FEATURES.map(function(f){ return f.code; });
    var _permRole = null, _permRoleCur = [], _permRolesData = [], _permRoleName = '', _permRoleSys = false;
    $('#btn-perm-setting').on('click', function(e){ e.preventDefault(); _permRole=null; loadPermRoles(); $('#permModal').modal('show'); });
    function loadPermRoles(){
        // 僅載入 QC 模組角色＋系統管理員（各頁面角色分開設定，唯管理員全頁共用）
        $.get(ROLES_API, { action:'get_roles', module:'qc' }, function(res){
            if(!res.success){ $('#perm-role-list').html('<div class="text-danger">載入角色失敗</div>'); return; }
            _permRolesData = res.data || [];
            $('#perm-role-list').html(_permRolesData.map(function(r){
                return '<a href="#" class="list-group-item perm-role" data-id="'+r.role_id+'" data-sys="'+r.is_system+'" data-name="'+esc(r.role_name)+'">'+esc(r.role_name)+(r.is_system==1?' <span class="label label-default">系統</span>':'')+'</a>';
            }).join(''));
            $('#btn-rename-role,#btn-delete-role').prop('disabled', true);
        },'json');
    }
    // 新增角色
    $('#btn-add-role').on('click', function(){
        var name=prompt('新增角色名稱：',''); if(name===null) return; name=name.trim(); if(!name) return;
        $.post(ROLES_API,{action:'save_role',role_name:name,module:'qc'},function(res){
            if(!res.success){ alert('新增失敗：'+(res.message||'')); return; } loadPermRoles();
        },'json');
    });
    // 重新命名
    $('#btn-rename-role').on('click', function(){
        if(!_permRole||_permRoleSys) return;
        var name=prompt('修改角色名稱：',_permRoleName); if(name===null) return; name=name.trim(); if(!name) return;
        $.post(ROLES_API,{action:'save_role',role_id:_permRole,role_name:name},function(res){
            if(!res.success){ alert('修改失敗：'+(res.message||'')); return; } loadPermRoles();
        },'json');
    });
    // 刪除角色（先列人員→可轉移→輸入 Y）
    $('#btn-delete-role').on('click', function(){
        if(!_permRole||_permRoleSys) return;
        $('#del-role-name').text(_permRoleName);
        $('#del-confirm-y').val('');
        $('#del-role-users').html('讀取人員中…');
        $('#del-transfer-wrap').hide();
        $('#roleDeleteModal').modal('show');
        $.get(ROLES_API,{action:'get_users'},function(res){
            var users=(res.data||[]).filter(function(u){ return (u.roles||[]).some(function(r){ return r.role_id==_permRole; }); });
            $('#roleDeleteModal').data('users', users);
            if(!users.length){ $('#del-role-users').html('<div class="alert alert-info" style="margin:0;">目前沒有人員被指派為此角色，可直接刪除。</div>'); }
            else {
                $('#del-role-users').html('<div class="alert alert-warning" style="margin:0;"><b>下列 '+users.length+' 位人員目前是「'+esc(_permRoleName)+'」：</b><br>'+users.map(function(u){ return esc(u.user_cname||u.user_uname||u.id); }).join('、')+'</div>');
                var opts='<option value="">不轉移（僅移除此角色指派）</option>'+_permRolesData.filter(function(r){ return r.role_id!=_permRole; }).map(function(r){ return '<option value="'+r.role_id+'">'+esc(r.role_name)+'</option>'; }).join('');
                $('#del-transfer-role').html(opts); $('#del-transfer-wrap').show();
            }
        },'json');
    });
    $('#btn-confirm-del-role').on('click', function(){
        if($('#del-confirm-y').val()!=='Y'){ alert('請輸入大寫 Y 以確認刪除'); return; }
        var users=$('#roleDeleteModal').data('users')||[];
        var transferTo=$('#del-transfer-role').val();
        var $b=$(this).prop('disabled',true);
        function doDelete(){
            $.post(ROLES_API,{action:'delete_role',role_id:_permRole},function(res){
                $b.prop('disabled',false);
                if(!res.success){ alert('刪除失敗：'+(res.message||'')); return; }
                $('#roleDeleteModal').modal('hide'); _permRole=null; $('#perm-feature-box').html('<p class="text-muted">← 請先選擇角色</p>'); $('#btn-save-perm').prop('disabled',true);
                loadPermRoles(); alert('角色已刪除。');
            },'json').fail(function(x){ $b.prop('disabled',false); alert('刪除錯誤：'+x.responseText); });
        }
        if(transferTo && users.length){
            // 先把人員指派到新角色，再刪除舊角色(delete_role 會移除舊指派)
            var i=0; (function next(){
                if(i>=users.length){ doDelete(); return; }
                $.post(ROLES_API,{action:'assign_user_role',user_id:users[i].id,role_id:transferTo},function(){ i++; next(); },'json')
                 .fail(function(){ i++; next(); });
            })();
        } else { doDelete(); }
    });

    $('#perm-role-list').on('click','.perm-role', function(e){ e.preventDefault();
        $('.perm-role').removeClass('active'); $(this).addClass('active');
        _permRole=$(this).data('id'); var isSys=$(this).data('sys')==1;
        _permRoleName=$(this).data('name'); _permRoleSys=isSys;
        $('#btn-rename-role,#btn-delete-role').prop('disabled', isSys);
        $.get(ROLES_API,{action:'get_role_features',role_id:_permRole},function(res){
            _permRoleCur = res.success ? (res.data||[]) : [];
            var all = _permRoleCur.indexOf('all')!==-1;
            var html = QC_FEATURES.map(function(f){
                var chk=(all||_permRoleCur.indexOf(f.code)!==-1)?'checked':'';
                return '<div class="checkbox"><label><input type="checkbox" class="perm-feat" value="'+f.code+'" '+chk+(isSys||all?' disabled':'')+'> '+f.label+'</label></div>';
            }).join('');
            if(all) html+='<p class="text-info">此角色擁有全部權限(all)，無需逐項勾選。</p>';
            if(isSys&&!all) html+='<p class="text-muted">系統角色不可修改。</p>';
            $('#perm-feature-box').html(html);
            $('#btn-save-perm').prop('disabled', isSys||all);
        },'json');
    });
    $('#btn-save-perm').on('click', function(){
        if(!_permRole) return;
        var checked=$('#perm-feature-box .perm-feat:checked').map(function(){return this.value;}).get();
        // 合併：保留此角色非 QC 的既有功能 + 本次勾選的 QC 功能（避免洗掉其他模組權限）
        var merged=_permRoleCur.filter(function(c){ return QC_CODES.indexOf(c)===-1; }).concat(checked);
        $.post(ROLES_API,{action:'save_role_features',role_id:_permRole,features:JSON.stringify(merged)},function(res){
            if(!res.success){ alert('儲存失敗：'+(res.message||'')); return; }
            alert('角色權限已儲存。'); _permRoleCur=merged;
        },'json').fail(function(x){ alert('儲存錯誤：'+x.responseText); });
    });

    // ============ 設定：量具設定（種類/編號 CRUD、取代刪除） ============
    var toolMg = { categories:[], tools:[] }, curToolCat = null;
    $('#btn-tool-setting').on('click', function(e){ e.preventDefault(); loadToolMg(); $('#toolManageModal').modal('show'); });
    function loadToolMg(){
        $('#tool-cat-list').html('<div class="list-group-item text-muted">載入中…</div>');
        $.post(API,{action:'get_tool_manage_data'},function(res){
            if(!res.success){ alert('載入失敗：'+(res.message||'')); return; }
            toolMg = res;
            // 同步主表量具下拉來源（之後新增的檢驗項目列即用最新清單）
            TOOLS = res.categories.map(function(c){ return c.QC_Tool; });
            renderToolCats();
            if(curToolCat && res.categories.some(function(c){ return c.QC_Tool_List_id==curToolCat; })){
                renderToolInsts(curToolCat);
            } else {
                curToolCat=null; $('#tool-instance-area').hide(); $('#tool-instance-empty').show();
            }
        },'json');
    }
    function renderToolCats(){
        $('#tool-cat-list').html(toolMg.categories.map(function(c){
            return '<a href="#" class="list-group-item tool-cat-item '+(c.QC_Tool_List_id==curToolCat?'active':'')+'" data-id="'+c.QC_Tool_List_id+'" data-name="'+esc(c.QC_Tool)+'">'+esc(c.QC_Tool)+
                '<span class="pull-right">'+
                '<button class="btn btn-xs btn-warning btn-edit-tc" style="margin:0;" title="改名"><i class="fa fa-pencil"></i></button> '+
                '<button class="btn btn-xs btn-info btn-replace-tc" style="margin:0;" title="取代並刪除"><i class="fa fa-exchange"></i></button> '+
                '<button class="btn btn-xs btn-danger btn-del-tc" style="margin:0;" title="刪除"><i class="fa fa-trash"></i></button>'+
                '</span></a>';
        }).join('') || '<div class="list-group-item text-muted">尚無量具種類</div>');
    }
    $('#tool-cat-list').on('click','.tool-cat-item', function(e){
        if($(e.target).closest('button').length) return;
        e.preventDefault();
        curToolCat=$(this).data('id');
        $('#current-cat-name').text($(this).data('name'));
        $('#ti-cat-id').val(curToolCat);
        renderToolCats(); renderToolInsts(curToolCat);
    });
    function renderToolInsts(catId){
        $('#tool-instance-empty').hide(); $('#tool-instance-area').show();
        var list=toolMg.tools.filter(function(t){ return t.QC_Tool_List_id==catId; });
        $('#tool-inst-list').html(list.length ? list.map(function(t){
            return '<tr><td>'+esc(t.Tool_No)+'</td><td>'+
                '<button class="btn btn-xs btn-info btn-edit-ti" data-id="'+t.Tool_id+'" data-no="'+esc(t.Tool_No)+'"><i class="fa fa-pencil"></i></button> '+
                '<button class="btn btn-xs btn-danger btn-del-ti" data-id="'+t.Tool_id+'"><i class="fa fa-trash"></i></button></td></tr>';
        }).join('') : '<tr><td colspan="2" class="text-center text-muted">尚無編號</td></tr>');
    }
    $('#tool-cat-form').on('submit', function(e){
        e.preventDefault();
        $.post(API,{action:'save_tool_category',id:$('#tc-id').val(),name:$('#tc-name').val()},function(res){
            if(!res.success){ alert(res.message||'儲存失敗'); return; }
            $('#tc-id').val(''); $('#tc-name').val('');
            $('#btn-save-tc').text('新增'); $('#btn-cancel-tc').hide();
            loadToolMg();
        },'json');
    });
    $('#tool-cat-list').on('click','.btn-edit-tc', function(){
        var $i=$(this).closest('.tool-cat-item');
        $('#tc-id').val($i.data('id')); $('#tc-name').val($i.data('name')).focus();
        $('#btn-save-tc').text('儲存'); $('#btn-cancel-tc').show();
    });
    $('#btn-cancel-tc').on('click', function(){
        $('#tc-id').val(''); $('#tc-name').val('');
        $('#btn-save-tc').text('新增'); $(this).hide();
    });
    $('#tool-cat-list').on('click','.btn-del-tc', function(){
        if(!confirm('確定刪除此量具種類？')) return;
        $.post(API,{action:'delete_tool_category',id:$(this).closest('.tool-cat-item').data('id')},function(res){
            if(!res.success){ alert(res.message||'刪除失敗'); return; }
            curToolCat=null; loadToolMg();
        },'json');
    });
    $('#tool-cat-list').on('click','.btn-replace-tc', function(e){
        e.stopPropagation();
        var $i=$(this).closest('.tool-cat-item'), oldId=$i.data('id');
        $('#replace-old-id').val(oldId); $('#replace-old-name').text($i.data('name'));
        $('#replace-new-id').html(toolMg.categories.filter(function(c){ return c.QC_Tool_List_id!=oldId; })
            .map(function(c){ return '<option value="'+c.QC_Tool_List_id+'">'+esc(c.QC_Tool)+'</option>'; }).join(''));
        $('#toolReplaceModal').modal('show');
    });
    $('#btn-confirm-replace').on('click', function(){
        $.post(API,{action:'replace_tool_category',old_id:$('#replace-old-id').val(),new_id:$('#replace-new-id').val()},function(res){
            if(!res.success){ alert(res.message||'取代失敗'); return; }
            $('#toolReplaceModal').modal('hide'); curToolCat=null; loadToolMg();
        },'json');
    });
    $('#tool-inst-form').on('submit', function(e){
        e.preventDefault();
        $.post(API,{action:'save_tool_instance',id:$('#ti-id').val(),cat_id:$('#ti-cat-id').val(),no:$('#ti-no').val()},function(res){
            if(!res.success){ alert(res.message||'儲存失敗'); return; }
            $('#ti-id').val(''); $('#ti-no').val('');
            $('#btn-save-ti').text('新增編號'); $('#btn-cancel-ti').hide();
            loadToolMg();
        },'json');
    });
    $('#tool-inst-list').on('click','.btn-edit-ti', function(){
        $('#ti-id').val($(this).data('id')); $('#ti-no').val($(this).data('no')).focus();
        $('#btn-save-ti').text('儲存'); $('#btn-cancel-ti').show();
    });
    $('#btn-cancel-ti').on('click', function(){
        $('#ti-id').val(''); $('#ti-no').val('');
        $('#btn-save-ti').text('新增編號'); $(this).hide();
    });
    $('#tool-inst-list').on('click','.btn-del-ti', function(){
        if(!confirm('確定刪除此量具編號？')) return;
        $.post(API,{action:'delete_tool_instance',id:$(this).data('id')},function(res){
            if(!res.success){ alert(res.message||'刪除失敗'); return; }
            loadToolMg();
        },'json');
    });

    // ============ 設定：幾何公差管理 ============
    $('#btn-special-setting').on('click', function(e){ e.preventDefault(); loadSpecialItems(); $('#specialItemManageModal').modal('show'); });
    function loadSpecialItems(){
        $('#manage-special-list').html('<div class="list-group-item text-muted">載入中…</div>');
        $.post(API,{action:'get_special_items'},function(res){
            if(!res.success){ alert('載入失敗：'+(res.message||'')); return; }
            var items=res.special_items||[];
            $('#manage-special-list').html(items.length ? items.map(function(it){
                return '<div class="list-group-item clearfix" data-id="'+it.id+'" data-name="'+esc(it.name)+'" data-symbol="'+esc(it.symbol||'')+'" data-code="'+esc(it.code||'')+'">'+
                    '<span class="badge pull-left" style="margin-right:10px;background:#777;">'+esc(it.symbol||'')+'</span>'+
                    '<strong>'+esc(it.name)+'</strong> <small class="text-muted">('+esc(it.code||'')+')</small>'+
                    '<div class="pull-right">'+
                    '<button class="btn btn-xs btn-info btn-edit-si"><i class="fa fa-pencil"></i></button> '+
                    '<button class="btn btn-xs btn-danger btn-del-si"><i class="fa fa-trash"></i></button>'+
                    '</div></div>';
            }).join('') : '<div class="list-group-item text-muted">尚無資料，請於上方新增</div>');
        },'json');
    }
    $('#special-item-form').on('submit', function(e){
        e.preventDefault();
        $.post(API,{action:'save_special_item',id:$('#si-id').val(),name:$('#si-name').val(),symbol:$('#si-symbol').val(),code:$('#si-code').val()},function(res){
            if(!res.success){ alert(res.message||'儲存失敗'); return; }
            resetSiForm(); loadSpecialItems();
        },'json');
    });
    $('#manage-special-list').on('click','.btn-edit-si', function(){
        var $i=$(this).closest('.list-group-item');
        $('#si-id').val($i.data('id')); $('#si-name').val($i.data('name'));
        $('#si-symbol').val($i.data('symbol')); $('#si-code').val($i.data('code'));
        $('#btn-save-si').text('儲存'); $('#btn-cancel-si').show();
    });
    $('#btn-cancel-si').on('click', resetSiForm);
    function resetSiForm(){
        $('#si-id').val(''); $('#special-item-form')[0].reset();
        $('#btn-save-si').text('新增'); $('#btn-cancel-si').hide();
    }
    $('#manage-special-list').on('click','.btn-del-si', function(){
        if(!confirm('確定刪除？')) return;
        $.post(API,{action:'delete_special_item',id:$(this).closest('.list-group-item').data('id')},function(res){
            if(!res.success){ alert(res.message||'刪除失敗'); return; }
            loadSpecialItems();
        },'json');
    });

    // ============ 設定：通用樣板管理（建立/更新用主畫面表格內容） ============
    var editingTplId = null;
    $('#btn-template-setting').on('click', function(e){ e.preventDefault(); loadTplManage(); $('#templateManageModal').modal('show'); });
    function loadTplManage(){
        $('#template-list').html('<div class="list-group-item text-muted">載入中…</div>');
        $.post(API,{action:'manage_templates',sub_action:'list'},function(res){
            if(!res.success){ alert('載入失敗：'+(res.message||'')); return; }
            var d=res.data||[];
            $('#template-list').html(d.length ? d.map(function(t){
                return '<div class="list-group-item clearfix"><strong>'+esc(t.template_name)+'</strong>'+
                    '<div class="pull-right">'+
                    '<button class="btn btn-xs btn-info btn-edit-tpl" data-id="'+t.template_id+'" data-name="'+esc(t.template_name)+'"><i class="fa fa-pencil"></i> 編輯</button> '+
                    '<button class="btn btn-xs btn-danger btn-del-tpl" data-id="'+t.template_id+'"><i class="fa fa-trash"></i> 刪除</button>'+
                    '</div></div>';
            }).join('') : '<div class="list-group-item text-muted">尚無樣板</div>');
        },'json');
    }
    $('#btn-create-template').on('click', function(){
        var name=$('#new-template-name').val().trim();
        if(!name){ alert('請輸入樣板名稱'); $('#new-template-name').focus(); return; }
        var items=collectItems();
        if(!items.length){ alert('主畫面檢驗項目表是空的，無法建立樣板'); return; }
        var payload={action:'manage_templates',sub_action:'save',name:name,items:JSON.stringify(items)};
        if(editingTplId) payload.template_id=editingTplId;
        var $b=$(this).prop('disabled',true);
        $.post(API,payload,function(res){
            $b.prop('disabled',false);
            if(!res.success){ alert(res.message||'儲存失敗'); return; }
            resetTplEdit(); loadTplManage();
        },'json').fail(function(){ $b.prop('disabled',false); alert('儲存錯誤'); });
    });
    $('#template-list').on('click','.btn-edit-tpl', function(){
        if(!confirm('這將清空目前主畫面的檢驗項目表，並載入此樣板內容供編輯，確定嗎？')) return;
        var tid=$(this).data('id'), tname=$(this).data('name');
        $.post(API,{action:'manage_templates',sub_action:'get_items',template_id:tid},function(res){
            if(!res.success){ alert('載入失敗：'+(res.message||'')); return; }
            renderItems(res.items||[]);
            $('#no-std-hint').hide();
            editingTplId=tid;
            $('#new-template-name').val(tname);
            $('#btn-create-template').text('更新樣板').removeClass('btn-success').addClass('btn-warning');
            $('#btn-cancel-edit-template').show();
            $('#templateManageModal').modal('hide');
            $('html,body').animate({scrollTop:$('#items-table').offset().top-120},300);
        },'json');
    });
    $('#template-list').on('click','.btn-del-tpl', function(){
        if(!confirm('確定刪除此樣板？')) return;
        $.post(API,{action:'manage_templates',sub_action:'delete',template_id:$(this).data('id')},function(res){
            if(!res.success){ alert(res.message||'刪除失敗'); return; }
            loadTplManage();
        },'json');
    });
    $('#btn-cancel-edit-template').on('click', resetTplEdit);
    function resetTplEdit(){
        editingTplId=null;
        $('#new-template-name').val('');
        $('#btn-create-template').text('從當前表格建立樣板').removeClass('btn-warning').addClass('btn-success');
        $('#btn-cancel-edit-template').hide();
    }

    // ============ 設定：抽樣規則 ============
    $('#btn-sampling-setting').on('click', function(e){ e.preventDefault(); loadRules(); $('#samplingRuleModal').modal('show'); });
    function loadRules(){
        $('#rule-list').html('<tr><td colspan="3" class="text-center text-muted">載入中…</td></tr>');
        $.post(API,{action:'manage_sampling_rules',sub_action:'list'},function(res){
            if(!res.success){ alert('載入失敗：'+(res.message||'')); return; }
            var d=res.data||[];
            $('#rule-list').html(d.length ? d.map(function(r){
                return '<tr data-id="'+r.rule_id+'" data-min="'+r.min_qty+'" data-max="'+r.max_qty+'" data-sample="'+r.sample_qty+'">'+
                    '<td>'+r.min_qty+' ~ '+r.max_qty+'</td><td>'+r.sample_qty+'</td><td>'+
                    '<button class="btn btn-xs btn-info btn-edit-rule"><i class="fa fa-pencil"></i></button> '+
                    '<button class="btn btn-xs btn-danger btn-del-rule"><i class="fa fa-trash"></i></button></td></tr>';
            }).join('') : '<tr><td colspan="3" class="text-center text-muted">尚無規則（無規則時系統用簡易推估：≥500抽8、≥100抽5、其餘抽3）</td></tr>');
        },'json');
    }
    $('#rule-form').on('submit', function(e){
        e.preventDefault();
        $.post(API,{action:'manage_sampling_rules',sub_action:'save',id:$('#rule-id').val(),
            min:$('#rule-min').val(),max:$('#rule-max').val(),sample:$('#rule-sample').val()},function(res){
            if(!res.success){ alert(res.message||'儲存失敗'); return; }
            resetRuleForm(); loadRules();
        },'json');
    });
    $('#rule-list').on('click','.btn-edit-rule', function(){
        var $tr=$(this).closest('tr');
        $('#rule-id').val($tr.data('id'));
        $('#rule-min').val($tr.data('min')); $('#rule-max').val($tr.data('max')); $('#rule-sample').val($tr.data('sample'));
        $('#btn-save-rule').text('儲存'); $('#btn-cancel-rule').show();
    });
    $('#btn-cancel-rule').on('click', resetRuleForm);
    function resetRuleForm(){
        $('#rule-id').val(''); $('#rule-form')[0].reset();
        $('#btn-save-rule').text('新增'); $('#btn-cancel-rule').hide();
    }
    $('#rule-list').on('click','.btn-del-rule', function(){
        if(!confirm('確定刪除此規則？')) return;
        $.post(API,{action:'manage_sampling_rules',sub_action:'delete',id:$(this).closest('tr').data('id')},function(res){
            if(!res.success){ alert(res.message||'刪除失敗'); return; }
            loadRules();
        },'json');
    });

    // ============ #8 同料號歷次檢驗查詢 ============
    $('#btn-history').on('click', function(){
        if(!ctx || !ctx.d_id){ alert('請先由待驗清單開啟一筆檢驗（需有料號）再查詢歷史。'); return; }
        openPartHistory();
    });
    function ensureHistoryModal(){
        if($('#partHistModal').length) return;
        $('body').append(
          '<div class="modal fade" id="partHistModal" tabindex="-1" role="dialog"><div class="modal-dialog modal-lg"><div class="modal-content">'+
          '<div class="modal-header"><button type="button" class="close" data-dismiss="modal">&times;</button>'+
          '<h4 class="modal-title"><i class="fa fa-history"></i> 同料號歷次檢驗</h4></div>'+
          '<div class="modal-body"><div id="partHistList">載入中…</div><div id="partHistDetail" style="margin-top:12px;"></div></div>'+
          '<div class="modal-footer"><button class="btn btn-default" data-dismiss="modal">關閉</button></div>'+
          '</div></div></div>');
    }
    function openPartHistory(){
        ensureHistoryModal();
        $('#partHistDetail').empty(); $('#partHistList').html('載入中…');
        $('#partHistModal').modal('show');
        $.post(API,{action:'history_by_part', d_id:ctx.d_id}, function(res){
            if(!res.success){ $('#partHistList').html('查詢失敗：'+esc(res.message||'')); return; }
            var rows=res.rows||[];
            if(!rows.length){ $('#partHistList').html('<div class="text-muted">此料號尚無歷史檢驗紀錄。</div>'); return; }
            var h='<div class="text-muted" style="margin-bottom:6px;">料號 <b>'+esc(ctx.part_no||'')+'</b>　共 '+rows.length+' 筆（點「檢視」看逐項實測與同尺寸落點）</div>'+
              '<div style="max-height:230px;overflow:auto;"><table class="table table-condensed table-bordered"><thead><tr>'+
              '<th>日期</th><th>製令</th><th>製程</th><th>批/複</th><th>判定</th><th>不良</th><th>檢驗人</th><th></th></tr></thead><tbody>';
            rows.forEach(function(r){
                var d=String(r.check_date||r.created_at||'').substring(0,16);
                h+='<tr><td>'+esc(d)+'</td><td>'+esc(r.bom||'')+'</td><td>'+esc(r.process_name||'')+'</td>'+
                   '<td>'+(r.batch_no||1)+'/'+(r.round_no||1)+'</td><td>'+statusLabel(r.check_result)+'</td>'+
                   '<td>'+(r.ng_qty||0)+'</td><td>'+esc(r.user_cname||r.created_by||'')+'</td>'+
                   '<td><button class="btn btn-xs btn-primary ph-view" data-id="'+r.qc_form_id+'">檢視</button></td></tr>';
            });
            h+='</tbody></table></div>';
            $('#partHistList').html(h);
        }, 'json');
    }
    $(document).on('click','.ph-view', function(){
        var id=$(this).data('id');
        $('#partHistDetail').html('載入中…');
        $.post(API,{action:'get_history_record',qc_form_id:id}, function(res){
            if(!res.success){ $('#partHistDetail').html('載入失敗：'+esc(res.message||'')); return; }
            $('#partHistDetail').html(renderHistDetail(res));
        }, 'json');
    });
    function renderHistDetail(res){
        var h=res.header, its=res.items||[];
        var out='<div class="well well-sm" style="margin-bottom:8px;"><b>逐項實測</b>（單號 '+h.qc_form_id+'；送驗 '+(h.incoming_qty||0)+'／抽驗 '+(h.sample_qty||0)+'；整體 '+(h.check_result==='NG'?'<span class="text-danger">不良</span>':'合格')+'）</div>';
        out+='<div style="max-height:300px;overflow:auto;"><table class="table table-condensed table-bordered"><thead><tr><th>項目</th><th>標準</th><th>量具</th><th>實測（各PCS）</th><th>判定</th></tr></thead><tbody>';
        its.forEach(function(it){
            var readings=[{tool:(it.tool||''), samples:it.samples}];
            (it.extra||[]).forEach(function(ex){ readings.push({tool:(ex.method||ex.tool_no||''), samples:ex.samples}); });
            readings.forEach(function(rd,ri){
                var vals=(rd.samples||[]).map(function(s){ return (s&&s.v!==''&&s.v!=null)?('<span class="'+((s.r==='NG')?'text-danger':'')+'">'+esc(s.v)+'</span>'):'·'; }).join('　');
                out+='<tr>'+(ri===0?('<td rowspan="'+readings.length+'">'+esc(it.name)+'</td><td rowspan="'+readings.length+'">'+esc(it.std||'')+((it.up||it.lo)?(' ('+esc(it.up||'')+'/'+esc(it.lo||'')+')'):'')+'</td>'):'')+
                    '<td>'+esc(rd.tool||'')+'</td><td>'+vals+'</td>'+(ri===0?('<td rowspan="'+readings.length+'">'+(it.verdict==='NG'?'<span class="text-danger">NG</span>':(it.verdict==='AOD'?'特採':'OK'))+'</td>'):'')+'</tr>';
            });
            if(it.remark) out+='<tr><td colspan="5" class="text-muted" style="font-size:12px">備註：'+esc(it.remark)+'</td></tr>';
        });
        out+='</tbody></table></div>';
        return out;
    }
});
</script>
</body>
</html>
