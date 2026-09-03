// eg_stamp_tpl.js — 線上圖章「模板」渲染器（圖章管理頁設計器與批圖編輯器蓋章共用）
// 與 eg_stamp.js（簽核回墨章）互不相依。schema 由 stamp_template.schema_json 儲存：
// { shape:'circle|ellipse|rect|roundrect', color:'#cf3a2b', size:100, ratio:1, stroke:2.6, font:'kai|ming|hei',
//   rows:[ {h:30, text:'{部門}', fs:0, mode:'shrink', wrapn:0}, ... ] }   // h=高度%（自動正規化）
//   fs=字級（viewBox 單位，章寬=100；0/未填=自動）；mode='shrink' 超寬時壓縮字距（預設）｜'wrap' 超寬時自動換列
//   wrapn=換列模式的「第一列字數」：前 N 字放第一列、其餘放第二列（超寬壓縮），字級依列數自動放大填滿；0/未填=依寬度自動折行
// text 內可混用固定字樣與變數 token：{部門} {部門簡稱} {職稱} {姓名} {日期} {編號}
// {部門簡稱}＝去掉部門名尾端常見組織後綴（中心/總部/分部/部/組/課/室/廠/處/隊/站）：生管組→生管、設計開發課→設計開發
// ctx = { dept, position, name, date, serial }（date/serial 由呼叫端先算好字串）
(function (global) {
    var FONTS = {
        kai:  "DFKai-SB,BiauKai,KaiTi,'標楷體',serif",
        ming: "PMingLiU,'新細明體','Times New Roman',serif",
        hei:  "'Microsoft JhengHei','微軟正黑體',Arial,sans-serif"
    };
    // 長後綴排前面優先比對（如「中心」要整組去掉，不能只去掉「心」）
    var DEPT_SUFFIX = ['中心', '總部', '分部', '部', '組', '課', '室', '廠', '處', '隊', '站'];
    function deptShort(name) {
        name = String(name || '');
        for (var i = 0; i < DEPT_SUFFIX.length; i++) {
            var s = DEPT_SUFFIX[i];
            if (name.length > s.length && name.slice(-s.length) === s) return name.slice(0, -s.length);
        }
        return name;
    }
    function esc(s) {
        return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    function fill(text, ctx) {
        ctx = ctx || {};
        return String(text || '')
            .replace(/\{公司\}/g,   ctx.company == null ? '' : ctx.company)
            .replace(/\{部門簡稱\}/g, ctx.dept   == null ? '' : deptShort(ctx.dept))
            .replace(/\{部門\}/g,   ctx.dept    == null ? '' : ctx.dept)
            .replace(/\{職稱\}/g,   ctx.position== null ? '' : ctx.position)
            .replace(/\{姓名\}/g,   ctx.name    == null ? '' : ctx.name)
            .replace(/\{日期\}/g,   ctx.date    == null ? '' : ctx.date)
            .replace(/\{編號\}/g,   ctx.serial  == null ? '' : ctx.serial);
    }
    function hasSerial(schema) {
        return (schema.rows || []).some(function (r) { return String(r.text || '').indexOf('{編號}') >= 0; });
    }
    // 這顆模板實際用到哪些「被登記對象」變數：呼叫端據此決定要不要顯示/要求選「對象」
    // （例如純固定字樣的模板完全不需要選對象；只用{部門}的模板不需要強求選到有人名的個人章）
    function usesTokens(schema) {
        var all = (schema.rows || []).map(function (r) { return String(r.text || ''); }).join('');
        return { name: all.indexOf('{姓名}') >= 0, dept: (all.indexOf('{部門}') >= 0 || all.indexOf('{部門簡稱}') >= 0), position: all.indexOf('{職稱}') >= 0 };
    }
    function needsHolder(schema) {
        var t = usesTokens(schema);
        return t.name || t.dept || t.position;
    }
    // 外框內某一水平線的可用半寬（圓/橢圓用弦長，矩形固定）
    function halfWidthAt(schema, y, W, H) {
        var shape = schema.shape || 'circle';
        if (shape === 'rect' || shape === 'roundrect') return W / 2 - 7;
        var rx = W / 2 - 3, ry = H / 2 - 3, dy = (y - H / 2) / ry;
        var k = Math.max(0, 1 - dy * dy);
        return Math.max(6, rx * Math.sqrt(k) - 2);
    }
    /**
     * 一列文字實際可用的半寬。
     * 2026-09-02 使用者回報「公司名稱顯示不完全」：原本一律用「列中心那條線」的弦長當可用寬度，
     * 但圓／橢圓越靠上下緣越窄，字是有高度的——靠近上下緣那幾列（公司名、人名）的字會壓到外框。
     * 這裡改成取「整個字高跨過的範圍內最窄處」，也就是離圓心較遠的那一端。
     */
    function halfWidthForText(schema, cy, fs, W, H) {
        var shape = schema.shape || 'circle';
        if (shape === 'rect' || shape === 'roundrect' || shape === 'none') return halfWidthAt(schema, cy, W, H);
        var half = fs * 0.45;                       // 中文字的墨水高度約佔字級的九成
        var top = cy - half, bot = cy + half;
        var far = (Math.abs(top - H / 2) > Math.abs(bot - H / 2)) ? top : bot;
        return Math.min(halfWidthAt(schema, cy, W, H), halfWidthAt(schema, far, W, H));
    }
    /**
     * 讓一列文字塞進外框內：先容許縮小字級最多 15%（保持字形比例），
     * 還是不夠才用 textLength 壓字距（跟原本的作法一致）。
     * 回 { fs, maxW }。
     */
    /* lockFs（2026-09-03 使用者回報「印章內文字大小沒有套用圖章模板內的規定」）：
       使用者在圖章模板設計裡「明確填了字級」時，這裡**不可以再自動縮小字級**——本檔開頭就寫明
       `mode='shrink' 超寬時壓縮字距`，超寬本來就該交給下面的 textLength/lengthAdjust 去壓字距，
       而不是先把字級砍到 85% 再壓一次（等於縮兩次）。實測模板1 設 11.3/14.5，印出來卻是 9.6/12.3
       （正好是 ×0.85），比系統預設回墨印（11/14.5）還小一圈，所以蓋出來明顯比設計器裡看到的空。
       字級留空＝自動的列不受影響，維持原本的自動縮放行為。 */
    function fitRow(schema, cy, fs, len, W, H, lockFs) {
        len = Math.max(1, len);
        var w = 2 * halfWidthForText(schema, cy, fs, W, H) - 4;
        if (len * fs <= w) return { fs: fs, maxW: w };
        if (lockFs) return { fs: fs, maxW: w };
        var min = Math.max(5, fs * 0.85);
        for (var k = 0; k < 6 && fs > min; k++) {
            fs = Math.max(min, fs * 0.97);
            w = 2 * halfWidthForText(schema, cy, fs, W, H) - 4;
            if (len * fs <= w) break;
        }
        return { fs: fs, maxW: w };
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
                var userFs = +rows[i].fs > 0 ? +rows[i].fs : 0;
                if (rows[i].mode === 'wrap') {
                    var wrapn = Math.max(0, Math.floor(+rows[i].wrapn || 0));
                    var lines, n, fs;
                    if (wrapn > 0) {
                        // 指定第一列字數：前 N 字第一列、其餘第二列（超寬以 textLength 壓縮）；字級依列數自動放大填滿（同現有回墨章公司名排法）
                        lines = txt.length > wrapn ? [txt.substr(0, wrapn), txt.substr(wrapn)] : [txt];
                        n = lines.length;
                        fs = userFs || Math.max(5, (rh / n) * 0.9);
                    } else {
                        // 未指定：依可用寬度自動折行，行數放不下再逐步縮小
                        fs = userFs || rh * 0.62; lines = [txt]; n = 1;
                        for (var it = 0; it < 6; it++) {
                            var cpl = Math.max(1, Math.floor(maxW / fs));
                            lines = [];
                            for (var p = 0; p < txt.length; p += cpl) lines.push(txt.substr(p, cpl));
                            n = lines.length;
                            if (n * fs * 1.08 <= rh || fs <= 5) break;
                            fs = Math.max(5, Math.min(fs * 0.85, (rh / n) / 1.08));
                        }
                    }
                    var lh = Math.min(fs * 1.15, rh / n);
                    var startY = cy - lh * (n - 1) / 2;
                    for (var li = 0; li < n; li++) {
                        var ly = startY + li * lh;
                        var lf = fitRow(schema, ly, fs, lines[li].length, W, H, userFs > 0 && fs === userFs);
                        var lFit = lines[li].length * lf.fs > lf.maxW;
                        svg += '<text x="' + (W/2) + '" y="' + (ly + lf.fs * 0.36).toFixed(1) + '" text-anchor="middle" font-size="' + lf.fs.toFixed(1) + '" fill="' + color + '" font-weight="bold" font-family="' + font + '"'
                             + (lFit ? ' textLength="' + lf.maxW.toFixed(1) + '" lengthAdjust="spacingAndGlyphs"' : '')
                             + '>' + esc(lines[li]) + '</text>';
                    }
                } else {
                    // 自動縮小（預設）：單行置中，超寬時以 textLength 壓縮字距；自動字級取列高 0.68 盡量填滿（同現有回墨章比例）
                    var fs2 = userFs || Math.min(rh * 0.68, maxW / Math.max(1, txt.length) * 1.15);
                    fs2 = Math.max(5, Math.min(fs2, rh * 0.92));
                    // fs2 若已被列高 rh*0.92 壓過就不算「使用者指定的字級」（例：模板9 設 30 但列高只容得下 18.4），
                    // 那種情況維持原本的自動縮放，避免字撐出格子外
                    var rf = fitRow(schema, cy, fs2, txt.length, W, H, userFs > 0 && fs2 === userFs);
                    var needFit = txt.length * rf.fs > rf.maxW;
                    svg += '<text x="' + (W/2) + '" y="' + (cy + rf.fs * 0.36).toFixed(1) + '" text-anchor="middle" font-size="' + rf.fs.toFixed(1) + '" fill="' + color + '" font-weight="bold" font-family="' + font + '"'
                         + (needFit ? ' textLength="' + rf.maxW.toFixed(1) + '" lengthAdjust="spacingAndGlyphs"' : '')
                         + '>' + esc(txt) + '</text>';
                }
            }
            y = y2;
        }
        svg += '</svg>';
        return svg;
    }
    global.EGStampTpl = { render: render, fill: fill, hasSerial: hasSerial, usesTokens: usesTokens, needsHolder: needsHolder, deptShort: deptShort, FONTS: FONTS };
})(window);
