<?php
/**
 * 批次檢視（Phase 1：只做顯示，絕不寫入任何資料）
 * ─────────────────────────────────────────────────────────────────────
 * 用途：BOM 拆成多批（batch_label A/B/C…）之後，讓「哪一批走到哪一關、
 *       目前什麼狀態」一眼看得出來。使用者 2026-09-04 交辦。
 *
 * 設計上最重要的三件事（都是為了「絕對不影響現有 18 位使用者」）：
 *   1. 預設全部關閉，開關存在 localStorage＝每個人自己的瀏覽器，
 *      不可能因為我開了而讓別人的畫面改變，也沒有任何後端設定要存。
 *   2. 正式頁 OreadyReply_ForPm_BaseOfTime.php 只有兩處改動：
 *      一行 include，以及 tbody.appendChild(tr) 前的一個 decorateRow() 掛勾
 *      （而且包在 try/catch 裡）。這支檔案就算整個爆掉，原本的列照樣畫得出來。
 *   3. 只處理「真的有 batch_label 的 BOM」。沒拆批的 BOM 一律原封不動，
 *      連一個像素都不會變（使用者指定）。
 *
 * 資料來源：window.bomPSList 的 split_batches / all_split_batches
 *          （製程欄本來就在用這份，不另外查、不新增任何 API）。
 *
 * ⚠ Phase 1 的已知限制：資料庫目前沒有「這批是從哪一批分出來的」父子欄位，
 *   所以流程圖是用「同一個批號跨站相連」畫的。真正的樹狀分流／合併連線
 *   要等 Phase 2 建立父子關聯後才畫得出來，畫面上會標示出來不會騙人。
 */
