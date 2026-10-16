/**
 * eg_doc_fit.js — AS 文件頁首「文字太長就自動縮小」的唯一實作
 *
 * 為什麼需要：頁首那張表是**固定四欄格線**（16%/42%/16%/26%），
 * 文件名稱長一點、或管理員把頁首字級調大，文字就會換行把整張表撐高，
 * 每一頁的可用高度跟著變、換頁位置就跑掉（使用者 2026-10-16：
 * 「頁首的文件編號／文件名稱／頁次／頁版別 這些可以設定文字大小嗎？過大自動縮小」）。
 *
 * 做法：每一格的文字包在 <span class="adt-fit"> 裡，span 是 nowrap 的行內區塊，
 * 所以 scrollWidth ＝「這段字排成一行要多寬」。比可用寬度寬就把字級一級一級調小，
 * 調到下限（預設 6pt）還塞不下才放行換行——**寧可換行也不要把字縮到看不清楚**。
 *
 * ⚠ 一定要先把上一次縮過的字級清掉再量：不清的話每呼叫一次就在前一次的結果上
 *   再縮一次，改個設定按幾下預覽，字就愈縮愈小而且回不來。
 *
 * 用法：EGDocFit.run(root)（root 省略＝整份文件）。
 *   版面重畫、改公版設定預覽、列印前都要再呼叫一次。
 */
(function (w, d) {
    'use strict';

    var MIN_PX = 8;        // 6pt ≒ 8px，再小就看不清楚了
    var STEP = 0.5;

    function fitOne(sp) {
        var td = sp.parentNode;
        if (!td) return;
        // 先還原，否則會在上一次的結果上繼續縮（見檔頭說明）
        sp.style.fontSize = '';
        sp.classList.remove('adt-fit-wrap');
        var avail = td.clientWidth
                  - parseFloat(w.getComputedStyle(td).paddingLeft || 0)
                  - parseFloat(w.getComputedStyle(td).paddingRight || 0);
        if (!(avail > 0)) return;
        if (sp.scrollWidth <= avail + 0.5) return;      // 本來就放得下

        var size = parseFloat(w.getComputedStyle(sp).fontSize) || 12;
        for (var i = 0; i < 40 && size - STEP >= MIN_PX; i++) {
            size -= STEP;
            sp.style.fontSize = size + 'px';
            if (sp.scrollWidth <= avail + 0.5) return;
        }
        // 縮到下限還是放不下＝讓它換行（這種多半是超長的文件名稱）
        sp.classList.add('adt-fit-wrap');
    }

    function run(root) {
        var r = root || d;
        var list = r.querySelectorAll ? r.querySelectorAll('.adt-fit') : [];
        for (var i = 0; i < list.length; i++) fitOne(list[i]);
        return list.length;
    }

    /** 字型還沒載完就量，量到的是備用字型的寬度，縮放會算錯 */
    function runWhenReady(root, cb) {
        function go() { var n = run(root); if (cb) cb(n); }
        if (d.fonts && d.fonts.ready && d.fonts.ready.then) {
            d.fonts.ready.then(go, go);
            setTimeout(go, 1200);      // 保險：字型一直回不來也不要永遠不縮
        } else {
            go();
        }
    }

    w.EGDocFit = { run: run, runWhenReady: runWhenReady };
})(window, document);
