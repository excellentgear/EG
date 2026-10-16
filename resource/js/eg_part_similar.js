/**
 * eg_part_similar.js —— 「類似料號」提示的共用前端（2026-10-16，唯一實作，禁止各頁自刻）
 *
 * 後端：src/store/PartSimilar_API.php ＋ src/common/part_similar_lib.php
 * 用法：
 *   EGPartSimilar.prompt({dId, ctx:'quote'|'order'|'master', onceKey})  綁定料號後跳提示（有 A/B 級才跳；只有 C 級給小橫幅）
 *   EGPartSimilar.panel(el, {orderId|dId, ctx:'design'})               把結果畫在指定容器（設計備註用）
 *   EGPartSimilar.compare(aDId, bDId)                                  開比對跳窗
 *   EGPartSimilar.openSettings()                                       管理員設定（比對哪些標籤）
 *   EGPartSimilar.resetOnce()                                          換一張報價單／訂單時清掉「已提示過」記錄
 * 設計原則：這是輔助提示。任何失敗（網路、後端錯誤）一律安靜略過，絕不影響呼叫它的頁面。
 */
(function (w) {
    'use strict';
    if (w.EGPartSimilar) return;

    // 由本檔網址推出網站根（…/EGsystem/），這樣不管哪個目錄的頁面載入都指得到 API 與圖面頁
    var ROOT = (function () {
        try {
            var ss = document.getElementsByTagName('script');
            for (var i = ss.length - 1; i >= 0; i--) {
                var s = ss[i].src || '';
                var k = s.indexOf('resource/js/eg_part_similar.js');
                if (k >= 0) return s.substring(0, k);
            }
        } catch (e) {}
        return '../../';
    })();
    var API = ROOT + 'src/store/PartSimilar_API.php';
    var VIEWER = ROOT + 'views/pm/bom_viewer.php';

    var onceSet = {};
    var LEVEL = { B: ['齒輪＋總長＋標籤相同', '#DD5138', '#fff'], A: ['齒輪＋總長相同', '#F0A24B', '#4a2e0a'], C: ['齒輪相同・總長未填', '#EADFCD', '#6b5638'] };

    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function dateFmt(s) { return (w.egFmtDate ? w.egFmtDate(s) : (s || '')); }

    function injectCss() {
        if (document.getElementById('egps-css')) return;
        var css = [
            '.egps-mask{position:fixed;left:0;top:0;right:0;bottom:0;background:rgba(60,40,15,.45);z-index:10600;display:flex;align-items:flex-start;justify-content:center;overflow:auto;padding:40px 0;}',
            '.egps-box{background:#fff;border-radius:6px;box-shadow:0 8px 28px rgba(0,0,0,.3);width:820px;max-width:96%;color:#3d2c17;font-size:13px;font-family:inherit;line-height:1.5;}',
            '.egps-box.sm{width:560px;} .egps-box.lg{width:980px;}',
            '.egps-hd{background:#8a5a2b;color:#fff;padding:9px 14px;border-radius:6px 6px 0 0;display:flex;align-items:center;font-size:14px;font-weight:700;}',
            '.egps-hd .x{margin-left:auto;cursor:pointer;font-size:20px;line-height:1;padding:0 4px;}',
            '.egps-bd{padding:12px 14px;max-height:72vh;overflow:auto;}',
            '.egps-ft{padding:8px 14px;border-top:1px solid #EADFCD;text-align:right;}',
            '.egps-btn{display:inline-block;border:1px solid #c9a97a;background:#FFF3E2;color:#6b4a1e;border-radius:3px;padding:3px 10px;font-size:12px;cursor:pointer;margin-left:6px;}',
            '.egps-btn:hover{background:#F7E0BD;}',
            '.egps-card{border:1px solid #E4D3BC;border-radius:5px;padding:9px 11px;margin-bottom:10px;background:#FFFBF4;}',
            '.egps-card h5{margin:0 0 4px;font-size:13px;font-weight:700;display:flex;align-items:center;flex-wrap:wrap;gap:6px;}',
            '.egps-tag{display:inline-block;border-radius:3px;padding:0 6px;font-size:11px;line-height:18px;font-weight:600;}',
            '.egps-lnk{color:#1a5fa8;cursor:pointer;text-decoration:underline;}',
            '.egps-why{margin:3px 0 4px 16px;padding:0;font-size:12px;color:#6b5638;} .egps-why li{margin:0;}',
            '.egps-tb{width:100%;border-collapse:collapse;font-size:12px;margin-top:4px;}',
            '.egps-tb th{background:#F7E0BD;color:#4a2e0a;padding:3px 6px;text-align:left;border:1px solid #E4D3BC;}',
            '.egps-tb td{padding:3px 6px;border:1px solid #EADFCD;vertical-align:top;line-height:1.4;}',
            '.egps-tb td span,.egps-card h5 span{line-height:1.4;}',
            '.egps-diff td{background:#FDE6E0;} .egps-cfg td:first-child{box-shadow:inset 3px 0 0 #F0A24B;}',
            '.egps-grp td{background:#EADFCD;font-weight:700;color:#4a2e0a;}',
            '.egps-muted{color:#9b8566;font-size:12px;}',
            '.egps-banner{background:#FFF3E2;border:1px solid #F0A24B;border-left:5px solid #F0A24B;border-radius:4px;padding:6px 10px;margin:6px 0;font-size:12px;color:#6b4a1e;}',
            '.egps-tgl{cursor:pointer;color:#8a5a2b;font-size:12px;margin:2px 0 6px;display:inline-block;}',
            '.egps-chk{display:flex;align-items:center;gap:6px;padding:2px 0;} .egps-chk input{margin:0;}'
        ].join('');
        var st = document.createElement('style'); st.id = 'egps-css'; st.textContent = css;
        document.head.appendChild(st);
    }

    function post(data) {
        var body = Object.keys(data).map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(data[k]); }).join('&');
        return fetch(API, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body })
            .then(function (r) { return r.json(); });
    }

    function openMask(title, bodyHtml, cls, footHtml) {
        injectCss();
        var m = document.createElement('div'); m.className = 'egps-mask';
        m.innerHTML = '<div class="egps-box ' + (cls || '') + '"><div class="egps-hd"><span>' + title + '</span><span class="x" data-x>&times;</span></div>'
            + '<div class="egps-bd">' + bodyHtml + '</div><div class="egps-ft">' + (footHtml || '') + '<span class="egps-btn" data-x>關閉</span></div></div>';
        m.addEventListener('click', function (e) {
            if (e.target === m || (e.target.getAttribute && e.target.hasAttribute('data-x'))) m.remove();
        });
        document.body.appendChild(m);
        return m;
    }

    function openDrawing(dId) {
        var sw = screen.availWidth, sh = screen.availHeight, pw = Math.min(1400, Math.round(sw * .85)), ph = Math.min(900, Math.round(sh * .88));
        w.open(VIEWER + '?pk=' + encodeURIComponent(dId), 'drawing_' + dId,
            'width=' + pw + ',height=' + ph + ',left=' + Math.round((sw - pw) / 2) + ',top=' + Math.round((sh - ph) / 2) + ',resizable=yes,scrollbars=yes');
    }

    function money(p, cur) { return p === '' || p == null ? '—' : esc(p) + (cur ? ' ' + esc(cur) : ''); }

    function historyHtml(it, ctx) {
        var h = it.history || [];
        if (ctx === 'bom') return bomHistoryHtml(h);
        if (ctx !== 'quote' && ctx !== 'order') return '';
        var isQ = ctx === 'quote';
        var s = '<table class="egps-tb"><tr><th>' + (isQ ? '最近報價單號' : '最近訂單號') + '</th><th>日期</th><th>製程</th><th>數量</th><th>單價</th></tr>';
        if (!h.length) s += '<tr><td colspan="5" class="egps-muted">這個料號' + (isQ ? '沒有報價紀錄' : '沒有訂單紀錄') + '</td></tr>';
        h.forEach(function (r) {
            var key = isQ ? ('data-q="' + r.item_id + '"') : ('data-o="' + r.order_id + '"');
            s += '<tr><td><span class="egps-lnk" ' + key + '>' + esc(r.no) + '</span></td><td>' + esc(dateFmt(r.date)) + '</td><td>' + esc(r.process || '—')
                + '</td><td>' + esc(r.qty || '—') + (r.tiered ? ' <span class="egps-muted">階梯</span>' : '') + '</td><td>' + money(r.price, r.currency) + '</td></tr>';
        });
        return s + '</table>';
    }

    // BOM 總表用：最近三張製令（客戶、數量、每道製程的廠商與外包金額）
    function bomHistoryHtml(h) {
        // 每張製令一塊：上面一行 BOM／日期／客戶／數量，下面製程由左往右排（像 BOM 總表的製程欄），
        // 排不下就自動換行（flex-wrap），不出現左右捲軸。
        var s = '<div style="margin-top:4px;">';
        if (!h.length) s += '<div class="egps-muted" style="padding:4px 0;">這個料號沒有製令紀錄</div>';
        h.forEach(function (r) {
            s += '<div style="border:1px solid #E4D3BC;border-radius:4px;margin-bottom:6px;background:#fff;">'
              + '<div style="background:#F7E0BD;color:#4a2e0a;padding:3px 8px;font-size:12px;border-radius:4px 4px 0 0;">'
              + '<b>' + esc(r.bom) + '</b>　' + esc(dateFmt(r.date)) + '　客戶：' + esc(r.client || '—') + '　數量：<b>' + esc(r.qty || '—') + '</b></div>'
              + '<div style="display:flex;flex-wrap:wrap;gap:4px;padding:5px 6px;">';
            var procs = r.procs || [];
            if (!procs.length) s += '<span class="egps-muted">無製程資料</span>';
            procs.forEach(function (p, i) {
                var v = p.vendor ? (p.internal ? '<span class="egps-muted">廠內</span> ' + esc(p.vendor) : esc(p.vendor)) : '<span class="egps-muted">—</span>';
                // 外包只顯示單價（不顯示依本次數量算出的發包總價）
                var amt = (p.price !== '' && p.price != null)
                    ? '<div style="color:#8a4b12;">單價 <b>' + esc(p.price) + '</b> 元</div>' : '';
                s += '<div style="flex:0 0 118px;box-sizing:border-box;border:1px solid #EADFCD;border-radius:3px;background:#FFFBF4;padding:2px 5px;font-size:11px;line-height:1.35;">'
                   + '<div style="font-weight:700;color:#4a2e0a;"><span class="egps-muted" style="font-weight:400;">' + (i + 1) + '.</span> ' + esc(p.process) + '</div>'
                   + '<div style="word-break:break-all;">' + v + '</div>' + amt + '</div>';
            });
            s += '</div></div>';
        });
        return s + '</div>';
    }

    function cardHtml(it, ctx, myId) {
        var lv = LEVEL[it.level] || LEVEL.A;
        var s = '<div class="egps-card"><h5>'
            + '<span class="egps-tag" style="background:' + lv[1] + ';color:' + lv[2] + ';">' + esc(lv[0]) + '</span>'
            + '<span class="egps-tag" style="background:' + (it.same_customer ? '#EADFCD;color:#6b5638' : '#6b4a1e;color:#fff') + ';">' + (it.same_customer ? '同客戶' : '跨客戶') + '</span>'
            + '<span>客戶：' + esc(it.customer || '—') + '</span>'
            + '<span>料號：<span class="egps-lnk" data-draw="' + it.d_id + '" title="點開看圖面">' + esc(it.part_no) + '</span></span>'
            + (it.drawing_no ? '<span>圖號：<span class="egps-lnk" data-draw="' + it.d_id + '" title="點開看圖面">' + esc(it.drawing_no) + '</span></span>' : '')
            + (myId ? '<span class="egps-btn" data-cmp="' + it.d_id + '">比對內容</span>' : '')
            + '</h5><div class="egps-muted">認定類似的原因：</div><ul class="egps-why">'
            + (it.reasons || []).map(function (x) { return '<li>' + esc(x) + '</li>'; }).join('') + '</ul>'
            + historyHtml(it, ctx) + '</div>';
        return s;
    }

    // 點擊處理（看圖／比對／報價或訂單明細），掛在容器上用事件委派
    function wire(root, myId) {
        root.addEventListener('click', function (e) {
            var t = e.target;
            if (!t || !t.getAttribute) return;
            var d;
            if ((d = t.getAttribute('data-draw'))) { openDrawing(d); return; }
            if ((d = t.getAttribute('data-cmp'))) { compare(typeof myId === 'function' ? myId() : myId, parseInt(d, 10)); return; }
            if ((d = t.getAttribute('data-q'))) { detail('quote_detail', { item_id: d }); return; }
            if ((d = t.getAttribute('data-o'))) { detail('order_detail', { order_id: d }); return; }
            if (t.getAttribute('data-tgl')) { var n = root.querySelector('[data-more]'); if (n) { n.style.display = n.style.display === 'none' ? '' : 'none'; } }
        });
    }

    function splitItems(items) {
        var main = [], ref = [];
        (items || []).forEach(function (it) { (it.level === 'C' ? ref : main).push(it); });
        return { main: main, ref: ref };
    }

    function bodyFor(r, ctx, myId) {
        var sp = splitItems(r.items), s = '';
        var tg = r.target || {};
        s += '<div class="egps-muted" style="margin-bottom:6px;">本次料號：<b>' + esc(tg.part_no || '') + '</b>（' + esc(tg.customer || '—')
            + '）　共找到 ' + (r.total || 0) + ' 個類似料號。這只是提醒，是否查看與沿用由你決定。</div>';
        if (!r.label_cfg) s += '<div class="egps-muted" style="margin-bottom:6px;">※ 管理員尚未設定「要比對的標籤」，所以目前只比對齒輪規格＋工件總長。</div>';
        sp.main.forEach(function (it) { s += cardHtml(it, ctx, myId); });
        if (sp.ref.length) {
            s += '<span class="egps-tgl" data-tgl="1">▸ 參考：另有 ' + sp.ref.length + ' 個齒輪規格相同、但工件總長有一方未填的料號（點此展開／收合）</span>'
              + '<div data-more style="display:' + (sp.main.length ? 'none' : '') + ';">';
            sp.ref.forEach(function (it) { s += cardHtml(it, ctx, myId); });
            s += '</div>';
        }
        return s;
    }

    function prompt(opt) {
        try {
            opt = opt || {};
            var dId = parseInt(opt.dId, 10) || 0, ctx = opt.ctx || 'master';
            if (dId <= 0) return Promise.resolve(null);
            var key = (opt.onceKey || ctx) + ':' + dId;
            if (opt.once !== false && onceSet[key]) return Promise.resolve(null);
            return post({ action: 'check', d_id: dId, ctx: ctx }).then(function (r) {
                if (!r || !r.success || !r.enabled || !r.items || !r.items.length) return null;
                if (opt.once !== false) onceSet[key] = 1;
                var sp = splitItems(r.items);
                if (!sp.main.length && opt.refBanner !== false) {
                    // 只有「總長未填」的參考級：不打斷操作，只給一條小橫幅
                    var b = document.createElement('div');
                    b.style.cssText = 'position:fixed;right:18px;bottom:18px;z-index:10600;width:340px;max-width:92%;';
                    b.className = 'egps-banner';
                    injectCss();
                    b.innerHTML = '有 ' + sp.ref.length + ' 個料號的齒輪規格與「' + esc(r.target.part_no) + '」相同，但工件總長有一方未填，無法確認。'
                        + ' <span class="egps-lnk" data-open="1">查看</span>　<span class="egps-lnk" data-x="1">忽略</span>';
                    b.addEventListener('click', function (e) {
                        if (e.target.getAttribute('data-open')) { b.remove(); show(r, ctx, dId); }
                        else if (e.target.getAttribute('data-x')) b.remove();
                    });
                    document.body.appendChild(b);
                    setTimeout(function () { if (b.parentNode) b.remove(); }, 20000);
                    return r;
                }
                show(r, ctx, dId);
                return r;
            }).catch(function () { return null; });
        } catch (e) { return Promise.resolve(null); }
    }

    function show(r, ctx, myId) {
        var m = openMask('<i class="fa fa-clone"></i>&nbsp;有類似料號', bodyFor(r, ctx, myId), 'lg');
        wire(m, myId);
    }

    function panel(el, opt) {
        try {
            if (!el) return;
            opt = opt || {};
            var req = opt.orderId ? { action: 'order_check', order_id: opt.orderId, ctx: 'design' } : { action: 'check', d_id: opt.dId, ctx: opt.ctx || 'design' };
            injectCss();
            post(req).then(function (r) {
                if (!r || !r.success || !r.enabled || !r.items || !r.items.length) { el.innerHTML = ''; el.style.display = 'none'; return; }
                var myId = (r.target && r.target.d_id) || 0;
                var c = { B: 0, A: 0, C: 0 };
                r.items.forEach(function (it) { c[it.level]++; });
                var parts = [];
                if (c.B) parts.push('B 級 ' + c.B + ' 個'); if (c.A) parts.push('A 級 ' + c.A + ' 個'); if (c.C) parts.push('參考(C) ' + c.C + ' 個');
                el.style.display = '';
                // 淺藍底「類似料號」系統提示（使用者指定淺藍，與黃色的設計提醒區分）；點「查看」開跟建立訂單時一樣的跳窗
                el.innerHTML = '<div style="background:#E4F1FB;border:1px solid #9CC8E8;border-left:5px solid #4A90C2;border-radius:4px;padding:7px 11px;color:#1f4e79;font-size:13px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">'
                    + '<b><i class="fa fa-clone"></i> 類似料號</b><span>' + esc(parts.join('、')) + '（齒輪規格相同）</span>'
                    + '<span class="egps-btn" data-psopen="1" style="background:#fff;border-color:#4A90C2;color:#1f4e79;">查看類似料號</span></div>';
                if (!el._egpsWired) {
                    el._egpsWired = true;
                    el.addEventListener('click', function (e) {
                        if (e.target.getAttribute && e.target.getAttribute('data-psopen')) prompt({ dId: el._egpsMy, ctx: 'order', once: false, refBanner: false });
                    });
                }
                el._egpsMy = myId;
            }).catch(function () { el.innerHTML = ''; el.style.display = 'none'; });
        } catch (e) {}
    }

    function compare(a, b) {
        post({ action: 'compare', a: a, b: b }).then(function (r) {
            if (!r || !r.success) { alert('讀取比對資料失敗'); return; }
            var A = r.a, B = r.b;
            function head(h, tag) {
                return '<div style="flex:1;min-width:0;"><div class="egps-muted">' + tag + '</div><b>' + esc(h.part_no) + '</b><br>' + esc(h.customer || '—')
                    + '<br><span class="egps-btn" style="margin-left:0;" data-draw="' + h.d_id + '"><i class="fa fa-picture-o"></i> 看圖面</span></div>';
            }
            var s = '<div style="display:flex;gap:12px;margin-bottom:8px;">' + head(A, '本次料號（左）') + head(B, '類似料號（右）') + '</div>';
            s += '<table class="egps-tb"><tr><th style="width:18%;">項目</th><th style="width:36%;">本次</th><th style="width:36%;">類似料號</th><th style="width:10%;">相同</th></tr>';
            var last = '';
            r.rows.forEach(function (x) {
                if (x.group !== last) { last = x.group; s += '<tr class="egps-grp"><td colspan="4">' + esc(x.group) + '</td></tr>'; }
                s += '<tr class="' + (x.same ? '' : 'egps-diff') + (x.cfg ? ' egps-cfg' : '') + '"><td>' + esc(x.name) + (x.cfg ? ' <span class="egps-muted">(比對標籤)</span>' : '')
                    + '</td><td>' + esc(x.a || '—') + '</td><td>' + esc(x.b || '—') + '</td><td>' + (x.same ? '✓' : '≠') + '</td></tr>';
            });
            s += '</table><div class="egps-muted" style="margin-top:6px;">紅底＝兩邊不同；左側橘線＝管理員指定要比對的標籤。</div>';
            var m = openMask('<i class="fa fa-columns"></i>&nbsp;料號比對', s, 'lg');
            wire(m, 0);
        }).catch(function () { alert('讀取比對資料失敗'); });
    }

    function kv(label, v) { return '<tr><th style="width:90px;">' + label + '</th><td>' + esc(v == null || v === '' ? '—' : v) + '</td></tr>'; }
    function detail(action, params) {
        var req = { action: action }; for (var k in params) req[k] = params[k];
        post(req).then(function (r) {
            if (!r || !r.success || !r.data) { alert('查無資料'); return; }
            var d = r.data, s = '<table class="egps-tb">';
            if (action === 'quote_detail') {
                s += kv('報價單號', d.no) + kv('報價日期', dateFmt(d.date)) + kv('客戶', d.client) + kv('料號', d.part) + kv('規格', d.spec) + kv('製程', d.process)
                   + kv('數量', (d.qty || '') + (d.unit ? ' ' + d.unit : '')) + kv('單價', (d.price || '') + (d.currency ? ' ' + d.currency : '')) + kv('金額', d.amount);
                if (d.tiers && d.tiers.length) {
                    s += '<tr><th>階梯報價</th><td>' + d.tiers.map(function (t) { return esc(t.qty_min) + (t.qty_max ? '~' + esc(t.qty_max) : '以上') + '：' + esc(t.unit_price); }).join('<br>') + '</td></tr>';
                }
                s += kv('備註', d.note);
            } else {
                s += kv('訂單號', d.no) + kv('訂單日期', dateFmt(d.date)) + kv('交期', dateFmt(d.delivery)) + kv('客戶', d.client) + kv('客戶單號', d.customer_order)
                   + kv('製程', d.process) + kv('規格', d.spec) + kv('數量', d.qty) + kv('單價', (d.price || '') + (d.currency ? ' ' + d.currency : '')) + kv('備註', d.note);
            }
            openMask(action === 'quote_detail' ? '報價內容' : '訂單內容', s + '</table>', 'sm');
        }).catch(function () { alert('查無資料'); });
    }

    function openSettings() {
        post({ action: 'settings_get' }).then(function (r) {
            if (!r || !r.success || !r.is_admin) { alert('只有系統管理員可以設定'); return; }
            var sel = {}; (r.labels || []).forEach(function (x) { sel[x] = 1; });
            var s = '<div class="egps-muted" style="margin-bottom:8px;">在接單、報價、批圖、主檔儲存時，系統會先比對「齒輪規格＋工件總長」找類似料號；'
                + '下面勾選的標籤若也全部相同，會被標為最像的一級（齒輪＋總長＋標籤相同）。沒勾選任何標籤時只有「齒輪＋總長」一級。</div>'
                + '<label class="egps-chk"><input type="checkbox" id="egps-en"' + (r.enabled ? ' checked' : '') + '> 啟用類似料號提示</label><hr style="margin:6px 0;">'
                + '<div style="columns:2;column-gap:16px;">' + (r.choices || []).map(function (c) {
                    return '<label class="egps-chk"><input type="checkbox" class="egps-lb" value="' + c.label_id + '"' + (sel[c.label_id] ? ' checked' : '') + '> ' + esc(c.label_name) + '</label>';
                }).join('') + '</div><div id="egps-msg" class="egps-muted" style="margin-top:6px;"></div>'
                + '<hr style="margin:10px 0 6px;"><div><b>查詢某個料號有哪些類似料號與等級</b>（不必儲存設定就能查）</div>'
                + '<div style="margin-top:4px;"><input id="egps-q" type="text" placeholder="輸入料號關鍵字（至少 2 字）" style="width:260px;padding:2px 6px;border:1px solid #c9a97a;border-radius:3px;"> <span class="egps-btn" id="egps-qgo" style="margin-left:0;">查詢</span></div>'
                + '<div id="egps-qres" style="margin-top:6px;"></div>';
            var m = openMask('<i class="fa fa-cog"></i>&nbsp;類似料號比對設定', s, 'sm', '<span class="egps-btn" id="egps-save" style="background:#F0A24B;border-color:#d98a2f;">儲存</span>');
            function qgo() {
                var kw = m.querySelector('#egps-q').value, box = m.querySelector('#egps-qres');
                box.innerHTML = '<span class="egps-muted">查詢中…</span>';
                post({ action: 'find_part', kw: kw }).then(function (x) {
                    var rows = (x && x.rows) || [];
                    if (!rows.length) { box.innerHTML = '<span class="egps-muted">找不到料號（至少輸入 2 個字）</span>'; return; }
                    box.innerHTML = rows.map(function (p) {
                        return '<div style="padding:1px 0;"><span class="egps-lnk" data-pick="' + p.d_id + '">' + esc(p.part_no) + '</span> <span class="egps-muted">' + esc(p.customer || '—')
                            + (parseInt(p.has_gear, 10) ? '' : '（無齒輪規格，不偵測）') + '</span></div>';
                    }).join('');
                }).catch(function () { box.innerHTML = '<span class="egps-muted">網路錯誤</span>'; });
            }
            m.querySelector('#egps-qgo').addEventListener('click', qgo);
            m.querySelector('#egps-q').addEventListener('keydown', function (e) { if (e.key === 'Enter') qgo(); });
            m.querySelector('#egps-qres').addEventListener('click', function (e) {
                var id = e.target.getAttribute && e.target.getAttribute('data-pick');
                if (!id) return;
                post({ action: 'check', d_id: id, ctx: 'master' }).then(function (r) {
                    if (!r || !r.items || !r.items.length) { alert('這個料號沒有類似料號（或沒有齒輪規格）'); return; }
                    show(r, 'master', parseInt(id, 10));
                });
            });
            m.querySelector('#egps-save').addEventListener('click', function () {
                var ids = [].slice.call(m.querySelectorAll('.egps-lb:checked')).map(function (x) { return parseInt(x.value, 10); });
                post({ action: 'settings_save', csrf: r.csrf, enabled: m.querySelector('#egps-en').checked ? 1 : 0, labels: JSON.stringify(ids) }).then(function (x) {
                    m.querySelector('#egps-msg').textContent = x && x.success ? '已儲存（' + ids.length + ' 個比對標籤）' : ((x && x.message) || '儲存失敗');
                }).catch(function () { m.querySelector('#egps-msg').textContent = '網路錯誤'; });
            });
        });
    }

    w.EGPartSimilar = { prompt: prompt, panel: panel, compare: compare, openSettings: openSettings, resetOnce: function () { onceSet = {}; } };
})(window);
