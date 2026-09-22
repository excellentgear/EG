/**
 * eg_cause_picker.js — 異常原因分類「逐層挑選」共用元件（全站唯一實作）
 *
 * 2026-09-22 使用者要求：「異常原因分類應該是先選第一層後出現第二層選擇，選第二層才出現
 * 第三層選擇，希望可以改像 views/QC/inspection_entry_v2.php 內的這種選單」——就是量具那種
 * 「① 先點類型 → ② 再點編號」的大方塊逐層挑選。三個地方要用同一套：
 *   views/QA/correction_order.php（異常矯正單，單選）
 *   views/QA/qa_abnormal_form.php（品質異常處理單，可複選）
 *   views/Sales/IR_Track.php（客退單開/編異常單，單選）
 * 所以收斂成這一支，**禁止各頁自己再刻一份**（鐵律4：三份遲早長出三種操作方式）。
 *
 * 資料來源一律是 qa_cause_cat 的三層樹（由各頁後端用 qab_cause_tree() 取），
 * 本檔案只負責「怎麼挑」，不自己查資料、不寫死任何分類名稱。
 *
 * 用法：
 *   EGCausePicker.open({
 *     tree: 樹狀陣列, selected: [已選id], multi: false,
 *     title: '選擇異常原因分類', onApply: function(ids){ ... }
 *   });
 *   EGCausePicker.pathOf(tree, id)          → '人 → 操作疏失 → 未依SOP標準作業'
 *   EGCausePicker.chipsHtml(tree, ids, {removable:true})  → 已選標籤（× 用 .egcp-chip-x[data-id]）
 *
 * 刻意不依賴 Bootstrap modal：這個挑選視窗常常是開在「另一個跳窗之上」（異常矯正單就是
 * 開在單據檢視裡），Bootstrap 3 疊第二層關閉時會把 body 的 modal-open 移掉、底下那層就捲不動
 * （見 memory modal-width-convention）。自己畫一層遮罩就完全避開這個坑。
 */
