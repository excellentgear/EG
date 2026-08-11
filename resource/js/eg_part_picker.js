/*!
 * eg_part_picker.js — 料號模糊搜尋選擇器（全站唯一實作，2026-08-11）
 *
 * 為什麼要有這支：多個表單（型態識別文件管制表／產品開發評估表／PFMEA…）都要求「料號欄位打部分
 * 字元篩選、選定後自動帶出客戶、可點擊開圖面」。料號主檔筆數大（3000+），不能比照 eg_asdoc_picker.js
 * 整批載入前端篩選，改成打字時 AJAX 向 PartPicker_API.php 查詢（防抖 250ms）。
 *
 * 用法：
 *   <script src="../../resource/js/eg_part_picker.js?v=..."></script>
 *   EGPartPicker.open({
 *     apiUrl : '../../src/store/PartPicker_API.php',   // 依頁面深度調整相對路徑
 *     title  : '選擇料號',                              // 可省略
 *     onSave : function(row){ ... }  // row = {d_id, part_no, drawing_no, customer_id, customer_name}
 *   });
 *   EGPartPicker.viewerLink(dId, viewerUrl)  // 回傳可點擊開 part_viewer.php 的 <a> HTML
 *     viewerUrl 例：'../pm/part_viewer.php'（依頁面深度調整）
 */
