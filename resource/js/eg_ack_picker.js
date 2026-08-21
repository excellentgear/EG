/*!
 * eg_ack_picker.js — 「簽收／通知對象」挑選器（部門＋人員混合）的全站唯一實作
 *
 * 為什麼要共用：這個挑選器同時被「圖面變更紀錄頁的登錄跳窗」與「料號附件上傳後
 * 自動跳出的變更登錄跳窗」使用；各頁自刻一份，遲早只改到其中一邊（側欄與輸入欄位
 * 規則都已經這樣失守過）。新的地方要用一律 EGAckPicker.create()，不要複製貼上。
 *
 * 規則：
 *   - 部門一律「含子部門」（組織是樹狀）；後端送過來的 departments[].user_ids
 *     已經是展開後的實際在職成員，前端只負責顯示與去重
 *   - 已選過的項目在清單中反灰不可點
 *   - 人員若已被選中的部門涵蓋，也反灰並註明「已含在『XX』內」，避免重複指定
 *   - 選了部門後，原本個別選、落在該部門內的人自動移除（來源收斂成部門那一筆）
 *   - 即時顯示「實際會通知 N 人」，算法與後端 dwg_expand_ack_targets() 一致
 *   - 人員顯示欄位順序固定「部門／職稱／姓名」（人員列表鐵則）
 *
 * 用法：
 *   var picker = EGAckPicker.create({
 *       chips:'#xx-chips', input:'#xx-q', dropdown:'#xx-dd', summary:'#xx-sum'
 *   });
 *   picker.setData({people:[...], departments:[...]});   // 後端 lookups 回傳的兩個陣列
 *   picker.setSelection({users:[1,2], depts:[5]});       // 編輯既有資料時回填
 *   var sel = picker.getSelection();                     // {users:[], depts:[]}
 *
 * 後端對應：src/common/dwg_change_lib.php 的 dwg_ack_lookup_data() 產生資料、
 *          dwg_expand_ack_targets() 展開存檔，兩邊算法必須一致。
 */
