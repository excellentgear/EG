/**
 * eg_proc_pick.js —— 製程大類／製程小類 多選選擇器（全站唯一實作，2026-10-20）
 *
 * 用途：料號附件的「製程標籤」、料號清單的「附件製程」篩選。
 * 選擇內容：[{t:'type', id, name}, {t:'no', id, name}]；大類＝整個大類，小類＝個別製程，可混合複選。
 * 大類已選時，它底下的小類視為已涵蓋（灰掉不可再選，避免重複）。
 *
 * 用法：
 *   <div id="x" class="epp"></div>
 *   EGProcPick.mount(document.getElementById('x'), { url:'../../src/store/Part_Attachment_API.php',
 *        value:[...], legacy:'舊文字（沒有重新選過的舊附件）', onChange:function(sel){} });
 *   EGProcPick.get(el)      → 目前選擇（陣列）
 *   EGProcPick.touched(el)  → 使用者是否動過（沒動過就不要送出，免得把舊文字洗掉）
 *   EGProcPick.set(el, sel, legacy)
 * 視窗左側是大類／小類清單（兩欄），右側是「組合」：常用的 大類/小類 混合選擇可存成一組、一鍵套用
 * （全公司共用，存 part_attach_proc_combo，見 attach_proc_lib）。
 * 配色走暖色系（ai-rules/10）；視窗寬度用固定像素（不用 vw）。
 */
