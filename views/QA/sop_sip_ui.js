/**
 * sop_sip_ui.js — 作業標準書(SOP)／標準檢驗指導書(SIP) 前端
 * 建立：2026-09-21
 *
 * 由 sop_sip.php 提供：SS_API／SS_CSRF／SS_PERMS／SS_KINDS／SS_SCOPES／SS_SLOTS／SS_STATUSES／SS_TODAY
 * 規則的唯一來源在後端 sopsip_lib.php，這裡只做「即時提示」，存檔仍以後端回覆為準（鐵律8）。
 */
var TAB = 'sop';            // 目前分頁
var ROWS = [];              // 目前清單（全部，分頁在前端做）
var PAGE = 1, PER = 10;
var CUR = null;             // 目前打開的文件 detail

function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
}
function num(v) { return parseInt(v, 10) || 0; }
/** 日期顯示一律 YYYY.MM.DD（ai-rules/20），走共用 egFmtDate，不自寫 */
function dispDate(s) { return (window.egFmtDate ? egFmtDate(s) : (s || '')) || ''; }
function openMask(id) { $('#' + id).addClass('on'); }
function closeMask(id) { $('#' + id).removeClass('on'); }
$(document).on('click', '[data-close]', function () { closeMask($(this).data('close')); });
$(document).on('click', '.ss-mask', function (e) { if (e.target === this) $(this).removeClass('on'); });

function api(action, data, cb, method) {
    var d = $.extend({ action: action }, data || {});
    if ((method || 'GET') === 'POST') d.csrf = SS_CSRF;
    $.ajax({ url: SS_API, type: method || 'GET', data: d, dataType: 'json' })
        .done(function (res) {
            if (!res || !res.success) { alert((res && res.message) || '操作失敗'); return; }
            if (cb) cb(res);
        })
        .fail(function (x) { alert('連線失敗（' + x.status + '）' + (x.responseText || '').slice(0, 200)); });
}
function post(action, data, cb) { api(action, data, cb, 'POST'); }

/* ══════════════════════ 清單 ══════════════════════ */

function kindOptions(sel, withAll) {
    var h = withAll ? '<option value="">全部</option>' : '';
    $.each(SS_KINDS, function (k, d) {
        if (d.tab !== TAB) return;
        h += '<option value="' + k + '">' + esc(d.label) + '</option>';
    });
    $(sel).html(h);
}

function load() {
    api('list', {
        tab: TAB, kind: $('#fKind').val() || '', scope: $('#fScope').val() || '',
        status: $('#fStatus').val() || '', year: $('#fYear').val() || '', kw: $('#fKw').val() || ''
    }, function (res) {
        ROWS = res.rows || [];
        PAGE = 1;
        renderList();
    });
}

function renderList() {
    var total = ROWS.length, pages = Math.max(1, Math.ceil(total / PER));
    if (PAGE > pages) PAGE = pages;
    var from = (PAGE - 1) * PER, part = ROWS.slice(from, from + PER);

    $('#listNote').html(total
        ? '共 <b>' + total + '</b> 份文件。點一列可打開內容；<b>版次</b>欄顯示的是現行版，'
          + '同一份文件的舊版次在文件裡的「版次歷程」看得到。'
        : '目前沒有符合條件的文件。按右上角「新增」建立第一份。');

    var h = '';
    $.each(part, function (i, r) {
        var bind = r.scope === 'machine'
            ? (esc(r.asset_no || '') + (r.field_no ? '<br><span class="muted-help">' + esc(r.field_no) + '</span>' : ''))
            : (r.scope === 'part' ? esc(r.part_no_text || '') : '<span class="muted-help">通用</span>');
        h += '<tr data-doc="' + num(r.doc_id) + '" data-ver="' + num(r.ver_id) + '">'
          + '<td>' + esc(r.kind_label) + '<br><span class="muted-help">' + esc(r.scope_label) + '</span></td>'
          + '<td>' + bind + '</td>'
          + '<td><b>' + esc(r.title) + '</b>'
              + (r.proc_name ? '<br><span class="muted-help">' + esc(r.proc_name) + '</span>' : '') + '</td>'
          + '<td class="c">' + esc(r.proc_name && r.kind === 'sip' ? r.proc_name : '') + '</td>'
          + '<td>' + esc(r.customer_name || '') + '</td>'
          + '<td class="c">' + esc(r.ver_no) + (num(r.ver_cnt) > 1 ? '<br><span class="muted-help">共 ' + num(r.ver_cnt) + ' 版</span>' : '') + '</td>'
          + '<td class="c">' + dispDate(r.form_date) + '</td>'
          + '<td class="c"><span class="st st-' + esc(r.status) + '">' + esc(r.status_label) + '</span></td>'
          + '<td class="c">' + num(r.sign_cnt) + ' / ' + num(r.sign_total) + '</td>'
          + '<td class="c">'
              + '<button class="btn btn-xs btn-warm-o act-open">開啟</button> '
              + '<button class="btn btn-xs btn-warm-o act-print">列印</button>'
          + '</td></tr>';
    });
    $('#tblList tbody').html(h || '<tr><td colspan="10" class="c muted-help" style="padding:16px;">沒有資料</td></tr>');

    var pg = '';
    if (pages > 1) {
        pg += '<span class="muted-help">第 ' + PAGE + ' / ' + pages + ' 頁</span>';
        pg += '<button data-pg="1">&laquo;</button>';
        for (var p = Math.max(1, PAGE - 2); p <= Math.min(pages, PAGE + 2); p++)
            pg += '<button data-pg="' + p + '"' + (p === PAGE ? ' class="on"' : '') + '>' + p + '</button>';
        pg += '<button data-pg="' + pages + '">&raquo;</button>';
    }
    pg += '　<span class="muted-help">每頁</span> <select id="perSel" data-eg-skip>'
        + [5, 10, 20, 50].map(function (n) { return '<option value="' + n + '"' + (n === PER ? ' selected' : '') + '>' + n + '</option>'; }).join('')
        + '</select>';
    $('#pagerTop,#pagerBottom').html(pg);
}