(function (global) {
    'use strict';

    function esc(s) {
        return (s == null ? '' : String(s)).replace(/[&<>"]/g, function (c) {
            return ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'})[c];
        });
    }
    function $one(sel) { return typeof sel === 'string' ? document.querySelector(sel) : sel; }

    var seq = 0;
    var registry = {};

    function create(opt) {
        var id = 'egack' + (++seq);
        var el = {
            chips:    $one(opt.chips),
            input:    $one(opt.input),
            dropdown: $one(opt.dropdown),
            summary:  opt.summary ? $one(opt.summary) : null
        };
        if (!el.chips || !el.input || !el.dropdown) {
            throw new Error('EGAckPicker: chips/input/dropdown 三個容器都必須存在');
        }

        var api = {
            id: id,
            people: [],
            depts: [],
            pickedUsers: [],
            pickedDepts: [],
            // 鎖定（預設）對象：一定會在名單裡，chip 不給 ×，也不會被 clear() 清掉。
            // 用在「系統預設的通知對象，開單者可以再加人但不可移除」這種場景。
            lockedUsers: {},
            lockedDepts: {}
        };
        registry[id] = api;

        /** 已被選中的部門涵蓋到的 user_id → 該部門 */
        function coveredByDept() {
            var cov = {};
            api.pickedDepts.forEach(function (d) {
                (d.user_ids || []).forEach(function (u) { cov[u] = d; });
            });
            return cov;
        }

        function renderList() {
            var q = (el.input.value || '').trim();
            var cov = coveredByDept();
            var pickedU = {}, pickedD = {};
            api.pickedUsers.forEach(function (p) { pickedU[p.id] = 1; });
            api.pickedDepts.forEach(function (d) { pickedD[d.id] = 1; });

            function row(disabled, reason, handler, html) {
                var style = 'padding:5px 9px;font-size:12px;border-bottom:1px solid #f2f4f6;'
                          + (disabled ? 'color:#c3c9d0;background:#fafbfc;cursor:not-allowed;' : 'cursor:pointer;color:#333;');
                var hover = disabled ? ''
                    : ' onmouseover="this.style.background=\'#fff7ec\'" onmouseout="this.style.background=\'\'"';
                return '<div' + (disabled ? '' : ' onclick="' + handler + '"') + ' style="' + style + '"' + hover + '>'
                     + html + (reason ? ' <span style="font-size:10px;color:#c3c9d0;">' + esc(reason) + '</span>' : '')
                     + '</div>';
            }

            var html = '';
            var ds = api.depts.filter(function (d) { return !q || (d.path || d.name || '').indexOf(q) >= 0; });
            if (ds.length) {
                html += '<div style="padding:3px 9px;font-size:10px;color:#999;background:#f7f9fa;font-weight:700;">部門</div>';
                ds.forEach(function (d) {
                    var dis = !!pickedD[d.id];
                    html += row(dis, dis ? '已選' : '', "EGAckPicker._pickDept('" + id + "'," + d.id + ")",
                        '<i class="fa fa-sitemap" style="color:#C77C1A;"></i> ' + esc(d.path || d.name)
                        + ' <span style="font-size:10px;color:#95a5a6;">' + (d.count || 0) + ' 人</span>');
                });
            }
            var ps = api.people.filter(function (p) {
                if (!q) return true;
                return (p.name || '').indexOf(q) >= 0
                    || (p.dept_name || '').indexOf(q) >= 0
                    || (p.position || '').indexOf(q) >= 0;
            });
            if (ps.length) {
                html += '<div style="padding:3px 9px;font-size:10px;color:#999;background:#f7f9fa;font-weight:700;">人員</div>';
                ps.forEach(function (p) {
                    var byDept = cov[p.id];
                    var dis = !!pickedU[p.id] || !!byDept;
                    var reason = pickedU[p.id] ? '已選' : (byDept ? ('已含在「' + (byDept.name || '') + '」內') : '');
                    html += row(dis, reason, "EGAckPicker._pickUser('" + id + "'," + p.id + ")",
                        '<span style="color:#8e6b45;">' + esc(p.dept_name || '—') + '</span>　'
                        + '<span style="color:#95a5a6;">' + esc(p.position || '') + '</span>　'
                        + '<b>' + esc(p.name || '') + '</b>'
                        + (p.leave_note ? ' <span style="font-size:10px;color:#e67e22;">※' + esc(p.leave_note) + '</span>' : ''));
                });
            }
            el.dropdown.innerHTML = html || '<div style="padding:8px 9px;font-size:12px;color:#aaa;">查無符合的部門或人員</div>';
            el.dropdown.style.display = '';
        }

        function renderChips() {
            var h = '';
            api.pickedDepts.forEach(function (d) {
                h += '<span style="display:inline-flex;align-items:center;gap:4px;background:#FFF3E2;color:#8a5a12;'
                   + 'border:1px solid #E4D3BC;border-radius:11px;padding:1px 8px;font-size:12px;">'
                   + '<i class="fa fa-sitemap"></i>' + esc(d.path || d.name) + '（' + (d.count || 0) + '人）'
                   + (api.lockedDepts[d.id]
                        ? '<i class="fa fa-lock" title="系統預設的通知對象，不可移除"></i></span>'
                        : '<a href="javascript:void(0)" onclick="EGAckPicker._delDept(\'' + id + '\',' + d.id + ')" '
                          + 'style="color:#c0392b;text-decoration:none;font-weight:700;">&times;</a></span>');
            });
            api.pickedUsers.forEach(function (p) {
                h += '<span style="display:inline-flex;align-items:center;gap:4px;background:#f0f8ff;color:#1a5276;'
                   + 'border:1px solid #aed6f1;border-radius:11px;padding:1px 8px;font-size:12px;">'
                   + esc((p.dept_name ? p.dept_name + ' ' : '') + (p.position ? p.position + ' ' : '') + p.name)
                   + (api.lockedUsers[p.id]
                        ? '<i class="fa fa-lock" title="系統預設的通知對象，不可移除"></i></span>'
                        : '<a href="javascript:void(0)" onclick="EGAckPicker._delUser(\'' + id + '\',' + p.id + ')" '
                          + 'style="color:#c0392b;text-decoration:none;font-weight:700;">&times;</a></span>');
            });
            el.chips.innerHTML = h
                || '<span style="color:#bbb;font-size:12px;padding:2px 4px;">尚未選擇（下方輸入姓名或部門名稱來加入）</span>';

            if (el.summary) {
                var all = {};
                api.pickedDepts.forEach(function (d) { (d.user_ids || []).forEach(function (u) { all[u] = 1; }); });
                api.pickedUsers.forEach(function (p) { all[p.id] = 1; });
                var n = Object.keys(all).length;
                el.summary.style.color = n ? '#27ae60' : '#7f8c8d';
                el.summary.textContent = n
                    ? ('實際會通知 ' + n + ' 人（部門展開後去重，同一人只會收到一次）')
                    : '尚未指定簽收對象——不指定就不會有人收到通知';
            }
        }

        api._renderList  = renderList;
        api._renderChips = renderChips;

        el.input.addEventListener('input', renderList);
        el.input.addEventListener('focus', renderList);
        // 點清單以外的地方才收起（用 mousedown 會在 click 之前觸發，所以要排除清單自己）
        document.addEventListener('mousedown', function (e) {
            if (!el.dropdown.contains(e.target) && e.target !== el.input) el.dropdown.style.display = 'none';
        });

        api.setData = function (data) {
            api.people = (data && data.people) || [];
            api.depts  = (data && data.departments) || [];
            renderChips();
        };
        api.setSelection = function (sel) {
            var us = ((sel && sel.users) || []).map(Number);
            var ds = ((sel && sel.depts) || []).map(Number);
            api.pickedDepts = api.depts.filter(function (d) { return ds.indexOf(Number(d.id)) >= 0; });
            var cov = coveredByDept();
            api.pickedUsers = api.people.filter(function (p) {
                return us.indexOf(Number(p.id)) >= 0 && !cov[p.id];
            });
            renderChips();
        };
        /**
         * 設定「不可移除的預設對象」：直接併進目前選取，chip 改成鎖頭、不給 ×。
         * 真正的守門在後端（送出時一律再併回去一次），這裡只是讓畫面說得通。
         * 必須在 setData() 之後呼叫（要先有 people/depts 才對得到人）。
         */
        api.setLocked = function (sel) {
            api.lockedUsers = {}; api.lockedDepts = {};
            ((sel && sel.users) || []).forEach(function (u) { api.lockedUsers[Number(u)] = 1; });
            ((sel && sel.depts) || []).forEach(function (d) { api.lockedDepts[Number(d)] = 1; });
            var cur = api.getSelection();
            api.setSelection({
                users: cur.users.concat(Object.keys(api.lockedUsers).map(Number)),
                depts: cur.depts.concat(Object.keys(api.lockedDepts).map(Number))
            });
        };
        api.clear = function () {
            // clear 是「清掉使用者自己加的」，不是「連系統預設對象都清掉」
            api.pickedUsers = []; api.pickedDepts = [];
            el.input.value = '';
            el.dropdown.style.display = 'none';
            api.setSelection({ users: Object.keys(api.lockedUsers).map(Number),
                               depts: Object.keys(api.lockedDepts).map(Number) });
        };
        api.getSelection = function () {
            return {
                users: api.pickedUsers.map(function (p) { return p.id; }),
                depts: api.pickedDepts.map(function (d) { return d.id; })
            };
        };
        /** 展開後實際會通知的人數（與後端同一套算法） */
        api.count = function () {
            var all = {};
            api.pickedDepts.forEach(function (d) { (d.user_ids || []).forEach(function (u) { all[u] = 1; }); });
            api.pickedUsers.forEach(function (p) { all[p.id] = 1; });
            return Object.keys(all).length;
        };

        renderChips();
        return api;
    }

    global.EGAckPicker = {
        create: create,
        _pickDept: function (id, deptId) {
            var a = registry[id]; if (!a) return;
            var d = a.depts.find(function (x) { return Number(x.id) === Number(deptId); });
            if (!d || a.pickedDepts.some(function (x) { return Number(x.id) === Number(deptId); })) return;
            a.pickedDepts.push(d);
            var cov = {}; (d.user_ids || []).forEach(function (u) { cov[u] = 1; });
            a.pickedUsers = a.pickedUsers.filter(function (p) { return !cov[p.id]; });
            a._renderChips(); a._renderList();
        },
        _pickUser: function (id, userId) {
            var a = registry[id]; if (!a) return;
            var p = a.people.find(function (x) { return Number(x.id) === Number(userId); });
            if (!p || a.pickedUsers.some(function (x) { return Number(x.id) === Number(userId); })) return;
            var cov = {};
            a.pickedDepts.forEach(function (d) { (d.user_ids || []).forEach(function (u) { cov[u] = 1; }); });
            if (cov[userId]) return;                       // 已被部門涵蓋，不重複加
            a.pickedUsers.push(p);
            a._renderChips(); a._renderList();
        },
        _delDept: function (id, deptId) {
            var a = registry[id]; if (!a) return;
            if (a.lockedDepts[Number(deptId)]) { alert('這是系統預設的通知對象，不可移除'); return; }
            a.pickedDepts = a.pickedDepts.filter(function (d) { return Number(d.id) !== Number(deptId); });
            a._renderChips(); a._renderList();
        },
        _delUser: function (id, userId) {
            var a = registry[id]; if (!a) return;
            if (a.lockedUsers[Number(userId)]) { alert('這是系統預設的通知對象，不可移除'); return; }
            a.pickedUsers = a.pickedUsers.filter(function (p) { return Number(p.id) !== Number(userId); });
            a._renderChips(); a._renderList();
        }
    };
})(window);
