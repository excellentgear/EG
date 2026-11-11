/**
 * BOM 管理員修正面板（左側滑出，唯一實作）——BOM總覽「BOM 製程列表」旁的「管理員修正」鈕開啟。
 * 後端：views/pm/OreadyReply_ForPm_BaseOfTime_ajax.php 的 baf_* 動作（src/common/bom_admin_fix_lib.php）。
 * 用法：EGBomAdminFix.open('B-1150407008', bomSn)；需先輸入操作確認密碼。
 * 修改欄位後按右上角「儲存」（一次存全部已改的列）或「儲存並結束」（存完自動上鎖並關閉）；刪除與新增是當下生效。
 * EGBomAdminFix.renderWarn(bom)：在「修改 BOM 資料」視窗的發單數下方顯示總數異常的紅字提示。
 */
(function (w) {
    var viewMode = 'time', drawer = null, bom = '', bomSn = '', DATA = null, unlocked = false;
    var KINDS = [['send', '發單'], ['return', '回廠／報廢／取回'], ['qc', 'QC檢驗'], ['transfer', '移轉憑單'], ['abnormal', '異常單'], ['ing', 'ERP匯入/未採用']];
    var COLORS = { send: '#F0A24B', return: '#2E8B57', qc: '#8A6A45', transfer: '#C0561F', abnormal: '#DD5138', ing: '#8B8B8B' };
    var LABELS = { send: '發單', return: '回廠', qc: 'QC檢驗', transfer: '移轉憑單', abnormal: '異常單', ing: 'ERP匯入/未採用' };
    var STATE_TXT = { N: '待發包', Q: 'QC待驗', P: '待移轉', ing: '加工中', E: '已移轉', '1': '已結案', skip: '跳過' };
    var on = { send: true, return: true, qc: true, transfer: true, abnormal: true, ing: true };
    var stationFilter = '';
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function nz(v) { v = parseFloat(v) || 0; return (Math.round(v * 1000) / 1000).toString(); }
    function today() { var d = new Date(), p = function (n) { return (n < 10 ? '0' : '') + n; }; return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()); }
    function toast(t, ok) { if (typeof w.showTemporaryMessage === 'function') w.showTemporaryMessage(t, !!ok); }
    function post(data, cb, bomOverride) {
        data.bom = bomOverride || bom;
        w.jQuery.post('', data, function (r) {
            if (r && r.need_unlock) { unlocked = false; promptPw(function () { post(data, cb, bomOverride); }); return; }
            cb(r || { success: false, message: '無回應' });
        }, 'json').fail(function () { cb({ success: false, message: '與伺服器通訊失敗' }); });
    }
    // ── 修改視窗「發單數」下方的總數異常提示（只有管理員看得到；後端也限管理員）──
    function warnHtml(r) {
        if (!r || !r.items || !r.items.length) return '';
        // 只列「超過發單數最多」的那一關
        var top = r.items.reduce(function (a, b) { return (parseFloat(b.max) > parseFloat(a.max)) ? b : a; });
        return '<b>總數異常：</b><br>' + esc(top.label) + '：最多 ' + esc(top.max_name) + ' = ' + nz(top.max) + ' pcs（發單 ' + nz(top.send) + '／移轉憑單 ' + nz(top.transfer) + '／QC檢驗 ' + nz(top.qc) + '）';
    }
    function renderWarn(b, tries) {
        tries = tries || 0;
        var el = document.getElementById('baf-warn');
        if (!el) { if (tries < 8) setTimeout(function () { renderWarn(b, tries + 1); }, 200); return; }
        w.jQuery.post('', { action: 'baf_check', bom: b }, function (r) { var e2 = document.getElementById('baf-warn'); if (e2 && r && r.success) e2.innerHTML = warnHtml(r); }, 'json');
    }
    // ── 廠商挑選：輸入時即時在下方列出符合的廠商，點選即帶入（不用自己按按鈕）──
    function makerPicker(input, onPick) {
        var box = document.createElement('div'), timer = null, seq = 0;
        box.style.cssText = 'position:fixed;z-index:10900;background:#fff;border:1px solid #d9b98a;border-radius:3px;box-shadow:0 4px 14px rgba(0,0,0,.2);max-height:220px;overflow-y:auto;display:none;font-size:12px;';
        document.body.appendChild(box);
        function hide() { box.style.display = 'none'; }
        function show(list) {
            if (!list.length) { box.innerHTML = '<div style="padding:6px 10px;color:#999;">查無符合的廠商</div>'; }
            else box.innerHTML = list.map(function (m, i) { return '<div class="mk-o" data-i="' + i + '" style="padding:4px 10px;cursor:pointer;border-bottom:1px solid #f3e9d8;"><b>' + esc(m.maker_id_no) + '</b>　' + esc(m.maker_id) + '</div>'; }).join('');
            var r = input.getBoundingClientRect();
            box.style.left = r.left + 'px'; box.style.top = (r.bottom + 2) + 'px'; box.style.minWidth = Math.max(220, r.width) + 'px'; box.style.display = 'block';
            box.querySelectorAll('.mk-o').forEach(function (o) {
                o.onmousedown = function (e) { e.preventDefault(); var m = list[parseInt(o.getAttribute('data-i'), 10)]; input.value = m.maker_id_no + ' ' + m.maker_id; hide(); onPick({ no: m.maker_id_no, name: m.maker_id }); };
            });
        }
        function q() {
            var v = input.value.trim(); onPick(null);
            if (v.length < 1) { hide(); return; }
            var my = ++seq;
            w.jQuery.post('', { action: 'search_maker', term: v, search_type: /^[A-Za-z0-9-]+$/.test(v) ? 'no' : 'name' }, function (res) {
                if (my !== seq) return;
                show(res && res.success && Array.isArray(res.data) ? res.data.slice(0, 30) : []);
            }, 'json');
        }
        input.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(q, 150); });
        input.addEventListener('focus', function () { if (input.value.trim() && !/^\S+ \S+/.test(input.value)) q(); });
        input.addEventListener('blur', function () { setTimeout(hide, 150); });
    }
    function ensure() {
        if (drawer) return drawer;
        var st = document.createElement('style');
        st.textContent = '#egaf-drawer input[type=number]::-webkit-inner-spin-button,#egaf-drawer input[type=number]::-webkit-outer-spin-button{-webkit-appearance:none;margin:0;}#egaf-drawer input[type=number]{-moz-appearance:textfield;appearance:textfield;}'
            + '#egaf-drawer{position:fixed;top:0;left:0;height:100%;width:620px;max-width:96vw;background:#fff;z-index:10700;box-shadow:4px 0 18px rgba(0,0,0,.22);transform:translateX(-105%);transition:transform .22s ease;display:flex;flex-direction:column;font-size:13px;color:#4a3320;}'
            + '#egaf-drawer.open{transform:translateX(0);}'
            + '#egaf-drawer .h{padding:10px 14px;border-bottom:1px solid #E4B3A0;background:#F6D9CC;display:flex;justify-content:space-between;align-items:center;gap:8px;}'
            + '#egaf-drawer .f{padding:8px 14px;border-bottom:1px solid #E4D3BC;background:#FDF3E3;}'
            + '#egaf-drawer .b{flex:1;overflow-y:auto;padding:8px 14px 24px;}'
            + '#egaf-drawer .chip{display:inline-block;border:1px solid #E4D3BC;border-radius:12px;padding:1px 10px;margin:0 4px 4px 0;cursor:pointer;background:#fff;font-size:12px;user-select:none;}'
            + '#egaf-drawer .chip.on{color:#fff;border-color:transparent;}'
            + '#egaf-drawer .dd{font-weight:bold;margin:10px 0 4px;color:#7a4a10;border-bottom:1px dotted #E4D3BC;}'
            + '#egaf-drawer .it{border:1px solid #EEE0CC;border-radius:4px;padding:5px 8px;margin-bottom:5px;background:#FFFCF7;}'
            + '#egaf-drawer .it.dirty{background:#FFF1C9;border-color:#E8B54A;}'
            + '#egaf-drawer .it .k{display:inline-block;color:#fff;border-radius:3px;padding:0 6px;font-size:11px;line-height:18px;margin-right:6px;}'
            + '#egaf-drawer .it input{padding:0 3px;height:24px;font-size:12px;border:1px solid #d8c8b0;border-radius:3px;}'
            + '#egaf-drawer .it .row2{margin-top:4px;display:flex;flex-wrap:wrap;gap:6px;align-items:center;font-size:12px;}'
            + '#egaf-drawer .nw{display:none;border:1px dashed #E4D3BC;border-radius:4px;padding:6px 8px;margin-top:6px;background:#fff;font-size:12px;}'
            + '#egaf-drawer .nw input,#egaf-drawer .nw select{height:24px;font-size:12px;padding:0 3px;}'
            + '#egaf-drawer.wide{width:96vw;}'
            + '#egaf-drawer .tn{display:flex;flex-direction:column;align-items:center;}'
            + '#egaf-drawer .tk-col{display:flex;flex-direction:row;justify-content:center;position:relative;padding-top:14px;}'
            + '#egaf-drawer .tk-col::before{content:"";position:absolute;top:0;left:50%;height:14px;border-left:2px solid #C0561F;}'
            + '#egaf-drawer .tk{position:relative;padding:14px 6px 0;display:flex;flex-direction:column;align-items:center;}'
            + '#egaf-drawer .tk::before{content:"";position:absolute;top:0;left:50%;height:14px;border-left:2px solid #C0561F;}'
            + '#egaf-drawer .tk::after{content:"";position:absolute;top:0;left:0;right:0;border-top:2px solid #C0561F;}'
            + '#egaf-drawer .tk:first-child::after{left:50%;}'
            + '#egaf-drawer .tk:last-child::after{right:50%;}'
            + '#egaf-drawer .tk:only-child::after{display:none;}'
            + '#egaf-drawer .flw{display:flex;align-items:flex-start;gap:10px;height:calc(100vh - 250px);min-height:300px;}'
            + '#egaf-drawer .flw-main{flex:1;min-width:0;height:100%;overflow:auto;padding-bottom:8px;}'
            + '#egaf-drawer .flw-main > div{width:max-content;min-width:100%;box-sizing:border-box;}'
            + '#egaf-drawer .flw-side{width:272px;flex:0 0 272px;height:100%;overflow-y:auto;overflow-x:hidden;box-sizing:border-box;}'
            + '#egaf-drawer .ecard{width:236px;flex:0 0 auto;box-sizing:border-box;font-size:11px;line-height:1.35;padding:3px 6px;}'
            + '#egaf-drawer .ecard .it{margin:0 0 2px;padding:1px 2px;border:none;background:none;}'
            + '#egaf-drawer .ecard .it.dirty{background:#FFF1C9;}'
            + '#egaf-drawer .ecard .it .k,#egaf-drawer .ecard .it>b{display:none;}'
            + '#egaf-drawer .ecard .it .row2{margin-top:1px;gap:3px;font-size:11px;}'
            + '#egaf-drawer .ecard input[type=date]{width:106px!important;font-size:11px;}'
            + '#egaf-drawer .ecard input[type=number]{width:54px!important;font-size:11px;}'
            + '#egaf-drawer .ecard select{font-size:11px;height:20px;width:100%;max-width:none;}'
            + '#egaf-drawer .ecard .btn{padding:0 5px;font-size:11px;line-height:18px;}'
            + '#egaf-drawer .flw-side .ecard{width:100%;}'
            + '#egaf-drawer .gp{white-space:nowrap;}'
            + '#egaf-drawer .ecard .gp.nl{flex-basis:100%;}'
            + '#egaf-drawer .tcard{width:230px;flex:0 0 auto;box-sizing:border-box;}'
            + '#egaf-drawer .fl-st{border:1px solid #E4B98A;border-radius:5px;margin:8px 0;background:#FFFCF7;}'
            + '#egaf-drawer .fl-h{background:#F6E2C6;padding:4px 8px;font-weight:bold;color:#6B4423;border-radius:5px 5px 0 0;}'
            + '#egaf-drawer .fl-b{margin:6px 8px 6px 14px;border-left:4px solid #F0A24B;background:#fff;padding:4px 8px;border-radius:0 4px 4px 0;}'
            + '#egaf-drawer .fl-t.over,#egaf-drawer .fl-b.over,#egaf-drawer .fl-un.over{outline:2px dashed #C0561F;background:#FFF1C9;}'
            + '#egaf-drawer .fl-t{margin:4px 0 4px 16px;border-left:4px solid #C0561F;background:#FDF3E3;padding:3px 8px;border-radius:0 4px 4px 0;cursor:grab;font-size:12px;}'
            + '#egaf-drawer .fl-t select{height:22px;font-size:11px;max-width:200px;}'
            + '#egaf-drawer .fl-in{margin:3px 0 3px 16px;color:#2E6B3F;font-size:12px;border-left:4px solid #2E8B57;padding-left:8px;}'
            + '#egaf-drawer .fl-un{margin:6px 8px;border:1px dashed #C0561F;padding:2px 4px;border-radius:4px;}'
            + '#egaf-drawer .tg{display:inline-block;border-radius:3px;padding:0 5px;font-size:10px;color:#fff;margin-left:4px;}'
            + '#egaf-drawer .wn{background:#FDECEA;border:1px solid #E9A59C;color:#c0261b;border-radius:4px;padding:6px 10px;margin-bottom:6px;font-size:12px;line-height:1.6;}';
        document.head.appendChild(st);
        drawer = document.createElement('div');
        drawer.id = 'egaf-drawer';
        drawer.innerHTML = '<div class="h"><strong id="egaf-title">管理員修正</strong><span style="white-space:nowrap;"><span id="egaf-dn" style="color:#b8431f;font-size:12px;margin-right:6px;"></span>'
            + '<button type="button" id="egaf-save" class="btn btn-warning btn-xs">儲存</button> <button type="button" id="egaf-saveend" class="btn btn-danger btn-xs">儲存並結束</button> '
            + '<button type="button" id="egaf-x" style="border:none;background:none;font-size:20px;cursor:pointer;color:#999;line-height:1;" title="關閉（不上鎖）">×</button></span></div>'
            + '<div class="f"><div id="egaf-chips"></div><div style="margin-top:2px;">製程：<select id="egaf-st" style="height:24px;font-size:12px;"></select> '
            + '<span style="margin-right:8px;"><button type="button" class="btn btn-default btn-xs" id="egaf-v-time">時間軸</button><button type="button" class="btn btn-default btn-xs" id="egaf-v-flow">分支</button></span> <label style="margin:0 8px 0 0;font-weight:normal;cursor:pointer;" title="ERP 匯入的移轉憑單，預設鎖定日期／數量；取消勾選才能改（改了會同步更新移轉憑單）"><input type="checkbox" id="egaf-lock" checked style="height:auto;"> 鎖定ERP移轉憑單</label><button type="button" class="btn btn-success btn-xs" id="egaf-new-send">＋新增發單</button> <button type="button" class="btn btn-success btn-xs" id="egaf-new-ret">＋新增回廠／報廢</button></div>'
            + '<div class="nw" id="egaf-nw-send">廠商 <input id="egaf-mk" autocomplete="off" style="width:190px;" placeholder="輸入編號或名稱，下方即時列出"> 數量 <input type="number" min="0" step="any" id="egaf-ns-q" style="width:70px;"> 送出日 <input type="date" id="egaf-ns-d" style="width:125px;"> <button type="button" class="btn btn-warning btn-xs" id="egaf-ns-go">新增</button></div>'
            + '<div class="nw" id="egaf-nw-ret">發單批次 <select id="egaf-nr-b" style="max-width:200px;"></select> 回廠 <input type="number" min="0" step="any" id="egaf-nr-r" style="width:60px;"> 報廢 <input type="number" min="0" step="any" id="egaf-nr-s" style="width:60px;"> 取回 <input type="number" min="0" step="any" id="egaf-nr-w" style="width:60px;"> 日期 <input type="date" id="egaf-nr-d" style="width:125px;"> <button type="button" class="btn btn-warning btn-xs" id="egaf-nr-go">新增</button></div>'
            + '<div id="egaf-msg" style="min-height:16px;font-size:12px;font-weight:bold;margin-top:3px;"></div></div>'
            + '<div class="b" id="egaf-body"></div>';
        document.body.appendChild(drawer);
        drawer.querySelector('#egaf-x').onclick = function () { if (dirtyCount() && !confirm('有 ' + dirtyCount() + ' 筆修改尚未儲存，確定直接關閉？')) return; close(); };
        drawer.querySelector('#egaf-save').onclick = function () { saveAll(false); };
        drawer.querySelector('#egaf-saveend').onclick = function () { saveAll(true); };
        drawer.querySelector('#egaf-v-time').onclick = function () { viewMode = 'time'; render(); };
        drawer.querySelector('#egaf-lock').onchange = applyLock;
        // 拖放時滑鼠靠近捲動區的上下左右邊緣就自動捲動（拖曳中滾輪常常無效）
        drawer.addEventListener('dragover', function (e) {
            var zone = 60, step = 18;
            var cs = [drawer.querySelector('.flw-main'), drawer.querySelector('.flw-side'), drawer.querySelector('#egaf-body')];
            for (var i = 0; i < cs.length; i++) {
                var c = cs[i]; if (!c) continue;
                var r = c.getBoundingClientRect();
                if (e.clientX < r.left || e.clientX > r.right || e.clientY < r.top || e.clientY > r.bottom) continue;
                if (e.clientY - r.top < zone) c.scrollTop -= step; else if (r.bottom - e.clientY < zone) c.scrollTop += step;
                if (e.clientX - r.left < zone) c.scrollLeft -= step; else if (r.right - e.clientX < zone) c.scrollLeft += step;
                break;
            }
        });
        drawer.querySelector('#egaf-v-flow').onclick = function () { viewMode = 'flow'; render(); };
        drawer.querySelector('#egaf-st').onchange = function () { stationFilter = this.value; delete drawer.querySelector('#egaf-ns-q').dataset.t; delete drawer.querySelector('#egaf-nr-r').dataset.t; render(); };
        drawer.querySelector('#egaf-new-send').onclick = function () { toggleNw('egaf-nw-send'); };
        drawer.querySelector('#egaf-new-ret').onclick = function () { toggleNw('egaf-nw-ret'); };
        drawer.querySelector('#egaf-body').addEventListener('input', function (e) {
            var it = e.target.closest && e.target.closest('.it'); if (!it || e.target.closest('.qs') || e.target.closest('.al')) return;
            it.classList.add('dirty'); updDn();
        });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && drawer.classList.contains('open') && !document.getElementById('egaf-pw')) close(); });
        bindNew();
        return drawer;
    }
    // 新增發單／回廠的數量預設值：發單＝上一關有紀錄的數量（優先回廠量，沒有才用發單量）；回廠＝選定批次尚未回廠的量。使用者手動改過的欄位不覆蓋
    function prevQty() {
        var sts = (DATA.stations || []).slice().sort(function (a, b) { return (a.sn || 0) - (b.sn || 0); }), cur = null;
        sts.forEach(function (s) { if (String(s.fid) === String(stationFilter)) cur = s; });
        if (!cur) return '';
        for (var i = sts.length - 1; i >= 0; i--) {
            if (!((sts[i].sn || 0) < (cur.sn || 0))) continue;
            var ret = 0, snd = 0;
            (DATA.rows || []).forEach(function (r) {
                if (String(r.fid) !== String(sts[i].fid)) return;
                if (r.kind === 'return') ret += parseFloat(r.return_qty) || 0;
                else if (r.kind === 'send') snd += parseFloat(r.send_qty) || 0;
            });
            if (ret > 0) return nz(ret);
            if (snd > 0) return nz(snd);
        }
        return '';
    }
    function leftOfBatch() {
        var sel = drawer.querySelector('#egaf-nr-b'), id = sel && sel.value, b = null;
        (DATA.batches || []).forEach(function (x) { if (String(x.batch_id) === String(id)) b = x; });
        if (!b) return '';
        var used = 0;
        (DATA.rows || []).forEach(function (r) { if (r.kind === 'return' && String(r.batch_id) === String(id)) used += (parseFloat(r.return_qty) || 0) + (parseFloat(r.scrap_qty) || 0) + (parseFloat(r.withdraw_qty) || 0); });
        var left = (parseFloat(b.send_qty) || 0) - used;
        return left > 0 ? nz(left) : '';
    }
    function autoFill() {
        var q = drawer.querySelector('#egaf-ns-q'), r = drawer.querySelector('#egaf-nr-r');
        if (q && !q.dataset.t) q.value = prevQty();
        if (r && !r.dataset.t) r.value = leftOfBatch();
    }
    function dirtyCount() { return drawer ? drawer.querySelectorAll('.it.dirty').length : 0; }
    function updDn() { var n = dirtyCount(); drawer.querySelector('#egaf-dn').textContent = n ? n + ' 筆未儲存' : ''; }
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
        renderWarn(bom);
    }
    function load(keepMsg, ok) {
        var body = drawer.querySelector('#egaf-body'), sc = body.scrollTop;
        // 重畫會整塊換掉內容：先記下分支檢視內層捲動位置與游標所在欄位，畫完還原，避免儲存後畫面跳走、焦點遺失
        var fm0 = body.querySelector('.flw-main'), fmT = fm0 ? fm0.scrollTop : 0, fmL = fm0 ? fm0.scrollLeft : 0, bL = body.scrollLeft, fk = null;
        try {
            var ae = document.activeElement, row = ae && body.contains(ae) && ae.closest ? ae.closest('.it') : null;
            if (row && ae.className) fk = { k: row.getAttribute('data-k'), id: row.getAttribute('data-id'), cls: String(ae.className).split(/\s+/).filter(function (c) { return /^f-/.test(c); })[0] };
        } catch (e0) {}
        post({ action: 'baf_list' }, function (r) {
            if (!r.success) { body.innerHTML = '<div style="color:#b8431f;">' + esc(r.message || '讀取失敗') + '</div>'; return; }
            DATA = r; render(); body.scrollTop = sc; body.scrollLeft = bL;
            var fm1 = body.querySelector('.flw-main'); if (fm1) { fm1.scrollTop = fmT; fm1.scrollLeft = fmL; }
            if (fk && fk.cls) {
                try {
                    var tr = body.querySelector('.it[data-k="' + fk.k + '"][data-id="' + fk.id + '"] .' + fk.cls);
                    if (tr && !tr.disabled && !tr.readOnly) tr.focus({ preventScroll: true });
                } catch (e1) {}
            }
            if (keepMsg) msg(keepMsg, ok);
        });
    }
    // 篩選／切換檢視也會重畫：保留面板與分支檢視內層的捲動位置
    function render() {
        var body = drawer.querySelector('#egaf-body'), fm0 = body.querySelector('.flw-main'), keep = !!DATA && body.querySelector('.flw,.it');
        var sT = body.scrollTop, sL = body.scrollLeft, fT = fm0 ? fm0.scrollTop : 0, fL = fm0 ? fm0.scrollLeft : 0;
        renderInner();
        if (!keep) return;
        body.scrollTop = sT; body.scrollLeft = sL;
        var fm1 = body.querySelector('.flw-main'); if (fm1 && fm0) { fm1.scrollTop = fT; fm1.scrollLeft = fL; }
    }
    function renderInner() {
        var rows = DATA.rows || [], cnt = {};
        rows.forEach(function (x) { cnt[x.kind] = (cnt[x.kind] || 0) + 1; });
        drawer.querySelector('#egaf-chips').innerHTML = KINDS.map(function (k) {
            var o = on[k[0]];
            return '<span class="chip' + (o ? ' on' : '') + '" data-k="' + k[0] + '" style="' + (o ? 'background:' + COLORS[k[0]] : '') + '">' + k[1] + ' ' + (cnt[k[0]] || 0) + '</span>';
        }).join('');
        drawer.querySelectorAll('.chip').forEach(function (c) { c.onclick = function () { keepDirtyWarn(); on[c.getAttribute('data-k')] = !on[c.getAttribute('data-k')]; render(); }; });
        var ss = drawer.querySelector('#egaf-st');
        ss.innerHTML = '<option value="">全部製程</option>' + (DATA.stations || []).map(function (s) { return '<option value="' + s.fid + '"' + (String(s.fid) === String(stationFilter) ? ' selected' : '') + '>' + esc(s.label) + '</option>'; }).join('');
        drawer.querySelector('#egaf-nr-b').innerHTML = (DATA.batches || []).filter(function (b) { return !stationFilter || String(b.fid) === String(stationFilter); }).map(function (b) {
            var stn = (DATA.stations || []).filter(function (s) { return s.fid === b.fid; })[0];
            return '<option value="' + b.batch_id + '" data-f="' + b.fid + '">' + esc((stn ? stn.label : '') + '／' + (b.maker || '未登記') + ' ' + nz(b.send_qty)) + '</option>';
        }).join('');
        DATA._def = calcDeficit();
        autoFill();
        drawer.querySelector('#egaf-v-time').style.fontWeight = viewMode === 'time' ? 'bold' : 'normal'; drawer.querySelector('#egaf-v-time').style.background = viewMode === 'time' ? '#F6D9CC' : '';
        drawer.querySelector('#egaf-v-flow').style.fontWeight = viewMode === 'flow' ? 'bold' : 'normal'; drawer.querySelector('#egaf-v-flow').style.background = viewMode === 'flow' ? '#F6D9CC' : '';
        drawer.classList.toggle('wide', viewMode === 'flow');
        if (viewMode === 'flow') { renderFlow(); return; }
        var html = '', last = null, shown = 0;
        rows.forEach(function (x) {
            if (!on[x.kind]) return;
            if (stationFilter && String(x.fid) !== String(stationFilter)) return;
            shown++;
            if (x.date !== last) { last = x.date; html += '<div class="dd">' + esc(x.date === '0000-00-00' ? '無日期' : (w.egFmtDate ? w.egFmtDate(x.date) : x.date)) + '</div>'; }
            html += rowHtml(x);
        });
        var body = drawer.querySelector('#egaf-body');
        body.innerHTML = snBoxHtml() + '<div style="color:#999;font-size:11px;margin-bottom:2px;">依日期由舊到新，顯示 ' + shown + ' / ' + rows.length + ' 筆；改完按右上角「儲存」。回廠日、檢驗日、移轉日不可晚於今天。</div>' + html
            + (shown ? '' : '<div style="color:#999;padding:12px;">沒有符合的紀錄</div>');
        body.querySelectorAll('.it').forEach(function (it) { bindRow(it); });
        bindSnBox(body);
        applyLock();
        updDn();
    }
    // ── 分支檢視：每關 → 發單批次 → 它的回廠與出去的移轉憑單 → 接到下一關哪個發單。流向是系統依日期＋數量推測的，可用下拉或拖放修正 ──
    function renderFlow() {
        var rows = DATA.rows || [], flow = DATA.flow || {}, sts = DATA.stations || [], body = drawer.querySelector('#egaf-body');
        var sends = {}, stOf = {};
        sts.forEach(function (s) { stOf[s.fid] = s; });
        rows.forEach(function (r) { if (r.kind === 'send') sends[r.id] = r; });
        function bLabel(b) { return (stOf[b.fid] ? stOf[b.fid].label : '') + '／' + (b.maker || '未登記') + ' ' + nz(b.send_qty) + '（' + b.date.slice(5).replace('-', '/') + '）'; }
        function tag(auto) { return '<span class="tg" style="background:' + (auto ? '#8B8B8B' : '#2E8B57') + ';">' + (auto ? '推測' : '手動') + '</span>'; }
        function chip(t) {
            var fl = flow[t.id] || { src: 0, dst: 0, src_auto: true, dst_auto: true };
            var so = '<option value="a">自動推測</option><option value="0"' + (!fl.src_auto && !fl.src ? ' selected' : '') + '>無（不屬於任何發單）</option>';
            var dos = '<option value="a">自動推測</option><option value="0"' + (!fl.dst_auto && !fl.dst && !fl.dst_fid ? ' selected' : '') + '>無（沒有接續發單）</option>';
            Object.keys(sends).forEach(function (id) {
                var b = sends[id];
                if (b.sn === t.sn) so += '<option value="' + id + '"' + (!fl.src_auto && fl.src === b.id ? ' selected' : '') + '>' + esc(bLabel(b)) + '</option>';
                else if (b.sn > t.sn) dos += '<option value="' + id + '"' + (!fl.dst_auto && fl.dst === b.id ? ' selected' : '') + '>' + esc(bLabel(b)) + '</option>';
            });
            // 接續到「某一關」（該關還沒有發單批次也能選）：SN 比這張憑單所屬關卡大、且不是已併入別關的 SN
            sts.forEach(function (s) {
                if (s.sn > t.sn && s.raw_sn === s.sn) dos += '<option value="f' + s.fid + '"' + (fl.dst_fid === s.fid ? ' selected' : '') + '>→ ' + esc(s.label) + '（' + esc(t.to || '廠商未登記') + '）</option>';
            });
            // 自動推測時，下拉要顯示目前實際推到哪裡（selected 放在 a 之外的實際批次上會讓使用者誤以為是手動，所以改在文字說明）
            var dstTxt = fl.dst && sends[fl.dst] ? '→ 接 ' + esc(bLabel(sends[fl.dst])) : '→ <span style="color:#999;">沒有接續的發單</span>';
            return '<div class="fl-t ecard" style="margin:0;" draggable="true" title="可拖到發單卡片改流向" data-tid="' + t.id + '" data-sn="' + t.sn + '" data-fid="' + t.fid + '">'
                + '<div><span style="color:#fff;background:#C0561F;border-radius:3px;padding:0 5px;font-size:11px;">' + esc(t.proc || '未知製程') + (t.orphan ? '（SN ' + t.raw_sn + '）' : '') + '</span> ' + tag(fl.src_auto && fl.dst_auto) + '</div>'
                + rowHtml(t, true)
                + '<div style="display:flex;gap:3px;margin-top:1px;" title="' + esc(dstTxt.replace(/<[^>]+>/g, '')) + '"><select class="fl-sel" data-f="src" data-tid="' + t.id + '" style="width:50%;" title="來源發單">' + so.replace('自動推測', '來源：自動').replace('無（不屬於任何發單）', '來源：無') + '</select><select class="fl-sel" data-f="dst" data-tid="' + t.id + '" style="width:50%;" title="接續發單">' + dos.replace('自動推測', '接續：自動').replace('無（沒有接續發單）', '接續：無') + '</select></div>'
                + '<div style="margin-top:2px;"><button type="button" class="fl-split" data-tid="' + t.id + '" style="font-size:11px;padding:0 6px;border:1px solid #C0561F;background:' + (fl.alloc && fl.alloc.length ? '#C0561F;color:#fff' : '#FFF6E8;color:#C0561F') + ';border-radius:3px;cursor:pointer;" title="這張憑單的數量要分給好幾個發單批次時使用（不會改動 ERP 憑單）">' + (fl.alloc && fl.alloc.length ? '已拆分 ' + fl.alloc.length + ' 批（修改）' : '⇉ 拆給多個批次') + '</button></div></div>';
        }
        var html = '<div style="color:#999;font-size:11px;margin-bottom:2px;">分支檢視：移轉憑單掛在它來自的發單批次下；<b>灰色「推測」</b>是系統依日期＋數量排的，不對就拖到正確的發單卡片上，或用下拉改。卡片上可直接改日期／數量、刪除、快速補發單，改完按右上角「儲存」。SN 對應請切到「時間軸」設定。</div>';
        // 樹狀：從第一關的發單批次出發，沿著「移轉憑單 → 接續發單」一路往下長；一批分給好幾家就分岔，合流到同一批時只畫一次
        var tfs = rows.filter(function (r) { return r.kind === 'transfer'; }), bsAll = Object.keys(sends).map(function (k) { return sends[k]; });
        var isDst = {};
        tfs.forEach(function (t) { var fl = flow[t.id]; if (fl && fl.dst) isDst[fl.dst] = true; if (fl && fl.alloc) fl.alloc.forEach(function (a) { isDst[a.batch_id] = true; }); });
        var bpar = DATA.bparent || {};
        Object.keys(bpar).forEach(function (k) { if (sends[k] && sends[bpar[k]]) isDst[k] = true; }); // 手動接在前一關批次底下的，不再當起點
        bsAll.sort(function (x, y) { return (x.sn - y.sn) || (x.date < y.date ? -1 : x.date > y.date ? 1 : x.id - y.id); });
        var seen = {};
        // 橫向樹：左邊是上一關，右邊是它長出去的分支；每個分支一欄、用線接回上一個節點
        function bnode(b) {
            seen[b.id] = true;
            var h = '<div class="fl-b ecard" style="margin:0;" draggable="true" title="可拖到上一關的移轉憑單卡片上，設為它的接續發單" data-batch="' + b.id + '" data-sn="' + b.sn + '" data-fid="' + b.fid + '"><div style="margin-bottom:1px;"><span style="background:#C0561F;color:#fff;border-radius:3px;padding:0 5px;font-size:11px;">' + esc(stOf[b.fid] ? stOf[b.fid].label : '') + '</span> <b>' + esc(b.maker || '未登記廠商') + '</b></div>' + rowHtml(b)
                + rows.filter(function (r) { return r.kind === 'return' && r.batch_id === b.id; }).map(function (r) { return rowHtml(r); }).join('') + '</div>';
            var outs = tfs.filter(function (t) { return flow[t.id] && flow[t.id].src === b.id; });
            var kids = outs.map(function (t) { return '<div class="tk">' + tnode(t) + '</div>'; }).join('');
            // 沒有憑單可掛、由管理員直接拖到這一批底下的下一關發單批次
            Object.keys(bpar).forEach(function (k) {
                if (String(bpar[k]) !== String(b.id) || !sends[k]) return;
                var cb = sends[k];
                kids += '<div class="tk"><div style="font-size:11px;color:#C0561F;text-align:center;margin-bottom:2px;">手動接上（可拖到上方空白處解除）</div>' + (seen[cb.id] ? '<div class="fl-in tcard" data-merge="' + cb.id + '" style="margin:0;border:2px solid #2E8B57;background:#EAF5EE;padding:2px 8px;font-size:11px;color:#2E8B57;font-weight:bold;">⤵ 合流（見連線）</div>' : bnode(cb)) + '</div>';
            });
            return '<div class="tn">' + h + (kids ? '<div class="tk-col">' + kids + '</div>' : '') + '</div>';
        }
        function tnode(t) {
            var fl = flow[t.id], d = fl.dst && sends[fl.dst], kid = '';
            if (fl.alloc && fl.alloc.length) {
                // 管理員把這張憑單的數量拆給多個發單批次：每個批次各一條分支，標出分配的件數
                kid = fl.alloc.map(function (a) {
                    var dd = sends[a.batch_id]; if (!dd) return '';
                    var lab = '<div style="font-size:11px;color:#C0561F;font-weight:bold;text-align:center;margin-bottom:2px;">分配 ' + nz(a.qty) + ' 件</div>';
                    return '<div class="tk">' + lab + (seen[dd.id] ? '<div class="fl-in tcard" data-merge="' + dd.id + '" style="margin:0;border:2px solid #2E8B57;background:#EAF5EE;padding:2px 8px;font-size:11px;color:#2E8B57;font-weight:bold;">⤵ 合流（見連線）</div>' : bnode(dd)) + '</div>';
                }).join('');
            }
            else if (!d && fl.dst_fid && stOf[fl.dst_fid]) kid = '<div class="tk"><div class="fl-in tcard" style="margin:0;padding:3px 8px;font-size:12px;border:1px dashed #C0561F;background:#FFF6E8;"><b>' + esc(stOf[fl.dst_fid].label) + '</b> ／ ' + esc(t.to || '廠商未登記') + '<br><span style="color:#888;font-size:11px;">尚未發單</span></div></div>';
            else if (d) kid = seen[d.id] ? '<div class="tk"><div class="fl-in tcard" data-merge="' + d.id + '" style="margin:0;border:2px solid #2E8B57;background:#EAF5EE;padding:2px 8px;font-size:11px;color:#2E8B57;font-weight:bold;">⤵ 合流（見連線）</div></div>' : '<div class="tk">' + bnode(d) + '</div>';
            return '<div class="tn">' + chip(t) + (kid ? '<div class="tk-col">' + kid + '</div>' : '') + '</div>';
        }
        html += '<div class="flw"><div class="flw-main">';
        // 第一關的所有發單批次（含併入第一關的 SN）是同一關下的不同分支：共用一個「第1關」根節點，各批往下各自長
        var roots = bsAll.filter(function (b) { return !isDst[b.id]; }), firstSn = sts[0] && sts[0].sn;
        function rootGroup(list, title, warn) {
            if (!list.length) return '';
            return '<div style="margin:8px 0;padding:6px 0;border-bottom:1px dashed #E4D3BC;"><div class="tn"><div class="fl-b" style="margin:0;padding:3px 12px;font-weight:bold;' + (warn ? 'border-left-color:#b8431f;color:#b8431f;' : '') + '">' + esc(title) + '</div><div class="tk-col">'
                + list.map(function (b) { return '<div class="tk">' + bnode(b) + '</div>'; }).join('') + '</div></div></div>';
        }
        var firstList = roots.filter(function (b) { return b.sn === firstSn; }), otherList = roots.filter(function (b) { return b.sn !== firstSn; });
        html += rootGroup(firstList, (sts[0] ? sts[0].label.replace(/（SN d+）/, '') : '第1關'), false);
        html += rootGroup(otherList, '起點不明（找不到上一關接過來的移轉憑單）', true);
        // 來源發單不明、但已接到下一關發單的移轉憑單：畫在樹上（連到它接的那批），可再把它拖到來源發單上補齊來源
        var linkedNoSrc = tfs.filter(function (t) { var fl = flow[t.id]; return fl && !fl.src && fl.dst && sends[fl.dst]; });
        if (linkedNoSrc.length) html += '<div style="margin:8px 0;padding:6px 0;border-bottom:1px dashed #E4D3BC;"><div class="tn"><div class="fl-b" style="margin:0;padding:3px 12px;font-weight:bold;border-left-color:#b8431f;color:#b8431f;">來源發單不明的移轉憑單（已接到下一關，可拖到來源發單補齊）</div><div class="tk-col">'
            + linkedNoSrc.map(function (t) { return '<div class="tk">' + tnode(t) + '</div>'; }).join('') + '</div></div></div>';
        bsAll.forEach(function (b) { if (!seen[b.id]) html += '<div style="margin:8px 0;"><div style="color:#b8431f;font-size:11px;">循環連結，請檢查流向</div>' + bnode(b) + '</div>'; });
        html += '</div>';
        var un = tfs.filter(function (t) { var fl = flow[t.id]; return !(fl && (fl.src || (fl.dst && sends[fl.dst]))); });
        html += '<div class="flw-side"><div class="fl-un" data-batch="0" data-fid="' + (un[0] ? un[0].fid : 0) + '" style="margin:0;min-height:60px;"><div style="color:#b8431f;font-size:12px;font-weight:bold;margin-bottom:4px;">⚠ 找不到來源發單的移轉憑單（' + un.length + '）</div><div style="color:#999;font-size:11px;margin-bottom:4px;">拖到左邊對的發單卡片，或用下拉指定</div><div style="display:flex;flex-direction:column;gap:6px;">';
        un.forEach(function (t) { html += chip(t); });
        html += '</div></div></div></div>';
        body.innerHTML = html;
        body.querySelectorAll('.it').forEach(function (it) { bindRow(it); });
        applyLock();
        // 合流：從「合流」小標籤畫一條線連回它進入的發單卡片
        (function drawMerge() {
            var main = body.querySelector('.flw-main');
            if (!main) return;
            var stubs = main.querySelectorAll('[data-merge]');
            if (!stubs.length) return;
            main.style.position = 'relative';
            var NS = 'http://www.w3.org/2000/svg', svg = document.createElementNS(NS, 'svg');
            svg.setAttribute('style', 'position:absolute;left:0;top:0;pointer-events:none;overflow:visible;z-index:3;');
            svg.setAttribute('width', main.scrollWidth); svg.setAttribute('height', main.scrollHeight);
            svg.innerHTML = '<defs><marker id="egaf-mg" markerWidth="8" markerHeight="8" refX="7" refY="4" orient="auto"><path d="M0,0 L8,4 L0,8 z" fill="#2E8B57"/></marker></defs>';
            var mr = main.getBoundingClientRect();
            function pt(el, side) { var r = el.getBoundingClientRect(); return { x: r.left - mr.left + main.scrollLeft + r.width / 2, y: (side === 'top' ? r.top : side === 'bottom' ? r.bottom : r.top + r.height / 2) - mr.top + main.scrollTop, l: r.left - mr.left + main.scrollLeft, r: r.right - mr.left + main.scrollLeft }; }
            stubs.forEach(function (s) {
                var tg = main.querySelector('.fl-b[data-batch="' + s.getAttribute('data-merge') + '"]');
                if (!tg) return;
                var a = pt(s, 'mid'), b = pt(tg, 'mid'), x1, x2;
                if (b.x >= a.x) { x1 = a.r; x2 = b.l; } else { x1 = a.l; x2 = b.r; }
                var dx = Math.max(40, Math.abs(x2 - x1) / 2) * (x2 >= x1 ? 1 : -1);
                var p = document.createElementNS(NS, 'path');
                p.setAttribute('d', 'M' + x1 + ',' + a.y + ' C' + (x1 + dx) + ',' + a.y + ' ' + (x2 - dx) + ',' + b.y + ' ' + x2 + ',' + b.y);
                p.setAttribute('fill', 'none'); p.setAttribute('stroke', '#2E8B57'); p.setAttribute('stroke-width', '2'); p.setAttribute('stroke-dasharray', '5 3'); p.setAttribute('marker-end', 'url(#egaf-mg)');
                svg.appendChild(p);
            });
            main.appendChild(svg);
        })();
        function setFlow(tid, field, val) {
            post({ action: 'baf_flow_set', transfer_id: tid, field: field, batch_id: val }, function (r) { if (r.success) { load('已調整流向', true); } else msg(r.message, false); });
        }
        function openSplit(tid) {
            var t = rows.filter(function (r) { return r.kind === 'transfer' && String(r.id) === String(tid); })[0]; if (!t) return;
            var fl = flow[t.id] || {}, cur = {};
            (fl.alloc || []).forEach(function (a) { cur[a.batch_id] = a.qty; });
            var cands = bsAll.filter(function (b) { return b.sn > t.sn; });
            var ov = document.createElement('div');
            ov.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.35);z-index:100000;display:flex;align-items:center;justify-content:center;';
            var h = '<div style="background:#fff;border-radius:6px;padding:14px 16px;width:560px;max-width:94vw;max-height:88vh;overflow:auto;font-size:13px;">'
                + '<div style="font-weight:bold;font-size:14px;margin-bottom:4px;">拆分移轉憑單 ' + esc(t.no || ('#' + t.id)) + '</div>'
                + '<div style="color:#666;margin-bottom:6px;">' + esc(t.from || '') + ' → ' + esc(t.to || '') + '　憑單數量 <b>' + nz(t.qty) + '</b>。這裡只另記「這張憑單的貨分給哪幾個發單批次、各幾件」，<b>不會改動 ERP 的移轉憑單</b>。</div>'
                + '<table style="width:100%;border-collapse:collapse;">';
            cands.forEach(function (b) {
                h += '<tr><td style="padding:3px 6px 3px 0;">' + esc(bLabel(b)) + '</td><td style="width:110px;"><input type="number" min="0" step="any" class="fl-aq" data-b="' + b.id + '" value="' + (cur[b.id] ? nz(cur[b.id]) : '') + '" placeholder="件數" style="width:100px;"></td></tr>';
            });
            if (!cands.length) h += '<tr><td style="color:#b8431f;">這張憑單之後的關卡還沒有任何發單批次，請先在「時間軸」新增發單。</td></tr>';
            h += '</table><div id="fl-sum" style="margin:8px 0;font-weight:bold;"></div><div id="fl-err" style="color:#b8431f;min-height:16px;"></div>'
                + '<div style="text-align:right;"><button type="button" id="fl-clr" style="margin-right:6px;">清除拆分</button><button type="button" id="fl-no" style="margin-right:6px;">取消</button><button type="button" id="fl-ok" style="background:#C0561F;color:#fff;border:0;padding:3px 14px;border-radius:3px;">儲存拆分</button></div></div>';
            ov.innerHTML = h; document.body.appendChild(ov);
            function items() { var a = []; ov.querySelectorAll('.fl-aq').forEach(function (i) { var q = parseFloat(i.value) || 0; if (q > 0) a.push({ batch_id: parseInt(i.getAttribute('data-b'), 10), qty: q }); }); return a; }
            function sumChk() {
                var it = items(), sum = it.reduce(function (x, y) { return x + y.qty; }, 0), left = Math.round((t.qty - sum) * 1000) / 1000;
                var el = ov.querySelector('#fl-sum');
                el.innerHTML = '已分配 ' + nz(sum) + ' / 憑單 ' + nz(t.qty) + (left > 0 ? '　<span style="color:#b8431f;">尚有 ' + nz(left) + ' 件沒有分配到任何批次</span>' : left < 0 ? '　<span style="color:#b8431f;">超出 ' + nz(-left) + ' 件</span>' : '　<span style="color:#2a7a2a;">✔ 剛好分完</span>');
                // 各批已被分配的量（含其他憑單）：超過該批發單數就即時提醒，後端也會擋
                return left >= 0;
            }
            ov.querySelectorAll('.fl-aq').forEach(function (i) { i.addEventListener('input', sumChk); }); sumChk();
            ov.querySelector('#fl-no').onclick = function () { ov.remove(); };
            function save(list) {
                post({ action: 'baf_flow_alloc', transfer_id: t.id, items: JSON.stringify(list) }, function (r) {
                    if (r.success) { ov.remove(); load('已儲存拆分', true); } else ov.querySelector('#fl-err').textContent = r.message || '儲存失敗';
                });
            }
            ov.querySelector('#fl-clr').onclick = function () { save([]); };
            ov.querySelector('#fl-ok').onclick = function () {
                var it = items();
                if (!it.length) { ov.querySelector('#fl-err').textContent = '請至少填一個批次的件數，或按「清除拆分」'; return; }
                if (!sumChk()) { ov.querySelector('#fl-err').textContent = '分配合計超過憑單數量'; return; }
                save(it);
            };
        }
        body.querySelectorAll('.fl-split').forEach(function (b) { b.onclick = function () { openSplit(b.getAttribute('data-tid')); }; });
        body.querySelectorAll('.fl-sel').forEach(function (sel) {
            sel.onchange = function () {
                if (sel.value.charAt(0) === 'f') { setFlow(sel.getAttribute('data-tid'), 'dst_fid', parseInt(sel.value.slice(1), 10)); return; }
                setFlow(sel.getAttribute('data-tid'), sel.getAttribute('data-f'), sel.value === 'a' ? -1 : parseInt(sel.value, 10));
            };
        });
        body.querySelectorAll('.fl-t').forEach(function (c) {
            c.ondragstart = function (e) { e.dataTransfer.setData('text/plain', c.getAttribute('data-tid') + '|' + c.getAttribute('data-sn')); e.dataTransfer.effectAllowed = 'move'; };
            // 把發單卡片拖到移轉憑單卡片上＝設為這張移轉憑單的接續發單（必須在它的後面關卡）
            c.ondragover = function (e) { e.preventDefault(); c.classList.add('over'); };
            c.ondragleave = function () { c.classList.remove('over'); };
            c.ondrop = function (e) {
                e.preventDefault(); e.stopPropagation(); c.classList.remove('over');
                var p = (e.dataTransfer.getData('text/plain') || '').split('|');
                if (p[0].indexOf('B:') !== 0) return;
                var bid = p[0].slice(2), bsn = parseInt(p[1], 10), tsn = parseInt(c.getAttribute('data-sn'), 10);
                if (!(bsn > tsn)) { msg('接續發單必須在這張移轉憑單的後面關卡', false); return; }
                setFlow(c.getAttribute('data-tid'), 'dst', parseInt(bid, 10));
            };
        });
        body.querySelectorAll('.fl-b[data-batch]').forEach(function (bc) {
            bc.ondragstart = function (e) { e.dataTransfer.setData('text/plain', 'B:' + bc.getAttribute('data-batch') + '|' + bc.getAttribute('data-sn')); e.dataTransfer.effectAllowed = 'move'; };
        });
        body.querySelectorAll('.fl-b,.fl-un').forEach(function (box) {
            box.ondragover = function (e) { e.preventDefault(); box.classList.add('over'); };
            box.ondragleave = function () { box.classList.remove('over'); };
            box.ondrop = function (e) {
                e.preventDefault(); e.stopPropagation(); box.classList.remove('over');
                var p = (e.dataTransfer.getData('text/plain') || '').split('|');
                if (p.length >= 2 && p[0].indexOf('B:') === 0) {
                    // 發單批次拖到另一個發單批次上＝接在它底下（上一關）；拖到右邊「找不到來源」區＝解除
                    var cb = parseInt(p[0].slice(2), 10), tb = parseInt(box.getAttribute('data-batch'), 10), tsn2 = parseInt(box.getAttribute('data-sn'), 10);
                    if (!tb && box.classList.contains('fl-un')) { post({ action: 'baf_bparent_set', batch_id: cb, parent_id: 0 }, function (r) { if (r.success) load('已解除', true); else msg(r.message, false); }); return; }
                    if (!(tb > 0) || tb === cb) return;
                    if (!(tsn2 < parseInt(p[1], 10))) { msg('只能把下一關的發單批次拖到前一關的批次上', false); return; }
                    post({ action: 'baf_bparent_set', batch_id: cb, parent_id: tb }, function (r) { if (r.success) load('已接上', true); else msg(r.message, false); });
                    return;
                }
                if (p.length < 2) return;
                var batch = parseInt(box.getAttribute('data-batch'), 10), sameStation = box.getAttribute('data-sn') === p[1];
                if (batch === 0) setFlow(p[0], 'src', 0);
                else if (sameStation) setFlow(p[0], 'src', batch);
                else if (batch > 0) { if (parseInt(box.getAttribute('data-sn'), 10) > parseInt(p[1], 10)) setFlow(p[0], 'dst', batch); else msg('接續發單必須在移轉憑單的後面關卡', false); }
            };
        });
        updDn();
    }
    // 非整十的 SN（例如 15、25、31）可設成「算某一關」：列表／分支／總數統計都會併入那一關，資料本身不動
    function snBoxHtml() {
        var m = DATA.sn_map; if (!m || !m.items || !m.items.length) return '';
        var rows = m.items.map(function (x) {
            var opts = '<option value="0">— 不對應（獨立一關）—</option>' + (m.targets || []).filter(function (t) { return t.sn !== x.sn && t.sn % 10 === 0 || (t.sn !== x.sn && !m.items.some(function (y) { return y.sn === t.sn; })); }).map(function (t) { return '<option value="' + t.sn + '"' + (x.target === t.sn ? ' selected' : '') + '>' + esc(t.label) + '（SN ' + t.sn + '）</option>'; }).join('');
            return '<div style="margin:2px 0;">SN <b>' + x.sn + '</b>　' + esc(x.label) + (x.target ? ' <span class="tg" style="background:#2E8B57;">已併入</span>' : '') + '　算同一關：<select class="sn-sel" data-sn="' + x.sn + '" style="height:22px;font-size:12px;max-width:230px;">' + opts + '</select> <button class="btn btn-default btn-xs sn-go" data-sn="' + x.sn + '">儲存</button></div>';
        }).join('');
        return '<details style="margin-bottom:6px;border:1px dashed #E4B98A;border-radius:4px;padding:3px 8px;background:#FFFCF7;font-size:12px;"' + ' open' + '><summary style="cursor:pointer;font-weight:bold;color:#6B4423;">SN 對應（非整十的 SN 可設成跟某一關同一關，' + m.items.length + ' 個）</summary>' + rows + '</details>';
    }
    function bindSnBox(root) {
        root.querySelectorAll('.sn-go').forEach(function (btn) {
            btn.onclick = function () {
                var sn = btn.getAttribute('data-sn'), sel = root.querySelector('.sn-sel[data-sn="' + sn + '"]');
                post({ action: 'baf_alias_set', alias_sn: sn, target_sn: sel.value }, function (r) { if (r.success) { toast('已儲存SN對應', true); load('已儲存 SN ' + sn + ' 的對應', true); refreshMain(); } else msg(r.message, false); });
            };
        });
    }
    // 全域鎖定：勾選時所有移轉憑單的日期／數量唯讀（並還原未儲存的修改）；取消勾選才可改
    function applyLock() {
        var lock = drawer.querySelector('#egaf-lock').checked;
        drawer.querySelectorAll('.it[data-k="transfer"]').forEach(function (it) {
            var d = it.querySelector('.f-d'), q = it.querySelector('.f-q'); if (!d || !q) return;
            if (lock) { d.value = d.defaultValue; q.value = q.defaultValue; it.classList.remove('dirty'); }
            d.disabled = q.disabled = lock;
        });
        updDn();
    }
    function keepDirtyWarn() { /* 篩選只是換顯示，未儲存的修改在重畫時會被丟掉，先提醒 */ if (dirtyCount()) msg('提醒：篩選切換會清掉未儲存的修改，請先按「儲存」', false); }
    function inp(cls, v, wd, type) { return '<input class="' + cls + '" type="' + (type || 'number') + '" ' + (type === 'date' ? 'max="' + today() + '"' : 'min="0" step="any"') + ' value="' + esc(v) + '" style="width:' + wd + 'px;">'; }
    // 每張移轉憑單是否「發單不夠」：同一關、同一家轉出廠商，依日期累加移轉量，超過該廠商已登記的發單總量的那幾張，缺的量就是要補的發單數
    function calcDeficit() {
        var rows = DATA.rows || [], sends = {}, groups = {}, def = {};
        rows.forEach(function (r) { if (r.kind === 'send') { var k = r.sn + '|' + r.maker_no; sends[k] = (sends[k] || 0) + (parseFloat(r.send_qty) || 0); } });
        rows.forEach(function (r) { if (r.kind === 'transfer' && r.fid && r.from_no) { var k = r.sn + '|' + r.from_no; (groups[k] = groups[k] || []).push(r); } });
        Object.keys(groups).forEach(function (k) {
            var cum = 0, S = sends[k] || 0;
            groups[k].sort(function (a, b) { return (a.date < b.date ? -1 : a.date > b.date ? 1 : a.id - b.id); }).forEach(function (r) {
                var before = cum; cum += (parseFloat(r.qty) || 0);
                if (cum > S + 1e-9) def[r.id] = Math.min(parseFloat(r.qty) || 0, cum - Math.max(S, before));
            });
        });
        return def;
    }
    function aliasHtml(x) {
        var seen = {}, opts = '<option value="0">— 不對應 —</option>';
        (DATA.stations || []).forEach(function (s) { if (seen[s.sn]) return; seen[s.sn] = 1; opts += '<option value="' + s.sn + '"' + (x.aliased && String(s.sn) === String(x.sn) ? ' selected' : '') + '>' + esc(s.label) + '（SN ' + s.sn + '）</option>'; });
        return '<div class="row2 al" style="background:#F3F3F3;border:1px dashed #bbb;padding:4px 6px;">SN ' + x.raw_sn + (x.orphan ? ' 不是這個 BOM 的製程（ERP 拆單產生）' : ' 已對應') + '，算同一關：<select class="al-sel" style="height:24px;font-size:12px;max-width:230px;">' + opts + '</select> <button class="btn btn-default btn-xs al-go" data-sn="' + x.raw_sn + '">儲存對應</button></div>';
    }
    function rowHtml(x, noAlias) {
        var h = '<div class="it" data-k="' + x.kind + '" data-id="' + x.id + '" data-f="' + x.fid + '" data-mn="' + esc(x.maker_no || '') + '" data-m="' + esc(x.maker || '') + '" data-fno="' + esc(x.from_no || '') + '" data-fname="' + esc(x.from || '') + '">'
            + '<span class="k" style="background:' + COLORS[x.kind] + ';">' + LABELS[x.kind] + '</span><b>' + esc(x.station || '') + '</b>';
        if (x.kind === 'send') {
            h += ' <span style="color:#7a4a10;">' + esc(x.maker || '未登記廠商') + '</span><div class="row2"><span class="gp">送出日 ' + inp('f-d', x.date, 125, 'date') + '</span><span class="gp"><button class="btn btn-danger btn-xs f-del">刪除</button></span><span class="gp nl">發單數 ' + inp('f-sq', nz(x.send_qty), 70) + '</span>'
              + '<span style="color:#888;">已回 ' + nz(x.return_qty) + '／廢 ' + nz(x.scrap_qty) + '／取回 ' + nz(x.withdraw_qty) + '</span></div>';
        } else if (x.kind === 'return') {
            h += ' <span style="color:#7a4a10;">' + esc(x.maker || '') + '</span>' + (x.note ? ' <span style="color:#999;">（' + esc(x.note) + '）</span>' : '')
              + '<div class="row2">日期 ' + inp('f-d', x.date, 125, 'date') + ' 回廠 ' + inp('f-r', nz(x.return_qty), 60) + ' 報廢 ' + inp('f-s', nz(x.scrap_qty), 60) + ' 取回 ' + inp('f-w', nz(x.withdraw_qty), 60)
              + '<span style="margin-left:auto;"><button class="btn btn-danger btn-xs f-del">刪除</button></span></div>'
              + '<div style="font-size:11px;color:#a67c3d;margin-top:2px;">回廠＝實際良品回來的數量，<b>不含報廢</b>；回廠＋報廢＋取回 ＝ 該批發單數（例：發20、報廢1，則回廠19）</div>';
        } else if (x.kind === 'qc') {
            h += ' <span style="color:#7a4a10;">' + esc(x.result || '') + '</span><div class="row2">檢驗日 ' + inp('f-d', x.date, 125, 'date') + ' 允收 ' + inp('f-ok', x.ok, 55) + ' 特採 ' + inp('f-aod', x.aod, 55) + ' 異常 ' + inp('f-qq', x.qq, 55) + ' 驗退 ' + inp('f-ng', x.ng, 55) + '</div>';
        } else if (x.kind === 'transfer') {
            var defQ = (DATA._def && DATA._def[x.id]) || 0;
            var need = x.from_no && x.fid && defQ > 0;
            h += ' <span style="color:#7a4a10;">' + esc(x.no) + '　' + esc(x.from) + (x.to ? ' → ' + esc(x.to) : '') + '</span><div class="row2"><span class="gp">移轉日 ' + inp('f-d', x.date, 125, 'date').replace('<input', '<input disabled') + '</span><span class="gp nl">數量 ' + inp('f-q', x.qty, 70).replace('<input', '<input disabled') + '</span>'
              + (need ? '<span style="margin-left:auto;"><button class="btn btn-success btn-xs f-qs" title="這一關發給「' + esc(x.from) + '」的發單數量不夠（缺 ' + nz(defQ) + '），快速補一筆">快速補發單</button></span>' : '') + '</div>'
              + (need ? '<div class="qs row2" style="display:none;background:#fff;border:1px dashed #E4D3BC;padding:4px 6px;">補發單給「' + esc(x.from) + '」　數量 ' + inp('q-q', nz(defQ), 70) + ' 送出日 ' + inp('q-d', x.date, 125, 'date') + ' <button class="btn btn-warning btn-xs q-go">確認補發單</button></div>' : '')
              + ((!noAlias && (x.orphan || x.aliased)) ? aliasHtml(x) : '');
        } else if (x.kind === 'ing') {
            h += ' <span style="color:#7a4a10;">' + esc(x.maker || '未登記廠商') + '</span><span style="margin-left:6px;color:#fff;background:#8B8B8B;border-radius:3px;padding:0 6px;font-size:11px;">' + esc(x.reason) + '</span>'
              + '<div class="row2" style="color:#666;">bom_ing #' + x.id + '　發單數 ' + nz(x.qty) + '　狀態 ' + esc(STATE_TXT[x.state] || x.state) + (x.batch_label ? '　批次 ' + esc(x.batch_label) : '') + '　發包日 ' + esc(x.od || '-') + '　回廠日 ' + esc(x.rd || '-')
              + '<span style="margin-left:auto;color:#aaa;">（ERP匯入的原始製程列，製程列表不顯示；僅供對照）</span></div>';
        } else {
            h += '<div class="row2"><span>' + esc(x.no) + '　' + esc(x.text) + (x.ng ? '　不良 ' + nz(x.ng) : '') + '</span><span style="margin-left:auto;"><a href="../QA/qa_abnormal_form.php?id=' + x.id + '" target="_blank" class="btn btn-default btn-xs">開啟異常單</a></span></div>';
        }
        return h + '</div>';
    }
    function payloadOf(it) {
        var k = it.getAttribute('data-k'), d = { action: 'baf_save', kind: k, id: it.getAttribute('data-id'), fid: it.getAttribute('data-f') };
        function v(c) { var e = it.querySelector('.' + c); return e ? e.value : ''; }
        d.date = v('f-d');
        if (k === 'send') { d.send_qty = v('f-sq'); d.maker_no = it.getAttribute('data-mn'); d.maker = it.getAttribute('data-m'); }
        else if (k === 'return') { d.return_qty = v('f-r'); d.scrap_qty = v('f-s'); d.withdraw_qty = v('f-w'); }
        else if (k === 'qc') { d.ok = v('f-ok'); d.aod = v('f-aod'); d.qq = v('f-qq'); d.ng = v('f-ng'); }
        else if (k === 'transfer') { d.qty = v('f-q'); }
        return d;
    }
    function saveAll(thenEnd) {
        var items = Array.prototype.slice.call(drawer.querySelectorAll('.it.dirty'));
        if (!items.length) { if (thenEnd) finish(); else msg('沒有需要儲存的修改', true); return; }
        var sens = items.filter(function (it) { var k = it.getAttribute('data-k'); return k === 'qc' || k === 'transfer'; }).length;
        if (sens && !confirm('這次包含 ' + sens + ' 筆 QC檢驗／移轉憑單 的修改，會直接改變已驗數量與移轉帳款分析。確定儲存？')) return;
        var btns = drawer.querySelectorAll('#egaf-save,#egaf-saveend'); btns.forEach(function (b) { b.disabled = true; });
        var pending = items.slice(), fails = [], okN = 0, round = 0;
        function next() {
            var it = pending.shift();
            if (!it) {
                // 有失敗的（例如發單數要等回廠數先改小）再試一輪
                if (fails.length && round === 0 && okN > 0) { round = 1; pending = fails.map(function (f) { return f.it; }); fails = []; return next(); }
                return done();
            }
            post(payloadOf(it), function (r) {
                if (r.success) { okN++; it.classList.remove('dirty'); } else fails.push({ it: it, m: r.message || '失敗' });
                next();
            });
        }
        function done() {
            btns.forEach(function (b) { b.disabled = false; });
            if (okN) refreshMain();
            load(null);
            if (fails.length) {
                var t = '儲存 ' + okN + ' 筆；' + fails.length + ' 筆失敗：' + fails.map(function (f) { return f.m; }).filter(function (m, i, a) { return a.indexOf(m) === i; }).join('；');
                setTimeout(function () { msg(t, false); }, 400);
                if (thenEnd) alert('有 ' + fails.length + ' 筆沒存成功，所以不會結束：\n' + t);
            } else {
                msg('已儲存 ' + okN + ' 筆', true); toast('已儲存 ' + okN + ' 筆', true);
                if (thenEnd) finish();
            }
        }
        next();
    }
    function finish() {
        w.jQuery.post('', { action: 'baf_lock' }, function () { unlocked = false; close(); toast('已儲存並上鎖', true); }, 'json');
    }
    function bindRow(it) {
        var k = it.getAttribute('data-k'), id = it.getAttribute('data-id'), f = it.getAttribute('data-f');
        var dl = it.querySelector('.f-del');
        if (dl) dl.onclick = function () {
            if (!confirm(k === 'send' ? '確定刪除這個發單批次（含它底下所有回廠紀錄）？' : '確定刪除這筆回廠／報廢／取回紀錄？')) return;
            post({ action: 'baf_delete', kind: k, id: id, fid: f }, function (r) { if (r.success) { toast('已刪除', true); load('已刪除', true); refreshMain(); } else msg(r.message, false); });
        };
        var ag = it.querySelector('.al-go');
        if (ag) ag.onclick = function () {
            post({ action: 'baf_alias_set', alias_sn: ag.getAttribute('data-sn'), target_sn: it.querySelector('.al-sel').value }, function (r) { if (r.success) { toast('已儲存SN對應', true); load('已儲存 SN ' + ag.getAttribute('data-sn') + ' 的對應', true); refreshMain(); } else msg(r.message, false); });
        };
        var qs = it.querySelector('.f-qs');
        if (qs) {
            qs.onclick = function () {
                var box = it.querySelector('.qs');
                // 數量預設＝缺的發單數（算好帶在欄位裡，可手動改）；日期預設＝這張移轉憑單的日期
                box.querySelector('.q-d').value = it.querySelector('.f-d').value;
                box.style.display = box.style.display === 'none' ? 'flex' : 'none';
            };
            it.querySelector('.q-go').onclick = function () {
                var box = it.querySelector('.qs');
                post({ action: 'baf_add_send', fid: f, maker_no: it.getAttribute('data-fno'), maker: it.getAttribute('data-fname'), send_qty: box.querySelector('.q-q').value, date: box.querySelector('.q-d').value },
                    function (r) { if (r.success) { toast('已補發單', true); load('已補發單給「' + it.getAttribute('data-fname') + '」', true); refreshMain(); } else msg(r.message, false); });
            };
        }
    }
    function bindNew() {
        var pick = null;
        ['#egaf-ns-q', '#egaf-nr-r'].forEach(function (id) { drawer.querySelector(id).addEventListener('input', function (e) { if (e.isTrusted) this.dataset.t = '1'; }); });
        drawer.querySelector('#egaf-nr-b').addEventListener('change', function () { var r = drawer.querySelector('#egaf-nr-r'); delete r.dataset.t; autoFill(); });
        makerPicker(drawer.querySelector('#egaf-mk'), function (p) { pick = p; });
        drawer.querySelector('#egaf-ns-go').onclick = function () {
            if (!stationFilter) { msg('請先在「製程」下拉選一個製程，再新增發單', false); return; }
            if (!pick || !pick.no) { msg('請從下方列出的廠商清單點選一家廠商', false); return; }
            post({ action: 'baf_add_send', fid: stationFilter, maker_no: pick.no, maker: pick.name, send_qty: drawer.querySelector('#egaf-ns-q').value, date: drawer.querySelector('#egaf-ns-d').value },
                function (r) { if (r.success) { delete drawer.querySelector('#egaf-ns-q').dataset.t; load('已新增發單', true); refreshMain(); } else msg(r.message, false); });
        };
        drawer.querySelector('#egaf-nr-go').onclick = function () {
            var sel = drawer.querySelector('#egaf-nr-b'), opt = sel.options[sel.selectedIndex];
            if (!opt) { msg('沒有可選的發單批次，請先新增發單', false); return; }
            post({ action: 'baf_add_return', fid: opt.getAttribute('data-f'), batch_id: sel.value, return_qty: drawer.querySelector('#egaf-nr-r').value, scrap_qty: drawer.querySelector('#egaf-nr-s').value,
                   withdraw_qty: drawer.querySelector('#egaf-nr-w').value, date: drawer.querySelector('#egaf-nr-d').value },
                function (r) { if (r.success) { delete drawer.querySelector('#egaf-nr-r').dataset.t; load('已新增', true); refreshMain(); } else msg(r.message, false); });
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
    w.EGBomAdminFix = { open: open, close: close, renderWarn: renderWarn };
})(window);
