/* 申請採購 —— 前端（views/pages/purchase_request.php 專用）
 * 資料一律走 src/store/Purchase_API.php；金額、統計、匯出都由後端算全量，前端只負責顯示。
 */
(function () {
'use strict';

var API = '../../src/store/Purchase_API.php';
var META = null, TAB = 'mine', PAGE = 1, PS = 10, MPAGE = 1, MPS = 20;
var CUR = null;                 // 目前開啟的單據
var EDIT = { id: 0, items: [], atts: [], tempAtts: [] };
var ITEMEDIT = { id: 0, specs: [], attrs: [], tags: [] };
var DELID = 0;
var FULL = false;               // 申請單版型：true=採購版（多找採購品/標題/預估單價/到貨處理/附件分類）
var ATT_TYPE = 'other';         // 附件類別（精簡版一律 other，採購版由標籤點選）
var REQPP = null;               // 單頭用途歸屬 {type, order_id, bom, d_id, note, label}
var PP = { target: null, cur: null };   // 用途選擇 modal 的當下狀態（target='req' 或某個 <tr>）

/* ── 共用小工具 ─────────────────────────────── */
function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
/** 小數尾 0 省略：3.50→3.5、3.00→3 */
function nz(v) { if (v === null || v === '' || v === undefined) return ''; var n = parseFloat(v);
    if (isNaN(n)) return ''; return String(parseFloat(n.toFixed(4))); }
// 金額：null＝角色沒有「看得到金額」權限，後端已挖掉，顯示遮蔽符號而不是 0
function money(v) {
    if (v === null || v === undefined) return '＊＊＊';
    var n = parseFloat(v || 0); return n.toLocaleString('zh-TW', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
}
function api(action, data, method) {
    var opt = { url: API, dataType: 'json' };
    if (method === 'POST') { opt.type = 'POST'; opt.data = $.extend({ action: action }, data || {}); }
    else { opt.type = 'GET'; opt.data = $.extend({ action: action }, data || {}); }
    return $.ajax(opt).then(function (r) {
        if (!r || !r.ok) return $.Deferred().reject(r && r.error ? r.error : '未知錯誤');
        return r;
    }, function (xhr) {
        var msg = '連線失敗';
        try { var j = JSON.parse(xhr.responseText); if (j.error) msg = j.error; } catch (e) {}
        return $.Deferred().reject(msg);
    });
}
function fail(msg) { alert('操作失敗：' + msg); }
window.closeMask = function (id) { $('#' + id).removeClass('show'); };
function openMask(id) { $('#' + id).addClass('show'); }
function pill(st) { return '<span class="pill ' + esc(st) + '">' + esc((META.statuses || {})[st] || st) + '</span>'; }
function unitLabel(uid) { var u = (META.units || []).filter(function (x) { return String(x.unit_id) === String(uid); })[0];
    return u ? (u.unit_symbol || u.unit_name) : ''; }
function locName(lid) { var l = (META.locations || []).filter(function (x) { return String(x.location_id) === String(lid); })[0];
    return l ? l.location_code : ''; }
function optList(arr, valKey, txtKey, sel, blank) {
    var h = blank ? '<option value="">' + esc(blank) + '</option>' : '';
    (arr || []).forEach(function (o) {
        h += '<option value="' + esc(o[valKey]) + '"' + (String(o[valKey]) === String(sel) ? ' selected' : '') + '>' +
             esc(o[txtKey]) + '</option>';
    });
    return h;
}

/* ── UI 規範：雙擊清空 / 聚焦全選 / Enter 跳下一欄 / 表格上下鍵 ── */
function bindInputUX() {
    var $doc = $(document);
    // 聚焦已有資料的欄位自動全選
    $doc.on('focus', 'input[type=text],input[type=number],input[type=date]', function () {
        var el = this;
        if (el.value) { try { el.select(); } catch (e) {} }
    });
    // 有值雙擊清空（篩選欄同時解除該欄篩選＝重查）
    $doc.on('dblclick', 'input[type=text],input[type=number],input[type=date],select', function () {
        var $t = $(this);
        if ($t.is('select')) { if (!$t.val()) return; $t.val(''); } else { if (!this.value) return; this.value = ''; }
        if ($t.closest('.pq-toolbar').length) { $t.trigger('change'); reload(); }
    });
    // Enter 跳下一欄；最後一欄 Enter＝送出（textarea 內 Enter 仍為換行）
    $doc.on('keydown', 'input,select', function (e) {
        if (e.key !== 'Enter') return;
        var $mask = $(this).closest('.pq-mask');
        var scope = $mask.length ? $mask : $('#view-list,#view-master');
        var $f = scope.find('input:visible:not([type=file]),select:visible').filter(':enabled');
        var i = $f.index(this);
        e.preventDefault();
        if (i > -1 && i < $f.length - 1) { $f.eq(i + 1).focus(); }
        else {
            var $go = $mask.length ? $mask.find('.m-foot .warm') : $('#btnSearch');
            if ($go.length) $go.first().click();
        }
    });
    // 多列輸入表格：↑↓ 切換上下列同欄
    $doc.on('keydown', 'table.pq-table tbody input,table.pq-table tbody select', function (e) {
        if (e.key !== 'ArrowUp' && e.key !== 'ArrowDown') return;
        var $td = $(this).closest('td'), $tr = $td.closest('tr');
        var col = $tr.children('td').index($td);
        var $next = e.key === 'ArrowDown' ? $tr.next('tr') : $tr.prev('tr');
        if (!$next.length) return;
        var $tgt = $next.children('td').eq(col).find('input,select').first();
        if (!$tgt.length) return;
        e.preventDefault(); $tgt.focus();
    });
}

/* ── 啟動 ─────────────────────────────────── */
$(function () {
    bindInputUX();
    api('meta').done(function (r) {
        META = r;
        $('#printHead').text(r.print_header || '');
        var stOpts = '';
        $.each(r.statuses, function (k, v) { stOpts += '<option value="' + esc(k) + '">' + esc(v) + '</option>'; });
        $('#fStatus').append(stOpts);
        $('#fPay,#fStatus').val('');
        $('#mCat,#pkCat,#itCat,#amCat').html(optList(r.categories, 'category_id', 'category_name', '', '全部類別'));
        $('#itCat,#amCat').html(optList(r.categories, 'category_id', 'category_name', '', ''));
        $('#mTag,#pkTag').html(optList(r.tags, 'tag_id', 'tag_name', '', '全部標籤'));
        $('#itUnit,#spUnit').html(optList(r.units, 'unit_id', 'unit_name', '', '（未指定）'));
        $('#spLoc').html(optList(r.locations, 'location_id', 'location_code', '', '（未指定）'));
        var taxOpts = ''; $.each(r.tax_types, function (k, v) { taxOpts += '<option value="' + esc(k) + '">' + esc(v) + '</option>'; });
        $('#qTax').html(taxOpts);
        $('#rWho').val(r.me.name + (r.me.dept_name ? '／' + r.me.dept_name : ''));
        applyFormMode();
        if (r.perms.canAdmin) {
            $('#cfgL1').val(r.thresholds.l1); $('#cfgL2').val(r.thresholds.l2);
            $('#cfgNas').val(r.attach_nas_dir || ''); $('#cfgUrl').val(r.attach_url_dir || '');
            $('#cfgPh').val(r.print_header || ''); $('#cfgPf').val(r.print_footer || '');
        }
        bindAll();
        reload(); loadBadges();
    }).fail(function (m) { $('#listBody').html('<tr><td colspan="11" class="pq-empty">' + esc(m) + '</td></tr>'); });
});

/* 申請單兩種版型（角色 purchase_form_full 或採購作業以上＝採購版）：
   一般使用者的版本只留「買什麼、為了什麼」，其餘欄位隱藏並由採購後續補。 */
function applyFormMode() {
    FULL = !!(META && META.perms && META.perms.canFormFull);
    $('.pq-full-only').toggle(FULL);
    ATT_TYPE = FULL ? ($('#attTagWrap .att-tag.on').data('v') || 'other') : 'other';
    $('#reqItemSecTitle').text(FULL ? '要買什麼（可綁採購料號、填預估單價與到貨處理）'
                                    : '要買什麼（只填品名、數量就好；價格與入庫方式由採購接手）');
    var th = '<th style="width:22%;">品名</th><th style="width:16%;">規格</th><th style="width:8%;">數量</th>' +
             '<th style="width:8%;">單位</th>';
    if (FULL) th += '<th style="width:10%;">預估單價</th><th style="width:11%;">到貨處理</th>';
    th += '<th style="width:18%;">用途</th><th style="width:5%;">急</th><th>備註</th><th style="width:4%;"></th>';
    $('#reqItemHead').html(th);
}

function bindAll() {
    $('#tabs').on('click', '.pq-tab', function () {
        $('.pq-tab').removeClass('on'); $(this).addClass('on');
        TAB = $(this).data('tab'); PAGE = 1;
        $('#view-list').toggle(['mine', 'buy', 'sign', 'unpaid', 'all'].indexOf(TAB) > -1);
        $('#view-master').toggle(TAB === 'master');
        $('#view-setting').toggle(TAB === 'setting');
        if (TAB === 'master') loadItems();
        else if (TAB === 'setting') { if ($('#roleList').length) loadRoles(); }
        else reload();
    });
    $('#btnSearch').on('click', function () { PAGE = 1; reload(); });
    $('#pageSize').on('change', function () { PS = parseInt(this.value, 10) || 10; PAGE = 1; reload(); });
    $('#btnCsv').on('click', exportCsv);
    $('#btnRoleHelp').on('click', function () { openMask('mRole'); loadRoleHelp(); });
    $('#btnNew').on('click', function () { openReq(0); });
    $('#btnSaveReq').on('click', saveReq);
    $('#pkGo').on('click', searchSpecs);
    $('#pkFree').on('click', function () { addItemRow(null); });
    $('#btnAddRow').on('click', function () { addItemRow(null); });
    // 單頭急件＝一次勾滿／清空所有品項，之後仍可逐項微調
    $('#rUrgent').on('change', function () {
        $('#reqItemBody .i-urg').prop('checked', $(this).prop('checked'));
    });
    // 逐項改動時同步單頭勾選狀態（全部勾滿才算整張單急件）
    $(document).on('change', '#reqItemBody .i-urg', function () {
        var $all = $('#reqItemBody .i-urg');
        $('#rUrgent').prop('checked', $all.length > 0 && $all.length === $all.filter(':checked').length);
    });
    // 附件類別改成標籤點選（採購版才顯示；精簡版一律 other）
    $('#attTagWrap').on('click', '.att-tag', function () {
        $('#attTagWrap .att-tag').removeClass('on'); $(this).addClass('on');
        ATT_TYPE = $(this).data('v') || 'other';
    });
    $('#attUp').on('click', uploadAtt);
    $('#btnSaveQuote').on('click', saveQuote);
    $('#btnSaveRecv').on('click', saveRecv);
    $('#btnSaveAcct').on('click', saveAcct);
    $('#btnApprove').on('click', function () { doSign('approved'); });
    $('#btnReject').on('click', function () { doSign('rejected'); });
    $('#btnDoDel').on('click', doDelete);
    $('#mSearch').on('click', function () { MPAGE = 1; loadItems(); });
    $('#mPageSize').on('change', function () { MPS = parseInt(this.value, 10) || 20; MPAGE = 1; loadItems(); });
    $('#btnNewItem').on('click', function () { openItem(0); });
    $('#btnSaveItem').on('click', saveItem);
    $('#btnAddSpec').on('click', function () { openSpec(0); });
    $('#btnSaveSpec').on('click', saveSpec);
    $('#btnTags').on('click', openTagMgr);
    $('#tgAdd').on('click', addTag);
    $('#btnAttrs').on('click', openAttrMgr);
    $('#amCat').on('change', loadAttrMgr);
    $('#amAdd').on('click', addAttr);
    $('#btnSaveCfg').on('click', saveCfg);
    // 廠商 / 品名即時搜尋
    bindSearch('#qVendor', '#qVendorList', 'search_vendor', function (v) { return v.maker_id + '（' + v.maker_id_no + '）'; },
        function (v) { $('#qVendor').val(v.maker_id).data('vid', v.maker_id_no);
                       if (v.payment_method && !$('#qPayMethod').val()) $('#qPayMethod').val(v.payment_method); });
    bindSearch('#itVendor', '#itVendorList', 'search_vendor', function (v) { return v.maker_id + '（' + v.maker_id_no + '）'; },
        function (v) { $('#itVendor').val(v.maker_id).data('vid', v.maker_id_no); });
    // 品名打字即時防重複
    var dupT = null;
    $('#itName').on('input', function () {
        clearTimeout(dupT); var kw = $(this).val().trim();
        if (ITEMEDIT.id > 0 || kw.length < 1) { $('#itDup').empty(); return; }
        dupT = setTimeout(function () {
            api('item_check_dup', { item_name: kw, category_id: $('#itCat').val() }).done(function (r) {
                if (!r.similar.length) { $('#itDup').empty(); return; }
                var h = '<div class="sug"><i class="fa fa-exclamation-triangle"></i> 已有類似品項，確定不是同一個嗎？<br>';
                r.similar.forEach(function (s) {
                    h += '<a href="javascript:;" class="dup-pick" data-id="' + s.item_id + '">' +
                         esc(s.item_code + ' ' + s.item_name) + '（' + s.category_name + '，' + s.spec_cnt + ' 個規格）</a><br>';
                });
                $('#itDup').html(h + '<span class="hint">同一個品項只要加規格就好，不必重建。</span></div>');
            });
        }, 300);
    });
    $('#itDup').on('click', '.dup-pick', function () { closeMask('mItem'); openItem($(this).data('id')); });
}

function bindSearch(inputSel, listSel, action, labelFn, pickFn) {
    var t = null;
    $(document).on('input', inputSel, function () {
        clearTimeout(t); var kw = $(this).val().trim();
        if (kw.length < 1) { $(listSel).empty(); return; }
        t = setTimeout(function () {
            api(action, { kw: kw }).done(function (r) {
                var rows = r.vendors || r.users || [];
                if (!rows.length) { $(listSel).html('<div class="sug">查無資料</div>'); return; }
                var h = '<div class="sug">';
                rows.forEach(function (v, i) { h += '<a href="javascript:;" class="pick-one" data-i="' + i + '">' + esc(labelFn(v)) + '</a><br>'; });
                $(listSel).html(h + '</div>').data('rows', rows);
            });
        }, 300);
    });
    $(document).on('click', listSel + ' .pick-one', function () {
        var rows = $(listSel).data('rows') || [];
        pickFn(rows[$(this).data('i')]); $(listSel).empty();
    });
}

/* ── 單據清單 ─────────────────────────────── */
function listParams() {
    return { scope: TAB, status: $('#fStatus').val() || '', pay_status: $('#fPay').val() || '',
             date_from: $('#fFrom').val() || '', date_to: $('#fTo').val() || '',
             kw: $('#fKw').val() || '', page: PAGE, page_size: PS };
}
function reload() {
    if (['mine', 'buy', 'sign', 'unpaid', 'all'].indexOf(TAB) < 0) return;
    $('#listBody').html('<tr><td colspan="11" class="pq-empty">載入中…</td></tr>');
    api('req_list', listParams()).done(function (r) {
        renderList(r); loadBadges();
    }).fail(function (m) { $('#listBody').html('<tr><td colspan="11" class="pq-empty">' + esc(m) + '</td></tr>'); });
}
function renderList(r) {
    $('#stCnt').text(r.stats.cnt || 0);
    $('#stSum').text(money(r.stats.sum_total));
    $('#stUnpaid').text(money(r.stats.sum_unpaid));
    $('#stHint').text(TAB === 'sign' ? '只列出目前輪到您簽核的單' :
                     (TAB === 'buy' ? '待詢價／待下單／待到貨的單' : '統計為所有符合篩選條件的資料，不只本頁'));
    if (!r.rows.length) { $('#listBody').html('<tr><td colspan="12" class="pq-empty">沒有符合條件的單據</td></tr>');
        renderPager(r); return; }
    var h = '';
    r.rows.forEach(function (x) {
        var urg = parseInt(x.is_urgent, 10) || parseInt(x.urgent_cnt, 10);
        h += '<tr>' +
            '<td><a href="javascript:;" class="op-detail" data-id="' + x.req_id + '">' + esc(x.req_no) + '</a></td>' +
            '<td class="l">' + esc(x.title || '') + (urg ? ' <span class="urg">急</span>' : '') + '</td>' +
            '<td class="l">' + (x.purpose_type
                ? '<span class="hint">' + esc(ppTypeName(x.purpose_type)) + '</span>' +
                  (x.purpose_label ? '<br>' + esc(x.purpose_label) : '')
                : '<span class="hint">—</span>') + '</td>' +
            '<td>' + esc(x.requester_name || '') + '</td><td>' + esc(x.dept_name || '') + '</td>' +
            '<td>' + esc(x.item_cnt) + '</td>' +
            '<td class="l">' + (x.masked_vendor ? '＊＊＊' : esc(x.vendor_name || '')) + '</td>' +
            '<td class="r money">' + (x.masked_amount ? '＊＊＊'
                : (parseFloat(x.grand_total) ? money(x.grand_total) : '—')) + '</td>' +
            '<td>' + pill(x.status) + '</td>' +
            '<td><span class="pill ' + (x.pay_status === 'paid' ? 'paid' : 'unpaid') + '">' +
                (x.pay_status === 'paid' ? '已付' : '未付') + '</span></td>' +
            '<td>' + esc(String(x.Created_At || '').substr(0, 10)) + '</td>' +
            '<td class="no-print"><button class="pq-btn op-detail" data-id="' + x.req_id + '">檢視</button></td></tr>';
    });
    $('#listBody').html(h);
    $('#listFoot').text(META.print_footer || '');
    renderPager(r);
}
function renderPager(r) {
    $('#pgInfo').text('共 ' + (r.total || 0) + ' 筆，第 ' + (r.page || 1) + '/' + (r.pages || 1) + ' 頁');
    var h = '<button ' + (r.page <= 1 ? 'disabled' : '') + ' data-p="' + (r.page - 1) + '">‹</button>';
    var from = Math.max(1, r.page - 2), to = Math.min(r.pages, from + 4);
    for (var i = from; i <= to; i++) h += '<button class="' + (i === r.page ? 'on' : '') + '" data-p="' + i + '">' + i + '</button>';
    h += '<button ' + (r.page >= r.pages ? 'disabled' : '') + ' data-p="' + (r.page + 1) + '">›</button>';
    $('#pgBtns').html(h);
}
$(document).on('click', '#pgBtns button', function () { PAGE = parseInt($(this).data('p'), 10) || 1; reload(); });
$(document).on('click', '.op-detail', function () { openDetail($(this).data('id')); });

function loadBadges() {
    api('req_badges').done(function (r) {
        $.each(r.badges, function (k, v) {
            var $b = $('#bg-' + k);
            if (!$b.length) return;
            if (v > 0) $b.text(v).show(); else $b.hide();
        });
    });
}

function exportCsv() {
    api('req_export', listParams()).done(function (r) {
        var head = ['單號', '標題', '用途類別', '用途對象', '申請人', '部門', '品項數', '廠商', '稅別', '未稅小計', '稅額', '含稅總額',
                    '狀態', '發票號碼', '發票日期', '付款狀態', '付款日', '付款方式', '申請日', '核准日', '下單日'];
        var lines = [head.join(',')];
        r.rows.forEach(function (x) {
            lines.push([x.req_no, x.title, ppTypeName(x.purpose_type), x.purpose_label,
                x.requester_name, x.dept_name, x.item_cnt, x.vendor_name,
                (META.tax_types[x.tax_type] || x.tax_type), x.subtotal, x.tax_amount, x.grand_total,
                (META.statuses[x.status] || x.status), x.invoice_no, x.invoice_date,
                (x.pay_status === 'paid' ? '已付' : '未付'), x.pay_date, x.pay_method,
                String(x.Created_At || '').substr(0, 10), String(x.approved_at || '').substr(0, 10),
                String(x.ordered_at || '').substr(0, 10)
            ].map(function (v) { return '"' + String(v == null ? '' : v).replace(/"/g, '""') + '"'; }).join(','));
        });
        lines.push('');
        // 合計金額要落在「含稅總額」那一欄——用 head 反查欄位位置，日後增欄不會再對錯格
        var foot = [];
        for (var fi = 0; fi < head.length; fi++) foot.push('');
        foot[0] = '合計'; foot[1] = r.rows.length + ' 筆';
        foot[head.indexOf('含稅總額')] = (r.stats.sum_total || 0);
        lines.push(foot.map(function (v) { return '"' + String(v).replace(/"/g, '""') + '"'; }).join(','));
        var blob = new Blob(['﻿' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8;' });
        var a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = '採購單_' + new Date().toISOString().substr(0, 10) + '.csv';
        a.click();
    }).fail(fail);
}

/* ── 申請單編輯 ───────────────────────────── */
function openReq(id) {
    EDIT = { id: id || 0, items: [], atts: [], tempAtts: [] };
    $('#reqTitle').text(id ? '修改採購申請' : '提出採購申請');
    $('#btnSaveReq').html('<i class="fa fa-save"></i> ' + (id ? '儲存修改' : '送出申請'));
    $('#rTitle,#rNeed,#rReason,#pkKw').val('');
    $('#rUrgent').prop('checked', false);
    REQPP = null; renderReqPurpose();
    $('#pkResult').html('<span class="hint">在上方搜尋採購品後點選加入；主檔沒有的東西可直接手打，採購到貨前再建檔。</span>');
    $('#attList').empty(); $('#reqItemBody').empty();
    if (id) {
        api('req_detail', { req_id: id }).done(function (r) {
            var q = r.req;
            $('#rTitle').val(q.title || ''); $('#rNeed').val(q.need_date || ''); $('#rReason').val(q.reason || '');
            $('#rUrgent').prop('checked', false);   // 品項載入後再依「是否全部急件」回推
            if (q.purpose_type) {
                REQPP = { type: q.purpose_type, order_id: q.purpose_order_id || 0, bom: q.purpose_bom || '',
                          d_id: q.purpose_d_id || 0, note: q.purpose_note || '', label: q.purpose_label || '' };
            }
            renderReqPurpose();
            q.items.forEach(function (it) {
                addItemRow(it.spec_id ? { spec_id: it.spec_id, item_name: it.item_name, spec_text: it.spec_text,
                    category_id: it.category_id, unit_id: it.unit_id } : null, it);
            });
            $('#reqItemBody .i-urg').first().trigger('change');   // 同步單頭勾選狀態
            renderAtts(q.attachments || [], false);
            openMask('mReq');
        }).fail(fail);
    } else { addItemRow(null); openMask('mReq'); }
}

function searchSpecs() {
    api('spec_search', { kw: $('#pkKw').val(), category_id: $('#pkCat').val(), tag_id: $('#pkTag').val() })
    .done(function (r) {
        if (!r.specs.length) { $('#pkResult').html('<span class="hint">查無採購品——可按「主檔沒有，直接手打」，或請採購事後建檔。</span>'); return; }
        var h = '<div class="pq-wrap"><table class="pq-table"><thead><tr><th>採購料號</th><th>品名</th><th>規格</th>' +
                '<th>類別</th><th>目前庫存</th><th>最近採購價</th><th></th></tr></thead><tbody>';
        r.specs.forEach(function (s, i) {
            var low = s.safety_qty && parseFloat(s.stock_qty) < parseFloat(s.safety_qty);
            h += '<tr><td>' + esc(s.spec_code) + '</td><td class="l">' + esc(s.item_name) + '</td>' +
                 '<td class="l">' + esc(s.spec_text) + '</td><td>' + esc(s.category_name) + '</td>' +
                 '<td class="r">' + nz(s.stock_qty) + ' ' + esc(s.unit_label || '') +
                 (low ? ' <span class="urg">低於安全量</span>' : '') + '</td>' +
                 '<td class="r money">' + (s.last_price ? money(s.last_price) : '—') + '</td>' +
                 '<td><button class="pq-btn warm pick-spec" data-i="' + i + '">加入</button></td></tr>';
        });
        $('#pkResult').html(h + '</tbody></table></div>').data('specs', r.specs);
    }).fail(fail);
}
$(document).on('click', '.pick-spec', function () {
    var s = ($('#pkResult').data('specs') || [])[$(this).data('i')];
    if (s) addItemRow(s);
});

function addItemRow(spec, prefill) {
    // 精簡版只問「買什麼、幾個」；預估單價／到貨處理屬採購與倉管的語言，只在採購版出現
    var uid = spec ? (spec.unit_id || spec.default_unit_id || '') : '';
    var modes = '';
    if (FULL) $.each(META.receive_modes, function (k, v) { modes += '<option value="' + esc(k) + '">' + esc(v) + '</option>'; });
    var h = '<tr data-spec="' + esc(spec ? spec.spec_id : '') + '">' +
        '<td class="l">' + (spec ? '<b>' + esc(spec.item_name) + '</b><br><span class="hint">' + esc(spec.spec_code || '') + '</span>'
                                 : '<input type="text" class="i-name" placeholder="品名（例：白板筆）">') + '</td>' +
        '<td class="l">' + (spec ? esc(spec.spec_text || '') : '<input type="text" class="i-spec" placeholder="規格／顏色">') + '</td>' +
        '<td><input type="number" class="i-qty" step="0.01" style="width:70px;text-align:right;" value="1"></td>' +
        '<td>' + (spec ? esc(unitLabel(uid)) : '<select class="i-unit" style="width:80px;">' +
                  optList(META.units, 'unit_id', 'unit_name', '', '—') + '</select>') + '</td>' +
        (FULL ? '<td><input type="number" class="i-est" step="0.01" style="width:85px;text-align:right;" placeholder="可留白"></td>' +
                '<td><select class="i-mode">' + modes + '</select></td>' : '') +
        '<td class="l"><div class="pq-pp-cell"></div></td>' +
        '<td><input type="checkbox" class="i-urg" title="這一項是急件"></td>' +
        '<td><input type="text" class="i-remark" style="width:100%;" placeholder="選填"></td>' +
        '<td><button class="pq-btn danger i-del" title="移除">✕</button></td></tr>';
    var $tr = $(h).appendTo('#reqItemBody');
    $tr.data('meta', spec ? { spec_id: spec.spec_id, item_name: spec.item_name, spec_text: spec.spec_text,
                              category_id: spec.category_id, unit_id: uid } : null);
    $tr.data('pp', null);   // null = 沿用單頭用途
    if ($('#rUrgent').prop('checked')) $tr.find('.i-urg').prop('checked', true);
    if (prefill) {
        $tr.find('.i-qty').val(nz(prefill.qty_requested));
        $tr.find('.i-remark').val(prefill.remark || '');
        $tr.find('.i-urg').prop('checked', parseInt(prefill.is_urgent, 10) === 1);
        if (FULL) {
            $tr.find('.i-est').val(prefill.est_price === null ? '' : nz(prefill.est_price));
            $tr.find('.i-mode').val(prefill.receive_mode || 'stock');
        }
        if (prefill.purpose_type) {
            $tr.data('pp', { type: prefill.purpose_type, order_id: prefill.purpose_order_id || 0,
                             bom: prefill.purpose_bom || '', d_id: prefill.purpose_d_id || 0,
                             note: prefill.purpose_note || '', label: prefill.purpose_label || '' });
        }
        if (!spec) { $tr.find('.i-name').val(prefill.item_name || ''); $tr.find('.i-spec').val(prefill.spec_text || '');
                     $tr.find('.i-unit').val(prefill.unit_id || ''); }
    }
    renderPpCell($tr);
    if (!spec) $tr.find('.i-name').focus();
}
$(document).on('click', '.i-del', function () { $(this).closest('tr').remove(); });

function collectItems() {
    var items = [], bad = '';
    $('#reqItemBody tr').each(function () {
        var $t = $(this), m = $t.data('meta');
        var qty = parseFloat($t.find('.i-qty').val() || 0);
        var name = m ? m.item_name : ($t.find('.i-name').val() || '').trim();
        if (!name) return;
        if (!(qty > 0)) { bad = name + ' 的數量要大於 0'; return; }
        var pp = $t.data('pp') || {};
        items.push({
            spec_id: m ? m.spec_id : 0,
            item_name: name,
            spec_text: m ? (m.spec_text || '') : ($t.find('.i-spec').val() || ''),
            category_id: m ? (m.category_id || 0) : 0,
            unit_id: m ? (m.unit_id || 0) : ($t.find('.i-unit').val() || 0),
            qty: qty,
            remark: $t.find('.i-remark').val() || '',
            is_urgent: $t.find('.i-urg').prop('checked') ? 1 : 0,
            // 精簡版沒有這兩欄，留白讓後端沿用預設（採購在詢價頁補）
            est_price: FULL ? ($t.find('.i-est').val() || '') : '',
            receive_mode: FULL ? $t.find('.i-mode').val() : '',
            // 留白＝沿用單頭；後端會重新驗 ID 並自行重建顯示名稱
            purpose_type: pp.type || '',
            purpose_order_id: pp.order_id || '',
            purpose_bom: pp.bom || '',
            purpose_d_id: pp.d_id || '',
            purpose_note: pp.note || ''
        });
    });
    if (bad) { alert(bad); return null; }
    if (!items.length) { alert('請至少填一筆品項'); return null; }
    return items;
}

function saveReq() {
    if (!REQPP || !REQPP.type) { alert('請先選擇這筆採購的用途'); openPurpose('req'); return; }
    var items = collectItems(); if (!items) return;
    // 精簡版不顯示標題，一律留白交給後端自動組（用途＋品名）
    var d = { req_id: EDIT.id, is_new: EDIT.id ? '0' : '1', title: FULL ? $('#rTitle').val() : '',
              need_date: $('#rNeed').val(), reason: $('#rReason').val(),
              // 單頭急件＝任一項急件（列表與通知看單頭這個旗標）
              is_urgent: ($('#rUrgent').prop('checked') || $('#reqItemBody .i-urg:checked').length) ? 1 : 0,
              purpose_type: REQPP.type, purpose_order_id: REQPP.order_id || '',
              purpose_bom: REQPP.bom || '', purpose_d_id: REQPP.d_id || '',
              purpose_note: REQPP.note || '',
              items: JSON.stringify(items), temp_att_ids: JSON.stringify(EDIT.tempAtts) };
    $('#btnSaveReq').prop('disabled', true);
    api('req_save', d, 'POST').done(function (r) {
        closeMask('mReq'); reload(); loadBadges();
        alert('已' + (EDIT.id ? '儲存' : '送出') + '：' + r.req_no);
    }).fail(fail).always(function () { $('#btnSaveReq').prop('disabled', false); });
}

/* ── 角色權限說明（動態產生：角色可改名／可改內容，寫死的說明表會失真） ── */
function loadRoleHelp() {
    api('role_matrix').done(function (r) {
        var byGroup = { view: [], op: [] };
        (r.features || []).forEach(function (f) { byGroup[f.group].push(f); });
        // 「由上而下包含」的鏈：勾了上層就自動有下層，說明要一起列出來才不會誤解
        var chain = { purchase_admin: ['purchase_buy', 'purchase_receive', 'purchase_apply', 'purchase_view'],
                      purchase_buy: ['purchase_receive', 'purchase_apply', 'purchase_view'],
                      purchase_receive: ['purchase_apply', 'purchase_view'],
                      purchase_apply: [] };
        var h = '';
        (r.roles || []).forEach(function (ro) {
            var codes = ro.codes.slice();
            if (ro.is_system) codes = ['all'];
            // 展開包含關係
            codes.slice().forEach(function (c) { (chain[c] || []).forEach(function (x) {
                if (codes.indexOf(x) < 0) codes.push(x); }); });
            if (codes.indexOf('purchase_buy') > -1 && codes.indexOf('purchase_form_full') < 0) codes.push('purchase_form_full');
            function labels(list) {
                if (codes.indexOf('all') > -1) return '<span style="color:#8A5A2B;">全部</span>';
                var t = list.filter(function (f) { return codes.indexOf(f.code) > -1; })
                            .map(function (f) { return esc(f.label); });
                return t.length ? t.join('<br>') : '<span class="hint">—</span>';
            }
            h += '<tr><td><b>' + esc(ro.role_name) + '</b>' +
                 (ro.is_system ? '<br><span class="hint">系統角色．固定全權</span>' : '') + '</td>' +
                 '<td class="l">' + labels(byGroup.view) + '</td>' +
                 '<td class="l">' + labels(byGroup.op) + '</td></tr>';
        });
        $('#roleHelpBody').html(h || '<tr><td colspan="3" class="pq-empty">尚無角色</td></tr>');
    }).fail(function (m) {
        $('#roleHelpBody').html('<tr><td colspan="3" class="pq-empty">' + esc(m) + '</td></tr>');
    });
}

/* ── 角色權限設定（沿用全站 Roles_API + role_features，module=purchase） ──
   角色名稱與功能勾選都由管理員自訂；程式不再依賴固定的 role_code。 */
var RAPI = '../../src/store/Roles_API.php';
var ROLES = [], CURROLE = 0;

function loadRoles(then) {
    $.getJSON(RAPI, { action: 'get_roles', module: 'purchase' }, function (res) {
        ROLES = res.data || [];
        var h = '';
        ROLES.forEach(function (r) {
            var sys = String(r.is_system) === '1';
            h += '<div class="pq-role-item' + (sys ? ' sys' : '') + '" data-id="' + r.role_id + '">' +
                 esc(r.role_name) + (sys ? '（系統．固定全權）' : '') + '</div>';
        });
        $('#roleList').html(h || '<div class="hint" style="padding:10px;">尚無角色</div>');
        if (CURROLE) $('.pq-role-item[data-id="' + CURROLE + '"]').addClass('on');
        if (typeof then === 'function') then();
    });
}

function selRole(id) {
    var r = ROLES.filter(function (x) { return String(x.role_id) === String(id); })[0];
    if (!r) return;
    if (String(r.is_system) === '1') { alert('系統角色「' + r.role_name + '」固定擁有全部權限，不可修改'); return; }
    CURROLE = id;
    $('.pq-role-item').removeClass('on');
    $('.pq-role-item[data-id="' + id + '"]').addClass('on');
    $('#roleEditHint').hide(); $('#roleEdit').show();
    $('#roleName').val(r.role_name);

    var vh = '', oh = '';
    (META.features || []).forEach(function (f) {
        var row = '<label class="pq-feat"><input type="checkbox" class="featcb" value="' + esc(f.code) + '"> ' +
                  esc(f.label) + '</label>';
        if (f.group === 'view') vh += row; else oh += row;
    });
    $('#featView').html(vh); $('#featOp').html(oh);
    $.getJSON(RAPI, { action: 'get_role_features', role_id: id }, function (res) {
        var has = res.data || [];
        $('.featcb').each(function () {
            $(this).prop('checked', has.indexOf(this.value) > -1 || has.indexOf('all') > -1);
        });
    });
    loadRoleUsers(id);
}

function loadRoleUsers(rid) {
    $.getJSON(RAPI, { action: 'get_users', module: 'purchase' }, function (res) {
        var h = '';
        (res.data || []).forEach(function (u) {
            var owned = (u.roles || []).some(function (x) { return String(x.role_id) === String(rid); });
            h += '<label class="pq-feat"><input type="checkbox" class="rucb" data-uid="' + u.id + '"' +
                 (owned ? ' checked' : '') + '> ' + esc(u.user_cname) +
                 ' <span class="hint">(' + esc(u.user_uname) + ')</span></label>';
        });
        $('#roleUsers').html(h || '<span class="hint">查無使用者</span>');
    });
}

$(document).on('click', '#roleList .pq-role-item', function () { selRole($(this).data('id')); });
$(document).on('click', '#btnRoleAdd', function () {
    var n = prompt('新角色名稱（例：倉管、廠務、只看報表）：');
    if (!n || !$.trim(n)) return;
    $.post(RAPI, { action: 'save_role', role_name: $.trim(n), module: 'purchase' }, function (r) {
        if (!r.success) { alert(r.message); return; }
        // 新角色一開始沒有任何功能，載完直接開起來讓管理員勾
        loadRoles(function () { selRole(r.role_id); });
    }, 'json');
});
$(document).on('click', '#btnRoleRename', function () {
    if (!CURROLE) return;
    var n = $.trim($('#roleName').val() || '');
    if (!n) { alert('請輸入角色名稱'); return; }
    $.post(RAPI, { action: 'save_role', role_id: CURROLE, role_name: n }, function (r) {
        if (!r.success) { alert(r.message); return; }
        loadRoles(); alert('已改名');
    }, 'json');
});
$(document).on('click', '#btnRoleDel', function () {
    if (!CURROLE) return;
    if (!confirm('確定刪除此角色？擁有此角色的人會失去對應權限（不會刪到使用者本身）。')) return;
    $.post(RAPI, { action: 'delete_role', role_id: CURROLE }, function (r) {
        if (!r.success) { alert(r.message); return; }
        CURROLE = 0; $('#roleEdit').hide(); $('#roleEditHint').show();
        $('#roleUsers').html('<span class="hint">請先選一個角色</span>');
        loadRoles();
    }, 'json');
});
$(document).on('click', '#btnRoleFeatSave', function () {
    if (!CURROLE) return;
    var feats = $('.featcb:checked').map(function () { return this.value; }).get();
    $.post(RAPI, { action: 'save_role_features', role_id: CURROLE, features: JSON.stringify(feats) },
        function (r) { alert(r.success ? '已儲存。受影響的人重新整理頁面後生效。' : r.message); }, 'json');
});
$(document).on('change', '#roleUsers .rucb', function () {
    if (!CURROLE) return;
    var uid = $(this).data('uid'), on = this.checked, $cb = $(this);
    $.post(RAPI, { action: on ? 'assign_user_role' : 'remove_user_role', user_id: uid, role_id: CURROLE },
        function (r) { if (!r.success) { alert(r.message); $cb.prop('checked', !on); } }, 'json');
});

/* ── 用途歸屬（單頭與逐列共用同一個選擇器） ── */
// 成本要歸得了戶，訂單一律綁 order_track.Order_id、料號一律綁 d_setting.d_id，
// 絕不存訂單號／料號字串（一個訂單號最多對到 25 列、料號字串有 159 個重複）。
var PP_NEED_PICK = { ORDER: 1, BOM: 1, PART: 1 };
var PP_PICK_CFG = {
    ORDER: { label: '搜尋訂單（訂單號／料號／客戶）', hint: '一個訂單號可能有多列料號，請點到你要的那一列。' },
    BOM:   { label: '搜尋 BOM（BOM 單號／料號／客戶）', hint: '點選要歸屬的 BOM 單。' },
    PART:  { label: '搜尋料號（料號／圖號）', hint: '點選要歸屬的料號。' }
};

function ppTypeName(t) { return (META && META.purpose_types && META.purpose_types[t]) || t || ''; }

function ppText(pp) {
    if (!pp || !pp.type) return '';
    return ppTypeName(pp.type) + (pp.label ? '：' + pp.label : '');
}

/** 單頭用途的顯示 */
function renderReqPurpose() {
    if (REQPP && REQPP.type) {
        $('#rPurposeShow').replaceWith('<span id="rPurposeShow" class="pq-purpose-tag">' +
            '<span class="k">' + esc(ppTypeName(REQPP.type)) + '</span>' +
            esc(REQPP.label || '') + '</span>');
        $('#btnPickPurpose').html('<i class="fa fa-pencil"></i> 更改');
    } else {
        $('#rPurposeShow').replaceWith('<span id="rPurposeShow" class="pq-purpose-none">尚未選擇</span>');
        $('#btnPickPurpose').html('<i class="fa fa-crosshairs"></i> 選擇用途');
    }
}

/** 品項列用途的顯示（留白＝沿用單頭） */
function renderPpCell($tr) {
    var pp = $tr.data('pp');
    var h = (pp && pp.type)
        ? '<span class="pq-pp-set i-pp" title="點擊修改">' + esc(ppText(pp)) + '</span>' +
          '<span class="pq-pp-clr i-pp-clr" title="改回沿用單頭">✕</span>'
        : '<span class="pq-pp-same i-pp">同單頭（點此改）</span>';
    $tr.find('.pq-pp-cell').html(h);
}

function openPurpose(target) {
    PP.target = target;
    var cur = (target === 'req') ? REQPP : target.data('pp');
    PP.cur = cur ? $.extend({}, cur) : { type: '', order_id: 0, bom: '', d_id: 0, note: '', label: '' };

    var isReq = (target === 'req');
    $('#ppTitle').text(isReq ? '這筆採購是為了什麼？' : '這一項的用途（與單頭不同時才設）');
    $('#ppClear').toggle(!isReq);

    var opts = isReq ? '<option value="">— 請選擇 —</option>' : '<option value="">（沿用單頭）</option>';
    $.each((META && META.purpose_types) || {}, function (k, v) {
        opts += '<option value="' + esc(k) + '">' + esc(v) + '</option>';
    });
    $('#ppType').html(opts).val(PP.cur.type || '');
    $('#ppNote').val(PP.cur.note || '');
    $('#ppKw').val(''); $('#ppList').empty();
    ppSyncType();
    openMask('mPurpose');
}

/** 依類別切換：要不要挑對象、要不要填說明 */
function ppSyncType() {
    var t = $('#ppType').val() || '';
    var needPick = !!PP_NEED_PICK[t];
    $('#ppPickWrap').toggle(needPick);
    if (needPick) {
        $('#ppKwLabel').text(PP_PICK_CFG[t].label);
        $('#ppPickHint').text(PP_PICK_CFG[t].hint);
    }
    // 只有「其他」需要文字說明，其餘類別的說明是選填備註
    $('#ppNoteWrap').toggle(t === 'OTHER' || t === 'EQUIP' || t === 'STOCK');
    $('#ppNoteLabel').text(t === 'OTHER' ? '說明（必填）' : '說明（選填）');
    ppPreview();
}

function ppPreview() {
    var t = $('#ppType').val() || '';
    if (!t) { $('#ppPreview').text(PP.target === 'req' ? '尚未選擇' : '沿用單頭'); return; }
    var lb = PP.cur.label || '';
    if (PP_NEED_PICK[t] && !lb) { $('#ppPreview').text(ppTypeName(t) + '（尚未指定對象）'); return; }
    $('#ppPreview').text(ppTypeName(t) + (lb ? '：' + lb : ''));
}

function ppSearch() {
    var t = $('#ppType').val() || '', kw = $.trim($('#ppKw').val() || '');
    if (!PP_NEED_PICK[t]) return;
    if (!kw) { $('#ppList').empty(); return; }
    var seq = ++ppSeq;
    $('#ppList').html('<div class="pp-row x">搜尋中…</div>');
    api('purpose_search', { type: t, kw: kw }).done(function (r) {
        if (seq !== ppSeq) return;   // 已有更新的查詢，這批結果丟掉
        if (!r.rows.length) { $('#ppList').html('<div class="pp-row x">查無資料，換個關鍵字試試</div>'); return; }
        var h = '';
        r.rows.forEach(function (o, i) {
            h += '<div class="pp-row pp-pick" data-i="' + i + '">' +
                 '<span class="m">' + esc(o.main) + '</span>' +
                 (o.sub ? ' <span class="s">' + esc(o.sub) + '</span>' : '') +
                 (o.extra ? '<br><span class="x">' + esc(o.extra) + '</span>' : '') + '</div>';
        });
        $('#ppList').html(h).data('rows', r.rows);
    }).fail(fail);
}

$(document).on('change', '#ppType', function () {
    // 換類別＝先前挑的對象作廢，免得殘留舊 ID
    PP.cur.type = $(this).val() || '';
    PP.cur.order_id = 0; PP.cur.bom = ''; PP.cur.d_id = 0; PP.cur.label = '';
    $('#ppList').empty(); $('#ppKw').val('');
    ppSyncType();
});
// 即時搜尋：邊打邊找，不必按按鈕。300ms 去抖動，並丟棄比較舊的回應避免結果亂序
var ppT = null, ppSeq = 0;
$(document).on('input', '#ppKw', function () {
    clearTimeout(ppT);
    var kw = $.trim($(this).val() || '');
    if (!kw) { $('#ppList').empty(); return; }
    ppT = setTimeout(ppSearch, 300);
});
$(document).on('keydown', '#ppKw', function (e) {
    if (e.keyCode === 13) { e.preventDefault(); clearTimeout(ppT); ppSearch(); }
});
$(document).on('click', '.pp-pick', function () {
    var o = ($('#ppList').data('rows') || [])[$(this).data('i')];
    if (!o) return;
    var t = $('#ppType').val();
    if (t === 'ORDER') PP.cur.order_id = o.id;
    else if (t === 'BOM') PP.cur.bom = o.id;
    else if (t === 'PART') PP.cur.d_id = o.id;
    PP.cur.label = o.label;
    $('.pp-pick').css('background', ''); $(this).css('background', '#F7E0BD');
    ppPreview();
});
$(document).on('input', '#ppNote', function () { PP.cur.note = $(this).val(); ppPreview(); });

$(document).on('click', '#ppOk', function () {
    var t = $('#ppType').val() || '';
    if (PP.target === 'req' && !t) { alert('請選擇用途類別'); return; }
    if (PP_NEED_PICK[t] && !PP.cur.label) { alert('請在下方搜尋並點選要歸屬的對象'); return; }
    if (t === 'OTHER' && !$.trim($('#ppNote').val() || '')) { alert('選「其他」請簡單寫一下用途'); return; }
    PP.cur.type = t; PP.cur.note = $('#ppNote').val() || '';
    if (!t) {                                   // 品項列選「沿用單頭」
        PP.target.data('pp', null); renderPpCell(PP.target);
    } else if (PP.target === 'req') {
        REQPP = $.extend({}, PP.cur); renderReqPurpose();
    } else {
        PP.target.data('pp', $.extend({}, PP.cur)); renderPpCell(PP.target);
    }
    closeMask('mPurpose');
});
$(document).on('click', '#ppClear', function () {
    if (PP.target && PP.target !== 'req') { PP.target.data('pp', null); renderPpCell(PP.target); }
    closeMask('mPurpose');
});
$(document).on('click', '#btnPickPurpose', function () { openPurpose('req'); });
$(document).on('click', '.i-pp', function () { openPurpose($(this).closest('tr')); });
$(document).on('click', '.i-pp-clr', function (e) {
    e.stopPropagation();
    var $tr = $(this).closest('tr'); $tr.data('pp', null); renderPpCell($tr);
});

/* ── 附件 ─────────────────────────────────── */
function uploadAtt() {
    var f = document.getElementById('attFile');
    if (!f.files || !f.files[0]) { alert('請先選擇檔案'); return; }
    var fd = new FormData();
    fd.append('action', 'att_upload'); fd.append('req_id', EDIT.id || 0);
    fd.append('att_type', ATT_TYPE); fd.append('file', f.files[0]);
    $('#attUp').prop('disabled', true);
    $.ajax({ url: API, type: 'POST', data: fd, processData: false, contentType: false, dataType: 'json' })
    .done(function (r) {
        if (!r.ok) { fail(r.error); return; }
        if (!EDIT.id) EDIT.tempAtts.push(r.att_id);
        EDIT.atts.push({ att_id: r.att_id, original_name: r.original_name, url: r.url, att_type: ATT_TYPE });
        renderAtts(EDIT.atts, true); f.value = '';
    }).fail(function () { fail('上傳失敗'); })
    .always(function () { $('#attUp').prop('disabled', false); });
}
function renderAtts(list, keep) {
    if (keep) EDIT.atts = list; else EDIT.atts = list.slice();
    var types = { quote: '估價單', invoice: '發票', receipt: '收據', other: '其他' };
    var h = '';
    EDIT.atts.forEach(function (a) {
        h += '<span class="tag-chip">' + esc(types[a.att_type] || '') + '｜' +
             '<a href="' + esc(a.url || '#') + '" target="_blank">' + esc(a.original_name || a.file_name) + '</a> ' +
             '<a href="javascript:;" class="att-del" data-id="' + a.att_id + '" style="color:#DD5138;">✕</a></span> ';
    });
    $('#attList').html(h || '<span class="hint">尚未上傳附件</span>');
}
$(document).on('click', '.att-del', function () {
    var id = $(this).data('id');
    if (!confirm('確定刪除這個附件？')) return;
    api('att_delete', { att_id: id }, 'POST').done(function () {
        EDIT.atts = EDIT.atts.filter(function (a) { return String(a.att_id) !== String(id); });
        EDIT.tempAtts = EDIT.tempAtts.filter(function (x) { return String(x) !== String(id); });
        renderAtts(EDIT.atts, true);
        if (CUR) openDetail(CUR.req_id);
    }).fail(fail);
});

/* ── 單據詳情 ─────────────────────────────── */
function openDetail(id) {
    api('req_detail', { req_id: id }).done(function (r) {
        CUR = r.req; renderDetail(r.req); openMask('mDetail');
    }).fail(fail);
}
function flowBar(q) {
    var steps = [['submitted', '申請'], ['quoted', '詢價/簽核'], ['approved', '核准'],
                 ['ordered', '下單'], ['received', '到貨'], ['closed', '結案']];
    var order = { submitted: 0, quoted: 1, rejected: 1, approved: 2, ordered: 3, partial: 4, received: 4, closed: 5, canceled: 5 };
    var cur = order[q.status] === undefined ? 0 : order[q.status];
    var h = '';
    steps.forEach(function (s, i) {
        if (i) h += '<i class="fa fa-angle-right"></i>';
        h += '<span class="' + (i < cur ? 'dn' : (i === cur ? 'cur' : '')) + '">' + esc(s[1]) + '</span>';
    });
    if (q.status === 'rejected') h += ' <span class="pill rejected">已駁回：' + esc(q.reject_reason || '') + '</span>';
    return '<div class="flow">' + h + '</div>';
}
function renderDetail(q) {
    $('#dtTitle').text('採購單 ' + q.req_no + '　' + (q.title || ''));
    var types = { quote: '估價單', invoice: '發票', receipt: '收據', other: '其他' };
    var h = flowBar(q);
    h += '<div class="pq-sec"><div class="pq-grid" style="margin:0;">' +
        fld('狀態', pill(q.status) + (parseInt(q.is_urgent, 10) ? ' <span class="urg">急件</span>' : '')) +
        fld('用途', q.purpose_type
            ? '<b>' + esc(ppTypeName(q.purpose_type)) + '</b>' + (q.purpose_label ? '　' + esc(q.purpose_label) : '')
            : '<span class="hint">未指定</span>') +
        fld('申請人', esc(q.requester_name) + '／' + esc(q.dept_name || '')) +
        fld('申請日', String(q.Created_At || '').substr(0, 16)) + fld('希望到貨日', q.need_date || '—') +
        fld('廠商', q.masked_vendor ? '＊＊＊' : esc(q.vendor_name || '—')) +
        fld('稅別', esc(META.tax_types[q.tax_type] || '')) +
        fld('未稅小計', '<span class="money">' + money(q.subtotal) + '</span>') +
        fld('稅額', '<span class="money">' + money(q.tax_amount) + '</span>') +
        fld('含稅總額', '<b class="money" style="font-size:16px;color:#8A5A2B;">' + money(q.grand_total) + '</b>') +
        fld('簽核', q.need_levels > 0 ? ('需 ' + q.need_levels + ' 層，已完成 ' + q.level_done + ' 層' +
            (q.pending_signers ? '（待簽：' + esc(q.pending_signers) + '）' : '')) : '未達門檻，免簽核') +
        fld('採購', esc(q.buyer_name || '—')) + fld('預計到貨', q.expected_date || '—') +
        fld('發票', q.masked_vendor ? '＊＊＊' : esc(q.invoice_no || '—') + (q.invoice_date ? '（' + q.invoice_date + '）' : '')) +
        fld('付款', q.masked_vendor ? '＊＊＊'
            : '<span class="pill ' + (q.pay_status === 'paid' ? 'paid' : 'unpaid') + '">' +
              (q.pay_status === 'paid' ? '已付' : '未付') + '</span> ' + esc(q.pay_date || '') + ' ' + esc(q.pay_method || '')) +
        '</div>' + (q.reason ? '<div class="hint" style="margin-top:6px;">事由：' + esc(q.reason) + '</div>' : '') + '</div>';

    h += '<div class="pq-wrap"><table class="pq-table"><thead><tr><th>品名</th><th>規格</th><th>採購料號</th>' +
         '<th>數量</th><th>單位</th><th>單價</th><th>小計</th><th>到貨處理</th><th>已到貨</th><th>儲位</th><th>備註</th>' +
         '<th>用途</th></tr></thead><tbody>';
    q.items.forEach(function (it) {
        h += '<tr><td class="l">' + esc(it.item_name) + (parseInt(it.is_urgent, 10) ? ' <span class="urg">急</span>' : '') + '</td>' +
             '<td class="l">' + esc(it.spec_text || '') + '</td><td>' + esc(it.spec_code || '（未建檔）') + '</td>' +
             '<td class="r">' + nz(it.qty_requested) + '</td><td>' + esc(it.unit_label || '') + '</td>' +
             '<td class="r money">' + (it.unit_price === null ? '—' : money(it.unit_price)) + '</td>' +
             '<td class="r money">' + (it.amount === null ? '—' : money(it.amount)) + '</td>' +
             '<td>' + esc(META.receive_modes[it.receive_mode] || '') + '</td>' +
             '<td class="r">' + nz(it.qty_received) + '</td><td>' + esc(it.location_code || '') + '</td>' +
             '<td class="l">' + esc(it.remark || '') + '</td>' +
             '<td class="l">' + (it.purpose_type
                 ? esc(ppTypeName(it.purpose_type) + (it.purpose_label ? '：' + it.purpose_label : ''))
                 : '<span class="hint">同單頭</span>') + '</td></tr>';
    });
    h += '</tbody></table></div>';

    if (q.receipts && q.receipts.length) {
        h += '<div class="pq-sec" style="margin-top:10px;"><h5>到貨紀錄</h5><div class="pq-wrap"><table class="pq-table">' +
             '<thead><tr><th>日期</th><th>數量</th><th>處理方式</th><th>儲位</th><th>交付對象</th><th>登錄人</th><th>備註</th></tr></thead><tbody>';
        q.receipts.forEach(function (rc) {
            h += '<tr><td>' + esc(rc.rcpt_date) + '</td><td class="r">' + nz(rc.qty) + '</td>' +
                 '<td>' + esc(META.receive_modes[rc.receive_mode] || '') + '</td><td>' + esc(rc.location_code || '') + '</td>' +
                 '<td>' + esc(rc.receiver_name || '') + '</td><td>' + esc(rc.created_name || '') + '</td>' +
                 '<td class="l">' + esc(rc.remark || '') + '</td></tr>';
        });
        h += '</tbody></table></div></div>';
    }
    if (q.approvals && q.approvals.length) {
        h += '<div class="pq-sec"><h5>簽核紀錄</h5><div class="pq-wrap"><table class="pq-table">' +
             '<thead><tr><th>關卡</th><th>結果</th><th>簽核人</th><th>時間</th><th>意見</th></tr></thead><tbody>';
        q.approvals.forEach(function (a) {
            h += '<tr><td>' + esc(a.level) + '</td><td>' +
                 (a.status === 'approved' ? '<span class="pill received">核准</span>' :
                  a.status === 'rejected' ? '<span class="pill rejected">駁回</span>' : '<span class="pill quoted">待簽</span>') +
                 '</td><td>' + esc(a.approver_name || '') + '</td><td>' + esc(a.decided_at || '') + '</td>' +
                 '<td class="l">' + esc(a.note || '') + '</td></tr>';
        });
        h += '</tbody></table></div></div>';
    }
    var ah = '';
    (q.attachments || []).forEach(function (a) {
        ah += '<span class="tag-chip">' + esc(types[a.att_type] || '') + '｜<a href="' + esc(a.url) + '" target="_blank">' +
              esc(a.original_name || a.file_name) + '</a></span> ';
    });
    h += '<div class="pq-sec"><h5>附件</h5>' + (ah || '<span class="hint">無</span>') + '</div>';
    $('#dtBody').html(h);

    var f = '<button class="pq-btn" onclick="closeMask(\'mDetail\')">關閉</button> ';
    f += '<button class="pq-btn" onclick="window.print()"><i class="fa fa-print"></i> 列印</button> ';
    if (q.can.edit)    f += '<button class="pq-btn" id="dtEdit">修改申請</button> ';
    if (q.can.delete)  f += '<button class="pq-btn danger" id="dtDel">刪除</button> ';
    if (q.can.quote)   f += '<button class="pq-btn warm" id="dtQuote"><i class="fa fa-usd"></i> 詢價填金額</button> ';
    if (q.can.sign)    f += '<button class="pq-btn warm" id="dtSign"><i class="fa fa-pencil-square-o"></i> 簽核</button> ';
    if (q.can.order)   f += '<button class="pq-btn warm" id="dtOrder"><i class="fa fa-shopping-cart"></i> 已下單</button> ';
    if (q.can.receive) f += '<button class="pq-btn warm" id="dtRecv"><i class="fa fa-truck"></i> 登錄到貨</button> ';
    if (q.can.account) f += '<button class="pq-btn" id="dtAcct"><i class="fa fa-file-text"></i> 發票／付款</button> ';
    if (q.can.close)   f += '<button class="pq-btn" id="dtClose">結案</button> ';
    $('#dtFoot').html(f);
}
function fld(l, v) { return '<div class="pq-fld"><label>' + esc(l) + '</label><div style="font-size:13px;color:#5b3a1e;">' + v + '</div></div>'; }

$(document).on('click', '#dtEdit', function () { closeMask('mDetail'); openReq(CUR.req_id); });
$(document).on('click', '#dtDel', function () { DELID = CUR.req_id; $('#delWho').text('單號 ' + CUR.req_no);
    $('#delReason').val(''); openMask('mDel'); });
$(document).on('click', '#dtQuote', function () { closeMask('mDetail'); openQuote(); });
$(document).on('click', '#dtSign', function () { closeMask('mDetail'); openSign(); });
$(document).on('click', '#dtRecv', function () { closeMask('mDetail'); openRecv(); });
$(document).on('click', '#dtAcct', function () { closeMask('mDetail'); openAcct(); });
$(document).on('click', '#dtOrder', function () {
    if (!confirm('確認已向廠商下單？')) return;
    api('mark_ordered', { req_id: CUR.req_id }, 'POST').done(function () {
        closeMask('mDetail'); reload(); loadBadges();
    }).fail(fail);
});
$(document).on('click', '#dtClose', function () {
    if (!confirm('確認結案？結案後不再列入待辦。')) return;
    api('close_req', { req_id: CUR.req_id }, 'POST').done(function () {
        closeMask('mDetail'); reload(); loadBadges();
    }).fail(fail);
});
function doDelete() {
    var reason = $('#delReason').val().trim();
    if (!reason) { alert('請輸入刪除原因'); return; }
    api('req_delete', { req_id: DELID, delete_reason: reason }, 'POST').done(function () {
        closeMask('mDel'); closeMask('mDetail'); reload(); loadBadges();
    }).fail(fail);
}

/* ── 詢價填價 ─────────────────────────────── */
function openQuote() {
    var q = CUR;
    $('#qVendor').val(q.vendor_name || '').data('vid', q.vendor_id || '');
    $('#qTax').val(q.tax_type || 'taxable');
    $('#qPayMethod').val(q.pay_method || ''); $('#qExpect').val(q.expected_date || '');
    var modes = ''; $.each(META.receive_modes, function (k, v) { modes += '<option value="' + esc(k) + '">' + esc(v) + '</option>'; });
    var h = '';
    q.items.forEach(function (it) {
        h += '<tr data-id="' + it.pr_item_id + '" data-qty="' + it.qty_requested + '">' +
            '<td class="l">' + esc(it.item_name) +
                (parseInt(it.is_urgent, 10) ? ' <span class="urg">急</span>' : '') + '</td>' +
            '<td class="l">' + esc(it.spec_text || '') + '</td>' +
            // 申請人手打的品名沒有採購料號，由採購在這裡建檔綁定（也可以留到入庫時再綁）
            '<td class="l">' + (it.spec_code ? esc(it.spec_code) :
                '<button class="pq-btn bind-spec" data-id="' + it.pr_item_id + '" data-name="' + esc(it.item_name) +
                '" data-spec="' + esc(it.spec_text || '') + '"><i class="fa fa-link"></i> 綁定</button>') + '</td>' +
            '<td class="r">' + nz(it.qty_requested) + '</td><td>' + esc(it.unit_label || '') + '</td>' +
            '<td><input type="number" class="q-price" step="0.0001" style="width:100%;text-align:right;" value="' +
                (it.unit_price === null ? (it.est_price === null ? '' : nz(it.est_price)) : nz(it.unit_price)) + '"></td>' +
            '<td><select class="q-mode">' + modes + '</select></td>' +
            '<td><select class="q-loc">' + optList(META.locations, 'location_id', 'location_code', it.location_id, '（未指定）') + '</select></td>' +
            '<td class="r money q-amt">0</td></tr>';
        });
    $('#quoteBody').html(h);
    q.items.forEach(function (it, i) { $('#quoteBody tr').eq(i).find('.q-mode').val(it.receive_mode || 'stock'); });
    calcQuote();
    openMask('mQuote');
}
$(document).on('input change', '.q-price,#qTax', calcQuote);
function calcQuote() {
    var sub = 0;
    $('#quoteBody tr').each(function () {
        var $t = $(this), qty = parseFloat($t.data('qty')) || 0;
        var p = parseFloat($t.find('.q-price').val() || 0) || 0;
        var amt = Math.round(qty * p * 100) / 100;
        $t.find('.q-amt').text(money(amt)); sub += amt;
    });
    sub = Math.round(sub * 100) / 100;
    var tax = $('#qTax').val() === 'taxable' ? Math.round(sub * 5) / 100 : 0;
    var grand = Math.round((sub + tax) * 100) / 100;
    $('#qSub').text(money(sub)); $('#qTaxAmt').text(money(tax)); $('#qGrand').text(money(grand));
    var l1 = parseFloat(META.thresholds.l1), l2 = parseFloat(META.thresholds.l2);
    var msg = grand <= l1 ? '未達 ' + money(l1) + ' → 免簽核，送出後可直接下單'
            : (grand <= l2 ? '超過 ' + money(l1) + ' → 需部門主管簽核 1 層'
                           : '超過 ' + money(l2) + ' → 需主管＋高階核准共 2 層');
    $('#qLevelHint').html('<b>' + esc(msg) + '</b>（實際仍以後端計算為準）');
}
function saveQuote() {
    var prices = [], bad = '';
    $('#quoteBody tr').each(function () {
        var $t = $(this), v = $t.find('.q-price').val();
        if (v === '' || parseFloat(v) < 0) bad = '每一列都要填實際單價（沒有就填 0）';
        prices.push({ pr_item_id: $t.data('id'), unit_price: v, receive_mode: $t.find('.q-mode').val(),
                      location_id: $t.find('.q-loc').val() || 0 });
    });
    if (bad) { alert(bad); return; }
    var d = { req_id: CUR.req_id, vendor_name: $('#qVendor').val(), vendor_id: $('#qVendor').data('vid') || '',
              tax_type: $('#qTax').val(), pay_method: $('#qPayMethod').val(), expected_date: $('#qExpect').val(),
              prices: JSON.stringify(prices) };
    $('#btnSaveQuote').prop('disabled', true);
    api('save_quote', d, 'POST').done(function (r) {
        closeMask('mQuote'); reload(); loadBadges();
        alert(r.need_levels > 0 ? '已送出核價，含稅總額 ' + money(r.grand_total) + ' 元，需 ' + r.need_levels + ' 層簽核，通知已送出。'
                                : '含稅總額 ' + money(r.grand_total) + ' 元，未達簽核門檻，已自動核准，可直接下單。');
    }).fail(fail).always(function () { $('#btnSaveQuote').prop('disabled', false); });
}

/* ── 到貨 ─────────────────────────────────── */
function openRecv() {
    $('#rcDate').val(META.today);
    var modes = ''; $.each(META.receive_modes, function (k, v) { modes += '<option value="' + esc(k) + '">' + esc(v) + '</option>'; });
    var h = '';
    CUR.items.forEach(function (it) {
        var left = parseFloat(it.qty_requested) - parseFloat(it.qty_received);
        h += '<tr data-id="' + it.pr_item_id + '" data-left="' + left + '" data-spec="' + (it.spec_id || '') + '">' +
            '<td class="l">' + esc(it.item_name) + (it.spec_id ? '' :
                ' <button class="pq-btn bind-spec" data-id="' + it.pr_item_id + '" data-name="' + esc(it.item_name) +
                '" data-spec="' + esc(it.spec_text || '') + '">建檔</button>') + '</td>' +
            '<td class="l">' + esc(it.spec_text || '') + '</td>' +
            '<td class="r">' + nz(it.qty_received) + ' / ' + nz(it.qty_requested) + '</td>' +
            '<td><input type="number" class="rc-qty" step="0.01" style="width:100%;text-align:right;" value="' +
                (left > 0 ? nz(left) : '') + '"' + (left <= 0 ? ' disabled' : '') + '></td>' +
            '<td><select class="rc-mode">' + modes + '</select></td>' +
            '<td><select class="rc-loc">' + optList(META.locations, 'location_id', 'location_code', it.location_id, '（未指定）') + '</select></td>' +
            '<td><input type="text" class="rc-recv" placeholder="預設＝請購人" autocomplete="off" style="width:100%;">' +
                '<div class="rc-recv-list"></div></td>' +
            '<td><input type="text" class="rc-remark" style="width:100%;"></td></tr>';
    });
    $('#recvBody').html(h);
    CUR.items.forEach(function (it, i) { $('#recvBody tr').eq(i).find('.rc-mode').val(it.receive_mode || 'stock'); });
    toggleRecvCols();
    openMask('mRecv');
}
$(document).on('change', '.rc-mode', toggleRecvCols);
function toggleRecvCols() {
    $('#recvBody tr').each(function () {
        var m = $(this).find('.rc-mode').val();
        $(this).find('.rc-loc').prop('disabled', m === 'expense');
        $(this).find('.rc-recv').prop('disabled', m !== 'direct')
               .attr('placeholder', m === 'direct' ? '預設＝請購人' : '—');
    });
}
// 交付對象搜尋（逐列）
var recvT = null;
$(document).on('input', '.rc-recv', function () {
    var $in = $(this), $list = $in.siblings('.rc-recv-list');
    clearTimeout(recvT); var kw = $in.val().trim();
    if (kw.length < 1) { $list.empty(); return; }
    recvT = setTimeout(function () {
        api('search_user', { kw: kw }).done(function (r) {
            var h = '<div class="sug">';
            (r.users || []).forEach(function (u, i) {
                h += '<a href="javascript:;" class="rc-pick" data-id="' + u.id + '" data-name="' + esc(u.user_cname) + '">' +
                     esc(u.user_cname) + (u.dept_name ? '（' + esc(u.dept_name) + '）' : '') + '</a><br>';
            });
            $list.html(h + '</div>');
        });
    }, 300);
});
$(document).on('click', '.rc-pick', function () {
    var $in = $(this).closest('td').find('.rc-recv');
    $in.val($(this).data('name')).data('uid', $(this).data('id'));
    $(this).closest('.rc-recv-list').empty();
});
// 申請人手打的品名沒有採購料號：由採購在「詢價」或「登錄到貨」時建檔綁定（申請人不必先查主檔）
$(document).on('click', '.bind-spec', function () {
    var id = $(this).data('id'), name = $(this).data('name'), sp = $(this).data('spec');
    // 用編號選類別，比要求打對中文名稱不容易錯
    var list = META.categories.map(function (c, i) { return (i + 1) + '. ' + c.category_name; }).join('\n');
    var ans = prompt('要把「' + name + '」建到哪個類別？請輸入編號：\n' + list, '1');
    if (ans === null || $.trim(ans) === '') return;
    ans = $.trim(ans);
    var c = /^\d+$/.test(ans) ? META.categories[parseInt(ans, 10) - 1]
                             : META.categories.filter(function (x) { return x.category_name === ans; })[0];
    if (!c) { alert('查無此類別'); return; }
    // 綁完要回到原本那個視窗（詢價 or 到貨），不能寫死其中一個
    var back = $('#mQuote').hasClass('show') ? 'quote' : 'recv';
    api('bind_spec', { pr_item_id: id, spec_id: 0, category_id: c.category_id, item_name: name,
                       spec_text: sp || '' }, 'POST').done(function () {
        api('req_detail', { req_id: CUR.req_id }).done(function (r) {
            CUR = r.req;
            if (back === 'quote') { closeMask('mQuote'); openQuote(); }
            else { closeMask('mRecv'); openRecv(); }
            alert('已建檔並綁定採購料號');
        });
    }).fail(fail);
});
function saveRecv() {
    var lines = [], bad = '';
    $('#recvBody tr').each(function () {
        var $t = $(this);
        var qty = parseFloat($t.find('.rc-qty').val() || 0);
        if (!(qty > 0)) return;
        var left = parseFloat($t.data('left')) || 0;
        if (qty > left + 0.0001) { bad = '到貨數量不可超過未到量'; return; }
        var mode = $t.find('.rc-mode').val();
        if ((mode === 'stock' || mode === 'direct') && !$t.data('spec')) {
            bad = '有品項尚未建檔，無法入庫——請先按「建檔」，或把該列改成「不列管」'; return;
        }
        if ((mode === 'stock' || mode === 'direct') && !$t.find('.rc-loc').val()) { bad = '入庫要指定儲位'; return; }
        lines.push({ pr_item_id: $t.data('id'), qty: qty, receive_mode: mode,
                     location_id: $t.find('.rc-loc').val() || 0,
                     receiver_id: $t.find('.rc-recv').data('uid') || 0,
                     remark: $t.find('.rc-remark').val() || '' });
    });
    if (bad) { alert(bad); return; }
    if (!lines.length) { alert('請至少填一筆到貨數量'); return; }
    $('#btnSaveRecv').prop('disabled', true);
    api('receive', { req_id: CUR.req_id, rcpt_date: $('#rcDate').val(), lines: JSON.stringify(lines) }, 'POST')
    .done(function (r) {
        closeMask('mRecv'); reload(); loadBadges();
        alert(r.status === 'received' ? '已全數到貨' : '已登錄部分到貨');
    }).fail(fail).always(function () { $('#btnSaveRecv').prop('disabled', false); });
}

/* ── 記帳 / 簽核 ──────────────────────────── */
function openAcct() {
    $('#aInvNo').val(CUR.invoice_no || ''); $('#aInvDate').val(CUR.invoice_date || '');
    $('#aPayStatus').val(CUR.pay_status || 'unpaid'); $('#aPayDate').val(CUR.pay_date || '');
    $('#aPayMethod').val(CUR.pay_method || '');
    openMask('mAcct');
}
function saveAcct() {
    api('save_account', { req_id: CUR.req_id, invoice_no: $('#aInvNo').val(), invoice_date: $('#aInvDate').val(),
        pay_status: $('#aPayStatus').val(), pay_date: $('#aPayDate').val(), pay_method: $('#aPayMethod').val() }, 'POST')
    .done(function () { closeMask('mAcct'); reload(); loadBadges(); }).fail(fail);
}
function openSign() {
    var q = CUR;
    var h = '<div class="pq-sec"><div class="pq-grid" style="margin:0;">' +
        fld('單號', esc(q.req_no) + (parseInt(q.is_urgent, 10) ? ' <span class="urg">急件</span>' : '')) +
        fld('申請人', esc(q.requester_name) + '／' + esc(q.dept_name || '')) +
        fld('用途', q.purpose_type
            ? '<b>' + esc(ppTypeName(q.purpose_type)) + '</b>' + (q.purpose_label ? '　' + esc(q.purpose_label) : '')
            : '<span class="hint">未指定</span>') +
        fld('廠商', esc(q.vendor_name || '—')) +
        fld('含稅總額', '<b class="money" style="font-size:18px;color:#8A5A2B;">' + money(q.grand_total) + '</b>') +
        fld('關卡', '第 ' + (q.pending_level || 1) + ' 關 / 共 ' + q.need_levels + ' 關') + '</div></div>';
    h += '<div class="pq-wrap"><table class="pq-table"><thead><tr><th>品名</th><th>規格</th><th>數量</th>' +
         '<th>單價</th><th>小計</th></tr></thead><tbody>';
    q.items.forEach(function (it) {
        h += '<tr><td class="l">' + esc(it.item_name) + '</td><td class="l">' + esc(it.spec_text || '') + '</td>' +
             '<td class="r">' + nz(it.qty_requested) + '</td><td class="r money">' + money(it.unit_price) + '</td>' +
             '<td class="r money">' + money(it.amount) + '</td></tr>';
    });
    h += '</tbody></table></div><div class="pq-fld" style="margin-top:10px;"><label>簽核意見（駁回必填）</label>' +
         '<textarea id="signNote"></textarea></div>';
    $('#signBody').html(h);
    openMask('mSign');
}
function doSign(decision) {
    var note = $('#signNote').val().trim();
    if (decision === 'rejected' && !note) { alert('駁回必須填寫原因'); return; }
    if (!confirm(decision === 'approved' ? '確認核准這張採購單？' : '確認駁回？')) return;
    api('sign', { req_id: CUR.req_id, decision: decision, note: note }, 'POST').done(function (r) {
        closeMask('mSign'); reload(); loadBadges();
        alert(r.status === 'approved' ? '已核准，採購可下單' :
              r.status === 'rejected' ? '已駁回' : '已核准第一關，已轉送下一關簽核');
    }).fail(fail);
}

/* ── 採購品主檔 ───────────────────────────── */
function loadItems() {
    $('#itemBody').html('<tr><td colspan="8" class="pq-empty">載入中…</td></tr>');
    api('item_list', { kw: $('#mKw').val(), category_id: $('#mCat').val(), tag_id: $('#mTag').val(),
                       page: MPAGE, page_size: MPS }).done(function (r) {
        if (!r.rows.length) { $('#itemBody').html('<tr><td colspan="8" class="pq-empty">尚無採購品，按「新增品項」建立</td></tr>'); }
        else {
            var h = '';
            r.rows.forEach(function (x) {
                var tg = (x.tags || []).map(function (t) { return '<span class="tag-chip">' + esc(t.tag_name) + '</span>'; }).join('');
                h += '<tr><td>' + esc(x.item_code) + '</td><td>' + esc(x.category_name) + '</td>' +
                     '<td class="l"><a href="javascript:;" class="op-item" data-id="' + x.item_id + '">' + esc(x.item_name) + '</a></td>' +
                     '<td class="l">' + tg + '</td><td class="r">' + x.spec_cnt + '</td>' +
                     '<td class="r">' + nz(x.stock_qty) + '</td><td class="l">' + esc(x.default_vendor_name || '') + '</td>' +
                     '<td class="no-print"><button class="pq-btn op-item" data-id="' + x.item_id + '">編輯／規格</button></td></tr>';
            });
            $('#itemBody').html(h);
        }
        $('#mPgInfo').text('共 ' + r.total + ' 筆，第 ' + r.page + '/' + r.pages + ' 頁');
        var b = '<button ' + (r.page <= 1 ? 'disabled' : '') + ' data-p="' + (r.page - 1) + '">‹</button>';
        var from = Math.max(1, r.page - 2), to = Math.min(r.pages, from + 4);
        for (var i = from; i <= to; i++) b += '<button class="' + (i === r.page ? 'on' : '') + '" data-p="' + i + '">' + i + '</button>';
        b += '<button ' + (r.page >= r.pages ? 'disabled' : '') + ' data-p="' + (r.page + 1) + '">›</button>';
        $('#mPgBtns').html(b);
    }).fail(fail);
}
$(document).on('click', '#mPgBtns button', function () { MPAGE = parseInt($(this).data('p'), 10) || 1; loadItems(); });
$(document).on('click', '.op-item', function () { openItem($(this).data('id')); });

function openItem(id) {
    ITEMEDIT = { id: id || 0, specs: [], attrs: [], tags: [] };
    $('#itTitle').text(id ? '編輯品項' : '新增品項');
    $('#itName,#itNote,#itVendor').val(''); $('#itDup').empty();
    $('#itCat').val(META.categories.length ? META.categories[0].category_id : '');
    $('#itUnit').val(''); renderTagPick([]);
    $('#specSec').toggle(!!id);
    $('#specHint').text(id ? '' : '');
    if (!id) { $('#specBody').empty(); openMask('mItem'); return; }
    api('item_detail', { item_id: id }).done(function (r) {
        var it = r.item;
        $('#itCat').val(it.category_id); $('#itName').val(it.item_name);
        $('#itUnit').val(it.default_unit_id || ''); $('#itNote').val(it.note || '');
        $('#itVendor').val(it.default_vendor_name || '').data('vid', it.default_vendor_id || '');
        ITEMEDIT.specs = it.specs || []; ITEMEDIT.attrs = it.attrs || [];
        renderTagPick(it.tag_ids || []);
        renderSpecs();
        openMask('mItem');
    }).fail(fail);
}
function renderTagPick(sel) {
    var h = '';
    (META.tags || []).forEach(function (t) {
        h += '<label style="margin-right:10px;font-size:12px;font-weight:normal;color:#5b3a1e;">' +
             '<input type="checkbox" class="tg-pick" value="' + t.tag_id + '"' +
             (sel.indexOf(parseInt(t.tag_id, 10)) > -1 ? ' checked' : '') + '> ' + esc(t.tag_name) + '</label>';
    });
    $('#itTags').html(h || '<span class="hint">尚未建立標籤（標籤管理可新增）</span>');
}
function renderSpecs() {
    if (!ITEMEDIT.specs.length) {
        $('#specBody').html('<tr><td colspan="8" class="pq-empty">尚無規格——按「新增規格」加尺寸／顏色等變體</td></tr>'); return;
    }
    var h = '';
    ITEMEDIT.specs.forEach(function (s, i) {
        var low = s.safety_qty && parseFloat(s.stock_qty) < parseFloat(s.safety_qty);
        h += '<tr><td>' + esc(s.spec_code) + '</td><td class="l">' + esc(s.spec_text) + '</td>' +
             '<td>' + esc(unitLabel(s.unit_id)) + '</td><td>' + esc(s.location_code || '') + '</td>' +
             '<td class="r">' + nz(s.safety_qty) + '</td>' +
             '<td class="r">' + nz(s.stock_qty) + (low ? ' <span class="urg">低於安全量</span>' : '') + '</td>' +
             '<td class="r money">' + (s.last_price ? money(s.last_price) : '—') + '</td>' +
             '<td class="no-print"><button class="pq-btn sp-edit" data-i="' + i + '">編輯</button> ' +
             (META.perms.canAdmin ? '<button class="pq-btn danger sp-del" data-id="' + s.spec_id + '">停用</button>' : '') +
             '</td></tr>';
    });
    $('#specBody').html(h);
}
$(document).on('click', '.sp-edit', function () { openSpec(ITEMEDIT.specs[$(this).data('i')]); });
$(document).on('click', '.sp-del', function () {
    if (!confirm('確定停用這個規格？（有庫存者不可停用）')) return;
    api('spec_delete', { spec_id: $(this).data('id') }, 'POST').done(function () { openItem(ITEMEDIT.id); }).fail(fail);
});

function saveItem() {
    var name = $('#itName').val().trim();
    if (!name) { alert('請輸入品名'); return; }
    var tags = $('.tg-pick:checked').map(function () { return parseInt(this.value, 10); }).get();
    api('item_save', { item_id: ITEMEDIT.id, category_id: $('#itCat').val(), item_name: name,
        default_unit_id: $('#itUnit').val() || 0, default_vendor_name: $('#itVendor').val(),
        default_vendor_id: $('#itVendor').data('vid') || '', note: $('#itNote').val(),
        tag_ids: JSON.stringify(tags) }, 'POST').done(function (r) {
        if (!ITEMEDIT.id) { alert('已建立品項 ' + r.item_code + '，接著加規格'); openItem(r.item_id); }
        else { alert('已儲存'); loadItems(); }
    }).fail(fail);
}

function openSpec(spec) {
    var s = spec || null;
    $('#spTitle').text(s ? '編輯規格' : '新增規格');
    $('#spText').val(s ? s.spec_text : ''); $('#spUnit').val(s ? (s.unit_id || '') : ($('#itUnit').val() || ''));
    $('#spLoc').val(s ? (s.location_id || '') : ''); $('#spSafe').val(s ? nz(s.safety_qty) : '');
    var vals = {};
    if (s && s.attr_json) { try { vals = JSON.parse(s.attr_json) || {}; } catch (e) {} }
    var h = '';
    (ITEMEDIT.attrs || []).forEach(function (a) {
        var v = vals[a.attr_id] === undefined ? '' : vals[a.attr_id];
        h += '<div class="pq-fld"><label>' + esc(a.attr_name) + (a.attr_unit ? '（' + esc(a.attr_unit) + '）' : '') + '</label>';
        if (a.attr_type === 'select') {
            h += '<select class="sp-attr" data-id="' + a.attr_id + '"><option value="">—</option>';
            String(a.attr_options || '').split(',').forEach(function (o) {
                o = o.trim(); if (!o) return;
                h += '<option value="' + esc(o) + '"' + (o === v ? ' selected' : '') + '>' + esc(o) + '</option>';
            });
            h += '</select>';
        } else {
            h += '<input type="' + (a.attr_type === 'number' ? 'number' : 'text') + '" class="sp-attr" data-id="' +
                 a.attr_id + '" value="' + esc(v) + '" step="any">';
        }
        h += '</div>';
    });
    $('#spAttrs').html(h || '<div class="hint" style="grid-column:1/-1;">這個類別還沒設定規格屬性——可到「規格屬性設定」加（例：刀具＝直徑／長度／材質），或直接在下方輸入規格說明。</div>');
    $('#spAttrs').data('spec_id', s ? s.spec_id : 0);
    openMask('mSpec');
}
function saveSpec() {
    var vals = {};
    $('.sp-attr').each(function () { var v = $(this).val(); if (v !== '') vals[$(this).data('id')] = v; });
    api('spec_save', { spec_id: $('#spAttrs').data('spec_id') || 0, item_id: ITEMEDIT.id,
        spec_text: $('#spText').val(), attr_vals: JSON.stringify(vals), unit_id: $('#spUnit').val() || 0,
        location_id: $('#spLoc').val() || 0, safety_qty: $('#spSafe').val() }, 'POST')
    .done(function (r) { closeMask('mSpec'); openItem(ITEMEDIT.id); }).fail(fail);
}

/* ── 標籤 / 屬性 / 設定 ───────────────────── */
function openTagMgr() { renderTags(); openMask('mTagMgr'); }
function renderTags() {
    var h = '';
    (META.tags || []).forEach(function (t) {
        h += '<tr><td class="l">' + esc(t.tag_name) + '</td><td>' + esc(t.color || '') + '</td>' +
             '<td><button class="pq-btn danger tg-del" data-id="' + t.tag_id + '">刪除</button></td></tr>';
    });
    $('#tgBody').html(h || '<tr><td colspan="3" class="pq-empty">尚無標籤</td></tr>');
}
function addTag() {
    var n = $('#tgName').val().trim();
    if (!n) { alert('請輸入標籤名稱'); return; }
    api('tag_save', { tag_name: n, color: '#F7E0BD' }, 'POST').done(function () {
        $('#tgName').val(''); refreshMetaTags();
    }).fail(fail);
}
$(document).on('click', '.tg-del', function () {
    if (!confirm('刪除標籤會一併解除所有品項上的這個標籤，確定？')) return;
    api('tag_delete', { tag_id: $(this).data('id') }, 'POST').done(refreshMetaTags).fail(fail);
});
function refreshMetaTags() {
    api('meta').done(function (r) {
        META.tags = r.tags;
        $('#mTag,#pkTag').html(optList(r.tags, 'tag_id', 'tag_name', '', '全部標籤'));
        renderTags();
        if ($('#mItem').hasClass('show')) renderTagPick($('.tg-pick:checked').map(function () { return parseInt(this.value, 10); }).get());
    });
}
function openAttrMgr() { loadAttrMgr(); openMask('mAttrMgr'); }
function loadAttrMgr() {
    api('attr_list', { category_id: $('#amCat').val() }).done(function (r) {
        var h = '';
        (r.attrs || []).forEach(function (a) {
            var t = { text: '文字', number: '數值', select: '下拉' }[a.attr_type] || a.attr_type;
            h += '<tr><td class="l">' + esc(a.attr_name) + '</td><td>' + esc(t) + '</td>' +
                 '<td class="l">' + esc(a.attr_options || '') + '</td><td>' + esc(a.attr_unit || '') + '</td>' +
                 '<td><button class="pq-btn danger am-del" data-id="' + a.attr_id + '">停用</button></td></tr>';
        });
        $('#amBody').html(h || '<tr><td colspan="5" class="pq-empty">這個類別尚未設定屬性</td></tr>');
    }).fail(fail);
}
function addAttr() {
    var n = $('#amName').val().trim();
    if (!n) { alert('請輸入屬性名稱'); return; }
    api('attr_save', { category_id: $('#amCat').val(), attr_name: n, attr_type: $('#amType').val(),
        attr_options: $('#amOpts').val(), attr_unit: $('#amUnit').val() }, 'POST').done(function () {
        $('#amName,#amOpts,#amUnit').val(''); loadAttrMgr();
    }).fail(fail);
}
$(document).on('click', '.am-del', function () {
    if (!confirm('停用此屬性？已存在的規格文字不受影響。')) return;
    api('attr_delete', { attr_id: $(this).data('id') }, 'POST').done(loadAttrMgr).fail(fail);
});
function saveCfg() {
    api('save_settings', { l1: $('#cfgL1').val(), l2: $('#cfgL2').val(), nas_dir: $('#cfgNas').val(),
        url_dir: $('#cfgUrl').val(), print_header: $('#cfgPh').val(), print_footer: $('#cfgPf').val() }, 'POST')
    .done(function () {
        META.thresholds.l1 = parseFloat($('#cfgL1').val()); META.thresholds.l2 = parseFloat($('#cfgL2').val());
        META.print_header = $('#cfgPh').val(); META.print_footer = $('#cfgPf').val();
        $('#printHead').text(META.print_header || '');
        alert('已儲存設定');
    }).fail(fail);
}

})();