$(document).on('click', '.pager button[data-pg]', function () { PAGE = num($(this).data('pg')); renderList(); });
$(document).on('change', '#perSel', function () { PER = num($(this).val()) || 10; PAGE = 1; renderList(); });
$(document).on('click', '.ss-tab', function () {
    $('.ss-tab').removeClass('on'); $(this).addClass('on');
    TAB = $(this).data('tab');
    kindOptions('#fKind', true);
    load();
});
$('#btnSearch').on('click', load);
$('#fKind,#fScope,#fStatus,#fYear').on('change', load);
$('#fKw').on('keydown', function (e) { if (e.which === 13) { e.preventDefault(); load(); } });
$(document).on('click', '#tblList tbody tr', function (e) {
    if ($(e.target).hasClass('act-print')) { doPrint(num($(this).data('ver'))); return; }
    openDoc(num($(this).data('ver')));
});

/* ══════════════════════ 新增 ══════════════════════ */

$('#btnNew').on('click', function () {
    kindOptions('#nKind', false);
    $('#nErr').text(''); $('#nBind').val(''); $('#nBindId').val('');
    $('#nTitle').val(''); $('#nProc').val(''); $('#nVer').val('01');
    $('#nDate').val(SS_TODAY); $('#nNote').val('初訂');
    syncScope();
    openMask('maskNew');
});

function syncScope() {
    var kind = $('#nKind').val(), h = '';
    var allow = (kind === 'equip') ? ['machine'] : ['general', 'part'];
    $.each(allow, function (i, s) { h += '<option value="' + s + '">' + esc(SS_SCOPES[s]) + '</option>'; });
    $('#nScope').html(h);
    syncBind();
}
function syncBind() {
    var s = $('#nScope').val();
    $('#nBind').val(''); $('#nBindId').val('');
    if (s === 'machine') {
        $('#nBindLab').text('機台 *').closest('.frm').find('#nBind').attr('placeholder', '');
        $('#nBind').show().prop('disabled', false);
        $('#nBindHint').text('打機器編號（EG-027）、現場編號（KX500）或機台名稱，從清單挑一台。');
    } else if (s === 'part') {
        $('#nBindLab').text('料號 *');
        $('#nBind').show().prop('disabled', false);
        $('#nBindHint').text('打料號從清單挑；同一個料號文字可能有好幾筆、分屬不同客戶，一定要挑對那一筆。');
    } else {
        $('#nBindLab').text('綁定對象');
        $('#nBind').hide().prop('disabled', true);
        $('#nBindHint').text('通用文件不綁特定機台或料號。');
    }
}
$('#nKind').on('change', syncScope);
$('#nScope').on('change', syncBind);

/**
 * 綁定對象的自動完成（機台與料號共用同一個輸入框，依目前的適用範圍決定查哪一支）。
 * 兩件事一定要做對：
 *  ① **手動改字就自動解除綁定**，否則會出現「畫面寫 A、實際綁著 B」而且完全看不出來
 *  ② 建議清單用 position:fixed 由 JS 定位——跳窗的 .m-body 是 overflow:auto 的捲動容器，
 *     absolute 會被整個裁掉，症狀是「打了字卻沒有清單可以選」，只數 DOM 節點的測試抓不到
 */
var $AC = $('<div class="ac-list"></div>').appendTo('body');
var acTimer = null, acSeq = 0;

$(document).on('input', '#nBind', function () {
    $('#nBindId').val('');
    var kw = $(this).val().trim(), s = $('#nScope').val();
    clearTimeout(acTimer);
    if (!kw) { $AC.hide(); return; }
    var my = ++acSeq, action = (s === 'machine') ? 'search_machine' : 'search_part';
    acTimer = setTimeout(function () {
        api(action, { kw: kw }, function (res) {
            if (my !== acSeq) return;           // 打字很快時只採用最後一次查詢的結果
            var rows = res.rows || [], h = '';
            $.each(rows, function (i, r) {
                h += '<div class="it" data-i="' + i + '">' + (s === 'machine'
                    ? ('<span class="hit">' + esc(r.asset_no || '') + '</span>　' + esc(r.field_no || '') + '　' + esc(r.machine || ''))
                    : ('<span class="hit">' + esc(r.D_Setting_Id) + '</span>　' + esc(r.customer || r.Customer_Id || '')
                       + '　<span class="muted-help">#' + num(r.d_id) + '</span>')) + '</div>';
            });
            $AC.html(h || '<div class="it muted-help">查無資料</div>').data('rows', rows).data('mode', s);
            var $in = $('#nBind'), o = $in.offset(), st = $(window).scrollTop();
            $AC.css({ left: o.left, top: o.top - st + $in.outerHeight() + 2, width: Math.max(300, $in.outerWidth()) }).show();
        });
    }, 180);
});
$AC.on('click', '.it', function () {
    var rows = $AC.data('rows') || [], r = rows[num($(this).data('i'))];
    if (!r) { $AC.hide(); return; }
    if ($AC.data('mode') === 'machine') {
        $('#nBind').val((r.asset_no || '') + ' ' + (r.field_no || ''));
        $('#nBindId').val(r.machine_id);
        if (!$('#nTitle').val()) $('#nTitle').val(r.machine || '');
    } else {
        $('#nBind').val(r.D_Setting_Id);
        $('#nBindId').val(r.d_id);
        if (!$('#nTitle').val()) $('#nTitle').val(r.D_Setting_Id);
    }
    $AC.hide();
});
$(document).on('click', function (e) {
    if (!$(e.target).closest('#nBind').length && !$(e.target).closest($AC).length) $AC.hide();
});

