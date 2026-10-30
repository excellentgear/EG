/**
 * BOM 相關紀錄（右側滑出式視窗，唯一實作）——BOM總覽與已完工BOM查詢共用。
 * 用法：EGBomTimeline.open('B-1150608999')；資料來自 src/store/BomTimeline_API.php（純讀取）。
 */
(function (w) {
    var API = null, drawer = null, seq = 0, cur = null;
    var COLORS = { send: '#F0A24B', forward: '#E08A2E', return: '#2E8B57', withdraw: '#B8431F', scrap: '#DD5138',
                   qc: '#8A6A45', abnormal: '#DD5138', transfer: '#C0561F', event: '#A07C55', op: '#6B4423' };
    var TONES = { green: ['#2E8B57', '#fff', '#1f6b41'], red: ['#DD5138', '#fff', '#b8381f'], yellow: ['#F5C542', '#5a3d00', '#8a5a00'] };
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function fmtDate(d) { return (w.egFmtDate ? w.egFmtDate(d) : String(d).replace(/-/g, '.')); }
    function ensure() {
        if (drawer) return drawer;
        var st = document.createElement('style');
        st.textContent = '#egbt-drawer{position:fixed;top:0;right:0;height:100%;width:440px;max-width:92vw;background:#fff;z-index:10600;box-shadow:-4px 0 18px rgba(0,0,0,.18);transform:translateX(105%);transition:transform .22s ease;display:flex;flex-direction:column;font-size:13px;color:#4a3320;}'
            + '#egbt-drawer.open{transform:translateX(0);}'
            + '#egbt-drawer .egbt-h{padding:12px 14px;border-bottom:1px solid #E4D3BC;background:#FDF3E3;display:flex;justify-content:space-between;align-items:center;}'
            + '#egbt-drawer .egbt-b{flex:1;overflow-y:auto;padding:10px 14px 20px;}'
            + '#egbt-drawer .egbt-d{font-weight:bold;margin:12px 0 4px;color:#7a4a10;border-bottom:1px dotted #E4D3BC;}'
            + '#egbt-drawer .egbt-i{display:flex;gap:8px;padding:4px 0;line-height:1.5;}'
            + '#egbt-drawer .egbt-t{flex:0 0 40px;color:#999;font-size:11px;padding-top:2px;}'
            + '#egbt-drawer .egbt-k{flex:0 0 auto;align-self:flex-start;color:#fff;border-radius:3px;padding:0 6px;font-size:11px;line-height:18px;margin-top:2px;white-space:nowrap;}'
            + '#egbt-drawer .egbt-w{color:#999;font-size:11px;}';
        document.head.appendChild(st);
        drawer = document.createElement('div');
        drawer.id = 'egbt-drawer';
        drawer.innerHTML = '<div class="egbt-h"><strong id="egbt-title">BOM 相關紀錄</strong><span><button type="button" id="egbt-print" style="border:1px solid #d9861f;background:#fff;color:#a4491a;border-radius:3px;padding:2px 10px;cursor:pointer;margin-right:8px;font-size:12px;"><i class="fa fa-print"></i> 列印</button><button type="button" id="egbt-x" style="border:none;background:none;font-size:20px;cursor:pointer;color:#999;line-height:1;">×</button></span></div><div class="egbt-b" id="egbt-body"></div>';
        document.body.appendChild(drawer);
        drawer.querySelector('#egbt-x').onclick = close;
        drawer.querySelector('#egbt-print').onclick = printIt;
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
        return drawer;
    }
    function printIt() {
        if (!cur || !cur.events || !cur.events.length) { alert('沒有可列印的紀錄'); return; }
        var rows = '', last = '';
        cur.events.forEach(function (e) {
            var d = e.date !== last ? fmtDate(e.date) : ''; last = e.date;
            var col = e.tone === 'green' ? '#1f6b41' : e.tone === 'red' ? '#b8381f' : e.tone === 'yellow' ? '#8a5a00' : '#333';
            rows += '<tr><td class="d">' + esc(d) + '</td><td class="t">' + esc(e.time) + '</td><td class="k">' + esc(e.label) + '</td><td style="color:' + col + ';">' + esc(e.text) + '</td><td class="w">' + esc(e.who) + '</td></tr>';
        });
        var now = new Date(), p2 = function (n) { return (n < 10 ? '0' : '') + n; };
        var ts = now.getFullYear() + '.' + p2(now.getMonth() + 1) + '.' + p2(now.getDate()) + ' ' + p2(now.getHours()) + ':' + p2(now.getMinutes());
        var h = '<!doctype html><html><head><meta charset="utf-8"><title>BOM 相關紀錄 ' + esc(cur.bom) + '</title><style>'
            + '@page{size:A4 portrait;margin:14mm 12mm 16mm 12mm;@bottom-left{content:"第 " counter(page) " 頁／共 " counter(pages) " 頁";font-size:10px;color:#555;}}'
            + 'body{font-family:"Microsoft JhengHei","PingFang TC",sans-serif;font-size:12px;color:#222;margin:0;}'
            + 'h1{font-size:16px;text-align:center;margin:0 0 2px;}h2{font-size:14px;text-align:center;margin:0 0 6px;}'
            + '.m{display:flex;justify-content:space-between;font-size:11px;color:#555;margin-bottom:6px;}'
            + 'table{width:100%;border-collapse:collapse;table-layout:fixed;}thead{display:table-header-group;}tr{page-break-inside:avoid;}'
            + 'th,td{border:1px solid #bbb;padding:3px 5px;vertical-align:top;word-break:break-all;line-height:1.45;}th{background:#F7E0BD;}'
            + 'td.d{width:72px;white-space:nowrap;}td.t{width:38px;color:#666;}td.k{width:74px;}td.w{width:56px;color:#666;}'
            + '</style></head><body>'
            + (cur.company ? '<h1>' + esc(cur.company) + '</h1>' : '') + '<h2>BOM 相關紀錄　' + esc(cur.bom) + '</h2>'
            + '<div class="m"><span>依日期由舊到新，共 ' + cur.events.length + ' 筆</span><span>列印時間 ' + ts + '</span></div>'
            + '<table><thead><tr><th>日期</th><th>時間</th><th>類別</th><th>內容</th><th>人員</th></tr></thead><tbody>' + rows + '</tbody></table>'
            + '</body></html>';
        try { if (w.EGPrintLog) w.EGPrintLog.record({ source: 'bom_timeline', doc_name: 'BOM 相關紀錄 ' + cur.bom, doc_kind: 'form' }); } catch (e) {}
        var win = w.open('', '_blank');
        if (!win) { alert('瀏覽器擋住了列印視窗，請允許彈出視窗後再按一次'); return; }
        win.document.open(); win.document.write(h); win.document.close();
        var done = false;
        var go = function () { if (done) return; done = true; try { win.focus(); win.print(); } catch (e) {} };
        win.onload = go;
        setTimeout(go, 600);
    }
    function close() { if (drawer) drawer.classList.remove('open'); }
    function apiUrl() {
        if (API) return API;
        var m = (document.currentScript && document.currentScript.src) || '';
        API = (w.EG_BOM_TIMELINE_API) || '../../src/store/BomTimeline_API.php';
        return API;
    }
    function open(bom) {
        bom = String(bom || '').trim();
        if (!bom) return;
        var d = ensure(), body = d.querySelector('#egbt-body'), my = ++seq;
        d.querySelector('#egbt-title').textContent = 'BOM 相關紀錄　' + bom;
        body.innerHTML = '<div style="color:#999;">載入中…</div>';
        d.classList.add('open');
        var x = new XMLHttpRequest();
        x.open('POST', apiUrl());
        x.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        x.onload = function () {
            if (my !== seq) return;
            var r; try { r = JSON.parse(x.responseText); } catch (e) { r = null; }
            cur = (r && r.success) ? { bom: bom, company: r.company || '', events: r.events } : null;
            if (!r || !r.success) { body.innerHTML = '<div style="color:#b8431f;">讀取失敗：' + esc((r && r.message) || '') + '</div>'; return; }
            if (!r.events.length) { body.innerHTML = '<div style="color:#999;">這個 BOM 目前沒有任何相關紀錄。</div>'; return; }
            var html = '', last = '';
            r.events.forEach(function (e) {
                if (e.date !== last) { last = e.date; html += '<div class="egbt-d">' + esc(fmtDate(e.date)) + '</div>'; }
                var tc = TONES[e.tone] || null;
                html += '<div class="egbt-i"><span class="egbt-t">' + esc(e.time) + '</span><span class="egbt-k" style="background:' + (tc ? tc[0] : (COLORS[e.kind] || '#A07C55')) + ';color:' + (tc ? tc[1] : '#fff') + ';">' + esc(e.label) + '</span>'
                    + '<span' + (tc ? ' style="color:' + tc[2] + ';"' : '') + '>' + esc(e.text) + (e.who ? ' <span class="egbt-w">・' + esc(e.who) + '</span>' : '') + '</span></div>';
            });
            body.innerHTML = '<div style="color:#999;font-size:11px;margin-bottom:4px;">依日期由舊到新，共 ' + r.events.length + ' 筆</div>' + html;
        };
        x.onerror = function () { if (my === seq) body.innerHTML = '<div style="color:#b8431f;">連線失敗</div>'; };
        x.send('bom=' + encodeURIComponent(bom));
    }
    w.EGBomTimeline = { open: open, close: close };
})(window);
