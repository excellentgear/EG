/* ============================================================================
 * 外包產能甘特圖  capacity_gantt.js
 * 由 OreadyReply_ForPm_BaseOfTime2.php 的「外包產能」按鈕觸發 window.openCapacityGantt()
 * 後端 action：get_gantt_filters / get_capacity_gantt（見 _ajax.php）
 * 每筆製程 = 一條長條（移轉日 → 有效回廠日）。同一泳道長條重疊 = 產能排擠。
 * ==========================================================================*/
(function () {
  'use strict';

  var PAGE = 'OreadyReply_ForPm_BaseOfTime2.php';
  var built = false;
  var state = {
    makers: [],          // {maker_id_no,maker_id,internal}
    procs: [],           // {process_no,ProcessName}
    selMakers: new Set(),
    selProcs: new Set(),
    rows: [],            // 上次查詢結果
    meta: { start: '', end: '', today: '' },
    groupBy: 'maker',    // 'maker' | 'process'
    hideStale: false,
    showLoad: true
  };

  // ---- 小工具 ----------------------------------------------------------------
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function pad2(n) { return (n < 10 ? '0' : '') + n; }
  function toUTC(d) { var p = d.split('-'); return Date.UTC(+p[0], +p[1] - 1, +p[2]); }
  function dayIdx(dateStr, startStr) { return Math.round((toUTC(dateStr) - toUTC(startStr)) / 86400000); }
  function fmtMD(dateStr) { if (!dateStr) return ''; var p = dateStr.split('-'); return pad2(+p[1]) + '/' + pad2(+p[2]); }
  function todayStr() { var d = new Date(); return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate()); }
  function addDays(dateStr, n) { var t = toUTC(dateStr) + n * 86400000; var d = new Date(t); return d.getUTCFullYear() + '-' + pad2(d.getUTCMonth() + 1) + '-' + pad2(d.getUTCDate()); }
  function hueOf(s) { var h = 0; s = String(s || ''); for (var i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) >>> 0; return h % 360; }

  // ---- 建立 DOM（僅一次）------------------------------------------------------
  function build() {
    if (built) return;
    built = true;

    var style = document.createElement('style');
    style.textContent = [
      '#cg-overlay{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:20000;display:none;}',
      '#cg-modal{position:absolute;top:2.5vh;left:2.5vw;width:95vw;height:95vh;background:#fff;border-radius:6px;box-shadow:0 6px 30px rgba(0,0,0,.4);display:flex;flex-direction:column;overflow:hidden;font-size:13px;}',
      '#cg-head{display:flex;align-items:center;gap:10px;padding:8px 14px;background:#2E6DA4;color:#fff;flex:0 0 auto;}',
      '#cg-head h3{margin:0;font-size:16px;font-weight:700;flex:1;}',
      '#cg-head .cg-x{cursor:pointer;font-size:22px;line-height:1;border:none;background:none;color:#fff;}',
      '#cg-tools{display:flex;flex-wrap:wrap;align-items:center;gap:6px 10px;padding:8px 14px;border-bottom:1px solid #e2e2e2;background:#f7f9fb;flex:0 0 auto;}',
      '#cg-tools label{margin:0;font-weight:600;color:#444;}',
      '#cg-tools input[type=date]{height:28px;padding:2px 6px;border:1px solid #ccc;border-radius:4px;}',
      '.cg-qbtn{border:1px solid #bcd;background:#fff;color:#2E6DA4;border-radius:4px;padding:2px 8px;cursor:pointer;font-size:12px;}',
      '.cg-qbtn:hover{background:#eaf2fb;}',
      '.cg-ms{position:relative;display:inline-block;}',
      '.cg-ms-btn{min-width:120px;text-align:left;border:1px solid #ccc;background:#fff;border-radius:4px;padding:3px 8px;cursor:pointer;height:28px;}',
      '.cg-ms-panel{display:none;position:absolute;top:30px;left:0;z-index:30;background:#fff;border:1px solid #bbb;border-radius:4px;box-shadow:0 4px 14px rgba(0,0,0,.2);width:250px;max-height:300px;overflow:auto;padding:6px;}',
      '.cg-ms-panel.open{display:block;}',
      '.cg-ms-panel input.cg-ms-search{width:100%;box-sizing:border-box;margin-bottom:5px;padding:3px 6px;border:1px solid #ccc;border-radius:3px;}',
      '.cg-ms-item{display:block;padding:2px 3px;cursor:pointer;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}',
      '.cg-ms-item:hover{background:#eaf2fb;}',
      '.cg-ms-item input{margin-right:5px;}',
      '.cg-ms-tools{display:flex;gap:6px;margin-bottom:5px;}',
      '.cg-ms-tools a{font-size:12px;color:#2E6DA4;cursor:pointer;}',
      '#cg-go{background:#2E6DA4;color:#fff;border:none;border-radius:4px;padding:4px 16px;cursor:pointer;font-weight:700;height:28px;}',
      '#cg-go:hover{background:#245a8a;}',
      '.cg-mini{background:#5a9;color:#fff;border:none;border-radius:4px;padding:3px 10px;cursor:pointer;height:28px;}',
      '.cg-mini.csv{background:#17a2b8;}.cg-mini.print{background:#6c757d;}',
      '#cg-body{flex:1 1 auto;overflow:auto;position:relative;}',
      '#cg-status{padding:6px 14px;color:#666;flex:0 0 auto;border-top:1px solid #eee;background:#fafafa;}',
      '.cg-grid{position:relative;min-width:100%;}',
      '.cg-axis{position:sticky;top:0;z-index:12;background:#fff;border-bottom:2px solid #ccc;height:34px;}',
      '.cg-axis .cg-labelcol{position:sticky;left:0;z-index:13;background:#fff;border-right:2px solid #ccc;}',
      '.cg-row{display:flex;border-bottom:1px solid #eee;}',
      '.cg-labelcol{flex:0 0 auto;width:150px;box-sizing:border-box;padding:4px 8px;font-weight:600;position:sticky;left:0;background:#fff;z-index:8;border-right:2px solid #ccc;display:flex;flex-direction:column;justify-content:center;}',
      '.cg-labelcol .cg-sub{font-weight:400;color:#888;font-size:11px;}',
      '.cg-track{position:relative;flex:1 1 auto;}',
      '.cg-tick{position:absolute;top:0;bottom:0;border-left:1px solid #f0f0f0;}',
      '.cg-tick.month{border-left:1px solid #cdd7e0;}',
      '.cg-tick.weekend{background:rgba(0,0,0,.03);}',
      '.cg-ticklabel{position:absolute;top:2px;font-size:10px;color:#889;transform:translateX(2px);white-space:nowrap;}',
      '.cg-today{position:absolute;top:0;bottom:0;border-left:2px dashed #e74c3c;z-index:6;}',
      '.cg-bar{position:absolute;height:15px;border-radius:3px;box-sizing:border-box;overflow:hidden;font-size:10px;line-height:15px;color:#083;white-space:nowrap;padding:0 4px;cursor:pointer;border:1px solid rgba(0,0,0,.25);}',
      '.cg-bar.stale{opacity:.35;border-style:dashed;}',
      '.cg-bar .cg-ov{position:absolute;top:0;font-weight:700;}',
      '.cg-loadrow{display:flex;border-bottom:1px solid #eee;background:#fbfbfb;}',
      '.cg-loadcells{position:relative;flex:1 1 auto;height:15px;}',
      '.cg-lc{position:absolute;top:1px;bottom:1px;font-size:9px;text-align:center;color:#333;overflow:hidden;}',
      '.cg-legend{display:flex;flex-wrap:wrap;gap:4px 12px;padding:6px 14px;border-top:1px solid #eee;flex:0 0 auto;max-height:74px;overflow:auto;}',
      '.cg-legend span{font-size:11px;display:inline-flex;align-items:center;gap:4px;}',
      '.cg-legend i{width:12px;height:12px;border-radius:2px;display:inline-block;border:1px solid rgba(0,0,0,.2);}',
      '#cg-tip{position:fixed;z-index:30000;display:none;background:#222;color:#fff;padding:6px 9px;border-radius:4px;font-size:12px;max-width:320px;pointer-events:none;box-shadow:0 3px 12px rgba(0,0,0,.4);line-height:1.5;white-space:pre-line;}'
    ].join('\n');
    document.head.appendChild(style);

    var ov = document.createElement('div');
    ov.id = 'cg-overlay';
    ov.innerHTML =
      '<div id="cg-modal">' +
      '  <div id="cg-head"><h3><i class="fa fa-bar-chart"></i> 外包產能甘特圖</h3>' +
      '     <button class="cg-x" title="關閉">&times;</button></div>' +
      '  <div id="cg-tools">' +
      '     <label>區間</label>' +
      '     <input type="date" id="cg-start"> ~ <input type="date" id="cg-end">' +
      '     <button class="cg-qbtn" data-days="30">近30天</button>' +
      '     <button class="cg-qbtn" data-days="60">近60天</button>' +
      '     <button class="cg-qbtn" data-days="90">近90天</button>' +
      '     <button class="cg-qbtn" data-month="1">本月</button>' +
      '     <span style="width:8px"></span>' +
      '     <label>分組</label>' +
      '     <label style="font-weight:400"><input type="radio" name="cg-group" value="maker" checked> 依廠商</label>' +
      '     <label style="font-weight:400"><input type="radio" name="cg-group" value="process"> 依製程</label>' +
      '     <div class="cg-ms" id="cg-ms-maker"></div>' +
      '     <div class="cg-ms" id="cg-ms-proc"></div>' +
      '     <button id="cg-go">查詢</button>' +
      '     <label style="font-weight:400"><input type="checkbox" id="cg-hidestale"> 隱藏逾期在廠中(&gt;60天)</label>' +
      '     <label style="font-weight:400"><input type="checkbox" id="cg-showload" checked> 每日負載</label>' +
      '     <span style="flex:1"></span>' +
      '     <button class="cg-mini csv" id="cg-csv">轉 CSV</button>' +
      '     <button class="cg-mini print" id="cg-print">列印</button>' +
      '  </div>' +
      '  <div id="cg-body"><div style="padding:30px;color:#888;">請設定條件後按「查詢」。</div></div>' +
      '  <div class="cg-legend" id="cg-legend"></div>' +
      '  <div id="cg-status"></div>' +
      '</div>';
    document.body.appendChild(ov);

    var tip = document.createElement('div');
    tip.id = 'cg-tip';
    document.body.appendChild(tip);

    // 事件綁定
    ov.querySelector('.cg-x').onclick = closeModal;
    ov.addEventListener('mousedown', function (e) { if (e.target === ov) closeModal(); });
    document.getElementById('cg-go').onclick = runQuery;
    document.getElementById('cg-csv').onclick = exportCsv;
    document.getElementById('cg-print').onclick = printView;
    document.getElementById('cg-hidestale').onchange = function () { state.hideStale = this.checked; render(); };
    document.getElementById('cg-showload').onchange = function () { state.showLoad = this.checked; render(); };
    Array.prototype.forEach.call(ov.querySelectorAll('input[name=cg-group]'), function (r) {
      r.onchange = function () { state.groupBy = this.value; render(); };
    });
    Array.prototype.forEach.call(ov.querySelectorAll('.cg-qbtn'), function (b) {
      b.onclick = function () {
        var end = todayStr(), start;
        if (this.dataset.month) { var p = end.split('-'); start = p[0] + '-' + p[1] + '-01'; }
        else { start = addDays(end, -(parseInt(this.dataset.days, 10) - 1)); }
        document.getElementById('cg-start').value = start;
        document.getElementById('cg-end').value = end;
      };
    });
    // 關閉多選面板（點空白處）
    document.addEventListener('mousedown', function (e) {
      if (!e.target.closest('.cg-ms')) {
        Array.prototype.forEach.call(document.querySelectorAll('.cg-ms-panel.open'), function (p) { p.classList.remove('open'); });
      }
    });
  }

  // ---- 多選元件 --------------------------------------------------------------
  function buildMultiSelect(hostId, allLabel, items, keyFn, textFn, selSet) {
    var host = document.getElementById(hostId);
    host.innerHTML =
      '<button type="button" class="cg-ms-btn"></button>' +
      '<div class="cg-ms-panel">' +
      '  <input type="text" class="cg-ms-search" placeholder="搜尋…">' +
      '  <div class="cg-ms-tools"><a class="cg-ms-all">全選</a><a class="cg-ms-none">清除</a></div>' +
      '  <div class="cg-ms-list"></div>' +
      '</div>';
    var btn = host.querySelector('.cg-ms-btn');
    var panel = host.querySelector('.cg-ms-panel');
    var list = host.querySelector('.cg-ms-list');
    var search = host.querySelector('.cg-ms-search');

    function refreshBtn() {
      btn.textContent = selSet.size === 0 ? allLabel : (allLabel.replace('全部', '') + '已選 ' + selSet.size);
    }
    function drawList(filter) {
      filter = (filter || '').toLowerCase();
      list.innerHTML = '';
      items.forEach(function (it) {
        var k = String(keyFn(it)), t = textFn(it);
        if (filter && t.toLowerCase().indexOf(filter) < 0) return;
        var lbl = document.createElement('label');
        lbl.className = 'cg-ms-item';
        var cb = document.createElement('input');
        cb.type = 'checkbox'; cb.value = k; cb.checked = selSet.has(k);
        cb.onchange = function () { if (this.checked) selSet.add(k); else selSet.delete(k); refreshBtn(); };
        lbl.appendChild(cb); lbl.appendChild(document.createTextNode(t));
        list.appendChild(lbl);
      });
    }
    btn.onclick = function () {
      var wasOpen = panel.classList.contains('open');
      Array.prototype.forEach.call(document.querySelectorAll('.cg-ms-panel.open'), function (p) { p.classList.remove('open'); });
      if (!wasOpen) { panel.classList.add('open'); drawList(search.value); search.focus(); }
    };
    search.oninput = function () { drawList(this.value); };
    host.querySelector('.cg-ms-all').onclick = function () { items.forEach(function (it) { selSet.add(String(keyFn(it))); }); drawList(search.value); refreshBtn(); };
    host.querySelector('.cg-ms-none').onclick = function () { selSet.clear(); drawList(search.value); refreshBtn(); };
    refreshBtn();
  }

  // ---- 開啟 ------------------------------------------------------------------
  window.openCapacityGantt = function () {
    build();
    document.getElementById('cg-overlay').style.display = 'block';
    // 預設近60天
    if (!document.getElementById('cg-start').value) {
      var end = todayStr(), start = addDays(end, -59);
      document.getElementById('cg-start').value = start;
      document.getElementById('cg-end').value = end;
    }
    if (state.makers.length === 0) loadFilters();
  };
  function closeModal() { document.getElementById('cg-overlay').style.display = 'none'; hideTip(); }

  function loadFilters() {
    $.post(PAGE, { action: 'get_gantt_filters' }, function (res) {
      if (!res || !res.success) { return; }
      state.makers = res.makers || [];
      state.procs = res.processes || [];
      buildMultiSelect('cg-ms-maker', '全部廠商', state.makers,
        function (m) { return m.maker_id_no; },
        function (m) { return m.maker_id + (String(m.internal) === '1' ? '（廠內）' : ''); },
        state.selMakers);
      buildMultiSelect('cg-ms-proc', '全部製程', state.procs,
        function (p) { return p.process_no; },
        function (p) { return (p.ProcessName || ('製程' + p.process_no)); },
        state.selProcs);
    }, 'json');
  }

  // ---- 查詢 ------------------------------------------------------------------
  function runQuery() {
    var start = document.getElementById('cg-start').value;
    var end = document.getElementById('cg-end').value;
    if (!start || !end) { alert('請選擇起訖日期'); return; }
    var body = document.getElementById('cg-body');
    body.innerHTML = '<div style="padding:30px;color:#888;">查詢中…</div>';
    $.post(PAGE, {
      action: 'get_capacity_gantt',
      start: start, end: end,
      maker_ids: Array.from(state.selMakers).join(','),
      process_nos: Array.from(state.selProcs).join(',')
    }, function (res) {
      if (!res || !res.success) { body.innerHTML = '<div style="padding:30px;color:#c00;">查詢失敗：' + esc(res && res.message) + '</div>'; return; }
      state.rows = res.rows || [];
      state.meta = { start: res.start, end: res.end, today: res.today };
      state._capped = res.capped;
      render();
    }, 'json').fail(function () { body.innerHTML = '<div style="padding:30px;color:#c00;">連線失敗</div>'; });
  }

  // ---- 繪製 ------------------------------------------------------------------
  function render() {
    var body = document.getElementById('cg-body');
    var meta = state.meta;
    if (!meta.start) { return; }
    var rows = state.rows.slice();
    if (state.hideStale) rows = rows.filter(function (r) { return !r.is_stale; });

    if (rows.length === 0) { body.innerHTML = '<div style="padding:30px;color:#888;">此條件下沒有外包紀錄。</div>'; setStatus(0, 0); document.getElementById('cg-legend').innerHTML = ''; return; }

    var start = meta.start, end = meta.end, today = meta.today;
    var totalDays = dayIdx(end, start) + 1;
    var todayIdx = (today >= start && today <= end) ? dayIdx(today, start) : -1;

    // 版面寬度
    var avail = body.clientWidth - 150 - 24; if (avail < 300) avail = 800;
    var pxDay = Math.max(5, Math.min(46, Math.floor(avail / totalDays)));
    var trackW = totalDays * pxDay;

    // 分組
    var groupBy = state.groupBy;
    var groups = {}; // key -> {label, sub, bars:[]}
    rows.forEach(function (r) {
      var key = groupBy === 'maker' ? (r.maker_no || '_none_') : ('p' + r.process_no);
      var label = groupBy === 'maker' ? r.maker_name : r.proc_name;
      var sub = groupBy === 'maker' ? (String(r.internal) === '1' ? '廠內' : '外包') : ('製程 ' + r.process_no);
      if (!groups[key]) groups[key] = { label: label, sub: sub, bars: [] };
      groups[key].bars.push(r);
    });
    var keys = Object.keys(groups).sort(function (a, b) { return groups[a].label.localeCompare(groups[b].label, 'zh-Hant'); });

    // 色彩維度：分組=廠商時用製程上色，反之用廠商上色
    var colorItems = {};
    function colorOf(r) {
      var ck = groupBy === 'maker' ? r.proc_name : r.maker_name;
      colorItems[ck] = true;
      return 'hsl(' + hueOf(ck) + ',62%,62%)';
    }

    // 組 HTML
    var html = '<div class="cg-grid" style="width:' + (150 + trackW + 4) + 'px;">';

    // 時間軸
    html += '<div class="cg-row cg-axis"><div class="cg-labelcol">廠商 / 製程</div><div class="cg-track" style="width:' + trackW + 'px;">';
    for (var d = 0; d < totalDays; d++) {
      var ds = addDays(start, d);
      var wd = new Date(toUTC(ds)).getUTCDay();
      var cls = 'cg-tick' + (wd === 0 || wd === 6 ? ' weekend' : '');
      var isMonth = ds.slice(8) === '01' || d === 0;
      if (isMonth) cls += ' month';
      html += '<div class="' + cls + '" style="left:' + (d * pxDay) + 'px;width:' + pxDay + 'px;"></div>';
      if (isMonth) html += '<div class="cg-ticklabel" style="left:' + (d * pxDay) + 'px;">' + ds.slice(0, 7) + '</div>';
      else if (pxDay >= 22) html += '<div class="cg-ticklabel" style="left:' + (d * pxDay) + 'px;">' + (+ds.slice(8)) + '</div>';
    }
    if (todayIdx >= 0) html += '<div class="cg-today" style="left:' + (todayIdx * pxDay) + 'px;"></div>';
    html += '</div></div>';

    // 各泳道
    keys.forEach(function (k) {
      var g = groups[k];
      // 區間裁切 + 打包（貪婪分層）
      var segs = g.bars.map(function (r) {
        var bEnd = r.ret_date || today;
        var s = dayIdx(r.out_date, start), e = dayIdx(bEnd, start);
        var cs = Math.max(0, s), ce = Math.min(totalDays - 1, e);
        return { r: r, s: s, e: e, cs: cs, ce: ce, ovL: s < 0, ovR: e > totalDays - 1 };
      }).filter(function (x) { return x.ce >= x.cs; })
        .sort(function (a, b) { return a.cs - b.cs || a.ce - b.ce; });

      var lanes = []; // 每層最後結束 idx
      segs.forEach(function (x) {
        var placed = -1;
        for (var i = 0; i < lanes.length; i++) { if (lanes[i] < x.cs) { placed = i; break; } }
        if (placed < 0) { placed = lanes.length; lanes.push(x.ce); } else lanes[placed] = x.ce;
        x.lane = placed;
      });
      var laneCount = Math.max(1, lanes.length);
      var rowH = laneCount * 17 + 4;

      html += '<div class="cg-row"><div class="cg-labelcol" style="height:' + rowH + 'px;">' +
        esc(g.label) + '<span class="cg-sub">' + esc(g.sub) + '｜峰值 ' + laneCount + ' 件</span></div>' +
        '<div class="cg-track" style="width:' + trackW + 'px;height:' + rowH + 'px;">';
      // 週末底 / 今日線
      for (var dd = 0; dd < totalDays; dd++) {
        var wd2 = new Date(toUTC(addDays(start, dd))).getUTCDay();
        if (wd2 === 0 || wd2 === 6) html += '<div class="cg-tick weekend" style="left:' + (dd * pxDay) + 'px;width:' + pxDay + 'px;"></div>';
      }
      if (todayIdx >= 0) html += '<div class="cg-today" style="left:' + (todayIdx * pxDay) + 'px;"></div>';

      segs.forEach(function (x) {
        var r = x.r;
        var left = x.cs * pxDay;
        var w = (x.ce - x.cs + 1) * pxDay - 2; if (w < 3) w = 3;
        var top = x.lane * 17 + 2;
        var bg = colorOf(r);
        var retTxt = r.ret_date ? (r.ret_label + fmtMD(r.ret_date)) : '在廠中';
        var inner = (w > 46) ? esc(r.proc_name + ' ' + retTxt) : '';
        var arrowL = x.ovL ? '<span class="cg-ov" style="left:1px;">‹</span>' : '';
        var arrowR = x.ovR ? '<span class="cg-ov" style="right:1px;">›</span>' : '';
        html += '<div class="cg-bar' + (r.is_stale ? ' stale' : '') + '" style="left:' + left + 'px;top:' + top + 'px;width:' + w + 'px;background:' + bg + ';" ' +
          'data-tip="' + esc(tipText(r)) + '">' + arrowL + esc(inner) + arrowR + '</div>';
      });
      html += '</div></div>';

      // 每日負載
      if (state.showLoad) {
        var cnt = new Array(totalDays).fill(0), qty = new Array(totalDays).fill(0);
        segs.forEach(function (x) { for (var i = x.cs; i <= x.ce; i++) { cnt[i]++; qty[i] += (x.r.sqty || 0); } });
        var mx = Math.max.apply(null, cnt) || 1;
        html += '<div class="cg-loadrow"><div class="cg-labelcol" style="height:17px;font-weight:400;color:#888;font-size:11px;">每日在廠</div>' +
          '<div class="cg-loadcells" style="width:' + trackW + 'px;">';
        for (var i2 = 0; i2 < totalDays; i2++) {
          if (cnt[i2] === 0) continue;
          var alpha = 0.15 + 0.75 * (cnt[i2] / mx);
          var lbl = pxDay >= 16 ? cnt[i2] : '';
          html += '<div class="cg-lc" style="left:' + (i2 * pxDay) + 'px;width:' + pxDay + 'px;background:rgba(231,76,60,' + alpha.toFixed(2) + ');" ' +
            'data-tip="' + esc(fmtMD(addDays(start, i2)) + '　在廠 ' + cnt[i2] + ' 件 / ' + qty[i2] + ' pcs') + '">' + lbl + '</div>';
        }
        html += '</div></div>';
      }
    });

    html += '</div>';
    body.innerHTML = html;
    bindTips(body);

    // 圖例
    var legKeys = Object.keys(colorItems).sort();
    var leg = document.getElementById('cg-legend');
    leg.innerHTML = '<span style="font-weight:600;">' + (groupBy === 'maker' ? '製程色：' : '廠商色：') + '</span>' +
      legKeys.map(function (ck) { return '<span><i style="background:hsl(' + hueOf(ck) + ',62%,62%)"></i>' + esc(ck) + '</span>'; }).join('') +
      '　<span><i style="background:#ccc;border-style:dashed;opacity:.5"></i>逾60天在廠中(可能忘記回廠)</span>' +
      '　<span><i style="background:rgba(231,76,60,.7)"></i>每日在廠負載</span>';

    setStatus(rows.length, keys.length);
  }

  function tipText(r) {
    var retTxt = r.ret_date ? (r.ret_label + ' ' + r.ret_date) : '在廠中（未回廠）' + (r.is_stale ? '｜逾60天' : '');
    return 'BOM ' + r.bom + '\n製程：' + r.proc_name + '（' + r.process_no + '）\n廠商：' + r.maker_name +
      '\n數量：' + r.sqty + '\n移轉：' + r.out_date + '\n回廠判定：' + retTxt;
  }
  function setStatus(nBar, nLane) {
    var s = '共 ' + nBar + ' 筆製程、' + nLane + ' 個泳道　｜　區間 ' + state.meta.start + ' ~ ' + state.meta.end + '（紅色虛線＝今天）';
    if (state._capped) s += '　⚠ 資料量過大，僅顯示前 4000 筆，請縮小區間或加篩選';
    document.getElementById('cg-status').textContent = s;
  }

  // ---- Tooltip ---------------------------------------------------------------
  function bindTips(scope) {
    scope.addEventListener('mousemove', function (e) {
      var el = e.target.closest('[data-tip]');
      if (!el) { hideTip(); return; }
      var tip = document.getElementById('cg-tip');
      tip.style.display = 'block';
      tip.textContent = el.getAttribute('data-tip');
      var x = e.clientX + 14, y = e.clientY + 14;
      if (x + tip.offsetWidth > window.innerWidth) x = e.clientX - tip.offsetWidth - 14;
      if (y + tip.offsetHeight > window.innerHeight) y = e.clientY - tip.offsetHeight - 14;
      tip.style.left = x + 'px'; tip.style.top = y + 'px';
    });
    scope.addEventListener('mouseleave', hideTip);
  }
  function hideTip() { var t = document.getElementById('cg-tip'); if (t) t.style.display = 'none'; }

  // ---- 匯出 ------------------------------------------------------------------
  function exportCsv() {
    if (!state.rows.length) { alert('沒有資料可匯出'); return; }
    var head = ['廠商', '廠內', '製程', '製程碼', 'BOM', '數量', '移轉日', '回廠判定', '回廠日', '狀態'];
    var lines = [head.join(',')];
    state.rows.forEach(function (r) {
      var cells = [r.maker_name, (String(r.internal) === '1' ? '是' : ''), r.proc_name, r.process_no, r.bom, r.sqty,
        r.out_date, (r.ret_date ? r.ret_label : '在廠中' + (r.is_stale ? '(逾60天)' : '')), (r.ret_date || ''), r.state];
      lines.push(cells.map(function (c) { c = String(c == null ? '' : c); return /[",\n]/.test(c) ? '"' + c.replace(/"/g, '""') + '"' : c; }).join(','));
    });
    var blob = new Blob(['﻿' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8;' });
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = '外包產能_' + state.meta.start + '_' + state.meta.end + '.csv';
    document.body.appendChild(a); a.click(); document.body.removeChild(a);
  }

  function printView() {
    var grid = document.querySelector('#cg-body .cg-grid');
    if (!grid) { alert('請先查詢'); return; }
    var w = window.open('', '_blank');
    var styleEls = document.head.querySelectorAll('style');
    var css = '';
    styleEls.forEach(function (s) { if (s.textContent.indexOf('.cg-bar') >= 0) css = s.textContent; });
    w.document.write('<html><head><meta charset="utf-8"><title>外包產能甘特圖 ' + state.meta.start + '~' + state.meta.end + '</title><style>' +
      css + 'body{margin:10px;font-family:sans-serif;} #cg-tip{display:none!important;}</style></head><body>' +
      '<h3>外包產能甘特圖　' + state.meta.start + ' ~ ' + state.meta.end + '</h3>' +
      '<div id="cg-body">' + grid.outerHTML + '</div>' +
      document.getElementById('cg-legend').outerHTML +
      '</body></html>');
    w.document.close();
    setTimeout(function () { w.print(); }, 400);
  }

})();
