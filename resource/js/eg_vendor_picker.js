/*!
 * eg_vendor_picker.js — 廠商模糊搜尋選擇器（全站唯一實作，2026-08-26）
 *
 * 為什麼要有這支：多個表單要填「廠商」，廠商主檔 maker_list 有 900 筆左右，純下拉沒人找得到
 * （UI 規則：超過約 10 筆一律要能打字篩選）。現場記得的可能是廠商代號、簡稱或全名，三個都要能比對。
 * 後端唯一端點：src/store/VendorPicker_API.php（action=search / get_one）。
 *
 * 用法（就地打字篩選，欄位下方即時長出清單，點選或方向鍵＋Enter 完成選取）：
 *   <script src="../../resource/js/eg_vendor_picker.js?v=..."></script>
 *   EGVendorPicker.attach(document.getElementById('svc-vendor'), {
 *       apiUrl  : '../../src/store/VendorPicker_API.php',   // 依頁面深度調整相對路徑
 *       onSelect: function(row){ hiddenIdInput.value = row.maker_id_no; },
 *       onInput : function(){ hiddenIdInput.value = ''; }   // 使用者自行改字＝不再對應主檔那一筆
 *   });
 *
 * 使用者若打完字沒從清單點選就離開欄位，內容視為「自由輸入的廠商名稱」（不會有 maker_id_no）——
 * 這是刻意的：臨時的維修廠商不一定建過主檔，不該因此擋住存檔。呼叫端若一定要有主檔對應，請自行驗證。
 */