(function (global) {
    'use strict';

    var CSS_ID = 'eg-cause-picker-css';
    var Z = 10600;   // 要蓋得過 Bootstrap modal(1050) 與站上其他浮動工具(10400)

    function injectCss() {
        if (document.getElementById(CSS_ID)) return;
        var s = document.createElement('style');
        s.id = CSS_ID;
        s.textContent = [
            '.egcp-mask{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:' + Z + ';display:flex;',
            '  align-items:flex-start;justify-content:center;padding:40px 12px;overflow:auto;}',
            '.egcp-win{background:#fff;border-radius:10px;width:760px;max-width:100%;box-shadow:0 10px 40px rgba(0,0,0,.3);',
            '  font-family:inherit;color:#4A3524;}',
            '.egcp-hd{background:#FFF8EE;border-bottom:1px solid #E4D3BC;padding:10px 14px;border-radius:10px 10px 0 0;',
            '  display:flex;align-items:center;gap:8px;}',
            '.egcp-hd b{font-size:16px;}',
            '.egcp-x{margin-left:auto;border:0;background:transparent;font-size:22px;line-height:1;color:#8a6a45;cursor:pointer;}',
            '.egcp-bd{padding:12px 14px;}',
            '.egcp-tip{font-size:12px;color:#8a6a45;margin-bottom:8px;line-height:1.7;}',
            '.egcp-picked{background:#FBEEE6;border:1px solid #F3D9C4;border-radius:6px;padding:5px 8px;margin-bottom:10px;',
            '  display:flex;flex-wrap:wrap;gap:5px;align-items:center;min-height:32px;}',
            '.egcp-picked>b{flex:0 0 auto;font-size:12px;color:#8a6a45;font-weight:normal;}',
            '.egcp-chip{display:inline-flex;align-items:center;gap:4px;background:#fff;border:1px solid #D9A066;',
            '  border-radius:11px;padding:1px 4px 1px 9px;font-size:12px;line-height:1.8;}',
            '.egcp-chip-x{border:0;background:transparent;color:#C0703A;font-size:14px;line-height:1;padding:0 3px;cursor:pointer;}',
            '.egcp-chip-x:hover{color:#DD5138;}',
            '.egcp-none{color:#C0703A;font-style:italic;font-size:12px;}',
            '.egcp-crumb{font-size:13px;margin-bottom:8px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;}',
            '.egcp-crumb .egcp-here{color:#8a6a45;}',
            '.egcp-crumb b{color:#4A3524;}',
            '.egcp-btn{border:1px solid #D9A066;background:#fff;color:#8A5A2B;border-radius:5px;',
            '  padding:2px 10px;font-size:12px;cursor:pointer;}',
            '.egcp-btn:hover{background:#FBEEE6;}',
            '.egcp-grid{display:flex;flex-wrap:wrap;gap:8px;max-height:46vh;overflow:auto;padding:2px;}',
            '.egcp-grid button{min-width:150px;min-height:54px;border:1px solid #E4D3BC;background:#fff;color:#4A3524;',
            '  border-radius:8px;padding:8px 12px;font-size:15px;font-weight:bold;text-align:left;cursor:pointer;}',
            '.egcp-grid button:hover{background:#FDF6EC;border-color:#D9A066;}',
            '.egcp-grid button.on{background:#F0A24B;border-color:#C0703A;color:#3B2A18;}',
            '.egcp-grid button.has-sel{border-color:#D9A066;background:#FFF3E2;}',
            '.egcp-grid button small{display:block;font-weight:normal;font-size:11px;color:#8a6a45;margin-top:2px;}',
            '.egcp-grid button.on small{color:#6B4A22;}',
            '.egcp-empty{color:#8a6a45;font-size:13px;padding:10px 2px;}',
            '.egcp-ft{border-top:1px solid #eee;padding:10px 14px;display:flex;align-items:center;gap:8px;}',
            '.egcp-ft .egcp-sp{margin-left:auto;}',
            '.egcp-ok{border:1px solid #C0703A;background:#F0A24B;color:#3B2A18;border-radius:5px;padding:5px 14px;',
            '  font-size:13px;font-weight:bold;cursor:pointer;}',
            '.egcp-ok:hover{background:#E08E36;}',
            '.egcp-cancel{border:1px solid #ccc;background:#fff;color:#555;border-radius:5px;padding:5px 12px;font-size:13px;cursor:pointer;}',
            '@media print{.egcp-mask{display:none !important;}}'
        ].join('');
        document.head.appendChild(s);
    }

    function esc(v) {
        return String(v === null || v === undefined ? '' : v)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function nodesOf(tree, path) {   // path=[id,id]；回傳該層的節點清單
        var list = tree || [];
        for (var i = 0; i < path.length; i++) {
            var hit = null;
            for (var j = 0; j < list.length; j++) {
                if (String(list[j].cat_id) === String(path[i])) { hit = list[j]; break; }
            }
            if (!hit) return [];
            list = hit.children || [];
        }
        return list;
    }
    function findNode(tree, id) {
        var out = null;
        (function walk(ns) {
            (ns || []).forEach(function (n) {
                if (out) return;
                if (String(n.cat_id) === String(id)) { out = n; return; }
                walk(n.children);
            });
        })(tree);
        return out;
    }
    /** 'A → B → C'；找不到回空字串（呼叫端自己決定要不要顯示 #id） */
    function pathOf(tree, id) {
        var found = '';
        (function walk(ns, prefix) {
            (ns || []).forEach(function (n) {
                if (found) return;
                var p = prefix ? (prefix + ' → ' + n.name) : n.name;
                if (String(n.cat_id) === String(id)) { found = p; return; }
                if (n.children && n.children.length) walk(n.children, p);
            });
        })(tree, '');
        return found;
    }
    /** 這個節點底下（含自己）有沒有被選到的 → 用來把上層方塊標記成「裡面有選」 */
    function hasSelectedUnder(node, sel) {
        var hit = false;
        (function walk(n) {
            if (hit) return;
            if (sel.indexOf(String(n.cat_id)) >= 0) { hit = true; return; }
            (n.children || []).forEach(walk);
        })(node);
        return hit;
    }
    function countLeaf(node) {
        var c = 0;
        (node.children || []).forEach(function () { c++; });
        return c;
    }
    function chipsHtml(tree, ids, opt) {
        opt = opt || {};
        ids = (ids || []).map(String);
        if (!ids.length) return '<span class="egcp-none">' + esc(opt.emptyText || '（尚未選擇）') + '</span>';
        return ids.map(function (id) {
            var p = pathOf(tree, id) || ('#' + id);
            return '<span class="egcp-chip">' + esc(p)
                + (opt.removable ? ('<button type="button" class="egcp-chip-x" data-id="' + esc(id) + '" title="取消">&times;</button>') : '')
                + '</span>';
        }).join('');
    }

    var cur = null;   // 目前開著的挑選器狀態

    function close() {
        if (!cur) return;
        if (cur.mask && cur.mask.parentNode) cur.mask.parentNode.removeChild(cur.mask);
        document.removeEventListener('keydown', cur.onKey, true);
        cur = null;
    }

    function render() {
        if (!cur) return;
        var tree = cur.tree, path = cur.path, sel = cur.sel;
        var list = nodesOf(tree, path);
        var lv = path.length + 1;

        // 已選
        cur.$picked.innerHTML = '<b>已選：</b>' + chipsHtml(tree, sel, { removable: true });

        // 麵包屑＋「直接選這一層」
        var crumb = '<span class="egcp-here">第 ' + lv + ' 層</span>';
        if (path.length) {
            var names = path.map(function (id) { var n = findNode(tree, id); return n ? n.name : ('#' + id); });
            crumb = '<b>' + esc(names.join(' → ')) + '</b>';
            var here = path[path.length - 1];
            crumb += '<button type="button" class="egcp-btn" data-act="up">← 上一層</button>';
            crumb += '<button type="button" class="egcp-btn" data-act="self" data-id="' + esc(here) + '">'
                   + (sel.indexOf(String(here)) >= 0 ? '取消選這一層' : '就選「' + esc(names[names.length - 1]) + '」這一層')
                   + '</button>';
        }
        cur.$crumb.innerHTML = crumb;

        // 方塊
        if (!list.length) {
            cur.$grid.innerHTML = '<div class="egcp-empty">這一層底下沒有更細的分類了，請用上面的「就選…這一層」選定，或按「← 上一層」換一個。</div>';
        } else {
            cur.$grid.innerHTML = list.map(function (n) {
                var kids = (n.children || []).length;
                var on = sel.indexOf(String(n.cat_id)) >= 0;
                var mark = (!on && hasSelectedUnder(n, sel)) ? ' has-sel' : '';
                return '<button type="button" class="' + (on ? 'on' : '') + mark + '" data-act="go" data-id="' + esc(n.cat_id) + '">'
                    + esc(n.name)
                    + '<small>' + (kids ? ('往下還有 ' + kids + ' 項') : (on ? '✓ 已選' : '可直接選')) + '</small></button>';
            }).join('');
        }
        cur.$okN.textContent = String(sel.length);
    }

    function pick(id) {
        var sel = cur.sel, k = String(id);
        if (cur.multi) {
            var i = sel.indexOf(k);
            if (i >= 0) sel.splice(i, 1); else sel.push(k);
            render();
        } else {
            cur.sel = (sel.length === 1 && sel[0] === k) ? [] : [k];
            // 單選：點到就是要選它，直接套用關窗（少一次點擊；複選才需要「確定」）
            if (cur.sel.length) { apply(); return; }
            render();
        }
    }
    function apply() {
        var ids = cur.sel.slice(), cb = cur.onApply;
        close();
        if (cb) cb(ids.map(function (x) { return parseInt(x, 10); }));
    }

    function open(opt) {
        opt = opt || {};
        injectCss();
        close();
        var mask = document.createElement('div');
        mask.className = 'egcp-mask';
        mask.innerHTML =
            '<div class="egcp-win" role="dialog">'
            + '<div class="egcp-hd"><b>' + esc(opt.title || '選擇異常原因分類') + '</b>'
            + '<button type="button" class="egcp-x" data-act="close" title="關閉">&times;</button></div>'
            + '<div class="egcp-bd">'
            + '<div class="egcp-tip">' + (opt.tip || (
                '先點<b>第一層</b>，再點<b>第二層</b>、<b>第三層</b>，一層一層往下選。'
                + (opt.multi ? '可以選好幾個（再點一次＝取消），選完按「確定」。'
                             : '<b>只能選一個</b>，點到最後要的那一個就直接套用。')
                + '<br>分類清單由管理員在<b>品質異常處理單 → 設定 → 異常原因分類</b>維護，各表單共用同一份。'))
            + '</div>'
            + '<div class="egcp-picked"></div>'
            + '<div class="egcp-crumb"></div>'
            + '<div class="egcp-grid"></div>'
            + '</div>'
            + '<div class="egcp-ft">'
            + '<button type="button" class="egcp-cancel" data-act="clear">清除全部</button>'
            + '<span class="egcp-sp"></span>'
            + '<button type="button" class="egcp-cancel" data-act="close">取消</button>'
            + '<button type="button" class="egcp-ok" data-act="ok">確定（已選 <b class="egcp-n">0</b> 項）</button>'
            + '</div></div>';
        document.body.appendChild(mask);

        cur = {
            tree: opt.tree || [],
            sel: (opt.selected || []).filter(function (x) { return x !== null && x !== undefined && x !== ''; }).map(String),
            multi: !!opt.multi,
            onApply: opt.onApply,
            path: [],
            mask: mask,
            $picked: mask.querySelector('.egcp-picked'),
            $crumb: mask.querySelector('.egcp-crumb'),
            $grid: mask.querySelector('.egcp-grid'),
            $okN: mask.querySelector('.egcp-n')
        };
        // 已經選過的：直接把畫面停在它的上一層，不用再從第一層點下來
        if (cur.sel.length === 1) {
            var chain = [], target = String(cur.sel[0]);
            (function walk(ns, acc) {
                (ns || []).forEach(function (n) {
                    if (chain.length) return;
                    var next = acc.concat([String(n.cat_id)]);
                    if (String(n.cat_id) === target) { chain = acc; return; }
                    if (n.children && n.children.length) walk(n.children, next);
                });
            })(cur.tree, []);
            cur.path = chain;
        }

        mask.addEventListener('click', function (e) {
            if (e.target === mask) { close(); return; }                 // 點遮罩＝取消
            var b = e.target.closest ? e.target.closest('button') : null;
            if (!b) return;
            var act = b.getAttribute('data-act');
            if (b.classList.contains('egcp-chip-x')) {
                var rid = String(b.getAttribute('data-id'));
                cur.sel = cur.sel.filter(function (x) { return x !== rid; });
                render(); return;
            }
            if (act === 'close') { close(); return; }
            if (act === 'clear') { cur.sel = []; render(); return; }
            if (act === 'ok') { apply(); return; }
            if (act === 'up') { cur.path = cur.path.slice(0, -1); render(); return; }
            if (act === 'self') { pick(b.getAttribute('data-id')); return; }
            if (act === 'go') {
                var id = b.getAttribute('data-id');
                var n = findNode(cur.tree, id);
                if (n && n.children && n.children.length) { cur.path = cur.path.concat([String(id)]); render(); }
                else pick(id);                                           // 最底層＝直接選
                return;
            }
        });
        cur.onKey = function (e) { if (e.key === 'Escape') { e.stopPropagation(); close(); } };
        document.addEventListener('keydown', cur.onKey, true);

        render();
    }

    global.EGCausePicker = {
        open: open, close: close,
        pathOf: pathOf, chipsHtml: chipsHtml, findNode: findNode
    };
})(window);
