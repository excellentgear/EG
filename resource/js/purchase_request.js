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
        $('#mCat,#pkCat,#bdFCat,#itCat,#amCat').html(optList(r.categories, 'category_id', 'category_name', '', '全部類別'));
        $('#itCat,#amCat,#bdCat').html(optList(r.categories, 'category_id', 'category_name', '', ''));
        $('#mTag,#pkTag,#bdFTag').html(optList(r.tags, 'tag_id', 'tag_name', '', '全部標籤'));
        $('#itUnit,#spUnit,#bdUnit').html(optList(r.units, 'unit_id', 'unit_name', '', '（未指定）'));
        $('#spLoc,#bdLoc').html(optList(r.locations, 'location_id', 'location_code', '', '（未指定）'));
        var taxOpts = ''; $.each(r.tax_types, function (k, v) { taxOpts += '<option value="' + esc(k) + '">' + esc(v) + '</option>'; });
        $('#qTax').html(taxOpts);
        $('#rWho').val(r.me.name + (r.me.dept_name ? '／' + r.me.dept_name : ''));
        applyFormMode();
        if (r.perms.canAdmin) {
            $('#cfgL1').val(r.thresholds.l1); $('#cfgL2').val(r.thresholds.l2);
            $('#cfgNas').val(r.attach_nas_raw || r.attach_nas_dir || '');
            renderNasState(r);
            $('#cfgPh').val(r.print_header || ''); $('#cfgPf').val(r.print_footer || '');
        }
        bindAll();
        reload(); loadBadges();
    }).fail(function (m) { $('#listBody').html('<tr><td colspan="11" class="pq-empty">' + esc(m) + '</td></tr>'); });
});

/* 附件路徑目前的實際狀態：填錯路徑最怕的是「存下去沒事、等有人上傳才爆」，
   所以直接把實際生效路徑與可否寫入攤在設定頁上。 */
