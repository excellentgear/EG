/* data_console.js — 資料急救台前端邏輯（搭配 DataConsole_API.php） */
(function () {
  const DC = window.DC = {};
  const API = window.DC_API;
  const S = DC.state = { csrf: '', perm: {}, tables: [], curTable: null, schema: null, page: 1, per: 20, sort_col: '', sort_dir: 'ASC', editMode: 'edit', editPk: null, delCtx: null };

  const esc = s => (s === null || s === undefined) ? '' : String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const $ = id => document.getElementById(id);

  DC.api = function (action, params, method) {
    params = params || {};
    if (method === 'POST') {
      const fd = new FormData(); fd.append('action', action);
      for (const k in params) fd.append(k, params[k] == null ? '' : params[k]);
      return fetch(API, { method: 'POST', body: fd }).then(r => r.json());
    }
    const u = new URLSearchParams(Object.assign({ action }, params));
    return fetch(API + '?' + u.toString()).then(r => r.json());
  };

  // ── 初始化 ────────────────────────────────────────────────────────────────
  DC.init = function () {
    DC.api('bootstrap').then(res => {
      if (!res.success) { alert(res.message || '載入失敗'); return; }
      S.csrf = res.csrf; S.perm = res.perm; S.tables = res.tables;
      DC.renderTableList();
    });
  };

  DC.renderTableList = function (filter) {
    filter = (filter || '').toLowerCase();
    const el = $('tableList'); if (!el) return;
    el.innerHTML = S.tables.filter(t => !filter || t.name.toLowerCase().includes(filter)).map(t => {
      let badge = '';
      if (t.hard_readonly) badge = '<span class="dc-pill ro">唯讀</span>';
      else { if (t.can_edit) badge += '<span class="dc-pill edit">可編</span>'; if (t.can_delete) badge += '<span class="dc-pill del">可刪</span>'; }
      return `<div class="it${S.curTable === t.name ? ' active' : ''}" onclick="DC.selectTable('${t.name}')">
        <span>${esc(t.name)}${badge}</span><span class="cnt">${t.rows}</span></div>`;
    }).join('') || '<div style="padding:12px;color:#a98a5c;font-size:13px;">無符合的資料表</div>';
  };
  DC.filterTableList = q => DC.renderTableList(q);

  // ── 選表 → 載入 schema + 資料 ───────────────────────────────────────────────
  DC.selectTable = function (name) {
    S.curTable = name; S.page = 1; S.filters = []; S.sort_col = ''; S.sort_dir = 'ASC';
    DC.renderTableList($('tblFilter') ? $('tblFilter').value : '');
    // 若在別的分頁點擊（來自全域搜尋）先切到瀏覽分頁
    $('tab-browse').style.display = ''; $('tab-search').style.display = 'none';
    const st = $('tab-setting'); if (st) st.style.display = 'none';
    document.querySelectorAll('#tb_search,#tb_browse,#tb_setting').forEach(b => b.classList.remove('active'));
    $('tb_browse').classList.add('active');
    DC.api('schema', { table: name }).then(res => {
      if (!res.success) { alert(res.message); return; }
      S.schema = res; DC.renderBrowsePanel(); DC.loadRows();
    });
  };

  DC.renderBrowsePanel = function () {
    const sc = S.schema;
    const cols = sc.columns.map(c => c.name);
    const tmeta = S.tables.find(t => t.name === S.curTable) || {};
    let head = `<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:10px;">
      <h4 style="margin:0;color:var(--amber-d);">${esc(S.curTable)}
        <span style="font-size:12px;color:#a98a5c;font-weight:400;">主鍵：${esc(sc.pk.join(', ') || '（無）')}</span></h4>
      <div>`;
    if (sc.hard_readonly) head += '<span class="dc-pill ro">永久唯讀（紀錄/稽核表）</span>';
    else {
      if ((S.perm.edit) && sc.can_edit) head += `<button class="btn btn-amber btn-sm" onclick="DC.openEdit('insert')"><i class="fa fa-plus"></i> 新增一列</button>`;
      else head += '<span style="font-size:12px;color:#b06f27;">此表未開放編輯' + (S.perm.admin ? '（可到⚙️設定開啟）' : '') + '</span>';
    }
    head += '</div></div>';

    // 篩選條件建構器
    const opts = cols.map(c => `<option value="${esc(c)}">${esc(c)}</option>`).join('');
    let flt = `<div class="dc-card" style="background:#fff;padding:10px 12px;">
      <div style="font-size:12px;color:#6b5638;font-weight:600;margin-bottom:6px;">篩選條件（點選式查詢，可加多條，全部成立才符合）</div>
      <div id="filterRows"></div>
      <button class="btn btn-sand btn-sm" onclick="DC.addFilterRow()"><i class="fa fa-plus"></i> 加條件</button>
      <button class="btn btn-amber btn-sm" onclick="DC.applyFilters()"><i class="fa fa-search"></i> 查詢</button>
      <button class="btn btn-sand btn-sm" onclick="DC.clearFilters()">清除</button>
    </div>`;

    $('browsePanel').innerHTML = head + flt +
      `<div style="display:flex;justify-content:space-between;align-items:center;margin:6px 0;flex-wrap:wrap;gap:8px;">
        <div style="font-size:13px;color:#6b5638;">共 <b id="rowTotal">-</b> 筆</div>
        <div style="display:flex;gap:8px;align-items:center;">
          每頁 <select id="perSel" class="dc-input" onchange="DC.changePer(this.value)">
            <option>5</option><option>10</option><option selected>20</option><option>50</option></select>
          <span class="pager" id="pagerTop"></span>
        </div></div>
      <div class="dc-scroll"><table class="dc-tbl" id="rowsTbl"><thead></thead><tbody></tbody></table></div>
      <div class="pager" id="pagerBottom" style="margin-top:10px;"></div>`;
    DC._filterRows = [];
    DC.addFilterRow();
  };

  DC.addFilterRow = function () {
    const cols = S.schema.columns.map(c => c.name);
    const idx = DC._filterRows.length;
    const optCols = cols.map(c => `<option value="${esc(c)}">${esc(c)}</option>`).join('');
    const ops = { '=': '等於', 'contains': '包含', '!=': '不等於', '>': '大於', '<': '小於', '>=': '≥', '<=': '≤', 'empty': '空白', 'notempty': '非空白' };
    const optOps = Object.keys(ops).map(o => `<option value="${o}">${ops[o]}</option>`).join('');
    const div = document.createElement('div');
    div.className = 'filter-row'; div.dataset.idx = idx;
    div.innerHTML = `<select class="dc-input f-col" style="width:180px;">${optCols}</select>
      <select class="dc-input f-op" style="width:100px;" onchange="DC.toggleFilterVal(this)">${optOps}</select>
      <input class="dc-input f-val" style="width:180px;" placeholder="值">
      <button class="btn btn-sand btn-sm" onclick="this.parentNode.remove()">✕</button>`;
    $('filterRows').appendChild(div);
    DC._filterRows.push(div);
  };
  DC.toggleFilterVal = function (sel) {
    const val = sel.parentNode.querySelector('.f-val');
    const hide = (sel.value === 'empty' || sel.value === 'notempty');
    val.style.display = hide ? 'none' : '';
  };
  DC.applyFilters = function () {
    const rows = $('filterRows').querySelectorAll('.filter-row');
    S.filters = [];
    rows.forEach(r => {
      const col = r.querySelector('.f-col').value, op = r.querySelector('.f-op').value, val = r.querySelector('.f-val').value;
      if (op === 'empty' || op === 'notempty') S.filters.push({ col, op, val: '' });
      else if (val !== '') S.filters.push({ col, op, val });
    });
    S.page = 1; DC.loadRows();
  };
  DC.clearFilters = function () { $('filterRows').innerHTML = ''; DC._filterRows = []; DC.addFilterRow(); S.filters = []; S.page = 1; DC.loadRows(); };
  DC.changePer = function (v) { S.per = parseInt(v, 10); S.page = 1; DC.loadRows(); };

  DC.loadRows = function () {
    $('rowsTbl').querySelector('tbody').innerHTML = `<tr><td colspan="99" style="text-align:center;padding:20px;"><span class="spin"></span> 載入中…</td></tr>`;
    DC.api('rows', { table: S.curTable, page: S.page, per: S.per, filters: JSON.stringify(S.filters || []), sort_col: S.sort_col, sort_dir: S.sort_dir })
      .then(res => { if (!res.success) { alert(res.message); return; } DC.renderRows(res); });
  };

  DC.renderRows = function (res) {
    const sc = S.schema, cols = sc.columns;
    const refset = {}; cols.forEach(c => { if (c.ref) refset[c.name] = c.ref; });
    const canRowAct = !sc.hard_readonly;
    // 表頭
    let th = '<tr>';
    if (canRowAct) th += '<th style="width:96px;">操作</th>';
    cols.forEach(c => {
      const arrow = S.sort_col === c.name ? (S.sort_dir === 'ASC' ? ' ▲' : ' ▼') : '';
      const roTag = c.readonly ? '<span class="dc-pill ro" style="font-size:9px;">鎖</span>' : '';
      const refTag = c.ref ? `<span class="dc-ref">→${esc(c.ref.table)}</span>` : '';
      th += `<th onclick="DC.sortBy('${esc(c.name)}')">${esc(c.name)}${roTag}${refTag}${arrow}</th>`;
    });
    th += '</tr>';
    $('rowsTbl').querySelector('thead').innerHTML = th;
    // 內容
    const disp = res.ref_display || {};
    let body = '';
    if (!res.rows.length) body = `<tr><td colspan="99" style="text-align:center;padding:24px;color:#a98a5c;">無資料</td></tr>`;
    res.rows.forEach((row, i) => {
      body += '<tr>';
      if (canRowAct) {
        const pk = {}; sc.pk.forEach(k => pk[k] = row[k]);
        const pkJson = esc(JSON.stringify(pk));
        let ops = '';
        if (S.perm.edit && sc.can_edit) ops += `<button class="btn btn-sand btn-sm" title="編輯" onclick='DC.openEdit("edit",${pkJson})'>✏️</button> `;
        if (S.perm.delete && sc.can_delete) ops += `<button class="btn btn-coral btn-sm" title="刪除" onclick='DC.openDel(${pkJson})'>🗑</button>`;
        if (!ops) ops = '<span style="color:#c4b596;font-size:11px;">—</span>';
        body += `<td>${ops}</td>`;
      }
      cols.forEach(c => {
        let v = row[c.name];
        let cell = esc(v);
        if (c.ref && v != null && v !== '' && disp[c.name] && disp[c.name][String(v)] != null) {
          cell = `<span title="原始值：${esc(v)}">${esc(disp[c.name][String(v)])} <span style="color:#b39a70;font-size:11px;">#${esc(v)}</span></span>`;
        } else if (v === null) cell = '<span style="color:#c4b596;">NULL</span>';
        body += `<td title="${esc(v)}">${cell}</td>`;
      });
      body += '</tr>';
    });
    $('rowsTbl').querySelector('tbody').innerHTML = body;
    $('rowTotal').textContent = res.total;
    DC.renderPager(res.total);
    // 儲存本頁 rows 供編輯取現值
    S._rows = res.rows;
  };

  DC.sortBy = function (col) {
    if (S.sort_col === col) S.sort_dir = S.sort_dir === 'ASC' ? 'DESC' : 'ASC';
    else { S.sort_col = col; S.sort_dir = 'ASC'; }
    DC.loadRows();
  };

  DC.renderPager = function (total) {
    const pages = Math.max(1, Math.ceil(total / S.per));
    let h = '';
    h += `<button ${S.page <= 1 ? 'disabled' : ''} onclick="DC.goPage(${S.page - 1})">‹</button>`;
    const win = 2;
    for (let p = 1; p <= pages; p++) {
      if (p === 1 || p === pages || (p >= S.page - win && p <= S.page + win)) {
        h += `<button class="${p === S.page ? 'active' : ''}" onclick="DC.goPage(${p})">${p}</button>`;
      } else if (p === S.page - win - 1 || p === S.page + win + 1) h += `<span style="padding:0 3px;">…</span>`;
    }
    h += `<button ${S.page >= pages ? 'disabled' : ''} onclick="DC.goPage(${S.page + 1})">›</button>`;
    $('pagerTop').innerHTML = h; $('pagerBottom').innerHTML = h;
  };
  DC.goPage = function (p) { S.page = p; DC.loadRows(); };

  // ── 全域搜尋 ────────────────────────────────────────────────────────────────
  DC.globalSearch = function () {
    const q = $('gsInput').value.trim();
    if (!q) { alert('請輸入搜尋關鍵字'); return; }
    $('gsResult').innerHTML = `<div style="padding:20px;text-align:center;color:#a98a5c;"><span class="spin"></span> 掃描所有資料表中…（可能需數秒）</div>`;
    DC.api('search', { q }).then(res => {
      if (!res.success) { $('gsResult').innerHTML = `<div class="warn-box">${esc(res.message)}</div>`; return; }
      if (!res.hits.length) { $('gsResult').innerHTML = `<div class="info-box">在所有資料表中都找不到「${esc(q)}」。</div>`; return; }
      let h = `<div style="margin-bottom:10px;font-size:14px;color:#6b5638;">關鍵字「<b>${esc(q)}</b>」命中 <b>${res.table_count}</b> 張資料表：</div>`;
      res.hits.forEach(hit => {
        h += `<div class="hit-card"><div class="hh"><span><b>${esc(hit.table)}</b> 命中 ${hit.count} 筆</span>
          <button class="btn btn-amber btn-sm" onclick="DC.selectTable('${hit.table}')">前往此表編輯 →</button></div>
          <div class="dc-scroll" style="border:none;"><table class="dc-tbl"><thead><tr>`;
        const keys = hit.sample.length ? Object.keys(hit.sample[0]) : [];
        keys.forEach(k => h += `<th>${esc(k)}</th>`);
        h += '</tr></thead><tbody>';
        hit.sample.forEach(r => { h += '<tr>'; keys.forEach(k => h += `<td title="${esc(r[k])}">${r[k] === null ? '<span style="color:#c4b596;">NULL</span>' : esc(r[k])}</td>`); h += '</tr>'; });
        h += '</tbody></table></div>';
        if (hit.count > hit.sample.length) h += `<div style="padding:6px 12px;font-size:12px;color:#a98a5c;">僅顯示前 ${hit.sample.length} 筆，前往此表可看全部。</div>`;
        h += '</div>';
      });
      $('gsResult').innerHTML = h;
    });
  };

  // ── 編輯 / 新增 Modal ───────────────────────────────────────────────────────
  DC.openEdit = function (mode, pk) {
    S.editMode = mode; S.editPk = pk || null;
    const sc = S.schema;
    let row = {};
    if (mode === 'edit') { row = (S._rows || []).find(r => sc.pk.every(k => String(r[k]) === String(pk[k]))) || {}; }
    $('editModalTitle').textContent = (mode === 'edit' ? '修改資料：' : '新增一列：') + S.curTable;
    let body = '';
    if (mode === 'edit') {
      body += `<div class="info-box" style="margin-bottom:10px;">主鍵：${sc.pk.map(k => esc(k) + '=' + esc(pk[k])).join('、')}</div>`;
    }
    const tmeta = S.tables.find(t => t.name === S.curTable) || {};
    if (['order_track', 'quotation', 'bom', 'live_event'].includes(S.curTable)) {
      body += `<div class="warn-box" style="margin-bottom:10px;">此表有程式連動（通知／旗標／子單等）。直接修改<strong>不會</strong>觸發那些連動，改完請自行確認相關頁面狀態是否一致。</div>`;
    }
    body += '<div id="editFields">';
    sc.columns.forEach(c => {
      const editable = DC.fieldEditable(c, mode);
      let val = (mode === 'edit') ? (row[c.name] != null ? row[c.name] : '') : (c.default != null ? c.default : '');
      body += DC.renderField(c, val, editable, mode, row);
    });
    body += '</div>';
    body += `<div class="dc-field" style="margin-top:12px;"><label>${mode === 'edit' ? '修改' : '新增'}原因（必填，會記入稽核）</label>
      <input id="editReason" placeholder="例如：客戶已回覆確認驗收，補上 QC 已檢驗旗標"></div>`;
    $('editModalBody').innerHTML = body;
    // 綁定 combobox
    $('editModalBody').querySelectorAll('.dc-combo input.combo-in').forEach(DC.wireCombo);
    $('editModalBg').style.display = 'flex';
  };

  DC.fieldEditable = function (c, mode) {
    const isAuto = /auto_increment/i.test(c.extra || '');
    if (isAuto) return false;
    if (mode === 'insert') return !(c.readonly && c.key !== 'PRI'); // 稽核欄跳過；非自增主鍵可填
    return !c.readonly;
  };

  DC.renderField = function (c, val, editable, mode, row) {
    const dis = editable ? '' : 'disabled';
    const roTag = editable ? '' : '<span class="ro-tag">🔒 唯讀</span>';
    let inner;
    if (c.ref && editable) {
      // 參照欄：combobox
      const disp = (S.schema && S._rows) ? '' : '';
      let label = '';
      if (mode === 'edit' && row && row[c.name] != null && row[c.name] !== '') label = '#' + row[c.name];
      inner = `<div class="dc-combo">
        <input type="hidden" class="combo-val" data-col="${esc(c.name)}" value="${esc(val)}">
        <input class="combo-in" data-table="${esc(S.curTable)}" data-col="${esc(c.name)}" placeholder="輸入關鍵字或 id 搜尋…" value="${esc(label)}" autocomplete="off">
        <div class="list"></div>
        <div class="cur" style="font-size:11px;color:#a98a5c;margin-top:2px;">目前值：<b>${esc(val) || '（空）'}</b> → <span class="lbl">未變更</span></div>
      </div>`;
    } else if (/^(date)$/i.test(c.type) && editable) {
      inner = `<input type="date" data-col="${esc(c.name)}" value="${esc(String(val).substr(0,10))}">`;
    } else if (/^(datetime|timestamp)$/i.test(c.type) && editable) {
      const dv = val ? String(val).replace(' ', 'T').substr(0, 16) : '';
      inner = `<input type="datetime-local" data-col="${esc(c.name)}" value="${esc(dv)}">`;
    } else if (/text/i.test(c.type)) {
      inner = `<textarea data-col="${esc(c.name)}" rows="2" ${dis}>${esc(val)}</textarea>`;
    } else {
      inner = `<input data-col="${esc(c.name)}" value="${esc(val)}" ${dis}>`;
    }
    return `<div class="dc-field"><label>${esc(c.name)} <span style="font-weight:400;color:#a98a5c;">${esc(c.coltype)}</span>${roTag}${c.comment ? ' <span style="font-weight:400;color:#b39a70;">— ' + esc(c.comment) + '</span>' : ''}</label>${inner}</div>`;
  };

  DC.wireCombo = function (input) {
    const box = input.closest('.dc-combo');
    const list = box.querySelector('.list');
    const hidden = box.querySelector('.combo-val');
    const lbl = box.querySelector('.lbl');
    let timer = null;
    const doSearch = () => {
      const q = input.value.trim();
      DC.api('ref_options', { table: input.dataset.table, column: input.dataset.col, q }).then(res => {
        if (!res.success) return;
        if (!res.options.length) { list.innerHTML = '<div class="o" style="color:#a98a5c;">無相符</div>'; list.style.display = 'block'; return; }
        list.innerHTML = res.options.map(o => `<div class="o" data-id="${esc(o.id)}">${esc(o.label)} <span style="color:#b39a70;">#${esc(o.id)}</span></div>`).join('');
        list.style.display = 'block';
        list.querySelectorAll('.o[data-id]').forEach(o => o.onclick = () => {
          hidden.value = o.dataset.id; input.value = o.textContent.replace(/#\S+$/, '').trim();
          if (lbl) lbl.textContent = '已改為 ' + o.textContent.trim(); list.style.display = 'none';
        });
      });
    };
    input.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(doSearch, 250); });
    input.addEventListener('focus', doSearch);
    input.addEventListener('blur', () => setTimeout(() => list.style.display = 'none', 200));
  };

  DC.collectFields = function () {
    const out = {};
    $('editModalBody').querySelectorAll('#editFields [data-col]').forEach(el => {
      if (el.disabled) return;
      if (el.classList.contains('combo-in')) return; // 用 hidden combo-val
      out[el.dataset.col] = el.value;
    });
    // combobox 隱藏值
    $('editModalBody').querySelectorAll('#editFields .combo-val').forEach(el => { out[el.dataset.col] = el.value; });
    return out;
  };

  DC.saveEdit = function () {
    const reason = $('editReason').value.trim();
    if (!reason) { alert('請填寫原因'); $('editReason').focus(); return; }
    const fields = DC.collectFields();
    const btn = $('editSaveBtn'); btn.disabled = true; btn.textContent = '儲存中…';
    const done = res => { btn.disabled = false; btn.textContent = '儲存'; if (!res.success) { alert(res.message); return; } DC.closeEdit(); DC.loadRows(); };
    if (S.editMode === 'edit') {
      DC.api('update', { table: S.curTable, pk: JSON.stringify(S.editPk), fields: JSON.stringify(fields), reason, csrf: S.csrf }, 'POST').then(done);
    } else {
      DC.api('insert', { table: S.curTable, fields: JSON.stringify(fields), reason, csrf: S.csrf }, 'POST').then(done);
    }
  };
  DC.closeEdit = () => $('editModalBg').style.display = 'none';

  // ── 刪除影響分析 ────────────────────────────────────────────────────────────
  DC.openDel = function (pk) {
    S.delCtx = { pk };
    $('delModalBody').innerHTML = `<div style="text-align:center;padding:20px;"><span class="spin"></span> 分析影響範圍中…</div>`;
    $('delConfirmBtn').disabled = true;
    $('delModalBg').style.display = 'flex';
    DC.api('delete_impact', { table: S.curTable, pk: JSON.stringify(pk) }).then(res => {
      if (!res.success) { $('delModalBody').innerHTML = `<div class="warn-box">${esc(res.message)}</div>`; return; }
      let h = `<div style="margin-bottom:10px;font-size:13px;">你即將從 <b>${esc(S.curTable)}</b> 刪除這一筆：</div>`;
      h += `<div class="dc-scroll" style="margin-bottom:12px;"><table class="dc-tbl"><tbody>` +
        Object.keys(res.row).map(k => `<tr><th style="cursor:default;">${esc(k)}</th><td>${res.row[k] === null ? 'NULL' : esc(res.row[k])}</td></tr>`).join('') +
        `</tbody></table></div>`;
      if (res.composite) {
        h += `<div class="info-box">此表為複合主鍵，未自動掃描外部參照，請自行確認關聯資料。</div>`;
      } else if (res.referenced_by.length) {
        h += `<div class="warn-box"><b>⚠️ 這筆資料被其他 ${res.referenced_by.length} 張表、共 ${res.total_refs} 筆資料引用：</b><ul style="margin:6px 0 0 18px;">` +
          res.referenced_by.map(r => `<li><b>${esc(r.table)}</b> 的 <code>${esc(r.column)}</code> 欄有 <b>${r.count}</b> 筆指向它</li>`).join('') +
          `</ul><div style="margin-top:6px;">直接刪除會讓這些資料變成<strong>孤兒</strong>（指向一個不存在的對象），可能造成相關頁面顯示錯誤或串聯中斷。建議先處理這些引用，或改用「作廢」而非刪除。</div></div>`;
      } else {
        h += `<div class="info-box">✓ 未偵測到其他表引用這筆資料（依命名慣例掃描）。仍請留意程式層面的關聯。</div>`;
      }
      if (!res.can_delete) {
        h += `<div class="warn-box" style="margin-top:10px;">此表尚未開放刪除。</div>`;
        $('delModalBody').innerHTML = h; return;
      }
      h += `<div class="dc-field" style="margin-top:12px;"><label>刪除原因（必填）</label><input id="delReason" placeholder="為何要刪除這筆資料"></div>
        <div class="dc-field"><label>請輸入 <b>DELETE</b> 以確認</label><input id="delConfirm" placeholder="DELETE" oninput="DC.checkDelConfirm()"></div>`;
      $('delModalBody').innerHTML = h;
    });
  };
  DC.checkDelConfirm = function () { $('delConfirmBtn').disabled = ($('delConfirm').value.trim() !== 'DELETE' || !$('delReason').value.trim()); };
  DC.doDelete = function () {
    const reason = $('delReason').value.trim(), confirm = $('delConfirm').value.trim();
    const btn = $('delConfirmBtn'); btn.disabled = true; btn.textContent = '刪除中…';
    DC.api('delete', { table: S.curTable, pk: JSON.stringify(S.delCtx.pk), reason, confirm, csrf: S.csrf }, 'POST').then(res => {
      btn.textContent = '確認刪除';
      if (!res.success) { alert(res.message); btn.disabled = false; return; }
      DC.closeDel(); DC.loadRows();
    });
  };
  DC.closeDel = () => $('delModalBg').style.display = 'none';

  // ── 設定 ────────────────────────────────────────────────────────────────────
  DC.loadSettings = function () { DC.renderCfg(); };
  DC.settingTab = function (k, btn) {
    document.querySelectorAll('.dc-setting-tab').forEach(e => e.style.display = 'none');
    $('set-' + k).style.display = '';
    document.querySelectorAll('#stb_access,#stb_ref,#stb_role').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    if (k === 'ref') DC.refmapList();
  };
  DC.renderCfg = function (filter) {
    filter = (filter || '').toLowerCase();
    let h = `<thead><tr><th>資料表</th><th>估計筆數</th><th>可編輯</th><th>可刪除</th><th>備註</th></tr></thead><tbody>`;
    S.tables.filter(t => !filter || t.name.toLowerCase().includes(filter)).forEach(t => {
      if (t.hard_readonly) {
        h += `<tr><td><b>${esc(t.name)}</b> <span class="dc-pill ro">永久唯讀</span></td><td>${t.rows}</td><td colspan="3" style="color:#a98a5c;">紀錄/稽核表，不可開放</td></tr>`;
        return;
      }
      h += `<tr><td><b>${esc(t.name)}</b></td><td>${t.rows}</td>
        <td><input type="checkbox" ${t.can_edit ? 'checked' : ''} onchange="DC.saveCfg('${t.name}',this.checked?1:0,undefined)"></td>
        <td><input type="checkbox" ${t.can_delete ? 'checked' : ''} onchange="DC.saveCfg('${t.name}',undefined,this.checked?1:0)"></td>
        <td><input class="dc-input cfg-note" data-t="${esc(t.name)}" style="width:100%;" value="${esc(t.note || '')}" onblur="DC.saveCfg('${t.name}',undefined,undefined,this.value)"></td></tr>`;
    });
    h += '</tbody>';
    $('cfgTbl').innerHTML = h;
  };
  DC.filterCfg = q => DC.renderCfg(q);
  DC.saveCfg = function (table, ce, cd, note) {
    const t = S.tables.find(x => x.name === table);
    const params = { table, csrf: S.csrf, can_edit: ce !== undefined ? ce : t.can_edit, can_delete: cd !== undefined ? cd : t.can_delete, note: note !== undefined ? note : (t.note || '') };
    DC.api('save_table_cfg', params, 'POST').then(res => {
      if (!res.success) { alert(res.message); DC.renderCfg($('cfgFilter').value); return; }
      t.can_edit = parseInt(params.can_edit, 10); t.can_delete = parseInt(params.can_delete, 10); t.note = params.note;
      DC.renderTableList($('tblFilter') ? $('tblFilter').value : '');
    });
  };

  DC.refmapList = function () {
    DC.api('refmap_list').then(res => {
      if (!res.success) return;
      let h = `<thead><tr><th>來源表</th><th>來源欄位</th><th>參照表</th><th>參照主鍵</th><th>顯示欄</th><th>操作</th></tr></thead><tbody>`;
      if (!res.rows.length) h += `<tr><td colspan="6" style="text-align:center;color:#a98a5c;padding:14px;">尚無自訂覆寫（全靠自動判斷）</td></tr>`;
      res.rows.forEach(r => {
        h += `<tr><td>${esc(r.src_table || '（任意）')}</td><td>${esc(r.src_column)}</td><td>${esc(r.ref_table)}</td><td>${esc(r.ref_pk || '自動')}</td><td>${esc(r.display_cols || '自動')}</td>
          <td><button class="btn btn-coral btn-sm" onclick="DC.refmapDel(${r.id})">刪</button></td></tr>`;
      });
      h += '</tbody>';
      $('refmapTbl').innerHTML = h;
    });
  };
  DC.refmapSave = function () {
    const p = { src_table: $('rmSrcT').value.trim(), src_column: $('rmSrcC').value.trim(), ref_table: $('rmRefT').value.trim(), ref_pk: $('rmRefPk').value.trim(), display_cols: $('rmDisp').value.trim(), csrf: S.csrf };
    if (!p.src_column || !p.ref_table) { alert('來源欄位與參照表為必填'); return; }
    DC.api('refmap_save', p, 'POST').then(res => { if (!res.success) { alert(res.message); return; } ['rmSrcT', 'rmSrcC', 'rmRefT', 'rmRefPk', 'rmDisp'].forEach(id => $(id).value = ''); DC.refmapList(); });
  };
  DC.refmapDel = function (id) { if (!confirm('確定刪除此關聯覆寫？')) return; DC.api('refmap_del', { id, csrf: S.csrf }, 'POST').then(() => DC.refmapList()); };

  document.addEventListener('DOMContentLoaded', DC.init);
})();
