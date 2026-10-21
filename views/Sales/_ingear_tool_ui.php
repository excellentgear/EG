<?php
// c:\MAMP\htdocs\EGsystem\views\Sales\_ingear_tool_ui.php
// ── 內齒計算工具（跨銷距）：畫面（CSS＋浮動視窗 HTML＋JS）共用檔，唯一實作 ─────────────
// 2026-10-21 新增。比照鍵槽計算（_keyway_tool_ui.php）的「外掛」做法：獨立檔案、純前端、
// 不依賴 jQuery、不引用呼叫端任何全域函式、不讀寫任何資料表，所以任何頁面 include 都能用，
// 而且完全不會動到既有的齒輪計算工具（_gear_tool_ui.php）與它的資料。
// 用法（任何頁面）：
//   1) 在 </body> 前： $INGEAR_TOOL_PDO = $pdo; include __DIR__.'/_ingear_tool_ui.php';
//   2) 自己放一顆按鈕： <button onclick="openInGearTool()">內齒計算</button>
//      （要不要顯示用 $can_ingear_calc 判斷，本檔會自動算好）
// 權限：沿用「齒輪計算」的規則（系統管理員或持有 ot_gear_calc 的角色），不另開角色。
// 算法：內齒輪（齒槽involute）的量銷（跨銷）距
//   inv α_p = e/d + inv α − D/d_b      （e＝分度圓齒槽寬、D＝銷徑、d_b＝基圓直徑）
//   偶數齒  M = d_b/cos α_p − D
//   奇數齒  M = d_b·cos(π/2z)/cos α_p − D
//   轉位係數 x 定義：e = πm/2 + 2·x·m·tanα（與既有齒輪軟體的符號一致）
//   接觸點半徑 r_k：tan α_k = tan α_p + D/d_b
require_once __DIR__ . '/../../src/common/gear_tool_lib.php';

