/**
 * BOM 管理員修正面板（左側滑出，唯一實作）——BOM總覽「BOM 製程列表」旁的「管理員修正」鈕開啟。
 * 後端：views/pm/OreadyReply_ForPm_BaseOfTime_ajax.php 的 baf_* 動作（src/common/bom_admin_fix_lib.php）。
 * 用法：EGBomAdminFix.open('B-1150407008', bomSn)；需先輸入操作確認密碼；每次儲存後局部更新，不整頁重載。
 */
(function (w) {
    var drawer = null, bom = '', bomSn = '', DATA = null, unlocked = false;
    var KINDS = [['send', '發單'], ['return', '回廠／報廢／取回'], ['qc', 'QC檢驗'], ['transfer', '移轉憑單'], ['abnormal', '異常單']];
    var COLORS = { send: '#F0A24B', return: '#2E8B57', qc: '#8A6A45', transfer: '#C0561F', abnormal: '#DD5138' };
    var LABELS = { send: '發單', return: '回廠', qc: 'QC檢驗', transfer: '移轉憑單', abnormal: '異常單' };
    var on = { send: true, return: true, qc: true, transfer: true, abnormal: true };
    var stationFilter = '';
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function nz(v) { v = parseFloat(v) || 0; return (Math.round(v * 1000) / 1000).toString(); }
    function today() { var d = new Date(), p = function (n) { return (n < 10 ? '0' : '') + n; }; return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()); }
    function toast(t, ok) { if (typeof w.showTemporaryMessage === 'function') w.showTemporaryMessage(t, !!ok); else alert(t); }
    function post(data, cb) {
        data.bom = bom;
        w.jQuery.post('', data, function (r) {
            if (r && r.need_unlock) { unlocked = false; promptPw(function () { post(data, cb); }); return; }
            cb(r || { success: false, message: '無回應' });
        }, 'json').fail(function () { cb({ success: false, message: '與伺服器通訊失敗' }); });
    }
    function ensure() {
        if (drawer) return drawer;
        var st = document.createElement('style');
        st.textContent = '#egaf-drawer{position:fixed;top:0;left:0;height:100%;width:600px;max-width:96vw;background:#fff;z-index:10700;box-shadow:4px 0 18px rgba(0,0,0,.22);transform:translateX(-105%);transition:transform .22s ease;display:flex;flex-direction:column;font-size:13px;color:#4a3320;}'
            + '#egaf-drawer.open{transform:translateX(0);}'
            + '#egaf-drawer .h{padding:10px 14px;border-bottom:1px solid #E4B3A0;background:#F6D9CC;display:flex;justify-content:space-between;align-items:center;}'
            + '#egaf-drawer .f{padding:8px 14px;border-bottom:1px solid #E4D3BC;background:#FDF3E3;}'
            + '#egaf-drawer .b{flex:1;overflow-y:auto;padding:8px 14px 24px;}'
            + '#egaf-drawer .chip{display:inline-block;border:1px solid #E4D3BC;border-radius:12px;padding:1px 10px;margin:0 4px 4px 0;cursor:pointer;background:#fff;font-size:12px;user-select:none;}'
            + '#egaf-drawer .chip.on{color:#fff;border-color:transparent;}'
            + '#egaf-drawer .dd{font-weight:bold;margin:10px 0 4px;color:#7a4a10;border-bottom:1px dotted #E4D3BC;}'
            + '#egaf-drawer .it{border:1px solid #EEE0CC;border-radius:4px;padding:5px 8px;margin-bottom:5px;background:#FFFCF7;}'
            + '#egaf-drawer .it .k{display:inline-block;color:#fff;border-radius:3px;padding:0 6px;font-size:11px;line-height:18px;margin-right:6px;}'
            + '#egaf-drawer .it input{padding:0 3px;height:24px;font-size:12px;border:1px solid #d8c8b0;border-radius:3px;}'
            + '#egaf-drawer .it .row2{margin-top:4px;display:flex;flex-wrap:wrap;gap:6px;align-items:center;font-size:12px;}'
            + '#egaf-drawer .nw{display:none;border:1px dashed #E4D3BC;border-radius:4px;padding:6px 8px;margin-top:6px;background:#fff;font-size:12px;}'
            + '#egaf-drawer .nw input,#egaf-drawer .nw select{height:24px;font-size:12px;padding:0 3px;}';
        document.head.appendChild(st);
        drawer = document.createElement('div');
        drawer.id = 'egaf-drawer';
        drawer.innerHTML = '<div class="h"><strong id="egaf-title">管理員修正</strong><span><button type="button" id="egaf-lock" class="btn btn-default btn-xs" style="margin-right:8px;">結束並上鎖</button><button type="button" id="egaf-x" style="border:none;background:none;font-size:20px;cursor:pointer;color:#999;line-height:1;">×</button></span></div>'
            + '<div class="f"><div id="egaf-chips"></div><div style="margin-top:2px;">製程：<select id="egaf-st" style="height:24px;font-size:12px;"></select> '
            + '<button type="button" class="btn btn-success btn-xs" id="egaf-new-send">＋新增發單</button> <button type="button" class="btn btn-success btn-xs" id="egaf-new-ret">＋新增回廠／報廢</button></div>'
            + '<div class="nw" id="egaf-nw-send">廠商 <input list="egaf-mk-dl" id="egaf-mk" style="width:170px;" placeholder="編號或名稱後點選"><datalist id="egaf-mk-dl"></datalist> 數量 <input type="number" min="0" step="any" id="egaf-ns-q" style="width:70px;"> 送出日 <input type="date" id="egaf-ns-d" style="width:125px;"> <button type="button" class="btn btn-warning btn-xs" id="egaf-ns-go">新增</button></div>'
            + '<div class="nw" id="egaf-nw-ret">發單批次 <select id="egaf-nr-b" style="max-width:190px;"></select> 回廠 <input type="number" min="0" step="any" id="egaf-nr-r" style="width:60px;"> 報廢 <input type="number" min="0" step="any" id="egaf-nr-s" style="width:60px;"> 取回 <input type="number" min="0" step="any" id="egaf-nr-w" style="width:60px;"> 日期 <input type="date" id="egaf-nr-d" style="width:125px;"> <button type="button" class="btn btn-warning btn-xs" id="egaf-nr-go">新增</button></div>'
            + '<div id="egaf-msg" style="min-height:16px;font-size:12px;font-weight:bold;margin-top:3px;"></div></div>'
            + '<div class="b" id="egaf-body"></div>';
        document.body.appendChild(drawer);
        drawer.querySelector('#egaf-x').onclick = close;
        drawer.querySelector('#egaf-lock').onclick = function () { w.jQuery.post('', { action: 'baf_lock' }, function () { unlocked = false; close(); toast('已上鎖', true); }, 'json'); };
        drawer.querySelector('#egaf-st').onchange = function () { stationFilter = this.value; render(); };
        drawer.querySelector('#egaf-new-send').onclick = function () { toggleNw('egaf-nw-send'); };
        drawer.querySelector('#egaf-new-ret').onclick = function () { toggleNw('egaf-nw-ret'); };
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && drawer.classList.contains('open') && !document.getElementById('egaf-pw')) close(); });
        bindNew();
        return drawer;
    }
    function toggleNw(id) { var el = drawer.querySelector('#' + id); el.style.display = el.style.display === 'block' ? 'none' : 'block'; }
    function msg(t, ok) { var m = drawer.querySelector('#egaf-msg'); m.style.color = ok ? '#2a7a2a' : '#b8431f'; m.textContent = t || ''; }
    function promptPw(cb) {
        var old = document.getElementById('egaf-pw'); if (old) old.remove();
        var m = document.createElement('div');
        m.id = 'egaf-pw';
        m.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:10800;display:flex;align-items:center;justify-content:center;';
        m.innerHTML = '<div style="background:#fff;border-radius:6px;padding:16px 18px;width:340px;box-shadow:0 8px 30px rgba(0,0,0,.35);"><div style="font-weight:bold;color:#6B4423;margin-bottom:6px;">管理員修正：輸入操作確認密碼</div>'
            + '<div style="font-size:12px;color:#7a4a10;margin-bottom:8px;">進入後可直接修改這個 BOM 的發單、回廠、QC檢驗、移轉憑單數量與日期，每個動作都會留操作紀錄。</div>'
            + '<input type="password" id="egaf-pw-i" autocomplete="off" style="width:100%;height:30px;padding:0 6px;"><div id="egaf-pw-m" style="color:#b8431f;font-size:12px;min-height:16px;margin-top:4px;"></div>'
            + '<div style="text-align:right;margin-top:6px;"><button class="btn btn-default btn-sm" id="egaf-pw-c">取消</button> <button class="btn btn-warning btn-sm" id="egaf-pw-ok">確認</button></div></div>';
        document.body.appendChild(m);
        var inp = m.querySelector('#egaf-pw-i'); inp.focus();
        var go = function () {
            var v = inp.value; if (!v) { m.querySelector('#egaf-pw-m').textContent = '請輸入密碼'; return; }
            w.jQuery.post('', { action: 'baf_unlock', password: v }, function (r) {
                if (r && r.success) { unlocked = true; m.remove(); cb(); }
                else m.querySelector('#egaf-pw-m').textContent = (r && r.message) || '密碼錯誤';
            }, 'json').fail(function () { m.querySelector('#egaf-pw-m').textContent = '與伺服器通訊失敗'; });
        };
        m.querySelector('#egaf-pw-ok').onclick = go;
        m.querySelector('#egaf-pw-c').onclick = function () { m.remove(); };
        inp.addEventListener('keydown', function (e) { if (e.key === 'Enter') go(); if (e.key === 'Escape') m.remove(); });
    }
    function refreshMain() {
        try { if (typeof w.fetchDataAndFilter === 'function') w.fetchDataAndFilter(); } catch (e) {}
        try { if (typeof w.refreshEditModalProcessList === 'function' && bom) w.refreshEditModalProcessList(bom, bomSn); } catch (e) {}
    }
    function load(keepMsg, ok) {
        var body = drawer.querySelector('#egaf-body'), sc = body.scrollTop;
        post({ action: 'baf_list' }, function (r) {
            if (!r.success) { body.innerHTML = '<div style="color:#b8431f;">' + esc(r.message || '讀取失敗') + '</div>'; return; }
            DATA = r; render(); body.scrollTop = sc;
            if (keepMsg) msg(keepMsg, ok);
        });
    }
    function render() {
        var rows = DATA.rows || [], cnt = {};
        rows.forEach(function (x) { cnt[x.kind] = (cnt[x.kind] || 0) + 1; });
        drawer.querySelector('#egaf-chips').innerHTML = KINDS.map(function (k) {
            var o = on[k[0]];
            return '<span class="chip' + (o ? ' on' : '') + '" data-k="' + k[0] + '" style="' + (o ? 'background:' + COLORS[k[0]] : '') + '">' + k[1] + ' ' + (cnt[k[0]] || 0) + '</span>';
        }).join('');
        drawer.querySelectorAll('.chip').forEach(function (c) { c.onclick = function () { on[c.getAttribute('data-k')] = !on[c.getAttribute('data-k')]; render(); }; });
        var ss = drawer.querySelector('#egaf-st');
        ss.innerHTML = '<option value="">全部製程</option>' + (DATA.stations || []).map(function (s) { return '<option value="' + s.fid + '"' + (String(s.fid) === String(stationFilter) ? ' selected' : '') + '>' + esc(s.label) + '</option>'; }).join('');
        // 新增用的製程預設＝目前篩選的製程
        drawer.querySelector('#egaf-nr-b').innerHTML = (DATA.batches || []).filter(function (b) { return !stationFilter || String(b.fid) === String(stationFilter); }).map(function (b) {
            var stn = (DATA.stations || []).filter(function (s) { return s.fid === b.fid; })[0];
            return '<option value="' + b.batch_id + '" data-f="' + b.fid + '">' + esc((stn ? stn.label : '') + '／' + (b.maker || '未登記') + ' ' + nz(b.send_qty)) + '</option>';
        }).join('');
        var html = '', last = '', shown = 0;
        rows.forEach(function (x) {
            if (!on[x.kind]) return;
            if (stationFilter && String(x.fid) !== String(stationFilter)) return;
            shown++;
            if (x.date !== last) { last = x.date; html += '<div class="dd">' + esc(w.egFmtDate ? w.egFmtDate(x.date) : x.date) + '</div>'; }
            html += rowHtml(x);
        });
        var body = drawer.querySelector('#egaf-body');
        body.innerHTML = '<div style="color:#999;font-size:11px;margin-bottom:2px;">依日期由舊到新，顯示 ' + shown + ' / ' + rows.length + ' 筆；修改後按該列「儲存」。回廠日、檢驗日、移轉日不可晚於今天。</div>' + (html || '<div style="color:#999;padding:12px;">沒有符合的紀錄</div>');
        body.querySelectorAll('.it').forEach(function (it) { bindRow(it); });
    }
    function inp(cls, v, wd, type) { return '<input class="' + cls + '" type="' + (type || 'number') + '" ' + (type === 'date' ? 'max="' + today() + '"' : 'min="0" step="any"') + ' value="' + esc(v) + '" style="width:' + wd + 'px;">'; }
    function rowHtml(x) {
        var h = '<div class="it" data-k="' + x.kind + '" data-id="' + x.id + '" data-f="' + x.fid + '" data-mn="' + esc(x.maker_no || '') + '" data-m="' + esc(x.maker || '') + '">'
            + '<span class="k" style="background:' + COLORS[x.kind] + ';">' + LABELS[x.kind] + '</span><b>' + esc(x.station || '') + '</b>';
        if (x.kind === 'send') {
            h += ' <span style="color:#7a4a10;">' + esc(x.maker || '未登記廠商') + '</span><div class="row2">送出日 ' + inp('f-d', x.date, 125, 'date') + ' 發單數 ' + inp('f-sq', nz(x.send_qty), 70)
              + '<span style="color:#888;">已回 ' + nz(x.return_qty) + '／廢 ' + nz(x.scrap_qty) + '／取回 ' + nz(x.withdraw_qty) + '</span>'
              + '<span style="margin-left:auto;"><button class="btn btn-warning btn-xs f-save">儲存</button> <button class="btn btn-danger btn-xs f-del">刪除</button></span></div>';
        } else if (x.kind === 'return') {
            h += ' <span style="color:#7a4a10;">' + esc(x.maker || '') + '</span>' + (x.note ? ' <span style="color:#999;">（' + esc(x.note) + '）</span>' : '')
              + '<div class="row2">日期 ' + inp('f-d', x.date, 125, 'date') + ' 回廠 ' + inp('f-r', nz(x.return_qty), 60) + ' 報廢 ' + inp('f-s', nz(x.scrap_qty), 60) + ' 取回 ' + inp('f-w', nz(x.withdraw_qty), 60)
              + '<span style="margin-left:auto;"><button class="btn btn-warning btn-xs f-save">儲存</button> <button class="btn btn-danger btn-xs f-del">刪除</button></span></div>';
        } else if (x.kind === 'qc') {
            h += ' <span style="color:#7a4a10;">' + esc(x.result || '') + '</span><div class="row2">檢驗日 ' + inp('f-d', x.date, 125, 'date') + ' 允收 ' + inp('f-ok', x.ok, 55) + ' 特採 ' + inp('f-aod', x.aod, 55) + ' 異常 ' + inp('f-qq', x.qq, 55) + ' 驗退 ' + inp('f-ng', x.ng, 55)
              + '<span style="margin-left:auto;"><button class="btn btn-warning btn-xs f-save">儲存</button></span></div>';
        } else if (x.kind === 'transfer') {
            h += ' <span style="color:#7a4a10;">' + esc(x.no) + '　' + esc(x.from) + (x.to ? ' → ' + esc(x.to) : '') + '</span><div class="row2">移轉日 ' + inp('f-d', x.date, 125, 'date') + ' 數量 ' + inp('f-q', x.qty, 70)
              + '<span style="margin-left:auto;"><button class="btn btn-warning btn-xs f-save">儲存</button></span></div>';
        } else {
            h += '<div class="row2"><span>' + esc(x.no) + '　' + esc(x.text) + (x.ng ? '　不良 ' + nz(x.ng) : '') + '</span><span style="margin-left:auto;"><a href="../QA/qa_abnormal_form.php?id=' + x.id + '" target="_blank" class="btn btn-default btn-xs">開啟異常單</a></span></div>';
        }
        return h + '</div>';
    }
    function bindRow(it) {
        var k = it.getAttribute('data-k'), id = it.getAttribute('data-id'), f = it.getAttribute('data-f');
        function v(c) { var e = it.querySelector('.' + c); return e ? e.value : ''; }
        var sv = it.querySelector('.f-save'), dl = it.querySelector('.f-del');
        if (sv) sv.onclick = function () {
            var d = { action: 'baf_save', kind: k, id: id, fid: f, date: v('f-d') };
            if (k === 'send') { d.send_qty = v('f-sq'); d.maker_no = it.getAttribute('data-mn'); d.maker = it.getAttribute('data-m'); }
            else if (k === 'return') { d.return_qty = v('f-r'); d.scrap_qty = v('f-s'); d.withdraw_qty = v('f-w'); }
            else if (k === 'qc') { d.ok = v('f-ok'); d.aod = v('f-aod'); d.qq = v('f-qq'); d.ng = v('f-ng'); if (!confirm('確定修正這筆QC檢驗？這會直接改變這一站的已驗數量。')) return; }
            else if (k === 'transfer') { d.qty = v('f-q'); if (!confirm('確定修正這張移轉憑單的數量／日期？（移轉帳款分析會跟著變）')) return; }
            post(d, function (r) { if (r.success) { toast('已儲存', true); load('已儲存', true); refreshMain(); } else msg(r.message, false); });
        };
        if (dl) dl.onclick = function () {
            if (!confirm(k === 'send' ? '確定刪除這個發單批次（含它底下所有回廠紀錄）？' : '確定刪除這筆回廠／報廢／取回紀錄？')) return;
            post({ action: 'baf_delete', kind: k, id: id, fid: f }, function (r) { if (r.success) { toast('已刪除', true); load('已刪除', true); refreshMain(); } else msg(r.message, false); });
        };
    }
    function bindNew() {
        var mk = drawer.querySelector('#egaf-mk'), dl = drawer.querySelector('#egaf-mk-dl'), pick = null;
        function find(vv) { var h = null; Array.prototype.forEach.call(dl.options, function (o) { if (o.value === vv) h = { no: o.getAttribute('data-no'), name: o.getAttribute('data-name') }; }); return h; }
        mk.addEventListener('input', function () {
            var vv = mk.value.trim(); pick = find(vv); if (pick || vv.length < 1) return;
            w.jQuery.post('', { action: 'search_maker', term: vv, search_type: /^[A-Za-z0-9-]+$/.test(vv) ? 'no' : 'name' }, function (res) {
                dl.innerHTML = '';
                if (res && res.success && Array.isArray(res.data)) res.data.forEach(function (m) { var o = document.createElement('option'); o.value = m.maker_id_no + ' ' + m.maker_id; o.setAttribute('data-no', m.maker_id_no); o.setAttribute('data-name', m.maker_id); dl.appendChild(o); });
            }, 'json');
        });
        drawer.querySelector('#egaf-ns-go').onclick = function () {
            var pk = find(mk.value.trim()) || pick;
            if (!stationFilter) { msg('請先在「製程」下拉選一個製程，再新增發單', false); return; }
            if (!pk || !pk.no) { msg('請從清單挑選廠商', false); return; }
            post({ action: 'baf_add_send', fid: stationFilter, maker_no: pk.no, maker: pk.name, send_qty: drawer.querySelector('#egaf-ns-q').value, date: drawer.querySelector('#egaf-ns-d').value },
                function (r) { if (r.success) { load('已新增發單', true); refreshMain(); } else msg(r.message, false); });
        };
        drawer.querySelector('#egaf-nr-go').onclick = function () {
            var sel = drawer.querySelector('#egaf-nr-b'), opt = sel.options[sel.selectedIndex];
            if (!opt) { msg('沒有可選的發單批次，請先新增發單', false); return; }
            post({ action: 'baf_add_return', fid: opt.getAttribute('data-f'), batch_id: sel.value, return_qty: drawer.querySelector('#egaf-nr-r').value, scrap_qty: drawer.querySelector('#egaf-nr-s').value,
                   withdraw_qty: drawer.querySelector('#egaf-nr-w').value, date: drawer.querySelector('#egaf-nr-d').value },
                function (r) { if (r.success) { load('已新增', true); refreshMain(); } else msg(r.message, false); });
        };
    }
    function close() { if (drawer) drawer.classList.remove('open'); }
    function open(b, sn) {
        bom = String(b || '').trim(); bomSn = sn || '';
        if (!bom) return;
        var go = function () {
            ensure();
            stationFilter = '';
            drawer.querySelector('#egaf-title').textContent = '管理員修正　' + bom;
            drawer.querySelector('#egaf-body').innerHTML = '<div style="color:#999;">載入中…</div>';
            msg('', true);
            drawer.classList.add('open');
            load();
        };
        if (unlocked) go(); else promptPw(go);
    }
    w.EGBomAdminFix = { open: open, close: close };
})(window);