?>
<style>
/* ── 批次檢視：暖色系為主（ai-rules/10），狀態色沿用使用者指定的圖例 ── */
.eg-bv-legend{display:none;align-items:center;flex-wrap:wrap;gap:10px;padding:5px 9px;margin:0 0 6px;
    background:#FFFDF8;border:1px solid #E0B77A;border-radius:4px;font-size:11px;color:#7A4A12;line-height:1.5;}
.eg-bv-legend.on{display:flex;}
.eg-bv-legend b{font-weight:600;}
.eg-bv-dot{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:3px;vertical-align:middle;}

/* 狀態色塊：字級小一定要自己寫 line-height，否則會繼承表格列行高把整列撐高 */
.eg-bv-badge{display:inline-block;padding:1px 6px;border-radius:3px;font-size:10px;line-height:15px;
    white-space:nowrap;border:1px solid transparent;font-weight:600;}
.eg-bv-ing {background:#E8F0FE;border-color:#B7CDEB;color:#2E6DA4;}   /* 加工中 */
.eg-bv-qc  {background:#F7E0BD;border-color:#E0B77A;color:#7A4A12;}   /* QC待驗 */
.eg-bv-ok  {background:#EAF3EA;border-color:#CFE3CF;color:#4A7A4A;}   /* 已完成 */
.eg-bv-ng  {background:#DD5138;border-color:#DD5138;color:#fff;}      /* NG */
.eg-bv-na  {background:#F5F5F5;border-color:#E2E2E2;color:#999;}      /* 未執行 */
.eg-bv-done{opacity:.55;}                                             /* 已完成整體淡化 */

/* 發單日欄的「同時進行中」摘要 */
.eg-bv-sum{margin:0 0 4px;padding:3px 5px;background:#FFFDF8;border:1px dashed #E0B77A;border-radius:3px;
    font-size:10px;line-height:1.5;color:#7A4A12;}
.eg-bv-sum-t{font-weight:600;display:block;margin-bottom:1px;}
.eg-bv-sum-i{display:block;white-space:nowrap;}

/* 製程欄的批次列 */
.eg-bv-cell-h{font-size:10.5px;font-weight:600;color:#444;margin-bottom:3px;}
.eg-bv-chip{font-size:9px;font-weight:normal;background:#F7E0BD;color:#7A4A12;border-radius:8px;padding:1px 5px;margin-left:3px;}
.eg-bv-row{display:flex;align-items:center;gap:4px;margin:2px 0;padding:2px 0 2px 5px;
    border-left:2px solid #E0B77A;font-size:10px;line-height:1.4;}
.eg-bv-lbl{font-weight:700;color:#7A4A12;min-width:12px;}
.eg-bv-qty{font-weight:600;color:#333;}
.eg-bv-sub{color:#777;font-size:9px;}

/* 欄標題旁的「此欄有進行中批次」圓點 */
.eg-bv-hdot{display:inline-block;width:7px;height:7px;border-radius:50%;background:#F0A24B;margin-left:4px;vertical-align:middle;}

/* ── 流程圖列 ── */
tr.eg-bv-flow-row > td{background:#FFFDF8 !important;border-top:2px solid #E0B77A !important;padding:7px 10px !important;}
.eg-bv-flow-wrap{overflow-x:auto;}
.eg-bv-flow-t{font-size:11px;font-weight:600;color:#7A4A12;margin-bottom:5px;}
.eg-bv-lane{display:flex;align-items:stretch;margin-bottom:5px;}
.eg-bv-lane-lbl{flex:0 0 34px;display:flex;align-items:center;justify-content:center;font-weight:700;
    font-size:11px;color:#7A4A12;background:#F7E0BD;border:1px solid #E0B77A;border-radius:3px;margin-right:4px;}
.eg-bv-station{flex:0 0 168px;display:flex;align-items:center;}
.eg-bv-node{flex:1;min-width:0;border:1px solid #E0B77A;border-radius:4px;background:#fff;padding:3px 5px;font-size:10px;line-height:1.35;}
.eg-bv-node.na{border-style:dashed;border-color:#E2E2E2;background:transparent;}
.eg-bv-node-t{font-weight:600;color:#333;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.eg-bv-node-s{color:#777;font-size:9px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.eg-bv-link{flex:0 0 22px;height:1px;background:#C9A063;position:relative;align-self:center;}
.eg-bv-link:after{content:'';position:absolute;right:0;top:-3px;border:3px solid transparent;border-left-color:#C9A063;}
.eg-bv-link.off{background:transparent;}
.eg-bv-link.off:after{display:none;}
.eg-bv-branch{color:#DD5138;font-size:9px;font-weight:600;}
.eg-bv-note{font-size:10px;color:#A8814A;margin-top:3px;}

/* 開關鈕 */
#eg-bv-toggle{margin-left:6px;}
.eg-bv-menu{position:absolute;z-index:12000;background:#fff;border:1px solid #E0B77A;border-radius:4px;
    box-shadow:0 3px 10px rgba(0,0,0,.15);padding:7px 10px;font-size:12px;min-width:210px;}
.eg-bv-menu label{display:block;font-weight:normal;margin:0 0 5px;cursor:pointer;color:#333;}
.eg-bv-menu label:last-of-type{margin-bottom:0;}
.eg-bv-menu input{margin-right:5px;}
.eg-bv-menu hr{margin:7px 0;border-top:1px solid #eee;}
.eg-bv-menu .eg-bv-hint{font-size:10.5px;color:#A8814A;line-height:1.5;}
@media print{.eg-bv-legend,#eg-bv-toggle{display:none !important;}}
</style>
<script>
/* 批次檢視：全部程式碼在這個 IIFE 內，對外只暴露 window.EGBatchView */
(function () {
    'use strict';

    var LS_KEY = 'eg_bom_batch_view_v1';

    // 快取：decorateRow 是「每一列每一次重繪」都會呼叫的，而本頁每 5 秒自動更新一次；
    // 每列都去讀一次 localStorage 是不必要的固定成本，故快取起來、寫入時才失效。
    var _pref = null;
    function readPref() {
        if (_pref) return _pref;
        try { _pref = JSON.parse(localStorage.getItem(LS_KEY) || '{}') || {}; }
        catch (e) { _pref = {}; }   // 私密視窗／停用 site data 時 localStorage 會丟例外
        return _pref;
    }
    function writePref(p) {
        _pref = p || {};
        try { localStorage.setItem(LS_KEY, JSON.stringify(_pref)); } catch (e) {}
    }
    function on(k) { return !!readPref()[k]; }
    function anyOn() { var p = readPref(); return !!(p.lane || p.flow); }

    function esc(s) {
        if (s === null || s === undefined) return '';
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;')
                        .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    /* ── 一個批次的狀態判定（唯一實作，泳道與流程圖共用）──
       欄位語意見資料字典：N=待發包 P=待移轉 Q=QC待驗 ing=加工中 E=已移轉 */
    function statusOf(b) {
        var st = String(b.processing_state || '');
        if (String(b.QC_check || '') === 'ng')            return { k: 'ng',  t: 'NG',     cls: 'eg-bv-ng'  };
        if (st === 'ing')                                  return { k: 'ing', t: '加工中', cls: 'eg-bv-ing' };
        if (st === 'Q' && !(b.qc_completed == 1))           return { k: 'qc',  t: 'QC待驗', cls: 'eg-bv-qc'  };
        if (st === 'E' || st === '1' || st === 1)           return { k: 'ok',  t: '已結',   cls: 'eg-bv-ok'  };
        if (st === 'P' || (b.qc_completed == 1))            return { k: 'ok',  t: 'OK',     cls: 'eg-bv-ok'  };
        if (st === 'N')                                     return { k: 'na',  t: '未發包', cls: 'eg-bv-na'  };
        return { k: 'na', t: st || '—', cls: 'eg-bv-na' };
    }
    function isLive(s) { return s.k === 'ing' || s.k === 'qc' || s.k === 'ng'; }

    /* ── 取這支 BOM 的逐站批次結構 ──
       來源就是製程欄本來在用的 window.bomPSList，不另外查資料 */
    function stationsOf(bom) {
        var key = String(bom || '').trim();
        if (!key) return [];
        var list = (window.bomPSList || []).filter(function (p) {
            return p && p.bom && String(p.bom).trim() === key;
        });
        list.sort(function (a, b) { return (parseInt(a.bom_sn, 10) || 0) - (parseInt(b.bom_sn, 10) || 0); });
        return list.map(function (p) {
            var bs = (p.split_batches && p.split_batches.length > 1) ? p.split_batches
                   : (p.all_split_batches && p.all_split_batches.length > 1) ? p.all_split_batches
                   : null;
            return {
                bom_sn: p.bom_sn,
                name: p.ProcessName || '',
                batches: bs   // null＝這一站沒有拆批
            };
        });
    }
    function hasSplit(sts) {
        for (var i = 0; i < sts.length; i++) if (sts[i].batches) return true;
        return false;
    }

    function fmtDate(d) {
        if (!d) return '';
        var m = String(d).match(/^(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})/);
        return m ? (parseInt(m[2], 10) + '/' + parseInt(m[3], 10)) : String(d);
    }

    /* ── (a) 泳道表格 ─────────────────────────────────────────── */

    // 發單日欄頂端的「同時進行中」摘要
    function summaryHtml(sts) {
        var live = [];
        sts.forEach(function (s) {
            if (!s.batches) return;
            s.batches.forEach(function (b) {
                var st = statusOf(b);
                if (!isLive(st)) return;
                live.push('<span class="eg-bv-sum-i">' + esc(s.bom_sn) + esc(s.name)
                        + ' <b>' + esc(b.batch_label || '─') + '</b> '
                        + '<span class="eg-bv-badge ' + st.cls + '">' + esc(st.t) + '</span></span>');
            });
        });
        if (!live.length) return '';
        return '<div class="eg-bv-sum"><span class="eg-bv-sum-t">同時進行中：</span>' + live.join('') + '</div>';
    }

    // 單一製程欄的批次列表
    function stationCellHtml(s) {
        var h = '<div class="eg-bv-cell-h">' + esc(s.name)
              + '<span class="eg-bv-chip">拆' + s.batches.length + '批</span></div>';
        s.batches.forEach(function (b) {
            var st = statusOf(b);
            var sub = [fmtDate(b.outsource_date), b.maker_id || ''].filter(Boolean).join(' ');
            h += '<div class="eg-bv-row' + (st.k === 'ok' ? ' eg-bv-done' : '') + '">'
              +    '<span class="eg-bv-lbl">' + esc(b.batch_label || '─') + '</span>'
              +    '<span class="eg-bv-qty">' + esc(b.sqty != null ? b.sqty : '') + '</span>'
              +    '<span class="eg-bv-badge ' + st.cls + '">' + esc(st.t) + '</span>'
              +    (sub ? '<span class="eg-bv-sub">' + esc(sub) + '</span>' : '')
              +  '</div>';
        });
        return h;
    }

    /* ── (b) 流程圖 ───────────────────────────────────────────── */
    function flowHtml(sts) {
        // 只取有拆批的站，並收集所有出現過的批號
        var cols = sts.filter(function (s) { return s.batches; });
        if (!cols.length) return '';
        var labels = [], seen = {};
        cols.forEach(function (s) {
            s.batches.forEach(function (b) {
                var l = b.batch_label || '─';
                if (!seen[l]) { seen[l] = true; labels.push(l); }
            });
        });
        labels.sort();

        var h = '<div class="eg-bv-flow-t">批次流程（依批號相連）</div><div class="eg-bv-flow-wrap">';
        labels.forEach(function (l) {
            h += '<div class="eg-bv-lane"><div class="eg-bv-lane-lbl">' + esc(l) + '</div>';
            var firstSeen = -1;
            cols.forEach(function (s, ci) {
                var b = null;
                for (var i = 0; i < s.batches.length; i++) {
                    if ((s.batches[i].batch_label || '─') === l) { b = s.batches[i]; break; }
                }
                if (b && firstSeen < 0) firstSeen = ci;
                h += '<div class="eg-bv-station">';
                if (b) {
                    var st = statusOf(b);
                    var sub = [fmtDate(b.outsource_date), b.maker_id || ''].filter(Boolean).join(' ');
                    h += '<div class="eg-bv-node' + (st.k === 'ok' ? ' eg-bv-done' : '') + '">'
                      +    '<div class="eg-bv-node-t">' + esc(s.bom_sn) + esc(s.name)
                      +      ' <span class="eg-bv-qty">x' + esc(b.sqty != null ? b.sqty : '') + '</span>'
                      +      ' <span class="eg-bv-badge ' + st.cls + '">' + esc(st.t) + '</span></div>'
                      +    (sub ? '<div class="eg-bv-node-s">' + esc(sub) + '</div>' : '')
                      +    (firstSeen === ci && ci > 0 ? '<div class="eg-bv-branch">∟ 此站才出現（由其他批分出）</div>' : '')
                      +  '</div>';
                } else {
                    h += '<div class="eg-bv-node na"><div class="eg-bv-node-s">—</div></div>';
                }
                h += '</div>';
                // 連線（最後一站不畫）
                if (ci < cols.length - 1) {
                    var nextHas = cols[ci + 1].batches.some(function (x) { return (x.batch_label || '─') === l; });
                    h += '<div class="eg-bv-link' + (b && nextHas ? '' : ' off') + '"></div>';
                }
            });
            h += '</div>';
        });
        h += '</div><div class="eg-bv-note">※ Phase 1：目前資料庫還沒有「這批從哪一批分出來」的父子欄位，'
           + '所以上面是用<b>同一個批號跨站相連</b>畫的；真正的分流／合併連線要等 Phase 2 建立父子關聯後才畫得出來。</div>';
        return h;
    }

    /* ── 欄標題圓點：該製程欄目前有進行中的批次 ── */
    function markHeaders() {
        var tbl = document.getElementById('table-DOWN');
        if (!tbl) return;
        var hrow = tbl.querySelector('thead tr');
        if (!hrow) return;
        // 先清掉舊的（關掉泳道時也要清乾淨）
        var olds = hrow.querySelectorAll('.eg-bv-hdot');
        for (var k = 0; k < olds.length; k++) olds[k].parentNode.removeChild(olds[k]);
        if (!on('lane')) return;
        var body = tbl.querySelector('tbody');
        if (!body) return;
        var ths = hrow.children, liveCols = {};
        var rows = body.querySelectorAll('tr');
        for (var r = 0; r < rows.length; r++) {
            var tds = rows[r].children;
            for (var i = 0; i < tds.length; i++) {
                if (String(tds[i].className || '').indexOf('process-col') >= 0
                    && tds[i].querySelector('.eg-bv-ing, .eg-bv-qc, .eg-bv-ng')) {
                    liveCols[i] = true;
                }
            }
        }
        Object.keys(liveCols).forEach(function (i) {
            var th = ths[i];
            if (th && !th.querySelector('.eg-bv-hdot')) {
                var d = document.createElement('span');
                d.className = 'eg-bv-hdot';
                d.title = '此製程目前有進行中的批次';
                th.appendChild(d);
            }
        });
    }

    /* 圖例顯不顯示 */
    function syncLegend() {
        var lg = document.getElementById('eg-bv-legend');
        if (lg) lg.className = 'eg-bv-legend' + (on('lane') ? ' on' : '');
    }

    /* ── 表格每次重繪後都要同步圖例與欄標題圓點 ──
       清單有很多條重繪路徑（篩選、換頁、排序、5 秒自動更新），
       只在按開關時更新會出現「換頁後圓點不見了」。用 MutationObserver
       盯 tbody，任何一條路徑重繪都涵蓋得到，且只在真的重畫時才跑。 */
    var _syncTimer = null;
    function scheduleSync() {
        if (_syncTimer) clearTimeout(_syncTimer);
        _syncTimer = setTimeout(function () {
            _syncTimer = null;
            try { syncLegend(); markHeaders(); } catch (e) {}
        }, 80);
    }
    function watchTable() {
        var tbl = document.getElementById('table-DOWN');
        var body = tbl && tbl.querySelector('tbody');
        if (!body || body.__egBvWatched) return;
        body.__egBvWatched = true;
        try {
            new MutationObserver(scheduleSync).observe(body, { childList: true });
        } catch (e) { /* 舊瀏覽器沒有 MutationObserver 就算了，不影響其他功能 */ }
        scheduleSync();
    }

    /* ── 對外唯一入口：正式頁在 tbody.appendChild(tr) 前呼叫 ── */
    function decorateRow(tr, row, tbody) {
        if (!anyOn()) return;                       // 沒開＝完全不做事
        if (!tr || !row || !row.bom) return;
        var sts = stationsOf(row.bom);
        if (!hasSplit(sts)) return;                 // 沒拆批的 BOM 一律不動（使用者指定）
        tr.setAttribute('data-eg-bv', '1');

        if (on('lane')) {
            // 發單日欄：最前面插「同時進行中」摘要
            var tdOut = tr.querySelector('td[name="outsource_date"]');
            if (tdOut) {
                var sh = summaryHtml(sts);
                if (sh) tdOut.insertAdjacentHTML('afterbegin', sh);
            }
            // 製程欄：有拆批的站改用新版樣式重畫
            var pcs = tr.querySelectorAll('td.process-col');
            for (var i = 0; i < pcs.length && i < sts.length; i++) {
                if (sts[i] && sts[i].batches) pcs[i].innerHTML = stationCellHtml(sts[i]);
            }
        }

        if (on('flow')) {
            var fh = flowHtml(sts);
            if (fh) {
                var ftr = document.createElement('tr');
                ftr.className = 'eg-bv-flow-row';
                var ftd = document.createElement('td');
                ftd.colSpan = Math.max(1, tr.children.length);
                ftd.innerHTML = fh;
                ftr.appendChild(ftd);
                // ⚠ 這裡絕對不可以自己 tbody.appendChild(tr)——呼叫端在我 return 之後
                //   還會再 append 一次，同一個節點 append 兩次是「搬移」，
                //   結果會變成本列跑到流程列後面。改用微任務：等呼叫端把 tr 放進
                //   DOM 之後（同步區塊結束時）再把流程列插在它正後方。
                Promise.resolve().then(function () {
                    if (tr.parentNode && !ftr.parentNode) tr.parentNode.insertBefore(ftr, tr.nextSibling);
                });
            }
        }
    }

    /* ── 開關 UI（自己注入工具列，正式頁不必為了這顆按鈕改任何一行）── */
    function refresh() {
        syncLegend();
        var b = document.getElementById('eg-bv-toggle');
        if (b) {
            var n = (on('lane') ? 1 : 0) + (on('flow') ? 1 : 0);
            b.className = 'btn btn-xs ' + (n ? 'btn-warning' : 'btn-default');
            b.innerHTML = '<i class="fa fa-sitemap"></i> 批次檢視' + (n ? '（開）' : '');
        }
        if (typeof window.processAndRenderData === 'function') window.processAndRenderData();
        scheduleSync();
    }

    function buildMenu(btn) {
        var old = document.getElementById('eg-bv-menu');
        if (old) { old.remove(); return; }
        var p = readPref();
        var m = document.createElement('div');
        m.id = 'eg-bv-menu';
        m.className = 'eg-bv-menu';
        m.innerHTML =
            '<label><input type="checkbox" id="eg-bv-lane"' + (p.lane ? ' checked' : '') + '>泳道表格（發單日摘要＋製程欄批次）</label>' +
            '<label><input type="checkbox" id="eg-bv-flow"' + (p.flow ? ' checked' : '') + '>流程圖（該列下方展開站點連線）</label>' +
            '<hr><div class="eg-bv-hint">兩種可以分開開、也可以一起開。<br>' +
            '設定只存在你自己的瀏覽器，<b>不會影響其他人</b>。<br>' +
            '只有<b>拆過批</b>的 BOM 會換成新版面。</div>';
        document.body.appendChild(m);
        var r = btn.getBoundingClientRect();
        m.style.left = Math.max(6, Math.min(r.left, window.innerWidth - m.offsetWidth - 10)) + 'px';
        m.style.top  = (r.bottom + window.scrollY + 4) + 'px';
        m.querySelector('#eg-bv-lane').onchange = function () { var q = readPref(); q.lane = this.checked; writePref(q); refresh(); };
        m.querySelector('#eg-bv-flow').onchange = function () { var q = readPref(); q.flow = this.checked; writePref(q); refresh(); };
        setTimeout(function () {
            document.addEventListener('mousedown', function h(e) {
                var mm = document.getElementById('eg-bv-menu');
                if (mm && !mm.contains(e.target) && e.target !== btn) { mm.remove(); document.removeEventListener('mousedown', h); }
            });
        }, 0);
    }

    function inject() {
        if (document.getElementById('eg-bv-toggle')) return;
        var anchor = document.querySelector('button[onclick="scrollToProcesses()"]');
        if (!anchor) return;
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.id = 'eg-bv-toggle';
        btn.title = '拆批的 BOM 用新版面顯示（泳道表格／流程圖），設定只存在你自己的瀏覽器';
        btn.onclick = function (e) { e.stopPropagation(); buildMenu(btn); };
        anchor.insertAdjacentElement('afterend', btn);

        // 圖例（只有開泳道時才顯示）
        var lg = document.createElement('div');
        lg.id = 'eg-bv-legend';
        lg.className = 'eg-bv-legend';
        lg.innerHTML = '<b>圖例</b>'
            + '<span><span class="eg-bv-dot" style="background:#2E6DA4;"></span>加工中</span>'
            + '<span><span class="eg-bv-dot" style="background:#F0A24B;"></span>QC待驗</span>'
            + '<span><span class="eg-bv-dot" style="background:#4A7A4A;"></span>OK（已完成，淡化）</span>'
            + '<span><span class="eg-bv-dot" style="background:#DD5138;"></span>NG</span>'
            + '<span><span class="eg-bv-hdot" style="margin-left:0;"></span>欄位標題旁圓點＝該欄目前有進行中批次</span>';
        var tbl = document.getElementById('table-DOWN');
        if (tbl && tbl.parentNode) tbl.parentNode.insertBefore(lg, tbl);
        syncLegend();
        // 只更新按鈕外觀，不在載入當下觸發重繪（避免多跑一次 processAndRenderData）
        var n = (on('lane') ? 1 : 0) + (on('flow') ? 1 : 0);
        btn.className = 'btn btn-xs ' + (n ? 'btn-warning' : 'btn-default');
        btn.innerHTML = '<i class="fa fa-sitemap"></i> 批次檢視' + (n ? '（開）' : '');
        watchTable();
    }

    window.EGBatchView = {
        decorateRow: decorateRow,
        markHeaders: markHeaders,
        isOn: anyOn,
        // 設定被本頁以外的地方改掉時（其他分頁、或手動改 localStorage）用來讓快取失效
        reload: function () { _pref = null; syncLegend(); scheduleSync(); }
    };
    // 另一個分頁改了設定 → 這一頁的快取要失效，不然兩邊會不一致
    window.addEventListener('storage', function (e) {
        if (e && e.key === LS_KEY) { _pref = null; syncLegend(); scheduleSync(); }
    });

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { setTimeout(inject, 300); });
    else setTimeout(inject, 300);
})();
</script>
