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
            + '.epp-combo .epp-ci{margin:5px 0 2px;line-height:2;}'
            + '.epp-combo .epp-chip{margin-right:0;}'
            + '.epp-chead{display:flex;align-items:center;gap:4px;}'
            + '.epp-chead b{flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}'
            + '.epp-cl{margin-left:auto;white-space:nowrap;}.epp-cl a{color:#a0522d;cursor:pointer;font-size:11px;margin-left:8px;text-decoration:none;}'
            + '.epp-ent{display:inline-block;border:1px solid #E4B27A;border-radius:12px;padding:1px 3px;background:#FFFAF2;margin:0 0 3px 0;}'
            + '.epp-var{margin:0;}.epp-vor{font-size:11px;font-weight:700;color:#E4802A;margin:1px 0 1px 6px;}'
            + '.epp-or-sep{font-size:10px;color:#a0522d;margin:0 3px;}.epp-plus{font-size:12px;font-weight:700;color:#7A4A12;margin:0 4px;}'
            + '.epp-editing{border-color:#E4802A;box-shadow:0 0 0 2px #FBE3C6;}'
            + '.epp-pick{display:flex;align-items:center;gap:6px;margin-top:4px;font-size:12px;color:#7A4A12;}.epp-pick select{flex:1;}'
            + '.epp-tgt{background:#FFF1DC;border:1px solid #E4B27A;border-radius:4px;padding:4px 8px;margin-bottom:6px;font-size:12px;color:#6B4A2B;}.epp-tgt a{color:#a0522d;cursor:pointer;margin-left:6px;}'
            + '.epp-orl{margin:0 0 0 8px;font-size:11px;font-weight:600;color:#a0522d;cursor:pointer;}.epp-orn{font-weight:400;color:#888;}'
            + '.epp-pk{cursor:pointer;}.epp-pk:hover{background:#F0DFC6;}'
            + '.epp-combo .epp-cb{font-size:11px;padding:0 8px;border:1px solid #E4B27A;background:#FFF3E2;color:#8a5a12;border-radius:3px;cursor:pointer;margin-right:4px;}'
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
                    + '<div class="epp-saver"><div id="epp-tgt" class="epp-tgt" style="display:none;"></div><div style="font-size:11px;color:#888;margin-bottom:4px;">把左側目前勾選的內容存成一組（單一大類或小類也可以；名稱預設自動帶入，可自行修改）</div>'
                    + '<div style="display:flex;gap:4px;"><input type="text" id="epp-cname" class="form-control input-sm" maxlength="60" placeholder="組合名稱（自動帶入：製程1+製程2…）" autocomplete="off">'
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
                MODAL.addEventListener('change', function (e) {
                    var sel = e.target, cid = sel.getAttribute && sel.getAttribute('data-epp-cpick');
                    if (!cid || sel.value === '') return;
                    startEdit(parseInt(cid, 10), 'edit', parseInt(sel.value, 10));
                });
                MODAL.querySelector('#epp-cname').addEventListener('input', function () { MODAL._nameTouched = this.value.trim() !== ''; });
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
            MODAL._nameTouched = false;
            MODAL._split = {}; MODAL._tgt = null; MODAL._pick = null;
            updateTgtUi();
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
              + ((!covered && nSel >= 2)
                  ? '<label class="epp-orl" title="勾選＝這幾個小類是同一個製程（任一個即可）；取消勾選＝各自是不同製程"><input type="checkbox" data-epp-or="' + t.id + '"' + (MODAL._split[t.id] ? '' : ' checked') + '> 或' + (MODAL._split[t.id] ? '<span class="epp-orn">（各為不同製程）</span>' : '') + '</label>'
                  : '')
              + '<div class="epp-subs">';
            subs.forEach(function (s) {
                h += '<label class="' + (covered ? 'cov' : '') + '"><input type="checkbox" data-epp-no="' + s.id + '"' + ((covered || W.no[s.id]) ? ' checked' : '') + (covered ? ' disabled' : '') + '> ' + esc(s.name) + '</label>';
            });
            h += '</div></div>';
        });
        MODAL.querySelector('#epp-list').innerHTML = h || '<div style="color:#aaa;padding:10px;">查無符合的製程</div>';
        var n = Object.keys(W.type).length + Object.keys(W.no).length;
        MODAL.querySelector('#epp-cnt').textContent = '已選 ' + n + ' 項';
        syncName();
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
    // ── 組合 ＝ 一種或多種「做法」（variants，彼此是「或」）；每種做法是一串「製程」（用「＋」連接）；
    //    一個製程可含多個項目（組內也是「或」）。例：[精滾]＋[壓檔片]　或　[滾齒]＋[壓檔片]
    // 左側勾選：同一大類裡勾 2 個以上小類時，預設為「或」（同一個製程）；取消「或」＝各自是不同製程。
    function curProcs() {
        var W = MODAL._work, sp = MODAL._split || {}, out = [];
        DICT.forEach(function (t) {
            if (W.type[t.id]) { out.push([{ t: 'type', id: t.id, name: t.name }]); return; }
            var subs = t.subs.filter(function (s) { return W.no[s.id]; }).map(function (s) { return { t: 'no', id: s.id, name: s.name }; });
            if (!subs.length) return;
            if (sp[t.id]) subs.forEach(function (x) { out.push([x]); });
            else out.push(subs);
        });
        return out;
    }
    function procsName(procs) { return procs.map(function (g) { return g.map(function (x) { return x.name; }).join('/'); }).join('+'); }
    function variantsName(vs) {
        var n = vs.map(procsName).join(' 或 ');
        return n.length > 60 ? n.slice(0, 59) + '…' : n;
    }
    function autoName() { return variantsName([curProcs()]); }
    function syncName() {
        var el = MODAL.querySelector('#epp-cname');
        if (!MODAL._nameTouched) el.value = autoName();
    }
    function parentName(id) {
        var pn = ''; DICT.forEach(function (t) { if (t.subs.some(function (s) { return s.id === id; })) pn = t.name; }); return pn;
    }
    // 一個製程 ＝ 一個外框，裡面的項目用「或」連接；點項目＝只把這一項加進左側勾選
    function procChips(procs) {
        var typeNames = {}; DICT.forEach(function (t) { typeNames[t.name] = 1; });
        return procs.map(function (g) {
            return '<span class="epp-ent">' + g.map(function (x) {
                if (x.t === 'type') return '<span class="epp-chip epp-pk" data-epp-citem="type:' + x.id + '" title="大類（整個大類）；點一下只加入這一項">◆ ' + esc(x.name) + '</span>';
                var pn = parentName(x.id), label = (typeNames[x.name] && pn) ? pn + '›' + x.name : x.name;
                return '<span class="epp-chip epp-pk" data-epp-citem="no:' + x.id + '" title="' + esc(pn ? '屬於大類：' + pn + '；點一下只加入這一項' : '點一下只加入這一項') + '">' + esc(label) + '</span>';
            }).join('<span class="epp-or-sep">或</span>') + '</span>';
        }).join('<span class="epp-plus">＋</span>');
    }
    function findCombo(id) { var c = null; COMBOS.forEach(function (x) { if (x.id === id) c = x; }); return c; }

    function drawCombos() {
        var box = MODAL.querySelector('#epp-clist'), h = '';
        COMBOS.forEach(function (c) {
            var mine = (c.created_by === MY_UID || MY_UID === 1);
            var pk = MODAL._pick && MODAL._pick.id === c.id;
            h += '<div class="epp-combo' + (MODAL._tgt && MODAL._tgt.id === c.id ? ' epp-editing' : '') + '">'
              + '<div class="epp-chead">'
              + '<button type="button" class="epp-cb" data-epp-capply="' + c.id + '" title="用這個組合取代目前勾選">套用</button>'
              + '<button type="button" class="epp-cb" data-epp-cadd="' + c.id + '" title="把這個組合加進目前勾選">加入</button>'
              + '<b title="' + esc(c.name) + '">' + esc(c.name) + '</b>'
              + (mine ? '<span class="epp-cl"><a data-epp-cedit="' + c.id + '" title="修改這個組合的內容">編輯</a><a data-epp-calt="' + c.id + '" title="新增一種「或」的做法（先帶入現有內容，改成另一種再存）">相同製程</a><a data-epp-cdel="' + c.id + '" title="刪除整個組合">刪除</a></span>' : '')
              + '</div>'
              + '<div class="epp-ci">' + (c.variants.length
                  ? c.variants.map(function (v) { return '<div class="epp-var">' + procChips(v) + '</div>'; }).join('<div class="epp-vor">或</div>')
                  : '<span class="epp-none">（內容的製程已不存在）</span>') + '</div>';
            if (pk) {
                h += '<div class="epp-pick"><span>要編輯哪一種？</span><select data-epp-cpick="' + c.id + '" class="form-control input-sm">'
                  + '<option value="">請選擇…</option>'
                  + c.variants.map(function (v, i) { return '<option value="' + i + '">第 ' + (i + 1) + ' 種：' + esc(procsName(v)) + '</option>'; }).join('')
                  + '</select></div>';
            }
            h += '</div>';
        });
        box.innerHTML = h || '<div style="color:#aaa;font-size:12px;padding:6px 0;">還沒有組合。左側勾好常用的大類／小類後，在下方存成組合，之後一鍵套用。</div>';
    }

    // 把一種做法放進左側勾選（replace＝取代；否則疊加），並依結構還原各大類的「或／且」
    function applyProcs(procs, merge) {
        var W = merge ? MODAL._work : { type: {}, no: {} };
        if (!merge) MODAL._split = {};
        MODAL._work = W;
        procs.forEach(function (g) { g.forEach(function (x) { if (x.t === 'type') W.type[x.id] = 1; }); });
        DICT.forEach(function (t) { if (W.type[t.id]) t.subs.forEach(function (s) { delete W.no[s.id]; }); });
        procs.forEach(function (g) {
            g.forEach(function (x) {
                if (x.t !== 'no') return;
                var cov = DICT.some(function (t) { return W.type[t.id] && t.subs.some(function (s) { return s.id === x.id; }); });
                if (!cov) W.no[x.id] = 1;
            });
        });
        // 同一大類的小類分散在 2 個以上製程＝「且」（不同製程）
        DICT.forEach(function (t) {
            var n = 0;
            procs.forEach(function (g) { if (g.some(function (x) { return x.t === 'no' && t.subs.some(function (s) { return s.id === x.id; }); })) n++; });
            if (n >= 2) MODAL._split[t.id] = true;
        });
    }
    function comboApply(id, merge) {
        var c = findCombo(id); if (!c) return;
        c.variants.forEach(function (v, i) { applyProcs(v, merge || i > 0); });   // 各種做法是「或」，全部放進勾選
        drawList();
        comboMsg('已' + (merge ? '加入' : '套用') + '「' + c.name + '」，按「確定」才會帶入欄位', true);
    }

    // ── 編輯／新增「相同製程」：左側勾選區變成編輯區，存檔鈕改成「更新…」 ──
    function updateTgtUi() {
        var T = MODAL._tgt, bar = MODAL.querySelector('#epp-tgt'), btn = MODAL.querySelector('[data-epp-csave]');
        if (!T) { bar.style.display = 'none'; btn.textContent = '存成組合'; return; }
        var c = findCombo(T.id) || { name: '', variants: [] }, multi = c.variants.length > 1;
        var txt = T.mode === 'edit'
            ? '編輯中：「' + c.name + '」' + (multi ? '第 ' + (T.idx + 1) + ' 種' : '')
            : '「' + c.name + '」新增相同製程（或）：請在左側勾選另一種做法（例如 滾齒＋壓檔片；同大類的小類要各自成為不同製程時，先取消該大類旁的「或」），再按「加入為「或」」';
        bar.innerHTML = '<b>' + esc(txt) + '</b> <a data-epp-ctgt-x="1">取消</a>'
            + (T.mode === 'edit' && multi ? ' <a data-epp-cvdel="1" title="只刪除這一種，其他種保留">刪除這一種</a>' : '');
        bar.style.display = 'block';
        btn.textContent = T.mode === 'edit' ? (multi ? '更新這一種' : '更新組合') : '加入為「或」';
    }
    function startEdit(id, mode, idx) {
        var c = findCombo(id); if (!c || !c.variants.length) return;
        MODAL._pick = null;
        MODAL._tgt = { id: id, mode: mode, idx: idx };
        if (mode === 'new') { MODAL._work = { type: {}, no: {} }; MODAL._split = {}; }   // 相同製程：左側從空白開始，勾另一種做法
        else applyProcs(c.variants[idx], false);
        MODAL.querySelector('#epp-cname').value = c.name;
        MODAL._nameTouched = true;
        updateTgtUi(); drawList(); drawCombos();
        comboMsg('', true);
    }
    function endEdit() {
        MODAL._tgt = null; MODAL._pick = null; MODAL._nameTouched = false;
        updateTgtUi(); syncName(); drawCombos();
    }
    function flatOf(g) { return g.map(function (x) { return { t: x.t, id: x.id, name: x.name }; }); }
    function cloneVariants(c) { return c.variants.map(function (v) { return v.map(flatOf); }); }

    function postVariants(c, variants, nm) {
        // 名稱：原名稱是自動產生的才跟著新內容更新；自己取的名稱不動
        if (c && (!nm || nm === c.name) && c.name === variantsName(c.variants)) nm = variantsName(variants);
        else if (!nm) nm = variantsName(variants);
        var data = { name: nm, variants: JSON.stringify(variants.map(function (v) { return v.map(function (g) { return g.map(function (x) { return { t: x.t, id: x.id }; }); }); })) };
        if (c) data.id = c.id;
        postCombo('process_combo_save', data, function (r) {
            comboMsg(r.message || '', !!r.success);
            if (r.success) {
                MODAL._tgt = null; MODAL._pick = null; MODAL._nameTouched = false;
                MODAL._work = { type: {}, no: {} }; MODAL._split = {};      // 存好了，左側勾選清空
                updateTgtUi(); drawList();
            }
            drawCombos();
        });
    }
    function saveCombo() {
        var T = MODAL._tgt, nm = MODAL.querySelector('#epp-cname').value.trim(), c = T ? findCombo(T.id) : null;
        if (T && !c) { comboMsg('這個組合已不存在，請重新整理', false); return; }
        var procs = curProcs();
        if (!procs.length) { comboMsg('左側請先勾選要存的大類或小類', false); return; }
        var vs = c ? cloneVariants(c) : [];
        if (!T) vs = [procs];
        else if (T.mode === 'edit') vs[T.idx] = procs;
        else vs.push(procs);
        postVariants(c, vs, nm);
    }
    function delVariant() {
        var T = MODAL._tgt, c = T ? findCombo(T.id) : null;
        if (!c || c.variants.length < 2) return;
        if (!confirm('只刪除第 ' + (T.idx + 1) + ' 種做法？（其他種保留）')) return;
        var vs = cloneVariants(c); vs.splice(T.idx, 1);
        postVariants(c, vs, '');
    }

    function modalClick(e) {
        var t = e.target;
        if (t.getAttribute('data-epp-x')) { close(); return; }
        if (t === MODAL) { close(); return; }
        if (t.getAttribute('data-epp-clear')) { MODAL._work = { type: {}, no: {} }; MODAL._split = {}; drawList(); return; }
        if (t.getAttribute('data-epp-tg')) { var id = t.getAttribute('data-epp-tg'); MODAL._open[id] = !MODAL._open[id]; drawList(); return; }
        if (t.getAttribute('data-epp-ok')) { commit(); return; }
        var orT = t.getAttribute('data-epp-or');
        if (orT) { if (t.checked) delete MODAL._split[orT]; else MODAL._split[orT] = true; drawList(); return; }
        var ca = t.getAttribute('data-epp-capply'), cd = t.getAttribute('data-epp-cadd'), cx = t.getAttribute('data-epp-cdel');
        var ce = t.getAttribute('data-epp-cedit'), cl = t.getAttribute('data-epp-calt');
        var ci = t.getAttribute('data-epp-citem');
        if (ci) {
            var pr = ci.split(':'), id1 = parseInt(pr[1], 10), W1 = MODAL._work;
            if (pr[0] === 'type') {
                W1.type[id1] = 1;
                DICT.forEach(function (d) { if (d.id === id1) d.subs.forEach(function (s) { delete W1.no[s.id]; }); });
            } else if (!DICT.some(function (d) { return W1.type[d.id] && d.subs.some(function (s) { return s.id === id1; }); })) { W1.no[id1] = 1; }
            drawList(); return;
        }
        if (ca) { comboApply(parseInt(ca, 10), false); return; }
        if (cd) { comboApply(parseInt(cd, 10), true); return; }
        if (ce) {
            var cid = parseInt(ce, 10), cc = findCombo(cid); if (!cc) return;
            if (cc.variants.length <= 1) { startEdit(cid, 'edit', 0); return; }
            MODAL._pick = { id: cid }; drawCombos(); return;      // 有「或」的幾種做法：先選要編輯哪一種
        }
        if (cl) { startEdit(parseInt(cl, 10), 'new', 0); return; }  // 相同製程＝新增一種「或」的做法（帶入現有內容再修改）
        if (t.getAttribute('data-epp-ctgt-x')) { endEdit(); return; }
        if (t.getAttribute('data-epp-cvdel')) { delVariant(); return; }
        if (cx) {
            if (!confirm('確定刪除整個組合？（已套用過的附件不受影響）')) return;
            postCombo('process_combo_del', { id: cx }, function (r) {
                if (MODAL._tgt && String(MODAL._tgt.id) === cx) { MODAL._tgt = null; updateTgtUi(); }
                drawCombos(); comboMsg(r.message || '', !!r.success);
            });
            return;
        }
        if (t.getAttribute('data-epp-csave')) { saveCombo(); return; }
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
