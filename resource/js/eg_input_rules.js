/*!
 * eg_input_rules.js — EGsystem 全站輸入欄位互動規則（CLAUDE.md「UI 規則」的唯一實作）
 *
 * 為什麼要有這支：這些規則 CLAUDE.md 早就寫了，但過去每個頁面都自己手刻一份，
 * 結果總有頁面漏掉、或只綁到一部分欄位（例如只綁跳窗內、沒綁篩選列）。
 * 改成共用檔＋document 事件委派後，只要載入本檔就全頁生效，
 * 連 AJAX 之後才畫出來的欄位也一樣有效，沒有「忘記綁」的可能。
 *
 * 用法（放在 custom.min.js 之後）：
 *   <script src="../../resource/js/eg_input_rules.js?v=<?= filemtime('../../resource/js/eg_input_rules.js') ?>"></script>
 *
 * 實作的規則：
 *   1. 有值時雙擊清空（並觸發 input/change，篩選欄雙擊＝同時解除該欄篩選）
 *   2. 聚焦已有資料的欄位自動全選
 *   3. Enter 跳下一欄；容器最後一欄按 Enter＝觸發該容器的主要動作鈕（textarea 內 Enter 仍為換行）
 *   4. 多列輸入表格內 ↑↓ 切換上下列同欄（日期欄會攔截原生 ↑↓ 改日，否則會變成改日期）
 *   5. 數字欄：隱藏上下增減鈕、離開欄位時小數尾 0 省略（3.50→3.5、3.00→3）
 *   6. 可增列表格：在最末列按 ↓ 自動新增一列並跳過去；在「沒填東西的最末列」按 ↑ 自動移除該列並跳回上一列
 *   7. 長清單下拉可打字篩選：<select data-eg-filter> 自動長出一個篩選輸入框（人員／料號／客戶等清單一多就找不到人）
 *
 * 個別欄位要排除：加 data-eg-skip
 * 整個區塊要排除：在祖先元素加 data-eg-skip
 * 指定 Enter 的送出目標：在容器加 data-eg-submit="選擇器"，或讓容器內有 .m-foot .go / .btn-warm / button[type=submit]
 * 啟用規則 6（自動增/刪列）：在該表格的 <tbody> 加
 *     data-eg-row-add="全域函式名"   （呼叫後應在最後面多一列並重繪；不帶參數）
 *     data-eg-row-del="全域函式名"   （呼叫後應移除最後一列並重繪；列數只剩 1 列時函式自己要擋掉）
 *   「沒填東西」的判定：該列所有輸入欄皆為空，或該列是剛剛用 ↓ 自動加出來、使用者一個字都沒動過
 *   （所以即使新列會自動帶入上一列的值，只要沒動過，按 ↑ 一樣會收回去）。
 */
