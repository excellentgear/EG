/**
 * BOM 檢核（BOM總覽）前端——唯一實作。後端 src/store/BomCheck_API.php／src/common/bom_check_lib.php
 * 用法：EGBomCheck.init({api,csrf,canCheck,canReport,map,types})；列表渲染時由頁面呼叫
 *   EGBomCheck.decorateRow(tr,row,tdClient,tdDid,ctnWrap)、EGBomCheck.pin(rows)、EGBomCheck.isFlagged(bom)
 * 沒有被標示的 BOM 與沒有檢核權限的人：畫面與原本完全相同（只多了被標示列的淺黃底與回報區）。
 */
(function (w) {
    var S = { api: '', csrf: '', canCheck: false, canReport: false, map: {}, types: [], mode: false };
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function fmtAt(s) { s = String(s || ''); var d = s.substring(0, 10), t = s.substring(11, 16); return (w.egFmtDate ? w.egFmtDate(d) : d.replace(/-/g, '.')) + ' ' + t; }
    function rerender() { if (typeof w.processAndRenderData === 'function') w.processAndRenderData(); }
    function post(data, cb) {
        data.csrf = S.csrf;
        var body = Object.keys(data).map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(data[k]); }).join('&');
        fetch(S.api, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body })
            .then(function (r) { return r.json(); }).then(cb).catch(function () { cb({ success: false, message: '與伺服器通訊失敗' }); });
    }
    function applyMap(r) { if (r && r.map) S.map = r.map; if (r && r.types) S.types = r.types; }

    // ── 樣式 ──
    var st = document.createElement('style');
    st.textContent = '#table-DOWN tbody tr.bom-check-flag > td{background-color:#FFF6C9 !important;}'
        + '#table-DOWN tbody tr.bom-check-flag:hover > td{background-color:#FFEFA8 !important;}'
        + '.bchk-badge{display:inline-block;background:#DD5138;color:#fff;border-radius:3px;padding:0 6px;font-size:11px;line-height:18px;font-weight:bold;white-space:nowrap;}'
        + '.bchk-note{font-size:11px;color:#6B4423;line-height:1.35;margin-top:2px;word-break:break-all;white-space:pre-wrap;}'
        + '.bchk-rep{font-size:11px;line-height:1.35;margin-top:3px;padding:3px 5px;background:#fff;border-left:3px solid #F0A24B;display:flex;gap:6px;align-items:flex-start;justify-content:space-between;}'
        + '.bchk-rep .bchk-who{color:#999;font-size:10px;}'
        + '.bchk-done{color:#fff;background:#2E8B57;border-radius:2px;padding:0 4px;font-size:10px;margin-right:3px;}'
        + '.bchk-ing{color:#5a3d00;background:#F5C542;border-radius:2px;padding:0 4px;font-size:10px;margin-right:3px;}'
        + '.bchk-btn{border:1px solid #d9861f;background:#fff;color:#a4491a;border-radius:3px;padding:0 6px;font-size:11px;line-height:18px;cursor:pointer;white-space:nowrap;}'
        + '.bchk-btn.on{background:#DD5138;border-color:#DD5138;color:#fff;}'
        + '.bchk-btn.ok{border-color:#2E8B57;color:#1f6b41;}'
        + '#bchk-mask{position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:10700;display:flex;align-items:center;justify-content:center;}'
        + '#bchk-mask .bchk-m{background:#fff;border-radius:6px;width:480px;max-width:94vw;max-height:90vh;overflow:auto;padding:16px 18px;color:#4a3320;font-size:13px;box-shadow:0 8px 30px rgba(0,0,0,.3);}'
        + '#bchk-mask h4{margin:0 0 10px;color:#6B4423;}'
        + '#bchk-mask .bchk-types{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:8px;}'
        + '#bchk-mask .bchk-type{border:1px solid #d9861f;background:#fff;color:#a4491a;border-radius:4px;padding:4px 10px;cursor:pointer;}'
        + '#bchk-mask .bchk-type.sel{background:#DD5138;border-color:#DD5138;color:#fff;font-weight:bold;}'
        + '#bchk-mask textarea{width:100%;min-height:72px;border:1px solid #ccb89c;border-radius:3px;padding:5px;}'
        + '#bchk-mask .bchk-err{color:#b8431f;font-size:12px;min-height:16px;margin-top:4px;}'
        + '#bchk-mask .bchk-foot{display:flex;justify-content:flex-end;gap:8px;margin-top:10px;}';
    document.head.appendChild(st);

    // ── 跳窗 ──
    function closeModal() { var m = document.getElementById('bchk-mask'); if (m) m.remove(); }
    function openModal(html) {
        closeModal();
        var m = document.createElement('div');
        m.id = 'bchk-mask';
        m.innerHTML = '<div class="bchk-m">' + html + '</div>';
        document.body.appendChild(m);
        m.addEventListener('mousedown', function (e) { if (e.target === m) closeModal(); });
        return m;
    }
    function btnHtml(id, label, cls) { return '<button type="button" id="' + id + '" class="btn btn-sm ' + (cls || 'btn-default') + '">' + label + '</button>'; }

    function flagModal(bom) {
        var cur = S.map[bom] || null;
        var selId = cur ? cur.type_id : 0;
        var tp = S.types.map(function (t) { return '<button type="button" class="bchk-type' + (t.type_id == selId ? ' sel' : '') + '" data-id="' + t.type_id + '">' + esc(t.name) + '</button>'; }).join('');
        var m = openModal('<h4>BOM 檢核　' + esc(bom) + '</h4>'
            + '<div style="margin-bottom:4px;">問題種類（必選）' + (S.canCheck ? ' <a href="javascript:;" id="bchk-mgr" style="font-size:11px;margin-left:6px;">管理種類</a>' : '') + '</div>'
            + '<div class="bchk-types">' + (tp || '<span class="text-muted">尚未設定問題種類</span>') + '</div>'
            + '<div style="margin:6px 0 2px;">備註</div><textarea id="bchk-note" maxlength="500">' + esc(cur ? cur.note : '') + '</textarea>'
            + '<div class="bchk-err" id="bchk-err"></div>'
            + '<div class="bchk-foot">' + btnHtml('bchk-cancel', '取消') + btnHtml('bchk-save', cur ? '更新標示' : '標示為檢核異常', 'btn-danger') + '</div>');
        m.addEventListener('click', function (e) {
            var b = e.target.closest('.bchk-type');
            if (b) { selId = +b.getAttribute('data-id'); [].forEach.call(m.querySelectorAll('.bchk-type'), function (x) { x.classList.toggle('sel', x === b); }); m.querySelector('#bchk-err').textContent = ''; }
        });
        m.querySelector('#bchk-cancel').onclick = closeModal;
        var mg = m.querySelector('#bchk-mgr'); if (mg) mg.onclick = typesModal;
        m.querySelector('#bchk-save').onclick = function () {
            var err = m.querySelector('#bchk-err');
            if (!selId) { err.textContent = '請先選擇問題種類'; return; }
            var btn = this; btn.disabled = true;
            post({ action: 'flag', bom: bom, type_id: selId, note: m.querySelector('#bchk-note').value }, function (r) {
                btn.disabled = false;
                if (!r.success) { err.textContent = r.message || '儲存失敗'; return; }
                applyMap(r); closeModal(); rerender();
            });
        };
    }
    function replyModal(bom, chk) {
        var done = 0;
        var m = openModal('<h4>回報　' + esc(bom) + '</h4><div style="margin-bottom:6px;"><span class="bchk-badge">' + esc(chk.type_name) + '</span> <span class="bchk-note">' + esc(chk.note) + '</span></div>'
            + '<div style="margin-bottom:2px;">說明（必填）</div><textarea id="bchk-content" maxlength="1000"></textarea>'
            + '<div style="margin:8px 0 2px;">處理情形（必選）</div><div class="bchk-types"><button type="button" class="bchk-type" data-d="1">已處理完成</button><button type="button" class="bchk-type" data-d="0">尚未完成</button></div>'
            + '<div class="bchk-err" id="bchk-err"></div>'
            + '<div class="bchk-foot">' + btnHtml('bchk-cancel', '取消') + btnHtml('bchk-save', '送出回報', 'btn-warning') + '</div>');
        var picked = false;
        m.addEventListener('click', function (e) {
            var b = e.target.closest('.bchk-type[data-d]');
            if (b) { picked = true; done = +b.getAttribute('data-d'); [].forEach.call(m.querySelectorAll('.bchk-type[data-d]'), function (x) { x.classList.toggle('sel', x === b); }); m.querySelector('#bchk-err').textContent = ''; }
        });
        m.querySelector('#bchk-cancel').onclick = closeModal;
        m.querySelector('#bchk-save').onclick = function () {
            var err = m.querySelector('#bchk-err'), c = m.querySelector('#bchk-content').value.trim();
            if (!c) { err.textContent = '請輸入說明'; return; }
            if (!picked) { err.textContent = '請選擇「已處理完成」或「尚未完成」'; return; }
            var btn = this; btn.disabled = true;
            post({ action: 'reply', check_id: chk.check_id, content: c, is_done: done }, function (r) {
                btn.disabled = false;
                if (!r.success) { err.textContent = r.message || '送出失敗'; if (r.code === 'STALE') { applyMap(r); setTimeout(function () { closeModal(); rerender(); }, 900); } return; }
                applyMap(r); closeModal(); rerender();
            });
        };
    }
    function typesModal() {
        var m = openModal('<h4>管理問題種類</h4><div id="bchk-tl">載入中…</div><div class="bchk-err" id="bchk-err"></div>'
            + '<div style="display:flex;gap:6px;margin-top:8px;"><input type="text" id="bchk-new" class="form-control input-sm" maxlength="40" placeholder="新增種類名稱"> ' + btnHtml('bchk-add', '新增', 'btn-warning') + '</div>'
            + '<div class="bchk-foot">' + btnHtml('bchk-close', '關閉') + '</div>');
        var err = m.querySelector('#bchk-err');
        function paint(types) {
            S.types = types.filter(function (t) { return +t.active === 1; });
            m.querySelector('#bchk-tl').innerHTML = types.map(function (t) {
                return '<div style="display:flex;gap:6px;align-items:center;margin-bottom:4px;" data-id="' + t.type_id + '"><input type="text" class="form-control input-sm bchk-tn" value="' + esc(t.name) + '" maxlength="40">'
                    + '<label style="white-space:nowrap;margin:0;font-weight:normal;"><input type="checkbox" class="bchk-ta"' + (+t.active ? ' checked' : '') + '> 啟用</label>'
                    + '<button type="button" class="btn btn-xs btn-default bchk-ts">儲存</button><button type="button" class="btn btn-xs btn-default bchk-td">刪除</button></div>';
            }).join('') || '<span class="text-muted">尚無種類</span>';
        }
        function done(r) { if (!r.success) { err.textContent = r.message || '失敗'; return; } err.textContent = ''; paint(r.types); }
        fetch(S.api + '?action=types', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (r) { if (r.success) paint(r.types); else err.textContent = r.message || '讀取失敗'; });
        m.addEventListener('click', function (e) {
            var row = e.target.closest('[data-id]');
            if (e.target.classList.contains('bchk-ts') && row) post({ action: 'type_save', type_id: row.getAttribute('data-id'), name: row.querySelector('.bchk-tn').value, active: row.querySelector('.bchk-ta').checked ? 1 : 0 }, done);
            else if (e.target.classList.contains('bchk-td') && row) { if (confirm('確定刪除這個種類？')) post({ action: 'type_delete', type_id: row.getAttribute('data-id') }, done); }
        });
        m.querySelector('#bchk-add').onclick = function () { var v = m.querySelector('#bchk-new').value; post({ action: 'type_save', type_id: 0, name: v }, function (r) { done(r); if (r.success) m.querySelector('#bchk-new').value = ''; }); };
        m.querySelector('#bchk-close').onclick = function () { closeModal(); };
    }

    // ── 列表渲染 ──
    function mkBtn(label, cls, fn) {
        var b = document.createElement('button'); b.type = 'button'; b.className = 'bchk-btn ' + (cls || ''); b.textContent = label;
        b.onclick = function (e) { e.preventDefault(); e.stopPropagation(); fn(); };
        return b;
    }
    function decorateRow(tr, row, tdClient, tdDid, ctnWrap) {
        try {
            var bom = String(row.bom || '');
            var chk = S.map[bom] || null;
            if (S.canCheck && S.mode && tdClient) {
                var fb = mkBtn('問題標示', chk ? 'on' : '', function () { flagModal(bom); });
                fb.style.marginRight = '5px';
                tdClient.insertBefore(fb, tdClient.firstChild);
            }
            if (!chk) return;
            tr.classList.add('bom-check-flag');
            var badge = document.createElement('span');
            badge.className = 'bchk-badge'; badge.textContent = chk.type_name; badge.title = '檢核異常：' + chk.by + ' ' + fmtAt(chk.at);
            if (ctnWrap) { badge.style.marginRight = 'auto'; ctnWrap.insertBefore(badge, ctnWrap.firstChild); }
            else { var w0 = document.createElement('div'); w0.style.cssText = 'margin-top:3px;'; w0.appendChild(badge); tdDid.appendChild(w0); }
            var box = document.createElement('div');
            box.style.cssText = 'margin-top:2px;';
            var nr = document.createElement('div');
            nr.style.cssText = 'display:flex;gap:6px;align-items:flex-start;justify-content:space-between;';
            var nt = document.createElement('div'); nt.className = 'bchk-note'; nt.style.flex = '1'; nt.textContent = chk.note || '';
            nr.appendChild(nt);
            var rbtns = document.createElement('div'); rbtns.style.cssText = 'flex:0 0 auto;display:flex;gap:4px;';
            if (S.canReport) rbtns.appendChild(mkBtn('回報', '', function () { replyModal(bom, chk); }));
            var resolveBtn = function () {
                return mkBtn('異常解決', 'ok', function () {
                    if (!confirm('確定要解除 ' + bom + ' 的檢核異常？\n（會記錄到 BOM 相關紀錄）')) return;
                    post({ action: 'resolve', check_id: chk.check_id }, function (r) { if (!r.success) alert(r.message || '失敗'); applyMap(r); rerender(); });
                });
            };
            var reps = chk.replies || [];
            if (S.canCheck && S.mode && !reps.length) rbtns.appendChild(resolveBtn());
            nr.appendChild(rbtns);
            box.appendChild(nr);
            reps.forEach(function (rp, i) {
                var d = document.createElement('div'); d.className = 'bchk-rep';
                var l = document.createElement('div');
                l.innerHTML = '<span class="' + (rp.is_done ? 'bchk-done' : 'bchk-ing') + '">' + (rp.is_done ? '已完成' : '未完成') + '</span>' + esc(rp.content).replace(/\n/g, '<br>')
                    + '<div class="bchk-who">' + esc(rp.by) + '　' + esc(fmtAt(rp.at)) + '</div>';
                d.appendChild(l);
                if (S.canCheck && S.mode && i === reps.length - 1) d.appendChild(resolveBtn());
                box.appendChild(d);
            });
            tdDid.appendChild(box);
        } catch (e) { console.warn('EGBomCheck.decorateRow', e); }
    }
    function pin(rows) {
        if (!Array.isArray(rows) || !rows.length || !Object.keys(S.map).length) return rows;
        var a = [], b = [];
        rows.forEach(function (r) { (r && S.map[String(r.bom || '')] ? a : b).push(r); });
        return a.length ? a.concat(b) : rows;
    }
    function refresh(cb) {
        fetch(S.api + '?action=map', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (r) {
            if (!r || !r.success) return;
            var changed = JSON.stringify(S.map) !== JSON.stringify(r.map);
            S.map = r.map; S.types = r.types || S.types;
            if (cb) cb(changed);
        }).catch(function () {});
    }
    function init(o) {
        S.api = o.api; S.csrf = o.csrf; S.canCheck = !!o.canCheck; S.canReport = !!o.canReport; S.map = o.map || {}; S.types = o.types || [];
        if (!S.canCheck) return;
        var anchor = document.getElementById('btn-vp-reveal');
        if (anchor) {
            var tb = document.createElement('button');
            tb.type = 'button'; tb.id = 'btn-bom-check-mode'; tb.className = 'btn btn-xs btn-default'; tb.style.marginLeft = '8px';
            tb.title = '開啟後，每列客戶欄左側會出現「問題標示」鈕，可標示有問題的 BOM'; tb.innerHTML = '<i class="fa fa-check-square-o"></i> BOM檢核';
            tb.onclick = function () { S.mode = !S.mode; tb.className = 'btn btn-xs ' + (S.mode ? 'btn-warning' : 'btn-default'); rerender(); };
            anchor.parentNode.insertBefore(tb, anchor.nextSibling);
        }
        var sf = document.getElementById('status-filter');
        if (sf && !sf.querySelector('option[value="bom_check_flag"]')) {
            var op = document.createElement('option'); op.value = 'bom_check_flag'; op.textContent = '檢核異常';
            var sep = sf.querySelector('option[value="---sep_process_remark---"]');
            sf.insertBefore(op, sep || null);
        }
    }
    w.EGBomCheck = { init: init, decorateRow: decorateRow, pin: pin, refresh: refresh, isFlagged: function (b) { return !!S.map[String(b || '')]; }, hasAny: function () { return Object.keys(S.map).length > 0; } };
})(window);