$('#nSave').on('click', function () {
    var s = $('#nScope').val(), err = [];
    if (!$('#nTitle').val().trim()) err.push('文件名稱');
    if (!$('#nDate').val()) err.push('表單日期');
    if ((s === 'machine' || s === 'part') && !num($('#nBindId').val())) err.push('綁定對象（要從清單挑，打字不選不算）');
    if (err.length) { $('#nErr').text('還沒填：' + err.join('、')); return; }
    $('#nErr').text('');
    post('doc_save', {
        kind: $('#nKind').val(), scope: s,
        machine_id: s === 'machine' ? num($('#nBindId').val()) : 0,
        part_d_id: s === 'part' ? num($('#nBindId').val()) : 0,
        title: $('#nTitle').val(), proc_name: $('#nProc').val(),
        ver_no: $('#nVer').val(), form_date: $('#nDate').val(), rev_note: $('#nNote').val()
    }, function (res) {
        closeMask('maskNew');
        load();
        openDoc(num(res.ver_id));
    });
});

/* ══════════════════════ 文件內容 ══════════════════════ */

function openDoc(verId) {
    if (!verId) return;
    api('detail', { ver_id: verId }, function (res) {
        CUR = res;
        renderDoc();
        openMask('maskDoc');
    });
}

function fileUrl(id) { return 'sopsip_file.php?id=' + num(id); }

/** 表頭：三種版面各自的欄位 */
function headHtml() {
    var v = CUR.ver, d = CUR.doc, ro = CUR.can_edit ? '' : ' readonly';
    var h = '<div class="sec"><h5>表頭</h5><div class="frm">';
    h += '<label>文件名稱</label><div class="wide"><input id="fTitle" value="' + esc(d.title) + '"' + ro + '></div>';
    if (d.scope === 'machine' && CUR.machine) {
        var m = CUR.machine;
        h += '<label>機器編號</label><div><input value="' + esc(m.asset_no || '') + '" readonly></div>'
           + '<label>現場編號</label><div><input value="' + esc(m.field_no || '') + '" readonly></div>';
    } else if (d.scope === 'part') {
        h += '<label>產品料號</label><div><input value="' + esc(d.part_no_text || '') + '" readonly></div>'
           + '<label>料號主檔</label><div><input value="#' + num(d.part_d_id) + '" readonly></div>';
    } else {
        h += '<label>適用範圍</label><div class="wide"><input value="通用（不綁特定機台或料號）" readonly></div>';
    }
    h += '<label>版次</label><div><input id="fVer" value="' + esc(v.ver_no) + '"' + ro + '></div>'
       + '<label>表單日期</label><div><input type="date" id="fDate" value="' + esc(v.form_date || '') + '"' + ro + '></div>'
       + '<label>制/修訂事項</label><div class="wide"><input id="fRev" value="' + esc(v.rev_note || '') + '"' + ro + '></div>';

    if (CUR.kind === 'equip') {
        h += '<label>機器製造商</label><div><input id="f_m_maker" value="' + esc(v.m_maker || '') + '"' + ro + '></div>'
           + '<label>機器名稱</label><div><input id="f_m_name" value="' + esc(v.m_name || '') + '"' + ro + '></div>'
           + '<label>型式規格</label><div><input id="f_m_spec" value="' + esc(v.m_spec || '') + '"' + ro + '></div>'
           + '<label>加工適用範圍</label><div><input id="f_m_range" value="' + esc(v.m_range || '') + '"' + ro + '></div>';
    } else if (CUR.kind === 'process') {
        h += '<label>使用設備</label><div><input id="f_use_equip" value="' + esc(v.use_equip || '') + '"' + ro + '></div>'
           + '<label>預計工時</label><div><input id="f_est_hours" value="' + esc(v.est_hours || '') + '"' + ro + '></div>';
    } else {
        h += '<label>客戶名稱</label><div><input id="f_customer_name" value="' + esc(v.customer_name || '') + '"' + ro + '></div>'
           + '<label>製令單號</label><div><input id="f_order_no" value="' + esc(v.order_no || '') + '"' + ro + '></div>'
           + '<label>工程名稱</label><div><input id="f_proc" value="' + esc(d.proc_name || '') + '"' + ro + '></div>'
           + '<label>數量</label><div><input id="f_qty" value="' + esc(v.qty || '') + '"' + ro + '></div>';
    }
    h += '</div></div>';
    return h;
}

/** 設備操作說明書的三個大段落（操作方法／使用注意事項／保養維修要點），一行一步 */
function equipHtml() {
    var v = CUR.ver, ro = CUR.can_edit ? '' : ' readonly';
    function box(id, label, val, hint) {
        return '<div class="sec"><h5>' + label + '<span class="muted-help">' + hint + '</span></h5>'
             + '<textarea id="' + id + '" style="width:100%;min-height:96px;border:1px solid var(--line);'
             + 'border-radius:4px;padding:5px 7px;font-size:13px;line-height:1.7;"' + ro + '>' + esc(val || '') + '</textarea></div>';
    }
    return box('f_op_method', '操作方法', v.op_method, '一行一個步驟')
         + box('f_cautions', '使用注意事項', v.cautions, '一行一條')
         + box('f_maintain', '保養維修要點', v.maintain, '一行一條');
}

