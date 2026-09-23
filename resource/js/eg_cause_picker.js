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
            '.egcp-cell{position:relative;display:inline-flex;}',
            '.egcp-grid .egcp-cell.has-into>button:first-child{padding-right:38px;}',
            /* 選擇器要比 `.egcp-grid button` 更明確，否則會被大方塊那組樣式蓋掉
               （那條是 class+type＝比單一 class 高，寫 .egcp-into 是吃不到的） */
            '.egcp-grid .egcp-into{position:absolute;right:6px;bottom:6px;min-width:0;width:24px;height:24px;',
            '  min-height:0;border:1px solid #D9A066;background:#FFF8EE;color:#8A5A2B;border-radius:50%;',
            '  padding:0;font-size:15px;font-weight:bold;line-height:20px;text-align:center;cursor:pointer;}',
            '.egcp-grid .egcp-into:hover{background:#F0A24B;color:#3B2A18;border-color:#C0703A;}',
            '.egcp-cell.egcp-just>button{box-shadow:0 0 0 2px #F0A24B;}',
            '.egcp-hint{font-size:12px;color:#8A5A2B;background:#FBEEE6;border:1px solid #F3D9C4;border-radius:5px;',
            '  padding:4px 8px;margin-bottom:8px;}',
            '.egcp-grid button.egcp-add{border-style:dashed;color:#8A5A2B;font-weight:normal;}',
            '.egcp-grid button.egcp-add:hover{background:#FBEEE6;}',
            '.egcp-new{background:#FFF8EE;border:1px solid #E4D3BC;border-radius:6px;padding:8px 10px;margin-bottom:8px;}',
            '.egcp-new input{border:1px solid #D9A066;border-radius:5px;padding:4px 8px;font-size:14px;width:260px;}',
            '.egcp-new .egcp-err{color:#DD5138;font-size:12px;margin-top:4px;}',
            /* ── 維護模式（設定頁用）：右上角鉛筆＝修改，修改面板裡才有刪除 ── */
            '.egcp-grid .egcp-cell.has-pen>button:first-child{padding-right:34px;}',
            '.egcp-grid .egcp-pen{position:absolute;right:5px;top:5px;min-width:0;width:23px;height:23px;min-height:0;',
            '  border:1px solid #D9A066;background:#FFF8EE;color:#8A5A2B;border-radius:50%;padding:0;font-size:12px;',
            '  font-weight:normal;line-height:19px;text-align:center;cursor:pointer;}',
            '.egcp-grid .egcp-pen:hover{background:#F0A24B;color:#3B2A18;border-color:#C0703A;}',
            '.egcp-grid button.off{background:#F5F1EA;color:#9c8b76;border-style:dashed;}',
            '.egcp-ed{background:#FFF8EE;border:1px solid #D9A066;border-radius:6px;padding:10px 12px;margin-bottom:10px;}',
            '.egcp-ed h5{margin:0 0 8px;font-size:14px;color:#8A5A2B;}',
            '.egcp-ed .egcp-row{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:8px;}',
            '.egcp-ed input[type=text]{border:1px solid #D9A066;border-radius:5px;padding:4px 8px;font-size:14px;flex:1 1 220px;min-width:180px;}',
            '.egcp-ed label{font-size:13px;font-weight:normal;margin:0;display:inline-flex;align-items:center;gap:4px;}',
            '.egcp-ed .egcp-use{font-size:12px;background:#fff;border:1px solid #E4D3BC;border-radius:5px;padding:6px 8px;',
            '  line-height:1.7;margin-bottom:8px;max-height:110px;overflow:auto;}',
            '.egcp-ed .egcp-use b{color:#C0703A;}',
            '.egcp-ed .egcp-err{color:#DD5138;font-size:12px;margin-top:4px;}',
            '.egcp-del{border:1px solid #DD5138;background:#fff;color:#DD5138;border-radius:5px;padding:4px 12px;font-size:13px;cursor:pointer;}',
            '.egcp-del:hover{background:#DD5138;color:#fff;}',
            '.egcp-xfer{background:#fff;border:1px solid #DD5138;border-radius:6px;padding:8px 10px;margin-top:8px;}',
            '.egcp-xfer .egcp-flt{width:100%;margin-bottom:6px;}',
            '.egcp-xlist{max-height:170px;overflow:auto;border:1px solid #eee;border-radius:5px;}',
            '.egcp-xlist button{display:block;width:100%;text-align:left;border:0;border-bottom:1px solid #f3f3f3;',
            '  background:#fff;padding:5px 8px;font-size:13px;cursor:pointer;color:#4A3524;}',
            '.egcp-xlist button:hover{background:#FDF6EC;}',
            '.egcp-xlist button.on{background:#F0A24B;color:#3B2A18;font-weight:bold;}',
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

    /* ── 管理員可以就地新增分類（不必離開表單跑去設定頁）────────────────────
       寫入一律打品質異常處理單那支 `cause_save`（唯一實作，管理員判定與三層上限都在那邊），
       這裡不自己寫 SQL、也不自己判權限：畫面上藏起來只是省得誤點，後端會再擋一次（鐵律8）。 */
    function canAddHere() {
        if (!cur || !cur.add || !cur.add.can || !cur.add.url) return false;
        return cur.path.length < 3;          // 最多三層
    }
    function addTile() {
        if (!canAddHere()) return '';
        var where = cur.path.length
            ? ('在「' + (findNode(cur.tree, cur.path[cur.path.length - 1]) || {}).name + '」底下新增')
            : '新增第一層分類';
        return '<button type="button" class="egcp-add" data-act="addnew">＋ ' + esc(where)
             + '<small>管理員限定，新增後全站表單一起看得到</small></button>';
    }
    function showNewRow() {
        var where = cur.path.length
            ? ('「' + (findNode(cur.tree, cur.path[cur.path.length - 1]) || {}).name + '」底下的第 ' + (cur.path.length + 1) + ' 層')
            : '第 1 層';
        cur.$new.innerHTML = '<b style="font-size:13px;">新增 ' + esc(where) + '分類：</b> '
            + '<input type="text" class="egcp-nm" maxlength="60" placeholder="輸入分類名稱…"> '
            + '<button type="button" class="egcp-ok" data-act="addsave">建立</button> '
            + '<button type="button" class="egcp-cancel" data-act="addcancel">取消</button>'
            + '<div class="egcp-err"></div>';
        cur.$new.style.display = '';
        var i = cur.$new.querySelector('.egcp-nm');
        if (i) { i.focus(); i.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); doAdd(); } }); }
    }
    function hideNewRow() { cur.$new.style.display = 'none'; cur.$new.innerHTML = ''; }
    function onlyActive(list) {           // cause_save 回的樹含停用中的，挑選畫面只要啟用的
        return (list || []).filter(function (n) { return Number(n.is_active) !== 0; })
            .map(function (n) { var c = {}; for (var k in n) c[k] = n[k]; c.children = onlyActive(n.children); return c; });
    }
    function doAdd() {
        if (!canAddHere()) return;
        var $i = cur.$new.querySelector('.egcp-nm'), $e = cur.$new.querySelector('.egcp-err');
        var name = ($i.value || '').trim();
        if (!name) { $e.textContent = '請輸入分類名稱'; $i.focus(); return; }
        var sibs = nodesOf(cur.tree, cur.path);
        for (var k = 0; k < sibs.length; k++) {
            if (String(sibs[k].name).trim() === name) { $e.textContent = '這一層已經有同名的分類了'; $i.focus(); return; }
        }
        var sort = 0;
        sibs.forEach(function (n) { sort = Math.max(sort, parseInt(n.sort_order, 10) || 0); });
        $e.textContent = '建立中…';
        var body = 'action=cause_save&csrf=' + encodeURIComponent(cur.add.csrf || '')
                 + '&name=' + encodeURIComponent(name)
                 + '&parent_id=' + encodeURIComponent(cur.path.length ? cur.path[cur.path.length - 1] : 0)
                 + '&sort_order=' + (sort + 1) + '&is_active=1';
        fetch(cur.add.url, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, body: body
        }).then(function (r) { return r.json(); }).then(function (res) {
            if (!res || !res.success) { $e.textContent = (res && res.message) || '建立失敗'; return; }
            // 維護模式要連停用的一起看得到（挑選模式只要啟用中的）
            cur.tree = isManage() ? (res.causes || []) : onlyActive(res.causes || []);
            var newId = parseInt(res.cat_id, 10) || 0;
            hideNewRow();
            /* 新建的分類底下一定是空的＝在畫面上會被當成「最底層、點了就選它」，
               所以還可以再往下一層時，**直接帶進它底下**，使用者才看得到「＋ 在○○底下新增」
               （使用者回報：新增完第二層沒有出現可以新增第三層的畫面就被自動選定）。 */
            if (newId && cur.path.length < 2) {
                cur.path = cur.path.concat([String(newId)]);
                cur.justAdded = 0;
                cur.hint = '已新增「<b>' + esc(name) + '</b>」，現在在它底下。要再往下加一層就按「＋ 在「'
                         + esc(name) + '」底下新增」；要直接用這一層，按上面的「就選「' + esc(name) + '」這一層」；'
                         + '要回去加同一層的，按「← 上一層」。';
            } else {
                cur.justAdded = newId;
                cur.hint = '已新增「<b>' + esc(name) + '</b>」，點它就可以選定。';
            }
            render();
            if (cur.onTreeChange) cur.onTreeChange(cur.tree);
        }).catch(function () { $e.textContent = '建立失敗，請重新整理頁面後再試'; });
    }


    /* ══════════════════════════════════════════════════════════════════════
       維護模式（管理員在「品質異常處理單 → 設定 → 異常原因分類」用）
       ----------------------------------------------------------------------
       2026-09-23 使用者要求：設定頁那張表格不好用，改成跟挑選畫面同一套大方塊，
       「修改用筆圖示表示在選項右上角，刪除則需要進去修改才能按刪除」。
       所以維護模式與挑選模式共用同一份方塊與同一套逐層瀏覽，差別只有三件事：
         ① 點方塊＝往下一層看（不是選它）   ② 右上角多一顆鉛筆＝開修改面板
         ③ 底部沒有「確定」，改的東西當下就存
       寫入一律打後端 cause_save／cause_del／cause_move／cause_usage（唯一實作），
       這裡不自己算使用筆數、也不自己判權限（後端會再擋一次＝鐵律8）。
       ══════════════════════════════════════════════════════════════════════ */
    function isManage() { return !!(cur && cur.manage && cur.manage.can); }

    function mgPost(action, params, ok, fail) {
        var body = 'action=' + encodeURIComponent(action)
                 + '&csrf=' + encodeURIComponent((cur.manage && cur.manage.csrf) || '');
        Object.keys(params || {}).forEach(function (k) {
            body += '&' + k + '=' + encodeURIComponent(params[k] === null || params[k] === undefined ? '' : params[k]);
        });
        fetch(cur.manage.url, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, body: body
        }).then(function (r) { return r.json(); }).then(function (res) {
            if (res && res.success) { ok(res); return; }
            if (fail) fail(res || {}); else alert((res && res.message) || '操作失敗');
        }).catch(function () {
            if (fail) fail({ message: '連線失敗，請重新整理頁面後再試' });
            else alert('連線失敗，請重新整理頁面後再試');
        });
    }

    /** 換掉整棵樹之後要把畫面與呼叫端一起更新（停用的分類在維護模式一樣要看得到） */
    function mgAfterTree(res) {
        cur.tree = res.causes || [];
        if (cur.onTreeChange) cur.onTreeChange(cur.tree);
    }

    function openEdit(id) {
        var n = findNode(cur.tree, id);
        if (!n) return;
        cur.edit = { id: String(id), name: n.name, active: Number(n.is_active) !== 0, usage: null, xfer: false, to: '', flt: '' };
        hideNewRow();
        renderEdit();
        loadUsage(id);
        render();
    }
    function closeEdit() { cur.edit = null; renderEdit(); render(); }

    function loadUsage(id) {
        mgPost('cause_usage', { cat_id: id }, function (res) {
            if (!cur || !cur.edit || String(cur.edit.id) !== String(id)) return;   // 已經換一個在改了
            cur.edit.usage = res;
            renderEdit();
        }, function (res) {
            if (!cur || !cur.edit) return;
            cur.edit.usage = { err: (res && res.message) || '查不到使用情形' };
            renderEdit();
        });
    }

    function usageHtml(u) {
        if (!u) return '<span style="color:#8a6a45;">使用情形查詢中…</span>';
        if (u.err) return '<span style="color:#DD5138;">' + esc(u.err) + '</span>';
        var t = (u.usage && u.usage.total) || 0;
        if (!t) return '目前<b>沒有</b>任何異常單或矯正單選用這個分類（含底下的下層分類），可以安全修改或刪除。';
        var ab = u.usage.ab || { cnt: 0, docs: [] }, car = u.usage.car || { cnt: 0, docs: [] };
        var h = '已經有 <b>' + t + '</b> 張單據選用這個分類';
        h += (u.kids ? '（含底下 ' + u.kids + ' 個下層分類）' : '') + '：<br>';
        if (ab.cnt) h += '　異常單 <b>' + ab.cnt + '</b> 張：' + esc(ab.docs.join('、'))
                      + (ab.cnt > ab.docs.length ? ' …等' : '') + '<br>';
        if (car.cnt) h += '　矯正單 <b>' + car.cnt + '</b> 張：' + esc(car.docs.join('、'))
                       + (car.cnt > car.docs.length ? ' …等' : '') + '<br>';
        h += '<span style="color:#8a6a45;">改名稱不會影響這些單據的歸屬，但它們顯示的分類名稱會一起變成新名稱；'
           + '要刪除的話必須指定移轉到哪一個分類。</span>';
        return h;
    }

    /** 移轉目標的候選：全部分類扣掉自己與自己的子孫（移到自己底下＝刪完一樣會不見） */
    function xferCandidates(excludeId) {
        var bad = {}, out = [];
        (function mark(n) { bad[String(n.cat_id)] = 1; (n.children || []).forEach(mark); })(findNode(cur.tree, excludeId) || { cat_id: excludeId, children: [] });
        (function walk(ns, prefix) {
            (ns || []).forEach(function (n) {
                var p = prefix ? (prefix + ' → ' + n.name) : n.name;
                if (!bad[String(n.cat_id)]) out.push({ id: String(n.cat_id), path: p, active: Number(n.is_active) !== 0 });
                walk(n.children, p);
            });
        })(cur.tree, '');
        return out;
    }

    function renderEdit() {
        if (!cur) return;
        if (!cur.edit) { cur.$ed.style.display = 'none'; cur.$ed.innerHTML = ''; return; }
        var e = cur.edit;
        /* 使用情形是非同步查回來的，回來時會重畫這個面板——要先把「使用者已經打到一半的
           名稱」接回來，否則查詢一回來就把剛打的字洗掉（而且完全看不出原因）。 */
        var $nm = cur.$ed.querySelector('.egcp-ed-nm');
        if ($nm) {
            e.name = $nm.value;
            var $ac = cur.$ed.querySelector('.egcp-ed-act');
            if ($ac) e.active = $ac.checked;
        }
        var full = pathOf(cur.tree, e.id) || e.name;
        var h = '<h5><i class="fa fa-pencil"></i> 修改分類：' + esc(full) + '</h5>'
              + '<div class="egcp-row">'
              + '<input type="text" class="egcp-ed-nm" maxlength="60" value="' + esc(e.name) + '" placeholder="分類名稱">'
              + '<label><input type="checkbox" class="egcp-ed-act"' + (e.active ? ' checked' : '') + '> 啟用</label>'
              + '<button type="button" class="egcp-btn" data-act="mgup" title="在同一層往前移">↑</button>'
              + '<button type="button" class="egcp-btn" data-act="mgdown" title="在同一層往後移">↓</button>'
              + '<button type="button" class="egcp-ok" data-act="mgsave">儲存</button>'
              + '<button type="button" class="egcp-cancel" data-act="mgclose">關閉</button>'
              + '</div>'
              + '<div class="egcp-use">' + usageHtml(e.usage) + '</div>'
              + '<div class="egcp-row"><button type="button" class="egcp-del" data-act="mgdel">'
              + '<i class="fa fa-trash"></i> 刪除這個分類</button>'
              + '<span style="font-size:12px;color:#8a6a45;">停用＝既有單據仍看得到，新單不再出現（比刪除安全）。</span></div>'
              + '<div class="egcp-err"></div>';

        if (e.xfer) {
            var cands = xferCandidates(e.id).filter(function (c) {
                if (!e.flt) return true;
                return c.path.toLowerCase().indexOf(String(e.flt).toLowerCase()) >= 0;
            });
            h += '<div class="egcp-xfer">'
               + '<div style="font-weight:bold;color:#DD5138;margin-bottom:6px;">'
               + '這個分類已經有單據在用，要刪除的話請先選一個「改成哪一個分類」，'
               + '按下之後會<b>自動把全部單據移轉過去</b>再刪除。</div>'
               + '<input type="text" class="egcp-flt" placeholder="輸入關鍵字縮小範圍…" value="' + esc(e.flt || '') + '">'
               + '<div class="egcp-xlist">'
               + (cands.length ? cands.map(function (c) {
                     return '<button type="button" data-act="mgto" data-id="' + esc(c.id) + '"'
                          + (String(e.to) === c.id ? ' class="on"' : '') + '>' + esc(c.path)
                          + (c.active ? '' : '（已停用）') + '</button>';
                 }).join('') : '<div style="padding:8px;color:#8a6a45;font-size:13px;">沒有符合的分類</div>')
               + '</div>'
               + '<div class="egcp-row" style="margin:8px 0 0;">'
               + '<button type="button" class="egcp-del" data-act="mgdelgo"'
               + (e.to ? '' : ' disabled style="opacity:.5;cursor:not-allowed;"') + '>移轉並刪除</button>'
               + '<button type="button" class="egcp-cancel" data-act="mgxcancel">取消</button>'
               + '</div></div>';
        }
        cur.$ed.innerHTML = h;
        cur.$ed.style.display = '';
    }

    function mgSave() {
        var e = cur.edit, $e = cur.$ed.querySelector('.egcp-err');
        var nm = (cur.$ed.querySelector('.egcp-ed-nm').value || '').trim();
        var act = cur.$ed.querySelector('.egcp-ed-act').checked ? 1 : '';
        if (!nm) { $e.textContent = '請填分類名稱'; return; }
        var n = findNode(cur.tree, e.id) || {};
        $e.textContent = '儲存中…';
        mgPost('cause_save', { cat_id: e.id, name: nm, parent_id: n.parent_id || '',
                               sort_order: n.sort_order || 0, is_active: act },
            function (res) {
                mgAfterTree(res);
                cur.edit.name = nm; cur.edit.active = !!act;
                cur.hint = '已儲存「<b>' + esc(nm) + '</b>」。';
                renderEdit(); render();
            },
            function (res) { $e.textContent = (res && res.message) || '儲存失敗'; });
    }

    function mgMove(dir) {
        var e = cur.edit, $e = cur.$ed.querySelector('.egcp-err');
        $e.textContent = '';
        mgPost('cause_move', { cat_id: e.id, dir: dir }, function (res) {
            mgAfterTree(res); renderEdit(); render();
        }, function (res) { $e.textContent = (res && res.message) || '移動失敗'; });
    }

    function mgDelete() {
        var e = cur.edit, $e = cur.$ed.querySelector('.egcp-err');
        var u = e.usage;
        if (!u || u.err) { $e.textContent = '使用情形還沒查回來，請稍候再按一次'; return; }
        if (u.kids) { $e.textContent = '這個分類底下還有 ' + u.kids + ' 個下層分類，請先刪除或移走下層，再刪除它'; return; }
        var total = (u.usage && u.usage.total) || 0;
        if (total > 0) {           // 有單據在用 → 一定要先挑移轉目標
            cur.edit.xfer = true; renderEdit(); return;
        }
        if (!confirm('確定要刪除「' + (pathOf(cur.tree, e.id) || e.name) + '」？\n目前沒有任何單據選用它。')) return;
        doDelete(0);
    }
    function doDelete(toId) {
        var e = cur.edit, $e = cur.$ed.querySelector('.egcp-err');
        $e.textContent = '處理中…';
        mgPost('cause_del', { cat_id: e.id, to_cat_id: toId || 0 }, function (res) {
            mgAfterTree(res);
            var m = res.moved || {};
            var n = (m.ab || 0) + (m.car || 0);
            cur.edit = null;
            /* 刪掉的如果正好是目前停留的那一層，畫面要退回上一層，否則會停在一個不存在的節點 */
            if (cur.path.length && !findNode(cur.tree, cur.path[cur.path.length - 1])) cur.path = cur.path.slice(0, -1);
            cur.hint = '已刪除「<b>' + esc(res.deleted || '') + '</b>」'
                     + (res.moved_to ? ('，原本選用它的單據已全部移轉到「<b>' + esc(res.moved_to) + '</b>」'
                                        + (n ? ('（' + n + ' 筆）') : '')) : '') + '。';
            renderEdit(); render();
        }, function (res) { $e.textContent = (res && res.message) || '刪除失敗'; });
    }

    function close() {
        if (!cur) return;
        var onClose = cur.onClose, tree = cur.tree;
        if (cur.mask && cur.mask.parentNode) cur.mask.parentNode.removeChild(cur.mask);
        document.removeEventListener('keydown', cur.onKey, true);
        // 還原底下那個 Bootstrap 跳窗的焦點鎖（開啟時為了讓輸入框打得了字而暫時拿掉）
        if (cur.focusFreed && global.jQuery) {
            try {
                var $m = global.jQuery('.modal.in').last();
                var inst = $m.length ? $m.data('bs.modal') : null;
                if (inst && typeof inst.enforceFocus === 'function') inst.enforceFocus();
            } catch (e) {}
        }
        cur = null;
        if (onClose) onClose(tree);      // 設定頁要用它把畫面上的分類總覽重畫
    }

    function render() {
        if (!cur) return;
        var tree = cur.tree, path = cur.path, sel = cur.sel;
        var list = nodesOf(tree, path);
        var lv = path.length + 1;

        var mg = isManage();

        // 已選（維護模式沒有「選」這件事）
        if (mg) { cur.$picked.style.display = 'none'; }
        else cur.$picked.innerHTML = '<b>已選：</b>' + chipsHtml(tree, sel, { removable: true });

        // 麵包屑＋「直接選這一層」／維護模式的「修改這一層」
        var crumb = '<span class="egcp-here">第 ' + lv + ' 層</span>';
        if (path.length) {
            var names = path.map(function (id) { var n = findNode(tree, id); return n ? n.name : ('#' + id); });
            crumb = '<b>' + esc(names.join(' → ')) + '</b>';
            var here = path[path.length - 1];
            crumb += '<button type="button" class="egcp-btn" data-act="up">← 上一層</button>';
            crumb += mg
                ? ('<button type="button" class="egcp-btn" data-act="pen" data-id="' + esc(here) + '">'
                   + '✎ 修改「' + esc(names[names.length - 1]) + '」這一層</button>')
                : ('<button type="button" class="egcp-btn" data-act="self" data-id="' + esc(here) + '">'
                   + (sel.indexOf(String(here)) >= 0 ? '取消選這一層' : '就選「' + esc(names[names.length - 1]) + '」這一層')
                   + '</button>');
        }
        cur.$crumb.innerHTML = crumb;

        // 提示列（只在剛新增完那一次顯示）
        cur.$hint.style.display = cur.hint ? '' : 'none';
        cur.$hint.innerHTML = cur.hint || '';

        // 方塊
        if (!list.length) {
            cur.$grid.innerHTML = '<div class="egcp-empty">這一層底下還沒有更細的分類'
                                + (canAddHere() ? '，可以用下面的「＋」新增一個'
                                               : (path.length >= 3 ? '（已經是第三層，不能再往下）' : ''))
                                + '；'
                                + (mg ? '按「← 上一層」可以換一個。' : '也可以用上面的「就選…這一層」直接選定，或按「← 上一層」換一個。')
                                + '</div>'
                                + addTile();
        } else {
            cur.$grid.innerHTML = list.map(function (n) {
                var kids = (n.children || []).length;
                var on = !mg && sel.indexOf(String(n.cat_id)) >= 0;
                var mark = (!mg && !on && hasSelectedUnder(n, sel)) ? ' has-sel' : '';
                var just = (String(n.cat_id) === String(cur.justAdded)) ? ' egcp-just' : '';
                var off = Number(n.is_active) === 0 ? ' off' : '';
                /* 沒有子項的分類本來就是「點了就選它」。但管理員要在它底下再加一層時，
                   一路點下去只會變成選取、永遠進不去（使用者回報：新增完第二層就被自動選定，
                   看不到新增第三層的畫面）——所以多一顆窄的「＋」把「選它」與「進去它底下」分開。
                   維護模式沒有這個問題（點方塊本來就是往下看），所以只長鉛筆不長「＋」。 */
                var canInto = !mg && !kids && cur.add && cur.add.can && cur.path.length < 2;
                return '<span class="egcp-cell' + just + (canInto ? ' has-into' : '') + (mg ? ' has-pen' : '') + '">'
                    + '<button type="button" class="' + (on ? 'on' : '') + mark + off + '" data-act="go" data-id="' + esc(n.cat_id) + '">'
                    + esc(n.name)
                    + '<small>' + (mg
                        ? ((kids ? ('底下有 ' + kids + ' 項') : '底下沒有下層') + (off ? '｜已停用' : ''))
                        : (kids ? ('往下還有 ' + kids + ' 項') : (on ? '✓ 已選' : '可直接選')))
                      + '</small></button>'
                    + (canInto ? ('<button type="button" class="egcp-into" data-act="into" data-id="' + esc(n.cat_id)
                                  + '" title="在「' + esc(n.name) + '」底下再加一層（不是選它）">＋</button>') : '')
                    + (mg ? ('<button type="button" class="egcp-pen" data-act="pen" data-id="' + esc(n.cat_id)
                             + '" title="修改「' + esc(n.name) + '」（名稱／停用／排序／刪除）">&#9998;</button>') : '')
                    + '</span>';
            }).join('') + addTile();
        }
        if (cur.$okN) cur.$okN.textContent = String(sel.length);
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
        var manage = (opt.manage && opt.manage.can) ? opt.manage : null;
        var mask = document.createElement('div');
        mask.className = 'egcp-mask';
        mask.innerHTML =
            '<div class="egcp-win" role="dialog">'
            + '<div class="egcp-hd"><b>' + esc(opt.title || (manage ? '維護異常原因分類' : '選擇異常原因分類')) + '</b>'
            + '<button type="button" class="egcp-x" data-act="close" title="關閉">&times;</button></div>'
            + '<div class="egcp-bd">'
            + '<div class="egcp-tip">' + (opt.tip || (manage
                ? ('<b>點方塊</b>＝進去看它底下的分類；<b>右上角的 ✎</b>＝修改這一個（名稱、停用、排序，<b>刪除也在裡面</b>）；'
                   + '<b>＋</b>＝在目前這一層新增。最多三層（例：人 → 操作疏失 → 未依SOP）。'
                   + '<br>改好當下就存檔，不必再按儲存。<b>修改與刪除前會自動查「有沒有異常單／矯正單已經選了它」</b>；'
                   + '要刪除有單據在用的分類時，會請你指定移轉到哪一個分類，按下去就自動全部移轉。')
                : ('先點<b>第一層</b>，再點<b>第二層</b>、<b>第三層</b>，一層一層往下選。'
                   + (opt.multi ? '可以選好幾個（再點一次＝取消），選完按「確定」。'
                                : '<b>只能選一個</b>，點到最後要的那一個就直接套用。')
                   + '<br>分類清單由管理員在<b>品質異常處理單 → 設定 → 異常原因分類</b>維護，各表單共用同一份。')))
            + '</div>'
            + '<div class="egcp-picked"></div>'
            + '<div class="egcp-ed" style="display:none;"></div>'
            + '<div class="egcp-new" style="display:none;"></div>'
            + '<div class="egcp-hint" style="display:none;"></div>'
            + '<div class="egcp-crumb"></div>'
            + '<div class="egcp-grid"></div>'
            + '</div>'
            + '<div class="egcp-ft">'
            + (manage
                ? ('<span style="font-size:12px;color:#8a6a45;">分類是各張表單共用的，改了之後品質異常處理單、'
                   + '異常矯正處理單、客退單都會一起變。</span><span class="egcp-sp"></span>'
                   + '<button type="button" class="egcp-ok" data-act="close">完成，關閉</button>')
                : ('<button type="button" class="egcp-cancel" data-act="clear">清除全部</button>'
                   + '<span class="egcp-sp"></span>'
                   + '<button type="button" class="egcp-cancel" data-act="close">取消</button>'
                   + '<button type="button" class="egcp-ok" data-act="ok">確定（已選 <b class="egcp-n">0</b> 項）</button>'))
            + '</div></div>';
        document.body.appendChild(mask);

        cur = {
            tree: opt.tree || [],
            sel: (opt.selected || []).filter(function (x) { return x !== null && x !== undefined && x !== ''; }).map(String),
            multi: !!opt.multi,
            onApply: opt.onApply,
            /* 維護模式本來就是管理員在用，新增那一套（＋ 方塊）直接沿用同一份設定 */
            add: opt.add || (manage ? { can: true, url: manage.url, csrf: manage.csrf } : null),
            manage: manage,
            edit: null,
            onTreeChange: opt.onTreeChange,
            onClose: opt.onClose,
            path: [],
            mask: mask,
            $ed: mask.querySelector('.egcp-ed'),
            $picked: mask.querySelector('.egcp-picked'),
            $crumb: mask.querySelector('.egcp-crumb'),
            $grid: mask.querySelector('.egcp-grid'),
            $new: mask.querySelector('.egcp-new'),
            $hint: mask.querySelector('.egcp-hint'),
            hint: '', justAdded: 0,
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
            /* ── 維護模式 ── */
            if (act === 'pen') { openEdit(b.getAttribute('data-id')); return; }
            if (act === 'mgclose') { closeEdit(); return; }
            if (act === 'mgsave') { mgSave(); return; }
            if (act === 'mgup') { mgMove('up'); return; }
            if (act === 'mgdown') { mgMove('down'); return; }
            if (act === 'mgdel') { mgDelete(); return; }
            if (act === 'mgto') { cur.edit.to = String(b.getAttribute('data-id')); renderEdit(); return; }
            if (act === 'mgxcancel') { cur.edit.xfer = false; cur.edit.to = ''; renderEdit(); return; }
            if (act === 'mgdelgo') {
                if (!cur.edit.to) return;
                var toPath = pathOf(cur.tree, cur.edit.to) || ('#' + cur.edit.to);
                if (!confirm('確定要把目前選用「' + (pathOf(cur.tree, cur.edit.id) || cur.edit.name)
                           + '」的所有異常單／矯正單，全部改成「' + toPath + '」，然後刪除這個分類？')) return;
                doDelete(cur.edit.to);
                return;
            }
            if (act === 'into') { cur.path = cur.path.concat([String(b.getAttribute('data-id'))]);
                                  cur.hint = ''; cur.justAdded = 0; hideNewRow(); render(); return; }
            if (act === 'addnew') { showNewRow(); return; }
            if (act === 'addcancel') { hideNewRow(); return; }
            if (act === 'addsave') { doAdd(); return; }
            if (act === 'up') { cur.path = cur.path.slice(0, -1); cur.hint = ''; cur.justAdded = 0;
                                hideNewRow(); render(); return; }
            if (act === 'self') { pick(b.getAttribute('data-id')); return; }
            if (act === 'go') {
                var id = b.getAttribute('data-id');
                var n = findNode(cur.tree, id);
                // 維護模式：點方塊一律是「進去看它底下」，沒有「選它」這件事
                if (isManage() || (n && n.children && n.children.length)) {
                    cur.path = cur.path.concat([String(id)]);
                    cur.hint = ''; cur.justAdded = 0; hideNewRow();
                    if (cur.edit) { cur.edit = null; renderEdit(); }
                    render();
                } else pick(id);                                         // 最底層＝直接選
                return;
            }
        });
        // 移轉目標的關鍵字篩選：只重畫清單那一塊，整個面板重畫會讓輸入框失去焦點
        mask.addEventListener('input', function (e) {
            if (!cur || !cur.edit || !e.target.classList.contains('egcp-flt')) return;
            cur.edit.flt = e.target.value || '';
            var $l = cur.$ed.querySelector('.egcp-xlist');
            if (!$l) return;
            var cands = xferCandidates(cur.edit.id).filter(function (c) {
                return !cur.edit.flt || c.path.toLowerCase().indexOf(cur.edit.flt.toLowerCase()) >= 0;
            });
            $l.innerHTML = cands.length ? cands.map(function (c) {
                return '<button type="button" data-act="mgto" data-id="' + esc(c.id) + '"'
                     + (String(cur.edit.to) === c.id ? ' class="on"' : '') + '>' + esc(c.path)
                     + (c.active ? '' : '（已停用）') + '</button>';
            }).join('') : '<div style="padding:8px;color:#8a6a45;font-size:13px;">沒有符合的分類</div>';
        });
        /* Bootstrap 3 的 modal 會在 document 上掛 focusin.bs.modal，只要焦點跑到跳窗外面就
           立刻搶回去（enforceFocus）——這個挑選視窗刻意掛在 body 上、不依賴 Bootstrap modal
           （見檔頭），於是輸入框一拿到焦點就被搶走＝**打不了字**（使用者回報）。
           **在遮罩上攔 focusin 沒有用**：jQuery 的 focusin 是用 document 的 capture 階段實作的，
           比我們的冒泡監聽先跑。所以改成「開著的時候先把那個監聽拿掉、關閉時再裝回去」，
           也不必去改 Bootstrap 本身（那是全站共用的版型檔）。 */
        if (global.jQuery && global.jQuery('.modal.in').length) {
            global.jQuery(document).off('focusin.bs.modal');
            cur.focusFreed = true;            // 關閉時再把焦點鎖還給底下那個跳窗
        }

        cur.onKey = function (e) { if (e.key === 'Escape') { e.stopPropagation(); close(); } };
        document.addEventListener('keydown', cur.onKey, true);

        render();
    }

    global.EGCausePicker = {
        open: open, close: close,
        pathOf: pathOf, chipsHtml: chipsHtml, findNode: findNode
    };
})(window);
