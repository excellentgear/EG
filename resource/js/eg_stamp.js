// eg_stamp.js — 共用簽章印章產生器（抽自 views/QA/correction_order.php carStamp()/stampRow()）
// 依賴：jQuery（用於 HTML escape）、全域變數 window.__ownCompany（本公司全名，各頁自行查 customer_list.is_own_company=1 設定）
// 用法：EGStamp.stamp(name, date, isDeputy) 產生印章 HTML；EGStamp.row(stampHtml, leftHtml) 產生「簽章：」排版列
// 掃描實體章（2026-07-23 圖章管理模組）：載入本檔時自動向 store_Stamp_API.php?action=asset_map 抓「姓名→掃描章」對照表，
// 有掃描章的人 stamp() 自動改用去背 PNG 底圖＋動態日期帶（白遮罩蓋舊日期再壓日期字）；沒上傳的人維持純 SVG 章。
// 對照表是非同步載入——已渲染在畫面上的章（span 帶 data-sname）會在載到後自動升級替換，各呼叫端不需改程式。
(function (global) {
    function esc(s) { return $('<div>').text(s == null ? '' : s).html(); }

    var API = '/EGsystem/src/store/store_Stamp_API.php';
    var ASSETS = null;   // 姓名 → {uid, top, bot, t}；null=尚未載入

    // 簽章印章 SVG（回墨印）：上=公司全名分兩列(上4字/下最多6字,自動縮字)、中=日期、下=人員中文名
    // 字體標楷體；不另顯示部門/職稱。isDeputy=true 時右下角加「代」字（代理人代簽）。
    function stamp(name, date, isDeputy) {
        var a = ASSETS && name ? ASSETS[name] : null;
        if (a) return scanStamp(name, date, isDeputy, a);
        return svgStamp(name, date, isDeputy);
    }

    // 掃描實體章：去背 PNG 當底圖，日期帶區域鋪白遮罩蓋掉掃描件上的舊日期，再壓動態日期（圖章系統說明.md 第二節）
    // asset: {uid, top, bot, t}（top/bot=日期帶上下緣，相對圖高百分比；t=檔案mtime做快取破壞）
    function scanStamp(name, date, isDeputy, asset) {
        var FONT = "DFKai-SB,BiauKai,KaiTi,'標楷體',serif";
        var FONT_NUM = "'Times New Roman','Courier New',serif";
        var top = Math.max(0, Math.min(100, +asset.top || 32));
        var bot = Math.max(top + 4, Math.min(100, +asset.bot || 66));
        var url = API + '?action=asset_img&user_id=' + encodeURIComponent(asset.uid) + '&t=' + encodeURIComponent(asset.t || 0);
        var size = Math.min(14.5, (bot - top) * 0.55);
        var midY = (top + bot) / 2 + size * 0.35;
        var svg = '<svg class="car-stamp" viewBox="0 0 100 100" width="76" height="76" xmlns="http://www.w3.org/2000/svg">'
            + '<image href="' + esc(url) + '" x="0" y="0" width="100" height="100" preserveAspectRatio="xMidYMid meet"/>'
            + (date ? '<rect x="16" y="' + top + '" width="68" height="' + (bot - top) + '" fill="#fff"/>'
                    + '<text x="50" y="' + midY.toFixed(1) + '" text-anchor="middle" font-size="' + size.toFixed(1) + '" fill="#cf3a2b" font-weight="bold" font-family="' + FONT_NUM + '">' + esc(date) + '</text>' : '')
            + (isDeputy ? '<text x="80" y="93" text-anchor="middle" font-size="12" fill="#cf3a2b" font-weight="bold" font-family="' + FONT + '">代</text>' : '')
            + '</svg>';
        return wrap(svg, name, date, isDeputy);
    }

    function wrap(svg, name, date, isDeputy) {
        return '<span class="stamp-wrap" data-sname="' + esc(name || '') + '" data-sdate="' + esc(date || '') + '" data-sdep="' + (isDeputy ? 1 : 0) + '">' + svg + '</span>';
    }

    function svgStamp(name, date, isDeputy) {
        var company = global.__ownCompany || '';
        var l1 = company.substring(0, 4), l2 = company.substring(4);
        var FONT = "DFKai-SB,BiauKai,KaiTi,'標楷體',serif";           // 中文：標楷體
        var FONT_NUM = "'Times New Roman','Courier New',serif";   // 日期：襯線數字（7 有下勾，仿日期字輪）
        // 依字數自動縮小（textLength 壓縮到可用寬度內）
        function fit(txt, y, baseSize, maxW, font) {
            if (!txt) return '';
            return '<text x="50" y="' + y + '" text-anchor="middle" font-size="' + baseSize + '" fill="#cf3a2b" font-weight="bold" font-family="' + (font || FONT) + '"'
                 + (txt.length * baseSize > maxW ? ' textLength="' + maxW + '" lengthAdjust="spacingAndGlyphs"' : '')
                 + '>' + esc(txt) + '</text>';
        }
        // 橫線端點貼齊外圓內緣（以弦長計算），整顆印章成一體、縮放比例固定
        // 區塊比例（總高 2.3cm）：公司名 0.6cm / 日期 1.0cm / 人名 0.7cm → 分隔線 y≈27.5、68.5
        function chord(y) { var r = 45.7, dy = y - 50, dx = Math.sqrt(Math.max(0, r * r - dy * dy)); return { x1: (50 - dx).toFixed(1), x2: (50 + dx).toFixed(1) }; }
        var c1 = chord(27.5), c2 = chord(68.5);
        var svg = '<svg class="car-stamp" viewBox="0 0 100 100" width="76" height="76" xmlns="http://www.w3.org/2000/svg">'
            + '<circle cx="50" cy="50" r="47" fill="none" stroke="#cf3a2b" stroke-width="2.6"/>'
            + fit(l1, 15, 11, 58)            // 公司名第一列（4字，加大）
            + fit(l2, 26, 11.5, 76)          // 公司名第二列（最多6字，超過自動壓縮，加大）
            + '<line x1="' + c1.x1 + '" y1="27.5" x2="' + c1.x2 + '" y2="27.5" stroke="#cf3a2b" stroke-width="1.4"/>'
            + '<line x1="' + c2.x1 + '" y1="68.5" x2="' + c2.x2 + '" y2="68.5" stroke="#cf3a2b" stroke-width="1.4"/>'
            + fit(date || '', 54.5, 14.5, 72, FONT_NUM)   // 簽章日期（襯線數字、置中）
            + fit(name || '', 84.5, (name || '').length > 3 ? 15 : 19, 56)   // 人員中文名（加大、貼近上方分隔線）
            + (isDeputy ? '<text x="80" y="93" text-anchor="middle" font-size="12" fill="#cf3a2b" font-weight="bold" font-family="' + FONT + '">代</text>' : '')   // 代理簽章標記（右下角）
            + '</svg>';
        return wrap(svg, name, date, isDeputy);
    }

    // 對照表載入（非同步）：載到後把畫面上已渲染的章升級成掃描章；載入失敗一律回退純 SVG，不影響簽核顯示
    function loadAssets() {
        try {
            $.getJSON(API, { action: 'asset_map' }).done(function (r) {
                if (!r || !r.ok || !r.map) return;
                ASSETS = r.map;
                upgradeRendered(document);
            });
        } catch (e) {}
    }
    function upgradeRendered(root) {
        if (!ASSETS) return;
        var nodes = (root || document).querySelectorAll('.stamp-wrap[data-sname]');
        for (var i = 0; i < nodes.length; i++) {
            var n = nodes[i], nm = n.getAttribute('data-sname');
            if (nm && ASSETS[nm]) {
                var tmp = document.createElement('span');
                tmp.innerHTML = stamp(nm, n.getAttribute('data-sdate') || '', n.getAttribute('data-sdep') === '1');
                n.parentNode.replaceChild(tmp.firstChild, n);
            }
        }
    }

    // 簽章列：右側=「簽章：」緊貼印章；左側可帶欄位內左下角文字（如 預定完成日）
    function row(stampHtml, leftHtml) {
        return '<div style="display:flex;justify-content:space-between;align-items:flex-end;margin-top:2px;">'
             + '<span style="font-size:12px;color:#777;">' + (leftHtml || '') + '</span>'
             + '<span style="display:inline-flex;align-items:flex-end;gap:4px;">'
             + '<span class="text-muted" style="font-size:12px;line-height:1;margin-bottom:8px;">簽章：</span>' + stampHtml + '</span></div>';
    }

    // 印章相關 CSS 自動注入，載入本檔的頁面不需要自己重複定義
    function injectCss() {
        if (document.getElementById('eg-stamp-css')) return;
        var style = document.createElement('style');
        style.id = 'eg-stamp-css';
        style.textContent =
            '.car-stamp{ opacity:.92; vertical-align:middle; filter:drop-shadow(0 1px 1px rgba(0,0,0,.1)); }' +
            '.stamp-wrap{ display:inline-block; text-align:center; margin:2px 10px 2px 0; }' +
            '.stamp-wrap .stamp-title{ display:block; font-size:11px; color:#999; margin-top:1px; }';
        document.head.appendChild(style);
    }
    injectCss();
    loadAssets();

    global.EGStamp = {
        stamp: stamp, row: row,
        scan: scanStamp, svg: svgStamp,                              // 圖章管理頁預覽用（可帶暫時的日期帶值）
        setAssets: function (m) { ASSETS = m || {}; upgradeRendered(document); },
        hasAsset: function (name) { return !!(ASSETS && ASSETS[name]); },
        upgrade: upgradeRendered                                     // 動態插入大量章後可手動再跑一次
    };
})(window);