/** 製造製程說明書的操作步驟明細 */
function stepsHtml() {
    var ro = CUR.can_edit ? '' : ' readonly', dis = CUR.can_edit ? '' : ' disabled';
    var h = '<div class="sec"><h5>操作步驟'
          + '<span class="muted-help">最後一列按 ↓ 自動加一列；沒填東西的末列按 ↑ 自動移除</span></h5>'
          + '<table class="grid" id="tblSteps"><thead><tr>'
          + '<th style="width:44px;">項次</th><th style="width:130px;">名稱</th><th style="width:150px;">參考圖示</th>'
          + '<th>操作步驟</th><th style="width:200px;">說明</th>'
          + (CUR.can_edit ? '<th style="width:38px;"></th>' : '') + '</tr></thead><tbody data-eg-row-add="stepAdd" data-eg-row-del="stepDel">';
    var rows = CUR.steps.length ? CUR.steps : (CUR.can_edit ? [{}] : []);
    $.each(rows, function (i, s) { h += stepRow(i, s, ro, dis); });
    h += '</tbody></table></div>';
    return h;
}
function stepRow(i, s, ro, dis) {
    s = s || {};
    var img = num(s.img_file_id)
        ? '<img class="thumb" src="' + fileUrl(s.img_file_id) + '" data-file="' + num(s.img_file_id) + '">'
        : '<span class="muted-help">無</span>';
    return '<tr data-img="' + num(s.img_file_id) + '">'
        + '<td class="c">' + (i + 1) + '</td>'
        + '<td><input class="s-name" value="' + esc(s.step_name || '') + '"' + ro + '></td>'
        + '<td class="c">' + img
            + (CUR.can_edit ? '<br><button class="btn btn-xs btn-warm-o s-pick">挑圖</button>'
                              + (num(s.img_file_id) ? ' <button class="btn btn-xs s-clr">移除</button>' : '') : '')
        + '</td>'
        + '<td><textarea class="s-text" rows="2"' + ro + '>' + esc(s.step_text || '') + '</textarea></td>'
        + '<td><textarea class="s-note" rows="2"' + ro + '>' + esc(s.note || '') + '</textarea></td>'
        + (CUR.can_edit ? '<td class="c"><button class="btn btn-xs s-del"' + dis + '>×</button></td>' : '')
        + '</tr>';
}
/* 共用檔 eg_input_rules.js 的可增列表格會呼叫這兩支（禁止各頁自刻增刪列邏輯） */
function stepAdd($tbody) {
    $tbody.append(stepRow($tbody.children('tr').length, {}, '', ''));
    renumber($tbody);
}
function stepDel($tr) {
    var $tb = $tr.closest('tbody');
    if ($tb.children('tr').length <= 1) return;
    $tr.remove(); renumber($tb);
}
function renumber($tb) { $tb.children('tr').each(function (i) { $(this).children('td').first().text(i + 1); }); }
$(document).on('click', '.s-del', function () { stepDel($(this).closest('tr')); });

/** 標準檢驗指導書的檢驗項目明細 */
function itemsHtml() {
    var ro = CUR.can_edit ? '' : ' readonly';
    var h = '<div class="sec"><h5>檢驗項目'
          + '<span class="muted-help">最後一列按 ↓ 自動加一列；尺寸類填上下限，其餘填品質特性</span></h5>'
          + '<table class="grid" id="tblItems"><thead><tr>'
          + '<th style="width:36px;">#</th><th style="width:150px;">管理重點</th><th>品質特性</th>'
          + '<th style="width:80px;">上限</th><th style="width:80px;">下限</th>'
          + '<th style="width:70px;">擔當者</th><th style="width:120px;">檢驗方法</th>'
          + '<th style="width:90px;">檢具編號</th><th style="width:110px;">檢驗頻率</th><th style="width:140px;">備註</th>'
          + (CUR.can_edit ? '<th style="width:38px;"></th>' : '') + '</tr></thead><tbody data-eg-row-add="itemAdd" data-eg-row-del="itemDel">';
    var rows = CUR.items.length ? CUR.items : (CUR.can_edit ? [{}] : []);
    $.each(rows, function (i, r) { h += itemRow(i, r, ro); });
    h += '</tbody></table></div>';
    return h;
}
function itemRow(i, r, ro) {
    r = r || {};
    function td(cls, val, w) { return '<td><input class="' + cls + '" value="' + esc(val || '') + '"' + ro + '></td>'; }
    return '<tr><td class="c">' + (i + 1) + '</td>'
        + td('i-ctrl', r.ctrl_point) + td('i-q', r.q_char) + td('i-up', r.up_limit) + td('i-lo', r.lo_limit)
        + td('i-own', r.owner) + td('i-mth', r.method) + td('i-tool', r.tool_no) + td('i-freq', r.freq) + td('i-note', r.note)
        + (CUR.can_edit ? '<td class="c"><button class="btn btn-xs i-del">×</button></td>' : '')
        + '</tr>';
}
function itemAdd($tbody) { $tbody.append(itemRow($tbody.children('tr').length, {}, '')); renumber($tbody); }
function itemDel($tr) {
    var $tb = $tr.closest('tbody');
    if ($tb.children('tr').length <= 1) return;
    $tr.remove(); renumber($tb);
}
$(document).on('click', '.i-del', function () { itemDel($(this).closest('tr')); });

/** SIP 的圖面與注意事項 */
function sipExtraHtml() {
    var v = CUR.ver, ro = CUR.can_edit ? '' : ' readonly';
    var draw = num(v.draw_file_id);
    var h = '<div class="sec"><h5>圖面'
          + '<span class="muted-help">從這個料號的料號附件挑一個帶入（只建立關聯，不複製檔案）</span></h5>';
    h += draw ? '<img class="thumb" style="max-width:300px;max-height:220px;" src="' + fileUrl(draw) + '" data-file="' + draw + '">'
              : '<span class="muted-help">尚未帶入圖面</span>';
    if (CUR.can_edit) {
        h += '<div style="margin-top:6px;"><button class="btn btn-xs btn-warm-o" id="btnPickDraw">挑圖面</button>'
           + (draw ? ' <button class="btn btn-xs" id="btnClrDraw">移除圖面</button>' : '')
           + ' <button class="btn btn-xs btn-warm-o" id="btnUpDraw">上傳新圖</button>'
           + '<input type="file" id="fileDraw" accept="image/*,.pdf" style="display:none;"></div>';
    }
    h += '</div>';
    h += '<div class="sec"><h5>注意事項<span class="muted-help">一行一條</span></h5>'
       + '<textarea id="f_notice" style="width:100%;min-height:90px;border:1px solid var(--line);border-radius:4px;'
       + 'padding:5px 7px;font-size:13px;line-height:1.7;"' + ro + '>' + esc(v.notice || '') + '</textarea></div>';
    return h;
}