if (!isset($can_ingear_calc)) {
    if (isset($show_gear_tool)) {
        $can_ingear_calc = (bool)$show_gear_tool;
    } else {
        $__ig_pdo = null;
        foreach ([$INGEAR_TOOL_PDO ?? null, $pdo ?? null, $db ?? null] as $__ig_c) {
            if ($__ig_c instanceof PDO) { $__ig_pdo = $__ig_c; break; }
        }
        $can_ingear_calc = $__ig_pdo ? gear_tool_can_use($__ig_pdo, intval($_SESSION['id'] ?? 0)) : false;
    }
}
?>
<?php if ($can_ingear_calc): ?>
<style>
    #ig-win { position:fixed; z-index:10410; display:none; width:930px; max-width:96vw; max-height:94vh; top:30px; left:50%; transform:translateX(-50%);
        background:#fff; color:#333; border-radius:8px; box-shadow:0 12px 40px rgba(0,0,0,.35); border:1px solid #e0b27a;
        font-family:"Microsoft JhengHei","PingFang TC",sans-serif; font-size:12px; line-height:1.4; box-sizing:border-box; flex-direction:column; }
    #ig-win * { box-sizing:border-box; }
    #ig-hdr { border-radius:8px 8px 0 0; background:linear-gradient(135deg,#5b3a1a,#e67e22); color:#fff; padding:7px 12px; display:flex; align-items:center; justify-content:space-between; cursor:move; user-select:none; }
    #ig-hdr .ig-title { font-weight:700; font-size:13px; }
    #ig-hdr button { background:rgba(255,255,255,.18); color:#fff; border:1px solid rgba(255,255,255,.5); border-radius:4px; padding:2px 9px; font-size:12px; cursor:pointer; margin-left:5px; }
    #ig-hdr button:hover { background:rgba(255,255,255,.32); }
    #ig-body { padding:8px 12px 12px; overflow:auto; }
    #ig-win .ig-row { display:flex; gap:8px; flex-wrap:wrap; align-items:flex-end; }
    #ig-win .ig-f { display:flex; flex-direction:column; }
    #ig-win .ig-f label { font-size:11px; color:#6b4423; font-weight:600; margin-bottom:2px; }
    #ig-win .ig-in { width:96px; padding:3px 6px; border:1px solid #c9b79c; border-radius:4px; background:#f3f3f3; font-size:12px; font-family:Consolas,monospace; color:#333; }
    #ig-win .ig-in:focus { outline:none; border-color:#e67e22; background:#fff; }
    #ig-win .ig-in.ig-bad { border-color:#DD5138; background:#fdeeea; }
    #ig-win .ig-note { font-size:10.5px; color:#6b4423; background:#FBF1E1; border:1px solid #F0D2A0; border-radius:4px; padding:4px 9px; margin-bottom:6px; }
    #ig-win .ig-err { font-size:11px; color:#DD5138; margin-top:3px; min-height:14px; }
    #ig-tabs { display:flex; border-bottom:2px solid #d9c3a3; background:#faf4ea; padding:0 6px; margin:8px 0; }
    #ig-tabs button { padding:6px 14px; border:none; border-bottom:2px solid transparent; background:transparent; color:#777; font-size:12px; font-weight:600; cursor:pointer; margin-bottom:-2px; }
    #ig-tabs button.on { border-bottom-color:#e67e22; color:#b85c0d; }
    #ig-cols { display:flex; gap:12px; align-items:flex-start; }
    #ig-left { width:390px; flex-shrink:0; }
    #ig-right { flex:1; min-width:0; }
    #ig-win .ig-card { border:1px solid #e3d3b8; border-radius:6px; margin-bottom:8px; overflow:hidden; }
    #ig-win .ig-ch { background:#F7E0BD; color:#5a3a12; font-weight:700; padding:3px 8px; font-size:11.5px; }
    #ig-win .ig-cb { padding:6px 8px; }
    #ig-win table.ig-t { width:100%; border-collapse:collapse; }
    #ig-win table.ig-t td { padding:2px 4px; vertical-align:top; }
    #ig-win table.ig-t td.k { color:#6b4423; width:46%; }
    #ig-win table.ig-t td.v { font-family:Consolas,monospace; font-weight:700; text-align:right; }
    #ig-win .ig-big { font-size:17px; color:#b85c0d; }
    #ig-win .ig-badge { display:block; margin:6px 0 2px; padding:5px 8px; border-radius:5px; font-weight:700; font-size:12px; }
    #ig-win .ig-ok   { background:#F7E0BD; color:#5a3a12; border:1px solid #e0b27a; }
    #ig-win .ig-warn { background:#F0A24B; color:#3d2200; }
    #ig-win .ig-ng   { background:#DD5138; color:#fff; }
    #ig-win .ig-btn { padding:3px 9px; border:1px solid #e0b27a; background:#fff; color:#b85c0d; border-radius:4px; font-size:11px; font-weight:600; cursor:pointer; }
    #ig-win .ig-btn:hover { background:#FBF1E1; }
    #ig-win svg { display:block; background:#fffdf9; border:1px solid #e3d3b8; border-radius:6px; }
    @media (max-width: 900px) { #ig-cols { flex-direction:column; } #ig-left { width:100%; } }
</style>

<template id="ig-tpl"><div id="ig-win">
    <div id="ig-hdr">
        <span class="ig-title"><i class="fa fa-circle-o-notch"></i> 內齒計算（跨銷距）</span>
        <span><button type="button" onclick="clearInGearTool()">清除</button><button type="button" onclick="closeInGearTool()">✕ 關閉</button></span>
    </div>
    <div id="ig-body">
        <div class="ig-note">內齒輪的量銷（跨銷）計算：算跨銷距、標準跨銷徑，以及把<b>客戶的銷徑與跨銷距換算成我方銷徑</b>；右側圖示會畫出銷與齒面的接觸位置，<b>銷徑過大（接觸點超出齒頂）或過小（碰到齒根）會以紅色標示</b>。灰底欄位自己填。</div>
        <div class="ig-row">
            <div class="ig-f"><label>模數 m</label><input class="ig-in" id="ig-m" type="text" inputmode="decimal"></div>
            <div class="ig-f"><label>齒數 z</label><input class="ig-in" id="ig-z" type="text" inputmode="numeric"></div>
            <div class="ig-f"><label>壓力角 α（度）</label><input class="ig-in" id="ig-al" type="text" inputmode="decimal" value="20"></div>
            <div class="ig-f"><label title="內齒的齒頂圓（內徑，較小的那一圈）。空白＝自動：分度圓徑 − 2m">齒頂圓徑 Di（空白自動）</label><input class="ig-in" id="ig-dtip" type="text" inputmode="decimal"></div>
            <div class="ig-f"><label title="內齒的齒根圓（較大的那一圈）。空白＝自動：分度圓徑 + 2.5m">齒根圓徑 Df（空白自動）</label><input class="ig-in" id="ig-droot" type="text" inputmode="decimal"></div>
        </div>
        <div id="ig-gerr" class="ig-err"></div>
        <div id="ig-tabs">
            <button type="button" id="ig-tab-a" class="on" onclick="switchInGearTab('a')">跨銷距計算</button>
            <button type="button" id="ig-tab-b" onclick="switchInGearTab('b')">跨銷換算（客戶銷徑 → 我方銷徑）</button>
        </div>
        <div id="ig-cols">
            <div id="ig-left">
                <div id="ig-pane-a">
                    <div class="ig-card"><div class="ig-ch">輸入</div><div class="ig-cb">
                        <div class="ig-row">
                            <div class="ig-f"><label title="e = πm/2 + 2·x·m·tanα（x 為負＝齒槽變窄）">轉位係數 x</label><input class="ig-in" id="ig-x" type="text" inputmode="decimal" value="0"></div>
                            <div class="ig-f"><label>銷徑 D（mm）</label><input class="ig-in" id="ig-d" type="text" inputmode="decimal"></div>
                            <div class="ig-f"><label>&nbsp;</label><button type="button" class="ig-btn" onclick="inGearUseStd()" title="把銷徑填成「接觸點剛好落在分度圓」的標準跨銷徑">帶入標準跨銷徑</button></div>
                        </div>
                    </div></div>
                </div>
                <div id="ig-pane-b" style="display:none">
                    <div class="ig-card"><div class="ig-ch">客戶提供的資料</div><div class="ig-cb">
                        <div class="ig-row">
                            <div class="ig-f"><label>客戶銷徑 D1</label><input class="ig-in" id="ig-d1" type="text" inputmode="decimal"></div>
                            <div class="ig-f"><label>客戶跨銷距 M1</label><input class="ig-in" id="ig-m1" type="text" inputmode="decimal"></div>
                        </div>
                        <div class="ig-row" style="margin-top:5px">
                            <div class="ig-f"><label>上公差（例 +0.15）</label><input class="ig-in" id="ig-ut" type="text" inputmode="decimal" value="0"></div>
                            <div class="ig-f"><label>下公差（例 0）</label><input class="ig-in" id="ig-lt" type="text" inputmode="decimal" value="0"></div>
                        </div>
                    </div></div>
                    <div class="ig-card"><div class="ig-ch">我方量測用的銷</div><div class="ig-cb">
                        <div class="ig-row">
                            <div class="ig-f"><label>我方銷徑 D2</label><input class="ig-in" id="ig-d2" type="text" inputmode="decimal"></div>
                            <div class="ig-f"><label>&nbsp;</label><button type="button" class="ig-btn" onclick="inGearUseStd2()" title="把我方銷徑填成這顆齒輪的標準跨銷徑">帶入標準跨銷徑</button></div>
                        </div>
                        <div style="font-size:10.5px;color:#6b4423;margin-top:4px">由客戶的銷徑與跨銷距反推這顆齒輪的齒槽寬（轉位係數 x），再換算成我方銷徑的跨銷距與公差範圍。上方的轉位係數欄位在這個分頁不使用。</div>
                    </div></div>
                </div>
                <div class="ig-card"><div class="ig-ch">計算結果</div><div class="ig-cb" id="ig-res"></div></div>
            </div>
            <div id="ig-right">
                <div id="ig-zoom"></div>
                <div style="display:flex;gap:8px;margin-top:8px;align-items:flex-start">
                    <div id="ig-over" style="flex-shrink:0"></div>
                    <div style="font-size:10.5px;color:#6b4423">
                        <div><b>圖示說明</b></div>
                        <div>左上：銷與齒槽放大。棕線＝齒面，<span style="color:#b85c0d">●</span> 銷與齒面的接觸點。</div>
                        <div>虛線由左至右依序：齒頂圓（內徑）、分度圓、接觸點、齒根圓。</div>
                        <div>銷顏色：琥珀＝可量測；橘＝接近極限；<span style="color:#DD5138;font-weight:700">紅＝不可量測</span>。</div>
                        <div>換算分頁會以灰色虛線疊上客戶的銷，方便比較兩顆銷的位置。</div>
                        <div>左下：整顆齒輪與兩顆量測銷（奇數齒時兩銷不在正對面，差半個齒距）。</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div></template>
<div id="ig-container"></div>

<script>
(function(){
    'use strict';
    var tab = 'a', domInited = false;

    /* ───── 計算核心 ───── */
    function inv(a){ return Math.tan(a) - a; }
    function invInv(v){
        if (!(v >= 0)) return null;
        var lo = 0, hi = 1.5, i, mid;
        for (i = 0; i < 80; i++) { mid = (lo + hi) / 2; if (inv(mid) < v) lo = mid; else hi = mid; }
        return (lo + hi) / 2;
    }
    function pinAt(g, e, D){
        var ap = invInv(e / g.d + inv(g.al) - D / g.db);
        if (ap === null) return null;
        var rp = g.db / 2 / Math.cos(ap);
        var ak = Math.atan(Math.tan(ap) + D / g.db);
        var rk = g.db / 2 / Math.cos(ak);
        var psik = e / g.d + inv(g.al) - inv(ak);
        var M = (g.z % 2 === 0) ? 2 * rp - D : 2 * rp * Math.cos(Math.PI / (2 * g.z)) - D;
        return { ap: ap, rp: rp, ak: ak, rk: rk, psik: psik, M: M, D: D };
    }
    function solveE(g, Mt, D){
        var lo = 0.02 * g.m, hi = Math.PI * g.m * 0.999, i, mid, r;
        function f(e){ var q = pinAt(g, e, D); return q ? q.M : -1e9; }
        if (f(hi) < Mt || f(lo) > Mt) return null;
        for (i = 0; i < 100; i++) { mid = (lo + hi) / 2; r = f(mid); if (r < Mt) lo = mid; else hi = mid; }
        return (lo + hi) / 2;
    }
    function eOf(g, x){ return Math.PI * g.m / 2 + 2 * x * g.m * Math.tan(g.al); }
    function xOf(g, e){ return (e - Math.PI * g.m / 2) / (2 * g.m * Math.tan(g.al)); }
    function stdPin(g, e){ var ap = g.al - e / g.d; return ap <= 0 ? null : g.db * (Math.tan(g.al) - Math.tan(ap)); }
    function psiAt(g, e, r){ var c = Math.min(1, g.db / 2 / r); return e / g.d + inv(g.al) - inv(Math.acos(c)); }

    function judge(g, r){
        var rt = g.dTip / 2, rr = g.dRoot / 2, span = rr - rt, rho = r.D / 2;
        if (r.rk < rt - 1e-9) return { lv: 2, txt: '✗ 跨銷徑過大：接觸點 Ø' + f(2 * r.rk, 3) + ' 已超出齒頂圓 Ø' + f(g.dTip, 3) + '（銷壓在齒頂以外，無法正確量測）' };
        if (r.rp + rho > rr + 1e-9) return { lv: 2, txt: '✗ 跨銷徑過小：銷底 Ø' + f(2 * (r.rp + rho), 3) + ' 已碰到齒根圓 Ø' + f(g.dRoot, 3) + '（銷落到齒根，無法正確量測）' };
        if (r.rk > rr) return { lv: 2, txt: '✗ 跨銷徑過小：接觸點 Ø' + f(2 * r.rk, 3) + ' 超出齒根圓 Ø' + f(g.dRoot, 3) };
        if (r.rk - rt < 0.1 * span) return { lv: 1, txt: '⚠ 接觸點很靠近齒頂（離齒頂圓只剩 ' + f(r.rk - rt, 3) + ' mm），銷再大一點就量不準' };
        if (rr - r.rk < 0.1 * span) return { lv: 1, txt: '⚠ 接觸點很靠近齒根（離齒根圓只剩 ' + f(rr - r.rk, 3) + ' mm），可能碰到齒根圓角' };
        return { lv: 0, txt: '✓ 可正常量測：接觸點在有效齒面內（離齒頂 ' + f(r.rk - rt, 3) + ' mm、離齒根 ' + f(rr - r.rk, 3) + ' mm）' };
    }

    // 可用銷徑區間：掃描銷徑，取第一段連續「判定等級 ≤ maxLv」的範圍（下限進位、上限捨去到 0.01，確保區間內每個值都真的可用）
    function usableRange(g, e, maxLv){
        var D, q, ok, lo = null, hi = null, step = 0.002, lim = g.m * 3;
        for (D = 0.02; D <= lim; D += step) {
            q = pinAt(g, e, D); ok = q && judge(g, q).lv <= maxLv;
            if (ok) { if (lo === null) lo = D; hi = D; }
            else if (lo !== null) break;
        }
        if (lo === null) return null;
        var a = Math.ceil(lo * 100 - 1e-9) / 100, b = Math.floor(hi * 100 + 1e-9) / 100;
        return a <= b ? [a, b] : null;
    }
    function rangeRows(g, e){
        var u = usableRange(g, e, 1), sf = usableRange(g, e, 0);
        return row('可用跨銷徑範圍', u ? f(u[0], 2) + ' ~ ' + f(u[1], 2) + ' mm' : '無', 'ig-big') +
               row('建議範圍（不靠近齒頂／齒根）', sf ? f(sf[0], 2) + ' ~ ' + f(sf[1], 2) + ' mm' : '無');
    }

    /* ───── 小工具 ───── */
    function $(id){ return document.getElementById(id); }
    function f(v, n){ return (v === null || v === undefined || !isFinite(v)) ? '—' : (+v).toFixed(n); }
    function pv(id){
        var el = $(id); if (!el) return null;
        var s = String(el.value).replace(/\s+/g, '').replace(/，/g, '.');
        if (s === '' || s === '+' || s === '-') return null;
        var v = parseFloat(s); return isNaN(v) ? null : v;
    }
    function bad(id, on){ var el = $(id); if (el) el.classList[on ? 'add' : 'remove']('ig-bad'); }
    function row(k, v, cls){ return '<tr><td class="k">' + k + '</td><td class="v ' + (cls || '') + '">' + v + '</td></tr>'; }
    function badge(j){ return '<span class="ig-badge ' + (j.lv === 0 ? 'ig-ok' : (j.lv === 1 ? 'ig-warn' : 'ig-ng')) + '">' + j.txt + '</span>'; }

    function readGear(){
        var m = pv('ig-m'), z = pv('ig-z'), al = pv('ig-al'), dt = pv('ig-dtip'), dr = pv('ig-droot'), msg = '';
        bad('ig-m', false); bad('ig-z', false); bad('ig-al', false); bad('ig-dtip', false); bad('ig-droot', false);
        if (m === null && z === null) { $('ig-gerr').textContent = ''; return null; } // 剛開啟／清除後：先不報錯，等使用者輸入
        if (m === null || m <= 0) { bad('ig-m', true); msg = '請輸入大於 0 的模數'; }
        else if (z === null || z < 6 || Math.floor(z) !== z) { bad('ig-z', true); msg = '齒數需為 6 以上的整數'; }
        else if (al === null || al <= 0 || al >= 45) { bad('ig-al', true); msg = '壓力角需介於 0～45 度之間'; }
        if (msg) { $('ig-gerr').textContent = msg; return null; }
        var a = al * Math.PI / 180, d = m * z;
        var g = { m: m, z: z, al: a, alDeg: al, d: d, db: d * Math.cos(a) };
        g.dTip = (dt !== null && dt > 0) ? dt : d - 2 * m;
        g.dRoot = (dr !== null && dr > 0) ? dr : d + 2.5 * m;
        if (g.dTip <= g.db) { bad('ig-dtip', true); msg = '齒頂圓徑不可小於或等於基圓徑 Ø' + f(g.db, 3); }
        else if (g.dRoot <= g.dTip) { bad('ig-droot', true); msg = '齒根圓徑必須大於齒頂圓徑'; }
        $('ig-gerr').textContent = msg;
        return msg ? null : g;
    }

    /* ───── 繪圖 ───── */
    function drawZoom(g, e, pins){ // pins: [{r, col, dash, lbl}]
        var W = 450, H = 320, rt = g.dTip / 2, rr = g.dRoot / 2, m = g.m;
        var x0 = rt - 0.9 * m, x1 = rr + 0.9 * m, s = W / (x1 - x0), N = 10, i, k;
        function X(x){ return (x - x0) * s; }
        function Y(y){ return H / 2 - y * s; }
        function P(r, ph){ return X(r * Math.cos(ph)).toFixed(1) + ',' + Y(r * Math.sin(ph)).toFixed(1); }
        function ps(r){ return Math.max(0, psiAt(g, e, r)); }
        var step = 2 * Math.PI / g.z, out = '', ring = '';
        function flank(phc, sign){ var a = [], r; for (i = 0; i <= N; i++) { r = rt + (rr - rt) * i / N; a.push(P(r, phc + sign * ps(r))); } return a; }
        // 外環（齒根圓以外的實體）
        var rg = [], r2 = rr + 2 * m, ang;
        for (i = -12; i <= 12; i++) { ang = i * step * 0.2; rg.push(P(rr, ang)); }
        for (i = 12; i >= -12; i--) { ang = i * step * 0.2; rg.push(P(r2, ang)); }
        out += '<polygon points="' + rg.join(' ') + '" fill="#F7E0BD"/>';
        // 齒（兩側各一顆）
        for (k = -1; k <= 0; k++) {
            var pc = k * step, pn = (k + 1) * step, t = [], r;
            for (i = 0; i <= N; i++) { r = rr - (rr - rt) * i / N; t.push(P(r, pc + ps(r))); }
            for (i = 1; i <= 3; i++) t.push(P(rt, pc + ps(rt) + (pn - ps(rt) - (pc + ps(rt))) * i / 4));
            for (i = 0; i <= N; i++) { r = rt + (rr - rt) * i / N; t.push(P(r, pn - ps(r))); }
            t.push(P(r2, pn - ps(rr))); t.push(P(r2, pc + ps(rr)));
            out += '<polygon points="' + t.join(' ') + '" fill="#F7E0BD"/>';
        }
        // 輪廓線：三個齒槽的兩側齒面＋槽底
        for (k = -1; k <= 1; k++) {
            var ph = k * step;
            out += '<polyline fill="none" stroke="#6b4423" stroke-width="1.6" points="' + flank(ph, -1).join(' ') + '"/>';
            out += '<polyline fill="none" stroke="#6b4423" stroke-width="1.6" points="' + flank(ph, 1).join(' ') + '"/>';
            out += '<polyline fill="none" stroke="#6b4423" stroke-width="1.6" points="' + P(rr, ph - ps(rr)) + ' ' + P(rr, ph) + ' ' + P(rr, ph + ps(rr)) + '"/>';
        }
        // 基準虛線
        var lines = [[rt, '齒頂Ø' + f(g.dTip, 2), 12], [g.d / 2, '分度Ø' + f(g.d, 2), 24], [rr, '齒根Ø' + f(g.dRoot, 2), 12]];
        var main = pins[0] ? pins[0].r : null;
        if (main) lines.push([main.rk, '接觸Ø' + f(2 * main.rk, 2), 36]);
        for (i = 0; i < lines.length; i++) {
            var lx = X(lines[i][0]);
            out += '<line x1="' + lx.toFixed(1) + '" x2="' + lx.toFixed(1) + '" y1="' + (lines[i][2] + 4) + '" y2="' + H + '" stroke="#b58b5a" stroke-width="1" stroke-dasharray="4 3"/>';
            out += '<text x="' + (lx + 3).toFixed(1) + '" y="' + lines[i][2] + '" font-size="10" fill="#6b4423">' + lines[i][1] + '</text>';
        }
        // 銷
        for (i = pins.length - 1; i >= 0; i--) {
            var pn2 = pins[i], q = pn2.r; if (!q) continue;
            var cx = X(q.rp), cy = Y(0), rad = q.D / 2 * s;
            out += '<circle cx="' + cx.toFixed(1) + '" cy="' + cy.toFixed(1) + '" r="' + rad.toFixed(1) + '" fill="' + (pn2.dash ? 'none' : pn2.col) + '" fill-opacity="' + (pn2.dash ? 0 : 0.45) + '" stroke="' + pn2.col + '" stroke-width="1.6"' + (pn2.dash ? ' stroke-dasharray="5 3"' : '') + '/>';
            out += '<circle cx="' + cx.toFixed(1) + '" cy="' + cy.toFixed(1) + '" r="1.8" fill="' + pn2.col + '"/>';
            var kx = q.rk * Math.cos(q.psik), ky = q.rk * Math.sin(q.psik);
            out += '<circle cx="' + X(kx).toFixed(1) + '" cy="' + Y(ky).toFixed(1) + '" r="3.2" fill="' + pn2.col + '" stroke="#fff"/>';
            out += '<circle cx="' + X(kx).toFixed(1) + '" cy="' + Y(-ky).toFixed(1) + '" r="3.2" fill="' + pn2.col + '" stroke="#fff"/>';
            out += '<text x="' + (cx - rad).toFixed(1) + '" y="' + (cy + rad + 13).toFixed(1) + '" font-size="10.5" fill="#333">' + pn2.lbl + '</text>';
        }
        out += '<text x="6" y="' + (H - 8) + '" font-size="10" fill="#6b4423">← 齒輪中心</text>';
        $('ig-zoom').innerHTML = '<svg width="' + W + '" height="' + H + '" viewBox="0 0 ' + W + ' ' + H + '">' + out + '</svg>';
    }

    function drawOverview(g, e, q, col){
        var S = 230, c = S / 2, rr = g.dRoot / 2, rt = g.dTip / 2, Ro = rr * 1.1, s = (c - 6) / Ro, N = 5, i, k, pts = [], step = 2 * Math.PI / g.z;
        function PX(r, ph){ return (c + r * Math.cos(ph) * s).toFixed(1) + ',' + (c - r * Math.sin(ph) * s).toFixed(1); }
        function ps(r){ return Math.max(0, psiAt(g, e, r)); }
        for (k = 0; k < g.z; k++) {
            var pc = k * step, r;
            for (i = 0; i <= N; i++) { r = rt + (rr - rt) * i / N; pts.push(PX(r, pc - ps(r))); }
            for (i = N; i >= 0; i--) { r = rt + (rr - rt) * i / N; pts.push(PX(r, pc + ps(r))); }
            var a1 = pc + ps(rt), a2 = pc + step - ps(rt);
            pts.push(PX(rt, a1 + (a2 - a1) / 3)); pts.push(PX(rt, a1 + 2 * (a2 - a1) / 3));
        }
        var out = '<circle cx="' + c + '" cy="' + c + '" r="' + (Ro * s).toFixed(1) + '" fill="#F7E0BD" stroke="#b58b5a"/>';
        out += '<polygon points="' + pts.join(' ') + '" fill="#fff" stroke="#6b4423" stroke-width="0.8"/>';
        out += '<circle cx="' + c + '" cy="' + c + '" r="' + (g.d / 2 * s).toFixed(1) + '" fill="none" stroke="#b58b5a" stroke-dasharray="3 3"/>';
        if (q) {
            var ph2 = Math.floor(g.z / 2) * step, i2;
            var A = [q.rp * s, 0], B = [q.rp * Math.cos(ph2) * s, q.rp * Math.sin(ph2) * s];
            out += '<line x1="' + (c + A[0]).toFixed(1) + '" y1="' + c + '" x2="' + (c + B[0]).toFixed(1) + '" y2="' + (c - B[1]).toFixed(1) + '" stroke="#DD5138" stroke-dasharray="4 3"/>';
            var cs = [[A[0], 0], [B[0], B[1]]];
            for (i2 = 0; i2 < 2; i2++) {
                out += '<circle cx="' + (c + cs[i2][0]).toFixed(1) + '" cy="' + (c - cs[i2][1]).toFixed(1) + '" r="' + Math.max(2.5, q.D / 2 * s).toFixed(1) + '" fill="' + col + '" stroke="#5a3a12"/>';
            }
            out += '<text x="' + (c - 24) + '" y="' + (c - 6) + '" font-size="10.5" fill="#DD5138">M ' + f(q.M, 3) + '</text>';
        }
        $('ig-over').innerHTML = '<svg width="' + S + '" height="' + S + '" viewBox="0 0 ' + S + ' ' + S + '">' + out + '</svg>';
    }

    function clearGraphics(){ $('ig-zoom').innerHTML = ''; $('ig-over').innerHTML = ''; }
    function colOf(j){ return j.lv === 0 ? '#F0A24B' : (j.lv === 1 ? '#E67E22' : '#DD5138'); }

    /* ───── 兩個分頁的計算 ───── */
    function calcA(g){
        var x = pv('ig-x'), D = pv('ig-d'), res = $('ig-res');
        bad('ig-x', x === null); bad('ig-d', false);
        if (x === null) { res.innerHTML = '<span class="ig-err">請輸入轉位係數 x（沒有轉位填 0）</span>'; clearGraphics(); return; }
        var e = eOf(g, x), Ds = stdPin(g, e), h = '';
        h += '<table class="ig-t">' + row('分度圓齒槽寬 e', f(e, 5) + ' mm') + row('標準跨銷徑（接觸點在分度圓）', f(Ds, 4) + ' mm') + rangeRows(g, e) + '</table>';
        if (D === null || D <= 0) {
            res.innerHTML = h + '<div class="ig-err">請輸入銷徑，或按「帶入標準跨銷徑」</div>';
            if (Ds) { var qs = pinAt(g, e, Ds); drawZoom(g, e, [{ r: qs, col: '#F0A24B', lbl: '標準銷 Ø' + f(Ds, 3) }]); drawOverview(g, e, qs, '#F0A24B'); } else clearGraphics();
            return;
        }
        var r = pinAt(g, e, D);
        if (!r) { bad('ig-d', true); res.innerHTML = h + '<div class="ig-err">此銷徑太大，銷放不進齒槽（算不出解）</div>'; clearGraphics(); return; }
        var j = judge(g, r), col = colOf(j);
        h += '<table class="ig-t">' + row('跨銷距 M（銷與銷之間）', f(r.M, 4) + ' mm', 'ig-big') +
             row('銷中心圓徑', f(2 * r.rp, 4) + ' mm') +
             row('接觸點直徑', f(2 * r.rk, 4) + ' mm') +
             row('接觸點壓力角', f(r.ak * 180 / Math.PI, 3) + ' °') +
             row('有效齒面範圍', 'Ø' + f(g.dTip, 2) + ' ～ Ø' + f(g.dRoot, 2)) +
             row('齒數類型', g.z % 2 === 0 ? '偶數齒（銷在正對面）' : '奇數齒（兩銷差半個齒距）') + '</table>' + badge(j);
        res.innerHTML = h;
        drawZoom(g, e, [{ r: r, col: col, lbl: '銷 Ø' + f(D, 3) }]);
        drawOverview(g, e, r, col);
    }

    function calcB(g){
        var D1 = pv('ig-d1'), M1 = pv('ig-m1'), ut = pv('ig-ut') || 0, lt = pv('ig-lt') || 0, D2 = pv('ig-d2'), res = $('ig-res');
        bad('ig-d1', false); bad('ig-m1', false); bad('ig-d2', false);
        if (D1 === null || D1 <= 0 || M1 === null) { res.innerHTML = '<span class="ig-err">請輸入客戶銷徑與客戶跨銷距</span>'; clearGraphics(); return; }
        var e = solveE(g, M1, D1);
        if (e === null) { bad('ig-m1', true); res.innerHTML = '<span class="ig-err">用這組齒輪資料與客戶銷徑，反推不出合理的齒槽寬，請確認模數、齒數、壓力角與客戶跨銷距</span>'; clearGraphics(); return; }
        var h = '<table class="ig-t">' + row('反推轉位係數 x', f(xOf(g, e), 6)) + row('反推分度圓齒槽寬 e', f(e, 5) + ' mm') +
                row('此齒輪標準跨銷徑', f(stdPin(g, e), 4) + ' mm') + rangeRows(g, e) + '</table>';
        var r1 = pinAt(g, e, D1), j1 = r1 ? judge(g, r1) : null;
        if (j1) h += '<div style="font-size:11px;color:#6b4423;margin-top:4px">客戶銷 Ø' + f(D1, 4) + '：' + j1.txt + '</div>';
        if (D2 === null || D2 <= 0) {
            res.innerHTML = h + '<div class="ig-err">請輸入我方銷徑 D2</div>';
            if (r1) { drawZoom(g, e, [{ r: r1, col: colOf(j1), dash: false, lbl: '客戶銷 Ø' + f(D1, 3) }]); drawOverview(g, e, r1, colOf(j1)); } else clearGraphics();
            return;
        }
        var r2 = pinAt(g, e, D2);
        if (!r2) { bad('ig-d2', true); res.innerHTML = h + '<div class="ig-err">我方銷徑太大，銷放不進齒槽</div>'; clearGraphics(); return; }
        var eU = solveE(g, M1 + ut, D1), eL = solveE(g, M1 + lt, D1), rU = eU === null ? null : pinAt(g, eU, D2), rL = eL === null ? null : pinAt(g, eL, D2);
        var lo = rL ? rL.M : null, hi = rU ? rU.M : null;
        if (lo !== null && hi !== null && lo > hi) { var tmp = lo; lo = hi; hi = tmp; }
        var worst = judge(g, r2), cand = [rL, rU], i, jj;
        for (i = 0; i < cand.length; i++) { if (cand[i]) { jj = judge(g, cand[i]); if (jj.lv > worst.lv) worst = jj; } }
        h += '<table class="ig-t">' + row('我方跨銷距 M2（公稱）', f(r2.M, 4) + ' mm', 'ig-big') +
             row('換算後區間', (lo === null ? '—' : f(lo, 3)) + ' ～ ' + (hi === null ? '—' : f(hi, 3))) +
             row('公差帶寬（客戶→我方）', f(ut - lt, 3) + ' → ' + (lo !== null && hi !== null ? f(hi - lo, 3) : '—')) +
             row('銷徑差 / 跨銷距差', f(D2 - D1, 4) + ' / ' + f(r2.M - M1, 4)) +
             row('接觸點直徑（我方銷）', f(2 * r2.rk, 4) + ' mm') +
             row('有效齒面範圍', 'Ø' + f(g.dTip, 2) + ' ～ Ø' + f(g.dRoot, 2)) + '</table>' +
             '<div style="font-size:11px;color:#6b4423;margin-top:4px">我方銷 Ø' + f(D2, 4) + '（含公差上下限兩端一起判定）：</div>' + badge(worst);
        res.innerHTML = h;
        var pins = [{ r: r2, col: colOf(worst), dash: false, lbl: '我方 Ø' + f(D2, 3) }];
        if (r1) pins.push({ r: r1, col: '#8c8c8c', dash: true, lbl: '客戶 Ø' + f(D1, 3) });
        drawZoom(g, e, pins);
        drawOverview(g, e, r2, colOf(worst));
    }

    function calc(){
        if (!domInited) return;
        var g = readGear();
        if (!g) { $('ig-res').innerHTML = ''; clearGraphics(); return; }
        if (tab === 'a') calcA(g); else calcB(g);
    }

    /* ───── 對外函式 ───── */
    window.inGearUseStd = function(){
        var g = readGear(), x = pv('ig-x'); if (!g || x === null) return;
        var D = stdPin(g, eOf(g, x)); if (D) { $('ig-d').value = (+D.toFixed(4)).toString(); calc(); }
    };
    window.inGearUseStd2 = function(){
        var g = readGear(), D1 = pv('ig-d1'), M1 = pv('ig-m1'); if (!g || D1 === null || M1 === null) return;
        var e = solveE(g, M1, D1); if (e === null) return;
        var D = stdPin(g, e); if (D) { $('ig-d2').value = (+D.toFixed(4)).toString(); calc(); }
    };
    window.switchInGearTab = function(t){
        tab = t;
        $('ig-pane-a').style.display = t === 'a' ? '' : 'none';
        $('ig-pane-b').style.display = t === 'b' ? '' : 'none';
        $('ig-tab-a').className = t === 'a' ? 'on' : '';
        $('ig-tab-b').className = t === 'b' ? 'on' : '';
        calc();
    };
    window.clearInGearTool = function(){
        ['ig-m', 'ig-z', 'ig-d', 'ig-d1', 'ig-m1', 'ig-d2', 'ig-dtip', 'ig-droot'].forEach(function(id){ var el = $(id); if (el) el.value = ''; });
        $('ig-al').value = '20'; $('ig-x').value = '0'; $('ig-ut').value = '0'; $('ig-lt').value = '0';
        calc();
        var mEl = $('ig-m'); if (mEl) mEl.focus();
    };
    window.closeInGearTool = function(){ var w = $('ig-win'); if (w) w.style.display = 'none'; };
    window.openInGearTool = function(){
        ensureDom();
        var w = $('ig-win'); if (w) w.style.display = 'flex';
        calc();
        setTimeout(function(){ var el = $('ig-m'); if (el) el.focus(); }, 60);
    };

    function initDrag(){
        var hdr = $('ig-hdr'), win = $('ig-win'), sx, sy, sl, st;
        if (!hdr || !win) return;
        hdr.addEventListener('mousedown', function(e){
            if (e.target.tagName === 'BUTTON') return;
            sx = e.clientX; sy = e.clientY;
            sl = parseInt(win.style.left) || win.getBoundingClientRect().left;
            st = parseInt(win.style.top) || win.getBoundingClientRect().top;
            win.style.transform = 'none'; win.style.left = sl + 'px'; win.style.top = st + 'px';
            document.addEventListener('mousemove', mv); document.addEventListener('mouseup', up);
            e.preventDefault();
        });
        function mv(e){ win.style.left = (sl + e.clientX - sx) + 'px'; win.style.top = (st + e.clientY - sy) + 'px'; }
        function up(){ document.removeEventListener('mousemove', mv); document.removeEventListener('mouseup', up); }
    }

    function ensureDom(){
        if (domInited) return;
        var tpl = $('ig-tpl'), cont = $('ig-container');
        if (tpl && cont) { cont.appendChild(document.importNode(tpl.content, true)); if (tpl.parentNode) tpl.parentNode.removeChild(tpl); }
        domInited = true;
        initDrag();
        var win = $('ig-win');
        win.addEventListener('input', calc);
        // Enter 跳下一個可見欄位；聚焦時全選（只綁在本視窗上，不碰 document）
        win.addEventListener('keydown', function(e){
            if (e.key !== 'Enter' || !e.target.classList || !e.target.classList.contains('ig-in')) return;
            var all = Array.prototype.filter.call(win.querySelectorAll('input.ig-in'), function(el){ return el.offsetParent !== null; });
            var i = all.indexOf(e.target); if (i >= 0 && i + 1 < all.length) { all[i + 1].focus(); e.preventDefault(); }
        });
        win.addEventListener('focusin', function(e){
            var el = e.target;
            if (el.classList && el.classList.contains('ig-in')) setTimeout(function(){ if (document.activeElement === el) { try { el.select(); } catch (x) {} } }, 0);
        });
    }
})();
</script>
<?php endif; ?>
