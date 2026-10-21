/**
 * 齒輪「外形」與「內孔」圖片選擇（唯一實作；規則來自後端 eg_shape_defs()，由頁面以 window.EG_SHAPE_DEFS 提供）
 *   EGPartShape.form   ：料號編輯畫面（mount / set / get / validate）
 *   EGPartShape.filter ：清單篩選列（mount / params / reset / active）
 * 驗證規則與後端 part_shape_lib.php 的 eg_shape_normalize() 完全一致（前端即時擋、後端再擋一次）。
 */
(function (global) {
  'use strict';
  var D = function () { return global.EG_SHAPE_DEFS || { outer: {}, bore: {}, steps_min: 2, steps_max: 6 }; };
  var BODY = '#E9B872', LINE = '#8A5A2B', HOLE = '#fff';

  // ── 圖示（剖面側視圖，軸線水平；暖色系固定配色）──────────────────────────
  function rect(x, y, w, h, fill, extra) {
    return '<rect x="' + x + '" y="' + y + '" width="' + w + '" height="' + h + '" fill="' + fill + '" ' + (extra || '') + '/>';
  }
  function svg(inner) {
    return '<svg viewBox="0 0 56 40" width="56" height="40" aria-hidden="true">' + inner + '</svg>';
  }
  function icon(kind, code) {
    var s = 'stroke="' + LINE + '" stroke-width="1.2"';
    if (kind === 'outer') {
      if (code === 'flush') return svg(rect(6, 8, 44, 24, BODY, s));
      if (code === 'one_side') return svg(rect(10, 6, 24, 28, BODY, s) + rect(34, 14, 14, 12, BODY, s));
      if (code === 'both_sides') return svg(rect(18, 6, 20, 28, BODY, s) + rect(6, 14, 12, 12, BODY, s) + rect(38, 14, 12, 12, BODY, s));
      return svg('<text x="28" y="27" text-anchor="middle" font-size="20" fill="' + LINE + '">?</text>');
    }
    var b = rect(6, 4, 44, 32, BODY, s);
    if (code === 'none') return svg(b);
    if (code === 'through') return svg(b + rect(6, 15, 44, 10, HOLE, s));
    if (code === 'blind') return svg(b + rect(6, 15, 34, 10, HOLE, s));
    if (code === 'step_through') return svg(b + rect(6, 11, 22, 18, HOLE, s) + rect(28, 17, 22, 6, HOLE, s));
    if (code === 'step_blind') return svg(b + rect(6, 11, 22, 18, HOLE, s) + rect(28, 17, 12, 6, HOLE, s));
    if (code === 'ends_big') return svg(b + rect(6, 11, 12, 18, HOLE, s) + rect(38, 11, 12, 18, HOLE, s) + rect(18, 17, 20, 6, HOLE, s));
    if (code === 'ends_small') return svg(b + rect(6, 17, 12, 6, HOLE, s) + rect(38, 17, 12, 6, HOLE, s) + rect(18, 11, 20, 18, HOLE, s));
    return svg('<text x="28" y="27" text-anchor="middle" font-size="20" fill="' + LINE + '">?</text>');
  }
  function esc(t) { return String(t == null ? '' : t).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

  function chipHtml(kind, code, name, on, iconCode) {
    var ik = (kind === 'outer') ? 'outer' : 'bore', ic = iconCode || (kind === 'blind' ? (code === 'blind' ? 'blind' : 'through') : code);
    return '<span class="eg-shape-chip' + (on ? ' on' : '') + '" data-kind="' + kind + '" data-code="' + esc(code) + '" role="button" tabindex="0" title="' + esc(name) + '">' +
      icon(ik, ic) + '<span class="eg-shape-nm">' + esc(name) + '</span></span>';
  }
  function injectCss() {
    if (document.getElementById('eg-shape-css')) return;
    var st = document.createElement('style'); st.id = 'eg-shape-css';
    st.textContent =
      '.eg-shape-line{display:flex;flex-wrap:wrap;align-items:center;gap:5px;margin:3px 0}' +
      '.eg-shape-row{display:flex;flex-wrap:wrap;gap:5px;align-items:flex-start;margin:2px 0}' +
      '.eg-shape-chip{display:inline-flex;flex-direction:column;align-items:center;width:66px;padding:2px 1px 1px;border:2px solid #EADFCD;border-radius:6px;background:#FFF9F0;cursor:pointer;user-select:none;line-height:1.15}' +
      '.eg-shape-chip svg{width:44px;height:31px}' +
      '.eg-shape-chip:hover{border-color:#F0A24B}' +
      '.eg-shape-chip.on{border-color:#DD5138;background:#FDE9D0;box-shadow:0 0 0 1px #DD5138 inset}' +
      '.eg-shape-nm{font-size:10px;color:#5a3d1c;text-align:center}' +
      '.eg-shape-lab{font-weight:bold;color:#5a3d1c;font-size:12px;min-width:34px}' +
      '.eg-shape-sep{color:#d8c7ad;margin:0 2px}' +
      '.eg-shape-hint{font-size:11px;color:#8a6a3c}' +
      '.eg-shape-err{font-size:12px;color:#DD5138;margin:1px 0;min-height:0}' +
      '.eg-shape-in{display:inline-flex;align-items:center;gap:3px;margin:1px 8px 1px 0;font-size:12px;color:#5a3d1c}' +
      '.eg-shape-in input{width:76px;height:24px;padding:1px 5px;font-size:12px}' +
      '.eg-shape-in input.bad{border-color:#DD5138}';
    document.head.appendChild(st);
  }

  // ── 判定與驗證（與後端 eg_shape_normalize 同規則；孔型由系統自動判定）──────────
  function num(v) {
    v = String(v == null ? '' : v).trim();
    if (v === '') return null;
    if (isNaN(Number(v)) || Number(v) <= 0) return false;
    return Math.round(Number(v) * 1000) / 1000;
  }
  function fmt(n) { return String(Math.round(n * 1000) / 1000); }
  // 回傳 { msg:錯誤原因, bad:{欄位:1}, info:判定結果文字, type:孔型代碼 }
  function judge(st) {
    var defs = D(), bad = {}, i, d, l, r = { msg: '', bad: bad, info: '', type: '' };
    if (st.outer === 'one_side' || st.outer === 'both_sides') {
      var hv = num(st.hub);
      if (hv === false) { bad['hub'] = 1; r.msg = '最大凸出外徑必須是大於 0 的數字'; return r; }
    }
    if (!st.mode) return r;
    if (st.mode === 'single') {
      r.type = st.blind ? 'blind' : 'through';
      d = num((st.bore[0] || {}).d); l = num((st.bore[0] || {}).l);
      if (d === false) { bad['0d'] = 1; r.msg = '孔徑必須是大於 0 的數字'; return r; }
      if (l === false) { bad['0l'] = 1; r.msg = '深度必須是大於 0 的數字'; return r; }
      if (d !== null) r.info = defs.bore[r.type].name + '　Ø' + fmt(d);
      return r;
    }
    if (st.mode === 'steps') {
      r.type = st.blind ? 'step_blind' : 'step_through';
      var n = st.bore_steps || 0;
      if (!n) { r.msg = '請先輸入孔數'; r.needSteps = true; return r; }
      var vals = [], any = false;
      for (i = 0; i < n; i++) {
        d = num((st.bore[i] || {}).d); l = num((st.bore[i] || {}).l);
        if (d === false) { bad[i + 'd'] = 1; r.msg = '第 ' + (i + 1) + ' 個孔徑必須是大於 0 的數字'; return r; }
        if (l === false) { bad[i + 'l'] = 1; r.msg = '第 ' + (i + 1) + ' 個深度必須是大於 0 的數字'; return r; }
        if (d !== null) any = true;
        vals.push(d);
      }
      if (!any) return r;
      for (i = 0; i < n; i++) if (vals[i] === null) { bad[i + 'd'] = 1; r.partial = true; r.msg = '第 ' + (i + 1) + ' 個孔徑還沒填'; return r; }
      var up = true, down = true;
      for (i = 1; i < n; i++) {
        if (vals[i] === vals[i - 1]) { bad[(i - 1) + 'd'] = bad[i + 'd'] = 1; r.msg = '第 ' + i + '、' + (i + 1) + ' 個孔徑相同（' + fmt(vals[i]) + '），不是不同的階；相同請合併成一階'; return r; }
        if (vals[i] < vals[i - 1]) up = false; else down = false;
      }
      if (!up && !down) { for (i = 0; i < n; i++) bad[i + 'd'] = 1; r.msg = '孔徑順序忽大忽小，無法判定為多階；若是「兩端孔徑相同、中間不同」請改選該型態'; return r; }
      var sorted = vals.slice().sort(function (x, y) { return y - x; });
      r.info = defs.bore[r.type].name + '（' + n + ' 階）　' + sorted.map(function (v) { return 'Ø' + fmt(v); }).join(' → ') + (up ? '　（已自動排成由大到小）' : '');
      return r;
    }
    // ends：兩端同孔、中間不同
    if (st.blind) { r.msg = '「兩端同孔、中間不同」只適用通孔，請把上面改選「通孔」'; return r; }
    var e = num((st.bore[0] || {}).d), m = num((st.bore[1] || {}).d);
    if (e === false) { bad['0d'] = 1; r.msg = '孔徑必須是大於 0 的數字'; return r; }
    if (m === false) { bad['1d'] = 1; r.msg = '孔徑必須是大於 0 的數字'; return r; }
    if (e === null && m === null) return r;
    if (e === null) { bad['0d'] = 1; r.partial = true; r.msg = '請輸入兩端孔徑'; return r; }
    if (m === null) { bad['1d'] = 1; r.partial = true; r.msg = '請輸入中間孔徑'; return r; }
    if (e === m) { bad['0d'] = bad['1d'] = 1; r.msg = '兩端與中間孔徑相同，這是單一通孔，請改選「單一孔」'; return r; }
    r.type = e > m ? 'ends_big' : 'ends_small';
    r.info = defs.bore[r.type].name + '　兩端 Ø' + fmt(e) + '／中間 Ø' + fmt(m);
    return r;
  }

  // ═════ 編輯畫面 ═════
  // 流程：內孔 無孔(預設/軸狀)｜通孔｜盲孔 → 點了就直接出現輸入框並取得焦點（預設單一孔徑）
  //       → 需要時改選 多階孔徑（先輸入孔數，Enter 後自動開始輸入）或 兩端同徑中間不同 → 系統自動判定孔型
  var form = {
    el: null, st: { outer: '', hub: '', hole: '', mode: '', bore_steps: 0, bore: [] },
    mount: function (el) { injectCss(); this.el = el; this.render(); this._bind(); },
    set: function (data) {
      var s = { outer: '', hub: '', hole: '', mode: '', bore_steps: 0, bore: [] };
      if (data) {
        s.outer = data.outer_shape || '';
        s.hub = (data.hub_dia == null || data.hub_dia === '') ? '' : String(parseFloat(data.hub_dia));
        var bt = data.bore_type || '';
        if (bt === 'through' || bt === 'blind') s.mode = 'single';
        else if (bt === 'step_through' || bt === 'step_blind') s.mode = 'steps';
        else if (bt === 'ends_big' || bt === 'ends_small') s.mode = 'ends';
        if (s.mode) s.hole = (bt === 'blind' || bt === 'step_blind') ? 'blind' : 'through';
        s.bore_steps = parseInt(data.bore_steps || 0, 10) || 0;
        s.bore = (data.bore || []).map(function (b) { return { d: b.d == null ? '' : String(b.d), l: b.l == null ? '' : String(b.l) }; });
      }
      this.st = s; if (this.el) this.render();
    },
    _j: function () { var s = this.st; return { outer: s.outer, hub: s.hub, blind: s.hole === 'blind', mode: s.hole ? s.mode : '', bore_steps: s.bore_steps, bore: s.bore }; },
    get: function () {
      var j = this._j();
      return { outer: j.outer, hub_dia: (j.outer === 'one_side' || j.outer === 'both_sides') ? j.hub : '', blind: j.blind ? 1 : 0, mode: j.mode, bore_steps: j.bore_steps, bore: j.bore.map(function (b) { return { d: b.d, l: b.l }; }) };
    },
    validate: function () { return judge(this._j()).msg; },
    _bind: function () {
      var self = this, el = this.el;
      el.addEventListener('click', function (ev) {
        var chip = ev.target.closest('.eg-shape-chip'); if (chip) self._pick(chip);
      });
      el.addEventListener('keydown', function (ev) {
        var t = ev.target;
        if (ev.key === 'Enter') {
          if (t.hasAttribute && t.hasAttribute('data-cnt')) { ev.preventDefault(); ev.stopPropagation(); self._applyCount(true); return; }
          if (t.matches && t.matches('input[data-hub]')) {
            ev.preventDefault(); ev.stopPropagation();
            var nx = el.querySelector('input[data-cnt]') || el.querySelector('input[data-i]'); if (nx) { nx.focus(); nx.select(); } else { t.blur(); }
            return;
          }
          if (t.matches && t.matches('input[data-i]')) {
            ev.preventDefault(); ev.stopPropagation();           // Enter 只在這個區塊內跳下一格，不觸發表單送出
            var ins = [].slice.call(el.querySelectorAll('input[data-i]')), ix = ins.indexOf(t);
            if (ix >= 0 && ix < ins.length - 1) { ins[ix + 1].focus(); ins[ix + 1].select(); } else { t.blur(); }
            return;
          }
        }
        if ((ev.key === 'Enter' || ev.key === ' ') && t.classList && t.classList.contains('eg-shape-chip')) { ev.preventDefault(); self._pick(t); }
      }, true);
      el.addEventListener('input', function (ev) {
        var hb = ev.target.closest('input[data-hub]');
        if (hb) { self.st.hub = hb.value; self._showJudge(); return; }
        var inp = ev.target.closest('input[data-i]'); if (!inp) return;
        var i = parseInt(inp.getAttribute('data-i'), 10), f = inp.getAttribute('data-f');
        while (self.st.bore.length <= i) self.st.bore.push({ d: '', l: '' });
        self.st.bore[i][f] = inp.value;
        self._showJudge();
      });
      el.addEventListener('change', function (ev) { if (ev.target.hasAttribute && ev.target.hasAttribute('data-cnt')) self._applyCount(false); });
    },
    _focusFirst: function () {
      var f = this.el.querySelector('input[data-cnt]') || this.el.querySelector('input[data-i="0"][data-f="d"]');
      if (this.el.querySelector('input[data-cnt]') && this.st.bore_steps) f = this.el.querySelector('input[data-i="0"][data-f="d"]') || f;
      if (f) { f.focus(); if (f.select) f.select(); }
    },
    _applyCount: function (focusFirst) {
      var inp = this.el.querySelector('input[data-cnt]'); if (!inp) return;
      var defs = D(), n = parseInt(inp.value, 10), box = this.el.querySelector('.eg-shape-err');
      if (!(n >= defs.steps_min && n <= defs.steps_max)) {
        if (inp.value.trim() !== '' && box) box.textContent = '孔數請輸入 ' + defs.steps_min + '～' + defs.steps_max;
        return;
      }
      if (n === this.st.bore_steps && !focusFirst) return;
      this.st.bore_steps = n; this.st.bore = this.st.bore.slice(0, n);
      this.render();
      if (focusFirst) { var f = this.el.querySelector('input[data-i="0"][data-f="d"]'); if (f) f.focus(); }
    },
    _pick: function (chip) {
      var kind = chip.getAttribute('data-kind'), code = chip.getAttribute('data-code'), s = this.st, focus = false, focusHub = false;
      if (kind === 'outer') {
        s.outer = (code === 'other' || s.outer === code) ? '' : code;
        if (s.outer !== 'one_side' && s.outer !== 'both_sides') s.hub = '';
        else focusHub = true;
      }
      else if (kind === 'hole') {
        if (code === 'none') { s.hole = ''; s.mode = ''; s.bore = []; s.bore_steps = 0; }
        else {
          s.hole = code;
          if (!s.mode) { s.mode = 'single'; s.bore = []; s.bore_steps = 0; }
          if (code === 'blind' && s.mode === 'ends') { s.mode = 'single'; s.bore = []; s.bore_steps = 0; }   // 盲孔沒有「兩端同徑」
          focus = true;
        }
      } else if (kind === 'mode') {
        if (s.mode !== code) { s.mode = code; s.bore = []; s.bore_steps = 0; }
        focus = true;
      }
      this.render();
      if (focusHub) { var hi = this.el.querySelector('input[data-hub]'); if (hi) { hi.focus(); hi.select(); } }
      if (focus) this._focusFirst();
    },
    _showJudge: function () {
      var v = judge(this._j()), box = this.el.querySelector('.eg-shape-err'), ok = this.el.querySelector('.eg-shape-ok'); if (!box || !ok) return;
      // 還沒輸入完的提醒（孔數未填、少填一格）不要在打字當下紅字，只有明確錯誤才立即顯示
      box.textContent = (v.needSteps || v.partial) ? '' : v.msg;
      ok.textContent = v.msg ? '' : (v.info ? '系統判定：' + v.info : '');
      this.el.querySelectorAll('input[data-i]').forEach(function (inp) {
        inp.classList.toggle('bad', !!v.bad[inp.getAttribute('data-i') + inp.getAttribute('data-f')]);
      });
      var hb = this.el.querySelector('input[data-hub]'); if (hb) hb.classList.toggle('bad', !!v.bad['hub']);
    },
    render: function () {
      var defs = D(), s = this.st, h = '', blind = s.hole === 'blind';
      h += '<div class="eg-shape-line"><span class="eg-shape-lab" title="齒寬幾乎＝工件總長選「平板型」；一側有輪轂選「單邊凸出」；兩側都有選「雙邊凸出」；沒選＝其他。再點一次已選的可取消。">外形</span>';
      Object.keys(defs.outer).forEach(function (c) { h += chipHtml('outer', c, defs.outer[c], s.outer === c); });
      h += chipHtml('outer', 'other', '其他', s.outer === '');
      if (s.outer === 'one_side' || s.outer === 'both_sides') {
        h += '<span class="eg-shape-sep">│</span><label class="eg-shape-in">最大凸出外徑 <input type="number" step="any" min="0" data-hub="1" value="' + esc(s.hub) + '" class="form-control input-sm no-spin-input"> mm</label>';
      }
      h += '</div>';

      h += '<div class="eg-shape-line"><span class="eg-shape-lab" title="沒選或軸狀工件＝無孔">內孔</span>' +
        chipHtml('hole', 'none', '無孔', s.hole === '') + chipHtml('hole', 'through', '通孔', s.hole === 'through') + chipHtml('hole', 'blind', '盲孔', s.hole === 'blind');
      if (s.hole) {
        h += '<span class="eg-shape-sep">│</span>' +
          chipHtml('mode', 'single', '單一孔徑', s.mode === 'single', blind ? 'blind' : 'through') +
          chipHtml('mode', 'steps', '多階孔徑', s.mode === 'steps', blind ? 'step_blind' : 'step_through') +
          (blind ? '' : chipHtml('mode', 'ends', '兩端同徑 中間不同', s.mode === 'ends', 'ends_big'));
      } else {
        h += '<span class="eg-shape-hint">未選＝無孔（軸狀工件也是無孔）</span>';
      }
      // 輸入框直接接在選項同一排（跟標籤一樣：點了就在旁邊出現可輸入的欄位）
      if (s.hole && s.mode) {
        h += '<span class="eg-shape-sep">│</span>';
        if (s.mode === 'steps') {
          h += '<label class="eg-shape-in">孔數 <input type="number" min="' + defs.steps_min + '" max="' + defs.steps_max + '" step="1" data-cnt="1" value="' + (s.bore_steps || '') + '" class="form-control input-sm no-spin-input" style="width:56px" placeholder="' + defs.steps_min + '～' + defs.steps_max + '"></label>' +
            '<span class="eg-shape-hint">輸入後按 Enter，自動開始輸入孔徑；大到小或小到大都可以，系統自動排列</span>';
          if (s.bore_steps) {
            h += '</div><div class="eg-shape-line">';
            for (var i = 0; i < s.bore_steps; i++) h += this._in(i, '第' + (i + 1) + '孔徑', 'd');
          }
        } else if (s.mode === 'single') {
          h += this._in(0, '孔徑', 'd');
        } else {
          h += this._in(0, '兩端孔徑', 'd') + this._in(1, '中間孔徑', 'd') + '<span class="eg-shape-hint">兩端孔徑相同只輸入一次</span>';
        }
      }
      h += '</div>';
      h += '<div class="eg-shape-ok" style="font-size:12px;color:#5a3d1c;font-weight:bold;min-height:0"></div><div class="eg-shape-err"></div>';
      this.el.innerHTML = h;
      this._showJudge();
    },
    _in: function (i, label, f) {
      var v = (this.st.bore[i] || {})[f] || '';
      return '<label class="eg-shape-in">' + esc(label) + ' <input type="number" step="any" min="0" class="form-control input-sm no-spin-input" data-i="' + i + '" data-f="' + f + '" value="' + esc(v) + '"></label>';
    }
  };

  // ═════ 清單篩選 ═════
  var filter = {
    el: null, outer: [], bore: [], onChange: null, _t: null,
    mount: function (el, onChange) {
      injectCss(); this.el = el; this.onChange = onChange; this.render();
      var self = this;
      el.addEventListener('click', function (ev) {
        var chip = ev.target.closest('.eg-shape-chip'); if (!chip) return;
        var list = chip.getAttribute('data-kind') === 'outer' ? self.outer : self.bore, c = chip.getAttribute('data-code'), ix = list.indexOf(c);
        if (ix >= 0) list.splice(ix, 1); else list.push(c);
        chip.classList.toggle('on', ix < 0);
        self._fire();
      });
      el.addEventListener('input', function (ev) { if (ev.target.matches('input')) self._fire(); });
    },
    _fire: function () { var self = this; clearTimeout(this._t); this._t = setTimeout(function () { if (self.onChange) self.onChange(); }, 350); },
    render: function () {
      var defs = D(), h = '', self = this;
      h += '<span class="filter-group-label">外形</span><span class="eg-shape-row" style="display:inline-flex;margin:0">';
      Object.keys(defs.outer).forEach(function (c) { h += chipHtml('outer', c, defs.outer[c], self.outer.indexOf(c) >= 0); });
      h += chipHtml('outer', 'other', '其他', self.outer.indexOf('other') >= 0) + '</span>';
      h += '<br><span class="filter-group-label">內孔</span><span class="eg-shape-row" style="display:inline-flex;margin:0">';
      Object.keys(defs.bore).forEach(function (c) { h += chipHtml('bore', c, defs.bore[c].name, self.bore.indexOf(c) >= 0); });
      h += chipHtml('bore', 'none', '無孔', self.bore.indexOf('none') >= 0) + '</span>';
      h += '<br><span class="filter-group-label" title="單邊／雙邊凸出的最大凸出外徑">凸出外徑</span>' +
        '<input type="number" step="any" data-sh="min" class="form-control input-sm no-spin-input" placeholder="最小" style="width:64px;display:inline-block;height:24px;padding:2px 6px;font-size:12px">' +
        '<span style="color:#888;margin:0 2px;">~</span>' +
        '<input type="number" step="any" data-sh="max" class="form-control input-sm no-spin-input" placeholder="最大" style="width:64px;display:inline-block;height:24px;padding:2px 6px;font-size:12px">' +
        '<span style="font-size:11px;color:#888;margin:0 12px 0 3px;">mm</span>' +
        '<span class="filter-group-label" title="只要這個料號有任何一階孔徑落在範圍內就會出現">孔徑</span>' +
        '<input type="number" step="any" data-sb="min" class="form-control input-sm no-spin-input" placeholder="最小" style="width:64px;display:inline-block;height:24px;padding:2px 6px;font-size:12px">' +
        '<span style="color:#888;margin:0 2px;">~</span>' +
        '<input type="number" step="any" data-sb="max" class="form-control input-sm no-spin-input" placeholder="最大" style="width:64px;display:inline-block;height:24px;padding:2px 6px;font-size:12px">' +
        '<span style="font-size:11px;color:#888;margin-left:3px;">mm（多階層：任一階落在範圍內即符合）</span>';
      this.el.innerHTML = h;
    },
    _v: function (k) { var i = this.el && this.el.querySelector('input[data-sb="' + k + '"]'); return i ? i.value.trim() : ''; },
    _h: function (k) { var i = this.el && this.el.querySelector('input[data-sh="' + k + '"]'); return i ? i.value.trim() : ''; },
    params: function () {
      return { filter_shape_outer: this.outer.join(','), filter_shape_bore: this.bore.join(','), filter_shape_bore_min: this._v('min'), filter_shape_bore_max: this._v('max'), filter_shape_hub_min: this._h('min'), filter_shape_hub_max: this._h('max') };
    },
    active: function () { return !!(this.outer.length || this.bore.length || this._v('min') !== '' || this._v('max') !== '' || this._h('min') !== '' || this._h('max') !== ''); },
    reset: function () { this.outer = []; this.bore = []; if (this.el) this.render(); }
  };

  global.EGPartShape = { form: form, filter: filter, icon: icon };
})(window);