/** 簽章三格 */
function signHtml() {
    var h = '<div class="sec"><h5>簽核'
          + '<span class="muted-help">依序 製表 → 審核 → 核准；可簽的人限表單日期當時在職、且簽章當天沒請整天假</span></h5>'
          + '<div class="sign-row">';
    $.each(SS_SLOTS, function (k, def) {
        var s = CUR.signs[k];
        h += '<div class="sign-box"><div class="t"><b>' + esc(def.label) + '</b></div>';
        if (s && num(s.user_id)) {
            h += '<div class="who">' + esc(s.dept_name || '') + '　' + esc(s.position_name || '') + '<br>'
               + '<b>' + esc(s.user_name || '') + '</b>　' + dispDate(s.sign_date) + '</div>';
            if (SS_PERMS.canAdmin) h += '<div style="margin-top:5px;"><button class="btn btn-xs sg-clr" data-slot="' + k + '">清除</button></div>';
        } else if (CUR.ver.status === 'draft') {
            h += '<div class="muted-help">送簽後才會出現</div>';
        } else if (CUR.can_sign) {
            h += '<div style="margin-top:4px;"><button class="btn btn-xs btn-warm sg-go" data-slot="' + k + '">蓋這一格</button></div>';
        } else {
            h += '<div class="muted-help">尚未簽核</div>';
        }
        h += '</div>';
    });
    h += '</div></div>';
    return h;
}

/** 版次歷程＝紙本上的修訂履歷（不另外手打，由各版次組出來） */
function versHtml() {
    var h = '<div class="sec"><h5>版次歷程<span class="muted-help">紙本的「修訂履歷／修改記錄」就是這一份</span></h5>'
          + '<table class="grid"><thead><tr><th style="width:70px;">版次</th><th style="width:110px;">日期</th>'
          + '<th>制/修訂事項</th><th style="width:90px;">狀態</th><th style="width:80px;"></th></tr></thead><tbody>';
    $.each(CUR.vers || [], function (i, v) {
        var cur = num(v.ver_id) === num(CUR.ver.ver_id);
        h += '<tr' + (cur ? ' style="background:#FFF6E6;"' : '') + '>'
           + '<td class="c">' + esc(v.ver_no) + (cur ? '　<span class="muted-help">目前</span>' : '') + '</td>'
           + '<td class="c">' + dispDate(v.form_date) + '</td>'
           + '<td>' + esc(v.rev_note || '') + '</td>'
           + '<td class="c"><span class="st st-' + esc(v.status) + '">' + esc(SS_STATUSES[v.status] || v.status) + '</span></td>'
           + '<td class="c">' + (cur ? '' : '<button class="btn btn-xs btn-warm-o v-open" data-ver="' + num(v.ver_id) + '">開啟</button>') + '</td>'
           + '</tr>';
    });
    h += '</tbody></table></div>';
    return h;
}

/** 附件（掃描檔與多餘的步驟圖都在這裡，不丟掉） */
function filesHtml() {
    var list = (CUR.files || []).filter(function (f) { return f.usage_kind === 'scan' || f.usage_kind === 'other'; });
    if (!list.length && !CUR.can_edit) return '';
    var h = '<div class="sec"><h5>附件<span class="muted-help">紙本掃描檔、已簽核的紙本</span></h5>';
    if (list.length) {
        h += '<ul style="margin:0 0 6px 18px;font-size:12.5px;">';
        $.each(list, function (i, f) {
            h += '<li><a href="' + fileUrl(f.file_id) + '" target="_blank">' + esc(f.orig_name || f.file_name) + '</a>'
               + ' <a class="muted-help" href="' + fileUrl(f.file_id) + '&dl=1">下載</a>'
               + (CUR.can_edit ? ' <button class="btn btn-xs f-del" data-file="' + num(f.file_id) + '">刪除</button>' : '')
               + '</li>';
        });
        h += '</ul>';
    } else h += '<div class="muted-help">沒有附件</div>';
    if (CUR.can_edit) {
        h += '<button class="btn btn-xs btn-warm-o" id="btnUpScan">上傳附件</button>'
           + '<input type="file" id="fileScan" style="display:none;">';
    }
    h += '</div>';
    return h;
}

function renderDoc() {
    var d = CUR.doc, v = CUR.ver;
    $('#docTitle').text((SS_KINDS[CUR.kind] ? SS_KINDS[CUR.kind].label : '') + '　' + (d.title || ''));
    $('#docAsNo').text(CUR.as_no || '');
    $('#docStatus').attr('class', 'st st-' + v.status).text(SS_STATUSES[v.status] || v.status);

    var body = headHtml();
    if (CUR.kind === 'equip') body += equipHtml();
    else if (CUR.kind === 'process') body += stepsHtml();
    else body += sipExtraHtml() + itemsHtml();
    body += signHtml() + versHtml() + filesHtml();
    $('#docBody').html(body);

    var foot = '<span class="muted-help">' + (CUR.can_edit ? '草稿可以直接改，改完記得存檔。'
             : (v.status === 'approved' ? '已核准的版次不可修改，要改請建立新版次。' : '目前沒有修改權限。')) + '</span><span class="sp"></span>';
    foot += '<button class="btn btn-sm btn-warm-o" id="btnPrint">列印</button>';
    if (CUR.can_edit) {
        foot += ' <button class="btn btn-sm btn-warm-o" id="btnSave">存檔</button>'
              + ' <button class="btn btn-sm btn-warm" id="btnSubmit">送出簽核</button>';
    }
    if (ssCanEditKind()) foot += ' <button class="btn btn-sm btn-warm-o" id="btnNewVer">建立新版次</button>';
    foot += ' <button class="btn btn-sm" data-close="maskDoc">關閉</button>';
    $('#docFoot').html(foot);
}
function ssCanEditKind() {
    var tab = SS_KINDS[CUR.kind] ? SS_KINDS[CUR.kind].tab : 'sop';
    return tab === 'sip' ? !!SS_PERMS.canEditSip : !!SS_PERMS.canEditSop;
}

