// eg_stamp_tpl.js — 線上圖章「模板」渲染器（圖章管理頁設計器與批圖編輯器蓋章共用）
// 與 eg_stamp.js（簽核回墨章）互不相依。schema 由 stamp_template.schema_json 儲存：
// { shape:'circle|ellipse|rect|roundrect', color:'#cf3a2b', size:100, ratio:1, stroke:2.6, font:'kai|ming|hei',
//   rows:[ {h:30, text:'{部門}'}, {h:40, text:'{日期}'}, {h:30, text:'{姓名}'} ] }   // h=高度%（自動正規化）
// text 內可混用固定字樣與變數 token：{部門} {職稱} {姓名} {日期} {編號}
// ctx = { dept, position, name, date, serial }（date/serial 由呼叫端先算好字串）
(function (global) {
    var FONTS = {
        kai:  "DFKai-SB,BiauKai,KaiTi,'標楷體',serif",
        ming: "PMingLiU,'新細明體','Times New Roman',serif",
        hei:  "'Microsoft JhengHei','微軟正黑體',Arial,sans-serif"
    };
    function esc(s) {
        return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    function fill(text, ctx) {
        ctx = ctx || {};
        return String(text || '')
            .replace(/\{部門\}/g,  ctx.dept    == null ? '' : ctx.dept)
            .replace(/\{職稱\}/g,  ctx.position== null ? '' : ctx.position)
            .replace(/\{姓名\}/g,  ctx.name    == null ? '' : ctx.name)
            .replace(/\{日期\}/g,  ctx.date    == null ? '' : ctx.date)
            .replace(/\{編號\}/g,  ctx.serial  == null ? '' : ctx.serial);
    }
    function hasSerial(schema) {
        return (schema.rows || []).some(function (r) { return String(r.text || '').indexOf('{編號}') >= 0; });
    }
    // 外框內某一水平線的可用半寬（圓/橢圓用弦長，矩形固定）
    function halfWidthAt(schema, y, W, H) {
        var shape = schema.shape || 'circle';
        if (shape === 'rect' || shape === 'roundrect') return W / 2 - 7;
        var rx = W / 2 - 3, ry = H / 2 - 3, dy = (y - H / 2) / ry;
        var k = Math.max(0, 1 - dy * dy);
        return Math.max(6, rx * Math.sqrt(k) - 2);
    }
    // render(schema, ctx) → SVG 字串。寬 schema.size px、高依 ratio。
    function render(schema, ctx) {
        schema = schema || {};
        var color  = schema.color || '#cf3a2b';
        var stroke = +schema.stroke || 2.6;
        var ratio  = Math.min(3, Math.max(0.3, +schema.ratio || 1));
        var W = 100, H = Math.round(100 * ratio);
        var size = Math.min(600, Math.max(24, +schema.size || 100));
        var font = FONTS[schema.font] || FONTS.kai;
        var shape = schema.shape || 'circle';
        var svg = '<svg class="eg-stamp-tpl" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' + W + ' ' + H + '" width="' + size + '" height="' + Math.round(size * ratio) + '">';
        // 外框
        if (shape === 'circle' && ratio === 1) {
            svg += '<circle cx="50" cy="50" r="47" fill="none" stroke="' + color + '" stroke-width="' + stroke + '"/>';
        } else if (shape === 'circle' || shape === 'ellipse') {
            svg += '<ellipse cx="' + (W/2) + '" cy="' + (H/2) + '" rx="' + (W/2-3) + '" ry="' + (H/2-3) + '" fill="none" stroke="' + color + '" stroke-width="' + stroke + '"/>';
        } else if (shape === 'roundrect') {
            svg += '<rect x="3" y="3" width="' + (W-6) + '" height="' + (H-6) + '" rx="9" fill="none" stroke="' + color + '" stroke-width="' + stroke + '"/>';
        } else if (shape === 'rect') {
            svg += '<rect x="3" y="3" width="' + (W-6) + '" height="' + (H-6) + '" fill="none" stroke="' + color + '" stroke-width="' + stroke + '"/>';
        }
        // 分割列（h% 自動正規化到內部高度）
        var rows = (schema.rows || []).filter(function (r) { return r; });
        if (!rows.length) rows = [{ h: 100, text: '' }];
        var totalH = rows.reduce(function (s, r) { return s + (+r.h > 0 ? +r.h : 1); }, 0);
        var innerTop = 3 + stroke, innerBot = H - 3 - stroke, innerH = innerBot - innerTop;
        var y = innerTop;
        for (var i = 0; i < rows.length; i++) {
            var rh = innerH * ((+rows[i].h > 0 ? +rows[i].h : 1) / totalH);
            var y2 = y + rh;
            // 列間分隔線（貼齊外框內緣）
            if (i > 0 && shape !== 'none') {
                var hw = halfWidthAt(schema, y, W, H);
                svg += '<line x1="' + (W/2 - hw).toFixed(1) + '" y1="' + y.toFixed(1) + '" x2="' + (W/2 + hw).toFixed(1) + '" y2="' + y.toFixed(1) + '" stroke="' + color + '" stroke-width="' + Math.max(1, stroke * 0.55).toFixed(2) + '"/>';
            }
            var txt = fill(rows[i].text, ctx).trim();
            if (txt) {
                var cy = (y + y2) / 2;
                var maxW = 2 * halfWidthAt(schema, cy, W, H) - 4;
                var fs = Math.min(rh * 0.62, maxW / Math.max(1, txt.length) * 1.15);
                fs = Math.max(5, fs);
                var needFit = txt.length * fs > maxW;
                svg += '<text x="' + (W/2) + '" y="' + (cy + fs * 0.36).toFixed(1) + '" text-anchor="middle" font-size="' + fs.toFixed(1) + '" fill="' + color + '" font-weight="bold" font-family="' + font + '"'
                     + (needFit ? ' textLength="' + maxW.toFixed(1) + '" lengthAdjust="spacingAndGlyphs"' : '')
                     + '>' + esc(txt) + '</text>';
            }
            y = y2;
        }
        svg += '</svg>';
        return svg;
    }
    global.EGStampTpl = { render: render, fill: fill, hasSerial: hasSerial, FONTS: FONTS };
})(window);
