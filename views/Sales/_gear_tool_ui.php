<?php
// c:\MAMP\htdocs\EGsystem\views\Sales\_gear_tool_ui.php
// ── 齒輪／花鍵計算工具：畫面（CSS＋浮動視窗 HTML＋JS）共用檔，唯一實作 ────────
// 2026-08-25 由 views/Sales/NewOrder_Track.php 抽出，讓其他頁面也能開同一個工具視窗。
// 用法（任何頁面）：
//   1) 在 </body> 前： $GEAR_TOOL_PDO = $pdo; include __DIR__.'/_gear_tool_ui.php';
//   2) 自己放一顆按鈕： <button onclick="openGearTool()">齒輪計算</button>
//      （按鈕要不要顯示請用 $show_gear_tool 判斷——本檔會自動算好）
// 後端 API 一律走 views/Sales/gear_tool_api.php（見 src/common/gear_tool_lib.php），
// 不要在自己的頁面再寫一份 gear_*/spline_* 的 action。
require_once __DIR__ . '/../../src/common/gear_tool_lib.php';

// 使用權限：呼叫端已經算好就沿用（NewOrder_Track 有自己的 RBAC 區塊），沒算過才在這裡算
if (!isset($show_gear_tool)) {
    $__gear_pdo = null;
    foreach ([$GEAR_TOOL_PDO ?? null, $pdo ?? null, $db ?? null] as $__c) {
        if ($__c instanceof PDO) { $__gear_pdo = $__c; break; }
    }
    $show_gear_tool = $__gear_pdo ? gear_tool_can_use($__gear_pdo, intval($_SESSION['id'] ?? 0)) : false;
}
if (!isset($is_gear_admin)) $is_gear_admin = gear_tool_is_admin();

// 停靠模式（Figma 式「頁面內側邊面板」）：呼叫端設 $gear_tool_dock = '#canvas-wrap'（CSS 選擇器＝
// 面板要貼在哪個容器的右緣）才會出現「停靠／浮出」切換鈕；沒設定的頁面維持原本的浮動視窗、行為完全不變。
// $gear_tool_dock_default = 第一次開啟時預設停靠還是浮動（之後記住使用者自己的選擇）。
if (!isset($gear_tool_dock)) $gear_tool_dock = '';
if (!isset($gear_tool_dock_default)) $gear_tool_dock_default = ($gear_tool_dock !== '');
// $gear_tool_dock_offset = 同容器內另一個也貼右緣的面板（例：批圖編輯器的標籤庫）。
// 設了之後那個面板一打開，本面板會自動往左讓開，兩個面板並排而不是互相蓋住。
if (!isset($gear_tool_dock_offset)) $gear_tool_dock_offset = '';