(function () {
    'use strict';

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c];
        });
    }

    function injectCss() {
        if (document.getElementById('eg-partpick-css')) return;
        var st = document.createElement('style');
        st.id = 'eg-partpick-css';
        st.appendChild(document.createTextNode(
            '.eg-pp-mask{position:fixed;inset:0;background:rgba(60,40,20,.45);z-index:12000;display:block;}'
          + '.eg-pp-box{background:#fff;border-radius:8px;max-width:640px;margin:60px auto;'
          + 'box-shadow:0 5px 25px rgba(0,0,0,.3);display:flex;flex-direction:column;max-height:80vh;position:relative;}'
          + '.eg-pp-hd{background:#F7E0BD;color:#5b3a1e;font-weight:bold;padding:10px 15px;'
          + 'border-radius:8px 8px 0 0;display:flex;justify-content:space-between;}'
          + '.eg-pp-hd .eg-pp-close{cursor:pointer;color:#b5762a;}'
          + '.eg-pp-bd{padding:14px 15px;overflow-y:auto;}'
          + '.eg-pp-ft{padding:10px 15px;border-top:1px solid #EADFC8;text-align:right;}'
          + '.eg-pp-kw{width:100%;padding:6px 9px;font-size:13px;border:1px solid #D8BE93;'
          + 'border-radius:4px;background:#FFFDF8;color:#5b3a1e;margin-bottom:8px;box-sizing:border-box;}'
          + '.eg-pp-lst{width:100%;border:1px solid #D8BE93;border-radius:4px;max-height:280px;overflow-y:auto;}'
          + '.eg-pp-item{padding:6px 9px;font-size:13px;color:#5b3a1e;cursor:pointer;border-bottom:1px solid #F3E9D6;}'
          + '.eg-pp-item:last-child{border-bottom:none;}'
          + '.eg-pp-item:hover,.eg-pp-item.sel{background:#F7E0BD;}'
          + '.eg-pp-item .pn{font-weight:bold;}'
          + '.eg-pp-item .cu{color:#8a6d45;margin-left:8px;font-size:12px;}'
          + '.eg-pp-cnt{font-size:12px;color:#8a6d45;margin-top:5px;}'
          + '.eg-pp-ft button{height:30px;padding:0 16px;border-radius:4px;font-size:13px;cursor:pointer;}'
          + '.eg-pp-btn-no{border:1px solid #D8BE93;background:#fff;color:#5b3a1e;}'));
        (document.head || document.documentElement).appendChild(st);
    }

    var API = {
        /** 顯示用連結：點擊開新視窗看料號圖面（比照 inspection_entry_v2.php 既有作法） */
        viewerLink: function (dId, viewerUrl, label) {
            if (!dId) return esc(label || '');
            var url = viewerUrl + '?d_id=' + encodeURIComponent(dId);
            return '<a href="javascript:void(0)" class="eg-pp-viewer-lnk" data-d-id="' + esc(dId)
                 + '" data-viewer="' + esc(viewerUrl) + '" style="color:#b5762a;text-decoration:underline;">'
                 + esc(label || dId) + '</a>';
        },

        openViewer: function (dId, viewerUrl) {
            if (!dId) return;
            var w = screen.availWidth, h = screen.availHeight;
            var pw = Math.min(1400, Math.round(w * 0.85)), ph = Math.min(900, Math.round(h * 0.88));
            window.open(viewerUrl + '?d_id=' + encodeURIComponent(dId), 'part_dv_' + dId,
                'width=' + pw + ',height=' + ph + ',left=' + Math.round((w - pw) / 2) + ',top=' + Math.round((h - ph) / 2) + ',resizable=yes,scrollbars=yes');
        },

        open: function (opt) {
            opt = opt || {};
            var apiUrl = opt.apiUrl;
            injectCss();

            var stale = document.querySelectorAll('.eg-pp-mask');
            for (var i = 0; i < stale.length; i++) if (stale[i].parentNode) stale[i].parentNode.removeChild(stale[i]);

            var mask = document.createElement('div');
            mask.className = 'eg-pp-mask';
            mask.innerHTML =
                '<div class="eg-pp-box">'
              + '<div class="eg-pp-hd"><span>' + esc(opt.title || '選擇料號') + '</span>'
              + '<span class="eg-pp-close">✕</span></div>'
              + '<div class="eg-pp-bd">'
              + '<input type="text" class="eg-pp-kw" data-eg-skip="1" placeholder="輸入部分料號或圖號即可搜尋（至少1字）">'
              + '<div class="eg-pp-lst"></div>'
              + '<div class="eg-pp-cnt"></div>'
              + '</div>'
              + '<div class="eg-pp-ft"><button type="button" class="eg-pp-btn-no">取消</button></div>'
              + '</div>';

            var hostModal = document.querySelector('.modal.in, .modal.show');
            (hostModal || document.body).appendChild(mask);

            var kw = mask.querySelector('.eg-pp-kw'),
                lst = mask.querySelector('.eg-pp-lst'),
                cnt = mask.querySelector('.eg-pp-cnt'),
                rows = [], selIdx = -1, timer = null;

            function renderList() {
                if (!rows.length) {
                    lst.innerHTML = '';
                    cnt.textContent = kw.value.trim() ? '查無符合的料號' : '請輸入關鍵字開始搜尋';
                    return;
                }
                var html = '';
                rows.forEach(function (r, idx) {
                    html += '<div class="eg-pp-item' + (idx === selIdx ? ' sel' : '') + '" data-idx="' + idx + '">'
                          + '<span class="pn">' + esc(r.part_no) + '</span>'
                          + (r.drawing_no ? '<span class="cu">圖號 ' + esc(r.drawing_no) + '</span>' : '')
                          + '<span class="cu">' + esc(r.customer_name || r.customer_id || '（無客戶）') + '</span>'
                          + '</div>';
                });
                lst.innerHTML = html;
                cnt.textContent = '符合 ' + rows.length + ' 筆（上限30筆，請縮小關鍵字範圍）';
            }

            function doSearch() {
                var q = kw.value.trim();
                if (!q) { rows = []; selIdx = -1; renderList(); return; }
                fetch(apiUrl + '?action=search&q=' + encodeURIComponent(q), {credentials: 'same-origin'})
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        rows = (d && d.success) ? (d.rows || []) : [];
                        selIdx = rows.length ? 0 : -1;
                        renderList();
                    })
                    .catch(function () { rows = []; renderList(); cnt.textContent = '搜尋失敗，請稍後再試'; });
            }

            function close() {
                document.removeEventListener('keydown', esckey);
                if (mask.parentNode) mask.parentNode.removeChild(mask);
            }
            function pick(idx) {
                var row = rows[idx];
                if (!row) return;
                close();
                if (typeof opt.onSave === 'function') opt.onSave(row);
            }
            function esckey(e) { if (e.key === 'Escape') close(); }

            kw.addEventListener('input', function () {
                clearTimeout(timer);
                timer = setTimeout(doSearch, 250);
            });
            kw.addEventListener('keydown', function (e) {
                if (e.key === 'ArrowDown') { e.preventDefault(); if (selIdx < rows.length - 1) selIdx++; renderList(); }
                else if (e.key === 'ArrowUp') { e.preventDefault(); if (selIdx > 0) selIdx--; renderList(); }
                else if (e.key === 'Enter') { e.preventDefault(); if (selIdx >= 0) pick(selIdx); }
            });
            lst.addEventListener('click', function (e) {
                var item = e.target.closest('.eg-pp-item');
                if (item) pick(parseInt(item.getAttribute('data-idx'), 10));
            });
            mask.querySelector('.eg-pp-btn-no').addEventListener('click', close);
            mask.querySelector('.eg-pp-close').addEventListener('click', close);
            mask.addEventListener('click', function (e) { if (e.target === mask) close(); });
            document.addEventListener('keydown', esckey);
            renderList();
            setTimeout(function () { kw.focus(); }, 30);
        }
    };

    // 全站委派：任何頁面只要點了 .eg-pp-viewer-lnk 就開圖面檢視窗（不需各頁自己綁）
    document.addEventListener('click', function (e) {
        var a = e.target.closest && e.target.closest('.eg-pp-viewer-lnk');
        if (!a) return;
        e.preventDefault();
        API.openViewer(a.getAttribute('data-d-id'), a.getAttribute('data-viewer'));
    });

    window.EGPartPicker = API;
})();