(function () {
    'use strict';

    /* Element.closest 相容保護：本檔是全站共用，不假設瀏覽器版本 */
    if (!Element.prototype.closest) {
        var matches = Element.prototype.matches || Element.prototype.msMatchesSelector
                   || Element.prototype.webkitMatchesSelector;
        Element.prototype.closest = function (sel) {
            var n = this;
            while (n && n.nodeType === 1) {
                if (matches.call(n, sel)) return n;
                n = n.parentElement || n.parentNode;
            }
            return null;
        };
    }

    /* 可套用規則的欄位型別（checkbox/radio/file/hidden/按鈕類都不算） */
    var TEXTY = ['text', 'search', 'tel', 'url', 'email', 'password',
                 'number', 'date', 'month', 'week', 'time', 'datetime-local'];

    function isTexty(el) {
        if (!el || !el.tagName) return false;
        var tag = el.tagName.toLowerCase();
        if (tag === 'textarea') return true;
        if (tag === 'select')   return true;
        if (tag !== 'input')    return false;
        return TEXTY.indexOf((el.type || 'text').toLowerCase()) >= 0;
    }
    function skipped(el) {
        if (!el) return true;
        if (el.disabled || el.readOnly) return true;
        return !!(el.closest && el.closest('[data-eg-skip]'));
    }
    /* 規則7 自己長出來的篩選框帶著 data-eg-skip，那是為了不要套「Enter 跳下一欄／↑↓ 換列」
       （在篩選框裡按 Enter 應該是選起來、不是跳走）。但使用者 2026-08-28 明確要求
       **「有值雙擊清空」與「聚焦自動全選」這兩條篩選框也要有**——它本來就是一個要反覆重打的欄位。
       所以規則 1、2 改用這支：data-eg-skip 照樣擋別的元素，只放行篩選框本身。 */
    function skippedSoft(el) {
        if (el && el.classList && el.classList.contains('eg-filter-box')) {
            return !!(el.disabled || el.readOnly);
        }
        return skipped(el);
    }
    function fire(el, name) {
        var ev;
        try { ev = new Event(name, {bubbles: true}); }
        catch (e) { ev = document.createEvent('Event'); ev.initEvent(name, true, true); }
        el.dispatchEvent(ev);
    }
    function isNumberish(el) {
        return el.tagName.toLowerCase() === 'input'
            && (el.type || '').toLowerCase() === 'number';
    }

    /* ── 規則 1：有值雙擊清空 ─────────────────────────────────────────── */
    document.addEventListener('dblclick', function (e) {
        var el = e.target;
        if (!isTexty(el) || skippedSoft(el)) return;
        var tag = el.tagName.toLowerCase();

        if (tag === 'select') {
            // 篩選用下拉：雙擊回到第一個「空值」選項＝解除該欄篩選
            var reset = -1;
            for (var i = 0; i < el.options.length; i++) {
                if (el.options[i].value === '') { reset = i; break; }
            }
            if (reset < 0 || el.selectedIndex === reset) return;
            el.selectedIndex = reset;
            fire(el, 'change');
            return;
        }
        if (el.value === '' || el.value == null) return;
        el.value = '';
        fire(el, 'input');
        fire(el, 'change');
    }, true);

    /* ── 規則 2：聚焦已有資料自動全選 ─────────────────────────────────── */
    document.addEventListener('focusin', function (e) {
        var el = e.target;
        if (!isTexty(el) || skippedSoft(el)) return;
        if (el.tagName.toLowerCase() === 'select') return;
        if (el.value === '' || el.value == null) return;
        // number/date 等型別部分瀏覽器不支援 select()，包 try 即可。
        // ※ 一定要再確認焦點還在這個欄位上：這支 select() 是延後執行的，
        //   若這段空檔裡焦點已經被移到別的欄位（例如規則 6 按 ↓ 自動加一列後把游標移到新列），
        //   在 Chrome 對「沒有焦點的 input」呼叫 select() 會把焦點搶回來，
        //   使用者看到的症狀是「按了 ↓ 有加列、游標卻彈回原本那一列」。
        setTimeout(function () {
            if (document.activeElement !== el) return;
            try { el.select(); } catch (err) {}
        }, 0);
    });

    /* ── 規則 3/4：Enter 跳欄、表格內 ↑↓ 換列 ─────────────────────────── */

    /** 找出這個欄位所屬的「表單容器」，用來決定 Enter 要跳到哪、以及誰是送出鈕 */
    function containerOf(el) {
        return el.closest('[data-eg-form]') || el.closest('form')
            || el.closest('.a-modal') || el.closest('.at-modal')
            || el.closest('.sq-modal') || el.closest('.a-bar')
            || el.closest('.sq-bar')  || el.closest('.at-bar')
            || el.form || document.body;
    }
    function focusables(box) {
        var list = box.querySelectorAll('input,select,textarea');
        var out = [];
        for (var i = 0; i < list.length; i++) {
            var el = list[i];
            if (!isTexty(el) || skipped(el)) continue;
            if (el.offsetParent === null && el.type !== 'hidden') continue;  // 看不到的不算
            out.push(el);
        }
        return out;
    }
    function submitTargetOf(box) {
        var sel = box.getAttribute && box.getAttribute('data-eg-submit');
        if (sel) return box.querySelector(sel) || document.querySelector(sel);
        return box.querySelector('.m-foot .go')
            || box.querySelector('.btn-warm')
            || box.querySelector('button[type=submit]');
    }

    /** 同一欄在上／下一列的對應欄位（多列輸入表格用） */
    function siblingRowField(el, dir) {
        var td = el.closest('td'); if (!td) return null;
        var tr = td.closest('tr');  if (!tr) return null;
        var idx = Array.prototype.indexOf.call(tr.children, td);
        var row = (dir < 0) ? tr.previousElementSibling : tr.nextElementSibling;
        while (row) {
            var cell = row.children[idx];
            if (cell) {
                var f = cell.querySelector('input,select,textarea');
                if (f && isTexty(f) && !skipped(f)) return f;
            }
            row = (dir < 0) ? row.previousElementSibling : row.nextElementSibling;
        }
        return null;
    }

    /* ── 規則 6：可增列表格的自動增／刪列 ─────────────────────────────── */
    /* 剛用 ↓ 自動加出來、還沒被使用者動過的那一列（動過就不再自動收回） */
    var AUTOROW = null;      // {tb: tbody, idx: 列索引}

    function autoRowBox(el) {
        var tb = el.closest('tbody');
        if (!tb) return null;
        return (tb.getAttribute('data-eg-row-add') || tb.getAttribute('data-eg-row-del')) ? tb : null;
    }
    function callFn(name) {
        if (!name) return false;
        var f = window[name];
        if (typeof f !== 'function') return false;
        try { f(); } catch (err) { return false; }
        return true;
    }
    function idxOf(list, node) { return Array.prototype.indexOf.call(list, node); }
    function rowIsBlank(tr) {
        var list = tr.querySelectorAll('input,select,textarea');
        for (var i = 0; i < list.length; i++) {
            var el = list[i];
            if (!isTexty(el)) continue;                 // checkbox 等不列入判斷
            if (String(el.value == null ? '' : el.value).trim() !== '') return false;
        }
        return true;
    }
    /* 重繪後才找得到新列，所以用「列索引＋欄索引」重新定位，找不到再延後一次 */
    function focusCell(tb, ri, ci) {
        var tr = tb.rows && tb.rows[ri];
        if (!tr) return false;
        var cell = tr.children[ci];
        var f = (cell && cell.querySelector('input,select,textarea')) || tr.querySelector('input,select,textarea');
        if (!f) return false;
        f.focus();
        try { f.select(); } catch (err) {}
        return true;
    }
    function focusCellSoon(tb, ri, ci) {
        if (!focusCell(tb, ri, ci)) setTimeout(function () { focusCell(tb, ri, ci); }, 0);
    }
    /* 使用者一動那一列（打字/改值）就取消「自動加出來的」標記 */
    function autoRowTouched(e) {
        if (!AUTOROW) return;
        var el = e.target;
        if (!el || !el.closest) return;
        var tb = el.closest('tbody');
        if (tb !== AUTOROW.tb) return;
        var tr = el.closest('tr');
        if (tr && idxOf(tb.rows, tr) === AUTOROW.idx) AUTOROW = null;
    }
    document.addEventListener('input', autoRowTouched, true);
    document.addEventListener('change', autoRowTouched, true);

    document.addEventListener('keydown', function (e) {
        // 頁面自己已經處理掉的（有 preventDefault）就不再插手，
        // 否則會出現「頁面跳一欄、共用檔又跳一欄」而跳過欄位。
        if (e.defaultPrevented) return;
        var el = e.target;
        if (!isTexty(el) || skipped(el)) return;
        var tag = el.tagName.toLowerCase();

        /* 規則 4：在表格內的欄位，↑↓ 切換上下列同欄。
           日期／數字欄的原生 ↑↓ 會改值，所以一律 preventDefault 蓋掉。 */
        if ((e.key === 'ArrowUp' || e.key === 'ArrowDown') && !e.ctrlKey && !e.altKey && !e.metaKey) {
            if (tag === 'select') return;                       // 下拉的 ↑↓ 保留原生選項切換
            var td0 = el.closest('td');
            if (td0) {
                /* 規則 6：可增列表格的自動增／刪列（要放在換列之前——
                   「最末空列按 ↑」時上一列是存在的，先換列就沒機會刪了） */
                var tb  = autoRowBox(el);
                var tr0 = el.closest('tr');
                var ri  = tb ? idxOf(tb.rows, tr0) : -1;
                var ci  = idxOf(tr0.children, td0);
                var isLast = (tb && ri >= 0 && ri === tb.rows.length - 1);
                if (isLast && e.key === 'ArrowUp' && ri > 0 && tb.getAttribute('data-eg-row-del')
                    && (rowIsBlank(tr0) || (AUTOROW && AUTOROW.tb === tb && AUTOROW.idx === ri))) {
                    e.preventDefault();
                    AUTOROW = null;
                    callFn(tb.getAttribute('data-eg-row-del'));
                    focusCellSoon(tb, ri - 1, ci);
                    return;
                }
                var nx = siblingRowField(el, e.key === 'ArrowUp' ? -1 : 1);
                if (nx) {
                    e.preventDefault();
                    nx.focus();
                    try { nx.select(); } catch (err) {}
                    return;
                }
                if (isLast && e.key === 'ArrowDown' && tb.getAttribute('data-eg-row-add')) {
                    e.preventDefault();
                    if (callFn(tb.getAttribute('data-eg-row-add'))) {
                        AUTOROW = {tb: tb, idx: ri + 1};
                        focusCellSoon(tb, ri + 1, ci);
                    }
                    return;
                }
                // 沒有上／下一列時，日期與數字欄仍要擋掉原生改值，避免誤改
                if ((el.type === 'date' || el.type === 'month' || el.type === 'number')) {
                    e.preventDefault();
                }
            }
            return;
        }

        /* 規則 3：Enter 跳下一欄；textarea 內 Enter 保持換行 */
        if (e.key !== 'Enter' || e.ctrlKey || e.altKey || e.metaKey || e.shiftKey) return;
        if (tag === 'textarea') return;

        var box  = containerOf(el);
        var list = focusables(box);
        var i    = list.indexOf(el);
        if (i < 0) return;

        if (i < list.length - 1) {
            e.preventDefault();
            var t = list[i + 1];
            t.focus();
            try { t.select(); } catch (err) {}
        } else {
            // 最後一欄：觸發該容器的主要動作（查詢／存檔）
            var btn = submitTargetOf(box);
            if (btn && !btn.disabled) {
                e.preventDefault();
                btn.click();
            }
        }
    });

    /* ── 規則 5：數字欄小數尾 0 省略 ──────────────────────────────────── */
    document.addEventListener('blur', function (e) {
        var el = e.target;
        if (!isNumberish(el) || skipped(el)) return;
        var v = (el.value || '').trim();
        if (v === '' || !/^-?\d*\.\d+$/.test(v)) return;         // 只處理有小數點的
        var n = parseFloat(v);
        if (isNaN(n)) return;
        var s = String(parseFloat(n.toFixed(6)));                // 3.50→3.5、3.00→3
        if (s !== v) { el.value = s; fire(el, 'change'); }
    }, true);

    /* ── 規則 5：數字欄不顯示上下增減鈕（注入一次，免得每頁各寫一份 CSS）── */
    (function injectCss() {
        if (document.getElementById('eg-input-rules-css')) return;
        var css = 'input[type=number]::-webkit-outer-spin-button,'
                + 'input[type=number]::-webkit-inner-spin-button'
                + '{-webkit-appearance:none;margin:0;}'
                + 'input[type=number]{-moz-appearance:textfield;appearance:textfield;}';
        var st = document.createElement('style');
        st.id = 'eg-input-rules-css';
        st.appendChild(document.createTextNode(css));
        (document.head || document.documentElement).appendChild(st);
    })();

    /* ── 規則 7：長清單下拉可打字篩選 ─────────────────────────────────
     * 為什麼：人員／料號／客戶這種下拉，清單一多就要在幾十筆裡用眼睛找，非常難用
     * （使用者反覆反映過同一件事）。凡是 <select data-eg-filter> 一律自動長出一個
     * 篩選輸入框，打字即過濾選項（多關鍵字空白分隔＝每個都要命中）。
     * 頁面不要自己刻：新畫出來的 select（AJAX/重繪）由 MutationObserver 自動接手。
     * 篩選框上按 Enter／↓＝跳進 select；清空篩選＝還原全部選項；目前選中的選項永遠保留。
     */
    function egFilterEnhance(sel) {
        if (!sel || sel.tagName !== 'SELECT' || sel.egFiltered || skipped(sel)) return;
        sel.egFiltered = true;
        var box = document.createElement('input');
        box.type = 'text';
        box.className = 'eg-filter-box';
        box.placeholder = sel.getAttribute('data-eg-filter') || '輸入關鍵字篩選…';
        box.setAttribute('data-eg-skip', '1');                 // 篩選框本身不套 Enter 跳欄等規則
        sel.parentNode.insertBefore(box, sel);
        var all = [];                                          // 完整選項快照
        var rendered = '';                                     // 上一次由本函式寫進去的選項簽章
        function sig() {
            var a = [];
            for (var i = 0; i < sel.options.length; i++) a.push(sel.options[i].value);
            return a.join(String.fromCharCode(1));
        }
        function snap() {
            all = [];
            for (var i = 0; i < sel.options.length; i++)
                all.push({v: sel.options[i].value, t: sel.options[i].text});
        }
        snap();
        rendered = sig();
        function apply() {
            // 選項不是我們上次寫進去的那批＝頁面自己換過了（AJAX 撈完才填、或重繪整批），
            // 一定要先重新快照，否則會拿舊快照（常常是「還沒填任何選項」的空快照）把清單洗掉。
            if (sig() !== rendered) snap();
            var kw = box.value.trim().toLowerCase().split(/\s+/).filter(Boolean);
            var cur = sel.value, html = '', hit = [];
            all.forEach(function (o) {
                var t = o.t.toLowerCase();
                var ok = !kw.length || kw.every(function (k) { return t.indexOf(k) >= 0; });
                if (!ok && o.v !== cur) return;                 // 目前選中的一定留著，免得存檔被清掉
                if (ok && o.v !== '') hit.push(o.v);
                html += '<option value="' + o.v.replace(/"/g, '&quot;') + '">' + o.t + '</option>';
            });
            sel.innerHTML = html;
            if (kw.length && hit.length === 1) sel.value = hit[0];   // 只剩一個就直接選起來
            else sel.value = cur;
            rendered = sig();
            if (sel.value !== cur) fire(sel, 'change');              // 值真的變了才發事件，免得誤觸頁面既有 change
        }
        box.addEventListener('input', apply);
        box.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === 'ArrowDown') { e.preventDefault(); sel.focus(); }
        });
        sel.egFilterResnap = function () { snap(); apply(); };   // 頁面自行換掉整批選項後可呼叫
    }
    window.egSelectFilterScan = function (root) {
        var list = (root || document).querySelectorAll('select[data-eg-filter]');
        for (var i = 0; i < list.length; i++) egFilterEnhance(list[i]);
    };
    document.addEventListener('DOMContentLoaded', function () { window.egSelectFilterScan(); });
    if (window.MutationObserver) {
        new MutationObserver(function (muts) {
            for (var i = 0; i < muts.length; i++) {
                var ad = muts[i].addedNodes;
                for (var j = 0; j < ad.length; j++) {
                    var n = ad[j];
                    if (!n || n.nodeType !== 1) continue;
                    if (n.tagName === 'SELECT') egFilterEnhance(n);
                    else if (n.querySelectorAll) window.egSelectFilterScan(n);
                }
            }
        }).observe(document.documentElement, {childList: true, subtree: true});
    }

    /* 規則 7 的預設樣式（頁面可自行覆蓋 .eg-filter-box） */
    (function injectFilterCss() {
        if (document.getElementById('eg-filter-css')) return;
        var st = document.createElement('style');
        st.id = 'eg-filter-css';
        st.appendChild(document.createTextNode(
            '.eg-filter-box{display:block;width:100%;max-width:280px;margin:0 0 3px;padding:3px 6px;'
            + 'font-size:12px;border:1px solid #D8BE93;border-radius:4px;background:#FFFDF8;color:#5b3a1e;}'
            + '.eg-filter-box::placeholder{color:#b59b74;}'
            + '@media print{.eg-filter-box{display:none;}}'));
        (document.head || document.documentElement).appendChild(st);
    })();

    /* 對外留一個旗標，檢查工具與頁面都可判斷本檔是否已載入 */
    window.EG_INPUT_RULES = {version: 4};
})();
