/* AS 線上表單 — 共用格狀渲染器
 * schema JSON → 單一 <table>（colspan/rowspan，交瀏覽器原生分頁）
 * 設計器預覽 / 填寫頁 / 列印 三處共用。純函式、無 jQuery 依賴。
 *
 * schema 契約：
 *   meta   : { title, doc_no, paper, orientation, header:{show}, footer:{show} }
 *   grid   : { cols }
 *   cells  : [ { r,c,cs,rs, type, text, key, ftype, options, required, align, rows } ]
 *            type : title | label | field | static | signature | blank
 *            ftype: text | textarea | number | date | select | checkbox
 *   sections: [ { key,label,step,rule } ]  簽核區
 *   crosscheck: []                          勾稽（第二期）
 */
(function (global) {
  'use strict';

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, function (m) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[m];
    });
  }

  function cellClass(cell) {
    switch (cell.type) {
      case 'title': return 'cell-title';
      case 'label': return 'cell-label' + (cell.align === 'left' ? ' align-left' : '');
      case 'signature': return 'cell-sig';
      default: return 'cell-field';
    }
  }

  // ══ 公式引擎（同 Excel 概念：引用欄位代號；SUM/AVG/MIN/MAX/COUNT＋四則運算）══
  // 安全求值：先展開函式與欄位值，再驗證只剩數字運算子才計算——不對使用者輸入裸 eval
  function evalFormula(expr, getVal) {
    try {
      expr = String(expr || '').replace(/^\s*=/, '');
      expr = expr.replace(/(SUM|AVG|MIN|MAX|COUNT)\s*\(([^()]*)\)/gi, function (_, fn, args) {
        var vs = args.split(',').map(function (s) { return parseFloat(getVal(s.trim())); })
                     .filter(function (v) { return !isNaN(v); });
        fn = fn.toUpperCase();
        if (fn === 'COUNT') return String(vs.length);
        if (!vs.length) return '0';
        if (fn === 'SUM') return String(vs.reduce(function (a, b) { return a + b; }, 0));
        if (fn === 'AVG') return String(vs.reduce(function (a, b) { return a + b; }, 0) / vs.length);
        if (fn === 'MIN') return String(Math.min.apply(null, vs));
        return String(Math.max.apply(null, vs));
      });
      expr = expr.replace(/[A-Za-z_][A-Za-z0-9_]*/g, function (k) {
        var v = parseFloat(getVal(k)); return isNaN(v) ? '0' : String(v);
      });
      if (expr.trim() === '' || !/^[-+*/(). 0-9eE]*$/.test(expr)) return '';
      var v = Function('"use strict";return (' + expr + ')')();
      if (typeof v !== 'number' || !isFinite(v)) return '';
      return String(Math.round(v * 1e6) / 1e6);   // 去浮點雜訊；尾0自動省略
    } catch (e) { return ''; }
  }

  // ══ SVG 圖表（雷達/長條/折線；暖色系固定調色盤，禁外部圖表庫）══
  var CH = { fill: 'rgba(240,162,75,.45)', stroke: '#DD8A3A', grid: '#E0CBA0', text: '#5A3D1E', bar: '#F0A24B', barLine: '#C9782A' };

  function radarSVG(labels, vals, max) {
    var cx = 110, cy = 85, R = 56, n = vals.length;
    function pt(i, r) { var a = -Math.PI / 2 + i * 2 * Math.PI / n; return [(cx + r * Math.cos(a)).toFixed(1), (cy + r * Math.sin(a)).toFixed(1)]; }
    var s = '<svg viewBox="0 0 220 170" style="width:100%;max-width:320px;display:block;margin:0 auto;">';
    [0.33, 0.66, 1].forEach(function (f) {
      var ps = []; for (var i = 0; i < n; i++) ps.push(pt(i, R * f).join(','));
      s += '<polygon points="' + ps.join(' ') + '" fill="none" stroke="' + CH.grid + '" stroke-width="1"/>';
    });
    for (var i = 0; i < n; i++) { var p = pt(i, R); s += '<line x1="' + cx + '" y1="' + cy + '" x2="' + p[0] + '" y2="' + p[1] + '" stroke="' + CH.grid + '" stroke-width="1"/>'; }
    var dp = []; for (i = 0; i < n; i++) { var f2 = Math.max(0, Math.min(1, vals[i] / max)); dp.push(pt(i, R * f2).join(',')); }
    s += '<polygon points="' + dp.join(' ') + '" fill="' + CH.fill + '" stroke="' + CH.stroke + '" stroke-width="2"/>';
    for (i = 0; i < n; i++) { var lp = pt(i, R + 13); s += '<text x="' + lp[0] + '" y="' + lp[1] + '" text-anchor="middle" font-size="9" fill="' + CH.text + '">' + esc(labels[i]) + '（' + vals[i] + '）</text>'; }
    return s + '</svg>';
  }

  function barSVG(labels, vals, max) {
    var n = vals.length, H = n * 20 + 8;
    var s = '<svg viewBox="0 0 220 ' + H + '" style="width:100%;max-width:320px;display:block;margin:0 auto;">';
    for (var i = 0; i < n; i++) {
      var y = 4 + i * 20;
      var w = Math.max(1, 118 * Math.max(0, Math.min(1, vals[i] / max)));
      s += '<text x="58" y="' + (y + 10) + '" text-anchor="end" font-size="9" fill="' + CH.text + '">' + esc(labels[i]) + '</text>'
         + '<rect x="64" y="' + y + '" width="' + w + '" height="13" fill="' + CH.bar + '" stroke="' + CH.barLine + '" stroke-width="1"/>'
         + '<text x="' + (68 + w) + '" y="' + (y + 10) + '" font-size="9" fill="' + CH.text + '">' + vals[i] + '</text>';
    }
    return s + '</svg>';
  }

  function lineSVG(labels, vals, max) {
    var n = vals.length, W = 220, H = 120, padL = 18, padR = 10, padT = 8, padB = 18;
    var iw = W - padL - padR, ih = H - padT - padB;
    function px(i) { return (padL + (n === 1 ? iw / 2 : i * iw / (n - 1))).toFixed(1); }
    function py(v) { return (padT + ih * (1 - Math.max(0, Math.min(1, v / max)))).toFixed(1); }
    var s = '<svg viewBox="0 0 ' + W + ' ' + H + '" style="width:100%;max-width:320px;display:block;margin:0 auto;">';
    [0, 0.5, 1].forEach(function (f) {
      var y = (padT + ih * f).toFixed(1);
      s += '<line x1="' + padL + '" y1="' + y + '" x2="' + (W - padR) + '" y2="' + y + '" stroke="' + CH.grid + '" stroke-width="1"/>';
    });
    var pts = []; for (var i = 0; i < n; i++) pts.push(px(i) + ',' + py(vals[i]));
    s += '<polyline points="' + pts.join(' ') + '" fill="none" stroke="' + CH.stroke + '" stroke-width="2"/>';
    for (i = 0; i < n; i++) {
      s += '<circle cx="' + px(i) + '" cy="' + py(vals[i]) + '" r="2.5" fill="' + CH.stroke + '"/>'
         + '<text x="' + px(i) + '" y="' + (H - 5) + '" text-anchor="middle" font-size="8" fill="' + CH.text + '">' + esc(labels[i]) + '</text>';
    }
    return s + '</svg>';
  }

  function chartSVG(kind, labels, vals, max) {
    if (!vals.length) return '<span style="color:#b7a488;font-size:11px;">（圖表：未設定數據來源欄位）</span>';
    if (!max || max <= 0) max = Math.max.apply(null, vals.concat([1]));
    if (kind === 'radar' && vals.length >= 3) return radarSVG(labels, vals, max);
    if (kind === 'line') return lineSVG(labels, vals, max);
    return barSVG(labels, vals, max);   // 長條為預設；雷達不足3軸退回長條
  }

  // 重算所有計算欄與圖表（欄位值變動時呼叫；順序＝先公式後圖表，圖表可吃公式結果）
  function updateComputed($host) {
    function getVal(k) {
      var el = $host.find('[data-key="' + k + '"]').first();
      return el.length ? el.val() : '';
    }
    $host.find('input[data-formula]').each(function () {
      this.value = evalFormula(this.getAttribute('data-formula'), getVal);
    });
    $host.find('.eg-chart').each(function () {
      var fields = (this.getAttribute('data-fields') || '').split(',').map(function (s) { return s.trim(); }).filter(Boolean);
      var labels = (this.getAttribute('data-labels') || '').split(',').map(function (s) { return s.trim(); }).filter(Boolean);
      var vals = fields.map(function (k) { var v = parseFloat(getVal(k)); return isNaN(v) ? 0 : v; });
      var labs = fields.map(function (k, i) { return labels[i] || k; });
      this.innerHTML = chartSVG(this.getAttribute('data-kind') || 'radar', labs, vals, parseFloat(this.getAttribute('data-max')) || 0);
    });
  }

  function localToday() {
    var d = new Date();   // 用本地時區組今日（勿用 toISOString，UTC 會差 8 小時跨日）
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  }

  function cellInner(cell, mode, data, ctx) {
    ctx = ctx || {};
    var type = cell.type;
    if (type === 'title') return esc(cell.text);
    if (type === 'label') return esc(cell.text) + (cell.required ? '<span class="req-star">*</span>' : '');
    if (type === 'static') return esc(cell.text);
    if (type === 'signature') {
      var v = data['__sig_' + (cell.section || cell.key)];
      if (v) return '<div>' + esc(v.name) + '</div><div class="sig-hint">' + esc(v.at || '') + '</div>';
      return '<span class="sig-hint">（待簽核）</span>';
    }
    if (type === 'chart') {
      // 圖表格：數據來源＝表內欄位代號，updateComputed 時繪製/重繪
      var ch = cell.chart || {};
      return '<div class="eg-chart" data-kind="' + esc(ch.kind || 'radar') + '" data-fields="' + esc((ch.fields || []).join(',')) + '" data-labels="' + esc((ch.labels || []).join(',')) + '" data-max="' + esc(ch.max || '') + '"></div>';
    }
    // field
    var key = cell.key, val = data[key] != null ? data[key] : '';
    var req = cell.required ? ' data-req="1"' : '';
    var ro = (mode === 'view') ? ' readonly disabled' : '';
    var star = cell.required ? '<span class="req-star">*</span>' : '';
    var u = ctx.user || {};
    var upos = u.positions || [];
    switch (cell.ftype) {
      // ── 固定部門（綁部門ID，顯示時即時解析名稱→改名自動連動）──
      case 'fixed_dept': {
        var dname = (ctx.deptMap && ctx.deptMap[String(cell.dept_id)]) || cell.dept || val || '';
        return '<input type="text" data-key="' + esc(key) + '" value="' + esc(dname) + '" readonly' + req + '>';
      }
      // ── 自動帶入使用者身分（姓名固定；部門/職稱兼職者下拉可改，預設主要身分）──
      case 'user_name':
        return '<input type="text" data-key="' + esc(key) + '" value="' + esc(val || u.name || '') + '" readonly' + req + '>';
      case 'user_dept': case 'user_position': {
        var f = (cell.ftype === 'user_dept') ? 'dept' : 'position';
        var names = [];
        upos.forEach(function (p) { if (names.indexOf(p[f]) < 0) names.push(p[f]); });   // 去重、主要在前
        var cur = val || names[0] || '';
        if (mode === 'view' || !names.length) {
          return '<input type="text" data-key="' + esc(key) + '" value="' + esc(cur) + '" readonly' + req + '>';
        }
        var opts2 = names.map(function (n) {
          return '<option' + (String(cur) === String(n) ? ' selected' : '') + '>' + esc(n) + '</option>';
        }).join('');
        return '<select data-key="' + esc(key) + '"' + req + '>' + opts2 + '</select>' + star;
      }
      // ── 計算欄（公式）：唯讀，值由 updateComputed 依公式即時算出 ──
      case 'formula':
        return '<input type="text" data-key="' + esc(key) + '" data-formula="' + esc(cell.formula || '') + '" value="' + esc(val) + '" readonly title="公式：' + esc(cell.formula || '') + '">';
      case 'textarea':
        return '<textarea data-key="' + esc(key) + '"' + req + ro + ' rows="' + (cell.rows || 4) + '">' + esc(val) + '</textarea>';
      case 'number':
        return '<input type="number" data-key="' + esc(key) + '" value="' + esc(val) + '"' + req + ro + '>';
      case 'date':
        if (!val && cell.today && mode === 'fill') val = localToday();   // 預設帶入今日
        return '<input type="date" data-key="' + esc(key) + '" value="' + esc(val) + '"' + req + ro + '>';
      case 'select': {
        var opts = ['<option value=""></option>'].concat((cell.options || []).map(function (o) {
          return '<option' + (String(val) === String(o) ? ' selected' : '') + '>' + esc(o) + '</option>';
        })).join('');
        return '<select data-key="' + esc(key) + '"' + req + ro + '>' + opts + '</select>' + star;
      }
      case 'checkbox': {
        var copts = cell.options || [];
        if (copts.length) {
          // 勾選群組：一格內多個選項（值＝勾選項目的陣列）
          var selVals = Array.isArray(val) ? val.map(String) : String(val || '').split(',').filter(Boolean);
          return '<span style="display:flex;flex-wrap:wrap;gap:2px 12px;">' + copts.map(function (o) {
            var on = selVals.indexOf(String(o)) >= 0;
            return '<label style="font-weight:normal;margin:0;white-space:nowrap;"><input type="checkbox" data-key="' + esc(key) + '" value="' + esc(o) + '"' + (on ? ' checked' : '') + ro + '> ' + esc(o) + '</label>';
          }).join('') + '</span>';
        }
        // 未設選項：單一勾選（相容舊資料）
        return '<label style="font-weight:normal;"><input type="checkbox" data-key="' + esc(key) + '"' + (val ? ' checked' : '') + ro + '> ' + esc(cell.text || '') + '</label>';
      }
      default: {
        var pat = cell.pattern ? ' data-pattern="' + esc(cell.pattern) + '"' : '';   // 格式規則（編號等，blur 時檢查）
        return '<input type="text" data-key="' + esc(key) + '" value="' + esc(val) + '"' + req + pat + ro + '>' + star;
      }
    }
  }

  // schema → HTML table 字串
  // opts: { mode:'fill'|'view', data:{}, ctx:{company,docNo,version} }
  function renderForm(schema, opts) {
    opts = opts || {};
    var mode = opts.mode || 'fill', data = opts.data || {}, ctx = opts.ctx || {};
    var meta = schema.meta || {};
    var header = meta.header || {};   // {show} 預設顯示
    var footer = meta.footer || {};   // {show} 預設顯示
    var cols = (schema.grid && schema.grid.cols) || 6;
    var cells = (schema.cells || []).slice();
    var maxR = cells.reduce(function (m, c) { return Math.max(m, c.r + (c.rs || 1)); }, 0);
    var occ = Array.from({ length: maxR }, function () { return new Array(cols).fill(false); });
    var at = {};
    cells.forEach(function (c) { at[c.r + '_' + c.c] = c; });

    var body = '';
    for (var r = 0; r < maxR; r++) {
      body += '<tr>';
      for (var c = 0; c < cols; c++) {
        if (occ[r][c]) continue;
        var cell = at[r + '_' + c];
        if (!cell) { occ[r][c] = true; body += '<td></td>'; continue; }
        var cs = cell.cs || 1, rs = cell.rs || 1;
        for (var dr = 0; dr < rs; dr++) for (var dc = 0; dc < cs; dc++) { if (occ[r + dr]) occ[r + dr][c + dc] = true; }
        var span = (cs > 1 ? ' colspan="' + cs + '"' : '') + (rs > 1 ? ' rowspan="' + rs + '"' : '');
        body += '<td' + span + ' class="' + cellClass(cell) + '">' + cellInner(cell, mode, data, ctx) + '</td>';
      }
      body += '</tr>';
    }

    var html = '<table class="eg-form">';
    html += '<colgroup>' + Array.from({ length: cols }, function () { return '<col style="width:' + (100 / cols).toFixed(4) + '%">'; }).join('') + '</colgroup>';
    if (header.show !== false && ctx.company) {
      html += '<thead><tr><th colspan="' + cols + '" class="cell-letterhead">' + esc(ctx.company) + '</th></tr></thead>';
    }
    html += '<tbody>' + body + '</tbody>';
    // 表尾：文件編號＋版次直接串接（無標籤），置右下角；無版次則只顯示編號
    var footText = (ctx.docNo || '') + (ctx.version || '');
    if (footer.show !== false && footText) {
      html += '<tfoot><tr><td colspan="' + cols + '" class="cell-footer">'
        + '<span class="ft-right">' + esc(footText) + '</span>'
        + '<span style="clear:both;display:block;"></span></td></tr></tfoot>';
    }
    html += '</table>';
    return html;
  }

  // 填寫頁 UI 互動（ai-rules/08）：雙擊清空、聚焦全選、Enter 跳欄、數字尾0省略
  // 需 jQuery；傳入 jQuery 包裝的容器
  function bindFormUX($host) {
    var $fields = $host.find('input[data-key],select[data-key],textarea[data-key]');
    $fields.on('focus', function () { if (this.type !== 'date' && this.select) this.select(); });
    $fields.on('dblclick', function () { if (this.type !== 'checkbox') { this.value = ''; $(this).trigger('focus'); } });
    $fields.on('keydown', function (e) {
      if (e.key !== 'Enter' || this.tagName === 'TEXTAREA') return;
      e.preventDefault();
      var idx = $fields.index(this);
      if (idx < $fields.length - 1) $fields.eq(idx + 1).trigger('focus');
      else $(this).closest('form,.form-sheet').find('[data-submit]').trigger('click');
    });
    $host.find('input[type=number]').on('blur', function () {
      if (this.value !== '' && !isNaN(this.value)) this.value = String(parseFloat(this.value));
    });
    // 格式規則（編號等）：blur 即時檢查，不符→紅底提示（送出時後端再驗一次）
    $host.find('input[data-pattern]').on('blur input', function () {
      var p = this.getAttribute('data-pattern');
      if (!p || this.value === '') { this.style.background = ''; this.title = ''; return; }
      try {
        var ok = new RegExp(p).test(this.value);
        this.style.background = ok ? '' : '#f6c9bc';
        this.title = ok ? '' : ('格式不符，規則：' + p);
      } catch (e) { /* regex 無效不干擾填寫 */ }
    });
    // 任何欄位變動 → 重算計算欄與圖表
    $fields.on('input change', function () { updateComputed($host); });
    updateComputed($host);
  }

  global.EGForm = { renderForm: renderForm, cellClass: cellClass, cellInner: cellInner, esc: esc,
                    bindFormUX: bindFormUX, updateComputed: updateComputed, evalFormula: evalFormula };
})(window);
