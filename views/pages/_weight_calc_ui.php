<?php
/**
 * 料號重量「自動計算」跳窗（主檔管理 料號編輯畫面用）— CSS＋HTML＋JS 全在這一檔，由 master_data_management.php include。
 * 資料來源：外形／內孔 = EGPartShape.form.get()（畫面上目前填的，不必先存檔）；材質 = #pf-labels-json；
 * 密度表與齒輪預設外徑／齒寬 = 後端 weight_calc_ctx（src/common/part_weight_lib.php），與 KPI 頁脫鉤。
 * 這是自刻的浮層（不是 Bootstrap modal）：料號編輯本身就是 Bootstrap modal，疊第二層 modal 關閉後底層會捲不動。
 */
?>
<style>
#wc-mask{display:none;position:fixed;inset:0;background:rgba(60,40,15,.45);z-index:2000;overflow:auto;}
#wc-box{width:900px;max-width:calc(100% - 24px);margin:24px auto;background:#FFFCF7;border:1px solid #EADFCD;border-radius:8px;box-shadow:0 8px 30px rgba(60,40,15,.3);color:#4a3418;}
#wc-box .wc-h{display:flex;align-items:center;gap:10px;padding:10px 16px;background:#C77C1A;color:#fff;border-radius:8px 8px 0 0;font-size:15px;font-weight:700;}
#wc-box .wc-h .wc-x{margin-left:auto;cursor:pointer;font-size:20px;line-height:1;opacity:.9;}
#wc-box .wc-b{padding:12px 16px;}
#wc-box .wc-sec{font-size:12px;font-weight:700;color:#8a5a14;margin:10px 0 4px;padding-bottom:3px;border-bottom:1px dashed #EADFCD;}
#wc-box .wc-note{font-size:12px;color:#8a6a45;margin:2px 0 6px;}
#wc-box table.wc-t{width:100%;border-collapse:collapse;font-size:12px;}
#wc-box table.wc-t th{background:#F7E0BD;color:#6B4423;font-weight:700;padding:4px 6px;text-align:left;white-space:nowrap;}
#wc-box table.wc-t td{padding:3px 4px;border-bottom:1px solid #F0E4D0;vertical-align:middle;}
#wc-box .wc-in{width:78px;height:26px;padding:2px 6px;border:1px solid #D9C7A8;border-radius:3px;background:#fff;font-size:12px;}
#wc-box .wc-nm{width:130px;height:26px;padding:2px 6px;border:1px solid #D9C7A8;border-radius:3px;font-size:12px;}
#wc-box .wc-in.wc-miss{border-color:#DD5138;background:#FFF1EC;}
#wc-box .wc-in.wc-auto{background:#F5EFE3;color:#8a6a45;}
#wc-box .wc-out{color:#2e7d32;font-weight:700;} #wc-box .wc-bore{color:#c0392b;font-weight:700;}
#wc-box .wc-vol{text-align:right;white-space:nowrap;color:#6B4423;min-width:70px;}
#wc-box .wc-res{display:flex;gap:18px;align-items:flex-end;flex-wrap:wrap;padding:8px 12px;background:#FFF3DF;border:1px solid #EACB97;border-radius:6px;margin-top:8px;}
#wc-box .wc-res .wc-big{font-size:26px;font-weight:800;color:#8a4b00;line-height:1.1;}
#wc-box .wc-res small{display:block;color:#8a6a45;font-size:11px;}
#wc-box .wc-err{color:#DD5138;font-size:12px;margin-top:6px;}
#wc-box .wc-f{display:flex;gap:8px;justify-content:flex-end;padding:10px 16px;border-top:1px solid #EADFCD;}
#wc-box select.wc-sel{height:28px;padding:2px 6px;border:1px solid #D9C7A8;border-radius:3px;font-size:12px;background:#fff;}
#wc-box .wc-btn{border:1px solid #D9C7A8;background:#fff;color:#6B4423;border-radius:4px;padding:3px 10px;font-size:12px;cursor:pointer;}
#wc-box .wc-btn.wc-pri{background:#C77C1A;border-color:#C77C1A;color:#fff;font-weight:700;}
#wc-box .wc-btn[disabled]{opacity:.45;cursor:not-allowed;}
#wc-box .wc-formula{font-size:11px;color:#8a6a45;background:#F8F1E4;border-radius:4px;padding:5px 8px;line-height:1.6;}
</style>
<div id="wc-mask">
  <div id="wc-box">
    <div class="wc-h"><i class="fa fa-calculator"></i> 重量自動計算 <span id="wc-title-part" style="font-weight:400;font-size:12px;opacity:.9;"></span><span class="wc-x" onclick="closeWeightCalc()" title="關閉">&times;</span></div>
    <div class="wc-b" id="wc-body"><div class="wc-note">載入中…</div></div>
    <div class="wc-f">
      <button type="button" class="wc-btn" onclick="closeWeightCalc()">取消</button>
      <button type="button" class="wc-btn wc-pri" id="wc-apply" onclick="applyWeightCalc()" disabled><i class="fa fa-check"></i> 帶入重量欄位</button>
    </div>
  </div>
