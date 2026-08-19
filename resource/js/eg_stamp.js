// eg_stamp.js — 共用簽章印章產生器（抽自 views/QA/correction_order.php carStamp()/stampRow()）
// 依賴：jQuery（用於 HTML escape）、全域變數 window.__ownCompany（本公司全名，各頁自行查 customer_list.is_own_company=1 設定）
// 用法：EGStamp.stamp(name, date, isDeputy) 產生印章 HTML；EGStamp.row(stampHtml, leftHtml) 產生「簽章：」排版列
// 選用第5/6參數 dept, position：EGStamp.stamp(name, date, isDeputy, tplSchema, dept, position)——只有第4參數帶「圖章模板設計」的
// schema 且模板內有 {部門}/{職稱} token 才會顯示，掃描章／預設回墨印SVG本身沒有部門欄位、傳了也不影響。
// 掃描實體章（2026-07-23 圖章管理模組）：載入本檔時自動向 store_Stamp_API.php?action=asset_map 抓「姓名→掃描章」對照表，
// 有掃描章的人 stamp() 自動改用去背 PNG 底圖＋動態日期帶（白遮罩蓋舊日期再壓日期字）；沒上傳的人維持純 SVG 章。
// 對照表是非同步載入——已渲染在畫面上的章（span 帶 data-sname）會在載到後自動升級替換，各呼叫端不需改程式。
(function (global) {
    function esc(s) { return $('<div>').text(s == null ? '' : s).html(); }

    var API = '/EGsystem/src/store/store_Stamp_API.php';
    var ASSETS = null;   // 姓名 → {uid, top, bot, t}；null=尚未載入

    // 簽章印章 SVG（回墨印）：上=公司全名分兩列(上4字/下最多6字,自動縮字)、中=日期、下=人員中文名
    // 字體標楷體；不另顯示部門/職稱。isDeputy=true 時右下角加「代」字（代理人代簽）。
    // tplSchema：選用，某些模組(如會議紀錄的出席簽到/項目確認簽名)可在設定內指定改用「圖章模板設計」(eg_stamp_tpl.js)的樣式；
    // 有掃描實體章仍優先用掃描章(不受模板影響，因為那是真實蓋出來的章)，只有沒掃描章時才套用模板產生 SVG。
    // dept/position：選用，僅套用「圖章模板設計」且模板內有 {部門}/{職稱} token 時才會顯示；不影響掃描章與預設回墨印SVG(本無此欄位)。
    function stamp(name, date, isDeputy, tplSchema, dept, position) {
        var a = ASSETS && name ? ASSETS[name] : null;
        if (a) return scanStamp(name, date, isDeputy, a);
        if (tplSchema && global.EGStampTpl) return tplStamp(name, date, isDeputy, tplSchema, dept, position);
        return svgStamp(name, date, isDeputy);
    }

    // 填滿列高比例：模板可設定 fillRatio(0.1~1，預設0.9)＝蓋在有固定列高的格子裡時自動縮放成該比例；
    // noScale=true 則一律用模板設計的實際大小，不自動縮放（可能蓋出格子外）。見圖章管理「線上圖章設計」。
    function tplStamp(name, date, isDeputy, schema, dept, position) {
        var ctx = { company: global.__ownCompany || '', name: name || '', date: date || '', dept: dept || '', position: position || '' };
        var svg = global.EGStampTpl.render(schema, ctx);
        if (isDeputy) {
            var ratio = Math.min(3, Math.max(0.3, +schema.ratio || 1));
            var h = Math.round(100 * ratio);
            var color = schema.color || '#cf3a2b';
            svg = svg.replace('</svg>', '<text x="92" y="' + (h - 4) + '" text-anchor="end" font-size="13" fill="' + color
                + '" font-weight="bold" font-family="DFKai-SB,BiauKai,KaiTi,&quot;標楷體&quot;,serif">代</text></svg>');
        }
        var fillPct = schema.noScale ? null : Math.round((schema.fillRatio != null ? +schema.fillRatio : 0.9) * 100);
        return wrap(svg, name, date, isDeputy, fillPct);
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

    // fillPct：非 null 時＝在有固定高度的容器(如表格列)內自動縮放成該容器高度的百分比(容器沒有明確高度時此設定不生效，維持原樣大小)；
    // null＝不縮放，一律用 svg 本身設計的大小（noScale 或掃描章/泛用SVG章走這條路徑，維持既有行為不受影響）。
    function wrap(svg, name, date, isDeputy, fillPct) {
        var cls = 'stamp-wrap' + (fillPct != null ? ' stamp-fill' : '');
        var style = fillPct != null ? ' style="height:' + fillPct + '%;"' : '';
        return '<span class="' + cls + '"' + style + ' data-sname="' + esc(name || '') + '" data-sdate="' + esc(date || '') + '" data-sdep="' + (isDeputy ? 1 : 0) + '">' + svg + '</span>';
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
    // READY：對照表「已經有結果」（成功或失敗都算）的通知點。要把章轉成圖片存檔（如表單簽核設計器匯出 PDF）
    // 的頁面必須等這個，否則對照表還沒回來就先畫，有掃描實體章的人會被存成預設 SVG 章，跟畫面上看到的不一樣。
    var READY_CBS = [], READY = false;
    function markReady() {
        if (READY) return;
        READY = true;
        var cbs = READY_CBS; READY_CBS = [];
        for (var i = 0; i < cbs.length; i++) { try { cbs[i](); } catch (e) {} }
    }
    function whenReady(cb) {
        if (typeof cb !== 'function') return;
        if (READY) { cb(); return; }
        READY_CBS.push(cb);
    }
    function loadAssets() {
        try {
            $.getJSON(API, { action: 'asset_map' })
                .done(function (r) {
                    if (r && r.ok && r.map) { ASSETS = r.map; upgradeRendered(document); }
                    markReady();
                })
                .fail(function () { markReady(); });
        } catch (e) { markReady(); }
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

    // 小型印章圖示（badge）：行事曆/列表等狹小處用的迷你章，非正式簽章。
    // kind：'sign'=「簽」字章（已簽核確認用）；'pending'=「申請中」章（請假送審中）。
    // 純 SVG 免圖檔；size 預設 16px，行事曆事件列可直接內嵌。title 給滑鼠提示（顏色非唯一資訊）。
    function badge(kind, size) {
        var FONT = "DFKai-SB,BiauKai,KaiTi,'標楷體',serif";
        size = size || 16;
        var svg;
        if (kind === 'pending') {
            // 「申請中」三字直排太擠，改橫式膠囊章：外框圓角矩形＋橫排文字（暖橘 #F0A24B 系）
            svg = '<svg viewBox="0 0 44 20" width="' + Math.round(size * 2.2) + '" height="' + size + '" xmlns="http://www.w3.org/2000/svg" style="vertical-align:-2px;">'
                + '<rect x="1.2" y="1.2" width="41.6" height="17.6" rx="4" fill="rgba(255,255,255,.75)" stroke="#d2691e" stroke-width="1.8"/>'
                + '<text x="22" y="14.6" text-anchor="middle" font-size="11.5" fill="#d2691e" font-weight="bold" font-family="' + FONT + '" textLength="34" lengthAdjust="spacingAndGlyphs">申請中</text>'
                + '</svg>';
            return '<span class="stamp-badge stamp-badge-pending" title="請假申請中（簽核尚未完成）">' + svg + '</span>';
        }
        // 預設 'sign'：圓形「簽」字章（印泥紅，同正式章色系）
        svg = '<svg viewBox="0 0 20 20" width="' + size + '" height="' + size + '" xmlns="http://www.w3.org/2000/svg" style="vertical-align:-2px;">'
            + '<circle cx="10" cy="10" r="8.8" fill="rgba(255,255,255,.75)" stroke="#cf3a2b" stroke-width="1.6"/>'
            + '<text x="10" y="14" text-anchor="middle" font-size="11" fill="#cf3a2b" font-weight="bold" font-family="' + FONT + '">簽</text>'
            + '</svg>';
        return '<span class="stamp-badge stamp-badge-sign" title="已簽核">' + svg + '</span>';
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
            '.stamp-wrap .stamp-title{ display:block; font-size:11px; color:#999; margin-top:1px; }' +
            // stamp-fill：容器(如表格列)有明確高度時，章依 fillRatio 縮放至該高度比例；容器無明確高度時 height:% 無效，退回原尺寸不受影響
            '.stamp-wrap.stamp-fill{ display:inline-flex; align-items:center; margin:0; }' +
            '.stamp-wrap.stamp-fill svg,.stamp-wrap.stamp-fill img{ height:100%; width:auto; max-width:none; }';
        document.head.appendChild(style);
    }
    injectCss();
    loadAssets();

    global.EGStamp = {
        stamp: stamp, row: row, badge: badge,                        // badge('sign'|'pending', size)：迷你章圖示（行事曆/列表用）
        scan: scanStamp, svg: svgStamp,                              // 圖章管理頁預覽用（可帶暫時的日期帶值）
        setAssets: function (m) { ASSETS = m || {}; upgradeRendered(document); markReady(); },
        hasAsset: function (name) { return !!(ASSETS && ASSETS[name]); },
        upgrade: upgradeRendered,                                    // 動態插入大量章後可手動再跑一次
        whenReady: whenReady                                         // 掃描章對照表載入完成（成功/失敗皆算）後回呼；要把章轉圖存檔的頁面必須等這個
    };
})(window);