(function (w) {
    'use strict';
    var DICT = null, DICT_CBS = null, DICT_URL = '';
    var COMBOS = [], MY_UID = 0;
    var MODAL = null, CUR = null;       // CUR＝目前正在編輯的掛載元素

    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

    function loadDict(url, cb) {
        if (DICT) { cb(DICT); return; }
        if (DICT_CBS) { DICT_CBS.push(cb); return; }
        DICT_CBS = [cb];
        DICT_URL = url;
        var xhr = new XMLHttpRequest();
        xhr.open('GET', url + (url.indexOf('?') >= 0 ? '&' : '?') + 'action=process_dict');
        xhr.onload = function () {
            var d = [];
            try { var r = JSON.parse(xhr.responseText); if (r && r.success) d = r.types || []; } catch (e) {}
            DICT = d;
            var cbs = DICT_CBS; DICT_CBS = null;
            cbs.forEach(function (f) { f(DICT); });
        };
        xhr.onerror = function () { var cbs = DICT_CBS; DICT_CBS = null; cbs.forEach(function (f) { f([]); }); };
        xhr.send();
    }

    function nameOf(t, id) {
        if (!DICT) return '';
        for (var i = 0; i < DICT.length; i++) {
            if (t === 'type' && DICT[i].id === id) return DICT[i].name;
            if (t === 'no') for (var j = 0; j < DICT[i].subs.length; j++) if (DICT[i].subs[j].id === id) return DICT[i].subs[j].name;
        }
        return '';
    }

    function renderBox(el) {
        var st = el._epp, h = '';
        st.sel.forEach(function (x, i) {
            h += '<span class="epp-chip" title="' + (x.t === 'type' ? '製程大類（整個大類）' : '製程小類') + '">'
              + (x.t === 'type' ? '<b>◆</b> ' : '') + esc(x.name)
              + (st.ro ? '' : ' <a href="javascript:void(0)" data-epp-del="' + i + '" title="移除">×</a>') + '</span>';
        });
        if (!st.sel.length && st.legacy) h += '<span class="epp-chip epp-legacy" title="舊資料的文字標籤；重新選擇後會改用主檔的製程">舊值：' + esc(st.legacy) + '</span>';
        if (!st.sel.length && !st.legacy) h += '<span class="epp-none">（共用／未指定）</span>';
        if (!st.ro) h += '<button type="button" class="epp-btn" data-epp-open="1"><i class="fa fa-plus"></i> 選擇製程</button>';
        el.innerHTML = h;
    }

    function ensureCss() {
        if (document.getElementById('epp-css')) return;
        var s = document.createElement('style'); s.id = 'epp-css';
        s.textContent = '.epp{display:flex;flex-wrap:wrap;gap:3px;align-items:center;min-height:30px;padding:2px 4px;border:1px solid #ccc;border-radius:3px;background:#fff;}'
            + '.epp-chip{display:inline-block;font-size:11px;font-weight:600;background:#F3E9DC;color:#6B4A2B;border:1px solid #E4D3BC;border-radius:10px;padding:0 7px;line-height:18px;white-space:nowrap;}'
            + '.epp-chip a{color:#a0522d;text-decoration:none;margin-left:2px;font-weight:700;}'
            + '.epp-legacy{background:#f4f4f4;color:#888;border-color:#ddd;font-weight:400;}'
            + '.epp-none{font-size:11px;color:#aaa;}'
            + '.epp-btn{margin-left:auto;font-size:11px;padding:1px 8px;border:1px solid #E4B27A;background:#FFF3E2;color:#8a5a12;border-radius:3px;cursor:pointer;}'
            + '#epp-mask{position:fixed;left:0;top:0;right:0;bottom:0;background:rgba(60,40,20,.45);z-index:20000;display:none;}'
            + '#epp-box{position:absolute;left:50%;top:6vh;margin-left:-580px;width:1160px;max-height:86vh;background:#fff;border-radius:6px;box-shadow:0 8px 30px rgba(0,0,0,.35);display:flex;flex-direction:column;}'
            + '#epp-box header{padding:10px 14px;background:#7A4A12;color:#fff;border-radius:6px 6px 0 0;font-weight:700;display:flex;justify-content:space-between;}'
            + '#epp-box .epp-main{display:flex;flex:1;min-height:0;}'
            + '#epp-box .epp-body{padding:10px 14px;overflow-y:auto;flex:1;min-width:0;}'
            + '#epp-list{column-count:2;column-gap:22px;}'
            + '#epp-list .epp-t{break-inside:avoid;}'
            + '#epp-box .epp-side{width:420px;flex-shrink:0;border-left:1px solid #eadfce;background:#FBF6EE;display:flex;flex-direction:column;min-height:0;}'
            + '.epp-side h5{margin:0;padding:10px 14px 6px;font-weight:700;color:#7A4A12;font-size:13px;}'
            + '.epp-side .epp-clist{flex:1;overflow-y:auto;padding:0 14px 8px;}'
            + '.epp-combo{background:#fff;border:1px solid #E4D3BC;border-radius:5px;padding:6px 8px;margin-bottom:6px;}'
            + '.epp-combo b{color:#4A3524;font-size:13px;}'
            + '.epp-combo .epp-ci{margin:3px 0 5px;line-height:1.9;}'
            + '.epp-combo .epp-chip{margin-right:3px;}'
            + '.epp-combo .epp-cb{font-size:11px;padding:0 8px;border:1px solid #E4B27A;background:#FFF3E2;color:#8a5a12;border-radius:3px;cursor:pointer;margin-right:4px;}'
            + '.epp-combo .epp-cd{float:right;color:#a0522d;cursor:pointer;font-size:11px;}'
            + '.epp-saver{border-top:1px solid #eadfce;padding:8px 14px;}'
            + '#epp-box footer{padding:8px 14px;border-top:1px solid #eee;display:flex;gap:6px;justify-content:flex-end;align-items:center;}'
            + '.epp-t{border-bottom:1px solid #f0e6d8;padding:4px 0;}'
            + '.epp-t label{margin:0;font-weight:700;cursor:pointer;color:#4A3524;}'
            + '.epp-subs{margin:4px 0 2px 24px;display:none;flex-wrap:wrap;gap:2px 14px;}'
            + '.epp-t.open .epp-subs{display:flex;}'
            + '.epp-subs label{font-weight:400;font-size:12px;min-width:110px;}'
            + '.epp-subs label.cov{color:#bbb;}'
            + '.epp-tg{cursor:pointer;color:#8a5a12;font-size:11px;margin-left:6px;}';
        document.head.appendChild(s);
    }

    function openModal(el) {
        ensureCss();
        CUR = el;
        loadDict(el._epp.url, function (dict) {
            if (!MODAL) {
                MODAL = document.createElement('div'); MODAL.id = 'epp-mask';
                MODAL.innerHTML = '<div id="epp-box"><header><span>選擇製程（大類＝整個大類，小類＝個別製程，可混合複選）</span><a href="javascript:void(0)" data-epp-x="1" style="color:#fff;">✕</a></header>'
                    + '<div class="epp-main"><div class="epp-body"><input type="text" id="epp-kw" class="form-control input-sm" placeholder="輸入關鍵字篩選大類或製程名稱" autocomplete="off" style="margin-bottom:6px;"><div id="epp-list"></div></div>'
                    + '<div class="epp-side"><h5>常用組合（大類＋小類可混合）</h5><div class="epp-clist" id="epp-clist"></div>'
                    + '<div class="epp-saver"><div style="font-size:11px;color:#888;margin-bottom:4px;">把左側目前勾選的內容存成一組（至少 2 項）</div>'
                    + '<div style="display:flex;gap:4px;"><input type="text" id="epp-cname" class="form-control input-sm" maxlength="60" placeholder="組合名稱，例如：熱處理＋齒研" autocomplete="off">'
                    + '<button type="button" class="btn btn-warning btn-sm" data-epp-csave="1" style="white-space:nowrap;">存成組合</button></div>'
                    + '<div id="epp-cmsg" style="font-size:11px;margin-top:3px;min-height:15px;"></div></div></div></div>'
                    + '<footer><span id="epp-cnt" style="margin-right:auto;font-size:12px;color:#888;"></span>'
                    + '<button type="button" class="btn btn-default btn-sm" data-epp-clear="1">全部清除</button>'
                    + '<button type="button" class="btn btn-default btn-sm" data-epp-x="1">取消</button>'
                    + '<button type="button" class="btn btn-warning btn-sm" data-epp-ok="1">確定</button></footer></div>';
                document.body.appendChild(MODAL);
                MODAL.addEventListener('click', modalClick);
                var kwEl = MODAL.querySelector('#epp-kw');
                ['input', 'keyup', 'compositionend', 'change'].forEach(function (ev) { kwEl.addEventListener(ev, function () { drawList(); }); });
                // Bootstrap modal 的 focusin 鎖會把焦點搶回自己，輸入框就打不了字（也就不會篩選）；本視窗內的焦點事件不往上傳
                MODAL.addEventListener('focusin', function (e) { e.stopPropagation(); });
                MODAL.addEventListener('keydown', function (e) { if (e.key === 'Enter' && e.target && e.target.id === 'epp-kw') e.preventDefault(); });
            }
            // Bootstrap 的 modal 會把焦點鎖在自己裡面，輸入框要打字就得把本視窗放進目前開著的 modal 內
            // 疊了好幾層 modal 時要放進「最上層」那一個（z-index 最大、同值取 DOM 較後者），否則會被上層 modal 蓋住
            var host = document.body, bestZ = -1;
            Array.prototype.forEach.call(document.querySelectorAll('.modal.in'), function (m) {
                var z = parseInt(window.getComputedStyle(m).zIndex, 10); if (isNaN(z)) z = 0;
                if (z >= bestZ) { bestZ = z; host = m; }
            });
            if (MODAL.parentNode !== host) host.appendChild(MODAL);
            MODAL._work = { type: {}, no: {} };
            el._epp.sel.forEach(function (x) { MODAL._work[x.t][x.id] = 1; });
            MODAL.querySelector('#epp-kw').value = '';
            MODAL._open = {};
            drawList();
            drawCombos();
            loadCombos(el._epp.url, function () { drawCombos(); });
            MODAL.style.display = 'block';
            MODAL.querySelector('#epp-kw').focus();
        });
    }

    function drawList() {
        var kw = (MODAL.querySelector('#epp-kw').value || '').trim().toLowerCase();
        var words = kw ? kw.split(/\s+/) : [];
        var W = MODAL._work, h = '';
        function hit(s) { s = String(s).toLowerCase(); return words.every(function (x) { return s.indexOf(x) >= 0; }); }
        DICT.forEach(function (t) {
            var subs = t.subs.filter(function (s) { return !words.length || hit(t.name) || hit(s.name); });
            if (words.length && !hit(t.name) && !subs.length) return;
            var covered = !!W.type[t.id];
            var open = MODAL._open[t.id] || (words.length > 0 && subs.length > 0 && !hit(t.name));
            var nSel = t.subs.filter(function (s) { return W.no[s.id]; }).length;
            h += '<div class="epp-t' + (open ? ' open' : '') + '" data-tid="' + t.id + '"><label><input type="checkbox" data-epp-type="' + t.id + '"' + (covered ? ' checked' : '') + '> ' + esc(t.name) + '</label>'
              + (t.subs.length ? '<span class="epp-tg" data-epp-tg="' + t.id + '">' + (open ? '▾' : '▸') + ' 小類 ' + t.subs.length + (nSel ? '（已選 ' + nSel + '）' : '') + '</span>' : '')
              + '<div class="epp-subs">';
            subs.forEach(function (s) {
                h += '<label class="' + (covered ? 'cov' : '') + '"><input type="checkbox" data-epp-no="' + s.id + '"' + ((covered || W.no[s.id]) ? ' checked' : '') + (covered ? ' disabled' : '') + '> ' + esc(s.name) + '</label>';
            });
            h += '</div></div>';
        });
        MODAL.querySelector('#epp-list').innerHTML = h || '<div style="color:#aaa;padding:10px;">查無符合的製程</div>';
        var n = Object.keys(W.type).length + Object.keys(W.no).length;
        MODAL.querySelector('#epp-cnt').textContent = '已選 ' + n + ' 項';
    }

    function loadCombos(url, cb) {
        var xhr = new XMLHttpRequest();
        xhr.open('GET', url + (url.indexOf('?') >= 0 ? '&' : '?') + 'action=process_combo_list');
        xhr.onload = function () {
            try { var r = JSON.parse(xhr.responseText); if (r && r.success) { COMBOS = r.combos || []; MY_UID = r.uid || 0; } } catch (e) {}
            if (cb) cb();
        };
        xhr.send();
    }
    function postCombo(action, data, cb) {
        var url = CUR ? CUR._epp.url : DICT_URL;
        var xhr = new XMLHttpRequest();
        xhr.open('POST', url);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onload = function () {
            var r = null; try { r = JSON.parse(xhr.responseText); } catch (e) {}
            if (r && r.combos) { COMBOS = r.combos; MY_UID = r.uid || MY_UID; }
            cb(r || { success: false, message: '伺服器回應異常' });
        };
        xhr.onerror = function () { cb({ success: false, message: '連線失敗' }); };
        var body = 'action=' + action;
        Object.keys(data).forEach(function (k) { body += '&' + k + '=' + encodeURIComponent(data[k]); });
        xhr.send(body);
    }
    function comboMsg(t, ok) { var m = MODAL.querySelector('#epp-cmsg'); m.style.color = ok ? '#2e7d32' : '#c0392b'; m.textContent = t; }
    function drawCombos() {
        var box = MODAL.querySelector('#epp-clist'), h = '';
        COMBOS.forEach(function (c) {
            var chips = c.items.map(function (x) {
                return '<span class="epp-chip">' + (x.t === 'type' ? '<b>◆</b> ' : '') + esc(x.name) + '</span>';
            }).join('');
            h += '<div class="epp-combo"><b>' + esc(c.name) + '</b>'
              + ((c.created_by === MY_UID || MY_UID === 1) ? '<span class="epp-cd" data-epp-cdel="' + c.id + '" title="刪除這個組合">刪除</span>' : '')
              + '<div class="epp-ci">' + (chips || '<span class="epp-none">（內容的製程已不存在）</span>') + '</div>'
              + '<button type="button" class="epp-cb" data-epp-capply="' + c.id + '" title="用這個組合取代目前勾選">套用</button>'
              + '<button type="button" class="epp-cb" data-epp-cadd="' + c.id + '" title="把這個組合加進目前勾選">加入</button></div>';
        });
        box.innerHTML = h || '<div style="color:#aaa;font-size:12px;padding:6px 0;">還沒有組合。左側勾好常用的大類／小類後，在下方命名存起來，之後一鍵套用。</div>';
    }
    function comboApply(id, merge) {
        var c = null; COMBOS.forEach(function (x) { if (x.id === id) c = x; });
        if (!c) return;
        var W = merge ? MODAL._work : { type: {}, no: {} };
        MODAL._work = W;
        c.items.forEach(function (x) { if (x.t === 'type') W.type[x.id] = 1; });
        DICT.forEach(function (t) { if (W.type[t.id]) t.subs.forEach(function (s) { delete W.no[s.id]; }); });
        c.items.forEach(function (x) {
            if (x.t !== 'no') return;
            var cov = DICT.some(function (t) { return W.type[t.id] && t.subs.some(function (s) { return s.id === x.id; }); });
            if (!cov) W.no[x.id] = 1;
        });
        drawList();
        comboMsg('已' + (merge ? '加入' : '套用') + '「' + c.name + '」，按「確定」才會帶入欄位', true);
    }

    function modalClick(e) {
        var t = e.target;
        if (t.getAttribute('data-epp-x')) { close(); return; }
        if (t === MODAL) { close(); return; }
        if (t.getAttribute('data-epp-clear')) { MODAL._work = { type: {}, no: {} }; drawList(); return; }
        if (t.getAttribute('data-epp-tg')) { var id = t.getAttribute('data-epp-tg'); MODAL._open[id] = !MODAL._open[id]; drawList(); return; }
        if (t.getAttribute('data-epp-ok')) { commit(); return; }
        var ca = t.getAttribute('data-epp-capply'), cd = t.getAttribute('data-epp-cadd'), cx = t.getAttribute('data-epp-cdel');
        if (ca) { comboApply(parseInt(ca, 10), false); return; }
        if (cd) { comboApply(parseInt(cd, 10), true); return; }
        if (cx) {
            if (!confirm('確定刪除這個組合？（已套用過的附件不受影響）')) return;
            postCombo('process_combo_del', { id: cx }, function (r) { drawCombos(); comboMsg(r.message || '', !!r.success); });
            return;
        }
        if (t.getAttribute('data-epp-csave')) {
            var sel = [], W = MODAL._work;
            DICT.forEach(function (d) { if (W.type[d.id]) sel.push({ t: 'type', id: d.id }); d.subs.forEach(function (s) { if (W.no[s.id]) sel.push({ t: 'no', id: s.id }); }); });
            var nm = MODAL.querySelector('#epp-cname').value.trim();
            if (!nm) { comboMsg('請先輸入組合名稱', false); return; }
            if (sel.length < 2) { comboMsg('左側至少要勾選 2 項才能存成組合', false); return; }
            postCombo('process_combo_save', { name: nm, items: JSON.stringify(sel) }, function (r) {
                drawCombos(); comboMsg(r.message || '', !!r.success);
                if (r.success) MODAL.querySelector('#epp-cname').value = '';
            });
            return;
        }
        var ty = t.getAttribute && t.getAttribute('data-epp-type');
        if (ty) {
            ty = parseInt(ty, 10);
            if (t.checked) {
                MODAL._work.type[ty] = 1;
                DICT.forEach(function (d) { if (d.id === ty) d.subs.forEach(function (s) { delete MODAL._work.no[s.id]; }); });   // 整個大類已涵蓋，個別小類清掉
            } else { delete MODAL._work.type[ty]; }
            drawList(); return;
        }
        var no = t.getAttribute && t.getAttribute('data-epp-no');
        if (no) { no = parseInt(no, 10); if (t.checked) MODAL._work.no[no] = 1; else delete MODAL._work.no[no]; drawList(); }
    }

    function commit() {
        var W = MODAL._work, sel = [];
        DICT.forEach(function (t) { if (W.type[t.id]) sel.push({ t: 'type', id: t.id, name: t.name }); });
        DICT.forEach(function (t) { t.subs.forEach(function (s) { if (W.no[s.id]) sel.push({ t: 'no', id: s.id, name: s.name }); }); });
        var el = CUR;
        el._epp.sel = sel; el._epp.touched = true; el._epp.legacy = '';
        renderBox(el);
        close();
        if (el._epp.onChange) el._epp.onChange(sel);
    }
    function close() { if (MODAL) MODAL.style.display = 'none'; CUR = null; }

    var API = {
        mount: function (el, opt) {
            if (!el) return;
            ensureCss();
            opt = opt || {};
            el.classList.add('epp');
            el._epp = { sel: (opt.value || []).map(function (x) { return { t: x.t, id: parseInt(x.id, 10), name: x.name || '' }; }),
                        legacy: opt.legacy || '', touched: false, ro: !!opt.readonly, url: opt.url || '../../src/store/Part_Attachment_API.php', onChange: opt.onChange || null };
            if (!el._eppBound) {
                el._eppBound = true;
                el.addEventListener('click', function (e) {
                    var d = e.target.getAttribute && e.target.getAttribute('data-epp-del');
                    if (d !== null && d !== undefined && d !== '') {
                        el._epp.sel.splice(parseInt(d, 10), 1); el._epp.touched = true; renderBox(el);
                        if (el._epp.onChange) el._epp.onChange(el._epp.sel); return;
                    }
                    var b = e.target.closest ? e.target.closest('[data-epp-open]') : null;
                    if (b) openModal(el);
                });
            }
            renderBox(el);
            loadDict(el._epp.url, function () {   // 字典載回來後補上缺的名稱
                el._epp.sel.forEach(function (x) { if (!x.name) x.name = nameOf(x.t, x.id); });
                renderBox(el);
            });
        },
        get: function (el) { return el && el._epp ? el._epp.sel.map(function (x) { return { t: x.t, id: x.id }; }) : []; },
        touched: function (el) { return !!(el && el._epp && el._epp.touched); },
        set: function (el, sel, legacy) { if (!el || !el._epp) return; el._epp.sel = sel || []; el._epp.legacy = legacy || ''; el._epp.touched = false; renderBox(el); },
        loadDict: loadDict
    };
    w.EGProcPick = API;
})(window);
