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
 * 配色走暖色系（ai-rules/10）；視窗寬度用固定像素（不用 vw）。
 */
(function (w) {
    'use strict';
    var DICT = null, DICT_CBS = null, DICT_URL = '';
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
            + '#epp-box{position:absolute;left:50%;top:6vh;margin-left:-290px;width:580px;max-height:86vh;background:#fff;border-radius:6px;box-shadow:0 8px 30px rgba(0,0,0,.35);display:flex;flex-direction:column;}'
            + '#epp-box header{padding:10px 14px;background:#7A4A12;color:#fff;border-radius:6px 6px 0 0;font-weight:700;display:flex;justify-content:space-between;}'
            + '#epp-box .epp-body{padding:10px 14px;overflow-y:auto;flex:1;}'
            + '#epp-box footer{padding:8px 14px;border-top:1px solid #eee;display:flex;gap:6px;justify-content:flex-end;align-items:center;}'
            + '.epp-t{border-bottom:1px solid #f0e6d8;padding:4px 0;}'
            + '.epp-t label{margin:0;font-weight:700;cursor:pointer;color:#4A3524;}'
            + '.epp-subs{margin:4px 0 2px 24px;display:none;flex-wrap:wrap;gap:2px 14px;}'
            + '.epp-t.open .epp-subs{display:flex;}'
            + '.epp-subs label{font-weight:400;font-size:12px;min-width:150px;}'
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
                    + '<div class="epp-body"><input type="text" id="epp-kw" class="form-control input-sm" placeholder="輸入關鍵字篩選大類或製程名稱" style="margin-bottom:6px;"><div id="epp-list"></div></div>'
                    + '<footer><span id="epp-cnt" style="margin-right:auto;font-size:12px;color:#888;"></span>'
                    + '<button type="button" class="btn btn-default btn-sm" data-epp-clear="1">全部清除</button>'
                    + '<button type="button" class="btn btn-default btn-sm" data-epp-x="1">取消</button>'
                    + '<button type="button" class="btn btn-warning btn-sm" data-epp-ok="1">確定</button></footer></div>';
                document.body.appendChild(MODAL);
                MODAL.addEventListener('click', modalClick);
                MODAL.querySelector('#epp-kw').addEventListener('input', function () { drawList(); });
            }
            // Bootstrap 的 modal 會把焦點鎖在自己裡面，輸入框要打字就得把本視窗放進目前開著的 modal 內
            var host = document.querySelector('.modal.in') || document.body;
            if (MODAL.parentNode !== host) host.appendChild(MODAL);
            MODAL._work = { type: {}, no: {} };
            el._epp.sel.forEach(function (x) { MODAL._work[x.t][x.id] = 1; });
            MODAL.querySelector('#epp-kw').value = '';
            MODAL._open = {};
            drawList();
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

    function modalClick(e) {
        var t = e.target;
        if (t.getAttribute('data-epp-x')) { close(); return; }
        if (t === MODAL) { close(); return; }
        if (t.getAttribute('data-epp-clear')) { MODAL._work = { type: {}, no: {} }; drawList(); return; }
        if (t.getAttribute('data-epp-tg')) { var id = t.getAttribute('data-epp-tg'); MODAL._open[id] = !MODAL._open[id]; drawList(); return; }
        if (t.getAttribute('data-epp-ok')) { commit(); return; }
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
