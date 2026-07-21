/* ============================================================================
 * 外包產能甘特圖  capacity_gantt.js
 * 由 OreadyReply_ForPm_BaseOfTime2.php 的「外包產能」按鈕觸發 window.openCapacityGantt()
 * 後端 action：get_gantt_filters / get_capacity_gantt（見 _ajax.php）
 * 每筆製程 = 一條長條（移轉日 → 有效回廠日）。同一泳道長條重疊 = 產能排擠。
 * 長條顏色 = 急件燈號（暖色系）：一般件 / 急件(U) / 特急件(E)。
 * 配色與文字可讀性規範見 CLAUDE.md「UI 規則－配色規範」與 ai-rules/10-配色與文字可讀性.md
 * ==========================================================================*/
(function () {
  'use strict';

  // 以「目前所在頁面」為 AJAX 端點與篩選對象：
  // 測試版嵌在 OreadyReply_ForPm_BaseOfTime2.php、正式版嵌在 OreadyReply_ForPm_BaseOfTime.php，皆自動對應
  var PAGE = (location.pathname.split('/').pop() || 'OreadyReply_ForPm_BaseOfTime2.php');
  var built = false;

  // 急件燈號暖色系（bg 底色 / bd 邊框 / tx 文字，皆已確認對比足夠可讀）
  var PRIO = {
    n: { bg: '#F7E0BD', bd: '#E4C293', tx: '#6B471A', name: '一般件' },
    u: { bg: '#F0A24B', bd: '#D6851F', tx: '#4E2C0B', name: '急件' },
    e: { bg: '#DD5138', bd: '#BE3C25', tx: '#FFFFFF', name: '特急件' }
  };
  var LOAD_RGB = '176,88,38';   // 每日負載熱度（暖棕橘）
  var TODAY_COL = '#B23A2E';    // 今日線（暗紅虛線）

  // 版面常數
  var LABEL_W = 158, AXIS_H = 34, BAR_H = 15, LANE_STEP = 17, LOAD_H = 17;

  var state = {
    makers: [], procs: [], types: [],
    selMakers: new Set(), selProcs: new Set(), selTypes: new Set(),
    prioOn: { n: true, u: true, e: true },   // 燈號篩選（點圖例切換），預設全選
    rows: [], meta: { start: '', end: '', today: '' }, nonwork: new Set(), holidays: {},
    groupBy: 'maker', hideStale: false, showLoad: true, showDash: true, _capped: false,
    dashSort: 'peak', dashDesc: true, overloadPeak: 8
  };

  // ---- 小工具 ----------------------------------------------------------------
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function pad2(n) { return (n < 10 ? '0' : '') + n; }
  function toUTC(d) { var p = d.split('-'); return Date.UTC(+p[0], +p[1] - 1, +p[2]); }
  function dayIdx(dateStr, startStr) { return Math.round((toUTC(dateStr) - toUTC(startStr)) / 86400000); }
  function fmtMD(dateStr) { if (!dateStr) return ''; var p = dateStr.split('-'); return pad2(+p[1]) + '/' + pad2(+p[2]); }
  function todayStr() { var d = new Date(); return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate()); }
  function addDays(dateStr, n) { var d = new Date(toUTC(dateStr) + n * 86400000); return d.getUTCFullYear() + '-' + pad2(d.getUTCMonth() + 1) + '-' + pad2(d.getUTCDate()); }
  function weekday(dateStr) { return new Date(toUTC(dateStr)).getUTCDay(); }

  // ---- 建立 DOM（僅一次）------------------------------------------------------
  function build() {
    if (built) return;
    built = true;

    var style = document.createElement('style');
    style.textContent = [
      // overlay 不擋事件、無深色底 → 甘特圖為可移動的浮動視窗，底下 OreadyReply 頁面仍可操作
      '#cg-overlay{position:fixed;inset:0;background:transparent;z-index:20000;display:none;pointer-events:none;}',
      '#cg-modal{position:fixed;top:3vh;left:3vw;width:94vw;height:92vh;background:#fff;border:1px solid #cbb08f;border-radius:6px;box-shadow:0 8px 34px rgba(0,0,0,.45);display:flex;flex-direction:column;overflow:hidden;font-size:13px;color:#333;pointer-events:auto;}',
      '#cg-head{display:flex;align-items:center;gap:10px;padding:8px 14px;background:#8a5a2b;color:#fff;flex:0 0 auto;cursor:move;user-select:none;}',
      '#cg-head h3{margin:0;font-size:16px;font-weight:700;flex:1;}',
      '#cg-head .cg-x{cursor:pointer;font-size:22px;line-height:1;border:none;background:none;color:#fff;}',
      '#cg-tools{display:flex;flex-wrap:wrap;align-items:center;gap:6px 10px;padding:8px 14px;border-bottom:1px solid #e2e2e2;background:#faf6f0;flex:0 0 auto;}',
      '#cg-tools label{margin:0;font-weight:600;color:#5a4632;}',
      '#cg-tools input[type=date]{height:28px;padding:2px 6px;border:1px solid #ccc;border-radius:4px;}',
      '.cg-qbtn{border:1px solid #e0cbb0;background:#fff;color:#8a5a2b;border-radius:4px;padding:2px 8px;cursor:pointer;font-size:12px;}',
      '.cg-qbtn:hover{background:#f3e7d8;}',
      '.cg-ms{position:relative;display:inline-block;}',
      '.cg-ms-btn{min-width:130px;text-align:left;border:1px solid #ccc;background:#fff;border-radius:4px;padding:3px 8px;cursor:pointer;height:28px;}',
      '.cg-ms-panel{display:none;position:absolute;top:30px;left:0;z-index:30;background:#fff;border:1px solid #bbb;border-radius:4px;box-shadow:0 4px 14px rgba(0,0,0,.2);width:290px;max-height:320px;overflow:auto;padding:6px;}',
      '.cg-ms-panel.open{display:block;}',
      '.cg-ms-panel input.cg-ms-search{width:100%;box-sizing:border-box;margin-bottom:5px;padding:3px 6px;border:1px solid #ccc;border-radius:3px;}',
      '.cg-ms-hint{font-size:11px;color:#a08a6f;margin-bottom:4px;}',
      '.cg-ms-item{display:block;padding:2px 3px;cursor:pointer;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}',
      '.cg-ms-item:hover{background:#f3e7d8;}',
      '.cg-ms-item input{margin-right:5px;}',
      '.cg-ms-tools{display:flex;gap:10px;margin-bottom:5px;}',
      '.cg-ms-tools a{font-size:12px;color:#8a5a2b;cursor:pointer;text-decoration:underline;}',
      '#cg-go{background:#8a5a2b;color:#fff;border:none;border-radius:4px;padding:4px 16px;cursor:pointer;font-weight:700;height:28px;}',
      '#cg-go:hover{background:#734a22;}',
      '#cg-clear{background:#fff;color:#8a5a2b;border:1px solid #cbb08f;border-radius:4px;padding:4px 12px;cursor:pointer;height:28px;}',
      '#cg-clear:hover{background:#f3e7d8;}',
      '.cg-mini{background:#b5762f;color:#fff;border:none;border-radius:4px;padding:3px 10px;cursor:pointer;height:28px;}',
      '.cg-mini.csv{background:#c98a3a;}.cg-mini.img{background:#a2703a;}.cg-mini.print{background:#8c7a63;}',
      '#cg-dashwrap{flex:0 0 auto;}',
      '.cg-dash-head{display:flex;align-items:center;gap:10px;padding:5px 12px 2px;font-weight:700;color:#5a4632;font-size:12px;}',
      '.cg-dash-head input{width:52px;height:22px;padding:1px 4px;border:1px solid #ccc;border-radius:3px;}',
      '.cg-dash{margin:0 12px 6px;border:1px solid #e4c293;border-radius:6px;overflow:auto;max-height:190px;}',
      '.cg-dash table{border-collapse:collapse;width:100%;font-size:12px;}',
      '.cg-dash th,.cg-dash td{padding:3px 10px;border-bottom:1px solid #f0e6d6;text-align:right;white-space:nowrap;}',
      '.cg-dash th{position:sticky;top:0;background:#faf1e4;color:#5a4632;cursor:pointer;user-select:none;}',
      '.cg-dash th.l,.cg-dash td.l{text-align:left;}',
      '.cg-dash tbody tr{cursor:pointer;}',
      '.cg-dash tbody tr:hover{background:#fbf4ea;}',
      '.cg-row.cg-flash>.cg-labelcol{background:#fff2cc;} .cg-row.cg-flash{background:#fff7e6;transition:background .3s;}',
      '.cg-dash tr.over{background:#fdece7;}',
      '.cg-dash tr.over td.l{font-weight:700;color:#c0392b;}',
      '#cg-body{flex:1 1 auto;overflow:auto;position:relative;}',
      '#cg-status{padding:6px 14px;color:#6b5a45;flex:0 0 auto;border-top:1px solid #eee;background:#faf6f0;}',
      '.cg-grid{position:relative;min-width:100%;}',
      '.cg-axis{position:sticky;top:0;z-index:12;background:#fff;border-bottom:2px solid #d8c7b0;height:' + AXIS_H + 'px;}',
      '.cg-row{display:flex;border-bottom:1px solid #efe7db;}',
      '.cg-labelcol{flex:0 0 auto;width:' + LABEL_W + 'px;box-sizing:border-box;padding:4px 8px;font-weight:600;position:sticky;left:0;background:#fff;z-index:8;border-right:2px solid #d8c7b0;display:flex;flex-direction:column;justify-content:center;}',
      '.cg-labelcol .cg-sub{font-weight:400;color:#a08a6f;font-size:11px;}',
      '.cg-track{position:relative;flex:1 1 auto;}',
      '.cg-tick{position:absolute;top:0;bottom:0;border-left:1px solid #f4efe6;}',
      '.cg-tick.month{border-left:1px solid #ddcdb5;}',
      '.cg-tick.weekend{background:rgba(140,110,70,.05);}',
      '.cg-ticklabel{position:absolute;top:2px;font-size:10px;color:#a08a6f;transform:translateX(2px);white-space:nowrap;}',
      '.cg-today{position:absolute;top:0;bottom:0;border-left:2px dashed ' + TODAY_COL + ';z-index:6;}',
      '.cg-bar{position:absolute;height:' + BAR_H + 'px;border-radius:3px;box-sizing:border-box;overflow:hidden;font-size:10px;line-height:' + (BAR_H - 1) + 'px;white-space:nowrap;padding:0 4px;cursor:pointer;border:1px solid;}',
      '.cg-bar.p-n{background:' + PRIO.n.bg + ';border-color:' + PRIO.n.bd + ';color:' + PRIO.n.tx + ';}',
      '.cg-bar.p-u{background:' + PRIO.u.bg + ';border-color:' + PRIO.u.bd + ';color:' + PRIO.u.tx + ';}',
      '.cg-bar.p-e{background:' + PRIO.e.bg + ';border-color:' + PRIO.e.bd + ';color:' + PRIO.e.tx + ';}',
      '.cg-bar:hover{outline:2px solid #5a4632;outline-offset:-1px;filter:brightness(1.06);}',
      '.cg-bar.due-over{box-shadow:inset 0 3px 0 #c0392b;}',   // 交期已過卻仍在廠：頂部紅條
      '.cg-bar.stale{opacity:.42;border-style:dashed;}',
      '.cg-bar .cg-ov{position:absolute;top:0;font-weight:700;}',
      '.cg-loadrow{display:flex;border-bottom:1px solid #efe7db;background:#fbf7f1;}',
      '.cg-loadcells{position:relative;flex:1 1 auto;height:' + LOAD_H + 'px;}',
      '.cg-lc{position:absolute;top:1px;bottom:1px;font-size:9px;text-align:center;color:#3a2a18;overflow:hidden;}',
      '.cg-legend{display:flex;flex-wrap:wrap;gap:6px 14px;padding:6px 14px;border-top:1px solid #eee;flex:0 0 auto;align-items:center;}',
      '.cg-legend span{font-size:12px;display:inline-flex;align-items:center;gap:4px;}',
      '.cg-legend i{width:14px;height:14px;border-radius:3px;display:inline-block;border:1px solid rgba(0,0,0,.2);}',
      '#cg-tip{position:fixed;z-index:30000;display:none;background:#3a2a18;color:#fff;padding:6px 9px;border-radius:4px;font-size:12px;max-width:340px;pointer-events:none;box-shadow:0 3px 12px rgba(0,0,0,.4);line-height:1.55;white-space:pre-line;}'
    ].join('\n');
    document.head.appendChild(style);

    var ov = document.createElement('div');
    ov.id = 'cg-overlay';
    ov.innerHTML =
      '<div id="cg-modal">' +
      '  <div id="cg-head"><h3><i class="fa fa-bar-chart"></i> 外包產能甘特圖</h3>' +
      '     <span style="font-size:11px;font-weight:400;opacity:.85;">（拖曳標題可移動視窗；點長條＝在下方頁面篩選該廠商該件）</span>' +
      '     <button class="cg-x" title="關閉">&times;</button></div>' +
      '  <div id="cg-tools">' +
      '     <label>區間</label>' +
      '     <input type="date" id="cg-start"> ~ <input type="date" id="cg-end">' +
      '     <button class="cg-qbtn" data-days="30">近30天</button>' +
      '     <button class="cg-qbtn" data-days="60">近60天</button>' +
      '     <button class="cg-qbtn" data-days="90">近90天</button>' +
      '     <button class="cg-qbtn" data-month="1">本月</button>' +
      '     <span style="width:6px"></span>' +
      '     <label>分組</label>' +
      '     <label style="font-weight:400"><input type="radio" name="cg-group" value="maker" checked> 依廠商</label>' +
      '     <label style="font-weight:400"><input type="radio" name="cg-group" value="ptype"> 依製程大類</label>' +
      '     <label style="font-weight:400"><input type="radio" name="cg-group" value="process"> 依製程小類</label>' +
      '     <label style="font-weight:400"><input type="radio" name="cg-group" value="client"> 依客戶</label>' +
      '     <label style="font-weight:400"><input type="radio" name="cg-group" value="part"> 依料號</label>' +
      '     <div class="cg-ms" id="cg-ms-maker"></div>' +
      '     <div class="cg-ms" id="cg-ms-type"></div>' +
      '     <div class="cg-ms" id="cg-ms-proc"></div>' +
      '     <button id="cg-go">查詢</button>' +
      '     <button id="cg-clear" title="清除廠商/製程篩選與分組">清除篩選</button>' +
      '     <label style="font-weight:400"><input type="checkbox" id="cg-hidestale"> 隱藏逾期在廠中(&gt;60天)</label>' +
      '     <label style="font-weight:400"><input type="checkbox" id="cg-showload" checked> 每日負荷</label>' +
      '     <label style="font-weight:400"><input type="checkbox" id="cg-showdash" checked> 廠商負荷看板</label>' +
      '     <span style="flex:1"></span>' +
      '     <button class="cg-mini img" id="cg-img">轉圖片</button>' +
      '     <button class="cg-mini csv" id="cg-csv">轉 CSV</button>' +
      '     <button class="cg-mini print" id="cg-print">列印</button>' +
      '  </div>' +
      '  <div id="cg-dashwrap"></div>' +
      '  <div id="cg-body"><div style="padding:30px;color:#a08a6f;">請設定條件後按「查詢」。</div></div>' +
      '  <div class="cg-legend" id="cg-legend"></div>' +
      '  <div id="cg-status"></div>' +
      '</div>';
    document.body.appendChild(ov);

    var tip = document.createElement('div'); tip.id = 'cg-tip'; document.body.appendChild(tip);

    ov.querySelector('.cg-x').onclick = closeModal;
    setupDrag();
    document.getElementById('cg-go').onclick = runQuery;
    document.getElementById('cg-clear').onclick = clearFilters;
    document.getElementById('cg-csv').onclick = exportCsv;
    document.getElementById('cg-img').onclick = exportImage;
    document.getElementById('cg-print').onclick = printView;
    document.getElementById('cg-hidestale').onchange = function () { state.hideStale = this.checked; render(); };
    document.getElementById('cg-showload').onchange = function () { state.showLoad = this.checked; render(); };
    document.getElementById('cg-showdash').onchange = function () { state.showDash = this.checked; render(); };
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
    document.addEventListener('mousedown', function (e) {
      if (!e.target.closest('.cg-ms')) {
        Array.prototype.forEach.call(document.querySelectorAll('.cg-ms-panel.open'), function (p) { p.classList.remove('open'); });
      }
    });
  }

  // ---- 多選元件（searchFn 決定可搜尋的字串，可含代號/編號/類別）------------------
  function buildMultiSelect(hostId, allLabel, items, keyFn, textFn, selSet, searchFn, hint) {
    var host = document.getElementById(hostId);
    host.innerHTML =
      '<button type="button" class="cg-ms-btn"></button>' +
      '<div class="cg-ms-panel">' +
      (hint ? '<div class="cg-ms-hint">' + esc(hint) + '</div>' : '') +
      '  <input type="text" class="cg-ms-search" placeholder="搜尋…">' +
      '  <div class="cg-ms-tools"><a class="cg-ms-all">全選(目前結果)</a><a class="cg-ms-none">清除</a></div>' +
      '  <div class="cg-ms-list"></div>' +
      '</div>';
    var btn = host.querySelector('.cg-ms-btn');
    var panel = host.querySelector('.cg-ms-panel');
    var list = host.querySelector('.cg-ms-list');
    var search = host.querySelector('.cg-ms-search');
    var curFilter = '';

    function refreshBtn() {
      var prefix = allLabel.replace('全部', '');
      btn.textContent = selSet.size === 0 ? allLabel : (prefix + '已選 ' + selSet.size);
    }
    function filtered() {
      var f = curFilter.toLowerCase();
      return items.filter(function (it) { return !f || searchFn(it).indexOf(f) >= 0; });
    }
    function drawList() {
      list.innerHTML = '';
      filtered().forEach(function (it) {
        var k = String(keyFn(it));
        var lbl = document.createElement('label'); lbl.className = 'cg-ms-item';
        var cb = document.createElement('input'); cb.type = 'checkbox'; cb.value = k; cb.checked = selSet.has(k);
        cb.onchange = function () { if (this.checked) selSet.add(k); else selSet.delete(k); refreshBtn(); };
        lbl.appendChild(cb); lbl.appendChild(document.createTextNode(textFn(it)));
        list.appendChild(lbl);
      });
    }
    btn.onclick = function () {
      var wasOpen = panel.classList.contains('open');
      Array.prototype.forEach.call(document.querySelectorAll('.cg-ms-panel.open'), function (p) { p.classList.remove('open'); });
      if (!wasOpen) { panel.classList.add('open'); drawList(); search.focus(); }
    };
    search.oninput = function () { curFilter = this.value; drawList(); };
    // 全選：只加入「目前搜尋結果」，避免整批誤選
    host.querySelector('.cg-ms-all').onclick = function () { filtered().forEach(function (it) { selSet.add(String(keyFn(it))); }); drawList(); refreshBtn(); };
    host.querySelector('.cg-ms-none').onclick = function () { selSet.clear(); drawList(); refreshBtn(); };
    refreshBtn();
  }

  function buildMakerMS() {
    buildMultiSelect('cg-ms-maker', '全部廠商', state.makers,
      function (m) { return m.maker_id_no; },
      function (m) { return m.maker_id + (String(m.internal) === '1' ? '（廠內）' : '') + '  [' + m.maker_id_no + ']'; },
      state.selMakers,
      function (m) { return (m.maker_id + ' ' + m.maker_id_no).toLowerCase(); },
      '可打廠商名稱或代號(部分即可)');
  }
  function buildTypeMS() {
    buildMultiSelect('cg-ms-type', '全部製程大類', state.types,
      function (t) { return t.process_type_id; },
      function (t) { return t.process_type_id + '　' + t.process_type; },
      state.selTypes,
      function (t) { return (t.process_type_id + ' ' + t.process_type).toLowerCase(); },
      '製程大類（可打名稱或編號）');
  }
  function buildProcMS() {
    buildMultiSelect('cg-ms-proc', '全部製程(小類)', state.procs,
      function (p) { return p.process_no; },
      function (p) { return '[' + (p.process_type || '未分類') + '] ' + p.process_no + '　' + (p.ProcessName || ''); },
      state.selProcs,
      function (p) { return (p.process_no + ' ' + (p.ProcessName || '') + ' ' + (p.process_type || '') + ' 類別' + (p.process_type_id || '')).toLowerCase(); },
      '單一製程（小類）：可打製程編號或名稱');
  }

  // ---- 開啟 / 清除 -----------------------------------------------------------
  window.openCapacityGantt = function () {
    build();
    document.getElementById('cg-overlay').style.display = 'block';
    if (!document.getElementById('cg-start').value) {
      var end = todayStr(), start = addDays(end, -59);
      document.getElementById('cg-start').value = start;
      document.getElementById('cg-end').value = end;
    }
    if (state.makers.length === 0) loadFilters();
  };
  function closeModal() { document.getElementById('cg-overlay').style.display = 'none'; hideTip(); }

  // ---- 拖曳移動視窗 ----------------------------------------------------------
  function setupDrag() {
    var head = document.getElementById('cg-head');
    var modal = document.getElementById('cg-modal');
    var dragging = false, ox = 0, oy = 0;
    head.addEventListener('mousedown', function (e) {
      if (e.target.closest('.cg-x')) return;
      var r = modal.getBoundingClientRect();
      modal.style.left = r.left + 'px'; modal.style.top = r.top + 'px';
      modal.style.width = r.width + 'px'; modal.style.height = r.height + 'px';
      dragging = true; ox = e.clientX - r.left; oy = e.clientY - r.top; e.preventDefault();
    });
    document.addEventListener('mousemove', function (e) {
      if (!dragging) return;
      var x = Math.max(-modal.offsetWidth + 120, Math.min(window.innerWidth - 120, e.clientX - ox));
      var y = Math.max(0, Math.min(window.innerHeight - 40, e.clientY - oy));
      modal.style.left = x + 'px'; modal.style.top = y + 'px';
    });
    document.addEventListener('mouseup', function () { dragging = false; });
  }

  // ---- 點長條 → 篩選下方 OreadyReply 頁面（本廠商 + 本 BOM）--------------------
  function filterHostPage(bom, maker) {
    var venIn = document.getElementById('vendor-filter');
    var bomIn = document.getElementById('bom-filter');
    if (!bomIn) { alert('找不到頁面的 BOM 篩選欄（此頁可能非 OreadyReply 主頁）'); return; }
    if (venIn) venIn.value = maker || '';
    bomIn.value = bom;
    // 觸發頁面既有的篩選（兩頁皆綁定 input 事件 → processAndRenderData）
    if (venIn) venIn.dispatchEvent(new Event('input', { bubbles: true }));
    bomIn.dispatchEvent(new Event('input', { bubbles: true }));
    closeModal();   // 關閉視窗以便看到篩選後的清單
  }

  function clearFilters() {
    state.selMakers.clear(); state.selProcs.clear(); state.selTypes.clear();
    state.groupBy = 'maker'; state.hideStale = false;
    state.prioOn = { n: true, u: true, e: true };
    var mk = document.querySelector('input[name=cg-group][value=maker]'); if (mk) mk.checked = true;
    document.getElementById('cg-hidestale').checked = false;
    if (state.makers.length) buildMakerMS();
    if (state.types.length) buildTypeMS();
    if (state.procs.length) buildProcMS();
    // 已查詢過就重新以「無篩選」查一次，讓圖表也跟著清空篩選
    if (state.rows.length) runQuery();
  }

  function loadFilters() {
    $.post(PAGE, { action: 'get_gantt_filters' }, function (res) {
      if (!res || !res.success) return;
      state.makers = res.makers || [];
      state.procs = res.processes || [];
      state.types = res.types || [];
      buildMakerMS();
      buildTypeMS();
      buildProcMS();
    }, 'json');
  }

  // ---- 查詢 ------------------------------------------------------------------
  function runQuery() {
    var start = document.getElementById('cg-start').value;
    var end = document.getElementById('cg-end').value;
    if (!start || !end) { alert('請選擇起訖日期'); return; }
    var body = document.getElementById('cg-body');
    body.innerHTML = '<div style="padding:30px;color:#a08a6f;">查詢中…</div>';
    $.post(PAGE, {
      action: 'get_capacity_gantt', start: start, end: end,
      maker_ids: Array.from(state.selMakers).join(','),
      process_nos: Array.from(state.selProcs).join(','),
      process_type_ids: Array.from(state.selTypes).join(',')
    }, function (res) {
      if (!res || !res.success) { body.innerHTML = '<div style="padding:30px;color:#c00;">查詢失敗：' + esc(res && res.message) + '</div>'; return; }
      state.rows = res.rows || [];
      state.meta = { start: res.start, end: res.end, today: res.today };
      state.nonwork = new Set(res.nonwork || []);   // 非工作日(依 calendar.php：休假/週末，扣除補班)
      state.holidays = res.holidays || {};          // date -> 休假名稱
      state._capped = res.capped;
      render();
    }, 'json').fail(function () { body.innerHTML = '<div style="padding:30px;color:#c00;">連線失敗</div>'; });
  }

  // ---- 版面計算（DOM 與 圖片匯出共用）-----------------------------------------
  function computeLayout(availWidth) {
    var meta = state.meta;
    var rows = state.rows.slice();
    if (state.hideStale) rows = rows.filter(function (r) { return !r.is_stale; });
    rows = rows.filter(function (r) { return state.prioOn[r.prio || 'n']; });   // 燈號篩選
    if (!meta.start || rows.length === 0) return null;

    var start = meta.start, end = meta.end, today = meta.today;
    var totalDays = dayIdx(end, start) + 1;
    var todayIdx = (today >= start && today <= end) ? dayIdx(today, start) : -1;
    var avail = availWidth - LABEL_W - 24; if (avail < 300) avail = 900;
    var pxDay = Math.max(5, Math.min(46, Math.floor(avail / totalDays)));
    var trackW = totalDays * pxDay;

    var groupBy = state.groupBy;
    var gmap = {};
    rows.forEach(function (r) {
      var key, label, sub;
      if (groupBy === 'ptype') { key = 't' + r.ptype_id; label = r.ptype_name || '未分類'; sub = '製程大類'; }
      else if (groupBy === 'process') { key = 'p' + r.process_no; label = r.proc_name; sub = '製程小類 ' + r.process_no; }
      else if (groupBy === 'client') { key = 'c' + (r.client || '_'); label = r.client || '(無客戶)'; sub = '客戶'; }
      else if (groupBy === 'part') { key = 'd' + (r.d_id || '_'); label = r.d_id || '(無料號)'; sub = '料號'; }
      else { key = r.maker_no || '_'; label = r.maker_name; sub = (String(r.internal) === '1' ? '廠內' : '外包'); }
      if (!gmap[key]) gmap[key] = { label: label, sub: sub, bars: [] };
      gmap[key].bars.push(r);
    });
    var keys = Object.keys(gmap).sort(function (a, b) { return gmap[a].label.localeCompare(gmap[b].label, 'zh-Hant'); });

    var groups = keys.map(function (k) {
      var g = gmap[k];
      var segs = g.bars.map(function (r) {
        var bEnd = r.ret_date || today;
        var s = dayIdx(r.out_date, start), e = dayIdx(bEnd, start);
        return { r: r, s: s, e: e, cs: Math.max(0, s), ce: Math.min(totalDays - 1, e), ovL: s < 0, ovR: e > totalDays - 1 };
      }).filter(function (x) { return x.ce >= x.cs; }).sort(function (a, b) { return a.cs - b.cs || a.ce - b.ce; });
      var lanes = [];
      segs.forEach(function (x) {
        var placed = -1;
        for (var i = 0; i < lanes.length; i++) { if (lanes[i] < x.cs) { placed = i; break; } }
        if (placed < 0) { placed = lanes.length; lanes.push(x.ce); } else lanes[placed] = x.ce;
        x.lane = placed;
      });
      // 每日負載
      var cnt = new Array(totalDays).fill(0), qty = new Array(totalDays).fill(0);
      segs.forEach(function (x) { for (var i = x.cs; i <= x.ce; i++) { cnt[i]++; qty[i] += (x.r.sqty || 0); } });
      // 平均回廠加工日（僅計已回廠/結案等有回廠日者）
      var wSum = 0, wN = 0;
      segs.forEach(function (x) { if (x.r.ret_src !== 'ongoing') { wSum += (x.r.work_days || 0); wN++; } });
      var avgWork = wN ? Math.round(wSum / wN * 10) / 10 : null;
      return { key: k, label: g.label, sub: g.sub, segs: segs, laneCount: Math.max(1, lanes.length), cnt: cnt, qty: qty, avgWork: avgWork, retN: wN };
    });

    return { start: start, end: end, today: today, totalDays: totalDays, todayIdx: todayIdx, pxDay: pxDay, trackW: trackW, groups: groups, nBar: rows.length, flatRows: rows };
  }

  // ---- 廠商負荷看板 ----------------------------------------------------------
  function peakConcurrent(segs, totalDays) {
    var cnt = new Array(totalDays).fill(0), mx = 0;
    segs.forEach(function (x) { for (var i = x.cs; i <= x.ce; i++) { cnt[i]++; if (cnt[i] > mx) mx = cnt[i]; } });
    return mx;
  }
  function renderDashboard(L) {
    var start = L.start, totalDays = L.totalDays, today = L.today, byV = {};
    L.flatRows.forEach(function (r) {
      var k = r.maker_no || '_';
      if (!byV[k]) byV[k] = { name: r.maker_name, internal: r.internal, rows: [] };
      byV[k].rows.push(r);
    });
    var stats = Object.keys(byV).map(function (k) {
      var v = byV[k], segs = [], wSum = 0, wN = 0, qty = 0, overdue = 0, stale = 0;
      v.rows.forEach(function (r) {
        var bEnd = r.ret_date || today, s = dayIdx(r.out_date, start), e = dayIdx(bEnd, start);
        var cs = Math.max(0, s), ce = Math.min(totalDays - 1, e);
        if (ce >= cs) segs.push({ cs: cs, ce: ce });
        qty += r.sqty || 0;
        if (r.ret_src !== 'ongoing') { wSum += (r.work_days || 0); wN++; }
        if (r.ret_src === 'ongoing' && r.delivery && r.delivery < today) overdue++;
        if (r.is_stale) stale++;
      });
      return { mk: k, name: v.name, internal: v.internal, pieces: v.rows.length, qty: qty,
        peak: peakConcurrent(segs, totalDays), avg: (wN ? Math.round(wSum / wN * 10) / 10 : null), overdue: overdue, stale: stale };
    });
    var key = state.dashSort, desc = state.dashDesc;
    stats.sort(function (a, b) {
      if (key === 'name') return (desc ? -1 : 1) * String(a.name).localeCompare(String(b.name), 'zh-Hant');
      var va = a[key] == null ? -1 : a[key], vb = b[key] == null ? -1 : b[key];
      return desc ? (vb - va) : (va - vb);
    });
    function th(k, txt, cls) { var ar = state.dashSort === k ? (state.dashDesc ? ' ▼' : ' ▲') : ''; return '<th class="' + (cls || '') + '" data-sort="' + k + '">' + txt + ar + '</th>'; }
    var h = '<div class="cg-dash-head">廠商負荷看板（' + stats.length + ' 家）　峰值≥ <input type="number" id="cg-ol" value="' + state.overloadPeak + '" min="1"> 標紅' +
      '　<span style="font-weight:400;color:#a08a6f">點標題排序</span></div>' +
      '<div class="cg-dash"><table><thead><tr>' +
      th('name', '廠商', 'l') + th('pieces', '件數') + th('qty', '數量') + th('peak', '峰值同時') +
      th('avg', '平均回廠加工日') + th('overdue', '逾期未回') + th('stale', '逾60天在廠') +
      '</tr></thead><tbody>';
    stats.forEach(function (s) {
      var over = s.peak >= state.overloadPeak;
      h += '<tr class="' + (over ? 'over' : '') + '" data-mk="' + esc(s.mk) + '" title="點此跳到甘特圖此廠商列"><td class="l">' + esc(s.name) + (String(s.internal) === '1' ? ' <span style="color:#a08a6f">(廠內)</span>' : '') + '</td>' +
        '<td>' + s.pieces + '</td><td>' + s.qty + '</td><td>' + s.peak + '</td>' +
        '<td>' + (s.avg == null ? '—' : s.avg) + '</td>' +
        '<td' + (s.overdue ? ' style="color:#c0392b;font-weight:700"' : '') + '>' + s.overdue + '</td>' +
        '<td' + (s.stale ? ' style="color:#b5762f"' : '') + '>' + s.stale + '</td></tr>';
    });
    return h + '</tbody></table></div>';
  }
  function paintDashboard(L) {
    var wrap = document.getElementById('cg-dashwrap');
    if (!wrap) return;
    if (!L || !state.showDash) { wrap.innerHTML = ''; return; }
    wrap.innerHTML = renderDashboard(L);
    Array.prototype.forEach.call(wrap.querySelectorAll('th[data-sort]'), function (thEl) {
      thEl.onclick = function () {
        var k = this.dataset.sort;
        if (state.dashSort === k) state.dashDesc = !state.dashDesc;
        else { state.dashSort = k; state.dashDesc = (k !== 'name'); }
        render();
      };
    });
    var ol = document.getElementById('cg-ol');
    if (ol) ol.onchange = function () { var v = parseInt(this.value, 10); state.overloadPeak = (v > 0 ? v : 1); render(); };
    Array.prototype.forEach.call(wrap.querySelectorAll('tbody tr[data-mk]'), function (tr) {
      tr.onclick = function () { scrollToVendor(this.getAttribute('data-mk')); };
    });
  }
  // 點看板某廠商 → 甘特圖捲動並高亮該廠商列（非依廠商分組時先切回依廠商）
  function scrollToVendor(mk) {
    if (state.groupBy !== 'maker') {
      state.groupBy = 'maker';
      var rb = document.querySelector('input[name=cg-group][value=maker]'); if (rb) rb.checked = true;
      render();
    }
    var body = document.getElementById('cg-body'), target = null;
    Array.prototype.forEach.call(body.querySelectorAll('.cg-row[data-gkey]'), function (el) {
      if (el.getAttribute('data-gkey') === String(mk)) target = el;
    });
    if (target) {
      body.scrollTop = Math.max(0, target.offsetTop - AXIS_H - 6);
      target.classList.add('cg-flash');
      setTimeout(function () { target.classList.remove('cg-flash'); }, 1800);
    }
  }

  function barLabel(r, w) {
    if (w < 38) return '';
    // 由左到右資訊量遞增，寬度不夠時由 CSS/clip 自動截斷（BOM 一定看得到）
    var ret = r.ret_date ? (r.ret_label + fmtMD(r.ret_date)) : '在廠中';
    var parts = [r.bom, '×' + r.sqty, ret];
    if (r.client) parts.push(r.client);
    if (r.d_id) parts.push(r.d_id);
    parts.push(r.proc_name);
    return parts.join('　');
  }

  // ---- 繪製（DOM）------------------------------------------------------------
  function render() {
    var body = document.getElementById('cg-body');
    if (!state.meta.start) return;
    var L = computeLayout(body.clientWidth);
    if (!L) { body.innerHTML = '<div style="padding:30px;color:#a08a6f;">此條件下沒有外包紀錄。</div>'; setStatus(0, 0); document.getElementById('cg-legend').innerHTML = ''; paintDashboard(null); return; }

    var pxDay = L.pxDay, totalDays = L.totalDays, trackW = L.trackW, start = L.start;
    var html = '<div class="cg-grid" style="width:' + (LABEL_W + trackW + 4) + 'px;">';

    // 時間軸
    html += '<div class="cg-row cg-axis"><div class="cg-labelcol">廠商 / 製程</div><div class="cg-track" style="width:' + trackW + 'px;">';
    for (var d = 0; d < totalDays; d++) {
      var ds = addDays(start, d);
      var isMonth = ds.slice(8) === '01' || d === 0;
      var cls = 'cg-tick' + (state.nonwork.has(ds) ? ' weekend' : '') + (isMonth ? ' month' : '');
      var htitle = state.holidays[ds] ? ' title="' + esc(state.holidays[ds]) + '"' : '';
      html += '<div class="' + cls + '" style="left:' + (d * pxDay) + 'px;width:' + pxDay + 'px;"' + htitle + '></div>';
      if (isMonth) html += '<div class="cg-ticklabel" style="left:' + (d * pxDay) + 'px;">' + ds.slice(0, 7) + '</div>';
      else if (pxDay >= 22) html += '<div class="cg-ticklabel" style="left:' + (d * pxDay) + 'px;">' + (+ds.slice(8)) + '</div>';
    }
    if (L.todayIdx >= 0) html += '<div class="cg-today" style="left:' + (L.todayIdx * pxDay) + 'px;"></div>';
    html += '</div></div>';

    L.groups.forEach(function (g) {
      var rowH = g.laneCount * LANE_STEP + 4;
      var avgTxt = (g.avgWork != null) ? ('｜平均回廠 ' + g.avgWork + ' 加工日(' + g.retN + ')') : '｜平均回廠 —';
      html += '<div class="cg-row" data-gkey="' + esc(g.key) + '"><div class="cg-labelcol" style="height:' + rowH + 'px;">' +
        esc(g.label) + '<span class="cg-sub">' + esc(g.sub) + '｜峰值 ' + g.laneCount + ' 件' + esc(avgTxt) + '</span></div>' +
        '<div class="cg-track" style="width:' + trackW + 'px;height:' + rowH + 'px;">';
      for (var dd = 0; dd < totalDays; dd++) {
        if (state.nonwork.has(addDays(start, dd))) html += '<div class="cg-tick weekend" style="left:' + (dd * pxDay) + 'px;width:' + pxDay + 'px;"></div>';
      }
      if (L.todayIdx >= 0) html += '<div class="cg-today" style="left:' + (L.todayIdx * pxDay) + 'px;"></div>';
      g.segs.forEach(function (x) {
        var r = x.r, left = x.cs * pxDay, w = (x.ce - x.cs + 1) * pxDay - 2; if (w < 3) w = 3;
        var top = x.lane * LANE_STEP + 2;
        var overdue = (r.ret_src === 'ongoing' && r.delivery && r.delivery < L.today);
        html += '<div class="cg-bar p-' + (r.prio || 'n') + (r.is_stale ? ' stale' : '') + (overdue ? ' due-over' : '') + '" style="left:' + left + 'px;top:' + top + 'px;width:' + w + 'px;" title="點此在下方頁面篩選：' + esc(r.maker_name + ' / ' + r.bom) + '" data-bom="' + esc(r.bom) + '" data-maker="' + esc(r.maker_name) + '" data-tip="' + esc(tipText(r)) + '">' +
          (x.ovL ? '<span class="cg-ov" style="left:1px;">‹</span>' : '') + esc(barLabel(r, w)) +
          (x.ovR ? '<span class="cg-ov" style="right:1px;">›</span>' : '') + '</div>';
      });
      html += '</div></div>';

      if (state.showLoad) {
        var mx = Math.max.apply(null, g.cnt) || 1;
        html += '<div class="cg-loadrow"><div class="cg-labelcol" style="height:' + LOAD_H + 'px;font-weight:400;color:#a08a6f;font-size:11px;">每日負荷</div>' +
          '<div class="cg-loadcells" style="width:' + trackW + 'px;">';
        for (var i2 = 0; i2 < totalDays; i2++) {
          if (g.cnt[i2] === 0) continue;
          var alpha = (0.18 + 0.72 * (g.cnt[i2] / mx)).toFixed(2);
          html += '<div class="cg-lc" style="left:' + (i2 * pxDay) + 'px;width:' + pxDay + 'px;background:rgba(' + LOAD_RGB + ',' + alpha + ');" ' +
            'data-tip="' + esc(fmtMD(addDays(start, i2)) + '　負荷 ' + g.cnt[i2] + ' 件 / ' + g.qty[i2] + ' pcs') + '">' + (pxDay >= 16 ? g.cnt[i2] : '') + '</div>';
        }
        html += '</div></div>';
      }
    });

    html += '</div>';
    body.innerHTML = html;
    bindTips(body);
    paintDashboard(L);

    function legItem(code, txt) {
      var p = PRIO[code], on = state.prioOn[code];
      return '<span class="cg-prio" data-prio="' + code + '" title="點選切換顯示此燈號" style="cursor:pointer;user-select:none;' + (on ? '' : 'opacity:.35;text-decoration:line-through;') + '">' +
        '<i style="background:' + p.bg + ';border-color:' + p.bd + '"></i>' + txt + '</span>';
    }
    var leg = document.getElementById('cg-legend');
    leg.innerHTML =
      '<span style="font-weight:700;color:#5a4632;">燈號(可點選篩選)：</span>' +
      legItem('n', '一般件') + legItem('u', '急件(U)') + legItem('e', '特急件(E)') +
      '<span><i style="background:' + PRIO.n.bg + ';border-style:dashed;opacity:.45"></i>逾60天在廠中(可能忘記回廠)</span>' +
      '<span><i style="background:' + PRIO.n.bg + ';box-shadow:inset 0 3px 0 #c0392b"></i>交期已過卻未回廠</span>' +
      '<span><i style="background:rgba(' + LOAD_RGB + ',.7)"></i>每日負荷</span>' +
      '<span style="color:' + TODAY_COL + ';">┋ 今天</span>';
    Array.prototype.forEach.call(leg.querySelectorAll('.cg-prio'), function (el) {
      el.onclick = function () {
        var c = this.dataset.prio;
        state.prioOn[c] = !state.prioOn[c];
        if (!state.prioOn.n && !state.prioOn.u && !state.prioOn.e) state.prioOn[c] = true; // 至少留一個
        render();
      };
    });

    setStatus(L.nBar, L.groups.length);
  }

  function tipText(r) {
    var ret = r.ret_date ? (r.ret_label + ' ' + r.ret_date) : ('在廠中（未回廠）' + (r.is_stale ? '｜逾60天' : ''));
    return 'BOM ' + r.bom + '　【' + r.prio_label + '】' +
      (r.client ? '\n客戶：' + r.client : '') +
      (r.d_id ? '\n料號：' + r.d_id : '') +
      '\n製程：' + r.proc_name + '（' + r.process_no + '／' + (r.ptype_name || '未分類') + '）' +
      '\n廠商：' + r.maker_name +
      '\n數量：' + r.sqty +
      (r.delivery ? '\n交期：' + r.delivery + ((r.ret_src === 'ongoing' && r.delivery < state.meta.today) ? '（已逾期未回廠）' : '') : '') +
      '\n移轉：' + r.out_date +
      '\n回廠判定：' + ret + (r.ret_src !== 'ongoing' ? '（在廠 ' + r.work_days + ' 加工日）' : '');
  }
  function setStatus(nBar, nLane) {
    var s = '共 ' + nBar + ' 筆製程、' + nLane + ' 個泳道　｜　區間 ' + state.meta.start + ' ~ ' + state.meta.end + '（' + TODAY_COL_TXT() + '＝今天）';
    if (state._capped) s += '　⚠ 資料量過大，僅顯示前 4000 筆，請縮小區間或加篩選';
    document.getElementById('cg-status').textContent = s;
  }
  function TODAY_COL_TXT() { return '紅色虛線'; }

  // ---- Tooltip ---------------------------------------------------------------
  function bindTips(scope) {
    scope.addEventListener('mousemove', function (e) {
      var el = e.target.closest('[data-tip]');
      if (!el) { hideTip(); return; }
      var tip = document.getElementById('cg-tip');
      tip.style.display = 'block'; tip.textContent = el.getAttribute('data-tip');
      var x = e.clientX + 14, y = e.clientY + 14;
      if (x + tip.offsetWidth > window.innerWidth) x = e.clientX - tip.offsetWidth - 14;
      if (y + tip.offsetHeight > window.innerHeight) y = e.clientY - tip.offsetHeight - 14;
      tip.style.left = x + 'px'; tip.style.top = y + 'px';
    });
    scope.addEventListener('mouseleave', hideTip);
    scope.addEventListener('click', function (e) {
      var el = e.target.closest('.cg-bar');
      if (!el || !el.dataset.bom) return;
      filterHostPage(el.dataset.bom, el.dataset.maker);
    });
  }
  function hideTip() { var t = document.getElementById('cg-tip'); if (t) t.style.display = 'none'; }

  // ---- 匯出 CSV（純資料；圖表請用「轉圖片」或「列印」）--------------------------
  function exportCsv() {
    if (!state.rows.length) { alert('沒有資料可匯出'); return; }
    var head = ['廠商', '廠內', '燈號', '客戶', '料號', '製程', '製程碼', 'BOM', '數量', '移轉日', '回廠判定', '回廠日', '狀態'];
    var lines = [head.join(',')];
    state.rows.forEach(function (r) {
      var cells = [r.maker_name, (String(r.internal) === '1' ? '是' : ''), r.prio_label, r.client, r.d_id, r.proc_name, r.process_no, r.bom, r.sqty,
        r.out_date, (r.ret_date ? r.ret_label : '在廠中' + (r.is_stale ? '(逾60天)' : '')), (r.ret_date || ''), r.state];
      lines.push(cells.map(function (c) { c = String(c == null ? '' : c); return /[",\n]/.test(c) ? '"' + c.replace(/"/g, '""') + '"' : c; }).join(','));
    });
    var blob = new Blob(['﻿' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8;' });
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = '外包產能_' + state.meta.start + '_' + state.meta.end + '.csv';
    document.body.appendChild(a); a.click(); document.body.removeChild(a);
  }

  // ---- 匯出圖片（PNG，自繪 canvas，含圖表）------------------------------------
  function exportImage() {
    var L = computeLayout(document.getElementById('cg-body').clientWidth);
    if (!L) { alert('請先查詢'); return; }
    var pxDay = L.pxDay, totalDays = L.totalDays, trackW = L.trackW, start = L.start;

    // 計算總高
    var topPad = 30;
    var y = topPad + AXIS_H;
    L.groups.forEach(function (g) { y += g.laneCount * LANE_STEP + 4; if (state.showLoad) y += LOAD_H; });
    var W = LABEL_W + trackW, H = y + 46;

    var scale = Math.min(2, (window.devicePixelRatio || 1));
    if (W * scale > 16000) scale = Math.max(1, Math.floor(16000 / W));
    var cv = document.createElement('canvas');
    cv.width = Math.round(W * scale); cv.height = Math.round(H * scale);
    var ctx = cv.getContext('2d'); ctx.scale(scale, scale);
    ctx.textBaseline = 'middle';
    ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, W, H);

    // 標題
    ctx.fillStyle = '#5a4632'; ctx.font = '700 15px sans-serif'; ctx.textAlign = 'left';
    ctx.fillText('外包產能甘特圖　' + start + ' ~ ' + L.end + '（依' + (state.groupBy === 'maker' ? '廠商' : '製程') + '）', 8, topPad / 2 + 6);

    var xOff = LABEL_W;
    // 週末背景 + 月份格線（整張高）
    var gridTop = topPad, gridBot = H - 46;
    for (var d = 0; d < totalDays; d++) {
      var ds = addDays(start, d), wd = weekday(ds), x = xOff + d * pxDay;
      if (state.nonwork.has(ds)) { ctx.fillStyle = 'rgba(140,110,70,.06)'; ctx.fillRect(x, gridTop + AXIS_H, pxDay, gridBot - gridTop - AXIS_H); }
      if (ds.slice(8) === '01' || d === 0) { ctx.strokeStyle = '#ddcdb5'; ctx.beginPath(); ctx.moveTo(x + .5, gridTop); ctx.lineTo(x + .5, gridBot); ctx.stroke(); }
    }
    // 軸標籤
    ctx.fillStyle = '#a08a6f'; ctx.font = '10px sans-serif';
    for (var d2 = 0; d2 < totalDays; d2++) {
      var ds2 = addDays(start, d2), x2 = xOff + d2 * pxDay;
      if (ds2.slice(8) === '01' || d2 === 0) ctx.fillText(ds2.slice(0, 7), x2 + 2, gridTop + AXIS_H / 2);
      else if (pxDay >= 22) ctx.fillText('' + (+ds2.slice(8)), x2 + 2, gridTop + AXIS_H / 2);
    }
    ctx.strokeStyle = '#d8c7b0'; ctx.beginPath(); ctx.moveTo(0, gridTop + AXIS_H + .5); ctx.lineTo(W, gridTop + AXIS_H + .5); ctx.stroke();

    var ty = gridTop + AXIS_H;
    L.groups.forEach(function (g) {
      var rowH = g.laneCount * LANE_STEP + 4;
      // 泳道標籤
      ctx.fillStyle = '#3a2a18'; ctx.font = '600 12px sans-serif';
      ctx.fillText(cut(ctx, g.label, LABEL_W - 12), 8, ty + 12);
      ctx.fillStyle = '#a08a6f'; ctx.font = '11px sans-serif';
      ctx.fillText(g.sub + '｜峰值 ' + g.laneCount + (g.avgWork != null ? '｜回廠均 ' + g.avgWork + '工日' : ''), 8, ty + 26);
      // 長條
      g.segs.forEach(function (x) {
        var r = x.r, bx = xOff + x.cs * pxDay, bw = (x.ce - x.cs + 1) * pxDay - 2; if (bw < 3) bw = 3;
        var by = ty + x.lane * LANE_STEP + 2, pc = PRIO[r.prio] || PRIO.n;
        ctx.globalAlpha = r.is_stale ? 0.42 : 1;
        ctx.fillStyle = pc.bg; roundRect(ctx, bx, by, bw, BAR_H, 3); ctx.fill();
        ctx.lineWidth = 1; ctx.strokeStyle = pc.bd;
        if (r.is_stale) ctx.setLineDash([3, 2]); roundRect(ctx, bx, by, bw, BAR_H, 3); ctx.stroke(); ctx.setLineDash([]);
        ctx.globalAlpha = 1;
        var lab = barLabel(r, bw);
        if (lab) { ctx.fillStyle = pc.tx; ctx.font = '10px sans-serif'; ctx.save(); ctx.beginPath(); ctx.rect(bx + 2, by, bw - 4, BAR_H); ctx.clip(); ctx.fillText(lab, bx + 4, by + BAR_H / 2 + 1); ctx.restore(); }
      });
      ty += rowH;
      // 每日負載
      if (state.showLoad) {
        var mx = Math.max.apply(null, g.cnt) || 1;
        for (var i = 0; i < totalDays; i++) {
          if (!g.cnt[i]) continue;
          var a = 0.18 + 0.72 * (g.cnt[i] / mx);
          ctx.fillStyle = 'rgba(' + LOAD_RGB + ',' + a.toFixed(2) + ')';
          ctx.fillRect(xOff + i * pxDay, ty + 1, pxDay, LOAD_H - 2);
          if (pxDay >= 16) { ctx.fillStyle = '#3a2a18'; ctx.font = '9px sans-serif'; ctx.textAlign = 'center'; ctx.fillText('' + g.cnt[i], xOff + i * pxDay + pxDay / 2, ty + LOAD_H / 2); ctx.textAlign = 'left'; }
        }
        ctx.fillStyle = '#a08a6f'; ctx.font = '11px sans-serif'; ctx.fillText('每日負荷', 8, ty + LOAD_H / 2);
        ty += LOAD_H;
      }
      ctx.strokeStyle = '#efe7db'; ctx.beginPath(); ctx.moveTo(0, ty + .5); ctx.lineTo(W, ty + .5); ctx.stroke();
    });

    // 今日線
    if (L.todayIdx >= 0) {
      var tx = xOff + L.todayIdx * pxDay;
      ctx.strokeStyle = TODAY_COL; ctx.lineWidth = 2; ctx.setLineDash([4, 3]);
      ctx.beginPath(); ctx.moveTo(tx, gridTop); ctx.lineTo(tx, ty); ctx.stroke(); ctx.setLineDash([]);
    }
    // 標籤欄分隔線
    ctx.strokeStyle = '#d8c7b0'; ctx.lineWidth = 2; ctx.beginPath(); ctx.moveTo(LABEL_W, gridTop); ctx.lineTo(LABEL_W, ty); ctx.stroke();

    // 圖例
    var ly = ty + 22; ctx.textAlign = 'left';
    var leg = [['一般件', PRIO.n], ['急件(U)', PRIO.u], ['特急件(E)', PRIO.e]];
    var lx = 10;
    ctx.font = '12px sans-serif';
    leg.forEach(function (it) { ctx.fillStyle = it[1].bg; roundRect(ctx, lx, ly - 7, 14, 14, 3); ctx.fill(); ctx.strokeStyle = it[1].bd; ctx.stroke(); ctx.fillStyle = '#5a4632'; ctx.fillText(it[0], lx + 20, ly); lx += 30 + ctx.measureText(it[0]).width; });
    ctx.fillStyle = 'rgba(' + LOAD_RGB + ',.7)'; ctx.fillRect(lx, ly - 7, 14, 14); ctx.fillStyle = '#5a4632'; ctx.fillText('每日在廠負載', lx + 20, ly);

    var url = cv.toDataURL('image/png');
    var a = document.createElement('a'); a.href = url;
    a.download = '外包產能甘特圖_' + start + '_' + L.end + '.png';
    document.body.appendChild(a); a.click(); document.body.removeChild(a);
  }
  function cut(ctx, s, maxW) { s = String(s || ''); if (ctx.measureText(s).width <= maxW) return s; while (s.length && ctx.measureText(s + '…').width > maxW) s = s.slice(0, -1); return s + '…'; }
  function roundRect(ctx, x, y, w, h, r) { r = Math.min(r, w / 2, h / 2); ctx.beginPath(); ctx.moveTo(x + r, y); ctx.arcTo(x + w, y, x + w, y + h, r); ctx.arcTo(x + w, y + h, x, y + h, r); ctx.arcTo(x, y + h, x, y, r); ctx.arcTo(x, y, x + w, y, r); ctx.closePath(); }

  // ---- 列印 ------------------------------------------------------------------
  function printView() {
    var grid = document.querySelector('#cg-body .cg-grid');
    if (!grid) { alert('請先查詢'); return; }
    var css = '';
    Array.prototype.forEach.call(document.head.querySelectorAll('style'), function (s) { if (s.textContent.indexOf('.cg-bar') >= 0) css = s.textContent; });
    var w = window.open('', '_blank');
    w.document.write('<html><head><meta charset="utf-8"><title>外包產能甘特圖 ' + state.meta.start + '~' + state.meta.end + '</title><style>' +
      css + 'body{margin:10px;font-family:sans-serif;} #cg-tip{display:none!important;}</style></head><body>' +
      '<h3>外包產能甘特圖　' + state.meta.start + ' ~ ' + state.meta.end + '</h3>' +
      '<div id="cg-body">' + grid.outerHTML + '</div>' + document.getElementById('cg-legend').outerHTML +
      '</body></html>');
    w.document.close();
    setTimeout(function () { w.print(); }, 400);
  }

})();
