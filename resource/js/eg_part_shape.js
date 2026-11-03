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
  // 篩選用的旗標籤（有外錐／有外徑段／含錐孔）：與其他籤同樣外觀，但後端用「且」條件處理
  function flagChip(kind, code, name, on, inner) {
    return '<span class="eg-shape-chip' + (on ? ' on' : '') + '" data-kind="' + kind + '" data-code="' + esc(code) + '" role="button" tabindex="0" title="' + esc(name) + '">' +
      svg(inner) + '<span class="eg-shape-nm">' + esc(name) + '</span></span>';
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
  // 錐孔檢查（與後端 eg_shape_taper_part 同規則）：d 為大徑；回傳 null＝沒問題，否則 {msg, partial}
  function taperMsg(b, i, bad, d, lab) {
    if (!b || !b.tp) return null;
    var d2 = num(b.d2), rr = num(b.r), ha = num(b.ha);
    if (ha === false || (ha !== null && ha >= 90)) { bad[i + 'ha'] = 1; return { msg: lab + '半錐角必須大於 0 且小於 90 度' }; }
    if (d2 === false) { bad[i + 'd2'] = 1; return { msg: lab + '小徑必須是大於 0 的數字' }; }
    if (rr === false) { bad[i + 'r'] = 1; return { msg: lab + '錐度必須是大於 0 的數字' }; }
    if (num(b.tl) === false) { bad[i + 'tl'] = 1; return { msg: lab + '錐長必須是大於 0 的數字' }; }
    if (d2 === null && rr === null && ha === null) { bad[i + 'd2'] = bad[i + 'r'] = bad[i + 'ha'] = 1; return { msg: lab + '勾選了錐，請填小徑、錐度或半錐角（至少一個）', partial: true }; }
    if (d2 !== null && d !== null && d2 === d) { bad[i + 'd2'] = 1; return { msg: lab + '大徑與小徑相同，不是錐孔' }; }
    if (d2 !== null && d !== null && d2 > d) { bad[i + 'd2'] = 1; return { msg: lab + '小徑必須小於大徑（請把較大的填在大徑）', partial: true }; }   // 離開欄位時會自動對調，打字當下不紅字
    return null;
  }
  // 由大徑／小徑／錐長（或錐度 1:N）算出錐度資訊；資料不足回 null
  function taperCalc(b) {
    if (!b || !b.tp) return null;
    var D = num(b.d), s = num(b.d2), L = num(b.tl), r = num(b.r), ha = num(b.ha), C, dd = false;
    if (!r && ha && ha < 90) r = 1 / (2 * Math.tan(ha * Math.PI / 180));   // 半錐角 θ：錐度 1:N，N＝1/(2·tanθ)
    if (!D) { if (s && L && r) { D = s + L / r; dd = true; } else return null; }   // 大徑沒填：由 小徑＋錐長＋錐度（或半錐角）算出
    if (s && L) C = (D - s) / L;
    else if (s && r) { C = 1 / r; L = (D - s) * r; }
    else if (r && L) { C = 1 / r; s = D - L * C; if (s <= 0) return null; }
    else if (r) { C = 1 / r; s = null; L = null; }
    else return null;
    if (!(C > 0)) return null;
    var half = Math.atan(C / 2) * 180 / Math.PI;
    return { D: D, dd: dd, s: s, L: L, n: 1 / C, half: half };
  }
  function taperInfo(b) {
    var t = taperCalc(b); if (!t) return '';
    var r3 = function (v) { return String(Math.round(v * 1000) / 1000); };
    return '錐度 1:' + r3(t.n) + '　半錐角 ' + r3(t.half) + '°（全錐角 ' + r3(t.half * 2) + '°）' + (t.dd ? '　大徑 Ø' + r3(t.D) : '') + (t.s ? '　小徑 Ø' + r3(t.s) : '') + (t.L ? '　錐長 ' + r3(t.L) : '');
  }
  // 外錐／重點外徑段的檢查（與後端 eg_shape_outer_extra 同規則）；回傳 {msg, partial, bad}
  function outerExtra(st) {
    var bad = {}, r = { msg: '', bad: bad }, i, o, g;
    for (i = 0; i < (st.otp || []).length; i++) {
      o = st.otp[i];
      if (!['d', 'd2', 'tl', 'r', 'ha'].some(function (k) { return String(o[k] || '').trim() !== ''; })) continue;
      var lab = '第 ' + (i + 1) + ' 個外錐', d = num(o.d);
      if (d === null) { var tcx = taperCalc(Object.assign({ tp: 1 }, o)); if (tcx && tcx.dd) d = tcx.D; }   // 大徑可由 小徑＋錐長＋錐度/半錐角 算出
      if (d === false || d === null) { bad['otp' + i + 'd'] = 1; r.msg = lab + '請填大徑，或填「小徑＋錐長＋錐度（或半錐角）」讓系統算出大徑'; r.partial = d === null; return r; }
      var tb = {}, tm = taperMsg(Object.assign({ tp: 1 }, o), 0, tb, d, lab);
      if (tm) { Object.keys(tb).forEach(function (k) { bad['otp' + i + k.replace(/^0/, '')] = 1; }); r.msg = tm.msg; r.partial = !!tm.partial; return r; }
    }
    for (i = 0; i < (st.seg || []).length; i++) {
      g = st.seg[i];
      if (!String(g.d || '').trim() && !String(g.l || '').trim() && !String(g.n || '').trim()) continue;
      var lb = '第 ' + (i + 1) + ' 段外徑', gd = num(g.d), gl = num(g.l);
      if (gd === false || gd === null) { bad['seg' + i + 'd'] = 1; r.msg = lb + '請填外徑（大於 0 的數字）'; r.partial = gd === null; return r; }
      if (gl === false) { bad['seg' + i + 'l'] = 1; r.msg = lb + '長度必須是大於 0 的數字'; return r; }
    }
    return r;
  }
  // 回傳 { msg:錯誤原因, bad:{欄位:1}, info:判定結果文字, type:孔型代碼 }
  function judge(st) {
    var defs = D(), bad = {}, i, d, l, r = { msg: '', bad: bad, info: '', type: '' };
    if (st.outer === 'flush') {   // 平板型：外徑可輸入；齒輪可省略（＝最大外徑同齒部外徑），治具必填
      var fv = num(st.hub);
      if (fv === false) { bad['hub'] = 1; r.msg = '外徑必須是大於 0 的數字'; return r; }
      if (fv === null && st.ptype === 'J') { bad['hub'] = 1; r.msg = '治具請填外徑'; r.partial = true; return r; }
    }
    if (st.outer === 'one_side' || st.outer === 'both_sides') {
      var hv = num(st.hub), pf = (st.outer === 'both_sides' && !st.hub_same) ? '左側' : '';
      var tpErr = function (o, lab, keyOf) {   // 「錐」的檢查：有錯回傳 true（訊息已寫入 r.msg）
        var d = num(o.d);
        if (d === null) { var tcx = taperCalc(Object.assign({ tp: 1 }, o)); if (tcx && tcx.dd) d = tcx.D; }
        if (d === false || d === null) { bad[keyOf('d')] = 1; r.msg = lab + '請填大徑，或填「小徑＋長度＋錐度（或半錐角）」讓系統算出大徑'; r.partial = d === null; return true; }
        var tb = {}, tm = taperMsg(Object.assign({ tp: 1 }, o), 0, tb, d, lab);
        if (tm) { Object.keys(tb).forEach(function (k) { bad[keyOf(k.replace(/^0/, ''))] = 1; }); r.msg = tm.msg; r.partial = !!tm.partial; return true; }
        return false;
      };
      if (st.hub_multi) {   // 多階凸出：各階外徑／長度各自選填，只檢查格式
        var sidesM = [['a', pf]]; if (pf) sidesM.push(['b', '右側']);
        for (var si = 0; si < sidesM.length; si++) {
          var arrM = st['hub_' + sidesM[si][0]] || [];
          for (var gi = 0; gi < arrM.length; gi++) {
            if (num(arrM[gi].d) === false) { bad['hs' + sidesM[si][0] + gi + 'd'] = 1; r.msg = sidesM[si][1] + '第 ' + (gi + 1) + ' 階凸出外徑必須是大於 0 的數字'; return r; }
            if (num(arrM[gi].l) === false) { bad['hs' + sidesM[si][0] + gi + 'l'] = 1; r.msg = sidesM[si][1] + '第 ' + (gi + 1) + ' 階凸出長度必須是大於 0 的數字'; return r; }
            if (arrM[gi].tp) {
              var gk = sidesM[si][0] + gi;
              if (tpErr({ d: arrM[gi].d, d2: arrM[gi].d2, tl: arrM[gi].l, r: arrM[gi].r, ha: arrM[gi].ha }, sidesM[si][1] + '第 ' + (gi + 1) + ' 階（錐）', function (f) { return 'hs' + gk + (f === 'tl' ? 'l' : f); })) return r;
            }
          }
        }
      } else {
      if (hv === false) { bad['hub'] = 1; r.msg = pf + '凸出外徑必須是大於 0 的數字'; return r; }
      if (num(st.hub_len) === false) { bad['hublen'] = 1; r.msg = pf + '凸出長度必須是大於 0 的數字'; return r; }
      if (st.outer === 'both_sides' && !st.hub_same) {
        if (num(st.hub2) === false) { bad['hub2'] = 1; r.msg = '右側凸出外徑必須是大於 0 的數字'; return r; }
        if (num(st.hub2_len) === false) { bad['hub2len'] = 1; r.msg = '右側凸出長度必須是大於 0 的數字'; return r; }
      }
      if (st.hub_ta && st.hub_ta.tp && tpErr({ d: st.hub, d2: st.hub_ta.d2, tl: st.hub_len, r: st.hub_ta.r, ha: st.hub_ta.ha }, pf + '凸出', function (f) { return f === 'd' ? 'hub' : f === 'tl' ? 'hublen' : 'tpta_' + f; })) return r;
      if (st.outer === 'both_sides' && !st.hub_same && st.hub_tb && st.hub_tb.tp && tpErr({ d: st.hub2, d2: st.hub_tb.d2, tl: st.hub2_len, r: st.hub_tb.r, ha: st.hub_tb.ha }, '右側凸出', function (f) { return f === 'd' ? 'hub2' : f === 'tl' ? 'hub2len' : 'tptb_' + f; })) return r;
      }
    }
    if (!st.mode) return r;
    if (st.mode === 'single') {
      r.type = st.blind ? 'blind' : 'through';
      d = num((st.bore[0] || {}).d); l = num((st.bore[0] || {}).l);
      if (d === false) { bad['0d'] = 1; r.msg = '孔徑必須是大於 0 的數字'; return r; }
      if (l === false) { bad['0l'] = 1; r.msg = '深度必須是大於 0 的數字'; return r; }
      var tm0 = taperMsg(st.bore[0], 0, bad, d, ''); if (tm0) { r.msg = tm0.msg; r.partial = !!tm0.partial; return r; }
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
        var tmi = taperMsg(st.bore[i], i, bad, d, '第 ' + (i + 1) + ' 孔'); if (tmi) { r.msg = tmi.msg; r.partial = !!tmi.partial; return r; }
        if (d !== null) any = true;
        vals.push(d);
      }
      if (!any) return r;
      for (i = 0; i < n; i++) if (vals[i] === null) { bad[i + 'd'] = 1; r.partial = true; r.msg = '第 ' + (i + 1) + ' 個孔徑還沒填'; return r; }
      // 孔徑輸入順序不拘，一律自動排成由大到小；只擋「兩階孔徑相同」
      for (i = 0; i < n; i++) for (var k2 = i + 1; k2 < n; k2++) {
        if (vals[i] === vals[k2]) { bad[i + 'd'] = bad[k2 + 'd'] = 1; r.msg = '第 ' + (i + 1) + '、' + (k2 + 1) + ' 個孔徑相同（' + fmt(vals[i]) + '），不是不同的階；相同請合併成一階'; return r; }
      }
      var inOrder = vals.every(function (v, j) { return j === 0 || v < vals[j - 1]; });
      var sorted = vals.slice().sort(function (x, y) { return y - x; });
      r.info = defs.bore[r.type].name + '（' + n + ' 階）　' + sorted.map(function (v) { return 'Ø' + fmt(v); }).join(' → ') + (inOrder ? '' : '　（已自動排成由大到小）');
      return r;
    }
    // ends：兩端同孔、中間不同
    if (st.blind) { r.msg = '「兩端同孔、中間不同」只適用通孔，請把上面改選「通孔」'; return r; }
    var e = num((st.bore[0] || {}).d), m = num((st.bore[1] || {}).d);
    if (e === false) { bad['0d'] = 1; r.msg = '孔徑必須是大於 0 的數字'; return r; }
    if (m === false) { bad['1d'] = 1; r.msg = '孔徑必須是大於 0 的數字'; return r; }
    var te0 = taperMsg(st.bore[0], 0, bad, e, '兩端'); if (te0) { r.msg = te0.msg; r.partial = !!te0.partial; return r; }
    var te1 = taperMsg(st.bore[1], 1, bad, m, '中間'); if (te1) { r.msg = te1.msg; r.partial = !!te1.partial; return r; }
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
    el: null, ptype: '', st: { outer: '', hub: '', hub_len: '', hub2: '', hub2_len: '', hub_same: true, hub_multi: false, hub_a: [], hub_b: [], hub_ta: { tp: 0, d2: '', r: '', ha: '' }, hub_tb: { tp: 0, d2: '', r: '', ha: '' }, hole: '', mode: '', bore_steps: 0, bore: [], otp: [], seg: [] },
    mount: function (el) { injectCss(); this.el = el; this.render(); this._bind(); },
    // 工件種類（G 齒輪／J 治具）：平板型外徑的提示與必填與否不同；不放進 st，免得 set() 重設時被洗掉
    setType: function (t) { this.ptype = t || ''; if (this.el) this.render(); },
    set: function (data) {
      var s = { outer: '', hub: '', hub_len: '', hub2: '', hub2_len: '', hub_same: true, hub_multi: false, hub_a: [], hub_b: [], hub_ta: { tp: 0, d2: '', r: '', ha: '' }, hub_tb: { tp: 0, d2: '', r: '', ha: '' }, hole: '', mode: '', bore_steps: 0, bore: [], otp: [], seg: [] };
      if (data) {
        var sv = function (x) { return x == null ? '' : String(x); };
        s.otp = (data.otp || []).map(function (o) { return { d: sv(o.d), d2: sv(o.d2), tl: sv(o.tl), r: sv(o.r), ha: sv(o.ha) }; });
        s.seg = (data.seg || []).map(function (g) { return { d: sv(g.d), l: sv(g.l), n: sv(g.n) }; });
        s.outer = data.outer_shape || '';
        s.hub = (data.hub_dia == null || data.hub_dia === '') ? '' : String(parseFloat(data.hub_dia));
        s.hub_len = (data.hub_len == null || data.hub_len === '') ? '' : String(parseFloat(data.hub_len));
        s.hub2 = (data.hub2_dia == null || data.hub2_dia === '') ? '' : String(parseFloat(data.hub2_dia));
        s.hub2_len = (data.hub2_len == null || data.hub2_len === '') ? '' : String(parseFloat(data.hub2_len));
        s.hub_same = (s.hub2 === '' && s.hub2_len === '');   // 右側沒資料＝兩側相同
        if (data.hub_multi) {   // 多階凸出：各階依輸入順序（不排序）
          var hjm = function (a) { return (a || []).map(function (g) { return { d: sv(g.d), l: sv(g.l), tp: g.tp ? 1 : 0, d2: sv(g.d2), r: sv(g.r), ha: sv(g.ha) }; }); };
          s.hub_multi = true; s.hub_a = hjm(data.hub_a); s.hub_b = hjm(data.hub_b); s.hub_same = !s.hub_b.length;
        }
        var tpf = function (o) { return o ? { tp: 1, d2: sv(o.d2), r: sv(o.r), ha: sv(o.ha) } : { tp: 0, d2: '', r: '', ha: '' }; };
        s.hub_ta = tpf(data.hub_ta); s.hub_tb = tpf(data.hub_tb);
        if (data.hub_tb) s.hub_same = false;
        var bt = data.bore_type || '';
        if (bt === 'through' || bt === 'blind') s.mode = 'single';
        else if (bt === 'step_through' || bt === 'step_blind') s.mode = 'steps';
        else if (bt === 'ends_big' || bt === 'ends_small') s.mode = 'ends';
        if (s.mode) s.hole = (bt === 'blind' || bt === 'step_blind') ? 'blind' : 'through';
        s.bore_steps = parseInt(data.bore_steps || 0, 10) || 0;
        s.bore = (data.bore || []).map(function (b) { return { d: b.d == null ? '' : String(b.d), l: b.l == null ? '' : String(b.l), tp: b.tp ? 1 : 0, d2: b.d2 == null ? '' : String(b.d2), r: b.r == null ? '' : String(b.r), tl: b.tl == null ? '' : String(b.tl), ha: b.ha == null ? '' : String(b.ha) }; });
      }
      this.st = s; if (this.el) this.render();
    },
    _j: function () { var s = this.st; return { outer: s.outer, hub: s.hub, hub_len: s.hub_len, hub2: s.hub2, hub2_len: s.hub2_len, hub_same: s.hub_same, hub_multi: s.hub_multi, hub_a: s.hub_a, hub_b: s.hub_b, hub_ta: s.hub_ta, hub_tb: s.hub_tb, ptype: this.ptype || '', blind: s.hole === 'blind', mode: s.hole ? s.mode : '', bore_steps: s.bore_steps, bore: s.bore }; },
    get: function () {
      var j = this._j(), st = this.st;
      var any = function (o) { return Object.keys(o).some(function (k) { return String(o[k] || '').trim() !== ''; }); };
      var hubOn = j.outer === 'one_side' || j.outer === 'both_sides';
      var stepsOut = function (arr) {   // 多階凸出：依輸入順序，兩格都空的階略過
        return (arr || []).filter(function (g) { return ['d', 'l', 'd2', 'r', 'ha'].some(function (k) { return String(g[k] || '').trim() !== ''; }); })
          .map(function (g) { return g.tp ? { d: g.d, l: g.l, tp: 1, d2: g.d2, r: g.r, ha: g.ha } : { d: g.d, l: g.l }; });
      };
      return { hub_multi: (hubOn && st.hub_multi) ? 1 : 0, hub_a: (hubOn && st.hub_multi) ? stepsOut(st.hub_a) : [], hub_b: (hubOn && st.hub_multi && j.outer === 'both_sides' && !st.hub_same) ? stepsOut(st.hub_b) : [],
        hub_ta: (hubOn && !st.hub_multi && st.hub_ta && st.hub_ta.tp) ? { tp: 1, d2: st.hub_ta.d2, r: st.hub_ta.r, ha: st.hub_ta.ha } : null,
        hub_tb: (j.outer === 'both_sides' && !st.hub_same && !st.hub_multi && st.hub_tb && st.hub_tb.tp) ? { tp: 1, d2: st.hub_tb.d2, r: st.hub_tb.r, ha: st.hub_tb.ha } : null,
        otp: st.otp.filter(any).map(function (o) {
        if (String(o.d || '').trim() === '') { var tc = taperCalc(Object.assign({ tp: 1 }, o)); if (tc && tc.dd) o = Object.assign({}, o, { d: String(Math.round(tc.D * 1000) / 1000) }); }
        return o;
      }), seg: st.seg.filter(any), outer: j.outer, hub_dia: (hubOn || j.outer === 'flush') ? j.hub : '', hub_len: (j.outer === 'one_side' || j.outer === 'both_sides') ? j.hub_len : '', hub2_dia: (j.outer === 'both_sides' && !st.hub_same) ? st.hub2 : '', hub2_len: (j.outer === 'both_sides' && !st.hub_same) ? st.hub2_len : '', blind: j.blind ? 1 : 0, mode: j.mode, bore_steps: j.bore_steps, bore: (j.mode === 'steps' ? j.bore.slice().sort(function (a, b) { var x = num(a.d), y = num(b.d); return (x && y) ? y - x : 0; }) : j.bore).map(function (b) { return b.tp ? { d: b.d, l: b.l, tp: 1, d2: b.d2, r: b.r, tl: b.tl, ha: b.ha } : { d: b.d, l: b.l }; }) };
    },
    validate: function () { return judge(this._j()).msg || outerExtra(this.st).msg; },
    _bind: function () {
      var self = this, el = this.el;
      el.addEventListener('click', function (ev) {
        var chip = ev.target.closest('.eg-shape-chip'); if (chip) self._pick(chip);
      });
      el.addEventListener('click', function (ev) {
        var ab = ev.target.closest('[data-oadd]'), db = ev.target.closest('[data-odel]');
        if (ab) {
          var k = ab.getAttribute('data-oadd'), lim = k === 'otp' ? 6 : 12;
          if (self.st[k].length >= lim) return;
          self.st[k].push(k === 'otp' ? { d: '', d2: '', tl: '', r: '', ha: '' } : { d: '', l: '', n: '' });
          self.render();
          var f = el.querySelector('input[data-o="' + k + '"][data-oi="' + (self.st[k].length - 1) + '"]'); if (f) f.focus();
        } else if (db) {
          var p = db.getAttribute('data-odel').split(':'); self.st[p[0]].splice(parseInt(p[1], 10), 1); self.render();
        }
      });
      el.addEventListener('keydown', function (ev) {
        var t = ev.target;
        if (ev.key === 'Enter') {
          if (t.hasAttribute && t.hasAttribute('data-cnt')) { ev.preventDefault(); ev.stopPropagation(); self._applyCount(true); return; }
          if (t.matches && t.matches('input[data-hubf]')) {
            ev.preventDefault(); ev.stopPropagation();
            var hfs = [].slice.call(el.querySelectorAll('input[data-hubf]')), hx = hfs.indexOf(t);
            if (hx >= 0 && hx < hfs.length - 1) { hfs[hx + 1].focus(); hfs[hx + 1].select(); return; }
            var nx = el.querySelector('input[data-cnt]') || el.querySelector('input[data-i]'); if (nx) { nx.focus(); nx.select(); } else { t.blur(); }
            return;
          }
          if (t.matches && t.matches('input[data-oi]')) {
            ev.preventDefault(); ev.stopPropagation();           // 外錐／外徑段：Enter 只在這個區塊內跳下一格
            var oins = [].slice.call(el.querySelectorAll('input[data-oi]')), ox = oins.indexOf(t);
            if (ox >= 0 && ox < oins.length - 1) { oins[ox + 1].focus(); oins[ox + 1].select(); } else { t.blur(); }
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
        var htp = ev.target.closest('input[data-hubtpf]');
        if (htp) {   // 單階凸出勾「錐」後的小徑／錐度／半錐角
          var tobj = self.st['hub_' + htp.getAttribute('data-hubtpf')];
          if (tobj) { tobj[htp.getAttribute('data-hf')] = htp.value; self._showJudge(); }
          return;
        }
        var hs = ev.target.closest('input[data-hubs]');
        if (hs) {
          var hsk = hs.getAttribute('data-hubs'), hgi = parseInt(hs.getAttribute('data-hi'), 10), harr = self.st['hub_' + hsk];
          if (harr && harr[hgi]) {
            harr[hgi][hs.getAttribute('data-hf')] = hs.value;
            // 最後一階有資料就自動多出一格（連續輸入，不必先決定幾階）
            if (hgi === harr.length - 1 && (String(harr[hgi].d || '').trim() !== '' || String(harr[hgi].l || '').trim() !== '')) {
              harr.push({ d: '', l: '' });
              var hbox = self.el.querySelector('[data-hubrows="' + hsk + '"]');
              if (hbox) hbox.insertAdjacentHTML('beforeend', self._hubRow(hsk, harr.length - 1));
            }
            self._showJudge();
          }
          return;
        }
        var hf = ev.target.closest('input[data-hubf]');
        if (hf) {
          var hk = hf.hasAttribute('data-hub') ? 'hub' : hf.hasAttribute('data-hublen') ? 'hub_len' : hf.hasAttribute('data-hub2') ? 'hub2' : 'hub2_len';
          self.st[hk] = hf.value; self._showJudge(); return;
        }
        var oin = ev.target.closest('input[data-oi]');
        if (oin) {
          var lst = self.st[oin.getAttribute('data-o')], oi = parseInt(oin.getAttribute('data-oi'), 10);
          if (lst && lst[oi]) { lst[oi][oin.getAttribute('data-of')] = oin.value; self._showJudge(); }
          return;
        }
        var inp = ev.target.closest('input[data-i]'); if (!inp) return;
        var i = parseInt(inp.getAttribute('data-i'), 10), f = inp.getAttribute('data-f');
        while (self.st.bore.length <= i) self.st.bore.push({ d: '', l: '' });
        self.st.bore[i][f] = inp.value;
        self._showJudge();
      });
      el.addEventListener('change', function (ev) {
        var t = ev.target;
        if (t.hasAttribute && t.hasAttribute('data-cnt')) { self._applyCount(false); return; }
        if (t.hasAttribute && t.hasAttribute('data-hubtpc')) {   // 單階凸出：勾選「錐」
          var tk = t.getAttribute('data-hubtpc'), tob = self.st['hub_' + tk];
          if (tob) { tob.tp = t.checked ? 1 : 0; if (!t.checked) { tob.d2 = ''; tob.r = ''; tob.ha = ''; } }
          self.render();
          var tfe = self.el.querySelector(tk === 'ta' ? 'input[data-hub]' : 'input[data-hub2]'); if (tfe) { tfe.focus(); tfe.select(); }
          return;
        }
        if (t.hasAttribute && t.hasAttribute('data-hubtp')) {   // 多階凸出：這一階勾選「錐」
          var tpp = t.getAttribute('data-hubtp').split(':'), tstep = (self.st['hub_' + tpp[0]] || [])[parseInt(tpp[1], 10)];
          if (tstep) { tstep.tp = t.checked ? 1 : 0; if (!t.checked) { tstep.d2 = ''; tstep.r = ''; tstep.ha = ''; } }
          self.render();
          var tfs = self.el.querySelector('input[data-hubs="' + tpp[0] + '"][data-hi="' + tpp[1] + '"][data-hf="d"]'); if (tfs) { tfs.focus(); tfs.select(); }
          return;
        }
        // 凸出的錐：小徑比大徑大時，離開欄位自動對調（原地換值，不重畫，游標才不會掉）
        var swapPair = function (oA, kA, oB, kB, eA, eB) {
          var x = num(oA[kA]), y = num(oB[kB]);
          if (x && y && y > x) { var tmpv0 = oA[kA]; oA[kA] = oB[kB]; oB[kB] = tmpv0; if (eA) eA.value = oA[kA]; if (eB) eB.value = oB[kB]; self._showJudge(); return true; }
          return false;
        };
        if (t.hasAttribute && t.hasAttribute('data-hubs') && (t.getAttribute('data-hf') === 'd' || t.getAttribute('data-hf') === 'd2')) {
          var sk3 = t.getAttribute('data-hubs'), si3 = t.getAttribute('data-hi'), g3 = (self.st['hub_' + sk3] || [])[parseInt(si3, 10)], q3 = 'input[data-hubs="' + sk3 + '"][data-hi="' + si3 + '"]';
          if (g3 && g3.tp && swapPair(g3, 'd', g3, 'd2', self.el.querySelector(q3 + '[data-hf="d"]'), self.el.querySelector(q3 + '[data-hf="d2"]'))) return;
        }
        if (self.st.hub_ta && self.st.hub_ta.tp && (t.hasAttribute('data-hub') || (t.getAttribute('data-hubtpf') === 'ta' && t.getAttribute('data-hf') === 'd2'))) {
          if (swapPair(self.st, 'hub', self.st.hub_ta, 'd2', self.el.querySelector('input[data-hub]'), self.el.querySelector('input[data-hubtpf="ta"][data-hf="d2"]'))) return;
        }
        if (self.st.hub_tb && self.st.hub_tb.tp && (t.hasAttribute('data-hub2') || (t.getAttribute('data-hubtpf') === 'tb' && t.getAttribute('data-hf') === 'd2'))) {
          if (swapPair(self.st, 'hub2', self.st.hub_tb, 'd2', self.el.querySelector('input[data-hub2]'), self.el.querySelector('input[data-hubtpf="tb"][data-hf="d2"]'))) return;
        }
        if (t.hasAttribute && t.hasAttribute('data-hubsame')) {   // 雙邊凸出：兩側相同／分左右側
          self.st.hub_same = !!t.checked;
          if (t.checked) { self.st.hub2 = ''; self.st.hub2_len = ''; self.st.hub_b = []; self.st.hub_tb = { tp: 0, d2: '', r: '', ha: '' }; }
          self.render();
          var nf = self.el.querySelector(t.checked ? 'input[data-hubsame]' : (self.st.hub_multi ? 'input[data-hubs="b"]' : 'input[data-hub2]')); if (nf) nf.focus();
          return;
        }
        if (t.hasAttribute && t.hasAttribute('data-hubmulti')) {   // 多階凸出：可只設定為多階、不輸入
          var S = self.st, has = function (a, b) { return String(a || '').trim() !== '' || String(b || '').trim() !== ''; };
          if (t.checked) {
            S.hub_multi = true;
            S.hub_a = has(S.hub, S.hub_len) ? [Object.assign({ d: S.hub, l: S.hub_len }, S.hub_ta && S.hub_ta.tp ? S.hub_ta : {})] : [];
            S.hub_b = (!S.hub_same && has(S.hub2, S.hub2_len)) ? [Object.assign({ d: S.hub2, l: S.hub2_len }, S.hub_tb && S.hub_tb.tp ? S.hub_tb : {})] : [];
          } else {
            S.hub_multi = false;
            var a0 = (S.hub_a || []).filter(function (g) { return has(g.d, g.l); })[0] || { d: '', l: '' };
            var b0 = (S.hub_b || []).filter(function (g) { return has(g.d, g.l); })[0] || { d: '', l: '' };
            S.hub = a0.d; S.hub_len = a0.l; S.hub2 = b0.d; S.hub2_len = b0.l; S.hub_a = []; S.hub_b = [];
            S.hub_ta = a0.tp ? { tp: 1, d2: a0.d2 || '', r: a0.r || '', ha: a0.ha || '' } : { tp: 0, d2: '', r: '', ha: '' };
            S.hub_tb = b0.tp ? { tp: 1, d2: b0.d2 || '', r: b0.r || '', ha: b0.ha || '' } : { tp: 0, d2: '', r: '', ha: '' };
          }
          self.render();
          var mf = self.el.querySelector(t.checked ? 'input[data-hubs="a"]' : 'input[data-hub]'); if (mf) mf.focus();
          return;
        }
        if (t.hasAttribute && t.hasAttribute('data-oi') && t.getAttribute('data-o') === 'otp' && (t.getAttribute('data-of') === 'd' || t.getAttribute('data-of') === 'd2')) {
          var oq = parseInt(t.getAttribute('data-oi'), 10), ob = self.st.otp[oq];
          if (ob) {
            var oa = num(ob.d), oc = num(ob.d2);
            if (oa && oc && oc > oa) {
              var ot = ob.d; ob.d = ob.d2; ob.d2 = ot;
              var oe1 = self.el.querySelector('input[data-o="otp"][data-oi="' + oq + '"][data-of="d"]'), oe2 = self.el.querySelector('input[data-o="otp"][data-oi="' + oq + '"][data-of="d2"]');
              if (oe1) oe1.value = ob.d; if (oe2) oe2.value = ob.d2;
              self._showJudge(); return;
            }
          }
        }
        // 錐孔：離開欄位時若「小徑」比「大徑」大，自動對調（使用者不必管哪個大哪個小）
        if (t.hasAttribute && t.hasAttribute('data-i') && (t.getAttribute('data-f') === 'd' || t.getAttribute('data-f') === 'd2')) {
          var k = parseInt(t.getAttribute('data-i'), 10), bb = self.st.bore[k];
          if (bb && bb.tp) {
            var a = num(bb.d), c = num(bb.d2);
            if (a && c && c > a) {
              var tmpv = bb.d; bb.d = bb.d2; bb.d2 = tmpv;
              var ie1 = self.el.querySelector('input[data-i="' + k + '"][data-f="d"]'), ie2 = self.el.querySelector('input[data-i="' + k + '"][data-f="d2"]');
              if (ie1) ie1.value = bb.d; if (ie2) ie2.value = bb.d2;
              self._showJudge(); return;
            }
          }
        }
        if (t.hasAttribute && t.hasAttribute('data-tp')) {
          var i = parseInt(t.getAttribute('data-tp'), 10);
          while (self.st.bore.length <= i) self.st.bore.push({ d: '', l: '' });
          self.st.bore[i].tp = t.checked ? 1 : 0;
          if (!t.checked) { self.st.bore[i].d2 = ''; self.st.bore[i].r = ''; self.st.bore[i].tl = ''; self.st.bore[i].ha = ''; }
          self.render();
          // 勾選後游標回到「大徑」（先填大徑，再填小徑）
          var f = self.el.querySelector('input[data-i="' + i + '"][data-f="d"]'); if (f) { f.focus(); if (f.select) f.select(); }
        }
      });
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
        var tpd = function () { return { tp: 0, d2: '', r: '', ha: '' }; };
        if (s.outer !== 'one_side' && s.outer !== 'both_sides') {
          s.hub = ''; s.hub_len = ''; s.hub2 = ''; s.hub2_len = ''; s.hub_same = true; s.hub_multi = false; s.hub_a = []; s.hub_b = []; s.hub_ta = tpd(); s.hub_tb = tpd();
          if (s.outer === 'flush') focusHub = true;   // 平板型：外徑可輸入
        } else {
          if (s.outer === 'one_side') { s.hub2 = ''; s.hub2_len = ''; s.hub_same = true; s.hub_b = []; s.hub_tb = tpd(); }
          focusHub = true;   // 單邊／雙邊凸出：游標進外徑欄
        }
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
      if (focusHub) { var hi = this.el.querySelector('input[data-hub]') || this.el.querySelector('input[data-hubs]'); if (hi) { hi.focus(); hi.select(); } }
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
      var self = this, ex = outerExtra(this.st);
      if (!v.msg && ex.msg && !ex.partial) box.textContent = ex.msg;
      this.el.querySelectorAll('input[data-oi]').forEach(function (inp) {
        inp.classList.toggle('bad', !!ex.bad[inp.getAttribute('data-o') + inp.getAttribute('data-oi') + inp.getAttribute('data-of')]);
      });
      this.el.querySelectorAll('[data-oinfo]').forEach(function (sp) { sp.textContent = taperInfo(Object.assign({ tp: 1 }, self.st.otp[parseInt(sp.getAttribute('data-oinfo'), 10)] || {})); });
      this.el.querySelectorAll('[data-tinfo]').forEach(function (sp) { sp.textContent = taperInfo(self.st.bore[parseInt(sp.getAttribute('data-tinfo'), 10)]); });
      var hb = this.el.querySelector('input[data-hub]'); if (hb) hb.classList.toggle('bad', !!v.bad['hub']);
      var hlq = this.el.querySelector('input[data-hublen]'); if (hlq) hlq.classList.toggle('bad', !!v.bad['hublen']);
      this.el.querySelectorAll('input[data-hubs]').forEach(function (inp) {
        inp.classList.toggle('bad', !!v.bad['hs' + inp.getAttribute('data-hubs') + inp.getAttribute('data-hi') + inp.getAttribute('data-hf')]);
      });
      this.el.querySelectorAll('input[data-hubtpf]').forEach(function (inp) {
        inp.classList.toggle('bad', !!v.bad['tp' + inp.getAttribute('data-hubtpf') + '_' + inp.getAttribute('data-hf')]);
      });
      this.el.querySelectorAll('[data-hubtpinfo]').forEach(function (sp) {
        var key = sp.getAttribute('data-hubtpinfo'), S = self.st, o = null;
        if (key === 'ta') o = { tp: 1, d: S.hub, d2: S.hub_ta.d2, tl: S.hub_len, r: S.hub_ta.r, ha: S.hub_ta.ha };
        else if (key === 'tb') o = { tp: 1, d: S.hub2, d2: S.hub_tb.d2, tl: S.hub2_len, r: S.hub_tb.r, ha: S.hub_tb.ha };
        else { var pp = key.split(':'), gg = (S['hub_' + pp[0]] || [])[parseInt(pp[1], 10)]; if (gg) o = { tp: 1, d: gg.d, d2: gg.d2, tl: gg.l, r: gg.r, ha: gg.ha }; }
        sp.textContent = o ? taperInfo(o) : '';
      });
      var h2q = this.el.querySelector('input[data-hub2]'); if (h2q) h2q.classList.toggle('bad', !!v.bad['hub2']);
      var h2l = this.el.querySelector('input[data-hub2len]'); if (h2l) h2l.classList.toggle('bad', !!v.bad['hub2len']);
    },
    render: function () {
      var defs = D(), s = this.st, h = '', blind = s.hole === 'blind';
      h += '<div class="eg-shape-line"><span class="eg-shape-lab" title="齒寬幾乎＝工件總長選「平板型」；一側有輪轂選「單邊凸出」；兩側都有選「雙邊凸出」；沒選＝其他。只要有凸出（凸出多少都算）就選單邊／雙邊凸出；完全沒有凸出才是平板型。再點一次已選的可取消。">外形</span>';
      Object.keys(defs.outer).forEach(function (c) { h += chipHtml('outer', c, defs.outer[c], s.outer === c); });
      h += chipHtml('outer', 'other', '其他', s.outer === '');
      h += '</div>';
      // 平板型：外徑可輸入。齒輪可省略（省略＝最大外徑與齒部外徑相同）；治具必填
      if (s.outer === 'flush') {
        var jigF = this.ptype === 'J';
        h += '<div class="eg-shape-line" style="margin-left:40px"><span class="eg-shape-hint" style="font-weight:bold;color:#5a3d1c;min-width:30px">平板</span>' +
          '<label class="eg-shape-in" title="' + (jigF ? '治具的外徑（必填）' : this.ptype === 'G' ? '最大外徑；省略時，最大外徑＝齒部外徑' : '外徑（選填）') + '">外徑 <input type="number" step="any" min="0" data-hubf="1" data-hub="1" value="' + esc(s.hub) + '" class="form-control input-sm no-spin-input"> mm</label>' +
          '<span class="eg-shape-hint">' + (jigF ? '治具請填外徑' : this.ptype === 'G' ? '可省略：省略表示最大外徑＝齒部外徑' : '外徑（選填）') + '</span></div>';
      }
      // 凸出（單邊／雙邊）：外徑、長度各自選填；雙邊凸出預設「兩側相同」，取消勾選後分左側、右側各填一組
      // 「多階凸出」：可只設定為多階而不輸入；連續輸入各階，最後一階有資料會自動多一格；各階依輸入順序（不自動排序）
      // 每一段（單階的左／右側、多階的每一階）都可勾「錐」：外徑填大徑，長度＝錐長，再填小徑／錐度／半錐角其中一個
      if (s.outer === 'one_side' || s.outer === 'both_sides') {
        var fm = this, both = s.outer === 'both_sides', two = both && !s.hub_same, multi = !!s.hub_multi;
        var hin = function (attr, val, lab, tip, calc) {
          return '<label class="eg-shape-in"' + (tip ? ' title="' + esc(tip) + '"' : '') + '>' + lab + ' <input ' +
            (calc ? 'type="text" inputmode="decimal" autocomplete="off" data-eg-calc="1" data-eg-hint="可輸入算式，例：48-18"' : 'type="number" step="any" min="0"') +
            ' data-hubf="1" ' + attr + '="1" value="' + esc(val) + '" class="form-control input-sm no-spin-input"> mm</label>';
        };
        var sideLab = function (t) { return '<span class="eg-shape-hint" style="font-weight:bold;color:#5a3d1c;min-width:30px">' + t + '</span>'; };
        var tpChk = function (side, on) {
          return '<label class="eg-shape-in" title="這一段凸出是錐面：外徑填大徑，長度＝錐長，再填小徑／錐度／半錐角其中一個"><input type="checkbox" data-hubtpc="' + side + '"' + (on ? ' checked' : '') + ' style="width:auto;height:auto"> 錐</label>';
        };
        var tpFlds = function (side, o) {
          var f = function (hf, lab, w) { return '<label class="eg-shape-in">' + lab + ' <input type="number" step="any" min="0" data-hubf="1" data-hubtpf="' + side + '" data-hf="' + hf + '"' + (w ? ' style="width:' + w + 'px"' : '') + ' value="' + esc(o[hf]) + '" class="form-control input-sm no-spin-input"></label>'; };
          return f('d2', '小徑') + '<span class="eg-shape-hint">或</span>' + f('r', '錐度 1：', 56) + '<span class="eg-shape-hint">或</span>' + f('ha', '半錐角 °', 56) +
            '<span class="eg-shape-hint" data-hubtpinfo="' + side + '" style="font-weight:bold;color:#5a3d1c"></span>';
        };
        var chkMulti = '<label class="eg-shape-in" title="凸出有好幾階時打勾（可以只設定為多階、先不輸入）；連續輸入各階的外徑與長度，依輸入順序排列、不自動排序"><input type="checkbox" data-hubmulti="1"' + (multi ? ' checked' : '') + ' style="width:auto;height:auto"> 多階凸出</label>';
        var chkSame = both ? '<label class="eg-shape-in" title="左右兩側的外徑與長度都相同時打勾；不同時取消勾選，分別填左側、右側"><input type="checkbox" data-hubsame="1"' + (s.hub_same ? ' checked' : '') + ' style="width:auto;height:auto"> 兩側相同</label>' : '';
        if (!multi) {
          var ta = s.hub_ta, tb = s.hub_tb;
          h += '<div class="eg-shape-line" style="margin-left:40px">' + sideLab(two ? '左側' : '凸出') +
            hin('data-hub', s.hub, ta.tp ? '大徑' : '外徑', '凸出部分的最大直徑') + hin('data-hublen', s.hub_len, '長度', '凸出部分的長度（軸向）', true) +
            tpChk('ta', ta.tp) + (ta.tp ? tpFlds('ta', ta) : '') + chkMulti + chkSame + '</div>';
          if (two) h += '<div class="eg-shape-line" style="margin-left:40px">' + sideLab('右側') +
            hin('data-hub2', s.hub2, tb.tp ? '大徑' : '外徑', '右側凸出部分的最大直徑') + hin('data-hub2len', s.hub2_len, '長度', '右側凸出部分的長度（軸向）', true) +
            tpChk('tb', tb.tp) + (tb.tp ? tpFlds('tb', tb) : '') + '</div>';
          h += '<div class="eg-shape-hint" style="margin-left:40px">只要有凸出就算（不論多少）；外徑與長度各自選填，只填長度也可以</div>';
        } else {
          fm._hubTail();
          var rowsHtml = function (sk) { var out = ''; s['hub_' + sk].forEach(function (g, i) { out += fm._hubRow(sk, i); }); return out; };
          h += '<div class="eg-shape-line" style="margin-left:40px">' + sideLab('凸出') + chkMulti + chkSame + '</div>';
          h += '<div class="eg-shape-line" data-hubrows="a" style="margin-left:40px">' + (two ? sideLab('左側') : '') + rowsHtml('a') + '</div>';
          if (two) h += '<div class="eg-shape-line" data-hubrows="b" style="margin-left:40px">' + sideLab('右側') + rowsHtml('b') + '</div>';
          h += '<div class="eg-shape-hint" style="margin-left:40px">連續輸入各階，最後一階有資料會自動多出一格；可以不輸入；各階依輸入順序，不自動排序</div>';
        }
      }

      // 外錐（可多筆）／重點外徑段（可多筆，只記想記的幾段）—— 皆選填
      var oin = function (o, i, f, lab, w, calc) {
        return '<label class="eg-shape-in">' + lab + ' <input ' + (calc ? 'type="text" inputmode="decimal" autocomplete="off" data-eg-calc="1" ' : 'type="number" step="any" min="0" ') + 'class="form-control input-sm no-spin-input" data-o="' + o + '" data-oi="' + i + '" data-of="' + f + '"' + (w ? ' style="width:' + w + 'px"' : '') + ' value="' + esc((s[o][i] || {})[f] || '') + '"></label>';
      };
      h += '<div class="eg-shape-line"><span class="eg-shape-lab" title="選填。外圓錐面（如軸端錐度）可新增多筆；大小徑任意填、系統自動判斷，填「小徑＋錐長」或「錐度」或「半錐角」即自動算出其餘">外錐</span>' +
        '<button type="button" class="btn btn-xs btn-default" data-oadd="otp">＋ 外錐</button>' + (s.otp.length ? '' : '<span class="eg-shape-hint">沒有外錐就不用填</span>') + '</div>';
      s.otp.forEach(function (o, i) {
        h += '<div class="eg-shape-line" style="margin-left:40px">' + oin('otp', i, 'd', '大徑') + oin('otp', i, 'd2', '小徑') + oin('otp', i, 'tl', '錐長', 60, true) +
          '<span class="eg-shape-hint">或</span>' + oin('otp', i, 'r', '錐度 1：', 56) + '<span class="eg-shape-hint">或</span>' + oin('otp', i, 'ha', '半錐角 °', 56) +
          '<a href="javascript:;" data-odel="otp:' + i + '" title="刪除這筆外錐" style="color:#DD5138">✕</a>' +
          '<span class="eg-shape-hint" data-oinfo="' + i + '" style="font-weight:bold;color:#5a3d1c;flex-basis:100%"></span></div>';
      });
      h += '<div class="eg-shape-line"><span class="eg-shape-lab" title="選填。軸件有很多段時，只記想記的幾段（如最大外徑、軸頸），不必全部填，也不檢查有沒有漏段">外徑段</span>' +
        '<button type="button" class="btn btn-xs btn-default" data-oadd="seg">＋ 重點外徑段</button>' + (s.seg.length ? '' : '<span class="eg-shape-hint">軸件只記重點幾段即可，不用全部設定</span>') + '</div>';
      s.seg.forEach(function (g, i) {
        h += '<div class="eg-shape-line" style="margin-left:40px">' + oin('seg', i, 'd', '外徑') + oin('seg', i, 'l', '長度', 60, true) +
          '<label class="eg-shape-in">名稱 <input type="text" maxlength="30" autocomplete="off" class="form-control input-sm" style="width:110px" data-o="seg" data-oi="' + i + '" data-of="n" data-eg-hint="選填，例：軸頸、最大外徑" value="' + esc(g.n || '') + '"></label>' +
          '<a href="javascript:;" data-odel="seg:' + i + '" title="刪除這一段" style="color:#DD5138">✕</a></div>';
      });

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
            '<span class="eg-shape-hint">輸入後按 Enter，自動開始輸入孔徑；順序不拘，系統自動由大到小排列</span>';
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
    // 多階凸出的一階（外徑＋長度）；各階依輸入順序，不自動排序
    _hubRow: function (sk, i) {
      var g = (this.st['hub_' + sk] || [])[i] || {}, tp = !!g.tp;
      var inp = function (f, lab, calc, w) {
        return '<label class="eg-shape-in">' + lab + ' <input ' + (calc ? 'type="text" inputmode="decimal" autocomplete="off" data-eg-calc="1" data-eg-hint="可輸入算式，例：48-18"' : 'type="number" step="any" min="0"') +
          ' data-hubf="1" data-hubs="' + sk + '" data-hi="' + i + '" data-hf="' + f + '"' + (w ? ' style="width:' + w + 'px"' : '') + ' value="' + esc(g[f] || '') + '" class="form-control input-sm no-spin-input"></label>';
      };
      var h = '<span style="display:inline-flex;flex-wrap:wrap;align-items:center;gap:4px;margin-right:10px' + (tp ? ';flex-basis:100%' : '') + '"><span class="eg-shape-hint">第' + (i + 1) + '階</span>' +
        inp('d', tp ? '大徑' : '外徑') + inp('l', '長度', true) +
        '<label class="eg-shape-in" title="這一階是錐面：外徑填大徑，長度＝錐長，再填小徑／錐度／半錐角其中一個"><input type="checkbox" data-hubtp="' + sk + ':' + i + '"' + (tp ? ' checked' : '') + ' style="width:auto;height:auto"> 錐</label>';
      if (tp) h += inp('d2', '小徑') + '<span class="eg-shape-hint">或</span>' + inp('r', '錐度 1：', false, 56) + '<span class="eg-shape-hint">或</span>' + inp('ha', '半錐角 °', false, 56) +
        '<span class="eg-shape-hint" data-hubtpinfo="' + sk + ':' + i + '" style="font-weight:bold;color:#5a3d1c"></span>';
      return h + '</span>';
    },
    // 保證每一側結尾有一格空白（最後一階有資料才會自動多出下一格）
    _hubTail: function () {
      var s = this.st, two = s.outer === 'both_sides' && !s.hub_same;
      var has = function (g) { return String(g.d || '').trim() !== '' || String(g.l || '').trim() !== ''; };
      (two ? ['a', 'b'] : ['a']).forEach(function (k) { var a = s['hub_' + k]; if (!a.length || has(a[a.length - 1])) a.push({ d: '', l: '' }); });
    },
    _in: function (i, label, f) {
      var b = this.st.bore[i] || {}, v = b[f] || '', tp = !!b.tp;
      var one = function (ff, lab, w) {
        var calc = ff === 'tl';   // 錐長可輸入算式（48-18 按 Enter＝30，由共用檔 eg_input_rules.js 規則 9 處理）
        return '<label class="eg-shape-in">' + lab + ' <input ' + (calc ? 'type="text" inputmode="decimal" autocomplete="off" data-eg-calc="1" data-eg-hint="可輸入算式，例：48-18" ' : 'type="number" step="any" min="0" ') + 'class="form-control input-sm no-spin-input" data-i="' + i + '" data-f="' + ff + '"' + (w ? ' style="width:' + w + 'px"' : '') + ' value="' + esc(b[ff] || '') + '"></label>';
      };
      var h = '<label class="eg-shape-in">' + esc(tp ? label.replace(/孔徑$/, '大徑') : label) + ' <input type="number" step="any" min="0" class="form-control input-sm no-spin-input" data-i="' + i + '" data-f="' + f + '" value="' + esc(v) + '"></label>';
      h += '<label class="eg-shape-in" title="此孔為錐孔：輸入大徑，再填小徑或錐度（1:N）其中一個"><input type="checkbox" data-tp="' + i + '"' + (tp ? ' checked' : '') + ' style="width:auto;height:auto"> 錐孔</label>';
      if (tp) {
        h += one('d2', '小徑') + one('tl', '錐長', 60) + '<span class="eg-shape-hint">或</span>' + one('r', '錐度 1：', 56) + '<span class="eg-shape-hint">或</span>' + one('ha', '半錐角 °', 56) +
          '<span class="eg-shape-hint" data-tinfo="' + i + '" style="font-weight:bold;color:#5a3d1c">' + esc(taperInfo(b)) + '</span>' +
          '<span class="eg-shape-hint" style="flex-basis:100%">兩端直徑任意填，系統自動分大徑／小徑；填「小徑＋錐長」或「錐度」即自動算出錐度與半錐角</span>';
      }
      return h;
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
        if (c === 'has_taper' || c === 'has_otp') self._panels(true);
        self._fire();
      });
      el.addEventListener('input', function (ev) { if (ev.target.matches('input')) self._fire(); });
    },
    _fire: function () { var self = this; clearTimeout(this._t); this._t = setTimeout(function () { if (self.onChange) self.onChange(); }, 350); },
    render: function () {
      var defs = D(), h = '', self = this;
      var line = '<div style="display:flex;flex-wrap:wrap;align-items:center;gap:4px;margin:3px 0;width:100%">';
      var ins = 'class="form-control input-sm no-spin-input" style="width:64px;display:inline-block;height:24px;padding:2px 6px;font-size:12px"';
      var sepx = '<span style="color:#888;margin:0 2px;">~</span>';
      var st = 'stroke="' + LINE + '" stroke-width="1.2"';
      // 第 1 行：外形
      h += line + '<span class="filter-group-label">外形</span>';
      Object.keys(defs.outer).forEach(function (c) { h += chipHtml('outer', c, defs.outer[c], self.outer.indexOf(c) >= 0); });
      h += chipHtml('outer', 'other', '其他', self.outer.indexOf('other') >= 0) +
        '<span class="eg-shape-sep">│</span>' +
        flagChip('outer', 'has_otp', '有外錐', self.outer.indexOf('has_otp') >= 0, '<polygon points="8,16 48,10 48,30 8,24" fill="' + BODY + '" ' + st + '/>') +
        flagChip('outer', 'has_seg', '有外徑段', self.outer.indexOf('has_seg') >= 0, rect(6, 14, 14, 12, BODY, st) + rect(20, 8, 18, 24, BODY, st) + rect(38, 14, 12, 12, BODY, st)) + '</div>';
      // 第 2 行：內孔（獨立一行）
      h += line + '<span class="filter-group-label">內孔</span>';
      Object.keys(defs.bore).forEach(function (c) { h += chipHtml('bore', c, defs.bore[c].name, self.bore.indexOf(c) >= 0); });
      h += chipHtml('bore', 'none', '無孔', self.bore.indexOf('none') >= 0) +
        '<span class="eg-shape-sep">│</span>' +
        flagChip('bore', 'has_taper', '含錐孔', self.bore.indexOf('has_taper') >= 0, rect(6, 4, 44, 32, BODY, st) + '<polygon points="6,11 50,16 50,24 6,29" fill="#fff" ' + st + '/>') + '</div>';
      // 錐度數值篩選：點選「含錐孔」／「有外錐」才展開；同類（大徑、小徑）排同一行，不同類（錐度、半錐角）自動換行
      var rg = function (g, k, lab, unit, tip) {
        return '<span class="filter-group-label" title="' + esc(tip || '') + '">' + lab + '</span>' +
          '<input type="number" step="any" data-tp="' + g + '.' + k + '.0" ' + ins + ' data-eg-hint="最小">' + sepx +
          '<input type="number" step="any" data-tp="' + g + '.' + k + '.1" ' + ins + ' data-eg-hint="最大">' +
          (unit ? '<span style="font-size:11px;color:#888;margin:0 10px 0 2px;">' + unit + '</span>' : '');
      };
      [['b', '錐孔'], ['o', '外錐']].forEach(function (g) {
        h += '<div data-tpanel="' + g[0] + '" style="display:none;border-left:3px solid #F0A24B;padding-left:8px;margin:3px 0 3px 6px">' +
          line + '<span class="filter-group-label" style="font-weight:bold">' + g[1] + '尺寸</span>' + rg(g[0], 'd', '大徑', 'mm') + rg(g[0], 's', '小徑', 'mm') + '</div>' +
          line + '<span class="filter-group-label" style="font-weight:bold">' + g[1] + '角度</span>' +
          rg(g[0], 'n', '錐度 1：', '', '例：填 10~10 即篩出 1:10（含系統由小徑＋錐長自動算出的）') + rg(g[0], 'h', '半錐角', '°', '含系統自動算出的半錐角') + '</div></div>';
      });
      // 第 3 行：凸出外徑／孔徑範圍
      h += line + '<span class="filter-group-label" title="單邊／雙邊凸出的最大凸出外徑">凸出外徑</span>' +
        '<input type="number" step="any" data-sh="min" placeholder="最小" ' + ins + '>' + sepx +
        '<input type="number" step="any" data-sh="max" placeholder="最大" ' + ins + '>' +
        '<span style="font-size:11px;color:#888;margin:0 12px 0 3px;">mm</span>' +
        '<span class="filter-group-label" title="凸出部分的長度；雙邊凸出任一側符合即命中（只填長度沒填外徑的料號也找得到）">凸出長度</span>' +
        '<input type="number" step="any" data-tp="hl.l.0" placeholder="最小" ' + ins + '>' + sepx +
        '<input type="number" step="any" data-tp="hl.l.1" placeholder="最大" ' + ins + '>' +
        '<span style="font-size:11px;color:#888;margin:0 12px 0 3px;">mm</span>' +
        '<span class="filter-group-label" title="只要這個料號有任何一階孔徑落在範圍內就會出現">孔徑</span>' +
        '<input type="number" step="any" data-sb="min" placeholder="最小" ' + ins + '>' + sepx +
        '<input type="number" step="any" data-sb="max" placeholder="最大" ' + ins + '>' +
        '<span style="font-size:11px;color:#888;margin-left:3px;">mm（多階層：任一階落在範圍內即符合）</span></div>';
      this.el.innerHTML = h;
      this._panels(false);
    },
    // 依「含錐孔／有外錐」是否選取顯示或收起錐度面板；收起時清掉裡面填的值（免得看不到卻還在篩）
    _panels: function (clear) {
      var self = this;
      [['b', this.bore, 'has_taper'], ['o', this.outer, 'has_otp']].forEach(function (g) {
        var pn = self.el && self.el.querySelector('[data-tpanel="' + g[0] + '"]'); if (!pn) return;
        var on = g[1].indexOf(g[2]) >= 0;
        pn.style.display = on ? 'block' : 'none';
        if (!on && clear) pn.querySelectorAll('input').forEach(function (i) { i.value = ''; });
      });
    },
    _tp: function () {
      var o = {}, any = false, self = this;
      if (!this.el) return '';
      this.el.querySelectorAll('input[data-tp]').forEach(function (inp) {
        var v = inp.value.trim(); if (v === '') return;
        var p = inp.getAttribute('data-tp').split('.');
        if (p[0] === 'b' && self.bore.indexOf('has_taper') < 0) return;
        if (p[0] === 'o' && self.outer.indexOf('has_otp') < 0) return;
        o[p[0]] = o[p[0]] || {}; o[p[0]][p[1]] = o[p[0]][p[1]] || [null, null]; o[p[0]][p[1]][parseInt(p[2], 10)] = v; any = true;
      });
      return any ? JSON.stringify(o) : '';
    },
    _v: function (k) { var i = this.el && this.el.querySelector('input[data-sb="' + k + '"]'); return i ? i.value.trim() : ''; },
    _h: function (k) { var i = this.el && this.el.querySelector('input[data-sh="' + k + '"]'); return i ? i.value.trim() : ''; },
    params: function () {
      return { filter_shape_outer: this.outer.join(','), filter_shape_bore: this.bore.join(','), filter_shape_bore_min: this._v('min'), filter_shape_bore_max: this._v('max'), filter_shape_hub_min: this._h('min'), filter_shape_hub_max: this._h('max'), filter_shape_tp: this._tp() };
    },
    active: function () { return !!(this.outer.length || this.bore.length || this._v('min') !== '' || this._v('max') !== '' || this._h('min') !== '' || this._h('max') !== '' || this._tp() !== ''); },
    reset: function () { this.outer = []; this.bore = []; if (this.el) this.render(); }
  };

  global.EGPartShape = { form: form, filter: filter, icon: icon };
})(window);
