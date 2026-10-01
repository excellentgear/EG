/**
 * eg_gear_calc.js — 齒輪基礎幾何計算（全站唯一實作）
 * 建立：2026-10-01
 *
 * 由 views/Sales/_gear_tool_ui.php 的齒輪計算工具抽出來，公式**一字未改**：
 *     mt = mn / cos β          端面模數
 *     d  = mt × z              節圓直徑
 *     da = d + 2·mn·(1 + x)    外徑
 *     df = d − 2·mn·(hf − x)   齒根徑（根徑），hf 預設 1.25
 *     h  = (da − df) / 2       齒高
 *     k  = round(z·αn/180 + 0.5)   建議跨齒數
 *
 * **為什麼要抽出來**：標準作業流程 SOP 的「工件規格」也要自動算根徑，
 * 而齒輪計算工具已經有一份正確的公式。再抄一份就是兩份會各自演進（鐵律4）
 * ——齒輪的齒冠／齒根係數是會依標準調整的，兩邊算出不同的根徑而且不會有人發現。
 *
 * 用法：
 *     var r = EGGear.basic({ mn: 4.5, z: 79, alpha_n: 20, beta: 0, x: 0 });
 *     r.df  // → 348.75
 *   算不出來（必填的沒填、或數值不合理）時回 null，呼叫端一律要判 null，
 *   **不可以拿 0 或 NaN 當成計算結果填進欄位**。
 */
(function (root) {
    'use strict';

    function num(v) {
        if (v === null || v === undefined) return null;
        var s = String(v).trim();
        if (s === '') return null;
        // 現場常打「20°」「4.5mm」這種帶單位的字，抓得出數字就用
        var m = s.match(/-?\d+(\.\d+)?/);
        if (!m) return null;
        var n = parseFloat(m[0]);
        return isFinite(n) ? n : null;
    }

    /**
     * 法向模數也可能是 DP／CP 制（紙本上寫 DP14、CP5）。
     * 回傳換算成公制法向模數的數值；認不出來就回 null。
     */
    function toModule(v) {
        if (v === null || v === undefined) return null;
        var s = String(v).trim().toUpperCase().replace(/\s+/g, '');
        var m;
        if ((m = s.match(/^DP(-?\d+(\.\d+)?)$/))) {           // 徑節：mn = 25.4 / DP
            var dp = parseFloat(m[1]);
            return (isFinite(dp) && dp > 0) ? 25.4 / dp : null;
        }
        if ((m = s.match(/^CP(-?\d+(\.\d+)?)$/))) {           // 周節：mn = CP / π
            var cp = parseFloat(m[1]);
            return (isFinite(cp) && cp > 0) ? cp / Math.PI : null;
        }
        return num(s);
    }

    /** 小數位四捨五入（與齒輪工具的 gRound 同一種寫法） */
    function round(v, n) {
        if (v === null || !isFinite(v)) return null;
        var p = Math.pow(10, n === undefined ? 4 : n);
        return Math.round(v * p) / p;
    }

    /**
     * 基礎幾何。
     * @param {Object} o  mn 法向模數（可寫 DP14／CP5）、z 齒數、alpha_n 法向壓力角（度，預設 20）、
     *                    beta 螺旋角（度，預設 0）、x 轉位係數（預設 0）、hf 齒根係數（預設 1.25）
     * @returns {Object|null} { mt, d, da, df, h, k }
     */
    function basic(o) {
        o = o || {};
        var mn = toModule(o.mn);
        var z  = num(o.z);
        if (mn === null || z === null || mn <= 0 || z <= 0) return null;

        var an = num(o.alpha_n); if (an === null) an = 20;
        var bd = num(o.beta);    if (bd === null) bd = 0;
        var x  = num(o.x);       if (x  === null) x  = 0;
        var hf = num(o.hf);      if (hf === null) hf = 1.25;
        // 角度不合理就不要硬算——算出來的根徑會是假的
        if (an <= 0 || an >= 90) return null;
        if (bd <= -90 || bd >= 90) return null;

        var beta  = bd * Math.PI / 180;
        var cos_b = Math.cos(beta);
        if (!isFinite(cos_b) || Math.abs(cos_b) < 1e-9) return null;

        var mt = mn / cos_b;
        var d  = mt * z;
        var da = d + 2 * mn * (1 + x);
        var df = d - 2 * mn * (hf - x);
        var h  = (da - df) / 2;
        var k  = Math.max(1, Math.round(z * an / 180 + 0.5));

        return { mt: round(mt), d: round(d), da: round(da), df: round(df), h: round(h), k: k };
    }

    /** 只要根徑（SOP 的「工件規格」用；算不出來回 null） */
    function rootDia(o) {
        var r = basic(o);
        return r ? r.df : null;
    }

    /**
     * 由圖面上的**外徑**回推轉位係數 x（齒輪工具的「回推 x」同一條式子反解）：
     *     da = d + 2·mn·(1 + x)  →  x = (da − d) / (2·mn) − 1
     * 現場的圖面常常只給外徑不給轉位係數，回推出來才算得出根徑。
     * 算不出來或結果離譜（|x| > 2，多半是模數／齒數填錯）一律回 null，不要硬給一個數字。
     */
    function solveX(o) {
        o = o || {};
        var mn = toModule(o.mn), z = num(o.z), da = num(o.da);
        if (mn === null || z === null || da === null || mn <= 0 || z <= 0) return null;
        var bd = num(o.beta); if (bd === null) bd = 0;
        if (bd <= -90 || bd >= 90) return null;
        var cos_b = Math.cos(bd * Math.PI / 180);
        if (!isFinite(cos_b) || Math.abs(cos_b) < 1e-9) return null;
        var d = (mn / cos_b) * z;
        var x = (da - d) / (2 * mn) - 1;
        if (!isFinite(x) || Math.abs(x) > 2) return null;
        return round(x, 4);
    }

    root.EGGear = { basic: basic, rootDia: rootDia, solveX: solveX, toModule: toModule, num: num, round: round };
})(window);