$(document).on('click', '.v-open', function (e) { e.stopPropagation(); openDoc(num($(this).data('ver'))); });

/* ══════════════════════ 存檔 ══════════════════════ */

function collectSteps() {
    var out = [];
    $('#tblSteps tbody tr').each(function () {
        var $t = $(this);
        out.push({ step_name: $t.find('.s-name').val() || '', img_file_id: num($t.data('img')),
                   step_text: $t.find('.s-text').val() || '', note: $t.find('.s-note').val() || '' });
    });
    return out;
}
function collectItems() {
    var out = [];
    $('#tblItems tbody tr').each(function () {
        var $t = $(this);
        out.push({ ctrl_point: $t.find('.i-ctrl').val() || '', q_char: $t.find('.i-q').val() || '',
                   up_limit: $t.find('.i-up').val() || '', lo_limit: $t.find('.i-lo').val() || '',
                   owner: $t.find('.i-own').val() || '', method: $t.find('.i-mth').val() || '',
                   tool_no: $t.find('.i-tool').val() || '', freq: $t.find('.i-freq').val() || '',
                   note: $t.find('.i-note').val() || '' });
    });
    return out;
}

function saveDoc(cb) {
    if (!CUR || !CUR.can_edit) return;
    var d = CUR.doc;
    // 主檔（名稱／製程）與版次分兩支存：主檔是整份文件共用的，版次才是這一版的內容
    post('doc_save', {
        doc_id: num(d.doc_id), kind: CUR.kind, scope: d.scope,
        machine_id: num(d.machine_id), part_d_id: num(d.part_d_id),
        title: $('#fTitle').val(), proc_name: (CUR.kind === 'sip' ? ($('#f_proc').val() || '') : (d.proc_name || ''))
    }, function () {
        var p = { ver_id: num(CUR.ver.ver_id), ver_no: $('#fVer').val(), form_date: $('#fDate').val(),
                  rev_note: $('#fRev').val() };
        if (CUR.kind === 'equip') {
            $.each(['m_maker', 'm_name', 'm_spec', 'm_range', 'op_method', 'cautions', 'maintain'], function (i, k) {
                p[k] = $('#f_' + k).val() || '';
            });
        } else if (CUR.kind === 'process') {
            p.use_equip = $('#f_use_equip').val() || '';
            p.est_hours = $('#f_est_hours').val() || '';
            p.steps = JSON.stringify(collectSteps());
        } else {
            p.customer_name = $('#f_customer_name').val() || '';
            p.order_no = $('#f_order_no').val() || '';
            p.qty = $('#f_qty').val() || '';
            p.notice = $('#f_notice').val() || '';
            p.items = JSON.stringify(collectItems());
        }
        post('ver_save', p, function () {
            if (cb) cb(); else { openDoc(num(CUR.ver.ver_id)); load(); }
        });
    });
}
$(document).on('click', '#btnSave', function () { saveDoc(); });

/* ══════════════════════ 圖面／附件 ══════════════════════ */

var PICK_FOR = null;        // 'draw' 或 步驟的 <tr>

function openDrawPick(forWhat) {
    PICK_FOR = forWhat;
    var list = CUR.draw_candidates || [];
    var h = '<div class="note-box">清單就是<b>這個料號的料號附件</b>。挑一個帶入，只建立關聯不會複製檔案；'
          + '料號附件那邊換了圖，這裡也會跟著是新的。</div>';
    if (!list.length) {
        h += '<div class="muted-help">這個料號目前沒有可帶入的圖檔（只列圖片與 PDF，批圖暫存檔不列）。'
           + '可以改用「上傳新圖」。</div>';
    } else {
        h += '<div style="display:flex;flex-wrap:wrap;gap:8px;">';
        $.each(list, function (i, f) {
            h += '<div style="border:1px solid var(--line);border-radius:6px;padding:6px;width:180px;text-align:center;cursor:pointer;"'
               + ' class="dp-item" data-id="' + num(f.id) + '">'
               + (num(f.is_image) ? '<img src="../../src/store/Part_Attachment_API.php?action=download&id=' + num(f.id)
                                     + '&thumb=1" style="max-width:100%;max-height:96px;">'
                                  : '<div style="padding:24px 0;color:#9A8A7A;"><i class="fa fa-file-pdf-o fa-2x"></i></div>')
               + '<div style="font-size:11.5px;margin-top:4px;word-break:break-all;">' + esc(f.name) + '</div>'
               + '<div class="muted-help" style="font-size:11px;">' + esc((f.tags || []).join('／')) + '　' + dispDate(f.uploaded_on) + '</div>'
               + '</div>';
        });
        h += '</div>';
    }
    $('#submitBody').html(h);
    $('#maskSubmit .m-head').contents().first().replaceWith('挑選圖面');
    $('#sbGo').hide();
    openMask('maskSubmit');
}
$(document).on('click', '.dp-item', function () {
    var id = num($(this).data('id'));
    post('file_link_part', { doc_id: num(CUR.doc.doc_id), ver_id: num(CUR.ver.ver_id),
                             part_attach_id: id, usage: PICK_FOR === 'draw' ? 'draw' : 'step' },
    function (res) {
        closeMask('maskSubmit');
        if (PICK_FOR === 'draw') {
            post('ver_save', { ver_id: num(CUR.ver.ver_id), draw_file_id: num(res.file_id) },
                 function () { openDoc(num(CUR.ver.ver_id)); });
        } else if (PICK_FOR && PICK_FOR.length) {
            PICK_FOR.data('img', num(res.file_id));
            PICK_FOR.find('td').eq(2).html('<img class="thumb" src="' + fileUrl(res.file_id) + '">'
                + '<br><button class="btn btn-xs btn-warm-o s-pick">挑圖</button> <button class="btn btn-xs s-clr">移除</button>');
        }
    });
});
$(document).on('click', '#btnPickDraw', function () { openDrawPick('draw'); });
$(document).on('click', '.s-pick', function () { openDrawPick($(this).closest('tr')); });
$(document).on('click', '.s-clr', function () {
    var $tr = $(this).closest('tr');
    $tr.data('img', 0);
    $tr.find('td').eq(2).html('<span class="muted-help">無</span><br><button class="btn btn-xs btn-warm-o s-pick">挑圖</button>');
});
$(document).on('click', '#btnClrDraw', function () {
    post('ver_save', { ver_id: num(CUR.ver.ver_id), draw_file_id: 0 }, function () { openDoc(num(CUR.ver.ver_id)); });
});