</div>
<script>
(function () {
  var WC = { ctx: null, rows: [], seq: 0, denSel: '', denCustom: '', denOpen: false, denRows: [] };
  var PI = Math.PI;

  function pn(v) { if (v == null) return null; v = String(v).trim(); if (v === '' || isNaN(Number(v)) || Number(v) <= 0) return null; return Number(v); }
  function r3(v) { return Math.round(v * 1000) / 1000; }
  function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
  function nv(v) { return v == null ? '' : String(v); }

  // 錐的小徑：直接填小徑，或由錐度 1:N／半錐角＋長度推出
  function coneSmall(D, o, L) {
    var d2 = pn(o && o.d2); if (d2) return d2;
    var r = pn(o && o.r), ha = pn(o && o.ha);
    if (!r && ha && ha < 90) r = 1 / (2 * Math.tan(ha * PI / 180));
    if (r && L && D) { var s = D - L / r; return s > 0 ? r3(s) : null; }
    return null;
  }
  function mk(kind, name, D, L, extra) {
    var o = { id: ++WC.seq, kind: kind, name: name, shape: 'cyl', D: nv(D), D2: '', L: nv(L), auto: '' };
    if (extra) for (var k in extra) o[k] = extra[k];
    return o;
  }

  // ── 由畫面上目前的外形／內孔／材質組出預設列（缺的欄位留空，使用者在跳窗內補） ──
  function buildRows() {
    var s = (window.EGPartShape && EGPartShape.form) ? EGPartShape.form.get() : {};
    var gear = WC.ctx.gear || {};
    var rows = [], notes = [];
    var maxOd = pn(s.max_od) || pn(gear.da) || null;
    var wl = pn((document.getElementById('pf-workpiece_length') || {}).value);
    var maxLen = pn(s.max_len) || wl || null;
    var hasHub = s.outer === 'one_side' || s.outer === 'both_sides';
    var hubA = [], hubB = [];
    var hubStep = function (g, name) { var t = !!g.tp; return { name: name, D: pn(g.d), L: pn(g.l), tp: t, o: g }; };
    if (hasHub) {
      if (s.hub_multi) {
        (s.hub_a || []).forEach(function (g, i) { hubA.push(hubStep(g, '凸出' + (s.outer === 'both_sides' ? ' 左側第' : ' 第') + (i + 1) + '階')); });
        (s.hub_b || []).forEach(function (g, i) { hubB.push(hubStep(g, '凸出 右側第' + (i + 1) + '階')); });
        if (s.outer === 'both_sides' && !hubB.length) hubA.forEach(function (h, i) { hubB.push({ name: '凸出 右側第' + (i + 1) + '階', D: h.D, L: h.L, tp: h.tp, o: h.o }); });
      } else {
        var one = function (dia, len, ta, name) { return { name: name, D: pn(dia), L: pn(len), tp: !!(ta && ta.tp), o: ta || {} }; };
        hubA.push(one(s.hub_dia, s.hub_len, s.hub_ta, s.outer === 'both_sides' ? '凸出 左側' : '凸出'));
        if (s.outer === 'both_sides') {
          if (pn(s.hub2_dia) || pn(s.hub2_len) || s.hub_tb) hubB.push(one(s.hub2_dia, s.hub2_len, s.hub_tb, '凸出 右側'));
          else hubB.push(one(s.hub_dia, s.hub_len, s.hub_ta, '凸出 右側（同左側）'));
        }
      }
    }
    var hubLen = 0; hubA.concat(hubB).forEach(function (h) { hubLen += (h.L || 0); });
    var bodyL = null;
    if (maxLen) bodyL = r3(maxLen - hubLen); else if (pn(gear.fw)) bodyL = pn(gear.fw);
    if (bodyL !== null && bodyL <= 0) bodyL = null;
    rows.push(mk('out', hasHub ? '本體（齒部／主體）' : '本體', maxOd ? r3(maxOd) : '', bodyL !== null ? r3(bodyL) : ''));
    hubA.concat(hubB).forEach(function (h) {
      var r = mk('out', h.name, h.D || '', h.L || '');
      if (h.tp) { r.shape = 'cone'; var sm = coneSmall(h.D, h.o, h.L); r.D2 = sm ? String(sm) : ''; }
      rows.push(r);
    });
    if ((s.seg && s.seg.length) || (s.otp && s.otp.length)) notes.push('「重點外徑段」與「外錐」只是記錄用，沒有自動納入計算；需要的話請用下方「＋新增外型段」補一列。');

    // 內孔
    var bores = s.bore || [];
    if (s.mode === 'single' && bores[0]) {
      var b0 = bores[0], rb = mk('bore', '內孔', pn(b0.d) || '', pn(b0.l) || '');
      if (!s.blind) rb.auto = 'T';
      if (b0.tp) { rb.shape = 'cone'; var s0 = coneSmall(pn(b0.d), b0, pn(b0.l)); rb.D2 = s0 ? String(s0) : ''; }
      rows.push(rb);
    } else if (s.mode === 'steps') {
      bores.forEach(function (b, i) {
        var rb2 = mk('bore', '第' + (i + 1) + '階孔（由大到小）', pn(b.d) || '', pn(b.l) || '');
        if (!s.blind) rb2.auto = 'chain';
        if (b.tp) { rb2.shape = 'cone'; var s1 = coneSmall(pn(b.d), b, pn(b.l)); rb2.D2 = s1 ? String(s1) : ''; }
        rows.push(rb2);
      });
    } else if (s.mode === 'ends' && bores.length >= 2) {
      var ed = pn(bores[0].d), md = pn(bores[1].d), el = pn(bores[0].l) || '';
      rows.push(mk('bore', '左端孔', ed || '', el, { auto: 'chain' }));
      rows.push(mk('bore', '右端孔', ed || '', el, { auto: 'chain' }));
      rows.push(mk('bore', '中間孔', md || '', pn(bores[1].l) || '', { auto: 'chain' }));
    }
    WC.notes = notes;
    return rows;
  }

  // ── 計算 ──
  function calc() {
    var out = { vOut: 0, vBore: 0, miss: [], T: 0, per: {} };
    var outs = WC.rows.filter(function (r) { return r.kind === 'out'; });
    var T = 0; outs.forEach(function (r) { T += (pn(r.L) || 0); });
    out.T = r3(T);
    // 貫通孔分段：只有一格沒填時，自動 = 總長 − 其他
    var chain = WC.rows.filter(function (r) { return r.kind === 'bore' && r.auto === 'chain'; });
    var chainBlank = chain.filter(function (r) { return !pn(r.L); });
    var chainSum = 0; chain.forEach(function (r) { chainSum += (pn(r.L) || 0); });
    WC.rows.forEach(function (r) {
      var D = pn(r.D), L = pn(r.L), autoNote = '';
      if (r.kind === 'bore' && !L) {
        if (r.auto === 'T' && T > 0) { L = T; autoNote = '貫通＝總長'; }
        else if (r.auto === 'chain' && chainBlank.length === 1 && chainBlank[0] === r && T > 0 && T - chainSum > 0) { L = r3(T - chainSum); autoNote = '＝總長−其他孔'; }
      }
      r._autoL = autoNote ? L : null; r._autoNote = autoNote;
      var D2 = r.shape === 'cone' ? pn(r.D2) : null;
      var miss = [];
      if (!D) miss.push('外徑'); if (!L) miss.push(r.kind === 'out' ? '長度' : '深度');
      if (r.shape === 'cone' && !D2) miss.push('小徑');
      if (miss.length) { out.miss.push((r.name || (r.kind === 'out' ? '外型' : '內孔')) + '：' + miss.join('、')); out.per[r.id] = null; return; }
      var v = r.shape === 'cone' ? PI / 12 * L * (D * D + D * D2 + D2 * D2) : PI / 4 * D * D * L;
      out.per[r.id] = v;
      if (r.kind === 'out') out.vOut += v; else out.vBore += v;
    });
    return out;
  }
  function density() {
    var sel = WC.denSel;
    if (sel === 'custom') return pn(WC.denCustom);
    var m = /^sub:(\d+)$/.exec(sel || ''); if (!m) return null;
    var hit = (WC.ctx.densities || []).filter(function (d) { return String(d.sub_id) === m[1]; })[0];
    return hit ? hit.density_g : null;
  }

  function partMaterials() {   // 這支料號目前勾的材質子標籤（畫面上的，不必先存檔）
    var ids = [], names = [];
    try {
      var arr = JSON.parse((document.getElementById('pf-labels-json') || {}).value || '[]');
      arr.forEach(function (it) {
        if (String(it.label_id) !== String(WC.ctx.material_label_id)) return;
        (it.sub_labels || []).forEach(function (sl) {
          ids.push(String(sl.sub_id));
          var sn = (WC.ctx.subs || []).filter(function (s) { return String(s.sub_id) === String(sl.sub_id); })[0];
          names.push((sn ? sn.sub_name : '#' + sl.sub_id) + (sl.input_value ? ' ' + sl.input_value : ''));
        });
        if (it.input_value) names.push(it.input_value);
      });
    } catch (e) {}
    return { ids: ids, names: names };
  }

  // ── 畫面 ──
  function rowHtml(r) {
    var isOut = r.kind === 'out';
    var cone = r.shape === 'cone';
    var lph = r._autoNote ? ' placeholder="' + esc(String(r._autoL)) + '"' : '';
    return '<tr data-rid="' + r.id + '">'
      + '<td><span class="' + (isOut ? 'wc-out' : 'wc-bore') + '">' + (isOut ? '＋外型' : '－內孔') + '</span></td>'
      + '<td><input class="wc-nm" data-f="name" value="' + esc(r.name) + '"></td>'
      + '<td><select class="wc-sel" data-f="shape"><option value="cyl"' + (cone ? '' : ' selected') + '>圓柱</option><option value="cone"' + (cone ? ' selected' : '') + '>錐（圓台）</option></select></td>'
      + '<td><input type="number" step="any" min="0" class="wc-in no-spin-input" data-f="D" value="' + esc(r.D) + '"></td>'
      + '<td><input type="number" step="any" min="0" class="wc-in no-spin-input" data-f="D2" value="' + esc(r.D2) + '"' + (cone ? '' : ' disabled style="visibility:hidden"') + '></td>'
      + '<td><input type="number" step="any" min="0" class="wc-in no-spin-input' + (r._autoNote ? ' wc-auto' : '') + '" data-f="L" value="' + esc(r.L) + '"' + lph + '>'
      + '<div style="font-size:10px;color:#8a6a45;" data-note="' + r.id + '">' + esc(r._autoNote || '') + '</div></td>'
      + '<td class="wc-vol" data-vol="' + r.id + '"></td>'
      + '<td><span class="wc-btn" data-del="' + r.id + '" title="刪除這一列" style="padding:1px 7px;">×</span></td></tr>';
  }
  function tableHtml(kind) {
    var rs = WC.rows.filter(function (r) { return r.kind === kind; });
    var h = '<table class="wc-t"><thead><tr><th></th><th>名稱</th><th>形狀</th><th>' + (kind === 'out' ? '外徑 Ø' : '孔徑 Ø（錐填大徑）') + '</th><th>小徑 Ø</th><th>' + (kind === 'out' ? '長度' : '深度') + '</th><th style="text-align:right">體積 cm³</th><th></th></tr></thead><tbody>';
    h += rs.map(rowHtml).join('');
    if (!rs.length) h += '<tr><td colspan="8" class="wc-note">' + (kind === 'out' ? '尚無外型段，請按下方「新增外型段」。' : '沒有內孔（實心軸件）；有孔請按「新增內孔」。') + '</td></tr>';
    return h + '</tbody></table>';
  }
  function densityHtml() {
    var ds = WC.ctx.densities || [], mat = partMaterials();
    var h = '<div class="wc-sec">② 材質密度</div>';
    h += '<div class="wc-note">這支料號的材質：<b>' + (mat.names.length ? esc(mat.names.join('、')) : '尚未勾選材質標籤') + '</b>' + (mat.ids.length ? '' : '（請在下方「標籤」選材質，或直接在這裡選密度）') + '</div>';
    h += '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;"><select class="wc-sel" id="wc-den-sel"><option value="">請選擇材質密度…</option>';
    ds.forEach(function (d) { h += '<option value="sub:' + d.sub_id + '"' + (WC.denSel === 'sub:' + d.sub_id ? ' selected' : '') + '>' + esc(d.sub_name || ('#' + d.sub_id)) + '　' + d.density_g + ' g/cm³</option>'; });
    h += '<option value="custom"' + (WC.denSel === 'custom' ? ' selected' : '') + '>自訂密度…</option></select>';
    h += '<input type="number" step="any" min="0" class="wc-in no-spin-input" id="wc-den-custom" placeholder="g/cm³" value="' + esc(WC.denCustom) + '" style="' + (WC.denSel === 'custom' ? '' : 'display:none;') + '">';
    h += '<span class="wc-btn" id="wc-den-toggle"><i class="fa fa-table"></i> 密度對照表' + (WC.ctx.can_edit ? '（可編輯）' : '') + '</span></div>';
    if (WC.denOpen) {
      h += '<div style="margin-top:6px;padding:6px 8px;background:#F8F1E4;border-radius:4px;">';
      h += '<table class="wc-t" style="max-width:520px"><thead><tr><th>材質（材質標籤的子標籤）</th><th>密度 g/cm³</th><th></th></tr></thead><tbody>';
      WC.denRows.forEach(function (d, i) {
        h += '<tr data-di="' + i + '"><td>';
        if (WC.ctx.can_edit) {
          h += '<select class="wc-sel" data-df="sub_id" style="min-width:170px"><option value="">請選擇…</option>' + (WC.ctx.subs || []).map(function (s) { return '<option value="' + s.sub_id + '"' + (String(s.sub_id) === String(d.sub_id) ? ' selected' : '') + '>' + esc(s.sub_name) + '</option>'; }).join('') + '</select>';
        } else h += esc(d.sub_name || ('#' + d.sub_id));
        h += '</td><td>' + (WC.ctx.can_edit ? '<input type="number" step="any" min="0" class="wc-in no-spin-input" data-df="density_g" value="' + esc(d.density_g) + '">' : esc(d.density_g)) + '</td>';
        h += '<td>' + (WC.ctx.can_edit ? '<span class="wc-btn" data-ddel="' + i + '" style="padding:1px 7px;">×</span>' : '') + '</td></tr>';
      });
      h += '</tbody></table>';
      if (WC.ctx.can_edit) h += '<div style="margin-top:6px;"><span class="wc-btn" id="wc-den-add">＋ 新增一列</span> <span class="wc-btn wc-pri" id="wc-den-save">儲存密度表</span> <span class="wc-note">儲存後全站共用；與 KPI 頁的密度表各自獨立。</span></div>';
      else h += '<div class="wc-note">要修改密度表需要「類別字典（標籤）」編輯權限。</div>';
      h += '</div>';
    }
    return h;
  }
  function render() {
    var h = '';
    h += '<div class="wc-formula">圓柱 W(g) = π/4 × Ø²(mm²) × 長度(mm) × 密度(g/cm³) ÷ 1000　｜　錐（圓台）體積 = π/12 × 長度 × (Ø² + Ø×小徑 + 小徑²)<br>重量 ＝ 全部<span class="wc-out">外型</span>體積 − 全部<span class="wc-bore">內孔</span>體積，再乘以密度。尺寸已依畫面上目前填的外形／內孔帶入，<b>紅框的格子請補填</b>；這裡改的數字只用來計算，不會改動料號的外形與內孔資料。</div>';
    (WC.notes || []).forEach(function (n) { h += '<div class="wc-note" style="color:#b0561a;">※ ' + esc(n) + '</div>'; });
    h += '<div class="wc-sec">① 外型與內孔尺寸（mm）　<span class="wc-note">總長 <b id="wc-T">0</b> mm（外型長度加總；貫通孔深度沒填時自動用總長）</span></div>';
    h += '<div id="wc-t-out">' + tableHtml('out') + '</div><div style="margin:4px 0 8px;"><span class="wc-btn" data-add="out">＋ 新增外型段</span></div>';
    h += '<div id="wc-t-bore">' + tableHtml('bore') + '</div><div style="margin:4px 0;"><span class="wc-btn" data-add="bore">＋ 新增內孔</span></div>';
    h += '<div id="wc-den">' + densityHtml() + '</div>';
    h += '<div class="wc-res"><div><small>體積 cm³</small><span id="wc-v">—</span></div><div><small>重量</small><span class="wc-big" id="wc-g">—</span> <span style="font-size:14px;font-weight:700;color:#8a4b00;">g</span></div><div><small>換算</small><span id="wc-kg">—</span> kg</div></div>';
    h += '<div class="wc-err" id="wc-err"></div>';
    document.getElementById('wc-body').innerHTML = h;
    recalc();
  }
  function recalc() {
    var c = calc(), rho = density();
    document.getElementById('wc-T').textContent = c.T;
    WC.rows.forEach(function (r) {
      var td = document.querySelector('[data-vol="' + r.id + '"]');
      var v = c.per[r.id];
      if (td) td.textContent = (v == null) ? '—' : (r.kind === 'bore' ? '−' : '') + (Math.round(v / 10) / 100).toLocaleString();
      var inL = document.querySelector('tr[data-rid="' + r.id + '"] [data-f="L"]');
      if (inL) {
        inL.placeholder = r._autoNote ? String(r._autoL) : '';
        inL.classList.toggle('wc-auto', !!r._autoNote);
        var nt = document.querySelector('[data-note="' + r.id + '"]'); if (nt) nt.textContent = r._autoNote || '';
      }
      // 缺值紅框（只標「必要但沒填」的格子）
      var tr = document.querySelector('tr[data-rid="' + r.id + '"]'); if (!tr) return;
      var need = { D: !pn(r.D), D2: r.shape === 'cone' && !pn(r.D2), L: !pn(r.L) && !r._autoNote };
      Object.keys(need).forEach(function (k) { var el = tr.querySelector('[data-f="' + k + '"]'); if (el) el.classList.toggle('wc-miss', need[k]); });
    });
    var errs = c.miss.slice();
    if (!rho) errs.push('材質密度：請選擇或自訂');
    var net = c.vOut - c.vBore;
    var ok = !errs.length && net > 0 && rho > 0 && c.vOut > 0;
    if (!errs.length && c.vOut <= 0) errs.push('至少要有一個外型段');
    if (!errs.length && net <= 0) errs.push('內孔體積大於或等於外型體積，請檢查孔徑與深度');
    var g = ok ? net * rho / 1000 : null;
    document.getElementById('wc-v').textContent = ok ? (Math.round(net / 10) / 100).toLocaleString() : '—';
    document.getElementById('wc-g').textContent = ok ? (Math.round(g * 10) / 10).toLocaleString() : '—';
    document.getElementById('wc-kg').textContent = ok ? String(Math.round(g) / 1000) : '—';
    document.getElementById('wc-err').innerHTML = errs.length ? '尚缺或有誤：<br>' + errs.map(esc).join('<br>') : '';
    document.getElementById('wc-apply').disabled = !ok;
    WC.result = ok ? g : null;
  }

  function pickDefaultDensity() {
    var mat = partMaterials(), ds = WC.ctx.densities || [];
    for (var i = 0; i < mat.ids.length; i++) {
      if (ds.some(function (d) { return String(d.sub_id) === mat.ids[i]; })) { WC.denSel = 'sub:' + mat.ids[i]; return; }
    }
    WC.denSel = '';
  }

  window.openWeightCalc = function () {
    var did = (document.getElementById('pf-d_id') || {}).value || 0;
    document.getElementById('wc-mask').style.display = 'block';
    if (window.jQuery) jQuery(document).off('focusin.bs.modal');   // 料號編輯 modal 的焦點鎖會把焦點搶回自己，打開期間先拿掉
    document.getElementById('wc-body').innerHTML = '<div class="wc-note">載入中…</div>';
    document.getElementById('wc-apply').disabled = true;
    var pn0 = (document.getElementById('pf-D_Setting_Id') || {}).value || '';
    document.getElementById('wc-title-part').textContent = pn0 ? '— ' + pn0 : '';
    api({ action: 'weight_calc_ctx', d_id: did }).done(function (r) {
      if (!r || !r.success) { document.getElementById('wc-body').innerHTML = '<div class="wc-err">' + esc((r && r.message) || '載入失敗') + '</div>'; return; }
      WC.ctx = r; WC.denOpen = false; WC.denCustom = ''; WC.seq = 0;
      WC.rows = buildRows(); pickDefaultDensity(); render();
    }).fail(function () { document.getElementById('wc-body').innerHTML = '<div class="wc-err">載入失敗，請重新整理頁面後再試</div>'; });
  };
  window.closeWeightCalc = function () {
    document.getElementById('wc-mask').style.display = 'none';
    try { if (window.jQuery) { var m = jQuery('#partModal').data('bs.modal'); if (m && m.isShown) m.enforceFocus(); } } catch (e) {}   // 還原焦點鎖
  };
  window.applyWeightCalc = function () {
    if (WC.result == null) return;
    var gRound = Math.round(WC.result);   // 重量欄位最小單位 1 g（資料庫存 kg 三位小數）
    _fillWeightField(gRound / 1000);
    var el = document.getElementById('pf-Weight_Kg'); if (el) { el.dispatchEvent(new Event('input', { bubbles: true })); el.dispatchEvent(new Event('change', { bubbles: true })); }
    closeWeightCalc();
    showToast('已帶入重量 ' + gRound.toLocaleString() + ' g（儲存料號後才會寫入）', 'success');
  };

  // ── 事件（委派到浮層，重畫後仍有效）──
  var mask = document.getElementById('wc-mask');
  function findRow(el) { var tr = el.closest('tr[data-rid]'); if (!tr) return null; var id = +tr.getAttribute('data-rid'); return WC.rows.filter(function (r) { return r.id === id; })[0] || null; }
  mask.addEventListener('input', function (ev) {
    var t = ev.target, f = t.getAttribute('data-f');
    if (f) { var r = findRow(t); if (r) { r[f] = t.value; if (f === 'L') { r._autoNote = ''; } recalc(); } return; }
    if (t.id === 'wc-den-custom') { WC.denCustom = t.value; recalc(); return; }
    var df = t.getAttribute('data-df');
    if (df) { var di = +t.closest('tr').getAttribute('data-di'); WC.denRows[di][df] = t.value; }
  });
  mask.addEventListener('change', function (ev) {
    var t = ev.target, f = t.getAttribute('data-f');
    if (f === 'shape') { var r = findRow(t); if (r) { r.shape = t.value; document.getElementById(r.kind === 'out' ? 'wc-t-out' : 'wc-t-bore').innerHTML = tableHtml(r.kind); recalc(); } return; }
    if (t.id === 'wc-den-sel') {
      WC.denSel = t.value; var ci = document.getElementById('wc-den-custom'); if (ci) ci.style.display = (t.value === 'custom') ? '' : 'none';
      if (t.value === 'custom' && ci) ci.focus(); recalc(); return;
    }
    if (t.getAttribute('data-df') === 'sub_id') { var di = +t.closest('tr').getAttribute('data-di'); WC.denRows[di].sub_id = t.value; }
  });
  mask.addEventListener('click', function (ev) {
    var t = ev.target;
    var add = t.closest('[data-add]'), del = t.closest('[data-del]');
    if (add) {
      var k = add.getAttribute('data-add');
      WC.rows.push(mk(k, k === 'out' ? '外型段' : '內孔', '', '', k === 'bore' ? { auto: 'T' } : null));
      document.getElementById(k === 'out' ? 'wc-t-out' : 'wc-t-bore').innerHTML = tableHtml(k); recalc(); return;
    }
    if (del) {
      var id = +del.getAttribute('data-del'), r = WC.rows.filter(function (x) { return x.id === id; })[0];
      WC.rows = WC.rows.filter(function (x) { return x.id !== id; });
      if (r) document.getElementById(r.kind === 'out' ? 'wc-t-out' : 'wc-t-bore').innerHTML = tableHtml(r.kind); recalc(); return;
    }
    if (t.closest('#wc-den-toggle')) {
      WC.denOpen = !WC.denOpen;
      if (WC.denOpen) WC.denRows = (WC.ctx.densities || []).map(function (d) { return { sub_id: d.sub_id, sub_name: d.sub_name, density_g: d.density_g }; });
      document.getElementById('wc-den').innerHTML = densityHtml(); return;
    }
    if (t.closest('#wc-den-add')) { WC.denRows.push({ sub_id: '', sub_name: '', density_g: '' }); document.getElementById('wc-den').innerHTML = densityHtml(); return; }
    var dd = t.closest('[data-ddel]');
    if (dd) { WC.denRows.splice(+dd.getAttribute('data-ddel'), 1); document.getElementById('wc-den').innerHTML = densityHtml(); return; }
    if (t.closest('#wc-den-save')) {
      var items = WC.denRows.map(function (d) { return { sub_id: parseInt(d.sub_id, 10) || 0, density_g: parseFloat(d.density_g) || 0 }; });
      api({ action: 'weight_density_save', items: JSON.stringify(items) }).done(function (r) {
        if (!r || !r.success) { showToast((r && r.message) || '儲存失敗', 'error'); return; }
        showToast('密度表已儲存', 'success');
        var keep = WC.denSel;
        api({ action: 'weight_calc_ctx', d_id: (document.getElementById('pf-d_id') || {}).value || 0 }).done(function (c) {
          if (c && c.success) { WC.ctx = c; WC.denSel = keep; if (!/^sub:/.test(keep) || !c.densities.some(function (d) { return 'sub:' + d.sub_id === keep; })) { if (keep !== 'custom') pickDefaultDensity(); } WC.denOpen = false; document.getElementById('wc-den').innerHTML = densityHtml(); recalc(); }
        });
      }).fail(function () { showToast('儲存失敗，請稍後再試', 'error'); });
      return;
    }
  });
  // Bootstrap modal（料號編輯）會把焦點搶回自己、Esc 也會連它一起關：這個浮層在 modal 外面，所以要擋下
  mask.addEventListener('focusin', function (ev) { ev.stopPropagation(); });
  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape' && mask.style.display === 'block') { ev.stopPropagation(); ev.preventDefault(); closeWeightCalc(); }
  }, true);
})();
</script>
