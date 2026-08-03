/*!
 * eg_asdoc_picker.js — AS 文件編號綁定選擇器（全站唯一實作，2026-08-03）
 *
 * 為什麼要有這支：列印文件要綁 AS 文件編號（表頭取 doc_name、頁尾右下取 doc_no，見 ai-rules/16），
 * 但過去每個頁面各自刻一份綁定 UI——有的只有一個長下拉、有的沒有篩選，文件一多就找不到編號。
 * 使用者明確要求「統一成可以填寫編號快速篩選出現列表」，所以收斂成這支共用檔。
 *
 * 用法：
 *   <script src="../../resource/js/eg_asdoc_picker.js?v=<?= @filemtime(...) ?>"></script>
 *   EGAsDoc.open({
 *     docs   : [{id:1, doc_no:'2-SM-01-05', doc_name:'訂單變更紀錄表'}, ...],  // 後端 eg_asdoc_list()
 *     current: 12,                       // 目前綁定的 as_document.id（0/null=未綁定）
 *     title  : 'AS 文件編號綁定',         // 可省略
 *     onSave : function(id, doc){ ... }  // 按「儲存綁定」後呼叫；id=0 代表取消綁定
 *   });
 *   EGAsDoc.label(doc)  // 統一的顯示字串：'2-SM-01-05（訂單變更紀錄表）' 或 '尚未綁定'
 *
 * 行為（全站一致）：輸入框打編號或名稱即時篩選（多關鍵字空白分隔＝每個都要命中）、
 * 清單直接點選、Enter＝只剩一筆時直接選起來、Esc 或點遮罩＝取消。
 */