/* 上傳：依記憶 file_upload_change_event 三鐵則——送出時直讀 input.files，不倚賴 change 事件被觸發 */
function doUpload(inputId, usage, after) {
    var el = document.getElementById(inputId);
    if (!el || !el.files || !el.files.length) return;
    var fd = new FormData();
    fd.append('action', 'file_upload'); fd.append('csrf', SS_CSRF);
    fd.append('doc_id', num(CUR.doc.doc_id)); fd.append('ver_id', num(CUR.ver.ver_id));
    fd.append('usage', usage); fd.append('file', el.files[0]);
    $.ajax({ url: SS_API, type: 'POST', data: fd, processData: false, contentType: false, dataType: 'json' })
        .done(function (res) {
            el.value = '';
            if (!res || !res.success) { alert((res && res.message) || '上傳失敗'); return; }
            if (after) after(res); else openDoc(num(CUR.ver.ver_id));
        })
        .fail(function (x) { alert('上傳失敗（' + x.status + '）'); });
}
$(document).on('click', '#btnUpDraw', function () { $('#fileDraw').trigger('click'); });
$(document).on('change', '#fileDraw', function () {
    doUpload('fileDraw', 'draw', function (res) {
        post('ver_save', { ver_id: num(CUR.ver.ver_id), draw_file_id: num(res.file_id) },
             function () { openDoc(num(CUR.ver.ver_id)); });
    });
});
$(document).on('click', '#btnUpScan', function () { $('#fileScan').trigger('click'); });
$(document).on('change', '#fileScan', function () { doUpload('fileScan', 'scan'); });
$(document).on('click', '.f-del', function () {
    if (!confirm('確定刪除這個附件？')) return;
    post('file_delete', { file_id: num($(this).data('file')) }, function () { openDoc(num(CUR.ver.ver_id)); });
});

/* ══════════════════════ 送簽與簽核 ══════════════════════ */

$(document).on('click', '#btnSubmit', function () {
    saveDoc(function () {
        api('signer_candidates', { form_date: $('#fDate').val() || CUR.ver.form_date }, function (res) {
            var people = res.rows || [];
            var h = '<div class="note-box">送出後「製表」那一格立刻成立，業務日期＝表單日期。'
                  + '可以簽的人限<b>表單日期當時在職</b>，且<b>簽章當天沒有請整天假</b>——'
                  + '不可選的人會直接標出原因。</div><div class="frm">';
            h += '<label>簽章日期</label><div class="wide"><input type="date" id="sbDate" value="'
               + esc(CUR.ver.form_date || SS_TODAY) + '"' + (SS_PERMS.canAdmin ? '' : ' readonly') + '></div>';
            if (SS_PERMS.canAdmin) {
                h += '<label>製表（填表人）</label><div class="wide">' + peopleSel('sbMaker', people, 0) + '</div>'
                   + '<label>審核</label><div class="wide">' + peopleSel('sbReview', people, 0) + '</div>'
                   + '<label>核准</label><div class="wide">' + peopleSel('sbApprove', people, 0) + '</div>'
                   + '<label>自動簽核</label><div class="wide"><label style="font-weight:normal;text-align:left;">'
                   + '<input type="checkbox" id="sbAuto"> 送出當下把審核與核准一起蓋好（時間會錯開且不跨日）</label></div>';
            } else {
                h += '<label>製表</label><div class="wide"><input value="' + esc(SS_PERMS.name) + '" readonly></div>';
            }
            h += '</div>';
            $('#submitBody').html(h);
            $('#maskSubmit .m-head').contents().first().replaceWith('送出簽核');
            $('#sbGo').show();
            openMask('maskSubmit');
        });
    });
});
function peopleSel(id, people, sel) {
    var h = '<select id="' + id + '" data-eg-filter="輸入姓名或部門篩選…"><option value="">（不指定）</option>';
    $.each(people, function (i, p) {
        h += '<option value="' + num(p.id) + '"' + (num(p.id) === num(sel) ? ' selected' : '')
           + (num(p.blocked) ? ' disabled' : '') + '>'
           + esc(p.dept) + '　' + esc(p.position) + '　' + esc(p.name)
           + (num(p.blocked) ? '（' + esc(p.block_why) + '，不可簽）' : '')
           + (num(p.is_former) ? '（當時在職・現已離職）' : '') + '</option>';
    });
    return h + '</select>';
}
$('#sbGo').on('click', function () {
    var p = { ver_id: num(CUR.ver.ver_id), sign_date: $('#sbDate').val() || '' };
    if (SS_PERMS.canAdmin) {
        p.maker_id = num($('#sbMaker').val());
        p.review_id = num($('#sbReview').val());
        p.approve_id = num($('#sbApprove').val());
        p.auto = $('#sbAuto').is(':checked') ? 1 : 0;
    }
    post('submit', p, function (res) {
        closeMask('maskSubmit');
        openDoc(num(CUR.ver.ver_id));
        load();
    });
});

