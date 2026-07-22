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

  function cellInner(cell, mode, data) {
    var type = cell.type;
    if (type === 'title') return esc(cell.text);
    if (type === 'label') return esc(cell.text) + (cell.required ? '<span class="req-star">*</span>' : '');
    if (type === 'static') return esc(cell.text);
    if (type === 'signature') {
      var v = data['__sig_' + (cell.section || cell.key)];
      if (v) return '<div>' + esc(v.name) + '</div><div class="sig-hint">' + esc(v.at || '') + '</div>';
      return '<span class="sig-hint">（待簽核）</span>';
    }
    // field
    var key = cell.key, val = data[key] != null ? data[key] : '';
    var req = cell.required ? ' data-req="1"' : '';
    var ro = (mode === 'view') ? ' readonly disabled' : '';
    var star = cell.required ? '<span class="req-star">*</span>' : '';
    switch (cell.ftype) {
      case 'textarea':
        return '<textarea data-key="' + esc(key) + '"' + req + ro + ' rows="' + (cell.rows || 4) + '">' + esc(val) + '</textarea>';
      case 'number':
        return '<input type="number" data-key="' + esc(key) + '" value="' + esc(val) + '"' + req + ro + '>';
      case 'date':
        return '<input type="date" data-key="' + esc(key) + '" value="' + esc(val) + '"' + req + ro + '>';
      case 'select': {
        var opts = ['<option value=""></option>'].concat((cell.options || []).map(function (o) {
          return '<option' + (String(val) === String(o) ? ' selected' : '') + '>' + esc(o) + '</option>';
        })).join('');
        return '<select data-key="' + esc(key) + '"' + req + ro + '>' + opts + '</select>' + star;
      }
      case 'checkbox':
        return '<label style="font-weight:normal;"><input type="checkbox" data-key="' + esc(key) + '"' + (val ? ' checked' : '') + ro + '> ' + esc(cell.text || '') + '</label>';
      default:
        return '<input type="text" data-key="' + esc(key) + '" value="' + esc(val) + '"' + req + ro + '>' + star;
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
        body += '<td' + span + ' class="' + cellClass(cell) + '">' + cellInner(cell, mode, data) + '</td>';
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
  }

  global.EGForm = { renderForm: renderForm, cellClass: cellClass, cellInner: cellInner, esc: esc, bindFormUX: bindFormUX };
})(window);
