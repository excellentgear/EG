/**
 * 表格模板「網頁式填寫表單」共用前端 — 2026-10-14
 * 填寫頁（sheet_form.php）與設計器預覽（sheet_form_template.php）共用，禁止各頁自己再刻一份。
 * 依賴：jQuery、resource/js/eg_sheet_form.js（EGSheetForm.mount 產生的 ctl＝藏在畫面外的試算表引擎，只負責公式與值）。
 * 畫面＝一般 HTML 表單：基本資料格狀、明細每筆一張卡片、計算欄與合計唯讀；輸入即寫進引擎，180ms 後讀回計算結果。
 * 容器 DOM 由 views/ADM/_sheet_form_modal.php 提供（#fmBase #fmDetail #fmCalc #fmErr）。
 */
(function (w, $) {
    'use strict';
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (m) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[m]; }); }
    function parseDate(v) { return /^(\d{4})[-.\/](\d{1,2})[-.\/](\d{1,2})$/.test(v); }

    /** 與後端 sf_norm_value() 同一套規則的即時檢查；回傳錯誤訊息或空字串 */
    function checkValue(f, v, strict) {
        if (f.t === 'checkbox') return '';
        var e = (v === '' || v == null);
        if (e) return (strict && f.req) ? '必填' : '';
        if (f.t === 'number' && isNaN(Number(v))) return '必須是數字';
        if (f.t === 'date' && !parseDate(String(v))) return '日期格式不正確';
        if (f.t === 'select' && f.opt.indexOf(String(v)) < 0) return '不在選項內';
        return '';
    }

    function create(o) {
        var $root = $(o.root), ctl = o.ctl, fields = o.fields || [], canEdit = !!o.canEdit, people = o.people || [];
        var st = { lay: { fieldMeta: {} }, byId: {}, calcT: null, lkT: null, lkReq: 0 };
        fields.forEach(function (f) { st.byId[f.id] = f; });
        var ns = '.sfui' + Math.floor(Math.random() * 1e6);
        var ui = {};

        function inputHtml(f, idx, v) {
            var dis = canEdit ? '' : ' disabled', meta = st.lay.fieldMeta[f.id] || {}, a = ' data-f="' + f.id + '" data-i="' + (idx == null ? '' : idx) + '"';
            if (f.t === 'checkbox') return '<label class="fm-chk"><input type="checkbox"' + a + dis + (v ? ' checked' : '') + '> 是</label>';
            if (f.t === 'number') return '<input type="number" step="any" class="fm-in"' + a + dis + ' value="' + esc(v) + '">';
            if (f.t === 'date') return '<input type="date" class="fm-in"' + a + dis + ' value="' + esc(v) + '">';
            if (f.t === 'part' || f.t === 'customer')
                return '<input type="text" class="fm-in fm-lk" autocomplete="off" data-lk="' + f.t + '"' + a + dis + ' value="' + esc(v) + '" placeholder="' + (f.t === 'part' ? '輸入料號或品名搜尋主檔' : '輸入客戶名稱搜尋主檔') + '">';
            if (f.t === 'select' || f.t === 'person') {
                var opts = f.t === 'select' ? f.opt.slice() : people.slice();
                if (v && opts.indexOf(v) < 0) opts.unshift(v);
                return '<select class="fm-in"' + a + dis + (f.t === 'person' ? ' data-eg-filter="輸入姓名篩選…"' : '') + '><option value="">請選擇</option>' +
                    opts.map(function (x) { return '<option value="' + esc(x) + '"' + (x === v ? ' selected' : '') + '>' + esc(x) + '</option>'; }).join('') + '</select>';
            }
            return meta.wrap ? '<textarea class="fm-in" rows="2"' + a + dis + ' data-eg-skip>' + esc(v) + '</textarea>' : '<input type="text" class="fm-in"' + a + dis + ' value="' + esc(v) + '">';
        }

        ui.build = function () {
            st.lay = ctl.layout();
            var vals = ctl.getValues(), h = '', base = fields.filter(function (f) { return !f.g; });
            if (base.length) {
                h += '<div class="fm-sec">基本資料</div><div class="fm-grid">';
                base.forEach(function (f) {
                    h += '<div class="fm-fld"><label>' + esc(f.label || EGSheetForm.a1(f.r, f.c)) + (f.req ? '<span class="req">*</span>' : '') + '</label>' + inputHtml(f, null, vals[f.id]) + '<div class="fm-msg" data-m="' + f.id + '_"></div></div>';
                });
                h += '</div>';
            }
            $root.find('#fmBase').html(h);
            ui.buildDetail(vals);
        };

        ui.buildDetail = function (vals) {
            vals = vals || ctl.getValues();
            var lay = st.lay = ctl.layout(), d = lay.detail;
            if (!d) { $root.find('#fmDetail').html(''); ui.buildCalc(); return; }
            var n = lay.extra + 1, h = '<div class="fm-sec">明細（共 ' + n + ' 筆）</div>';
            for (var i = 0; i < n; i++) {
                h += '<div class="fm-card"><div class="fm-card-h"><b>第 ' + (i + 1) + ' 筆</b>' + (canEdit && n > 1 ? '<button type="button" class="fm-del" data-i="' + i + '" title="刪除這一筆">✕ 刪除</button>' : '') + '</div><div class="fm-grid fm-cardgrid">';
                d.cols.forEach(function (c) {
                    if (c.kind === 'field') {
                        var meta = lay.fieldMeta[c.f.id] || {}, wide = meta.w >= 200 && (c.f.t === 'text' || c.f.t === 'part' || c.f.t === 'customer');
                        h += '<div class="fm-fld' + (wide ? ' fm-wide' : '') + '"><label>' + esc(c.header) + (c.f.req ? '<span class="req">*</span>' : '') + '</label>' + inputHtml(c.f, i, (vals[c.f.id] || [])[i]) + '<div class="fm-msg" data-m="' + c.f.id + '_' + i + '"></div></div>';
                    } else if (!/項次|序號|NO/i.test(c.header)) {
                        h += '<div class="fm-fld"><label>' + esc(c.header) + '</label><div class="fm-cv" data-r="' + (lay.groupRow + i) + '" data-c="' + c.c + '"></div></div>';
                    }
                });
                h += '</div></div>';
            }
            if (canEdit) h += '<button type="button" class="fm-addrow" id="btnFmAddRow"><i class="fa fa-plus"></i> 新增一筆</button>';
            $root.find('#fmDetail').html(h);
            ui.buildCalc();
        };

        ui.buildCalc = function () {
            var h = '';
            st.lay.summary.forEach(function (x) {
                h += '<div class="fm-calcbox"><label style="font-size:12.5px">' + esc(x.label || EGSheetForm.a1(x.r, x.c)) + '</label><div class="v" data-r="' + x.r + '" data-c="' + x.c + '"></div></div>';
            });
            $root.find('#fmCalc').html(h ? '<div class="fm-sec">計算結果</div>' + h : '');
            ui.refreshCalc();
        };

        ui.refreshCalc = function () {
            clearTimeout(st.calcT);
            st.calcT = setTimeout(function () {
                $root.find('[data-r]').each(function () {
                    var t = ctl.display(+$(this).attr('data-r'), +$(this).attr('data-c'));
                    $(this).text(/^#/.test(t) ? '—' : t);
                });
            }, 180);
        };

        function showMsg(id, idx, msg) {
            var k = id + '_' + (idx == null ? '' : idx);
            $root.find('[data-f="' + id + '"][data-i="' + (idx == null ? '' : idx) + '"]').toggleClass('fm-bad', !!msg);
            $root.find('[data-m="' + k + '"]').text(msg || '');
        }
        ui.showErrors = function (errs) { (errs || []).forEach(function (e) { showMsg(e[0], e[1], e[2]); }); };

        ui.validateAll = function (strict) {
            var errs = [], vals = ctl.getValues();
            $root.find('.fm-bad').removeClass('fm-bad'); $root.find('.fm-msg').text('');
            fields.forEach(function (f) {
                var arr = f.g ? (vals[f.id] || []) : [vals[f.id]];
                arr.forEach(function (v, i) {
                    var er = checkValue(f, v, strict); if (!er) return;
                    var idx = f.g ? i : null, msg = (f.label || EGSheetForm.a1(f.r, f.c)) + (f.g ? '（第' + (i + 1) + '列）' : '') + er;
                    errs.push(msg); showMsg(f.id, idx, msg);
                });
            });
            $root.find('#fmErr').text(errs.length ? '請修正以下問題：\n' + errs.slice(0, 10).join('\n') + (errs.length > 10 ? '\n…另有 ' + (errs.length - 10) + ' 項' : '') : '');
            if (errs.length) { var el = $root.find('.fm-bad').get(0); if (el && el.scrollIntoView) el.scrollIntoView({ block: 'center' }); }
            return errs.length === 0;
        };

        /* ---- 搜尋主檔欄位（料號／客戶）：輸入即向後端查，挑選後填入 ---- */
        function closeLk() { $('.fm-lkbox').remove(); }
        function openLk($in, rows) {
            closeLk();
            if (!rows.length) return;
            var r = $in.get(0).getBoundingClientRect(), h = '<div class="fm-lkbox" style="left:' + r.left + 'px;top:' + (r.bottom + 2) + 'px;min-width:' + r.width + 'px">';
            rows.forEach(function (x) { h += '<div class="fm-lkitem" data-v="' + esc(x.value) + '">' + esc(x.value) + (x.sub ? '<small>' + esc(x.sub) + '</small>' : '') + '</div>'; });
            $('body').append(h + '</div>');
        }
        if (canEdit && o.lookupUrl) {
            $root.on('input' + ns, '.fm-lk', function () {
                var $in = $(this), q = $.trim($in.val()), kind = $in.attr('data-lk');
                clearTimeout(st.lkT);
                if (q.length < 1) { closeLk(); return; }
                st.lkT = setTimeout(function () {
                    var my = ++st.lkReq;
                    $.getJSON(o.lookupUrl, { action: 'lookup', kind: kind, q: q }, function (res) { if (my === st.lkReq && res.ok) openLk($in, res.rows || []); });
                }, 220);
            });
            $(document).on('mousedown' + ns, '.fm-lkitem', function (e) {
                e.preventDefault();
                var $box = $(this).closest('.fm-lkbox'), v = $(this).attr('data-v');
                var $in = $root.find('.fm-lk.fm-lk-on');
                if ($in.length) $in.val(v).trigger('change');
                closeLk();
            });
            $root.on('focus' + ns, '.fm-lk', function () { $root.find('.fm-lk').removeClass('fm-lk-on'); $(this).addClass('fm-lk-on'); });
            $root.on('blur' + ns, '.fm-lk', function () { setTimeout(closeLk, 150); });
        }

        /* ---- 一般輸入：寫進引擎＋即時檢查 ---- */
        if (canEdit) {
            $root.on('input' + ns + ' change' + ns, '[data-f]', function () {
                var $e = $(this), f = st.byId[$e.attr('data-f')], idxRaw = $e.attr('data-i'), idx = (idxRaw === '' || idxRaw == null) ? null : +idxRaw;
                var v = $e.is(':checkbox') ? $e.is(':checked') : $e.val();
                ctl.setField(f, idx, v);
                var er = checkValue(f, v, false);
                showMsg(f.id, idx, er ? (f.label || '') + er : '');
                ui.refreshCalc();
            });
            $root.on('click' + ns, '#btnFmAddRow', function () {
                $(this).prop('disabled', true);
                ctl.addDetailRow().then(function () { ui.buildDetail(); });
            });
            $root.on('click' + ns, '.fm-del', function () {
                var i = +$(this).attr('data-i');
                if (!confirm('刪除第 ' + (i + 1) + ' 筆（含已填內容）？')) return;
                ctl.removeDetailRow(i).then(function () { ui.buildDetail(); });
            });
        }

        ui.destroy = function () { $root.off(ns); $(document).off(ns); closeLk(); clearTimeout(st.calcT); };
        return ui;
    }

    w.EGSheetFormUI = { create: create, checkValue: checkValue };
})(window, jQuery);
