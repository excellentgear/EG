/**
 * 表格模板共用前端（Univer 包裝）— 2026-10-14
 * 設計器與填寫頁都用它；需先載入 resource/js/eg_univer.js（全站唯一一份 Univer 打包檔，禁止各頁自帶）。
 *
 * 待填欄位的定義寫在「儲存格自己的 custom.sf」：{id,t,req,opt,g,label}，隨列插入自動位移；
 * 存檔時後端（sheet_form_lib.php sf_book_fields）掃出來存成 fields_json，那才是填寫與驗證的依據。
 * 儲存格的淡黃底色（MARK）只是「這格要填」的畫面提示，存檔／列印時一律剝掉（MARK 是保留色）。
 */
(function (w) {
    'use strict';
    var MARK = '#FFF3D1';
    var TYPE_LABEL = { text: '文字', number: '數字', date: '日期', select: '下拉選單', checkbox: '勾選框', person: '人員' };

    function p2(n) { return (n < 10 ? '0' : '') + n; }
    function colName(c) { var s = ''; c++; while (c > 0) { var m = (c - 1) % 26; s = String.fromCharCode(65 + m) + s; c = Math.floor((c - 1) / 26); } return s; }
    function a1(r, c) { return colName(c) + (r + 1); }
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (m) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[m]; }); }
    function clone(o) { return o == null ? o : JSON.parse(JSON.stringify(o)); }
    function serialToDate(n) { var d = new Date(Date.UTC(1899, 11, 30) + Math.round(n) * 86400000); return d.getUTCFullYear() + '-' + p2(d.getUTCMonth() + 1) + '-' + p2(d.getUTCDate()); }
    function dateToSerial(s) { var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(s || '')); return m ? Math.round((Date.UTC(+m[1], +m[2] - 1, +m[3]) - Date.UTC(1899, 11, 30)) / 86400000) : null; }

    function firstSheet(book) {
        var order = book.sheetOrder || [], sid = order[0] || Object.keys(book.sheets || {})[0];
        return { id: sid, sheet: (book.sheets || {})[sid] || {} };
    }

    /** 對活頁簿 JSON 上色／去色（只動「有 custom.sf 的格子」或保留色 MARK 的格子） */
    function tintBook(book, on) {
        var fs = firstSheet(book), cd = fs.sheet.cellData || {}, styles = book.styles || {};
        Object.keys(cd).forEach(function (r) {
            Object.keys(cd[r] || {}).forEach(function (c) {
                var cell = cd[r][c]; if (!cell) return;
                var isF = !!(cell.custom && cell.custom.sf);
                var base = typeof cell.s === 'string' ? clone(styles[cell.s] || {}) : clone(cell.s || {});
                var hasMark = base.bg && base.bg.rgb && String(base.bg.rgb).toUpperCase() === MARK;
                if (on) { if (isF && !base.bg) { base.bg = { rgb: MARK }; cell.s = base; } }
                else if (hasMark) { delete base.bg; cell.s = base; }
            });
        });
        return book;
    }

    /** 公式內相對列號往下平移 n 列（有 $ 的絕對列不動；避開函式名稱如 LOG10( ） */
    function shiftFormulaRows(f, n) {
        return String(f).replace(/(?<![A-Za-z0-9_.])(\$?[A-Z]{1,3})(\$?)(\d+)(?![\d(A-Za-z_])/g, function (m, col, abs, row) {
            return abs ? m : col + (parseInt(row, 10) + n);
        });
    }
    /** 彙總函式（SUM 等）的範圍若結尾剛好是明細最後一列，就延伸 n 列；單格引用 SUM(C5) 則變 SUM(C5:C6) */
    function expandAggregates(f, lastRow1, n) {
        n = n || 1;
        var s = String(f);
        s = s.replace(/(\$?[A-Z]{1,3}\$?)(\d+):(\$?[A-Z]{1,3}\$?)(\d+)/g, function (m, c1, r1, c2, r2) {
            return parseInt(r2, 10) === lastRow1 ? c1 + r1 + ':' + c2 + (lastRow1 + n) : m;
        });
        s = s.replace(/\b(SUM|AVERAGE|COUNT|COUNTA|MAX|MIN)\(\s*(\$?[A-Z]{1,3}\$?)(\d+)\s*\)/gi, function (m, fn, col, row) {
            return parseInt(row, 10) === lastRow1 ? fn + '(' + col + row + ':' + col.replace(/\$/g, '') + (lastRow1 + n) + ')' : m;
        });
        return s;
    }

    function mount(o) {
        var U = w.EGUniver;
        if (!U) throw new Error('Univer 元件尚未載入');
        var mode = o.mode || 'fill';
        var book = clone(o.book) || null;
        var fields = o.fields || [];
        var people = o.people || [];
        var toast = o.toast || function (m) { try { console.log(m); } catch (e) {} };
        var extra = o.extraRows || 0;
        var ctl = {};
        var internal = 0;                 // >0 ＝ 是我們自己在改，不要被鎖定攔截
        var groupRow = null;
        fields.forEach(function (f) { if (f.g) groupRow = f.r; });

        if (book) tintBook(book, mode !== 'view');
        var created = U.createUniver({
            locale: U.LocaleType.ZH_TW,
            locales: (function () { var m = {}; m[U.LocaleType.ZH_TW] = U.mergeLocales(U.zhTW, U.dvZhTW); return m; })(),
            presets: [U.UniverSheetsCorePreset({ container: o.el }), U.UniverSheetsDataValidationPreset()]
        });
        var api = created.univerAPI;
        ctl.api = api; ctl.univer = created.univer;
        var wb = api.createWorkbook(book || {}); ctl.wb = wb;
        function ws() { return wb.getActiveSheet(); }

        if (mode !== 'design') {
            try { api.setUIVisible(api.Enum.BuiltInUIPart.TOOLBAR, false); } catch (e) {}
        }
        if (mode === 'view') { try { wb.setEditable(false); } catch (e) {} }   // 唯讀檢視：整本不可編輯

        /* ---------- 位置換算（填寫模式：明細列多加幾列後，其下方欄位整體往下位移） ---------- */
        function rowOf(f, i) { return (f.g ? f.r + (i || 0) : (groupRow != null && f.r > groupRow ? f.r + extra : f.r)); }
        function fieldAt(r, c) {
            for (var k = 0; k < fields.length; k++) {
                var f = fields[k];
                if (f.c !== c) continue;
                if (f.g) { if (r >= f.r && r <= f.r + extra) return f; }
                else if (rowOf(f) === r) return f;
            }
            return null;
        }
        ctl.isEditable = function (r, c) { return !!fieldAt(r, c); };

        /* ---------- 填寫模式：載入值 ---------- */
        function setCellValue(f, r, v) {
            var rng = ws().getRange(r, f.c);
            if (f.t === 'date') { var sn = dateToSerial(v); if (sn != null) { rng.setValue(sn); } else { rng.setValue(v || ''); } }
            else if (f.t === 'checkbox') { rng.setValue(v ? 1 : 0); }
            else if (f.t === 'number') { rng.setValue(v === '' || v == null ? '' : Number(v)); }
            else { rng.setValue(v == null ? '' : String(v)); }
        }
        ctl.setValues = function (vals) {
            internal++;
            try {
                fields.forEach(function (f) {
                    var v = vals[f.id];
                    if (f.g) { (Array.isArray(v) ? v : [v]).forEach(function (x, i) { if (x !== undefined && x !== '' && x !== null && x !== false) setCellValue(f, f.r + i, x); }); }
                    else if (v !== undefined && v !== '' && v !== null && v !== false) setCellValue(f, rowOf(f), v);
                });
            } finally { internal--; }
        };
        function readCell(f, r) {
            var rng = ws().getRange(r, f.c), v = (f.t === 'date' && rng.getRawValue) ? rng.getRawValue() : rng.getValue();
            if (v === null || v === undefined) return f.t === 'checkbox' ? false : '';
            if (f.t === 'date') { if (typeof v === 'number') return serialToDate(v); return String(v).replace(/[.\/]/g, '-'); }
            if (f.t === 'checkbox') return v === true || v === 1 || v === '1' || String(v).toLowerCase() === 'true';
            if (f.t === 'number') return v === '' ? '' : v;
            return String(v);
        }
        ctl.getValues = function () {
            var out = {};
            fields.forEach(function (f) {
                if (f.g) { var arr = []; for (var i = 0; i <= extra; i++) arr.push(readCell(f, f.r + i)); out[f.id] = arr; }
                else out[f.id] = readCell(f, rowOf(f));
            });
            return out;
        };
        ctl.getExtra = function () { return extra; };

        /* ---------- 明細列：加一列 ---------- */
        ctl.hasDetail = function () { return groupRow != null; };
        var chain = Promise.resolve();
        function sleep(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }
        async function doAdd(n) {
            n = n || 1;
            internal++;
            try {
                var sh = ws(), lastD = groupRow + extra, maxC = 0;
                var cd = (firstSheet(wb.save()).sheet.cellData) || {};
                Object.keys(cd).forEach(function (r) { Object.keys(cd[r] || {}).forEach(function (c) { if (+c > maxC) maxC = +c; }); });
                sh.insertRowsAfter(lastD, n);              // 樣式與資料驗證會自動沿用上一列
                await sleep(150);                           // 等 Univer 自己的參照位移處理完，再改公式（否則會被它蓋回去）
                var merges = (sh.getMergedRanges() || []).filter(function (m) { return m.getRow() === lastD && m.getLastRow() === lastD && m.getLastColumn() > m.getColumn(); });
                var srcF = [], srcV = [];
                for (var c = 0; c <= maxC; c++) {
                    var isF = fields.some(function (f) { return f.g && f.c === c; });
                    var src = sh.getRange(lastD, c);
                    srcF[c] = isF ? '' : src.getFormula();
                    srcV[c] = (isF || srcF[c]) ? null : src.getValue();
                }
                var h = 0; try { h = sh.getRowHeight(lastD); } catch (e) {}
                for (var k = 1; k <= n; k++) {
                    var nr = lastD + k;
                    for (var c2 = 0; c2 <= maxC; c2++) {
                        if (srcF[c2]) sh.getRange(nr, c2).setFormula(shiftFormulaRows(srcF[c2], k));
                        else if (srcV[c2] !== null && srcV[c2] !== undefined && srcV[c2] !== '') sh.getRange(nr, c2).setValue(srcV[c2]);
                    }
                    merges.forEach(function (m) { sh.getRange(nr, m.getColumn(), 1, m.getLastColumn() - m.getColumn() + 1).merge(); });
                    if (h) { try { sh.setRowHeight(nr, h); } catch (e) {} }
                }
                // 其他列的彙總公式（如合計）一併延伸
                var snap = firstSheet(wb.save()).sheet.cellData || {};
                Object.keys(snap).forEach(function (r) {
                    if (+r >= groupRow && +r <= lastD + n) return;
                    Object.keys(snap[r] || {}).forEach(function (c) {
                        var f0 = snap[r][c] && snap[r][c].f;
                        if (!f0) return;
                        var nf = expandAggregates(f0, lastD + 1, n);
                        if (nf !== f0) sh.getRange(+r, +c).setFormula(nf);
                    });
                });
                extra += n;
                await sleep(80);
                if (ctl.armGuard) ctl.armGuard();           // 結構變了，鎖定基準要重建，否則下一次編輯會被當成違規而復原
            } finally { internal--; }
            return true;
        }
        /** 加一列（回傳 Promise；連按會排隊依序執行，不會互相踩） */
        ctl.addDetailRow = function () {
            if (groupRow == null) return Promise.resolve(false);
            chain = chain.then(function () { return doAdd(1); }).catch(function (e) { try { console.error(e); } catch (x) {} toast('加列失敗：' + (e && e.message || e)); return false; });
            return chain;
        };
        /** 一次加 n 列（載入既有單據時用，比逐列加快很多） */
        ctl.addDetailRows = function (n) {
            if (groupRow == null || n <= 0) return Promise.resolve(false);
            chain = chain.then(function () { return doAdd(n); }).catch(function (e) { try { console.error(e); } catch (x) {} toast('加列失敗：' + (e && e.message || e)); return false; });
            return chain;
        };
        async function doRemoveLast() {
            internal++;
            try { ws().deleteRow(groupRow + extra); extra--; await sleep(120); if (ctl.armGuard) ctl.armGuard(); } finally { internal--; }
            return true;
        }
        ctl.removeLastDetailRow = function () {
            if (groupRow == null || extra <= 0) return Promise.resolve(false);
            chain = chain.then(doRemoveLast);
            return chain;
        };
        /** 寫入單一待填格（網頁表單用；不觸發鎖定安全網） */
        ctl.setField = function (f, idx, v) {
            internal++;
            try { setCellValue(f, f.g ? f.r + (idx || 0) : rowOf(f), v); } finally { internal--; }
        };
        /** 整批覆寫明細列（含清空）；vals＝{欄位id:[各列值]} */
        ctl.setDetailRows = function (vals) {
            internal++;
            try {
                fields.forEach(function (f) {
                    if (!f.g) return;
                    var arr = vals[f.id] || [];
                    for (var i = 0; i <= extra; i++) {
                        var v = arr[i], rng = ws().getRange(f.r + i, f.c);
                        if (v === undefined || v === null || v === '' || v === false) rng.setValue(f.t === 'checkbox' ? 0 : '');
                        else setCellValue(f, f.r + i, v);
                    }
                });
            } finally { internal--; }
        };
        /** 刪除指定的明細列（第 i 列，0 起算）：後面的列往上補，最後一列刪掉 */
        ctl.removeDetailRow = function (i) {
            if (groupRow == null || i < 0 || i > extra) return Promise.resolve(false);
            chain = chain.then(async function () {
                var vals = ctl.getValues();
                fields.forEach(function (f) { if (f.g && Array.isArray(vals[f.id])) vals[f.id].splice(i, 1); });
                if (extra > 0) { await doRemoveLast(); ctl.setDetailRows(vals); }
                else { var empty = {}; fields.forEach(function (f) { if (f.g) empty[f.id] = ['']; }); ctl.setDetailRows(empty); }
                await sleep(150);
                return true;
            });
            return chain;
        };
        ctl.display = function (r, c) { try { return ws().getRange(r, c).getDisplayValue(); } catch (e) { return ''; } };
        /** 網頁表單版面：欄位資訊、明細表欄位（含計算欄）、其他計算儲存格 */
        ctl.layout = function () {
            var snap = wb.save(), sh = firstSheet(snap).sheet, cd = sh.cellData || {}, colD = sh.columnData || {}, defW = sh.defaultColumnWidth || 88, styles = snap.styles || {};
            function cell(r, c) { return (cd[r] && cd[r][c]) || null; }
            function styleOf(x) { return x ? (typeof x.s === 'string' ? (styles[x.s] || {}) : (x.s || {})) : {}; }
            function stext(r, c) { var x = cell(r, c); return x && x.v != null && !x.f && typeof x.v !== 'object' ? String(x.v) : ''; }
            function width(c) { return (colD[c] && colD[c].w) || defW; }
            var out = { groupRow: groupRow, extra: extra, detail: null, summary: [], fieldMeta: {} };
            fields.forEach(function (f) { var r = f.g ? f.r : rowOf(f); out.fieldMeta[f.id] = { wrap: styleOf(cell(r, f.c)).tb === 3, w: width(f.c) }; });
            var cols = [];
            if (groupRow != null) {
                var maxC = 0; Object.keys(cd[groupRow] || {}).forEach(function (c) { if (+c > maxC) maxC = +c; });
                for (var c = 0; c <= maxC; c++) {
                    var fld = fields.filter(function (f) { return f.g && f.c === c; })[0], x = cell(groupRow, c);
                    var head = ''; for (var rr = groupRow - 1; rr >= Math.max(0, groupRow - 3) && !head; rr--) head = stext(rr, c);
                    if (fld) cols.push({ c: c, kind: 'field', f: fld, header: head || fld.label || colName(c), w: width(c) });
                    else if (x && x.f) cols.push({ c: c, kind: 'calc', header: head || colName(c), w: width(c) });
                }
                out.detail = { cols: cols, first: groupRow };
            }
            Object.keys(cd).forEach(function (r0) {
                var r = +r0;
                if (groupRow != null && r >= groupRow && r <= groupRow + extra) return;
                Object.keys(cd[r] || {}).forEach(function (c0) {
                    var c = +c0, x = cd[r][c]; if (!x || !x.f) return;
                    var label = ''; for (var k = c - 1; k >= 0 && !label; k--) label = stext(r, k);
                    out.summary.push({ r: r, c: c, label: label, below: groupRow != null && r > groupRow + extra,
                        inCols: cols.some(function (cc) { return cc.c === c; }) });
                });
            });
            out.summary.sort(function (a, b) { return a.r - b.r || a.c - b.c; });
            out.rowTexts = {};
            out.summary.forEach(function (x) {
                if (out.rowTexts[x.r]) return;
                var t = {}; Object.keys(cd[x.r] || {}).forEach(function (c) { var v = stext(x.r, +c); if (v) t[c] = v; });
                out.rowTexts[x.r] = t;
            });
            return out;
        };

        /* ---------- 填寫模式：鎖定非待填儲存格 ---------- */
        if (mode === 'fill') {
            api.addEvent(api.Event.BeforeSheetEditStart, function (p) {
                if (internal) return;
                if (!ctl.isEditable(p.row, p.column)) { p.cancel = true; toast('這一格不能修改（只有淡黃色的格子可以填寫）'); }
            });
            try {
                api.addEvent(api.Event.BeforeClipboardPaste, function (p) {
                    if (internal) return;
                    var rg = ws().getActiveRange && ws().getActiveRange();
                    if (!rg) return;
                    for (var r = rg.getRow(); r <= rg.getLastRow(); r++) for (var c = rg.getColumn(); c <= rg.getLastColumn(); c++)
                        if (!ctl.isEditable(r, c)) { p.cancel = true; toast('貼上範圍包含不能修改的格子'); return; }
                });
            } catch (e) {}
            // 安全網：任何指令做完，若有「非待填格」的內容被改（刪除、拖曳填滿等），整步復原
            var baseline = null, undoing = false;
            function snapLocked() {
                var cd = firstSheet(wb.save()).sheet.cellData || {}, m = {};
                Object.keys(cd).forEach(function (r) {
                    Object.keys(cd[r] || {}).forEach(function (c) {
                        if (ctl.isEditable(+r, +c)) return;
                        var cell = cd[r][c] || {};
                        m[r + ',' + c] = cell.f ? 'f:' + cell.f : (cell.v == null ? '' : 'v:' + cell.v);
                    });
                });
                return m;
            }
            ctl.armGuard = function () { baseline = snapLocked(); };
            wb.onCommandExecuted(function (cmd) {
                if (internal || undoing || !baseline) return;
                if (!/set-range-values|clear|delete|remove|paste|auto-fill|move|insert|merge/i.test(cmd.id || '')) return;
                setTimeout(function () {
                    if (internal || undoing) return;
                    var now = snapLocked(), bad = false, k;
                    for (k in baseline) { if (baseline[k] !== now[k]) { bad = true; break; } }
                    if (!bad) for (k in now) { if (baseline[k] !== now[k]) { bad = true; break; } }
                    if (bad) { undoing = true; try { wb.undo(); } catch (e) {} setTimeout(function () { undoing = false; }, 200); toast('這一格不能修改，已復原'); }
                    else baseline = now;
                }, 30);
            });
        }

        /* ---------- 設計模式 ---------- */
        function eachSelCell(fn) {
            var rg = wb.getActiveRange(); if (!rg) return 0;
            var merges = ws().getMergedRanges() || [], n = 0;
            for (var r = rg.getRow(); r <= rg.getLastRow(); r++) for (var c = rg.getColumn(); c <= rg.getLastColumn(); c++) {
                var skip = merges.some(function (m) { return r >= m.getRow() && r <= m.getLastRow() && c >= m.getColumn() && c <= m.getLastColumn() && !(r === m.getRow() && c === m.getColumn()); });
                if (!skip) { fn(r, c); n++; }
            }
            return n;
        }
        function nextId() {
            var max = 0; ctl.listFields().forEach(function (f) { var m = /^f(\d+)$/.exec(f.id); if (m) max = Math.max(max, +m[1]); });
            return 'f' + (max + 1);
        }
        function applyDV(rng, def) {
            var b = api.newDataValidation();
            try {
                if (def.t === 'select') rng.setDataValidation(b.requireValueInList(def.opt || [], false, true).setOptions({ showErrorMessage: true, errorStyle: 'stop' }).build());
                else if (def.t === 'person') rng.setDataValidation(b.requireValueInList(people, false, true).setOptions({ showErrorMessage: true, errorStyle: 'stop' }).build());
                else if (def.t === 'checkbox') rng.setDataValidation(b.requireCheckbox('1', '0').build());
                else if (def.t === 'date') rng.setDataValidation(b.requireDateOnOrAfter(new Date('2000-01-01')).setOptions({ showErrorMessage: true, errorStyle: 'stop' }).build());
                else if (def.t === 'number') rng.setDataValidation(b.requireNumberBetween(-1e15, 1e15).setOptions({ showErrorMessage: true, errorStyle: 'stop' }).build());
                else rng.setDataValidation(null);
            } catch (e) { try { rng.setDataValidation(null); } catch (e2) {} }
        }
        ctl.listFields = function () {
            var cd = firstSheet(wb.save()).sheet.cellData || {}, out = [];
            Object.keys(cd).forEach(function (r) {
                Object.keys(cd[r] || {}).forEach(function (c) {
                    var sf = cd[r][c] && cd[r][c].custom && cd[r][c].custom.sf;
                    if (sf) out.push({ id: sf.id, r: +r, c: +c, t: sf.t, req: sf.req ? 1 : 0, opt: sf.opt || [], g: sf.g || '', label: sf.label || '' });
                });
            });
            out.sort(function (a, b) { return a.r - b.r || a.c - b.c; });
            return out;
        };
        ctl.getSelection = function () {
            var rg = wb.getActiveRange(); if (!rg) return null;
            return { r1: rg.getRow(), c1: rg.getColumn(), r2: rg.getLastRow(), c2: rg.getLastColumn() };
        };
        ctl.fieldAtCell = function (r, c) {
            var f = ctl.listFields().filter(function (x) { return x.r === r && x.c === c; });
            return f[0] || null;
        };
        ctl.applyField = function (def) {
            var rg = wb.getActiveRange(); if (!rg) return 0;
            var made = 0, cur = ctl.listFields();
            var ids = {}; cur.forEach(function (f) { ids[f.id] = 1; });
            var seq = 0; cur.forEach(function (f) { var m = /^f(\d+)$/.exec(f.id); if (m) seq = Math.max(seq, +m[1]); });
            eachSelCell(function (r, c) {
                var ex = cur.filter(function (x) { return x.r === r && x.c === c; })[0];
                var id = ex ? ex.id : 'f' + (++seq);
                var cell = ws().getRange(r, c);
                cell.setCustomMetaData({ sf: { id: id, t: def.t, req: def.req ? 1 : 0, opt: def.opt || [], g: def.g || '', label: def.label || '' } });
                var bgs = cell.getBackground && cell.getBackground();
                if (!bgs || /^#?(f{3}|f{6})$/i.test(String(bgs))) cell.setBackground(MARK);
                if (def.t === 'date') { try { cell.setNumberFormat('yyyy.mm.dd'); } catch (e) {} }
                applyDV(cell, def);
                made++;
            });
            return made;
        };
        ctl.clearField = function () {
            var n = 0;
            eachSelCell(function (r, c) {
                var cell = ws().getRange(r, c);
                try { cell.setCustomMetaData(null); } catch (e) { cell.setCustomMetaData({}); }
                var bg = cell.getBackground && cell.getBackground();
                if (bg && String(bg).toUpperCase() === MARK) { try { cell.setBackground(null); } catch (e) { cell.setBackground('#FFFFFF'); } }
                try { cell.setDataValidation(null); } catch (e) {}
                n++;
            });
            return n;
        };
        ctl.onSelection = function (cb) { api.addEvent(api.Event.SelectionChanged, function () { try { cb(); } catch (e) {} }); };

        /* ---------- 匯出（剝掉提示底色） ---------- */
        ctl.exportBook = function () { return tintBook(wb.save(), false); };

        /* ---------- 列印用 HTML ---------- */
        ctl.printTable = function () {
            var snap = tintBook(wb.save(), false), fsx = firstSheet(snap), sh = fsx.sheet, cd = sh.cellData || {}, styles = snap.styles || {};
            var maxR = 0, maxC = 0;
            Object.keys(cd).forEach(function (r) { Object.keys(cd[r] || {}).forEach(function (c) { if (+r > maxR) maxR = +r; if (+c > maxC) maxC = +c; }); });
            var merges = sh.mergeData || [];
            merges.forEach(function (m) { if (m.endRow > maxR) maxR = m.endRow; if (m.endColumn > maxC) maxC = m.endColumn; });
            var disp = ws().getRange(0, 0, maxR + 1, maxC + 1).getDisplayValues();
            var defW = sh.defaultColumnWidth || 88, defH = sh.defaultRowHeight || 24, colD = sh.columnData || {}, rowD = sh.rowData || {};
            var covered = {}, mapM = {};
            merges.forEach(function (m) {
                mapM[m.startRow + ',' + m.startColumn] = m;
                for (var r = m.startRow; r <= m.endRow; r++) for (var c = m.startColumn; c <= m.endColumn; c++) if (!(r === m.startRow && c === m.startColumn)) covered[r + ',' + c] = 1;
            });
            var cbox = {};
            fields.forEach(function (f) { if (f.t === 'checkbox') { if (f.g) { for (var i = 0; i <= extra; i++) cbox[(f.r + i) + ',' + f.c] = 1; } else cbox[rowOf(f) + ',' + f.c] = 1; } });
            var bdCss = function (b) {
                if (!b || !b.s) return null;
                var wd = { 1: '1px solid', 2: '1px dotted', 3: '1px dotted', 4: '1px dashed', 5: '1px dashed', 6: '1px dashed', 7: '3px double', 8: '2px solid', 9: '2px dashed', 13: '3px solid' }[b.s] || '1px solid';
                return wd + ' ' + ((b.cl && b.cl.rgb) || '#000');
            };
            var totalW = 0, cols = '';
            for (var c = 0; c <= maxC; c++) { var cw = (colD[c] && colD[c].w) || defW; if (colD[c] && colD[c].hd) cw = 0; totalW += cw; cols += '<col style="width:' + cw + 'px">'; }
            var html = '<table class="sfp" style="border-collapse:collapse;table-layout:fixed;width:' + totalW + 'px"><colgroup>' + cols + '</colgroup><tbody>';
            for (var r = 0; r <= maxR; r++) {
                var rh = (rowD[r] && rowD[r].h) || defH;
                if (rowD[r] && rowD[r].hd) continue;
                html += '<tr style="height:' + rh + 'px">';
                for (var c2 = 0; c2 <= maxC; c2++) {
                    if (colD[c2] && colD[c2].hd) continue;
                    if (covered[r + ',' + c2]) continue;
                    var cell = (cd[r] && cd[r][c2]) || {}, st = typeof cell.s === 'string' ? (styles[cell.s] || {}) : (cell.s || {});
                    var m = mapM[r + ',' + c2], css = [], attr = '';
                    if (m) { if (m.endColumn > m.startColumn) attr += ' colspan="' + (m.endColumn - m.startColumn + 1) + '"'; if (m.endRow > m.startRow) attr += ' rowspan="' + (m.endRow - m.startRow + 1) + '"'; }
                    if (st.bg && st.bg.rgb) css.push('background:' + st.bg.rgb);
                    if (st.cl && st.cl.rgb) css.push('color:' + st.cl.rgb);
                    css.push('font-size:' + Math.round((st.fs || 11) * 1.3333 * 10) / 10 + 'px');
                    css.push("font-family:" + (st.ff ? "'" + String(st.ff).replace(/['"]/g, '') + "'," : '') + "'Microsoft JhengHei','PMingLiU','DFKai-SB',sans-serif");
                    if (st.bl) css.push('font-weight:bold'); if (st.it) css.push('font-style:italic');
                    var deco = []; if (st.ul && st.ul.s) deco.push('underline'); if (st.st && st.st.s) deco.push('line-through'); if (deco.length) css.push('text-decoration:' + deco.join(' '));
                    var ht = st.ht, vt = st.vt, val = disp[r] ? disp[r][c2] : '';
                    var isNum = val !== '' && !isNaN(Number(String(val).replace(/,/g, ''))) && !cbox[r + ',' + c2];
                    css.push('text-align:' + ({ 1: 'left', 2: 'center', 3: 'right' }[ht] || (isNum ? 'right' : 'left')));
                    css.push('vertical-align:' + ({ 1: 'top', 2: 'middle', 3: 'bottom' }[vt] || 'bottom'));
                    if (st.tb === 3) css.push('white-space:pre-wrap;word-break:break-all'); else css.push('white-space:nowrap;overflow:hidden');
                    var bd = st.bd || {};
                    [['t', 'top'], ['r', 'right'], ['b', 'bottom'], ['l', 'left']].forEach(function (p) { var x = bdCss(bd[p[0]]); if (x) css.push('border-' + p[1] + ':' + x); });
                    css.push('padding:1px 3px');
                    if (cbox[r + ',' + c2]) { var v0 = cell.v; val = (v0 === 1 || v0 === '1' || v0 === true) ? '☑' : '☐'; }
                    html += '<td' + attr + ' style="' + css.join(';') + '">' + esc(val) + '</td>';
                }
                html += '</tr>';
            }
            html += '</tbody></table>';
            return { html: html, width: totalW };
        };

        ctl.dispose = function () { try { created.univer.dispose(); } catch (e) {} };

        // 載入填寫值：先依 extra 補回明細列（逐列等 Univer 處理完），再灌值，最後建立鎖定基準
        ctl.ready = Promise.resolve();
        if (mode !== 'design') {
            var want = extra; extra = 0;
            ctl.ready = (async function () {
                await sleep(120);
                if (want > 0) await ctl.addDetailRows(want);
                if (o.values) ctl.setValues(o.values);
                await sleep(80);
                if (ctl.armGuard) ctl.armGuard();
                return true;
            })();
        }
        return ctl;
    }

    /** 整頁列印視窗（A4/A3、橫直式、公司大標題＋表單名稱、頁碼左下／AS 編號右下，見 ai-rules/16） */
    function printWindow(ctl, meta) {
        var t = ctl.printTable();
        var land = meta.orientation !== 'portrait', a3 = meta.paper_size === 'A3';
        var pageW = (land ? (a3 ? 420 : 297) : (a3 ? 297 : 210)), margin = 10;
        var avail = Math.floor((pageW - margin * 2) * 96 / 25.4);
        var zoom = t.width > avail ? (avail / t.width) : 1;
        var css = '@page{size:' + (a3 ? 'A3' : 'A4') + ' ' + (land ? 'landscape' : 'portrait') + ';margin:' + margin + 'mm ' + margin + 'mm 16mm;' +
            '@bottom-left{content:"第 " counter(page) " 頁／共 " counter(pages) " 頁";font-size:11px;color:#333;}' +
            '@bottom-right{content:"' + String(meta.doc_no || '').replace(/"/g, '') + '";font-size:11px;color:#333;}}' +
            'body{margin:0;font-family:"Microsoft JhengHei","PMingLiU",sans-serif;color:#000}' +
            '.h1{text-align:center;font-size:20px;font-weight:bold;margin:0 0 2px}.h2{text-align:center;font-size:16px;font-weight:bold;margin:0 0 8px}' +
            '.wrap{zoom:' + zoom + '}table.sfp td{box-sizing:border-box}tr{page-break-inside:avoid}';
        var h = '<!doctype html><html><head><meta charset="utf-8"><title>' + esc(meta.title || '') + '</title><style>' + css + '</style></head><body>' +
            (meta.company ? '<div class="h1">' + esc(meta.company) + '</div>' : '') +
            '<div class="h2">' + esc(meta.title || '') + '</div><div class="wrap">' + t.html + '</div>' +
            '<script>window.onload=function(){setTimeout(function(){window.focus();window.print();},300);};<\/script></body></html>';
        var win = window.open('', '_blank');
        if (!win) { alert('瀏覽器擋住了列印視窗，請允許本站的彈出視窗後再按一次'); return false; }
        win.document.open(); win.document.write(h); win.document.close();
        return true;
    }

    w.EGSheetForm = { mount: mount, printWindow: printWindow, MARK: MARK, TYPE_LABEL: TYPE_LABEL, a1: a1, colName: colName };
})(window);
