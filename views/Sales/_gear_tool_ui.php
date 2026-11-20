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

// 砂輪/刀具干涉 3D 模擬（出尾計算的 3D 版本）：出尾計算分頁右側那顆按鈕要不要出現。
// 元件本身在檔尾才 include（它會自己做重複 include 防護），但按鈕在上面就要輸出，
// 所以權限必須在這裡先算好。元件的重量級函式庫（o3dv 1MB）是「點開才載入」的，
// 所以掛上這顆按鈕不會增加訂單追蹤／批圖編輯器的頁面載入成本。
require_once __DIR__ . '/../../src/common/grind3d_tool_lib.php';
if (!isset($can_grind3d)) {
    $__g3_pdo0 = null;
    foreach ([$GEAR_TOOL_PDO ?? null, $GRIND3D_TOOL_PDO ?? null, $pdo ?? null, $db ?? null] as $__c3) {
        if ($__c3 instanceof PDO) { $__g3_pdo0 = $__c3; break; }
    }
    $can_grind3d = grind3d_tool_can_use($__g3_pdo0, intval($_SESSION['id'] ?? 0));
}

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
// 3D 預覽用的函式庫網址（o3dv，點開才載入）：同樣推成網站絕對路徑
$__gt3_dir  = __DIR__ . '/../../resource/js/';
$__gt3_fs   = str_replace(chr(92), '/', (string)realpath($__gt3_dir));
$__gt3_root = str_replace(chr(92), '/', (string)realpath($_SERVER['DOCUMENT_ROOT'] ?? ''));
$__gt3_url  = ($__gt3_fs && $__gt3_root && strpos($__gt3_fs, $__gt3_root) === 0) ? substr($__gt3_fs, strlen($__gt3_root)) : '../../resource/js';
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
        /* 「砂輪/刀具干涉3D」不是分頁、是開另一個工具視窗，所以用暖色與虛線底標示成
           不同性質的入口（它永遠不會有 .active 狀態，看起來跟分頁一樣會讓人以為
           按了沒反應）。 */
        .gear-tab-btn.gear-tab-ext { color:#C77C1A; border-bottom:3px dotted #E8C9A0; }
        .gear-tab-btn.gear-tab-ext:hover { color:#8a5a2b; border-bottom-color:#C77C1A; }
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
        #sp-int-gfx-panel { position:fixed; z-index:700; display:none; flex-direction:column; background:#fff; color:#333; border:1px solid #e0b27a; border-radius:6px 0 0 6px; box-shadow:-6px 0 18px rgba(0,0,0,.30); font-family:"Microsoft JhengHei","PingFang TC",sans-serif; font-size:12px; box-sizing:border-box; }
        #sp-int-gfx-panel .gfxh { background:linear-gradient(135deg,#5b3a1a,#e67e22); color:#fff; padding:6px 10px; display:flex; align-items:center; justify-content:space-between; border-radius:6px 0 0 0; }
        #sp-int-gfx-panel .gfxh button { background:rgba(255,255,255,.18); color:#fff; border:1px solid rgba(255,255,255,.5); border-radius:4px; padding:1px 8px; cursor:pointer; }
        #sp-int-gfx-panel .gfxb { padding:8px; overflow-y:auto; flex:1 1 auto; }
        .sp-gfx svg { display:block; max-width:100%; height:auto; background:#fffdf9; border:1px solid #e3d3b8; border-radius:6px; }
        .sp-gfx { overflow:hidden; }
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

    <!-- 料號齒輪規格帶入：放在分頁列上方，不論目前在哪個分頁都看得到（多組規格時要在這裡選） -->
    <div id="g-part-card" style="flex:0 0 auto;margin:0;padding:5px 10px;background:#FBF1E1;border-bottom:1px solid #F0D2A0;">
        <div style="display:flex;align-items:center;gap:8px;">
            <b style="font-size:11.5px;color:#5a3a12;white-space:nowrap;">料號齒輪規格帶入</b>
            <input type="text" id="g-part-q" class="gear-input" placeholder="輸入料號搜尋（可不填）" autocomplete="off" style="flex:1;min-width:0;padding:2px 6px;">
        </div>
        <div id="g-part-res" style="display:none;margin-top:3px;border:1px solid #e3d3b8;border-radius:4px;background:#fff;max-height:150px;overflow-y:auto;"></div>
        <div id="g-part-info" style="font-size:11px;color:#5a3a12;"></div>
        <div id="g-part-gears"></div>
    </div>

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
<?php if ($can_grind3d): ?>
        <?php /* 這一顆不是分頁、是開另一個工具視窗，所以用 gear-tab-ext 標成不同顏色，
                 不要讓使用者以為按了會換分頁。唯一實作在 views/Sales/_grind3d_tool_ui.php。 */ ?>
        <button class="gear-tab-btn gear-tab-ext" onclick="openGrind3DFromTail()"
                title="開啟砂輪/刀具干涉 3D 模擬：出尾計算的 3D 版本，可旋轉、含砂輪傾角。會自動把這一頁已經填好的刀具外徑／齒根直徑／肩部直徑／退刀量帶過去。注意出尾計算的公式只在砂輪傾角 0 時成立，有傾角時要以 3D 的結果為準"><i class="fa fa-cube"></i> 砂輪/刀具干涉3D</button>
<?php endif; ?>
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
                    <!-- 把算好的齒形畫到圖面（只有批圖編輯器這類提供 egGearDrawHook 的頁面才會顯示） -->
                    <div id="g-draw-block" style="display:none;margin-top:10px;padding:8px 10px;background:#fff8ee;border:1px solid #f0c98f;border-radius:5px;">
                        <div style="font-size:11px;font-weight:700;color:#a35a00;margin-bottom:6px;"><i class="fa fa-pencil"></i> 把齒形畫到圖面（先按「計算齒輪」）</div>
                        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;font-size:12px;">
                            <select id="g-draw-mode" class="gear-input" style="width:auto;" onchange="document.getElementById('g-draw-n-wrap').style.display=(this.value==='part')?'':'none'">
                                <option value="full">整個齒輪外形</option>
                                <option value="part" selected>局部（連續幾個齒）</option>
                            </select>
                            <span id="g-draw-n-wrap">齒數 <input type="number" id="g-draw-n" class="gear-input" style="width:56px;" value="4" min="1" max="12" step="1"></span>
                            <label style="display:flex;align-items:center;gap:3px;"><input type="checkbox" id="g-draw-pitch"> 節圓</label>
                            <label style="display:flex;align-items:center;gap:3px;"><input type="checkbox" id="g-draw-tip"> 齒頂圓</label>
                            <label style="display:flex;align-items:center;gap:3px;"><input type="checkbox" id="g-draw-root"> 齒根圓</label>
                            <button class="btn-gear-calc" type="button" onclick="gearDrawToCanvas()"><i class="fa fa-pencil"></i> 畫到圖面</button>
                        </div>
                        <div style="display:flex;gap:8px;align-items:center;margin-top:6px;font-size:12px;flex-wrap:wrap;">
                            <span title="齒部端面外緣的倒角。0.5＝C0.5（45°）；R1＝圓角；1@30＝軸向距離 1、與軸線 30°（距端面多少、幾度導過去）；Ø66@30＝端面上 Ø66 處往中心、與端面 30° 導角。">齒端倒角 <input type="text" id="g-draw-c" class="gear-input" style="width:120px;" placeholder="0.5 / R1 / 1@30 / Ø66@30"></span>
                            <select id="g-draw-cend" class="gear-input" style="width:auto;" title="倒角畫在齒部的哪一端（剖面圖左端＝第 1 段那一側）"><option value="both">兩端</option><option value="left">只有左端</option><option value="right">只有右端</option></select>
                        </div>
                        <div style="font-size:10px;color:#8a5a2b;margin-top:4px;line-height:1.5;">畫出來是一組向量線條（不含齒根圓角），尺寸只是示意，畫完自己拖角縮放對齊圖面；Alt＋雙擊可進入群組個別調整。</div>
                        <div style="border-top:1px dashed #f0c98f;margin-top:8px;padding-top:6px;">
                            <div style="font-size:11px;font-weight:700;color:#a35a00;margin-bottom:2px;">剖面圖（外形與內孔分開輸入）</div>
                            <div style="font-size:11px;color:#6b4a1d;line-height:1.6;margin-bottom:4px;"><b>數字欄可直接打算式</b>（例：32-18.5-2+1、(40-14)/2，漏打開頭的括號也行：40-14)/2），按 Enter 或離開欄位就自動算出結果。想像零件橫放、軸線水平。<b>外形</b>與<b>內孔</b>各自由左到右分段，兩邊的分段位置不必對齊。每段填直徑與長度；<b>迄徑/錐度</b>留空＝直的；填數字＝該段右端的直徑；填 <b>1/10</b>（或 1:10）＝錐度、填 <b>3°</b>＝半錐角（預設往右變小，前面加「−」＝往右變大）（起徑漸變到迄徑）；左／右倒角填 C 值（45°）。外形有一段要選「齒部」（外徑留空自動用 da，節圓線只畫在這一段）。<button class="btn-gear-clr" type="button" style="padding:1px 8px;margin-left:4px;" onclick="gearSegExample()">帶入範例</button></div>
                            <div style="font-size:11px;font-weight:700;color:#8a5a2b;margin:4px 0 2px;">① 外形（外徑 × 長度，由左到右）　<label style="font-weight:400;"><input type="checkbox" id="g-taper-out" onchange="gearTaperApply()"> 有錐度段</label></div>
                            <div class="g-seg-head" data-list="out" style="display:grid;grid-template-columns:62px repeat(4,minmax(0,1fr)) 72px;gap:3px 4px;font-size:10.5px;font-weight:600;color:#8a5a2b;"><span>段落</span><span>外徑</span><span class="g-th-taper" style="display:none;" title="迄徑，或錐度：1/10、1:10（每長度1/10的直徑變化）或 3°（半錐角）。預設往右變小，前面加 − 號＝往右變大">迄徑/錐度</span><span>長度</span><span title="倒角：0.5＝C0.5；R1＝圓角；1@30＝軸向1、與軸線30°；Ø60@30＝端面上Ø60處起、與端面30°">左倒角</span><span title="倒角：0.5＝C0.5；R1＝圓角；1@30＝軸向1、與軸線30°；Ø60@30＝端面上Ø60處起、與端面30°">右倒角</span><span></span></div>
                            <div id="g-seg-list" data-kind="out"></div>
                            <button class="btn-gear-clr" type="button" onclick="gearSegAdd('out')">＋ 外形再加一段（往右）</button>
                            <div style="font-size:11px;font-weight:700;color:#8a5a2b;margin:8px 0 2px;">② 內孔（孔徑 × 長度，由左到右）　<label style="font-weight:400;">孔從 <select id="g-bore-align" class="gear-input" style="width:auto;padding:1px 2px;"><option value="left">左端面</option><option value="right">右端面</option></select> 起算（短於全長＝盲孔）</label>　<label style="font-weight:400;"><input type="checkbox" id="g-taper-bore" onchange="gearTaperApply()"> 有錐度段</label></div>
                            <div class="g-seg-head" data-list="bore" style="display:grid;grid-template-columns:62px repeat(4,minmax(0,1fr)) 72px;gap:3px 4px;font-size:10.5px;font-weight:600;color:#8a5a2b;"><span>段落</span><span>孔徑</span><span class="g-th-taper" style="display:none;" title="迄徑，或錐度：1/10、1:10 或 3°（半錐角）。預設往右變小，前面加 − 號＝往右變大">迄徑/錐度</span><span>長度</span><span title="倒角：0.5＝C0.5；R1＝圓角；1@30＝軸向1、與軸線30°；Ø60@30＝端面上Ø60處起、與端面30°">左倒角</span><span title="倒角：0.5＝C0.5；R1＝圓角；1@30＝軸向1、與軸線30°；Ø60@30＝端面上Ø60處起、與端面30°">右倒角</span><span></span></div>
                            <div id="g-bore-list" data-kind="bore"></div>
                            <button class="btn-gear-clr" type="button" onclick="gearSegAdd('bore')">＋ 內孔再加一段（往右）</button>
                            <div style="font-size:11px;font-weight:700;color:#8a5a2b;margin:8px 0 2px;">③ 內鍵槽（在內孔上方；寬與深會標在端面圖上）</div>
                            <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;font-size:11.5px;">
                                <label><input type="checkbox" id="g-key-on"> 有內鍵槽</label>
                                <select id="g-key-seg" class="gear-input" style="width:auto;" title="鍵槽開在哪一個內徑段上"><option value="-1">整個內孔</option></select>
                                <span>寬 <input type="number" id="g-key-w" class="gear-input" style="width:56px;" min="0" step="any"></span>
                                <span title="從孔壁往外多挖的深度">深 <input type="number" id="g-key-t" class="gear-input" style="width:56px;" min="0" step="any"></span>
                                <span title="從所選內徑段的左端算起，空白＝0">起點 <input type="number" id="g-key-x" class="gear-input" style="width:56px;" min="0" step="any"></span>
                                <span title="空白＝一直到所選內徑段結束">長度 <input type="number" id="g-key-l" class="gear-input" style="width:56px;" min="0" step="any" placeholder="貫穿"></span>
                            </div>
                            <div style="font-size:11px;font-weight:700;color:#8a5a2b;margin:8px 0 2px;">③＋ 外鍵槽（開在外徑上；畫在剖面圖上，位置固定在正上方）</div>
                            <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;font-size:11.5px;">
                                <label><input type="checkbox" id="g-okey-on"> 有外鍵槽</label>
                                <select id="g-okey-seg" class="gear-input" style="width:auto;" title="鍵槽開在哪一個外徑段上"></select>
                                <span>寬 <input type="number" id="g-okey-w" class="gear-input" style="width:56px;" min="0" step="any"></span>
                                <span title="從外徑表面往下的深度">深 <input type="number" id="g-okey-t" class="gear-input" style="width:56px;" min="0" step="any"></span>
                                <span title="從所選外徑段的左端算起，空白＝0">起點 <input type="number" id="g-okey-x" class="gear-input" style="width:56px;" min="0" step="any"></span>
                                <span title="空白＝一直到所選外徑段結束">長度 <input type="number" id="g-okey-l" class="gear-input" style="width:56px;" min="0" step="any"></span>
                            </div>
                            <div style="font-size:11px;font-weight:700;color:#8a5a2b;margin:8px 0 2px;">④ 端面螺孔</div>
                            <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;font-size:11.5px;">
                                <label><input type="checkbox" id="g-hole-on"> 有螺孔</label>
                                <select id="g-hole-face" class="gear-input" style="width:auto;" title="螺孔開在哪一個端面"><option value="left">左端面</option><option value="right">右端面</option></select>
                                <select id="g-hole-size" class="gear-input" style="width:auto;" title="粗牙螺紋規格（牙距自動帶入）"><option value="3,0.5">M3x0.5</option><option value="4,0.7">M4x0.7</option><option value="5,0.8" selected>M5x0.8</option><option value="6,1">M6x1</option><option value="8,1.25">M8x1.25</option><option value="10,1.5">M10x1.5</option><option value="12,1.75">M12x1.75</option></select>
                                <span>數量 <input type="number" id="g-hole-n" class="gear-input" style="width:48px;" value="2" min="1" max="24" step="1"></span>
                                <span title="第一個孔的角度（從正上方順時針量）。要每個孔角度不同，直接用逗號分開打，例：0,100,250（此時數量自動以個數為準）">角度 <input type="text" id="g-hole-ang" class="gear-input" style="width:84px;" value="0" placeholder="0 或 0,100,250">°</span>
                                <span title="相鄰兩孔之間的夾角；空白＝平均分佈">孔間夾角 <input type="text" id="g-hole-step" class="gear-input" style="width:56px;" placeholder="平均">°</span>
                                <span title="螺孔中心所在圓的直徑">分佈圓 PCD <input type="number" id="g-hole-pcd" class="gear-input" style="width:64px;" min="0" step="any"></span>
                                <span>螺紋深 <input type="number" id="g-hole-depth" class="gear-input" style="width:56px;" min="0" step="any"></span>
                                <span title="空白＝螺紋深＋半個螺紋外徑">鑽孔深 <input type="number" id="g-hole-drill" class="gear-input" style="width:56px;" min="0" step="any" placeholder="自動"></span>
                            </div>
                            <div style="font-size:11px;font-weight:700;color:#8a5a2b;margin:8px 0 2px;">⑤ 外徑徑向螺孔（可與端面螺孔並存）</div>
                            <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;font-size:11.5px;">
                                <label><input type="checkbox" id="g-rad-on"> 有外徑螺孔</label>
                                <select id="g-rad-size" class="gear-input" style="width:auto;"><option value="3,0.5">M3x0.5</option><option value="4,0.7">M4x0.7</option><option value="5,0.8" selected>M5x0.8</option><option value="6,1">M6x1</option><option value="8,1.25">M8x1.25</option><option value="10,1.5">M10x1.5</option><option value="12,1.75">M12x1.75</option></select>
                                <span>數量 <input type="number" id="g-rad-n" class="gear-input" style="width:48px;" value="1" min="1" max="24" step="1"></span>
                                <span title="第一個孔的角度（從正上方順時針量，0＝正上方）。要每個孔角度不同，直接用逗號分開打，例：0,100,250（此時數量自動以個數為準）">角度 <input type="text" id="g-rad-ang" class="gear-input" style="width:84px;" value="0" placeholder="0 或 0,100,250">°</span>
                                <span title="相鄰兩孔之間的夾角；空白＝平均分佈">孔間夾角 <input type="text" id="g-rad-step" class="gear-input" style="width:56px;" placeholder="平均">°</span>
                                <select id="g-rad-align" class="gear-input" style="width:auto;" title="是否把角度對到齒頂或齒底（依目前計算的齒數）"><option value="any">角度任意</option><option value="tip">對齊齒頂</option><option value="root">對齊齒底</option></select>
                                <span title="孔中心在軸向（沿軸線方向）離左端面多遠。空白＝自動放在齒部那一段的正中央">軸向位置 <input type="number" id="g-rad-x" class="gear-input" style="width:64px;" min="0" step="any" placeholder="齒部中央"></span>
                                <span>螺紋深 <input type="number" id="g-rad-depth" class="gear-input" style="width:56px;" min="0" step="any"></span>
                                <span title="空白＝螺紋深＋半個螺紋外徑（只在「指定深度」時使用）">鑽孔深 <input type="number" id="g-rad-drill" class="gear-input" style="width:56px;" min="0" step="any" placeholder="自動"></span>
                                <select id="g-rad-thru" class="gear-input" style="width:auto;" title="孔要鑽多深：指定深度＝用上面的螺紋深／鑽孔深；貫穿到內孔＝鑽穿這一側的壁到內徑為止；全部貫穿＝兩側的壁都鑽穿"><option value="depth">指定深度</option><option value="bore">貫穿到內孔</option><option value="all">全部貫穿（兩側）</option></select>
                            </div>
                            <div style="font-size:11px;font-weight:700;color:#8a5a2b;margin:8px 0 2px;">⑥ 自動標註尺寸（標上去的每一個尺寸都是獨立物件，可單獨刪除、雙擊改文字）</div>
                            <div style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;font-size:11.5px;">
                                <label><input type="checkbox" id="g-dim-on" checked> 畫出尺寸標註</label>
                                <span title="標註文字大小（畫出來之後，選取標註在上方「字級」也可以改）">文字大小 <input type="number" id="g-dim-fs" class="gear-input" style="width:52px;" value="18" min="8" max="80" step="1"></span>
                                <label title="剖面圖上標示齒部兩端的倒角"><input type="checkbox" id="g-dim-ch-gear" checked> 標示齒端倒角</label>
                                <label title="剖面圖上標示其他外形段的倒角／圓角"><input type="checkbox" id="g-dim-ch-out" checked> 標示外形倒角</label>
                                <label title="剖面圖上標示內孔的倒角／圓角"><input type="checkbox" id="g-dim-ch-bore" checked> 標示內孔倒角</label>
                                <label title="剖面圖是否畫出非 0/90/180/270° 角度的螺孔（虛線）"><input type="checkbox" id="g-show-obl" checked> 顯示斜角度螺孔</label>
                                <span title="直接接在外徑尺寸後面。上下偏差堆疊用 ^，例：+0^-0.02（上排 +0、下排 -0.02）；對稱偏差直接打 ±0.02">外徑公差 <input type="text" id="g-tol-od" class="gear-input" style="width:84px;" placeholder="+0^-0.02 或 ±0.02"></span>
                                <span title="直接接在孔徑尺寸後面。上下偏差堆疊用 ^，例：+0.027^0；對稱偏差直接打 ±0.01">孔徑公差 <input type="text" id="g-tol-id" class="gear-input" style="width:84px;" placeholder="+0.027^0 或 ±0.01"></span>
                                <span title="直接接在長度尺寸後面。例：±0.1，或上下偏差堆疊 +0.1^-0.05">長度公差 <input type="text" id="g-tol-len" class="gear-input" style="width:84px;" placeholder="±0.1"></span>
                                <button class="btn-gear-clr" type="button" style="padding:1px 8px;" onclick="gearDefaultsSave()" title="把本區（標註、剖面線、端面圖／切割線方向、公差）目前的設定存成預設，下次開啟、開新料號都先套用（只存在這台電腦的瀏覽器）">存為預設</button>
                                <button class="btn-gear-clr" type="button" style="padding:1px 8px;" onclick="gearDefaultsClear()" title="清除自訂預設，下次開啟回到系統預設">還原系統預設</button>
                            </div>
                            <div style="display:flex;gap:8px;align-items:center;margin-top:8px;flex-wrap:wrap;">
                                <label style="font-size:12px;display:flex;align-items:center;gap:3px;"><input type="checkbox" id="g-draw-hatch" checked> 剖面線</label>
                                <label style="font-size:12px;display:flex;align-items:center;gap:3px;" title="斜線之間的距離（畫面像素，數字越大越疏）">間距 <input type="number" id="g-draw-hatch-gap" class="gear-input" style="width:56px;" value="28" min="3" max="60" step="1"></label>
                                <label style="font-size:12px;display:flex;align-items:center;gap:3px;" title="孔口、孔階的可見邊線（連接上下兩半的垂直線）"><input type="checkbox" id="g-draw-edge" checked> 孔口連線</label>
                                <select id="g-view-side" class="gear-input" style="width:auto;" title="端面圖從哪一側的端面看過去（決定哪些圓看得到＝實線、被擋住＝虛線）"><option value="right">端面圖：從右端面看</option><option value="left">端面圖：從左端面看</option></select>
                                <select id="g-cut-dir" class="gear-input" style="width:auto;" title="A-A 剖面切割線在端面圖上的畫法與看過去的方向。垂直線＝剖面切在上下方向（0°／180° 的孔會被切到）；水平線＝切在左右方向（90°／270° 的孔會被切到）"><option value="v-right">切割線垂直，箭頭朝右</option><option value="v-left">切割線垂直，箭頭朝左</option><option value="h-down">切割線水平，箭頭朝下</option><option value="h-up">切割線水平，箭頭朝上</option><option value="v-center">切割線垂直，箭頭指向中心線</option><option value="h-center">切割線水平，箭頭指向中心線</option></select>
                                <label style="font-size:12px;display:flex;align-items:center;gap:3px;" title="端面圖也畫出內孔、鍵槽與螺孔"><input type="checkbox" id="g-draw-endbore" checked> 端面圖含孔／鍵槽／螺孔</label>
                                <button class="btn-gear-calc" type="button" onclick="gearDrawSection()"><i class="fa fa-pencil"></i> 畫剖面圖</button>
                                <select id="g-align" class="gear-input" style="width:auto;" title="兩個視圖的對齊方式：自動＝依投影法與剖面箭頭方向；水平對齊＝左右並排、中心軸線同一高度；垂直對齊＝上下排、中心線同一垂直線；不對齊＝不移動，自己拖"><option value="auto">對齊：自動</option><option value="h">水平對齊</option><option value="v">垂直對齊</option><option value="none">不對齊</option></select>
                                <button class="btn-gear-calc" type="button" onclick="if(window.egGearDrawViews)egGearDrawViews('third',document.getElementById('g-cut-dir').value,document.getElementById('g-align').value,'sec')" title="一次畫出端面圖＋剖面圖，並依所選投影法與剖面箭頭方向排好位置（標註一起移動）"><i class="fa fa-th-large"></i> 畫成兩視圖</button>
                                <label style="font-size:12px;display:flex;align-items:center;gap:3px;" title="勾選時，改了剖面線、孔口連線、剖面箭頭方向、端面圖視角…這類設定，已畫出的端面圖／剖面圖會自動重畫並留在原位置（你手動拖過的標註位置會重設）"><input type="checkbox" id="g-autoredraw" checked> 設定變更時自動更新圖面</label>
                                <button class="btn-gear-calc" type="button" onclick="gearDraw3D()" title="依目前的外形、內孔、倒角、鍵槽、螺孔、齒形（含螺旋角）產生可旋轉的 3D 模型"><i class="fa fa-cube"></i> 3D 預覽</button>
                            </div>
                        </div>
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
                    <div id="m2-pv"></div>
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
                    <div id="m5-pv"></div>
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
                    <div class="sp-int-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:0 8px;">
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
                        <label>轉位係數 x <small style="color:#999;">同外花鍵／標準表示，空白=0</small></label>
                        <input type="number" id="sp-int-x" class="gear-input" step="any" placeholder="0">
                    </div>
                    <div id="sp-int-dnom-wrap" class="gear-field-group" style="display:none;">
                        <label>基準直徑 d_B <small style="color:#999;">DIN5480，填了自動算 x</small></label>
                        <input type="number" id="sp-int-dnom" class="gear-input" step="any" placeholder="例：55">
                    </div>
                    <div style="display:flex;gap:6px;">
                        <div class="gear-field-group" style="flex:1;">
                            <label>小徑 di <small style="color:#999;">空白=公式</small></label>
                            <input type="number" id="sp-int-di-in" class="gear-input" step="any" placeholder="圖面標準值">
                        </div>
                        <div class="gear-field-group" style="flex:1;">
                            <label>大徑 Df <small style="color:#999;">空白=公式</small></label>
                            <input type="number" id="sp-int-df-in" class="gear-input" step="any" placeholder="圖面標準值">
                        </div>
                    </div>
                    <div class="gear-field-group">
                        <label>齒根形式</label>
                        <select id="sp-int-root" class="gear-input">
                            <option value="flat">平底 Flat Root（hf*=1.25）</option>
                            <option value="fillet">圓弧 Fillet Root（hf*=1.50）</option>
                        </select>
                    </div>
                    <div style="display:contents;">
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
                    </div><!-- /sp-int-grid -->
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
                        <div class="gear-out-row"><span class="gear-out-label">測棒接觸圓 dk</span><span class="gear-output-val" id="sp-int-out-dk">—</span></div>
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
                矩形直邊花鍵（栓槽）跨球值計算。輸入大徑 D、小徑 d、鍵寬／槽寬 B（軸＝齒寬、孔＝槽寬）、槽數 N，可附加公差回推 M 值範圍。
                與「花鍵 內／外」（漸開線齒形）不同：栓槽的齒側面是平行直邊，用球頂在側面或落在溝底量測，不用模數與壓力角。
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
                        <label>鍵寬／槽寬 B (mm)  <small style="color:#888;">軸＝齒(鍵)寬、孔＝槽寬；可附公差代號如 e8、H7</small></label>
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
                        <label>建議球徑 dp <small style="color:#888;font-weight:400;">（依幾何：軸＝頂齒側面中段；孔＝1.2×B）</small></label>
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
                        <label id="sr-tooth-lbl">齒厚 <small style="color:#888;font-weight:400;">（軸＝齒寬 B；孔＝小徑弦長）</small></label>
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
                        Wk: Wk, Wk_actual: Wk_actual, da: da, df: df, dp: dp_used, x: x_in };

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
    // ══ 外齒跨珠圖示與建議範圍（2026-10-21 新增，純顯示；不參與、不改變任何既有計算）══════
    //    與內齒計算工具（_ingear_tool_ui.php）同一套畫法：上＝珠與齒槽放大，下＝整顆齒輪與兩顆量測珠。
    //    斜齒輪以端面近似（珠徑採 dp/cosβb，與 calcM 的 inv 公式一致）。
    function gpvInvInv(v) {
        if (!(v >= 0)) return null;
        var lo = 0, hi = 1.5, i, mid;
        for (i = 0; i < 80; i++) { mid = (lo + hi) / 2; if (inv(mid) < v) lo = mid; else hi = mid; }
        return (lo + hi) / 2;
    }
    function gpvGeom(p, x) {
        var cos_b = Math.cos(p.beta), mt = p.mn / cos_b, d = mt * p.z, at = p.alpha_t;
        var sin_bb = Math.sin(p.beta) * Math.cos(p.alpha_n);
        return { z: p.z, d: d, at: at, db: d * Math.cos(at), cbb: Math.sqrt(1 - sin_bb * sin_bb),
                 stD: (PI / (2 * p.z)) + (2 * x * Math.tan(p.alpha_n) / p.z), ra: p.da / 2, rf: p.df / 2, mn: p.mn };
    }
    function gpvPin(g, dp) {
        var D = dp / g.cbb, ap = gpvInvInv(g.stD + inv(g.at) + D / g.db - PI / g.z);
        if (ap === null) return null;
        var rp = g.db / 2 / Math.cos(ap), tk = Math.tan(ap) - D / g.db;
        if (tk <= 0) return null;
        var ak = Math.atan(tk), rk = g.db / 2 / Math.cos(ak);
        var M = (g.z % 2 === 0 ? 2 * rp : 2 * rp * Math.cos(PI / (2 * g.z))) + dp;
        return { D: D, dp: dp, rp: rp, ak: ak, rk: rk, psik: g.stD + inv(g.at) - inv(ak), M: M };
    }
    function gpvJudge(g, q) {
        var span = g.ra - g.rf, rho = q.D / 2;
        if (q.rk > g.ra + 1e-9) return { lv: 2, txt: '✗ 跨珠徑過大：接觸點 Ø' + fmtNum(2 * q.rk, 3) + ' 已超出齒頂圓 Ø' + fmtNum(2 * g.ra, 3) + '（珠壓在齒頂以外，無法正確量測）' };
        if (q.rp - rho < g.rf - 1e-9) return { lv: 2, txt: '✗ 跨珠徑過小：珠底 Ø' + fmtNum(2 * (q.rp - rho), 3) + ' 已碰到齒根圓 Ø' + fmtNum(2 * g.rf, 3) + '（珠落到齒根，無法正確量測）' };
        if (q.rk < g.rf) return { lv: 2, txt: '✗ 跨珠徑過小：接觸點 Ø' + fmtNum(2 * q.rk, 3) + ' 低於齒根圓 Ø' + fmtNum(2 * g.rf, 3) };
        if (g.ra - q.rk < 0.1 * span) return { lv: 1, txt: '⚠ 接觸點很靠近齒頂（離齒頂圓只剩 ' + fmtNum(g.ra - q.rk, 3) + ' mm），珠再大一點就量不準' };
        if (q.rk - g.rf < 0.1 * span) return { lv: 1, txt: '⚠ 接觸點很靠近齒根（離齒根圓只剩 ' + fmtNum(q.rk - g.rf, 3) + ' mm），可能碰到齒根圓角' };
        return { lv: 0, txt: '✓ 可正常量測：接觸點在有效齒面內（離齒頂 ' + fmtNum(g.ra - q.rk, 3) + ' mm、離齒根 ' + fmtNum(q.rk - g.rf, 3) + ' mm）' };
    }
    function gpvRange(p, xs, maxLv) { // 多個 x 取交集
        var gs = xs.map(function(x){ return gpvGeom(p, x); }), D, i, ok, q, lo = null, hi = null, lim = p.mn * 6;
        for (D = 0.02; D <= lim; D += 0.004) {
            ok = true;
            for (i = 0; i < gs.length && ok; i++) { q = gpvPin(gs[i], D); ok = !!q && gpvJudge(gs[i], q).lv <= maxLv; }
            if (ok) { if (lo === null) lo = D; hi = D; } else if (lo !== null) break;
        }
        if (lo === null) return null;
        var a = Math.ceil(lo * 100 - 1e-9) / 100, b = Math.floor(hi * 100 + 1e-9) / 100;
        return a <= b ? [a, b] : null;
    }
    function gpvZoom(g, pins) {
        var W = 450, H = 245, m = g.mn, x0 = g.rf - 0.9 * m, x1 = g.ra + 0.9 * m, s = W / (x1 - x0), N = 10, i, k, out = '';
        var step = 2 * PI / g.z, rb = g.db / 2;
        function X(x){ return (x - x0) * s; }
        function Y(y){ return H / 2 - y * s; }
        function P(r, ph){ return X(r * Math.cos(ph)).toFixed(1) + ',' + Y(r * Math.sin(ph)).toFixed(1); }
        function ps(r){ var rr = Math.max(r, rb + 1e-6); return Math.max(0, g.stD + inv(g.at) - inv(Math.acos(Math.min(1, rb / rr)))); }
        var rIn = g.rf - 2 * m, body = [];
        for (i = -12; i <= 12; i++) body.push(P(g.rf, i * step * 0.2));
        for (i = 12; i >= -12; i--) body.push(P(rIn, i * step * 0.2));
        out += '<polygon points="' + body.join(' ') + '" fill="#F7E0BD"/>';
        for (k = 0; k <= 1; k++) { // 兩側各一顆齒：中心在 ±π/z，珠在角度 0 的齒槽
            var tc = (k === 0 ? -1 : 1) * step / 2, t = [], r, fl = [], fl2 = [];
            for (i = 0; i <= N; i++) { r = g.rf + (g.ra - g.rf) * i / N; t.push(P(r, tc - ps(r))); }
            for (i = 1; i <= 3; i++) t.push(P(g.ra, tc - ps(g.ra) + 2 * ps(g.ra) * i / 4));
            for (i = N; i >= 0; i--) { r = g.rf + (g.ra - g.rf) * i / N; t.push(P(r, tc + ps(r))); }
            out += '<polygon points="' + t.join(' ') + '" fill="#F7E0BD"/>';
            for (i = 0; i <= N; i++) { r = g.rf + (g.ra - g.rf) * i / N; fl.push(P(r, tc - ps(r))); fl2.push(P(r, tc + ps(r))); }
            out += '<polyline fill="none" stroke="#6b4423" stroke-width="1.6" points="' + fl.join(' ') + '"/>';
            out += '<polyline fill="none" stroke="#6b4423" stroke-width="1.6" points="' + fl2.join(' ') + '"/>';
        }
        var main = pins[0] ? pins[0].q : null;
        var lines = [[g.rf, '齒根Ø' + fmtNum(2 * g.rf, 2), 12], [g.d / 2, '分度Ø' + fmtNum(g.d, 2), 24], [g.ra, '齒頂Ø' + fmtNum(2 * g.ra, 2), 12]];
        if (main) lines.push([main.rk, '接觸Ø' + fmtNum(2 * main.rk, 2), 36]);
        for (i = 0; i < lines.length; i++) {
            var lx = X(lines[i][0]);
            out += '<line x1="' + lx.toFixed(1) + '" x2="' + lx.toFixed(1) + '" y1="' + (lines[i][2] + 4) + '" y2="' + H + '" stroke="#b58b5a" stroke-width="1" stroke-dasharray="4 3"/>';
            out += '<text x="' + (lx + 3).toFixed(1) + '" y="' + lines[i][2] + '" font-size="10" fill="#6b4423">' + lines[i][1] + '</text>';
        }
        for (i = pins.length - 1; i >= 0; i--) {
            var pn = pins[i], q = pn.q; if (!q) continue;
            var cx = X(q.rp), cy = Y(0), rad = q.D / 2 * s;
            out += '<circle cx="' + cx.toFixed(1) + '" cy="' + cy.toFixed(1) + '" r="' + rad.toFixed(1) + '" fill="' + (pn.dash ? 'none' : pn.col) + '" fill-opacity="' + (pn.dash ? 0 : 0.45) + '" stroke="' + pn.col + '" stroke-width="1.6"' + (pn.dash ? ' stroke-dasharray="5 3"' : '') + '/>';
            var kph = PI / g.z - q.psik, kx = q.rk * Math.cos(kph), ky = q.rk * Math.sin(kph);
            out += '<circle cx="' + X(kx).toFixed(1) + '" cy="' + Y(ky).toFixed(1) + '" r="3.2" fill="' + pn.col + '" stroke="#fff"/>';
            out += '<circle cx="' + X(kx).toFixed(1) + '" cy="' + Y(-ky).toFixed(1) + '" r="3.2" fill="' + pn.col + '" stroke="#fff"/>';
            out += '<text x="' + cx.toFixed(1) + '" y="' + (cy + 15 + 12 * i).toFixed(1) + '" text-anchor="middle" font-size="10.5" fill="#333">' + pn.lbl + '</text>';
        }
        out += '<text x="6" y="' + (H - 8) + '" font-size="10" fill="#6b4423">← 齒輪中心</text>';
        return '<svg viewBox="0 0 ' + W + ' ' + H + '" style="width:100%;height:auto;display:block;background:#fffdf9;border:1px solid #e3d3b8;border-radius:6px">' + out + '</svg>';
    }
    function gpvOverview(g, q, col) {
        var S = 188, c = S / 2, Ro = g.ra * 1.08, s = (c - 6) / Ro, step = 2 * PI / g.z, rb = g.db / 2, pts = [], k, i, N = 4;
        function PX(r, ph){ return (c + r * Math.cos(ph) * s).toFixed(1) + ',' + (c - r * Math.sin(ph) * s).toFixed(1); }
        function ps(r){ var rr = Math.max(r, rb + 1e-6); return Math.max(0, g.stD + inv(g.at) - inv(Math.acos(Math.min(1, rb / rr)))); }
        for (k = 0; k < g.z; k++) {
            var tc = k * step, r;
            for (i = 0; i <= N; i++) { r = g.rf + (g.ra - g.rf) * i / N; pts.push(PX(r, tc - ps(r))); }
            pts.push(PX(g.ra, tc - ps(g.ra) / 3)); pts.push(PX(g.ra, tc + ps(g.ra) / 3));
            for (i = N; i >= 0; i--) { r = g.rf + (g.ra - g.rf) * i / N; pts.push(PX(r, tc + ps(r))); }
        }
        var out = '<polygon points="' + pts.join(' ') + '" fill="#F7E0BD" stroke="#6b4423" stroke-width="0.8"/>';
        out += '<circle cx="' + c + '" cy="' + c + '" r="' + (g.d / 2 * s).toFixed(1) + '" fill="none" stroke="#b58b5a" stroke-dasharray="3 3"/>';
        if (q) {
            var pa = PI / g.z, pb = pa + Math.floor(g.z / 2) * step, A = [q.rp * Math.cos(pa) * s, q.rp * Math.sin(pa) * s], B = [q.rp * Math.cos(pb) * s, q.rp * Math.sin(pb) * s];
            out += '<line x1="' + (c + A[0]).toFixed(1) + '" y1="' + (c - A[1]).toFixed(1) + '" x2="' + (c + B[0]).toFixed(1) + '" y2="' + (c - B[1]).toFixed(1) + '" stroke="#DD5138" stroke-dasharray="4 3"/>';
            [A, B].forEach(function(u){ out += '<circle cx="' + (c + u[0]).toFixed(1) + '" cy="' + (c - u[1]).toFixed(1) + '" r="' + Math.max(2.5, q.D / 2 * s).toFixed(1) + '" fill="' + col + '" stroke="#5a3a12"/>'; });
            out += '<text x="' + (c - 26) + '" y="' + (c + 14) + '" font-size="10.5" fill="#DD5138">M ' + fmtNum(q.M, 3) + '</text>';
        }
        return '<svg viewBox="0 0 ' + S + ' ' + S + '" style="width:' + S + 'px;height:' + S + 'px;flex-shrink:0;display:block;background:#fffdf9;border:1px solid #e3d3b8;border-radius:6px">' + out + '</svg>';
    }
    // hostId：放圖示的容器；xs：要同時滿足的轉位係數（公差上下限）；dpMain：我方珠徑；dpGhost：客戶珠徑（可 null）
    window.gearPinView = function(hostId, p, xs, dpMain, dpGhost) {
        var host = document.getElementById(hostId); if (!host) return;
        if (!p || p.internal || !(p.df > 0) || !(p.da > 0) || !(dpMain > 0)) { host.innerHTML = ''; return; }
        var xm = (xs[0] + xs[xs.length - 1]) / 2, g = gpvGeom(p, xm), q = gpvPin(g, dpMain);
        if (!q) { host.innerHTML = '<div style="margin-top:8px;color:#c0392b;font-size:11px">此珠徑算不出接觸位置（珠太大放不進齒槽，或轉位係數超出範圍），無法繪圖</div>'; return; }
        var worst = gpvJudge(g, q), i, qq, jj, gg;
        for (i = 0; i < xs.length; i++) { gg = gpvGeom(p, xs[i]); qq = gpvPin(gg, dpMain); if (!qq) continue; jj = gpvJudge(gg, qq); if (jj.lv > worst.lv) worst = jj; }
        var col = worst.lv === 0 ? '#F0A24B' : (worst.lv === 1 ? '#E67E22' : '#DD5138');
        var u = gpvRange(p, xs, 1), sf = gpvRange(p, xs, 0);
        var pins = [{ q: q, col: col, dash: false, lbl: '我方 Ø' + fmtNum(dpMain, 3) }];
        if (dpGhost > 0) { var qg = gpvPin(g, dpGhost); if (qg) pins.push({ q: qg, col: '#8c8c8c', dash: true, lbl: '客戶 Ø' + fmtNum(dpGhost, 3) }); }
        var bg = worst.lv === 0 ? 'background:#F7E0BD;color:#5a3a12;border:1px solid #e0b27a' : (worst.lv === 1 ? 'background:#F0A24B;color:#3d2200' : 'background:#DD5138;color:#fff');
        host.innerHTML =
            '<div style="margin-top:10px;border:1px solid #e3d3b8;border-radius:6px;padding:6px 8px;background:#fffaf2">' +
            '<div style="font-weight:700;color:#6b4423;margin-bottom:3px">跨珠位置圖示與可用珠徑</div>' +
            '<div style="display:flex;justify-content:space-between"><span>可用跨珠徑範圍</span><b style="color:#b85c0d;font-size:14px">' + (u ? fmtNum(u[0], 2) + ' ~ ' + fmtNum(u[1], 2) + ' mm' : '無') + '</b></div>' +
            '<div style="display:flex;justify-content:space-between"><span style="white-space:nowrap">建議範圍<span style="font-size:9px">（不靠近齒頂／齒根）</span></span><b>' + (sf ? fmtNum(sf[0], 2) + ' ~ ' + fmtNum(sf[1], 2) + ' mm' : '無') + '</b></div>' +
            '<div style="margin:5px 0;padding:5px 8px;border-radius:5px;font-weight:700;font-size:11.5px;' + bg + '">' + worst.txt + '</div>' +
            gpvZoom(g, pins) +
            '<div style="display:flex;gap:8px;margin-top:6px;align-items:flex-start">' + gpvOverview(g, q, col) +
            '<div style="font-size:10px;color:#6b4423;line-height:1.35"><b>圖示說明</b><br>上：珠與齒槽放大，棕線＝齒面，圓點＝珠與齒面的接觸點。虛線由左至右：齒根圓、分度圓、接觸點、齒頂圓。<br>珠顏色：琥珀＝可量測；橘＝接近極限；<span style="color:#DD5138;font-weight:700">紅＝不可量測</span>。有客戶珠徑時以灰色虛線疊上客戶的珠。<br>左下：整顆齒輪與兩顆量測珠（奇數齒兩珠差半個齒距）。斜齒輪以端面近似。<br>範圍已同時滿足公差上下限兩端；齒頂、齒根圓徑取基本計算的結果。</div></div></div>';
    };

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
        try { gearPinView('m2-pv', p, [x_min, x_max], dp2, null); } catch (e) {}
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
        try { gearPinView('m5-pv', p, [x_dn, x_up], dp_m, dp_c); } catch (e) {}
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
                      'sp-int-x','sp-int-dnom','sp-int-q','sp-int-dp'], window.calcSplineInt);
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

    // ── 料號齒輪規格帶入（2026-11-11）：資料取自主檔管理設定的齒輪規格，唯讀查詢 ───────────
    var _gpGears = [];
    function gpEsc(t){ return escapeHtml(String(t == null ? '' : t)); }
    window.gearLoadPart = function(dId, autoFill) {
        var info = document.getElementById('g-part-info'), box = document.getElementById('g-part-gears');
        if (!info || !box || !dId) return;
        _gearCurD = dId;
        info.textContent = '讀取齒輪規格中…'; box.innerHTML = '';
        gearAjax.post({action:'gear_part_specs', d_id: dId}, function(res){
            if (!res || !res.success) { info.textContent = (res && res.message) || '讀取失敗'; return; }
            _gpGears = res.gears || [];
            var pn = res.part ? (res.part.D_Setting_Id || '') : '';
            if (!_gpGears.length) { info.textContent = pn + '：主檔尚未設定齒輪規格'; return; }
            info.innerHTML = '料號 <b>' + gpEsc(pn) + '</b>　共 ' + _gpGears.length + ' 組齒輪規格' + (_gpGears.length > 1 ? '，請選擇要帶入的一組：' : '');
            var h = '';
            _gpGears.forEach(function(g, i){
                var dest = g.kind === 'int' ? '內花鍵' : (g.kind === 'ext' ? '外花鍵' : (g.kind === 'any' ? '' : '基本計算'));
                var btns = (g.kind === 'any')
                  ? '<button type="button" class="gear-preset-btn" data-gp-fill="' + i + '" data-gp-as="int"' + (g.fillable ? '' : ' disabled') + '>帶入內花鍵</button><button type="button" class="gear-preset-btn" data-gp-fill="' + i + '" data-gp-as="ext"' + (g.fillable ? '' : ' disabled') + '>帶入外花鍵</button>'
                  : '<button type="button" class="gear-preset-btn" data-gp-fill="' + i + '"' + (g.fillable ? '' : ' disabled') + '>帶入' + (g.label ? ' ' + gpEsc(g.label) : '') + '→' + dest + '</button>';
                h += '<div style="display:flex;align-items:center;gap:6px;margin-top:3px;flex-wrap:wrap;">' + btns
                  + '<span style="font-size:11.5px;font-family:Consolas,monospace;">' + (g.label ? gpEsc(g.label) + '：' : '') + gpEsc(g.summary || '—')
                  + (g.type ? ' <span style="color:#888;">（' + gpEsc(g.type) + '）</span>' : '')
                  + (g.fillable ? '' : ' <span style="color:#DD5138;">（缺模數或齒數，無法帶入）</span>') + '</span></div>';
            });
            box.innerHTML = h;
            if (autoFill && _gpGears.length === 1 && _gpGears[0].fillable && _gpGears[0].kind !== 'any') window.gearFillBasic(0);
        });
    };
    window.gearFillBasic = function(i, asKind) {
        var g = _gpGears[i]; if (!g || !g.fillable) return;
        function set(id, v){ var e = document.getElementById(id); if (e) e.value = v; }
        var kind = asKind || g.kind;
        if (kind === 'int' || kind === 'ext') {            // 花鍵：帶進對應的花鍵分頁（外／內）
            var pre = 'sp-' + kind + '-';
            if (g.std) set(pre + 'std', g.std);
            if (g.std === 'ANSIB922') set(pre + 'pd', g.pd); else set(pre + 'mn', g.mod);
            set(pre + 'z', g.teeth);
            set(pre + 'an', g.pa || 30);
            set(pre + 'x', g.x || '');
            set(pre + 'bt', g.helix || '');
            if (g.std === 'DIN5480' && g.dnom) set(pre + 'dnom', g.dnom);
            if (g.q) set(pre + 'q', g.q);
            if (g.fit) {   // 配合代號只收該分頁下拉有的選項（內：大寫 H/JS/K；外：小寫）
                var fe = document.getElementById(pre + 'fit'), f1 = kind === 'int' ? g.fit.toUpperCase() : g.fit.toLowerCase();
                if (fe) for (var o = 0; o < fe.options.length; o++) if (fe.options[o].value === f1) { fe.value = f1; break; }
            }
            if (kind === 'int' && window.splineIntStdChange) window.splineIntStdChange();
            if (kind === 'ext' && window.splineExtStdChange) window.splineExtStdChange();
            var tb = document.getElementById('gtab-sp-' + kind); if (tb) window.switchGearTab('sp-' + kind, tb);
            var inf = document.getElementById('g-part-info');
            if (inf) { var ds0 = inf.querySelectorAll('div'); for (var k0 = 0; k0 < ds0.length; k0++) ds0[k0].parentNode.removeChild(ds0[k0]);
                inf.innerHTML += '<div style="color:#2e7d32;font-weight:700;">✔ 已帶入' + (kind === 'int' ? '內花鍵' : '外花鍵') + '分頁，可手動修改後按計算</div>'; }
            return;
        }
        set('g-mn-unit', g.unit || 'M'); set('g-mn', g.mod); set('g-z', g.teeth);
        var an = parseFloat(g.pa); if (isFinite(an) && an > 0) window.setAlphaN(an); else window.setAlphaN(20);
        set('g-x', g.x || '');
        var rd = document.getElementById('g-bt-mode-deg'); if (rd) rd.checked = true;
        window.toggleBetaMode('deg'); set('g-bt', g.helix || '');
        window.updateGearMnDisplay();
        var info = document.getElementById('g-part-info');
        if (info) info.innerHTML += '<div style="color:#2e7d32;font-weight:700;">✔ 已帶入' + (g.label ? ' ' + gpEsc(g.label) : '') + '（模數、齒數、壓力角、轉位係數、螺旋角），可手動修改後按「計算齒輪」</div>';
        var st = info && info.querySelector('div'); // 只保留最新一則提示
        if (info) { var ds = info.querySelectorAll('div'); for (var k = 0; k < ds.length - 1; k++) ds[k].parentNode.removeChild(ds[k]); }
    };
    var _gpTimer = null;
    function gpInit() {
        var card = document.getElementById('g-part-card'); if (!card || card.getAttribute('data-gp') === '1') return;
        card.setAttribute('data-gp', '1');
        var q = document.getElementById('g-part-q'), res = document.getElementById('g-part-res');
        q.addEventListener('input', function(){
            clearTimeout(_gpTimer);
            var v = q.value.trim();
            if (!v) { res.style.display = 'none'; return; }
            _gpTimer = setTimeout(function(){
                gearAjax.post({action:'gear_part_search', q: v}, function(r){
                    var rows = (r && r.rows) || [];
                    if (!rows.length) { res.innerHTML = '<div style="padding:4px 8px;color:#999;font-size:11px;">查無已設定齒輪規格的料號</div>'; res.style.display = 'block'; return; }
                    res.innerHTML = rows.map(function(x){
                        return '<div data-gp-pick="' + x.d_id + '" style="padding:3px 8px;cursor:pointer;font-size:11.5px;border-bottom:1px solid #f1e6d3;">'
                            + gpEsc(x.D_Setting_Id) + (x.Spec_No ? ' <span style="color:#888;">' + gpEsc(x.Spec_No) + '</span>' : '')
                            + ' <span style="color:#b85c0d;">' + x.gear_cnt + ' 組</span></div>';
                    }).join('');
                    res.style.display = 'block';
                });
            }, 250);
        });
        card.addEventListener('click', function(e){
            var t = e.target.closest ? e.target.closest('[data-gp-pick],[data-gp-fill]') : null; if (!t) return;
            if (t.hasAttribute('data-gp-fill')) { window.gearFillBasic(parseInt(t.getAttribute('data-gp-fill'), 10), t.getAttribute('data-gp-as') || ''); return; }
            res.style.display = 'none';
            window.gearLoadPart(parseInt(t.getAttribute('data-gp-pick'), 10), true);
        });
    }

    // opt.dId＝要帶入齒輪規格的料號主檔 d_id（呼叫端知道是哪個料號時才傳；沒傳＝與原本完全相同）
    /* 齒形（漸開線）幾何：端面截形，齒中心線在上方。純數學，不碰任何畫面；
       畫到圖面由呼叫頁提供 window.egGearDrawHook(幾何) 負責（批圖編輯器）。 */
    window.gearDrawToCanvas = function() {
        window.gearMemSave();
        var p = _baseParams;
        if (!p) { alert('請先按「計算齒輪」'); return; }
        if (!window.gearEvalAll()) { alert('有數字欄的算式無法計算（紅框處），請修改'); return; }
        if (typeof window.egGearDrawHook !== 'function') return;
        var z = p.z, mn = p.mn, x = p.x || 0, an = p.alpha_n, at = p.alpha_t;
        var mt = mn / Math.cos(p.beta || 0);
        var r = mt * z / 2, rb = r * Math.cos(at), ra = p.da / 2;
        var rf = ((p.df != null) ? p.df : (mt * z - 2 * mn * (1.25 - x))) / 2;
        var internal = !!p.internal;
        var ri = p.da / 2, RfI = (mt * z + 2 * mn * (1.25 - x)) / 2, Router = 0;
        if (internal) {
            var og = readSegs('g-seg-list', true).filter(function (q) { return q.gear && q.D1 > 0; })[0];
            if (!og) { alert('內齒輪請在「外形」的齒部填外徑（內齒的外圓）'); return; }
            Router = og.D1 / 2;
            if (!(Router > RfI) || !(ri < RfI)) { alert('內齒輪外徑必須大於齒根圓（' + (2 * RfI).toFixed(2) + '）'); return; }
        } else if (!(ra > rf) || !(rb > 0)) { alert('齒頂圓必須大於齒根圓，請確認轉位係數與外徑'); return; }
        var rView = internal ? Router : ra, rTip = internal ? ri : ra, rRoot = internal ? RfI : rf;
        var r0 = Math.max(rb, rf);
        var s = mt * Math.PI / 2 + 2 * x * mn * Math.tan(an);       // 節圓上的端面齒厚
        var hp = s / (2 * r);
        var invAt = inv(at);
        function phi(rr) { var a = Math.acos(Math.min(1, rb / rr)); return Math.max(0, hp + invAt - inv(a)); }
        function P(rr, th) { return { x: rr * Math.sin(th), y: -rr * Math.cos(th) }; }
        var pitchAng = 2 * Math.PI / z, phi0 = phi(r0), phiTip = phi(ra);
        var NF = 14, NT = 6, NR = 6;
        function arc(rr, a1, a2, n, pts) { for (var i = 0; i <= n; i++) pts.push(P(rr, a1 + (a2 - a1) * i / n)); }
        function tooth(tc, pts) {
            for (var i = 0; i <= NF; i++) { var rr = r0 + (ra - r0) * i / NF; pts.push(P(rr, tc - phi(rr))); }
            arc(ra, tc - phiTip, tc + phiTip, NT, pts);
            for (var j = NF; j >= 0; j--) { var r2 = r0 + (ra - r0) * j / NF; pts.push(P(r2, tc + phi(r2))); }
        }
        var full = (document.getElementById('g-draw-mode').value === 'full');
        var nShow = Math.max(1, Math.min(12, parseInt(document.getElementById('g-draw-n').value, 10) || 4));
        if (!full && nShow >= z) full = true;
        var pts = [], ringSpan = null, first = 0;
        if (internal) {
            var rlo = Math.max(ri, rb * 1.0001);
            var hsI = (mt * Math.PI / 2 - 2 * x * mn * Math.tan(an)) / (2 * r);      // 節圓上齒槽半角（近似）
            var phiT = function (rr) { return Math.max(0, Math.PI / z - (hsI + invAt - inv(Math.acos(Math.min(1, rb / rr))))); };
            var toothI = function (tc, arr) {
                var ii;
                for (ii = 0; ii <= NF; ii++) { var r1 = RfI + (rlo - RfI) * ii / NF; arr.push(P(r1, tc - phiT(r1))); }
                arc(rlo, tc - phiT(rlo), tc + phiT(rlo), NT, arr);
                for (ii = NF; ii >= 0; ii--) { var r2 = RfI + (rlo - RfI) * ii / NF; arr.push(P(r2, tc + phiT(r2))); }
            };
            var phR = phiT(RfI);
            if (full) {
                for (var ti0 = 0; ti0 < z; ti0++) { var tcI = ti0 * pitchAng; toothI(tcI, pts); arc(RfI, tcI + phR, tcI + pitchAng - phR, NR, pts); }
            } else {
                first = -(nShow - 1) / 2 * pitchAng;
                arc(RfI, first - pitchAng / 2, first - phR, NR, pts);
                for (var tk = 0; tk < nShow; tk++) { var tcJ = first + tk * pitchAng; toothI(tcJ, pts); arc(RfI, tcJ + phR, tcJ + pitchAng - phR, NR, pts); }
                ringSpan = [first - pitchAng * 1.2, first + (nShow - 1) * pitchAng + pitchAng * 1.2];
            }
        } else if (full) {
            for (var i = 0; i < z; i++) {
                var tc = i * pitchAng;
                if (rf < r0) pts.push(P(rf, tc - phi0));
                tooth(tc, pts);
                if (rf < r0) pts.push(P(rf, tc + phi0));
                arc(rf, tc + phi0, tc + pitchAng - phi0, NR, pts);
            }
        } else {
            first = -(nShow - 1) / 2 * pitchAng;      // 以第 0 齒為中央，左右對稱
            arc(rf, first - pitchAng / 2, first - phi0, NR, pts);
            for (var k = 0; k < nShow; k++) {
                var tc2 = first + k * pitchAng;
                if (rf < r0) pts.push(P(rf, tc2 - phi0));
                tooth(tc2, pts);
                if (rf < r0) pts.push(P(rf, tc2 + phi0));
                arc(rf, tc2 + phi0, tc2 + pitchAng - phi0, NR, pts);
            }
            ringSpan = [first - pitchAng * 1.2, first + (nShow - 1) * pitchAng + pitchAng * 1.2];
        }
        // 縮放：整圈 → 外徑約 520px；局部 → 全部齒的寬度約 460px（只是示意大小，畫完自己拉到對齊）
        var scale = full ? (520 / (2 * rView)) : (460 / (nShow * Math.PI * mt));
        function sc(a) { return a.map(function (q) { return { x: q.x * scale, y: q.y * scale }; }); }
        var rings = [];
        function ring(rr, kind) {
            var a = [];
            if (full) { arc(rr, 0, 2 * Math.PI, 120, a); a.pop(); }
            else arc(rr, ringSpan[0], ringSpan[1], 40, a);
            rings.push({ pts: sc(a), closed: full, kind: kind });
        }
        if (document.getElementById('g-draw-pitch').checked) ring(r, 'pitch');
        if (document.getElementById('g-draw-tip').checked) ring(rTip, 'tip');
        if (document.getElementById('g-draw-root').checked) ring(rRoot, 'root');
        if (internal) ring(Router, 'bore');   // 內齒：外圓
        var chG = parseCham(document.getElementById('g-draw-c').value, ra, false);
        if (chG && chG.bad) { alert('齒端倒角格式不對（例：0.5、R1、1@30、Ø66@30）'); return; }
        var cham = chG ? chG.rd : 0;
        if (!internal && cham > 0 && ra - cham > rf) {   // 端面圖上的倒角線：齒頂外圓往內縮倒角量
            var rrc = ra - cham, phc = phi(rrc), tcs = [], ti;
            if (full) { for (ti = 0; ti < z; ti++) tcs.push(ti * pitchAng); }
            else { for (ti = 0; ti < nShow; ti++) tcs.push(first + ti * pitchAng); }
            tcs.forEach(function (tcc) { var aa = []; arc(rrc, tcc - phc, tcc + phc, 6, aa); rings.push({ pts: sc(aa), closed: false, kind: 'chamfer' }); });
        }
        // 端面圖：內孔（含鍵槽缺口）與端面螺孔（螺紋小徑整圈粗線、外徑 3/4 圈細線、分佈圓一點鏈線）
        if (document.getElementById('g-draw-endbore').checked) {
            var bsegs = readSegs('g-bore-list', false), kyp = keyParams(), hlp = holeParams();
            if (kyp === false || hlp === false) return;
            var TAU = 2 * Math.PI, bi;
            // 視角：從左或右端面看過去（決定哪些圓看得到＝實線、哪些被擋住＝虛線）
            var osegs = readSegs('g-seg-list', true).filter(function (q) { return q.L > 0; });
            var viewRight = (document.getElementById('g-view-side').value === 'right');
            var totalL = 0; osegs.forEach(function (q) { totalL += q.L; });
            var boreTot = 0; bsegs.forEach(function (q) { boreTot += q.L || 0; });
            var bx0v = (document.getElementById('g-bore-align').value === 'right') ? totalL - boreTot : 0, bEndv = bx0v + boreTot;
            var faceBore = null;   // 開口在所看那一面的孔段
            if (bsegs.length) {
                if (!viewRight && bx0v <= 1e-6) faceBore = bsegs[0];
                if (viewRight && (bEndv >= totalL - 1e-6)) faceBore = bsegs[bsegs.length - 1];
            }
            var rbF = faceBore && faceBore.D1 > 0 ? (viewRight ? (faceBore.D2 || faceBore.D1) : faceBore.D1) / 2 : 0;
            var kseg = (kyp && kyp.si >= 0 && bsegs[kyp.si]) ? bsegs[kyp.si] : faceBore;
            var rKey = kseg ? ((kseg === faceBore ? rbF : (kseg.D1 > 0 ? Math.max(kseg.D1, kseg.D2 || kseg.D1) / 2 : 0))) : 0;
            var notchPts = function (rr, withKey) {
                var pp = [];
                if (withKey && kyp && kyp.w < 2 * rr) {
                    var ka = Math.asin((kyp.w / 2) / rr);
                    for (bi = 0; bi <= 90; bi++) pp.push(P(rr, ka + (TAU - 2 * ka) * bi / 90));
                    pp.push({ x: -kyp.w / 2, y: -(rr + kyp.t) }); pp.push({ x: kyp.w / 2, y: -(rr + kyp.t) });
                } else { for (bi = 0; bi < 120; bi++) pp.push(P(rr, TAU * bi / 120)); }
                return pp;
            };
            if (!internal && rbF > 0) rings.push({ pts: sc(notchPts(rbF, kyp && kseg === faceBore)), closed: true, kind: 'bore' });
            if (!internal && kyp && kseg && kseg !== faceBore && rKey > 0) rings.push({ pts: sc(notchPts(rKey, true)), closed: true, kind: (faceBore && rKey <= rbF + 1e-9) ? 'bore' : 'hidden' });   // 透過孔看得到＝實線，被擋住＝虛線   // 開在較深的內徑段：被擋住畫虛線
            // 已填剖面資料：其他孔階／外形階段、中心線、剖面切割線（A-A）
            if (bsegs.length || osegs.length) {
                var circ = function (rr) { var c = []; for (var ci = 0; ci < 120; ci++) c.push(P(rr, TAU * ci / 120)); return c; };
                var rbView = internal ? ri : rbF, rMaxEnd = rView;
                bsegs.forEach(function (q) {
                    if (q === faceBore || !(q.D1 > 0) || (kyp && q === kseg)) return;
                    var rr = Math.max(q.D1, q.D2 || q.D1) / 2;
                    if (rbView > 0 && Math.abs(rr - rbView) < 1e-6) return;
                    // 開口那面有孔：比它小的孔段透過孔看得到＝實線；比較大的被材料擋住＝虛線；沒有開口那面＝全部被擋住
                    rings.push({ pts: sc(circ(rr)), closed: true, kind: (rbView > 0 && rr < rbView) ? 'bore' : 'hidden' });
                });
                var gIdx = -1; osegs.forEach(function (q, qi) { if (q.gear) gIdx = qi; });
                osegs.forEach(function (q, qi) {
                    if (q.gear || !(q.D1 > 0)) return;
                    var rr = Math.max(q.D1, q.D2 || q.D1) / 2;
                    rMaxEnd = Math.max(rMaxEnd, rr);
                    if (Math.abs(rr - rView) < 1e-6) return;
                    var nearer = (gIdx < 0) ? true : (viewRight ? qi > gIdx : qi < gIdx);   // 比齒部更靠近觀看這一側
                    // 在齒部前面的外形看得到＝實線；在後面：比齒小被擋住＝虛線，比齒大會露出來＝實線
                    rings.push({ pts: sc(circ(rr)), closed: true, kind: (nearer || rr > rView) ? 'bore' : 'hidden' });
                });
                var cx1 = rMaxEnd * 1.18;
                rings.push({ pts: sc([{ x: -cx1, y: 0 }, { x: cx1, y: 0 }]), closed: false, kind: 'center' });
                rings.push({ pts: sc([{ x: 0, y: -cx1 }, { x: 0, y: cx1 }]), closed: false, kind: 'center' });
                // 剖面切割線 A-A：兩端粗線＋箭頭（箭頭方向＝看過去的方向）＋字母；垂直線或水平線可選
                var cdir = document.getElementById('g-cut-dir').value, vert = cdir.charAt(0) === 'v';
                var dsg = (cdir === 'v-right' || cdir === 'h-down') ? 1 : -1, toCenter = cdir.indexOf('center') > 0;
                var c1 = rMaxEnd * 1.12, c2 = rMaxEnd * 1.36, ah = rMaxEnd * 0.2;
                [-1, 1].forEach(function (sg) {
                    var Pa = vert ? { x: 0, y: sg * c1 } : { x: sg * c1, y: 0 }, Pb = vert ? { x: 0, y: sg * c2 } : { x: sg * c2, y: 0 };
                    var dir = toCenter ? (vert ? { x: 0, y: -sg } : { x: -sg, y: 0 }) : (vert ? { x: dsg, y: 0 } : { x: 0, y: dsg }), nn = { x: -dir.y, y: dir.x };
                    rings.push({ pts: sc([Pa, Pb]), closed: false, kind: 'cut' });
                    // 箭頭「比著」切割線（->|）：箭桿在切割線的後方、尖端頂到切割線端點，方向＝看過去的方向
                    rings.push({ pts: sc([{ x: Pb.x - dir.x * ah * 1.25, y: Pb.y - dir.y * ah * 1.25 }, Pb]), closed: false, kind: 'cutline' });
                    var tip = { x: Pb.x, y: Pb.y }, bc = { x: Pb.x - dir.x * ah * 0.45, y: Pb.y - dir.y * ah * 0.45 };
                    rings.push({ pts: sc([tip, { x: bc.x + nn.x * ah * 0.14, y: bc.y + nn.y * ah * 0.14 }, { x: bc.x - nn.x * ah * 0.14, y: bc.y - nn.y * ah * 0.14 }]), closed: true, kind: 'arrow' });
                    var tp = sc([{ x: Pb.x - dir.x * ah * 1.9, y: Pb.y - dir.y * ah * 1.9 }])[0];
                    rings.push({ kind: 'text', text: 'A', x: tp.x, y: tp.y, size: 22 });
                });
            }
            var rdp = radParams(); if (rdp === false) return;
            if (rdp) {   // 外徑徑向螺孔：端面看不到孔口，畫成被擋住的虛線＋中心線
                var Rr = rView, cumx = 0;
                readSegs('g-seg-list', true).forEach(function (q) {
                    if (!(q.L > 0)) return;
                    var dq = q.D1 > 0 ? q.D1 : (q.gear ? p.da : 0);
                    if (rdp.x >= cumx && rdp.x <= cumx + q.L && dq > 0) Rr = dq / 2;
                    cumx += q.L;
                });
                var boreHere = 0, bcum = bx0v;   // 這個軸向位置的孔半徑（孔深到孔壁就停）
                bsegs.forEach(function (q) { var xs0 = bcum, xe0 = bcum + (q.L || 0); bcum = xe0; if (rdp.x >= xs0 && rdp.x <= xe0 && q.D1 > 0) boreHere = q.D1 / 2; });
                var ddE = Math.min(rdp.dd, Rr - boreHere), thr = rdp.dd >= Rr - boreHere - 1e-6;
                if (rdp.thru === 'bore') { ddE = Math.max(0, Rr - boreHere); thr = true; }
                else if (rdp.thru === 'all') { ddE = 2 * Rr; thr = true; }
                rdp.angles.forEach(function (aDeg) {
                    var th = aDeg * Math.PI / 180, ux = Math.sin(th), uy = -Math.cos(th), nx = Math.cos(th), ny = Math.sin(th);
                    var ddH = ddE;   // 孔正對內鍵槽：螺孔只打到鍵槽頂（不穿過鍵槽）
                    if (kyp && boreHere > 0 && Math.abs(boreHere * Math.sin(th)) < kyp.w / 2 + rdp.D / 2 && Math.cos(th) > 0) ddH = Math.min(ddH, Math.max(0, Rr - boreHere - kyp.t));
                    [-1, 1].forEach(function (sg) {
                        [rdp.dm / 2, rdp.D / 2].forEach(function (hh) {   // 每邊兩條：螺紋小徑＋大徑
                            var o1 = sg * hh;
                            rings.push({ pts: sc([{ x: ux * Rr + nx * o1, y: uy * Rr + ny * o1 }, { x: ux * (Rr - ddH) + nx * o1, y: uy * (Rr - ddH) + ny * o1 }]), closed: false, kind: 'hidden' });
                        });
                    });
                    rings.push({ pts: sc([{ x: ux * (Rr + Rr * 0.08), y: uy * (Rr + Rr * 0.08) }, { x: ux * (Rr - ddE - (thr ? 0 : rdp.cone)), y: uy * (Rr - ddE - (thr ? 0 : rdp.cone)) }]), closed: false, kind: 'center' });
                });
            }
            var angDims = [];   // 角度標示：獨立的標註物件（兩端有箭頭、可拖曳調整半徑），數字一律正值
            var angArc = function (aDeg, rA, nomDeg, eMm) {   // 弧畫在實際位置；數字標使用者輸入的名義角度（對齊齒頂後弧與數字可能差一點）
                var av = ((aDeg + 180) % 360 + 360) % 360 - 180;
                if (Math.abs(av) < 0.01) return;
                var nv = (nomDeg == null) ? av : ((nomDeg + 180) % 360 + 360) % 360 - 180;
                angDims.push({ kind: 'a', r: rA * scale, a0: 0, a1: av, e: eMm ? eMm * scale : 0, e0: (eMm && typeof rMaxEnd !== 'undefined') ? rMaxEnd * 1.18 * scale : 0, text: fmtDim(Math.abs(nv)) + '°' });
            };
            if (typeof rdp !== 'undefined' && rdp) rdp.angles.forEach(function (aDeg, ai) { angArc(aDeg, Rr * (0.42 + 0.07 * ai), rdp.nomAngles ? rdp.nomAngles[ai] : null, Rr * 1.08); });
            if (hlp) {
                var pcp = []; for (bi = 0; bi < 120; bi++) pcp.push(P(hlp.Rp, TAU * bi / 120));
                rings.push({ pts: sc(pcp), closed: true, kind: 'pcd' });
                for (var hi = 0; hi < hlp.angles.length; hi++) {
                    var th = hlp.angles[hi] * Math.PI / 180, hcx = hlp.Rp * Math.sin(th), hcy = -hlp.Rp * Math.cos(th), cmn = [], cmj = [], aa;
                    for (bi = 0; bi < 48; bi++) { aa = TAU * bi / 48; cmn.push({ x: hcx + hlp.dm / 2 * Math.cos(aa), y: hcy + hlp.dm / 2 * Math.sin(aa) }); }
                    for (bi = 0; bi <= 36; bi++) { aa = TAU * 0.75 * bi / 36; cmj.push({ x: hcx + hlp.D / 2 * Math.cos(aa), y: hcy + hlp.D / 2 * Math.sin(aa) }); }
                    rings.push({ pts: sc(cmn), closed: true, kind: 'hole' });
                    rings.push({ pts: sc(cmj), closed: false, kind: 'holethin' });
                }
                hlp.angles.forEach(function (aDeg, ai) { angArc(aDeg, hlp.Rp * (0.5 - 0.06 * ai)); });
            }
        }
        var dimsEnd = [];
        if (document.getElementById('g-dim-on').checked && typeof angDims !== 'undefined') angDims.forEach(function (q) { dimsEnd.push(q); });
        if (document.getElementById('g-dim-on').checked && !internal && typeof kyp !== 'undefined' && kyp && rKey > 0) {   // 內鍵槽：寬、與孔徑加深度的總高
            var kTop = -(rKey + kyp.t) * scale, kbot = rKey * scale, kw2 = kyp.w / 2 * scale;
            dimsEnd.push({ kind: 'h', x1: -kw2, x2: kw2, y: kTop - 26, e1: kTop, e2: kTop, text: fmtDim(kyp.w) });
            dimsEnd.push({ kind: 'v', x: -(rKey * scale) - 30, y1: kTop, y2: kbot, e1: -kw2, e2: 0, text: fmtDim(2 * rKey + kyp.t) });
        }
        if (document.getElementById('g-dim-on').checked && full) {
            var tOD2 = dimTol('g-tol-od'), RR = rView * scale;
            dimsEnd.push({ kind: 'v', x: RR * 1.32, y1: -RR, y2: RR, e1: 0, e2: 0, text: 'Ø' + fmtDim(2 * rView) + (tOD2 ? ' ' + tOD2 : '') });
        }
        window.egGearDrawHook({ dims: dimsEnd, outline: { pts: sc(pts), closed: full }, rings: rings,
            desc: '齒形 m' + mt.toFixed(3) + ' z' + z + (full ? '' : '（局部 ' + nShow + ' 齒）') });
    };
    /* ── 剖面圖：外形（外徑×長度）與內孔（孔徑×長度）各自由左到右分段，兩邊分段位置不必對齊。
       另可加鍵槽（內孔上方）與端面螺孔。純幾何，畫到圖面交給呼叫頁的 hook（egGearDrawItems） ── */
    function segRowHtml(kind, isGear, v) {
        v = v || {};
        var st = 'class="gear-input" style="width:100%;min-width:0;padding:3px 3px;" step="any" min="0"';
        function inp(f, ph) {
            if (f === 'D2' || f === 'cL' || f === 'cR') return '<input type="text" class="gear-input" style="width:100%;min-width:0;padding:3px 3px;' + (f === 'D2' ? 'display:none;' : '') + '" data-f="' + f + '" placeholder="' + ph + '" value="' + (v[f] != null ? v[f] : '') + '">';
            return '<input type="text" inputmode="decimal" data-expr="1" ' + st + ' data-f="' + f + '" placeholder="' + ph + '" value="' + (v[f] != null ? v[f] : '') + '">';
        }
        function chamCell(f) {
            return '<span style="display:flex;align-items:center;gap:1px;min-width:0;">' + inp(f, 'C/R/角') +
                '<input type="checkbox" data-f="' + f + 'lab" title="在圖上標示這個倒角（取消勾選＝只畫倒角、不標文字）"' + ((v[f + 'lab'] === false) ? '' : ' checked') + ' style="flex:0 0 auto;margin:0;"></span>';
        }
        var head = (kind === 'out')
            ? '<label style="display:flex;flex-direction:column;line-height:1.2;white-space:nowrap;cursor:pointer;"><b class="g-seg-no">第1段</b><span><input type="radio" name="g-seg-gear"' + (isGear ? ' checked' : '') + '> 齒部</span></label>'
            : '<b class="g-seg-no">第1段</b>';
        return '<div class="g-seg" style="display:grid;grid-template-columns:62px repeat(4,minmax(0,1fr)) 72px;gap:3px 4px;align-items:center;margin-bottom:3px;font-size:11px;">' +
            head +
            inp('D', kind === 'out' ? (isGear ? '自動da' : '外徑') : '孔徑') + inp('D2', '迄徑/錐度') + inp('L', '長度') + inp('cL', 'C/R/角') + inp('cR', 'C/R/角') +
            '<span style="display:flex;gap:2px;">' +
            '<button type="button" class="btn-gear-clr" style="padding:1px 4px;" onclick="gearSegMove(this,-1)" title="往左移（變成前一段）">←</button>' +
            '<button type="button" class="btn-gear-clr" style="padding:1px 4px;" onclick="gearSegMove(this,1)" title="往右移（變成後一段）">→</button>' +
            '<button type="button" class="btn-gear-clr" style="padding:1px 4px;" onclick="gearSegDel(this)" title="刪除這一段">×</button></span></div>';
    }
    function taperOn(kind) { var e = document.getElementById(kind === 'bore' ? 'g-taper-bore' : 'g-taper-out'); return !!(e && e.checked); }
    window.gearTaperApply = function () {
        ['out', 'bore'].forEach(function (kind) {
            var on = taperOn(kind), cols = on ? '62px repeat(5,minmax(0,1fr)) 72px' : '62px repeat(4,minmax(0,1fr)) 72px';
            var box = segListEl(kind); if (!box) return;
            Array.prototype.forEach.call(box.querySelectorAll('.g-seg'), function (r) {
                r.style.gridTemplateColumns = cols;
                var d = r.querySelector('[data-f=D2]'); d.style.display = on ? '' : 'none'; if (!on) d.value = '';
            });
            var head = document.querySelector('.g-seg-head[data-list="' + kind + '"]');
            if (head) { head.style.gridTemplateColumns = cols; var th = head.querySelector('.g-th-taper'); if (th) th.style.display = on ? '' : 'none'; }
        });
    };
    // 算式：支援 + - * / ( ) × ÷ x；括號不成對時自動補（40-14)/2 → (40-14)/2）
    function evalExpr(str) {
        var t = String(str == null ? '' : str)
            .replace(/[０-９]/g, function (c) { return String.fromCharCode(c.charCodeAt(0) - 0xFEE0); })
            .replace(/[（]/g, '(').replace(/[）]/g, ')').replace(/[＋]/g, '+').replace(/[－−]/g, '-').replace(/[．]/g, '.')
            .replace(/[×xX＊]/g, '*').replace(/[÷／]/g, '/').replace(/\s+/g, '');
        if (!t || /^[0-9.]+$/.test(t)) return undefined;   // 空白或單純數字：不處理
        if (!/^[0-9+\-*/().]+$/.test(t)) return NaN;
        var op = (t.match(/\(/g) || []).length, cl = (t.match(/\)/g) || []).length;
        if (cl > op) t = new Array(cl - op + 1).join('(') + t; else if (op > cl) t += new Array(op - cl + 1).join(')');
        var v;
        try { v = Function('"use strict";return (' + t + ')')(); } catch (e) { return NaN; }
        return isFinite(v) ? Math.round(v * 1e6) / 1e6 : NaN;
    }
    window.gearEvalInput = function (el) {
        var r = evalExpr(el.value);
        if (r === undefined) { el.style.borderColor = ''; return true; }
        if (isNaN(r)) { el.style.borderColor = '#DD5138'; return false; }
        el.value = String(r); el.style.borderColor = ''; return true;
    };
    window.gearEvalAll = function () {
        var ok = true;
        Array.prototype.forEach.call(document.querySelectorAll('#g-draw-block [data-expr]'), function (el) { if (!window.gearEvalInput(el)) ok = false; });
        return ok;
    };
    function segListEl(kind) { return document.getElementById(kind === 'bore' ? 'g-bore-list' : 'g-seg-list'); }
    function refreshKeySelects() {
        function fill(id, listId, first) {
            var sel = document.getElementById(id); if (!sel) return;
            var keep = sel.value, html = first || '', rows = document.querySelectorAll('#' + listId + ' .g-seg');
            for (var i = 0; i < rows.length; i++) { var d = rows[i].querySelector('[data-f=D]').value; html += '<option value="' + i + '">第' + (i + 1) + '段' + (d ? ' Ø' + d : '') + '</option>'; }
            sel.innerHTML = html;
            if (Array.prototype.some.call(sel.options, function (o) { return o.value === keep; })) sel.value = keep;
        }
        fill('g-key-seg', 'g-bore-list', '<option value="-1">整個內孔</option>');
        fill('g-okey-seg', 'g-seg-list', '');
    }
    window.gearRefreshKeySelects = refreshKeySelects;
    function segRenumber() {
        refreshKeySelects();
        ['g-seg-list', 'g-bore-list'].forEach(function (id) {
            var rs = document.querySelectorAll('#' + id + ' .g-seg');
            for (var i = 0; i < rs.length; i++) rs[i].querySelector('.g-seg-no').textContent = '第' + (i + 1) + '段';
        });
    }
    window.gearSegAdd = function (kind) {
        kind = (kind === 'bore') ? 'bore' : 'out';
        var box = segListEl(kind); if (!box) return;
        box.insertAdjacentHTML('beforeend', segRowHtml(kind, kind === 'out' && box.children.length === 0));
        segRenumber(); window.gearTaperApply();
    };
    window.gearSegDel = function (btn) {
        var row = btn.closest('.g-seg'), box = row.parentNode;
        var isOut = box.id === 'g-seg-list';
        if (isOut && box.children.length <= 1) return;
        var wasGear = isOut && row.querySelector('input[type=radio]').checked;
        box.removeChild(row);
        if (wasGear) box.children[0].querySelector('input[type=radio]').checked = true;
        segRenumber();
    };
    window.gearSegMove = function (btn, dir) {
        var row = btn.closest('.g-seg'), box = row.parentNode;
        if (dir < 0 && row.previousElementSibling) box.insertBefore(row, row.previousElementSibling);
        else if (dir > 0 && row.nextElementSibling) box.insertBefore(row.nextElementSibling, row);
        segRenumber();
    };
    window.gearSegInit = function () {
        var o = segListEl('out'), b = segListEl('bore');
        // 外徑和計算出的齒輪外徑相同的那一段，自動認定為齒部（之後仍可手動改選）
        if (o && !o.__autoGear) {
            o.__autoGear = true;
            o.addEventListener('input', function (e) {
                var t = e.target;
                if (!t || t.getAttribute('data-f') !== 'D' || !_baseParams || _baseParams.internal) return;
                var v = parseFloat(t.value);
                if (v > 0 && Math.abs(v - _baseParams.da) < 0.0051) { var rd = t.closest('.g-seg').querySelector('input[type=radio]'); if (rd) rd.checked = true; }
            });
        }
        // 輸入框按 Enter＝跳到下一個輸入框
        var blk = document.getElementById('g-draw-block');
        if (blk && !blk.__exprInit) {   // 靜態數字欄一律改成文字欄，才能打算式
            blk.__exprInit = true;
            Array.prototype.forEach.call(blk.querySelectorAll('input[type=number]'), function (el) { el.type = 'text'; el.setAttribute('inputmode', 'decimal'); el.setAttribute('data-expr', '1'); });
            blk.addEventListener('change', function (e) {
                var t = e.target;
                if (t && t.getAttribute && t.getAttribute('data-expr')) window.gearEvalInput(t);
                if (t && t.closest && t.closest('#g-seg-list,#g-bore-list')) refreshKeySelects();
            });
        }
        if (blk && !blk.__enterNav) {
            blk.__enterNav = true;
            blk.addEventListener('keydown', function (e) {
                // 段落表格：↑↓＝同一欄往上／往下一列；最後一列按↓自動新增一列；空白的最後一列按↑自動移除
                if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                    var ta = e.target, rowA = ta && ta.closest ? ta.closest('.g-seg') : null;
                    if (!rowA || ta.tagName !== 'INPUT' || ta.type === 'radio') return;
                    var fA = ta.getAttribute('data-f'); if (!fA) return;
                    e.preventDefault();
                    if (ta.getAttribute('data-expr')) window.gearEvalInput(ta);
                    var boxA = rowA.parentNode, kindA = boxA.id === 'g-bore-list' ? 'bore' : 'out', tgt, newRowA = false;
                    if (e.key === 'ArrowDown') {
                        tgt = rowA.nextElementSibling;
                        if (!tgt) { window.gearSegAdd(kindA); tgt = boxA.lastElementChild; newRowA = true; }
                    } else {
                        tgt = rowA.previousElementSibling;
                        if (!tgt) return;
                        var emptyRow = true;
                        Array.prototype.forEach.call(rowA.querySelectorAll('input:not([type=radio]):not([type=checkbox])'), function (el) { if (String(el.value).trim() !== '') emptyRow = false; });
                        if (!rowA.nextElementSibling && emptyRow && (kindA === 'bore' || boxA.children.length > 1)) {
                            var wasG = kindA === 'out' && rowA.querySelector('input[type=radio]').checked;
                            boxA.removeChild(rowA);
                            if (wasG && boxA.children.length) boxA.children[0].querySelector('input[type=radio]').checked = true;
                            segRenumber();
                        }
                    }
                    var nxA = tgt.querySelector('[data-f=' + (newRowA ? 'D' : fA) + ']');
                    if (nxA) { nxA.focus(); if (nxA.select) nxA.select(); }
                    return;
                }
                if (e.key !== 'Enter') return;
                var t = e.target;
                if (!t || (t.tagName !== 'INPUT' && t.tagName !== 'SELECT') || t.type === 'checkbox' || t.type === 'radio' || t.type === 'button') return;
                e.preventDefault();
                if (t.getAttribute('data-expr')) window.gearEvalInput(t);
                var list = Array.prototype.slice.call(blk.querySelectorAll('input:not([type=checkbox]):not([type=radio]):not([type=button]),select')).filter(function (el) { return el.offsetParent !== null && !el.disabled; });
                var nx = list[list.indexOf(t) + 1];
                if (nx) { nx.focus(); if (nx.select && nx.tagName === 'INPUT') nx.select(); }
            });
        }
        if (o && !o.children.length) { window.gearSegAdd('out'); window.gearSegAdd('out'); }
        if (b && !b.children.length) window.gearSegAdd('bore');
        window.gearTaperApply();
    };

    // ══ 記憶設定（只存在這台電腦的瀏覽器 localStorage）══════════════════════
    // ① 預設值：⑥自動標註區與畫圖選項，使用者按「存為預設」後，每次開啟先套用
    // ② 依料號記憶：由料號開啟時，最後一次畫圖／畫剖面圖／3D 的全部數據（外形、內孔、倒角、鍵槽、螺孔…）
    var GMEM_DEF_IDS = ['g-dim-on', 'g-dim-fs',  'g-align', 'g-dim-ch-gear', 'g-dim-ch-out', 'g-dim-ch-bore', 'g-show-obl', 'g-tol-od', 'g-tol-id', 'g-tol-len', 'g-draw-hatch', 'g-draw-hatch-gap', 'g-draw-edge', 'g-view-side', 'g-cut-dir', 'g-draw-endbore'];
    var _gearCurD = 0;
    function gmemGet(k) { try { var v = localStorage.getItem(k); return v ? JSON.parse(v) : null; } catch (e) { return null; } }
    function gmemSet(k, o) { try { localStorage.setItem(k, JSON.stringify(o)); return true; } catch (e) { return false; } }
    function gmemRead(ids) {
        var o = {};
        ids.forEach(function (id) { var el = document.getElementById(id); if (!el) return; o[id] = (el.type === 'checkbox') ? !!el.checked : el.value; });
        return o;
    }
    function gmemWrite(o) {
        Object.keys(o || {}).forEach(function (id) { var el = document.getElementById(id); if (!el) return; if (el.type === 'checkbox') el.checked = !!o[id]; else el.value = o[id]; });
    }
    window.gearDefaultsSave = function () {
        if (gmemSet('eg_gear_defaults', gmemRead(GMEM_DEF_IDS))) { if (window.showToast) showToast('已存為預設（這台電腦）'); else alert('已存為預設（這台電腦）'); }
        else alert('無法儲存（瀏覽器不允許寫入）');
    };
    window.gearDefaultsClear = function () {
        try { localStorage.removeItem('eg_gear_defaults'); } catch (e) {}
        alert('已清除自訂預設；重新整理頁面後回到系統預設。');
    };
    function gmemApplyDefaults() { var d = gmemGet('eg_gear_defaults'); if (d) gmemWrite(d); }
    function gmemSegs(id) {
        var rows = document.querySelectorAll('#' + id + ' .g-seg'), out = [];
        Array.prototype.forEach.call(rows, function (r) {
            var f = {}; Array.prototype.forEach.call(r.querySelectorAll('[data-f]'), function (el) { f[el.getAttribute('data-f')] = el.value; });
            var rd = r.querySelector('input[type=radio]'); out.push({ f: f, gear: !!(rd && rd.checked) });
        });
        return out;
    }
    window.gearMemSave = function () {
        if (!_gearCurD) return;
        var blk = document.getElementById('g-draw-block'); if (!blk) return;
        var ids = []; Array.prototype.forEach.call(blk.querySelectorAll('input[id],select[id]'), function (el) { if (el.id) ids.push(el.id); });
        gmemSet('eg_gear_part_' + _gearCurD, { v: gmemRead(ids), out: gmemSegs('g-seg-list'), bore: gmemSegs('g-bore-list') });
    };
    function gmemRestoreSegs(kind, listId, arr) {
        var box = segListEl(kind); if (!box || !arr || !arr.length) return;
        box.innerHTML = '';
        arr.forEach(function () { window.gearSegAdd(kind); });
        var rows = document.querySelectorAll('#' + listId + ' .g-seg');
        arr.forEach(function (a, i) {
            var r = rows[i]; if (!r) return;
            Object.keys(a.f).forEach(function (k) { var el = r.querySelector('[data-f=' + k + ']'); if (el) el.value = a.f[k]; });
            var rd = r.querySelector('input[type=radio]'); if (rd) rd.checked = !!a.gear;
        });
        if (kind === 'out' && !box.querySelector('input[type=radio]:checked') && box.children[0]) box.children[0].querySelector('input[type=radio]').checked = true;
    }
    function gmemRestorePart(dId) {
        var m = gmemGet('eg_gear_part_' + dId); if (!m) return false;
        gmemRestoreSegs('out', 'g-seg-list', m.out); gmemRestoreSegs('bore', 'g-bore-list', m.bore);
        window.gearTaperApply(); refreshKeySelects();
        gmemWrite(m.v);   // 鍵槽段選單要在 refreshKeySelects 之後才設得到值
        return true;
    }
    window.gearSegExample = function () {
        var o = segListEl('out'), b = segListEl('bore'); if (!o || !b) return;
        o.innerHTML = segRowHtml('out', true, { L: 18.5 }) + segRowHtml('out', false, { D: 40, L: 25, cR: 1 });
        b.innerHTML = segRowHtml('bore', false, { D: 14, L: 43.5, cL: 0.5, cR: 0.5 });
        segRenumber(); window.gearTaperApply();
        function setv(id, val) { var e = document.getElementById(id); if (e) e.value = val; }
        setv('g-draw-c', '0.5'); setv('g-draw-cend', 'both');
        document.getElementById('g-key-on').checked = true; setv('g-key-w', 5); setv('g-key-t', 2.3);
        document.getElementById('g-hole-on').checked = true; setv('g-hole-face', 'right'); setv('g-hole-pcd', 28); setv('g-hole-depth', 8); setv('g-hole-n', 2);
    };
    // 迄徑／錐度欄：空白＝同起徑；純數字＝迄徑；1/10 或 1:10＝錐度（直徑變化÷長度）；3°＝半錐角；負號＝往右變大
    function d2of(s) {
        var t = String(s.D2raw == null ? '' : s.D2raw).replace(/\s+/g, '').replace(/[－−]/g, '-');
        if (!t) return s.D1;
        var neg = false; if (t.charAt(0) === '-') { neg = true; t = t.slice(1); }
        var dD;
        if (/°$|deg$/i.test(t)) { var ang = parseFloat(t); if (!(ang >= 0 && ang < 89)) return NaN; dD = 2 * s.L * Math.tan(ang * Math.PI / 180); }
        else if (/[\/:]/.test(t)) { var q = t.split(/[\/:]/), a = parseFloat(q[0]), b = parseFloat(q[1]); if (!(a >= 0) || !(b > 0)) return NaN; dD = s.L * a / b; }
        else { var vv = parseFloat(t); return (vv > 0 && !neg) ? vv : NaN; }
        return neg ? s.D1 + dD : s.D1 - dD;
    }
    function readSegs(listId, isOut) {
        var out = [], rows = document.querySelectorAll('#' + listId + ' .g-seg');
        for (var i = 0; i < rows.length; i++) {
            (function (row, i) {
                function f(k) { var v = parseFloat(row.querySelector('[data-f=' + k + ']').value); return isNaN(v) ? null : v; }
                var s = { no: i + 1, D1: f('D'), D2raw: taperOn(listId === 'g-bore-list' ? 'bore' : 'out') ? row.querySelector('[data-f=D2]').value : '', L: f('L'), cLraw: row.querySelector('[data-f=cL]').value, cRraw: row.querySelector('[data-f=cR]').value,
                          labL: (function () { var c = row.querySelector('[data-f=cLlab]'); return c ? c.checked : true; })(), labR: (function () { var c = row.querySelector('[data-f=cRlab]'); return c ? c.checked : true; })(),
                          gear: isOut && row.querySelector('input[type=radio]').checked };
                if (!isOut && s.D1 == null && s.L == null) return;   // 內孔：整列空白＝略過
                s.D2 = (s.D1 > 0 && s.L > 0) ? d2of(s) : null;
                out.push(s);
            })(rows[i], i);
        }
        return out;
    }
    function holeParams() {
        if (!document.getElementById('g-hole-on').checked) return null;
        var sz = document.getElementById('g-hole-size').value.split(',');
        var D = parseFloat(sz[0]), P = parseFloat(sz[1]);
        var pcd = parseFloat(document.getElementById('g-hole-pcd').value), td = parseFloat(document.getElementById('g-hole-depth').value);
        if (!(pcd > 0) || !(td > 0)) { alert('螺孔請填分佈圓 PCD 與螺紋深'); return false; }
        var dd = parseFloat(document.getElementById('g-hole-drill').value);
        if (!(dd > 0)) dd = td + D * 0.5;
        if (dd < td) dd = td;
        var n0 = Math.max(1, parseInt(document.getElementById('g-hole-n').value, 10) || 1);
        var angles = anglesFrom(document.getElementById('g-hole-ang').value, n0, document.getElementById('g-hole-step').value).map(function (a) { return ((a % 360) + 360) % 360; });
        return { D: D, P: P, dm: D - 1.0825 * P, Rp: pcd / 2, td: td, dd: dd, cone: (D - 1.0825 * P) / 2 / Math.tan(59 * Math.PI / 180),
                 n: angles.length, angles: angles, face: document.getElementById('g-hole-face').value, label: 'M' + D + 'x' + P };
    }
    function okeyParams() {
        if (!document.getElementById('g-okey-on').checked) return null;
        var w = parseFloat(document.getElementById('g-okey-w').value), t = parseFloat(document.getElementById('g-okey-t').value), l = parseFloat(document.getElementById('g-okey-l').value);
        if (!(w > 0) || !(t > 0)) { alert('外鍵槽請填寬與深'); return false; }
        var si = parseInt(document.getElementById('g-okey-seg').value, 10); if (isNaN(si) || si < 0) si = 0;
        return { w: w, t: t, x: parseFloat(document.getElementById('g-okey-x').value) || 0, l: (l > 0 ? l : null), si: si };
    }
    function keyParams() {
        if (!document.getElementById('g-key-on').checked) return null;
        var w = parseFloat(document.getElementById('g-key-w').value), t = parseFloat(document.getElementById('g-key-t').value);
        if (!(w > 0) || !(t > 0)) { alert('鍵槽請填寬與深'); return false; }
        var x = parseFloat(document.getElementById('g-key-x').value) || 0, l = parseFloat(document.getElementById('g-key-l').value);
        var si = parseInt(document.getElementById('g-key-seg').value, 10); if (isNaN(si)) si = -1;
        return { w: w, t: t, x: x, l: (l > 0 ? l : null), si: si };
    }
    // 倒角輸入格式：0.5＝C0.5（45°）；R1＝圓角；1@30＝軸向 1、與軸線 30°；Ø60@30＝端面上 Ø60 處起、與端面 30°
    function parseCham(txt, Rc, bore) {
        var t = String(txt == null ? '' : txt).replace(/\s+/g, '').replace(/[Øøφ⌀]/g, 'D');
        if (!t) return null;
        var m;
        if ((m = /^R(\d*\.?\d+)$/i.exec(t))) { var rr = parseFloat(m[1]); return rr > 0 ? { ax: rr, rd: rr, fillet: rr, label: 'R' + fmtDim(rr) } : null; }
        if ((m = /^D(\d*\.?\d+)@(\d*\.?\d+)°?$/i.exec(t))) {
            var D = parseFloat(m[1]), th = parseFloat(m[2]), rd = bore ? D / 2 - Rc : Rc - D / 2;
            if (!(rd > 0) || !(th > 0 && th < 90)) return { bad: true };
            return { ax: rd * Math.tan(th * Math.PI / 180), rd: rd, fillet: 0, label: 'Ø' + fmtDim(D) + '×' + fmtDim(th) + '°' };
        }
        if ((m = /^C?(\d*\.?\d+)@(\d*\.?\d+)°?$/i.exec(t))) {
            var L = parseFloat(m[1]), th2 = parseFloat(m[2]);
            if (!(L > 0 && th2 > 0 && th2 < 90)) return { bad: true };
            return { ax: L, rd: L * Math.tan(th2 * Math.PI / 180), fillet: 0, label: fmtDim(L) + '×' + fmtDim(th2) + '°' };
        }
        if ((m = /^C?(\d*\.?\d+)$/i.exec(t))) { var c = parseFloat(m[1]); return c > 0 ? { ax: c, rd: c, fillet: 0, label: 'C' + fmtDim(c) } : null; }
        return { bad: true };
    }
    function clampCh(ch, maxAx, maxRd) {
        if (!ch || ch.bad) return null;
        var k = Math.min(1, maxAx / ch.ax, maxRd / ch.rd);
        return (k < 1) ? { ax: ch.ax * k, rd: ch.rd * k, fillet: ch.fillet ? ch.fillet * k : 0, label: ch.label } : ch;
    }
    function arcPts(cx, cy, r, a0, a1, n) {   // 圓弧取樣（角度為弧度，數學座標）
        var o = []; for (var i = 0; i <= n; i++) { var a = a0 + (a1 - a0) * i / n; o.push([cx + r * Math.cos(a), cy + r * Math.sin(a)]); }
        return o;
    }
    function fmtDim(v) { return String(Math.round(v * 1000) / 1000); }
    function dimTol(id) { var e = document.getElementById(id); return e ? String(e.value || '').trim() : ''; }
    // 角度清單：含逗號＝逐孔指定；否則「起始角＋孔間夾角（空白＝平均分佈）」
    function anglesFrom(txt, n, stepTxt) {
        var t = String(txt == null ? '' : txt).trim(), out = [], k;
        if (/[,，]/.test(t)) { t.split(/[,，]/).forEach(function (x) { var v = parseFloat(x); if (!isNaN(v)) out.push(v); }); return out.length ? out : [0]; }
        var a0 = parseFloat(t) || 0, st = parseFloat(stepTxt); if (isNaN(st) || st === 0) st = 360 / n;
        for (k = 0; k < n; k++) out.push(a0 + st * k);
        return out;
    }
    function cutIsH() { return document.getElementById('g-cut-dir').value.charAt(0) === 'h'; }
    // 切面通過的孔／鍵槽角度：垂直切割線＝0°；水平切割線朝上＝看到上方（0°）的鍵槽與孔（在視圖正中央）；朝下＝看下半，上方的鍵槽與孔不存在，只剩 90°／270° 方向的孔
    function cutBaseAngle() { var v = document.getElementById('g-cut-dir').value; return (v.charAt(0) === 'h' && v !== 'h-up') ? 90 : 0; }
    function angNear(a, t) { var d = (((a - t) % 360) + 360) % 360; return d < 0.01 || d > 359.99; }
    function radParams() {
        if (!document.getElementById('g-rad-on').checked) return null;
        var sz = document.getElementById('g-rad-size').value.split(','), D = parseFloat(sz[0]), P = parseFloat(sz[1]);
        var n0 = Math.max(1, parseInt(document.getElementById('g-rad-n').value, 10) || 1), align = document.getElementById('g-rad-align').value;
        var xp = parseFloat(document.getElementById('g-rad-x').value), td = parseFloat(document.getElementById('g-rad-depth').value);
        if (!(xp > 0)) {   // 空白＝齒部那一段的正中央（沒有齒部就放全長中央）
            var cum = 0, gc = null, tot = 0;
            readSegs('g-seg-list', true).forEach(function (q) { if (q.L > 0) { if (q.gear && gc == null) gc = cum + q.L / 2; cum += q.L; } });
            tot = cum; xp = (gc != null) ? gc : tot / 2;
            if (!(xp > 0)) { alert('外徑螺孔請先填外形各段長度，或直接填軸向位置'); return false; }
        }
        if (!(td > 0)) { alert('外徑螺孔請填螺紋深'); return false; }
        var dd = parseFloat(document.getElementById('g-rad-drill').value); if (!(dd > 0)) dd = td + D * 0.5; if (dd < td) dd = td;
        var pz = _baseParams ? 360 / _baseParams.z : 0;
        var nomA = anglesFrom(document.getElementById('g-rad-ang').value, n0, document.getElementById('g-rad-step').value);
        var angles = nomA.map(function (a) {
            if (align !== 'any' && pz > 0) { var q = a / pz; a = (align === 'tip' ? Math.round(q) : Math.round(q - 0.5) + 0.5) * pz; }   // 齒中心＝0°、整數倍齒距；齒底＝半個齒距處
            return ((a % 360) + 360) % 360;
        });
        var dm = D - 1.0825 * P;
        return { D: D, P: P, dm: dm, n: angles.length, angles: angles, nomAngles: nomA, x: xp, td: td, dd: dd, thru: document.getElementById('g-rad-thru').value, cone: dm / 2 / Math.tan(59 * Math.PI / 180), label: 'M' + D + 'x' + P };
    }
    window.gearDrawSection = function () {
        window.gearMemSave();
        var p = _baseParams;
        if (typeof window.egGearDrawItems !== 'function') return;
        if (!window.gearEvalAll()) { alert('有數字欄的算式無法計算（紅框處），請修改'); return; }
        var eps = 1e-9;
        var outer = readSegs('g-seg-list', true), bore = readSegs('g-bore-list', false);
        if (!outer.length) { alert('外形請至少填一段'); return; }
        var cGtxt = String(document.getElementById('g-draw-c').value || '').trim(), cend = document.getElementById('g-draw-cend').value;
        var x = 0, gearIdx = -1, i;
        for (i = 0; i < outer.length; i++) {
            var s = outer[i];
            if (s.gear && !(s.D1 > 0)) {
                if (!p) { alert('請先按「計算齒輪」，齒部外徑才會自動帶入'); return; }
                if (p.internal) { alert('內齒輪的齒部外徑要自己填（外形第 ' + s.no + ' 段）'); return; }
                s.D1 = p.da;
            }
            if (!(s.D1 > 0) || !(s.L > 0)) { alert('外形第 ' + s.no + ' 段請填外徑與長度'); return; }
            s.D2 = d2of(s);
            if (!(s.D2 > 0)) { alert('外形第 ' + s.no + ' 段的迄徑／錐度格式不對（例：20、1/10、3°）'); return; }
            s.R1 = s.D1 / 2; s.R2 = s.D2 / 2;
            if (s.gear) { gearIdx = i; s.cLraw = (cGtxt && cend !== 'right') ? cGtxt : ''; s.cRraw = (cGtxt && cend !== 'left') ? cGtxt : ''; s.labL = s.labR = document.getElementById('g-dim-ch-gear').checked; } else { s.labL = s.labR = document.getElementById('g-dim-ch-out').checked; }
            s.chL = parseCham(s.cLraw, s.R1, false); s.chR = parseCham(s.cRraw, s.R2, false);
            if ((s.chL && s.chL.bad) || (s.chR && s.chR.bad)) { alert('外形第 ' + s.no + ' 段的倒角格式不對（例：0.5、R1、1@30、Ø66@30）'); return; }
            s.xs = x; x += s.L; s.xe = x;
        }
        var total = x, maxR = 0; outer.forEach(function (s) { maxR = Math.max(maxR, s.R1, s.R2); });
        var boreTotal = 0;
        for (i = 0; i < bore.length; i++) {
            var b = bore[i];
            if (!(b.D1 > 0) || !(b.L > 0)) { alert('內孔第 ' + b.no + ' 段請填孔徑與長度'); return; }
            b.D2 = d2of(b);
            if (!(b.D2 > 0)) { alert('內孔第 ' + b.no + ' 段的迄徑／錐度格式不對（例：20、1/10、3°）'); return; }
            b.r1 = b.D1 / 2; b.r2 = b.D2 / 2; boreTotal += b.L;
            b.chL = parseCham(b.cLraw, b.r1, true); b.chR = parseCham(b.cRraw, b.r2, true);
            b.labL = b.labR = document.getElementById('g-dim-ch-bore').checked;
            if ((b.chL && b.chL.bad) || (b.chR && b.chR.bad)) { alert('內孔第 ' + b.no + ' 段的倒角格式不對（例：0.5、R1、1@30、Ø20@30）'); return; }
        }
        if (boreTotal > total + 1e-6) { alert('內孔總長（' + boreTotal + '）不能超過外形總長（' + total + '）'); return; }
        var bx0 = (document.getElementById('g-bore-align').value === 'right') ? total - boreTotal : 0, bx = bx0;
        bore.forEach(function (b) { b.xs = bx; bx += b.L; b.xe = bx; });
        var bEnd = bx0 + boreTotal;
        var key = keyParams(); if (key === false) return;
        if (document.getElementById('g-cut-dir').value === 'h-down') key = null;   // 由上往下看、保留下半：上方的內鍵槽在被切掉的那一半，不會出現
        if (key && !bore.length) { alert('有鍵槽時請先填內孔'); return; }
        if (key) {
            if (key.si >= 0 && bore[key.si]) { key.k0 = bore[key.si].xs + key.x; key.k1 = key.l ? key.k0 + key.l : bore[key.si].xe; }
            else { key.k0 = key.x; key.k1 = key.l ? key.x + key.l : bEnd; }
        }
        var hole = holeParams(); if (hole === false) return;
        var rad = radParams(); if (rad === false) return;
        var keyUp = null, radUp = null;   // 水平切割線朝上：看上半部——鍵槽與 0° 螺孔在視圖正中央（從孔內往上看到的槽與孔口），剖面本身左右對稱
        if (document.getElementById('g-cut-dir').value === 'h-up') { keyUp = key; key = null; radUp = rad; rad = null; }
        var okey = okeyParams(); if (okey === false) return;
        function radO(s, xx) { return s.R1 + (s.R2 - s.R1) * (xx - s.xs) / s.L; }
        function radB(b, xx) { return b.r1 + (b.r2 - b.r1) * (xx - b.xs) / b.L; }
        function boreRadAt(xx) { var rr = 0; bore.forEach(function (b) { if (xx >= b.xs - eps && xx <= b.xe + eps) rr = radB(b, xx); }); return rr; }
        if (rad) {   // 孔深：指定深度（不超過壁厚）／貫穿到內孔／全部貫穿（兩側的壁都鑽穿）
            var rsgW = null; outer.forEach(function (s) { if (rad.x >= s.xs - eps && rad.x <= s.xe + eps) rsgW = s; });
            rad.wall0 = rsgW ? radO(rsgW, rad.x) - boreRadAt(rad.x) : Infinity;
            rad.all = (rad.thru === 'all');
        }
        // 孔剛好開在內鍵槽上（0°、軸向範圍與寬度都蓋得住）：壁厚要再扣掉鍵槽深度，孔只鑽到鍵槽頂端，不延伸到內孔
        function radKeyAdj(angle) {
            return (rad && key && angNear(angle, 0) && key.k0 <= rad.x - rad.dm / 2 + eps && key.k1 >= rad.x + rad.dm / 2 - eps && key.w >= rad.dm - 1e-6) ? key.t : 0;
        }
        function radDepth(angle) {
            var wall = rad.wall0 - radKeyAdj(angle);
            var dd = (rad.thru !== 'depth' && isFinite(wall)) ? wall : rad.dd;
            var thr = (rad.thru !== 'depth') || dd >= wall - 1e-6;
            dd = Math.min(dd, wall);
            return { dd: dd, td: Math.min(rad.td, dd), through: thr };
        }
        var showObl = document.getElementById('g-show-obl').checked;
        function isCardinal(a) { var m = ((a % 90) + 90) % 90; return m < 0.01 || m > 89.99; }
        function radCuts(base) {   // 這個切面（base 角度）有沒有切到外徑螺孔
            return !!rad && (rad.angles.some(function (a) { return angNear(a, base); }) || (rad.all && rad.angles.some(function (a) { return angNear(a + 180, base); })));
        }
        // 螺孔位置檢查（要落在該端面的材料上，不能壓到孔或超出外徑）
        if (hole) {
            var fx = (hole.face === 'left') ? 0 : total;
            var oR = (hole.face === 'left') ? outer[0].R1 : outer[outer.length - 1].R2;
            var bR = 0;
            if (bore.length) {
                if (hole.face === 'left' && bx0 <= eps) bR = bore[0].r1 + (bore[0].chL ? bore[0].chL.rd : 0);
                if (hole.face === 'right' && bEnd >= total - eps) bR = bore[bore.length - 1].r2 + (bore[bore.length - 1].chR ? bore[bore.length - 1].chR.rd : 0);
            }
            if (hole.Rp - hole.dm / 2 <= bR || hole.Rp + hole.dm / 2 >= oR) { alert('螺孔分佈圓位置不在端面材料上（需在內孔與外徑之間）'); return; }
            if (hole.dd + hole.cone >= total) { alert('螺孔太深，超過零件長度'); return; }
        }
        function holePts(face, sign) {   // sign=+1 上半、-1 下半（鏡射用）；回傳沿「該端面由下往上」的點序
            var s = (face === 'left') ? 1 : -1, f = (face === 'left') ? 0 : total, Rp = hole.Rp;
            var pts = [[f, Rp - hole.dm / 2], [f + s * hole.dd, Rp - hole.dm / 2], [f + s * (hole.dd + hole.cone), Rp], [f + s * hole.dd, Rp + hole.dm / 2], [f, Rp + hole.dm / 2]];
            return pts;
        }
        // 內孔輪廓（右→左），倒角只畫在「孔口」與「孔階的材料角」
        function boreRev() {
            var bp = [], n = bore.length, j;
            function pb(px, py) { bp.push([px, py]); }
            for (j = n - 1; j >= 0; j--) {
                var b = bore[j];
                var lOK = (j === 0 && bx0 <= eps) || (j > 0 && bore[j - 1].r2 > b.r1 + eps);
                var rOK = (j === n - 1 && bEnd >= total - eps) || (j < n - 1 && b.r2 < bore[j + 1].r1 - eps);
                b.ecl = lOK ? clampCh(b.chL, b.L / 2, 1e9) : null; b.ecr = rOK ? clampCh(b.chR, b.L / 2, 1e9) : null;
                var q;
                if (b.ecr && b.ecr.fillet) { arcPts(b.xe - b.ecr.ax, b.r2 + b.ecr.rd, b.ecr.fillet, 0, -Math.PI / 2, 8).forEach(function (a) { pb(a[0], a[1]); }); }
                else if (b.ecr) { pb(b.xe, b.r2 + b.ecr.rd); pb(b.xe - b.ecr.ax, radB(b, b.xe - b.ecr.ax)); }
                else pb(b.xe, b.r2);
                if (b.ecl && b.ecl.fillet) { arcPts(b.xs + b.ecl.ax, b.r1 + b.ecl.rd, b.ecl.fillet, -Math.PI / 2, -Math.PI, 8).forEach(function (a) { pb(a[0], a[1]); }); }
                else if (b.ecl) { pb(b.xs + b.ecl.ax, radB(b, b.xs + b.ecl.ax)); pb(b.xs, b.r1 + b.ecl.rd); }
                else pb(b.xs, b.r1);
            }
            return bp;
        }
        // 鍵槽範圍內：頂線＝孔壁（不含倒角）＋槽深，倒角不跟著搬到槽頂，改畫在孔壁線（見下方 chamfer 線）
        function keyRoofY(xx, yy, t) {
            for (var jb = 0; jb < bore.length; jb++) { var bb = bore[jb]; if (xx >= bb.xs - eps && xx <= bb.xe + eps) return Math.min(yy, radB(bb, xx)) + t; }
            return yy + t;
        }
        function applyKey(pts, k0, k1, t) {
            var out = [], a, b, cuts, j, xa, xb, ins, ya, yb;
            function yAt(a, b, xx) { return Math.abs(b[0] - a[0]) < eps ? a[1] : a[1] + (b[1] - a[1]) * (xx - a[0]) / (b[0] - a[0]); }
            for (var q = 0; q < pts.length - 1; q++) {
                a = pts[q]; b = pts[q + 1];
                if (Math.abs(a[0] - b[0]) < eps) {   // 垂直段（孔階）：整段同進同出
                    var inside = a[0] > k0 + eps && a[0] < k1 - eps, off = inside ? t : 0;
                    out.push([a[0], a[1] + off]); out.push([b[0], b[1] + off]); continue;
                }
                cuts = [a[0], b[0]];
                [k0, k1].forEach(function (k) { if ((a[0] - k) * (b[0] - k) < 0) cuts.push(k); });
                cuts.sort(function (u, v) { return a[0] > b[0] ? v - u : u - v; });
                for (j = 0; j < cuts.length - 1; j++) {
                    xa = cuts[j]; xb = cuts[j + 1];
                    ins = ((xa + xb) / 2 > k0 + eps && (xa + xb) / 2 < k1 - eps);
                    ya = ins ? keyRoofY(xa, yAt(a, b, xa), t) : yAt(a, b, xa); yb = ins ? keyRoofY(xb, yAt(a, b, xb), t) : yAt(a, b, xb);
                    out.push([xa, ya]); out.push([xb, yb]);
                }
            }
            return out;
        }
        function halfPoly(withKey, side) {
            var pts = [], bp = null;
            outer.forEach(function (s, i) {
                var lEx = (i === 0) || outer[i - 1].R2 < s.R1 - eps, rEx = (i === outer.length - 1) || outer[i + 1].R1 < s.R2 - eps;
                var chl = lEx ? clampCh(s.chL, s.L / 2, s.R1) : null, chr = rEx ? clampCh(s.chR, s.L / 2, s.R2) : null;
                s.chLe = chl; s.chRe = chr;
                if (chl && chl.fillet) { arcPts(s.xs + chl.ax, s.R1 - chl.rd, chl.fillet, Math.PI, Math.PI / 2, 8).forEach(function (a) { pts.push(a); }); }
                else if (chl) { pts.push([s.xs, s.R1 - chl.rd]); pts.push([s.xs + chl.ax, radO(s, s.xs + chl.ax)]); }
                else pts.push([s.xs, s.R1]);
                if (chr && chr.fillet) { arcPts(s.xe - chr.ax, s.R2 - chr.rd, chr.fillet, Math.PI / 2, 0, 8).forEach(function (a) { pts.push(a); }); }
                else if (chr) { pts.push([s.xe - chr.ax, radO(s, s.xe - chr.ax)]); pts.push([s.xe, s.R2 - chr.rd]); }
                else pts.push([s.xe, s.R2]);
            });
            // 外徑徑向螺孔（剖面剛好切到 0°＝上半、180°＝下半時才挖缺口）
            if (rad) {
                var hit = radCuts(side === 'up' ? cutBaseAngle() : cutBaseAngle() + 180);
                if (hit) {
                    var x1 = rad.x - rad.dm / 2, x2 = rad.x + rad.dm / 2, sg0 = null;
                    outer.forEach(function (s) { if (rad.x >= s.xs - eps && rad.x <= s.xe + eps) sg0 = s; });
                    if (!sg0 || x1 < sg0.xs || x2 > sg0.xe) { alert('外徑螺孔位置要落在同一段外形的直徑面上（不可跨段或壓到倒角）'); throw 'stop'; }
                    var inside = pts.some(function (q) { return q[0] > x1 + eps && q[0] < x2 - eps; });
                    if (inside) { alert('外徑螺孔位置不能壓到倒角或階梯'); throw 'stop'; }
                    var idx = 0; while (idx < pts.length && pts[idx][0] <= x1 + eps) idx++;
                    var y1 = radO(sg0, x1), y2 = radO(sg0, x2), yc = radO(sg0, rad.x);
                    var dpt = radDepth(side === 'up' ? cutBaseAngle() : cutBaseAngle() + 180);
                    if (dpt.through) pts.splice(idx, 0, [x1, y1], [x1, y1 - dpt.dd], [x2, y2 - dpt.dd], [x2, y2]);
                    else pts.splice(idx, 0, [x1, y1], [x1, y1 - dpt.dd], [rad.x, yc - dpt.dd - rad.cone], [x2, y2 - dpt.dd], [x2, y2]);
                }
            }
            // 外鍵槽（正上方，剖面看得到的那一邊）；位置從所選外徑段的左端算起
            if (okey && side === 'up') {
                var ksg = outer[okey.si] || outer[0], k1 = ksg.xs + okey.x, k2 = okey.l ? k1 + okey.l : ksg.xe;
                if (k2 > ksg.xe + eps) { alert('外鍵槽超出所選外徑段（第 ' + ksg.no + ' 段）'); throw 'stop'; }
                if (pts.some(function (q) { return q[0] > k1 + eps && q[0] < k2 - eps; })) { alert('外鍵槽位置不能壓到倒角或階梯'); throw 'stop'; }
                var ki = 0; while (ki < pts.length && pts[ki][0] <= k1 + eps) ki++;
                pts.splice(ki, 0, [k1, radO(ksg, k1)], [k1, radO(ksg, k1) - okey.t], [k2, radO(ksg, k2) - okey.t], [k2, radO(ksg, k2)]);
                okey.k1 = k1; okey.k2 = k2; okey.seg = ksg;
            }
            // 右端面（由上往下）
            var holeHere = hole && hole.angles.some(function (a) { return angNear(a, side === 'up' ? cutBaseAngle() : cutBaseAngle() + 180); });
            if (holeHere && hole.face === 'right') holePts('right').reverse().forEach(function (q) { pts.push(q); });
            if (!bore.length) { pts.push([total, 0]); }
            else {
                if (bEnd < total - eps) { pts.push([total, 0]); pts.push([bEnd, 0]); }
                bp = boreRev();
                if (withKey && key) bp = applyKey(bp, key.k0, key.k1, key.t);
                bp.forEach(function (q) { pts.push(q); });
                if (bx0 > eps) { pts.push([bx0, 0]); pts.push([0, 0]); }
            }
            if (!bore.length) pts.push([0, 0]);
            // 左端面（由下往上）
            if (holeHere && hole.face === 'left') holePts('left').forEach(function (q) { pts.push(q); });
            var ded = [];
            pts.forEach(function (q) { var l = ded[ded.length - 1]; if (!l || Math.abs(l[0] - q[0]) > eps || Math.abs(l[1] - q[1]) > eps) ded.push(q); });
            return ded;
        }
        var up, lo;
        try { up = halfPoly(true, 'up'); lo = halfPoly(false, 'lo').map(function (q) { return [q[0], -q[1]]; }); }
        catch (err) { if (err === 'stop') return; throw err; }
        var sc = 520 / Math.max(total, 2 * maxR);
        var items = [];
        function toPx(a) { return a.map(function (q) { return { x: (q[0] - total / 2) * sc, y: -q[1] * sc }; }); }
        function toPxLine(a, dash) { items.push({ pts: toPx(a), closed: false, w: dash ? 1 : 2, dash: dash || null }); }
        items.push({ pts: toPx(up), closed: true, w: 2 });
        items.push({ pts: toPx(lo), closed: true, w: 2 });
        // 剖面線：45°，每個半邊各自掃描（斜線與多邊形邊求交，兩兩配對）
        if (document.getElementById('g-draw-hatch').checked) {
            var hgap = Math.max(3, Math.min(60, parseFloat(document.getElementById('g-draw-hatch-gap').value) || 28));
            var step = hgap / sc * Math.SQRT2;
            [up, lo].forEach(function (poly) {
                var cmin = Infinity, cmax = -Infinity;
                poly.forEach(function (q) { var u = q[1] - q[0]; if (u < cmin) cmin = u; if (u > cmax) cmax = u; });
                for (var cv = cmin + step / 2 + 0.00137; cv < cmax; cv += step) {
                    var xs = [];
                    for (var e = 0; e < poly.length; e++) {
                        var a = poly[e], b2 = poly[(e + 1) % poly.length];
                        var ua = a[1] - a[0], ub = b2[1] - b2[0];
                        if ((ua - cv) * (ub - cv) < 0) { var t = (cv - ua) / (ub - ua); xs.push([a[0] + t * (b2[0] - a[0]), a[1] + t * (b2[1] - a[1])]); }
                    }
                    xs.sort(function (m, n) { return m[0] - n[0]; });
                    for (var k = 0; k + 1 < xs.length; k += 2) items.push({ pts: toPx([xs[k], xs[k + 1]]), closed: false, w: 0.8 });
                }
            });
        }
        // 孔口／孔階連接線：內孔遠側壁的可見邊，上下兩半之間的垂直線
        if (bore.length && document.getElementById('g-draw-edge').checked) {
            var edges = {};
            function addEdge(xx, span) { var k = Math.round(xx * 1e6); if (!(span > 0)) return; if (!edges[k] || edges[k][1] < span) edges[k] = [xx, span]; }
            for (i = 0; i <= bore.length; i++) {
                var left = bore[i - 1], right = bore[i];
                var xx = left ? left.xe : right.xs;
                var spanL = left ? left.r2 + (left.ecr ? left.ecr.rd : 0) : 0, spanR = right ? right.r1 + (right.ecl ? right.ecl.rd : 0) : 0;
                if (i === 0 || i === bore.length || Math.abs((left ? left.r2 : 0) - (right ? right.r1 : 0)) > eps) addEdge(xx, Math.max(spanL, spanR));
            }
            bore.forEach(function (b) {
                if (b.ecl) addEdge(b.xs + b.ecl.ax, radB(b, b.xs + b.ecl.ax));
                if (b.ecr) addEdge(b.xe - b.ecr.ax, radB(b, b.xe - b.ecr.ax));
            });
            Object.keys(edges).forEach(function (k) {
                var e = edges[k], eTop = e[1];   // 鍵槽範圍內，上半部的孔口連線只畫到鍵槽邊緣那條線，不要凸進槽裡
                if (key && key.w / 2 < e[1] && e[0] >= key.k0 - eps && e[0] <= key.k1 + eps) eTop = Math.sqrt(e[1] * e[1] - key.w * key.w / 4);
                items.push({ pts: toPx([[e[0], eTop], [e[0], -e[1]]]), closed: false, w: 2 });
            });
        }
        // 中心線（一點鏈線）與齒部節圓線
        var ext = Math.max(total, 2 * maxR) * 0.06;
        items.push({ pts: toPx([[-ext, 0], [total + ext, 0]]), closed: false, w: 1, dash: 'dashdot' });
        if (gearIdx >= 0 && p && !p.internal) {
            var rp = (p.mn / Math.cos(p.beta || 0)) * p.z / 2, gs = outer[gearIdx];
            [rp, -rp].forEach(function (yy) { items.push({ pts: toPx([[gs.xs - ext / 2, yy], [gs.xe + ext / 2, yy]]), closed: false, w: 1, dash: 'dashdot' }); });
        }
        // 螺孔：螺紋外徑細線、螺紋終止線、中心線（上下兩半各一個，孔在最上與最下位置）
        if (hole) {
            var sg = (hole.face === 'left') ? 1 : -1, f0 = (hole.face === 'left') ? 0 : total;
            [1, -1].forEach(function (sy) {
                if (!hole.angles.some(function (a) { return angNear(a, sy > 0 ? cutBaseAngle() : cutBaseAngle() + 180); })) return;
                var Rp = hole.Rp * sy, hd = hole.D / 2, hm = hole.dm / 2, xt = f0 + sg * hole.td;
                items.push({ pts: toPx([[f0, Rp + hd], [xt, Rp + hd]]), closed: false, w: 2 });
                items.push({ pts: toPx([[f0, Rp - hd], [xt, Rp - hd]]), closed: false, w: 2 });
                items.push({ pts: toPx([[xt, Rp + hm], [xt, Rp + hd]]), closed: false, w: 2 });
                items.push({ pts: toPx([[xt, Rp - hm], [xt, Rp - hd]]), closed: false, w: 2 });
                items.push({ pts: toPx([[f0 - sg * ext / 2, Rp], [f0 + sg * (hole.dd + hole.cone + ext / 2), Rp]]), closed: false, w: 1, dash: 'dashdot' });
            });
            hole.angles.forEach(function (a) {   // 沒落在切面上的孔：畫成被擋住的虛線
                if (angNear(a, cutBaseAngle()) || angNear(a, cutBaseAngle() + 180)) return;
                if ((!showObl && !isCardinal(a)) || cutIsH()) return;   // 水平切割線（由上往下看）：沒被切到的孔不畫虛線
                var yc2 = hole.Rp * Math.cos((a - cutBaseAngle()) * Math.PI / 180), hm2 = hole.dm / 2, xe2 = f0 + sg * hole.dd;
                items.push({ pts: toPx([[f0, yc2 + hm2], [xe2, yc2 + hm2]]), closed: false, w: 1, dash: 'dashed' });
                items.push({ pts: toPx([[f0, yc2 - hm2], [xe2, yc2 - hm2]]), closed: false, w: 1, dash: 'dashed' });
                items.push({ pts: toPx([[xe2, yc2 - hm2], [xe2, yc2 + hm2]]), closed: false, w: 1, dash: 'dashed' });
                items.push({ pts: toPx([[f0 - sg * ext / 2, yc2], [xe2, yc2]]), closed: false, w: 1, dash: 'dashdot' });
            });
        }
        if (rad) {
            var rsg = null; outer.forEach(function (s) { if (rad.x >= s.xs - eps && rad.x <= s.xe + eps) rsg = s; });
            [['up', 1, cutBaseAngle()], ['lo', -1, cutBaseAngle() + 180]].forEach(function (q) {
                if (!radCuts(q[2]) || !rsg) return;
                var sy = q[1], Ro = radO(rsg, rad.x), hd = rad.D / 2, hm = rad.dm / 2, dq = radDepth(q[2]), yt = Ro - dq.td;
                [-1, 1].forEach(function (sx) {
                    items.push({ pts: toPx([[rad.x + sx * hd, Ro * sy], [rad.x + sx * hd, yt * sy]]), closed: false, w: 2 });
                    items.push({ pts: toPx([[rad.x + sx * hm, yt * sy], [rad.x + sx * hd, yt * sy]]), closed: false, w: 2 });
                });
                items.push({ pts: toPx([[rad.x, (Ro + ext / 2) * sy], [rad.x, (Ro - dq.dd - (dq.through ? 0 : rad.cone) - ext / 2) * sy]]), closed: false, w: 1, dash: 'dashdot' });
                var yo = Math.sqrt(Math.max(0, Ro * Ro - hm * hm));   // 孔口遠側邊緣：在外圓柱面稍下方的一條線
                items.push({ pts: toPx([[rad.x - hm, yo * sy], [rad.x + hm, yo * sy]]), closed: false, w: 2 });
            });
            // 沒有落在切面上的孔：剖面圖上畫成被擋住的虛線（依角度投影到上下方向）
            rad.angles.forEach(function (a) {
                if (!rsg || angNear(a, cutBaseAngle()) || angNear(a, cutBaseAngle() + 180)) return;
                if ((!showObl && !isCardinal(a)) || cutIsH()) return;   // 水平切割線（由上往下看）：沒被切到的孔不畫虛線
                var dq2 = radDepth(a), cs = Math.cos((a - cutBaseAngle()) * Math.PI / 180), Ro2 = radO(rsg, rad.x), yA = Ro2 * cs, yB = (Ro2 - dq2.dd) * cs, hx1 = rad.x - rad.dm / 2, hx2 = rad.x + rad.dm / 2;
                items.push({ pts: toPx([[hx1, yA], [hx1, yB]]), closed: false, w: 1, dash: 'dashed' });
                items.push({ pts: toPx([[hx2, yA], [hx2, yB]]), closed: false, w: 1, dash: 'dashed' });
                items.push({ pts: toPx([[hx1, yB], [hx2, yB]]), closed: false, w: 1, dash: 'dashed' });
                items.push({ pts: toPx([[rad.x, yA + (yA >= 0 ? ext / 2 : -ext / 2)], [rad.x, yB]]), closed: false, w: 1, dash: 'dashdot' });
                var ell = [];   // 孔口與外圓柱面相交的曲線（投影後是略帶弧度的橢圓）
                for (var q2 = 0; q2 < 36; q2++) {
                    var ph = q2 / 36 * 2 * Math.PI, py = (rad.dm / 2) * Math.sin(ph), dxx = (rad.dm / 2) * Math.cos(ph);
                    var dl = Math.asin(Math.max(-1, Math.min(1, py / Ro2)));
                    ell.push([rad.x + dxx, Ro2 * Math.cos((a - cutBaseAngle()) * Math.PI / 180 + dl)]);
                }
                items.push({ pts: toPx(ell), closed: true, w: 1, dash: 'dashed' });
            });
        }
        if (key && bore.length) {   // 內鍵槽：剖面看得到槽的邊線（槽寬邊緣落在孔壁稍下方）
            var kbs = (key.si >= 0 && bore[key.si]) ? bore[key.si] : bore[0], rK0 = radB(kbs, (key.k0 + key.k1) / 2);
            if (key.w / 2 < rK0) { var yE = Math.sqrt(rK0 * rK0 - key.w * key.w / 4); var xa = key.k0, xb = key.k1;   // 孔壁線只畫在倒角以內：倒角範圍內那一段由斜的倒角線取代，不能再有水平線凸出去
                bore.forEach(function (bq) {
                    if (bq.ecl && !bq.ecl.fillet && Math.abs(bq.xs - key.k0) < eps) xa = Math.max(xa, bq.xs + bq.ecl.ax);
                    if (bq.ecr && !bq.ecr.fillet && Math.abs(bq.xe - key.k1) < eps) xb = Math.min(xb, bq.xe - bq.ecr.ax);
                });
                if (xb > xa) items.push({ pts: toPx([[xa, yE], [xb, yE]]), closed: false, w: 2 }); }
            // 鍵槽邊緣（孔壁上、離中心線 w/2 的那條遠側線）的高度：yK(半徑)＝√(半徑²−(w/2)²)。倒角也要跟著這個高度走
            var yK = function (rr) { return Math.sqrt(Math.max(0, rr * rr - key.w * key.w / 4)); };
            var kRoof = function (xx) { return radB(kbs, xx) + key.t; };
            var kEnd = { 0: yK(rK0), 1: yK(rK0) };   // 鍵槽兩端：孔壁線在端面處的高度（有倒角時是倒角的最上端）
            bore.forEach(function (bb) {   // 內孔倒角（落在鍵槽範圍內的）畫在孔壁線上，位置依鍵槽邊緣高度換算
                if (bb.ecl && !bb.ecl.fillet && bb.xs >= key.k0 - eps && bb.xs + bb.ecl.ax <= key.k1 + eps) {
                    items.push({ pts: toPx([[bb.xs, yK(bb.r1 + bb.ecl.rd)], [bb.xs + bb.ecl.ax, yK(radB(bb, bb.xs + bb.ecl.ax))]]), closed: false, w: 2 });
                    if (Math.abs(bb.xs - key.k0) < eps) kEnd[0] = yK(bb.r1 + bb.ecl.rd);
                }
                if (bb.ecr && !bb.ecr.fillet && bb.xe <= key.k1 + eps && bb.xe - bb.ecr.ax >= key.k0 - eps) {
                    items.push({ pts: toPx([[bb.xe, yK(bb.r2 + bb.ecr.rd)], [bb.xe - bb.ecr.ax, yK(radB(bb, bb.xe - bb.ecr.ax))]]), closed: false, w: 2 });
                    if (Math.abs(bb.xe - key.k1) < eps) kEnd[1] = yK(bb.r2 + bb.ecr.rd);
                }
            });
            // 鍵槽兩端的封閉線：從槽頂垂直連到孔壁線（貫穿到端面時，端面處也要有這條線，槽才是封閉的方框）
            [[key.k0, kEnd[0]], [key.k1, kEnd[1]]].forEach(function (e) {
                if (kRoof(e[0]) > e[1] + eps) items.push({ pts: toPx([[e[0], kRoof(e[0])], [e[0], e[1]]]), closed: false, w: 2 });
            });
        }
        if (okey && okey.seg) {   // 外鍵槽：槽寬邊緣在外圓柱面上，被切面擋住＝虛線
            var Ro3 = radO(okey.seg, okey.k1);
            if (okey.w / 2 < Ro3) { var yO = Math.sqrt(Ro3 * Ro3 - okey.w * okey.w / 4); items.push({ pts: toPx([[okey.k1, yO], [okey.k2, yO]]), closed: false, w: 1, dash: 'dashed' }); }
        }
        if (gearIdx >= 0 && p && p.internal) {   // 內齒：齒根圓（大徑）細虛線
            var rfi = ((p.mn / Math.cos(p.beta || 0)) * p.z + 2 * p.mn * (1.25 - (p.x || 0))) / 2, gsi = outer[gearIdx];
            [rfi, -rfi].forEach(function (yy) { items.push({ pts: toPx([[gsi.xs, yy], [gsi.xe, yy]]), closed: false, w: 1, dash: 'dashed' }); });
        }
        var dims = [];
        if (document.getElementById('g-dim-on').checked) {
            var tOD = dimTol('g-tol-od'), tID = dimTol('g-tol-id'), tLN = dimTol('g-tol-len');
            var PX = function (xm) { return (xm - total / 2) * sc; }, PY = function (ym) { return -ym * sc; };
            var yb = maxR * sc, seen = {};
            var withTol = function (txt, tol) { return txt + (tol ? ' ' + tol : ''); };
            // 長度：每一段外形一條，全長一條（只有一段時只標全長）
            var lvl1 = yb + 32, lvl2 = yb + 68;
            if (outer.length > 1) outer.forEach(function (sg) { dims.push({ kind: 'h', x1: PX(sg.xs), x2: PX(sg.xe), y: lvl1, e1: sg.R1 * sc, e2: sg.R2 * sc, text: withTol(fmtDim(sg.L), tLN) }); });
            dims.push({ kind: 'h', x1: PX(0), x2: PX(total), y: (outer.length > 1) ? lvl2 : lvl1, e1: outer[0].R1 * sc, e2: outer[outer.length - 1].R2 * sc, text: withTol(fmtDim(total), tLN) });
            if (bore.length && (bore.length > 1 || Math.abs(boreTotal - total) > eps)) {   // 內孔深度（每一段，再加總深）
                var lvlB = ((outer.length > 1) ? lvl2 : lvl1) + 36;
                bore.forEach(function (bg) { dims.push({ kind: 'h', x1: PX(bg.xs), x2: PX(bg.xe), y: lvlB, e1: bg.r1 * sc, e2: bg.r2 * sc, text: withTol(fmtDim(bg.L), tLN) }); });
                if (bore.length > 1) dims.push({ kind: 'h', x1: PX(bore[0].xs), x2: PX(bore[bore.length - 1].xe), y: lvlB + 36, e1: bore[0].r1 * sc, e2: bore[bore.length - 1].r2 * sc, text: withTol(fmtDim(boreTotal), tLN) });
            }
            // 直徑：在左半邊的標在左側（尺寸界線從該段左端面引出），在右半邊的標在右側（從右端面引出）；兩側各自由近到遠排開
            var nL = 0, nR = 0;
            outer.forEach(function (sg) {
                var dd = 2 * Math.max(sg.R1, sg.R2), key = fmtDim(dd);
                if (seen['o' + key]) return; seen['o' + key] = 1;
                var onLeft = outer.length > 1 && ((sg.xs + sg.xe) / 2 < total / 2);
                var xx = onLeft ? PX(0) - 34 - (nL++) * 34 : PX(total) + 34 + (nR++) * 34, ee = onLeft ? PX(sg.xs) : PX(sg.xe);
                dims.push({ kind: 'v', x: xx, y1: -dd / 2 * sc, y2: dd / 2 * sc, e1: ee, e2: ee, text: withTol('Ø' + key, tOD) });
            });
            var kyp0 = (typeof key !== 'undefined') ? key : null;
            bore.forEach(function (bg) {   // 孔徑：標在孔內，箭頭直接頂到孔壁（不用尺寸界線）
                var dd = 2 * Math.max(bg.r1, bg.r2), key = fmtDim(dd);
                if (seen['b' + key]) return; seen['b' + key] = 1;
                var xx = PX((bg.xs + bg.xe) / 2);
                var yTopB = dd / 2;   // 這段孔上方有鍵槽時，箭頭頂到鍵槽邊緣那條線（實際量起來略小於標稱孔徑）
                if (kyp0 && kyp0.w / 2 < yTopB && (kyp0.si < 0 || bore[kyp0.si] === bg) && (bg.xs + bg.xe) / 2 >= kyp0.k0 - eps && (bg.xs + bg.xe) / 2 <= kyp0.k1 + eps) yTopB = Math.sqrt(yTopB * yTopB - kyp0.w * kyp0.w / 4);
                dims.push({ kind: 'v', x: xx, y1: -yTopB * sc, y2: dd / 2 * sc, e1: xx, e2: xx, text: withTol('Ø' + key, tID) });
            });
            if (okey && okey.seg) {
                var yTop = -radO(okey.seg, okey.k1) * sc;
                dims.push({ kind: 'h', x1: PX(okey.k1), x2: PX(okey.k2), y: -yb - 24, e1: yTop, e2: yTop, text: fmtDim(okey.k2 - okey.k1) });
                dims.push({ kind: 't', x: PX((okey.k1 + okey.k2) / 2), y: -yb - 56, text: '外鍵槽 寬' + fmtDim(okey.w) + ' 深' + fmtDim(okey.t) });
            }
            // 倒角
            outer.forEach(function (sg) {   // 倒角：從倒角中點拉出引線再寫文字（只標真的有削到的角）
                if (sg.chLe && sg.labL !== false) { var lx = PX(sg.xs + sg.chLe.ax / 2), ly = -(sg.R1 - sg.chLe.rd / 2) * sc; dims.push({ kind: 't', x: lx - 28, y: ly - 30, lx: lx, ly: ly, text: sg.chLe.label }); }
                if (sg.chRe && sg.labR !== false) { var rx = PX(sg.xe - sg.chRe.ax / 2), ry = -(sg.R2 - sg.chRe.rd / 2) * sc; dims.push({ kind: 't', x: rx + 28, y: ry - 30, lx: rx, ly: ry, text: sg.chRe.label }); }
            });
            bore.forEach(function (bg) {
                if (bg.ecl && bg.labL !== false) { var lx2 = PX(bg.xs + bg.ecl.ax / 2), ly2 = -(bg.r1 + bg.ecl.rd / 2) * sc; dims.push({ kind: 't', x: lx2 + 36, y: -(bg.r1 * sc) + 26, lx: lx2, ly: ly2, text: bg.ecl.label }); }
                if (bg.ecr && bg.labR !== false) { var rx2 = PX(bg.xe - bg.ecr.ax / 2), ry2 = -(bg.r2 + bg.ecr.rd / 2) * sc; dims.push({ kind: 't', x: rx2 - 36, y: -(bg.r2 * sc) + 26, lx: rx2, ly: ry2, text: bg.ecr.label }); }
            });
            if (hole) {
                var hsg = (hole.face === 'left') ? 1 : -1, hfx = (hole.face === 'left') ? 0 : total;
                dims.push({ kind: 't', x: PX(hfx) + hsg * 50, y: -yb - 44, text: hole.n + '-' + hole.label + ' ↧' + fmtDim(hole.td) });
            }
            if (rad) {
                var rsg2 = null; outer.forEach(function (sg) { if (rad.x >= sg.xs - eps && rad.x <= sg.xe + eps) rsg2 = sg; });
                if (rsg2) dims.push({ kind: 't', x: PX(rad.x), y: -yb - 66, text: rad.n + '-' + rad.label + ' ↧' + fmtDim(rad.td) });
            }
        }
        if (keyUp && bore.length) {   // 孔內鍵槽：兩條槽邊線沿軸向＋兩端封閉線，在視圖正中央
            var kw = keyUp.w / 2;
            toPxLine([[keyUp.k0, kw], [keyUp.k1, kw]]); toPxLine([[keyUp.k0, -kw], [keyUp.k1, -kw]]);
            toPxLine([[keyUp.k0, kw], [keyUp.k0, -kw]]); toPxLine([[keyUp.k1, kw], [keyUp.k1, -kw]]);
        }
        if (radUp) radUp.angles.forEach(function (a) {   // 0° 方向的外徑螺孔：孔口圓在視圖正中央
            if (!angNear(a, 0)) return;
            var c = [], r = radUp.dm / 2, ph; for (ph = 0; ph <= 48; ph++) c.push([radUp.x + r * Math.cos(2 * Math.PI * ph / 48), r * Math.sin(2 * Math.PI * ph / 48)]);
            toPxLine(c);
            var cm = [], r2 = radUp.D / 2; for (ph = 0; ph <= 36; ph++) cm.push([radUp.x + r2 * Math.cos(1.5 * Math.PI * ph / 36), r2 * Math.sin(1.5 * Math.PI * ph / 36)]);
            toPxLine(cm);
            toPxLine([[radUp.x - r2 * 1.3, 0], [radUp.x + r2 * 1.3, 0]], 'dashdot'); toPxLine([[radUp.x, -r2 * 1.3], [radUp.x, r2 * 1.3]], 'dashdot');
        });
        var cdir0 = document.getElementById('g-cut-dir').value;
        if (cutIsH()) {   // 水平切割線：剖面圖放在端面圖上／下方，軸線要轉成垂直（投影對齊：寬度與端面圖同向），座標 x↔y 互換
            var fy = (cdir0 === 'h-up') ? -1 : 1;   // 朝上：整個視圖上下鏡射（左端在下）
            items.forEach(function (it) { if (it.pts) it.pts = it.pts.map(function (q) { return { x: q.y, y: fy * q.x }; }); if (it.text) { var tx = it.x; it.x = it.y; it.y = fy * tx; } });
            dims = dims.map(function (q) {
                var r = JSON.parse(JSON.stringify(q));
                if (q.kind === 'h') return { kind: 'v', x: q.y, y1: fy * q.x1, y2: fy * q.x2, e1: q.e1, e2: q.e2, text: q.text };
                if (q.kind === 'v') return { kind: 'h', y: fy * q.x, x1: q.y1, x2: q.y2, e1: fy * q.e1, e2: fy * q.e2, text: q.text };
                if (q.kind === 't') { r.x = q.y; r.y = fy * q.x; if (q.lx != null) { r.lx = q.ly; r.ly = fy * q.lx; } return r; }
                return r;
            });
        }
        if (cdir0 === 'v-left') {   // 箭頭朝左：視圖左右鏡射（箭頭朝右維持原樣）
            items.forEach(function (it) { if (it.pts) it.pts = it.pts.map(function (q) { return { x: -q.x, y: q.y }; }); if (it.text) it.x = -it.x; });
            dims = dims.map(function (q) {
                var r = JSON.parse(JSON.stringify(q));
                if (q.kind === 'h') { r.x1 = -q.x2; r.x2 = -q.x1; }
                else if (q.kind === 'v') { r.x = -q.x; r.e1 = -q.e1; r.e2 = -q.e2; }
                else if (q.kind === 't') { r.x = -q.x; if (q.lx != null) r.lx = -q.lx; }
                return r;
            });
        }
        window.egGearDrawItems(items, '齒輪剖面圖（外形 ' + outer.length + ' 段' + (bore.length ? '、內孔 ' + bore.length + ' 段' : '') + (key ? '、鍵槽' : '') + (hole ? '、' + hole.n + '-' + hole.label + '螺孔' : '') + '，全長 ' + total + ' mm）', dims);
    };
    /* ══════════════════════════════════════════════════════════════════════
       3D 預覽：用同一份外形／內孔／倒角／鍵槽／螺孔資料，以 o3dv（站上既有的 3D 檢視器）即時產生模型。
       做法：剖面輪廓（x,r）繞軸迴轉；齒部再依漸開線齒形（含螺旋角扭轉）調整半徑；
       鍵槽直接挖在孔壁／外圓柱面的半徑函數上；螺孔先用深色圓盤標示位置（不做布林運算）。
       o3dv 的 Mesh.AddVertex 只收 Coord3D 物件、三角形繞法決定法向量方向（見砂輪干涉 3D 元件的註解）。 */
    var G3_LIB = '<?= $__gt3_url ?>/o3dv.min.js';
    var g3State = 0, g3Queue = [];
    function g3Libs(cb) {
        if (g3State === 2) { cb(true); return; }
        if (g3State === 3) { cb(false); return; }
        g3Queue.push(cb);
        if (g3State === 1) return;
        g3State = 1;
        function done() { var q = g3Queue; g3Queue = []; q.forEach(function (f) { f(g3State === 2); }); }
        if (typeof OV !== 'undefined' && OV.EmbeddedViewer) { g3State = 2; done(); return; }
        var sc = document.createElement('script');
        sc.src = G3_LIB;
        sc.onload = function () { g3State = (typeof OV !== 'undefined' && OV.EmbeddedViewer) ? 2 : 3; done(); };
        sc.onerror = function () { g3State = 3; done(); };
        document.head.appendChild(sc);
    }
    function g3Phong(r, g, b, flat) {   // flat＝線稿用：不受光照影響的均勻底色（只剩輪廓線，像一般工程圖）
        var m = new OV.PhongMaterial();
        m.color = new OV.RGBColor(r, g, b);
        m.ambient = flat ? new OV.RGBColor(r, g, b) : new OV.RGBColor(Math.round(r * 0.45), Math.round(g * 0.45), Math.round(b * 0.45));
        m.specular = flat ? new OV.RGBColor(0, 0, 0) : new OV.RGBColor(40, 40, 40);
        m.shininess = 0.25;
        return m;
    }

    /* 收集資料（與剖面圖同一套解析與驗證） */
    function g3Data() {
        var p = _baseParams;
        if (!p) { alert('請先按「計算齒輪」'); return null; }
        if (p.internal) { alert('3D 預覽目前只支援外齒輪'); return null; }
        if (!window.gearEvalAll()) { alert('有數字欄的算式無法計算（紅框處），請修改'); return null; }
        var eps = 1e-9, i;
        var outer = readSegs('g-seg-list', true), bore = readSegs('g-bore-list', false);
        if (!outer.length) { alert('外形請至少填一段'); return null; }
        var cGtxt = String(document.getElementById('g-draw-c').value || '').trim(), cend = document.getElementById('g-draw-cend').value;
        var x = 0, gs = null;
        for (i = 0; i < outer.length; i++) {
            var s = outer[i];
            if (s.gear && !(s.D1 > 0)) s.D1 = p.da;
            if (!(s.D1 > 0) || !(s.L > 0)) { alert('外形第 ' + s.no + ' 段請填外徑與長度'); return null; }
            s.D2 = d2of(s);
            if (!(s.D2 > 0)) { alert('外形第 ' + s.no + ' 段的迄徑／錐度格式不對'); return null; }
            s.R1 = s.D1 / 2; s.R2 = s.D2 / 2;
            if (s.gear) { gs = s; s.cLraw = (cGtxt && cend !== 'right') ? cGtxt : ''; s.cRraw = (cGtxt && cend !== 'left') ? cGtxt : ''; }
            s.chL = parseCham(s.cLraw, s.R1, false); s.chR = parseCham(s.cRraw, s.R2, false);
            if ((s.chL && s.chL.bad) || (s.chR && s.chR.bad)) { alert('外形第 ' + s.no + ' 段的倒角格式不對'); return null; }
            s.xs = x; x += s.L; s.xe = x;
        }
        var total = x, boreTotal = 0;
        for (i = 0; i < bore.length; i++) {
            var b = bore[i];
            if (!(b.D1 > 0) || !(b.L > 0)) { alert('內孔第 ' + b.no + ' 段請填孔徑與長度'); return null; }
            b.D2 = d2of(b);
            if (!(b.D2 > 0)) { alert('內孔第 ' + b.no + ' 段的迄徑／錐度格式不對'); return null; }
            b.r1 = b.D1 / 2; b.r2 = b.D2 / 2; boreTotal += b.L;
            b.chL = parseCham(b.cLraw, b.r1, true); b.chR = parseCham(b.cRraw, b.r2, true);
            if ((b.chL && b.chL.bad) || (b.chR && b.chR.bad)) { alert('內孔第 ' + b.no + ' 段的倒角格式不對'); return null; }
        }
        if (boreTotal > total + 1e-6) { alert('內孔總長（' + boreTotal + '）不能超過外形總長（' + total + '）'); return null; }
        var bx0 = (document.getElementById('g-bore-align').value === 'right') ? total - boreTotal : 0, bx = bx0;
        bore.forEach(function (bb) { bb.xs = bx; bx += bb.L; bb.xe = bx; });
        var key = keyParams(); if (key === false) return null;
        if (key && !bore.length) { alert('有鍵槽時請先填內孔'); return null; }
        if (key) {
            if (key.si >= 0 && bore[key.si]) { key.k0 = bore[key.si].xs + key.x; key.k1 = key.l ? key.k0 + key.l : bore[key.si].xe; }
            else { key.k0 = key.x; key.k1 = key.l ? key.x + key.l : bx0 + boreTotal; }
        }
        var okey = okeyParams(); if (okey === false) return null;
        if (okey) { var os = outer[okey.si] || outer[0]; okey.k1 = os.xs + okey.x; okey.k2 = okey.l ? okey.k1 + okey.l : os.xe; }
        var hole = holeParams(); if (hole === false) return null;
        var rad = radParams(); if (rad === false) return null;
        return { p: p, outer: outer, bore: bore, total: total, bx0: bx0, bEnd: bx0 + boreTotal, gear: gs, key: key, okey: okey, hole: hole, rad: rad, viewRight: (document.getElementById('g-view-side').value === 'right') };
    }

    /* 剖面輪廓（x,r）：外輪廓左→右、右端面、內孔右→左、左端面；每個點帶標籤 o 外 / b 孔 / f 端面軸線 */
    function g3Profile(d) {
        var outer = d.outer, bore = d.bore, total = d.total, bx0 = d.bx0, bEnd = d.bEnd, eps = 1e-9, pts = [];
        function pu(px, py, tag) { var l = pts[pts.length - 1]; if (!l || Math.abs(l[0] - px) > eps || Math.abs(l[1] - py) > eps) pts.push([px, py, tag]); }
        function radO(sg, xx) { return sg.R1 + (sg.R2 - sg.R1) * (xx - sg.xs) / sg.L; }
        function radB(bg, xx) { return bg.r1 + (bg.r2 - bg.r1) * (xx - bg.xs) / bg.L; }
        outer.forEach(function (sg, i) {
            var lEx = (i === 0) || outer[i - 1].R2 < sg.R1 - eps, rEx = (i === outer.length - 1) || outer[i + 1].R1 < sg.R2 - eps;
            var chl = lEx ? clampCh(sg.chL, sg.L / 2, sg.R1) : null, chr = rEx ? clampCh(sg.chR, sg.L / 2, sg.R2) : null;
            if (chl && chl.fillet) arcPts(sg.xs + chl.ax, sg.R1 - chl.rd, chl.fillet, Math.PI, Math.PI / 2, 8).forEach(function (a) { pu(a[0], a[1], 'o'); });
            else if (chl) { pu(sg.xs, sg.R1 - chl.rd, 'o'); pu(sg.xs + chl.ax, radO(sg, sg.xs + chl.ax), 'o'); }
            else pu(sg.xs, sg.R1, 'o');
            if (chr && chr.fillet) arcPts(sg.xe - chr.ax, sg.R2 - chr.rd, chr.fillet, Math.PI / 2, 0, 8).forEach(function (a) { pu(a[0], a[1], 'o'); });
            else if (chr) { pu(sg.xe - chr.ax, radO(sg, sg.xe - chr.ax), 'o'); pu(sg.xe, sg.R2 - chr.rd, 'o'); }
            else pu(sg.xe, sg.R2, 'o');
        });
        if (!bore.length) { pu(total, 0, 'f'); pu(0, 0, 'f'); return pts; }
        if (bEnd < total - eps) { pu(total, 0, 'f'); pu(bEnd, 0, 'f'); }
        var n = bore.length, j;
        for (j = n - 1; j >= 0; j--) {
            var bg = bore[j];
            var lOK = (j === 0 && bx0 <= eps) || (j > 0 && bore[j - 1].r2 > bg.r1 + eps);
            var rOK = (j === n - 1 && bEnd >= total - eps) || (j < n - 1 && bg.r2 < bore[j + 1].r1 - eps);
            var el = lOK ? clampCh(bg.chL, bg.L / 2, 1e9) : null, er = rOK ? clampCh(bg.chR, bg.L / 2, 1e9) : null;
            if (er && er.fillet) arcPts(bg.xe - er.ax, bg.r2 + er.rd, er.fillet, 0, -Math.PI / 2, 8).forEach(function (a) { pu(a[0], a[1], 'b'); });
            else if (er) { pu(bg.xe, bg.r2 + er.rd, 'b'); pu(bg.xe - er.ax, radB(bg, bg.xe - er.ax), 'b'); }
            else pu(bg.xe, bg.r2, 'b');
            if (el && el.fillet) arcPts(bg.xs + el.ax, bg.r1 + el.rd, el.fillet, -Math.PI / 2, -Math.PI, 8).forEach(function (a) { pu(a[0], a[1], 'b'); });
            else if (el) { pu(bg.xs + el.ax, radB(bg, bg.xs + el.ax), 'b'); pu(bg.xs, bg.r1 + el.rd, 'b'); }
            else pu(bg.xs, bg.r1, 'b');
        }
        if (bx0 > eps) { pu(bx0, 0, 'f'); pu(0, 0, 'f'); }
        return pts;
    }

    /* 齒形半徑函數：相對齒中心的夾角 u（0..齒距/2）→ 半徑（漸開線，含齒根圓）*/
    function g3ToothLut(p) {
        var z = p.z, mn = p.mn, x = p.x || 0, an = p.alpha_n, at = p.alpha_t, mt = mn / Math.cos(p.beta || 0);
        var r = mt * z / 2, rb = r * Math.cos(at), ra = p.da / 2;
        var rf = ((p.df != null) ? p.df : (mt * z - 2 * mn * (1.25 - x))) / 2;
        var r0 = Math.max(rb, rf), s = mt * Math.PI / 2 + 2 * x * mn * Math.tan(an), hp = s / (2 * r), invAt = inv(at);
        function phi(rr) { return Math.max(0, hp + invAt - inv(Math.acos(Math.min(1, rb / rr)))); }
        var phi0 = phi(r0), phiTip = phi(ra), half = Math.PI / z, M = 600, lut = new Float64Array(M + 1), k;
        for (k = 0; k <= M; k++) {
            var u = half * k / M, rr;
            if (u >= phi0) rr = rf;
            else if (u <= phiTip) rr = ra;
            else {   // phi 隨半徑遞減：二分法反求半徑
                var lo = r0, hi = ra;
                for (var it = 0; it < 30; it++) { var mid = (lo + hi) / 2; if (phi(mid) > u) lo = mid; else hi = mid; }
                rr = (lo + hi) / 2;
            }
            lut[k] = rr;
        }
        return { lut: lut, M: M, half: half, pitch: 2 * half, ra: ra, rf: rf, rp: r, tanb: Math.tan(p.beta || 0) };
    }

    function g3Build(d, style, cap) {
        var eps = 1e-9, TAU = 2 * Math.PI;
        var model = new OV.Model();
        var line = (style === 'line');
        var mBody = model.AddMaterial(line ? g3Phong(252, 252, 252, true) : g3Phong(196, 190, 182));
        var mDark = model.AddMaterial(g3Phong(48, 48, 54));
        var mesh = new OV.Mesh(); if (mesh.SetName) mesh.SetName('齒輪');
        var pts = g3Profile(d), np = pts.length, i, area = 0;
        for (i = 0; i < np; i++) { var q0 = pts[i], q1 = pts[(i + 1) % np]; area += (q0[0] * q1[1] - q1[0] * q0[1]) / 2; }
        var flip = area < 0;
        var gs = d.gear, tooth = (gs) ? g3ToothLut(d.p) : null, z = d.p.z;
        var NT = Math.max(180, Math.min(1800, z * 20));
        // 角度取樣：均勻＋鍵槽邊緣各一對（槽內／槽外），讓槽壁是直的
        var th = [], k;
        for (k = 0; k < NT; k++) th.push(-Math.PI + TAU * k / NT);
        var dl = 1e-5;
        function addEdgeAng(a) { th.push(a - dl, a + dl, -a - dl, -a + dl); }
        var aBore = 0, aOut = 0;
        if (d.key) { var rkb = (d.key.si >= 0 && d.bore[d.key.si]) ? d.bore[d.key.si].r1 : d.bore[0].r1; if (d.key.w / 2 < rkb) { aBore = Math.asin(d.key.w / 2 / rkb); addEdgeAng(aBore); addEdgeAng(Math.atan((d.key.w / 2) / (rkb + d.key.t))); } }
        if (d.okey) { var ro = (d.outer[d.okey.si] || d.outer[0]).R1; if (d.okey.w / 2 < ro) { aOut = Math.asin(d.okey.w / 2 / ro); addEdgeAng(aOut); } }
        th = th.filter(function (a) { return a >= -Math.PI && a < Math.PI; }).sort(function (a, b) { return a - b; });
        var tu = [th[0]]; for (k = 1; k < th.length; k++) if (th[k] - tu[tu.length - 1] > 1e-9) tu.push(th[k]);
        th = tu;
        function wrap(a) { a = a % TAU; if (a > Math.PI) a -= TAU; if (a <= -Math.PI) a += TAU; return a; }
        function gearR(t, xx) {
            var tt = t - tooth.tanb * (xx - gs.xs) / tooth.rp;
            var u = ((tt % tooth.pitch) + tooth.pitch) % tooth.pitch;
            if (u > tooth.half) u = tooth.pitch - u;
            return tooth.lut[Math.min(tooth.M, Math.round(u / tooth.half * tooth.M))];
        }
        function slotR(t, rb) {   // 鍵槽是長方形：兩側壁互相平行（往外徑方向），槽頂是平的
            var at = Math.abs(wrap(t)), w2 = d.key.w / 2, tr = Math.atan(w2 / (rb + d.key.t));
            return (at <= tr) ? (rb + d.key.t) / Math.cos(at) : w2 / Math.sin(at);
        }
        function wallAt(xx) {   // 鍵槽所在孔段在 xx 的孔壁半徑（不含倒角）
            var rr = 0;
            d.bore.forEach(function (bg) {
                if (d.key && d.key.si >= 0 && d.bore[d.key.si] !== bg) return;
                if (xx >= bg.xs - eps && xx <= bg.xe + eps) rr = bg.r1 + (bg.r2 - bg.r1) * (xx - bg.xs) / bg.L;
            });
            return rr;
        }
        function onBoreWall(xx, rE) {
            for (var q = 0; q < d.bore.length; q++) {
                var bg = d.bore[q];
                if (d.key && d.key.si >= 0 && d.bore[d.key.si] !== bg) continue;
                if (xx >= bg.xs - eps && xx <= bg.xe + eps && Math.abs(rE - (bg.r1 + (bg.r2 - bg.r1) * (xx - bg.xs) / bg.L)) < 1e-6) return true;
            }
            return false;
        }
        function onOuterWall(xx, rE) {
            var sg = d.outer[d.okey.si] || d.outer[0];
            return xx >= sg.xs - eps && xx <= sg.xe + eps && Math.abs(rE - (sg.R1 + (sg.R2 - sg.R1) * (xx - sg.xs) / sg.L)) < 1e-6;
        }
        function radiusAt(t, xx, rE, tag) {
            var r = rE;
            if (gs && (tag === 'o' || tag === 'f') && xx >= gs.xs - eps && xx <= gs.xe + eps && rE > tooth.rf + 1e-6) {
                var w = Math.max(0, Math.min(1, (rE - tooth.rf) / (tooth.ra - tooth.rf)));
                r = rE + w * (gearR(t, xx) - tooth.ra);
            }
            if (tag === 'b' && d.key && xx >= d.key.k0 && xx <= d.key.k1 && aBore > 0 && Math.abs(wrap(t)) <= aBore) {
                var wl = wallAt(xx);   // 槽頂＝孔壁＋槽深；倒角的點也一併拉到槽頂（槽會切穿倒角）
                if (onBoreWall(xx, rE) || (wl > 0 && rE > wl + 1e-6 && rE - wl < 3)) r = slotR(t, wl);
            }
            if (tag === 'fi' && d.key && xx >= d.key.k0 - eps && xx <= d.key.k1 + eps && aBore > 0 && Math.abs(wrap(t)) <= aBore) {   // 貫穿到端面：端面內緣在槽寬範圍內也拉到槽頂，才是封閉的開口
                var wl2 = wallAt(xx); if (wl2 > 0 && rE >= wl2 - 1e-6 && rE - wl2 < 3) r = slotR(t, wl2);
            }
            if (tag === 'o' && d.okey && xx >= d.okey.k1 && xx <= d.okey.k2 && aOut > 0 && Math.abs(wrap(t)) <= aOut && onOuterWall(xx, rE)) r -= d.okey.t;
            return r;
        }
        function tri(a, b, c, cv) {
            if (a === b || b === c || a === c) return;
            var t = flip ? new OV.Triangle(a, c, b) : new OV.Triangle(a, b, c);
            t.SetMaterial(mBody); t.SetCurve(cv);
            mesh.AddTriangle(t);
            if (cap) cap.tris.push(flip ? [a, c, b, cv] : [a, b, c, cv]);
        }
        // 齒面分區（供線稿取線）：齒頂／齒面／齒根交界才是真正的稜線，其餘細分面之間不該有線
        var utip = 0, u0t = 0;
        if (tooth) {
            var iTi = 0, iRo = tooth.M;
            while (iTi < tooth.M && tooth.lut[iTi] >= tooth.ra - 1e-9) iTi++;
            while (iRo > 0 && tooth.lut[iRo] <= tooth.rf + 1e-9) iRo--;
            utip = tooth.half * iTi / tooth.M; u0t = tooth.half * iRo / tooth.M;
        }
        function toothReg(t, xx) {
            var tt = t - tooth.tanb * (xx - gs.xs) / tooth.rp, u = ((tt % tooth.pitch) + tooth.pitch) % tooth.pitch;
            if (u > tooth.half) u = tooth.pitch - u;
            return u <= utip + 1e-9 ? 1001 : (u < u0t - 1e-9 ? 1002 : 1003);
        }
        var nTw = (gs && Math.abs(tooth.tanb) > 1e-6) ? Math.max(2, Math.min(24, Math.ceil(Math.abs(tooth.tanb * gs.L / tooth.rp) / 0.12))) : 1;
        for (i = 0; i < np; i++) {
            var A = pts[i], B = pts[(i + 1) % np], tag = (A[2] === B[2] && (A[2] === 'o' || A[2] === 'b')) ? A[2] : 'f';
            if (A[1] < 1e-9 && B[1] < 1e-9) continue;               // 軸線上的邊
            var dx = B[0] - A[0], dr = B[1] - A[1], flat = Math.abs(dr) < 1e-9 && Math.abs(dx) > 1e-9;
            var ts = [0, 1];
            if (flat) {
                var xa = Math.min(A[0], B[0]), xb = Math.max(A[0], B[0]);
                var cuts = [];
                if (tag === 'o' && gs && xa >= gs.xs - eps && xb <= gs.xe + eps) for (k = 1; k < nTw; k++) cuts.push(xa + (xb - xa) * k / nTw);
                if (tag === 'b' && d.key) { cuts.push(d.key.k0 - 1e-5, d.key.k0 + 1e-5, d.key.k1 - 1e-5, d.key.k1 + 1e-5); }
                if (tag === 'o' && d.okey) { cuts.push(d.okey.k1 - 1e-5, d.okey.k1 + 1e-5, d.okey.k2 - 1e-5, d.okey.k2 + 1e-5); }
                cuts.forEach(function (cx) { if (cx > xa + 1e-7 && cx < xb - 1e-7) ts.push((cx - A[0]) / dx); });
                ts.sort(function (a, b) { return a - b; });
            }
            var rows = [], rowsX = [], innerIdx = (tag === 'f' && Math.abs(dx) < 1e-9 && A[1] > 1e-9 && B[1] > 1e-9) ? (A[1] < B[1] ? 0 : 1) : -1;
            ts.forEach(function (tt, tIdx) {
                var xx = A[0] + dx * tt, rE = A[1] + dr * tt, row = [], rtag = (tIdx === innerIdx) ? 'fi' : tag;
                if (rE < 1e-9) { var ap = mesh.AddVertex(new OV.Coord3D(xx, 0, 0)); for (k = 0; k < th.length; k++) row.push(ap); }
                else for (k = 0; k < th.length; k++) { var rr = radiusAt(th[k], xx, rE, rtag); row.push(mesh.AddVertex(new OV.Coord3D(xx, rr * Math.cos(th[k]), rr * Math.sin(th[k])))); }
                rows.push(row); rowsX.push(xx);
            });
            var cv = (tag === 'o' && gs && (A[0] + B[0]) / 2 >= gs.xs && (A[0] + B[0]) / 2 <= gs.xe && flat) ? 0 : (i + 1);
            for (var j = 0; j < rows.length - 1; j++) for (k = 0; k < th.length; k++) {
                var k2 = (k + 1) % th.length;
                var a0 = rows[j][k], a1 = rows[j + 1][k], b1 = rows[j + 1][k2], b0 = rows[j][k2], cq = cv;
                if (cv === 0 && tooth) { var tn = (k2 === 0) ? th[0] + TAU : th[k2]; cq = toothReg((th[k] + tn) / 2, (rowsX[j] + rowsX[j + 1]) / 2); }
                tri(a0, a1, b1, cq); tri(a0, b1, b0, cq);
            }
        }
        if (cap) { cap.mesh = mesh; cap.tooth = tooth; cap.gs = gs; cap.utip = utip; cap.u0 = u0t; cap.z = z; cap.surf = function (t, xx) { return surfR(t, xx); }; }
        var im = model.AddMesh(mesh), nd = new OV.Node(); nd.AddMeshIndex(im); model.GetRootNode().AddChildNode(nd);
        // 螺孔：深色圓盤標示孔口位置。圓盤的每個點都貼在實際表面上（外圓柱／齒面／內孔壁），從孔的軸向看過去是正圓
        // 角度方向：端面圖是「從所選端面看過去，由正上方順時針量」。3D 座標 y＝上、z 由 x 軸右手定則決定，
        // 從右端面看時順時針＝−z，從左端面看＝+z，所以右端面視角要把角度取負號，孔的方向才跟端面圖一致
        var sgn3 = (d.viewRight === false) ? 1 : -1;
        var dm = new OV.Mesh(); if (dm.SetName) dm.SetName('螺孔');
        var mHole = line ? model.AddMaterial(g3Phong(0, 0, 0, true)) : mDark;   // 線稿：孔口只畫成黑色細環（輪廓線），中間保持白底
        function discOn(xc, t, rr, rfn, off) {   // 圓盤貼在半徑函數 rfn(角度,x) 的表面上，off＝離表面的距離（外正內負）
            var R0 = rfn(t, xc), n = 36, ring = [], q, a, capRing = [];
            var cv0 = dm.AddVertex(new OV.Coord3D(xc, (R0 + off) * Math.cos(t), (R0 + off) * Math.sin(t)));
            for (q = 0; q < n; q++) {
                a = TAU * q / n;
                var u = rr * Math.cos(a), sx = rr * Math.sin(a), ph = Math.asin(Math.max(-1, Math.min(1, sx / Math.max(R0, 1e-6))));
                var RR = rfn(t + ph, xc + u) + off;
                ring.push(dm.AddVertex(new OV.Coord3D(xc + u, RR * Math.cos(t + ph), RR * Math.sin(t + ph))));
                capRing.push([xc + u, RR * Math.cos(t + ph), RR * Math.sin(t + ph)]);
            }
            if (cap && off > 0) cap.rings.push(capRing);
            if (line) {   // 線稿：細環（外緣 rr、內緣 rr−0.18）
                var ring2 = [];
                for (q = 0; q < n; q++) {
                    a = TAU * q / n;
                    var u2 = (rr - 0.18) * Math.cos(a), sx2 = (rr - 0.18) * Math.sin(a), ph2 = Math.asin(Math.max(-1, Math.min(1, sx2 / Math.max(R0, 1e-6))));
                    var RR2 = rfn(t + ph2, xc + u2) + off;
                    ring2.push(dm.AddVertex(new OV.Coord3D(xc + u2, RR2 * Math.cos(t + ph2), RR2 * Math.sin(t + ph2))));
                }
                for (q = 0; q < n; q++) {
                    var q2 = (q + 1) % n;
                    [[ring[q], ring[q2], ring2[q]], [ring[q2], ring2[q2], ring2[q]]].forEach(function (tv) {
                        var ta = new OV.Triangle(tv[0], tv[1], tv[2]); ta.SetMaterial(mHole); ta.SetCurve(0); dm.AddTriangle(ta);
                        var tb = new OV.Triangle(tv[0], tv[2], tv[1]); tb.SetMaterial(mHole); tb.SetCurve(0); dm.AddTriangle(tb);
                    });
                }
                return;
            }
            for (q = 0; q < n; q++) {
                var t1 = new OV.Triangle(cv0, ring[q], ring[(q + 1) % n]); t1.SetMaterial(mHole); t1.SetCurve(0); dm.AddTriangle(t1);
                var t2 = new OV.Triangle(cv0, ring[(q + 1) % n], ring[q]); t2.SetMaterial(mHole); t2.SetCurve(0); dm.AddTriangle(t2);
            }
        }
        function disc(c, e1, e2, rr) {   // 端面螺孔：平的圓盤
            var cv0 = dm.AddVertex(new OV.Coord3D(c[0], c[1], c[2])), ring = [], n = 28;
            for (var q = 0; q < n; q++) { var a = TAU * q / n; ring.push(dm.AddVertex(new OV.Coord3D(c[0] + rr * (Math.cos(a) * e1[0] + Math.sin(a) * e2[0]), c[1] + rr * (Math.cos(a) * e1[1] + Math.sin(a) * e2[1]), c[2] + rr * (Math.cos(a) * e1[2] + Math.sin(a) * e2[2])))); }
            for (q = 0; q < n; q++) {
                var t1 = new OV.Triangle(cv0, ring[q], ring[(q + 1) % n]); t1.SetMaterial(mHole); t1.SetCurve(0); dm.AddTriangle(t1);
                var t2 = new OV.Triangle(cv0, ring[(q + 1) % n], ring[q]); t2.SetMaterial(mHole); t2.SetCurve(0); dm.AddTriangle(t2);
            }
        }
        var haveDisc = false;
        function surfR(t, xx) {   // 外表面半徑（含齒形）
            var sg = null; d.outer.forEach(function (s) { if (xx >= s.xs - eps && xx <= s.xe + eps) sg = s; });
            if (!sg) return 0;
            return radiusAt(t, xx, sg.R1 + (sg.R2 - sg.R1) * (xx - sg.xs) / sg.L, 'o');
        }
        function boreRAt(xx) { var rr = 0; d.bore.forEach(function (bg) { if (xx >= bg.xs - eps && xx <= bg.xe + eps) rr = bg.r1 + (bg.r2 - bg.r1) * (xx - bg.xs) / bg.L; }); return rr; }
        if (d.rad) d.rad.angles.forEach(function (a) {
            var t = sgn3 * a * Math.PI / 180;
            discOn(d.rad.x, t, d.rad.dm / 2, surfR, 0.05); haveDisc = true;
            if (d.rad.thru === 'all') discOn(d.rad.x, t + Math.PI, d.rad.dm / 2, surfR, 0.05);
        });
        if (d.rad && d.rad.thru !== 'depth') d.rad.angles.forEach(function (a) {   // 貫穿到內孔：孔壁內側也要看得到孔口
            [sgn3 * a * Math.PI / 180, (d.rad.thru === 'all') ? sgn3 * a * Math.PI / 180 + Math.PI : null].forEach(function (t) {
                if (t == null) return;
                var rbv = boreRAt(d.rad.x); if (rbv <= 0) return;
                var cover = d.key && Math.abs(wrap(t)) < 1e-6 && d.key.k0 <= d.rad.x - d.rad.dm / 2 && d.key.k1 >= d.rad.x + d.rad.dm / 2 && d.key.w >= d.rad.dm;
                var kt = cover ? d.key.t : 0;
                discOn(d.rad.x, t, d.rad.dm / 2, function (tt, xx) { return boreRAt(xx) + kt; }, -0.05); haveDisc = true;
            });
        });
        if (d.hole) d.hole.angles.forEach(function (a) {
            var t = sgn3 * a * Math.PI / 180, f0 = (d.hole.face === 'left') ? -0.05 : d.total + 0.05;
            disc([f0, d.hole.Rp * Math.cos(t), d.hole.Rp * Math.sin(t)], [0, 1, 0], [0, 0, 1], d.hole.dm / 2); haveDisc = true;
        });
        if (haveDisc) { var im2 = model.AddMesh(dm), nd2 = new OV.Node(); nd2.AddMeshIndex(im2); model.GetRootNode().AddChildNode(nd2); }
        OV.FinalizeModel(model, function () { return line ? new OV.RGBColor(250, 250, 250) : new OV.RGBColor(196, 190, 182); });
        return model;
    }

    // 線稿加粗加深：WebGL 的線寬固定 1 像素，所以把截圖裡偏暗的像素（線）轉成純黑，再往四周各擴 1 像素（再粗會讓螺旋齒的密集線條糊成一團）
    function g3Bold(url, cb) {
        var im = new Image();
        im.onload = function () {
            try {
                var w = im.width, h = im.height, c = document.createElement('canvas'); c.width = w; c.height = h;
                var x = c.getContext('2d'); x.drawImage(im, 0, 0);
                var id = x.getImageData(0, 0, w, h), dk = x.createImageData(w, h), a = id.data, b = dk.data, i;
                for (i = 0; i < a.length; i += 4) {
                    if (a[i + 3] > 60 && (a[i] + a[i + 1] + a[i + 2]) / 3 < 170) { b[i] = 0; b[i + 1] = 0; b[i + 2] = 0; b[i + 3] = 255; }
                }
                var c2 = document.createElement('canvas'); c2.width = w; c2.height = h; c2.getContext('2d').putImageData(dk, 0, 0);
                var offs = [[-1, 0], [1, 0], [0, -1], [0, 1], [0, 0]];
                offs.forEach(function (o) { x.drawImage(c2, o[0], o[1]); });
                cb(c.toDataURL('image/png'));
            } catch (e) { console.warn('[gear3d] 線稿加粗失敗，使用原圖', e); cb(url); }
        };
        im.onerror = function () { cb(url); };
        im.src = url;
    }

    /* ── 3D → 2D 線圖（像 Inventor 的隱藏線移除、只留實線）──
       ①沿用 3D 預覽同一份網格 ②只取「真正的稜線」：不同曲面群組交界（倒角、階梯、齒頂／齒面／齒根）、輪廓線（正反面交界）、孔口輪廓
       ③用同一視角的深度緩衝（z-buffer）判斷被前面擋住的線並移除 ④相連的線段接成折線，一條線一個物件 */
    function g3Lines(d, cam) {
        var cap = { tris: [], mesh: null, rings: [] };
        g3Build(d, 'line', cap);
        var mesh = cap.mesh, nV = mesh.VertexCount(), i, k;
        function nrm(v) { var l = Math.sqrt(v[0] * v[0] + v[1] * v[1] + v[2] * v[2]) || 1; return [v[0] / l, v[1] / l, v[2] / l]; }
        function cross(a, b) { return [a[1] * b[2] - a[2] * b[1], a[2] * b[0] - a[0] * b[2], a[0] * b[1] - a[1] * b[0]]; }
        var f = nrm([cam.center.x - cam.eye.x, cam.center.y - cam.eye.y, cam.center.z - cam.eye.z]);
        var rt = nrm(cross(f, [cam.up.x, cam.up.y, cam.up.z])), up = cross(rt, f);
        // 焊接重複頂點（不同輪廓段各自建頂點）
        var wmap = {}, wpos = [], wid = new Int32Array(nV), size = 0;
        for (i = 0; i < nV; i++) {
            var c = mesh.GetVertex(i), key = Math.round(c.x * 4000) + ',' + Math.round(c.y * 4000) + ',' + Math.round(c.z * 4000);
            if (wmap[key] === undefined) { wmap[key] = wpos.length; wpos.push([c.x, c.y, c.z]); }
            wid[i] = wmap[key];
            size = Math.max(size, Math.abs(c.x), Math.abs(c.y), Math.abs(c.z));
        }
        // 投影：u＝右、v＝上、z＝深度（越小越近）
        var nW = wpos.length, pu = new Float64Array(nW), pv = new Float64Array(nW), pz = new Float64Array(nW);
        var umin = 1e18, umax = -1e18, vmin = 1e18, vmax = -1e18;
        for (i = 0; i < nW; i++) {
            var p3 = wpos[i];
            pu[i] = p3[0] * rt[0] + p3[1] * rt[1] + p3[2] * rt[2]; pv[i] = p3[0] * up[0] + p3[1] * up[1] + p3[2] * up[2]; pz[i] = p3[0] * f[0] + p3[1] * f[1] + p3[2] * f[2];
            umin = Math.min(umin, pu[i]); umax = Math.max(umax, pu[i]); vmin = Math.min(vmin, pv[i]); vmax = Math.max(vmax, pv[i]);
        }
        var scl = Math.min(1500 / Math.max(umax - umin, 1e-6), 1000 / Math.max(vmax - vmin, 1e-6)), W = Math.ceil((umax - umin) * scl) + 8, H = Math.ceil((vmax - vmin) * scl) + 8;
        function sx(u) { return (u - umin) * scl + 4; }
        function sy(v) { return (vmax - v) * scl + 4; }
        // 三角形（焊接後）與面法線
        var T = cap.tris, nT = T.length, tw = new Int32Array(nT * 3), tn = new Float32Array(nT * 3), tc = new Int32Array(nT);
        for (i = 0; i < nT; i++) {
            var t3 = T[i], w0 = wid[t3[0]], w1 = wid[t3[1]], w2 = wid[t3[2]];
            tw[i * 3] = w0; tw[i * 3 + 1] = w1; tw[i * 3 + 2] = w2; tc[i] = t3[3];
            var a = wpos[w0], b = wpos[w1], cc = wpos[w2], n = nrm(cross([b[0] - a[0], b[1] - a[1], b[2] - a[2]], [cc[0] - a[0], cc[1] - a[1], cc[2] - a[2]]));
            tn[i * 3] = n[0]; tn[i * 3 + 1] = n[1]; tn[i * 3 + 2] = n[2];
        }
        // z-buffer
        var zb = new Float32Array(W * H); for (i = 0; i < zb.length; i++) zb[i] = 1e30;
        for (i = 0; i < nT; i++) {
            var A = tw[i * 3], B = tw[i * 3 + 1], C = tw[i * 3 + 2];
            var x0 = sx(pu[A]), y0 = sy(pv[A]), x1 = sx(pu[B]), y1 = sy(pv[B]), x2 = sx(pu[C]), y2 = sy(pv[C]);
            var den = (y1 - y2) * (x0 - x2) + (x2 - x1) * (y0 - y2); if (Math.abs(den) < 1e-9) continue;
            var bx0 = Math.max(0, Math.floor(Math.min(x0, x1, x2))), bx1 = Math.min(W - 1, Math.ceil(Math.max(x0, x1, x2)));
            var by0 = Math.max(0, Math.floor(Math.min(y0, y1, y2))), by1 = Math.min(H - 1, Math.ceil(Math.max(y0, y1, y2)));
            for (var yy = by0; yy <= by1; yy++) for (var xx = bx0; xx <= bx1; xx++) {
                var l0 = ((y1 - y2) * (xx - x2) + (x2 - x1) * (yy - y2)) / den, l1 = ((y2 - y0) * (xx - x2) + (x0 - x2) * (yy - y2)) / den, l2 = 1 - l0 - l1;
                if (l0 < -0.02 || l1 < -0.02 || l2 < -0.02) continue;
                var z = l0 * pz[A] + l1 * pz[B] + l2 * pz[C];
                if (z < zb[yy * W + xx]) zb[yy * W + xx] = z;
            }
        }
        var eps = Math.max(size, 1) * 0.012;
        function vis(x, y, z) {
            var ix = Math.round(x), iy = Math.round(y);
            for (var dy = -1; dy <= 1; dy++) for (var dx = -1; dx <= 1; dx++) {   // 鄰近像素任一個深度夠近就算看得到（線貼著表面時避免被自己遮住）
                var px = ix + dx, py = iy + dy; if (px < 0 || py < 0 || px >= W || py >= H) continue;
                if (z <= zb[py * W + px] + eps) return true;
            }
            return false;
        }
        // 邊 → 相鄰三角形
        var emap = {};
        for (i = 0; i < nT; i++) for (k = 0; k < 3; k++) {
            var ea = tw[i * 3 + k], eb = tw[i * 3 + (k + 1) % 3], ek = ea < eb ? ea + '_' + eb : eb + '_' + ea, e = emap[ek];
            if (!e) emap[ek] = { a: ea, b: eb, t: [i] }; else e.t.push(i);
        }
        var segs = [];   // 可見線段 [x1,y1,x2,y2]（螢幕像素）
        function addSeg(a, b) {
            var xa = sx(pu[a]), ya = sy(pv[a]), xb = sx(pu[b]), yb = sy(pv[b]), za = pz[a], zb2 = pz[b];
            if (vis(xa, ya, za) && vis(xb, yb, zb2) && vis((xa + xb) / 2, (ya + yb) / 2, (za + zb2) / 2)) segs.push([xa, ya, xb, yb]);
        }
        var DEG = Math.PI / 180;
        Object.keys(emap).forEach(function (ek) {
            var e = emap[ek], draw = false;
            if (e.t.length === 1) draw = true;
            else if (e.t.length >= 2) {
                var t1 = e.t[0], t2 = e.t[1], n1 = [tn[t1 * 3], tn[t1 * 3 + 1], tn[t1 * 3 + 2]], n2 = [tn[t2 * 3], tn[t2 * 3 + 1], tn[t2 * 3 + 2]];
                var d1 = n1[0] * f[0] + n1[1] * f[1] + n1[2] * f[2], d2 = n2[0] * f[0] + n2[1] * f[1] + n2[2] * f[2];
                if ((d1 < -1e-4 && d2 > 1e-4) || (d1 > 1e-4 && d2 < -1e-4)) draw = true;   // 輪廓線：一面朝向、一面背向
                else {
                    var dot = Math.max(-1, Math.min(1, n1[0] * n2[0] + n1[1] * n2[1] + n1[2] * n2[2])), ang = Math.acos(dot);
                    if (tc[t1] !== tc[t2]) draw = (tc[t1] >= 1000 && tc[t2] >= 1000) ? false : ang > 20 * DEG;   // 齒頂／齒面／齒根交界不從網格取（會呈鋸齒狀、每顆齒都多線），改由下面用螺旋線解析式畫
                    else draw = ang > 40 * DEG;   // 同一曲面群組內的尖銳折角（內鍵槽的槽壁與孔壁、槽頂）也要畫
                }
            }
            if (draw) addSeg(e.a, e.b);
        });
        if (cap.tooth && cap.gs) {   // 齒頂邊線與齒根交界：沿軸向的螺旋線，半徑取實際表面（含倒角）
            var th0 = cap.tooth, g0 = cap.gs, nSamp = Math.max(12, Math.min(160, Math.ceil(Math.abs(th0.tanb) * g0.L / th0.rp / 0.05) + 24)), TAU2 = 2 * Math.PI;
            for (var kk = 0; kk < cap.z; kk++) {
                [[cap.utip], [-cap.utip], [cap.u0], [-cap.u0]].forEach(function (c) {
                    var run = [];
                    for (var si = 0; si <= nSamp; si++) {
                        var x3 = g0.xs + g0.L * si / nSamp, tt = kk * th0.pitch + c[0] + th0.tanb * (x3 - g0.xs) / th0.rp, rr = cap.surf(tt, x3);
                        var X = x3, Y = rr * Math.cos(tt), Z = rr * Math.sin(tt);
                        var qu = X * rt[0] + Y * rt[1] + Z * rt[2], qv = X * up[0] + Y * up[1] + Z * up[2], qz = X * f[0] + Y * f[1] + Z * f[2];
                        var px = sx(qu), py = sy(qv);
                        if (vis(px, py, qz)) run.push([px, py]);
                        else { for (var m = 1; m < run.length; m++) segs.push([run[m - 1][0], run[m - 1][1], run[m][0], run[m][1]]); run = []; }
                    }
                    for (var m2 = 1; m2 < run.length; m2++) segs.push([run[m2 - 1][0], run[m2 - 1][1], run[m2][0], run[m2][1]]);
                });
            }
        }
        cap.rings.forEach(function (ring) {   // 孔口輪廓
            var P = ring.map(function (q) { return [sx(q[0] * rt[0] + q[1] * rt[1] + q[2] * rt[2]), sy(q[0] * up[0] + q[1] * up[1] + q[2] * up[2]), q[0] * f[0] + q[1] * f[1] + q[2] * f[2]]; });
            for (var m = 0; m < P.length; m++) {
                var p1 = P[m], p2 = P[(m + 1) % P.length];
                if (vis(p1[0], p1[1], p1[2]) && vis(p2[0], p2[1], p2[2])) segs.push([p1[0], p1[1], p2[0], p2[1]]);
            }
        });
        // 相連線段接成折線
        var ends = {}, used = new Uint8Array(segs.length);
        function ky(x, y) { return Math.round(x * 2) + ',' + Math.round(y * 2); }
        segs.forEach(function (sg, idx) { [ky(sg[0], sg[1]), ky(sg[2], sg[3])].forEach(function (kk) { (ends[kk] = ends[kk] || []).push(idx); }); });
        var polys = [];
        for (i = 0; i < segs.length; i++) {
            if (used[i]) continue; used[i] = 1;
            var chain = [[segs[i][0], segs[i][1]], [segs[i][2], segs[i][3]]];
            for (var dir = 0; dir < 2; dir++) {
                for (var guard = 0; guard < 100000; guard++) {
                    var tip = dir === 0 ? chain[chain.length - 1] : chain[0], nx = null, lst = ends[ky(tip[0], tip[1])] || [];
                    for (var q = 0; q < lst.length; q++) {
                        var sg2 = segs[lst[q]]; if (used[lst[q]]) continue;
                        var s0 = ky(sg2[0], sg2[1]) === ky(tip[0], tip[1]);
                        used[lst[q]] = 1; nx = s0 ? [sg2[2], sg2[3]] : [sg2[0], sg2[1]]; break;
                    }
                    if (!nx) break;
                    if (dir === 0) chain.push(nx); else chain.unshift(nx);
                }
            }
            polys.push(chain);
        }
        var cx = W / 2, cy = H / 2;
        return polys.map(function (ch) { return { pts: ch.map(function (q) { return { x: q[0] - cx, y: q[1] - cy }; }), closed: false, w: 2 }; });
    }
    window.gearDraw3DLines = function () {
        if (!G3_LAST || !G3_VW) { alert('請先按「3D 預覽」'); return; }
        if (typeof window.egGearDrawItems !== 'function') { alert('這個頁面不能把線圖放到畫布'); return; }
        var cam = G3_VW.GetViewer().GetCamera(), t0 = Date.now(), items;
        try { items = g3Lines(G3_LAST, cam); } catch (e) { console.warn('[gear3d] 線圖計算失敗', e); alert('線圖計算失敗：' + (e && e.message ? e.message : e)); return; }
        if (!items.length) { alert('沒有算出任何線'); return; }
        window.egGearDrawItems(items, '齒輪 3D 線圖（依目前視角，只畫實線，' + items.length + ' 條，' + (Date.now() - t0) + ' ms）', []);
    };
    var G3_VW = null, G3_OBJ = null;
    function g3Win() {
        var w = document.getElementById('gear3d-win');
        if (w) return w;
        w = document.createElement('div');
        w.id = 'gear3d-win';
        w.style.cssText = 'position:fixed;left:80px;top:70px;width:780px;height:580px;z-index:10600;background:#fbf7f1;border:2px solid #8a5a2b;border-radius:8px;box-shadow:0 8px 30px rgba(0,0,0,.35);display:none;flex-direction:column;overflow:hidden;';
        w.innerHTML = '<div id="gear3d-hdr" style="background:#8a5a2b;color:#fff;padding:6px 10px;display:flex;align-items:center;gap:8px;cursor:move;font-size:13px;"><i class="fa fa-cube"></i><b>齒輪 3D 預覽</b><span style="flex:1"></span>' +
            '<select id="gear3d-style" style="font-size:12px;border-radius:4px;border:0;padding:1px 4px;"><option value="solid">實體</option><option value="line">線稿（白底黑邊）</option></select>' +
            '<button type="button" id="gear3d-lines" style="background:#F0A24B;color:#3a2a10;border:0;border-radius:4px;padding:2px 8px;cursor:pointer;" title="依目前視角，由模型算出稜線與輪廓線、移除被擋住的線，畫成可編輯的向量線圖放到批圖畫布">放到圖面（線圖）</button>' +
            '<button type="button" id="gear3d-insert" style="background:#F6D9AE;color:#3a2a10;border:0;border-radius:4px;padding:2px 8px;cursor:pointer;" title="把目前的 3D 畫面轉成圖片放到批圖畫布上（舊做法）">圖片</button>' +
            '<button type="button" id="gear3d-refit" style="background:rgba(255,255,255,.2);color:#fff;border:0;border-radius:4px;padding:2px 8px;cursor:pointer;">重設視角</button>' +
            '<button type="button" id="gear3d-close" style="background:rgba(255,255,255,.2);color:#fff;border:0;border-radius:4px;padding:2px 8px;cursor:pointer;">關閉</button></div>' +
            '<div id="gear3d-host" style="flex:1 1 auto;min-height:0;position:relative;"></div>' +
            '<div style="font-size:11px;color:#6b4a1d;padding:4px 10px;background:#fff8ee;border-top:1px solid #f0c98f;">左鍵拖曳＝旋轉，滾輪＝縮放，右鍵拖曳＝平移。螺孔以深色圓盤標示孔口位置（不做挖孔）。</div>';
        document.body.appendChild(w);
        document.getElementById('gear3d-close').onclick = function () { w.style.display = 'none'; };
        document.getElementById('gear3d-refit').onclick = function () { g3Fit(); };
        document.getElementById('gear3d-style').onchange = function () { g3Render(); };
        document.getElementById('gear3d-lines').onclick = function () { window.gearDraw3DLines(); };
        document.getElementById('gear3d-insert').onclick = function () {
            if (!G3_VW) return;
            if (typeof window.egGearInsertImage !== 'function') { alert('這個頁面不能把 3D 圖片放到畫布'); return; }
            var prevSt = document.getElementById('gear3d-style').value;
            g3Render(function () {   // 放到圖面一律用線稿（白底黑邊、背景透明），跟圖面其他線圖一致
                var url = G3_VW.GetViewer().GetImageAsDataUrl(1400, 1000, true);
                if (prevSt !== 'line') g3Render(null, prevSt);
                g3Bold(url, function (u2) { window.egGearInsertImage(u2); });
            }, 'line');
        };
        var hdr = document.getElementById('gear3d-hdr'), sx, sy, sl, st, drag = false;
        hdr.addEventListener('mousedown', function (e) { if (e.target.tagName === 'BUTTON' || e.target.tagName === 'SELECT' || e.target.tagName === 'OPTION') return;   // 下拉選單不能被拖曳處理擋掉（preventDefault 會讓它打不開）
                drag = true; sx = e.clientX; sy = e.clientY; sl = w.offsetLeft; st = w.offsetTop; e.preventDefault(); });
        document.addEventListener('mousemove', function (e) { if (drag) { w.style.left = (sl + e.clientX - sx) + 'px'; w.style.top = (st + e.clientY - sy) + 'px'; } });
        document.addEventListener('mouseup', function () { drag = false; });
        return w;
    }
    function g3Fit() {
        if (!G3_VW) return;
        var viewer = G3_VW.GetViewer(), sph = viewer.GetBoundingSphere(function () { return true; });
        if (sph) { viewer.AdjustClippingPlanesToSphere(sph); viewer.SetUpVector(OV.Direction.Y, false); viewer.FitSphereToWindow(sph, false); }
        viewer.Render();
    }
    var G3_LAST = null;
    function g3Render(cb, forceStyle) {
        var d = G3_LAST; if (!d) return;
        g3Libs(function (ok) {
            if (!ok) { alert('3D 元件載入失敗（找不到 o3dv.min.js）'); return; }
            var w = g3Win(); w.style.display = 'flex';
            var style = forceStyle || document.getElementById('gear3d-style').value, model;
            try { model = g3Build(d, style); } catch (e) { console.warn('[gear3d]', e); alert('3D 模型建立失敗：' + (e && e.message ? e.message : e)); return; }
            var host = document.getElementById('gear3d-host');
            if (!G3_VW) {
                host.innerHTML = '';
                G3_VW = new OV.EmbeddedViewer(host, {
                    backgroundColor: new OV.RGBAColor(247, 243, 238, 255),
                    defaultColor: new OV.RGBColor(196, 190, 182),
                    edgeSettings: new OV.EdgeSettings(false, new OV.RGBColor(0, 0, 0), 1)
                });
                setTimeout(function () { if (G3_VW && G3_VW.Resize) G3_VW.Resize(); }, 150);
            }
            OV.ConvertModelToThreeObject(model, new OV.ModelToThreeConversionParams(), new OV.ModelToThreeConversionOutput(), {
                onModelLoaded: function (obj) {
                    var viewer = G3_VW.GetViewer(), old = G3_OBJ, first = !old;
                    viewer.SetMainObject(obj); G3_OBJ = obj;
                    if (old && OV.DisposeThreeObjects) { try { OV.DisposeThreeObjects(old); } catch (e) {} }
                    if (style === 'line') try {   // 線稿：材質改成不受光照的均勻底色（emissive），畫面上只剩輪廓線
                        obj.traverse(function (o3) {
                            if (!o3.isMesh || !o3.material) return;
                            (Array.isArray(o3.material) ? o3.material : [o3.material]).forEach(function (mt) {
                                if (!mt.color || !mt.emissive) return;
                                var v = mt.color.r; mt.emissive.setRGB(v, v, v); mt.color.setRGB(0, 0, 0);
                                if (mt.specular) mt.specular.setRGB(0, 0, 0);
                            });
                        });
                    } catch (e) { console.warn('[gear3d] 線稿材質失敗', e); }
                    try {
                        viewer.SetEdgeSettings(style === 'line' ? new OV.EdgeSettings(true, new OV.RGBColor(0, 0, 0), 25) : new OV.EdgeSettings(false, new OV.RGBColor(0, 0, 0), 1));
                        viewer.SetBackgroundColor(style === 'line' ? new OV.RGBAColor(255, 255, 255, 255) : new OV.RGBAColor(247, 243, 238, 255));
                    } catch (e) { console.warn('[gear3d] 樣式設定失敗', e); }
                    if (first) g3Fit(); else viewer.Render();   // 之後重畫不動相機，免得每次都被轉回預設角度
                    if (cb) cb();
                },
                onTextureLoaded: function () { if (G3_VW) G3_VW.GetViewer().Render(); }
            });
        });
    }
    window.gearDraw3D = function () {
        window.gearMemSave();
        var d = g3Data(); if (!d) return;
        G3_LAST = d; g3Render();
    };

    // 追蹤畫出的圖，並在相關設定改變時自動重畫（只在批圖編輯器有 egGearTrack 時）
    (function () {
        var o1 = window.gearDrawToCanvas, o2 = window.gearDrawSection;
        window.gearDrawToCanvas = function () { return window.egGearTrack ? window.egGearTrack('end', function () { return o1.apply(null, arguments); }) : o1.apply(null, arguments); };
        window.gearDrawSection = function () { return window.egGearTrack ? window.egGearTrack('sec', function () { return o2.apply(null, arguments); }, 'gearDrawSection') : o2.apply(null, arguments); };
        var o3 = null;   // gearDrawSide 定義在後面，第一次呼叫時才包裝
        window.gearDrawSideTracked = function () { var f = window.__gearSideRaw; return window.egGearTrack ? window.egGearTrack('sec', function () { return f(); }, 'gearDrawSideTracked') : f(); };
        var ids = ['g-draw-hatch', 'g-draw-hatch-gap', 'g-draw-edge', 'g-cut-dir', 'g-view-side', 'g-draw-endbore', 'g-show-obl', 'g-dim-on', 'g-dim-ch-gear', 'g-dim-ch-out', 'g-dim-ch-bore', 'g-align'], tm = null;
        document.addEventListener('change', function (e) {
            var t = e.target; if (!t || !t.id || ids.indexOf(t.id) < 0) return;
            var cb = document.getElementById('g-autoredraw'); if (!cb || !cb.checked || !window.egGearRedraw) return;
            clearTimeout(tm); tm = setTimeout(function () { try { window.egGearRedraw(); } catch (err) { console.warn('[gear] 自動重畫失敗', err); } }, 250);
        });
    })();

    /* ── 外形側視圖（正投影，只畫實線）：由齒形與外形輪廓「算出」特徵線，不靠 3D 引擎猜 ──
       視線垂直於軸線、從 +z 側看過來。畫的線：外形輪廓（含倒角、階梯的垂直交界線）、齒頂線與齒根交界線（螺旋齒會斜）、
       外徑螺孔的外輪廓（橢圓，只畫看得到的那一半）、外鍵槽邊線。孔內、內鍵槽被擋住＝虛線，不畫。 */
    window.gearDrawSide = function () {
        window.gearMemSave && window.gearMemSave();
        var d = g3Data(); if (!d) return;
        if (typeof window.egGearDrawItems !== 'function') return;
        var p = d.p, eps = 1e-9, total = d.total, TAU = 2 * Math.PI;
        var maxR = 0; d.outer.forEach(function (sg) { maxR = Math.max(maxR, sg.R1, sg.R2); });
        var sc = 520 / (2 * maxR);   // 與端面圖同一比例（整個齒輪外形模式）
        var items = [];
        function toPx(a) { return a.map(function (q) { return { x: (q[0] - total / 2) * sc, y: -q[1] * sc }; }); }
        function add(a, w, dash) { if (a.length > 1) items.push({ pts: toPx(a), closed: false, w: w || 2, dash: dash || null }); }
        // 1. 外形輪廓（上、下兩條）＋輪廓上每個轉折處的垂直交界線＋兩端面
        var up = g3Profile(d).filter(function (q) { return q[2] === 'o'; }).map(function (q) { return [q[0], q[1]]; });
        add(up, 2); add(up.map(function (q) { return [q[0], -q[1]]; }), 2);
        up.forEach(function (q, i) {
            if (!(q[1] > eps)) return;
            var a = up[i - 1], b = up[i + 1], corner = (i === 0 || i === up.length - 1);
            if (!corner && a && b) {
                var a1 = Math.atan2(q[1] - a[1], q[0] - a[0]), a2 = Math.atan2(b[1] - q[1], b[0] - q[0]), da = Math.abs(a2 - a1);
                if (da > Math.PI) da = TAU - da;
                corner = da > 0.17;   // 轉折超過約 10° 才算特徵（圓角上的一連串小轉折不畫）
            }
            if (corner) add([[q[0], q[1]], [q[0], -q[1]]], 2);
        });
        // 2. 齒：齒頂兩側邊線與齒根交界線（螺旋齒沿軸向轉動）
        if (d.gear && p.z > 0) {
            var t = g3ToothLut(p), gs = d.gear, M = t.M, lut = t.lut, iT = 0, iR = M;
            while (iT < M && lut[iT] >= t.ra - 1e-9) iT++;
            while (iR > 0 && lut[iR] <= t.rf + 1e-9) iR--;
            var ut = t.half * iT / M, u0 = t.half * iR / M;
            var cl = clampCh(gs.chL, gs.L / 2, gs.R1), cr = clampCh(gs.chR, gs.L / 2, gs.R2);
            var xa = gs.xs + (cl && !cl.fillet ? cl.ax : 0), xb = gs.xe - (cr && !cr.fillet ? cr.ax : 0), nS = Math.max(6, Math.ceil(Math.abs(t.tanb) * (xb - xa) / t.rp / 0.08) + 2);
            for (var k = 0; k < p.z; k++) {
                var th = TAU * k / p.z;
                [[t.ra, ut], [t.ra, -ut], [t.rf, u0], [t.rf, -u0]].forEach(function (c) {
                    var run = [];
                    for (var i = 0; i <= nS; i++) {
                        var xx = xa + (xb - xa) * i / nS, ang = th + c[1] + t.tanb * (xx - gs.xs) / t.rp;
                        if (Math.sin(ang) > 0) run.push([xx, c[0] * Math.cos(ang)]);
                        else { add(run, 1.2); run = []; }
                    }
                    add(run, 1.2);
                });
            }
        }
        // 3. 外徑螺孔外輪廓（只畫看得到的那一半）
        if (d.rad) {
            var rsg = null; d.outer.forEach(function (sg) { if (d.rad.x >= sg.xs - eps && d.rad.x <= sg.xe + eps) rsg = sg; });
            if (rsg) d.rad.angles.forEach(function (a) {
                var tt = a * Math.PI / 180, Rv = rsg.R1 + (rsg.R2 - rsg.R1) * (d.rad.x - rsg.xs) / rsg.L, hm = d.rad.dm / 2, run = [];
                for (var q = 0; q <= 48; q++) {
                    var ph = TAU * q / 48, ps = Math.asin(Math.max(-1, Math.min(1, hm * Math.sin(ph) / Rv))), ang = tt + ps;
                    if (Math.sin(ang) > 0) run.push([d.rad.x + hm * Math.cos(ph), Rv * Math.cos(ang)]);
                    else { add(run, 1.2); run = []; }
                }
                add(run, 1.2);
            });
        }
        // 4. 外鍵槽邊線（槽在正上方：從側面看是槽寬邊緣的稜線與兩端）
        if (d.okey) {
            var osg = d.outer[d.okey.si] || d.outer[0], Ro = osg.R1 + (osg.R2 - osg.R1) * (d.okey.k1 - osg.xs) / osg.L;
            if (d.okey.w / 2 < Ro) {
                var yO = Math.sqrt(Ro * Ro - d.okey.w * d.okey.w / 4);
                add([[d.okey.k1, yO], [d.okey.k2, yO]], 2);
            }
        }
        window.egGearDrawItems(items, '齒輪外形側視圖（正投影，全長 ' + total + ' mm）', []);
    };
    window.__gearSideRaw = window.gearDrawSide;
    window.openGearTool = function(opt) {
        ensureGearDom();
        var w = document.getElementById('gear-tool-window');
        if (!w) return;
        w.style.display = _gearDocked ? 'flex' : 'block';
        var _drawBlk = document.getElementById('g-draw-block');
        if (_drawBlk) _drawBlk.style.display = (typeof window.egGearDrawHook === 'function') ? '' : 'none';
        if (window.gearSegInit) window.gearSegInit();
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
        gpInit();
        if (!w.__defApplied) { w.__defApplied = true; gmemApplyDefaults(); }
        if (!(opt && typeof opt === 'object' && opt.dId)) _gearCurD = 0;
        if (opt && typeof opt === 'object' && opt.dId) {
            _gearCurD = parseInt(opt.dId, 10) || 0;
            window.gearLoadPart(_gearCurD, true);
            if (_gearCurD && gmemRestorePart(_gearCurD) && window.showToast) showToast('已帶入此料號上次的畫圖設定');
        }
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

    // 內花鍵（量測棒放在內齒的槽內，量兩棒之間的「內側」距離）
    // 修正（2026-11-10）：舊公式把符號寫反（e/d − inv(α) + dp/db），
    //   與外花鍵的公式一樣的形狀，算出來的 M 偏大（例 mn4 z20 dp6.72：81.6 → 正確 70.58），
    //   測棒會被算到撐破齒根。已用「漸開線齒面＋測棒相切」暴力幾何模擬逐值驗證。
    // 內齒的槽是「外齒齒形的反面」（越往外越窄），測棒接觸齒面的半徑比測棒中心大：
    //   inv(αM) = e/d + inv(αt) − dp/(db·cosβb)        （e/d＝槽半角＝π/(2z) − 2x·tan(αn)/z）
    //   測棒中心圓 dc = db/cos(αM)；接觸圓 dk：tan(αk) = tan(αM) + dp/(db·cosβb)，dk = db/cos(αk)
    //   M = dc − dp（偶數齒）；dc·cos(90°/z) − dp（奇數齒）
    // 回傳 {M, dc, dk} 或 錯誤字串
    function intPinGeom(x_int, mn, z, alpha_n, beta, dp) {
        var cos_b  = Math.cos(beta);
        var mt     = mn / cos_b;
        var d      = mt * z;
        var alpha_t = Math.atan(Math.tan(alpha_n) / cos_b);
        var db     = d * Math.cos(alpha_t);
        var sin_bb = Math.sin(beta) * Math.cos(alpha_n);
        var cos_bb = Math.sqrt(1 - sin_bb * sin_bb);

        var et_d  = (PI / (2 * z)) - (2 * x_int * Math.tan(alpha_n) / z);
        var kp    = dp / (db * cos_bb);
        var inv_p = et_d + inv(alpha_t) - kp;
        if (inv_p <= 0) return '異常(測棒過大，無法放入齒槽)';

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
        var ak = Math.atan(Math.tan(ap) + kp);
        var dk = db / Math.cos(ak);
        var M  = (z % 2 === 0) ? dc - dp : dc * Math.cos(PI / (2 * z)) - dp;
        return {M: gRound(M, 5), dc: dc, dk: dk};
    }

    function calcMInt(x_int, mn, z, alpha_n, beta, dp) {
        var g = intPinGeom(x_int, mn, z, alpha_n, beta, dp);
        return (typeof g === 'string') ? g : g.M;
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

    // 內花鍵 dp 最大值：測棒越大，接觸點越往小徑（齒頂）移動；
    // 接觸圓 dk 掉到小徑 di 以下＝測棒碰到齒頂，量不準。取 dk ≥ di 的最大 dp。
    function findDpMaxInt(x_int, mn, z, alpha_n, beta, di) {
        var lo = 0.001, hi = 4 * mn * z; // hi 會被「inv_p ≤ 0」自動擋下
        var g0 = intPinGeom(x_int, mn, z, alpha_n, beta, lo);
        if (typeof g0 === 'string' || g0.dk < di) return null;
        for (var i = 0; i < 120; i++) {
            var dp_try = (lo + hi) / 2;
            var g = intPinGeom(x_int, mn, z, alpha_n, beta, dp_try);
            if (typeof g === 'string' || g.dk < di) hi = dp_try; else lo = dp_try;
            if (hi - lo < 1e-7) break;
        }
        return gRound(lo, 4);
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
        var M_upper  = (M_nom !== null && !tol.isEst) ? gRound(M_nom  + tol.upperDev, 4) : null;   // 公差資料庫查無＝只有估算值，不顯示以免誤用
        var M_lower  = (M_nom !== null && !tol.isEst) ? gRound(M_nom  + tol.upperDev - tol.tol, 4) : null;

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
            ? '<span class="sp-est-badge">未顯示公差</span> 公差資料庫查無此標準／精度／配合組合，為避免誤用不顯示 M 公差；請至「公差資料庫」輸入標準值'
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
        if (r.tol.isEst) { setOut('sp-ext-conv-m-up', '—'); setOut('sp-ext-conv-m-dn', '—'); return; }
        setOut('sp-ext-conv-m-up', fmtNum(gRound(M2 + r.tol.upperDev, 4), 4), 'val-ok');
        setOut('sp-ext-conv-m-dn', fmtNum(gRound(M2 + r.tol.upperDev - r.tol.tol, 4), 4), 'val-ok');
    };

    // ── 內花鍵 ──────────────────────────────────────────────────────────────

    window.splineIntStdChange = function() {
        var std = gVal('sp-int-std');
        var isAnsi = (std === 'ANSIB922');
        document.getElementById('sp-int-mn-wrap').style.display = isAnsi ? 'none' : '';
        document.getElementById('sp-int-pd-wrap').style.display = isAnsi ? '' : 'none';
        document.getElementById('sp-int-dnom-wrap').style.display = (std === 'DIN5480') ? '' : 'none';
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
        // 輸入的 x 採花鍵標準表示法（與外花鍵、圖面、DIN 5480 相同）；
        // 下方內花鍵公式採「內齒輪」符號慣例（x 為負號相反），故內部取負。
        var x_spl = (gFloat('sp-int-x') !== null) ? gFloat('sp-int-x') : 0;
        var d_B   = (standard === 'DIN5480') ? (gFloat('sp-int-dnom') || 0) : 0;
        var dinShort = (standard === 'DIN5480' && d_B > 0);   // 選 DIN 5480 並填基準直徑＝短齒高算法
        if (d_B > 0) {   // DIN 5480：d_B = m·z + 2·x·m（勾選「短齒高算法」時改用 x＝(d_B−m·z−1.1m)／2m）
            x_spl = dinShort ? gRound((d_B - mt * z - 1.1 * mn) / (2 * mn), 4) : gRound((d_B - mt * z) / (2 * mn), 4);
            var xEl = document.getElementById('sp-int-x'); if (xEl) xEl.value = x_spl;
        }
        var x_int   = -x_spl;
        var root_form = gVal('sp-int-root');
        var hf_star = (root_form === 'fillet') ? 1.5 : 1.25;
        var quality  = gVal('sp-int-q') || '7';
        var fit_code = gVal('sp-int-fit') || 'H';
        var dp_in    = gFloat('sp-int-dp');

        // 幾何
        var d  = mt * z;
        var di = d - 2 * mn * (1 + x_int);          // 小徑（齒頂）
        var Df = d + 2 * mn * (hf_star - x_int);     // 大徑（齒根）
        // 圖面／標準有給小徑、大徑時以輸入值為準（DIN 5480 等短齒高花鍵的公式值與標準表不同）；留空＝沿用公式
        if (dinShort) { di = d_B - 2 * mn; Df = (root_form === 'fillet') ? d_B + 0.2 * mn : d_B; }   // DIN 5480 短齒高（選用）
        var di_in = gFloat('sp-int-di-in'), Df_in = gFloat('sp-int-df-in');
        if (di_in && di_in > 0) di = di_in;
        if (Df_in && Df_in > 0) Df = Df_in;
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
        var M_nom = null, dk_used = null;
        if (di > db) {
            var pg = intPinGeom(x_int, mn, z, alpha_n, beta, dp_used);
            if (typeof pg === 'string') { warns.push('M 值計算異常：' + pg); }
            else { M_nom = pg.M; dk_used = pg.dk; }
        }

        // 測棒有效性：接觸圓必須落在齒面有效段（小徑 di ～ 大徑 Df）之內
        if (dk_used !== null) {
            if (dk_used < di) warns.push('測棒過大：接觸圓 dk(' + fmtNum(dk_used,4) + ') < 小徑(' + fmtNum(di,4) + ')，測棒會碰到齒頂，請減小 dp');
            else if (dk_used > Df) warns.push('測棒過小：接觸圓 dk(' + fmtNum(dk_used,4) + ') > 大徑(' + fmtNum(Df,4) + ')，測棒會落到齒根，請增大 dp');
        }

        // dp_max：接觸圓剛好等於小徑時的測棒直徑
        var dp_max = (di > db) ? findDpMaxInt(x_int, mn, z, alpha_n, beta, di) : null;

        // 公差
        var tol = getSplineTol(mn, d, quality, fit_code, standard, false);
        // 內花鍵：M_lower = M_nom + EI, M_upper = M_nom + EI + T
        var M_lower = (M_nom !== null && !tol.isEst) ? gRound(M_nom + tol.upperDev, 4) : null;   // 查無資料庫值＝不顯示估算公差
        var M_upper = (M_nom !== null && !tol.isEst) ? gRound(M_nom + tol.upperDev + tol.tol, 4) : null;

        _splineIntResult = {standard:standard, mn:mn, z:z, alpha_n:alpha_n, alpha_t:alpha_t,
            beta:beta, x:x_int, root_form:root_form, d:d, di:di, Df:Df, db:db, h:h,
            dp:dp_used, dp_max:dp_max, dk:dk_used,
            M_nom:M_nom, M_lower:M_lower, M_upper:M_upper, tol:tol};

        setOut('sp-int-out-d',  fmtNum(d,4));  setOut('sp-int-out-di', fmtNum(di,4));
        setOut('sp-int-out-Df', fmtNum(Df,4)); setOut('sp-int-out-db', fmtNum(db,4));
        setOut('sp-int-out-h',  fmtNum(h,4));  setOut('sp-int-out-dp-used', fmtNum(dp_used,4));
        setOut('sp-int-out-dp-max', dp_max !== null ? fmtNum(dp_max,4) : '—');
        setOut('sp-int-out-dk', dk_used !== null ? fmtNum(dk_used,4) : '—');
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
            ? '<span class="sp-est-badge">未顯示公差</span> 公差資料庫查無此標準／精度／配合組合，為避免誤用不顯示 M 公差；請至「公差資料庫」輸入標準值'
            : '<span class="sp-exact-badge">標準值</span> 使用公差資料庫中的精確值';

        setOut('sp-int-conv-m-lo',''); setOut('sp-int-conv-m-up','');
        updateSplineFitBtn();
        drawSplineIntGfx({m:mn, z:z, al:an_deg, beta:bt_deg, x:x_spl, D:dp_used, dTip:di, dRoot:Df, rootForm:(root_form === 'fillet' ? 'fillet' : 'flat'), ok:(di > db && M_nom !== null)});
    };

    // 內花鍵圖示：沿用「內齒計算」的繪圖（同一套幾何與判定）。圖示放在齒輪計算視窗「左側」另一個面板，
    // 方便一邊看圖一邊在右邊改數字；內齒工具沒載入或算不出就不顯示。
    var _gfxObs = false;
    function gfxPanel() {
        var p = document.getElementById('sp-int-gfx-panel');
        if (!p) {
            p = document.createElement('div'); p.id = 'sp-int-gfx-panel';
            p.innerHTML = '<div class="gfxh" title="拖曳標題列可移動視窗；雙擊標題列回到齒輪視窗左側"><b>內花鍵圖示：跨銷徑／跨銷距／齒型</b><button type="button" onclick="closeSplineIntGfx()">✕</button></div>'
                + '<div class="gfxb"><div id="sp-int-g-msg" style="font-size:11px;margin-bottom:6px;"></div>'
                + '<div id="sp-int-g-zoom" class="sp-gfx"></div>'
                + '<div style="display:flex;gap:8px;align-items:flex-start;margin-top:8px;"><div id="sp-int-g-over" class="sp-gfx" style="flex:0 0 150px;width:150px;"></div><div id="sp-int-g-prof" class="sp-gfx" style="flex:1 1 0;min-width:0;"></div></div></div>';
            document.body.appendChild(p);
            // 標題列可拖曳：拖過之後保留使用者放的位置，雙擊標題列才回到自動貼齊齒輪視窗左側
            var hd = p.querySelector('.gfxh');
            hd.style.cursor = 'move';
            hd.addEventListener('mousedown', function(e){
                if (e.target.tagName === 'BUTTON') return;
                var sx = e.clientX, sy = e.clientY, sl = p.offsetLeft, st = p.offsetTop;
                function mv(ev){
                    p.setAttribute('data-drag', '1');
                    p.style.left = Math.max(0, Math.min(window.innerWidth - 80, sl + ev.clientX - sx)) + 'px';
                    p.style.top = Math.max(0, Math.min(window.innerHeight - 40, st + ev.clientY - sy)) + 'px';
                }
                function up(){ document.removeEventListener('mousemove', mv); document.removeEventListener('mouseup', up); }
                document.addEventListener('mousemove', mv); document.addEventListener('mouseup', up);
                e.preventDefault();
            });
            hd.addEventListener('dblclick', function(){ p.removeAttribute('data-drag'); placeSplineIntGfx(); });
        }
        if (!_gfxObs) {
            _gfxObs = true;
            var mo = new MutationObserver(placeSplineIntGfx);
            ['gear-tool-window', 'gear-pane-sp-int'].forEach(function(id){
                var el = document.getElementById(id); if (el) mo.observe(el, { attributes: true, attributeFilter: ['class', 'style'] });
            });
            if (GEAR_DOCK_OFFSET_SEL) { var oe = document.querySelector(GEAR_DOCK_OFFSET_SEL); if (oe) mo.observe(oe, { attributes: true, attributeFilter: ['class', 'style'] }); }
            window.addEventListener('resize', placeSplineIntGfx);
        }
        return p;
    }
    // 頁面左側選單（訂單追蹤頁等）佔掉的寬度：圖示面板不可被它蓋住
    function gfxMinLeft() {
        var sb = document.querySelector('.left_col');
        return (sb && sb.offsetParent !== null) ? Math.max(0, Math.round(sb.getBoundingClientRect().right)) : 0;
    }
    function placeSplineIntGfx() {
        var p = document.getElementById('sp-int-gfx-panel'); if (!p) return;
        var win = document.getElementById('gear-tool-window'), pane = document.getElementById('gear-pane-sp-int');
        var show = p.getAttribute('data-show') === '1' && win && win.style.display && win.style.display !== 'none'
                   && pane && pane.classList.contains('active');
        if (!show) { p.style.display = 'none'; restoreGearFloat(); return; }
        // 浮動視窗（訂單追蹤頁）：視窗置中又很寬，左邊放不下圖示面板 → 第一次顯示圖示時把視窗縮窄並靠右，關閉圖示時還原
        if (!win.classList.contains('gear-docked') && !win.getAttribute('data-gfx-shift')) {
            win.setAttribute('data-gfx-shift', '1');
            win._gfxOrig = { left: win.style.left, top: win.style.top, transform: win.style.transform, width: win.style.width };
            var vw = window.innerWidth, need = 548 + gfxMinLeft();
            var ww = Math.max(640, Math.min(1280, vw - need - 8));
            win.style.width = ww + 'px'; win.style.transform = 'none'; win.style.left = Math.max(0, vw - ww - 6) + 'px';
        }
        var r = win.getBoundingClientRect();
        var ml = gfxMinLeft();
        var w = Math.max(320, Math.min(540, r.left - 8 - ml));
        p.style.width = w + 'px'; p.style.height = r.height + 'px';
        if (p.getAttribute('data-drag') !== '1') { p.style.top = r.top + 'px'; p.style.left = Math.max(ml, r.left - w - 2) + 'px'; }
        p.style.display = 'flex';
    }
    function restoreGearFloat() {
        var win = document.getElementById('gear-tool-window');
        if (!win || !win.getAttribute('data-gfx-shift')) return;
        win.removeAttribute('data-gfx-shift');
        var o = win._gfxOrig; if (!o) return;
        win.style.width = o.width; win.style.transform = o.transform; win.style.left = o.left; win.style.top = o.top;
    }
    window.closeSplineIntGfx = function() { var p = document.getElementById('sp-int-gfx-panel'); if (p) { p.setAttribute('data-show', '0'); p.removeAttribute('data-drag'); p.style.display = 'none'; } restoreGearFloat(); };
    function drawSplineIntGfx(o) {
        var p = document.getElementById('sp-int-gfx-panel');
        if (!o || !o.ok || typeof window.EGInGearDraw !== 'function') { if (p) { p.setAttribute('data-show', '0'); p.style.display = 'none'; } restoreGearFloat(); return; }
        p = gfxPanel();
        var z1 = document.getElementById('sp-int-g-zoom'), o1 = document.getElementById('sp-int-g-over'), p1 = document.getElementById('sp-int-g-prof');
        var msg = document.getElementById('sp-int-g-msg');
        z1.innerHTML = ''; o1.innerHTML = ''; p1.innerHTML = ''; msg.innerHTML = '';
        var r = window.EGInGearDraw({m:o.m, z:o.z, al:o.al, beta:o.beta, x:o.x, D:o.D, dTip:o.dTip, dRoot:o.dRoot, rootForm:o.rootForm}, {zoom:z1, over:o1, prof:p1});
        if (!r) { p.setAttribute('data-show', '0'); p.style.display = 'none'; return; }
        var rg = r.range ? r.range[0].toFixed(2) + ' ~ ' + r.range[1].toFixed(2) + ' mm' : '無（此齒形找不到可用的跨銷徑）';
        var rc = r.rec ? r.rec[0].toFixed(2) + ' ~ ' + r.rec[1].toFixed(2) + ' mm' : '無';
        msg.innerHTML = '<div style="margin-bottom:4px;padding:4px 8px;background:#FBF1E1;border:1px solid #F0D2A0;border-radius:4px;color:#5a3a12;"><b>適合使用的跨銷徑範圍：<span style="color:#b85c0d;font-size:14px;">' + rg + '</span></b>'
            + '<div style="font-size:10.5px;">建議範圍（不靠近齒頂／齒根）：' + rc + '　目前測棒 Ø' + escGear(String(o.D)) + '</div></div>';
        msg.innerHTML += '<span style="color:' + (r.lv === 0 ? '#5a3a12' : (r.lv === 1 ? '#b85c0d' : '#DD5138')) + ';font-weight:700;">' + escGear(r.txt) + '</span>';
        p.setAttribute('data-show', '1');
        placeSplineIntGfx();
    }

    window.clearSplineInt = function() {
        _splineIntResult = null;
        drawSplineIntGfx(null);
        // 輸入欄一併清空（含 DIN5480 自動算出的 x）；標準與下拉選項維持不動
        ['sp-int-mn','sp-int-pd','sp-int-z','sp-int-an','sp-int-bt','sp-int-x','sp-int-dnom','sp-int-di-in','sp-int-df-in','sp-int-q','sp-int-dp','sp-int-conv-dp2'].forEach(function(id){
            var el = document.getElementById(id); if (el) el.value = '';
        });
        ['sp-int-out-d','sp-int-out-di','sp-int-out-Df','sp-int-out-db','sp-int-out-h',
         'sp-int-out-dp-used','sp-int-out-dp-max','sp-int-out-dk','sp-int-out-m-nom','sp-int-out-m-lo','sp-int-out-m-up',
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
        if (r.tol.isEst) { setOut('sp-int-conv-m-lo', '—'); setOut('sp-int-conv-m-up', '—'); return; }
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
        setOut('sp-fit-x-int', fmtNum(-x_int,4));   // 顯示為標準表示法（與外花鍵同號）
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
        var phi     = Math.PI / N;
        var warnMsgs = [];

        // ══ 量測球幾何（2026-11-10 重寫，已用「實體邊界取樣＋球心掃描」暴力模擬逐值驗證）══
        // 約定：B＝平行側面的寬度——軸＝齒寬(鍵寬)、孔＝槽寬。D＝大徑、d＝小徑。
        // ‧軸：球放進兩齒之間的溝，從外往內落下，停在「最先接觸」處：
        //      溝底(圓 d)／齒側面(平行面)／齒頂邊角(圓 D 與側面的交線)，三者取球心最高者。
        //      球心離軸心 h；偶數：M=2h+dp（量兩球外緣）；奇數：M=h+dp/2+D/2（量至對面齒頂）。
        // ‧孔：球放進孔齒的槽，從孔內往外：dp≤B 落到槽底(圓 D)；dp>B 卡在小徑邊角(圓 d 與槽側面的交線)。
        //      球心離軸心 y；偶數：M=2y−dp（兩球最小內距）；奇數：M=y−dp/2+d/2（球內緣至對面齒頂）。
        function ballShaft(D_v, d_v, B_v, dp_v) {
            var rp = dp_v / 2, best = {h: d_v / 2 + rp, c: 'bottom'};
            var hs = (rp + B_v / 2) / Math.sin(phi);
            var rad = Math.sqrt(Math.pow(hs * Math.cos(phi), 2) + B_v * B_v / 4);
            var cand = null;
            if (rad <= D_v / 2) {                       // 接觸點落在齒側面上
                if (rad >= d_v / 2) cand = {h: hs, c: 'side'};
            } else if (B_v < D_v) {                     // 接觸點超出齒頂 → 改接觸齒頂邊角
                var uu = Math.sqrt(D_v * D_v / 4 - B_v * B_v / 4);
                var px = uu * Math.cos(phi) + (B_v / 2) * Math.sin(phi);
                var py = uu * Math.sin(phi) - (B_v / 2) * Math.cos(phi);
                if (rp >= Math.abs(py)) cand = {h: px + Math.sqrt(rp * rp - py * py), c: 'corner'};
            }
            if (cand && cand.h > best.h) best = cand;
            return best;
        }
        function ballBore(D_v, d_v, B_v, dp_v) {
            var rp = dp_v / 2, bottom = D_v / 2 - rp;
            if (dp_v <= B_v || B_v >= d_v) return {y: bottom, c: 'bottom'};
            var px = Math.sqrt(d_v * d_v / 4 - B_v * B_v / 4);
            var y = px - Math.sqrt(rp * rp - B_v * B_v / 4);
            return (y < bottom) ? {y: y, c: 'corner'} : {y: bottom, c: 'bottom'};
        }
        // 回傳 {M, c} 或 null（無法量測）
        function calcM(D_v, d_v, B_v, dp_v) {
            var M, c;
            if (isShaft) {
                var bs = ballShaft(D_v, d_v, B_v, dp_v); c = bs.c;
                M = isEven ? (2 * bs.h + dp_v) : (bs.h + dp_v / 2 + D_v / 2);
            } else {
                var bb = ballBore(D_v, d_v, B_v, dp_v); c = bb.c;
                M = isEven ? (2 * bb.y - dp_v) : (bb.y - dp_v / 2 + d_v / 2);
            }
            return (isFinite(M) && M > 0) ? {M: M, c: c} : null;
        }

        // ── 幾何合理性 ──────────────────────────────────────────────────────
        if (isShaft && B > d * Math.sin(phi)) {
            warnMsgs.push('齒寬 B(' + fmtNum(B,3) + ') 超過 d·sin(180°/N)＝' + fmtNum(d * Math.sin(phi),3) + '：相鄰兩齒在小徑處已互相重疊，沒有溝可放球，請檢查 N、d、B');
        }
        if (!isShaft && B >= d) {
            warnMsgs.push('槽寬 B 不可大於等於小徑 d');
        }

        // ── 可用球徑與建議值 ────────────────────────────────────────────────
        var dpA = null, dpB = null, dp_rec = 0, dpRangeStr;
        if (isShaft) {
            // 掃描球徑：找出「球頂在齒側面（量得到齒寬 B）」的區間；
            // 齒數少、齒間溝寬時（如 6 齒）球永遠先落到溝底或頂到齒頂邊角，這時沒有側面區間。
            var step = Math.max(D / 3000, 0.001), sideLo = null, sideHi = null, firstCorner = null;
            if (B < d * Math.sin(phi)) {
                for (var t = step; t < D; t += step) {
                    var cc = ballShaft(D, d, B, t).c;
                    if (cc === 'side') { if (sideLo === null) sideLo = t; sideHi = t; }
                    else if (cc === 'corner' && firstCorner === null) firstCorner = t;
                }
            }
            if (sideLo !== null) {
                dpA = sideLo; dpB = sideHi;
                dp_rec = gRound((sideLo + sideHi) / 2, 3);
                dpRangeStr = '齒側面接觸（量齒寬 B）：dp ' + fmtNum(sideLo,3) + ' ~ ' + fmtNum(sideHi,3) + ' mm；較小落到溝底（量 d），較大頂到齒頂邊角（量 D、B）';
            } else if (firstCorner !== null) {
                dpB = firstCorner;
                dp_rec = gRound(firstCorner * 0.9, 3);
                dpRangeStr = 'dp ≤ ' + fmtNum(firstCorner,3) + ' mm：球落到溝底（量小徑 d）；較大：球頂在齒頂邊角（量 D 與 B）。此規格球頂不到齒側面';
            } else if (B < d * Math.sin(phi)) {
                dpB = D;
                dp_rec = gRound(B, 3);
                dpRangeStr = '球落到溝底（量小徑 d）；此規格沒有其他接觸型態';
            } else {
                dpRangeStr = '無法量測（齒間溝太窄，放不下球）';
            }
        } else {
            dp_rec = gRound(1.2 * B, 3);
            var g0 = calcM(D, d, B, dp_rec);
            if (!g0) dp_rec = gRound(B, 3);
            dpRangeStr = 'dp ≤ ' + fmtNum(B,3) + '：落到槽底（量大徑 D）；dp > ' + fmtNum(B,3) + '：卡在小徑邊角（量小徑 d 與槽寬 B）';
        }
        var dpCustom = parseFloat(gVal('sr-dp-custom'));
        var customOn = (!isNaN(dpCustom) && dpCustom > 0);
        var dp = customOn ? dpCustom : dp_rec;
        var pinOK = (dp > 0);

        // ── M（含公差：D、d、B 各取上下限共 8 種組合，取最小／最大）──────────
        var M_nom = null, M_upper = null, M_lower = null, contactC = '', methodStr;
        if (pinOK) {
            var g = calcM(D, d, B, dp);
            if (g) {
                M_nom = g.M; contactC = g.c;
                var mins = M_nom, maxs = M_nom;
                [D_max, D_min].forEach(function(Dv){ [d_max, d_min].forEach(function(dv){ [B_max, B_min].forEach(function(Bv){
                    var gx = calcM(Dv, dv, Bv, dp);
                    if (gx) { if (gx.M < mins) mins = gx.M; if (gx.M > maxs) maxs = gx.M; }
                }); }); });
                M_lower = mins; M_upper = maxs;
            } else {
                warnMsgs.push('這顆球算不出跨銷值（球太大或幾何不成立），請改用較小的 dp 或檢查 D、d、B、N');
            }
        }
        var contactTxt = '';
        if (contactC) {
            contactTxt = isShaft
                ? ({bottom:'球落在溝底，量到的是小徑 d', side:'球頂在兩側齒面，量到的是齒寬 B', corner:'球頂在齒頂邊角，量到的是大徑 D 與齒寬 B'}[contactC])
                : ({bottom:'球落在槽底，量到的是大徑 D', corner:'球卡在小徑邊角，量到的是小徑 d 與槽寬 B'}[contactC]);
        }
        methodStr = isShaft
            ? (isEven ? '偶數槽：兩對面的溝各放一球，量兩球外緣距離' : '奇數槽：一球放溝，量球外緣至對面齒頂')
            : (isEven ? '偶數槽：兩對面的槽各放一球，量兩球最小內距' : '奇數槽：一球放槽，量球內緣至對面齒頂');
        if (contactTxt) methodStr += '<br>▸ ' + contactTxt;
        if (customOn && isShaft && sideLo !== null && (dp > sideHi + 1e-9)) {
            warnMsgs.push('自訂球徑 dp=' + dp + ' 已超過齒側面接觸範圍（' + fmtNum(sideHi,3) + '），球頂在齒頂邊角，量不到齒寬');
        } else if (customOn && isShaft && sideLo !== null && (dp < sideLo - 1e-9)) {
            warnMsgs.push('自訂球徑 dp=' + dp + ' 小於齒側面接觸範圍（' + fmtNum(sideLo,3) + '），球落到溝底，量不到齒寬');
        }

        // ── 齒厚 ────────────────────────────────────────────────────────────
        // 軸：B 本身就是齒寬（平行側面），直接是量測值。
        // 孔：槽之間的孔齒不是平行側面，取小徑圓上的弦長（齒頂寬）。
        function calcToothChord(d_v, B_v) {
            if (B_v >= d_v) return null;
            var slotHalfAngle = Math.asin(B_v / d_v);
            var toothAngle = 2*Math.PI/N - 2*slotHalfAngle;
            if (toothAngle <= 0) return null;
            return d_v * Math.sin(toothAngle / 2);
        }
        var tooth_nom, tooth_upper, tooth_lower;
        if (isShaft) {
            tooth_nom = B; tooth_upper = B_max; tooth_lower = B_min;
        } else {
            tooth_nom   = calcToothChord(d, B);
            tooth_upper = calcToothChord(d_max, B_min); // d大B小→齒最寬
            tooth_lower = calcToothChord(d_min, B_max); // d小B大→齒最窄
        }

        // ── 輸出 ─────────────────────────────────────────────────────────────
        var elOut = function(id,v,cls){
            var el = document.getElementById(id);
            el.textContent = v;
            el.className = 'gear-output-val' + (cls?' '+cls:'');
        };
        var lblT = document.getElementById('sr-tooth-lbl');
        if (lblT) lblT.innerHTML = isShaft
            ? '齒寬 B <small style="color:#888;font-weight:400;">（平行側面，直接量）</small>'
            : '孔齒齒頂寬 b <small style="color:#888;font-weight:400;">（小徑弦長）</small>';

        elOut('sr-dp-range', dpRangeStr, (isShaft && !(dpB > 0)) ? 'val-err' : '');
        elOut('sr-dp-rec', (dp_rec > 0 ? fmtNum(dp_rec,3)+' mm' : '—') + (customOn ? ' ★自訂 '+dp+' mm' : ''));

        elOut('sr-M-nom', M_nom!==null ? fmtNum(M_nom,4)+' mm' : '無法量測', M_nom!==null?'val-ok':'val-err');
        var hasTol = Dup||Ddn||dup||ddn||btolCode;
        elOut('sr-M-range', M_nom===null ? '無法量測'
            : (!hasTol ? '（未輸入公差）'
            : fmtNum(M_lower,4)+' ~ '+fmtNum(M_upper,4)+' mm'),
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

        if (warnMsgs.length) {
            var wEl = document.getElementById('sr-warn');
            wEl.style.display = 'block';
            wEl.innerHTML = warnMsgs.map(function(w){ return '<div>' + escGear(w) + '</div>'; }).join('');
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

    // ══ 砂輪/刀具干涉 3D 模擬：把出尾計算已經填好的值帶過去 ════════════════════════
    // 唯一實作在 views/Sales/_grind3d_tool_ui.php（本檔檔尾 include），這裡只負責
    // 「哪一個欄位對應到 3D 工具的哪一個參數」。欄位沒填就不傳，讓 3D 工具用它
    // 自己的預設值，不要硬塞 0 進去（砂輪外徑 0 會直接變成算不出來的錯誤狀態）。
    window.openGrind3DFromTail = function () {
        if (typeof openGrind3D !== 'function') {
            alert('砂輪/刀具干涉 3D 模擬元件尚未載入，請重新整理頁面後再試');
            return;
        }
        function v(id) {
            var el = document.getElementById(id);
            if (!el) return null;
            var n = parseFloat(el.value);
            return isFinite(n) && n > 0 ? n : null;
        }
        var p = {};
        var da = v('tail-da-a'), dr = v('tail-dr'), ds = v('tail-ds');
        var el = document.getElementById('tail-u');
        var u  = el ? parseFloat(el.value) : NaN;
        if (da !== null) p.Dw = da;      // 刀具（砂輪）外徑
        if (dr !== null) p.Dr = dr;      // 齒根直徑 → 工件齒底徑
        if (ds !== null) p.Ds = ds;      // 肩部直徑
        if (isFinite(u) && u >= 0) p.U = u;   // 退刀量（0 是合法值，不可用上面的 >0 判斷）
        openGrind3D(Object.keys(p).length ? p : null);
    };

})();
</script>
<?php
/* 砂輪/刀具干涉 3D 模擬元件（出尾計算分頁右側那顆按鈕要用）。
   放在檔尾才 include，且元件自己有重複 include 防護，所以：
     - 頁面只 include 齒輪工具 → 3D 工具跟著有（批圖編輯器、訂單追蹤都是這樣）
     - 頁面自己也 include 了 3D 工具 → 第二次整個不輸出，不會有兩份
   元件的 o3dv（1MB）是「使用者第一次點開才動態注入」，所以這個 include 只會多出
   CSS 與自己的 inline JS，不會增加頁面載入的重量。 */
if ($can_grind3d) {
    $GRIND3D_TOOL_PDO = $GRIND3D_TOOL_PDO ?? ($GEAR_TOOL_PDO ?? ($pdo ?? ($db ?? null)));
    include __DIR__ . '/_grind3d_tool_ui.php';
}
?>
<?php endif; ?>