function renderNasState(r) {
    if (!$('#cfgNasState').length) return;
    var eff = r.attach_nas_dir || '';
    var h = '目前實際使用：<code>' + esc(eff) + '</code>';
    if (!r.attach_nas_raw) {
        h += '<br><span style="color:#DD5138;"><b>你還沒設定過</b>，現在用的是系統預設值——附件會寫到上面這個位置。</span>';
    }
    if (!r.attach_nas_exists) {
        h += '<br><span style="color:#DD5138;"><b>這個資料夾現在讀不到</b>（不存在、或執行網站的帳號沒有存取權）。上傳附件會失敗。</span>';
    } else if (!r.attach_nas_writable) {
        h += '<br><span style="color:#DD5138;"><b>資料夾存在但不可寫入</b>，上傳附件會失敗。</span>';
    } else {
        h += '　<span style="color:#2e7d32;">✔ 讀寫正常</span>';
    }
    $('#cfgNasState').html(h);
}

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
    // 綁定採購料號：查既有 / 建新的（詳細理由見 openBind 上方註解）
    $(document).on('click', '.bind-spec', function () { openBind($(this).data('id')); });
    $('#bdTabs').on('click', '.pq-tab', function () { bdTab($(this).data('bd')); });
    $('#bdFGo').on('click', bdSearch);
    var bdT = null;
    $('#bdFKw').on('input', function () { clearTimeout(bdT); bdT = setTimeout(bdSearch, 300); });
    $('#bdFCat,#bdFTag').on('change', bdSearch);
    // 雙擊清空篩選欄＝同時解除該欄篩選（共用清空handler跑在後面，所以延一個 tick 再查）
    $('#bdFKw,#bdFCat,#bdFTag').on('dblclick', function () { setTimeout(bdSearch, 0); });
    $('#bdCat').on('change', function () { BIND.codeTouched = false; bdCatChanged(); });
    $('input[name=bdItemMode]').on('change', bdItemChanged);
    $('#bdItemSel').on('change', function () { BIND.codeTouched = false; bdItemChanged(); });
    $('#bdCodeAuto').on('click', function () { BIND.codeTouched = false; bdCodeSuggest(); });
    $('#bdSpecCode').on('input', function () { BIND.codeTouched = true; });
    $('#bdSpecText').on('input', function () { BIND.textTouched = ($.trim(this.value) !== ''); });
    $(document).on('input change', '.bd-attr', bdCompose);
    $('#bdSave').on('click', bdSaveNew);
    $('#bdClear').on('click', function () {
        if (!confirm('解除這一列的採購料號綁定？（主檔的料號不會被刪除）')) return;
        bdSubmit({ mode: 'clear' }, '已解除綁定');
    });
    // 新品項品名打字即時防重複（同類別同名不可再建一個，否則以後找不到料號）
    var bdDupT = null;
    $('#bdItemName').on('input', function () {
        clearTimeout(bdDupT); var kw = $.trim($(this).val());
        if (kw.length < 1) { $('#bdItemDup').empty(); return; }
        bdDupT = setTimeout(function () {
            api('item_check_dup', { item_name: kw, category_id: $('#bdCat').val() }).done(function (r) {
                if (!r.similar.length) { $('#bdItemDup').empty(); return; }
                var h = '<div class="sug"><i class="fa fa-exclamation-triangle"></i> 已有類似品項，確定不是同一個嗎？<br>';
                r.similar.forEach(function (s) {
                    h += '<a href="javascript:;" class="bd-dup-pick" data-id="' + s.item_id + '">' +
                         esc(s.item_code + ' ' + s.item_name) + '（' + s.category_name + '，' + s.spec_cnt + ' 個料號）</a><br>';
                });
                $('#bdItemDup').html(h + '<span class="hint">同一個品項只要加規格就好，不必重建。</span></div>');
            });
        }, 300);
    });
    $('#bdItemDup').on('click', '.bd-dup-pick', function () {
        $('input[name=bdItemMode][value=exist]').prop('checked', true);
        BIND.codeTouched = false;
        if ($('#bdItemSel option[value="' + $(this).data('id') + '"]').length) {
            $('#bdItemSel').val($(this).data('id')); bdItemChanged();
        } else { bdCatChanged(); }
        $('#bdItemDup').empty();
    });
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
    $('#btnBrands').on('click', openBrandMgr);
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
        loadRoles();
    }, 'json');
});
$(document).on('click', '#btnRoleFeatSave', function () {
    if (!CURROLE) return;
    var feats = $('.featcb:checked').map(function () { return this.value; }).get();
    $.post(RAPI, { action: 'save_role_features', role_id: CURROLE, features: JSON.stringify(feats) },
        function (r) { alert(r.success ? '已儲存。受影響的人重新整理頁面後生效。' : r.message); }, 'json');
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
// 選檔即上傳（不再有獨立的「上傳」按鈕），多檔逐一送出、一個失敗不影響其餘
function uploadAtt() {
    var el = document.getElementById('attFile');
    var files = el && el.files ? Array.prototype.slice.call(el.files) : [];
    if (!files.length) return;
    $('#attPick').css('opacity', .5);
    var done = 0, okCnt = 0, badNames = [];
    function finish() {
        if (++done < files.length) return;
        el.value = '';
        $('#attPick').css('opacity', 1);
        $('#attHint').text(badNames.length
            ? '已上傳 ' + okCnt + ' 個；失敗：' + badNames.join('、')
            : '已上傳 ' + okCnt + ' 個檔案');
    }
    files.forEach(function (file) {
        var fd = new FormData();
        fd.append('action', 'att_upload'); fd.append('req_id', EDIT.id || 0);
        fd.append('att_type', ATT_TYPE); fd.append('file', file);
        $.ajax({ url: API, type: 'POST', data: fd, processData: false, contentType: false, dataType: 'json' })
        .done(function (r) {
            if (!r.ok) { badNames.push(file.name + '（' + r.error + '）'); finish(); return; }
            if (!EDIT.id) EDIT.tempAtts.push(r.att_id);
            EDIT.atts.push({ att_id: r.att_id, original_name: r.original_name, url: r.url, att_type: ATT_TYPE });
            renderAtts(EDIT.atts, true); okCnt++; finish();
        }).fail(function () { badNames.push(file.name); finish(); });
    });
}
$(document).on('change', '#attFile', uploadAtt);
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

    h += '<div class="pq-wrap"><table class="pq-table"><thead>' +
         '<tr><th colspan="4" style="background:#F3E2C7;">申請內容</th>' +
         '<th colspan="4" style="background:var(--pq-soft);">採購實際</th>' +
         '<th colspan="6"></th></tr>' +
         '<tr><th>品名</th><th>規格</th><th>數量</th><th>單位</th>' +
         '<th>採購料號</th><th>實際品名／規格</th><th>數量</th><th>單價</th>' +
         '<th>小計</th><th>到貨處理</th><th>已到貨</th><th>儲位</th><th>備註</th><th>用途</th></tr></thead><tbody>';
    q.items.forEach(function (it) {
        // 申請內容與採購實際並列；採購沒改的欄位顯示「同申請」，一眼看得出買到的是不是他要的
        var bn = it.buy_item_name || '', bs = it.buy_spec_text || '';
        var bothSame = (!bn && !bs);
        h += '<tr><td class="l">' + esc(it.item_name) + (parseInt(it.is_urgent, 10) ? ' <span class="urg">急</span>' : '') + '</td>' +
             '<td class="l">' + esc(it.spec_text || '') + '</td>' +
             '<td class="r">' + nz(it.qty_requested) + '</td><td>' + esc(it.unit_label || '') + '</td>' +
             '<td>' + esc(it.buy_spec_code || it.spec_code || '（未綁定）') + '</td>' +
             '<td class="l">' + (bothSame ? '<span class="hint">同申請</span>'
                 : esc(bn || it.item_name) + (bs ? '／' + esc(bs) : '')) + '</td>' +
             '<td class="r">' + (it.buy_qty === null || it.buy_qty === undefined || it.buy_qty === ''
                 ? '<span class="hint">同申請</span>' : nz(it.buy_qty)) + '</td>' +
             '<td class="r money">' + (it.unit_price === null ? '—' : money(it.unit_price)) + '</td>' +
             '<td class="r money">' + (it.amount === null ? '—' : money(it.amount)) + '</td>' +
             '<td>' + esc(META.receive_modes[it.receive_mode] || '') + '</td>' +
             '<td class="r">' + nz(it.qty_received) + '</td><td>' + esc(it.location_code || '') + '</td>' +
             '<td class="l">' + esc(it.remark || '') +
                 (it.buy_remark ? '<br><span class="hint">採購：' + esc(it.buy_remark) + '</span>' : '') + '</td>' +
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
/** 採購料號欄：顯示目前綁到哪一支＋一個入口（綁定／改綁或新建）。沒有採購權限的人只看得到料號 */
function bindCell(it) {
    var code = it.buy_spec_code || it.spec_code || '';
    var h = code ? '<b style="color:#8A5A2B;">' + esc(code) + '</b>' : '<span class="hint">未綁定</span>';
    if (!(META.perms && META.perms.canBuy)) return h + (code ? '' : '<br><span class="hint">請採購綁定</span>');
    return h + '<br><button class="pq-btn bind-spec" data-id="' + it.pr_item_id + '">' +
           '<i class="fa fa-link"></i> ' + (code ? '改綁／新建' : '綁定料號') + '</button>';
}

function openQuote() {
    var q = CUR;
    $('#qVendor').val(q.vendor_name || '').data('vid', q.vendor_id || '');
    $('#qTax').val(q.tax_type || 'taxable');
    $('#qPayMethod').val(q.pay_method || ''); $('#qExpect').val(q.expected_date || '');
    var modes = ''; $.each(META.receive_modes, function (k, v) { modes += '<option value="' + esc(k) + '">' + esc(v) + '</option>'; });
    var h = '';
    q.items.forEach(function (it) {
        // 左半＝申請人填的，唯讀；右半＝採購自己那一層，寫進 buy_* 欄位，不動申請內容
        h += '<tr data-id="' + it.pr_item_id + '" data-qty="' + it.qty_requested + '">' +
            '<td class="l">' + esc(it.item_name) +
                (parseInt(it.is_urgent, 10) ? ' <span class="urg">急</span>' : '') + '</td>' +
            '<td class="l">' + esc(it.spec_text || '') + '</td>' +
            '<td class="r">' + nz(it.qty_requested) + '</td><td>' + esc(it.unit_label || '') + '</td>' +
            // 採購料號一律可點：沒綁的來綁，綁錯的可改綁或另建一支（申請人選的也可能買到別支）
            '<td class="l">' + bindCell(it) + '</td>' +
            '<td><input type="text" class="q-bname" style="width:100%;" placeholder="同申請" value="' +
                esc(it.buy_item_name || '') + '"></td>' +
            '<td><input type="text" class="q-bspec" style="width:100%;" placeholder="同申請" value="' +
                esc(it.buy_spec_text || '') + '"></td>' +
            '<td><input type="number" class="q-bqty" step="0.01" style="width:70px;text-align:right;" placeholder="同申請" value="' +
                nz(it.buy_qty) + '"></td>' +
            '<td><input type="number" class="q-price" step="0.0001" style="width:100%;text-align:right;" value="' +
                (it.unit_price === null ? (it.est_price === null ? '' : nz(it.est_price)) : nz(it.unit_price)) + '"></td>' +
            '<td><select class="q-mode">' + modes + '</select></td>' +
            '<td><select class="q-loc">' + optList(META.locations, 'location_id', 'location_code', it.location_id, '（未指定）') + '</select></td>' +
            '<td><input type="text" class="q-bremark" style="width:100%;" placeholder="選填" value="' +
                esc(it.buy_remark || '') + '"></td>' +
            '<td class="r money q-amt">0</td></tr>';
        });
    $('#quoteBody').html(h);
    q.items.forEach(function (it, i) { $('#quoteBody tr').eq(i).find('.q-mode').val(it.receive_mode || 'stock'); });
    calcQuote();
    openMask('mQuote');
}
$(document).on('input change', '.q-price,.q-bqty,#qTax', calcQuote);
function calcQuote() {
    var sub = 0;
    $('#quoteBody tr').each(function () {
        var $t = $(this);
        // 小計用採購實際數量；留白就是申請數量（與後端 COALESCE(buy_qty, qty_requested) 一致）
        var bq = $t.find('.q-bqty').val();
        var qty = (bq === '' || bq === undefined) ? (parseFloat($t.data('qty')) || 0) : (parseFloat(bq) || 0);
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
                      location_id: $t.find('.q-loc').val() || 0,
                      // 採購自己那一層；留白＝同申請（後端存 NULL）
                      buy_item_name: $t.find('.q-bname').val() || '',
                      buy_spec_text: $t.find('.q-bspec').val() || '',
                      buy_qty: $t.find('.q-bqty').val() || '',
                      buy_remark: $t.find('.q-bremark').val() || '' });
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
        // 到貨要對「採購實際」買的東西與數量；採購沒改才等於申請內容
        var target = (it.buy_qty === null || it.buy_qty === undefined || it.buy_qty === '')
            ? parseFloat(it.qty_requested) : parseFloat(it.buy_qty);
        var left = target - parseFloat(it.qty_received);
        var boundSpec = it.buy_spec_id || it.spec_id;
        var shownName = it.buy_item_name || it.item_name;
        var shownSpec = it.buy_spec_text || it.spec_text || '';
        h += '<tr data-id="' + it.pr_item_id + '" data-left="' + left + '" data-spec="' + (boundSpec || '') + '">' +
            '<td class="l">' + esc(shownName) +
                (it.buy_item_name ? ' <span class="hint">（申請：' + esc(it.item_name) + '）</span>' : '') + '</td>' +
            '<td class="l">' + esc(shownSpec) + '</td>' +
            '<td class="l">' + bindCell(it) + '</td>' +
            '<td class="r">' + nz(it.qty_received) + ' / ' + nz(target) + '</td>' +
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
/* ── 綁定採購料號（採購側）─────────────────────────────
   申請人手打的品名沒有採購料號，由採購在「詢價」或「登錄到貨」時決定它是哪一支。
   舊版只用 prompt 問類別就自動建品項＋自動配號，主檔於是長出一堆同名品項、
   號碼沒人看過也沒人記得，之後根本找不到採購料號（2026-07-30 使用者退回）。
   現在給兩條明路：①綁既有料號（查得到就不要重建） ②真的建一個新料號
   （類別／掛哪個品項／規格屬性／料號自己決定，可自訂編號），重複一律當場擋下並指出是哪一筆。 */
var BIND = { prItemId: 0, back: '', boundId: 0, codeTouched: false, textTouched: false, attrs: [], items: [] };

function openBind(prItemId) {
    var it = ((CUR && CUR.items) || []).filter(function (x) { return String(x.pr_item_id) === String(prItemId); })[0];
    if (!it) return;
    BIND = { prItemId: prItemId, boundId: parseInt(it.buy_spec_id, 10) || 0,
             // 綁完要回到原本那個視窗（詢價 or 到貨），不能寫死其中一個
             back: $('#mQuote').hasClass('show') ? 'quote' : ($('#mRecv').hasClass('show') ? 'recv' : ''),
             codeTouched: false, textTouched: false, attrs: [], items: [] };
    var curCode = it.buy_spec_code || it.spec_code || '';
    $('#bdInfo').html('<b>申請內容</b>：' + esc(it.item_name) + (it.spec_text ? '／' + esc(it.spec_text) : '') +
        '　數量 ' + nz(it.qty_requested) + ' ' + esc(it.unit_label || '') +
        '<br><b>目前採購料號</b>：' + (curCode ? '<b style="color:#8A5A2B;">' + esc(curCode) + '</b>' +
            (it.buy_spec_code ? '（採購綁定）' : '（申請人選的）') : '<span class="hint">尚未綁定</span>'));
    $('#bdClear').toggle(!!it.buy_spec_id);
    // 預設先查既有：帶申請的品名去找，找得到就不該再建一支新料號
    bdTab('exist');
    $('#bdFCat,#bdFTag').val('');
    $('#bdFKw').val(it.item_name || '');
    bdSearch();
    // 建新的那一頁：類別預設沿用申請時帶的類別，品名預設帶申請人寫的
    $('#bdCat').val(it.category_id || (META.categories.length ? META.categories[0].category_id : ''));
    $('input[name=bdItemMode][value=exist]').prop('checked', true);
    $('#bdItemName').val(it.item_name || ''); $('#bdItemDup').empty();
    $('#bdSpecText').val(it.spec_text || ''); $('#bdSpecCode').val('');
    $('#bdUnit').val(it.unit_id || ''); $('#bdLoc').val(it.location_id || ''); $('#bdSafe').val('');
    $('#bdErr').hide().empty();
    // textTouched 只在採購自己動過規格說明時才成立：帶進來的申請人原文可以被屬性組合覆蓋
    bdCatChanged();
    openMask('mBind');
}
function bdTab(which) {
    $('#bdTabs .pq-tab').removeClass('on').filter('[data-bd=' + which + ']').addClass('on');
    $('#bdExist').toggle(which === 'exist');
    $('#bdNew').toggle(which === 'new');
    $('#bdSave').toggle(which === 'new');
}
/** 綁既有：查詢結果 */
function bdSearch() {
    $('#bdResult').html('<span class="hint">查詢中…</span>');
    api('spec_search', { kw: $('#bdFKw').val(), category_id: $('#bdFCat').val(), tag_id: $('#bdFTag').val() })
    .done(function (r) {
        if (!r.specs.length) {
            $('#bdResult').html('<span class="hint">查無採購料號——請改關鍵字（少打幾個字），或切到「建立新採購料號」。</span>');
            return;
        }
        var h = '<div class="pq-wrap"><table class="pq-table"><thead><tr><th>採購料號</th><th>品名</th><th>規格</th>' +
                '<th>類別</th><th>目前庫存</th><th>最近採購價</th><th></th></tr></thead><tbody>';
        r.specs.forEach(function (s, i) {
            var isCur = String(s.spec_id) === String(BIND.boundId);
            h += '<tr' + (isCur ? ' style="background:#FFF3DF;"' : '') + '><td><b>' + esc(s.spec_code) + '</b></td>' +
                 '<td class="l">' + esc(s.item_name) + '</td><td class="l">' + esc(s.spec_text) + '</td>' +
                 '<td>' + esc(s.category_name) + '</td>' +
                 '<td class="r">' + nz(s.stock_qty) + ' ' + esc(s.unit_label || '') + '</td>' +
                 '<td class="r money">' + (s.last_price ? money(s.last_price) : '—') + '</td>' +
                 '<td>' + (isCur ? '<span class="hint">目前綁定</span>'
                     : '<button class="pq-btn warm bd-pick" data-id="' + s.spec_id + '">綁定這筆</button>') + '</td></tr>';
        });
        $('#bdResult').html(h + '</tbody></table></div>');
    }).fail(function (m) { $('#bdResult').html('<span style="color:#DD5138;">' + esc(m) + '</span>'); });
}
$(document).on('click', '.bd-pick', function () {
    bdSubmit({ mode: 'existing', spec_id: $(this).data('id') }, '已綁定採購料號');
});
/** 建新的：類別換了 → 重載該類別的品項與規格屬性、重取建議編號 */
function bdCatChanged() {
    var cat = $('#bdCat').val();
    api('item_search', { category_id: cat }).done(function (r) {
        BIND.items = r.items || [];
        var h = '<option value="">（請選擇品項）</option>';
        BIND.items.forEach(function (o) {
            h += '<option value="' + o.item_id + '">' + esc(o.item_code + '　' + o.item_name) +
                 '（' + (o.specs || []).length + ' 個料號）</option>';
        });
        $('#bdItemSel').html(h);
        // 沒有既有品項可掛時，自動切到「建立新品項」，不要讓人卡在空下拉
        if (!BIND.items.length) {
            $('input[name=bdItemMode][value=new]').prop('checked', true).trigger('change');
        } else {
            // 品名一樣的直接幫他選好（最常見情況：同一種東西買不同尺寸）
            var name = $.trim($('#bdItemName').val());
            var hit = BIND.items.filter(function (o) { return o.item_name === name; })[0];
            if (hit) $('#bdItemSel').val(hit.item_id);
        }
        bdItemChanged();
    }).fail(fail);
    api('attr_list', { category_id: cat }).done(function (r) {
        BIND.attrs = r.attrs || [];
        $('#bdAttrs').html(bdAttrHtml(BIND.attrs));
        $('#bdSpecHint').text(BIND.attrs.length ? '上面填了屬性就會自動組出規格說明，也可以自己改。'
                                                : '這個類別還沒設定規格屬性（設定→規格屬性設定可加），直接寫規格說明即可。');
    }).fail(fail);
}
function bdAttrHtml(attrs) {
    if (!attrs.length) return '';
    var h = '';
    attrs.forEach(function (a) {
        h += '<div class="pq-fld"><label>' + esc(a.attr_name) + (a.attr_unit ? '（' + esc(a.attr_unit) + '）' : '') + '</label>';
        if (a.attr_type === 'select') {
            h += '<select class="bd-attr" data-id="' + a.attr_id + '"><option value="">—</option>';
            String(a.attr_options || '').split(',').forEach(function (o) {
                o = o.trim(); if (!o) return;
                h += '<option value="' + esc(o) + '">' + esc(o) + '</option>';
            });
            h += '</select>';
        } else {
            h += '<input type="' + (a.attr_type === 'number' ? 'number' : 'text') +
                 '" class="bd-attr" data-id="' + a.attr_id + '" step="any">';
        }
        h += '</div>';
    });
    return h;
}
/** 選了哪個品項 → 顯示它現有的料號（避免又建一支一樣的）＋重取建議編號 */
function bdItemChanged() {
    var isNew = $('input[name=bdItemMode]:checked').val() === 'new';
    $('#bdItemExistWrap').toggle(!isNew);
    $('#bdItemNewWrap').toggle(isNew);
    var itemId = isNew ? 0 : (parseInt($('#bdItemSel').val(), 10) || 0);
    if (!isNew && itemId) {
        var o = BIND.items.filter(function (x) { return String(x.item_id) === String(itemId); })[0];
        var sp = (o && o.specs) || [];
        $('#bdItemSpecs').html(sp.length
            ? '這個品項現有料號：' + sp.map(function (s) {
                  return '<a href="javascript:;" class="bd-pick" data-id="' + s.spec_id + '" title="直接綁定這筆">' +
                         esc(s.spec_code + '（' + s.spec_text + '）') + '</a>'; }).join('、') +
              '　<span class="hint">要買的就是其中一筆的話直接點它，不要再建新的。</span>'
            : '<span class="hint">這個品項還沒有任何料號。</span>');
        if (o && o.default_unit_id && !$('#bdUnit').val()) $('#bdUnit').val(o.default_unit_id);
    } else {
        $('#bdItemSpecs').empty();
    }
    if (!BIND.codeTouched) bdCodeSuggest();
}
/** 建議編號：先讓採購看到會拿到什麼號碼（可自己改成公司慣用編號） */
function bdCodeSuggest() {
    var isNew = $('input[name=bdItemMode]:checked').val() === 'new';
    var itemId = isNew ? 0 : (parseInt($('#bdItemSel').val(), 10) || 0);
    if (!isNew && !itemId) { $('#bdSpecCode').val(''); return; }
    api('code_preview', { item_id: itemId, category_id: $('#bdCat').val() }).done(function (r) {
        $('#bdSpecCode').val(r.spec_code || '');
        BIND.codeTouched = false;
        $('#bdCodeHint').text(r.is_new_item
            ? '新品項編碼 ' + r.item_code + '，料號建議 ' + r.spec_code + '（可改，全系統不可重複）'
            : '建議 ' + r.spec_code + '（可改成公司慣用編號，全系統不可重複）');
    });
}
/** 屬性填一填就即時組出規格說明；除非採購自己動過那一欄 */
function bdCompose() {
    if (BIND.textTouched) return;
    var parts = [];
    BIND.attrs.forEach(function (a) {
        var v = $.trim(String($('.bd-attr[data-id="' + a.attr_id + '"]').val() || ''));
        if (v !== '') parts.push(a.attr_name + v + (a.attr_unit || ''));
    });
    $('#bdSpecText').val(parts.join(' '));
}
function bdSaveNew() {
    // 「綁既有」分頁時共用檔的 Enter 規則會去點 .m-foot 的主要動作鈕（此時是隱藏的建立鈕），
    // 若不擋，在搜尋框按 Enter 會憑隱藏欄位建出一支料號——改成當成「再查一次」
    if (!$('#bdNew').is(':visible')) { bdSearch(); return; }
    var isNew = $('input[name=bdItemMode]:checked').val() === 'new';
    var d = { mode: 'new', pr_item_id: BIND.prItemId, category_id: $('#bdCat').val(),
              spec_text: $.trim($('#bdSpecText').val()), spec_code: $.trim($('#bdSpecCode').val()),
              unit_id: $('#bdUnit').val() || 0, location_id: $('#bdLoc').val() || 0,
              safety_qty: $('#bdSafe').val() || '' };
    var vals = {};
    $('.bd-attr').each(function () { var v = $.trim(String($(this).val() || '')); if (v !== '') vals[$(this).data('id')] = v; });
    d.attr_vals = JSON.stringify(vals);
    if (isNew) {
        d.item_id = 0; d.item_name = $.trim($('#bdItemName').val());
        if (!d.item_name) { bdErr('請輸入新品項的品名'); return; }
    } else {
        d.item_id = parseInt($('#bdItemSel').val(), 10) || 0;
        if (!d.item_id) { bdErr('請選一個既有品項，或改成「建立新品項」'); return; }
    }
    if (!d.spec_text) { bdErr('請填規格說明（例：Ø5 長100 HSS）——它是這支料號代表的東西'); return; }
    $('#bdErr').hide();
    bdSubmit(d, '已建立採購料號並綁定');
}
/** 綁完只更新那一列的料號欄（詢價／到貨兩張表格都有這一欄，欄位位置不同） */
function bdRefreshRow(prItemId) {
    var it = ((CUR && CUR.items) || []).filter(function (x) { return String(x.pr_item_id) === String(prItemId); })[0];
    if (!it) return;
    var $q = $('#quoteBody tr[data-id="' + prItemId + '"]');
    if ($q.length) $q.children('td').eq(4).html(bindCell(it));
    var $r = $('#recvBody tr[data-id="' + prItemId + '"]');
    if ($r.length) {
        // data-spec 是「能不能入庫」的判定依據，跟著一起更新
        $r.attr('data-spec', it.buy_spec_id || it.spec_id || '').data('spec', it.buy_spec_id || it.spec_id || '');
        $r.children('td').eq(2).html(bindCell(it));
    }
}
function bdErr(msg, extraHtml) {
    $('#bdErr').html(esc(msg) + (extraHtml || '')).show();
}
/** 綁定共用出口：成功就回到原本那個視窗；重複則當場說明是哪一筆、可一鍵改綁 */
function bdSubmit(data, okMsg) {
    var d = $.extend({ pr_item_id: BIND.prItemId }, data);
    $('#bdSave,#bdClear').prop('disabled', true);
    api('bind_spec', d, 'POST').done(function (r) {
        if (r.conflict) {
            bdTab('new');
            bdErr(r.msg, r.spec_id ? ' <button class="pq-btn warm bd-pick" data-id="' + r.spec_id + '">改綁這筆</button>' : '');
            if (r.conflict === 'item' && r.item_id) {
                // 幫他切到「掛在既有品項」並選好那個同名品項，少一步手動找
                $('input[name=bdItemMode][value=exist]').prop('checked', true);
                if (!$('#bdItemSel option[value="' + r.item_id + '"]').length) { bdCatChanged(); }
                else { $('#bdItemSel').val(r.item_id); bdItemChanged(); }
            }
            return;
        }
        var prId = BIND.prItemId;
        api('req_detail', { req_id: CUR.req_id }).done(function (rr) {
            CUR = rr.req;
            closeMask('mBind');
            // 只換那一列的料號欄，不整個重畫——重畫會把採購剛打好還沒送出的單價／數量清掉
            if (BIND.back === 'quote' || BIND.back === 'recv') bdRefreshRow(prId);
            else openDetail(CUR.req_id);
            if (okMsg) alert(okMsg + (r.spec_code ? '：' + r.spec_code : ''));
        });
    }).fail(function (m) { bdErr('操作失敗：' + m); })
      .always(function () { $('#bdSave,#bdClear').prop('disabled', false); });
}
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
            bad = '有品項還沒有採購料號，無法入庫——請先按該列「綁定料號」，或把該列改成「不列管」'; return;
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
        if (it.brands) { BRANDS = it.brands; renderBrandOptions(); }   // 規格編輯的品牌下拉來源
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
        $('#specBody').html('<tr><td colspan="10" class="pq-empty">尚無規格——按「新增規格」加尺寸／顏色等變體</td></tr>'); return;
    }
    var h = '';
    ITEMEDIT.specs.forEach(function (s, i) {
        var low = s.safety_qty && parseFloat(s.stock_qty) < parseFloat(s.safety_qty);
        // 供應商：主要那家標星號，其餘只顯示家數（同規格可跟多家買）
        var vs = s.vendors || [], pri = vs.filter(function (v) { return v.is_primary === 1; })[0] || vs[0];
        var vTxt = !vs.length ? '<span class="hint">未設定</span>'
            : (esc(pri.vendor_name || pri.vendor_id) + (vs.length > 1 ? ' <span class="hint">等 ' + vs.length + ' 家</span>' : ''));
        h += '<tr><td>' + esc(s.spec_code) + '</td><td class="l">' + esc(s.spec_text) + '</td>' +
             '<td>' + (s.brand ? esc(s.brand) : '<span class="hint">—</span>') + '</td>' +
             '<td class="l">' + vTxt + '</td>' +
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
    $('#spCode').val(s ? (s.spec_code || '') : '');   // 新增時留白＝自動編號；編輯時可改號
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
    // 品牌（可打字或從清單選）＋ 供應商（同規格可跟多家買）
    $('#spBrand').val(s ? (s.brand || '') : '');
    renderBrandOptions();
    SV_ROWS = (s && s.vendors) ? s.vendors.map(function (v) { return $.extend({}, v); }) : [];
    $('#svKw').val(''); $('#svVendorList').empty();
    renderSpecVendors();
    openMask('mSpec');
}
function saveSpec() {
    var vals = {};
    $('.sp-attr').each(function () { var v = $(this).val(); if (v !== '') vals[$(this).data('id')] = v; });
    api('spec_save', { spec_id: $('#spAttrs').data('spec_id') || 0, item_id: ITEMEDIT.id,
        spec_text: $('#spText').val(), spec_code: $.trim($('#spCode').val()),
        attr_vals: JSON.stringify(vals), unit_id: $('#spUnit').val() || 0,
        location_id: $('#spLoc').val() || 0, safety_qty: $('#spSafe').val(),
        brand: $.trim($('#spBrand').val()), vendors: JSON.stringify(SV_ROWS) }, 'POST')
    .done(function (r) { closeMask('mSpec'); openItem(ITEMEDIT.id); }).fail(fail);
}

/* ── 品牌清單（採購維護）＋ 規格的供應商 ─────────────────
 * 使用者 2026-07-30 指示：品牌 ≠ 購買廠商；品牌可手動輸入或從清單選，清單由採購建立。
 */
var BRANDS = [], SV_ROWS = [];
function renderBrandOptions() {
    $('#brandOptions').html(BRANDS.filter(function (b) { return b.is_active === 1; })
        .map(function (b) { return '<option value="' + esc(b.brand_name) + '">'; }).join(''));
}
function loadBrands(cb) {
    api('brand_list', { all: 1 }).done(function (r) {
        BRANDS = r.brands || []; renderBrandOptions(); if (cb) cb();
    }).fail(function () { if (cb) cb(); });
}
function openBrandMgr() { loadBrands(function () { renderBrands(); openMask('mBrandMgr'); }); }
function renderBrands() {
    var h = BRANDS.map(function (b) {
        return '<tr data-id="' + b.brand_id + '"><td class="l">' + esc(b.brand_name) + '</td>' +
               '<td class="l">' + esc(b.note || '') + '</td>' +
               '<td>' + (b.use_cnt || 0) + '</td>' +
               '<td>' + (b.is_active === 1 ? '啟用' : '<span class="hint">已停用</span>') + '</td>' +
               '<td><button class="pq-btn bd-ren">改名</button> ' +
               (b.is_active === 1 ? '<button class="pq-btn danger bd-del">刪除</button>'
                                  : '<button class="pq-btn bd-on">啟用</button>') + '</td></tr>';
    }).join('');
    $('#bdBody').html(h || '<tr><td colspan="5" class="pq-empty">尚未建立品牌</td></tr>');
}
function addBrand() {
    var n = $.trim($('#bdName').val());
    if (!n) { alert('請輸入品牌名稱'); $('#bdName').focus(); return; }
    api('brand_save', { brand_name: n, note: $.trim($('#bdNote').val()) }, 'POST').done(function (r) {
        BRANDS = r.brands || []; $('#bdName,#bdNote').val(''); renderBrands(); renderBrandOptions();
    }).fail(fail);
}
$(document).on('click', '#bdAdd', addBrand);
$(document).on('click', '.bd-ren', function () {
    var id = $(this).closest('tr').data('id');
    var b = BRANDS.filter(function (x) { return String(x.brand_id) === String(id); })[0] || {};
    var n = prompt('品牌改名：', b.brand_name || '');
    if (n === null) return;
    n = $.trim(n); if (!n) return;
    if (b.use_cnt > 0 && !confirm('已有 ' + b.use_cnt + ' 個規格用這個品牌。\n改名只會改清單，既有規格上存的品牌名稱不會跟著改，確定？')) return;
    api('brand_save', { brand_id: id, brand_name: n, note: b.note || '', is_active: b.is_active }, 'POST')
        .done(function (r) { BRANDS = r.brands || []; renderBrands(); renderBrandOptions(); }).fail(fail);
});
$(document).on('click', '.bd-on', function () {
    var id = $(this).closest('tr').data('id');
    var b = BRANDS.filter(function (x) { return String(x.brand_id) === String(id); })[0] || {};
    api('brand_save', { brand_id: id, brand_name: b.brand_name, note: b.note || '', is_active: 1 }, 'POST')
        .done(function (r) { BRANDS = r.brands || []; renderBrands(); renderBrandOptions(); }).fail(fail);
});
$(document).on('click', '.bd-del', function () {
    var id = $(this).closest('tr').data('id');
    var b = BRANDS.filter(function (x) { return String(x.brand_id) === String(id); })[0] || {};
    if (!confirm('刪除品牌「' + (b.brand_name || '') + '」？\n若已有規格使用，會改成「停用」而不是真的刪掉（既有規格顯示不受影響）。')) return;
    api('brand_delete', { brand_id: id }, 'POST').done(function (r) {
        BRANDS = r.brands || [];
        if (r.disabled) alert('已有 ' + r.used + ' 個規格使用此品牌，已改為停用（不再出現在選單，既有資料不動）。');
        renderBrands(); renderBrandOptions();
    }).fail(fail);
});

/* 規格的供應商小表格 */
function renderSpecVendors() {
    if (!SV_ROWS.length) {
        $('#svBody').html('<tr><td colspan="7" class="pq-empty">尚未設定供應商——用下方搜尋加入廠商</td></tr>');
        return;
    }
    var h = SV_ROWS.map(function (v, i) {
        return '<tr data-i="' + i + '">' +
            '<td><input type="radio" name="svPri" class="sv-pri"' + (v.is_primary === 1 ? ' checked' : '') + '></td>' +
            '<td class="l">' + esc(v.vendor_name || '') + ' <span class="hint">' + esc(v.vendor_id || '') + '</span></td>' +
            '<td><input type="text" class="sv-pn" value="' + esc(v.vendor_part_no || '') + '" maxlength="60" style="width:110px;"></td>' +
            '<td><input type="number" class="sv-price" value="' + (v.ref_price == null ? '' : v.ref_price) + '" step="0.0001" style="width:100px;"></td>' +
            '<td><input type="date" class="sv-qd" value="' + esc((v.quote_date || '').substr(0, 10)) + '" style="width:140px;"></td>' +
            '<td><input type="text" class="sv-note" value="' + esc(v.note || '') + '" maxlength="200" style="width:130px;"></td>' +
            '<td class="no-print"><button class="pq-btn danger sv-del">移除</button></td></tr>';
    }).join('');
    $('#svBody').html(h);
}
$(document).on('change', '.sv-pri', function () {
    var i = $(this).closest('tr').data('i');
    SV_ROWS.forEach(function (v, k) { v.is_primary = (k === i) ? 1 : 0; });
});
$(document).on('input change', '.sv-pn, .sv-price, .sv-qd, .sv-note', function () {
    var $tr = $(this).closest('tr'), v = SV_ROWS[$tr.data('i')];
    if (!v) return;
    v.vendor_part_no = $tr.find('.sv-pn').val();
    v.ref_price      = $tr.find('.sv-price').val();
    v.quote_date     = $tr.find('.sv-qd').val();
    v.note           = $tr.find('.sv-note').val();
});
$(document).on('click', '.sv-del', function () {
    SV_ROWS.splice($(this).closest('tr').data('i'), 1);
    renderSpecVendors();
});
var _svTimer = null;
$(document).on('input', '#svKw', function () {
    var kw = $.trim(this.value);
    clearTimeout(_svTimer);
    if (!kw) { $('#svVendorList').empty(); return; }
    _svTimer = setTimeout(function () {
        api('search_vendor', { kw: kw }).done(function (r) {
            var h = (r.vendors || []).map(function (v) {
                return '<a href="#" class="sv-pick" data-id="' + esc(v.maker_id_no) + '" data-nm="' + esc(v.maker_id) + '" ' +
                       'style="display:inline-block;margin:2px 6px 2px 0;color:#b5762a;">' +
                       esc(v.maker_id) + '（' + esc(v.maker_id_no) + '）</a>';
            }).join('');
            $('#svVendorList').html(h || '<span class="hint">查無廠商</span>');
        }).fail(function () { $('#svVendorList').html('<span class="hint">搜尋失敗</span>'); });
    }, 250);
});
$(document).on('click', '.sv-pick', function (e) {
    e.preventDefault();
    var id = $(this).data('id'), nm = $(this).data('nm');
    if (SV_ROWS.some(function (v) { return String(v.vendor_id) === String(id); })) { alert('這家廠商已經在清單裡了'); return; }
    SV_ROWS.push({ vendor_id: id, vendor_name: nm, vendor_part_no: '', ref_price: '', quote_date: '',
                   is_primary: SV_ROWS.length ? 0 : 1, note: '' });   // 第一家自動當主要供應商
    $('#svKw').val(''); $('#svVendorList').empty();
    renderSpecVendors();
});

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
        print_header: $('#cfgPh').val(), print_footer: $('#cfgPf').val() }, 'POST')
    .done(function () {
        META.thresholds.l1 = parseFloat($('#cfgL1').val()); META.thresholds.l2 = parseFloat($('#cfgL2').val());
        META.print_header = $('#cfgPh').val(); META.print_footer = $('#cfgPf').val();
        $('#printHead').text(META.print_header || '');
        // 重撈一次，讓「目前實際使用／可否寫入」立刻反映剛存的路徑
        api('meta').done(function (r) { renderNasState(r); });
        alert('已儲存設定');
    }).fail(fail);
}

})();