(function () {
    'use strict';

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c];
        });
    }

    function injectCss() {
        if (document.getElementById('eg-vp-css')) return;
        var st = document.createElement('style');
        st.id = 'eg-vp-css';
        st.appendChild(document.createTextNode(
            // 清單刻意用 position:fixed 掛在 <body> 下，不是掛在欄位旁邊的 absolute——
            // 這種欄位常出現在有 overflow-y:auto 的跳窗裡，absolute 清單會被捲動容器裁掉
            // （CSS 無法只裁單軸，全站已踩過多次），fixed + 依欄位 rect 定位才不會被裁。
            '.eg-vp-dd{position:fixed;background:#fff;'
          + 'border:1px solid #D8BE93;border-radius:4px;box-shadow:0 4px 14px rgba(0,0,0,.18);z-index:13000;'
          + 'max-height:260px;overflow-y:auto;display:none;}'
          + '.eg-vp-item{padding:5px 9px;font-size:13px;color:#5b3a1e;cursor:pointer;border-bottom:1px solid #F3E9D6;}'
          + '.eg-vp-item:last-child{border-bottom:none;}'
          + '.eg-vp-item:hover,.eg-vp-item.sel{background:#F7E0BD;}'
          + '.eg-vp-item .vid{font-weight:bold;margin-right:8px;}'
          + '.eg-vp-item .vfull{color:#8a6d45;margin-left:8px;font-size:12px;}'
          + '.eg-vp-item .voff{color:#DD5138;margin-left:8px;font-size:12px;}'
          + '.eg-vp-empty{padding:8px 9px;font-size:12px;color:#8a6d45;}'));
        (document.head || document.documentElement).appendChild(st);
    }

    /** 顯示用名稱：有全名用全名，否則用簡稱（維修紀錄要印出來，全名比較正式） */
    function vendorLabel(row) {
        if (!row) return '';
        return row.maker_id_all || row.maker_id || row.maker_id_no || '';
    }

    var EGVendorPicker = {

        label: vendorLabel,

        attach: function (input, opt) {
            if (!input || input.__egVpBound) return;
            input.__egVpBound = true;
            opt = opt || {};
            var apiUrl = opt.apiUrl || '../../src/store/VendorPicker_API.php';
            var display = (typeof opt.display === 'function') ? opt.display : vendorLabel;
            injectCss();

            var dd = document.createElement('div');
            dd.className = 'eg-vp-dd';
            document.body.appendChild(dd);
            // 共用輸入規則(eg_input_rules.js)的 Enter 跳下一欄會跟清單的 Enter 選取打架，故本欄排除
            if (!input.hasAttribute('data-eg-skip')) input.setAttribute('data-eg-skip', '1');
            if (!input.hasAttribute('autocomplete')) input.setAttribute('autocomplete', 'off');

            var rows = [], selIdx = -1, timer = null;

            function render() {
                if (!rows.length) {
                    dd.innerHTML = '<div class="eg-vp-empty">'
                        + (input.value.trim() ? '查無符合的廠商（可直接輸入名稱，不一定要建過主檔）' : '輸入廠商代號、簡稱或全名的部分字元即可搜尋')
                        + '</div>';
                } else {
                    dd.innerHTML = rows.map(function (r, idx) {
                        return '<div class="eg-vp-item' + (idx === selIdx ? ' sel' : '') + '" data-idx="' + idx + '">'
                             + '<span class="vid">' + esc(r.maker_id_no) + '</span>'
                             + esc(r.maker_id || '')
                             + (r.maker_id_all && r.maker_id_all !== r.maker_id ? '<span class="vfull">' + esc(r.maker_id_all) + '</span>' : '')
                             + (Number(r.is_disabled) ? '<span class="voff">已停用</span>' : '')
                             + '</div>';
                    }).join('');
                }
                place();
                dd.style.display = 'block';
            }
            function place() {
                var r = input.getBoundingClientRect();
                var below = window.innerHeight - r.bottom;
                dd.style.left = r.left + 'px';
                dd.style.width = r.width + 'px';
                if (below < 140 && r.top > below) {   // 下方空間不足就往上開
                    dd.style.top = 'auto';
                    dd.style.bottom = (window.innerHeight - r.top + 2) + 'px';
                    dd.style.maxHeight = Math.min(260, r.top - 8) + 'px';
                } else {
                    dd.style.bottom = 'auto';
                    dd.style.top = (r.bottom + 2) + 'px';
                    dd.style.maxHeight = Math.min(260, below - 8) + 'px';
                }
            }
            function hide() { dd.style.display = 'none'; }
            function reposition() { if (dd.style.display === 'block') place(); }
            window.addEventListener('resize', reposition);
            window.addEventListener('scroll', reposition, true);   // true＝連跳窗內部捲動也跟著
            function search() {
                var q = input.value.trim();
                if (!q) { rows = []; selIdx = -1; hide(); return; }
                var url = apiUrl + '?action=search&q=' + encodeURIComponent(q)
                        + (opt.includeDisabled ? '&include_disabled=1' : '');
                fetch(url, {credentials: 'same-origin'})
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        rows = (d && d.success) ? (d.rows || []) : [];
                        selIdx = rows.length ? 0 : -1;
                        render();
                    })
                    .catch(function () { rows = []; render(); });
            }
            function pick(idx) {
                var row = rows[idx];
                if (!row) return;
                input.value = display(row);
                hide();
                if (typeof opt.onSelect === 'function') opt.onSelect(row);
            }

            input.addEventListener('input', function () {
                if (typeof opt.onInput === 'function') opt.onInput();
                clearTimeout(timer);
                timer = setTimeout(search, 250);
            });
            input.addEventListener('focus', function () { if (rows.length) render(); });
            input.addEventListener('keydown', function (e) {
                if (dd.style.display !== 'block') return;
                if (e.key === 'ArrowDown') { e.preventDefault(); if (selIdx < rows.length - 1) selIdx++; render(); }
                else if (e.key === 'ArrowUp') { e.preventDefault(); if (selIdx > 0) selIdx--; render(); }
                else if (e.key === 'Enter') { if (selIdx >= 0) { e.preventDefault(); pick(selIdx); } }
                else if (e.key === 'Escape') { hide(); }
            });
            dd.addEventListener('mousedown', function (e) {
                var item = e.target.closest ? e.target.closest('.eg-vp-item') : null;
                if (item) { e.preventDefault(); pick(parseInt(item.getAttribute('data-idx'), 10)); }
            });
            document.addEventListener('click', function (e) {
                if (e.target !== input && !dd.contains(e.target)) hide();
            });
        }
    };

    window.EGVendorPicker = EGVendorPicker;
})();
