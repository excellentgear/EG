/**
 * eg_doc_sign_stamp.js — 把制修訂紀錄書上的簽章格畫成印章
 *
 * 為什麼要獨立一支：編輯器（as_doc_editor.php）與列印版（as_doc_print.php）
 * 用的是**同一份**由後端產生的版面（as_doc_tpl_lib），兩邊各寫一次畫章的程式
 * 遲早會畫出不一樣的章（ai-rules/18＋鐵律4）。
 *
 * 後端只輸出「要蓋誰的章」：
 *   <td data-sign="approve" data-stamp-name="王小明" data-stamp-dept="管理課"
 *       data-stamp-pos="課長" data-stamp-date="2026.09.22" data-stamp-more="1">
 * 這裡負責叫 EGStamp 畫出來。沒有 data-stamp-name 的格子＝還沒簽，留空給手簽。
 */
(function (w, d) {
    'use strict';

    function draw(td) {
        var name = td.getAttribute('data-stamp-name') || '';
        if (!name) return;
        if (td.getAttribute('data-stamped') === '1') return;   // 重畫時不要疊第二個章
        var date = td.getAttribute('data-stamp-date') || '';
        var dept = td.getAttribute('data-stamp-dept') || '';
        var pos  = td.getAttribute('data-stamp-pos') || '';
        var more = parseInt(td.getAttribute('data-stamp-more') || '0', 10);
        var html = '';
        try {
            // 第 3 個參數是「代理人代簽」；本流程的簽核人是送審當下就指定好的本人，固定 false
            html = w.EGStamp.stamp(name, date, false, null, dept, pos);
        } catch (e) { return; }
        if (more > 0) {
            html += '<div style="font-size:10px;color:#8A5A2B;line-height:1.4">另 ' + more + ' 人已簽</div>';
        }
        td.innerHTML = html;
        td.setAttribute('data-stamped', '1');
    }

    /** 把 root（預設整頁）底下所有簽章格畫出來 */
    function paint(root) {
        if (!w.EGStamp) return;
        var host = root || d;
        var tds = host.querySelectorAll ? host.querySelectorAll('td[data-sign][data-stamp-name]') : [];
        Array.prototype.slice.call(tds).forEach(draw);
    }

    /**
     * 一定要等掃描實體章的對照表載完才畫（eg_stamp.js 的 whenReady）：
     * 有實體章的人如果沒等，會先畫成預設的回墨印，跟別處看到的章不一樣。
     */
    function paintWhenReady(root, cb) {
        function done() { if (typeof cb === 'function') cb(); }
        if (!w.EGStamp) { done(); return; }
        if (typeof w.EGStamp.whenReady === 'function') {
            w.EGStamp.whenReady(function () { paint(root); done(); });
        } else { paint(root); done(); }
    }

    w.egDocStamps = paintWhenReady;
    w.egDocStampsNow = paint;
})(window, document);
