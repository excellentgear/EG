/**
 * 客戶／廠商共用：「別名與更名歷史」＋「額外地址」編輯元件（唯一實作，客戶與廠商共用）
 * 後端：src/common/party_name_lib.php
 *
 * 版面預留：<div id="{p}-pe-alias"></div>、<div id="{p}-pe-addr"></div>
 * 隱藏欄位：<input type="hidden" id="{p}-party_aliases" name="party_aliases">
 *           <input type="hidden" id="{p}-party_addrs"   name="party_addrs">
 * 更名日期欄位由元件自己長在別名區塊（id="{p}-rename_date" name="rename_date"）
 *
 * EGPartyExtra.load(p, data)  載入 get_customer / get_maker 回傳的 name_aliases、extra_addrs
 * EGPartyExtra.reset(p)       新增模式：清空
 */
(function (w) {
    'use strict';
    var ST = {};
    var CSS = '#__pe_css';
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
    function el(id) { return document.getElementById(id); }
    function ensureCss() {
        if (el('__pe_css')) return;
        var st = document.createElement('style'); st.id = '__pe_css';
        st.textContent =
            '.pe-box{border:1px solid #E4D3BC;background:#FFFBF4;border-radius:6px;padding:8px 10px;margin:4px 0 10px}' +
            '.pe-title{font-size:12px;font-weight:600;color:#5b3a12;margin-bottom:4px}' +
            '.pe-hint{font-size:11px;color:#8a6d4b;margin:2px 0 6px;line-height:1.5}' +
            '.pe-hist{width:100%;border-collapse:collapse;font-size:12px;margin-bottom:6px}' +
            '.pe-hist th,.pe-hist td{border:1px solid #E4D3BC;padding:3px 6px;text-align:left;vertical-align:top}' +
            '.pe-hist th{background:#F7E7CE;color:#5b3a12;font-weight:600}' +
            '.pe-row{display:flex;gap:6px;align-items:center;margin-bottom:5px}' +
            '.pe-row input{min-width:0}' +
            '.pe-card{border:1px solid #E4D3BC;background:#fff;border-radius:5px;padding:6px 8px;margin-bottom:6px;position:relative}' +
            '.pe-card .pe-g{display:grid;grid-template-columns:repeat(6,1fr);gap:6px;margin-bottom:4px}' +
            '.pe-card label{font-size:11px;color:#8a6d4b;margin:0 0 1px;display:block}' +
            '.pe-btn{border:1px solid #d9a86c;background:#FFF3E0;color:#8a4b0f;border-radius:4px;padding:2px 10px;font-size:12px;cursor:pointer}' +
            '.pe-del{border:0;background:transparent;color:#DD5138;font-size:16px;line-height:1;cursor:pointer;padding:0 4px}' +
            '.view-readonly .pe-edit{display:none !important}';
        document.head.appendChild(st);
    }
    function sync(p) {
        var s = ST[p]; if (!s) return;
        var a = el(p + '-party_aliases'), d = el(p + '-party_addrs');
        if (a) a.value = JSON.stringify(s.manual.filter(function (r) { return String(r.alias_name || '').trim() !== ''; }));
        if (d) d.value = JSON.stringify(s.addrs);
    }
    function renderAlias(p) {
        var box = el(p + '-pe-alias'); if (!box) return;
        var s = ST[p];
        var oldRd = el(p + '-rename_date'); oldRd = oldRd ? oldRd.value : '';
        var h = '<div class="pe-box"><div class="pe-title"><i class="fa fa-history"></i> 別名與更名紀錄</div>';
        h += '<div class="pe-hint">編號永遠不變。公司改名時直接改上面的簡稱／全名即可，舊名稱會<b>自動留存</b>在下方（含誰、何時修改），搜尋與 ERP 匯入仍找得到；歷史單據列印用當時名稱。</div>';
        if (s.hist.length) {
            h += '<table class="pe-hist"><tr><th>舊簡稱</th><th>舊全名</th><th>使用至</th><th>說明</th><th>修改人／時間</th></tr>';
            s.hist.forEach(function (r) {
                h += '<tr><td>' + esc(r.alias_name) + '</td><td>' + esc(r.alias_full) + '</td><td>' + esc(String(r.used_until || '').replace(/-/g, '.')) + '</td><td>' + esc(r.note) + '</td><td>' + esc(r.created_by_name) + '<br>' + esc(r.created_at_txt) + '</td></tr>';
            });
            h += '</table>';
        }
        h += '<div class="pe-title" style="margin-top:4px">手動別名（例如 ERP 慣用寫法、客戶口頭稱呼）</div>';
        s.manual.forEach(function (r, i) {
            h += '<div class="pe-row"><input type="text" class="form-control input-sm" style="flex:2" maxlength="100" placeholder="別名" data-pe="alias_name" data-i="' + i + '" value="' + esc(r.alias_name) + '" data-eg-skip>' +
                 '<input type="text" class="form-control input-sm" style="flex:3" maxlength="200" placeholder="說明（選填）" data-pe="note" data-i="' + i + '" value="' + esc(r.note) + '" data-eg-skip>' +
                 '<button type="button" class="pe-del pe-edit" data-pe-delalias="' + i + '" title="刪除">&times;</button></div>';
        });
        h += '<button type="button" class="pe-btn pe-edit" data-pe-addalias="1"><i class="fa fa-plus"></i> 新增別名</button>';
        h += '<div class="pe-row pe-edit" style="margin-top:8px"><label style="margin:0;font-size:12px;color:#5b3a12">更名生效日</label>' +
             '<input type="date" class="form-control input-sm" style="width:160px" id="' + p + '-rename_date" name="rename_date" data-eg-skip>' +
             '<span class="pe-hint" style="margin:0">只有改了簡稱或全名時才用到；空白＝今天</span></div>';
        h += '</div>';
        box.innerHTML = h;
        var nr = el(p + '-rename_date'); if (nr && oldRd) nr.value = oldRd;
    }
    function cfg() { return w.EGPartyExtra.cfg || {}; }
    function toast(msg, ok) { if (typeof w.showToast === 'function') w.showToast(msg, ok ? 'success' : 'error'); else alert(msg); }
    function typeSelect(s, i, cur) {
        var h = '<div><label>地址類別</label><select class="form-control input-sm" data-pea="addr_type_id" data-i="' + i + '" data-eg-skip>' +
                '<option value="">— 選擇類別 —</option>';
        (s.types || []).forEach(function (t) { h += '<option value="' + t.type_id + '"' + (String(cur) === String(t.type_id) ? ' selected' : '') + '>' + esc(t.type_name) + '</option>'; });
        return h + '</select></div>';
    }
    /** 地址類別管理面板：被使用的類別只能看、不可改名或刪除（後端同規則擋） */
    function renderTypeMgr(p) {
        var s = ST[p], h = '<div class="pe-card" style="background:#FFF8EC"><div class="pe-title">地址類別設定（客戶、廠商共用；已被使用的類別不可修改、不可刪除）</div>';
        (s.types || []).forEach(function (t) {
            var used = parseInt(t.used, 10) || 0;
            h += '<div class="pe-row"><input type="text" class="form-control input-sm" style="flex:1" maxlength="30" value="' + esc(t.type_name) + '" data-pet-name="' + t.type_id + '"' + (used ? ' readonly' : '') + ' data-eg-skip>' +
                 (used ? '<span class="pe-hint" style="margin:0;white-space:nowrap">使用中 ' + used + ' 筆</span>'
                       : '<button type="button" class="pe-btn" data-pet-save="' + t.type_id + '">儲存名稱</button><button type="button" class="pe-btn" style="color:#DD5138" data-pet-del="' + t.type_id + '">刪除</button>') + '</div>';
        });
        h += '<div class="pe-row"><input type="text" class="form-control input-sm" style="flex:1" maxlength="30" placeholder="新類別名稱（例：報關地址）" data-pet-new="1" data-eg-skip><button type="button" class="pe-btn" data-pet-add="1">新增類別</button></div></div>';
        return h;
    }
    function typeCall(p, data) {
        var c = cfg(); if (!c.call) return;
        c.call(data).done(function (r) {
            toast(r.message || (r.success ? '已儲存' : '失敗'), !!r.success);
            if (r.addr_types) {
                var s = ST[p]; s.types = r.addr_types; if (w.EGPartyExtra.cfg) w.EGPartyExtra.cfg.types = r.addr_types;
                s.addrs.forEach(function (a) { if (a.addr_type_id && !s.types.some(function (t) { return String(t.type_id) === String(a.addr_type_id); })) a.addr_type_id = ''; });
                renderAddr(p); sync(p);
            }
        }).fail(function () { toast('網路錯誤', false); });
    }
    function renderAddr(p) {
        var box = el(p + '-pe-addr'); if (!box) return;
        var s = ST[p];
        var h = '<div class="pe-box"><div class="pe-title"><i class="fa fa-map-marker"></i> 其他地址（發票地址、送貨地址、不同廠區…）' +
                (cfg().canManage ? '<button type="button" class="pe-btn pe-edit" style="float:right" data-pe-typemgr="1"><i class="fa fa-cog"></i> 管理地址類別</button>' : '') + '</div>' +
                '<div class="pe-hint">上面是標準地址；這裡可另外加任意多組。「地址類別」從設定好的清單選（例如發票地址、送貨地址），「類別說明」可自由補充（例如：第二廠區）。</div>';
        if (s.mgr && cfg().canManage) h += renderTypeMgr(p);
        s.addrs.forEach(function (r, i) {
            h += '<div class="pe-card"><button type="button" class="pe-del pe-edit" style="position:absolute;right:6px;top:2px" data-pe-deladdr="' + i + '" title="刪除此地址">&times;</button>' +
                 '<div class="pe-g" style="grid-template-columns:1.3fr 1.7fr 1fr 3.5fr">' +
                 typeSelect(s, i, r.addr_type_id) + f(i, 'addr_label', '類別說明', r.addr_label, 60, '例：第二廠區') + f(i, 'zip', '郵遞區號', r.zip, 20, '') + f(i, 'address', '地址', r.address, 255, '') + '</div>' +
                 '<div class="pe-g" style="grid-template-columns:1fr 1fr 1fr">' +
                 f(i, 'tel', '電話', r.tel, 50, '') + f(i, 'fax', '傳真', r.fax, 50, '') + f(i, 'contact', '聯絡人', r.contact, 60, '') + '</div></div>';
        });
        h += '<button type="button" class="pe-btn pe-edit" data-pe-addaddr="1"><i class="fa fa-plus"></i> 新增其他地址</button></div>';
        box.innerHTML = h;
        function f(i, k, lab, v, max, ph) {
            return '<div><label>' + lab + '</label><input type="text" class="form-control input-sm" maxlength="' + max + '" data-pea="' + k + '" data-i="' + i + '" value="' + esc(v) + '"' + (ph ? ' data-eg-hint="' + esc(ph) + '"' : '') + ' data-eg-skip></div>';
        }
    }
    function bind(p) {
        [p + '-pe-alias', p + '-pe-addr'].forEach(function (id) {
            var box = el(id); if (!box || box.__peBound) return; box.__peBound = true;
            box.addEventListener('input', function (e) {
                var t = e.target, s = ST[p]; if (!s) return;
                var i = parseInt(t.getAttribute('data-i'), 10);
                if (t.hasAttribute('data-pe') && s.manual[i]) s.manual[i][t.getAttribute('data-pe')] = t.value;
                else if (t.hasAttribute('data-pea') && s.addrs[i]) s.addrs[i][t.getAttribute('data-pea')] = t.value;
                sync(p);
            });
            box.addEventListener('click', function (e) {
                var b = e.target.closest('button'); if (!b) return; var s = ST[p];
                if (b.hasAttribute('data-pe-typemgr')) { s.mgr = !s.mgr; renderAddr(p); return; }
                if (b.hasAttribute('data-pet-save') || b.hasAttribute('data-pet-del') || b.hasAttribute('data-pet-add')) {
                    var tid = b.getAttribute('data-pet-save') || b.getAttribute('data-pet-del') || '0';
                    if (b.hasAttribute('data-pet-del')) {
                        if (!confirm('確定刪除這個地址類別？')) return;
                        typeCall(p, { action: 'party_addr_type_delete', type_id: tid });
                    } else {
                        var inp = box.querySelector(b.hasAttribute('data-pet-add') ? '[data-pet-new]' : '[data-pet-name="' + tid + '"]');
                        typeCall(p, { action: 'party_addr_type_save', type_id: b.hasAttribute('data-pet-add') ? 0 : tid, type_name: inp ? inp.value : '' });
                    }
                    return;
                }
                if (b.hasAttribute('data-pe-addalias')) { s.manual.push({ alias_id: 0, alias_name: '', note: '' }); renderAlias(p); }
                else if (b.hasAttribute('data-pe-delalias')) { s.manual.splice(parseInt(b.getAttribute('data-pe-delalias'), 10), 1); renderAlias(p); }
                else if (b.hasAttribute('data-pe-addaddr')) { s.addrs.push({ addr_type_id: '', addr_label: '', zip: '', address: '', tel: '', fax: '', contact: '' }); renderAddr(p); }
                else if (b.hasAttribute('data-pe-deladdr')) { s.addrs.splice(parseInt(b.getAttribute('data-pe-deladdr'), 10), 1); renderAddr(p); }
                else return;
                sync(p);
            });
        });
    }
    function load(p, d) {
        ensureCss();
        d = d || {};
        var al = d.name_aliases || [];
        ST[p] = {
            hist: al.filter(function (r) { return r.kind === 'history'; }),
            manual: al.filter(function (r) { return r.kind !== 'history'; }).map(function (r) { return { alias_id: r.alias_id, alias_name: r.alias_name, note: r.note || '' }; }),
            types: d.addr_types || (w.EGPartyExtra.cfg && w.EGPartyExtra.cfg.types) || [],
            mgr: false,
            addrs: (d.extra_addrs || []).map(function (r) { return { addr_type_id: r.addr_type_id || '', addr_label: r.addr_label || '', zip: r.zip || '', address: r.address || '', tel: r.tel || '', fax: r.fax || '', contact: r.contact || '' }; })
        };
        renderAlias(p); renderAddr(p); bind(p); sync(p);
    }
    w.EGPartyExtra = { load: load, reset: function (p) { load(p, {}); } };
})(window);