$(document).on('click', '.sg-go', function () {
    var slot = $(this).data('slot');
    // 點開即刷新：按下當下先跟後端要最新狀態，不一致就擋下並重畫
    api('detail', { ver_id: num(CUR.ver.ver_id) }, function (res) {
        if (res.next_slot && res.next_slot !== slot) {
            CUR = res; renderDoc();
            alert('這一格的狀態已經變了（目前輪到「' + (SS_SLOTS[res.next_slot] ? SS_SLOTS[res.next_slot].label : res.next_slot) + '」），畫面已更新。');
            return;
        }
        post('sign', { ver_id: num(CUR.ver.ver_id), slot: slot, sign_date: '' }, function () {
            openDoc(num(CUR.ver.ver_id)); load();
        });
    });
});
$(document).on('click', '.sg-clr', function () {
    if (!confirm('確定清除這一格簽章？清掉之後這一版會退回「簽核中」。')) return;
    post('sign_clear', { ver_id: num(CUR.ver.ver_id), slot: $(this).data('slot') },
         function () { openDoc(num(CUR.ver.ver_id)); load(); });
});

$(document).on('click', '#btnNewVer', function () {
    var v = prompt('新版次的版次號（留空＝自動遞增）', '');
    if (v === null) return;
    post('ver_new', { from_ver_id: num(CUR.ver.ver_id), ver_no: v || '', form_date: SS_TODAY, rev_note: '' },
         function (res) { openDoc(num(res.ver_id)); load(); });
});

/* ══════════════════════ 列印 ══════════════════════ */

function doPrint(verId) {
    // 一定要由使用者點擊直接開視窗，不然會被彈出視窗封鎖擋掉而「按了完全沒反應」
    window.open('sop_sip_print.php?ver_id=' + num(verId), '_blank');
}
$(document).on('click', '#btnPrint', function () { doPrint(num(CUR.ver.ver_id)); });

/* ══════════════════════ 設定 ══════════════════════ */

$('#btnSetting').on('click', function () {
    api('settings_get', {}, function (res) {
        var h = '<div class="note-box">這裡設定的是<b>全站共用</b>的：自動簽核、各關預設簽核人、圖章模板、'
              + 'AS 文件編號綁定與上班時段。上班時段只用來判定「請整天假」——請假涵蓋整個上班時段才算整天。</div>';
        h += '<div class="frm" style="margin-bottom:10px;">'
           + '<label>上班時段</label><div class="wide">'
           + '<input type="text" id="stWs" value="' + esc(res.work_start) + '" style="width:80px;display:inline-block;"> ~ '
           + '<input type="text" id="stWe" value="' + esc(res.work_end) + '" style="width:80px;display:inline-block;"></div>'
           + '</div>';
        $.each(SS_SLOTS, function (k, def) {
            h += '<div class="frm" style="margin-bottom:4px;"><label>' + esc(def.label) + '圖章模板</label><div class="wide">'
               + '<select id="stTpl_' + k + '"><option value="0">（預設回墨印）</option>';
            $.each(res.stamp_templates || [], function (i, t) {
                h += '<option value="' + num(t.id) + '"' + (num((res.stamp || {})[k]) === num(t.id) ? ' selected' : '') + '>'
                   + esc(t.tpl_name) + '</option>';
            });
            h += '</select></div></div>';
        });
        $.each(SS_KINDS, function (k, def) {
            var cfg = (res.kinds || {})[k] || {};
            h += '<div class="sec"><h5>' + esc(def.label) + '　<span class="as-tag">'
               + esc(cfg.as_no || '未綁定') + '</span>'
               + '<button class="btn btn-xs btn-warm-o st-as" data-kind="' + k + '">綁定 AS 文件</button></h5><div class="frm">'
               + '<label>自動簽核</label><div class="wide"><label style="font-weight:normal;text-align:left;">'
               + '<input type="checkbox" class="st-auto" data-kind="' + k + '"' + (num(cfg.auto_sign) ? ' checked' : '')
               + '> 送出時自動完成審核與核准</label></div>';
            $.each(SS_SLOTS, function (slot, sd) {
                if (slot === 'maker') return;
                h += '<label>' + esc(sd.label) + '預設人</label><div class="wide">'
                   + peopleSel('stSg_' + k + '_' + slot, res.people || [], num((cfg.signers || {})[slot])) + '</div>';
            });
            h += '</div></div>';
        });
        $('#setBody').html(h);
        openMask('maskSet');
    });
});
$(document).on('click', '.st-as', function () {
    var kind = $(this).data('kind');
    if (!window.EGAsDoc) { alert('AS 文件挑選器未載入'); return; }
    api('asdoc_list', { kind: kind }, function (res) {
        EGAsDoc.open({ docs: res.docs, current: res.current,
            title: (SS_KINDS[kind] ? SS_KINDS[kind].label : '') + '－AS 文件編號綁定',
            onSave: function (id) { post('asdoc_save', { kind: kind, doc_id: id }, function () { $('#btnSetting').trigger('click'); }); } });
    });
});
$('#setSave').on('click', function () {
    var p = { work_start: $('#stWs').val(), work_end: $('#stWe').val() };
    $.each(SS_SLOTS, function (k) { p['stamp_' + k] = num($('#stTpl_' + k).val()); });
    $.each(SS_KINDS, function (k) {
        p['auto_' + k] = $('.st-auto[data-kind="' + k + '"]').is(':checked') ? 1 : 0;
        $.each(SS_SLOTS, function (slot) {
            if (slot === 'maker') return;
            p['signer_' + k + '_' + slot] = num($('#stSg_' + k + '_' + slot).val());
        });
    });
    post('settings_save', p, function () { alert('已儲存'); closeMask('maskSet'); });
});

/* ══════════════════════ 起動 ══════════════════════ */
$(function () {
    if (!SS_PERMS.canView) return;
    kindOptions('#fKind', true);
    load();
});
$('#btnPageHelp').on('click', function () { openMask('helpUseMask'); });