// API 網址：由本檔自身位置推導成網站絕對路徑，這樣不管是哪個目錄的頁面 include 都指得到
$__gear_api_url = 'gear_tool_api.php';
$__droot = rtrim(str_replace(chr(92), '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
$__here  = str_replace(chr(92), '/', __DIR__);
if ($__droot !== '' && strpos($__here, $__droot) === 0) {
    $__gear_api_url = substr($__here, strlen($__droot)) . '/gear_tool_api.php';
}
?>
<?php if ($show_gear_tool): ?>
<script>
window.GEAR_TOOL_API_URL      = <?= json_encode($__gear_api_url) ?>;
window.GEAR_TOOL_DOCK_SEL     = <?= json_encode($gear_tool_dock) ?>;
window.GEAR_TOOL_DOCK_DEFAULT = <?= $gear_tool_dock_default ? 'true' : 'false' ?>;
window.GEAR_TOOL_DOCK_OFFSET_SEL = <?= json_encode($gear_tool_dock_offset) ?>;
</script>
<style>
        /* ── 齒輪計算工具 ───────────────────────────────────────────── */
        #gear-tool-window {
            position: fixed; z-index: 10500; display: none;
            width: 1280px; max-width: 98vw;
            top: 55px; left: 50%; transform: translateX(-50%);
            background: #fff; border-radius: 8px;
            box-shadow: 0 12px 40px rgba(0,0,0,.35);
            border: 1px solid #b0bec5;
            overflow: visible; font-size: 13px;
        }
        #gear-tool-hdr {
            border-radius: 8px 8px 0 0;
            background: linear-gradient(135deg,#1a252f,#2980b9);
            color: #fff; padding: 9px 14px; cursor: move;
            display: flex; align-items: center; justify-content: space-between;
            user-select: none;
        }
        #gear-tool-hdr .gear-hdr-title { font-size: 14px; font-weight: 600; display: flex; align-items: center; gap: 8px; }
        #gear-tool-hdr .gear-hdr-btns  { display: flex; align-items: center; gap: 6px; }
        #gear-tool-hdr button { background: rgba(255,255,255,.15); border: 1px solid rgba(255,255,255,.3); color: #fff; border-radius: 4px; padding: 2px 8px; font-size: 12px; cursor: pointer; transition: background .2s; }
        #gear-tool-hdr button:hover { background: rgba(255,255,255,.3); }
        .gear-tabs { display: flex; flex-wrap: wrap; background: #ecf0f1; border-bottom: 2px solid #bdc3c7; overflow-x: auto; scrollbar-width: none; -ms-overflow-style: none; }
        .gear-tabs::-webkit-scrollbar { height: 0; width: 0; }
        .gear-tabs::-webkit-scrollbar-button { display: none; width: 0; height: 0; }
        .gear-tab-btn {
            padding: 7px 14px; cursor: pointer; white-space: nowrap; font-size: 12px; font-weight: 600;
            border: none; background: none; color: #666; border-bottom: 3px solid transparent; margin-bottom: -2px;
            transition: color .2s, border-color .2s;
        }
        .gear-tab-btn.active { color: #1a6fa0; border-bottom-color: #1a6fa0; background: #fff; }
        .gear-tab-btn:disabled { color: #bbb; cursor: not-allowed; }
        .gear-tab-btn:not(:disabled):hover { color: #2980b9; }
        .gear-tab-pane { display: none; padding: 14px; }
        .gear-tab-pane.active { display: block; }
        #gear-tool-body { max-height: calc(85vh - 90px); overflow-y: auto; }
        #gear-tool-body::-webkit-scrollbar-button { display: none; height: 0; width: 0; }

        /* 齒輪工具：輸入/輸出欄位 */
        .gear-field-group { margin-bottom: 10px; }
        .gear-field-group label { display: block; font-size: 11px; color: #555; margin-bottom: 3px; font-weight: 600; }
        .gear-input, .gear-output-val {
            font-family: "Consolas", "Courier New", monospace;
            font-size: 13px; border: 1px solid #ccc; border-radius: 4px;
            padding: 4px 7px; width: 100%; box-sizing: border-box;
        }
        .gear-input { background: #fff; color: #222; }
        .gear-input:focus { outline: none; border-color: #2980b9; box-shadow: 0 0 0 2px rgba(41,128,185,.15); }
        .gear-input[readonly], .gear-output-val { background: #f4f8fb; color: #1a3a50; }
        .gear-input.gear-err { background: #fff0f0; border-color: #e74c3c; color: #c0392b; }
        .gear-input.gear-warn { background: #fffbea; border-color: #f39c12; }
        .gear-output-val { display: block; }
        .gear-output-val.val-ok    { background: #f0fff4; color: #1e7e34; }
        .gear-output-val.val-err   { background: #fff0f0; color: #c0392b; font-weight: 700; }
        .gear-output-val.val-warn  { background: #fffbea; color: #856404; font-weight: 700; }
        .gear-output-val.val-boss  { background: #fff3e0; color: #e65100; font-weight: 700; }
        .gear-output-val.val-cust  { background: #f3e5f5; color: #6a1b9a; font-weight: 700; }
        .gear-out-label.lbl-cust   { color: #6a1b9a; font-weight: 700; }

        /* DMS 三欄角度輸入 */
        .dms-wrap { display: flex; align-items: center; gap: 4px; }
        .dms-wrap input[type=number] {
            font-family: "Consolas","Courier New",monospace; font-size: 13px;
            border: 1px solid #ccc; border-radius: 4px; padding: 4px 5px;
            width: 52px; text-align: right;
            appearance: textfield; -moz-appearance: textfield;
        }
        .dms-wrap input[type=number]::-webkit-outer-spin-button,
        .dms-wrap input[type=number]::-webkit-inner-spin-button { -webkit-appearance: none; appearance: none; margin: 0; }
        .gear-input[type=number]::-webkit-outer-spin-button,
        .gear-input[type=number]::-webkit-inner-spin-button { -webkit-appearance: none; appearance: none; margin: 0; }
        .gear-input[type=number] { appearance: textfield; -moz-appearance: textfield; }
        .dms-wrap input.dms-err { border-color: #e74c3c; background: #fff0f0; }
        .dms-sym { font-size: 14px; color: #555; }
        .dms-dec { font-size: 11px; color: #999; margin-left: 4px; white-space: nowrap; }

        /* 兩欄佈局 */
        .gear-two-col { display: flex; gap: 14px; }
        .gear-col-left  { flex: 1; min-width: 220px; }
        .gear-col-right { flex: 1.1; min-width: 240px; }
        .gear-section-title {
            font-size: 11px; font-weight: 700; color: #1a6fa0; text-transform: uppercase;
            letter-spacing: .5px; margin: 0 0 8px; padding-bottom: 4px;
            border-bottom: 1px solid #d0e4f0;
        }
        .gear-out-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 6px 10px; }
        .gear-out-row { display: flex; flex-direction: column; }
        .gear-out-label { font-size: 10px; color: #888; margin-bottom: 2px; }

        /* 按鈕 */
        .btn-gear-calc  { background: #2980b9; color: #fff; border: none; border-radius: 4px; padding: 6px 18px; font-size: 13px; font-weight: 600; cursor: pointer; }
        .btn-gear-calc:hover { background: #1a6fa0; }
        .btn-gear-clr   { background: #95a5a6; color: #fff; border: none; border-radius: 4px; padding: 6px 14px; font-size: 13px; cursor: pointer; }
        .btn-gear-clr:hover { background: #7f8c8d; }
        .btn-gear-m2    { background: #e67e22; color: #fff; border: none; border-radius: 4px; padding: 5px 14px; font-size: 12px; cursor: pointer; }
        .btn-gear-m3    { background: #8e44ad; color: #fff; border: none; border-radius: 4px; padding: 5px 14px; font-size: 12px; cursor: pointer; }
        .btn-gear-m4    { background: #27ae60; color: #fff; border: none; border-radius: 4px; padding: 5px 14px; font-size: 12px; cursor: pointer; }
        .btn-gear-m2:hover { background: #ca6f1e; } .btn-gear-m3:hover { background: #76329a; } .btn-gear-m4:hover { background: #1e8449; }
        .btn-gear-add   { background: #27ae60; color: #fff; border: none; border-radius: 4px; padding: 4px 10px; font-size: 12px; cursor: pointer; }
        .btn-gear-edit  { background: #2980b9; color: #fff; border: none; border-radius: 3px; padding: 2px 8px; font-size: 11px; cursor: pointer; }
        .btn-gear-del   { background: #e74c3c; color: #fff; border: none; border-radius: 3px; padding: 2px 8px; font-size: 11px; cursor: pointer; }
        .btn-gear-save  { background: #27ae60; color: #fff; border: none; border-radius: 3px; padding: 3px 10px; font-size: 12px; cursor: pointer; }
        .btn-gear-cancel{ background: #95a5a6; color: #fff; border: none; border-radius: 3px; padding: 3px 10px; font-size: 12px; cursor: pointer; }

        /* 預留量管理表格 */
        .gear-allow-table { width: 100%; border-collapse: collapse; font-size: 12px; font-family: "Consolas","Courier New",monospace; }
        .gear-allow-table th { background: #2c3e50; color: #fff; padding: 5px 8px; text-align: center; font-size: 11px; }
        .gear-allow-table td { padding: 4px 7px; border-bottom: 1px solid #eee; text-align: center; }
        .gear-allow-table tr:nth-child(even) td { background: #f8fafb; }
        .gear-allow-table tr.editing td { background: #fffde7; }
        .gear-allow-tbl-wrap { max-height: 220px; overflow-y: auto; }
        .gear-inline-form { display: flex; gap: 5px; align-items: center; flex-wrap: wrap; padding: 6px 8px; background: #f0f7ff; border-radius: 4px; margin-top: 6px; }
        .gear-inline-form input { font-family: "Consolas","Courier New",monospace; font-size: 12px; border: 1px solid #aaa; border-radius: 3px; padding: 3px 5px; width: 80px; }
        .gear-inline-form input[type=checkbox] { width: auto; margin: 0 3px 0 0; cursor: pointer; }
        .gear-inline-form input[type=number]::-webkit-outer-spin-button,
        .gear-inline-form input[type=number]::-webkit-inner-spin-button { -webkit-appearance: none; appearance: none; margin: 0; }
        .gear-inline-form input[type=number] { appearance: textfield; -moz-appearance: textfield; }
        .gear-inline-form label { font-size: 11px; color: #555; }
        .gear-boss-badge { display: inline-block; background: #fff3e0; color: #e65100; border: 1px solid #ffa06a; border-radius: 3px; padding: 1px 5px; font-size: 10px; font-weight: 700; }
        .gear-preset-btn {
            background: #ecf0f1; border: 1px solid #bdc3c7; color: #333;
            border-radius: 3px; padding: 2px 7px; font-size: 11px; cursor: pointer;
            font-family: "Consolas","Courier New",monospace; transition: background .15s, border-color .15s;
        }
        .gear-preset-btn:hover { background: #d0e4f0; border-color: #2980b9; color: #1a6fa0; }
        .gear-preset-btn.active { background: #2980b9; border-color: #1a6fa0; color: #fff; }

        /* 設定面板 */
        #gear-settings-panel {
            display: none; position: absolute; top: 42px; right: 8px; z-index: 10;
            background: #fff; color: #333; border: 1px solid #b0bec5; border-radius: 6px;
            box-shadow: 0 4px 16px rgba(0,0,0,.2); padding: 12px 16px; min-width: 260px;
        }
        .gear-dept-list { max-height: 200px; overflow-y: auto; margin: 8px 0; }
        .gear-dept-item { display: flex; align-items: center; gap: 6px; padding: 3px 0; font-size: 12px; }
        .gear-dept-item.level-2 { padding-left: 10px; }
        .gear-dept-item.level-3 { padding-left: 20px; }
        .gear-dept-item.level-4 { padding-left: 30px; }

        /* 響應式 */
        @media (max-width: 768px) {
            #gear-tool-window { width: 98vw; top: 10px; }
            .gear-two-col { flex-direction: column; }
            .gear-out-grid { grid-template-columns: 1fr; }
        }

        /* ══ 花鍵工具 CSS ════════════════════════════════════════════════════ */
        .sp-tab-sep { width:1px; background:#bdc3c7; align-self:stretch; margin:4px 4px; display:inline-block; flex-shrink:0; }
        .gear-tab-btn.sp-tab { color:#6c3483; }
        .gear-tab-btn.sp-tab.active { color:#5b2c6f; border-bottom-color:#6c3483; background:#fdf5ff; }
        .gear-tab-btn.sp-tab:not(:disabled):hover { color:#9b59b6; }
        .tab-beta-badge { display:inline-block; font-size:9px; font-weight:700; color:#fff; background:#e67e22; border-radius:3px; padding:0 3px; margin-left:4px; vertical-align:middle; line-height:14px; letter-spacing:0; }
        .sp-warn-box { background:#fffbea; border:1px solid #f5c518; border-radius:4px; padding:6px 10px; font-size:11px; color:#7d6608; margin-top:6px; }
        .sp-err-box  { background:#fff0f0; border:1px solid #f5c5c5; border-radius:4px; padding:6px 10px; font-size:11px; color:#c0392b; margin-top:6px; }
        .sp-est-badge   { display:inline-block; background:#e8f5e9; color:#1b5e20; border:1px solid #a5d6a7; border-radius:3px; padding:1px 5px; font-size:10px; font-weight:700; }
        .sp-exact-badge { display:inline-block; background:#e3f2fd; color:#0d47a1; border:1px solid #90caf9; border-radius:3px; padding:1px 5px; font-size:10px; font-weight:700; }
        .sp-conv-area { background:#f8fafb; border:1px solid #d0e4f0; border-radius:4px; padding:8px 10px; margin-top:8px; }
        .sp-fit-result { padding:8px 12px; border-radius:6px; font-size:13px; font-weight:700; margin:8px 0; text-align:center; }
        .sp-fit-clearance   { background:#e8f5e9; color:#1e7e34; border:1px solid #a5d6a7; }
        .sp-fit-transition  { background:#fff8e1; color:#856404; border:1px solid #ffc107; }
        .sp-fit-interference{ background:#ffebee; color:#b71c1c; border:1px solid #ef9a9a; }
        .sp-tol-table { width:100%; border-collapse:collapse; font-size:11px; font-family:"Consolas","Courier New",monospace; }
        .sp-tol-table th { background:#4a235a; color:#fff; padding:4px 6px; text-align:center; font-size:10px; }
        .sp-tol-table td { padding:3px 6px; border-bottom:1px solid #eee; text-align:center; }
        .sp-tol-table tr:nth-child(even) td { background:#f9f5ff; }
        .sp-tol-tbl-wrap { max-height:180px; overflow-y:auto; }
        .btn-spline-calc { background:#7d3c98; color:#fff; border:none; border-radius:4px; padding:6px 18px; font-size:13px; font-weight:600; cursor:pointer; }
        .btn-spline-calc:hover { background:#6c3483; }

        /* ── 抽成共用檔後追加：本視窗可能被放進深色主題的頁面（例：批圖編輯器）──
           那些頁面的 body 是深底淺字，沒有寫死顏色的文字會被繼承成淺色＝白底視窗上看不見字。
           這裡只釘「會被繼承的」基本樣式，不動任何既有 .gear-* 規則（避免蓋掉原本的外觀）。 */
        #gear-tool-window {
            color: #333;
            font-family: "Microsoft JhengHei", "PingFang TC", "Segoe UI", sans-serif;
            font-weight: 400;
            line-height: 1.5;
            text-align: left;
        }
        #gear-tool-window *, #gear-tool-window *::before, #gear-tool-window *::after { box-sizing: border-box; }
        #gear-tool-window input, #gear-tool-window select, #gear-tool-window textarea, #gear-tool-window button { font-family: inherit; }
        #gear-tool-container { height: 0; flex: 0 0 auto; }

        /* ── 停靠模式：Figma 式「頁面內側邊面板」（貼在呼叫端指定容器的右緣，不蓋住整個畫面）──
           只在 .gear-docked 之下改版面，浮動視窗的既有外觀完全不受影響。 */
        #gear-tool-window.gear-docked {
            position: absolute; top: 0; right: 0; bottom: 0; left: auto;
            transform: none; width: 430px; max-width: 100%;
            border-radius: 0; border: none; border-left: 1px solid #9fb3bf;
            box-shadow: -6px 0 18px rgba(0,0,0,.30);
            flex-direction: column; z-index: 620; overflow: visible;
        }
        .gear-docked #gear-tool-hdr { border-radius: 0; padding: 7px 10px; cursor: default; flex: 0 0 auto; }
        .gear-docked #gear-tool-hdr .gear-hdr-title { font-size: 13px; }
        /* 分頁列在窄面板會排成兩列：一定要 flex:0 0 auto。
           它有 overflow-x（＝捲動容器）＝自動最小尺寸失效，不釘住的話會被 flex 壓成一列高，
           第二列的「花鍵內／栓槽／出尾／預留量管理」就被切掉看不見也點不到。 */
        .gear-docked .gear-tabs { flex: 0 0 auto; flex-wrap: wrap; overflow: visible; align-content: flex-start; }
        .gear-docked .gear-tab-btn { padding: 4px 7px; font-size: 10.5px; border-bottom-width: 2px; }
        .gear-docked .sp-tab-sep { display: none; }
        .gear-docked #gear-tool-body { max-height: none; flex: 1 1 auto; overflow-y: auto; }
        .gear-docked .gear-tab-pane { padding: 10px; }
        /* 窄面板：兩欄改上下排（輸入在上、結果在下），內層寫死的 min-width 一併鬆綁 */
        .gear-docked .gear-two-col { flex-direction: column; gap: 10px; }
        .gear-docked .gear-two-col > div { flex: none !important; width: 100%; min-width: 0 !important; }
        .gear-docked .gear-out-grid { grid-template-columns: 1fr 1fr; }
        .gear-docked .gear-allow-tbl-wrap, .gear-docked .sp-tol-tbl-wrap { overflow-x: auto; }
        .gear-docked .gear-inline-form input { width: 68px; }
        /* 左緣拖曳調整面板寬度（比照批圖編輯器標籤庫的做法） */
        #gear-dock-resizer { display: none; position: absolute; left: -3px; top: 0; bottom: 0; width: 7px; cursor: ew-resize; z-index: 5; }
        .gear-docked #gear-dock-resizer { display: block; }
        #gear-dock-resizer:hover, #gear-dock-resizer.active { background: rgba(41,128,185,.45); }
</style>
<!-- ═══ 齒輪計算工具視窗（點擊開啟後動態載入 DOM）════════════════════════════ -->
<template id="gear-tool-tpl"><div id="gear-tool-window">
    <div id="gear-dock-resizer" title="拖曳調整面板寬度"></div>
    <!-- Header / 拖曳把手 -->
    <div id="gear-tool-hdr">
        <span class="gear-hdr-title"><i class="fa fa-cog fa-spin" id="gear-hdr-icon"></i> 齒輪計算工具</span>
        <div class="gear-hdr-btns">
            <?php if ($is_gear_admin): ?>
            <button id="gear-settings-toggle" onclick="toggleGearSettings(event)" title="可使用本工具的部門（於組織角色綁定設定維護）"><i class="fa fa-sliders"></i> 設定</button>
            <?php endif; ?>
            <?php if ($gear_tool_dock !== ''): ?>
            <button id="gear-dock-toggle" onclick="toggleGearDock()" title="切換：停靠成頁面內的側邊面板／浮出成可拖曳的獨立視窗"><i class="fa fa-columns"></i> 停靠</button>
            <?php endif; ?>
            <button onclick="closeGearTool()" title="關閉">✕ 關閉</button>
        </div>
        <?php if ($is_gear_admin): ?>
        <!-- 設定面板 -->
        <div id="gear-settings-panel">
            <div style="font-weight:700;font-size:13px;color:#1a3a50;margin-bottom:6px;"><i class="fa fa-cog"></i> 可使用本工具的部門</div>
            <div class="gear-dept-list" id="gear-dept-list"><div style="color:#aaa;font-size:11px;padding:6px;">載入中…</div></div>
            <div style="margin-top:8px;display:flex;gap:6px;">
                <button onclick="document.getElementById('gear-settings-panel').style.display='none'" class="btn-gear-cancel">關閉</button>
            </div>
            <div id="gear-settings-msg" style="font-size:11px;margin-top:5px;"></div>
        </div>
        <?php endif; ?>
    </div><!-- /hdr -->

    <!-- 分頁 -->
    <div class="gear-tabs">
        <button class="gear-tab-btn active" data-tab="m1" onclick="switchGearTab('m1',this)" title="基本齒輪計算"><i class="fa fa-calculator"></i> 基本計算</button>
        <button class="gear-tab-btn" id="gtab-m2" data-tab="m2" onclick="switchGearTab('m2',this)" disabled title="跨齒厚 → 跨珠值（請先執行基本計算）">跨齒 → 跨珠</button>
        <button class="gear-tab-btn" id="gtab-m3" data-tab="m3" onclick="switchGearTab('m3',this)" disabled title="跨珠值 → 跨齒厚（請先執行基本計算）">跨珠 → 跨齒</button>
        <button class="gear-tab-btn" id="gtab-m5" data-tab="m5" onclick="switchGearTab('m5',this)" disabled title="跨珠換算（請先執行基本計算）">跨珠換算</button>
        <button class="gear-tab-btn" id="gtab-m4" data-tab="m4" onclick="switchGearTab('m4',this)" disabled title="客戶跨齒規格 → 建議滾齒值（請先執行基本計算）">建議滾齒</button>
        <button class="gear-tab-btn" id="gtab-rx" data-tab="rx" onclick="switchGearTab('rx',this)" disabled title="由外徑或跨齒厚反推轉位係數 x（請先執行基本計算）">回推 x</button>
        <span class="sp-tab-sep"></span>
        <button class="gear-tab-btn sp-tab" id="gtab-sp-ext" data-tab="sp-ext" onclick="switchGearTab('sp-ext',this)">花鍵 外</button>
        <button class="gear-tab-btn sp-tab" id="gtab-sp-int" data-tab="sp-int" onclick="switchGearTab('sp-int',this)">花鍵 內</button>
        <button class="gear-tab-btn sp-tab" id="gtab-sp-fit" data-tab="sp-fit" onclick="switchGearTab('sp-fit',this)" disabled title="需先分別計算外/內花鍵">花鍵配合</button>
        <span class="sp-tab-sep"></span>
        <button class="gear-tab-btn" data-tab="sr" onclick="switchGearTab('sr',this)" title="矩形栓槽跨銷值計算">栓槽</button>
        <button class="gear-tab-btn" data-tab="tail" onclick="switchGearTab('tail',this)" title="出尾長度計算與刀具外徑建議">出尾計算</button>
        <span class="sp-tab-sep"></span>
        <button class="gear-tab-btn" data-tab="tables" onclick="switchGearTab('tables',this)"><i class="fa fa-table"></i> 預留量管理</button>
    </div>

    <div id="gear-tool-body">

        <!-- ══ 模組一：基本齒輪計算 ══════════════════════════════════════════ -->
        <div class="gear-tab-pane active" id="gear-pane-m1">
            <div class="gear-two-col">
                <!-- 左：輸入 -->
                <div class="gear-col-left">
                    <div class="gear-section-title">輸入參數</div>
                    <div style="margin-bottom:8px;display:flex;align-items:center;gap:6px;">
                        <span style="font-size:12px;color:#555;font-weight:600;">齒型：</span>
                        <button type="button" class="gear-preset-btn active" id="g-mode-ext" onclick="setGearMode('ext')">外齒</button>
                        <button type="button" class="gear-preset-btn" id="g-mode-int" onclick="setGearMode('int')">內齒</button>
                        <small id="g-mode-hint" style="color:#999;font-weight:400;">外齒：跨齒厚＋建議滾齒；內齒：跨銷徑/跨銷值</small>
                    </div>
                    <div style="display:flex;gap:10px;">
                        <div class="gear-field-group" style="flex:1;">
                            <label>法向模數 mn <span style="color:#e74c3c">*</span> <small style="color:#999;font-weight:400;">可選 M/CP/DP</small></label>
                            <div style="display:flex;gap:4px;align-items:center;">
                                <select id="g-mn-unit" class="gear-input" style="width:58px;flex-shrink:0;padding:4px 2px;" onchange="updateGearMnDisplay()" title="模數輸入單位：M=模數、CP=周節、DP=徑節">
                                    <option value="M" selected>M</option>
                                    <option value="CP">CP</option>
                                    <option value="DP">DP</option>
                                </select>
                                <input type="number" id="g-mn" class="gear-input" placeholder="例：2" step="any" style="flex:1;min-width:0;" oninput="updateGearMnDisplay()">
                            </div>
                            <div id="g-mn-m-display" style="display:none;font-size:11px;color:#2980b9;font-weight:600;margin-top:2px;"></div>
                        </div>
                        <div class="gear-field-group" style="flex:1;">
                            <label>齒數 z <span style="color:#e74c3c">*</span></label>
                            <input type="number" id="g-z" class="gear-input" placeholder="例：30" step="1" min="2">
                        </div>
                    </div>
                    <div style="display:flex;gap:10px;">
                        <div class="gear-field-group" style="flex:1.25;min-width:0;">
                            <label>法向壓力角 α_n <span style="color:#e74c3c">*</span></label>
                            <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                                <div style="display:flex;align-items:center;gap:3px;">
                                    <input type="number" id="g-an" class="gear-input" placeholder="空白=20°" style="width:90px;" step="any" min="0" max="89" value="20">
                                    <span style="color:#555;font-size:14px;">°</span>
                                </div>
                                <button type="button" class="gear-preset-btn active" id="g-an-btn-20" onclick="setAlphaN(20)">20°（預設）</button>
                                <button type="button" class="gear-preset-btn" id="g-an-btn-30" onclick="setAlphaN(30)">30°</button>
                            </div>
                        </div>
                        <div class="gear-field-group" style="flex:1;min-width:0;">
                            <label>轉位係數 x <span style="color:#e74c3c">*</span></label>
                            <div style="display:flex;gap:5px;align-items:center;">
                                <input type="number" id="g-x" class="gear-input" placeholder="空白=0" step="any" style="flex:1;min-width:0;">
                                <button class="btn-gear-clr" style="padding:4px 9px;font-size:11px;white-space:nowrap;flex-shrink:0;" onclick="gotoRxTab()" title="由外徑或跨齒厚反推轉位係數 x"><i class="fa fa-undo"></i> 回推</button>
                            </div>
                        </div>
                    </div>
                    <div class="gear-field-group">
                        <label>螺旋角 β <small style="color:#999;font-weight:400;">（空白=直齒輪）</small></label>
                        <div style="display:flex;gap:14px;margin-bottom:4px;font-size:11px;">
                            <label style="display:flex;align-items:center;gap:4px;cursor:pointer;">
                                <input type="radio" name="g-bt-mode" id="g-bt-mode-deg" value="deg" checked onchange="toggleBetaMode('deg')"> 整數/小數度
                            </label>
                            <label style="display:flex;align-items:center;gap:4px;cursor:pointer;">
                                <input type="radio" name="g-bt-mode" id="g-bt-mode-dms" value="dms" onchange="toggleBetaMode('dms')"> 度°分′秒″
                            </label>
                        </div>
                        <div id="g-bt-deg-wrap" style="display:flex;align-items:center;gap:3px;">
                            <input type="number" id="g-bt" class="gear-input" placeholder="例：20（空白=0°）" style="width:170px;" step="any" min="0" max="89">
                            <span style="color:#555;font-size:14px;">°</span>
                        </div>
                        <div id="g-bt-dms-wrap" style="display:none;">
                            <div class="dms-wrap">
                                <input type="number" id="g-bt-d" placeholder="度" min="0" max="89" oninput="updateDmsDecimal('bt')">
                                <span class="dms-sym">°</span>
                                <input type="number" id="g-bt-m" placeholder="分" min="0" max="59" oninput="updateDmsDecimal('bt')">
                                <span class="dms-sym">′</span>
                                <input type="number" id="g-bt-s" placeholder="秒" min="0" max="59" oninput="updateDmsDecimal('bt')">
                                <span class="dms-sym">″</span>
                                <span class="dms-dec" id="g-bt-dec">0°</span>
                            </div>
                        </div>
                    </div>
                    <div style="display:flex;gap:10px;">
                        <div class="gear-field-group" style="flex:1;">
                            <label>齒研跨齒厚上公差 <small style="color:#999;font-weight:400;">空白=-0.02</small></label>
                            <input type="number" id="g-tol-up" class="gear-input" placeholder="-0.02" step="any">
                        </div>
                        <div class="gear-field-group" style="flex:1;">
                            <label>跨幾齒 k <small style="color:#999;font-weight:400;">空白=自動</small></label>
                            <input type="number" id="g-k-in" class="gear-input" placeholder="自動計算" step="1" min="1">
                        </div>
                    </div>
                    <div style="display:flex;gap:10px;">
                        <div class="gear-field-group" style="flex:1;">
                            <label>球徑 dp <small style="color:#999;font-weight:400;">空白=1.68×mn</small></label>
                            <input type="number" id="g-dp" class="gear-input" placeholder="自動建議" step="any">
                        </div>
                        <div class="gear-field-group" style="flex:1;">
                            <label>建議滾齒跨珠值 M 公差 上/下 <small style="color:#999;font-weight:400;">空白=±0.02</small></label>
                            <div style="display:flex;gap:4px;">
                                <input type="number" id="g-mtol-up" class="gear-input" placeholder="+0.02" step="any">
                                <input type="number" id="g-mtol-dn" class="gear-input" placeholder="-0.02" step="any">
                            </div>
                        </div>
                    </div>
                    <!-- 客戶提供數據（選填）：跨齒厚公差顯示上下限；跨銷徑/球徑＋M 上下限回推轉位 x -->
                    <div style="margin-top:8px;padding:8px 10px;background:#f3e5f5;border:1px solid #ce93d8;border-radius:5px;">
                        <div style="font-size:11px;font-weight:700;color:#6a1b9a;margin-bottom:6px;"><i class="fa fa-user"></i> 客戶提供數據（選填）</div>
                        <div style="display:flex;gap:8px;align-items:flex-end;">
                            <div class="gear-field-group" style="flex:1;margin-bottom:0;">
                                <label>客戶跨齒厚 Wk <small style="color:#999;font-weight:400;">空白=用理論值</small></label>
                                <input type="number" id="g-cust-wk" class="gear-input" placeholder="客戶圖面標準值" step="any">
                            </div>
                            <div class="gear-field-group" style="flex:1.3;margin-bottom:0;">
                                <label>客戶跨齒厚公差 上/下 <small style="color:#999;font-weight:400;">顯示跨齒厚上下限</small></label>
                                <div style="display:flex;gap:4px;">
                                    <input type="number" id="g-cust-wtol-up" class="gear-input" placeholder="上公差 例：0" step="any">
                                    <input type="number" id="g-cust-wtol-dn" class="gear-input" placeholder="下公差 例：-0.05" step="any">
                                </div>
                            </div>
                        </div>
                        <div style="display:flex;gap:8px;align-items:flex-end;">
                            <div class="gear-field-group" style="flex:1;margin-bottom:0;">
                                <label>客戶跨銷徑/球徑 dp <small style="color:#999;font-weight:400;">空白=1.68×mn</small></label>
                                <input type="number" id="g-cust-dp" class="gear-input" placeholder="自動" step="any">
                            </div>
                            <div class="gear-field-group" style="flex:1;margin-bottom:0;">
                                <label>客戶 M 下限</label>
                                <input type="number" id="g-cust-m-dn" class="gear-input" placeholder="例：58.301" step="any">
                            </div>
                            <div class="gear-field-group" style="flex:1;margin-bottom:0;">
                                <label>客戶 M 上限</label>
                                <input type="number" id="g-cust-m-up" class="gear-input" placeholder="例：58.341" step="any">
                            </div>
                            <button class="btn-gear-m3" style="flex-shrink:0;white-space:nowrap;" onclick="calcCustM2X()" title="由客戶跨銷徑/球徑與 M 上下限反推轉位係數 x，自動代入上方轉位係數欄並執行計算"><i class="fa fa-undo"></i> 回推轉位</button>
                        </div>
                        <div id="g-cust-x-out" style="display:none;margin-top:6px;font-size:11px;color:#4a148c;font-family:'Consolas','Courier New',monospace;">
                            回推 x：上限 <span id="g-cust-x-up" style="font-weight:700;">—</span>　下限 <span id="g-cust-x-dn" style="font-weight:700;">—</span>　中值 <span id="g-cust-x-mid" style="font-weight:700;color:#1e7e34;">—</span>（已代入轉位係數 x）
                        </div>
                        <div id="g-cust-warn" style="display:none;margin-top:6px;padding:5px 9px;background:#fffbea;border:1px solid #f39c12;border-radius:4px;font-size:11px;color:#856404;"></div>
                    </div>
                    <div style="margin-top:10px;display:flex;gap:8px;">
                        <button class="btn-gear-calc" onclick="calcGearM1()"><i class="fa fa-calculator"></i> 計算齒輪</button>
                        <button class="btn-gear-clr"  onclick="clearGearAll()">清除</button>
                    </div>
                    <div id="g-m1-warn" style="display:none;margin-top:8px;padding:5px 9px;background:#fffbea;border:1px solid #f39c12;border-radius:4px;font-size:11px;color:#856404;"></div>
                </div><!-- /left -->

                <!-- 右：輸出 -->
                <div class="gear-col-right">
                    <div class="gear-section-title">計算結果</div>
                    <div class="gear-out-grid">
                        <div class="gear-out-row"><span class="gear-out-label">端面模數 mt</span><span class="gear-output-val" id="go-mt">—</span></div>
                        <div class="gear-out-row"><span class="gear-out-label">節圓直徑 d</span><span class="gear-output-val" id="go-d">—</span></div>
                        <div class="gear-out-row"><span class="gear-out-label" id="go-da-lbl">外徑 da</span><span class="gear-output-val" id="go-da">—</span></div>
                        <div class="gear-out-row"><span class="gear-out-label" id="go-df-lbl">齒根圓 df</span><span class="gear-output-val" id="go-df">—</span></div>
                        <div class="gear-out-row"><span class="gear-out-label">齒高 h</span><span class="gear-output-val" id="go-h">—</span></div>
                    </div>
                    <div id="go-block-wk" style="margin-top:10px;padding:8px 10px;background:#f0f7ff;border-radius:5px;border:1px solid #c8dff0;">
                        <div class="gear-out-grid">
                            <div class="gear-out-row"><span class="gear-out-label">跨幾齒 k（自動/輸入）</span><span class="gear-output-val" id="go-k">—</span></div>
                            <div class="gear-out-row"><span class="gear-out-label">理論跨齒厚 Wk</span><span class="gear-output-val" id="go-wk">—</span></div>
                            <div class="gear-out-row" id="go-row-wk-bmin"><span class="gear-out-label">最小可量測齒寬 b<small style="color:#999;font-weight:400;">（跨此齒數所需工件厚度）</small></span><span class="gear-output-val" id="go-wk-bmin">—</span></div>
                            <div class="gear-out-row" id="go-row-cust-wk" style="display:none;"><span class="gear-out-label">客戶跨齒厚下限 / 上限</span><span class="gear-output-val" id="go-cust-wk-range">—</span></div>
                            <div class="gear-out-row"><span class="gear-out-label" id="go-rechob-wk-lbl">建議滾齒跨齒厚（依標準）</span><span class="gear-output-val val-ok" id="go-rechob-wk" style="font-weight:700;">—</span></div>
                            <div class="gear-out-row" id="go-row-cust-rh-wk" style="display:none;"><span class="gear-out-label" style="color:#6a1b9a;">客戶規格 建議滾齒跨齒厚 Wk</span><span class="gear-output-val" id="go-cust-rh-wk" style="font-weight:700;color:#6a1b9a;">—</span></div>
                            <div class="gear-out-row"><span class="gear-out-label">預留量（模數/外徑取大）</span><span class="gear-output-val" id="go-allow-info">—</span></div>
                        </div>
                    </div>
                    <div style="margin-top:8px;padding:8px 10px;background:#f0fff4;border-radius:5px;border:1px solid #a5d6b5;">
                        <div class="gear-section-title" style="margin-bottom:6px;color:#1e7e34;" id="go-m-title">跨珠值 M</div>
                        <div class="gear-out-grid">
                            <div class="gear-out-row"><span class="gear-out-label" id="go-dp-lbl">使用球徑 dp</span><span class="gear-output-val" id="go-dp-used">—</span></div>
                            <div class="gear-out-row"><span class="gear-out-label" id="go-m-lbl">標準跨珠值 M</span><span class="gear-output-val val-ok" id="go-m">—</span></div>
                            <div class="gear-out-row" id="go-row-rechob-m"><span class="gear-out-label">建議滾齒 M（公稱）</span><span class="gear-output-val val-ok" id="go-rechob-m" style="font-weight:700;">—</span></div>
                            <div class="gear-out-row"><span class="gear-out-label" id="go-rechob-m-range-lbl">建議滾齒 M 下/上限（依標準跨珠值 M 換算）</span>
                                <span class="gear-output-val" id="go-rechob-m-range">—</span>
                            </div>
                            <div class="gear-out-row" id="go-row-cust-m" style="display:none;grid-column:1/-1;border-top:1px dashed #a5d6b5;padding-top:5px;margin-top:2px;">
                                <span class="gear-out-label" style="color:#6a1b9a;">客戶規格→我方球徑 M 下/上限</span>
                                <span class="gear-output-val val-ok" id="go-cust-m-range" style="font-weight:700;color:#6a1b9a;">—</span>
                            </div>
                            <div class="gear-out-row" id="go-row-cust-rh-m" style="display:none;grid-column:1/-1;"><span class="gear-out-label" style="color:#6a1b9a;">客戶規格 建議滾齒 M（公稱）</span><span class="gear-output-val val-ok" id="go-cust-rh-m" style="font-weight:700;color:#6a1b9a;">—</span></div>
                            <div class="gear-out-row" id="go-row-cust-rh-range" style="display:none;grid-column:1/-1;"><span class="gear-out-label" style="color:#6a1b9a;">客戶規格 建議滾齒 M 下/上限</span><span class="gear-output-val val-ok" id="go-cust-rh-m-range" style="font-weight:700;color:#6a1b9a;">—</span></div>
                        </div>
                        <div id="go-cust-m-note" style="display:none;margin-top:4px;font-size:10px;color:#8e6aa0;line-height:1.5;"></div>
                    </div>
                </div><!-- /right -->
            </div><!-- /two-col -->
        </div><!-- /pane-m1 -->

        <!-- ══ 模組二：跨齒厚 → 跨珠值 ═══════════════════════════════════════ -->
        <div class="gear-tab-pane" id="gear-pane-m2">
            <div class="gear-two-col">
                <div class="gear-col-left">
                    <div class="gear-section-title">輸入（圖面跨齒厚規格）</div>
                    <div style="background:#fff8e1;border:1px solid #ffe082;border-radius:4px;padding:6px 9px;font-size:11px;color:#795548;margin-bottom:10px;">
                        以<strong>基本計算</strong>的基礎參數（mn, z, α_n, β, inv α_t）計算
                    </div>
                    <div style="display:flex;gap:10px;">
                        <div class="gear-field-group" style="flex:1;">
                            <label>跨幾齒 k <span style="color:#e74c3c">*</span></label>
                            <input type="number" id="m2-k" class="gear-input" placeholder="例：4" step="1" min="1">
                        </div>
                        <div class="gear-field-group" style="flex:1;">
                            <label>跨齒厚公稱值 Wk <span style="color:#e74c3c">*</span></label>
                            <input type="number" id="m2-wk" class="gear-input" placeholder="例：28.5" step="any">
                        </div>
                    </div>
                    <div style="display:flex;gap:10px;">
                        <div class="gear-field-group" style="flex:1;">
                            <label>跨齒厚上公差</label>
                            <input type="number" id="m2-tol-up" class="gear-input" placeholder="例：0" step="any">
                        </div>
                        <div class="gear-field-group" style="flex:1;">
                            <label>跨齒厚下公差</label>
                            <input type="number" id="m2-tol-dn" class="gear-input" placeholder="例：-0.005" step="any">
                        </div>
                    </div>
                    <div class="gear-field-group">
                        <label>球徑 dp <small style="color:#999;font-weight:400;">空白=沿用基本計算的球徑 <span id="m2-dp-hint" style="color:#2980b9;"></span></small></label>
                        <input type="number" id="m2-dp" class="gear-input" placeholder="自動" step="any">
                    </div>
                    <button class="btn-gear-m2" style="margin-top:8px;" onclick="calcGearM2()"><i class="fa fa-exchange"></i> 計算跨珠值</button>
                    <div id="g-m2-warn" style="display:none;margin-top:8px;padding:5px 9px;background:#fffbea;border:1px solid #f39c12;border-radius:4px;font-size:11px;color:#856404;"></div>
                </div>
                <div class="gear-col-right">
                    <div class="gear-section-title">計算結果</div>
                    <div class="gear-out-grid">
                        <div class="gear-out-row"><span class="gear-out-label">跨珠值上限 M_upper</span><span class="gear-output-val val-ok" id="m2-m-up">—</span></div>
                        <div class="gear-out-row"><span class="gear-out-label">跨珠值下限 M_lower</span><span class="gear-output-val val-ok" id="m2-m-dn">—</span></div>
                        <div class="gear-out-row"><span class="gear-out-label">驗算：跨齒厚上限</span><span class="gear-output-val" id="m2-wk-up-chk">—</span></div>
                        <div class="gear-out-row"><span class="gear-out-label">驗算：跨齒厚下限</span><span class="gear-output-val" id="m2-wk-dn-chk">—</span></div>
                    </div>
                </div>
            </div>
        </div><!-- /pane-m2 -->

        <!-- ══ 模組三：跨珠值 → 跨齒厚 ═══════════════════════════════════════ -->
        <div class="gear-tab-pane" id="gear-pane-m3">
            <div class="gear-two-col">
                <div class="gear-col-left">
                    <div class="gear-section-title">輸入（圖面跨珠值規格）</div>
                    <div style="background:#fff8e1;border:1px solid #ffe082;border-radius:4px;padding:6px 9px;font-size:11px;color:#795548;margin-bottom:10px;">
                        以<strong>基本計算</strong>的基礎參數計算；球徑可另行指定
                    </div>
                    <div style="display:flex;gap:10px;">
                        <div class="gear-field-group" style="flex:1;">
                            <label>球徑 dp <span style="color:#e74c3c">*</span></label>
                            <input type="number" id="m3-dp" class="gear-input" placeholder="例：3.36" step="any">
                        </div>
                        <div class="gear-field-group" style="flex:1;">
                            <label>跨珠值公稱 M <span style="color:#e74c3c">*</span></label>
                            <input type="number" id="m3-m" class="gear-input" placeholder="例：58.123" step="any">
                        </div>
                    </div>
                    <div style="display:flex;gap:10px;">
                        <div class="gear-field-group" style="flex:1;">
                            <label>M 上公差</label>
                            <input type="number" id="m3-tol-up" class="gear-input" placeholder="+0.02" step="any">
                        </div>
                        <div class="gear-field-group" style="flex:1;">
                            <label>M 下公差</label>
                            <input type="number" id="m3-tol-dn" class="gear-input" placeholder="-0.02" step="any">
                        </div>
                    </div>
                    <div class="gear-field-group">
                        <label>跨幾齒 k <small style="color:#999;font-weight:400;">空白=依模組一自動推算</small></label>
                        <input type="number" id="m3-k-in" class="gear-input" placeholder="自動" step="1" min="1">
                    </div>
                    <button class="btn-gear-m3" style="margin-top:8px;" onclick="calcGearM3()"><i class="fa fa-undo"></i> 回推跨齒厚</button>
                    <div id="g-m3-warn" style="display:none;margin-top:8px;padding:5px 9px;background:#fffbea;border:1px solid #f39c12;border-radius:4px;font-size:11px;color:#856404;"></div>
                </div>
                <div class="gear-col-right">
                    <div class="gear-section-title">計算結果</div>
                    <div class="gear-out-grid">
                        <div class="gear-out-row"><span class="gear-out-label">跨幾齒 k</span><span class="gear-output-val" id="m3-k-out">—</span></div>
                        <div class="gear-out-row"><span class="gear-out-label">對應轉位係數 x（公稱）</span><span class="gear-output-val" id="m3-x">—</span></div>
                        <div class="gear-out-row"><span class="gear-out-label">跨齒厚公稱值 Wk</span><span class="gear-output-val val-ok" id="m3-wk" style="font-weight:700;">—</span></div>
                        <div class="gear-out-row"><span class="gear-out-label">Wk 上公差 / 下公差</span><span class="gear-output-val" id="m3-wk-tol">—</span></div>
                        <div class="gear-out-row"><span class="gear-out-label">Wk 上限（絕對值）</span><span class="gear-output-val" id="m3-wk-up">—</span></div>
                        <div class="gear-out-row"><span class="gear-out-label">Wk 下限（絕對值）</span><span class="gear-output-val" id="m3-wk-dn">—</span></div>
                    </div>
                </div>
            </div>
        </div><!-- /pane-m3 -->

        <!-- ══ 模組四：客戶跨齒 → 建議滾齒 ═══════════════════════════════════ -->
        <div class="gear-tab-pane" id="gear-pane-m4">
            <div class="gear-two-col">
                <div class="gear-col-left">
                    <div class="gear-section-title">輸入（客戶圖面跨齒規格）</div>
                    <div style="background:#fff8e1;border:1px solid #ffe082;border-radius:4px;padding:6px 9px;font-size:11px;color:#795548;margin-bottom:10px;">
                        以客戶 Wk 公稱 + 公差調整 + 預留量計算（邏輯同基本計算）
                    </div>
                    <div style="display:flex;gap:10px;">
                        <div class="gear-field-group" style="flex:1;">
                            <label>客戶跨幾齒 k <span style="color:#e74c3c">*</span></label>
                            <input type="number" id="m4-k" class="gear-input" placeholder="例：4" step="1" min="1">
                        </div>
                        <div class="gear-field-group" style="flex:1;">
                            <label>客戶 Wk 公稱 <span style="color:#e74c3c">*</span></label>
                            <input type="number" id="m4-wk" class="gear-input" placeholder="例：28.500" step="any">
                        </div>
                    </div>
                    <div style="display:flex;gap:10px;">
                        <div class="gear-field-group" style="flex:1;">
                            <label>客戶 Wk 上公差</label>
                            <input type="number" id="m4-tol-up" class="gear-input" placeholder="例：0" step="any">
                        </div>
                        <div class="gear-field-group" style="flex:1;">
                            <label>客戶 Wk 下公差</label>
                            <input type="number" id="m4-tol-dn" class="gear-input" placeholder="例：-0.005" step="any">
                        </div>
                    </div>
                    <div style="display:flex;gap:10px;">
                        <div class="gear-field-group" style="flex:1;">
                            <label>球徑 dp <small style="color:#999;font-weight:400;">空白=1.68×mn</small></label>
                            <input type="number" id="m4-dp" class="gear-input" placeholder="自動建議" step="any">
                        </div>
                        <div class="gear-field-group" style="flex:1;">
                            <label>建議 M 公差 上/下 <small style="color:#999;font-weight:400;">空白=±0.02</small></label>
                            <div style="display:flex;gap:4px;">
                                <input type="number" id="m4-mtol-up" class="gear-input" placeholder="+0.02" step="any">
                                <input type="number" id="m4-mtol-dn" class="gear-input" placeholder="-0.02" step="any">
                            </div>
                        </div>
                    </div>
                    <button class="btn-gear-m4" style="margin-top:8px;" onclick="calcGearM4()"><i class="fa fa-gears"></i> 計算建議滾齒</button>
                    <div id="g-m4-warn" style="display:none;margin-top:8px;padding:5px 9px;background:#fffbea;border:1px solid #f39c12;border-radius:4px;font-size:11px;color:#856404;"></div>
                </div>
                <div class="gear-col-right">
                    <div class="gear-section-title">計算結果</div>
                    <div class="gear-out-grid">
                        <div class="gear-out-row"><span class="gear-out-label">使用球徑 dp</span><span class="gear-output-val" id="m4-dp-used">—</span></div>
                        <div class="gear-out-row"><span class="gear-out-label">使用預留量（來源）</span><span class="gear-output-val" id="m4-allow-info">—</span></div>
                        <div class="gear-out-row" style="grid-column:1/-1;"><span class="gear-out-label">建議滾齒跨齒厚</span><span class="gear-output-val val-ok" id="m4-rechob-wk" style="font-weight:700;">—</span></div>
                        <div class="gear-out-row"><span class="gear-out-label">建議滾齒 M（公稱）</span><span class="gear-output-val val-ok" id="m4-rechob-m" style="font-weight:700;">—</span></div>
                        <div class="gear-out-row"><span class="gear-out-label">M 下/上限</span><span class="gear-output-val" id="m4-rechob-m-range">—</span></div>
                    </div>
                    <div style="margin-top:8px;padding:8px 10px;background:#f5f5f5;border-radius:4px;border:1px solid #ddd;">
                        <div class="gear-section-title" style="margin-bottom:6px;color:#555;">客戶規格對應 M 值（參考）<span id="m4-cust-m-dp-label" style="font-weight:400;color:#999;font-size:10px;margin-left:4px;"></span></div>
                        <div class="gear-out-grid">
                            <div class="gear-out-row"><span class="gear-out-label">客戶 Wk 上限對應 M</span><span class="gear-output-val" id="m4-cust-m-up">—</span></div>
                            <div class="gear-out-row"><span class="gear-out-label">客戶 Wk 下限對應 M</span><span class="gear-output-val" id="m4-cust-m-dn">—</span></div>
                        </div>
                    </div>
                </div>
            </div>
        </div><!-- /pane-m4 -->

        <!-- ══ 模組五：跨珠換算（客戶球徑 → 我方球徑）════════════════════════ -->
        <div class="gear-tab-pane" id="gear-pane-m5">
            <div class="gear-two-col">
                <div class="gear-col-left">
                    <div class="gear-section-title">輸入（客戶跨珠規格 → 我方球徑換算）</div>
                    <div style="background:#fff8e1;border:1px solid #ffe082;border-radius:4px;padding:6px 9px;font-size:11px;color:#795548;margin-bottom:10px;">
                        以<strong>基本計算</strong>的基礎參數（mn, z, α_n, β）計算；輸入客戶球徑 M 值，轉換為我方球徑的 M 值
                    </div>
                    <div style="display:flex;gap:10px;">
                        <div class="gear-field-group" style="flex:1;">
                            <label>客戶球徑 dp_客 <span style="color:#e74c3c">*</span> <small style="color:#2980b9;font-weight:400;" id="m5-dp-hint"></small></label>
                            <input type="number" id="m5-dp-cust" class="gear-input" placeholder="自動帶入基本計算球徑" step="any">
                        </div>
                        <div class="gear-field-group" style="flex:1;">
                            <label>我方球徑 dp_我 <span style="color:#e74c3c">*</span></label>
                            <input type="number" id="m5-dp-mine" class="gear-input" placeholder="例：4" step="any">
                        </div>
                    </div>
                    <div style="display:flex;gap:10px;">
                        <div class="gear-field-group" style="flex:1;">
                            <label>客戶 M 下限 <span style="color:#e74c3c">*</span></label>
                            <input type="number" id="m5-m-dn" class="gear-input" placeholder="例：58.301" step="any">
                        </div>
                        <div class="gear-field-group" style="flex:1;">
                            <label>客戶 M 上限 <span style="color:#e74c3c">*</span></label>
                            <input type="number" id="m5-m-up" class="gear-input" placeholder="例：58.341" step="any">
                        </div>
                    </div>
                    <button class="btn-gear-m2" style="margin-top:8px;background:#16a085;" onclick="calcGearM5()"><i class="fa fa-refresh"></i> 換算</button>
                    <div id="g-m5-warn" style="display:none;margin-top:8px;padding:5px 9px;background:#fffbea;border:1px solid #f39c12;border-radius:4px;font-size:11px;color:#856404;"></div>
                    <!-- 建議滾齒尺寸：依客戶 M 上限(上公差=0)→跨齒厚→加預留量→我方球徑 M -->
                    <div style="padding:8px 10px;background:#fff8f0;border-radius:5px;border:1px solid #f0c891;margin-top:8px;">
                        <div class="gear-section-title" style="margin-bottom:6px;color:#b9770e;">建議滾齒尺寸（我方球徑）</div>
                        <div class="gear-out-grid">
                            <div class="gear-out-row"><span class="gear-out-label">使用預留量（來源）</span><span class="gear-output-val" id="m5-rh-allow">—</span></div>
                            <div class="gear-out-row"><span class="gear-out-label">建議滾齒跨齒厚 Wk</span><span class="gear-output-val" id="m5-rh-wk">—</span></div>
                            <div class="gear-out-row"><span class="gear-out-label">建議滾齒 M（公稱）</span><span class="gear-output-val val-ok" id="m5-rh-m" style="font-weight:700;">—</span></div>
                            <div class="gear-out-row"><span class="gear-out-label">建議滾齒 M 下/上限</span><span class="gear-output-val val-ok" id="m5-rh-m-range" style="font-weight:700;">—</span></div>
                        </div>
                        <div style="margin-top:5px;font-size:10px;color:#a0762e;line-height:1.5;">
                            依客戶 M 上限（上公差=0）→ 跨齒厚（跨 <span id="m5-rh-k">—</span> 齒）→ 加預留量（模數/外徑取大）→ 我方球徑 M；上下限套用基本計算之 M 公差
                        </div>
                    </div>
                </div>
                <div class="gear-col-right">
                    <div class="gear-section-title">換算結果</div>
                    <!-- 我方球徑對應 M 值：左欄跨 2 列顯示球徑，右欄上下各一 -->
                    <div style="padding:8px 10px;background:#f0fff4;border-radius:5px;border:1px solid #a5d6b5;margin-bottom:8px;">
                        <div class="gear-section-title" style="margin-bottom:6px;color:#1e7e34;">我方球徑對應 M 值</div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;grid-template-rows:auto auto;gap:6px 10px;">
                            <div style="grid-row:1/3;display:flex;flex-direction:column;align-items:center;justify-content:center;border-right:1px solid #a5d6b5;padding-right:8px;gap:4px;">
                                <span class="gear-out-label" style="text-align:center;">我方球徑 dp</span>
                                <span class="gear-output-val" id="m5-out-dp-mine" style="font-size:16px;font-weight:700;text-align:center;">—</span>
                                <div style="margin-top:5px;font-size:10px;color:#555;text-align:center;line-height:1.8;font-family:'Consolas','Courier New',monospace;">
                                    <div>反推 x（上）：<span id="m5-x-up" style="color:#1e7e34;font-weight:600;">—</span></div>
                                    <div>反推 x（下）：<span id="m5-x-dn" style="color:#1e7e34;font-weight:600;">—</span></div>
                                </div>
                            </div>
                            <div class="gear-out-row"><span class="gear-out-label">M 上限（換算後）</span><span class="gear-output-val val-ok" id="m5-m-mine-up" style="font-weight:700;">—</span></div>
                            <div class="gear-out-row"><span class="gear-out-label">M 下限（換算後）</span><span class="gear-output-val val-ok" id="m5-m-mine-dn" style="font-weight:700;">—</span></div>
                        </div>
                    </div>
                    <!-- 客戶規格驗算：相同格式 -->
                    <div style="padding:8px 10px;background:#f5f5f5;border-radius:4px;border:1px solid #ddd;">
                        <div class="gear-section-title" style="margin-bottom:6px;color:#555;">客戶規格驗算</div>
                        <div style="display:grid;grid-template-columns:1fr 1fr;grid-template-rows:auto auto;gap:6px 10px;">
                            <div style="grid-row:1/3;display:flex;flex-direction:column;align-items:center;justify-content:center;border-right:1px solid #ddd;padding-right:8px;gap:4px;">
                                <span class="gear-out-label" style="text-align:center;">客戶球徑 dp</span>
                                <span class="gear-output-val" id="m5-out-dp-cust" style="font-size:16px;font-weight:700;text-align:center;">—</span>
                            </div>
                            <div class="gear-out-row"><span class="gear-out-label">客戶 M 上限（驗算）</span><span class="gear-output-val" id="m5-m-cust-up">—</span></div>
                            <div class="gear-out-row"><span class="gear-out-label">客戶 M 下限（驗算）</span><span class="gear-output-val" id="m5-m-cust-dn">—</span></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── 跨齒轉換（客戶跨齒規格 → 我方跨齒數＋建議滾齒尺寸）───────── -->
            <div style="border-top:2px dashed #cfd8dc;margin-top:12px;padding-top:10px;">
                <div class="gear-section-title" style="color:#6a1b9a;">跨齒轉換（客戶跨齒厚規格 → 我方跨齒數）</div>
                <div style="background:#f3e5f5;border:1px solid #ce93d8;border-radius:4px;padding:6px 9px;font-size:11px;color:#6a1b9a;margin-bottom:10px;">
                    輸入客戶圖面的跨齒厚（標準值＋上下公差），依基本計算參數換算為我方跨齒數的跨齒厚，並自動計算建議滾齒尺寸
                </div>
                <div class="gear-two-col">
                    <div class="gear-col-left">
                        <div style="display:flex;gap:10px;">
                            <div class="gear-field-group" style="flex:1;">
                                <label>客戶跨幾齒 k_客 <small style="color:#999;font-weight:400;">空白=基本計算 k</small></label>
                                <input type="number" id="m5w-k-cust" class="gear-input" placeholder="自動帶入" step="1">
                            </div>
                            <div class="gear-field-group" style="flex:1;">
                                <label>我方跨幾齒 k_我 <small style="color:#999;font-weight:400;">空白=基本計算 k</small></label>
                                <input type="number" id="m5w-k-mine" class="gear-input" placeholder="自動帶入" step="1">
                            </div>
                        </div>
                        <div style="display:flex;gap:10px;">
                            <div class="gear-field-group" style="flex:1;">
                                <label>跨齒厚標準值 Wk <span style="color:#e74c3c">*</span></label>
                                <input type="number" id="m5w-wk" class="gear-input" placeholder="客戶圖面標準值" step="any">
                            </div>
                            <div class="gear-field-group" style="flex:1;">
                                <label>上限公差 <small style="color:#999;font-weight:400;">空白=0</small></label>
                                <input type="number" id="m5w-tol-up" class="gear-input" placeholder="例：0 或 -0.02" step="any">
                            </div>
                            <div class="gear-field-group" style="flex:1;">
                                <label>下限公差 <small style="color:#999;font-weight:400;">空白=0</small></label>
                                <input type="number" id="m5w-tol-dn" class="gear-input" placeholder="例：-0.06" step="any">
                            </div>
                        </div>
                        <button class="btn-gear-m2" style="margin-top:4px;background:#8e24aa;" onclick="calcGearM5Wk()"><i class="fa fa-exchange"></i> 跨齒轉換</button>
                        <div id="g-m5w-warn" style="display:none;margin-top:8px;padding:5px 9px;background:#fffbea;border:1px solid #f39c12;border-radius:4px;font-size:11px;color:#856404;"></div>
                    </div>
                    <div class="gear-col-right">
                        <div style="padding:8px 10px;background:#f0fff4;border-radius:5px;border:1px solid #a5d6b5;margin-bottom:8px;">
                            <div class="gear-section-title" style="margin-bottom:6px;color:#1e7e34;">轉換後跨齒厚（跨 <span id="m5w-out-k">—</span> 齒）</div>
                            <div class="gear-out-grid">
                                <div class="gear-out-row"><span class="gear-out-label">標準值</span><span class="gear-output-val val-ok" id="m5w-out-std" style="font-weight:700;">—</span></div>
                                <div class="gear-out-row"><span class="gear-out-label">下限 / 上限</span><span class="gear-output-val val-ok" id="m5w-out-range">—</span></div>
                            </div>
                        </div>
                        <div style="padding:8px 10px;background:#fff8f0;border-radius:5px;border:1px solid #f0c891;">
                            <div class="gear-section-title" style="margin-bottom:6px;color:#b9770e;">建議滾齒尺寸</div>
                            <div class="gear-out-grid">
                                <div class="gear-out-row"><span class="gear-out-label">使用預留量（來源）</span><span class="gear-output-val" id="m5w-rh-allow">—</span></div>
                                <div class="gear-out-row"><span class="gear-out-label">建議滾齒跨齒厚</span><span class="gear-output-val val-ok" id="m5w-rh-wk" style="font-weight:700;">—</span></div>
                                <div class="gear-out-row"><span class="gear-out-label">建議滾齒 M（公稱）</span><span class="gear-output-val val-ok" id="m5w-rh-m">—</span></div>
                                <div class="gear-out-row"><span class="gear-out-label">建議滾齒 M 下/上限</span><span class="gear-output-val" id="m5w-rh-m-range">—</span></div>
                            </div>
                            <div style="margin-top:5px;font-size:10px;color:#a0762e;line-height:1.5;">
                                與基本計算相同邏輯：轉換後標準值＋（上公差−(−0.02) 偏移）＋預留量（模數/外徑取大）→ 反推 x → 球徑 M；M 公差沿用基本計算設定
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div><!-- /pane-m5 -->

        <!-- ══ 回推轉位係數 x ══════════════════════════════════════════════ -->
        <div class="gear-tab-pane" id="gear-pane-rx">
            <div style="background:#fff8e1;border:1px solid #ffe082;border-radius:4px;padding:6px 9px;font-size:11px;color:#795548;margin-bottom:10px;">
                以<strong>基本計算</strong>的 mn, z, α_n, β 為基礎；由量測值或圖面值反推轉位係數 x
            </div>
            <div class="gear-two-col">
                <!-- 由外徑回推 -->
                <div class="gear-col-left">
                    <div class="gear-section-title">由外徑 da 回推</div>
                    <div class="gear-field-group">
                        <label>外徑 da <span style="color:#e74c3c">*</span></label>
                        <input type="number" id="rx-da" class="gear-input" step="any" placeholder="量測或圖面外徑">
                    </div>
                    <button class="btn-gear-m2" style="margin-top:4px;" onclick="calcGearDa2X()"><i class="fa fa-undo"></i> 回推 x</button>
                    <div id="g-rx-da-warn" style="display:none;margin-top:6px;padding:5px 9px;background:#fffbea;border:1px solid #f39c12;border-radius:4px;font-size:11px;color:#856404;"></div>
                    <div class="gear-out-grid" style="margin-top:10px;">
                        <div class="gear-out-row"><span class="gear-out-label">轉位係數 x</span><span class="gear-output-val val-ok" id="rx-da-x" style="font-weight:700;">—</span></div>
                        <div class="gear-out-row"><span class="gear-out-label">驗算 da</span><span class="gear-output-val" id="rx-da-verify">—</span></div>
                    </div>
                    <button class="btn-gear-m4" style="margin-top:8px;width:100%;" onclick="fillXfromDa()"><i class="fa fa-arrow-left"></i> 代入 M1 轉位係數</button>
                    <div style="margin-top:6px;font-size:11px;color:#999;">
                        da = mt·z + 2·mn·(1+x) → x = (da − mt·z) / (2·mn) − 1
                    </div>
                </div>
                <!-- 由跨齒厚回推 -->
                <div class="gear-col-right">
                    <div class="gear-section-title">由跨齒厚 Wk 回推</div>
                    <div style="display:flex;gap:8px;">
                        <div class="gear-field-group" style="flex:1;">
                            <label>跨幾齒 k <span style="color:#e74c3c">*</span></label>
                            <input type="number" id="rx-k" class="gear-input" step="1" min="1" placeholder="例：4">
                        </div>
                        <div class="gear-field-group" style="flex:2;">
                            <label>跨齒厚 Wk <span style="color:#e74c3c">*</span></label>
                            <input type="number" id="rx-wk" class="gear-input" step="any" placeholder="例：28.500">
                        </div>
                    </div>
                    <button class="btn-gear-m3" style="margin-top:4px;" onclick="calcGearWk2X()"><i class="fa fa-undo"></i> 回推 x</button>
                    <div id="g-rx-wk-warn" style="display:none;margin-top:6px;padding:5px 9px;background:#fffbea;border:1px solid #f39c12;border-radius:4px;font-size:11px;color:#856404;"></div>
                    <div class="gear-out-grid" style="margin-top:10px;">
                        <div class="gear-out-row"><span class="gear-out-label">轉位係數 x</span><span class="gear-output-val val-ok" id="rx-wk-x" style="font-weight:700;">—</span></div>
                        <div class="gear-out-row"><span class="gear-out-label">驗算 Wk</span><span class="gear-output-val" id="rx-wk-verify">—</span></div>
                    </div>
                    <button class="btn-gear-m4" style="margin-top:8px;width:100%;" onclick="fillXfromWk()"><i class="fa fa-arrow-left"></i> 代入 M1 轉位係數</button>
                    <div style="margin-top:6px;font-size:11px;color:#999;">
                        Wk = mn·cos(αn)·[π(k−½)+z·inv(αt)] + 2x·mn·sin(αn) → x = (Wk − A) / B
                    </div>
                </div>
            </div>
        </div><!-- /pane-rx -->

        <!-- ══ 花鍵 外 ══════════════════════════════════════════════════════ -->
        <div class="gear-tab-pane" id="gear-pane-sp-ext">
            <div style="background:#fff3cd;border:1px solid #ffc107;border-radius:4px;padding:4px 9px;font-size:11px;color:#856404;margin-bottom:8px;">⚠ 尚未完整測試，計算結果請自行驗證</div>
            <div class="gear-two-col">
                <!-- 左：輸入 -->
                <div class="gear-col-left">
                    <div class="gear-section-title">外花鍵 輸入參數</div>
                    <div class="gear-field-group">
                        <label>標準</label>
                        <select id="sp-ext-std" class="gear-input" onchange="splineExtStdChange()">
                            <option value="ISO4156">ISO 4156</option>
                            <option value="DIN5480">DIN 5480</option>
                            <option value="ANSIB922">ANSI B92.2</option>
                        </select>
                    </div>
                    <div id="sp-ext-mn-wrap" class="gear-field-group">
                        <label>法向模數 mn <span style="color:#e74c3c">*</span></label>
                        <input type="number" id="sp-ext-mn" class="gear-input" step="any" placeholder="例：2">
                    </div>
                    <div id="sp-ext-pd-wrap" class="gear-field-group" style="display:none;">
                        <label>Pd（牙/英吋）<span style="color:#e74c3c">*</span></label>
                        <input type="number" id="sp-ext-pd" class="gear-input" step="any" placeholder="例：12">
                        <div id="sp-ext-ansi-info" style="display:none;font-size:11px;color:#888;margin-top:2px;"><span id="sp-ext-ansi-pd"></span></div>
                    </div>
                    <div class="gear-field-group">
                        <label>齒數 z <span style="color:#e74c3c">*</span></label>
                        <input type="number" id="sp-ext-z" class="gear-input" step="1" min="2" placeholder="例：20">
                    </div>
                    <div id="sp-ext-dnom-wrap" class="gear-field-group" style="display:none;">
                        <label>公稱直徑 d<sub>N</sub> <small style="color:#888;">可選；W<b>20</b>×0.8×… 中的標稱值，填入可得精確外徑</small></label>
                        <input type="number" id="sp-ext-dnom" class="gear-input" step="any" placeholder="留空用短齒公式自動算">
                    </div>
                    <div class="gear-field-group">
                        <label>壓力角 α° <small style="color:#999;">預設30°</small></label>
                        <div style="display:flex;gap:4px;align-items:center;">
                            <input type="number" id="sp-ext-an" class="gear-input" step="any" placeholder="30" style="flex:1;">
                            <button class="gear-preset-btn" onclick="document.getElementById('sp-ext-an').value=20">20°</button>
                            <button class="gear-preset-btn" onclick="document.getElementById('sp-ext-an').value=30">30°</button>
                            <button class="gear-preset-btn" onclick="document.getElementById('sp-ext-an').value=45">45°</button>
                        </div>
                    </div>
                    <div class="gear-field-group">
                        <label>螺旋角 β° <small style="color:#999;">直齒填0</small></label>
                        <input type="number" id="sp-ext-bt" class="gear-input" step="any" placeholder="0">
                    </div>
                    <div class="gear-field-group">
                        <label>轉位係數 x <small style="color:#999;">空白=0</small></label>
                        <input type="number" id="sp-ext-x" class="gear-input" step="any" placeholder="0">
                    </div>
                    <div class="gear-field-group">
                        <label>齒根形式</label>
                        <select id="sp-ext-root" class="gear-input">
                            <option value="flat">平底 Flat Root（hf*=1.25）</option>
                            <option value="fillet">圓弧 Fillet Root（hf*=1.50）</option>
                        </select>
                    </div>
                    <div style="display:flex;gap:6px;">
                        <div class="gear-field-group" style="flex:1;">
                            <label>精度等級 <small style="color:#999;">4~7 / Q3~Q12</small></label>
                            <input type="text" id="sp-ext-q" class="gear-input" placeholder="例：7">
                        </div>
                        <div class="gear-field-group" style="flex:1;">
                            <label>配合代號</label>
                            <select id="sp-ext-fit" class="gear-input">
                                <option value="h">h（零偏差）</option>
                                <option value="g">g（小間隙）</option>
                                <option value="f">f（中間隙）</option>
                                <option value="e">e（大間隙）</option>
                                <option value="d">d（較大間隙）</option>
                                <option value="b">b（DIN5480 大間隙）</option>
                            </select>
                        </div>
                    </div>
                    <div style="display:flex;gap:6px;">
                        <div class="gear-field-group" style="flex:1;">
                            <label>測棒直徑 dp <small style="color:#999;">空白=1.68mn</small></label>
                            <input type="number" id="sp-ext-dp" class="gear-input" step="any" placeholder="自動">
                        </div>
                        <div class="gear-field-group" style="flex:1;">
                            <label>跨齒數 k <small style="color:#999;">不填=不算Wk</small></label>
                            <input type="number" id="sp-ext-k" class="gear-input" step="1" min="1" placeholder="省略">
                        </div>
                    </div>
                    <div style="margin-top:6px;display:flex;gap:6px;">
                        <button class="btn-spline-calc" onclick="calcSplineExt()"><i class="fa fa-calculator"></i> 計算</button>
                        <button class="btn-gear-clr" onclick="clearSplineExt()">清除</button>
                    </div>
                </div>
                <!-- 右：輸出 -->
                <div class="gear-col-right">
                    <div class="gear-section-title">外花鍵 計算結果</div>
                    <div id="sp-ext-warns" class="sp-warn-box" style="display:none;"></div>
                    <div class="gear-out-grid" style="margin-bottom:6px;">
                        <div class="gear-out-row"><span class="gear-out-label">節圓直徑 d</span><span class="gear-output-val" id="sp-ext-out-d">—</span></div>
                        <div class="gear-out-row"><span class="gear-out-label">齒頂圓(外徑) da</span><span class="gear-output-val" id="sp-ext-out-da">—</span></div>
                        <div class="gear-out-row"><span class="gear-out-label">齒根圓(根徑) df</span><span class="gear-output-val" id="sp-ext-out-df">—</span></div>
                        <div class="gear-out-row"><span class="gear-out-label">基圓 db</span><span class="gear-output-val" id="sp-ext-out-db">—</span></div>
                        <div class="gear-out-row"><span class="gear-out-label">全齒高 h</span><span class="gear-output-val" id="sp-ext-out-h">—</span></div>
                        <div class="gear-out-row"><span class="gear-out-label">使用測棒 dp</span><span class="gear-output-val" id="sp-ext-out-dp-used">—</span></div>
                    </div>
                    <div id="sp-ext-out-wk-wrap">
                        <div style="font-size:11px;font-weight:700;color:#1a6fa0;border-top:1px solid #d0e4f0;padding-top:5px;margin-bottom:4px;">跨齒厚 Wk</div>
                        <div class="gear-out-grid">
                            <div class="gear-out-row"><span class="gear-out-label">理論 Wk</span><span class="gear-output-val" id="sp-ext-out-wk-nom">—</span></div>
                            <div></div>
                            <div class="gear-out-row"><span class="gear-out-label">Wk 上限</span><span class="gear-output-val" id="sp-ext-out-wk-up">—</span></div>
                            <div class="gear-out-row"><span class="gear-out-label">Wk 下限</span><span class="gear-output-val" id="sp-ext-out-wk-dn">—</span></div>
                        </div>
                    </div>
                    <div style="font-size:11px;font-weight:700;color:#1a6fa0;border-top:1px solid #d0e4f0;padding-top:5px;margin:6px 0 4px;">跨珠值 M（外量）</div>
                    <div class="gear-out-grid">
                        <div class="gear-out-row"><span class="gear-out-label">M 公稱值</span><span class="gear-output-val" id="sp-ext-out-m-nom">—</span></div>
                        <div></div>
                        <div class="gear-out-row"><span class="gear-out-label">M 上限</span><span class="gear-output-val" id="sp-ext-out-m-up">—</span></div>
                        <div class="gear-out-row"><span class="gear-out-label">M 下限</span><span class="gear-output-val" id="sp-ext-out-m-dn">—</span></div>
                    </div>
                    <div id="sp-ext-tol-src" style="font-size:11px;color:#666;margin-top:4px;"></div>
                    <!-- dp 換算 -->
                    <div class="sp-conv-area">
                        <div class="gear-section-title" style="margin-bottom:5px;">測棒直徑換算</div>
                        <div style="display:flex;gap:6px;align-items:flex-end;">
                            <div class="gear-field-group" style="flex:1;margin:0;">
                                <label style="font-size:10px;color:#888;">換算用 dp2</label>
                                <input type="number" id="sp-ext-conv-dp2" class="gear-input" step="any" placeholder="新 dp">
                            </div>
                            <button class="btn-gear-m2" onclick="calcSplineConvExt()">換算 M</button>
                        </div>
                        <div class="gear-out-grid" style="margin-top:4px;">
                            <div class="gear-out-row"><span class="gear-out-label">換算後 M 上限</span><span class="gear-output-val" id="sp-ext-conv-m-up">—</span></div>
                            <div class="gear-out-row"><span class="gear-out-label">換算後 M 下限</span><span class="gear-output-val" id="sp-ext-conv-m-dn">—</span></div>
                        </div>
                    </div>
                </div>
            </div>
        </div><!-- /pane-sp-ext -->

        <!-- ══ 花鍵 內 ══════════════════════════════════════════════════════ -->
        <div class="gear-tab-pane" id="gear-pane-sp-int">
            <div style="background:#fff3cd;border:1px solid #ffc107;border-radius:4px;padding:4px 9px;font-size:11px;color:#856404;margin-bottom:8px;">⚠ 尚未完整測試，計算結果請自行驗證</div>
            <div class="gear-two-col">
                <!-- 左：輸入 -->
                <div class="gear-col-left">
                    <div class="gear-section-title">內花鍵 輸入參數</div>
                    <div class="gear-field-group">
                        <label>標準</label>
                        <select id="sp-int-std" class="gear-input" onchange="splineIntStdChange()">
                            <option value="ISO4156">ISO 4156</option>
                            <option value="DIN5480">DIN 5480</option>
                            <option value="ANSIB922">ANSI B92.2</option>
                        </select>
                    </div>
                    <div id="sp-int-mn-wrap" class="gear-field-group">
                        <label>法向模數 mn <span style="color:#e74c3c">*</span></label>
                        <input type="number" id="sp-int-mn" class="gear-input" step="any" placeholder="例：2">
                    </div>
                    <div id="sp-int-pd-wrap" class="gear-field-group" style="display:none;">
                        <label>Pd（牙/英吋）<span style="color:#e74c3c">*</span></label>
                        <input type="number" id="sp-int-pd" class="gear-input" step="any" placeholder="例：12">
                        <div id="sp-int-ansi-info" style="display:none;font-size:11px;color:#888;margin-top:2px;"><span id="sp-int-ansi-pd"></span></div>
                    </div>
                    <div class="gear-field-group">
                        <label>齒數 z <span style="color:#e74c3c">*</span></label>
                        <input type="number" id="sp-int-z" class="gear-input" step="1" min="2" placeholder="例：20">
                    </div>
                    <div class="gear-field-group">
                        <label>壓力角 α° <small style="color:#999;">預設30°</small></label>
                        <div style="display:flex;gap:4px;align-items:center;">
                            <input type="number" id="sp-int-an" class="gear-input" step="any" placeholder="30" style="flex:1;">
                            <button class="gear-preset-btn" onclick="document.getElementById('sp-int-an').value=20">20°</button>
                            <button class="gear-preset-btn" onclick="document.getElementById('sp-int-an').value=30">30°</button>
                            <button class="gear-preset-btn" onclick="document.getElementById('sp-int-an').value=45">45°</button>
                        </div>
                    </div>
                    <div class="gear-field-group">
                        <label>螺旋角 β° <small style="color:#999;">直齒填0</small></label>
                        <input type="number" id="sp-int-bt" class="gear-input" step="any" placeholder="0">
                    </div>
                    <div class="gear-field-group">
                        <label>轉位係數 x <small style="color:#999;">空白=0</small></label>
                        <input type="number" id="sp-int-x" class="gear-input" step="any" placeholder="0">
                    </div>
                    <div class="gear-field-group">
                        <label>齒根形式</label>
                        <select id="sp-int-root" class="gear-input">
                            <option value="flat">平底 Flat Root（hf*=1.25）</option>
                            <option value="fillet">圓弧 Fillet Root（hf*=1.50）</option>
                        </select>
                    </div>
                    <div style="display:flex;gap:6px;">
                        <div class="gear-field-group" style="flex:1;">
                            <label>精度等級 <small style="color:#999;">4~7 / Q3~Q12</small></label>
                            <input type="text" id="sp-int-q" class="gear-input" placeholder="例：7">
                        </div>
                        <div class="gear-field-group" style="flex:1;">
                            <label>配合代號</label>
                            <select id="sp-int-fit" class="gear-input">
                                <option value="H">H（零偏差）</option>
                                <option value="JS">JS（對稱）</option>
                                <option value="K">K（輕過盈）</option>
                            </select>
                        </div>
                    </div>
                    <div class="gear-field-group">
                        <label>測棒直徑 dp <small style="color:#999;">空白=1.68mn</small></label>
                        <input type="number" id="sp-int-dp" class="gear-input" step="any" placeholder="自動">
                    </div>
                    <div style="margin-top:6px;display:flex;gap:6px;">
                        <button class="btn-spline-calc" onclick="calcSplineInt()"><i class="fa fa-calculator"></i> 計算</button>
                        <button class="btn-gear-clr" onclick="clearSplineInt()">清除</button>
                    </div>
                </div>
                <!-- 右：輸出 -->
                <div class="gear-col-right">
                    <div class="gear-section-title">內花鍵 計算結果</div>
                    <div id="sp-int-warns" class="sp-warn-box" style="display:none;"></div>
                    <div class="gear-out-grid" style="margin-bottom:6px;">
                        <div class="gear-out-row"><span class="gear-out-label">節圓直徑 d</span><span class="gear-output-val" id="sp-int-out-d">—</span></div>
                        <div class="gear-out-row"><span class="gear-out-label">最小內徑(齒頂) di</span><span class="gear-output-val" id="sp-int-out-di">—</span></div>
                        <div class="gear-out-row"><span class="gear-out-label">最大外徑(齒根) Df</span><span class="gear-output-val" id="sp-int-out-Df">—</span></div>
                        <div class="gear-out-row"><span class="gear-out-label">基圓 db</span><span class="gear-output-val" id="sp-int-out-db">—</span></div>
                        <div class="gear-out-row"><span class="gear-out-label">全齒高 h</span><span class="gear-output-val" id="sp-int-out-h">—</span></div>
                        <div class="gear-out-row"><span class="gear-out-label">使用測棒 dp</span><span class="gear-output-val" id="sp-int-out-dp-used">—</span></div>
                    </div>
                    <div class="gear-out-grid" style="margin-bottom:6px;">
                        <div class="gear-out-row"><span class="gear-out-label">測棒最大限制 dp_max</span><span class="gear-output-val" id="sp-int-out-dp-max">—</span></div>
                    </div>
                    <div style="font-size:11px;font-weight:700;color:#1a6fa0;border-top:1px solid #d0e4f0;padding-top:5px;margin-bottom:4px;">跨珠值 M（內量，dc−dp）</div>
                    <div class="gear-out-grid">
                        <div class="gear-out-row"><span class="gear-out-label">M 公稱值</span><span class="gear-output-val" id="sp-int-out-m-nom">—</span></div>
                        <div></div>
                        <div class="gear-out-row"><span class="gear-out-label">M 下限</span><span class="gear-output-val" id="sp-int-out-m-lo">—</span></div>
                        <div class="gear-out-row"><span class="gear-out-label">M 上限</span><span class="gear-output-val" id="sp-int-out-m-up">—</span></div>
                    </div>
                    <div id="sp-int-tol-src" style="font-size:11px;color:#666;margin-top:4px;"></div>
                    <!-- dp 換算 -->
                    <div class="sp-conv-area">
                        <div class="gear-section-title" style="margin-bottom:5px;">測棒直徑換算</div>
                        <div style="display:flex;gap:6px;align-items:flex-end;">
                            <div class="gear-field-group" style="flex:1;margin:0;">
                                <label style="font-size:10px;color:#888;">換算用 dp2</label>
                                <input type="number" id="sp-int-conv-dp2" class="gear-input" step="any" placeholder="新 dp">
                            </div>
                            <button class="btn-gear-m3" onclick="calcSplineConvInt()">換算 M</button>
                        </div>
                        <div class="gear-out-grid" style="margin-top:4px;">
                            <div class="gear-out-row"><span class="gear-out-label">換算後 M 下限</span><span class="gear-output-val" id="sp-int-conv-m-lo">—</span></div>
                            <div class="gear-out-row"><span class="gear-out-label">換算後 M 上限</span><span class="gear-output-val" id="sp-int-conv-m-up">—</span></div>
                        </div>
                    </div>
                </div>
            </div>
        </div><!-- /pane-sp-int -->

        <!-- ══ 花鍵配合 ══════════════════════════════════════════════════════ -->
        <div class="gear-tab-pane" id="gear-pane-sp-fit">
            <div style="background:#fff3cd;border:1px solid #ffc107;border-radius:4px;padding:4px 9px;font-size:11px;color:#856404;margin-bottom:8px;">⚠ 尚未完整測試，計算結果請自行驗證</div>
            <div id="sp-fit-param-warn" class="sp-warn-box" style="display:none;"></div>
            <div style="display:flex;gap:6px;align-items:center;margin-bottom:8px;">
                <button class="btn-spline-calc" onclick="calcSplineFit()"><i class="fa fa-check-circle"></i> 執行配合驗算</button>
                <span style="font-size:11px;color:#888;">（需先分別完成外/內花鍵計算）</span>
            </div>
            <div id="sp-fit-result-area" style="display:none;">
                <div class="gear-section-title">配合參數</div>
                <div class="gear-out-grid" style="margin-bottom:8px;">
                    <div class="gear-out-row"><span class="gear-out-label">模數 mn</span><span class="gear-output-val" id="sp-fit-mn">—</span></div>
                    <div class="gear-out-row"><span class="gear-out-label">齒數 z</span><span class="gear-output-val" id="sp-fit-z">—</span></div>
                    <div class="gear-out-row"><span class="gear-out-label">壓力角 α</span><span class="gear-output-val" id="sp-fit-an">—</span></div>
                    <div></div>
                    <div class="gear-out-row"><span class="gear-out-label">外花鍵轉位 x</span><span class="gear-output-val" id="sp-fit-x-ext">—</span></div>
                    <div class="gear-out-row"><span class="gear-out-label">內花鍵轉位 x</span><span class="gear-output-val" id="sp-fit-x-int">—</span></div>
                </div>
                <div class="gear-section-title">測量值範圍對照</div>
                <div style="display:flex;gap:10px;margin-bottom:8px;">
                    <div style="flex:1;background:#f8fafb;border:1px solid #d0e4f0;border-radius:4px;padding:7px 10px;">
                        <div style="font-size:11px;font-weight:700;color:#1a6fa0;margin-bottom:4px;">外花鍵（dp=<span id="sp-fit-ext-dp">—</span>）</div>
                        <div class="gear-out-grid">
                            <div class="gear-out-row"><span class="gear-out-label">M 上限</span><span class="gear-output-val" id="sp-fit-ext-m-up">—</span></div>
                            <div class="gear-out-row"><span class="gear-out-label">M 下限</span><span class="gear-output-val" id="sp-fit-ext-m-dn">—</span></div>
                        </div>
                    </div>
                    <div style="flex:1;background:#fdf5ff;border:1px solid #d8b4fe;border-radius:4px;padding:7px 10px;">
                        <div style="font-size:11px;font-weight:700;color:#6c3483;margin-bottom:4px;">內花鍵（dp=<span id="sp-fit-int-dp">—</span>）</div>
                        <div class="gear-out-grid">
                            <div class="gear-out-row"><span class="gear-out-label">M 下限</span><span class="gear-output-val" id="sp-fit-int-m-dn">—</span></div>
                            <div class="gear-out-row"><span class="gear-out-label">M 上限</span><span class="gear-output-val" id="sp-fit-int-m-up">—</span></div>
                        </div>
                    </div>
                </div>
                <div class="gear-section-title">側面間隙（節圓切線方向，單位 mm）</div>
                <div class="gear-out-grid" style="margin-bottom:4px;">
                    <div class="gear-out-row"><span class="gear-out-label">公稱間隙 j</span><span class="gear-output-val" id="sp-fit-j-nom">—</span></div>
                    <div></div>
                    <div class="gear-out-row"><span class="gear-out-label">最小間隙 j_min</span><span class="gear-output-val" id="sp-fit-j-min">—</span></div>
                    <div class="gear-out-row"><span class="gear-out-label">最大間隙 j_max</span><span class="gear-output-val" id="sp-fit-j-max">—</span></div>
                    <div class="gear-out-row"><span class="gear-out-label">公稱徑向間隙</span><span class="gear-output-val" id="sp-fit-jr-nom">—</span></div>
                    <div></div>
                    <div class="gear-out-row"><span class="gear-out-label">最小徑向間隙</span><span class="gear-output-val" id="sp-fit-jr-min">—</span></div>
                    <div class="gear-out-row"><span class="gear-out-label">最大徑向間隙</span><span class="gear-output-val" id="sp-fit-jr-max">—</span></div>
                </div>
                <div id="sp-fit-class" class="sp-fit-result sp-fit-clearance">—</div>
                <div id="sp-fit-tol-note" style="font-size:10px;color:#999;text-align:center;margin-top:4px;"></div>
            </div>
        </div><!-- /pane-sp-fit -->

        <!-- ══ 栓槽跨銷值計算 ════════════════════════════════════════════════ -->
        <div class="gear-tab-pane" id="gear-pane-sr">
            <div style="background:#fff3cd;border:1px solid #ffc107;border-radius:4px;padding:4px 9px;font-size:11px;color:#856404;margin-bottom:6px;">⚠ 尚未完整測試，計算結果請自行驗證</div>
            <div style="background:#e8f5e9;border:1px solid #a5d6a7;border-radius:4px;padding:6px 9px;font-size:11px;color:#2e7d32;margin-bottom:10px;">
                矩形花鍵（栓槽）跨銷值計算。輸入大徑 D、小徑 d、槽寬 B、槽數 N，可附加公差回推 M 值範圍。
            </div>
            <div class="gear-two-col">
                <div>
                    <div class="gear-field-group">
                        <label>槽數 N</label>
                        <input type="number" id="sr-n" class="gear-input" placeholder="例：6" min="2" step="1">
                    </div>
                    <div class="gear-field-group">
                        <label>大徑 D (mm)</label>
                        <div style="display:flex;gap:4px;align-items:center;">
                            <input type="number" id="sr-D" class="gear-input" placeholder="公稱" style="flex:1;">
                            <span style="font-size:11px;color:#888;white-space:nowrap;">上 +</span>
                            <input type="number" id="sr-D-up" class="gear-input" placeholder="0" style="width:62px;" value="0">
                            <span style="font-size:11px;color:#888;white-space:nowrap;">下 −</span>
                            <input type="number" id="sr-D-dn" class="gear-input" placeholder="0" style="width:62px;" value="0">
                        </div>
                    </div>
                    <div class="gear-field-group">
                        <label>小徑 d (mm)</label>
                        <div style="display:flex;gap:4px;align-items:center;">
                            <input type="number" id="sr-d" class="gear-input" placeholder="公稱" style="flex:1;">
                            <span style="font-size:11px;color:#888;white-space:nowrap;">上 +</span>
                            <input type="number" id="sr-d-up" class="gear-input" placeholder="0" style="width:62px;" value="0">
                            <span style="font-size:11px;color:#888;white-space:nowrap;">下 −</span>
                            <input type="number" id="sr-d-dn" class="gear-input" placeholder="0" style="width:62px;" value="0">
                        </div>
                    </div>
                    <div class="gear-field-group">
                        <label>槽寬 B (mm)  <small style="color:#888;">可附公差：數值或代號如 e8、H7</small></label>
                        <div style="display:flex;gap:4px;align-items:center;">
                            <input type="number" id="sr-B" class="gear-input" placeholder="公稱" style="flex:1;">
                            <span style="font-size:11px;color:#888;white-space:nowrap;">公差代號</span>
                            <input type="text" id="sr-B-tol" class="gear-input" placeholder="e8 或留空" style="width:72px;">
                        </div>
                    </div>
                    <div class="gear-field-group">
                        <label>類型</label>
                        <div style="display:flex;gap:16px;margin-top:2px;">
                            <label style="font-size:12px;font-weight:400;cursor:pointer;"><input type="radio" name="sr-type" value="shaft" checked> 軸（外花鍵）</label>
                            <label style="font-size:12px;font-weight:400;cursor:pointer;"><input type="radio" name="sr-type" value="bore"> 孔（內花鍵）</label>
                        </div>
                    </div>
                    <div class="gear-field-group">
                        <label>自訂球徑 dp（可選，留空用建議值）</label>
                        <input type="number" id="sr-dp-custom" class="gear-input" placeholder="留空自動建議">
                    </div>
                    <button onclick="calcSplineRect()" style="background:#2ecc71;color:#fff;border:none;border-radius:4px;padding:5px 14px;font-size:12px;font-weight:600;cursor:pointer;margin-top:4px;">計算</button>
                </div>
                <div>
                    <div class="gear-field-group">
                        <label>可用球徑範圍</label>
                        <span class="gear-output-val" id="sr-dp-range">—</span>
                    </div>
                    <div class="gear-field-group">
                        <label>建議球徑 dp <small style="color:#888;font-weight:400;">（≈ 0.9×B）</small></label>
                        <span class="gear-output-val" id="sr-dp-rec">—</span>
                    </div>
                    <div style="border-top:1px solid #e0e0e0;margin:8px 0 6px;padding-top:6px;font-size:11px;font-weight:700;color:#1a3a50;">跨銷值（放球入槽量外徑）</div>
                    <div class="gear-field-group">
                        <label>跨銷 M（公稱）</label>
                        <span class="gear-output-val" id="sr-M-nom">—</span>
                    </div>
                    <div class="gear-field-group">
                        <label>跨銷範圍（含公差）</label>
                        <span class="gear-output-val" id="sr-M-range">—</span>
                    </div>
                    <div id="sr-method" style="font-size:11px;color:#555;line-height:1.5;padding:2px 0 4px;display:none;"></div>
                    <div style="border-top:1px solid #e0e0e0;margin:8px 0 6px;padding-top:6px;font-size:11px;font-weight:700;color:#1a3a50;">齒厚（直接量齒面寬）</div>
                    <div class="gear-field-group">
                        <label>單齒齒厚 b <small style="color:#888;font-weight:400;">（小徑弦長）</small></label>
                        <span class="gear-output-val" id="sr-tooth-nom">—</span>
                    </div>
                    <div class="gear-field-group">
                        <label>齒厚範圍（含公差）</label>
                        <span class="gear-output-val" id="sr-tooth-range">—</span>
                    </div>
                    <div id="sr-warn" style="display:none;margin-top:6px;padding:5px 8px;background:#fff3cd;border:1px solid #ffc107;border-radius:4px;font-size:11px;color:#856404;"></div>
                </div>
            </div>
        </div><!-- /pane-sr -->

        <!-- ══ 出尾計算 ════════════════════════════════════════════════════════ -->
        <div class="gear-tab-pane" id="gear-pane-tail">
            <div style="background:#e3f2fd;border:1px solid #90caf9;border-radius:4px;padding:6px 9px;font-size:11px;color:#0d47a1;margin-bottom:10px;">
                計算滾刀或齒研砂輪的出尾長度，以及最大刀具外徑建議。齒根直徑為加工完成後的齒根圓直徑。
            </div>
            <div class="gear-two-col" style="align-items:flex-start;gap:16px;">
                <div style="min-width:260px;">
                    <div class="gear-field-group">
                        <label>肩部直徑 Ds (mm)  <small style="color:#888;">較大徑</small></label>
                        <input type="number" id="tail-ds" class="gear-input" placeholder="例：52">
                    </div>
                    <div class="gear-field-group">
                        <label>齒根直徑 Dr (mm)  <small style="color:#888;">加工後齒根圓</small></label>
                        <input type="number" id="tail-dr" class="gear-input" placeholder="例：47.2">
                    </div>
                    <div class="gear-field-group">
                        <label>退刀量 U (mm)</label>
                        <input type="number" id="tail-u" class="gear-input" placeholder="0" value="0">
                    </div>
                    <div style="border-top:1px solid #e0e0e0;margin:10px 0 8px;"></div>
                    <div style="font-size:12px;font-weight:700;color:#1a3a50;margin-bottom:6px;">A：輸入刀具外徑 → 求出尾長度</div>
                    <div class="gear-field-group">
                        <label>刀具外徑 Da (mm)</label>
                        <input type="number" id="tail-da-a" class="gear-input" placeholder="例：50">
                    </div>
                    <button onclick="calcTailA()" style="background:#1a6fa0;color:#fff;border:none;border-radius:4px;padding:5px 14px;font-size:12px;font-weight:600;cursor:pointer;">計算出尾</button>
                    <div class="gear-field-group" style="margin-top:8px;">
                        <label>幾何出尾長度</label>
                        <span class="gear-output-val" id="tail-geo">—</span>
                    </div>
                    <div class="gear-field-group">
                        <label>出尾長度（含退刀量）</label>
                        <span class="gear-output-val" id="tail-total">—</span>
                    </div>
                    <div style="border-top:1px solid #e0e0e0;margin:10px 0 8px;"></div>
                    <div style="font-size:12px;font-weight:700;color:#1a3a50;margin-bottom:6px;">B：輸入出尾長度 → 求最大刀具外徑</div>
                    <div class="gear-field-group">
                        <label>目標出尾長度（含退刀量）(mm)</label>
                        <input type="number" id="tail-target" class="gear-input" placeholder="例：10">
                    </div>
                    <button onclick="calcTailB()" style="background:#8e44ad;color:#fff;border:none;border-radius:4px;padding:5px 14px;font-size:12px;font-weight:600;cursor:pointer;">計算最大刀具外徑</button>
                    <div class="gear-field-group" style="margin-top:8px;">
                        <label>最大建議刀具外徑 Da_max</label>
                        <span class="gear-output-val" id="tail-da-max">—</span>
                    </div>
                    <div id="tail-warn" style="display:none;margin-top:6px;padding:5px 8px;background:#fff3cd;border:1px solid #ffc107;border-radius:4px;font-size:11px;color:#856404;"></div>
                </div>
                <div style="flex:1;">
                    <div style="font-size:11px;color:#888;margin-bottom:4px;">示意圖（按比例縮放）</div>
                    <svg id="tail-svg" width="100%" viewBox="0 0 440 300" style="border:1px solid #dde;background:#fafcff;border-radius:4px;font-family:Consolas,monospace;"></svg>
                </div>
            </div>
        </div><!-- /pane-tail -->

        <!-- ══ 預留量管理 ════════════════════════════════════════════════════ -->
        <div class="gear-tab-pane" id="gear-pane-tables">
            <div class="gear-two-col" style="align-items:flex-start;">
                <!-- 模數預留量表 -->
                <div style="flex:1;min-width:260px;">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">
                        <div class="gear-section-title" style="margin:0;">模數預留量表</div>
                        <button class="btn-gear-add" onclick="openMnForm(0)"><i class="fa fa-plus"></i> 新增</button>
                    </div>
                    <div class="gear-allow-tbl-wrap">
                        <table class="gear-allow-table">
                            <thead><tr><th>模數 &gt;</th><th>&lt;=</th><th>滾齒預留</th><th>BOSS</th><th>操作</th></tr></thead>
                            <tbody id="mn-tbl-body"></tbody>
                        </table>
                    </div>
                    <div id="mn-form-area" style="display:none;">
                        <div class="gear-inline-form" id="mn-form">
                            <div><label><input type="checkbox" id="mn-f-exact" onchange="toggleMnExact()"> 精確(=)</label></div>
                            <div><label id="mn-f-gt-lbl">模數 &gt;</label><input type="number" id="mn-f-gt" step="any" placeholder="0"></div>
                            <div id="mn-f-lte-wrap"><label>&lt;</label><input type="number" id="mn-f-lte" step="any" placeholder="1"></div>
                            <div><label>預留量</label><input type="number" id="mn-f-allow" step="any" placeholder="0.2"></div>
                            <div><label><input type="checkbox" id="mn-f-boss"> BOSS確認</label></div>
                            <input type="hidden" id="mn-f-id" value="0">
                            <button class="btn-gear-save" onclick="saveMnRow()">儲存</button>
                            <button class="btn-gear-cancel" onclick="closeMnForm()">取消</button>
                        </div>
                        <div id="mn-form-err" style="font-size:11px;color:#c0392b;padding:3px 8px;"></div>
                    </div>
                </div>
                <!-- 外徑預留量表 -->
                <div style="flex:1;min-width:260px;">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">
                        <div class="gear-section-title" style="margin:0;">外徑預留量表</div>
                        <button class="btn-gear-add" onclick="openDaForm(0)"><i class="fa fa-plus"></i> 新增</button>
                    </div>
                    <div class="gear-allow-tbl-wrap">
                        <table class="gear-allow-table">
                            <thead><tr><th>外徑 &gt;</th><th>&lt;=</th><th>滾齒預留</th><th>BOSS</th><th>操作</th></tr></thead>
                            <tbody id="da-tbl-body"></tbody>
                        </table>
                    </div>
                    <div id="da-form-area" style="display:none;">
                        <div class="gear-inline-form" id="da-form">
                            <div><label>外徑 &gt;</label><input type="number" id="da-f-gt" step="any" placeholder="0"></div>
                            <div><label>&lt;</label><input type="number" id="da-f-lte" step="any" placeholder="150"></div>
                            <div><label>預留量</label><input type="number" id="da-f-allow" step="any" placeholder="0.3"></div>
                            <div><label><input type="checkbox" id="da-f-boss"> BOSS確認</label></div>
                            <input type="hidden" id="da-f-id" value="0">
                            <button class="btn-gear-save" onclick="saveDaRow()">儲存</button>
                            <button class="btn-gear-cancel" onclick="closeDaForm()">取消</button>
                        </div>
                        <div id="da-form-err" style="font-size:11px;color:#c0392b;padding:3px 8px;"></div>
                    </div>
                </div>
            </div>
            <div style="margin-top:10px;font-size:11px;color:#999;border-top:1px solid #eee;padding-top:6px;">
                <i class="fa fa-info-circle"></i> 計算時自動從兩表查詢匹配列（<code>值 &gt; 下限 AND 值 &lt;= 上限</code>，精確列為 <code>值 = 設定值</code>），優先精確匹配，再取較大預留量。
            </div>

            <!-- 花鍵公差資料管理 -->
            <div style="margin-top:14px;border-top:2px solid #e8d5f5;padding-top:10px;">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">
                    <div style="font-size:13px;font-weight:700;color:#6c3483;"><i class="fa fa-cog"></i> 花鍵公差資料管理</div>
                    <button class="btn-gear-add" style="background:#7d3c98;" onclick="openSplineTolForm(0)"><i class="fa fa-plus"></i> 新增</button>
                </div>
                <div style="font-size:11px;color:#888;margin-bottom:6px;">
                    未找到匹配資料時，系統以 ISO 286-1 IT 公式估算（標示「估算」）。此處可輸入標準文件的精確值加以覆蓋。
                </div>
                <div class="sp-tol-tbl-wrap">
                    <table class="sp-tol-table">
                        <thead><tr><th>標準</th><th>外/內</th><th>精度</th><th>配合</th><th>模數範圍</th><th>上偏差</th><th>公差T</th><th>來源</th><th>操作</th></tr></thead>
                        <tbody id="sp-tol-tbl-body"><tr><td colspan="9" style="color:#aaa;padding:8px;text-align:center;">載入中…</td></tr></tbody>
                    </table>
                </div>
                <div id="sp-tol-form-area" style="display:none;margin-top:6px;">
                    <div style="background:#fdf5ff;border:1px solid #d8b4fe;border-radius:4px;padding:10px;">
                        <div style="display:flex;gap:6px;flex-wrap:wrap;">
                            <div><label style="font-size:10px;color:#555;">標準</label><br>
                                <select id="sp-tol-f-std" class="gear-input" style="width:110px;font-size:12px;">
                                    <option value="ISO4156">ISO 4156</option><option value="DIN5480">DIN 5480</option><option value="ANSIB922">ANSI B92.2</option>
                                </select></div>
                            <div><label style="font-size:10px;color:#555;">外/內</label><br>
                                <select id="sp-tol-f-isext" class="gear-input" style="width:60px;font-size:12px;">
                                    <option value="1">外</option><option value="0">內</option>
                                </select></div>
                            <div><label style="font-size:10px;color:#555;">精度等級</label><br>
                                <input type="text" id="sp-tol-f-qc" class="gear-input" style="width:60px;font-size:12px;" placeholder="7"></div>
                            <div><label style="font-size:10px;color:#555;">配合代號</label><br>
                                <input type="text" id="sp-tol-f-fc" class="gear-input" style="width:50px;font-size:12px;" placeholder="h"></div>
                            <div><label style="font-size:10px;color:#555;">模數 &gt;</label><br>
                                <input type="number" id="sp-tol-f-mgt" class="gear-input" style="width:60px;font-size:12px;" step="any" placeholder="0"></div>
                            <div><label style="font-size:10px;color:#555;">&lt;=</label><br>
                                <input type="number" id="sp-tol-f-mlte" class="gear-input" style="width:60px;font-size:12px;" step="any" placeholder="∞"></div>
                            <div><label style="font-size:10px;color:#555;">上偏差(mm)</label><br>
                                <input type="number" id="sp-tol-f-udev" class="gear-input" style="width:80px;font-size:12px;" step="any" placeholder="0"></div>
                            <div><label style="font-size:10px;color:#555;">公差T(mm)</label><br>
                                <input type="number" id="sp-tol-f-tol" class="gear-input" style="width:80px;font-size:12px;" step="any" placeholder="0.02"></div>
                            <div><label style="font-size:10px;color:#555;">來源</label><br>
                                <select id="sp-tol-f-isest" class="gear-input" style="width:70px;font-size:12px;">
                                    <option value="0">精確</option><option value="1">估算</option>
                                </select></div>
                        </div>
                        <div style="margin-top:6px;">
                            <label style="font-size:10px;color:#555;">備註</label><br>
                            <input type="text" id="sp-tol-f-notes" class="gear-input" style="width:100%;font-size:12px;" placeholder="例：ISO 4156-3 Table 1">
                        </div>
                        <input type="hidden" id="sp-tol-f-id" value="0">
                        <div style="margin-top:6px;display:flex;gap:6px;">
                            <button class="btn-gear-save" onclick="saveSplineTolRow()">儲存</button>
                            <button class="btn-gear-cancel" onclick="closeSplineTolForm()">取消</button>
                        </div>
                        <div id="sp-tol-form-err" style="font-size:11px;color:#c0392b;margin-top:4px;"></div>
                    </div>
                </div>
            </div>
        </div><!-- /pane-tables -->

    </div><!-- /gear-tool-body -->
</div><!-- /gear-tool-window --></template>
<div id="gear-tool-container"></div>

<script>
// ══ 齒輪計算工具 JS ══════════════════════════════════════════════════════════
(function(){
    'use strict';

    // ── AJAX：一律打共用端點（本工具現在被多個頁面共用，不能再 post 到 '' ＝當前頁）──
    var GEAR_API = (window.GEAR_TOOL_API_URL || 'gear_tool_api.php');
    var gearAjax = {
        post: function(data, cb) {
            var body = new URLSearchParams();
            for (var k in data) {
                if (Object.prototype.hasOwnProperty.call(data, k)) body.append(k, data[k]);
            }
            fetch(GEAR_API, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                body: body
            }).then(function(r){ return r.json(); })
              .then(function(j){ if (cb) cb(j); })
              .catch(function(err){
                  if (cb) cb({ success: false, message: '連線失敗，請重新整理頁面後再試' + (err && err.message ? '（' + err.message + '）' : '') });
              });
        }
    };
    // 原本借用訂單追蹤頁的全域 escapeHtml；抽成共用檔後自帶一份，才不會依賴呼叫端
    function escapeHtml(s) {
        return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function(c){
            return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c];
        });
    }


    const PI = Math.PI;
    var _baseParams = null;   // 模組一計算後的共享參數
    var _mnTable = [];        // 模數預留量表
    var _daTable = [];        // 外徑預留量表
    var _techDeptIds = [];    // 技術課 dept IDs（設定用）
    var _allDepts = [];       // 所有部門（設定用）

    // ── 工具函數 ─────────────────────────────────────────────────────────────
    function gRound(v, n) { var f = Math.pow(10, n); return Math.round(v * f) / f; }
    function dmsToRad(d, m, s) { return ((+d || 0) + (+m || 0) / 60 + (+s || 0) / 3600) * PI / 180; }
    function dmsToDecDeg(d, m, s) { return (+d || 0) + (+m || 0) / 60 + (+s || 0) / 3600; }
    function fmtNum(v, n) {
        if (v === null || v === undefined || v === '' || isNaN(v)) return '—';
        var s = gRound(v, n).toString();
        // 去除多餘小數位的零
        if (s.indexOf('.') !== -1) s = s.replace(/\.?0+$/, '');
        return s;
    }
    function gVal(id) { var el = document.getElementById(id); return el ? el.value.trim() : ''; }
    function gFloat(id) { var v = parseFloat(gVal(id)); return isNaN(v) ? null : v; }
    function gInt(id) { var v = parseInt(gVal(id)); return isNaN(v) ? null : v; }
    function setOut(id, v, cls) {
        var el = document.getElementById(id); if (!el) return;
        el.textContent = (v === null || v === undefined || v === '') ? '—' : String(v);
        el.className = 'gear-output-val' + (cls ? ' ' + cls : '');
    }
    function showWarn(id, msg) {
        var el = document.getElementById(id); if (!el) return;
        if (msg) { el.innerHTML = '<i class="fa fa-exclamation-triangle"></i> ' + escapeHtml(msg); el.style.display = 'block'; }
        else el.style.display = 'none';
    }
    function escGear(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

    // ── 即時顯示度分秒換算 ───────────────────────────────────────────────────
    window.updateDmsDecimal = function(key) {
        var d = parseFloat(gVal('g-'+key+'-d')) || 0;
        var m = parseFloat(gVal('g-'+key+'-m')) || 0;
        var s = parseFloat(gVal('g-'+key+'-s')) || 0;
        var dec = dmsToDecDeg(d, m, s);
        var el = document.getElementById('g-'+key+'-dec');
        if (el) el.textContent = fmtNum(dec, 6) + '°';
        // 分秒超範圍提示
        ['m','s'].forEach(function(t) {
            var inp = document.getElementById('g-'+key+'-'+t);
            if (!inp) return;
            var v = parseFloat(inp.value);
            if (!isNaN(v) && (v < 0 || v > 59)) inp.classList.add('dms-err');
            else inp.classList.remove('dms-err');
        });
    };

    // ── inv 漸開線函數 ────────────────────────────────────────────────────────
    function inv(angle) { return Math.tan(angle) - angle; }

    // ── 核心：外花鍵跨棒距 M（DIN 5480 / ISO 4156）────────────────────────
    // 步驟 1：節徑 d=mt·z，基圓 db=d·cos(αt)
    // 步驟 2：解超越方程式（牛頓-拉弗森）求量棒中心壓力角 αM：
    //   inv(αM) = s/d + inv(αt) + DR/(db·cos(βb)) - π/z
    //   其中 s/d = π/(2z) + 2x·tan(αn)/z（圓弧齒厚/節圓直徑）
    // 步驟 3：M = db/cos(αM) + DR（偶數齒）；db/cos(αM)·cos(90°/z) + DR（奇數齒）
    function calcM(x_val, mn, z, alpha_n, beta, dp_val) {
        var cos_b  = Math.cos(beta);
        var mt     = mn / cos_b;
        var d      = mt * z;
        var alpha_t = Math.atan(Math.tan(alpha_n) / cos_b);
        var db     = d * Math.cos(alpha_t);
        var sin_bb = Math.sin(beta) * Math.cos(alpha_n);
        var cos_bb = Math.sqrt(1 - sin_bb * sin_bb);

        var st_d  = (PI / (2 * z)) + (2 * x_val * Math.tan(alpha_n) / z);  // s/d
        var inv_p = st_d + inv(alpha_t) + (dp_val / (db * cos_bb)) - (PI / z);

        if (inv_p <= 0) return '異常(inv≤0)';

        var LIMIT = 89 * PI / 180;
        var ap = Math.cbrt(3 * inv_p);  // 初始估計：inv(α) ≈ α³/3
        if (ap >= LIMIT) return '異常(初始角過大)';

        for (var i = 0; i < 100; i++) {
            if (ap >= LIMIT || ap <= 0) return '異常(疊代發散)';
            var fp = Math.tan(ap) * Math.tan(ap);  // f'(α) = sec²(α)-1 = tan²(α)
            if (fp === 0) break;
            var an = ap - (Math.tan(ap) - ap - inv_p) / fp;
            var diff = Math.abs(an - ap);
            ap = an;
            if (diff <= 1e-10) break;
        }
        if (ap >= LIMIT || ap <= 0) return '異常(收斂失敗)';

        var dc = db / Math.cos(ap);
        var M  = (z % 2 === 0) ? dc + dp_val : dc * Math.cos(PI / (2 * z)) + dp_val;
        return gRound(M, 5);
    }

    // ── 由 M 反推 x（二分法）────────────────────────────────────────────────
    function solveXfromM(M_target, mn, z, alpha_n, beta, dp_val) {
        var xlo = -2, xhi = 5;
        for (var i = 0; i < 200; i++) {
            var xm = (xlo + xhi) / 2;
            var Mm = calcM(xm, mn, z, alpha_n, beta, dp_val);
            if (typeof Mm === 'string') { xlo = xm; continue; }
            if (Mm < M_target) xlo = xm; else xhi = xm;
            if ((xhi - xlo) < 1e-9) break;
        }
        return (xlo + xhi) / 2;
    }

    // ── 內齒：由跨銷值 M 反推 x（內齒 M 隨 x 增大而減小，二分方向與外齒相反）──
    function solveXfromMInt(M_target, mn, z, alpha_n, beta, dp_val) {
        var xlo = -2, xhi = 5;
        for (var i = 0; i < 200; i++) {
            var xm = (xlo + xhi) / 2;
            var Mm = calcMInt(xm, mn, z, alpha_n, beta, dp_val);
            if (typeof Mm === 'string') { xhi = xm; continue; }
            if (Mm > M_target) xlo = xm; else xhi = xm;
            if ((xhi - xlo) < 1e-9) break;
        }
        return (xlo + xhi) / 2;
    }

    // ── 由跨齒厚 Wk（跨 k 齒）換算為跨珠值 M（外齒）───────────────────────────
    //    Wk = A + B·x → x = (Wk − A)/B → M = calcM(x)；用於「建議滾齒 M 上/下限」
    //    等須把「跨齒厚公差帶」正確轉成「跨珠值」的情況（不可直接把公差加在 M 上，
    //    因為 dM/dWk ≠ 1）。回傳 M 數值或錯誤字串。
    function wkToM(Wk, mn, z, alpha_n, beta, inv_alpha_t, k, dp_val) {
        var A = mn * Math.cos(alpha_n) * (PI * (k - 0.5) + z * inv_alpha_t);
        var B = 2 * mn * Math.sin(alpha_n);
        if (Math.abs(B) < 1e-10) return '異常(αn=0)';
        var x = (Wk - A) / B;
        return calcM(x, mn, z, alpha_n, beta, dp_val);
    }
    // 由建議滾齒 Wk 公稱 + 公差帶(tolUp/tolDn，加在 Wk 上) 產生「下限 ~ 上限」M 字串
    function wkTolToMRange(rhWk, mn, z, alpha_n, beta, inv_alpha_t, k, dp_val, tolUp, tolDn) {
        var Mup = wkToM(rhWk + tolUp, mn, z, alpha_n, beta, inv_alpha_t, k, dp_val);
        var Mdn = wkToM(rhWk + tolDn, mn, z, alpha_n, beta, inv_alpha_t, k, dp_val);
        var upStr = (typeof Mup === 'string') ? Mup : fmtNum(Mup, 5);
        var dnStr = (typeof Mdn === 'string') ? Mdn : fmtNum(Mdn, 5);
        return dnStr + '  ~  ' + upStr;  // 一律小(下限)在前、大(上限)在後
    }

    // ── 預留量查表 ───────────────────────────────────────────────────────────
    function lookupMn(mn_val) {
        // 優先：精確匹配（is_exact=1）
        for (var i = 0; i < _mnTable.length; i++) {
            var r = _mnTable[i];
            if (parseInt(r.is_exact) && Math.abs(mn_val - parseFloat(r.mn_gt)) < 1e-9) {
                return { allow: parseFloat(r.hob_allow), boss: !!parseInt(r.ask_boss), src: '模數(=)' };
            }
        }
        // 次：範圍匹配（> 下限 AND <= 上限）
        for (var i = 0; i < _mnTable.length; i++) {
            var r = _mnTable[i];
            if (!parseInt(r.is_exact) && mn_val > parseFloat(r.mn_gt) && mn_val <= parseFloat(r.mn_lte)) {
                return { allow: parseFloat(r.hob_allow), boss: !!parseInt(r.ask_boss), src: '模數' };
            }
        }
        return null;
    }
    function lookupDa(da_val) {
        for (var i = 0; i < _daTable.length; i++) {
            var r = _daTable[i];
            if (da_val > parseFloat(r.da_gt) && da_val <= parseFloat(r.da_lte)) {
                return { allow: parseFloat(r.od_allow), boss: !!parseInt(r.ask_boss), src: '外徑' };
            }
        }
        return null;
    }

    // ══ 模組一：基本計算 ═════════════════════════════════════════════════════
    // ── 法向模數輸入單位 M/CP/DP（換算邏輯同 master_data_management：CP→M=值/π、DP→M=25.4/值）──
    function gearMnToM() {
        var v = gFloat('g-mn');
        if (v === null || v <= 0) return null;
        var s = document.getElementById('g-mn-unit');
        var u = s ? s.value : 'M';
        if (u === 'CP') return v / Math.PI;
        if (u === 'DP') return 25.4 / v;
        return v;
    }
    window.updateGearMnDisplay = function() {
        var disp = document.getElementById('g-mn-m-display'); if (!disp) return;
        var s = document.getElementById('g-mn-unit');
        var u = s ? s.value : 'M';
        var m = gearMnToM();
        if (u !== 'M' && m !== null) {
            disp.textContent = '= M' + fmtNum(m, 6);
            disp.style.display = 'block';
        } else {
            disp.style.display = 'none';
        }
    };
    // ── 齒型模式：外齒 / 內齒 ──────────────────────────────────────────────
    var _gearInternal = false;
    function gSetText(id, t){ var el = document.getElementById(id); if (el) el.textContent = t; }
    window.setGearMode = function(mode){
        _gearInternal = (mode === 'int');
        var be = document.getElementById('g-mode-ext'), bi = document.getElementById('g-mode-int');
        if (be) be.classList.toggle('active', !_gearInternal);
        if (bi) bi.classList.toggle('active', _gearInternal);
        gSetText('go-da-lbl', _gearInternal ? '小徑 di（齒頂）' : '外徑 da');
        gSetText('go-df-lbl', _gearInternal ? '大徑 Df（齒根）' : '齒根圓 df');
        gSetText('go-dp-lbl', _gearInternal ? '使用測棒 dp（跨銷徑）' : '使用球徑 dp');
        gSetText('go-m-lbl',  _gearInternal ? '跨銷值 M（內量 dc−dp）' : '標準跨珠值 M');
        gSetText('go-m-title',_gearInternal ? '跨銷值 M（內齒）' : '跨珠值 M');
        gSetText('go-rechob-m-range-lbl', _gearInternal ? '跨銷值 M 下/上限' : '建議滾齒 M 下/上限（依標準跨珠值 M 換算）');
        gSetText('go-rechob-wk-lbl', '建議滾齒跨齒厚（依標準）');
        var _rlEl = document.getElementById('go-rechob-wk-lbl'); if (_rlEl) _rlEl.classList.remove('lbl-cust');
        var blk = document.getElementById('go-block-wk'); if (blk) blk.style.display = _gearInternal ? 'none' : '';
        var rm  = document.getElementById('go-row-rechob-m'); if (rm) rm.style.display = _gearInternal ? 'none' : '';
        ['go-mt','go-d','go-da','go-df','go-h','go-k','go-wk','go-wk-bmin','go-rechob-wk','go-allow-info','go-dp-used','go-m','go-rechob-m','go-rechob-m-range','go-cust-wk-range'].forEach(function(id){ setOut(id,''); });
        // 客戶數據輸出：換齒型後屬舊模式結果，一併隱藏
        var _cwRow = document.getElementById('go-row-cust-wk'); if (_cwRow) _cwRow.style.display = 'none';
        var _cxOut = document.getElementById('g-cust-x-out'); if (_cxOut) _cxOut.style.display = 'none';
        ['go-row-cust-m','go-cust-m-note','go-row-cust-rh-wk','go-row-cust-rh-m','go-row-cust-rh-range'].forEach(function(id){
            var e = document.getElementById(id); if (e) e.style.display = 'none';
        });
        ['g-cust-x-up','g-cust-x-dn','g-cust-x-mid'].forEach(function(id){ gSetText(id,'—'); });
        showWarn('g-m1-warn','');
    };
    // 內齒基本計算：使用跨銷徑/跨銷值（M = dc − dp），不計跨齒厚與建議滾齒
    function calcGearM1Internal(mn, z, an_dec, alpha_n, beta, x_in, dp_in, mtol_up, mtol_dn){
        var cos_b = Math.cos(beta);
        var mt = mn / cos_b;
        var d  = mt * z;
        var di = d - 2 * mn * (1 + x_in);       // 小徑（齒頂）
        var Df = d + 2 * mn * (1.25 - x_in);    // 大徑（齒根）
        var alpha_t = Math.atan(Math.tan(alpha_n) / cos_b);
        var db = d * Math.cos(alpha_t);
        var h  = (Df - di) / 2;
        var dp_used = (dp_in && dp_in > 0) ? dp_in : gRound(1.68 * mn, 2);

        var warns = [];
        if (di <= 0) warns.push('小徑 di ≤ 0，請減小轉位係數或增加齒數');
        if (di > 0 && di <= db) warns.push('小徑 di(' + fmtNum(di,4) + ') ≤ 基圓 db(' + fmtNum(db,4) + ')，壓力角需增大或轉位係數需減小');
        if (x_in < -2 || x_in > 5) warns.push('轉位係數 x = ' + x_in + ' 超出一般範圍 (-2~5)');

        var M_int = (di > db) ? calcMInt(x_in, mn, z, alpha_n, beta, dp_used) : '異常(小徑≤基圓)';
        var M_up = null, M_dn = null;
        if (typeof M_int === 'number') { M_up = gRound(M_int + mtol_up, 5); M_dn = gRound(M_int + mtol_dn, 5); }
        else warns.push('M 值計算異常：' + M_int);

        _baseParams = { mn:mn, z:z, alpha_n:alpha_n, beta:beta, an_dec:an_dec, alpha_t:alpha_t,
                        inv_alpha_t: inv(alpha_t), k:0, Wk:null, Wk_actual:null, da:di, dp:dp_used, x:x_in, internal:true };

        setOut('go-mt', fmtNum(mt,5));
        setOut('go-d',  fmtNum(d,4));
        setOut('go-da', fmtNum(di,4));
        setOut('go-df', fmtNum(Df,4));
        setOut('go-h',  fmtNum(h,4));
        setOut('go-dp-used', fmtNum(dp_used,2));
        setOut('go-m', typeof M_int === 'number' ? fmtNum(M_int,5) : M_int, typeof M_int === 'number' ? 'val-ok' : 'val-err');
        setOut('go-rechob-m-range', (M_up !== null) ? (fmtNum(M_dn,5) + '  ~  ' + fmtNum(M_up,5)) : '—', M_up !== null ? 'val-ok' : '');

        showWarn('g-m1-warn', warns.length ? warns.join('；') : '');

        // 客戶提供 M（選填）→ 右側顯示換算為我方測棒 dp 後的跨銷值上/下限
        var _cbI = readCustBackX(mn, z, alpha_n, beta);
        if (_cbI && !_cbI.err) showCustMineM(_cbI, mn, z, alpha_n, beta, dp_used);
        else hideCustOutputs();

        ['m2','m3','m4','m5','rx'].forEach(function(t){ var b = document.getElementById('gtab-'+t); if (b) b.disabled = false; });
        var ico = document.getElementById('gear-hdr-icon'); if (ico) { ico.className = 'fa fa-cog'; setTimeout(function(){ ico.className = 'fa fa-cog fa-spin'; }, 200); }
    }

    window.calcGearM1 = function() {
        var mn = gearMnToM(), z = gInt('g-z');  // CP/DP 已換算為 M
        if (!mn || !z || mn <= 0 || z < 2) { alert('請填寫法向模數 mn 和齒數 z（z≥2）'); return; }

        // 法向壓力角（空白預設 20°）
        var an_raw = parseFloat(gVal('g-an'));
        var an_dec = (!isNaN(an_raw) && an_raw > 0) ? an_raw : 20;
        if (an_dec >= 90) { alert('法向壓力角需小於 90°'); return; }
        // 若為空白補回預設值
        var anEl = document.getElementById('g-an');
        if (anEl && (anEl.value === '' || isNaN(parseFloat(anEl.value)))) {
            anEl.value = 20;
            setAlphaN(20);
        }
        var alpha_n = an_dec * PI / 180;

        // 螺旋角（整數/小數 或 度分秒）
        var beta_dec = 0;
        var btMode = document.getElementById('g-bt-mode-dms');
        if (btMode && btMode.checked) {
            var bt_d = parseFloat(gVal('g-bt-d')) || 0;
            var bt_m = parseFloat(gVal('g-bt-m')) || 0;
            var bt_s = parseFloat(gVal('g-bt-s')) || 0;
            beta_dec = dmsToDecDeg(bt_d, bt_m, bt_s);
        } else {
            beta_dec = parseFloat(gVal('g-bt')) || 0;
        }
        var beta = beta_dec * PI / 180;

        // 客戶提供 M 上下限（選填）→ 自動回推轉位並帶入轉位係數 x（外/內齒共用）
        var _cb = readCustBackX(mn, z, alpha_n, beta);
        if (_cb && _cb.err) {
            showWarn('g-cust-warn', _cb.err); hideCustOutputs();
        } else if (_cb) {
            showCustBackXBreakdown(_cb);
            showWarn('g-cust-warn', (_cb.x_mid <= -2 + 1e-6 || _cb.x_mid >= 5 - 1e-6)
                ? '回推 x 逼近求解邊界 (−2~5)，請確認 M 值、dp 與齒型（外齒/內齒）是否正確' : '');
            var _gx = document.getElementById('g-x');
            if (_gx) _gx.value = fmtNum(gRound(_cb.x_mid, 5), 5);
        } else {
            hideCustOutputs(); showWarn('g-cust-warn', '');
        }

        var x_in   = gFloat('g-x') !== null ? gFloat('g-x') : 0;  // 空白預設 0
        var tol_up = gFloat('g-tol-up') !== null ? gFloat('g-tol-up') : -0.02;
        var k_in   = gInt('g-k-in');
        var dp_in  = gFloat('g-dp');
        var mtol_up = gFloat('g-mtol-up') !== null ? gFloat('g-mtol-up') : 0.02;
        var mtol_dn = gFloat('g-mtol-dn') !== null ? gFloat('g-mtol-dn') : -0.02;

        // 內齒模式：改走內齒計算（跨銷徑/跨銷值），不計跨齒厚與建議滾齒
        if (_gearInternal) { calcGearM1Internal(mn, z, an_dec, alpha_n, beta, x_in, dp_in, mtol_up, mtol_dn); return; }

        // 基礎幾何
        var cos_b  = Math.cos(beta);
        var mt     = mn / cos_b;
        var d      = mt * z;
        var da     = d + 2 * mn * (1 + x_in);
        var df     = d - 2 * mn * (1.25 - x_in);
        var h      = 2.25 * mn;
        var alpha_t   = Math.atan(Math.tan(alpha_n) / cos_b);
        var inv_alpha_t = inv(alpha_t);

        // 自動推算跨齒數 k：標準公式 k = round(z·αn/180 + 0.5)，以實際齒數與法向壓力角計算
        // （直齒 β=0 時與虛擬齒數公式結果相同；螺旋角大時改用虛擬齒數 z/cos³β 會高估 k，
        //   使量測平面過斜、量趾點跑到齒頂而不可量，故一律採實際齒數版本，與外部齒輪軟體一致）
        var k_val;
        if (k_in && k_in >= 1) {
            k_val = k_in;
        } else {
            k_val = Math.round(z * an_dec / 180 + 0.5);
            if (k_val < 1) k_val = 1;
        }

        // 理論跨齒厚 Wk
        var Wk = mn * Math.cos(alpha_n) * (PI * (k_val - 0.5) + z * inv_alpha_t)
               + 2 * x_in * mn * Math.sin(alpha_n);

        // 修正 Wk（考慮上公差偏移）
        var wk_offset = tol_up - (-0.02);
        var Wk_actual = Wk + wk_offset;

        // 球徑
        var dp_used = (dp_in && dp_in > 0) ? dp_in : gRound(1.68 * mn, 2);

        // 標準 M
        var M_std = calcM(x_in, mn, z, alpha_n, beta, dp_used);

        // 查預留量表
        var mn_res = lookupMn(mn);
        var da_res = lookupDa(da);
        var warns = [];
        var allow_val = 0, allow_src = '（未查到）', is_boss = false;

        if (!mn_res && !da_res) {
            warns.push('查不到模數或外徑對應的預留量，請至「預留量管理」新增資料');
        } else {
            var mn_allow = mn_res ? mn_res.allow : -Infinity;
            var da_allow = da_res ? da_res.allow : -Infinity;
            if (da_res && da_res.boss) {
                allow_val = 0; allow_src = '外徑超大（需詢問BOSS）'; is_boss = true;
            } else {
                allow_val = Math.max(mn_allow, da_allow);
                var which = (da_allow >= mn_allow && da_res) ? '外徑' : '模數';
                allow_src = '預留 ' + fmtNum(allow_val, 4) + '（' + which + '預留較大）';
            }
        }

        // 客戶提供跨齒厚（選填）：有填客戶跨齒厚 Wk 或公差才視為有效客戶規格
        // 基準值：有填「客戶跨齒厚 Wk」用之，否則用理論跨齒厚 Wk
        var cwu = gFloat('g-cust-wtol-up'), cwd = gFloat('g-cust-wtol-dn');
        var custWkNom = gFloat('g-cust-wk');
        var custWkBase = (custWkNom !== null) ? custWkNom : Wk;
        var custWkHasData = (custWkNom !== null || cwu !== null || cwd !== null);
        // 客戶跨齒厚上限（有客戶上公差或客戶 Wk 公稱才算得出上限；僅填下公差則無上限可用）
        var custWkUpper = (cwu !== null) ? gRound(custWkBase + cwu, 5) : (custWkNom !== null ? custWkNom : null);
        // 有客戶跨齒厚上限時，建議滾齒跨齒厚改依客戶規格；否則沿用標準理論值
        var useCustRechob = (custWkUpper !== null);
        gSetText('go-rechob-wk-lbl', useCustRechob ? '建議滾齒跨齒厚（依客戶）' : '建議滾齒跨齒厚（依標準）');
        var rechobLblEl = document.getElementById('go-rechob-wk-lbl');
        if (rechobLblEl) rechobLblEl.classList.toggle('lbl-cust', useCustRechob);

        var rec_hob_Wk = null, x_rec = null, M_rec = null, M_rec_up = null, M_rec_dn = null;
        if (!is_boss && allow_val > 0) {
            if (useCustRechob) {
                // 客戶跨齒厚上限 → 加上公差偏移(0−(−0.02)=0.02，與 showCustRecHob 客戶M上限路徑同一套慣例)→ 加預留量
                rec_hob_Wk = gRound(custWkUpper + 0.02 + allow_val, 5);
            } else {
                rec_hob_Wk = gRound(Wk_actual + allow_val, 5);
            }
            var A = mn * Math.cos(alpha_n) * (PI * (k_val - 0.5) + z * inv_alpha_t);
            var B = 2 * mn * Math.sin(alpha_n);
            if (B !== 0) {
                x_rec = (rec_hob_Wk - A) / B;
                M_rec    = calcM(x_rec, mn, z, alpha_n, beta, dp_used);
                // 建議滾齒 M 上/下限 = 把建議滾齒 Wk±(M公差當作跨齒厚公差帶) 換算成跨珠值
                // （dM/dWk≠1，不可直接把公差加在 M 上）
                M_rec_up = wkToM(rec_hob_Wk + mtol_up, mn, z, alpha_n, beta, inv_alpha_t, k_val, dp_used);
                M_rec_dn = wkToM(rec_hob_Wk + mtol_dn, mn, z, alpha_n, beta, inv_alpha_t, k_val, dp_used);
            }
        }

        if (x_in < -2 || x_in > 5) warns.push('轉位係數 x = ' + x_in + ' 超出一般範圍 (-2~5)，請確認');

        // 儲存共享參數
        _baseParams = { mn: mn, z: z, alpha_n: alpha_n, beta: beta, an_dec: an_dec,
                        alpha_t: alpha_t, inv_alpha_t: inv_alpha_t, k: k_val,
                        Wk: Wk, Wk_actual: Wk_actual, da: da, dp: dp_used, x: x_in };

        // 輸出
        setOut('go-mt', fmtNum(mt, 5));
        setOut('go-d',  fmtNum(d,  4));
        setOut('go-da', fmtNum(da, 4));
        setOut('go-df', fmtNum(df, 4));
        setOut('go-h',  fmtNum(h,  4));
        setOut('go-k',  k_val);
        setOut('go-wk', fmtNum(Wk, 5));
        // 最小可量測齒寬（工件厚度）：螺旋齒量跨齒厚時，量測面沿基圓螺旋角傾斜，
        // 兩量測點在軸向偏移 b_min = Wk·sin(βb)，βb=基圓螺旋角（sinβb=sinβ·cosαn）。
        // 工件齒寬需大於此值才量得到；直齒(β=0)不受此限。實務再加量測餘裕。
        var sin_bb = Math.sin(beta) * Math.cos(alpha_n);
        var b_min  = Math.abs(Wk * sin_bb);
        if (Math.abs(beta) < 1e-9) {
            setOut('go-wk-bmin', '直齒不受此限', 'val-ok');
        } else {
            setOut('go-wk-bmin', '≥ ' + fmtNum(gRound(b_min, 3), 3) + ' mm', 'val-ok');
        }
        // 客戶提供跨齒厚（選填）：有填客戶跨齒厚 Wk 或公差才顯示上下限列（cwu/cwd/custWkNom/custWkUpper 已於上方讀取）
        var custWkRow = document.getElementById('go-row-cust-wk');
        if (custWkRow) {
            if (custWkHasData) {
                custWkRow.style.display = '';
                setOut('go-cust-wk-range',
                    (cwd !== null ? fmtNum(gRound(custWkBase + cwd, 5), 5) : (custWkNom !== null ? fmtNum(custWkNom,5) : '—')) + '  ~  ' +
                    (custWkUpper !== null ? fmtNum(custWkUpper, 5) : '—'), 'val-ok');
            } else {
                custWkRow.style.display = 'none';
            }
        }
        setOut('go-dp-used', fmtNum(dp_used, 2));
        setOut('go-allow-info', allow_src, is_boss ? 'val-boss' : '');

        if (is_boss) {
            setOut('go-rechob-wk', '需詢問BOSS', 'val-boss');
            setOut('go-m',     typeof M_std === 'string' ? M_std : fmtNum(M_std, 5), typeof M_std === 'string' ? 'val-err' : 'val-ok');
            setOut('go-rechob-m', '需詢問BOSS', 'val-boss');
            setOut('go-rechob-m-range', '需詢問BOSS', 'val-boss');
        } else {
            setOut('go-rechob-wk', allow_val > 0 ? fmtNum(rec_hob_Wk, 5) : '（待查表）', allow_val > 0 ? (useCustRechob ? 'val-cust' : 'val-ok') : 'val-warn');
            setOut('go-m',     typeof M_std === 'string' ? M_std : fmtNum(M_std, 5), typeof M_std === 'string' ? 'val-err' : 'val-ok');
            if (M_rec !== null && typeof M_rec === 'number') {
                setOut('go-rechob-m', fmtNum(M_rec, 5), 'val-ok');
                setOut('go-rechob-m-range',
                    (typeof M_rec_dn === 'string' ? M_rec_dn : fmtNum(M_rec_dn, 5)) + '  ~  ' +
                    (typeof M_rec_up === 'string' ? M_rec_up : fmtNum(M_rec_up, 5)));
            } else if (M_rec !== null) {
                setOut('go-rechob-m', String(M_rec), 'val-err');
                setOut('go-rechob-m-range', '—');
            } else {
                setOut('go-rechob-m', '（待查表）', 'val-warn');
                setOut('go-rechob-m-range', '—');
            }
        }

        showWarn('g-m1-warn', warns.length ? warns.join('；') : '');

        // 客戶提供 M（選填）→ 右側顯示換算為我方球徑後的 M 上/下限，及依客戶規格計算的建議滾齒尺寸
        if (_cb && !_cb.err) {
            showCustMineM(_cb, mn, z, alpha_n, beta, dp_used);
            showCustRecHob(_cb, mn, z, alpha_n, beta, inv_alpha_t, k_val, dp_used, allow_val, is_boss, mtol_up, mtol_dn);
        } else hideCustOutputs();

        // 啟用 M2~M4 分頁
        ['m2','m3','m4','m5','rx'].forEach(function(t) {
            var btn = document.getElementById('gtab-'+t);
            if (btn) btn.disabled = false;
        });
        // 旋轉圖示停止
        var ico = document.getElementById('gear-hdr-icon');
        if (ico) ico.className = 'fa fa-cog';
        setTimeout(function(){ if(ico) ico.className='fa fa-cog fa-spin'; },200);
    };

    // ── 客戶提供數據：由跨銷徑/球徑與 M 上下限回推我方轉位 x ────────────────────
    //    回傳 null=未填 M；{err}=輸入有誤；否則含 x_up/x_dn/x_mid 與客戶球徑 custDp
    function readCustBackX(mn, z, alpha_n, beta) {
        var M_up = gFloat('g-cust-m-up'), M_dn = gFloat('g-cust-m-dn');
        if (M_up === null && M_dn === null) return null;
        if (M_up !== null && M_dn !== null && M_dn > M_up) return { err: '客戶 M 下限大於上限，請確認輸入' };
        var dp_c = gFloat('g-cust-dp');
        var custDp = (dp_c && dp_c > 0) ? dp_c : gRound(1.68 * mn, 2);
        var solve = _gearInternal ? solveXfromMInt : solveXfromM;
        var x_up = (M_up !== null) ? solve(M_up, mn, z, alpha_n, beta, custDp) : null;
        var x_dn = (M_dn !== null) ? solve(M_dn, mn, z, alpha_n, beta, custDp) : null;
        var x_mid = (x_up !== null && x_dn !== null)
            ? solve((M_up + M_dn) / 2, mn, z, alpha_n, beta, custDp)
            : (x_up !== null ? x_up : x_dn);
        return { M_up:M_up, M_dn:M_dn, custDp:custDp, x_up:x_up, x_dn:x_dn, x_mid:x_mid };
    }
    // 顯示「回推 x 上/下/中值」明細
    function showCustBackXBreakdown(cb) {
        gSetText('g-cust-x-up', cb.x_up !== null ? fmtNum(gRound(cb.x_up, 5), 5) : '—');
        gSetText('g-cust-x-dn', cb.x_dn !== null ? fmtNum(gRound(cb.x_dn, 5), 5) : '—');
        gSetText('g-cust-x-mid', fmtNum(gRound(cb.x_mid, 5), 5));
        var box = document.getElementById('g-cust-x-out'); if (box) box.style.display = 'block';
    }
    // 隱藏客戶回推明細與右側換算列（含客戶規格建議滾齒列）
    function hideCustOutputs() {
        ['g-cust-x-out','go-row-cust-m','go-cust-m-note',
         'go-row-cust-rh-wk','go-row-cust-rh-m','go-row-cust-rh-range'].forEach(function(id){
            var e = document.getElementById(id); if (e) e.style.display = 'none';
        });
    }
    // 右側顯示「客戶規格 建議滾齒」：依客戶 M 上限 → 我方跨齒厚 + 預留量 → 我方球徑 M（外齒專用，邏輯同 M5/基本計算）
    //    參數 allowVal/isBoss 由基本計算的預留量查表結果帶入；k、dp 皆用我方（基本計算）值
    function showCustRecHob(cb, mn, z, alpha_n, beta, inv_alpha_t, k, ourDp, allowVal, isBoss, mtolUp, mtolDn) {
        var ids = ['go-row-cust-rh-wk','go-row-cust-rh-m','go-row-cust-rh-range'];
        // 內齒無「建議滾齒（跨齒厚）」概念 → 不顯示
        if (_gearInternal) { ids.forEach(function(i){ var e=document.getElementById(i); if(e) e.style.display='none'; }); return; }
        // 以客戶 M 上限回推的 x 為基準（無上限則退用下限）
        var xBase = (cb.x_up !== null) ? cb.x_up : cb.x_dn;
        var A = mn * Math.cos(alpha_n) * (PI * (k - 0.5) + z * inv_alpha_t);
        var B = 2 * mn * Math.sin(alpha_n);
        if (isBoss) {
            setOut('go-cust-rh-wk', '需詢問BOSS', 'val-boss');
            setOut('go-cust-rh-m', '需詢問BOSS', 'val-boss');
            setOut('go-cust-rh-m-range', '需詢問BOSS', 'val-boss');
        } else if (allowVal > 0 && Math.abs(B) > 1e-10) {
            // 客戶 M 上限 → 我方跨齒厚 Wk(上限) → 加上公差偏移(0−(−0.02)=0.02) → 加預留量
            var Wk_custmax = A + B * xBase;
            var rh_Wk = gRound(Wk_custmax + 0.02 + allowVal, 5);
            var x_rh = (rh_Wk - A) / B;
            var M_rh = calcM(x_rh, mn, z, alpha_n, beta, ourDp);  // 我方球徑
            setOut('go-cust-rh-wk', fmtNum(rh_Wk, 5), '');
            var wkEl = document.getElementById('go-cust-rh-wk'); if (wkEl) wkEl.style.color = '#6a1b9a';
            if (typeof M_rh === 'number') {
                setOut('go-cust-rh-m', fmtNum(M_rh, 5), 'val-ok');
                // M 上/下限 = 建議滾齒 Wk±(M公差當跨齒厚公差帶) 換算為跨珠值（非 M±公差）
                setOut('go-cust-rh-m-range',
                    wkTolToMRange(rh_Wk, mn, z, alpha_n, beta, inv_alpha_t, k, ourDp, mtolUp, mtolDn), 'val-ok');
            } else {
                setOut('go-cust-rh-m', String(M_rh), 'val-err');
                setOut('go-cust-rh-m-range', '—');
            }
            var mEl = document.getElementById('go-cust-rh-m'); if (mEl) mEl.style.color = '#6a1b9a';
            var rEl = document.getElementById('go-cust-rh-m-range'); if (rEl) rEl.style.color = '#6a1b9a';
        } else {
            setOut('go-cust-rh-wk', '（待查表）', 'val-warn');
            setOut('go-cust-rh-m', '（待查表）', 'val-warn');
            setOut('go-cust-rh-m-range', '—');
        }
        ids.forEach(function(i){ var e=document.getElementById(i); if(e) e.style.display=''; });
    }
    // 右側顯示「客戶規格→我方球徑 M 上/下限」：以客戶回推 x 套用我方球徑重算 M
    function showCustMineM(cb, mn, z, alpha_n, beta, ourDp) {
        var mfn = _gearInternal ? calcMInt : calcM;
        var Mup = (cb.x_up !== null) ? mfn(cb.x_up, mn, z, alpha_n, beta, ourDp) : null;
        var Mdn = (cb.x_dn !== null) ? mfn(cb.x_dn, mn, z, alpha_n, beta, ourDp) : null;
        var upStr = (Mup === null) ? '—' : (typeof Mup === 'string' ? Mup : fmtNum(Mup, 5));
        var dnStr = (Mdn === null) ? '—' : (typeof Mdn === 'string' ? Mdn : fmtNum(Mdn, 5));
        setOut('go-cust-m-range', dnStr + '  ~  ' + upStr, 'val-ok');
        var el = document.getElementById('go-cust-m-range'); if (el) el.style.color = '#6a1b9a';
        var row = document.getElementById('go-row-cust-m'); if (row) row.style.display = '';
        var note = document.getElementById('go-cust-m-note');
        if (note) {
            note.innerHTML = '客戶規格→我方球徑：依客戶球徑 dp=' + fmtNum(cb.custDp, 3) + ' 回推轉位 → 換用我方球徑 dp='
                + fmtNum(ourDp, 3) + ' 的' + (_gearInternal ? '跨銷' : '跨珠') + '值。'
                + (_gearInternal ? '' : '<br>客戶規格 建議滾齒：依客戶 M 上限 → 我方跨齒厚 + 預留量（模數/外徑取大）→ 我方球徑 M；M 公差沿用上方設定。');
            note.style.display = 'block';
        }
    }

    // ── 客戶提供數據：由跨銷徑/球徑與 M 上下限回推轉位係數 x，代入後自動計算 ──
    //    計算齒輪本身已會自動回推帶入，此按鈕為便捷入口（先驗證有填 M 再觸發計算）
    window.calcCustM2X = function() {
        if (gFloat('g-cust-m-up') === null && gFloat('g-cust-m-dn') === null) {
            alert('請填寫客戶 M 上限或下限（至少一項）'); return;
        }
        calcGearM1();  // calcGearM1 內含自動回推轉位、帶入 x、右側顯示我方球徑 M 換算
    };

    // ══ 模組二：跨齒厚 → 跨珠值 ════════════════════════════════════════════
    window.calcGearM2 = function() {
        if (!_baseParams) { alert('請先執行基本計算'); return; }
        var p = _baseParams;
        var k2   = gInt('m2-k'), Wk2 = gFloat('m2-wk');
        if (!k2 || Wk2 === null) { alert('請填寫跨幾齒和跨齒厚公稱值'); return; }
        var tu  = gFloat('m2-tol-up') !== null ? gFloat('m2-tol-up') : 0;
        var td  = gFloat('m2-tol-dn') !== null ? gFloat('m2-tol-dn') : 0;
        // 球徑：m2-dp 有填用之，否則沿用基本計算的 dp
        var dp2 = gFloat('m2-dp') !== null && gFloat('m2-dp') > 0 ? gFloat('m2-dp') : p.dp;

        var A = p.mn * Math.cos(p.alpha_n) * (PI * (k2 - 0.5) + p.z * p.inv_alpha_t);
        var B = 2 * p.mn * Math.sin(p.alpha_n);
        if (Math.abs(B) < 1e-10) { showWarn('g-m2-warn','法向壓力角為0°，無法計算'); return; }

        var x_max = ((Wk2 + tu) - A) / B;
        var x_min = ((Wk2 + td) - A) / B;

        var warns = [];
        if (x_max > 5 || x_min < -2) warns.push('轉位係數超出一般範圍 (-2~5)，請確認輸入值');

        var M_up  = calcM(x_max, p.mn, p.z, p.alpha_n, p.beta, dp2);
        var M_dn  = calcM(x_min, p.mn, p.z, p.alpha_n, p.beta, dp2);
        var Wk_uc = gRound(A + B * x_max, 5);
        var Wk_dc = gRound(A + B * x_min, 5);

        setOut('m2-m-up',     typeof M_up === 'string' ? M_up : fmtNum(M_up, 5), typeof M_up === 'string' ? 'val-err' : 'val-ok');
        setOut('m2-m-dn',     typeof M_dn === 'string' ? M_dn : fmtNum(M_dn, 5), typeof M_dn === 'string' ? 'val-err' : 'val-ok');
        setOut('m2-wk-up-chk', fmtNum(Wk_uc, 5));
        setOut('m2-wk-dn-chk', fmtNum(Wk_dc, 5));
        showWarn('g-m2-warn', warns.length ? warns.join('；') : '');
    };

    // ══ 模組三：跨珠值 → 跨齒厚 ════════════════════════════════════════════
    window.calcGearM3 = function() {
        if (!_baseParams) { alert('請先執行基本計算'); return; }
        var p = _baseParams;
        var dp3 = gFloat('m3-dp'), M3 = gFloat('m3-m');
        if (!dp3 || dp3 <= 0 || M3 === null) { alert('請填寫球徑和跨珠值公稱'); return; }
        var tu = gFloat('m3-tol-up') !== null ? gFloat('m3-tol-up') : 0.02;
        var td = gFloat('m3-tol-dn') !== null ? gFloat('m3-tol-dn') : -0.02;
        var k3_in = gInt('m3-k-in');
        var k3 = (k3_in && k3_in >= 1) ? k3_in : p.k;

        var x_nom = solveXfromM(M3,      p.mn, p.z, p.alpha_n, p.beta, dp3);
        var x_up  = solveXfromM(M3 + tu, p.mn, p.z, p.alpha_n, p.beta, dp3);
        var x_dn  = solveXfromM(M3 + td, p.mn, p.z, p.alpha_n, p.beta, dp3);

        var A = p.mn * Math.cos(p.alpha_n) * (PI * (k3 - 0.5) + p.z * p.inv_alpha_t);
        var B = 2 * p.mn * Math.sin(p.alpha_n);
        var Wk_nom = gRound(A + B * x_nom, 5);
        var Wk_up  = gRound(A + B * x_up,  5);
        var Wk_dn  = gRound(A + B * x_dn,  5);
        var tol_up_out = gRound(Wk_up - Wk_nom, 5);
        var tol_dn_out = gRound(Wk_dn - Wk_nom, 5);

        setOut('m3-k-out', k3);
        setOut('m3-x',     fmtNum(x_nom, 6));
        setOut('m3-wk',    fmtNum(Wk_nom, 5), 'val-ok');
        var tolStr = (tol_up_out >= 0 ? '+' : '') + fmtNum(tol_up_out, 5)
                   + '  ~  ' + (tol_dn_out >= 0 ? '+' : '') + fmtNum(tol_dn_out, 5);
        setOut('m3-wk-tol', tolStr);
        setOut('m3-wk-up', fmtNum(Wk_up, 5));
        setOut('m3-wk-dn', fmtNum(Wk_dn, 5));
        showWarn('g-m3-warn', '');
    };

    // ══ 模組四：客戶跨齒 → 建議滾齒 ════════════════════════════════════════
    window.calcGearM4 = function() {
        if (!_baseParams) { alert('請先執行基本計算'); return; }
        var p = _baseParams;
        var k4 = gInt('m4-k'), Wk4 = gFloat('m4-wk');
        if (!k4 || Wk4 === null) { alert('請填寫客戶跨幾齒和跨齒厚公稱值'); return; }
        var tu4 = gFloat('m4-tol-up') !== null ? gFloat('m4-tol-up') : 0;
        var td4 = gFloat('m4-tol-dn') !== null ? gFloat('m4-tol-dn') : 0;
        var dp4 = gFloat('m4-dp') !== null ? gFloat('m4-dp') : gRound(1.68 * p.mn, 2);
        var mtup4 = gFloat('m4-mtol-up') !== null ? gFloat('m4-mtol-up') : 0.02;
        var mtdn4 = gFloat('m4-mtol-dn') !== null ? gFloat('m4-mtol-dn') : -0.02;

        var A = p.mn * Math.cos(p.alpha_n) * (PI * (k4 - 0.5) + p.z * p.inv_alpha_t);
        var B = 2 * p.mn * Math.sin(p.alpha_n);
        if (Math.abs(B) < 1e-10) { showWarn('g-m4-warn','法向壓力角為0°，無法計算'); return; }

        var x_up4 = ((Wk4 + tu4) - A) / B;
        var x_dn4 = ((Wk4 + td4) - A) / B;
        var Wk_cust_up = gRound(A + B * x_up4, 5);

        // 與 M1 相同的 wk_offset 邏輯：以公稱 Wk + (上公差 − (−0.02)) 為起算點
        // tu4=−0.02（標準）時 offset=0，從公稱 Wk4 起算，與 M1 一致
        var wk_offset4 = tu4 - (-0.02);
        var Wk_actual4 = gRound(Wk4 + wk_offset4, 5);

        // 查預留量
        var mn_res = lookupMn(p.mn);
        var da_res = lookupDa(p.da);
        var allow4 = 0, src4 = '（未查到預留量）', boss4 = false;
        if (da_res && da_res.boss) {
            boss4 = true; src4 = '外徑超大（需詢問BOSS）';
        } else {
            var mna4 = mn_res ? mn_res.allow : -Infinity;
            var daa4 = da_res ? da_res.allow : -Infinity;
            allow4 = Math.max(mna4, daa4);
            if (allow4 > 0) {
                var which4 = (daa4 >= mna4 && da_res) ? '外徑' : '模數';
                src4 = '預留 ' + fmtNum(allow4, 4) + '（' + which4 + '預留較大）';
            }
        }

        var rec4_Wk = null, M_rec4 = null, M_rec4_up = null, M_rec4_dn = null;
        var M_cust_up = calcM(x_up4, p.mn, p.z, p.alpha_n, p.beta, dp4);
        var M_cust_dn = calcM(x_dn4, p.mn, p.z, p.alpha_n, p.beta, dp4);

        if (!boss4 && allow4 > 0) {
            rec4_Wk  = gRound(Wk_actual4 + allow4, 5);
            var x4_rec = (rec4_Wk - A) / B;
            M_rec4    = calcM(x4_rec, p.mn, p.z, p.alpha_n, p.beta, dp4);
            if (typeof M_rec4 === 'number') {
                // 上/下限 = 建議滾齒 Wk±(M公差當跨齒厚公差帶) 換算為 M（非 M±公差）
                M_rec4_up = wkToM(rec4_Wk + mtup4, p.mn, p.z, p.alpha_n, p.beta, p.inv_alpha_t, k4, dp4);
                M_rec4_dn = wkToM(rec4_Wk + mtdn4, p.mn, p.z, p.alpha_n, p.beta, p.inv_alpha_t, k4, dp4);
            }
        }

        setOut('m4-dp-used', fmtNum(dp4, 4) + ' mm');
        setOut('m4-allow-info', src4, boss4 ? 'val-boss' : '');
        setOut('m4-rechob-wk', boss4 ? '需詢問BOSS' : (rec4_Wk !== null ? fmtNum(rec4_Wk, 5) : '（待查表）'), boss4 ? 'val-boss' : 'val-ok');
        setOut('m4-rechob-m',  boss4 ? '需詢問BOSS' : (M_rec4 !== null && typeof M_rec4 === 'number' ? fmtNum(M_rec4, 5) : (M_rec4 || '（待查表）')), boss4 ? 'val-boss' : 'val-ok');
        setOut('m4-rechob-m-range', (M_rec4_up !== null) ? ((typeof M_rec4_dn==='string'?M_rec4_dn:fmtNum(M_rec4_dn,5)) + '  ~  ' + (typeof M_rec4_up==='string'?M_rec4_up:fmtNum(M_rec4_up,5))) : '—');
        setOut('m4-cust-m-up', typeof M_cust_up === 'string' ? M_cust_up : fmtNum(M_cust_up, 5), typeof M_cust_up === 'string' ? 'val-err' : '');
        setOut('m4-cust-m-dn', typeof M_cust_dn === 'string' ? M_cust_dn : fmtNum(M_cust_dn, 5), typeof M_cust_dn === 'string' ? 'val-err' : '');
        var dpLbl = document.getElementById('m4-cust-m-dp-label');
        if (dpLbl) dpLbl.textContent = '（dp = ' + fmtNum(dp4, 4) + ' mm）';
        showWarn('g-m4-warn', '');
    };

    // ══ 模組五：跨珠換算 ════════════════════════════════════════════════════
    window.calcGearM5 = function() {
        if (!_baseParams) { alert('請先執行基本計算'); return; }
        var p = _baseParams;
        var dp_c = gFloat('m5-dp-cust'), dp_m = gFloat('m5-dp-mine');
        var M_up = gFloat('m5-m-up'),   M_dn  = gFloat('m5-m-dn');
        if (!dp_c || dp_c <= 0) { alert('請填寫客戶球徑'); return; }
        if (!dp_m || dp_m <= 0) { alert('請填寫我方球徑'); return; }
        if (M_up === null || M_dn === null) { alert('請填寫客戶 M 上限與下限'); return; }

        // 由客戶 M 上/下限分別反推 x（以客戶球徑）
        var x_up = solveXfromM(M_up, p.mn, p.z, p.alpha_n, p.beta, dp_c);
        var x_dn = solveXfromM(M_dn, p.mn, p.z, p.alpha_n, p.beta, dp_c);

        // 以我方球徑計算對應 M 值
        var M_mine_up = calcM(x_up, p.mn, p.z, p.alpha_n, p.beta, dp_m);
        var M_mine_dn = calcM(x_dn, p.mn, p.z, p.alpha_n, p.beta, dp_m);

        // 驗算：以反推 x 重算客戶 M（確認反推正確）
        var M_cust_up_chk = calcM(x_up, p.mn, p.z, p.alpha_n, p.beta, dp_c);
        var M_cust_dn_chk = calcM(x_dn, p.mn, p.z, p.alpha_n, p.beta, dp_c);

        setOut('m5-out-dp-mine', fmtNum(dp_m, 4) + ' mm');
        setOut('m5-m-mine-up',  typeof M_mine_up === 'string' ? M_mine_up : fmtNum(M_mine_up, 5), typeof M_mine_up === 'string' ? 'val-err' : 'val-ok');
        setOut('m5-m-mine-dn',  typeof M_mine_dn === 'string' ? M_mine_dn : fmtNum(M_mine_dn, 5), typeof M_mine_dn === 'string' ? 'val-err' : 'val-ok');
        // 顯示兩個反推 x 值（供驗算）
        var xUpEl = document.getElementById('m5-x-up'); if (xUpEl) xUpEl.textContent = fmtNum(x_up, 6);
        var xDnEl = document.getElementById('m5-x-dn'); if (xDnEl) xDnEl.textContent = fmtNum(x_dn, 6);
        setOut('m5-out-dp-cust', fmtNum(dp_c, 4) + ' mm');
        setOut('m5-m-cust-up',  typeof M_cust_up_chk === 'string' ? M_cust_up_chk : fmtNum(M_cust_up_chk, 5));
        setOut('m5-m-cust-dn',  typeof M_cust_dn_chk === 'string' ? M_cust_dn_chk : fmtNum(M_cust_dn_chk, 5));

        // ── 建議滾齒尺寸：依客戶 M 上限(上公差=0) → 跨齒厚 → 加上公差偏移(0−(−0.02)=0.02) → 加預留量 → 我方球徑 M ──
        //    與基本計算同一基準：建議滾齒Wk = Wk(上限) + (上公差 − (−0.02)) + 預留量；客戶M上限即上公差=0，偏移固定 +0.02
        var k5 = p.k;
        var A5 = p.mn * Math.cos(p.alpha_n) * (PI * (k5 - 0.5) + p.z * p.inv_alpha_t);
        var B5 = 2 * p.mn * Math.sin(p.alpha_n);
        setOut('m5-rh-k', k5);

        // 查預留量（與基本計算相同：模數/外徑取大）
        var mn_res5 = lookupMn(p.mn);
        var da_res5 = lookupDa(p.da);
        var allow5 = 0, src5 = '（未查到預留量）', boss5 = false;
        if (da_res5 && da_res5.boss) {
            boss5 = true; src5 = '外徑超大（需詢問BOSS）';
        } else {
            var mna5 = mn_res5 ? mn_res5.allow : -Infinity;
            var daa5 = da_res5 ? da_res5.allow : -Infinity;
            allow5 = Math.max(mna5, daa5);
            if (allow5 > 0) {
                var which5 = (daa5 >= mna5 && da_res5) ? '外徑' : '模數';
                src5 = '預留 ' + fmtNum(allow5, 4) + '（' + which5 + '預留較大）';
            }
        }

        // M 公差（沿用基本計算設定，空白=±0.02）
        var mtup5 = gFloat('g-mtol-up') !== null ? gFloat('g-mtol-up') : 0.02;
        var mtdn5 = gFloat('g-mtol-dn') !== null ? gFloat('g-mtol-dn') : -0.02;

        if (boss5) {
            setOut('m5-rh-allow', src5, 'val-boss');
            setOut('m5-rh-wk', '需詢問BOSS', 'val-boss');
            setOut('m5-rh-m', '需詢問BOSS', 'val-boss');
            setOut('m5-rh-m-range', '需詢問BOSS', 'val-boss');
        } else if (allow5 > 0 && Math.abs(B5) > 1e-10) {
            // 客戶 M 上限 → 跨齒厚（以反推 x_up，上公差=0 即直接用客戶 M 上限）
            var Wk_custmax = A5 + B5 * x_up;
            var rh_Wk = gRound(Wk_custmax + 0.02 + allow5, 5);
            var x_rh = (rh_Wk - A5) / B5;
            var M_rh = calcM(x_rh, p.mn, p.z, p.alpha_n, p.beta, dp_m);  // 我方球徑
            setOut('m5-rh-allow', src5, '');
            setOut('m5-rh-wk', fmtNum(rh_Wk, 5), '');
            if (typeof M_rh === 'number') {
                setOut('m5-rh-m', fmtNum(M_rh, 5), 'val-ok');
                // 上/下限 = 建議滾齒 Wk±(M公差當跨齒厚公差帶) 換算為我方球徑 M（非 M±公差）
                setOut('m5-rh-m-range',
                    wkTolToMRange(rh_Wk, p.mn, p.z, p.alpha_n, p.beta, p.inv_alpha_t, k5, dp_m, mtup5, mtdn5), 'val-ok');
            } else {
                setOut('m5-rh-m', String(M_rh), 'val-err');
                setOut('m5-rh-m-range', '—');
            }
        } else {
            setOut('m5-rh-allow', src5, 'val-warn');
            setOut('m5-rh-wk', '（待查表）', 'val-warn');
            setOut('m5-rh-m', '（待查表）', 'val-warn');
            setOut('m5-rh-m-range', '—');
        }

        var warns = [];
        if (x_up < -2 || x_up > 5 || x_dn < -2 || x_dn > 5)
            warns.push('轉位係數超出一般範圍 (-2~5)，請確認輸入值');
        if (allow5 <= 0 && !boss5)
            warns.push('查不到模數或外徑對應的預留量，建議滾齒尺寸無法計算（請至「預留量管理」新增）');
        showWarn('g-m5-warn', warns.length ? warns.join('；') : '');
    };

    // ══ 模組五附加：跨齒轉換（客戶跨齒厚規格 → 我方跨齒數＋建議滾齒）═════════
    window.calcGearM5Wk = function() {
        if (!_baseParams) { alert('請先執行基本計算'); return; }
        var p = _baseParams;
        var kc = gInt('m5w-k-cust'); if (!kc || kc < 1) kc = p.k;
        var km = gInt('m5w-k-mine'); if (!km || km < 1) km = p.k;
        var Wk = gFloat('m5w-wk');
        if (Wk === null) { alert('請填寫跨齒厚標準值'); return; }
        var tu = gFloat('m5w-tol-up') !== null ? gFloat('m5w-tol-up') : 0;
        var td = gFloat('m5w-tol-dn') !== null ? gFloat('m5w-tol-dn') : 0;

        var B  = 2 * p.mn * Math.sin(p.alpha_n);
        if (Math.abs(B) < 1e-10) { showWarn('g-m5w-warn', '法向壓力角為 0°，無法計算'); return; }
        var Ac = p.mn * Math.cos(p.alpha_n) * (PI * (kc - 0.5) + p.z * p.inv_alpha_t);
        var Am = p.mn * Math.cos(p.alpha_n) * (PI * (km - 0.5) + p.z * p.inv_alpha_t);

        // 由客戶規格（標準/上限/下限）分別反推 x，再以我方跨齒數換算
        var x_std = (Wk - Ac) / B;
        var x_up  = ((Wk + tu) - Ac) / B;
        var x_dn  = ((Wk + td) - Ac) / B;
        var Wm_std = gRound(Am + B * x_std, 5);
        var Wm_up  = gRound(Am + B * x_up,  5);
        var Wm_dn  = gRound(Am + B * x_dn,  5);

        setOut('m5w-out-k', km);
        setOut('m5w-out-std', fmtNum(Wm_std, 5), 'val-ok');
        setOut('m5w-out-range', fmtNum(Wm_dn, 5) + '  ~  ' + fmtNum(Wm_up, 5), 'val-ok');

        // 建議滾齒：與 M1/M4 相同邏輯 → 轉換後標準值 + (上公差 − (−0.02)) 偏移 + 預留量
        var wk_off = tu - (-0.02);
        var mn_res = lookupMn(p.mn);
        var da_res = lookupDa(p.da);
        var allow5w = 0, src5w = '（未查到預留量）', boss5w = false;
        if (da_res && da_res.boss) {
            boss5w = true; src5w = '外徑超大（需詢問BOSS）';
        } else {
            var mna = mn_res ? mn_res.allow : -Infinity;
            var daa = da_res ? da_res.allow : -Infinity;
            allow5w = Math.max(mna, daa);
            if (allow5w > 0) {
                var which = (daa >= mna && da_res) ? '外徑' : '模數';
                src5w = '預留 ' + fmtNum(allow5w, 4) + '（' + which + '預留較大）';
            }
        }
        var mtup = gFloat('g-mtol-up') !== null ? gFloat('g-mtol-up') : 0.02;
        var mtdn = gFloat('g-mtol-dn') !== null ? gFloat('g-mtol-dn') : -0.02;

        if (boss5w) {
            setOut('m5w-rh-allow', src5w, 'val-boss');
            setOut('m5w-rh-wk', '需詢問BOSS', 'val-boss');
            setOut('m5w-rh-m', '需詢問BOSS', 'val-boss');
            setOut('m5w-rh-m-range', '需詢問BOSS', 'val-boss');
        } else if (allow5w > 0) {
            var rh_Wk = gRound(Wm_std + wk_off + allow5w, 5);
            var x_rh  = (rh_Wk - Am) / B;
            var M_rh  = calcM(x_rh, p.mn, p.z, p.alpha_n, p.beta, p.dp);
            setOut('m5w-rh-allow', src5w, '');
            setOut('m5w-rh-wk', fmtNum(rh_Wk, 5), 'val-ok');
            if (typeof M_rh === 'number') {
                setOut('m5w-rh-m', fmtNum(M_rh, 5), 'val-ok');
                // 上/下限 = 建議滾齒 Wk±(M公差當跨齒厚公差帶) 換算為我方球徑 M（非 M±公差）
                setOut('m5w-rh-m-range', wkTolToMRange(rh_Wk, p.mn, p.z, p.alpha_n, p.beta, p.inv_alpha_t, km, p.dp, mtup, mtdn));
            } else {
                setOut('m5w-rh-m', String(M_rh), 'val-err');
                setOut('m5w-rh-m-range', '—');
            }
        } else {
            setOut('m5w-rh-allow', src5w, 'val-warn');
            setOut('m5w-rh-wk', '（待查表）', 'val-warn');
            setOut('m5w-rh-m', '（待查表）', 'val-warn');
            setOut('m5w-rh-m-range', '—');
        }

        var warns = [];
        if (tu < td) warns.push('上限公差小於下限公差，請確認輸入');
        if (x_std < -2 || x_std > 5) warns.push('反推轉位係數 x = ' + fmtNum(x_std, 4) + ' 超出一般範圍 (-2~5)，請確認輸入值');
        showWarn('g-m5w-warn', warns.length ? warns.join('；') : '');
    };

    // ══ 回推轉位係數 x ═══════════════════════════════════════════════════════

    // 從 M1 的「回推」按鈕進入 rx tab（即使 tab 尚未啟用也強制開啟）
    window.gotoRxTab = function() {
        var btn = document.getElementById('gtab-rx');
        if (btn) { btn.disabled = false; switchGearTab('rx', btn); }
    };

    // 代入計算結果 → 填回 M1 的 g-x，切換回 M1 並 highlight
    window.fillXfromDa = function() {
        var el = document.getElementById('rx-da-x');
        if (!el || el.textContent === '—' || el.textContent === '') { alert('請先完成回推計算'); return; }
        var x = parseFloat(el.textContent);
        if (isNaN(x)) { alert('x 值無效'); return; }
        var gxEl = document.getElementById('g-x');
        if (!gxEl) return;
        gxEl.value = fmtNum(x, 5);
        calcGearM1();
        var m1btn = document.querySelector('.gear-tab-btn[data-tab="m1"]');
        switchGearTab('m1', m1btn);
        setTimeout(function(){ gxEl.focus(); gxEl.select(); }, 120);
    };

    window.fillXfromWk = function() {
        var el = document.getElementById('rx-wk-x');
        if (!el || el.textContent === '—' || el.textContent === '') { alert('請先完成回推計算'); return; }
        var x = parseFloat(el.textContent);
        if (isNaN(x)) { alert('x 值無效'); return; }
        var gxEl = document.getElementById('g-x');
        if (!gxEl) return;
        gxEl.value = fmtNum(x, 5);
        calcGearM1();
        var m1btn = document.querySelector('.gear-tab-btn[data-tab="m1"]');
        switchGearTab('m1', m1btn);
        setTimeout(function(){ gxEl.focus(); gxEl.select(); }, 120);
    };

    // 由外徑 da 回推 x
    window.calcGearDa2X = function() {
        if (!_baseParams) { alert('請先執行基本計算'); return; }
        var p = _baseParams;
        var da_in = gFloat('rx-da');
        if (da_in === null || da_in <= 0) { alert('請填寫外徑 da'); return; }

        // da = mt*z + 2*mn*(1+x)  →  x = (da - mt*z)/(2*mn) - 1
        var mt = p.mn / Math.cos(p.beta);
        var d  = mt * p.z;
        var x  = (da_in - d) / (2 * p.mn) - 1;
        var da_verify = gRound(d + 2 * p.mn * (1 + x), 5);  // 應等於 da_in

        var warns = [];
        var x_min = 1 - p.z * Math.sin(p.alpha_t) * Math.sin(p.alpha_t) / 2;
        if (x < x_min - 0.001) warns.push('根切警告：x(' + fmtNum(x,4) + ') < x_min(' + fmtNum(x_min,4) + ')');
        if (x < -2 || x > 5) warns.push('x = ' + fmtNum(x,4) + ' 超出一般範圍 (−2 ~ 5)');

        setOut('rx-da-x', fmtNum(gRound(x,5), 5), 'val-ok');
        setOut('rx-da-verify', fmtNum(da_verify, 4));
        showWarn('g-rx-da-warn', warns.length ? warns.join('；') : '');
    };

    // 由跨齒厚 Wk 回推 x
    window.calcGearWk2X = function() {
        if (!_baseParams) { alert('請先執行基本計算'); return; }
        var p = _baseParams;
        var k_in = gInt('rx-k'), Wk_in = gFloat('rx-wk');
        if (!k_in || k_in < 1) { alert('請填寫跨幾齒 k（≥1）'); return; }
        if (Wk_in === null) { alert('請填寫跨齒厚 Wk'); return; }

        // Wk = mn*cos(αn)*[π*(k-0.5) + z*inv(αt)] + 2*x*mn*sin(αn)
        var A = p.mn * Math.cos(p.alpha_n) * (PI * (k_in - 0.5) + p.z * p.inv_alpha_t);
        var B = 2 * p.mn * Math.sin(p.alpha_n);
        if (Math.abs(B) < 1e-10) { showWarn('g-rx-wk-warn','法向壓力角為 0°，無法計算'); return; }

        var x  = (Wk_in - A) / B;
        var Wk_verify = gRound(A + B * x, 5);  // 應等於 Wk_in

        var warns = [];
        var x_min = 1 - p.z * Math.sin(p.alpha_t) * Math.sin(p.alpha_t) / 2;
        if (x < x_min - 0.001) warns.push('根切警告：x(' + fmtNum(x,4) + ') < x_min(' + fmtNum(x_min,4) + ')');
        if (x < -2 || x > 5) warns.push('x = ' + fmtNum(x,4) + ' 超出一般範圍 (−2 ~ 5)');

        setOut('rx-wk-x', fmtNum(gRound(x,5), 5), 'val-ok');
        setOut('rx-wk-verify', fmtNum(Wk_verify, 4));
        showWarn('g-rx-wk-warn', warns.length ? warns.join('；') : '');
    };

    // ── 清除全部 ────────────────────────────────────────────────────────────
    window.clearGearAll = function() {
        ['g-mn','g-z','g-an','g-x','g-bt','g-bt-d','g-bt-m','g-bt-s',
         'g-tol-up','g-k-in','g-dp','g-mtol-up','g-mtol-dn',
         'g-cust-wk','g-cust-wtol-up','g-cust-wtol-dn','g-cust-dp','g-cust-m-up','g-cust-m-dn',
         // 依附基本計算的各分頁輸入欄（M2~M5、跨齒轉換、回推x）也一併清空
         'm2-k','m2-wk','m2-tol-up','m2-tol-dn','m2-dp',
         'm3-dp','m3-m','m3-tol-up','m3-tol-dn','m3-k-in',
         'm4-k','m4-wk','m4-tol-up','m4-tol-dn','m4-dp','m4-mtol-up','m4-mtol-dn',
         'm5-dp-cust','m5-dp-mine','m5-m-up','m5-m-dn',
         'm5w-k-cust','m5w-k-mine','m5w-wk','m5w-tol-up','m5w-tol-dn',
         'rx-da','rx-k','rx-wk'].forEach(function(id){
            var el = document.getElementById(id); if(el) el.value = '';
        });
        var btdec = document.getElementById('g-bt-dec'); if(btdec) btdec.textContent = '0°';
        // 模數單位還原為 M、隱藏換算顯示
        var mnUnit = document.getElementById('g-mn-unit'); if (mnUnit) mnUnit.value = 'M';
        updateGearMnDisplay();
        // 還原法向壓力角預設按鈕
        document.querySelectorAll('.gear-preset-btn').forEach(function(b){ b.classList.remove('active'); });
        ['go-mt','go-d','go-da','go-df','go-h','go-k','go-wk','go-wk-bmin','go-dp-used',
         'go-allow-info','go-rechob-wk','go-m','go-rechob-m','go-rechob-m-range','go-cust-wk-range','go-cust-m-range',
         'go-cust-rh-wk','go-cust-rh-m','go-cust-rh-m-range',
         // M2~M5、跨齒轉換 各分頁計算結果
         'm2-m-up','m2-m-dn','m2-wk-up-chk','m2-wk-dn-chk',
         'm3-k-out','m3-x','m3-wk','m3-wk-tol','m3-wk-up','m3-wk-dn',
         'm4-dp-used','m4-allow-info','m4-rechob-wk','m4-rechob-m','m4-rechob-m-range','m4-cust-m-up','m4-cust-m-dn',
         'm5-out-dp-mine','m5-m-mine-up','m5-m-mine-dn','m5-out-dp-cust','m5-m-cust-up','m5-m-cust-dn',
         'm5-rh-allow','m5-rh-wk','m5-rh-m','m5-rh-m-range',
         'm5w-out-std','m5w-out-range','m5w-rh-allow','m5w-rh-wk','m5w-rh-m','m5w-rh-m-range'].forEach(function(id){ setOut(id,''); });
        // 非 gear-output-val 樣式的純文字輸出（避免 setOut 改動 class）
        ['m5-x-up','m5-x-dn','g-cust-x-up','g-cust-x-dn','g-cust-x-mid'].forEach(function(id){ gSetText(id, '—'); });
        ['m5-rh-k','m5w-out-k'].forEach(function(id){ gSetText(id, '—'); });
        ['m2-dp-hint','m5-dp-hint','m4-cust-m-dp-label'].forEach(function(id){ gSetText(id, ''); });
        // 隱藏客戶數據相關輸出列
        var custWkRow = document.getElementById('go-row-cust-wk'); if (custWkRow) custWkRow.style.display = 'none';
        var custXOut = document.getElementById('g-cust-x-out'); if (custXOut) custXOut.style.display = 'none';
        ['go-row-cust-m','go-cust-m-note','go-row-cust-rh-wk','go-row-cust-rh-m','go-row-cust-rh-range'].forEach(function(id){
            var e = document.getElementById(id); if (e) e.style.display = 'none';
        });
        showWarn('g-m1-warn','');
        _baseParams = null;
        setGearMode('ext'); // 清除後一律回到預設「外齒」模式
        ['m2','m3','m4','m5','rx'].forEach(function(t){ var b=document.getElementById('gtab-'+t); if(b) b.disabled=true; });
        setOut('rx-da-x',''); setOut('rx-da-verify',''); setOut('rx-wk-x',''); setOut('rx-wk-verify','');
        ['g-rx-da-warn','g-rx-wk-warn','g-m2-warn','g-m3-warn','g-m4-warn','g-m5-warn','g-m5w-warn','g-cust-warn'].forEach(function(id){ var e=document.getElementById(id); if(e) e.style.display='none'; });
        setTimeout(function(){ var el=document.getElementById('g-mn'); if(el) el.focus(); }, 50);
    };

    // ══ 分頁切換 ════════════════════════════════════════════════════════════
    window.switchGearTab = function(tab, btnEl) {
        document.querySelectorAll('#gear-tool-window .gear-tab-pane').forEach(function(p){ p.classList.remove('active'); });
        document.querySelectorAll('#gear-tool-window .gear-tab-btn').forEach(function(b){ b.classList.remove('active'); });
        var pane = document.getElementById('gear-pane-'+tab);
        if (pane) pane.classList.add('active');
        if (btnEl) btnEl.classList.add('active');
        if (tab === 'tables') loadAllowanceTables();
        // 自動 focus 各模組第一個輸入框
        var firstMap = { m1:'g-mn', m2:'m2-k', m3:'m3-dp', m4:'m4-k', m5:'m5-dp-cust', rx:'rx-da', sr:'sr-n', tail:'tail-ds' };
        if (firstMap[tab]) {
            setTimeout(function(){ var el=document.getElementById(firstMap[tab]); if(el) el.focus(); }, 80);
        }
        // M2 顯示基本計算的 dp 提示
        if (tab === 'm2' && _baseParams) {
            var hint = document.getElementById('m2-dp-hint');
            if (hint) hint.textContent = '（基本計算 dp = ' + fmtNum(_baseParams.dp, 2) + '）';
        }
        // M5 自動帶入基本計算的球徑到「客戶球徑」欄
        if (tab === 'm5' && _baseParams) {
            var el5 = document.getElementById('m5-dp-cust');
            if (el5 && el5.value === '') el5.value = _baseParams.dp;
            var h5 = document.getElementById('m5-dp-hint');
            if (h5) h5.textContent = '（基本計算 dp = ' + fmtNum(_baseParams.dp, 2) + '）';
        }
    };

    // ── 法向壓力角快速選取 ───────────────────────────────────────────────────
    window.setAlphaN = function(deg) {
        var el = document.getElementById('g-an'); if (!el) return;
        el.value = deg;
        var b20 = document.getElementById('g-an-btn-20');
        var b30 = document.getElementById('g-an-btn-30');
        if (b20) b20.classList.toggle('active', deg === 20);
        if (b30) b30.classList.toggle('active', deg === 30);
    };
    // 手動改值時更新按鈕高亮（在 initEnterTab 中呼叫，確保 DOM 已注入）
    function initAnBtnHighlight() {
        var anEl = document.getElementById('g-an'); if (!anEl) return;
        anEl.addEventListener('input', function(){
            var v = parseFloat(this.value);
            var b20 = document.getElementById('g-an-btn-20');
            var b30 = document.getElementById('g-an-btn-30');
            if (b20) b20.classList.toggle('active', v === 20);
            if (b30) b30.classList.toggle('active', v === 30);
        });
    }

    // ── 螺旋角模式切換 ───────────────────────────────────────────────────────
    window.toggleBetaMode = function(mode) {
        var degW = document.getElementById('g-bt-deg-wrap');
        var dmsW = document.getElementById('g-bt-dms-wrap');
        if (!degW || !dmsW) return;
        if (mode === 'dms') {
            degW.style.display = 'none'; dmsW.style.display = 'block';
            // 清除整數欄
            var el = document.getElementById('g-bt'); if (el) el.value = '';
        } else {
            degW.style.display = 'flex'; dmsW.style.display = 'none';
            // 清除 DMS 欄
            ['g-bt-d','g-bt-m','g-bt-s'].forEach(function(id){
                var e = document.getElementById(id); if (e) e.value = '';
            });
            var dc = document.getElementById('g-bt-dec'); if (dc) dc.textContent = '0°';
        }
    };

    // ── Enter = Tab（模組一輸入區）────────────────────────────────────────────
    function initEnterTab() {
        // 依序排列 M1 輸入欄位 ID（beta 兩種模式的欄位都列入；末段接續「客戶提供數據」欄位，
        // 使建議滾齒 M 公差按 Enter 續往下跳客戶欄，最後一欄才觸發計算）
        var m1Seq = ['g-mn','g-z','g-an','g-x','g-bt','g-bt-d','g-bt-m','g-bt-s',
                     'g-tol-up','g-k-in','g-dp','g-mtol-up','g-mtol-dn',
                     'g-cust-wk','g-cust-wtol-up','g-cust-wtol-dn','g-cust-dp','g-cust-m-dn','g-cust-m-up'];
        m1Seq.forEach(function(id, idx) {
            var el = document.getElementById(id); if (!el) return;
            el.addEventListener('keydown', function(e) {
                if (e.key !== 'Enter') return;
                e.preventDefault();
                // 找下一個可見且未停用的欄位
                for (var i = idx + 1; i < m1Seq.length; i++) {
                    var next = document.getElementById(m1Seq[i]);
                    if (next && next.offsetParent !== null && !next.disabled) {
                        next.focus(); return;
                    }
                }
                // 最後一格 Enter = 觸發計算
                calcGearM1();
            });
        });

        // 通用：Enter = Tab，最後一格觸發 callback
        function bindSeqEnter(ids, lastCb) {
            ids.forEach(function(id, idx) {
                var el = document.getElementById(id); if (!el) return;
                el.addEventListener('keydown', function(e) {
                    if (e.key !== 'Enter') return;
                    e.preventDefault();
                    for (var i = idx + 1; i < ids.length; i++) {
                        var next = document.getElementById(ids[i]);
                        if (next && next.offsetParent !== null && !next.disabled) { next.focus(); return; }
                    }
                    if (lastCb) lastCb();
                });
            });
        }

        // 齒研跨齒厚上公差有值時，自動帶入「客戶跨齒厚公差 上公差」
        // 僅在「客戶跨齒厚 Wk 有填」時才帶入（客戶欄為空時才帶，不覆蓋手動輸入）；
        // 客戶跨齒厚 Wk 沒有資料時，不把齒研跨齒厚上公差帶入客戶跨齒厚公差上公差
        (function(){
            var src = document.getElementById('g-tol-up');
            var dst = document.getElementById('g-cust-wtol-up');
            var wk  = document.getElementById('g-cust-wk');
            if (!src || !dst || !wk) return;
            function sync(){ if (wk.value !== '' && src.value !== '' && dst.value === '') dst.value = src.value; }
            src.addEventListener('input', sync);
            src.addEventListener('change', sync);
            wk.addEventListener('input', sync);   // 客戶跨齒厚 Wk 填入後才觸發帶入
            wk.addEventListener('change', sync);
            sync(); // 初始若客戶Wk與齒研上公差皆已有值即帶入
        })();

        // 模組二
        bindSeqEnter(['m2-k','m2-wk','m2-tol-up','m2-tol-dn'], window.calcGearM2);
        // 模組三
        bindSeqEnter(['m3-dp','m3-m','m3-tol-up','m3-tol-dn','m3-k-in'], window.calcGearM3);
        // 模組四
        bindSeqEnter(['m4-k','m4-wk','m4-tol-up','m4-tol-dn','m4-dp','m4-mtol-up','m4-mtol-dn'], window.calcGearM4);
        // 模組五
        bindSeqEnter(['m5-dp-cust','m5-dp-mine','m5-m-dn','m5-m-up'], window.calcGearM5);
        // 預留量新增表單
        bindSeqEnter(['mn-f-gt','mn-f-lte','mn-f-allow'], window.saveMnRow);
        bindSeqEnter(['da-f-gt','da-f-lte','da-f-allow'], window.saveDaRow);

        // 回推 x
        bindSeqEnter(['rx-da'], window.calcGearDa2X);
        bindSeqEnter(['rx-k','rx-wk'], window.calcGearWk2X);

        // 花鍵 外（mn/pd 只有一個可見，offsetParent 會自動跳過隱藏那個）
        bindSeqEnter(['sp-ext-mn','sp-ext-pd','sp-ext-z','sp-ext-an','sp-ext-bt',
                      'sp-ext-x','sp-ext-q','sp-ext-dp','sp-ext-k'], window.calcSplineExt);
        // 花鍵 外：測棒換算
        bindSeqEnter(['sp-ext-conv-dp2'], window.calcSplineConvExt);

        // 花鍵 內
        bindSeqEnter(['sp-int-mn','sp-int-pd','sp-int-z','sp-int-an','sp-int-bt',
                      'sp-int-x','sp-int-q','sp-int-dp'], window.calcSplineInt);
        // 花鍵 內：測棒換算
        bindSeqEnter(['sp-int-conv-dp2'], window.calcSplineConvInt);

        // 花鍵公差管理表單
        bindSeqEnter(['sp-tol-f-qc','sp-tol-f-fc','sp-tol-f-mgt','sp-tol-f-mlte',
                      'sp-tol-f-udev','sp-tol-f-tol','sp-tol-f-notes'], window.saveSplineTolRow);
        // 栓槽
        bindSeqEnter(['sr-n','sr-D','sr-D-up','sr-D-dn','sr-d','sr-d-up','sr-d-dn','sr-B','sr-B-tol','sr-dp-custom'], window.calcSplineRect);
        // 出尾：共用欄位 → 刀具外徑 → 計算（A 模式主流程）
        bindSeqEnter(['tail-ds','tail-dr','tail-u','tail-da-a'], window.calcTailA);
        // 出尾 B模式：只綁目標出尾欄位（共用欄位已在 A 模式綁過）
        bindSeqEnter(['tail-target'], window.calcTailB);
    }

    // ══ 開啟/關閉視窗 ════════════════════════════════════════════════════════
    var _gearDomInited = false;
    function ensureGearDom() {
        if (_gearDomInited) return;
        _gearDomInited = true;
        var tpl  = document.getElementById('gear-tool-tpl');
        var cont = document.getElementById('gear-tool-container');
        if (tpl && cont) {
            cont.appendChild(document.importNode(tpl.content, true));
            if (tpl.parentNode) tpl.parentNode.removeChild(tpl);
        }
        initEnterTab();
        initAnBtnHighlight();
        initDrag();
        initDockResize();
        initDockOffsetWatch();
        initGearSelectOnFocus();
        // 停靠／浮出：只有呼叫端指定了停靠容器的頁面才有這個模式
        if (GEAR_DOCK_SEL) {
            var pref = null;
            try { pref = localStorage.getItem('eg_gear_dock'); } catch (e) {}
            setGearDock(pref === null ? (window.GEAR_TOOL_DOCK_DEFAULT !== false) : pref === '1', false);
        }
    }

    // ══ 停靠（頁面內側邊面板）↔ 浮出（可拖曳視窗）════════════════
    var GEAR_DOCK_SEL = (window.GEAR_TOOL_DOCK_SEL || '');
    var _gearDocked = false;
    var GEAR_DOCK_OFFSET_SEL = (window.GEAR_TOOL_DOCK_OFFSET_SEL || '');
    function gearDockHost() { return GEAR_DOCK_SEL ? document.querySelector(GEAR_DOCK_SEL) : null; }
    // 同容器內另一個也貼右緣的面板（批圖編輯器的標籤庫）打開時往左讓開，不要互相蓋住
    function gearDockOffset() {
        if (!GEAR_DOCK_OFFSET_SEL) return 0;
        var el = document.querySelector(GEAR_DOCK_OFFSET_SEL);
        if (!el || el.offsetParent === null) return 0;
        return Math.round(el.getBoundingClientRect().width);
    }
    function gearApplyDockOffset() {
        var win = document.getElementById('gear-tool-window');
        if (!win || !_gearDocked) return;
        win.style.right = gearDockOffset() + 'px';
    }
    function initDockOffsetWatch() {
        if (!GEAR_DOCK_OFFSET_SEL) return;
        var el = document.querySelector(GEAR_DOCK_OFFSET_SEL);
        if (!el) return;
        // 那個面板的開關與寬度都是改 class／style，用 MutationObserver 跟著調整
        new MutationObserver(gearApplyDockOffset).observe(el, { attributes: true, attributeFilter: ['class', 'style'] });
        window.addEventListener('resize', gearApplyDockOffset);
    }
    function gearDockWidth() {
        var w = 0;
        try { w = parseInt(localStorage.getItem('eg_gear_dock_w') || '0', 10); } catch (e) {}
        return (!w || w < 320) ? 430 : w;
    }
    window.setGearDock = function(on, remember) {
        var win = document.getElementById('gear-tool-window');
        if (!win) return;
        var host = gearDockHost();
        if (on && !host) on = false;                       // 沒有停靠容器＝只能浮動
        _gearDocked = !!on;
        var shown = win.style.display && win.style.display !== 'none';
        if (on) {
            if (window.getComputedStyle(host).position === 'static') host.style.position = 'relative';
            if (win.parentNode !== host) host.appendChild(win);
            win.classList.add('gear-docked');
            win.style.left = ''; win.style.top = ''; win.style.transform = '';
            win.style.width = Math.min(gearDockWidth(), Math.max(320, host.clientWidth - gearDockOffset() - 40)) + 'px';
            gearApplyDockOffset();
            if (shown) win.style.display = 'flex';
        } else {
            var cont = document.getElementById('gear-tool-container') || document.body;
            if (win.parentNode !== cont) cont.appendChild(win);
            win.classList.remove('gear-docked');
            win.style.width = ''; win.style.right = '';
            win.style.left = '50%'; win.style.top = '55px'; win.style.transform = 'translateX(-50%)';
            if (shown) win.style.display = 'block';
        }
        var btn = document.getElementById('gear-dock-toggle');
        if (btn) {
            btn.innerHTML = on ? '<i class="fa fa-external-link"></i> 浮出' : '<i class="fa fa-columns"></i> 停靠';
            btn.title = on ? '浮出成可拖曳的獨立視窗（可自由拖到畫面任何位置）'
                           : '停靠成頁面內的側邊面板（貼在右緣，不蓋住整個畫面）';
        }
        var sp = document.getElementById('gear-settings-panel');
        if (sp) sp.style.display = 'none';
        if (remember) { try { localStorage.setItem('eg_gear_dock', on ? '1' : '0'); } catch (e) {} }
    };
    window.toggleGearDock = function() { window.setGearDock(!_gearDocked, true); };

    // 停靠時左緣可拖曳調整寬度（記住使用者調過的寬度）
    function initDockResize() {
        var rz  = document.getElementById('gear-dock-resizer');
        var win = document.getElementById('gear-tool-window');
        if (!rz || !win) return;
        var startX = 0, startW = 0;
        rz.addEventListener('mousedown', function(e) {
            if (!_gearDocked) return;
            startX = e.clientX; startW = win.getBoundingClientRect().width;
            rz.classList.add('active');
            document.addEventListener('mousemove', onRz);
            document.addEventListener('mouseup',   offRz);
            e.preventDefault();
        });
        function onRz(e) {
            var host = gearDockHost();
            var max  = host ? Math.max(360, host.clientWidth - gearDockOffset() - 40) : 900;
            win.style.width = Math.min(Math.max(320, startW + (startX - e.clientX)), max) + 'px';
        }
        function offRz() {
            rz.classList.remove('active');
            document.removeEventListener('mousemove', onRz);
            document.removeEventListener('mouseup',   offRz);
            try { localStorage.setItem('eg_gear_dock_w', String(Math.round(win.getBoundingClientRect().width))); } catch (e) {}
        }
    }

    // ── 聚焦已有資料的欄位自動全選（齒輪工具視窗全部輸入欄一體適用）──────────
    function initGearSelectOnFocus() {
        var win = document.getElementById('gear-tool-window');
        if (!win) return;
        win.addEventListener('focusin', function(e) {
            var t = e.target;
            if (t && t.tagName === 'INPUT' && (t.type === 'number' || t.type === 'text') && t.value !== '') {
                try { t.select(); } catch(err) {}
                t.__gearSelAll = true;
            }
        });
        // 滑鼠點入時瀏覽器會在 mouseup 取消選取，攔截一次以保住全選
        win.addEventListener('mouseup', function(e) {
            var t = e.target;
            if (t && t.__gearSelAll) { t.__gearSelAll = false; e.preventDefault(); }
        });
    }

    window.openGearTool = function() {
        ensureGearDom();
        var w = document.getElementById('gear-tool-window');
        if (!w) return;
        w.style.display = _gearDocked ? 'flex' : 'block';
        // 初始化載入資料（首次開啟）
        if (_mnTable.length === 0 && _daTable.length === 0) {
            gearAjax.post({action:'gear_init'}, function(res){
                if (res.success) {
                    _mnTable = res.mn_rows || [];
                    _daTable = res.da_rows || [];
                    _techDeptIds = res.tech_dept_ids || [];
                    _allDepts = res.depts || [];
                    renderMnTable(_mnTable);
                    renderDaTable(_daTable);
                }
            }, 'json');
        }
        if (!_splineInited) { _splineInited = true; initSplineTool(); }
        // 游標定位到法向模數
        setTimeout(function(){ var el=document.getElementById('g-mn'); if(el) el.focus(); }, 80);
        // 圖示動畫
        var ico = document.getElementById('gear-hdr-icon');
        if (ico) { ico.className = 'fa fa-cog fa-spin'; }
    };
    window.closeGearTool = function() {
        var w = document.getElementById('gear-tool-window');
        if (w) w.style.display = 'none';
        var sp = document.getElementById('gear-settings-panel');
        if (sp) sp.style.display = 'none';
    };

    // ══ 拖曳移動視窗 ════════════════════════════════════════════════════════
    function initDrag() {
        var hdr = document.getElementById('gear-tool-hdr');
        var win = document.getElementById('gear-tool-window');
        if (!hdr || !win) return;
        var startX, startY, startL, startT;
        hdr.addEventListener('mousedown', function(e) {
            if (_gearDocked) return;                       // 停靠成側邊面板時標題列不拖曳
            if (e.target.tagName === 'BUTTON' || e.target.tagName === 'I') return;
            startX = e.clientX; startY = e.clientY;
            startL = parseInt(win.style.left) || (win.getBoundingClientRect().left);
            startT = parseInt(win.style.top)  || (win.getBoundingClientRect().top);
            win.style.transform = 'none';
            win.style.left = startL + 'px';
            win.style.top  = startT + 'px';
            document.addEventListener('mousemove', onDrag);
            document.addEventListener('mouseup',   onDrop);
            e.preventDefault();
        });
        function onDrag(e) {
            win.style.left = (startL + e.clientX - startX) + 'px';
            win.style.top  = (startT + e.clientY - startY) + 'px';
        }
        function onDrop() {
            document.removeEventListener('mousemove', onDrag);
            document.removeEventListener('mouseup',   onDrop);
        }
    }

    // ══ 設定：技術課部門 ═════════════════════════════════════════════════════
    window.toggleGearSettings = function(e) {
        e.stopPropagation();
        var sp = document.getElementById('gear-settings-panel');
        if (!sp) return;
        var visible = sp.style.display === 'block';
        if (visible) { sp.style.display = 'none'; return; }

        // 第一次開啟時把面板搬到 body 層，確保不被 overflow 或 stacking context 裁切
        if (sp.parentNode !== document.body) {
            sp.style.position = 'fixed';
            sp.style.zIndex   = '11000';
            document.body.appendChild(sp);
        }
        // 依設定按鈕位置計算面板座標
        var btn = document.getElementById('gear-settings-toggle');
        if (btn) {
            var r = btn.getBoundingClientRect();
            sp.style.top   = (r.bottom + 6) + 'px';
            sp.style.right = (window.innerWidth - r.right) + 'px';
            sp.style.left  = 'auto';
        }
        sp.style.display = 'block';
        renderGearDeptList();
    };
    function renderGearDeptList() {
        var el = document.getElementById('gear-dept-list'); if (!el) return;
        if (!_allDepts.length) {
            gearAjax.post({action:'gear_init'}, function(res){
                if(res.success){ _allDepts=res.depts||[]; _techDeptIds=res.tech_dept_ids||[]; renderGearDeptList(); }
            },'json'); return;
        }
        // 2026-08-03 起技術部門由全站「組織角色綁定設定」決定（含子部門）→ 本頁一律反灰唯讀
        var html = '';
        _allDepts.forEach(function(d) {
            var checked = _techDeptIds.indexOf(parseInt(d.id)) !== -1 ? ' checked' : '';
            var lv = parseInt(d.level) || 1; var cls = lv >= 2 ? ' level-'+lv : '';
            html += '<label class="gear-dept-item'+cls+'" style="color:#999;cursor:not-allowed;">'
                  + '<input type="checkbox" value="'+escGear(d.id)+'"'+checked+' disabled> '
                  + escGear(d.name) + '</label>';
        });
        el.innerHTML = html
                     + '<div style="font-size:11px;color:#8a6d45;margin-top:6px;">此項目已統一由'
                     + '<a href="../admin/org_role_setting.php" target="_blank"><b>組織角色綁定設定</b></a>'
                     + '的「設計／技術部門」決定（含其子部門），僅能在該頁修改。</div>';
    }
    window.saveGearSettings = function() {
        var checkboxes = document.querySelectorAll('#gear-dept-list input[type=checkbox]:checked');
        var ids = [];
        checkboxes.forEach(function(c){ ids.push(parseInt(c.value)); });
        var msgEl = document.getElementById('gear-settings-msg');
        gearAjax.post({action:'gear_save_settings', dept_ids: JSON.stringify(ids)}, function(res){
            if (res.success) {
                _techDeptIds = ids;
                if (msgEl) { msgEl.style.color='#27ae60'; msgEl.textContent='✓ 已儲存'; }
                setTimeout(function(){ if(msgEl) msgEl.textContent=''; document.getElementById('gear-settings-panel').style.display='none'; }, 1200);
            } else {
                if (msgEl) { msgEl.style.color='#c0392b'; msgEl.textContent='錯誤：'+(res.message||'儲存失敗'); }
            }
        }, 'json');
    };
    // 點外部關閉設定面板
    document.addEventListener('click', function(e){
        var sp = document.getElementById('gear-settings-panel');
        if (sp && sp.style.display === 'block') {
            if (!sp.contains(e.target) && e.target.id !== 'gear-settings-toggle' && !e.target.closest('#gear-settings-toggle')) {
                sp.style.display = 'none';
            }
        }
    });

    // ══ 預留量管理：載入 ═════════════════════════════════════════════════════
    function loadAllowanceTables() {
        gearAjax.post({action:'gear_init'}, function(res){
            if (res.success) {
                _mnTable = res.mn_rows || [];
                _daTable = res.da_rows || [];
                _allDepts = res.depts || [];
                _techDeptIds = res.tech_dept_ids || [];
                renderMnTable(_mnTable);
                renderDaTable(_daTable);
            }
        }, 'json');
    }

    // ── 模數表渲染 ───────────────────────────────────────────────────────────
    function renderMnTable(rows) {
        var tb = document.getElementById('mn-tbl-body'); if (!tb) return;
        if (!rows || !rows.length) { tb.innerHTML = '<tr><td colspan="5" style="color:#aaa;text-align:center;padding:8px;">尚無資料</td></tr>'; return; }
        var html = '';
        rows.forEach(function(r) {
            var boss = parseInt(r.ask_boss) ? '<span class="gear-boss-badge">BOSS</span>' : '';
            var isExact = parseInt(r.is_exact);
            var col1 = isExact ? '<span style="color:#e67e22;font-weight:700;">= '+fmtNum(parseFloat(r.mn_gt),3)+'</span>' : fmtNum(parseFloat(r.mn_gt),3);
            var col2 = isExact ? '<span style="color:#aaa;">—</span>' : fmtNum(parseFloat(r.mn_lte),3);
            var allowStr = parseInt(r.ask_boss) ? '<span style="color:#e65100;">詢問BOSS</span>' : fmtNum(parseFloat(r.hob_allow), 3);
            html += '<tr><td>'+col1+'</td><td>'+col2+'</td>'
                  + '<td>'+allowStr+'</td>'
                  + '<td>'+boss+'</td>'
                  + '<td style="white-space:nowrap;">'
                  + '<button class="btn-gear-edit" onclick="openMnForm('+escGear(r.id)+')">編輯</button> '
                  + '<button class="btn-gear-del"  onclick="deleteMnRow('+escGear(r.id)+')">刪除</button>'
                  + '</td></tr>';
        });
        tb.innerHTML = html;
    }
    window.toggleMnExact = function() {
        var isExact = document.getElementById('mn-f-exact').checked;
        var lteWrap = document.getElementById('mn-f-lte-wrap');
        var gtLbl   = document.getElementById('mn-f-gt-lbl');
        if (lteWrap) lteWrap.style.display = isExact ? 'none' : '';
        if (gtLbl)   gtLbl.textContent = isExact ? '模數 =' : '模數 >';
    };
    window.openMnForm = function(id) {
        document.getElementById('mn-form-area').style.display = 'block';
        document.getElementById('mn-form-err').textContent = '';
        if (id > 0) {
            var row = _mnTable.find(function(r){ return parseInt(r.id) === id; });
            if (row) {
                document.getElementById('mn-f-gt').value     = fmtNum(parseFloat(row.mn_gt), 3);
                document.getElementById('mn-f-lte').value    = fmtNum(parseFloat(row.mn_lte), 3);
                document.getElementById('mn-f-allow').value  = fmtNum(parseFloat(row.hob_allow), 3);
                document.getElementById('mn-f-boss').checked = !!parseInt(row.ask_boss);
                document.getElementById('mn-f-exact').checked = !!parseInt(row.is_exact);
                document.getElementById('mn-f-id').value     = row.id;
            }
        } else {
            ['mn-f-gt','mn-f-lte','mn-f-allow'].forEach(function(i){ document.getElementById(i).value=''; });
            document.getElementById('mn-f-boss').checked  = false;
            document.getElementById('mn-f-exact').checked = false;
            document.getElementById('mn-f-id').value = '0';
        }
        toggleMnExact();
        setTimeout(function(){ var el=document.getElementById('mn-f-gt'); if(el) el.focus(); }, 50);
    };
    window.closeMnForm = function() {
        document.getElementById('mn-form-area').style.display = 'none';
    };
    window.saveMnRow = function() {
        var isExact = document.getElementById('mn-f-exact').checked ? 1 : 0;
        var data = {
            action:'gear_save_mn',
            id:       document.getElementById('mn-f-id').value,
            mn_gt:    document.getElementById('mn-f-gt').value,
            mn_lte:   isExact ? document.getElementById('mn-f-gt').value : document.getElementById('mn-f-lte').value,
            is_exact: isExact,
            hob_allow:document.getElementById('mn-f-allow').value,
            ask_boss: document.getElementById('mn-f-boss').checked ? 1 : 0
        };
        gearAjax.post(data, function(res){
            if (res.success) {
                _mnTable = res.rows; renderMnTable(_mnTable); closeMnForm();
            } else {
                document.getElementById('mn-form-err').textContent = res.message || '儲存失敗';
            }
        }, 'json');
    };
    window.deleteMnRow = function(id) {
        if (!confirm('確定刪除此模數預留量列？')) return;
        gearAjax.post({action:'gear_delete_mn', id:id}, function(res){
            if (res.success) { _mnTable = res.rows; renderMnTable(_mnTable); }
            else alert(res.message || '刪除失敗');
        }, 'json');
    };

    // ── 外徑表渲染 ───────────────────────────────────────────────────────────
    function renderDaTable(rows) {
        var tb = document.getElementById('da-tbl-body'); if (!tb) return;
        if (!rows || !rows.length) { tb.innerHTML = '<tr><td colspan="5" style="color:#aaa;text-align:center;padding:8px;">尚無資料</td></tr>'; return; }
        var html = '';
        rows.forEach(function(r) {
            var boss = parseInt(r.ask_boss) ? '<span class="gear-boss-badge">BOSS</span>' : '';
            html += '<tr><td>'+fmtNum(parseFloat(r.da_gt),3)+'</td><td>'+fmtNum(parseFloat(r.da_lte),3)+'</td>'
                  + '<td>'+(parseInt(r.ask_boss)?'<span style="color:#e65100;">詢問BOSS</span>':fmtNum(parseFloat(r.od_allow),3))+'</td>'
                  + '<td>'+boss+'</td>'
                  + '<td style="white-space:nowrap;">'
                  + '<button class="btn-gear-edit" onclick="openDaForm('+escGear(r.id)+')">編輯</button> '
                  + '<button class="btn-gear-del"  onclick="deleteDaRow('+escGear(r.id)+')">刪除</button>'
                  + '</td></tr>';
        });
        tb.innerHTML = html;
    }
    window.openDaForm = function(id) {
        document.getElementById('da-form-area').style.display = 'block';
        document.getElementById('da-form-err').textContent = '';
        if (id > 0) {
            var row = _daTable.find(function(r){ return parseInt(r.id) === id; });
            if (row) {
                document.getElementById('da-f-gt').value    = fmtNum(parseFloat(row.da_gt), 3);
                document.getElementById('da-f-lte').value   = fmtNum(parseFloat(row.da_lte), 3);
                document.getElementById('da-f-allow').value = fmtNum(parseFloat(row.od_allow), 3);
                document.getElementById('da-f-boss').checked = !!parseInt(row.ask_boss);
                document.getElementById('da-f-id').value    = row.id;
            }
        } else {
            ['da-f-gt','da-f-lte','da-f-allow'].forEach(function(i){ document.getElementById(i).value=''; });
            document.getElementById('da-f-boss').checked = false;
            document.getElementById('da-f-id').value = '0';
        }
        setTimeout(function(){ var el=document.getElementById('da-f-gt'); if(el) el.focus(); }, 50);
    };
    window.closeDaForm = function() {
        document.getElementById('da-form-area').style.display = 'none';
    };
    window.saveDaRow = function() {
        var data = {
            action:'gear_save_da',
            id: document.getElementById('da-f-id').value,
            da_gt:    document.getElementById('da-f-gt').value,
            da_lte:   document.getElementById('da-f-lte').value,
            od_allow: document.getElementById('da-f-allow').value,
            ask_boss: document.getElementById('da-f-boss').checked ? 1 : 0
        };
        gearAjax.post(data, function(res){
            if (res.success) {
                _daTable = res.rows; renderDaTable(_daTable); closeDaForm();
            } else {
                document.getElementById('da-form-err').textContent = res.message || '儲存失敗';
            }
        }, 'json');
    };
    window.deleteDaRow = function(id) {
        if (!confirm('確定刪除此外徑預留量列？')) return;
        gearAjax.post({action:'gear_delete_da', id:id}, function(res){
            if (res.success) { _daTable = res.rows; renderDaTable(_daTable); }
            else alert(res.message || '刪除失敗');
        }, 'json');
    };


    // ══ 花鍵計算工具 JS ══════════════════════════════════════════════════════

    var _splineTolRows = [];
    var _splineExtResult = null;
    var _splineIntResult = null;
    var _splineInited = false;

    // ── 花鍵數學輔助 ─────────────────────────────────────────────────────────

    // 內花鍵 M = calcM(-x_int) - 2*dp
    function calcMInt(x_int, mn, z, alpha_n, beta, dp) {
        // 內花鍵跨棒距：inv(αM) = e/d - inv(α) + DR/db
        // 其中 e/d = π/(2z) - 2x·tan(αn)/z（槽寬/節圓直徑）
        // M = db/cos(αM) - dp（偶數齒）；db/cos(αM)·cos(90°/z) - dp（奇數齒）
        var cos_b  = Math.cos(beta);
        var mt     = mn / cos_b;
        var d      = mt * z;
        var alpha_t = Math.atan(Math.tan(alpha_n) / cos_b);
        var db     = d * Math.cos(alpha_t);
        var sin_bb = Math.sin(beta) * Math.cos(alpha_n);
        var cos_bb = Math.sqrt(1 - sin_bb * sin_bb);

        var et_d  = (PI / (2 * z)) - (2 * x_int * Math.tan(alpha_n) / z);
        var inv_p = et_d - inv(alpha_t) + (dp / (db * cos_bb));

        if (inv_p <= 0) return '異常(inv_p≤0)';

        var LIMIT = 89 * PI / 180;
        var ap = Math.cbrt(3 * inv_p);
        if (ap >= LIMIT) return '異常(初始角過大)';

        for (var i = 0; i < 100; i++) {
            if (ap >= LIMIT || ap <= 0) return '異常(疊代發散)';
            var fp = Math.tan(ap) * Math.tan(ap);
            if (fp === 0) break;
            var an = ap - (Math.tan(ap) - ap - inv_p) / fp;
            var diff = Math.abs(an - ap);
            ap = an;
            if (diff <= 1e-10) break;
        }
        if (ap >= LIMIT || ap <= 0) return '異常(收斂失敗)';

        var dc = db / Math.cos(ap);
        var M  = (z % 2 === 0) ? dc - dp : dc * Math.cos(PI / (2 * z)) - dp;
        return gRound(M, 5);
    }

    // 外花鍵跨齒厚 Wk
    function calcWkExt(k, x, mn, z, alpha_n, beta) {
        var cos_b = Math.cos(beta);
        var alpha_t = Math.atan(Math.tan(alpha_n) / cos_b);
        return mn * Math.cos(alpha_n) * (PI * (k - 0.5) + z * inv(alpha_t))
             + 2 * x * mn * Math.sin(alpha_n);
    }

    // 外花鍵齒頂齒厚（負值 = 齒頂尖點）
    function tipThickExt(x, mn, z, alpha_n, beta) {
        var cos_b = Math.cos(beta);
        var mt = mn / cos_b;
        var d = mt * z;
        var alpha_t = Math.atan(Math.tan(alpha_n) / cos_b);
        var db = d * Math.cos(alpha_t);
        var da = d + 2 * mn * (1 + x);
        if (da <= db || da <= 0) return null;
        var aa = Math.acos(db / da);
        return da * (PI / (2 * z) + 2 * x * Math.tan(alpha_n) / z + inv(alpha_t) - inv(aa));
    }

    // 內花鍵小徑處齒厚（負值 = 齒頂尖點；null = 無漸開線）
    function tipThickInt(x_int, mn, z, alpha_n, beta) {
        var cos_b = Math.cos(beta);
        var mt = mn / cos_b;
        var d = mt * z;
        var alpha_t = Math.atan(Math.tan(alpha_n) / cos_b);
        var db = d * Math.cos(alpha_t);
        var di = d - 2 * mn * (1 + x_int);
        if (di <= 0 || di <= db) return null;
        var ai = Math.acos(db / di);
        return di * (PI / (2 * z) + 2 * x_int * Math.tan(alpha_n) / z + inv(alpha_t) - inv(ai));
    }

    // 根切最小轉位係數
    function undercutXmin(z, alpha_t) {
        return 1 - z * Math.sin(alpha_t) * Math.sin(alpha_t) / 2;
    }

    // ISO 286-1 IT 等級公差值（mm）
    function calcITmm(d_nom, it_num) {
        var D = Math.max(d_nom, 1);
        var i = 0.45 * Math.pow(D, 1 / 3) + 0.001 * D;  // μm
        var f = {5:7, 6:10, 7:16, 8:25, 9:40, 10:64, 11:100, 12:160, 13:250, 14:400};
        return gRound((f[it_num] || 40) * i / 1000, 6);
    }

    // 精度等級字串 → IT 等級數字
    function qualityToIT(q_str, std) {
        var q = parseInt(String(q_str).replace(/Q/i, '')) || 0;
        if (std === 'ANSIB922') {
            var m = {12:5, 11:6, 10:7, 9:8, 8:9, 7:10, 6:11, 5:12, 4:13, 3:14};
            return m[q] || 9;
        }
        var m2 = {4:5, 5:6, 6:7, 7:8, 8:9, 9:10};
        return m2[q] || 9;
    }

    // 查 DB 或 IT 公式，返回 {upperDev, tol, isEst}
    // 外花鍵: upperDev=es（≤0 for h/g/f/e）; tol=公差帶寬 T
    // 內花鍵: upperDev=EI（≥0 for H, 負值 for JS/K）; tol=T
    function getSplineTol(mn, d, q_str, fit_code, std, is_ext) {
        for (var i = 0; i < _splineTolRows.length; i++) {
            var r = _splineTolRows[i];
            if (r.standard !== std) continue;
            if (parseInt(r.is_external) !== (is_ext ? 1 : 0)) continue;
            if (r.quality_class !== String(q_str)) continue;
            if (r.fit_code !== fit_code) continue;
            var mgt  = (r.m_gt  !== null && r.m_gt  !== '') ? parseFloat(r.m_gt)  : -Infinity;
            var mlte = (r.m_lte !== null && r.m_lte !== '') ? parseFloat(r.m_lte) :  Infinity;
            if (mn > mgt && mn <= mlte) {
                return {upperDev: parseFloat(r.upper_dev_mm), tol: parseFloat(r.tol_mm), isEst: parseInt(r.is_estimate)===1};
            }
        }
        // 估算
        var it = qualityToIT(q_str, std);
        var T = calcITmm(d, it);
        var fd;
        if (is_ext) {
            switch (fit_code) {
                case 'h': fd = 0; break;
                case 'g': fd = -0.4 * T; break;
                case 'f': fd = -0.9 * T; break;
                case 'e': fd = -1.8 * T; break;
                case 'd': fd = -3.0 * T; break;
                case 'b': fd = -5.2 * T; break;  // DIN 5480 b 配合估算，建議以公差資料庫輸入精確值
                default:  fd = 0;
            }
        } else {
            switch (fit_code) {
                case 'H':  fd = 0;         break;
                case 'JS': fd = -T / 2;    break;
                case 'K':  fd = -0.3 * T;  break;
                default:   fd = 0;
            }
        }
        return {upperDev: gRound(fd, 6), tol: T, isEst: true};
    }

    // 內花鍵 dp 最大值（測棒中心圓 dc ≤ Df - dp）
    function findDpMaxInt(x_int, mn, z, alpha_n, beta, hf_star) {
        var cos_b = Math.cos(beta);
        var mt = mn / cos_b;
        var Df = mt * z + 2 * mn * (hf_star - x_int);
        var lo = 0.001, hi = Df / 2;
        for (var i = 0; i < 120; i++) {
            var dp_try = (lo + hi) / 2;
            var m_try = calcMInt(x_int, mn, z, alpha_n, beta, dp_try);
            if (typeof m_try === 'string') { hi = dp_try; continue; }
            var dc_try = m_try + dp_try;  // even-teeth approx: M = dc - dp → dc = M + dp
            if (dc_try < Df - dp_try) lo = dp_try; else hi = dp_try;
            if (hi - lo < 1e-6) break;
        }
        return gRound((lo + hi) / 2, 4);
    }

    // ── 外花鍵 ──────────────────────────────────────────────────────────────

    window.splineExtStdChange = function() {
        var std = gVal('sp-ext-std');
        var isAnsi = (std === 'ANSIB922');
        var isDIN  = (std === 'DIN5480');
        document.getElementById('sp-ext-mn-wrap').style.display  = isAnsi ? 'none' : '';
        document.getElementById('sp-ext-pd-wrap').style.display  = isAnsi ? '' : 'none';
        document.getElementById('sp-ext-dnom-wrap').style.display = isDIN  ? '' : 'none';
    };

    window.calcSplineExt = function() {
        var standard = gVal('sp-ext-std');
        var mn, pd_inch = null;
        if (standard === 'ANSIB922') {
            var pd = gFloat('sp-ext-pd');
            if (!pd || pd <= 0) { alert('請填寫 Pd（每英吋齒數）'); return; }
            mn = gRound(25.4 / pd, 6);
            pd_inch = pd;
        } else {
            mn = gFloat('sp-ext-mn');
        }
        if (!mn || mn <= 0) { alert('請填寫法向模數 mn'); return; }
        var z = gInt('sp-ext-z');
        if (!z || z < 2) { alert('齒數 z 需 ≥ 2'); return; }
        var an_deg = gFloat('sp-ext-an') || 30;
        if (an_deg <= 0 || an_deg >= 90) { alert('壓力角需介於 0°~90°'); return; }
        var alpha_n = an_deg * PI / 180;
        var bt_deg  = gFloat('sp-ext-bt') || 0;
        var beta    = bt_deg * PI / 180;
        var cos_b   = Math.cos(beta);
        var mt      = mn / cos_b;
        var alpha_t = Math.atan(Math.tan(alpha_n) / cos_b);
        var x       = (gFloat('sp-ext-x') !== null) ? gFloat('sp-ext-x') : 0;
        var root_form = gVal('sp-ext-root');
        var hf_star = (root_form === 'fillet') ? 1.5 : 1.25;
        var quality  = gVal('sp-ext-q') || '7';
        var fit_code = gVal('sp-ext-fit') || 'h';
        var dp_in    = gFloat('sp-ext-dp');
        var k_in     = gInt('sp-ext-k');

        // 幾何
        var d  = mt * z;
        // DIN 5480 為短齒型：齒冠 ha* = 0.4m
        // 若有輸入公稱直徑 d_N，改用精確式 da = d_N - 0.2m（等效，可交叉驗證）
        var d_nom = (standard === 'DIN5480') ? (gFloat('sp-ext-dnom') || 0) : 0;
        var da;
        if (standard === 'DIN5480') {
            da = (d_nom > 0)
                ? gRound(d_nom - 0.2 * mn, 4)
                : gRound(d + 2 * mn * (0.4 + x), 4);
        } else {
            da = d + 2 * mn * (1 + x);
        }
        var df = d - 2 * mn * (hf_star - x);
        var db = d * Math.cos(alpha_t);
        var h  = (da - df) / 2;

        // 檢核
        var warns = [];
        var x_min = undercutXmin(z, alpha_t);
        if (x < x_min - 0.001) warns.push('根切警告：x(' + fmtNum(x,4) + ') < x_min(' + fmtNum(x_min,4) + ')');
        var sa = tipThickExt(x, mn, z, alpha_n, beta);
        if (sa === null || sa <= 0) warns.push('齒頂尖點：齒頂齒厚 ≤ 0，請降低轉位係數');
        else if (sa < 0.2 * mn) warns.push('齒頂偏薄：齒頂齒厚 ' + fmtNum(sa,4) + ' mm（< 0.2mn）');

        // 測棒
        var dp_used = (dp_in && dp_in > 0) ? dp_in : gRound(1.68 * mn, 3);

        // Wk
        var Wk_nom = (k_in && k_in >= 1) ? calcWkExt(k_in, x, mn, z, alpha_n, beta) : null;

        // M
        var M_raw = calcM(x, mn, z, alpha_n, beta, dp_used);
        var M_nom = (typeof M_raw === 'string') ? null : M_raw;
        if (M_nom === null) warns.push('M 值計算異常：' + M_raw);

        // 公差
        var tol = getSplineTol(mn, d, quality, fit_code, standard, true);
        // 外花鍵：Wk_upper=Wk_nom+es, Wk_lower=Wk_nom+es-T; 同樣套用於 M
        var Wk_upper = (Wk_nom !== null) ? gRound(Wk_nom + tol.upperDev, 4) : null;
        var Wk_lower = (Wk_nom !== null) ? gRound(Wk_nom + tol.upperDev - tol.tol, 4) : null;
        var M_upper  = (M_nom !== null)  ? gRound(M_nom  + tol.upperDev, 4) : null;
        var M_lower  = (M_nom !== null)  ? gRound(M_nom  + tol.upperDev - tol.tol, 4) : null;

        // 存結果供配合驗算
        _splineExtResult = {standard:standard, mn:mn, z:z, alpha_n:alpha_n, alpha_t:alpha_t,
            beta:beta, x:x, root_form:root_form, d:d, da:da, df:df, db:db, h:h,
            k:k_in, dp:dp_used,
            Wk_nom:Wk_nom, Wk_upper:Wk_upper, Wk_lower:Wk_lower,
            M_nom:M_nom, M_upper:M_upper, M_lower:M_lower, tol:tol};

        // 輸出 DOM
        setOut('sp-ext-out-d',  fmtNum(d,4));  setOut('sp-ext-out-da', fmtNum(da,4));
        setOut('sp-ext-out-df', fmtNum(df,4)); setOut('sp-ext-out-db', fmtNum(db,4));
        setOut('sp-ext-out-h',  fmtNum(h,4));  setOut('sp-ext-out-dp-used', fmtNum(dp_used,4));

        var wkWrap = document.getElementById('sp-ext-out-wk-wrap');
        if (wkWrap) wkWrap.style.display = (Wk_nom !== null) ? '' : 'none';
        if (Wk_nom !== null) {
            setOut('sp-ext-out-wk-nom', fmtNum(Wk_nom,4));
            setOut('sp-ext-out-wk-up', fmtNum(Wk_upper,4), 'val-ok');
            setOut('sp-ext-out-wk-dn', fmtNum(Wk_lower,4), 'val-ok');
        }
        setOut('sp-ext-out-m-nom', M_nom !== null ? fmtNum(M_nom,4) : '異常', M_nom ? '' : 'val-err');
        setOut('sp-ext-out-m-up',  M_upper !== null ? fmtNum(M_upper,4) : '—', M_upper ? 'val-ok' : '');
        setOut('sp-ext-out-m-dn',  M_lower !== null ? fmtNum(M_lower,4) : '—', M_lower ? 'val-ok' : '');

        if (pd_inch) {
            var ai = document.getElementById('sp-ext-ansi-info');
            var ap = document.getElementById('sp-ext-ansi-pd');
            if (ai) ai.style.display = '';
            if (ap) ap.textContent = 'Pd=' + fmtNum(pd_inch,4) + ' teeth/in → m=' + fmtNum(mn,5) + ' mm';
        } else {
            var ai2 = document.getElementById('sp-ext-ansi-info');
            if (ai2) ai2.style.display = 'none';
        }

        var wEl = document.getElementById('sp-ext-warns');
        if (wEl) {
            if (warns.length) {
                wEl.innerHTML = warns.map(function(w){return '<div><i class="fa fa-exclamation-triangle"></i> '+escGear(w)+'</div>';}).join('');
                wEl.style.display = 'block';
            } else wEl.style.display = 'none';
        }
        var tolEl = document.getElementById('sp-ext-tol-src');
        if (tolEl) tolEl.innerHTML = tol.isEst
            ? '<span class="sp-est-badge">估算</span> 公差以 ISO 286-1 IT 公式估算，請依標準文件核對'
            : '<span class="sp-exact-badge">標準值</span> 使用公差資料庫中的精確值';

        // 清除換算欄
        setOut('sp-ext-conv-m-up',''); setOut('sp-ext-conv-m-dn','');
        updateSplineFitBtn();
    };

    window.clearSplineExt = function() {
        _splineExtResult = null;
        ['sp-ext-out-d','sp-ext-out-da','sp-ext-out-df','sp-ext-out-db','sp-ext-out-h',
         'sp-ext-out-dp-used','sp-ext-out-wk-nom','sp-ext-out-wk-up','sp-ext-out-wk-dn',
         'sp-ext-out-m-nom','sp-ext-out-m-up','sp-ext-out-m-dn',
         'sp-ext-conv-m-up','sp-ext-conv-m-dn'].forEach(function(id){setOut(id,'');});
        var wEl = document.getElementById('sp-ext-warns');
        if (wEl) wEl.style.display = 'none';
        var tolEl = document.getElementById('sp-ext-tol-src');
        if (tolEl) tolEl.innerHTML = '';
        updateSplineFitBtn();
    };

    window.calcSplineConvExt = function() {
        if (!_splineExtResult) { alert('請先計算外花鍵'); return; }
        var r = _splineExtResult;
        var dp2 = gFloat('sp-ext-conv-dp2');
        if (!dp2 || dp2 <= 0) { alert('請填寫新測棒直徑 dp2'); return; }
        var M2 = calcM(r.x, r.mn, r.z, r.alpha_n, r.beta, dp2);
        if (typeof M2 === 'string') { alert('計算異常：' + M2); return; }
        setOut('sp-ext-conv-m-up', fmtNum(gRound(M2 + r.tol.upperDev, 4), 4), 'val-ok');
        setOut('sp-ext-conv-m-dn', fmtNum(gRound(M2 + r.tol.upperDev - r.tol.tol, 4), 4), 'val-ok');
    };

    // ── 內花鍵 ──────────────────────────────────────────────────────────────

    window.splineIntStdChange = function() {
        var std = gVal('sp-int-std');
        var isAnsi = (std === 'ANSIB922');
        document.getElementById('sp-int-mn-wrap').style.display = isAnsi ? 'none' : '';
        document.getElementById('sp-int-pd-wrap').style.display = isAnsi ? '' : 'none';
    };

    window.calcSplineInt = function() {
        var standard = gVal('sp-int-std');
        var mn, pd_inch = null;
        if (standard === 'ANSIB922') {
            var pd = gFloat('sp-int-pd');
            if (!pd || pd <= 0) { alert('請填寫 Pd（每英吋齒數）'); return; }
            mn = gRound(25.4 / pd, 6);
            pd_inch = pd;
        } else {
            mn = gFloat('sp-int-mn');
        }
        if (!mn || mn <= 0) { alert('請填寫法向模數 mn'); return; }
        var z = gInt('sp-int-z');
        if (!z || z < 2) { alert('齒數 z 需 ≥ 2'); return; }
        var an_deg = gFloat('sp-int-an') || 30;
        if (an_deg <= 0 || an_deg >= 90) { alert('壓力角需介於 0°~90°'); return; }
        var alpha_n = an_deg * PI / 180;
        var bt_deg  = gFloat('sp-int-bt') || 0;
        var beta    = bt_deg * PI / 180;
        var cos_b   = Math.cos(beta);
        var mt      = mn / cos_b;
        var alpha_t = Math.atan(Math.tan(alpha_n) / cos_b);
        var x_int   = (gFloat('sp-int-x') !== null) ? gFloat('sp-int-x') : 0;
        var root_form = gVal('sp-int-root');
        var hf_star = (root_form === 'fillet') ? 1.5 : 1.25;
        var quality  = gVal('sp-int-q') || '7';
        var fit_code = gVal('sp-int-fit') || 'H';
        var dp_in    = gFloat('sp-int-dp');

        // 幾何
        var d  = mt * z;
        var di = d - 2 * mn * (1 + x_int);          // 小徑（齒頂）
        var Df = d + 2 * mn * (hf_star - x_int);     // 大徑（齒根）
        var db = d * Math.cos(alpha_t);
        var h  = (Df - di) / 2;

        // 檢核
        var warns = [];
        if (di <= 0) warns.push('錯誤：小徑 di ≤ 0，請減小轉位係數或增加齒數');
        if (di <= db && di > 0) warns.push('錯誤：小徑 di(' + fmtNum(di,4) + ') ≤ 基圓 db(' + fmtNum(db,4) + ')，壓力角需增大或轉位係數需減小');

        var sa_int = (di > db) ? tipThickInt(x_int, mn, z, alpha_n, beta) : null;
        if (di > db) {
            if (sa_int === null || sa_int <= 0) warns.push('齒頂尖點：小徑處齒厚 ≤ 0，轉位係數過大');
            else if (sa_int < 0.2 * mn) warns.push('齒頂偏薄：小徑處齒厚 ' + fmtNum(sa_int,4) + ' mm');
        }

        // 測棒
        var dp_used = (dp_in && dp_in > 0) ? dp_in : gRound(1.68 * mn, 3);

        // M 值（內花鍵）
        var M_nom = null;
        if (di > db) {
            var M_raw = calcMInt(x_int, mn, z, alpha_n, beta, dp_used);
            M_nom = (typeof M_raw === 'string') ? null : M_raw;
            if (M_nom === null) warns.push('M 值計算異常：' + M_raw);
        }

        // 測棒有效性
        if (M_nom !== null) {
            if (M_nom < di) warns.push('測棒過小：M(' + fmtNum(M_nom,4) + ') < 小徑(' + fmtNum(di,4) + ')，請增大 dp');
            else if (M_nom + dp_used > Df) warns.push('測棒過大：M+dp(' + fmtNum(M_nom+dp_used,4) + ') > 大徑(' + fmtNum(Df,4) + ')，請減小 dp');
        }

        // dp_max
        var dp_max = (di > db) ? findDpMaxInt(x_int, mn, z, alpha_n, beta, hf_star) : null;

        // 公差
        var tol = getSplineTol(mn, d, quality, fit_code, standard, false);
        // 內花鍵：M_lower = M_nom + EI, M_upper = M_nom + EI + T
        var M_lower = (M_nom !== null) ? gRound(M_nom + tol.upperDev, 4) : null;
        var M_upper = (M_nom !== null) ? gRound(M_nom + tol.upperDev + tol.tol, 4) : null;

        _splineIntResult = {standard:standard, mn:mn, z:z, alpha_n:alpha_n, alpha_t:alpha_t,
            beta:beta, x:x_int, root_form:root_form, d:d, di:di, Df:Df, db:db, h:h,
            dp:dp_used, dp_max:dp_max,
            M_nom:M_nom, M_lower:M_lower, M_upper:M_upper, tol:tol};

        setOut('sp-int-out-d',  fmtNum(d,4));  setOut('sp-int-out-di', fmtNum(di,4));
        setOut('sp-int-out-Df', fmtNum(Df,4)); setOut('sp-int-out-db', fmtNum(db,4));
        setOut('sp-int-out-h',  fmtNum(h,4));  setOut('sp-int-out-dp-used', fmtNum(dp_used,4));
        setOut('sp-int-out-dp-max', dp_max !== null ? fmtNum(dp_max,4) : '—');
        setOut('sp-int-out-m-nom', M_nom !== null ? fmtNum(M_nom,4) : '異常', M_nom ? '' : 'val-err');
        setOut('sp-int-out-m-lo', M_lower !== null ? fmtNum(M_lower,4) : '—', M_lower ? 'val-ok' : '');
        setOut('sp-int-out-m-up', M_upper !== null ? fmtNum(M_upper,4) : '—', M_upper ? 'val-ok' : '');

        if (pd_inch) {
            var ai = document.getElementById('sp-int-ansi-info');
            var ap = document.getElementById('sp-int-ansi-pd');
            if (ai) ai.style.display = '';
            if (ap) ap.textContent = 'Pd=' + fmtNum(pd_inch,4) + ' teeth/in → m=' + fmtNum(mn,5) + ' mm';
        } else {
            var ai2 = document.getElementById('sp-int-ansi-info');
            if (ai2) ai2.style.display = 'none';
        }

        var wEl = document.getElementById('sp-int-warns');
        if (wEl) {
            if (warns.length) {
                wEl.innerHTML = warns.map(function(w){return '<div><i class="fa fa-exclamation-triangle"></i> '+escGear(w)+'</div>';}).join('');
                wEl.style.display = 'block';
            } else wEl.style.display = 'none';
        }
        var tolEl = document.getElementById('sp-int-tol-src');
        if (tolEl) tolEl.innerHTML = tol.isEst
            ? '<span class="sp-est-badge">估算</span> 公差以 ISO 286-1 IT 公式估算，請依標準文件核對'
            : '<span class="sp-exact-badge">標準值</span> 使用公差資料庫中的精確值';

        setOut('sp-int-conv-m-lo',''); setOut('sp-int-conv-m-up','');
        updateSplineFitBtn();
    };

    window.clearSplineInt = function() {
        _splineIntResult = null;
        ['sp-int-out-d','sp-int-out-di','sp-int-out-Df','sp-int-out-db','sp-int-out-h',
         'sp-int-out-dp-used','sp-int-out-dp-max','sp-int-out-m-nom','sp-int-out-m-lo','sp-int-out-m-up',
         'sp-int-conv-m-lo','sp-int-conv-m-up'].forEach(function(id){setOut(id,'');});
        var wEl = document.getElementById('sp-int-warns');
        if (wEl) wEl.style.display = 'none';
        var tolEl = document.getElementById('sp-int-tol-src');
        if (tolEl) tolEl.innerHTML = '';
        updateSplineFitBtn();
    };

    window.calcSplineConvInt = function() {
        if (!_splineIntResult) { alert('請先計算內花鍵'); return; }
        var r = _splineIntResult;
        var dp2 = gFloat('sp-int-conv-dp2');
        if (!dp2 || dp2 <= 0) { alert('請填寫新測棒直徑 dp2'); return; }
        var M2 = calcMInt(r.x, r.mn, r.z, r.alpha_n, r.beta, dp2);
        if (typeof M2 === 'string') { alert('計算異常：' + M2); return; }
        setOut('sp-int-conv-m-lo', fmtNum(gRound(M2 + r.tol.upperDev, 4), 4), 'val-ok');
        setOut('sp-int-conv-m-up', fmtNum(gRound(M2 + r.tol.upperDev + r.tol.tol, 4), 4), 'val-ok');
    };

    // ── 配合驗算 ────────────────────────────────────────────────────────────

    function updateSplineFitBtn() {
        var btn = document.getElementById('gtab-sp-fit');
        if (!btn) return;
        var ok = (_splineExtResult !== null && _splineIntResult !== null);
        btn.disabled = !ok;
        btn.title = ok ? '' : '需先分別計算外/內花鍵';
    }

    window.calcSplineFit = function() {
        if (!_splineExtResult || !_splineIntResult) {
            alert('請先計算外花鍵和內花鍵'); return;
        }
        var ext = _splineExtResult, int_ = _splineIntResult;
        var msgs = [];
        if (Math.abs(ext.mn - int_.mn) > 0.0001) msgs.push('模數不同 (外:'+fmtNum(ext.mn,4)+' / 內:'+fmtNum(int_.mn,4)+')');
        if (ext.z !== int_.z)                    msgs.push('齒數不同 (外:'+ext.z+' / 內:'+int_.z+')');
        if (Math.abs(ext.alpha_n - int_.alpha_n) > 0.0001) msgs.push('壓力角不同');
        if (Math.abs(ext.beta - int_.beta) > 0.0001)       msgs.push('螺旋角不同');

        var warnEl = document.getElementById('sp-fit-param-warn');
        var resArea = document.getElementById('sp-fit-result-area');
        if (msgs.length) {
            if (warnEl) { warnEl.innerHTML = '<i class="fa fa-times-circle"></i> 參數不一致，無法驗算：' + msgs.join('；'); warnEl.style.display = 'block'; }
            if (resArea) resArea.style.display = 'none';
            return;
        }
        if (warnEl) warnEl.style.display = 'none';

        var mn = ext.mn, alpha_n = ext.alpha_n, alpha_t = ext.alpha_t;
        var x_ext = ext.x, x_int = int_.x;

        // 節圓切線齒厚 / 齒槽（法向）
        var s_nom = mn * (PI / 2 + 2 * x_ext * Math.tan(alpha_n));  // 外花鍵齒厚
        var e_nom = mn * (PI / 2 - 2 * x_int * Math.tan(alpha_n));  // 內花鍵槽寬
        var j_nom = e_nom - s_nom;  // 公稱間隙

        // 公差換算到齒厚/槽寬（Δs = ΔWk/cos(α) ≈ ΔM/cos(α)）
        var cos_an = Math.cos(alpha_n);
        var T_ext = ext.tol.tol;
        var T_int = int_.tol.tol;
        var fd_ext = ext.tol.upperDev;   // ≤ 0，外花鍵上偏差
        var fd_int = int_.tol.upperDev;  // ≥ 0 for H，內花鍵下偏差 EI

        // 外花鍵齒厚範圍（較大 = 較厚）
        var s_max = s_nom + fd_ext / cos_an;
        var s_min = s_max - T_ext / cos_an;
        // 內花鍵槽寬範圍（較大 = 較寬）
        var e_min = e_nom + fd_int / cos_an;
        var e_max = e_min + T_int / cos_an;

        var j_min = e_min - s_max;
        var j_max = e_max - s_min;

        // 徑向間隙 = j / (2*tan(αt))
        var tan_at = Math.tan(alpha_t);
        var jr_nom = (tan_at > 0.001) ? j_nom / (2 * tan_at) : 0;
        var jr_min = (tan_at > 0.001) ? j_min / (2 * tan_at) : 0;
        var jr_max = (tan_at > 0.001) ? j_max / (2 * tan_at) : 0;

        // 判斷
        var fitClass, fitCls;
        if (j_min > 1e-5)       { fitClass = '間隙配合 (Clearance Fit)';    fitCls = 'sp-fit-clearance'; }
        else if (j_max < -1e-5) { fitClass = '過盈配合 (Interference Fit)'; fitCls = 'sp-fit-interference'; }
        else                    { fitClass = '過渡配合 (Transition Fit)';   fitCls = 'sp-fit-transition'; }

        setOut('sp-fit-mn', fmtNum(mn,4));
        setOut('sp-fit-z',  ext.z);
        setOut('sp-fit-an', fmtNum(alpha_n*180/PI,2)+'°');
        setOut('sp-fit-x-ext', fmtNum(x_ext,4));
        setOut('sp-fit-x-int', fmtNum(x_int,4));
        setOut('sp-fit-j-nom', fmtNum(j_nom,5));
        setOut('sp-fit-j-min', fmtNum(j_min,5), j_min>=0?'val-ok':'val-err');
        setOut('sp-fit-j-max', fmtNum(j_max,5), j_max>=0?'val-ok':'val-err');
        setOut('sp-fit-jr-nom', fmtNum(jr_nom,5));
        setOut('sp-fit-jr-min', fmtNum(jr_min,5), jr_min>=0?'val-ok':'val-err');
        setOut('sp-fit-jr-max', fmtNum(jr_max,5), jr_max>=0?'val-ok':'val-err');

        // 測量值顯示
        var extDp = document.getElementById('sp-fit-ext-dp'); if(extDp) extDp.textContent = fmtNum(ext.dp,4);
        var intDp = document.getElementById('sp-fit-int-dp'); if(intDp) intDp.textContent = fmtNum(int_.dp,4);
        setOut('sp-fit-ext-m-up', ext.M_upper!==null?fmtNum(ext.M_upper,4):'—');
        setOut('sp-fit-ext-m-dn', ext.M_lower!==null?fmtNum(ext.M_lower,4):'—');
        setOut('sp-fit-int-m-dn', int_.M_lower!==null?fmtNum(int_.M_lower,4):'—');
        setOut('sp-fit-int-m-up', int_.M_upper!==null?fmtNum(int_.M_upper,4):'—');

        var clsEl = document.getElementById('sp-fit-class');
        if (clsEl) { clsEl.className = 'sp-fit-result ' + fitCls; clsEl.textContent = fitClass; }

        var noteEl = document.getElementById('sp-fit-tol-note');
        if (noteEl) noteEl.textContent = (ext.tol.isEst||int_.tol.isEst) ? '⚠ 公差為估算值，結果僅供參考，請依標準核對' : '公差使用標準精確值';

        if (resArea) resArea.style.display = 'block';
    };

    // ── 公差資料管理 ────────────────────────────────────────────────────────

    function renderSplineTolTable(rows) {
        _splineTolRows = rows || [];
        var tbody = document.getElementById('sp-tol-tbl-body');
        if (!tbody) return;
        if (!rows || rows.length === 0) {
            tbody.innerHTML = '<tr><td colspan="9" style="color:#aaa;text-align:center;padding:8px;">（暫無資料）</td></tr>';
            return;
        }
        var std_names = {ISO4156:'ISO 4156', DIN5480:'DIN 5480', ANSIB922:'ANSI B92.2'};
        tbody.innerHTML = rows.map(function(r) {
            var isEst = parseInt(r.is_estimate) === 1;
            var badge = isEst ? '<span class="sp-est-badge">估算</span>' : '<span class="sp-exact-badge">精確</span>';
            var mRange = (r.m_gt!==null&&r.m_gt!=='') ? (r.m_gt+'~'+r.m_lte) : '—';
            return '<tr id="sp-tol-tr-'+r.id+'">' +
                '<td>'+(std_names[r.standard]||r.standard)+'</td>'+
                '<td>'+(parseInt(r.is_external)?'外':'內')+'</td>'+
                '<td>'+escGear(r.quality_class)+'</td>'+
                '<td>'+escGear(r.fit_code)+'</td>'+
                '<td>'+mRange+'</td>'+
                '<td>'+fmtNum(parseFloat(r.upper_dev_mm),5)+'</td>'+
                '<td>'+fmtNum(parseFloat(r.tol_mm),5)+'</td>'+
                '<td>'+badge+'</td>'+
                '<td><button class="btn-gear-edit" onclick="openSplineTolForm('+r.id+')">編輯</button> '+
                '<button class="btn-gear-del" onclick="deleteSplineTolRow('+r.id+')">刪除</button></td></tr>';
        }).join('');
    }

    window.openSplineTolForm = function(rid) {
        var fa = document.getElementById('sp-tol-form-area');
        if (fa) fa.style.display = 'block';
        var errEl = document.getElementById('sp-tol-form-err');
        if (errEl) errEl.textContent = '';
        if (rid === 0) {
            ['sp-tol-f-std','sp-tol-f-qc','sp-tol-f-fc','sp-tol-f-mgt','sp-tol-f-mlte',
             'sp-tol-f-udev','sp-tol-f-tol','sp-tol-f-notes'].forEach(function(id){var el=document.getElementById(id);if(el)el.value='';});
            var ei = document.getElementById('sp-tol-f-isext'); if(ei) ei.value='1';
            var es = document.getElementById('sp-tol-f-isest'); if(es) es.value='1';
            var id = document.getElementById('sp-tol-f-id'); if(id) id.value='0';
        } else {
            var row = null;
            for(var i=0;i<_splineTolRows.length;i++){if(parseInt(_splineTolRows[i].id)===rid){row=_splineTolRows[i];break;}}
            if (!row) return;
            var setV = function(id,v){var el=document.getElementById(id);if(el)el.value=v;};
            setV('sp-tol-f-id',     row.id);
            setV('sp-tol-f-std',    row.standard);
            setV('sp-tol-f-isext',  row.is_external);
            setV('sp-tol-f-qc',     row.quality_class);
            setV('sp-tol-f-fc',     row.fit_code);
            setV('sp-tol-f-mgt',    row.m_gt||'');
            setV('sp-tol-f-mlte',   row.m_lte||'');
            setV('sp-tol-f-udev',   row.upper_dev_mm);
            setV('sp-tol-f-tol',    row.tol_mm);
            setV('sp-tol-f-isest',  row.is_estimate);
            setV('sp-tol-f-notes',  row.source_notes||'');
        }
    };

    window.closeSplineTolForm = function() {
        var fa = document.getElementById('sp-tol-form-area'); if(fa) fa.style.display='none';
    };

    window.saveSplineTolRow = function() {
        var errEl = document.getElementById('sp-tol-form-err');
        var qc = gVal('sp-tol-f-qc'), fc = gVal('sp-tol-f-fc');
        if (!qc || !fc) { if(errEl) errEl.textContent='精度等級和配合代號必填'; return; }
        var data = {action:'spline_save_tol',
            id: gVal('sp-tol-f-id')||'0', standard: gVal('sp-tol-f-std'),
            is_external: gVal('sp-tol-f-isext'), quality_class: qc, fit_code: fc,
            m_gt: gVal('sp-tol-f-mgt'), m_lte: gVal('sp-tol-f-mlte'),
            upper_dev_mm: gVal('sp-tol-f-udev'), tol_mm: gVal('sp-tol-f-tol'),
            is_estimate: gVal('sp-tol-f-isest'), source_notes: gVal('sp-tol-f-notes')};
        gearAjax.post(data, function(res){
            if (res.success) { renderSplineTolTable(res.rows); closeSplineTolForm(); }
            else { if(errEl) errEl.textContent = res.message||'儲存失敗'; }
        }, 'json').fail(function(){ if(errEl) errEl.textContent='請求失敗'; });
    };

    window.deleteSplineTolRow = function(rid) {
        if (!confirm('確定刪除此公差資料？')) return;
        gearAjax.post({action:'spline_delete_tol', id:rid}, function(res){
            if (res.success) renderSplineTolTable(res.rows);
            else alert(res.message||'刪除失敗');
        }, 'json');
    };

    // ── 花鍵工具初始化 ──────────────────────────────────────────────────────

    window.initSplineTool = function() {
        gearAjax.post({action:'spline_init'}, function(res){
            if (res && res.success) renderSplineTolTable(res.tol_rows);
        }, 'json');
    };

    // ══ ISO 公差代號解析 ════════════════════════════════════════════════════
    function parseISOTol(nominal, code) {
        // 回傳 {upper, lower}（mm），正值=正公差，負值=負公差
        code = (code || '').trim();
        if (!code) return null;
        var m = code.match(/^([A-Za-z]+)(\d+)$/);
        if (!m) return null;
        var letter = m[1], grade = parseInt(m[2]);
        // IT 值（μm），按尺寸分段索引: <=3,3-6,6-10,10-18,18-30,30-50,50-80,80-120
        var IT = {5:[4,5,6,8,9,11,13,15], 6:[6,8,9,11,13,16,19,22],
                  7:[10,12,15,18,21,25,30,35], 8:[14,18,22,27,33,39,46,54],
                  9:[25,30,36,43,52,62,74,87], 10:[40,48,58,70,84,100,120,140],
                  11:[60,75,90,110,130,160,190,220]};
        // 基本偏差（μm），軸 (小寫)
        var FD = { e:[-14,-20,-25,-32,-40,-50,-60,-72], f:[-6,-10,-13,-16,-20,-25,-30,-36],
                   g:[-2,-4,-5,-6,-7,-9,-10,-12], h:[0,0,0,0,0,0,0,0],
                   k:[0,1,1,1,2,2,2,3], m:[2,4,6,7,8,9,11,13],
                   n:[4,8,10,12,15,17,20,23], p:[6,12,15,18,22,26,32,37] };
        var idx = nominal<=3?0:nominal<=6?1:nominal<=10?2:nominal<=18?3:
                  nominal<=30?4:nominal<=50?5:nominal<=80?6:7;
        var it = (IT[grade] ? IT[grade][idx] : null);
        if (it === null) return null;
        var isHole = (letter === letter.toUpperCase());
        if (isHole) {
            if (letter === 'H') return { upper: it/1000, lower: 0 };
            if (letter === 'JS') return { upper: it/2000, lower: -it/2000 };
            return null;
        } else {
            var fd = FD[letter.toLowerCase()];
            if (!fd) return null;
            var es = fd[idx] / 1000;
            return { upper: es, lower: es - it/1000 };
        }
    }

    // ══ 栓槽跨銷值計算 ══════════════════════════════════════════════════════

    window.calcSplineRect = function() {
        var N = parseInt(gVal('sr-n'));
        var D = parseFloat(gVal('sr-D'));
        var d = parseFloat(gVal('sr-d'));
        var B = parseFloat(gVal('sr-B'));
        if (isNaN(N)||N<2||isNaN(D)||isNaN(d)||isNaN(B)||D<=0||d<=0||B<=0||D<=d) {
            document.getElementById('sr-warn').style.display='block';
            document.getElementById('sr-warn').textContent='請確認輸入：D > d > 0，N ≥ 2，B > 0';
            return;
        }
        document.getElementById('sr-warn').style.display='none';

        // 公差
        var Dup = parseFloat(gVal('sr-D-up'))||0, Ddn = parseFloat(gVal('sr-D-dn'))||0;
        var dup = parseFloat(gVal('sr-d-up'))||0, ddn = parseFloat(gVal('sr-d-dn'))||0;
        var D_max = D + Math.abs(Dup), D_min = D - Math.abs(Ddn);
        var d_max = d + Math.abs(dup), d_min = d - Math.abs(ddn);

        // 槽寬公差代號解析
        var btolCode = gVal('sr-B-tol');
        var B_max = B, B_min = B;
        var btolInfo = '';
        if (btolCode) {
            var btol = parseISOTol(B, btolCode);
            if (btol) {
                B_max = gRound(B + btol.upper, 4);
                B_min = gRound(B + btol.lower, 4);
                btolInfo = ' [' + btolCode + ': ' + fmtNum(btol.upper*1000,1) + '/' + fmtNum(btol.lower*1000,1) + ' μm]';
            } else {
                document.getElementById('sr-warn').style.display='block';
                document.getElementById('sr-warn').textContent='公差代號「' + btolCode + '」無法解析，請檢查格式（如 e8、H7）';
                return;
            }
        }

        var isShaft = (document.querySelector('input[name="sr-type"]:checked').value === 'shaft');
        var isEven  = (N % 2 === 0);
        var depth   = (D - d) / 2;

        // ── 跨銷 ────────────────────────────────────────────────────────────
        var dp_max_allowed = Math.min(B_min, depth);
        var dp_min_allowed = Math.max(0.5 * B_min, 0.5);
        var pinOK = (dp_min_allowed <= dp_max_allowed);

        var dpCustom = parseFloat(gVal('sr-dp-custom'));
        var dp_rec = pinOK ? gRound(Math.min(0.9 * B_min, dp_max_allowed * 0.95), 3) : 0;
        if (dp_rec < dp_min_allowed && pinOK) dp_rec = dp_min_allowed;
        var dp = (!isNaN(dpCustom) && dpCustom > 0) ? dpCustom : dp_rec;

        function calcM(D_v, d_v, dp_v) {
            if (isShaft) return isEven ? (d_v + 2*dp_v) : ((d_v + D_v)/2 + dp_v);
            return isEven ? (D_v - 2*dp_v) : ((D_v + d_v)/2 - dp_v);
        }

        var M_nom, M_upper, M_lower, methodStr;
        if (!pinOK) {
            M_nom = null;
        } else {
            M_nom   = calcM(D, d, dp);
            M_upper = calcM(D_max, d_max, dp);
            M_lower = calcM(D_min, d_min, dp);
            if (!isShaft && isEven) { var t=M_upper; M_upper=M_lower; M_lower=t; }
        }
        methodStr = isShaft
            ? (isEven ? '偶數槽：兩對面槽各放一球，量兩球外緣' : '奇數槽：一球放槽，量至對面齒外緣')
            : (isEven ? '偶數槽：兩對面槽各放一球，量兩球最小內距' : '奇數槽：一球放槽，量至對面齒根');

        // ── 齒厚 ────────────────────────────────────────────────────────────
        function calcTooth(D_v, d_v, B_v) {
            // 齒的圓心角（在小徑圓上）
            if (B_v >= d_v) return null; // 槽寬超過小徑，無齒
            var slotHalfAngle = Math.asin(B_v / d_v);
            var toothAngle = 2*Math.PI/N - 2*slotHalfAngle;
            if (toothAngle <= 0) return null; // 槽太寬，無齒
            // 齒厚弦長（在小徑圓）
            return d_v * Math.sin(toothAngle / 2);
        }

        var tooth_nom   = calcTooth(D, d, B);
        var tooth_upper = calcTooth(D_max, d_max, B_min); // d大B小→齒最厚
        var tooth_lower = calcTooth(D_min, d_min, B_max); // d小B大→齒最薄

        // ── 輸出 ─────────────────────────────────────────────────────────────
        var elOut = function(id,v,cls){
            var el = document.getElementById(id);
            el.textContent = v;
            el.className = 'gear-output-val' + (cls?' '+cls:'');
        };

        elOut('sr-dp-range', pinOK
            ? fmtNum(dp_min_allowed,3)+' ~ '+fmtNum(dp_max_allowed,3)+' mm'
            : '無法量測（槽深/槽寬不足）', pinOK?'':'val-err');
        elOut('sr-dp-rec', pinOK
            ? fmtNum(dp_rec,3)+' mm' + ((!isNaN(dpCustom)&&dpCustom>0)?' ★自訂 '+dp+' mm':'')
            : '—');

        elOut('sr-M-nom', M_nom!==null ? fmtNum(M_nom,4)+' mm' : '無法量測', M_nom!==null?'val-ok':'val-err');
        var hasTol = Dup||Ddn||dup||ddn||btolCode;
        elOut('sr-M-range', M_nom===null ? '無法量測'
            : (!hasTol ? '（未輸入公差）'
            : fmtNum(Math.min(M_upper,M_lower),4)+' ~ '+fmtNum(Math.max(M_upper,M_lower),4)+' mm'),
            M_nom!==null&&hasTol?'val-ok':'');
        var mEl = document.getElementById('sr-method');
        if (M_nom !== null) {
            mEl.style.display = '';
            mEl.innerHTML = '▸ ' + methodStr + (btolInfo ? '<br><span style="color:#888;">公差' + btolInfo + '</span>' : '');
        } else {
            mEl.style.display = 'none';
        }

        elOut('sr-tooth-nom', tooth_nom!==null ? fmtNum(tooth_nom,4)+' mm' : '無法量測', tooth_nom!==null?'val-ok':'val-err');
        elOut('sr-tooth-range', tooth_nom===null ? '無法量測'
            : (!hasTol ? '（未輸入公差）'
            : fmtNum(Math.min(tooth_upper||0,tooth_lower||0),4)+' ~ '+fmtNum(Math.max(tooth_upper||0,tooth_lower||0),4)+' mm'),
            tooth_nom!==null&&hasTol?'val-ok':'');

        if (dp > dp_max_allowed && pinOK) {
            document.getElementById('sr-warn').style.display='block';
            document.getElementById('sr-warn').textContent='自訂球徑 dp='+dp+' 超出可用範圍 ['+fmtNum(dp_min_allowed,3)+', '+fmtNum(dp_max_allowed,3)+']';
        }
    };

    // ══ 出尾計算 ════════════════════════════════════════════════════════════

    function calcTailGeo(R_hob, H) {
        if (H <= 0 || R_hob <= 0) return null;
        if (H >= R_hob) return null; // 肩部超過滾刀半徑，無法加工
        var inner = R_hob * R_hob - (R_hob - H) * (R_hob - H);
        return Math.sqrt(inner);
    }

    function drawTailSVG(Ds, Dr, Da, U, L_geo, L_total) {
        var svg = document.getElementById('tail-svg');
        if (!svg) return;
        var VW = 440, VH = 300;
        var R_hob = Da / 2, H = (Ds - Dr) / 2;
        var R_s = Ds / 2, R_r = Dr / 2;

        // ── 版面設計 ─────────────────────────────────────────────────────
        // 圓心在 (Z=0, R=R_r+R_hob)——南四分點貼齒底徑 R_r
        // 在肩部高度 R_s 的左側截點恰好是 (-L_geo, R_s)，即出尾切點
        var spW  = Math.max(R_r * 2.5, 10);
        var zMin = -(L_geo + R_hob * 0.2);    // 含出尾切點左側空白
        var zMax = spW;
        var rMin = 0;
        // 圓弧只畫 30°，弧端最高在 R_r + R_hob*cos30° ≈ R_r + 0.866*R_hob（仍低於圓心）
        var rMax = R_r + R_hob * 1.05;        // 圓心 + 少量餘白即可

        var padL = 22, padR = 14, padT = 16, padB = 28;
        var sc = Math.min((VW - padL - padR) / (zMax - zMin),
                          (VH - padT - padB) / (rMax - rMin));
        // 水平居中：重新計算 padL 使內容置中
        padL = Math.floor((VW - (zMax - zMin) * sc) / 2);

        function sx(z) { return padL + (z - zMin) * sc; }
        function sy(r) { return VH - padB - (r - rMin) * sc; }

        var parts = [];
        var defs = '<defs>'
            + '<marker id="ta"  markerWidth="7" markerHeight="7" refX="6" refY="3.5" orient="auto"><path d="M0,0 L7,3.5 L0,7 Z" fill="#555"/></marker>'
            + '<marker id="ta2" markerWidth="7" markerHeight="7" refX="1" refY="3.5" orient="auto-start-reverse"><path d="M0,0 L7,3.5 L0,7 Z" fill="#555"/></marker>'
            + '</defs>';

        // ── 工件軸線 ─────────────────────────────────────────────────────
        parts.push('<line x1="'+sx(zMin-2)+'" y1="'+sy(0)+'" x2="'+sx(zMax+2)+'" y2="'+sy(0)+'" stroke="#ddd" stroke-width="1" stroke-dasharray="6,3"/>');

        // ── 肩部段（左側，R=0~R_s）──────────────────────────────────────
        var shW = -zMin - R_hob * 0.05;
        parts.push('<rect x="'+sx(-shW)+'" y="'+sy(R_s)+'" width="'+(shW*sc).toFixed(1)+'" height="'+(R_s*sc).toFixed(1)+'" fill="#dce8f8" stroke="#1565c0" stroke-width="1.5"/>');

        // ── 花鍵段（右側，R=0~R_r）──────────────────────────────────────
        parts.push('<rect x="'+sx(0)+'" y="'+sy(R_r)+'" width="'+(spW*sc).toFixed(1)+'" height="'+(R_r*sc).toFixed(1)+'" fill="#e8f5e9" stroke="#388e3c" stroke-width="1.5"/>');

        // ── 肩部面虛線（Z=0）─────────────────────────────────────────────
        parts.push('<line x1="'+sx(0)+'" y1="'+sy(R_s*1.08)+'" x2="'+sx(0)+'" y2="'+sy(0)+'" stroke="#e53935" stroke-width="1.2" stroke-dasharray="5,3"/>');

        // ── 滾刀：從南四分點往肩部(出尾)方向畫100°圓弧 ─────────────────
        var hcx = sx(0), hcy = sy(R_r + R_hob);
        var R_px = R_hob * sc;
        var arcSY = hcy + R_px;
        var arcEX = hcx - R_px * Math.sin(30 * Math.PI / 180);
        var arcEY = hcy + R_px * Math.cos(30 * Math.PI / 180);
        parts.push('<path d="M '+hcx.toFixed(1)+' '+arcSY.toFixed(1)+' A '+R_px.toFixed(1)+' '+R_px.toFixed(1)+' 0 0 1 '+arcEX.toFixed(1)+' '+arcEY.toFixed(1)+'" fill="none" stroke="#1976d2" stroke-width="2"/>');

        // ── 圓心標記 ─────────────────────────────────────────────────────
        parts.push('<circle cx="'+hcx+'" cy="'+hcy+'" r="3.5" fill="#1976d2"/>');
        parts.push('<line x1="'+(hcx-10)+'" y1="'+hcy+'" x2="'+(hcx+10)+'" y2="'+hcy+'" stroke="#1976d2" stroke-width="1"/>');
        parts.push('<line x1="'+hcx+'" y1="'+(hcy-10)+'" x2="'+hcx+'" y2="'+(hcy+10)+'" stroke="#1976d2" stroke-width="1"/>');

        // ── 南四分點（圓底，貼齒底徑 R_r）───────────────────────────────
        var southX = sx(0), southY = sy(R_r);
        parts.push('<circle cx="'+southX+'" cy="'+southY+'" r="4" fill="#1976d2"/>');

        // ── 出尾切點（圓在肩部高度 R_s 的左側截點，= (-L_geo, R_s)）──────
        var tanX = sx(-L_geo), tanY = sy(R_s);
        parts.push('<circle cx="'+tanX+'" cy="'+tanY+'" r="5" fill="#e53935"/>');

        // ── 肩部角點（Z=0, R=R_s）———————與切點同高，肩部面上 ───────────
        var cornerX = sx(0), cornerY = sy(R_s);
        parts.push('<circle cx="'+cornerX+'" cy="'+cornerY+'" r="4" fill="#e53935"/>');

        // ── 出尾尺寸線（(-L_geo, R_s) ~ (0, R_s)，在肩部高度）──────────
        var dimY = tanY;   // 就在切點同一水平（R_s 高度）
        parts.push('<line x1="'+tanX+'" y1="'+dimY+'" x2="'+cornerX+'" y2="'+dimY+'" stroke="#555" stroke-width="1.3" marker-start="url(#ta2)" marker-end="url(#ta)"/>');
        parts.push('<line x1="'+cornerX+'" y1="'+sy(R_s*1.05)+'" x2="'+cornerX+'" y2="'+(dimY-4)+'" stroke="#888" stroke-width="0.8"/>');
        parts.push('<line x1="'+tanX+'"   y1="'+sy(R_s*1.05)+'" x2="'+tanX+'"   y2="'+(dimY-4)+'" stroke="#888" stroke-width="0.8"/>');
        parts.push('<text x="'+((tanX+cornerX)/2)+'" y="'+(dimY-20)+'" text-anchor="middle" font-size="11" fill="#c0392b" font-weight="700">幾何出尾 '+fmtNum(L_geo,3)+'</text>');

        // ── H 尺寸（肩部步高，標在肩部左側）────────────────────────────
        var shDispW = Math.min(-zMin * 0.55, (-zMin - L_geo) * 0.8 + L_geo * 0.1);
        var hDimX = sx(-shDispW);
        parts.push('<line x1="'+hDimX+'" y1="'+sy(R_r)+'" x2="'+hDimX+'" y2="'+sy(R_s)+'" stroke="#e67e22" stroke-width="1" marker-start="url(#ta2)" marker-end="url(#ta)"/>');
        parts.push('<text x="'+(hDimX-25)+'" y="'+((sy(R_r)+sy(R_s))/2+4)+'" text-anchor="end" font-size="10" fill="#e67e22">H='+fmtNum(H,3)+'</text>');

        // ── 標籤 ─────────────────────────────────────────────────────────
        // Da：沿圓心→南四分點的半徑畫虛線，在右側中點標示直徑
        var rMidY = (hcy + arcSY) / 2;
        parts.push('<line x1="'+hcx.toFixed(1)+'" y1="'+(hcy+5).toFixed(1)+'" x2="'+hcx.toFixed(1)+'" y2="'+(arcSY-5).toFixed(1)+'" stroke="#1976d2" stroke-width="0.8" stroke-dasharray="3,2"/>');
        parts.push('<text x="'+(hcx+5).toFixed(1)+'" y="'+(rMidY+4).toFixed(1)+'" text-anchor="start" font-size="10" fill="#1565c0" font-weight="600">Da='+fmtNum(Da,2)+'</text>');
        parts.push('<text x="'+sx(spW*0.42)+'" y="'+(sy(0)-10)+'" text-anchor="middle" font-size="10" fill="#2e7d32">Dr='+fmtNum(Dr,2)+'</text>');
        parts.push('<text x="'+sx(-(-zMin)*0.55)+'" y="'+(sy(0)-10)+'" text-anchor="middle" font-size="10" fill="#1565c0">Ds='+fmtNum(Ds,2)+'</text>');
        parts.push('<text x="'+(VW-4)+'" y="'+(VH-4)+'" text-anchor="end" font-size="11" fill="#6a1b9a" font-weight="600">出尾(退刀 '+fmtNum(U,2)+')= '+fmtNum(L_total,3)+' mm</text>');

        svg.innerHTML = defs + parts.join('');
    }

    window.calcTailA = function() {
        var Ds = parseFloat(gVal('tail-ds'));
        var Dr = parseFloat(gVal('tail-dr'));
        var Da = parseFloat(gVal('tail-da-a'));
        var U  = parseFloat(gVal('tail-u')) || 0;
        var warn = document.getElementById('tail-warn');
        warn.style.display = 'none';

        if (isNaN(Ds)||isNaN(Dr)||isNaN(Da)||Ds<=Dr||Da<=0) {
            warn.style.display='block'; warn.textContent='請確認：肩部直徑 > 齒根直徑，且刀具外徑 > 0'; return;
        }
        var H = (Ds - Dr) / 2;
        var R_hob = Da / 2;
        if (H >= R_hob) {
            warn.style.display='block'; warn.textContent='肩部高差 H='+fmtNum(H,3)+' mm 超過刀具半徑 R='+fmtNum(R_hob,3)+' mm，幾何上無法加工，需換小刀具。'; return;
        }
        var L_geo = calcTailGeo(R_hob, H);
        var L_total = gRound(L_geo + U, 4);
        document.getElementById('tail-geo').textContent   = fmtNum(L_geo, 4) + ' mm';
        document.getElementById('tail-total').textContent = fmtNum(L_total, 4) + ' mm';
        document.getElementById('tail-geo').className   = 'gear-output-val val-ok';
        document.getElementById('tail-total').className = 'gear-output-val val-ok';
        drawTailSVG(Ds, Dr, Da, U, L_geo, L_total);
    };

    window.calcTailB = function() {
        var Ds     = parseFloat(gVal('tail-ds'));
        var Dr     = parseFloat(gVal('tail-dr'));
        var U      = parseFloat(gVal('tail-u')) || 0;
        var target = parseFloat(gVal('tail-target'));
        var warn   = document.getElementById('tail-warn');
        warn.style.display = 'none';

        if (isNaN(Ds)||isNaN(Dr)||isNaN(target)||Ds<=Dr||target<=U) {
            warn.style.display='block'; warn.textContent='請確認：肩部直徑 > 齒根直徑，且目標出尾長度 > 退刀量'; return;
        }
        var H   = (Ds - Dr) / 2;
        var L   = target - U; // 幾何出尾部分
        // Da_max = (L² + H²) / H
        var Da_max = (L*L + H*H) / H;
        document.getElementById('tail-da-max').textContent = fmtNum(Da_max, 3) + ' mm（建議使用 ≤ 此值的刀具）';
        document.getElementById('tail-da-max').className = 'gear-output-val val-ok';

        // 畫圖：用建議最大 Da 畫示意圖
        var L_geo = calcTailGeo(Da_max/2, H);
        drawTailSVG(Ds, Dr, Da_max, U, L_geo || L, target);
    };

})();
</script>
<?php endif; ?>
