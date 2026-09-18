/**
 * eg_chart_legend_fix.js — 修掉「Chart.js 圖表整區空白」的全站共用補丁（2026-09-18 建立）
 *
 * 症狀：頁面有資料、KPI 數字也出得來，但**圖表與它下面的表格整區空白**，畫面上沒有任何錯誤訊息，
 *       只有 F12 主控台看得到 `TypeError: Cannot read properties of undefined (reading 'call')`
 *       （堆疊在 Chart.min.js 的 buildLabels）。
 *
 * 根因：版型檔 `resource/js/custom.min.js`（Gentelella 自帶）在 `$(document).ready` 裡執行
 *       `Chart.defaults.global.legend = {enabled:false}`。這一句有兩個錯：
 *         ① Chart.js v2 關閉圖例的鍵是 `display`，不是 `enabled`（所以它根本沒關成）
 *         ② 它是**整個物件取代**而不是改欄位，於是原廠預設裡的
 *            `labels.generateLabels`／`onClick`／`onHover` 一起不見了
 *       → 任何「有圖例」的圖表一建立就在 buildLabels 丟例外；**例外在建圖當下丟出，
 *         呼叫端後面的程式（其他圖表、表格渲染）全部不會執行**，所以是整區空白而不是只有一張圖壞掉。
 *
 * 為什麼不直接改 custom.min.js：那是全站每一頁都載入的版型檔，改它等於動到所有頁面；
 * 這裡改成「把原廠預設留一份、畫圖前檢查有沒有被洗掉、被洗掉才還原」，不影響任何既有頁面。
 *
 * 用法（兩行）：
 *   1. 在 `Chart.min.js` **之後**載入本檔（一定要在 document.ready 之前，也就是放在 <script src> 區，
 *      這樣才來得及在 custom.min.js 動手之前把原廠預設抄走）
 *   2. 建圖之前呼叫一次 `egChartLegendReady()`（建議直接寫在自己的 mkChart 之類的共用建圖函式第一行）
 *
 * 已使用：views/ADM/leave_request.php（請假統計）
 */
(function () {
    if (typeof Chart === 'undefined' || !Chart.defaults || !Chart.defaults.global) return;
    var def = Chart.defaults.global.legend;
    // 只在「還沒被洗掉」時才抄；抄的是參考即可（custom.min.js 是整個取代，不是改欄位，
    // 所以原本那個物件不會被動到），但仍複製一層避免別處 mutate 到同一個物件。
    if (!def || !def.labels || typeof def.labels.generateLabels !== 'function') return;
    var copy = {};
    for (var k in def) if (Object.prototype.hasOwnProperty.call(def, k)) copy[k] = def[k];
    copy.labels = {};
    for (var k2 in def.labels) if (Object.prototype.hasOwnProperty.call(def.labels, k2)) copy.labels[k2] = def.labels[k2];
    window.__egChartLegendDefault = copy;
})();

/** 建圖之前呼叫：圖例預設被洗掉就還原（沒被洗掉時什麼都不做） */
function egChartLegendReady() {
    if (typeof Chart === 'undefined' || !window.__egChartLegendDefault) return;
    var cur = Chart.defaults.global.legend;
    if (!cur || !cur.labels || typeof cur.labels.generateLabels !== 'function') {
        Chart.defaults.global.legend = window.__egChartLegendDefault;
    }
}
