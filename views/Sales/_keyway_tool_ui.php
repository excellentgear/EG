<?php
// c:\MAMP\htdocs\EGsystem\views\Sales\_keyway_tool_ui.php
// ── 鍵槽計算工具：畫面（CSS＋浮動視窗 HTML＋JS）共用檔，唯一實作 ─────────────
// 2026-09-03 由 views/Sales/NewOrder_Track.php 抽出，讓其他頁面也能開同一個工具視窗
//（比照 2026-08-25 齒輪計算工具 _gear_tool_ui.php 的做法；禁止複製第二份＝鐵律4）。
// 用法（任何頁面）：
//   1) 在 </body> 前： $KEYWAY_TOOL_PDO = $pdo; include __DIR__.'/_keyway_tool_ui.php';
//   2) 自己放一顆按鈕： <button onclick="openKwTool()">鍵槽計算</button>
//      （按鈕要不要顯示請用 $can_keyway_calc 判斷——本檔會自動算好）
// 本工具是純前端計算（軸件／片狀鍵槽公差與極限值），沒有後端 API、不依賴 jQuery，
// 也不引用呼叫端頁面的任何全域函式，所以任何頁面 include 都能直接用。
require_once __DIR__ . '/../../src/common/keyway_tool_lib.php';

// 使用權限：呼叫端已經算好就沿用（NewOrder_Track 有自己的 RBAC 區塊），沒算過才在這裡算
if (!isset($can_keyway_calc)) {
    $__kw_pdo = null;
    foreach ([$KEYWAY_TOOL_PDO ?? null, $pdo ?? null, $db ?? null] as $__kw_c) {
        if ($__kw_c instanceof PDO) { $__kw_pdo = $__kw_c; break; }
    }
    $can_keyway_calc = $__kw_pdo ? keyway_tool_can_use($__kw_pdo, intval($_SESSION['id'] ?? 0)) : false;
}
?>
<?php if ($can_keyway_calc): ?>
<style>
        /* ── 鍵槽計算工具 ─────────────────────────────────────────────────── */
        /* ── 鍵槽計算工具 ─────────────────────────────────────────────── */
        #kw-tool-window {
            position:fixed; z-index:10400; display:none;
            width:920px; max-width:96vw; top:55px; left:50%; transform:translateX(-50%);
            background:#fff; border-radius:8px;
            box-shadow:0 12px 40px rgba(0,0,0,.35); border:1px solid #a5d6a7;
        }
        #kw-tool-hdr {
            border-radius:8px 8px 0 0;
            background:linear-gradient(135deg,#1a3a2a,#27ae60);
            color:#fff; padding:9px 14px; cursor:move;
            display:flex; align-items:center; justify-content:space-between; user-select:none;
        }
        #kw-tool-hdr .kw-hdr-title { font-size:14px; font-weight:700; display:flex; align-items:center; gap:8px; }
        #kw-tool-hdr .kw-hdr-btns  { display:flex; gap:6px; }
        #kw-tool-hdr button { background:rgba(255,255,255,.15); border:1px solid rgba(255,255,255,.3); color:#fff; border-radius:4px; padding:2px 10px; font-size:12px; cursor:pointer; }
        #kw-tool-hdr button:hover { background:rgba(255,255,255,.3); }
        #kw-tool-body { padding:10px 12px 12px; }
        /* 3-column layout */
        .kw-layout { display:flex; gap:0; align-items:flex-start; }
        .kw-col-l { width:205px; flex-shrink:0; }
        .kw-col-c { width:225px; flex-shrink:0; display:flex; align-items:flex-start; }
        .kw-col-r { flex:1; min-width:0; }
        /* Cards */
        .kw-card { border:1.5px solid #bbb; border-radius:4px; overflow:hidden; margin-bottom:5px; }
        .kw-card:last-child { margin-bottom:0; }
        .kw-ch { font-size:11px; font-weight:700; color:#fff; padding:4px 7px; letter-spacing:.2px; }
        .kw-ch-lt   { background:#5b2c6f; }
        .kw-ch-lb   { background:#1a5276; }
        .kw-ch-rt   { background:#7b241c; }
        .kw-ch-rr   { background:#4a5320; }
        .kw-ch-rb   { background:#784212; }
        .kw-ch-mach { background:#0e6655; }
        .kw-mutex-tag { font-size:9px; font-weight:400; opacity:.8; }
        /* Dimension row: [nom] [tol_up (lim_up)] / [tol_lo (lim_lo)] */
        .kw-dr { display:flex; align-items:center; padding:4px 7px; gap:5px; background:#fafafa; }
        .kw-dr + .kw-dr { border-top:1px solid #eee; }
        /* Nominal cell */
        .kw-ni, .kw-no {
            width:64px; flex-shrink:0; font-family:"Consolas","Courier New",monospace;
            font-size:17px; font-weight:700; text-align:center;
            border:1px solid #aaa; border-radius:3px; padding:3px 2px; box-sizing:border-box;
            display:block;
        }
        .kw-ni { background:#d5d5d5; color:#222; appearance:textfield; -moz-appearance:textfield; }
        .kw-ni:focus { outline:none; background:#fff; border-color:#27ae60; }
        .kw-no { background:#a8d9f5; color:#1a3a50; }
        /* Tolerance+limit column */
        .kw-tc { display:flex; flex-direction:column; gap:3px; flex:1; min-width:0; }
        .kw-tr { display:flex; align-items:center; gap:4px; }
        .kw-ti, .kw-to {
            width:52px; flex-shrink:0; font-family:"Consolas","Courier New",monospace;
            font-size:11px; text-align:center; border-radius:2px; padding:2px 3px; box-sizing:border-box;
            display:inline-block;
        }
        .kw-ti { background:#d5d5d5; color:#333; border:1px solid #aaa; appearance:textfield; -moz-appearance:textfield; }
        .kw-ti:focus { outline:none; background:#fff; border-color:#27ae60; }
        .kw-to { background:#a8d9f5; color:#1a3a50; border:none; }
        .kw-lv { font-family:"Consolas","Courier New",monospace; font-size:11px; color:#1a3a50; min-width:52px; display:inline-block; }
        .kw-ni::-webkit-outer-spin-button,.kw-ni::-webkit-inner-spin-button,
        .kw-ti::-webkit-outer-spin-button,.kw-ti::-webkit-inner-spin-button { -webkit-appearance:none; margin:0; }
        /* Diff area */
        .kw-diff { font-size:10.5px; padding:4px 7px; background:#edf4ff; border-top:1px solid #c5d8f5; }
        .kw-diff-r { display:flex; gap:5px; align-items:center; margin:2px 0; }
        .kw-diff-lbl { color:#555; min-width:0; }
        .kw-diff-v { font-family:"Consolas","Courier New",monospace; font-weight:700; color:#1a3a50; }
        /* Small note */
        .kw-note { font-size:9.5px; color:#777; padding:2px 7px; background:#f4f4f4; border-bottom:1px solid #e0e0e0; }
        /* Message box */
        #kw-msg-box { font-size:11px; padding:5px 10px; border-radius:4px; margin-top:6px; display:none; }
        #kw-msg-box.warn { background:#fffbea; border:1px solid #f5c518; color:#856404; display:block; }
        #kw-msg-box.err  { background:#fff0f0; border:1px solid #e74c3c; color:#c0392b; display:block; }
        @media (max-width:768px) { #kw-tool-window { width:98vw; top:10px; } .kw-layout { flex-direction:column; } .kw-col-c { width:100%; } }

        /* ── 抽成共用檔後追加：本視窗可能被放進深色主題的頁面（例：批圖編輯器）──
           那些頁面的 body 是深底淺字，沒有寫死顏色的文字會被繼承成淺色＝白底視窗上看不見字。
           這裡只釘「會被繼承的」基本樣式，不動任何既有 .kw-* 規則（避免蓋掉原本的外觀）。 */
        #kw-tool-window {
            color: #333;
            font-family: "Microsoft JhengHei", "PingFang TC", "Segoe UI", sans-serif;
            font-weight: 400;
            line-height: 1.5;
            text-align: left;
        }
        #kw-tool-window *, #kw-tool-window *::before, #kw-tool-window *::after { box-sizing: border-box; }
        #kw-tool-window input, #kw-tool-window select, #kw-tool-window textarea, #kw-tool-window button { font-family: inherit; }
        #kw-tool-container { height: 0; flex: 0 0 auto; }
    </style>
<!-- ═══ 鍵槽計算工具視窗 ═══════════════════════════════════════════════════════ -->
<template id="kw-tool-tpl"><div id="kw-tool-window">
    <div id="kw-tool-hdr">
        <span class="kw-hdr-title"><i class="fa fa-key"></i> 鍵槽計算</span>
        <div class="kw-hdr-btns">
            <button onclick="clearKwTool()">清除</button>
            <button onclick="closeKwTool()">✕ 關閉</button>
        </div>
    </div>
    <div style="display:flex;border-bottom:2px solid #bdc3c7;background:#f5f5f5;padding:0 6px;">
        <button id="kw-tab-shaft" onclick="switchKwTab('shaft')" style="padding:6px 14px;border:none;border-bottom:2px solid #27ae60;background:transparent;color:#27ae60;font-size:12px;font-weight:600;cursor:pointer;margin-bottom:-2px;">軸件</button>
        <button id="kw-tab-plate" onclick="switchKwTab('plate')" style="padding:6px 14px;border:none;border-bottom:2px solid transparent;background:transparent;color:#777;font-size:12px;font-weight:600;cursor:pointer;margin-bottom:-2px;">片狀</button>
    </div>
    <div id="kw-tool-body">
        <div id="kw-pane-shaft">
        <div style="font-size:10.5px;color:#555;margin-bottom:6px;background:#e8f5e9;border:1px solid #a5d6a7;border-radius:4px;padding:4px 9px;">
            灰底：填寫；藍底：自動計算。<strong>右上必填</strong>；右下與左上擇一填寫；左下自動計算。
        </div>
        <!-- 3-column: left blocks | CSS diagram | right blocks -->
        <div style="display:flex;gap:8px;align-items:flex-start;">

            <!-- ════ 左欄 ════ -->
            <div style="width:210px;flex-shrink:0;">
                <div class="kw-card">
                    <div class="kw-ch kw-ch-lt">成品尺寸 <span class="kw-mutex-tag">（與右下擇一）</span></div>
                    <div class="kw-dr">
                        <input id="kw-lt-nom" type="number" class="kw-ni" oninput="calcKw()">
                        <div class="kw-tc">
                            <div class="kw-tr"><input id="kw-lt-utol" type="number" class="kw-ti" oninput="calcKw()"><span class="kw-lv" id="kw-lt-ulim">—</span></div>
                            <div class="kw-tr"><input id="kw-lt-ltol" type="number" class="kw-ti" oninput="calcKw()"><span class="kw-lv" id="kw-lt-llim">—</span></div>
                        </div>
                    </div>
                    <div class="kw-ch kw-ch-mach">加工 / 檢驗尺寸</div>
                    <div class="kw-dr">
                        <span class="kw-no" id="kw-lt-mnom">—</span>
                        <div class="kw-tc">
                            <div class="kw-tr"><span class="kw-to">0</span><span class="kw-lv" id="kw-lt-mulim">—</span><span id="kw-lt-mulim-chk" style="font-size:9px;color:#c0392b;margin-left:3px;font-family:Consolas,monospace;"></span></div>
                            <div class="kw-tr"><span class="kw-to" id="kw-lt-mltol">—</span><span class="kw-lv" id="kw-lt-mllim">—</span><span id="kw-lt-mllim-chk" style="font-size:9px;color:#c0392b;margin-left:3px;font-family:Consolas,monospace;"></span></div>
                        </div>
                    </div>
                </div>
                <div class="kw-card">
                    <div class="kw-ch kw-ch-lb">成品尺寸（左下）</div>
                    <div class="kw-note">原圖有標示則依原圖檢驗</div>
                    <div class="kw-ch kw-ch-mach">加工 / 檢驗尺寸</div>
                    <div class="kw-dr">
                        <span class="kw-no" id="kw-lb-mnom">—</span>
                        <div class="kw-tc">
                            <div class="kw-tr"><span class="kw-to">0</span><span class="kw-lv" id="kw-lb-mulim">—</span></div>
                            <div class="kw-tr"><span class="kw-to" id="kw-lb-mltol">—</span><span class="kw-lv" id="kw-lb-mllim">—</span></div>
                        </div>
                    </div>
                </div>
            </div><!-- /left col -->

            <!-- ════ 中間：CSS 示意圖 (縮小版, pointer-events:none 讓標注線可跨欄) ════ -->
            <div style="position:relative;width:245px;height:155px;flex-shrink:0;overflow:visible;z-index:5;pointer-events:none;">
                <!-- Centerlines (dash-dot) -->
                <div style="position:absolute;top:59px;left:47px;width:148px;height:1px;background:repeating-linear-gradient(to right,#555 0,#555 8px,transparent 8px,transparent 10px,#555 10px,#555 12px,transparent 12px,transparent 14px);"></div>
                <div style="position:absolute;top:12px;left:120px;width:1px;height:102px;background:repeating-linear-gradient(to bottom,#555 0,#555 8px,transparent 8px,transparent 10px,#555 10px,#555 12px,transparent 12px,transparent 14px);"></div>
                <!-- Main circle -->
                <div style="position:absolute;top:20px;left:80px;width:80px;height:80px;border:2px solid black;border-radius:50%;box-sizing:border-box;z-index:1;"></div>
                <!-- Dashed arc (keyway opening) -->
                <div style="position:absolute;top:20px;left:80px;width:80px;height:80px;border:2px dashed black;border-radius:50%;box-sizing:border-box;z-index:0;clip-path:inset(30% 80% 30% 0);"></div>
                <!-- Keyway cutout -->
                <div style="position:absolute;top:48px;left:78px;width:24px;height:24px;background:white;border-top:2px solid black;border-right:2px solid black;border-bottom:2px solid black;box-sizing:border-box;z-index:2;"></div>
                <!-- Center mark -->
                <div style="position:absolute;top:59px;left:116px;width:8px;height:2px;background:#333;z-index:3;"></div>
                <div style="position:absolute;top:55px;left:120px;width:2px;height:9px;background:#333;z-index:3;"></div>
                <!-- RED: OD diagonal double arrow -->
                <div style="position:absolute;top:59px;left:80px;width:80px;height:2px;background:#c0392b;transform:rotate(45deg);transform-origin:50% 50%;z-index:3;">
                    <div style="position:absolute;width:0;height:0;border-top:4px solid transparent;border-bottom:4px solid transparent;border-right:8px solid #c0392b;left:-1px;top:-3px;"></div>
                    <div style="position:absolute;width:0;height:0;border-top:4px solid transparent;border-bottom:4px solid transparent;border-left:8px solid #c0392b;right:-1px;top:-3px;"></div>
                </div>
                <!-- Red extension RIGHT → 右上（延伸進右欄 50px） -->
                <div style="position:absolute;top:89px;left:149px;width:146px;height:2px;background:#c0392b;z-index:3;"></div>
                <!-- Vertical guide lines -->
                <div style="position:absolute;top:72px;left:82px;width:2px;height:16px;background:#5b2c6f;z-index:2;"></div>
                <div style="position:absolute;top:72px;left:102px;width:2px;height:191px;background:#5b8bb8;z-index:2;"></div>
                <div style="position:absolute;top:113px;left:121px;width:2px;height:13px;z-index:2;background:repeating-linear-gradient(to bottom,#1a5276 0,#1a5276 3px,transparent 3px,transparent 5px);"></div>
                <div style="position:absolute;top:72px;left:161px;width:2px;height:191px;background:#e67e22;z-index:2;"></div>
                <!-- PURPLE (左上): 延伸進左欄 50px + double arrow -->
                <div style="position:absolute;top:86px;left:-50px;width:132px;height:2px;background:#5b2c6f;z-index:3;"></div>
                <div style="position:absolute;top:86px;left:82px;width:21px;height:2px;background:#5b2c6f;z-index:3;">
                    <div style="position:absolute;width:0;height:0;border-top:4px solid transparent;border-bottom:4px solid transparent;border-right:8px solid #5b2c6f;left:-1px;top:-3px;"></div>
                    <div style="position:absolute;width:0;height:0;border-top:4px solid transparent;border-bottom:4px solid transparent;border-left:8px solid #5b2c6f;right:-1px;top:-3px;"></div>
                </div>
                <!-- BLUE (左下): L-shape 從量測點(y=119)下折到左下卡片位置(y=162)，延伸進左欄 -->
                <div style="position:absolute;top:119px;left:-50px;width:134px;height:43px;border-bottom:2px solid #1a5276;border-right:2px solid #1a5276;box-sizing:border-box;z-index:3;"></div>
                <div style="position:absolute;top:119px;left:84px;width:18px;height:2px;background:#1a5276;z-index:3;"></div>
                <div style="position:absolute;top:119px;left:102px;width:18px;height:2px;background:#1a5276;z-index:3;">
                    <div style="position:absolute;width:0;height:0;border-top:4px solid transparent;border-bottom:4px solid transparent;border-right:8px solid #1a5276;left:-1px;top:-3px;"></div>
                    <div style="position:absolute;width:0;height:0;border-top:4px solid transparent;border-bottom:4px solid transparent;border-left:8px solid #1a5276;right:-1px;top:-3px;"></div>
                </div>
                <!-- ORANGE (右下): double arrow 對齊右下卡片位置(y≈263)，延伸進右欄 -->
                <div style="position:absolute;top:263px;left:102px;width:59px;height:2px;background:#e67e22;z-index:3;">
                    <div style="position:absolute;width:0;height:0;border-top:4px solid transparent;border-bottom:4px solid transparent;border-right:8px solid #e67e22;left:-1px;top:-3px;"></div>
                    <div style="position:absolute;width:0;height:0;border-top:4px solid transparent;border-bottom:4px solid transparent;border-left:8px solid #e67e22;right:-1px;top:-3px;"></div>
                </div>
                <div style="position:absolute;top:263px;left:161px;width:134px;height:2px;background:#e67e22;z-index:3;"></div>
            </div><!-- /diagram -->

            <!-- ════ 右欄 ════ -->
            <div style="flex:1;min-width:0;">
                <div class="kw-card">
                    <div class="kw-ch kw-ch-rt">成品尺寸（右上：圓柱外徑，必填）</div>
                    <div class="kw-dr">
                        <input id="kw-rt-nom" type="number" class="kw-ni" oninput="calcKw()">
                        <div class="kw-tc">
                            <div class="kw-tr"><input id="kw-rt-utol" type="number" class="kw-ti" oninput="calcKw()"><span class="kw-lv" id="kw-rt-ulim">—</span></div>
                            <div class="kw-tr"><input id="kw-rt-ltol" type="number" class="kw-ti" oninput="calcKw()"><span class="kw-lv" id="kw-rt-llim">—</span></div>
                        </div>
                    </div>
                </div>
                <div class="kw-card">
                    <div class="kw-ch kw-ch-rr">實車尺寸（右上）</div>
                    <div class="kw-dr">
                        <input id="kw-rr-nom" type="number" class="kw-ni" oninput="calcKw()">
                        <div class="kw-tc">
                            <div class="kw-tr"><input id="kw-rr-utol" type="number" class="kw-ti" oninput="calcKw()"><span class="kw-lv" id="kw-rr-ulim">—</span></div>
                            <div class="kw-tr"><input id="kw-rr-ltol" type="number" class="kw-ti" oninput="calcKw()"><span class="kw-lv" id="kw-rr-llim">—</span></div>
                        </div>
                    </div>
                    <div class="kw-diff">
                        <div class="kw-diff-r"><span class="kw-diff-lbl">差異（上）實車上限−成品下限：</span><span class="kw-diff-v" id="kw-dif-u">—</span></div>
                        <div class="kw-diff-r"><span class="kw-diff-lbl">差異（下）實車下限−成品上限：</span><span class="kw-diff-v" id="kw-dif-l">—</span></div>
                        <div class="kw-diff-r" style="margin-top:2px;padding-top:2px;border-top:1px solid #c5d8f5;"><span class="kw-diff-lbl">研磨量（直徑）上：</span><span class="kw-diff-v" id="kw-grind-u">—</span><span class="kw-diff-lbl" style="margin-left:8px;">下：</span><span class="kw-diff-v" id="kw-grind-l">—</span></div>
                        <div class="kw-diff-r"><span class="kw-diff-lbl">研磨量（單邊）上：</span><span class="kw-diff-v" style="color:#c0392b;" id="kw-grind1-u">—</span><span class="kw-diff-lbl" style="margin-left:8px;">下：</span><span class="kw-diff-v" style="color:#c0392b;" id="kw-grind1-l">—</span></div>
                    </div>
                </div>
                <div class="kw-card">
                    <div class="kw-ch kw-ch-rb">成品尺寸（右下：實心端） <span class="kw-mutex-tag">（與左上擇一）</span></div>
                    <div class="kw-dr">
                        <input id="kw-rb-nom" type="number" class="kw-ni" oninput="calcKw()">
                        <div class="kw-tc">
                            <div class="kw-tr"><input id="kw-rb-utol" type="number" class="kw-ti" oninput="calcKw()"><span class="kw-lv" id="kw-rb-ulim">—</span></div>
                            <div class="kw-tr"><input id="kw-rb-ltol" type="number" class="kw-ti" oninput="calcKw()"><span class="kw-lv" id="kw-rb-llim">—</span></div>
                        </div>
                    </div>
                    <div class="kw-ch kw-ch-mach">加工 / 檢驗尺寸</div>
                    <div class="kw-dr">
                        <span class="kw-no" id="kw-rb-mnom">—</span>
                        <div class="kw-tc">
                            <div class="kw-tr"><span class="kw-to">0</span><span class="kw-lv" id="kw-rb-mulim">—</span><span id="kw-rb-mulim-chk" style="font-size:9px;color:#c0392b;margin-left:3px;font-family:Consolas,monospace;"></span></div>
                            <div class="kw-tr"><span class="kw-to" id="kw-rb-mltol">—</span><span class="kw-lv" id="kw-rb-mllim">—</span><span id="kw-rb-mllim-chk" style="font-size:9px;color:#c0392b;margin-left:3px;font-family:Consolas,monospace;"></span></div>
                        </div>
                    </div>
                </div>
            </div><!-- /right col -->

        </div><!-- /3-col -->
        <div id="kw-msg-box"></div>
        </div><!-- /kw-pane-shaft -->

        <!-- ════ 片狀鍵槽計算 ════ -->
        <div id="kw-pane-plate" style="display:none;">
        <div style="font-size:10.5px;color:#555;margin-bottom:6px;background:#e8f0ff;border:1px solid #b3c6f7;border-radius:4px;padding:4px 9px;">
            灰底：填寫；藍底：自動計算。<strong>右上必填</strong>；右下與左上擇一填寫。片狀（內徑研磨）：成品 &gt; 實車。
        </div>
        <div style="display:flex;gap:8px;align-items:flex-start;">

            <!-- ════ 左欄：右下（實心端）════ -->
            <div style="width:210px;flex-shrink:0;">
                <div class="kw-card">
                    <div class="kw-ch kw-ch-rb">成品尺寸（右下：實心端）</div>
                    <div class="kw-dr">
                        <input id="kwp-rb-nom" type="number" class="kw-ni" oninput="calcKwP()">
                        <div class="kw-tc">
                            <div class="kw-tr"><input id="kwp-rb-utol" type="number" class="kw-ti" oninput="calcKwP()"><span class="kw-lv" id="kwp-rb-ulim">—</span></div>
                            <div class="kw-tr"><input id="kwp-rb-ltol" type="number" class="kw-ti" oninput="calcKwP()"><span class="kw-lv" id="kwp-rb-llim">—</span></div>
                        </div>
                    </div>
                    <div class="kw-ch kw-ch-mach">加工 / 檢驗尺寸</div>
                    <div class="kw-dr">
                        <span class="kw-no" id="kwp-rb-mnom">—</span>
                        <div class="kw-tc">
                            <div class="kw-tr"><span class="kw-to" id="kwp-rb-mutol">—</span><span class="kw-lv" id="kwp-rb-mulim">—</span></div>
                            <div class="kw-tr"><span class="kw-to">0</span><span class="kw-lv" id="kwp-rb-mllim">—</span></div>
                        </div>
                    </div>
                </div>
            </div><!-- /left col -->

            <!-- ════ 中間：示意圖（片狀孔內鍵槽，凸出去）════ -->
            <div style="position:relative;width:245px;height:155px;flex-shrink:0;overflow:visible;z-index:5;pointer-events:none;">
                <!-- 中心線 -->
                <div style="position:absolute;top:59px;left:47px;width:148px;height:1px;background:repeating-linear-gradient(to right,#555 0,#555 8px,transparent 8px,transparent 10px,#555 10px,#555 12px,transparent 12px,transparent 14px);"></div>
                <div style="position:absolute;top:12px;left:120px;width:1px;height:102px;background:repeating-linear-gradient(to bottom,#555 0,#555 8px,transparent 8px,transparent 10px,#555 10px,#555 12px,transparent 12px,transparent 14px);"></div>
                <!-- 孔（bore）圓形 — 淺灰底表示中空 -->
                <div style="position:absolute;top:20px;left:80px;width:80px;height:80px;border:2px solid black;border-radius:50%;box-sizing:border-box;z-index:1;background:#eee;"></div>
                <!-- 凸出的鍵槽（往左突出，代表在板材上切出的槽） -->
                <div style="position:absolute;top:48px;left:55px;width:25px;height:24px;background:white;border-top:2px solid black;border-left:2px solid black;border-bottom:2px solid black;box-sizing:border-box;z-index:2;"></div>
                <!-- 遮蓋孔圓弧在鍵槽開口處 -->
                <div style="position:absolute;top:50px;left:78px;width:5px;height:20px;background:#eee;z-index:3;"></div>
                <!-- 中心標記 -->
                <div style="position:absolute;top:59px;left:116px;width:8px;height:2px;background:#333;z-index:3;"></div>
                <div style="position:absolute;top:55px;left:120px;width:2px;height:9px;background:#333;z-index:3;"></div>
                <!-- RED：內徑對角雙箭頭（右上：圓柱內徑） -->
                <div style="position:absolute;top:59px;left:80px;width:80px;height:2px;background:#c0392b;transform:rotate(45deg);transform-origin:50% 50%;z-index:3;">
                    <div style="position:absolute;width:0;height:0;border-top:4px solid transparent;border-bottom:4px solid transparent;border-right:8px solid #c0392b;left:-1px;top:-3px;"></div>
                    <div style="position:absolute;width:0;height:0;border-top:4px solid transparent;border-bottom:4px solid transparent;border-left:8px solid #c0392b;right:-1px;top:-3px;"></div>
                </div>
                <!-- RED 往右延伸 → 右欄右上 -->
                <div style="position:absolute;top:89px;left:149px;width:146px;height:2px;background:#c0392b;z-index:3;"></div>
                <!-- 橘色垂直刻度（鍵槽外壁位置標記） -->
                <div style="position:absolute;top:50px;left:57px;width:2px;height:18px;background:#e67e22;z-index:4;"></div>
                <!-- ORANGE 延伸線：往左進左欄 (x=-50 to x=55) -->
                <div style="position:absolute;top:59px;left:-50px;width:107px;height:2px;background:#e67e22;z-index:4;"></div>
                <!-- ORANGE 雙箭頭：鍵槽外壁 (x=55) → 東四分點 (x=160)，寬105px -->
                <div style="position:absolute;top:59px;left:55px;width:105px;height:2px;background:#e67e22;z-index:4;">
                    <div style="position:absolute;width:0;height:0;border-top:4px solid transparent;border-bottom:4px solid transparent;border-right:8px solid #e67e22;left:-1px;top:-3px;"></div>
                    <div style="position:absolute;width:0;height:0;border-top:4px solid transparent;border-bottom:4px solid transparent;border-left:8px solid #e67e22;right:-1px;top:-3px;"></div>
                </div>
            </div><!-- /diagram -->

            <!-- ════ 右欄 ════ -->
            <div style="flex:1;min-width:0;">
                <div class="kw-card">
                    <div class="kw-ch kw-ch-rt">成品尺寸（右上：圓柱內徑，必填）</div>
                    <div class="kw-dr">
                        <input id="kwp-rt-nom" type="number" class="kw-ni" oninput="calcKwP()">
                        <div class="kw-tc">
                            <div class="kw-tr"><input id="kwp-rt-utol" type="number" class="kw-ti" oninput="calcKwP()"><span class="kw-lv" id="kwp-rt-ulim">—</span></div>
                            <div class="kw-tr"><input id="kwp-rt-ltol" type="number" class="kw-ti" oninput="calcKwP()"><span class="kw-lv" id="kwp-rt-llim">—</span></div>
                        </div>
                    </div>
                </div>
                <div class="kw-card">
                    <div class="kw-ch kw-ch-rr">實車尺寸（右上）</div>
                    <div class="kw-dr">
                        <input id="kwp-rr-nom" type="number" class="kw-ni" oninput="calcKwP()">
                        <div class="kw-tc">
                            <div class="kw-tr"><input id="kwp-rr-utol" type="number" class="kw-ti" oninput="calcKwP()"><span class="kw-lv" id="kwp-rr-ulim">—</span></div>
                            <div class="kw-tr"><input id="kwp-rr-ltol" type="number" class="kw-ti" oninput="calcKwP()"><span class="kw-lv" id="kwp-rr-llim">—</span></div>
                        </div>
                    </div>
                    <div class="kw-diff">
                        <div class="kw-diff-r"><span class="kw-diff-lbl">差異（上）成品上限−實車下限：</span><span class="kw-diff-v" id="kwp-dif-u">—</span></div>
                        <div class="kw-diff-r"><span class="kw-diff-lbl">差異（下）成品下限−實車上限：</span><span class="kw-diff-v" id="kwp-dif-l">—</span></div>
                        <div class="kw-diff-r" style="margin-top:2px;padding-top:2px;border-top:1px solid #c5d8f5;"><span class="kw-diff-lbl">研磨量（直徑）上：</span><span class="kw-diff-v" id="kwp-grind-u">—</span><span class="kw-diff-lbl" style="margin-left:8px;">下：</span><span class="kw-diff-v" id="kwp-grind-l">—</span></div>
                        <div class="kw-diff-r"><span class="kw-diff-lbl">研磨量（單邊）上：</span><span class="kw-diff-v" style="color:#c0392b;" id="kwp-grind1-u">—</span><span class="kw-diff-lbl" style="margin-left:8px;">下：</span><span class="kw-diff-v" style="color:#c0392b;" id="kwp-grind1-l">—</span></div>
                    </div>
                </div>
            </div><!-- /right col -->
        </div>
        <div id="kwp-msg-box"></div>
        </div><!-- /kw-pane-plate -->
    </div><!-- /kw-tool-body -->
</div><!-- /kw-tool-window --></template>
<div id="kw-tool-container"></div>

<script>
(function(){
    'use strict';
    var _kwDomInited = false;

    function kwRD(v, n) { // ROUNDDOWN toward zero
        var f = Math.pow(10, n);
        return (v >= 0 ? Math.floor(v * f) : Math.ceil(v * f)) / f;
    }
    function kwRU(v, n) { // ROUNDUP away from zero
        var f = Math.pow(10, n);
        return (v >= 0 ? Math.ceil(v * f) : Math.floor(v * f)) / f;
    }
    function kwFmt(v) {
        if (v === null || v === undefined || isNaN(v)) return '—';
        var s = v.toString();
        if (s.indexOf('.') !== -1) s = s.replace(/\.?0+$/, '');
        return s;
    }
    function kwFmtLim(v) {
        if (v === null || v === undefined || isNaN(v)) return '—';
        return '(' + kwFmt(v) + ')';
    }
    function kwVal(id) {
        var el = document.getElementById(id);
        if (!el) return null;
        var v = parseFloat(el.value);
        return isNaN(v) ? null : v;
    }
    function kwSet(id, v) {
        var el = document.getElementById(id);
        if (!el) return;
        el.textContent = (v === null || v === undefined) ? '—' : String(v);
    }

    var _kwFieldOrder = [
        'kw-rt-nom','kw-rt-utol','kw-rt-ltol',
        'kw-rr-nom','kw-rr-utol','kw-rr-ltol',
        'kw-rb-nom','kw-rb-utol','kw-rb-ltol',
        'kw-lt-nom','kw-lt-utol','kw-lt-ltol'
    ];

    function initKwEnterNav() {
        _kwFieldOrder.forEach(function(id, i) {
            var el = document.getElementById(id);
            if (!el) return;
            el.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    var next = document.getElementById(_kwFieldOrder[(i + 1) % _kwFieldOrder.length]);
                    if (next) next.focus();
                }
            });
        });
    }

    function initKwEnterNavP() {
        var order = ['kwp-rb-nom','kwp-rb-utol','kwp-rb-ltol',
                     'kwp-rt-nom','kwp-rt-utol','kwp-rt-ltol',
                     'kwp-rr-nom','kwp-rr-utol','kwp-rr-ltol'];
        order.forEach(function(id, i) {
            var el = document.getElementById(id); if (!el) return;
            el.addEventListener('keydown', function(e) {
                if (e.key === 'Enter') { e.preventDefault(); var next = document.getElementById(order[(i+1)%order.length]); if (next) next.focus(); }
            });
        });
    }

    function initKwSelectOnFocus() {
        var win = document.getElementById('kw-tool-window');
        if (!win) return;
        win.querySelectorAll('input[type="number"]').forEach(function(el) {
            el.addEventListener('focus', function() { this.select(); });
        });
    }

    function ensureKwDom() {
        if (_kwDomInited) return;
        _kwDomInited = true;
        var tpl  = document.getElementById('kw-tool-tpl');
        var cont = document.getElementById('kw-tool-container');
        if (tpl && cont) {
            cont.appendChild(document.importNode(tpl.content, true));
            if (tpl.parentNode) tpl.parentNode.removeChild(tpl);
        }
        initKwDrag();
        initKwEnterNav();
        initKwEnterNavP();
        initKwSelectOnFocus();
        switchKwTab('shaft'); // 初始顯示軸件分頁
    }

    window.openKwTool = function() {
        ensureKwDom();
        var w = document.getElementById('kw-tool-window');
        if (w) w.style.display = 'block';
        setTimeout(function(){ var el = document.getElementById('kw-rt-nom'); if (el) el.focus(); }, 60);
    };
    window.closeKwTool = function() {
        var w = document.getElementById('kw-tool-window');
        if (w) w.style.display = 'none';
    };
    window.clearKwTool = function() {
        var platePane = document.getElementById('kw-pane-plate');
        var isPlate   = platePane && platePane.style.display !== 'none';
        if (isPlate) {
            ['kwp-rb-nom','kwp-rb-utol','kwp-rb-ltol',
             'kwp-rt-nom','kwp-rt-utol','kwp-rt-ltol',
             'kwp-rr-nom','kwp-rr-utol','kwp-rr-ltol'].forEach(function(id){
                var el = document.getElementById(id); if (el) el.value = '';
            });
            calcKwP();
            var el = document.getElementById('kwp-rt-nom'); if (el) el.focus();
        } else {
            ['kw-rt-nom','kw-rt-utol','kw-rt-ltol',
             'kw-rr-nom','kw-rr-utol','kw-rr-ltol',
             'kw-rb-nom','kw-rb-utol','kw-rb-ltol',
             'kw-lt-nom','kw-lt-utol','kw-lt-ltol'].forEach(function(id){
                var el = document.getElementById(id); if (el) el.value = '';
            });
            calcKw();
            var el = document.getElementById('kw-rt-nom'); if (el) el.focus();
        }
    };

    window.switchKwTab = function(tab) {
        var A = 'padding:6px 14px;border:none;border-bottom:2px solid #27ae60;background:transparent;color:#27ae60;font-size:12px;font-weight:600;cursor:pointer;margin-bottom:-2px;';
        var I = 'padding:6px 14px;border:none;border-bottom:2px solid transparent;background:transparent;color:#777;font-size:12px;font-weight:600;cursor:pointer;margin-bottom:-2px;';
        var sp = document.getElementById('kw-pane-shaft');
        var pp = document.getElementById('kw-pane-plate');
        var sb = document.getElementById('kw-tab-shaft');
        var pb = document.getElementById('kw-tab-plate');
        if (tab === 'shaft') {
            if (sp) sp.style.display = ''; if (pp) pp.style.display = 'none';
            if (sb) sb.style.cssText = A;   if (pb) pb.style.cssText = I;
        } else {
            if (sp) sp.style.display = 'none'; if (pp) pp.style.display = '';
            if (sb) sb.style.cssText = I;      if (pb) pb.style.cssText = A;
            setTimeout(function(){ var el = document.getElementById('kwp-rt-nom'); if (el) el.focus(); }, 60);
        }
    };

    window.calcKwP = function() {
        function pV(id){ var el=document.getElementById(id); if(!el) return null; var v=parseFloat(el.value); return isNaN(v)?null:v; }
        function pS(id,v){ var el=document.getElementById(id); if(!el) return; el.textContent=(v===null||v===undefined)?'—':String(v); }

        var rtN=pV('kwp-rt-nom'), rtU=pV('kwp-rt-utol'), rtL=pV('kwp-rt-ltol');
        var rrN=pV('kwp-rr-nom'), rrU=pV('kwp-rr-utol'), rrL=pV('kwp-rr-ltol');
        var rtUlim=(rtN!==null&&rtU!==null)?rtN+rtU:null;
        var rtLlim=(rtN!==null&&rtL!==null)?rtN+rtL:null;
        var rrUlim=(rrN!==null&&rrU!==null)?rrN+rrU:null;
        var rrLlim=(rrN!==null&&rrL!==null)?rrN+rrL:null;
        pS('kwp-rt-ulim', kwFmtLim(rtUlim!==null?parseFloat(rtUlim.toFixed(5)):null));
        pS('kwp-rt-llim', kwFmtLim(rtLlim!==null?parseFloat(rtLlim.toFixed(5)):null));
        pS('kwp-rr-ulim', kwFmtLim(rrUlim!==null?parseFloat(rrUlim.toFixed(5)):null));
        pS('kwp-rr-llim', kwFmtLim(rrLlim!==null?parseFloat(rrLlim.toFixed(5)):null));

        // 差異（片狀方向：成品 > 實車）
        // 注意：研磨量必須「交叉相減」才能取得極端值
        var difU=null,difL=null,grnd1U=null,grnd1L=null;
        if(rtUlim!==null&&rtLlim!==null&&rrUlim!==null&&rrLlim!==null){
            difU   = rtUlim - rrLlim;  // 最大直徑研磨量：成品上限 − 實車下限
            difL   = rtLlim - rrUlim;  // 最小直徑研磨量：成品下限 − 實車上限
            grnd1U = difU / 2;          // 最大單邊研磨量
            grnd1L = difL / 2;          // 最小單邊研磨量
        }
        pS('kwp-dif-u',    difU!==null  ?kwFmt(parseFloat(difU.toFixed(5))):null);
        pS('kwp-dif-l',    difL!==null  ?kwFmt(parseFloat(difL.toFixed(5))):null);
        pS('kwp-grind-u',  difU!==null  ?kwFmt(parseFloat(difU.toFixed(5))):null);
        pS('kwp-grind-l',  difL!==null  ?kwFmt(parseFloat(difL.toFixed(5))):null);
        pS('kwp-grind1-u', grnd1U!==null?kwFmt(parseFloat(grnd1U.toFixed(5))):null);
        pS('kwp-grind1-l', grnd1L!==null?kwFmt(parseFloat(grnd1L.toFixed(5))):null);

        // 右下（已移至左欄）
        var rbN=pV('kwp-rb-nom'),rbU=pV('kwp-rb-utol'),rbL=pV('kwp-rb-ltol');
        var rbUlim=(rbN!==null&&rbU!==null)?rbN+rbU:null;
        var rbLlim=(rbN!==null&&rbL!==null)?rbN+rbL:null;
        pS('kwp-rb-ulim', kwFmtLim(rbUlim!==null?parseFloat(rbUlim.toFixed(5)):null));
        pS('kwp-rb-llim', kwFmtLim(rbLlim!==null?parseFloat(rbLlim.toFixed(5)):null));
        var rbMU=null,rbML=null;
        if(rbUlim!==null&&rbLlim!==null&&grnd1U!==null&&grnd1L!==null){
            // 安全公差：確保不論研磨量落在哪個極端，成品必定在公差內
            // 安全下限：成品下限 − 最小單邊研磨量（往上捨，避免低於成品要求）
            rbML = kwRU(rbLlim - grnd1L, 2);
            // 安全上限：成品上限 − 最大單邊研磨量（往下捨，避免超過成品要求）
            rbMU = kwRD(rbUlim - grnd1U, 2);
        }
        // 加工尺寸：以下限為標稱，上公差為正數，下公差=0
        pS('kwp-rb-mnom',  rbML!==null?kwFmt(rbML):null);
        pS('kwp-rb-mutol', rbMU!==null&&rbML!==null?kwFmt(parseFloat((rbMU-rbML).toFixed(5))):null);
        pS('kwp-rb-mulim', rbMU!==null?kwFmtLim(rbMU):null);
        pS('kwp-rb-mllim', rbML!==null?kwFmtLim(rbML):null);
    };

    window.calcKw = function() {
        // ── 讀取右上 ──────────────────────────────────────────────────────
        var rtN  = kwVal('kw-rt-nom');
        var rtU  = kwVal('kw-rt-utol');
        var rtL  = kwVal('kw-rt-ltol');
        var rrN  = kwVal('kw-rr-nom');
        var rrU  = kwVal('kw-rr-utol');
        var rrL  = kwVal('kw-rr-ltol');

        // 右上 顯示上下限
        var rtUlim = (rtN !== null && rtU !== null) ? rtN + rtU : null;
        var rtLlim = (rtN !== null && rtL !== null) ? rtN + rtL : null;
        var rrUlim = (rrN !== null && rrU !== null) ? rrN + rrU : null;
        var rrLlim = (rrN !== null && rrL !== null) ? rrN + rrL : null;

        kwSet('kw-rt-ulim', kwFmtLim(rtUlim !== null ? parseFloat(rtUlim.toFixed(5)) : null));
        kwSet('kw-rt-llim', kwFmtLim(rtLlim !== null ? parseFloat(rtLlim.toFixed(5)) : null));
        kwSet('kw-rr-ulim', kwFmtLim(rrUlim !== null ? parseFloat(rrUlim.toFixed(5)) : null));
        kwSet('kw-rr-llim', kwFmtLim(rrLlim !== null ? parseFloat(rrLlim.toFixed(5)) : null));

        // ── 差異 & 研磨量 ─────────────────────────────────────────────────
        var difU  = null, difL  = null;
        var grndU = null, grndL = null, grnd1U = null, grnd1L = null;
        if (rtUlim !== null && rtLlim !== null && rrUlim !== null && rrLlim !== null) {
            difU  = rrUlim - rtLlim;   // 實車上限 - 成品下限
            difL  = rrLlim - rtUlim;   // 實車下限 - 成品上限
            grndU = rrUlim - rtUlim;   // 研磨量上（直徑）
            grndL = rrLlim - rtLlim;   // 研磨量下（直徑）
            grnd1U = grndU / 2;
            grnd1L = grndL / 2;
        }
        kwSet('kw-dif-u',    difU  !== null ? kwFmt(parseFloat(difU.toFixed(5)))  : null);
        kwSet('kw-dif-l',    difL  !== null ? kwFmt(parseFloat(difL.toFixed(5)))  : null);
        kwSet('kw-grind-u',  grndU !== null ? kwFmt(parseFloat(grndU.toFixed(5))) : null);
        kwSet('kw-grind-l',  grndL !== null ? kwFmt(parseFloat(grndL.toFixed(5))) : null);
        kwSet('kw-grind1-u', grnd1U !== null ? kwFmt(parseFloat(grnd1U.toFixed(5))) : null);
        kwSet('kw-grind1-l', grnd1L !== null ? kwFmt(parseFloat(grnd1L.toFixed(5))) : null);

        // ── 讀取右下 / 左上 ───────────────────────────────────────────────
        var rbN  = kwVal('kw-rb-nom'),  rbU = kwVal('kw-rb-utol'), rbL = kwVal('kw-rb-ltol');
        var ltN  = kwVal('kw-lt-nom'),  ltU = kwVal('kw-lt-utol'), ltL = kwVal('kw-lt-ltol');
        var rbHas = (rbN !== null);
        var ltHas = (ltN !== null);

        // 互斥警告
        var msgEl = document.getElementById('kw-msg-box');
        if (msgEl) {
            if (rbHas && ltHas) {
                msgEl.className = 'warn';
                msgEl.textContent = '⚠ 右下與左上只能擇一填寫，請清除其中一個。';
            } else {
                msgEl.className = '';
                msgEl.textContent = '';
            }
        }

        // ── 右下 顯示上下限 & 加工/検驗 ───────────────────────────────────
        var rbUlim = (rbN !== null && rbU !== null) ? rbN + rbU : null;
        var rbLlim = (rbN !== null && rbL !== null) ? rbN + rbL : null;
        kwSet('kw-rb-ulim', kwFmtLim(rbUlim !== null ? parseFloat(rbUlim.toFixed(5)) : null));
        kwSet('kw-rb-llim', kwFmtLim(rbLlim !== null ? parseFloat(rbLlim.toFixed(5)) : null));

        var rbMU = null, rbML = null;
        var rbChkU = null, rbChkL = null;
        if (rbUlim !== null && rbLlim !== null && grnd1U !== null && grnd1L !== null) {
            var absG1U = Math.abs(grnd1U);
            var absG1L = Math.abs(grnd1L);
            // 加工上限：成品上限 + 最小單邊研磨量（取絕對值），往下捨
            rbMU = kwRD(rbUlim + absG1L, 2);
            // 加工下限：成品下限 + 最大單邊研磨量（取絕對值），往上捨
            rbML = kwRU(rbLlim + absG1U, 2);
            // 驗算：加工上下限 − 對應研磨量 ≈ 成品上下限
            rbChkU = parseFloat((rbMU - absG1L).toFixed(5));
            rbChkL = parseFloat((rbML - absG1U).toFixed(5));
        }
        kwSet('kw-rb-mnom',  rbMU !== null ? kwFmt(rbMU) : null);
        kwSet('kw-rb-mltol', rbMU !== null && rbML !== null ? kwFmt(parseFloat((rbML - rbMU).toFixed(5))) : null);
        kwSet('kw-rb-mulim', rbMU !== null ? kwFmtLim(rbMU) : null);
        kwSet('kw-rb-mllim', rbML !== null ? kwFmtLim(rbML) : null);
        kwSet('kw-rb-mulim-chk', rbChkU !== null ? '→' + kwFmt(rbChkU) : '');
        kwSet('kw-rb-mllim-chk', rbChkL !== null ? '→' + kwFmt(rbChkL) : '');

        // ── 左上 顯示上下限 & 加工/検驗 ───────────────────────────────────
        var ltUlim = (ltN !== null && ltU !== null) ? ltN + ltU : null;
        var ltLlim = (ltN !== null && ltL !== null) ? ltN + ltL : null;
        kwSet('kw-lt-ulim', kwFmtLim(ltUlim !== null ? parseFloat(ltUlim.toFixed(5)) : null));
        kwSet('kw-lt-llim', kwFmtLim(ltLlim !== null ? parseFloat(ltLlim.toFixed(5)) : null));

        var ltMU = null, ltML = null;
        var ltChkU = null, ltChkL = null;
        if (ltUlim !== null && ltLlim !== null && grnd1U !== null && grnd1L !== null) {
            var absG1U_lt = Math.abs(grnd1U);
            var absG1L_lt = Math.abs(grnd1L);
            ltMU = kwRD(ltUlim + absG1L_lt, 2);
            ltML = kwRU(ltLlim + absG1U_lt, 2);
            ltChkU = parseFloat((ltMU - absG1L_lt).toFixed(5));
            ltChkL = parseFloat((ltML - absG1U_lt).toFixed(5));
        }
        kwSet('kw-lt-mnom',  ltMU !== null ? kwFmt(ltMU) : null);
        kwSet('kw-lt-mltol', ltMU !== null && ltML !== null ? kwFmt(parseFloat((ltML - ltMU).toFixed(5))) : null);
        kwSet('kw-lt-mulim', ltMU !== null ? kwFmtLim(ltMU) : null);
        kwSet('kw-lt-mllim', ltML !== null ? kwFmtLim(ltML) : null);
        kwSet('kw-lt-mulim-chk', ltChkU !== null ? '→' + kwFmt(ltChkU) : '');
        kwSet('kw-lt-mllim-chk', ltChkL !== null ? '→' + kwFmt(ltChkL) : '');

        // ── 左下（圓心到鍵槽底）─────────────────────────────────────────
        // 取有填入的一側（右下優先若兩側都填則以右下為準，互斥警告已提示）
        var filledN = rbHas ? rbN : (ltHas ? ltN : null);
        var filledL = rbHas ? rbLlim : (ltHas ? ltLlim : null);

        var lbMN = null, lbMLtol = null;
        if (filledN !== null && rrLlim !== null && rtLlim !== null && filledL !== null) {
            // E18: ROUNDDOWN(K14 - K6/2, 2)  K6=실차下限, K14=filled 성품 nominal
            lbMN    = kwRD(filledN - rrLlim / 2, 2);
            // F19: ROUNDUP(ABS(K7/2 - K15) - E18, 2)  K7=성품下限, K15=filled 성품下限
            var raw = Math.abs(rtLlim / 2 - filledL) - lbMN;
            lbMLtol = kwRU(raw, 2);
        }
        kwSet('kw-lb-mnom',  lbMN !== null ? kwFmt(lbMN) : null);
        kwSet('kw-lb-mltol', lbMLtol !== null ? kwFmt(lbMLtol) : null);
        kwSet('kw-lb-mulim', lbMN !== null ? kwFmtLim(lbMN) : null);
        kwSet('kw-lb-mllim', (lbMN !== null && lbMLtol !== null) ? kwFmtLim(parseFloat((lbMN + lbMLtol).toFixed(5))) : null);
    };

    function initKwDrag() {
        var hdr = document.getElementById('kw-tool-hdr');
        var win = document.getElementById('kw-tool-window');
        if (!hdr || !win) return;
        var sx, sy, sl, st;
        hdr.addEventListener('mousedown', function(e) {
            if (e.target.tagName === 'BUTTON' || e.target.tagName === 'I') return;
            sx = e.clientX; sy = e.clientY;
            sl = parseInt(win.style.left) || win.getBoundingClientRect().left;
            st = parseInt(win.style.top)  || win.getBoundingClientRect().top;
            win.style.transform = 'none';
            win.style.left = sl + 'px'; win.style.top = st + 'px';
            document.addEventListener('mousemove', onDrag);
            document.addEventListener('mouseup',   onDrop);
            e.preventDefault();
        });
        function onDrag(e) {
            win.style.left = (sl + e.clientX - sx) + 'px';
            win.style.top  = (st + e.clientY - sy) + 'px';
        }
        function onDrop() {
            document.removeEventListener('mousemove', onDrag);
            document.removeEventListener('mouseup',   onDrop);
        }
    }
})();
</script>
<?php endif; ?>