(function () {
    'use strict';

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c];
        });
    }

    function injectCss() {
        if (document.getElementById('eg-asdoc-css')) return;
        var st = document.createElement('style');
        st.id = 'eg-asdoc-css';
        st.appendChild(document.createTextNode(
            '.eg-asdoc-mask{position:fixed;inset:0;background:rgba(60,40,20,.45);z-index:12000;display:block;}'
          + '.eg-asdoc-box{background:#fff;border-radius:8px;max-width:620px;margin:60px auto;'
          + 'box-shadow:0 5px 25px rgba(0,0,0,.3);display:flex;flex-direction:column;max-height:80vh;}'
          + '.eg-asdoc-box .h{background:#F7E0BD;color:#5b3a1e;font-weight:bold;padding:10px 15px;'
          + 'border-radius:8px 8px 0 0;display:flex;justify-content:space-between;}'
          + '.eg-asdoc-box .h .x{cursor:pointer;color:#b5762a;}'
          + '.eg-asdoc-box .b{padding:14px 15px;overflow-y:auto;}'
          + '.eg-asdoc-box .f{padding:10px 15px;border-top:1px solid #EADFC8;text-align:right;}'
          + '.eg-asdoc-box input.kw{width:100%;padding:6px 9px;font-size:13px;border:1px solid #D8BE93;'
          + 'border-radius:4px;background:#FFFDF8;color:#5b3a1e;margin-bottom:8px;}'
          + '.eg-asdoc-box select.lst{width:100%;font-size:13px;border:1px solid #D8BE93;border-radius:4px;'
          + 'padding:4px;color:#5b3a1e;}'
          + '.eg-asdoc-box .cnt{font-size:12px;color:#8a6d45;margin-top:5px;}'
          + '.eg-asdoc-box button{height:30px;padding:0 16px;border-radius:4px;font-size:13px;cursor:pointer;}'
          + '.eg-asdoc-box .ok{border:1px solid #d98a33;background:#F0A24B;color:#fff;margin-left:6px;}'
          + '.eg-asdoc-box .no{border:1px solid #D8BE93;background:#fff;color:#5b3a1e;}'));
        (document.head || document.documentElement).appendChild(st);
    }

    var API = {
        /** 統一的顯示字串（各頁顯示「目前綁定」時請用這個，格式才會一致） */
        label: function (doc) {
            return doc && doc.doc_no ? (doc.doc_no + '（' + (doc.doc_name || '') + '）') : '尚未綁定';
        },

        open: function (opt) {
            opt = opt || {};
            var docs = opt.docs || [], cur = String(opt.current || 0);
            injectCss();

            var mask = document.createElement('div');
            mask.className = 'eg-asdoc-mask';
            mask.innerHTML =
                '<div class="eg-asdoc-box">'
              + '<div class="h"><span>' + esc(opt.title || 'AS 文件編號綁定') + '</span><span class="x">✕</span></div>'
              + '<div class="b">'
              + '<input type="text" class="kw" data-eg-skip="1" placeholder="輸入文件編號或名稱即可篩選（例：2-SM 或 訂單變更）">'
              + '<select class="lst" size="12"></select>'
              + '<div class="cnt"></div>'
              + '<div class="cnt">綁定後：列印表頭自動顯示該文件的<b>表單名稱</b>，頁尾右下角自動印<b>文件編號</b>（全站列印標準 ai-rules/16）。</div>'
              + '</div>'
              + '<div class="f"><button class="no">取消</button><button class="ok">儲存綁定</button></div>'
              + '</div>';
            document.body.appendChild(mask);

            var kw = mask.querySelector('input.kw'),
                lst = mask.querySelector('select.lst'),
                cnt = mask.querySelector('.cnt');

            function render() {
                var terms = kw.value.trim().toLowerCase().split(/\s+/).filter(Boolean);
                var keep = lst.value || cur, html = '<option value="0">（不綁定）</option>', n = 0;
                docs.forEach(function (d) {
                    var t = (d.doc_no + ' ' + d.doc_name).toLowerCase();
                    var ok = !terms.length || terms.every(function (k) { return t.indexOf(k) >= 0; });
                    if (!ok && String(d.id) !== keep) return;      // 已選中的一定留著
                    if (ok) n++;
                    html += '<option value="' + d.id + '">' + esc(d.doc_no) + '　' + esc(d.doc_name) + '</option>';
                });
                lst.innerHTML = html;
                lst.value = keep;
                if (lst.value === null || lst.value === '') lst.value = '0';
                cnt.textContent = '符合 ' + n + ' 筆' + (terms.length ? '' : '（共 ' + docs.length + ' 份文件）');
            }
            render();

            function close() { if (mask.parentNode) mask.parentNode.removeChild(mask); }
            function save() {
                var id = parseInt(lst.value, 10) || 0;
                var doc = null;
                docs.forEach(function (d) { if (parseInt(d.id, 10) === id) doc = d; });
                close();
                if (typeof opt.onSave === 'function') opt.onSave(id, doc);
            }

            // 【踩坑】本選擇器常被從 Bootstrap modal 內叫出來，而 BS modal 的 enforceFocus 會監聽
            // document 的 focusin、把焦點搶回 modal 裡 → 篩選框「點得到但打不了字」。
            // 攔在自己的遮罩上把 focusin 停止冒泡，document 上的 handler 就收不到，不必去改 Bootstrap 全域行為。
            mask.addEventListener('focusin', function (e) { e.stopPropagation(); });
            mask.addEventListener('mousedown', function (e) { e.stopPropagation(); });

            kw.addEventListener('input', render);
            kw.addEventListener('keydown', function (e) {
                if (e.key === 'ArrowDown') { e.preventDefault(); lst.focus(); }
                else if (e.key === 'Enter') {                       // 只剩一筆＝直接選起來
                    e.preventDefault();
                    if (lst.options.length === 2) { lst.value = lst.options[1].value; save(); }
                }
            });
            lst.addEventListener('dblclick', save);
            mask.querySelector('.ok').addEventListener('click', save);
            mask.querySelector('.no').addEventListener('click', close);
            mask.querySelector('.x').addEventListener('click', close);
            mask.addEventListener('click', function (e) { if (e.target === mask) close(); });
            document.addEventListener('keydown', function esckey(e) {
                if (e.key === 'Escape') { close(); document.removeEventListener('keydown', esckey); }
            });
            setTimeout(function () { kw.focus(); }, 30);
        }
    };

    window.EGAsDoc = API;
})();
